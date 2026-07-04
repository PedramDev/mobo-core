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

		$ttl  = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_lock_ttl_seconds', 120, 30, 600 );
		$lock = Mobo_Core_Lock::acquire( 'real_cron_runner', $ttl );

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
		} finally {
			Mobo_Core_Lock::release( 'real_cron_runner', $lock );
		}

		if ( class_exists( 'Mobo_Core_Health_Reporter' ) ) {
			$health_reporter = new Mobo_Core_Health_Reporter();
			$result['healthReport'] = $health_reporter->send_report( $source );
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

		return array(
			'cronUrl'       => self::build_cron_url(),
			'lastHitAt'     => $last_hit,
			'lastSuccessAt' => $last_ok,
			'isActive'      => $last_hit > 0 && ( time() - $last_hit ) < HOUR_IN_SECONDS,
			'lastResult'    => $last_res,
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
			$queue          = new Mobo_Core_Webhook_Queue();
			$webhook_result = $queue->process();
		}

		$image_result = array( 'processed' => 0, 'failed' => 0, 'status' => 'skipped', 'remaining' => false );
		if ( class_exists( 'Mobo_Core_Image_Sync' ) ) {
			$image_sync   = new Mobo_Core_Image_Sync();
			$image_result = $image_sync->process_queue();
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

		if ( isset( $image_sync ) && $image_sync instanceof Mobo_Core_Image_Sync && ( time() - $started_at ) < $budget ) {
			$late_image_result = $image_sync->process_queue();

			$image_result['processed'] = ( isset( $image_result['processed'] ) ? absint( $image_result['processed'] ) : 0 ) + ( isset( $late_image_result['processed'] ) ? absint( $late_image_result['processed'] ) : 0 );
			$image_result['failed']    = ( isset( $image_result['failed'] ) ? absint( $image_result['failed'] ) : 0 ) + ( isset( $late_image_result['failed'] ) ? absint( $late_image_result['failed'] ) : 0 );
			$image_result['remaining'] = ! empty( $image_result['remaining'] ) || ! empty( $late_image_result['remaining'] );
			$image_result['latePass']  = $late_image_result;
		}

		$needs_continuation = false;
		$processed_webhooks = isset( $webhook_result['processed'] ) ? absint( $webhook_result['processed'] ) : 0;
		$remaining_webhooks = ! empty( $webhook_result['remainingFile'] ) || ! empty( $webhook_result['remainingTable'] ) || ! empty( $webhook_result['remainingDueTable'] );
		$processed_images   = isset( $image_result['processed'] ) ? absint( $image_result['processed'] ) : 0;
		$remaining_images   = ! empty( $image_result['remaining'] );
		$processed_reprice  = isset( $reprice_result['processed'] ) ? absint( $reprice_result['processed'] ) : 0;
		$remaining_reprice  = ! empty( $reprice_result['remaining'] );

		if ( $processed_webhooks > 0 && $remaining_webhooks ) {
			$needs_continuation = true;
		}

		if ( $processed_images > 0 && $remaining_images ) {
			$needs_continuation = true;
		}

		if ( $processed_reprice > 0 && $remaining_reprice ) {
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
}
