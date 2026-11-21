<?php
/**
 * CP28: non-activating audit of money-unit boundaries for an order candidate.
 * It does not infer Provider/Wallet contracts, total-payment semantics, or
 * permission to checkout, debit wallet, dispatch, or retry.
 * Only canonical exact decimal strings are accepted. PHP 7.4+.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Mobo_Core_Order_Money_Boundary_Audit {
    private static function result( $status ) {
        return array( 'status' => $status, 'purchase_allowed' => false,
            'provider_calls_permitted' => 0, 'wallet_calls_permitted' => 0,
            'money_contract_only' => true );
    }
    /**
     * Required explicit components (a zero is still a declared amount):
     * product, shipping, discount, tax, wallet. `wallet` here means only a
     * hypothetical store-side money component, NEVER verified Provider debit.
     * A component with origin=source requires source_amount + store_amount.
     * A component with origin=store requires store_amount only.
     */
    public static function audit( $source_unit, $store_unit, $source_digits,
                                  $store_digits, $order_unit, $components ) {
        if ( ! class_exists( 'Mobo_Core_Money_Converter' )
            || ! in_array( $source_unit, array( 'IRT', 'IRR' ), true )
            || ! in_array( $store_unit, array( 'IRT', 'IRR' ), true )
            || ! is_int( $source_digits ) || $source_digits < 0 || $source_digits > 6
            || ! is_int( $store_digits ) || $store_digits < 0 || $store_digits > 6
            || ! is_string( $order_unit ) || ! in_array( $order_unit, array( 'IRT', 'IRR' ), true ) ) {
            return self::result( 'INVALID_MONEY_CONTEXT' );
        }
        if ( $order_unit !== $store_unit ) {
            return self::result( 'ORDER_CURRENCY_MISMATCH' );
        }
        $required = array( 'product', 'shipping', 'discount', 'tax', 'wallet' );
        if ( ! is_array( $components ) || count( $components ) !== count( $required )
            || array_diff( array_keys( $components ), $required )
            || array_diff( $required, array_keys( $components ) ) ) {
            return self::result( 'INCOMPLETE_COMPONENTS' );
        }
        foreach ( $required as $key ) {
            $component = $components[ $key ];
            if ( ! is_array( $component ) || ! isset( $component['origin'], $component['store_amount'] )
                || ! is_string( $component['origin'] ) || ! is_string( $component['store_amount'] ) ) {
                return self::result( 'INVALID_COMPONENT' );
            }
            $origin = $component['origin'];
            if ( 'source' === $origin ) {
                if ( count( $component ) !== 3 || ! array_key_exists( 'source_amount', $component )
                    || ! is_string( $component['source_amount'] ) ) {
                    return self::result( 'SOURCE_EVIDENCE_MISSING' );
                }
                $canonical_source = Mobo_Core_Money_Converter::convert_exact(
                    $component['source_amount'], $source_unit, $source_unit, $source_digits, $source_digits );
                if ( empty( $canonical_source['ok'] ) || $canonical_source['amount'] !== $component['source_amount'] ) {
                    return self::result( 'SOURCE_AMOUNT_NONCANONICAL' );
                }
                $expected = Mobo_Core_Money_Converter::convert_exact(
                    $component['source_amount'], $source_unit, $store_unit, $source_digits, $store_digits );
                if ( empty( $expected['ok'] ) ) {
                    return self::result( 'CONVERSION_UNVERIFIED' );
                }
            } elseif ( 'store' === $origin ) {
                if ( count( $component ) !== 2 ) {
                    return self::result( 'UNEXPECTED_SOURCE_AMOUNT' );
                }
                $expected = Mobo_Core_Money_Converter::convert_exact(
                    $component['store_amount'], $store_unit, $store_unit, $store_digits, $store_digits );
                if ( empty( $expected['ok'] ) ) {
                    return self::result( 'STORE_AMOUNT_INVALID' );
                }
            } else {
                return self::result( 'UNKNOWN_COMPONENT_ORIGIN' );
            }
            if ( $expected['amount'] !== $component['store_amount'] ) {
                return self::result( 'STORE_AMOUNT_OR_CONVERSION_MISMATCH' );
            }
        }
        return self::result( 'MONEY_COMPONENTS_MATCHED_NOT_AUTHORIZED' );
    }
}
