<?php
/**
 * MoboCore Health / Sync Health last-good snapshot layer.
 *
 * r2 security contract:
 * - NEVER intercept REST before the endpoint permission_callback.
 * - Health snapshots are served only from rest_dispatch_request, which runs
 *   after route matching, validation and permission_callback.
 * - Expensive Health collectors run in background refresh mode.
 * - Sync Health operational audit is served from a durable last-good snapshot
 *   and refreshed outside ordinary admin page reads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Health_Read_Cache {

	const REST_SNAPSHOT_TRANSIENT = 'mobo_core_health_rest_snapshot_v2';
	const REST_SNAPSHOT_OPTION    = 'mobo_core_health_rest_snapshot_last_good_v2';
	const AUX_SNAPSHOT_OPTION     = 'mobo_core_health_aux_snapshot_v2';
	const CPANEL_SNAPSHOT_OPTION  = 'mobo_core_health_cpanel_http_snapshot_v2';
	const REST_SNAPSHOT_STATE_KEY = 'health_rest_snapshot_last_good_v2';
	const AUX_SNAPSHOT_STATE_KEY  = 'health_aux_snapshot_v2';
	const CPANEL_SNAPSHOT_STATE_KEY = 'health_cpanel_http_snapshot_v2';
	const SYNC_SNAPSHOT_STATE_PREFIX = 'sync_health_operational_last_good_v2_';

	const REFRESH_LOCK_OPTION     = 'mobo_core_health_snapshot_refresh_lock_v2';
	const CRON_HOOK               = 'mobo_core_health_snapshot_refresh_v2';
	const SYNC_CRON_HOOK          = 'mobo_core_sync_health_snapshot_refresh_v2';
	const CRON_SCHEDULE           = 'mobo_core_health_every_minute_v2';

	const HEALTH_REFRESH_REQUESTED_OPTION = 'mobo_core_health_snapshot_refresh_requested_at';
	const HEALTH_BACKGROUND_SUCCESS_OPTION = 'mobo_core_health_snapshot_last_background_success_at';
	const SYNC_REFRESH_REQUESTED_AT_OPTION = 'mobo_core_sync_health_snapshot_refresh_requested_at';
	const SYNC_REFRESH_REQUESTED_LIMIT_OPTION = 'mobo_core_sync_health_snapshot_refresh_requested_limit';
	const SYNC_BACKGROUND_SUCCESS_OPTION = 'mobo_core_sync_health_snapshot_last_background_success_at';

	const SYNC_TRANSIENT_PREFIX   = 'mobo_core_sync_health_operational_snapshot_v2_';
	const SYNC_OPTION_PREFIX      = 'mobo_core_sync_health_operational_last_good_v2_';

	private static $booted          = false;
	private static $refreshing      = false;
	private static $sync_refreshing = false;
	private static $refresh_lock_token = '';

	/** Namespace volatile cache keys by active/default Connection. */
	private static function connection_cache_key( $base ) {
		$id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		return sanitize_key( (string) $base ) . '_c' . $id;
	}

	/** Read a large durable snapshot. Legacy fallback exists only before strict cutover. */
	private static function durable_snapshot_get( $legacy_option, $state_key, $default = array() ) {
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() ) {
			if ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { Mobo_Core_State_Store::migrate_option( $legacy_option, $state_key ); }
			return Mobo_Core_State_Store::get( $state_key, $default );
		}
		if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { return $default; }
		if ( class_exists( 'Mobo_Core_Connection_Context' ) && method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) && ! Mobo_Core_Connection_Context::legacy_default_owner_allowed() ) { return $default; }
		return get_option( $legacy_option, $default );
	}

	/** Persist a large durable snapshot; wp_options fallback is upgrade-only. */
	private static function durable_snapshot_set( $legacy_option, $state_key, $value ) {
		$legacy_owner_allowed = ! class_exists( 'Mobo_Core_Connection_Context' ) || ! method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) || Mobo_Core_Connection_Context::legacy_default_owner_allowed();
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() ) {
			if ( ! Mobo_Core_State_Store::set( $state_key, $value ) ) { return false; }
			if ( $legacy_owner_allowed && ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) ) {
				delete_option( $legacy_option );
				wp_cache_delete( $legacy_option, 'options' );
			}
			return true;
		}
		if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { return false; }
		if ( ! $legacy_owner_allowed ) { return false; }
		return class_exists( 'Mobo_Core_Durable_State_Policy' )
			? Mobo_Core_Durable_State_Policy::update_option_verified( $legacy_option, $value, false )
			: update_option( $legacy_option, $value, false );
	}

	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		/*
		 * 10.33.53: snapshot refresh ownership belongs to the deterministic Mobo
		 * real-cron runner. Keep the hook callbacks registered only for backward
		 * compatibility with explicit do_action() callers; this class no longer
		 * creates WP-Cron schedules.
		 */
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh_now' ) );
		add_action( self::SYNC_CRON_HOOK, array( __CLASS__, 'refresh_sync_health_snapshot' ), 10, 1 );

		/*
		 * SECURITY: rest_dispatch_request runs after permission_callback.
		 * Do not move this to rest_pre_dispatch.
		 */
		add_filter( 'rest_dispatch_request', array( __CLASS__, 'serve_health_snapshot' ), 10, 4 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'capture_health_snapshot' ), 999, 3 );

		/*
		 * The legacy reporter can call cPanel directly. During ordinary Health
		 * reads we substitute the last-good HTTP response. During background
		 * refresh we allow the real request and retain only a serializable,
		 * successful response.
		 */
		add_filter( 'pre_http_request', array( __CLASS__, 'serve_cpanel_http_snapshot' ), 10, 3 );
		add_filter( 'http_response', array( __CLASS__, 'capture_cpanel_http_snapshot' ), 10, 3 );
	}

	public static function cron_schedules( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 60,
				'display'  => 'MoboCore Health Snapshot (60 seconds)',
			);
		}
		return $schedules;
	}

	public static function ensure_schedule() {
		/* Deprecated compatibility shim. Final architecture does not schedule WP-Cron. */
		self::schedule_near_refresh();
		self::schedule_sync_refresh( 20 );
	}

	public static function is_refreshing() {
		return self::$refreshing;
	}

	public static function is_sync_refreshing() {
		return self::$sync_refreshing;
	}

	private static function is_health_route( $request ) {
		return $request instanceof WP_REST_Request
			&& 'GET' === strtoupper( (string) $request->get_method() )
			&& '/mobo-core/v1/health' === $request->get_route();
	}

	private static function get_health_snapshot() {
		$snapshot = get_transient( self::connection_cache_key( self::REST_SNAPSHOT_TRANSIENT ) );
		if ( is_array( $snapshot ) ) {
			return $snapshot;
		}

		$snapshot = self::durable_snapshot_get( self::REST_SNAPSHOT_OPTION, self::REST_SNAPSHOT_STATE_KEY, array() );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	private static function snapshot_to_response( $snapshot, $stale = false ) {
		if ( ! is_array( $snapshot ) || ! isset( $snapshot['body'] ) || ! is_array( $snapshot['body'] ) ) {
			return null;
		}

		$status   = isset( $snapshot['status'] ) ? absint( $snapshot['status'] ) : 200;
		$response = new WP_REST_Response( $snapshot['body'], $status > 0 ? $status : 200 );

		if ( ! empty( $snapshot['headers'] ) && is_array( $snapshot['headers'] ) ) {
			foreach ( $snapshot['headers'] as $name => $value ) {
				if ( is_scalar( $name ) && is_scalar( $value ) ) {
					$response->header( (string) $name, (string) $value );
				}
			}
		}

		$generated = isset( $snapshot['generatedAt'] ) ? absint( $snapshot['generatedAt'] ) : 0;
		$age       = $generated > 0 ? max( 0, time() - $generated ) : 0;

		$response->header( 'X-Mobo-Health-Snapshot', '1' );
		$response->header( 'X-Mobo-Health-Snapshot-Age', (string) $age );
		$response->header( 'X-Mobo-Health-Snapshot-Stale', $stale ? '1' : '0' );

		return $response;
	}

	/**
	 * Serve a cached Health result only after the endpoint permission callback
	 * has succeeded. WordPress calls this filter from respond_to_request().
	 */
	public static function serve_health_snapshot( $dispatch_result, $request, $route, $handler ) {
		if ( null !== $dispatch_result || self::$refreshing || ! self::is_health_route( $request ) ) {
			return $dispatch_result;
		}

		$snapshot = self::get_health_snapshot();
		if ( ! is_array( $snapshot ) || empty( $snapshot['generatedAt'] ) ) {
			self::schedule_near_refresh();
			return $dispatch_result;
		}

		$age = max( 0, time() - absint( $snapshot['generatedAt'] ) );

		if ( $age <= 120 ) {
			$response = self::snapshot_to_response( $snapshot, false );
			return $response instanceof WP_REST_Response ? $response : $dispatch_result;
		}

		/*
		 * Stale-while-revalidate. A last-good snapshot remains usable while a
		 * bounded background refresh is requested. We prefer stale diagnostic
		 * data to a 30-second admin/Portal timeout.
		 */
		self::schedule_near_refresh();
		$response = self::snapshot_to_response( $snapshot, true );
		return $response instanceof WP_REST_Response ? $response : $dispatch_result;
	}

	private static function store_health_response( $response ) {
		if ( ! $response instanceof WP_REST_Response ) {
			return false;
		}

		$status = absint( $response->get_status() );
		$body   = $response->get_data();

		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			return false;
		}

		if ( isset( $body['success'] ) && false === (bool) $body['success'] ) {
			return false;
		}

		$headers = $response->get_headers();
		if ( ! is_array( $headers ) ) {
			$headers = array();
		}
		unset(
			$headers['X-Mobo-Health-Snapshot'],
			$headers['X-Mobo-Health-Snapshot-Age'],
			$headers['X-Mobo-Health-Snapshot-Stale']
		);

		$safe_headers = array();
		foreach ( $headers as $name => $value ) {
			if ( is_scalar( $name ) && is_scalar( $value ) ) {
				$safe_headers[ (string) $name ] = (string) $value;
			}
		}

		$snapshot = array(
			'schemaVersion' => 2,
			'generatedAt'   => time(),
			'status'        => $status,
			'headers'       => $safe_headers,
			'body'          => $body,
		);

		set_transient( self::connection_cache_key( self::REST_SNAPSHOT_TRANSIENT ), $snapshot, DAY_IN_SECONDS );
		if ( ! self::durable_snapshot_set( self::REST_SNAPSHOT_OPTION, self::REST_SNAPSHOT_STATE_KEY, $snapshot ) ) { return false; }
		Mobo_Core_Settings::update( 'mobo_core_health_snapshot_last_success_at', time(), false );
		Mobo_Core_Settings::delete( 'mobo_core_health_snapshot_last_error' );
		return true;
	}

	public static function capture_health_snapshot( $response, $server, $request ) {
		if ( ! self::is_health_route( $request ) || ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		/*
		 * Never re-store a response that was itself served from the last-good cache.
		 * Re-capturing it would reset generatedAt and make stale diagnostic data look
		 * fresh forever when WP-Cron is disabled.
		 */
		$headers = $response->get_headers();
		if ( is_array( $headers ) ) {
			foreach ( $headers as $name => $value ) {
				if ( 0 === strcasecmp( (string) $name, 'X-Mobo-Health-Snapshot' )
					&& '1' === trim( is_array( $value ) ? implode( ',', $value ) : (string) $value ) ) {
					return $response;
				}
			}
		}

		self::store_health_response( $response );
		return $response;
	}

	private static function schedule_near_refresh() {
		$now      = time();
		$existing = absint( Mobo_Core_Settings::get( self::HEALTH_REFRESH_REQUESTED_OPTION, 0 ) );
		if ( $existing <= 0 || ( $now - $existing ) >= 30 ) {
			Mobo_Core_Settings::update( self::HEALTH_REFRESH_REQUESTED_OPTION, $now, false );
		}
	}

	private static function acquire_refresh_lock() {
		if ( ! class_exists( 'Mobo_Core_Lock' ) ) {
			return false;
		}
		$token = Mobo_Core_Lock::acquire( 'health_snapshot_refresh', 180 );
		if ( false === $token ) {
			return false;
		}
		self::$refresh_lock_token = (string) $token;
		return true;
	}

	private static function release_refresh_lock() {
		if ( '' !== self::$refresh_lock_token && class_exists( 'Mobo_Core_Lock' ) ) {
			Mobo_Core_Lock::release( 'health_snapshot_refresh', self::$refresh_lock_token );
		}
		self::$refresh_lock_token = '';
	}

	public static function refresh_now() {
		if ( self::$refreshing || ! self::acquire_refresh_lock() ) {
			return false;
		}

		self::$refreshing = true;

		try {
			self::refresh_auxiliary_stats();

			$request = new WP_REST_Request( 'GET', '/mobo-core/v1/health' );
			$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
			$connection = ( $connection_id > 0 && class_exists( 'Mobo_Core_Connection_Repository' ) )
				? Mobo_Core_Connection_Repository::get_by_id( $connection_id )
				: null;
			$sec = is_array( $connection )
				? trim( (string) ( $connection['security_code'] ?? '' ) )
				: '';

			if ( '' === $sec ) {
				Mobo_Core_Settings::update( 'mobo_core_health_snapshot_last_error', 'Health refresh cannot authenticate because X-SEC is empty.', false );
				return false;
			}

			$request->set_header( 'X-SEC', $sec );
			if ( is_array( $connection ) && ! empty( $connection['webhook_id'] ) ) {
				$request->set_header( 'X-Mobo-Webhook-Id', (string) $connection['webhook_id'] );
			}
			$request->set_header( 'X-Mobo-Webhook-Test', '1' );
			$request->set_header( 'Cache-Control', 'no-cache, no-store' );

			$response = rest_do_request( $request );

			if ( $response instanceof WP_REST_Response ) {
				$status = absint( $response->get_status() );
				if ( $status >= 200 && $status < 300 && self::store_health_response( $response ) ) {
					Mobo_Core_Settings::update( self::HEALTH_BACKGROUND_SUCCESS_OPTION, time(), false );
					Mobo_Core_Settings::delete( self::HEALTH_REFRESH_REQUESTED_OPTION );
					return true;
				}
				Mobo_Core_Settings::update( 'mobo_core_health_snapshot_last_error', 'Health refresh HTTP ' . $status, false );
				return false;
			}

			Mobo_Core_Settings::update( 'mobo_core_health_snapshot_last_error', 'Health refresh did not return WP_REST_Response.', false );
			return false;
		} catch ( Throwable $e ) {
			Mobo_Core_Settings::update( 'mobo_core_health_snapshot_last_error', 'Health snapshot refresh failed with an internal exception.', false );
			return false;
		} finally {
			self::$refreshing = false;
			self::release_refresh_lock();
		}
	}

	/** Count legacy webhook files owned by the effective Connection. */
	private static function count_owned_legacy_webhook_files( $pattern ) {
		$files = glob( (string) $pattern );
		if ( ! is_array( $files ) || empty( $files ) ) { return 0; }
		$current_connection_id = class_exists( 'Mobo_Core_Connection_Context' )
			? absint( Mobo_Core_Connection_Context::get_effective_connection_id() )
			: 0;
		$default_connection_id = 0;
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			$default = Mobo_Core_Connection_Repository::get_default();
			$default_connection_id = is_array( $default ) ? absint( $default['id'] ?? 0 ) : 0;
		}
		$count = 0;
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || ! is_file( $file ) || ! is_readable( $file ) ) { continue; }
			$contents = file_get_contents( $file );
			$data = is_string( $contents ) && '' !== trim( $contents ) ? json_decode( $contents, true ) : null;
			if ( is_array( $data ) && array_key_exists( 'connectionId', $data ) ) {
				if ( $current_connection_id > 0 && absint( $data['connectionId'] ) === $current_connection_id ) { $count++; }
				continue;
			}
			/* Pre-Multi-Connection or malformed fallback files are Default-owned. */
			if ( $default_connection_id > 0 && $current_connection_id === $default_connection_id ) { $count++; }
		}
		return $count;
	}

	public static function refresh_auxiliary_stats() {
		$pending = 0;
		$failed  = 0;

		if ( defined( 'MOBO_CORE_WEBHOOK_FILE_DIR' ) ) {
			$pending = self::count_owned_legacy_webhook_files( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . '*.json' );
			$failed  = self::count_owned_legacy_webhook_files( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/*.json' );
		}

		$as_past_due = null;
		$as_failed   = null;

		/*
		 * Reuse the reporter's canonical private counter while refresh mode is
		 * enabled. The reporter patch only short-circuits ordinary read paths.
		 */
		if ( class_exists( 'Mobo_Core_Health_Reporter' ) ) {
			try {
				$rc       = new ReflectionClass( 'Mobo_Core_Health_Reporter' );
				$reporter = $rc->newInstanceWithoutConstructor();
				$method   = new ReflectionMethod( 'Mobo_Core_Health_Reporter', 'get_action_scheduler_count' );
				if ( method_exists( $method, 'setAccessible' ) ) {
					$method->setAccessible( true );
				}
				$as_past_due = $method->invoke( $reporter, 'pending', true );
				$as_failed   = $method->invoke( $reporter, 'failed', false );
			} catch ( Throwable $e ) {
				$as_past_due = null;
				$as_failed   = null;
			}
		}

		$snapshot = array(
			'schemaVersion'          => 2,
			'generatedAt'            => time(),
			'legacyWebhookPending'   => $pending,
			'legacyWebhookFailed'    => $failed,
			'actionSchedulerPastDue' => $as_past_due,
			'actionSchedulerFailed'  => $as_failed,
		);

		self::durable_snapshot_set( self::AUX_SNAPSHOT_OPTION, self::AUX_SNAPSHOT_STATE_KEY, $snapshot );
		return $snapshot;
	}

	public static function get_legacy_webhook_file_stats() {
		$snapshot = self::durable_snapshot_get( self::AUX_SNAPSHOT_OPTION, self::AUX_SNAPSHOT_STATE_KEY, array() );
		if ( ! is_array( $snapshot ) || empty( $snapshot['generatedAt'] ) ) {
			self::schedule_near_refresh();
			return array( 'pending' => 0, 'failed' => 0, 'generatedAt' => 0, 'stale' => true );
		}

		$age = max( 0, time() - absint( $snapshot['generatedAt'] ) );
		if ( $age > 120 ) {
			self::schedule_near_refresh();
		}

		return array(
			'pending'     => isset( $snapshot['legacyWebhookPending'] ) ? absint( $snapshot['legacyWebhookPending'] ) : 0,
			'failed'      => isset( $snapshot['legacyWebhookFailed'] ) ? absint( $snapshot['legacyWebhookFailed'] ) : 0,
			'generatedAt' => absint( $snapshot['generatedAt'] ),
			'stale'       => $age > 120,
		);
	}

	public static function get_action_scheduler_count_snapshot( $status, $past_due ) {
		$snapshot = self::durable_snapshot_get( self::AUX_SNAPSHOT_OPTION, self::AUX_SNAPSHOT_STATE_KEY, array() );
		if ( ! is_array( $snapshot ) || empty( $snapshot['generatedAt'] ) ) {
			self::schedule_near_refresh();
			return null;
		}

		$age = max( 0, time() - absint( $snapshot['generatedAt'] ) );
		if ( $age > 120 ) {
			self::schedule_near_refresh();
		}

		if ( $past_due && 'pending' === (string) $status ) {
			return isset( $snapshot['actionSchedulerPastDue'] ) && null !== $snapshot['actionSchedulerPastDue']
				? absint( $snapshot['actionSchedulerPastDue'] )
				: null;
		}

		if ( ! $past_due && 'failed' === (string) $status ) {
			return isset( $snapshot['actionSchedulerFailed'] ) && null !== $snapshot['actionSchedulerFailed']
				? absint( $snapshot['actionSchedulerFailed'] )
				: null;
		}

		return null;
	}

	private static function sync_key( $prefix, $limit ) {
		return $prefix . max( 1, absint( $limit ) );
	}

	private static function schedule_sync_refresh( $limit ) {
		$limit    = max( 1, absint( $limit ) );
		$now      = time();
		$existing = absint( Mobo_Core_Settings::get( self::SYNC_REFRESH_REQUESTED_AT_OPTION, 0 ) );
		if ( $existing <= 0 || ( $now - $existing ) >= 30 ) {
			Mobo_Core_Settings::update( self::SYNC_REFRESH_REQUESTED_AT_OPTION, $now, false );
		}
		if ( absint( Mobo_Core_Settings::get( self::SYNC_REFRESH_REQUESTED_LIMIT_OPTION, 0 ) ) !== $limit ) {
			Mobo_Core_Settings::update( self::SYNC_REFRESH_REQUESTED_LIMIT_OPTION, $limit, false );
		}
	}

	/**
	 * A last-good Sync Health snapshot must represent a completed operational
	 * classification. Transport/query/storage failures return an array too, but
	 * must never replace the previous known-good snapshot.
	 *
	 * @param mixed $result Candidate result.
	 * @return bool
	 */
	private static function sync_health_result_is_usable( $result ) {
		if ( ! is_array( $result ) ) {
			return false;
		}

		$status = sanitize_key( (string) ( isset( $result['operationalStatus'] ) ? $result['operationalStatus'] : '' ) );
		return '' !== $status && 'unavailable' !== $status;
	}

	/**
	 * Return a last-good operational Sync Health result. Ordinary page reads do
	 * not run the full Product Map scan once a snapshot exists.
	 */
	public static function get_sync_health_operational_snapshot( $limit ) {
		$limit = max( 1, absint( $limit ) );
		$key   = self::connection_cache_key( self::sync_key( self::SYNC_TRANSIENT_PREFIX, $limit ) );

		$wrapper = get_transient( $key );
		if ( ! is_array( $wrapper ) ) {
			$legacy_key = self::sync_key( self::SYNC_OPTION_PREFIX, $limit );
			$state_key  = self::SYNC_SNAPSHOT_STATE_PREFIX . $limit;
			$wrapper    = self::durable_snapshot_get( $legacy_key, $state_key, array() );
		}

		if ( ! is_array( $wrapper ) || ! isset( $wrapper['result'] ) || ! self::sync_health_result_is_usable( $wrapper['result'] ) ) {
			self::schedule_sync_refresh( $limit );
			return null;
		}

		$generated = isset( $wrapper['generatedAt'] ) ? absint( $wrapper['generatedAt'] ) : 0;
		$age       = $generated > 0 ? max( 0, time() - $generated ) : PHP_INT_MAX;

		if ( $age > 60 ) {
			self::schedule_sync_refresh( $limit );
		}

		return $wrapper['result'];
	}

	public static function store_sync_health_operational_snapshot( $limit, $result ) {
		if ( ! self::sync_health_result_is_usable( $result ) ) {
			return false;
		}

		$limit   = max( 1, absint( $limit ) );
		$wrapper = array(
			'schemaVersion' => 2,
			'generatedAt'   => time(),
			'limit'         => $limit,
			'result'        => $result,
		);

		set_transient( self::connection_cache_key( self::sync_key( self::SYNC_TRANSIENT_PREFIX, $limit ) ), $wrapper, 10 * MINUTE_IN_SECONDS );
		if ( ! self::durable_snapshot_set( self::sync_key( self::SYNC_OPTION_PREFIX, $limit ), self::SYNC_SNAPSHOT_STATE_PREFIX . $limit, $wrapper ) ) { return false; }
		Mobo_Core_Settings::update( 'mobo_core_sync_health_snapshot_last_success_at', time(), false );
		Mobo_Core_Settings::delete( 'mobo_core_sync_health_snapshot_last_error' );
		return true;
	}

	public static function refresh_sync_health_snapshot( $limit = 20 ) {
		if ( self::$sync_refreshing || ! class_exists( 'Mobo_Core_Sync_Health' ) ) {
			return false;
		}

		$limit = max( 1, absint( $limit ) );
		self::$sync_refreshing = true;

		try {
			$method = new ReflectionMethod( 'Mobo_Core_Sync_Health', 'get_operational_dashboard_status' );
			if ( method_exists( $method, 'setAccessible' ) ) {
				$method->setAccessible( true );
			}

			$target = null;
			if ( ! $method->isStatic() ) {
				$rc     = new ReflectionClass( 'Mobo_Core_Sync_Health' );
				$target = $rc->newInstanceWithoutConstructor();
			}

			$args = array();
			if ( $method->getNumberOfParameters() > 0 ) {
				$args[] = $limit;
			}
			/* Background/Admin snapshots must stay targeted. Passing only the limit
			 * leaves Sync Health's $deep_structure parameter at its forensic default
			 * (true), which hydrates the full WooCommerce catalog and can turn a
			 * dashboard/cache refresh into an hours-long operation on large stores. */
			if ( $method->getNumberOfParameters() > 1 ) {
				$args[] = false;
			}

			$result = $method->invokeArgs( $target, $args );
			if ( self::sync_health_result_is_usable( $result ) && self::store_sync_health_operational_snapshot( $limit, $result ) ) {
				Mobo_Core_Settings::update( self::SYNC_BACKGROUND_SUCCESS_OPTION, time(), false );
				Mobo_Core_Settings::delete( self::SYNC_REFRESH_REQUESTED_AT_OPTION );
				Mobo_Core_Settings::delete( self::SYNC_REFRESH_REQUESTED_LIMIT_OPTION );
				return true;
			}

			$reason = is_array( $result ) ? sanitize_key( (string) ( isset( $result['operationalReason'] ) ? $result['operationalReason'] : '' ) ) : '';
			$error  = '' !== $reason
				? 'Operational Sync Health refresh unavailable: ' . $reason
				: 'Operational Sync Health refresh did not return a usable result.';
			Mobo_Core_Settings::update( 'mobo_core_sync_health_snapshot_last_error', $error, false );
			return false;
		} catch ( Throwable $e ) {
			Mobo_Core_Settings::update( 'mobo_core_sync_health_snapshot_last_error', 'Sync Health refresh failed with an internal exception.', false );
			return false;
		} finally {
			self::$sync_refreshing = false;
		}
	}


	/**
	 * Refresh stale/requested snapshots from the deterministic real-cron runner.
	 *
	 * Health refresh can include an 8-second cPanel request, so it only starts when
	 * the caller still has at least 10 seconds of runner budget. Sync Health gets a
	 * smaller 4-second floor. A busy runner simply carries the durable request to
	 * the next invocation instead of falling back to WP-Cron.
	 *
	 * @param float $deadline Absolute microtime deadline, or 0 for no deadline.
	 * @return array
	 */
	public static function refresh_due_from_real_cron( $deadline = 0.0 ) {
		$deadline = (float) $deadline;
		$now      = time();
		$result   = array(
			'success' => true,
			'status'  => 'not-due',
			'health'  => 'not-due',
			'sync'    => 'not-due',
		);

		$health_requested = absint( Mobo_Core_Settings::get( self::HEALTH_REFRESH_REQUESTED_OPTION, 0 ) ) > 0;
		$health_last      = absint( Mobo_Core_Settings::get( self::HEALTH_BACKGROUND_SUCCESS_OPTION, 0 ) );
		$health_due       = $health_requested || $health_last <= 0 || ( $now - $health_last ) >= 120;

		if ( $health_due ) {
			if ( $deadline > 0 && microtime( true ) + 10.0 >= $deadline ) {
				$result['health'] = 'deferred-budget';
			} else {
				$result['health'] = self::refresh_now() ? 'refreshed' : 'failed';
				if ( 'failed' === $result['health'] ) {
					$result['success'] = false;
				}
			}
		}

		$sync_requested = absint( Mobo_Core_Settings::get( self::SYNC_REFRESH_REQUESTED_AT_OPTION, 0 ) ) > 0;
		$sync_last      = absint( Mobo_Core_Settings::get( self::SYNC_BACKGROUND_SUCCESS_OPTION, 0 ) );
		$sync_due       = $sync_requested || $sync_last <= 0 || ( $now - $sync_last ) >= 300;
		$sync_limit     = max( 1, absint( Mobo_Core_Settings::get( self::SYNC_REFRESH_REQUESTED_LIMIT_OPTION, 20 ) ) );

		if ( $sync_due ) {
			if ( $deadline > 0 && microtime( true ) + 4.0 >= $deadline ) {
				$result['sync'] = 'deferred-budget';
			} else {
				$result['sync'] = self::refresh_sync_health_snapshot( $sync_limit ) ? 'refreshed' : 'failed';
				if ( 'failed' === $result['sync'] ) {
					$result['success'] = false;
				}
			}
		}

		if ( 'not-due' !== $result['health'] || 'not-due' !== $result['sync'] ) {
			$result['status'] = $result['success'] ? 'processed' : 'partial';
		}
		$result['healthLastBackgroundSuccessAt'] = absint( Mobo_Core_Settings::get( self::HEALTH_BACKGROUND_SUCCESS_OPTION, 0 ) );
		$result['syncLastBackgroundSuccessAt']   = absint( Mobo_Core_Settings::get( self::SYNC_BACKGROUND_SUCCESS_OPTION, 0 ) );
		return $result;
	}

	private static function is_cpanel_health_request( $args ) {
		if ( ! is_array( $args ) || empty( $args['headers'] ) ) {
			return false;
		}

		$headers = $args['headers'];
		$auth    = '';

		if ( is_array( $headers ) ) {
			foreach ( $headers as $name => $value ) {
				if ( 0 === strcasecmp( (string) $name, 'Authorization' ) ) {
					$auth = is_array( $value ) ? implode( ',', $value ) : (string) $value;
					break;
				}
			}
		}

		if ( 0 !== stripos( trim( $auth ), 'cpanel ' ) ) {
			return false;
		}

		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 ) as $frame ) {
			if ( isset( $frame['class'] ) && 'Mobo_Core_Health_Reporter' === $frame['class'] ) {
				return true;
			}
		}

		return false;
	}

	public static function serve_cpanel_http_snapshot( $preempt, $args, $url ) {
		if ( self::$refreshing || ! self::is_cpanel_health_request( $args ) ) {
			return $preempt;
		}

		$snapshot = self::durable_snapshot_get( self::CPANEL_SNAPSHOT_OPTION, self::CPANEL_SNAPSHOT_STATE_KEY, array() );
		if ( is_array( $snapshot ) && ! empty( $snapshot['generatedAt'] ) && isset( $snapshot['response'] ) && is_array( $snapshot['response'] ) ) {
			$age = max( 0, time() - absint( $snapshot['generatedAt'] ) );
			if ( $age <= 900 ) {
				if ( $age > 300 ) {
					self::schedule_near_refresh();
				}
				return $snapshot['response'];
			}
		}

		self::schedule_near_refresh();
		return new WP_Error( 'mobo_core_health_cpanel_snapshot_pending', 'cPanel health data is refreshing in background.' );
	}

	public static function capture_cpanel_http_snapshot( $response, $args, $url ) {
		if ( ! self::$refreshing || ! self::is_cpanel_health_request( $args ) || ! is_array( $response ) ) {
			return $response;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );
		if ( $code >= 200 && $code < 300 ) {
			$retrieved_headers = wp_remote_retrieve_headers( $response );
			if ( is_object( $retrieved_headers ) && method_exists( $retrieved_headers, 'getAll' ) ) {
				$retrieved_headers = $retrieved_headers->getAll();
			}
			if ( ! is_array( $retrieved_headers ) ) {
				$retrieved_headers = array();
			}

			$safe_response = array(
				'headers'  => $retrieved_headers,
				'body'     => (string) wp_remote_retrieve_body( $response ),
				'response' => array(
					'code'    => $code,
					'message' => (string) wp_remote_retrieve_response_message( $response ),
				),
				'cookies'  => array(),
				'filename' => null,
			);

			$cpanel_snapshot = array(
				'schemaVersion' => 2,
				'generatedAt'   => time(),
				'urlHash'       => hash( 'sha256', (string) $url ),
				'response'      => $safe_response,
			);
			self::durable_snapshot_set( self::CPANEL_SNAPSHOT_OPTION, self::CPANEL_SNAPSHOT_STATE_KEY, $cpanel_snapshot );
		}

		return $response;
	}
}

Mobo_Core_Health_Read_Cache::boot();
