<?php
/**
 * Shared minimal WordPress/WooCommerce stubs for the pricing / watchdog
 * developer tests. No network, no database: $wpdb is an in-memory fake that
 * understands only the Connection Settings and pricing-override sweeper
 * statements. Loads the REAL Mobo_Core_Settings / Connection_Settings /
 * Legacy_Rules so default handling is exercised end to end.
 *
 * PHP 7.4 compatible.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['t_options']   = array();
$GLOBALS['t_meta']      = array();
$GLOBALS['t_posts']     = array();
$GLOBALS['t_scheduled'] = array();
$GLOBALS['t_currency']  = 'IRT';
$GLOBALS['t_decimals']  = 0;
$GLOBALS['t_failures']  = 0;
$GLOBALS['t_checks']    = 0;

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_Post {
	public $ID; public $post_type = 'product'; public $post_parent = 0; public $post_name = ''; public $post_status = 'publish';
	public function __construct( $props ) { foreach ( $props as $k => $v ) { $this->$k = $v; } }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_email( $v ) { return trim( (string) $v ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function apply_filters( $tag, $value ) { return $value; }
function add_action() { return true; }
function add_filter() { return true; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-' . str_pad( (string) mt_rand( 1, 999999 ), 12, '0', STR_PAD_LEFT ); }
function maybe_serialize( $d ) { return ( is_array( $d ) || is_object( $d ) ) ? serialize( $d ) : $d; }
function maybe_unserialize( $d ) {
	if ( is_string( $d ) && preg_match( '/^(a|O|s|i|b|d|N):/', $d ) ) { $r = @unserialize( $d ); return ( false === $r && 'b:0;' !== $d ) ? $d : $r; }
	return $d;
}
function untrailingslashit( $v ) { return rtrim( (string) $v, '/\\' ); }
function trailingslashit( $v ) { return rtrim( (string) $v, '/\\' ) . '/'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['t_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['t_options'][ $name ] ); return true; }
function get_post_meta( $id, $key, $single = false ) {
	$v = isset( $GLOBALS['t_meta'][ $id ][ $key ] ) ? $GLOBALS['t_meta'][ $id ][ $key ] : '';
	return $single ? $v : ( '' === $v ? array() : array( $v ) );
}
function update_post_meta( $id, $key, $value ) { $GLOBALS['t_meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['t_meta'][ $id ][ $key ] ); return true; }
function get_post( $id ) { return isset( $GLOBALS['t_posts'][ $id ] ) ? $GLOBALS['t_posts'][ $id ] : null; }
function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function get_woocommerce_currency() { return $GLOBALS['t_currency']; }
function wc_get_price_decimals() { return (int) $GLOBALS['t_decimals']; }
function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['t_scheduled'] as $e ) { if ( $e['hook'] === $hook && $e['args'] === $args ) { return $e['ts']; } }
	return false;
}
function wp_schedule_single_event( $ts, $hook, $args = array(), $wp_error = false ) {
	$GLOBALS['t_scheduled'][] = array( 'ts' => $ts, 'hook' => $hook, 'args' => $args );
	return true;
}
function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$before = count( $GLOBALS['t_scheduled'] );
	$GLOBALS['t_scheduled'] = array_values( array_filter( $GLOBALS['t_scheduled'], static function ( $e ) use ( $hook, $args ) { return ! ( $e['hook'] === $hook && $e['args'] === $args ); } ) );
	return $before - count( $GLOBALS['t_scheduled'] );
}

/** Simplified wc_format_decimal: floats to plain decimal strings, optional dp. */
function wc_format_decimal( $number, $dp = false, $trim_zeros = false ) {
	if ( '' === $number || null === $number ) { return ''; }
	if ( is_float( $number ) || is_int( $number ) ) {
		$number = rtrim( rtrim( sprintf( '%.10F', (float) $number ), '0' ), '.' );
	}
	$number = str_replace( ',', '.', trim( (string) $number ) );
	if ( false !== $dp && is_numeric( $number ) ) {
		$number = number_format( (float) $number, (int) $dp, '.', '' );
		if ( $trim_zeros && false !== strpos( $number, '.' ) ) { $number = rtrim( rtrim( $number, '0' ), '.' ); }
	}
	return $number;
}

/** In-memory $wpdb for Connection Settings + the pricing override sweeper. */
final class Pricing_Fake_WPDB {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $postmeta = 'wp_postmeta';
	public $posts = 'wp_posts';
	public $last_error = '';
	public $rows = array();
	public $pending_meta_ids = array();
	public $entity_pending_ids = array();
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		return array( 'q' => $query, 'a' => $args );
	}
	public function esc_like( $t ) { return $t; }
	public function get_var( $p ) {
		$q = is_array( $p ) ? $p['q'] : (string) $p;
		$a = is_array( $p ) ? $p['a'] : array();
		if ( 0 === strpos( $q, 'SHOW TABLES LIKE' ) ) { return $a[0]; }
		if ( false !== strpos( $q, 'SELECT setting_value' ) ) { return isset( $this->rows[ (int) $a[0] ][ $a[1] ] ) ? $this->rows[ (int) $a[0] ][ $a[1] ] : null; }
		if ( false !== strpos( $q, 'SELECT id FROM' ) ) { return isset( $this->rows[ (int) $a[0] ][ $a[1] ] ) ? '1' : null; }
		return null;
	}
	public function query( $p ) {
		$q = is_array( $p ) ? $p['q'] : (string) $p;
		$a = is_array( $p ) ? $p['a'] : array();
		if ( 0 === strpos( trim( $q ), 'INSERT INTO' ) ) { $this->rows[ (int) $a[0] ][ $a[1] ] = $a[2]; return 1; }
		return 0;
	}
	public function delete( $table, $where ) {
		if ( isset( $where['setting_key'] ) ) { unset( $this->rows[ (int) $where['connection_id'] ][ $where['setting_key'] ] ); }
		elseif ( isset( $where['connection_id'] ) ) { unset( $this->rows[ (int) $where['connection_id'] ] ); }
		return 1;
	}
	public function get_col( $p ) {
		$q = is_array( $p ) ? $p['q'] : (string) $p;
		if ( false !== strpos( $q, 'FROM wp_postmeta' ) ) { return $this->pending_meta_ids; }
		if ( false !== strpos( $q, 'FROM wp_mobo_entity_meta' ) ) { return $this->entity_pending_ids; }
		return array();
	}
	public function get_results() { return array(); }
}
$GLOBALS['wpdb'] = new Pricing_Fake_WPDB();

final class Mobo_Core_Connection_Context {
	public static $id = 1;
	public static function get_effective_connection_id() { return self::$id; }
	public static function get_current_connection_id() { return self::$id; }
	public static function run( $id, $cb ) { $old = self::$id; self::$id = $id; try { return $cb(); } finally { self::$id = $old; } }
}

final class Mobo_Core_Connection_Repository {
	public static $rows = array();
	public static function get_by_id( $id ) { return isset( self::$rows[ $id ] ) ? self::$rows[ $id ] : null; }
	public static function get_by_id_fresh( $id ) { return self::get_by_id( $id ); }
	public static function get_runtime_connection() { return self::get_by_id( Mobo_Core_Connection_Context::$id ); }
	public static function get_default() { return self::get_by_id( 1 ); }
	public static function table_exists() { return false; }
	public static function list_enabled() { return array_values( self::$rows ); }
	public static function connection_ownership_ready() { return false; }
}

/** Connection-scoped state store (in memory). */
final class Mobo_Core_State_Store {
	public static $data = array();
	public static function is_available() { return true; }
	public static function get( $key, $default = null ) { $k = Mobo_Core_Connection_Context::$id . '|' . $key; return array_key_exists( $k, self::$data ) ? self::$data[ $k ] : $default; }
	public static function set( $key, $value ) { self::$data[ Mobo_Core_Connection_Context::$id . '|' . $key ] = $value; return true; }
	public static function delete( $key ) { unset( self::$data[ Mobo_Core_Connection_Context::$id . '|' . $key ] ); return true; }
}

function t_connection_row( $id, $unit, $digits = 0 ) {
	return array( 'id' => $id, 'is_enabled' => 1, 'is_default' => 1 === $id ? 1 : 0, 'money_unit' => $unit, 'money_fraction_digits' => $digits, 'money_price_representation' => 'major', 'money_contract_version' => 1 );
}

function t_reset() {
	$GLOBALS['t_options'] = array();
	$GLOBALS['t_meta'] = array();
	$GLOBALS['t_posts'] = array();
	$GLOBALS['t_scheduled'] = array();
	$GLOBALS['t_currency'] = 'IRT';
	$GLOBALS['t_decimals'] = 0;
	$GLOBALS['wpdb']->rows = array();
	$GLOBALS['wpdb']->pending_meta_ids = array();
	Mobo_Core_State_Store::$data = array();
	Mobo_Core_Connection_Context::$id = 1;
	Mobo_Core_Connection_Repository::$rows = array( 1 => t_connection_row( 1, 'IRT' ) );
	if ( class_exists( 'Mobo_Core_Connection_Settings', false ) ) { Mobo_Core_Connection_Settings::clear_cache(); }
}

function t_setting( $key, $value, $connection_id = 1 ) {
	Mobo_Core_Connection_Settings::set( $connection_id, $key, $value );
}

function t_check( $cond, $label ) {
	$GLOBALS['t_checks']++;
	if ( $cond ) { echo "  ok   - {$label}\n"; return; }
	$GLOBALS['t_failures']++;
	echo "  FAIL - {$label}\n";
}

function t_same( $expected, $actual, $label ) {
	$ok = $expected === $actual;
	t_check( $ok, $label . ( $ok ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' ) );
}

function t_done() {
	echo "\n" . $GLOBALS['t_checks'] . ' checks, ' . $GLOBALS['t_failures'] . ' failure(s) on PHP ' . PHP_VERSION . "\n";
	exit( $GLOBALS['t_failures'] > 0 ? 1 : 0 );
}

$t_inc = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
foreach ( array( 'settings', 'connection-settings', 'legacy-rules', 'money-policy', 'money-converter', 'money-target-settings',
	'money-price-input-plan', 'price-rounding', 'dynamic-price-rules', 'price-calculator', 'store-currency-guard',
	'product-exclusions', 'job-diagnostics' ) as $t_file ) {
	require_once $t_inc . 'class-mobo-core-' . $t_file . '.php';
}
t_reset();
