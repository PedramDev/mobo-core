<?php
/**
 * Standalone test for product-delta-v1 rules and webhook-batch-v1 parsing/result
 * mapping (contract shared with the Backend).
 *
 * Run: php tests-dev/delta-batch-test.php
 * PHP 7.4+ compatible.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MOBO_CORE_VERSION', '10.34.77' );
$GLOBALS['__delta_db_version'] = '10.33.67';
function get_option( $name, $default = false ) { return 'mobo_core_db_version' === $name ? $GLOBALS['__delta_db_version'] : $default; }

class WP_Error {
	private $code;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->code; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }

final class Mobo_Core_Remote_Identity_Policy {
	public static function is_valid( $v ) { return is_string( $v ) && (bool) preg_match( '/^[A-Za-z0-9\-]{6,}$/', $v ); }
}
final class Mobo_Core_Event_Type_Policy {
	public static function map_numeric( $t ) { $m = array( 0 => 'ProductUpdated', 1 => 'UpdateVariant' ); return isset( $m[ (int) $t ] ) ? $m[ (int) $t ] : ''; }
}

$base = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $base . 'class-mobo-core-product-delta.php';
require $base . 'class-mobo-core-webhook-batch.php';

$failures = 0;
$checks   = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	$checks++;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $failures++; }
}

/** Fake local catalogue used as the delta resolver. */
class Fake_Resolver {
	public $products = array();
	public $variants = array();
	public function resolve_delta_product( $guid ) {
		return isset( $this->products[ $guid ] ) ? $this->products[ $guid ] : array( 'id' => 0, 'state' => 'missing' );
	}
	public function resolve_delta_variant( $guid, $product ) {
		if ( ! isset( $this->variants[ $guid ] ) ) { return array( 'id' => 0, 'state' => 'missing' ); }
		$v = $this->variants[ $guid ];
		return (int) $v['parent'] === (int) $product['id'] ? $v : array( 'id' => $v['id'], 'state' => 'inconsistent' );
	}
}

$D = 'Mobo_Core_Product_Delta';
$B = 'Mobo_Core_Webhook_Batch';

// --- is_delta / shape.
check( $D::is_delta( array( 'delta' => true, 'productId' => 'prod-0001' ) ), 'top-level delta:true' );
check( $D::is_delta( array( 'Delta' => 'true', 'data' => array( 'productId' => 'prod-0001' ) ) ), 'PascalCase "true" string' );
check( $D::is_delta( array( 'data' => array( array( 'productId' => 'prod-0001', 'delta' => true ) ) ) ), 'item-level delta inside data list' );
check( ! $D::is_delta( array( 'delta' => false, 'productId' => 'prod-0001' ) ), 'delta:false is a full payload' );
check( ! $D::is_delta( array( 'data' => array( array( 'productId' => 'prod-0001' ) ) ) ), 'legacy full payload is not a delta' );
check( 'prod-0001' === $D::product_guid( $D::product_item( array( 'data' => array( 'productId' => 'prod-0001' ) ) ) ), 'product id from data object' );
check( 'prod-0001' === $D::product_guid( $D::product_item( array( 'delta' => true, 'productId' => 'prod-0001' ) ) ), 'product id from flat payload' );

// --- present_fields keeps only changed contract fields.
$fields = $D::present_fields( array( 'price' => 1000, 'Title' => 'ignored', 'stock' => 0, 'compareAtPrice' => null, 'slug' => 'x' ), $D::PRODUCT_FIELD_ALIASES );
check( array( 'price' => 1000, 'compareAtPrice' => null, 'stock' => 0 ) == $fields, 'only delta fields kept (explicit null preserved, title/slug ignored)' );
check( ! array_key_exists( 'publishedAt', $D::logical_fields( array( 'price' => 1 ), $D::variant_field_aliases() ) ), 'variants never accept publishedAt' );
check( array( 'compareAtPrice' => 5 ) === $D::logical_fields( array( 'CompareAtPrice' => 5 ), $D::variant_field_aliases() ), 'PascalCase alias maps to logical field' );

// --- validation.
check( is_wp_error( $D::validate( array( 'delta' => true ) ) ), 'missing product identifier is invalid' );
check( is_wp_error( $D::validate( array( 'delta' => true, 'productId' => 'prod-0001', 'variants' => 'x' ) ) ), 'non-list variants invalid' );
check( is_wp_error( $D::validate( array( 'delta' => true, 'productId' => 'prod-0001', 'variants' => array( array( 'price' => 1 ) ) ) ) ), 'variant without identifier invalid' );
check( is_wp_error( $D::validate( array( 'delta' => true, 'productId' => 'prod-0001', 'variants' => array( array( 'variantId' => 'var-00001' ), array( 'variantId' => 'VAR-00001' ) ) ) ) ), 'duplicate variant invalid' );
check( true === $D::validate( array( 'delta' => true, 'productId' => 'prod-0001', 'revision' => 3 ) ), 'product-only delta valid' );

// --- precheck rules.
$r = new Fake_Resolver();
$r->products['prod-0001'] = array( 'id' => 10, 'state' => 'ok', 'type' => 'variable' );
$r->products['prod-0002'] = array( 'id' => 11, 'state' => 'quarantined' );
$r->products['prod-0003'] = array( 'id' => 12, 'state' => 'inconsistent' );
$r->variants['var-00001'] = array( 'id' => 21, 'state' => 'ok', 'context' => 'variation', 'parent' => 10 );
$r->variants['var-00002'] = array( 'id' => 22, 'state' => 'quarantined', 'context' => 'variation', 'parent' => 10 );
$r->variants['var-00009'] = array( 'id' => 29, 'state' => 'ok', 'context' => 'variation', 'parent' => 99 );

$p = $D::precheck( array( 'delta' => true, 'productId' => 'prod-9999', 'revision' => 5, 'price' => 1 ), $r );
check( 'need_full' === $p['status'] && 'product_missing' === $p['reason'], 'unknown product => needFullSnapshot' );
$p = $D::precheck( array( 'delta' => true, 'productId' => 'prod-0002', 'revision' => 5 ), $r );
check( 'need_full' === $p['status'] && 'product_quarantined' === $p['reason'], 'quarantined product => needFullSnapshot' );
$p = $D::precheck( array( 'delta' => true, 'productId' => 'prod-0003', 'revision' => 5 ), $r );
check( 'need_full' === $p['status'], 'inconsistent product => needFullSnapshot' );
$p = $D::precheck( array( 'delta' => true, 'productId' => 'prod-0001', 'price' => 1 ), $r );
check( 'need_full' === $p['status'] && 'revision_missing' === $p['reason'], 'missing revision => needFullSnapshot (ordering cannot be enforced)' );
$p = $D::precheck( array( 'delta' => true, 'productId' => 'prod-0001', 'revision' => 5, 'variants' => array( array( 'variantId' => 'var-00001', 'price' => 1 ), array( 'variantId' => 'var-77777', 'price' => 2 ) ) ), $r );
check( 'need_full' === $p['status'] && 'variant_missing' === $p['reason'] && array() === $p['targets'], 'one unknown variant => needFullSnapshot and nothing targeted (no partial apply)' );
$p = $D::precheck( array( 'delta' => true, 'productId' => 'prod-0001', 'revision' => 5, 'variants' => array( array( 'variantId' => 'var-00002', 'stock' => 1 ) ) ), $r );
check( 'need_full' === $p['status'] && 'variant_quarantined' === $p['reason'], 'quarantined variant => needFullSnapshot' );
$p = $D::precheck( array( 'delta' => true, 'productId' => 'prod-0001', 'revision' => 5, 'variants' => array( array( 'variantId' => 'var-00009', 'stock' => 1 ) ) ), $r );
check( 'need_full' === $p['status'] && 'variant_inconsistent' === $p['reason'], 'variant owned by another parent => needFullSnapshot' );
$p = $D::precheck( array( 'delta' => true, 'data' => array( 'productId' => 'prod-0001', 'revision' => 7, 'price' => 1200, 'variants' => array( array( 'variantId' => 'var-00001', 'price' => 900, 'purchasable' => false, 'title' => 'ignored' ) ) ) ), $r );
check( 'ok' === $p['status'] && 10 === $p['product']['id'] && 7 === $p['revision'], 'known product + variant => ok' );
check( 1 === count( $p['targets'] ) && 21 === $p['targets'][0]['id'] && 'variation' === $p['targets'][0]['context'], 'variant target resolved' );
check( array( 'price' => 900, 'purchasable' => false ) === $p['targets'][0]['fields'], 'variant target carries only present delta fields' );
check( 7 === $p['targets'][0]['revision'], 'variant revision falls back to product revision' );
$p = $D::precheck( array( 'delta' => true, 'productId' => 'prod-0001', 'revision' => 7, 'variants' => array( array( 'variantId' => 'var-00001', 'revision' => 9, 'stock' => 3 ) ) ), $r );
check( 9 === $p['targets'][0]['revision'], 'explicit variant revision wins' );
$p = $D::precheck( array( 'delta' => true, 'productId' => 'prod-0001', 'revision' => 7, 'variants' => 'bad' ), $r );
check( 'invalid' === $p['status'], 'malformed delta => invalid (not needFull)' );

// --- batch parsing and limits.
check( 413 === $B::parse( str_repeat( 'x', $B::MAX_BYTES + 1 ) )['status'], 'body over 1 MB => 413' );
$too_many = array( 'batch' => array_fill( 0, 201, array( 'eventId' => 'e', 'eventType' => 'ProductUpdated' ) ) );
check( 413 === $B::parse( json_encode( $too_many ) )['status'], 'more than 200 events => 413' );
check( 200 === $B::parse( json_encode( array( 'batch' => array_fill( 0, 200, array( 'eventType' => 'UpdateVariant' ) ) ) ) )['status'], 'exactly 200 events accepted' );
check( 400 === $B::parse( '{not json' )['status'], 'invalid JSON => 400' );
check( 400 === $B::parse( '{"events":[]}' )['status'], 'missing batch key => 400' );
check( $B::is_batch_header( '1' ) && ! $B::is_batch_header( '' ) && ! $B::is_batch_header( null ), 'X-Mobo-Batch header detection' );
/* M1: capabilities are advertised only while normal runtime is ready. */
check( array() === $B::capabilities(), 'no capabilities while the migration keeps the runtime unready (no Migration class, unstamped)' );
$GLOBALS['__delta_db_version'] = '10.34.77';
check( array( 'product-delta-v1', 'webhook-batch-v1' ) === $B::capabilities(), 'capabilities list matches the contract once the DB is stamped' );
check( 'ProductUpdated' === $B::event_type( array( 'eventType' => 'ProductUpdated' ) ) && 'UpdateVariant' === $B::event_type( array( 'type' => 1 ) ), 'event type from eventType / numeric type' );
$single = $B::to_single_payload( array( 'eventType' => 'UpdateVariant', 'eventId' => 'x1', 'data' => array() ), 'UpdateVariant' );
check( 'UpdateVariant' === $single['event'] && 'x1' === $single['eventId'], 'batch row normalized to single-event shape without dropping fields' );

// --- batch processing and result mapping.
$enqueued = array();
$events = array(
	array( 'eventId' => 'e1', 'eventType' => 'ProductUpdated', 'delta' => true, 'productId' => 'prod-0001' ),
	array( 'eventId' => 'e2', 'eventType' => 'UpdateVariant', 'productId' => 'prod-0001' ),
	array( 'eventId' => 'e3' ),
	'garbage',
	array( 'eventId' => 'e5', 'eventType' => 'ProductUpdated', 'delta' => true, 'productId' => 'prod-9999' ),
	array( 'eventId' => 'e6', 'eventType' => 'ShippingMethodsChanged' ),
	array( 'eventId' => 'e7', 'eventType' => 'ProductUpdated', 'delta' => true, 'productId' => 'prod-boom' ),
	array( 'eventId' => 'e8', 'eventType' => 'ProductUpdated', 'delta' => true, 'productId' => 'prod-busy' ),
);
$inline = function ( $payload, $type ) {
	if ( 'ProductUpdated' !== $type || empty( $payload['delta'] ) ) { return null; }
	if ( 'prod-9999' === $payload['productId'] ) { return array( 'ok' => true, 'needFullSnapshot' => true ); }
	if ( 'prod-boom' === $payload['productId'] ) { throw new RuntimeException( 'boom' ); }
	if ( 'prod-busy' === $payload['productId'] ) { return null; }
	return array( 'ok' => true, 'needFullSnapshot' => false );
};
$enqueue = function ( $payload ) use ( &$enqueued ) {
	if ( 'ShippingMethodsChanged' === $payload['event'] ) { return new WP_Error( 'mobo_core_webhook_write_failed' ); }
	$enqueued[] = $payload['eventId'];
	return true;
};
$results = $B::process( $events, $inline, $enqueue, microtime( true ) + 60 );
check( count( $events ) === count( $results ), 'exactly one result per received event' );
check( array( 'e1', 'e2', 'e3', '', 'e5', 'e6', 'e7', 'e8' ) === array_column( $results, 'eventId' ), 'results are in request order' );
check( $results[0]['ok'] && ! $results[0]['needFullSnapshot'], 'inline delta applied => ok' );
check( $results[1]['ok'] && in_array( 'e2', $enqueued, true ), 'non-delta event enqueued => ok:true' );
check( ! $results[2]['ok'] && 'event_type_missing' === $results[2]['error'], 'missing eventType => ok:false' );
check( ! $results[3]['ok'] && 'invalid_event' === $results[3]['error'], 'non-object row => ok:false' );
check( $results[4]['ok'] && $results[4]['needFullSnapshot'], 'unknown product delta => needFullSnapshot:true' );
check( ! $results[5]['ok'] && 'mobo_core_webhook_write_failed' === $results[5]['error'], 'enqueue failure => ok:false with error code' );
check( ! $results[6]['ok'] && 0 === strpos( $results[6]['error'], 'processor_exception_' ), 'inline exception => ok:false, batch continues' );
check( $results[7]['ok'] && in_array( 'e8', $enqueued, true ), 'declined inline (busy lock) => enqueued whole => ok:true' );
foreach ( $results as $row ) {
	if ( array_keys( $row ) !== array( 'eventId', 'ok', 'needFullSnapshot', 'error' ) ) { check( false, 'result row shape' ); break; }
}
check( true, 'every result row has exactly eventId/ok/needFullSnapshot/error' );

$enqueued = array();
$results = $B::process( array( $events[0] ), $inline, $enqueue, microtime( true ) - 1 );
check( $results[0]['ok'] && array( 'e1' ) === $enqueued, 'after the inline deadline deltas are enqueued, not applied' );

// --- M6: quarantined objects: accepted + recorded, never a full-snapshot loop.
check( $D::is_quarantine_reason( 'product_quarantined' ) && $D::is_quarantine_reason( 'variant_quarantined' ) && ! $D::is_quarantine_reason( 'product_inconsistent' ), 'M6: quarantine reasons recognized (inconsistent still needs a snapshot)' );
$p = $D::precheck( array( 'delta' => true, 'productId' => 'prod-0002', 'revision' => 5 ), $r );
check( 11 === $p['objectId'], 'M6: precheck reports the quarantined product id' );
$p = $D::precheck( array( 'delta' => true, 'productId' => 'prod-0001', 'revision' => 5, 'variants' => array( array( 'variantId' => 'var-00002', 'stock' => 1 ) ) ), $r );
check( 22 === $p['objectId'], 'M6: precheck reports the quarantined VARIANT id (released per object)' );
$row = $B::result( 'e9', true, false, '', $D::NOTE_QUARANTINED );
check( true === $row['ok'] && false === $row['needFullSnapshot'] && 'quarantined' === $row['note'], 'M6: batch row ok:true, needFullSnapshot:false, note:"quarantined"' );
check( ! array_key_exists( 'note', $B::result( 'e1', true ) ), 'batch rows without a note keep the original shape' );
$results = $B::process(
	array( array( 'eventId' => 'q1', 'event' => 'ProductUpdated', 'delta' => true, 'productId' => 'prod-0002' ) ),
	function () { return array( 'ok' => true, 'needFullSnapshot' => false, 'error' => '', 'note' => 'quarantined' ); },
	function () { return true; },
	microtime( true ) + 30
);
check( 'quarantined' === ( $results[0]['note'] ?? '' ) && false === $results[0]['needFullSnapshot'], 'M6: inline quarantined outcome is passed through the batch' );
$split = $D::split_released_quarantine_skips(
	array( 'prod-0002' => array( 'productId' => 11 ), 'prod-0003' => array( 'productId' => 22 ), 'prod-0004' => array( 'productId' => 0 ) ),
	function ( $id ) { return 22 === $id; }
);
check( array( 'prod-0002' ) === array_keys( $split['released'] ) && array( 'prod-0003', 'prod-0004' ) === array_keys( $split['remaining'] ), 'M6: only released objects get ONE full snapshot request' );

// --- M5: in-flight delta crash marker.
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['mobo_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['mobo_meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['mobo_meta'][ $id ][ $key ] ); return true; }
final class Inflight_WPDB {
	public $postmeta = 'wp_postmeta';
	public $posts = 'wp_posts';
	public $rows = array();
	public $last_sql = '';
	public function prepare( $q, ...$a ) { $i = 0; return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ] ?? ''; return '%d' === $m[0] ? (string) (int) $v : "'" . $v . "'"; }, $q ); }
	public function get_results( $sql, $o = null ) { $this->last_sql = $sql; return $this->rows; }
}
$GLOBALS['wpdb'] = new Inflight_WPDB();
define( 'ARRAY_A', 'ARRAY_A' );
require $base . 'class-mobo-core-delta-inflight.php';
$F = 'Mobo_Core_Delta_Inflight';
check( 0 !== strpos( $F::META_KEY, '_mobo_' ) && 0 !== strpos( $F::META_KEY, 'mobo_' ), 'M5: marker key stays a plain postmeta row (outside managed prefixes)' );
check( true === $F::mark( 10, 'prod-0001', 7 ), 'M5: marker written before phase-2 writes (verified)' );
check( true === $F::is_marked( 10 ), 'M5: a killed delta leaves the marker behind' );
$F::clear( 10 );
check( false === $F::is_marked( 10 ), 'M5: marker removed after the last write' );
$now = 2000000000;
$GLOBALS['wpdb']->rows = array(
	array( 'post_id' => 10, 'meta_value' => $F::value( 'prod-0001', 7, $now - 600 ) ),
	array( 'post_id' => 11, 'meta_value' => $F::value( 'prod-0002', 8, $now - 30 ) ),
	array( 'post_id' => 12, 'meta_value' => 'garbage' ),
);
$stale = $F::stale( $now );
check( 2 === count( $stale ) && 10 === $stale[0]['productId'] && 'prod-0001' === $stale[0]['productGuid'], 'M5: restart sweep picks markers older than any live product lock' );
check( 12 === $stale[1]['productId'] && '' === $stale[1]['productGuid'], 'M5: malformed marker is returned for cleanup only' );
check( false !== strpos( $GLOBALS['wpdb']->last_sql, 'LIMIT 20' ), 'M5: sweep is bounded' );
$sync_src = file_get_contents( $base . 'class-mobo-core-product-sync.php' );
check( false !== strpos( $sync_src, 'Mobo_Core_Delta_Inflight::mark( $product_id, $guid, $revision )' ) && false !== strpos( $sync_src, "'delta_interrupted' )" ), 'M5: phase 2 marks first; sweep re-requests a full snapshot' );
check( false !== strpos( $sync_src, 'Mobo_Core_Delta_Inflight::is_marked( $object_id )' ), 'M5: leftover marker makes the next delta ask for a full snapshot' );

echo PHP_EOL . $checks . ' checks, ' . $failures . ' failure(s) on PHP ' . PHP_VERSION . PHP_EOL;
exit( $failures > 0 ? 1 : 0 );
