<?php
/**
 * Customer-side self runner.
 *
 * This class intentionally avoids WP-Cron and central runner dependency.
 * It wakes the local bounded worker through a non-blocking HTTP request to
 * /wp-json/mobo-core/v1/worker/run?token=...
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Self_Runner {

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

		return add_query_arg(
			array(
				'token'  => rawurlencode( $token ),
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
		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_self_runner_enabled', '1' ) ) {
			return self::save_kick_result(
				array(
					'success' => true,
					'status'  => 'disabled',
					'message' => 'Self runner is disabled.',
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

		$min_interval = Mobo_Core_Settings::get_int( 'mobo_core_self_runner_min_interval_seconds', 3, 0, 60 );
		$last_attempt = absint( get_option( 'mobo_core_self_runner_last_kick_attempt_at', 0 ) );

		if ( ! $force && $min_interval > 0 && $last_attempt > 0 && ( time() - $last_attempt ) < $min_interval ) {
			return self::save_kick_result(
				array(
					'success'      => true,
					'status'       => 'throttled',
					'message'      => 'Self runner kick was throttled.',
					'lastAttempt'  => $last_attempt,
					'minInterval'  => $min_interval,
				)
			);
		}

		$lock = Mobo_Core_Lock::acquire( 'self_runner_kick', 10 );

		if ( false === $lock ) {
			return self::save_kick_result(
				array(
					'success' => true,
					'status'  => 'kick-locked',
					'message' => 'Another self runner kick is already being dispatched.',
				)
			);
		}

		try {
			update_option( 'mobo_core_self_runner_last_kick_attempt_at', time(), false );

			$timeout = Mobo_Core_Settings::get_int( 'mobo_core_self_runner_http_timeout_seconds', 1, 1, 10 );
			$args    = array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'blocking'    => false,
				'sslverify'   => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'self_runner' ),
				'headers'     => array(
					'Accept'             => 'application/json',
					'X-Mobo-Self-Runner' => '1',
				),
				'body'        => array(
					'source' => sanitize_key( (string) $reason ),
				),
			);

			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				$result = array(
					'success' => false,
					'status'  => 'request-failed',
					'message' => $response->get_error_message(),
				);
			} else {
				$result = array(
					'success' => true,
					'status'  => 'dispatched',
					'message' => 'Self runner request dispatched.',
					'reason'  => sanitize_key( (string) $reason ),
				);

				update_option( 'mobo_core_self_runner_last_kick_success_at', time(), false );
			}
		} finally {
			Mobo_Core_Lock::release( 'self_runner_kick', $lock );
		}

		return self::save_kick_result( $result );
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

		$reprice = isset( $result['repriceQueue'] ) && is_array( $result['repriceQueue'] ) ? $result['repriceQueue'] : array();
		$processed_reprice = isset( $reprice['processed'] ) ? absint( $reprice['processed'] ) : 0;
		if ( $processed_reprice > 0 && ! empty( $reprice['remaining'] ) ) {
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
		$result['updatedAt'] = time();
		update_option( 'mobo_core_self_runner_last_kick_result', $result, false );

		return $result;
	}
}
