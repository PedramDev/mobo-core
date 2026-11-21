<?php
/**
 * Minimal WordPress stubs for the environment / remote-diagnostics harnesses.
 * No WordPress, no database, no network. Options can be persisted to a JSON
 * file ($GLOBALS['__opts_file']) so subprocess tests can inspect what a child
 * wrote before it died. Uses no function that the disabled-function children
 * disable. PHP 7.4 compatible.
 */

error_reporting( E_ALL );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['__opts']      = isset( $GLOBALS['__opts'] ) ? $GLOBALS['__opts'] : array();
$GLOBALS['__opts_file'] = isset( $GLOBALS['__opts_file'] ) ? $GLOBALS['__opts_file'] : '';
$GLOBALS['__actions']   = array();
$GLOBALS['__filters']   = array();
$GLOBALS['__writes']    = 0;
$GLOBALS['__t_pass']    = 0;
$GLOBALS['__t_fail']    = 0;

function t_opts_save() {
	if ( '' !== $GLOBALS['__opts_file'] ) {
		file_put_contents( $GLOBALS['__opts_file'], json_encode( $GLOBALS['__opts'] ) );
	}
}
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['__opts'][ $name ] = $value; $GLOBALS['__writes']++; t_opts_save(); return true; }
function add_option( $name, $value, $dep = '', $autoload = null ) { if ( array_key_exists( $name, $GLOBALS['__opts'] ) ) { return false; } return update_option( $name, $value ); }
function delete_option( $name ) { unset( $GLOBALS['__opts'][ $name ] ); t_opts_save(); return true; }
function get_site_option( $name, $default = false ) { return get_option( $name, $default ); }
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__actions'][ $tag ][] = $cb; return true; }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__filters'][ $tag ][] = $cb; return true; }
function do_action( $tag ) { $args = array_slice( func_get_args(), 1 ); foreach ( isset( $GLOBALS['__actions'][ $tag ] ) ? $GLOBALS['__actions'][ $tag ] : array() as $cb ) { call_user_func_array( $cb, $args ); } }
function apply_filters( $tag, $value ) { return $value; }
function has_action( $tag ) { return ! empty( $GLOBALS['__actions'][ $tag ] ); }
function remove_action( $tag, $cb = null ) { return true; }
function remove_filter( $tag, $cb = null ) { return true; }
function register_activation_hook( $file, $cb ) { return true; }
function register_deactivation_hook( $file, $cb ) { return true; }
function plugin_dir_path( $file ) { return rtrim( str_replace( '\\', '/', dirname( $file ) ), '/' ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/mobo-core/'; }
function plugin_basename( $file ) { return 'mobo-core/mobo-core.php'; }
function is_admin() { return false; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_unslash( $v ) { return $v; }
function wp_json_encode( $v, $flags = 0, $depth = 512 ) { return json_encode( $v, $flags, $depth ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-4%03x-a%03x-%012x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xffffffffffff ) ); }
function wp_generate_password( $len = 12, $special = true, $extra = false ) { $c = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'; $o = ''; for ( $i = 0; $i < $len; $i++ ) { $o .= $c[ mt_rand( 0, strlen( $c ) - 1 ) ]; } return $o; }
function wp_list_pluck( $list, $field ) { $o = array(); foreach ( (array) $list as $k => $row ) { if ( is_array( $row ) && isset( $row[ $field ] ) ) { $o[ $k ] = $row[ $field ]; } } return $o; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function trailingslashit( $v ) { return rtrim( (string) $v, '/\\' ) . '/'; }
function untrailingslashit( $v ) { return rtrim( (string) $v, '/\\' ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_url_raw( $v, $p = null ) { return (string) $v; }
function esc_url( $v ) { return (string) $v; }
function __( $v, $d = '' ) { return $v; }
function esc_html__( $v, $d = '' ) { return $v; }
function is_multisite() { return false; }
function wp_cache_get( $k, $g = '' ) { return false; }
function wp_cache_set( $k, $v, $g = '', $e = 0 ) { return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $e = 0 ) { return true; }
function delete_transient( $k ) { return true; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function get_bloginfo( $k = '' ) { return '6.6'; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function rest_ensure_response( $v ) { return $v; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

function t_ok( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['__t_pass']++;
		echo "  ok   - {$label}\n";
	} else {
		$GLOBALS['__t_fail']++;
		echo "  FAIL - {$label}\n";
	}
}
function t_same( $expected, $actual, $label ) {
	$ok = $expected === $actual;
	t_ok( $ok, $label . ( $ok ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' ) );
}
function t_done() {
	$total = $GLOBALS['__t_pass'] + $GLOBALS['__t_fail'];
	echo "\n{$total} checks, {$GLOBALS['__t_fail']} failure(s) on PHP " . PHP_VERSION . "\n";
	exit( $GLOBALS['__t_fail'] > 0 ? 1 : 0 );
}
