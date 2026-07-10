<?php
/**
 * Customer-side WordPress health reporter.
 *
 * Builds a compact site-health snapshot and optionally posts it to MoboCore:
 * POST /api/site-health/report
 * X-SEC: <mobo_core_security_code>
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Health_Reporter {

	/**
	 * Build a health report compatible with MoboCore WordPressSiteHealthReportDto.
	 *
	 * @return array
	 */
	public function build_report() {
		$sync_status = $this->get_sync_status();
		$queue_stats = $this->get_webhook_queue_stats();
		$image_stats = $this->get_image_queue_stats();
		$disk         = $this->get_disk_stats();
		$cron_status  = Mobo_Core_Cron_Runner::get_status();
		$self_status  = class_exists( 'Mobo_Core_Self_Runner' ) ? Mobo_Core_Self_Runner::get_status() : array();
		$logs         = $this->get_log_stats();
		$cache        = $this->get_cache_stats();
		$wp_memory    = $this->get_wordpress_memory_stats();
		$environment  = $this->get_environment_stats();

		$last_self_run     = isset( $self_status['lastRunAt'] ) ? absint( $self_status['lastRunAt'] ) : 0;
		$last_cron_hit     = isset( $cron_status['lastHitAt'] ) ? absint( $cron_status['lastHitAt'] ) : 0;
		$last_sync_success = $this->get_last_sync_success_timestamp( $sync_status );
		$last_error        = $this->resolve_last_error( $sync_status, $cron_status );

		return array(
			'siteUrl'               => home_url( '/' ),
			'licenseToken'          => (string) get_option( 'mobo_core_token', '' ),
			'pluginVersion'         => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
			'wordpressVersion'      => get_bloginfo( 'version' ),
			'phpVersion'            => PHP_VERSION,
			'wooCommerceVersion'    => $this->get_woocommerce_version(),
			'webServerSoftware'     => $environment['web_server'],
			'serverName'            => $environment['server_name'],
			'databaseVersion'       => $environment['database_version'],
			'activeTheme'           => $environment['active_theme'],
			'activePluginsCount'    => $environment['active_plugins_count'],
			'opCacheEnabled'        => $environment['opcache_enabled'],

			'wpDebug'               => defined( 'WP_DEBUG' ) ? (bool) WP_DEBUG : false,
			'wpDebugLog'            => defined( 'WP_DEBUG_LOG' ) ? (bool) WP_DEBUG_LOG : false,
			'wpDebugDisplay'        => defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : false,
			'debugLogPath'          => $logs['debug_log_path'],
			'debugLogSizeBytes'     => $logs['debug_log_size_bytes'],
			'phpErrorLogPath'       => $logs['php_error_log_path'],
			'phpErrorLogSizeBytes'  => $logs['php_error_log_size_bytes'],
			'objectCacheEnabled'    => $cache['object_cache_enabled'],
			'advancedCacheEnabled'  => $cache['advanced_cache_enabled'],
			'pageCacheDetected'     => $cache['page_cache_detected'],
			'cacheSystem'           => $cache['cache_system'],
			'cachePlugins'          => $cache['cache_plugins'],

			'phpMemoryLimitRaw'     => (string) ini_get( 'memory_limit' ),
			'phpMemoryLimitBytes'   => $this->parse_size_to_bytes( (string) ini_get( 'memory_limit' ) ),
			'phpMemoryUsageBytes'   => memory_get_usage( true ),
			'phpMemoryPeakUsageBytes'=> memory_get_peak_usage( true ),
			'phpMaxExecutionTime'   => absint( ini_get( 'max_execution_time' ) ),
			'phpUploadMaxFilesize'  => (string) ini_get( 'upload_max_filesize' ),
			'phpPostMaxSize'        => (string) ini_get( 'post_max_size' ),
			'wpMemoryLimitRaw'      => $wp_memory['wp_memory_limit_raw'],
			'wpMemoryLimitBytes'    => $wp_memory['wp_memory_limit_bytes'],
			'wpMaxMemoryLimitRaw'   => $wp_memory['wp_max_memory_limit_raw'],
			'wpMaxMemoryLimitBytes' => $wp_memory['wp_max_memory_limit_bytes'],

			'diskFreeBytes'         => $disk['free'],
			'diskTotalBytes'        => $disk['total'],
			'diskFreePercent'       => $disk['percent'],

			'cronMode'              => $last_self_run > 0 ? 'self_runner' : ( $last_cron_hit > 0 ? 'real_cron' : 'not_detected' ),
			'lastCronHitAt'         => $this->format_timestamp( $last_self_run > 0 ? $last_self_run : $last_cron_hit ),
			'lastSyncSuccessAt'     => $this->format_timestamp( $last_sync_success ),
			'lastWebhookSuccessAt'  => $this->format_timestamp( $this->get_last_webhook_success_timestamp( $cron_status ) ),

			'pendingWebhookJobs'    => $queue_stats['pending'],
			'failedWebhookJobs'     => $queue_stats['failed'],
			'pendingImageJobs'      => $image_stats['pending'],
			'failedImageJobs'       => $image_stats['failed'],
			'pendingSyncJobs'       => $this->get_pending_sync_jobs( $sync_status ),
			'actionSchedulerPastDue'=> $this->get_action_scheduler_past_due_count(),
			'actionSchedulerFailed' => $this->get_action_scheduler_failed_count(),

			'lastError'             => $last_error,
		);
	}

	/**
	 * Send report to MoboCore.
	 *
	 * @param string $source Source label.
	 * @param bool   $force Ignore minimum interval.
	 * @return array
	 */
	public function send_report( $source = 'real-cron', $force = false ) {
		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_health_report_enabled', '0' ) ) {
			return $this->save_result(
				array(
					'success' => true,
					'status'  => 'disabled',
					'message' => 'Health reporting is disabled.',
				)
			);
		}

		$min_interval = Mobo_Core_Settings::get_int( 'mobo_core_health_report_min_interval_seconds', 300, 60, 3600 );
		$last_success = absint( get_option( 'mobo_core_health_last_report_success_at', 0 ) );

		if ( ! $force && $last_success > 0 && ( time() - $last_success ) < $min_interval ) {
			return array(
				'success' => true,
				'status'  => 'throttled',
				'message' => 'Health report was skipped because minimum interval has not passed.',
			);
		}

		$url = $this->get_report_url();

		if ( '' === $url ) {
			return $this->save_result(
				array(
					'success' => false,
					'status'  => 'missing-url',
					'message' => 'Health report URL is missing.',
				)
			);
		}

		$security_code = trim( (string) get_option( 'mobo_core_security_code', '' ) );

		if ( '' === $security_code ) {
			return $this->save_result(
				array(
					'success' => false,
					'status'  => 'missing-security-code',
					'message' => 'Security code is missing.',
				)
			);
		}

		$payload = $this->build_report();
		$payload['reportSource'] = sanitize_key( (string) $source );

		update_option( 'mobo_core_health_last_report_attempt_at', time(), false );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => Mobo_Core_Settings::get_int( 'mobo_core_health_report_timeout_seconds', 15, 5, 60 ),
				'redirection' => 2,
				'sslverify'   => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'health_reporter' ),
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json; charset=utf-8',
					'X-SEC'        => $security_code,
				),
				'body'        => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->save_result(
				array(
					'success' => false,
					'status'  => 'request-failed',
					'message' => $response->get_error_message(),
				)
			);
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );
		$body = (string) wp_remote_retrieve_body( $response );

		$result = array(
			'success'    => $code >= 200 && $code < 300,
			'status'     => $code >= 200 && $code < 300 ? 'sent' : 'http-error',
			'httpStatus' => $code,
			'message'    => $code >= 200 && $code < 300 ? 'Health report sent.' : 'MoboCore returned HTTP ' . $code,
			'body'       => $this->trim_string( $body, 1000 ),
		);

		return $this->save_result( $result );
	}

	/**
	 * Return current local health status for REST /health.
	 *
	 * @return array
	 */
	public function get_local_status() {
		return array(
			'success'       => true,
			'status'        => 'ok',
			'pluginVersion' => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
			'siteTime'      => gmdate( 'c' ),
			'data'          => $this->build_report(),
			'lastReport'    => $this->get_last_report_status(),
		);
	}

	/**
	 * Get last report status for UI.
	 *
	 * @return array
	 */
	public function get_last_report_status() {
		$last_result = get_option( 'mobo_core_health_last_report_result', array() );

		if ( ! is_array( $last_result ) ) {
			$last_result = array();
		}

		return array(
			'enabled'       => Mobo_Core_Settings::enabled( 'mobo_core_health_report_enabled', '0' ),
			'reportUrl'     => $this->get_report_url(),
			'lastAttemptAt' => absint( get_option( 'mobo_core_health_last_report_attempt_at', 0 ) ),
			'lastSuccessAt' => absint( get_option( 'mobo_core_health_last_report_success_at', 0 ) ),
			'lastResult'    => $last_result,
		);
	}

	/**
	 * Save result and timestamps.
	 *
	 * @param array $result Result.
	 * @return array
	 */
	private function save_result( $result ) {
		$result['updatedAt'] = time();
		update_option( 'mobo_core_health_last_report_result', $result, false );

		if ( ! empty( $result['success'] ) && 'sent' === ( isset( $result['status'] ) ? $result['status'] : '' ) ) {
			update_option( 'mobo_core_health_last_report_success_at', time(), false );
		}

		return $result;
	}

	/**
	 * Resolve MoboCore health report URL.
	 *
	 * @return string
	 */
	private function get_report_url() {
		$explicit = (string) Mobo_Core_Settings::get( 'mobo_core_health_report_url', '' );

		if ( '' !== trim( $explicit ) ) {
			return esc_url_raw( trim( $explicit ) );
		}

		$base_url = apply_filters( 'mobo_core_api_base_url', '' );

		if ( '' === trim( (string) $base_url ) ) {
			$base_url = (string) Mobo_Core_Settings::get( 'mobo_core_api_base_url', '' );
		}

		if ( '' === trim( (string) $base_url ) ) {
			return '';
		}

		return trailingslashit( esc_url_raw( $base_url ) ) . 'api/site-health/report';
	}

	/**
	 * @return array
	 */
	private function get_sync_status() {
		$sync = new Mobo_Core_Product_Sync();
		return $sync->get_manual_sync_status();
	}

	/**
	 * @param array $sync_status Sync status.
	 * @return int
	 */
	private function get_last_sync_success_timestamp( $sync_status ) {
		if ( ! is_array( $sync_status ) ) {
			return 0;
		}

		if ( ! empty( $sync_status['isDone'] ) && ! empty( $sync_status['completedAt'] ) ) {
			return absint( $sync_status['completedAt'] );
		}

		return absint( get_option( 'mobo_core_last_sync_success_at', 0 ) );
	}

	/**
	 * @param array $cron_status Cron status.
	 * @return int
	 */
	private function get_last_webhook_success_timestamp( $cron_status ) {
		if ( ! is_array( $cron_status ) || empty( $cron_status['lastResult'] ) || ! is_array( $cron_status['lastResult'] ) ) {
			return 0;
		}

		$result = $cron_status['lastResult'];

		if ( empty( $result['success'] ) || empty( $result['webhookQueue'] ) || ! is_array( $result['webhookQueue'] ) ) {
			return 0;
		}

		$status = isset( $result['webhookQueue']['status'] ) ? sanitize_key( (string) $result['webhookQueue']['status'] ) : '';

		if ( in_array( $status, array( 'processed', 'empty' ), true ) ) {
			return isset( $result['executedAt'] ) ? absint( $result['executedAt'] ) : 0;
		}

		return 0;
	}

	/**
	 * @return array
	 */

	/**
	 * Get image queue stats.
	 *
	 * @return array
	 */
	private function get_image_queue_stats() {
		if ( ! class_exists( 'Mobo_Core_Image_Queue' ) || ! Mobo_Core_Image_Queue::table_exists() ) {
			return array( 'pending' => 0, 'failed' => 0, 'due' => 0 );
		}

		$queue = new Mobo_Core_Image_Queue();

		return array(
			'pending' => $queue->count_pending(),
			'failed'  => $queue->count_failed(),
			'due'     => $queue->count_due(),
		);
	}

	private function get_webhook_queue_stats() {
		$pending = 0;
		$failed  = 0;

		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) && Mobo_Core_Sync_Event_Store::table_exists() ) {
			$event_store = new Mobo_Core_Sync_Event_Store();
			$pending += $event_store->count_pending();
			$failed  += $event_store->count_failed();
		}

		$pending_files = glob( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . '*.json' );
		if ( is_array( $pending_files ) ) {
			$pending += count( $pending_files );
		}

		$failed_files = glob( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/*.json' );
		if ( is_array( $failed_files ) ) {
			$failed += count( $failed_files );
		}

		return array(
			'pending' => $pending,
			'failed'  => $failed,
		);
	}

	/**
	 * @param array $sync_status Sync status.
	 * @return int
	 */
	private function get_pending_sync_jobs( $sync_status ) {
		if ( ! is_array( $sync_status ) || empty( $sync_status['isRunning'] ) ) {
			return 0;
		}

		$remaining = isset( $sync_status['remainingProducts'] ) ? absint( $sync_status['remainingProducts'] ) : 0;
		$queued    = isset( $sync_status['queuedProducts'] ) ? absint( $sync_status['queuedProducts'] ) : 0;

		return max( 1, $remaining + $queued );
	}

	/**
	 * @return array
	 */
	private function get_log_stats() {
		$debug_path = $this->resolve_debug_log_path();
		$error_path = (string) ini_get( 'error_log' );

		return array(
			'debug_log_path'          => $debug_path,
			'debug_log_size_bytes'    => $this->safe_file_size( $debug_path ),
			'php_error_log_path'      => $error_path,
			'php_error_log_size_bytes'=> $this->safe_file_size( $error_path ),
		);
	}

	/**
	 * @return string
	 */
	private function resolve_debug_log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== trim( WP_DEBUG_LOG ) ) {
			return (string) WP_DEBUG_LOG;
		}

		return trailingslashit( WP_CONTENT_DIR ) . 'debug.log';
	}

	/**
	 * @param string $path File path.
	 * @return int|null
	 */
	private function safe_file_size( $path ) {
		$path = (string) $path;

		if ( '' === trim( $path ) || ! file_exists( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$size = @filesize( $path );
		return false === $size ? null : (int) $size;
	}

	/**
	 * @return array
	 */
	private function get_wordpress_memory_stats() {
		$wp_memory     = defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '';
		$wp_max_memory = defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : '';

		return array(
			'wp_memory_limit_raw'       => $wp_memory,
			'wp_memory_limit_bytes'     => $this->parse_size_to_bytes( $wp_memory ),
			'wp_max_memory_limit_raw'   => $wp_max_memory,
			'wp_max_memory_limit_bytes' => $this->parse_size_to_bytes( $wp_max_memory ),
		);
	}

	/**
	 * @return array
	 */
	private function get_environment_stats() {
		global $wpdb;

		$theme = wp_get_theme();
		$active_plugins = (array) get_option( 'active_plugins', array() );

		return array(
			'web_server'           => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'server_name'          => isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '',
			'database_version'     => is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '',
			'active_theme'         => $theme && $theme->exists() ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) : '',
			'active_plugins_count' => count( $active_plugins ),
			'opcache_enabled'      => function_exists( 'opcache_get_status' ) ? (bool) @opcache_get_status( false ) : null,
		);
	}

	/**
	 * @return array
	 */
	private function get_cache_stats() {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$cache_plugins  = array();

		$known = array(
			'litespeed-cache/litespeed-cache.php'       => 'LiteSpeed Cache',
			'wp-rocket/wp-rocket.php'                   => 'WP Rocket',
			'w3-total-cache/w3-total-cache.php'         => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'               => 'WP Super Cache',
			'wp-fastest-cache/wpFastestCache.php'       => 'WP Fastest Cache',
			'autoptimize/autoptimize.php'               => 'Autoptimize',
			'sg-cachepress/sg-cachepress.php'           => 'SiteGround Optimizer',
			'cache-enabler/cache-enabler.php'           => 'Cache Enabler',
			'breeze/breeze.php'                         => 'Breeze',
			'redis-cache/redis-cache.php'               => 'Redis Object Cache',
		);

		foreach ( $known as $plugin_file => $label ) {
			if ( in_array( $plugin_file, $active_plugins, true ) ) {
				$cache_plugins[] = $label;
			}
		}

		$advanced_cache = file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
		$object_cache   = wp_using_ext_object_cache();

		$cache_system = 'none';
		if ( ! empty( $cache_plugins ) ) {
			$cache_system = implode( ', ', $cache_plugins );
		} elseif ( $advanced_cache ) {
			$cache_system = 'advanced-cache.php';
		} elseif ( $object_cache ) {
			$cache_system = 'external-object-cache';
		}

		return array(
			'object_cache_enabled'   => $object_cache,
			'advanced_cache_enabled' => $advanced_cache,
			'page_cache_detected'    => $advanced_cache || ! empty( $cache_plugins ),
			'cache_system'           => $cache_system,
			'cache_plugins'          => $cache_plugins,
		);
	}

	/**
	 * @param string $value Size string.
	 * @return int|null
	 */
	private function parse_size_to_bytes( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		$unit = strtolower( substr( $value, -1 ) );
		$number = (float) $value;

		switch ( $unit ) {
			case 'g':
				$number *= 1024;
				// no break
			case 'm':
				$number *= 1024;
				// no break
			case 'k':
				$number *= 1024;
				break;
		}

		return $number > 0 ? (int) round( $number ) : null;
	}

	/**
	 * @return array
	 */
	private function get_disk_stats() {
		$free  = $this->safe_disk_free_space( ABSPATH );
		$total = $this->safe_disk_total_space( ABSPATH );

		$percent = null;
		if ( null !== $free && null !== $total && $total > 0 ) {
			$percent = round( ( $free / $total ) * 100, 2 );
		}

		return array(
			'free'    => $free,
			'total'   => $total,
			'percent' => $percent,
		);
	}

	/**
	 * @param string $path Path.
	 * @return int|null
	 */
	private function safe_disk_free_space( $path ) {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}

		$value = @disk_free_space( $path );

		return false === $value ? null : (int) $value;
	}

	/**
	 * @param string $path Path.
	 * @return int|null
	 */
	private function safe_disk_total_space( $path ) {
		if ( ! function_exists( 'disk_total_space' ) ) {
			return null;
		}

		$value = @disk_total_space( $path );

		return false === $value ? null : (int) $value;
	}

	/**
	 * @return int|null
	 */
	private function get_action_scheduler_past_due_count() {
		return $this->get_action_scheduler_count( 'pending', true );
	}

	/**
	 * @return int|null
	 */
	private function get_action_scheduler_failed_count() {
		return $this->get_action_scheduler_count( 'failed', false );
	}

	/**
	 * Count Action Scheduler actions if available. Returns null when unavailable.
	 *
	 * @param string $status Action status.
	 * @param bool   $past_due Whether to only count scheduled actions in the past.
	 * @return int|null
	 */
	private function get_action_scheduler_count( $status, $past_due ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return null;
		}

		$args = array(
			'status'   => $status,
			'per_page' => 50,
		);

		if ( $past_due ) {
			$args['date']         = gmdate( 'Y-m-d H:i:s' );
			$args['date_compare'] = '<=';
		}

		try {
			$actions = as_get_scheduled_actions( $args, 'ids' );
		} catch ( Exception $exception ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Mobo_Core_Logger::error( 'Mobo Core health Action Scheduler count failed: ' . $exception->getMessage() );
			}

			return null;
		}

		if ( ! is_array( $actions ) ) {
			return null;
		}

		return count( $actions );
	}

	/**
	 * @return string
	 */
	private function get_woocommerce_version() {
		if ( defined( 'WC_VERSION' ) ) {
			return (string) WC_VERSION;
		}

		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->version ) ) {
			return (string) WC()->version;
		}

		return '';
	}

	/**
	 * @param array $sync_status Sync status.
	 * @param array $cron_status Cron status.
	 * @return string|null
	 */
	private function resolve_last_error( $sync_status, $cron_status ) {
		if ( is_array( $sync_status ) && ! empty( $sync_status['lastError'] ) ) {
			return sanitize_text_field( (string) $sync_status['lastError'] );
		}

		if ( is_array( $cron_status ) && ! empty( $cron_status['lastResult'] ) && is_array( $cron_status['lastResult'] ) ) {
			$result = $cron_status['lastResult'];
			if ( empty( $result['success'] ) && ! empty( $result['message'] ) ) {
				return sanitize_text_field( (string) $result['message'] );
			}
		}

		$health_result = get_option( 'mobo_core_health_last_report_result', array() );
		if ( is_array( $health_result ) && empty( $health_result['success'] ) && ! empty( $health_result['message'] ) ) {
			return sanitize_text_field( (string) $health_result['message'] );
		}

		return null;
	}

	/**
	 * @param int $timestamp Timestamp.
	 * @return string|null
	 */
	private function format_timestamp( $timestamp ) {
		$timestamp = absint( $timestamp );

		if ( $timestamp <= 0 ) {
			return null;
		}

		return gmdate( 'c', $timestamp );
	}

	/**
	 * @param string $value Value.
	 * @param int    $max Max length.
	 * @return string
	 */
	private function trim_string( $value, $max ) {
		$value = (string) $value;

		if ( strlen( $value ) <= $max ) {
			return $value;
		}

		return substr( $value, 0, $max ) . '...';
	}
}
