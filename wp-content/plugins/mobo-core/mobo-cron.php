<?php
/**
 * Mobo Core local PHP cron runner.
 *
 * Intended for cPanel hosts that accept a PHP script path as a cron target.
 * HTTP access is supported only when the configured cron token is supplied.
 *
 * @package MoboCore
 */

/**
 * Locate and load WordPress when this script is invoked directly by PHP CLI.
 *
 * @return void
 */
function mobo_core_cron_bootstrap_wordpress() {
	if ( defined( 'ABSPATH' ) ) {
		return;
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
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8', true, 500 );
		}
		echo '{"success":false,"status":"wp-load-not-found","message":"WordPress bootstrap file was not found."}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed JSON error response.
		exit( 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI process status, not page output.
	}

	require_once $wp_load;
}

mobo_core_cron_bootstrap_wordpress();

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DOING_CRON' ) ) {
	define( 'DOING_CRON', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core cron context constant.
}

if ( ! defined( 'MOBO_CORE_LOCAL_PHP_CRON' ) ) {
	define( 'MOBO_CORE_LOCAL_PHP_CRON', true );
}

/**
 * Normalize accidental output for diagnostics.
 *
 * @param string $text Raw text.
 * @param int    $max Maximum length.
 * @return string
 */
function mobo_core_cron_compact_output( $text, $max = 2000 ) {
	$text = trim( wp_strip_all_tags( (string) $text ) );
	$text = preg_replace( '/\s+/', ' ', $text );
	$text = is_string( $text ) ? $text : '';
	$max  = max( 1, absint( $max ) );

	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
}

/**
 * Emit a JSON response and stop execution.
 *
 * @param array  $payload Payload.
 * @param int    $exit_code Process exit code.
 * @param string $captured     Captured output.
 * @param int    $base_ob_level Output-buffer level that must remain open.
 * @param int    $http_status   HTTP status code.
 * @return void
 */
function mobo_core_cron_emit_json( $payload, $exit_code = 0, $captured = '', $base_ob_level = 0, $http_status = 0 ) {
	$base_ob_level = max( 0, absint( $base_ob_level ) );
	while ( ob_get_level() > $base_ob_level ) {
		$chunk = ob_get_clean();
		if ( false !== $chunk && '' !== trim( (string) $chunk ) ) {
			$captured .= "\n" . $chunk;
		}
	}

	$captured = mobo_core_cron_compact_output( $captured );
	if ( '' !== $captured ) {
		$payload['diagnostics'] = isset( $payload['diagnostics'] ) && is_array( $payload['diagnostics'] ) ? $payload['diagnostics'] : array();
		$payload['diagnostics']['strayOutputDiscarded'] = true;
		$payload['diagnostics']['strayOutputPreview']   = $captured;
	}

	$payload['executedAt'] = isset( $payload['executedAt'] ) ? absint( $payload['executedAt'] ) : time();
	$status_code           = $http_status > 0 ? absint( $http_status ) : ( empty( $payload['success'] ) ? 500 : 200 );

	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8', true, $status_code );
	}

	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON API response.
	exit( max( 0, absint( $exit_code ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI process status, not page output.
}

/**
 * Run the cron worker.
 *
 * @return void
 */
function mobo_core_cron_run() {
	$base_ob_level = ob_get_level();
	ob_start();

	$is_http_request = isset( $_SERVER['REQUEST_METHOD'] ) && '' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
	if ( $is_http_request ) {
		$expected = (string) get_option( 'mobo_core_cron_token', '' );
		$provided = '';

		// Token authentication is used instead of a nonce because this is a machine-to-machine cron endpoint.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['token'] ) ) {
			$provided = sanitize_text_field( wp_unslash( $_GET['token'] ) );
		} elseif ( isset( $_SERVER['HTTP_X_SEC'] ) ) {
			$provided = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SEC'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === trim( $expected ) || '' === $provided || ! hash_equals( $expected, $provided ) ) {
			mobo_core_cron_emit_json( array( 'success' => false, 'status' => 'forbidden', 'message' => 'Forbidden.' ), 1, '', $base_ob_level, 403 );
		}
	}

	if ( ! class_exists( 'Mobo_Core_Cron_Runner' ) ) {
		mobo_core_cron_emit_json( array( 'success' => false, 'status' => 'plugin-not-loaded', 'message' => 'Mobo Core is inactive or unavailable.' ), 1, '', $base_ob_level, 503 );
	}

	$runner = new Mobo_Core_Cron_Runner();
	$result = $runner->run( 'cpanel-local-php-cron' );

	if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
		try {
			Mobo_Core_Self_Runner::record_run_result( $result );
		} catch ( Throwable $exception ) {
			$result['diagnostics'] = isset( $result['diagnostics'] ) && is_array( $result['diagnostics'] ) ? $result['diagnostics'] : array();
			$result['diagnostics']['selfRunnerRecordError'] = mobo_core_cron_compact_output( $exception->getMessage(), 500 );
		}
	}

	mobo_core_cron_emit_json( $result, empty( $result['success'] ) ? 1 : 0, '', $base_ob_level );
}

mobo_core_cron_run();
