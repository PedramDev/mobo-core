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
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
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
			UNIQUE KEY product_guid (product_guid),
			KEY wp_product_id (wp_product_id),
			KEY portal_product_id (portal_product_id),
			KEY sync_status (sync_status),
			KEY portal_revision (portal_revision)
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
	public static function mark_synced( $guid, $wp_id = 0, $revision = 0, $hash = '', $portal_id = 0, $version = '' ) {
		return self::upsert_health( $guid, $wp_id, 'synced', $revision, $hash, '', $portal_id, true, $version );
	}

	/**
	 * Mark a product sync as failed.
	 */
	public static function mark_failed( $guid, $wp_id = 0, $error = '', $revision = 0, $portal_id = 0, $version = '' ) {
		return self::upsert_health( $guid, $wp_id, 'failed', $revision, '', $error, $portal_id, false, $version );
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
			$rows = $wpdb->get_results( "SELECT sync_status, COUNT(*) AS total FROM {$table} GROUP BY sync_status", ARRAY_A );
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
	public static function get_operational_dashboard_status( $detail_limit = 15, $deep_structure = true ) {
		/* MOBO-HEALTH-SYNC-LICENSE-HARDENING-r2: durable operational snapshot. */
		$mobo_operational_limit = max( 1, absint( $limit ) );
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
			'converged'                => 0,
			'activeRetryOwner'         => 0,
			'terminalUpstreamInvalid'  => 0,
			'unownedConvergenceGap'    => 0,
			'needsAttention'           => 0,
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
		$result['activeRetryRows'] = array();
		$result['terminalRows'] = array();

		if ( ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists() ) {
			$result['operationalReason'] = 'product-map-unavailable';
			return $result;
		}

		$map_table = preg_replace( '/[^A-Za-z0-9_]/', '', Mobo_Core_Product_Map::table_name() );
		$health_table = self::table_exists() ? preg_replace( '/[^A-Za-z0-9_]/', '', self::table_name() ) : '';
		$health_select = '' !== $health_table
			? ", h.id AS health_id, h.wp_product_id AS health_wp_product_id, h.portal_revision, h.portal_version, h.portal_hash, h.sync_status, h.last_error, h.updated_at AS health_updated_at"
			: ", 0 AS health_id, 0 AS health_wp_product_id, 0 AS portal_revision, '' AS portal_version, '' AS portal_hash, '' AS sync_status, NULL AS last_error, NULL AS health_updated_at";
		$health_join = '' !== $health_table
			? " LEFT JOIN `{$health_table}` h ON h.product_guid = m.remote_guid"
			: '';

		$rows = $wpdb->get_results(
			"SELECT m.remote_guid, m.wp_post_id, m.last_hash AS map_last_hash, m.sync_incomplete AS map_sync_incomplete, m.updated_at AS map_updated_at{$health_select}
			 FROM `{$map_table}` m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.wp_post_id
			 {$health_join}
			 WHERE m.object_type = 'product'
			   AND m.wp_post_id > 0
			   AND p.post_type = 'product'
			   AND p.post_status NOT IN ('trash','auto-draft')
			 ORDER BY m.id ASC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
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
		foreach ( $rows as $row ) {
			$guid = sanitize_text_field( (string) ( isset( $row['remote_guid'] ) ? $row['remote_guid'] : '' ) );
			if ( '' !== $guid ) {
				$current_guids[ $guid ] = true;
			}
		}

		$event_ownership = self::load_operational_event_ownership();
		$active_events = isset( $event_ownership['active'] ) && is_array( $event_ownership['active'] ) ? $event_ownership['active'] : array();
		$terminal_events = isset( $event_ownership['terminal'] ) && is_array( $event_ownership['terminal'] ) ? $event_ownership['terminal'] : array();
		$limit = max( 0, absint( $detail_limit ) );

		foreach ( $rows as $row ) {
			$guid = sanitize_text_field( (string) ( isset( $row['remote_guid'] ) ? $row['remote_guid'] : '' ) );
			$product_id = absint( isset( $row['wp_post_id'] ) ? $row['wp_post_id'] : 0 );
			$health_id = absint( isset( $row['health_id'] ) ? $row['health_id'] : 0 );
			$health_status = sanitize_key( (string) ( isset( $row['sync_status'] ) ? $row['sync_status'] : '' ) );
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
			$has_active_owner = '' !== $guid && ! empty( $active_events[ $guid ] );
			$current_incomplete_signal = $post_incomplete
				|| $map_incomplete
				|| in_array( $health_status, array( 'behind', 'repairing', 'failed' ), true );
			$terminal_candidates = '' !== $guid && isset( $terminal_events[ $guid ] ) && is_array( $terminal_events[ $guid ] )
				? $terminal_events[ $guid ]
				: array();
			$terminal_event = ! empty( $terminal_candidates ) ? end( $terminal_candidates ) : array();
			/* current-boundary-terminal-owns-local-structure-gap
			 * r4: a synced row can still be upstream-blocked when its durable local
			 * variable structure is malformed. Do not accept any historical terminal
			 * failure blindly: at least one terminal event must match or supersede the
			 * current product/Health ordering boundary. Existing explicit incomplete/
			 * failed signals retain the r3 ownership semantics. */
			$current_terminal_event = self::find_current_terminal_event( $terminal_candidates, $row, $product_id );
			$terminal_matches_current_boundary = ! empty( $current_terminal_event );
			if ( $terminal_matches_current_boundary ) {
				$terminal_event = $current_terminal_event;
			}
			$has_terminal_upstream = ! $has_active_owner
				&& ! empty( $terminal_candidates )
				&& ( $current_incomplete_signal || ( ! $local_sane && $terminal_matches_current_boundary ) );

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

				$operational['unownedConvergenceGap']++;
				if ( count( $result['unownedRows'] ) < $limit ) {
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

			$operational['unownedConvergenceGap']++;
			if ( count( $result['unownedRows'] ) < $limit ) {
				$result['unownedRows'][] = $detail;
			}
		}

		/* Health rows not belonging to a current live canonical Product Map row are
		 * historical/non-live observations. They remain queryable but are excluded
		 * from operational product-health counts. */
		if ( '' !== $health_table ) {
			$incomplete_rows = $wpdb->get_results(
				"SELECT product_guid FROM `{$health_table}` WHERE sync_status IN ('behind','repairing','failed')",
				ARRAY_A
			);
			foreach ( is_array( $incomplete_rows ) ? $incomplete_rows : array() as $health_row ) {
				$guid = sanitize_text_field( (string) ( isset( $health_row['product_guid'] ) ? $health_row['product_guid'] : '' ) );
				if ( '' === $guid || empty( $current_guids[ $guid ] ) ) {
					$operational['historicalNonLive']++;
				}
			}
		}

		$operational['needsAttention'] = absint( $operational['activeRetryOwner'] ) + absint( $operational['unownedConvergenceGap'] );
		$operational['notFullyConverged'] = absint( $operational['needsAttention'] ) + absint( $operational['terminalUpstreamInvalid'] );
		$operational['historicalExcluded'] = absint( $operational['historicalHealthResidue'] ) + absint( $operational['historicalNonLive'] );

		/* Keep the current-product accounting closed. Any future classifier branch
		 * that forgets to account for a mapped live product becomes visible here. */
		$accounted = absint( $operational['converged'] )
			+ absint( $operational['activeRetryOwner'] )
			+ absint( $operational['terminalUpstreamInvalid'] )
			+ absint( $operational['unownedConvergenceGap'] );
		$operational['accountingDelta'] = absint( $operational['currentProducts'] ) - $accounted;

		$result['operational'] = $operational;
		$result['pendingSync'] = absint( $operational['needsAttention'] );
		$result['pendingRepair'] = absint( $operational['needsAttention'] ); // Backward-compatible field; no Repair is scheduled.
		$result['operationalStatus'] = $operational['accountingDelta'] !== 0
			? 'accounting-error'
			: ( $operational['unownedConvergenceGap'] > 0 ? 'degraded-unowned' : ( $operational['activeRetryOwner'] > 0 ? 'progressing' : ( $operational['terminalUpstreamInvalid'] > 0 ? 'blocked-upstream' : 'clear' ) ) );
		$result['operationalReason'] = 'current-product-map';
		$result['structureAuditMode'] = $deep_structure ? 'deep' : 'targeted';

		if ( class_exists( 'Mobo_Core_Health_Read_Cache' ) ) {
			Mobo_Core_Health_Read_Cache::store_sync_health_operational_snapshot( $mobo_operational_limit, $result );
		}
		return $result;
	}

	/**
	 * Bulk-load durable webhook ownership for current product convergence.
	 *
	 * @return array
	 */
	private static function load_operational_event_ownership() {
		global $wpdb;
		$out = array( 'active' => array(), 'terminal' => array() );
		if ( ! class_exists( 'Mobo_Core_Sync_Event_Store' ) || ! Mobo_Core_Sync_Event_Store::table_exists() ) {
			return $out;
		}

		$table = preg_replace( '/[^A-Za-z0-9_]/', '', Mobo_Core_Sync_Event_Store::table_name() );
		$rows = $wpdb->get_results(
			"SELECT id, event_type, entity_type, entity_guid, event_version, status, try_count, payload_json, last_error, updated_at
			 FROM `{$table}`
			 WHERE event_type IN ('ProductUpdated','UpdateVariant')
			   AND (
				status IN ('pending','processing')
				OR (event_type='UpdateVariant' AND status='failed' AND try_count>=5 AND last_error LIKE 'Incomplete Mobo variant attributes.%')
			   )
			 ORDER BY id ASC",
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
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
			foreach ( (array) $variation->get_attributes( 'edit' ) as $key => $value ) {
				$key = preg_replace( '/^attribute_/', '', sanitize_title( (string) $key ) );
				$value = sanitize_text_field( (string) $value );
				if ( '' === $key || '' === $value ) {
					return false;
				}
				if ( isset( $expected[ $key ] ) && ! empty( $expected[ $key ] ) && ! in_array( $value, $expected[ $key ], true ) ) {
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
	private static function upsert_health( $guid, $wp_id, $status, $revision, $hash, $error, $portal_id, $successful, $version = '' ) {
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
		$now = current_time( 'mysql', true );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, wp_product_id, portal_product_id, portal_revision, portal_version, portal_hash, sync_status FROM {$table} WHERE product_guid = %s LIMIT 1", $guid ), ARRAY_A );
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
		if ( $successful ) {
			$data['last_successful_sync_time'] = $now;
		}
		if ( $row_id ) {
			$written = $wpdb->update( $table, $data, array( 'id' => $row_id ) );
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
				"SELECT product_guid, wp_product_id, portal_product_id, portal_revision, portal_version, portal_hash, sync_status FROM {$table} WHERE product_guid = %s LIMIT 1",
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
			if ( $successful ) {
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
		if ( '' !== $guid ) {
			$id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT wp_product_id FROM {$table} WHERE product_guid = %s LIMIT 1", $guid ) ) );
		} elseif ( $portal_id > 0 ) {
			$id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT wp_product_id FROM {$table} WHERE portal_product_id = %d AND wp_product_id > 0 ORDER BY updated_at DESC, id DESC LIMIT 1", $portal_id ) ) );
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
