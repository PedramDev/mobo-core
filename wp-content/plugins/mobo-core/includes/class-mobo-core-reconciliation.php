<?php
/**
 * Adaptive reconciliation and per-product sync health.
 *
 * Webhooks, automatic recovery and manual repair all converge on
 * Mobo_Core_Product_Sync's desired-state product/variation processors.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
class Mobo_Core_Reconciliation {

	const STATE_OPTION             = 'mobo_core_reconciliation_state';
	const REVISION_OPTION          = 'mobo_core_reconciliation_revision';
	const FALLBACK_CURSOR_OPTION   = 'mobo_core_reconciliation_fallback_cursor';
	const LAST_CHECK_OPTION        = 'mobo_core_reconciliation_last_check_at';
	const LAST_SUCCESS_OPTION      = 'mobo_core_reconciliation_last_success_at';
	const LAST_DEEP_OPTION         = 'mobo_core_reconciliation_last_deep_at';
	const LAST_RESULT_OPTION       = 'mobo_core_reconciliation_last_result';
	const ENDPOINT_SUPPORT_OPTION  = 'mobo_core_reconciliation_changes_endpoint';

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
	}

	/**
	 * Run one bounded recovery slice.
	 *
	 * @param string $source Source label.
	 * @param bool   $force Force fast check.
	 * @param bool   $force_deep Force deep check.
	 * @return array
	 */
	public function run_tick( $source = 'real-cron', $force = false, $force_deep = false ) {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return array_merge( Mobo_Core_Upgrade_Coordinator::paused_result( 'reconciliation' ), array( 'processedProducts' => 0, 'processedVariations' => 0, 'needsContinuation' => false ) );
		}

		$source     = sanitize_key( (string) $source );
		$force      = (bool) $force;
		$force_deep = (bool) $force_deep;
		$state      = $this->get_state();

		if ( 'idle' === $state['status'] && ! $force && ! $force_deep && ! Mobo_Core_Settings::enabled( 'mobo_core_auto_reconciliation_enabled', '1' ) ) {
			return $this->finish_result( array( 'success' => true, 'status' => 'disabled', 'source' => $source ) );
		}

		$sync = new Mobo_Core_Product_Sync();
		$manual_status = $sync->get_manual_sync_status();
		if ( ! empty( $manual_status['isRunning'] ) || ! empty( $manual_status['isWaitingForPortal'] ) ) {
			return $this->finish_result(
				array(
					'success' => true,
					'status'  => 'manual-sync-busy',
					'source'  => $source,
					'sync'    => $manual_status,
				)
			);
		}

		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'adaptive_reconciliation', 180 ) : 'no-lock';
		if ( false === $lock ) {
			return $this->finish_result( array( 'success' => true, 'status' => 'locked', 'source' => $source ) );
		}

		try {
			if ( 'running' !== $state['status'] ) {
				if ( $force_deep ) {
					$state = $this->start_deep_state( $source );
				} elseif ( $force ) {
					$state = $this->start_fast_state( $source );
				} elseif ( $this->is_deep_due() ) {
					$state = $this->start_deep_state( $source );
				} elseif ( $this->is_fast_due() ) {
					$state = $this->start_fast_state( $source );
				} else {
					return $this->finish_result(
						array(
							'success' => true,
							'status'  => 'not-due',
							'source'  => $source,
							'nextCheckAt' => $this->get_next_check_at(),
						)
					);
				}
			}

			$result = $this->process_state( $state, $source );
			$this->save_state( $state );
			return $this->finish_result( $result );
		} catch ( Throwable $e ) {
			$state['lastError'] = sanitize_text_field( $e->getMessage() );
			$state['updatedAt'] = time();
			$this->save_state( $state );
			return $this->finish_result(
				array(
					'success' => false,
					'status'  => 'exception',
					'source'  => $source,
					'error'   => $e->getMessage(),
				)
			);
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && 'no-lock' !== $lock ) {
				Mobo_Core_Lock::release( 'adaptive_reconciliation', $lock );
			}
		}
	}

	/**
	 * Start a fast check. Prefer revision endpoint, then use a bounded rolling scan.
	 *
	 * @param string $source Source.
	 * @return array
	 */
	private function start_fast_state( $source ) {
		$now   = time();
		$limit = Mobo_Core_Settings::get_int( 'mobo_core_reconciliation_products_per_run', 100, 10, 500 );
		$api   = new Mobo_Core_API_Client();
		$after = absint( get_option( self::REVISION_OPTION, 0 ) );

		update_option( self::LAST_CHECK_OPTION, $now, false );

		$response = $api->get_sync_changes( $after, $limit );
		if ( ! is_wp_error( $response ) && $this->looks_like_changes_response( $response ) ) {
			update_option( self::ENDPOINT_SUPPORT_OPTION, 'supported', false );
			$change_payload = $this->get_changes_payload( $response );
			$changes = $this->normalize_changes( $change_payload );
			$state   = $this->get_state_defaults();
			$state['status']          = 'running';
			$state['mode']            = 'fast-changes';
			$state['source']          = $source;
			$state['startedAt']       = $now;
			$state['updatedAt']       = $now;
			$state['pending']         = $changes;
			$state['afterRevision']   = $after;
			$state['currentRevision'] = absint( $this->get_value( $change_payload, 'currentRevision', $after ) );
			$state['moreChanges']     = count( $changes ) >= $limit;
			$state['lastMessage']     = 'Revision-based reconciliation started.';
			return $state;
		}

		update_option( self::ENDPOINT_SUPPORT_OPTION, is_wp_error( $response ) ? $response->get_error_code() : 'unsupported-shape', false );
		$cursor   = absint( get_option( self::FALLBACK_CURSOR_OPTION, 0 ) );
		$fallback = $api->get_products_page( 1, $limit, 'auto-fallback-' . gmdate( 'YmdHis' ), $cursor, true );
		$state    = $this->get_state_defaults();
		$state['status']    = 'running';
		$state['mode']      = 'fast-fallback';
		$state['source']    = $source;
		$state['startedAt'] = $now;
		$state['updatedAt'] = $now;
		$state['scanCursor'] = $cursor;

		if ( is_wp_error( $fallback ) ) {
			$state['status']    = 'idle';
			$state['lastError'] = $fallback->get_error_message();
			$state['lastMessage'] = 'Fast reconciliation failed before catalog scan.';
			return $state;
		}

		$items = $this->get_value( $fallback, 'data', array() );
		$items = is_array( $items ) ? $items : array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$pending = $this->build_pending_from_product( $item, 0 );
			if ( $this->should_skip_unchanged_product( $pending ) ) {
				continue;
			}
			$state['pending'][] = $pending;
		}

		$state['nextScanCursor'] = absint( $this->get_value( $fallback, 'nextCursor', $cursor ) );
		$state['scanHasMore']     = $this->to_bool( $this->get_value( $fallback, 'hasMore', false ) );
		$state['lastMessage']     = 'Rolling fallback reconciliation started.';
		return $state;
	}

	/**
	 * Start a complete integrity scan, processed in bounded pages.
	 *
	 * @param string $source Source.
	 * @return array
	 */
	private function start_deep_state( $source ) {
		$state = $this->get_state_defaults();
		$state['status']      = 'running';
		$state['mode']        = 'deep';
		$state['phase']       = 'catalog';
		$state['source']      = sanitize_key( (string) $source );
		$state['startedAt']   = time();
		$state['updatedAt']   = time();
		$state['scanCursor']  = 0;
		$state['deepSeen']    = array();
		$state['lastMessage'] = 'Deep integrity check started.';
		update_option( self::LAST_CHECK_OPTION, time(), false );
		return $state;
	}

	/**
	 * Process one bounded state slice.
	 *
	 * @param array  $state State by reference.
	 * @param string $source Source.
	 * @return array
	 */
	private function process_state( &$state, $source ) {
		$product_budget   = Mobo_Core_Settings::get_int( 'mobo_core_reconciliation_products_per_run', 100, 10, 500 );
		$variation_budget = Mobo_Core_Settings::get_int( 'mobo_core_reconciliation_variation_batch', 1000, 100, 10000 );
		$processed        = 0;
		$variations       = 0;
		$failed           = 0;
		$deadline         = microtime( true ) + min( 18, Mobo_Core_Settings::get_int( 'mobo_core_real_cron_time_budget_seconds', 25, 5, 55 ) - 2 );

		while ( microtime( true ) < $deadline && $processed < $product_budget && $variations < $variation_budget ) {
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				$state['lastMessage'] = 'Reconciliation paused at a safe boundary for plugin upgrade.';
				break;
			}
			if ( empty( $state['pending'] ) ) {
				if ( 'deep' === $state['mode'] && 'catalog' === $state['phase'] ) {
					$filled = $this->fill_deep_catalog_page( $state, $product_budget );
					if ( is_wp_error( $filled ) ) {
						$state['lastError'] = $filled->get_error_message();
						$failed++;
						break;
					}
					if ( empty( $state['pending'] ) && 'sweep' !== $state['phase'] ) {
						break;
					}
				} elseif ( 'deep' === $state['mode'] && 'sweep' === $state['phase'] ) {
					$sweep = $this->process_deep_sweep( $state, $product_budget - $processed );
					$processed += absint( $sweep['processed'] );
					if ( ! empty( $sweep['done'] ) ) {
						$this->complete_state( $state, true, 'Deep integrity check completed.' );
						update_option( self::LAST_DEEP_OPTION, time(), false );
					}
					break;
				} elseif ( 'fast-changes' === $state['mode'] && ! empty( $state['moreChanges'] ) ) {
					if ( ! empty( $state['revisionFailed'] ) ) {
						$this->complete_revision_state( $state, 'Revision reconciliation stopped for retry.' );
						break;
					}
					$filled = $this->fill_more_changes( $state, $product_budget );
					if ( is_wp_error( $filled ) ) {
						$state['lastError'] = $filled->get_error_message();
						$failed++;
						break;
					}
					if ( empty( $state['pending'] ) && empty( $state['moreChanges'] ) ) {
						$this->complete_revision_state( $state, 'Fast revision reconciliation completed.' );
						break;
					}
				} else {
					if ( 'fast-changes' === $state['mode'] ) {
						$this->complete_revision_state( $state, 'Fast revision reconciliation completed.' );
					} elseif ( 'fast-fallback' === $state['mode'] ) {
						$next = ! empty( $state['scanHasMore'] ) ? absint( $state['nextScanCursor'] ) : 0;
						update_option( self::FALLBACK_CURSOR_OPTION, $next, false );
						$this->complete_state( $state, true, 'Rolling fallback reconciliation completed.' );
					} else {
						$this->complete_state( $state, true, 'Reconciliation slice completed.' );
					}
					break;
				}
			}

			if ( empty( $state['pending'] ) ) {
				continue;
			}

			$item_result = $this->process_pending_item( $state['pending'][0], $variation_budget - $variations );
			$variations += absint( $item_result['variations'] );

			if ( ! empty( $item_result['complete'] ) ) {
				$item = array_shift( $state['pending'] );
				$processed++;
				if ( ! empty( $item_result['failed'] ) ) {
					$failed++;
					if ( 'fast-changes' === $state['mode'] ) {
						$state['revisionFailed'] = true;
					}
				}
				if ( empty( $item_result['failed'] ) && empty( $state['revisionFailed'] ) ) {
					$revision = max( absint( $state['afterRevision'] ), absint( isset( $item['revision'] ) ? $item['revision'] : 0 ) );
					if ( $revision > absint( $state['afterRevision'] ) ) {
						$state['afterRevision'] = $revision;
						update_option( self::REVISION_OPTION, $revision, false );
					}
				}
			} else {
				$state['pending'][0] = $item_result['item'];
				if ( ! empty( $item_result['blocked'] ) || 0 === absint( $item_result['variations'] ) ) {
					break;
				}
			}
		}

		if ( 'fast-fallback' === $state['mode'] && empty( $state['pending'] ) && 'running' === $state['status'] ) {
			$next = ! empty( $state['scanHasMore'] ) ? absint( $state['nextScanCursor'] ) : 0;
			update_option( self::FALLBACK_CURSOR_OPTION, $next, false );
			$this->complete_state( $state, true, 'Rolling fallback reconciliation completed.' );
		}

		$state['processedProducts']  = absint( $state['processedProducts'] ) + $processed;
		$state['processedVariations'] = absint( $state['processedVariations'] ) + $variations;
		$state['failedProducts']      = absint( $state['failedProducts'] ) + $failed;
		$state['updatedAt']           = time();

		return array(
			'success'             => 0 === absint( $state['failedProducts'] ),
			'status'              => $state['status'],
			'mode'                => $state['mode'],
			'phase'               => $state['phase'],
			'source'              => $source,
			'processedProducts'   => $processed,
			'processedVariations' => $variations,
			'failedProducts'      => $failed,
			'pendingProducts'     => count( $state['pending'] ),
			'needsContinuation'   => 'running' === $state['status'],
			'lastError'           => $state['lastError'],
		);
	}

	/**
	 * Process one product through the existing desired-state engine.
	 *
	 * @param array $item Pending item by value.
	 * @param int   $remaining_variation_budget Remaining variation budget.
	 * @return array
	 */
	private function process_pending_item( $item, $remaining_variation_budget ) {
		$item = wp_parse_args(
			is_array( $item ) ? $item : array(),
			array(
				'productGuid' => '',
				'portalProductId' => 0,
				'revision' => 0,
				'deleted' => false,
				'parentDone' => false,
				'productPayload' => array(),
				'variantPage' => 1,
				'variantCursor' => 0,
				'syncId' => '',
				'portalHash' => '',
				'hashParts' => array(),
			)
		);

		$guid      = sanitize_text_field( (string) $item['productGuid'] );
		$portal_id = absint( $item['portalProductId'] );
		$revision  = absint( $item['revision'] );

		if ( ! empty( $item['deleted'] ) ) {
			$this->delete_local_product( $guid, $portal_id );
			return array( 'complete' => true, 'failed' => false, 'variations' => 0, 'item' => $item );
		}

		if ( '' !== $guid ) {
			self::mark_repairing( $guid, 0, $revision, sanitize_text_field( (string) $item['portalHash'] ), $portal_id );
		}

		$sync = new Mobo_Core_Product_Sync();
		$api  = new Mobo_Core_API_Client();

		if ( empty( $item['parentDone'] ) ) {
			$payload = is_array( $item['productPayload'] ) ? $item['productPayload'] : array();
			if ( empty( $payload ) ) {
				$response = '' !== $guid
					? $api->get_product_by_guid( $guid, 'auto-reconcile-' . gmdate( 'YmdHis' ) )
					: $api->get_product_by_portal_id( $portal_id, 'auto-reconcile-' . gmdate( 'YmdHis' ) );

				if ( is_wp_error( $response ) ) {
					self::mark_failed( $guid, 0, $response->get_error_message(), $revision, $portal_id );
					return array( 'complete' => true, 'failed' => true, 'variations' => 0, 'item' => $item );
				}

				$products = $this->get_value( $response, 'data', array() );
				if ( ! is_array( $products ) || empty( $products ) ) {
					$this->delete_local_product( $guid, $portal_id );
					return array( 'complete' => true, 'failed' => false, 'variations' => 0, 'item' => $item );
				}

				$product = reset( $products );
				if ( ! is_array( $product ) ) {
					self::mark_failed( $guid, 0, 'Invalid product snapshot.', $revision, $portal_id );
					return array( 'complete' => true, 'failed' => true, 'variations' => 0, 'item' => $item );
				}
				$guid = $this->extract_product_guid( $product, $guid );
				$portal_id = $this->extract_portal_product_id( $product, $portal_id );
				$item['productGuid'] = $guid;
				$item['portalProductId'] = $portal_id;
				$item['portalHash'] = $this->extract_portal_hash( $product, $item['portalHash'] );
				$payload = array( 'data' => array( $product ) );
			}

			$item['hashParts'][] = $this->stable_hash( $payload );
			$result = $sync->process_product_updated_payload( $payload );
			if ( empty( $result['success'] ) ) {
				$error = isset( $result['message'] ) ? (string) $result['message'] : 'Product desired-state sync failed.';
				self::mark_failed( $guid, 0, $error, $revision, $portal_id );
				return array( 'complete' => true, 'failed' => true, 'variations' => 0, 'item' => $item );
			}

			$delete_file = isset( $result['data']['deleteFile'] ) ? (bool) $result['data']['deleteFile'] : true;
			if ( ! $delete_file ) {
				$item['productPayload'] = $payload;
				return array( 'complete' => false, 'failed' => false, 'blocked' => true, 'variations' => 0, 'item' => $item );
			}

			$item['parentDone']     = true;
			$item['productPayload'] = array();
			if ( '' === $item['syncId'] ) {
				$item['syncId'] = wp_generate_uuid4();
			}
		}

		$page_limit = min( 200, max( 1, absint( $remaining_variation_budget ) ) );
		$response   = $api->get_variants_page(
			$item['productGuid'],
			max( 1, absint( $item['variantPage'] ) ),
			$page_limit,
			$item['syncId'],
			absint( $item['variantCursor'] ),
			Mobo_Core_Settings::enabled( 'mobo_core_variant_cursor_sync_enabled', '1' )
		);

		if ( is_wp_error( $response ) ) {
			self::mark_failed( $item['productGuid'], 0, $response->get_error_message(), $revision, $portal_id );
			return array( 'complete' => true, 'failed' => true, 'variations' => 0, 'item' => $item );
		}

		$item['hashParts'][] = $this->stable_hash( $response );
		$response['variantListAuthoritative'] = true;
		$response['isFullVariantSnapshot']    = true;
		$result = $sync->process_update_variant_payload( $response, true );
		if ( empty( $result['success'] ) ) {
			$error = isset( $result['message'] ) ? (string) $result['message'] : 'Variation desired-state sync failed.';
			self::mark_failed( $item['productGuid'], 0, $error, $revision, $portal_id );
			return array( 'complete' => true, 'failed' => true, 'variations' => 0, 'item' => $item );
		}

		$items       = $this->get_value( $response, 'data', array() );
		$count       = is_array( $items ) ? count( $items ) : 0;
		$has_more    = $this->to_bool( $this->get_value( $response, 'hasMore', false ) );
		$next_cursor = absint( $this->get_value( $response, 'nextCursor', $item['variantCursor'] ) );
		$item['variantCursor'] = $next_cursor;
		$item['variantPage']    = absint( $item['variantPage'] ) + 1;

		if ( $has_more ) {
			return array( 'complete' => false, 'failed' => false, 'blocked' => false, 'variations' => $count, 'item' => $item );
		}

		$wp_id = $this->find_local_product_id( $item['productGuid'], $portal_id );
		$hash  = '' !== (string) $item['portalHash']
			? sanitize_text_field( (string) $item['portalHash'] )
			: hash( 'sha256', implode( '|', array_map( 'strval', (array) $item['hashParts'] ) ) );
		self::mark_synced( $item['productGuid'], $wp_id, $revision, $hash, $portal_id );
		return array( 'complete' => true, 'failed' => false, 'variations' => $count, 'item' => $item );
	}

	/**
	 * Fill one deep catalog page.
	 *
	 * @param array $state State by reference.
	 * @param int   $limit Limit.
	 * @return true|WP_Error
	 */
	private function fill_deep_catalog_page( &$state, $limit ) {
		$api = new Mobo_Core_API_Client();
		$response = $api->get_products_page( 1, $limit, 'deep-integrity-' . gmdate( 'YmdHis' ), absint( $state['scanCursor'] ), true );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = $this->get_value( $response, 'data', array() );
		$items = is_array( $items ) ? $items : array();
		foreach ( $items as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$item = $this->build_pending_from_product( $product, 0 );
			if ( '' !== $item['productGuid'] ) {
				$state['deepSeen'][ $item['productGuid'] ] = 1;
			}
			$state['pending'][] = $item;
		}

		$has_more = $this->to_bool( $this->get_value( $response, 'hasMore', false ) );
		$state['scanCursor'] = absint( $this->get_value( $response, 'nextCursor', $state['scanCursor'] ) );
		if ( empty( $items ) || ! $has_more ) {
			$state['phase']       = 'sweep';
			$state['sweepCursor'] = 0;
		}
		return true;
	}

	/**
	 * Fetch next revision page after all current changes were applied.
	 *
	 * @param array $state State by reference.
	 * @param int   $limit Limit.
	 * @return true|WP_Error
	 */
	private function fill_more_changes( &$state, $limit ) {
		$api      = new Mobo_Core_API_Client();
		$response = $api->get_sync_changes( absint( $state['afterRevision'] ), $limit );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$change_payload = $this->get_changes_payload( $response );
		$changes = $this->normalize_changes( $change_payload );
		$state['pending'] = $changes;
		$state['currentRevision'] = absint( $this->get_value( $change_payload, 'currentRevision', $state['currentRevision'] ) );
		$state['moreChanges'] = count( $changes ) >= $limit;
		if ( empty( $changes ) ) {
			$state['moreChanges'] = false;
		}
		return true;
	}

	/**
	 * Sweep mapped local products after a complete deep catalog scan.
	 *
	 * @param array $state State by reference.
	 * @param int   $limit Limit.
	 * @return array
	 */
	private function process_deep_sweep( &$state, $limit ) {
		global $wpdb;

		$table  = Mobo_Core_Product_Map::table_name();
		$cursor = absint( $state['sweepCursor'] );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, remote_guid, wp_post_id, object_type, parent_remote_guid FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
				$cursor,
				max( 1, absint( $limit ) )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			$this->cleanup_orphan_health_rows();
			return array( 'processed' => 0, 'deleted' => 0, 'orphans' => 0, 'done' => true );
		}

		$deleted = 0;
		$orphans = 0;
		$map     = new Mobo_Core_Product_Map();
		foreach ( $rows as $row ) {
			$id          = absint( $row['id'] );
			$guid        = sanitize_text_field( (string) $row['remote_guid'] );
			$post_id     = absint( $row['wp_post_id'] );
			$object_type = sanitize_key( (string) $row['object_type'] );
			$parent_guid = sanitize_text_field( (string) $row['parent_remote_guid'] );
			$state['sweepCursor'] = max( absint( $state['sweepCursor'] ), $id );

			if ( Mobo_Core_Product_Map::TYPE_VARIATION === $object_type ) {
				$parent_id = '' !== $parent_guid ? $map->get_product_id( $parent_guid ) : 0;
				$is_orphan = $post_id <= 0
					|| 'product_variation' !== get_post_type( $post_id )
					|| $parent_id <= 0
					|| absint( wp_get_post_parent_id( $post_id ) ) !== $parent_id;
				if ( $is_orphan ) {
					if ( $post_id > 0 && 'product_variation' === get_post_type( $post_id ) ) {
						wp_delete_post( $post_id, true );
					}
					$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
					$orphans++;
				}
				continue;
			}

			if ( Mobo_Core_Product_Map::TYPE_PRODUCT !== $object_type || $post_id <= 0 || 'product' !== get_post_type( $post_id ) ) {
				$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
				if ( '' !== $guid ) {
					$wpdb->delete( self::table_name(), array( 'product_guid' => $guid ), array( '%s' ) );
				}
				$orphans++;
				continue;
			}

			if ( '' !== $guid && ! isset( $state['deepSeen'][ $guid ] ) ) {
				$this->delete_local_product( $guid, 0 );
				$deleted++;
			}
		}

		return array( 'processed' => count( $rows ), 'deleted' => $deleted, 'orphans' => $orphans, 'done' => false );
	}

	/**
	 * Delete a local Mobo-owned product and all mappings.
	 *
	 * @param string $guid Product GUID.
	 * @param int    $portal_id Portal numeric ID.
	 * @return bool
	 */
	private function delete_local_product( $guid, $portal_id ) {
		global $wpdb;

		$post_id = $this->find_local_product_id( $guid, $portal_id );
		if ( $post_id > 0 ) {
			wp_delete_post( $post_id, true );
		}

		$map = new Mobo_Core_Product_Map();
		if ( $post_id > 0 ) {
			$map->delete_by_post_id( $post_id );
		}
		$map_table = Mobo_Core_Product_Map::table_name();
		if ( '' !== $guid ) {
			$wpdb->delete( $map_table, array( 'parent_remote_guid' => $guid ), array( '%s' ) );
			$wpdb->delete( $map_table, array( 'remote_guid' => $guid, 'object_type' => Mobo_Core_Product_Map::TYPE_PRODUCT ), array( '%s', '%s' ) );
		}
		if ( '' !== $guid ) {
			$wpdb->delete( self::table_name(), array( 'product_guid' => $guid ), array( '%s' ) );
		}
		if ( $portal_id > 0 ) {
			$wpdb->delete( self::table_name(), array( 'portal_product_id' => $portal_id ), array( '%d' ) );
		}
		return true;
	}

	/**
	 * Locate local product from map or legacy metadata.
	 *
	 * @param string $guid Product GUID.
	 * @param int    $portal_id Portal ID.
	 * @return int
	 */
	private function find_local_product_id( $guid, $portal_id ) {
		$guid = sanitize_text_field( (string) $guid );
		if ( '' !== $guid ) {
			$map = new Mobo_Core_Product_Map();
			$id  = $map->get_product_id( $guid );
			if ( $id > 0 ) {
				return $id;
			}
		}

		$portal_id = absint( $portal_id );
		if ( $portal_id <= 0 ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => 'portal_product_id', 'value' => $portal_id, 'compare' => '=' ),
					array( 'key' => 'mobo_portal_product_id', 'value' => $portal_id, 'compare' => '=' ),
					array( 'key' => '_mobo_portal_product_id', 'value' => $portal_id, 'compare' => '=' ),
				),
			)
		);
		return ! empty( $query->posts ) ? absint( $query->posts[0] ) : 0;
	}

	/**
	 * Normalize change endpoint response.
	 *
	 * @param array $response Response.
	 * @return array
	 */
	private function normalize_changes( $response ) {
		$changes = $this->get_value( $response, 'changes', array() );
		$changes = is_array( $changes ) ? $changes : array();
		$out = array();
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}
			$raw_product_id = $this->first_value( $change, array( 'productId', 'ProductId' ), '' );
			$guid = sanitize_text_field( (string) $this->first_value( $change, array( 'productGuid', 'product_guid', 'guid', 'entityGuid' ), '' ) );
			if ( '' === $guid && is_string( $raw_product_id ) && preg_match( '/^[0-9a-f-]{32,36}$/i', $raw_product_id ) ) {
				$guid = sanitize_text_field( $raw_product_id );
			}
			$portal_id = absint( $this->first_value( $change, array( 'portalProductId', 'portal_product_id' ), 0 ) );
			if ( $portal_id <= 0 && is_numeric( $raw_product_id ) ) {
				$portal_id = absint( $raw_product_id );
			}
			if ( '' === $guid && $portal_id <= 0 ) {
				continue;
			}
			$out[] = array(
				'productGuid' => $guid,
				'portalProductId' => $portal_id,
				'revision' => absint( $this->first_value( $change, array( 'revision', 'Revision' ), 0 ) ),
				'deleted' => $this->to_bool( $this->first_value( $change, array( 'deleted', 'isDeleted', 'removed' ), false ) ),
				'parentDone' => false,
				'productPayload' => array(),
				'variantPage' => 1,
				'variantCursor' => 0,
				'syncId' => wp_generate_uuid4(),
				'portalHash' => sanitize_text_field( (string) $this->first_value( $change, array( 'contentHash', 'ContentHash', 'portalHash', 'PortalHash', 'hash' ), '' ) ),
				'hashParts' => array(),
			);
		}
		return $out;
	}

	/**
	 * Build pending work from lightweight catalog product.
	 *
	 * @param array $product Product.
	 * @param int   $revision Revision.
	 * @return array
	 */
	private function build_pending_from_product( $product, $revision ) {
		return array(
			'productGuid' => $this->extract_product_guid( $product, '' ),
			'portalProductId' => $this->extract_portal_product_id( $product, 0 ),
			'revision' => max( absint( $revision ), $this->extract_revision( $product ) ),
			'deleted' => false,
			'parentDone' => false,
			'productPayload' => array(),
			'variantPage' => 1,
			'variantCursor' => 0,
			'syncId' => wp_generate_uuid4(),
			'portalHash' => $this->extract_portal_hash( $product, '' ),
			'hashParts' => array(),
		);
	}

	/**
	 * Skip an unchanged product only when Portal exposes its authoritative
	 * ContentHash. Without a hash/revision, the bounded rolling fallback keeps
	 * fetching snapshots so missed variation-only changes still recover.
	 *
	 * @param array $item Pending item.
	 * @return bool
	 */
	private function should_skip_unchanged_product( $item ) {
		global $wpdb;

		$guid = sanitize_text_field( (string) ( isset( $item['productGuid'] ) ? $item['productGuid'] : '' ) );
		$hash = sanitize_text_field( (string) ( isset( $item['portalHash'] ) ? $item['portalHash'] : '' ) );
		if ( '' === $guid || '' === $hash || ! self::health_table_exists() ) {
			return false;
		}
		$table = self::table_name();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT portal_hash, sync_status FROM {$table} WHERE product_guid = %s LIMIT 1", $guid ),
			ARRAY_A
		);
		return is_array( $row )
			&& 'synced' === sanitize_key( (string) $row['sync_status'] )
			&& hash_equals( (string) $row['portal_hash'], $hash );
	}

	/**
	 * Extract Portal's existing content hash when exposed by the API.
	 *
	 * @param array  $data Product data.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private function extract_portal_hash( $data, $fallback = '' ) {
		if ( ! is_array( $data ) ) {
			return sanitize_text_field( (string) $fallback );
		}
		return sanitize_text_field( (string) $this->first_value( $data, array( 'contentHash', 'ContentHash', 'portalHash', 'PortalHash' ), $fallback ) );
	}

	/**
	 * Persist health state.
	 */
	private static function upsert_health( $guid, $wp_id, $status, $revision, $hash, $error, $portal_id, $successful ) {
		global $wpdb;

		$guid = sanitize_text_field( (string) $guid );
		if ( '' === $guid ) {
			return false;
		}
		$status = sanitize_key( (string) $status );
		if ( ! in_array( $status, array( 'synced', 'behind', 'repairing', 'failed' ), true ) ) {
			$status = 'behind';
		}
		$table = self::table_name();
		if ( ! self::health_table_exists() ) {
			self::create_table();
		}
		if ( ! self::health_table_exists() ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, wp_product_id, portal_product_id, portal_revision, portal_hash FROM {$table} WHERE product_guid = %s LIMIT 1", $guid ),
			ARRAY_A
		);
		$row_id = is_array( $existing ) ? absint( $existing['id'] ) : 0;
		$wp_id = absint( $wp_id );
		$portal_id = absint( $portal_id );
		$revision = absint( $revision );
		$hash = sanitize_text_field( (string) $hash );
		$data = array(
			'product_guid' => $guid,
			'wp_product_id' => $wp_id > 0 ? $wp_id : ( is_array( $existing ) ? absint( $existing['wp_product_id'] ) : 0 ),
			'portal_product_id' => $portal_id > 0 ? $portal_id : ( is_array( $existing ) ? absint( $existing['portal_product_id'] ) : 0 ),
			'portal_revision' => $revision > 0 ? $revision : ( is_array( $existing ) ? absint( $existing['portal_revision'] ) : 0 ),
			'portal_hash' => '' !== $hash ? $hash : ( is_array( $existing ) ? sanitize_text_field( (string) $existing['portal_hash'] ) : '' ),
			'sync_status' => $status,
			'last_error' => '' !== (string) $error ? sanitize_textarea_field( (string) $error ) : null,
			'updated_at' => $now,
		);
		if ( $successful ) {
			$data['last_successful_sync_time'] = $now;
		}
		if ( $row_id ) {
			$wpdb->update( $table, $data, array( 'id' => absint( $row_id ) ) );
		} else {
			$data['created_at'] = $now;
			if ( ! isset( $data['last_successful_sync_time'] ) ) {
				$data['last_successful_sync_time'] = null;
			}
			$wpdb->insert( $table, $data );
		}

		$wp_id = absint( $data['wp_product_id'] );
		if ( $wp_id > 0 ) {
			update_post_meta( $wp_id, '_mobo_portal_revision', absint( $revision ) );
			update_post_meta( $wp_id, '_mobo_sync_status', $status );
			update_post_meta( $wp_id, '_mobo_sync_last_error', sanitize_textarea_field( (string) $error ) );
			if ( $successful ) {
				update_post_meta( $wp_id, '_mobo_last_successful_sync_time', gmdate( 'c' ) );
			}
		}
		return true;
	}

	public static function mark_behind( $guid, $wp_id = 0, $revision = 0, $hash = '', $portal_id = 0 ) {
		return self::upsert_health( $guid, $wp_id, 'behind', $revision, $hash, '', $portal_id, false );
	}

	public static function mark_repairing( $guid, $wp_id = 0, $revision = 0, $hash = '', $portal_id = 0 ) {
		return self::upsert_health( $guid, $wp_id, 'repairing', $revision, $hash, '', $portal_id, false );
	}

	public static function mark_synced( $guid, $wp_id = 0, $revision = 0, $hash = '', $portal_id = 0 ) {
		return self::upsert_health( $guid, $wp_id, 'synced', $revision, $hash, '', $portal_id, true );
	}

	public static function mark_failed( $guid, $wp_id = 0, $error = '', $revision = 0, $portal_id = 0 ) {
		return self::upsert_health( $guid, $wp_id, 'failed', $revision, '', $error, $portal_id, false );
	}

	/**
	 * Record webhook outcome in the same health layer.
	 *
	 * @param string $event Event.
	 * @param array  $payload Payload.
	 * @param array  $result Result.
	 * @return void
	 */
	public static function record_webhook_result( $event, $payload, $result ) {
		$instance = new self();
		$guid     = $instance->extract_product_guid_recursive( $payload );
		if ( '' === $guid ) {
			return;
		}
		$wp_id = $instance->find_local_product_id( $guid, 0 );
		if ( empty( $result['success'] ) ) {
			self::mark_failed( $guid, $wp_id, isset( $result['message'] ) ? $result['message'] : 'Webhook sync failed.' );
			return;
		}
		if ( 'UpdateVariant' === $event && ( ! isset( $result['data']['deleteFile'] ) || ! empty( $result['data']['deleteFile'] ) ) ) {
			self::mark_synced( $guid, $wp_id );
		} else {
			self::mark_behind( $guid, $wp_id );
		}
	}

	/**
	 * Dashboard status.
	 *
	 * @return array
	 */
	public static function get_dashboard_status() {
		global $wpdb;
		$table = self::table_name();
		$counts = array( 'synced' => 0, 'behind' => 0, 'repairing' => 0, 'failed' => 0 );
		if ( self::health_table_exists() ) {
			$rows = $wpdb->get_results( "SELECT sync_status, COUNT(*) AS total FROM {$table} GROUP BY sync_status", ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$key = sanitize_key( (string) $row['sync_status'] );
				if ( isset( $counts[ $key ] ) ) {
					$counts[ $key ] = absint( $row['total'] );
				}
			}
		}
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'counts' => $counts,
			'pendingRepair' => absint( $counts['behind'] ) + absint( $counts['repairing'] ) + absint( $counts['failed'] ),
			'lastCheckAt' => absint( get_option( self::LAST_CHECK_OPTION, 0 ) ),
			'lastSuccessAt' => absint( get_option( self::LAST_SUCCESS_OPTION, 0 ) ),
			'lastDeepAt' => absint( get_option( self::LAST_DEEP_OPTION, 0 ) ),
			'nextCheckAt' => ( new self() )->get_next_check_at(),
			'endpointSupport' => sanitize_text_field( (string) get_option( self::ENDPOINT_SUPPORT_OPTION, 'unknown' ) ),
			'state' => $state,
			'lastResult' => get_option( self::LAST_RESULT_OPTION, array() ),
		);
	}

	/**
	 * Complete a revision-based pass and advance to the server watermark only
	 * after the page has been applied successfully.
	 *
	 * @param array  $state State by reference.
	 * @param string $message Completion message.
	 * @return void
	 */
	private function complete_revision_state( &$state, $message ) {
		if ( ! empty( $state['revisionFailed'] ) ) {
			$this->complete_state( $state, false, 'Revision reconciliation completed with failed products; watermark retained for retry.' );
			return;
		}
		$revision = max( absint( $state['afterRevision'] ), absint( $state['currentRevision'] ) );
		$state['afterRevision'] = $revision;
		update_option( self::REVISION_OPTION, $revision, false );
		$this->complete_state( $state, true, $message );
	}

	/**
	 * Remove health rows that point to deleted WooCommerce products.
	 * Failed/behind rows with no local product are retained because they may
	 * represent Portal products waiting to be recreated.
	 *
	 * @return void
	 */
	private function cleanup_orphan_health_rows() {
		global $wpdb;

		if ( ! self::health_table_exists() ) {
			return;
		}
		$health = self::table_name();
		$posts  = $wpdb->posts;
		$wpdb->query(
			"DELETE h FROM {$health} h LEFT JOIN {$posts} p ON p.ID = h.wp_product_id AND p.post_type = 'product' WHERE h.wp_product_id > 0 AND p.ID IS NULL"
		);
	}

	/**
	 * Check the health table without triggering dbDelta.
	 *
	 * @return bool
	 */
	private static function health_table_exists() {
		global $wpdb;
		$table = self::table_name();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function complete_state( &$state, $success, $message ) {
		$state['status']      = 'idle';
		$state['completedAt'] = time();
		$state['updatedAt']   = time();
		$state['lastMessage'] = sanitize_text_field( (string) $message );
		if ( $success ) {
			$state['lastError'] = '';
			update_option( self::LAST_SUCCESS_OPTION, time(), false );
		}
	}

	private function finish_result( $result ) {
		$result['executedAt'] = time();
		update_option( self::LAST_RESULT_OPTION, $result, false );
		return $result;
	}

	private function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return wp_parse_args( is_array( $state ) ? $state : array(), $this->get_state_defaults() );
	}

	private function save_state( $state ) {
		update_option( self::STATE_OPTION, is_array( $state ) ? $state : $this->get_state_defaults(), false );
	}

	private function get_state_defaults() {
		return array(
			'status' => 'idle',
			'mode' => '',
			'phase' => '',
			'source' => '',
			'pending' => array(),
			'deepSeen' => array(),
			'scanCursor' => 0,
			'nextScanCursor' => 0,
			'scanHasMore' => false,
			'sweepCursor' => 0,
			'afterRevision' => absint( get_option( self::REVISION_OPTION, 0 ) ),
			'currentRevision' => 0,
			'moreChanges' => false,
			'revisionFailed' => false,
			'processedProducts' => 0,
			'processedVariations' => 0,
			'failedProducts' => 0,
			'startedAt' => 0,
			'updatedAt' => 0,
			'completedAt' => 0,
			'lastMessage' => '',
			'lastError' => '',
		);
	}

	private function is_fast_due() {
		$last = absint( get_option( self::LAST_CHECK_OPTION, 0 ) );
		return 0 === $last || time() >= $last + $this->get_fast_interval_seconds();
	}

	private function is_deep_due() {
		$last = absint( get_option( self::LAST_DEEP_OPTION, 0 ) );
		$interval = 'daily' === (string) Mobo_Core_Settings::get( 'mobo_core_reconciliation_deep_schedule', 'weekly' ) ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
		return 0 === $last || time() >= $last + $interval;
	}

	private function get_fast_interval_seconds() {
		$value = Mobo_Core_Settings::get_int( 'mobo_core_reconciliation_fast_interval', HOUR_IN_SECONDS, 900, DAY_IN_SECONDS );
		$allowed = array( 900, 1800, 3600, 10800, 21600, 43200, 86400 );
		return in_array( $value, $allowed, true ) ? $value : HOUR_IN_SECONDS;
	}

	private function get_next_check_at() {
		$last = absint( get_option( self::LAST_CHECK_OPTION, 0 ) );
		return $last > 0 ? $last + $this->get_fast_interval_seconds() : time();
	}

	private function looks_like_changes_response( $response ) {
		$payload = $this->get_changes_payload( $response );
		return is_array( $payload ) && ( array_key_exists( 'changes', $payload ) || array_key_exists( 'Changes', $payload ) );
	}

	/**
	 * Accept direct change responses and common API envelopes such as
	 * `{ data: { currentRevision, changes } }`.
	 *
	 * @param array $response API response.
	 * @return array
	 */
	private function get_changes_payload( $response ) {
		if ( ! is_array( $response ) ) {
			return array();
		}
		if ( array_key_exists( 'changes', $response ) || array_key_exists( 'Changes', $response ) ) {
			return $response;
		}
		$data = $this->get_value( $response, 'data', array() );
		return is_array( $data ) ? $data : $response;
	}

	private function extract_product_guid( $data, $fallback = '' ) {
		if ( ! is_array( $data ) ) {
			return sanitize_text_field( (string) $fallback );
		}
		return sanitize_text_field( (string) $this->first_value( $data, array( 'productId', 'productGuid', 'product_guid', 'guid', 'id' ), $fallback ) );
	}

	private function extract_product_guid_recursive( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		$guid = $this->extract_product_guid( $data, '' );
		if ( '' !== $guid && preg_match( '/^[0-9a-f-]{32,36}$/i', $guid ) ) {
			return $guid;
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$found = $this->extract_product_guid_recursive( $value );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}
		return '';
	}

	private function extract_portal_product_id( $data, $fallback = 0 ) {
		if ( ! is_array( $data ) ) {
			return absint( $fallback );
		}
		return absint( $this->first_value( $data, array( 'portalProductId', 'portal_product_id', 'PortalProductId' ), $fallback ) );
	}

	private function extract_revision( $data ) {
		$value = $this->first_value( is_array( $data ) ? $data : array(), array( 'revision', 'portalRevision', 'updatedAt', 'UpdatedAt' ), 0 );
		if ( is_numeric( $value ) ) {
			return absint( $value );
		}
		$time = strtotime( (string) $value );
		return false !== $time ? absint( $time ) : 0;
	}

	private function stable_hash( $data ) {
		return hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	private function first_value( $array, $keys, $default = null ) {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $array ) ) {
				return $array[ $key ];
			}
		}
		return $default;
	}

	private function get_value( $array, $key, $default = null ) {
		if ( ! is_array( $array ) ) {
			return $default;
		}
		if ( array_key_exists( $key, $array ) ) {
			return $array[ $key ];
		}
		$lower = strtolower( $key );
		foreach ( $array as $candidate => $value ) {
			if ( strtolower( (string) $candidate ) === $lower ) {
				return $value;
			}
		}
		return $default;
	}

	private function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
