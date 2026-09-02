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
		wp_cache_delete( $post_id, 'post_meta' );
		$stored = self::canonical_meta_value( get_post_meta( $post_id, $key, true ) );
		return maybe_serialize( $stored ) === maybe_serialize( $expected );
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
