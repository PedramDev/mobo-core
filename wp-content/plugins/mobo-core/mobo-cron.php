<?php
/**
 * Mobo Core local PHP cron runner.
 *
 * Use this file on restricted cPanel hosts where cron only accepts a PHP script
 * path and blocks wget/curl/shell operators.
 *
 * Recommended cPanel PHP Script target, every 1 minute:
 * /home/USER/public_html/wp-content/plugins/mobo-core/mobo-cron.php
 *
 * If full commands are allowed, run this command every 1 minute:
 * /usr/local/bin/php -q /home/USER/public_html/wp-content/plugins/mobo-core/mobo-cron.php
 *
 * PHP 7.4 compatible.
 */

$mobo_core_cron_base_ob_level = ob_get_level();
ob_start();

@ini_set( 'display_errors', '0' );
@ini_set( 'html_errors', '0' );
error_reporting( E_ALL );

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

/**
 * Encode JSON even when WordPress has not been loaded yet.
 *
 * @param array $payload Payload.
 * @return string
 */
function mobo_core_cron_json_encode( $payload ) {
	$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	if ( defined( 'JSON_PARTIAL_OUTPUT_ON_ERROR' ) ) {
		$flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
	}

	if ( function_exists( 'wp_json_encode' ) ) {
		$json = wp_json_encode( $payload, $flags );
	} else {
		$json = json_encode( $payload, $flags );
	}

	if ( ! is_string( $json ) || '' === $json ) {
		$json = '{"success":false,"status":"json-encode-failed","message":"Cron result could not be JSON encoded."}';
	}

	return $json;
}

/**
 * Return compact text for diagnostics without breaking JSON output.
 *
 * @param string $text Raw text.
 * @param int    $max  Max length.
 * @return string
 */
function mobo_core_cron_compact_output( $text, $max = 2000 ) {
	$text = trim( (string) $text );

	if ( '' === $text ) {
		return '';
	}

	if ( function_exists( 'wp_strip_all_tags' ) ) {
		$text = wp_strip_all_tags( $text );
	} else {
		$text = strip_tags( $text );
	}
	$text = preg_replace( '/\s+/', ' ', $text );

	if ( ! is_string( $text ) ) {
		$text = '';
	}

	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $text, 0, $max );
	}

	return substr( $text, 0, $max );
}

/**
 * Emit a clean JSON response. Any accidental output captured from WordPress or
 * other plugins is discarded from stdout and preserved inside diagnostics.
 *
 * @param array  $payload       Payload.
 * @param int    $exit_code     Process exit code.
 * @param string $extra_output  Extra captured output.
 * @return void
 */
function mobo_core_cron_emit_json( $payload, $exit_code = 0, $extra_output = '' ) {
	$captured = (string) $extra_output;
	$base     = isset( $GLOBALS['mobo_core_cron_base_ob_level'] ) ? max( 0, (int) $GLOBALS['mobo_core_cron_base_ob_level'] ) : 0;

	while ( ob_get_level() > $base ) {
		$chunk = ob_get_clean();

		if ( false !== $chunk && '' !== trim( (string) $chunk ) ) {
			$captured .= "\n" . $chunk;
		}
	}

	$captured = mobo_core_cron_compact_output( $captured );

	if ( '' !== $captured ) {
		if ( ! isset( $payload['diagnostics'] ) || ! is_array( $payload['diagnostics'] ) ) {
			$payload['diagnostics'] = array();
		}

		$payload['diagnostics']['strayOutputDiscarded'] = true;
		$payload['diagnostics']['strayOutputPreview']   = $captured;
	}

	if ( ! isset( $payload['executedAt'] ) ) {
		$payload['executedAt'] = time();
	}

	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8' );
	}

	echo mobo_core_cron_json_encode( $payload );
	echo PHP_EOL;
	exit( max( 0, (int) $exit_code ) );
}

/**
 * Emit a JSON-formatted cron failure.
 *
 * @param string $message   Message.
 * @param string $status    Status key.
 * @param int    $exit_code Exit code.
 * @param array  $extra     Extra fields.
 * @return void
 */
function mobo_core_cron_fail( $message, $status = 'failed', $exit_code = 1, $extra = array() ) {
	$payload = array_merge(
		array(
			'success' => false,
			'status'  => function_exists( 'sanitize_key' ) ? sanitize_key( (string) $status ) : preg_replace( '/[^a-z0-9_\-]/i', '', strtolower( (string) $status ) ),
			'message' => (string) $message,
		),
		is_array( $extra ) ? $extra : array()
	);

	mobo_core_cron_emit_json( $payload, $exit_code );
}

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
	mobo_core_cron_fail(
		'Mobo Core cron failed: wp-load.php was not found.',
		'wp-load-not-found',
		1,
		array( 'pluginDir' => __DIR__ )
	);
}

try {
	require_once $wp_load;
} catch ( Throwable $e ) {
	mobo_core_cron_fail(
		'Mobo Core cron failed while loading WordPress: ' . $e->getMessage(),
		'wp-load-exception',
		1,
		array(
			'exceptionClass' => get_class( $e ),
			'file'           => $e->getFile(),
			'line'           => $e->getLine(),
		)
	);
}

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
		mobo_core_cron_fail( 'Forbidden.', 'forbidden', 1 );
	}
}

if ( ! class_exists( 'Mobo_Core_Cron_Runner' ) ) {
	mobo_core_cron_fail(
		'Mobo Core cron failed: Mobo Core plugin is inactive or not loaded.',
		'plugin-not-loaded',
		1
	);
}

$runner = new Mobo_Core_Cron_Runner();
$result = $runner->run( 'cpanel-local-php-cron' );

/*
 * Keep the legacy/admin "Worker" timestamp in sync for local PHP cron too.
 * The real source of truth remains mobo_core_real_cron_last_hit_at, but many
 * users look at the Worker card first.
 */
if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
	try {
		Mobo_Core_Self_Runner::record_run_result( $result );
	} catch ( Throwable $e ) {
		if ( ! isset( $result['diagnostics'] ) || ! is_array( $result['diagnostics'] ) ) {
			$result['diagnostics'] = array();
		}

		$result['diagnostics']['selfRunnerRecordError'] = $e->getMessage();
	}
}

mobo_core_cron_emit_json( $result, empty( $result['success'] ) ? 1 : 0 );
