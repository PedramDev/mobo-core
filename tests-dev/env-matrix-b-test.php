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

/* Extra WP stubs for the Clone Guard / Health read cache (F2). */
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $u ) { return (string) $u; }
function home_url( $path = '' ) { return rtrim( (string) get_option( 'home', '' ), '/' ) . $path; }
function wp_get_environment_type() { return 'production'; }
function add_action() { return true; }
function add_filter() { return true; }
function wp_next_scheduled() { return 1; }
function wp_schedule_event() { return true; }

require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-migration-quarantine.php';
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-storage-schema.php';
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-migration.php';
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-clone-guard.php';
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-health-read-cache.php';
/* Money policy stub (F3): only the live contract status matters. */
class Mobo_Core_Money_Policy {
	public static $status = array();
	public static function compatibility_status( $connection_id = 0, $fresh = false ) { return self::$status; }
}
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-webhook-queue.php';
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-image-integrity.php';
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-sync-event-store.php';

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

echo "\n[F2] Health snapshot copied with the database is never served for another site address\n";
$GLOBALS['__options'] = array();
$snap = function ( $site, $clone ) {
	return array( 'generatedAt' => time(), 'status' => 200, 'body' => array( 'success' => true, 'data' => array( 'siteUrl' => $site, 'cloneSuspected' => $clone ) ) );
};
update_option( 'home', 'https://shop.example' );
update_option( 'siteurl', 'https://shop.example' );
update_option( Mobo_Core_Clone_Guard::OPTION_BINDING, array( 'v' => 1, 'home' => 'shop.example', 'site' => 'shop.example', 'homeUrl' => 'https://shop.example', 'db' => Mobo_Core_Clone_Guard::database_fingerprint(), 'boundAt' => 1, 'source' => 'provision' ) );
Mobo_Core_Clone_Guard::reset_cache();
check( 'original site: its own snapshot is served (cache kept)', true === Mobo_Core_Health_Read_Cache::snapshot_matches_current_site( $snap( 'https://shop.example/', false ) ) );
check( 'original site: www / http variant of the same host still matches', true === Mobo_Core_Health_Read_Cache::snapshot_matches_current_site( $snap( 'http://www.shop.example/', false ) ) );
update_option( 'home', 'https://staging.shop.example' );
update_option( 'siteurl', 'https://staging.shop.example' );
Mobo_Core_Clone_Guard::reset_cache();
check( 'clone: guard reports cloneSuspected', true === Mobo_Core_Clone_Guard::is_clone_suspected() );
check( 'clone: the copied original snapshot (other siteUrl, cloneSuspected=false) is refused', false === Mobo_Core_Health_Read_Cache::snapshot_matches_current_site( $snap( 'https://shop.example/', false ) ) );
check( 'clone: right URL but stale clone verdict is refused', false === Mobo_Core_Health_Read_Cache::snapshot_matches_current_site( $snap( 'https://staging.shop.example/', false ) ) );
check( 'clone: its own fresh snapshot is served', true === Mobo_Core_Health_Read_Cache::snapshot_matches_current_site( $snap( 'https://staging.shop.example/', true ) ) );
check( 'legacy snapshot without siteUrl/cloneSuspected fields is accepted unchanged', true === Mobo_Core_Health_Read_Cache::snapshot_matches_current_site( array( 'generatedAt' => time(), 'body' => array( 'data' => array( 'x' => 1 ) ) ) ) );

echo "\n[F3] Upgraded-but-not-yet-provisioned site defers price events instead of failing them\n";
$fence = new RuntimeException( 'Source monetary contract is missing or unsupported; signed Connection provisioning must refresh it.' );
Mobo_Core_Money_Policy::$status = array( 'compatible' => false, 'code' => 'money_contract_missing', 'requiresReprovision' => true );
check( 'missing contract (awaiting provisioning) -> deferred, no attempt consumed', true === Mobo_Core_Webhook_Queue::is_awaiting_money_provisioning( $fence ) );
check( 'other RuntimeException while unprovisioned -> normal failure path', false === Mobo_Core_Webhook_Queue::is_awaiting_money_provisioning( new RuntimeException( 'Product payload integrity failed.' ) ) );
Mobo_Core_Money_Policy::$status = array( 'compatible' => false, 'code' => 'money_unit_mismatch', 'requiresReprovision' => false );
check( 'store currency mismatch (admin action) is NOT deferred forever', false === Mobo_Core_Webhook_Queue::is_awaiting_money_provisioning( $fence ) );
Mobo_Core_Money_Policy::$status = array( 'compatible' => true, 'code' => 'compatible', 'requiresReprovision' => false );
check( 'provisioned contract -> not deferred', false === Mobo_Core_Webhook_Queue::is_awaiting_money_provisioning( $fence ) );

echo "\n[F4] A short/empty STORED copy (disk full during copy or sub-size write) is detected\n";
$tmpdir = sys_get_temp_dir() . '/envb-f4-' . getmypid();
@mkdir( $tmpdir );
$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat( 'A', 6000 ) . "\xFF\xD9";
file_put_contents( $tmpdir . '/full.jpg', $jpeg );
file_put_contents( $tmpdir . '/short.jpg', substr( $jpeg, 0, 2400 ) );
file_put_contents( $tmpdir . '/zero.jpg', '' );
$webp = 'RIFF' . pack( 'V', 5000 ) . 'WEBPVP8 ' . str_repeat( 'B', 4992 );
file_put_contents( $tmpdir . '/full.webp', $webp );
file_put_contents( $tmpdir . '/short.webp', substr( $webp, 0, 2000 ) );
check( 'complete JPEG -> ok', '' === Mobo_Core_Image_Integrity::stored_structure_problem( $tmpdir . '/full.jpg' ) );
check( 'JPEG cut at 40% -> jpeg-missing-eoi', 'jpeg-missing-eoi' === Mobo_Core_Image_Integrity::stored_structure_problem( $tmpdir . '/short.jpg' ) );
check( 'zero-byte stored file -> empty', 'empty' === Mobo_Core_Image_Integrity::stored_structure_problem( $tmpdir . '/zero.jpg' ) );
check( 'complete WebP -> ok', '' === Mobo_Core_Image_Integrity::stored_structure_problem( $tmpdir . '/full.webp' ) );
check( 'WebP shorter than its RIFF size -> webp-short-riff', 'webp-short-riff' === Mobo_Core_Image_Integrity::stored_structure_problem( $tmpdir . '/short.webp' ) );
check( 'missing file (media offload) -> "missing", which the importer ignores', 'missing' === Mobo_Core_Image_Integrity::stored_structure_problem( $tmpdir . '/nope.jpg' ) );
foreach ( glob( $tmpdir . '/*' ) as $f ) { @unlink( $f ); }
@rmdir( $tmpdir );
$sync_src = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-image-sync.php' );
check( 'importer checks the stored attachment before linking it', false !== strpos( $sync_src, '$stored_problem = $this->stored_attachment_problem( $attachment_id );' ) );
check( '  and reports it with the storage prefix (retry + revival, never a permanent image defect)', 1 === preg_match( '/STORAGE_NOT_READY_PREFIX[^;]*stored copy in uploads is incomplete/s', $sync_src ) );

echo "\n[F5] Product-sync durable-write failures (DB/disk full) stay transient: extended budget + auto re-arm\n";
$E = 'Mobo_Core_Sync_Event_Store';
foreach ( array(
	'ProductUpdated could not durably create/update the product; event will retry without advancing the batch cursor.',
	'Product revision watermark could not be persisted; mutation was deferred.',
	'Variation ordering watermark could not be committed after mutation; event will retry.',
	'Could not durably persist the authoritative variation seen-set; destructive finalization was deferred.',
	'Full snapshot request could not be written (database write failure); delta event kept for retry.',
	'Database write failed (retryable): ثبت نهایی Product Map محصول ساده ناموفق بود.',
) as $m ) {
	check( 'transient: ' . substr( $m, 0, 60 ), true === $E::is_transient_failure( $m ) );
}
foreach ( array(
	'ProductUpdated ordering watermark is not comparable with the durable product watermark; mutation was blocked.',
	'Equal-version ProductUpdated payload conflicts with the durable applied source hash; mutation was blocked.',
	'Webhook event expired.',
) as $m ) {
	check( 'NOT transient (real conflict): ' . substr( $m, 0, 60 ), false === $E::is_transient_failure( $m ) );
}
$p = $E::failure_policy( 5, 5, true, 'ProductUpdated could not durably create/update the product; event will retry without advancing the batch cursor.', 7 );
check( '5th failed attempt of a durable-write failure is NOT final (TRANSIENT_MAX_TRY applies)', false === $p['final'] && $p['transient'] );
$q_src = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-webhook-queue.php' );
check( 'queue marks processor results flagged retryable as transient', false !== strpos( $q_src, "'Database write failed (retryable): ' . \$message" ) );

echo "\n[F6] REST security-plugin bypass and no-store headers are actually wired on REST requests\n";
$main = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/mobo-core.php' );
$pos  = strpos( $main, '$rest = new Mobo_Core_Rest_Controller();' );
$blk  = false === $pos ? '' : substr( $main, $pos, 1500 );
check( 'rest_api_init closure instantiates the controller', false !== $pos );
check( '  and registers filter_rest_authentication_errors (priority 999) in the same closure', 1 === preg_match( "/add_filter\(\s*'rest_authentication_errors',\s*array\(\s*'Mobo_Core_Rest_Controller',\s*'filter_rest_authentication_errors'\s*\),\s*999\s*\)/", $blk ) );
check( '  and registers harden_rest_response_cache on rest_post_dispatch', 1 === preg_match( "/add_filter\(\s*'rest_post_dispatch',\s*array\(\s*\\\$rest,\s*'harden_rest_response_cache'\s*\)/", $blk ) );
$ctl_src = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-rest-controller.php' );
check( 'the filter is still a public static callable', false !== strpos( $ctl_src, 'public static function filter_rest_authentication_errors( $result )' ) );

echo "\n[F7] utf8mb3: guard-encoded attribute values compare equal to WooCommerce's html-decoded read-back\n";
if ( ! class_exists( 'Mobo_Core_Unicode_Guard' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-unicode-guard.php';
}
$enc = array( array( 'name' => 'color', 'options' => array( 'v0 &#x1f600;&#x1f680;', 'v1 &#x1f600;&#x1f680;' ) ) );
$dec = array( array( 'name' => 'color', 'options' => array( "v0 \u{1F600}\u{1F680}", "v1 \u{1F600}\u{1F680}" ) ) );
check( 'canonical(encoded desired) === canonical(decoded stored)', Mobo_Core_Unicode_Guard::canonical( $enc ) === Mobo_Core_Unicode_Guard::canonical( $dec ) );
check( 'BMP numeric entities (e.g. &#1575;) are left untouched', '&#1575;' === Mobo_Core_Unicode_Guard::canonical( '&#1575;' ) );
check( 'a genuinely different option still differs', Mobo_Core_Unicode_Guard::canonical( 'v2 &#x1f600;' ) !== Mobo_Core_Unicode_Guard::canonical( "v0 \u{1F600}" ) );
$ps_src = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-product-sync.php' );
check( 'parent attribute verification compares canonical forms', 1 === preg_match( '/function product_attributes_match_payload.{0,900}Mobo_Core_Unicode_Guard::canonical\( \$current \)/s', $ps_src ) );
check( 'variation selection validation compares canonical forms', false !== strpos( $ps_src, '$value_canon = Mobo_Core_Unicode_Guard::canonical( $value );' ) );

echo "\n" . $GLOBALS['__pass'] . ' passed, ' . $GLOBALS['__fail'] . " failed\n";
exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );
