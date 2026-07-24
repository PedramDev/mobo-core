<?php
/**
 * Persian date formatting for WordPress administration.
 *
 * Runtime timestamps, queue values and REST payloads remain UTC/Unix. This
 * helper is presentation-only and always renders in Asia/Tehran.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Iran_Date {

	/**
	 * Compatible replacement for wp_date() in Mobo administration screens.
	 * Gregorian year/month/day tokens are rendered as Jalali values while time
	 * tokens are evaluated in Asia/Tehran.
	 *
	 * @param string   $format Date format.
	 * @param int|null $timestamp Unix timestamp; current time when omitted.
	 * @return string
	 */
	public static function format( $format = 'Y/m/d H:i:s', $timestamp = null ) {
		$timestamp = null === $timestamp ? time() : absint( $timestamp );
		if ( $timestamp <= 0 ) {
			return '—';
		}

		try {
			$date = new DateTimeImmutable( '@' . $timestamp );
			$date = $date->setTimezone( new DateTimeZone( 'Asia/Tehran' ) );
		} catch ( Exception $e ) {
			return '—';
		}

		list( $jy, $jm, $jd ) = self::gregorian_to_jalali(
			(int) $date->format( 'Y' ),
			(int) $date->format( 'n' ),
			(int) $date->format( 'j' )
		);

		$replacements = array(
			'Y' => str_pad( (string) $jy, 4, '0', STR_PAD_LEFT ),
			'y' => substr( (string) $jy, -2 ),
			'm' => str_pad( (string) $jm, 2, '0', STR_PAD_LEFT ),
			'n' => (string) $jm,
			'd' => str_pad( (string) $jd, 2, '0', STR_PAD_LEFT ),
			'j' => (string) $jd,
			'H' => $date->format( 'H' ),
			'G' => $date->format( 'G' ),
			'i' => $date->format( 'i' ),
			's' => $date->format( 's' ),
			'T' => 'Asia/Tehran',
		);

		$out = '';
		$escaped = false;
		for ( $i = 0, $length = strlen( $format ); $i < $length; $i++ ) {
			$char = $format[ $i ];
			if ( $escaped ) {
				$out .= $char;
				$escaped = false;
				continue;
			}
			if ( '\\' === $char ) {
				$escaped = true;
				continue;
			}
			$out .= array_key_exists( $char, $replacements ) ? $replacements[ $char ] : $date->format( $char );
		}

		return self::persian_digits( $out );
	}

	/**
	 * Format an ISO, MySQL, timestamp or DateTime-compatible value.
	 *
	 * @param mixed  $value Input value.
	 * @param string $format Output format.
	 * @return string
	 */
	public static function format_value( $value, $format = 'Y/m/d H:i:s' ) {
		if ( empty( $value ) ) {
			return '—';
		}
		if ( is_numeric( $value ) ) {
			return self::format( $format, absint( $value ) );
		}
		$timestamp = strtotime( (string) $value );
		return false === $timestamp ? '—' : self::format( $format, $timestamp );
	}

	private static function persian_digits( $value ) {
		return strtr( (string) $value, array(
			'0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
			'5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
		) );
	}

	/** Standard Gregorian-to-Jalali conversion. */
	private static function gregorian_to_jalali( $gy, $gm, $gd ) {
		$g_days_in_month = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		$j_days_in_month = array( 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29 );
		$gy -= 1600;
		$gm -= 1;
		$gd -= 1;
		$g_day_no = 365 * $gy + intdiv( $gy + 3, 4 ) - intdiv( $gy + 99, 100 ) + intdiv( $gy + 399, 400 );
		for ( $i = 0; $i < $gm; ++$i ) { $g_day_no += $g_days_in_month[ $i ]; }
		if ( $gm > 1 && ( ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400 ) ) { ++$g_day_no; }
		$g_day_no += $gd;
		$j_day_no = $g_day_no - 79;
		$j_np = intdiv( $j_day_no, 12053 );
		$j_day_no %= 12053;
		$jy = 979 + 33 * $j_np + 4 * intdiv( $j_day_no, 1461 );
		$j_day_no %= 1461;
		if ( $j_day_no >= 366 ) {
			$jy += intdiv( $j_day_no - 1, 365 );
			$j_day_no = ( $j_day_no - 1 ) % 365;
		}
		for ( $i = 0; $i < 11 && $j_day_no >= $j_days_in_month[ $i ]; ++$i ) { $j_day_no -= $j_days_in_month[ $i ]; }
		return array( $jy, $i + 1, $j_day_no + 1 );
	}
}
