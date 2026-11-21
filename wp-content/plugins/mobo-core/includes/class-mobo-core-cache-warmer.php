<?php
/**
 * Deferred, exact-product cache warmup queue.
 *
 * Product synchronization must never block on a front-end page render. After a
 * targeted page-cache purge succeeds, the current product permalink is queued
 * here. The real Mobo cron later performs an anonymous GET for that exact URL,
 * allowing the active page-cache layer to regenerate only the product page.
 *
 * No home/category/tag/shop URLs are warmed by this class.
 *
 * PHP 7.4 compatible.
 *
 * @package MoboCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Cache_Warmer {

	const OPTION_QUEUE       = 'mobo_core_cache_warmup_queue'; // legacy migration source only
	const QUEUE_TYPE         = 'cache_warmup';
	const QUEUE_TYPE_HOT     = 'cache_warmup_hot'; // HOT is claimed ahead of WARM, without scanning/sorting 20K payloads.
	const OPTION_LAST_RESULT = 'mobo_core_cache_warmup_last_result'; // legacy Default-only migration source
	const STATE_LAST_RESULT  = 'cache_warmup_last_result';
	const QUEUE_SCHEMA       = 2;
	const QUEUE_MAX_ITEMS    = 20000; // hard total ceiling per Connection, no eviction.
	const WARM_MAX_ITEMS     = 18000; // reserve 2K entries for HOT, even during a 30K WARM storm.
	const GLOBAL_RATE_OPTION = 'mobo_core_cache_warmup_request_started_at';
	const LOCK_NAME          = 'cache_warmup_queue';
	private static $legacy_queue_checked = array(); // per Connection; Source B must not suppress Default migration.

	/**
	 * Queue the current permalink of one product.
	 *
	 * Re-queueing the same product/URL resets its retry state so the newest
	 * mutation always gets a fresh warmup attempt.
	 *
	 * @param int    $product_id WooCommerce product ID.
	 * @param string $url Optional current permalink.
	 * @param string $reason Queue reason.
	 * @return array
	 */
	public static function enqueue_product( $product_id, $url = '', $reason = 'product-updated' ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return array( 'success' => false, 'status' => 'invalid-product', 'queued' => false );
		}
		if ( function_exists( 'get_post_type' ) && 'product_variation' === get_post_type( $product_id ) ) {
			$product_id = absint( wp_get_post_parent_id( $product_id ) );
			$url = ''; // A variation never creates a second URL-keyed warmup.
		}
		if ( $product_id <= 0 ) { return array( 'success' => false, 'status' => 'invalid-parent', 'queued' => false ); }
		$tier = self::product_tier( $product_id, $reason );
		if ( 'cold' === $tier ) { return array( 'success' => true, 'status' => 'skipped-cold', 'queued' => false, 'tier' => 'cold' ); }
		if ( function_exists( 'get_post_status' ) && 'publish' !== get_post_status( $product_id ) ) { return array( 'success' => true, 'status' => 'skipped-nonpublic', 'queued' => false, 'tier' => $tier ); }

		if ( '' === (string) $url ) {
			$url = get_permalink( $product_id );
		}

		$url = self::normalize_frontend_url( $url );
		if ( '' === $url ) {
			return array( 'success' => false, 'status' => 'invalid-url', 'queued' => false );
		}

		return self::enqueue_url( $url, $product_id, $reason, $tier );
	}

	/**
	 * Queue one exact same-origin front-end URL.
	 *
	 * @param string $url Absolute URL.
	 * @param int    $product_id Product ID for diagnostics/deduplication.
	 * @param string $reason Reason.
	 * @return array
	 */
	public static function enqueue_url( $url, $product_id = 0, $reason = 'product-updated', $tier_override = '' ) {
		$url        = self::normalize_frontend_url( $url );
		$product_id = absint( $product_id );
		$reason     = sanitize_key( (string) $reason );
		if ( '' === $url ) { return array( 'success' => false, 'status' => 'invalid-url', 'queued' => false ); }
		// Warmup only a canonical WooCommerce product page. Arbitrary URLs, even
		// on this host, must not become public cache candidates.
		if ( $product_id <= 0 || ( function_exists( 'get_post_type' ) && 'product' !== get_post_type( $product_id ) ) ) { return array( 'success' => false, 'status' => 'invalid-product', 'queued' => false ); }
		if ( function_exists( 'get_post_status' ) && 'publish' !== get_post_status( $product_id ) ) { return array( 'success' => true, 'status' => 'skipped-nonpublic', 'queued' => false ); }
		if ( self::normalize_frontend_url( get_permalink( $product_id ) ) !== $url ) { return array( 'success' => false, 'status' => 'noncanonical-product-url', 'queued' => false ); }
		if ( ! self::row_store_ready() ) { return array( 'success' => false, 'status' => 'storage-unavailable', 'queued' => false ); }

		$tier = in_array( $tier_override, array( 'hot', 'warm', 'cold' ), true ) ? $tier_override : self::product_tier( $product_id, $reason );
		if ( 'cold' === $tier ) {
			return array( 'success' => true, 'status' => 'skipped-cold', 'queued' => false, 'tier' => $tier );
		}
		$key  = self::item_key( $url, $product_id );
		$type = 'hot' === $tier ? self::QUEUE_TYPE_HOT : self::QUEUE_TYPE;
		$other_type = 'hot' === $tier ? self::QUEUE_TYPE : self::QUEUE_TYPE_HOT;
		$lock = self::acquire_lock();
		if ( false === $lock ) { return array( 'success' => false, 'status' => 'locked', 'queued' => false ); }
		try {
			$previous = Mobo_Core_Work_Queue_Store::read_one( $type, $key );
			$other_previous = Mobo_Core_Work_Queue_Store::read_one( $other_type, $key );
			$capacity_state = array();
			$new_row = null === $previous && null === $other_previous;
			$moving_lane = null === $previous && null !== $other_previous;
			if ( ( $new_row || $moving_lane ) && ! self::admission_has_capacity( $capacity_state, $tier, $new_row ) ) {
				return array( 'success' => false, 'status' => 'queue-capacity', 'queued' => false, 'tier' => $tier );
			}
			$now = time();
			$debounce = Mobo_Core_Settings::get_int( 'mobo_core_cache_warmup_debounce_seconds', 15, 0, 120 );
			if ( 'warm' === $tier ) { $debounce = max( $debounce, 90 ); }
			$old_row = is_array( $previous ) ? $previous : $other_previous;
			$old = is_array( $old_row ) && isset( $old_row['payload'] ) && is_array( $old_row['payload'] ) ? $old_row['payload'] : array();
			$item = array(
				'url' => $url,
				'productId' => $product_id,
				'reason' => '' !== $reason ? $reason : 'product-updated',
				'tier' => $tier,
				'queuedAt' => isset( $old['queuedAt'] ) ? absint( $old['queuedAt'] ) : $now,
				'updatedAt' => $now,
				'attempts' => 0,
				'nextAttemptAt' => $now + $debounce,
				'revision' => wp_generate_uuid4(),
				'lastError' => '',
			);
			$ok = Mobo_Core_Work_Queue_Store::put_direct( $type, $key, $item );
			if ( ! $ok ) { return array( 'success' => false, 'status' => 'write-failed', 'queued' => false ); }
			if ( ! Mobo_Core_Work_Queue_Store::delete_key( $other_type, $key ) ) {
				return array( 'success' => false, 'status' => 'dedupe-cleanup-failed', 'queued' => false, 'tier' => $tier );
			}
			if ( $product_id > 0 ) {
				// Retire URL-keyed UX51 rows only after the stable product-key row is durable.
				if ( ! Mobo_Core_Work_Queue_Store::delete_other_object_keys( self::QUEUE_TYPE, $product_id, $key ) ||
					! Mobo_Core_Work_Queue_Store::delete_other_object_keys( self::QUEUE_TYPE_HOT, $product_id, $key ) ) {
					return array( 'success' => false, 'status' => 'legacy-dedupe-cleanup-failed', 'queued' => false, 'tier' => $tier );
				}
			}
			if ( $new_row || $moving_lane ) { self::admission_note_change( $capacity_state, $tier, $new_row, $moving_lane ); }
			$hint = $new_row || $moving_lane ? absint( $capacity_state['count'] ) : self::admission_count_hint();
			return array( 'success' => true, 'status' => 'queued', 'queued' => true, 'tier' => $tier, 'pendingCount' => $hint );
		} finally {
			self::release_lock( $lock );
		}
	}

	/** Default selective admission uses sales only as a coarse proxy; a recent
	 * traffic integration can supply an explicit HOT/WARM/COLD tier via filter. */
	private static function product_tier( $product_id, $reason ) {
		$policy = sanitize_key( (string) Mobo_Core_Settings::get( 'mobo_core_cache_warmup_policy', 'selective' ) );
		$tier = 'warm';
		if ( 'all' !== $policy ) {
			$sales = $product_id > 0 && function_exists( 'get_post_meta' ) ? absint( get_post_meta( $product_id, 'total_sales', true ) ) : 0;
			$tier = $sales > 0 ? 'warm' : 'cold'; // Historical sales are NOT proof of recent HOT traffic.
		}
		$tier = sanitize_key( (string) apply_filters( 'mobo_core_cache_warmup_product_tier', $tier, $product_id, $reason ) );
		return in_array( $tier, array( 'hot', 'warm', 'cold' ), true ) ? $tier : 'cold';
	}

	private static function row_store_ready() {
		if ( ! class_exists( 'Mobo_Core_Work_Queue_Store' ) || ! Mobo_Core_Work_Queue_Store::is_available() ) { return false; }
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? Mobo_Core_Connection_Context::get_effective_connection_id() : 0;
		if ( ! isset( self::$legacy_queue_checked[ $connection_id ] ) ) {
			if ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) {
				if ( ! Mobo_Core_Work_Queue_Store::migrate_legacy_warmup_option( self::OPTION_QUEUE, true ) ) { return false; }
			}
			self::$legacy_queue_checked[ $connection_id ] = true;
		}
		return true;
	}

	private static function combined_stats() {
		$warm = Mobo_Core_Work_Queue_Store::queue_stats( self::QUEUE_TYPE );
		$hot = Mobo_Core_Work_Queue_Store::queue_stats( self::QUEUE_TYPE_HOT );
		$oldest = array_filter( array( $warm['oldestAt'], $hot['oldestAt'] ) );
		$next = array_filter( array( $warm['nextAt'], $hot['nextAt'] ) );
		return array( 'pending' => $warm['pending'] + $hot['pending'], 'due' => $warm['due'] + $hot['due'],
			'claimed' => $warm['claimed'] + $hot['claimed'], 'retrying' => $warm['retrying'] + $hot['retrying'],
			'hot' => $hot['pending'], 'warm' => $warm['pending'],
			'oldestAt' => ! empty( $oldest ) ? min( $oldest ) : '', 'nextAt' => ! empty( $next ) ? min( $next ) : '' );
	}

	/** Admission hint is an upper bound under the warmup mutex. Normal worker
	 * deletion can only overestimate it. On pressure, refresh both lane counts. */
	private static function admission_option_key() {
		$id = class_exists( 'Mobo_Core_Connection_Context' ) ? Mobo_Core_Connection_Context::get_effective_connection_id() : 0;
		return $id > 0 ? 'mobo_core_warmup_admission_' . absint( $id ) : '';
	}
	private static function admission_has_capacity( &$state, $tier, $new_row ) {
		$key = self::admission_option_key();
		if ( '' === $key ) { return false; }
		$state = get_option( $key, array() );
		$refresh = ! is_array( $state ) || ! isset( $state['count'], $state['warm'], $state['hot'], $state['checkedAt'] )
			|| time() - absint( $state['checkedAt'] ) >= 60
			|| ( $new_row && absint( $state['count'] ) >= self::QUEUE_MAX_ITEMS )
			|| ( 'warm' === $tier && absint( isset( $state['warm'] ) ? $state['warm'] : 0 ) >= self::WARM_MAX_ITEMS );
		if ( $refresh ) {
			$warm = Mobo_Core_Work_Queue_Store::count_strict( self::QUEUE_TYPE );
			$hot = Mobo_Core_Work_Queue_Store::count_strict( self::QUEUE_TYPE_HOT );
			if ( $warm < 0 || $hot < 0 ) { return false; }
			$state = array( 'count' => $warm + $hot, 'warm' => $warm, 'hot' => $hot, 'checkedAt' => time() );
			if ( ! update_option( $key, $state, false ) && get_option( $key, array() ) !== $state ) { return false; }
		}
		if ( $new_row && absint( $state['count'] ) >= self::QUEUE_MAX_ITEMS ) { return false; }
		if ( 'warm' === $tier && absint( $state['warm'] ) >= self::WARM_MAX_ITEMS ) { return false; }
		return true;
	}
	private static function admission_note_change( $state, $tier, $new_row, $moving_lane ) {
		$key = self::admission_option_key();
		if ( '' === $key ) { return; }
		if ( $new_row ) { $state['count']++; }
		if ( $new_row || $moving_lane ) {
			$state[ $tier ]++;
			if ( $moving_lane ) {
				$old_tier = 'hot' === $tier ? 'warm' : 'hot';
				$state[ $old_tier ] = max( 0, absint( $state[ $old_tier ] ) - 1 );
			}
		}
		if ( ! update_option( $key, $state, false ) && get_option( $key, array() ) !== $state ) {
			// Drop an unreliable hint; subsequent admissions must recount via SQL.
			delete_option( $key );
		}
	}
	private static function admission_count_hint() {
		$key = self::admission_option_key();
		$value = '' !== $key ? get_option( $key, array() ) : array();
		return is_array( $value ) ? absint( isset( $value['count'] ) ? $value['count'] : 0 ) : 0;
	}

	/** Aggregate admission counters once per purge batch, never once per product. */
	public static function record_admission_metrics( $counters ) {
		if ( ! class_exists( 'Mobo_Core_State_Store' ) || ! Mobo_Core_State_Store::is_available() ) { return; }
		$stored = Mobo_Core_State_Store::get( 'cache_warmup_admission_metrics', array() );
		$stored = is_array( $stored ) ? $stored : array();
		foreach ( array( 'queued', 'skippedCold', 'capacityRejected', 'writeFailures' ) as $name ) {
			$stored[ $name ] = absint( isset( $stored[ $name ] ) ? $stored[ $name ] : 0 ) + absint( isset( $counters[ $name ] ) ? $counters[ $name ] : 0 );
		}
		$stored['updatedAt'] = time();
		Mobo_Core_State_Store::set( 'cache_warmup_admission_metrics', $stored );
	}

	/**
	 * Process a bounded number of due product warmups.
	 *
	 * @param int    $limit Maximum URLs in this call.
	 * @param int    $time_budget_seconds Cooperative time budget.
	 * @param string $source Runner source.
	 * @return array
	 */
	public static function process_batch( $limit = 2, $time_budget_seconds = 10, $source = 'real-cron' ) {
		$limit = max( 1, min( 10, absint( $limit ) ) );
		$time_budget_seconds = max( 2, min( 30, absint( $time_budget_seconds ) ) );
		$source = sanitize_key( (string) $source );
		if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) && Mobo_Core_Recovery_Coordinator::post_recovery_warmup_pending() ) { $limit = 1; }
		$started = microtime( true );
		$deadline = $started + $time_budget_seconds;
		$result = array( 'success' => true, 'status' => 'empty', 'processed' => 0, 'warmed' => 0, 'hotProcessed' => 0, 'warmProcessed' => 0, 'verifiedHit' => 0, 'unverified' => 0, 'cacheBypass' => 0,
			'failed' => 0, 'dropped' => 0, 'deferred' => 0, 'remaining' => false, 'remainingDue' => false, 'pendingCount' => 0, 'lastError' => '', 'source' => $source );
		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_cache_warmup_enabled', '1' ) ) {
			$result['status'] = 'disabled'; self::persist_last_result( $result, $started ); return $result;
		}
		if ( ! self::row_store_ready() ) {
			$result['success'] = false; $result['status'] = 'storage-unavailable'; $result['lastError'] = 'Warmup row storage is unavailable.';
			self::persist_last_result( $result, $started ); return $result;
		}
		if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) && Mobo_Core_Recovery_Coordinator::recovery_pending() ) {
			Mobo_Core_Recovery_Coordinator::mark_post_recovery_warmup_pending();
			$result['status'] = 'deferred-recovery'; $result['remaining'] = true;
			self::persist_last_result( $result, $started ); return $result;
		}
		// One installation-wide recovery lease serializes GETs across Connections.
		$pipeline_lock = class_exists( 'Mobo_Core_Recovery_Coordinator' ) ? Mobo_Core_Recovery_Coordinator::acquire( 300 ) : '__mobo_no_lock__';
		if ( false === $pipeline_lock ) { $result['status'] = 'recovery-pipeline-locked'; $result['remaining'] = true; return $result; }
		$lock = false;
		try {
			if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) && Mobo_Core_Recovery_Coordinator::recovery_pending() ) {
				Mobo_Core_Recovery_Coordinator::mark_post_recovery_warmup_pending();
				$result['status'] = 'deferred-recovery'; $result['remaining'] = true;
				self::persist_last_result( $result, $started ); return $result;
			}
			$max_attempts = Mobo_Core_Settings::get_int( 'mobo_core_cache_warmup_max_attempts', 5, 1, 10 );
			$base_timeout = Mobo_Core_Settings::get_int( 'mobo_core_cache_warmup_timeout_seconds', 8, 2, 20 );
			$previous_result = self::read_last_result();
			$hot_streak = min( 4, absint( isset( $previous_result['hotStreak'] ) ? $previous_result['hotStreak'] : 0 ) );
			while ( $result['processed'] < $limit && microtime( true ) < $deadline - 1.0 ) {
				// A prior CAS failure/continue must not carry the short mutex into the next iteration.
				if ( false !== $lock ) { self::release_lock( $lock ); $lock = false; }
				if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) && Mobo_Core_Recovery_Coordinator::recovery_pending() ) {
					Mobo_Core_Recovery_Coordinator::mark_post_recovery_warmup_pending(); $result['status'] = 'deferred-recovery'; break;
				}
				$lock = self::acquire_lock();
				if ( false === $lock ) { $result['status'] = 'locked'; break; }
				// HOT precedes WARM, but reserve every fifth slot for WARM to avoid
				// starvation under a continuous stream of high-priority changes.
				$claim_type = $hot_streak >= 4 ? self::QUEUE_TYPE : self::QUEUE_TYPE_HOT;
				$item = Mobo_Core_Work_Queue_Store::next_due_one( $claim_type );
				if ( null === $item ) { $claim_type = self::QUEUE_TYPE_HOT === $claim_type ? self::QUEUE_TYPE : self::QUEUE_TYPE_HOT; $item = Mobo_Core_Work_Queue_Store::next_due_one( $claim_type ); }
				if ( null === $item ) { break; }
				$key = (string) $item['dedupe_key'];
				$payload = $item['payload'];
				$url = self::normalize_frontend_url( isset( $payload['url'] ) ? $payload['url'] : '' );
				$product_id = absint( isset( $payload['productId'] ) ? $payload['productId'] : 0 );
				if ( $product_id <= 0 || ( function_exists( 'get_post_type' ) && 'product' !== get_post_type( $product_id ) ) ||
					( function_exists( 'get_post_status' ) && 'publish' !== get_post_status( $product_id ) ) ||
					$url !== self::normalize_frontend_url( get_permalink( $product_id ) ) ) { $url = ''; }
				$claim_token = wp_generate_uuid4();
				$expected_hash = (string) $item['payload_hash'];
				if ( ! Mobo_Core_Work_Queue_Store::claim_one( $claim_type, $key, $expected_hash, $claim_token, max( 120, $base_timeout + 30 ) ) ) { $result['deferred']++; continue; }
				if ( self::QUEUE_TYPE_HOT === $claim_type ) { $hot_streak++; $result['hotProcessed']++; }
				else { $hot_streak = 0; $result['warmProcessed']++; }
				self::release_lock( $lock ); $lock = false;
				if ( '' === $url ) {
					$response = array( 'success' => false, 'code' => 400, 'error' => 'Queued URL is invalid.', 'cacheEvidence' => 'unknown' );
				} elseif ( ! self::reserve_global_request_slot( $deadline ) ) {
					// Restore the same revision to due state without consuming a retry.
					Mobo_Core_Work_Queue_Store::finish_claim( $claim_type, $key, $expected_hash, $claim_token, $payload );
					$result['status'] = 'rate-limited'; $result['deferred']++; break;
				} else {
					$remaining = max( 2, (int) floor( $deadline - microtime( true ) ) );
					try { $response = self::warm_url( $url, min( $base_timeout, $remaining ), $product_id ); }
					catch ( Throwable $e ) { $response = array( 'success' => false, 'code' => 0, 'error' => 'Warmup failed with an internal exception.', 'cacheEvidence' => 'unknown' ); }
				}
				$result['processed']++;
				$lock = self::acquire_lock();
				if ( false === $lock ) { $result['success'] = false; $result['status'] = 'commit-lock-lost'; $result['lastError'] = 'Warmup claim could not be committed.'; break; }
				$attempt = absint( isset( $payload['attempts'] ) ? $payload['attempts'] : 0 ) + 1;
				$evidence = sanitize_key( (string) ( isset( $response['cacheEvidence'] ) ? $response['cacheEvidence'] : 'unknown' ) );
				if ( ! empty( $response['success'] ) ) {
					if ( ! Mobo_Core_Work_Queue_Store::finish_claim( $claim_type, $key, $expected_hash, $claim_token ) ) { $result['deferred']++; continue; }
					$result['warmed']++;
					if ( 'hit' === $evidence ) { $result['verifiedHit']++; } else { $result['unverified']++; }
					continue;
				}
				$error = sanitize_text_field( (string) ( isset( $response['error'] ) ? $response['error'] : 'Warmup failed.' ) );
				$code = absint( isset( $response['code'] ) ? $response['code'] : 0 );
				$result['failed']++; $result['lastError'] = $error;
				if ( 'bypass' === $evidence ) { $result['cacheBypass']++; }
				$terminal = 'bypass' === $evidence || in_array( $code, array( 400, 401, 403, 404, 405, 410 ), true );
				if ( $terminal || $attempt >= $max_attempts ) {
					if ( Mobo_Core_Work_Queue_Store::finish_claim( $claim_type, $key, $expected_hash, $claim_token ) ) { $result['dropped']++; }
					else { $result['deferred']++; }
					continue;
				}
				$payload['attempts'] = $attempt;
				$payload['updatedAt'] = time();
				$payload['nextAttemptAt'] = time() + self::retry_delay_seconds( $attempt );
				$payload['lastError'] = $error;
				unset( $payload['processingToken'], $payload['processingUntil'] ); // Only the durable row claim owns this retry.
				if ( ! Mobo_Core_Work_Queue_Store::finish_claim( $claim_type, $key, $expected_hash, $claim_token, $payload ) ) { $result['deferred']++; }
			}
			$result['hotStreak'] = $hot_streak;
			$stats = self::combined_stats();
			$result['pendingCount'] = $stats['pending'];
			$result['remaining'] = $stats['pending'] > 0;
			$result['remainingDue'] = $stats['due'] > 0;
			if ( ! in_array( $result['status'], array( 'deferred-recovery', 'commit-lock-lost', 'rate-limited', 'locked' ), true ) ) {
				$result['status'] = $result['failed'] > 0 ? ( $result['warmed'] > 0 ? 'partial' : 'failed' ) : ( $result['warmed'] > 0 ? 'success' : ( $result['remaining'] ? 'waiting' : 'empty' ) );
			}
			$result['success'] = ! in_array( $result['status'], array( 'failed', 'commit-lock-lost' ), true );
			if ( ! $result['remaining'] && class_exists( 'Mobo_Core_Recovery_Coordinator' ) && Mobo_Core_Recovery_Coordinator::post_recovery_warmup_pending() ) {
				Mobo_Core_Recovery_Coordinator::clear_post_recovery_warmup_pending();
			}
			self::persist_last_result( $result, $started );
			return $result;
		} finally {
			self::release_lock( $lock );
			if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) && '__mobo_no_lock__' !== $pipeline_lock ) { Mobo_Core_Recovery_Coordinator::release( $pipeline_lock ); }
		}
	}

	/** A serialized, installation-wide ~1 request/second admission budget. */
	private static function reserve_global_request_slot( $deadline ) {
		$gap = max( 0, min( 3, (int) apply_filters( 'mobo_core_cache_warmup_min_gap_seconds', 1 ) ) );
		if ( $gap <= 0 ) { return true; }
		$last = (float) get_option( self::GLOBAL_RATE_OPTION, 0 );
		$wait = $last + $gap - microtime( true );
		if ( $wait > 0 && microtime( true ) + $wait + 1.0 >= $deadline ) { return false; }
		if ( $wait > 0 ) { usleep( (int) min( 3000000, ceil( $wait * 1000000 ) ) ); }
		$reserved_at = (string) microtime( true );
		if ( ! update_option( self::GLOBAL_RATE_OPTION, $reserved_at, false ) && (string) get_option( self::GLOBAL_RATE_OPTION, '' ) !== $reserved_at ) {
			return false; // Never run a GET when the shared installation budget cannot be durably reserved.
		}
		return true;
	}

	/**
	 * Whether immediately runnable warmup work exists.
	 *
	 * @return bool
	 */
	public static function has_due_work() {
		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_cache_warmup_enabled', '1' ) || ! self::row_store_ready() ) { return false; }
		return Mobo_Core_Work_Queue_Store::has_due_one( self::QUEUE_TYPE_HOT ) || Mobo_Core_Work_Queue_Store::has_due_one( self::QUEUE_TYPE );
	}

	/** Compact indexed Health query; never materializes pending URLs/product IDs. */
	public static function get_status() {
		$stats = self::row_store_ready() ? self::combined_stats() : array( 'pending'=>0,'due'=>0,'oldestAt'=>'','nextAt'=>'','claimed'=>0,'retrying'=>0,'hot'=>0,'warm'=>0 );
		$last = self::read_last_result();
		$admission = class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() ? Mobo_Core_State_Store::get( 'cache_warmup_admission_metrics', array() ) : array();
		$admission = is_array( $admission ) ? $admission : array();
		$oldest = '' !== $stats['oldestAt'] ? strtotime( $stats['oldestAt'] . ' UTC' ) : 0;
		$next = '' !== $stats['nextAt'] ? strtotime( $stats['nextAt'] . ' UTC' ) : 0;
		return array(
			'enabled' => Mobo_Core_Settings::enabled( 'mobo_core_cache_warmup_enabled', '1' ),
			'policy' => sanitize_key( (string) Mobo_Core_Settings::get( 'mobo_core_cache_warmup_policy', 'selective' ) ),
			'queueCapacity' => self::QUEUE_MAX_ITEMS, 'warmCapacity' => self::WARM_MAX_ITEMS, 'hotReservedCapacity' => self::QUEUE_MAX_ITEMS - self::WARM_MAX_ITEMS,
			'pendingCount' => $stats['pending'], 'dueCount' => $stats['due'],
			'hotPendingCount' => $stats['hot'], 'warmPendingCount' => $stats['warm'],
			'claimedCount' => $stats['claimed'], 'retryCount' => $stats['retrying'],
			'oldestPendingAgeSeconds' => $oldest > 0 ? max( 0, time() - $oldest ) : 0,
			'nextAttemptAt' => self::format_timestamp( $next ),
			'lastAttemptAt' => self::format_timestamp( isset( $last['attemptedAt'] ) ? $last['attemptedAt'] : 0 ),
			'lastCompletedAt' => self::format_timestamp( isset( $last['completedAt'] ) ? $last['completedAt'] : 0 ),
			'lastStatus' => isset( $last['status'] ) ? sanitize_key( (string) $last['status'] ) : 'never-run',
			'lastProcessed' => absint( isset( $last['processed'] ) ? $last['processed'] : 0 ),
			'lastHotProcessed' => absint( isset( $last['hotProcessed'] ) ? $last['hotProcessed'] : 0 ),
			'lastWarmProcessed' => absint( isset( $last['warmProcessed'] ) ? $last['warmProcessed'] : 0 ),
			'lastWarmed' => absint( isset( $last['warmed'] ) ? $last['warmed'] : 0 ),
			'lastVerifiedHit' => absint( isset( $last['verifiedHit'] ) ? $last['verifiedHit'] : 0 ),
			'lastUnverified' => absint( isset( $last['unverified'] ) ? $last['unverified'] : 0 ),
			'lastCacheBypass' => absint( isset( $last['cacheBypass'] ) ? $last['cacheBypass'] : 0 ),
			'lastDurationMs' => absint( isset( $last['durationMs'] ) ? $last['durationMs'] : 0 ),
			'skippedColdTotal' => absint( isset( $admission['skippedCold'] ) ? $admission['skippedCold'] : 0 ),
			'capacityRejectedTotal' => absint( isset( $admission['capacityRejected'] ) ? $admission['capacityRejected'] : 0 ),
			'writeFailuresTotal' => absint( isset( $admission['writeFailures'] ) ? $admission['writeFailures'] : 0 ),
			'lastError' => isset( $last['lastError'] ) ? sanitize_text_field( (string) $last['lastError'] ) : '',
			'installation' => self::get_installation_queue_status(),
		);
	}

	/** Installation-wide non-secret summary; Source-specific payloads stay isolated. */
	private static function get_installation_queue_status() {
		$totals = array( 'connectionCount' => 0, 'pendingCount' => 0, 'dueCount' => 0, 'hotPendingCount' => 0,
			'warmPendingCount' => 0, 'disabledConnectionPending' => 0, 'truncated' => false );
		if ( ! self::row_store_ready() || ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! class_exists( 'Mobo_Core_Connection_Context' ) ) { return $totals; }
		$rows = Mobo_Core_Connection_Repository::list_all();
		$totals['truncated'] = count( $rows ) > 100;
		foreach ( array_slice( $rows, 0, 100 ) as $row ) {
			$id = absint( isset( $row['id'] ) ? $row['id'] : 0 );
			if ( $id <= 0 ) { continue; }
			$stats = Mobo_Core_Connection_Context::run( $id, static function () { return self::combined_stats(); } );
			$totals['connectionCount']++;
			$totals['pendingCount'] += $stats['pending'];
			$totals['dueCount'] += $stats['due'];
			$totals['hotPendingCount'] += $stats['hot'];
			$totals['warmPendingCount'] += $stats['warm'];
			if ( empty( $row['is_enabled'] ) ) { $totals['disabledConnectionPending'] += $stats['pending']; }
		}
		return $totals;
	}

	/**
	 * Execute one anonymous same-origin GET.
	 *
	 * @param string $url URL.
	 * @param int    $timeout Timeout seconds.
	 * @return array
	 */
	private static function warm_url( $url, $timeout, $product_id = 0 ) {
		$current = self::normalize_frontend_url( $url );
		if ( '' === $current ) {
			return array( 'success' => false, 'code' => 0, 'error' => 'Warmup URL is invalid or not same-origin.' );
		}

		$timeout = max( 2, min( 20, absint( $timeout ) ) );
		$canonical = $product_id > 0 ? self::normalize_frontend_url( get_permalink( $product_id ) ) : '';
		if ( $product_id > 0 && ( '' === $canonical || $canonical !== $current ) ) {
			return array( 'success' => false, 'code' => 400, 'error' => 'Product permalink changed before the warmup request.', 'cacheEvidence' => 'bypass' );
		}
		for ( $redirect = 0; $redirect < 3; $redirect++ ) {
			$response = wp_remote_get(
				$current,
				array(
					'timeout'     => $timeout,
					'cookies'     => array(),
					'redirection' => 0,
					'blocking'    => true,
					'user-agent'  => 'Mozilla/5.0 (compatible; MoboCore-Cache-Warmer/' . ( defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : 'unknown' ) . ')',
					'headers'     => array(
						'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'code'    => 0,
					'error'   => 'Cache warmup HTTP transport failed.',
					'transportCode' => sanitize_key( (string) $response->get_error_code() ),
				);
			}

			$code = absint( wp_remote_retrieve_response_code( $response ) );
			if ( 200 === $code ) {
				$evidence = self::cache_response_evidence( $response );
				if ( 'bypass' === $evidence ) {
					return array( 'success' => false, 'code' => $code, 'error' => 'Cache headers mark the page private, no-store or bypassed.', 'cacheEvidence' => 'bypass' );
				}
				return array( 'success' => true, 'code' => $code, 'error' => '', 'cacheEvidence' => $evidence );
			}

			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				$next     = self::resolve_redirect_url( $location, $current );
				if ( '' === $next || ( $product_id > 0 && $next !== $canonical ) ) {
					return array( 'success' => false, 'code' => $code, 'error' => 'Warmup redirect was missing, off-origin or not the canonical product page.', 'cacheEvidence' => 'bypass' );
				}
				$current = $next;
				continue;
			}

			return array(
				'success' => false,
				'code'    => $code,
				'error'   => 'Warmup HTTP status ' . $code . '.',
			);
		}

		return array( 'success' => false, 'code' => 310, 'error' => 'Warmup exceeded same-origin redirect limit.' );
	}

	/** Header evidence is deliberately conservative: first GET MISS can prime a
	 * cache, but only an explicit HIT is called verified. No second probe/GET. */
	private static function cache_response_evidence( $response ) {
		$control = strtolower( (string) wp_remote_retrieve_header( $response, 'cache-control' ) );
		$content_type = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
		$vary = strtolower( (string) wp_remote_retrieve_header( $response, 'vary' ) );
		$set_cookie = wp_remote_retrieve_header( $response, 'set-cookie' );
		if ( '' !== trim( (string) ( is_array( $set_cookie ) ? implode( ';', $set_cookie ) : $set_cookie ) ) ) { return 'bypass'; }
		if ( '*' === trim( $vary ) || preg_match( '/(?:^|[,\s])(?:cookie|authorization)(?:[,\s]|$)/i', $vary ) ) { return 'bypass'; }
		if ( '' !== $content_type && 0 !== strpos( $content_type, 'text/html' ) && 0 !== strpos( $content_type, 'application/xhtml+xml' ) ) { return 'bypass'; }
		if ( preg_match( '/(?:^|[,\s])(?:private|no-store|no-cache)(?:[,\s=]|$)/i', $control ) ) { return 'bypass'; }
		if ( '' === $content_type ) { return 'unknown'; }
		$values = array();
		foreach ( array( 'x-litespeed-cache', 'x-cache', 'cf-cache-status', 'x-proxy-cache', 'x-wp-super-cache' ) as $header ) {
			$value = wp_remote_retrieve_header( $response, $header );
			if ( is_string( $value ) && '' !== trim( $value ) ) { $values[] = strtolower( trim( $value ) ); }
		}
		foreach ( $values as $value ) {
			if ( preg_match( '/\b(?:bypass|dynamic|uncacheable)\b/i', $value ) ) { return 'bypass'; }
		}
		foreach ( $values as $value ) {
			if ( preg_match( '/\bhit\b/i', $value ) ) { return 'hit'; }
		}
		foreach ( $values as $value ) {
			if ( preg_match( '/\bmiss\b/i', $value ) ) { return 'miss'; }
		}
		return 'unknown';
	}

	/**
	 * Normalize and enforce same-origin public front-end URL.
	 *
	 * We deliberately use wp_remote_get() rather than wp_safe_remote_get() after
	 * this check because customer domains can resolve to private/local addresses
	 * from inside their own hosting network. The URL itself must still be the
	 * current site's own HTTP(S) origin.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalize_frontend_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) ) {
			return '';
		}

		$scheme    = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host      = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$home_host = isset( $home['host'] ) ? strtolower( (string) $home['host'] ) : '';

		$home_scheme = isset( $home['scheme'] ) ? strtolower( (string) $home['scheme'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || '' === $home_host || $host !== $home_host || $scheme !== $home_scheme ) { return ''; }
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) { return ''; }
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( preg_match( '#(?:^|/)(?:wp-admin|wp-json|wp-login\.php|cart|checkout|my-account|wc-api)(?:/|$)#i', rawurldecode( $path ) ) ) { return ''; }
		if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
			// Support WordPress plain product permalinks, never action/session/query variants.
			parse_str( (string) $parts['query'], $query );
			if ( ! is_array( $query ) || empty( $query ) || count( $query ) > 3 ) { return ''; }
			foreach ( $query as $name => $value ) {
				if ( ! in_array( $name, array( 'p', 'page_id', 'post_type', 'product' ), true ) || is_array( $value ) ) { return ''; }
				if ( in_array( $name, array( 'p', 'page_id' ), true ) && ! preg_match( '/^[0-9]+$/D', (string) $value ) ) { return ''; }
				if ( 'post_type' === $name && 'product' !== (string) $value ) { return ''; }
			}
		}

		/*
		 * Do not allow an otherwise valid customer hostname to be used as a loopback
		 * tunnel into an arbitrary local service port. Standard 80/443 redirects are
		 * accepted; a custom port must match the configured home URL exactly.
		 */
		$url_port  = isset( $parts['port'] ) ? absint( $parts['port'] ) : 0;
		$home_port = isset( $home['port'] ) ? absint( $home['port'] ) : 0;
		if ( $home_port > 0 ) {
			if ( $url_port !== $home_port ) {
				return '';
			}
		} elseif ( $url_port > 0 ) {
			$standard_port = ( 'https' === $scheme ) ? 443 : 80;
			if ( $url_port !== $standard_port ) {
				return '';
			}
		}

		return $url;
	}

	/**
	 * Resolve only same-origin absolute/root-relative redirects.
	 *
	 * @param string $location Redirect Location header.
	 * @param string $current Current absolute URL.
	 * @return string
	 */
	private static function resolve_redirect_url( $location, $current ) {
		$location = trim( (string) $location );
		if ( '' === $location ) {
			return '';
		}

		if ( 0 === strpos( $location, '//' ) ) {
			$current_parts = wp_parse_url( $current );
			$scheme = is_array( $current_parts ) && isset( $current_parts['scheme'] ) ? strtolower( (string) $current_parts['scheme'] ) : 'https';
			$location = $scheme . ':' . $location;
		} elseif ( 0 === strpos( $location, '/' ) ) {
			$current_parts = wp_parse_url( $current );
			if ( ! is_array( $current_parts ) || empty( $current_parts['scheme'] ) || empty( $current_parts['host'] ) ) {
				return '';
			}
			$authority = strtolower( (string) $current_parts['scheme'] ) . '://' . (string) $current_parts['host'];
			if ( isset( $current_parts['port'] ) && absint( $current_parts['port'] ) > 0 ) {
				$authority .= ':' . absint( $current_parts['port'] );
			}
			$location = $authority . $location;
		}

		return self::normalize_frontend_url( $location );
	}


	/**
	 * Whether a queue item is currently owned by a non-expired processor.
	 *
	 * @param array $item Queue item.
	 * @param int   $now Current epoch.
	 * @return bool
	 */
	private static function item_is_actively_processing( $item, $now ) {
		if ( ! is_array( $item ) ) {
			return false;
		}
		$token = isset( $item['processingToken'] ) ? trim( (string) $item['processingToken'] ) : '';
		$until = absint( isset( $item['processingUntil'] ) ? $item['processingUntil'] : 0 );
		return '' !== $token && $until > absint( $now );
	}

	/**
	 * Whether a queue item can be claimed now.
	 *
	 * Expired processing claims are deliberately reclaimable after a worker
	 * crash. A live claim is not reported as due, preventing duplicate GETs.
	 *
	 * @param array $item Queue item.
	 * @param int   $now Current epoch.
	 * @return bool
	 */
	private static function item_is_due( $item, $now ) {
		if ( ! is_array( $item ) || self::item_is_actively_processing( $item, $now ) ) {
			return false;
		}
		return absint( isset( $item['nextAttemptAt'] ) ? $item['nextAttemptAt'] : 0 ) <= absint( $now );
	}

	private static function retry_delay_seconds( $attempt ) {
		$attempt = max( 1, absint( $attempt ) );
		$delays  = array( 60, 300, 900, 3600, 21600 );
		$index   = min( count( $delays ) - 1, $attempt - 1 );
		return $delays[ $index ];
	}

	private static function item_key( $url, $product_id ) {
		return $product_id > 0 ? 'product:' . absint( $product_id ) : sha1( 'url:' . strtolower( (string) $url ) );
	}


	private static function acquire_lock() {
		if ( ! class_exists( 'Mobo_Core_Lock' ) ) {
			return '__mobo_no_lock__';
		}
		return Mobo_Core_Lock::acquire( self::LOCK_NAME, 45 );
	}

	private static function release_lock( $token ) {
		if ( '__mobo_no_lock__' === $token || false === $token || ! class_exists( 'Mobo_Core_Lock' ) ) {
			return;
		}
		Mobo_Core_Lock::release( self::LOCK_NAME, $token );
	}

	private static function persist_last_result( $result, $started ) {
		$record = is_array( $result ) ? $result : array();
		$record['pluginVersion'] = defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '';
		$record['attemptedAt']    = isset( $record['attemptedAt'] ) ? absint( $record['attemptedAt'] ) : time();
		$record['completedAt']    = time();
		$record['durationMs']     = max( 0, (int) round( ( microtime( true ) - (float) $started ) * 1000 ) );
		unset( $record['urls'], $record['items'] );
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() ) {
			Mobo_Core_State_Store::set( self::STATE_LAST_RESULT, $record );
			if ( self::legacy_default_owner_allowed() ) { delete_option( self::OPTION_LAST_RESULT ); }
			return;
		}
		if ( self::legacy_default_owner_allowed() ) { update_option( self::OPTION_LAST_RESULT, $record, false ); }
	}

	private static function read_last_result() {
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() ) {
			if ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { Mobo_Core_State_Store::migrate_option( self::OPTION_LAST_RESULT, self::STATE_LAST_RESULT ); }
			$value = Mobo_Core_State_Store::get( self::STATE_LAST_RESULT, array() );
			return is_array( $value ) ? $value : array();
		}
		if ( ! self::legacy_default_owner_allowed() || ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) ) { return array(); }
		$value = get_option( self::OPTION_LAST_RESULT, array() );
		return is_array( $value ) ? $value : array();
	}

	private static function legacy_default_owner_allowed() {
		return ! class_exists( 'Mobo_Core_Connection_Context' ) || ! method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) || Mobo_Core_Connection_Context::legacy_default_owner_allowed();
	}

	private static function format_timestamp( $timestamp ) {
		$timestamp = absint( $timestamp );
		return $timestamp > 0 ? gmdate( 'c', $timestamp ) : '';
	}
}
