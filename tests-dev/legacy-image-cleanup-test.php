<?php
/**
 * Standalone tests for the READ-ONLY legacy Mobo image scan:
 * classification (incl. merchant-upload protection), reference detection,
 * size estimation, resume/crash idempotency and Stop behaviour.
 *
 * Run: php tests-dev/legacy-image-cleanup-test.php   (PHP 7.4 and 8.x)
 * No WordPress, no database, no network: $wpdb is an in-memory fake that
 * refuses every write.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['t_options'] = array();
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function maybe_unserialize( $v ) { if ( is_string( $v ) && preg_match( '/^(a|O|s|i|b|d):/', $v ) ) { $u = @unserialize( $v ); return ( false === $u && 'b:0;' !== $v ) ? $v : $u; } return $v; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['t_options'][ $k ] = $v; $GLOBALS['t_option_writes'][] = $k; return true; }
function delete_option( $k ) { unset( $GLOBALS['t_options'][ $k ] ); return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function get_theme_mod( $k, $d = false ) { return $d; }
function wp_generate_uuid4() { return 'cycle-' . mt_rand( 1000, 9999 ); }
function wp_get_upload_dir() { return array( 'basedir' => $GLOBALS['t_basedir'] ); }

/** In-memory read-only $wpdb fake covering the scanner's query shapes. */
class Fake_WPDB {
	public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $termmeta = 'wp_termmeta'; public $options = 'wp_options';
	public $last_error = ''; public $writes = 0; public $fail_next = '';
	public $p = array();   // ID => [type, mime, parent, content, excerpt]
	public $m = array();   // [meta_id, post_id, key, value]
	public $tm = array();  // term meta values
	function esc_like( $t ) { return addcslashes( $t, '_%\\' ); }
	function prepare( $q, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[dsf]/', function ( $mm ) use ( &$i, $args ) {
			$v = $args[ $i++ ];
			return '%d' === $mm[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $q );
	}
	function query( $q ) { $this->writes++; return false; }
	function insert() { $this->writes++; return false; }
	function update() { $this->writes++; return false; }
	function delete() { $this->writes++; return false; }
	private function ids( $q, $after ) {
		if ( ! preg_match( '/' . preg_quote( $after, '/' ) . '\s*\(([^)]*)\)/', $q, $mm ) ) { return array(); }
		return array_map( function ( $v ) { return trim( $v, " '" ); }, explode( ',', $mm[1] ) );
	}
	private function guard( $q ) {
		$this->last_error = '';
		if ( preg_match( '/^\s*(insert|update|delete|replace|alter|drop|create|truncate)/i', $q ) ) { $this->writes++; }
		if ( '' !== $this->fail_next && false !== strpos( $q, $this->fail_next ) ) { $this->fail_next = ''; $this->last_error = 'simulated failure'; return false; }
		return true;
	}
	function get_var( $q ) {
		if ( ! $this->guard( $q ) ) { return null; }
		if ( false !== strpos( $q, 'COUNT(*)' ) ) { return count( array_filter( $this->p, function ( $r ) { return 'attachment' === $r[0] && in_array( $r[1], array( 'image/jpeg', 'image/png' ), true ); } ) ); }
		if ( false !== strpos( $q, 'MAX(ID)' ) ) { return max( array_keys( $this->p ) ); }
		if ( preg_match( '/SELECT meta_id FROM wp_postmeta WHERE post_id = (\d+) AND meta_key IN/', $q, $mm ) ) {
			$keys = $this->ids( $q, 'meta_key IN' );
			foreach ( $this->m as $row ) { if ( (int) $row[1] === (int) $mm[1] && in_array( $row[2], $keys, true ) && '' !== $row[3] ) { return $row[0]; } }
			return null;
		}
		return null;
	}
	function get_col( $q ) {
		if ( ! $this->guard( $q ) ) { return null; }
		if ( false !== strpos( $q, "post_mime_type = 'image/webp' AND ID IN" ) ) {
			$ids = $this->ids( $q, 'ID IN' );
			return array_values( array_filter( $ids, function ( $id ) { return isset( $this->p[ (int) $id ] ) && 'image/webp' === $this->p[ (int) $id ][1]; } ) );
		}
		if ( false !== strpos( $q, 'FROM wp_termmeta' ) ) {
			$ids = $this->ids( $q, 'meta_value IN' );
			return array_values( array_filter( $this->tm, function ( $v ) use ( $ids ) { return in_array( (string) $v, $ids, true ); } ) );
		}
		return array();
	}
	function get_results( $q, $o = null ) {
		if ( ! $this->guard( $q ) ) { return null; }
		if ( preg_match( "/SELECT ID, post_mime_type, post_parent FROM wp_posts\s+WHERE post_type = 'attachment' AND ID > (\d+).*LIMIT (\d+)/s", $q, $mm ) ) {
			$out = array();
			ksort( $this->p );
			foreach ( $this->p as $id => $r ) {
				if ( $id > (int) $mm[1] && 'attachment' === $r[0] && in_array( $r[1], array( 'image/jpeg', 'image/png' ), true ) ) { $out[] = array( 'ID' => $id, 'post_mime_type' => $r[1], 'post_parent' => $r[2] ); }
				if ( count( $out ) >= (int) $mm[2] ) { break; }
			}
			return $out;
		}
		if ( 0 === strpos( trim( $q ), 'SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id IN' ) ) {
			$ids = $this->ids( $q, 'post_id IN' ); $keys = $this->ids( $q, 'meta_key IN' ); $out = array();
			foreach ( $this->m as $row ) { if ( in_array( (string) $row[1], $ids, true ) && in_array( $row[2], $keys, true ) ) { $out[] = array( 'post_id' => $row[1], 'meta_key' => $row[2], 'meta_value' => $row[3] ); } }
			return $out;
		}
		if ( 0 === strpos( trim( $q ), 'SELECT ID, post_type FROM wp_posts WHERE ID IN' ) ) {
			$out = array();
			foreach ( $this->ids( $q, 'ID IN' ) as $id ) { if ( isset( $this->p[ (int) $id ] ) ) { $out[] = array( 'ID' => (int) $id, 'post_type' => $this->p[ (int) $id ][0] ); } }
			return $out;
		}
		if ( false !== strpos( $q, "meta_key = '_wp_attached_file' AND meta_value IN" ) ) {
			$vals = $this->ids( $q, 'meta_value IN' ); $out = array();
			foreach ( $this->m as $row ) { if ( '_wp_attached_file' === $row[2] && in_array( $row[3], $vals, true ) ) { $out[] = array( 'post_id' => $row[1], 'meta_value' => $row[3] ); } }
			return $out;
		}
		if ( false !== strpos( $q, "pm.meta_key = '_thumbnail_id'" ) ) {
			$vals = $this->ids( $q, 'pm.meta_value IN' ); $out = array();
			foreach ( $this->m as $row ) { if ( '_thumbnail_id' === $row[2] && in_array( (string) $row[3], $vals, true ) ) { $pp = $this->p[ $row[1] ]; $out[] = array( 'post_id' => $row[1], 'meta_value' => $row[3], 'post_type' => $pp[0], 'post_parent' => $pp[2] ); } }
			return $out;
		}
		if ( false !== strpos( $q, 'pm.meta_value REGEXP' ) ) {
			$keys = $this->ids( $q, 'pm.meta_key IN' ); preg_match( "/REGEXP '([^']*)'/", $q, $rx ); $out = array();
			foreach ( $this->m as $row ) { if ( in_array( $row[2], $keys, true ) && preg_match( '/' . $rx[1] . '/', (string) $row[3] ) ) { $pp = $this->p[ $row[1] ]; $out[] = array( 'post_id' => $row[1], 'meta_key' => $row[2], 'meta_value' => $row[3], 'post_type' => $pp[0], 'post_parent' => $pp[2] ); } }
			return $out;
		}
		if ( preg_match( '/SELECT post_content, post_excerpt FROM wp_posts\s+WHERE ID > (\d+) AND ID <= (\d+)/', $q, $mm ) ) {
			$out = array();
			foreach ( $this->p as $id => $r ) { if ( $id > (int) $mm[1] && $id <= (int) $mm[2] && 'attachment' !== $r[0] ) { $out[] = array( 'post_content' => $r[3], 'post_excerpt' => $r[4] ); } }
			return $out;
		}
		return array();
	}
	function post( $id, $type, $mime = '', $parent = 0, $content = '', $excerpt = '' ) { $this->p[ $id ] = array( $type, $mime, $parent, $content, $excerpt ); }
	function meta( $post_id, $key, $value ) { static $mid = 0; $this->m[] = array( ++$mid, $post_id, $key, $value ); }
}

$base = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $base . 'class-mobo-core-legacy-image-policy.php';
require $base . 'class-mobo-core-legacy-image-audit.php';

$failures = 0; $checks = 0;
function check( $cond, $label ) { global $failures, $checks; $checks++; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL; if ( ! $cond ) { $failures++; } }

$P    = 'Mobo_Core_Legacy_Image_Policy';
$GUID = '0a1b2c3d-1111-2222-3333-444455556666';
$base_facts = array( 'mime' => 'image/jpeg', 'file' => '2025/10/abc.jpg', 'img_guid' => $GUID, 'refs_complete' => true, 'refs' => array() );
$cls = function ( $over ) use ( $P, $base_facts ) { $r = $P::classify( array_merge( $base_facts, $over ) ); return $r['class']; };

/* ---- classification ---- */
check( $P::CLASS_DELETE_CANDIDATE === $cls( array() ), 'img_guid-marked, unreferenced JPG => delete candidate (b)' );
check( $P::CLASS_PROTECTED_NOT_MOBO === $cls( array( 'img_guid' => '', 'image_guid' => '' ) ), 'no ownership marker => merchant upload, never touched (c)' );
check( $P::CLASS_PROTECTED_NOT_MOBO === $cls( array( 'img_guid' => '', 'mobo_source_url' => 'https://mobomobo.ir/a.jpg' ) ), 'mobo_source_url alone is not ownership proof' );
check( $P::CLASS_PROTECTED_UNCERTAIN === $cls( array( 'image_guid' => 'aaaaaaaa-0000', 'img_guid' => 'bbbbbbbb-0000' ) ), 'conflicting image_guid/img_guid => uncertain' );
check( $P::CLASS_PROTECTED_UNCERTAIN === $cls( array( 'img_guid' => 'https://x/y' ) ), 'URL-shaped identity => invalid => uncertain' );
check( $P::CLASS_DELETE_CANDIDATE === $cls( array( 'image_guid' => strtoupper( $GUID ) ) ), 'image_guid and img_guid agree case-insensitively' );
check( $P::CLASS_NOT_LEGACY === $cls( array( 'mime' => 'image/webp', 'file' => '2025/10/abc.webp' ) ), 'WebP is out of scope' );
check( $P::CLASS_NOT_LEGACY === $cls( array( 'mime' => 'image/jpeg', 'file' => '2025/10/abc.webp' ) ), 'webp extension wins over stale jpeg mime' );
check( $P::CLASS_DELETE_CANDIDATE === $cls( array( 'mime' => '', 'file' => '2025/10/abc.PNG' ) ), 'PNG by extension is legacy raster' );
check( $P::CLASS_PROTECTED_SHARED_MEDIA === $cls( array( 'shared_media' => true ) ), 'Shared Media protected' );
check( $P::CLASS_PROTECTED_UNCERTAIN === $cls( array( 'file' => '../../wp-config.jpg' ) ), 'path traversal => uncertain' );
check( $P::CLASS_PROTECTED_UNCERTAIN === $cls( array( 'file' => '/etc/abc.jpg' ) ), 'absolute path => uncertain' );
check( $P::CLASS_PROTECTED_SHARED_FILE === $cls( array( 'shares_physical_file' => true ) ), 'physical file shared with another attachment => protected' );
check( $P::CLASS_PROTECTED_FOREIGN_USE === $cls( array( 'parent_non_mobo' => true ) ), 'copy attached to a merchant product => protected' );
check( $P::CLASS_PROTECTED_LIVE === $cls( array( 'live_in_image_queue' => true ) ), 'current image-queue attachment => protected' );
check( $P::CLASS_PROTECTED_LIVE === $cls( array( 'active_refresh' => true ) ), 'active refresh row => protected' );
check( $P::CLASS_PROTECTED_UNCERTAIN === $cls( array( 'guid_pending_raster' => true ) ), 'pending raster source for GUID (could reuse JPG) => uncertain' );
check( $P::CLASS_PROTECTED_UNCERTAIN === $cls( array( 'refs_complete' => false ) ), 'incomplete reference check never yields a candidate' );
check( $P::CLASS_PROTECTED_UNCERTAIN === $cls( array( 'refs_complete' => null ) ), 'unknown reference completeness => uncertain' );
$mobo_ref = array( array( 'kind' => 'featured', 'mobo' => true ) );
check( $P::CLASS_REPLACE_THEN_DELETE === $cls( array( 'refs' => $mobo_ref, 'webp_available' => true ) ), 'featured on Mobo product + WebP available => replace then delete (a)' );
check( $P::CLASS_REPLACE_THEN_DELETE === $cls( array( 'refs' => array( array( 'kind' => 'gallery', 'mobo' => true ) ), 'replaced_by_valid' => true ) ), 'gallery + recorded replacement => (a)' );
check( $P::CLASS_IN_USE_NO_REPLACEMENT === $cls( array( 'refs' => $mobo_ref ) ), 'in use, no WebP known => keep' );
check( $P::CLASS_PROTECTED_FOREIGN_USE === $cls( array( 'refs' => array( array( 'kind' => 'featured', 'mobo' => false ) ), 'webp_available' => true ) ), 'featured on merchant product => protected even with WebP' );
check( $P::CLASS_PROTECTED_FOREIGN_USE === $cls( array( 'refs' => array( array( 'kind' => 'variation', 'mobo' => null ) ) ) ), 'unknown product ownership => protected' );
foreach ( array( 'term', 'option', 'content', 'other' ) as $kind ) {
	check( $P::CLASS_PROTECTED_FOREIGN_USE === $cls( array( 'refs' => array( array( 'kind' => $kind, 'mobo' => null ) ), 'webp_available' => true ) ), "reference kind '{$kind}' => protected" );
}
check( $P::CLASS_PROTECTED_FOREIGN_USE === $cls( array( 'refs' => array( array( 'kind' => 'featured', 'mobo' => true ), array( 'kind' => 'content', 'mobo' => null ) ), 'webp_available' => true ) ), 'one foreign reference among Mobo ones => protected' );

/* ---- reference detection ---- */
$r = $P::extract_content_references( '<img class="wp-image-77" src="https://shop.test/wp-content/uploads/2025/10/abc-300x300.jpg?ver=2"> [gallery ids="5, 9,12"] <!-- wp:image {"id":31,"sizeSlug":"large"} -->' );
check( in_array( 77, $r['ids'], true ) && in_array( 5, $r['ids'], true ) && in_array( 12, $r['ids'], true ) && in_array( 31, $r['ids'], true ), 'wp-image, gallery ids and block id extracted' );
check( in_array( 'abc-300x300.jpg', $r['basenames'], true ), 'size basename extracted from URL with query' );
$r = $P::extract_content_references( '{"url":"https:\/\/shop.test\/wp-content\/uploads\/2025\/10\/Foo%20Bar.JPEG"}' );
check( in_array( 'foo bar.jpeg', $r['basenames'], true ) || in_array( 'foo%20bar.jpeg', $r['basenames'], true ), 'JSON-escaped/URL-encoded basename extracted (lower-cased)' );
$r = $P::extract_content_references( 'plain text without images 123' );
check( empty( $r['basenames'] ) && empty( $r['ids'] ), 'no false extraction from plain text' );
check( array( 3, 4 ) === $P::parse_gallery( ' 3,4,,x,3 ' ), 'gallery CSV parsed, deduplicated, junk ignored' );

/* ---- size estimation ---- */
$meta = array( 'file' => '2025/10/abc.jpg', 'filesize' => 1000, 'original_image' => 'abc-orig.jpg', 'sizes' => array(
	'thumbnail' => array( 'file' => 'abc-150x150.jpg', 'filesize' => 100 ),
	'medium'    => array( 'file' => 'abc-300x300.jpg', 'filesize' => 300 ),
	'dup'       => array( 'file' => 'abc-150x150.jpg', 'filesize' => 100 ),
	'evil'      => array( 'file' => '../../../x.jpg' ),
) );
$files = $P::family_files( '2025/10/abc.jpg', $meta );
check( 5 === count( $files ) && isset( $files['2025/10/abc-orig.jpg'] ) && isset( $files['2025/10/x.jpg'] ), 'family = main + original + unique sizes; traversal reduced to basename in same dir' );
$disk = array( '2025/10/abc.jpg' => 1200, '2025/10/abc-150x150.jpg' => 90 );
$est  = $P::estimate_family( '2025/10/abc.jpg', $meta, function ( $rel ) use ( $disk ) { return isset( $disk[ $rel ] ) ? $disk[ $rel ] : false; } );
check( 1290 === $est['bytes'] && 2 === $est['existing'] && 3 === $est['missing'], 'bytes = real sizes of existing files only; missing files counted' );
check( 1400 === $est['metaBytes'], 'metadata bytes summed separately' );
check( 0 === $P::estimate_family( '../abc.jpg', $meta, null )['files'], 'unsafe main path => no files' );

/* ---- scanner on an in-memory site ---- */
$GLOBALS['t_basedir'] = sys_get_temp_dir() . '/mobo-legacy-test-' . getmypid();
@mkdir( $GLOBALS['t_basedir'] . '/2025/10', 0777, true );
file_put_contents( $GLOBALS['t_basedir'] . '/2025/10/del.jpg', str_repeat( 'x', 500 ) );
file_put_contents( $GLOBALS['t_basedir'] . '/2025/10/del-150x150.jpg', str_repeat( 'x', 50 ) );

$wpdb = new Fake_WPDB();
$GLOBALS['wpdb'] = $wpdb;
$ser = function ( $file, $sizes ) { $m = array( 'file' => $file, 'filesize' => 500, 'sizes' => array() ); foreach ( $sizes as $n => $f ) { $m['sizes'][ $n ] = array( 'file' => $f, 'filesize' => 50 ); } return serialize( $m ); };
// Mobo product 10 (product_guid) and merchant product 20.
$wpdb->post( 10, 'product' ); $wpdb->meta( 10, 'product_guid', 'pg-1' );
$wpdb->post( 20, 'product' );
$wpdb->post( 30, 'page', '', 0, '<p><img src="/wp-content/uploads/2025/10/incontent.jpg"></p>' );
// 101: unreferenced legacy (b) with real files.
$wpdb->post( 101, 'attachment', 'image/jpeg' ); $wpdb->meta( 101, 'img_guid', 'g-101-aaaa' ); $wpdb->meta( 101, '_wp_attached_file', '2025/10/del.jpg' ); $wpdb->meta( 101, '_wp_attachment_metadata', $ser( '2025/10/del.jpg', array( 'thumbnail' => 'del-150x150.jpg', 'medium' => 'del-300x300.jpg' ) ) );
// 102: merchant upload (no marker), featured on merchant product.
$wpdb->post( 102, 'attachment', 'image/jpeg' ); $wpdb->meta( 102, '_wp_attached_file', '2019/01/merchant.jpg' ); $wpdb->meta( 20, '_thumbnail_id', '102' );
// 103: legacy, featured on Mobo product, Mobo gallery too; WebP replacement recorded (a).
$wpdb->post( 103, 'attachment', 'image/jpeg' ); $wpdb->meta( 103, 'img_guid', 'g-103-aaaa' ); $wpdb->meta( 103, '_wp_attached_file', '2025/10/inuse.jpg' ); $wpdb->meta( 103, 'mobo_image_refresh_replaced_by_attachment_id', '900' ); $wpdb->meta( 10, '_thumbnail_id', '103' );
$wpdb->post( 900, 'attachment', 'image/webp' );
// 104: legacy, referenced only from page content.
$wpdb->post( 104, 'attachment', 'image/jpeg' ); $wpdb->meta( 104, 'img_guid', 'g-104-aaaa' ); $wpdb->meta( 104, '_wp_attached_file', '2025/10/incontent.jpg' );
// 105: legacy copy attached to the merchant product (duplicate-product plugin).
$wpdb->post( 105, 'attachment', 'image/jpeg', 20 ); $wpdb->meta( 105, 'img_guid', 'g-105-aaaa' ); $wpdb->meta( 105, '_wp_attached_file', '2025/10/copy.jpg' );
// 106 + 107: two rows pointing at the same physical file (very old overwrite-by-basename).
$wpdb->post( 106, 'attachment', 'image/jpeg' ); $wpdb->meta( 106, 'img_guid', 'g-106-aaaa' ); $wpdb->meta( 106, '_wp_attached_file', '2025/10/same.jpg' );
$wpdb->post( 107, 'attachment', 'image/jpeg' ); $wpdb->meta( 107, '_wp_attached_file', '2025/10/same.jpg' );
// 108: legacy in a Mobo product gallery and in a category thumbnail.
$wpdb->post( 108, 'attachment', 'image/png' ); $wpdb->meta( 108, 'img_guid', 'g-108-aaaa' ); $wpdb->meta( 108, '_wp_attached_file', '2025/10/term.png' ); $wpdb->meta( 10, '_product_image_gallery', '1108,108, 55' ); $wpdb->tm[] = '108';
// 109: legacy referenced by the gallery of the merchant product.
$wpdb->post( 109, 'attachment', 'image/jpeg' ); $wpdb->meta( 109, 'img_guid', 'g-109-aaaa' ); $wpdb->meta( 109, '_wp_attached_file', '2025/10/mgal.jpg' ); $wpdb->meta( 20, '_product_image_gallery', '109' );
$wpdb->post( 110, 'attachment', 'image/webp' );

$audit = new Mobo_Core_Legacy_Image_Audit();
$state = $audit->run( 5.0, 5 );
check( 'complete' === $state['status'], 'scan completes within one slice on a small site' );
check( 0 === $wpdb->writes, 'scanner issued no database write' );
$classes = array();
foreach ( $state['classes'] as $c => $row ) { $classes[ $c ] = $row['count']; }
check( 9 === $state['scanned'], 'every JPG/PNG attachment scanned once (WebP excluded)' );
check( 1 === $classes['delete_candidate'], 'exactly one delete candidate (101)' );
check( 1 === $classes['replace_then_delete'], 'one replace-then-delete (103)' );
check( 2 === $classes['protected_not_mobo'], 'merchant uploads (102, 107) protected' );
check( 4 === $classes['protected_foreign_use'], 'content (104), merchant copy (105), term (108) and merchant gallery (109) protected' );
check( 1 === $classes['protected_shared_file'], 'shared physical file protected (106)' );
$s101 = null; foreach ( $state['samples'] as $s ) { if ( 101 === $s['id'] ) { $s101 = $s; } }
check( null !== $s101 && 550 === $s101['bytes'] && 1 === $s101['missing'] && 3 === $s101['files'], 'real disk bytes of candidate measured; missing size file reported' );
$summary = Mobo_Core_Legacy_Image_Audit::health_summary();
check( 2 === $summary['candidates'] && 550 === $summary['bytes'] && 0 === $summary['deleted'] && false === $summary['deletionEnabled'], 'health summary: candidates, bytes, deleted=0, deletion disabled' );
check( array_key_exists( 'lastRunAt', $summary ) && $summary['lastRunAt'] > 0, 'health summary has lastRunAt' );

/* ---- crash / resume idempotency ---- */
$audit->reset();
$wpdb->fail_next = "pm.meta_key = '_thumbnail_id'"; // DB dies in the middle of the first attachment batch.
$st = $audit->run( 5.0, 5 );
check( '' !== $st['lastError'] && 0 === $st['scanned'] && 0 === $st['cursor'], 'failed batch is not committed (cursor and counters unchanged)' );
$st = $audit->run( 5.0, 5 );
$classes2 = array(); foreach ( $st['classes'] as $c => $row ) { $classes2[ $c ] = $row['count']; }
check( 'complete' === $st['status'] && $classes2 === $classes && 9 === $st['scanned'], 'resume after failure gives identical totals (no double counting)' );

/* ---- Stop behaviour ---- */
$audit->reset();
update_option( Mobo_Core_Legacy_Image_Audit::STOP_OPTION, '1' );
$GLOBALS['t_options'][ Mobo_Core_Legacy_Image_Audit::STATE_OPTION ] = array_merge( Mobo_Core_Legacy_Image_Audit::empty_state(), array( 'status' => 'running', 'phase' => 'attachments', 'contentMaxId' => 900 ) );
class Stop_Probe_Audit extends Mobo_Core_Legacy_Image_Audit {
	public $batches = 0;
	public function scan_batch( $cursor, $limit, array $content_refs = array(), $content_overflow = false ) {
		$this->batches++;
		if ( 1 === $this->batches ) { update_option( self::STOP_OPTION, '1' ); } // Stop pressed while the first batch runs.
		return parent::scan_batch( $cursor, $limit, $content_refs, $content_overflow );
	}
}
$probe = new Stop_Probe_Audit();
$st    = $probe->run( 5.0, 5 );
check( 1 === $probe->batches && 'stopped' === $st['status'], 'Stop pressed during a batch halts before the next batch' );
check( 5 === $st['scanned'] && $st['cursor'] > 0, 'work done before Stop is kept' );
$st = $probe->run( 5.0, 5 );
check( 'complete' === $st['status'] && 9 === $st['scanned'], 'explicit run after Stop resumes from the cursor and completes' );
$st = $audit->request_stop();
check( 'complete' === $st['status'], 'Stop on a completed scan does not change its status' );

/* ---- content overflow => uncertain ---- */
$batch = $audit->scan_batch( 0, 50, array(), true );
$unc = 0; foreach ( $batch['items'] as $it ) { if ( 'protected_uncertain' === $it['class'] ) { $unc++; } if ( 'delete_candidate' === $it['class'] ) { $unc = -100; } }
check( $unc > 0, 'content reference overflow turns would-be candidates into uncertain' );

@unlink( $GLOBALS['t_basedir'] . '/2025/10/del.jpg' ); @unlink( $GLOBALS['t_basedir'] . '/2025/10/del-150x150.jpg' );
@rmdir( $GLOBALS['t_basedir'] . '/2025/10' ); @rmdir( $GLOBALS['t_basedir'] . '/2025' ); @rmdir( $GLOBALS['t_basedir'] );

echo PHP_EOL . ( $failures ? "FAILED {$failures} of {$checks}" : "OK {$checks} checks" ) . PHP_EOL;
exit( $failures ? 1 : 0 );
