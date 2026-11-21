<?php
/**
 * CP30 — inert, pure-PHP comparison of a hypothetical supplier payable quote
 * with a candidate wallet-debit envelope. NOT an API response specification.
 *
 * The live Portal provider does not expose a proven money-unit-bound debit
 * amount or a provider-authoritative, reserved quote. Values received in this
 * method may be caller-supplied and NEVER establish authenticity, stock,
 * wallet funds, settlement, idempotency at Provider or checkout permission.
 * No WordPress hooks, IO, globals, writes, HTTP, float arithmetic or purchase.
 * PHP 7.4+; invalid or unsupported monetary inputs fail closed.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Mobo_Core_Supplier_Wallet_Candidate_Audit {
    private static function result( $status, $matched = false ) {
        return array(
            'status' => $status,
            'candidate_payable_matches_debit' => $matched,
            'retail_payable_equality_required' => false,
            'provider_quote_verified' => false,
            'wallet_debit_contract_verified' => false,
            'ownership_verified' => false,
            'stock_verified' => false,
            'provider_idempotency_verified' => false,
            'purchase_allowed' => false,
            'provider_calls_permitted' => 0,
            'wallet_calls_permitted' => 0,
        );
    }

    private static function fields( $value, $required ) {
        return is_array( $value ) && count( $value ) === count( $required )
            && ! array_diff( array_keys( $value ), $required )
            && ! array_diff( $required, array_keys( $value ) );
    }

    private static function opaque_id( $value ) {
        return is_string( $value ) && strlen( $value ) >= 1 && strlen( $value ) <= 128
            && preg_match( '//u', $value ) === 1
            && ! preg_match( '/[\x00-\x20\x7F]/', $value );
    }

    private static function hash_id( $value ) {
        return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/D', $value ) === 1;
    }

    private static function unit( $value ) {
        return is_string( $value ) && in_array( $value, array( 'IRT', 'IRR' ), true );
    }

    /** Exact, canonical decimal to bounded integer minor units. */
    private static function minor( $value, $digits ) {
        if ( ! is_int( $digits ) || $digits < 0 || $digits > 6 || ! is_string( $value )
            || ! preg_match( '/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]+)?$/D', $value ) ) {
            return null;
        }
        $parts = explode( '.', $value, 2 );
        $fraction = isset( $parts[1] ) ? $parts[1] : '';
        if ( strlen( $fraction ) > $digits || ( '' !== $fraction && substr( $fraction, -1 ) === '0' ) ) {
            return null;
        }
        return (int) $parts[0] * ( 10 ** $digits )
            + ( '' !== $fraction ? (int) str_pad( $fraction, $digits, '0', STR_PAD_RIGHT ) : 0 );
    }

    /**
     * Candidate-only shapes are internal test envelopes, not Provider DTOs.
     * $scope: connection_id, provider_order_id, fingerprint, attempt_key,
     *         retail_total, retail_unit, retail_digits.
     * $quote: connection_id, provider_order_id, fingerprint, attempt_key,
     *         quote_id, payable, unit, digits, issued_at, expires_at.
     * $debit: connection_id, provider_order_id, fingerprint, attempt_key,
     *         quote_id, amount, unit, digits.
     */
    public static function audit( $scope, $quote, $debit, $now ) {
        $scope_fields = array( 'connection_id','provider_order_id','fingerprint','attempt_key',
            'retail_total','retail_unit','retail_digits' );
        $quote_fields = array( 'connection_id','provider_order_id','fingerprint','attempt_key',
            'quote_id','payable','unit','digits','issued_at','expires_at' );
        $debit_fields = array( 'connection_id','provider_order_id','fingerprint','attempt_key',
            'quote_id','amount','unit','digits' );
        if ( ! self::fields( $scope, $scope_fields ) || ! is_int( $now ) || $now < 0
            || ! self::opaque_id( $scope['connection_id'] )
            || ! self::opaque_id( $scope['provider_order_id'] )
            || ! self::hash_id( $scope['fingerprint'] )
            || ! self::hash_id( $scope['attempt_key'] )
            || ! self::unit( $scope['retail_unit'] )
            || null === self::minor( $scope['retail_total'], $scope['retail_digits'] ) ) {
            return self::result( 'INVALID_SCOPE' );
        }
        if ( ! self::fields( $quote, $quote_fields ) ) {
            return self::result( 'UNSUPPORTED_QUOTE_ENVELOPE' );
        }
        if ( ! self::fields( $debit, $debit_fields ) ) {
            return self::result( 'UNSUPPORTED_DEBIT_ENVELOPE' );
        }
        foreach ( array( $quote, $debit ) as $candidate ) {
            if ( ! self::opaque_id( $candidate['connection_id'] )
                || ! self::opaque_id( $candidate['provider_order_id'] )
                || ! self::opaque_id( $candidate['quote_id'] )
                || ! self::hash_id( $candidate['fingerprint'] )
                || ! self::hash_id( $candidate['attempt_key'] ) ) {
                return self::result( 'INVALID_CANDIDATE_IDENTITY' );
            }
            if ( $candidate['connection_id'] !== $scope['connection_id']
                || $candidate['provider_order_id'] !== $scope['provider_order_id']
                || ! hash_equals( $scope['fingerprint'], $candidate['fingerprint'] )
                || ! hash_equals( $scope['attempt_key'], $candidate['attempt_key'] ) ) {
                return self::result( 'CANDIDATE_SCOPE_MISMATCH' );
            }
        }
        if ( $quote['quote_id'] !== $debit['quote_id'] ) {
            return self::result( 'QUOTE_REFERENCE_MISMATCH' );
        }
        if ( ! is_int( $quote['issued_at'] ) || ! is_int( $quote['expires_at'] )
            || $quote['issued_at'] < 0 || $quote['expires_at'] <= $quote['issued_at'] ) {
            return self::result( 'INVALID_QUOTE_WINDOW' );
        }
        if ( $quote['issued_at'] > $now ) { return self::result( 'QUOTE_IN_FUTURE' ); }
        if ( $quote['expires_at'] <= $now ) { return self::result( 'QUOTE_EXPIRED' ); }
        if ( ! self::unit( $quote['unit'] ) || ! self::unit( $debit['unit'] )
            || ! is_int( $quote['digits'] ) || ! is_int( $debit['digits'] )
            || $quote['digits'] < 0 || $quote['digits'] > 6
            || $debit['digits'] < 0 || $debit['digits'] > 6 ) {
            return self::result( 'INVALID_PROVIDER_MONEY_CONTEXT' );
        }
        if ( $quote['unit'] !== $debit['unit'] || $quote['digits'] !== $debit['digits'] ) {
            // No implicit cross-currency wallet conversion without a Provider contract.
            return self::result( 'WALLET_UNIT_OR_SCALE_MISMATCH' );
        }
        $payable = self::minor( $quote['payable'], $quote['digits'] );
        $debit_amount = self::minor( $debit['amount'], $debit['digits'] );
        if ( null === $payable || null === $debit_amount || $payable === 0 || $debit_amount === 0 ) {
            return self::result( 'INVALID_PROVIDER_AMOUNT' );
        }
        if ( $payable !== $debit_amount ) {
            return self::result( 'SUPPLIER_WALLET_AMOUNT_MISMATCH' );
        }
        // Deliberately do not compare retail_total with supplier payable.
        // Origin authenticity, stock, wallet contract and payment remain UNPROVEN.
        return self::result( 'CANDIDATE_AMOUNT_MATCH_NOT_AUTHORIZED', true );
    }
}
