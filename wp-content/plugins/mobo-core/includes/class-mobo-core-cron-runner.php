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
	 * One invocation drains multiple fair queue rounds until the effective time
	 * budget is exhausted, no immediately runnable work remains, or progress
	 * stops. The global runner lease is renewed before every major stage.
	 *
	 * @param string $source Source label.
	 * @param bool   $send_health_report Send the outbound health report after the slice.
	 * @param array  $runtime_overrides Optional bounded runtime overrides.
	 * @return array
	 */
	public function run( $source = 'real-cron', $send_health_report = true, $runtime_overrides = array() ) {
		$source = sanitize_key( (string) $source );
		$source = '' !== $source ? $source : 'real-cron';

		update_option( 'mobo_core_real_cron_last_hit_at', time(), false );

		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			$result               = Mobo_Core_Upgrade_Coordinator::paused_result( 'cron-runner' );
			$result['source']     = $source;
			$result['executedAt'] = time();
			$this->save_last_result( $result );
			return $result;
		}

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		try {
			$config = $this->get_runtime_config( $runtime_overrides );
			$lock   = Mobo_Core_Lock::acquire( 'real_cron_runner', $config['lockTtlSeconds'] );
		} catch ( Throwable $e ) {
			$result = $this->exception_result( 'lock-exception', $e, array( 'source' => $source ) );
			$this->save_last_result( $result );
			return $result;
		}

		if ( false === $lock ) {
			$result = array(
				'success' => false,
				'status'  => 'locked',
				'source'  => $source,
				'message' => 'Cron runner is already running.',
				'lock'    => Mobo_Core_Lock::get_status( 'real_cron_runner' ),
			);

			$this->save_last_result( $result );
			return $result;
		}

		/*
		 * A fatal error or explicit exit normally still runs shutdown callbacks.
		 * release() verifies the token, so it cannot delete a newer owner's lease.
		 * If shutdown itself is skipped, the finite lease expires automatically.
		 */
		register_shutdown_function( array( 'Mobo_Core_Lock', 'release' ), 'real_cron_runner', $lock );

		try {
			$result = $this->run_locked( $source, $lock, $config );
		} catch ( Throwable $e ) {
			$result = $this->exception_result(
				'runner-exception',
				$e,
				array(
					'source' => $source,
					'runner' => array(
						'configuredTimeBudgetSeconds' => $config['configuredTimeBudgetSeconds'],
						'effectiveTimeBudgetSeconds'  => $config['effectiveTimeBudgetSeconds'],
						'lockTtlSeconds'              => $config['lockTtlSeconds'],
					),
				)
			);
		} finally {
			$result['lockReleased'] = Mobo_Core_Lock::release( 'real_cron_runner', $lock );
		}

		/*
		 * Continue immediately after a bounded slice when real progress was made
		 * and due work remains. This applies to direct cPanel/PHP cron as well as
		 * the local REST worker, so a full queue does not wait for the next minute.
		 */
		if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
			try {
				if ( Mobo_Core_Self_Runner::should_continue_after_result( $result ) ) {
					$result['continuationKick'] = Mobo_Core_Self_Runner::kick( 'cron-continuation', true );
				} else {
					$result['continuationKick'] = array(
						'success' => true,
						'status'  => 'not-needed',
					);
				}
			} catch ( Throwable $e ) {
				$result['continuationKick'] = $this->exception_result( 'continuation-kick-exception', $e );
			}
		}

		/*
		 * Persist the current runner result before building health payloads so the
		 * report contains this invocation rather than the previous cron slice.
		 */
		$this->save_last_result( $result );

		if ( $send_health_report && class_exists( 'Mobo_Core_Health_Reporter' ) ) {
			try {
				$health_reporter       = new Mobo_Core_Health_Reporter();
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
		$is_overdue        = $next_estimated_at > 0 && time() > ( $next_estimated_at + 30 );
		$lock_status       = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_status( 'real_cron_runner' ) : array();

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
			'lock'                    => $lock_status,
			'lastResult'              => $last_res,
		);
	}

	/**
	 * Return a compact, token-free status for the central health report.
	 *
	 * @return array
	 */
	public static function get_health_status() {
		$status = self::get_status();
		$last   = isset( $status['lastResult'] ) && is_array( $status['lastResult'] ) ? $status['lastResult'] : array();
		$runner = isset( $last['runner'] ) && is_array( $last['runner'] ) ? $last['runner'] : array();

		return array(
			'pluginVersion'           => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
			'lastHitAt'               => isset( $status['lastHitAt'] ) ? absint( $status['lastHitAt'] ) : 0,
			'lastSuccessAt'           => isset( $status['lastSuccessAt'] ) ? absint( $status['lastSuccessAt'] ) : 0,
			'expectedIntervalSeconds' => isset( $status['expectedIntervalSeconds'] ) ? absint( $status['expectedIntervalSeconds'] ) : 0,
			'isOverdue'               => ! empty( $status['isOverdue'] ),
			'lock'                    => isset( $status['lock'] ) && is_array( $status['lock'] ) ? $status['lock'] : array(),
			'lastRun'                 => array(
				'success'                       => ! empty( $last['success'] ),
				'status'                        => isset( $last['status'] ) ? sanitize_key( (string) $last['status'] ) : 'never-run',
				'source'                        => isset( $last['source'] ) ? sanitize_key( (string) $last['source'] ) : '',
				'executedAt'                    => isset( $last['executedAt'] ) ? absint( $last['executedAt'] ) : 0,
				'needsContinuation'             => ! empty( $last['needsContinuation'] ),
				'rounds'                        => isset( $runner['rounds'] ) ? absint( $runner['rounds'] ) : 0,
				'maxRounds'                     => isset( $runner['maxRounds'] ) ? absint( $runner['maxRounds'] ) : 0,
				'productStepsPerRound'          => isset( $runner['productStepsPerRound'] ) ? absint( $runner['productStepsPerRound'] ) : 0,
				'configuredTimeBudgetSeconds'   => isset( $runner['configuredTimeBudgetSeconds'] ) ? absint( $runner['configuredTimeBudgetSeconds'] ) : 0,
				'effectiveTimeBudgetSeconds'    => isset( $runner['effectiveTimeBudgetSeconds'] ) ? absint( $runner['effectiveTimeBudgetSeconds'] ) : 0,
				'safetyMarginSeconds'           => isset( $runner['safetyMarginSeconds'] ) ? absint( $runner['safetyMarginSeconds'] ) : 0,
				'elapsedMs'                     => isset( $runner['elapsedMs'] ) ? absint( $runner['elapsedMs'] ) : 0,
				'stopReason'                    => isset( $runner['stopReason'] ) ? sanitize_key( (string) $runner['stopReason'] ) : '',
				'madeProgress'                  => ! empty( $runner['madeProgress'] ),
				'hasImmediateWork'              => ! empty( $runner['hasImmediateWork'] ),
				'lockRenewals'                  => isset( $runner['lockRenewals'] ) ? absint( $runner['lockRenewals'] ) : 0,
				'lockLost'                      => ! empty( $runner['lockLost'] ),
				'configuredLockTtlSeconds'      => isset( $runner['configuredLockTtlSeconds'] ) ? absint( $runner['configuredLockTtlSeconds'] ) : 0,
				'effectiveLockTtlSeconds'       => isset( $runner['effectiveLockTtlSeconds'] ) ? absint( $runner['effectiveLockTtlSeconds'] ) : 0,
				'longestBlockingTimeoutSeconds' => isset( $runner['longestBlockingTimeoutSeconds'] ) ? absint( $runner['longestBlockingTimeoutSeconds'] ) : 0,
				'queuePasses'                   => isset( $runner['queuePasses'] ) && is_array( $runner['queuePasses'] )
					? array_map( 'absint', $runner['queuePasses'] )
					: array(),
				'failedStages'                  => isset( $runner['failedStages'] ) && is_array( $runner['failedStages'] )
					? array_values( array_map( 'sanitize_key', $runner['failedStages'] ) )
					: array(),
			),
		);
	}

	/**
	 * Resolve safe runtime budgets and lease TTL.
	 *
	 * The configured lock TTL is treated as a minimum. The actual lease also
	 * covers the longest configured blocking HTTP request plus recovery grace,
	 * preventing overlap while one request is legitimately still in flight.
	 *
	 * @param array $overrides Optional heartbeat/runtime limits.
	 * @return array
	 */
	private function get_runtime_config( $overrides = array() ) {
		$configured_budget = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_time_budget_seconds', 25, 5, 55 );
		$safety_margin     = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_safety_margin_seconds', 3, 1, 10 );
		$php_limit         = absint( ini_get( 'max_execution_time' ) );
		$effective_budget  = $configured_budget;

		if ( $php_limit > 0 ) {
			$effective_budget = min( $configured_budget, max( 1, $php_limit - $safety_margin ) );
		}

		$api_timeout      = Mobo_Core_Settings::get_int( 'mobo_core_api_request_timeout_seconds', 60, 5, 180 );
		$payload_timeout  = Mobo_Core_Settings::get_int( 'mobo_core_payload_pull_timeout_seconds', 60, 5, 180 );
		$checkout_timeout = Mobo_Core_Settings::get_int( 'mobo_core_checkout_mobo_timeout_seconds', 8, 2, 20 );
		$blocking_timeout = max( 15, $api_timeout, $payload_timeout, $checkout_timeout );
		$configured_ttl   = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_lock_ttl_seconds', 120, 30, 600 );
		$lock_ttl         = min( 600, max( $configured_ttl, $effective_budget + 30, $blocking_timeout + 30 ) );

		$max_rounds              = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_max_rounds', 100, 1, 500 );
		$product_steps_per_round = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_max_sync_steps', 3, 1, 20 );

		if ( is_array( $overrides ) ) {
			if ( isset( $overrides['maxTimeBudgetSeconds'] ) ) {
				$override_budget  = max( 3, min( 55, absint( $overrides['maxTimeBudgetSeconds'] ) ) );
				$configured_budget = min( $configured_budget, $override_budget );
				$effective_budget  = min( $effective_budget, $override_budget );
			}

			if ( isset( $overrides['maxRounds'] ) ) {
				$max_rounds = max( 1, min( 500, absint( $overrides['maxRounds'] ) ) );
			}

			if ( isset( $overrides['productStepsPerRound'] ) ) {
				$product_steps_per_round = max( 1, min( 20, absint( $overrides['productStepsPerRound'] ) ) );
			}
		}

		$lock_ttl = min( 600, max( $configured_ttl, $effective_budget + 30, $blocking_timeout + 30 ) );

		return array(
			'configuredTimeBudgetSeconds' => $configured_budget,
			'effectiveTimeBudgetSeconds'  => $effective_budget,
			'safetyMarginSeconds'         => $safety_margin,
			'maxRounds'                   => $max_rounds,
			'productStepsPerRound'        => $product_steps_per_round,
			'lockTtlSeconds'              => $lock_ttl,
			'configuredLockTtlSeconds'    => $configured_ttl,
			'longestBlockingTimeout'      => $blocking_timeout,
		);
	}

	/**
	 * Drain fair queue rounds while the runner lease is held.
	 *
	 * @param string $source Source.
	 * @param string $lock_token Lock owner token.
	 * @param array  $config Runtime configuration.
	 * @return array
	 */
	private function run_locked( $source, $lock_token, $config ) {
		$started_at      = microtime( true );
		$deadline        = $started_at + max( 1, (int) $config['effectiveTimeBudgetSeconds'] );
		$rounds          = 0;
		$lock_renewals   = 0;
		$lock_lost       = false;
		$upgrade_paused  = false;
		$stop_reason     = 'queues-empty-or-deferred';
		$made_progress   = false;
		$immediate_work  = false;
		$disabled_stages = array();
		$aggregate       = $this->empty_aggregate_result( $source );

		while ( $rounds < absint( $config['maxRounds'] ) ) {
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				$upgrade_paused = true;
				$stop_reason    = 'plugin-upgrade-barrier';
				break;
			}

			if ( ! $this->has_time_remaining( $deadline, $config['safetyMarginSeconds'] ) ) {
				$stop_reason = 'time-budget-exhausted';
				break;
			}

			if ( ! $this->renew_runner_lock( $lock_token, $config['lockTtlSeconds'], $lock_renewals ) ) {
				$lock_lost   = true;
				$stop_reason = 'lock-lost';
				break;
			}

			$rounds++;
			$round = $this->run_one_round(
				$source,
				$rounds,
				$deadline,
				$config,
				$lock_token,
				$lock_renewals,
				$disabled_stages
			);

			$this->merge_round_result( $aggregate, $round );
			$made_progress  = $made_progress || ! empty( $round['madeProgress'] );
			$immediate_work = ! empty( $round['hasImmediateWork'] );

			if ( ! empty( $round['lockLost'] ) ) {
				$lock_lost   = true;
				$stop_reason = 'lock-lost';
				break;
			}

			if ( ! empty( $round['upgradePaused'] ) ) {
				$upgrade_paused = true;
				$stop_reason    = 'plugin-upgrade-barrier';
				break;
			}

			if ( ! empty( $round['deadlineReached'] ) || ! $this->has_time_remaining( $deadline, $config['safetyMarginSeconds'] ) ) {
				$stop_reason = 'time-budget-exhausted';
				break;
			}

			if ( empty( $round['madeProgress'] ) ) {
				$stop_reason = $immediate_work ? 'no-progress' : 'queues-empty-or-deferred';
				break;
			}

			if ( ! $immediate_work ) {
				$stop_reason = 'queues-empty-or-deferred';
				break;
			}
		}

		if ( ! $upgrade_paused && $rounds >= absint( $config['maxRounds'] ) && $immediate_work ) {
			$stop_reason = 'max-rounds-reached';
		}

		$elapsed_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

		/*
		 * Auto-chain only when progress was made. A no-progress/locked/deferred
		 * queue waits for the next real cron and cannot create a tight loop.
		 */
		$needs_continuation = ! $lock_lost
			&& ! $upgrade_paused
			&& $made_progress
			&& (
				$immediate_work
				|| in_array( $stop_reason, array( 'time-budget-exhausted', 'max-rounds-reached' ), true )
			);

		$aggregate['success']           = ! $lock_lost;
		$aggregate['status']            = $upgrade_paused
			? 'paused-for-upgrade'
			: ( $lock_lost ? 'lock-lost' : ( empty( $aggregate['runnerErrors'] ) ? 'ok' : 'partial' ) );
		$aggregate['executedAt']        = time();
		$aggregate['needsContinuation'] = $needs_continuation;
		$aggregate['message']           = $upgrade_paused
			? 'Cron processing reached a safe boundary and paused for plugin upgrade.'
			: ( $lock_lost
				? 'Cron processing stopped because the runner lease was lost.'
				: 'Cron queue drain slice completed.' );
		if ( $upgrade_paused && class_exists( 'Mobo_Core_Upgrade_Coordinator' ) ) {
			$aggregate['upgradeBarrier'] = Mobo_Core_Upgrade_Coordinator::get_status();
		}
		$aggregate['runner']            = array(
			'pluginVersion'                   => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
			'configuredTimeBudgetSeconds'     => absint( $config['configuredTimeBudgetSeconds'] ),
			'effectiveTimeBudgetSeconds'      => absint( $config['effectiveTimeBudgetSeconds'] ),
			'safetyMarginSeconds'             => absint( $config['safetyMarginSeconds'] ),
			'elapsedMs'                       => $elapsed_ms,
			'rounds'                          => $rounds,
			'maxRounds'                       => absint( $config['maxRounds'] ),
			'productStepsPerRound'            => absint( $config['productStepsPerRound'] ),
			'stopReason'                      => $stop_reason,
			'madeProgress'                    => $made_progress,
			'hasImmediateWork'                => $immediate_work,
			'lockLost'                        => $lock_lost,
			'upgradePaused'                    => $upgrade_paused,
			'lockRenewals'                    => $lock_renewals,
			'configuredLockTtlSeconds'        => absint( $config['configuredLockTtlSeconds'] ),
			'effectiveLockTtlSeconds'         => absint( $config['lockTtlSeconds'] ),
			'longestBlockingTimeoutSeconds'   => absint( $config['longestBlockingTimeout'] ),
			'failedStages'                    => array_values( array_keys( $disabled_stages ) ),
			'queuePasses'                     => $aggregate['queuePasses'],
		);

		if ( ! $lock_lost ) {
			update_option( 'mobo_core_real_cron_last_success_at', time(), false );
		}

		return $aggregate;
	}

	/**
	 * Run one fair pass over all queue families.
	 *
	 * @param string $source Source.
	 * @param int    $round_number Round number.
	 * @param float  $deadline Absolute microtime deadline.
	 * @param array  $config Runtime config.
	 * @param string $lock_token Lock token.
	 * @param int    $lock_renewals Renewal counter by reference.
	 * @param array  $disabled_stages Stages disabled after an exception by reference.
	 * @return array
	 */
	private function run_one_round( $source, $round_number, $deadline, $config, $lock_token, &$lock_renewals, &$disabled_stages ) {
		$round = $this->empty_round_result();

		/* Webhook queue. */
		if ( Mobo_Core_Settings::enabled( 'mobo_core_real_cron_process_webhooks', '1' ) && ! isset( $disabled_stages['webhookQueue'] ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			$remaining_seconds = max( 1, (int) floor( $deadline - microtime( true ) - 0.25 ) );
			$webhook_budget     = min( Mobo_Core_Settings::get_int( 'mobo_core_sync_time_budget_seconds', 8, 2, 25 ), $remaining_seconds );
			$round['webhookQueue'] = $this->execute_stage(
				'webhookQueue',
				function () use ( $webhook_budget ) {
					$queue = new Mobo_Core_Webhook_Queue();
					return $queue->process( $webhook_budget );
				},
				array( 'processed' => 0, 'failed' => 1, 'status' => 'exception', 'remainingFile' => true, 'remainingTable' => true, 'remainingDueTable' => false ),
				$disabled_stages,
				$round['stageErrors']
			);
			$round['queuePasses']['webhookQueue']++;
		}

		/* Product image queue. */
		$image_sync  = null;
		$image_limit = max( 3, Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 3, 0, 10 ) );
		if ( class_exists( 'Mobo_Core_Image_Sync' ) && ! isset( $disabled_stages['imageQueue'] ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			$image_sync = new Mobo_Core_Image_Sync();
			$round['imageQueue'] = $this->execute_stage(
				'imageQueue',
				function () use ( $image_sync, $image_limit ) {
					return $image_sync->process_queue( $image_limit );
				},
				array( 'processed' => 0, 'failed' => 1, 'status' => 'exception', 'remaining' => true ),
				$disabled_stages,
				$round['stageErrors']
			);
			$round['queuePasses']['imageQueue']++;
		}

		/* Image refresh workflow/queue. */
		if ( ! isset( $disabled_stages['imageRefreshQueue'] ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			if ( class_exists( 'Mobo_Core_Image_Refresh_Automation' ) && Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_automation_enabled', '0' ) ) {
				$round['imageRefreshAutomation'] = $this->execute_stage(
					'imageRefreshQueue',
					function () use ( $source ) {
						$automation = new Mobo_Core_Image_Refresh_Automation();
						return $automation->run_tick( $source );
					},
					array( 'success' => false, 'status' => 'exception', 'needsContinuation' => false, 'progressed' => false ),
					$disabled_stages,
					$round['stageErrors']
				);

				if ( isset( $round['imageRefreshAutomation']['operation'] ) && is_array( $round['imageRefreshAutomation']['operation'] ) ) {
					$round['imageRefreshQueue'] = $round['imageRefreshAutomation']['operation'];
				}
			} elseif ( class_exists( 'Mobo_Core_Image_Refresh_Service' ) ) {
				$image_refresh_limit = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_per_run', 2, 1, 20 );
				$round['imageRefreshQueue'] = $this->execute_stage(
					'imageRefreshQueue',
					function () use ( $image_refresh_limit ) {
						$service = new Mobo_Core_Image_Refresh_Service();
						return $service->process_queue( $image_refresh_limit );
					},
					array( 'processed' => 0, 'failed' => 1, 'skipped' => 0, 'status' => 'exception', 'remaining' => true ),
					$disabled_stages,
					$round['stageErrors']
				);
			}
			$round['queuePasses']['imageRefreshQueue']++;
		}

		/* Product sync steps. */
		if ( ! isset( $disabled_stages['productSync'] ) ) {
			$product_sync = new Mobo_Core_Product_Sync();
			$status       = $product_sync->get_manual_sync_status();
			$steps        = 0;

			while ( ! empty( $status['shouldContinue'] ) && $steps < absint( $config['productStepsPerRound'] ) ) {
				if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
					break;
				}

				try {
					$round['lastStep'] = $product_sync->run_manual_sync_step();
					$steps++;
					$status = $product_sync->get_manual_sync_status();
				} catch ( Throwable $e ) {
					$disabled_stages['productSync'] = true;
					$round['stageErrors'][] = $this->compact_stage_error( 'productSync', $e );
					break;
				}
			}

			$round['productSteps']  = $steps;
			$round['productStatus'] = $status;
			if ( $steps > 0 ) {
				$round['queuePasses']['productSync']++;
			}
		}


		/* Adaptive reconciliation / sync health. */
		if ( 1 === absint( $round_number ) && class_exists( 'Mobo_Core_Reconciliation' ) && ! isset( $disabled_stages['reconciliation'] ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			$round['reconciliation'] = $this->execute_stage(
				'reconciliation',
				function () use ( $source ) {
					$reconciliation = new Mobo_Core_Reconciliation();
					return $reconciliation->run_tick( $source, false, false );
				},
				array( 'success' => false, 'status' => 'exception', 'processedProducts' => 0, 'processedVariations' => 0, 'needsContinuation' => false ),
				$disabled_stages,
				$round['stageErrors']
			);
			$round['queuePasses']['reconciliation']++;
		}

		/* Reprice queue. */
		if ( class_exists( 'Mobo_Core_Reprice_Queue' ) && ! isset( $disabled_stages['repriceQueue'] ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			$round['repriceQueue'] = $this->execute_stage(
				'repriceQueue',
				function () {
					$queue = new Mobo_Core_Reprice_Queue();
					return $queue->process_batch();
				},
				array( 'processed' => 0, 'updated' => 0, 'failed' => 1, 'status' => 'exception', 'remaining' => true ),
				$disabled_stages,
				$round['stageErrors']
			);
			$round['queuePasses']['repriceQueue']++;
		}

		/* Recategorize queue. */
		if ( class_exists( 'Mobo_Core_Recategorize_Queue' ) && ! isset( $disabled_stages['recategorizeQueue'] ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			$round['recategorizeQueue'] = $this->execute_stage(
				'recategorizeQueue',
				function () {
					$queue = new Mobo_Core_Recategorize_Queue();
					return $queue->process_batch();
				},
				array( 'processed' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 1, 'status' => 'exception', 'remaining' => true ),
				$disabled_stages,
				$round['stageErrors']
			);
			$round['queuePasses']['recategorizeQueue']++;
		}

		/* Due configuration syncs only need one check per invocation. */
		if ( 1 === absint( $round_number ) ) {
			if ( class_exists( 'Mobo_Core_Address_Mapping' ) && ! isset( $disabled_stages['addressMapping'] ) ) {
				if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
					return $round;
				}

				$round['addressMapping'] = $this->execute_stage(
					'addressMapping',
					function () use ( $source ) {
						$mapping = new Mobo_Core_Address_Mapping();
						return $mapping->maybe_sync_if_due( $source, false );
					},
					array( 'success' => false, 'status' => 'exception' ),
					$disabled_stages,
					$round['stageErrors']
				);
			}

			if ( class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) && ! isset( $disabled_stages['remoteShipping'] ) ) {
				if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
					return $round;
				}

				$round['remoteShipping'] = $this->execute_stage(
					'remoteShipping',
					function () use ( $source ) {
						$shipping = new Mobo_Core_Remote_Shipping_Methods();
						return $shipping->maybe_sync_if_due( $source, false );
					},
					array( 'success' => false, 'status' => 'exception' ),
					$disabled_stages,
					$round['stageErrors']
				);
			}
		}

		/* Queued order submissions. */
		if ( class_exists( 'Mobo_Core_Checkout_Validator' ) && ! isset( $disabled_stages['orderSubmissions'] ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			$round['orderSubmissions'] = $this->execute_stage(
				'orderSubmissions',
				function () use ( $source ) {
					$validator = new Mobo_Core_Checkout_Validator();
					return $validator->process_queued_mobo_order_submissions( 1, $source );
				},
				array( 'status' => 'exception', 'processed' => 0, 'success' => 0, 'failed' => 1, 'skipped' => 0, 'remaining' => true ),
				$disabled_stages,
				$round['stageErrors']
			);
			$round['queuePasses']['orderSubmissions']++;
		}

		/* A late image pass consumes images enqueued by product sync in this round. */
		if ( $image_sync instanceof Mobo_Core_Image_Sync && ! isset( $disabled_stages['imageQueue'] ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			$late_image = $this->execute_stage(
				'imageQueue',
				function () use ( $image_sync, $image_limit ) {
					return $image_sync->process_queue( $image_limit );
				},
				array( 'processed' => 0, 'failed' => 1, 'status' => 'exception', 'remaining' => true ),
				$disabled_stages,
				$round['stageErrors']
			);

			$round['imageQueue'] = $this->merge_queue_counters(
				$round['imageQueue'],
				$late_image,
				array( 'processed', 'failed' ),
				array( 'remaining' )
			);
			$round['imageQueue']['latePass'] = $late_image;
			$round['queuePasses']['imageQueue']++;
		}

		/* Maintenance is opportunistic and only checked once per invocation. */
		if ( 1 === absint( $round_number ) && class_exists( 'Mobo_Core_Maintenance' ) && ! isset( $disabled_stages['maintenance'] ) ) {
			if ( $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				$round['maintenance'] = $this->execute_stage(
					'maintenance',
					function () use ( $source ) {
						return Mobo_Core_Maintenance::maybe_run( $source );
					},
					array( 'success' => false, 'status' => 'exception' ),
					$disabled_stages,
					$round['stageErrors']
				);
			}
		}

		$this->finalize_round_state( $round, $disabled_stages );
		return $round;
	}

	/**
	 * Initialize aggregate result.
	 *
	 * @param string $source Source.
	 * @return array
	 */
	private function empty_aggregate_result( $source ) {
		return array(
			'success'                => true,
			'status'                 => 'ok',
			'source'                 => sanitize_key( (string) $source ),
			'webhookQueue'           => array( 'processed' => 0, 'failed' => 0, 'status' => 'skipped', 'remainingFile' => false, 'remainingTable' => false, 'remainingDueTable' => false ),
			'imageQueue'             => array( 'processed' => 0, 'failed' => 0, 'status' => 'skipped', 'remaining' => false ),
			'imageRefreshQueue'      => array( 'processed' => 0, 'failed' => 0, 'skipped' => 0, 'status' => 'skipped', 'remaining' => false ),
			'imageRefreshAutomation' => array( 'success' => true, 'status' => 'disabled', 'needsContinuation' => false, 'progressed' => false ),
			'repriceQueue'           => array( 'processed' => 0, 'updated' => 0, 'failed' => 0, 'status' => 'skipped', 'remaining' => false ),
			'recategorizeQueue'      => array( 'processed' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'status' => 'skipped', 'remaining' => false ),
			'addressMapping'         => array( 'status' => 'skipped' ),
			'remoteShipping'         => array( 'status' => 'skipped' ),
			'orderSubmissions'       => array( 'status' => 'skipped', 'processed' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => false ),
			'maintenance'            => array( 'status' => 'skipped' ),
			'productSteps'           => 0,
			'productStatus'          => array(),
			'reconciliation'          => array( 'success' => true, 'status' => 'skipped', 'processedProducts' => 0, 'processedVariations' => 0, 'needsContinuation' => false ),
			'lastStep'               => null,
			'runnerErrors'           => array(),
			'queuePasses'            => array(
				'webhookQueue'      => 0,
				'imageQueue'        => 0,
				'imageRefreshQueue' => 0,
				'productSync'       => 0,
				'reconciliation'    => 0,
				'repriceQueue'      => 0,
				'recategorizeQueue' => 0,
				'orderSubmissions'  => 0,
			),
		);
	}

	/**
	 * Initialize one round result.
	 *
	 * @return array
	 */
	private function empty_round_result() {
		$result = $this->empty_aggregate_result( 'round' );
		$result['stageErrors']      = array();
		$result['madeProgress']     = false;
		$result['hasImmediateWork'] = false;
		$result['deadlineReached']  = false;
		$result['lockLost']         = false;
		unset( $result['source'], $result['runnerErrors'] );
		return $result;
	}

	/**
	 * Renew lease and verify time before a major stage.
	 *
	 * @param float  $deadline Deadline.
	 * @param array  $config Config.
	 * @param string $lock_token Token.
	 * @param int    $lock_renewals Renewal counter.
	 * @param array  $round Round result.
	 * @return bool
	 */
	private function prepare_stage( $deadline, $config, $lock_token, &$lock_renewals, &$round, $disabled_stages = array() ) {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			$round['upgradePaused'] = true;
			$this->finalize_round_state( $round, $disabled_stages );
			return false;
		}

		if ( ! $this->has_time_remaining( $deadline, $config['safetyMarginSeconds'] ) ) {
			$round['deadlineReached'] = true;
			$this->finalize_round_state( $round, $disabled_stages );
			return false;
		}

		if ( ! $this->renew_runner_lock( $lock_token, $config['lockTtlSeconds'], $lock_renewals ) ) {
			$round['lockLost'] = true;
			$this->finalize_round_state( $round, $disabled_stages );
			return false;
		}

		return true;
	}

	/**
	 * Execute one stage without allowing its exception to abort other queues.
	 *
	 * @param string   $stage Stage name.
	 * @param callable $callback Callback.
	 * @param array    $fallback Fallback result.
	 * @param array    $disabled_stages Disabled stages by reference.
	 * @param array    $errors Errors by reference.
	 * @return array
	 */
	private function execute_stage( $stage, $callback, $fallback, &$disabled_stages, &$errors ) {
		try {
			$result = call_user_func( $callback );
			return is_array( $result ) ? $result : $fallback;
		} catch ( Throwable $e ) {
			$disabled_stages[ (string) $stage ] = true;
			$errors[] = $this->compact_stage_error( $stage, $e );
			$fallback['exceptionClass'] = get_class( $e );
			$fallback['message']        = $e->getMessage();
			return $fallback;
		}
	}

	/**
	 * Finalize progress and immediate-work flags for a round.
	 *
	 * @param array $round Round by reference.
	 * @param array $disabled_stages Disabled stages.
	 * @return void
	 */
	private function finalize_round_state( &$round, $disabled_stages ) {
		$webhook_processed = absint( isset( $round['webhookQueue']['processed'] ) ? $round['webhookQueue']['processed'] : 0 );
		$image_processed   = absint( isset( $round['imageQueue']['processed'] ) ? $round['imageQueue']['processed'] : 0 );
		$refresh_processed = absint( isset( $round['imageRefreshQueue']['processed'] ) ? $round['imageRefreshQueue']['processed'] : 0 );
		$reprice_processed = absint( isset( $round['repriceQueue']['processed'] ) ? $round['repriceQueue']['processed'] : 0 );
		$recat_processed   = absint( isset( $round['recategorizeQueue']['processed'] ) ? $round['recategorizeQueue']['processed'] : 0 );
		$order_processed   = absint( isset( $round['orderSubmissions']['processed'] ) ? $round['orderSubmissions']['processed'] : 0 );
		$product_steps     = absint( isset( $round['productSteps'] ) ? $round['productSteps'] : 0 );
		$reconciliation_products = absint( isset( $round['reconciliation']['processedProducts'] ) ? $round['reconciliation']['processedProducts'] : 0 );
		$reconciliation_variations = absint( isset( $round['reconciliation']['processedVariations'] ) ? $round['reconciliation']['processedVariations'] : 0 );
		$automation_moved  = ! empty( $round['imageRefreshAutomation']['progressed'] );

		$round['madeProgress'] = ( $webhook_processed + $image_processed + $refresh_processed + $reprice_processed + $recat_processed + $order_processed + $product_steps + $reconciliation_products + $reconciliation_variations ) > 0 || $automation_moved;

		$webhook_due = false;
		if ( ! isset( $disabled_stages['webhookQueue'] ) && class_exists( 'Mobo_Core_Webhook_Queue' ) ) {
			try {
				$queue       = new Mobo_Core_Webhook_Queue();
				$webhook_due = $queue->has_due_work();
			} catch ( Throwable $e ) {
				$webhook_due = false;
			}
		}

		$product_continue = ! isset( $disabled_stages['productSync'] ) && ! empty( $round['productStatus']['shouldContinue'] );
		$automation_continue = ! isset( $disabled_stages['imageRefreshQueue'] )
			&& ! empty( $round['imageRefreshAutomation']['needsContinuation'] )
			&& ! empty( $round['imageRefreshAutomation']['progressed'] );

		$round['hasImmediateWork'] = $webhook_due
			|| ( ! isset( $disabled_stages['imageQueue'] ) && ! empty( $round['imageQueue']['remaining'] ) )
			|| ( ! isset( $disabled_stages['imageRefreshQueue'] ) && ! empty( $round['imageRefreshQueue']['remaining'] ) )
			|| $automation_continue
			|| $product_continue
			|| ( ! isset( $disabled_stages['reconciliation'] ) && ! empty( $round['reconciliation']['needsContinuation'] ) )
			|| ( ! isset( $disabled_stages['repriceQueue'] ) && ! empty( $round['repriceQueue']['remaining'] ) )
			|| ( ! isset( $disabled_stages['recategorizeQueue'] ) && ! empty( $round['recategorizeQueue']['remaining'] ) )
			|| ( ! isset( $disabled_stages['orderSubmissions'] ) && ! empty( $round['orderSubmissions']['remaining'] ) );
	}

	/**
	 * Merge a round into aggregate counters and latest statuses.
	 *
	 * @param array $aggregate Aggregate by reference.
	 * @param array $round Round.
	 * @return void
	 */
	private function merge_round_result( &$aggregate, $round ) {
		$aggregate['webhookQueue'] = $this->merge_queue_counters(
			$aggregate['webhookQueue'],
			$round['webhookQueue'],
			array( 'processed', 'failed' ),
			array( 'remainingFile', 'remainingTable', 'remainingDueTable' )
		);
		$aggregate['imageQueue'] = $this->merge_queue_counters(
			$aggregate['imageQueue'],
			$round['imageQueue'],
			array( 'processed', 'failed' ),
			array( 'remaining' )
		);
		$aggregate['imageRefreshQueue'] = $this->merge_queue_counters(
			$aggregate['imageRefreshQueue'],
			$round['imageRefreshQueue'],
			array( 'processed', 'failed', 'skipped' ),
			array( 'remaining' )
		);
		$aggregate['repriceQueue'] = $this->merge_queue_counters(
			$aggregate['repriceQueue'],
			$round['repriceQueue'],
			array( 'processed', 'updated', 'failed' ),
			array( 'remaining' )
		);
		$aggregate['recategorizeQueue'] = $this->merge_queue_counters(
			$aggregate['recategorizeQueue'],
			$round['recategorizeQueue'],
			array( 'processed', 'updated', 'skipped', 'failed' ),
			array( 'remaining' )
		);
		$aggregate['orderSubmissions'] = $this->merge_queue_counters(
			$aggregate['orderSubmissions'],
			$round['orderSubmissions'],
			array( 'processed', 'success', 'failed', 'skipped' ),
			array( 'remaining' )
		);

		$aggregate['imageRefreshAutomation'] = $round['imageRefreshAutomation'];
		if ( isset( $round['reconciliation'] ) && is_array( $round['reconciliation'] ) && 'skipped' !== ( isset( $round['reconciliation']['status'] ) ? $round['reconciliation']['status'] : '' ) ) {
			$aggregate['reconciliation'] = $round['reconciliation'];
		}
		$aggregate['productSteps'] += absint( isset( $round['productSteps'] ) ? $round['productSteps'] : 0 );
		$aggregate['productStatus'] = isset( $round['productStatus'] ) && is_array( $round['productStatus'] ) ? $round['productStatus'] : $aggregate['productStatus'];
		if ( null !== $round['lastStep'] ) {
			$aggregate['lastStep'] = $round['lastStep'];
		}

		foreach ( array( 'addressMapping', 'remoteShipping', 'maintenance' ) as $key ) {
			if ( isset( $round[ $key ] ) && is_array( $round[ $key ] ) && 'skipped' !== ( isset( $round[ $key ]['status'] ) ? $round[ $key ]['status'] : '' ) ) {
				$aggregate[ $key ] = $round[ $key ];
			}
		}

		if ( ! empty( $round['stageErrors'] ) && is_array( $round['stageErrors'] ) ) {
			$aggregate['runnerErrors'] = array_slice( array_merge( $aggregate['runnerErrors'], $round['stageErrors'] ), -20 );
		}

		foreach ( $aggregate['queuePasses'] as $key => $count ) {
			$aggregate['queuePasses'][ $key ] = absint( $count ) + absint( isset( $round['queuePasses'][ $key ] ) ? $round['queuePasses'][ $key ] : 0 );
		}
	}

	/**
	 * Merge counters while preserving latest status/remaining flags.
	 *
	 * @param array $current Current aggregate.
	 * @param array $next Next result.
	 * @param array $sum_keys Numeric keys to sum.
	 * @param array $latest_bool_keys Boolean keys to take from latest result.
	 * @return array
	 */
	private function merge_queue_counters( $current, $next, $sum_keys, $latest_bool_keys ) {
		$current = is_array( $current ) ? $current : array();
		$next    = is_array( $next ) ? $next : array();
		$merged  = array_merge( $current, $next );

		foreach ( $sum_keys as $key ) {
			$merged[ $key ] = absint( isset( $current[ $key ] ) ? $current[ $key ] : 0 ) + absint( isset( $next[ $key ] ) ? $next[ $key ] : 0 );
		}

		$next_status = isset( $next['status'] ) ? sanitize_key( (string) $next['status'] ) : '';
		foreach ( $latest_bool_keys as $key ) {
			if ( 'skipped' === $next_status && array_key_exists( $key, $current ) ) {
				$merged[ $key ] = ! empty( $current[ $key ] );
			} else {
				$merged[ $key ] = ! empty( $next[ $key ] );
			}
		}

		return $merged;
	}

	/**
	 * Renew the current runner lease.
	 *
	 * @param string $token Token.
	 * @param int    $ttl TTL.
	 * @param int    $renewals Renewal counter.
	 * @return bool
	 */
	private function renew_runner_lock( $token, $ttl, &$renewals ) {
		$renewed = Mobo_Core_Lock::renew( 'real_cron_runner', $token, $ttl );
		if ( $renewed ) {
			$renewals++;
		}
		return $renewed;
	}

	/**
	 * Whether enough cooperative time remains to start another stage.
	 *
	 * @param float $deadline Deadline.
	 * @param int   $margin Safety margin.
	 * @return bool
	 */
	private function has_time_remaining( $deadline, $margin = 0 ) {
		return microtime( true ) < ( (float) $deadline - max( 0, (int) $margin ) );
	}

	/**
	 * Compact stage exception for diagnostics and health reports.
	 *
	 * @param string    $stage Stage.
	 * @param Throwable $e Exception.
	 * @return array
	 */
	private function compact_stage_error( $stage, Throwable $e ) {
		return array(
			'stage'          => sanitize_key( (string) $stage ),
			'message'        => sanitize_text_field( $e->getMessage() ),
			'exceptionClass' => get_class( $e ),
			'file'           => $e->getFile(),
			'line'           => $e->getLine(),
			'at'             => time(),
		);
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
	 * @param Throwable $e Exception.
	 * @param array     $extra Extra fields.
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
