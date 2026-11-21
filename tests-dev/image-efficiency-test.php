<?php
/**
 * Standalone test for 10.34.78 image efficiency policies: conservative
 * same-image detection (no re-download for host/query-only URL changes, content
 * token wins) and the intermediate-size restriction for Mobo sideloads.
 *
 * Run: php tests-dev/image-efficiency-test.php
 * PHP 7.4+ compatible.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_meta']    = array();
$GLOBALS['t_options'] = array();
$GLOBALS['t_filters'] = array();
function get_post_meta( $id, $key, $single = false ) { return isset( $GLOBALS['t_meta'][ $id ][ $key ] ) ? $GLOBALS['t_meta'][ $id ][ $key ] : ''; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function apply_filters( $tag, $value ) { return isset( $GLOBALS['t_filters'][ $tag ] ) ? call_user_func( $GLOBALS['t_filters'][ $tag ], $value ) : $value; }
function absint( $v ) { return abs( (int) $v ); }

$base = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $base . 'class-mobo-core-image-source-identity.php';
require $base . 'class-mobo-core-image-size-policy.php';

$failures = 0;
$checks   = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	$checks++;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $failures++; }
}

$I = 'Mobo_Core_Image_Source_Identity';

// --- Same-image detection.
check( $I::same_source( 'https://cdn1.example.com/media/p/abc.webp', 'https://cdn2.example.net/media/p/abc.webp?v=3' ), 'host + query change, same path/name => same image' );
check( $I::same_source( 'http://a.example.com/m/x.webp', 'https://a.example.com/m/x.webp#frag' ), 'scheme/fragment change => same image' );
check( ! $I::same_source( 'https://cdn.example.com/media/p/abc.webp', 'https://cdn.example.com/media/p/abd.webp' ), 'different file name => new image' );
check( ! $I::same_source( 'https://cdn.example.com/media/p/abc.webp', 'https://cdn.example.com/media/q/abc.webp' ), 'different directory => new image' );
check( ! $I::same_source( 'https://cdn.example.com/media/p/ABC.webp', 'https://cdn.example.com/media/p/abc.webp' ), 'path comparison is case-sensitive (conservative)' );
check( ! $I::same_source( 'https://cdn.example.com/img?id=1', 'https://cdn.example.com/img?id=2' ), 'no file extension => query decides nothing, treated as new' );
check( ! $I::same_source( '', 'https://cdn.example.com/a.webp' ), 'missing stored URL => new image' );
check( $I::same_source( 'https://a.example.com/x.webp', 'https://a.example.com/x.webp' ), 'identical URL => same image' );
check( ! $I::same_source( 'https://a.example.com/x.webp', 'https://b.example.com/x.webp', 'hash-AAAA1', 'hash-BBBB2' ), 'differing content tokens => new image even with same path' );
check( $I::same_source( 'https://a.example.com/old-name.webp', 'https://b.example.com/new-name.webp', 'sha-123456', 'sha-123456' ), 'equal content tokens => same image even when renamed' );
check( $I::same_source( 'https://a.example.com/x.webp', 'https://b.example.com/x.webp?s=1', '', 'sha-123456' ), 'token on one side only => URL rule' );
check( 'sha-123456' === $I::content_token( array( 'url' => 'u', 'contentHash' => 'sha-123456' ) ), 'contentHash token read' );
check( 'abc1234' === $I::content_token( array( 'ETag' => 'W/"abc1234"' ) ), 'weak ETag normalized' );
check( '' === $I::content_token( array( 'hash' => 'x' ) ), 'too-short/invalid token ignored' );

$a = $I::canonicalize_for_hash( array( array( 'id' => 'g1', 'url' => 'https://cdn1.example.com/m/a.webp?sig=1', 'position' => 0 ) ) );
$b = $I::canonicalize_for_hash( array( array( 'id' => 'g1', 'url' => 'https://cdn2.example.com/m/a.webp?sig=2', 'position' => 0 ) ) );
$c = $I::canonicalize_for_hash( array( array( 'id' => 'g1', 'url' => 'https://cdn2.example.com/m/b.webp', 'position' => 0 ) ) );
check( $a === $b, 'convergence hash input ignores host/query-only changes' );
check( $a !== $c, 'convergence hash input changes when the file changes' );
check( 0 === $a[0]['position'] && 'g1' === $a[0]['id'], 'non-URL fields preserved for hashing' );

// --- Intermediate size restriction.
$S = 'Mobo_Core_Image_Size_Policy';
$registered = array(
	'thumbnail' => array( 'width' => 150 ), 'medium' => array( 'width' => 300 ), 'medium_large' => array( 'width' => 768 ),
	'large' => array( 'width' => 1024 ), '1536x1536' => array( 'width' => 1536 ), 'woocommerce_thumbnail' => array( 'width' => 300 ),
	'woocommerce_single' => array( 'width' => 600 ), 'woocommerce_gallery_thumbnail' => array( 'width' => 100 ), 'theme_hero' => array( 'width' => 1920 ),
);
check( array( 'thumbnail', 'woocommerce_gallery_thumbnail', 'woocommerce_single', 'woocommerce_thumbnail' ) === array_values( array_intersect( array( 'thumbnail', 'woocommerce_gallery_thumbnail', 'woocommerce_single', 'woocommerce_thumbnail' ), array_keys( $S::restrict( $registered, $S::allowed_sizes() ) ) ) ) && 4 === count( $S::restrict( $registered, $S::allowed_sizes() ) ), 'default keeps exactly the 4 WooCommerce-needed sizes' );
check( $registered === $S::filter_sizes( $registered, array(), 0 ), 'non-Mobo upload outside a Mobo sideload keeps every size' );
$S::begin_sideload();
check( 4 === count( $S::filter_sizes( $registered, array(), 0 ) ), 'during a Mobo sideload only the needed sizes are generated' );
$S::end_sideload();
$GLOBALS['t_meta'][55] = array( 'image_guid' => 'img-1' );
check( 4 === count( $S::filter_sizes( $registered, array(), 55 ) ), 'identified Mobo attachment (later regeneration/scans) uses the same subset' );
$GLOBALS['t_meta'][56] = array( 'image_guid' => 'img-2', '_mobo_shared_media_image_id' => 'shared-1' );
check( $registered === $S::filter_sizes( $registered, array(), 56 ), 'shared-media attachments are never restricted' );
$GLOBALS['t_options']['mobo_core_image_subsizes'] = 'all';
$S::begin_sideload();
check( $registered === $S::filter_sizes( $registered, array(), 0 ), "option 'all' restores WordPress' default" );
$GLOBALS['t_options']['mobo_core_image_subsizes'] = 'thumbnail, theme_hero';
check( array( 'thumbnail', 'theme_hero' ) === array_keys( $S::filter_sizes( $registered, array(), 0 ) ), 'custom size list honoured' );
$GLOBALS['t_options']['mobo_core_image_subsizes'] = 'not_registered_size';
check( $registered === $S::filter_sizes( $registered, array(), 0 ), 'misconfiguration never drops every size' );
$GLOBALS['t_filters']['mobo_core_image_subsizes'] = function () { return null; };
check( $registered === $S::filter_sizes( $registered, array(), 0 ), 'filter returning null => all sizes' );
$S::end_sideload();

echo PHP_EOL . $checks . ' checks, ' . $failures . ' failure(s) on PHP ' . PHP_VERSION . PHP_EOL;
exit( $failures > 0 ? 1 : 0 );
