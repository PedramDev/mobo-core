<?php
/**
 * Real cron runner.
 *
 * This class is independent from WP-Cron. The preferred cPanel integration is
 * the CLI-only mobo-cron.php worker. REST/manual callers use the same process
 * lock so they cannot overlap the dedicated CLI worker.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Cron_Runner {

	/**
	 * Run one bounded cron slice or one fair CLI-worker round.
	 *
	 * @param string $source  Source label.
	 * @param array  $options Runtime options.
	 * @return array
	 */
	public function run( $source = 'real-cron', $options = array() ) {
		$options = is_array( $options ) ? $options : array();
		$source  = sanitize_key( (string) $source );
		$source  = '' !== $source ? $source : 'real-cron';

		$owns_process_lock = false;
		$process_lock      = null;

		if ( empty( $options['processLockHeld'] ) && class_exists( 'Mobo_Core_Queue_Worker_Lock' ) ) {
			$process_lock = Mobo_Core_Queue_Worker_Lock::acquire();

			if ( is_wp_error( $process_lock ) ) {
				$result = array(
					'success' => false,
					'status'  => 'process-locked',
					'message' => $process_lock->get_error_message(),
					'source'  => $source,
				);
				$this->save_last_result( $result );
				return $result;
			}

			$owns_process_lock = true;
		}

		try {
			$now = time();
			update_option( 'mobo_core_real_cron_last_hit_at', $now, false );

			$config_result = array( 'success' => true, 'status' => 'skipped' );
			$refresh_remote_configuration = ! array_key_exists( 'refreshRemoteConfiguration', $options ) || ! empty( $options['refreshRemoteConfiguration'] );

			if ( $refresh_remote_configuration && class_exists( 'Mobo_Core_Remote_Config' ) ) {
				try {
					$config_result = Mobo_Core_Remote_Config::instance()->refresh_if_due( $source );
				} catch ( Throwable $e ) {
					$config_result = $this->exception_result( 'remote-config-exception', $e );
				}
			}

			$use_runtime_lock = empty( $options['queueWorkerMode'] );
			$lock             = null;

			if ( $use_runtime_lock ) {
				try {
					$ttl  = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_lock_ttl_seconds', 120, 30, 600 );
					$lock = Mobo_Core_Lock::acquire( 'real_cron_runner', $ttl );
				} catch ( Throwable $e ) {
					$result = $this->exception_result( 'lock-exception', $e, array( 'source' => $source ) );
					$this->save_last_result( $result );
					return $result;
				}

				if ( false === $lock ) {
					$result = array(
						'success' => false,
						'status'  => 'locked',
						'message' => 'Cron runner is already running.',
						'source'  => $source,
					);

					$this->save_last_result( $result );
					return $result;
				}
			}

			try {
				if ( ! empty( $options['queueWorkerMode'] ) ) {
					$result = $this->run_queue_worker_round_locked( $source, $options );
				} else {
					$result = $this->run_legacy_locked( $source );
				}
				$result['remoteConfiguration'] = $config_result;
			} catch ( Throwable $e ) {
				$result = $this->exception_result( 'runner-exception', $e, array( 'source' => $source ) );
			} finally {
				if ( $use_runtime_lock && false !== $lock && null !== $lock ) {
					Mobo_Core_Lock::release( 'real_cron_runner', $lock );
				}
			}

			$send_health_report = ! array_key_exists( 'sendHealthReport', $options ) || ! empty( $options['sendHealthReport'] );
			if ( $send_health_report && class_exists( 'Mobo_Core_Health_Reporter' ) ) {
				try {
					$health_reporter = new Mobo_Core_Health_Reporter();
					$result['healthReport'] = $health_reporter->send_report( $source );
				} catch ( Throwable $e ) {
					$result['healthReport'] = $this->exception_result( 'health-report-exception', $e );
				}
			}

			$this->save_last_result( $result );
			return $result;
		} finally {
			if ( $owns_process_lock && class_exists( 'Mobo_Core_Queue_Worker_Lock' ) ) {
				Mobo_Core_Queue_Worker_Lock::release( $process_lock );
			}
		}
	}

	/**
	 * Build a secure cron URL for the current site.
	 *
	 * @return string
	 */
	public static function build_cron_url() {
		if ( class_exists( 'Mobo_Core_Queue_Worker_Lock' ) && Mobo_Core_Queue_Worker_Lock::is_cli_worker_enabled() ) {
			return '';
		}

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
			'queueWorkerEnabled'      => class_exists( 'Mobo_Core_Queue_Worker_Lock' ) && Mobo_Core_Queue_Worker_Lock::is_cli_worker_enabled(),
			'queueWorkerLockPath'     => class_exists( 'Mobo_Core_Queue_Worker_Lock' ) ? Mobo_Core_Queue_Worker_Lock::path() : '',
			'queueWorkerLastStartAt'  => absint( get_option( 'mobo_core_queue_worker_last_start_at', 0 ) ),
			'queueWorkerLastEndAt'    => absint( get_option( 'mobo_core_queue_worker_last_end_at', 0 ) ),
			'queueWorkerLastResult'   => get_option( 'mobo_core_queue_worker_last_result', array() ),
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
	 * Run one fair queue round for the long-lived CLI worker.
	 *
	 * Every ready queue receives at most one bounded batch per round. The starting
	 * queue rotates between rounds so a slow queue cannot permanently starve later
	 * queues. A new batch is not started when the process is too close to its
	 * microtime deadline.
	 *
	 * @param string $source  Source label.
	 * @param array  $options Runtime options.
	 * @return array
	 */
	private function run_queue_worker_round_locked( $source, $options ) {
		$started_at = microtime( true );
		$deadline   = isset( $options['deadline'] ) ? (float) $options['deadline'] : $started_at + 8.0;
		$guard      = 2.0;
		$offset     = isset( $options['queueOffset'] ) ? absint( $options['queueOffset'] ) : 0;
		$batch_estimates = isset( $options['batchEstimatesMs'] ) && is_array( $options['batchEstimatesMs'] ) ? $options['batchEstimatesMs'] : array();

		$queue_order = array(
			'webhook',
			'image',
			'image-refresh',
			'product-sync',
			'reprice',
			'recategorize',
			'order-submission',
		);

		$count = count( $queue_order );
		if ( $count > 0 ) {
			$offset      = $offset % $count;
			$queue_order = array_merge( array_slice( $queue_order, $offset ), array_slice( $queue_order, 0, $offset ) );
		}

		$webhook_result = array( 'processed' => 0, 'failed' => 0, 'status' => 'skipped', 'remainingFile' => false, 'remainingTable' => false, 'remainingDueTable' => false );
		$image_result = array( 'processed' => 0, 'failed' => 0, 'status' => 'skipped', 'remaining' => false );
		$image_refresh_result = array( 'processed' => 0, 'failed' => 0, 'skipped' => 0, 'status' => 'skipped', 'remaining' => false );
		$image_refresh_automation = array( 'success' => true, 'status' => 'disabled', 'needsContinuation' => false, 'progressed' => false );
		$reprice_result = array( 'processed' => 0, 'updated' => 0, 'failed' => 0, 'status' => 'skipped', 'remaining' => false );
		$recategorize_result = array( 'processed' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'status' => 'skipped', 'remaining' => false );
		$order_submission_result = array( 'status' => 'skipped', 'processed' => 0, 'success' => 0, 'failed' => 0, 'remaining' => false );
		$status = array( 'shouldContinue' => false );
		$steps = 0;
		$last_step = null;
		$batch_durations = array();
		$deadline_reached = false;

		foreach ( $queue_order as $queue_name ) {
			$estimated_ms = isset( $batch_estimates[ $queue_name ] ) ? max( 0, absint( $batch_estimates[ $queue_name ] ) ) : 0;
			$queue_guard  = $estimated_ms > 0
				? max( $guard, min( 15.0, ( $estimated_ms / 1000 ) * 1.25 + 0.5 ) )
				: max( 3.0, $guard );

			if ( ! $this->can_start_worker_batch( $deadline, $queue_guard ) ) {
				$deadline_reached = true;
				break;
			}

			$batch_started = microtime( true );

			try {
				switch ( $queue_name ) {
					case 'webhook':
						if ( Mobo_Core_Settings::enabled( 'mobo_core_real_cron_process_webhooks', '1' ) && class_exists( 'Mobo_Core_Webhook_Queue' ) ) {
							$queue          = new Mobo_Core_Webhook_Queue();
							$webhook_result = $queue->process();
						}
						break;

					case 'image':
						if ( class_exists( 'Mobo_Core_Image_Sync' ) ) {
							$image_sync   = new Mobo_Core_Image_Sync();
							$image_limit  = max( 3, Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 3, 0, 10 ) );
							$image_result = $image_sync->process_queue( $image_limit );
						}
						break;

					case 'image-refresh':
						if ( class_exists( 'Mobo_Core_Image_Refresh_Automation' ) && Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_automation_enabled', '0' ) ) {
							$automation               = new Mobo_Core_Image_Refresh_Automation();
							$image_refresh_automation = $automation->run_tick( $source );
							if ( isset( $image_refresh_automation['operation'] ) && is_array( $image_refresh_automation['operation'] ) && 'process-queue' === ( isset( $image_refresh_automation['status'] ) ? $image_refresh_automation['status'] : '' ) ) {
								$image_refresh_result = $image_refresh_automation['operation'];
							}
						} elseif ( class_exists( 'Mobo_Core_Image_Refresh_Service' ) ) {
							$image_refresh_service = new Mobo_Core_Image_Refresh_Service();
							$image_refresh_limit   = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_per_run', 2, 1, 20 );
							$image_refresh_result  = $image_refresh_service->process_queue( $image_refresh_limit );
						}
						break;

					case 'product-sync':
						if ( class_exists( 'Mobo_Core_Product_Sync' ) ) {
							$product_sync = new Mobo_Core_Product_Sync();
							$status       = $product_sync->get_manual_sync_status();

							if ( ! empty( $status['shouldContinue'] ) ) {
								$sync_lock = Mobo_Core_Lock::acquire( 'manual_sync', 30 );
								if ( false === $sync_lock ) {
									$last_step = array( 'success' => false, 'status' => 'locked', 'message' => 'Product sync step is already running.' );
								} else {
									try {
										$last_step = $product_sync->run_manual_sync_step();
										$steps     = 1;
										$status    = $product_sync->get_manual_sync_status();
									} finally {
										Mobo_Core_Lock::release( 'manual_sync', $sync_lock );
									}
								}
							}
						}
						break;

					case 'reprice':
						if ( class_exists( 'Mobo_Core_Reprice_Queue' ) ) {
							$reprice_queue  = new Mobo_Core_Reprice_Queue();
							$reprice_result = $reprice_queue->process_batch();
						}
						break;

					case 'recategorize':
						if ( class_exists( 'Mobo_Core_Recategorize_Queue' ) ) {
							$recategorize_queue  = new Mobo_Core_Recategorize_Queue();
							$recategorize_result = $recategorize_queue->process_batch();
						}
						break;

					case 'order-submission':
						if ( class_exists( 'Mobo_Core_Checkout_Validator' ) ) {
							$checkout_validator      = new Mobo_Core_Checkout_Validator();
							$order_submission_result = $checkout_validator->process_queued_mobo_order_submissions( 1, $source );
						}
						break;
				}
			} catch ( Throwable $e ) {
				$failure = $this->exception_result( $queue_name . '-exception', $e, array( 'processed' => 0, 'failed' => 1, 'remaining' => true ) );

				switch ( $queue_name ) {
					case 'webhook':
						$webhook_result = $failure;
						update_option( 'mobo_core_webhook_queue_last_result', $failure, false );
						update_option( 'mobo_core_webhook_queue_last_attempt_at', time(), false );
						break;
					case 'image':
						$image_result = $failure;
						break;
					case 'image-refresh':
						$image_refresh_result = $failure;
						break;
					case 'reprice':
						$reprice_result = $failure;
						break;
					case 'recategorize':
						$recategorize_result = $failure;
						break;
					case 'order-submission':
						$order_submission_result = $failure;
						break;
					case 'product-sync':
						$last_step = $failure;
						break;
				}
			}

			$batch_durations[ $queue_name ] = (int) round( ( microtime( true ) - $batch_started ) * 1000 );
		}

		$address_mapping_result = array( 'status' => 'skipped' );
		$remote_shipping_result = array( 'status' => 'skipped' );
		$maintenance_result = array( 'status' => 'skipped' );

		if ( ! empty( $options['includeHousekeeping'] ) ) {
			if ( $this->can_start_worker_batch( $deadline, $guard ) && class_exists( 'Mobo_Core_Address_Mapping' ) ) {
				try {
					$address_mapping = new Mobo_Core_Address_Mapping();
					$address_mapping_result = $address_mapping->maybe_sync_if_due( $source, false );
				} catch ( Throwable $e ) {
					$address_mapping_result = $this->exception_result( 'address-mapping-exception', $e );
				}
			}

			if ( $this->can_start_worker_batch( $deadline, $guard ) && class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ) {
				try {
					$remote_shipping = new Mobo_Core_Remote_Shipping_Methods();
					$remote_shipping_result = $remote_shipping->maybe_sync_if_due( $source, false );
				} catch ( Throwable $e ) {
					$remote_shipping_result = $this->exception_result( 'remote-shipping-exception', $e );
				}
			}

			if ( $this->can_start_worker_batch( $deadline, max( 3.0, $guard ) ) && class_exists( 'Mobo_Core_Maintenance' ) ) {
				try {
					$maintenance_result = Mobo_Core_Maintenance::maybe_run( $source );
				} catch ( Throwable $e ) {
					$maintenance_result = $this->exception_result( 'maintenance-exception', $e );
				}
			}
		}

		$processed_webhooks = isset( $webhook_result['processed'] ) ? absint( $webhook_result['processed'] ) : 0;
		$processed_images = isset( $image_result['processed'] ) ? absint( $image_result['processed'] ) : 0;
		$processed_image_refresh = isset( $image_refresh_result['processed'] ) ? absint( $image_refresh_result['processed'] ) : 0;
		$processed_reprice = isset( $reprice_result['processed'] ) ? absint( $reprice_result['processed'] ) : 0;
		$processed_recategorize = isset( $recategorize_result['processed'] ) ? absint( $recategorize_result['processed'] ) : 0;
		$processed_orders = isset( $order_submission_result['processed'] ) ? absint( $order_submission_result['processed'] ) : 0;

		$did_work = $processed_webhooks > 0
			|| $processed_images > 0
			|| $processed_image_refresh > 0
			|| ! empty( $image_refresh_automation['progressed'] )
			|| $steps > 0
			|| $processed_reprice > 0
			|| $processed_recategorize > 0
			|| $processed_orders > 0;

		$needs_continuation = ! empty( $webhook_result['remainingFile'] )
			|| ! empty( $webhook_result['remainingTable'] )
			|| ! empty( $webhook_result['remainingDueTable'] )
			|| ! empty( $image_result['remaining'] )
			|| ! empty( $image_refresh_result['remaining'] )
			|| ! empty( $image_refresh_automation['needsContinuation'] )
			|| ! empty( $status['shouldContinue'] )
			|| ! empty( $reprice_result['remaining'] )
			|| ! empty( $recategorize_result['remaining'] )
			|| ! empty( $order_submission_result['remaining'] );

		$result = array(
			'success'                  => true,
			'status'                   => 'ok',
			'source'                   => sanitize_key( (string) $source ),
			'executedAt'               => time(),
			'webhookQueue'             => $webhook_result,
			'imageQueue'               => $image_result,
			'imageRefreshQueue'        => $image_refresh_result,
			'imageRefreshAutomation'   => $image_refresh_automation,
			'repriceQueue'             => $reprice_result,
			'recategorizeQueue'        => $recategorize_result,
			'addressMapping'           => $address_mapping_result,
			'remoteShipping'           => $remote_shipping_result,
			'orderSubmissions'         => $order_submission_result,
			'maintenance'              => $maintenance_result,
			'productSteps'             => $steps,
			'productStatus'            => $status,
			'lastStep'                 => $last_step,
			'didWork'                  => $did_work,
			'needsContinuation'        => $needs_continuation,
			'deadlineReached'          => $deadline_reached || ! $this->can_start_worker_batch( $deadline, $guard ),
			'queueOrder'               => $queue_order,
			'batchDurationsMs'         => $batch_durations,
			'roundDurationMs'          => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			'message'                  => 'Fair queue round completed.',
		);

		update_option( 'mobo_core_real_cron_last_success_at', time(), false );
		return $result;
	}

	/**
	 * Determine whether another batch may safely start before the deadline.
	 *
	 * @param float $deadline Absolute microtime deadline.
	 * @param float $guard    Required time remaining.
	 * @return bool
	 */
	private function can_start_worker_batch( $deadline, $guard = 2.0 ) {
		return ( (float) $deadline - microtime( true ) ) > max( 0.5, (float) $guard );
	}

	/**
	 * Execute work while cron lock is held.
	 *
	 * @param string $source Source.
	 * @return array
	 */
	private function run_legacy_locked( $source ) {
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

		$image_refresh_result     = array( 'processed' => 0, 'failed' => 0, 'skipped' => 0, 'status' => 'skipped', 'remaining' => false );
		$image_refresh_automation = array( 'success' => true, 'status' => 'disabled', 'needsContinuation' => false, 'progressed' => false );
		if ( class_exists( 'Mobo_Core_Image_Refresh_Automation' ) && Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_automation_enabled', '0' ) && ( time() - $started_at ) < $budget ) {
			$automation               = new Mobo_Core_Image_Refresh_Automation();
			$image_refresh_automation = $automation->run_tick( $source );
			if ( isset( $image_refresh_automation['operation'] ) && is_array( $image_refresh_automation['operation'] ) && 'process-queue' === ( isset( $image_refresh_automation['status'] ) ? $image_refresh_automation['status'] : '' ) ) {
				$image_refresh_result = $image_refresh_automation['operation'];
			}
		} elseif ( class_exists( 'Mobo_Core_Image_Refresh_Service' ) && ( time() - $started_at ) < $budget ) {
			$image_refresh_service = new Mobo_Core_Image_Refresh_Service();
			$image_refresh_limit   = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_per_run', 2, 1, 20 );
			$image_refresh_result  = $image_refresh_service->process_queue( $image_refresh_limit );
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

		$remote_shipping_result = array( 'status' => 'skipped' );
		if ( class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) && ( time() - $started_at ) < $budget ) {
			$remote_shipping = new Mobo_Core_Remote_Shipping_Methods();
			$remote_shipping_result = $remote_shipping->maybe_sync_if_due( $source, false );
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
		$processed_image_refresh = isset( $image_refresh_result['processed'] ) ? absint( $image_refresh_result['processed'] ) : 0;
		$remaining_image_refresh = ! empty( $image_refresh_result['remaining'] );
		$automation_progressed = ! empty( $image_refresh_automation['progressed'] );
		$automation_continue   = ! empty( $image_refresh_automation['needsContinuation'] );
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

		if ( $processed_image_refresh > 0 && $remaining_image_refresh ) {
			$needs_continuation = true;
		}

		if ( $automation_progressed && $automation_continue ) {
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
			'imageRefreshQueue' => $image_refresh_result,
			'imageRefreshAutomation' => $image_refresh_automation,
			'repriceQueue'      => $reprice_result,
			'recategorizeQueue' => $recategorize_result,
			'addressMapping'     => $address_mapping_result,
			'remoteShipping'     => isset( $remote_shipping_result ) ? $remote_shipping_result : array( 'status' => 'skipped' ),
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
