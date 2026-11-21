<?php
/**
 * Address value normalization for the Source purchase flow.
 *
 * - text():   Arabic ي/ى/ك -> Persian ی/ک, tatweel removed, whitespace/ZWNJ runs
 *             compacted. Safe for values sent to the Resolver: the Backend applies the
 *             same folding before building its resolution key, so approved aliases keep
 *             matching.
 * - digits(): Persian (۰-۹) and Arabic-Indic (٠-٩) digits -> ASCII.
 * - phone():  digits() + separators removed + +98 / 0098 / 98 country prefix -> 0.
 * - postcode(): digits() + every non-digit removed.
 * - match_key(): text() + lowercase + ZWNJ as space + «استان»/«شهرستان»/«شهر» prefix
 *             removed. LOCAL MATCHING ONLY; never sent to the Source.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Address_Normalizer {

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function text( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( '' === $value ) {
			return '';
		}
		$value = str_replace( array( 'ي', 'ى', 'ك', 'ـ' ), array( 'ی', 'ی', 'ک', '' ), $value );
		/* NBSP and other Unicode spaces behave like a normal space. */
		$value = preg_replace( '/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}\t\r\n]+/u', ' ', $value );
		/* ZWNJ runs collapse to one ZWNJ and absorb neighbouring spaces. */
		$value = preg_replace( '/\s*\x{200C}[\s\x{200C}]*/u', "\u{200C}", (string) $value );
		$value = preg_replace( '/ {2,}/', ' ', (string) $value );
		/* Multibyte-safe trim (trim()'s byte list would cut UTF-8 sequences). */
		$value = preg_replace( '/^[\s\x{200C}]+|[\s\x{200C}]+$/u', '', (string) $value );
		return null === $value ? '' : $value;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function digits( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		return strtr(
			$value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	/**
	 * Iranian mobile/phone to the domestic form (0912...). Non-Iranian international
	 * numbers keep their leading '+'.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function phone( $value ) {
		$value = trim( self::digits( $value ) );
		if ( '' === $value ) {
			return '';
		}
		$plus   = '+' === substr( $value, 0, 1 );
		$digits = preg_replace( '/\D+/', '', $value );
		$digits = null === $digits ? '' : $digits;
		if ( '' === $digits ) {
			return '';
		}
		if ( 0 === strpos( $digits, '0098' ) ) {
			return '0' . substr( $digits, 4 );
		}
		if ( 0 === strpos( $digits, '98' ) && ( $plus || 12 === strlen( $digits ) ) ) {
			return '0' . substr( $digits, 2 );
		}
		if ( $plus ) {
			return '+' . $digits;
		}
		if ( 10 === strlen( $digits ) && '9' === $digits[0] ) {
			return '0' . $digits;
		}
		return $digits;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function postcode( $value ) {
		$digits = preg_replace( '/\D+/', '', self::digits( $value ) );
		return null === $digits ? '' : $digits;
	}

	/**
	 * Whether a city/state value is a pure number (after digit folding).
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function is_numeric_code( $value ) {
		return (bool) preg_match( '/^[0-9]+$/D', trim( self::digits( $value ) ) );
	}

	/**
	 * Comparison key for local matching only.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function match_key( $value ) {
		$value = self::text( self::digits( $value ) );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = str_replace( array( 'ة', 'ۀ', 'أ', 'إ', 'ٱ' ), array( 'ه', 'ه', 'ا', 'ا', 'ا' ), $value );
		$value = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $value );
		$value = preg_replace( '/[\x{200C}\x{200D}]/u', ' ', (string) $value );
		$value = preg_replace( '/\s+/u', ' ', (string) $value );
		$value = trim( (string) $value );
		$value = preg_replace( '/^(?:استان|شهرستان|شهر)\s+/u', '', $value );
		return trim( (string) $value );
	}
}
