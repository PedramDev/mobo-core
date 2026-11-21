<?php
/**
 * Terminal metadata compatibility facade.
 *
 * Physical Product/Variation Mobo metadata is split across three Custom stores:
 * - operational typed columns (10.33.94),
 * - identity typed columns (10.33.95),
 * - generic entity metadata rows (10.33.96).
 *
 * WordPress specific-key metadata reads are already virtualized by those owners.
 * This facade preserves the two surfaces that otherwise bypass that virtualization:
 * get_post_meta( $id ) / get_post_custom() all-meta reads, and WooCommerce's raw
 * WC_Data metadata cache for the specialized typed families.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Storage_Terminal_Meta_Compat {

	/* Generic v6 rows already use 4e18+custom-id. Keep specialized virtual IDs in
	 * disjoint 1e18-wide ranges so they remain stable if Woo caches filtered raw
	 * metadata across requests. The existing v6 bridge already assumes 64-bit PHP;
	 * on a 32-bit runtime we expose read values with id=0 and mutations still flow
	 * through the ordinary key-based metadata API instead of risking ID collision. */
	const OP_META_ID_BASE       = 5000000000000000000;
	const IDENTITY_META_ID_BASE = 6000000000000000000;
	const META_ID_RANGE         = 1000000000000000000;
	const META_ID_STRIDE        = 32;

	/** @return array<string,int> */
	private static function operational_codes() {
		return array(
			'_mobo_product_seen_revision'    => 1,
			'_mobo_product_applied_revision' => 2,
			'_mobo_variant_seen_revision'    => 3,
			'_mobo_variant_applied_revision' => 4,
			'_mobo_product_source_hash'      => 5,
			'_mobo_variant_source_hash'      => 6,
			'mobo_sync_incomplete'            => 7,
		);
	}

	/** @return array<string,int> */
	private static function identity_codes() {
		return array(
			'product_guid'                    => 1,
			'variant_guid'                    => 2,
			'mobo_variant_guid'               => 3,
			'_mobo_variant_guid'              => 4,
			'portal_product_id'               => 5,
			'mobo_portal_product_id'          => 6,
			'_mobo_portal_product_id'         => 7,
			'PortalProductId'                 => 8,
			'portal_variant_id'               => 9,
			'mobo_portal_variant_id'          => 10,
			'_mobo_portal_variant_id'         => 11,
			'PortalVariantId'                 => 12,
		);
	}

	/** @return bool */
	private static function supported_object( $object_id ) {
		return in_array( get_post_type( absint( $object_id ) ), array( 'product', 'product_variation' ), true );
	}

	/** @return bool */
	private static function any_terminal_authority() {
		return ( class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' ) && Mobo_Core_Storage_Operational_Meta_Retirement::is_fenced() )
			|| ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced() )
			|| ( class_exists( 'Mobo_Core_Storage_Entity_Meta_Retirement' ) && Mobo_Core_Storage_Entity_Meta_Retirement::is_authoritative() );
	}

	/**
	 * Preserve get_post_meta( $id ) / get_post_custom() after physical retirement.
	 * WordPress' all-meta contract is a map of key => raw stored value arrays, so
	 * generic values stay serialized here just as wp_postmeta cache values do.
	 *
	 * @return mixed
	 */
	public static function filter_all_meta_read( $check, $object_id, $meta_key, $single = false, $meta_type = 'post' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $single, $meta_type );
		$object_id = absint( $object_id );
		if ( null !== $check || '' !== (string) $meta_key || $object_id <= 0 || ! self::supported_object( $object_id ) || ! self::any_terminal_authority() ) {
			return $check;
		}

		$legacy_map = self::legacy_all_meta_map( $object_id );
		if ( null === $legacy_map ) {
			/* A complete all-meta view cannot be proven. Returning an empty array is
			 * safer than exposing retired/stale Mobo rows as authoritative. */
			return array();
		}

		$out = array();
		foreach ( $legacy_map as $key => $values ) {
			$key = (string) $key;
			if ( self::specialized_key_is_terminal_for_object( $object_id, $key )
				|| self::generic_key_is_terminal_for_object( $object_id, $key ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				if ( ! isset( $out[ $key ] ) ) { $out[ $key ] = array(); }
				$out[ $key ][] = null === $value ? '' : (string) $value;
			}
		}

		self::append_specialized_all_meta( $out, $object_id );
		self::append_generic_all_meta( $out, $object_id );
		return $out;
	}

	/**
	 * Raw wp_postmeta key => values map for one object.
	 *
	 * 10.34.78: served from WordPress' own post_meta cache (update_meta_cache() reads
	 * the identical `post_id=%d ORDER BY meta_id ASC` row set and every metadata
	 * write invalidates it). Previously every all-meta read, including storefront
	 * get_post_custom() calls, issued its own uncached SELECT.
	 *
	 * @param int $object_id Object ID.
	 * @return array|null Null when the rows cannot be read.
	 */
	private static function legacy_all_meta_map( $object_id ) {
		$object_id = absint( $object_id );
		if ( function_exists( 'wp_cache_get' ) && function_exists( 'update_meta_cache' ) ) {
			$cached = wp_cache_get( $object_id, 'post_meta' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			$primed = update_meta_cache( 'post', array( $object_id ) );
			if ( is_array( $primed ) ) {
				return isset( $primed[ $object_id ] ) && is_array( $primed[ $object_id ] ) ? $primed[ $object_id ] : array();
			}
		}
		global $wpdb;
		$wpdb->last_error = '';
		$legacy_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id ASC", $object_id ),
			ARRAY_A
		);
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $legacy_rows ) ) {
			return null;
		}
		$map = array();
		foreach ( $legacy_rows as $row ) {
			$map[ (string) ( $row['meta_key'] ?? '' ) ][] = $row['meta_value'] ?? '';
		}
		return $map;
	}

	/** @return bool */
	private static function specialized_key_is_terminal_for_object( $object_id, $key ) {
		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' )
			&& Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced()
			&& isset( Mobo_Core_Storage_Identity_Meta_Retirement::managed_keys()[ $key ] )
			&& Mobo_Core_Storage_Identity_Meta_Retirement::key_applies_to_object( $object_id, $key ) ) {
			return true;
		}
		if ( class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' )
			&& Mobo_Core_Storage_Operational_Meta_Retirement::is_fenced()
			&& isset( Mobo_Core_Storage_Operational_Meta_Retirement::managed_keys()[ $key ] )
			&& Mobo_Core_Storage_Operational_Meta_Retirement::key_applies_to_object( $object_id, $key ) ) {
			return true;
		}
		return false;
	}

	/** @return bool */
	private static function generic_key_is_terminal_for_object( $object_id, $key ) {
		return class_exists( 'Mobo_Core_Storage_Entity_Meta_Retirement' )
			&& Mobo_Core_Storage_Entity_Meta_Retirement::is_authoritative()
			&& Mobo_Core_Storage_Entity_Meta_Retirement::is_managed_key( $key )
			&& Mobo_Core_Storage_Entity_Meta_Retirement::key_applies_to_object( $object_id, $key );
	}

	/** @return void */
	private static function append_specialized_all_meta( &$out, $object_id ) {
		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced() ) {
			foreach ( Mobo_Core_Storage_Identity_Meta_Retirement::managed_keys() as $key => $family ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				unset( $family );
				if ( ! Mobo_Core_Storage_Identity_Meta_Retirement::key_applies_to_object( $object_id, $key ) || ! metadata_exists( 'post', $object_id, $key ) ) { continue; }
				$value = get_post_meta( $object_id, $key, true );
				$out[ $key ] = array( self::raw_meta_value( $value ) );
			}
		}
		if ( class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' ) && Mobo_Core_Storage_Operational_Meta_Retirement::is_fenced() ) {
			foreach ( Mobo_Core_Storage_Operational_Meta_Retirement::managed_keys() as $key => $family ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				unset( $family );
				if ( ! Mobo_Core_Storage_Operational_Meta_Retirement::key_applies_to_object( $object_id, $key ) || ! metadata_exists( 'post', $object_id, $key ) ) { continue; }
				$value = get_post_meta( $object_id, $key, true );
				$out[ $key ] = array( self::raw_meta_value( $value ) );
			}
		}
	}

	/** @return void */
	private static function append_generic_all_meta( &$out, $object_id ) {
		if ( ! class_exists( 'Mobo_Core_Storage_Entity_Meta_Retirement' ) || ! Mobo_Core_Storage_Entity_Meta_Retirement::is_authoritative() ) { return; }
		global $wpdb;
		$table = Mobo_Core_Storage_Entity_Meta_Retirement::table_name();
		if ( '' === $table ) { return; }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_key,meta_value FROM {$table} WHERE woo_object_id=%d ORDER BY sort_order ASC,id ASC", absint( $object_id ) ),
			ARRAY_A
		);
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $rows ) ) { return; }
		foreach ( $rows as $row ) {
			$key = (string) ( $row['meta_key'] ?? '' );
			if ( '' === $key || ! Mobo_Core_Storage_Entity_Meta_Retirement::is_managed_key( $key ) || ! Mobo_Core_Storage_Entity_Meta_Retirement::key_applies_to_object( $object_id, $key ) ) { continue; }
			if ( ! isset( $out[ $key ] ) ) { $out[ $key ] = array(); }
			$out[ $key ][] = null === ( $row['meta_value'] ?? null ) ? '' : (string) $row['meta_value'];
		}
	}

	/** @return string */
	private static function raw_meta_value( $value ) {
		$raw = maybe_serialize( $value );
		return null === $raw ? '' : (string) $raw;
	}

	/**
	 * Add specialized typed metadata to WooCommerce's raw metadata view. Generic
	 * v6 rows are appended by Mobo_Core_Storage_Entity_Meta_Retirement at priority 20.
	 *
	 * @return array
	 */
	public static function filter_woocommerce_read_meta( $raw_meta_data, $object ) {
		$raw_meta_data = is_array( $raw_meta_data ) ? $raw_meta_data : array();
		if ( ! is_object( $object ) || ! method_exists( $object, 'get_id' ) ) { return $raw_meta_data; }
		$object_id = absint( $object->get_id() );
		if ( $object_id <= 0 || ! self::supported_object( $object_id ) ) { return $raw_meta_data; }

		$filtered = array();
		foreach ( $raw_meta_data as $row ) {
			$key = is_object( $row ) && isset( $row->meta_key ) ? (string) $row->meta_key : '';
			if ( self::specialized_key_is_terminal_for_object( $object_id, $key ) ) { continue; }
			$filtered[] = $row;
		}

		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced() ) {
			foreach ( self::identity_codes() as $key => $code ) {
				if ( ! Mobo_Core_Storage_Identity_Meta_Retirement::key_applies_to_object( $object_id, $key ) || ! metadata_exists( 'post', $object_id, $key ) ) { continue; }
				$filtered[] = self::woo_row( self::virtual_meta_id( 'identity', $object_id, $code ), $key, get_post_meta( $object_id, $key, true ) );
			}
		}
		if ( class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' ) && Mobo_Core_Storage_Operational_Meta_Retirement::is_fenced() ) {
			foreach ( self::operational_codes() as $key => $code ) {
				if ( ! Mobo_Core_Storage_Operational_Meta_Retirement::key_applies_to_object( $object_id, $key ) || ! metadata_exists( 'post', $object_id, $key ) ) { continue; }
				$filtered[] = self::woo_row( self::virtual_meta_id( 'operational', $object_id, $code ), $key, get_post_meta( $object_id, $key, true ) );
			}
		}
		return $filtered;
	}

	/** @return stdClass */
	private static function woo_row( $meta_id, $key, $value ) {
		$row = new stdClass();
		$row->meta_id    = false === $meta_id ? 0 : $meta_id;
		$row->meta_key   = (string) $key;
		$row->meta_value = self::raw_meta_value( $value );
		return $row;
	}

	/** @return int|false */
	private static function virtual_meta_id( $family, $object_id, $code ) {
		if ( PHP_INT_SIZE < 8 ) { return false; }
		$object_id = absint( $object_id );
		$code      = absint( $code );
		$base      = 'identity' === $family ? self::IDENTITY_META_ID_BASE : self::OP_META_ID_BASE;
		if ( $object_id <= 0 || $code <= 0 || $code >= self::META_ID_STRIDE ) { return false; }
		$delta = ( $object_id * self::META_ID_STRIDE ) + $code;
		if ( $delta <= 0 || $delta >= self::META_ID_RANGE || $base > PHP_INT_MAX - $delta ) { return false; }
		return $base + $delta;
	}

	/** @return array|null */
	private static function decode_virtual_meta_id( $meta_id ) {
		if ( PHP_INT_SIZE < 8 || ! is_numeric( $meta_id ) ) { return null; }
		$id = (int) $meta_id;
		$family = '';
		$base = 0;
		$codes = array();
		if ( $id > self::OP_META_ID_BASE && $id < self::OP_META_ID_BASE + self::META_ID_RANGE ) {
			$family = 'operational'; $base = self::OP_META_ID_BASE; $codes = array_flip( self::operational_codes() );
		} elseif ( $id > self::IDENTITY_META_ID_BASE && $id < self::IDENTITY_META_ID_BASE + self::META_ID_RANGE ) {
			$family = 'identity'; $base = self::IDENTITY_META_ID_BASE; $codes = array_flip( self::identity_codes() );
		} else { return null; }
		$delta     = $id - $base;
		$code      = $delta % self::META_ID_STRIDE;
		$object_id = (int) floor( $delta / self::META_ID_STRIDE );
		if ( $object_id <= 0 || ! isset( $codes[ $code ] ) ) { return null; }
		return array( 'family'=>$family, 'objectId'=>$object_id, 'key'=>(string) $codes[ $code ] );
	}

	/** @return mixed */
	public static function filter_get_by_mid( $check, $meta_id ) {
		if ( null !== $check ) { return $check; }
		$decoded = self::decode_virtual_meta_id( $meta_id );
		if ( null === $decoded || ! self::decoded_row_is_authoritative( $decoded ) ) { return $check; }
		$object_id = absint( $decoded['objectId'] );
		$key       = (string) $decoded['key'];
		if ( ! metadata_exists( 'post', $object_id, $key ) ) { return false; }
		$meta = new stdClass();
		$meta->meta_id    = (string) (int) $meta_id;
		$meta->post_id    = (string) $object_id;
		$meta->meta_key   = $key;
		$meta->meta_value = get_post_meta( $object_id, $key, true );
		return $meta;
	}

	/** @return mixed */
	public static function filter_update_by_mid( $check, $meta_id, $meta_value, $meta_key = false ) {
		if ( null !== $check ) { return $check; }
		$decoded = self::decode_virtual_meta_id( $meta_id );
		if ( null === $decoded || ! self::decoded_row_is_authoritative( $decoded ) ) { return $check; }
		$key = (string) $decoded['key'];
		/* WC always supplies the same key. Cross-key rename of a typed virtual row
		 * has no lossless representation and is therefore rejected fail-closed. */
		if ( false !== $meta_key && (string) $meta_key !== $key ) { return false; }
		$object_id = absint( $decoded['objectId'] );
		if ( 'identity' === $decoded['family'] ) {
			return Mobo_Core_Storage_Identity_Meta_Retirement::filter_update( null, $object_id, $key, $meta_value, '' );
		}
		return Mobo_Core_Storage_Operational_Meta_Retirement::filter_update( null, $object_id, $key, $meta_value, '' );
	}

	/** @return mixed */
	public static function filter_delete_by_mid( $check, $meta_id ) {
		if ( null !== $check ) { return $check; }
		$decoded = self::decode_virtual_meta_id( $meta_id );
		if ( null === $decoded || ! self::decoded_row_is_authoritative( $decoded ) ) { return $check; }
		$object_id = absint( $decoded['objectId'] );
		$key       = (string) $decoded['key'];
		if ( 'identity' === $decoded['family'] ) {
			return Mobo_Core_Storage_Identity_Meta_Retirement::filter_delete( null, $object_id, $key, '', false );
		}
		return Mobo_Core_Storage_Operational_Meta_Retirement::filter_delete( null, $object_id, $key, '', false );
	}

	/** @return bool */
	private static function decoded_row_is_authoritative( $decoded ) {
		$object_id = absint( $decoded['objectId'] ?? 0 );
		$key       = (string) ( $decoded['key'] ?? '' );
		if ( 'identity' === ( $decoded['family'] ?? '' ) ) {
			return class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' )
				&& Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced()
				&& Mobo_Core_Storage_Identity_Meta_Retirement::key_applies_to_object( $object_id, $key );
		}
		return class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' )
			&& Mobo_Core_Storage_Operational_Meta_Retirement::is_fenced()
			&& Mobo_Core_Storage_Operational_Meta_Retirement::key_applies_to_object( $object_id, $key );
	}
}
