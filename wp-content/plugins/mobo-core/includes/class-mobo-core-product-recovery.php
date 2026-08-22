<?php
/**
 * One-time automatic recovery for parent products removed by older Mobo Core versions.
 *
 * Recovery has two evidence phases and never changes the customer's OnlyInStock setting:
 * 1) durable local evidence (product ledger seeded from product map/postmeta/image queue),
 * 2) Portal's site-scoped delivered webhook history.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Product_Recovery {
	const STATE_OPTION         = 'mobo_core_product_recovery_state';
	const PENDING_OPTION       = 'mobo_core_product_recovery_pending';
	const FOLLOWUP_OPTION      = 'mobo_core_product_recovery_followup_reason';
	const MAX_CURRENT_ATTEMPTS = 8;
	const QUARANTINE_MAX_ITEMS = 50;
	const VARIATION_INTEGRITY_REASON = 'upgrade-10-33-35-variation-integrity';

	public static function is_pending() {
		return '1' === (string) get_option( self::PENDING_OPTION, '0' );
	}

	public static function schedule( $reason = 'upgrade' ) {
		$existing = get_option( self::STATE_OPTION, array() );
		$status   = is_array( $existing ) ? sanitize_key( (string) ( isset( $existing['status'] ) ? $existing['status'] : '' ) ) : '';

		/*
		 * A missing/corrupted state row must never be treated as an active generation.
		 * Older code only rebuilt when the option was non-array or already done, so an
		 * absent option (get_option defaulting to an empty array) could set pending=1
		 * without ever creating generationId/cursors. Rebuild any empty/status-less
		 * state fail-closed before arming the pending marker.
		 */
		if ( ! is_array( $existing ) || empty( $existing ) || '' === $status || 0 === strpos( $status, 'done' ) ) {
			$existing = self::build_initial_state( $reason );
			update_option( self::STATE_OPTION, $existing, false );
		}
		update_option( self::PENDING_OPTION, '1', false );
	}

	/**
	 * Schedule a full follow-up generation without overlapping or resetting an
	 * already-running recovery. Used by upgrade integrity re-audits that must start
	 * from cursor zero even when an older generation is still draining.
	 *
	 * @param string $reason Follow-up reason.
	 * @return void
	 */
	public static function schedule_followup( $reason = 'integrity-reaudit' ) {
		$reason   = sanitize_key( (string) $reason );
		$existing = get_option( self::STATE_OPTION, array() );
		$status   = is_array( $existing ) ? sanitize_key( (string) ( isset( $existing['status'] ) ? $existing['status'] : '' ) ) : '';

		if ( self::is_pending() && is_array( $existing ) && ! empty( $existing ) && '' !== $status && 0 !== strpos( $status, 'done' ) ) {
			update_option( self::FOLLOWUP_OPTION, '' !== $reason ? $reason : 'integrity-reaudit', false );
			return;
		}

		delete_option( self::FOLLOWUP_OPTION );
		update_option( self::STATE_OPTION, self::build_initial_state( $reason ), false );
		update_option( self::PENDING_OPTION, '1', false );
	}

	private static function is_variation_integrity_reason( $reason ) {
		$reason = sanitize_key( (string) $reason );

		/* 10.33.35 originally supplied a dotted version string. sanitize_key() drops
		 * dots, so already-scheduled sites may carry upgrade-103335-... while new
		 * generations use the canonical hyphenated form. Accept both permanently. */
		return in_array(
			$reason,
			array( self::VARIATION_INTEGRITY_REASON, 'upgrade-103335-variation-integrity' ),
			true
		);
	}

	private static function build_initial_state( $reason ) {
		return array(
			'generationId'    => wp_generate_uuid4(),
			'status'          => 'running',
			'reason'          => sanitize_key( (string) $reason ),
			'phase'           => 'local-ledger',
			'ledgerCursor'    => 0,
			'cursor'          => 0,
			'manifestBuffer'  => array(),
			'processed'       => 0,
			'recovered'       => 0,
			'alreadyPresent'  => 0,
			'skipped'         => 0,
			'failed'          => 0,
			'retryFailures'   => 0,
			'quarantined'     => 0,
			'quarantineItems' => array(),
			'globalAttempts'  => 0,
			'currentSource'   => '',
			'currentGuid'     => '',
			'currentCursor'   => 0,
			'currentPayload'  => array(),
			'currentAttempts' => 0,
			'nextRetryAt'     => 0,
			'lastError'       => '',
			'startedAt'       => time(),
			'updatedAt'       => time(),
			'completedAt'     => 0,
		);
	}

	public static function get_status() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Run a bounded recovery batch from the real-cron runner.
	 *
	 * @param int $limit Maximum product identities handled in this call.
	 * @param int $budget_seconds Time budget.
	 * @return array
	 */
	public function process_batch( $limit = 3, $budget_seconds = 8 ) {
		if ( ! self::is_pending() ) {
			return array( 'success' => true, 'status' => 'done', 'remaining' => false, 'processed' => 0, 'recovered' => 0 );
		}

		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return array( 'success' => true, 'status' => 'paused-for-upgrade', 'remaining' => true, 'processed' => 0, 'recovered' => 0 );
		}

		$lock = class_exists( 'Mobo_Core_Recovery_Coordinator' ) ? Mobo_Core_Recovery_Coordinator::acquire( 300 ) : ( class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'product_recovery', 180 ) : 'no-lock' );
		if ( false === $lock ) {
			return array( 'success' => true, 'status' => 'locked', 'remaining' => true, 'processed' => 0, 'recovered' => 0 );
		}

		$deadline = microtime( true ) + max( 2, min( 20, absint( $budget_seconds ) ) );
		$limit    = max( 1, min( 10, absint( $limit ) ) );
		$handled  = 0;
		$restored = 0;

		try {
			$state = self::get_status();
			if ( empty( $state ) ) {
				self::schedule( 'upgrade' );
				$state = self::get_status();
			}

			$state = $this->normalize_state( $state );

			if ( absint( $state['nextRetryAt'] ) > time() ) {
				return array(
					'success'     => true,
					'status'      => 'retry-wait',
					'remaining'   => true,
					'processed'   => 0,
					'recovered'   => 0,
					'nextRetryAt' => absint( $state['nextRetryAt'] ),
				);
			}

			$api  = new Mobo_Core_API_Client();
			$sync = new Mobo_Core_Product_Sync();
			$sync->set_repair_mode( true );

			while ( $handled < $limit && microtime( true ) < ( $deadline - 0.5 ) ) {
				$current_source  = sanitize_key( (string) $state['currentSource'] );
				$current_guid    = sanitize_text_field( (string) $state['currentGuid'] );
				$current_cursor  = absint( $state['currentCursor'] );
				$current_payload = isset( $state['currentPayload'] ) && is_array( $state['currentPayload'] ) ? $state['currentPayload'] : array();

				/* Crash/corruption invariant: a persisted in-flight identity is usable only
				 * when source + cursor + GUID form one complete checkpoint. Never let a
				 * stale payload from an incomplete state become the next product's payload. */
				if ( '' !== $current_guid && ( ! in_array( $current_source, array( 'ledger', 'portal' ), true ) || $current_cursor <= 0 ) ) {
					$state['failed']          = absint( $state['failed'] ) + 1;
					$state['quarantined']     = absint( $state['quarantined'] ) + 1;
					$this->append_quarantine( $state, 'checkpoint', $current_guid, $current_cursor, 'Incomplete persisted recovery checkpoint was discarded safely.', absint( $state['currentAttempts'] ) );
					$state['currentSource']   = '';
					$state['currentGuid']     = '';
					$state['currentCursor']   = 0;
					$state['currentPayload']  = array();
					$state['currentAttempts'] = 0;
					$state['nextRetryAt']     = 0;
					$state['updatedAt']       = time();
					update_option( self::STATE_OPTION, $state, false );
					$current_source = '';
					$current_guid = '';
					$current_cursor = 0;
					$current_payload = array();
				}

				if ( '' === $current_guid ) {
					$candidate = $this->next_candidate( $state, $api );
					if ( is_wp_error( $candidate ) ) {
						return $this->defer_error( $state, $candidate->get_error_message(), 120, $handled, $restored );
					}

					if ( ! empty( $candidate['phaseChanged'] ) ) {
						$state['globalAttempts'] = 0;
						update_option( self::STATE_OPTION, $state, false );
						continue;
					}

					if ( ! empty( $candidate['done'] ) ) {
						$followup_reason = sanitize_key( (string) get_option( self::FOLLOWUP_OPTION, '' ) );
						if ( '' !== $followup_reason ) {
							delete_option( self::FOLLOWUP_OPTION );
							$state = self::build_initial_state( $followup_reason );
							update_option( self::STATE_OPTION, $state, false );
							update_option( self::PENDING_OPTION, '1', false );
							return array( 'success' => true, 'status' => 'followup-started', 'remaining' => true, 'processed' => $handled, 'recovered' => $restored, 'generationId' => $state['generationId'] );
						}

						$state['status']      = absint( $state['quarantined'] ) > 0 ? 'done-with-warnings' : 'done';
						$state['completedAt'] = time();
						$state['updatedAt']   = time();
						$state['lastError']   = '';
						$state['nextRetryAt'] = 0;
						update_option( self::STATE_OPTION, $state, false );
						delete_option( self::PENDING_OPTION );
						$this->mark_post_recovery_warmup_if_needed();
						return array( 'success' => true, 'status' => $state['status'], 'remaining' => false, 'processed' => $handled, 'recovered' => $restored, 'quarantined' => absint( $state['quarantined'] ) );
					}

					$current_source = sanitize_key( (string) ( isset( $candidate['source'] ) ? $candidate['source'] : '' ) );
					$current_guid   = sanitize_text_field( (string) ( isset( $candidate['guid'] ) ? $candidate['guid'] : '' ) );
					$current_cursor = absint( isset( $candidate['cursor'] ) ? $candidate['cursor'] : 0 );

					if ( '' === $current_guid || $current_cursor <= 0 ) {
						return $this->defer_error( $state, 'Recovery source returned an invalid product identity.', 300, $handled, $restored );
					}

					$existing_id = $this->find_local_product_id( $current_guid );
					$local_sane  = $existing_id > 0
						&& class_exists( 'Mobo_Core_Reconciliation' )
						&& Mobo_Core_Reconciliation::local_product_structure_is_sane( $existing_id );
					$force_integrity_refetch = 'ledger' === $current_source
						&& self::is_variation_integrity_reason( isset( $state['reason'] ) ? $state['reason'] : '' );
					if ( $local_sane && ! $force_integrity_refetch ) {
						Mobo_Core_Product_Ledger::record( $current_guid, $existing_id, 'recovery-scan', false );
						$this->advance_candidate( $state, $current_source, $current_cursor );
						$state['processed']       = absint( $state['processed'] ) + 1;
						$state['alreadyPresent']  = absint( $state['alreadyPresent'] ) + 1;
						$state['globalAttempts']  = 0;
						$state['updatedAt']       = time();
						update_option( self::STATE_OPTION, $state, false );
						$handled++;
						continue;
					}

					/* Existing-but-drifted products intentionally fall through to the same exact
					 * GUID recovery path as missing products. The 10.33.35 local-ledger re-audit
					 * deliberately exact-refetches every previously imported local identity once,
					 * catching semantic drift even when key/value shape still looks superficially
					 * valid. Portal-history duplicates later use the cheap local-sane skip. */

					/* Persist the identity before network I/O. Payload stays empty until an
					 * exact fetch succeeds, making timeout recovery deterministic. */
					$state['currentSource']   = $current_source;
					$state['currentGuid']     = $current_guid;
					$state['currentCursor']   = $current_cursor;
					$state['currentPayload']  = array();
					$current_payload          = array();
					$state['currentAttempts'] = 0;
					$state['globalAttempts']  = 0;
					$state['updatedAt']       = time();
					update_option( self::STATE_OPTION, $state, false );
				}

				/* An interrupted/failed exact fetch intentionally leaves an empty payload.
				 * Never feed that empty state to Product Sync: re-fetch the exact GUID. */
				if ( ! $this->payload_has_product( $current_payload ) ) {
					$response = $api->get_product_by_guid( $current_guid, 'auto-restore-' . gmdate( 'YmdHis' ) );
					if ( is_wp_error( $response ) ) {
						return $this->defer_current_error( $state, $current_source, $current_guid, $current_cursor, array(), $response->get_error_message(), $handled, $restored );
					}

					if ( ! $this->payload_has_product( $response ) ) {
						return $this->defer_current_error( $state, $current_source, $current_guid, $current_cursor, array(), 'Exact recovery endpoint returned an empty or malformed product payload.', $handled, $restored );
					}

					$current_payload = $response;
					$state['currentPayload'] = $current_payload;
					$state['nextRetryAt']    = 0;
					$state['lastError']      = '';
					$state['updatedAt']      = time();
					update_option( self::STATE_OPTION, $state, false );
				}

				$parent_result = $sync->process_product_updated_payload( $current_payload );
				if ( empty( $parent_result['success'] ) ) {
					return $this->defer_current_error(
						$state,
						$current_source,
						$current_guid,
						$current_cursor,
						$current_payload,
						(string) ( isset( $parent_result['message'] ) ? $parent_result['message'] : 'Product restore failed.' ),
						$handled,
						$restored
					);
				}

				if ( empty( $parent_result['data']['deleteFile'] ) ) {
					$message = isset( $parent_result['message'] ) ? (string) $parent_result['message'] : 'Parent restore is still deferred.';
					return $this->defer_current_error( $state, $current_source, $current_guid, $current_cursor, $current_payload, $message, $handled, $restored );
				}

				/*
				 * Product Sync can intentionally accept-and-skip an identity because of
				 * an explicit URL exclusion or the local remote-trash policy. Those are
				 * deliberate site rules, not restore failures; do not retry forever.
				 */
				$restored_parent_id = $this->find_local_product_id( $current_guid );
				if ( $restored_parent_id <= 0 ) {
					$this->advance_candidate( $state, $current_source, $current_cursor );
					$state['processed']       = absint( $state['processed'] ) + 1;
					$state['skipped']         = absint( $state['skipped'] ) + 1;
					$state['currentSource']   = '';
					$state['currentGuid']     = '';
					$state['currentCursor']   = 0;
					$state['currentPayload']  = array();
					$state['currentAttempts'] = 0;
					$state['lastError']       = '';
					$state['nextRetryAt']     = 0;
					$state['updatedAt']       = time();
					update_option( self::STATE_OPTION, $state, false );
					$handled++;
					continue;
				}

				$product_rows = isset( $current_payload['data'] ) && is_array( $current_payload['data'] ) ? $current_payload['data'] : array();
				$product_data = ! empty( $product_rows ) && is_array( reset( $product_rows ) ) ? reset( $product_rows ) : array();
				$variants     = isset( $product_data['variants'] ) && is_array( $product_data['variants'] ) ? $product_data['variants'] : ( isset( $product_data['Variants'] ) && is_array( $product_data['Variants'] ) ? $product_data['Variants'] : array() );
				$attributes   = isset( $product_data['attributes'] ) && is_array( $product_data['attributes'] ) ? $product_data['attributes'] : ( isset( $product_data['Attributes'] ) && is_array( $product_data['Attributes'] ) ? $product_data['Attributes'] : array() );

				$variant_payload = array(
					'productId'                => $current_guid,
					'syncId'                   => 'auto-restore-' . $current_guid,
					'pageNumber'               => 1,
					'recordPerPage'            => max( 1, count( $variants ) ),
					'totalCount'               => count( $variants ),
					'hasMore'                  => false,
					'isLastPage'               => true,
					'variantListAuthoritative' => true,
					'isFullVariantSnapshot'    => true,
					'attributes'               => $attributes,
					'data'                     => $variants,
				);

				$variant_result = $sync->process_update_variant_payload( $variant_payload, true );
				if ( empty( $variant_result['success'] ) || empty( $variant_result['data']['deleteFile'] ) ) {
					$message = (string) ( isset( $variant_result['message'] ) ? $variant_result['message'] : 'Variant restore did not converge.' );
					return $this->defer_current_error( $state, $current_source, $current_guid, $current_cursor, $current_payload, $message, $handled, $restored );
				}

				$wp_id = $this->find_local_product_id( $current_guid );
				if ( $wp_id <= 0 ) {
					return $this->defer_current_error( $state, $current_source, $current_guid, $current_cursor, $current_payload, 'Restored product did not persist in WooCommerce.', $handled, $restored );
				}

				Mobo_Core_Product_Ledger::record( $current_guid, $wp_id, 'auto-recovery', true );
				$this->advance_candidate( $state, $current_source, $current_cursor );
				$state['processed']       = absint( $state['processed'] ) + 1;
				$state['recovered']       = absint( $state['recovered'] ) + 1;
				$state['currentSource']   = '';
				$state['currentGuid']     = '';
				$state['currentCursor']   = 0;
				$state['currentPayload']  = array();
				$state['currentAttempts'] = 0;
				$state['nextRetryAt']     = 0;
				$state['lastError']       = '';
				$state['updatedAt']       = time();
				update_option( self::STATE_OPTION, $state, false );
				$handled++;
				$restored++;
			}

			return array(
				'success'   => true,
				'status'    => 'running',
				'remaining' => true,
				'processed' => $handled,
				'recovered' => $restored,
				'phase'     => sanitize_key( (string) $state['phase'] ),
				'cursor'    => absint( $state['cursor'] ),
			);
		} finally {
			if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) ) {
				Mobo_Core_Recovery_Coordinator::release( $lock );
			} elseif ( class_exists( 'Mobo_Core_Lock' ) && 'no-lock' !== $lock ) {
				Mobo_Core_Lock::release( 'product_recovery', $lock );
			}
		}
	}

	/**
	 * Normalize pre-release/incomplete state without discarding progress.
	 */
	private function normalize_state( $state ) {
		$defaults = array(
			'generationId'    => '',
			'status'          => 'running',
			'reason'          => 'upgrade',
			'phase'           => 'local-ledger',
			'ledgerCursor'    => 0,
			'cursor'          => 0,
			'manifestBuffer'  => array(),
			'processed'       => 0,
			'recovered'       => 0,
			'alreadyPresent'  => 0,
			'skipped'         => 0,
			'failed'          => 0,
			'retryFailures'   => 0,
			'quarantined'     => 0,
			'quarantineItems' => array(),
			'globalAttempts'  => 0,
			'currentSource'   => '',
			'currentGuid'     => '',
			'currentCursor'   => 0,
			'currentPayload'  => array(),
			'currentAttempts' => 0,
			'nextRetryAt'     => 0,
			'lastError'       => '',
			'startedAt'       => time(),
			'updatedAt'       => time(),
			'completedAt'     => 0,
		);
		$state = array_merge( $defaults, is_array( $state ) ? $state : array() );
		$generation_id = is_scalar( $state['generationId'] ) ? sanitize_text_field( (string) $state['generationId'] ) : '';
		$state['generationId']   = '' !== $generation_id ? $generation_id : wp_generate_uuid4();
		$state['status']         = is_scalar( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'running';
		$state['reason']         = is_scalar( $state['reason'] ) ? sanitize_key( (string) $state['reason'] ) : 'upgrade';
		$state['manifestBuffer'] = isset( $state['manifestBuffer'] ) && is_array( $state['manifestBuffer'] ) ? array_values( $state['manifestBuffer'] ) : array();
		$state['currentPayload'] = isset( $state['currentPayload'] ) && is_array( $state['currentPayload'] ) ? $state['currentPayload'] : array();
		$state['currentSource']  = is_scalar( $state['currentSource'] ) ? sanitize_key( (string) $state['currentSource'] ) : '';
		$state['currentGuid']    = is_scalar( $state['currentGuid'] ) ? sanitize_text_field( (string) $state['currentGuid'] ) : '';
		$state['lastError']      = is_scalar( $state['lastError'] ) ? sanitize_text_field( (string) $state['lastError'] ) : '';
		foreach ( array( 'ledgerCursor', 'cursor', 'processed', 'recovered', 'alreadyPresent', 'skipped', 'failed', 'retryFailures', 'quarantined', 'globalAttempts', 'currentCursor', 'currentAttempts', 'nextRetryAt', 'startedAt', 'updatedAt', 'completedAt' ) as $numeric_key ) {
			$state[ $numeric_key ] = is_scalar( $state[ $numeric_key ] ) ? absint( $state[ $numeric_key ] ) : 0;
		}
		$state['quarantineItems'] = isset( $state['quarantineItems'] ) && is_array( $state['quarantineItems'] ) ? array_slice( array_values( $state['quarantineItems'] ), -self::QUARANTINE_MAX_ITEMS ) : array();
		$phase = is_scalar( $state['phase'] ) ? sanitize_key( (string) $state['phase'] ) : '';
		$state['phase'] = in_array( $phase, array( 'local-ledger', 'portal-history' ), true ) ? $phase : 'local-ledger';
		return $state;
	}

	/**
	 * Return next product identity from local proof first, then Portal history.
	 * The state argument is by reference because phase transitions are durable.
	 *
	 * @return array|WP_Error
	 */
	private function next_candidate( &$state, $api ) {
		$phase = sanitize_key( (string) $state['phase'] );

		if ( 'local-ledger' === $phase ) {
			$rows = class_exists( 'Mobo_Core_Product_Ledger' )
				? Mobo_Core_Product_Ledger::get_after_id( absint( $state['ledgerCursor'] ), 1 )
				: array();

			if ( ! empty( $rows ) ) {
				$row    = reset( $rows );
				$cursor = absint( isset( $row['id'] ) ? $row['id'] : 0 );
				$guid   = sanitize_text_field( (string) ( isset( $row['product_guid'] ) ? $row['product_guid'] : '' ) );
				if ( $cursor > absint( $state['ledgerCursor'] ) && '' !== $guid ) {
					return array( 'source' => 'ledger', 'cursor' => $cursor, 'guid' => $guid );
				}

				/* Corrupt local evidence is terminal for this row, not for the whole site. */
				$state['ledgerCursor'] = max( absint( $state['ledgerCursor'] ), $cursor );
				$state['failed']       = absint( $state['failed'] ) + 1;
				$state['quarantined']  = absint( $state['quarantined'] ) + 1;
				$this->append_quarantine( $state, 'ledger', $guid, $cursor, 'Local recovery ledger returned an invalid product identity.', 1 );
				return array( 'phaseChanged' => true );
			}

			$state['phase']     = 'portal-history';
			$state['updatedAt'] = time();
			return array( 'phaseChanged' => true );
		}

		$items = isset( $state['manifestBuffer'] ) && is_array( $state['manifestBuffer'] ) ? $state['manifestBuffer'] : array();
		if ( empty( $items ) ) {
			$manifest = $api->get_product_recovery_manifest( absint( $state['cursor'] ), 50 );
			if ( is_wp_error( $manifest ) ) {
				return $manifest;
			}

			$items = isset( $manifest['items'] ) && is_array( $manifest['items'] ) ? array_values( $manifest['items'] ) : array();
			if ( empty( $items ) ) {
				return array( 'done' => true );
			}
			$state['manifestBuffer'] = $items;
		}

		$manifest_start_cursor = absint( $state['cursor'] );
		while ( ! empty( $state['manifestBuffer'] ) ) {
			$item   = array_shift( $state['manifestBuffer'] );
			$guid   = sanitize_text_field( (string) ( isset( $item['productGuid'] ) ? $item['productGuid'] : ( isset( $item['ProductGuid'] ) ? $item['ProductGuid'] : '' ) ) );
			$cursor = absint( isset( $item['cursor'] ) ? $item['cursor'] : ( isset( $item['Cursor'] ) ? $item['Cursor'] : 0 ) );

			if ( '' !== $guid && $cursor > absint( $state['cursor'] ) ) {
				return array( 'source' => 'portal', 'cursor' => $cursor, 'guid' => $guid );
			}

			/* Malformed historical evidence must not permanently strand every later
			 * product. Keep bounded diagnostics, advance a valid cursor when possible,
			 * and continue within the same short manifest page. */
			$state['failed']      = absint( $state['failed'] ) + 1;
			$state['quarantined'] = absint( $state['quarantined'] ) + 1;
			$this->append_quarantine( $state, 'portal-manifest', $guid, $cursor, 'Recovery manifest returned a non-advancing or invalid product identity.', 1 );
			if ( $cursor > absint( $state['cursor'] ) ) {
				$state['cursor'] = $cursor;
			}
		}

		if ( absint( $state['cursor'] ) > $manifest_start_cursor ) {
			return $this->next_candidate( $state, $api );
		}

		/* The page contained only malformed/non-advancing identities and provides no
		 * safe cursor for reaching later rows. Treat this broken page as exhausted
		 * with warnings rather than spinning forever or asking the administrator. */
		return array( 'done' => true );
	}

	private function advance_candidate( &$state, $source, $cursor ) {
		if ( 'ledger' === sanitize_key( (string) $source ) ) {
			$state['ledgerCursor'] = max( absint( $state['ledgerCursor'] ), absint( $cursor ) );
			return;
		}
		$state['cursor'] = max( absint( $state['cursor'] ), absint( $cursor ) );
	}

	private function defer_current_error( $state, $source, $guid, $cursor, $payload, $message, $handled, $restored ) {
		$attempts = absint( $state['currentAttempts'] ) + 1;
		$state['currentSource']   = sanitize_key( (string) $source );
		$state['currentGuid']     = sanitize_text_field( (string) $guid );
		$state['currentCursor']   = absint( $cursor );
		$state['currentPayload']  = is_array( $payload ) ? $payload : array();
		$state['currentAttempts'] = $attempts;
		$state['retryFailures']   = absint( $state['retryFailures'] ) + 1;
		$state['lastError']       = sanitize_text_field( (string) $message );
		$state['updatedAt']       = time();

		if ( $attempts >= self::MAX_CURRENT_ATTEMPTS ) {
			$this->advance_candidate( $state, $source, $cursor );
			$state['processed']       = absint( $state['processed'] ) + 1;
			$state['skipped']         = absint( $state['skipped'] ) + 1;
			$state['failed']          = absint( $state['failed'] ) + 1;
			$state['quarantined']     = absint( $state['quarantined'] ) + 1;
			$this->append_quarantine( $state, $source, $guid, $cursor, $state['lastError'], $attempts );
			$state['currentSource']   = '';
			$state['currentGuid']     = '';
			$state['currentCursor']   = 0;
			$state['currentPayload']  = array();
			$state['currentAttempts'] = 0;
			$state['nextRetryAt']     = 0;
			update_option( self::STATE_OPTION, $state, false );
			return array(
				'success'     => true,
				'status'      => 'quarantined',
				'remaining'   => true,
				'processed'   => $handled + 1,
				'recovered'   => $restored,
				'quarantined' => absint( $state['quarantined'] ),
				'error'       => $state['lastError'],
			);
		}

		$state['nextRetryAt'] = time() + $this->retry_delay_seconds( $attempts );
		update_option( self::STATE_OPTION, $state, false );
		return array(
			'success'     => true,
			'status'      => 'retry-wait',
			'remaining'   => true,
			'processed'   => $handled,
			'recovered'   => $restored,
			'attempts'    => $attempts,
			'error'       => $state['lastError'],
			'nextRetryAt' => $state['nextRetryAt'],
		);
	}

	private function defer_error( $state, $message, $seconds, $handled, $restored ) {
		$attempts = absint( $state['globalAttempts'] ) + 1;
		$state['globalAttempts'] = $attempts;
		$state['lastError']      = sanitize_text_field( (string) $message );
		$state['nextRetryAt']    = time() + max( max( 30, absint( $seconds ) ), $this->retry_delay_seconds( $attempts ) );
		$state['updatedAt']      = time();
		update_option( self::STATE_OPTION, $state, false );
		return array( 'success' => true, 'status' => 'retry-wait', 'remaining' => true, 'processed' => $handled, 'recovered' => $restored, 'attempts' => $attempts, 'error' => $state['lastError'], 'nextRetryAt' => $state['nextRetryAt'] );
	}

	private function retry_delay_seconds( $attempts ) {
		$steps = array( 30, 120, 600, 1800, 3600, 3600, 3600, 3600 );
		$index = min( count( $steps ) - 1, max( 0, absint( $attempts ) - 1 ) );
		return $steps[ $index ];
	}

	private function payload_has_product( $payload ) {
		if ( ! is_array( $payload ) || empty( $payload['data'] ) || ! is_array( $payload['data'] ) ) {
			return false;
		}
		$first = reset( $payload['data'] );
		return is_array( $first ) && ! empty( $first );
	}

	private function append_quarantine( &$state, $source, $guid, $cursor, $message, $attempts ) {
		$items   = isset( $state['quarantineItems'] ) && is_array( $state['quarantineItems'] ) ? array_values( $state['quarantineItems'] ) : array();
		$items[] = array(
			'source'   => sanitize_key( (string) $source ),
			'guid'     => sanitize_text_field( (string) $guid ),
			'cursor'   => absint( $cursor ),
			'error'    => sanitize_text_field( (string) $message ),
			'attempts' => absint( $attempts ),
			'at'       => time(),
		);
		$state['quarantineItems'] = array_slice( $items, -self::QUARANTINE_MAX_ITEMS );
	}

	private function mark_post_recovery_warmup_if_needed() {
		$queue = get_option( 'mobo_core_cache_warmup_queue', array() );
		if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) && is_array( $queue ) && ! empty( $queue['items'] ) ) {
			Mobo_Core_Recovery_Coordinator::mark_post_recovery_warmup_pending();
		}
	}

	private function find_local_product_id( $guid ) {
		$guid = sanitize_text_field( (string) $guid );
		if ( '' === $guid ) {
			return 0;
		}
		if ( class_exists( 'Mobo_Core_Product_Map' ) ) {
			$map = new Mobo_Core_Product_Map();
			$id  = $map->get_product_id( $guid );
			if ( $id > 0 && 'product' === get_post_type( $id ) ) {
				return $id;
			}
		}
		$query = new WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => array( 'publish', 'draft', 'private', 'pending' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( array( 'key' => 'product_guid', 'value' => $guid ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		return ! empty( $query->posts[0] ) ? absint( $query->posts[0] ) : 0;
	}
}
