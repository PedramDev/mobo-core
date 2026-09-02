<?php
/**
 * Shared remote identity policy.
 *
 * Portal category/image/product identifiers are opaque remote keys. They are not
 * all guaranteed to be UUIDs, so this policy intentionally preserves the legacy
 * permissive contract: non-empty scalar identity without URL/path separators.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Remote_Identity_Policy {

	/**
	 * Whether a value is usable as an opaque remote identity key.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_valid( $value ) {
		if ( is_bool( $value ) || ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) ) {
			return false;
		}

		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value ) {
			return false;
		}
		return false === strpos( $value, '/' )
			&& false === strpos( $value, '\\' )
			&& false === strpos( $value, '://' );
	}

	/**
	 * Collect category GUID candidates from every supported Portal relation shape.
	 * Actual category identifiers are preferred; relation IDs remain last-resort
	 * compatibility candidates.
	 *
	 * @param mixed $ref Category reference.
	 * @return array
	 */
	public static function collect_category_guid_candidates( $ref ) {
		$guids = array();

		if ( ! is_array( $ref ) ) {
			if ( ! self::is_valid( $ref ) ) {
				return array();
			}
			return array( sanitize_text_field( (string) $ref ) );
		}

		$primary_keys = array(
			'category_guid', 'categoryGuid', 'categoryId', 'categoryGUID', 'guid',
			'remote_guid', 'remoteGuid', 'portal_category_id', 'portalCategoryId',
			'category_portal_id', 'categoryPortalId',
		);
		foreach ( $primary_keys as $key ) {
			self::append_candidate( $guids, self::array_value( $ref, $key, '' ) );
		}

		$nested = self::array_value( $ref, 'category', null );
		if ( is_array( $nested ) ) {
			foreach ( self::collect_category_guid_candidates( $nested ) as $nested_guid ) {
				self::append_candidate( $guids, $nested_guid );
			}
		} else {
			self::append_candidate( $guids, $nested );
		}

		$fallback_keys = array( 'product_category_id', 'productCategoryId', 'product_category_guid', 'productCategoryGuid', 'id' );
		foreach ( $fallback_keys as $key ) {
			self::append_candidate( $guids, self::array_value( $ref, $key, '' ) );
		}

		return array_values( array_unique( array_filter( $guids ) ) );
	}

	/**
	 * Return the preferred category GUID candidate.
	 *
	 * @param mixed $ref Category ref.
	 * @return string
	 */
	public static function category_guid( $ref ) {
		$guids = self::collect_category_guid_candidates( $ref );
		return ! empty( $guids ) ? sanitize_text_field( (string) $guids[0] ) : '';
	}

	/**
	 * Pick one stable identifier for diagnostics.
	 *
	 * @param array $identifiers Candidate identifiers.
	 * @return string
	 */
	public static function primary_identifier( $identifiers ) {
		if ( ! is_array( $identifiers ) || empty( $identifiers ) ) {
			return '';
		}
		foreach ( $identifiers as $identifier ) {
			if ( self::is_valid( $identifier ) ) {
				return sanitize_text_field( (string) $identifier );
			}
		}
		return '';
	}

	private static function append_candidate( &$values, $value ) {
		if ( self::is_valid( $value ) ) {
			$values[] = trim( sanitize_text_field( (string) $value ) );
		}
	}

	private static function array_value( $array, $key, $default = null ) {
		if ( ! is_array( $array ) ) {
			return $default;
		}
		if ( array_key_exists( $key, $array ) ) {
			return $array[ $key ];
		}
		$pascal = ucfirst( (string) $key );
		return array_key_exists( $pascal, $array ) ? $array[ $pascal ] : $default;
	}
}
