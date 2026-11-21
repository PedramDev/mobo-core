<?php
/**
 * CP20 - isolated, non-activating provider order replay preflight.
 *
 * Produces a canonical, connection-scoped purchase INTENT fingerprint and a
 * deterministic key for a future durable outbox. It NEVER authorizes a remote
 * purchase. Only a DB-backed atomic claim, a provider idempotency contract,
 * provider-authoritative quote and independent reconciliation can do that.
 *
 * Do not wire this into Checkout, wallet or Auto Submit as a send decision.
 * Compatible with PHP 7.4.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Mobo_Core_Order_Commit_Preflight {
    private static function reject( $reason ) {
        return array( 'status' => $reason, 'purchase_allowed' => false, 'provider_calls_permitted' => 0 );
    }
    private static function identifier( $value ) {
        return is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 192
            && 1 === preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x1f\x7f]/', $value );
    }
    private static function amount( $value ) {
        return is_string( $value ) && strlen( $value ) <= 40
            && 1 === preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/D', $value );
    }
    private static function quantity( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,11}$/D', $value );
    }
    private static function canonical_attributes( $attributes ) {
        if ( ! is_array( $attributes ) || count( $attributes ) < 1 || count( $attributes ) > 32 ) { return null; }
        $out = array();
        foreach ( $attributes as $name => $value ) {
            if ( ! self::identifier( $name ) || ! self::identifier( $value ) || strlen( $value ) > 512 ) { return null; }
            $out[ $name ] = $value;
        }
        ksort( $out, SORT_STRING );
        return $out;
    }
    /**
     * Returns immutable evidence to persist, never a send permission.
     * $secret must be a server-held stable secret, not one received from client.
     * A generation increment is a NEW intent and requires separate durable proof
     * that the previous generation can no longer have a provider effect.
     */
    public static function freeze( $order_id, $connection_id, $generation, $items, $secret ) {
        if ( ! is_int( $order_id ) || $order_id < 1 || ! self::identifier( $connection_id )
            || ! is_int( $generation ) || $generation < 1 || ! is_string( $secret )
            || strlen( $secret ) < 32 || ! is_array( $items ) || count( $items ) < 1
            || count( $items ) > 200 ) { return self::reject( 'INVALID_INTENT' ); }
        $rows = array();
        $money_unit = null;
        foreach ( $items as $line ) {
            if ( ! is_array( $line ) || count( $line ) !== 6
                || array_diff( array_keys( $line ), array( 'product_id', 'variant_id', 'quantity', 'unit_price', 'money_unit', 'attributes' ) )
                || ! isset( $line['product_id'], $line['variant_id'], $line['quantity'], $line['unit_price'], $line['money_unit'], $line['attributes'] )
                || ! self::identifier( $line['product_id'] ) || ! self::identifier( $line['variant_id'] )
                || ! self::quantity( $line['quantity'] ) || ! self::amount( $line['unit_price'] )
                || ! in_array( $line['money_unit'], array( 'IRT', 'IRR' ), true ) ) {
                return self::reject( 'INVALID_LINE' );
            }
            if ( null !== $money_unit && $money_unit !== $line['money_unit'] ) {
                return self::reject( 'MIXED_MONEY_UNIT' );
            }
            $money_unit = $line['money_unit'];
            $attrs = self::canonical_attributes( $line['attributes'] );
            if ( null === $attrs ) { return self::reject( 'INVALID_ATTRIBUTES' ); }
            $rows[] = array( 'product_id' => $line['product_id'], 'variant_id' => $line['variant_id'],
                'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'],
                'money_unit' => $money_unit, 'attributes' => $attrs );
        }
        $encoded_rows = array();
        foreach ( $rows as $row ) {
            $j = json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            if ( false === $j ) { return self::reject( 'INVALID_ENCODING' ); }
            $encoded_rows[] = $j;
        }
        // Reordered cart lines are the same intent. Duplicate lines are retained.
        sort( $encoded_rows, SORT_STRING );
        $scope = json_encode( array( 'v' => 1, 'order_id' => $order_id, 'connection_id' => $connection_id,
            'generation' => $generation ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $payload = json_encode( array( 'scope' => $scope, 'lines' => $encoded_rows ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $scope || false === $payload ) { return self::reject( 'INVALID_ENCODING' ); }
        return array( 'status' => 'FROZEN_NOT_AUTHORIZED', 'purchase_allowed' => false,
            'provider_calls_permitted' => 0, 'order_id' => $order_id,
            'connection_id' => $connection_id, 'generation' => $generation,
            'payload_fingerprint' => hash( 'sha256', $payload ),
            // Domain-separate and include generation; never use the secret in diagnostics.
            'idempotency_key' => 'mobo1_' . hash_hmac( 'sha256', "mobo-provider-order-v1\0" . $scope, $secret ) );
    }
    /** Pure diagnostic against one durable outbox record (no DB writes). */
    public static function inspect( $frozen, $existing ) {
        if ( ! is_array( $frozen ) || ( $frozen['status'] ?? '' ) !== 'FROZEN_NOT_AUTHORIZED'
            || ! isset( $frozen['idempotency_key'], $frozen['payload_fingerprint'], $frozen['order_id'], $frozen['connection_id'], $frozen['generation'] ) ) {
            return self::reject( 'INVALID_INTENT' );
        }
        if ( null === $existing ) { return self::reject( 'ATOMIC_OUTBOX_CLAIM_REQUIRED' ); }
        if ( ! is_array( $existing ) || count( $existing ) !== 6
            || array_diff( array_keys( $existing ), array( 'idempotency_key', 'payload_fingerprint', 'order_id', 'connection_id', 'generation', 'state' ) ) ) {
            return self::reject( 'INVALID_OUTBOX_RECORD' );
        }
        foreach ( array( 'idempotency_key', 'payload_fingerprint', 'order_id', 'connection_id', 'generation' ) as $field ) {
            if ( ! array_key_exists( $field, $existing ) || $existing[ $field ] !== $frozen[ $field ] ) {
                return self::reject( 'SCOPE_OR_PAYLOAD_CHANGED_MANUAL_REVIEW' );
            }
        }
        $states = array( 'PREPARED' => 'ATOMIC_DISPATCH_RECORD_REQUIRED',
            'DISPATCH_RECORDED' => 'UNCERTAIN_RECONCILE_ONLY',
            'UNCERTAIN' => 'UNCERTAIN_RECONCILE_ONLY',
            'PROVIDER_CONFIRMED' => 'ALREADY_CONFIRMED_NO_RESEND',
            'RECONCILED_FOUND' => 'ALREADY_CONFIRMED_NO_RESEND',
            'RECONCILED_NOT_FOUND' => 'MANUAL_REVIEW_NO_AUTOMATIC_RESEND',
            'CANCELLED' => 'CANCELLED_NO_RESEND' );
        if ( ! isset( $existing['state'] ) || ! is_string( $existing['state'] )
            || ! isset( $states[ $existing['state'] ] ) ) { return self::reject( 'INVALID_OUTBOX_STATE' ); }
        return self::reject( $states[ $existing['state'] ] );
    }
}
