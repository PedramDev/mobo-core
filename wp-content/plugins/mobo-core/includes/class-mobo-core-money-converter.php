<?php
/**
 * C75B-P4G-P1: exact, side-effect-free Source -> Woo money converter.
 *
 * This engine is intentionally NOT wired into the price/shipping/order pipelines
 * and is not enabled by any setting in this stage. The signed Connection money
 * contract remains V1 and the existing fail-closed compatibility gate is intact.
 *
 * Every output is a canonical decimal STRING, never a floating-point operation.
 * No FX market rates, implicit fallback or rounding are allowed. A new currency
 * pair must be audited and explicitly added to pair_definition().
 *
 * Compatible with PHP 7.4+ (64-bit hosting required by MoboCore).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Money_Converter {
    const SAFE_MAX_MAJOR = '9007199254740991'; // Float-safe maximum for downstream Woo logic.

    /** No implicit exchange-rate provider. Identity works for well-formed codes. */
    public static function pair_definition( $source, $store ) {
        $source = self::unit( $source );
        $store = self::unit( $store );
        if ( '' === $source || '' === $store ) {
            return array( 'supported' => false, 'code' => 'invalid_unit', 'exponent' => null );
        }
        if ( $source === $store ) {
            return array( 'supported' => true, 'code' => 'identity', 'exponent' => 0 );
        }
        // Explicit fixed IRR/IRT relation: 1 IRT = 10 IRR, not a market FX rate.
        if ( 'IRT' === $source && 'IRR' === $store ) {
            return array( 'supported' => true, 'code' => 'fixed_irt_to_irr', 'exponent' => 1 );
        }
        if ( 'IRR' === $source && 'IRT' === $store ) {
            return array( 'supported' => true, 'code' => 'fixed_irr_to_irt', 'exponent' => -1 );
        }
        return array( 'supported' => false, 'code' => 'unsupported_pair', 'exponent' => null );
    }

    private static function unit( $value ) {
        if ( ! is_scalar( $value ) || is_bool( $value ) ) { return ''; }
        $value = strtoupper( trim( (string) $value ) );
        if ( in_array( $value, array( 'TOMAN', 'TOMANS' ), true ) ) { return 'IRT'; }
        return preg_match( '/^[A-Z0-9_]{2,16}$/D', $value ) ? $value : '';
    }

    /**
     * Fail-closed exact conversion. $source_digits comes from signed Backend
     * contract; $target_digits is a store-side precision capability, not a rate.
     *
     * Output: ['ok'=>bool,'code'=>string,'amount'=>string|null,'policy'=>string].
     * When the conversion would require rounding, it is REJECTED. A rounding
     * policy, if ever added, must be reviewed per price/shipping/order path.
     */
    public static function convert_exact( $amount, $source, $store, $source_digits, $target_digits ) {
        $pair = self::pair_definition( $source, $store );
        $policy = (string) $pair['code'];
        if ( ! $pair['supported'] ) { return self::result( $policy, $policy ); }
        if ( ! self::precision( $source_digits ) || ! self::precision( $target_digits ) ) {
            return self::result( 'invalid_precision', $policy );
        }
        if ( ! is_scalar( $amount ) || is_bool( $amount ) ) { return self::result( 'invalid_amount', $policy ); }
        $raw = trim( (string) $amount );
        // Numeric grouping, negatives, exponent notation and locale guesswork are forbidden.
        if ( ! preg_match( '/^[+]?[0-9]+(?:\.[0-9]+)?$/D', $raw ) ) {
            return self::result( 'invalid_amount', $policy );
        }
        $raw = ltrim( $raw, '+' );
        $parts = explode( '.', $raw, 2 );
        $whole = ltrim( $parts[0], '0' );
        if ( '' === $whole ) { $whole = '0'; }
        $fraction = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';
        if ( strlen( $fraction ) > (int) $source_digits ) {
            return self::result( 'source_fraction_exceeded', $policy );
        }
        // Shift the DECIMAL POINT using strings; never multiply float by 10.
        $all_digits = ltrim( $whole . $fraction, '0' );
        if ( '' === $all_digits ) { $all_digits = '0'; }
        $exponent = (int) $pair['exponent'];
        $new_scale = strlen( $fraction ) - $exponent;
        if ( $new_scale <= 0 ) {
            $out_whole = $all_digits . str_repeat( '0', -$new_scale );
            $out_fraction = '';
        } else {
            $all_digits = str_pad( $all_digits, $new_scale + 1, '0', STR_PAD_LEFT );
            $out_whole = substr( $all_digits, 0, -$new_scale );
            $out_fraction = rtrim( substr( $all_digits, -$new_scale ), '0' );
        }
        $out_whole = ltrim( $out_whole, '0' );
        if ( '' === $out_whole ) { $out_whole = '0'; }
        if ( strlen( $out_fraction ) > (int) $target_digits ) {
            return self::result( 'target_fraction_exceeded', $policy );
        }
        if ( strlen( $out_whole ) > strlen( self::SAFE_MAX_MAJOR ) ||
            ( strlen( $out_whole ) === strlen( self::SAFE_MAX_MAJOR ) && strcmp( $out_whole, self::SAFE_MAX_MAJOR ) > 0 ) ) {
            return self::result( 'amount_out_of_safe_range', $policy );
        }
        return array(
            'ok' => true, 'code' => 'ok', 'amount' => $out_whole . ( '' !== $out_fraction ? '.' . $out_fraction : '' ),
            'policy' => $policy,
        );
    }

    private static function precision( $value ) {
        return ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^[0-9]+$/D', $value ) ) )
            && (int) $value >= 0 && (int) $value <= 6;
    }

    private static function result( $code, $policy ) {
        return array( 'ok' => false, 'code' => $code, 'amount' => null, 'policy' => $policy );
    }
}
