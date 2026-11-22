<?php
/**
 * B3 (forced-update staging 2026-11-22): Mobo_Core_State_Store::set() verified
 * its read-back with a type-strict comparison, so every scalar (int stored,
 * string read back) was reported as a failed write and every category snapshot
 * ended "incomplete" ("Category snapshot watermark could not be durably
 * committed."). Scalars now compare by canonical string form, arrays exact.
 *
 * Uses the real State_Store SQL against SQLite (ON DUPLICATE KEY UPDATE is
 * rewritten to the SQLite upsert) and the real category snapshot commit path.
 *
 * Run: php tests-dev/state-store-scalar-test.php   (PHP 7.4 and 8.x)
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
require __DIR__ . '/sqlite-wpdb-stub.php';

$GLOBALS['t_options'] = array();
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function is_serialized( $data ) { return is_string( $data ) && ( 'N;' === $data || (bool) preg_match( '/^[aOsibd]:/', $data ) ); }
function maybe_serialize( $data ) {
	if ( is_array( $data ) || is_object( $data ) ) { return serialize( $data ); }
	if ( is_serialized( $data ) ) { return serialize( $data ); }
	return $data;
}
function maybe_unserialize( $data ) { return is_serialized( $data ) ? @unserialize( $data ) : $data; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['t_options'][ $k ] ); return true; }
function wp_cache_delete() { return true; }

/** $wpdb: the shared SQLite stub plus the MySQL upsert used by State_Store::set(). */
final class T_State_WPDB {
	public $inner;
	public function __construct() {
		$this->inner = new Sqlite_Test_WPDB();
		$this->inner->pdo->exec( 'CREATE TABLE wp_mobo_state (id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER NOT NULL, state_key TEXT NOT NULL, state_value TEXT, value_hash TEXT, created_at TEXT, updated_at TEXT, UNIQUE (connection_id, state_key))' );
	}
	public function __get( $name ) { return $this->inner->$name; }
	public function __set( $name, $value ) { $this->inner->$name = $value; }
	public function query( $sql ) {
		$sql = preg_replace_callback(
			'/ON DUPLICATE KEY UPDATE\s+(.*)$/is',
			static function ( $m ) {
				return 'ON CONFLICT (connection_id, state_key) DO UPDATE SET ' . preg_replace( '/VALUES\((\w+)\)/i', 'excluded.$1', $m[1] );
			},
			$sql
		);
		return $this->inner->query( $sql );
	}
	public function __call( $name, $args ) { return call_user_func_array( array( $this->inner, $name ), $args ); }
}
$GLOBALS['wpdb'] = new T_State_WPDB();

final class Mobo_Core_Connection_Context {
	public static function get_effective_connection_id() { return 1; }
	public static function legacy_default_owner_allowed() { return true; }
}
final class Mobo_Core_Lock {
	public static $held = array();
	public static function acquire( $name, $ttl ) { if ( isset( self::$held[ $name ] ) ) { return false; } self::$held[ $name ] = 'tok-' . $name; return self::$held[ $name ]; }
	public static function renew( $name, $token, $ttl ) { return isset( self::$held[ $name ] ) && self::$held[ $name ] === $token; }
	public static function release( $name, $token ) { unset( self::$held[ $name ] ); return true; }
}
class Mobo_Core_Category_Map {
	public static function table_exists() { return true; }
}
final class Mobo_Core_Settings {
	public static function update( $k, $v, $autoload = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
	public static function get( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
}

$inc = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $inc . 'class-mobo-core-state-store.php';
require $inc . 'class-mobo-core-durable-state-policy.php';
require $inc . 'class-mobo-core-category-sync.php';

$failures = 0; $checks = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	$checks++;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $failures++; }
}

$S = 'Mobo_Core_State_Store';
global $wpdb;

echo "\n[1] State_Store::set() scalar read-back\n";
check( true === $S::is_available(), 'state table available' );
check( true === $S::set( 'k', 123 ), "set('k', 123) => true" );
check( '123' === $S::get( 'k' ), '  row written (read back as the string "123")' );
check( true === $S::set( 'k', 1795304300614842 ), 'set() with a microsecond watermark int => true' );
check( 1795304300614842 === absint( $S::get( 'k' ) ), '  upsert replaced the value' );
check( true === $S::set( 'f', 12.5 ), 'float => true' );
check( true === $S::set( 'b', true ) && '1' === $S::get( 'b' ), 'bool true => true ("1")' );
check( true === $S::set( 'b0', false ), 'bool false => true' );
check( true === $S::set( 's', 'abc' ) && 'abc' === $S::get( 's' ), 'string => true' );
check( true === $S::set( 'z', '0' ) && true === $S::set( 'z', 0 ), '"0" and 0 => true' );
check( true === $S::set( 'ser', 'a:1:{i:0;i:1;}' ) && 'a:1:{i:0;i:1;}' === $S::get( 'ser' ), 'serialized-looking string round-trips' );
check( true === $S::set( 'arr', array( 'a' => 1, 'b' => array( 2, '3' ), 'c' => null ) ), 'array => true' );
check( array( 'a' => 1, 'b' => array( 2, '3' ), 'c' => null ) === $S::get( 'arr' ), '  array read back exactly (types preserved)' );
$wpdb->fail_pattern = '/INSERT INTO wp_mobo_state/';
check( false === $S::set( 'k', 7 ), 'failed write => false' );
$wpdb->fail_pattern = '';
check( 1795304300614842 === absint( $S::get( 'k' ) ), '  previous value intact' );
/* Read-back helper semantics (shared with option_read_back_matches). */
check( $S::read_back_matches( '123', 123 ) && $S::read_back_matches( '1', true ) && $S::read_back_matches( '', false ), 'scalars compare by canonical string form' );
check( ! $S::read_back_matches( '124', 123 ) && ! $S::read_back_matches( '', null ) && ! $S::read_back_matches( array( 1 ), '1' ), 'mismatches stay mismatches (null strict, array vs scalar)' );
check( ! $S::read_back_matches( array( 'a' => '1' ), array( 'a' => 1 ) ), 'arrays compare exactly (no loose element types)' );

echo "\n[2] Durable_State_Policy::update_state_verified()\n";
$D = 'Mobo_Core_Durable_State_Policy';
check( true === $D::update_state_verified( 'cursor', 42 ), 'int => true' );
check( true === $D::update_state_verified( 'state', array( 'status' => 'running', 'page' => 3 ) ), 'array => true' );
$wpdb->fail_pattern = '/INSERT INTO wp_mobo_state/';
check( false === $D::update_state_verified( 'cursor', 43 ), 'failed write => false' );
$wpdb->fail_pattern = '';

echo "\n[3] option migration with an int legacy value\n";
$GLOBALS['t_options']['mobo_core_legacy_counter'] = 17;
check( true === $S::migrate_option( 'mobo_core_legacy_counter' ), 'migrate_option() of an int option => true' );
check( '17' === $S::get( 'mobo_core_legacy_counter' ) && ! isset( $GLOBALS['t_options']['mobo_core_legacy_counter'] ), '  migrated and legacy option deleted' );

echo "\n[4] category snapshot commit path\n";
$cs = new Mobo_Core_Category_Sync();
$started = 1795304300614842;
$r = $cs->load_categories_for_mapping_payload( array( 'data' => array() ), $started );
check( ! empty( $r['complete'] ) && empty( $r['skipped'] ) && empty( $r['error'] ), 'fully applied snapshot reported complete (was: watermark could not be durably committed)' );
check( $started === absint( $S::get( 'category_snapshot_committed_us' ) ), '  watermark committed' );
$r = $cs->sync_categories_payload( array( 'data' => array() ), $started - 5 );
check( empty( $r['complete'] ) && ! empty( $r['stale'] ), 'older in-flight snapshot is fenced as stale' );
$r = $cs->load_categories_for_mapping_payload( array( 'data' => array() ), $started + 10 );
check( ! empty( $r['complete'] ) && $started + 10 === absint( $S::get( 'category_snapshot_committed_us' ) ), 'newer snapshot completes and advances the watermark' );
check( empty( Mobo_Core_Lock::$held ), 'snapshot lease released' );
$wpdb->fail_pattern = '/INSERT INTO wp_mobo_state/';
$r = $cs->load_categories_for_mapping_payload( array( 'data' => array() ), $started + 20 );
$wpdb->fail_pattern = '';
check( empty( $r['complete'] ) && false !== strpos( (string) ( $r['error'] ?? '' ), 'durably committed' ), 'a real watermark write failure is still reported' );

echo PHP_EOL . $checks . ' checks, ' . $failures . ' failure(s) on PHP ' . PHP_VERSION . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
