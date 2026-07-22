<?php
/**
 * Safe PHP capability inspection and runtime-probe authentication cache.
 *
 * This file intentionally has no ABSPATH guard because mobo-runtime-probe.php
 * loads it without bootstrapping WordPress.
 */

class Mobo_Core_Php_Capabilities {

	const PROBE_SCHEMA_VERSION = 1;
	const AUTH_FILE_NAME       = 'runtime-probe-auth.php';

	/**
	 * Register WordPress hooks and refresh the probe authentication cache.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'updated_option', array( __CLASS__, 'handle_option_change' ), 10, 3 );
		add_action( 'added_option', array( __CLASS__, 'handle_option_add' ), 10, 2 );
		add_action( 'deleted_option', array( __CLASS__, 'handle_option_delete' ), 10, 1 );
		self::sync_runtime_probe_auth();
	}

	/**
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 * @return void
	 */
	public static function handle_option_change( $option, $old_value, $value ) {
		unset( $old_value, $value );
		if ( 'mobo_core_security_code' === (string) $option ) {
			self::sync_runtime_probe_auth( true );
		}
	}

	/**
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public static function handle_option_add( $option, $value ) {
		unset( $value );
		if ( 'mobo_core_security_code' === (string) $option ) {
			self::sync_runtime_probe_auth( true );
		}
	}

	/**
	 * @param string $option Option name.
	 * @return void
	 */
	public static function handle_option_delete( $option ) {
		if ( 'mobo_core_security_code' === (string) $option ) {
			self::remove_runtime_probe_auth();
		}
	}

	/**
	 * Return the audited function catalog.
	 *
	 * required levels:
	 * - critical: Mobo Core cannot reliably operate without it.
	 * - warning: a feature/fallback is unavailable.
	 * - info: diagnostic or optional system execution capability.
	 *
	 * @return array
	 */
	public static function get_function_catalog() {
		return array(
			'json_encode'             => array( 'category' => 'core', 'required' => 'critical', 'extension' => 'json', 'impact' => 'ساخت payloadهای API و گزارش سلامت ممکن نیست.' ),
			'json_decode'             => array( 'category' => 'core', 'required' => 'critical', 'extension' => 'json', 'impact' => 'خواندن پاسخ‌های API و داده‌های صف ممکن نیست.' ),
			'hash'                    => array( 'category' => 'security', 'required' => 'critical', 'extension' => 'hash', 'impact' => 'Hash و کنترل یکپارچگی قابل انجام نیست.' ),
			'hash_hmac'               => array( 'category' => 'security', 'required' => 'critical', 'extension' => 'hash', 'impact' => 'امضای HMAC و برخی کنترل‌های امنیتی قابل انجام نیست.' ),
			'hash_equals'             => array( 'category' => 'security', 'required' => 'critical', 'extension' => 'hash', 'impact' => 'مقایسه امن Tokenها قابل انجام نیست.' ),
			'random_bytes'            => array( 'category' => 'security', 'required' => 'critical', 'extension' => 'standard', 'impact' => 'تولید Token امن با اختلال روبه‌رو می‌شود.' ),
			'openssl_verify'          => array( 'category' => 'security', 'required' => 'warning', 'extension' => 'openssl', 'impact' => 'اعتبارسنجی فایل تنظیمات امضاشده در دسترس نیست.' ),
			'openssl_pkey_get_public' => array( 'category' => 'security', 'required' => 'warning', 'extension' => 'openssl', 'impact' => 'Public Key تنظیمات امضاشده قابل بارگذاری نیست.' ),

			'fopen'                   => array( 'category' => 'filesystem', 'required' => 'critical', 'extension' => 'standard', 'impact' => 'فایل‌های صف، Lock و داده‌های Runtime قابل بازشدن نیستند.' ),
			'fwrite'                  => array( 'category' => 'filesystem', 'required' => 'critical', 'extension' => 'standard', 'impact' => 'نوشتن فایل‌های صف و Runtime ممکن نیست.' ),
			'file_put_contents'       => array( 'category' => 'filesystem', 'required' => 'critical', 'extension' => 'standard', 'impact' => 'نوشتن اتمیک داده‌ها و فایل احراز هویت Probe ممکن نیست.' ),
			'file_get_contents'       => array( 'category' => 'filesystem', 'required' => 'warning', 'extension' => 'standard', 'impact' => 'خواندن برخی فایل‌ها و fallbackهای HTTP ممکن نیست.' ),
			'rename'                  => array( 'category' => 'filesystem', 'required' => 'critical', 'extension' => 'standard', 'impact' => 'جایگزینی اتمیک فایل‌ها ممکن نیست.' ),
			'unlink'                  => array( 'category' => 'filesystem', 'required' => 'warning', 'extension' => 'standard', 'impact' => 'پاک‌سازی فایل‌های قدیمی و موقت محدود می‌شود.' ),
			'flock'                   => array( 'category' => 'filesystem', 'required' => 'critical', 'extension' => 'standard', 'impact' => 'قفل فایل و جلوگیری از اجرای هم‌زمان قابل اتکا نیست.' ),
			'disk_free_space'         => array( 'category' => 'filesystem', 'required' => 'warning', 'extension' => 'standard', 'impact' => 'فضای آزاد دیسک در گزارش سلامت نامشخص می‌شود.' ),
			'disk_total_space'        => array( 'category' => 'filesystem', 'required' => 'warning', 'extension' => 'standard', 'impact' => 'ظرفیت کل دیسک در گزارش سلامت نامشخص می‌شود.' ),

			'curl_init'               => array( 'category' => 'network', 'required' => 'warning', 'extension' => 'curl', 'impact' => 'WordPress باید از transport جایگزین برای HTTP استفاده کند.' ),
			'curl_exec'               => array( 'category' => 'network', 'required' => 'warning', 'extension' => 'curl', 'impact' => 'Transport مبتنی بر cURL در دسترس نیست.' ),
			'fsockopen'               => array( 'category' => 'network', 'required' => 'warning', 'extension' => 'standard', 'impact' => 'بخشی از fallbackهای اتصال Socket در دسترس نیست.' ),
			'stream_socket_client'    => array( 'category' => 'network', 'required' => 'warning', 'extension' => 'standard', 'impact' => 'fallback اتصال Stream Socket در دسترس نیست.' ),

			'imagewebp'               => array( 'category' => 'image', 'required' => 'warning', 'extension' => 'gd', 'impact' => 'GD نمی‌تواند WebP تولید کند؛ Imagick ممکن است جایگزین باشد.' ),
			'imagecreatefromwebp'     => array( 'category' => 'image', 'required' => 'warning', 'extension' => 'gd', 'impact' => 'GD نمی‌تواند فایل WebP را بخواند.' ),
			'imagecreatefromjpeg'     => array( 'category' => 'image', 'required' => 'warning', 'extension' => 'gd', 'impact' => 'GD نمی‌تواند JPEG را برای تبدیل تصویر بخواند.' ),
			'imagecreatefrompng'      => array( 'category' => 'image', 'required' => 'warning', 'extension' => 'gd', 'impact' => 'GD نمی‌تواند PNG را برای تبدیل تصویر بخواند.' ),
			'getimagesize'            => array( 'category' => 'image', 'required' => 'warning', 'extension' => 'standard', 'impact' => 'تشخیص ابعاد و نوع برخی تصاویر ممکن نیست.' ),

			'gzencode'                => array( 'category' => 'compression', 'required' => 'warning', 'extension' => 'zlib', 'impact' => 'فشرده‌سازی فایل پشتیبانی و گزارش کامل ممکن نیست.' ),
			'gzdecode'                => array( 'category' => 'compression', 'required' => 'warning', 'extension' => 'zlib', 'impact' => 'بازکردن برخی داده‌های فشرده ممکن نیست.' ),
			'phpinfo'                 => array( 'category' => 'diagnostics', 'required' => 'info', 'extension' => 'standard', 'impact' => 'فقط صفحه کامل phpinfo در دسترس نخواهد بود؛ عملکرد اصلی افزونه مختل نمی‌شود.' ),
			'ini_get'                 => array( 'category' => 'diagnostics', 'required' => 'warning', 'extension' => 'standard', 'impact' => 'بخشی از تنظیمات PHP و disable_functions قابل گزارش نیست.' ),

			'exec'                    => array( 'category' => 'system', 'required' => 'info', 'extension' => 'standard', 'impact' => 'Mobo Core به‌طور معمول به اجرای Command سیستم نیاز ندارد.' ),
			'shell_exec'              => array( 'category' => 'system', 'required' => 'info', 'extension' => 'standard', 'impact' => 'Mobo Core به‌طور معمول به Shell نیاز ندارد.' ),
			'system'                  => array( 'category' => 'system', 'required' => 'info', 'extension' => 'standard', 'impact' => 'Mobo Core به‌طور معمول به اجرای Command سیستم نیاز ندارد.' ),
			'passthru'                => array( 'category' => 'system', 'required' => 'info', 'extension' => 'standard', 'impact' => 'Mobo Core به‌طور معمول به اجرای Command سیستم نیاز ندارد.' ),
			'proc_open'               => array( 'category' => 'system', 'required' => 'info', 'extension' => 'standard', 'impact' => 'Mobo Core به‌طور معمول Process خارجی ایجاد نمی‌کند.' ),
			'popen'                   => array( 'category' => 'system', 'required' => 'info', 'extension' => 'standard', 'impact' => 'Mobo Core به‌طور معمول Process خارجی ایجاد نمی‌کند.' ),
		);
	}

	/**
	 * Read functions explicitly disabled by PHP/hosting configuration.
	 *
	 * @return array
	 */
	public static function get_disabled_functions() {
		$values = array();
		if ( function_exists( 'ini_get' ) ) {
			$values[] = (string) ini_get( 'disable_functions' );
			$values[] = (string) ini_get( 'suhosin.executor.func.blacklist' );
		}

		$disabled = array();
		foreach ( $values as $raw ) {
			foreach ( explode( ',', $raw ) as $name ) {
				$name = strtolower( trim( $name ) );
				if ( '' !== $name ) {
					$disabled[ $name ] = true;
				}
			}
		}

		$disabled = array_keys( $disabled );
		sort( $disabled, SORT_STRING );
		return $disabled;
	}

	/**
	 * Inspect one function without executing it.
	 *
	 * @param string $function_name Function name.
	 * @param array  $meta          Catalog metadata.
	 * @param array  $disabled      Disabled list.
	 * @return array
	 */
	public static function inspect_function( $function_name, $meta = array(), $disabled = null ) {
		$name     = strtolower( trim( (string) $function_name ) );
		$disabled = is_array( $disabled ) ? $disabled : self::get_disabled_functions();
		$listed   = in_array( $name, $disabled, true );
		$exists   = function_exists( $name );
		$callable = $exists && is_callable( $name );
		$status   = 'available';

		if ( $listed ) {
			$status   = 'disabled-by-host';
			$exists   = false;
			$callable = false;
		} elseif ( ! $exists || ! $callable ) {
			$status = 'unavailable';
		}

		$required = isset( $meta['required'] ) ? (string) $meta['required'] : 'info';
		$severity = 'ok';
		if ( 'available' !== $status ) {
			$severity = 'critical' === $required ? 'critical' : ( 'warning' === $required ? 'warning' : 'info' );
		}

		$extension        = isset( $meta['extension'] ) ? (string) $meta['extension'] : '';
		$extension_loaded = '' === $extension || 'standard' === $extension || ! function_exists( 'extension_loaded' ) ? null : extension_loaded( $extension );

		return array(
			'name'              => $name,
			'category'          => isset( $meta['category'] ) ? (string) $meta['category'] : 'other',
			'status'            => $status,
			'available'         => 'available' === $status,
			'callable'          => $callable,
			'listedAsDisabled'  => $listed,
			'requiredLevel'     => $required,
			'severity'          => $severity,
			'extension'         => $extension,
			'extensionLoaded'   => $extension_loaded,
			'impact'            => isset( $meta['impact'] ) ? (string) $meta['impact'] : '',
		);
	}

	/**
	 * Build the safe capability report.
	 *
	 * @param bool $include_extensions Include loaded extension names.
	 * @param bool $redact_paths       Do not include filesystem paths.
	 * @return array
	 */
	public static function get_report( $include_extensions = true, $redact_paths = false ) {
		$catalog   = self::get_function_catalog();
		$disabled  = self::get_disabled_functions();
		$functions = array();
		$counts    = array( 'available' => 0, 'disabledByHost' => 0, 'configuredDisabled' => count( $disabled ), 'unavailable' => 0, 'critical' => 0, 'warning' => 0, 'info' => 0 );

		foreach ( $catalog as $name => $meta ) {
			$result             = self::inspect_function( $name, $meta, $disabled );
			$functions[ $name ] = $result;
			if ( 'available' === $result['status'] ) {
				$counts['available']++;
			} elseif ( 'disabled-by-host' === $result['status'] ) {
				$counts['disabledByHost']++;
			} else {
				$counts['unavailable']++;
			}
			if ( 'ok' !== $result['severity'] ) {
				$counts[ $result['severity'] ]++;
			}
		}

		$extensions = array();
		if ( $include_extensions && function_exists( 'get_loaded_extensions' ) ) {
			$extensions = get_loaded_extensions();
			sort( $extensions, SORT_NATURAL | SORT_FLAG_CASE );
		}

		$ini_file        = '';
		$ini_file_loaded = null;
		if ( function_exists( 'php_ini_loaded_file' ) ) {
			$loaded          = php_ini_loaded_file();
			$ini_file_loaded = false !== $loaded;
			if ( ! $redact_paths && false !== $loaded ) {
				$ini_file = (string) $loaded;
			}
		}

		return array(
			'schemaVersion'       => self::PROBE_SCHEMA_VERSION,
			'checkedAt'           => gmdate( 'c' ),
			'phpVersion'          => PHP_VERSION,
			'sapi'                => PHP_SAPI,
			'os'                  => defined( 'PHP_OS_FAMILY' ) ? PHP_OS_FAMILY : PHP_OS,
			'iniFile'             => $ini_file,
			'iniFileLoaded'       => $ini_file_loaded,
			'disabledFunctions'   => $disabled,
			'functions'           => $functions,
			'counts'              => $counts,
			'extensions'          => array_values( $extensions ),
			'phpinfoAvailable'    => isset( $functions['phpinfo'] ) && ! empty( $functions['phpinfo']['available'] ),
			'coreRuntimeHealthy'  => 0 === $counts['critical'],
		);
	}

	/**
	 * Build runtime probe status for WordPress health reports/admin UI.
	 *
	 * @return array
	 */
	public static function get_runtime_probe_status() {
		$path = self::get_runtime_probe_auth_path();
		return array(
			'enabled'        => true,
			'authCacheReady' => '' !== $path && is_file( $path ) && is_readable( $path ),
			'endpointUrl'    => defined( 'MOBO_CORE_PLUGIN_URL' ) ? MOBO_CORE_PLUGIN_URL . 'mobo-runtime-probe.php' : '',
			'authMode'       => 'x-sec-sha256',
			'wordpressFree'  => true,
		);
	}

	/**
	 * Synchronize hashed X-SEC data used by the WordPress-free runtime probe.
	 *
	 * @param bool $force Force rewrite.
	 * @return array
	 */
	public static function sync_runtime_probe_auth( $force = false ) {
		if ( ! function_exists( 'get_option' ) ) {
			return array( 'success' => false, 'status' => 'wordpress-unavailable' );
		}

		if ( ! function_exists( 'hash' ) ) {
			return array( 'success' => false, 'status' => 'hash-unavailable' );
		}
		if ( ! function_exists( 'file_put_contents' ) || ! function_exists( 'rename' ) ) {
			return array( 'success' => false, 'status' => 'filesystem-functions-unavailable' );
		}

		$security_code = trim( (string) get_option( 'mobo_core_security_code', '' ) );
		if ( class_exists( 'Mobo_Core_Settings' ) ) {
			$security_code = Mobo_Core_Settings::normalize_security_code( $security_code );
		}
		if ( '' === $security_code ) {
			self::remove_runtime_probe_auth();
			return array( 'success' => false, 'status' => 'missing-security-code' );
		}

		$path = self::get_runtime_probe_auth_path();
		if ( '' === $path ) {
			return array( 'success' => false, 'status' => 'uploads-unavailable' );
		}

		$directory = dirname( $path );
		if ( ! is_dir( $directory ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $directory );
			} elseif ( function_exists( 'mkdir' ) ) {
				@mkdir( $directory, 0750, true );
			}
		}
		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			return array( 'success' => false, 'status' => 'directory-not-writable' );
		}

		$payload = array(
			'schemaVersion'      => self::PROBE_SCHEMA_VERSION,
			'securityCodeSha256' => hash( 'sha256', $security_code ),
			'pluginVersion'      => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
			'siteUrlSha256'      => function_exists( 'home_url' ) ? hash( 'sha256', strtolower( rtrim( (string) home_url( '/' ), '/' ) ) ) : '',
			'updatedAt'          => time(),
		);

		if ( ! $force && is_file( $path ) ) {
			$current = @include $path;
			if ( is_array( $current ) && isset( $current['securityCodeSha256'], $current['pluginVersion'] ) && self::safe_equals( (string) $current['securityCodeSha256'], $payload['securityCodeSha256'] ) && (string) $current['pluginVersion'] === $payload['pluginVersion'] ) {
				return array( 'success' => true, 'status' => 'current', 'path' => $path );
			}
		}

		$content = "<?php\n// Generated by Mobo Core. Direct requests produce no output.\nreturn " . var_export( $payload, true ) . ";\n";
		$temp    = $path . '.tmp-' . substr( hash( 'sha256', uniqid( '', true ) ), 0, 12 );
		if ( false === @file_put_contents( $temp, $content, LOCK_EX ) ) {
			return array( 'success' => false, 'status' => 'write-failed' );
		}
		if ( function_exists( 'chmod' ) ) {
			@chmod( $temp, 0640 );
		}
		if ( ! @rename( $temp, $path ) ) {
			if ( function_exists( 'unlink' ) ) {
				@unlink( $temp );
			}
			return array( 'success' => false, 'status' => 'atomic-rename-failed' );
		}

		self::write_directory_protection( $directory );
		return array( 'success' => true, 'status' => 'written', 'path' => $path );
	}

	/**
	 * @return string
	 */
	public static function get_runtime_probe_auth_path() {
		if ( defined( 'MOBO_CORE_RUNTIME_PROBE_AUTH_FILE' ) && '' !== trim( (string) MOBO_CORE_RUNTIME_PROBE_AUTH_FILE ) ) {
			return (string) MOBO_CORE_RUNTIME_PROBE_AUTH_FILE;
		}
		if ( defined( 'MOBO_CORE_DATA_DIR' ) && '' !== trim( (string) MOBO_CORE_DATA_DIR ) ) {
			return rtrim( (string) MOBO_CORE_DATA_DIR, '/\\' ) . DIRECTORY_SEPARATOR . self::AUTH_FILE_NAME;
		}
		if ( function_exists( 'wp_upload_dir' ) ) {
			$upload = wp_upload_dir( null, false );
			if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
				return rtrim( (string) $upload['basedir'], '/\\' ) . DIRECTORY_SEPARATOR . 'mobo-core' . DIRECTORY_SEPARATOR . self::AUTH_FILE_NAME;
			}
		}
		return '';
	}

	/**
	 * Remove generated runtime-probe auth cache.
	 *
	 * @return void
	 */
	public static function remove_runtime_probe_auth() {
		$path = self::get_runtime_probe_auth_path();
		if ( '' !== $path && is_file( $path ) && function_exists( 'unlink' ) ) {
			@unlink( $path );
		}
	}

	/**
	 * Timing-safe equality with a local fallback when hash_equals is disabled.
	 *
	 * @param string $known Known value.
	 * @param string $user  User value.
	 * @return bool
	 */
	private static function safe_equals( $known, $user ) {
		$known = (string) $known;
		$user  = (string) $user;
		if ( function_exists( 'hash_equals' ) ) {
			return hash_equals( $known, $user );
		}
		if ( strlen( $known ) !== strlen( $user ) ) {
			return false;
		}
		$result = 0;
		for ( $i = 0, $length = strlen( $known ); $i < $length; $i++ ) {
			$result |= ord( $known[ $i ] ) ^ ord( $user[ $i ] );
		}
		return 0 === $result;
	}

	private static function write_directory_protection( $directory ) {
		$files = array(
			'.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></system.webServer></configuration>",
			'index.php'  => "<?php\nhttp_response_code( 404 );\nexit;\n",
		);
		foreach ( $files as $name => $content ) {
			$path = rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . $name;
			if ( ! is_file( $path ) ) {
				@file_put_contents( $path, $content, LOCK_EX );
			}
		}
	}
}
