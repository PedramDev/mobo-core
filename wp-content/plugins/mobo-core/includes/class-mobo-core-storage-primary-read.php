<?php
/**
 * Guarded Custom-Primary identity reader with fail-safe Legacy fallback telemetry.
 *
 * 10.33.75 permits Product/Variation identity reads from Custom Storage only after
 * the independent promotion gate is eligible. A custom row is accepted only when
 * its Woo object still exists, has the expected post type/status, and the retained
 * Woo identity meta agrees with the requested GUID. This class never mutates Woo
 * or Product Map state. Exceptional fallback telemetry is aggregated once per
 * request; successful primary reads perform no telemetry write.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Storage_Primary_Read {

	const WORKER_NAME = 'storage_primary_read_v1';
	const MAX_FALLBACK_SAMPLES = 5;

	/** @var array<int,array> Exceptional fallback telemetry buckets by durable Connection. */
	private static $fallback_buckets = array();
	/** @var bool */
	private static $shutdown_registered = false;
	/** @var array<int,bool> Stop Custom-Primary per Connection after the first real fallback in this request. */
	private static $request_blocked = array();
	/** @var array<string,array> */
	private static $product_cache = array();
	/** @var array<string,array> */
	private static $variant_cache = array();

	/** Active durable Connection id for request-local identity/cache isolation. @return int */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** Namespace one identity cache entry by durable Connection. @return string */
	private static function identity_cache_key( $guid ) {
		return self::connection_id() . '|' . sanitize_text_field( (string) $guid );
	}


	/**
	 * Resolve one Product identity from Custom Storage without consulting Product Map.
	 *
	 * @return array{status:string,postId:int,reason:string}
	 */
	public static function lookup_product( $product_guid ) {
		$product_guid = sanitize_text_field( (string) $product_guid );
		$cache_key = self::identity_cache_key( $product_guid );
		if ( isset( self::$product_cache[ $cache_key ] ) ) { return self::$product_cache[ $cache_key ]; }
		if ( ! self::valid_guid( $product_guid ) || ! self::enabled() ) {
			$attempt = self::attempt( 'disabled', 0, 'primary_disabled' ); return self::$product_cache[ $cache_key ] = $attempt;
		}

		$row = ( new Mobo_Core_Product_State_Repository() )->get_by_guid( $product_guid );
		if ( ! is_array( $row ) ) {
			$attempt = self::attempt( 'missing', 0, 'custom_missing' ); return self::$product_cache[ $cache_key ] = $attempt;
		}

		$post_id = absint( isset( $row['woo_product_id'] ) ? $row['woo_product_id'] : 0 );
		if ( $post_id <= 0 || 'product' !== get_post_type( $post_id ) ) {
			$attempt = self::attempt( 'invalid', $post_id, 'custom_invalid_object' ); return self::$product_cache[ $cache_key ] = $attempt;
		}
		$status = sanitize_key( (string) get_post_status( $post_id ) );
		if ( in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
			$attempt = self::attempt( 'invalid', $post_id, 'custom_retired_object' ); return self::$product_cache[ $cache_key ] = $attempt;
		}

		$row_guid = sanitize_text_field( (string) ( isset( $row['product_guid'] ) ? $row['product_guid'] : '' ) );
		if ( $row_guid !== $product_guid ) {
			$attempt = self::attempt( 'invalid', $post_id, 'custom_identity_mismatch' ); return self::$product_cache[ $cache_key ] = $attempt;
		}
		if ( ! self::custom_only_active() ) {
			$woo_guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );
			if ( $woo_guid !== $product_guid ) {
				$attempt = self::attempt( 'invalid', $post_id, 'custom_identity_mismatch' ); return self::$product_cache[ $cache_key ] = $attempt;
			}
		}

		if ( class_exists( 'Mobo_Core_Storage_Primary_Usage' ) ) { Mobo_Core_Storage_Primary_Usage::record_identity_hit( 'product', $product_guid ); }
		$attempt = self::attempt( 'hit', $post_id, '' ); return self::$product_cache[ $cache_key ] = $attempt;
	}

	/**
	 * Resolve one Variation identity from Custom Storage without consulting Product Map.
	 *
	 * @return array{status:string,postId:int,reason:string}
	 */
	public static function lookup_variant( $variant_guid ) {
		$variant_guid = sanitize_text_field( (string) $variant_guid );
		$cache_key = self::identity_cache_key( $variant_guid );
		if ( isset( self::$variant_cache[ $cache_key ] ) ) { return self::$variant_cache[ $cache_key ]; }
		if ( ! self::valid_guid( $variant_guid ) || ! self::enabled() ) {
			return self::attempt( 'disabled', 0, 'primary_disabled' );
		}

		$row = ( new Mobo_Core_Variant_State_Repository() )->get_by_guid( $variant_guid );
		self::$variant_cache[ $cache_key ] = self::validate_variant_row( $variant_guid, $row );
		return self::$variant_cache[ $cache_key ];
	}

	/**
	 * Set-based Variation lookup for Product sync pages.
	 *
	 * @param array $variant_guids Remote GUIDs.
	 * @return array<string,array{status:string,postId:int,reason:string}>
	 */
	public static function lookup_variants( $variant_guids ) {
		$result = array();
		if ( ! is_array( $variant_guids ) || empty( $variant_guids ) || ! self::enabled() ) {
			return $result;
		}
		$clean = array();
		foreach ( $variant_guids as $guid ) {
			$guid = sanitize_text_field( (string) $guid );
			if ( self::valid_guid( $guid ) ) { $clean[ $guid ] = true; }
		}
		$guids = array_keys( $clean );
		if ( empty( $guids ) ) { return $result; }

		$rows = ( new Mobo_Core_Variant_State_Repository() )->get_by_guids( $guids );
		$post_ids = array();
		foreach ( $rows as $row ) {
			$id = absint( isset( $row['woo_variation_id'] ) ? $row['woo_variation_id'] : 0 );
			if ( $id > 0 ) { $post_ids[] = $id; }
		}
		$post_ids = array_values( array_unique( $post_ids ) );
		if ( ! empty( $post_ids ) ) {
			if ( function_exists( '_prime_post_caches' ) ) { _prime_post_caches( $post_ids, false, false ); }
			if ( ! self::custom_only_active() ) { update_meta_cache( 'post', $post_ids ); }
		}

		foreach ( $guids as $guid ) {
			$cache_key = self::identity_cache_key( $guid );
			if ( isset( self::$variant_cache[ $cache_key ] ) ) { $result[ $guid ] = self::$variant_cache[ $cache_key ]; continue; }
			$row = isset( $rows[ $guid ] ) && is_array( $rows[ $guid ] ) ? $rows[ $guid ] : null;
			self::$variant_cache[ $cache_key ] = self::validate_variant_row( $guid, $row );
			$result[ $guid ] = self::$variant_cache[ $cache_key ];
		}
		return $result;
	}

	/** Record an exceptional fallback after Legacy has confirmed it is real drift. */
	public static function record_fallback( $entity_type, $guid, $reason, $custom_id = 0, $legacy_id = 0 ) {
		$entity_type = sanitize_key( (string) $entity_type );
		$guid = sanitize_text_field( (string) $guid );
		$reason = sanitize_key( (string) $reason );
		if ( '' === $reason ) { $reason = 'custom_primary_fallback'; }

		$connection_id = self::connection_id();
		self::$request_blocked[ $connection_id ] = true;
		if ( class_exists( 'Mobo_Core_Storage_Mode' ) ) { Mobo_Core_Storage_Mode::reset_request_cache(); }
		if ( ! isset( self::$fallback_buckets[ $connection_id ] ) ) { self::$fallback_buckets[ $connection_id ] = array( 'fallbacks'=>0, 'reasons'=>array(), 'samples'=>array() ); }
		self::$fallback_buckets[ $connection_id ]['fallbacks']++;
		self::$fallback_buckets[ $connection_id ]['reasons'][ $reason ] = isset( self::$fallback_buckets[ $connection_id ]['reasons'][ $reason ] ) ? absint( self::$fallback_buckets[ $connection_id ]['reasons'][ $reason ] ) + 1 : 1;
		if ( count( self::$fallback_buckets[ $connection_id ]['samples'] ) < self::MAX_FALLBACK_SAMPLES ) {
			self::$fallback_buckets[ $connection_id ]['samples'][] = array(
				'entityType' => $entity_type,
				'guid'       => $guid,
				'reason'     => $reason,
				'customId'   => absint( $custom_id ),
				'legacyId'   => absint( $legacy_id ),
				'connectionId'=> $connection_id,
			);
		}
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			Mobo_Core_Environment::on_shutdown( array( __CLASS__, 'flush' ) );
		}
	}

	/** Persist exceptional fallback telemetry once per request, isolated by durable Connection. @return bool */
	public static function flush() {
		global $wpdb;
		if ( empty( self::$fallback_buckets ) ) { return true; }
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { self::clear_request_buffer(); return false; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$now = current_time( 'mysql', true );
		$ok = true;
		foreach ( self::$fallback_buckets as $connection_id => $bucket ) {
			$connection_id = absint( $connection_id );
			$fallbacks = absint( isset( $bucket['fallbacks'] ) ? $bucket['fallbacks'] : 0 );
			if ( $connection_id <= 0 || $fallbacks <= 0 ) { continue; }
			$reasons = isset( $bucket['reasons'] ) && is_array( $bucket['reasons'] ) ? $bucket['reasons'] : array();
			$reason = ''; if ( ! empty( $reasons ) ) { arsort( $reasons ); $reason = sanitize_key( (string) key( $reasons ) ); }
			$state = array( 'connectionId'=>$connection_id, 'fallbacks'=>$fallbacks, 'reasons'=>$reasons, 'samples'=>isset($bucket['samples'])&&is_array($bucket['samples'])?$bucket['samples']:array(), 'flushedAt'=>gmdate('c') );
			$json = wp_json_encode( $state ); if ( ! is_string( $json ) ) { $json = '{}'; }
			$sql = "INSERT INTO {$table}
				(connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,last_error_code,state_json,created_at,updated_at)
				VALUES (%d,%s,%s,%s,'',%d,'closed',%s,%s,%s,%s)
				ON DUPLICATE KEY UPDATE last_started_at=VALUES(last_started_at),last_finished_at=VALUES(last_finished_at),consecutive_failures=consecutive_failures+VALUES(consecutive_failures),circuit_state='closed',last_error_code=VALUES(last_error_code),state_json=VALUES(state_json),updated_at=VALUES(updated_at)";
			$ok = ( false !== $wpdb->query( $wpdb->prepare( $sql, $connection_id, self::WORKER_NAME, $now, $now, $fallbacks, $reason, $json, $now, $now ) ) ) && $ok;
		}
		self::clear_request_buffer();
		return $ok;
	}

	/** @return int */
	public static function fallback_count() {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return 0; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return 0; }
		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT consecutive_failures FROM {$table} WHERE connection_id=%d AND worker_name=%s LIMIT 1", $connection_id, self::WORKER_NAME ) ) );
	}

	/** @return array */
	public static function get_status() {
		global $wpdb;
		$base = array( 'enabled'=>self::enabled(), 'totalFallbacks'=>0, 'lastReason'=>'', 'lastBatch'=>array(), 'updatedAt'=>'' );
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return $base; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return $base; }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT consecutive_failures,last_error_code,state_json,updated_at FROM {$table} WHERE connection_id=%d AND worker_name=%s LIMIT 1", $connection_id, self::WORKER_NAME ), ARRAY_A );
		if ( ! is_array( $row ) ) { return $base; }
		$decoded = json_decode( (string) ( isset( $row['state_json'] ) ? $row['state_json'] : '' ), true );
		$base['totalFallbacks'] = absint( isset( $row['consecutive_failures'] ) ? $row['consecutive_failures'] : 0 );
		$base['lastReason'] = sanitize_key( (string) ( isset( $row['last_error_code'] ) ? $row['last_error_code'] : '' ) );
		$base['lastBatch'] = is_array( $decoded ) ? $decoded : array();
		$base['updatedAt'] = sanitize_text_field( (string) ( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ) );
		return $base;
	}

	/** Clear request-local identity caches after tests/manual mode changes. @return void */
	public static function reset_request_cache() { self::$product_cache = array(); self::$variant_cache = array(); self::$request_blocked = array(); }

	/** @return bool */
	public static function request_allowed() { return empty( self::$request_blocked[ self::connection_id() ] ); }

	/** @return bool */
	private static function enabled() {
		return self::request_allowed()
			&& class_exists( 'Mobo_Core_Storage_Mode' )
			&& Mobo_Core_Storage_Mode::reads_custom_primary()
			&& class_exists( 'Mobo_Core_Storage_Schema' )
			&& Mobo_Core_Storage_Schema::is_ready();
	}

	/** @return array{status:string,postId:int,reason:string} */
	private static function validate_variant_row( $variant_guid, $row ) {
		if ( ! is_array( $row ) ) { return self::attempt( 'missing', 0, 'custom_missing' ); }
		$post_id = absint( isset( $row['woo_variation_id'] ) ? $row['woo_variation_id'] : 0 );
		if ( $post_id <= 0 || 'product_variation' !== get_post_type( $post_id ) ) {
			return self::attempt( 'invalid', $post_id, 'custom_invalid_object' );
		}
		$status = sanitize_key( (string) get_post_status( $post_id ) );
		if ( in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
			return self::attempt( 'invalid', $post_id, 'custom_retired_object' );
		}
		$row_guid = sanitize_text_field( (string) ( isset( $row['variant_guid'] ) ? $row['variant_guid'] : '' ) );
		if ( $row_guid !== $variant_guid ) {
			return self::attempt( 'invalid', $post_id, 'custom_identity_mismatch' );
		}
		if ( ! self::custom_only_active() ) {
			$woo_guid = sanitize_text_field( (string) get_post_meta( $post_id, 'variant_guid', true ) );
			if ( $woo_guid !== $variant_guid ) {
				return self::attempt( 'invalid', $post_id, 'custom_identity_mismatch' );
			}
		}
		$parent_id = absint( wp_get_post_parent_id( $post_id ) );
		$row_parent_id = absint( isset( $row['woo_product_id'] ) ? $row['woo_product_id'] : 0 );
		$product_guid = sanitize_text_field( (string) ( isset( $row['product_guid'] ) ? $row['product_guid'] : '' ) );
		if ( $parent_id <= 0 || $row_parent_id !== $parent_id || ! self::valid_guid( $product_guid ) ) {
			return self::attempt( 'invalid', $post_id, 'custom_parent_mismatch' );
		}
		if ( ! self::custom_only_active() ) {
			$woo_parent_guid = sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) );
			if ( $woo_parent_guid !== $product_guid ) {
				return self::attempt( 'invalid', $post_id, 'custom_parent_identity_mismatch' );
			}
		}
		if ( class_exists( 'Mobo_Core_Storage_Primary_Usage' ) ) { Mobo_Core_Storage_Primary_Usage::record_identity_hit( 'variant', $variant_guid ); }
		return self::attempt( 'hit', $post_id, '' );
	}

	/** Whether this request is currently operating under the stronger Custom-only lease. @return bool */
	private static function custom_only_active() {
		return class_exists( 'Mobo_Core_Storage_Mode' ) && Mobo_Core_Storage_Mode::reads_custom_only();
	}

	/** @return bool */
	private static function valid_guid( $guid ) {
		return class_exists( 'Mobo_Core_Remote_Identity_Policy' ) && Mobo_Core_Remote_Identity_Policy::is_valid( $guid );
	}

	/** @return array{status:string,postId:int,reason:string} */
	private static function attempt( $status, $post_id, $reason ) {
		return array( 'status'=>sanitize_key( (string) $status ), 'postId'=>absint( $post_id ), 'reason'=>sanitize_key( (string) $reason ) );
	}

	/** @return void */
	private static function clear_request_buffer() {
		self::$fallback_buckets = array();
		self::$shutdown_registered = false;
	}
}
