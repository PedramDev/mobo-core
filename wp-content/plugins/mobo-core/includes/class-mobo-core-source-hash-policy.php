<?php
/**
 * Canonical Product/Variation source-hash contract.
 *
 * Product Sync builds these hashes with md5(), so the operational storage contract
 * is exactly 32 lowercase hexadecimal characters. This class keeps Custom Storage,
 * parity, primary reads, and write suppression from drifting to a different width.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Source_Hash_Policy {
	const HEX_LENGTH = 32;

	/** @return string */
	public static function normalize( $value ) {
		if ( ! is_scalar( $value ) ) { return ''; }
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{32}$/', $value ) ? $value : '';
	}

	/** @return bool */
	public static function is_valid( $value ) {
		return '' !== self::normalize( $value );
	}
}
