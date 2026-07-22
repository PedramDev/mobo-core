<?php
/**
 * WordPress-free PHP runtime probe for Mobo Core.
 *
 * Authentication uses the webhook X-SEC value. Mobo Core stores only its
 * SHA-256 digest in a protected uploads file, so this endpoint can operate even
 * when WordPress bootstrap fails.
 */

define( 'MOBO_CORE_RUNTIME_PROBE_VERSION', '10.31.77' );
require_once __DIR__ . '/includes/class-mobo-core-php-capabilities.php';

/**
 * @param array $payload Payload.
 * @param int   $status  HTTP status.
 * @return void
 */
function mobo_core_runtime_probe_emit( $payload, $status ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8', true, $status );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'Pragma: no-cache', true );
		header( 'Expires: 0', true );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'Referrer-Policy: no-referrer', true );
	}
	if ( function_exists( 'json_encode' ) ) {
		echo json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	} else {
		echo '{"success":false,"status":"json-unavailable"}';
	}
	exit;
}

/**
 * Timing-safe equality fallback for hosts that disabled hash_equals().
 *
 * @param string $known Known value.
 * @param string $user  User value.
 * @return bool
 */
function mobo_core_runtime_probe_equals( $known, $user ) {
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

/**
 * Resolve the generated auth-cache file without loading WordPress.
 *
 * @return string
 */
function mobo_core_runtime_probe_auth_file() {
	$custom = getenv( 'MOBO_CORE_RUNTIME_PROBE_AUTH_FILE' );
	if ( is_string( $custom ) && '' !== trim( $custom ) ) {
		return $custom;
	}

	$content_dir = dirname( dirname( __DIR__ ) );
	$candidates  = array(
		$content_dir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'mobo-core' . DIRECTORY_SEPARATOR . Mobo_Core_Php_Capabilities::AUTH_FILE_NAME,
		$content_dir . DIRECTORY_SEPARATOR . 'mobo-core-data' . DIRECTORY_SEPARATOR . Mobo_Core_Php_Capabilities::AUTH_FILE_NAME,
	);
	foreach ( $candidates as $candidate ) {
		if ( is_file( $candidate ) && is_readable( $candidate ) ) {
			return $candidate;
		}
	}
	return '';
}

if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
	mobo_core_runtime_probe_emit( array( 'success' => false, 'status' => 'method-not-allowed' ), 405 );
}

$auth_file = mobo_core_runtime_probe_auth_file();
if ( '' === $auth_file ) {
	mobo_core_runtime_probe_emit(
		array(
			'success' => false,
			'status'  => 'auth-cache-unavailable',
			'message' => 'Runtime probe authentication cache is unavailable. Open any WordPress page after saving X-SEC, or verify uploads permissions.',
		),
		503
	);
}

$auth = @include $auth_file;
if ( ! is_array( $auth ) || empty( $auth['securityCodeSha256'] ) ) {
	mobo_core_runtime_probe_emit( array( 'success' => false, 'status' => 'auth-cache-invalid' ), 503 );
}

$provided = '';
if ( isset( $_SERVER['HTTP_X_SEC'] ) ) {
	$provided = trim( (string) $_SERVER['HTTP_X_SEC'] );
} elseif ( isset( $_SERVER['HTTP_X_MOBO_HEALTH_SECRET'] ) ) {
	$provided = trim( (string) $_SERVER['HTTP_X_MOBO_HEALTH_SECRET'] );
}

if ( '' === $provided || ! function_exists( 'hash' ) ) {
	mobo_core_runtime_probe_emit( array( 'success' => false, 'status' => 'unauthorized' ), 401 );
}

$provided_hash = hash( 'sha256', $provided );
if ( ! mobo_core_runtime_probe_equals( (string) $auth['securityCodeSha256'], $provided_hash ) ) {
	mobo_core_runtime_probe_emit( array( 'success' => false, 'status' => 'forbidden' ), 403 );
}

$capabilities = Mobo_Core_Php_Capabilities::get_report( true, true );
mobo_core_runtime_probe_emit(
	array(
		'success'          => true,
		'status'           => ! empty( $capabilities['coreRuntimeHealthy'] ) ? 'ok' : 'critical-capability-missing',
		'probeType'        => 'php-runtime',
		'schemaVersion'    => Mobo_Core_Php_Capabilities::PROBE_SCHEMA_VERSION,
		'pluginVersion'    => MOBO_CORE_RUNTIME_PROBE_VERSION,
		'wordpressLoaded'  => false,
		'phpReached'       => true,
		'checkedAt'        => gmdate( 'c' ),
		'phpCapabilities'  => $capabilities,
	),
	! empty( $capabilities['coreRuntimeHealthy'] ) ? 200 : 503
);
