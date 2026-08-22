<?php
/**
 * Customer-side WordPress health reporter.
 *
 * Builds a compact site-health snapshot for Portal pull requests.
 * Automatic outbound reports are disabled; Portal reads /health directly.
 * The legacy manual send action remains available for diagnostics only.
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
		try {
			$disk = $this->get_disk_stats();
		} catch ( Throwable $error ) {
			$disk = $this->get_unavailable_disk_stats( 'بررسی فضای ذخیره‌سازی در دسترس نیست.' );
		}
		$cron_status  = Mobo_Core_Cron_Runner::get_status();
		$self_status  = class_exists( 'Mobo_Core_Self_Runner' ) ? Mobo_Core_Self_Runner::get_status() : array();
		$logs         = $this->get_log_stats();
		$cache        = $this->get_cache_stats();
		$cache_purge  = class_exists( 'Mobo_Core_Cache_Purger' ) ? Mobo_Core_Cache_Purger::get_health_status() : array();
		$wp_memory    = $this->get_wordpress_memory_stats();
		$environment  = $this->get_environment_stats();
		$webhook_auth = class_exists( 'Mobo_Core_Webhook_Auth_Status' ) ? Mobo_Core_Webhook_Auth_Status::get_status() : array();
		$runtime_diagnostics = class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ? Mobo_Core_Runtime_Diagnostics::get_health_status() : array();

		$last_self_run     = isset( $self_status['lastRunAt'] ) ? absint( $self_status['lastRunAt'] ) : 0;
		$last_cron_hit     = isset( $cron_status['lastHitAt'] ) ? absint( $cron_status['lastHitAt'] ) : 0;
		$last_sync_success = $this->get_last_sync_success_timestamp( $sync_status );
		$last_error        = $this->resolve_last_error( $sync_status, $cron_status );

		$plugin_db_version      = sanitize_text_field( (string) get_option( 'mobo_core_db_version', '' ) );
		$schema_version         = sanitize_text_field( (string) get_option( 'mobo_core_schema_version', '' ) );
		$schema_last_error      = sanitize_text_field( (string) get_option( 'mobo_core_schema_last_error', '' ) );
		$schema_last_error_at   = absint( get_option( 'mobo_core_schema_last_error_at', 0 ) );
		$current_plugin_version = defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '';
		$database_schema_ready  = '' !== $current_plugin_version
			&& $plugin_db_version === $current_plugin_version
			&& $schema_version === $current_plugin_version
			&& '' === $schema_last_error;

		return array(
			'siteUrl'               => home_url( '/' ),
			'licenseToken'          => (string) get_option( 'mobo_core_token', '' ),
			'pluginVersion'         => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
			'webhookCredential'      => $webhook_auth,
			'wordpressVersion'      => get_bloginfo( 'version' ),
			'phpVersion'            => PHP_VERSION,
			'wooCommerceVersion'    => $this->get_woocommerce_version(),
			'webServerSoftware'     => $environment['web_server'],
			'serverName'            => $environment['server_name'],
			'databaseVersion'       => $environment['database_version'],
			'pluginDatabaseVersion' => $plugin_db_version,
			'databaseSchemaVersion' => $schema_version,
			'databaseSchemaReady'   => $database_schema_ready,
			'databaseSchemaLastError' => $schema_last_error,
			'databaseSchemaLastErrorAt' => $this->format_timestamp( $schema_last_error_at ),
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
			'cachePurge'            => $cache_purge,
			'runtimeDiagnostics'     => $runtime_diagnostics,

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

			// Account-level quota fields. Legacy disk* values remain null when only
			// the underlying server filesystem capacity is visible to PHP.
			'diskFreeBytes'              => $disk['free'],
			'diskTotalBytes'             => $disk['total'],
			'diskFreePercent'            => $disk['percent'],
			'diskMetricScope'            => $disk['metric_scope'],
			'diskMetricSource'           => $disk['metric_source'],
			'diskMetricNote'             => $disk['metric_note'],
			'accountDiskQuotaAvailable'  => $disk['account_available'],
			'accountDiskQuotaUnlimited'  => $disk['account_unlimited'],
			'accountDiskUsedBytes'       => $disk['account_used'],
			'accountDiskQuotaBytes'      => $disk['account_limit'],
			'accountDiskFreeBytes'       => $disk['account_free'],
			'accountDiskFreePercent'     => $disk['account_free_percent'],
			'accountDiskUnderLimit'      => $disk['account_under_limit'],
			'accountInodesUsed'          => $disk['account_inodes_used'],
			'accountInodesLimit'         => $disk['account_inodes_limit'],
			'accountInodesFree'          => $disk['account_inodes_free'],
			'accountInodesFreePercent'   => $disk['account_inodes_percent'],
			'accountUnderInodeLimit'     => $disk['account_under_inode_limit'],
			'accountQuotaError'          => $disk['quota_error'],
			'filesystemDiskFreeBytes'    => $disk['filesystem_free'],
			'filesystemDiskTotalBytes'   => $disk['filesystem_total'],
			'filesystemDiskFreePercent' => $disk['filesystem_percent'],
			'storageWriteProbeOk'        => isset( $disk['write_probe']['ok'] ) ? (bool) $disk['write_probe']['ok'] : null,
			'storageWriteProbeStatus'    => isset( $disk['write_probe']['status'] ) ? sanitize_key( (string) $disk['write_probe']['status'] ) : 'unavailable',
			'storageWriteProbeError'     => isset( $disk['write_probe']['error'] ) ? sanitize_text_field( (string) $disk['write_probe']['error'] ) : '',
			'storageWriteProbeCheckedAt' => isset( $disk['write_probe']['checked_at'] ) ? $this->format_timestamp( absint( $disk['write_probe']['checked_at'] ) ) : null,
			'storageWriteProbeBytes'     => isset( $disk['write_probe']['bytes'] ) ? absint( $disk['write_probe']['bytes'] ) : 0,

			'cronMode'              => $last_self_run > 0 ? 'self_runner' : ( $last_cron_hit > 0 ? 'real_cron' : 'not_detected' ),
			'cronRunner'            => class_exists( 'Mobo_Core_Cron_Runner' ) ? Mobo_Core_Cron_Runner::get_health_status() : array(),
			'portalHeartbeat'       => array(
				'lastAttemptAt'       => absint( get_option( 'mobo_core_portal_heartbeat_last_attempt_at', 0 ) ),
				'lastSuccessAt'       => absint( get_option( 'mobo_core_portal_heartbeat_last_success_at', 0 ) ),
				'secondsSinceSuccess' => absint( get_option( 'mobo_core_portal_heartbeat_last_success_at', 0 ) ) > 0
					? max( 0, time() - absint( get_option( 'mobo_core_portal_heartbeat_last_success_at', 0 ) ) )
					: 0,
				'lastResult'          => get_option( 'mobo_core_portal_heartbeat_last_result', array() ),
			),
			'syncHealth'            => class_exists( 'Mobo_Core_Reconciliation' ) ? Mobo_Core_Reconciliation::get_dashboard_status() : array(),
			'syncStatus'            => $sync_status,
			'remoteControl'          => class_exists( 'Mobo_Core_Remote_Control' ) ? Mobo_Core_Remote_Control::get_status() : array(),
			'settingsSnapshot'       => Mobo_Core_Settings::get_portal_settings_metadata(),
			'upgradeBarrier'        => class_exists( 'Mobo_Core_Upgrade_Coordinator' ) ? Mobo_Core_Upgrade_Coordinator::get_status() : array( 'active' => false, 'status' => 'unavailable' ),
			'lastCronHitAt'         => $this->format_timestamp( $last_self_run > 0 ? $last_self_run : $last_cron_hit ),
			'lastSyncSuccessAt'     => $this->format_timestamp( $last_sync_success ),
			'lastWebhookSuccessAt'  => $this->format_timestamp( $this->get_last_webhook_success_timestamp( $cron_status ) ),

			'pendingWebhookJobs'    => $queue_stats['pending'],
			'failedWebhookJobs'     => $queue_stats['failed'],
			'oldestWebhookPendingAt'=> isset( $queue_stats['oldestPendingAt'] ) ? (string) $queue_stats['oldestPendingAt'] : '',
			'pendingImageJobs'      => $image_stats['pending'],
			'attachingImageJobs'    => isset( $image_stats['attaching'] ) ? absint( $image_stats['attaching'] ) : 0,
			'failedImageJobs'       => $image_stats['failed'],
			'oldestImagePendingAt'  => isset( $image_stats['oldestPendingAt'] ) ? (string) $image_stats['oldestPendingAt'] : '',
			'nextImageRetryAt'      => isset( $image_stats['nextRetryAt'] ) ? (string) $image_stats['nextRetryAt'] : '',
			'pendingSyncJobs'       => $this->get_pending_sync_jobs( $sync_status ),
			'actionSchedulerPastDue'=> $this->get_action_scheduler_past_due_count(),
			'actionSchedulerFailed' => $this->get_action_scheduler_failed_count(),

			'disableWpCronDefined'   => defined( 'DISABLE_WP_CRON' ),
			'wpCronDisabled'         => defined( 'DISABLE_WP_CRON' ) && true === DISABLE_WP_CRON,
			'imageProcessing'        => $this->get_image_processing_stats(),
			'phpInfoSummary'         => $this->get_php_info_summary(),

			'lastError'             => $last_error,
		);
	}

	/**
	 * Legacy compatibility method. No outbound request is performed.
	 *
	 * @param string $source Source label.
	 * @param bool   $force Retained for backward compatibility.
	 * @return array
	 */
	public function send_report( $source = 'real-cron', $force = false ) {
		return array(
			'success' => true,
			'status'  => 'portal-pull-only',
			'message' => 'ارسال گزارش سلامت غیرفعال است؛ Portal گزارش را مستقیماً از endpoint سلامت دریافت می‌کند.',
		);
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
			'enabled'       => false,
			'deliveryMode'  => 'portal-pull',
			'reportUrl'     => '',
			'lastAttemptAt' => absint( get_option( 'mobo_core_health_last_report_attempt_at', 0 ) ),
			'lastSuccessAt' => absint( get_option( 'mobo_core_health_last_report_success_at', 0 ) ),
			'lastResult'    => array(),
			'message'       => 'Portal گزارش سلامت را مستقیماً از endpoint افزونه دریافت می‌کند.',
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
	 * Return a compact, non-secret PHP/runtime summary suitable for health reports.
	 * Raw phpinfo output is intentionally never sent.
	 *
	 * @return array
	 */
	private function get_php_info_summary() {
		$extensions = get_loaded_extensions();
		sort( $extensions, SORT_NATURAL | SORT_FLAG_CASE );

		return array(
			'sapi'               => PHP_SAPI,
			'os'                 => PHP_OS_FAMILY,
			'iniFile'            => (string) php_ini_loaded_file(),
			'memoryLimit'        => (string) ini_get( 'memory_limit' ),
			'maxExecutionTime'   => (string) ini_get( 'max_execution_time' ),
			'maxInputVars'       => (string) ini_get( 'max_input_vars' ),
			'postMaxSize'        => (string) ini_get( 'post_max_size' ),
			'uploadMaxFilesize'  => (string) ini_get( 'upload_max_filesize' ),
			'extensions'         => array_values( $extensions ),
		);
	}

	/**
	 * Detect the active WordPress image stack and WebP support.
	 *
	 * @return array
	 */
	private function get_image_processing_stats() {
		$gd_loaded       = extension_loaded( 'gd' );
		$gd_webp         = $gd_loaded && function_exists( 'imagewebp' );
		$imagick_loaded  = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$imagick_webp    = false;

		if ( $imagick_loaded ) {
			try {
				$formats = Imagick::queryFormats( 'WEBP' );
				$imagick_webp = ! empty( $formats );
			} catch ( Throwable $error ) {
				$imagick_webp = false;
			}
		}

		$wp_webp = function_exists( 'wp_image_editor_supports' ) ? wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) : ( $gd_webp || $imagick_webp );
		$uploads = wp_upload_dir();
		$probe   = $this->get_storage_write_probe();
		$automation = class_exists( 'Mobo_Core_Image_Refresh_Automation' ) ? Mobo_Core_Image_Refresh_Automation::get_status() : array();

		$uploads_writable = empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && wp_is_writable( $uploads['basedir'] );
		$uploads_error    = ! empty( $uploads['error'] ) ? (string) $uploads['error'] : '';
		if ( $uploads_writable && isset( $probe['ok'] ) && false === (bool) $probe['ok'] ) {
			$uploads_writable = false;
			$uploads_error    = isset( $probe['error'] ) && '' !== trim( (string) $probe['error'] )
				? (string) $probe['error']
				: 'آزمون نوشتن واقعی در uploads ناموفق بود.';
		}

		return array(
			'gdLoaded'          => $gd_loaded,
			'gdWebp'            => $gd_webp,
			'imagickLoaded'     => $imagick_loaded,
			'imagickWebp'       => $imagick_webp,
			'wordpressWebp'     => (bool) $wp_webp,
			'uploadsWritable'   => $uploads_writable,
			'uploadsError'      => sanitize_text_field( $uploads_error ),
			'writeProbeOk'      => isset( $probe['ok'] ) ? (bool) $probe['ok'] : null,
			'writeProbeStatus'  => isset( $probe['status'] ) ? sanitize_key( (string) $probe['status'] ) : 'unavailable',
			'writeProbeError'   => isset( $probe['error'] ) ? sanitize_text_field( (string) $probe['error'] ) : '',
			'refreshAutomationEnabled'         => ! empty( $automation['enabled'] ),
			'refreshAutomationStep'            => absint( isset( $automation['currentStep'] ) ? $automation['currentStep'] : 0 ),
			'refreshAutomationStatus'          => isset( $automation['status'] ) ? sanitize_key( (string) $automation['status'] ) : 'idle',
			'refreshAutomationWaitingApproval' => isset( $automation['waitingApproval'] ) ? sanitize_key( (string) $automation['waitingApproval'] ) : '',
			'refreshAutomationLastRunAt'        => absint( isset( $automation['lastRunAt'] ) ? $automation['lastRunAt'] : 0 ),
			'refreshAutomationMessage'          => isset( $automation['message'] ) ? sanitize_text_field( (string) $automation['message'] ) : '',
		);
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
			return array( 'pending' => 0, 'attaching' => 0, 'failed' => 0, 'due' => 0, 'nextRetryAt' => '', 'oldestPendingAt' => '' );
		}

		$queue   = new Mobo_Core_Image_Queue();
		$summary = $queue->get_summary();
		return array(
			'pending'         => absint( isset( $summary['pending'] ) ? $summary['pending'] : 0 ),
			'attaching'       => absint( isset( $summary['attaching'] ) ? $summary['attaching'] : 0 ),
			'failed'          => absint( isset( $summary['failed'] ) ? $summary['failed'] : 0 ),
			'due'             => absint( isset( $summary['due'] ) ? $summary['due'] : 0 ),
			'nextRetryAt'     => isset( $summary['nextRetryAt'] ) ? (string) $summary['nextRetryAt'] : '',
			'oldestPendingAt' => isset( $summary['oldestPendingAt'] ) ? (string) $summary['oldestPendingAt'] : '',
		);
	}

	private function get_webhook_queue_stats() {
		$pending   = 0;
		$failed    = 0;
		$oldest_at = '';

		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) && Mobo_Core_Sync_Event_Store::table_exists() ) {
			$event_store = new Mobo_Core_Sync_Event_Store();
			$summary     = $event_store->get_summary();
			$pending    += absint( isset( $summary['pendingCount'] ) ? $summary['pendingCount'] : 0 );
			$failed     += absint( isset( $summary['failedCount'] ) ? $summary['failedCount'] : 0 );
			$oldest_at   = isset( $summary['oldestPendingAt'] ) ? (string) $summary['oldestPendingAt'] : '';
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
			'pending'         => $pending,
			'failed'          => $failed,
			'oldestPendingAt' => $oldest_at,
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
	 * Return storage statistics without confusing server filesystem capacity with
	 * the hosting account quota. The legacy disk* fields are populated only when
	 * an account-level quota is known. Filesystem capacity is reported separately.
	 *
	 * @return array
	 */
	private function get_disk_stats() {
		try {
			return $this->build_disk_stats();
		} catch ( Throwable $error ) {
			return $this->get_unavailable_disk_stats( 'بررسی فضای ذخیره‌سازی در دسترس نیست.' );
		}
	}

	/**
	 * Build storage statistics. All callers use get_disk_stats(), which provides
	 * the final Throwable boundary for the Site Health endpoint.
	 *
	 * @return array
	 */
	private function build_disk_stats() {
		$filesystem_free    = $this->safe_disk_free_space( ABSPATH );
		$filesystem_total   = $this->safe_disk_total_space( ABSPATH );
		$filesystem_percent = null;

		if ( null !== $filesystem_free && null !== $filesystem_total && $filesystem_total > 0 ) {
			$filesystem_percent = round( ( $filesystem_free / $filesystem_total ) * 100, 2 );
		}

		try {
			$quota = $this->get_hosting_quota_stats();
		} catch ( Throwable $error ) {
			$quota = $this->get_unavailable_quota_stats( 'بررسی سهمیه اکانت در دسترس نیست.' );
		}

		try {
			$probe = $this->get_storage_write_probe();
		} catch ( Throwable $error ) {
			$probe = $this->get_unavailable_write_probe( 'آزمون نوشتن در uploads در دسترس نیست.' );
		}

		$metric_scope  = 'filesystem_only';
		$metric_source = 'php_disk_functions';
		$metric_note   = 'این مقدار ظرفیت Filesystem سرور است و سهمیه اکانت هاست محسوب نمی‌شود.';
		$free          = null;
		$total         = null;
		$percent       = null;

		if ( ! empty( $quota['available'] ) ) {
			$metric_source = isset( $quota['source'] ) ? sanitize_key( (string) $quota['source'] ) : 'hosting_quota';

			if ( ! empty( $quota['unlimited'] ) ) {
				$metric_scope = 'account_unlimited';
				$metric_note  = 'سهمیه اکانت هاست نامحدود گزارش شده است.';
			} else {
				$metric_scope = 'account_quota';
				$metric_note  = 'مقادیر دیسک از سهمیه اکانت هاست محاسبه شده‌اند.';
				$free         = isset( $quota['free'] ) ? $quota['free'] : null;
				$total        = isset( $quota['limit'] ) ? $quota['limit'] : null;
				$percent      = isset( $quota['free_percent'] ) ? $quota['free_percent'] : null;
			}
		} elseif ( null === $filesystem_free && null === $filesystem_total ) {
			$metric_scope  = 'unavailable';
			$metric_source = 'unavailable';
			$metric_note   = 'اطلاعات سهمیه اکانت و ظرفیت Filesystem در دسترس PHP نیست.';
		}

		return array(
			// Backward-compatible fields. Deliberately null without account quota.
			'free'                     => $free,
			'total'                    => $total,
			'percent'                  => $percent,
			'metric_scope'             => $metric_scope,
			'metric_source'            => $metric_source,
			'metric_note'              => $metric_note,
			'account_available'        => ! empty( $quota['available'] ),
			'account_unlimited'        => ! empty( $quota['unlimited'] ),
			'account_used'             => isset( $quota['used'] ) ? $quota['used'] : null,
			'account_limit'            => isset( $quota['limit'] ) ? $quota['limit'] : null,
			'account_free'             => isset( $quota['free'] ) ? $quota['free'] : null,
			'account_free_percent'     => isset( $quota['free_percent'] ) ? $quota['free_percent'] : null,
			'account_under_limit'      => isset( $quota['under_limit'] ) ? $quota['under_limit'] : null,
			'account_inodes_used'      => isset( $quota['inodes_used'] ) ? $quota['inodes_used'] : null,
			'account_inodes_limit'     => isset( $quota['inodes_limit'] ) ? $quota['inodes_limit'] : null,
			'account_inodes_free'      => isset( $quota['inodes_free'] ) ? $quota['inodes_free'] : null,
			'account_inodes_percent'   => isset( $quota['inodes_free_percent'] ) ? $quota['inodes_free_percent'] : null,
			'account_under_inode_limit'=> isset( $quota['under_inode_limit'] ) ? $quota['under_inode_limit'] : null,
			'quota_error'              => isset( $quota['error'] ) ? sanitize_text_field( (string) $quota['error'] ) : '',
			'filesystem_free'          => $filesystem_free,
			'filesystem_total'         => $filesystem_total,
			'filesystem_percent'       => $filesystem_percent,
			'write_probe'              => $probe,
		);
	}

	/**
	 * Resolve account-level hosting quota data.
	 *
	 * Integrations may provide data through the mobo_core_hosting_quota_stats
	 * filter. cPanel UAPI is also supported when the following wp-config.php
	 * constants are explicitly configured: MOBO_CORE_CPANEL_QUOTA_URL,
	 * MOBO_CORE_CPANEL_USERNAME and MOBO_CORE_CPANEL_API_TOKEN.
	 *
	 * @return array
	 */
	private function get_hosting_quota_stats() {
		try {
			$filtered = apply_filters( 'mobo_core_hosting_quota_stats', null, ABSPATH );
			if ( is_array( $filtered ) ) {
				$normalized = $this->normalize_hosting_quota_stats( $filtered, 'filter' );
				if ( ! empty( $normalized['available'] ) ) {
					return $normalized;
				}
			}

			if (
				! defined( 'MOBO_CORE_CPANEL_QUOTA_URL' ) ||
				! defined( 'MOBO_CORE_CPANEL_USERNAME' ) ||
				! defined( 'MOBO_CORE_CPANEL_API_TOKEN' )
			) {
				return array(
					'available' => false,
					'source'    => 'none',
					'error'     => '',
				);
			}

			$url      = trim( (string) MOBO_CORE_CPANEL_QUOTA_URL );
			$username = trim( (string) MOBO_CORE_CPANEL_USERNAME );
			$token    = trim( (string) MOBO_CORE_CPANEL_API_TOKEN );

			if ( '' === $url || '' === $username || '' === $token ) {
				return array(
					'available' => false,
					'source'    => 'cpanel_uapi',
					'error'     => 'تنظیمات cPanel UAPI ناقص است.',
				);
			}

			if ( false === strpos( $url, '/execute/' ) ) {
				$url = untrailingslashit( $url ) . '/execute/Quota/get_quota_info';
			}

			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) ) {
				return array(
					'available' => false,
					'source'    => 'cpanel_uapi',
					'error'     => 'آدرس cPanel UAPI باید یک URL معتبر HTTPS باشد.',
				);
			}

			$cache_key = 'mobo_core_cpanel_quota_' . md5( strtolower( $url . '|' . $username ) );
			$cached    = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 8,
					'redirection' => 0,
					'sslverify'   => true,
					'headers'     => array(
						'Accept'        => 'application/json',
						'Authorization' => 'cpanel ' . $username . ':' . $token,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$result = array(
					'available' => false,
					'source'    => 'cpanel_uapi',
					'error'     => 'اتصال به cPanel UAPI ناموفق بود: ' . $response->get_error_message(),
				);
				set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
				return $result;
			}

			$status_code = absint( wp_remote_retrieve_response_code( $response ) );
			$body        = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $status_code || ! is_array( $body ) || empty( $body['status'] ) || empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
				$result = array(
					'available' => false,
					'source'    => 'cpanel_uapi',
					'error'     => 'پاسخ معتبر سهمیه از cPanel UAPI دریافت نشد. HTTP ' . $status_code,
				);
				set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
				return $result;
			}

			$data = $body['data'];
			$raw  = array(
				'used'              => $this->megabytes_to_bytes( isset( $data['megabytes_used'] ) ? $data['megabytes_used'] : null ),
				'limit'             => $this->megabytes_to_bytes( isset( $data['megabyte_limit'] ) ? $data['megabyte_limit'] : null ),
				'free'              => $this->megabytes_to_bytes( isset( $data['megabytes_remain'] ) ? $data['megabytes_remain'] : null ),
				'unlimited'         => isset( $data['megabyte_limit'] ) && is_numeric( $data['megabyte_limit'] ) && (float) $data['megabyte_limit'] <= 0,
				'under_limit'       => isset( $data['under_megabyte_limit'] ) ? (bool) absint( $data['under_megabyte_limit'] ) : null,
				'inodes_used'       => isset( $data['inodes_used'] ) && is_numeric( $data['inodes_used'] ) ? (int) $data['inodes_used'] : null,
				'inodes_limit'      => isset( $data['inode_limit'] ) && is_numeric( $data['inode_limit'] ) ? (int) $data['inode_limit'] : null,
				'inodes_free'       => isset( $data['inodes_remain'] ) && is_numeric( $data['inodes_remain'] ) ? (int) $data['inodes_remain'] : null,
				'under_inode_limit' => isset( $data['under_inode_limit'] ) ? (bool) absint( $data['under_inode_limit'] ) : null,
			);

			$result = $this->normalize_hosting_quota_stats( $raw, 'cpanel_uapi' );
			set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );

			return $result;
		} catch ( Throwable $error ) {
			return $this->get_unavailable_quota_stats( 'بررسی سهمیه اکانت در دسترس نیست.' );
		}
	}

	/**
	 * Normalize quota values from filters or cPanel.
	 *
	 * @param array  $stats  Raw stats.
	 * @param string $source Source name.
	 * @return array
	 */
	private function normalize_hosting_quota_stats( $stats, $source ) {
		$used      = isset( $stats['used'] ) && is_numeric( $stats['used'] ) ? max( 0, (int) $stats['used'] ) : null;
		$limit     = isset( $stats['limit'] ) && is_numeric( $stats['limit'] ) ? max( 0, (int) $stats['limit'] ) : null;
		$free      = isset( $stats['free'] ) && is_numeric( $stats['free'] ) ? max( 0, (int) $stats['free'] ) : null;
		$unlimited = ! empty( $stats['unlimited'] );

		if ( ! $unlimited && null !== $limit && $limit > 0 ) {
			if ( null === $free && null !== $used ) {
				$free = max( 0, $limit - $used );
			}
			if ( null === $used && null !== $free ) {
				$used = max( 0, $limit - $free );
			}
		}

		$free_percent = null;
		if ( ! $unlimited && null !== $limit && $limit > 0 && null !== $free ) {
			$free_percent = round( min( 100, max( 0, ( $free / $limit ) * 100 ) ), 2 );
		}

		$inodes_used  = isset( $stats['inodes_used'] ) && is_numeric( $stats['inodes_used'] ) ? max( 0, (int) $stats['inodes_used'] ) : null;
		$inodes_limit = isset( $stats['inodes_limit'] ) && is_numeric( $stats['inodes_limit'] ) ? max( 0, (int) $stats['inodes_limit'] ) : null;
		$inodes_free  = isset( $stats['inodes_free'] ) && is_numeric( $stats['inodes_free'] ) ? max( 0, (int) $stats['inodes_free'] ) : null;

		if ( null !== $inodes_limit && $inodes_limit > 0 ) {
			if ( null === $inodes_free && null !== $inodes_used ) {
				$inodes_free = max( 0, $inodes_limit - $inodes_used );
			}
			if ( null === $inodes_used && null !== $inodes_free ) {
				$inodes_used = max( 0, $inodes_limit - $inodes_free );
			}
		}

		$inodes_free_percent = null;
		if ( null !== $inodes_limit && $inodes_limit > 0 && null !== $inodes_free ) {
			$inodes_free_percent = round( min( 100, max( 0, ( $inodes_free / $inodes_limit ) * 100 ) ), 2 );
		}

		$available = $unlimited || ( null !== $limit && $limit > 0 );

		return array(
			'available'           => $available,
			'source'              => sanitize_key( (string) $source ),
			'used'                => $used,
			'limit'               => $limit,
			'free'                => $free,
			'free_percent'        => $free_percent,
			'unlimited'           => $unlimited,
			'under_limit'         => isset( $stats['under_limit'] ) ? (bool) $stats['under_limit'] : ( $available && ! $unlimited && null !== $free ? $free > 0 : null ),
			'inodes_used'         => $inodes_used,
			'inodes_limit'        => $inodes_limit,
			'inodes_free'         => $inodes_free,
			'inodes_free_percent' => $inodes_free_percent,
			'under_inode_limit'   => isset( $stats['under_inode_limit'] ) ? (bool) $stats['under_inode_limit'] : ( null !== $inodes_limit && $inodes_limit > 0 && null !== $inodes_free ? $inodes_free > 0 : null ),
			'error'               => isset( $stats['error'] ) ? sanitize_text_field( (string) $stats['error'] ) : '',
		);
	}

	/**
	 * Perform a real, cached write test inside uploads. is_writable() may still
	 * return true after a hosting account has exhausted its byte or inode quota.
	 *
	 * @return array
	 */
	private function get_storage_write_probe() {
		$probe_filename = '';

		try {
			$cache_key = 'mobo_core_storage_write_probe_v1';
			$cached    = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			$checked_at = time();
			$bytes      = 64 * 1024;
			$uploads    = wp_upload_dir();
			$basedir    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
			$result     = array(
				'ok'         => false,
				'status'     => 'failed',
				'error'      => '',
				'checked_at' => $checked_at,
				'bytes'      => $bytes,
				'path'       => $basedir,
			);

			if ( ! empty( $uploads['error'] ) ) {
				$result['error'] = sanitize_text_field( (string) $uploads['error'] );
				set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
				return $result;
			}

			if ( '' === $basedir || ( ! is_dir( $basedir ) && ! wp_mkdir_p( $basedir ) ) ) {
				$result['error'] = 'پوشه uploads وجود ندارد یا قابل ایجاد نیست.';
				set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
				return $result;
			}

			if ( ! wp_is_writable( $basedir ) ) {
				$result['error'] = 'پوشه uploads بر اساس مجوزهای Filesystem قابل نوشتن نیست.';
				set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
				return $result;
			}

			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$filesystem = new WP_Filesystem_Direct( null );
			$filename       = trailingslashit( $basedir ) . '.mobo-core-write-probe-' . wp_generate_password( 12, false, false ) . '.tmp';
			$probe_filename = $filename;
			$payload        = str_repeat( 'M', $bytes );

			if ( function_exists( 'error_clear_last' ) ) {
				error_clear_last();
			}

			$written_ok = $filesystem->put_contents( $filename, $payload, FS_CHMOD_FILE );
			$actual_size = $written_ok ? $filesystem->size( $filename ) : false;
			$deleted     = $written_ok ? $filesystem->delete( $filename, false, 'f' ) : false;
			$probe_filename = $deleted ? '' : $filename;

			if ( $written_ok && false !== $actual_size && (int) $actual_size >= $bytes ) {
				$result['ok']     = true;
				$result['status'] = $deleted ? 'ok' : 'ok_cleanup_failed';
				if ( ! $deleted ) {
					$result['error'] = 'نوشتن موفق بود اما حذف فایل آزمایشی ناموفق بود.';
				}
			} else {
				$result['error'] = $this->get_last_php_error_message( 'نوشتن واقعی در uploads کامل نشد؛ سهمیه دیسک، inode یا مجوز نوشتن را بررسی کنید.' );
			}

			set_transient( $cache_key, $result, $result['ok'] ? 10 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
			return $result;
		} catch ( Throwable $error ) {
			try {
				if ( '' !== $probe_filename ) {
					wp_delete_file( $probe_filename );
				}
			} catch ( Throwable $cleanup_error ) {
				// Cleanup must never make Site Health fail.
			}

			return $this->get_unavailable_write_probe( 'آزمون نوشتن در uploads در دسترس نیست.' );
		}
	}

	/**
	 * Return a stable account-quota fallback without exposing exception details.
	 *
	 * @param string $message Public diagnostic message.
	 * @return array
	 */
	private function get_unavailable_quota_stats( $message ) {
		return array(
			'available' => false,
			'source'    => 'unavailable',
			'error'     => (string) $message,
		);
	}

	/**
	 * Return a stable storage write-probe fallback.
	 *
	 * @param string $message Public diagnostic message.
	 * @return array
	 */
	private function get_unavailable_write_probe( $message ) {
		return array(
			'ok'         => null,
			'status'     => 'unavailable',
			'error'      => (string) $message,
			'checked_at' => time(),
			'bytes'      => 0,
			'path'       => '',
		);
	}

	/**
	 * Return a complete storage fallback so Site Health remains available even
	 * when a hosting integration, filter, filesystem function or write probe
	 * throws an unexpected Throwable.
	 *
	 * @param string $message Public diagnostic message.
	 * @return array
	 */
	private function get_unavailable_disk_stats( $message ) {
		return array(
			'free'                      => null,
			'total'                     => null,
			'percent'                   => null,
			'metric_scope'              => 'unavailable',
			'metric_source'             => 'unavailable',
			'metric_note'               => (string) $message,
			'account_available'         => false,
			'account_unlimited'         => false,
			'account_used'              => null,
			'account_limit'             => null,
			'account_free'              => null,
			'account_free_percent'      => null,
			'account_under_limit'       => null,
			'account_inodes_used'       => null,
			'account_inodes_limit'      => null,
			'account_inodes_free'       => null,
			'account_inodes_percent'    => null,
			'account_under_inode_limit' => null,
			'quota_error'               => (string) $message,
			'filesystem_free'           => null,
			'filesystem_total'          => null,
			'filesystem_percent'        => null,
			'write_probe'               => $this->get_unavailable_write_probe( $message ),
		);
	}

	/**
	 * @param mixed $megabytes Megabytes.
	 * @return int|null
	 */
	private function megabytes_to_bytes( $megabytes ) {
		if ( ! is_numeric( $megabytes ) ) {
			return null;
		}

		return (int) round( max( 0, (float) $megabytes ) * 1024 * 1024 );
	}

	/**
	 * @param string $fallback Fallback message.
	 * @return string
	 */
	private function get_last_php_error_message( $fallback ) {
		$error = error_get_last();
		if ( is_array( $error ) && ! empty( $error['message'] ) ) {
			return sanitize_text_field( (string) $error['message'] );
		}

		return sanitize_text_field( (string) $fallback );
	}

	/**
	 * @param string $path Path.
	 * @return int|null
	 */
	private function safe_disk_free_space( $path ) {
		try {
			if ( ! function_exists( 'disk_free_space' ) ) {
				return null;
			}

			$value = @disk_free_space( $path );

			return false === $value ? null : (int) $value;
		} catch ( Throwable $error ) {
			return null;
		}
	}

	/**
	 * @param string $path Path.
	 * @return int|null
	 */
	private function safe_disk_total_space( $path ) {
		try {
			if ( ! function_exists( 'disk_total_space' ) ) {
				return null;
			}

			$value = @disk_total_space( $path );

			return false === $value ? null : (int) $value;
		} catch ( Throwable $error ) {
			return null;
		}
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
