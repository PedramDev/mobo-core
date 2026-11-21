<?php
/**
 * Regression tests for image storage resilience (pre-release gaps M5, M6, 5.8, 4.6):
 *  - M5: a disk/quota/storage-not-ready failure is never counted as a
 *    permanent readiness failure; terminal rows caused by storage are revived
 *    when storage recovers, other terminal rows get a bounded 7-day re-check;
 *    pending storage-blocked rows are expedited; success forgets the ledger.
 *  - M6: the automatic prune never deletes a physical file another attachment
 *    still references, and is non-destructive by default (owner rule).
 *  - Truncated JPEG/PNG/WebP downloads (Content-Length / end markers) and
 *    images too large for memory_limit are refused before WordPress decodes.
 *  - Failed progress writes stop the prune slice (disk full).
 *
 * Real plugin classes; SQL runs on SQLite (sqlite-wpdb-stub.php).
 *
 * Run: php tests-dev/image-storage-resilience-test.php   (PHP 7.4 and 8.x)
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['t_options']  = array();
$GLOBALS['t_filters']  = array();
$GLOBALS['t_deleted']  = array();
$GLOBALS['t_settings'] = array();
$GLOBALS['t_failures'] = 0;
$GLOBALS['t_checks']   = 0;

class WP_Error {
	private $c; private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function esc_url_raw( $v ) { return trim( (string) $v ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['t_options'][ $k ] ); return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['t_filters'][ $tag ] = $cb; return true; }
function remove_filter( $tag, $cb, $prio = 10 ) { unset( $GLOBALS['t_filters'][ $tag ] ); return true; }
function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_header( $r, $h ) { return isset( $r['headers'][ strtolower( $h ) ] ) ? $r['headers'][ strtolower( $h ) ] : ''; }
function maybe_unserialize( $d ) { if ( is_string( $d ) && preg_match( '/^(a|O|s|i|b|d|N):/', $d ) ) { $r = @unserialize( $d ); return ( false === $r && 'b:0;' !== $d ) ? $d : $r; } return $d; }
function wp_normalize_path( $p ) { return preg_replace( '#/+#', '/', str_replace( '\\', '/', (string) $p ) ); }
function untrailingslashit( $v ) { return rtrim( (string) $v, '/\\' ); }
function trailingslashit( $v ) { return rtrim( (string) $v, '/\\' ) . '/'; }
function wp_get_upload_dir() { return array( 'basedir' => $GLOBALS['t_basedir'], 'baseurl' => 'https://shop.test/wp-content/uploads' ); }
function get_post_type( $id ) { global $wpdb; $t = $wpdb->get_var( 'SELECT post_type FROM wp_posts WHERE ID = ' . (int) $id ); return null === $t ? false : $t; }
function get_post_meta( $id, $key, $single = false ) { global $wpdb; $v = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM wp_postmeta WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1', $id, $key ) ); return null === $v ? '' : maybe_unserialize( $v ); }
function delete_post_meta( $id, $key ) { global $wpdb; $wpdb->query( $wpdb->prepare( 'DELETE FROM wp_postmeta WHERE post_id = %d AND meta_key = %s', $id, $key ) ); return true; }
function wp_get_attachment_metadata( $id ) { $m = get_post_meta( $id, '_wp_attachment_metadata', true ); return is_array( $m ) ? $m : false; }
function get_attached_file( $id ) { $rel = (string) get_post_meta( $id, '_wp_attached_file', true ); return '' === $rel ? '' : $GLOBALS['t_basedir'] . '/' . $rel; }
function wp_delete_attachment( $id, $force = false ) { global $wpdb; $GLOBALS['t_deleted'][] = (int) $id; $wpdb->query( 'DELETE FROM wp_posts WHERE ID = ' . (int) $id ); return true; }
final class Mobo_Core_Connection_Context { public static function get_effective_connection_id() { return 1; } }
final class Mobo_Core_Settings {
	public static function enabled( $k, $d = '0' ) { $v = array_key_exists( $k, $GLOBALS['t_settings'] ) ? $GLOBALS['t_settings'][ $k ] : $d; return in_array( strtolower( (string) $v ), array( '1', 'yes', 'true', 'on' ), true ); }
	public static function get_int( $k, $d, $min = 0, $max = PHP_INT_MAX ) { return (int) $d; }
	public static function get( $k, $d = '' ) { return array_key_exists( $k, $GLOBALS['t_settings'] ) ? $GLOBALS['t_settings'][ $k ] : $d; }
}
/** Storage probe double (real class needs a WordPress uploads stack). */
final class Mobo_Core_Image_Storage {
	public static $ready = true;
	public static $checks = 0;
	public static function check() { self::$checks++; return array( 'ready' => self::$ready, 'message' => self::$ready ? 'ok' : 'quota full' ); }
}

function t_check( $cond, $label ) {
	$GLOBALS['t_checks']++;
	echo ( $cond ? '  ok   - ' : '  FAIL - ' ) . $label . "\n";
	if ( ! $cond ) { $GLOBALS['t_failures']++; }
}
function t_same( $e, $a, $label ) { t_check( $e === $a, $label . ( $e === $a ? '' : ' (expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) . ')' ) ); }

require __DIR__ . '/sqlite-wpdb-stub.php';
$inc = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $inc . 'class-mobo-core-environment.php';
require $inc . 'class-mobo-core-image-reimport-ledger.php';
require $inc . 'class-mobo-core-image-integrity.php';
require $inc . 'class-mobo-core-legacy-image-policy.php';
require $inc . 'class-mobo-core-image-queue.php';
require $inc . 'class-mobo-core-image-prune-queue.php';
require $inc . 'class-mobo-core-image-sync.php';

$wpdb = new Sqlite_Test_WPDB();
$GLOBALS['wpdb'] = $wpdb;
$wpdb->pdo->exec( "CREATE TABLE wp_mobo_image_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER NOT NULL DEFAULT 0, queue_key TEXT DEFAULT '', product_id INTEGER DEFAULT 0, product_guid TEXT DEFAULT '', image_guid TEXT DEFAULT '', source_url TEXT, position_index INTEGER DEFAULT 0, attachment_id INTEGER DEFAULT 0, status TEXT DEFAULT 'pending', try_count INTEGER DEFAULT 0, next_retry_at TEXT NULL, locked_until TEXT NULL, last_error TEXT NULL, created_at TEXT, updated_at TEXT)" );
$wpdb->pdo->exec( "CREATE TABLE wp_mobo_image_prune_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER NOT NULL DEFAULT 0, attachment_id INTEGER NOT NULL, owner_product_id INTEGER DEFAULT 0, image_guid TEXT DEFAULT '', legacy_img_guid TEXT DEFAULT '', source_url TEXT NULL, status TEXT DEFAULT 'pending', phase TEXT DEFAULT 'postmeta', postmeta_cursor INTEGER DEFAULT 0, option_cursor INTEGER DEFAULT 0, post_cursor INTEGER DEFAULT 0, proof_pass INTEGER DEFAULT 0, try_count INTEGER DEFAULT 0, next_retry_at TEXT NULL, last_error TEXT NULL, created_at TEXT, updated_at TEXT)" );

$L = 'Mobo_Core_Image_Reimport_Ledger';
$I = 'Mobo_Core_Image_Integrity';
$P = 'Mobo_Core_Image_Prune_Queue';
$tmp = sys_get_temp_dir() . '/mobo-img-test-' . substr( md5( (string) microtime( true ) ), 0, 8 );
@mkdir( $tmp . '/uploads/2026/11', 0777, true );
$GLOBALS['t_basedir'] = str_replace( '\\', '/', $tmp . '/uploads' );

/* ====================================================================== */
echo "[1] M5 ledger: storage failures are transient, revival is bounded\n";
t_check( $L::is_storage_transient_message( $L::STORAGE_NOT_READY_PREFIX . ' quota full' ), 'storage prefix is transient' );
t_check( $L::is_storage_transient_message( 'Image file exists but final WebP/subsize validation failed: ساخت برش‌ها به دلیل آماده نبودن فضای ذخیره‌سازی متوقف شد.' ), 'legacy subsize storage-gate message (old releases) is recognised' );
t_check( $L::is_storage_transient_message( 'imagewebp(): write failed: No space left on device' ), 'ENOSPC is transient' );
t_check( ! $L::is_storage_transient_message( 'Image file exists but final WebP/subsize validation failed: unsupported_editor' ), 'editor defect is not storage' );
$now = 1800000000;
$terminal_storage = 'Permanent: ' . $L::TERMINAL_MARKER . ' after the bounded fresh reimport; Image file exists but final WebP/subsize validation failed: ساخت برش‌ها به دلیل آماده نبودن فضای ذخیره‌سازی متوقف شد.';
$terminal_defect  = 'Permanent: ' . $L::TERMINAL_MARKER . ' after the bounded fresh reimport; Image file exists but final WebP/subsize validation failed: unsupported_editor';
t_same( 'forget', $L::revival_action( $terminal_storage, $now - 60, $now, true ), 'terminal row caused by storage: forgotten once storage is ready' );
t_same( 'keep', $L::revival_action( $terminal_storage, $now - 60, $now, false ), '  but not while storage is still full' );
t_same( 'keep', $L::revival_action( $terminal_defect, $now - 60, $now, true ), 'genuine terminal defect kept...' );
t_same( 'reset_failures', $L::revival_action( $terminal_defect, $now - $L::REVIVE_AFTER_SECONDS - 1, $now, true ), '...and re-checked after 7 days (bounded)' );
t_same( 'keep', $L::revival_action( 'Permanent: Product does not exist.', 0, $now, true ), 'non-readiness terminal rows untouched' );
$k = $L::key( 1, 'g-defect', 'https://cdn.test/a.webp' );
$L::record_fresh_reimport( $k );
for ( $i = 0; $i < 6; $i++ ) { $L::record_readiness_failure( $k ); }
$L::reset_failures( $k );
$e = $L::get( $k );
t_check( 0 === $e['f'] && 1 === $e['r'], 'bounded revival clears failures but keeps the fresh-reimport cap (no extra attachment)' );
t_check( ! $L::may_fresh_reimport( $e ), '  fresh reimport still refused' );

/* ====================================================================== */
echo "\n[2] M5 image queue: expedite storage-blocked rows, revive terminal rows\n";
$ts = gmdate( 'Y-m-d H:i:s' );
$later = gmdate( 'Y-m-d H:i:s', time() + 6 * 3600 );
$old = gmdate( 'Y-m-d H:i:s', time() - $L::REVIVE_AFTER_SECONDS - 60 );
$rows = array(
	'blocked'  => array( 'status' => 'pending', 'image_guid' => 'g1', 'source_url' => 'https://cdn.test/1.webp', 'try_count' => 7, 'next_retry_at' => $later, 'last_error' => $L::STORAGE_NOT_READY_PREFIX . ' quota full', 'updated_at' => $ts ),
	'network'  => array( 'status' => 'pending', 'image_guid' => 'g2', 'source_url' => 'https://cdn.test/2.webp', 'try_count' => 7, 'next_retry_at' => $later, 'last_error' => 'Image HTTP request failed: http_request_failed', 'updated_at' => $ts ),
	'tstorage' => array( 'status' => 'failed', 'image_guid' => 'g3', 'source_url' => 'https://cdn.test/3.webp', 'try_count' => 4, 'last_error' => $terminal_storage, 'updated_at' => $ts ),
	'tdefect'  => array( 'status' => 'failed', 'image_guid' => 'g4', 'source_url' => 'https://cdn.test/4.webp', 'try_count' => 4, 'last_error' => $terminal_defect, 'updated_at' => $ts ),
	'told'     => array( 'status' => 'failed', 'image_guid' => 'g5', 'source_url' => 'https://cdn.test/5.webp', 'try_count' => 4, 'last_error' => $terminal_defect, 'updated_at' => $old ),
);
$ids = array();
foreach ( $rows as $name => $row ) {
	$wpdb->insert( 'wp_mobo_image_queue', array_merge( array( 'connection_id' => 1, 'product_id' => 9, 'created_at' => $ts ), $row ) );
	$ids[ $name ] = $wpdb->insert_id;
}
$k3 = $L::key( 1, 'g3', 'https://cdn.test/3.webp' );
for ( $i = 0; $i < 6; $i++ ) { $L::record_readiness_failure( $k3 ); }
$k5 = $L::key( 1, 'g5', 'https://cdn.test/5.webp' );
$L::record_fresh_reimport( $k5 );
for ( $i = 0; $i < 6; $i++ ) { $L::record_readiness_failure( $k5 ); }
$queue = new Mobo_Core_Image_Queue();
$changed = $queue->revive_storage_blocked( 100 );
$q = function ( $name ) use ( $wpdb, $ids ) { return $wpdb->row( 'wp_mobo_image_queue', $ids[ $name ] ); };
t_same( null, $q( 'blocked' )['next_retry_at'], 'storage-blocked pending row retries now (not up to 6 h later)' );
t_same( $later, $q( 'network' )['next_retry_at'], 'other pending failures keep their backoff' );
t_same( 'pending', $q( 'tstorage' )['status'], 'terminal row caused by a full disk is revived' );
t_same( 0, (int) $q( 'tstorage' )['try_count'], '  with a fresh queue budget' );
t_same( 0, $L::get( $k3 )['f'] + $L::get( $k3 )['r'], '  and a forgotten ledger entry' );
t_same( 'failed', $q( 'tdefect' )['status'], 'recent genuine terminal row stays terminal' );
t_same( 'pending', $q( 'told' )['status'], 'terminal row older than 7 days gets one bounded re-check' );
t_check( 0 === $L::get( $k5 )['f'] && 1 === $L::get( $k5 )['r'], '  failure counter reset, fresh-reimport cap kept' );
t_same( 3, $changed, 'revive_storage_blocked() reports changed rows (1 expedited + 2 revived)' );

/* ====================================================================== */
echo "\n[3] M5 image sync wiring\n";
$sync_ref = new ReflectionClass( 'Mobo_Core_Image_Sync' );
$sync     = $sync_ref->newInstanceWithoutConstructor();
$classify = $sync_ref->getMethod( 'is_storage_transient_readiness_failure' );
$classify->setAccessible( true );
Mobo_Core_Image_Storage::$ready = true;
t_check( $classify->invoke( $sync, array( 'success' => false, 'code' => 'storage_not_ready' ), 'x' ), 'refresh-service storage_not_ready code => storage failure (not counted)' );
t_check( ! $classify->invoke( $sync, array( 'success' => false ), 'unsupported_editor' ), 'editor failure with healthy storage => readiness failure (counted)' );
Mobo_Core_Image_Storage::$ready = false;
t_check( $classify->invoke( $sync, array( 'success' => false ), 'wp_update_image_subsizes failed' ), 'generic subsize failure while storage probe fails => storage failure' );
Mobo_Core_Image_Storage::$ready = true;
$revive = $sync_ref->getMethod( 'maybe_revive_storage_blocked_images' );
$revive->setAccessible( true );
$fake_queue = new class() { public $calls = 0; public function revive_storage_blocked( $limit ) { $this->calls++; return 3; } };
$GLOBALS['t_options'] = array();
t_same( 3, $revive->invoke( $sync, $fake_queue ), 'revival runs when storage is ready' );
t_same( 0, $revive->invoke( $sync, $fake_queue ), '  and is throttled' );
$GLOBALS['t_options'] = array();
Mobo_Core_Image_Storage::$ready = false;
t_same( 0, $revive->invoke( $sync, $fake_queue ), 'no revival while storage is still not ready' );
Mobo_Core_Image_Storage::$ready = true;
$src = (string) file_get_contents( $inc . 'class-mobo-core-image-sync.php' );
t_check( false !== strpos( $src, 'Mobo_Core_Image_Reimport_Ledger::forget( Mobo_Core_Image_Reimport_Ledger::key(' ), 'successful import forgets the ledger entry (forget() is no longer dead code)' );

/* ====================================================================== */
echo "\n[4] truncated / oversized downloads\n";
t_check( $I::length_matches( '1000', 1000 ) && ! $I::length_matches( '1000', 512 ) && $I::length_matches( '', 512 ), 'Content-Length comparison (unknown length tolerated)' );
$png1x1 = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
t_same( '', $I::structure_problem( substr( $png1x1, 0, 32 ), $png1x1, strlen( $png1x1 ) ), 'complete PNG accepted' );
$cut = substr( $png1x1, 0, strlen( $png1x1 ) - 20 );
t_same( 'png-missing-iend', $I::structure_problem( substr( $cut, 0, 32 ), $cut, strlen( $cut ) ), 'truncated PNG refused' );
$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat( "\x11", 5000 ) . "\xFF\xD9";
t_same( '', $I::structure_problem( substr( $jpeg, 0, 32 ), substr( $jpeg, -4096 ), strlen( $jpeg ) ), 'JPEG with EOI accepted' );
$jcut = substr( $jpeg, 0, 3000 );
t_same( 'jpeg-missing-eoi', $I::structure_problem( substr( $jcut, 0, 32 ), substr( $jcut, -4096 ), strlen( $jcut ) ), 'truncated JPEG (no EOI) refused' );
$webp = 'RIFF' . pack( 'V', 1000 ) . 'WEBPVP8 ' . str_repeat( "\0", 100 );
t_same( 'webp-short-riff', $I::structure_problem( substr( $webp, 0, 32 ), $webp, strlen( $webp ) ), 'WebP shorter than its RIFF size refused' );
t_same( 'too_large', $I::decode_budget( 6000, 6000, 134217728, PHP_INT_MAX ), '6000x6000 cannot be decoded within 128M => permanent' );
t_same( 'ok', $I::decode_budget( 6000, 6000, 1073741824, PHP_INT_MAX ), '  fine with 1G' );
t_same( 'headroom', $I::decode_budget( 6000, 6000, 1073741824, 50000000 ), '  deferred when this request has too little headroom left' );
t_same( 'too_large', $I::decode_budget( 9000, 9000, 0, PHP_INT_MAX ), 'unmeasurable host: absolute pixel cap' );
$f = $tmp . '/ok.png';
file_put_contents( $f, $png1x1 );
t_same( '', $I::inspect_file( $f, (string) strlen( $png1x1 ), 134217728, PHP_INT_MAX ), 'inspect_file(): valid PNG passes' );
t_check( 0 === strpos( $I::inspect_file( $f, '999', 134217728, PHP_INT_MAX ), $I::TRUNCATED_PREFIX ), 'inspect_file(): Content-Length mismatch refused' );
file_put_contents( $tmp . '/cut.png', $cut );
t_check( 0 === strpos( $I::inspect_file( $tmp . '/cut.png', '', 134217728, PHP_INT_MAX ), $I::TRUNCATED_PREFIX ), 'inspect_file(): truncated PNG refused' );
/* PNG whose IHDR claims 8000x8000: getimagesize() reads only the header. */
$ihdr = "\x89PNG\r\n\x1A\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'NN', 8000, 8000 ) . "\x08\x06\x00\x00\x00" . pack( 'N', 0 ) . pack( 'N', 0 ) . 'IEND' . pack( 'N', 0xAE426082 );
file_put_contents( $tmp . '/huge.png', $ihdr );
$dims = $I::dimensions( $tmp . '/huge.png' );
t_same( array( 8000, 8000 ), $dims, 'dimensions are read from the header only' );
t_check( 0 === strpos( $I::inspect_file( $tmp . '/huge.png', '', 268435456, PHP_INT_MAX ), $I::TOO_LARGE_PREFIX ), 'inspect_file(): 8000x8000 under 256M => too large (no decode, no OOM)' );

$guard_m = $sync_ref->getMethod( 'register_sideload_integrity_guard' );
$guard_m->setAccessible( true );
$unguard = $sync_ref->getMethod( 'unregister_sideload_integrity_guard' );
$unguard->setAccessible( true );
$err_prop = $sync_ref->getProperty( 'sideload_integrity_error' );
$err_prop->setAccessible( true );
$GLOBALS['t_filters'] = array();
$guard = $guard_m->invoke( $sync, true );
t_check( isset( $GLOBALS['t_filters']['http_response'], $GLOBALS['t_filters']['wp_handle_sideload_prefilter'] ), 'sideload guard hooks http_response and the sideload prefilter' );
file_put_contents( $tmp . '/stream.tmp', str_repeat( 'x', 500 ) );
$resp = array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-length' => '1000' ) );
$out  = call_user_func( $GLOBALS['t_filters']['http_response'], $resp, array( 'stream' => true, 'filename' => $tmp . '/stream.tmp' ), 'https://cdn.test/x.jpg' );
t_check( is_wp_error( $out ) && 'mobo_image_truncated' === $out->get_error_code(), 'streamed body shorter than Content-Length => WP_Error (download_url deletes it)' );
$resp['headers']['content-encoding'] = 'gzip';
t_check( ! is_wp_error( call_user_func( $GLOBALS['t_filters']['http_response'], $resp, array( 'stream' => true, 'filename' => $tmp . '/stream.tmp' ), 'u' ) ), '  compressed transfer is left to the structural check' );
$file = call_user_func( $GLOBALS['t_filters']['wp_handle_sideload_prefilter'], array( 'tmp_name' => $tmp . '/cut.png', 'name' => 'a.png' ) );
t_check( ! empty( $file['error'] ) && 0 === strpos( (string) $err_prop->getValue( $sync ), $I::TRUNCATED_PREFIX ), 'prefilter rejects a truncated file before WordPress builds thumbnails' );
$file = call_user_func( $GLOBALS['t_filters']['wp_handle_sideload_prefilter'], array( 'tmp_name' => $tmp . '/ok.png', 'name' => 'a.png' ) );
t_check( empty( $file['error'] ), 'prefilter accepts a complete image' );
$unguard->invoke( $sync, $guard );
t_check( empty( $GLOBALS['t_filters'] ), 'guard removed after the sideload' );

/* ====================================================================== */
echo "\n[5] M6 shared-file family detection (pure)\n";
$meta_a = array( 'file' => '2026/11/a.webp', 'sizes' => array( 'thumbnail' => array( 'file' => 'a-150x150.webp' ) ) );
t_same( 7, $P::shared_family_conflict( '2026/11/a.webp', $meta_a, array( 7 => array( 'file' => '2026/11/a.webp', 'metadata' => array() ) ) ), 'same _wp_attached_file => conflict' );
t_same( 8, $P::shared_family_conflict( '2026/11/a.webp', $meta_a, array( 8 => array( 'file' => '2026/11/b.webp', 'metadata' => array( 'sizes' => array( 's' => array( 'file' => 'a-150x150.webp' ) ) ) ) ) ), 'another attachment generated a size with the same file name => conflict' );
t_same( 9, $P::shared_family_conflict( '2026/11/a.webp', $meta_a, array( 9 => array( 'file' => '2026/11/A-150x150.webp', 'metadata' => array() ) ) ), 'an attachment whose original is one of our size files => conflict (case-insensitive)' );
t_same( 0, $P::shared_family_conflict( '2026/11/a.webp', $meta_a, array( 10 => array( 'file' => '2026/10/a.webp', 'metadata' => $meta_a ) ) ), 'same name in another month directory => no conflict' );

/* ====================================================================== */
echo "\n[6] M6 prune queue against SQLite\n";
function t_attachment( $id, $rel, $guid, $sizes = array() ) {
	global $wpdb;
	$wpdb->pdo->exec( "INSERT INTO wp_posts (ID, post_type) VALUES ({$id}, 'attachment')" );
	$meta = array( 'file' => $rel, 'width' => 10, 'height' => 10, 'sizes' => $sizes );
	foreach ( array( '_wp_attached_file' => $rel, '_wp_attachment_metadata' => serialize( $meta ), 'image_guid' => $guid ) as $k => $v ) {
		if ( '' !== $v ) { $wpdb->insert( 'wp_postmeta', array( 'post_id' => $id, 'meta_key' => $k, 'meta_value' => $v ) ); }
	}
	@file_put_contents( $GLOBALS['t_basedir'] . '/' . $rel, 'bytes' );
}
function t_prune_row( $attachment_id, $guid, $phase = 'finalize' ) {
	global $wpdb;
	$ts = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( 'wp_mobo_image_prune_queue', array( 'connection_id' => 1, 'attachment_id' => $attachment_id, 'image_guid' => $guid, 'status' => 'pending', 'phase' => $phase, 'proof_pass' => 1, 'created_at' => $ts, 'updated_at' => $ts ) );
	return $wpdb->insert_id;
}
$prune = new Mobo_Core_Image_Prune_Queue();
t_attachment( 101, '2026/11/shared.webp', 'aaaaaaaa-0000-4000-8000-000000000101' );
t_attachment( 102, '2026/11/shared.webp', '' ); /* duplicate row, same physical file */
t_attachment( 103, '2026/11/unique.webp', 'aaaaaaaa-0000-4000-8000-000000000103' );
$r101 = t_prune_row( 101, 'aaaaaaaa-0000-4000-8000-000000000101' );
$r103 = t_prune_row( 103, 'aaaaaaaa-0000-4000-8000-000000000103' );
t_check( false === $P::physical_delete_enabled(), 'automatic physical deletion is OFF by default (owner rule)' );
$GLOBALS['t_deleted'] = array();
$res = $prune->process_due( 5, 5 );
t_same( array(), $GLOBALS['t_deleted'], 'default: no attachment deleted' );
t_same( $P::STATUS_AWAITING_APPROVAL, $wpdb->row( 'wp_mobo_image_prune_queue', $r101 )['status'], '  candidates parked as awaiting_approval (non-destructive)' );
t_same( 2, (int) $prune->get_summary()['awaitingApproval'], '  parked count visible in the summary' );
t_check( ! $prune->has_due_work(), '  parked rows are not due work (no runner churn)' );

$GLOBALS['t_settings'][ $P::PHYSICAL_DELETE_OPTION ] = '1';
$prune->process_due( 1, 5 );
$row101 = $wpdb->row( 'wp_mobo_image_prune_queue', $r101 );
t_check( $P::STATUS_AWAITING_APPROVAL !== $row101['status'] && 'postmeta' === $row101['phase'] && 0 === (int) $row101['proof_pass'], 'enabling deletion re-queues parked rows with a fresh two-pass proof' );
t_same( array(), $GLOBALS['t_deleted'], '  nothing deleted by the re-queue itself' );
/* Race: the duplicate attachment row appears after the bounded proof passed.
 * The locked final boundary must still see it. */
$wpdb->pdo->exec( "UPDATE wp_mobo_image_prune_queue SET status = 'pending', phase = 'finalize', proof_pass = 1, last_error = NULL" );
$GLOBALS['t_deleted'] = array();
$prune->process_due( 5, 5 );
$row101 = $wpdb->row( 'wp_mobo_image_prune_queue', $r101 );
t_same( 'retained', $row101['status'], 'attachment sharing its file with another row is retained' );
t_check( false !== strpos( (string) $row101['last_error'], '#102' ), '  reason names the other attachment' );
t_check( ! in_array( 101, $GLOBALS['t_deleted'], true ) && is_file( $GLOBALS['t_basedir'] . '/2026/11/shared.webp' ), '  shared physical file NOT deleted' );
t_check( in_array( 103, $GLOBALS['t_deleted'], true ), 'unshared stale attachment is deleted when deletion is enabled' );

t_attachment( 104, '2026/11/c.webp', 'aaaaaaaa-0000-4000-8000-000000000104', array( 'thumbnail' => array( 'file' => 'c-150x150.webp' ) ) );
t_attachment( 105, '2026/11/other.webp', '', array( 'thumbnail' => array( 'file' => 'c-150x150.webp' ) ) );
$r104 = t_prune_row( 104, 'aaaaaaaa-0000-4000-8000-000000000104' );
$GLOBALS['t_deleted'] = array();
$prune->process_due( 5, 5 );
t_same( 'retained', $wpdb->row( 'wp_mobo_image_prune_queue', $r104 )['status'], 'shared generated size file => retained' );
t_same( array(), $GLOBALS['t_deleted'], '  nothing deleted' );

t_attachment( 106, '2026/11/d.webp', 'aaaaaaaa-0000-4000-8000-000000000106' );
$r106 = t_prune_row( 106, 'aaaaaaaa-0000-4000-8000-000000000106' );
$wpdb->fail_pattern = "/meta_key = '_wp_attached_file' AND post_id <>/";
$GLOBALS['t_deleted'] = array();
$prune->process_due( 5, 5 );
$wpdb->fail_pattern = '';
t_same( 'retained', $wpdb->row( 'wp_mobo_image_prune_queue', $r106 )['status'], 'failed shared-file proof query fails closed (retained)' );
t_same( array(), $GLOBALS['t_deleted'], '  nothing deleted' );

echo "\n[7] failed progress writes stop the prune slice\n";
t_attachment( 107, '2026/11/e.webp', 'aaaaaaaa-0000-4000-8000-000000000107' );
t_attachment( 108, '2026/11/f.webp', 'aaaaaaaa-0000-4000-8000-000000000108' );
$r107 = t_prune_row( 107, 'aaaaaaaa-0000-4000-8000-000000000107', 'posts' );
$r108 = t_prune_row( 108, 'aaaaaaaa-0000-4000-8000-000000000108', 'posts' );
$wpdb->pdo->exec( "UPDATE wp_mobo_image_prune_queue SET proof_pass = 0 WHERE id IN ({$r107},{$r108})" );
$wpdb->fail_pattern = '/^UPDATE wp_mobo_image_prune_queue SET phase/';
$res = $prune->process_due( 5, 5 );
$wpdb->fail_pattern = '';
t_check( ! empty( $res['writeFailed'] ), 'phase write failure reported (disk full)' );
t_same( 1, (int) $res['processed'], '  slice stopped after the first failed write instead of hammering the database' );
t_same( 'posts', $wpdb->row( 'wp_mobo_image_prune_queue', $r107 )['phase'], '  no progress recorded for the failed row' );
t_same( 'pending', $wpdb->row( 'wp_mobo_image_prune_queue', $r108 )['status'], '  the next row was not touched' );

echo "\n[8] admin image refresh: integrity gate and failed queue writes\n";
require $inc . 'class-mobo-core-image-refresh-queue.php';
require $inc . 'class-mobo-core-image-refresh-service.php';
$wpdb->pdo->exec( "CREATE TABLE wp_mobo_image_refresh_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER DEFAULT 0, product_id INTEGER DEFAULT 0, image_guid TEXT DEFAULT '', old_attachment_id INTEGER DEFAULT 0, new_attachment_id INTEGER DEFAULT 0, new_source_url TEXT DEFAULT '', status TEXT DEFAULT 'pending', try_count INTEGER DEFAULT 0, next_retry_at TEXT NULL, locked_until TEXT NULL, last_error TEXT NULL, created_at TEXT, updated_at TEXT)" );
$wpdb->insert( 'wp_mobo_image_refresh_queue', array( 'connection_id' => 1, 'product_id' => 9, 'image_guid' => 'g-r', 'old_attachment_id' => 77, 'new_source_url' => 'https://cdn.test/r.webp', 'status' => 'processing', 'locked_until' => 'tok-r', 'created_at' => $ts, 'updated_at' => $ts ) );
$rid = $wpdb->insert_id;
$rq  = new Mobo_Core_Image_Refresh_Queue();
$wpdb->fail_pattern = '/^UPDATE wp_mobo_image_refresh_queue/';
t_same( false, $rq->mark_done_if_current( $rid, 501, '', 9, 'g-r', 77, 'https://cdn.test/r.webp', 'tok-r' ), 'refresh done transition fails when the write fails' );
t_same( true, $rq->last_write_failed(), '  reported as a write failure (worker stops the batch), not as superseded' );
t_same( false, $rq->begin_attempt_if_current( $rid, 1, 9, 'g-r', 77, 'https://cdn.test/r.webp', 'tok-r' ), 'attempt checkpoint write failure => false' );
t_same( true, $rq->last_write_failed(), '  reported as a write failure (editor not run without its checkpoint)' );
$wpdb->fail_pattern = '';
t_same( false, $rq->mark_done_if_current( $rid, 501, '', 9, 'g-r', 77, 'https://cdn.test/OTHER.webp', 'tok-r' ), 'superseded identity => false' );
t_same( false, $rq->last_write_failed(), '  but not a write failure (superseded path unchanged)' );
t_same( true, $rq->mark_done_if_current( $rid, 501, '', 9, 'g-r', 77, 'https://cdn.test/r.webp', 'tok-r' ), 'healthy write commits' );
$svc_src = (string) file_get_contents( $inc . 'class-mobo-core-image-refresh-service.php' );
t_check( substr_count( $svc_src, "\$queue->last_write_failed()" ) >= 5 && false !== strpos( $svc_src, "'db-write-failed'" ), 'refresh worker stops (db-write-failed, remaining) on every failed transition write' );

$svc_ref = new ReflectionClass( 'Mobo_Core_Image_Refresh_Service' );
$svc     = $svc_ref->newInstanceWithoutConstructor();
$gate    = $svc_ref->getMethod( 'subsize_integrity_problem' );
$gate->setAccessible( true );
foreach ( array( 201 => 'huge.png', 202 => 'cut.png', 203 => 'ok.png' ) as $aid => $name ) {
	copy( $tmp . '/' . $name, $GLOBALS['t_basedir'] . '/2026/11/r-' . $name );
	$wpdb->insert( 'wp_postmeta', array( 'post_id' => $aid, 'meta_key' => '_wp_attached_file', 'meta_value' => '2026/11/r-' . $name ) );
}
if ( function_exists( 'ini_set' ) ) { @ini_set( 'memory_limit', '256M' ); } // 8000x8000 is too large both under 256M and under the unmeasured 50 MP cap.
$big = $gate->invoke( $svc, 201 );
t_check( isset( $big['code'] ) && 'too_large' === $big['code'] && true === $big['permanent'], 'subsize regeneration of an 8000x8000 original refused before decode (permanent)' );
$cutr = $gate->invoke( $svc, 202 );
t_check( isset( $cutr['code'] ) && 'truncated' === $cutr['code'] && empty( $cutr['permanent'] ), 'truncated original refused (retryable)' );
t_same( array(), $gate->invoke( $svc, 203 ), 'complete original may be regenerated' );
$err = $sync_ref->getProperty( 'last_image_error' );
$err->setAccessible( true );
$err->setValue( $sync, $I::TOO_LARGE_PREFIX . ' 8000x8000' );
t_same( $I::TOO_LARGE_PREFIX . ' 8000x8000', $sync->last_import_error(), 'refresh service can read the download guard reason (too large => permanent refresh failure)' );
t_check( false !== strpos( $svc_src, "'permanent' => \$too_large" ) && false !== strpos( $svc_src, "'permanent' => ! empty( \$subsize_result['permanent'] )" ), 'refresh failures carry the permanent flag for too-large images' );

/* cleanup */
foreach ( array_reverse( (array) glob( $tmp . '/{,uploads/2026/11/}*', GLOB_BRACE ) ) as $path ) { if ( is_file( $path ) ) { @unlink( $path ); } }
@rmdir( $tmp . '/uploads/2026/11' ); @rmdir( $tmp . '/uploads/2026' ); @rmdir( $tmp . '/uploads' ); @rmdir( $tmp );

echo "\n" . $GLOBALS['t_checks'] . ' checks, ' . $GLOBALS['t_failures'] . ' failure(s) on PHP ' . PHP_VERSION . "\n";
exit( $GLOBALS['t_failures'] > 0 ? 1 : 0 );
