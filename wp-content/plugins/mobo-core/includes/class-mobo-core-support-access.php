<?php
/**
 * Support access: passwordless one-time support login, Source account
 * diagnostics, sealed hosting-panel credentials and a readiness checklist.
 *
 * Contract (Backend "Portal"):
 * - POST /support/login-link     (X-SEC)  -> one-time login URL while access is granted.
 * - GET  /support/login?token=   (public) -> single-use, rate-limited login + redirect.
 * - POST /support/request-access (X-SEC)  -> pending request + admin notice + one email.
 * - POST {api_base_url}/api/support/hosting-access (plugin -> Backend, Token + X-SEC).
 *
 * Security notes:
 * - The support user can only be created/re-enabled from wp-admin (manage_options
 *   + nonce). No REST path calls grant().
 * - Only the SHA-256 hash of a login token is stored; the token is compared with
 *   hash_equals() and consumed with a compare-and-swap UPDATE (single use).
 * - The hosting panel password is sealed with RSA-OAEP for the Backend and is
 *   never stored, logged or returned.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'MOBO_CORE_SUPPORT_PUBLIC_KEY_ID' ) ) {
	define( 'MOBO_CORE_SUPPORT_PUBLIC_KEY_ID', 'sa1-31e692f99104d251' );
}

if ( ! defined( 'MOBO_CORE_SUPPORT_PUBLIC_KEY_PEM' ) ) {
	define(
		'MOBO_CORE_SUPPORT_PUBLIC_KEY_PEM',
		"-----BEGIN PUBLIC KEY-----\n" .
		"MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAqqien7n0o8JUrTtI0Xtg\n" .
		"Y6iqd7A3i8q8RCrJ+tTdKSSfB/PGT52pdUgt+Zlv7cncrymOPUYcW59JRoyy20I3\n" .
		"CKlEvhV7I/pKf/wcg2VRimIdxPE/OuZvL1zSTNpKET+K29vGLhzobbm8I2Ck0jmZ\n" .
		"W439FGlKXeI/r6Jep5SmAW9D/hyLVn26jYnpIhmmckCdWap2J5zGOdiIg9fKkVB3\n" .
		"0eEuLphkfw+Un6iw8G5SmZJ/pzNuSEkYN7wp9+5p4oI/aQe4c1ZCqSDp2N340pI6\n" .
		"3avvLjFch56yCxH9nXz7mAqNzLyGivrWmeWcOSIqaeO4nqjZbvj1IudqQ6WTPtQr\n" .
		"WF4A5zzWeVOBPUZbn7B4GcVcgu2OhcZyDp04fLCZqliRKQC74LBW6fCN94ntkCoy\n" .
		"ercAKuX8+2r2jq4wHAvc2GmCnDsoVPXQ6R/hDbQEEYOewL757k+Wp7yMrK1CRz9e\n" .
		"SlRWpOQG/AjTccbDqZjbcAlIRLM7hCWbwDzh1B97IZ1pAgMBAAE=\n" .
		"-----END PUBLIC KEY-----\n"
	);
}

final class Mobo_Core_Support_Access {

	/* ---------------------------------------------------------------------
	 * Storage keys.
	 * ------------------------------------------------------------------- */

	/** Support access state (small, autoloaded: read by the logged-in expiry guard). */
	const OPTION_STATE = 'mobo_core_support_access_state';
	/** Bounded index of live login-token option names. */
	const OPTION_TOKEN_INDEX = 'mobo_core_support_login_token_index';
	/** Per-token option prefix (value = JSON; name carries the token hash). */
	const TOKEN_OPTION_PREFIX = 'mobo_core_support_lt_';
	/** Support access log (last 100 events). */
	const OPTION_LOG = 'mobo_core_support_access_log';
	/** Pending support access requests from the Backend. */
	const OPTION_REQUESTS = 'mobo_core_support_access_requests';
	/** Last Source account check result. */
	const OPTION_SOURCE = 'mobo_core_support_source_account';
	/** Non-secret hosting access record. */
	const OPTION_HOSTING = 'mobo_core_support_hosting_access';
	/** Cached plugin-integrity result. */
	const OPTION_INTEGRITY = 'mobo_core_support_integrity_cache';
	/** User meta marking a plugin-created support user. */
	const USER_META_MARK = '_mobo_core_support_user';
	/** User meta: per-user dismissed Source-failure key. */
	const USER_META_SOURCE_DISMISSED = '_mobo_core_support_source_notice_dismissed';

	const PRIMARY_LOGIN  = 'MoboCore';
	const FALLBACK_LOGIN = 'mobocore-support';
	const DISPLAY_NAME   = 'پشتیبانی موبو';

	const DEFAULT_DAYS = 7;
	const MIN_DAYS     = 1;
	const MAX_DAYS     = 30;

	const TOKEN_TTL_DEFAULT = 300;
	const TOKEN_TTL_MIN     = 60;
	const TOKEN_TTL_MAX     = 600;
	const MAX_LIVE_TOKENS   = 20;

	const LOG_LIMIT          = 100;
	const MAX_REQUESTS       = 20;
	const RATE_LIMIT_MAX     = 10;
	const RATE_LIMIT_WINDOW  = 60;

	const SOURCE_CHECK_INTERVAL = 21600; // 6 hours.
	const SOURCE_LOCK_RETRY     = 1800;  // Lock contention: try again after 30 minutes.

	const INTEGRITY_CACHE_TTL = 21600;

	const MAX_PASSWORD_BYTES = 256;

	/** Statuses that indicate a customer-fixable Source account problem. */
	const SOURCE_BAD_STATUSES = array( 'invalid_credentials', 'locked', 'unreachable' );

	/* =====================================================================
	 * Support access state.
	 * =================================================================== */

	/**
	 * Raw stored state.
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Pure status resolution used by REST, health and the admin tab.
	 *
	 * @param array $state Stored state.
	 * @param int   $now   Unix time.
	 * @return string granted|not_granted|expired|revoked
	 */
	public static function resolve_status( $state, $now ) {
		if ( ! is_array( $state ) || empty( $state['status'] ) ) {
			return 'not_granted';
		}
		$status = (string) $state['status'];
		if ( 'granted' === $status ) {
			$expires = absint( isset( $state['expiresAt'] ) ? $state['expiresAt'] : 0 );
			return ( $expires > 0 && $expires > (int) $now ) ? 'granted' : 'expired';
		}
		return in_array( $status, array( 'expired', 'revoked' ), true ) ? $status : 'not_granted';
	}

	/**
	 * Current effective status.
	 *
	 * @param int|null $now Unix time.
	 * @return string
	 */
	public static function get_status( $now = null ) {
		return self::resolve_status( self::get_state(), null === $now ? time() : (int) $now );
	}

	/**
	 * Clamp configured access days.
	 *
	 * @param mixed $days Days.
	 * @return int
	 */
	public static function clamp_days( $days ) {
		$days = (int) $days;
		if ( $days <= 0 ) {
			$days = self::DEFAULT_DAYS;
		}
		return max( self::MIN_DAYS, min( self::MAX_DAYS, $days ) );
	}

	/**
	 * Clamp a login-link TTL.
	 *
	 * @param mixed $ttl Seconds.
	 * @return int
	 */
	public static function clamp_ttl( $ttl ) {
		$ttl = (int) $ttl;
		if ( $ttl <= 0 ) {
			$ttl = self::TOKEN_TTL_DEFAULT;
		}
		return max( self::TOKEN_TTL_MIN, min( self::TOKEN_TTL_MAX, $ttl ) );
	}

	/**
	 * ISO-8601 UTC timestamp or null.
	 *
	 * @param int $timestamp Unix time.
	 * @return string|null
	 */
	public static function iso( $timestamp ) {
		$timestamp = absint( $timestamp );
		return $timestamp > 0 ? gmdate( 'c', $timestamp ) : null;
	}

	/**
	 * Health "supportAccess" object.
	 *
	 * @param int|null $now Unix time.
	 * @return array
	 */
	public static function health_support_access( $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$state  = self::get_state();
		$status = self::resolve_status( $state, $now );
		return array(
			'status'         => $status,
			'user'           => isset( $state['login'] ) ? self::clean_login( (string) $state['login'] ) : '',
			'grantedAt'      => self::iso( isset( $state['grantedAt'] ) ? $state['grantedAt'] : 0 ),
			'expiresAt'      => self::iso( isset( $state['expiresAt'] ) ? $state['expiresAt'] : 0 ),
			'pendingRequest' => self::has_pending_request(),
		);
	}

	/**
	 * Create or re-enable the support administrator. Admin-only (button or notice).
	 *
	 * @param int    $days       Access duration in days (1-30).
	 * @param string $granted_by Admin login for the log.
	 * @return array|WP_Error State.
	 */
	public static function grant( $days, $granted_by = '' ) {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mobo_core_support_forbidden', 'اجازه این عملیات را ندارید.' );
		}

		$days = self::clamp_days( $days );
		$user = self::ensure_support_user();
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$now   = time();
		$state = array(
			'status'    => 'granted',
			'userId'    => (int) $user->ID,
			'login'     => (string) $user->user_login,
			'grantedAt' => $now,
			'expiresAt' => $now + ( $days * DAY_IN_SECONDS ),
			'days'      => $days,
			'grantedBy' => sanitize_text_field( (string) $granted_by ),
		);
		update_option( self::OPTION_STATE, $state, true );
		self::mark_requests_handled( 'granted' );
		self::log_event( 'grant', array( 'requestedBy' => $granted_by, 'detail' => $days . ' days' ) );
		return $state;
	}

	/**
	 * Revoke (or expire) support access: new random password, no role, no sessions.
	 * The user is never deleted (post authorship is preserved).
	 *
	 * @param string $reason revoked|expired.
	 * @param string $actor  Admin login or "system".
	 * @return bool
	 */
	public static function revoke( $reason = 'revoked', $actor = '' ) {
		$reason = 'expired' === $reason ? 'expired' : 'revoked';
		$state  = self::get_state();
		$user_id = absint( isset( $state['userId'] ) ? $state['userId'] : 0 );

		if ( $user_id > 0 && function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( $user && '1' === (string) get_user_meta( $user_id, self::USER_META_MARK, true ) ) {
				wp_set_password( self::random_password(), $user_id );
				$user = get_userdata( $user_id );
				if ( $user ) {
					$user->set_role( '' );
				}
				if ( class_exists( 'WP_Session_Tokens' ) ) {
					WP_Session_Tokens::get_instance( $user_id )->destroy_all();
				}
			}
		}

		self::purge_all_tokens();
		$state['status']    = $reason;
		$state['endedAt']   = time();
		$state['endedBy']   = sanitize_text_field( (string) $actor );
		update_option( self::OPTION_STATE, $state, true );
		self::log_event( 'expired' === $reason ? 'expire' : 'revoke', array( 'requestedBy' => $actor ) );
		return true;
	}

	/**
	 * Expire a granted access whose time has passed. Cheap when nothing to do.
	 *
	 * @return bool True when an expiry was applied.
	 */
	public static function maybe_expire() {
		$state = self::get_state();
		if ( isset( $state['status'] ) && 'granted' === $state['status'] && 'expired' === self::resolve_status( $state, time() ) ) {
			self::revoke( 'expired', 'system' );
			return true;
		}
		return false;
	}

	/**
	 * Find or create the dedicated support user.
	 *
	 * @return WP_User|WP_Error
	 */
	private static function ensure_support_user() {
		foreach ( array( self::PRIMARY_LOGIN, self::FALLBACK_LOGIN ) as $login ) {
			$existing = get_user_by( 'login', $login );
			if ( $existing instanceof WP_User ) {
				if ( '1' !== (string) get_user_meta( $existing->ID, self::USER_META_MARK, true ) ) {
					continue; // Belongs to someone else; never take it over.
				}
				wp_set_password( self::random_password(), $existing->ID );
				$user = get_userdata( $existing->ID );
				if ( ! $user ) {
					return new WP_Error( 'mobo_core_support_user_missing', 'کاربر پشتیبانی پیدا نشد.' );
				}
				$user->set_role( 'administrator' );
				wp_update_user( array( 'ID' => $user->ID, 'display_name' => self::DISPLAY_NAME ) );
				return get_userdata( $user->ID );
			}

			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => self::random_password(),
					'user_email'   => 'support-' . strtolower( wp_generate_password( 10, false, false ) ) . '@mobocore.invalid',
					'display_name' => self::DISPLAY_NAME,
					'nickname'     => self::DISPLAY_NAME,
					'first_name'   => self::DISPLAY_NAME,
					'role'         => 'administrator',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return new WP_Error( 'mobo_core_support_user_create_failed', 'ساخت کاربر پشتیبانی ناموفق بود: ' . $user_id->get_error_message() );
			}
			update_user_meta( $user_id, self::USER_META_MARK, '1' );
			update_user_meta( $user_id, '_mobo_core_support_created_at', time() );
			return get_userdata( $user_id );
		}

		return new WP_Error( 'mobo_core_support_login_taken', 'نام‌های کاربری MoboCore و mobocore-support هر دو متعلق به کاربران دیگر هستند؛ یکی از آن‌ها را تغییر دهید.' );
	}

	/**
	 * Random password that is never shown or sent.
	 *
	 * @return string
	 */
	private static function random_password() {
		return bin2hex( random_bytes( 24 ) ) . wp_generate_password( 16, true, true );
	}

	/**
	 * Validate that the stored support user is still a plugin-managed admin.
	 *
	 * @param array $state State.
	 * @return WP_User|null
	 */
	public static function resolve_support_user( $state ) {
		$user_id = absint( isset( $state['userId'] ) ? $state['userId'] : 0 );
		if ( $user_id <= 0 ) {
			return null;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || '1' !== (string) get_user_meta( $user_id, self::USER_META_MARK, true ) || ! user_can( $user, 'manage_options' ) ) {
			return null;
		}
		return $user;
	}

	/* =====================================================================
	 * One-time login tokens.
	 * =================================================================== */

	/**
	 * Token option name for a token hash.
	 *
	 * @param string $hash SHA-256 hex.
	 * @return string
	 */
	public static function token_option_name( $hash ) {
		return self::TOKEN_OPTION_PREFIX . substr( (string) $hash, 0, 64 );
	}

	/**
	 * Create a one-time login link (Backend-authenticated by X-SEC).
	 *
	 * @param string   $requested_by Admin email or name on the Backend.
	 * @param int      $ttl          Seconds (clamped 60-600).
	 * @param int|null $now          Unix time.
	 * @return array{success:bool,url?:string,expiresAt?:string,token?:string,code?:string,status?:string}
	 */
	public static function create_login_link( $requested_by, $ttl, $now = null ) {
		global $wpdb;
		$now          = null === $now ? time() : (int) $now;
		$requested_by = self::clean_requested_by( $requested_by );
		$status       = self::resolve_status( self::get_state(), $now );
		if ( 'granted' !== $status ) {
			self::log_event( 'link_refused', array( 'requestedBy' => $requested_by, 'detail' => $status ) );
			return array( 'success' => false, 'code' => 'support_access_not_granted', 'status' => $status );
		}

		$ttl     = self::clamp_ttl( $ttl );
		$token   = bin2hex( random_bytes( 32 ) );
		$hash    = hash( 'sha256', $token );
		$expires = $now + $ttl;
		$name    = self::token_option_name( $hash );
		$value   = self::encode_token_record( array( 'h' => $hash, 'exp' => $expires, 'by' => $requested_by, 'used' => 0, 'c' => $now ) );

		$inserted = $wpdb->insert(
			$wpdb->options,
			array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'no' ),
			array( '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
			return array( 'success' => false, 'code' => 'support_link_store_failed', 'status' => $status );
		}
		self::index_token( $name, $now );
		self::log_event( 'link_created', array( 'requestedBy' => $requested_by, 'detail' => 'ttl ' . $ttl ) );

		return array(
			'success'   => true,
			'url'       => self::login_url( $token ),
			'expiresAt' => gmdate( 'c', $expires ),
			'token'     => $token, // Internal (tests); stripped by the REST layer.
		);
	}

	/**
	 * Public login URL for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function login_url( $token ) {
		$base = function_exists( 'rest_url' ) ? rest_url( 'mobo-core/v1/support/login' ) : home_url( '/wp-json/mobo-core/v1/support/login' );
		return add_query_arg( 'token', rawurlencode( (string) $token ), $base );
	}

	/**
	 * Validate and consume a login token. No side effects besides consuming the
	 * token and logging; the REST layer sets the auth cookie on success.
	 *
	 * @param mixed    $token Raw token.
	 * @param string   $ip    Client IP (REMOTE_ADDR).
	 * @param int|null $now   Unix time.
	 * @return array{ok:bool,reason:string,user?:WP_User,requestedBy?:string}
	 */
	public static function consume_login_token( $token, $ip, $now = null ) {
		global $wpdb;
		$now = null === $now ? time() : (int) $now;
		$ip  = self::clean_ip( $ip );

		if ( self::is_rate_limited( $ip, $now ) ) {
			self::log_event( 'login_failure', array( 'ip' => $ip, 'detail' => 'rate_limited' ) );
			return array( 'ok' => false, 'reason' => 'rate_limited' );
		}

		$token = is_string( $token ) ? strtolower( trim( $token ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return self::login_failure( $ip, $now, 'malformed', '' );
		}

		$hash = hash( 'sha256', $token );
		$name = self::token_option_name( $hash );
		$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return self::login_failure( $ip, $now, 'unknown_token', '' );
		}
		$record = self::decode_token_record( $raw );
		$by     = isset( $record['by'] ) ? (string) $record['by'] : '';
		if ( empty( $record['h'] ) || ! hash_equals( (string) $record['h'], $hash ) ) {
			return self::login_failure( $ip, $now, 'unknown_token', $by );
		}
		if ( ! empty( $record['used'] ) ) {
			return self::login_failure( $ip, $now, 'already_used', $by );
		}
		if ( absint( isset( $record['exp'] ) ? $record['exp'] : 0 ) <= $now ) {
			return self::login_failure( $ip, $now, 'expired_token', $by );
		}

		$state = self::get_state();
		if ( 'granted' !== self::resolve_status( $state, $now ) ) {
			return self::login_failure( $ip, $now, 'access_not_granted', $by );
		}
		$user = self::resolve_support_user( $state );
		if ( ! $user ) {
			return self::login_failure( $ip, $now, 'support_user_invalid', $by );
		}

		/* Single use: only the request whose UPDATE flips used 0 -> time wins. */
		$record['used'] = $now;
		$record['ip']   = $ip;
		$swapped = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				self::encode_token_record( $record ),
				$name,
				$raw
			)
		);
		if ( 1 !== (int) $swapped ) {
			return self::login_failure( $ip, $now, 'already_used', $by );
		}

		self::log_event( 'login_success', array( 'requestedBy' => $by, 'ip' => $ip ) );
		return array( 'ok' => true, 'reason' => 'ok', 'user' => $user, 'requestedBy' => $by );
	}

	/**
	 * Record one failed attempt.
	 *
	 * @param string $ip     IP.
	 * @param int    $now    Time.
	 * @param string $reason Internal reason (logged, never shown).
	 * @param string $by     requestedBy when known.
	 * @return array
	 */
	private static function login_failure( $ip, $now, $reason, $by ) {
		self::register_rate_limit_failure( $ip, $now );
		self::log_event( 'login_failure', array( 'requestedBy' => $by, 'ip' => $ip, 'detail' => $reason ) );
		return array( 'ok' => false, 'reason' => $reason );
	}

	/**
	 * Encode a token record.
	 *
	 * @param array $record Record.
	 * @return string
	 */
	private static function encode_token_record( $record ) {
		return (string) wp_json_encode( $record );
	}

	/**
	 * Decode a token record.
	 *
	 * @param string $raw Raw.
	 * @return array
	 */
	private static function decode_token_record( $raw ) {
		$data = json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Track a token option and prune expired/excess ones.
	 *
	 * @param string $name Option name.
	 * @param int    $now  Time.
	 * @return void
	 */
	private static function index_token( $name, $now ) {
		global $wpdb;
		$index = get_option( self::OPTION_TOKEN_INDEX, array() );
		$index = is_array( $index ) ? $index : array();
		$index[ $name ] = $now + self::TOKEN_TTL_MAX + 3600; // Keep used records briefly for audit.

		foreach ( $index as $option => $keep_until ) {
			if ( absint( $keep_until ) < $now ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => (string) $option ), array( '%s' ) );
				unset( $index[ $option ] );
			}
		}
		while ( count( $index ) > self::MAX_LIVE_TOKENS ) {
			reset( $index );
			$oldest = (string) key( $index );
			$wpdb->delete( $wpdb->options, array( 'option_name' => $oldest ), array( '%s' ) );
			unset( $index[ $oldest ] );
		}
		update_option( self::OPTION_TOKEN_INDEX, $index, false );
	}

	/**
	 * Delete every login token (revoke/expire).
	 *
	 * @return void
	 */
	public static function purge_all_tokens() {
		global $wpdb;
		$index = get_option( self::OPTION_TOKEN_INDEX, array() );
		if ( is_array( $index ) && is_object( $wpdb ) ) {
			foreach ( array_keys( $index ) as $option ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => (string) $option ), array( '%s' ) );
			}
		}
		update_option( self::OPTION_TOKEN_INDEX, array(), false );
	}

	/* ---------------------------------------------------------------------
	 * Rate limiting (failures per IP per minute).
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $ip IP.
	 * @return string
	 */
	private static function rate_limit_key( $ip ) {
		return 'mobo_core_slrl_' . substr( hash( 'sha256', (string) $ip ), 0, 32 );
	}

	/**
	 * Whether this IP exhausted its failure budget for the current window.
	 *
	 * @param string $ip  IP.
	 * @param int    $now Time.
	 * @return bool
	 */
	public static function is_rate_limited( $ip, $now ) {
		$entry = get_transient( self::rate_limit_key( $ip ) );
		if ( ! is_array( $entry ) ) {
			return false;
		}
		$start = absint( isset( $entry['s'] ) ? $entry['s'] : 0 );
		if ( (int) $now - $start >= self::RATE_LIMIT_WINDOW ) {
			return false;
		}
		return absint( isset( $entry['n'] ) ? $entry['n'] : 0 ) >= self::RATE_LIMIT_MAX;
	}

	/**
	 * Count one failure.
	 *
	 * @param string $ip  IP.
	 * @param int    $now Time.
	 * @return void
	 */
	private static function register_rate_limit_failure( $ip, $now ) {
		$key   = self::rate_limit_key( $ip );
		$entry = get_transient( $key );
		if ( ! is_array( $entry ) || (int) $now - absint( isset( $entry['s'] ) ? $entry['s'] : 0 ) >= self::RATE_LIMIT_WINDOW ) {
			$entry = array( 's' => (int) $now, 'n' => 0 );
		}
		$entry['n'] = absint( $entry['n'] ) + 1;
		set_transient( $key, $entry, self::RATE_LIMIT_WINDOW * 2 );
	}

	/* =====================================================================
	 * Support access log.
	 * =================================================================== */

	/**
	 * Append a bounded log event. Never pass secrets here.
	 *
	 * @param string $event Event key.
	 * @param array  $data  requestedBy, ip, detail.
	 * @return void
	 */
	public static function log_event( $event, $data = array() ) {
		$log = get_option( self::OPTION_LOG, array() );
		$log = is_array( $log ) ? $log : array();
		$log[] = array(
			'at'          => time(),
			'event'       => sanitize_key( (string) $event ),
			'requestedBy' => self::clean_requested_by( isset( $data['requestedBy'] ) ? $data['requestedBy'] : '' ),
			'ip'          => self::clean_ip( isset( $data['ip'] ) ? $data['ip'] : '' ),
			'detail'      => substr( sanitize_text_field( (string) ( isset( $data['detail'] ) ? $data['detail'] : '' ) ), 0, 120 ),
		);
		if ( count( $log ) > self::LOG_LIMIT ) {
			$log = array_slice( $log, -self::LOG_LIMIT );
		}
		update_option( self::OPTION_LOG, $log, false );
	}

	/**
	 * Log entries, newest first.
	 *
	 * @return array
	 */
	public static function get_log() {
		$log = get_option( self::OPTION_LOG, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	/**
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function clean_requested_by( $value ) {
		return substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, 190 );
	}

	/**
	 * Login sanitizer usable with or without WordPress loaded.
	 *
	 * @param string $login Login.
	 * @return string
	 */
	public static function clean_login( $login ) {
		return function_exists( 'sanitize_user' ) ? sanitize_user( (string) $login, true ) : (string) preg_replace( '/[^A-Za-z0-9_.\-@]/', '', (string) $login );
	}

	/**
	 * @param mixed $ip IP.
	 * @return string
	 */
	public static function clean_ip( $ip ) {
		$ip = is_string( $ip ) ? trim( $ip ) : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/* =====================================================================
	 * Support access requests from the Backend.
	 * =================================================================== */

	/**
	 * Store a Backend request, notify admin_email once.
	 *
	 * @param string $message      Message.
	 * @param string $requested_by Requester.
	 * @return array
	 */
	public static function record_access_request( $message, $requested_by ) {
		$message      = substr( sanitize_textarea_field( is_scalar( $message ) ? (string) $message : '' ), 0, 1000 );
		$requested_by = self::clean_requested_by( $requested_by );
		$requests     = self::get_requests();
		$request      = array(
			'id'          => bin2hex( random_bytes( 8 ) ),
			'at'          => time(),
			'message'     => $message,
			'requestedBy' => $requested_by,
			'status'      => 'pending',
		);
		$requests[] = $request;
		if ( count( $requests ) > self::MAX_REQUESTS ) {
			$requests = array_slice( $requests, -self::MAX_REQUESTS );
		}
		update_option( self::OPTION_REQUESTS, $requests, false );
		self::log_event( 'request_received', array( 'requestedBy' => $requested_by ) );

		$admin_email = (string) get_option( 'admin_email', '' );
		if ( function_exists( 'wp_mail' ) && function_exists( 'is_email' ) && is_email( $admin_email ) ) {
			$site    = wp_specialchars_decode( (string) get_option( 'blogname', '' ), ENT_QUOTES );
			$subject = sprintf( '[%s] درخواست دسترسی پشتیبانی موبو', $site );
			$lines   = array(
				'پشتیبانی موبو برای بررسی مشکل سایت شما درخواست دسترسی کرده است.',
				'',
				'' !== $message ? 'پیام پشتیبانی: ' . $message : '',
				'' !== $requested_by ? 'درخواست‌دهنده: ' . $requested_by : '',
				'',
				'برای اجازه دسترسی وارد پیشخوان وردپرس شوید و روی دکمه «اجازه دسترسی» بزنید:',
				self::support_tab_url(),
				'',
				'هیچ رمز عبوری رد و بدل نمی‌شود و دسترسی پس از مدت تعیین‌شده خودکار لغو می‌شود.',
			);
			wp_mail( $admin_email, $subject, implode( "\n", array_values( array_filter( $lines, 'is_string' ) ) ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
		}

		return $request;
	}

	/**
	 * @return array
	 */
	public static function get_requests() {
		$requests = get_option( self::OPTION_REQUESTS, array() );
		return is_array( $requests ) ? array_values( $requests ) : array();
	}

	/**
	 * Pending requests (newest last).
	 *
	 * @return array
	 */
	public static function get_pending_requests() {
		return array_values(
			array_filter(
				self::get_requests(),
				static function ( $request ) {
					return is_array( $request ) && isset( $request['status'] ) && 'pending' === $request['status'];
				}
			)
		);
	}

	/**
	 * @return bool
	 */
	public static function has_pending_request() {
		return array() !== self::get_pending_requests();
	}

	/**
	 * Close every pending request.
	 *
	 * @param string $status granted|declined.
	 * @return void
	 */
	public static function mark_requests_handled( $status ) {
		$requests = self::get_requests();
		$changed  = false;
		foreach ( $requests as $index => $request ) {
			if ( is_array( $request ) && isset( $request['status'] ) && 'pending' === $request['status'] ) {
				$requests[ $index ]['status']    = sanitize_key( (string) $status );
				$requests[ $index ]['handledAt'] = time();
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION_REQUESTS, $requests, false );
		}
	}

	/**
	 * Admin URL of the Support tab.
	 *
	 * @param array $args Extra args.
	 * @return string
	 */
	public static function support_tab_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'mobo-core', 'tab' => 'support' ), is_array( $args ) ? $args : array() ), admin_url( 'admin.php' ) );
	}

	/* =====================================================================
	 * Source account check.
	 * =================================================================== */

	/**
	 * Persian message per Source status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function source_status_message( $status ) {
		$messages = array(
			'ok'                  => 'ورود به حساب منبع با موفقیت انجام شد.',
			'invalid_credentials' => 'نام کاربری یا رمز عبور حساب منبع (موبو) اشتباه است؛ آن را در تنظیمات سفارش موبو اصلاح کنید.',
			'locked'              => 'حساب منبع (موبو) قفل یا محدود شده است؛ ابتدا حساب را در سایت منبع باز کنید.',
			'unreachable'         => 'سایت منبع در دسترس نبود؛ اتصال اینترنت/فایروال هاست را بررسی کنید.',
			'not_configured'      => 'نام کاربری و رمز عبور حساب منبع در تنظیمات سفارش موبو وارد نشده است.',
			'unknown'             => 'وضعیت حساب منبع هنوز مشخص نیست.',
		);
		return isset( $messages[ $status ] ) ? $messages[ $status ] : $messages['unknown'];
	}

	/**
	 * Pure classification of the existing login-test result.
	 *
	 * @param true|WP_Error $result test_mobo_login() result.
	 * @return string ok|invalid_credentials|locked|unreachable|not_configured|unknown|skip
	 */
	public static function classify_source_result( $result ) {
		if ( true === $result ) {
			return 'ok';
		}
		if ( ! is_wp_error( $result ) ) {
			return 'unknown';
		}

		$code = sanitize_key( (string) $result->get_error_code() );
		$data = $result->get_error_data();
		$data = is_array( $data ) ? $data : array();
		$http = absint( isset( $data['status'] ) ? $data['status'] : 0 );
		$fail = sanitize_key( (string) ( isset( $data['failure_class'] ) ? $data['failure_class'] : '' ) );

		if ( in_array( $code, array( 'mobo_core_mobo_credentials_missing', 'mobo_core_source_checkout_unavailable', 'mobo_core_provider_unavailable', 'mobo_core_provider_auth_unsupported' ), true ) ) {
			return 'not_configured';
		}
		/* Local lock/barrier contention is not a Source verdict: keep the last result. */
		if ( false !== strpos( $code, 'shared_cart' ) || false !== strpos( $code, '_lock' ) ) {
			return 'skip';
		}
		if ( in_array( $http, array( 423, 429 ), true ) || 403 === $http || in_array( $fail, array( 'access_denied', 'rate_limited' ), true ) ) {
			return 'locked';
		}
		if ( 401 === $http || 'authentication_failed' === $fail || 'mobo_core_mobo_login_missing_cookie' === $code ) {
			return 'invalid_credentials';
		}
		if ( 'request_rejected' === $fail && in_array( $http, array( 400, 422 ), true ) ) {
			return 'invalid_credentials';
		}
		if ( in_array( $fail, array( 'timeout', 'dns_failure', 'tls_failure', 'connect_failure', 'transport_failure', 'upstream_failure' ), true )
			|| in_array( $code, array( 'mobo_core_mobo_login_transport_error', 'mobo_core_dependency_backoff' ), true ) ) {
			return 'unreachable';
		}
		return 'unknown';
	}

	/**
	 * Wallet-low flag from the existing wallet monitor (no network).
	 *
	 * @param array $wallet Mobo_Core_Wallet_Alert::get_status().
	 * @return bool|null
	 */
	public static function wallet_low_from_status( $wallet ) {
		if ( ! is_array( $wallet ) || empty( $wallet['supported'] ) || empty( $wallet['hasBalance'] ) ) {
			return null;
		}
		$threshold = isset( $wallet['threshold'] ) ? (float) $wallet['threshold'] : 0.0;
		if ( $threshold <= 0 || ! isset( $wallet['lastBalance'] ) || ! is_numeric( $wallet['lastBalance'] ) ) {
			return null;
		}
		return (float) $wallet['lastBalance'] <= $threshold;
	}

	/**
	 * Stored Source result.
	 *
	 * @return array
	 */
	public static function get_source_state() {
		$state = get_option( self::OPTION_SOURCE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Health "sourceAccount" object.
	 *
	 * @return array
	 */
	public static function health_source_account() {
		$state  = self::get_source_state();
		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'unknown';
		if ( ! in_array( $status, array( 'ok', 'invalid_credentials', 'locked', 'unreachable', 'not_configured', 'unknown' ), true ) ) {
			$status = 'unknown';
		}
		return array(
			'status'    => $status,
			'checkedAt' => self::iso( isset( $state['checkedAt'] ) ? $state['checkedAt'] : 0 ),
			'message'   => self::source_status_message( $status ),
			'walletLow' => array_key_exists( 'walletLow', $state ) && null !== $state['walletLow'] ? (bool) $state['walletLow'] : null,
		);
	}

	/**
	 * Default (installation controller) Connection id; 0 when unavailable.
	 *
	 * @return int
	 */
	public static function default_connection_id() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::table_exists() ) {
			return 0;
		}
		$row = Mobo_Core_Connection_Repository::get_default_fresh();
		return is_array( $row ) && ! empty( $row['is_enabled'] ) ? absint( isset( $row['id'] ) ? $row['id'] : 0 ) : 0;
	}

	/**
	 * Run a callback under the Default Connection context when one exists.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	private static function in_default_context( $callback ) {
		$id = self::default_connection_id();
		if ( $id > 0 && class_exists( 'Mobo_Core_Connection_Context' ) ) {
			return Mobo_Core_Connection_Context::run( $id, $callback );
		}
		return call_user_func( $callback );
	}

	/**
	 * Keyed fingerprint of the Source credentials (no plain hash of the password).
	 *
	 * @return string Empty when not configured.
	 */
	private static function credential_fingerprint() {
		$username = trim( (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_username', '' ) );
		$password = (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_password', '' );
		if ( '' === $username || '' === $password ) {
			return '';
		}
		$key = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'mobo-core';
		return hash_hmac( 'sha256', $username . "\0" . $password, $key );
	}

	/**
	 * Single login attempt with the EXISTING Source credentials and login code
	 * (Mobo_Core_Checkout_Validator::test_mobo_login()). Never retried here.
	 *
	 * @param string $trigger manual|settings|cron.
	 * @return array Stored state.
	 */
	public static function run_source_check( $trigger = 'manual' ) {
		return self::in_default_context(
			static function () use ( $trigger ) {
				$previous    = self::get_source_state();
				$fingerprint = self::credential_fingerprint();
				$now         = time();

				if ( '' === $fingerprint ) {
					$status = 'not_configured';
					$code   = 'mobo_core_mobo_credentials_missing';
				} elseif ( ! class_exists( 'Mobo_Core_Checkout_Validator' ) ) {
					$status = 'unknown';
					$code   = 'checkout_validator_missing';
				} else {
					try {
						$validator = new Mobo_Core_Checkout_Validator();
						$result    = $validator->test_mobo_login();
					} catch ( Throwable $error ) {
						$result = new WP_Error( 'mobo_core_support_source_exception', 'Source check failed.' );
					}
					$status = self::classify_source_result( $result );
					$code   = is_wp_error( $result ) ? sanitize_key( (string) $result->get_error_code() ) : '';
				}

				if ( 'skip' === $status ) {
					$previous['nextDueAt'] = $now + self::SOURCE_LOCK_RETRY;
					update_option( self::OPTION_SOURCE, $previous, false );
					return $previous;
				}

				$wallet_low = null;
				if ( 'ok' === $status && class_exists( 'Mobo_Core_Wallet_Alert' ) ) {
					try {
						$wallet_low = self::wallet_low_from_status( Mobo_Core_Wallet_Alert::get_status() );
					} catch ( Throwable $error ) {
						$wallet_low = null;
					}
				}

				$state = array(
					'status'           => $status,
					'checkedAt'        => $now,
					'nextDueAt'        => $now + self::SOURCE_CHECK_INTERVAL,
					'errorCode'        => substr( $code, 0, 64 ),
					'trigger'          => sanitize_key( (string) $trigger ),
					'walletLow'        => $wallet_low,
					'fingerprint'      => $fingerprint,
					'notifiedFailure'  => isset( $previous['notifiedFailure'] ) ? (string) $previous['notifiedFailure'] : '',
				);
				$state = self::maybe_notify_source_failure( $state );
				update_option( self::OPTION_SOURCE, $state, false );
				return $state;
			}
		);
	}

	/**
	 * Whether an automatic check is due. Never re-tries known-bad credentials
	 * (avoids locking the customer's Source account) until they change.
	 *
	 * @param array  $state       Stored state.
	 * @param string $fingerprint Current credential fingerprint.
	 * @param int    $now         Time.
	 * @return bool
	 */
	public static function scheduled_check_due( $state, $fingerprint, $now ) {
		$state = is_array( $state ) ? $state : array();
		$changed = (string) ( isset( $state['fingerprint'] ) ? $state['fingerprint'] : '' ) !== (string) $fingerprint;
		if ( $changed ) {
			return true;
		}
		$status = isset( $state['status'] ) ? (string) $state['status'] : '';
		if ( in_array( $status, array( 'invalid_credentials', 'locked' ), true ) ) {
			return false;
		}
		if ( '' === (string) $fingerprint && 'not_configured' === $status ) {
			return false;
		}
		return absint( isset( $state['nextDueAt'] ) ? $state['nextDueAt'] : 0 ) <= (int) $now;
	}

	/**
	 * Settings-save hook: check only when the Source credentials changed.
	 *
	 * @return array|null
	 */
	public static function check_after_settings_save() {
		$current = class_exists( 'Mobo_Core_Connection_Context' ) ? Mobo_Core_Connection_Context::get_effective_connection_id() : 0;
		$default = self::default_connection_id();
		if ( $default > 0 && $current !== $default ) {
			return null;
		}
		$state = self::get_source_state();
		if ( (string) ( isset( $state['fingerprint'] ) ? $state['fingerprint'] : '' ) === self::credential_fingerprint() && ! empty( $state['checkedAt'] ) ) {
			return null;
		}
		return self::run_source_check( 'settings' );
	}

	/**
	 * Cron stage: expiry + at most one Source check per 6 hours (Default only).
	 *
	 * @param string $source Cron source.
	 * @return array
	 */
	public static function cron_tick( $source = 'real-cron' ) {
		$expired = self::maybe_expire();
		$default = self::default_connection_id();
		$current = class_exists( 'Mobo_Core_Connection_Context' ) ? Mobo_Core_Connection_Context::get_current_connection_id() : 0;
		if ( $default > 0 && $current > 0 && $current !== $default ) {
			return array( 'success' => true, 'status' => 'skipped', 'expired' => $expired );
		}
		$state = self::get_source_state();
		$due   = self::in_default_context(
			static function () use ( $state ) {
				return self::scheduled_check_due( $state, self::credential_fingerprint(), time() );
			}
		);
		if ( ! $due ) {
			return array( 'success' => true, 'status' => 'not-due', 'expired' => $expired );
		}
		$result = self::run_source_check( 'cron' );
		return array( 'success' => true, 'status' => 'checked', 'sourceStatus' => isset( $result['status'] ) ? (string) $result['status'] : 'unknown', 'expired' => $expired, 'source' => sanitize_key( (string) $source ) );
	}

	/**
	 * Email admin_email once per distinct failure.
	 *
	 * @param array $state New state.
	 * @return array
	 */
	private static function maybe_notify_source_failure( $state ) {
		$status = (string) $state['status'];
		if ( ! in_array( $status, self::SOURCE_BAD_STATUSES, true ) ) {
			if ( 'ok' === $status ) {
				$state['notifiedFailure'] = '';
			}
			return $state;
		}
		$key = $status;
		if ( $key === (string) $state['notifiedFailure'] ) {
			return $state;
		}
		$admin_email = (string) get_option( 'admin_email', '' );
		if ( function_exists( 'wp_mail' ) && function_exists( 'is_email' ) && is_email( $admin_email ) ) {
			$site    = wp_specialchars_decode( (string) get_option( 'blogname', '' ), ENT_QUOTES );
			$subject = sprintf( '[%s] مشکل در اتصال به حساب منبع موبو', $site );
			$body    = implode(
				"\n",
				array(
					'افزونه موبو نتوانست با حساب منبع شما وارد شود.',
					'',
					self::source_status_message( $status ),
					'',
					'اصلاح: ' . self::source_settings_url(),
					'',
					'این ایمیل فقط یک بار برای همین مشکل ارسال می‌شود.',
				)
			);
			wp_mail( $admin_email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		}
		$state['notifiedFailure'] = $key;
		return $state;
	}

	/**
	 * Where the Source credentials are edited.
	 *
	 * @return string
	 */
	public static function source_settings_url() {
		return add_query_arg( array( 'page' => 'mobo-core', 'tab' => 'checkout' ), admin_url( 'admin.php' ) );
	}

	/* =====================================================================
	 * Hosting access (sealed for the Backend).
	 * =================================================================== */

	/**
	 * @return array<string,string>
	 */
	public static function panel_types() {
		return array(
			'cpanel'      => 'cPanel',
			'directadmin' => 'DirectAdmin',
			'plesk'       => 'Plesk',
			'other'       => 'سایر',
		);
	}

	/**
	 * Effective support public key (filterable for staging).
	 *
	 * @return array{keyId:string,pem:string}
	 */
	public static function public_key() {
		$key = apply_filters(
			'mobo_core_support_public_key',
			array( 'keyId' => MOBO_CORE_SUPPORT_PUBLIC_KEY_ID, 'pem' => MOBO_CORE_SUPPORT_PUBLIC_KEY_PEM )
		);
		if ( ! is_array( $key ) || empty( $key['keyId'] ) || empty( $key['pem'] ) ) {
			return array( 'keyId' => MOBO_CORE_SUPPORT_PUBLIC_KEY_ID, 'pem' => MOBO_CORE_SUPPORT_PUBLIC_KEY_PEM );
		}
		return array( 'keyId' => (string) $key['keyId'], 'pem' => (string) $key['pem'] );
	}

	/**
	 * Seal a password with RSA-OAEP (SHA-1 MGF, OPENSSL_PKCS1_OAEP_PADDING).
	 *
	 * @param string $password Plain password.
	 * @param string $pem      Public key PEM.
	 * @return string|WP_Error base64 ciphertext.
	 */
	public static function seal_password( $password, $pem ) {
		if ( ! function_exists( 'openssl_public_encrypt' ) || ! function_exists( 'openssl_pkey_get_public' ) ) {
			return new WP_Error( 'mobo_core_support_openssl_missing', 'افزونه OpenSSL روی PHP هاست فعال نیست؛ رمز عبور ارسال نشد. از هاستینگ بخواهید ext-openssl را فعال کند یا اطلاعات را از راه امن دیگری به پشتیبانی بدهید.' );
		}
		$password = (string) $password;
		if ( '' === $password || strlen( $password ) > self::MAX_PASSWORD_BYTES ) {
			return new WP_Error( 'mobo_core_support_password_invalid', 'طول رمز عبور پنل معتبر نیست.' );
		}
		$key = openssl_pkey_get_public( (string) $pem );
		if ( false === $key ) {
			return new WP_Error( 'mobo_core_support_key_invalid', 'کلید عمومی پشتیبانی معتبر نیست؛ رمز عبور ارسال نشد.' );
		}
		$sealed = '';
		$ok     = openssl_public_encrypt( $password, $sealed, $key, OPENSSL_PKCS1_OAEP_PADDING );
		if ( ! $ok || '' === $sealed ) {
			return new WP_Error( 'mobo_core_support_seal_failed', 'رمزنگاری رمز عبور ناموفق بود؛ رمز عبور ارسال نشد.' );
		}
		return base64_encode( $sealed );
	}

	/**
	 * Validate the hosting form (pure).
	 *
	 * @param array $input panelType, panelUrl, username, password.
	 * @return array|WP_Error Normalized fields (password kept only in memory).
	 */
	public static function validate_hosting_input( $input ) {
		$input = is_array( $input ) ? $input : array();
		$type  = sanitize_key( (string) ( isset( $input['panelType'] ) ? $input['panelType'] : '' ) );
		if ( ! array_key_exists( $type, self::panel_types() ) ) {
			return new WP_Error( 'mobo_core_support_panel_type', 'نوع پنل هاست را انتخاب کنید.' );
		}
		$url    = trim( (string) ( isset( $input['panelUrl'] ) ? $input['panelUrl'] : '' ) );
		$parts  = wp_parse_url( $url );
		$scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = is_array( $parts ) && isset( $parts['host'] ) ? (string) $parts['host'] : '';
		if ( '' === $url || strlen( $url ) > 500 || ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host
			|| ! preg_match( '/^[a-z0-9.\-\[\]:]+$/i', $host ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'mobo_core_support_panel_url', 'آدرس پنل هاست معتبر نیست؛ نمونه درست: https://example.com:2083' );
		}
		$username = trim( sanitize_text_field( (string) ( isset( $input['username'] ) ? $input['username'] : '' ) ) );
		if ( '' === $username || strlen( $username ) > 190 ) {
			return new WP_Error( 'mobo_core_support_panel_username', 'نام کاربری پنل هاست را وارد کنید.' );
		}
		$password = (string) ( isset( $input['password'] ) ? $input['password'] : '' );
		if ( '' === $password || strlen( $password ) > self::MAX_PASSWORD_BYTES ) {
			return new WP_Error( 'mobo_core_support_panel_password', 'رمز عبور پنل هاست را وارد کنید (حداکثر ۲۵۶ بایت).' );
		}
		return array( 'panelType' => $type, 'panelUrl' => esc_url_raw( $url, array( 'http', 'https' ) ), 'username' => $username, 'password' => $password );
	}

	/**
	 * Build the Backend body (pure apart from sealing).
	 *
	 * @param array $fields Validated fields.
	 * @return array|WP_Error
	 */
	public static function build_hosting_payload( $fields ) {
		$key    = self::public_key();
		$sealed = self::seal_password( isset( $fields['password'] ) ? $fields['password'] : '', $key['pem'] );
		if ( is_wp_error( $sealed ) ) {
			return $sealed;
		}
		return array(
			'panelType'      => (string) $fields['panelType'],
			'panelUrl'       => (string) $fields['panelUrl'],
			'username'       => (string) $fields['username'],
			'keyId'          => $key['keyId'],
			'sealedPassword' => $sealed,
		);
	}

	/**
	 * Optional panel reachability probe (single request, no login attempt).
	 *
	 * @param string $url Panel URL.
	 * @return bool
	 */
	public static function probe_panel_url( $url ) {
		$response = wp_safe_remote_head(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 2,
				'sslverify'   => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'support_panel_probe' ),
				'user-agent'  => 'MoboCore-SupportProbe/' . ( defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '1.0' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = absint( wp_remote_retrieve_response_code( $response ) );
		return $code > 0 && $code < 500;
	}

	/**
	 * Submit hosting access to the Backend. The password is never stored or logged.
	 *
	 * @param array $input Raw form input.
	 * @param bool  $probe Run the reachability probe first.
	 * @return array|WP_Error Stored non-secret record.
	 */
	public static function submit_hosting_access( $input, $probe = false ) {
		$fields = self::validate_hosting_input( $input );
		unset( $input );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$reachable = $probe ? self::probe_panel_url( $fields['panelUrl'] ) : null;
		$payload   = self::build_hosting_payload( $fields );
		$fields['password'] = '';
		unset( $fields['password'] );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$response = self::in_default_context(
			static function () use ( $payload ) {
				if ( ! class_exists( 'Mobo_Core_API_Client' ) ) {
					return new WP_Error( 'mobo_core_support_api_missing', 'API client is unavailable.' );
				}
				$client = new Mobo_Core_API_Client();
				return $client->submit_support_hosting_access( $payload );
			}
		);
		$payload = null;

		$record = array(
			'panelType'     => $fields['panelType'],
			'panelUrl'      => $fields['panelUrl'],
			'username'      => $fields['username'],
			'panelReachable'=> $reachable,
			'attemptedAt'   => time(),
		);
		$previous = self::get_hosting_state();
		if ( is_wp_error( $response ) || ! is_array( $response ) || empty( $response['success'] ) ) {
			$record['status']      = 'send_failed';
			$record['submittedAt'] = isset( $previous['submittedAt'] ) ? absint( $previous['submittedAt'] ) : 0;
			$record['lastError']   = is_wp_error( $response ) ? sanitize_key( (string) $response->get_error_code() ) : 'unexpected_response';
			update_option( self::OPTION_HOSTING, $record, false );
			self::log_event( 'hosting_send_failed', array( 'detail' => $record['lastError'] ) );
			return new WP_Error( 'mobo_core_support_hosting_send_failed', 'ارسال اطلاعات هاست به پشتیبانی موبو ناموفق بود؛ اتصال افزونه به سرویس مرکزی را بررسی و دوباره تلاش کنید.' );
		}

		$record['status']      = 'provided';
		$record['submittedAt'] = time();
		$record['receivedAt']  = isset( $response['receivedAt'] ) ? substr( sanitize_text_field( (string) $response['receivedAt'] ), 0, 40 ) : '';
		$record['lastError']   = '';
		update_option( self::OPTION_HOSTING, $record, false );
		self::log_event( 'hosting_submitted', array( 'detail' => $record['panelType'] ) );
		return $record;
	}

	/**
	 * @return array
	 */
	public static function get_hosting_state() {
		$state = get_option( self::OPTION_HOSTING, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Health "hostingAccess" object (never includes username/password).
	 *
	 * @return array
	 */
	public static function health_hosting_access() {
		$state  = self::get_hosting_state();
		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'not_provided';
		if ( ! in_array( $status, array( 'provided', 'not_provided', 'send_failed' ), true ) ) {
			$status = 'not_provided';
		}
		$type = isset( $state['panelType'] ) ? sanitize_key( (string) $state['panelType'] ) : '';
		$url  = isset( $state['panelUrl'] ) ? esc_url_raw( (string) $state['panelUrl'], array( 'http', 'https' ) ) : '';
		return array(
			'status'      => $status,
			'panelType'   => array_key_exists( $type, self::panel_types() ) ? $type : null,
			'panelUrl'    => '' !== $url ? $url : null,
			'submittedAt' => self::iso( isset( $state['submittedAt'] ) ? $state['submittedAt'] : 0 ),
		);
	}

	/* =====================================================================
	 * Readiness checklist.
	 * =================================================================== */

	/**
	 * Pure readiness computation.
	 *
	 * @param array $in Inputs (see collect_readiness_inputs()).
	 * @return array<int,array{key:string,ok:bool,message:string}>
	 */
	public static function compute_readiness( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$now = absint( isset( $in['now'] ) ? $in['now'] : time() );
		$out = array();

		$support = isset( $in['supportStatus'] ) ? (string) $in['supportStatus'] : 'not_granted';
		$out[] = array(
			'key'     => 'support_access',
			'ok'      => 'granted' === $support,
			'message' => 'granted' === $support
				? 'دسترسی پشتیبانی فعال است.'
				: ( 'expired' === $support ? 'مهلت دسترسی پشتیبانی تمام شده است؛ در صورت نیاز دوباره اجازه دهید.' : 'دسترسی پشتیبانی داده نشده است؛ از زبانه «پشتیبانی» روی «اعطای دسترسی» بزنید.' ),
		);

		$source = isset( $in['sourceStatus'] ) ? (string) $in['sourceStatus'] : 'unknown';
		$out[] = array(
			'key'     => 'source_account',
			'ok'      => 'ok' === $source,
			'message' => self::source_status_message( $source ),
		);

		$hosting = isset( $in['hostingStatus'] ) ? (string) $in['hostingStatus'] : 'not_provided';
		$out[] = array(
			'key'     => 'hosting_access',
			'ok'      => 'provided' === $hosting,
			'message' => 'provided' === $hosting
				? 'اطلاعات دسترسی هاست برای پشتیبانی ارسال شده است.'
				: ( 'send_failed' === $hosting ? 'ارسال اطلاعات هاست ناموفق بود؛ دوباره ارسال کنید.' : 'اطلاعات دسترسی پنل هاست ارسال نشده است.' ),
		);

		$cron_last = max( absint( isset( $in['cronLastSuccessAt'] ) ? $in['cronLastSuccessAt'] : 0 ), absint( isset( $in['selfRunLastSuccessAt'] ) ? $in['selfRunLastSuccessAt'] : 0 ) );
		$cron_ok   = $cron_last > 0 && ( $now - $cron_last ) <= 2 * 3600;
		$out[] = array(
			'key'     => 'cron',
			'ok'      => $cron_ok,
			'message' => $cron_ok
				? 'Cron/Worker افزونه به‌طور منظم اجرا می‌شود.'
				: ( $cron_last > 0 ? 'Cron افزونه بیش از ۲ ساعت است که با موفقیت اجرا نشده است؛ Cron واقعی هاست را بررسی کنید.' : 'هیچ اجرای موفق Cron ثبت نشده است؛ Cron واقعی هاست را طبق راهنمای زبانه Cron تنظیم کنید.' ),
		);

		$lb_enabled = ! empty( $in['loopbackEnabled'] );
		$lb_attempt = absint( isset( $in['loopbackLastAttemptAt'] ) ? $in['loopbackLastAttemptAt'] : 0 );
		$lb_success = absint( isset( $in['loopbackLastRunSuccessAt'] ) ? $in['loopbackLastRunSuccessAt'] : 0 );
		if ( ! $lb_enabled ) {
			$lb_ok  = true;
			$lb_msg = 'اجرای داخلی (Loopback) خاموش است و Cron واقعی استفاده می‌شود.';
		} elseif ( 0 === $lb_attempt ) {
			$lb_ok  = true;
			$lb_msg = 'هنوز درخواست داخلی (Loopback) ثبت نشده است.';
		} elseif ( $lb_success > 0 && ( $now - $lb_success ) <= 86400 ) {
			$lb_ok  = true;
			$lb_msg = 'درخواست داخلی (Loopback) سایت به خودش کار می‌کند.';
		} else {
			$lb_ok  = false;
			$lb_msg = 'درخواست داخلی (Loopback) سایت به خودش اجرا نمی‌شود؛ فایروال، SSL یا مسدود بودن REST را در هاست بررسی کنید.';
		}
		$out[] = array( 'key' => 'loopback', 'ok' => $lb_ok, 'message' => $lb_msg );

		$php     = isset( $in['phpVersion'] ) ? (string) $in['phpVersion'] : PHP_VERSION;
		$php_min = isset( $in['phpMinimum'] ) ? (string) $in['phpMinimum'] : '7.4';
		$is64    = ! array_key_exists( 'php64bit', $in ) || ! empty( $in['php64bit'] );
		$php_ok  = version_compare( $php, $php_min, '>=' ) && $is64;
		$out[] = array(
			'key'     => 'php_version',
			'ok'      => $php_ok,
			'message' => $php_ok
				? sprintf( 'نسخه PHP (%s) مناسب است.', $php )
				: ( $is64 ? sprintf( 'نسخه PHP (%1$s) قدیمی است؛ حداقل %2$s لازم است.', $php, $php_min ) : 'PHP هاست 32 بیتی است؛ PHP 64 بیتی لازم است.' ),
		);

		$stamped = ! empty( $in['migrationStamped'] );
		$marked  = absint( isset( $in['migrationMarked'] ) ? $in['migrationMarked'] : 0 );
		$out[] = array(
			'key'     => 'migration',
			'ok'      => $stamped && 0 === $marked,
			'message' => ! $stamped
				? 'به‌روزرسانی دیتابیس افزونه هنوز کامل نشده است؛ Cron باید چند بار اجرا شود.'
				: ( $marked > 0 ? sprintf( 'به‌روزرسانی دیتابیس انجام شد اما %d محصول برای بررسی علامت‌گذاری شده است.', $marked ) : 'دیتابیس افزونه به‌روز است.' ),
		);

		$integrity = isset( $in['integrity'] ) ? $in['integrity'] : null;
		$int_ok    = true === $integrity;
		$out[] = array(
			'key'     => 'plugin_integrity',
			'ok'      => $int_ok,
			'message' => $int_ok
				? 'فایل‌های افزونه سالم و مطابق نسخه منتشرشده هستند.'
				: ( null === $integrity ? 'سلامت فایل‌های افزونه قابل بررسی نیست (فایل manifest موجود نیست).' : 'برخی فایل‌های افزونه تغییر کرده یا ناقص است؛ افزونه را دوباره نصب کنید.' ),
		);

		$out[] = self::db_charset_readiness_item( isset( $in['dbCharset'] ) && is_array( $in['dbCharset'] ) ? $in['dbCharset'] : array() );

		return $out;
	}

	/**
	 * Pure db_charset readiness item.
	 *
	 * @param array $db status (ok|degraded|unknown), unsupportedColumns (list), replacements (int), migrationStatus.
	 * @return array
	 */
	public static function db_charset_readiness_item( $db ) {
		$status      = isset( $db['status'] ) ? (string) $db['status'] : 'unknown';
		$columns     = isset( $db['unsupportedColumns'] ) && is_array( $db['unsupportedColumns'] ) ? $db['unsupportedColumns'] : array();
		$replaced    = absint( isset( $db['replacements'] ) ? $db['replacements'] : 0 );
		$migration   = isset( $db['migrationStatus'] ) ? (string) $db['migrationStatus'] : '';
		if ( 'ok' === $status ) {
			return array( 'key' => 'db_charset', 'ok' => true, 'message' => 'جدول‌های دیتابیس از ایموجی و کاراکترهای ۴ بایتی پشتیبانی می‌کنند (utf8mb4).' );
		}
		if ( 'degraded' !== $status ) {
			return array( 'key' => 'db_charset', 'ok' => true, 'message' => 'پشتیبانی دیتابیس از کاراکترهای ۴ بایتی هنوز بررسی نشده است.' );
		}
		$message = sprintf(
			'%d ستون از جدول‌های وردپرس (مثل wp_terms/wp_posts) هنوز utf8/utf8mb3 است و ایموجی/کاراکتر ۴ بایتی را نمی‌پذیرد. افزونه این کاراکترها را موقتاً جایگزین می‌کند تا همگام‌سازی متوقف نشود%s. پیشنهاد: ابتدا از دیتابیس Backup بگیرید و سپس از «سلامت سایت» ابزار «تبدیل دیتابیس به utf8mb4» را به‌صورت دستی اجرا کنید.',
			count( $columns ),
			$replaced > 0 ? sprintf( ' (تاکنون %d مورد جایگزین شده)', $replaced ) : ''
		);
		if ( in_array( $migration, array( 'ready', 'paused', 'partial', 'failed' ), true ) ) {
			$message .= ' تبدیل شروع شده ولی کامل نشده است؛ «تبدیل جدول بعدی» را ادامه دهید.';
		}
		return array( 'key' => 'db_charset', 'ok' => false, 'message' => $message );
	}

	/**
	 * Gather readiness inputs from existing diagnostics (options only).
	 *
	 * @return array
	 */
	public static function collect_readiness_inputs() {
		$cron = class_exists( 'Mobo_Core_Cron_Runner' ) ? Mobo_Core_Cron_Runner::get_status() : array();
		$self = array(
			'enabled'           => class_exists( 'Mobo_Core_Settings' ) ? Mobo_Core_Settings::enabled( 'mobo_core_self_runner_enabled', '1' ) : true,
			'lastKickAttemptAt' => absint( get_option( 'mobo_core_self_runner_last_kick_attempt_at', 0 ) ),
			'lastRunSuccessAt'  => absint( get_option( 'mobo_core_self_runner_last_run_success_at', 0 ) ),
		);
		$db_version = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		$marked     = 0;
		if ( class_exists( 'Mobo_Core_Migration_Quarantine' ) && method_exists( 'Mobo_Core_Migration_Quarantine', 'all' ) ) {
			$all    = Mobo_Core_Migration_Quarantine::all();
			$marked = is_array( $all ) ? count( $all ) : 0;
		}
		$source  = self::get_source_state();
		$hosting = self::health_hosting_access();
		return array(
			'now'                      => time(),
			'supportStatus'            => self::get_status(),
			'sourceStatus'             => isset( $source['status'] ) ? (string) $source['status'] : 'unknown',
			'hostingStatus'            => $hosting['status'],
			'cronLastSuccessAt'        => absint( isset( $cron['lastSuccessAt'] ) ? $cron['lastSuccessAt'] : 0 ),
			'selfRunLastSuccessAt'     => $self['lastRunSuccessAt'],
			'loopbackEnabled'          => (bool) $self['enabled'],
			'loopbackLastAttemptAt'    => $self['lastKickAttemptAt'],
			'loopbackLastRunSuccessAt' => $self['lastRunSuccessAt'],
			'phpVersion'               => PHP_VERSION,
			'phpMinimum'               => '7.4',
			'php64bit'                 => PHP_INT_SIZE >= 8,
			'migrationStamped'         => defined( 'MOBO_CORE_VERSION' ) && MOBO_CORE_VERSION === $db_version,
			'migrationMarked'          => $marked,
			'integrity'                => self::plugin_integrity(),
			'dbCharset'                => self::collect_db_charset_input(),
		);
	}

	/**
	 * db_charset readiness input (column support is cached for 6 hours).
	 *
	 * @return array
	 */
	private static function collect_db_charset_input() {
		if ( ! class_exists( 'Mobo_Core_Unicode_Guard' ) ) {
			return array( 'status' => 'unknown' );
		}
		try {
			$snapshot = Mobo_Core_Unicode_Guard::snapshot();
		} catch ( Throwable $e ) {
			return array( 'status' => 'unknown' );
		}
		$migration = class_exists( 'Mobo_Core_Database_Unicode_Migration' ) ? Mobo_Core_Database_Unicode_Migration::get_status() : array();
		return array(
			'status'             => (string) $snapshot['status'],
			'unsupportedColumns' => $snapshot['unsupportedColumns'],
			'replacements'       => $snapshot['replacements'],
			'migrationStatus'    => isset( $migration['status'] ) ? (string) $migration['status'] : '',
		);
	}

	/**
	 * Health "readiness" array.
	 *
	 * @return array
	 */
	public static function health_readiness() {
		return self::compute_readiness( self::collect_readiness_inputs() );
	}

	/**
	 * Manifest integrity (cached for 6 hours per version).
	 *
	 * @param bool $force Recompute.
	 * @return bool|null Null when no manifest.
	 */
	public static function plugin_integrity( $force = false ) {
		if ( ! defined( 'MOBO_CORE_PLUGIN_DIR' ) ) {
			return null;
		}
		$version = defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '';
		$cache   = get_option( self::OPTION_INTEGRITY, array() );
		if ( ! $force && is_array( $cache ) && isset( $cache['v'], $cache['at'] ) && $version === (string) $cache['v'] && ( time() - absint( $cache['at'] ) ) < self::INTEGRITY_CACHE_TTL ) {
			return array_key_exists( 'ok', $cache ) ? $cache['ok'] : null;
		}
		$result = self::verify_manifest( MOBO_CORE_PLUGIN_DIR, $version );
		update_option( self::OPTION_INTEGRITY, array( 'v' => $version, 'at' => time(), 'ok' => $result ), false );
		return $result;
	}

	/**
	 * Verify every manifest entry's SHA-256 (path => hash map).
	 *
	 * @param string $root    Plugin root.
	 * @param string $version Expected version.
	 * @return bool|null
	 */
	public static function verify_manifest( $root, $version ) {
		$root = rtrim( (string) $root, '/\\' ) . '/';
		$file = $root . 'mobo-core-manifest.json';
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}
		$manifest = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $manifest ) || empty( $manifest['files'] ) || ! is_array( $manifest['files'] ) ) {
			return false;
		}
		if ( '' !== (string) $version && isset( $manifest['version'] ) && (string) $manifest['version'] !== (string) $version ) {
			return false;
		}
		foreach ( $manifest['files'] as $relative => $expected ) {
			$relative = str_replace( '\\', '/', (string) $relative );
			if ( '' === $relative || false !== strpos( $relative, '..' ) || ! preg_match( '/^[a-f0-9]{64}$/i', (string) $expected ) ) {
				return false;
			}
			$path   = $root . ltrim( $relative, '/' );
			$actual = is_file( $path ) ? hash_file( 'sha256', $path ) : '';
			if ( ! is_string( $actual ) || '' === $actual || ! hash_equals( strtolower( (string) $expected ), strtolower( $actual ) ) ) {
				return false;
			}
		}
		return true;
	}
}

