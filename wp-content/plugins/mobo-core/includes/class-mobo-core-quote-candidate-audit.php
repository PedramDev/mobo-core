<?php
/**
 * CP19 experimental V2 quote echo audit, NOT an approval engine.
 * A matching untrusted JSON body cannot establish ownership, stock authority,
 * reservation, payment safety or Provider provenance. Never call this from
 * Checkout, Blocks, Wallet or Auto Submit as a purchase permission.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Mobo_Core_Quote_Candidate_Audit {
    private static function denied( $reason ) {
        return array( 'status' => $reason, 'purchase_allowed' => false );
    }
    public static function inspect( $request, $candidate, $now, $max_lifetime_seconds, $skew_seconds = 0 ) {
        $bad = self::denied( 'INVALID_NOT_AUTHORIZED' );
        if ( ! is_array( $request ) || ! is_array( $candidate ) || ! is_int( $now ) || $now < 1
            || ! is_int( $max_lifetime_seconds ) || $max_lifetime_seconds < 1 || $max_lifetime_seconds > 3600
            || ! is_int( $skew_seconds ) || $skew_seconds < 0 || $skew_seconds > 60 ) { return $bad; }
        if ( ! class_exists( 'Mobo_Core_API_Client' )
            || is_wp_error( Mobo_Core_API_Client::validate_purchase_eligibility_quote_request( $request ) ) ) { return $bad; }
        // Exact closed-world candidate schema. A forged 'trusted', 'eligible'
        // or any unknown extension is a hard rejection, never an authorization.
        $keys = array( 'proof_protocol_version', 'request_nonce', 'connection_id', 'product_id', 'variant_id',
            'quantity', 'attributes', 'money_unit', 'requested_unit_price', 'provider_unit_price',
            'available_quantity', 'source_revision', 'provider_quote_id', 'binding_generation',
            'checked_at_utc', 'expires_at_utc', 'provider_side_effect' );
        if ( count( $candidate ) !== count( $keys ) || array_diff( array_keys( $candidate ), $keys )
            || 2 !== ( $candidate['proof_protocol_version'] ?? null )
            || false !== ( $candidate['provider_side_effect'] ?? null ) ) { return $bad; }
        foreach ( array( 'request_nonce', 'connection_id', 'product_id', 'variant_id', 'quantity', 'money_unit' ) as $field ) {
            if ( ! isset( $candidate[$field] ) || ! is_string( $candidate[$field] )
                || ! hash_equals( $request[$field], $candidate[$field] ) ) { return $bad; }
        }
        if ( ! isset( $candidate['attributes'] ) || ! is_array( $candidate['attributes'] )
            || count( $candidate['attributes'] ) !== count( $request['attributes'] ) ) { return $bad; }
        foreach ( $request['attributes'] as $name => $value ) {
            if ( ! array_key_exists( $name, $candidate['attributes'] ) || $candidate['attributes'][$name] !== $value ) { return $bad; }
        }
        if ( ! isset( $candidate['requested_unit_price'] ) || ! is_string( $candidate['requested_unit_price'] )
            || ! hash_equals( $request['unit_price'], $candidate['requested_unit_price'] )
            || ! isset( $candidate['provider_unit_price'] ) || ! is_string( $candidate['provider_unit_price'] )
            || strlen( $candidate['provider_unit_price'] ) > 40
            || ! preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/D', $candidate['provider_unit_price'] )
            || ! isset( $candidate['available_quantity'] ) || ! is_string( $candidate['available_quantity'] )
            || ! preg_match( '/^(?:0|[1-9][0-9]{0,11})$/D', $candidate['available_quantity'] ) ) { return $bad; }
        foreach ( array( 'source_revision', 'provider_quote_id' ) as $field ) {
            if ( ! isset( $candidate[$field] ) || ! is_string( $candidate[$field] )
                || '' === trim( $candidate[$field] ) || strlen( $candidate[$field] ) > 192
                || ! preg_match( '//u', $candidate[$field] ) || preg_match( '/[\x00-\x1F\x7F]/', $candidate[$field] ) ) { return $bad; }
        }
        if ( ! isset( $candidate['binding_generation'], $candidate['checked_at_utc'], $candidate['expires_at_utc'] )
            || ! is_int( $candidate['binding_generation'] ) || $candidate['binding_generation'] < 1
            || ! is_int( $candidate['checked_at_utc'] ) || ! is_int( $candidate['expires_at_utc'] )
            || $candidate['checked_at_utc'] < 1 || $candidate['checked_at_utc'] > $now + $skew_seconds
            || $candidate['expires_at_utc'] <= $now
            || $candidate['expires_at_utc'] <= $candidate['checked_at_utc']
            || $candidate['expires_at_utc'] - $candidate['checked_at_utc'] > $max_lifetime_seconds ) { return $bad; }
        // Compare decimal-encoded whole quantities without integer overflow.
        $available = $candidate['available_quantity']; $requested = $request['quantity'];
        if ( strlen( $available ) < strlen( $requested )
            || ( strlen( $available ) === strlen( $requested ) && strcmp( $available, $requested ) < 0 ) ) {
            return self::denied( 'QUANTITY_INSUFFICIENT_NOT_AUTHORIZED' );
        }
        if ( $candidate['provider_unit_price'] !== $request['unit_price'] ) {
            return self::denied( 'PRICE_RECONFIRMATION_NOT_AUTHORIZED' );
        }
        // Request-bound is NOT provider-authenticated and must never be an
        // ELIGIBLE_VERIFIED state. All outcomes reject automatic purchase.
        return self::denied( 'REQUEST_BOUND_ONLY_NOT_AUTHORIZED' );
    }
}
