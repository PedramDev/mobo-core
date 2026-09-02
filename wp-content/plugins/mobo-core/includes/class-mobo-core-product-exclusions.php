<?php
/**
 * Shared product exclusion policy.
 *
 * The administrator configures exclusions by source URL/path. Every source-driven
 * product mutation path must consult the same policy so Repair, Variant, Reprice,
 * Recategorize and image-recovery work cannot bypass the Product Sync filter.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Product_Exclusions {

	const OPTION_NAME          = 'mobo_core_excluded_product_urls';
	const GUID_EVIDENCE_OPTION = 'mobo_core_excluded_product_guid_urls';
	const MAX_GUID_EVIDENCE    = 1000;

	/**
	 * Normalize an absolute source URL or path to the canonical exclusion key.
	 *
	 * @param mixed $url URL/path.
	 * @return string
	 */
	public static function normalize_url( $url ) {
		$url = trim( sanitize_text_field( (string) $url ) );

		if ( '' === $url ) {
			return '';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = $url;
		}

		$path = trim( $path );
		if ( '' === $path ) {
			return '';
		}

		$path = '/' . ltrim( $path, '/' );
		$path = untrailingslashit( $path );

		return strtolower( $path );
	}

	/**
	 * Return the configured normalized exclusion paths.
	 *
	 * @return array
	 */
	public static function get_excluded_urls() {
		$raw = (string) get_option( self::OPTION_NAME, '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		if ( ! is_array( $lines ) ) {
			return array();
		}

		$urls = array();
		foreach ( $lines as $line ) {
			$normalized = self::normalize_url( $line );
			if ( '' !== $normalized ) {
				$urls[] = $normalized;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Whether a source URL/path is currently excluded.
	 *
	 * @param mixed $url URL/path.
	 * @return bool
	 */
	public static function is_url_excluded( $url ) {
		$normalized = self::normalize_url( $url );
		return '' !== $normalized && in_array( $normalized, self::get_excluded_urls(), true );
	}

	/**
	 * Return a product URL from known Portal/Mobo payload shapes.
	 *
	 * @param mixed $payload Product payload.
	 * @return string
	 */
	public static function get_payload_url( $payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}

		foreach ( array( 'url', 'Url', 'productUrl', 'ProductUrl', 'product_url', 'path', 'Path' ) as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				$value = sanitize_text_field( (string) $payload[ $key ] );
				if ( '' !== trim( $value ) ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * Whether a product payload is excluded. When possible, remember the GUID→URL
	 * evidence so an UpdateVariant event without a URL can be terminated safely
	 * instead of retrying forever while its intentionally excluded parent is absent.
	 *
	 * The remembered path is never an independent source of truth: every lookup
	 * re-checks it against the administrator's current exclusion list. Removing an
	 * exclusion therefore immediately re-enables the GUID.
	 *
	 * @param mixed $payload Product payload.
	 * @param bool  $remember Whether to remember GUID/URL evidence.
	 * @return bool
	 */
	public static function is_payload_excluded( $payload, $remember = true ) {
		$url = self::get_payload_url( $payload );
		if ( '' === $url || ! self::is_url_excluded( $url ) ) {
			return false;
		}

		if ( $remember ) {
			$guid = self::get_payload_guid( $payload );
			if ( '' !== $guid ) {
				self::remember_guid_url( $guid, $url );
			}
		}

		return true;
	}

	/**
	 * Whether a product GUID has durable evidence for a URL which is still in the
	 * current exclusion list.
	 *
	 * @param mixed $product_guid Product GUID.
	 * @return bool
	 */
	public static function is_product_guid_excluded( $product_guid ) {
		$product_guid = strtolower( trim( sanitize_text_field( (string) $product_guid ) ) );
		if ( ! self::is_guid( $product_guid ) ) {
			return false;
		}

		$evidence = get_option( self::GUID_EVIDENCE_OPTION, array() );
		$evidence = is_array( $evidence ) ? $evidence : array();
		$url      = isset( $evidence[ $product_guid ] ) ? (string) $evidence[ $product_guid ] : '';

		return '' !== $url && self::is_url_excluded( $url );
	}

	/**
	 * Whether a local parent/variation belongs to a currently excluded source URL.
	 *
	 * @param int $post_id Product or variation ID.
	 * @return bool
	 */
	public static function is_local_product_excluded( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && 'product_variation' === $post->post_type ) {
			$post_id = absint( $post->post_parent );
		}

		if ( $post_id <= 0 || 'product' !== get_post_type( $post_id ) ) {
			return false;
		}

		$url = sanitize_text_field( (string) get_post_meta( $post_id, 'mobo_url', true ) );
		if ( '' !== $url && self::is_url_excluded( $url ) ) {
			$guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );
			if ( '' !== $guid ) {
				self::remember_guid_url( $guid, $url );
			}
			return true;
		}

		$guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );
		return '' !== $guid && self::is_product_guid_excluded( $guid );
	}

	/**
	 * Persist bounded GUID→normalized URL evidence and verify the write.
	 *
	 * @param mixed $product_guid Product GUID.
	 * @param mixed $url URL/path.
	 * @return bool
	 */
	public static function remember_guid_url( $product_guid, $url ) {
		$product_guid = strtolower( trim( sanitize_text_field( (string) $product_guid ) ) );
		$normalized   = self::normalize_url( $url );
		if ( ! self::is_guid( $product_guid ) || '' === $normalized ) {
			return false;
		}

		$evidence = get_option( self::GUID_EVIDENCE_OPTION, array() );
		$evidence = is_array( $evidence ) ? $evidence : array();
		$evidence[ $product_guid ] = $normalized;
		if ( count( $evidence ) > self::MAX_GUID_EVIDENCE ) {
			$evidence = array_slice( $evidence, -self::MAX_GUID_EVIDENCE, null, true );
		}

		if ( ! Mobo_Core_Durable_State_Policy::update_option_verified( self::GUID_EVIDENCE_OPTION, $evidence, false ) ) {
			return false;
		}

		$stored = get_option( self::GUID_EVIDENCE_OPTION, array() );
		return is_array( $stored )
			&& isset( $stored[ $product_guid ] )
			&& hash_equals( $normalized, (string) $stored[ $product_guid ] );
	}

	/**
	 * Extract a stable product GUID from known payload shapes.
	 *
	 * @param array $payload Product payload.
	 * @return string
	 */
	private static function get_payload_guid( $payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}

		foreach ( array( 'productId', 'ProductId', 'productGuid', 'ProductGuid', 'product_guid', 'guid', 'Guid', 'id', 'Id' ) as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				continue;
			}
			$value = strtolower( trim( sanitize_text_field( (string) $payload[ $key ] ) ) );
			if ( self::is_guid( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Strict GUID shape used by Portal product identities.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_guid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim( $value ) );
	}
}
