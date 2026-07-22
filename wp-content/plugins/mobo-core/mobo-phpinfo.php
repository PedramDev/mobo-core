<?php
/**
 * Protected phpinfo viewer for Mobo Core support diagnostics.
 *
 * This file never exposes phpinfo to anonymous users. It loads WordPress,
 * requires an authenticated administrator and validates a short-lived nonce.
 */

$directory = __DIR__;
$wp_load   = '';

for ( $level = 0; $level < 6; $level++ ) {
	$candidate = rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . 'wp-load.php';
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
	http_response_code( 500 );
	exit( 'WordPress bootstrap was not found.' );
}

require_once $wp_load;

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
	status_header( 403 );
	nocache_headers();
	exit( 'Access denied.' );
}

$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
if ( ! wp_verify_nonce( $nonce, 'mobo_core_phpinfo' ) ) {
	status_header( 403 );
	nocache_headers();
	exit( 'Invalid or expired security token.' );
}

nocache_headers();
header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
header( 'Content-Security-Policy: frame-ancestors \'self\'' );
header( 'X-Content-Type-Options: nosniff', true );

require_once __DIR__ . '/includes/class-mobo-core-php-capabilities.php';
$catalog            = Mobo_Core_Php_Capabilities::get_function_catalog();
$phpinfo_capability = Mobo_Core_Php_Capabilities::inspect_function(
    'phpinfo',
    isset( $catalog['phpinfo'] ) ? $catalog['phpinfo'] : array()
);

if ( empty( $phpinfo_capability['available'] ) ) {
    status_header( 501 );
    header( 'Content-Type: text/plain; charset=utf-8', true );
    echo "phpinfo() is unavailable.\n";
    echo 'Status: ' . ( isset( $phpinfo_capability['status'] ) ? $phpinfo_capability['status'] : 'unavailable' ) . "\n";
    echo "The hosting provider may have disabled this function. Mobo Core's main runtime does not require phpinfo().\n";
    exit;
}

phpinfo( INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES );
