<?php
/**
 * Environment-matrix group B regressions (MySQL 9.1 / MariaDB staging findings, 2026-11-22).
 *
 * No WordPress, no database, no network: WordPress functions, $wpdb and the few
 * collaborating classes are replaced by in-memory stubs. Run with:
 *   php tests-dev/env-matrix-b-test.php
 *
 * PHP 7.4 compatible.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MOBO_CORE_VERSION', '10.34.77' );
define( 'MOBO_CORE_CONNECTION_SETTINGS_CONTRACT_VERSION', '7' );
define( 'ARRAY_A', 'ARRAY_A' );

/* ---------------- WordPress stubs ---------------- */
$GLOBALS['__options'] = array();
$GLOBALS['__post_types'] = array();
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['__options'][ $name ] = $value; return true; }
function add_option( $name, $value, $dep = '', $autoload = null ) { if ( array_key_exists( $name, $GLOBALS['__options'] ) ) { return false; } $GLOBALS['__options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['__options'][ $name ] ); return true; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $v ) ) ); }
function get_post_type( $id ) { return isset( $GLOBALS['__post_types'][ $id ] ) ? $GLOBALS['__post_types'][ $id ] : false; }
function wp_get_post_parent_id( $id ) { return 0; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function apply_filters( $tag, $value ) { return $value; }

/* Minimal $wpdb fake: records queries, answers scripted SELECTs. */
class Fake_WPDB {
	public $postmeta = 'wp_postmeta';
	public $posts = 'wp_posts';
	public $options = 'wp_options';
	public $prefix = 'wp_';
	public $last_error = '';
	public $queries = array();
	/** @var array<int,array<string,array>> post_id => key => rows */
	public $meta = array();
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $sql );
	}
	public function get_results( $sql, $output = null ) {
		$this->queries[] = $sql;
		if ( false !== strpos( $sql, 'GROUP BY pm.post_id,pm.meta_key HAVING COUNT(*)>1' ) ) {
			$out = array();
			foreach ( $this->meta as $post_id => $keys ) {
				if ( preg_match( '/NOT IN \(([0-9,]+)\)/', $sql, $m ) && in_array( (string) $post_id, explode( ',', $m[1] ), true ) ) { continue; }
				foreach ( $keys as $key => $rows ) { if ( count( $rows ) > 1 ) { $out[] = array( 'post_id' => $post_id, 'meta_key' => $key ); } }
			}
			return $out;
		}
		if ( preg_match( "/WHERE post_id=(\d+) AND meta_key='([^']+)' ORDER BY meta_id ASC/", $sql, $m ) ) {
			return $this->meta[ (int) $m[1] ][ $m[2] ] ?? array();
		}
		if ( preg_match( "/SELECT meta_id FROM wp_postmeta WHERE post_id=(\d+) AND meta_key='([^']+)'/", $sql, $m ) ) {
			return $this->meta[ (int) $m[1] ][ $m[2] ] ?? array();
		}
		return array();
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( "/DELETE FROM wp_postmeta WHERE post_id=(\d+) AND meta_key='([^']+)' AND meta_id IN \(([0-9,]+)\)/", $sql, $m ) ) {
			$ids = array_map( 'intval', explode( ',', $m[3] ) );
			$before = count( $this->meta[ (int) $m[1] ][ $m[2] ] );
			$this->meta[ (int) $m[1] ][ $m[2] ] = array_values( array_filter( $this->meta[ (int) $m[1] ][ $m[2] ], function ( $r ) use ( $ids ) { return ! in_array( (int) $r['meta_id'], $ids, true ); } ) );
			return $before - count( $this->meta[ (int) $m[1] ][ $m[2] ] );
		}
		return 0;
	}
	public function get_var( $sql ) { $this->queries[] = $sql; return null; }
	public function get_col( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( '/SELECT ID FROM wp_posts WHERE ID IN \(([0-9,]+)\)/', $sql, $m ) ) {
			$existing = isset( $GLOBALS['__existing_posts'] ) ? $GLOBALS['__existing_posts'] : array();
			return array_values( array_filter( explode( ',', $m[1] ), function ( $id ) use ( $existing ) { return isset( $existing[ (int) $id ] ); } ) );
		}
		return array();
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

/* Collaborator stubs (class_exists() must see them; there is no autoloader here). */
class Mobo_Core_Connection_Repository {
	public static $rows = array( array( 'id' => 1, 'is_default' => 1, 'is_enabled' => 1 ) );
	public static $ownership_ready = true;
	public static function table_exists() { return true; }
	public static function list_all() { return self::$rows; }
	public static function get_default() { foreach ( self::$rows as $r ) { if ( ! empty( $r['is_default'] ) && ! empty( $r['is_enabled'] ) ) { return $r; } } return null; }
	public static function get_default_any() { foreach ( self::$rows as $r ) { if ( ! empty( $r['is_default'] ) ) { return $r; } } return null; }
	public static function legacy_terminal_retirement_topology_safe() { $d = self::get_default(); return 1 === count( self::$rows ) && is_array( $d ); }
	public static function connection_ownership_ready() { return self::$ownership_ready; }
}
/* Lock stub: counts destructive releases (F1). */
class Mobo_Core_Lock {
	public static $force_release_all_calls = 0;
	public static function force_release_all() { self::$force_release_all_calls++; return array( 'deleted' => 2, 'found' => 2 ); }
	public static function force_release( $name ) { return true; }
	public static function is_locked( $name ) { return false; }
}

require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-migration-quarantine.php';
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-storage-schema.php';
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-migration.php';

$GLOBALS['__pass'] = 0; $GLOBALS['__fail'] = 0;
function check( $label, $cond ) {
	if ( $cond ) { $GLOBALS['__pass']++; echo "  ok   - {$label}\n"; }
	else { $GLOBALS['__fail']++; echo "  FAIL - {$label}\n"; }
}
echo "PHP " . PHP_VERSION . "\n";

echo "\n[F1] 10.31.71 lock recovery runs once, never inside every fresh-install migration pass\n";
$lock_recovery = new ReflectionMethod( 'Mobo_Core_Migration', 'apply_103171_runtime_lock_recovery' );
$lock_recovery->setAccessible( true );
$GLOBALS['__options'] = array();
Mobo_Core_Lock::$force_release_all_calls = 0;
$lock_recovery->invoke( null, '' );   // activation of a fresh install
check( 'first call on a fresh install clears legacy locks once', 1 === Mobo_Core_Lock::$force_release_all_calls );
check( '  and records the one-shot marker', false !== get_option( 'mobo_core_103171_lock_cleanup_at', false ) );
delete_option( 'mobo_core_category_placeholder_repair_pending' );
for ( $i = 0; $i < 5; $i++ ) { $lock_recovery->invoke( null, '' ); } // budgeted passes while db_version is still empty
check( 'later passes with empty db_version do NOT force-release the runner lease again', 1 === Mobo_Core_Lock::$force_release_all_calls );
check( '  and do not re-queue the category placeholder repair', false === get_option( 'mobo_core_category_placeholder_repair_pending', false ) );
$GLOBALS['__options'] = array();
Mobo_Core_Lock::$force_release_all_calls = 0;
$lock_recovery->invoke( null, '10.30.5' );
$lock_recovery->invoke( null, '10.30.5' );
check( 'upgrade from < 10.31.71: one cleanup, not one per pass', 1 === Mobo_Core_Lock::$force_release_all_calls );
$GLOBALS['__options'] = array();
$lock_recovery->invoke( null, '10.33.67' );
check( 'upgrade from 10.33.67 never force-releases locks', 1 === Mobo_Core_Lock::$force_release_all_calls && false === get_option( 'mobo_core_103171_lock_cleanup_at', false ) );

echo "\n" . $GLOBALS['__pass'] . ' passed, ' . $GLOBALS['__fail'] . " failed\n";
exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );
