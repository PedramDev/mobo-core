<?php
/**
 * CP29: read-only exact Woo order arithmetic preflight, NOT supplier settlement.
 *
 * Woo line totals are AFTER coupons; subtracting the coupon again would double
 * discount. No Provider quote, wallet debit or settlement semantics are inferred.
 * No WordPress hooks, network requests, persistent writes, floats or rounding.
 * PHP 7.4+ / 64 bit. Large unsupported values fail closed, not rounded.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Mobo_Core_Woo_Order_Total_Audit {
    private static function result( $status ) {
        return array( 'status' => $status, 'purchase_allowed' => false,
            'provider_calls_permitted' => 0, 'wallet_calls_permitted' => 0,
            'woocommerce_arithmetic_only' => true, 'provider_quote_verified' => false,
            'provider_wallet_unit_verified' => false );
    }

    /**
     * Converts a strict canonical decimal STRING to exact minor units.
     * Major part bounded to 11 digits to preclude PHP int overflow even for
     * sums involving all components at the maximum supported 6-digit scale.
     */
    private static function minor_units( $amount, $scale, $allow_negative = false ) {
        if ( ! is_string( $amount ) || '' === $amount
            || ! preg_match( '/^-?(?:0|[1-9][0-9]{0,10})(?:\.[0-9]+)?$/D', $amount ) ) {
            return null;
        }
        $negative = '-' === $amount[0];
        if ( $negative && ! $allow_negative ) { return null; }
        $raw = $negative ? substr( $amount, 1 ) : $amount;
        $parts = explode( '.', $raw, 2 );
        $fraction = isset( $parts[1] ) ? $parts[1] : '';
        if ( strlen( $fraction ) > $scale || ( '' !== $fraction && '0' === substr( $fraction, -1 ) ) ) {
            return null;
        }
        if ( $negative && 0 === (int) $parts[0] && ( '' === $fraction || 0 === (int) $fraction ) ) {
            return null;
        }
        $multiplier = 10 ** $scale;
        $minor = ( (int) $parts[0] ) * $multiplier
            + ( '' === $fraction ? 0 : (int) str_pad( $fraction, $scale, '0', STR_PAD_RIGHT ) );
        return $negative ? -$minor : $minor;
    }

    /**
     * Explicit financial fields from a Woo business snapshot (major units):
     * lineSubtotal: pre-coupon subtotal; lineTotal: post-coupon net lines;
     * discountTotal: coupon discount already reflected in lineTotal;
     * shippingTotal and feeTotal: net amounts; totalTax: all tax, including fees;
     * orderTotal: total charged on Woo; refundedTotal: already refunded.
     *
     * This verifies candidate arithmetic only. It must never be used as a
     * quote, a Wallet amount, a stock decision, or permission to dispatch.
     */
    public static function audit( $store_unit, $order_unit, $store_digits, $snapshot ) {
        if ( ! is_string( $store_unit ) || ! is_string( $order_unit )
            || ! in_array( $store_unit, array( 'IRT', 'IRR' ), true )
            || ! in_array( $order_unit, array( 'IRT', 'IRR' ), true )
            || ! is_int( $store_digits ) || $store_digits < 0 || $store_digits > 6 ) {
            return self::result( 'INVALID_MONEY_CONTEXT' );
        }
        if ( $order_unit !== $store_unit ) {
            return self::result( 'ORDER_CURRENCY_MISMATCH' );
        }
        $required = array( 'lineSubtotal', 'lineTotal', 'shippingTotal', 'feeTotal',
            'totalTax', 'discountTotal', 'orderTotal', 'refundedTotal' );
        if ( ! is_array( $snapshot ) || count( $snapshot ) !== count( $required )
            || array_diff( array_keys( $snapshot ), $required )
            || array_diff( $required, array_keys( $snapshot ) ) ) {
            return self::result( 'INCOMPLETE_WOO_SNAPSHOT' );
        }
        $minor = array();
        foreach ( $required as $name ) {
            $minor[ $name ] = self::minor_units( $snapshot[ $name ], $store_digits, 'feeTotal' === $name );
            if ( null === $minor[ $name ] ) {
                return self::result( 'INVALID_' . strtoupper( preg_replace( '/([a-z])([A-Z])/', '$1_$2', $name ) ) );
            }
        }
        if ( $minor['refundedTotal'] !== 0 ) {
            return self::result( 'REFUND_REQUIRES_MANUAL_REVIEW' );
        }
        if ( $minor['lineSubtotal'] < $minor['lineTotal']
            || $minor['lineSubtotal'] - $minor['lineTotal'] !== $minor['discountTotal'] ) {
            return self::result( 'DISCOUNT_RECONCILIATION_MISMATCH' );
        }
        // Woo lineTotal is POST coupon; never subtract discountTotal twice.
        $expected = $minor['lineTotal'] + $minor['shippingTotal'] + $minor['feeTotal'] + $minor['totalTax'];
        if ( $expected < 0 || $expected !== $minor['orderTotal'] ) {
            return self::result( 'ORDER_TOTAL_MISMATCH' );
        }
        return self::result( 'WOO_TOTAL_RECONCILED_NOT_AUTHORIZED' );
    }
}
