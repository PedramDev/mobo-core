<?php
/**
 * Shared durability boundary for wp_options state.
 *
 * update_option() returning false is ambiguous: it can mean either a no-op or a
 * failed write. Critical state therefore uses exact read-back as the postcondition.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Durable_State_Policy {

	/**
	 * Write an option and verify the exact value from the database/cache boundary.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value Desired value.
	 * @param bool   $autoload Autoload flag.
	 * @return bool
	 */
	public static function update_option_verified( $option, $value, $autoload = false ) {
		$option = trim( (string) $option );
		if ( '' === $option ) {
			return false;
		}

		update_option( $option, $value, $autoload );
		wp_cache_delete( $option, 'options' );
		$stored = get_option( $option, null );

		return self::option_read_back_matches( $stored, $value );
	}

	/**
	 * wp_options returns scalars as their stored string form (int 8 reads back as "8",
	 * true as "1"), so scalars compare by string form; arrays/objects stay exact.
	 *
	 * @param mixed $stored Read-back value.
	 * @param mixed $expected Written value.
	 * @return bool
	 */
	public static function option_read_back_matches( $stored, $expected ) {
		if ( null === $stored || null === $expected ) {
			return $stored === $expected;
		}
		if ( is_scalar( $stored ) && is_scalar( $expected ) ) {
			$stored   = is_bool( $stored ) ? ( $stored ? '1' : '' ) : (string) $stored;
			$expected = is_bool( $expected ) ? ( $expected ? '1' : '' ) : (string) $expected;
			return $stored === $expected;
		}
		return maybe_serialize( $stored ) === maybe_serialize( $expected );
	}

	/**
	 * Write a plugin-owned large state row outside wp_options and verify exact read-back.
	 *
	 * @param string $key State key.
	 * @param mixed  $value Desired value.
	 * @return bool
	 */
	public static function update_state_verified( $key, $value ) {
		if ( ! class_exists( 'Mobo_Core_State_Store' ) || ! Mobo_Core_State_Store::is_available() ) {
			return false;
		}
		if ( ! Mobo_Core_State_Store::set( $key, $value ) ) {
			return false;
		}
		$stored = Mobo_Core_State_Store::get( $key, null );
		return maybe_serialize( $stored ) === maybe_serialize( $value );
	}


	/**
	 * Canonicalize scalar metadata exactly as WordPress stores it in metadata tables.
	 * Numeric/bool/null scalars are read back as strings, while arrays/objects retain
	 * their serialized structure. Verified persistence must compare DB semantics, not
	 * PHP in-memory scalar types.
	 *
	 * @param mixed $value Desired metadata value.
	 * @return mixed
	 */
	public static function canonical_meta_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( null === $value ) {
			return '';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		return $value;
	}

	/** Persist post metadata and verify WordPress-normalized read-back. */
	public static function update_post_meta_verified( $post_id, $key, $value ) {
		$post_id = absint( $post_id );
		$key     = sanitize_key( (string) $key );
		if ( $post_id <= 0 || '' === $key ) {
			return false;
		}

		$expected = self::canonical_meta_value( $value );
		$current  = self::canonical_meta_value( get_post_meta( $post_id, $key, true ) );
		if ( maybe_serialize( $current ) !== maybe_serialize( $expected ) ) {
			update_post_meta( $post_id, $key, $expected );
		}
		self::invalidate_post_meta_read_caches( $post_id, array( $key ) );
		$stored = self::canonical_meta_value( get_post_meta( $post_id, $key, true ) );
		return maybe_serialize( $stored ) === maybe_serialize( $expected );
	}

	/**
	 * Make the verification read-back hit the database.
	 *
	 * Terminal Custom-Storage keys are served by Mobo's own request-local row cache,
	 * not by WordPress' post_meta cache, so dropping that object snapshot is enough
	 * for them and avoids reloading every WooCommerce meta row of the product. Any
	 * other key keeps the original full post_meta cache invalidation.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $keys Keys about to be verified.
	 * @return void
	 */
	private static function invalidate_post_meta_read_caches( $post_id, $keys ) {
		$post_id = absint( $post_id );
		if ( class_exists( 'Mobo_Core_Storage_Row_Cache', false ) ) {
			Mobo_Core_Storage_Row_Cache::forget_object( $post_id );
		}
		$all_custom = class_exists( 'Mobo_Core_Storage_Entity_Meta_Retirement', false )
			&& Mobo_Core_Storage_Entity_Meta_Retirement::is_authoritative();
		if ( $all_custom ) {
			foreach ( (array) $keys as $key ) {
				if ( ! Mobo_Core_Storage_Entity_Meta_Retirement::key_applies_to_object( $post_id, (string) $key ) ) {
					$all_custom = false;
					break;
				}
			}
		}
		if ( ! $all_custom ) {
			wp_cache_delete( $post_id, 'post_meta' );
		}
	}

	/**
	 * PERF03: Verify one contiguous group of bookkeeping metadata with a single
	 * explicit post-meta cache refresh. This is ONLY for fields that have no
	 * externally observable side effect or crash/ack fence between individual
	 * writes. Ordering watermarks, in-flight markers and payment metadata must
	 * continue to use the individual verified-write boundary.
	 *
	 * Every field is checked AFTER the final cache invalidation. An ambiguous
	 * update_post_meta() return value never becomes proof of persistence. In
	 * particular, neither the pre-write metadata cache nor a successful UPDATE
	 * is accepted as the final postcondition.
	 *
	 * @param int   $post_id Post or variation ID.
	 * @param array $updates Associative key => desired value, in write order.
	 * @return bool Whether the entire group matches verified persisted state.
	 */
	public static function update_post_meta_verified_batch( $post_id, $updates ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! is_array( $updates ) || empty( $updates ) ) {
			return false;
		}
		$expected = array();
		foreach ( $updates as $raw_key => $value ) {
			$key = sanitize_key( (string) $raw_key );
			if ( '' === $key || isset( $expected[ $key ] ) ) {
				return false;
			}
			$expected[ $key ] = self::canonical_meta_value( $value );
		}

		foreach ( $expected as $key => $value ) {
			$current = self::canonical_meta_value( get_post_meta( $post_id, $key, true ) );
			if ( maybe_serialize( $current ) !== maybe_serialize( $value ) ) {
				update_post_meta( $post_id, $key, $value );
			}
		}
		/* WordPress update_post_meta() may have invalidated the cache while writing.
		 * Make one final explicit refresh even when every field looked like a no-op. */
		self::invalidate_post_meta_read_caches( $post_id, array_keys( $expected ) );
		foreach ( $expected as $key => $value ) {
			$stored = self::canonical_meta_value( get_post_meta( $post_id, $key, true ) );
			if ( maybe_serialize( $stored ) !== maybe_serialize( $value ) ) {
				return false;
			}
		}
		return true;
	}

	/** Persist term metadata and verify WordPress-normalized read-back. */
	public static function update_term_meta_verified( $term_id, $key, $value ) {
		$term_id = absint( $term_id );
		$key     = sanitize_key( (string) $key );
		if ( $term_id <= 0 || '' === $key ) {
			return false;
		}

		$expected = self::canonical_meta_value( $value );
		$current  = self::canonical_meta_value( get_term_meta( $term_id, $key, true ) );
		if ( maybe_serialize( $current ) !== maybe_serialize( $expected ) ) {
			update_term_meta( $term_id, $key, $expected );
		}
		wp_cache_delete( $term_id, 'term_meta' );
		$stored = self::canonical_meta_value( get_term_meta( $term_id, $key, true ) );
		return maybe_serialize( $stored ) === maybe_serialize( $expected );
	}

}
