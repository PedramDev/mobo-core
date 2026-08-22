<?php
/**
 * Customer-side self runner.
 *
 * This class intentionally avoids WP-Cron and central runner dependency.
 * It wakes the local bounded worker through a non-blocking HTTP request to
 * /wp-json/mobo-core/v1/worker/run using the X-SEC request header.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Self_Runner {

	const DISPATCH_LOCK_NAME        = 'worker_dispatcher';
	const OPTION_DISPATCH_PENDING   = 'mobo_core_worker_dispatch_pending';
	const OPTION_DISPATCH_RETRY_AT  = 'mobo_core_worker_dispatch_retry_at';
	const OPTION_DISPATCH_ATTEMPTS  = 'mobo_core_worker_dispatch_attempts';
	const OPTION_DISPATCH_ID        = 'mobo_core_worker_dispatch_id';
	const OPTION_DISPATCHED_AT      = 'mobo_core_worker_dispatched_at';
	const OPTION_WORKER_STARTED_AT  = 'mobo_core_worker_started_at';
	const DISPATCH_LEASE_TTL        = 180; // Handoff/non-arrived request lease.
	const ACTIVE_WORKER_LEASE_TTL    = 600; // Covers bounded worker + longest configured blocking call.

	/**
	 * Build local worker URL.
	 *
	 * @param string $source Source label.
	 * @return string
	 */
	public static function build_worker_url( $source = 'self-kick' ) {
		$token = (string) get_option( 'mobo_core_cron_token', '' );

		if ( '' === trim( $token ) ) {
			return '';
		}

		/* Keep the credential out of URLs/access logs; the internal kick uses X-SEC. */
		return add_query_arg(
			array(
				'source' => sanitize_key( (string) $source ),
			),
			rest_url( 'mobo-core/v1/worker/run' )
		);
	}

	/**
	 * Dispatch a non-blocking local worker request.
	 *
	 * @param string $reason Reason/source label.
	 * @param bool   $force Ignore throttle.
	 * @return array
	 */
	public static function kick( $reason = 'webhook', $force = false ) {
		$reason = sanitize_key( (string) $reason );
		$reason = '' !== $reason ? $reason : 'webhook';

		/* Durable intent first: if dispatch itself fails or another worker owns the
		 * lease, the next normal request/real cron still knows work wants a wake-up. */
		update_option( self::OPTION_DISPATCH_PENDING, '1', false );

		if ( ! did_action( 'init' ) ) {
			return self::save_kick_result(
				array(
					'success' => true,
					'status'  => 'deferred-until-init',
					'message' => 'Self runner kick was deferred until WordPress init.',
				)
			);
		}

		/* Webhooks and other producers may enqueue durable work while an upgrade is
		 * replacing plugin files. Keep the wake-up intent, but do not create another
		 * dispatcher handoff that the upgrade barrier would immediately have to drain. */
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return self::save_kick_result(
				array(
					'success'    => true,
					'status'     => 'paused-for-upgrade',
					'message'    => 'Durable work was preserved and worker dispatch is paused until the plugin upgrade releases its barrier.',
					'retryAfter' => 60,
				)
			);
		}

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_self_runner_enabled', '1' ) ) {
			return self::save_kick_result(
				array(
					'success' => true,
					'status'  => 'disabled',
					'message' => 'Self runner is disabled; persistent work remains available to real cron.',
				)
			);
		}

		$url = self::build_worker_url( $reason );
		if ( '' === $url ) {
			return self::save_kick_result(
				array(
					'success' => false,
					'status'  => 'missing-token',
					'message' => 'Worker token is missing.',
				)
			);
		}

		$now      = time();
		$retry_at = absint( get_option( self::OPTION_DISPATCH_RETRY_AT, 0 ) );
		if ( $retry_at > $now ) {
			return self::save_kick_result(
				array(
					'success'     => true,
					'status'      => 'dispatch-backoff',
					'message'     => 'Worker dispatch is waiting for bounded backoff.',
					'nextRetryAt' => $retry_at,
				)
			);
		}

		/* A successfully handed-off non-blocking request that never reached the
		 * worker is treated as a dispatch timeout after the lease window. Discovering
		 * that timeout schedules backoff rather than immediately creating a storm. */
		$last_dispatched = absint( get_option( self::OPTION_DISPATCHED_AT, 0 ) );
		$last_started    = absint( get_option( self::OPTION_WORKER_STARTED_AT, 0 ) );
		if ( $last_dispatched > 0 && $last_started < $last_dispatched && ( $now - $last_dispatched ) >= self::DISPATCH_LEASE_TTL ) {
			$attempts = absint( get_option( self::OPTION_DISPATCH_ATTEMPTS, 0 ) ) + 1;
			$delay    = self::dispatch_backoff_seconds( $attempts );
			update_option( self::OPTION_DISPATCH_ATTEMPTS, $attempts, false );
			update_option( self::OPTION_DISPATCH_RETRY_AT, $now + $delay, false );
			update_option( self::OPTION_DISPATCHED_AT, 0, false );
			return self::save_kick_result(
				array(
					'success'     => true,
					'status'      => 'dispatch-timeout-backoff',
					'message'     => 'Previous worker dispatch did not start before its lease expired; retry was backed off.',
					'attempts'    => $attempts,
					'nextRetryAt' => $now + $delay,
				)
			);
		}

		$min_interval = Mobo_Core_Settings::get_int( 'mobo_core_self_runner_min_interval_seconds', 3, 0, 60 );
		$last_attempt = absint( get_option( 'mobo_core_self_runner_last_kick_attempt_at', 0 ) );
		if ( ! $force && $min_interval > 0 && $last_attempt > 0 && ( $now - $last_attempt ) < $min_interval ) {
			return self::save_kick_result(
				array(
					'success'     => true,
					'status'      => 'throttled',
					'message'     => 'Self runner kick was throttled.',
					'lastAttempt' => $last_attempt,
					'minInterval' => $min_interval,
				)
			);
		}

		$dispatch_token = Mobo_Core_Lock::acquire( self::DISPATCH_LOCK_NAME, self::DISPATCH_LEASE_TTL );
		if ( false === $dispatch_token ) {
			return self::save_kick_result(
				array(
					'success'    => true,
					'status'     => 'dispatcher-locked',
					'httpStatus' => 423,
					'message'    => 'Another site worker request already owns the dispatcher lease.',
				)
			);
		}

		$dispatch_id = wp_generate_uuid4();
		update_option( 'mobo_core_self_runner_last_kick_attempt_at', $now, false );
		update_option( self::OPTION_DISPATCH_ID, $dispatch_id, false );

		$timeout = Mobo_Core_Settings::get_int( 'mobo_core_self_runner_http_timeout_seconds', 1, 1, 10 );
		$token   = (string) get_option( 'mobo_core_cron_token', '' );
		$args    = array(
			'timeout'     => $timeout,
			'redirection' => 0,
			'blocking'    => false,
			'sslverify'   => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'self_runner' ),
			'headers'     => array(
				'Accept'                => 'application/json',
				'X-SEC'                 => $token,
				'X-Mobo-Self-Runner'    => '1',
				'X-Mobo-Dispatch-Token' => $dispatch_token,
				'X-Mobo-Dispatch-Id'    => $dispatch_id,
			),
			'body'        => array(
				'source' => $reason,
			),
		);

		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			Mobo_Core_Lock::release( self::DISPATCH_LOCK_NAME, $dispatch_token );
			$attempts = absint( get_option( self::OPTION_DISPATCH_ATTEMPTS, 0 ) ) + 1;
			$delay    = self::dispatch_backoff_seconds( $attempts );
			update_option( self::OPTION_DISPATCH_ATTEMPTS, $attempts, false );
			update_option( self::OPTION_DISPATCH_RETRY_AT, time() + $delay, false );
			return self::save_kick_result(
				array(
					'success'     => false,
					'status'      => 'request-failed',
					'message'     => $response->get_error_message(),
					'attempts'    => $attempts,
					'nextRetryAt' => time() + $delay,
				)
			);
		}

		/* Ownership intentionally transfers to /worker/run. Do not release here. */
		update_option( self::OPTION_DISPATCHED_AT, time(), false );
		update_option( 'mobo_core_self_runner_last_kick_success_at', time(), false );

		return self::save_kick_result(
			array(
				'success'    => true,
				'status'     => 'dispatched',
				'message'    => 'Self runner request dispatched under a site-wide lease.',
				'reason'     => $reason,
				'dispatchId' => $dispatch_id,
			)
		);
	}

	/**
	 * Cancel a self-runner HTTP handoff that has not reached the worker yet.
	 *
	 * The upgrade barrier may safely cancel this pre-work lease because no worker
	 * has claimed it. The lock helper performs an atomic snapshot match, so if the
	 * request claims/renews the lease concurrently this method cannot delete the
	 * active worker lease. Durable pending intent is intentionally preserved and
	 * the updater kicks the worker again after the barrier is released.
	 *
	 * @param int $barrier_started_at Barrier activation timestamp.
	 * @return array Compact diagnostic result.
	 */
	public static function cancel_pending_handoff_for_upgrade( $barrier_started_at = 0 ) {
		$barrier_started_at = absint( $barrier_started_at );
		$last_dispatched    = absint( get_option( self::OPTION_DISPATCHED_AT, 0 ) );
		$last_started       = absint( get_option( self::OPTION_WORKER_STARTED_AT, 0 ) );

		/* OPTION_DISPATCHED_AT is cleared by claim_worker_request(). Do not rely on
		 * second-resolution started/dispatched timestamp ordering: two generations
		 * can legitimately share the same timestamp. */
		if ( $last_dispatched <= 0 ) {
			return array(
				'success'  => true,
				'status'   => 'not-pending-handoff',
				'released' => false,
			);
		}

		if ( $barrier_started_at > 0 && $last_dispatched > $barrier_started_at ) {
			return array(
				'success'  => true,
				'status'   => 'newer-than-barrier',
				'released' => false,
			);
		}

		if ( ! class_exists( 'Mobo_Core_Lock' ) ) {
			return array(
				'success'  => false,
				'status'   => 'lock-helper-unavailable',
				'released' => false,
			);
		}

		$snapshot = Mobo_Core_Lock::get_status( self::DISPATCH_LOCK_NAME );
		if ( empty( $snapshot['active'] ) ) {
			update_option( self::OPTION_DISPATCHED_AT, 0, false );
			return array(
				'success'  => true,
				'status'   => 'handoff-already-gone',
				'released' => false,
			);
		}

		/* Close the renew-before-metadata-update race. A pre-claim handoff has a
		 * fixed 180-second lease window; claim_worker_request() renews it to the
		 * 600-second active-worker window before touching OPTION_WORKER_STARTED_AT.
		 * If that renewal already happened, treat it as live work and never delete. */
		$heartbeat_at = isset( $snapshot['lastHeartbeatAt'] ) ? absint( $snapshot['lastHeartbeatAt'] ) : 0;
		$expires_at   = isset( $snapshot['expiresAt'] ) ? absint( $snapshot['expiresAt'] ) : 0;
		$lease_window = $expires_at > $heartbeat_at ? ( $expires_at - $heartbeat_at ) : 0;
		if ( $lease_window > ( self::DISPATCH_LEASE_TTL + 1 ) ) {
			return array(
				'success'          => true,
				'status'           => 'handoff-claimed-concurrently',
				'released'         => false,
				'dispatchedAt'     => $last_dispatched,
				'workerStartedAt'  => $last_started,
				'leaseWindow'      => $lease_window,
				'barrierStartedAt' => $barrier_started_at,
			);
		}

		$released = method_exists( 'Mobo_Core_Lock', 'release_if_snapshot_matches' )
			? Mobo_Core_Lock::release_if_snapshot_matches( self::DISPATCH_LOCK_NAME, $snapshot )
			: false;

		if ( $released ) {
			update_option( self::OPTION_DISPATCHED_AT, 0, false );
			update_option( self::OPTION_DISPATCH_PENDING, '1', false );
		}

		return array(
			'success'          => $released,
			'status'           => $released ? 'pending-handoff-cancelled' : 'handoff-claimed-concurrently',
			'released'         => $released,
			'dispatchedAt'     => $last_dispatched,
			'workerStartedAt'  => $last_started,
			'leaseWindow'      => $lease_window,
			'barrierStartedAt' => $barrier_started_at,
		);
	}

	/**
	 * Claim/adopt the site-wide worker dispatcher lease.
	 *
	 * @param string $transferred_token Token sent by a self-runner dispatch.
	 * @return array
	 */
	public static function claim_worker_request( $transferred_token = '' ) {
		$transferred_token = sanitize_text_field( (string) $transferred_token );
		$ttl = self::ACTIVE_WORKER_LEASE_TTL;

		if ( '' !== $transferred_token ) {
			if ( ! Mobo_Core_Lock::renew( self::DISPATCH_LOCK_NAME, $transferred_token, $ttl ) ) {
				return array( 'success' => false, 'status' => 'stale-dispatch', 'httpStatus' => 409, 'token' => '' );
			}
			$token = $transferred_token;
		} else {
			$token = Mobo_Core_Lock::acquire( self::DISPATCH_LOCK_NAME, $ttl );
			if ( false === $token ) {
				return array( 'success' => false, 'status' => 'dispatcher-locked', 'httpStatus' => 423, 'token' => '' );
			}
		}

		update_option( self::OPTION_WORKER_STARTED_AT, time(), false );
		delete_option( self::OPTION_DISPATCH_PENDING );
		delete_option( self::OPTION_DISPATCH_RETRY_AT );
		delete_option( self::OPTION_DISPATCH_ATTEMPTS );
		update_option( self::OPTION_DISPATCHED_AT, 0, false );

		return array( 'success' => true, 'status' => 'claimed', 'httpStatus' => 200, 'token' => $token );
	}

	/** Renew an active worker dispatcher lease only while the same request owns it. */
	public static function renew_worker_request( $token ) {
		$token = sanitize_text_field( (string) $token );
		if ( '' === $token ) {
			return false;
		}

		return Mobo_Core_Lock::renew( self::DISPATCH_LOCK_NAME, $token, self::ACTIVE_WORKER_LEASE_TTL );
	}

	/** Release the worker dispatcher only when the caller still owns it. */
	public static function release_worker_request( $token ) {
		return Mobo_Core_Lock::release( self::DISPATCH_LOCK_NAME, sanitize_text_field( (string) $token ) );
	}

	/**
	 * Consume a wake-up that arrived while the current worker owned the dispatcher.
	 * At most one follow-up dispatch is created after the lease is released.
	 */
	public static function consume_pending_dispatch() {
		if ( '1' !== (string) get_option( self::OPTION_DISPATCH_PENDING, '0' ) ) {
			return false;
		}
		delete_option( self::OPTION_DISPATCH_PENDING );
		return true;
	}

	/** Bounded exponential-ish retry schedule for loopback dispatch failures. */
	private static function dispatch_backoff_seconds( $attempts ) {
		$steps = array( 30, 120, 600, 1800, 3600 );
		$index = min( count( $steps ) - 1, max( 0, absint( $attempts ) - 1 ) );
		return $steps[ $index ];
	}

	/**
	 * Record a worker run result.
	 *
	 * @param array $result Runner result.
	 * @return array
	 */
	public static function record_run_result( $result ) {
		if ( ! is_array( $result ) ) {
			$result = array(
				'success' => false,
				'status'  => 'invalid-result',
			);
		}

		update_option( 'mobo_core_self_runner_last_run_at', time(), false );
		update_option( 'mobo_core_self_runner_last_run_result', $result, false );

		if ( ! empty( $result['success'] ) ) {
			update_option( 'mobo_core_self_runner_last_run_success_at', time(), false );
		}

		return $result;
	}

	/**
	 * Decide whether another local worker slice should be kicked.
	 *
	 * We only auto-chain when the previous slice made progress. This prevents a
	 * tight loop when the only remaining events are delayed retries or blocked
	 * items.
	 *
	 * @param array $result Runner result.
	 * @return bool
	 */
	public static function should_continue_after_result( $result ) {
		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_self_runner_continue_enabled', '1' ) ) {
			return false;
		}

		if ( ! is_array( $result ) || empty( $result['success'] ) ) {
			return false;
		}

		/*
		 * New runner versions calculate continuation after a full multi-round
		 * drain slice. Trust that explicit decision so every entry point behaves
		 * identically and no queue family is forgotten here.
		 */
		if ( array_key_exists( 'needsContinuation', $result ) ) {
			return ! empty( $result['needsContinuation'] );
		}

		$webhook = isset( $result['webhookQueue'] ) && is_array( $result['webhookQueue'] ) ? $result['webhookQueue'] : array();
		$processed_webhooks = isset( $webhook['processed'] ) ? absint( $webhook['processed'] ) : 0;
		$failed_webhooks    = isset( $webhook['failed'] ) ? absint( $webhook['failed'] ) : 0;
		$remaining_webhooks = ! empty( $webhook['remainingFile'] ) || ! empty( $webhook['remainingTable'] ) || ! empty( $webhook['remainingDueTable'] );
		$remaining_due_webhooks = ! empty( $webhook['remainingFile'] ) || ! empty( $webhook['remainingDueTable'] );

		if ( $processed_webhooks > 0 && $remaining_webhooks ) {
			return true;
		}

		if ( $failed_webhooks > 0 && $remaining_due_webhooks ) {
			return true;
		}

		$product_steps = isset( $result['productSteps'] ) ? absint( $result['productSteps'] ) : 0;
		$product_status = isset( $result['productStatus'] ) && is_array( $result['productStatus'] ) ? $result['productStatus'] : array();

		if ( $product_steps > 0 && ! empty( $product_status['shouldContinue'] ) ) {
			return true;
		}

		$image_queue = isset( $result['imageQueue'] ) && is_array( $result['imageQueue'] ) ? $result['imageQueue'] : array();
		$processed_images = isset( $image_queue['processed'] ) ? absint( $image_queue['processed'] ) : 0;
		if ( $processed_images > 0 && ! empty( $image_queue['remaining'] ) ) {
			return true;
		}

		$image_refresh = isset( $result['imageRefreshQueue'] ) && is_array( $result['imageRefreshQueue'] ) ? $result['imageRefreshQueue'] : array();
		$processed_image_refresh = isset( $image_refresh['processed'] ) ? absint( $image_refresh['processed'] ) : 0;
		if ( $processed_image_refresh > 0 && ! empty( $image_refresh['remaining'] ) ) {
			return true;
		}

		$image_refresh_automation = isset( $result['imageRefreshAutomation'] ) && is_array( $result['imageRefreshAutomation'] ) ? $result['imageRefreshAutomation'] : array();
		if ( ! empty( $image_refresh_automation['progressed'] ) && ! empty( $image_refresh_automation['needsContinuation'] ) ) {
			return true;
		}

		$reprice = isset( $result['repriceQueue'] ) && is_array( $result['repriceQueue'] ) ? $result['repriceQueue'] : array();
		$processed_reprice = isset( $reprice['processed'] ) ? absint( $reprice['processed'] ) : 0;
		if ( $processed_reprice > 0 && ! empty( $reprice['remaining'] ) ) {
			return true;
		}

		$recategorize = isset( $result['recategorizeQueue'] ) && is_array( $result['recategorizeQueue'] ) ? $result['recategorizeQueue'] : array();
		$processed_recategorize = isset( $recategorize['processed'] ) ? absint( $recategorize['processed'] ) : 0;
		if ( $processed_recategorize > 0 && ! empty( $recategorize['remaining'] ) ) {
			return true;
		}

		$order_submissions = isset( $result['orderSubmissions'] ) && is_array( $result['orderSubmissions'] ) ? $result['orderSubmissions'] : array();
		$processed_orders = isset( $order_submissions['processed'] ) ? absint( $order_submissions['processed'] ) : 0;
		if ( $processed_orders > 0 && ! empty( $order_submissions['remaining'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Return self runner status.
	 *
	 * @return array
	 */
	public static function get_status() {
		$queue_status = array();

		if ( class_exists( 'Mobo_Core_Webhook_Queue' ) ) {
			$queue = new Mobo_Core_Webhook_Queue();
			$queue_status = $queue->get_status();
		}

		return array(
			'enabled'            => Mobo_Core_Settings::enabled( 'mobo_core_self_runner_enabled', '1' ),
			'dispatchPending'    => '1' === (string) get_option( self::OPTION_DISPATCH_PENDING, '0' ),
			'dispatchRetryAt'    => absint( get_option( self::OPTION_DISPATCH_RETRY_AT, 0 ) ),
			'dispatchAttempts'   => absint( get_option( self::OPTION_DISPATCH_ATTEMPTS, 0 ) ),
			'dispatcher'         => class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_status( self::DISPATCH_LOCK_NAME ) : array(),
			'continueEnabled'    => Mobo_Core_Settings::enabled( 'mobo_core_self_runner_continue_enabled', '1' ),
			'workerUrl'          => self::build_worker_url( 'manual' ),
			'lastKickAttemptAt'  => absint( get_option( 'mobo_core_self_runner_last_kick_attempt_at', 0 ) ),
			'lastKickSuccessAt'  => absint( get_option( 'mobo_core_self_runner_last_kick_success_at', 0 ) ),
			'lastKickResult'     => get_option( 'mobo_core_self_runner_last_kick_result', array() ),
			'lastRunAt'          => absint( get_option( 'mobo_core_self_runner_last_run_at', 0 ) ),
			'lastRunSuccessAt'   => absint( get_option( 'mobo_core_self_runner_last_run_success_at', 0 ) ),
			'lastRunResult'      => get_option( 'mobo_core_self_runner_last_run_result', array() ),
			'queue'              => $queue_status,
		);
	}

	/**
	 * Save compact kick result.
	 *
	 * @param array $result Result.
	 * @return array
	 */
	private static function save_kick_result( $result ) {
		$now = time();
		$result['updatedAt'] = $now;

		/* High webhook/admin traffic can legitimately hit the throttle/lock path many
		 * times inside a few seconds. Persisting the same diagnostic result for every
		 * rejected kick turns a protection mechanism into an option-write storm. Keep
		 * actual dispatch/failure transitions durable, but rate-limit identical noisy
		 * statuses to one write per 30 seconds. */
		$status = isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : '';
		$noisy  = in_array( $status, array( 'throttled', 'kick-locked', 'dispatcher-locked', 'dispatch-backoff', 'dispatch-timeout-backoff', 'deferred-until-init', 'disabled' ), true );

		if ( $noisy ) {
			$previous = get_option( 'mobo_core_self_runner_last_kick_result', array() );
			if ( is_array( $previous ) ) {
				$previous_status = isset( $previous['status'] ) ? sanitize_key( (string) $previous['status'] ) : '';
				$previous_at     = isset( $previous['updatedAt'] ) ? absint( $previous['updatedAt'] ) : 0;
				if ( $status === $previous_status && $previous_at > 0 && ( $now - $previous_at ) < 30 ) {
					$result['persisted'] = false;
					return $result;
				}
			}
		}

		$result['persisted'] = true;
		update_option( 'mobo_core_self_runner_last_kick_result', $result, false );

		return $result;
	}
}
