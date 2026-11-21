<?php
/**
 * Minimal WordPress stubs for standalone MoboCore policy tests.
 * No network, no database: $wpdb is a scripted fake per test.
 *
 * PHP 7.4 compatible.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['mobo_test_options'] = array();
$GLOBALS['mobo_test_filters'] = array();
$GLOBALS['mobo_test_failures'] = 0;
$GLOBALS['mobo_test_count'] = 0;

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['mobo_test_options'] ) ? $GLOBALS['mobo_test_options'][ $name ] : $default;
}
function update_option( $name, $value ) { $GLOBALS['mobo_test_options'][ $name ] = $value; return true; }
function apply_filters( $tag, $value ) {
	return isset( $GLOBALS['mobo_test_filters'][ $tag ] ) ? call_user_func( $GLOBALS['mobo_test_filters'][ $tag ], $value ) : $value;
}
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function trailingslashit( $value ) { return rtrim( (string) $value, '/\\' ) . '/'; }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
function esc_url_raw( $url, $protocols = null ) {
	$url = trim( (string) $url );
	if ( '' === $url ) { return ''; }
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	$allowed = is_array( $protocols ) ? $protocols : array( 'http', 'https' );
	return in_array( $scheme, $allowed, true ) ? $url : '';
}
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
function wp_json_encode( $value ) { return json_encode( $value ); }

class Mobo_Core_Settings {
	public static function normalize_security_code( $value ) { return trim( (string) $value ); }
	public static function is_valid_security_code( $value ) { return '' !== $value && (bool) preg_match( '/^[\x21-\x7e]+$/', $value ); }
	public static function normalize_opaque_secret( $value, $max = 191 ) {
		$value = trim( (string) $value );
		return strlen( $value ) <= $max ? $value : '';
	}
	public static function get( $key, $default = '' ) { return get_option( $key, $default ); }
}

function mobo_assert( $condition, $label ) {
	$GLOBALS['mobo_test_count']++;
	if ( $condition ) {
		echo "  ok   - {$label}\n";
		return;
	}
	$GLOBALS['mobo_test_failures']++;
	echo "  FAIL - {$label}\n";
}

function mobo_assert_same( $expected, $actual, $label ) {
	$ok = $expected === $actual;
	mobo_assert( $ok, $label . ( $ok ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( is_wp_error( $actual ) ? 'WP_Error:' . $actual->get_error_code() : $actual, true ) . ')' ) );
}

function mobo_assert_error( $code, $actual, $label ) {
	$ok = is_wp_error( $actual ) && $code === $actual->get_error_code();
	mobo_assert( $ok, $label . ( $ok ? '' : ' (expected WP_Error ' . $code . ', got ' . var_export( is_wp_error( $actual ) ? 'WP_Error:' . $actual->get_error_code() : $actual, true ) . ')' ) );
}

function mobo_finish() {
	echo "\n" . $GLOBALS['mobo_test_count'] . ' assertions, ' . $GLOBALS['mobo_test_failures'] . " failures (PHP " . PHP_VERSION . ")\n";
	exit( $GLOBALS['mobo_test_failures'] > 0 ? 1 : 0 );
}
