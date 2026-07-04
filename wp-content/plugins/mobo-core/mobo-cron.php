<?php
/**
 * Mobo Core local PHP cron runner.
 *
 * Use this file on restricted cPanel hosts where cron only accepts a PHP script
 * path and blocks wget/curl/shell operators.
 *
 * Recommended cPanel target:
 * /home/USER/public_html/wp-content/plugins/mobo-core/mobo-cron.php
 *
 * If full commands are allowed:
 * /usr/local/bin/php -q /home/USER/public_html/wp-content/plugins/mobo-core/mobo-cron.php
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}

if ( ! defined( 'DOING_CRON' ) ) {
	define( 'DOING_CRON', true );
}

if ( ! defined( 'MOBO_CORE_LOCAL_PHP_CRON' ) ) {
	define( 'MOBO_CORE_LOCAL_PHP_CRON', true );
}

@ignore_user_abort( true );
@set_time_limit( 0 );

$is_http_request = ! empty( $_SERVER['HTTP_HOST'] ) || ! empty( $_SERVER['REQUEST_METHOD'] );

/**
 * Locate wp-load.php by walking upward from this plugin directory.
 * This avoids hard-coding public_html paths because cPanel accounts may use
 * custom document roots or addon-domain directories.
 */
$dir     = __DIR__;
$wp_load = '';

for ( $i = 0; $i < 10; $i++ ) {
	$candidate = $dir . '/wp-load.php';

	if ( is_file( $candidate ) ) {
		$wp_load = $candidate;
		break;
	}

	$parent = dirname( $dir );

	if ( $parent === $dir ) {
		break;
	}

	$dir = $parent;
}

if ( '' === $wp_load ) {
	if ( PHP_SAPI === 'cli' ) {
		fwrite( STDERR, "Mobo Core cron failed: wp-load.php was not found.\n" );
	} else {
		header( 'Content-Type: text/plain; charset=utf-8' );
		http_response_code( 500 );
		echo 'Mobo Core cron failed: wp-load.php was not found.';
	}
	exit( 1 );
}

require_once $wp_load;

/*
 * If this file is called through the web by mistake, require the same cron token
 * used by the REST cron endpoint. Local cPanel PHP cron execution does not need
 * a token because it runs the file directly on the server.
 */
if ( $is_http_request ) {
	$expected = (string) get_option( 'mobo_core_cron_token', '' );
	$provided = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

	if ( '' === $provided && ! empty( $_SERVER['HTTP_X_SEC'] ) ) {
		$provided = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SEC'] ) );
	}

	if ( '' === trim( $expected ) || '' === $provided || ! hash_equals( $expected, $provided ) ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		http_response_code( 403 );
		echo 'Forbidden.';
		exit( 1 );
	}
}

if ( ! class_exists( 'Mobo_Core_Cron_Runner' ) ) {
	if ( PHP_SAPI === 'cli' ) {
		fwrite( STDERR, "Mobo Core cron failed: Mobo Core plugin is inactive or not loaded.\n" );
	} else {
		header( 'Content-Type: text/plain; charset=utf-8' );
		http_response_code( 500 );
		echo 'Mobo Core cron failed: Mobo Core plugin is inactive or not loaded.';
	}
	exit( 1 );
}

$runner = new Mobo_Core_Cron_Runner();
$result = $runner->run( 'cpanel-local-php-cron' );

if ( ! headers_sent() ) {
	header( 'Content-Type: application/json; charset=utf-8' );
}

if ( function_exists( 'wp_json_encode' ) ) {
	echo wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
} else {
	echo json_encode( $result );
}

echo PHP_EOL;

if ( empty( $result['success'] ) ) {
	exit( 1 );
}

exit( 0 );
