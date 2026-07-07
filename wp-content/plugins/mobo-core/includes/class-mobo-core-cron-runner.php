<?php
/**
 * Real cron runner.
 *
 * This class is intentionally independent from WP-Cron. It is triggered by a
 * server/cPanel cron that calls /wp-json/mobo-core/v1/cron/run?token=...
 * or by WP-CLI/custom integrations.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Cron_Runner {

	/**
	 * Run one bounded cron slice.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public function run( $source = 'real-cron' ) {
		$now = time();
		update_option( 'mobo_core_real_cron_last_hit_at', $now, false );

		try {
			$ttl  = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_lock_ttl_seconds', 120, 30, 600 );
			$lock = Mobo_Core_Lock::acquire( 'real_cron_runner', $ttl );
		} catch ( Throwable $e ) {
			$result = $this->exception_result( 'lock-exception', $e, array( 'source' => sanitize_key( (string) $source ) ) );
			$this->save_last_result( $result );
			return $result;
		}

		if ( false === $lock ) {
			$result = array(
				'success' => false,
				'status'  => 'locked',
				'message' => 'Cron runner is already running.',
			);

			$this->save_last_result( $result );
			return $result;
		}

		try {
			$result = $this->run_locked( $source );
		} catch ( Throwable $e ) {
			$result = $this->exception_result( 'runner-exception', $e, array( 'source' => sanitize_key( (string) $source ) ) );
		} finally {
			Mobo_Core_Lock::release( 'real_cron_runner', $lock );
		}

		if ( class_exists( 'Mobo_Core_Health_Reporter' ) ) {
			try {
				$health_reporter = new Mobo_Core_Health_Reporter();
				$result['healthReport'] = $health_reporter->send_report( $source );
			} catch ( Throwable $e ) {
				$result['healthReport'] = $this->exception_result( 'health-report-exception', $e );
			}
		}

		$this->save_last_result( $result );
		return $result;
	}

	/**
	 * Build a secure cron URL for the current site.
	 *
	 * @return string
	 */
	public static function build_cron_url() {
		$token = (string) get_option( 'mobo_core_cron_token', '' );

		if ( '' === trim( $token ) ) {
			return '';
		}

		return add_query_arg(
			array( 'token' => rawurlencode( $token ) ),
			rest_url( 'mobo-core/v1/cron/run' )
		);
	}

	/**
	 * Get cron status for admin UI and central health checks.
	 *
	 * @return array
	 */
	public static function get_status() {
		$last_hit = absint( get_option( 'mobo_core_real_cron_last_hit_at', 0 ) );
		$last_ok  = absint( get_option( 'mobo_core_real_cron_last_success_at', 0 ) );
		$last_res = get_option( 'mobo_core_real_cron_last_result', array() );

		if ( ! is_array( $last_res ) ) {
			$last_res = array();
		}

		$expected_interval = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_expected_interval_seconds', 60, 60, 3600 );
		$next_estimated_at = $last_hit > 0 ? $last_hit + $expected_interval : 0;
		$is_overdue       = $next_estimated_at > 0 && time() > ( $next_estimated_at + 30 );

		return array(
			'cronUrl'                 => self::build_cron_url(),
			'lastHitAt'               => $last_hit,
			'lastSuccessAt'           => $last_ok,
			'nextEstimatedAt'         => $next_estimated_at,
			'expectedIntervalSeconds' => $expected_interval,
			'isOverdue'               => $is_overdue,
			'secondsSinceLastHit'     => $last_hit > 0 ? max( 0, time() - $last_hit ) : 0,
			'secondsSinceLastSuccess' => $last_ok > 0 ? max( 0, time() - $last_ok ) : 0,
			'isActive'                => $last_hit > 0 && ( time() - $last_hit ) < HOUR_IN_SECONDS,
			'lastResult'              => $last_res,
		);
	}

	/**
	 * Execute work while cron lock is held.
	 *
	 * @param string $source Source.
	 * @return array
	 */
	private function run_locked( $source ) {
		$started_at = time();
		$budget     = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_time_budget_seconds', 25, 5, 55 );
		$max_steps  = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_max_sync_steps', 3, 1, 20 );

		$webhook_result = array( 'processed' => 0, 'failed' => 0, 'status' => 'skipped' );
		if ( Mobo_Core_Settings::enabled( 'mobo_core_real_cron_process_webhooks', '1' ) ) {
			try {
				$queue          = new Mobo_Core_Webhook_Queue();
				$webhook_result = $queue->process();
			} catch ( Throwable $e ) {
				$webhook_result = $this->exception_result(
					'webhook-queue-exception',
					$e,
					array(
						'processed' => 0,
						'failed'    => 1,
						'remaining' => true,
					)
				);

				update_option( 'mobo_core_webhook_queue_last_result', $webhook_result, false );
				update_option( 'mobo_core_webhook_queue_last_attempt_at', time(), false );
			}
		}

		$image_result = array( 'processed' => 0, 'failed' => 0, 'status' => 'skipped', 'remaining' => false );
		if ( class_exists( 'Mobo_Core_Image_Sync' ) ) {
			$image_sync   = new Mobo_Core_Image_Sync();
			$image_limit  = max( 3, Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 3, 0, 10 ) );
			$image_result = $image_sync->process_queue( $image_limit );
		}

		$product_sync = new Mobo_Core_Product_Sync();
		$status       = $product_sync->get_manual_sync_status();
		$steps        = 0;
		$last_step    = null;

		while ( ! empty( $status['shouldContinue'] ) && $steps < $max_steps && ( time() - $started_at ) < $budget ) {
			$last_step = $product_sync->run_manual_sync_step();
			$steps++;
			$status = $product_sync->get_manual_sync_status();
		}

		$reprice_result = array( 'processed' => 0, 'updated' => 0, 'failed' => 0, 'status' => 'skipped', 'remaining' => false );
		if ( class_exists( 'Mobo_Core_Reprice_Queue' ) && ( time() - $started_at ) < $budget ) {
			$reprice_queue  = new Mobo_Core_Reprice_Queue();
			$reprice_result = $reprice_queue->process_batch();
		}

		$recategorize_result = array( 'processed' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'status' => 'skipped', 'remaining' => false );
		if ( class_exists( 'Mobo_Core_Recategorize_Queue' ) && ( time() - $started_at ) < $budget ) {
			$recategorize_queue  = new Mobo_Core_Recategorize_Queue();
			$recategorize_result = $recategorize_queue->process_batch();
		}

		$address_mapping_result = array( 'status' => 'skipped' );
		if ( class_exists( 'Mobo_Core_Address_Mapping' ) && ( time() - $started_at ) < $budget ) {
			$address_mapping = new Mobo_Core_Address_Mapping();
			$address_mapping_result = $address_mapping->maybe_sync_if_due( $source, false );
		}

		$order_submission_result = array( 'status' => 'skipped', 'processed' => 0, 'success' => 0, 'failed' => 0, 'remaining' => false );
		if ( class_exists( 'Mobo_Core_Checkout_Validator' ) && ( time() - $started_at ) < $budget ) {
			$checkout_validator = new Mobo_Core_Checkout_Validator();
			$order_submission_result = $checkout_validator->process_queued_mobo_order_submissions( 1, $source );
		}

		if ( isset( $image_sync ) && $image_sync instanceof Mobo_Core_Image_Sync && ( time() - $started_at ) < $budget ) {
			$image_limit       = isset( $image_limit ) ? absint( $image_limit ) : max( 3, Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 3, 0, 10 ) );
			$late_image_result = $image_sync->process_queue( $image_limit );

			$image_result['processed'] = ( isset( $image_result['processed'] ) ? absint( $image_result['processed'] ) : 0 ) + ( isset( $late_image_result['processed'] ) ? absint( $late_image_result['processed'] ) : 0 );
			$image_result['failed']    = ( isset( $image_result['failed'] ) ? absint( $image_result['failed'] ) : 0 ) + ( isset( $late_image_result['failed'] ) ? absint( $late_image_result['failed'] ) : 0 );
			$image_result['remaining'] = ! empty( $image_result['remaining'] ) || ! empty( $late_image_result['remaining'] );
			$image_result['latePass']  = $late_image_result;
		}

		$maintenance_result = array( 'status' => 'skipped' );
		if ( class_exists( 'Mobo_Core_Maintenance' ) && ( time() - $started_at ) < max( 1, $budget - 2 ) ) {
			try {
				$maintenance_result = Mobo_Core_Maintenance::maybe_run( $source );
			} catch ( Throwable $e ) {
				$maintenance_result = $this->exception_result( 'maintenance-exception', $e );
			}
		}

		$needs_continuation = false;
		$processed_webhooks = isset( $webhook_result['processed'] ) ? absint( $webhook_result['processed'] ) : 0;
		$remaining_webhooks = ! empty( $webhook_result['remainingFile'] ) || ! empty( $webhook_result['remainingTable'] ) || ! empty( $webhook_result['remainingDueTable'] );
		$processed_images   = isset( $image_result['processed'] ) ? absint( $image_result['processed'] ) : 0;
		$remaining_images   = ! empty( $image_result['remaining'] );
		$processed_reprice  = isset( $reprice_result['processed'] ) ? absint( $reprice_result['processed'] ) : 0;
		$remaining_reprice  = ! empty( $reprice_result['remaining'] );
		$processed_recategorize = isset( $recategorize_result['processed'] ) ? absint( $recategorize_result['processed'] ) : 0;
		$remaining_recategorize = ! empty( $recategorize_result['remaining'] );
		$processed_order_submissions = isset( $order_submission_result['processed'] ) ? absint( $order_submission_result['processed'] ) : 0;
		$remaining_order_submissions = ! empty( $order_submission_result['remaining'] );

		if ( $processed_webhooks > 0 && $remaining_webhooks ) {
			$needs_continuation = true;
		}

		if ( $processed_images > 0 && $remaining_images ) {
			$needs_continuation = true;
		}

		if ( $processed_reprice > 0 && $remaining_reprice ) {
			$needs_continuation = true;
		}

		if ( $processed_recategorize > 0 && $remaining_recategorize ) {
			$needs_continuation = true;
		}

		if ( $processed_order_submissions > 0 && $remaining_order_submissions ) {
			$needs_continuation = true;
		}

		if ( $steps > 0 && ! empty( $status['shouldContinue'] ) ) {
			$needs_continuation = true;
		}

		$result = array(
			'success'           => true,
			'status'            => 'ok',
			'source'            => sanitize_key( (string) $source ),
			'executedAt'        => time(),
			'webhookQueue'      => $webhook_result,
			'imageQueue'        => $image_result,
			'repriceQueue'      => $reprice_result,
			'recategorizeQueue' => $recategorize_result,
			'addressMapping'     => $address_mapping_result,
			'orderSubmissions'  => $order_submission_result,
			'maintenance'       => $maintenance_result,
			'productSteps'      => $steps,
			'productStatus'     => $status,
			'lastStep'          => $last_step,
			'needsContinuation' => $needs_continuation,
			'message'           => 'Cron slice completed.',
		);

		update_option( 'mobo_core_real_cron_last_success_at', time(), false );

		return $result;
	}

	/**
	 * Save compact last result.
	 *
	 * @param array $result Result.
	 * @return void
	 */
	private function save_last_result( $result ) {
		update_option( 'mobo_core_real_cron_last_result', $result, false );
	}

	/**
	 * Build a compact, JSON-safe exception result for admin/CLI diagnostics.
	 *
	 * @param string    $status Status key.
	 * @param Throwable $e      Exception.
	 * @param array     $extra  Extra fields.
	 * @return array
	 */
	private function exception_result( $status, Throwable $e, $extra = array() ) {
		$result = array(
			'success'        => false,
			'status'         => sanitize_key( (string) $status ),
			'message'        => $e->getMessage(),
			'exceptionClass' => get_class( $e ),
			'file'           => $e->getFile(),
			'line'           => $e->getLine(),
			'executedAt'     => time(),
		);

		if ( is_array( $extra ) && ! empty( $extra ) ) {
			$result = array_merge( $result, $extra );
		}

		return $result;
	}
}
