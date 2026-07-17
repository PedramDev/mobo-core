<?php
/**
 * Mobo Core cPanel CLI queue worker.
 *
 * cPanel should start this file once per minute. The same PHP process stays
 * alive for a bounded period, processes all existing queues in fair rounds,
 * and rechecks idle queues at the configured interval.
 *
 * This file is CLI-only by design. It does not accept HTTP requests and it
 * never chains execution through exec(), shell_exec(), or loopback HTTP calls.
 *
 * @package MoboCore
 */

if ( 'cli' !== PHP_SAPI ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/plain; charset=utf-8', true, 403 );
	}
	echo "Mobo queue worker is CLI-only.\n";
	exit( 1 );
}

if ( ! defined( 'DOING_CRON' ) ) {
	define( 'DOING_CRON', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core cron context constant.
}

if ( ! defined( 'MOBO_CORE_LOCAL_PHP_CRON' ) ) {
	define( 'MOBO_CORE_LOCAL_PHP_CRON', true );
}

if ( ! defined( 'MOBO_QUEUE_WORKER_PROCESS' ) ) {
	define( 'MOBO_QUEUE_WORKER_PROCESS', true );
}

/**
 * Locate and load WordPress.
 *
 * @return void
 */
function mobo_core_queue_worker_bootstrap_wordpress() {
	if ( defined( 'ABSPATH' ) ) {
		return;
	}

	$directory = __DIR__;
	$wp_load   = '';

	for ( $level = 0; $level < 10; $level++ ) {
		$candidate = $directory . '/wp-load.php';
		if ( is_file( $candidate ) ) {
			$wp_load = $candidate;
			break;
		}

		$parent = dirname( $directory );
		if ( $parent === $directory ) {
			break;
		}
		$directory = $parent;
	}

	if ( '' === $wp_load ) {
		fwrite( STDERR, "[mobo-queue-worker] WordPress bootstrap file was not found.\n" );
		exit( 1 );
	}

	require_once $wp_load;
}

/**
 * Write one structured CLI log line.
 *
 * @param string $level   Log level.
 * @param string $message Message.
 * @param array  $context Context.
 * @param bool   $stderr  Write to STDERR.
 * @return void
 */
function mobo_core_queue_worker_log( $level, $message, $context = array(), $stderr = false ) {
	$payload = array(
		'timestamp' => gmdate( 'c' ),
		'level'     => strtoupper( (string) $level ),
		'message'   => (string) $message,
	);

	if ( is_array( $context ) && ! empty( $context ) ) {
		$payload['context'] = $context;
	}

	$encoded = function_exists( 'wp_json_encode' )
		? wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		: json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	fwrite( $stderr ? STDERR : STDOUT, (string) $encoded . PHP_EOL );
}

/**
 * Compact one round for logs and final aggregate output.
 *
 * @param array $result Runner result.
 * @return array
 */
function mobo_core_queue_worker_round_summary( $result ) {
	$result = is_array( $result ) ? $result : array();

	$get_count = static function ( $section, $key ) use ( $result ) {
		return isset( $result[ $section ] ) && is_array( $result[ $section ] ) && isset( $result[ $section ][ $key ] )
			? absint( $result[ $section ][ $key ] )
			: 0;
	};

	return array(
		'success'               => ! empty( $result['success'] ),
		'status'                => isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : 'unknown',
		'didWork'               => ! empty( $result['didWork'] ),
		'needsContinuation'     => ! empty( $result['needsContinuation'] ),
		'deadlineReached'       => ! empty( $result['deadlineReached'] ),
		'webhooksProcessed'     => $get_count( 'webhookQueue', 'processed' ),
		'imagesProcessed'       => $get_count( 'imageQueue', 'processed' ),
		'imageRefreshProcessed' => $get_count( 'imageRefreshQueue', 'processed' ),
		'repriceProcessed'      => $get_count( 'repriceQueue', 'processed' ),
		'recategorizeProcessed' => $get_count( 'recategorizeQueue', 'processed' ),
		'ordersProcessed'       => $get_count( 'orderSubmissions', 'processed' ),
		'productSteps'          => isset( $result['productSteps'] ) ? absint( $result['productSteps'] ) : 0,
		'queueOrder'            => isset( $result['queueOrder'] ) && is_array( $result['queueOrder'] ) ? $result['queueOrder'] : array(),
		'batchDurationsMs'      => isset( $result['batchDurationsMs'] ) && is_array( $result['batchDurationsMs'] ) ? $result['batchDurationsMs'] : array(),
	);
}

mobo_core_queue_worker_bootstrap_wordpress();

if ( ! class_exists( 'Mobo_Core_Queue_Worker_Lock' ) || ! class_exists( 'Mobo_Core_Cron_Runner' ) ) {
	mobo_core_queue_worker_log( 'error', 'Mobo Core is inactive or required worker classes are unavailable.', array(), true );
	exit( 1 );
}

if ( ! Mobo_Core_Queue_Worker_Lock::is_cli_worker_enabled() ) {
	mobo_core_queue_worker_log(
		'info',
		'Queue worker is disabled. Define MOBO_QUEUE_WORKER_ENABLED as true in wp-config.php to enable it.'
	);
	exit( 0 );
}

if ( function_exists( 'set_time_limit' ) ) {
	@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The worker uses its own microtime deadline and does not rely on this call.
}

$process_lock = Mobo_Core_Queue_Worker_Lock::acquire();
if ( is_wp_error( $process_lock ) ) {
	$is_expected_overlap = 'mobo_queue_worker_locked' === $process_lock->get_error_code();
	mobo_core_queue_worker_log(
		$is_expected_overlap ? 'info' : 'error',
		$process_lock->get_error_message(),
		array(
			'code' => $process_lock->get_error_code(),
			'data' => $process_lock->get_error_data(),
		),
		! $is_expected_overlap
	);
	exit( $is_expected_overlap ? 0 : 1 );
}

$lock_released = false;
$release_lock  = static function () use ( &$process_lock, &$lock_released ) {
	if ( $lock_released ) {
		return;
	}

	Mobo_Core_Queue_Worker_Lock::release( $process_lock );
	$lock_released = true;
};

register_shutdown_function( $release_lock );

$started_at  = microtime( true );
$max_runtime = Mobo_Core_Queue_Worker_Lock::max_runtime();
$idle_sleep  = Mobo_Core_Queue_Worker_Lock::idle_sleep();
$deadline    = $started_at + $max_runtime;
$round       = 0;
$last_round_duration = 0.0;
$batch_estimates_ms = array();
$last_result = array();
$aggregate   = array(
	'rounds'                  => 0,
	'workRounds'              => 0,
	'idleChecks'              => 0,
	'webhooksProcessed'       => 0,
	'imagesProcessed'         => 0,
	'imageRefreshProcessed'   => 0,
	'repriceProcessed'        => 0,
	'recategorizeProcessed'   => 0,
	'ordersProcessed'         => 0,
	'productSteps'            => 0,
);

update_option( 'mobo_core_queue_worker_last_start_at', time(), false );
update_option( 'mobo_core_queue_worker_last_pid', function_exists( 'getmypid' ) ? getmypid() : 0, false );

mobo_core_queue_worker_log(
	'info',
	'Queue worker started.',
	array(
		'pid'                => function_exists( 'getmypid' ) ? getmypid() : 0,
		'maxRuntimeSeconds'  => $max_runtime,
		'idleSleepSeconds'   => $idle_sleep,
		'lockPath'           => isset( $process_lock['path'] ) ? $process_lock['path'] : '',
	)
);

try {
	$runner = new Mobo_Core_Cron_Runner();

	while ( true ) {
		$now       = microtime( true );
		$remaining = $deadline - $now;
		$largest_batch_estimate = empty( $batch_estimates_ms ) ? 0 : max( array_map( 'absint', $batch_estimates_ms ) );
		$estimated_guard = max(
			3.0,
			min( 15.0, $last_round_duration > 0 ? $last_round_duration * 1.25 : 3.0 ),
			min( 15.0, $largest_batch_estimate > 0 ? ( $largest_batch_estimate / 1000 ) * 1.25 + 0.5 : 3.0 )
		);

		if ( $remaining <= $estimated_guard ) {
			break;
		}

		$round_started = microtime( true );
		$result = $runner->run(
			'cpanel-cli-worker',
			array(
				'queueWorkerMode'           => true,
				'processLockHeld'           => true,
				'deadline'                  => $deadline,
				'queueOffset'               => $round,
				'batchEstimatesMs'          => $batch_estimates_ms,
				'refreshRemoteConfiguration'=> 0 === $round,
				'includeHousekeeping'       => 0 === $round,
				'sendHealthReport'          => 0 === $round,
			)
		);
		$last_round_duration = microtime( true ) - $round_started;
		$last_result         = is_array( $result ) ? $result : array();
		if ( isset( $last_result['batchDurationsMs'] ) && is_array( $last_result['batchDurationsMs'] ) ) {
			foreach ( $last_result['batchDurationsMs'] as $queue_name => $duration_ms ) {
				$duration_ms = max( 0, absint( $duration_ms ) );
				if ( $duration_ms > 0 ) {
					$batch_estimates_ms[ sanitize_key( (string) $queue_name ) ] = $duration_ms;
				}
			}
		}
		$summary             = mobo_core_queue_worker_round_summary( $last_result );

		$aggregate['rounds']++;
		if ( ! empty( $summary['didWork'] ) ) {
			$aggregate['workRounds']++;
		}
		$aggregate['webhooksProcessed']     += $summary['webhooksProcessed'];
		$aggregate['imagesProcessed']       += $summary['imagesProcessed'];
		$aggregate['imageRefreshProcessed'] += $summary['imageRefreshProcessed'];
		$aggregate['repriceProcessed']      += $summary['repriceProcessed'];
		$aggregate['recategorizeProcessed'] += $summary['recategorizeProcessed'];
		$aggregate['ordersProcessed']       += $summary['ordersProcessed'];
		$aggregate['productSteps']          += $summary['productSteps'];

		mobo_core_queue_worker_log(
			empty( $last_result['success'] ) ? 'error' : 'info',
			'Queue round completed.',
			array_merge(
				array(
					'round'      => $round + 1,
					'durationMs' => (int) round( $last_round_duration * 1000 ),
					'remainingRuntimeSeconds' => round( max( 0, $deadline - microtime( true ) ), 3 ),
				),
				$summary
			),
			empty( $last_result['success'] )
		);

		$round++;

		if ( ! empty( $last_result['deadlineReached'] ) ) {
			break;
		}

		if ( ! empty( $last_result['didWork'] ) ) {
			continue;
		}

		$aggregate['idleChecks']++;
		$remaining_after_round = $deadline - microtime( true );
		$sleep_seconds         = min( (float) $idle_sleep, max( 0.0, $remaining_after_round - $estimated_guard ) );

		if ( $sleep_seconds <= 0 ) {
			break;
		}

		mobo_core_queue_worker_log(
			'info',
			'No ready queue work found; sleeping before the next check.',
			array( 'sleepSeconds' => round( $sleep_seconds, 3 ) )
		);

		usleep( (int) round( $sleep_seconds * 1000000 ) );
	}
} catch ( Throwable $exception ) {
	mobo_core_queue_worker_log(
		'error',
		'Unhandled queue worker exception.',
		array(
			'exceptionClass' => get_class( $exception ),
			'message'        => $exception->getMessage(),
			'file'           => $exception->getFile(),
			'line'           => $exception->getLine(),
		),
		true
	);
	update_option( 'mobo_core_queue_worker_last_error', sanitize_text_field( $exception->getMessage() ), false );
	$release_lock();
	exit( 1 );
} finally {
	$release_lock();
}

$finished_at = microtime( true );
$final = array(
	'success'        => true,
	'status'         => 'completed',
	'startedAt'      => gmdate( 'c', (int) $started_at ),
	'finishedAt'     => gmdate( 'c', (int) $finished_at ),
	'durationSeconds'=> round( $finished_at - $started_at, 3 ),
	'aggregate'      => $aggregate,
	'lastRound'      => mobo_core_queue_worker_round_summary( $last_result ),
);

update_option( 'mobo_core_queue_worker_last_end_at', time(), false );
update_option( 'mobo_core_queue_worker_last_result', $final, false );

if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
	try {
		Mobo_Core_Self_Runner::record_run_result( $final );
	} catch ( Throwable $exception ) {
		mobo_core_queue_worker_log( 'warning', 'Unable to record worker result.', array( 'message' => $exception->getMessage() ) );
	}
}

mobo_core_queue_worker_log( 'info', 'Queue worker finished.', $final );
exit( 0 );
