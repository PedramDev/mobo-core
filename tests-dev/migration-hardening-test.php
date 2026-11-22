<?php
/**
 * Standalone harness for the 10.34.77 migration hardening decision logic.
 *
 * No WordPress, no database, no network: WordPress functions, $wpdb and the few
 * collaborating classes are replaced by in-memory stubs. Run with:
 *   php tests-dev/migration-hardening-test.php
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

require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-migration-quarantine.php';
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-storage-schema.php';
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-migration.php';

/* ---------------- tiny assertion runner ---------------- */
$GLOBALS['__pass'] = 0; $GLOBALS['__fail'] = 0;
function check( $label, $cond ) {
	if ( $cond ) { $GLOBALS['__pass']++; echo "  ok   - {$label}\n"; }
	else { $GLOBALS['__fail']++; echo "  FAIL - {$label}\n"; }
}
function reset_state() {
	$GLOBALS['__options'] = array();
	$GLOBALS['wpdb'] = new Fake_WPDB();
	Mobo_Core_Connection_Repository::$rows = array( array( 'id' => 1, 'is_default' => 1, 'is_enabled' => 1 ) );
	Mobo_Core_Connection_Repository::$ownership_ready = true;
	Mobo_Core_Migration_Quarantine::reset_request_cache();
}

echo "PHP " . PHP_VERSION . "\n";

echo "\n[1] duplicate classification (only byte-identical values are duplicates)\n";
$Q = 'Mobo_Core_Migration_Quarantine';
$r = $Q::classify_duplicate_rows( array( array( 'meta_id' => 7, 'meta_value' => 'abc' ), array( 'meta_id' => 3, 'meta_value' => 'abc' ), array( 'meta_id' => 9, 'meta_value' => 'abc' ) ) );
check( 'identical values -> dedupe', 'dedupe' === $r['action'] );
check( 'keeps the oldest row (lowest meta_id)', 3 === $r['keep'] );
check( 'removes only the redundant copies', array( 7, 9 ) === $r['remove'] );
$r = $Q::classify_duplicate_rows( array( array( 'meta_id' => 1, 'meta_value' => 'abc' ), array( 'meta_id' => 2, 'meta_value' => 'ABC' ) ) );
check( 'case difference -> conflict (never deleted)', 'conflict' === $r['action'] && empty( $r['remove'] ) );
$r = $Q::classify_duplicate_rows( array( array( 'meta_id' => 1, 'meta_value' => 'abc' ), array( 'meta_id' => 2, 'meta_value' => 'abc ' ) ) );
check( 'trailing whitespace difference -> conflict', 'conflict' === $r['action'] );
$r = $Q::classify_duplicate_rows( array( array( 'meta_id' => 1, 'meta_value' => null ), array( 'meta_id' => 2, 'meta_value' => '' ) ) );
check( 'NULL vs empty string -> conflict', 'conflict' === $r['action'] );
$r = $Q::classify_duplicate_rows( array( array( 'meta_id' => 1, 'meta_value' => null ), array( 'meta_id' => 2, 'meta_value' => null ) ) );
check( 'NULL vs NULL -> dedupe', 'dedupe' === $r['action'] && array( 2 ) === $r['remove'] );
$r = $Q::classify_duplicate_rows( array( array( 'meta_id' => 1, 'meta_value' => '5' ) ) );
check( 'single row -> none', 'none' === $r['action'] );
$r = $Q::classify_duplicate_rows( array( array( 'meta_id' => 1, 'meta_value' => '0' ), array( 'meta_id' => 2, 'meta_value' => '' ) ) );
check( '"0" vs "" -> conflict (no loose comparison)', 'conflict' === $r['action'] );

echo "\n[2] runner gating\n";
check( 'no migration -> normal', 'normal' === $Q::decide_runner_mode( false, false ) );
check( 'migration + runtime ready -> shared (normal work continues)', 'shared' === $Q::decide_runner_mode( true, true ) );
check( 'migration + prerequisites missing -> migration-only', 'migration-only' === $Q::decide_runner_mode( true, false ) );
check( 'shared budget floor is 2s', 2 === $Q::shared_migration_budget( 3 ) );
check( 'shared budget is 40% of 12s', 4 === $Q::shared_migration_budget( 12 ) );
check( 'shared budget capped at 8s', 8 === $Q::shared_migration_budget( 55 ) );

echo "\n[3] normal_runtime_ready (structural prerequisites only)\n";
reset_state();
update_option( 'mobo_core_db_version', '10.33.67' );
$reason = '';
check( 'unstamped, nothing ready -> false', false === Mobo_Core_Migration::normal_runtime_ready( $reason ) );
check( '  reason = custom-storage-schema-not-ready', 'custom-storage-schema-not-ready' === $reason );
update_option( Mobo_Core_Storage_Schema::VERSION_OPTION, Mobo_Core_Storage_Schema::VERSION );
update_option( Mobo_Core_Storage_Schema::VALIDATED_OPTION, array( 'version' => Mobo_Core_Storage_Schema::VERSION, 'at' => time() ) );
/* is_ready() memoizes per request; simulate the next request. */
$ready_cache = new ReflectionProperty( 'Mobo_Core_Storage_Schema', 'ready_cache' );
$ready_cache->setAccessible( true );
$ready_cache->setValue( null, null );
$queries_before = count( $GLOBALS['wpdb']->queries );
Mobo_Core_Migration::normal_runtime_ready( $reason );
check( 'cached schema validation issues no SHOW queries', count( $GLOBALS['wpdb']->queries ) === $queries_before );
check( 'schema cached-ready, connection pending', 'connection-foundation-pending' === $reason );
update_option( 'mobo_core_103412_connection_migrated_at', time() );
update_option( 'mobo_core_connection_settings_contract_version', '7' );
update_option( 'mobo_core_103393_large_runtime_strict_cutover_at', time() );
Mobo_Core_Connection_Repository::$ownership_ready = false;
Mobo_Core_Migration::normal_runtime_ready( $reason );
check( 'ownership pending detected', 'connection-ownership-pending' === $reason );
Mobo_Core_Connection_Repository::$ownership_ready = true;
check( 'seed pending no longer blocks normal runtime (only product creation is gated)', true === Mobo_Core_Migration::normal_runtime_ready( $reason ) && '' === $reason );
update_option( 'mobo_core_product_map_seed_completed_at', time() );
check( 'all prerequisites -> ready even though unstamped and workers unfinished', true === Mobo_Core_Migration::normal_runtime_ready( $reason ) && '' === $reason );
update_option( 'mobo_core_db_version', '10.34.77' );
delete_option( 'mobo_core_product_map_seed_completed_at' );
check( 'stamped -> always ready', true === Mobo_Core_Migration::normal_runtime_ready( $reason ) );

echo "\n[4] plugins_loaded throttle and schema validation cache\n";
$now = 1700000000;
check( 'never ran -> due', true === Mobo_Core_Migration::ordinary_pass_due( 0, $now ) );
check( '10s ago -> not due', false === Mobo_Core_Migration::ordinary_pass_due( $now - 10, $now ) );
check( '60s ago -> due', true === Mobo_Core_Migration::ordinary_pass_due( $now - 60, $now ) );
check( 'clock skew (future stamp) -> due', true === Mobo_Core_Migration::ordinary_pass_due( $now + 3600, $now ) );
$S = 'Mobo_Core_Storage_Schema';
check( 'fresh stamp for this schema version -> trusted', true === $S::validation_cache_fresh( array( 'version' => $S::VERSION, 'at' => $now - 100 ), $now ) );
check( 'stamp of another schema version -> revalidate', false === $S::validation_cache_fresh( array( 'version' => '14', 'at' => $now ), $now ) );
check( 'expired stamp -> revalidate', false === $S::validation_cache_fresh( array( 'version' => $S::VERSION, 'at' => $now - $S::VALIDATED_TTL ), $now ) );
check( 'garbage -> revalidate', false === $S::validation_cache_fresh( 'x', $now ) );

echo "\n[5] marking store, threshold, cap and exclusions\n";
reset_state();
$GLOBALS['__post_types'] = array( 10 => 'product', 11 => 'product', 12 => 'product_variation' );
check( 'retry before marking: 1st failure', false === $Q::record_failure( 10 ) );
check( 'retry before marking: 2nd failure', false === $Q::record_failure( 10 ) );
check( 'mark on 3rd consecutive failure', true === $Q::record_failure( 10 ) );
check( 'mark() succeeds', true === $Q::mark( 10, 'identity', 'mirror_failed', 'x' ) );
check( 'marking clears the failure counter', ! isset( $GLOBALS['__options'][ $Q::FAILURES_OPTION ][10] ) );
check( 'is_marked', $Q::is_marked( 10 ) && ! $Q::is_marked( 11 ) );
check( 'marked exclusion fragment is integer-only', ' AND pm.post_id NOT IN (10)' === $Q::marked_exclusion_sql( 'pm.post_id' ) );
check( 'no foreign exclusion with a single Connection', '' === $Q::foreign_exclusion_sql( 'pm.post_id' ) );
check( 'persisted with type', 'product' === $GLOBALS['__options'][ $Q::OPTION ][10]['t'] );
$Q::unmark( 10 );
check( 'unmark removes the entry and the option', 0 === $Q::count() && ! isset( $GLOBALS['__options'][ $Q::OPTION ] ) );
reset_state();
$full = array();
for ( $i = 1; $i <= $Q::MAX_ITEMS; $i++ ) { $full[ $i ] = array( 'w' => 'identity', 'r' => 'x', 'at' => 1 ); }
update_option( $Q::OPTION, $full );
$GLOBALS['__existing_posts'] = array_fill_keys( array_keys( $full ), true );
check( 'cap reached with live posts -> mark() returns false (worker stays honestly blocked)', false === $Q::mark( 999999, 'identity', 'x' ) );
check( '  overflow is recorded for support', 1 === absint( $Q::overflow_status()['count'] ?? 0 ) );
$Q::reset_request_cache();
unset( $GLOBALS['__existing_posts'][5], $GLOBALS['__existing_posts'][6] );
check( 'M8: entries of deleted posts are pruned at the cap, freeing capacity', true === $Q::mark( 999999, 'identity', 'x' ) );
check( '  pruned entries gone, new entry present', ! $Q::is_marked( 5 ) && ! $Q::is_marked( 6 ) && $Q::is_marked( 999999 ) );
unset( $GLOBALS['__existing_posts'] );

echo "\n[5b] M8: paged quarantine storage (more than 1000 marked objects)\n";
reset_state();
check( 'capacity is no longer the old 1000 (paged)', $Q::MAX_ITEMS > 1000 && $Q::PAGE_SIZE <= 1000 );
for ( $i = 1; $i <= 2500; $i++ ) { $Q::mark( $i, 'operational', 'mirror_failed' ); }
check( '2500 objects marked', 2500 === $Q::count() );
check( 'stored in pages of PAGE_SIZE', count( $GLOBALS['__options'][ $Q::OPTION ] ) === $Q::PAGE_SIZE && isset( $GLOBALS['__options'][ $Q::OPTION . '_p1' ], $GLOBALS['__options'][ $Q::OPTION . '_p2' ] ) );
$Q::reset_request_cache();
check( 'reload merges all pages', 2500 === $Q::count() && $Q::is_marked( 2500 ) && $Q::is_marked( 1 ) );
for ( $i = 1001; $i <= 2500; $i++ ) { $Q::unmark( $i ); }
check( 'shrinking deletes trailing pages', ! isset( $GLOBALS['__options'][ $Q::OPTION . '_p1' ] ) && ! isset( $GLOBALS['__options'][ $Q::OPTION . '_p2' ] ) && 1000 === $Q::count() );
check( 'filter_existing keeps only live ids', array( 2 => 'b' ) === $Q::filter_existing( array( 1 => 'a', 2 => 'b' ), array( 2 => true ) ) );

echo "\n[5c] admin notice reflects real background activity\n";
$now = 1800000000;
check( 'recent success -> healthy', 'healthy' === $Q::classify_runtime_activity( $now - 120, array(), $now )['state'] );
$a = $Q::classify_runtime_activity( $now - 3600, array( 'active' => true, 'remainingSeconds' => 40 ), $now );
check( 'stale success + active lease -> locked with remaining seconds', 'locked' === $a['state'] && 40 === $a['lockRemaining'] );
check( 'stale success, no lease -> idle (no false "continues normally")', 'idle' === $Q::classify_runtime_activity( 0, array( 'active' => false ), $now )['state'] );

echo "\n[6] multi-Connection topology\n";
reset_state();
check( 'lone enabled Default -> allowed', true === $Q::retirement_topology_allowed() );
Mobo_Core_Connection_Repository::$rows[] = array( 'id' => 2, 'is_default' => 0, 'is_enabled' => 0 );
$Q::reset_request_cache();
Mobo_Core_Connection_Repository::$ownership_ready = false;
check( 'extra disabled Connection + ownership pending -> wait (not a permanent block)', false === $Q::retirement_topology_allowed() );
Mobo_Core_Connection_Repository::$ownership_ready = true;
check( 'extra Connection + ownership proven -> allowed', true === $Q::retirement_topology_allowed() );
check( 'other connections detected', true === $Q::other_connections_exist() );

echo "\n[7] exact-duplicate normalization against a fake postmeta table\n";
reset_state();
$GLOBALS['__post_types'] = array( 20 => 'product', 21 => 'product', 22 => 'product' );
$GLOBALS['wpdb']->meta = array(
	20 => array( 'product_guid' => array( array( 'meta_id' => 100, 'meta_value' => 'g-1' ), array( 'meta_id' => 105, 'meta_value' => 'g-1' ) ) ),
	21 => array( 'product_guid' => array( array( 'meta_id' => 101, 'meta_value' => 'g-2' ), array( 'meta_id' => 106, 'meta_value' => 'g-OTHER' ) ) ),
	22 => array( 'product_guid' => array( array( 'meta_id' => 102, 'meta_value' => 'g-3' ) ) ),
);
$res = $Q::dedupe_exact_duplicate_meta( array( 'product_guid' ), 'identity', 50 );
check( 'no error', '' === $res['error'] );
check( 'one exact duplicate group collapsed', 1 === $res['deduped'] && 1 === $res['rowsRemoved'] );
check( 'kept oldest row of post 20', array( array( 'meta_id' => 100, 'meta_value' => 'g-1' ) ) === $GLOBALS['wpdb']->meta[20]['product_guid'] );
check( 'differing duplicates of post 21 untouched', 2 === count( $GLOBALS['wpdb']->meta[21]['product_guid'] ) );
check( 'post 21 marked with reason duplicate_meta_conflict', $Q::is_marked( 21 ) && 'duplicate_meta_conflict' === $Q::all()[21]['r'] );
check( 'post 22 untouched / unmarked', ! $Q::is_marked( 22 ) && 1 === count( $GLOBALS['wpdb']->meta[22]['product_guid'] ) );
$res2 = $Q::dedupe_exact_duplicate_meta( array( 'product_guid' ), 'identity', 50 );
check( 'second pass: marked post excluded, nothing left to do', 0 === $res2['groups'] && 0 === $res2['deduped'] );

echo "\n[8] re-probe outcome combination\n";
check( 'all clear -> release', 'release' === $Q::combine_reprobe_outcomes( array( 'clear', 'clear' ) ) );
check( 'identity keep -> keep', 'keep' === $Q::combine_reprobe_outcomes( array( 'keep' ) ) );
check( 'any error -> error', 'error' === $Q::combine_reprobe_outcomes( array( 'clear', 'error' ) ) );
check( 'empty -> keep', 'keep' === $Q::combine_reprobe_outcomes( array() ) );
reset_state();
update_option( 'mobo_core_db_version', '10.33.67' );
update_option( $Q::OPTION, array( 5 => array( 'w' => 'identity', 'r' => 'x', 'at' => 1 ) ) );
check( 're-probe never runs before the stamp (marked objects stay marked)', false === $Q::reprobe_due() );
update_option( 'mobo_core_db_version', '10.34.77' );
$Q::reset_request_cache();
check( 're-probe due after stamp', true === $Q::reprobe_due() );
update_option( $Q::REPROBE_OPTION, time() + 600 );
check( 're-probe respects its interval', false === $Q::reprobe_due() );
$Q::request_reprobe();
check( 'request_reprobe (Repair) makes it due again', true === $Q::reprobe_due() );

echo "\n[9] runtime hardening: pass deadline, dbDelta index filter, honest schema error\n";
$M = 'Mobo_Core_Migration';
check( 'no deadline -> never reached', false === $M::deadline_reached( 0, 1e12 ) );
check( 'before deadline -> continue', false === $M::deadline_reached( 100.0, 99.5 ) );
check( 'at/after deadline -> stop between steps', true === $M::deadline_reached( 100.0, 100.0 ) && true === $M::deadline_reached( 100.0, 130.0 ) );
$create = "CREATE TABLE wp_mobo_product_map (\n\t\t\tid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n\t\t\tremote_guid varchar(191) NOT NULL,\n\t\t\tPRIMARY KEY  (id),\n\t\t\tUNIQUE KEY remote_object (connection_id, remote_guid(140), object_type),\n\t\t\tKEY wp_post_id (connection_id, wp_post_id),\n\t\t\tKEY updated_id (connection_id, updated_at, id)\n\t\t) DEFAULT CHARSET=utf8mb4;";
$stripped = $M::strip_existing_index_lines( $create, array( 'primary' => true, 'remote_object' => true, 'wp_post_id' => true ) );
check( 'existing index names removed (no "Duplicate key name" ADD KEY)', false === strpos( $stripped, 'remote_object' ) && false === strpos( $stripped, 'KEY wp_post_id' ) && false === strpos( $stripped, 'PRIMARY KEY' ) );
check( 'missing index kept so dbDelta still adds it', false !== strpos( $stripped, 'KEY updated_id' ) );
check( 'columns untouched', false !== strpos( $stripped, 'remote_guid varchar(191) NOT NULL' ) && false !== strpos( $stripped, 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT' ) );
check( 'nothing existing -> statement unchanged', $create === $M::strip_existing_index_lines( $create, array() ) );
reset_state();
$finalize = new ReflectionMethod( $M, 'finalize_database_version' );
$finalize->setAccessible( true );
update_option( 'mobo_core_db_version', '10.33.67' );
update_option( 'mobo_core_schema_version', MOBO_CORE_VERSION );
update_option( 'mobo_core_schema_last_error', $M::POSTCONDITION_ERROR_PREFIX . '; it will retry on the next request.' );
$finalize->invoke( null, false );
check( 'schema fine, workers in progress -> no false "postconditions not satisfied" error', ! isset( $GLOBALS['__options']['mobo_core_schema_last_error'] ) );
check( '  and the DB version is not stamped', '10.33.67' === get_option( 'mobo_core_db_version' ) );
update_option( 'mobo_core_schema_last_error', 'Mobo Core database schema is incomplete: x' );
$finalize->invoke( null, false );
check( 'a real schema error written by create_database_tables() is preserved', 0 === strpos( (string) get_option( 'mobo_core_schema_last_error' ), 'Mobo Core database schema is incomplete' ) );
delete_option( 'mobo_core_schema_version' );
delete_option( 'mobo_core_schema_last_error' );
$finalize->invoke( null, false );
check( 'schema not proven -> postcondition error still reported', 0 === strpos( (string) get_option( 'mobo_core_schema_last_error' ), $M::POSTCONDITION_ERROR_PREFIX ) );

echo "\n[10] envA B1: 10.31.71 lock recovery is one-shot (fresh install / old upgrade budgeted slices)\n";
/* Declared at runtime, after the earlier sections, so their class_exists() paths are unchanged. */
if ( ! class_exists( 'Mobo_Core_Lock' ) ) {
	class Mobo_Core_Lock {
		public static $calls = 0;
		public static function force_release_all() { self::$calls++; return array( 'deleted' => 2, 'found' => 2 ); }
	}
}
reset_state();
$recovery = new ReflectionMethod( $M, 'apply_103171_runtime_lock_recovery' );
$recovery->setAccessible( true );
Mobo_Core_Lock::$calls = 0;
$recovery->invoke( null, '' ); // activation of a fresh install
$recovery->invoke( null, '' ); // budgeted cron slice 1 (db version still '')
$recovery->invoke( null, '' ); // budgeted cron slice 2
check( 'fresh install: locks force-released at most once (live cron lease survives later slices)', 1 === Mobo_Core_Lock::$calls );
reset_state();
Mobo_Core_Lock::$calls = 0;
$recovery->invoke( null, '10.30.5' );
$recovery->invoke( null, '10.30.5' );
check( 'pre-10.31.71 upgrade: legacy lock cleanup runs once, not on every slice', 1 === Mobo_Core_Lock::$calls );
check( '  and records the cleanup marker', false !== get_option( 'mobo_core_103171_lock_cleanup_at', false ) );
reset_state();
Mobo_Core_Lock::$calls = 0;
$recovery->invoke( null, '10.33.67' );
check( '10.33.67 upgrade: no lock cleanup at all', 0 === Mobo_Core_Lock::$calls );

echo "\n" . $GLOBALS['__pass'] . ' passed, ' . $GLOBALS['__fail'] . " failed\n";
exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );
