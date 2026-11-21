<?php
/**
 * Read-only shadow verifier for direct operational-state reads.
 *
 * Identity parity is observed at Product Map lookups. This observer covers the
 * other side of the migration boundary: direct get_post_meta() reads of Mobo's
 * revision/hash/completion state that can occur in checkout, health, repair and
 * migration code without a preceding GUID lookup. It never filters or replaces
 * the Legacy metadata value. One full Legacy/Custom snapshot is compared per
 * Woo entity per request, with bounded telemetry in mobo_runtime.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Storage_State_Parity {

	const WORKER_NAME = 'storage_state_parity_v3';
	const MAX_COMPARISONS_PER_REQUEST = 32;
	const MAX_MISMATCH_SAMPLES = 5;

	/** @var array<int,array> Request-local parity telemetry keyed by Connection id. */
	private static $buckets = array();
	/** @var bool */
	private static $shutdown_registered = false;
	/** @var bool Recursion guard while canonical Legacy snapshots call get_post_meta(). */
	private static $observing = false;

	/**
	 * Observe one already-requested Legacy metadata read without altering its value.
	 *
	 * @param int    $object_id Woo post/variation ID.
	 * @param string $meta_key Operational Mobo meta key being read.
	 * @return void
	 */
	public static function observe_meta_read( $object_id, $meta_key ) {
		if ( self::$observing ) { return; }
		$object_id = absint( $object_id );
		$meta_key  = (string) $meta_key;
		if ( $object_id <= 0 || ! self::is_interested_key( $meta_key ) || ! self::enabled() ) { return; }
		if ( class_exists( 'Mobo_Core_Storage_Meta_Commit_Bridge' )
			&& ( Mobo_Core_Storage_Meta_Commit_Bridge::is_flushing() || Mobo_Core_Storage_Meta_Commit_Bridge::has_pending_object( $object_id ) ) ) { return; }

		$post_type = get_post_type( $object_id );
		if ( 'product' !== $post_type && 'product_variation' !== $post_type ) { return; }
		if ( ! self::key_applies_to_type( $meta_key, $post_type ) ) { return; }

		$connection_id = self::connection_id();
		if ( $connection_id <= 0 || ! self::object_belongs_to_connection( $object_id, $post_type, $connection_id ) ) { return; }
		$key = ( 'product' === $post_type ? 'product|' : 'variant|' ) . $object_id;
		if ( ! self::reserve( $key, $connection_id ) ) { return; }

		self::$observing = true;
		try {
			if ( 'product' === $post_type ) {
				$product_guid = sanitize_text_field( (string) get_post_meta( $object_id, 'product_guid', true ) );
				if ( ! self::valid_guid( $product_guid ) ) {
					self::ignore_reserved_comparison( $connection_id );
					return;
				}
				$sync_incomplete = '1' === (string) get_post_meta( $object_id, 'mobo_sync_incomplete', true );
				$legacy = Mobo_Core_Storage_Bridge::legacy_product_snapshot( $product_guid, $object_id, $sync_incomplete );
				$custom = ( new Mobo_Core_Product_State_Repository() )->get_by_guid( $product_guid );
				self::compare( $connection_id, 'product', $product_guid, $meta_key, $legacy, $custom );
				return;
			}

			$variant_guid = sanitize_text_field( (string) get_post_meta( $object_id, 'variant_guid', true ) );
			$parent_id    = absint( wp_get_post_parent_id( $object_id ) );
			$product_guid = $parent_id > 0 ? sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) ) : '';
			if ( ! self::valid_guid( $variant_guid ) || ! self::valid_guid( $product_guid ) ) {
				self::ignore_reserved_comparison( $connection_id );
				return;
			}
			$sync_incomplete = '1' === (string) get_post_meta( $object_id, 'mobo_sync_incomplete', true );
			$legacy = Mobo_Core_Storage_Bridge::legacy_variant_snapshot( $variant_guid, $object_id, $product_guid, $sync_incomplete );
			$custom = ( new Mobo_Core_Variant_State_Repository() )->get_by_guid( $variant_guid );
			self::compare( $connection_id, 'variant', $variant_guid, $meta_key, $legacy, $custom );
		} finally {
			self::$observing = false;
		}
	}

	/** Operational read keys that participate in custom-state parity. @return bool */
	public static function is_interested_key( $meta_key ) {
		return in_array(
			(string) $meta_key,
			array(
				'_mobo_product_seen_revision', '_mobo_product_applied_revision',
				'_mobo_variant_seen_revision', '_mobo_variant_applied_revision',
				'_mobo_product_source_hash', '_mobo_variant_source_hash',
				'mobo_sync_incomplete',
			),
			true
		);
	}

	/** Persist this request's aggregate metrics exactly once per Connection. @return bool */
	public static function flush() {
		global $wpdb;
		if ( empty( self::$buckets ) ) { return true; }
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			self::clear_request_buffer();
			return false;
		}

		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$now   = current_time( 'mysql', true );
		$ok    = true;
		foreach ( self::$buckets as $connection_id => $bucket ) {
			$connection_id = absint( $connection_id );
			if ( $connection_id <= 0 || ! is_array( $bucket ) ) { continue; }
			$comparisons = absint( $bucket['comparisons'] ?? 0 );
			$skipped = absint( $bucket['skipped'] ?? 0 );
			if ( $comparisons <= 0 && $skipped <= 0 ) { continue; }
			$reasons = isset( $bucket['reasons'] ) && is_array( $bucket['reasons'] ) ? $bucket['reasons'] : array();
			$last_reason = '';
			if ( ! empty( $reasons ) ) { arsort( $reasons ); $last_reason = sanitize_key( (string) key( $reasons ) ); }
			$state = array(
				'connectionId' => $connection_id,
				'mode'         => Mobo_Core_Storage_Mode::requested(),
				'comparisons'  => $comparisons,
				'matches'      => absint( $bucket['matches'] ?? 0 ),
				'mismatches'   => absint( $bucket['mismatches'] ?? 0 ),
				'skippedByCap' => $skipped,
				'reasons'      => $reasons,
				'samples'      => isset( $bucket['samples'] ) && is_array( $bucket['samples'] ) ? $bucket['samples'] : array(),
				'flushedAt'    => gmdate( 'c' ),
			);
			$encoded = wp_json_encode( $state );
			if ( ! is_string( $encoded ) ) { $encoded = '{}'; }
			$sql = "INSERT INTO {$table}
				(connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,circuit_open_until,last_error_code,state_json,created_at,updated_at)
				VALUES (%d,%s,%s,%s,%s,%d,'closed',NULL,%s,%s,%s,%s)
				ON DUPLICATE KEY UPDATE
					last_started_at=VALUES(last_started_at),last_finished_at=VALUES(last_finished_at),
					last_cursor=CAST(COALESCE(NULLIF(last_cursor,''),'0') AS UNSIGNED)+CAST(VALUES(last_cursor) AS UNSIGNED),
					consecutive_failures=consecutive_failures+VALUES(consecutive_failures),
					circuit_state='closed',circuit_open_until=NULL,last_error_code=VALUES(last_error_code),state_json=VALUES(state_json),updated_at=VALUES(updated_at)";
			$result = $wpdb->query( $wpdb->prepare( $sql, $connection_id, self::WORKER_NAME, $now, $now, (string) $comparisons, absint( $bucket['mismatches'] ?? 0 ), $last_reason, $encoded, $now, $now ) );
			if ( false === $result ) { $ok = false; }
		}
		self::clear_request_buffer();
		return $ok;
	}

	/** Return durable state-read parity telemetry. @return array */
	public static function get_status() {
		global $wpdb;
		$base = array(
			'enabled'          => self::enabled(),
			'mode'             => class_exists( 'Mobo_Core_Storage_Mode' ) ? Mobo_Core_Storage_Mode::requested() : 'legacy',
			'maxPerRequest'    => self::MAX_COMPARISONS_PER_REQUEST,
			'totalComparisons' => 0,
			'totalMatches'     => 0,
			'totalMismatches'  => 0,
			'mismatchRate'     => 0.0,
			'lastReason'       => '',
			'lastBatch'        => array(),
			'updatedAt'        => '',
		);
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return $base; }
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return $base; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT last_cursor,consecutive_failures,last_error_code,state_json,updated_at FROM {$table} WHERE connection_id=%d AND worker_name=%s LIMIT 1", $connection_id, self::WORKER_NAME ), ARRAY_A );
		if ( ! is_array( $row ) ) { return $base; }
		$total = absint( isset( $row['last_cursor'] ) ? $row['last_cursor'] : 0 );
		$mismatches = absint( isset( $row['consecutive_failures'] ) ? $row['consecutive_failures'] : 0 );
		$decoded = json_decode( (string) ( isset( $row['state_json'] ) ? $row['state_json'] : '' ), true );
		$base['totalComparisons'] = $total;
		$base['totalMismatches']  = $mismatches;
		$base['totalMatches']     = max( 0, $total - $mismatches );
		$base['mismatchRate']     = $total > 0 ? round( ( $mismatches * 100 ) / $total, 4 ) : 0.0;
		$base['lastReason']       = sanitize_key( (string) ( isset( $row['last_error_code'] ) ? $row['last_error_code'] : '' ) );
		$base['lastBatch']        = is_array( $decoded ) ? $decoded : array();
		$base['updatedAt']        = sanitize_text_field( (string) ( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ) );
		return $base;
	}

	/** Delete only state-parity telemetry; no Product/Variation state is touched. @return bool */
	public static function reset_metrics() {
		global $wpdb;
		self::clear_request_buffer();
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$result = $wpdb->delete( $table, array( 'connection_id' => $connection_id, 'worker_name' => self::WORKER_NAME ), array( '%d', '%s' ) );
		return false !== $result;
	}

	/** Expose the recursion guard so Custom-Primary state reads can preserve a true Legacy snapshot. @return bool */
	public static function is_observing() { return self::$observing; }

	/** Tests/manual tooling only. @return void */
	public static function reset_request_state() { self::clear_request_buffer(); self::$observing = false; }

	/** @return bool */
	private static function enabled() {
		if ( ! class_exists( 'Mobo_Core_Storage_Mode' ) || ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$requested = Mobo_Core_Storage_Mode::requested();
		return in_array( $requested, array( Mobo_Core_Storage_Mode::SHADOW_READ, Mobo_Core_Storage_Mode::CUSTOM_PRIMARY, Mobo_Core_Storage_Mode::CUSTOM_ONLY ), true );
	}

	/** @return bool */
	private static function key_applies_to_type( $meta_key, $post_type ) {
		$meta_key = (string) $meta_key;
		if ( 'product_variation' === $post_type ) {
			return in_array( $meta_key, array( '_mobo_variant_seen_revision', '_mobo_variant_applied_revision', '_mobo_variant_source_hash', 'mobo_sync_incomplete' ), true );
		}
		return in_array( $meta_key, array( '_mobo_product_seen_revision', '_mobo_product_applied_revision', '_mobo_variant_seen_revision', '_mobo_variant_applied_revision', '_mobo_product_source_hash', 'mobo_sync_incomplete' ), true );
	}

	/** @return int */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** Only the owning Connection may interpret legacy postmeta as its parity source. @return bool */
	private static function object_belongs_to_connection( $object_id, $post_type, $connection_id ) {
		$object_id = absint( $object_id );
		$connection_id = absint( $connection_id );
		if ( $object_id <= 0 || $connection_id <= 0 ) { return false; }
		$owner = null;
		if ( 'product_variation' === $post_type && class_exists( 'Mobo_Core_Variant_State_Repository' ) ) {
			$owner = ( new Mobo_Core_Variant_State_Repository() )->get_owner_by_woo_variation_id( $object_id );
		} elseif ( 'product' === $post_type && class_exists( 'Mobo_Core_Product_State_Repository' ) ) {
			$owner = ( new Mobo_Core_Product_State_Repository() )->get_owner_by_woo_product_id( $object_id );
		}
		if ( is_array( $owner ) ) { return absint( $owner['connection_id'] ?? 0 ) === $connection_id; }
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) ) { return false; }
		$default = Mobo_Core_Connection_Repository::get_default();
		return is_array( $default ) && absint( $default['id'] ?? 0 ) === $connection_id;
	}

	/** @return array */
	private static function empty_bucket() {
		return array( 'seen'=>array(), 'comparisons'=>0, 'matches'=>0, 'mismatches'=>0, 'skipped'=>0, 'reasons'=>array(), 'samples'=>array() );
	}

	/** @return bool */
	private static function reserve( $key, $connection_id ) {
		$key = sanitize_text_field( (string) $key );
		$connection_id = absint( $connection_id );
		if ( '' === $key || $connection_id <= 0 ) { return false; }
		if ( ! isset( self::$buckets[ $connection_id ] ) ) { self::$buckets[ $connection_id ] = self::empty_bucket(); }
		$bucket =& self::$buckets[ $connection_id ];
		if ( isset( $bucket['seen'][ $key ] ) ) { return false; }
		if ( absint( $bucket['comparisons'] ) >= self::MAX_COMPARISONS_PER_REQUEST ) { $bucket['skipped']++; return false; }
		$bucket['seen'][ $key ] = true;
		$bucket['comparisons']++;
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( array( __CLASS__, 'flush' ) );
		}
		return true;
	}

	/** @return void */
	private static function compare( $connection_id, $entity_type, $guid, $meta_key, $legacy, $custom ) {
		$connection_id = absint( $connection_id );
		if ( $connection_id <= 0 || ! isset( self::$buckets[ $connection_id ] ) ) { return; }
		if ( null === $legacy && null === $custom ) { self::$buckets[ $connection_id ]['matches']++; return; }
		if ( null === $legacy ) { self::mismatch( $connection_id, $entity_type, $guid, 'legacy_missing', array( 'metaKey'=>$meta_key, 'customRow'=>true ) ); return; }
		if ( null === $custom ) { self::mismatch( $connection_id, $entity_type, $guid, 'custom_missing', array( 'metaKey'=>$meta_key ) ); return; }
		$fields = 'product' === $entity_type
			? array(
				'product_seen_revision'=>'int','product_seen_revision_present'=>'bool','product_applied_revision'=>'int','product_applied_revision_present'=>'bool',
				'variant_seen_revision'=>'int','variant_seen_revision_present'=>'bool','variant_applied_revision'=>'int','variant_applied_revision_present'=>'bool',
				'product_source_hash'=>'hash','product_source_hash_present'=>'bool','sync_incomplete_meta'=>'bool','sync_incomplete_meta_present'=>'bool','sync_incomplete'=>'bool',
				'desired_revision'=>'int','applied_revision'=>'int','desired_hash'=>'hash','applied_hash'=>'hash','sync_status'=>'key',
			)
			: array(
				'variant_seen_revision'=>'int','variant_seen_revision_present'=>'bool','variant_applied_revision'=>'int','variant_applied_revision_present'=>'bool',
				'variant_source_hash'=>'hash','variant_source_hash_present'=>'bool','sync_incomplete_meta'=>'bool','sync_incomplete_meta_present'=>'bool','sync_incomplete'=>'bool',
				'desired_revision'=>'int','applied_revision'=>'int','desired_hash'=>'hash','applied_hash'=>'hash','sync_status'=>'key',
			);
		$diff = array();
		foreach ( $fields as $field => $type ) {
			$left = self::normalize_value( isset( $legacy[ $field ] ) ? $legacy[ $field ] : null, $type );
			$right = self::normalize_value( isset( $custom[ $field ] ) ? $custom[ $field ] : null, $type );
			if ( $left !== $right ) { $diff[ $field ] = array( 'legacy'=>$left, 'custom'=>$right ); }
		}
		if ( empty( $diff ) ) { self::$buckets[ $connection_id ]['matches']++; return; }
		self::mismatch( $connection_id, $entity_type, $guid, 'field_mismatch', array( 'metaKey'=>$meta_key, 'fields'=>array_keys( $diff ), 'diff'=>$diff ) );
	}

	/** Ignore a reserved comparison for a non-Mobo object without letting ordinary Woo traffic satisfy promotion evidence. @return void */
	private static function ignore_reserved_comparison( $connection_id ) {
		$connection_id = absint( $connection_id );
		if ( $connection_id > 0 && isset( self::$buckets[ $connection_id ] ) && self::$buckets[ $connection_id ]['comparisons'] > 0 ) {
			self::$buckets[ $connection_id ]['comparisons']--;
		}
	}

	/** @return void */
	private static function mismatch( $connection_id, $entity_type, $guid, $reason, $detail ) {
		$connection_id = absint( $connection_id );
		if ( $connection_id <= 0 || ! isset( self::$buckets[ $connection_id ] ) ) { return; }
		$bucket =& self::$buckets[ $connection_id ];
		$bucket['mismatches']++;
		$reason = sanitize_key( (string) $reason );
		$bucket['reasons'][ $reason ] = isset( $bucket['reasons'][ $reason ] ) ? absint( $bucket['reasons'][ $reason ] ) + 1 : 1;
		if ( count( $bucket['samples'] ) < self::MAX_MISMATCH_SAMPLES ) {
			$bucket['samples'][] = array( 'entityType'=>sanitize_key( (string) $entity_type ), 'guid'=>sanitize_text_field( (string) $guid ), 'reason'=>$reason, 'detail'=>is_array( $detail ) ? $detail : array() );
		}
	}

	/** @return mixed */
	private static function normalize_value( $value, $type ) {
		if ( 'int' === $type ) { return absint( $value ); }
		if ( 'bool' === $type ) { return ! empty( $value ) ? 1 : 0; }
		if ( 'hash' === $type ) {
			return class_exists( 'Mobo_Core_Source_Hash_Policy' ) ? Mobo_Core_Source_Hash_Policy::normalize( $value ) : '';
		}
		if ( 'key' === $type ) { return sanitize_key( (string) $value ); }
		return sanitize_text_field( (string) $value );
	}

	/** @return bool */
	private static function valid_guid( $guid ) {
		return class_exists( 'Mobo_Core_Remote_Identity_Policy' ) && Mobo_Core_Remote_Identity_Policy::is_valid( $guid );
	}

	/** @return void */
	private static function clear_request_buffer() {
		self::$buckets = array(); self::$shutdown_registered = false;
	}
}
