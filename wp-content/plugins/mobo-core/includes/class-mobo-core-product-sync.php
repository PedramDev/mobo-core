<?php
/**
 * Product and variation sync.
 *
 * Clean v2 implementation preserving legacy behavior/options/GUIDs.
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/*
 * This component operates on Mobo Core's internal queue/map tables. Direct
 * database access is required for atomic batching and cursor updates; table
 * identifiers are generated internally and all external values are prepared.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
/**
 * Narrowly typed, non-transient conflict. A cross-parent identity is never
 * reparented automatically; Repair may quarantine this exact product and keep
 * the other products progressing without losing the historical Woo IDs.
 */
final class Mobo_Core_Variation_Parent_Conflict extends RuntimeException {
	public $variant_guid;
	public $variation_id;
	public $existing_parent_id;
	public $requested_parent_id;
	public $product_guid;
	public $connection_id;

	public function __construct( $variant_guid, $variation_id, $existing_parent_id, $requested_parent_id, $product_guid, $connection_id ) {
		$this->variant_guid = sanitize_text_field( (string) $variant_guid );
		$this->variation_id = absint( $variation_id );
		$this->existing_parent_id = absint( $existing_parent_id );
		$this->requested_parent_id = absint( $requested_parent_id );
		$this->product_guid = sanitize_text_field( (string) $product_guid );
		$this->connection_id = absint( $connection_id );
		parent::__construct( sprintf(
			'Existing variation identity belongs to another parent product; refusing cross-parent reassignment. connection=%d product_guid=%s variant_guid=%s variation=%d existing_parent=%d requested_parent=%d',
			$this->connection_id, $this->product_guid, $this->variant_guid,
			$this->variation_id, $this->existing_parent_id, $this->requested_parent_id
		) );
	}
}


/**
 * Q2 P4B5: definitive, source-scoped parent identity disagreement.
 * Incomplete evidence (including database errors) MUST remain an ordinary
 * hard failure: it is not evidence of a durable identity conflict.
 */
final class Mobo_Core_Parent_Identity_Conflict extends RuntimeException {
	public $product_guid;
	public $connection_id;
	public $reason;
	public $canonical_parent_id;
	public $legacy_parent_id;
	public $custom_parent_id;
	public $candidate_parent_id;
	public $variation_parent_ids;

	public static function is_definitive_reason( $reason ) {
		return in_array( (string) $reason, array(
			'split_parent_maps',
			'variation_parent_disagrees_with_map',
			'mixed_variation_parents',
			'foreign_parent_owner',
			'candidate_disagrees_with_map',
		), true );
	}

	public function __construct( $guid, $connection_id, $decision ) {
		$this->product_guid = sanitize_text_field( (string) $guid );
		$this->connection_id = absint( $connection_id );
		$this->reason = sanitize_key( (string) ( $decision['reason'] ?? '' ) );
		$this->canonical_parent_id = absint( $decision['canonical'] ?? 0 );
		$this->legacy_parent_id = absint( $decision['legacy'] ?? 0 );
		$this->custom_parent_id = absint( $decision['custom'] ?? 0 );
		$this->candidate_parent_id = absint( $decision['candidate'] ?? 0 );
		$this->variation_parent_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $decision['variationParents'] ?? array() ) ) ) ) );
		parent::__construct( sprintf(
			'Q2 parent identity fence blocked unsafe product mutation: %s connection=%d guid=%s',
			$this->reason, $this->connection_id, $this->product_guid
		) );
	}
}


/**
 * Thrown when creating a new product must wait (legacy Product Map seed still
 * running). Retryable: queues defer the event without consuming an attempt.
 */
class Mobo_Core_Product_Creation_Deferred extends RuntimeException {
}

/**
 * Q2/P0 identity fence: evidence-only decision before selecting, creating or
 * rewriting a local WooCommerce product parent. Disabled by default until the
 * Disposable runtime contract and rollback have been proven. No remap is made.
 */
final class Mobo_Core_Parent_Identity_Fence {
	/** Pure classifier; its inputs are source-scoped and must be read under a product lock. */
	public static function classify( $candidate_id, $legacy_id, $custom_id, $variation_parents, $foreign_owner = false, $evidence_complete = true ) {
		$candidate_id = absint( $candidate_id );
		$legacy_id = absint( $legacy_id );
		$custom_id = absint( $custom_id );
		$parents = array_values( array_unique( array_filter( array_map( 'absint', (array) $variation_parents ) ) ) );
		sort( $parents, SORT_NUMERIC );
		$canonical = $custom_id > 0 ? $custom_id : $legacy_id;
		$reason = '';
		if ( ! $evidence_complete ) {
			$reason = 'evidence_unavailable';
		} elseif ( $foreign_owner ) {
			$reason = 'foreign_parent_owner';
		} elseif ( $legacy_id > 0 && $custom_id > 0 && $legacy_id !== $custom_id ) {
			$reason = 'split_parent_maps';
		} elseif ( $candidate_id > 0 && $canonical > 0 && $candidate_id !== $canonical ) {
			$reason = 'candidate_disagrees_with_map';
		} elseif ( count( $parents ) > 1 ) {
			$reason = 'mixed_variation_parents';
		} elseif ( ! empty( $parents ) && ( 0 === $canonical || $parents[0] !== $canonical ) ) {
			$reason = 'variation_parent_disagrees_with_map';
		} elseif ( 0 === $canonical && $candidate_id > 0 ) {
			// Explicit candidate on an unowned GUID is not proof of ownership.
			$reason = 'unmapped_candidate_requires_authority';
		}
		return array(
			'allow' => '' === $reason,
			'reason' => '' === $reason ? ( $canonical > 0 ? 'existing_consistent' : 'new_without_local_identity' ) : $reason,
			'canonical' => $canonical,
			'legacy' => $legacy_id,
			'custom' => $custom_id,
			'candidate' => $candidate_id,
			'variationParents' => $parents,
		);
	}

	/** A hidden/future/trashed map owner must never silently become a duplicate. */
	public static function assert_can_create( $guid ) {
		$decision = self::assert_safe( $guid );
		if ( 0 !== $decision['canonical'] || 'new_without_local_identity' !== $decision['reason'] ) {
			throw new RuntimeException( 'Q2 parent identity fence: existing GUID authority must be resolved before creating another Woo product.' );
		}
		return true;
	}

	/** Never consult a different connection and never infer identity from a title. */
	public static function assert_safe( $guid, $candidate_id = 0, $trusted_new = false ) {
		global $wpdb;
		$guid = sanitize_text_field( (string) $guid );
		$candidate_id = absint( $candidate_id );
		$cid = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( ! $wpdb instanceof wpdb || '' === $guid || $cid <= 0
			|| ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists()
			|| ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			throw new RuntimeException( 'Q2 parent identity fence: scoped storage evidence unavailable; no parent mutation allowed.' );
		}

		$legacy_table = Mobo_Core_Product_Map::table_name();
		$custom_table = Mobo_Core_Storage_Schema::table_name( 'products' );
		$variant_table = Mobo_Core_Storage_Schema::table_name( 'variants' );
		$legacy_rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT wp_post_id FROM {$legacy_table} WHERE connection_id=%d AND BINARY remote_guid=BINARY %s AND object_type='product' LIMIT 2", $cid, $guid
		) );
		$legacy_error = (string) $wpdb->last_error;
		$custom_rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT woo_product_id FROM {$custom_table} WHERE connection_id=%d AND BINARY product_guid=BINARY %s LIMIT 2", $cid, $guid
		) );
		$custom_error = (string) $wpdb->last_error;
		$parents_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.post_parent AS parent_id, MIN(v.woo_variation_id) AS example_variation_id, COUNT(*) AS child_count
			 FROM {$variant_table} v INNER JOIN {$wpdb->posts} p ON p.ID=v.woo_variation_id
			 WHERE v.connection_id=%d AND BINARY v.product_guid=BINARY %s
			 AND p.post_type='product_variation' AND p.post_status IN ('publish','private','draft','pending','future','trash')
			 GROUP BY p.post_parent ORDER BY p.post_parent ASC LIMIT 4", $cid, $guid
		), ARRAY_A );
		$parents_error = (string) $wpdb->last_error;
		$complete = '' === $legacy_error && '' === $custom_error && '' === $parents_error
			&& is_array( $legacy_rows ) && is_array( $custom_rows ) && is_array( $parents_rows )
			&& count( $legacy_rows ) <= 1 && count( $custom_rows ) <= 1 && count( $parents_rows ) < 4;
		if ( ( is_array( $legacy_rows ) && count( $legacy_rows ) > 0 && absint( $legacy_rows[0] ) <= 0 )
			|| ( is_array( $custom_rows ) && count( $custom_rows ) > 0 && absint( $custom_rows[0] ) <= 0 ) ) {
			$complete = false;
		}
		$legacy_id = ! empty( $legacy_rows[0] ) ? absint( $legacy_rows[0] ) : 0;
		$custom_id = ! empty( $custom_rows[0] ) ? absint( $custom_rows[0] ) : 0;
		$parents = array();
		foreach ( (array) $parents_rows as $row ) {
			$parents[] = absint( $row['parent_id'] ?? 0 );
			if ( absint( $row['parent_id'] ?? 0 ) <= 0 && absint( $row['child_count'] ?? 0 ) > 0 ) {
				$complete = false;
			}
		}
		// Cross-source/connection reuse of a concrete local Woo ID is a hard failure.
		$foreign_owner = false;
		$ids = array_values( array_unique( array_filter( array( $candidate_id, $legacy_id, $custom_id ) ) ) );
		foreach ( $ids as $id ) {
			$foreign_custom = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$custom_table} WHERE woo_product_id=%d AND (connection_id<>%d OR BINARY product_guid<>BINARY %s) LIMIT 1", $id, $cid, $guid
			) );
			if ( '' !== (string) $wpdb->last_error ) { $complete = false; }
			$foreign_legacy = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$legacy_table} WHERE wp_post_id=%d AND object_type='product' AND (connection_id<>%d OR BINARY remote_guid<>BINARY %s) LIMIT 1", $id, $cid, $guid
			) );
			if ( '' !== (string) $wpdb->last_error ) { $complete = false; }
			if ( $foreign_custom || $foreign_legacy ) { $foreign_owner = true; }
		}
		$decision = self::classify( $trusted_new ? 0 : $candidate_id, $legacy_id, $custom_id, $parents, $foreign_owner, $complete );
		if ( $trusted_new && ( ! $decision['allow'] || ( $decision['canonical'] > 0 && $decision['canonical'] !== $candidate_id ) ) ) {
			$decision['allow'] = false;
			$decision['reason'] = 'concurrent_identity_appeared_during_creation';
		}
		if ( ! $decision['allow'] ) {
			static $logged = array();
			$log_key = $cid . ':' . $guid . ':' . $decision['reason'];
			if ( ! isset( $logged[ $log_key ] ) ) {
				$logged[ $log_key ] = true;
				Mobo_Core_Environment::log( '[MOBO Q2 PARENT IDENTITY FENCE] connection=' . $cid . ' guid=' . $guid . ' reason=' . $decision['reason'] . ' legacy=' . $legacy_id . ' custom=' . $custom_id . ' candidate=' . $candidate_id . ' variantParents=' . implode( ',', $decision['variationParents'] ) );
			}
			if ( Mobo_Core_Parent_Identity_Conflict::is_definitive_reason( $decision['reason'] ) ) {
				throw new Mobo_Core_Parent_Identity_Conflict( $guid, $cid, $decision );
			}
			throw new RuntimeException( 'Q2 parent identity fence blocked unsafe product mutation: ' . $decision['reason'] . ' connection=' . $cid . ' guid=' . $guid );
		}
		return $decision;
	}
}

class Mobo_Core_Product_Sync {

	const STATE_OPTION = 'mobo_core_sync_state'; // legacy migration source only
	const STATE_KEY    = 'sync_state';
	const REPAIR_COMPLETED_OPTION = 'mobo_core_repair_completed_at';
	const REPAIR_LAST_SYNC_ID_OPTION = 'mobo_core_repair_last_sync_id';
	const REPAIR_SYNC_ID_META = '_mobo_last_repair_sync_id';
	const REPAIR_SYNCED_AT_META = '_mobo_last_repair_synced_at';
	const REPAIR_APPLIED_REVISION_META = '_mobo_last_repair_applied_revision';
	const REPAIR_WEBHOOK_US_META = '_mobo_last_repair_webhook_us';
	const STALE_RUNNING_RECOVERY_SECONDS = 15;
	const TARGETED_CONVERGENCE_WINDOW = 1000;
	const TARGETED_CONVERGENCE_RECLASSIFY_LIMIT = 2000;
	const TARGETED_CONVERGENCE_UNRESOLVED_LIMIT = 1000;

	/** Request-local checkpoint coalescing used only by the real cron runner. */
	private $checkpoint_coalescing = false;
	private $checkpoint_state = null;
	private $checkpoint_pending_steps = 0;
	private $checkpoint_last_flush_at = 0.0;
	private $checkpoint_max_steps = 3;
	private $checkpoint_max_seconds = 2.0;

	private $rules;
	private $price_calculator;
	private $image_sync;
	private $category_sync;
	private $product_map;
	private $repair_mode = false;
	private $last_parent_core_noop    = false;

	/** Q2: request-local authorization for exactly one newly saved parent ID. */
	private $q2_new_parent_authorizations = array();

	/** @var array Request-local blocked-status decisions by Connection/object type/GUID. */
	private $blocked_status_cache = array();

	/** @var array Request-local variation attribute-signature index by parent product ID. */
	private $variation_signature_index = array();

	/** PERF02: winner-only reverse index (parent => variation ID => signature). */
	private $variation_signature_reverse = array();

	/** @var array Request-local parent portal_product_id cache. */
	private $parent_portal_product_id_cache = array();

	public function __construct() {
		$this->rules            = new Mobo_Core_Legacy_Rules();
		$this->price_calculator = new Mobo_Core_Price_Calculator( $this->rules );
		$this->image_sync       = new Mobo_Core_Image_Sync();
		$this->category_sync    = new Mobo_Core_Category_Sync();
		$this->product_map      = class_exists( 'Mobo_Core_Product_Map' ) ? new Mobo_Core_Product_Map() : null;
	}

	/** Active Source owner for every durable product/variant query in this instance. */
	private function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/**
	 * Enable repair semantics for this Product Sync instance.
	 *
	 * At the Product Sync instance level, Repair bypasses the stored source-hash
	 * shortcut while preserving field/price/stock policy. The existing manual
	 * Repair workflow additionally runs Mobo_Core_Repair_Integrity before the
	 * authoritative Portal snapshot.
	 *
	 * @param bool $enabled Enable repair mode.
	 * @return self
	 */
	public function set_repair_mode( $enabled = true ) {
		$this->repair_mode = (bool) $enabled;
		return $this;
	}

	public function start_manual_sync( $sync_id = '', $source = 'admin', $repair_mode = false ) {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return $this->result( false, 'شروع Sync تا پایان آپدیت افزونه متوقف است.', array_merge( $this->get_manual_sync_status(), array( 'pausedForUpgrade' => true, 'upgradeBarrier' => Mobo_Core_Upgrade_Coordinator::get_status() ) ) );
		}

		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'manual_sync_start', 30 ) : 'no-lock';
		if ( false === $lock ) {
			return $this->result( false, 'درخواست دیگری هم‌زمان در حال شروع Sync/Repair است.', array_merge( $this->get_manual_sync_status(), array( 'locked' => true ) ) );
		}

		try {
			return $this->start_manual_sync_unlocked( $sync_id, $source, $repair_mode );
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && 'no-lock' !== $lock ) {
				Mobo_Core_Lock::release( 'manual_sync_start', $lock );
			}
		}
	}

	private function start_manual_sync_unlocked( $sync_id = '', $source = 'admin', $repair_mode = false ) {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return $this->result( false, 'شروع Sync تا پایان آپدیت افزونه متوقف است.', array_merge( $this->get_manual_sync_status(), array( 'pausedForUpgrade' => true, 'upgradeBarrier' => Mobo_Core_Upgrade_Coordinator::get_status() ) ) );
		}

		if ( class_exists( 'Mobo_Core_Lock' ) ) {
			Mobo_Core_Lock::recover_stale_locks( array( 'manual_sync', 'manual_sync_start', 'self_runner_kick', 'worker_dispatcher' ) );
			if ( Mobo_Core_Lock::is_locked( 'manual_sync' ) ) {
				return $this->result( false, 'مرحله قبلی Sync هنوز در حال پایان یافتن است. پس از آزاد شدن Worker دوباره شروع کنید.', array_merge( $this->get_manual_sync_status(), array( 'locked' => true ) ) );
			}
		}

		$sync_id     = sanitize_text_field( (string) $sync_id );
		$repair_mode = (bool) $repair_mode;
		$current     = $this->get_manual_sync_status();

		if ( ! empty( $current['isRunning'] ) ) {
			$recovered = $this->recover_stalled_manual_sync_checkpoint( $current, $sync_id, $source, $repair_mode );
			if ( is_array( $recovered ) ) {
				return $recovered;
			}
		}

		if ( ! empty( $current['isRunning'] ) || ! empty( $current['isWaitingForPortal'] ) ) {
			$current_sync_id = isset( $current['syncId'] ) ? sanitize_text_field( (string) $current['syncId'] ) : '';
			$current_repair  = ! empty( $current['repairMode'] );

			if ( '' !== $sync_id && '' !== $current_sync_id && hash_equals( $current_sync_id, $sync_id ) && $current_repair === $repair_mode ) {
				return $this->result( true, 'این درخواست Sync قبلاً پذیرفته شده و هنوز در حال اجرا است.', array_merge( $current, array( 'alreadyAccepted' => true ) ) );
			}

			return $this->result( false, 'یک Sync یا Repair دیگر در حال اجرا است.', $current );
		}

		if ( $repair_mode ) {
			Mobo_Core_Settings::delete( self::REPAIR_COMPLETED_OPTION );
		}

		if ( '' === $sync_id ) {
			$sync_id = wp_generate_uuid4();
		}

		$state = array(
			'syncId'                       => $sync_id,
			'status'                       => 'running',
			'source'                       => sanitize_key( (string) $source ),
			'repairMode'                   => $repair_mode,
			'hashCheckBypassed'            => $repair_mode,

			'categorySynced'               => false,
			'categorySnapshotRetryCount'    => 0,
			'categorySyncDegraded'          => false,
			'categorySyncSkippedRows'       => 0,
			'categorySyncUnresolved'        => array(),
			'categoryRetiredRows'           => 0,

			'productPage'                  => 1,
			'productCursor'                => 0,
			'productCursorMode'            => Mobo_Core_Settings::enabled( 'mobo_core_product_cursor_sync_enabled', '1' ) ? 'cursor' : 'page',
			'productCursorSupported'       => false,
			'productQueue'                 => array(),

			'currentProductGuid'           => '',
			'currentProductId'             => 0,
			'currentProductPortalId'       => 0,
			'currentProductSourceRevision' => 0,
			'currentProductSourceVersion'  => '',
			'currentProductSourceHash'     => '',
			'currentProductSnapshotUs'    => 0,
			'currentProductImages'         => array(),
			'currentProductImagesPresent'  => false,
			'currentProductAttributes'     => array(),
			'currentProductImageOffset'    => 0,
			'currentProductWasExisting'    => false,
			'currentProductImagesDone'     => false,
			'currentProductCanHaveVariants'=> false,

			'variantPage'                  => 1,

			'productTotalCount'            => 0,
			'productTotalPages'            => 0,
			'processedProducts'            => 0,
			'remainingProducts'            => 0,

			'currentVariantTotalCount'     => 0,
			'currentVariantTotalPages'     => 0,
			'currentVariantProcessedPages' => 0,
			'currentVariantCursor'          => 0,
			'currentVariantCursorDisabled'  => false,

			'startedAt'                    => time(),
			'completedAt'                  => 0,
			'updatedAt'                    => time(),
			'lastMessage'                  => $repair_mode ? 'Repair محصولات شروع شد؛ ابتدا ترمیم محافظه‌کارانه داده‌های قدیمی و سپس Sync کامل اجرا می‌شود.' : 'همگام‌سازی محصولات شروع شد.',
			'lastError'                    => '',
			'transientRetryCount'           => 0,
			'lastTransientError'            => '',
			'waitingForPortalSince'         => 0,
			'waitingForPortalReason'        => '',
			'nextRetryAt'                  => 0,
			'cancelRequestedAt'            => 0,
			'staleRecoveryCount'           => 0,
			'lastStaleRecoveryAt'          => 0,
			'lastStaleRecoveryReason'      => '',
			'repairLivenessLockKey'        => '',
			'repairLivenessLockCount'      => 0,
			'repairProductLockQuarantined' => 0,
			'repairProductLockSamples'     => array(),
			'repairParentConflictCount'    => 0,
			'repairParentConflictSamples'  => array(),
			'repairParentConflictGuids'    => array(),

			'repairIntegrityPhase'           => $repair_mode ? 'portal-variant-duplicates' : 'done',
			'repairIntegrityCursor'          => 0,
			'repairIntegrityComplete'        => ! $repair_mode,
			'repairIntegrityStats'           => array(),

			'repairProductsComplete'         => false,
			'postSyncIntegrityPhase'           => $repair_mode ? 'variation-topology' : 'done',
			'postSyncIntegrityCursor'          => 0,
			'postSyncIntegrityComplete'        => ! $repair_mode,
			'postSyncIntegrityStats'           => array(),

			'convergenceRecoveryComplete'     => ! $repair_mode,
			'convergenceRecoveryAttempted'    => array(),
			'convergenceRecoveryScanned'      => 0,
			'convergenceRecoveryQueued'       => 0,
			'convergenceRecoverySkipped'      => 0,
			'convergenceRecoveryUnresolved'   => 0,
			'convergenceRecoveryCursor'       => 0,
			'targetedConvergenceOnly'       => false,
			'targetedConvergenceOwnsUnowned'=> false,
			'targetedConvergenceTargets'    => array(),

			'missingImageRecoveryCursor'      => 0,
			'missingImageRecoveryScanned'     => 0,
			'missingImageRecoveryQueued'      => 0,
			'missingImageRecoverySkipped'     => 0,
			'missingImageRecoveryFailed'      => 0,
			'missingImageRecoveryComplete'    => false,
		);

		if ( ! $this->persist_manual_sync_state_verified( $state ) ) {
			return $this->result( false, 'شروع Sync به دلیل ذخیره نشدن checkpoint اولیه متوقف شد.', $this->get_manual_sync_status() );
		}

		return $this->result(
			true,
			$repair_mode ? 'Repair محصولات شروع شد.' : 'همگام‌سازی محصولات شروع شد.',
			$this->get_manual_sync_status()
		);
	}



	/**
	 * Start a post-upgrade exact convergence generation without running a full
	 * catalogue Repair. The target set is frozen from the current authoritative
	 * unowned classifier so a temporary Backend outage still has durable recovery
	 * ownership and the normal real-cron runner can resume later.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public function start_targeted_convergence_recovery( $source = 'post-upgrade-convergence' ) {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return $this->result( false, 'Targeted convergence recovery is deferred until the upgrade barrier is released.', array_merge( $this->get_manual_sync_status(), array( 'pausedForUpgrade' => true ) ) );
		}

		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'manual_sync_start', 30 ) : 'no-lock';
		if ( false === $lock ) {
			return $this->result( false, 'Targeted convergence recovery start is contended.', array_merge( $this->get_manual_sync_status(), array( 'locked' => true ) ) );
		}

		try {
			$current_state = $this->get_manual_sync_state();
			$current_status = sanitize_key( (string) ( $current_state['status'] ?? '' ) );
			if ( in_array( $current_status, array( 'running', 'waiting_for_portal' ), true ) ) {
				if ( ! empty( $current_state['targetedConvergenceOnly'] ) ) {
					return $this->result( true, 'Targeted convergence recovery is already owned by the current generation.', array_merge( $this->get_manual_sync_status(), array( 'alreadyAccepted' => true ) ) );
				}
				return $this->result( false, 'Another Sync/Repair generation already owns Product Sync.', $this->get_manual_sync_status() );
			}

			if ( ! class_exists( 'Mobo_Core_Sync_Health' ) || ! method_exists( 'Mobo_Core_Sync_Health', 'get_operational_dashboard_status' ) ) {
				return $this->result( false, 'Operational Sync Health is unavailable for targeted recovery selection.', $this->get_manual_sync_status() );
			}
			if ( class_exists( 'Mobo_Core_Health_Read_Cache' ) && method_exists( 'Mobo_Core_Health_Read_Cache', 'refresh_sync_health_snapshot' ) ) {
				Mobo_Core_Health_Read_Cache::refresh_sync_health_snapshot( self::TARGETED_CONVERGENCE_WINDOW );
			}
			$dashboard = Mobo_Core_Sync_Health::get_operational_dashboard_status( self::TARGETED_CONVERGENCE_WINDOW, false );
			if ( ! is_array( $dashboard ) || 'unavailable' === sanitize_key( (string) ( $dashboard['operationalStatus'] ?? '' ) ) ) {
				return $this->result( false, 'Operational Sync Health could not classify post-upgrade recovery targets.', $this->get_manual_sync_status() );
			}

			$targets = array();
			foreach ( (array) ( $dashboard['unownedRows'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$guid = sanitize_text_field( (string) ( $row['productGuid'] ?? '' ) );
				$wp_id = absint( $row['wpProductId'] ?? ( $row['wpId'] ?? 0 ) );
				if ( '' === $guid || $wp_id <= 0 ) { continue; }
				$targets[] = array(
					'productGuid'       => $guid,
					'wpProductId'       => $wp_id,
					'postIncomplete'    => ! empty( $row['postIncomplete'] ),
					'mapIncomplete'     => ! empty( $row['mapIncomplete'] ),
					'localStructureSane'=> array_key_exists( 'localStructureSane', $row ) ? (bool) $row['localStructureSane'] : true,
				);
			}

			$total_unowned = absint( $dashboard['operational']['unownedConvergenceGap'] ?? count( $targets ) );

			if ( empty( $targets ) ) {
				return $this->result( true, 'No post-upgrade convergence gaps require exact recovery.', array_merge( $this->get_manual_sync_status(), array( 'noTargets' => true, 'targetCount' => 0 ) ) );
			}

			$now = time();
			$state = array(
				'syncId'                       => wp_generate_uuid4(),
				'status'                       => 'running',
				'source'                       => sanitize_key( (string) $source ),
				'repairMode'                   => true,
				'hashCheckBypassed'            => true,
				'categorySynced'               => true,
				'productQueue'                 => array(),
				'currentProductGuid'           => '',
				'currentProductId'             => 0,
				'productTotalCount'            => 0,
				'processedProducts'            => 0,
				'startedAt'                    => $now,
				'completedAt'                  => 0,
				'updatedAt'                    => $now,
				'lastMessage'                  => sprintf( 'Post-upgrade targeted convergence recovery owns %d actionable local product(s); current worker window: %d.', $total_unowned, count( $targets ) ),
				'lastError'                    => '',
				'repairIntegrityPhase'          => 'done',
				'repairIntegrityComplete'       => true,
				'repairProductsComplete'        => true,
				'postSyncIntegrityPhase'        => 'done',
				'postSyncIntegrityComplete'     => true,
				'convergenceRecoveryComplete'   => false,
				'convergenceRecoveryAttempted'  => array(),
				'convergenceRecoveryScanned'    => 0,
				'convergenceRecoveryQueued'     => 0,
				'convergenceRecoverySkipped'    => 0,
				'convergenceRecoveryUnresolved' => 0,
				'convergenceRecoveryCursor'     => 0,
				'missingImageRecoveryComplete' => true,
				'targetedConvergenceOnly'       => true,
				'targetedConvergenceOwnsUnowned'=> true,
				'targetedConvergenceTargets'    => array_slice( $targets, 0, self::TARGETED_CONVERGENCE_WINDOW ),
			);
			if ( ! $this->persist_manual_sync_state_verified( $state ) ) {
				return $this->result( false, 'Targeted convergence recovery checkpoint could not be persisted.', $this->get_manual_sync_status() );
			}
			return $this->result( true, $state['lastMessage'], array_merge( $this->get_manual_sync_status(), array( 'targetCount' => count( $targets ), 'ownedCount' => $total_unowned ) ) );
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && 'no-lock' !== $lock ) {
				Mobo_Core_Lock::release( 'manual_sync_start', $lock );
			}
		}
	}


	/**
	 * Recover a stalled running checkpoint without resetting any cursor/state.
	 *
	 * Only a generation with no active manual_sync lease and no checkpoint
	 * progress for the safety window is eligible. Remote callers with another
	 * explicit syncId cannot take over the old generation.
	 *
	 * @param array  $current Current public status.
	 * @param string $requested_sync_id Requested generation.
	 * @param string $source Request source.
	 * @param bool   $repair_mode Requested mode.
	 * @return array|null Result when recovered, otherwise null.
	 */
	private function recover_stalled_manual_sync_checkpoint( $current, $requested_sync_id, $source, $repair_mode ) {
		if ( empty( $current['isRunning'] ) || empty( $current['stalledWithoutWorker'] ) ) {
			return null;
		}

		$current_repair = ! empty( $current['repairMode'] );
		if ( $current_repair !== (bool) $repair_mode ) {
			return null;
		}

		$current_sync_id   = sanitize_text_field( (string) ( $current['syncId'] ?? '' ) );
		$requested_sync_id = sanitize_text_field( (string) $requested_sync_id );
		if ( '' !== $requested_sync_id && '' !== $current_sync_id && ! hash_equals( $current_sync_id, $requested_sync_id ) ) {
			return null;
		}

		if ( class_exists( 'Mobo_Core_Lock' ) && Mobo_Core_Lock::is_locked( 'manual_sync' ) ) {
			return null;
		}

		$state = $this->get_manual_sync_state();
		if ( 'running' !== sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
			return null;
		}

		$state_sync_id = sanitize_text_field( (string) ( $state['syncId'] ?? '' ) );
		if ( '' === $state_sync_id || ( '' !== $current_sync_id && ! hash_equals( $current_sync_id, $state_sync_id ) ) ) {
			return null;
		}

		$updated_at = absint( $state['updatedAt'] ?? 0 );
		if ( $updated_at <= 0 || ( time() - $updated_at ) < self::STALE_RUNNING_RECOVERY_SECONDS ) {
			return null;
		}

		$state['staleRecoveryCount']      = absint( $state['staleRecoveryCount'] ?? 0 ) + 1;
		$state['lastStaleRecoveryAt']     = time();
		$state['lastStaleRecoveryReason'] = 'worker-missing';
		$state['lastError']               = '';
		$state['lastMessage']             = $current_repair
			? 'Repair بدون Worker فعال از آخرین checkpoint پایدار بازیابی شد و ادامه می‌یابد.'
			: 'Sync بدون Worker فعال از آخرین checkpoint پایدار بازیابی شد و ادامه می‌یابد.';

		if ( ! $this->persist_manual_sync_state_verified( $state ) ) {
			return $this->result( false, 'Checkpoint گیرکرده تشخیص داده شد اما ثبت recovery پایدار نشد؛ state دست‌نخورده باقی ماند.', $this->get_manual_sync_status() );
		}

		return $this->result(
			true,
			$state['lastMessage'],
			array_merge( $this->get_manual_sync_status(), array( 'staleRecovered' => true, 'recoveredSyncId' => $state_sync_id, 'recoverySource' => sanitize_key( (string) $source ) ) )
		);
	}

	/**
	 * Resume the same durable manual-sync generation after a Portal wait.
	 *
	 * Only waiting_for_portal is resumable. Done/cancelled generations must be
	 * restarted explicitly so stale cursors cannot be revived accidentally.
	 *
	 * @return array
	 */
	public function resume_manual_sync() {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return $this->result( false, 'ادامه Sync تا پایان آپدیت افزونه متوقف است.', array_merge( $this->get_manual_sync_status(), array( 'pausedForUpgrade' => true, 'upgradeBarrier' => Mobo_Core_Upgrade_Coordinator::get_status() ) ) );
		}

		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'manual_sync', 30 ) : 'no-lock';
		if ( false === $lock ) {
			return $this->result( false, 'یک Worker Sync فعال است؛ Resume بدون تغییر state متوقف شد.', array_merge( $this->get_manual_sync_status(), array( 'locked' => true ) ) );
		}

		try {
			$state = $this->get_manual_sync_state();
			$status = sanitize_key( (string) ( $state['status'] ?? '' ) );
			$sync_id = sanitize_text_field( (string) ( $state['syncId'] ?? '' ) );

			if ( 'running' === $status && '' !== $sync_id ) {
				return $this->result( true, 'Sync از قبل در حال اجرا است.', array_merge( $this->get_manual_sync_status(), array( 'alreadyRunning' => true ) ) );
			}

			if ( 'waiting_for_portal' !== $status || '' === $sync_id ) {
				return $this->result( false, 'فقط Sync منتظر اتصال به MoboCore قابل Resume است. برای وضعیت فعلی Start جدید انجام دهید.', $this->get_manual_sync_status() );
			}

			$state['status']                = 'running';
			$state['lastError']             = '';
			$state['transientRetryCount']   = 0;
			$state['lastTransientError']    = '';
			$state['waitingForPortalSince'] = 0;
			$state['nextRetryAt']           = 0;
			$state['lastMessage']           = 'همگام‌سازی از همان نسل و آخرین checkpoint ذخیره‌شده ادامه داده می‌شود.';
			$state['updatedAt']             = time();

			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'نسل Sync هنگام Resume تغییر کرد؛ state دست‌نخورده باقی ماند.', $this->get_manual_sync_status() );
			}

			return $this->result( true, 'ادامه Sync از آخرین checkpoint شروع شد.', $this->get_manual_sync_status() );
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && 'no-lock' !== $lock ) {
				Mobo_Core_Lock::release( 'manual_sync', $lock );
			}
		}
	}

	public function cancel_manual_sync() {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return $this->result( false, 'لغو Sync هنگام Drain آپدیت مجاز نیست؛ وضعیت و Cursor حفظ شده‌اند.', array_merge( $this->get_manual_sync_status(), array( 'pausedForUpgrade' => true, 'upgradeBarrier' => Mobo_Core_Upgrade_Coordinator::get_status() ) ) );
		}

		$state = $this->get_manual_sync_state();
		$now   = time();

		$state['status']            = 'cancelled';
		$state['completedAt']       = $now;
		$state['updatedAt']         = $now;
		$state['cancelRequestedAt'] = $now;
		$state['nextRetryAt']       = 0;
		$state['lastError']         = '';
		$state['lastTransientError'] = '';
		$state['lastMessage']       = 'همگام‌سازی محصولات متوقف شد.';

		if ( ! $this->save_manual_sync_state( $state ) ) {
			return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
		}

		return $this->result(
			true,
			'همگام‌سازی محصولات متوقف شد.',
			$this->get_manual_sync_status()
		);
	}

	public function reset_manual_sync_state() {
		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'manual_sync', 30 ) : 'no-lock';
		if ( false === $lock ) {
			return false;
		}

		try {
			$legacy_owner_allowed = ! class_exists( 'Mobo_Core_Connection_Context' ) || ! method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) || Mobo_Core_Connection_Context::legacy_default_owner_allowed();
			if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() ) {
				Mobo_Core_State_Store::delete( self::STATE_KEY );
				if ( $legacy_owner_allowed && ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) ) {
					delete_option( self::STATE_OPTION );
					wp_cache_delete( self::STATE_OPTION, 'options' );
				}
				if ( null !== Mobo_Core_State_Store::get( self::STATE_KEY, null ) ) { return false; }
			} else {
				if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { return false; }
				if ( ! $legacy_owner_allowed ) { return false; }
				delete_option( self::STATE_OPTION );
				wp_cache_delete( self::STATE_OPTION, 'options' );
				if ( false !== get_option( self::STATE_OPTION, false ) ) { return false; }
			}
			$this->checkpoint_state         = null;
			$this->checkpoint_pending_steps = 0;
			return true;
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && 'no-lock' !== $lock ) {
				Mobo_Core_Lock::release( 'manual_sync', $lock );
			}
		}
	}

	/**
	 * Read the large sync checkpoint from the dedicated state table.
	 * The first read after upgrading migrates and deletes the legacy option.
	 *
	 * @return array
	 */
	private function load_durable_sync_state() {
		if ( ! class_exists( 'Mobo_Core_State_Store' ) || ! Mobo_Core_State_Store::is_available() ) {
			if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { return array(); }
			if ( class_exists( 'Mobo_Core_Connection_Context' ) && method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) && ! Mobo_Core_Connection_Context::legacy_default_owner_allowed() ) { return array(); }
			$legacy = get_option( self::STATE_OPTION, array() );
			return is_array( $legacy ) ? $legacy : array();
		}
		if ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { Mobo_Core_State_Store::migrate_option( self::STATE_OPTION, self::STATE_KEY ); }
		$state = Mobo_Core_State_Store::get( self::STATE_KEY, array() );
		return is_array( $state ) ? $state : array();
	}

	public function get_manual_sync_state() {
		$default = array(
			'syncId'                       => '',
			'status'                       => 'idle',
			'source'                       => '',
			'repairMode'                   => false,
			'hashCheckBypassed'            => false,

			'categorySynced'               => false,
			'categorySnapshotRetryCount'    => 0,
			'categorySyncDegraded'          => false,
			'categorySyncSkippedRows'       => 0,
			'categorySyncUnresolved'        => array(),
			'categoryRetiredRows'           => 0,

			'productPage'                  => 1,
			'productCursor'                => 0,
			'productCursorMode'            => 'cursor',
			'productCursorSupported'       => false,
			'productQueue'                 => array(),

			'currentProductGuid'           => '',
			'currentProductId'             => 0,
			'currentProductPortalId'       => 0,
			'currentProductSourceRevision' => 0,
			'currentProductSourceVersion'  => '',
			'currentProductSourceHash'     => '',
			'currentProductSnapshotUs'    => 0,
			'currentProductImages'         => array(),
			'currentProductImagesPresent'  => false,
			'currentProductAttributes'     => array(),
			'currentProductImageOffset'    => 0,
			'currentProductWasExisting'    => false,
			'currentProductImagesDone'     => false,
			'currentProductCanHaveVariants'=> false,

			'variantPage'                  => 1,

			'productTotalCount'            => 0,
			'productTotalPages'            => 0,
			'processedProducts'            => 0,
			'remainingProducts'            => 0,

			'currentVariantTotalCount'     => 0,
			'currentVariantTotalPages'     => 0,
			'currentVariantProcessedPages' => 0,
			'currentVariantCursor'          => 0,
			'currentVariantCursorDisabled'  => false,

			'startedAt'                    => 0,
			'completedAt'                  => 0,
			'updatedAt'                    => 0,
			'lastMessage'                  => '',
			'lastError'                    => '',
			'transientRetryCount'           => 0,
			'lastTransientError'            => '',
			'waitingForPortalSince'         => 0,
			'waitingForPortalReason'        => '',
			'nextRetryAt'                  => 0,
			'cancelRequestedAt'            => 0,
			'staleRecoveryCount'           => 0,
			'lastStaleRecoveryAt'          => 0,
			'lastStaleRecoveryReason'      => '',
			'repairLivenessLockKey'        => '',
			'repairLivenessLockCount'      => 0,
			'repairProductLockQuarantined' => 0,
			'repairProductLockSamples'     => array(),
			'repairParentConflictCount'    => 0,
			'repairParentConflictSamples'  => array(),
			'repairParentConflictGuids'    => array(),

			'repairIntegrityPhase'           => 'done',
			'repairIntegrityCursor'          => 0,
			'repairIntegrityComplete'        => true,
			'repairIntegrityStats'           => array(),

			'repairProductsComplete'         => false,
			'postSyncIntegrityPhase'           => 'done',
			'postSyncIntegrityCursor'          => 0,
			'postSyncIntegrityComplete'        => true,
			'postSyncIntegrityStats'           => array(),

			'convergenceRecoveryComplete'     => true,
			'convergenceRecoveryAttempted'    => array(),
			'convergenceRecoveryScanned'      => 0,
			'convergenceRecoveryQueued'       => 0,
			'convergenceRecoverySkipped'      => 0,
			'convergenceRecoveryUnresolved'   => 0,
			'convergenceRecoveryCursor'       => 0,
			'targetedConvergenceOnly'       => false,
			'targetedConvergenceOwnsUnowned'=> false,
			'targetedConvergenceTargets'    => array(),

			'missingImageRecoveryCursor'      => 0,
			'missingImageRecoveryScanned'     => 0,
			'missingImageRecoveryQueued'      => 0,
			'missingImageRecoverySkipped'     => 0,
			'missingImageRecoveryFailed'      => 0,
			'missingImageRecoveryComplete'    => false,
		);

		$state = $this->checkpoint_coalescing && is_array( $this->checkpoint_state )
			? $this->checkpoint_state
			: $this->load_durable_sync_state();

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$had_post_sync_state = array_key_exists( 'postSyncIntegrityComplete', $state );
		$had_convergence_recovery_state = array_key_exists( 'convergenceRecoveryComplete', $state );
		$state = wp_parse_args( $state, $default );
		/* An in-flight Repair created by an older build did not have the post-sync
		 * phase. Do not silently treat that missing field as completed after upgrade. */
		if ( ! empty( $state['repairMode'] ) && ! $had_post_sync_state ) {
			$state['postSyncIntegrityPhase']    = 'variation-topology';
			$state['postSyncIntegrityCursor']   = 0;
			$state['postSyncIntegrityComplete'] = false;
			$state['postSyncIntegrityStats']    = array();
		}
		/* 10.34.68 added exact convergence recovery after the catalogue pass. An
		 * in-flight Repair from an older build must execute that phase instead of
		 * inheriting the default completed value. */
		if ( ! empty( $state['repairMode'] ) && ! $had_convergence_recovery_state ) {
			$state['convergenceRecoveryComplete']   = false;
			$state['convergenceRecoveryAttempted']  = array();
			$state['convergenceRecoveryScanned']    = 0;
			$state['convergenceRecoveryQueued']     = 0;
			$state['convergenceRecoverySkipped']    = 0;
			$state['convergenceRecoveryUnresolved'] = 0;
			$state['convergenceRecoveryCursor']     = 0;
		}

		return $state;
	}

	public function get_manual_sync_status() {
		$state = $this->get_manual_sync_state();

		$total           = absint( $state['productTotalCount'] );
		$processed       = absint( $state['processedProducts'] );
		$last_error      = sanitize_text_field( (string) $state['lastError'] );
		$current_status  = sanitize_key( (string) $state['status'] );

		/*
		 * MoboCore totalCount can be a stale estimate while hasMore=false is the
		 * authoritative terminal signal. When sync is already done, the UI must not
		 * keep showing a phantom remaining product such as 529/530.
		 */
		if ( 'done' === $current_status ) {
			if ( $total <= 0 || $processed < $total ) {
				$total = $processed;
			}

			$remaining = 0;
			$progress  = 100;
		} else {
			/* A fetched parent is counted before its image/variant sub-steps finish, and
			 * productQueue can still hold authoritative snapshots after Portal says
			 * hasMore=false. Never expose terminal-looking 100%/0 while either exists. */
			$queued_products = is_array( $state['productQueue'] ) ? count( $state['productQueue'] ) : 0;
			$current_product = '' !== trim( (string) $state['currentProductGuid'] ) ? 1 : 0;
			$local_remaining = $queued_products + $current_product;
			$remote_remaining = $total > $processed ? $total - $processed : 0;
			$remaining = max( $local_remaining, $remote_remaining );
			$effective_total = max( $total, $processed + $remaining );
			$total = $effective_total;
			$progress = $effective_total > 0 ? round( ( $processed / $effective_total ) * 100, 2 ) : 0;
			if ( $progress >= 100 ) {
				$progress = 99.99;
			}
		}

		$next_retry_at   = absint( $state['nextRetryAt'] ?? 0 );
		$is_waiting      = 'waiting_for_portal' === $current_status;
		$is_retry_due    = $is_waiting && ( 0 === $next_retry_at || $next_retry_at <= time() );
		$should_continue = ( 'running' === $current_status && '' === $last_error ) || ( $is_retry_due && '' === $last_error );
		$worker_lock     = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_status( 'manual_sync' ) : array( 'active' => false );
		$updated_at      = absint( $state['updatedAt'] ?? 0 );
		$stalled_without_worker = 'running' === $current_status
			&& empty( $worker_lock['active'] )
			&& $updated_at > 0
			&& ( time() - $updated_at ) >= self::STALE_RUNNING_RECOVERY_SECONDS;

		return array(
			'syncId'                       => sanitize_text_field( (string) $state['syncId'] ),
			'status'                       => $current_status,
			'source'                       => sanitize_key( (string) $state['source'] ),
			'repairMode'                   => ! empty( $state['repairMode'] ),
			'hashCheckBypassed'            => ! empty( $state['hashCheckBypassed'] ),
			'repairCompletedAt'            => self::get_repair_completed_at(),
			'isRepairCompleted'            => self::is_repair_completed() && absint( $state['repairParentConflictCount'] ?? 0 ) === 0 && 'needs_attention' !== $current_status,

			'isRunning'                    => 'running' === $current_status,
			'isWaitingForPortal'            => $is_waiting,
			'isRetryDue'                    => $is_retry_due,
			'secondsUntilNextRetry'         => $is_waiting && $next_retry_at > time() ? max( 0, $next_retry_at - time() ) : 0,
			'isDone'                       => 'done' === $current_status,
			'isCancelled'                  => 'cancelled' === $current_status,

			'categorySynced'               => (bool) $state['categorySynced'],
			'categorySyncDegraded'          => ! empty( $state['categorySyncDegraded'] ),
			'categorySyncSkippedRows'       => absint( $state['categorySyncSkippedRows'] ?? 0 ),
			'categoryRetiredRows'           => absint( $state['categoryRetiredRows'] ?? 0 ),
			'categorySyncUnresolved'        => isset( $state['categorySyncUnresolved'] ) && is_array( $state['categorySyncUnresolved'] ) ? array_slice( $state['categorySyncUnresolved'], 0, 25 ) : array(),
			'repairLivenessLockKey'         => sanitize_text_field( (string) ( $state['repairLivenessLockKey'] ?? '' ) ),
			'repairLivenessLockCount'       => absint( $state['repairLivenessLockCount'] ?? 0 ),
			'repairProductLockQuarantined'  => absint( $state['repairProductLockQuarantined'] ?? 0 ),
			'repairProductLockSamples'      => isset( $state['repairProductLockSamples'] ) && is_array( $state['repairProductLockSamples'] ) ? array_slice( $state['repairProductLockSamples'], 0, 25 ) : array(),
			'repairParentConflictCount'     => absint( $state['repairParentConflictCount'] ?? 0 ),
			'repairParentConflictSamples'   => isset( $state['repairParentConflictSamples'] ) && is_array( $state['repairParentConflictSamples'] ) ? array_slice( $state['repairParentConflictSamples'], 0, 25 ) : array(),
			'isNeedsAttention'              => 'needs_attention' === $current_status,
			'repairIntegrityPhase'           => sanitize_key( (string) $state['repairIntegrityPhase'] ),
			'repairIntegrityCursor'          => absint( $state['repairIntegrityCursor'] ),
			'repairIntegrityComplete'        => ! empty( $state['repairIntegrityComplete'] ),
			'repairIntegrityStats'           => is_array( $state['repairIntegrityStats'] ) ? $state['repairIntegrityStats'] : array(),
			'repairProductsComplete'         => ! empty( $state['repairProductsComplete'] ),
			'postSyncIntegrityPhase'           => sanitize_key( (string) $state['postSyncIntegrityPhase'] ),
			'postSyncIntegrityCursor'          => absint( $state['postSyncIntegrityCursor'] ),
			'postSyncIntegrityComplete'        => ! empty( $state['postSyncIntegrityComplete'] ),
			'postSyncIntegrityStats'           => is_array( $state['postSyncIntegrityStats'] ) ? $state['postSyncIntegrityStats'] : array(),
			'convergenceRecoveryComplete'      => ! empty( $state['convergenceRecoveryComplete'] ),
			'convergenceRecoveryScanned'       => absint( $state['convergenceRecoveryScanned'] ?? 0 ),
			'convergenceRecoveryQueued'        => absint( $state['convergenceRecoveryQueued'] ?? 0 ),
			'convergenceRecoverySkipped'       => absint( $state['convergenceRecoverySkipped'] ?? 0 ),
			'convergenceRecoveryUnresolved'    => absint( $state['convergenceRecoveryUnresolved'] ?? 0 ),
			'convergenceRecoveryCursor'        => absint( $state['convergenceRecoveryCursor'] ?? 0 ),
			'missingImageRecoveryCursor'      => absint( $state['missingImageRecoveryCursor'] ),
			'missingImageRecoveryScanned'     => absint( $state['missingImageRecoveryScanned'] ),
			'missingImageRecoveryQueued'      => absint( $state['missingImageRecoveryQueued'] ),
			'missingImageRecoverySkipped'     => absint( $state['missingImageRecoverySkipped'] ),
			'missingImageRecoveryFailed'      => absint( $state['missingImageRecoveryFailed'] ),
			'missingImageRecoveryComplete'    => ! empty( $state['missingImageRecoveryComplete'] ),

			'productPage'                  => absint( $state['productPage'] ),
			'productCursor'                => absint( $state['productCursor'] ),
			'productCursorMode'            => sanitize_key( (string) $state['productCursorMode'] ),
			'productCursorSupported'       => (bool) $state['productCursorSupported'],
			'queuedProducts'               => is_array( $state['productQueue'] ) ? count( $state['productQueue'] ) : 0,

			'currentProductGuid'           => sanitize_text_field( (string) $state['currentProductGuid'] ),
			'currentProductId'             => absint( $state['currentProductId'] ),
			'currentProductImageOffset'    => absint( $state['currentProductImageOffset'] ),
			'currentProductImagesCount'    => is_array( $state['currentProductImages'] ) ? count( $state['currentProductImages'] ) : 0,
			'currentProductAttributesCount'=> is_array( $state['currentProductAttributes'] ) ? count( $state['currentProductAttributes'] ) : 0,
			'currentProductImagesDone'     => (bool) $state['currentProductImagesDone'],
			'currentProductCanHaveVariants'=> (bool) $state['currentProductCanHaveVariants'],

			'variantPage'                  => absint( $state['variantPage'] ),

			'productTotalCount'            => $total,
			'productTotalPages'            => absint( $state['productTotalPages'] ),
			'processedProducts'            => $processed,
			'remainingProducts'            => $remaining,
			'progressPercent'              => $progress,

			'currentVariantTotalCount'     => absint( $state['currentVariantTotalCount'] ),
			'currentVariantTotalPages'     => absint( $state['currentVariantTotalPages'] ),
			'currentVariantProcessedPages' => absint( $state['currentVariantProcessedPages'] ),
			'currentVariantCursor'          => absint( $state['currentVariantCursor'] ),

			'startedAt'                    => absint( $state['startedAt'] ),
			'completedAt'                  => absint( $state['completedAt'] ),
			'updatedAt'                    => absint( $state['updatedAt'] ),
			'waitingForPortalSince'         => absint( $state['waitingForPortalSince'] ?? 0 ),
			'waitingForPortalReason'        => sanitize_key( (string) ( $state['waitingForPortalReason'] ?? '' ) ),
			'nextRetryAt'                  => $next_retry_at,
			'cancelRequestedAt'            => absint( $state['cancelRequestedAt'] ?? 0 ),
			'workerLock'                    => $worker_lock,
			'stalledWithoutWorker'          => $stalled_without_worker,
			'staleRecoveryCount'            => absint( $state['staleRecoveryCount'] ?? 0 ),
			'lastStaleRecoveryAt'           => absint( $state['lastStaleRecoveryAt'] ?? 0 ),
			'lastStaleRecoveryReason'       => sanitize_key( (string) ( $state['lastStaleRecoveryReason'] ?? '' ) ),

			'lastMessage'                  => sanitize_text_field( (string) $state['lastMessage'] ),
			'lastError'                    => $last_error,
			'transientRetryCount'           => absint( $state['transientRetryCount'] ?? 0 ),
			'repairLivenessLockKey'         => sanitize_text_field( (string) ( $state['repairLivenessLockKey'] ?? '' ) ),
			'repairLivenessLockCount'       => absint( $state['repairLivenessLockCount'] ?? 0 ),
			'repairProductLockQuarantined'  => absint( $state['repairProductLockQuarantined'] ?? 0 ),
			'lastTransientError'            => sanitize_text_field( (string) ( $state['lastTransientError'] ?? '' ) ),

			'shouldContinue'               => $should_continue,
			'recommendedDelayMs'           => $should_continue ? 0 : ( $is_waiting && $next_retry_at > time() ? max( 1000, ( $next_retry_at - time() ) * 1000 ) : 5000 ),
		);
	}

	public function run_manual_sync_step() {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return $this->result( true, 'Sync در نقطه امن برای آپدیت افزونه Pause شده است.', array_merge( $this->get_manual_sync_status(), array( 'pausedForUpgrade' => true, 'upgradeBarrier' => Mobo_Core_Upgrade_Coordinator::get_status() ) ) );
		}

		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'manual_sync', 300 ) : 'no-lock';
		if ( false === $lock ) {
			return $this->result( true, 'یک Worker دیگر در حال اجرای مرحله Sync است؛ این اجرا بدون تغییر state متوقف شد.', array_merge( $this->get_manual_sync_status(), array( 'locked' => true ) ) );
		}

		try {
			/*
			 * Cron may coalesce several checkpoints in one PHP request while the
			 * manual_sync lock is intentionally released between steps. A cancel or
			 * a newer sync generation can therefore be persisted by another request
			 * between two iterations. Re-read only the durable generation boundary
			 * after acquiring ownership, before any product mutation.
			 */
			if ( $this->checkpoint_coalescing && is_array( $this->checkpoint_state ) ) {
				$durable       = $this->load_durable_sync_state();
				$buffered_id   = sanitize_text_field( (string) ( $this->checkpoint_state['syncId'] ?? '' ) );
				$durable_id    = is_array( $durable ) ? sanitize_text_field( (string) ( $durable['syncId'] ?? '' ) ) : '';
				$durable_state = is_array( $durable ) ? sanitize_key( (string) ( $durable['status'] ?? '' ) ) : '';

				$generation_changed = '' !== $buffered_id && '' !== $durable_id && ! hash_equals( $buffered_id, $durable_id );
				$cancelled_current  = 'cancelled' === $durable_state && '' !== $buffered_id && '' !== $durable_id && hash_equals( $buffered_id, $durable_id );

				if ( $generation_changed || $cancelled_current ) {
					$this->checkpoint_state         = is_array( $durable ) ? $durable : array();
					$this->checkpoint_pending_steps = 0;
					$this->checkpoint_last_flush_at = microtime( true );

					return $this->result(
						! $cancelled_current,
						$cancelled_current ? 'Sync لغو شده است؛ Worker قدیمی قبل از هر تغییر جدید متوقف شد.' : 'نسل جدید Sync شناسایی شد؛ Worker قدیمی بدون تغییر داده متوقف شد.',
						array_merge( $this->get_manual_sync_status(), array( 'staleGenerationStopped' => true ) )
					);
				}
			}

			if ( class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) ) {
				return Mobo_Core_Cache_Mutation_Guard::run(
					function () {
						return $this->run_manual_sync_step_guarded();
					},
					! empty( $this->get_manual_sync_state()['repairMode'] ) ? 'manual-repair' : 'manual-sync'
				);
			}

			return $this->run_manual_sync_step_guarded();
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && 'no-lock' !== $lock ) {
				Mobo_Core_Lock::release( 'manual_sync', $lock );
			}
		}
	}

	/**
	 * Execute one manual Sync/Repair step inside the cache mutation guard.
	 *
	 * @return array
	 */
	private function run_manual_sync_step_guarded() {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return $this->result( true, 'Sync در نقطه امن برای آپدیت افزونه Pause شده است.', array_merge( $this->get_manual_sync_status(), array( 'pausedForUpgrade' => true, 'upgradeBarrier' => Mobo_Core_Upgrade_Coordinator::get_status() ) ) );
		}

		$api            = new Mobo_Core_API_Client();
		$state          = $this->get_manual_sync_state();
		$this->repair_mode = ! empty( $state['repairMode'] );
		list( $products_limit, $variants_limit ) = self::effective_page_sizes();

		if ( 'idle' === $state['status'] || '' === $state['syncId'] ) {
			return $this->result( false, 'همگام‌سازی شروع نشده است.', $this->get_manual_sync_status() );
		}

		if ( 'cancelled' === $state['status'] ) {
			return $this->result( false, 'همگام‌سازی متوقف شده است.', $this->get_manual_sync_status() );
		}

		if ( 'done' === $state['status'] ) {
			return $this->result( true, 'همگام‌سازی قبلاً کامل شده است.', $this->get_manual_sync_status() );
		}
		if ( 'needs_attention' === $state['status'] ) {
			return $this->result( false, 'Repair با تعارض هویت والد Variant متوقف شده است؛ برای این نسل تأیید تکمیل ممنوع است.', $this->get_manual_sync_status() );
		}

		if ( 'waiting_for_portal' === $state['status'] ) {
			$next_retry_at = absint( $state['nextRetryAt'] ?? 0 );

			if ( $next_retry_at > 0 && $next_retry_at > time() ) {
				$state['updatedAt'] = time();
				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result( true, 'MoboCore هنوز آماده تلاش مجدد نیست. وضعیت sync حفظ شده است.', $this->get_manual_sync_status() );
			}

			$waiting_reason = sanitize_key( (string) ( $state['waitingForPortalReason'] ?? '' ) );
			$state['status'] = 'running';
			/* A dependency circuit deferral did not send a new HTTP request, so it must
			 * not erase the count of actual transport attempts accumulated so far. */
			if ( 'network_backoff' !== $waiting_reason ) {
				$state['transientRetryCount'] = 0;
			}
			$state['waitingForPortalReason'] = '';
			$state['lastError']              = '';
			$state['lastMessage']            = 'اتصال به MoboCore دوباره بررسی می‌شود؛ ادامه از آخرین نقطه ذخیره‌شده.';
			$state['updatedAt']              = time();
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}
		}

		/*
		 * The existing Repair button also performs bounded legacy data cleanup
		 * before the authoritative Portal snapshot. Each call handles one small
		 * slice so large stores do not turn an admin click into a long request.
		 */
		if ( ! empty( $state['repairMode'] ) && empty( $state['repairIntegrityComplete'] ) ) {
			if ( ! class_exists( 'Mobo_Core_Repair_Integrity' ) ) {
				$state['lastError']   = 'Repair integrity service is unavailable.';
				$state['lastMessage'] = 'مرحله ترمیم داده‌های قدیمی در دسترس نیست.';
				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}
				return $this->result( false, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			$integrity = new Mobo_Core_Repair_Integrity();
			$integrity_result = $integrity->run_slice( $state );
			if ( is_wp_error( $integrity_result ) ) {
				if ( $this->is_retryable_repair_integrity_error( $integrity_result ) ) {
					return $this->handle_transient_request_error( $state, $integrity_result, 'خواندن داده‌های مرحله Repair موقتاً ناموفق بود.' );
				}
				$state['lastError']   = sanitize_text_field( $integrity_result->get_error_message() );
				$state['lastMessage'] = 'ترمیم داده‌های قدیمی با خطا متوقف شد: ' . $state['lastError'];
				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}
				return $this->result( false, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			$this->clear_transient_request_error( $state );
			$state['lastError']   = '';
			$state['lastMessage'] = sanitize_text_field( (string) ( isset( $integrity_result['message'] ) ? $integrity_result['message'] : 'مرحله ترمیم داده‌های قدیمی اجرا شد.' ) );
			$state['updatedAt']   = time();
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}
			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * A catalogue filtered by OnlyInStock may intentionally omit an existing local
		 * product after its upstream stock reaches zero. Omission from the list is not
		 * authoritative deletion evidence, but an already-local product with a durable
		 * unowned convergence gap must not remain stale forever. After the catalogue
		 * pass, Repair therefore exact-fetches only current local unowned products by
		 * GUID and feeds them through the same Parent/Variant pipeline. New products are
		 * never discovered by this phase and terminal/active-owned rows are excluded by
		 * Operational Health classification.
		 */
		if ( ! empty( $state['repairMode'] ) && ! empty( $state['repairProductsComplete'] ) && empty( $state['convergenceRecoveryComplete'] ) ) {
			if ( '' === trim( (string) ( $state['currentProductGuid'] ?? '' ) ) && empty( $state['productQueue'] ) ) {
				$recovery_result = $this->run_convergence_gap_recovery_step( $state, $api );
				if ( is_array( $recovery_result ) ) {
					return $recovery_result;
				}
			}
			/* When an exact snapshot has been queued, fall through to the ordinary product
			 * stages below. Their existing lock, webhook-watermark, hash and Variant
			 * boundaries remain the mutation authority. */
		}

		/*
		 * Once the authoritative product pass and exact local-gap recovery are complete,
		 * run a second bounded integrity pass over only the parents that were durably
		 * completed by this exact Repair generation. This avoids treating remaining
		 * OnlyInStock omissions as authoritative topology evidence.
		 */
		if ( ! empty( $state['repairMode'] ) && ! empty( $state['repairProductsComplete'] ) && ! empty( $state['convergenceRecoveryComplete'] ) && empty( $state['postSyncIntegrityComplete'] ) ) {
			if ( ! class_exists( 'Mobo_Core_Repair_Integrity' ) ) {
				return $this->result( false, 'مرحله نهایی Repair در دسترس نیست.', $this->get_manual_sync_status() );
			}
			$integrity = new Mobo_Core_Repair_Integrity();
			$post_result = $integrity->run_post_sync_slice( $state );
			if ( is_wp_error( $post_result ) ) {
				if ( $this->is_retryable_repair_integrity_error( $post_result ) ) {
					return $this->handle_transient_request_error( $state, $post_result, 'خواندن داده‌های مرحله نهایی Repair موقتاً ناموفق بود.' );
				}
				$state['lastError']   = sanitize_text_field( $post_result->get_error_message() );
				$state['lastMessage'] = 'مرحله نهایی Repair با خطا متوقف شد: ' . $state['lastError'];
				$this->save_manual_sync_state( $state );
				return $this->result( false, $state['lastMessage'], $this->get_manual_sync_status() );
			}
			$this->clear_transient_request_error( $state );
			$state['lastError']   = '';
			$state['lastMessage'] = sanitize_text_field( (string) ( $post_result['message'] ?? 'مرحله نهایی Repair اجرا شد.' ) );
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint مرحله نهایی Repair ذخیره نشد.', $this->get_manual_sync_status() );
			}
			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Repair-only image recovery pass. The normal product list may exclude a
		 * product that became out of stock mid-sync. Existing local products that
		 * have no usable image are fetched individually by GUID and only their
		 * image payload is applied. Stock, fields and variants are untouched.
		 */
		if ( ! empty( $state['repairMode'] ) && ! empty( $state['repairProductsComplete'] ) && ! empty( $state['convergenceRecoveryComplete'] ) && ! empty( $state['postSyncIntegrityComplete'] ) && empty( $state['missingImageRecoveryComplete'] ) ) {
			return $this->run_missing_image_repair_step( $state );
		}

		/*
		 * Step 1: Sync categories once before products.
		 */
		if ( empty( $state['categorySynced'] ) ) {
			if ( $this->rules->should_update_categories() ) {
				$category_snapshot_started_us = (int) floor( microtime( true ) * 1000000 );
				$response = $api->get_categories( $state['syncId'] );

				if ( is_wp_error( $response ) ) {
					return $this->handle_transient_request_error( $state, $response, 'خطا در همگام‌سازی دسته‌بندی‌ها.' );
				}

				$categories = $this->normalize_categories_api_response( $response );
				if ( is_wp_error( $categories ) ) {
					return $this->handle_transient_request_error( $state, $categories, 'پاسخ دسته‌بندی MoboCore نامعتبر بود.' );
				}
				$category_result = $this->category_sync->sync_categories_payload( $categories, $category_snapshot_started_us );
				if ( empty( $category_result['complete'] ) || absint( $category_result['skipped'] ) > 0 ) {
					$category_try = absint( $state['categorySnapshotRetryCount'] ?? 0 ) + 1;
					$category_max = 3;
					$state['categorySnapshotRetryCount'] = $category_try;
					$state['categorySyncSkippedRows'] = absint( $category_result['skipped'] ?? 0 );
					$state['categorySyncUnresolved'] = isset( $category_result['unresolved'] ) && is_array( $category_result['unresolved'] ) ? array_slice( $category_result['unresolved'], 0, 25 ) : array();
					$state['lastError'] = '';
					if ( $category_try < $category_max ) {
						$state['lastMessage'] = sprintf( 'همگام‌سازی دسته‌بندی ناقص بود؛ %d ردیف invalid/unresolved است. تلاش semantic %d از %d.', absint( $category_result['skipped'] ), $category_try, $category_max );
						if ( ! $this->save_manual_sync_state( $state ) ) { return $this->result( false, 'Checkpoint دسته‌بندی ذخیره نشد.', $this->get_manual_sync_status() ); }
						return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
					}
					/* Permanent/semantic source defects must not hold the entire Repair forever.
					 * Preserve a degraded marker, skip authoritative retirement because the
					 * snapshot was incomplete, and continue products from the last safe map. */
					$state['categorySynced'] = true;
					$state['categorySyncDegraded'] = true;
					$state['categorySnapshotRetryCount'] = 0;
					$state['lastMessage'] = sprintf( 'دسته‌بندی با وضعیت degraded ادامه یافت: %d ردیف منبع بعد از %d تلاش همچنان invalid/unresolved بود؛ Repair محصولات ادامه می‌یابد.', absint( $category_result['skipped'] ), $category_max );
					if ( ! $this->save_manual_sync_state( $state ) ) { return $this->result( false, 'Checkpoint دسته‌بندی degraded ذخیره نشد.', $this->get_manual_sync_status() ); }
					return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
				}
				$this->clear_transient_request_error( $state );
				$state['categorySnapshotRetryCount'] = 0;
				$state['categorySyncDegraded'] = false;
				$state['categorySyncSkippedRows'] = 0;
				$state['categorySyncUnresolved'] = array();
				$state['categoryRetiredRows'] = absint( $category_result['retired'] ?? 0 );

				Mobo_Core_Settings::update( 'mobo_core_categories_last_sync_at', time(), false );

				$state['categorySynced'] = true;
				$state['lastError']      = '';
				$state['lastMessage']    = sprintf(
					'دسته‌بندی‌ها همگام شدند. ایجاد: %d، بروزرسانی: %d، رد شده: %d',
					absint( $category_result['created'] ),
					absint( $category_result['updated'] ),
					absint( $category_result['skipped'] )
				);

				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			$state['categorySynced'] = true;
			$state['lastError']      = '';
			$state['lastMessage']    = 'همگام‌سازی دسته‌بندی غیرفعال است؛ در صورت ایجاد محصول جدید، دسته پیشفرض استفاده می‌شود.';
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Step 2: Fetch product page when queue is empty and no current product exists.
		 */
		if ( empty( $state['productQueue'] ) && '' === $state['currentProductGuid'] ) {
			$use_product_cursor = Mobo_Core_Settings::enabled( 'mobo_core_product_cursor_sync_enabled', '1' ) && 'page-fallback' !== (string) $state['productCursorMode'];

			/* Capture before the network request: an applied webhook during the HTTP
			 * response must be treated as newer than this potentially stale page. */
			$manual_snapshot_us = $this->current_wall_clock_us();
			$response = $api->get_products_page(
				absint( $state['productPage'] ),
				$products_limit,
				$state['syncId'],
				absint( $state['productCursor'] ),
				$use_product_cursor
			);

			if ( is_wp_error( $response ) ) {
				return $this->handle_transient_request_error( $state, $response, 'خطا در دریافت صفحه محصولات.' );
			}

			$items = $this->extract_explicit_list_data( $response, 'products page' );
			if ( is_wp_error( $items ) ) {
				return $this->handle_transient_request_error( $state, $items, 'پاسخ صفحه محصولات MoboCore نامعتبر بود.' );
			}
			$has_more_result = $this->validated_page_has_more( $response, absint( $state['productPage'] ) );
			if ( is_wp_error( $has_more_result ) ) {
				return $this->handle_transient_request_error( $state, $has_more_result, 'صفحه‌بندی محصولات MoboCore نامعتبر بود.' );
			}
			$has_more = (bool) $has_more_result;
			$this->clear_transient_request_error( $state );

			$total_count = absint( $this->get_value( $response, 'totalCount', 0 ) );
			$total_pages = absint( $this->get_value( $response, 'totalPages', 0 ) );

			if ( $total_count > 0 ) {
				$state['productTotalCount'] = $total_count;
			}

			if ( $total_pages > 0 ) {
				$state['productTotalPages'] = $total_pages;
			}


			$cursor_mode = sanitize_key( (string) $this->get_value( $response, 'cursorMode', '' ) );
			if ( '' !== $cursor_mode ) {
				$state['productCursorMode']      = $cursor_mode;
				$state['productCursorSupported'] = true;

				$next_cursor = $this->get_value( $response, 'nextCursor', null );
				if ( $has_more && ( null === $next_cursor || '' === $next_cursor || absint( $next_cursor ) <= absint( $state['productCursor'] ) ) ) {
					/* Cursor semantics are optional optimization. A non-advancing cursor is
					 * not allowed to deadlock Repair: fall back durably to page-number mode. */
					$state['productCursorMode'] = 'page-fallback';
					$state['productCursorSupported'] = false;
					$state['productCursor'] = 0;
					$state['lastMessage'] = 'Cursor محصولات جلو نرفت؛ ادامه همگام‌سازی به حالت page fallback منتقل شد.';
				} elseif ( null !== $next_cursor && '' !== $next_cursor ) {
					$state['productCursor'] = absint( $next_cursor );
				}
			} elseif ( $use_product_cursor ) {
				/*
				 * Backward compatibility: older MoboCore builds ignore UseCursor/Cursor.
				 * After the first legacy response, fall back to page-number mode.
				 */
				$state['productCursorMode']      = 'page-fallback';
				$state['productCursorSupported'] = false;
			}

			/* Every manual product page is a point-in-time snapshot. Persist a local
			 * capture fence on each queued item so a foreground webhook that lands
			 * after this fetch can never be overwritten when the item is processed
			 * later. This protection is required for normal Full Sync as well as
			 * Repair. */
			foreach ( $items as $product_data ) {
				if ( is_array( $product_data ) ) {
					$product_data['_moboManualSnapshotUs'] = $manual_snapshot_us;
					if ( $this->is_repair_mode() ) {
						/* Backward-compatible marker for checkpoints created by older builds. */
						$product_data['_moboRepairSnapshotUs'] = $manual_snapshot_us;
					}
					$state['productQueue'][] = $product_data;
				}
			}

			$state['productPage'] = absint( $state['productPage'] ) + 1;

			if ( empty( $state['productQueue'] ) && ! $this->to_bool( $has_more ) ) {
				/*
				 * If MoboCore says there is no next page, sync is complete even when a
				 * previously reported totalCount was one item higher. Persist the
				 * effective total so the admin UI does not show a phantom remaining item.
				 */
				$state['productTotalCount'] = absint( $state['processedProducts'] );
				$state['status']            = 'done';
				$state['completedAt']       = time();
				$state['lastError']         = '';
				$state['lastMessage']       = 'همگام‌سازی محصولات کامل شد.';
				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result( true, 'همگام‌سازی محصولات کامل شد.', $this->get_manual_sync_status() );
			}

			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, 'صفحه محصولات دریافت شد.', $this->get_manual_sync_status() );
		}

		/*
		 * Step 3: Upsert one parent product.
		 */
		if ( '' === $state['currentProductGuid'] ) {
			$product_data = array_shift( $state['productQueue'] );

			if ( ! is_array( $product_data ) ) {
				$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
				$state['lastError']         = '';
				$state['lastMessage']       = 'محصول نامعتبر رد شد.';

				if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
					$state['status']      = 'done';
					$state['completedAt'] = time();
					$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
				}

				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			if ( $this->should_skip_product_by_url( $product_data ) ) {
				$skipped_url = sanitize_text_field( (string) $this->get_value( $product_data, 'url', '' ) );

				$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
				$state['lastError']         = '';
				$state['lastMessage']       = 'محصول به دلیل قرار داشتن آدرس در لیست عدم همگام‌سازی رد شد: ' . $skipped_url;

				if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
					$state['status']      = 'done';
					$state['completedAt'] = time();
					$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
				}

				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result(
					true,
					$state['lastMessage'],
					$this->get_manual_sync_status()
				);
			}

			$product_guid       = $this->extract_product_guid( $product_data );
			$manual_snapshot_us = isset( $product_data['_moboManualSnapshotUs'] )
				? absint( $product_data['_moboManualSnapshotUs'] )
				: ( isset( $product_data['_moboRepairSnapshotUs'] ) ? absint( $product_data['_moboRepairSnapshotUs'] ) : 0 );

			/*
			 * Manual/Repair pages are snapshots. A foreground webhook may have applied
			 * newer parent/variant state after this page was fetched but before this
			 * queued product reaches Step 3. Never let that old page overwrite the
			 * webhook. Refresh the exact product outside the product lock so webhook
			 * latency stays low; a second watermark check after lock acquisition closes
			 * the race.
			 */
			if ( '' !== $product_guid ) {
				$last_webhook_us = $this->get_last_webhook_applied_us( $product_guid );

				/* A legacy checkpoint without a snapshot time cannot safely be
				 * blessed with the current clock. Always fetch an exact fresh parent
				 * before applying it, even if no webhook watermark is currently stored. */
				if ( $manual_snapshot_us <= 0 || $last_webhook_us > $manual_snapshot_us ) {
					$manual_snapshot_us = $this->current_wall_clock_us();
					$fresh_response = $api->get_product_by_guid( $product_guid, $state['syncId'] );

					if ( is_wp_error( $fresh_response ) ) {
						array_unshift( $state['productQueue'], $product_data );
						return $this->handle_transient_request_error( $state, $fresh_response, 'Manual Sync برای جلوگیری از بازنویسی Webhook منتظر Snapshot تازه محصول است.' );
					}

					$fresh_items = $this->extract_explicit_list_data( $fresh_response, 'repair exact product refresh' );

					if ( is_wp_error( $fresh_items ) ) {
						array_unshift( $state['productQueue'], $product_data );
						return $this->handle_transient_request_error( $state, $fresh_items, 'Manual Sync پاسخ Snapshot تازه محصول را معتبر دریافت نکرد.' );
					}

					$fresh_product = null;
					foreach ( $fresh_items as $fresh_candidate ) {
						if ( is_array( $fresh_candidate ) && $product_guid === $this->extract_product_guid( $fresh_candidate ) ) {
							$fresh_product = $fresh_candidate;
							break;
						}
					}

					if ( ! is_array( $fresh_product ) ) {
						/* The exact endpoint no longer exposes this product. Keep the newer
						 * webhook-applied local state rather than replaying an older Repair page. */
						$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
						$state['lastError']         = '';
						$state['lastMessage']       = 'Repair یک Snapshot قدیمی را کنار گذاشت تا وضعیت جدیدتر Webhook حفظ شود: ' . $product_guid;
						$state['updatedAt']         = time();
						if ( ! $this->save_manual_sync_state( $state ) ) {
							return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
						}

						return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
					}

					$product_data = $fresh_product;
					if ( $this->should_skip_product_by_url( $product_data ) ) {
						$skipped_url = class_exists( 'Mobo_Core_Product_Exclusions' )
							? Mobo_Core_Product_Exclusions::get_payload_url( $product_data )
							: sanitize_text_field( (string) $this->get_value( $product_data, 'url', '' ) );
						$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
						$state['lastError']         = '';
						$state['lastMessage']       = 'Snapshot تازه محصول در فهرست عدم همگام‌سازی است و Repair/Sync آن را تغییر نداد: ' . sanitize_text_field( (string) $skipped_url );
						$state['updatedAt']         = time();
						if ( ! $this->save_manual_sync_state( $state ) ) {
							return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
						}
						return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
					}

					$product_data['_moboManualSnapshotUs'] = $manual_snapshot_us;
					if ( $this->is_repair_mode() ) {
						$product_data['_moboRepairSnapshotUs'] = $manual_snapshot_us;
					}
					$this->clear_transient_request_error( $state );
				}
			}

			if ( '' !== $product_guid && ! $this->is_repair_mode() && $this->is_remote_product_trashed( $product_guid ) ) {
				$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
				$state['lastError']         = '';
				$state['lastMessage']       = 'محصول به دلیل قرار داشتن در سطل زباله وردپرس رد شد: ' . $product_guid;

				if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
					$state['status']      = 'done';
					$state['completedAt'] = time();
					$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
				}

				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			$product_lock = false;

			if ( '' !== $product_guid && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
				$product_lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 5, 180 );

				if ( false === $product_lock ) {
					if ( ! empty( $state['repairMode'] ) ) {
						$lock_try = $this->repair_product_lock_miss( $state, 'parent-upsert', $product_guid );
						if ( $lock_try >= 5 ) {
							return $this->quarantine_repair_product_for_liveness( $state, 'parent-upsert', $product_guid, $this->find_product_id_by_guid( $product_guid ), false );
						}
						array_unshift( $state['productQueue'], $product_data );
						$state['lastError'] = '';
						$state['lastMessage'] = sprintf( 'Repair منتظر lock محصول است (%d/5): %s.', $lock_try, $product_guid );
						$state['updatedAt'] = time();
						if ( ! $this->save_manual_sync_state( $state ) ) {
							return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
						}
						return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
					}

					array_unshift( $state['productQueue'], $product_data );
					$state['lastError']   = '';
					$state['lastMessage'] = 'این محصول در مسیر دیگری در حال پردازش است؛ sync در مرحله بعدی دوباره تلاش می‌کند: ' . $product_guid;
					$state['updatedAt']   = time();
					if ( ! $this->save_manual_sync_state( $state ) ) {
						return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
					}

					return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
				}
				$this->clear_repair_product_lock_miss( $state, 'parent-upsert', $product_guid );
			}

			try {
				if ( '' !== $product_guid && $manual_snapshot_us > 0 && $this->get_last_webhook_applied_us( $product_guid ) > $manual_snapshot_us ) {
					array_unshift( $state['productQueue'], $product_data );
					$state['lastError']   = '';
					$state['lastMessage'] = 'Webhook جدیدتری هنگام آماده‌سازی Manual Sync رسید؛ این محصول در مرحله بعد با Snapshot تازه پردازش می‌شود: ' . $product_guid;
					$state['updatedAt']   = time();
					if ( ! $this->save_manual_sync_state( $state ) ) {
						return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
					}

					return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
				}

				$existing_product_id = '' !== $product_guid ? $this->find_product_id_by_guid( $product_guid ) : 0;
				$was_existing        = $existing_product_id > 0;
				$desired_attributes  = $this->get_value( $product_data, 'attributes', array() );
				if ( ! is_array( $desired_attributes ) ) {
					$desired_attributes = array();
				}

				$attribute_structure_changed = false;
				if ( $existing_product_id > 0 && $this->product_attributes_field_present( $product_data ) ) {
					$existing_product = wc_get_product( $existing_product_id );
					$attribute_structure_changed = $existing_product instanceof WC_Product
						&& $this->product_attribute_structure_changed( $existing_product, $desired_attributes );
				}

				/*
				 * Do not delete existing variations before the replacement snapshot has
				 * been fetched. The terminal authoritative variant page removes stale
				 * children only after the replacement set is proven complete.
				 */
				$product_id = $this->upsert_parent_product( $product_data, true );
				$product_source_version  = $this->extract_source_version( $product_data, $product_data );
				$product_source_revision = $this->extract_source_revision( $product_data, $product_data );
				$product_source_hash     = '' !== $product_guid ? $this->build_product_source_hash( $product_data, $product_guid ) : '';
				$product_portal_id       = $this->extract_portal_product_id( $product_data );
				if ( $product_id > 0 && ( '' !== $product_source_version || $product_source_revision > 0 ) && ! $this->persist_ordering_watermarks( $product_id, 'product', $product_source_version, $product_source_revision, true ) ) {
					$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
					$state['lastError']   = 'Product ordering watermark could not be persisted.';
					$state['lastMessage'] = 'محصول اعمال شد اما checkpoint ترتیب آن ذخیره نشد؛ همان محصول در اجرای بعد idempotent تکرار می‌شود.';
					$this->save_manual_sync_state( $state );
					return $this->result( false, $state['lastMessage'], $this->get_manual_sync_status() );
				}
				if ( $attribute_structure_changed && $product_id > 0 ) {
					update_post_meta( $product_id, '_mobo_desired_state_rebuild_pending', '1' );
					update_post_meta( $product_id, '_mobo_desired_state_rebuild_requested_at', gmdate( 'c' ) );
					if ( class_exists( 'Mobo_Core_Sync_Health' ) ) {
						Mobo_Core_Sync_Health::mark_behind( $product_guid, $product_id );
					}
				}
			} catch ( Mobo_Core_Parent_Identity_Conflict $conflict ) {
				/* Only definitive conflicts can be journalled. The product lock is
				 * released by finally even when Repair checkpoints this conflict. */
				if ( ! empty( $state['repairMode'] ) ) {
					return $this->quarantine_repair_parent_conflict( $state, $conflict, $product_guid, 0 );
				}
				throw $conflict;
			} catch ( Throwable $parent_failure ) {
				/* The product was shifted from the queue only in memory. Re-throwing
				 * used to discard that checkpoint, so the next tick re-read the same
				 * product, failed the same way (e.g. a utf8mb3 write rejection) and the
				 * whole Sync/Repair was stuck forever. Retry it a bounded number of
				 * times, then mark only this product failed/retryable and continue. */
				return $this->isolate_failed_parent_product( $state, $product_data, $product_guid, $parent_failure );
			} finally {
				if ( false !== $product_lock && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
					Mobo_Core_Product_Concurrency::release_product_lock( $product_lock );
				}
			}

			if ( $product_id <= 0 ) {
				$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
				$state['lastError']         = '';
				$state['lastMessage']       = 'محصول نامعتبر رد شد.';

				if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
					$state['status']      = 'done';
					$state['completedAt'] = time();
					$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
				}

				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			$state['currentProductGuid']            = $product_guid;
			$state['currentProductId']              = absint( $product_id );
			$state['currentProductPortalId']        = isset( $product_portal_id ) ? absint( $product_portal_id ) : 0;
			$state['currentProductSourceRevision']  = isset( $product_source_revision ) ? absint( $product_source_revision ) : 0;
			$state['currentProductSourceVersion']   = isset( $product_source_version ) ? sanitize_text_field( (string) $product_source_version ) : '';
			$state['currentProductSourceHash']      = isset( $product_source_hash ) ? sanitize_text_field( (string) $product_source_hash ) : '';
			$state['currentProductSnapshotUs']     = absint( $manual_snapshot_us );
			$state['currentProductImages']          = $this->get_product_images_from_payload( $product_data );
			$state['currentProductImagesPresent']   = $this->product_images_field_present( $product_data );
			$state['currentProductAttributes']      = is_array( $desired_attributes ) ? $desired_attributes : array();
			$state['currentProductImageOffset']     = 0;
			$state['currentProductWasExisting']     = $was_existing;
			$state['currentProductImagesDone']      = false;
			$state['currentProductCanHaveVariants'] = $this->product_payload_can_have_variants( $product_data, $product_id );

			$state['variantPage']                   = 1;
			$state['currentVariantTotalCount']      = 0;
			$state['currentVariantTotalPages']      = 0;
			$state['currentVariantProcessedPages']  = 0;
			$state['currentVariantCursor']          = 0;
			$state['currentVariantCursorDisabled']  = false;
			$state['lastError']                     = '';
			$state['lastMessage']                   = $this->last_parent_core_noop
				? 'محصول اصلی بدون تغییر بود و ذخیره مجدد نشد: ' . $product_guid
				: 'محصول اصلی همگام شد: ' . $product_guid;

			if ( ! $this->reset_seen_variants( $product_guid, $state['syncId'] ) ) {
				$state['lastError']   = 'Could not durably initialize the variation seen-set.';
				$state['lastMessage'] = 'Checkpoint تنوع‌های محصول ذخیره نشد؛ برای جلوگیری از حذف اشتباه هیچ Variantی پردازش نشد.';
				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}
				return $this->result( false, $state['lastMessage'], $this->get_manual_sync_status() );
			}
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint محصول پس از آماده‌سازی Variantها به‌صورت پایدار ذخیره نشد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/* UX55: Do not continue images/variant topology from a parent snapshot that
		 * lost to an applied foreground webhook after Step 3's parent lock. */
		$rebase = $this->rebase_current_product_if_webhook_advanced( $state, $api );
		if ( null !== $rebase ) {
			return $rebase;
		}

		/*
		 * Step 4: Process product images in chunks before variants/simple finish.
		 */
		if ( empty( $state['currentProductImagesDone'] ) ) {
			$product_id   = absint( $state['currentProductId'] );
			$images       = is_array( $state['currentProductImages'] ) ? $state['currentProductImages'] : array();
			$image_offset = absint( $state['currentProductImageOffset'] );
			$was_existing = ! empty( $state['currentProductWasExisting'] );

			$images_present = ! empty( $state['currentProductImagesPresent'] );
			$should_process_images = $product_id > 0
				&& ( ! $was_existing || $images_present )
				&& ( ! $was_existing || $this->rules->should_update_images() )
				&& $this->product_images_need_processing( $product_id, $images, ! $was_existing );

			if ( $should_process_images ) {
				$image_result = $this->image_sync->process_images(
					$product_id,
					$images,
					$image_offset,
					false
				);

				if ( ! empty( $image_result['error'] ) ) {
					return $this->handle_transient_request_error( $state, (string) $image_result['error'], 'ثبت Desired State تصاویر در صف دیتابیس ناموفق بود.' );
				}

				if ( empty( $image_result['done'] ) ) {
					$state['currentProductImageOffset'] = absint( $image_result['nextOffset'] );
					$state['lastError']                 = '';
					$state['lastMessage']               = sprintf( 'تصاویر محصول در حال پردازش است. پردازش‌شده: %d، باقی‌مانده صف: %d', isset( $image_result['processed'] ) ? absint( $image_result['processed'] ) : 0, isset( $image_result['pending'] ) ? absint( $image_result['pending'] ) : 0 );

					if ( ! $this->save_manual_sync_state( $state ) ) {
						return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
					}

					return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
				}

				if ( ! empty( $image_result['pending'] ) ) {
					if ( ! $this->mark_product_images_pending( $product_id, $images ) ) {
						return $this->handle_transient_request_error( $state, 'Image pending desired-state hash could not be committed.', 'ثبت watermark در انتظار تصاویر ناموفق بود.' );
					}
				} elseif ( ! $this->mark_product_images_converged( $product_id, $images ) ) {
					return $this->handle_transient_request_error( $state, 'Image desired-state hash could not be committed.', 'ثبت watermark تصاویر ناموفق بود.' );
				}
			}

			$state['currentProductImagesDone']   = true;
			$state['currentProductImageOffset']  = 0;
			$state['lastError']                  = '';
			$state['lastMessage']                = 'تصاویر محصول به صف امن منتقل شد و همگام‌سازی محصول ادامه پیدا می‌کند.';

			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Step 5: Product without variation attributes is simple.
		 *
		 * A Mobo storefront "simple" product still has one purchasable Variant row.
		 * WooCommerce does not create a WC_Product_Variation for that row, therefore
		 * the storefront portal_variant_id and remote variant GUID must be persisted
		 * on the WC_Product_Simple itself. Older builds stopped here without calling
		 * get-variants, leaving the product visible in WooCommerce but impossible to
		 * add to the Mobo cart during checkout/order submission.
		 */
		if ( empty( $state['currentProductCanHaveVariants'] ) ) {
			$product_guid = sanitize_text_field( (string) $state['currentProductGuid'] );
			$product_id   = absint( $state['currentProductId'] );

			if ( $product_id <= 0 && '' !== $product_guid ) {
				$product_id = $this->find_product_id_by_guid( $product_guid );
			}

			if ( $product_id > 0 ) {
				/*
				 * Fetch/validate Portal state outside the shared product lock. Only the
				 * local topology/identity commit is serialized. This keeps Portal latency
				 * out of the critical section while closing the Step-3 -> Step-5 TOCTOU.
				 */
				$simple_snapshot = $this->fetch_simple_product_variant_snapshot_from_api(
					$api,
					$product_guid,
					$product_id,
					$state['syncId']
				);

				if ( is_wp_error( $simple_snapshot ) ) {
					return $this->handle_transient_request_error( $state, $simple_snapshot, 'خطا در دریافت شناسه قابل خرید محصول ساده.' );
				}

				$simple_variant = $this->commit_manual_simple_product_variant_snapshot(
					$product_id,
					$product_guid,
					$simple_snapshot,
					isset( $state['currentProductAttributes'] ) && is_array( $state['currentProductAttributes'] )
						? $state['currentProductAttributes']
						: array(),
					absint( $state['currentProductSnapshotUs'] ?? 0 )
				);

				if ( is_wp_error( $simple_variant ) ) {
					if ( 'mobo_core_manual_snapshot_superseded' === $simple_variant->get_error_code() ) {
						return $this->rebase_current_product_if_webhook_advanced( $state, $api, true );
					}
					if ( ! empty( $state['repairMode'] ) && 'mobo_core_manual_simple_product_lock_busy' === $simple_variant->get_error_code() ) {
						$lock_try = $this->repair_product_lock_miss( $state, 'simple-commit', $product_guid );
						if ( $lock_try >= 5 ) {
							return $this->quarantine_repair_product_for_liveness( $state, 'simple-commit', $product_guid, $product_id, true );
						}
						$state['lastError'] = '';
						$state['lastMessage'] = sprintf( 'Repair منتظر lock محصول ساده است (%d/5): %s.', $lock_try, $product_guid );
						if ( ! $this->save_manual_sync_state( $state ) ) {
							return $this->result( false, 'Checkpoint انتظار lock محصول ساده ذخیره نشد.', $this->get_manual_sync_status() );
						}
						return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
					}
					return $this->handle_transient_request_error( $state, $simple_variant, 'محصول ساده برای commit امن منتظر lock/revalidation والد است.' );
				}
				$this->clear_repair_product_lock_miss( $state, 'simple-commit', $product_guid );

				$message = ! empty( $simple_variant['success'] )
					? 'محصول ساده و شناسه قابل خرید آن با موفقیت همگام شد.'
					: ( isset( $simple_variant['message'] ) ? $simple_variant['message'] : 'شناسه قابل خرید محصول ساده پیدا نشد؛ محصول برای خرید مسدود شد.' );

				wc_delete_product_transients( $product_id );
			} else {
				$message = 'محصول ساده محلی پیدا نشد و پردازش آن رد شد.';
			}

			if ( ! $this->finish_current_product_state( $state, $message ) ) {
				return $this->handle_transient_request_error( $state, new WP_Error( 'mobo_core_product_map_finalize_failed', 'Product Map final checkpoint was not durably stored.' ), 'ثبت نهایی Product Map محصول ناموفق بود.' );
			}
			$this->clear_transient_request_error( $state );

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Step 6: Sync one variants page for current product.
		 */
		$product_guid = sanitize_text_field( (string) $state['currentProductGuid'] );

		$use_variant_cursor = Mobo_Core_Settings::enabled( 'mobo_core_variant_cursor_sync_enabled', '1' ) && empty( $state['currentVariantCursorDisabled'] );
		$variant_cursor     = max( 0, absint( $state['currentVariantCursor'] ?? 0 ) );

		$response = $api->get_variants_page(
			$product_guid,
			absint( $state['variantPage'] ),
			$variants_limit,
			$state['syncId'],
			$variant_cursor,
			$use_variant_cursor
		);

		if ( is_wp_error( $response ) ) {
			return $this->handle_transient_request_error( $state, $response, 'خطا در دریافت تنوع‌های محصول.' );
		}

		$variant_items = $this->validated_variant_page_items( $response );
		if ( is_wp_error( $variant_items ) ) {
			return $this->handle_transient_request_error( $state, $variant_items, 'پاسخ تنوع‌های MoboCore نامعتبر بود.' );
		}
		$variant_has_more_result = $this->validated_page_has_more( $response, absint( $state['variantPage'] ) );
		if ( is_wp_error( $variant_has_more_result ) ) {
			return $this->handle_transient_request_error( $state, $variant_has_more_result, 'صفحه‌بندی تنوع‌های MoboCore نامعتبر بود.' );
		}
		$variant_has_more = (bool) $variant_has_more_result;

		$variant_items_count = count( $variant_items );
		$variant_total_count = absint( $this->get_value( $response, 'totalCount', 0 ) );

		/*
		 * Some MoboCore/Swagger payloads may return totalCount=0 while data still contains variants.
		 * Treat the actual data as authoritative for this page; never mark existing variations
		 * as simple/out-of-stock just because totalCount is zero.
		 */
		if ( 0 === $variant_total_count && $variant_items_count > 0 ) {
			$variant_total_count = $variant_items_count;
		}

		$product_id = absint( $state['currentProductId'] );

		if ( $product_id <= 0 ) {
			$product_id = $this->find_product_id_by_guid( $product_guid );
		}

		/*
		 * Do not mutate product topology from variant_total_count alone. A zero count
		 * is destructive evidence only when process_update_variant_payload() also sees
		 * an explicit valid attributes=[] collection on the authoritative page. This
		 * keeps manual/API sync on the same presence policy as webhook processing.
		 */

		$state['currentVariantTotalCount'] = $variant_total_count;
		$state['currentVariantTotalPages'] = absint( $this->get_value( $response, 'totalPages', 0 ) );

		$variant_cursor_mode = sanitize_key( (string) $this->get_value( $response, 'cursorMode', '' ) );
		$variant_next_cursor = $this->get_value( $response, 'nextCursor', null );
		if ( '' !== $variant_cursor_mode && $variant_has_more && ( null === $variant_next_cursor || '' === $variant_next_cursor || absint( $variant_next_cursor ) <= $variant_cursor ) ) {
			$state['currentVariantCursorDisabled'] = true;
			$state['currentVariantCursor'] = 0;
			$state['lastMessage'] = 'Cursor تنوع‌ها جلو نرفت؛ ادامه این محصول به page fallback منتقل شد.';
			$variant_next_cursor = null;
		}
		/* Do not checkpoint nextCursor until this fetched page is durably applied.
		 * Otherwise a failed page can be skipped after retry/upgrade. */

		$variant_source_version  = $this->extract_source_version( $response, $response );
		$variant_source_revision = $this->extract_source_revision( $response, $response );
		$payload = array(
			'event'                    => 'UpdateVariant',
			'variantListAuthoritative' => true,
			'syncId'                   => $state['syncId'],
			'eventVersion'              => $variant_source_version,
			'sourceRevision'            => $variant_source_revision,
			'productId'     => $product_guid,
			'totalCount'    => $variant_total_count,
			'pageNumber'    => $this->get_value( $response, 'pageNumber', $state['variantPage'] ),
			'recordPerPage' => $this->get_value( $response, 'recordPerPage', $variants_limit ),
			'hasMore'       => $variant_has_more,
			'isLastPage'    => ! $variant_has_more,
			'attributes'    => is_array( $state['currentProductAttributes'] ) ? $state['currentProductAttributes'] : array(),
			'attributeOrderSignificant' => false,
			'_moboManualParentSnapshotUs' => absint( $state['currentProductSnapshotUs'] ?? 0 ),
			'data'          => $variant_items,
		);

		try {
			$result = $this->process_update_variant_payload( $payload, true );
		} catch ( Mobo_Core_Variation_Parent_Conflict $conflict ) {
			/* Existing typed variation-parent conflict remains fail-closed. */
			if ( ! empty( $state['repairMode'] ) ) {
				return $this->quarantine_repair_parent_conflict( $state, $conflict, $product_guid, $product_id );
			}
			throw $conflict;
		} catch ( Mobo_Core_Parent_Identity_Conflict $conflict ) {
			/* The parent fence also protects variant-page entrypoints. */
			if ( ! empty( $state['repairMode'] ) ) {
				return $this->quarantine_repair_parent_conflict( $state, $conflict, $product_guid, $product_id );
			}
			throw $conflict;
		}

		$variant_wait = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		if ( ! empty( $variant_wait['manualSnapshotStale'] ) ) {
			return $this->rebase_current_product_if_webhook_advanced( $state, $api, true );
		}
		if ( ! empty( $variant_wait['waitingForProduct'] ) ) {
			$waiting_reason = sanitize_key( (string) ( $variant_wait['waitingReason'] ?? '' ) );
			if ( ! empty( $state['repairMode'] ) && 'product_lock_busy' === $waiting_reason ) {
				$lock_try = $this->repair_product_lock_miss( $state, 'variant-page', $product_guid );
				if ( $lock_try >= 5 ) {
					return $this->quarantine_repair_product_for_liveness( $state, 'variant-page', $product_guid, $product_id, true );
				}
				$state['lastError'] = '';
				$state['lastMessage'] = sprintf( 'Repair منتظر lock صفحه Variant است (%d/5): %s.', $lock_try, $product_guid );
				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint انتظار lock Variant ذخیره نشد.', $this->get_manual_sync_status() );
				}
				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
			}
			return $this->handle_transient_request_error(
				$state,
				new WP_Error( 'mobo_core_manual_variant_waiting_for_product', isset( $result['message'] ) ? (string) $result['message'] : 'Variant page is waiting for the shared product lock.' ),
				'صفحه Variant برای lock محصول منتظر می‌ماند.'
			);
		}
		$this->clear_repair_product_lock_miss( $state, 'variant-page', $product_guid );

		if ( empty( $result['success'] ) ) {
			$state['lastError']   = isset( $result['message'] ) ? $result['message'] : 'خطا در پردازش تنوع محصول.';
			$state['lastMessage'] = 'خطا در پردازش تنوع محصول.';
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( false, $state['lastError'], $this->get_manual_sync_status() );
		}

		if ( '' !== $variant_cursor_mode && null !== $variant_next_cursor && '' !== $variant_next_cursor ) {
			$state['currentVariantCursor'] = absint( $variant_next_cursor );
		}

		$state['currentVariantProcessedPages'] = absint( $state['currentVariantProcessedPages'] ) + 1;

		$variant_items     = $this->get_value( $payload, 'data', array() );
		$variant_has_more  = $this->to_bool( $payload['hasMore'] );
		$payload_last_page = $this->get_value( $payload, 'isLastPage', null );
		$total_pages       = absint( $state['currentVariantTotalPages'] );

		if ( null !== $payload_last_page && $this->to_bool( $payload_last_page ) ) {
			$variant_has_more = false;
		}

		if ( $total_pages > 0 && absint( $state['currentVariantProcessedPages'] ) >= $total_pages ) {
			$variant_has_more = false;
		}

		if ( is_array( $variant_items ) && empty( $variant_items ) ) {
			$variant_has_more = false;
		}

		if ( $variant_has_more ) {
			$this->clear_transient_request_error( $state );
			$state['variantPage'] = absint( $state['variantPage'] ) + 1;
			$state['lastError']   = '';
			$state['lastMessage'] = $result['message'];

			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		if ( ! $this->finish_current_product_state( $state, $result['message'] ) ) {
			return $this->handle_transient_request_error( $state, new WP_Error( 'mobo_core_product_map_finalize_failed', 'Product Map final checkpoint was not durably stored.' ), 'ثبت نهایی Product Map محصول ناموفق بود.' );
		}
		$this->clear_transient_request_error( $state );

		return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
	}



	/**
	 * Extract the product GUID currently being processed from a ProductUpdated payload.
	 *
	 * @param array $payload Payload by reference.
	 * @return string
	 */
	private function extract_current_product_guid_from_product_updated_payload( &$payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}

		$items = $this->get_value( $payload, 'data', array() );

		if ( ! is_array( $items ) ) {
			return '';
		}

		$product_index = max( 0, absint( $this->get_value( $payload, '_moboProductIndex', 0 ) ) );

		if ( ! isset( $items[ $product_index ] ) || ! is_array( $items[ $product_index ] ) ) {
			return '';
		}

		return $this->extract_product_guid( $items[ $product_index ] );
	}

	/**
	 * Extract parent product GUID from all known UpdateVariant payload shapes.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	private function extract_product_guid_from_update_variant_payload( $payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}

		$inner_data = $this->get_value( $payload, 'data', null );
		if ( is_array( $inner_data ) && ! $this->is_list_array( $inner_data ) ) {
			$inner_product_guid = $this->extract_product_guid( $inner_data );
			$inner_variants     = $this->get_value( $inner_data, 'data', null );

			if ( '' !== $inner_product_guid || is_array( $inner_variants ) ) {
				$payload = array_merge( $payload, $inner_data );
			}
		}

		$product_guid = $this->extract_product_guid( $payload );
		$variants     = $this->get_value( $payload, 'data', array() );

		if ( is_array( $variants ) && ! $this->is_list_array( $variants ) ) {
			$nested_variants = $this->get_value( $variants, 'data', null );
			if ( is_array( $nested_variants ) ) {
				$variants = $nested_variants;
			}
		}

		if ( '' === $product_guid && is_array( $variants ) && isset( $variants[0] ) && is_array( $variants[0] ) ) {
			$product_guid = $this->extract_product_guid( $variants[0] );
		}

		if ( '' === $product_guid ) {
			$variant_guid = $this->extract_variant_guid( $payload );
			if ( '' !== $variant_guid ) {
				$product_guid = $this->find_parent_product_guid_by_variant_guid( $variant_guid );
			}
		}

		return sanitize_text_field( (string) $product_guid );
	}

	/**
	 * Rebase an in-flight Manual/Repair product when a foreground webhook wins.
	 * Never finalize an old product state or reuse its old variation seen-set.
	 * An exact product GET starts from a conservative pre-request time fence.
	 * Returns null if the accepted parent snapshot is still safe to continue.
	 */
	private function rebase_current_product_if_webhook_advanced( &$state, $api, $force = false ) {
		$product_guid = sanitize_text_field( (string) ( $state['currentProductGuid'] ?? '' ) );
		if ( '' === $product_guid ) {
			return null;
		}
		$parent_snapshot_us = absint( $state['currentProductSnapshotUs'] ?? 0 );
		$latest_webhook_us = $this->get_last_webhook_applied_us( $product_guid );
		if ( ! $force && ( 0 === $latest_webhook_us || $latest_webhook_us <= $parent_snapshot_us ) ) {
			return null;
		}

		/* Do not advance the catalogue cursor or mark a stale parent complete if
		 * Portal is temporarily unavailable. Preserve the current checkpoint. */
		$fresh_snapshot_us = $this->current_wall_clock_us();
		$fresh_response = $api->get_product_by_guid( $product_guid, $state['syncId'] );
		if ( is_wp_error( $fresh_response ) ) {
			return $this->handle_transient_request_error( $state, $fresh_response, 'Snapshot والد پس از Webhook تازه باید دوباره دریافت شود.' );
		}
		$fresh_items = $this->extract_explicit_list_data( $fresh_response, 'manual current product rebase' );
		if ( is_wp_error( $fresh_items ) ) {
			return $this->handle_transient_request_error( $state, $fresh_items, 'Snapshot تازه والد معتبر نبود؛ مراحل قبلی ادامه نیافت.' );
		}
		$fresh_product = null;
		foreach ( $fresh_items as $candidate ) {
			if ( is_array( $candidate ) && $product_guid === $this->extract_product_guid( $candidate ) ) {
				$fresh_product = $candidate;
				break;
			}
		}
		if ( ! is_array( $fresh_product ) ) {
			return $this->handle_transient_request_error( $state, new WP_Error( 'mobo_core_manual_rebase_product_missing', 'The exact replacement parent is not available.' ), 'تا دریافت Snapshot معتبر والد، مراحل تصاویر و Variant اجرا نمی‌شوند.' );
		}
		$fresh_product['_moboManualSnapshotUs'] = $fresh_snapshot_us;
		if ( ! empty( $state['repairMode'] ) ) {
			$fresh_product['_moboRepairSnapshotUs'] = $fresh_snapshot_us;
		}

		/* Product is still incomplete until the refreshed Parent+Variant stages
		 * reach their normal durable completion boundary. */
		$product_id = absint( $state['currentProductId'] ?? 0 );
		if ( $product_id > 0 && ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' )
			|| ! $this->upsert_product_map( $product_guid, $product_id, true ) ) ) {
			return $this->result( false, 'Could not checkpoint the superseded parent before rebase.', $this->get_manual_sync_status() );
		}
		array_unshift( $state['productQueue'], $fresh_product );
		$state['currentProductGuid'] = '';
		$state['currentProductId'] = 0;
		$state['currentProductPortalId'] = 0;
		$state['currentProductSourceRevision'] = 0;
		$state['currentProductSourceVersion'] = '';
		$state['currentProductSourceHash'] = '';
		$state['currentProductSnapshotUs'] = 0;
		$state['currentProductImages'] = array();
		$state['currentProductImagesPresent'] = false;
		$state['currentProductAttributes'] = array();
		$state['currentProductImageOffset'] = 0;
		$state['currentProductWasExisting'] = false;
		$state['currentProductImagesDone'] = false;
		$state['currentProductCanHaveVariants'] = false;
		$state['variantPage'] = 1;
		$state['currentVariantTotalCount'] = 0;
		$state['currentVariantTotalPages'] = 0;
		$state['currentVariantProcessedPages'] = 0;
		$state['currentVariantCursor'] = 0;
		$state['currentVariantCursorDisabled'] = false;
		$state['lastError'] = '';
		$state['lastMessage'] = 'Webhook newer than the parent snapshot: restarted this product from an exact fresh snapshot.';
		if ( ! $this->save_manual_sync_state( $state ) ) {
			return $this->result( false, 'Could not persist the superseded-parent rebase checkpoint.', $this->get_manual_sync_status() );
		}
		/* A rebase is a correctness boundary; force any request-local checkpoint
		 * coalescer to flush now so the old current product cannot replay on crash. */
		if ( $this->checkpoint_coalescing && ! $this->flush_manual_sync_checkpoint() ) {
			return $this->result( false, 'Could not flush the superseded-parent rebase checkpoint.', $this->get_manual_sync_status() );
		}
		return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
	}

	/**
	 * Current wall-clock timestamp in microseconds for local ordering guards.
	 *
	 * @return int
	 */
	private function current_wall_clock_us() {
		return (int) floor( microtime( true ) * 1000000 );
	}

	/**
	 * Record that a foreground webhook successfully applied product state.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return bool True only when the ordering fence is durably persisted.
	 */
	private function mark_webhook_product_applied( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}

		$watermark = $this->current_wall_clock_us();
		return $this->persist_post_meta_verified( $product_id, '_mobo_last_webhook_applied_us', $watermark );
	}

	/**
	 * Read the most recent foreground webhook watermark for a Mobo product.
	 *
	 * @param string $product_guid Remote product GUID.
	 * @return int
	 */
	private function get_last_webhook_applied_us( $product_guid ) {
		$product_id = $this->find_product_id_by_guid( $product_guid );
		if ( $product_id <= 0 ) {
			return 0;
		}

		return absint( get_post_meta( $product_id, '_mobo_last_webhook_applied_us', true ) );
	}

	/**
	 * Whether the current manual sync run is a repair pass.
	 *
	 * Within payload application, Repair bypasses the source-hash short-circuit and
	 * may restore an exact trashed identity that is present in the authoritative
	 * Repair payload. Field update decisions still follow normal Mobo settings.
	 * Manual Repair also runs the bounded legacy-integrity stage before payloads.
	 *
	 * @return bool
	 */
	private function is_repair_mode() {
		return (bool) $this->repair_mode;
	}

	/**
	 * product-delta-v1 resolver: local state of the delta's product.
	 *
	 * @param string $guid Remote product GUID.
	 * @return array{id:int,state:string,type?:string,excluded?:bool}
	 */
	public function resolve_delta_product( $guid ) {
		$guid       = sanitize_text_field( (string) $guid );
		$product_id = '' !== $guid ? absint( $this->find_product_id_by_guid( $guid ) ) : 0;
		if ( $product_id <= 0 ) {
			return array( 'id' => 0, 'state' => 'missing' );
		}
		$state = $this->delta_object_state( $product_id, 'product' );
		if ( 'ok' !== $state ) {
			return array( 'id' => $product_id, 'state' => $state );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return array( 'id' => $product_id, 'state' => 'inconsistent' );
		}
		$type = $product->is_type( 'variable' ) ? 'variable' : ( $product->is_type( 'simple' ) ? 'simple' : 'other' );
		if ( 'other' === $type ) {
			return array( 'id' => $product_id, 'state' => 'inconsistent' );
		}
		return array(
			'id'       => $product_id,
			'state'    => 'ok',
			'type'     => $type,
			'excluded' => class_exists( 'Mobo_Core_Product_Exclusions' ) && method_exists( 'Mobo_Core_Product_Exclusions', 'is_local_product_excluded' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $product_id ),
		);
	}

	/**
	 * product-delta-v1 resolver: local target of one changed variant.
	 *
	 * @param string $guid Remote variant GUID.
	 * @param array  $product resolve_delta_product() result.
	 * @return array{id:int,state:string,context?:string}
	 */
	public function resolve_delta_variant( $guid, $product ) {
		$guid       = sanitize_text_field( (string) $guid );
		$product_id = absint( is_array( $product ) && isset( $product['id'] ) ? $product['id'] : 0 );
		$type       = is_array( $product ) && isset( $product['type'] ) ? (string) $product['type'] : '';
		if ( '' === $guid || $product_id <= 0 ) {
			return array( 'id' => 0, 'state' => 'missing' );
		}
		if ( 'simple' === $type ) {
			/* A simple Mobo product embeds exactly one Source variant. */
			$stored = sanitize_text_field( (string) get_post_meta( $product_id, 'variant_guid', true ) );
			if ( '' !== $stored && 0 === strcasecmp( $stored, $guid ) ) {
				return array( 'id' => $product_id, 'state' => 'ok', 'context' => 'product' );
			}
			return array( 'id' => 0, 'state' => 'missing' );
		}
		if ( 'variable' !== $type ) {
			return array( 'id' => 0, 'state' => 'inconsistent' );
		}
		$variation_id = absint( $this->find_variation_id_by_guid( $guid ) );
		if ( $variation_id <= 0 ) {
			return array( 'id' => 0, 'state' => 'missing' );
		}
		if ( absint( wp_get_post_parent_id( $variation_id ) ) !== $product_id ) {
			return array( 'id' => $variation_id, 'state' => 'inconsistent' );
		}
		return array( 'id' => $variation_id, 'state' => $this->delta_object_state( $variation_id, 'product_variation' ), 'context' => 'variation' );
	}

	/** @return string ok|quarantined|inconsistent */
	private function delta_object_state( $object_id, $post_type ) {
		$object_id = absint( $object_id );
		if ( $post_type !== get_post_type( $object_id ) ) {
			return 'inconsistent';
		}
		$status = sanitize_key( (string) get_post_status( $object_id ) );
		if ( in_array( $status, array( '', 'trash', 'auto-draft' ), true ) ) {
			return 'inconsistent';
		}
		if ( class_exists( 'Mobo_Core_Migration_Quarantine' ) && Mobo_Core_Migration_Quarantine::is_marked( $object_id ) ) {
			return 'quarantined';
		}
		/* M5: a previous delta was killed halfway through its writes. */
		if ( 'product' === $post_type && class_exists( 'Mobo_Core_Delta_Inflight' ) && Mobo_Core_Delta_Inflight::is_marked( $object_id ) ) {
			return 'inconsistent';
		}
		if ( '1' === (string) get_post_meta( $object_id, 'mobo_sync_incomplete', true )
			|| '1' === (string) get_post_meta( $object_id, '_mobo_desired_state_rebuild_pending', true ) ) {
			return 'inconsistent';
		}
		return 'ok';
	}

	/**
	 * Apply one product-delta-v1 payload (inline from the webhook route or from the
	 * durable queue). Same product lock, ordering watermarks, price policy, stock
	 * normalization and Source visibility helpers as full payloads.
	 *
	 * Result data keys: deleteFile, needFullSnapshot, staleSkipped, deltaApplied.
	 *
	 * @param array $payload Delta payload.
	 * @return array
	 */
	public function apply_product_delta( $payload ) {
		$item = class_exists( 'Mobo_Core_Product_Delta' ) ? Mobo_Core_Product_Delta::product_item( $payload ) : null;
		$guid = is_array( $item ) ? Mobo_Core_Product_Delta::product_guid( $item ) : '';
		if ( '' === $guid ) {
			return $this->result( false, 'Delta payload has no product identifier.', array( 'deleteFile' => true, 'nonRetryable' => true, 'error' => 'delta_product_guid_missing' ) );
		}
		/* Ordering: a delta never overtakes an older open event of the same product or
		 * variants (that event's revision would otherwise look stale and be skipped). */
		$queue_uuid = isset( $payload['_moboQueueEventUuid'] ) ? sanitize_text_field( (string) $payload['_moboQueueEventUuid'] ) : '';
		if ( ( '' !== $queue_uuid || ! empty( $payload['_moboDeltaInline'] ) ) && class_exists( 'Mobo_Core_Sync_Event_Store' ) ) {
			$variant_guids = array();
			$delta_variants = Mobo_Core_Product_Delta::variants( $item, $payload );
			foreach ( is_array( $delta_variants ) ? $delta_variants : array() as $delta_variant ) {
				$variant_guids[] = Mobo_Core_Product_Delta::variant_guid( $delta_variant );
			}
			$event_store = new Mobo_Core_Sync_Event_Store();
			if ( $event_store->has_open_events_before( $guid, $variant_guids, $queue_uuid ) ) {
				return class_exists( 'Mobo_Core_Product_Concurrency' )
					? Mobo_Core_Product_Concurrency::defer_result( $guid, 'older_events_pending', 20 )
					: $this->result( true, 'Delta deferred behind older events.', array( 'deleteFile' => false, 'waitingForProduct' => true, 'deferSeconds' => 20 ) );
			}
		}

		$this->sweep_interrupted_deltas();

		$lock = false;
		if ( class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			if ( Mobo_Core_Product_Concurrency::is_manual_sync_busy_for_product( $guid ) ) {
				return Mobo_Core_Product_Concurrency::defer_result( $guid, 'manual_sync_active', 30 );
			}
			$lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $guid, 0, 120 );
			if ( false === $lock || is_wp_error( $lock ) ) {
				return Mobo_Core_Product_Concurrency::defer_result( $guid, 'product_lock_busy', 20 );
			}
		}
		try {
			$run = function () use ( $payload ) {
				return $this->apply_product_delta_locked( $payload );
			};
			return class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) ? Mobo_Core_Cache_Mutation_Guard::run( $run, 'webhook-product' ) : $run();
		} finally {
			if ( false !== $lock && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
				Mobo_Core_Product_Concurrency::release_product_lock( $lock );
			}
		}
	}

	/**
	 * Canonical field names understood by the existing price/stock/visibility
	 * readers, built only from the logical delta fields that are present.
	 *
	 * @param array $logical Logical delta fields.
	 * @return array
	 */
	private function delta_canonical_fields( $logical ) {
		$map = array(
			'price'          => 'price',
			'compareAtPrice' => 'comparePrice',
			'stock'          => 'stock',
			'publishedAt'    => 'publishedAt',
			'sourceStatus'   => 'sourceStatus',
			'visibility'     => 'visibility',
			'purchasable'    => 'purchasable',
		);
		$out = array();
		foreach ( $map as $field => $key ) {
			if ( is_array( $logical ) && array_key_exists( $field, $logical ) ) {
				$out[ $key ] = $logical[ $field ];
			}
		}
		return $out;
	}

	/**
	 * Stage price/stock changes on an in-memory WC object (no save).
	 *
	 * @param WC_Product $object Product or variation.
	 * @param array      $fields Canonical delta fields.
	 * @param string     $context product|variation.
	 * @param string     $money_context Money validation context.
	 * @return bool Whether something was staged.
	 */
	private function stage_delta_commerce_fields( $object, $fields, $context, $money_context ) {
		$staged = false;
		$money  = $this->validate_money_payload_fields( $fields, $money_context );
		if ( is_wp_error( $money ) ) {
			throw new RuntimeException( 'Invalid delta money field: ' . sanitize_text_field( $money->get_error_message() ) );
		}
		if ( ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() )
			&& ( $this->product_price_field_present( $fields ) || $this->product_compare_price_field_present( $fields ) ) ) {
			$this->apply_price_to_product( $object, $fields, $context, false );
			$staged = true;
		}
		if ( $this->rules->should_update_stock() ) {
			$stock_present = false;
			$stock_value   = $this->get_stock_value_from_payload( $fields, $stock_present );
			if ( $stock_present ) {
				if ( ! $this->is_valid_api_stock_payload_value( $stock_value ) || ! $this->apply_api_stock( $object, $stock_value ) ) {
					throw new RuntimeException( 'Invalid delta stock value.' );
				}
				$staged = true;
			}
		}
		return $staged;
	}

	/**
	 * Whether a variable parent keeps its own (parent-level) stock. While the Source
	 * block overlay is persisted the WooCommerce props read "unmanaged", so the
	 * remembered natural state decides.
	 *
	 * @param WC_Product $product Variable product.
	 * @return bool
	 */
	private function variable_parent_has_parent_stock( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$natural = Mobo_Core_Source_Visibility::stored_natural_stock( $product );
		if ( is_array( $natural ) ) {
			return ! empty( $natural['manage'] );
		}
		return (bool) $product->get_manage_stock( 'edit' );
	}

	/**
	 * Delta application under the product lock. Phase 1 validates and stages every
	 * change in memory; phase 2 saves. A failure in phase 1 applies nothing; a
	 * failure in phase 2 marks the product incomplete and asks for a full snapshot.
	 *
	 * @param array $payload Delta payload.
	 * @return array
	 */
	private function apply_product_delta_locked( $payload ) {
		$pre  = Mobo_Core_Product_Delta::precheck( $payload, $this );
		$guid = (string) $pre['productGuid'];
		if ( 'invalid' === $pre['status'] ) {
			return $this->result( false, 'Delta payload is malformed: ' . sanitize_key( $pre['reason'] ), array( 'deleteFile' => true, 'nonRetryable' => true, 'productGuid' => $guid, 'error' => sanitize_key( $pre['reason'] ) ) );
		}
		if ( 'ok' !== $pre['status'] && Mobo_Core_Product_Delta::is_quarantine_reason( $pre['reason'] ) ) {
			/* M6: quarantined objects are never changed by a delta, but asking for a
			 * full snapshot looped forever. Accept + record; a full snapshot is
			 * requested once the object leaves quarantine (health report). */
			Mobo_Core_Product_Delta::record_quarantined_skip( $guid, absint( $pre['objectId'] ?? 0 ), absint( $pre['revision'] ), $pre['reason'] );
			return $this->result( true, 'Delta recorded for a quarantined product; not applied.', array( 'deleteFile' => true, 'needFullSnapshot' => false, 'note' => Mobo_Core_Product_Delta::NOTE_QUARANTINED, 'productGuid' => $guid, 'reason' => sanitize_key( $pre['reason'] ), 'healthSkipped' => true ) );
		}
		if ( 'ok' !== $pre['status'] ) {
			$requested      = $this->record_delta_full_snapshot_request( $guid, absint( $pre['product']['id'] ?? 0 ), $pre['reason'] );
			$interrupted_id = absint( $pre['product']['id'] ?? 0 );
			if ( $interrupted_id > 0 && class_exists( 'Mobo_Core_Delta_Inflight' ) && Mobo_Core_Delta_Inflight::is_marked( $interrupted_id ) ) {
				/* M5: under the product lock a leftover in-flight marker is certainly
				 * from a killed delta: hand over to the incomplete marker + snapshot. */
				$marked = $this->persist_post_meta_verified( $interrupted_id, 'mobo_sync_incomplete', '1' );
				$this->upsert_product_map( $guid, $interrupted_id, true );
				if ( $marked && $requested ) {
					Mobo_Core_Delta_Inflight::clear( $interrupted_id );
				}
			}
			if ( ! $requested ) {
				return $this->result( false, 'Full snapshot request could not be written (database write failure); delta event kept for retry.', array( 'deleteFile' => false, 'productGuid' => $guid, 'error' => 'snapshot_request_unrecorded' ) );
			}
			return $this->result( true, 'Delta cannot be applied safely; a full snapshot is required.', array( 'deleteFile' => true, 'needFullSnapshot' => true, 'productGuid' => $guid, 'reason' => sanitize_key( $pre['reason'] ), 'healthSkipped' => true ) );
		}

		$product_id = absint( $pre['product']['id'] );
		$revision   = absint( $pre['revision'] );
		if ( ! empty( $pre['product']['excluded'] ) ) {
			return $this->result( true, 'Delta skipped for an excluded product.', array( 'deleteFile' => true, 'productGuid' => $guid, 'productId' => $product_id, 'healthSkipped' => true, 'skippedBecause' => 'excluded_url' ) );
		}
		$seen_revision = absint( get_post_meta( $product_id, '_mobo_product_seen_revision', true ) );
		if ( $seen_revision > $revision ) {
			return $this->result( true, 'Stale product delta revision skipped.', array( 'deleteFile' => true, 'productGuid' => $guid, 'productId' => $product_id, 'sourceRevision' => $revision, 'staleSkipped' => true ) );
		}

		$item    = Mobo_Core_Product_Delta::product_item( $payload );
		$logical = Mobo_Core_Product_Delta::logical_fields( Mobo_Core_Product_Delta::present_fields( $item, Mobo_Core_Product_Delta::PRODUCT_FIELD_ALIASES ), Mobo_Core_Product_Delta::PRODUCT_FIELD_ALIASES );
		$fields  = $this->delta_canonical_fields( $logical );
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			if ( ! $this->record_delta_full_snapshot_request( $guid, $product_id, 'product_unloadable' ) ) {
				return $this->result( false, 'Full snapshot request could not be written (database write failure); delta event kept for retry.', array( 'deleteFile' => false, 'productGuid' => $guid, 'error' => 'snapshot_request_unrecorded' ) );
			}
			return $this->result( true, 'Delta product could not be loaded; a full snapshot is required.', array( 'deleteFile' => true, 'needFullSnapshot' => true, 'productGuid' => $guid ) );
		}
		$is_variable = $product->is_type( 'variable' );

		/* Phase 1: validate + stage in memory. Nothing is persisted here. */
		$staged_objects = array();
		$flag_updates   = array();
		$watermarks     = array();
		try {
			$product_staged = false;
			if ( ! $is_variable ) {
				$product_staged = $this->stage_delta_commerce_fields( $product, $fields, 'product', 'product' ) || $product_staged;
				/* Product-level sourceStatus is visibility (applied below); only an
				 * explicit product-level purchasable drives the simple product flag. */
				if ( array_key_exists( 'purchasable', $fields ) ) {
					$flag_updates[ $product_id ] = Mobo_Core_Source_Visibility::payload_variant_purchasable( array( 'purchasable' => $fields['purchasable'] ) );
				}
			} elseif ( array_key_exists( 'stock', $fields ) && $this->variable_parent_has_parent_stock( $product ) ) {
				/* B1: a variable parent with parent-level stock (_manage_stock=yes) gets
				 * the product-level stock exactly like the full snapshot path
				 * (apply_api_stock: quantity, stock status, Source-block overlay; the
				 * save below updates the lookup table and the outofstock visibility
				 * term). Variant-derived prices are never staged on the parent. A
				 * parent whose stock is derived from its variations stays unmanaged. */
				$product_staged = $this->stage_delta_commerce_fields( $product, array( 'stock' => $fields['stock'] ), 'product', 'product' ) || $product_staged;
			}
			if ( array_key_exists( 'publishedAt', $fields ) && '' !== sanitize_text_field( (string) $fields['publishedAt'] ) ) {
				$this->apply_product_dates( $product, $fields );
				$product_staged = true;
			}
			$applied_variants = 0;
			$stale_variants   = 0;
			foreach ( $pre['targets'] as $target ) {
				$target_id = absint( $target['id'] );
				$vlogical  = Mobo_Core_Product_Delta::logical_fields( $target['fields'], Mobo_Core_Product_Delta::variant_field_aliases() );
				$vfields   = $this->delta_canonical_fields( $vlogical );
				if ( 'product' === $target['context'] ) {
					/* Simple product: the embedded variant's fields live on the product. */
					$product_staged = $this->stage_delta_commerce_fields( $product, $vfields, 'product', 'simple-variant' ) || $product_staged;
					if ( array_key_exists( 'purchasable', $vfields ) || array_key_exists( 'sourceStatus', $vfields ) ) {
						$flag_updates[ $product_id ] = Mobo_Core_Source_Visibility::payload_variant_purchasable( $vfields );
					}
					$applied_variants++;
					continue;
				}
				$variant_seen = absint( get_post_meta( $target_id, '_mobo_variant_seen_revision', true ) );
				if ( $variant_seen > absint( $target['revision'] ) ) {
					$stale_variants++;
					continue;
				}
				$variation = wc_get_product( $target_id );
				if ( ! $variation instanceof WC_Product_Variation ) {
					throw new RuntimeException( 'Delta variation could not be loaded.' );
				}
				if ( $this->stage_delta_commerce_fields( $variation, $vfields, 'variation', 'variation' ) ) {
					$staged_objects[ $target_id ] = $variation;
				}
				if ( array_key_exists( 'purchasable', $vfields ) || array_key_exists( 'sourceStatus', $vfields ) ) {
					$flag_updates[ $target_id ] = Mobo_Core_Source_Visibility::payload_variant_purchasable( $vfields );
				}
				$watermarks[ $target_id ] = absint( $target['revision'] );
				$applied_variants++;
			}
		} catch ( Throwable $e ) {
			return $this->result( false, 'Delta rejected before any write: ' . sanitize_text_field( $e->getMessage() ), array( 'deleteFile' => true, 'nonRetryable' => true, 'productGuid' => $guid, 'productId' => $product_id, 'error' => 'delta_validation_failed' ) );
		}

		/* Phase 2: persist. M5: an in-flight crash marker is written BEFORE the
		 * first write and removed after the last; a request killed in between
		 * leaves it behind, so the next delta/sweep asks for a full snapshot. */
		try {
			if ( class_exists( 'Mobo_Core_Delta_Inflight' ) && ! Mobo_Core_Delta_Inflight::mark( $product_id, $guid, $revision ) ) {
				throw new RuntimeException( 'Delta in-flight crash marker could not be persisted.' );
			}
			if ( ! $this->persist_ordering_watermarks( $product_id, 'product', '', $revision, false ) ) {
				throw new RuntimeException( 'Product delta revision watermark could not be persisted.' );
			}
			foreach ( $staged_objects as $variation ) {
				$variation->save();
			}
			if ( $product_staged ) {
				$product->save();
			}
			foreach ( $flag_updates as $flag_object_id => $purchasable ) {
				Mobo_Core_Source_Visibility::apply_inactive_flag( $flag_object_id, $purchasable );
			}
			$visibility = Mobo_Core_Source_Visibility::payload_visibility( $fields );
			if ( Mobo_Core_Source_Visibility::UNKNOWN !== $visibility ) {
				Mobo_Core_Source_Visibility::apply_to_product( $product_id, $visibility, Mobo_Core_Source_Visibility::normalize_status( isset( $fields['sourceStatus'] ) ? $fields['sourceStatus'] : '' ) );
			}
			foreach ( $watermarks as $variation_id => $variant_revision ) {
				if ( ! $this->persist_ordering_watermarks( $variation_id, 'variant', '', $variant_revision, true ) ) {
					throw new RuntimeException( 'Variant delta revision watermark could not be persisted.' );
				}
			}
			if ( ! $this->persist_ordering_watermarks( $product_id, 'product', '', $revision, true ) || ! $this->mark_webhook_product_applied( $product_id ) ) {
				throw new RuntimeException( 'Product delta applied watermark could not be persisted.' );
			}
		} catch ( Throwable $e ) {
			$marked    = $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
			$this->upsert_product_map( $guid, $product_id, true );
			$requested = $this->record_delta_full_snapshot_request( $guid, $product_id, 'delta_write_failed' );
			if ( ! $marked || ! $requested ) {
				/* Disk full / database write failure: the recovery evidence itself
				 * was not persisted. Never acknowledge the event in that state; keep
				 * the in-flight crash marker (the interrupted-delta sweep still owns
				 * the product) and let the queue retry with transient backoff. */
				return $this->result( false, 'Delta write failed and the recovery markers could not be written (database write failure); event kept for retry.', array( 'deleteFile' => false, 'productGuid' => $guid, 'productId' => $product_id, 'error' => 'delta_write_failed_unrecorded' ) );
			}
			if ( class_exists( 'Mobo_Core_Delta_Inflight' ) ) {
				Mobo_Core_Delta_Inflight::clear( $product_id ); // Superseded by the incomplete marker + snapshot request.
			}
			return $this->result( true, 'Delta write failed; product marked incomplete and a full snapshot is required.', array( 'deleteFile' => true, 'needFullSnapshot' => true, 'productGuid' => $guid, 'productId' => $product_id, 'error' => 'delta_write_failed' ) );
		}

		if ( class_exists( 'Mobo_Core_Delta_Inflight' ) ) {
			Mobo_Core_Delta_Inflight::clear( $product_id );
		}
		if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
			Mobo_Core_Runtime_Diagnostics::increment( 'product_delta_applied' );
		}
		return $this->result(
			true,
			'Product delta applied.',
			array(
				'deleteFile'      => true,
				'deltaApplied'    => true,
				'productGuid'     => $guid,
				'productId'       => $product_id,
				'sourceRevision'  => $revision,
				'appliedVariants' => $applied_variants,
				'staleVariants'   => $stale_variants,
			)
		);
	}

	/**
	 * Remember products whose delta could not be applied, so the health report can
	 * ask the Backend for full snapshots even when the delta was processed from the
	 * queue after the HTTP response. Bounded to 200 entries.
	 *
	 * @param string $guid Product GUID.
	 * @param int    $product_id Local product ID (0 when unknown).
	 * @param string $reason Reason code.
	 * @return void
	 */
	/** @var bool Request-local: interrupted-delta sweep already ran. */
	private static $delta_sweep_done = false;

	/**
	 * M5: on restart, products whose delta was killed halfway (in-flight marker
	 * older than any live product lock) are marked incomplete and a full
	 * snapshot is requested for them. Bounded; once per request.
	 *
	 * @return void
	 */
	private function sweep_interrupted_deltas() {
		if ( self::$delta_sweep_done || ! class_exists( 'Mobo_Core_Delta_Inflight' ) ) {
			return;
		}
		self::$delta_sweep_done = true;
		foreach ( Mobo_Core_Delta_Inflight::stale( time() ) as $row ) {
			$product_id = absint( $row['productId'] );
			$guid       = (string) $row['productGuid'];
			if ( $product_id <= 0 ) {
				continue;
			}
			if ( '' === $guid ) {
				Mobo_Core_Delta_Inflight::clear( $product_id );
				continue;
			}
			try {
				$marked = $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
				$this->upsert_product_map( $guid, $product_id, true );
				$requested = $this->record_delta_full_snapshot_request( $guid, $product_id, 'delta_interrupted' );
				if ( $marked && $requested ) {
					/* Clear the crash marker only once its replacement evidence is durable. */
					Mobo_Core_Delta_Inflight::clear( $product_id );
				}
			} catch ( Throwable $error ) {
				if ( class_exists( 'Mobo_Core_Logger' ) ) {
					Mobo_Core_Logger::warning( 'Interrupted delta recovery deferred.', array( 'productId' => $product_id, 'error' => $error->getMessage() ) );
				}
			}
		}
	}

	private function record_delta_full_snapshot_request( $guid, $product_id, $reason ) {
		$guid = sanitize_text_field( (string) $guid );
		if ( '' === $guid ) {
			return false;
		}
		$requests = Mobo_Core_Settings::get( 'mobo_core_delta_full_snapshot_requests', array() );
		$requests = is_array( $requests ) ? $requests : array();
		unset( $requests[ $guid ] );
		$requests[ $guid ] = array( 'at' => time(), 'reason' => sanitize_key( (string) $reason ) );
		if ( count( $requests ) > 200 ) {
			$requests = array_slice( $requests, -200, null, true );
		}
		$stored = Mobo_Core_Settings::update( 'mobo_core_delta_full_snapshot_requests', $requests, false );
		if ( ! $stored ) {
			/* update_option() is also false for an unchanged value. */
			$current = Mobo_Core_Settings::get( 'mobo_core_delta_full_snapshot_requests', array() );
			$stored  = is_array( $current ) && isset( $current[ $guid ] );
		}
		if ( absint( $product_id ) > 0 && class_exists( 'Mobo_Core_Sync_Health' ) && method_exists( 'Mobo_Core_Sync_Health', 'mark_behind' ) ) {
			Mobo_Core_Sync_Health::mark_behind( $guid, absint( $product_id ) );
		}
		return (bool) $stored;
	}

	/**
	 * Adaptive Sync/Repair page sizes.
	 *
	 * The old fixed defaults (1 product / 5 variants per HTTP page) cost one Backend
	 * round trip plus one durable checkpoint per product. Pages now default to 10
	 * products / 25 variants and are scaled by the adaptive tuner's learned host
	 * speed (0.25x..4x, damped under memory/time pressure). The merchant settings act
	 * as a floor; the hard ceilings stay 20 / 100. Products are still applied one per
	 * cooperative step, so a larger page never lengthens a single step's write work.
	 * Mode `fixed` (option mobo_core_sync_page_size_mode) restores exact settings.
	 *
	 * @return array{0:int,1:int}
	 */
	public static function effective_page_sizes() {
		$configured_products = Mobo_Core_Settings::get_int( 'mobo_core_products_per_page', 1, 1, 20 );
		$configured_variants = Mobo_Core_Settings::get_int( 'mobo_core_variants_per_page', 5, 1, 100 );
		if ( 'fixed' === sanitize_key( (string) Mobo_Core_Settings::get( 'mobo_core_sync_page_size_mode', 'adaptive' ) ) ) {
			return array( $configured_products, $configured_variants );
		}
		$products = class_exists( 'Mobo_Core_Adaptive_Tuner' ) ? Mobo_Core_Adaptive_Tuner::page_size( 10, $configured_products, 20 ) : max( 10, $configured_products );
		$variants = class_exists( 'Mobo_Core_Adaptive_Tuner' ) ? Mobo_Core_Adaptive_Tuner::page_size( 25, $configured_variants, 100 ) : max( 25, $configured_variants );
		$sizes    = apply_filters( 'mobo_core_sync_page_sizes', array( $products, $variants ), $configured_products, $configured_variants );
		$products = is_array( $sizes ) && isset( $sizes[0] ) ? max( 1, min( 20, absint( $sizes[0] ) ) ) : $products;
		$variants = is_array( $sizes ) && isset( $sizes[1] ) ? max( 1, min( 100, absint( $sizes[1] ) ) ) : $variants;
		return array( $products, $variants );
	}

	/**
	 * Whether a converged-object no-op fast path may run.
	 *
	 * Normal Sync: always (each caller still verifies storefront state). Repair used
	 * to disable every no-op path, forcing a full WooCommerce save + cache purge for
	 * every product. Repair now keeps no-op detection and only forces the full save
	 * for structural drift: the stored source hash must equal the incoming hash
	 * exactly (no source change since the last complete apply) and the post must be
	 * in its expected live status. Anything else falls through to the full save.
	 *
	 * @param int    $object_id Product/variation ID.
	 * @param string $old_hash Stored source hash.
	 * @param string $incoming_hash Incoming source hash.
	 * @param string $kind product|variation.
	 * @return bool
	 */
	private function noop_fast_path_allowed( $object_id, $old_hash, $incoming_hash, $kind = 'product' ) {
		if ( ! $this->is_repair_mode() ) {
			return true;
		}
		$object_id = absint( $object_id );
		if ( $object_id <= 0 || '' === (string) $incoming_hash || ! hash_equals( (string) $incoming_hash, (string) $old_hash ) ) {
			return false;
		}
		if ( (bool) apply_filters( 'mobo_core_repair_force_full_save', false, $object_id, $kind ) ) {
			return false;
		}
		$status  = sanitize_key( (string) get_post_status( $object_id ) );
		$allowed = 'variation' === $kind ? array( 'publish', 'private' ) : array( 'publish' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}
		if ( '1' === (string) get_post_meta( $object_id, '_mobo_desired_state_rebuild_pending', true ) ) {
			return false;
		}
		if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
			Mobo_Core_Runtime_Diagnostics::increment( 'repair_noop' );
		}
		return true;
	}

	/**
	 * Get repair completion timestamp.
	 *
	 * @return int
	 */
	public static function get_repair_completed_at() {
		return absint( Mobo_Core_Settings::get( self::REPAIR_COMPLETED_OPTION, 0 ) );
	}

	/**
	 * Whether the required repair pass has completed.
	 *
	 * @return bool
	 */
	public static function is_repair_completed() {
		return self::get_repair_completed_at() > 0;
	}

	public function process_product_updated_payload( &$payload ) {
		/* product-delta-v1: a delta never enters the full-snapshot pipeline (whose
		 * absent-field and variant-list semantics assume a complete payload). */
		if ( class_exists( 'Mobo_Core_Product_Delta' ) && Mobo_Core_Product_Delta::is_delta( $payload ) ) {
			return $this->apply_product_delta( $payload );
		}
		$context = $this->extract_product_updated_result_context( $payload );
		if ( class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) ) {
			$result = Mobo_Core_Cache_Mutation_Guard::run(
				function () use ( &$payload ) {
					return $this->process_product_updated_payload_guarded( $payload );
				},
				$this->is_repair_mode() ? 'repair-product' : 'webhook-product'
			);
		} else {
			$result = $this->process_product_updated_payload_guarded( $payload );
		}

		return $this->attach_result_sync_context( $result, $context );
	}

	/** Build durable identity/revision context for the ProductUpdated item about to run. */
	private function extract_product_updated_result_context( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		$items   = $this->get_value( $payload, 'data', array() );
		$index   = max( 0, absint( $this->get_value( $payload, '_moboProductIndex', 0 ) ) );
		$item    = is_array( $items ) && isset( $items[ $index ] ) && is_array( $items[ $index ] ) ? $items[ $index ] : array();
		$guid    = $this->extract_product_guid( $item );
		return array(
			'productGuid'    => $guid,
			'productId'      => '' !== $guid ? $this->find_product_id_by_guid( $guid ) : 0,
			'portalProductId'=> $this->extract_portal_product_id( $item ),
			'sourceRevision' => $this->extract_source_revision( $item, $payload ),
			'sourceVersion'  => $this->extract_source_version( $item, $payload ),
			'sourceHash'     => '' !== $guid ? $this->build_product_source_hash( $item, $guid ) : '',
		);
	}

	/** Attach item identity to every checkpoint result, including batch partials. */
	private function attach_result_sync_context( $result, $context ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}
		if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
			$result['data'] = array();
		}
		foreach ( array( 'productGuid', 'productId', 'portalProductId', 'sourceRevision', 'sourceVersion', 'sourceHash' ) as $key ) {
			if ( ! isset( $result['data'][ $key ] ) && isset( $context[ $key ] ) && '' !== (string) $context[ $key ] && 0 !== $context[ $key ] ) {
				$result['data'][ $key ] = $context[ $key ];
			}
		}
		return $result;
	}

	/** Extract a numeric source revision from item/wrapper without inventing order. */
	private function extract_source_revision( $item, $wrapper = array() ) {
	return Mobo_Core_Ordering_Policy::extract_numeric_revision_from_sources( $item, $wrapper );
}

	/** Extract the raw ordering watermark without assuming it is numeric. */
	private function extract_source_version( $item, $wrapper = array() ) {
	return Mobo_Core_Ordering_Policy::extract_version_from_sources( $item, $wrapper );
}

	/** Compare numeric or ISO-date source versions; null means format is not safely ordered. */
	private function compare_source_versions( $left, $right ) {
	return Mobo_Core_Ordering_Policy::compare_versions( $left, $right );
}

	/**
	 * Whether a delayed ProductUpdated can preserve an already-converged variable
	 * product completion boundary.
	 *
	 * UpdateVariant and ProductUpdated for one desired-state revision may execute
	 * out of order. If a terminal UpdateVariant for the same/newer boundary has
	 * already committed, a later ProductUpdated must not leave the parent crash
	 * marker/map incomplete again unless the parent topology actually changed or
	 * local variation structure is no longer sane.
	 *
	 * @param int    $product_id WooCommerce parent product ID.
	 * @param string $source_version ProductUpdated source version.
	 * @param int    $source_revision ProductUpdated source revision.
	 * @param bool   $attribute_structure_changed Whether ProductUpdated changed parent attribute structure.
	 * @return bool
	 */
	private function variable_completion_already_covers_product_update( $product_id, $source_version, $source_revision, $attribute_structure_changed ) {
		$product_id                 = absint( $product_id );
		$source_version             = sanitize_text_field( (string) $source_version );
		$source_revision            = absint( $source_revision );
		$attribute_structure_changed = (bool) $attribute_structure_changed;

		if ( $product_id <= 0 || $attribute_structure_changed ) {
			return false;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		if ( ! $product instanceof WC_Product_Variable ) {
			return false;
		}

		if ( '1' === (string) get_post_meta( $product_id, '_mobo_desired_state_rebuild_pending', true ) ) {
			return false;
		}

		if ( ! class_exists( 'Mobo_Core_Sync_Health' ) || ! Mobo_Core_Sync_Health::local_product_structure_is_sane( $product_id ) ) {
			return false;
		}

		$variant_version  = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_variant_applied_event_version', true ) );
		$variant_revision = absint( get_post_meta( $product_id, '_mobo_variant_applied_revision', true ) );

		if ( '' !== $source_version && '' !== $variant_version ) {
			$version_cmp = $this->compare_source_versions( $variant_version, $source_version );
			if ( null !== $version_cmp ) {
				return $version_cmp >= 0;
			}
		}

		if ( $source_revision > 0 && $variant_revision > 0 ) {
			return $variant_revision >= $source_revision;
		}

		return false;
	}

	/**
	 * Process ProductUpdated after entering the Mobo cache mutation scope.
	 *
	 * @param array $payload Payload, by reference.
	 * @return array
	 */
	private function process_product_updated_payload_guarded( &$payload ) {
		$product_guid = $this->extract_current_product_guid_from_product_updated_payload( $payload );

		if ( '' !== $product_guid && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			if ( Mobo_Core_Product_Concurrency::is_manual_sync_busy_for_product( $product_guid ) ) {
				return Mobo_Core_Product_Concurrency::defer_result( $product_guid, 'manual_sync_active', 45 );
			}

			$lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 5, 180 );

			if ( false === $lock ) {
				return Mobo_Core_Product_Concurrency::defer_result( $product_guid, 'product_lock_busy', 30 );
			}

			try {
				return $this->process_product_updated_payload_unlocked( $payload );
			} finally {
				Mobo_Core_Product_Concurrency::release_product_lock( $lock );
			}
		}

		return $this->process_product_updated_payload_unlocked( $payload );
	}

	private function process_product_updated_payload_unlocked( &$payload ) {
		if ( ! is_array( $payload ) ) {
			return $this->result( false, 'Invalid ProductUpdated payload.' );
		}

		$items = $this->get_value( $payload, 'data', array() );

		if ( ! is_array( $items ) ) {
			return $this->result( false, 'ProductUpdated data must be array.' );
		}

		$product_index = max( 0, absint( $this->get_value( $payload, '_moboProductIndex', 0 ) ) );
		$image_offset  = max( 0, absint( $this->get_value( $payload, '_moboImageOffset', 0 ) ) );

		if ( ! isset( $items[ $product_index ] ) || ! is_array( $items[ $product_index ] ) ) {
			unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );

			return $this->result( true, 'ProductUpdated completed.', array( 'deleteFile' => true ) );
		}

		$product_data = $items[ $product_index ];
		if ( $this->should_skip_product_by_url( $product_data ) ) {
			$product_index++;

			$payload['_moboProductIndex'] = $product_index;
			$payload['_moboImageOffset']  = 0;

			if ( $product_index < count( $items ) ) {
				return $this->result(
					true,
					'ProductUpdated skipped excluded product; products remaining.',
					array(
						'deleteFile'    => false,
						'healthSkipped' => true,
						'skippedBecause'=> 'excluded_url',
					)
				);
			}

			unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );

			return $this->result(
				true,
				'ProductUpdated skipped excluded product.',
				array(
					'deleteFile'    => true,
					'healthSkipped' => true,
					'skippedBecause'=> 'excluded_url',
				)
			);
		}

		$product_guid = $this->extract_product_guid( $product_data );

		if ( '' === $product_guid ) {
			$product_index++;
			$payload['_moboProductIndex'] = $product_index;
			$payload['_moboImageOffset']  = 0;
			$terminal = $product_index >= count( $items );
			if ( $terminal ) {
				unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );
			}
			return $this->result(
				true,
				'ProductUpdated skipped malformed product without a stable GUID.',
				array(
					'deleteFile'     => $terminal,
					'healthSkipped'  => true,
					'skippedBecause' => 'missing_product_guid',
				)
			);
		}

		if ( $this->is_remote_product_trashed( $product_guid ) ) {
			$product_index++;
			$payload['_moboProductIndex'] = $product_index;
			$payload['_moboImageOffset']  = 0;

			if ( $product_index < count( $items ) ) {
				return $this->result(
					true,
					'ProductUpdated skipped trashed product; products remaining.',
					array(
						'deleteFile'    => false,
						'productGuid'   => $product_guid,
						'healthSkipped' => true,
						'skippedBecause'=> 'product_trashed',
					)
				);
			}

			unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );

			return $this->result(
				true,
				'ProductUpdated skipped trashed product.',
				array(
					'deleteFile'    => true,
					'productGuid'   => $product_guid,
					'healthSkipped' => true,
					'skippedBecause'=> 'product_trashed',
				)
			);
		}

		$existing_product_id       = '' !== $product_guid ? $this->find_product_id_by_guid( $product_guid ) : 0;

		/* Never create a product that is not published on the Source. Acknowledge the
		 * event (no retry storm); a later publish arrives as a new revision and creates it. */
		if ( $existing_product_id <= 0 && ! Mobo_Core_Source_Visibility::payload_allows_creation( $product_data ) ) {
			$product_index++;
			$payload['_moboProductIndex'] = $product_index;
			$payload['_moboImageOffset']  = 0;
			$terminal = $product_index >= count( $items );
			if ( $terminal ) {
				unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );
			}
			return $this->result(
				true,
				'ProductUpdated skipped a product that is not published on the Source; it was not created.',
				array(
					'deleteFile'     => $terminal,
					'productGuid'    => $product_guid,
					'healthSkipped'  => true,
					'skippedBecause' => 'source_not_published',
				)
			);
		}

		$source_version            = $this->extract_source_version( $product_data, $payload );
		$source_revision           = $this->extract_source_revision( $product_data, $payload );
		if ( $existing_product_id > 0 && '' !== $source_version ) {
			$seen_version = sanitize_text_field( (string) get_post_meta( $existing_product_id, '_mobo_product_seen_event_version', true ) );
			$version_cmp  = $this->compare_source_versions( $source_version, $seen_version );
			if ( '' !== $seen_version && null === $version_cmp && $source_version !== $seen_version ) {
				return $this->result( false, 'ProductUpdated ordering watermark is not comparable with the durable product watermark; mutation was blocked.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $existing_product_id, 'sourceVersion' => $source_version, 'orderingConflict' => true, 'nonRetryable' => true ) );
			}
			if ( 0 === $version_cmp ) {
				$incoming_source_hash = $this->build_product_source_hash( $product_data, $product_guid );
				$stored_source_hash   = sanitize_text_field( (string) get_post_meta( $existing_product_id, '_mobo_product_source_hash', true ) );
				$applied_version      = sanitize_text_field( (string) get_post_meta( $existing_product_id, '_mobo_product_applied_event_version', true ) );
				if ( '' !== $incoming_source_hash && '' !== $stored_source_hash && $applied_version === $source_version && ! hash_equals( $stored_source_hash, $incoming_source_hash ) ) {
					return $this->result( false, 'Equal-version ProductUpdated payload conflicts with the durable applied source hash; mutation was blocked.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $existing_product_id, 'sourceVersion' => $source_version, 'orderingConflict' => true, 'nonRetryable' => true ) );
				}
			}
			if ( -1 === $version_cmp ) {
				$product_index++;
				$payload['_moboProductIndex'] = $product_index;
				$payload['_moboImageOffset']  = 0;
				$terminal = $product_index >= count( $items );
				if ( $terminal ) {
					unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );
				}
				return $this->result( true, 'Stale ProductUpdated event version skipped.', array( 'deleteFile' => $terminal, 'productGuid' => $product_guid, 'productId' => $existing_product_id, 'sourceRevision' => $source_revision, 'sourceVersion' => $source_version, 'staleSkipped' => true ) );
			}
			if ( ( null !== $version_cmp || '' === $seen_version ) && ! $this->persist_post_meta_verified( $existing_product_id, '_mobo_product_seen_event_version', $source_version ) ) {
				return $this->result( false, 'Product event-version watermark could not be persisted; mutation was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
		}
		if ( $existing_product_id > 0 && $source_revision > 0 ) {
			$seen_revision = absint( get_post_meta( $existing_product_id, '_mobo_product_seen_revision', true ) );
			if ( $seen_revision > $source_revision ) {
				$product_index++;
				$payload['_moboProductIndex'] = $product_index;
				$payload['_moboImageOffset']  = 0;
				$terminal = $product_index >= count( $items );
				if ( $terminal ) {
					unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );
				}
				return $this->result( true, 'Stale ProductUpdated revision skipped.', array( 'deleteFile' => $terminal, 'productGuid' => $product_guid, 'productId' => $existing_product_id, 'sourceRevision' => $source_revision, 'staleSkipped' => true ) );
			}
			if ( ! $this->persist_post_meta_verified( $existing_product_id, '_mobo_product_seen_revision', $source_revision ) ) {
				return $this->result( false, 'Product revision watermark could not be persisted; mutation was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
		}
		$was_existing             = $existing_product_id > 0;
		$attribute_structure_changed = $was_existing && $this->product_attributes_field_present( $product_data ) && $this->product_attribute_structure_changed(
			wc_get_product( $existing_product_id ),
			$this->get_value( $product_data, 'attributes', array() )
		);

		/* Keep the current variations until a complete authoritative replacement
		 * snapshot is available. ProductUpdated alone is not proof that all variant
		 * data has arrived, so deleting children here can make a product unsellable
		 * when the follow-up variant event is delayed or lost. */
		$product_id = $this->upsert_parent_product( $product_data, true );
		if ( $product_id > 0 && ( '' !== $source_version || $source_revision > 0 ) && ! $this->persist_ordering_watermarks( $product_id, 'product', $source_version, $source_revision, true ) ) {
			$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
			$this->upsert_product_map( $product_guid, $product_id, true );
			return $this->result( false, 'Product ordering watermark could not be committed after mutation; event will retry idempotently.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $product_id, 'retryable' => true ) );
		}

		if ( $product_id > 0 && ! empty( $payload['_moboWebhookForegroundContext'] ) && ! $this->mark_webhook_product_applied( $product_id ) ) {
			$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
			$this->upsert_product_map( $product_guid, $product_id, true );
			return $this->result( false, 'Foreground webhook ordering fence could not be persisted; event will retry idempotently.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $product_id, 'retryable' => true ) );
		}

		if ( $attribute_structure_changed && $product_id > 0 ) {
			update_post_meta( $product_id, '_mobo_desired_state_rebuild_pending', '1' );
			update_post_meta( $product_id, '_mobo_desired_state_rebuild_requested_at', gmdate( 'c' ) );
			if ( class_exists( 'Mobo_Core_Sync_Health' ) ) {
				Mobo_Core_Sync_Health::mark_behind( $product_guid, $product_id );
			}
		}

		if ( $product_id <= 0 ) {
			return $this->result(
				false,
				'ProductUpdated could not durably create/update the product; event will retry without advancing the batch cursor.',
				array(
					'deleteFile'   => false,
					'productGuid'  => $product_guid,
					'retryable'    => true,
				)
			);
		}

		$product_images = $this->get_product_images_from_payload( $product_data );
		$images_dirty   = ( ! $was_existing || $this->product_images_field_present( $product_data ) )
			&& ( ! $was_existing || $this->rules->should_update_images() )
			&& $this->product_images_need_processing( $product_id, $product_images, ! $was_existing );

		if ( $images_dirty ) {
			$image_result = $this->image_sync->process_images(
				$product_id,
				$product_images,
				$image_offset,
				false
			);

			if ( ! empty( $image_result['error'] ) ) {
				return $this->result(
					true,
					'ProductUpdated منتظر ثبت امن Desired State تصاویر در صف دیتابیس است.',
					array(
						'deleteFile'   => false,
						'deferSeconds' => 60,
						'productId'    => $product_id,
						'error'        => sanitize_text_field( (string) $image_result['error'] ),
					)
				);
			}

			if ( empty( $image_result['done'] ) ) {
				$payload['_moboProductIndex'] = $product_index;
				$payload['_moboImageOffset']  = absint( $image_result['nextOffset'] );

				return $this->result(
					true,
					'ProductUpdated partially processed; images remaining.',
					array(
						'deleteFile' => false,
						'productId'  => $product_id,
						'offset'     => absint( $image_result['nextOffset'] ),
					)
				);
			}

			if ( ! empty( $image_result['pending'] ) ) {
				if ( ! $this->mark_product_images_pending( $product_id, $product_images ) ) {
					return $this->result( false, 'ثبت watermark در انتظار تصاویر محصول ناموفق بود؛ event برای retry حفظ شد.', array( 'deleteFile' => false, 'productId' => $product_id, 'retryable' => true ) );
				}
			} elseif ( ! $this->mark_product_images_converged( $product_id, $product_images ) ) {
				return $this->result( false, 'ثبت watermark نهایی تصاویر محصول ناموفق بود؛ event برای retry حفظ شد.', array( 'deleteFile' => false, 'productId' => $product_id, 'retryable' => true ) );
			}
		}

		/*
		 * Product attributes alone are not proof that the storefront topology is
		 * variable. A WC_Product_Simple can legitimately carry descriptive/product
		 * attributes while its source topology contains exactly one purchasable
		 * Variant with no selection attributes.
		 *
		 * When the current Woo object is Simple, probe the authoritative Variant
		 * endpoint even if ProductUpdated carries attributes. A mapped no-selection
		 * Variant (or authoritative zero-Variant state) proves Simple topology and
		 * can complete immediately. Ambiguous/selection-attribute snapshots remain
		 * incomplete and are left for UpdateVariant to converge.
		 */
		$payload_can_have_variants = $this->product_payload_can_have_variants( $product_data, $product_id );
		$current_product_for_topology = wc_get_product( $product_id );
		$should_probe_simple_topology = ! $payload_can_have_variants || $current_product_for_topology instanceof WC_Product_Simple;

		if ( $should_probe_simple_topology ) {
			$api = new Mobo_Core_API_Client();

			/* PERF09: Portal already exposes a lightweight count endpoint. When the
			 * ProductUpdated payload itself contains variation-capable attributes and
			 * the local parent is still Simple, a count > 1 is sufficient to prove that
			 * selecting one purchasable Variant for the Simple parent would be unsafe.
			 * This is deliberately one-way/non-destructive: count 0/1, malformed data or
			 * any endpoint failure falls back to the full authoritative Variant snapshot. */
			$simple_snapshot = null;
			if ( $payload_can_have_variants
				&& $current_product_for_topology instanceof WC_Product_Simple
				&& method_exists( $api, 'get_variants_count' )
			) {
				$variant_count = $api->get_variants_count( $product_guid );
				if ( ! is_wp_error( $variant_count ) && absint( $variant_count ) > 1 ) {
					$simple_snapshot = array(
						'mode'         => 'variable_handoff',
						'totalCount'   => absint( $variant_count ),
						'countFastPath'=> true,
						'message'      => 'Variant count proves multi-Variant topology; full Simple lookup was skipped.',
					);
				}
			}

			if ( ! is_array( $simple_snapshot ) ) {
				$simple_snapshot = $this->fetch_simple_product_variant_snapshot_from_api(
					$api,
					$product_guid,
					$product_id,
					'product-updated-' . gmdate( 'YmdHis' )
				);
			}

			if ( is_wp_error( $simple_snapshot ) ) {
				$probe_error_code = sanitize_key( (string) $simple_snapshot->get_error_code() );

				if ( $payload_can_have_variants && 'mobo_core_simple_variant_pagination_ambiguous' === $probe_error_code ) {
					/*
					 * Additional authoritative Variant pages are not a transient
					 * Simple lookup failure. They prove that selecting one Variant
					 * for the Simple parent would be unsafe. Preserve the incomplete
					 * marker and let the normal UpdateVariant snapshot perform the
					 * Simple-to-Variable convergence.
					 */
					$simple_snapshot = array(
						'mode'    => 'variable_handoff',
						'message' => $simple_snapshot->get_error_message(),
					);
				} else {
					return $this->result(
						true,
						'ProductUpdated منتظر دریافت topology قابل اتکای Variant است.',
						array(
							'deleteFile'   => false,
							'deferSeconds' => 60,
							'productId'    => $product_id,
							'error'        => $simple_snapshot->get_error_message(),
						)
					);
				}
			}

			$snapshot_mode = sanitize_key( (string) ( isset( $simple_snapshot['mode'] ) ? $simple_snapshot['mode'] : '' ) );
			$snapshot_variant = isset( $simple_snapshot['variant'] ) && is_array( $simple_snapshot['variant'] )
				? $simple_snapshot['variant']
				: array();
			$simple_topology_proven = 'authoritative_missing' === $snapshot_mode
				|| ( 'mapped' === $snapshot_mode && ! $this->variant_has_selection_attributes( $snapshot_variant ) );

			if ( $simple_topology_proven ) {
				$simple_variant = $this->apply_simple_product_variant_snapshot(
					$product_id,
					$product_guid,
					$simple_snapshot
				);

				if ( is_wp_error( $simple_variant ) ) {
					return $this->result(
						true,
						'ProductUpdated منتظر commit امن topology محصول ساده است.',
						array(
							'deleteFile'   => false,
							'deferSeconds' => 60,
							'productId'    => $product_id,
							'error'        => $simple_variant->get_error_message(),
						)
					);
				}

				$payload_can_have_variants = false;
			} elseif ( ! $payload_can_have_variants ) {
				/*
				 * A payload that itself proves no variation attributes but receives
				 * an ambiguous Variant snapshot cannot be acknowledged complete.
				 * Preserve the previous fail-closed retry behavior.
				 */
				$message = isset( $simple_snapshot['message'] )
					? sanitize_text_field( (string) $simple_snapshot['message'] )
					: 'Simple product Variant snapshot is ambiguous.';
				return $this->result(
					true,
					'ProductUpdated منتظر رفع ابهام topology محصول ساده است.',
					array(
						'deleteFile'   => false,
						'deferSeconds' => 60,
						'productId'    => $product_id,
						'error'        => $message,
					)
				);
			}
		}

		$preserve_variable_completion = $payload_can_have_variants
			&& $this->variable_completion_already_covers_product_update( $product_id, $source_version, $source_revision, $attribute_structure_changed );

		if ( ! $payload_can_have_variants || $preserve_variable_completion ) {
			if ( ! $this->upsert_product_map( $product_guid, $product_id, false ) ) {
				$this->update_post_meta_if_changed( $product_id, 'mobo_sync_incomplete', '1' );
				return $this->result( true, 'ProductUpdated منتظر ثبت پایدار Product Map است.', array( 'deleteFile' => false, 'deferSeconds' => 60, 'productGuid' => $product_guid, 'productId' => $product_id ) );
			}
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '0' ) ) {
				$this->upsert_product_map( $product_guid, $product_id, true );
				return $this->result( true, 'ProductUpdated منتظر ثبت پایدار completion marker است.', array( 'deleteFile' => false, 'deferSeconds' => 60, 'productGuid' => $product_guid, 'productId' => $product_id ) );
			}
		}

		$product_index++;
		$payload['_moboProductIndex'] = $product_index;
		$payload['_moboImageOffset']  = 0;

		if ( $product_index < count( $items ) ) {
			return $this->result( true, 'ProductUpdated partially processed; products remaining.', array( 'deleteFile' => false ) );
		}

		unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );

		return $this->result( true, 'ProductUpdated processed.', array( 'deleteFile' => true ) );
	}

	public function process_update_variant_payload( $payload, $from_manual_sync = false ) {
		if ( class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) ) {
			return Mobo_Core_Cache_Mutation_Guard::run(
				function () use ( $payload, $from_manual_sync ) {
					return $this->process_update_variant_payload_guarded( $payload, $from_manual_sync );
				},
				( $from_manual_sync || $this->is_repair_mode() ) ? 'repair-variant' : 'webhook-variant'
			);
		}

		return $this->process_update_variant_payload_guarded( $payload, $from_manual_sync );
	}

	/**
	 * Process UpdateVariant after entering the Mobo cache mutation scope.
	 *
	 * @param array $payload Payload.
	 * @param bool  $from_manual_sync Whether called by manual Sync/Repair.
	 * @return array
	 */
	private function process_update_variant_payload_guarded( $payload, $from_manual_sync = false ) {
		$product_guid = $this->extract_product_guid_from_update_variant_payload( $payload );

		if ( '' !== $product_guid && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			if ( ! $from_manual_sync && Mobo_Core_Product_Concurrency::is_manual_sync_busy_for_product( $product_guid ) ) {
				return Mobo_Core_Product_Concurrency::defer_result( $product_guid, 'manual_sync_active', 45 );
			}

			$lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 5, 180 );

			if ( false === $lock ) {
				return Mobo_Core_Product_Concurrency::defer_result( $product_guid, 'product_lock_busy', 30 );
			}

			try {
				return $this->process_update_variant_payload_unlocked( $payload, $lock );
			} finally {
				Mobo_Core_Product_Concurrency::release_product_lock( $lock );
			}
		}

		return $this->process_update_variant_payload_unlocked( $payload );
	}

	private function process_update_variant_payload_unlocked( $payload, $product_lock = false ) {
		if ( ! is_array( $payload ) ) {
			return $this->result( false, 'Invalid UpdateVariant payload.' );
		}

		/*
		 * Be tolerant of all known MoboCore shapes:
		 * 1) VariantSyncPagedResult: { productId, data: [...] }
		 * 2) EventModel wrapper: { event: UpdateVariant, data: { productId, data: [...] } }
		 * 3) Legacy list wrapper: { data: [ { productId, ... } ] }
		 * 4) Variant-specific metadata: { variantId/entityGuid } where parent can be found from product_map.
		 */
		$inner_data = $this->get_value( $payload, 'data', null );
		if ( is_array( $inner_data ) && ! $this->is_list_array( $inner_data ) ) {
			$inner_product_guid = $this->extract_product_guid( $inner_data );

			$inner_variants = $this->get_value( $inner_data, 'data', null );
			if ( '' !== $inner_product_guid || is_array( $inner_variants ) ) {
				$payload = array_merge( $payload, $inner_data );
			}
		}

		$product_guid = $this->extract_product_guid( $payload );
		$sync_id      = sanitize_text_field( (string) $this->get_value( $payload, 'syncId', '' ) );
		$page_number  = max( 1, absint( $this->get_value( $payload, 'pageNumber', 1 ) ) );
		$has_more     = $this->get_value( $payload, 'hasMore', false );
		$is_last_page = $this->get_value( $payload, 'isLastPage', null );
		$variant_collection_inspection = Mobo_Core_Payload_Field_Policy::inspect( $payload, array( 'data', 'Data' ) );
		$variant_collection_present    = ! empty( $variant_collection_inspection['present'] );
		$variant_collection_is_list    = $variant_collection_present && is_array( $variant_collection_inspection['value'] ) && $this->is_list_array( $variant_collection_inspection['value'] );
		$variants     = $this->get_value( $payload, 'data', array() );

		if ( is_array( $variants ) && ! $this->is_list_array( $variants ) ) {
			$nested_variants = $this->get_value( $variants, 'data', null );
			if ( is_array( $nested_variants ) ) {
				$variants = $nested_variants;
			} elseif ( $this->looks_like_variant_payload( $variants ) ) {
				$variants = array( $variants );
			}
		}

		if ( ! is_array( $variants ) || ! $this->is_list_array( $variants ) ) {
			$variants = array();
		}

		if ( '' === $product_guid && isset( $variants[0] ) && is_array( $variants[0] ) ) {
			$product_guid = $this->extract_product_guid( $variants[0] );
		}


		if ( '' === $product_guid ) {
			$variant_guid = $this->extract_variant_guid( $payload );
			if ( '' !== $variant_guid ) {
				$product_guid = $this->find_parent_product_guid_by_variant_guid( $variant_guid );
			}
		}

		if ( '' === $product_guid ) {
			return $this->result( false, $this->build_missing_product_id_message( $payload, $variants ) );
		}

		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_product_guid_excluded( $product_guid ) ) {
			return $this->result(
				true,
				'محصول مادر در فهرست عدم همگام‌سازی است؛ رویداد تنوع بدون mutation خاتمه یافت.',
				array(
					'deleteFile'     => true,
					'productGuid'    => $product_guid,
					'healthSkipped'  => true,
					'skippedBecause' => 'excluded_url',
				)
			);
		}

		foreach ( $variants as $variant_index => $variant_data ) {
			if ( is_array( $variant_data ) ) {
				$variant_product_guid = $this->extract_product_guid( $variant_data );

				if ( '' === $variant_product_guid ) {
					$variants[ $variant_index ]['productId'] = $product_guid;
				}
			}
		}

		if ( $this->is_remote_product_trashed( $product_guid ) ) {
			return $this->result(
				true,
				'محصول مادر در سطل زباله وردپرس است؛ تنوع‌های آن رد شدند.',
				array(
					'deleteFile'     => true,
					'productGuid'    => $product_guid,
					'healthSkipped'  => true,
					'skippedBecause' => 'parent_trashed',
				)
			);
		}

		if ( '' === $sync_id ) {
			$sync_id = 'no-sync-id-' . $product_guid;
		}

		$product_id = $this->find_product_id_by_guid( $product_guid );

		if ( $product_id > 0 && class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $product_id ) ) {
			return $this->result(
				true,
				'محصول مادر در فهرست عدم همگام‌سازی است؛ رویداد تنوع بدون mutation خاتمه یافت.',
				array(
					'deleteFile'     => true,
					'productGuid'    => $product_guid,
					'healthSkipped'  => true,
					'skippedBecause' => 'excluded_url',
				)
			);
		}

		if ( $product_id <= 0 && Mobo_Core_Source_Visibility::HIDDEN === Mobo_Core_Source_Visibility::variant_page_parent_visibility( $payload ) ) {
			/* The parent is intentionally never created while it is not published on the
			 * Source; waiting for it would retry forever. */
			return $this->result(
				true,
				'محصول مادر در منبع منتشر نشده است؛ تنوع‌ها بدون ساخت محصول رد شدند.',
				array(
					'deleteFile'     => true,
					'productGuid'    => $product_guid,
					'healthSkipped'  => true,
					'skippedBecause' => 'source_not_published',
				)
			);
		}

		if ( $product_id <= 0 ) {
			return $this->result(
				true,
				'محصول مادر هنوز ساخته نشده است؛ پردازش تنوع‌ها برای تلاش بعدی به تعویق افتاد.',
				array(
					'deleteFile'       => false,
					'deferSeconds'     => 120,
					'waitingForParent' => true,
					'productGuid'      => $product_guid,
				)
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return $this->result( false, 'Invalid parent product.' );
		}

		/* Under the shared product lock, reject a Manual/Repair variant page if
		 * the foreground webhook already committed a newer parent generation. */
		if ( array_key_exists( '_moboManualParentSnapshotUs', $payload )
			&& absint( get_post_meta( $product_id, '_mobo_last_webhook_applied_us', true ) ) > absint( $payload['_moboManualParentSnapshotUs'] ) ) {
			return $this->result( false, 'Manual variant page was superseded by a foreground webhook.', array( 'manualSnapshotStale' => true, 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
		}

		$variant_list_authoritative = $this->is_authoritative_variant_list_payload( $payload, $variants );
		$source_version             = $this->extract_source_version( $payload, $payload );
		$source_revision            = $this->extract_source_revision( $payload, $payload );

		$attribute_inspection       = Mobo_Core_Payload_Field_Policy::inspect( $payload, Mobo_Core_Payload_Field_Policy::attribute_aliases() );
		$desired_attributes_present = ! empty( $attribute_inspection['present'] );
		$desired_attributes_valid   = ! $desired_attributes_present || is_array( $attribute_inspection['value'] );
		$desired_attributes         = $desired_attributes_present && is_array( $attribute_inspection['value'] ) ? $attribute_inspection['value'] : array();

		/*
		 * A destructive topology snapshot requires explicit, well-formed evidence for
		 * both collections. Missing attributes preserves topology; malformed attributes
		 * or malformed/absent snapshot data fail closed before any watermark/mutation.
		 */
		if ( $variant_list_authoritative && ( ! $variant_collection_present || ! $variant_collection_is_list ) ) {
			return $this->result( false, 'Authoritative Variant snapshot data is absent or malformed; topology mutation was blocked.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
		}
		if ( $variant_list_authoritative && $desired_attributes_present && ! $desired_attributes_valid ) {
			return $this->result( false, 'Authoritative Variant attributes are malformed; topology mutation was blocked.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
		}
		$topology_authoritative = $variant_list_authoritative && $variant_collection_is_list && $desired_attributes_present && $desired_attributes_valid;

		/* Validate every parent ordering fence before persisting either watermark. */
		if ( $variant_list_authoritative ) {
			$seen_event_version = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_variant_seen_event_version', true ) );
			$seen_revision      = absint( get_post_meta( $product_id, '_mobo_variant_seen_revision', true ) );
			$version_cmp        = '' !== $source_version ? $this->compare_source_versions( $source_version, $seen_event_version ) : null;
			if ( '' !== $source_version && '' !== $seen_event_version && null === $version_cmp && $source_version !== $seen_event_version ) {
				return $this->result( false, 'Authoritative UpdateVariant ordering watermark is not comparable with the durable variant watermark; topology mutation was blocked.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $product_id, 'sourceVersion' => $source_version, 'orderingConflict' => true, 'nonRetryable' => true ) );
			}
			if ( '' !== $source_version && -1 === $version_cmp ) {
				return $this->result( true, 'Stale authoritative UpdateVariant event version skipped.', array( 'deleteFile' => true, 'productGuid' => $product_guid, 'productId' => $product_id, 'sourceRevision' => $source_revision, 'sourceVersion' => $source_version, 'staleSkipped' => true ) );
			}
			if ( $source_revision > 0 && $seen_revision > $source_revision ) {
				return $this->result( true, 'Stale authoritative UpdateVariant revision skipped.', array( 'deleteFile' => true, 'productGuid' => $product_guid, 'productId' => $product_id, 'sourceRevision' => $source_revision, 'staleSkipped' => true ) );
			}
			if ( '' !== $source_version && ( null !== $version_cmp || '' === $seen_event_version ) && ! $this->persist_post_meta_verified( $product_id, '_mobo_variant_seen_event_version', $source_version ) ) {
				return $this->result( false, 'Variant snapshot event-version watermark could not be persisted; mutation was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			if ( $source_revision > 0 && ! $this->persist_post_meta_verified( $product_id, '_mobo_variant_seen_revision', $source_revision ) ) {
				return $this->result( false, 'Variant snapshot revision watermark could not be persisted; mutation was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
		}
		if ( $topology_authoritative ) {
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' ) || ! $this->upsert_product_map( $product_guid, $product_id, true ) ) {
				return $this->result( false, 'Authoritative Variant topology crash marker could not be persisted before mutation.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
		}
		$desired_attribute_count = count( $this->build_product_attributes( $desired_attributes ) );
		if ( $topology_authoritative && 0 === $desired_attribute_count ) {
			$preflight_simple = $this->preflight_simple_variation_cleanup( $product_id );
			if ( is_wp_error( $preflight_simple ) ) {
				return $this->result( false, $preflight_simple->get_error_message(), array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
		}
		$variant_total = max( count( $variants ), absint( $this->get_value( $payload, 'totalCount', count( $variants ) ) ) );

		$is_last_page = null === $is_last_page ? ! $this->to_bool( $has_more ) : $this->to_bool( $is_last_page );

		/*
		 * Multi-page authoritative snapshots should not purge/warm the same product
		 * after every intermediate variants page. The final page performs one parent
		 * sync and one targeted cache invalidation for the converged storefront state.
		 */
		if ( $variant_list_authoritative && ! $is_last_page && class_exists( 'Mobo_Core_Cache_Purger' ) && method_exists( 'Mobo_Core_Cache_Purger', 'suppress_product_for_request' ) ) {
			Mobo_Core_Cache_Purger::suppress_product_for_request( $product_id );
		}

		$parent_mutated = false;

		if ( $topology_authoritative ) {
			$structure_changed = $this->product_attribute_structure_changed( $product, $desired_attributes );

			if ( $structure_changed ) {
				/* Preserve old children until this authoritative snapshot reaches its
				 * validated terminal page. finalize_missing_variants() then deletes only
				 * children absent from the complete replacement set. */
				update_post_meta( $product_id, '_mobo_desired_state_rebuild_pending', '1' );
				update_post_meta( $product_id, '_mobo_desired_state_rebuild_requested_at', gmdate( 'c' ) );
				$parent_mutated = true;
			}

			if ( $this->apply_desired_product_attributes( $product_id, $desired_attributes ) ) {
				$parent_mutated = true;
			}
			$product = wc_get_product( $product_id );
		}

		/*
		 * A simple WooCommerce product maps its one Mobo storefront Variant onto
		 * the parent product itself. An authoritative snapshot with no variation
		 * attributes is also allowed to convert an old variable product back to simple.
		 */
		$authoritative_simple_snapshot = $topology_authoritative
			&& 0 === $desired_attribute_count;

		if ( 1 === count( $variants ) && $variant_total <= 1 && ! $this->variant_has_selection_attributes( $variants[0] ) && ( $authoritative_simple_snapshot || $product instanceof WC_Product_Simple || '1' === (string) get_post_meta( $product_id, '_mobo_simple_variant_mapped', true ) ) ) {
			if ( ! $variant_list_authoritative && ( '' !== $source_version || $source_revision > 0 ) ) {
				$simple_seen_version  = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_variant_seen_event_version', true ) );
				$simple_seen_revision = absint( get_post_meta( $product_id, '_mobo_variant_seen_revision', true ) );
				$simple_version_cmp   = '' !== $source_version ? $this->compare_source_versions( $source_version, $simple_seen_version ) : null;
				if ( '' !== $source_version && '' !== $simple_seen_version && null === $simple_version_cmp && $source_version !== $simple_seen_version ) {
					return $this->result( false, 'Simple UpdateVariant ordering watermark is not comparable with the durable variant watermark; mutation was blocked.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $product_id, 'sourceVersion' => $source_version, 'orderingConflict' => true, 'nonRetryable' => true ) );
				}
				if ( '' !== $source_version && -1 === $simple_version_cmp ) {
					return $this->result( true, 'Stale simple UpdateVariant event version skipped.', array( 'deleteFile' => true, 'productGuid' => $product_guid, 'productId' => $product_id, 'sourceRevision' => $source_revision, 'sourceVersion' => $source_version, 'staleSkipped' => true ) );
				}
				if ( $source_revision > 0 && $simple_seen_revision > $source_revision ) {
					return $this->result( true, 'Stale simple UpdateVariant revision skipped.', array( 'deleteFile' => true, 'productGuid' => $product_guid, 'productId' => $product_id, 'sourceRevision' => $source_revision, 'staleSkipped' => true ) );
				}
				if ( '' !== $source_version && ( null !== $simple_version_cmp || '' === $simple_seen_version ) && ! $this->persist_post_meta_verified( $product_id, '_mobo_variant_seen_event_version', $source_version ) ) {
					return $this->result( false, 'Simple-variant event-version watermark could not be persisted; mutation was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
				}
				if ( $source_revision > 0 && ! $this->persist_post_meta_verified( $product_id, '_mobo_variant_seen_revision', $source_revision ) ) {
					return $this->result( false, 'Simple-variant revision watermark could not be persisted; mutation was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
				}
			}


			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' ) ) {
				return $this->result( false, 'Simple-product crash marker could not be persisted before mutation.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			if ( ! $this->upsert_product_map( $product_guid, $product_id, true ) ) {
				return $this->result( false, 'ثبت Product Map پیش از تغییر محصول ساده ناموفق بود.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			$simple_result = $this->apply_simple_variant_to_product( $product_id, $variants[0], $product_guid );

			if ( is_wp_error( $simple_result ) ) {
				return $this->result( false, $simple_result->get_error_message(), array( 'deleteFile' => false, 'productGuid' => $product_guid ) );
			}

			if ( $authoritative_simple_snapshot ) {
				if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
					$mapping_error = '';
					$this->product_map->delete_variations_for_parent( $product_guid, array(), $mapping_error );
					if ( '' !== $mapping_error ) {
						return $this->result(
							false,
							'پاک‌سازی نگاشت تنوع‌های قدیمی پس از تبدیل محصول به ساده ناموفق بود و در اجرای بعد دوباره تلاش می‌شود.',
							array( 'deleteFile' => false, 'productGuid' => $product_guid, 'mappingError' => $mapping_error )
						);
					}
				}
				delete_post_meta( $product_id, '_mobo_desired_state_rebuild_pending' );
				delete_post_meta( $product_id, '_mobo_missing_variants_finalize_reason' );
				delete_post_meta( $product_id, '_mobo_missing_variants_seen_count' );
				delete_post_meta( $product_id, '_mobo_missing_variants_expected_count' );
				update_post_meta( $product_id, '_mobo_desired_state_last_completed_at', gmdate( 'c' ) );
				if ( ! $this->clear_seen_variants( $product_guid, $sync_id ) ) {
					return $this->result( false, 'Authoritative variation seen-set could not be cleared durably after finalization; event will retry idempotently.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
				}
			}

			if ( ( '' !== $source_version || $source_revision > 0 ) && ! $this->persist_ordering_watermarks( $product_id, 'variant', $source_version, $source_revision, true ) ) {
				$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
				return $this->result( false, 'Simple-variant ordering watermark could not be committed after mutation; event will retry.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			if ( ! $this->upsert_product_map( $product_guid, $product_id, false ) ) {
				$this->update_post_meta_if_changed( $product_id, 'mobo_sync_incomplete', '1' );
				return $this->result( false, 'ثبت نهایی Product Map محصول ساده ناموفق بود.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '0' ) ) {
				return $this->result( false, 'Simple-product completion marker could not be persisted; event will retry.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			$this->release_awaiting_variants( $product_id );

			if ( ! empty( $payload['_moboWebhookForegroundContext'] ) && ! $this->mark_webhook_product_applied( $product_id ) ) {
				$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
				$this->upsert_product_map( $product_guid, $product_id, true );
				return $this->result( false, 'Simple-product webhook ordering fence could not be persisted; event will retry.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $product_id, 'retryable' => true ) );
			}

			return $this->result(
				true,
				'شناسه قابل خرید محصول ساده بروزرسانی شد.',
				array(
					'deleteFile'       => true,
					'productGuid'      => $product_guid,
					'simpleProduct'    => true,
					'portalVariantId'  => isset( $simple_result['portalVariantId'] ) ? absint( $simple_result['portalVariantId'] ) : 0,
				)
			);
		}

		/* A delta can arrive after a newer delta/snapshot has already converged.
		 * Prove that the entire page is stale before touching parent topology;
		 * otherwise an old delta could convert a newer simple parent back to
		 * variable even though every child mutation is subsequently skipped. */
		if ( ! $variant_list_authoritative && $this->delta_variant_payload_is_fully_stale( $product_id, $variants, $source_version, $source_revision ) ) {
			return $this->result(
				true,
				'Stale UpdateVariant delta skipped before parent mutation.',
				array(
					'deleteFile'     => true,
					'productGuid'    => $product_guid,
					'productId'      => $product_id,
					'sourceRevision' => $source_revision,
					'sourceVersion'  => $source_version,
					'staleSkipped'   => true,
				)
			);
		}

		if ( ! $variant_list_authoritative ) {
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' ) || ! $this->upsert_product_map( $product_guid, $product_id, true ) ) {
				return $this->result( false, 'Variant delta crash marker could not be persisted before mutation.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
		}

		/*
		 * Delta variant webhooks are coalesced at the parent level. Suppress the
		 * WooCommerce CRUD purge generated by each child save; the parent-finalize
		 * queue will perform one recalculation and one targeted purge after this
		 * webhook batch. Authoritative snapshots keep their existing final-page rule.
		 */
		if ( ! $variant_list_authoritative && class_exists( 'Mobo_Core_Cache_Purger' ) && method_exists( 'Mobo_Core_Cache_Purger', 'suppress_product_for_request' ) ) {
			Mobo_Core_Cache_Purger::suppress_product_for_request( $product_id );
		}

		if ( $topology_authoritative && ! $this->initialize_seen_variants( $product_guid, $sync_id ) ) {
			return $this->result( false, 'Could not durably initialize the authoritative variation seen-set; snapshot finalization was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
		}

		$before_product_type = $product instanceof WC_Product ? (string) $product->get_type() : '';
		$type_result = ( $variant_list_authoritative && ! $topology_authoritative )
			? $product
			: $this->ensure_product_type_for_variants( $product_id, ( $variant_total > 0 || $desired_attribute_count > 0 ) ? max( 1, $variant_total ) : 0 );
		if ( is_wp_error( $type_result ) ) {
			return $this->result( false, $type_result->get_error_message(), array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
		}

		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product && $before_product_type !== (string) $product->get_type() ) {
			$parent_mutated = true;
		}

		if ( $topology_authoritative && 0 === $desired_attribute_count && 0 === $variant_total && empty( $variants ) ) {
			$empty_simple = $this->apply_authoritative_simple_without_purchase_variant( $product_id, $product_guid );
			if ( is_wp_error( $empty_simple ) ) {
				return $this->result( false, $empty_simple->get_error_message(), array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			$product = wc_get_product( $product_id );
			$parent_mutated = true;
		}

		/* Prime GUID->ID, post and meta caches once for this variant page. */
		$this->prime_variation_page_context( $product_id, $variants );

		$updated            = 0;
		$unchanged          = 0;
		$skipped            = 0;
		$stale_skipped      = 0;
		$seen_variant_guids = array();
		$last_lock_renewal  = time();

		foreach ( $variants as $variant_data ) {
			/*
			 * Very large/slow variant pages may outlive the initial runtime lease.
			 * MySQL GET_LOCK still serializes writers, but the runtime heartbeat is
			 * what the upgrade barrier can observe. Renew at a coarse interval so a
			 * live mutation cannot become invisible during a deploy drain.
			 */
			if ( false !== $product_lock && ( time() - $last_lock_renewal ) >= 45 && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
				if ( ! Mobo_Core_Product_Concurrency::renew_product_lock( $product_lock, 180 ) ) {
					return Mobo_Core_Product_Concurrency::defer_result( $product_guid, 'product_lock_lease_lost', 45 );
				}
				$last_lock_renewal = time();
			}

			if ( ! is_array( $variant_data ) ) {
				$skipped++;
				continue;
			}

			$incoming_variant_guid = $this->extract_variant_guid( $variant_data );
			if ( ! $variant_list_authoritative && ( '' !== $source_version || $source_revision > 0 ) && '' !== $incoming_variant_guid ) {
				$existing_variation_id = $this->find_variation_id_by_guid( $incoming_variant_guid );
				if ( $existing_variation_id > 0 && absint( wp_get_post_parent_id( $existing_variation_id ) ) === $product_id ) {
					$variation_seen_version  = sanitize_text_field( (string) get_post_meta( $existing_variation_id, '_mobo_variant_seen_event_version', true ) );
					$variation_seen_revision = absint( get_post_meta( $existing_variation_id, '_mobo_variant_seen_revision', true ) );
					$variation_version_cmp   = '' !== $source_version ? $this->compare_source_versions( $source_version, $variation_seen_version ) : null;
					if ( '' !== $source_version && '' !== $variation_seen_version && null === $variation_version_cmp && $source_version !== $variation_seen_version ) {
						return $this->result( false, 'Variation ordering watermark is not comparable with the durable variation watermark; mutation was blocked.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $product_id, 'sourceVersion' => $source_version, 'orderingConflict' => true, 'nonRetryable' => true ) );
					}
					if ( ( '' !== $source_version && -1 === $variation_version_cmp ) || ( $source_revision > 0 && $variation_seen_revision > $source_revision ) ) {
						$stale_skipped++;
						$unchanged++;
						continue;
					}
					if ( '' !== $source_version && ( null !== $variation_version_cmp || '' === $variation_seen_version ) && ! $this->persist_post_meta_verified( $existing_variation_id, '_mobo_variant_seen_event_version', $source_version ) ) {
						return $this->result( false, 'Variation event-version watermark could not be persisted before mutation.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
					}
					if ( $source_revision > 0 && ! $this->persist_post_meta_verified( $existing_variation_id, '_mobo_variant_seen_revision', $source_revision ) ) {
						return $this->result( false, 'Variation revision watermark could not be persisted before mutation.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
					}
				}
			}


			$variation_result = $this->upsert_variation( $product, $variant_data, $product_guid );
			$variation_id     = is_array( $variation_result ) && isset( $variation_result['id'] ) ? absint( $variation_result['id'] ) : absint( $variation_result );

			if ( $variation_id > 0 ) {
				$variant_guid = $this->extract_variant_guid( $variant_data );
				$authoritative_attrs = $this->normalize_variation_attributes( $this->get_value( $variant_data, 'attributes', array() ) );
				if ( ! empty( $authoritative_attrs ) && ! $this->persist_variation_attributes_exact( $variation_id, $authoritative_attrs ) ) {
					$this->persist_post_meta_verified( $variation_id, 'mobo_sync_incomplete', '1' );
					return $this->result( false, 'Exact variation storefront attributes could not be durably converged after mutation.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
				}

				/* Every successful child mutation, including authoritative snapshots, must
				 * persist the child watermark. Otherwise a late pre-snapshot delta can pass
				 * the per-variation stale guard and overwrite the newer snapshot state. */
				if ( ( '' !== $source_version || $source_revision > 0 ) && ! $this->persist_ordering_watermarks( $variation_id, 'variant', $source_version, $source_revision, true ) ) {
					$this->persist_post_meta_verified( $variation_id, 'mobo_sync_incomplete', '1' );
					if ( '' !== $variant_guid ) {
						$this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, true );
					}
					return $this->result( false, 'Variation ordering watermark could not be committed after mutation; event will retry.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
				}

				if ( $topology_authoritative && '' !== $variant_guid ) {
					$seen_variant_guids[ $variant_guid ] = true;
				}

				if ( is_array( $variation_result ) && empty( $variation_result['changed'] ) ) {
					$unchanged++;
				} else {
					$updated++;
				}
			} else {
				$intentional_trash_skip = is_array( $variation_result ) && ! empty( $variation_result['skipped_trash'] );
				if ( $intentional_trash_skip ) {
					/* A locally trashed Mobo variation is an explicit retention policy, not an
					 * incomplete source page. Count its remote identity as seen so an
					 * authoritative snapshot can finish without restoring it or retrying forever. */
					if ( $topology_authoritative && '' !== $incoming_variant_guid ) {
						$seen_variant_guids[ $incoming_variant_guid ] = true;
					}
					$unchanged++;
				} else {
					$skipped++;
				}
			}
		}

		if ( $topology_authoritative && ! empty( $seen_variant_guids ) && ! $this->mark_variants_seen_bulk( $product_guid, $sync_id, array_keys( $seen_variant_guids ) ) ) {
			return $this->result( false, 'Could not durably persist the authoritative variation seen-set; destructive finalization was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
		}

		$deleted_missing_variants          = 0;
		$authoritative_snapshot_incomplete = false;

		if ( $topology_authoritative ) {
			$expected_variant_total = absint( $this->get_value( $payload, 'totalCount', 0 ) );
			$seen_variant_count     = $this->count_seen_variants( $product_guid, $sync_id );
			if ( $seen_variant_count < 0 ) {
				return $this->result( false, 'Authoritative variation seen-set could not be read back; snapshot finalization was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			$snapshot_complete      = ( 0 === $expected_variant_total && $is_last_page )
				|| ( $expected_variant_total > 0 && $seen_variant_count >= $expected_variant_total );

			if ( $snapshot_complete ) {
				$finalize_result = $this->finalize_missing_variants( $product, $product_guid, $sync_id );
				if ( is_wp_error( $finalize_result ) ) {
					update_post_meta( $product_id, '_mobo_missing_variants_finalize_skipped_at', gmdate( 'c' ) );
					update_post_meta( $product_id, '_mobo_missing_variants_finalize_reason', 'local_variation_delete_failed' );
					return $this->result( false, $finalize_result->get_error_message(), array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
				}
				$deleted_missing_variants = absint( $finalize_result );
				delete_post_meta( $product_id, '_mobo_desired_state_rebuild_pending' );
				delete_post_meta( $product_id, '_mobo_missing_variants_finalize_reason' );
				delete_post_meta( $product_id, '_mobo_missing_variants_seen_count' );
				delete_post_meta( $product_id, '_mobo_missing_variants_expected_count' );
				update_post_meta( $product_id, '_mobo_desired_state_last_completed_at', gmdate( 'c' ) );
				if ( ! $this->clear_seen_variants( $product_guid, $sync_id ) ) {
					return $this->result( false, 'Authoritative variation seen-set could not be cleared durably after finalization; event will retry idempotently.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
				}
			} elseif ( $is_last_page ) {
				$authoritative_snapshot_incomplete = true;
				/*
				 * Keep the accumulated set. A delayed earlier page from the same sync can
				 * still complete the snapshot and safely trigger finalization.
				 */
				update_post_meta( $product_id, '_mobo_missing_variants_finalize_skipped_at', gmdate( 'c' ) );
				update_post_meta( $product_id, '_mobo_missing_variants_finalize_reason', 'authoritative_snapshot_incomplete' );
				update_post_meta( $product_id, '_mobo_missing_variants_seen_count', $seen_variant_count );
				update_post_meta( $product_id, '_mobo_missing_variants_expected_count', $expected_variant_total );
			}
		} elseif ( $is_last_page ) {
			update_post_meta( $product_id, '_mobo_missing_variants_finalize_skipped_at', gmdate( 'c' ) );
			update_post_meta( $product_id, '_mobo_missing_variants_finalize_reason', 'variant_delta_webhook_not_authoritative' );
			update_post_meta( $product_id, '_mobo_missing_variants_payload_event', sanitize_text_field( (string) $this->get_value( $payload, 'event', '' ) ) );
		}

		if ( $parent_mutated || $updated > 0 || $deleted_missing_variants > 0 ) {
			if ( ! $this->persist_post_meta_verified( $product_id, '_mobo_parent_sync_pending', '1' ) ) {
				$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
				$this->upsert_product_map( $product_guid, $product_id, true );
				return $this->result( false, 'Parent-finalize checkpoint could not be persisted after Variant mutation; event will retry.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $product_id, 'retryable' => true ) );
			}
		}

		$parent_sync_performed = false;
		$parent_sync_deferred  = false;
		$parent_sync_pending   = '1' === sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_parent_sync_pending', true ) );

		if ( $is_last_page && $parent_sync_pending ) {
			if ( $variant_list_authoritative && is_callable( array( 'WC_Product_Variable', 'sync' ) ) ) {
				WC_Product_Variable::sync( $product_id );
				delete_post_meta( $product_id, '_mobo_parent_sync_pending' );
				wc_delete_product_transients( $product_id );
				if ( class_exists( 'Mobo_Core_Cache_Purger' ) ) {
					Mobo_Core_Cache_Purger::queue_product( $product_id, 'variant-snapshot-converged' );
				}
				$parent_sync_performed = true;
			} elseif ( ! $variant_list_authoritative && class_exists( 'Mobo_Core_Parent_Finalize_Queue' ) ) {
				$queued = Mobo_Core_Parent_Finalize_Queue::enqueue( $product_id, $product_guid, 'variant-delta' );
				$parent_sync_deferred = is_array( $queued ) && ! empty( $queued['success'] );

				/*
				 * A transient option-lock failure must not leave the parent permanently
				 * dirty with this request's cache purge suppressed. Fall back to the old
				 * immediate convergence path only when enqueue itself failed.
				 */
				if ( ! $parent_sync_deferred && is_callable( array( 'WC_Product_Variable', 'sync' ) ) ) {
					WC_Product_Variable::sync( $product_id );
					delete_post_meta( $product_id, '_mobo_parent_sync_pending' );
					wc_delete_product_transients( $product_id );
					if ( class_exists( 'Mobo_Core_Cache_Purger' ) ) {
						if ( method_exists( 'Mobo_Core_Cache_Purger', 'unsuppress_product_for_request' ) ) {
							Mobo_Core_Cache_Purger::unsuppress_product_for_request( $product_id );
						}
						Mobo_Core_Cache_Purger::queue_product( $product_id, 'variant-delta-converged-fallback' );
					}
					$parent_sync_performed = true;
				}
			} elseif ( is_callable( array( 'WC_Product_Variable', 'sync' ) ) ) {
				/* Compatibility fallback if the coalescing queue is unavailable. */
				WC_Product_Variable::sync( $product_id );
				delete_post_meta( $product_id, '_mobo_parent_sync_pending' );
				wc_delete_product_transients( $product_id );
				if ( class_exists( 'Mobo_Core_Cache_Purger' ) ) {
					if ( method_exists( 'Mobo_Core_Cache_Purger', 'unsuppress_product_for_request' ) ) {
						Mobo_Core_Cache_Purger::unsuppress_product_for_request( $product_id );
					}
					Mobo_Core_Cache_Purger::queue_product( $product_id, 'variant-delta-converged' );
				}
				$parent_sync_performed = true;
			}
		}

		if ( ! empty( $payload['_moboWebhookForegroundContext'] ) && ! $this->mark_webhook_product_applied( $product_id ) ) {
			$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
			$this->upsert_product_map( $product_guid, $product_id, true );
			return $this->result( false, 'Variant webhook ordering fence could not be persisted; event will retry idempotently.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $product_id, 'retryable' => true ) );
		}

		if ( $skipped > 0 || $authoritative_snapshot_incomplete ) {
			$this->update_post_meta_if_changed( $product_id, 'mobo_sync_incomplete', '1' );
			$this->upsert_product_map( $product_guid, $product_id, true );
			return $this->result(
				true,
				'تنوع‌ها هنوز کامل همگام نشده‌اند و event برای retry حفظ شد.',
				array(
					'deleteFile' => false,
					'deferSeconds' => 60,
					'productGuid' => $product_guid,
					'pageNumber' => $page_number,
					'skipped' => $skipped,
					'authoritativeSnapshotIncomplete' => $authoritative_snapshot_incomplete,
				)
			);
		}

		if ( $is_last_page ) {
			/* Ordering evidence is part of the completion commit. Persist it before
			 * advertising a clean Product Map / crash marker so a process death can
			 * never acknowledge a snapshot whose stale guard was not durable. */
			if ( $variant_list_authoritative && ( '' !== $source_version || $source_revision > 0 ) && ! $this->persist_ordering_watermarks( $product_id, 'variant', $source_version, $source_revision, true ) ) {
				$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
				$this->upsert_product_map( $product_guid, $product_id, true );
				return $this->result( false, 'Authoritative Variant ordering watermark could not be committed; event will retry.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			if ( ! $this->upsert_product_map( $product_guid, $product_id, false ) ) {
				$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
				return $this->result( false, 'ثبت نهایی Product Map پس از Variant Sync ناموفق بود.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '0' ) ) {
				$this->upsert_product_map( $product_guid, $product_id, true );
				return $this->result( false, 'ثبت نهایی completion marker پس از Variant Sync ناموفق بود.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			$this->release_awaiting_variants( $product_id );
		}

		$message = sprintf(
			'تنوع‌های محصول پردازش شد. محصول: %s، صفحه: %d، بروزرسانی: %d، بدون تغییر: %d، رد شده: %d، stale: %d',
			$product_guid,
			$page_number,
			$updated,
			$unchanged,
			$skipped,
			$stale_skipped
		);

		return $this->result(
			true,
			$message,
			array(
				'deleteFile'  => true,
				'productGuid' => $product_guid,
				'pageNumber'  => $page_number,
				'updated'     => $updated,
				'unchanged'   => $unchanged,
				'skipped'              => $skipped,
				'staleSkipped'         => $stale_skipped,
				'sourceRevision'       => $source_revision,
				'sourceVersion'        => $source_version,
				'isLastPage'           => $is_last_page,
				'parentSyncPerformed'  => $parent_sync_performed,
				'parentSyncDeferred'   => $parent_sync_deferred || ( ! $is_last_page && ( $parent_mutated || $updated > 0 ) ),
				'deletedMissingVariants' => absint( $deleted_missing_variants ),
			)
		);
	}


	/**
	 * Fetch remote categories only for admin mapping.
	 *
	 * This method never creates or updates WooCommerce product_cat terms and
	 * does not respect/change the automatic category update schedule.
	 *
	 * @param string $sync_id Optional sync ID.
	 * @return array
	 */
	public function preload_categories_for_mapping( $sync_id = '' ) {
		$sync_id = sanitize_text_field( (string) $sync_id );

		if ( '' === $sync_id ) {
			$sync_id = 'category-mapping-' . gmdate( 'YmdHis' );
		}

		$api      = new Mobo_Core_API_Client();
		$category_snapshot_started_us = (int) floor( microtime( true ) * 1000000 );
		$response = $api->get_categories( $sync_id );

		if ( is_wp_error( $response ) ) {
			return $this->result(
				false,
				$response->get_error_message(),
				array(
					'skipped' => false,
					'synced'  => false,
				)
			);
		}

		$categories = $this->normalize_categories_api_response( $response );
		if ( is_wp_error( $categories ) ) {
			return $this->result( false, $categories->get_error_message(), array( 'skipped' => false, 'synced' => false ) );
		}

		$category_result = $this->category_sync->load_categories_for_mapping_payload( $categories, $category_snapshot_started_us );

		if ( empty( $category_result['complete'] ) || absint( $category_result['skipped'] ) > 0 ) {
			return $this->result(
				false,
				sprintf( 'Snapshot دسته‌بندی برای نگاشت ناقص بود؛ %d ردیف نامعتبر یا حل‌نشده بود و زمان موفقیت جلو نرفت.', absint( $category_result['skipped'] ) ),
				array(
					'skipped'      => false,
					'synced'       => false,
					'mappingOnly'  => true,
					'created'      => absint( $category_result['created'] ),
					'updated'      => absint( $category_result['updated'] ),
					'active'       => absint( $category_result['active'] ?? 0 ),
					'skippedItems' => absint( $category_result['skipped'] ),
				)
			);
		}

		Mobo_Core_Settings::update( 'mobo_core_categories_mapping_loaded_at', time(), false );

		return $this->result(
			true,
			'دسته‌بندی‌ها فقط برای نگاشت لود شدند. هیچ دسته‌ای در ووکامرس ساخته یا بروزرسانی نشد.',
			array(
				'skipped'      => false,
				'synced'       => true,
				'mappingOnly'  => true,
				'created'      => absint( $category_result['created'] ),
				'updated'      => absint( $category_result['updated'] ),
				'active'       => absint( $category_result['active'] ?? 0 ),
				'skippedItems' => absint( $category_result['skipped'] ),
			)
		);
	}

	/**
	 * Ensure categories are synced if refresh interval has passed.
	 *
	 * This is intended to be called by C# through REST.
	 * It does not rely on WP-Cron.
	 *
	 * @param string $sync_id Optional sync ID.
	 * @param bool   $force Force sync.
	 * @return array
	 */
	public function ensure_categories_synced_if_due( $sync_id = '', $force = false ) {
		/* A product that could not resolve its Source category asked for an early
		 * refresh (Mobo_Core_Category_Sync::request_category_sync). Honour it at
		 * most every 5 minutes so products waiting on a temporary category get the
		 * correct one soon instead of after the 12h interval. */
		$early_request = array();
		if ( class_exists( 'Mobo_Core_Category_Sync' ) ) {
			$early_request = Mobo_Core_Category_Sync::get_category_sync_request();
		}
		$early_at = absint( $early_request['at'] ?? 0 );
		$early_last = max( absint( Mobo_Core_Settings::get( 'mobo_core_categories_last_sync_at', 0 ) ), absint( Mobo_Core_Settings::get( 'mobo_core_categories_mapping_loaded_at', 0 ) ) );
		$early_attempt = absint( get_option( 'mobo_core_category_early_refresh_attempt_at', 0 ) );
		$early_due = $early_at > 0 && $early_at > $early_last && ( time() - $early_last ) >= 300 && ( time() - $early_attempt ) >= 300;
		if ( $early_due ) {
			update_option( 'mobo_core_category_early_refresh_attempt_at', time(), false );
		}

		if ( ! $force && ! $this->rules->should_update_categories() && $early_due && Mobo_Core_Settings::enabled( 'mobo_core_category_mapping_enabled', '1' ) ) {
			/* Automatic categories off: refresh only the mapping list so the merchant
			 * can map the new Source category (no WooCommerce term is created). */
			return $this->preload_categories_for_mapping( $sync_id );
		}

		if ( ! $force && ! $this->rules->should_update_categories() ) {
			return $this->result(
				true,
				'آپدیت اتوماتیک دسته‌بندی‌ها غیرفعال است.',
				array(
					'skipped'  => true,
					'reason'   => 'disabled',
					'synced'   => false,
					'forced'   => (bool) $force,
				)
			);
		}

		$sync_id = sanitize_text_field( (string) $sync_id );

		if ( '' === $sync_id ) {
			$sync_id = 'category-refresh-' . gmdate( 'YmdHis' );
		}

		$last_sync_at = absint( Mobo_Core_Settings::get( 'mobo_core_categories_last_sync_at', 0 ) );

		$interval_hours = absint( Mobo_Core_Settings::get( 'mobo_core_categories_refresh_interval_hours', 12 ) );

		if ( $interval_hours <= 0 ) {
			$interval_hours = 12;
		}

		$interval_seconds = $interval_hours * HOUR_IN_SECONDS;
		$now              = time();

		if ( ! $force && ! $early_due && $last_sync_at > 0 && ( $now - $last_sync_at ) < $interval_seconds ) {
			return $this->result(
				true,
				'هنوز زمان بروزرسانی دوره‌ای دسته‌بندی‌ها نرسیده است.',
				array(
					'skipped'        => true,
					'reason'         => 'not-due',
					'synced'         => false,
					'forced'         => false,
					'lastSyncAt'     => $last_sync_at,
					'nextSyncAt'     => $last_sync_at + $interval_seconds,
					'intervalHours'  => $interval_hours,
				)
			);
		}

		$api      = new Mobo_Core_API_Client();
		$category_snapshot_started_us = (int) floor( microtime( true ) * 1000000 );
		$response = $api->get_categories( $sync_id );

		if ( is_wp_error( $response ) ) {
			return $this->result(
				false,
				$response->get_error_message(),
				array(
					'skipped' => false,
					'synced'  => false,
					'forced'  => (bool) $force,
				)
			);
		}

		$categories = $this->normalize_categories_api_response( $response );
		if ( is_wp_error( $categories ) ) {
			return $this->result( false, $categories->get_error_message(), array( 'skipped' => false, 'synced' => false, 'forced' => (bool) $force ) );
		}
		$category_result = $this->category_sync->sync_categories_payload( $categories, $category_snapshot_started_us );

		if ( empty( $category_result['complete'] ) || absint( $category_result['skipped'] ) > 0 ) {
			return $this->result(
				false,
				sprintf( 'Snapshot دسته‌بندی ناقص بود؛ %d ردیف نامعتبر یا حل‌نشده بود. زمان آخرین Sync موفق تغییر نکرد.', absint( $category_result['skipped'] ) ),
				array(
					'skipped'       => false,
					'synced'        => false,
					'forced'        => (bool) $force,
					'lastSyncAt'    => $last_sync_at,
					'intervalHours' => $interval_hours,
					'created'       => absint( $category_result['created'] ),
					'updated'       => absint( $category_result['updated'] ),
					'skippedItems'  => absint( $category_result['skipped'] ),
				)
			);
		}

		Mobo_Core_Settings::update( 'mobo_core_categories_last_sync_at', $now, false );

		return $this->result(
			true,
			'دسته‌بندی‌ها بروزرسانی شدند.',
			array(
				'skipped'       => false,
				'synced'        => true,
				'forced'        => (bool) $force,
				'lastSyncAt'    => $now,
				'intervalHours' => $interval_hours,
				'created'       => absint( $category_result['created'] ),
				'updated'       => absint( $category_result['updated'] ),
				'skippedItems'  => absint( $category_result['skipped'] ),
			)
		);
	}

	/**
	 * Recover an image for one existing local product during Repair.
	 *
	 * @param array $state Manual sync state.
	 * @return array
	 */
	private function run_missing_image_repair_step( &$state ) {
		if ( ! class_exists( 'Mobo_Core_Missing_Image_Recovery' ) ) {
			$state['missingImageRecoveryComplete'] = true;
			$state['status']                       = 'done';
			$state['completedAt']                  = time();
			$state['lastError']                    = '';
			$state['lastMessage']                  = 'Repair کامل شد؛ سرویس بازیابی تصویر در دسترس نبود.';
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		$recovery = new Mobo_Core_Missing_Image_Recovery();
		if ( ! $recovery->is_enabled() ) {
			$state['missingImageRecoveryComplete'] = true;
			$state['status']                       = 'done';
			$state['completedAt']                  = time();
			$state['lastError']                    = '';
			$state['lastMessage']                  = 'Repair کامل شد؛ بازیابی تصویر محصولات بدون عکس به دلیل غیرفعال بودن بروزرسانی تصاویر رد شد.';
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		$cursor = absint( $state['missingImageRecoveryCursor'] ?? 0 );
		$batch  = $recovery->get_candidate_batch( 1, $cursor );
		$rows   = isset( $batch['rows'] ) && is_array( $batch['rows'] ) ? $batch['rows'] : array();

		$state['missingImageRecoveryScanned'] = absint( $state['missingImageRecoveryScanned'] ?? 0 ) + absint( $batch['scanned'] ?? 0 );

		if ( empty( $rows ) ) {
			$state['missingImageRecoveryCursor'] = absint( $batch['cursorEnd'] ?? $cursor );
			$state['lastError']                  = '';

			if ( ! empty( $batch['cycleComplete'] ) ) {
				$state['missingImageRecoveryCursor']   = 0;
				$state['missingImageRecoveryComplete'] = true;
				$state['status']                       = 'done';
				$state['completedAt']                  = time();
				$state['lastMessage']                  = sprintf(
					'Repair کامل شد. محصولات محلی بررسی‌شده برای تصویر: %d، صف‌شده: %d، ردشده: %d، ناموفق: %d.',
					absint( $state['missingImageRecoveryScanned'] ?? 0 ),
					absint( $state['missingImageRecoveryQueued'] ?? 0 ),
					absint( $state['missingImageRecoverySkipped'] ?? 0 ),
					absint( $state['missingImageRecoveryFailed'] ?? 0 )
				);
			} else {
				$state['lastMessage'] = 'Repair محصولات اصلی کامل است؛ جست‌وجوی محصولات محلی بدون تصویر ادامه دارد.';
			}

			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}
			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		$row          = $rows[0];
		$product_id   = absint( $row['product_id'] ?? 0 );
		$product_guid = sanitize_text_field( (string) ( $row['product_guid'] ?? '' ) );
		$result       = $recovery->recover_product(
			$product_id,
			$product_guid,
			'repair-missing-image-' . sanitize_text_field( (string) $state['syncId'] )
		);

		if ( is_wp_error( $result ) ) {
			$state['missingImageRecoveryFailed'] = absint( $state['missingImageRecoveryFailed'] ?? 0 ) + 1;
			return $this->handle_transient_request_error( $state, $result, 'خطا در دریافت تصویر محصول محلی بدون عکس.' );
		}

		$this->clear_transient_request_error( $state );
		$state['missingImageRecoveryCursor'] = absint( $batch['cursorEnd'] ?? $product_id );

		if ( ! empty( $result['skipped'] ) ) {
			$state['missingImageRecoverySkipped'] = absint( $state['missingImageRecoverySkipped'] ?? 0 ) + 1;
		} else {
			$state['missingImageRecoveryQueued'] = absint( $state['missingImageRecoveryQueued'] ?? 0 ) + 1;
		}

		if ( ! empty( $batch['cycleComplete'] ) ) {
			$state['missingImageRecoveryCursor']   = 0;
			$state['missingImageRecoveryComplete'] = true;
			$state['status']                       = 'done';
			$state['completedAt']                  = time();
			$state['lastMessage']                  = sprintf(
				'Repair و بازیابی تصاویر کامل شد. محصولات بررسی‌شده: %d، صف‌شده: %d، ردشده: %d، ناموفق: %d.',
				absint( $state['missingImageRecoveryScanned'] ?? 0 ),
				absint( $state['missingImageRecoveryQueued'] ?? 0 ),
				absint( $state['missingImageRecoverySkipped'] ?? 0 ),
				absint( $state['missingImageRecoveryFailed'] ?? 0 )
			);
		} else {
			$state['lastMessage'] = sprintf(
				'تصویر محصول بدون عکس بررسی شد: %s. صف‌شده: %d، ردشده: %d.',
				$product_guid,
				absint( $state['missingImageRecoveryQueued'] ?? 0 ),
				absint( $state['missingImageRecoverySkipped'] ?? 0 )
			);
		}

		if ( ! $this->save_manual_sync_state( $state ) ) {
			return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
		}
		return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
	}

	/**
	 * Build the next bounded targeted-convergence window from a fresh owner-ignored
	 * Health view. Successful GUIDs disappear from the unowned view and are dropped
	 * from the attempted ledger; unresolved GUIDs stay carried but are never retried
	 * inside the same generation. The unresolved count is capped so a permanently
	 * missing upstream set cannot grow durable state or loop forever.
	 *
	 * @param array $post_rows Fresh unowned rows.
	 * @param array $attempted Previously attempted GUIDs.
	 * @param int   $unresolved_attempts Durable skipped/unresolved count.
	 * @return array
	 */
	private function build_targeted_convergence_window( $post_rows, $attempted, $unresolved_attempts ) {
		$post_rows = is_array( $post_rows ) ? $post_rows : array();
		$attempted = is_array( $attempted ) ? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $attempted ) ) ) ) : array();
		$attempted_index = array_fill_keys( $attempted, true );
		$post_unowned_guids = array();
		$new_targets = array();

		foreach ( $post_rows as $post_row ) {
			if ( ! is_array( $post_row ) ) { continue; }
			$post_guid = sanitize_text_field( (string) ( $post_row['productGuid'] ?? '' ) );
			$post_wp_id = absint( $post_row['wpProductId'] ?? ( $post_row['wpId'] ?? 0 ) );
			if ( '' === $post_guid || $post_wp_id <= 0 ) { continue; }
			$post_unowned_guids[ $post_guid ] = true;
			if ( isset( $attempted_index[ $post_guid ] ) ) { continue; }
			$new_targets[] = array(
				'productGuid'        => $post_guid,
				'wpProductId'        => $post_wp_id,
				'postIncomplete'     => ! empty( $post_row['postIncomplete'] ),
				'mapIncomplete'      => ! empty( $post_row['mapIncomplete'] ),
				'localStructureSane' => array_key_exists( 'localStructureSane', $post_row ) ? (bool) $post_row['localStructureSane'] : true,
			);
		}

		$carry_attempted = array();
		foreach ( $attempted as $attempted_guid ) {
			if ( isset( $post_unowned_guids[ $attempted_guid ] ) ) {
				$carry_attempted[] = $attempted_guid;
			}
		}
		$carry_attempted = array_slice(
			array_values( array_unique( $carry_attempted ) ),
			-self::TARGETED_CONVERGENCE_UNRESOLVED_LIMIT
		);

		$next_window = array();
		if ( absint( $unresolved_attempts ) < self::TARGETED_CONVERGENCE_UNRESOLVED_LIMIT && ! empty( $new_targets ) ) {
			$next_window = array_slice( $new_targets, 0, self::TARGETED_CONVERGENCE_WINDOW );
		}

		return array(
			'nextWindow'      => $next_window,
			'carryAttempted'  => $carry_attempted,
			'availableFresh'  => count( $new_targets ),
			'unresolvedBound' => absint( $unresolved_attempts ) >= self::TARGETED_CONVERGENCE_UNRESOLVED_LIMIT,
		);
	}

	/**
	 * Exact-fetch one existing local product that Operational Health classifies as
	 * an unowned convergence gap. This is a Repair-only bridge for catalogue-policy
	 * omissions such as OnlyInStock. It never discovers/imports a new product.
	 *
	 * Returning null means an exact snapshot was queued and the caller should fall
	 * through to the normal product mutation stages in the same step.
	 *
	 * @param array                $state Durable Repair state (by reference).
	 * @param Mobo_Core_API_Client $api API client.
	 * @return array|null
	 */
	private function run_convergence_gap_recovery_step( &$state, $api ) {
		if ( ! class_exists( 'Mobo_Core_Sync_Health' ) || ! method_exists( 'Mobo_Core_Sync_Health', 'get_operational_dashboard_status' ) ) {
			$state['convergenceRecoveryComplete']   = true;
			$state['convergenceRecoveryUnresolved'] = 0;
			$state['lastMessage'] = 'Exact convergence recovery skipped because Operational Health is unavailable.';
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint exact convergence recovery could not be persisted.', $this->get_manual_sync_status() );
			}
			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		$targeted_only = ! empty( $state['targetedConvergenceOnly'] );
		$operational = array();
		if ( $targeted_only ) {
			/* The target set was frozen before this generation became the recovery owner.
			 * Do not re-read the operational dashboard here: Health correctly classifies
			 * these GUIDs as active-owned while this state is running, so using that view
			 * as the worker input would make the worker hide its own work. */
			$rows = isset( $state['targetedConvergenceTargets'] ) && is_array( $state['targetedConvergenceTargets'] )
				? $state['targetedConvergenceTargets']
				: array();
		} else {
			/* Force a fresh targeted classification at this mutation boundary. The admin
			 * dashboard cache is intentionally long-lived and must not cause a just-healed
			 * GUID to be selected again or keep the unresolved count stale. */
			if ( class_exists( 'Mobo_Core_Health_Read_Cache' ) && method_exists( 'Mobo_Core_Health_Read_Cache', 'refresh_sync_health_snapshot' ) ) {
				Mobo_Core_Health_Read_Cache::refresh_sync_health_snapshot( 500 );
			}
			$dashboard = Mobo_Core_Sync_Health::get_operational_dashboard_status( 500, false );
			if ( ! is_array( $dashboard ) || 'unavailable' === sanitize_key( (string) ( $dashboard['operationalStatus'] ?? '' ) ) ) {
				return $this->handle_transient_request_error( $state, new WP_Error( 'mobo_core_convergence_recovery_health_unavailable', 'Operational Health could not classify exact convergence recovery candidates.' ), 'Exact convergence recovery منتظر وضعیت پایدار Health است.' );
			}
			$operational = isset( $dashboard['operational'] ) && is_array( $dashboard['operational'] ) ? $dashboard['operational'] : array();
			$rows = isset( $dashboard['unownedRows'] ) && is_array( $dashboard['unownedRows'] ) ? $dashboard['unownedRows'] : array();
		}
		$attempted = isset( $state['convergenceRecoveryAttempted'] ) && is_array( $state['convergenceRecoveryAttempted'] )
			? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $state['convergenceRecoveryAttempted'] ) ) ) )
			: array();
		$attempted_index = array_fill_keys( $attempted, true );
		$candidate = null;
		$cursor = $targeted_only ? min( count( $rows ), absint( $state['convergenceRecoveryCursor'] ?? 0 ) ) : 0;
		$row_count = count( $rows );
		for ( $row_index = $cursor; $row_index < $row_count; $row_index++ ) {
			$row = $rows[ $row_index ];
			if ( ! is_array( $row ) ) {
				if ( $targeted_only ) { $state['convergenceRecoveryCursor'] = $row_index + 1; }
				continue;
			}
			$guid = sanitize_text_field( (string) ( $row['productGuid'] ?? '' ) );
			$wp_id = absint( $row['wpProductId'] ?? 0 );
			if ( '' === $guid || $wp_id <= 0 ) {
				if ( $targeted_only ) { $state['convergenceRecoveryCursor'] = $row_index + 1; }
				continue;
			}
			if ( in_array( $guid, (array) ( $state['repairParentConflictGuids'] ?? array() ), true ) ) {
				/* A hard parent identity conflict is not a retryable convergence gap
				 * during the same Repair generation. Never loop over the same GUID. */
				if ( $targeted_only ) { $state['convergenceRecoveryCursor'] = $row_index + 1; }
				continue;
			}
			if ( isset( $attempted_index[ $guid ] ) ) {
				/* Older states may predate the durable targeted cursor. Fast-forward over
				 * already-finalized GUIDs without counting or reprocessing them. */
				if ( $targeted_only ) { $state['convergenceRecoveryCursor'] = $row_index + 1; }
				continue;
			}
			/* Never create a new product in this phase. The canonical live map row and the
			 * local Woo object must still resolve to the identity classified by Health. */
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $wp_id ) : false;
			if ( ! $product instanceof WC_Product || 'trash' === get_post_status( $wp_id ) ) {
				$state['convergenceRecoveryAttempted'][] = $guid;
				$attempted_index[ $guid ] = true;
				$state['convergenceRecoverySkipped'] = absint( $state['convergenceRecoverySkipped'] ?? 0 ) + 1;
				$state['convergenceRecoveryScanned'] = absint( $state['convergenceRecoveryScanned'] ?? 0 ) + 1;
				if ( $targeted_only ) { $state['convergenceRecoveryCursor'] = $row_index + 1; }
				continue;
			}
			$candidate = array(
				'guid'  => $guid,
				'wpId'  => $wp_id,
				'row'   => $row,
				'index' => $row_index,
			);
			break;
		}
		if ( ! is_array( $candidate ) ) {
			/* A targeted post-upgrade generation freezes its initial target set so an
			 * outage cannot lose ownership. That set is not necessarily the final set:
			 * reconnects/newer source boundaries can invalidate old terminal evidence
			 * while this generation is running. Before releasing the owner, classify
			 * current state again while explicitly ignoring this generation's own
			 * ownership. Adopt newly-unowned GUIDs into the same durable generation. */
			if ( $targeted_only ) {
				if ( class_exists( 'Mobo_Core_Health_Read_Cache' ) && method_exists( 'Mobo_Core_Health_Read_Cache', 'refresh_sync_health_snapshot' ) ) {
					Mobo_Core_Health_Read_Cache::refresh_sync_health_snapshot( self::TARGETED_CONVERGENCE_RECLASSIFY_LIMIT );
				}
				$post_dashboard = Mobo_Core_Sync_Health::get_operational_dashboard_status( self::TARGETED_CONVERGENCE_RECLASSIFY_LIMIT, false, true );
				if ( ! is_array( $post_dashboard ) || 'unavailable' === sanitize_key( (string) ( $post_dashboard['operationalStatus'] ?? '' ) ) ) {
					return $this->handle_transient_request_error( $state, new WP_Error( 'mobo_core_targeted_convergence_final_health_unavailable', 'Operational Health could not reclassify the targeted generation at completion.' ), 'Exact convergence recovery منتظر reclassification نهایی Health است.' );
				}

				$post_rows = isset( $post_dashboard['unownedRows'] ) && is_array( $post_dashboard['unownedRows'] )
					? $post_dashboard['unownedRows']
					: array();
				$window_plan = $this->build_targeted_convergence_window(
					$post_rows,
					$attempted,
					absint( $state['convergenceRecoverySkipped'] ?? 0 )
				);
				$state['convergenceRecoveryAttempted'] = $window_plan['carryAttempted'];

				if ( ! empty( $window_plan['nextWindow'] ) ) {
					$next_window = $window_plan['nextWindow'];
					$state['targetedConvergenceTargets'] = $next_window;
					$state['convergenceRecoveryCursor'] = 0;
					$state['convergenceRecoveryComplete'] = false;
					$state['convergenceRecoveryUnresolved'] = 0;
					$state['lastError'] = '';
					$state['lastMessage'] = sprintf(
						'Exact convergence recovery advanced to the next bounded window: %d target(s), %d actionable gap(s) currently owned.',
						count( $next_window ),
						absint( $post_dashboard['operational']['unownedConvergenceGap'] ?? count( $post_rows ) )
					);
					if ( ! $this->save_manual_sync_state( $state ) ) {
						return $this->result( false, 'Checkpoint expanded targeted convergence recovery could not be persisted.', $this->get_manual_sync_status() );
					}
					return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
				}

				$remaining = absint( $post_dashboard['operational']['unownedConvergenceGap'] ?? count( $post_rows ) );
			} else {
				$remaining = absint( $operational['unownedConvergenceGap'] ?? 0 );
			}

			$state['convergenceRecoveryComplete']   = true;
			$state['convergenceRecoveryUnresolved'] = $remaining;
			$state['lastError'] = '';
			$state['lastMessage'] = $remaining > 0
				? sprintf( 'Exact convergence recovery reached its safe boundary; %d product(s) remain unresolved.', $remaining )
				: 'Exact convergence recovery completed with no unresolved target.';
			if ( $targeted_only ) {
				$state['status']                         = 'done';
				$state['completedAt']                    = time();
				$state['targetedConvergenceOwnsUnowned'] = false;
			}
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint exact convergence recovery could not be persisted.', $this->get_manual_sync_status() );
			}
			if ( class_exists( 'Mobo_Core_Health_Read_Cache' ) && method_exists( 'Mobo_Core_Health_Read_Cache', 'refresh_sync_health_snapshot' ) ) {
				Mobo_Core_Health_Read_Cache::refresh_sync_health_snapshot( 500 );
			}
			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		$guid = (string) $candidate['guid'];
		$snapshot_us = $this->current_wall_clock_us();
		$response = $api->get_product_by_guid( $guid, $state['syncId'] );
		if ( is_wp_error( $response ) ) {
			return $this->handle_transient_request_error( $state, $response, 'Exact convergence recovery نتوانست Snapshot محصول محلی را دریافت کند.' );
		}
		$items = $this->extract_explicit_list_data( $response, 'repair exact convergence recovery' );
		if ( is_wp_error( $items ) ) {
			return $this->handle_transient_request_error( $state, $items, 'Exact convergence recovery پاسخ محصول را معتبر دریافت نکرد.' );
		}
		/* A valid exact response proves the dependency recovered. Clear the accumulated
		 * real-attempt retry budget here as every other successful API phase does;
		 * otherwise an old outage can poison later isolated failures after recovery. */
		$this->clear_transient_request_error( $state );
		$exact = null;
		foreach ( $items as $item ) {
			if ( is_array( $item ) && hash_equals( $guid, $this->extract_product_guid( $item ) ) ) {
				$exact = $item;
				break;
			}
		}
		if ( ! is_array( $exact ) ) {
			$state['convergenceRecoveryAttempted'][] = $guid;
			$state['convergenceRecoveryAttempted'] = array_slice( array_values( array_unique( $state['convergenceRecoveryAttempted'] ) ), -1000 );
			$state['convergenceRecoveryScanned'] = absint( $state['convergenceRecoveryScanned'] ?? 0 ) + 1;
			if ( $targeted_only ) { $state['convergenceRecoveryCursor'] = absint( $candidate['index'] ?? 0 ) + 1; }
			/* Absence from the exact endpoint is not deletion evidence. Record the bounded
			 * unresolved attempt and continue; downstream Health remains degraded. */
			$state['convergenceRecoverySkipped'] = absint( $state['convergenceRecoverySkipped'] ?? 0 ) + 1;
			$state['lastMessage'] = 'Exact convergence recovery could not confirm the local product upstream: ' . $guid;
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint exact convergence recovery could not be persisted.', $this->get_manual_sync_status() );
			}
			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/* A product can be fully mapped/completed yet remain operationally unowned
		 * solely because its local Variable child selections drifted from the exact
		 * upstream option bytes (for example legacy case-only attribute values). In
		 * that narrow state, replaying the parent through the generic catalogue queue
		 * is unnecessary and can lose the exact child snapshot boundary. If the exact
		 * product response embeds concrete Variants, repair those existing children as
		 * a non-destructive UpdateVariant delta. This never treats an omitted embedded
		 * Variant as deletion evidence; authoritative missing-Variant finalization stays
		 * exclusively on the normal paged snapshot path. */
		$candidate_row = isset( $candidate['row'] ) && is_array( $candidate['row'] ) ? $candidate['row'] : array();
		$structure_only = array_key_exists( 'localStructureSane', $candidate_row )
			&& empty( $candidate_row['localStructureSane'] )
			&& empty( $candidate_row['postIncomplete'] )
			&& empty( $candidate_row['mapIncomplete'] );
		$embedded_variants = $this->get_value( $exact, 'variants', null );
		if ( $structure_only && is_array( $embedded_variants ) && $this->is_list_array( $embedded_variants ) && ! empty( $embedded_variants ) ) {
			$repair_variants = array();
			foreach ( $embedded_variants as $variant ) {
				if ( ! is_array( $variant ) || ! $this->looks_like_variant_payload( $variant ) ) {
					continue;
				}
				$variant_parent = $this->extract_product_guid( $variant );
				if ( '' !== $variant_parent && ! hash_equals( $guid, $variant_parent ) ) {
					continue;
				}
				if ( '' === $variant_parent ) {
					$variant['productId'] = $guid;
				}
				$repair_variants[] = $variant;
			}

			if ( ! empty( $repair_variants ) ) {
				$variant_payload = array(
					'event'                       => 'UpdateVariant',
					'variantListAuthoritative'    => false,
					'syncId'                      => sanitize_text_field( (string) $state['syncId'] ),
					'productId'                   => $guid,
					'pageNumber'                  => 1,
					'recordPerPage'               => count( $repair_variants ),
					'totalCount'                  => count( $repair_variants ),
					'hasMore'                     => false,
					'isLastPage'                  => true,
					'_moboManualParentSnapshotUs' => $snapshot_us,
					'data'                        => $repair_variants,
				);
				$variant_result = $this->process_update_variant_payload( $variant_payload, true );
				if ( ! is_array( $variant_result ) || empty( $variant_result['success'] ) ) {
					$message = is_array( $variant_result ) && isset( $variant_result['message'] )
						? sanitize_text_field( (string) $variant_result['message'] )
						: 'Embedded exact Variant repair failed.';
					return $this->handle_transient_request_error(
						$state,
						new WP_Error( 'mobo_core_exact_structure_variant_repair_failed', $message ),
						'Exact convergence recovery نتوانست ساختار Variantهای محلی را از Snapshot دقیق ترمیم کند.'
					);
				}

				$state['convergenceRecoveryAttempted'][] = $guid;
				$state['convergenceRecoveryAttempted'] = array_slice( array_values( array_unique( $state['convergenceRecoveryAttempted'] ) ), -1000 );
				$state['convergenceRecoveryScanned'] = absint( $state['convergenceRecoveryScanned'] ?? 0 ) + 1;
				if ( $targeted_only ) { $state['convergenceRecoveryCursor'] = absint( $candidate['index'] ?? 0 ) + 1; }
				$state['convergenceRecoveryQueued'] = absint( $state['convergenceRecoveryQueued'] ?? 0 ) + 1;
				$state['lastError'] = '';
				$state['lastMessage'] = sprintf( 'Exact convergence recovery replayed %d embedded Variant(s) for local structure drift: %s', count( $repair_variants ), $guid );
				if ( class_exists( 'Mobo_Core_Health_Read_Cache' ) && method_exists( 'Mobo_Core_Health_Read_Cache', 'refresh_sync_health_snapshot' ) ) {
					Mobo_Core_Health_Read_Cache::refresh_sync_health_snapshot( 500 );
				}
				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint exact structure convergence recovery could not be persisted.', $this->get_manual_sync_status() );
				}
				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
			}
		}

		$state['convergenceRecoveryAttempted'][] = $guid;
		$state['convergenceRecoveryAttempted'] = array_slice( array_values( array_unique( $state['convergenceRecoveryAttempted'] ) ), -1000 );
		$state['convergenceRecoveryScanned'] = absint( $state['convergenceRecoveryScanned'] ?? 0 ) + 1;
		if ( $targeted_only ) { $state['convergenceRecoveryCursor'] = absint( $candidate['index'] ?? 0 ) + 1; }
		$exact['_moboManualSnapshotUs'] = $snapshot_us;
		$exact['_moboRepairSnapshotUs'] = $snapshot_us;
		$state['productQueue'][] = $exact;
		$state['convergenceRecoveryQueued'] = absint( $state['convergenceRecoveryQueued'] ?? 0 ) + 1;
		$state['lastError'] = '';
		$state['lastMessage'] = 'Exact convergence recovery queued existing local product: ' . $guid;
		if ( ! $this->save_manual_sync_state( $state ) ) {
			return $this->result( false, 'Checkpoint exact convergence recovery could not be persisted.', $this->get_manual_sync_status() );
		}
		return null;
	}

	private function save_manual_sync_state( $state ) {
		if ( ! is_array( $state ) ) {
			return false;
		}

		$incoming_status  = sanitize_key( (string) ( $state['status'] ?? '' ) );
		$incoming_sync_id = sanitize_text_field( (string) ( $state['syncId'] ?? '' ) );
		$current          = $this->checkpoint_coalescing ? array() : $this->load_durable_sync_state();

		/*
		 * A running worker may have loaded the state before the admin clicked
		 * cancel. Without this guard, that stale worker can save its old
		 * "running" snapshot after the cancel request and make the UI look
		 * alive again. Once a syncId is cancelled, only another explicit start or
		 * resume action is allowed to move it out of cancelled state.
		 */
		if ( is_array( $current ) ) {
			$current_status  = sanitize_key( (string) ( $current['status'] ?? '' ) );
			$current_sync_id = sanitize_text_field( (string) ( $current['syncId'] ?? '' ) );

			/* A stale worker from an older generation may never overwrite a newer run. */
			if ( '' !== $current_sync_id && '' !== $incoming_sync_id && ! hash_equals( $current_sync_id, $incoming_sync_id ) ) {
				return false;
			}

			if ( 'cancelled' === $current_status && 'cancelled' !== $incoming_status && '' !== $incoming_sync_id && $incoming_sync_id === $current_sync_id ) {
				return false;
			}
		}

		/*
		 * A normal Repair product pass may finish without ever seeing a product
		 * that became out of stock while OnlyInStock is enabled. Before marking
		 * Repair complete, switch to the local missing-image recovery pass.
		 */
		if ( ! empty( $state['repairMode'] ) && 'done' === $incoming_status && empty( $state['missingImageRecoveryComplete'] ) ) {
			$state['repairProductsComplete'] = true;
			$state['status']                 = 'running';
			$state['completedAt']            = 0;
			$state['lastError']              = '';
			$state['lastMessage']            = 'Repair محصولات اصلی کامل شد؛ بازیابی تصاویر محصولات محلی بدون عکس شروع می‌شود.';
			$incoming_status                 = 'running';
		}

		/* A completed product traversal is not a successful Repair if even one
		 * cross-parent identity was quarantined. Persist a terminal diagnostic,
		 * not the Repair completed option or a misleading 100% success state. */
		if ( ! empty( $state['repairMode'] ) && 'done' === sanitize_key( (string) ( $state['status'] ?? '' ) ) && absint( $state['repairParentConflictCount'] ?? 0 ) > 0 ) {
			$state['status'] = 'needs_attention';
			$state['completedAt'] = 0;
			$state['lastError'] = sprintf( 'Repair parent identity conflicts: %d unresolved product(s).', absint( $state['repairParentConflictCount'] ) );
			$state['lastMessage'] = sprintf( 'Repair سایر مراحل را پیمود، اما %d محصول دارای تعارض والد Variant است؛ تکمیل Repair تأیید نشد.', absint( $state['repairParentConflictCount'] ) );
		}

		$state['updatedAt'] = time();

		if ( $this->checkpoint_coalescing ) {
			$this->checkpoint_state = $state;
			$this->checkpoint_pending_steps++;

			$terminal = in_array( sanitize_key( (string) $state['status'] ), array( 'done', 'cancelled', 'waiting_for_portal' ), true )
				|| '' !== sanitize_text_field( (string) ( $state['lastError'] ?? '' ) );
			$elapsed = microtime( true ) - (float) $this->checkpoint_last_flush_at;

			if ( ! $terminal && $this->checkpoint_pending_steps < $this->checkpoint_max_steps && $elapsed < $this->checkpoint_max_seconds ) {
				return true;
			}

			return $this->flush_manual_sync_checkpoint();
		}

		if ( ! $this->persist_manual_sync_state_verified( $state ) ) {
			return false;
		}
		$this->persist_repair_completion_if_needed( $state );

		return true;
	}

	/**
	 * Durably persist the manual-sync checkpoint and verify the exact read-back.
	 * update_option() returns false both for failures and no-op writes, so the
	 * postcondition—not its boolean return—is the durability boundary.
	 *
	 * @param array $state Sync state.
	 * @return bool
	 */
	private function persist_manual_sync_state_verified( $state ) {
		if ( ! is_array( $state ) ) { return false; }
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() ) {
			return Mobo_Core_Durable_State_Policy::update_state_verified( self::STATE_KEY, $state );
		}
		if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { return false; }
		if ( class_exists( 'Mobo_Core_Connection_Context' ) && method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) && ! Mobo_Core_Connection_Context::legacy_default_owner_allowed() ) { return false; }
		return Mobo_Core_Durable_State_Policy::update_option_verified( self::STATE_OPTION, $state, false );
	}

	/**
	 * Begin bounded request-local checkpoint coalescing. Public so the real cron
	 * runner can combine several idempotent sync steps into one option write.
	 * Manual/admin one-step calls never enable this and retain immediate durability.
	 *
	 * @param int   $max_steps Max deferred step writes.
	 * @param float $max_seconds Max seconds between durable checkpoints.
	 * @return void
	 */
	public function begin_checkpoint_coalescing( $max_steps = 3, $max_seconds = 2.0 ) {
		$this->checkpoint_coalescing   = true;
		$this->checkpoint_pending_steps = 0;
		$this->checkpoint_max_steps    = max( 1, min( 10, absint( $max_steps ) ) );
		$this->checkpoint_max_seconds  = max( 0.5, min( 5.0, (float) $max_seconds ) );
		$this->checkpoint_last_flush_at = microtime( true );
		$this->checkpoint_state        = $this->get_manual_sync_state();
	}

	/**
	 * Flush a buffered sync checkpoint while preserving an admin cancellation
	 * that may have arrived from another request. At most a few idempotent steps
	 * are replayed after an abrupt PHP/server crash.
	 *
	 * @return bool
	 */
	public function flush_manual_sync_checkpoint() {
		if ( ! $this->checkpoint_coalescing || ! is_array( $this->checkpoint_state ) || $this->checkpoint_pending_steps <= 0 ) {
			return true;
		}

		/* Read the durable row directly so a concurrent cancel/new generation is visible. */
		$current = $this->load_durable_sync_state();
		$pending = $this->checkpoint_state;

		if ( is_array( $current ) ) {
			$current_status  = sanitize_key( (string) ( $current['status'] ?? '' ) );
			$current_sync_id = sanitize_text_field( (string) ( $current['syncId'] ?? '' ) );
			$pending_sync_id = sanitize_text_field( (string) ( $pending['syncId'] ?? '' ) );

			if ( '' !== $current_sync_id && '' !== $pending_sync_id && ! hash_equals( $current_sync_id, $pending_sync_id ) ) {
				$this->checkpoint_state         = $current;
				$this->checkpoint_pending_steps = 0;
				$this->checkpoint_last_flush_at = microtime( true );
				return false;
			}

			if ( 'cancelled' === $current_status && '' !== $current_sync_id && $current_sync_id === $pending_sync_id ) {
				$this->checkpoint_state         = $current;
				$this->checkpoint_pending_steps = 0;
				$this->checkpoint_last_flush_at = microtime( true );
				return false;
			}
		}

		if ( ! $this->persist_manual_sync_state_verified( $pending ) ) {
			return false;
		}
		$this->persist_repair_completion_if_needed( $pending );
		$this->checkpoint_pending_steps = 0;
		$this->checkpoint_last_flush_at = microtime( true );
		return true;
	}

	/** End coalescing and durably flush the latest checkpoint. */
	public function end_checkpoint_coalescing() {
		$result = $this->flush_manual_sync_checkpoint();
		$this->checkpoint_coalescing = false;
		$this->checkpoint_state      = null;
		return $result;
	}

	/** Persist repair-completion flags only when the durable state checkpoint is accepted. */
	private function persist_repair_completion_if_needed( $state ) {
		if ( ! is_array( $state ) || empty( $state['repairMode'] ) || ! empty( $state['targetedConvergenceOnly'] ) || absint( $state['repairParentConflictCount'] ?? 0 ) > 0 || 'done' !== sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
			return;
		}

		$completed_at = ! empty( $state['completedAt'] ) ? absint( $state['completedAt'] ) : time();
		Mobo_Core_Settings::update( self::REPAIR_COMPLETED_OPTION, $completed_at, false );
		Mobo_Core_Settings::update( self::REPAIR_LAST_SYNC_ID_OPTION, sanitize_text_field( (string) ( $state['syncId'] ?? '' ) ), false );
		update_option( 'mobo_core_legacy_repair_required', '0', false );
		delete_option( 'mobo_core_desired_state_repair_required' );
		delete_option( 'mobo_core_desired_state_repair_queue_pending' );
	}

	/** Track one repeated Repair product-lock miss without using the transport retry budget. */
	private function repair_product_lock_miss( &$state, $scope, $product_guid ) {
		$scope = sanitize_key( (string) $scope );
		$product_guid = sanitize_text_field( (string) $product_guid );
		$key = 'product-sync|' . $scope . '|' . $product_guid;
		$previous = sanitize_text_field( (string) ( $state['repairLivenessLockKey'] ?? '' ) );
		$count = $previous === $key ? absint( $state['repairLivenessLockCount'] ?? 0 ) + 1 : 1;
		$state['repairLivenessLockKey'] = $key;
		$state['repairLivenessLockCount'] = $count;
		return $count;
	}

	/** Clear the request-local/durable Repair lock budget after real progress. */
	private function clear_repair_product_lock_miss( &$state, $scope, $product_guid ) {
		$key = 'product-sync|' . sanitize_key( (string) $scope ) . '|' . sanitize_text_field( (string) $product_guid );
		if ( $key === sanitize_text_field( (string) ( $state['repairLivenessLockKey'] ?? '' ) ) ) {
			$state['repairLivenessLockKey'] = '';
			$state['repairLivenessLockCount'] = 0;
		}
	}

	/** Record a bounded Repair product quarantine and preserve an explicit incomplete marker. */
	private function quarantine_repair_product_for_liveness( &$state, $scope, $product_guid, $product_id, $reset_current ) {
		$scope = sanitize_key( (string) $scope );
		$product_guid = sanitize_text_field( (string) $product_guid );
		$product_id = absint( $product_id );
		if ( $product_id <= 0 && '' !== $product_guid ) {
			$product_id = $this->find_product_id_by_guid( $product_guid );
		}
		if ( $product_id > 0 ) {
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' ) ) {
				return $this->result( false, 'Repair نتوانست incomplete marker محصول lock‌شده را به‌صورت پایدار ثبت کند.', $this->get_manual_sync_status() );
			}
			if ( '' !== $product_guid && ! $this->upsert_product_map( $product_guid, $product_id, true ) ) {
				return $this->result( false, 'Repair نتوانست Product Map محصول lock‌شده را به‌صورت ناقص ثبت کند.', $this->get_manual_sync_status() );
			}
			if ( '' !== $product_guid && class_exists( 'Mobo_Core_Sync_Health' ) ) {
				Mobo_Core_Sync_Health::mark_behind( $product_guid, $product_id );
			}
		}

		$state['repairProductLockQuarantined'] = absint( $state['repairProductLockQuarantined'] ?? 0 ) + 1;
		if ( ! isset( $state['repairProductLockSamples'] ) || ! is_array( $state['repairProductLockSamples'] ) ) {
			$state['repairProductLockSamples'] = array();
		}
		if ( count( $state['repairProductLockSamples'] ) < 25 ) {
			$state['repairProductLockSamples'][] = array(
				'scope' => $scope,
				'productGuid' => $product_guid,
				'productId' => $product_id,
				'reason' => 'persistent product lock contention quarantined after five Repair attempts',
			);
		}
		$state['repairLivenessLockKey'] = '';
		$state['repairLivenessLockCount'] = 0;
		$state['processedProducts'] = absint( $state['processedProducts'] ?? 0 ) + 1;

		if ( $reset_current ) {
			$state['currentProductGuid'] = '';
			$state['currentProductId'] = 0;
			$state['currentProductPortalId'] = 0;
			$state['currentProductSourceRevision'] = 0;
			$state['currentProductSourceVersion'] = '';
			$state['currentProductSourceHash'] = '';
			$state['currentProductSnapshotUs'] = 0;
			$state['currentProductImages'] = array();
			$state['currentProductImagesPresent'] = false;
			$state['currentProductAttributes'] = array();
			$state['currentProductImageOffset'] = 0;
			$state['currentProductWasExisting'] = false;
			$state['currentProductImagesDone'] = false;
			$state['currentProductCanHaveVariants'] = false;
			$state['variantPage'] = 1;
			$state['currentVariantTotalCount'] = 0;
			$state['currentVariantTotalPages'] = 0;
			$state['currentVariantProcessedPages'] = 0;
			$state['currentVariantCursor'] = 0;
			$state['currentVariantCursorDisabled'] = false;
		}

		$state['lastError'] = '';
		$state['lastMessage'] = sprintf( 'Repair محصول %s را پس از ۵ بار lock contention به‌صورت ناقص quarantine کرد و ادامه می‌دهد.', '' !== $product_guid ? $product_guid : ('#' . $product_id) );
		if ( absint( $state['productTotalCount'] ?? 0 ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
			$state['status'] = 'done';
			$state['completedAt'] = time();
		}
		if ( ! $this->save_manual_sync_state( $state ) ) {
			return $this->result( false, 'Checkpoint quarantine محصول lock‌شده ذخیره نشد.', $this->get_manual_sync_status() );
		}
		return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
	}

	/**
	 * Bounded retry + isolation for a parent product whose upsert threw.
	 *
	 * Attempts 1..N-1 put the payload back at the head of the queue (durable
	 * checkpoint, so the failure counter survives). Attempt N marks the product
	 * failed/retryable (incomplete marker, incomplete map row, Sync Health failed
	 * entry) and advances, so one bad item can never block the other products.
	 * Webhooks/next Sync retry the product later through the normal paths.
	 *
	 * @param array      $state Manual sync state (by reference).
	 * @param array      $product_data Payload.
	 * @param string     $product_guid Product GUID.
	 * @param Throwable  $failure Failure.
	 * @return array
	 */
	private function isolate_failed_parent_product( &$state, $product_data, $product_guid, $failure ) {
		$product_guid = sanitize_text_field( (string) $product_guid );
		$message      = substr( sanitize_text_field( (string) $failure->getMessage() ), 0, 300 );
		if ( '' === $message ) {
			$message = 'Product upsert failed (' . sanitize_key( get_class( $failure ) ) . ').';
		}
		$key = '' !== $product_guid ? $product_guid : md5( (string) wp_json_encode( $product_data ) );

		$failures = isset( $state['productFailureAttempts'] ) && is_array( $state['productFailureAttempts'] ) ? $state['productFailureAttempts'] : array();
		$attempts = absint( $failures[ $key ] ?? 0 ) + 1;
		$max      = max( 1, min( 10, absint( apply_filters( 'mobo_core_manual_sync_product_failure_retry_limit', 3, $product_guid ) ) ) );

		if ( class_exists( 'Mobo_Core_Logger' ) ) {
			Mobo_Core_Logger::error( 'Mobo Core manual sync product upsert failed.', array( 'productGuid' => $product_guid, 'attempt' => $attempts, 'max' => $max, 'error' => $message ) );
		}

		if ( $attempts < $max ) {
			$failures[ $key ] = $attempts;
			$state['productFailureAttempts'] = array_slice( $failures, -50, null, true );
			array_unshift( $state['productQueue'], $product_data );
			$state['lastError']   = $message;
			$state['lastMessage'] = sprintf( 'ذخیره محصول %s ناموفق بود و در مرحله بعد دوباره تلاش می‌شود (%d/%d).', '' !== $product_guid ? $product_guid : '—', $attempts, $max );
			$state['updatedAt']   = time();
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}
			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		unset( $failures[ $key ] );
		$state['productFailureAttempts'] = $failures;
		$product_id = '' !== $product_guid ? $this->find_product_id_by_guid( $product_guid ) : 0;
		if ( $product_id > 0 ) {
			$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
			$this->upsert_product_map( $product_guid, $product_id, true );
		}
		if ( '' !== $product_guid && class_exists( 'Mobo_Core_Sync_Health' ) ) {
			Mobo_Core_Sync_Health::mark_failed( $product_guid, $product_id, $message );
		}

		$state['failedProducts'] = absint( $state['failedProducts'] ?? 0 ) + 1;
		if ( ! isset( $state['failedProductSamples'] ) || ! is_array( $state['failedProductSamples'] ) ) {
			$state['failedProductSamples'] = array();
		}
		if ( count( $state['failedProductSamples'] ) < 25 ) {
			$state['failedProductSamples'][] = array( 'productGuid' => $product_guid, 'productId' => $product_id, 'error' => $message, 'at' => time() );
		}
		$state['processedProducts'] = absint( $state['processedProducts'] ?? 0 ) + 1;
		$state['lastError']         = $message;
		$state['lastMessage']       = sprintf( 'محصول %s پس از %d تلاش ناموفق به‌عنوان «ناموفق/قابل تلاش مجدد» علامت خورد و همگام‌سازی سایر محصولات ادامه یافت.', '' !== $product_guid ? $product_guid : '—', $max );
		$state['updatedAt']         = time();
		if ( absint( $state['productTotalCount'] ?? 0 ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) && empty( $state['productQueue'] ) ) {
			$state['status']      = 'done';
			$state['completedAt'] = time();
		}
		if ( ! $this->save_manual_sync_state( $state ) ) {
			return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
		}
		return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
	}

	/**
	 * Quarantine one definitive cross-parent identity mismatch without changing
	 * Woo post_parent, variation IDs, order items or Product Map parent IDs.
	 * Unlike a transient fetch failure, this conflict must not be retried on
	 * every cron tick. Other products may still progress in the same generation.
	 * Completion remains blocked until a new, fully validated Repair generation.
	 */
	private function quarantine_repair_parent_conflict( &$state, $conflict, $product_guid, $product_id ) {
		$product_guid = sanitize_text_field( (string) $product_guid );
		$product_id = absint( $product_id );
		$q2_enabled = '1' === (string) get_option( 'mobo_core_parent_identity_fence_q2_enabled', '0' );
		$is_parent_fence_conflict = $conflict instanceof Mobo_Core_Parent_Identity_Conflict;
		if ( '' === $product_guid
			|| ! ( $conflict instanceof Mobo_Core_Variation_Parent_Conflict || $is_parent_fence_conflict )
			|| ! hash_equals( $product_guid, $conflict->product_guid )
			|| $this->connection_id() !== $conflict->connection_id
			|| ( $is_parent_fence_conflict && ( ! $q2_enabled || ! Mobo_Core_Parent_Identity_Conflict::is_definitive_reason( $conflict->reason ) ) ) ) {
			return $this->result( false, 'Repair rejected an unowned or non-definitive parent-identity conflict.', $this->get_manual_sync_status() );
		}

		/* Q2: no postmeta, map, Woo-parent or Health writes to a conflicting
		 * identity. The durable Repair journal is the sole quarantine marker.
		 * Before Q2 activation the existing variation path is unchanged. */
		if ( ! $q2_enabled ) {
			if ( $product_id <= 0
				|| ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' )
				|| ! $this->upsert_product_map( $product_guid, $product_id, true ) ) {
				return $this->result( false, 'Parent conflict checkpoint could not durably preserve the mapped product as incomplete.', $this->get_manual_sync_status() );
			}
			if ( class_exists( 'Mobo_Core_Sync_Health' ) ) {
				Mobo_Core_Sync_Health::mark_behind( $product_guid, $product_id );
			}
		}

		$guids = isset( $state['repairParentConflictGuids'] ) && is_array( $state['repairParentConflictGuids'] ) ? $state['repairParentConflictGuids'] : array();
		$already_quarantined = in_array( $product_guid, $guids, true );
		if ( ! $already_quarantined ) {
			if ( count( $guids ) >= 1000 ) {
				return $this->result( false, 'Parent conflict journal reached the hard limit; skipping is forbidden.', $this->get_manual_sync_status() );
			}
			$guids[] = $product_guid;
			$state['repairParentConflictGuids'] = $guids;
			$state['repairParentConflictCount'] = absint( $state['repairParentConflictCount'] ?? 0 ) + 1;
			if ( ! isset( $state['repairParentConflictSamples'] ) || ! is_array( $state['repairParentConflictSamples'] ) ) {
				$state['repairParentConflictSamples'] = array();
			}
			if ( count( $state['repairParentConflictSamples'] ) < 25 ) {
				$state['repairParentConflictSamples'][] = array(
					'connectionId'    => $conflict->connection_id,
					'productGuid'     => $product_guid,
					'mappedParent'    => $product_id,
					'existingParent'  => $is_parent_fence_conflict ? 0 : $conflict->existing_parent_id,
					'variationId'     => $is_parent_fence_conflict ? 0 : $conflict->variation_id,
					'variantGuid'     => $is_parent_fence_conflict ? '' : $conflict->variant_guid,
					'reason'          => $is_parent_fence_conflict ? $conflict->reason : 'cross-parent variation identity; no reassignment',
					'legacyParent'    => $is_parent_fence_conflict ? $conflict->legacy_parent_id : 0,
					'customParent'    => $is_parent_fence_conflict ? $conflict->custom_parent_id : 0,
					'variationParents' => $is_parent_fence_conflict ? $conflict->variation_parent_ids : array( $conflict->existing_parent_id ),
				);
			}
		}

		/* One remote GUID is counted only once per Repair generation, even if a
		 * duplicate catalogue snapshot reaches this method again. */
		if ( ! $already_quarantined ) {
			$state['processedProducts'] = absint( $state['processedProducts'] ?? 0 ) + 1;
		}
		$state['currentProductGuid'] = '';
		$state['currentProductId'] = 0;
		$state['currentProductPortalId'] = 0;
		$state['currentProductSourceRevision'] = 0;
		$state['currentProductSourceVersion'] = '';
		$state['currentProductSourceHash'] = '';
		$state['currentProductSnapshotUs'] = 0;
		$state['currentProductImages'] = array();
		$state['currentProductImagesPresent'] = false;
		$state['currentProductAttributes'] = array();
		$state['currentProductImageOffset'] = 0;
		$state['currentProductWasExisting'] = false;
		$state['currentProductImagesDone'] = false;
		$state['currentProductCanHaveVariants'] = false;
		$state['variantPage'] = 1;
		$state['currentVariantTotalCount'] = 0;
		$state['currentVariantTotalPages'] = 0;
		$state['currentVariantProcessedPages'] = 0;
		$state['currentVariantCursor'] = 0;
		$state['currentVariantCursorDisabled'] = false;
		$state['lastError'] = '';
		$state['lastMessage'] = $is_parent_fence_conflict
			? sprintf( 'Repair تعارض هویت Parent را فقط در Checkpoint ثبت کرد: %s، علت %s. هیچ نگاشت یا Variant تغییر نکرد.', $product_guid, $conflict->reason )
			: sprintf( 'Repair تعارض والد Variant را بدون جابه‌جایی یا حذف قرنطینه کرد: %s، والد ثبت‌شده %d، والد دارای Variant %d.', $product_guid, $product_id, $conflict->existing_parent_id );
		if ( absint( $state['productTotalCount'] ?? 0 ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
			$state['status'] = 'done';
		}
		if ( ! $this->save_manual_sync_state( $state ) ) {
			return $this->result( false, 'Parent conflict quarantine checkpoint could not be persisted.', $this->get_manual_sync_status() );
		}
		return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
	}

	private function finish_current_product_state( &$state, $message ) {
		$completed_guid = sanitize_text_field( (string) $state['currentProductGuid'] );
		$completed_id   = absint( $state['currentProductId'] );
		if ( '' !== $completed_guid && $completed_id > 0 ) {
			if ( ! $this->upsert_product_map( $completed_guid, $completed_id, false ) ) {
				$this->update_post_meta_if_changed( $completed_id, 'mobo_sync_incomplete', '1' );
				return false;
			}
			if ( ! $this->persist_post_meta_verified( $completed_id, 'mobo_sync_incomplete', '0' ) ) {
				$this->upsert_product_map( $completed_guid, $completed_id, true );
				return false;
			}

			/* Commit the Repair-generation marker only for products that reached the
			 * same durable completion boundary as normal sync. The timestamp is written
			 * first and the syncId last, making syncId the commit marker. Post-sync
			 * cleanup also requires mobo_sync_incomplete=0. */
			if ( ! empty( $state['repairMode'] ) ) {
				$repair_sync_id = sanitize_text_field( (string) ( $state['syncId'] ?? '' ) );
				$repair_applied_revision = absint( get_post_meta( $completed_id, '_mobo_product_applied_revision', true ) );
				$repair_webhook_us = absint( get_post_meta( $completed_id, '_mobo_last_webhook_applied_us', true ) );
				if ( '' === $repair_sync_id
					|| ! $this->persist_post_meta_verified( $completed_id, self::REPAIR_APPLIED_REVISION_META, $repair_applied_revision )
					|| ! $this->persist_post_meta_verified( $completed_id, self::REPAIR_WEBHOOK_US_META, $repair_webhook_us )
					|| ! $this->persist_post_meta_verified( $completed_id, self::REPAIR_SYNCED_AT_META, time() )
					|| ! $this->persist_post_meta_verified( $completed_id, self::REPAIR_SYNC_ID_META, $repair_sync_id ) ) {
					$this->persist_post_meta_verified( $completed_id, 'mobo_sync_incomplete', '1' );
					$this->upsert_product_map( $completed_guid, $completed_id, true );
					return false;
				}
			}

			if ( class_exists( 'Mobo_Core_Sync_Health' ) ) {
				Mobo_Core_Sync_Health::mark_synced(
					$completed_guid,
					$completed_id,
					absint( $state['currentProductSourceRevision'] ?? 0 ),
					sanitize_text_field( (string) ( $state['currentProductSourceHash'] ?? '' ) ),
					absint( $state['currentProductPortalId'] ?? 0 ),
					sanitize_text_field( (string) ( $state['currentProductSourceVersion'] ?? '' ) )
				);
			}
		}

		$state['processedProducts']              = absint( $state['processedProducts'] ) + 1;
		$state['currentProductGuid']             = '';
		$state['currentProductId']               = 0;
		$state['currentProductPortalId']         = 0;
		$state['currentProductSourceRevision']   = 0;
		$state['currentProductSourceVersion']    = '';
		$state['currentProductSourceHash']       = '';
		$state['currentProductSnapshotUs']      = 0;
		$state['currentProductImages']           = array();
		$state['currentProductImagesPresent']    = false;
		$state['currentProductAttributes']       = array();
		$state['currentProductImageOffset']      = 0;
		$state['currentProductWasExisting']      = false;
		$state['currentProductImagesDone']       = false;
		$state['currentProductCanHaveVariants']  = false;
		$state['variantPage']                    = 1;
		$state['currentVariantTotalCount']       = 0;
		$state['currentVariantTotalPages']       = 0;
		$state['currentVariantProcessedPages']   = 0;
		$state['currentVariantCursor']            = 0;
		$state['currentVariantCursorDisabled']    = false;
		$state['lastError']                      = '';
		$state['lastMessage']                    = sanitize_text_field( (string) $message );

		if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
			$state['status']      = 'done';
			$state['completedAt'] = time();
			$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
		}

		return $this->save_manual_sync_state( $state );
	}

	/**
	 * Source-status aware parent upsert.
	 *
	 * - A payload explicitly marked not published never creates a new local product
	 *   (returns 0, which every caller already treats as "skip").
	 * - Existing products get the Source visibility overlay (never trashed/drafted).
	 * - Payloads without status fields (10.33.67-era Backend) keep the legacy behavior.
	 *
	 * @param array $data Product payload.
	 * @param bool  $skip_images Skip images.
	 * @return int
	 */
	private function upsert_parent_product( $data, $skip_images ) {
		$visibility = class_exists( 'Mobo_Core_Source_Visibility' ) ? Mobo_Core_Source_Visibility::payload_visibility( $data ) : '';
		if ( Mobo_Core_Source_Visibility::HIDDEN === $visibility ) {
			$guard_guid = $this->extract_product_guid( $data );
			if ( '' !== $guard_guid && $this->find_product_id_by_guid( $guard_guid ) <= 0 ) {
				$this->last_parent_core_noop = false;
				Mobo_Core_Source_Visibility::log( 'warning', 'Product is not published on the Source; it was not created on this site.', array( 'productGuid' => $guard_guid ) );
				return 0;
			}
		}

		$product_id = $this->upsert_parent_product_core( $data, $skip_images );

		if ( $product_id > 0 ) {
			$source_status = Mobo_Core_Source_Visibility::normalize_status( $this->get_value( $data, 'sourceStatus', '' ) );
			Mobo_Core_Source_Visibility::apply_to_product( $product_id, $visibility, $source_status );
			$this->apply_optional_description( $product_id, $data );
		}

		return $product_id;
	}

	/**
	 * Apply the Source description only when this Connection enabled it and the payload
	 * explicitly carries a non-null description (portal-ir never sends one).
	 *
	 * @param int   $product_id Product ID.
	 * @param array $data Product payload.
	 * @return void
	 */
	private function apply_optional_description( $product_id, $data ) {
		if ( ! method_exists( $this->rules, 'should_update_description' ) || ! $this->rules->should_update_description() ) {
			return;
		}
		$inspection = Mobo_Core_Payload_Field_Policy::inspect( $data, array( 'description', 'Description' ) );
		if ( empty( $inspection['present'] ) || ! is_string( $inspection['value'] ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$description = wp_kses_post( (string) $inspection['value'] );
		if ( class_exists( 'Mobo_Core_Unicode_Guard' ) ) {
			$description = (string) Mobo_Core_Unicode_Guard::prepare_post_field( $description, 'post_content', 'product-description' );
		}
		if ( (string) $product->get_description( 'edit' ) !== $description ) {
			$product->set_description( $description );
			$product->save();
		}
	}

	/**
	 * Whether a brand-new local product may be created now: always after the DB
	 * version is stamped, otherwise only once the legacy Product Map seed completed.
	 *
	 * @return bool
	 */
	public static function product_creation_allowed() {
		if ( defined( 'MOBO_CORE_VERSION' ) && MOBO_CORE_VERSION === trim( (string) get_option( 'mobo_core_db_version', '' ) ) ) {
			return true;
		}
		return absint( get_option( 'mobo_core_product_map_seed_completed_at', 0 ) ) > 0;
	}

	private function upsert_parent_product_core( $data, $skip_images ) {
		$this->last_parent_core_noop    = false;
		$this->maybe_adopt_creation_orphans();

		$product_guid = $this->extract_product_guid( $data );

		if ( '' === $product_guid ) {
			return 0;
		}

		$product_id      = $this->find_product_id_by_guid( $product_guid );
		$is_new_product  = $product_id <= 0;
		$payload_integrity = $this->validate_product_desired_state_payload( $data, $is_new_product );
		if ( is_wp_error( $payload_integrity ) ) {
			throw new RuntimeException( $payload_integrity->get_error_message() );
		}
		if ( $product_id <= 0 && $this->is_repair_mode() ) {
			$product_id = $this->restore_trashed_mobo_object_by_identity( 'product', 'product_guid', $product_guid, 0 );
			if ( $product_id < 0 ) {
				/* Merchant-trashed product: never resurrect and never create a duplicate. */
				return 0;
			}
			if ( $product_id > 0 ) {
				$this->upsert_product_map( $product_guid, $product_id, false );
			}
		}
		$is_new_product  = $product_id <= 0;
		/* H6: a previous attempt may have crashed between wp_insert_post() and the
		 * GUID bind. Adopt that marked product instead of creating a duplicate; it
		 * is still filled with new-product semantics below. */
		$adopted_creation_id = 0;
		if ( $is_new_product && class_exists( 'Mobo_Core_Product_Creation_Intent' ) ) {
			$adopted_creation_id = Mobo_Core_Product_Creation_Intent::find_adoptable( $this->connection_id(), $product_guid );
			if ( $adopted_creation_id > 0 && class_exists( 'Mobo_Core_Logger' ) ) {
				Mobo_Core_Logger::warning( 'Adopting a product left without identity by an interrupted creation instead of creating a duplicate.', array( 'productGuid' => $product_guid, 'productId' => $adopted_creation_id ) );
			}
		}
		if ( $is_new_product && $adopted_creation_id <= 0 && ! self::product_creation_allowed() ) {
			/* Upgrade window: the legacy Product Map seed is still running. Updates to
			 * existing products proceed (lookups fall back to legacy identity); only
			 * creating a product is deferred, so an unmapped existing one can never be
			 * duplicated. Queues retry this without consuming an attempt. */
			throw new Mobo_Core_Product_Creation_Deferred( 'Product creation is deferred until the Product Map seed completes: ' . $product_guid );
		}
		if ( $is_new_product && '1' === (string) get_option( 'mobo_core_parent_identity_fence_q2_enabled', '0' ) ) {
			Mobo_Core_Parent_Identity_Fence::assert_can_create( $product_guid );
		}
		$incoming_hash   = $this->build_product_source_hash( $data, $product_guid );

		if ( $product_id > 0 ) {
			$product = wc_get_product( $product_id );

			/*
			 * An existing Mobo identity must never be type-coerced merely because the
			 * WooCommerce factory could not materialize it. Constructing
			 * WC_Product_Simple with an existing Variable product ID can make
			 * WC_Product::save() run WooCommerce's Variable->non-Variable transition,
			 * which force-deletes every child variation before Mobo's quarantine/map
			 * lifecycle has a chance to preserve forensic identity. Treat a transient
			 * or structural factory failure as retryable/fail-closed instead.
			 */
			if ( ! $product instanceof WC_Product ) {
				throw new RuntimeException(
					sprintf(
						'WooCommerce could not load existing Mobo product %d (%s); mutation was deferred to avoid unsafe product-type coercion.',
						$product_id,
						$product_guid
					)
				);
			}
		} elseif ( $adopted_creation_id > 0 ) {
			$product = wc_get_product( $adopted_creation_id );
			if ( ! $product instanceof WC_Product ) {
				throw new RuntimeException( sprintf( 'WooCommerce could not load interrupted-creation product %d (%s); creation deferred to avoid a duplicate.', $adopted_creation_id, $product_guid ) );
			}
		} else {
			$product = new WC_Product_Simple();
		}

		/*
		 * R1: ProductUpdated carries no Variants (they arrive in separate UpdateVariant
		 * pages), but its product-level price is the lowest Variant price and its stock
		 * the sum. A new product whose payload proves variation attributes is therefore
		 * created blocked (persisted out of stock, not purchasable) until its Variant
		 * topology is applied; it must never sell as this temporary Simple parent.
		 * Existing products are never flagged.
		 */
		if ( class_exists( 'Mobo_Core_Source_Visibility' )
			&& Mobo_Core_Source_Visibility::should_await_variants(
				$is_new_product,
				$product instanceof WC_Product_Simple && ! $product instanceof WC_Product_Variable,
				$this->product_payload_can_have_variants( $data, 0 )
			)
		) {
			Mobo_Core_Source_Visibility::mark_awaiting_variants( $product );
		}

		/*
		 * Fast path for an already converged parent product. The source hash contains
		 * only Mobo-controlled storefront fields and the active pricing/update policy.
		 * A small state verification protects against local drift (price/stock/title/
		 * slug/attributes). Internal identity/category-reference metadata can still be
		 * repaired below without running WC_Product::save(), so no page-cache purge is
		 * generated for an internal-only change.
		 */
		if ( ! $is_new_product && '' !== $incoming_hash ) {
			$old_hash       = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_product_source_hash', true ) );
			$old_incomplete = sanitize_text_field( (string) get_post_meta( $product_id, 'mobo_sync_incomplete', true ) );
		}
		if ( ! $is_new_product && '' !== $incoming_hash && $this->noop_fast_path_allowed( $product_id, $old_hash, $incoming_hash, 'product' ) ) {

			/*
			 * Frontend dirty classification: the source hash may change because policy
			 * configuration or internal source metadata changed even though the rendered
			 * WooCommerce state is already identical. In that case refresh internal
			 * convergence metadata directly and avoid WC CRUD/cache hooks entirely.
			 */
			if ( '1' !== $old_incomplete && $this->product_frontend_state_matches_payload( $product, $data ) ) {
				if ( ! $this->sync_product_identity_meta_fast( $product_id, $data, $product_guid ) || ! $this->sync_price_source_meta_fast( $product_id, $data ) ) {
					throw new RuntimeException( 'Could not durably refresh product source bookkeeping on the no-op path.' );
				}
				$this->cleanup_stock_diagnostic_meta_fast( $product_id, $data );
				if ( $this->product_attributes_field_present( $data ) && ! $this->store_product_attribute_guids_if_changed( $product_id, $this->get_value( $data, 'attributes', array() ) ) ) {
					throw new RuntimeException( 'Could not durably persist product attribute identity metadata.' );
				}
				if ( $this->product_categories_field_present( $data ) ) {
					$fast_category_refs = $this->get_raw_product_categories_field( $data );
					if ( ! is_array( $fast_category_refs ) || ! $this->store_product_category_refs_if_changed( $product_id, $fast_category_refs ) ) {
						throw new RuntimeException( 'Could not durably persist product category reference metadata.' );
					}
					$fast_category_assignment = $this->category_sync->assign_product_categories(
						$product_id,
						$fast_category_refs,
						$this->rules->should_update_categories(),
						false,
						true
					);
					if ( is_array( $fast_category_assignment ) && ! empty( $fast_category_assignment['error'] ) ) {
						throw new RuntimeException( 'Category assignment failed on product no-op path: ' . sanitize_text_field( (string) $fast_category_assignment['error'] ) );
					}
				}
				if ( ! $this->persist_post_meta_verified( $product_id, '_mobo_product_source_hash', $incoming_hash ) ) {
					throw new RuntimeException( 'Could not durably persist the product source hash.' );
				}
				if ( $old_hash !== $incoming_hash ) {
					$this->update_post_meta_if_changed( $product_id, '_mobo_product_source_hash_updated_at', gmdate( 'c' ) );
				}
				$this->last_parent_core_noop = true;
				if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
					Mobo_Core_Runtime_Diagnostics::increment( 'product_noop' );
				}
				if ( ! $this->upsert_product_map( $product_guid, $product_id, false ) ) {
					$this->update_post_meta_if_changed( $product_id, 'mobo_sync_incomplete', '1' );
					throw new RuntimeException( 'Could not refresh product mapping metadata.' );
				}

				/* A missing simple-product storefront identity must still be repairable. */
				if ( ! $this->product_payload_can_have_variants( $data, $product_id ) ) {
					$embedded_variant = $this->extract_embedded_simple_variant_data( $data );
					if ( ! empty( $embedded_variant ) && ! $this->has_stored_simple_variant_identity( $product_id ) ) {
						if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' ) || ! $this->upsert_product_map( $product_guid, $product_id, true ) ) {
							throw new RuntimeException( 'Could not durably persist the simple-product repair crash marker.' );
						}
						$embedded_result = $this->apply_simple_variant_to_product( $product_id, $embedded_variant, $product_guid );
						if ( is_wp_error( $embedded_result ) ) {
							throw new RuntimeException( $embedded_result->get_error_message() );
						}
						if ( ! $this->upsert_product_map( $product_guid, $product_id, false ) ) {
							throw new RuntimeException( 'Could not finalize embedded simple-product mapping.' );
						}
						if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '0' ) ) {
							$this->upsert_product_map( $product_guid, $product_id, true );
							throw new RuntimeException( 'Could not durably persist the simple-product repair completion marker.' );
						}
					}
				}

				return $product_id;
			}
		}

		/*
		 * New products are now assembled fully in memory and persisted with one
		 * WooCommerce CRUD save. The incomplete marker stays at 1 during that save,
		 * preserving crash detection without paying for a second WC save/hook cycle.
		 */
		if ( $is_new_product ) {
			$initial_title = $this->source_post_title( $data );

			if ( '' === $initial_title ) {
				$initial_title = 'Mobo Product ' . $product_guid;
			}

			$product->set_name( $initial_title );
			$product->set_status( 'publish' );
			$this->update_product_meta_if_changed( $product, 'product_guid', $product_guid );
			$this->store_portal_product_id_on_product_object( $product, $data );
			$this->store_external_product_id_on_product_object( $product, $data );
			$product->update_meta_data( 'mobo_sync_incomplete', '1' );
		} else {
			/*
			 * Existing products used to perform a full WooCommerce CRUD save here only
			 * to mark the sync incomplete, followed by another save after field updates.
			 * Direct metadata keeps crash-recovery semantics while removing that first
			 * expensive save/hook/cache-invalidation cycle.
			 */
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' ) ) {
				throw new RuntimeException( 'Could not durably persist the product crash marker before mutation.' );
			}
		}

		if ( $is_new_product || $this->rules->should_update_title() ) {
			$title = $this->source_post_title( $data );

			if ( '' !== $title && (string) $product->get_name( 'edit' ) !== $title ) {
				$product->set_name( $title );
			}
		}

		$price_field_present   = $this->product_price_field_present( $data );
		$compare_field_present = $this->product_compare_price_field_present( $data );
		if ( $is_new_product || ( ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) && ( $price_field_present || $compare_field_present ) ) ) {
			$this->apply_price_to_product( $product, $data, 'product', $is_new_product );
		}

		if ( $is_new_product || $this->rules->should_update_stock() ) {
			$stock_present = false;
			$stock_value   = $this->get_stock_value_from_payload( $data, $stock_present );

			if ( $stock_present ) {
				if ( ! $this->apply_api_stock( $product, $stock_value ) ) {
					throw new RuntimeException( 'Invalid Mobo stock payload for product.' );
				}
			} elseif ( $is_new_product ) {
				$this->apply_api_stock( $product, null );
			}
		}

		if ( $is_new_product || $this->rules->should_update_slug() ) {
			$this->apply_product_slug( $product, $data );
		}

		$this->apply_product_dates( $product, $data );

		$url = class_exists( 'Mobo_Core_Product_Exclusions' ) ? Mobo_Core_Product_Exclusions::sanitize_source_url( $this->get_value( $data, 'url', '' ) ) : sanitize_text_field( (string) $this->get_value( $data, 'url', '' ) );

		if ( '' !== $url ) {
			$this->update_product_meta_if_changed( $product, 'mobo_url', $url );
		}

		$this->update_product_meta_if_changed( $product, 'product_guid', $product_guid );
		$this->store_portal_product_id_on_product_object( $product, $data );

		$attributes_present        = $is_new_product || $this->product_attributes_field_present( $data );
		$desired_attribute_payload = $this->get_value( $data, 'attributes', array() );
		$attribute_state_dirty     = $attributes_present && ( $is_new_product || ! $this->product_attributes_match_payload( $product, $desired_attribute_payload ) );
		$default_attributes_dirty  = $attributes_present && ! empty( $product->get_default_attributes() );

		/*
		 * Price/stock/title-only updates must not re-serialize _product_attributes.
		 * WC_Product::set_attributes() marks the property dirty even when callers
		 * reconstruct an identical object graph, causing unnecessary postmeta work.
		 */
		if ( $attribute_state_dirty || $default_attributes_dirty ) {
			$product->set_default_attributes( array() );
			$product->set_attributes( $this->build_product_attributes( $desired_attribute_payload ) );
		}

		$source_hash_changed = false;
		if ( '' !== $incoming_hash ) {
			$source_hash_changed = $this->update_product_meta_if_changed( $product, '_mobo_product_source_hash', $incoming_hash );
			if ( $source_hash_changed ) {
				$this->update_product_meta_if_changed( $product, '_mobo_product_source_hash_updated_at', gmdate( 'c' ) );
			}
		}
		if ( $is_new_product && $adopted_creation_id <= 0 && class_exists( 'Mobo_Core_Product_Creation_Intent' ) ) {
			/* H6: the provisional identity marker is written by wp_insert_post()
			 * itself (meta_input), before any save hook can be interrupted. */
			$product_id = absint(
				Mobo_Core_Product_Creation_Intent::save_with_marker(
					$this->connection_id(),
					$product_guid,
					static function () use ( $product ) {
						return $product->save();
					}
				)
			);
		} else {
			$product_id = absint( $product->save() );
		}
		if ( $is_new_product && $product_id > 0 && '1' === (string) get_option( 'mobo_core_parent_identity_fence_q2_enabled', '0' ) ) {
			$this->q2_new_parent_authorizations[ $product_guid ] = $product_id;
		}
		if ( $product_id > 0 && class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
			Mobo_Core_Runtime_Diagnostics::increment( 'product_save' );
			if ( $is_new_product ) {
				Mobo_Core_Runtime_Diagnostics::increment( 'product_created' );
			}
		}

		if ( $product_id > 0 ) {
			/* Keep both persistence layers incomplete until category/image/simple-variant
			 * side effects have durably committed. The caller clears the marker only at
			 * its terminal desired-state checkpoint. */
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' ) ) {
				throw new RuntimeException( 'Could not verify the product crash marker after WooCommerce save.' );
			}
			if ( ! $this->sync_product_identity_meta_fast( $product_id, $data, $product_guid ) || ! $this->sync_price_source_meta_fast( $product_id, $data ) || ( '' !== $incoming_hash && ! $this->persist_post_meta_verified( $product_id, '_mobo_product_source_hash', $incoming_hash ) ) ) {
				throw new RuntimeException( 'Could not verify product identity/source bookkeeping after WooCommerce save.' );
			}
			if ( ! $this->upsert_product_map( $product_guid, $product_id, true ) ) {
				throw new RuntimeException( 'Could not durably commit product identity mapping.' );
			}
			if ( $is_new_product && class_exists( 'Mobo_Core_Product_Creation_Intent' ) ) {
				/* Identity and map are durable: the provisional marker is no longer needed. */
				Mobo_Core_Product_Creation_Intent::clear( $product_id );
			}
		}

		if ( $product_id <= 0 ) {
			return 0;
		}

		if ( $attributes_present && ! $this->store_product_attribute_guids_if_changed( $product_id, $this->get_value( $data, 'attributes', array() ) ) ) {
			throw new RuntimeException( 'Could not durably persist product attribute identity metadata.' );
		}

		$categories_field_present = $this->product_categories_field_present( $data );
		$categories_present       = $is_new_product || $categories_field_present;
		$product_category_refs    = $categories_field_present ? $this->get_raw_product_categories_field( $data ) : array();
		if ( ! is_array( $product_category_refs ) ) {
			$product_category_refs = array();
		}
		if ( $categories_field_present && ! $this->store_product_category_refs_if_changed( $product_id, $product_category_refs ) ) {
			throw new RuntimeException( 'Could not durably persist product category reference metadata.' );
		}

		$category_assignment = $categories_present ? $this->category_sync->assign_product_categories(
			$product_id,
			$product_category_refs,
			$this->rules->should_update_categories(),
			$is_new_product,
			$categories_field_present
		) : array();

		if ( is_array( $category_assignment ) && ! empty( $category_assignment['error'] ) ) {
			$this->update_post_meta_if_changed( $product_id, 'mobo_sync_incomplete', '1' );
			$this->upsert_product_map( $product_guid, $product_id, true );
			throw new RuntimeException( 'Category assignment failed for product ' . $product_id . ': ' . sanitize_text_field( (string) $category_assignment['error'] ) );
		}

		if ( ! $skip_images && ( $is_new_product || $this->product_images_field_present( $data ) ) && ( $is_new_product || $this->rules->should_update_images() ) && $this->product_images_need_processing( $product_id, $this->get_product_images_from_payload( $data ), $is_new_product ) ) {
			$image_result = $this->image_sync->process_images(
				$product_id,
				$this->get_product_images_from_payload( $data ),
				0,
				false
			);
			if ( ! empty( $image_result['error'] ) ) {
				$this->update_post_meta_if_changed( $product_id, 'mobo_sync_incomplete', '1' );
				$this->upsert_product_map( $product_guid, $product_id, true );
				throw new RuntimeException( 'Image queue persistence failed for product ' . $product_id . ': ' . sanitize_text_field( (string) $image_result['error'] ) );
			}
			if ( ! empty( $image_result['pending'] ) ) {
				if ( ! $this->mark_product_images_pending( $product_id, $this->get_product_images_from_payload( $data ) ) ) {
					$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
					$this->upsert_product_map( $product_guid, $product_id, true );
					throw new RuntimeException( 'Could not durably persist the pending image desired-state hash.' );
				}
			} elseif ( ! empty( $image_result['done'] ) && ! $this->mark_product_images_converged( $product_id, $this->get_product_images_from_payload( $data ) ) ) {
				$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
				$this->upsert_product_map( $product_guid, $product_id, true );
				throw new RuntimeException( 'Could not durably persist the converged image desired-state hash.' );
			}
		}

		/* Preserve embedded single-variant behavior. */
		if ( ! $this->product_payload_can_have_variants( $data, $product_id ) ) {
			$embedded_variant = $this->extract_embedded_simple_variant_data( $data );

			if ( ! empty( $embedded_variant ) ) {
				$embedded_result = $this->apply_simple_variant_to_product( $product_id, $embedded_variant, $product_guid );
				if ( is_wp_error( $embedded_result ) ) {
					$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
					$this->upsert_product_map( $product_guid, $product_id, true );
					throw new RuntimeException( $embedded_result->get_error_message() );
				}
			}
		}

		return $product_id;
	}

	/**
	 * Variation upsert plus the Source inactive-variant overlay (out of stock / not
	 * purchasable, never deleted). Unknown purchasability (legacy payload) changes nothing.
	 *
	 * @param WC_Product $parent Parent.
	 * @param array      $data Variant payload.
	 * @param string     $authoritative_product_guid Parent GUID.
	 * @return array
	 */
	private function upsert_variation( $parent, $data, $authoritative_product_guid = '' ) {
		$result = $this->upsert_variation_core( $parent, $data, $authoritative_product_guid );
		if ( is_array( $result ) && ! empty( $result['id'] ) ) {
			Mobo_Core_Source_Visibility::apply_inactive_flag( absint( $result['id'] ), Mobo_Core_Source_Visibility::payload_variant_purchasable( $data ) );
		}
		return $result;
	}

	private function upsert_variation_core( $parent, $data, $authoritative_product_guid = '' ) {
		if ( ! $parent instanceof WC_Product ) {
			return array(
				'id'      => 0,
				'changed' => false,
			);
		}

		$parent_id    = absint( $parent->get_id() );
		$variant_guid = $this->extract_variant_guid( $data );

		if ( $parent_id <= 0 || '' === $variant_guid ) {
			return array(
				'id'      => 0,
				'changed' => false,
			);
		}

		$money_integrity = $this->validate_money_payload_fields( $data, 'variation' );
		if ( is_wp_error( $money_integrity ) ) {
			throw new RuntimeException( $money_integrity->get_error_message() );
		}

		$product_guid  = $this->extract_product_guid( $data );
		$incoming_hash = $this->build_variation_source_hash( $data, $product_guid );

		if ( ! $this->is_repair_mode() && $this->is_remote_variation_trashed( $variant_guid ) ) {
			return array(
				'id'             => 0,
				'changed'        => false,
				'skipped_trash'  => true,
			);
		}

		/*
		 * Fail closed before mutating an existing variation. Mobo variants represent
		 * concrete selections, so every parent variation attribute must be present in
		 * each variant payload. Older Portal/title parsing could emit only a subset;
		 * accepting that payload permanently detached one WooCommerce attribute.
		 */
		$raw_attrs       = $this->get_value( $data, 'attributes', array() );
		$raw_integrity   = $this->validate_raw_variation_attribute_payload( $raw_attrs );
		if ( is_wp_error( $raw_integrity ) ) {
			update_post_meta( $parent_id, '_mobo_variant_attribute_drift_detected_at', gmdate( 'c' ) );
			update_post_meta( $parent_id, '_mobo_variant_attribute_drift_variant_guid', $variant_guid );
			update_post_meta( $parent_id, '_mobo_variant_attribute_drift_reason', sanitize_text_field( $raw_integrity->get_error_message() ) );
			if ( '' !== $product_guid && class_exists( 'Mobo_Core_Sync_Health' ) ) {
				Mobo_Core_Sync_Health::mark_behind( $product_guid, $parent_id );
			}
			throw new RuntimeException( $raw_integrity->get_error_message() );
		}

		$attrs           = $this->normalize_variation_attributes( $raw_attrs );
		$attrs_integrity = $this->validate_variation_attribute_completeness( $parent, $attrs );
		if ( is_wp_error( $attrs_integrity ) ) {
			update_post_meta( $parent_id, '_mobo_variant_attribute_drift_detected_at', gmdate( 'c' ) );
			update_post_meta( $parent_id, '_mobo_variant_attribute_drift_variant_guid', $variant_guid );
			update_post_meta( $parent_id, '_mobo_variant_attribute_drift_reason', sanitize_text_field( $attrs_integrity->get_error_message() ) );
			if ( '' !== $product_guid && class_exists( 'Mobo_Core_Sync_Health' ) ) {
				Mobo_Core_Sync_Health::mark_behind( $product_guid, $parent_id );
			}
			throw new RuntimeException( $attrs_integrity->get_error_message() );
		}

		$variation_id            = $this->find_variation_id_by_guid( $variant_guid );
		if ( $variation_id <= 0 && $this->is_repair_mode() ) {
			$variation_id = $this->restore_trashed_mobo_object_by_identity( 'product_variation', 'variant_guid', $variant_guid, $parent_id );
			if ( $variation_id < 0 ) {
				/* Merchant-trashed variation: keep it in Trash, same contract as normal sync. */
				return array(
					'id'            => 0,
					'changed'       => false,
					'skipped_trash' => true,
				);
			}
		}
		$matched_by_portal_id    = false;
		$matched_by_signature    = false;

		/* PortalVariantId is the concrete purchase identity. Prefer an existing
		 * sibling with that identity before falling back to storefront signature;
		 * this closes the race/crash window that previously created another local
		 * variation when a GUID map was temporarily missing. */
		if ( $variation_id <= 0 ) {
			$variation_id = $this->find_variation_id_by_portal_variant_id( $parent_id, $data );
			$matched_by_portal_id = $variation_id > 0;
		}

		if ( $variation_id <= 0 ) {
			$variation_id = $this->find_variation_id_by_attribute_signature( $parent_id, $data );
			$matched_by_signature = $variation_id > 0;
		}

		if ( $variation_id > 0 ) {
			$existing_parent_id = absint( wp_get_post_parent_id( $variation_id ) );
			if ( $existing_parent_id > 0 && $existing_parent_id !== $parent_id ) {
				throw new Mobo_Core_Variation_Parent_Conflict( $variant_guid, $variation_id, $existing_parent_id, $parent_id, '' !== $product_guid ? $product_guid : $authoritative_product_guid, $this->connection_id() );
			}
		}

		$is_new_variation = $variation_id <= 0;

		if ( ! $is_new_variation && $variation_id > 0 ) {
			$old_hash          = sanitize_text_field( (string) get_post_meta( $variation_id, '_mobo_variant_source_hash', true ) );
			$old_incomplete    = sanitize_text_field( (string) get_post_meta( $variation_id, 'mobo_sync_incomplete', true ) );
			$current_parent_id = absint( wp_get_post_parent_id( $variation_id ) );
			$current_parent_ok = 0 === $current_parent_id || $current_parent_id === $parent_id;

			if ( '' !== $incoming_hash && '1' !== $old_incomplete && $current_parent_ok && $this->noop_fast_path_allowed( $variation_id, $old_hash, $incoming_hash, 'variation' ) && $this->variation_frontend_state_matches_payload( $variation_id, $parent_id, $data ) ) {
				/*
				 * A remote variant may be recreated with a new GUID while keeping the
				 * exact same storefront attribute signature. In that case the existing
				 * WooCommerce variation is intentionally reused. The fast/no-op path
				 * must still move the local identity to the new GUID before authoritative
				 * missing-variant finalization runs; otherwise the reused variation still
				 * looks like the old, now-missing GUID and is deleted immediately after
				 * being matched.
				 */
				if ( ! $this->persist_post_meta_verified( $variation_id, 'variant_guid', $variant_guid ) || ! $this->store_portal_ids_on_variation_post( $variation_id, $data, $product_guid, $parent_id ) || ! $this->sync_price_source_meta_fast( $variation_id, $data ) || ! $this->persist_post_meta_verified( $variation_id, '_mobo_variant_source_hash', $incoming_hash ) ) {
					throw new RuntimeException( 'Could not durably refresh variation source bookkeeping on the no-op path.' );
				}
				$this->cleanup_stock_diagnostic_meta_fast( $variation_id, $data );
				if ( $old_hash !== $incoming_hash ) {
					$this->update_post_meta_if_changed( $variation_id, '_mobo_variant_source_hash_updated_at', gmdate( 'c' ) );
				}
				if ( $matched_by_signature || $matched_by_portal_id ) {
					$stored_guid = sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) );
					if ( $stored_guid !== $variant_guid || ! $this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, false ) ) {
						$this->update_post_meta_if_changed( $variation_id, 'mobo_sync_incomplete', '1' );
						throw new RuntimeException( 'Could not commit migrated variation identity.' );
					}
				}

				if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
					Mobo_Core_Runtime_Diagnostics::increment( 'variation_noop' );
				}
				if ( ! $this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, false ) ) {
					$this->update_post_meta_if_changed( $variation_id, 'mobo_sync_incomplete', '1' );
					throw new RuntimeException( 'Could not refresh variation mapping metadata.' );
				}
				return array(
					'id'      => $variation_id,
					'changed' => false,
				);
			}
		}

		if ( $variation_id > 0 ) {
			if ( ! $this->persist_post_meta_verified( $variation_id, 'mobo_sync_incomplete', '1' ) || ! $this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, true ) ) {
				throw new RuntimeException( 'Could not durably persist the variation crash marker before mutation.' );
			}
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				$variation = new WC_Product_Variation( $variation_id );
			}
		} else {
			$variation = new WC_Product_Variation();
		}

		if ( $is_new_variation ) {
			$initial_title = $this->source_post_title( $data );

			if ( '' === $initial_title ) {
				$initial_title = 'Mobo Variant ' . $variant_guid;
			}

			$variation->set_parent_id( $parent_id );
			$variation->set_status( 'publish' );
			$variation->set_name( $initial_title );
			$this->update_product_meta_if_changed( $variation, 'variant_guid', $variant_guid );
			$this->store_portal_variant_id_on_product_object( $variation, $data );
			$this->store_portal_product_id_on_product_object( $variation, $data, $parent_id );
			$variation->update_meta_data( 'mobo_sync_incomplete', '1' );

			if ( '' !== $product_guid ) {
				$this->update_product_meta_if_changed( $variation, 'product_guid', $product_guid );
			}
		} else {
			if ( absint( $variation->get_parent_id( 'edit' ) ) !== $parent_id ) {
				$variation->set_parent_id( $parent_id );
			}
		}

		if ( $is_new_variation || $this->rules->should_update_title() ) {
			$title = $this->source_post_title( $data );

			if ( '' !== $title && (string) $variation->get_name( 'edit' ) !== $title ) {
				$variation->set_name( $title );
			}
		}

		if ( $is_new_variation || $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) {
			$this->apply_price_to_product( $variation, $data, 'variation', $is_new_variation );
		}

		if ( $is_new_variation || $this->rules->should_update_stock() ) {
			$stock_present = false;
			$stock_value   = $this->get_stock_value_from_payload( $data, $stock_present );

			if ( $stock_present ) {
				if ( ! $this->is_valid_api_stock_payload_value( $stock_value ) ) {
					throw new RuntimeException( 'Invalid Mobo stock payload for variation.' );
				}

				$this->apply_api_stock( $variation, $stock_value );
			} elseif ( $is_new_variation ) {
				$this->apply_api_stock( $variation, null );
			}
		}

		if ( ! $this->variation_attributes_match_exact_payload( $this->get_persisted_variation_attributes_exact( $variation ), $attrs ) ) {
			$variation->set_attributes( $attrs );
		}

		$this->update_product_meta_if_changed( $variation, 'variant_guid', $variant_guid );
		$this->store_portal_variant_id_on_product_object( $variation, $data );
		$this->store_portal_product_id_on_product_object( $variation, $data, $parent_id );

		if ( '' !== $product_guid ) {
			$this->update_product_meta_if_changed( $variation, 'product_guid', $product_guid );
		}

		$variation_hash_changed = false;
		if ( '' !== $incoming_hash ) {
			$variation_hash_changed = $this->update_product_meta_if_changed( $variation, '_mobo_variant_source_hash', $incoming_hash );
			if ( $variation_hash_changed ) {
				$this->update_product_meta_if_changed( $variation, '_mobo_variant_source_hash_updated_at', gmdate( 'c' ) );
			}
		}
		$variation_id = absint( $variation->save() );
		if ( $variation_id <= 0 ) {
			throw new RuntimeException( 'WooCommerce variation save returned no durable post ID.' );
		}
		/* WooCommerce may hydrate a custom-attribute option through the parent and
		 * expose the canonical case in the product object even while the persisted
		 * attribute_* postmeta still contains legacy case. A normal CRUD save then
		 * sees no dirty property and leaves the raw storefront value stale. Verify
		 * and repair the durable variation attribute values explicitly. */
		if ( ! $this->persist_variation_attributes_exact( $variation_id, $attrs ) ) {
			$this->persist_post_meta_verified( $variation_id, 'mobo_sync_incomplete', '1' );
			throw new RuntimeException( 'Could not durably converge exact variation attribute values.' );
		}
		if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
			Mobo_Core_Runtime_Diagnostics::increment( 'variation_save' );
			if ( $is_new_variation ) {
				Mobo_Core_Runtime_Diagnostics::increment( 'variation_created' );
			}
		}

		if ( $variation_id > 0 ) {
			$this->remember_variation_signature( $parent_id, $variation_id, $attrs );
			if ( ! $this->persist_post_meta_verified( $variation_id, 'variant_guid', $variant_guid ) || ! $this->store_portal_ids_on_variation_post( $variation_id, $data, $product_guid, $parent_id ) || ! $this->sync_price_source_meta_fast( $variation_id, $data ) || ( '' !== $incoming_hash && ! $this->persist_post_meta_verified( $variation_id, '_mobo_variant_source_hash', $incoming_hash ) ) ) {
				$this->persist_post_meta_verified( $variation_id, 'mobo_sync_incomplete', '1' );
				throw new RuntimeException( 'Could not verify variation identity/source bookkeeping after WooCommerce save.' );
			}
			$stored_guid = sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) );
			if ( $stored_guid !== $variant_guid || ! $this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, true ) ) {
				$this->update_post_meta_if_changed( $variation_id, 'mobo_sync_incomplete', '1' );
				throw new RuntimeException( 'Could not commit variation identity mapping.' );
			}
			if ( ! $this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, false ) ) {
				$this->update_post_meta_if_changed( $variation_id, 'mobo_sync_incomplete', '1' );
				throw new RuntimeException( 'Could not finalize variation identity mapping.' );
			}
			if ( ! $this->persist_post_meta_verified( $variation_id, 'mobo_sync_incomplete', '0' ) ) {
				throw new RuntimeException( 'Could not durably persist the variation completion marker.' );
			}
		}

		return array(
			'id'      => $variation_id,
			'changed' => $variation_id > 0,
		);
	}



	private function store_portal_product_id_on_product_object( $product, $data, $fallback_product_id = 0 ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$portal_product_id   = $this->extract_portal_product_id( $data );
		$external_product_id = $this->extract_external_product_id( $data );

		/* An explicit generic provider identity means a missing numeric Portal ID is
		 * intentional. Do not resurrect an old Portal.ir ID from local fallback. */
		if ( $portal_product_id <= 0 && '' === $external_product_id && $fallback_product_id > 0 ) {
			$portal_product_id = $this->get_cached_parent_portal_product_id( $fallback_product_id );
		}

		if ( $portal_product_id <= 0 ) {
			if ( '' !== $external_product_id ) {
				foreach ( array( 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id' ) as $legacy_key ) {
					$product->delete_meta_data( $legacy_key );
				}
			}
			return;
		}

		$this->update_product_meta_if_changed( $product, 'portal_product_id', $portal_product_id );
		$this->update_product_meta_if_changed( $product, 'mobo_portal_product_id', $portal_product_id );
		$this->update_product_meta_if_changed( $product, '_mobo_portal_product_id', $portal_product_id );
	}

	private function store_external_product_id_on_product_object( $product, $data ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$external_product_id = $this->extract_external_product_id( $data );
		if ( '' !== $external_product_id ) {
			$this->update_product_meta_if_changed( $product, '_mobo_external_product_id', $external_product_id );
		}
	}

	private function store_portal_variant_id_on_product_object( $product, $data ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$portal_variant_id = $this->extract_portal_variant_id( $data );

		if ( $portal_variant_id <= 0 ) {
			return;
		}

		$this->update_product_meta_if_changed( $product, 'portal_variant_id', $portal_variant_id );
		$this->update_product_meta_if_changed( $product, 'mobo_portal_variant_id', $portal_variant_id );
		$this->update_product_meta_if_changed( $product, '_mobo_portal_variant_id', $portal_variant_id );
	}

	private function store_portal_ids_on_variation_post( $variation_id, $data, $product_guid = '', $parent_id = 0 ) {
		$variation_id = absint( $variation_id );

		if ( $variation_id <= 0 ) {
			return false;
		}

		$external_variant_id = $this->extract_external_variant_id( $data );
		if ( '' !== $external_variant_id && ! $this->persist_post_meta_verified( $variation_id, '_mobo_external_variant_id', $external_variant_id ) ) {
			return false;
		}

		$portal_variant_id = $this->extract_portal_variant_id( $data );

		if ( $portal_variant_id > 0 ) {
			foreach ( array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' ) as $key ) {
				if ( ! $this->persist_post_meta_verified( $variation_id, $key, $portal_variant_id ) ) {
					return false;
				}
			}
		} elseif ( '' !== $external_variant_id ) {
			foreach ( array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' ) as $key ) {
				if ( ! $this->delete_post_meta_verified( $variation_id, $key ) ) {
					return false;
				}
			}
		}

		$external_product_id = $this->extract_external_product_id( $data );
		if ( '' !== $external_product_id && ! $this->persist_post_meta_verified( $variation_id, '_mobo_external_product_id', $external_product_id ) ) {
			return false;
		}

		$portal_product_id = $this->extract_portal_product_id( $data );

		if ( $portal_product_id <= 0 && '' === $external_product_id && $parent_id > 0 ) {
			$portal_product_id = $this->get_cached_parent_portal_product_id( $parent_id );
		}

		if ( $portal_product_id > 0 ) {
			foreach ( array( 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id' ) as $key ) {
				if ( ! $this->persist_post_meta_verified( $variation_id, $key, $portal_product_id ) ) {
					return false;
				}
			}
		} elseif ( '' !== $external_product_id ) {
			foreach ( array( 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id' ) as $key ) {
				if ( ! $this->delete_post_meta_verified( $variation_id, $key ) ) {
					return false;
				}
			}
		}

		if ( '' !== $product_guid && ! $this->persist_post_meta_verified( $variation_id, 'product_guid', sanitize_text_field( (string) $product_guid ) ) ) {
			return false;
		}

		return true;
	}

	private function product_attribute_structure_changed( $product, $desired_attributes ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$current = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$current[] = sanitize_title( (string) $attribute->get_name() );
		}

		$desired = array();
		foreach ( $this->normalize_product_attributes_for_hash( $desired_attributes ) as $attribute ) {
			$desired[] = sanitize_title( (string) ( isset( $attribute['name'] ) ? $attribute['name'] : '' ) );
		}

		$current = array_values( array_unique( array_filter( $current ) ) );
		$desired = array_values( array_unique( array_filter( $desired ) ) );
		sort( $current, SORT_STRING );
		sort( $desired, SORT_STRING );

		return $current !== $desired;
	}

	private function apply_desired_product_attributes( $product_id, $attributes ) {
		$product_id = absint( $product_id );
		$product    = wc_get_product( $product_id );
		if ( $product_id <= 0 || ! $product instanceof WC_Product ) {
			return false;
		}

		if ( $this->product_attributes_match_payload( $product, $attributes ) ) {
			if ( ! $this->store_product_attribute_guids_if_changed( $product_id, $attributes ) ) {
				throw new RuntimeException( 'Product attribute identity metadata failed verification.' );
			}
			return false;
		}

		$product->set_default_attributes( array() );
		$product->set_attributes( $this->build_product_attributes( $attributes ) );
		$saved_id = absint( $product->save() );
		if ( $saved_id !== $product_id ) {
			throw new RuntimeException( 'WooCommerce did not persist the desired product attributes.' );
		}
		$fresh = wc_get_product( $product_id );
		if ( ! $fresh instanceof WC_Product || ! $this->product_attributes_match_payload( $fresh, $attributes ) ) {
			throw new RuntimeException( 'WooCommerce product attributes failed their post-write verification.' );
		}
		if ( ! $this->store_product_attribute_guids( $product_id, $attributes ) ) {
			throw new RuntimeException( 'Product attribute identity metadata failed verification after attribute save.' );
		}
		return true;
	}

	/**
	 * Return live variation children by post_parent regardless of the parent WC type.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function get_live_variation_children( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_parent'    => $product_id,
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		return array_values( array_filter( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
	}


	/**
	 * Return Trash children whose shared quarantine marker proves a prior retirement
	 * attempt already crossed the storefront boundary but still needs idempotent
	 * Product Map/identity cleanup. Manually trashed variations without this marker
	 * are deliberately excluded.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function get_pending_quarantine_children( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_parent'    => $product_id,
				'post_status'    => array( 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => '_mobo_variation_quarantine_reason',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return array_values( array_filter( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
	}

	/**
	 * Move one Mobo-owned variation to Trash/quarantine using the shared crash-safe policy.
	 *
	 * @param int    $variation_id Variation ID.
	 * @param string $reason Reason key.
	 * @param array  $context Context.
	 * @return true|WP_Error
	 */
	private function quarantine_variation( $variation_id, $reason, $context = array() ) {
		$variation_id = absint( $variation_id );
		if ( $variation_id <= 0 || ! class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ) {
			return new WP_Error( 'mobo_core_variation_lifecycle_unavailable', 'Variation lifecycle policy is unavailable.' );
		}
		$parent_id = absint( wp_get_post_parent_id( $variation_id ) );
		if ( $parent_id > 0 ) {
			unset( $this->variation_signature_index[ $parent_id ], $this->variation_signature_reverse[ $parent_id ] );
		}
		return Mobo_Core_Variation_Lifecycle_Policy::quarantine( $variation_id, $reason, $context, $this->product_map );
	}

	/**
	 * Prime all local identities/meta needed by one UpdateVariant page.
	 *
	 * @param int   $parent_id Parent product ID.
	 * @param array $variants Variant payload list.
	 * @return void
	 */
	private function prime_variation_page_context( $parent_id, $variants ) {
		$parent_id = absint( $parent_id );
		$guids     = array();
		foreach ( is_array( $variants ) ? $variants : array() as $variant_data ) {
			if ( ! is_array( $variant_data ) ) {
				continue;
			}
			$guid = $this->extract_variant_guid( $variant_data );
			if ( '' !== $guid ) {
				$guids[ $guid ] = true;
			}
		}

		if ( ! empty( $guids ) && $this->product_map instanceof Mobo_Core_Product_Map && method_exists( $this->product_map, 'prime_variation_ids' ) ) {
			$this->product_map->prime_variation_ids( array_keys( $guids ) );
		}

		if ( $parent_id > 0 ) {
			$this->get_cached_parent_portal_product_id( $parent_id );
		}
	}

	/**
	 * Cache parent portal product identity for all variations processed in this request.
	 *
	 * @param int $parent_id Parent product ID.
	 * @return int
	 */
	private function get_cached_parent_portal_product_id( $parent_id ) {
		$parent_id = absint( $parent_id );
		if ( $parent_id <= 0 ) {
			return 0;
		}
		if ( ! array_key_exists( $parent_id, $this->parent_portal_product_id_cache ) ) {
			$this->parent_portal_product_id_cache[ $parent_id ] = absint( get_post_meta( $parent_id, 'portal_product_id', true ) );
		}
		return absint( $this->parent_portal_product_id_cache[ $parent_id ] );
	}

	/**
	 * Keep an already-built signature index current after a variation save.
	 *
	 * @param int   $parent_id Parent product ID.
	 * @param int   $variation_id Variation ID.
	 * @param array $attrs Normalized attributes.
	 * @return void
	 */
	private function remember_variation_signature( $parent_id, $variation_id, $attrs ) {
		$parent_id    = absint( $parent_id );
		$variation_id = absint( $variation_id );
		if ( $parent_id <= 0 || $variation_id <= 0 || ! array_key_exists( $parent_id, $this->variation_signature_index ) ) {
			return;
		}
		/* The reverse map tracks only actual signature winners. A losing duplicate
		 * must never erase the winning variation's lookup on a later update. */
		$old_signature = isset( $this->variation_signature_reverse[ $parent_id ][ $variation_id ] )
			? (string) $this->variation_signature_reverse[ $parent_id ][ $variation_id ] : '';
		if ( '' !== $old_signature && isset( $this->variation_signature_index[ $parent_id ][ $old_signature ] )
			&& absint( $this->variation_signature_index[ $parent_id ][ $old_signature ] ) === $variation_id ) {
			unset( $this->variation_signature_index[ $parent_id ][ $old_signature ] );
		}
		unset( $this->variation_signature_reverse[ $parent_id ][ $variation_id ] );
		$signature = $this->variation_attribute_signature( $attrs );
		if ( '' !== $signature ) {
			$previous_winner = isset( $this->variation_signature_index[ $parent_id ][ $signature ] )
				? absint( $this->variation_signature_index[ $parent_id ][ $signature ] ) : 0;
			if ( $previous_winner > 0 && $previous_winner !== $variation_id ) {
				unset( $this->variation_signature_reverse[ $parent_id ][ $previous_winner ] );
			}
			$this->variation_signature_index[ $parent_id ][ $signature ] = $variation_id;
			$this->variation_signature_reverse[ $parent_id ][ $variation_id ] = $signature;
		}

	}

	private function find_variation_id_by_attribute_signature( $parent_id, $data ) {
		$parent_id = absint( $parent_id );
		$incoming  = $this->variation_attribute_signature( $this->normalize_variation_attributes( $this->get_value( $data, 'attributes', array() ) ) );
		if ( $parent_id <= 0 || '' === $incoming ) {
			return 0;
		}

		if ( ! array_key_exists( $parent_id, $this->variation_signature_index ) ) {
			$index = array();
			$children = get_posts(
				array(
					'post_type'              => 'product_variation',
					'post_parent'            => $parent_id,
					'post_status'            => array( 'publish', 'private', 'draft', 'pending' ),
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			$children = array_values( array_filter( array_map( 'absint', is_array( $children ) ? $children : array() ) ) );
			if ( ! empty( $children ) ) {
				update_meta_cache( 'post', $children );
			}

			foreach ( $children as $variation_id ) {
				$all_meta = get_post_meta( $variation_id );
				$attrs    = array();
				if ( is_array( $all_meta ) ) {
					foreach ( $all_meta as $meta_key => $values ) {
						if ( 0 !== strpos( (string) $meta_key, 'attribute_' ) ) {
							continue;
						}
						$value = is_array( $values ) && isset( $values[0] ) ? $values[0] : '';
						if ( is_scalar( $value ) && '' !== (string) $value ) {
							$attrs[ $meta_key ] = (string) $value;
						}
					}
				}
				$signature = $this->variation_attribute_signature( $attrs );
				if ( '' !== $signature && ! isset( $index[ $signature ] ) ) {
					$index[ $signature ] = $variation_id;
				}
			}

			$this->variation_signature_index[ $parent_id ] = $index;
			$this->variation_signature_reverse[ $parent_id ] = array();
			foreach ( $index as $known_signature => $winner_id ) {
				$this->variation_signature_reverse[ $parent_id ][ absint( $winner_id ) ] = (string) $known_signature;
			}
		}

		return isset( $this->variation_signature_index[ $parent_id ][ $incoming ] )
			? absint( $this->variation_signature_index[ $parent_id ][ $incoming ] )
			: 0;
	}

	private function variation_attribute_signature( $attributes ) {
	return Mobo_Core_Variation_Identity_Policy::attribute_signature( $attributes );
}

	/**
	 * Compare the persisted storefront variation selection with the desired payload
	 * without collapsing value case through sanitize_title(). Identity matching may
	 * intentionally use a slug signature, but WooCommerce custom-attribute option
	 * membership is value-sensitive. A legacy `Airpods 3` child under a parent whose
	 * authoritative option is `airpods 3` must therefore be rewritten, not treated as
	 * a no-op merely because both values produce the same identity slug.
	 *
	 * @param mixed $current Persisted WooCommerce variation attributes.
	 * @param mixed $desired Normalized desired variation attributes.
	 * @return bool
	 */
	/**
	 * Read the durable variation attribute_* values instead of trusting the
	 * hydrated WC_Product_Variation attribute property. WooCommerce may resolve a
	 * custom option against the parent and hide case-only drift in raw postmeta.
	 *
	 * @param WC_Product_Variation|int $variation Variation object or ID.
	 * @return array
	 */
	private function get_persisted_variation_attributes_exact( $variation ) {
		global $wpdb;

		$variation_id = is_numeric( $variation )
			? absint( $variation )
			: ( $variation instanceof WC_Product_Variation ? absint( $variation->get_id() ) : 0 );
		if ( $variation_id <= 0 ) {
			return array();
		}

		/* Read the durable rows directly. WC_Product_Variation hydration is allowed to
		 * normalize option values and therefore cannot be used as exact persistence
		 * evidence for case-only storefront convergence. */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s ORDER BY meta_id ASC",
				$variation_id,
				$wpdb->esc_like( 'attribute_' ) . '%'
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$result = array();
		foreach ( $rows as $row ) {
			$raw_key = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';
			if ( 0 !== strpos( $raw_key, 'attribute_' ) ) {
				continue;
			}
			$key = sanitize_title( substr( $raw_key, strlen( 'attribute_' ) ) );
			if ( '' === $key ) {
				continue;
			}
			/* Duplicate normalized keys are not exact convergence. Preserve an extra
			 * sentinel key so equality checks fail closed until canonicalized. */
			if ( array_key_exists( $key, $result ) ) {
				$result[ '__mobo_duplicate__' . $key ] = sanitize_text_field( (string) ( $row['meta_value'] ?? '' ) );
				continue;
			}
			$result[ $key ] = sanitize_text_field( (string) ( $row['meta_value'] ?? '' ) );
		}

		return $result;
	}

	/**
	 * Persist exact custom-attribute option values when WooCommerce's hydrated
	 * object has already canonicalized them and therefore does not mark a case-only
	 * change dirty. Identity matching remains slug-normalized; this helper only
	 * converges the durable storefront value after the ordinary variation save.
	 *
	 * @param int   $variation_id Variation ID.
	 * @param array $desired Normalized desired attributes.
	 * @return bool
	 */
	private function persist_variation_attributes_exact( $variation_id, $desired ) {
		global $wpdb;

		$variation_id = absint( $variation_id );
		if ( $variation_id <= 0 || ! is_array( $desired ) ) {
			return false;
		}

		$normalized_desired = array();
		foreach ( $desired as $key => $value ) {
			$key = preg_replace( '/^attribute_/', '', sanitize_title( (string) $key ) );
			$value = sanitize_text_field( (string) $value );
			if ( '' === $key || '' === $value ) {
				return false;
			}
			$normalized_desired[ $key ] = $value;
		}
		if ( empty( $normalized_desired ) ) {
			return false;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s ORDER BY meta_id ASC",
				$variation_id,
				$wpdb->esc_like( 'attribute_' ) . '%'
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return false;
		}

		$groups = array();
		foreach ( $rows as $row ) {
			$meta_id = absint( $row['meta_id'] ?? 0 );
			$raw_key = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';
			if ( $meta_id <= 0 || 0 !== strpos( $raw_key, 'attribute_' ) ) {
				continue;
			}
			$key = sanitize_title( substr( $raw_key, strlen( 'attribute_' ) ) );
			if ( '' === $key ) {
				continue;
			}
			$groups[ $key ][] = $row;
		}

		foreach ( $normalized_desired as $key => $value ) {
			$canonical_meta_key = 'attribute_' . $key;
			$matches = isset( $groups[ $key ] ) && is_array( $groups[ $key ] ) ? $groups[ $key ] : array();
			$keep = null;
			foreach ( $matches as $row ) {
				if ( isset( $row['meta_key'] ) && $canonical_meta_key === (string) $row['meta_key'] ) {
					$keep = $row;
					break;
				}
			}
			if ( null === $keep && ! empty( $matches ) ) {
				$keep = reset( $matches );
			}

			if ( is_array( $keep ) && absint( $keep['meta_id'] ?? 0 ) > 0 ) {
				$meta_id = absint( $keep['meta_id'] );
				$updated = update_metadata_by_mid( 'post', $meta_id, $value, $canonical_meta_key );
				if ( false === $updated ) {
					/* update_metadata_by_mid() returns false both for failure and for a
					 * byte-identical no-op. Verify the durable row before failing. */
					$verified = $wpdb->get_row( $wpdb->prepare( "SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE meta_id=%d", $meta_id ), ARRAY_A );
					if ( ! is_array( $verified ) || $canonical_meta_key !== (string) ( $verified['meta_key'] ?? '' ) || $value !== (string) ( $verified['meta_value'] ?? '' ) ) {
						return false;
					}
				}
				foreach ( $matches as $row ) {
					$duplicate_id = absint( $row['meta_id'] ?? 0 );
					if ( $duplicate_id > 0 && $duplicate_id !== $meta_id && ! delete_metadata_by_mid( 'post', $duplicate_id ) ) {
						return false;
					}
				}
			} else {
				$added = add_post_meta( $variation_id, $canonical_meta_key, $value, true );
				if ( false === $added ) {
					return false;
				}
			}
		}

		/* An authoritative concrete Variant payload must not retain stale extra
		 * attribute_* selections from an older topology. Remove only normalized keys
		 * that are absent from the validated desired set. */
		foreach ( $groups as $key => $group_rows ) {
			if ( isset( $normalized_desired[ $key ] ) ) {
				continue;
			}
			foreach ( $group_rows as $row ) {
				$meta_id = absint( $row['meta_id'] ?? 0 );
				if ( $meta_id > 0 && ! delete_metadata_by_mid( 'post', $meta_id ) ) {
					return false;
				}
			}
		}

		clean_post_cache( $variation_id );
		$reloaded = wc_get_product( $variation_id );
		if ( ! $reloaded instanceof WC_Product_Variation ) {
			return false;
		}
		$parent_id = absint( $reloaded->get_parent_id() );
		if ( $parent_id > 0 ) {
			wc_delete_product_transients( $parent_id );
		}

		return $this->variation_attributes_match_exact_payload( $this->get_persisted_variation_attributes_exact( $variation_id ), $normalized_desired );
	}

	private function variation_attributes_match_exact_payload( $current, $desired ) {
		$normalize = static function( $attributes ) {
			$result = array();
			if ( ! is_array( $attributes ) ) {
				return $result;
			}
			foreach ( $attributes as $key => $value ) {
				$key   = preg_replace( '/^attribute_/', '', sanitize_title( (string) $key ) );
				$value = sanitize_text_field( (string) $value );
				if ( '' !== $key && '' !== $value ) {
					$result[ $key ] = $value;
				}
			}
			ksort( $result, SORT_STRING );
			return $result;
		};

		return $normalize( $current ) === $normalize( $desired );
	}

	/**
	 * Whether a ProductUpdated payload contains every field that contributes to the
	 * canonical parent source hash. Partial payloads may mutate explicit fields, but
	 * must never replace the last complete-snapshot hash with a partial-state hash.
	 */
	private function product_payload_has_complete_source_hash_state( $data ) {
		if ( ! is_array( $data ) || ! $this->product_attributes_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_title() && ! $this->product_title_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_price() && ! $this->product_price_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_compare_price() && ! $this->product_compare_price_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_stock() ) {
			$stock_present = false;
			$this->get_stock_value_from_payload( $data, $stock_present );
			if ( ! $stock_present ) {
				return false;
			}
		}
		if ( $this->rules->should_update_slug() && ! $this->product_slug_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_categories() && ! $this->product_categories_field_present( $data ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Same completeness boundary for one variation snapshot. Attributes are identity
	 * state and every enabled storefront field must be present before replacing the
	 * canonical variation hash.
	 */
	private function variation_payload_has_complete_source_hash_state( $data ) {
		if ( ! is_array( $data ) || ! $this->product_attributes_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_title() && ! $this->product_title_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_price() && ! $this->product_price_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_compare_price() && ! $this->product_compare_price_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_stock() ) {
			$stock_present = false;
			$this->get_stock_value_from_payload( $data, $stock_present );
			if ( ! $stock_present ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build a deterministic hash for Mobo-controlled parent storefront fields.
	 * Internal identity metadata and images are intentionally excluded: identity
	 * can be repaired without WC CRUD, while images have their own convergence hash.
	 */
	private function build_product_source_hash( $data, $product_guid = '' ) {
		if ( ! is_array( $data ) || ! $this->product_payload_has_complete_source_hash_state( $data ) ) {
			return '';
		}

		$hash_data = array(
			'productId'        => sanitize_text_field( (string) $product_guid ),
			'updateTitle'      => $this->rules->should_update_title() ? 1 : 0,
			'updatePrice'      => $this->rules->should_update_price() ? 1 : 0,
			'updateCompare'    => $this->rules->should_update_compare_price() ? 1 : 0,
			'updateStock'      => $this->rules->should_update_stock() ? 1 : 0,
			'updateSlug'       => $this->rules->should_update_slug() ? 1 : 0,
			'updateCategories' => $this->rules->should_update_categories() ? 1 : 0,
		);

		if ( $this->product_attributes_field_present( $data ) ) {
			$hash_data['attributes'] = $this->normalize_product_attributes_for_hash( $this->get_value( $data, 'attributes', array() ) );
		}

		if ( $this->rules->should_update_title() && $this->product_title_field_present( $data ) ) {
			$hash_data['title'] = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );
		}
		if ( ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) && ( $this->product_price_field_present( $data ) || $this->product_compare_price_field_present( $data ) ) ) {
			if ( $this->product_price_field_present( $data ) ) {
				$hash_data['price'] = $this->get_value( $data, 'price', null );
			}
			if ( $this->product_compare_price_field_present( $data ) ) {
				$hash_data['comparePrice'] = $this->get_compare_price_field_value( $data, null );
			}
			$hash_data['pricePolicyHash'] = $this->build_price_policy_hash();
		}
		if ( $this->rules->should_update_stock() ) {
			$present = false;
			$stock_value = $this->get_stock_value_from_payload( $data, $present );
			if ( $present ) {
				$hash_data['stock'] = $stock_value;
			}
		}
		if ( $this->rules->should_update_slug() && $this->product_slug_field_present( $data ) ) {
			$hash_data['slug'] = $this->expected_product_slug( $data );
		}
		if ( $this->rules->should_update_categories() && $this->product_categories_field_present( $data ) ) {
			$hash_data['categories'] = $this->normalize_category_guids_for_hash( $this->get_product_category_refs_from_payload( $data ) );
		}

		$json = wp_json_encode( $this->sort_array_for_hash( $hash_data ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false === $json ? '' : md5( $json );
	}

	/**
	 * Whether a WooCommerce object already renders the calculated price pair.
	 * An "unavailable" pair (Source has no price) matches only an object whose
	 * regular, sale and active prices are all empty (not purchasable).
	 *
	 * @param WC_Product $object Product/variation.
	 * @param array      $pair   Calculator result.
	 * @return bool
	 */
	private function price_pair_matches_object( $object, $pair ) {
		if ( ! $object instanceof WC_Product || ! is_array( $pair ) ) {
			return false;
		}
		if ( ! empty( $pair['unavailable'] ) ) {
			return '' === (string) $object->get_regular_price( 'edit' )
				&& '' === (string) $object->get_sale_price( 'edit' )
				&& '' === (string) $object->get_price( 'edit' );
		}
		if ( null !== $pair['regular_price'] && '' !== $pair['regular_price'] && wc_format_decimal( $object->get_regular_price() ) !== wc_format_decimal( $pair['regular_price'] ) ) {
			return false;
		}
		if ( isset( $pair['sale_price'] ) && wc_format_decimal( $object->get_sale_price() ) !== wc_format_decimal( $pair['sale_price'] ) ) {
			return false;
		}
		return true;
	}

	private function product_frontend_state_matches_payload( $product, $data ) {
		if ( ! $product instanceof WC_Product || ! is_array( $data ) ) {
			return false;
		}

		if ( $this->rules->should_update_title() ) {
			$title = $this->source_post_title( $data );
			if ( '' !== $title && (string) $product->get_name() !== $title ) {
				return false;
			}
		}

		if ( $this->rules->should_update_slug() ) {
			$slug = $this->expected_product_slug( $data );
			if ( '' !== $slug && ! $this->product_slug_matches_expected( $product, $slug ) ) {
				return false;
			}
		}

		if ( $this->rules->should_update_stock() ) {
			$present = false;
			$stock   = $this->get_stock_value_from_payload( $data, $present );
			if ( $present && ! $this->product_stock_matches_value( $product, $stock ) ) {
				return false;
			}
		}

		if ( ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) && ( $this->product_price_field_present( $data ) || $this->product_compare_price_field_present( $data ) ) ) {
			$product_id      = absint( $product->get_id() );
			$raw_price       = $this->product_price_field_present( $data ) ? $this->get_value( $data, 'price', null ) : get_post_meta( $product_id, 'mobo_api_price', true );
			$raw_compare     = $this->product_compare_price_field_present( $data ) ? $this->get_compare_price_field_value( $data, null ) : get_post_meta( $product_id, 'mobo_api_compare_price', true );
			if ( ! $this->product_price_field_present( $data ) && ( null === $raw_price || '' === $raw_price ) ) {
				/* Compare-price-only partial payload cannot be rendered safely without the
				 * source price. Preserve storefront prices; fast bookkeeping still stores
				 * the explicit comparePrice for the next complete price payload. */
			} else {
			$pair = $this->price_calculator->calculate_price_pair(
				$product_id,
				$raw_price,
				$raw_compare,
				'product'
			);
			if ( ! empty( $pair['error'] ) ) {
				return false;
			}
			if ( ! $this->price_pair_matches_object( $product, $pair ) ) {
				return false;
			}
			}
		}

		if ( $this->product_attributes_field_present( $data ) && ! $this->product_attributes_match_payload( $product, $this->get_value( $data, 'attributes', array() ) ) ) {
			return false;
		}

		$published_at = sanitize_text_field( (string) $this->get_value( $data, 'publishedAt', '' ) );
		if ( '' !== $published_at && sanitize_text_field( (string) get_post_meta( $product->get_id(), 'published_at', true ) ) !== $published_at ) {
			return false;
		}

		if ( $this->rules->should_update_categories() && $this->product_categories_field_present( $data ) ) {
			$current = get_post_meta( $product->get_id(), 'mobo_product_category_guids', true );
			$current = is_array( $current ) ? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $current ) ) ) ) : array();
			sort( $current, SORT_STRING );
			if ( $current !== $this->normalize_category_guids_for_hash( $this->get_product_category_refs_from_payload( $data ) ) ) {
				return false;
			}
		}

		return true;
	}

	private function product_stock_matches_value( $product, $stock ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		/* Read-side convergence mirrors apply_api_stock() exactly and reads the
		 * PERSISTED ('edit') props: the view-context Source visibility filter must not
		 * make a hidden product look permanently divergent (B14). */
		$normalized = null;
		if ( null !== $stock && '' !== $stock ) {
			$normalized = $this->normalize_api_stock_quantity( $stock );
			if ( null === $normalized ) {
				return false;
			}
		} elseif ( ! $this->product_has_known_stock_state( $product ) ) {
			return false;
		}

		$natural = Mobo_Core_Stock_Policy::natural_state( $normalized, true, $this->current_natural_stock_state( $product ) );
		$blocked = Mobo_Core_Source_Visibility::is_blocked( $product );
		$stored  = Mobo_Core_Source_Visibility::stored_natural_stock( $product );
		if ( $blocked ? ( null === $stored || Mobo_Core_Stock_Policy::normalize_state( $natural ) !== $stored ) : null !== $stored ) {
			return false;
		}
		return Mobo_Core_Stock_Policy::props_match(
			array(
				'manage'     => (bool) $product->get_manage_stock( 'edit' ),
				'qty'        => $product->get_stock_quantity( 'edit' ),
				'status'     => (string) $product->get_stock_status( 'edit' ),
				'backorders' => (string) $product->get_backorders( 'edit' ),
			),
			Mobo_Core_Stock_Policy::persisted_state( $natural, $blocked )
		);
	}

	private function expected_product_slug( $data ) {
		$slug = sanitize_title( (string) $this->get_value( $data, 'slug', '' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( trim( (string) $this->get_value( $data, 'url', '' ), '/' ) );
		}
		return $slug;
	}

	private function normalize_product_attributes_for_hash( $attributes ) {
		$result   = array();
		$position = 0;

		foreach ( is_array( $attributes ) ? $attributes : array() as $attribute_data ) {
			if ( ! is_array( $attribute_data ) ) {
				continue;
			}
			$name = $this->source_attribute_text( $this->get_value( $attribute_data, 'name', '' ) );
			if ( '' === $name ) {
				continue;
			}
			$values = $this->get_value( $attribute_data, 'values', array() );
			if ( ! is_array( $values ) ) {
				continue;
			}

			$options = array();
			foreach ( $values as $value_data ) {
				if ( ! is_array( $value_data ) ) {
					continue;
				}
				$value = $this->source_attribute_text( $this->get_value( $value_data, 'value', '' ) );
				if ( '' !== $value ) {
					$options[] = $value;
				}
			}
			$options = array_values( array_unique( $options ) );
			if ( empty( $options ) ) {
				continue;
			}

			$result[] = array(
				'name'      => sanitize_title( $name ),
				'options'   => array_values( array_map( 'strval', $options ) ),
				'position'  => $position,
				'visible'   => 1,
				'variation' => 1,
			);
			$position++;
		}

		return $result;
	}

	private function current_product_attributes_for_hash( $product ) {
		$result = array();
		if ( ! $product instanceof WC_Product ) {
			return $result;
		}
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			$options = array_values( array_map( 'strval', $attribute->get_options() ) );
			$result[] = array(
				'name'      => sanitize_title( (string) $attribute->get_name() ),
				'options'   => $options,
				'position'  => absint( $attribute->get_position() ),
				'visible'   => $attribute->get_visible() ? 1 : 0,
				'variation' => $attribute->get_variation() ? 1 : 0,
			);
		}
		return $result;
	}

	private function product_attributes_match_payload( $product, $attributes ) {
		$current = $this->current_product_attributes_for_hash( $product );
		$desired = $this->normalize_product_attributes_for_hash( $attributes );
		/* utf8mb3 sites: the Unicode guard stores four-byte characters as numeric
		 * entities (&#x1f600;), but WooCommerce's reader html-decodes custom
		 * attribute values (wc_get_text_attributes). Compare both sides in the
		 * guard's canonical form, otherwise every emoji value fails post-write
		 * verification and the variable product can never be built. */
		if ( class_exists( 'Mobo_Core_Unicode_Guard' ) ) {
			$current = Mobo_Core_Unicode_Guard::canonical( $current );
			$desired = Mobo_Core_Unicode_Guard::canonical( $desired );
		}
		return $current === $desired;
	}

	private function normalize_category_guids_for_hash( $refs ) {
		$guids = array();
		if ( is_array( $refs ) ) {
			foreach ( $refs as $ref ) {
				if ( is_array( $ref ) ) {
					$guid = $this->extract_category_guid_for_storage( $ref );
				} else {
					$guid = sanitize_text_field( (string) $ref );
				}
				if ( '' !== $guid && $this->is_remote_guid_value( $guid ) ) {
					$guids[] = $guid;
				}
			}
		}
		$guids = array_values( array_unique( array_filter( $guids ) ) );
		sort( $guids, SORT_STRING );
		return $guids;
	}

	private function sync_product_identity_meta_fast( $product_id, $data, $product_guid ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}
		if ( ! $this->persist_post_meta_verified( $product_id, 'product_guid', sanitize_text_field( (string) $product_guid ) ) ) {
			return false;
		}
		$external_product_id = $this->extract_external_product_id( $data );
		if ( '' !== $external_product_id && ! $this->persist_post_meta_verified( $product_id, '_mobo_external_product_id', $external_product_id ) ) {
			return false;
		}
		$portal_product_id = $this->extract_portal_product_id( $data );
		if ( $portal_product_id > 0 ) {
			foreach ( array( 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id' ) as $key ) {
				if ( ! $this->persist_post_meta_verified( $product_id, $key, $portal_product_id ) ) {
					return false;
				}
			}
		} elseif ( '' !== $external_product_id ) {
			foreach ( array( 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id' ) as $key ) {
				if ( ! $this->delete_post_meta_verified( $product_id, $key ) ) {
					return false;
				}
			}
		}
		$url = class_exists( 'Mobo_Core_Product_Exclusions' ) ? Mobo_Core_Product_Exclusions::sanitize_source_url( $this->get_value( $data, 'url', '' ) ) : sanitize_text_field( (string) $this->get_value( $data, 'url', '' ) );
		if ( '' !== $url && ! $this->persist_post_meta_verified( $product_id, 'mobo_url', $url ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Persist source-price bookkeeping without invoking WooCommerce CRUD/cache hooks.
	 * Used only after the rendered price has already been verified as converged.
	 *
	 * @param int   $post_id Product/variation ID.
	 * @param array $data Source payload.
	 * @return bool True only when all correctness-relevant bookkeeping converged.
	 */
	private function sync_price_source_meta_fast( $post_id, $data ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! is_array( $data ) ) {
			return false;
		}
		if ( ! $this->rules->should_update_price() && ! $this->rules->should_update_compare_price() ) {
			return true;
		}

		$price_present   = $this->product_price_field_present( $data );
		$compare_present = $this->product_compare_price_field_present( $data );
		if ( ! $price_present && ! $compare_present ) {
			return true;
		}

		$raw_price   = $price_present ? $this->get_value( $data, 'price', null ) : get_post_meta( $post_id, 'mobo_api_price', true );
		$raw_compare = $compare_present ? $this->get_compare_price_field_value( $data, null ) : get_post_meta( $post_id, 'mobo_api_compare_price', true );
		$changed     = false;
		/* PERF03: only contiguous price-source bookkeeping is batched. Crash
		 * markers and source ordering watermarks retain individual readback. */
		$price_source_updates = array();

		if ( $price_present ) {
			$desired = null === $raw_price || '' === $raw_price ? '' : wc_format_decimal( $raw_price );
			$changed = maybe_serialize( get_post_meta( $post_id, 'mobo_api_price', true ) ) !== maybe_serialize( $desired ) || $changed;
			$price_source_updates['mobo_api_price'] = $desired;
		}
		if ( $compare_present ) {
			$desired = null === $raw_compare || '' === $raw_compare ? '' : wc_format_decimal( $raw_compare );
			$changed = maybe_serialize( get_post_meta( $post_id, 'mobo_api_compare_price', true ) ) !== maybe_serialize( $desired ) || $changed;
			$price_source_updates['mobo_api_compare_price'] = $desired;
		}

		$fast_context = 'product_variation' === get_post_type( $post_id ) ? 'variation' : 'product';
		$fast_parent_id = 'variation' === $fast_context ? absint( wp_get_post_parent_id( $post_id ) ) : 0;
		$policy_type = class_exists( 'Mobo_Core_Product_Pricing_Policy' ) ? Mobo_Core_Product_Pricing_Policy::effective_policy_type( $post_id, $fast_parent_id, $fast_context ) : (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' );
		$changed     = (string) get_post_meta( $post_id, 'mobo_price_policy_type', true ) !== $policy_type || $changed;
		$price_source_updates['mobo_price_policy_type'] = $policy_type;
		if ( ! Mobo_Core_Durable_State_Policy::update_post_meta_verified_batch( $post_id, $price_source_updates ) ) {
			return false;
		}

		if ( $changed && ! $this->persist_post_meta_verified( $post_id, 'mobo_price_policy_updated_at', gmdate( 'c' ) ) ) {
			return false;
		}
		return true;
	}

	private function update_post_meta_if_changed( $post_id, $key, $value ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return false;
		}
		$current = get_post_meta( $post_id, $key, true );
		if ( $current == $value ) { // Intentional loose compare for numeric metadata stored as strings.
			return false;
		}
		return false !== update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Persist a correctness-critical postmeta value and verify the exact read-back.
	 * Unlike update_post_meta_if_changed(), true means durable convergence rather
	 * than "a write was attempted/changed". This is used for crash markers and
	 * ordering watermarks that must survive before an event can be acknowledged.
	 */
	private function persist_post_meta_verified( $post_id, $key, $value ) {
		return Mobo_Core_Durable_State_Policy::update_post_meta_verified( $post_id, $key, $value );
	}

	private function delete_post_meta_verified( $post_id, $key ) {
		$post_id = absint( $post_id );
		$key     = sanitize_key( (string) $key );
		if ( $post_id <= 0 || '' === $key ) {
			return false;
		}
		if ( metadata_exists( 'post', $post_id, $key ) ) {
			delete_post_meta( $post_id, $key );
		}
		wp_cache_delete( $post_id, 'post_meta' );
		return ! metadata_exists( 'post', $post_id, $key );
	}

	private function persist_ordering_watermarks( $post_id, $scope, $source_version, $source_revision, $applied = false ) {
		$post_id         = absint( $post_id );
		$scope           = 'variant' === sanitize_key( (string) $scope ) ? 'variant' : 'product';
		$source_version  = sanitize_text_field( (string) $source_version );
		$source_revision = absint( $source_revision );
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( '' !== $source_version && ! $this->persist_post_meta_verified( $post_id, '_mobo_' . $scope . '_seen_event_version', $source_version ) ) {
			return false;
		}
		if ( $source_revision > 0 && ! $this->persist_post_meta_verified( $post_id, '_mobo_' . $scope . '_seen_revision', $source_revision ) ) {
			return false;
		}
		if ( $applied && '' !== $source_version && ! $this->persist_post_meta_verified( $post_id, '_mobo_' . $scope . '_applied_event_version', $source_version ) ) {
			return false;
		}
		if ( $applied && $source_revision > 0 && ! $this->persist_post_meta_verified( $post_id, '_mobo_' . $scope . '_applied_revision', $source_revision ) ) {
			return false;
		}
		return true;
	}

	private function delete_post_meta_if_present( $post_id, $key ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! metadata_exists( 'post', $post_id, $key ) ) {
			return false;
		}
		return (bool) delete_post_meta( $post_id, $key );
	}

	/**
	 * Queue WooCommerce object metadata only when the persisted/edit value changed.
	 * This keeps WC_Data::save_meta_data() from issuing UPDATEs for identical Mobo
	 * bookkeeping values during price/stock-only syncs.
	 *
	 * @param WC_Product $product Product or variation object.
	 * @param string     $key Meta key.
	 * @param mixed      $value Desired value.
	 * @return bool True when a metadata mutation was queued.
	 */
	private function update_product_meta_if_changed( $product, $key, $value ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$product_id = absint( $product->get_id() );
		$typed_terminal_key = $product_id > 0 && (
			( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' )
				&& Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced()
				&& Mobo_Core_Storage_Identity_Meta_Retirement::key_applies_to_object( $product_id, $key ) )
			|| ( class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' )
				&& Mobo_Core_Storage_Operational_Meta_Retirement::is_fenced()
				&& Mobo_Core_Storage_Operational_Meta_Retirement::key_applies_to_object( $product_id, $key ) )
		);

		/* WC_Data_Store_WP does not read the typed 10.33.94/10.33.95 stores.
		 * Once those terminal fences are active, use the WordPress metadata API for
		 * existing objects so its terminal filters become the sole authority. New
		 * products still need WC's in-memory metadata queue until their first ID exists. */
		if ( $typed_terminal_key ) {
			$current = get_post_meta( $product_id, $key, true );
			if ( $current == $value ) { // Intentional loose compare for numeric metadata stored as strings.
				return false;
			}
			return false !== update_post_meta( $product_id, $key, $value );
		}

		$current = $product->get_meta( $key, true, 'edit' );
		if ( $current == $value ) { // Intentional loose compare for numeric metadata stored as strings.
			return false;
		}

		$product->update_meta_data( $key, $value );
		return true;
	}

	private function delete_product_meta_if_present( $product, $key ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$product_id = absint( $product->get_id() );
		$exists     = $product_id > 0
			? metadata_exists( 'post', $product_id, $key )
			: '' !== (string) $product->get_meta( $key, true, 'edit' );

		if ( ! $exists ) {
			return false;
		}

		$typed_terminal_key = $product_id > 0 && (
			( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' )
				&& Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced()
				&& Mobo_Core_Storage_Identity_Meta_Retirement::key_applies_to_object( $product_id, $key ) )
			|| ( class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' )
				&& Mobo_Core_Storage_Operational_Meta_Retirement::is_fenced()
				&& Mobo_Core_Storage_Operational_Meta_Retirement::key_applies_to_object( $product_id, $key ) )
		);

		if ( $typed_terminal_key ) {
			return (bool) delete_post_meta( $product_id, $key );
		}

		$product->delete_meta_data( $key );
		return true;
	}

	/**
	 * Remove obsolete stock diagnostics even when storefront state is already
	 * converged and the WooCommerce CRUD no-op path is taken.
	 *
	 * @param int   $post_id Product/variation ID.
	 * @param array $data Source payload.
	 * @return void
	 */
	private function cleanup_stock_diagnostic_meta_fast( $post_id, $data ) {
		if ( ! $this->rules->should_update_stock() || ! is_array( $data ) ) {
			return;
		}

		$present = false;
		$stock   = $this->get_stock_value_from_payload( $data, $present );
		if ( ! $present ) {
			return;
		}

		$changed = $this->delete_post_meta_if_present( $post_id, '_mobo_stock_payload_missing' );
		if ( null === $stock || '' === $stock ) {
			$changed = $this->delete_post_meta_if_present( $post_id, '_mobo_last_api_stock_quantity' ) || $changed;
		}

		if ( $changed ) {
			$this->update_post_meta_if_changed( $post_id, '_mobo_last_api_stock_applied_at', gmdate( 'c' ) );
		}
	}

	private function store_product_attribute_guids_if_changed( $product_id, $attributes ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}
		return $this->store_product_attribute_guids( $product_id, is_array( $attributes ) ? $attributes : array() );
	}

	private function store_product_category_refs_if_changed( $product_id, $category_refs ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}
		/* Always verify both halves of the stored category reference state. A stale
		 * refs JSON must not survive merely because the GUID list already matches. */
		return $this->store_product_category_refs( $product_id, is_array( $category_refs ) ? $category_refs : array() );
	}

	/**
	 * Image convergence hash. 10.34.78: URLs are reduced to their canonical path
	 * (Mobo_Core_Image_Source_Identity) so a CDN host / query-string change no longer
	 * re-enters image processing. Hashes persisted by older builds stay accepted.
	 */
	private function build_product_images_source_hash( $images ) {
		$images = is_array( $images ) ? $images : array();
		if ( class_exists( 'Mobo_Core_Image_Source_Identity' ) ) {
			$images = Mobo_Core_Image_Source_Identity::canonicalize_for_hash( $images );
		}
		$json   = wp_json_encode( $this->sort_array_for_hash( $images ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false === $json ? '' : md5( $json );
	}

	/** Pre-10.34.78 raw-URL image hash, accepted as an equivalent stored value. */
	private function build_legacy_product_images_source_hash( $images ) {
		$images = is_array( $images ) ? $images : array();
		$json   = wp_json_encode( $this->sort_array_for_hash( $images ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false === $json ? '' : md5( $json );
	}

	/** Whether a stored image hash matches the incoming images (new or legacy form). */
	private function product_images_hash_matches( $images, $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return false;
		}
		$incoming = $this->build_product_images_source_hash( $images );
		if ( '' !== $incoming && hash_equals( $incoming, $stored ) ) {
			return true;
		}
		$legacy = $this->build_legacy_product_images_source_hash( $images );
		return '' !== $legacy && hash_equals( $legacy, $stored );
	}

	private function product_images_need_processing( $product_id, $images, $is_new = false ) {
		$product_id = absint( $product_id );
		$images     = is_array( $images ) ? $images : array();
		if ( $product_id <= 0 ) {
			return false;
		}
		if ( empty( $images ) ) {
			$stored   = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_product_images_source_hash', true ) );
			$incoming = $this->product_images_hash_matches( array(), $stored ) ? $stored : $this->build_product_images_source_hash( array() );
			$product  = wc_get_product( $product_id );
			$has_linked_images = $product instanceof WC_Product && ( absint( $product->get_image_id() ) > 0 || ! empty( $product->get_gallery_image_ids() ) );
			$has_queue_rows = false;
			if ( class_exists( 'Mobo_Core_Image_Queue' ) && Mobo_Core_Image_Queue::table_exists() ) {
				$queue = new Mobo_Core_Image_Queue();
				$has_queue_rows = ! empty( $queue->get_ordered_rows_for_product( $product_id ) );
			}
			return $is_new || $incoming !== $stored || $has_linked_images || $has_queue_rows;
		}
		if ( $is_new ) {
			return true;
		}
		$stored   = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_product_images_source_hash', true ) );
		if ( ! $this->product_images_hash_matches( $images, $stored ) ) {
			return true;
		}

		/*
		 * A source hash proves only that Portal sent the same payload again; it does
		 * not prove local storage is still converged. Older releases checked only for
		 * a featured image, so a deleted gallery file, missing durable queue row or a
		 * stale attachment could remain broken forever behind the no-op fast path.
		 */
		return ! $this->product_image_fast_path_is_healthy( $product_id, $images );
	}

	/**
	 * Verify the cheap, local invariants required before skipping image sync.
	 * No file is generated or deleted here; any drift simply re-enters the normal
	 * queue where the stricter storage/readiness checks perform the repair.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images Desired image payload.
	 * @return bool
	 */
	private function product_image_fast_path_is_healthy( $product_id, $images ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || ! class_exists( 'Mobo_Core_Image_Queue' ) || ! Mobo_Core_Image_Queue::table_exists() ) {
			return false;
		}

		$desired = array();
		foreach ( array_values( is_array( $images ) ? $images : array() ) as $position => $image ) {
			if ( ! is_array( $image ) ) {
				return false;
			}

			$image_guid = Mobo_Core_Image_Desired_State_Policy::image_guid( $image );
			$url        = Mobo_Core_Image_Desired_State_Policy::image_url( $image );

			if ( '' === $image_guid || '' === $url ) {
				return false;
			}

			$desired[] = array(
				'image_guid' => $image_guid,
				'source_url' => $url,
				'position'   => absint( $position ),
			);
		}

		$queue = new Mobo_Core_Image_Queue();
		$rows  = $queue->get_ordered_rows_for_product( $product_id );
		if ( count( $rows ) !== count( $desired ) ) {
			return false;
		}

		$attachment_ids = array();
		$deep_health_service = class_exists( 'Mobo_Core_Image_Refresh_Service' ) ? new Mobo_Core_Image_Refresh_Service() : null;
		foreach ( $desired as $index => $wanted ) {
			$row = isset( $rows[ $index ] ) && is_array( $rows[ $index ] ) ? $rows[ $index ] : array();
			if ( 'done' !== sanitize_key( (string) ( isset( $row['status'] ) ? $row['status'] : '' ) )
				|| strtolower( trim( sanitize_text_field( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) ) ) ) !== strtolower( trim( $wanted['image_guid'] ) )
				|| esc_url_raw( (string) ( isset( $row['source_url'] ) ? $row['source_url'] : '' ) ) !== $wanted['source_url']
				|| absint( isset( $row['position_index'] ) ? $row['position_index'] : -1 ) !== $wanted['position'] ) {
				return false;
			}

			$attachment_id = absint( isset( $row['attachment_id'] ) ? $row['attachment_id'] : 0 );
			if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
				return false;
			}

			$stored_source = esc_url_raw( (string) get_post_meta( $attachment_id, 'mobo_source_url', true ) );
			$stored_guid   = sanitize_text_field( (string) get_post_meta( $attachment_id, 'image_guid', true ) );
			if ( '' === $stored_guid ) {
				$stored_guid = sanitize_text_field( (string) get_post_meta( $attachment_id, 'img_guid', true ) );
			}
			if ( $stored_source !== $wanted['source_url'] || ! hash_equals( strtolower( trim( $stored_guid ) ), strtolower( trim( $wanted['image_guid'] ) ) ) ) {
				return false;
			}

			$wanted_path = (string) wp_parse_url( (string) $wanted['source_url'], PHP_URL_PATH );
			$wanted_webp = 'webp' === strtolower( pathinfo( $wanted_path, PATHINFO_EXTENSION ) );
			$shared_attachment = class_exists( 'Mobo_Core_Shared_Media' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id );

			if ( ( $wanted_webp || $shared_attachment ) && $deep_health_service && method_exists( $deep_health_service, 'inspect_webp_attachment_health' ) ) {
				$health = $deep_health_service->inspect_webp_attachment_health( $attachment_id );
				if ( empty( $health['healthy'] ) ) {
					return false;
				}
			} elseif ( $shared_attachment ) {
				/* Shared Media must never take a shallow fast path if the deep verifier is
				 * unavailable; entering the queue is safer than accepting stale manifest data. */
				return false;
			} else {
				$file = get_attached_file( $attachment_id );
				$file = is_string( $file ) ? $file : '';
				if ( '' === $file || ! is_file( $file ) ) {
					return false;
				}
				$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Concurrent deletion is treated as drift.
				if ( false === $size || $size <= 0 ) {
					return false;
				}
				if ( function_exists( 'wp_get_image_mime' ) ) {
					$mime = strtolower( (string) wp_get_image_mime( $file ) );
					if ( '' === $mime || 0 !== strpos( $mime, 'image/' ) ) {
						return false;
					}
				}
			}

			$attachment_ids[] = $attachment_id;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$raw_gallery_ids = array_values( array_filter( array_map( 'absint', (array) $product->get_gallery_image_ids() ) ) );
		$current_gallery = array_values( array_unique( $raw_gallery_ids ) );
		$image_id        = absint( $product->get_image_id() );

		/* Do not normalize corruption away. Duplicate gallery entries or a featured
		 * image repeated inside _product_image_gallery are local drift and must re-enter
		 * image sync so WooCommerce metadata is repaired. */
		if ( count( $raw_gallery_ids ) !== count( $current_gallery ) || ( $image_id > 0 && in_array( $image_id, $current_gallery, true ) ) ) {
			return false;
		}

		$current_ids = $current_gallery;
		if ( $image_id > 0 ) {
			array_unshift( $current_ids, $image_id );
		}

		return $current_ids === array_values( array_unique( $attachment_ids ) );
	}

	private function mark_product_images_pending( $product_id, $images ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}
		$hash = $this->build_product_images_source_hash( $images );
		if ( '' === $hash || ! $this->persist_post_meta_verified( $product_id, '_mobo_product_images_pending_source_hash', $hash ) ) {
			return false;
		}
		return $this->persist_post_meta_verified( $product_id, '_mobo_product_images_pending_source_hash_updated_at', gmdate( 'c' ) );
	}

	private function mark_product_images_converged( $product_id, $images ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}
		$hash = $this->build_product_images_source_hash( $images );
		if ( '' === $hash || ! $this->persist_post_meta_verified( $product_id, '_mobo_product_images_source_hash', $hash ) ) {
			return false;
		}
		if ( ! $this->persist_post_meta_verified( $product_id, '_mobo_product_images_source_hash_updated_at', gmdate( 'c' ) ) ) {
			return false;
		}
		return $this->delete_post_meta_verified( $product_id, '_mobo_product_images_pending_source_hash' )
			&& $this->delete_post_meta_verified( $product_id, '_mobo_product_images_pending_source_hash_updated_at' );
	}


	/**
	 * Return true only when every concrete variation in a delta is provably older
	 * than the durable per-variation watermark. Unknown/new identities make the
	 * answer false so we fail open to normal validation rather than drop work.
	 *
	 * @param int    $parent_id Parent product ID.
	 * @param array  $variants Variant payloads.
	 * @param string $source_version Generic event ordering watermark.
	 * @param int    $source_revision Numeric source revision.
	 * @return bool
	 */
	private function delta_variant_payload_is_fully_stale( $parent_id, $variants, $source_version = '', $source_revision = 0 ) {
		$parent_id       = absint( $parent_id );
		$source_version  = sanitize_text_field( (string) $source_version );
		$source_revision = absint( $source_revision );
		if ( $parent_id <= 0 || ! is_array( $variants ) || empty( $variants ) || ( '' === $source_version && $source_revision <= 0 ) ) {
			return false;
		}

		$checked = 0;
		foreach ( $variants as $variant_data ) {
			if ( ! is_array( $variant_data ) ) {
				return false;
			}
			$variant_guid = $this->extract_variant_guid( $variant_data );
			if ( '' === $variant_guid ) {
				return false;
			}
			$variation_id = $this->find_variation_id_by_guid( $variant_guid );
			if ( $variation_id <= 0 || absint( wp_get_post_parent_id( $variation_id ) ) !== $parent_id ) {
				return false;
			}

			$is_stale = false;
			if ( '' !== $source_version ) {
				$seen_version = sanitize_text_field( (string) get_post_meta( $variation_id, '_mobo_variant_seen_event_version', true ) );
				if ( '' !== $seen_version && -1 === $this->compare_source_versions( $source_version, $seen_version ) ) {
					$is_stale = true;
				}
			}
			if ( ! $is_stale && $source_revision > 0 ) {
				$seen_revision = absint( get_post_meta( $variation_id, '_mobo_variant_seen_revision', true ) );
				if ( $seen_revision > $source_revision ) {
					$is_stale = true;
				}
			}
			if ( ! $is_stale ) {
				return false;
			}
			$checked++;
		}

		return $checked > 0;
	}

	/**
	 * Verify the storefront-relevant state of one existing variation.
	 *
	 * This allows a hash/policy change to refresh only Mobo internal metadata when
	 * title/price/stock/attributes are already converged, avoiding a WooCommerce
	 * variation save and the hooks/cache churn attached to it.
	 *
	 * @param int   $variation_id Variation ID.
	 * @param int   $parent_id Expected parent ID.
	 * @param array $data Desired variation payload.
	 * @return bool
	 */
	private function variation_frontend_state_matches_payload( $variation_id, $parent_id, $data ) {
		$variation = wc_get_product( absint( $variation_id ) );
		if ( ! $variation instanceof WC_Product_Variation || ! is_array( $data ) ) {
			return false;
		}

		if ( absint( $variation->get_parent_id() ) !== absint( $parent_id ) || 'publish' !== (string) $variation->get_status() ) {
			return false;
		}

		if ( $this->rules->should_update_title() ) {
			$title = $this->source_post_title( $data );
			if ( '' !== $title && (string) $variation->get_name() !== $title ) {
				return false;
			}
		}

		if ( ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) && ( $this->product_price_field_present( $data ) || $this->product_compare_price_field_present( $data ) ) ) {
			$raw_price   = $this->product_price_field_present( $data ) ? $this->get_value( $data, 'price', null ) : get_post_meta( absint( $variation_id ), 'mobo_api_price', true );
			$raw_compare = $this->product_compare_price_field_present( $data ) ? $this->get_compare_price_field_value( $data, null ) : get_post_meta( absint( $variation_id ), 'mobo_api_compare_price', true );
			if ( $this->product_price_field_present( $data ) || ( null !== $raw_price && '' !== $raw_price ) ) {
				$pair = $this->price_calculator->calculate_price_pair(
					absint( $variation_id ),
					$raw_price,
					$raw_compare,
					'variation',
					absint( $parent_id )
				);
				if ( ! empty( $pair['error'] ) ) {
					return false;
				}
				if ( ! $this->price_pair_matches_object( $variation, $pair ) ) {
					return false;
				}
			}
		}

		if ( $this->rules->should_update_stock() && ! $this->variation_stock_matches_payload( $variation_id, $data ) ) {
			return false;
		}

		$incoming_attributes = $this->normalize_variation_attributes( $this->get_value( $data, 'attributes', array() ) );
		if ( ! $this->variation_attributes_match_exact_payload( $this->get_persisted_variation_attributes_exact( $variation ), $incoming_attributes ) ) {
			return false;
		}

		return true;
	}


	private function build_variation_source_hash( $data, $product_guid = '' ) {
		if ( ! is_array( $data ) || ! $this->variation_payload_has_complete_source_hash_state( $data ) ) {
			return '';
		}

		$hash_data = array(
			'variantId'     => $this->extract_variant_guid( $data ),
			'productId'     => sanitize_text_field( (string) $product_guid ),
			'attributes'    => $this->get_value( $data, 'attributes', array() ),
			'updateTitle'   => $this->rules->should_update_title() ? 1 : 0,
			'updatePrice'   => $this->rules->should_update_price() ? 1 : 0,
			'updateCompare' => $this->rules->should_update_compare_price() ? 1 : 0,
			'updateStock'   => $this->rules->should_update_stock() ? 1 : 0,
		);

		if ( $this->rules->should_update_title() && $this->product_title_field_present( $data ) ) {
			$hash_data['title'] = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );
		}

		if ( ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) && ( $this->product_price_field_present( $data ) || $this->product_compare_price_field_present( $data ) ) ) {
			if ( $this->product_price_field_present( $data ) ) {
				$hash_data['price'] = $this->get_value( $data, 'price', null );
			}
			if ( $this->product_compare_price_field_present( $data ) ) {
				$hash_data['comparePrice'] = $this->get_compare_price_field_value( $data, null );
			}
			$hash_data['pricePolicyHash'] = $this->build_price_policy_hash();
		}

		if ( $this->rules->should_update_stock() ) {
			$stock_present = false;
			$stock_value   = $this->get_stock_value_from_payload( $data, $stock_present );
			if ( $stock_present ) {
				$hash_data['stock'] = $stock_value;
			}
		}

		$hash_data = $this->sort_array_for_hash( $hash_data );
		$json      = wp_json_encode( $hash_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false === $json ? '' : md5( $json );
	}

	private function build_price_policy_hash() {
		$policy = array(
			'mobo_price_type'                   => (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' ),
			'global_additional_price'           => (string) Mobo_Core_Settings::get( 'global_additional_price', 0 ),
			'global_additional_percentage'      => (string) Mobo_Core_Settings::get( 'global_additional_percentage', 0 ),
			'global_product_auto_compare_price' => (string) Mobo_Core_Settings::get( 'global_product_auto_compare_price', '0' ),
			'mobo_dynamic_price'                => (string) Mobo_Core_Settings::get( 'mobo_dynamic_price', '[]' ),
		);
		/* B11: optional rounding changes the written price. The fragment is empty
		 * while rounding is "none", so historical hashes are preserved. */
		if ( class_exists( 'Mobo_Core_Price_Rounding' ) ) {
			$policy = array_merge( $policy, Mobo_Core_Price_Rounding::fingerprint_fragment() );
		}

		/* Currency affects the actual price written by the shared calculator.
		 * An explicitly saved preference must therefore invalidate the Sync
		 * fast-skip policy hash whenever the destination currency or its
		 * WooCommerce decimal precision changes. Preserve the exact historical
		 * hash for customers who have NOT opted into conversion: upgrading the
		 * plugin must not force unrelated V1 storefront rewrites. */
		$currency_preference = class_exists( 'Mobo_Core_Money_Target_Settings' )
			? get_option( Mobo_Core_Money_Target_Settings::OPTION, null )
			: null;
		if ( null !== $currency_preference ) {
			$policy['currencyPreferenceSaved'] = true;
			$policy['currencyPreference']      = is_string( $currency_preference ) ? $currency_preference : '';
			$policy['wooCurrency']             = function_exists( 'get_woocommerce_currency' )
				? (string) get_woocommerce_currency() : '';
			$policy['wooPriceDecimals']        = function_exists( 'wc_get_price_decimals' )
				? (int) wc_get_price_decimals() : -1;
		}

		$json = wp_json_encode( $this->sort_array_for_hash( $policy ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false === $json ? '' : md5( $json );
	}

	private function sort_array_for_hash( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! $this->is_list_array( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->sort_array_for_hash( $item );
		}

		return $value;
	}

	/**
	 * Resolve whether the desired/current product is variable without treating an
	 * omitted partial-payload attributes field as an explicit empty attribute set.
	 */
	private function product_payload_can_have_variants( $data, $product_id = 0 ) {
		if ( $this->product_attributes_field_present( $data ) ) {
			return $this->product_has_variation_attributes( $data );
		}

		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product_Variable ) {
			return true;
		}
		if ( $product instanceof WC_Product ) {
			foreach ( $product->get_attributes() as $attribute ) {
				if ( $attribute instanceof WC_Product_Attribute && $attribute->get_variation() ) {
					return true;
				}
			}
		}

		return false;
	}

	private function product_has_variation_attributes( $data ) {
		$attributes = $this->get_value( $data, 'attributes', array() );

		if ( ! is_array( $attributes ) || empty( $attributes ) ) {
			return false;
		}

		foreach ( $attributes as $attribute_data ) {
			if ( ! is_array( $attribute_data ) ) {
				continue;
			}

			$name = $this->source_attribute_text( $this->get_value( $attribute_data, 'name', '' ) );

			if ( '' === $name ) {
				continue;
			}

			$values = $this->get_value( $attribute_data, 'values', array() );

			if ( ! is_array( $values ) || empty( $values ) ) {
				continue;
			}

			foreach ( $values as $value_data ) {
				if ( ! is_array( $value_data ) ) {
					continue;
				}

				$value = $this->source_attribute_text( $this->get_value( $value_data, 'value', '' ) );

				if ( '' !== $value ) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Fetch and validate the authoritative simple-product Variant snapshot.
	 *
	 * This method is deliberately read-only with respect to WooCommerce/Mobo state.
	 * Callers that are not already under the shared product lock can therefore keep
	 * Portal HTTP outside the critical section and serialize only the local commit.
	 *
	 * @param Mobo_Core_API_Client $api API client.
	 * @param string               $product_guid Remote product GUID.
	 * @param int                  $product_id WooCommerce product ID.
	 * @param string               $sync_id Sync ID.
	 * @return array|WP_Error
	 */
	private function fetch_simple_product_variant_snapshot_from_api( $api, $product_guid, $product_id, $sync_id ) {
		$product_guid = sanitize_text_field( (string) $product_guid );
		$product_id   = absint( $product_id );
		$sync_id      = sanitize_text_field( (string) $sync_id );

		if ( ! is_object( $api ) || ! is_callable( array( $api, 'get_variants_page' ) ) || '' === $product_guid || $product_id <= 0 ) {
			return new WP_Error( 'mobo_core_simple_variant_invalid_context', 'Simple product variant sync context is invalid.' );
		}

		$response = $api->get_variants_page( $product_guid, 1, 2, $sync_id, 0, false );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$variants = $this->validated_variant_page_items( $response );
		if ( is_wp_error( $variants ) ) {
			return $variants;
		}

		$page_has_more = $this->validated_page_has_more( $response, 1 );
		if ( is_wp_error( $page_has_more ) ) {
			return $page_has_more;
		}
		if ( $page_has_more ) {
			return new WP_Error( 'mobo_core_simple_variant_pagination_ambiguous', 'Simple product variant lookup returned additional pages; automatic selection is unsafe.' );
		}

		$total      = absint( $this->get_value( $response, 'totalCount', count( $variants ) ) );
		$total      = max( $total, count( $variants ) );
		$valid_rows = array_values( array_filter( $variants, array( $this, 'looks_like_variant_payload' ) ) );

		if ( 1 === count( $valid_rows ) && $total <= 1 ) {
			return array(
				'mode'       => 'mapped',
				'variant'    => $valid_rows[0],
				'totalCount' => $total,
			);
		}

		if ( 0 === count( $valid_rows ) && 0 === $total ) {
			return array(
				'mode'       => 'authoritative_missing',
				'totalCount' => 0,
				'message'    => 'محصول ساده در موبو هیچ Variant قابل خریدی ندارد؛ state معتبر ولی غیرقابل‌خرید به‌صورت ناموجود ثبت شد.',
			);
		}

		return array(
			'mode'       => 'ambiguous',
			'totalCount' => $total,
			'validCount' => count( $valid_rows ),
			'message'    => sprintf( 'برای محصول ساده پاسخ Variant مبهم است (%d مورد معتبر، totalCount=%d). انتخاب خودکار ناامن است؛ محصول برای خرید مسدود شد.', count( $valid_rows ), $total ),
		);
	}

	/**
	 * Apply a previously validated simple-product Variant snapshot.
	 *
	 * The caller is responsible for holding the shared product lock whenever this
	 * method can reach topology/quarantine mutation.
	 *
	 * @param int    $product_id WooCommerce product ID.
	 * @param string $product_guid Product GUID.
	 * @param array  $snapshot Validated snapshot.
	 * @return array|WP_Error
	 */
	/**
	 * R1: Variant topology of the product has been applied; lift the creation-time
	 * "awaiting Variants" sale block (no-op for every product that never had it).
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function release_awaiting_variants( $product_id ) {
		if ( class_exists( 'Mobo_Core_Source_Visibility' ) && method_exists( 'Mobo_Core_Source_Visibility', 'release_awaiting_variants' ) ) {
			Mobo_Core_Source_Visibility::release_awaiting_variants( $product_id );
		}
	}

	private function apply_simple_product_variant_snapshot( $product_id, $product_guid, $snapshot ) {
		$product_id   = absint( $product_id );
		$product_guid = sanitize_text_field( (string) $product_guid );
		$mode         = is_array( $snapshot ) ? sanitize_key( (string) ( isset( $snapshot['mode'] ) ? $snapshot['mode'] : '' ) ) : '';

		if ( $product_id <= 0 || '' === $product_guid || '' === $mode ) {
			return new WP_Error( 'mobo_core_simple_variant_snapshot_invalid', 'Validated simple-product Variant snapshot is invalid.' );
		}

		if ( 'mapped' === $mode ) {
			$variant = isset( $snapshot['variant'] ) && is_array( $snapshot['variant'] ) ? $snapshot['variant'] : array();
			$result  = $this->apply_simple_variant_to_product( $product_id, $variant, $product_guid );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$this->release_awaiting_variants( $product_id );

			return array(
				'success'         => true,
				'productId'       => $product_id,
				'portalVariantId' => isset( $result['portalVariantId'] ) ? absint( $result['portalVariantId'] ) : 0,
				'variantGuid'     => isset( $result['variantGuid'] ) ? sanitize_text_field( (string) $result['variantGuid'] ) : '',
				'message'         => 'شناسه قابل خرید محصول ساده همگام شد.',
			);
		}

		if ( 'authoritative_missing' === $mode ) {
			$empty_state = $this->apply_authoritative_simple_without_purchase_variant( $product_id, $product_guid );
			if ( is_wp_error( $empty_state ) ) {
				return $empty_state;
			}
			$this->release_awaiting_variants( $product_id );

			return array(
				'success'              => true,
				'productId'            => $product_id,
				'portalVariantId'      => 0,
				'variantGuid'          => '',
				'purchasable'          => false,
				'authoritativeMissing' => true,
				'message'              => isset( $snapshot['message'] ) ? sanitize_text_field( (string) $snapshot['message'] ) : 'Authoritative Source topology contains no purchasable Variant.',
			);
		}

		if ( 'ambiguous' === $mode ) {
			$message = isset( $snapshot['message'] ) ? sanitize_text_field( (string) $snapshot['message'] ) : 'Simple product Variant snapshot is ambiguous.';
			$this->mark_simple_product_variant_unresolved( $product_id, 'ambiguous', $message );
			return new WP_Error( 'mobo_core_simple_variant_ambiguous', $message );
		}

		return new WP_Error( 'mobo_core_simple_variant_snapshot_mode_invalid', 'Simple-product Variant snapshot mode is invalid.' );
	}

	/**
	 * Revalidate the exact parent before a Manual/Repair Step-5 Simple commit.
	 *
	 * No mutation is performed here. The caller already holds the shared product
	 * lock, so a successful proof remains stable until the subsequent commit.
	 *
	 * @param int    $product_id Expected WooCommerce product ID.
	 * @param string $product_guid Expected remote product GUID.
	 * @param array  $expected_attributes Parent attributes from the accepted snapshot.
	 * @return true|WP_Error
	 */
	private function revalidate_manual_simple_product_target( $product_id, $product_guid, $expected_attributes ) {
		$product_id          = absint( $product_id );
		$product_guid        = sanitize_text_field( (string) $product_guid );
		$expected_attributes = is_array( $expected_attributes ) ? $expected_attributes : array();

		if ( $product_id <= 0 || '' === $product_guid ) {
			return new WP_Error( 'mobo_core_manual_simple_revalidation_invalid', 'Manual Simple commit target identity is invalid.' );
		}

		$post = get_post( $product_id );
		if ( ! $post instanceof WP_Post
			|| 'product' !== (string) $post->post_type
			|| in_array( (string) $post->post_status, array( 'trash', 'auto-draft' ), true )
		) {
			return new WP_Error( 'mobo_core_manual_simple_parent_missing', 'Manual Simple commit parent no longer exists as an active product.' );
		}

		$stored_guid = sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) );
		if ( '' === $stored_guid || ! hash_equals( $product_guid, $stored_guid ) ) {
			return new WP_Error( 'mobo_core_manual_simple_parent_identity_changed', 'Manual Simple commit parent identity changed before lock acquisition.' );
		}

		if ( class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			$ids = Mobo_Core_Product_Concurrency::get_product_ids_by_guid( $product_guid );
			$ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
			if ( 1 !== count( $ids ) || $product_id !== absint( $ids[0] ) ) {
				return new WP_Error( 'mobo_core_manual_simple_parent_ownership_ambiguous', 'Manual Simple commit parent GUID ownership is no longer unique.' );
			}
		}

		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			$mapped_id = absint( $this->product_map->get_product_id( $product_guid ) );
			if ( $mapped_id > 0 && $mapped_id !== $product_id ) {
				return new WP_Error( 'mobo_core_manual_simple_parent_map_changed', 'Manual Simple commit Product Map ownership changed before mutation.' );
			}
		}

		$current = wc_get_product( $product_id );
		if ( ! $current instanceof WC_Product ) {
			return new WP_Error( 'mobo_core_manual_simple_parent_unloadable', 'Manual Simple commit parent could not be reloaded inside the product lock.' );
		}

		if ( $this->product_attribute_structure_changed( $current, $expected_attributes ) ) {
			return new WP_Error( 'mobo_core_manual_simple_parent_topology_changed', 'Manual Simple commit parent attribute topology changed before mutation.' );
		}

		return true;
	}

	/**
	 * Commit Manual/Repair Step-5 Simple state under the shared parent product lock.
	 *
	 * @param int    $product_id WooCommerce product ID.
	 * @param string $product_guid Product GUID.
	 * @param array  $snapshot Validated Portal snapshot fetched outside the lock.
	 * @param array  $expected_attributes Parent attributes from the accepted product snapshot.
	 * @return array|WP_Error
	 */
	private function commit_manual_simple_product_variant_snapshot( $product_id, $product_guid, $snapshot, $expected_attributes, $parent_snapshot_us = 0 ) {
		$product_id   = absint( $product_id );
		$product_guid = sanitize_text_field( (string) $product_guid );

		if ( $product_id <= 0 || '' === $product_guid || ! is_array( $snapshot ) ) {
			return new WP_Error( 'mobo_core_manual_simple_commit_invalid', 'Manual Simple commit context is invalid.' );
		}

		if ( ! class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			return new WP_Error( 'mobo_core_manual_simple_lock_unavailable', 'Shared product lock service is unavailable; Manual Simple mutation was deferred.' );
		}

		$lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 5, 180 );
		if ( false === $lock ) {
			return new WP_Error( 'mobo_core_manual_simple_product_lock_busy', 'Shared parent product lock is busy; Manual Simple mutation was deferred.' );
		}

		try {
			/* A webhook may have passed its manual-sync preflight before the parent
			 * checkpoint became visible and completed while Simple waited for lock. */
			if ( absint( get_post_meta( $product_id, '_mobo_last_webhook_applied_us', true ) ) > absint( $parent_snapshot_us ) ) {
				return new WP_Error( 'mobo_core_manual_snapshot_superseded', 'A foreground webhook superseded this Manual Simple parent snapshot.' );
			}
			$revalidated = $this->revalidate_manual_simple_product_target( $product_id, $product_guid, $expected_attributes );
			if ( is_wp_error( $revalidated ) ) {
				return $revalidated;
			}

			return $this->apply_simple_product_variant_snapshot( $product_id, $product_guid, $snapshot );
		} finally {
			Mobo_Core_Product_Concurrency::release_product_lock( $lock );
		}
	}

	/**
	 * Resolve and persist the one purchasable Mobo variant of a simple product.
	 *
	 * Existing guarded webhook/variant callers already hold the shared product lock.
	 * Manual/Repair Step 5 uses fetch_simple_product_variant_snapshot_from_api()
	 * followed by commit_manual_simple_product_variant_snapshot() instead.
	 *
	 * @param Mobo_Core_API_Client $api API client.
	 * @param string               $product_guid Remote product GUID.
	 * @param int                  $product_id WooCommerce product ID.
	 * @param string               $sync_id Sync ID.
	 * @return array|WP_Error
	 */
	private function sync_simple_product_variant_from_api( $api, $product_guid, $product_id, $sync_id ) {
		$snapshot = $this->fetch_simple_product_variant_snapshot_from_api( $api, $product_guid, $product_id, $sync_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		return $this->apply_simple_product_variant_snapshot( $product_id, $product_guid, $snapshot );
	}

	/**
	 * Persist a Mobo storefront variant on WC_Product_Simple.
	 *
	 * @param int    $product_id WooCommerce product ID.
	 * @param array  $variant_data Variant payload.
	 * @param string $product_guid Product GUID.
	 * @return array|WP_Error
	 */
	/**
	 * Simple-product single-variant apply plus the Source inactive-variant overlay.
	 *
	 * @param int    $product_id Product ID.
	 * @param array  $variant_data Variant payload.
	 * @param string $product_guid Product GUID.
	 * @return array|WP_Error
	 */
	private function apply_simple_variant_to_product( $product_id, $variant_data, $product_guid = '' ) {
		$result = $this->apply_simple_variant_to_product_core( $product_id, $variant_data, $product_guid );
		if ( ! is_wp_error( $result ) && is_array( $variant_data ) ) {
			Mobo_Core_Source_Visibility::apply_inactive_flag( absint( $product_id ), Mobo_Core_Source_Visibility::payload_variant_purchasable( $variant_data ) );
		}
		return $result;
	}

	private function apply_simple_variant_to_product_core( $product_id, $variant_data, $product_guid = '' ) {
		$product_id   = absint( $product_id );
		$product_guid = sanitize_text_field( (string) $product_guid );

		if ( $product_id <= 0 || ! is_array( $variant_data ) ) {
			return new WP_Error( 'mobo_core_simple_variant_invalid_payload', 'Simple product variant payload is invalid.' );
		}

		$money_integrity = $this->validate_money_payload_fields( $variant_data, 'simple-variant' );
		if ( is_wp_error( $money_integrity ) ) {
			return $money_integrity;
		}

		$portal_variant_id   = $this->extract_portal_variant_id( $variant_data );
		$external_variant_id = $this->extract_external_variant_id( $variant_data );
		$variant_guid        = $this->extract_simple_variant_guid( $variant_data );

		/* Provider-neutral synchronization must not require Portal.ir's numeric
		 * purchase identity. Future providers may expose only an opaque string
		 * ExternalVariantId while MoboCore still supplies its stable VariantId GUID.
		 * At least one durable source identity is required; PortalVariantId remains
		 * optional compatibility/purchase metadata. */
		if ( $portal_variant_id <= 0 && '' === $external_variant_id && '' === $variant_guid ) {
			$this->mark_simple_product_variant_unresolved( $product_id, 'variant_identity_missing', 'شناسه Variant در پاسخ منبع موجود نیست.' );
			return new WP_Error( 'mobo_core_simple_variant_identity_missing', 'Simple Mobo product variant identity is missing.' );
		}

		$incoming_hash = $this->build_variation_source_hash( $variant_data, $product_guid );
		$current       = wc_get_product( $product_id );
		$old_hash      = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_simple_variant_source_hash', true ) );
		$old_mapped    = '1' === sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_simple_variant_mapped', true ) );
		$old_incomplete = sanitize_text_field( (string) get_post_meta( $product_id, 'mobo_sync_incomplete', true ) );

		if ( $old_mapped && '1' !== $old_incomplete && '' !== $incoming_hash && $old_hash === $incoming_hash && $this->noop_fast_path_allowed( $product_id, $old_hash, $incoming_hash, 'product' ) && $current instanceof WC_Product_Simple && $this->simple_product_matches_variant_payload( $current, $variant_data ) ) {
			$this->store_simple_variant_identity_meta_fast( $product_id, $variant_data, $product_guid );
			return array(
				'productId'         => $product_id,
				'portalVariantId'   => $portal_variant_id,
				'externalVariantId' => $external_variant_id,
				'variantGuid'       => $variant_guid,
				'changed'           => false,
			);
		}

		if ( $this->variant_has_selection_attributes( $variant_data ) ) {
			return new WP_Error( 'mobo_core_simple_variant_has_attributes', 'A simple product variant unexpectedly contains selection attributes.' );
		}

		if ( ! $old_mapped || $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) {
			$price_validation = $this->price_calculator->calculate_price_pair(
				$product_id,
				$this->get_value( $variant_data, 'price', null ),
				$this->get_compare_price_field_value( $variant_data, null ),
				'product'
			);
			if ( ! empty( $price_validation['error'] ) ) {
				return new WP_Error( 'mobo_core_simple_price_invalid', sanitize_text_field( (string) $price_validation['error'] ) );
			}
		}

		if ( ! $old_mapped || $this->rules->should_update_stock() ) {
			$stock_present = false;
			$stock_value   = $this->get_stock_value_from_payload( $variant_data, $stock_present );
			/* Portal exposes stock as a nullable integer. An explicit JSON null is not a
			 * malformed value; it means stock is unspecified and must follow the same
			 * semantics already implemented by apply_api_stock( null ). Only non-empty
			 * values that cannot be normalized are rejected fail-closed. */
			if ( $stock_present && ! $this->is_valid_api_stock_payload_value( $stock_value ) ) {
				return new WP_Error( 'mobo_core_simple_stock_invalid', 'Simple Mobo product stock is present but invalid.' );
			}
		}

		$simple_conversion = $this->force_product_simple_if_needed( $product_id );
		if ( is_wp_error( $simple_conversion ) ) {
			return $simple_conversion;
		}
		$product = new WC_Product_Simple( $product_id );

		$was_mapped = '1' === sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_simple_variant_mapped', true ) );
		if ( $portal_variant_id > 0 ) {
			$this->update_product_meta_if_changed( $product, 'portal_variant_id', $portal_variant_id );
			$this->update_product_meta_if_changed( $product, 'mobo_portal_variant_id', $portal_variant_id );
			$this->update_product_meta_if_changed( $product, '_mobo_portal_variant_id', $portal_variant_id );
		} elseif ( '' !== $external_variant_id ) {
			/* An explicit provider-neutral Variant identity with no Portal numeric ID
			 * must not inherit a stale Portal.ir purchase identity. */
			foreach ( array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' ) as $legacy_key ) {
				$product->delete_meta_data( $legacy_key );
			}
		}
		if ( '' !== $external_variant_id ) {
			$this->update_product_meta_if_changed( $product, '_mobo_external_variant_id', $external_variant_id );
		}

		if ( '' !== $variant_guid ) {
			$this->update_product_meta_if_changed( $product, 'variant_guid', $variant_guid );
			$this->update_product_meta_if_changed( $product, 'mobo_variant_guid', $variant_guid );
			$this->update_product_meta_if_changed( $product, '_mobo_variant_guid', $variant_guid );
		}

		if ( '' !== $product_guid ) {
			$this->update_product_meta_if_changed( $product, 'product_guid', $product_guid );
		}

		$this->store_portal_product_id_on_product_object( $product, $variant_data, $product_id );
		$this->store_external_product_id_on_product_object( $product, $variant_data );
		$this->apply_price_to_product( $product, $variant_data, 'product', ! $was_mapped );

		if ( ! $was_mapped || $this->rules->should_update_stock() ) {
			$stock_present = false;
			$stock_value   = $this->get_stock_value_from_payload( $variant_data, $stock_present );

			if ( $stock_present ) {
				if ( ! $this->apply_api_stock( $product, $stock_value ) ) {
					return new WP_Error( 'mobo_core_simple_stock_invalid', 'Simple Mobo product stock is present but invalid.' );
				}
			} elseif ( ! $was_mapped ) {
				$this->apply_api_stock( $product, null );
			}
		}

		$product->update_meta_data( '_mobo_simple_variant_mapped', '1' );
		$product->update_meta_data( '_mobo_simple_variant_mapped_at', gmdate( 'c' ) );
		$product->update_meta_data( '_mobo_simple_variant_resolution_status', 'mapped' );
		$product->update_meta_data( '_mobo_simple_variant_source_hash', $incoming_hash );
		$product->delete_meta_data( '_mobo_simple_variant_resolution_message' );
		$saved_id = absint( $product->save() );
		if ( $saved_id !== $product_id ) {
			return new WP_Error( 'mobo_core_simple_variant_persist_failed', 'WooCommerce did not persist the simple Mobo variant state.' );
		}

		$fresh = wc_get_product( $product_id );
		$portal_identity_ok = $portal_variant_id <= 0 || absint( get_post_meta( $product_id, '_mobo_portal_variant_id', true ) ) === $portal_variant_id;
		$external_identity_ok = '' === $external_variant_id || hash_equals( $external_variant_id, (string) get_post_meta( $product_id, '_mobo_external_variant_id', true ) );
		if ( ! $fresh instanceof WC_Product_Simple
			|| '1' !== (string) $fresh->get_meta( '_mobo_simple_variant_mapped', true )
			|| ! $portal_identity_ok
			|| ! $external_identity_ok
			|| ! $this->simple_product_matches_variant_payload( $fresh, $variant_data )
		) {
			return new WP_Error( 'mobo_core_simple_variant_postcondition_failed', 'Simple Mobo variant save failed its post-write verification.' );
		}

		wc_delete_product_transients( $product_id );

		return array(
			'productId'       => $product_id,
			'portalVariantId'   => $portal_variant_id,
			'externalVariantId' => $external_variant_id,
			'variantGuid'       => $variant_guid,
		);
	}

	/**
	 * Commit an authoritative Simple topology with no purchasable source Variant.
	 * Old parent-level Variant identity is removed and checkout is made impossible,
	 * while the topology itself can still converge as a complete out-of-stock state.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $product_guid Product GUID.
	 * @return true|WP_Error
	 */
	private function apply_authoritative_simple_without_purchase_variant( $product_id, $product_guid ) {
		$product_id   = absint( $product_id );
		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( $product_id <= 0 ) {
			return new WP_Error( 'mobo_core_authoritative_simple_empty_invalid', 'Authoritative empty Simple state has an invalid product ID.' );
		}
		$conversion = $this->force_product_simple_if_needed( $product_id );
		if ( is_wp_error( $conversion ) ) {
			return $conversion;
		}
		$cleanup = $this->clear_simple_variant_mapping_from_parent( $product_id );
		if ( is_wp_error( $cleanup ) ) {
			return $cleanup;
		}
		$product = new WC_Product_Simple( $product_id );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->set_backorders( 'no' ); /* B13: zero must never become onbackorder. */
		$product->set_stock_status( 'outofstock' );
		$product->update_meta_data( '_mobo_simple_variant_mapped', '0' );
		$product->update_meta_data( '_mobo_simple_variant_resolution_status', 'authoritative_missing' );
		$product->update_meta_data( '_mobo_simple_variant_resolution_message', 'Authoritative Source topology contains no purchasable Variant.' );
		$product->update_meta_data( '_mobo_simple_variant_resolution_at', gmdate( 'c' ) );
		if ( '' !== $product_guid ) {
			$this->update_product_meta_if_changed( $product, 'product_guid', $product_guid );
		}
		$saved = absint( $product->save() );
		$fresh = $saved === $product_id ? wc_get_product( $product_id ) : false;
		if ( $saved !== $product_id || ! $fresh instanceof WC_Product_Simple || 'outofstock' !== (string) $fresh->get_stock_status() || absint( get_post_meta( $product_id, '_mobo_portal_variant_id', true ) ) > 0 || '' !== trim( (string) get_post_meta( $product_id, 'variant_guid', true ) ) ) {
			return new WP_Error( 'mobo_core_authoritative_simple_empty_postcondition', 'Authoritative Simple zero-Variant state failed post-write verification.' );
		}
		wc_delete_product_transients( $product_id );
		return true;
	}

	private function mark_simple_product_variant_unresolved( $product_id, $reason, $message ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return;
		}

		$product_guid = sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) );
		if ( '' !== $product_guid ) {
			$this->upsert_product_map( $product_guid, $product_id, true );
		}

		$simple_conversion = $this->force_product_simple_if_needed( $product_id );
		if ( is_wp_error( $simple_conversion ) ) {
			$current = wc_get_product( $product_id );
			if ( $current instanceof WC_Product ) {
				$current->set_stock_status( 'outofstock' );
				$current->update_meta_data( '_mobo_simple_variant_mapped', '0' );
				$current->update_meta_data( '_mobo_simple_variant_resolution_status', 'conversion_failed' );
				$current->update_meta_data( '_mobo_simple_variant_resolution_message', sanitize_text_field( (string) $message ) );
				$current->update_meta_data( '_mobo_simple_variant_resolution_at', gmdate( 'c' ) );
				update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
				$current->save();
			}
			return;
		}
		$product = new WC_Product_Simple( $product_id );

		foreach ( array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id', 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid' ) as $meta_key ) {
			$this->delete_product_meta_if_present( $product, $meta_key );
		}

		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->set_backorders( 'no' ); /* B13: zero must never become onbackorder. */
		$product->set_stock_status( 'outofstock' );
		$product->update_meta_data( '_mobo_simple_variant_mapped', '0' );
		$product->update_meta_data( '_mobo_simple_variant_resolution_status', sanitize_key( (string) $reason ) );
		$product->update_meta_data( '_mobo_simple_variant_resolution_message', sanitize_text_field( (string) $message ) );
		$product->update_meta_data( '_mobo_simple_variant_resolution_at', gmdate( 'c' ) );
		update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
		$product->save();

		/* Stale parent-level Variant mappings are not purchase authority, but leaving
		 * them behind can misroute future identity lookup. Remove only variation rows
		 * pointing at this exact parent; failure stays explicitly incomplete/retryable. */
		if ( $this->product_map instanceof Mobo_Core_Product_Map && ! $this->product_map->delete_variation_by_post_id_verified( $product_id ) ) {
			update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
			update_post_meta( $product_id, '_mobo_simple_variant_resolution_status', 'map_cleanup_failed' );
		}

		wc_delete_product_transients( $product_id );
	}

	private function simple_product_matches_variant_payload( $product, $data ) {
		if ( ! $product instanceof WC_Product_Simple ) {
			return false;
		}
		if ( $this->rules->should_update_title() ) {
			$title = $this->source_post_title( $data );
			if ( '' !== $title && (string) $product->get_name() !== $title ) {
				return false;
			}
		}
		if ( $this->rules->should_update_stock() ) {
			$present = false;
			$stock   = $this->get_stock_value_from_payload( $data, $present );
			if ( $present && ! $this->product_stock_matches_value( $product, $stock ) ) {
				return false;
			}
		}
		if ( ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) && ( $this->product_price_field_present( $data ) || $this->product_compare_price_field_present( $data ) ) ) {
			$product_id  = absint( $product->get_id() );
			$raw_price   = $this->product_price_field_present( $data ) ? $this->get_value( $data, 'price', null ) : get_post_meta( $product_id, 'mobo_api_price', true );
			$raw_compare = $this->product_compare_price_field_present( $data ) ? $this->get_compare_price_field_value( $data, null ) : get_post_meta( $product_id, 'mobo_api_compare_price', true );
			if ( $this->product_price_field_present( $data ) || ( null !== $raw_price && '' !== $raw_price ) ) {
				$pair = $this->price_calculator->calculate_price_pair(
					$product_id,
					$raw_price,
					$raw_compare,
					'product'
				);
				if ( ! empty( $pair['error'] ) ) {
					return false;
				}
				if ( ! $this->price_pair_matches_object( $product, $pair ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private function store_simple_variant_identity_meta_fast( $product_id, $variant_data, $product_guid = '' ) {
		$portal_variant_id   = $this->extract_portal_variant_id( $variant_data );
		$external_variant_id = $this->extract_external_variant_id( $variant_data );
		$variant_guid        = $this->extract_simple_variant_guid( $variant_data );
		if ( $portal_variant_id > 0 ) {
			foreach ( array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' ) as $key ) {
				$this->update_post_meta_if_changed( $product_id, $key, $portal_variant_id );
			}
		} elseif ( '' !== $external_variant_id ) {
			foreach ( array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' ) as $key ) {
				$this->delete_post_meta_verified( $product_id, $key );
			}
		}
		if ( '' !== $external_variant_id ) {
			$this->update_post_meta_if_changed( $product_id, '_mobo_external_variant_id', $external_variant_id );
		}
		if ( '' !== $variant_guid ) {
			foreach ( array( 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid' ) as $key ) {
				$this->update_post_meta_if_changed( $product_id, $key, $variant_guid );
			}
		}
		if ( '' !== $product_guid ) {
			$this->update_post_meta_if_changed( $product_id, 'product_guid', sanitize_text_field( (string) $product_guid ) );
		}
	}

	private function extract_embedded_simple_variant_data( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		foreach ( array( 'variant', 'defaultVariant', 'default_variant', 'singleVariant', 'single_variant', 'productVariant', 'product_variant' ) as $key ) {
			$candidate = $this->get_value( $data, $key, null );
			if ( is_array( $candidate ) && $this->looks_like_variant_payload( $candidate ) ) {
				return $candidate;
			}
		}

		foreach ( array( 'variants', 'variantItems', 'variant_items' ) as $key ) {
			$candidates = $this->get_value( $data, $key, null );
			if ( is_array( $candidates ) && $this->is_list_array( $candidates ) && 1 === count( $candidates ) && is_array( $candidates[0] ) ) {
				return $candidates[0];
			}
		}

		return $this->looks_like_variant_payload( $data ) ? $data : array();
	}

	private function looks_like_variant_payload( $data ) {
		if ( ! is_array( $data ) || empty( $data ) ) {
			return false;
		}

		foreach ( array( 'external_variant_id', 'externalVariantId', 'ExternalVariantId', 'portal_variant_id', 'portalVariantId', 'PortalVariantId', 'variant_guid', 'variantGuid', 'variantId', 'variant_id' ) as $key ) {
			if ( null !== $this->get_value( $data, $key, null ) ) {
				return true;
			}
		}

		/* Storefront cart/API variant objects have id plus variant-specific fields. */
		$id = $this->extract_positive_int_from_payload( $data, array( 'id' ) );
		$variant_detail_present = Mobo_Core_Payload_Field_Policy::is_present(
			$data,
			array( 'status', 'Status', 'min', 'Min', 'max', 'Max', 'price', 'Price', 'type', 'Type', 'attributes', 'Attributes' )
		);
		return $id > 0 && $variant_detail_present;
	}

	private function extract_simple_variant_guid( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		foreach ( array( 'variant_guid', 'variantGuid', 'variantId', 'guid', 'remote_guid', 'remoteGuid', 'entity_guid', 'entityGuid', 'entity_id', 'entityId' ) as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $data, $key, '' ) );
			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	private function variant_has_selection_attributes( $variant_data ) {
		if ( ! is_array( $variant_data ) ) {
			return false;
		}

		return ! empty( $this->normalize_variation_attributes( $this->get_value( $variant_data, 'attributes', array() ) ) );
	}

	private function get_stored_portal_variant_id( $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return 0;
		}

		foreach ( array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' ) as $meta_key ) {
			$value = absint( get_post_meta( $product_id, $meta_key, true ) );
			if ( $value > 0 ) {
				return $value;
			}
		}

		return 0;
	}

	/**
	 * Validate every child that an authoritative Simple transition may retire
	 * before parent attributes/type are mutated. Manual/non-Mobo children are
	 * intentionally preserved; conflicting Mobo aliases or unavailable map state
	 * block the transition fail-closed.
	 *
	 * @param int $product_id Product ID.
	 * @return true|WP_Error
	 */
	private function preflight_simple_variation_cleanup( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || ! class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ) {
			return new WP_Error( 'mobo_core_simple_preflight_unavailable', 'Variation lifecycle preflight is unavailable for Simple topology conversion.' );
		}

		$children = array_values( array_unique( array_merge( $this->get_live_variation_children( $product_id ), $this->get_pending_quarantine_children( $product_id ) ) ) );
		foreach ( $children as $variation_id ) {
			$preflight = Mobo_Core_Variation_Lifecycle_Policy::preflight_quarantine( $variation_id, $this->product_map );
			if ( is_wp_error( $preflight ) ) {
				return $preflight;
			}
			/* Non-Mobo children are outside Mobo topology ownership and remain live. */
			if ( empty( $preflight['owned'] ) ) {
				continue;
			}
		}
		return true;
	}

	/**
	 * Verify that an authoritative Simple transition preserved the historical
	 * quarantine object and did not mutate merchant/manual variations.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $owned_children Mobo-owned child IDs expected in Trash.
	 * @param array $manual_children Snapshot of non-Mobo children.
	 * @return true|WP_Error
	 */
	private function verify_simple_variation_preservation( $product_id, $owned_children, $manual_children ) {
		$product_id = absint( $product_id );

		foreach ( array_values( array_unique( array_map( 'absint', is_array( $owned_children ) ? $owned_children : array() ) ) ) as $variation_id ) {
			if ( $variation_id <= 0 ) {
				continue;
			}
			$post = get_post( $variation_id );
			if ( ! $post instanceof WP_Post
				|| 'product_variation' !== $post->post_type
				|| 'trash' !== $post->post_status
				|| absint( $post->post_parent ) !== $product_id
				|| '' === trim( (string) get_post_meta( $variation_id, '_mobo_variation_quarantine_reason', true ) )
			) {
				return new WP_Error(
					'mobo_core_simple_conversion_quarantine_not_preserved',
					'Historical Mobo variation quarantine was not preserved during Simple conversion.',
					array( 'productId' => $product_id, 'variationId' => $variation_id )
				);
			}
		}

		foreach ( is_array( $manual_children ) ? $manual_children : array() as $variation_id => $snapshot ) {
			$variation_id = absint( $variation_id );
			$post         = $variation_id > 0 ? get_post( $variation_id ) : null;
			if ( ! $post instanceof WP_Post
				|| 'product_variation' !== $post->post_type
				|| (string) $post->post_status !== (string) ( isset( $snapshot['status'] ) ? $snapshot['status'] : '' )
				|| absint( $post->post_parent ) !== absint( isset( $snapshot['parentId'] ) ? $snapshot['parentId'] : 0 )
			) {
				return new WP_Error(
					'mobo_core_simple_conversion_manual_variation_changed',
					'Manual/non-Mobo variation changed during authoritative Simple conversion; conversion safety verification failed.',
					array( 'productId' => $product_id, 'variationId' => $variation_id )
				);
			}
		}

		return true;
	}

	private function force_product_simple_if_needed( $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return new WP_Error( 'mobo_core_simple_product_invalid', 'Product ID is invalid for simple-product conversion.' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'mobo_core_simple_product_missing', 'Product could not be loaded for simple-product conversion.' );
		}

		/*
		 * Do not trust the current parent type as proof that children are already gone.
		 * Older builds could persist product_type=simple and leave live historical Mobo
		 * variations behind. Query by post_parent so retries self-heal that state too.
		 */
		$children        = array_values( array_unique( array_merge( $this->get_live_variation_children( $product_id ), $this->get_pending_quarantine_children( $product_id ) ) ) );
		$failed          = array();
		$owned_children  = array();
		$manual_children = array();

		foreach ( $children as $variation_id ) {
			$identity = class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ? Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $variation_id ) : new WP_Error( 'mobo_core_variation_lifecycle_missing', 'Variation lifecycle policy is unavailable.' );
			if ( is_wp_error( $identity ) ) {
				$failed[ $variation_id ] = $identity->get_error_message();
				continue;
			}
			if ( empty( $identity['owned'] ) ) {
				/* Manual/non-Mobo variations are outside Mobo topology ownership. Snapshot
				 * their exact post state because WooCommerce normally force-deletes every
				 * variation when a Variable product is saved as non-Variable. */
				$post = get_post( $variation_id );
				if ( $post instanceof WP_Post ) {
					$manual_children[ absint( $variation_id ) ] = array(
						'status'   => (string) $post->post_status,
						'parentId' => absint( $post->post_parent ),
					);
				}
				continue;
			}
			$owned_children[] = absint( $variation_id );
			$retired = $this->quarantine_variation( $variation_id, 'authoritative-variable-to-simple', array( 'parentId' => $product_id ) );
			if ( is_wp_error( $retired ) ) {
				$failed[ $variation_id ] = $retired->get_error_message();
			}
		}

		if ( ! empty( $failed ) ) {
			update_post_meta( $product_id, '_mobo_simple_conversion_failed_variations', wp_json_encode( $failed ) );
			update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
			return new WP_Error( 'mobo_core_simple_conversion_quarantine_failed', 'One or more Mobo variations could not be quarantined; product type was preserved for a safe retry.', array( 'variations' => $failed ) );
		}

		if ( $product instanceof WC_Product_Simple && ! $product instanceof WC_Product_Variable ) {
			$preserved = $this->verify_simple_variation_preservation( $product_id, $owned_children, $manual_children );
			if ( is_wp_error( $preserved ) ) {
				update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
				return $preserved;
			}
			delete_post_meta( $product_id, '_mobo_simple_conversion_failed_variations' );
			return true;
		}

		/* Product type mutation belongs to WooCommerce CRUD. Do not pre-write the
		 * product_type term: doing so hides the old type from WC_Data_Store and
		 * suppresses woocommerce_product_type_changed. The shared policy performs
		 * the typed save, scopes variation preservation to this transition, refreshes
		 * the read boundary and verifies raw taxonomy + factory + object class. */
		$simple_conversion = class_exists( 'Mobo_Core_Product_Type_Policy' )
			? Mobo_Core_Product_Type_Policy::to_simple_preserving_variations( $product_id )
			: new WP_Error( 'mobo_core_product_type_policy_missing', 'Product type transition policy is unavailable.' );
		if ( is_wp_error( $simple_conversion ) ) {
			update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
			return $simple_conversion;
		}

		$preserved = $this->verify_simple_variation_preservation( $product_id, $owned_children, $manual_children );
		if ( is_wp_error( $preserved ) ) {
			update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
			return $preserved;
		}

		delete_post_meta( $product_id, '_mobo_simple_conversion_failed_variations' );
		wc_delete_product_transients( $product_id );
		return true;
	}

	private function clear_simple_variant_mapping_from_parent( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return new WP_Error( 'mobo_core_simple_variant_cleanup_invalid', 'Product ID is invalid for simple-variant identity cleanup.' );
		}

		/*
		 * Checkout/purchase identity is the safety boundary. Clear and verify those
		 * aliases before touching Product Map so a database cleanup failure can leave
		 * an incomplete mapping to retry, but can never leave a stale purchasable
		 * Variant identity on a now-Simple parent.
		 */
		$identity_keys = array(
			'portal_variant_id',
			'mobo_portal_variant_id',
			'_mobo_portal_variant_id',
			'_mobo_external_variant_id',
			'variant_guid',
			'mobo_variant_guid',
			'_mobo_variant_guid',
			'_mobo_simple_variant_mapped',
			'_mobo_simple_variant_mapped_at',
			'_mobo_simple_variant_resolution_status',
			'_mobo_simple_variant_resolution_message',
			'_mobo_simple_variant_resolution_at',
			'_mobo_simple_variant_source_hash',
		);
		foreach ( $identity_keys as $meta_key ) {
			delete_post_meta( $product_id, $meta_key );
		}
		foreach ( $identity_keys as $meta_key ) {
			if ( '' !== (string) get_post_meta( $product_id, $meta_key, true ) ) {
				update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
				return new WP_Error( 'mobo_core_simple_variant_meta_cleanup_failed', 'Legacy simple-variant purchase identity metadata did not clear durably after product type conversion.' );
			}
		}

		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			/* Delete only variation rows that point at this parent post and verify the
			 * read-back. A stale remote GUID alias must not delete a row owned by another
			 * local object. */
			if ( ! $this->product_map->delete_variation_by_post_id_verified( $product_id ) ) {
				update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
				return new WP_Error( 'mobo_core_simple_variant_map_post_cleanup_failed', 'Could not remove and verify legacy variation mappings that still point at the parent product.' );
			}
		}

		return true;
	}

	private function ensure_product_type_for_variants( $product_id, $variant_total_count ) {
		$product_id          = absint( $product_id );
		$variant_total_count = absint( $variant_total_count );

		if ( $product_id <= 0 ) {
			return null;
		}

		$current = wc_get_product( $product_id );

		if ( ! $current instanceof WC_Product ) {
			return null;
		}

		if ( $variant_total_count > 0 ) {
			if ( $current instanceof WC_Product_Variable ) {
				/* Older builds could persist the variable product_type while failing to
				 * remove a parent-level simple-variant identity/map. Re-run the cleanup
				 * when any such identity remains so retries are self-healing. */
				$has_parent_variant_identity = '1' === (string) get_post_meta( $product_id, '_mobo_simple_variant_mapped', true )
					|| '' !== trim( (string) get_post_meta( $product_id, 'variant_guid', true ) )
					|| '' !== (string) get_post_meta( $product_id, '_mobo_external_variant_id', true )
					|| absint( get_post_meta( $product_id, 'portal_variant_id', true ) ) > 0;
				if ( $has_parent_variant_identity ) {
					$cleanup = $this->clear_simple_variant_mapping_from_parent( $product_id );
					if ( is_wp_error( $cleanup ) ) {
						return $cleanup;
					}
				}
				return $current;
			}

			$had_simple_variant_mapping = '1' === (string) get_post_meta( $product_id, '_mobo_simple_variant_mapped', true );
			$variable = class_exists( 'Mobo_Core_Product_Type_Policy' )
				? Mobo_Core_Product_Type_Policy::to_variable( $product_id )
				: new WP_Error( 'mobo_core_product_type_policy_missing', 'Product type transition policy is unavailable.' );
			if ( is_wp_error( $variable ) ) {
				update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
				return $variable;
			}

			/* The parent-level simple-variant identity is recovery evidence until the
			 * WooCommerce product-type transition itself is durable. Removing it before
			 * verified read-back can orphan a simple product after a transient failure. */
			if ( $had_simple_variant_mapping ) {
				$cleanup = $this->clear_simple_variant_mapping_from_parent( $product_id );
				if ( is_wp_error( $cleanup ) ) {
					return $cleanup;
				}
			}

			return $variable;
		}

		$simple_conversion = $this->force_product_simple_if_needed( $product_id );
		if ( is_wp_error( $simple_conversion ) ) {
			return $simple_conversion;
		}

		return wc_get_product( $product_id );
	}

	private function is_authoritative_variant_list_payload( $payload, $variants ) {
		if ( ! is_array( $payload ) ) {
			return false;
		}

		$explicit_keys = array(
			'variantListAuthoritative',
			'variant_list_authoritative',
			'authoritativeVariantList',
			'authoritative_variant_list',
			'isFullVariantSnapshot',
			'is_full_variant_snapshot',
			'fullVariantSync',
			'full_variant_sync',
		);

		foreach ( $explicit_keys as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				return $this->to_bool( $payload[ $key ] );
			}
		}

		/*
		 * Webhook UpdateVariant messages are deltas, not full snapshots. A payload like
		 * { event: UpdateVariant, data: { totalCount: 1, data: [one variant] } }
		 * must never mark the other product variations as missing/out-of-stock.
		 */
		$event = strtolower( sanitize_text_field( (string) $this->get_value( $payload, 'event', '' ) ) );

		if ( 'updatevariant' === $event ) {
			return false;
		}

		$entity_type = strtolower( sanitize_text_field( (string) $this->get_value( $payload, 'entityType', $this->get_value( $payload, 'entity_type', '' ) ) ) );

		if ( 'variant' === $entity_type ) {
			return false;
		}

		/*
		 * Safety rule: UpdateVariant payloads are treated as delta/webhook updates unless
		 * they explicitly opt in via variantListAuthoritative/isFullVariantSnapshot.
		 * This prevents four single-variant webhooks from marking each other's variants
		 * as missing/out-of-stock. Full manual/API syncs set the explicit flag above.
		 */
		return false;
	}

	private function has_response_key( $response, $key ) {
		if ( ! is_array( $response ) ) {
			return false;
		}
		if ( array_key_exists( $key, $response ) || array_key_exists( ucfirst( $key ), $response ) ) {
			return true;
		}
		return false;
	}

	private function extract_explicit_list_data( $response, $context ) {
		if ( ! is_array( $response ) || ! $this->has_response_key( $response, 'data' ) ) {
			return new WP_Error( 'mobo_core_api_data_missing', sanitize_text_field( (string) $context ) . ' response is missing explicit data.' );
		}
		$data = $this->get_value( $response, 'data', null );
		if ( ! is_array( $data ) || ! $this->is_list_array( $data ) ) {
			return new WP_Error( 'mobo_core_api_data_invalid', sanitize_text_field( (string) $context ) . ' response data must be a list.' );
		}
		return $data;
	}

	private function validated_variant_page_items( $response ) {
		if ( ! is_array( $response ) || ! $this->has_response_key( $response, 'data' ) ) {
			return new WP_Error( 'mobo_core_variant_data_missing', 'Variant response is missing explicit data.' );
		}
		$raw = $this->get_value( $response, 'data', null );
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'mobo_core_variant_data_invalid', 'Variant response data is not an array.' );
		}
		$items = $this->normalize_variant_items_from_response( $response );
		if ( empty( $items ) && ! empty( $raw ) ) {
			return new WP_Error( 'mobo_core_variant_data_shape_invalid', 'Variant response data has an unsupported shape.' );
		}
		return $items;
	}

	private function validated_page_has_more( $response, $page_number ) {
		if ( ! is_array( $response ) ) {
			return new WP_Error( 'mobo_core_pagination_invalid', 'Pagination response is invalid.' );
		}
		if ( $this->has_response_key( $response, 'hasMore' ) ) {
			return $this->to_bool( $this->get_value( $response, 'hasMore', false ) );
		}
		if ( $this->has_response_key( $response, 'isLastPage' ) ) {
			return ! $this->to_bool( $this->get_value( $response, 'isLastPage', false ) );
		}
		$total_pages = absint( $this->get_value( $response, 'totalPages', 0 ) );
		if ( $total_pages > 0 ) {
			$current_page = max( 1, absint( $this->get_value( $response, 'pageNumber', $page_number ) ) );
			return $current_page < $total_pages;
		}
		return new WP_Error( 'mobo_core_pagination_signal_missing', 'Response is missing hasMore/isLastPage/totalPages.' );
	}

	private function normalize_categories_api_response( $response ) {
		if ( ! is_array( $response ) ) {
			return new WP_Error( 'mobo_core_categories_shape_invalid', 'Categories response is not an array.' );
		}
		if ( $this->is_list_array( $response ) ) {
			return $response;
		}
		if ( ! $this->has_response_key( $response, 'data' ) ) {
			return new WP_Error( 'mobo_core_categories_data_missing', 'Categories envelope is missing explicit data.' );
		}
		$data = $this->get_value( $response, 'data', null );
		if ( ! is_array( $data ) || ! $this->is_list_array( $data ) ) {
			return new WP_Error( 'mobo_core_categories_data_invalid', 'Categories data must be a list.' );
		}
		return $data;
	}

	private function normalize_variant_items_from_response( $response ) {
		$items = $this->get_value( $response, 'data', array() );

		if ( is_array( $items ) && ! $this->is_list_array( $items ) ) {
			$nested_items = $this->get_value( $items, 'data', null );

			if ( is_array( $nested_items ) ) {
				$items = $nested_items;
			} elseif ( $this->looks_like_variant_payload( $items ) ) {
				/* A single variant may be returned as data:{...}, not data:[{...}]. */
				$items = array( $items );
			}
		}

		return is_array( $items ) && $this->is_list_array( $items ) ? $items : array();
	}

	private function get_stock_value_from_payload( $data, &$present = false ) {
		$inspection = Mobo_Core_Payload_Field_Policy::inspect( $data, Mobo_Core_Payload_Field_Policy::stock_aliases() );
		$present    = ! empty( $inspection['present'] );

		return $present ? $inspection['value'] : null;
	}

	private function variation_stock_matches_payload( $variation_id, $data ) {
		if ( ! $this->rules->should_update_stock() ) {
			return true;
		}

		$stock_present = false;
		$stock_value   = $this->get_stock_value_from_payload( $data, $stock_present );

		if ( ! $stock_present ) {
			return true;
		}

		$variation = wc_get_product( absint( $variation_id ) );

		if ( ! $variation instanceof WC_Product_Variation ) {
			return false;
		}

		return $this->product_stock_matches_value( $variation, $stock_value );
	}

	private function is_valid_api_stock_payload_value( $stock ) {
		if ( null === $stock || '' === $stock ) {
			return true;
		}

		return null !== $this->normalize_api_stock_quantity( $stock );
	}

	private function normalize_api_stock_quantity( $stock ) {
		/* Portal's stock contract is nullable integer. A negative source balance means
		 * there is no sellable stock and converges to WooCommerce quantity zero instead
		 * of poisoning the entire Product/Variation event. Never coerce fractional,
		 * scientific-notation, boolean, or partially numeric values. JSON integers
		 * decode as int; integer strings are accepted for older serializers. */
		if ( is_int( $stock ) ) {
			return max( 0, $stock );
		}

		if ( ! is_string( $stock ) ) {
			return null;
		}

		$raw_stock = trim( $stock );
		if ( '' === $raw_stock || ! preg_match( '/^-?[0-9]+$/D', $raw_stock ) ) {
			return null;
		}

		if ( '-' === substr( $raw_stock, 0, 1 ) ) {
			return 0;
		}

		$digits = ltrim( $raw_stock, '0' );
		if ( '' === $digits ) {
			return 0;
		}

		$max = (string) PHP_INT_MAX;
		if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ) {
			return null;
		}

		return (int) $digits;
	}

	private function apply_api_stock( $product, $stock ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$stock_quantity  = null;
		$sanitized_stock = '';
		if ( null !== $stock && '' !== $stock ) {
			$raw_stock       = is_scalar( $stock ) ? trim( (string) $stock ) : '';
			$stock_quantity  = $this->normalize_api_stock_quantity( $stock );
			$sanitized_stock = sanitize_text_field( $raw_stock );

			if ( null === $stock_quantity ) {
				$changed = false;
				$changed = $this->update_product_meta_if_changed( $product, '_mobo_last_api_stock_raw', $sanitized_stock ) || $changed;
				$changed = $this->update_product_meta_if_changed( $product, '_mobo_stock_update_source', 'api-invalid-stock-skipped' ) || $changed;
				if ( $changed ) {
					$this->update_product_meta_if_changed( $product, '_mobo_stock_update_skipped_at', gmdate( 'c' ) );
				}
				return false;
			}
		}

		/* B13/B14/B16: decisions live in Mobo_Core_Stock_Policy. Zero is always out of
		 * stock with backorders 'no'; unknown stock never makes a NEW object purchasable
		 * and keeps the last known state of an existing one; a Source-hidden/inactive
		 * object is persisted out of stock while its Source state is remembered. */
		$natural = Mobo_Core_Stock_Policy::natural_state( $stock_quantity, $this->product_has_known_stock_state( $product ), $this->current_natural_stock_state( $product ) );
		$blocked = Mobo_Core_Source_Visibility::is_blocked( $product );
		$changed = Mobo_Core_Source_Visibility::set_stock_props( $product, Mobo_Core_Stock_Policy::persisted_state( $natural, $blocked ) );
		if ( $blocked ) {
			$changed = Mobo_Core_Source_Visibility::remember_natural_stock( $product, $natural ) || $changed;
		} else {
			$changed = Mobo_Core_Source_Visibility::forget_natural_stock( $product ) || $changed;
		}

		$changed = $this->delete_product_meta_if_present( $product, '_mobo_stock_payload_missing' ) || $changed;
		$changed = $this->update_product_meta_if_changed( $product, '_mobo_last_api_stock_raw', $sanitized_stock ) || $changed;
		if ( null !== $stock_quantity ) {
			$changed = $this->update_product_meta_if_changed( $product, '_mobo_last_api_stock_quantity', $stock_quantity ) || $changed;
		}
		$changed = $this->update_product_meta_if_changed( $product, '_mobo_stock_update_source', $natural['source'] ) || $changed;

		if ( $changed ) {
			$this->update_product_meta_if_changed( $product, '_mobo_last_api_stock_applied_at', gmdate( 'c' ) );
		}

		return true;
	}

	/**
	 * Whether Mobo (or the merchant, with a managed quantity) already gave this object a
	 * stock state. Only an object WITHOUT one is "new" for unknown-stock decisions.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	private function product_has_known_stock_state( $product ) {
		if ( absint( $product->get_id() ) <= 0 ) {
			return false;
		}
		if ( '' !== (string) $product->get_meta( '_mobo_stock_update_source', true, 'edit' ) ) {
			return true;
		}
		return (bool) $product->get_manage_stock( 'edit' ) && null !== $product->get_stock_quantity( 'edit' );
	}

	/**
	 * Natural (Source) stock state: the remembered one while the blocked overlay is
	 * persisted, otherwise the current WooCommerce props.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array
	 */
	private function current_natural_stock_state( $product ) {
		$stored = Mobo_Core_Source_Visibility::stored_natural_stock( $product );
		if ( is_array( $stored ) ) {
			return $stored;
		}
		return array(
			'manage' => (bool) $product->get_manage_stock( 'edit' ),
			'qty'    => $product->get_stock_quantity( 'edit' ),
			'status' => (string) $product->get_stock_status( 'edit' ),
		);
	}

	private function apply_product_slug( $product, $data ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		/*
		 * Current behavior: use url as slug source.
		 * If API later sends explicit slug, prefer slug.
		 */
		$slug = sanitize_title( (string) $this->get_value( $data, 'slug', '' ) );

		if ( '' === $slug ) {
			$slug = sanitize_title( trim( (string) $this->get_value( $data, 'url', '' ), '/' ) );
		}

		if ( '' === $slug || $this->product_slug_matches_expected( $product, $slug ) ) {
			return;
		}

		$product->set_slug( $slug );
	}

	/**
	 * B17: a slug WordPress uniquified ("x" -> "x-2" because another post owns "x") is
	 * the converged state. Re-setting "x" would be uniquified again on every Sync and
	 * the product would never converge. Accept "x-N" only when wp_unique_post_slug()
	 * still produces exactly that value for this post; once "x" is free again the
	 * check fails and the next Sync moves the product back to "x".
	 *
	 * @param WC_Product $product Product.
	 * @param string     $expected Expected (sanitized) slug.
	 * @return bool
	 */
	private function product_slug_matches_expected( $product, $expected ) {
		$current  = (string) $product->get_slug( 'edit' );
		$expected = (string) $expected;
		if ( $current === $expected ) {
			return true;
		}
		$product_id = absint( $product->get_id() );
		if ( '' === $expected || $product_id <= 0 || 0 !== strpos( $current, $expected . '-' ) || ! function_exists( 'wp_unique_post_slug' ) ) {
			return false;
		}
		if ( ! preg_match( '/^[2-9][0-9]*$|^[1-9][0-9]+$/D', substr( $current, strlen( $expected ) + 1 ) ) ) {
			return false;
		}
		$status = function_exists( 'get_post_status' ) ? (string) get_post_status( $product_id ) : '';
		$parent = function_exists( 'wp_get_post_parent_id' ) ? absint( wp_get_post_parent_id( $product_id ) ) : 0;
		/* Draft/pending posts are not uniquified by WordPress; treat them as published so
		 * the comparison answers "what would publishing this slug yield". */
		if ( in_array( $status, array( '', 'draft', 'pending', 'auto-draft' ), true ) ) {
			$status = 'publish';
		}
		return wp_unique_post_slug( $expected, $product_id, $status, 'product', $parent ) === $current;
	}

	private function apply_product_dates( $product, $data ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$published_at = sanitize_text_field( (string) $this->get_value( $data, 'publishedAt', '' ) );

		if ( '' === $published_at ) {
			return;
		}

		$timestamp = ( class_exists( 'Mobo_Core_Environment' ) ? Mobo_Core_Environment::utc_strtotime( $published_at ) : strtotime( $published_at ) );

		if ( false === $timestamp || $timestamp <= 0 ) {
			$this->update_product_meta_if_changed( $product, 'published_at', $published_at );
			return;
		}

		$gmt_date   = gmdate( 'Y-m-d H:i:s', $timestamp );
		$local_date = get_date_from_gmt( $gmt_date, 'Y-m-d H:i:s' );
		$current_created = $product->get_date_created( 'edit' );

		if ( ! $current_created instanceof WC_DateTime || (int) $current_created->getTimestamp() !== (int) $timestamp ) {
			$date = new WC_DateTime( '@' . $timestamp );
			$date->setTimezone( new DateTimeZone( 'UTC' ) );
			$product->set_date_created( $date );
		}


		$this->update_product_meta_if_changed( $product, 'published_at', $published_at );
		$this->update_product_meta_if_changed( $product, 'mobo_published_at_gmt', $gmt_date );
		$this->update_product_meta_if_changed( $product, 'mobo_published_at_local', $local_date );
	}

	private function apply_price_to_product( $product, $data, $context, $is_new_object = false ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( ! $is_new_object && ! $this->rules->should_update_price() && ! $this->rules->should_update_compare_price() ) {
			return;
		}

		$price_present   = $this->product_price_field_present( $data );
		$compare_present = $this->product_compare_price_field_present( $data );
		if ( ! $is_new_object && ! $price_present && ! $compare_present ) {
			return;
		}

		$object_id         = absint( $product->get_id() );
		$raw_price         = $price_present || $is_new_object ? $this->get_value( $data, 'price', null ) : get_post_meta( $object_id, 'mobo_api_price', true );
		$raw_compare_price = $compare_present || $is_new_object ? $this->get_compare_price_field_value( $data, null ) : get_post_meta( $object_id, 'mobo_api_compare_price', true );
		$source_changed    = false;

		if ( ! $is_new_object && ! $price_present && ( null === $raw_price || '' === $raw_price ) ) {
			if ( $compare_present ) {
				$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_api_compare_price', null === $raw_compare_price || '' === $raw_compare_price ? '' : wc_format_decimal( $raw_compare_price ) ) || $source_changed;
			}
			if ( $source_changed ) {
				$this->update_product_meta_if_changed( $product, 'mobo_price_policy_updated_at', gmdate( 'c' ) );
			}
			return;
		}

		$parent_id = 'variation' === sanitize_key( (string) $context ) ? absint( $product->get_parent_id( 'edit' ) ) : 0;
		$pair = $this->price_calculator->calculate_price_pair(
			$object_id,
			$raw_price,
			$raw_compare_price,
			$context,
			$parent_id
		);

		if ( ! empty( $pair['error'] ) ) {
			throw new RuntimeException( 'Invalid Mobo price payload: ' . sanitize_text_field( (string) $pair['error'] ) );
		}

		if ( $price_present || $is_new_object ) {
			$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_api_price', null === $raw_price || '' === $raw_price ? '' : wc_format_decimal( $raw_price ) ) || $source_changed;
		}
		if ( $compare_present || $is_new_object ) {
			$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_api_compare_price', null === $raw_compare_price || '' === $raw_compare_price ? '' : wc_format_decimal( $raw_compare_price ) ) || $source_changed;
		}
		$effective_policy_type = isset( $pair['policy_type'] ) && '' !== (string) $pair['policy_type'] ? sanitize_key( (string) $pair['policy_type'] ) : (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' );
		$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_price_policy_type', $effective_policy_type ) || $source_changed;

		/* B2/B15: the Source says "no price" (null/empty/0/negative). Clear every
		 * WooCommerce price so the object stays visible but is NOT purchasable
		 * until a valid price arrives; never keep a stale or zero price. */
		if ( ! empty( $pair['unavailable'] ) ) {
			if ( '' !== (string) $product->get_regular_price( 'edit' ) ) {
				$product->set_regular_price( '' );
			}
			if ( '' !== (string) $product->get_sale_price( 'edit' ) ) {
				$product->set_sale_price( '' );
			}
			if ( '' !== (string) $product->get_price( 'edit' ) ) {
				$product->set_price( '' );
			}
			$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_calculated_regular_price', '' ) || $source_changed;
			$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_calculated_sale_price', '' ) || $source_changed;
			$source_changed = $this->update_product_meta_if_changed( $product, Mobo_Core_Reprice_Queue::META_PRICE_UNAVAILABLE, isset( $pair['unavailable_reason'] ) ? sanitize_key( (string) $pair['unavailable_reason'] ) : 'source-price-missing' ) || $source_changed;
			if ( $source_changed ) {
				$this->update_product_meta_if_changed( $product, 'mobo_price_policy_updated_at', gmdate( 'c' ) );
			}
			return;
		}
		$source_changed = $this->delete_product_meta_if_present( $product, Mobo_Core_Reprice_Queue::META_PRICE_UNAVAILABLE ) || $source_changed;

		if ( null !== $pair['regular_price'] && '' !== $pair['regular_price'] ) {
			$desired_regular = wc_format_decimal( $pair['regular_price'] );
			if ( wc_format_decimal( $product->get_regular_price( 'edit' ) ) !== $desired_regular ) {
				$product->set_regular_price( $pair['regular_price'] );
			}
			$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_calculated_regular_price', $pair['regular_price'] ) || $source_changed;
		}

		if ( isset( $pair['sale_price'] ) ) {
			$desired_sale = wc_format_decimal( $pair['sale_price'] );
			if ( wc_format_decimal( $product->get_sale_price( 'edit' ) ) !== $desired_sale ) {
				$product->set_sale_price( $pair['sale_price'] );
			}
			$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_calculated_sale_price', $pair['sale_price'] ) || $source_changed;
		}

		if ( $source_changed ) {
			$this->update_product_meta_if_changed( $product, 'mobo_price_policy_updated_at', gmdate( 'c' ) );
		}
	}


	/**
	 * Treat temporary API/HTTP failures as retryable manual-sync errors.
	 *
	 * A single timeout must not poison the sync state with lastError, because
	 * get_manual_sync_status() stops the self-runner when lastError is not empty.
	 *
	 * @param array    $state   Current manual sync state.
	 * @param WP_Error $error   Request error.
	 * @param string   $message Human message.
	 * @return array
	 */
	/** Identify database-read Repair failures that are safe to retry without advancing phase/cursor. */
	private function is_retryable_repair_integrity_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$code = sanitize_key( (string) $error->get_error_code() );
		return 0 === strpos( $code, 'mobo_core_repair_' )
			&& ( false !== strpos( $code, '_read_failed' ) || false !== strpos( $code, '_count_failed' ) );
	}

	private function handle_transient_request_error( &$state, $error, $message ) {
		$error_code = is_wp_error( $error ) ? sanitize_key( (string) $error->get_error_code() ) : '';
		$error_data = is_wp_error( $error ) ? $error->get_error_data() : array();
		$error_data = is_array( $error_data ) ? $error_data : array();
		$error_msg  = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;
		$retry_after = absint( $error_data['retry_after_seconds'] ?? 0 );
		$is_network_deferral = in_array( $error_code, array( 'mobo_core_dependency_backoff', 'mobo_core_api_request_circuit_open' ), true );

		/* A circuit/backoff result means no new HTTP request was attempted. Counting it
		 * against transientRetryCount turns one real outage into many fake retries and
		 * can spin a targeted recovery over the same target set. Park the generation
		 * until the dependency's durable retry boundary instead. */
		if ( $is_network_deferral ) {
			$delay_seconds = max( 1, $retry_after > 0 ? $retry_after : 5 );
			$state['status']                 = 'waiting_for_portal';
			$state['waitingForPortalSince']  = empty( $state['waitingForPortalSince'] ) ? time() : absint( $state['waitingForPortalSince'] );
			$state['waitingForPortalReason'] = 'network_backoff';
			$state['nextRetryAt']            = max( absint( $state['nextRetryAt'] ?? 0 ), time() + $delay_seconds );
			$state['lastTransientError']     = sanitize_text_field( $error_msg );
			$state['lastError']              = '';
			$state['lastMessage']            = sprintf( '%s درخواست جدیدی ارسال نشد؛ backoff شبکه فعال است و تلاش بعدی حدود %d ثانیه بعد انجام می‌شود.', $message, $delay_seconds );
			$state['updatedAt']              = time();
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}
			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		$try_count = absint( $state['transientRetryCount'] ?? 0 ) + 1;
		$max_try   = Mobo_Core_Settings::get_int( 'mobo_core_transient_retry_max_try', 10, 1, 50 );

		$state['transientRetryCount'] = $try_count;
		$state['lastTransientError']  = sanitize_text_field( $error_msg );
		$state['waitingForPortalReason'] = '';
		$state['updatedAt']           = time();

		if ( $try_count >= $max_try ) {
			$delay_seconds = Mobo_Core_Settings::get_int( 'mobo_core_waiting_for_portal_retry_delay_seconds', 60, 10, 3600 );
			$delay_seconds = max( $delay_seconds, $retry_after );

			$state['status']                 = 'waiting_for_portal';
			$state['waitingForPortalSince']  = empty( $state['waitingForPortalSince'] ) ? time() : absint( $state['waitingForPortalSince'] );
			$state['waitingForPortalReason'] = 'retry_budget';
			$state['nextRetryAt']            = time() + $delay_seconds;
			$state['lastError']              = '';
			$state['lastMessage']            = sprintf( '%s اتصال به MoboCore پس از %d تلاش واقعی برقرار نشد. sync متوقف نشده؛ از همین نقطه در تلاش بعدی ادامه می‌دهد.', $message, $max_try );
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'] . ' ' . $error_msg, $this->get_manual_sync_status() );
		}

		/* Keep lastError empty so the self-runner keeps the sync resumable. */
		$state['status']      = 'running';
		$state['nextRetryAt'] = 0;
		$state['lastError']   = '';
		$state['lastMessage'] = sprintf( '%s خطای موقت؛ تلاش واقعی %d از %d. %s', $message, $try_count, $max_try, $error_msg );
		if ( ! $this->save_manual_sync_state( $state ) ) {
			return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
		}

		return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
	}

	/**
	 * Clear transient request retry state after a successful API response.
	 *
	 * @param array $state Current state.
	 * @return void
	 */
	private function clear_transient_request_error( &$state ) {
		$state['transientRetryCount']   = 0;
		$state['lastTransientError']    = '';
		$state['lastError']             = '';
		$state['waitingForPortalSince'] = 0;
		$state['waitingForPortalReason'] = '';
		$state['nextRetryAt']           = 0;
		if ( 'waiting_for_portal' === sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
			$state['status'] = 'running';
		}
	}

	/**
	 * Validate explicitly present source money fields before mutation. Absent values
	 * preserve existing state; null/empty are explicit nullable state; malformed
	 * numeric input fails closed instead of being wc_format_decimal()-collapsed.
	 *
	 * @param array  $data Payload.
	 * @param string $context Diagnostic context.
	 * @return true|WP_Error
	 */
	private function validate_money_payload_fields( $data, $context ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'mobo_core_money_payload_invalid', 'Mobo money payload is invalid.' );
		}
		$has_price = $this->product_price_field_present( $data );
		$has_compare = $this->product_compare_price_field_present( $data );
		if ( ! $has_price && ! $has_compare ) {
			return true;
		}
		if ( ! class_exists( 'Mobo_Core_Money_Policy' ) ) {
			return new WP_Error( 'mobo_core_money_policy_unavailable', 'Mobo monetary policy is unavailable.' );
		}
		$compat = Mobo_Core_Money_Policy::assert_pricing_compatible();
		if ( is_wp_error( $compat ) ) {
			return $compat;
		}

		if ( $has_price ) {
			$price = Mobo_Core_Payload_Field_Policy::value( $data, Mobo_Core_Payload_Field_Policy::price_aliases(), null );
			$valid = Mobo_Core_Money_Policy::validate_source_amount( $price, 0, true );
			if ( is_wp_error( $valid ) ) {
				return new WP_Error( $valid->get_error_code(), 'Mobo ' . sanitize_key( (string) $context ) . ' price: ' . $valid->get_error_message(), $valid->get_error_data() );
			}
		}

		if ( $has_compare ) {
			$compare = Mobo_Core_Payload_Field_Policy::value( $data, Mobo_Core_Payload_Field_Policy::compare_price_aliases(), null );
			$valid = Mobo_Core_Money_Policy::validate_source_amount( $compare, 0, true );
			if ( is_wp_error( $valid ) ) {
				return new WP_Error( $valid->get_error_code(), 'Mobo ' . sanitize_key( (string) $context ) . ' comparePrice: ' . $valid->get_error_message(), $valid->get_error_data() );
			}
		}

		return true;
	}

	/**
	 * Validate present authoritative Product collection fields before any WooCommerce
	 * mutation. Explicit empty arrays are valid desired state; malformed/non-array or
	 * partially invalid collections are not silently normalized into destructive
	 * subsets.
	 *
	 * @param array $data Product payload.
	 * @param bool  $is_new_product Whether this is a new local product.
	 * @return true|WP_Error
	 */
	private function validate_product_desired_state_payload( $data, $is_new_product = false ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'mobo_core_product_payload_invalid', 'Mobo product payload must be an object/array.' );
		}

		$money_integrity = $this->validate_money_payload_fields( $data, 'product' );
		if ( is_wp_error( $money_integrity ) ) {
			return $money_integrity;
		}

		if ( $this->product_images_field_present( $data ) && ( $is_new_product || $this->rules->should_update_images() ) ) {
			$images           = Mobo_Core_Payload_Field_Policy::value( $data, Mobo_Core_Payload_Field_Policy::image_aliases(), null );
			$image_integrity  = Mobo_Core_Image_Desired_State_Policy::validate_collection( $images );
			if ( is_wp_error( $image_integrity ) ) {
				return $image_integrity;
			}
		}

		if ( $this->product_attributes_field_present( $data ) ) {
			$attributes = $this->get_value( $data, 'attributes', null );
			if ( ! is_array( $attributes ) ) {
				return new WP_Error( 'mobo_core_product_attributes_invalid', 'Mobo product attributes must be an array when the field is present.' );
			}
			$seen_names = array();
			foreach ( $attributes as $attribute ) {
				if ( ! is_array( $attribute ) ) {
					return new WP_Error( 'mobo_core_product_attribute_row_invalid', 'Mobo product contains a malformed attribute row.' );
				}
				$name = $this->source_attribute_text( $this->get_value( $attribute, 'name', '' ) );
				$key  = sanitize_title( $name );
				$values = $this->get_value( $attribute, 'values', null );
				if ( '' === $key || ! is_array( $values ) || empty( $values ) ) {
					return new WP_Error( 'mobo_core_product_attribute_row_invalid', 'Mobo product attribute must contain a name and at least one value.' );
				}
				if ( isset( $seen_names[ $key ] ) ) {
					return new WP_Error( 'mobo_core_product_attribute_duplicate', 'Mobo product contains duplicate attribute definitions: ' . $key );
				}
				$seen_names[ $key ] = true;
				$seen_values = array();
				foreach ( $values as $value_row ) {
					if ( ! is_array( $value_row ) ) {
						return new WP_Error( 'mobo_core_product_attribute_value_invalid', 'Mobo product attribute contains a malformed value row.' );
					}
					$value = $this->source_attribute_text( $this->get_value( $value_row, 'value', '' ) );
					if ( '' === $value ) {
						return new WP_Error( 'mobo_core_product_attribute_value_invalid', 'Mobo product attribute contains an empty value.' );
					}
					$value_key = strtolower( $value );
					if ( isset( $seen_values[ $value_key ] ) ) {
						return new WP_Error( 'mobo_core_product_attribute_value_duplicate', 'Mobo product attribute contains a duplicate value: ' . $name . '=' . $value );
					}
					$seen_values[ $value_key ] = true;
				}
			}
		}

		if ( $this->product_categories_field_present( $data ) ) {
			$categories = $this->get_raw_product_categories_field( $data );
			if ( ! is_array( $categories ) ) {
				return new WP_Error( 'mobo_core_product_categories_invalid', 'Mobo product categories must be an array when the field is present.' );
			}
			$seen_categories = array();
			foreach ( $categories as $ref ) {
				$guid = is_array( $ref ) ? $this->extract_category_guid_for_storage( $ref ) : sanitize_text_field( (string) $ref );
				if ( ! $this->is_remote_guid_value( $guid ) ) {
					return new WP_Error( 'mobo_core_product_category_row_invalid', 'Mobo product contains a category row without a stable remote identity.' );
				}
				$key = strtolower( $guid );
				if ( isset( $seen_categories[ $key ] ) ) {
					return new WP_Error( 'mobo_core_product_category_duplicate', 'Mobo product category desired state contains a duplicate remote identity.' );
				}
				$seen_categories[ $key ] = true;
			}
		}

		return true;
	}

	private function get_raw_product_categories_field( $data ) {
		return Mobo_Core_Payload_Field_Policy::value(
			$data,
			Mobo_Core_Payload_Field_Policy::category_aliases(),
			null
		);
	}

	private function get_product_images_from_payload( $data ) {
		$images = Mobo_Core_Payload_Field_Policy::value(
			$data,
			Mobo_Core_Payload_Field_Policy::image_aliases(),
			array()
		);

		if ( ! is_array( $images ) ) {
			return array();
		}

		/* Portal >= v79 emits explicit image positions. Prefer that durable contract
		 * over incidental JSON/DB enumeration order, while preserving array order for
		 * older Portal versions that do not send position yet. */
		return Mobo_Core_Image_Desired_State_Policy::ordered_collection( $images );
	}

	private function product_title_field_present( $data ) {
		return is_array( $data ) && ( array_key_exists( 'title', $data ) || array_key_exists( 'Title', $data ) );
	}

	private function product_slug_field_present( $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}
		foreach ( array( 'slug', 'Slug', 'url', 'Url' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				return true;
			}
		}
		return false;
	}

	private function product_published_at_field_present( $data ) {
		return is_array( $data ) && ( array_key_exists( 'publishedAt', $data ) || array_key_exists( 'PublishedAt', $data ) );
	}

	private function product_price_field_present( $data ) {
		return Mobo_Core_Payload_Field_Policy::is_present( $data, Mobo_Core_Payload_Field_Policy::price_aliases() );
	}

	private function product_compare_price_field_present( $data ) {
		return Mobo_Core_Payload_Field_Policy::is_present( $data, Mobo_Core_Payload_Field_Policy::compare_price_aliases() );
	}

	private function get_compare_price_field_value( $data, $default = null ) {
		return Mobo_Core_Payload_Field_Policy::value(
			$data,
			Mobo_Core_Payload_Field_Policy::compare_price_aliases(),
			$default
		);
	}

	private function product_images_field_present( $data ) {
		return Mobo_Core_Payload_Field_Policy::is_present( $data, Mobo_Core_Payload_Field_Policy::image_aliases() );
	}

	private function product_attributes_field_present( $data ) {
		return Mobo_Core_Payload_Field_Policy::is_present( $data, Mobo_Core_Payload_Field_Policy::attribute_aliases() );
	}

	private function product_categories_field_present( $data ) {
		return Mobo_Core_Payload_Field_Policy::is_present( $data, Mobo_Core_Payload_Field_Policy::category_aliases() );
	}

	/**
	 * Source product/variation title exactly as it will be stored in
	 * wp_posts.post_title. On utf8/utf8mb3 tables four-byte characters become
	 * numeric entities (deterministic), so the stored-vs-Source compare used by
	 * the change detection is stable and never reports a perpetual change.
	 *
	 * @param array $data Payload.
	 * @return string
	 */
	private function source_post_title( $data ) {
		$title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );
		if ( '' === $title || ! class_exists( 'Mobo_Core_Unicode_Guard' ) ) {
			return $title;
		}
		return (string) Mobo_Core_Unicode_Guard::prepare_post_field( $title, 'post_title', 'product-title' );
	}

	/**
	 * Source attribute name/value/option as it will be stored inside
	 * wp_postmeta (_product_attributes, attribute_* and attribute GUID maps).
	 * Every attribute read site uses this helper so derived keys
	 * (sanitize_title), compares and stored values stay mutually consistent.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function source_attribute_text( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value || ! class_exists( 'Mobo_Core_Unicode_Guard' ) ) {
			return $value;
		}
		return (string) Mobo_Core_Unicode_Guard::prepare_meta_text( $value, 'product-attribute' );
	}

	private function build_product_attributes( $attributes ) {
		$result   = array();
		$position = 0;

		if ( ! is_array( $attributes ) ) {
			return $result;
		}

		foreach ( $attributes as $attribute_data ) {
			if ( ! is_array( $attribute_data ) ) {
				continue;
			}

			$name = $this->source_attribute_text( $this->get_value( $attribute_data, 'name', '' ) );

			if ( '' === $name ) {
				continue;
			}

			$values = $this->get_value( $attribute_data, 'values', array() );

			if ( ! is_array( $values ) ) {
				continue;
			}

			$options = array();

			foreach ( $values as $value_data ) {
				if ( ! is_array( $value_data ) ) {
					continue;
				}

				$value = $this->source_attribute_text( $this->get_value( $value_data, 'value', '' ) );

				if ( '' !== $value ) {
					$options[] = $value;
				}
			}

			$options = array_values( array_unique( $options ) );

			if ( empty( $options ) ) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( $options );
			$attribute->set_position( $position );
			$attribute->set_visible( true );
			$attribute->set_variation( true );

			$result[] = $attribute;
			$position++;
		}

		return $result;
	}

	private function store_product_attribute_guids( $product_id, $attributes ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! is_array( $attributes ) ) {
			return false;
		}

		$map = array();
		foreach ( $attributes as $attribute_data ) {
			if ( ! is_array( $attribute_data ) ) {
				continue;
			}
			$guid = $this->extract_attribute_guid( $attribute_data );
			$name = $this->source_attribute_text( $this->get_value( $attribute_data, 'name', '' ) );
			if ( '' !== $guid && '' !== $name ) {
				$map[ $name ] = $guid;
			}
		}

		if ( empty( $map ) ) {
			return $this->delete_post_meta_verified( $product_id, 'attribute_guid' ) && $this->delete_post_meta_verified( $product_id, 'mobo_attribute_guid_map' );
		}

		return $this->persist_post_meta_verified( $product_id, 'attribute_guid', $map ) && $this->persist_post_meta_verified( $product_id, 'mobo_attribute_guid_map', $map );
	}

	private function extract_attribute_guid( $attribute_data ) {
		if ( ! is_array( $attribute_data ) ) {
			return '';
		}

		$keys = array( 'attribute_guid', 'attributeGuid', 'attributeId', 'guid', 'remote_guid', 'remoteGuid', 'id' );

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $attribute_data, $key, '' ) );

			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Validate that a concrete Mobo variation contains exactly one non-empty value
	 * for every variation attribute defined by its parent product.
	 *
	 * @param WC_Product $parent Parent product.
	 * @param array      $attrs Normalized variation attributes.
	 * @return true|WP_Error
	 */
	/**
	 * Validate raw variation attribute rows before normalization can collapse them.
	 * Duplicate names are ambiguous even when both values are individually valid;
	 * silently keeping the last row can purchase/sync the wrong concrete variant.
	 *
	 * @param mixed $attributes Raw variant attributes.
	 * @return true|WP_Error
	 */
	private function validate_raw_variation_attribute_payload( $attributes ) {
		if ( ! is_array( $attributes ) ) {
			return new WP_Error( 'mobo_core_variant_attributes_invalid', 'Mobo variant attributes must be an array.' );
		}

		$seen = array();
		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				return new WP_Error( 'mobo_core_variant_attribute_row_invalid', 'Mobo variant contains a malformed attribute row.' );
			}

			$name   = $this->source_attribute_text( $this->get_value( $attribute, 'name', '' ) );
			$option = $this->source_attribute_text( $this->get_value( $attribute, 'option', '' ) );
			$key    = sanitize_title( $name );

			if ( '' === $key || '' === $option ) {
				return new WP_Error( 'mobo_core_variant_attribute_row_invalid', 'Mobo variant contains an empty attribute name or selection.' );
			}
			if ( isset( $seen[ $key ] ) ) {
				return new WP_Error( 'mobo_core_variant_attribute_duplicate', 'Mobo variant contains a duplicate attribute selection: ' . $key );
			}
			$seen[ $key ] = true;
		}

		return true;
	}

	private function validate_variation_attribute_completeness( $parent, $attrs ) {
		if ( ! $parent instanceof WC_Product ) {
			return new WP_Error( 'mobo_core_variant_parent_invalid', 'Variant parent is invalid.' );
		}

		$expected = array();
		foreach ( (array) $parent->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$key = sanitize_title( (string) $attribute->get_name() );
			if ( '' !== $key ) {
				$options = array();
				foreach ( (array) $attribute->get_options() as $option ) {
					$option = sanitize_text_field( (string) $option );
					if ( '' !== $option ) {
						$options[] = $option;
					}
				}
				$expected[ $key ] = array_values( array_unique( $options ) );
			}
		}

		$expected_keys = array_keys( $expected );
		$actual_keys   = array();
		foreach ( is_array( $attrs ) ? $attrs : array() as $key => $value ) {
			$key   = preg_replace( '/^attribute_/', '', sanitize_title( (string) $key ) );
			$value = sanitize_text_field( (string) $value );
			if ( '' === $key || '' === $value ) {
				return new WP_Error( 'mobo_core_variant_attribute_empty', 'Mobo variant contains an empty attribute selection.' );
			}
			/* Canonical comparison: on utf8mb3 the stored selection carries the
			 * guard's numeric entities while WooCommerce html-decodes the parent's
			 * custom attribute options (see product_attributes_match_payload()). */
			$allowed = isset( $expected[ $key ] ) ? $expected[ $key ] : array();
			if ( class_exists( 'Mobo_Core_Unicode_Guard' ) ) {
				$allowed     = Mobo_Core_Unicode_Guard::canonical( $allowed );
				$value_canon = Mobo_Core_Unicode_Guard::canonical( $value );
			} else {
				$value_canon = $value;
			}
			if ( ! empty( $allowed ) && ! in_array( $value_canon, $allowed, true ) ) {
				return new WP_Error( 'mobo_core_variant_attribute_value_invalid', 'Mobo variant attribute value is not allowed by the parent attribute: ' . $key . '=' . $value );
			}
			$actual_keys[] = $key;
		}

		$expected_keys = array_values( array_unique( $expected_keys ) );
		$actual_keys   = array_values( array_unique( $actual_keys ) );
		sort( $expected_keys, SORT_STRING );
		sort( $actual_keys, SORT_STRING );

		if ( $expected_keys !== $actual_keys ) {
			$missing = array_values( array_diff( $expected_keys, $actual_keys ) );
			$extra   = array_values( array_diff( $actual_keys, $expected_keys ) );
			return new WP_Error(
				'mobo_core_variant_attribute_incomplete',
				'Incomplete Mobo variant attributes. Missing: ' . implode( ', ', $missing ) . '; extra: ' . implode( ', ', $extra )
			);
		}

		return true;
	}

	private function normalize_variation_attributes( $attributes ) {
		$result = array();

		if ( ! is_array( $attributes ) ) {
			return $result;
		}

		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}

			$name   = $this->source_attribute_text( $this->get_value( $attribute, 'name', '' ) );
			$option = $this->source_attribute_text( $this->get_value( $attribute, 'option', '' ) );

			if ( '' === $name || '' === $option ) {
				continue;
			}

			$key = sanitize_title( $name );

			if ( '' !== $key ) {
				$result[ $key ] = $option;
			}
		}

		return $result;
	}

	private function finalize_missing_variants( $product, $product_guid, $sync_id ) {
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'mobo_core_missing_variants_product_invalid', 'Product could not be loaded while finalizing missing variations.' );
		}

		$parent_id = absint( $product->get_id() );
		if ( $parent_id <= 0 || ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $parent_id ) ) ) {
			return new WP_Error( 'mobo_core_missing_variants_parent_excluded', 'Excluded/invalid parent cannot be mutated during variation finalization.' );
		}

		$seen = null;
		if ( class_exists( 'Mobo_Core_Seen_Variants_Store' ) && Mobo_Core_Seen_Variants_Store::is_available() ) {
			$seen = Mobo_Core_Seen_Variants_Store::load_set( $product_guid, $sync_id );
		} elseif ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) {
			$seen_option = $this->seen_option_name( $product_guid, $sync_id );
			wp_cache_delete( $seen_option, 'options' );
			$seen = get_option( $seen_option, null );
		}
		if ( ! is_array( $seen ) ) {
			return new WP_Error( 'mobo_core_seen_variants_checkpoint_missing', 'Authoritative variation seen-set is missing or unreadable; destructive finalization was blocked.' );
		}

		$children = array_values( array_unique( array_merge( $this->get_live_variation_children( $parent_id ), $this->get_pending_quarantine_children( $parent_id ) ) ) );
		if ( ! empty( $children ) ) {
			update_meta_cache( 'post', $children );
		}

		$quarantined = 0;
		$failed      = array();
		foreach ( $children as $variation_id ) {
			$identity = class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ? Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $variation_id ) : new WP_Error( 'mobo_core_variation_lifecycle_missing', 'Variation lifecycle policy is unavailable.' );
			if ( is_wp_error( $identity ) ) {
				$failed[ $variation_id ] = $identity->get_error_message();
				continue;
			}
			if ( empty( $identity['owned'] ) ) {
				continue;
			}
			$variant_guid = sanitize_text_field( (string) $identity['variantGuid'] );
			if ( '' !== $variant_guid && isset( $seen[ $variant_guid ] ) ) {
				continue;
			}

			$result = $this->quarantine_variation(
				$variation_id,
				'authoritative-variant-removed',
				array( 'parentId' => $parent_id, 'productGuid' => $product_guid, 'syncId' => $sync_id )
			);
			if ( is_wp_error( $result ) ) {
				$failed[ $variation_id ] = $result->get_error_message();
			} else {
				$quarantined++;
			}
		}

		if ( ! empty( $failed ) ) {
			return new WP_Error(
				'mobo_core_missing_variants_quarantine_failed',
				'One or more stale Mobo variations could not be quarantined; authoritative finalization was not committed and will be retried.',
				array( 'variations' => $failed )
			);
		}

		/* Remove only stale variation mapping rows after live retirement succeeded. */
		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			$mapping_error = '';
			$this->product_map->delete_variations_for_parent( $product_guid, array_keys( $seen ), $mapping_error );
			if ( '' !== $mapping_error ) {
				return new WP_Error(
					'mobo_core_missing_variants_mapping_cleanup_failed',
					'Stale variations were quarantined, but stale variation mapping cleanup failed; finalization will retry.',
					array( 'mappingError' => $mapping_error )
				);
			}
		}

		return $quarantined;
	}

	private function is_remote_product_trashed( $guid ) {
		return $this->remote_post_has_blocked_status( $guid, 'product', 'product_guid', 'product' );
	}

	private function is_remote_variation_trashed( $guid ) {
		return $this->remote_post_has_blocked_status( $guid, 'product_variation', 'variant_guid', 'variation' );
	}

	private function remote_post_has_blocked_status( $guid, $post_type, $meta_key, $object_type ) {
		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid ) {
			return false;
		}

		$cache_key = $this->connection_id() . '|' . sanitize_key( (string) $object_type ) . '|' . $guid;
		if ( array_key_exists( $cache_key, $this->blocked_status_cache ) ) {
			return (bool) $this->blocked_status_cache[ $cache_key ];
		}

		$status = '';
		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			if ( 'product' === $object_type && method_exists( $this->product_map, 'get_product_post_status' ) ) {
				$status = $this->product_map->get_product_post_status( $guid );
			} elseif ( 'variation' === $object_type && method_exists( $this->product_map, 'get_variation_post_status' ) ) {
				$status = $this->product_map->get_variation_post_status( $guid );
			}
		}

		/* A valid map row is authoritative; do not run a second legacy meta_query. */
		if ( '' !== $status ) {
			$blocked = in_array( $status, array( 'trash', 'auto-draft' ), true );
			$this->blocked_status_cache[ $cache_key ] = $blocked;
			return $blocked;
		}

		$blocked = $this->find_blocked_post_id_by_meta( $post_type, $meta_key, $guid ) > 0;
		$this->blocked_status_cache[ $cache_key ] = $blocked;
		return $blocked;
	}

	private function find_blocked_post_id_by_meta( $post_type, $meta_key, $meta_value ) {
		$meta_value = sanitize_text_field( (string) $meta_value );

		if ( '' === $meta_value ) {
			return 0;
		}

		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::must_use_custom_reads() && class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready() ) {
			global $wpdb;
			$post_type = sanitize_key( (string) $post_type );
			$meta_key = sanitize_key( (string) $meta_key );
			if ( 'product' === $post_type && 'product_guid' === $meta_key ) {
				$table = Mobo_Core_Storage_Schema::table_name( 'products' );
				return absint( $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$table} c ON c.woo_product_id=p.ID WHERE c.connection_id=%d AND p.post_type='product' AND p.post_status IN ('trash','auto-draft') AND c.product_guid=%s ORDER BY p.ID ASC LIMIT 1", $this->connection_id(), $meta_value ) ) );
			}
			if ( 'product_variation' === $post_type && 'variant_guid' === $meta_key ) {
				$table = Mobo_Core_Storage_Schema::table_name( 'variants' );
				return absint( $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$table} c ON c.woo_variation_id=p.ID WHERE c.connection_id=%d AND p.post_type='product_variation' AND p.post_status IN ('trash','auto-draft') AND c.variant_guid=%s ORDER BY p.ID ASC LIMIT 1", $this->connection_id(), $meta_value ) ) );
			}
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => sanitize_key( $post_type ),
				'post_status'            => array( 'trash', 'auto-draft' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded maintenance/synchronization lookup on indexed post IDs.
				'meta_query'             => array(
					array(
						'key'   => sanitize_key( $meta_key ),
						'value' => $meta_value,
					),
				),
			)
		);

		return ! empty( $query->posts[0] ) ? absint( $query->posts[0] ) : 0;
	}

	private function find_product_id_by_guid( $guid ) {
		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid ) {
			return 0;
		}

		// Q2 P0 opt-in strict preflight: inspect both identity layers and real
		// variant parenthood *before* canonical fallback can rewrite a map.
		if ( '1' === (string) get_option( 'mobo_core_parent_identity_fence_q2_enabled', '0' ) ) {
			Mobo_Core_Parent_Identity_Fence::assert_safe( $guid );
		}

		$mapped_product_id = 0;

		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			$mapped_product_id = $this->product_map->get_product_id( $guid );
		}

		if ( class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			$canonical_id = Mobo_Core_Product_Concurrency::get_canonical_product_id( $guid, $mapped_product_id );

			if ( $canonical_id > 0 ) {
				if ( $canonical_id !== $mapped_product_id ) {
					/*
					 * A legacy/canonical identity fallback is bootstrap evidence only.
					 * When no canonical Map row owns this identity yet, preserve the
					 * durable crash marker instead of silently electing the product as
					 * complete. Existing Map rows remain authoritative and are never
					 * overwritten from legacy postmeta here.
					 */
					$canonical_incomplete = '1' === (string) get_post_meta( $canonical_id, 'mobo_sync_incomplete', true );
					$this->upsert_product_map( $guid, $canonical_id, $canonical_incomplete );
				}
				return $canonical_id;
			}
		}

		if ( $mapped_product_id > 0 ) {
			return $mapped_product_id;
		}

		$product_id = $this->find_post_id_by_meta( 'product', 'product_guid', $guid );

		if ( $product_id > 0 ) {
			$legacy_incomplete = '1' === (string) get_post_meta( $product_id, 'mobo_sync_incomplete', true );
			$this->upsert_product_map( $guid, $product_id, $legacy_incomplete );
		}

		/* M11: a quarantined legacy product without Custom/map rows is invisible to
		 * every lookup above once the identity fence hides its legacy product_guid
		 * row. Adopt it (and give it a map row) instead of creating a duplicate. */
		if ( $product_id <= 0 && class_exists( 'Mobo_Core_Product_Creation_Intent' ) && class_exists( 'Mobo_Core_Migration_Quarantine' ) && Mobo_Core_Migration_Quarantine::count() > 0 ) {
			$marked_id = Mobo_Core_Product_Creation_Intent::find_quarantined_legacy_product( $guid, $this->connection_id() );
			if ( $marked_id > 0 ) {
				$this->upsert_product_map( $guid, $marked_id, true );
				return $marked_id;
			}
		}

		return $product_id;
	}

	/** @var array<int,bool> Request-local: orphan scan already ran for a Connection. */
	private static $creation_orphan_scan_done = array();

	/**
	 * H6: bounded orphan-adoption scan. Products that carry a provisional
	 * creation marker but never got an identity binding are bound to their GUID
	 * (marked incomplete so the next sync fills them). A marked product whose GUID
	 * already belongs to another product is parked and reported, never deleted.
	 * Runs at most once per request and once per 10 minutes per Connection.
	 *
	 * @return void
	 */
	private function maybe_adopt_creation_orphans() {
		$connection_id = $this->connection_id();
		if ( $connection_id <= 0 || isset( self::$creation_orphan_scan_done[ $connection_id ] ) || ! class_exists( 'Mobo_Core_Product_Creation_Intent' ) ) {
			return;
		}
		self::$creation_orphan_scan_done[ $connection_id ] = true;
		$scan_at = get_option( 'mobo_core_creation_orphan_scan_at', array() );
		$scan_at = is_array( $scan_at ) ? $scan_at : array();
		if ( time() - absint( isset( $scan_at[ $connection_id ] ) ? $scan_at[ $connection_id ] : 0 ) < 600 ) {
			return;
		}
		$scan_at[ $connection_id ] = time();
		update_option( 'mobo_core_creation_orphan_scan_at', $scan_at, false );

		foreach ( Mobo_Core_Product_Creation_Intent::list_marked( $connection_id, Mobo_Core_Product_Creation_Intent::SCAN_LIMIT ) as $row ) {
			$post_id = absint( $row['postId'] );
			$guid    = (string) $row['productGuid'];
			$lock    = class_exists( 'Mobo_Core_Product_Concurrency' ) ? Mobo_Core_Product_Concurrency::acquire_product_lock( $guid, 0, 60 ) : true;
			if ( false === $lock ) {
				continue; // A writer owns this GUID right now; it will adopt the product itself.
			}
			try {
				$bound  = $this->find_product_id_by_guid( $guid );
				$action = Mobo_Core_Product_Creation_Intent::orphan_action( $post_id, $bound );
				if ( 'clear' === $action ) {
					Mobo_Core_Product_Creation_Intent::clear( $post_id );
				} elseif ( 'duplicate' === $action ) {
					Mobo_Core_Product_Creation_Intent::park_duplicate( $post_id, Mobo_Core_Product_Creation_Intent::marker_value( $connection_id, $guid ) );
					if ( class_exists( 'Mobo_Core_Logger' ) ) {
						Mobo_Core_Logger::warning( 'Product left by an interrupted creation duplicates an already bound product; it was kept untouched for the merchant to review.', array( 'productId' => $post_id, 'boundProductId' => $bound, 'productGuid' => $guid ) );
					}
				} elseif ( $this->persist_post_meta_verified( $post_id, 'mobo_sync_incomplete', '1' )
					&& $this->persist_post_meta_verified( $post_id, 'product_guid', sanitize_text_field( $guid ) )
					&& $this->upsert_product_map( $guid, $post_id, true ) ) {
					Mobo_Core_Product_Creation_Intent::clear( $post_id );
					if ( class_exists( 'Mobo_Core_Logger' ) ) {
						Mobo_Core_Logger::warning( 'Adopted a product left without identity by an interrupted creation; the next sync completes it.', array( 'productId' => $post_id, 'productGuid' => $guid ) );
					}
				}
			} catch ( Throwable $error ) {
				if ( class_exists( 'Mobo_Core_Logger' ) ) {
					Mobo_Core_Logger::warning( 'Interrupted-creation orphan adoption deferred.', array( 'productId' => $post_id, 'error' => $error->getMessage() ) );
				}
			} finally {
				if ( true !== $lock && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
					Mobo_Core_Product_Concurrency::release_product_lock( $lock );
				}
			}
		}
	}

	/**
	 * Restore a trashed Mobo-owned object only while an authoritative Repair
	 * payload for the exact identity is being processed. Normal sync/webhook
	 * continues to respect a merchant trash action.
	 *
	 * @param string $post_type product|product_variation.
	 * @param string $meta_key Identity meta key.
	 * @param string $meta_value Identity value.
	 * @param int    $expected_parent Expected parent for variations.
	 * @return int
	 */
	private function restore_trashed_mobo_object_by_identity( $post_type, $meta_key, $meta_value, $expected_parent = 0 ) {
		$post_type       = sanitize_key( (string) $post_type );
		$meta_key        = sanitize_key( (string) $meta_key );
		$meta_value      = sanitize_text_field( (string) $meta_value );
		$expected_parent = absint( $expected_parent );
		if ( '' === $post_type || '' === $meta_key || '' === $meta_value ) {
			return 0;
		}

		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::must_use_custom_reads() ) {
			global $wpdb;
			if ( 'product' === $post_type && 'product_guid' === $meta_key ) {
				$table = Mobo_Core_Storage_Schema::table_name( 'products' );
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$table} c ON c.woo_product_id=p.ID WHERE c.connection_id=%d AND p.post_type='product' AND p.post_status='trash' AND c.product_guid=%s ORDER BY p.ID ASC LIMIT 2",
						$this->connection_id(),
						$meta_value
					)
				);
			} elseif ( 'product_variation' === $post_type && 'variant_guid' === $meta_key ) {
				$table = Mobo_Core_Storage_Schema::table_name( 'variants' );
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$table} c ON c.woo_variation_id=p.ID WHERE c.connection_id=%d AND p.post_type='product_variation' AND p.post_status='trash' AND c.variant_guid=%s ORDER BY p.ID ASC LIMIT 2",
						$this->connection_id(),
						$meta_value
					)
				);
			} else {
				$ids = array();
			}
			$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		} else {
			$query = new WP_Query(
				array(
					'post_type'              => $post_type,
					'post_status'            => array( 'trash' ),
					'fields'                 => 'ids',
					'posts_per_page'         => 2,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Pre-terminal bounded Repair identity lookup.
					'meta_query'             => array(
						array(
							'key'   => $meta_key,
							'value' => $meta_value,
						),
					),
				)
			);
			$ids = array_values( array_filter( array_map( 'absint', is_array( $query->posts ) ? $query->posts : array() ) ) );
		}
		if ( 1 !== count( $ids ) ) {
			/* Multiple trashed objects with one identity are ambiguous. The bounded
			 * integrity stage reports duplicates; never guess which parent/history to restore. */
			return 0;
		}

		$post_id = absint( $ids[0] );
		if ( 'product_variation' === $post_type && $expected_parent > 0 && absint( wp_get_post_parent_id( $post_id ) ) !== $expected_parent ) {
			return 0;
		}

		/*
		 * Merchant intent wins: a product/variation in Trash may have been trashed by the
		 * merchant, and Repair cannot tell who trashed it. Never un-trash automatically;
		 * log instead and return -1 so the caller skips the identity (no duplicate is
		 * created either). Operators can opt in explicitly via the filter below.
		 */
		if ( ! (bool) apply_filters( 'mobo_core_repair_untrash_enabled', false, $post_id, $post_type ) ) {
			if ( class_exists( 'Mobo_Core_Logger' ) ) {
				Mobo_Core_Logger::warning(
					'Repair found a trashed Mobo object and kept it in Trash (merchant intent preserved).',
					array( 'postId' => $post_id, 'postType' => $post_type, 'identity' => $meta_value )
				);
			}
			if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
				Mobo_Core_Runtime_Diagnostics::increment( 'repair_trash_preserved' );
			}
			return -1;
		}

		$restored = wp_untrash_post( $post_id );
		if ( ! $restored || 'trash' === get_post_status( $post_id ) ) {
			return 0;
		}

		$cache_key = $this->connection_id() . '|' . ( 'product_variation' === $post_type ? 'variation' : 'product' ) . '|' . $meta_value;
		unset( $this->blocked_status_cache[ $cache_key ] );
		if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
			Mobo_Core_Runtime_Diagnostics::increment( 'repair_untrashed_object' );
		}
		return $post_id;
	}

	/**
	 * Find an active sibling by concrete Portal purchase identity.
	 *
	 * If legacy data contains more than one candidate, prefer the candidate whose
	 * attribute signature matches the incoming payload; if several still match,
	 * prefer an exact incoming GUID and finally the oldest complete local object.
	 * This method never crosses parent-product boundaries.
	 *
	 * @param int   $parent_id Parent product ID.
	 * @param array $data Variation payload.
	 * @return int
	 */
	/**
	 * Find an existing WooCommerce variation by the canonical Mobo variant GUID.
	 *
	 * GUID is the first identity lookup. PortalVariantId and attribute signature
	 * remain fallback identities when the local GUID map is missing.
	 *
	 * @param string $guid Variant GUID.
	 * @return int
	 */
	private function find_variation_id_by_guid( $guid ) {
		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid ) {
			return 0;
		}

		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::must_use_custom_reads() ) {
			global $wpdb;
			$table = Mobo_Core_Storage_Schema::table_name( 'variants' );
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$table} c ON c.woo_variation_id=p.ID WHERE c.connection_id=%d AND p.post_type='product_variation' AND p.post_status IN ('publish','private','draft','trash') AND c.variant_guid=%s ORDER BY p.ID ASC LIMIT 1",
					$this->connection_id(),
					$guid
				)
			);
		} else {
			$ids = get_posts(
				array(
					'post_type'      => 'product_variation',
					'post_status'    => array( 'publish', 'private', 'draft', 'trash' ),
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
					'meta_query'     => array(
						array(
							'key'   => 'variant_guid',
							'value' => $guid,
						),
					),
				)
			);
		}

		return ! empty( $ids[0] ) ? absint( $ids[0] ) : 0;
	}

	private function find_variation_id_by_portal_variant_id( $parent_id, $data ) {
		global $wpdb;

		$parent_id         = absint( $parent_id );
		$portal_variant_id = $this->extract_portal_variant_id( $data );
		if ( $parent_id <= 0 || $portal_variant_id <= 0 ) {
			return 0;
		}

		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::must_use_custom_reads() ) {
			$table = Mobo_Core_Storage_Schema::table_name( 'variants' );
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$table} c ON c.woo_variation_id = p.ID
					WHERE p.post_type = 'product_variation'
					AND p.post_parent = %d
					AND p.post_status IN ('publish','private','draft','pending')
					AND c.connection_id = %d
					AND c.woo_product_id = %d
					AND c.portal_variant_id = %d
					ORDER BY p.ID ASC",
					$parent_id,
					$this->connection_id(),
					$parent_id,
					$portal_variant_id
				)
			);
		} else {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					WHERE p.post_type = 'product_variation'
					AND p.post_parent = %d
					AND p.post_status IN ('publish','private','draft','pending')
					AND pm.meta_key IN ('_mobo_portal_variant_id','mobo_portal_variant_id','portal_variant_id')
					AND pm.meta_value = %s
					ORDER BY p.ID ASC",
					$parent_id,
					(string) $portal_variant_id
				)
			);
		}
		$ids = array_values( array_filter( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		if ( 1 === count( $ids ) ) {
			return absint( $ids[0] );
		}

		$incoming_signature = $this->variation_attribute_signature( $this->normalize_variation_attributes( $this->get_value( $data, 'attributes', array() ) ) );
		$incoming_guid      = $this->extract_variant_guid( $data );
		$ranked             = array();
		$exact_guid_ids     = array();
		$signature_ids      = array();

		foreach ( $ids as $id ) {
			$all_meta = get_post_meta( $id );
			$attrs    = array();
			foreach ( is_array( $all_meta ) ? $all_meta : array() as $key => $values ) {
				if ( 0 !== strpos( (string) $key, 'attribute_' ) ) {
					continue;
				}
				$value = is_array( $values ) && isset( $values[0] ) ? $values[0] : '';
				if ( is_scalar( $value ) && '' !== (string) $value ) {
					$attrs[ $key ] = (string) $value;
				}
			}

			$stored_signature = $this->variation_attribute_signature( $attrs );
			$stored_guid      = sanitize_text_field( (string) get_post_meta( $id, 'variant_guid', true ) );
			$score            = 0;
			if ( '' !== $incoming_signature && $stored_signature === $incoming_signature ) {
				$score += 100;
				$signature_ids[] = $id;
			}
			if ( '' !== $incoming_guid && $stored_guid === $incoming_guid ) {
				$score += 1000;
				$exact_guid_ids[] = $id;
			}
			if ( '1' !== (string) get_post_meta( $id, 'mobo_sync_incomplete', true ) ) {
				$score += 10;
			}
			$ranked[] = array( 'id' => $id, 'score' => $score );
		}

		/* With duplicate Portal identity, never choose a candidate merely because it
		 * is older/complete. At least the exact remote GUID or the concrete incoming
		 * attribute signature must agree. This prevents an ambiguous legacy duplicate
		 * set from being silently mutated into the wrong Variation. */
		if ( empty( $exact_guid_ids ) && empty( $signature_ids ) ) {
			throw new RuntimeException( 'Duplicate PortalVariantId candidates are ambiguous; no existing Variation matches the incoming GUID or attribute signature.' );
		}

		usort(
			$ranked,
			static function ( $left, $right ) {
				if ( absint( $left['score'] ) === absint( $right['score'] ) ) {
					return absint( $left['id'] ) <=> absint( $right['id'] );
				}
				return absint( $right['score'] ) <=> absint( $left['score'] );
			}
		);

		return ! empty( $ranked[0]['id'] ) ? absint( $ranked[0]['id'] ) : 0;
	}

	/**
	 * Persist product GUID map if the table is available.
	 *
	 * @param string $product_guid Remote product GUID.
	 * @param int    $product_id Product ID.
	 * @param bool   $sync_incomplete Sync incomplete.
	 * @return void
	 */
	private function upsert_product_map( $product_guid, $product_id, $sync_incomplete = false ) {
		if ( ! ( $this->product_map instanceof Mobo_Core_Product_Map ) ) {
			return false;
		}

		// All Product Sync map writes must pass the same preflight; a new ID
		// must never steal an existing GUID/parent without independently
		// attested Source ownership. No automatic repair/reparent is performed.
		if ( '1' === (string) get_option( 'mobo_core_parent_identity_fence_q2_enabled', '0' ) ) {
			$trusted_new = isset( $this->q2_new_parent_authorizations[ $product_guid ] )
				&& absint( $this->q2_new_parent_authorizations[ $product_guid ] ) === absint( $product_id );
			Mobo_Core_Parent_Identity_Fence::assert_safe( $product_guid, $product_id, $trusted_new );
		}

		$last_hash = sanitize_text_field( (string) get_post_meta( absint( $product_id ), '_mobo_product_source_hash', true ) );
		if ( class_exists( 'Mobo_Core_Storage_Legacy_Write_Suppression' ) ) {
			$stored = Mobo_Core_Storage_Legacy_Write_Suppression::commit_product( $this->product_map, $product_guid, $product_id, $last_hash, $sync_incomplete );
		} else {
			$stored = (bool) $this->product_map->upsert_product( $product_guid, $product_id, $last_hash, $sync_incomplete );
			if ( $stored && class_exists( 'Mobo_Core_Storage_Bridge' ) ) {
				Mobo_Core_Storage_Bridge::mirror_product_legacy_state( $product_guid, $product_id, $sync_incomplete );
			}
		}
		if ( ! $stored ) { return false; }
		if ( isset( $this->q2_new_parent_authorizations[ $product_guid ] ) && absint( $this->q2_new_parent_authorizations[ $product_guid ] ) === absint( $product_id ) ) {
			unset( $this->q2_new_parent_authorizations[ $product_guid ] );
		}
		if ( class_exists( 'Mobo_Core_Product_Ledger' ) ) {
			Mobo_Core_Product_Ledger::record( $product_guid, $product_id, $this->is_repair_mode() ? 'repair' : 'sync', false );
		}
		return true;
	}

	/**
	 * Persist variation GUID map if the table is available.
	 *
	 * @param string $variant_guid Remote variant GUID.
	 * @param int    $variation_id Variation ID.
	 * @param string $product_guid Parent remote product GUID.
	 * @param bool   $sync_incomplete Sync incomplete.
	 * @return bool
	 */
	private function upsert_variation_map( $variant_guid, $variation_id, $product_guid = '', $sync_incomplete = false ) {
		if ( ! ( $this->product_map instanceof Mobo_Core_Product_Map ) ) {
			return false;
		}

		$last_hash = sanitize_text_field( (string) get_post_meta( absint( $variation_id ), '_mobo_variant_source_hash', true ) );
		if ( class_exists( 'Mobo_Core_Storage_Legacy_Write_Suppression' ) ) {
			$stored = Mobo_Core_Storage_Legacy_Write_Suppression::commit_variation( $this->product_map, $variant_guid, $variation_id, $product_guid, $last_hash, $sync_incomplete );
		} else {
			$stored = (bool) $this->product_map->upsert_variation( $variant_guid, $variation_id, $product_guid, $last_hash, $sync_incomplete );
			if ( $stored && class_exists( 'Mobo_Core_Storage_Bridge' ) ) {
				Mobo_Core_Storage_Bridge::mirror_variant_legacy_state( $variant_guid, $variation_id, $product_guid, $sync_incomplete );
			}
		}
		return (bool) $stored;
	}

	private function find_post_id_by_meta( $post_type, $meta_key, $meta_value ) {
		$meta_value = sanitize_text_field( (string) $meta_value );

		if ( '' === $meta_value ) {
			return 0;
		}

		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::must_use_custom_reads() && class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready() ) {
			global $wpdb;
			$post_type = sanitize_key( (string) $post_type );
			$meta_key = sanitize_key( (string) $meta_key );
			if ( 'product' === $post_type && 'product_guid' === $meta_key ) {
				$table = Mobo_Core_Storage_Schema::table_name( 'products' );
				return absint( $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$table} c ON c.woo_product_id=p.ID WHERE c.connection_id=%d AND p.post_type='product' AND p.post_status IN ('publish','draft','private','pending','inherit') AND c.product_guid=%s ORDER BY p.ID ASC LIMIT 1", $this->connection_id(), $meta_value ) ) );
			}
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => sanitize_key( $post_type ),
				'post_status'            => array( 'publish', 'draft', 'private', 'pending', 'inherit' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded maintenance/synchronization lookup on indexed post IDs.
				'meta_query'             => array(
					array(
						'key'   => sanitize_key( $meta_key ),
						'value' => $meta_value,
					),
				),
			)
		);

		return ! empty( $query->posts[0] ) ? absint( $query->posts[0] ) : 0;
	}

	private function persist_seen_variants_verified( $option_name, $seen ) {
		/* Retained only for backward-compatible private method shape. New writes never
		 * use wp_options; callers route through the scope-aware table methods below. */
		unset( $option_name );
		return is_array( $seen );
	}

	private function reset_seen_variants( $product_guid, $sync_id ) {
		if ( class_exists( 'Mobo_Core_Seen_Variants_Store' ) && Mobo_Core_Seen_Variants_Store::is_available() ) {
			return Mobo_Core_Seen_Variants_Store::reset( $product_guid, $sync_id );
		}
		if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { return false; }
		$option = $this->seen_option_name( $product_guid, $sync_id );
		delete_option( $option );
		return Mobo_Core_Durable_State_Policy::update_option_verified( $option, array(), false );
	}

	private function initialize_seen_variants( $product_guid, $sync_id ) {
		if ( class_exists( 'Mobo_Core_Seen_Variants_Store' ) && Mobo_Core_Seen_Variants_Store::is_available() ) {
			/* Legacy scope probing is upgrade-only; strict cutover creates table markers directly. */
			if ( ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) && Mobo_Core_Seen_Variants_Store::migrate_legacy_scope( $product_guid, $sync_id ) ) {
				return true;
			}
			return Mobo_Core_Seen_Variants_Store::initialize( $product_guid, $sync_id );
		}
		if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { return false; }
		$option = $this->seen_option_name( $product_guid, $sync_id );
		$seen   = get_option( $option, null );
		if ( is_array( $seen ) ) { return true; }
		return Mobo_Core_Durable_State_Policy::update_option_verified( $option, array(), false );
	}

	private function mark_variant_seen( $product_guid, $sync_id, $variant_guid ) {
		return $this->mark_variants_seen_bulk( $product_guid, $sync_id, array( $variant_guid ) );
	}

	/**
	 * Persist all variation GUIDs seen on one authoritative page as independent rows.
	 *
	 * @param string $product_guid Product GUID.
	 * @param string $sync_id Sync ID.
	 * @param array  $variant_guids Variation GUIDs.
	 * @return bool
	 */
	private function mark_variants_seen_bulk( $product_guid, $sync_id, $variant_guids ) {
		if ( class_exists( 'Mobo_Core_Seen_Variants_Store' ) && Mobo_Core_Seen_Variants_Store::is_available() ) {
			return Mobo_Core_Seen_Variants_Store::add_many( $product_guid, $sync_id, $variant_guids );
		}
		if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { return false; }
		$option = $this->seen_option_name( $product_guid, $sync_id );
		$seen   = get_option( $option, array() );
		$seen   = is_array( $seen ) ? $seen : array();
		foreach ( is_array( $variant_guids ) ? $variant_guids : array() as $guid ) {
			$guid = sanitize_text_field( (string) $guid );
			if ( '' !== $guid ) { $seen[ $guid ] = 1; }
		}
		return Mobo_Core_Durable_State_Policy::update_option_verified( $option, $seen, false );
	}

	private function count_seen_variants( $product_guid, $sync_id ) {
		if ( class_exists( 'Mobo_Core_Seen_Variants_Store' ) && Mobo_Core_Seen_Variants_Store::is_available() ) {
			return Mobo_Core_Seen_Variants_Store::count( $product_guid, $sync_id );
		}
		if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { return -1; }
		$seen = get_option( $this->seen_option_name( $product_guid, $sync_id ), null );
		return is_array( $seen ) ? count( array_unique( array_map( 'strval', array_keys( $seen ) ) ) ) : -1;
	}


	/**
	 * Deprecated: product identity is GUID-only and must be present in payload.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function extract_product_guid_from_url( $url ) {
		return '';
	}

	private function build_missing_product_id_message( $payload, $variants ) {
		$payload_keys = is_array( $payload ) ? implode( ',', array_slice( array_keys( $payload ), 0, 20 ) ) : '';
		$data_keys    = '';
		$variant_keys = '';

		$data = $this->get_value( $payload, 'data', null );
		if ( is_array( $data ) ) {
			$data_keys = implode( ',', array_slice( array_keys( $data ), 0, 20 ) );
		}

		if ( is_array( $variants ) && isset( $variants[0] ) && is_array( $variants[0] ) ) {
			$variant_keys = implode( ',', array_slice( array_keys( $variants[0] ), 0, 20 ) );
		}

		$pulled_from = is_array( $payload ) ? (string) $this->get_value( $payload, '_moboPulledFrom', '' ) : '';

		return sprintf(
			'productId is required. PayloadKeys=%s DataKeys=%s FirstVariantKeys=%s PulledFrom=%s',
			$payload_keys,
			$data_keys,
			$variant_keys,
			$pulled_from
		);
	}

	private function clear_seen_variants( $product_guid, $sync_id ) {
		if ( class_exists( 'Mobo_Core_Seen_Variants_Store' ) && Mobo_Core_Seen_Variants_Store::is_available() ) {
			return Mobo_Core_Seen_Variants_Store::clear( $product_guid, $sync_id );
		}
		if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { return false; }
		delete_option( $this->seen_option_name( $product_guid, $sync_id ) );
		return true;
	}

	private function seen_option_name( $product_guid, $sync_id ) {
		/* Legacy key used only by lazy migration in Mobo_Core_Seen_Variants_Store. */
		return 'mobo_seen_variants_' . md5( sanitize_text_field( (string) $product_guid ) . '|' . sanitize_text_field( (string) $sync_id ) );
	}

	private function get_value( $array, $key, $default = null ) {
		if ( ! is_array( $array ) ) {
			return $default;
		}

		if ( array_key_exists( $key, $array ) ) {
			return $array[ $key ];
		}

		$pascal = ucfirst( $key );

		if ( array_key_exists( $pascal, $array ) ) {
			return $array[ $pascal ];
		}

		return $default;
	}




	/** Provider-owned IDs are opaque strings: preserve case/content and never cast to numbers. */
	private function extract_external_product_id( $data ) {
		return $this->extract_opaque_external_id( $data, array( 'external_product_id', 'externalProductId', 'ExternalProductId' ) );
	}

	private function extract_external_variant_id( $data ) {
		return $this->extract_opaque_external_id( $data, array( 'external_variant_id', 'externalVariantId', 'ExternalVariantId' ) );
	}

	private function extract_opaque_external_id( $data, $keys ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		foreach ( (array) $keys as $key ) {
			$value = $this->get_value( $data, $key, null );
			if ( null === $value || is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}
			/* The backend enforces its 512-character contract. Do not re-measure here
			 * with byte-based strlen(), because opaque Unicode IDs must round-trip exactly. */
			return $value;
		}
		return '';
	}

	private function has_stored_simple_variant_identity( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}
		if ( $this->get_stored_portal_variant_id( $product_id ) > 0 ) {
			return true;
		}
		if ( '' !== (string) get_post_meta( $product_id, '_mobo_external_variant_id', true ) ) {
			return true;
		}
		return Mobo_Core_Remote_Identity_Policy::is_valid( (string) get_post_meta( $product_id, 'variant_guid', true ) );
	}


	private function extract_portal_product_id( $data ) {
		return $this->extract_positive_int_from_payload(
			$data,
			array(
				'portal_product_id',
				'portalProductId',
				'PortalProductId',
				'portal_product_id_api',
				'portalId',
			)
		);
	}

	private function extract_portal_variant_id( $data ) {
		$portal_variant_id = $this->extract_positive_int_from_payload(
			$data,
			array(
				'portal_variant_id',
				'portalVariantId',
				'PortalVariantId',
				'portalVariantID',
				'variant_portal_id',
				'variantPortalId',
			)
		);

		if ( $portal_variant_id > 0 ) {
			return $portal_variant_id;
		}

		/*
		 * Storefront simple products expose the purchasable ID as variant.id.
		 * A bare numeric id is accepted only when the array itself is clearly a
		 * variant payload, so the Mobo product ID cannot be mistaken for a variant.
		 */
		if ( $this->looks_like_variant_payload( $data ) ) {
			return $this->extract_positive_int_from_payload(
				$data,
				array( 'id', 'variant_id', 'variantIdNumeric', 'portalId' )
			);
		}

		$embedded = $this->extract_embedded_simple_variant_data( $data );

		if ( ! empty( $embedded ) && $embedded !== $data ) {
			return $this->extract_portal_variant_id( $embedded );
		}

		return 0;
	}

	private function extract_positive_int_from_payload( $data, $keys ) {
		if ( ! is_array( $data ) || ! is_array( $keys ) ) {
			return 0;
		}

		foreach ( $keys as $key ) {
			$value = $this->get_value( $data, $key, null );

			if ( null === $value || '' === $value || is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );

			if ( '' === $value || ! preg_match( '/^\d+$/', $value ) ) {
				continue;
			}

			$int_value = absint( $value );

			if ( $int_value > 0 ) {
				return $int_value;
			}
		}

		return 0;
	}

	/**
	 * Extract remote product GUID from a payload.
	 *
	 * @param array $data Payload.
	 * @return string
	 */
	private function extract_product_guid( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$keys = array( 'product_guid', 'productGuid', 'productId', 'parent_product_guid', 'parentProductId', 'parentGuid', 'remote_guid', 'remoteGuid', 'entity_guid', 'entityGuid', 'entityId', 'id' );

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $data, $key, '' ) );
			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Extract remote variation GUID from a payload.
	 *
	 * @param array $data Payload.
	 * @return string
	 */
	private function extract_variant_guid( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$keys = array( 'variant_guid', 'variantGuid', 'variantId', 'guid', 'remote_guid', 'remoteGuid', 'entity_guid', 'entityGuid', 'entity_id', 'entityId', 'id' );

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $data, $key, '' ) );
			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Check whether a value is usable as a remote GUID.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function is_remote_guid_value( $value ) {
	return Mobo_Core_Remote_Identity_Policy::is_valid( $value );
}

	/**
	 * Return the first non-empty scalar value.
	 *
	 * @param array $values Values.
	 * @return string
	 */
	private function first_non_empty( $values ) {
		foreach ( (array) $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Determine whether an array is a zero-based list.
	 *
	 * @param array $array Array.
	 * @return bool
	 */
	private function is_list_array( $array ) {
		if ( ! is_array( $array ) ) {
			return false;
		}

		$expected = 0;
		foreach ( array_keys( $array ) as $key ) {
			if ( $key !== $expected ) {
				return false;
			}
			$expected++;
		}

		return true;
	}

	/**
	 * Resolve parent product GUID from a known remote variant GUID.
	 *
	 * @param string $variant_guid Remote variant GUID.
	 * @return string
	 */
	private function find_parent_product_guid_by_variant_guid( $variant_guid ) {
		global $wpdb;

		$variant_guid = sanitize_text_field( (string) $variant_guid );
		if ( '' === $variant_guid ) {
			return '';
		}

		$parent_guid = '';

		if ( class_exists( 'Mobo_Core_Product_Map' ) && Mobo_Core_Product_Map::table_exists() ) {
			$table = Mobo_Core_Product_Map::table_name();
			$parent_guid = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT parent_remote_guid FROM {$table} WHERE connection_id = %d AND remote_guid = %s AND object_type = %s LIMIT 1",
					$this->connection_id(),
					$variant_guid,
					Mobo_Core_Product_Map::TYPE_VARIATION
				)
			);
		}

		if ( '' !== sanitize_text_field( (string) $parent_guid ) ) {
			return sanitize_text_field( (string) $parent_guid );
		}

		/* Simple products carry their purchasable Variant identity in the Product
		 * state row after the 10.33.95 terminal identity cutover. Never fall back to
		 * physically retired postmeta once that fence exists. */
		$product_id = 0;
		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::must_use_custom_reads() && class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready() ) {
			$table = Mobo_Core_Storage_Schema::table_name( 'products' );
			$product_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT woo_product_id FROM {$table} WHERE connection_id=%d AND simple_variant_guid=%s LIMIT 1", $this->connection_id(), $variant_guid ) ) );
		} else {
			$product_id = absint( $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = 'product' AND pm.meta_key IN ('variant_guid','mobo_variant_guid','_mobo_variant_guid') AND pm.meta_value = %s LIMIT 1",
					$variant_guid
				)
			) );
		}

		return $product_id > 0 ? sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) ) : '';
	}

	private function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value === 1;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return ! empty( $value );
	}

	private function result( $success, $message, $data = array() ) {
		return array(
			'success' => (bool) $success,
			'message' => sanitize_text_field( (string) $message ),
			'data'    => is_array( $data ) ? $data : array(),
		);
	}

	

/**
 * Return product category refs from all supported payload field names.
 *
 * Identity must be based on category GUIDs, but the collection name can differ
 * between .NET serializers or older payload versions.
 *
 * @param array $data Product payload.
 * @return array
 */
private function get_product_category_refs_from_payload( $data ) {
	if ( ! is_array( $data ) ) {
		return array();
	}

	$keys = array(
		'product_categories',
		'productCategories',
		'ProductCategories',
		'category_refs',
		'categoryRefs',
		'categories',
		'Categories',
		'category_guids',
		'categoryGuids',
		'CategoryGuids',
	);

	foreach ( $keys as $key ) {
		$value = $this->get_value( $data, $key, null );

		if ( is_array( $value ) && ! empty( $value ) ) {
			return $value;
		}
	}

	return array();
}


/**
 * Store product category refs for later category reapply runs.
 *
 * @param int   $product_id Product ID.
 * @param mixed $category_refs Raw category refs.
 * @return void
 */
private function store_product_category_refs( $product_id, $category_refs ) {
	$product_id = absint( $product_id );

	if ( $product_id <= 0 ) {
		return false;
	}

	if ( ! is_array( $category_refs ) ) {
		return false;
	}

	$normalized = array();
	$guids      = array();
	foreach ( $category_refs as $ref ) {
		if ( ! is_array( $ref ) ) {
			$guid = sanitize_text_field( (string) $ref );
			if ( $this->is_remote_guid_value( $guid ) ) {
				$normalized[] = array( 'id' => $guid );
				$guids[]      = $guid;
			}
			continue;
		}
		$guid = $this->extract_category_guid_for_storage( $ref );
		if ( '' === $guid ) {
			continue;
		}
		$normalized[] = array(
			'id'       => $guid,
			'title'    => sanitize_text_field( (string) $this->get_value( $ref, 'title', '' ) ),
			'url'      => sanitize_text_field( (string) $this->get_value( $ref, 'url', '' ) ),
			'parentId' => sanitize_text_field( (string) $this->get_value( $ref, 'parentId', '' ) ),
		);
		$guids[] = $guid;
	}

	/* An explicit empty list is authoritative desired state, not absence of local
	 * knowledge. Persist [] plus a marker so Recategorize can never resurrect
	 * categories from an older webhook/API fallback after the source intentionally
	 * cleared every category. */
	/* A category title with an emoji used to make this utf8mb3 postmeta write
	 * fail, which threw "Could not durably persist product category reference
	 * metadata" and stalled the product forever. json_for_meta() escapes only
	 * supplementary characters (😀) when the column needs it; the
	 * decoded refs are identical. */
	$json = class_exists( 'Mobo_Core_Unicode_Guard' )
		? Mobo_Core_Unicode_Guard::json_for_meta( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 'category-refs' )
		: wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		return false;
	}
	$guids = array_values( array_unique( array_filter( $guids ) ) );
	return $this->persist_post_meta_verified( $product_id, 'mobo_product_category_refs_json', $json )
		&& $this->persist_post_meta_verified( $product_id, 'mobo_product_category_guids', $guids )
		&& $this->persist_post_meta_verified( $product_id, '_mobo_product_category_refs_authoritative', '1' );
}

/**
 * Extract category GUID for storage.
 *
 * @param array $ref Category ref.
 * @return string
 */
private function extract_category_guid_for_storage( $ref ) {
	$guids = $this->collect_category_guid_candidates_for_storage( $ref );

	return ! empty( $guids ) ? sanitize_text_field( (string) $guids[0] ) : '';
}

/**
 * Collect category GUID candidates for storage, preferring actual category GUIDs over relation IDs.
 *
 * @param mixed $ref Category ref.
 * @return array
 */
private function collect_category_guid_candidates_for_storage( $ref ) {
	return Mobo_Core_Remote_Identity_Policy::collect_category_guid_candidates( $ref );
}

/**
 * Append storage GUID candidate.
 *
 * @param array $guids GUID list.
 * @param mixed $value Raw value.
 * @return void
 */
private function append_category_guid_candidate_for_storage( &$guids, $value ) {
	$value = trim( sanitize_text_field( (string) $value ) );
	if ( '' !== $value && $this->is_remote_guid_value( $value ) ) {
		$guids[] = $value;
	}
}

/**
 * Check if product should be excluded by URL.
 *
 * @param array $product_data Product payload.
 * @return bool
 */
private function should_skip_product_by_url( $product_data ) {
	return Mobo_Core_Product_Exclusions::is_payload_excluded( $product_data, true );
}

/**
 * Get excluded product URLs from the single shared policy source of truth.
 *
 * @return array
 */
private function get_excluded_product_urls() {
	return Mobo_Core_Product_Exclusions::get_excluded_urls();
}

/**
 * Normalize product URL/path through the single shared exclusion policy.
 *
 * @param string $url URL or path.
 * @return string
 */
private function normalize_product_url_for_exclusion( $url ) {
	return Mobo_Core_Product_Exclusions::normalize_url( $url );
}
}
