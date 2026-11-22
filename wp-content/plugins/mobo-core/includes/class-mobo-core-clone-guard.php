<?php
/**
 * S1 clone / staging-copy guard for Source-side financial calls.
 *
 * A database copy of a customer site (staging, clone, local backup restore, or a
 * domain move) carries every secret of the original: Connection Token, X-SEC,
 * Source storefront credentials, the shared Source cookie jar and the RSA
 * installation key. Without a binding to the site's address such a copy places
 * REAL supplier orders and wallet payments for orders paid on the copy (or for
 * queued/uncertain orders copied from the original).
 *
 * Policy (owner contract):
 * - The canonical site address (home + siteurl: host without "www.", non-default
 *   port and path; scheme ignored) is recorded when the Backend provisions a
 *   Connection. Installations that predate this guard adopt their current address
 *   once, only while WordPress reports a production environment.
 * - When the current address differs from the recorded one (clone, staging copy,
 *   domain move) every Source-side financial call (storefront login, shared cart,
 *   checkout, wallet payment, wallet balance) is refused, paid orders are held for
 *   manual review with a Persian order note, admins see a notice and the health
 *   payload reports cloneSuspected so the Backend can flag the installation.
 * - www/non-www and http/https of the SAME host never trigger.
 * - The binding is never updated automatically: only a new Backend provisioning
 *   (explicit domain move) re-binds the installation and clears the suspicion.
 * - A non-production WP_ENVIRONMENT_TYPE (staging/local/development) is an
 *   additional purchase block unless MOBO_CORE_ALLOW_NON_PRODUCTION_PURCHASE is
 *   defined true (developer test benches).
 * - The database fingerprint (DB_NAME + table prefix) is recorded and reported
 *   only; hosting moves legitimately change it, so it never blocks on its own.
 *
 * PHP 7.4 compatible. No host-disableable PHP function is used.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Clone_Guard {

	/** Canonical site binding. Small; autoloaded (read on order/checkout paths). */
	const OPTION_BINDING = 'mobo_core_site_binding_v1';

	/** First observation of the current mismatch (diagnostics only, never a gate). */
	const OPTION_DETECTION = 'mobo_core_clone_detection_v1';

	/** Order meta written when an order is held because of this guard. */
	const ORDER_META_BLOCKED = '_mobo_order_clone_blocked';

	/** Order submit status for a clone-held order (terminal for automation). */
	const SUBMIT_STATUS = 'blocked_clone';

	const ERROR_CODE = 'mobo_core_clone_guard_blocked';

	const REASON_SITE_CHANGED     = 'site_url_changed';
	const REASON_SITE_UNAVAILABLE = 'site_url_unavailable';
	const REASON_NON_PRODUCTION   = 'non_production_environment';

	/** @var array|null Request-local status cache. */
	private static $status = null;

	/** @return void */
	public static function reset_cache() {
		self::$status = null;
	}

	/**
	 * Normalized identity of one site URL: lowercase host without a leading
	 * "www." and trailing dot, ":port" only for non-default ports, and the path
	 * without trailing slash. The scheme is ignored on purpose.
	 *
	 * @param string $url URL.
	 * @return string Empty when the URL has no host.
	 */
	public static function site_key( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( false === strpos( $url, '://' ) ) {
			$url = 'http://' . ltrim( $url, '/' );
		}
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( rtrim( trim( (string) $parts['host'] ), '.' ) );
		if ( 0 === strpos( $host, 'www.' ) && strlen( $host ) > 4 ) {
			$host = substr( $host, 4 );
		}
		if ( '' === $host ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$key  = $host . ( $port > 0 && 80 !== $port && 443 !== $port ? ':' . $port : '' );
		$path = isset( $parts['path'] ) ? strtolower( trim( (string) $parts['path'] ) ) : '';
		$path = rtrim( $path, '/' );
		if ( '' !== $path ) {
			$key .= '/' . ltrim( $path, '/' );
		}
		return $key;
	}

	/**
	 * Raw configured site URLs. Options (WP_HOME/WP_SITEURL aware) rather than
	 * home_url(), which multilingual/domain-mapping plugins filter per request.
	 *
	 * @return array{home:string,site:string}
	 */
	public static function current_urls() {
		return array(
			'home' => (string) get_option( 'home', '' ),
			'site' => (string) get_option( 'siteurl', '' ),
		);
	}

	/** @return string Report-only fingerprint of the database/table set. */
	public static function database_fingerprint() {
		global $wpdb;
		$db_name = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		$prefix  = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		return substr( hash( 'sha256', 'mobo-site-db-v1|' . $db_name . '|' . $prefix ), 0, 32 );
	}

	/** @return string production|staging|development|local */
	public static function environment_type() {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			$type = sanitize_key( (string) wp_get_environment_type() );
			return '' !== $type ? $type : 'production';
		}
		return 'production';
	}

	/** @return bool Developer override for non-production purchase tests. */
	public static function non_production_purchase_allowed() {
		return defined( 'MOBO_CORE_ALLOW_NON_PRODUCTION_PURCHASE' ) && true === MOBO_CORE_ALLOW_NON_PRODUCTION_PURCHASE;
	}

	/**
	 * Stored binding, or null when absent/malformed.
	 *
	 * @return array|null
	 */
	public static function binding() {
		$raw = get_option( self::OPTION_BINDING, null );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$home = isset( $raw['home'] ) ? (string) $raw['home'] : '';
		$site = isset( $raw['site'] ) ? (string) $raw['site'] : '';
		if ( '' === $home || '' === $site ) {
			return null;
		}
		return array(
			'home'    => $home,
			'site'    => $site,
			'homeUrl' => isset( $raw['homeUrl'] ) ? (string) $raw['homeUrl'] : '',
			'db'      => isset( $raw['db'] ) ? (string) $raw['db'] : '',
			'boundAt' => isset( $raw['boundAt'] ) ? absint( $raw['boundAt'] ) : 0,
			'source'  => isset( $raw['source'] ) ? sanitize_key( (string) $raw['source'] ) : '',
		);
	}

	/**
	 * Record the CURRENT address as canonical. Called only by Backend provisioning
	 * (source "provision") and by the one-time production adoption ("adopted").
	 *
	 * @param string $source Binding source.
	 * @return bool
	 */
	public static function bind_current_site( $source ) {
		$urls = self::current_urls();
		$home = self::site_key( $urls['home'] );
		$site = self::site_key( '' !== trim( $urls['site'] ) ? $urls['site'] : $urls['home'] );
		if ( '' === $home || '' === $site ) {
			return false;
		}
		$binding = array(
			'v'       => 1,
			'home'    => $home,
			'site'    => $site,
			'homeUrl' => esc_url_raw( $urls['home'] ),
			'db'      => self::database_fingerprint(),
			'boundAt' => time(),
			'source'  => sanitize_key( (string) $source ),
		);
		update_option( self::OPTION_BINDING, $binding, true );
		$stored = get_option( self::OPTION_BINDING, null );
		$ok = is_array( $stored ) && isset( $stored['home'], $stored['site'] ) && $home === (string) $stored['home'] && $site === (string) $stored['site'];
		if ( $ok && function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION_DETECTION );
		}
		self::reset_cache();
		return $ok;
	}

	/**
	 * Pure decision (unit tested).
	 *
	 * @param array|null $binding Stored binding.
	 * @param array      $current home/site URLs.
	 * @param string     $environment Environment type.
	 * @param bool       $allow_non_production Developer override.
	 * @param string     $db_fingerprint Current DB fingerprint.
	 * @return array
	 */
	public static function evaluate( $binding, $current, $environment, $allow_non_production, $db_fingerprint = '' ) {
		$current      = is_array( $current ) ? $current : array();
		$current_home = self::site_key( isset( $current['home'] ) ? $current['home'] : '' );
		$current_site = self::site_key( isset( $current['site'] ) && '' !== trim( (string) $current['site'] ) ? $current['site'] : ( isset( $current['home'] ) ? $current['home'] : '' ) );
		$environment  = sanitize_key( (string) $environment );
		$reasons      = array();
		$suspected    = false;

		if ( is_array( $binding ) ) {
			if ( '' === $current_home || '' === $current_site ) {
				$reasons[] = self::REASON_SITE_UNAVAILABLE;
				$suspected = true;
			} elseif ( $current_home !== (string) $binding['home'] || $current_site !== (string) $binding['site'] ) {
				$reasons[] = self::REASON_SITE_CHANGED;
				$suspected = true;
			}
		}
		if ( in_array( $environment, array( 'staging', 'local', 'development' ), true ) && ! $allow_non_production ) {
			$reasons[] = self::REASON_NON_PRODUCTION;
		}

		return array(
			'cloneSuspected'   => $suspected,
			'purchaseBlocked'  => ! empty( $reasons ),
			'reasons'          => $reasons,
			'bound'            => is_array( $binding ),
			'boundHome'        => is_array( $binding ) ? (string) $binding['home'] : '',
			'boundSite'        => is_array( $binding ) ? (string) $binding['site'] : '',
			'currentHome'      => $current_home,
			'currentSite'      => $current_site,
			'environmentType'  => '' !== $environment ? $environment : 'production',
			'databaseChanged'  => is_array( $binding ) && '' !== (string) $binding['db'] && '' !== (string) $db_fingerprint && ! hash_equals( (string) $binding['db'], (string) $db_fingerprint ),
		);
	}

	/**
	 * Live status (request cached). Performs the one-time production adoption and
	 * records the first observation of a mismatch for diagnostics.
	 *
	 * @return array
	 */
	public static function status() {
		if ( is_array( self::$status ) ) {
			return self::$status;
		}
		$binding     = self::binding();
		$environment = self::environment_type();
		if ( null === $binding && 'production' === $environment ) {
			if ( self::bind_current_site( 'adopted' ) ) {
				$binding = self::binding();
			}
		}
		$status = self::evaluate( $binding, self::current_urls(), $environment, self::non_production_purchase_allowed(), self::database_fingerprint() );
		$status['boundAt']       = is_array( $binding ) ? absint( $binding['boundAt'] ) : 0;
		$status['bindingSource'] = is_array( $binding ) ? (string) $binding['source'] : '';
		$status['detectedAt']    = 0;

		if ( ! empty( $status['cloneSuspected'] ) ) {
			$detection = get_option( self::OPTION_DETECTION, null );
			$same      = is_array( $detection ) && isset( $detection['home'], $detection['site'] )
				&& (string) $detection['home'] === $status['currentHome'] && (string) $detection['site'] === $status['currentSite'];
			if ( ! $same ) {
				$detection = array(
					'home'       => $status['currentHome'],
					'site'       => $status['currentSite'],
					'boundHome'  => $status['boundHome'],
					'detectedAt' => time(),
				);
				update_option( self::OPTION_DETECTION, $detection, false );
				if ( class_exists( 'Mobo_Core_Logger' ) ) {
					Mobo_Core_Logger::error( 'Clone guard: site address differs from the provisioned binding; Source purchases and wallet payments are blocked until the Backend re-provisions this site.', array( 'boundHome' => $status['boundHome'], 'currentHome' => $status['currentHome'], 'currentSite' => $status['currentSite'] ) );
				}
			}
			$status['detectedAt'] = absint( $detection['detectedAt'] ?? 0 );
		}

		self::$status = $status;
		return $status;
	}

	/** @return bool Whether Source-side financial calls are refused right now. */
	public static function is_purchase_blocked() {
		$status = self::status();
		return ! empty( $status['purchaseBlocked'] );
	}

	/** @return bool */
	public static function is_clone_suspected() {
		$status = self::status();
		return ! empty( $status['cloneSuspected'] );
	}

	/**
	 * Persian, admin-facing explanation of the current block.
	 *
	 * @param array|null $status Optional status (defaults to live).
	 * @return string
	 */
	public static function blocked_message( $status = null ) {
		$status  = is_array( $status ) ? $status : self::status();
		$reasons = isset( $status['reasons'] ) && is_array( $status['reasons'] ) ? $status['reasons'] : array();
		$parts   = array();
		if ( in_array( self::REASON_SITE_CHANGED, $reasons, true ) || in_array( self::REASON_SITE_UNAVAILABLE, $reasons, true ) ) {
			$parts[] = sprintf(
				'نشانی فعلی سایت (%s) با نشانی ثبت‌شده هنگام اتصال به موبو (%s) یکسان نیست؛ این سایت احتمالاً نسخه کپی (staging/clone) است یا دامنه آن تغییر کرده است.',
				'' !== (string) ( $status['currentHome'] ?? '' ) ? (string) $status['currentHome'] : 'نامشخص',
				(string) ( $status['boundHome'] ?? '' )
			);
		}
		if ( in_array( self::REASON_NON_PRODUCTION, $reasons, true ) ) {
			$parts[] = sprintf( 'محیط WordPress این سایت «%s» (غیرعملیاتی) تعریف شده است.', (string) ( $status['environmentType'] ?? '' ) );
		}
		if ( empty( $parts ) ) {
			return '';
		}
		return implode( ' ', $parts ) . ' برای جلوگیری از خرید واقعی و برداشت از کیف پول منبع، خرید خودکار، پرداخت کیف پول و هر درخواست مالی به منبع متوقف شد و سفارش‌ها برای بررسی دستی نگه داشته می‌شوند. اگر دامنه را عمداً تغییر داده‌اید، اتصال را از پنل موبو دوباره Provision کنید.';
	}

	/**
	 * Error returned by every refused Source call (never reached the Source).
	 *
	 * @return WP_Error
	 */
	public static function blocked_error() {
		$status = self::status();
		return new WP_Error(
			self::ERROR_CODE,
			self::blocked_message( $status ),
			array(
				'requestMayHaveReachedServer' => false,
				'reasons'                     => $status['reasons'],
			)
		);
	}

	/**
	 * Order note for an order held by this guard.
	 *
	 * @return string
	 */
	public static function order_note() {
		return 'خرید خودکار موبو برای این سفارش انجام نشد و سفارش برای بررسی دستی نگه داشته شد. ' . self::blocked_message() . ' هیچ سفارش یا پرداختی در منبع ثبت نشده است؛ پس از رفع وضعیت، سفارش را دستی بررسی و در صورت نیاز ارسال کنید.';
	}

	/**
	 * Health payload section for the Backend.
	 *
	 * @return array
	 */
	public static function health_payload() {
		$status = self::status();
		return array(
			'cloneSuspected'  => ! empty( $status['cloneSuspected'] ),
			'purchaseBlocked' => ! empty( $status['purchaseBlocked'] ),
			'reasons'         => array_values( array_map( 'sanitize_key', (array) $status['reasons'] ) ),
			'bound'           => ! empty( $status['bound'] ),
			'bindingSource'   => (string) $status['bindingSource'],
			'boundAt'         => absint( $status['boundAt'] ),
			'boundHome'       => (string) $status['boundHome'],
			'boundSite'       => (string) $status['boundSite'],
			'currentHome'     => (string) $status['currentHome'],
			'currentSite'     => (string) $status['currentSite'],
			'environmentType' => (string) $status['environmentType'],
			'databaseChanged' => ! empty( $status['databaseChanged'] ),
			'detectedAt'      => absint( $status['detectedAt'] ),
		);
	}

	/**
	 * Admin notice. A suspected clone is always shown (money); a non-production
	 * environment only when automatic order submission is enabled somewhere.
	 *
	 * @return void
	 */
	public static function render_admin_notice() {
		if ( ! function_exists( 'current_user_can' ) || ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) ) {
			return;
		}
		$status = self::status();
		if ( empty( $status['purchaseBlocked'] ) ) {
			return;
		}
		if ( empty( $status['cloneSuspected'] ) ) {
			$auto_order = class_exists( 'Mobo_Core_Order_Submission_Policy' ) && method_exists( 'Mobo_Core_Order_Submission_Policy', 'is_enabled_for_any_connection' )
				&& Mobo_Core_Order_Submission_Policy::is_enabled_for_any_connection();
			if ( ! $auto_order ) {
				return;
			}
		}
		echo '<div class="notice notice-error mobo-clone-guard-notice" dir="rtl"><p><strong>موبوکور: خرید خودکار و پرداخت کیف پول متوقف است.</strong></p><p>' . esc_html( self::blocked_message( $status ) ) . '</p></div>';
	}
}
