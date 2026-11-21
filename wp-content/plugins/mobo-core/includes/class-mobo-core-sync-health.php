<?php
/**
 * Durable per-product synchronization health bookkeeping.
 *
 * This class is intentionally observational. It never fetches Portal snapshots,
 * mutates WooCommerce desired state or schedules recovery work.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Sync_Health {

	/** Resolve the active/default Connection ownership boundary. */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** @var bool|null Request-local table existence cache. */
	private static $table_exists_cache = null;

	/**
	 * Health table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mobo_sync_health';
	}

	/**
	 * Create/update health schema.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = Mobo_Core_Database_Charset::schema_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_guid varchar(191) NOT NULL,
			wp_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			portal_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			portal_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			portal_version varchar(64) NOT NULL DEFAULT '',
			portal_hash varchar(128) NOT NULL DEFAULT '',
			last_successful_sync_time datetime NULL,
			sync_status varchar(24) NOT NULL DEFAULT 'behind',
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_guid (connection_id,product_guid(180)),
			KEY wp_product_id (connection_id,wp_product_id),
			KEY portal_product_id (connection_id,portal_product_id),
			KEY sync_status (connection_id,sync_status),
			KEY portal_revision (connection_id,portal_revision)
		) {$charset_collate};";

		dbDelta( $sql );
		self::$table_exists_cache = null;
	}

	/**
	 * Whether the health table currently exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		if ( null !== self::$table_exists_cache ) {
			return (bool) self::$table_exists_cache;
		}
		$table = self::table_name();
		self::$table_exists_cache = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return (bool) self::$table_exists_cache;
	}

	/**
	 * Mark a product as waiting for completion of its current desired-state sync.
	 */
	public static function mark_behind( $guid, $wp_id = 0, $revision = 0, $hash = '', $portal_id = 0, $version = '' ) {
		return self::upsert_health( $guid, $wp_id, 'behind', $revision, $hash, '', $portal_id, false, $version );
	}

	/**
	 * Compatibility status retained for old health rows/tools.
	 */
	public static function mark_repairing( $guid, $wp_id = 0, $revision = 0, $hash = '', $portal_id = 0, $version = '' ) {
		return self::upsert_health( $guid, $wp_id, 'repairing', $revision, $hash, '', $portal_id, false, $version );
	}

	/**
	 * Mark a product as fully converged.
	 */
	public static function mark_synced( $guid, $wp_id = 0, $revision = 0, $hash = '', $portal_id = 0, $version = '', $record_sync_time = true ) {
		/* $record_sync_time=false: local proof says converged (migration self-heal) but
		 * no sync with the Source happened now, so "last successful sync" stays as is. */
		return self::upsert_health( $guid, $wp_id, 'synced', $revision, $hash, '', $portal_id, true, $version, (bool) $record_sync_time );
	}

	/**
	 * Mark a product sync as failed.
	 */
	public static function mark_failed( $guid, $wp_id = 0, $error = '', $revision = 0, $portal_id = 0, $version = '' ) {
		return self::upsert_health( $guid, $wp_id, 'failed', $revision, '', $error, $portal_id, false, $version );
	}


	/**
	 * Persist the specific terminal UpdateVariant evidence that operational Health
	 * currently reads from the sync-event queue before retention deletes that row.
	 *
	 * This intentionally reuses the existing Health ordering fields instead of
	 * introducing another evidence table. upsert_health() already rejects stale
	 * revisions/versions, so an old retained event cannot regress newer state.
	 *
	 * @param array  $payload Event payload.
	 * @param string $event_version Durable event ordering watermark.
	 * @param string $error Stable terminal error.
	 * @param string $entity_type Durable event entity type fallback.
	 * @param string $entity_guid Durable event entity GUID fallback.
	 * @return bool True when the event is either durably preserved or irrelevant.
	 */
	public static function preserve_terminal_update_variant_evidence( $payload, $event_version, $error, $entity_type = '', $entity_guid = '' ) {
		$payload       = is_array( $payload ) ? $payload : array();
		$event_version = sanitize_text_field( trim( (string) $event_version ) );
		$error         = sanitize_textarea_field( (string) $error );
		$entity_type   = sanitize_key( (string) $entity_type );
		$entity_guid   = sanitize_text_field( (string) $entity_guid );

		if ( 0 !== strpos( $error, 'Incomplete Mobo variant attributes.' ) ) {
			return true;
		}

		$guid = self::extract_product_guid_recursive( $payload );
		if ( '' === $guid && 'product' === $entity_type && preg_match( '/^[0-9a-f-]{32,36}$/i', $entity_guid ) ) {
			$guid = $entity_guid;
		}
		if ( '' === $guid ) {
			/* Operational Health could not consume this event either; retention may
			 * remove it without losing a current ownership proof. */
			return true;
		}

		$portal_id = self::extract_portal_product_id_recursive( $payload );
		$revision  = self::extract_revision_recursive( $payload );
		$version   = '' !== $event_version ? $event_version : self::extract_version_recursive( $payload );
		if ( '' === $version && $revision > 0 ) {
			$version = (string) $revision;
		}
		$wp_id = self::find_local_product_id( $guid, $portal_id );

		$marked = self::mark_failed( $guid, $wp_id, $error, $revision, $portal_id, $version );
		if ( ! $marked ) {
			return false;
		}

		/* mark_failed() deliberately keeps an equal-version synced Health row sticky
		 * unless the canonical product is explicitly incomplete. The live event queue
		 * used to overlay terminal ownership even in that case. Before retention removes
		 * the row, preserve just the terminal note on that exact Health boundary without
		 * regressing sync_status. A newer Health boundary makes this old event irrelevant
		 * and therefore safe to delete. */
		return self::ensure_terminal_evidence_note( $guid, $error, $revision, $version );
	}

	/**
	 * Ensure a current terminal event remains visible after queue retention.
	 *
	 * @param string $guid Product GUID.
	 * @param string $error Stable terminal error.
	 * @param int    $revision Event revision.
	 * @param string $version Event version.
	 * @return bool True when evidence is durable or the event is already stale.
	 */
	private static function ensure_terminal_evidence_note( $guid, $error, $revision, $version ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) {
			return false;
		}
		$table = self::table_name();
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, portal_revision, portal_version, last_error FROM {$table} WHERE connection_id = %d AND product_guid = %s LIMIT 1",
				$connection_id,
				$guid
			),
			ARRAY_A
		);
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $row ) ) {
			return false;
		}

		$current_version = sanitize_text_field( (string) ( isset( $row['portal_version'] ) ? $row['portal_version'] : '' ) );
		$current_revision = absint( isset( $row['portal_revision'] ) ? $row['portal_revision'] : 0 );
		$version = sanitize_text_field( trim( (string) $version ) );
		$revision = absint( $revision );

		if ( '' !== $version && '' !== $current_version ) {
			$cmp = self::compare_versions( $version, $current_version );
			if ( -1 === $cmp ) {
				return true;
			}
			if ( 1 === $cmp ) {
				return false;
			}
		} elseif ( $revision > 0 && $current_revision > 0 ) {
			if ( $revision < $current_revision ) {
				return true;
			}
			if ( $revision > $current_revision ) {
				return false;
			}
		}

		$stored_error = (string) ( isset( $row['last_error'] ) ? $row['last_error'] : '' );
		if ( 0 === strpos( $stored_error, 'Incomplete Mobo variant attributes.' ) ) {
			return true;
		}

		$data = array( 'last_error' => sanitize_textarea_field( (string) $error ) );
		$formats = array( '%s' );
		/* An equal-revision sticky row can legitimately predate versioned Health.
		 * Seed the exact event version only while the current field is still empty. */
		if ( '' === $current_version && '' !== $version ) {
			$data['portal_version'] = $version;
			$formats[] = '%s';
		}
		$where = array(
			'id'              => absint( $row['id'] ),
			'connection_id'   => $connection_id,
			'portal_revision' => $current_revision,
			'portal_version'  => $current_version,
		);
		$written = $wpdb->update( $table, $data, $where, $formats, array( '%d', '%d', '%d', '%s' ) );
		if ( false === $written ) {
			return false;
		}

		$verify = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT portal_revision, portal_version, last_error FROM {$table} WHERE connection_id = %d AND product_guid = %s LIMIT 1",
				$connection_id,
				$guid
			),
			ARRAY_A
		);
		if ( ! is_array( $verify ) ) {
			return false;
		}
		$verify_error = (string) ( isset( $verify['last_error'] ) ? $verify['last_error'] : '' );
		$verify_version = sanitize_text_field( (string) ( isset( $verify['portal_version'] ) ? $verify['portal_version'] : '' ) );
		if ( 0 !== strpos( $verify_error, 'Incomplete Mobo variant attributes.' ) ) {
			return false;
		}
		if ( '' !== $version && '' !== $verify_version ) {
			$cmp = self::compare_versions( $verify_version, $version );
			if ( null !== $cmp && $cmp < 0 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Record a webhook processing result.
	 *
	 * Successful partial results remain behind. Terminal UpdateVariant results are
	 * synced; terminal ProductUpdated results are synced for simple products but
	 * remain behind for variable products until their variant event converges.
	 * Failures are failed.
	 *
	 * @param string $event Webhook event.
	 * @param array  $payload Webhook payload.
	 * @param array  $result Processing result.
	 * @return void
	 */
	public static function record_webhook_result( $event, $payload, $result ) {
		$result  = is_array( $result ) ? $result : array();
		$payload = is_array( $payload ) ? $payload : array();
		$data    = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();

		$guid = isset( $data['productGuid'] ) ? sanitize_text_field( (string) $data['productGuid'] ) : '';
		if ( '' === $guid ) {
			$guid = self::extract_product_guid_recursive( $payload );
		}
		if ( '' === $guid ) {
			return;
		}

		/* A fully stale event was intentionally not applied. It must not mutate health,
		 * especially when its ordering watermark is a non-numeric timestamp that cannot
		 * be represented in portal_revision. Mixed delta pages use an integer count here,
		 * not boolean true, and still report the current applied work normally. */
		if ( ( isset( $data['staleSkipped'] ) && true === $data['staleSkipped'] )
			|| ! empty( $data['healthSkipped'] )
			|| ! empty( $data['skippedBecause'] ) ) {
			return;
		}

		$portal_id = isset( $data['portalProductId'] ) ? absint( $data['portalProductId'] ) : self::extract_portal_product_id_recursive( $payload );
		$revision  = isset( $data['sourceRevision'] ) ? absint( $data['sourceRevision'] ) : self::extract_revision_recursive( $payload );
		$version   = isset( $data['sourceVersion'] ) ? sanitize_text_field( (string) $data['sourceVersion'] ) : self::extract_version_recursive( $payload );
		$hash      = isset( $data['sourceHash'] ) ? sanitize_text_field( (string) $data['sourceHash'] ) : '';
		$wp_id     = isset( $data['productId'] ) ? absint( $data['productId'] ) : 0;
		if ( $wp_id <= 0 ) {
			$wp_id = self::find_local_product_id( $guid, $portal_id );
		}

		if ( empty( $result['success'] ) ) {
			self::mark_failed( $guid, $wp_id, isset( $result['message'] ) ? $result['message'] : 'Webhook sync failed.', $revision, $portal_id, $version );
			return;
		}

		$terminal = ! empty( $data['deleteFile'] );
		if ( ! $terminal ) {
			self::mark_behind( $guid, $wp_id, $revision, $hash, $portal_id, $version );
			return;
		}

		$event_key = strtolower( sanitize_text_field( (string) $event ) );
		if ( 'productupdated' === $event_key ) {
			$product = $wp_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $wp_id ) : false;
			if ( $product instanceof WC_Product_Variable ) {
				self::mark_behind( $guid, $wp_id, $revision, $hash, $portal_id, $version );
				return;
			}
		}

		self::mark_synced( $guid, $wp_id, $revision, $hash, $portal_id, $version );
	}

	/** Extract a numeric revision from nested payload wrappers. */
	private static function extract_revision_recursive( $data ) {
	return Mobo_Core_Ordering_Policy::extract_numeric_revision_recursive( $data );
}

	/** Extract a raw numeric/ISO ordering watermark from nested payload wrappers. */
	private static function extract_version_recursive( $data ) {
	return Mobo_Core_Ordering_Policy::extract_version_recursive( $data );
}

	/** Compare numeric or ISO-date ordering watermarks; null means incomparable. */
	private static function compare_versions( $left, $right ) {
	return Mobo_Core_Ordering_Policy::compare_versions( $left, $right );
}

	/** Extract Portal numeric product id without confusing the remote GUID. */
	private static function extract_portal_product_id_recursive( $data ) {
		if ( ! is_array( $data ) ) {
			return 0;
		}
		foreach ( array( 'portalProductId', 'portal_product_id', 'productNumericId' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
				$id = absint( $data[ $key ] );
				if ( $id > 0 ) {
					return $id;
				}
			}
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$found = self::extract_portal_product_id_recursive( $value );
				if ( $found > 0 ) {
					return $found;
				}
			}
		}
		return 0;
	}

	/**
	 * Compact health status for Admin/Portal diagnostics.
	 *
	 * @return array
	 */
	public static function get_dashboard_status() {
		global $wpdb;
		$counts = array( 'synced' => 0, 'behind' => 0, 'repairing' => 0, 'failed' => 0 );
		if ( self::table_exists() ) {
			$table = self::table_name();
			$connection_id = self::connection_id();
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT sync_status, COUNT(*) AS total FROM {$table} WHERE connection_id = %d GROUP BY sync_status", $connection_id ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$key = sanitize_key( (string) $row['sync_status'] );
				if ( isset( $counts[ $key ] ) ) {
					$counts[ $key ] = absint( $row['total'] );
				}
			}
		}
		$pending_sync = absint( $counts['behind'] ) + absint( $counts['repairing'] ) + absint( $counts['failed'] );
		return array(
			'counts'        => $counts,
			'pendingSync'   => $pending_sync,
			'pendingRepair' => $pending_sync, // Backward-compatible Portal field.
			'state'         => array( 'status' => 'observational', 'mode' => 'sync-health', 'phase' => '' ),
			'lastCheckAt'   => 0,
			'lastSuccessAt' => 0,
			'lastDeepAt'    => 0,
			'nextCheckAt'   => 0,
			'endpointSupport' => 'webhook-sync',
			'lastResult'    => array( 'success' => true, 'status' => 'observational-only' ),
		);
	}


	/**
	 * Return operational Sync Health for the current canonical Mobo product set.
	 *
	 * Raw Sync Health rows are historical observations and are intentionally retained.
	 * They are NOT a valid denominator for current storefront health. Operational
	 * counts are therefore derived from the current Product Map + live WooCommerce
	 * product and then reconciled with Health/Event ordering evidence.
	 *
	 * This method is read-only: it never rewrites Health, Product Map, events or
	 * product metadata and never schedules Repair/Recovery work.
	 *
	 * @param int  $detail_limit Maximum rows returned per actionable category.
	 * @param bool $deep_structure Whether to deep-scan every live product/variation structure.
	 *                             Admin/Portal callers should pass false: suspicious rows
	 *                             remain deeply checked while clean synced rows avoid
	 *                             a full-catalog WooCommerce object walk.
	 * @return array
	 */
	public static function get_operational_dashboard_status( $detail_limit = 15, $deep_structure = true, $ignore_targeted_recovery_owner = false ) {
		/* MOBO-HEALTH-SYNC-LICENSE-HARDENING-r2: durable operational snapshot. */
		$mobo_operational_limit = max( 1, absint( $detail_limit ) );
		if ( class_exists( 'Mobo_Core_Health_Read_Cache' ) && ! Mobo_Core_Health_Read_Cache::is_sync_refreshing() ) {
			$mobo_operational_cached = Mobo_Core_Health_Read_Cache::get_sync_health_operational_snapshot( $mobo_operational_limit );
			if ( is_array( $mobo_operational_cached ) ) {
				return $mobo_operational_cached;
			}
		}
global $wpdb;

		$raw = self::get_dashboard_status();
		$raw_counts = isset( $raw['counts'] ) && is_array( $raw['counts'] )
			? $raw['counts']
			: array( 'synced' => 0, 'behind' => 0, 'repairing' => 0, 'failed' => 0 );

		$operational = array(
			'currentProducts'          => 0,
			'monitoredProducts'        => 0,
			'excludedByPolicy'         => 0,
			'converged'                => 0,
			'activeRetryOwner'         => 0,
			'terminalUpstreamInvalid'  => 0,
			'unownedConvergenceGap'    => 0,
			'unverifiedHealthGap'       => 0,
			'needsAttention'           => 0,
			'needsVerification'        => 0,
			'notFullyConverged'        => 0,
			'historicalHealthResidue'  => 0,
			'historicalNonLive'        => 0,
			'historicalExcluded'       => 0,
		);

		$result = $raw;
		$result['rawCounts'] = $raw_counts;
		$result['operational'] = $operational;
		$result['operationalStatus'] = 'unavailable';
		$result['unownedRows'] = array();
		$result['verificationRows'] = array();
		$result['activeRetryRows'] = array();
		$result['terminalRows'] = array();

		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) {
			$result['operationalReason'] = 'connection-unavailable';
			$result['structureAuditMode'] = $deep_structure ? 'deep' : 'targeted';
			return $result;
		}

		if ( ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists() ) {
			$result['operationalReason'] = 'product-map-unavailable';
			$result['structureAuditMode'] = $deep_structure ? 'deep' : 'targeted';
			return $result;
		}

		$map_table = preg_replace( '/[^A-Za-z0-9_]/', '', Mobo_Core_Product_Map::table_name() );
		$health_table = self::table_exists() ? preg_replace( '/[^A-Za-z0-9_]/', '', self::table_name() ) : '';
		$health_select = '' !== $health_table
			? ", h.id AS health_id, h.wp_product_id AS health_wp_product_id, h.portal_revision, h.portal_version, h.portal_hash, h.sync_status, h.last_error, h.updated_at AS health_updated_at"
			: ", 0 AS health_id, 0 AS health_wp_product_id, 0 AS portal_revision, '' AS portal_version, '' AS portal_hash, '' AS sync_status, NULL AS last_error, NULL AS health_updated_at";
		$health_join = '' !== $health_table
			? " LEFT JOIN `{$health_table}` h ON h.connection_id = m.connection_id AND h.product_guid = m.remote_guid"
			: '';

		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.remote_guid, m.wp_post_id, m.last_hash AS map_last_hash, m.sync_incomplete AS map_sync_incomplete, m.updated_at AS map_updated_at{$health_select}
				 FROM `{$map_table}` m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.wp_post_id
				 {$health_join}
				 WHERE m.connection_id = %d
				   AND m.object_type = 'product'
				   AND m.wp_post_id > 0
				   AND p.post_type = 'product'
				   AND p.post_status NOT IN ('trash','auto-draft')
				 ORDER BY m.id ASC",
				$connection_id
			),
			ARRAY_A
		);
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $rows ) ) {
			$result['operationalStatus'] = 'unavailable';
			$result['operationalReason'] = 'current-product-map-query-failed';
			$result['structureAuditMode'] = $deep_structure ? 'deep' : 'targeted';
			return $result;
		}
		$operational['currentProducts'] = count( $rows );

		/* Prime product meta in one query to avoid per-product postmeta reads. */
		$product_ids = array();
		foreach ( $rows as $row ) {
			$product_id = absint( isset( $row['wp_post_id'] ) ? $row['wp_post_id'] : 0 );
			if ( $product_id > 0 ) {
				$product_ids[] = $product_id;
			}
		}
		if ( ! empty( $product_ids ) && function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', array_values( array_unique( $product_ids ) ) );
		}

		$current_guids = array();
		$health_scan_total = count( $rows );
		$health_scan_processed = 0;
		foreach ( $rows as $row ) {
			$health_scan_processed++;
			if ( ( 1 === $health_scan_processed || 0 === $health_scan_processed % 250 || $health_scan_processed === $health_scan_total )
				&& has_action( 'mobo_core_sync_health_scan_progress' ) ) {
				do_action( 'mobo_core_sync_health_scan_progress', $health_scan_processed, $health_scan_total, $deep_structure ? 'deep' : 'targeted' );
			}
			$guid = sanitize_text_field( (string) ( isset( $row['remote_guid'] ) ? $row['remote_guid'] : '' ) );
			if ( '' !== $guid ) {
				$current_guids[ $guid ] = true;
			}
		}

		$context = self::load_operational_context( $ignore_targeted_recovery_owner );
		if ( null === $context ) {
			$result['operationalStatus'] = 'unavailable';
			$result['operationalReason'] = 'sync-event-ownership-query-failed';
			$result['structureAuditMode'] = $deep_structure ? 'deep' : 'targeted';
			return $result;
		}
		$limit = max( 0, absint( $detail_limit ) );
		self::classify_operational_rows( $rows, $context, $deep_structure, $limit, $operational, $result );

		$historical = self::count_historical_non_live( $connection_id, $health_table, static function ( $guids ) use ( $current_guids ) {
			$live = array();
			foreach ( $guids as $guid ) {
				if ( ! empty( $current_guids[ $guid ] ) ) { $live[ $guid ] = true; }
			}
			return $live;
		} );
		if ( null === $historical ) {
			$result['operationalStatus'] = 'unavailable';
			$result['operationalReason'] = 'sync-health-history-query-failed';
			$result['structureAuditMode'] = $deep_structure ? 'deep' : 'targeted';
			return $result;
		}
		$operational['historicalNonLive'] += $historical;
		$result = self::finalize_operational_result( $result, $operational, $deep_structure );

		if ( class_exists( 'Mobo_Core_Health_Read_Cache' ) ) {
			Mobo_Core_Health_Read_Cache::store_sync_health_operational_snapshot( $mobo_operational_limit, $result );
		}
		return $result;
	}

	/**
	 * Event ownership + targeted-recovery context shared by the full and paged scans.
	 *
	 * @param bool $ignore_targeted_recovery_owner See get_operational_dashboard_status().
	 * @return array|null Null when the event ownership read failed.
	 */
	private static function load_operational_context( $ignore_targeted_recovery_owner = false ) {
		$event_ownership = self::load_operational_event_ownership();
		if ( ! empty( $event_ownership['readFailed'] ) ) {
			return null;
		}
		$context = array(
			'active'               => isset( $event_ownership['active'] ) && is_array( $event_ownership['active'] ) ? $event_ownership['active'] : array(),
			'terminal'             => isset( $event_ownership['terminal'] ) && is_array( $event_ownership['terminal'] ) ? $event_ownership['terminal'] : array(),
			'targetedGuids'        => array(),
			'targetedOwnsUnowned'  => false,
		);
		/* The worker's final reclassification must be able to observe its own
		 * post-apply residuals and any newly-actionable rows that appeared while
		 * the frozen target set was running. Ordinary dashboard callers keep the
		 * active-owner view; only the targeted worker opts out at its completion
		 * boundary. */
		if ( ! $ignore_targeted_recovery_owner && class_exists( 'Mobo_Core_Product_Sync' ) ) {
			$targeted_sync = new Mobo_Core_Product_Sync();
			$targeted_state = $targeted_sync->get_manual_sync_state();
			$targeted_status = sanitize_key( (string) ( $targeted_state['status'] ?? '' ) );
			if ( ! empty( $targeted_state['targetedConvergenceOnly'] ) && in_array( $targeted_status, array( 'running', 'waiting_for_portal' ), true ) ) {
				$context['targetedOwnsUnowned'] = ! empty( $targeted_state['targetedConvergenceOwnsUnowned'] );
				foreach ( (array) ( $targeted_state['targetedConvergenceTargets'] ?? array() ) as $target ) {
					if ( ! is_array( $target ) ) { continue; }
					$target_guid = sanitize_text_field( (string) ( $target['productGuid'] ?? '' ) );
					if ( '' !== $target_guid ) { $context['targetedGuids'][ $target_guid ] = true; }
				}
			}
		}
		return $context;
	}

	/**
	 * Classify current-product rows into the operational counters/detail lists.
	 * Row-independent, so the paged background scan can feed it page by page.
	 *
	 * @param array $rows           Rows of the current-product query.
	 * @param array $context        load_operational_context() result.
	 * @param bool  $deep_structure Deep structure audit.
	 * @param int   $limit          Detail rows per category.
	 * @param array $operational    Counters (by reference).
	 * @param array $result         Result with detail lists (by reference).
	 * @return void
	 */
	private static function classify_operational_rows( $rows, $context, $deep_structure, $limit, &$operational, &$result ) {
		$active_events = $context['active'];
		$terminal_events = $context['terminal'];
		$targeted_recovery_guids = $context['targetedGuids'];
		$targeted_recovery_owns_unowned = ! empty( $context['targetedOwnsUnowned'] );

		foreach ( $rows as $row ) {
			$guid = sanitize_text_field( (string) ( isset( $row['remote_guid'] ) ? $row['remote_guid'] : '' ) );
			$product_id = absint( isset( $row['wp_post_id'] ) ? $row['wp_post_id'] : 0 );
			$health_id = absint( isset( $row['health_id'] ) ? $row['health_id'] : 0 );
			$health_status = sanitize_key( (string) ( isset( $row['sync_status'] ) ? $row['sync_status'] : '' ) );

			/* A product intentionally excluded from source-driven mutation must not be
			 * reported as a convergence failure merely because historical Health or
			 * incomplete markers pre-date the exclusion. Keep it in current-product
			 * accounting, but outside the active synchronization-health denominator. */
			if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $product_id, false ) ) {
				$operational['excludedByPolicy']++;
				continue;
			}

			$post_incomplete = '1' === (string) get_post_meta( $product_id, 'mobo_sync_incomplete', true );
			$map_incomplete = ! empty( $row['map_sync_incomplete'] );
			/* Target deep structure checks to rows that can affect operational health.
			 * Full forensic callers retain the original deep-scan behavior. */
			$structure_check_required = (bool) $deep_structure
				|| $post_incomplete
				|| $map_incomplete
				|| in_array( $health_status, array( 'behind', 'repairing', 'failed' ), true )
				|| ( '' !== $guid && ! empty( $terminal_events[ $guid ] ) );
			$local_sane = $structure_check_required
				? self::local_product_structure_is_sane( $product_id )
				: true;
			$has_active_owner = '' !== $guid && ( ! empty( $active_events[ $guid ] ) || ! empty( $targeted_recovery_guids[ $guid ] ) );
			$current_incomplete_signal = $post_incomplete
				|| $map_incomplete
				|| in_array( $health_status, array( 'behind', 'repairing', 'failed' ), true );
			$terminal_candidates = '' !== $guid && isset( $terminal_events[ $guid ] ) && is_array( $terminal_events[ $guid ] )
				? $terminal_events[ $guid ]
				: array();
			$durable_terminal_error = 0 === strpos( (string) ( isset( $row['last_error'] ) ? $row['last_error'] : '' ), 'Incomplete Mobo variant attributes.' );
			$durable_terminal_version = sanitize_text_field( (string) ( isset( $row['portal_version'] ) ? $row['portal_version'] : '' ) );
			if ( '' === $durable_terminal_version && absint( isset( $row['portal_revision'] ) ? $row['portal_revision'] : 0 ) > 0 ) {
				$durable_terminal_version = (string) absint( $row['portal_revision'] );
			}
			$durable_terminal_event = $durable_terminal_error && '' !== $durable_terminal_version
				? array(
					'id'           => 0,
					'eventVersion' => $durable_terminal_version,
					'updatedAt'    => sanitize_text_field( (string) ( isset( $row['health_updated_at'] ) ? $row['health_updated_at'] : '' ) ),
					'lastError'    => sanitize_textarea_field( (string) ( isset( $row['last_error'] ) ? $row['last_error'] : '' ) ),
				)
				: array();
			$durable_terminal_current = ! empty( $durable_terminal_event )
				&& self::terminal_event_matches_current_boundary( $durable_terminal_event, $row, $product_id );
			$terminal_event = ! empty( $terminal_candidates ) ? end( $terminal_candidates ) : ( $durable_terminal_current ? $durable_terminal_event : array() );
			/* current-boundary-terminal-owns-local-structure-gap
			 * r4: a synced row can still be upstream-blocked when its durable local
			 * variable structure is malformed. Do not accept any historical terminal
			 * failure blindly: at least one terminal event must match or supersede the
			 * current product/Health ordering boundary. Existing explicit incomplete/
			 * failed signals retain the r3 ownership semantics. */
			$current_terminal_event = self::find_current_terminal_event( $terminal_candidates, $row, $product_id );
			$terminal_matches_current_boundary = ! empty( $current_terminal_event ) || $durable_terminal_current;
			if ( ! empty( $current_terminal_event ) ) {
				$terminal_event = $current_terminal_event;
			} elseif ( $durable_terminal_current ) {
				$terminal_event = $durable_terminal_event;
			}
			/* Terminal ownership is valid only at the current ordering boundary.
			 * Historical terminal events must not indefinitely shield an incomplete
			 * product after a newer reconnect/source snapshot supersedes them; such
			 * rows become actionable again and can be adopted by targeted recovery. */
			$has_terminal_upstream = ! $has_active_owner
				&& $terminal_matches_current_boundary
				&& ( $current_incomplete_signal || ! $local_sane );

			$detail = array(
				'productGuid' => $guid,
				'wpProductId' => $product_id,
				'healthStatus' => $health_status,
				'healthUpdatedAt' => sanitize_text_field( (string) ( isset( $row['health_updated_at'] ) ? $row['health_updated_at'] : '' ) ),
				'lastError' => sanitize_textarea_field( substr( (string) ( isset( $row['last_error'] ) ? $row['last_error'] : '' ), 0, 500 ) ),
				'postIncomplete' => $post_incomplete,
				'mapIncomplete' => $map_incomplete,
				'localStructureSane' => $local_sane,
				'localStructureChecked' => $structure_check_required,
				'terminalEvidenceCurrent' => $terminal_matches_current_boundary,
				'terminalEventId' => ! empty( $terminal_event ) ? absint( isset( $terminal_event['id'] ) ? $terminal_event['id'] : 0 ) : 0,
				'terminalEventVersion' => ! empty( $terminal_event ) ? sanitize_text_field( (string) ( isset( $terminal_event['eventVersion'] ) ? $terminal_event['eventVersion'] : '' ) ) : '',
				'terminalEventUpdatedAt' => ! empty( $terminal_event ) ? sanitize_text_field( (string) ( isset( $terminal_event['updatedAt'] ) ? $terminal_event['updatedAt'] : '' ) ) : '',
				'terminalEventError' => ! empty( $terminal_event ) ? sanitize_textarea_field( (string) ( isset( $terminal_event['lastError'] ) ? $terminal_event['lastError'] : '' ) ) : '',
			);

			if ( $has_active_owner ) {
				$operational['activeRetryOwner']++;
				if ( count( $result['activeRetryRows'] ) < $limit ) {
					$result['activeRetryRows'][] = $detail;
				}
				continue;
			}

			if ( $has_terminal_upstream ) {
				$operational['terminalUpstreamInvalid']++;
				if ( count( $result['terminalRows'] ) < $limit ) {
					$result['terminalRows'][] = $detail;
				}
				continue;
			}

			if ( $health_id > 0 && in_array( $health_status, array( 'behind', 'repairing', 'failed' ), true ) ) {
				if ( self::health_row_is_durable_residue( $row, $product_id, $post_incomplete, $map_incomplete, $local_sane ) ) {
					$operational['historicalHealthResidue']++;
					$operational['converged']++;
					continue;
				}

				/* A raw behind/failed Health row alone is not proof that the current
				 * product is broken. When local durable markers say complete and the
				 * targeted structure check is sane, classify it separately as an
				 * unverified historical/current ambiguity instead of claiming a real
				 * convergence gap. */
				if ( ! $post_incomplete && ! $map_incomplete && $local_sane ) {
					$operational['unverifiedHealthGap']++;
					if ( count( $result['verificationRows'] ) < $limit ) {
						$detail['classification'] = 'health-only-unverified';
						$result['verificationRows'][] = $detail;
					}
					continue;
				}

				if ( $targeted_recovery_owns_unowned ) {
					$operational['activeRetryOwner']++;
					if ( count( $result['activeRetryRows'] ) < $limit ) {
						$detail['classification'] = 'targeted-global-owner';
						$result['activeRetryRows'][] = $detail;
					}
					continue;
				}
				$operational['unownedConvergenceGap']++;
				if ( count( $result['unownedRows'] ) < $limit ) {
					$detail['classification'] = 'verified-local-gap';
					$result['unownedRows'][] = $detail;
				}
				continue;
			}

			/* A synced/no-health row is operationally complete only when durable local
			 * truth also says complete. This catches health bookkeeping that claims
			 * success while Product Map/post metadata still advertises incompleteness. */
			if ( ! $post_incomplete && ! $map_incomplete && $local_sane ) {
				$operational['converged']++;
				continue;
			}

			if ( $targeted_recovery_owns_unowned ) {
				$operational['activeRetryOwner']++;
				if ( count( $result['activeRetryRows'] ) < $limit ) {
					$detail['classification'] = 'targeted-global-owner';
					$result['activeRetryRows'][] = $detail;
				}
				continue;
			}
			$operational['unownedConvergenceGap']++;
			if ( count( $result['unownedRows'] ) < $limit ) {
				$result['unownedRows'][] = $detail;
			}
		}
	}

	/**
	 * Health rows not belonging to a current live canonical Product Map row are
	 * historical/non-live observations. They remain queryable but are excluded
	 * from operational product-health counts.
	 *
	 * @param int      $connection_id Connection.
	 * @param string   $health_table  Sanitized health table ('' when missing).
	 * @param callable $live_lookup   function( string[] $guids ): array<string,bool> of live GUIDs.
	 * @return int|null Null on a read failure.
	 */
	private static function count_historical_non_live( $connection_id, $health_table, $live_lookup ) {
		global $wpdb;
		if ( '' === $health_table ) {
			return 0;
		}
		$wpdb->last_error = '';
		$incomplete_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT product_guid FROM `{$health_table}` WHERE connection_id = %d AND sync_status IN ('behind','repairing','failed')", $connection_id ),
			ARRAY_A
		);
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $incomplete_rows ) ) {
			return null;
		}
		$guids = array();
		$count = 0;
		foreach ( $incomplete_rows as $health_row ) {
			$guid = sanitize_text_field( (string) ( isset( $health_row['product_guid'] ) ? $health_row['product_guid'] : '' ) );
			if ( '' === $guid ) {
				$count++;
			} else {
				$guids[] = $guid;
			}
		}
		$live = array();
		foreach ( array_chunk( $guids, 200 ) as $chunk ) {
			$found = call_user_func( $live_lookup, $chunk );
			if ( null === $found ) {
				return null;
			}
			$live += (array) $found;
		}
		foreach ( $guids as $guid ) {
			if ( empty( $live[ $guid ] ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Derived counters, accounting check and operational status.
	 *
	 * @param array $result         Partial result.
	 * @param array $operational    Counters.
	 * @param bool  $deep_structure Deep structure audit.
	 * @return array
	 */
	private static function finalize_operational_result( $result, $operational, $deep_structure ) {
		/* Only durable current-state evidence belongs in operational attention. A raw
		 * Health-only discrepancy is audit evidence, not proof that the live product
		 * needs mutation or Repair. Keep it in a separate verification denominator. */
		$operational['monitoredProducts'] = max( 0, absint( $operational['currentProducts'] ) - absint( $operational['excludedByPolicy'] ) );
		$operational['needsAttention'] = absint( $operational['activeRetryOwner'] ) + absint( $operational['unownedConvergenceGap'] );
		$operational['needsVerification'] = absint( $operational['unverifiedHealthGap'] );
		$operational['notFullyConverged'] = absint( $operational['needsAttention'] ) + absint( $operational['needsVerification'] ) + absint( $operational['terminalUpstreamInvalid'] );
		$operational['historicalExcluded'] = absint( $operational['historicalHealthResidue'] ) + absint( $operational['historicalNonLive'] );

		/* Keep the current-product accounting closed. Any future classifier branch
		 * that forgets to account for a mapped live product becomes visible here. */
		$accounted = absint( $operational['excludedByPolicy'] )
			+ absint( $operational['converged'] )
			+ absint( $operational['activeRetryOwner'] )
			+ absint( $operational['terminalUpstreamInvalid'] )
			+ absint( $operational['unownedConvergenceGap'] )
			+ absint( $operational['unverifiedHealthGap'] );
		$operational['accountingDelta'] = absint( $operational['currentProducts'] ) - $accounted;

		$result['operational'] = $operational;
		$result['pendingSync'] = absint( $operational['needsAttention'] );
		$result['pendingRepair'] = absint( $operational['needsAttention'] ); // Backward-compatible field; no Repair is scheduled.
		$result['operationalStatus'] = $operational['accountingDelta'] !== 0
			? 'accounting-error'
			: ( $operational['unownedConvergenceGap'] > 0 ? 'degraded-unowned' : ( $operational['unverifiedHealthGap'] > 0 ? 'needs-verification' : ( $operational['activeRetryOwner'] > 0 ? 'progressing' : ( $operational['terminalUpstreamInvalid'] > 0 ? 'blocked-upstream' : 'clear' ) ) ) );
		$result['operationalReason'] = 'current-product-map';
		$result['structureAuditMode'] = $deep_structure ? 'deep' : 'targeted';
		return $result;
	}

	/** Option holding the cursor/partial counters of the paged background scan (per Connection). */
	const SCAN_STATE_OPTION_PREFIX = 'mobo_core_sync_health_scan_v1_c';

	/** A partial scan older than this restarts from the beginning. */
	const SCAN_STATE_MAX_AGE = 3600;

	/** Persist the scan cursor at least this often while classifying (seconds). */
	const SCAN_PERSIST_SECONDS = 2.0;

	/** Requests that died at the same cursor (with a 1-row chunk) before that product is skipped. */
	const SCAN_STALL_SKIP_AFTER = 3;

	/**
	 * Pure decision (unit tested) at the start of a scan slice: did the previous
	 * slice die inside the scan (its in-flight marker is still stored), how large
	 * may the next chunk be, and must the stuck product be skipped?
	 *
	 * @param array $state     Stored scan state.
	 * @param int   $cursor    Current durable cursor.
	 * @param int   $page_size Maximum chunk.
	 * @return array{died:bool,stalls:int,chunk:int,skip:bool}
	 */
	public static function scan_chunk_decision( $state, $cursor, $page_size ) {
		$page_size = max( 1, absint( $page_size ) );
		$chunk     = absint( is_array( $state ) ? ( $state['chunk'] ?? 0 ) : 0 );
		$chunk     = $chunk > 0 ? min( $page_size, $chunk ) : $page_size;
		$stalls    = absint( is_array( $state ) ? ( $state['stalls'] ?? 0 ) : 0 );
		$inflight  = is_array( $state ) && isset( $state['inflight'] ) && is_array( $state['inflight'] ) ? $state['inflight'] : null;
		$died      = null !== $inflight;
		if ( $died ) {
			/* Any death shrinks the chunk; only a death without any cursor progress counts as a stall. */
			$stalls = absint( $inflight['startCursor'] ?? -1 ) === absint( $cursor ) ? $stalls + 1 : 0;
			$chunk  = max( 1, (int) floor( $chunk / 4 ) );
		}
		$skip = $died && 1 === $chunk && $stalls >= self::SCAN_STALL_SKIP_AFTER;
		return array( 'died' => $died, 'stalls' => $stalls, 'chunk' => $chunk, 'skip' => $skip );
	}

	/**
	 * Pure adaptive chunk size (unit tested): shrink to what fitted when the deadline
	 * cut a chunk short, grow (x2) after a full chunk that took under 2 s.
	 *
	 * @param int   $chunk     Current chunk.
	 * @param int   $done      Rows classified from this chunk.
	 * @param int   $fetched   Rows fetched for this chunk.
	 * @param float $elapsed   Seconds spent on this chunk.
	 * @param int   $page_size Maximum chunk.
	 * @return int
	 */
	public static function next_scan_chunk( $chunk, $done, $fetched, $elapsed, $page_size ) {
		$chunk     = max( 1, absint( $chunk ) );
		$page_size = max( 1, absint( $page_size ) );
		if ( absint( $done ) < absint( $fetched ) ) {
			return max( 1, min( $chunk, absint( $done ) ) );
		}
		if ( absint( $fetched ) >= $chunk && (float) $elapsed < 2.0 ) {
			return min( $page_size, $chunk * 2 );
		}
		return $chunk;
	}

	/**
	 * Pure decision (unit tested): may a stored partial scan be resumed?
	 *
	 * @param mixed $state         Stored state.
	 * @param int   $connection_id Current Connection.
	 * @param int   $limit         Requested detail limit.
	 * @param int   $now           Unix time.
	 * @return bool
	 */
	public static function scan_state_resumable( $state, $connection_id, $limit, $now ) {
		return is_array( $state )
			&& absint( $state['connectionId'] ?? 0 ) === absint( $connection_id )
			&& absint( $state['limit'] ?? -1 ) === absint( $limit )
			&& isset( $state['operational'], $state['lists'] ) && is_array( $state['operational'] ) && is_array( $state['lists'] )
			&& absint( $state['startedAt'] ?? 0 ) > 0
			&& ( absint( $now ) - absint( $state['startedAt'] ) ) < self::SCAN_STATE_MAX_AGE;
	}

	/** @return bool Whether a paged background scan is in progress for the current Connection. */
	public static function operational_scan_in_progress() {
		$connection_id = self::connection_id();
		return $connection_id > 0 && is_array( get_option( self::SCAN_STATE_OPTION_PREFIX . $connection_id, null ) );
	}

	/**
	 * One deadline-bounded slice of the operational Sync Health scan for the
	 * real-cron snapshot refresh. The full scan hydrated the whole catalog in one
	 * request (126 s on a 21 s budget); this pages the current Product Map by id
	 * with a durable cursor and partial counters, so successive cron requests finish
	 * it. Classification is the same row-wise code as the synchronous scan.
	 *
	 * @param int   $detail_limit Detail rows per category.
	 * @param float $deadline     Absolute microtime; work stops ~1 s before it.
	 * @param int   $page_size    Rows per page.
	 * @param int   $max_pages    Optional hard cap of pages in this call (0 = deadline only).
	 * @return array{complete:bool,status:string,processed:int,cursor:int,result?:array}
	 */
	public static function run_operational_scan_page( $detail_limit = 20, $deadline = 0.0, $page_size = 200, $max_pages = 0 ) {
		$max_pages = absint( $max_pages );
		$pages     = 0;
		global $wpdb;
		$limit         = max( 1, absint( $detail_limit ) );
		$page_size     = max( 20, min( 1000, absint( $page_size ) ) );
		$deadline      = (float) $deadline > 0 ? (float) $deadline : microtime( true ) + 5.0;
		$connection_id = self::connection_id();
		$out           = array( 'complete' => false, 'status' => 'unavailable', 'processed' => 0, 'cursor' => 0 );
		if ( $connection_id <= 0 || ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists() ) {
			return $out;
		}
		$option = self::SCAN_STATE_OPTION_PREFIX . $connection_id;
		$now    = time();
		$state  = get_option( $option, null );
		if ( ! self::scan_state_resumable( $state, $connection_id, $limit, $now ) ) {
			$state = array(
				'connectionId' => $connection_id,
				'limit'        => $limit,
				'startedAt'    => $now,
				'cursor'       => 0,
				'operational'  => array(
					'currentProducts' => 0, 'monitoredProducts' => 0, 'excludedByPolicy' => 0, 'converged' => 0,
					'activeRetryOwner' => 0, 'terminalUpstreamInvalid' => 0, 'unownedConvergenceGap' => 0,
					'unverifiedHealthGap' => 0, 'needsAttention' => 0, 'needsVerification' => 0, 'notFullyConverged' => 0,
					'historicalHealthResidue' => 0, 'historicalNonLive' => 0, 'historicalExcluded' => 0,
				),
				'lists'        => array( 'unownedRows' => array(), 'verificationRows' => array(), 'activeRetryRows' => array(), 'terminalRows' => array() ),
			);
		}

		$context = self::load_operational_context( false );
		if ( null === $context ) {
			$out['status'] = 'event-ownership-query-failed';
			return $out;
		}

		$map_table    = preg_replace( '/[^A-Za-z0-9_]/', '', Mobo_Core_Product_Map::table_name() );
		$health_table = self::table_exists() ? preg_replace( '/[^A-Za-z0-9_]/', '', self::table_name() ) : '';
		$health_select = '' !== $health_table
			? ", h.id AS health_id, h.wp_product_id AS health_wp_product_id, h.portal_revision, h.portal_version, h.portal_hash, h.sync_status, h.last_error, h.updated_at AS health_updated_at"
			: ", 0 AS health_id, 0 AS health_wp_product_id, 0 AS portal_revision, '' AS portal_version, '' AS portal_hash, '' AS sync_status, NULL AS last_error, NULL AS health_updated_at";
		$health_join = '' !== $health_table
			? " LEFT JOIN `{$health_table}` h ON h.connection_id = m.connection_id AND h.product_guid = m.remote_guid"
			: '';

		$operational = $state['operational'];
		$lists       = $state['lists'];
		$cursor      = absint( $state['cursor'] );
		$finished    = 'finalize' === (string) ( $state['phase'] ?? '' );

		/* Runtime-30s: a request that died inside this scan left its in-flight
		 * marker behind. Shrink the chunk after every such death; after
		 * SCAN_STALL_SKIP_AFTER deaths at the same cursor with a 1-row chunk the
		 * offending product is skipped and reported for verification instead of
		 * being retried forever (no livelock). */
		$decision = self::scan_chunk_decision( $state, $cursor, $page_size );
		$chunk    = $decision['chunk'];
		$state['chunk']  = $chunk;
		$state['stalls'] = $decision['stalls'];
		$state['deaths'] = absint( $state['deaths'] ?? 0 ) + ( $decision['died'] ? 1 : 0 );
		$state['inflight'] = array( 'startCursor' => $cursor, 'at' => $now );
		update_option( $option, $state, false );
		$last_persist = microtime( true );

		$sql_page = static function ( $after, $size ) use ( $wpdb, $map_table, $health_select, $health_join, $connection_id ) {
			$wpdb->last_error = '';
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.id AS map_id, m.remote_guid, m.wp_post_id, m.last_hash AS map_last_hash, m.sync_incomplete AS map_sync_incomplete, m.updated_at AS map_updated_at{$health_select}
					 FROM `{$map_table}` m
					 INNER JOIN {$wpdb->posts} p ON p.ID = m.wp_post_id
					 {$health_join}
					 WHERE m.connection_id = %d
					   AND m.object_type = 'product'
					   AND m.wp_post_id > 0
					   AND m.id > %d
					   AND p.post_type = 'product'
					   AND p.post_status NOT IN ('trash','auto-draft')
					 ORDER BY m.id ASC
					 LIMIT %d",
					$connection_id,
					$after,
					$size
				),
				ARRAY_A
			);
		};

		if ( ! $finished && $decision['skip'] ) {
			$rows = $sql_page( $cursor, 1 );
			if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $rows ) ) {
				$out['status'] = 'current-product-map-query-failed';
				return $out;
			}
			if ( ! empty( $rows ) ) {
				$row = $rows[0];
				$operational['currentProducts']++;
				$operational['unverifiedHealthGap']++;
				if ( count( $lists['verificationRows'] ) < $limit ) {
					$lists['verificationRows'][] = array(
						'productGuid'    => sanitize_text_field( (string) ( $row['remote_guid'] ?? '' ) ),
						'wpProductId'    => absint( $row['wp_post_id'] ?? 0 ),
						'healthStatus'   => sanitize_key( (string) ( $row['sync_status'] ?? '' ) ),
						'classification' => 'scan-skipped-timeout',
					);
				}
				$cursor = max( $cursor, absint( $row['map_id'] ?? 0 ) );
				$state['skipped'] = absint( $state['skipped'] ?? 0 ) + 1;
				$state['stalls']  = 0;
				$out['skipped']   = 1;
			}
		}

		$deadline_hit = false;
		while ( ! $finished ) {
			if ( microtime( true ) >= $deadline - 1.0 ) {
				$deadline_hit = true;
				break;
			}
			$requested = $chunk;
			$rows = $sql_page( $cursor, $requested );
			if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $rows ) ) {
				$out['status'] = 'current-product-map-query-failed';
				return $out;
			}
			if ( empty( $rows ) ) {
				$finished = true;
				break;
			}
			$product_ids = array();
			foreach ( $rows as $row ) {
				$product_id = absint( $row['wp_post_id'] ?? 0 );
				if ( $product_id > 0 ) { $product_ids[] = $product_id; }
			}
			$product_ids = array_values( array_unique( $product_ids ) );
			if ( ! empty( $product_ids ) && function_exists( 'update_meta_cache' ) ) {
				update_meta_cache( 'post', $product_ids );
			}
			$chunk_started = microtime( true );
			$done_in_chunk = 0;
			foreach ( $rows as $row ) {
				/* Per-product deadline: one product with many variations must not
				 * carry the request past its limit. */
				if ( $done_in_chunk > 0 && microtime( true ) >= $deadline - 1.0 ) {
					$deadline_hit = true;
					break;
				}
				$operational['currentProducts']++;
				self::classify_operational_rows( array( $row ), $context, false, $limit, $operational, $lists );
				$cursor = max( $cursor, absint( $row['map_id'] ?? 0 ) );
				$out['processed']++;
				$done_in_chunk++;
				if ( microtime( true ) - $last_persist >= self::SCAN_PERSIST_SECONDS ) {
					/* Incremental durable cursor: a hard kill loses at most a few seconds. */
					$state['cursor'] = $cursor; $state['operational'] = $operational; $state['lists'] = $lists;
					$state['inflight'] = array( 'startCursor' => $cursor, 'at' => time() );
					update_option( $option, $state, false );
					$last_persist = microtime( true );
				}
			}
			if ( function_exists( 'wp_cache_delete' ) ) {
				foreach ( $product_ids as $evict_id ) { wp_cache_delete( $evict_id, 'post_meta' ); }
			}
			$chunk = self::next_scan_chunk( $chunk, $done_in_chunk, count( $rows ), microtime( true ) - $chunk_started, $page_size );
			if ( $deadline_hit ) {
				break;
			}
			if ( count( $rows ) < $requested ) {
				$finished = true;
				break;
			}
			$pages++;
			if ( $max_pages > 0 && $pages >= $max_pages ) {
				break;
			}
		}

		$out['cursor'] = $cursor;
		$state['chunk'] = $chunk;
		/* The closing history query needs a little time of its own; resume it next request. */
		if ( $finished && microtime( true ) >= $deadline - 2.0 ) {
			$state['phase'] = 'finalize';
			$finished_now   = false;
		} else {
			$finished_now = $finished;
		}
		if ( ! $finished_now ) {
			$state['cursor']      = $cursor;
			$state['operational'] = $operational;
			$state['lists']       = $lists;
			unset( $state['inflight'] );
			update_option( $option, $state, false );
			$out['status'] = 'in-progress';
			$out['chunk']  = $chunk;
			return $out;
		}

		$historical = self::count_historical_non_live( $connection_id, $health_table, static function ( $guids ) use ( $wpdb, $map_table, $connection_id ) {
			$placeholders = implode( ',', array_fill( 0, count( $guids ), '%s' ) );
			$wpdb->last_error = '';
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT m.remote_guid FROM `{$map_table}` m INNER JOIN {$wpdb->posts} p ON p.ID = m.wp_post_id
					 WHERE m.connection_id = %d AND m.object_type = 'product' AND m.wp_post_id > 0
					   AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft')
					   AND m.remote_guid IN ({$placeholders})",
					array_merge( array( $connection_id ), $guids )
				)
			);
			if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $found ) ) {
				return null;
			}
			$live = array();
			foreach ( $found as $guid ) {
				$live[ sanitize_text_field( (string) $guid ) ] = true;
			}
			return $live;
		} );
		if ( null === $historical ) {
			$out['status'] = 'sync-health-history-query-failed';
			return $out;
		}
		$operational['historicalNonLive'] += $historical;

		$raw = self::get_dashboard_status();
		$result = $raw;
		$result['rawCounts'] = isset( $raw['counts'] ) && is_array( $raw['counts'] ) ? $raw['counts'] : array( 'synced' => 0, 'behind' => 0, 'repairing' => 0, 'failed' => 0 );
		foreach ( $lists as $key => $rows_list ) {
			$result[ $key ] = $rows_list;
		}
		$result = self::finalize_operational_result( $result, $operational, false );
		$result['scanMode'] = 'paged';
		$result['scanStartedAt'] = absint( $state['startedAt'] );
		delete_option( $option );

		$out['complete'] = true;
		$out['status']   = 'complete';
		$out['result']   = $result;
		return $out;
	}

	/**
	 * Bulk-load durable webhook ownership for current product convergence.
	 *
	 * @return array
	 */
	private static function load_operational_event_ownership() {
		global $wpdb;
		$out = array( 'active' => array(), 'terminal' => array(), 'readFailed' => false );
		if ( ! class_exists( 'Mobo_Core_Sync_Event_Store' ) || ! Mobo_Core_Sync_Event_Store::table_exists() ) {
			return $out;
		}

		$table = preg_replace( '/[^A-Za-z0-9_]/', '', Mobo_Core_Sync_Event_Store::table_name() );
		$connection_id = self::connection_id();
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, event_type, entity_type, entity_guid, event_version, status, try_count, payload_json, last_error, updated_at
			 FROM `{$table}`
			 WHERE connection_id = %d
			   AND event_type IN ('ProductUpdated','UpdateVariant')
			   AND (
				status IN ('pending','processing')
				OR (event_type='UpdateVariant' AND status='failed' AND try_count>=5 AND last_error LIKE 'Incomplete Mobo variant attributes.%')
			   )
			 ORDER BY id ASC", $connection_id ),
			ARRAY_A
		);
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $rows ) ) {
			$out['readFailed'] = true;
			return $out;
		}

		foreach ( $rows as $row ) {
			$event_type = sanitize_text_field( (string) ( isset( $row['event_type'] ) ? $row['event_type'] : '' ) );
			$status = sanitize_key( (string) ( isset( $row['status'] ) ? $row['status'] : '' ) );
			$payload = json_decode( (string) ( isset( $row['payload_json'] ) ? $row['payload_json'] : '' ), true );
			$guid = is_array( $payload ) ? self::extract_product_guid_recursive( $payload ) : '';
			if ( '' === $guid ) {
				$candidate = sanitize_text_field( (string) ( isset( $row['entity_guid'] ) ? $row['entity_guid'] : '' ) );
				$entity_type = sanitize_key( (string) ( isset( $row['entity_type'] ) ? $row['entity_type'] : '' ) );
				/* UpdateVariant terminal product entity fallback: some durable variant failures
				 * are product-scoped and carry the canonical product GUID only in entity_guid. */
				$entity_guid_is_product = 'ProductUpdated' === $event_type
					|| ( 'UpdateVariant' === $event_type && 'product' === $entity_type );
				if ( $entity_guid_is_product && preg_match( '/^[0-9a-f-]{32,36}$/i', $candidate ) ) {
					$guid = $candidate;
				}
			}
			if ( '' === $guid ) {
				continue;
			}

			if ( in_array( $status, array( 'pending', 'processing' ), true ) ) {
				$out['active'][ $guid ] = true;
				continue;
			}

			$error = (string) ( isset( $row['last_error'] ) ? $row['last_error'] : '' );
			if ( 'UpdateVariant' === $event_type
				&& 'failed' === $status
				&& absint( isset( $row['try_count'] ) ? $row['try_count'] : 0 ) >= 5
				&& 0 === strpos( $error, 'Incomplete Mobo variant attributes.' ) ) {
				/* Retain bounded terminal ownership evidence per product. Selection of
				 * current-vs-historical ownership is performed against the current row
				 * ordering boundary below, so a later-arriving stale DB row cannot hide a
				 * valid current terminal event. */
				if ( ! isset( $out['terminal'][ $guid ] ) || ! is_array( $out['terminal'][ $guid ] ) ) {
					$out['terminal'][ $guid ] = array();
				}
				$out['terminal'][ $guid ][] = array(
					'id'           => absint( isset( $row['id'] ) ? $row['id'] : 0 ),
					'eventVersion' => sanitize_text_field( (string) ( isset( $row['event_version'] ) ? $row['event_version'] : '' ) ),
					'updatedAt'    => sanitize_text_field( (string) ( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ) ),
					'lastError'    => sanitize_textarea_field( substr( $error, 0, 500 ) ),
				);
			}
		}

		return $out;
	}

	/**
	 * Select the newest terminal event that is current for this product boundary.
	 *
	 * @param array $events Terminal ownership candidates in ascending DB id order.
	 * @param array $row Joined current Product Map/Health row.
	 * @param int   $product_id Canonical current product ID.
	 * @return array
	 */
	private static function find_current_terminal_event( $events, $row, $product_id ) {
		$matched = array();
		foreach ( is_array( $events ) ? $events : array() as $event ) {
			if ( is_array( $event ) && self::terminal_event_matches_current_boundary( $event, $row, $product_id ) ) {
				$matched = $event;
			}
		}
		return $matched;
	}

	/**
	 * Prove that a terminal UpdateVariant failure belongs to the current product
	 * ordering boundary rather than to an older historical incarnation.
	 *
	 * @param array $event Terminal ownership detail.
	 * @param array $row Joined current Product Map/Health row.
	 * @param int   $product_id Canonical current product ID.
	 * @return bool
	 */
	private static function terminal_event_matches_current_boundary( $event, $row, $product_id ) {
		$event_version = sanitize_text_field( (string) ( isset( $event['eventVersion'] ) ? $event['eventVersion'] : '' ) );
		if ( '' === $event_version ) {
			return false;
		}

		$compared = false;
		$current_versions = array(
			sanitize_text_field( (string) ( isset( $row['portal_version'] ) ? $row['portal_version'] : '' ) ),
			sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_product_applied_event_version', true ) ),
		);
		foreach ( $current_versions as $current_version ) {
			if ( '' === $current_version ) {
				continue;
			}
			$cmp = self::compare_versions( $event_version, $current_version );
			if ( null === $cmp ) {
				continue;
			}
			$compared = true;
			if ( $cmp < 0 ) {
				return false;
			}
		}
		if ( $compared ) {
			return true;
		}

		/* Numeric revision fallback for legacy numeric event versions. */
		if ( ctype_digit( $event_version ) ) {
			$event_revision = absint( $event_version );
			$current_revisions = array(
				absint( isset( $row['portal_revision'] ) ? $row['portal_revision'] : 0 ),
				absint( get_post_meta( $product_id, '_mobo_product_applied_revision', true ) ),
			);
			$revision_compared = false;
			foreach ( $current_revisions as $current_revision ) {
				if ( $current_revision <= 0 ) {
					continue;
				}
				$revision_compared = true;
				if ( $event_revision < $current_revision ) {
					return false;
				}
			}
			if ( $revision_compared ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Prove that an incomplete raw Health row is stale relative to current durable
	 * product state. A complete local product is not enough by itself; at least one
	 * ordering/hash/recreation proof must show that current state supersedes Health.
	 *
	 * @param array $row Joined map/health row.
	 * @param int   $product_id Canonical current product ID.
	 * @param bool  $post_incomplete Post metadata incomplete marker.
	 * @param bool  $map_incomplete Product Map incomplete marker.
	 * @param bool  $local_sane Local product structure result.
	 * @return bool
	 */
	private static function health_row_is_durable_residue( $row, $product_id, $post_incomplete, $map_incomplete, $local_sane ) {
		if ( $post_incomplete || $map_incomplete || ! $local_sane ) {
			return false;
		}

		$health_wp_id = absint( isset( $row['health_wp_product_id'] ) ? $row['health_wp_product_id'] : 0 );
		if ( $health_wp_id > 0 && $health_wp_id !== absint( $product_id ) ) {
			/* Same GUID now maps to a different live product: the Health row belongs to
			 * a superseded local incarnation and must not count as a current failure. */
			return true;
		}

		$health_revision = absint( isset( $row['portal_revision'] ) ? $row['portal_revision'] : 0 );
		$health_version = sanitize_text_field( (string) ( isset( $row['portal_version'] ) ? $row['portal_version'] : '' ) );
		$health_hash = sanitize_text_field( (string) ( isset( $row['portal_hash'] ) ? $row['portal_hash'] : '' ) );
		$map_hash = sanitize_text_field( (string) ( isset( $row['map_last_hash'] ) ? $row['map_last_hash'] : '' ) );
		$product_hash = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_product_source_hash', true ) );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		$is_variable = $product instanceof WC_Product_Variable;

		$ordering_evidence = false;
		$ordering_proves_applied = true;
		if ( $health_revision > 0 ) {
			$ordering_evidence = true;
			$product_revision = absint( get_post_meta( $product_id, '_mobo_product_applied_revision', true ) );
			$variant_revision = absint( get_post_meta( $product_id, '_mobo_variant_applied_revision', true ) );
			$ordering_proves_applied = $product_revision >= $health_revision
				&& ( ! $is_variable || $variant_revision >= $health_revision );
		}

		if ( '' !== $health_version ) {
			$product_version = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_product_applied_event_version', true ) );
			$variant_version = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_variant_applied_event_version', true ) );
			$product_cmp = self::compare_versions( $product_version, $health_version );
			$variant_cmp = $is_variable ? self::compare_versions( $variant_version, $health_version ) : 0;
			if ( null !== $product_cmp && null !== $variant_cmp ) {
				$ordering_evidence = true;
				$ordering_proves_applied = $ordering_proves_applied
					&& $product_cmp >= 0
					&& ( ! $is_variable || $variant_cmp >= 0 );
			}
		}

		$hash_matches = '' === $health_hash
			|| ( '' !== $map_hash && hash_equals( $health_hash, $map_hash ) )
			|| ( '' !== $product_hash && hash_equals( $health_hash, $product_hash ) );
		if ( $ordering_evidence && $ordering_proves_applied && $hash_matches ) {
			return true;
		}

		/* Legacy rows may have no comparable revision/version. A later canonical
		 * Product Map write is durable supersession evidence when current Product Map
		 * and product source hashes agree, even if the old Health row carried a
		 * different hash from an earlier failed source state.
		 * current-map-write-supersedes-health */
		$map_updated = isset( $row['map_updated_at'] ) ? strtotime( (string) $row['map_updated_at'] . ' UTC' ) : false;
		$health_updated = isset( $row['health_updated_at'] ) ? strtotime( (string) $row['health_updated_at'] . ' UTC' ) : false;
		$current_hashes_agree = '' !== $map_hash && '' !== $product_hash && hash_equals( $map_hash, $product_hash );
		if ( false !== $map_updated && false !== $health_updated && $map_updated > $health_updated ) {
			if ( ( '' !== $health_hash && $hash_matches ) || $current_hashes_agree ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Verify minimum local storefront structure before treating a variable product
	 * as structurally converged.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return bool
	 */
	public static function local_product_structure_is_sane( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		if ( ! $product instanceof WC_Product_Variable ) {
			return true;
		}

		$expected = array();
		foreach ( (array) $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$key = sanitize_title( (string) $attribute->get_name() );
			if ( '' !== $key ) {
				$options = array();
				$raw_options = method_exists( $attribute, 'get_slugs' )
					? (array) $attribute->get_slugs()
					: (array) $attribute->get_options();
				foreach ( $raw_options as $option ) {
					$option = sanitize_text_field( (string) $option );
					if ( '' !== $option ) {
						$options[] = $option;
					}
				}
				$expected[ $key ] = array_values( array_unique( $options ) );
			}
		}
		$expected_keys = array_keys( $expected );
		sort( $expected_keys, SORT_STRING );
		if ( empty( $expected_keys ) ) {
			return false;
		}

		$children = array_values( array_filter( array_map( 'absint', (array) $product->get_children() ) ) );
		if ( empty( $children ) ) {
			return false;
		}

		/* Prime variation posts/meta in bulk before object hydration. */
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $children, false, true );
		} elseif ( function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $children );
		}

		foreach ( $children as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation || absint( $variation->get_parent_id() ) !== absint( $product->get_id() ) ) {
				return false;
			}
			$actual = array();
			$variation_attributes = method_exists( $variation, 'get_variation_attributes' )
				? (array) $variation->get_variation_attributes( false )
				: (array) $variation->get_attributes( 'edit' );
			foreach ( $variation_attributes as $key => $value ) {
				$key = preg_replace( '/^attribute_/', '', sanitize_title( (string) $key ) );
				$value = sanitize_text_field( (string) $value );
				if ( '' === $key ) {
					return false;
				}
				/*
				 * WooCommerce stores an empty Variation attribute value as wildcard
				 * "Any". It is valid for that attribute key and must not make Sync
				 * Health report a malformed local structure.
				 */
				if ( '' !== $value
					&& isset( $expected[ $key ] )
					&& ! empty( $expected[ $key ] )
					&& ! in_array( $value, $expected[ $key ], true )
				) {
					return false;
				}
				$actual[ $key ] = true;
			}
			$actual_keys = array_keys( $actual );
			sort( $actual_keys, SORT_STRING );
			if ( $actual_keys !== $expected_keys ) {
				return false;
			}
		}
		return true;
	}

	/** Persist one canonical health row. */
	private static function upsert_health( $guid, $wp_id, $status, $revision, $hash, $error, $portal_id, $successful, $version = '', $stamp_sync_time = true ) {
		global $wpdb;
		$guid = sanitize_text_field( (string) $guid );
		if ( '' === $guid ) {
			return false;
		}
		$status = sanitize_key( (string) $status );
		if ( ! in_array( $status, array( 'synced', 'behind', 'repairing', 'failed' ), true ) ) {
			$status = 'behind';
		}
		if ( ! self::table_exists() ) {
			self::create_table();
		}
		if ( ! self::table_exists() ) {
			return false;
		}

		$table = self::table_name();
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, wp_product_id, portal_product_id, portal_revision, portal_version, portal_hash, sync_status FROM {$table} WHERE connection_id = %d AND product_guid = %s LIMIT 1", $connection_id, $guid ), ARRAY_A );
		$row_id = is_array( $existing ) ? absint( $existing['id'] ) : 0;
		$wp_id = absint( $wp_id );
		$portal_id = absint( $portal_id );
		$revision = absint( $revision );
		$version = sanitize_text_field( trim( (string) $version ) );
		$hash = sanitize_text_field( (string) $hash );

		$existing_revision = is_array( $existing ) ? absint( $existing['portal_revision'] ) : 0;
		$existing_version  = is_array( $existing ) && isset( $existing['portal_version'] ) ? sanitize_text_field( (string) $existing['portal_version'] ) : '';
		$existing_status   = is_array( $existing ) ? sanitize_key( (string) $existing['sync_status'] ) : '';
		$existing_wp_id    = is_array( $existing ) ? absint( $existing['wp_product_id'] ) : 0;

		/*
		 * Equal-revision terminal success normally stays sticky so a delayed partial
		 * callback cannot regress a product that already converged. There is one
		 * stronger local truth: an explicit durable mobo_sync_incomplete=1 marker on
		 * the same canonical product. In that case behind/failed health must be able
		 * to represent the current local desired-state boundary even when callers do
		 * not carry the canonical wp_id or original revision back into this layer.
		 *
		 * Scope the escape hatch to behind/failed only. Repairing remains protected,
		 * and an explicitly older incoming revision/version is still rejected below.
		 */
		$effective_wp_id = $wp_id > 0 ? $wp_id : $existing_wp_id;
		$local_incomplete_non_success = in_array( $status, array( 'behind', 'failed' ), true )
			&& $effective_wp_id > 0
			&& ( 0 === $existing_wp_id || $existing_wp_id === $effective_wp_id )
			&& 'product' === get_post_type( $effective_wp_id )
			&& '1' === (string) get_post_meta( $effective_wp_id, 'mobo_sync_incomplete', true );

		/* A delayed result must never regress a newer desired-state health row.
		 * Once versioned health exists, unversioned legacy retries also cannot mark
		 * it behind/failed unless the canonical product is durably incomplete.
		 * Equal-revision terminal success remains sticky otherwise. */
		if ( '' !== $existing_version ) {
			$version_cmp = self::compare_versions( $version, $existing_version );
			if ( '' !== $version && null === $version_cmp && $version !== $existing_version ) {
				/* Two different non-empty incomparable watermarks are not safely ordered.
				 * Keep the durable Health boundary unchanged rather than letting arrival
				 * order regress or advance state without proof. */
				return false;
			}
			if ( -1 === $version_cmp ) {
				return true;
			}
			if ( '' === $version && in_array( $status, array( 'behind', 'repairing', 'failed' ), true ) && ! $local_incomplete_non_success ) {
				return true;
			}
			if ( 0 === $version_cmp && 'synced' === $existing_status && 'synced' !== $status && ! $local_incomplete_non_success ) {
				return true;
			}
		}
		if ( $existing_revision > 0 ) {
			if ( $revision > 0 && $revision < $existing_revision ) {
				return true;
			}
			if ( 0 === $revision && '' === $version && in_array( $status, array( 'behind', 'repairing', 'failed' ), true ) && ! $local_incomplete_non_success ) {
				return true;
			}
			if ( $revision === $existing_revision && $revision > 0 && 'synced' === $existing_status && 'synced' !== $status && ! $local_incomplete_non_success ) {
				return true;
			}
		}

		$data = array(
			'connection_id'    => $connection_id,
			'product_guid'      => $guid,
			'wp_product_id'     => $wp_id > 0 ? $wp_id : ( is_array( $existing ) ? absint( $existing['wp_product_id'] ) : 0 ),
			'portal_product_id' => $portal_id > 0 ? $portal_id : ( is_array( $existing ) ? absint( $existing['portal_product_id'] ) : 0 ),
			'portal_revision'   => $revision > 0 ? $revision : ( is_array( $existing ) ? absint( $existing['portal_revision'] ) : 0 ),
			'portal_version'    => '' !== $version ? $version : $existing_version,
			'portal_hash'       => '' !== $hash ? $hash : ( is_array( $existing ) ? sanitize_text_field( (string) $existing['portal_hash'] ) : '' ),
			'sync_status'       => $status,
			'last_error'        => '' !== (string) $error ? sanitize_textarea_field( (string) $error ) : null,
			'updated_at'        => $now,
		);
		if ( $successful && $stamp_sync_time ) {
			$data['last_successful_sync_time'] = $now;
		}
		if ( $row_id ) {
			$written = $wpdb->update( $table, $data, array( 'id' => $row_id, 'connection_id' => $connection_id ) );
		} else {
			$data['created_at'] = $now;
			if ( ! isset( $data['last_successful_sync_time'] ) ) {
				$data['last_successful_sync_time'] = null;
			}
			$written = $wpdb->insert( $table, $data );
		}
		if ( false === $written ) {
			return false;
		}

		/* Health is a correctness boundary for offline catch-up diagnostics. Verify
		 * the canonical table row before mirroring convenience metadata to posts. */
		$persisted = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT product_guid, wp_product_id, portal_product_id, portal_revision, portal_version, portal_hash, sync_status FROM {$table} WHERE connection_id = %d AND product_guid = %s LIMIT 1",
				$connection_id,
				$guid
			),
			ARRAY_A
		);
		if ( ! is_array( $persisted )
			|| (string) $persisted['product_guid'] !== $guid
			|| absint( $persisted['wp_product_id'] ) !== absint( $data['wp_product_id'] )
			|| absint( $persisted['portal_product_id'] ) !== absint( $data['portal_product_id'] )
			|| absint( $persisted['portal_revision'] ) !== absint( $data['portal_revision'] )
			|| (string) $persisted['portal_version'] !== (string) $data['portal_version']
			|| (string) $persisted['portal_hash'] !== (string) $data['portal_hash']
			|| sanitize_key( (string) $persisted['sync_status'] ) !== $status ) {
			return false;
		}

		$stored_wp_id = absint( $data['wp_product_id'] );
		if ( $stored_wp_id > 0 ) {
			update_post_meta( $stored_wp_id, '_mobo_portal_revision', absint( $data['portal_revision'] ) );
			update_post_meta( $stored_wp_id, '_mobo_portal_version', sanitize_text_field( (string) $data['portal_version'] ) );
			update_post_meta( $stored_wp_id, '_mobo_sync_status', $status );
			update_post_meta( $stored_wp_id, '_mobo_sync_last_error', sanitize_textarea_field( (string) $error ) );
			if ( $successful && $stamp_sync_time ) {
				update_post_meta( $stored_wp_id, '_mobo_last_successful_sync_time', gmdate( 'c' ) );
			}
		}
		return true;
	}

	/** Locate a local product from the indexed map or health table. */
	private static function find_local_product_id( $guid, $portal_id ) {
		global $wpdb;
		$guid = sanitize_text_field( (string) $guid );
		$portal_id = absint( $portal_id );
		if ( class_exists( 'Mobo_Core_Product_Map' ) ) {
			$map = new Mobo_Core_Product_Map();
			if ( '' !== $guid ) {
				$id = $map->get_product_id( $guid );
				if ( $id > 0 ) {
					return $id;
				}
			}
		}
		if ( ! self::table_exists() ) {
			return 0;
		}
		$table = self::table_name();
		$connection_id = self::connection_id();
		if ( '' !== $guid ) {
			$id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT wp_product_id FROM {$table} WHERE connection_id = %d AND product_guid = %s LIMIT 1", $connection_id, $guid ) ) );
		} elseif ( $portal_id > 0 ) {
			$id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT wp_product_id FROM {$table} WHERE connection_id = %d AND portal_product_id = %d AND wp_product_id > 0 ORDER BY updated_at DESC, id DESC LIMIT 1", $connection_id, $portal_id ) ) );
		} else {
			$id = 0;
		}
		return $id > 0 && 'product' === get_post_type( $id ) && ! in_array( get_post_status( $id ), array( 'trash', 'auto-draft' ), true ) ? $id : 0;
	}

	private static function extract_product_guid_recursive( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		foreach ( array( 'productId', 'productGuid', 'product_guid', 'guid' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$guid = sanitize_text_field( (string) $data[ $key ] );
				if ( preg_match( '/^[0-9a-f-]{32,36}$/i', $guid ) ) {
					return $guid;
				}
			}
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$found = self::extract_product_guid_recursive( $value );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}
		return '';
	}
}
