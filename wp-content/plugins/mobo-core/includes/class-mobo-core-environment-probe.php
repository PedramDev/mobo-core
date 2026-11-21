<?php
/**
 * Environment capability probe (cached once per day).
 *
 * Reports what the host removed or restricts — disabled functions that Mobo
 * calls, missing extensions, open_basedir, limits, SAPI and the loopback
 * result — and turns only the ones that actually impact a feature into readiness
 * items with a Persian explanation of that impact. Exposed in the health payload
 * as environment.capabilities and in the remote diagnostics bundle.
 *
 * compute() is pure (inputs in, report out) for the standalone tests.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Environment_Probe {

	const CACHE_OPTION = 'mobo_core_env_capabilities';
	const CACHE_TTL    = 86400;

	/**
	 * Functions Mobo (or the WordPress APIs it depends on) calls, with the
	 * feature impact when the host disables them. 'impact' => '' means the
	 * plugin has a complete fallback (listed in capabilities, no readiness item).
	 *
	 * @return array<string,array{feature:string,impact:string}>
	 */
	public static function relevant_functions() {
		return array(
			'set_time_limit'             => array( 'feature' => 'upgrade', 'impact' => 'تابع set_time_limit غیرفعال است؛ به‌روزرسانی خودکار افزونه فقط در محدوده max_execution_time هاست انجام می‌شود و روی هاست‌های کند ممکن است نیمه‌کاره بماند (کارهای پس‌زمینه با مهلت هر درخواست تنظیم می‌شوند و مشکلی ندارند).' ),
			'ignore_user_abort'          => array( 'feature' => 'workers', 'impact' => '' ),
			'ini_get'                    => array( 'feature' => 'runtime', 'impact' => 'تابع ini_get غیرفعال است؛ افزونه نمی‌تواند max_execution_time و memory_limit واقعی را بخواند و با فرض ۳۰ ثانیه و حافظه نامشخص، دسته‌های کوچک‌تر و کندتری پردازش می‌کند.' ),
			'register_shutdown_function' => array( 'feature' => 'locks', 'impact' => 'تابع register_shutdown_function غیرفعال است؛ اگر درخواستی با خطای مرگبار متوقف شود، قفل Worker تا پایان مهلت lease آزاد نمی‌شود و صف‌ها چند دقیقه دیرتر ادامه می‌یابند.' ),
			'scandir'                    => array( 'feature' => 'files', 'impact' => 'تابع scandir غیرفعال است؛ به‌روزرسانی خودکار افزونه انجام نمی‌شود و پاک‌سازی/بازسازی تصاویر قدیمی فایل‌های اندازه‌های قبلی را پیدا نمی‌کند.' ),
			'gethostbynamel'             => array( 'feature' => 'dns', 'impact' => '' ),
			'dns_get_record'             => array( 'feature' => 'dns', 'impact' => '' ),
			'gethostbyname'              => array( 'feature' => 'dns', 'impact' => '' ),
			'getenv'                     => array( 'feature' => 'config', 'impact' => '' ),
			'error_log'                  => array( 'feature' => 'logging', 'impact' => '' ),
			'usleep'                     => array( 'feature' => 'retry', 'impact' => '' ),
			'random_bytes'               => array( 'feature' => 'tokens', 'impact' => '' ),
			'memory_get_usage'           => array( 'feature' => 'adaptive', 'impact' => '' ),
			'php_ini_loaded_file'        => array( 'feature' => 'diagnostics', 'impact' => '' ),
			'disk_free_space'            => array( 'feature' => 'diagnostics', 'impact' => '' ),
			'opcache_get_status'         => array( 'feature' => 'diagnostics', 'impact' => '' ),
			'openssl_sign'               => array( 'feature' => 'license', 'impact' => 'توابع OpenSSL غیرفعال است؛ امضای درخواست‌های لایسنس/Portal و ارسال امن رمز پنل هاست برای پشتیبانی ممکن نیست.' ),
			'openssl_public_encrypt'     => array( 'feature' => 'support', 'impact' => '' ),
			'curl_exec'                  => array( 'feature' => 'http', 'impact' => '' ),
			'curl_multi_exec'            => array( 'feature' => 'http', 'impact' => '' ),
			'stream_socket_client'       => array( 'feature' => 'http', 'impact' => '' ),
			'fsockopen'                  => array( 'feature' => 'http', 'impact' => '' ),
			'exec'                       => array( 'feature' => 'none', 'impact' => '' ),
			'shell_exec'                 => array( 'feature' => 'none', 'impact' => '' ),
			'proc_open'                  => array( 'feature' => 'none', 'impact' => '' ),
			'flock'                      => array( 'feature' => 'none', 'impact' => '' ),
			'putenv'                     => array( 'feature' => 'none', 'impact' => '' ),
			'getmypid'                   => array( 'feature' => 'none', 'impact' => '' ),
			'ini_set'                    => array( 'feature' => 'none', 'impact' => '' ),
			'symlink'                    => array( 'feature' => 'none', 'impact' => '' ),
			'mail'                       => array( 'feature' => 'none', 'impact' => '' ),
		);
	}

	/**
	 * Extensions Mobo uses directly or through WordPress, with impact text.
	 *
	 * @return array<string,string>
	 */
	public static function relevant_extensions() {
		return array(
			'mbstring' => 'افزونه mbstring روی PHP فعال نیست؛ کوتاه‌سازی متن‌های فارسی (پیامک، نام دسته، گزارش‌ها) بایت‌به‌بایت انجام می‌شود و ممکن است حروف فارسی نیمه‌کاره نمایش داده شوند.',
			'intl'     => 'افزونه intl روی PHP فعال نیست؛ یکسان‌سازی یونیکد نام دسته‌ها و فیلترهای حذف محصول انجام نمی‌شود و نام‌هایی که با نویسه‌های ترکیبی نوشته شده‌اند ممکن است تطبیق داده نشوند.',
			'openssl'  => 'افزونه OpenSSL روی PHP فعال نیست؛ امضای درخواست‌های لایسنس/Portal و ارسال امن اطلاعات پنل هاست ممکن نیست.',
			'curl'     => '',
			'zip'      => '',
			'gd'       => '',
			'imagick'  => '',
			'sodium'   => '',
			'fileinfo' => '',
			'exif'     => '',
			'ctype'    => '',
			'json'     => 'افزونه json روی PHP فعال نیست؛ افزونه نمی‌تواند پاسخ‌های سرور مرکزی را بخواند.',
		);
	}

	/**
	 * Cached capability report (recomputed once per day or when PHP changes).
	 *
	 * @param bool $force Recompute.
	 * @return array
	 */
	public static function get( $force = false ) {
		$fingerprint = PHP_VERSION . '|' . PHP_SAPI;
		$cached      = function_exists( 'get_option' ) ? get_option( self::CACHE_OPTION, array() ) : array();
		if ( ! $force && is_array( $cached ) && isset( $cached['report'], $cached['at'], $cached['fp'] )
			&& $fingerprint === (string) $cached['fp'] && ( time() - absint( $cached['at'] ) ) < self::CACHE_TTL ) {
			return $cached['report'];
		}
		try {
			$report = self::compute( self::collect_inputs() );
		} catch ( Throwable $e ) {
			$report = self::compute( array() );
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::CACHE_OPTION, array( 'fp' => $fingerprint, 'at' => time(), 'report' => $report ), false );
		}
		return $report;
	}

	/**
	 * Gather raw inputs (cheap: function_exists/extension_loaded/ini/options).
	 *
	 * @return array
	 */
	public static function collect_inputs() {
		$functions = array();
		foreach ( array_keys( self::relevant_functions() ) as $name ) {
			$functions[ $name ] = Mobo_Core_Environment::can( $name );
		}
		$extensions = array();
		foreach ( array_keys( self::relevant_extensions() ) as $ext ) {
			$extensions[ $ext ] = extension_loaded( $ext );
		}
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
		$upload_dir = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		$temp_dir = function_exists( 'get_temp_dir' ) ? (string) get_temp_dir() : '';
		return array(
			'functions'          => $functions,
			'disabledList'       => Mobo_Core_Environment::disabled_functions(),
			'extensions'         => $extensions,
			'imagickClass'       => class_exists( 'Imagick', false ),
			'openBasedir'        => Mobo_Core_Environment::ini( 'open_basedir', '' ),
			'uploadsWritable'    => '' === $upload_dir ? null : ( function_exists( 'wp_is_writable' ) ? wp_is_writable( $upload_dir ) : is_writable( $upload_dir ) ),
			'tempWritable'       => '' === $temp_dir ? null : ( function_exists( 'wp_is_writable' ) ? wp_is_writable( $temp_dir ) : is_writable( $temp_dir ) ),
			'maxExecutionTime'   => Mobo_Core_Environment::ini( 'max_execution_time', '' ),
			'memoryLimit'        => Mobo_Core_Environment::ini( 'memory_limit', '' ),
			'sapi'               => PHP_SAPI,
			'phpVersion'         => PHP_VERSION,
			'httpSupportsSsl'    => function_exists( 'wp_http_supports' ) ? (bool) wp_http_supports( array( 'ssl' ) ) : null,
			'loopbackEnabled'    => class_exists( 'Mobo_Core_Settings' ) ? Mobo_Core_Settings::enabled( 'mobo_core_self_runner_enabled', '1' ) : true,
			'loopbackAttemptAt'  => function_exists( 'get_option' ) ? absint( get_option( 'mobo_core_self_runner_last_kick_attempt_at', 0 ) ) : 0,
			'loopbackSuccessAt'  => function_exists( 'get_option' ) ? absint( get_option( 'mobo_core_self_runner_last_run_success_at', 0 ) ) : 0,
			'now'                => time(),
		);
	}

	/**
	 * Pure capability report.
	 *
	 * @param array $in Inputs (see collect_inputs()).
	 * @return array
	 */
	public static function compute( $in ) {
		$in        = is_array( $in ) ? $in : array();
		$functions = isset( $in['functions'] ) && is_array( $in['functions'] ) ? $in['functions'] : array();
		$exts      = isset( $in['extensions'] ) && is_array( $in['extensions'] ) ? $in['extensions'] : array();
		$now       = absint( $in['now'] ?? time() );
		$impacts   = array();

		$disabled = array();
		foreach ( self::relevant_functions() as $name => $meta ) {
			if ( array_key_exists( $name, $functions ) && ! $functions[ $name ] ) {
				$disabled[] = $name;
				if ( '' !== $meta['impact'] ) {
					$impacts[] = array( 'key' => 'fn_' . $name, 'function' => $name, 'feature' => $meta['feature'], 'severity' => 'license' === $meta['feature'] ? 'critical' : 'warning', 'message' => $meta['impact'] );
				}
			}
		}
		/* DNS: only when every PHP resolver is gone. */
		$dns_off = array_key_exists( 'gethostbynamel', $functions ) && ! $functions['gethostbynamel'] && array_key_exists( 'dns_get_record', $functions ) && ! $functions['dns_get_record'];
		if ( $dns_off ) {
			$impacts[] = array( 'key' => 'fn_dns', 'function' => 'gethostbynamel,dns_get_record', 'feature' => 'dns', 'severity' => 'warning', 'message' => 'توابع DNS در PHP غیرفعال است؛ بررسی امنیتی IP آدرس سرور مرکزی (جلوگیری از آدرس داخلی) فقط بر اساس نام دامنه انجام می‌شود.' );
		}
		/* Outbound HTTP: WordPress needs cURL or PHP streams with SSL. */
		$curl_ok   = ! empty( $exts['curl'] ) && ( ! array_key_exists( 'curl_exec', $functions ) || $functions['curl_exec'] );
		$stream_ok = ( ! array_key_exists( 'stream_socket_client', $functions ) || $functions['stream_socket_client'] ) && ! empty( $exts['openssl'] );
		$http_ssl  = array_key_exists( 'httpSupportsSsl', $in ) && null !== $in['httpSupportsSsl'] ? (bool) $in['httpSupportsSsl'] : ( $curl_ok || $stream_ok );
		if ( ! $http_ssl ) {
			$impacts[] = array( 'key' => 'http_transport', 'function' => 'curl_exec,stream_socket_client', 'feature' => 'http', 'severity' => 'critical', 'message' => 'هیچ روش ارسال درخواست HTTPS (cURL یا stream با OpenSSL) روی PHP در دسترس نیست؛ همگام‌سازی، وب‌هوک‌های سبک، ثبت سفارش و بررسی لایسنس کار نمی‌کنند.' );
		}

		$missing = array();
		foreach ( self::relevant_extensions() as $ext => $impact ) {
			if ( array_key_exists( $ext, $exts ) && ! $exts[ $ext ] ) {
				$missing[] = $ext;
				if ( '' !== $impact && ! ( 'openssl' === $ext && in_array( 'openssl_sign', $disabled, true ) ) ) {
					$impacts[] = array( 'key' => 'ext_' . $ext, 'function' => '', 'feature' => $ext, 'severity' => in_array( $ext, array( 'openssl', 'json' ), true ) ? 'critical' : 'warning', 'message' => $impact );
				}
			}
		}
		$imagick = ! empty( $exts['imagick'] ) && ( ! array_key_exists( 'imagickClass', $in ) || ! empty( $in['imagickClass'] ) );
		$image_editor = ! empty( $exts['gd'] ) || $imagick;
		if ( array_key_exists( 'gd', $exts ) && ! $image_editor ) {
			$impacts[] = array( 'key' => 'image_editor', 'function' => '', 'feature' => 'images', 'severity' => 'critical', 'message' => 'هیچ‌کدام از افزونه‌های GD یا Imagick روی PHP فعال نیست؛ ساخت اندازه‌های تصویر و تبدیل WebP انجام نمی‌شود و تصاویر محصولات فقط با فایل اصلی ذخیره می‌شوند.' );
		}

		$open_basedir = trim( (string) ( $in['openBasedir'] ?? '' ) );
		if ( '' !== $open_basedir && ( false === ( $in['uploadsWritable'] ?? null ) || false === ( $in['tempWritable'] ?? null ) ) ) {
			$impacts[] = array( 'key' => 'open_basedir', 'function' => '', 'feature' => 'files', 'severity' => 'warning', 'message' => 'محدودیت open_basedir باعث شده پوشه آپلود یا پوشه موقت قابل نوشتن نباشد؛ دانلود و ذخیره تصاویر محصولات ممکن است ناموفق شود.' );
		}

		$max_exec = (string) ( $in['maxExecutionTime'] ?? '' );
		$max_exec_int = is_numeric( $max_exec ) ? (int) $max_exec : -1;
		if ( $max_exec_int > 0 && $max_exec_int < 20 ) {
			$impacts[] = array( 'key' => 'max_execution_time', 'function' => '', 'feature' => 'workers', 'severity' => 'warning', 'message' => sprintf( 'max_execution_time هاست %d ثانیه است؛ هر اجرای Worker فقط چند ثانیه کار می‌کند و همگام‌سازی محصولات کند پیش می‌رود (حداقل ۳۰ ثانیه پیشنهاد می‌شود).', $max_exec_int ) );
		}
		$memory_raw   = (string) ( $in['memoryLimit'] ?? '' );
		$memory_bytes = self::parse_bytes( $memory_raw );
		if ( $memory_bytes > 0 && $memory_bytes < 128 * 1024 * 1024 ) {
			$impacts[] = array( 'key' => 'memory_limit', 'function' => '', 'feature' => 'workers', 'severity' => 'warning', 'message' => sprintf( 'memory_limit هاست %s است؛ پردازش محصولات پرتنوع و تصاویر بزرگ ممکن است با خطای کمبود حافظه متوقف شود (حداقل 256M پیشنهاد می‌شود).', $memory_raw ) );
		}

		$lb_attempt = absint( $in['loopbackAttemptAt'] ?? 0 );
		$lb_success = absint( $in['loopbackSuccessAt'] ?? 0 );
		$loopback   = empty( $in['loopbackEnabled'] ) && array_key_exists( 'loopbackEnabled', $in ) ? 'disabled'
			: ( 0 === $lb_attempt ? 'unknown' : ( $lb_success > 0 && ( $now - $lb_success ) <= 86400 ? 'ok' : 'failing' ) );

		$disabled_all = isset( $in['disabledList'] ) && is_array( $in['disabledList'] ) ? array_values( array_map( 'strval', $in['disabledList'] ) ) : array();
		return array(
			'checkedAt'          => gmdate( 'c', $now ),
			'php'                => (string) ( $in['phpVersion'] ?? PHP_VERSION ),
			'sapi'               => (string) ( $in['sapi'] ?? PHP_SAPI ),
			'disabledFunctions'  => $disabled,
			'disabledFunctionsAll' => array_slice( $disabled_all, 0, 200 ),
			'missingExtensions'  => $missing,
			'imageEditor'        => ! empty( $exts['gd'] ) ? 'gd' : ( $imagick ? 'imagick' : 'none' ),
			'httpTransportSsl'   => $http_ssl,
			'openBasedir'        => '' !== $open_basedir,
			'openBasedirPaths'   => '' === $open_basedir ? array() : array_slice( array_map( array( __CLASS__, 'path_label' ), explode( PATH_SEPARATOR, $open_basedir ) ), 0, 10 ),
			'uploadsWritable'    => $in['uploadsWritable'] ?? null,
			'tempWritable'       => $in['tempWritable'] ?? null,
			'maxExecutionTime'   => $max_exec_int,
			'memoryLimit'        => $memory_raw,
			'memoryLimitBytes'   => $memory_bytes,
			'loopback'           => $loopback,
			'impacts'            => $impacts,
		);
	}

	/**
	 * Readiness items (only capabilities that actually impact a feature).
	 *
	 * @param array|null $report Report (null = cached).
	 * @return array<int,array{key:string,ok:bool,message:string}>
	 */
	public static function readiness_items( $report = null ) {
		$report = is_array( $report ) ? $report : self::get();
		$items  = array();
		foreach ( isset( $report['impacts'] ) && is_array( $report['impacts'] ) ? $report['impacts'] : array() as $impact ) {
			$items[] = array(
				'key'      => 'env_' . sanitize_key( (string) $impact['key'] ),
				'ok'       => false,
				'severity' => (string) $impact['severity'],
				'message'  => (string) $impact['message'],
			);
		}
		return $items;
	}

	/**
	 * Compact impact list for diagnosticsSummary.disabledFunctionsImpact.
	 *
	 * @param array|null $report Report.
	 * @return array<int,array{key:string,feature:string,severity:string}>
	 */
	public static function impact_summary( $report = null ) {
		$report = is_array( $report ) ? $report : self::get();
		$out = array();
		foreach ( isset( $report['impacts'] ) && is_array( $report['impacts'] ) ? $report['impacts'] : array() as $impact ) {
			$out[] = array( 'key' => (string) $impact['key'], 'feature' => (string) $impact['feature'], 'severity' => (string) $impact['severity'] );
		}
		return $out;
	}

	/**
	 * Bytes of a php.ini size value ('-1' => -1).
	 *
	 * @param string $raw Raw.
	 * @return int
	 */
	public static function parse_bytes( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return 0;
		}
		if ( '-1' === $raw ) {
			return -1;
		}
		$unit  = strtolower( substr( $raw, -1 ) );
		$value = (float) $raw;
		switch ( $unit ) {
			case 'g':
				$value *= 1024;
				// Fall through.
			case 'm':
				$value *= 1024;
				// Fall through.
			case 'k':
				$value *= 1024;
		}
		return (int) $value;
	}

	/**
	 * open_basedir entries without leaking full server paths.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function path_label( $path ) {
		$path = rtrim( str_replace( '\\', '/', trim( (string) $path ) ), '/' );
		$parts = explode( '/', $path );
		return count( $parts ) > 2 ? '…/' . implode( '/', array_slice( $parts, -2 ) ) : $path;
	}
}
