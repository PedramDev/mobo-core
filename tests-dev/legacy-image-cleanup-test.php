<?php
/**
 * Standalone tests for the READ-ONLY legacy Mobo image scan:
 * strong-ownership rules, reference extraction (Elementor JSON, Gutenberg,
 * shortcodes, serialized ACF/options, CSS), the site-wide reference index,
 * size estimation, resume/crash idempotency, oversized rows, Stop and the
 * request deadline.
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
	public $prefix = 'wp_'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $termmeta = 'wp_termmeta';
	public $term_taxonomy = 'wp_term_taxonomy'; public $usermeta = 'wp_usermeta'; public $options = 'wp_options';
	public $last_error = ''; public $writes = 0; public $fail_next = ''; public $fetched_lengths = array(); public $queries = array();
	public $p = array();   // ID => [type, mime, parent, content, excerpt, title]
	public $m = array();   // [meta_id, post_id, key, value]
	public $tm = array();  // [meta_id, term_id, key, value]
	public $tt = array();  // [term_taxonomy_id, taxonomy, description]
	public $um = array();  // [umeta_id, user_id, key, value]
	public $o = array();   // [option_id, name, value]
	public $extra = array(); // table => [pk, columns(name=>type), rows]
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
		return array_map( function ( $v ) { return stripslashes( trim( $v, " '" ) ); }, explode( ',', $mm[1] ) );
	}
	private function guard( $q ) {
		$this->last_error = '';
		$this->queries[] = $q;
		if ( preg_match( '/^\s*(insert|update|delete|replace|alter|drop|create|truncate)/i', $q ) ) { $this->writes++; }
		if ( '' !== $this->fail_next && false !== strpos( $q, $this->fail_next ) ) { $this->fail_next = ''; $this->last_error = 'simulated failure'; return false; }
		return true;
	}
	/** Generic table view: pk => row (assoc). */
	function table( $name ) {
		$rows = array();
		if ( 'wp_posts' === $name ) { foreach ( $this->p as $id => $r ) { $rows[ $id ] = array( 'ID' => $id, 'post_type' => $r[0], 'post_mime_type' => $r[1], 'post_parent' => $r[2], 'post_content' => $r[3], 'post_excerpt' => $r[4], 'post_title' => $r[5] ); } }
		elseif ( 'wp_postmeta' === $name ) { foreach ( $this->m as $r ) { $rows[ $r[0] ] = array( 'meta_id' => $r[0], 'post_id' => $r[1], 'meta_key' => $r[2], 'meta_value' => $r[3] ); } }
		elseif ( 'wp_termmeta' === $name ) { foreach ( $this->tm as $r ) { $rows[ $r[0] ] = array( 'meta_id' => $r[0], 'term_id' => $r[1], 'meta_key' => $r[2], 'meta_value' => $r[3] ); } }
		elseif ( 'wp_term_taxonomy' === $name ) { foreach ( $this->tt as $r ) { $rows[ $r[0] ] = array( 'term_taxonomy_id' => $r[0], 'taxonomy' => $r[1], 'description' => $r[2] ); } }
		elseif ( 'wp_usermeta' === $name ) { foreach ( $this->um as $r ) { $rows[ $r[0] ] = array( 'umeta_id' => $r[0], 'user_id' => $r[1], 'meta_key' => $r[2], 'meta_value' => $r[3] ); } }
		elseif ( 'wp_options' === $name ) { foreach ( $this->o as $r ) { $rows[ $r[0] ] = array( 'option_id' => $r[0], 'option_name' => $r[1], 'option_value' => $r[2] ); } }
		elseif ( isset( $this->extra[ $name ] ) ) { foreach ( $this->extra[ $name ]['rows'] as $r ) { $rows[ $r[ $this->extra[ $name ]['pk'] ] ] = $r; } }
		ksort( $rows );
		return $rows;
	}
	private function row_len( $row, $len_expr ) {
		preg_match_all( '/LENGTH\(`(\w+)`\)/', $len_expr, $mm ); $len = 0;
		foreach ( $mm[1] as $c ) { $len += strlen( (string) $row[ $c ] ); }
		return $len;
	}
	private function option_excluded( $row, $q ) {
		if ( ! isset( $row['option_name'] ) || false === strpos( $q, 'NOT LIKE' ) ) { return false; }
		$n = $row['option_name'];
		return 0 === strpos( $n, '_transient_' ) || 0 === strpos( $n, '_site_transient_' ) || in_array( $n, array( 'rewrite_rules', 'cron' ), true );
	}
	function get_var( $q ) {
		if ( ! $this->guard( $q ) ) { return null; }
		if ( false !== strpos( $q, 'COUNT(*)' ) ) { return count( array_filter( $this->p, function ( $r ) { return 'attachment' === $r[0] && in_array( $r[1], array( 'image/jpeg', 'image/png' ), true ); } ) ); }
		if ( preg_match( '/SELECT MAX\(`(\w+)`\) FROM `(\w+)`/', $q, $mm ) ) { $t = $this->table( $mm[2] ); return empty( $t ) ? null : max( array_keys( $t ) ); }
		if ( preg_match( '/SELECT meta_id FROM wp_postmeta WHERE post_id = (\d+) AND meta_key IN/', $q, $mm ) ) {
			$keys = $this->ids( $q, 'meta_key IN' );
			foreach ( $this->m as $row ) { if ( (int) $row[1] === (int) $mm[1] && in_array( $row[2], $keys, true ) && '' !== $row[3] ) { return $row[0]; } }
			return null;
		}
		return null; // SHOW TABLES LIKE 'wp_icl_translations' => absent
	}
	function get_col( $q ) {
		if ( ! $this->guard( $q ) ) { return null; }
		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/", trim( $q ), $mm ) ) {
			$like = str_replace( '%', '', stripslashes( stripslashes( $mm[1] ) ) ); $out = array();
			foreach ( array_keys( $this->extra ) as $t ) { if ( 0 === strpos( $t, $like ) ) { $out[] = $t; } }
			return $out;
		}
		if ( false !== strpos( $q, "post_mime_type = 'image/webp' AND ID IN" ) ) {
			$ids = $this->ids( $q, 'ID IN' );
			return array_values( array_filter( $ids, function ( $id ) { return isset( $this->p[ (int) $id ] ) && 'image/webp' === $this->p[ (int) $id ][1]; } ) );
		}
		if ( false !== strpos( $q, 'SELECT meta_value FROM wp_termmeta' ) ) {
			$ids = $this->ids( $q, 'meta_value IN' ); $out = array();
			foreach ( $this->tm as $r ) { if ( in_array( (string) $r[3], $ids, true ) ) { $out[] = $r[3]; } }
			return $out;
		}
		if ( false !== strpos( $q, 'SELECT option_name FROM wp_options WHERE option_name IN' ) ) {
			$names = $this->ids( $q, 'option_name IN' ); $out = array();
			foreach ( $this->o as $r ) { if ( in_array( $r[1], $names, true ) && 0 === strpos( $r[2], 'field_' ) ) { $out[] = $r[1]; } }
			return $out;
		}
		return array();
	}
	function get_results( $q, $o = null ) {
		if ( ! $this->guard( $q ) ) { return null; }
		if ( preg_match( "/SELECT ID, post_mime_type, post_parent, post_title, .* FROM wp_posts\s+WHERE post_type = 'attachment' AND ID > (\d+).*LIMIT (\d+)/s", $q, $mm ) ) {
			$out = array(); ksort( $this->p );
			foreach ( $this->p as $id => $r ) {
				if ( $id > (int) $mm[1] && 'attachment' === $r[0] && in_array( $r[1], array( 'image/jpeg', 'image/png' ), true ) ) { $out[] = array( 'ID' => $id, 'post_mime_type' => $r[1], 'post_parent' => $r[2], 'post_title' => $r[5], 'text_len' => strlen( $r[3] ) + strlen( $r[4] ) ); }
				if ( count( $out ) >= (int) $mm[2] ) { break; }
			}
			return $out;
		}
		if ( preg_match( '/^SHOW COLUMNS FROM `(\w+)`/', trim( $q ), $mm ) ) {
			$out = array();
			foreach ( $this->extra[ $mm[1] ]['columns'] as $name => $type ) { $out[] = array( 'Field' => $name, 'Type' => $type, 'Key' => $name === $this->extra[ $mm[1] ]['pk'] ? 'PRI' : '' ); }
			return $out;
		}
		if ( false !== strpos( $q, "post_type = 'acf-field'" ) ) {
			$out = array();
			foreach ( $this->p as $r ) { if ( 'acf-field' === $r[0] ) { $out[] = array( 'post_excerpt' => $r[4], 'post_content' => $r[3] ); } }
			return $out;
		}
		/* Reference pass plan: pk + summed length. */
		if ( preg_match( '/^SELECT `(\w+)` AS pk, \((.*?)\) AS len FROM `(\w+)` WHERE `\w+` > (\d+) AND `\w+` <= (\d+).*LIMIT (\d+)$/s', trim( $q ), $mm ) ) {
			$out = array();
			foreach ( $this->table( $mm[3] ) as $pk => $row ) {
				if ( $pk > (int) $mm[4] && $pk <= (int) $mm[5] && ! $this->option_excluded( $row, $q ) ) { $out[] = array( 'pk' => $pk, 'len' => $this->row_len( $row, $mm[2] ) ); }
				if ( count( $out ) >= (int) $mm[6] ) { break; }
			}
			return $out;
		}
		/* Reference pass fetch: values of rows not larger than the row limit. */
		if ( preg_match( '/^SELECT (`\w+` AS pk.*?) FROM `(\w+)` WHERE `\w+` > (\d+) AND `\w+` <= (\d+) AND \((.*?)\) <= (\d+)/s', trim( $q ), $mm ) ) {
			preg_match_all( '/`(\w+)` AS (\w+)/', $mm[1], $sel, PREG_SET_ORDER ); $out = array();
			foreach ( $this->table( $mm[2] ) as $pk => $row ) {
				if ( $pk <= (int) $mm[3] || $pk > (int) $mm[4] || $this->option_excluded( $row, $q ) ) { continue; }
				$len = $this->row_len( $row, $mm[5] );
				if ( $len > (int) $mm[6] ) { continue; }
				$this->fetched_lengths[] = $len; $r = array();
				foreach ( $sel as $s ) { $r[ $s[2] ] = $row[ $s[1] ]; }
				$out[] = $r;
			}
			return $out;
		}
		/* ACF probe on a meta table. */
		if ( preg_match( '/^SELECT `(\w+)` AS owner_id, meta_key FROM `(\w+)` WHERE `\w+` IN \(([^)]*)\) AND meta_key IN/', trim( $q ), $mm ) ) {
			$owners = explode( ',', $mm[3] ); $keys = $this->ids( $q, 'meta_key IN' ); $out = array();
			foreach ( $this->table( $mm[2] ) as $row ) { if ( in_array( (string) $row[ $mm[1] ], $owners, true ) && in_array( $row['meta_key'], $keys, true ) && 0 === strpos( $row['meta_value'], 'field_' ) ) { $out[] = array( 'owner_id' => $row[ $mm[1] ], 'meta_key' => $row['meta_key'] ); } }
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
		return array();
	}
	function post( $id, $type, $mime = '', $parent = 0, $content = '', $excerpt = '', $title = '' ) { $this->p[ $id ] = array( $type, $mime, $parent, $content, $excerpt, $title ); }
	function meta( $post_id, $key, $value ) { static $mid = 0; $this->m[] = array( ++$mid, $post_id, $key, $value ); }
	function termmeta( $term_id, $key, $value ) { $this->tm[] = array( count( $this->tm ) + 1, $term_id, $key, $value ); }
	function option( $name, $value ) { $this->o[] = array( count( $this->o ) + 1, $name, $value ); }
}

$base = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $base . 'class-mobo-core-request-deadline.php';
require $base . 'class-mobo-core-legacy-image-policy.php';
require $base . 'class-mobo-core-legacy-image-audit.php';

$failures = 0; $checks = 0;
function check( $cond, $label ) { global $failures, $checks; $checks++; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL; if ( ! $cond ) { $failures++; } }
function fresh_deadline() { Mobo_Core_Request_Deadline::reset( Mobo_Core_Request_Deadline::compute( microtime( true ), 30, microtime( true ) ) ); }

$P    = 'Mobo_Core_Legacy_Image_Policy';
$GUID = '0a1b2c3d-1111-2222-3333-444455556666';
$base_facts = array( 'mime' => 'image/jpeg', 'file' => '2025/10/abc.jpg', 'img_guid' => $GUID, 'refs_complete' => true, 'refs' => array(), 'filename_pattern' => 'title-equals-filename' );
$cls = function ( $over ) use ( $P, $base_facts ) { $r = $P::classify( array_merge( $base_facts, $over ) ); return $r['class']; };

/* ---- classification and ownership ---- */
check( $P::CLASS_DELETE_CANDIDATE === $cls( array() ), 'marker + our filename pattern, unreferenced => candidate' );
check( $P::CLASS_PROTECTED_WEAK_OWNERSHIP === $cls( array( 'filename_pattern' => '' ) ), 'img_guid alone (copyable marker) => unsure, protected' );
check( $P::CLASS_DELETE_CANDIDATE === $cls( array( 'filename_pattern' => '', 'linkage' => array( 'parent-mobo-product' ) ) ), 'marker + Mobo product parent => strong' );
check( $P::CLASS_DELETE_CANDIDATE === $cls( array( 'filename_pattern' => '', 'linkage' => array( 'refresh-marker' ) ) ), 'marker + our refresh marker => strong' );
check( $P::CLASS_DELETE_CANDIDATE === $cls( array( 'filename_pattern' => '', 'linkage' => array( 'image-queue-guid' ) ) ), 'marker + GUID known to our image queue => strong' );
check( $P::CLASS_PROTECTED_NOT_MOBO === $cls( array( 'img_guid' => '', 'image_guid' => '' ) ), 'no ownership marker => merchant upload / earliest unmarked version, never a candidate' );
check( $P::CLASS_PROTECTED_NOT_MOBO === $cls( array( 'img_guid' => '', 'filename_pattern' => 'source-hex-filename', 'linkage' => array( 'parent-mobo-product' ) ) ), 'filename pattern or linkage without marker never proves ownership' );
check( $P::CLASS_PROTECTED_UNCERTAIN === $cls( array( 'image_guid' => 'aaaaaaaa-0000', 'img_guid' => 'bbbbbbbb-0000' ) ), 'conflicting image_guid/img_guid => uncertain' );
check( $P::CLASS_PROTECTED_UNCERTAIN === $cls( array( 'img_guid' => 'https://x/y' ) ), 'URL-shaped identity => invalid => uncertain' );
check( $P::CLASS_DELETE_CANDIDATE === $cls( array( 'image_guid' => strtoupper( $GUID ) ) ), 'image_guid and img_guid agree case-insensitively' );
check( $P::CLASS_NOT_LEGACY === $cls( array( 'mime' => 'image/webp', 'file' => '2025/10/abc.webp' ) ), 'WebP is out of scope' );
check( $P::CLASS_NOT_LEGACY === $cls( array( 'mime' => 'image/jpeg', 'file' => '2025/10/abc.webp' ) ), 'webp extension wins over stale jpeg mime' );
check( $P::CLASS_PROTECTED_SHARED_MEDIA === $cls( array( 'shared_media' => true ) ), 'Shared Media protected' );
check( $P::CLASS_PROTECTED_UNCERTAIN === $cls( array( 'file' => '../../wp-config.jpg' ) ), 'path traversal => uncertain' );
check( $P::CLASS_PROTECTED_SHARED_FILE === $cls( array( 'shares_physical_file' => true ) ), 'physical file shared with another attachment => protected' );
check( $P::CLASS_PROTECTED_FOREIGN_USE === $cls( array( 'parent_non_mobo' => true ) ), 'copy attached to a merchant product => protected' );
check( $P::CLASS_PROTECTED_LIVE === $cls( array( 'live_in_image_queue' => true ) ), 'current image-queue attachment => protected' );
check( $P::CLASS_PROTECTED_UNCERTAIN === $cls( array( 'guid_pending_raster' => true ) ), 'pending raster source for GUID => uncertain' );
check( $P::CLASS_PROTECTED_UNCERTAIN === $cls( array( 'refs_complete' => false ) ), 'incomplete reference check never yields a candidate' );
$mobo_ref = array( array( 'kind' => 'featured', 'mobo' => true ) );
check( $P::CLASS_REPLACE_THEN_DELETE === $cls( array( 'refs' => $mobo_ref, 'webp_available' => true ) ), 'featured on Mobo product + WebP => replace then delete (a)' );
check( $P::CLASS_REPLACE_THEN_DELETE === $cls( array( 'filename_pattern' => '', 'refs' => $mobo_ref, 'webp_available' => true ) ), 'Mobo product reference is itself ownership linkage' );
check( $P::CLASS_IN_USE_NO_REPLACEMENT === $cls( array( 'refs' => $mobo_ref ) ), 'in use, no WebP known => keep' );
check( $P::CLASS_PROTECTED_FOREIGN_USE === $cls( array( 'refs' => array( array( 'kind' => 'featured', 'mobo' => false ) ), 'webp_available' => true ) ), 'featured on merchant product => protected' );
foreach ( array( 'termmeta', 'options', 'content', 'translation' ) as $kind ) {
	check( $P::CLASS_PROTECTED_REFERENCED === $cls( array( 'refs' => array( array( 'kind' => $kind, 'mobo' => null ) ), 'webp_available' => true ) ), "reference kind '{$kind}' => protected_referenced" );
}
$f = $P::finalize_candidate( $P::CLASS_DELETE_CANDIDATE, $P::source_bit( 'elementor' ) | $P::source_bit( 'acf' ), false );
check( $P::CLASS_PROTECTED_REFERENCED === $f['class'] && array( 'elementor', 'acf' ) === $f['sources'], 'finalize: any hit protects and names every source' );
$f = $P::finalize_candidate( $P::CLASS_DELETE_CANDIDATE, 0, true );
check( $P::CLASS_PROTECTED_UNCERTAIN === $f['class'], 'finalize: incomplete index => unsure, never unused' );
check( $P::CLASS_DELETE_CANDIDATE === $P::finalize_candidate( $P::CLASS_DELETE_CANDIDATE, 0, false )['class'], 'finalize: no hit, complete index => candidate' );

/* ---- filename patterns our download code produced ---- */
check( 'title-equals-filename' === $P::filename_pattern( 'گوشی-سامسونگ.jpg', 0, '2025/10/گوشی-سامسونگ.jpg', array() ), 'v1: title = file basename with extension' );
check( 'title-equals-filename' === $P::filename_pattern( 'photo.jpeg', 0, '2025/10/photo-scaled.jpeg', array( 'original_image' => 'photo.jpeg' ) ), 'v1: -scaled main file compared with original_image' );
check( 'source-hex-filename' === $P::filename_pattern( '5897fb', 0, '2025/10/5897fb-1-scaled.jpeg', array() ), 'Mobo Source hex file name with -N/-scaled' );
check( 'source-url-filename' === $P::filename_pattern( 'x', 0, '2026/01/shoe-2.png', array(), 'https://cdn.example/img/shoe.png?v=3' ), 'file = basename of recorded mobo_source_url (+ -N)' );
check( '' === $P::filename_pattern( 'My Photo', 0, '2025/10/My-Photo.jpg', array() ), 'normal WordPress upload (title without extension) is not our pattern' );
check( '' === $P::filename_pattern( 'abc.jpg', 12, '2025/10/abc.jpg', array() ), 'edited caption/description => not produced by our code' );

/* ---- reference extraction ---- */
$elementor = '[{"id":"3f2a1b","elType":"section","settings":{"background_image":{"url":"https:\/\/shop.test\/wp-content\/uploads\/2025\/10\/bg-1024x768.jpg","id":4001,"size":""}},"elements":[{"id":"9c8d","elType":"widget","settings":{"image":{"url":"https:\/\/shop.test\/wp-content\/uploads\/2025\/10\/گوشی.jpg","id":"4002"},"gallery":[{"id":4003,"url":"https:\/\/shop.test\/wp-content\/uploads\/2025\/10\/g1.png"}]},"widgetType":"image"}]}]';
$r = $P::extract_content_references( $elementor );
check( in_array( 4001, $r['ids'], true ) && in_array( 4002, $r['ids'], true ) && in_array( 4003, $r['ids'], true ), 'Elementor JSON: media ids with url extracted (int and string)' );
check( ! in_array( 3, $r['ids'], true ) && ! in_array( 9, $r['ids'], true ), 'Elementor element ids (hex) are not read as attachment ids' );
check( in_array( 'bg-1024x768.jpg', $r['basenames'], true ) && in_array( 'g1.png', $r['basenames'], true ), 'Elementor JSON: escaped-slash URLs give basenames' );
check( in_array( 'گوشی.jpg', $r['basenames'], true ), 'Elementor JSON: \\u-escaped Persian file name decoded' );
$r = $P::extract_content_references( '{"id":23343,"title":"Shop","thumbnail":"https:\/\/library.test\/t.png","url":"https:\/\/library.elementor.test\/kit\/shop\/"}' );
check( ! in_array( 23343, $r['ids'], true ), 'remote template cache: bare "id" next to a page url is not an attachment' );
$r = $P::extract_content_references( '<!-- wp:image {"id":31,"sizeSlug":"large"} --><figure><img src="/wp-content/uploads/2025/10/abc-300x300.jpg?ver=2" class="wp-image-77"/></figure><!-- /wp:image --><!-- wp:gallery {"ids":[5,9]} --><!-- wp:media-text {"mediaId":44} -->' );
check( in_array( 31, $r['ids'], true ) && in_array( 77, $r['ids'], true ) && in_array( 5, $r['ids'], true ) && in_array( 9, $r['ids'], true ) && in_array( 44, $r['ids'], true ), 'Gutenberg: block id, wp-image class, gallery ids, mediaId' );
check( in_array( 'abc-300x300.jpg', $r['basenames'], true ), 'Gutenberg: size basename from URL with query' );
$r = $P::extract_content_references( '[vc_row bg_image="61"][vc_single_image image="62" img_size="full"][vc_gallery images="63,64"][gallery ids="65, 66" size="large"][rev_slider alias="home"]' );
check( array() === array_diff( array( 61, 62, 63, 64, 65, 66 ), $r['ids'] ), 'WPBakery/classic shortcodes: image, images, bg_image, gallery ids' );
check( ! in_array( 0, $r['ids'], true ), 'no zero id' );
$acf_ser = 'a:3:{s:4:"logo";a:2:{s:3:"url";s:47:"https://shop.test/wp-content/uploads/a/logo.png";s:2:"id";i:88;}s:7:"gallery";a:2:{i:0;s:2:"91";i:1;i:92;}s:9:"image_ids";s:5:"93,94";}';
$r = $P::extract_content_references( $acf_ser );
check( array() === array_diff( array( 88, 91, 92, 93, 94 ), $r['ids'] ) && in_array( 'logo.png', $r['basenames'], true ), 'serialized kit/ACF: {url,id}, gallery array, CSV image_ids, basename' );
$r = $P::extract_content_references( 'a:2:{s:2:"id";i:555;s:5:"title";s:4:"menu";}' );
check( ! in_array( 555, $r['ids'], true ), 'serialized bare "id" without an image url is not an attachment' );
$r = $P::extract_content_references( '.elementor-12 .x{background-image:url("https://shop.test/wp-content/uploads/2025/10/hero-1536x864.jpg");} .y{background:url(../../2025/10/Tile.PNG)}' );
check( in_array( 'hero-1536x864.jpg', $r['basenames'], true ) && in_array( 'tile.png', $r['basenames'], true ), 'CSS url() basenames (lower-cased)' );
$r = $P::extract_content_references( 'photo attachment:55859 uploaded' );
check( in_array( 55859, $r['ids'], true ), 'plugin handle attachment:N' );
$r = $P::extract_content_references( 'plain text 123 width="300" price=4500' );
check( empty( $r['basenames'] ) && empty( $r['ids'] ), 'no false extraction from plain numbers/attributes' );
check( array( 12 ) === $P::extract_value_ids( 'hero_image', ' 12 ' ), 'numeric value under media key' );
check( array( 3, 4 ) === $P::extract_value_ids( 'product_gallery', '3,4' ), 'CSV under gallery key' );
check( array( 7, 8 ) === $P::extract_value_ids( 'slides', 'a:2:{i:0;s:1:"7";i:1;i:8;}' ), 'serialized array under media key' );
check( array( 9 ) === $P::extract_value_ids( 'whatever', '[9]', true ), 'ACF field forces media semantics (JSON array)' );
check( array() === $P::extract_value_ids( '_stock', '12' ) && array() === $P::extract_value_ids( '_price', '12' ), 'numeric value under unrelated key is not a reference' );
check( $P::is_media_key( '_thumbnail_id' ) && $P::is_media_key( 'mediaId' ) && $P::is_media_key( 'site_logo' ) && $P::is_media_key( 'bg' ) && $P::is_media_key( 'hero_background' ), 'media key heuristic: _id, camelId, logo, bg, background' );
check( ! $P::is_media_key( 'guid' ) && ! $P::is_media_key( 'paid' ) && ! $P::is_media_key( '_stock' ), 'media key heuristic rejects guid/paid/_stock' );
$acf = array( 'hero' => 1, 'pics' => 1 );
check( $P::is_acf_media_key( 'hero', $acf ) && $P::is_acf_media_key( 'rows_0_pics', $acf ) && $P::is_acf_media_key( 'options_hero', $acf ) && ! $P::is_acf_media_key( 'heroic', $acf ), 'ACF media key: exact, repeater sub-field, options page' );
check( 'abc.jpg' === $P::basename_root( 'abc-300x300.jpg' ) && 'abc.jpg' === $P::basename_root( 'abc-scaled.jpg' ) && 'abc.jpg' === $P::basename_root( 'abc-q1w2e3r4t5y6u7i8o9p0a1s2d3f4.jpg' ) && 'abc-1.jpg' === $P::basename_root( 'abc-1-150x150.jpg' ), 'basename root strips size/scaled/thumb hash, keeps -N' );

/* ---- size estimation ---- */
$meta = array( 'file' => '2025/10/abc.jpg', 'filesize' => 1000, 'original_image' => 'abc-orig.jpg', 'sizes' => array(
	'thumbnail' => array( 'file' => 'abc-150x150.jpg', 'filesize' => 100 ),
	'medium'    => array( 'file' => 'abc-300x300.jpg', 'filesize' => 300 ),
	'dup'       => array( 'file' => 'abc-150x150.jpg', 'filesize' => 100 ),
	'evil'      => array( 'file' => '../../../x.jpg' ),
) );
$files = $P::family_files( '2025/10/abc.jpg', $meta );
check( 5 === count( $files ) && isset( $files['2025/10/abc-orig.jpg'] ) && isset( $files['2025/10/x.jpg'] ), 'family = main + original + unique sizes; traversal reduced to basename' );
$disk = array( '2025/10/abc.jpg' => 1200, '2025/10/abc-150x150.jpg' => 90 );
$est  = $P::estimate_family( '2025/10/abc.jpg', $meta, function ( $rel ) use ( $disk ) { return isset( $disk[ $rel ] ) ? $disk[ $rel ] : false; } );
check( 1290 === $est['bytes'] && 2 === $est['existing'] && 3 === $est['missing'] && 1400 === $est['metaBytes'], 'bytes from real files; metadata bytes separate' );

/* ---- scanner on an in-memory site ---- */
$GLOBALS['t_basedir'] = sys_get_temp_dir() . '/mobo-legacy-test-' . getmypid();
@mkdir( $GLOBALS['t_basedir'] . '/2025/10', 0777, true );
@mkdir( $GLOBALS['t_basedir'] . '/elementor/css', 0777, true );
file_put_contents( $GLOBALS['t_basedir'] . '/2025/10/del.jpg', str_repeat( 'x', 500 ) );
file_put_contents( $GLOBALS['t_basedir'] . '/2025/10/del-150x150.jpg', str_repeat( 'x', 50 ) );
file_put_contents( $GLOBALS['t_basedir'] . '/elementor/css/post-30.css', '.e{background-image:url("https://shop.test/wp-content/uploads/2025/10/cssbg-768x512.jpg")}' );
file_put_contents( $GLOBALS['t_basedir'] . '/elementor/css/global.css', '.g{color:red}' );

function build_site() {
	$wpdb = new Fake_WPDB();
	$GLOBALS['wpdb'] = $wpdb;
	$ser = function ( $file, $sizes ) { $m = array( 'file' => $file, 'filesize' => 500, 'sizes' => array() ); foreach ( $sizes as $n => $f ) { $m['sizes'][ $n ] = array( 'file' => $f, 'filesize' => 50 ); } return serialize( $m ); };
	/* Legacy Mobo attachment: img_guid + title = basename.ext (v1 signature). */
	$legacy = function ( $id, $file, $mime = 'image/jpeg', $parent = 0, $title = null, $sizes = array() ) use ( $wpdb, $ser ) {
		$wpdb->post( $id, 'attachment', $mime, $parent, '', '', null === $title ? basename( $file ) : $title );
		$wpdb->meta( $id, 'img_guid', 'g-' . $id . '-aaaa' );
		$wpdb->meta( $id, '_wp_attached_file', $file );
		$wpdb->meta( $id, '_wp_attachment_metadata', $ser( $file, $sizes ) );
	};
	$wpdb->post( 10, 'product', '', 0, '', '', 'Mobo product' ); $wpdb->meta( 10, 'product_guid', 'pg-1' );
	$wpdb->post( 20, 'product', '', 0, '', '', 'Merchant product' );
	$wpdb->post( 30, 'page', '', 0, '<p><img src="/wp-content/uploads/2025/10/incontent.jpg"></p>', '', 'Page' );
	$wpdb->post( 31, 'revision', '', 30, '<img src="/wp-content/uploads/2025/10/onlyrev-300x300.jpg">', '', 'Rev' );
	$wpdb->post( 32, 'page', '', 0, '', '', 'Elementor page' );
	$wpdb->meta( 32, '_elementor_data', '[{"id":"a1","settings":{"image":{"url":"https:\/\/shop.test\/wp-content\/uploads\/2025\/10\/elem-300x300.jpg","id":"","size":""}}}]' );
	$wpdb->meta( 32, '_stock', '119' );           /* numeric under an unrelated key: NOT a reference */
	$wpdb->meta( 32, 'promo', '112' );            /* ACF field without DB definition ... */
	$wpdb->meta( 32, '_promo', 'field_5f1a2b3c' ); /* ... declared by its ACF reference row */
	$wpdb->post( 33, 'acf-field', '', 0, serialize( array( 'type' => 'gallery' ) ), 'shots', 'Shots' );
	$wpdb->meta( 32, 'shots', serialize( array( '122' ) ) );
	$legacy( 101, '2025/10/del.jpg', 'image/jpeg', 0, null, array( 'thumbnail' => 'del-150x150.jpg', 'medium' => 'del-300x300.jpg' ) );
	$wpdb->post( 102, 'attachment', 'image/jpeg', 0, '', '', 'merchant' ); $wpdb->meta( 102, '_wp_attached_file', '2019/01/merchant.jpg' ); $wpdb->meta( 20, '_thumbnail_id', '102' );
	$legacy( 103, '2025/10/inuse.jpg' ); $wpdb->meta( 103, 'mobo_image_refresh_replaced_by_attachment_id', '900' ); $wpdb->meta( 10, '_thumbnail_id', '103' );
	$wpdb->post( 900, 'attachment', 'image/webp', 0, '', '', 'new' ); $wpdb->meta( 900, 'mobo_refreshed_from_attachment_id', '103' ); $wpdb->meta( 900, 'mobo_source_url', 'https://src.test/inuse.jpg' );
	$legacy( 104, '2025/10/incontent.jpg' );
	$legacy( 105, '2025/10/copy.jpg', 'image/jpeg', 20 );
	$legacy( 106, '2025/10/same.jpg' ); $wpdb->post( 107, 'attachment', 'image/jpeg', 0, '', '', 'same' ); $wpdb->meta( 107, '_wp_attached_file', '2025/10/same.jpg' );
	$legacy( 108, '2025/10/term.png', 'image/png' ); $wpdb->meta( 10, '_product_image_gallery', '1108,108, 55' ); $wpdb->termmeta( 5, 'thumbnail_id', '108' );
	$legacy( 109, '2025/10/mgal.jpg' ); $wpdb->meta( 20, '_product_image_gallery', '109' );
	$wpdb->post( 110, 'attachment', 'image/webp', 0, '', '', 'webp' );
	$legacy( 111, '2025/10/elem.jpg', 'image/jpeg', 0, null, array( 'medium' => 'elem-300x300.jpg' ) );
	$legacy( 112, '2025/10/acf.jpg' );
	$legacy( 113, '2025/10/logo.png', 'image/png' ); $wpdb->option( 'theme_mods_shop', 'a:1:{s:11:"custom_logo";i:113;}' );
	$legacy( 115, '2025/10/slide.jpg' ); $wpdb->extra['wp_revslider_slides'] = array( 'pk' => 'id', 'columns' => array( 'id' => 'int(9)', 'slider_id' => 'int(9)', 'params' => 'longtext', 'layers' => 'longtext' ), 'rows' => array( array( 'id' => 1, 'slider_id' => 1, 'params' => '{}', 'layers' => '{"bg":{"image":"https:\/\/shop.test\/wp-content\/uploads\/2025\/10\/slide.jpg"}}' ) ) );
	$legacy( 116, '2025/10/cssbg.jpg' );
	$legacy( 117, '2025/10/onlyrev.jpg' );
	$legacy( 118, '2025/10/Weak Photo.jpg', 'image/jpeg', 0, 'Weak Photo' ); /* marker but no linkage / no pattern */
	$legacy( 119, '2025/10/stock.jpg' );
	$legacy( 120, '2025/10/twin.jpg' );
	$wpdb->post( 121, 'attachment', 'image/jpeg', 0, '', '', 'other' ); $wpdb->meta( 121, '_wp_attached_file', '2025/10/twin-x.jpg' ); $wpdb->meta( 121, '_wp_attachment_metadata', serialize( array( 'file' => '2025/10/twin-x.jpg', 'sizes' => array( 's' => array( 'file' => 'twin.jpg' ) ) ) ) );
	$legacy( 122, '2025/10/shot.jpg' );
	$legacy( 123, '2026/01/dup.jpg' ); /* same basename as a merchant file elsewhere: different path => not shared */
	$wpdb->post( 124, 'attachment', 'image/jpeg', 0, '', '', 'dup' ); $wpdb->meta( 124, '_wp_attached_file', '2019/05/dup.jpg' );
	$wpdb->option( 'elementor_remote_info_library', '{"templates":[{"id":101,"title":"Shop","url":"https:\/\/library.test\/shop\/"}]}' );
	$wpdb->option( '_transient_feed_1', '<img src="/wp-content/uploads/2025/10/del.jpg">' ); /* caches are not scanned */
	return $wpdb;
}
$wpdb = build_site();

$audit = new Mobo_Core_Legacy_Image_Audit();
fresh_deadline();
$state = $audit->run( 5.0, 5 );
check( 'complete' === $state['status'] && '' === $state['lastError'], 'scan completes (attachments, reference index, finalize) in one slice on a small site' );
check( 0 === $wpdb->writes, 'scanner issued no database write' );
$classes = array();
foreach ( $state['classes'] as $c => $row ) { $classes[ $c ] = $row['count']; }
check( 22 === $state['scanned'] && 22 === array_sum( $classes ), 'every JPG/PNG attachment scanned and counted exactly once (WebP excluded)' );
$final = array();
class Recording_Audit extends Mobo_Core_Legacy_Image_Audit {
	public $final = array();
	public function merge_batch( array $state, array $batch ) {
		foreach ( isset( $batch['items'] ) ? $batch['items'] : array() as $it ) { if ( empty( $it['pending'] ) ) { $this->final[ (int) $it['id'] ] = array( $it['class'], isset( $it['sources'] ) ? $it['sources'] : array(), isset( $it['reason'] ) ? $it['reason'] : '' ); } }
		return parent::merge_batch( $state, $batch );
	}
}
$rec = new Recording_Audit(); $rec->reset(); fresh_deadline(); $rec->run( 5.0, 5 ); $final = $rec->final;
$is = function ( $id, $class, $source = null ) use ( &$final ) { return isset( $final[ $id ] ) && $class === $final[ $id ][0] && ( null === $source || in_array( $source, $final[ $id ][1], true ) ); };
check( $is( 101, 'delete_candidate' ), '101 unreferenced, own metadata does not self-protect => unused candidate' );
check( $is( 119, 'delete_candidate' ), '119: numeric _stock value equal to its ID is not a reference' );
check( $is( 123, 'delete_candidate' ), '123: same basename in another folder of another attachment is not a shared file' );
check( $is( 102, 'protected_not_mobo' ) && $is( 107, 'protected_not_mobo' ) && $is( 124, 'protected_not_mobo' ), 'merchant uploads never inspected' );
check( $is( 103, 'replace_then_delete' ), '103 featured on Mobo product, WebP replacement; provenance keys of the WebP do not protect it' );
check( $is( 104, 'protected_referenced', 'content' ), '104 referenced from page content' );
check( $is( 105, 'protected_foreign_use' ), '105 copy attached to merchant product' );
check( $is( 106, 'protected_shared_file' ), '106 shares its physical file' );
check( $is( 108, 'protected_referenced', 'termmeta' ), '108 category thumbnail (termmeta)' );
check( $is( 109, 'protected_foreign_use' ), '109 in merchant product gallery' );
check( $is( 111, 'protected_referenced', 'elementor' ), '111 Elementor data references a generated size' );
check( $is( 112, 'protected_referenced', 'acf' ), '112 ACF value declared by its _field reference row' );
check( $is( 113, 'protected_referenced', 'options' ), '113 theme_mods custom_logo (serialized option)' );
check( $is( 115, 'protected_referenced', 'slider' ), '115 Revolution Slider layer' );
check( $is( 116, 'protected_referenced', 'css' ), '116 Elementor generated CSS file' );
check( $is( 117, 'protected_referenced', 'revision' ), '117 only in a revision => still protected (reported as revision)' );
check( $is( 118, 'protected_weak_ownership' ), '118 marker without linkage or our file name => unsure (weak ownership)' );
check( $is( 120, 'protected_referenced', 'sharedfile' ), '120 another attachment metadata lists the same file path' );
check( $is( 122, 'protected_referenced', 'acf' ), '122 ACF gallery field (definition stored as acf-field post)' );
check( ! isset( $final[101] ) || 'delete_candidate' === $final[101][0], 'remote Elementor library "id":101 and transient caches do not protect 101' );
$summary = Mobo_Core_Legacy_Image_Audit::health_summary();
check( 3 === $summary['deleteCandidates'] && 550 === $summary['unusedBytes'] && 0 === $summary['deleted'] && false === $summary['deletionEnabled'], 'health: unused candidates, bytes, deleted=0, deletion disabled' );
check( 10 === $summary['referenced'] && 1 === $summary['referencedBySource']['elementor'] && 2 === $summary['referencedBySource']['acf'] && 1 === $summary['unsureWeakOwnership'] && 4 === $summary['merchant'], 'health: referenced by source, weak ownership, merchant' );
check( true === $summary['referenceIndexComplete'] && $summary['lastRunAt'] > 0, 'health: index complete, lastRunAt' );
$reference_totals = $classes;

/* ---- resume / crash idempotency ---- */
$count_classes = function ( $st ) { $o = array(); foreach ( $st['classes'] as $c => $row ) { $o[ $c ] = $row['count']; } return $o; };
foreach ( array( "pm.meta_key = '_thumbnail_id'" => 'attachment pass', 'FROM `wp_postmeta` WHERE `meta_id` >' => 'reference pass (postmeta fetch)', 'SHOW COLUMNS' => 'source detection' ) as $needle => $label ) {
	$audit->reset();
	$wpdb->fail_next = $needle;
	fresh_deadline();
	$st = $audit->run( 5.0, 5 );
	check( '' !== $st['lastError'] && 'complete' !== $st['status'], "DB failure in {$label}: slice stops, nothing of the step committed" );
	fresh_deadline();
	$st = $audit->run( 5.0, 5 );
	check( 'complete' === $st['status'] && $count_classes( $st ) === $reference_totals && 22 === $st['scanned'], "resume after {$label} failure gives identical totals" );
}

/* Crash between writing the candidate chunk and committing the state (PHP killed). */
$audit->reset();
$st = Mobo_Core_Legacy_Image_Audit::get_state(); $st['status'] = 'running'; $st['cycleId'] = 'crash'; update_option( Mobo_Core_Legacy_Image_Audit::STATE_OPTION, $st, false );
$step = $audit->step( $st, 5 );
$step = $audit->step( $step['state'], 5 ); /* two batches written to chunks, state never committed */
fresh_deadline();
$st = $audit->run( 5.0, 5 );
check( 'complete' === $st['status'] && $count_classes( $st ) === $reference_totals, 'crash after chunk write, before state commit: no duplicate or lost candidates' );

/* Crash after the hit map was written but before the cursor moved. */
$audit->reset();
$st = Mobo_Core_Legacy_Image_Audit::get_state(); $st['status'] = 'running'; $st['cycleId'] = 'crash2';
for ( $i = 0; $i < 200 && 'refs' !== $st['phase']; $i++ ) { $st = $audit->step( $st, 5 )['state']; }
$st = $audit->step( $st, 5 )['state'];                       /* init sources */
for ( $i = 0; $i < 3; $i++ ) { $st = $audit->step( $st, 5 )['state']; }
update_option( Mobo_Core_Legacy_Image_Audit::STATE_OPTION, $st, false );
$audit->step( $st, 5 ); $audit->step( $st, 5 );              /* same page replayed twice, never committed */
fresh_deadline();
$st = $audit->run( 5.0, 5 );
check( 'complete' === $st['status'] && $count_classes( $st ) === $reference_totals, 'replayed reference pages (hits written, cursor not) do not change totals' );

/* Many tiny slices: Stop after every step, then continue. */
class Step_Limited_Audit extends Mobo_Core_Legacy_Image_Audit {
	public $steps = 0;
	public function step( array $state, $batch_size = self::BATCH_DEFAULT ) { $this->steps++; update_option( self::STOP_OPTION, '1' ); return parent::step( $state, $batch_size ); }
}
$tiny = new Step_Limited_Audit(); $tiny->reset(); $runs = 0;
do { fresh_deadline(); $st = $tiny->run( 5.0, 5 ); $runs++; } while ( 'complete' !== $st['status'] && $runs < 500 );
check( 'complete' === $st['status'] && $runs > 10 && $count_classes( $st ) === $reference_totals, 'scan split into ' . $runs . ' one-step slices (Stop/continue) gives identical totals' );

/* ---- request deadline ---- */
$audit->reset();
Mobo_Core_Request_Deadline::reset( Mobo_Core_Request_Deadline::compute( microtime( true ) - 29.0, 30, microtime( true ) ) );
$GLOBALS['t_option_writes'] = array();
$st = $audit->run( 8.0, 5 );
check( '' !== $st['lastError'] && 'idle' === $st['status'] && empty( $GLOBALS['t_option_writes'] ), 'no time left in the request: scan does not start and writes nothing' );
fresh_deadline();

/* ---- oversized row => whole site unsure, row never loaded ---- */
$wpdb = build_site();
$wpdb->meta( 32, '_elementor_data', str_repeat( 'a', Mobo_Core_Legacy_Image_Audit::ROW_MAX_BYTES + 10 ) );
$audit = new Recording_Audit(); $audit->reset(); fresh_deadline();
$st = $audit->run( 5.0, 5 );
$big_loaded = false; foreach ( $wpdb->fetched_lengths as $l ) { if ( $l > Mobo_Core_Legacy_Image_Audit::ROW_MAX_BYTES ) { $big_loaded = true; } }
check( 'complete' === $st['status'] && true === $st['incomplete'] && ! $big_loaded, 'row above the byte limit is skipped (never fetched) and marks the index incomplete' );
check( 0 === $st['classes']['delete_candidate']['count'] && 'protected_uncertain' === $audit->final[101][0] && 'reference-index-incomplete' === $audit->final[101][2], 'incomplete index: former candidates become unsure, none unused' );
check( 'protected_referenced' === $audit->final[104][0], 'incomplete index: hits found elsewhere still count as referenced' );
check( false === Mobo_Core_Legacy_Image_Audit::health_summary()['referenceIndexComplete'], 'health reports the incomplete index' );

/* Oversized Elementor CSS file. */
$wpdb = build_site();
file_put_contents( $GLOBALS['t_basedir'] . '/elementor/css/zz-huge.css', str_repeat( ' ', Mobo_Core_Legacy_Image_Audit::CSS_MAX_FILE_BYTES + 1 ) );
$audit = new Mobo_Core_Legacy_Image_Audit(); $audit->reset(); fresh_deadline();
$st = $audit->run( 5.0, 5 );
check( true === $st['incomplete'] && 0 === $st['classes']['delete_candidate']['count'], 'oversized CSS file => incomplete => no unused candidates' );
@unlink( $GLOBALS['t_basedir'] . '/elementor/css/zz-huge.css' );

/* Byte-budgeted pages: a page never exceeds PAGE_BYTES unless a single row does. */
$wpdb = build_site();
for ( $i = 0; $i < 6; $i++ ) { $wpdb->meta( 32, 'big_' . $i, str_repeat( 'b', (int) ( Mobo_Core_Legacy_Image_Audit::PAGE_BYTES * 0.6 ) ) ); }
$audit = new Mobo_Core_Legacy_Image_Audit(); $audit->reset(); fresh_deadline();
$st = $audit->run( 5.0, 5 );
check( 'complete' === $st['status'] && false === $st['incomplete'] && 3 === $st['classes']['delete_candidate']['count'], 'large (but allowed) rows are paged and scanned without marking the index incomplete' );
check( $st['refPages'] >= 6, 'large rows spread over several byte-budgeted pages (' . $st['refPages'] . ' pages)' );

/* ---- Stop behaviour ---- */
$wpdb = build_site();
$audit = new Mobo_Core_Legacy_Image_Audit(); $audit->reset();
class Stop_Probe_Audit extends Mobo_Core_Legacy_Image_Audit {
	public $batches = 0;
	public function scan_batch( $cursor, $limit ) {
		$this->batches++;
		if ( 1 === $this->batches ) { update_option( self::STOP_OPTION, '1' ); }
		return parent::scan_batch( $cursor, $limit );
	}
}
$probe = new Stop_Probe_Audit(); fresh_deadline();
$st    = $probe->run( 5.0, 5 );
check( 1 === $probe->batches && 'stopped' === $st['status'] && 5 === $st['scanned'], 'Stop pressed during a batch halts before the next step; work done is kept' );
fresh_deadline();
$st = $probe->run( 5.0, 5 );
check( 'complete' === $st['status'] && 22 === $st['scanned'], 'explicit run after Stop resumes from the cursor and completes' );
$st = $probe->request_stop();
check( 'complete' === $st['status'], 'Stop on a completed scan does not change its status' );
$GLOBALS['t_options'][ Mobo_Core_Legacy_Image_Audit::STATE_OPTION ] = array( 'version' => 1, 'status' => 'complete', 'classes' => array( 'delete_candidate' => array( 'count' => 2866 ) ) );
check( 'idle' === Mobo_Core_Legacy_Image_Audit::get_state()['status'] && 0 === Mobo_Core_Legacy_Image_Audit::health_summary()['deleteCandidates'], 'results of the older, weaker scanner version are discarded' );
$probe->reset();
$left = array_filter( array_keys( $GLOBALS['t_options'] ), function ( $k ) { return 0 === strpos( $k, Mobo_Core_Legacy_Image_Audit::CAND_PREFIX ) || Mobo_Core_Legacy_Image_Audit::HITS_OPTION === $k; } );
check( empty( $left ), 'reset removes candidate chunks and the hit map' );

@unlink( $GLOBALS['t_basedir'] . '/2025/10/del.jpg' ); @unlink( $GLOBALS['t_basedir'] . '/2025/10/del-150x150.jpg' );
@unlink( $GLOBALS['t_basedir'] . '/elementor/css/post-30.css' ); @unlink( $GLOBALS['t_basedir'] . '/elementor/css/global.css' );
@rmdir( $GLOBALS['t_basedir'] . '/elementor/css' ); @rmdir( $GLOBALS['t_basedir'] . '/elementor' );
@rmdir( $GLOBALS['t_basedir'] . '/2025/10' ); @rmdir( $GLOBALS['t_basedir'] . '/2025' ); @rmdir( $GLOBALS['t_basedir'] );

echo PHP_EOL . ( $failures ? "FAILED {$failures} of {$checks}" : "OK {$checks} checks" ) . PHP_EOL;
exit( $failures ? 1 : 0 );
