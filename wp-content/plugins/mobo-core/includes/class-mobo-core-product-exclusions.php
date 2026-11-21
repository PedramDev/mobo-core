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

		$path   = wp_parse_url( $url, PHP_URL_PATH );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		/* An absolute URL without a product path is not a usable exclusion. Older
		 * normalization could turn `https://source.example` into the synthetic path
		 * `/https://source.example`, which never matched a product but looked valid in
		 * Admin. Path-only input remains supported for backward compatibility. */
		if ( ( is_string( $host ) && '' !== $host ) || ( is_string( $scheme ) && '' !== $scheme ) ) {
			if ( ! is_string( $path ) || '' === trim( $path ) ) {
				return '';
			}
		} elseif ( ! is_string( $path ) || '' === $path ) {
			$path = $url;
		}

		$path = trim( (string) $path );
		if ( '' === $path ) {
			return '';
		}

		$path = '/' . ltrim( $path, '/' );
		$path = untrailingslashit( $path );

		return strtolower( $path );
	}

	/**
	 * Parse administrator input into the exact normalized list used by runtime.
	 *
	 * Empty lines are ignored. Duplicate representations (for example a full URL
	 * and the same path) collapse to one canonical path. Non-empty lines which do
	 * not produce a usable product path are reported instead of silently looking
	 * active in Admin.
	 *
	 * @param mixed $raw Newline-separated URLs/paths.
	 * @return array{paths:array<int,string>,canonical:string,inputCount:int,duplicateCount:int,invalidCount:int}
	 */
	public static function parse_input( $raw ) {
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return array(
				'paths'          => array(),
				'canonical'      => '',
				'inputCount'     => 0,
				'duplicateCount' => 0,
				'invalidCount'   => 0,
			);
		}

		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$lines = is_array( $lines ) ? $lines : array();
		$paths = array();
		$seen  = array();
		$input_count = 0;
		$duplicate_count = 0;
		$invalid_count = 0;

		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( (string) $line ) );
			if ( '' === $line ) {
				continue;
			}
			$input_count++;
			$normalized = self::normalize_url( $line );
			if ( '' === $normalized ) {
				$invalid_count++;
				continue;
			}
			if ( isset( $seen[ $normalized ] ) ) {
				$duplicate_count++;
				continue;
			}
			$seen[ $normalized ] = true;
			$paths[] = $normalized;
		}

		return array(
			'paths'          => $paths,
			'canonical'      => implode( "\n", $paths ),
			'inputCount'     => $input_count,
			'duplicateCount' => $duplicate_count,
			'invalidCount'   => $invalid_count,
		);
	}

	/**
	 * Return the configured normalized exclusion paths.
	 *
	 * @return array
	 */
	public static function get_excluded_urls() {
		$parsed = self::parse_input( Mobo_Core_Settings::get( self::OPTION_NAME, '' ) );
		return isset( $parsed['paths'] ) && is_array( $parsed['paths'] ) ? $parsed['paths'] : array();
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

		$evidence = Mobo_Core_Settings::get( self::GUID_EVIDENCE_OPTION, array() );
		$evidence = is_array( $evidence ) ? $evidence : array();
		$url      = isset( $evidence[ $product_guid ] ) ? (string) $evidence[ $product_guid ] : '';

		return '' !== $url && self::is_url_excluded( $url );
	}

	/**
	 * Whether a local parent/variation belongs to a currently excluded source URL.
	 *
	 * @param int  $post_id Product or variation ID.
	 * @param bool $remember Whether to persist GUID/path evidence when a direct URL match exists.
	 * @return bool
	 */
	public static function is_local_product_excluded( $post_id, $remember = true ) {
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
			if ( $remember && '' !== $guid ) {
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

		$evidence = Mobo_Core_Settings::get( self::GUID_EVIDENCE_OPTION, array() );
		$evidence = is_array( $evidence ) ? $evidence : array();
		$evidence[ $product_guid ] = $normalized;
		if ( count( $evidence ) > self::MAX_GUID_EVIDENCE ) {
			$evidence = array_slice( $evidence, -self::MAX_GUID_EVIDENCE, null, true );
		}

		if ( ! Mobo_Core_Settings::update( self::GUID_EVIDENCE_OPTION, $evidence, false ) ) {
			return false;
		}

		$stored = Mobo_Core_Settings::get( self::GUID_EVIDENCE_OPTION, array() );
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
