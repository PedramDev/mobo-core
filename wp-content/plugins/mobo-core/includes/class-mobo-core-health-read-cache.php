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

	const REFRESH_LOCK_OPTION     = 'mobo_core_health_snapshot_refresh_lock_v2';
	const CRON_HOOK               = 'mobo_core_health_snapshot_refresh_v2';
	const SYNC_CRON_HOOK          = 'mobo_core_sync_health_snapshot_refresh_v2';
	const CRON_SCHEDULE           = 'mobo_core_health_every_minute_v2';

	const SYNC_TRANSIENT_PREFIX   = 'mobo_core_sync_health_operational_snapshot_v2_';
	const SYNC_OPTION_PREFIX      = 'mobo_core_sync_health_operational_last_good_v2_';

	private static $booted          = false;
	private static $refreshing      = false;
	private static $sync_refreshing = false;

	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 20 );
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
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5, self::CRON_SCHEDULE, self::CRON_HOOK );
		}

		if ( ! wp_next_scheduled( self::SYNC_CRON_HOOK, array( 20 ) ) ) {
			wp_schedule_event( time() + 10, self::CRON_SCHEDULE, self::SYNC_CRON_HOOK, array( 20 ) );
		}
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
		$snapshot = get_transient( self::REST_SNAPSHOT_TRANSIENT );
		if ( is_array( $snapshot ) ) {
			return $snapshot;
		}

		$snapshot = get_option( self::REST_SNAPSHOT_OPTION, array() );
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

		set_transient( self::REST_SNAPSHOT_TRANSIENT, $snapshot, DAY_IN_SECONDS );
		update_option( self::REST_SNAPSHOT_OPTION, $snapshot, false );
		update_option( 'mobo_core_health_snapshot_last_success_at', time(), false );
		delete_option( 'mobo_core_health_snapshot_last_error' );
		return true;
	}

	public static function capture_health_snapshot( $response, $server, $request ) {
		if ( self::is_health_route( $request ) && $response instanceof WP_REST_Response ) {
			self::store_health_response( $response );
		}
		return $response;
	}

	private static function schedule_near_refresh() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 1, self::CRON_HOOK );
		}
	}

	private static function acquire_refresh_lock() {
		$now = time();
		if ( add_option( self::REFRESH_LOCK_OPTION, $now, '', false ) ) {
			return true;
		}

		$existing = absint( get_option( self::REFRESH_LOCK_OPTION, 0 ) );
		if ( $existing > 0 && ( $now - $existing ) > 180 ) {
			delete_option( self::REFRESH_LOCK_OPTION );
			return add_option( self::REFRESH_LOCK_OPTION, $now, '', false );
		}

		return false;
	}

	private static function release_refresh_lock() {
		delete_option( self::REFRESH_LOCK_OPTION );
	}

	public static function refresh_now() {
		if ( self::$refreshing || ! self::acquire_refresh_lock() ) {
			return false;
		}

		self::$refreshing = true;

		try {
			self::refresh_auxiliary_stats();

			$request = new WP_REST_Request( 'GET', '/mobo-core/v1/health' );
			$sec     = trim( (string) get_option( 'mobo_core_security_code', '' ) );

			if ( '' === $sec ) {
				update_option( 'mobo_core_health_snapshot_last_error', 'Health refresh cannot authenticate because X-SEC is empty.', false );
				return false;
			}

			$request->set_header( 'X-SEC', $sec );
			$request->set_header( 'X-Mobo-Webhook-Test', '1' );
			$request->set_header( 'Cache-Control', 'no-cache, no-store' );

			$response = rest_do_request( $request );

			if ( $response instanceof WP_REST_Response ) {
				$status = absint( $response->get_status() );
				if ( $status >= 200 && $status < 300 && self::store_health_response( $response ) ) {
					return true;
				}
				update_option( 'mobo_core_health_snapshot_last_error', 'Health refresh HTTP ' . $status, false );
				return false;
			}

			update_option( 'mobo_core_health_snapshot_last_error', 'Health refresh did not return WP_REST_Response.', false );
			return false;
		} catch ( Throwable $e ) {
			update_option( 'mobo_core_health_snapshot_last_error', sanitize_text_field( $e->getMessage() ), false );
			return false;
		} finally {
			self::$refreshing = false;
			self::release_refresh_lock();
		}
	}

	public static function refresh_auxiliary_stats() {
		$pending = 0;
		$failed  = 0;

		if ( defined( 'MOBO_CORE_WEBHOOK_FILE_DIR' ) ) {
			$pending_files = glob( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . '*.json' );
			$failed_files  = glob( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/*.json' );

			$pending = is_array( $pending_files ) ? count( $pending_files ) : 0;
			$failed  = is_array( $failed_files ) ? count( $failed_files ) : 0;
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

		update_option( self::AUX_SNAPSHOT_OPTION, $snapshot, false );
		return $snapshot;
	}

	public static function get_legacy_webhook_file_stats() {
		$snapshot = get_option( self::AUX_SNAPSHOT_OPTION, array() );
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
		$snapshot = get_option( self::AUX_SNAPSHOT_OPTION, array() );
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
		$limit = max( 1, absint( $limit ) );
		if ( ! wp_next_scheduled( self::SYNC_CRON_HOOK, array( $limit ) ) ) {
			wp_schedule_single_event( time() + 1, self::SYNC_CRON_HOOK, array( $limit ) );
		}
	}

	/**
	 * Return a last-good operational Sync Health result. Ordinary page reads do
	 * not run the full Product Map scan once a snapshot exists.
	 */
	public static function get_sync_health_operational_snapshot( $limit ) {
		$limit = max( 1, absint( $limit ) );
		$key   = self::sync_key( self::SYNC_TRANSIENT_PREFIX, $limit );

		$wrapper = get_transient( $key );
		if ( ! is_array( $wrapper ) ) {
			$wrapper = get_option( self::sync_key( self::SYNC_OPTION_PREFIX, $limit ), array() );
		}

		if ( ! is_array( $wrapper ) || ! isset( $wrapper['result'] ) || ! is_array( $wrapper['result'] ) ) {
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
		if ( ! is_array( $result ) ) {
			return false;
		}

		$limit   = max( 1, absint( $limit ) );
		$wrapper = array(
			'schemaVersion' => 2,
			'generatedAt'   => time(),
			'limit'         => $limit,
			'result'        => $result,
		);

		set_transient( self::sync_key( self::SYNC_TRANSIENT_PREFIX, $limit ), $wrapper, 10 * MINUTE_IN_SECONDS );
		update_option( self::sync_key( self::SYNC_OPTION_PREFIX, $limit ), $wrapper, false );
		update_option( 'mobo_core_sync_health_snapshot_last_success_at', time(), false );
		delete_option( 'mobo_core_sync_health_snapshot_last_error' );
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

			$result = $method->invokeArgs( $target, $args );
			if ( is_array( $result ) ) {
				self::store_sync_health_operational_snapshot( $limit, $result );
				return true;
			}

			update_option( 'mobo_core_sync_health_snapshot_last_error', 'Operational Sync Health refresh did not return an array.', false );
			return false;
		} catch ( Throwable $e ) {
			update_option( 'mobo_core_sync_health_snapshot_last_error', sanitize_text_field( $e->getMessage() ), false );
			return false;
		} finally {
			self::$sync_refreshing = false;
		}
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

		$snapshot = get_option( self::CPANEL_SNAPSHOT_OPTION, array() );
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

			update_option(
				self::CPANEL_SNAPSHOT_OPTION,
				array(
					'schemaVersion' => 2,
					'generatedAt'   => time(),
					'urlHash'       => hash( 'sha256', (string) $url ),
					'response'      => $safe_response,
				),
				false
			);
		}

		return $response;
	}
}

Mobo_Core_Health_Read_Cache::boot();
