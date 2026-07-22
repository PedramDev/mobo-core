<?php
/**
 * Direct authenticated WordPress health endpoint that does not depend on REST rewrites.
 *
 * Request header: X-SEC: <configured webhook security code>
 */

function mobo_core_healthcheck_json( $payload, $status = 200 ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8', true, (int) $status );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );
	}
	echo json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
}

$directory = __DIR__;
$wp_load   = '';
for ( $level = 0; $level < 10; $level++ ) {
	$candidate = $directory . '/wp-load.php';
	if ( is_file( $candidate ) ) {
		$wp_load = $candidate;
		break;
	}
	$parent = dirname( $directory );
	if ( $parent === $directory ) {
		break;
	}
	$directory = $parent;
}
if ( '' === $wp_load ) {
	mobo_core_healthcheck_json( array( 'success' => false, 'status' => 'wp-load-not-found' ), 503 );
}

require_once $wp_load;

try {
	if ( ! class_exists( 'Mobo_Core_Settings' ) || ! class_exists( 'Mobo_Core_Health_Reporter' ) ) {
		mobo_core_healthcheck_json( array( 'success' => false, 'status' => 'plugin-not-loaded' ), 503 );
	}
	$expected = Mobo_Core_Settings::normalize_security_code( get_option( 'mobo_core_security_code', '' ) );
	$provided = isset( $_SERVER['HTTP_X_SEC'] ) ? (string) $_SERVER['HTTP_X_SEC'] : '';
	if ( '' === $expected || '' === $provided || ! hash_equals( $expected, $provided ) ) {
		mobo_core_healthcheck_json( array( 'success' => false, 'status' => 'unauthorized' ), 401 );
	}
	$reporter = new Mobo_Core_Health_Reporter();
	$data = $reporter->get_local_status();
	$data['endpoint'] = 'direct-healthcheck';
	mobo_core_healthcheck_json( $data, 200 );
} catch ( Throwable $error ) {
	mobo_core_healthcheck_json(
		array(
			'success' => false,
			'status'  => 'healthcheck-exception',
			'message' => $error->getMessage(),
			'class'   => get_class( $error ),
		),
		500
	);
}
