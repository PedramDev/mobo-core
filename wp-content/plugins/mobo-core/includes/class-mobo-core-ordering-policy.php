<?php
/**
 * Shared ordering evidence policy for Product/Variant webhook state.
 *
 * Keeps SourceRevision/eventVersion extraction and comparison identical across
 * Event Store coalescing, Product Sync stale protection and Sync Health.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Ordering_Policy {

	/** @var array */
	private static $ordering_keys = array(
		'sourceRevision',
		'revision',
		'eventVersion',
		'_moboEventVersion',
		'version',
		'entityVersion',
	);

	/**
	 * Return canonical ordering keys in priority order.
	 *
	 * @return array
	 */
	public static function keys() {
		return self::$ordering_keys;
	}


	/**
	 * Canonical ordering fields and accepted envelope aliases.
	 * Identity fields such as entityGuid/remoteEventId intentionally do not live
	 * here because they are not ordering evidence.
	 *
	 * @return array
	 */
	public static function alias_map() {
		return array(
			'eventVersion'   => array( 'eventVersion', 'EventVersion', '_moboEventVersion' ),
			'sourceRevision' => array( 'sourceRevision', 'SourceRevision' ),
			'revision'       => array( 'revision', 'Revision' ),
			'entityVersion'  => array( 'entityVersion', 'EntityVersion' ),
			'version'        => array( 'version', 'Version' ),
		);
	}

	/**
	 * Preserve ordering evidence when replacing an envelope with fetched payload.
	 * Inner/fetched values win; wrapper values only fill missing fields.
	 *
	 * @param array $payload Inner/fetched payload.
	 * @param array $wrapper Outer envelope.
	 * @return array
	 */
	public static function merge_context( $payload, $wrapper ) {
		if ( ! is_array( $payload ) || ! is_array( $wrapper ) ) {
			return is_array( $payload ) ? $payload : array();
		}

		foreach ( self::alias_map() as $canonical => $aliases ) {
			$current = self::array_value( $payload, $canonical, null );
			if ( null !== $current && ( ! is_scalar( $current ) || '' !== trim( (string) $current ) ) ) {
				continue;
			}
			foreach ( $aliases as $alias ) {
				$value = array_key_exists( $alias, $wrapper ) ? $wrapper[ $alias ] : null;
				if ( null === $value || ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
					continue;
				}
				$payload[ $canonical ] = sanitize_text_field( (string) $value );
				break;
			}
		}

		if ( ! isset( $payload['_moboEventVersion'] ) && isset( $payload['eventVersion'] ) && '' !== trim( (string) $payload['eventVersion'] ) ) {
			$payload['_moboEventVersion'] = sanitize_text_field( (string) $payload['eventVersion'] );
		}

		return $payload;
	}

	/**
	 * Extract the first numeric ordering revision from one or more payload levels.
	 * No recursion is performed; callers control exactly which wrappers may supply
	 * ordering evidence.
	 *
	 * @param mixed ...$sources Payload arrays in priority order.
	 * @return int
	 */
	public static function extract_numeric_revision_from_sources( ...$sources ) {
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			foreach ( self::$ordering_keys as $key ) {
				$value = self::array_value( $source, $key, '' );
				if ( ! is_scalar( $value ) ) {
					continue;
				}
				$value = trim( (string) $value );
				if ( '' !== $value && ctype_digit( $value ) ) {
					return absint( $value );
				}
			}
		}
		return 0;
	}

	/**
	 * Extract the first raw ordering watermark from one or more payload levels.
	 *
	 * @param mixed ...$sources Payload arrays in priority order.
	 * @return string
	 */
	public static function extract_version_from_sources( ...$sources ) {
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			foreach ( self::$ordering_keys as $key ) {
				$value = self::array_value( $source, $key, '' );
				if ( ! is_scalar( $value ) ) {
					continue;
				}
				$value = sanitize_text_field( trim( (string) $value ) );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return '';
	}

	/**
	 * Extract a numeric ordering revision recursively from nested webhook payloads.
	 *
	 * @param mixed $data Payload.
	 * @return int
	 */
	public static function extract_numeric_revision_recursive( $data ) {
		if ( ! is_array( $data ) ) {
			return 0;
		}

		$direct = self::extract_numeric_revision_from_sources( $data );
		if ( $direct > 0 ) {
			return $direct;
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$found = self::extract_numeric_revision_recursive( $value );
				if ( $found > 0 ) {
					return $found;
				}
			}
		}

		return 0;
	}

	/**
	 * Extract a raw ordering watermark recursively from nested webhook payloads.
	 *
	 * @param mixed $data Payload.
	 * @return string
	 */
	public static function extract_version_recursive( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$direct = self::extract_version_from_sources( $data );
		if ( '' !== $direct ) {
			return $direct;
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$found = self::extract_version_recursive( $value );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}

		return '';
	}

	/**
	 * Compare numeric or ISO-date ordering watermarks.
	 *
	 * @param mixed $left New/left version.
	 * @param mixed $right Existing/right version.
	 * @return int|null -1/0/1, or null when the formats are not safely comparable.
	 */
	public static function compare_versions( $left, $right ) {
		$left  = trim( (string) $left );
		$right = trim( (string) $right );

		if ( '' === $left || '' === $right ) {
			return null;
		}
		if ( $left === $right ) {
			return 0;
		}

		if ( ctype_digit( $left ) && ctype_digit( $right ) ) {
			$l = ltrim( $left, '0' );
			$r = ltrim( $right, '0' );
			$l = '' === $l ? '0' : $l;
			$r = '' === $r ? '0' : $r;
			if ( strlen( $l ) !== strlen( $r ) ) {
				return strlen( $l ) < strlen( $r ) ? -1 : 1;
			}
			$cmp = strcmp( $l, $r );
			return 0 === $cmp ? 0 : ( $cmp < 0 ? -1 : 1 );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}[T ]/', $left ) && preg_match( '/^\d{4}-\d{2}-\d{2}[T ]/', $right ) ) {
			$lt = strtotime( $left );
			$rt = strtotime( $right );
			if ( false !== $lt && false !== $rt ) {
				return $lt === $rt ? 0 : ( $lt < $rt ? -1 : 1 );
			}
		}

		return null;
	}

	/**
	 * Read camelCase/PascalCase without inventing additional aliases.
	 *
	 * @param array  $array Array.
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
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
