<?php
/**
 * Cryptographic single-installation binding for Portal requests.
 *
 * A 2048-bit RSA keypair is generated once per WordPress installation. The
 * private key never leaves WordPress. Portal actively challenges the licensed
 * WordPress WebHook, stores only the proven public key, and then verifies every
 * licensed request.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Portal_Request_Signer {

	const OPTION_INSTALLATION_ID          = 'mobo_core_installation_id';
	const OPTION_PRIVATE_KEY              = 'mobo_core_installation_private_key';
	const OPTION_PUBLIC_KEY               = 'mobo_core_installation_public_key';
	const OPTION_KEY_FINGERPRINT          = 'mobo_core_installation_key_fingerprint';
	const SIGNATURE_VERSION               = '1';

	/** @var array|null */
	private static $identity_cache = null;

	/**
	 * Add installation proof headers to one Portal request.
	 *
	 * @param string $method HTTP method.
	 * @param string $url Full Portal URL.
	 * @param string $body Raw request body exactly as sent.
	 * @param array  $headers Existing headers.
	 * @return array|WP_Error
	 */
	public static function sign_headers( $method, $url, $body = '', $headers = array() ) {
		$method  = strtoupper( trim( (string) $method ) );
		$url     = esc_url_raw( (string) $url );
		$body    = (string) $body;
		$headers = is_array( $headers ) ? $headers : array();

		if ( '' === $url || '' === $method ) {
			return new WP_Error( 'mobo_core_installation_sign_invalid_request', 'Portal request could not be signed because method or URL is missing.' );
		}

		if ( ! function_exists( 'openssl_pkey_new' ) || ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'mobo_core_installation_openssl_missing', 'افزونه OpenSSL در PHP برای قفل رمزنگاری‌شده لایسنس لازم است.' );
		}

		$token = self::license_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$identity = self::get_or_create_identity();
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$site_domain = self::normalized_site_domain();
		if ( '' === $site_domain ) {
			return new WP_Error( 'mobo_core_installation_site_missing', 'دامنه WordPress برای امضای درخواست Portal قابل تشخیص نیست.' );
		}

		$timestamp = time();
		$nonce     = bin2hex( random_bytes( 24 ) );
		$body_hash = hash( 'sha256', $body );
		$target    = self::request_target( $url );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$canonical = self::build_request_canonical(
			$method,
			$target,
			$timestamp,
			$nonce,
			$identity['installationId'],
			$token,
			$site_domain,
			$body_hash
		);
		$signature = self::rsa_sign( $canonical, $identity['privateKey'] );
		if ( is_wp_error( $signature ) ) {
			return $signature;
		}

		$headers['X-Mobo-Signature-Version'] = self::SIGNATURE_VERSION;
		$headers['X-Mobo-Install-Id']         = $identity['installationId'];
		$headers['X-Mobo-Site']               = home_url( '/' );
		$headers['X-Mobo-Timestamp']          = (string) $timestamp;
		$headers['X-Mobo-Nonce']              = $nonce;
		$headers['X-Mobo-Content-SHA256']     = $body_hash;
		$headers['X-Mobo-Signature']          = $signature;

		return $headers;
	}


	/**
	 * Sign a Portal-originated challenge delivered to this exact WordPress site.
	 * The endpoint is separately protected by X-SEC.
	 *
	 * @param string $challenge Random Portal challenge.
	 * @param string $expected_installation_id Installation ID requested by Portal.
	 * @return array|WP_Error
	 */
	public static function create_challenge_response( $challenge, $expected_installation_id = '' ) {
		$challenge = strtolower( trim( (string) $challenge ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $challenge ) ) {
			return new WP_Error( 'mobo_core_installation_challenge_invalid', 'Installation challenge is invalid.', array( 'status' => 400 ) );
		}

		$identity = self::get_or_create_identity();
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$expected_installation_id = strtolower( trim( (string) $expected_installation_id ) );
		if ( '' !== $expected_installation_id && ( ! self::is_uuid( $expected_installation_id ) || ! hash_equals( $identity['installationId'], $expected_installation_id ) ) ) {
			return new WP_Error( 'mobo_core_installation_challenge_mismatch', 'Portal challenge targets a different installation.', array( 'status' => 409 ) );
		}

		$site_domain = self::normalized_site_domain();
		$canonical = implode( "\n", array(
			'MOBO-INSTALL-CHALLENGE-V1',
			$identity['installationId'],
			$site_domain,
			$challenge,
			$identity['fingerprint'],
		) );
		$signature = self::rsa_sign( $canonical, $identity['privateKey'] );
		if ( is_wp_error( $signature ) ) {
			return $signature;
		}

		return array(
			'installationId' => $identity['installationId'],
			'siteUrl' => home_url( '/' ),
			'keyFingerprint' => $identity['fingerprint'],
			'publicKeyPem' => $identity['publicKey'],
			'challenge' => $challenge,
			'signature' => $signature,
		);
	}

	/**
	 * Return a non-secret status for Health/Admin diagnostics.
	 *
	 * @return array
	 */
	public static function get_status() {
		$id          = trim( (string) get_option( self::OPTION_INSTALLATION_ID, '' ) );
		$fingerprint = trim( (string) get_option( self::OPTION_KEY_FINGERPRINT, '' ) );
		return array(
			'installationId'   => $id,
			'keyFingerprint'   => $fingerprint,
			'identityReady'     => self::is_uuid( $id ) && 1 === preg_match( '/^[a-f0-9]{64}$/i', $fingerprint ),
			'opensslAvailable'  => function_exists( 'openssl_pkey_new' ) && function_exists( 'openssl_sign' ),
		);
	}

	/**
	 * Generate or load the durable installation identity.
	 *
	 * @return array|WP_Error
	 */
	private static function get_or_create_identity() {
		if ( is_array( self::$identity_cache ) ) {
			return self::$identity_cache;
		}

		$existing = self::read_complete_identity();
		if ( is_array( $existing ) ) {
			self::$identity_cache = $existing;
			return self::$identity_cache;
		}

		/*
		 * Reuse MoboCore's compare-and-swap runtime lock. This closes the
		 * concurrent first-request race without inventing a second lock format.
		 * During an active upgrade barrier the lock may be temporarily refused;
		 * Portal will simply retry its installation challenge later.
		 */
		$lock_token = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'installation_keygen', 30 ) : false;
		if ( false === $lock_token ) {
			for ( $attempt = 0; $attempt < 25; $attempt++ ) {
				usleep( 100000 );
				$existing = self::read_complete_identity();
				if ( is_array( $existing ) ) {
					self::$identity_cache = $existing;
					return self::$identity_cache;
				}
			}
			return new WP_Error( 'mobo_core_installation_keygen_busy', 'ساخت شناسه رمزنگاری‌شده نصب توسط درخواست دیگری یا مانع آپدیت در حال انجام است؛ اجرای بعدی دوباره تلاش می‌کند.' );
		}

		try {
			/* Re-check after acquiring the lock in case another owner just finished. */
			$existing = self::read_complete_identity();
			if ( is_array( $existing ) ) {
				self::$identity_cache = $existing;
				return self::$identity_cache;
			}
			$material = self::generate_rsa_material();
			if ( is_wp_error( $material ) ) {
				return $material;
			}

			$key         = $material['key'];
			$new_private = $material['privateKey'];
			$details     = $material['details'];

			$new_public      = (string) $details['key'];
			$new_fingerprint = self::public_key_fingerprint( $new_public );
			if ( is_wp_error( $new_fingerprint ) ) {
				return $new_fingerprint;
			}

			$new_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::fallback_uuid4();
			if ( ! self::is_uuid( $new_id ) ) {
				return new WP_Error( 'mobo_core_installation_id_failed', 'ساخت شناسه نصب MoboCore ناموفق بود.' );
			}

			update_option( self::OPTION_INSTALLATION_ID, strtolower( $new_id ), false );
			update_option( self::OPTION_PRIVATE_KEY, $new_private, false );
			update_option( self::OPTION_PUBLIC_KEY, $new_public, false );
			update_option( self::OPTION_KEY_FINGERPRINT, $new_fingerprint, false );

			$written = self::read_complete_identity();
			if ( ! is_array( $written ) ) {
				return new WP_Error( 'mobo_core_installation_identity_persist_failed', 'ذخیره پایدار شناسه رمزنگاری‌شده نصب MoboCore ناموفق بود.' );
			}

			self::$identity_cache = $written;
			return self::$identity_cache;
		} finally {
			Mobo_Core_Lock::release( 'installation_keygen', $lock_token );
		}
	}

	/**
	 * Read a complete, internally consistent persisted identity.
	 *
	 * @return array|null
	 */
	private static function read_complete_identity() {
		$installation_id = trim( (string) get_option( self::OPTION_INSTALLATION_ID, '' ) );
		$private_key     = (string) get_option( self::OPTION_PRIVATE_KEY, '' );
		$public_key      = (string) get_option( self::OPTION_PUBLIC_KEY, '' );
		$fingerprint     = strtolower( trim( (string) get_option( self::OPTION_KEY_FINGERPRINT, '' ) ) );

		if ( ! self::is_uuid( $installation_id ) || '' === trim( $private_key ) || '' === trim( $public_key ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return null;
		}

		$computed = self::public_key_fingerprint( $public_key );
		if ( is_wp_error( $computed ) || ! hash_equals( $fingerprint, $computed ) ) {
			return null;
		}

		/* Verify the private key corresponds to the stored public key. */
		$probe = 'mobo-installation-key-consistency-v1';
		$sig   = self::rsa_sign( $probe, $private_key );
		if ( is_wp_error( $sig ) || 1 !== openssl_verify( $probe, base64_decode( $sig, true ), $public_key, OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}

		return array(
			'installationId' => strtolower( $installation_id ),
			'privateKey'     => $private_key,
			'publicKey'      => $public_key,
			'fingerprint'    => $fingerprint,
		);
	}

	/**
	 * Generate and export one complete RSA identity material set.
	 *
	 * An attempt is accepted only when:
	 * - RSA-2048 key generation succeeds,
	 * - private-key export succeeds,
	 * - public-key details are available.
	 *
	 * Apache/FastCGI and PHP CLI can resolve different OpenSSL configuration
	 * locations on Windows/WAMP. The successful generation context is therefore
	 * retained for export, and readable config candidates are also tried as
	 * export contexts before a generated key is discarded.
	 *
	 * @return array|WP_Error
	 */
	private static function generate_rsa_material() {
		if (
			! function_exists( 'openssl_pkey_new' ) ||
			! function_exists( 'openssl_pkey_export' ) ||
			! function_exists( 'openssl_pkey_get_details' ) ||
			! defined( 'OPENSSL_KEYTYPE_RSA' )
		) {
			return new WP_Error(
				'mobo_core_installation_openssl_missing',
				'افزونه OpenSSL در PHP برای قفل رمزنگاری‌شده لایسنس لازم است.',
				array( 'status' => 500 )
			);
		}

		$base = array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		);

		$config_files = self::openssl_config_candidates();
		$generation_attempts = array(
			array(
				'label'      => 'runtime-default',
				'configArgs' => $base,
				'configFile' => '',
			),
		);

		foreach ( $config_files as $config_file ) {
			$generation_attempts[] = array(
				'label'      => 'explicit-config',
				'configArgs' => array_merge( $base, array( 'config' => $config_file ) ),
				'configFile' => $config_file,
			);
		}

		$diagnostic_errors = array();
		$saw_generated_key = false;

		foreach ( $generation_attempts as $generation_attempt ) {
			self::drain_openssl_errors();

			$key = @openssl_pkey_new( $generation_attempt['configArgs'] );
			if ( false === $key ) {
				foreach ( self::drain_openssl_errors() as $openssl_error ) {
					$diagnostic_errors[] = $generation_attempt['label'] . '/new: ' . $openssl_error;
					if ( count( $diagnostic_errors ) >= 16 ) {
						break 2;
					}
				}
				continue;
			}

			$saw_generated_key = true;
			$details = @openssl_pkey_get_details( $key );
			if ( ! is_array( $details ) || empty( $details['key'] ) ) {
				foreach ( self::drain_openssl_errors() as $openssl_error ) {
					$diagnostic_errors[] = $generation_attempt['label'] . '/details: ' . $openssl_error;
					if ( count( $diagnostic_errors ) >= 16 ) {
						break 2;
					}
				}
				continue;
			}

			/*
			 * Prefer the exact explicit config that created the key. Also try the
			 * runtime-default export and every other readable config because on
			 * Windows the export operation itself can require config resolution
			 * even after key generation succeeded.
			 */
			$export_contexts = array();

			if ( '' !== $generation_attempt['configFile'] ) {
				$export_contexts[] = array(
					'label'   => 'same-explicit-config',
					'options' => array( 'config' => $generation_attempt['configFile'] ),
				);
			}

			$export_contexts[] = array(
				'label'   => 'runtime-default',
				'options' => null,
			);

			foreach ( $config_files as $config_file ) {
				if ( '' !== $generation_attempt['configFile'] && $config_file === $generation_attempt['configFile'] ) {
					continue;
				}
				$export_contexts[] = array(
					'label'   => 'alternate-explicit-config',
					'options' => array( 'config' => $config_file ),
				);
			}

			foreach ( $export_contexts as $export_context ) {
				self::drain_openssl_errors();
				$new_private = '';

				if ( is_array( $export_context['options'] ) ) {
					$exported = @openssl_pkey_export( $key, $new_private, null, $export_context['options'] );
				} else {
					$exported = @openssl_pkey_export( $key, $new_private );
				}

				if ( $exported && '' !== trim( (string) $new_private ) ) {
					return array(
						'key'        => $key,
						'privateKey' => (string) $new_private,
						'details'    => $details,
					);
				}

				foreach ( self::drain_openssl_errors() as $openssl_error ) {
					$diagnostic_errors[] =
						$generation_attempt['label'] . '/export/' . $export_context['label'] . ': ' . $openssl_error;
					if ( count( $diagnostic_errors ) >= 16 ) {
						break 3;
					}
				}
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && class_exists( 'Mobo_Core_Logger' ) ) {
			$openssl_version = defined( 'OPENSSL_VERSION_TEXT' ) ? (string) OPENSSL_VERSION_TEXT : 'unknown';
			Mobo_Core_Logger::error(
				'Mobo Core installation RSA material generation failed. SAPI=' . PHP_SAPI .
				'; OpenSSL=' . $openssl_version .
				'; generationAttempts=' . count( $generation_attempts ) .
				'; configCandidates=' . count( $config_files ) .
				'; generatedKey=' . ( $saw_generated_key ? 'yes' : 'no' ) .
				'; errors=' . implode( ' | ', $diagnostic_errors )
			);
		}

		return new WP_Error(
			$saw_generated_key ? 'mobo_core_installation_key_export_failed' : 'mobo_core_installation_keygen_failed',
			$saw_generated_key
				? 'خروجی کلید نصب MoboCore قابل تولید نیست.'
				: 'ساخت کلید رمزنگاری‌شده نصب MoboCore ناموفق بود.',
			array( 'status' => 500 )
		);
	}

	/**
	 * Return existing, readable OpenSSL configuration candidates.
	 *
	 * No path is exposed through REST responses.
	 *
	 * @return array
	 */
	private static function openssl_config_candidates() {
		$candidates = array();

		$env_openssl = getenv( 'OPENSSL_CONF' );
		$env_ssleay  = getenv( 'SSLEAY_CONF' );
		if ( is_string( $env_openssl ) && '' !== trim( $env_openssl ) ) {
			$candidates[] = $env_openssl;
		}
		if ( is_string( $env_ssleay ) && '' !== trim( $env_ssleay ) ) {
			$candidates[] = $env_ssleay;
		}

		$ini_file = php_ini_loaded_file();
		if ( is_string( $ini_file ) && '' !== trim( $ini_file ) ) {
			$ini_dir      = dirname( $ini_file );
			$candidates[] = $ini_dir . DIRECTORY_SEPARATOR . 'openssl.cnf';
			$candidates[] = dirname( $ini_dir ) . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'openssl.cnf';
			$candidates[] = $ini_dir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
		}

		if ( defined( 'PHP_BINARY' ) && is_string( PHP_BINARY ) && '' !== trim( PHP_BINARY ) ) {
			$binary_dir   = dirname( PHP_BINARY );
			$candidates[] = $binary_dir . DIRECTORY_SEPARATOR . 'openssl.cnf';
			$candidates[] = $binary_dir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
			$candidates[] = dirname( $binary_dir ) . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'openssl.cnf';
		}

		$extension_dir = (string) ini_get( 'extension_dir' );
		if ( '' !== trim( $extension_dir ) ) {
			$php_dir      = dirname( $extension_dir );
			$candidates[] = $php_dir . DIRECTORY_SEPARATOR . 'openssl.cnf';
			$candidates[] = $php_dir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
		}

		$candidates[] = '/etc/ssl/openssl.cnf';
		$candidates[] = '/etc/pki/tls/openssl.cnf';
		$candidates[] = '/usr/local/ssl/openssl.cnf';

		$result = array();
		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' === $candidate || ! is_file( $candidate ) || ! is_readable( $candidate ) ) {
				continue;
			}

			$real = realpath( $candidate );
			$real = false === $real ? $candidate : $real;
			if ( ! in_array( $real, $result, true ) ) {
				$result[] = $real;
			}
		}

		return $result;
	}

	/**
	 * Drain OpenSSL's per-thread error queue.
	 *
	 * @return array
	 */
	private static function drain_openssl_errors() {
		$errors = array();
		if ( ! function_exists( 'openssl_error_string' ) ) {
			return $errors;
		}

		while ( count( $errors ) < 16 ) {
			$error = openssl_error_string();
			if ( false === $error ) {
				break;
			}
			$errors[] = sanitize_text_field( (string) $error );
		}

		return $errors;
	}

	private static function license_token() {
		$token = trim( (string) get_option( 'mobo_core_token', '' ) );
		if ( ! self::is_uuid( $token ) ) {
			return new WP_Error( 'mobo_core_installation_missing_license_token', 'Token معتبر برای امضای درخواست Portal ثبت نشده است.' );
		}
		return strtolower( $token );
	}

	private static function normalized_site_domain() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = is_string( $host ) ? strtoupper( rtrim( trim( $host ), '.' ) ) : '';
		return $host;
	}


	private static function request_target( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return new WP_Error( 'mobo_core_installation_invalid_request_url', 'Portal request URL is invalid.' );
		}
		$path = isset( $parts['path'] ) && '' !== (string) $parts['path'] ? (string) $parts['path'] : '/';
		return $path . ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '' );
	}

	private static function build_request_canonical( $method, $target, $timestamp, $nonce, $installation_id, $token, $site_domain, $body_hash ) {
		return implode(
			"\n",
			array(
				'MOBO-REQUEST-V1',
				strtoupper( trim( (string) $method ) ),
				(string) $target,
				(string) absint( $timestamp ),
				strtolower( trim( (string) $nonce ) ),
				strtolower( trim( (string) $installation_id ) ),
				strtolower( trim( (string) $token ) ),
				strtoupper( trim( (string) $site_domain ) ),
				strtolower( trim( (string) $body_hash ) ),
			)
		);
	}


	private static function rsa_sign( $canonical, $private_key ) {
		$ok = openssl_sign( (string) $canonical, $signature, (string) $private_key, OPENSSL_ALGO_SHA256 );
		if ( ! $ok ) {
			return new WP_Error( 'mobo_core_installation_sign_failed', 'امضای رمزنگاری‌شده درخواست Portal ناموفق بود.' );
		}
		return base64_encode( $signature );
	}

	private static function public_key_fingerprint( $public_key ) {
		$base64 = preg_replace( '/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', (string) $public_key );
		$der    = base64_decode( (string) $base64, true );
		if ( false === $der || '' === $der ) {
			return new WP_Error( 'mobo_core_installation_public_key_invalid', 'کلید عمومی installation معتبر نیست.' );
		}
		return hash( 'sha256', $der );
	}

	private static function is_uuid( $value ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim( (string) $value ) );
	}

	private static function fallback_uuid4() {
		$data = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		$hex = bin2hex( $data );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}
}
