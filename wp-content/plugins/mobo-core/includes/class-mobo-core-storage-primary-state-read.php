<?php
/**
 * Guarded Custom-Primary operational-state reader with Legacy fallback.
 *
 * Exact state columns plus metadata-presence flags introduced by schema v3 may satisfy direct Mobo operational
 * metadata reads only while the promotion gate is runtime-eligible. Retained Woo
 * identity metadata remains an independent guard. Any real Custom miss or invalid
 * row falls back to Legacy and shares the existing request-level primary circuit
 * breaker. Reads made while Legacy metadata is being mutated/mirrored bypass the
 * Custom path without counting drift so read-after-write semantics stay intact.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Storage_Primary_State_Read {

	/** @var array<int,int> Explicit bypass depth for canonical Legacy snapshot builders by Connection. */
	private static $legacy_bypass_depth = array();
	/** @var array<string,array> Request-local Custom row cache by Woo object. */
	private static $row_cache = array();
	/** @var array<string,bool> Objects whose Legacy operational meta is being mutated this request, namespaced by Connection. */
	private static $legacy_mutation_objects = array();

	/** Active durable Connection for request-local isolation. @return int */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' )
			? absint( Mobo_Core_Connection_Context::get_effective_connection_id() )
			: 0;
	}

	/** Request-local row-cache key, isolated by durable Connection. @return string */
	private static function row_cache_key( $type, $object_id, $guid ) {
		return self::connection_id() . '|' . sanitize_key( (string) $type ) . '|' . absint( $object_id ) . '|' . sanitize_text_field( (string) $guid );
	}

	/** Custom-Only bootstrap cache key before the remote GUID is known. */
	private static function woo_row_cache_key( $type, $object_id ) {
		return self::connection_id() . '|' . sanitize_key( (string) $type ) . '-woo|' . absint( $object_id );
	}

	/** Request-local Legacy mutation key, isolated by durable Connection. @return string */
	private static function mutation_key( $object_id ) {
		return self::connection_id() . '|' . absint( $object_id );
	}

	/** Current Connection's explicit bypass depth. @return int */
	private static function bypass_depth() {
		$connection_id = self::connection_id();
		return isset( self::$legacy_bypass_depth[ $connection_id ] ) ? absint( self::$legacy_bypass_depth[ $connection_id ] ) : 0;
	}

	/** Begin one explicit canonical-Legacy read scope. @return void */
	public static function begin_legacy_bypass() {
		$connection_id = self::connection_id();
		self::$legacy_bypass_depth[ $connection_id ] = self::bypass_depth() + 1;
	}

	/** End one explicit canonical-Legacy read scope. @return void */
	public static function end_legacy_bypass() {
		$connection_id = self::connection_id();
		$depth = max( 0, self::bypass_depth() - 1 );
		if ( $depth > 0 ) { self::$legacy_bypass_depth[ $connection_id ] = $depth; }
		else { unset( self::$legacy_bypass_depth[ $connection_id ] ); }
	}

	/** Whether Custom operational state must not short-circuit this read. @return bool */
	public static function is_bypassed() { return self::bypass_depth() > 0; }

	/**
	 * Resolve one direct operational-state metadata read from Custom Storage.
	 *
	 * Returned status values:
	 * - hit: Custom value is safe and may short-circuit get_post_meta().
	 * - fallback: real Custom drift; caller must use Legacy and record fallback.
	 * - bypass/disabled/not-applicable: caller must use Legacy without drift telemetry.
	 *
	 * @param int    $object_id Woo Product/Variation ID.
	 * @param string $meta_key Operational metadata key.
	 * @return array{status:string,value:mixed,present:bool,reason:string,guid:string,customId:int}
	 */
	public static function read_meta( $object_id, $meta_key ) {
		$object_id = absint( $object_id );
		$meta_key  = (string) $meta_key;
		if ( $object_id <= 0 || ! Mobo_Core_Storage_State_Parity::is_interested_key( $meta_key ) ) {
			return self::result( 'not-applicable', null, 'not_applicable', '', 0 );
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::result( 'bypass', null, 'rest_legacy_write_safety', '', 0 );
		}
		if ( ! self::enabled() ) {
			return self::result( 'disabled', null, 'primary_state_disabled', '', 0 );
		}
		if ( self::must_bypass_object( $object_id ) ) {
			return self::result( 'bypass', null, 'legacy_write_pending', '', 0 );
		}

		$post_type = get_post_type( $object_id );
		if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
			return self::result( 'not-applicable', null, 'unsupported_post_type', '', 0 );
		}
		if ( ! self::key_applies_to_type( $meta_key, $post_type ) ) {
			return self::result( 'not-applicable', null, 'key_not_applicable', '', 0 );
		}
		if ( 'product' === $post_type ) {
			return self::read_product_meta( $object_id, $meta_key );
		}
		return self::read_variant_meta( $object_id, $meta_key );
	}

	/**
	 * Pin one object to authoritative Legacy reads for the remainder of this request.
	 *
	 * WordPress update_metadata() consults get_metadata_raw() before deciding whether
	 * a write is a no-op. Custom state must never participate in that write decision,
	 * so pre-mutation hooks call this before WordPress performs its old-value read.
	 * Leaving the pin in place after the mutation is intentional and also preserves
	 * read-after-write behavior until the next request.
	 *
	 * @return void
	 */
	public static function mark_legacy_mutation( $object_id ) {
		$object_id = absint( $object_id );
		if ( $object_id > 0 ) { self::$legacy_mutation_objects[ self::mutation_key( $object_id ) ] = true; }
	}

	/** Whether this object has already entered an authoritative Legacy mutation path in this request. @return bool */
	public static function has_legacy_mutation( $object_id ) {
		$object_id = absint( $object_id );
		return $object_id > 0 && ! empty( self::$legacy_mutation_objects[ self::mutation_key( $object_id ) ] );
	}

	/** Invalidate cached Custom state after an in-request mirror of one Woo object. @return void */
	public static function invalidate_object( $object_id ) {
		$object_id = absint( $object_id );
		if ( $object_id <= 0 || empty( self::$row_cache ) ) { return; }
		$connection_prefix = self::connection_id() . '|';
		foreach ( array_keys( self::$row_cache ) as $key ) {
			if ( 0 === strpos( $key, $connection_prefix . 'product|' . $object_id . '|' )
				|| 0 === strpos( $key, $connection_prefix . 'variant|' . $object_id . '|' )
				|| $key === $connection_prefix . 'product-woo|' . $object_id
				|| $key === $connection_prefix . 'variant-woo|' . $object_id ) {
				unset( self::$row_cache[ $key ] );
			}
		}
	}

	/** Tests/manual tooling only. @return void */
	public static function reset_request_cache() {
		self::$row_cache = array();
		self::$legacy_mutation_objects = array();
		self::$legacy_bypass_depth = array();
	}

	/** @return bool */
	private static function enabled() {
		if ( self::bypass_depth() > 0 ) { return false; }
		/*
		 * REST meta controllers perform their own get_metadata_raw() duplicate/delete
		 * preflight before update_metadata()/delete_metadata() reaches our mutation
		 * guard. Keep REST operational-meta reads Legacy-primary in this rollout so a
		 * Custom mirror can never suppress or alter an authoritative REST write.
		 */
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return false; }
		if ( ! class_exists( 'Mobo_Core_Storage_Mode' ) || ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$requested = Mobo_Core_Storage_Mode::requested();
		if ( ! in_array( $requested, array( Mobo_Core_Storage_Mode::CUSTOM_PRIMARY, Mobo_Core_Storage_Mode::CUSTOM_ONLY ), true ) ) { return false; }
		return Mobo_Core_Storage_Mode::reads_custom_primary()
			&& class_exists( 'Mobo_Core_Storage_Primary_Read' )
			&& Mobo_Core_Storage_Primary_Read::request_allowed();
	}

	/** Expected transient Legacy mutation/mirror scopes must never count as drift. @return bool */
	private static function must_bypass_object( $object_id ) {
		$object_id = absint( $object_id );
		if ( self::bypass_depth() > 0 ) { return true; }
		if ( $object_id > 0 && ! empty( self::$legacy_mutation_objects[ self::mutation_key( $object_id ) ] ) ) { return true; }
		if ( class_exists( 'Mobo_Core_Storage_State_Parity' ) && Mobo_Core_Storage_State_Parity::is_observing() ) { return true; }
		if ( class_exists( 'Mobo_Core_Storage_Meta_Commit_Bridge' ) ) {
			if ( Mobo_Core_Storage_Meta_Commit_Bridge::is_flushing() ) { return true; }
			if ( Mobo_Core_Storage_Meta_Commit_Bridge::has_pending_object( $object_id ) ) { return true; }
		}
		return false;
	}

	/** @return array */
	private static function read_product_meta( $product_id, $meta_key ) {
		$custom_only = self::custom_only_active();
		$row = null;
		$product_guid = '';
		if ( $custom_only ) {
			$woo_cache_key = self::woo_row_cache_key( 'product', $product_id );
			$row = self::cached_row( $woo_cache_key, function() use ( $product_id ) {
				return ( new Mobo_Core_Product_State_Repository() )->get_by_woo_product_id( $product_id );
			} );
			$product_guid = is_array( $row ) ? sanitize_text_field( (string) ( isset( $row['product_guid'] ) ? $row['product_guid'] : '' ) ) : '';
			if ( ! self::valid_guid( $product_guid ) ) {
				$legacy_guid = sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) );
				return self::valid_guid( $legacy_guid )
					? self::result( 'fallback', null, 'state_custom_missing', $legacy_guid, 0 )
					: self::result( 'not-applicable', null, 'unmanaged_object', '', 0 );
			}
		} else {
			$product_guid = sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) );
			if ( ! self::valid_guid( $product_guid ) ) { return self::result( 'not-applicable', null, 'unmanaged_object', '', 0 ); }
		}
		$status = sanitize_key( (string) get_post_status( $product_id ) );
		if ( in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
			return self::result( 'fallback', null, 'state_custom_retired_object', $product_guid, $product_id );
		}
		$cache_key = self::row_cache_key( 'product', $product_id, $product_guid );
		if ( ! is_array( $row ) ) {
			$row = self::cached_row( $cache_key, function() use ( $product_guid ) {
				return ( new Mobo_Core_Product_State_Repository() )->get_by_guid( $product_guid );
			} );
		} else {
			self::$row_cache[ $cache_key ] = $row;
		}
		if ( ! is_array( $row ) ) {
			return self::result( 'fallback', null, 'state_custom_missing', $product_guid, 0 );
		}
		$custom_id = absint( isset( $row['woo_product_id'] ) ? $row['woo_product_id'] : 0 );
		if ( $custom_id !== $product_id || sanitize_text_field( (string) ( isset( $row['product_guid'] ) ? $row['product_guid'] : '' ) ) !== $product_guid ) {
			return self::result( 'fallback', null, 'state_custom_identity_mismatch', $product_guid, $custom_id );
		}
		$field = self::product_field_for_key( $meta_key );
		$presence_field = self::presence_field_for_field( $field );
		if ( '' === $field || '' === $presence_field || ! array_key_exists( $field, $row ) || ! array_key_exists( $presence_field, $row ) ) {
			return self::result( 'fallback', null, 'state_custom_field_missing', $product_guid, $custom_id );
		}
		$present = 1 === absint( $row[ $presence_field ] );
		if ( ! $present ) { return self::result( 'hit', '', '', $product_guid, $custom_id, false ); }
		$value = self::meta_value( $field, $row[ $field ] );
		if ( null === $value ) {
			return self::result( 'fallback', null, 'state_custom_field_invalid', $product_guid, $custom_id );
		}
		return self::result( 'hit', $value, '', $product_guid, $custom_id, true );
	}

	/** @return array */
	private static function read_variant_meta( $variation_id, $meta_key ) {
		$custom_only = self::custom_only_active();
		$row = null;
		$variant_guid = '';
		if ( $custom_only ) {
			$woo_cache_key = self::woo_row_cache_key( 'variant', $variation_id );
			$row = self::cached_row( $woo_cache_key, function() use ( $variation_id ) {
				return ( new Mobo_Core_Variant_State_Repository() )->get_by_woo_variation_id( $variation_id );
			} );
			$variant_guid = is_array( $row ) ? sanitize_text_field( (string) ( isset( $row['variant_guid'] ) ? $row['variant_guid'] : '' ) ) : '';
			if ( ! self::valid_guid( $variant_guid ) ) {
				$legacy_guid = sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) );
				return self::valid_guid( $legacy_guid )
					? self::result( 'fallback', null, 'state_custom_missing', $legacy_guid, 0 )
					: self::result( 'not-applicable', null, 'unmanaged_object', '', 0 );
			}
		} else {
			$variant_guid = sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) );
			if ( ! self::valid_guid( $variant_guid ) ) { return self::result( 'not-applicable', null, 'unmanaged_object', '', 0 ); }
		}
		$status = sanitize_key( (string) get_post_status( $variation_id ) );
		if ( in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
			return self::result( 'fallback', null, 'state_custom_retired_object', $variant_guid, $variation_id );
		}
		$parent_id    = absint( wp_get_post_parent_id( $variation_id ) );
		$product_guid = $custom_only && is_array( $row )
			? sanitize_text_field( (string) ( isset( $row['product_guid'] ) ? $row['product_guid'] : '' ) )
			: ( $parent_id > 0 ? sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) ) : '' );
		if ( ! self::valid_guid( $product_guid ) || $parent_id <= 0 ) {
			return self::result( 'fallback', null, 'state_parent_identity_missing', $variant_guid, $variation_id );
		}
		$cache_key = self::row_cache_key( 'variant', $variation_id, $variant_guid );
		if ( ! is_array( $row ) ) {
			$row = self::cached_row( $cache_key, function() use ( $variant_guid ) {
				return ( new Mobo_Core_Variant_State_Repository() )->get_by_guid( $variant_guid );
			} );
		} else {
			self::$row_cache[ $cache_key ] = $row;
		}
		if ( ! is_array( $row ) ) {
			return self::result( 'fallback', null, 'state_custom_missing', $variant_guid, 0 );
		}
		$custom_id = absint( isset( $row['woo_variation_id'] ) ? $row['woo_variation_id'] : 0 );
		$row_parent_id = absint( isset( $row['woo_product_id'] ) ? $row['woo_product_id'] : 0 );
		$row_parent_guid = sanitize_text_field( (string) ( isset( $row['product_guid'] ) ? $row['product_guid'] : '' ) );
		if ( $custom_id !== $variation_id
			|| $row_parent_id !== $parent_id
			|| $row_parent_guid !== $product_guid
			|| sanitize_text_field( (string) ( isset( $row['variant_guid'] ) ? $row['variant_guid'] : '' ) ) !== $variant_guid ) {
			return self::result( 'fallback', null, 'state_custom_identity_mismatch', $variant_guid, $custom_id );
		}
		$field = self::variant_field_for_key( $meta_key );
		$presence_field = self::presence_field_for_field( $field );
		if ( '' === $field || '' === $presence_field || ! array_key_exists( $field, $row ) || ! array_key_exists( $presence_field, $row ) ) {
			return self::result( 'fallback', null, 'state_custom_field_missing', $variant_guid, $custom_id );
		}
		$present = 1 === absint( $row[ $presence_field ] );
		if ( ! $present ) { return self::result( 'hit', '', '', $variant_guid, $custom_id, false ); }
		$value = self::meta_value( $field, $row[ $field ] );
		if ( null === $value ) {
			return self::result( 'fallback', null, 'state_custom_field_invalid', $variant_guid, $custom_id );
		}
		return self::result( 'hit', $value, '', $variant_guid, $custom_id, true );
	}

	/** @return mixed */
	private static function cached_row( $key, $loader ) {
		if ( array_key_exists( $key, self::$row_cache ) ) { return self::$row_cache[ $key ]; }
		$row = is_callable( $loader ) ? call_user_func( $loader ) : null;
		self::$row_cache[ $key ] = is_array( $row ) ? $row : null;
		return self::$row_cache[ $key ];
	}

	/** @return string */
	private static function product_field_for_key( $meta_key ) {
		$map = array(
			'_mobo_product_seen_revision'    => 'product_seen_revision',
			'_mobo_product_applied_revision' => 'product_applied_revision',
			'_mobo_variant_seen_revision'    => 'variant_seen_revision',
			'_mobo_variant_applied_revision' => 'variant_applied_revision',
			'_mobo_product_source_hash'      => 'product_source_hash',
			'mobo_sync_incomplete'           => 'sync_incomplete_meta',
		);
		return isset( $map[ $meta_key ] ) ? $map[ $meta_key ] : '';
	}

	/** @return string */
	private static function variant_field_for_key( $meta_key ) {
		$map = array(
			'_mobo_variant_seen_revision'    => 'variant_seen_revision',
			'_mobo_variant_applied_revision' => 'variant_applied_revision',
			'_mobo_variant_source_hash'      => 'variant_source_hash',
			'mobo_sync_incomplete'           => 'sync_incomplete_meta',
		);
		return isset( $map[ $meta_key ] ) ? $map[ $meta_key ] : '';
	}

	/** Map one exact-value column to its metadata-presence flag. @return string */
	private static function presence_field_for_field( $field ) {
		$allowed = array(
			'product_seen_revision', 'product_applied_revision', 'variant_seen_revision', 'variant_applied_revision',
			'product_source_hash', 'variant_source_hash', 'sync_incomplete_meta',
		);
		return in_array( (string) $field, $allowed, true ) ? (string) $field . '_present' : '';
	}

	/** Convert typed Custom columns back to the scalar contract get_post_meta(..., true) exposes. @return string|null */
	private static function meta_value( $field, $value ) {
		if ( false !== strpos( $field, '_revision' ) ) {
			if ( ! is_numeric( $value ) || (int) $value < 0 ) { return null; }
			return (string) absint( $value );
		}
		if ( 'sync_incomplete_meta' === $field ) {
			if ( ! in_array( (string) $value, array( '0', '1' ), true ) && ! in_array( $value, array( 0, 1 ), true ) ) { return null; }
			return ! empty( $value ) ? '1' : '0';
		}
		if ( false !== strpos( $field, '_hash' ) ) {
			$value = trim( (string) $value );
			if ( '' === $value ) { return ''; }
			$normalized = class_exists( 'Mobo_Core_Source_Hash_Policy' ) ? Mobo_Core_Source_Hash_Policy::normalize( $value ) : '';
			return '' !== $normalized ? $normalized : null;
		}
		return sanitize_text_field( (string) $value );
	}

	/** @return bool */
	private static function key_applies_to_type( $meta_key, $post_type ) {
		if ( 'product_variation' === $post_type ) {
			return in_array( $meta_key, array( '_mobo_variant_seen_revision', '_mobo_variant_applied_revision', '_mobo_variant_source_hash', 'mobo_sync_incomplete' ), true );
		}
		return in_array( $meta_key, array( '_mobo_product_seen_revision', '_mobo_product_applied_revision', '_mobo_variant_seen_revision', '_mobo_variant_applied_revision', '_mobo_product_source_hash', 'mobo_sync_incomplete' ), true );
	}

	/** Whether the stronger Custom-only lease is active for this request. @return bool */
	private static function custom_only_active() {
		return class_exists( 'Mobo_Core_Storage_Mode' ) && Mobo_Core_Storage_Mode::reads_custom_only();
	}

	/** @return bool */
	private static function valid_guid( $guid ) {
		return class_exists( 'Mobo_Core_Remote_Identity_Policy' ) && Mobo_Core_Remote_Identity_Policy::is_valid( $guid );
	}

	/** @return array{status:string,value:mixed,present:bool,reason:string,guid:string,customId:int} */
	private static function result( $status, $value, $reason, $guid, $custom_id, $present = true ) {
		return array(
			'status'   => sanitize_key( (string) $status ),
			'value'    => $value,
			'present'  => (bool) $present,
			'reason'   => sanitize_key( (string) $reason ),
			'guid'     => sanitize_text_field( (string) $guid ),
			'customId' => absint( $custom_id ),
		);
	}
}
