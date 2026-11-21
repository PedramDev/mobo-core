<?php
/**
 * Irreversible operational postmeta retirement for Product/Variation runtime state.
 *
 * 10.33.94 moves the seven high-churn Mobo operational keys to terminal Custom
 * Storage authority. A durable fence is published only after Product Map seeding,
 * backfill and an exact bounded verification pass. Physical postmeta cleanup starts
 * only after a short grace period and database-version stamping remains blocked until
 * a zero-residue proof succeeds.
 *
 * Identity/media/pricing metadata and attachment-level mobo_sync_incomplete are out
 * of scope for this migration.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Storage_Operational_Meta_Retirement {

	const TARGET_VERSION = '10.33.94';
	const WORKER_NAME = 'storage_operational_meta_retirement_v2';
	const FENCE_VERSION = 2;
	const FENCE_OPTION = 'mobo_core_operational_meta_terminal_fence_v2';
	const PROOF_BATCH = 100;
	const CLEANUP_BATCH = 200;
	const RECONCILE_BATCH = 50;
	const GRACE_SECONDS = 120;

	/** @var bool|null Request-local positive fence cache. False is intentionally not cached. */
	private static $fenced = null;

	/** @var bool Request-local cache: terminal zero-residue was already proven. */
	private static $zero_residue_verified = false;
	/** @var bool Reconciliation reads fail closed on any storage query error. */
	private static $reconcile_query_error = false;
	/** @var array<int,bool> Request-local set-based reconcile snapshot scope. */
	private static $reconcile_legacy_scope = array();
	/** @var array<int,array<string,mixed>> First Legacy value by post/key inside the current reconcile page. */
	private static $reconcile_legacy_first = array();
	/** @var array<int,bool> Custom Product-state rows covered by the current reconcile snapshot. */
	private static $reconcile_product_scope = array();
	/** @var array<int,array<string,mixed>> */
	private static $reconcile_product_rows = array();
	/** @var array<int,bool> Custom Variant-state rows covered by the current reconcile snapshot. */
	private static $reconcile_variant_scope = array();
	/** @var array<int,array<string,mixed>> */
	private static $reconcile_variant_rows = array();

	/** @var array<int,bool> Custom Product-state rows covered by the current Operational proof page. */
	private static $proof_product_scope = array();
	/** @var array<int,array<string,mixed>> */
	private static $proof_product_rows = array();
	/** @var array<int,bool> Custom Variant-state rows covered by the current Operational proof page. */
	private static $proof_variant_scope = array();
	/** @var array<int,array<string,mixed>> */
	private static $proof_variant_rows = array();

	/** @return array<string,string> */
	public static function managed_keys() {
		return array(
			'_mobo_product_seen_revision'     => 'revision',
			'_mobo_product_applied_revision'  => 'revision',
			'_mobo_variant_seen_revision'     => 'revision',
			'_mobo_variant_applied_revision'  => 'revision',
			'_mobo_product_source_hash'       => 'hash',
			'_mobo_variant_source_hash'       => 'hash',
			'mobo_sync_incomplete'             => 'incomplete',
		);
	}

	/**
	 * Whether a Product/Variation is currently owned by Mobo identity. Used only
	 * to distinguish a truly corrupt missing operational row from an unrelated
	 * Woo object after terminal cutover.
	 *
	 * @return bool
	 */
	public static function object_is_mobo( $object_id ) {
		$object_id = absint( $object_id );
		$type = get_post_type( $object_id );
		if ( $object_id <= 0 || ! in_array( $type, array( 'product', 'product_variation' ), true ) ) { return false; }
		$key = 'product_variation' === $type ? 'variant_guid' : 'product_guid';
		$guid = sanitize_text_field( (string) get_post_meta( $object_id, $key, true ) );
		return class_exists( 'Mobo_Core_Remote_Identity_Policy' )
			? Mobo_Core_Remote_Identity_Policy::is_valid( $guid )
			: '' !== $guid;
	}

	/** @return bool */
	public static function key_applies_to_object( $object_id, $meta_key ) {
		$object_id = absint( $object_id );
		$meta_key = (string) $meta_key;
		if ( $object_id <= 0 || ! isset( self::managed_keys()[ $meta_key ] ) ) { return false; }
		$type = get_post_type( $object_id );
		if ( 'product' === $type ) {
			return '_mobo_variant_source_hash' !== $meta_key;
		}
		if ( 'product_variation' === $type ) {
			return in_array( $meta_key, array( '_mobo_variant_seen_revision','_mobo_variant_applied_revision','_mobo_variant_source_hash','mobo_sync_incomplete' ), true );
		}
		return false;
	}

	/**
	 * Durable terminal fence. A filter exists only so isolated deep tests can model
	 * the pre-terminal upgrade state in one request; production has no caller for it.
	 *
	 * @return bool
	 */
	public static function is_fenced() {
		if ( true === self::$fenced ) { return false !== apply_filters( 'mobo_core_operational_meta_terminal_fence_active', true ); }

		/* The fence is intentionally redundant. The runtime-table row is the rich
		 * migration state, while this tiny immutable option is the fail-closed marker
		 * used if that row cannot be read. A completed DB version is a final fallback. */
		$option_fence = self::FENCE_VERSION === absint( get_option( self::FENCE_OPTION, 0 ) );
		$version_fence = version_compare( (string) get_option( 'mobo_core_db_version', '0' ), self::TARGET_VERSION, '>=' );
		if ( $option_fence || $version_fence ) {
			self::$fenced = true;
			return false !== apply_filters( 'mobo_core_operational_meta_terminal_fence_active', true );
		}

		$state = self::load_state();
		$active = ! empty( $state['fenceActive'] ) && self::FENCE_VERSION === absint( isset( $state['fenceVersion'] ) ? $state['fenceVersion'] : 0 );
		if ( $active ) {
			self::$fenced = true;
			/* Self-heal the redundant marker; false can also mean it already existed. */
			update_option( self::FENCE_OPTION, self::FENCE_VERSION, false );
		}
		return $active && false !== apply_filters( 'mobo_core_operational_meta_terminal_fence_active', true );
	}

	/** @return void */
	public static function reset_request_cache() { self::$fenced = null; self::$zero_residue_verified = false; self::reset_reconcile_batch_cache(); self::reset_proof_batch_cache(); }


	/** @return int */
	private static function proof_batch_size() { return self::PROOF_BATCH; }
	/** @return int */
	private static function cleanup_batch_size() { return class_exists( 'Mobo_Core_Migration' ) && Mobo_Core_Migration::is_accelerated_context() ? 2000 : self::CLEANUP_BATCH; }

	/** @return array */
	public static function get_status() {
		$state = self::load_state();
		unset( $state['__persistedStateJson'], $state['__stateLoadError'] );
		$state['workerName'] = self::WORKER_NAME;
		$state['targetVersion'] = self::TARGET_VERSION;
		$state['fenced'] = self::is_fenced();
		$state['managedKeys'] = array_keys( self::managed_keys() );
		$state['scope'] = 'product/product_variation only; attachment metadata retained';
		return $state;
	}

	/**
	 * Advance at most one bounded migration step. Returns true only after zero
	 * physical Product/Variation residue has been proven.
	 *
	 * @return bool
	 */
	public static function run_migration_step() {
		if ( class_exists( 'Mobo_Core_Migration' ) && ! Mobo_Core_Migration::accelerated_slice_lock_is_valid() ) { return false; }
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() || ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists() ) {
			return false;
		}
		$state = self::load_state();
		if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		/* The tiny option/DB-version marker is authoritative even if the richer
		 * runtime-state row was lost or its final activation write failed. Recover
		 * the durable progress row before doing anything else, and restart the grace
		 * window rather than risking cleanup immediately after a state loss. */
		if ( self::is_fenced() && empty( $state['fenceActive'] ) ) {
			$state['fenceActive'] = true;
			$state['fenceVersion'] = self::FENCE_VERSION;
			$state['fenceActivatedAt'] = time();
			$state['status'] = 'fence-state-recovered';
			$state['lastError'] = '';
			if ( ! self::persist_state( $state, false ) ) { return false; }
		}
		if ( ! empty( $state['complete'] ) ) {
			self::enforce_terminal_options();
			$late_residue = self::has_physical_residue();
			if ( null === $late_residue ) { return false; }
			if ( ! $late_residue ) { self::$zero_residue_verified = true; return true; }
			/* A pre-fence request can outlive grace and die after committing Legacy.
			 * Re-open only the physical cleanup state; the permanent fence remains active. */
			$state['complete'] = false;
			$state['status'] = 'late-residue-recovery';
			$state['physicalResidue'] = null;
			$state['lastError'] = '';
			if ( ! self::persist_state( $state ) ) { return false; }
			$state = self::load_state();
			if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		}

		if ( empty( $state['fenceActive'] ) ) {
			/* 10.34.77 hardening: an additional (even disabled) Connection no longer
			 * blocks retirement of the Default Connection's data. Objects durably owned
			 * by another Connection are excluded from reconcile/proof/cleanup by
			 * Mobo_Core_Migration_Quarantine::foreign_exclusion_sql(). That exclusion is
			 * only sound after the ownership migration attributed every row, so wait
			 * (transiently) for that proof instead of blocking forever. */
			if ( class_exists( 'Mobo_Core_Migration_Quarantine' ) && ! Mobo_Core_Migration_Quarantine::retirement_topology_allowed() ) {
				$state['status'] = 'waiting-connection-ownership';
				$state['blockers'] = array( 'multi_connection_ownership_pending' => 1 );
				$state['lastError'] = '';
				self::persist_state( $state );
				return false;
			}
			self::ensure_pre_fence_mode();
			if ( absint( get_option( 'mobo_core_product_map_seed_completed_at', 0 ) ) <= 0 ) {
				self::record_state_error( $state, 'product_map_seed_not_complete', 'waiting-map-seed' );
				return false;
			}
			if ( class_exists( 'Mobo_Core_Storage_Backfill' ) ) {
				Mobo_Core_Storage_Backfill::schedule();
				/* Keep each backfill unit deliberately small even inside the accelerated
				 * slice. Customer hosting may terminate PHP without warning; speed comes
				 * from running multiple durable 100-row units per bounded Cron request,
				 * not from making one uninterruptible unit larger. */
				$backfill = Mobo_Core_Storage_Backfill::process_batch( 100, true );
				$status = sanitize_key( (string) ( isset( $backfill['status'] ) ? $backfill['status'] : '' ) );
				$state['backfillStatus'] = $status;
				$state['backfillCursor'] = absint( isset( $backfill['cursor'] ) ? $backfill['cursor'] : 0 );
				$state['backfillMirrored'] = absint( isset( $state['backfillMirrored'] ) ? $state['backfillMirrored'] : 0 ) + absint( isset( $backfill['mirrored'] ) ? $backfill['mirrored'] : 0 );
				if ( ! in_array( $status, array( 'completed','completed-gaps' ), true ) ) {
					$state['status'] = 'waiting-backfill';
					self::persist_state( $state );
					return false;
				}
			}

			/* Product Map seeding intentionally ignores trash/auto-draft objects.
			 * Operational postmeta, however, can legitimately survive on trashed Mobo
			 * objects that Repair must still be able to identify. Reconcile those
			 * objects before exact proof. Auto-draft/unowned residue is pruned only
			 * when ownership cannot be proven; live ambiguous objects remain blocked. */
			$reconcile = self::reconcile_unmapped_operational_batch( absint( $state['reconcileCursor'] ?? 0 ) );
			if ( ! empty( $reconcile['error'] ) ) {
				self::record_state_error( $state, (string) $reconcile['error'], 'reconcile-error' );
				return false;
			}
			if ( ! empty( $reconcile['blockers'] ) ) {
				$state = self::load_state();
				$state['status'] = 'blocked';
				$state['blockers'] = $reconcile['blockers'];
				$state['lastError'] = sanitize_key( (string) key( $reconcile['blockers'] ) );
				$state['reconciledUnmapped'] = absint( isset( $reconcile['reconciled'] ) ? $reconcile['reconciled'] : 0 );
				$state['prunedUnowned'] = absint( isset( $reconcile['pruned'] ) ? $reconcile['pruned'] : 0 );
				$state['reconcileCursor'] = absint( $reconcile['cursor'] ?? ( $state['reconcileCursor'] ?? 0 ) );
				self::persist_state( $state );
				return false;
			}
			if ( ! empty( $reconcile['changed'] ) ) {
				$state = self::load_state();
				$state['proofComplete'] = false;
				$state['status'] = 'reconciling-unmapped';
				$state['lastError'] = '';
				$state['blockers'] = array();
				$state['markedObjects'] = absint( $state['markedObjects'] ?? 0 ) + absint( $reconcile['marked'] ?? 0 );
				$state['reconciledUnmapped'] = absint( isset( $state['reconciledUnmapped'] ) ? $state['reconciledUnmapped'] : 0 ) + absint( isset( $reconcile['reconciled'] ) ? $reconcile['reconciled'] : 0 );
				$state['prunedUnowned'] = absint( isset( $state['prunedUnowned'] ) ? $state['prunedUnowned'] : 0 ) + absint( isset( $reconcile['pruned'] ) ? $reconcile['pruned'] : 0 );
				$state['reconcileCursor'] = absint( $reconcile['cursor'] ?? ( $state['reconcileCursor'] ?? 0 ) );
				self::persist_state( $state );
				return false;
			}
			if ( ! empty( $reconcile['scanComplete'] ) && ! empty( $state['reconcileCursor'] ) ) {
				$state = self::load_state();
				$state['reconcileCursor'] = 0;
				if ( ! self::persist_state( $state ) ) { return false; }
				$state = self::load_state();
			}

			$proof = self::advance_exact_proof( $state );
			if ( ! $proof['complete'] ) { return false; }
			/* Normalize exact duplicates / mark unresolvable objects in bounded passes
			 * before the global proof. Progress is persisted and the next step repeats
			 * until the global checks are clean (or a genuine error remains). */
			$normalized = self::normalize_global_blockers();
			if ( ! empty( $normalized['progress'] ) ) {
				$state = self::load_state();
				$state['status'] = 'normalizing';
				$state['lastError'] = '';
				$state['blockers'] = array();
				$state['dedupedGroups'] = absint( $state['dedupedGroups'] ?? 0 ) + absint( $normalized['deduped'] ?? 0 );
				$state['dedupedRows'] = absint( $state['dedupedRows'] ?? 0 ) + absint( $normalized['rowsRemoved'] ?? 0 );
				$state['markedObjects'] = absint( $state['markedObjects'] ?? 0 ) + absint( $normalized['marked'] ?? 0 );
				self::persist_state( $state );
				return false;
			}
			$blockers = ! empty( $normalized['blockers'] ) ? $normalized['blockers'] : self::global_blockers();
			if ( ! empty( $blockers ) ) {
				$state = self::load_state();
				$state['status'] = 'blocked';
				$state['blockers'] = $blockers;
				$state['lastError'] = implode( ',', array_keys( $blockers ) );
				self::persist_state( $state );
				return false;
			}
			if ( ! self::activate_fence() ) { return false; }
			return false;
		}

		self::enforce_terminal_options();
		$fenced_at = absint( isset( $state['fenceActivatedAt'] ) ? $state['fenceActivatedAt'] : 0 );
		if ( $fenced_at <= 0 ) {
			self::record_state_error( $state, 'fence_timestamp_missing', 'blocked');
			return false;
		}
		if ( time() < ( $fenced_at + self::GRACE_SECONDS ) ) {
			$state['status'] = 'fenced-grace';
			$state['graceRemainingSeconds'] = max( 0, ( $fenced_at + self::GRACE_SECONDS ) - time() );
			self::persist_state( $state );
			return false;
		}

		$cleanup = self::cleanup_one_batch( absint( $state['cleanupCursor'] ?? 0 ) );
		if ( false === $cleanup ) {
			$state = self::load_state(); self::record_state_error( $state, 'physical_cleanup_failed', 'cleanup-error' );
			return false;
		}
		if ( absint( $cleanup['deleted'] ?? 0 ) > 0 ) {
			$state = self::load_state();
			$state['status'] = 'physical-cleanup';
			$state['graceRemainingSeconds'] = 0;
			$state['cleanedRows'] = absint( isset( $state['cleanedRows'] ) ? $state['cleanedRows'] : 0 ) + absint( $cleanup['deleted'] );
			$state['cleanupCursor'] = max( absint( $state['cleanupCursor'] ?? 0 ), absint( $cleanup['cursor'] ?? 0 ) );
			if ( ! self::persist_state( $state ) ) { return false; }
			return false;
		}

		$residue = self::physical_residue_count();
		if ( null === $residue || $residue > 0 ) {
			$state = self::load_state();
			$state['status'] = 'residue-proof-failed';
			$state['physicalResidue'] = null === $residue ? -1 : $residue;
			/* A late write or crash-replay residue can land behind the durable cleanup cursor. */
			if ( $residue > 0 ) { $state['cleanupCursor'] = 0; }
			self::persist_state( $state );
			return false;
		}
		$state = self::load_state();
		$state['status'] = 'physical-retirement-complete';
		$state['graceRemainingSeconds'] = 0;
		$state['complete'] = true;
		$state['completedAt'] = time();
		$state['physicalResidue'] = 0;
		$state['lastError'] = '';
		if ( ! self::persist_state( $state, true ) ) { return false; }
		self::$zero_residue_verified = true;
		return true;
	}

	/** pre_add_post_metadata filter. @return mixed */
	public static function filter_add( $check, $object_id, $meta_key, $meta_value, $unique = false ) {
		unset( $unique );
		if ( null !== $check || ! self::is_fenced() || ! self::key_applies_to_object( $object_id, $meta_key ) ) { return $check; }
		$current = Mobo_Core_Storage_Postmeta_Write_Suppression::terminal_current( $object_id, $meta_key );
		if ( 'hit' !== ( $current['status'] ?? '' ) || ! empty( $current['present'] ) ) { return false; }
		$result = Mobo_Core_Storage_Postmeta_Write_Suppression::terminal_set( $object_id, $meta_key, $meta_value );
		return self::mutation_succeeded( $result );
	}

	/** pre_update_post_metadata filter. @return mixed */
	public static function filter_update( $check, $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		if ( null !== $check || ! self::is_fenced() || ! self::key_applies_to_object( $object_id, $meta_key ) ) { return $check; }
		$result = Mobo_Core_Storage_Postmeta_Write_Suppression::terminal_set( $object_id, $meta_key, $meta_value, $prev_value );
		return self::mutation_succeeded( $result );
	}

	/** pre_delete_post_metadata filter. @return mixed */
	public static function filter_delete( $check, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
		if ( null !== $check || ! self::is_fenced() ) { return $check; }
		/* delete_metadata(..., $delete_all=true) may pass object_id=0. Never let a
		 * global Legacy delete bypass terminal Custom authority for a managed key. */
		if ( $delete_all && isset( self::managed_keys()[ (string) $meta_key ] ) ) { return false; }
		if ( ! self::key_applies_to_object( $object_id, $meta_key ) ) { return $check; }
		$result = Mobo_Core_Storage_Postmeta_Write_Suppression::terminal_delete( $object_id, $meta_key, $meta_value );
		return self::mutation_succeeded( $result );
	}

	/** @return bool */
	private static function mutation_succeeded( $result ) {
		$status = sanitize_key( (string) ( is_array( $result ) && isset( $result['status'] ) ? $result['status'] : '' ) );
		return in_array( $status, array( 'applied','noop' ), true );
	}

	/** @return array{complete:bool} */
	private static function advance_exact_proof( $state ) {
		global $wpdb;
		$cursor = absint( isset( $state['proofCursor'] ) ? $state['proofCursor'] : 0 );
		$table = Mobo_Core_Product_Map::table_name();
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $connection_id <= 0 ) { self::record_state_error( $state, 'connection_owner_missing', 'proof-blocked' ); return array( 'complete'=>false ); }
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id,remote_guid,wp_post_id,object_type,parent_remote_guid,sync_incomplete FROM {$table} WHERE connection_id=%d AND id>%d AND object_type IN (%s,%s) ORDER BY id ASC LIMIT %d",
			$connection_id, $cursor, Mobo_Core_Product_Map::TYPE_PRODUCT, Mobo_Core_Product_Map::TYPE_VARIATION, self::proof_batch_size()
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			self::record_state_error( $state, 'proof_query_failed', 'proof-error' ); return array( 'complete'=>false );
		}
		if ( empty( $rows ) ) {
			$state['proofComplete'] = true; $state['status'] = 'proof-complete'; $state['lastError']=''; self::persist_state( $state );
			return array( 'complete'=>true );
		}

		/* Prime the bounded proof page once. Legacy post/meta reads stay request-local
		 * cache hits, while Custom Product/Variant rows are fetched set-wise. The
		 * healthy path verifies first and does not replay the heavyweight bridge.
		 * Mismatch recovery still uses the original mirror + fresh verification path. */
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'wp_post_id' ) ) ) ) );
		if ( ! self::prime_proof_batch_cache( $post_ids ) ) {
			self::record_state_error( $state, 'proof_batch_prefetch_failed', 'proof-error' );
			return array( 'complete'=>false );
		}
		$proof_noops = 0;
		$proof_mirrors = 0;
		$proof_marked = 0;
		$legacy_scope = class_exists( 'Mobo_Core_Storage_Bridge' )
			&& method_exists( 'Mobo_Core_Storage_Bridge', 'begin_migration_pre_fence_legacy_reads' );
		if ( $legacy_scope ) {
			Mobo_Core_Storage_Bridge::begin_migration_pre_fence_legacy_reads();
		}

		try {
		foreach ( $rows as $row ) {
			$row_id=absint($row['id']??0);$post_id=absint($row['wp_post_id']??0);$object_type=sanitize_key((string)($row['object_type']??''));$guid=sanitize_text_field((string)($row['remote_guid']??''));
			$expected = Mobo_Core_Product_Map::TYPE_VARIATION === $object_type ? 'product_variation' : 'product';
			if ( $post_id <= 0 || get_post_type( $post_id ) !== $expected ) { $cursor=$row_id; continue; }
			/* Marked objects keep their legacy rows and are skipped by every worker. */
			if ( class_exists( 'Mobo_Core_Migration_Quarantine' ) && Mobo_Core_Migration_Quarantine::is_marked( $post_id ) ) { $cursor=$row_id; continue; }
			if ( ! Mobo_Core_Remote_Identity_Policy::is_valid( $guid ) ) {
				/* Deterministic data problem: mark this object and continue (formerly blocked the whole worker). */
				if ( class_exists( 'Mobo_Core_Migration_Quarantine' ) && Mobo_Core_Migration_Quarantine::mark( $post_id, 'operational', 'invalid_map_guid', 'map#' . $row_id ) ) { $proof_marked++; $cursor=$row_id; continue; }
				self::record_state_error( $state, 'proof_invalid_guid_'.$row_id, 'proof-blocked' ); return array('complete'=>false);
			}
			$incomplete=!empty($row['sync_incomplete']);
			$failure='';
			if ( Mobo_Core_Product_Map::TYPE_PRODUCT === $object_type ) {
				$snapshot=Mobo_Core_Storage_Bridge::legacy_product_snapshot($guid,$post_id,$incomplete);
				if(!is_array($snapshot)){$failure='product_proof_failed_'.$row_id;}
				elseif(!self::product_snapshot_matches_custom($snapshot,self::proof_product_state_row($post_id))){
					if(!Mobo_Core_Storage_Bridge::mirror_product_legacy_state($guid,$post_id,$incomplete)){$failure='product_proof_failed_'.$row_id;}
					else{
						self::invalidate_proof_product_state($post_id);
						if(!self::product_snapshot_matches_custom($snapshot)){$failure='product_proof_failed_'.$row_id;}else{$proof_mirrors++;}
					}
				}else{$proof_noops++;}
			} else {
				$parent=sanitize_text_field((string)($row['parent_remote_guid']??''));
				$snapshot=Mobo_Core_Storage_Bridge::legacy_variant_snapshot($guid,$post_id,$parent,$incomplete);
				if(!is_array($snapshot)){$failure='variant_proof_failed_'.$row_id;}
				elseif(!self::variant_snapshot_matches_custom($snapshot,self::proof_variant_state_row($post_id))){
					if(!Mobo_Core_Storage_Bridge::mirror_variant_legacy_state($guid,$post_id,(string)$snapshot['product_guid'],$incomplete)){$failure='variant_proof_failed_'.$row_id;}
					else{
						self::invalidate_proof_variant_state($post_id);
						if(!self::variant_snapshot_matches_custom($snapshot)){$failure='variant_proof_failed_'.$row_id;}else{$proof_mirrors++;}
					}
				}else{$proof_noops++;}
			}
			if ( '' !== $failure ) {
				/* A failed mirror/verify can be transient (e.g. a concurrent write that the
				 * fresh verification caught), so it is retried first. Only after
				 * FAILURE_THRESHOLD consecutive failures on the same object is it marked
				 * and skipped so the rest of the catalog can continue. */
				if ( class_exists( 'Mobo_Core_Migration_Quarantine' ) && Mobo_Core_Migration_Quarantine::record_failure( $post_id )
					&& Mobo_Core_Migration_Quarantine::mark( $post_id, 'operational', 'mirror_failed', $failure ) ) {
					$proof_marked++; $cursor=$row_id; continue;
				}
				self::record_state_error($state,$failure,'proof-error');return array('complete'=>false);
			}
			$cursor=$row_id;
		}
		} finally {
			if ( $legacy_scope ) {
				Mobo_Core_Storage_Bridge::end_migration_pre_fence_legacy_reads();
			}
			self::release_proof_batch_cache($post_ids);
		}
		$state['proofCursor']=$cursor;$state['status']='proof-running';$state['lastError']='';
		if($proof_noops>0){$state['proofNoopVerified']=absint($state['proofNoopVerified']??0)+$proof_noops;}
		if($proof_mirrors>0){$state['proofMirrorWrites']=absint($state['proofMirrorWrites']??0)+$proof_mirrors;}
		if($proof_marked>0){$state['markedObjects']=absint($state['markedObjects']??0)+$proof_marked;}
		self::persist_state($state);
		return array('complete'=>false);
	}

	/** @return bool */
	private static function product_snapshot_matches_custom( $s, $r = null ) {
		if(null===$r){$repo=new Mobo_Core_Product_State_Repository();$r=$repo->get_by_woo_product_id(absint($s['woo_product_id']??0));}if(!is_array($r)){return false;}
		$keys=array('product_seen_revision','product_applied_revision','variant_seen_revision','variant_applied_revision');foreach($keys as $k){if(absint($r[$k]??0)!==absint($s[$k]??0)||absint($r[$k.'_present']??0)!==(!empty($s[$k.'_present'])?1:0)){return false;}}
		return (string)($r['product_guid']??'')===(string)($s['product_guid']??'')
			&& (string)($r['product_source_hash']??'')===(string)($s['product_source_hash']??'')
			&& absint($r['product_source_hash_present']??0)===(!empty($s['product_source_hash_present'])?1:0)
			&& absint($r['sync_incomplete_meta']??0)===(!empty($s['sync_incomplete_meta'])?1:0)
			&& absint($r['sync_incomplete_meta_present']??0)===(!empty($s['sync_incomplete_meta_present'])?1:0)
			&& absint($r['sync_incomplete']??0)===(!empty($s['sync_incomplete'])?1:0);
	}

	/** @return bool */
	private static function variant_snapshot_matches_custom( $s, $r = null ) {
		if(null===$r){$repo=new Mobo_Core_Variant_State_Repository();$r=$repo->get_by_woo_variation_id(absint($s['woo_variation_id']??0));}if(!is_array($r)){return false;}
		foreach(array('variant_seen_revision','variant_applied_revision') as $k){if(absint($r[$k]??0)!==absint($s[$k]??0)||absint($r[$k.'_present']??0)!==(!empty($s[$k.'_present'])?1:0)){return false;}}
		return (string)($r['variant_guid']??'')===(string)($s['variant_guid']??'')
			&& (string)($r['product_guid']??'')===(string)($s['product_guid']??'')
			&& (string)($r['variant_source_hash']??'')===(string)($s['variant_source_hash']??'')
			&& absint($r['variant_source_hash_present']??0)===(!empty($s['variant_source_hash_present'])?1:0)
			&& absint($r['sync_incomplete_meta']??0)===(!empty($s['sync_incomplete_meta'])?1:0)
			&& absint($r['sync_incomplete_meta_present']??0)===(!empty($s['sync_incomplete_meta_present'])?1:0)
			&& absint($r['sync_incomplete']??0)===(!empty($s['sync_incomplete'])?1:0);
	}


	/** Reset request-local Operational proof snapshot. @return void */
	private static function reset_proof_batch_cache() {
		self::$proof_product_scope=array();self::$proof_product_rows=array();self::$proof_variant_scope=array();self::$proof_variant_rows=array();
	}

	/** Set-based Custom-state snapshot for one bounded Operational proof page. @return bool */
	private static function prime_proof_batch_cache( $post_ids ) {
		global $wpdb;self::reset_proof_batch_cache();$post_ids=array_values(array_unique(array_filter(array_map('absint',(array)$post_ids))));if(empty($post_ids)){return true;}
		if(function_exists('_prime_post_caches')){_prime_post_caches($post_ids,false,false);}update_meta_cache('post',$post_ids);
		foreach($post_ids as $id){self::$proof_product_scope[$id]=true;self::$proof_variant_scope[$id]=true;}
		$connection_id=class_exists('Mobo_Core_Connection_Context')?absint(Mobo_Core_Connection_Context::get_effective_connection_id()):0;if($connection_id<=0){return false;}
		$idph=implode(',',array_fill(0,count($post_ids),'%d'));
		$pt=Mobo_Core_Storage_Schema::table_name('products');$wpdb->last_error='';$prows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$pt} WHERE connection_id=%d AND woo_product_id IN ({$idph})",array_merge(array($connection_id),$post_ids)),ARRAY_A);if(''!==(string)$wpdb->last_error||!is_array($prows)){return false;}foreach($prows as $row){$id=absint($row['woo_product_id']??0);if($id>0){self::$proof_product_rows[$id]=$row;}}
		$vt=Mobo_Core_Storage_Schema::table_name('variants');$wpdb->last_error='';$vrows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$vt} WHERE connection_id=%d AND woo_variation_id IN ({$idph})",array_merge(array($connection_id),$post_ids)),ARRAY_A);if(''!==(string)$wpdb->last_error||!is_array($vrows)){return false;}foreach($vrows as $row){$id=absint($row['woo_variation_id']??0);if($id>0){self::$proof_variant_rows[$id]=$row;}}
		return true;
	}

	/** @return array|null */
	private static function proof_product_state_row( $post_id ) {$post_id=absint($post_id);if(isset(self::$proof_product_scope[$post_id])){return self::$proof_product_rows[$post_id]??null;}return (new Mobo_Core_Product_State_Repository())->get_by_woo_product_id($post_id);}
	/** @return array|null */
	private static function proof_variant_state_row( $post_id ) {$post_id=absint($post_id);if(isset(self::$proof_variant_scope[$post_id])){return self::$proof_variant_rows[$post_id]??null;}return (new Mobo_Core_Variant_State_Repository())->get_by_woo_variation_id($post_id);}
	/** @return void */
	private static function invalidate_proof_product_state( $post_id ) {$post_id=absint($post_id);unset(self::$proof_product_scope[$post_id],self::$proof_product_rows[$post_id]);}
	/** @return void */
	private static function invalidate_proof_variant_state( $post_id ) {$post_id=absint($post_id);unset(self::$proof_variant_scope[$post_id],self::$proof_variant_rows[$post_id]);}

	/** Release only the heavy postmeta cache retained by this proof page. @return void */
	private static function release_proof_batch_cache( $post_ids ) {
		foreach((array)$post_ids as $post_id){$post_id=absint($post_id);if($post_id>0){wp_cache_delete($post_id,'post_meta');}}
		self::reset_proof_batch_cache();
	}

	/**
	 * Reconcile operational Product/Variation meta that predates or falls outside
	 * normal Product Map seeding. This is deliberately conservative: a durable map
	 * row is synthesized only when one unambiguous Mobo identity can be proven for
	 * the same Woo object. Trash is allowed because Repair keeps trash recoverable;
	 * auto-drafts and trash objects with no provable Mobo identity are transient/
	 * orphan residue and may be pruned. Live ambiguous objects remain hard blockers.
	 *
	 * @return array{changed:int,reconciled:int,pruned:int,blockers:array<string,int>,error:string}
	 */
	private static function reconcile_unmapped_operational_batch( $cursor = 0 ) {
		global $wpdb;
		$cursor = absint( $cursor );
		$out = array( 'changed'=>0, 'reconciled'=>0, 'pruned'=>0, 'marked'=>0, 'blockers'=>array(), 'error'=>'', 'cursor'=>$cursor, 'scanComplete'=>false );
		$keys = array_keys( self::managed_keys() );
		/* Marked objects and objects owned by another Connection are not this
		 * worker's to reconcile; without the exclusion a marked object would be
		 * rediscovered forever after the cursor resets. */
		$excl = class_exists( 'Mobo_Core_Migration_Quarantine' ) ? Mobo_Core_Migration_Quarantine::exclusion_sql( 'p.ID' ) : '';
		$ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$map = Mobo_Core_Product_Map::table_name();
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $connection_id <= 0 ) { $out['error']='unmapped_connection_owner_missing'; return $out; }
		$args = array_merge( array( $connection_id, $cursor ), $keys, array( self::RECONCILE_BATCH ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID,p.post_type,p.post_status,p.post_parent
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
				 LEFT JOIN {$map} m ON m.connection_id=%d AND m.wp_post_id=p.ID AND m.object_type=IF(p.post_type='product','product','variation')
				 WHERE p.post_type IN ('product','product_variation')
				 AND p.ID>%d
				 AND pm.meta_key IN ({$ph}) AND m.id IS NULL{$excl}
				 GROUP BY p.ID,p.post_type,p.post_status,p.post_parent
				 ORDER BY p.ID ASC LIMIT %d",
				$args
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) { $out['error'] = 'unmapped_reconcile_query_failed'; return $out; }
		if ( empty( $rows ) ) { self::reset_reconcile_batch_cache(); $out['scanComplete']=true; return $out; }
		if ( ! self::prime_reconcile_batch_cache( $rows ) ) { $out['error'] = 'unmapped_reconcile_prefetch_failed'; return $out; }

		foreach ( $rows as $row ) {
			$post_id = absint( isset( $row['ID'] ) ? $row['ID'] : 0 );
			$type = sanitize_key( (string) ( isset( $row['post_type'] ) ? $row['post_type'] : '' ) );
			$status = sanitize_key( (string) ( isset( $row['post_status'] ) ? $row['post_status'] : '' ) );
			if ( $post_id <= 0 || ! in_array( $type, array( 'product','product_variation' ), true ) ) { continue; }
			self::$reconcile_query_error = false;
			$identity = self::resolve_unmapped_identity( $post_id, $type );
			if ( self::$reconcile_query_error ) { $out['error'] = 'unmapped_identity_read_failed'; return $out; }
			$identity_status = sanitize_key( (string) ( isset( $identity['status'] ) ? $identity['status'] : '' ) );
			if ( 'auto-draft' === $status && 'ok' === $identity_status ) {
				if ( self::mark_reconcile_object( $out, $post_id, 'autodraft_with_identity' ) ) { continue; }
				$out['blockers']['operational_autodraft_with_identity'] = absint( isset( $out['blockers']['operational_autodraft_with_identity'] ) ? $out['blockers']['operational_autodraft_with_identity'] : 0 ) + 1;
				return $out;
			}
			if ( 'ok' !== $identity_status ) {
				if ( in_array( $status, array( 'trash','auto-draft' ), true ) && in_array( $identity_status, array( 'missing','parent-missing' ), true ) ) {
					$deleted = self::prune_operational_meta_for_object( $post_id );
					if ( false === $deleted ) { $out['error'] = 'trash_orphan_prune_failed'; return $out; }
					$out['pruned'] += $deleted > 0 ? 1 : 0; $out['changed'] += $deleted > 0 ? 1 : 0;
					$out['cursor'] = max( absint( $out['cursor'] ), $post_id );
					continue;
				}
				$key = 'conflict' === $identity_status ? 'operational_identity_conflict' : ( 'parent-missing' === $identity_status ? 'operational_parent_identity_missing' : 'operational_meta_without_identity' );
				$mark_reason = 'conflict' === $identity_status ? 'identity_conflict' : ( 'parent-missing' === $identity_status ? 'parent_identity_missing' : 'missing_identity' );
				if ( self::mark_reconcile_object( $out, $post_id, $mark_reason ) ) { continue; }
				$out['blockers'][ $key ] = absint( isset( $out['blockers'][ $key ] ) ? $out['blockers'][ $key ] : 0 ) + 1;
				return $out;
			}

			$seed = self::insert_reconciled_map_row( $post_id, $type, $identity );
			if ( 'ok' === $seed ) { $out['reconciled']++; $out['changed']++; $out['cursor'] = max( absint( $out['cursor'] ), $post_id ); continue; }
			if ( 'error' === $seed ) { $out['error'] = 'operational_map_reconcile_write_failed'; return $out; }
			$key = 'duplicate' === $seed ? 'operational_identity_duplicate' : 'operational_map_conflict';
			/* Typical cause: WooCommerce "Duplicate product" copied product_guid onto a
			 * second product. The original keeps its Product Map/Custom ownership; the
			 * copy is marked, keeps its legacy rows and is never adopted. */
			if ( self::mark_reconcile_object( $out, $post_id, 'duplicate' === $seed ? 'identity_duplicate' : 'map_conflict' ) ) { continue; }
			$out['blockers'][ $key ] = absint( isset( $out['blockers'][ $key ] ) ? $out['blockers'][ $key ] : 0 ) + 1;
			return $out;
		}
		return $out;
	}

	/**
	 * Mark one unresolvable reconcile object and advance the cursor past it.
	 *
	 * @param array  $out     Reconcile result (by reference).
	 * @param int    $post_id Woo object id.
	 * @param string $reason  Reason code.
	 * @return bool False when marking is unavailable/full (caller keeps the honest blocker).
	 */
	private static function mark_reconcile_object( &$out, $post_id, $reason ) {
		if ( ! class_exists( 'Mobo_Core_Migration_Quarantine' ) || ! Mobo_Core_Migration_Quarantine::mark( $post_id, 'operational', $reason ) ) { return false; }
		$out['marked']++;
		$out['changed']++;
		$out['cursor'] = max( absint( $out['cursor'] ), absint( $post_id ) );
		return true;
	}

	/** @return array{status:string,guid:string,parent_guid:string,last_hash:string,incomplete:bool} */
	private static function resolve_unmapped_identity( $post_id, $type ) {
		$post_id = absint( $post_id );
		$type = sanitize_key( (string) $type );
		$guid_candidates = array(); $parent_candidates = array();
		if ( 'product' === $type ) {
			$v = self::legacy_scalar( $post_id, 'product_guid' ); if ( Mobo_Core_Remote_Identity_Policy::is_valid( $v ) ) { $guid_candidates[] = $v; }
			if ( class_exists( 'Mobo_Core_Product_State_Repository' ) ) {
				$r = self::reconcile_product_state_row( $post_id );
				if ( is_array( $r ) && Mobo_Core_Remote_Identity_Policy::is_valid( (string) ( $r['product_guid'] ?? '' ) ) ) { $guid_candidates[] = (string) $r['product_guid']; }
			}
		} else {
			foreach ( array( 'variant_guid','mobo_variant_guid','_mobo_variant_guid' ) as $key ) { $v=self::legacy_scalar($post_id,$key); if(Mobo_Core_Remote_Identity_Policy::is_valid($v)){$guid_candidates[]=$v;} }
			$parent_id = absint( wp_get_post_parent_id( $post_id ) );
			if ( $parent_id > 0 && 'product' === get_post_type( $parent_id ) ) {
				$v=self::legacy_scalar($parent_id,'product_guid'); if(Mobo_Core_Remote_Identity_Policy::is_valid($v)){$parent_candidates[]=$v;}
				if(class_exists('Mobo_Core_Product_State_Repository')){$pr=self::reconcile_product_state_row($parent_id);if(is_array($pr)&&Mobo_Core_Remote_Identity_Policy::is_valid((string)($pr['product_guid']??''))){$parent_candidates[]=(string)$pr['product_guid'];}}
			}
			$v=self::legacy_scalar($post_id,'product_guid'); if(Mobo_Core_Remote_Identity_Policy::is_valid($v)){$parent_candidates[]=$v;}
			if(class_exists('Mobo_Core_Variant_State_Repository')){$vr=self::reconcile_variant_state_row($post_id);if(is_array($vr)){if(Mobo_Core_Remote_Identity_Policy::is_valid((string)($vr['variant_guid']??''))){$guid_candidates[]=(string)$vr['variant_guid'];}if(Mobo_Core_Remote_Identity_Policy::is_valid((string)($vr['product_guid']??''))){$parent_candidates[]=(string)$vr['product_guid'];}}}
		}
		$guid_candidates = array_values( array_unique( array_filter( array_map( 'strval', $guid_candidates ) ) ) );
		$parent_candidates = array_values( array_unique( array_filter( array_map( 'strval', $parent_candidates ) ) ) );
		if ( count( $guid_candidates ) > 1 || count( $parent_candidates ) > 1 ) { return array('status'=>'conflict','guid'=>'','parent_guid'=>'','last_hash'=>'','incomplete'=>false); }
		if ( empty( $guid_candidates ) ) { return array('status'=>'missing','guid'=>'','parent_guid'=>'','last_hash'=>'','incomplete'=>false); }
		if ( 'product_variation' === $type && empty( $parent_candidates ) ) { return array('status'=>'parent-missing','guid'=>'','parent_guid'=>'','last_hash'=>'','incomplete'=>false); }
		$hash_key = 'product' === $type ? '_mobo_product_source_hash' : '_mobo_variant_source_hash';
		return array(
			'status'=>'ok',
			'guid'=>(string)$guid_candidates[0],
			'parent_guid'=>'product_variation'===$type?(string)$parent_candidates[0]:'',
			'last_hash'=>sanitize_text_field((string)self::legacy_scalar($post_id,$hash_key)),
			'incomplete'=>'1'===(string)self::legacy_scalar($post_id,'mobo_sync_incomplete'),
		);
	}

	/** @return string ok|duplicate|conflict|error */
	private static function insert_reconciled_map_row( $post_id, $type, $identity ) {
		global $wpdb;
		$post_id=absint($post_id);$object_type='product_variation'===$type?Mobo_Core_Product_Map::TYPE_VARIATION:Mobo_Core_Product_Map::TYPE_PRODUCT;
		$connection_id=class_exists('Mobo_Core_Connection_Context')?absint(Mobo_Core_Connection_Context::get_effective_connection_id()):0;if($connection_id<=0){return 'conflict';}
		$guid=sanitize_text_field((string)($identity['guid']??''));$parent=sanitize_text_field((string)($identity['parent_guid']??''));
		if($post_id<=0||!Mobo_Core_Remote_Identity_Policy::is_valid($guid)){return 'conflict';}
		$map=Mobo_Core_Product_Map::table_name();
		if ( 'product' === $type && class_exists( 'Mobo_Core_Product_State_Repository' ) ) {
			$wpdb->last_error=''; $custom_owner=(new Mobo_Core_Product_State_Repository())->get_by_guid($guid);
			if(''!==(string)$wpdb->last_error){return 'error';}
			if(is_array($custom_owner)&&absint($custom_owner['woo_product_id']??0)>0&&absint($custom_owner['woo_product_id']??0)!==$post_id){return 'conflict';}
		}
		if ( 'product_variation' === $type && class_exists( 'Mobo_Core_Variant_State_Repository' ) ) {
			$wpdb->last_error=''; $custom_owner=(new Mobo_Core_Variant_State_Repository())->get_by_guid($guid);
			if(''!==(string)$wpdb->last_error){return 'error';}
			if(is_array($custom_owner)&&absint($custom_owner['woo_variation_id']??0)>0&&absint($custom_owner['woo_variation_id']??0)!==$post_id){return 'conflict';}
		}
		$owner=$wpdb->get_row($wpdb->prepare("SELECT wp_post_id FROM {$map} WHERE connection_id=%d AND remote_guid=%s AND object_type=%s LIMIT 1",$connection_id,$guid,$object_type),ARRAY_A);
		if(''!==(string)$wpdb->last_error){return 'error';}if(is_array($owner)){return absint($owner['wp_post_id']??0)===$post_id?'ok':'conflict';}
		$reverse=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$map} WHERE connection_id=%d AND wp_post_id=%d AND object_type=%s LIMIT 1",$connection_id,$post_id,$object_type));if(''!==(string)$wpdb->last_error){return 'error';}if(null!==$reverse){return 'conflict';}
		$wpdb->last_error='';
		if('product'===$type){$dups=$wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='product_guid' WHERE p.post_type='product' AND p.post_status<>'auto-draft' AND pm.meta_value=%s GROUP BY p.ID ORDER BY p.ID ASC LIMIT 2",$guid));}
		else{$dups=$wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key IN ('variant_guid','mobo_variant_guid','_mobo_variant_guid') WHERE p.post_type='product_variation' AND p.post_status<>'auto-draft' AND pm.meta_value=%s GROUP BY p.ID ORDER BY p.ID ASC LIMIT 2",$guid));}
		if(''!==(string)$wpdb->last_error||!is_array($dups)){return 'error';}if(count($dups)>1){return 'duplicate';}
		$lock_guid='product'===$type?$guid:$parent;if(!Mobo_Core_Remote_Identity_Policy::is_valid($lock_guid)||!class_exists('Mobo_Core_Product_Concurrency')){return 'conflict';}
		$lock=Mobo_Core_Product_Concurrency::acquire_product_lock($lock_guid,0,120);if(false===$lock){return 'error';}
		try{
			/* The page snapshot is only an optimization. Re-read this object's identity
			 * authoritatively under the Product lock before creating ownership. */
			self::invalidate_reconcile_cache_for_object($post_id,$type);
			$again=self::resolve_unmapped_identity($post_id,$type);if('ok'!==($again['status']??'')||!hash_equals($guid,(string)($again['guid']??''))||('product_variation'===$type&&!hash_equals($parent,(string)($again['parent_guid']??'')))){return 'conflict';}
			$owner=$wpdb->get_row($wpdb->prepare("SELECT wp_post_id FROM {$map} WHERE connection_id=%d AND remote_guid=%s AND object_type=%s LIMIT 1",$connection_id,$guid,$object_type),ARRAY_A);if(''!==(string)$wpdb->last_error){return 'error';}if(is_array($owner)){return absint($owner['wp_post_id']??0)===$post_id?'ok':'conflict';}
			$now=current_time('mysql',true);$inserted=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$map} (connection_id,remote_guid,wp_post_id,object_type,parent_remote_guid,last_hash,sync_incomplete,created_at,updated_at) VALUES (%d,%s,%d,%s,%s,%s,%d,%s,%s)",$connection_id,$guid,$post_id,$object_type,$parent,sanitize_text_field((string)($identity['last_hash']??'')),!empty($identity['incomplete'])?1:0,$now,$now));
			if(false===$inserted){return 'error';}$persisted=$wpdb->get_row($wpdb->prepare("SELECT wp_post_id,parent_remote_guid FROM {$map} WHERE connection_id=%d AND remote_guid=%s AND object_type=%s LIMIT 1",$connection_id,$guid,$object_type),ARRAY_A);if(!is_array($persisted)||absint($persisted['wp_post_id']??0)!==$post_id||('product_variation'===$type&&(string)($persisted['parent_remote_guid']??'')!==$parent)){return 'conflict';}
			return 'ok';
		}finally{Mobo_Core_Product_Concurrency::release_product_lock($lock);}
	}

	/** @return int|false */
	private static function prune_operational_meta_for_object( $post_id ) {
		global $wpdb;$post_id=absint($post_id);if($post_id<=0){return 0;}$keys=array_keys(self::managed_keys());$ph=implode(',',array_fill(0,count($keys),'%s'));$args=array_merge(array($post_id),$keys);$deleted=$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ({$ph})",$args));if(false===$deleted){return false;}wp_cache_delete($post_id,'post_meta');return absint($deleted);
	}

	/** Reset the request-local reconcile page snapshot. @return void */
	private static function reset_reconcile_batch_cache() {
		self::$reconcile_legacy_scope=array();self::$reconcile_legacy_first=array();self::$reconcile_product_scope=array();self::$reconcile_product_rows=array();self::$reconcile_variant_scope=array();self::$reconcile_variant_rows=array();
	}

	/** Prime one bounded reconcile page with set-based Legacy + Custom reads. @return bool */
	private static function prime_reconcile_batch_cache( $rows ) {
		global $wpdb;self::reset_reconcile_batch_cache();$ids=array();$product_ids=array();$variant_ids=array();
		foreach((array)$rows as $row){$id=absint($row['ID']??0);if($id<=0){continue;}$ids[$id]=true;$type=sanitize_key((string)($row['post_type']??''));if('product'===$type){$product_ids[$id]=true;}elseif('product_variation'===$type){$variant_ids[$id]=true;$parent=absint($row['post_parent']??0);if($parent>0){$ids[$parent]=true;$product_ids[$parent]=true;}}}
		$scope=array_keys($ids);if(empty($scope)){return true;}if(function_exists('_prime_post_caches')){_prime_post_caches($scope,false,false);}
		foreach($scope as $id){self::$reconcile_legacy_scope[$id]=true;}foreach(array_keys($product_ids) as $id){self::$reconcile_product_scope[$id]=true;}foreach(array_keys($variant_ids) as $id){self::$reconcile_variant_scope[$id]=true;}
		$keys=array('product_guid','variant_guid','mobo_variant_guid','_mobo_variant_guid','_mobo_product_source_hash','_mobo_variant_source_hash','mobo_sync_incomplete');$idph=implode(',',array_fill(0,count($scope),'%d'));$keyph=implode(',',array_fill(0,count($keys),'%s'));$wpdb->last_error='';$legacy=$wpdb->get_results($wpdb->prepare("SELECT post_id,meta_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$idph}) AND meta_key IN ({$keyph}) ORDER BY post_id ASC,meta_id ASC",array_merge($scope,$keys)),ARRAY_A);if(''!==(string)$wpdb->last_error||!is_array($legacy)){self::$reconcile_query_error=true;return false;}foreach($legacy as $row){$id=absint($row['post_id']??0);$key=(string)($row['meta_key']??'');if($id>0&&''!==$key&&!array_key_exists($key,self::$reconcile_legacy_first[$id]??array())){self::$reconcile_legacy_first[$id][$key]=$row['meta_value']??'';}}
		$connection_id=class_exists('Mobo_Core_Connection_Context')?absint(Mobo_Core_Connection_Context::get_effective_connection_id()):0;if($connection_id<=0){self::$reconcile_query_error=true;return false;}
		if(!empty($product_ids)){$pids=array_keys($product_ids);$ph=implode(',',array_fill(0,count($pids),'%d'));$table=Mobo_Core_Storage_Schema::table_name('products');$wpdb->last_error='';$custom=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE connection_id=%d AND woo_product_id IN ({$ph})",array_merge(array($connection_id),$pids)),ARRAY_A);if(''!==(string)$wpdb->last_error||!is_array($custom)){self::$reconcile_query_error=true;return false;}foreach($custom as $row){$id=absint($row['woo_product_id']??0);if($id>0){self::$reconcile_product_rows[$id]=$row;}}}
		if(!empty($variant_ids)){$vids=array_keys($variant_ids);$ph=implode(',',array_fill(0,count($vids),'%d'));$table=Mobo_Core_Storage_Schema::table_name('variants');$wpdb->last_error='';$custom=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE connection_id=%d AND woo_variation_id IN ({$ph})",array_merge(array($connection_id),$vids)),ARRAY_A);if(''!==(string)$wpdb->last_error||!is_array($custom)){self::$reconcile_query_error=true;return false;}foreach($custom as $row){$id=absint($row['woo_variation_id']??0);if($id>0){self::$reconcile_variant_rows[$id]=$row;}}}
		return true;
	}

	/** @return array|null */
	private static function reconcile_product_state_row( $post_id ) {global $wpdb;$post_id=absint($post_id);if(isset(self::$reconcile_product_scope[$post_id])){return self::$reconcile_product_rows[$post_id]??null;}$wpdb->last_error='';$row=(new Mobo_Core_Product_State_Repository())->get_by_woo_product_id($post_id);if(''!==(string)$wpdb->last_error){self::$reconcile_query_error=true;}return $row;}
	/** @return array|null */
	private static function reconcile_variant_state_row( $post_id ) {global $wpdb;$post_id=absint($post_id);if(isset(self::$reconcile_variant_scope[$post_id])){return self::$reconcile_variant_rows[$post_id]??null;}$wpdb->last_error='';$row=(new Mobo_Core_Variant_State_Repository())->get_by_woo_variation_id($post_id);if(''!==(string)$wpdb->last_error){self::$reconcile_query_error=true;}return $row;}

	/** Drop cached evidence before the under-lock ownership recheck. @return void */
	private static function invalidate_reconcile_cache_for_object( $post_id, $type ) {$post_id=absint($post_id);unset(self::$reconcile_legacy_scope[$post_id],self::$reconcile_legacy_first[$post_id],self::$reconcile_product_scope[$post_id],self::$reconcile_product_rows[$post_id],self::$reconcile_variant_scope[$post_id],self::$reconcile_variant_rows[$post_id]);if('product_variation'===sanitize_key((string)$type)){$parent=absint(wp_get_post_parent_id($post_id));if($parent>0){unset(self::$reconcile_legacy_scope[$parent],self::$reconcile_legacy_first[$parent],self::$reconcile_product_scope[$parent],self::$reconcile_product_rows[$parent]);}}}

	/** @return string */
	private static function legacy_scalar( $post_id, $key ) {
		global $wpdb;$post_id=absint($post_id);$key=(string)$key;if(isset(self::$reconcile_legacy_scope[$post_id])){$v=self::$reconcile_legacy_first[$post_id][$key]??'';return is_scalar($v)?sanitize_text_field((string)maybe_unserialize($v)):'';}$wpdb->last_error='';$v=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id ASC LIMIT 1",$post_id,$key));if(''!==(string)$wpdb->last_error){self::$reconcile_query_error=true;return '';}return is_scalar($v)?sanitize_text_field((string)maybe_unserialize($v)):'';
	}

	/**
	 * Bounded normalization before the global proof: collapse exact duplicate
	 * rows, mark objects whose duplicates disagree, objects with duplicate Product
	 * Map bindings and mapped live objects that still lack Custom coverage.
	 *
	 * @return array{progress:bool,deduped:int,rowsRemoved:int,marked:int,blockers:array}
	 */
	private static function normalize_global_blockers() {
		global $wpdb;
		$out = array( 'progress' => false, 'deduped' => 0, 'rowsRemoved' => 0, 'marked' => 0, 'blockers' => array() );
		if ( ! class_exists( 'Mobo_Core_Migration_Quarantine' ) ) { return $out; }
		$limit = class_exists( 'Mobo_Core_Migration' ) && Mobo_Core_Migration::is_accelerated_context() ? 200 : 50;
		$dedupe = Mobo_Core_Migration_Quarantine::dedupe_exact_duplicate_meta( array_keys( self::managed_keys() ), 'operational', $limit );
		if ( '' !== $dedupe['error'] ) { $out['blockers'] = array( 'duplicate_normalization_error' => 1 ); return $out; }
		if ( ! empty( $dedupe['full'] ) ) { $out['blockers'] = array( 'quarantine_capacity_reached' => 1 ); return $out; }
		$out['deduped'] = $dedupe['deduped']; $out['rowsRemoved'] = $dedupe['rowsRemoved']; $out['marked'] = $dedupe['marked'];
		if ( $dedupe['deduped'] > 0 || $dedupe['marked'] > 0 || ! empty( $dedupe['more'] ) ) { $out['progress'] = true; return $out; }

		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $connection_id <= 0 ) { return $out; }
		$map = Mobo_Core_Product_Map::table_name();
		$excl_map = Mobo_Core_Migration_Quarantine::marked_exclusion_sql( 'wp_post_id' );
		$wpdb->last_error = '';
		$dup_objects = $wpdb->get_col( $wpdb->prepare( "SELECT wp_post_id FROM {$map} WHERE connection_id=%d AND wp_post_id>0 AND object_type IN ('product','variation'){$excl_map} GROUP BY wp_post_id,object_type HAVING COUNT(*)>1 ORDER BY wp_post_id ASC LIMIT %d", $connection_id, $limit ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $dup_objects ) ) { $out['blockers'] = array( 'map_duplicate_query_error' => 1 ); return $out; }
		foreach ( $dup_objects as $post_id ) {
			if ( ! Mobo_Core_Migration_Quarantine::mark( absint( $post_id ), 'operational', 'duplicate_map_object' ) ) { $out['blockers'] = array( 'quarantine_capacity_reached' => 1 ); return $out; }
			$out['marked']++; $out['progress'] = true;
		}
		if ( $out['progress'] ) { return $out; }

		$pt = Mobo_Core_Storage_Schema::table_name( 'products' ); $vt = Mobo_Core_Storage_Schema::table_name( 'variants' );
		$excl_m = Mobo_Core_Migration_Quarantine::marked_exclusion_sql( 'm.wp_post_id' );
		$wpdb->last_error = '';
		$uncovered = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.wp_post_id FROM {$map} m INNER JOIN {$wpdb->posts} p ON p.ID=m.wp_post_id LEFT JOIN {$pt} cp ON cp.connection_id=m.connection_id AND m.object_type='product' AND cp.woo_product_id=m.wp_post_id AND cp.product_guid=m.remote_guid LEFT JOIN {$vt} cv ON cv.connection_id=m.connection_id AND m.object_type='variation' AND cv.woo_variation_id=m.wp_post_id AND cv.variant_guid=m.remote_guid WHERE m.connection_id=%d AND ((m.object_type='product' AND p.post_type='product' AND cp.id IS NULL) OR (m.object_type='variation' AND p.post_type='product_variation' AND cv.id IS NULL)){$excl_m} LIMIT %d", $connection_id, $limit ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $uncovered ) ) { $out['blockers'] = array( 'coverage_query_error' => 1 ); return $out; }
		foreach ( $uncovered as $post_id ) {
			if ( ! Mobo_Core_Migration_Quarantine::mark( absint( $post_id ), 'operational', 'custom_coverage_missing' ) ) { $out['blockers'] = array( 'quarantine_capacity_reached' => 1 ); return $out; }
			$out['marked']++; $out['progress'] = true;
		}
		return $out;
	}

	/** @return array<string,int> */
	private static function global_blockers() {
		global $wpdb;
		$out=array(); $keys=array_keys(self::managed_keys()); $ph=implode(',',array_fill(0,count($keys),'%s'));
		$connection_id=class_exists('Mobo_Core_Connection_Context')?absint(Mobo_Core_Connection_Context::get_effective_connection_id()):0;
		if($connection_id<=0){return array('connection_owner_missing'=>1);}
		/* Marked objects and objects owned by another Connection are outside this proof. */
		$excl=class_exists('Mobo_Core_Migration_Quarantine')?Mobo_Core_Migration_Quarantine::exclusion_sql('pm.post_id'):'';
		$excl_m=class_exists('Mobo_Core_Migration_Quarantine')?Mobo_Core_Migration_Quarantine::marked_exclusion_sql('m.wp_post_id'):'';
		$excl_w=class_exists('Mobo_Core_Migration_Quarantine')?Mobo_Core_Migration_Quarantine::marked_exclusion_sql('wp_post_id'):'';
		$wpdb->last_error='';
		$dup=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND pm.meta_key IN ({$ph}){$excl} GROUP BY pm.post_id,pm.meta_key HAVING COUNT(*)>1 LIMIT 1",$keys));
		if(''!==(string)$wpdb->last_error){return array('duplicate_query_error'=>1);} if(null!==$dup){$out['duplicate_operational_meta']=1;}
		$map=Mobo_Core_Product_Map::table_name();
		$unmapped_args=array_merge(array($connection_id),$keys);$wpdb->last_error='';
		$unmapped=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id LEFT JOIN {$map} m ON m.connection_id=%d AND m.wp_post_id=pm.post_id AND m.object_type=IF(p.post_type='product','product','variation') WHERE p.post_type IN ('product','product_variation') AND pm.meta_key IN ({$ph}) AND m.id IS NULL{$excl} LIMIT 1",$unmapped_args));
		if(''!==(string)$wpdb->last_error){$out['unmapped_query_error']=1;}elseif(null!==$unmapped){$out['operational_meta_without_map']=1;}
		$wpdb->last_error='';$mapdups=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$map} WHERE connection_id=%d AND wp_post_id>0 AND object_type IN ('product','variation'){$excl_w} GROUP BY wp_post_id,object_type HAVING COUNT(*)>1 LIMIT 1",$connection_id));
		if(''!==(string)$wpdb->last_error){$out['map_duplicate_query_error']=1;}elseif(null!==$mapdups){$out['duplicate_map_object']=1;}
		$pt=Mobo_Core_Storage_Schema::table_name('products'); $vt=Mobo_Core_Storage_Schema::table_name('variants');$wpdb->last_error='';
		$coverage=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$map} m INNER JOIN {$wpdb->posts} p ON p.ID=m.wp_post_id LEFT JOIN {$pt} cp ON cp.connection_id=m.connection_id AND m.object_type='product' AND cp.woo_product_id=m.wp_post_id AND cp.product_guid=m.remote_guid LEFT JOIN {$vt} cv ON cv.connection_id=m.connection_id AND m.object_type='variation' AND cv.woo_variation_id=m.wp_post_id AND cv.variant_guid=m.remote_guid WHERE m.connection_id=%d AND ((m.object_type='product' AND p.post_type='product' AND cp.id IS NULL) OR (m.object_type='variation' AND p.post_type='product_variation' AND cv.id IS NULL)){$excl_m} LIMIT 1",$connection_id));
		if(''!==(string)$wpdb->last_error){$out['coverage_query_error']=1;}elseif(null!==$coverage){$out['custom_coverage_missing']=1;}
		return $out;
	}

	/** @return bool */
	private static function activate_fence() {
		$state=self::load_state();
		if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		/* Publish and verify the minimal fail-closed marker first. If the following
		 * runtime-state write fails, the marker still prevents Legacy resurrection
		 * and the next migration step reconstructs the rich state with a fresh grace. */
		update_option(self::FENCE_OPTION,self::FENCE_VERSION,false);
		if(self::FENCE_VERSION!==absint(get_option(self::FENCE_OPTION,0))){
			self::record_state_error($state,'fence_marker_write_failed','fence-error');
			return false;
		}
		$state['fenceActive']=true;$state['fenceVersion']=self::FENCE_VERSION;$state['fenceActivatedAt']=time();$state['status']='fenced-grace';$state['blockers']=array();$state['lastError']='';
		if(!self::persist_state($state,false)){self::$fenced=true;self::enforce_terminal_options();return false;}
		self::$fenced=true; self::enforce_terminal_options(); return true;
	}

	/** @return void */
	private static function ensure_pre_fence_mode() {
		update_option( Mobo_Core_Storage_Mode::OPTION, Mobo_Core_Storage_Mode::DUAL_WRITE, false );
		update_option( Mobo_Core_Storage_Postmeta_Write_Suppression::OPTION, array(), false );
		Mobo_Core_Storage_Mode::reset_request_cache();
	}

	/** @return void */
	private static function enforce_terminal_options() {
		update_option( Mobo_Core_Storage_Mode::OPTION, Mobo_Core_Storage_Mode::CUSTOM_ONLY, false );
		update_option( Mobo_Core_Storage_Postmeta_Write_Suppression::OPTION, array_keys( self::managed_keys() ), false );
		Mobo_Core_Storage_Mode::reset_request_cache();
		Mobo_Core_Storage_Postmeta_Write_Suppression::reset_request_state();
		if(class_exists('Mobo_Core_Storage_Primary_State_Read',false)&&method_exists('Mobo_Core_Storage_Primary_State_Read','reset_request_state')){Mobo_Core_Storage_Primary_State_Read::reset_request_state();}
	}

	/** Cursor-based physical cleanup. Cursor advances only after one atomic DELETE succeeds. @return array|false */
	private static function cleanup_one_batch( $cursor ) {
		global $wpdb;$cursor=absint($cursor);$keys=array_keys(self::managed_keys());$ph=implode(',',array_fill(0,count($keys),'%s'));$args=array_merge(array($cursor),$keys,array(self::cleanup_batch_size()));
		$excl=self::retirement_exclusion_sql();$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare("SELECT pm.meta_id,pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_id>%d AND p.post_type IN ('product','product_variation') AND pm.meta_key IN ({$ph}){$excl} ORDER BY pm.meta_id ASC LIMIT %d",$args),ARRAY_A);if(''!==(string)$wpdb->last_error){return false;}if(empty($rows)){return array('deleted'=>0,'cursor'=>$cursor);}
		$ids=array();$posts=array();$next=$cursor;foreach($rows as $row){$id=absint($row['meta_id']??0);if($id>0){$ids[]=$id;$next=max($next,$id);}$post_id=absint($row['post_id']??0);if($post_id>0){$posts[$post_id]=true;}}$ids=array_values(array_filter($ids));if(empty($ids)){return false;}$idph=implode(',',array_fill(0,count($ids),'%d'));$wpdb->last_error='';$deleted=$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ({$idph})",$ids));if(false===$deleted||''!==(string)$wpdb->last_error){return false;}foreach(array_keys($posts) as $post_id){wp_cache_delete($post_id,'post_meta');}return array('deleted'=>absint($deleted),'cursor'=>$next);
	}

	/** Fast boolean residue probe used by prerequisite/complete gates. @return bool|null */
	private static function has_physical_residue() {
		global $wpdb;$keys=array_keys(self::managed_keys());$ph=implode(',',array_fill(0,count($keys),'%s'));$excl=self::retirement_exclusion_sql();$wpdb->last_error='';$hit=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND pm.meta_key IN ({$ph}){$excl} LIMIT 1",$keys));if(''!==(string)$wpdb->last_error){return null;}return null!==$hit;
	}

	/** Durable terminal prerequisite with request-local zero-residue caching. @return bool */
	public static function is_physically_retired() {
		if(!self::is_fenced()){return false;}$state=self::load_state();if(empty($state['complete'])){return false;}if(self::$zero_residue_verified){return true;}$has=self::has_physical_residue();if(null===$has||$has){return false;}self::$zero_residue_verified=true;return true;
	}

	/** @return int|null */
	public static function physical_residue_count() {
		global $wpdb;$keys=array_keys(self::managed_keys());$ph=implode(',',array_fill(0,count($keys),'%s'));$excl=self::retirement_exclusion_sql();$wpdb->last_error='';$count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND pm.meta_key IN ({$ph}){$excl}",$keys));if(''!==(string)$wpdb->last_error){return null;}return absint($count);
	}

	/**
	 * Cleanup/residue scope exclusion. Marked objects keep their legacy rows
	 * physically (they were never proven) and rows of objects owned by another
	 * Connection are not the Default Connection's to delete. "Physically retired"
	 * therefore means: zero residue for every object this worker proved. The
	 * marked count is reported separately and honestly.
	 *
	 * @return string
	 */
	private static function retirement_exclusion_sql() {
		return class_exists( 'Mobo_Core_Migration_Quarantine' ) ? Mobo_Core_Migration_Quarantine::exclusion_sql( 'pm.post_id' ) : '';
	}

	/**
	 * Post-fence re-probe of one marked object (called only after the DB version
	 * is stamped, under the migration lock). Operational values are sync
	 * checkpoints; once the object has its own Custom state row that row is the
	 * sole authority and the shadowed legacy rows can be removed.
	 *
	 * @param int $post_id Woo object id.
	 * @return string clear|keep|error
	 */
	public static function reprobe_quarantined_object( $post_id ) {
		global $wpdb;
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! self::is_fenced() ) { return 'keep'; }
		$keys = array_keys( self::managed_keys() ); $ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$wpdb->last_error = '';
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ({$ph})", array_merge( array( $post_id ), $keys ) ) );
		if ( '' !== (string) $wpdb->last_error ) { return 'error'; }
		if ( absint( $count ) <= 0 ) { return 'clear'; }
		$type = get_post_type( $post_id );
		if ( ! in_array( $type, array( 'product', 'product_variation' ), true ) ) { return 'clear'; }
		$wpdb->last_error = '';
		$row = 'product' === $type
			? ( new Mobo_Core_Product_State_Repository() )->get_by_woo_product_id( $post_id )
			: ( new Mobo_Core_Variant_State_Repository() )->get_by_woo_variation_id( $post_id );
		if ( '' !== (string) $wpdb->last_error ) { return 'error'; }
		if ( ! is_array( $row ) ) { return 'keep'; }
		return false === self::prune_operational_meta_for_object( $post_id ) ? 'error' : 'clear';
	}

	/** @return array */
	private static function load_state() {
		global $wpdb;
		$base=array('status'=>'pending','proofCursor'=>0,'proofComplete'=>false,'reconcileCursor'=>0,'cleanupCursor'=>0,'backfillStatus'=>'','backfillCursor'=>0,'backfillMirrored'=>0,'proofNoopVerified'=>0,'proofMirrorWrites'=>0,'fenceActive'=>false,'fenceVersion'=>0,'fenceActivatedAt'=>0,'cleanedRows'=>0,'physicalResidue'=>null,'complete'=>false,'lastError'=>'','blockers'=>array(),'stateRevision'=>0,'__persistedStateJson'=>null,'__stateLoadError'=>false);
		if(!class_exists('Mobo_Core_Storage_Schema')||!Mobo_Core_Storage_Schema::is_ready()){return $base;}
		$table=Mobo_Core_Storage_Schema::table_name('runtime');$wpdb->last_error='';
		$row=$wpdb->get_row($wpdb->prepare("SELECT state_json FROM {$table} WHERE connection_id=0 AND worker_name=%s LIMIT 1",self::WORKER_NAME),ARRAY_A);
		if(''!==trim((string)$wpdb->last_error)){$base['__stateLoadError']=true;return $base;}
		if(!is_array($row)){return $base;}
		$json=(string)($row['state_json']??'');$base['__persistedStateJson']=$json;
		$decoded=json_decode($json,true);if(!is_array($decoded)){$base['__stateLoadError']=true;return $base;}
		$out=array_merge($base,$decoded);$out['__persistedStateJson']=$json;$out['__stateLoadError']=false;return $out;
	}

	/** CAS-persist worker state so an expired/lost lock owner cannot overwrite newer progress. @return bool */
	private static function persist_state( $state, $finished = false ) {
		if ( class_exists( 'Mobo_Core_Migration' ) && ! Mobo_Core_Migration::accelerated_slice_lock_is_valid() ) { return false; }
		if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		global $wpdb;if(!class_exists('Mobo_Core_Storage_Schema')||!Mobo_Core_Storage_Schema::is_ready()){return false;}
		$table=Mobo_Core_Storage_Schema::table_name('runtime');$now=current_time('mysql',true);$expected_raw=$state['__persistedStateJson']??null;
		unset($state['__persistedStateJson'],$state['__stateLoadError']);$state['stateRevision']=absint($state['stateRevision']??0)+1;
		$json=wp_json_encode($state);if(!is_string($json)){return false;}$finished_at=$finished?$now:'';$cursor=(string)absint($state['proofCursor']??0);$error=sanitize_key((string)($state['lastError']??''));
		if(null===$expected_raw){
			$sql="INSERT IGNORE INTO {$table} (connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,last_error_code,state_json,created_at,updated_at) VALUES (0,%s,%s,NULLIF(%s,''),%s,0,'closed',%s,%s,%s,%s)";
			return 1===absint($wpdb->query($wpdb->prepare($sql,self::WORKER_NAME,$now,$finished_at,$cursor,$error,$json,$now,$now)));
		}
		$sql="UPDATE {$table} SET last_started_at=%s,last_finished_at=IF(%s='',last_finished_at,%s),last_cursor=%s,last_error_code=%s,state_json=%s,updated_at=%s WHERE connection_id=0 AND worker_name=%s AND BINARY state_json=BINARY %s";
		return 1===absint($wpdb->query($wpdb->prepare($sql,$now,$finished_at,$finished_at,$cursor,$error,$json,$now,self::WORKER_NAME,(string)$expected_raw)));
	}

	/** @return void */
	private static function record_state_error( $state, $error, $status ) {$state['status']=sanitize_key((string)$status);$state['lastError']=sanitize_key((string)$error);self::persist_state($state);}
}
