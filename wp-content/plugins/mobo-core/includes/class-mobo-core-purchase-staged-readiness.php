<?php
/**
 * CP96-CP99: dormant four-boundary pre-commit diagnostic, PHP 7.4 compatible.
 * NO WordPress hooks, DB writes, Provider/Wallet calls or purchase permission.
 * Candidate structures (including any 'verified' field) are NEVER authority.
 * Missing Provider-signed ownership, live quote, wallet debit contract and
 * durable dispatch/reconcile proof intentionally keep release BLOCKED.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Mobo_Core_Purchase_Staged_Readiness {
    private static function deny( $status, $extra = array() ) {
        return array_merge( array( 'status' => $status, 'purchase_allowed' => false,
            'provider_calls_permitted' => 0, 'wallet_calls_permitted' => 0,
            'authenticated_provider_quote' => false, 'release_ready' => false ), $extra );
    }
    private static function identifier( $s ) {
        return is_string( $s ) && strlen( $s ) > 0 && strlen( $s ) <= 192 && trim( $s ) !== ''
            && 1 === preg_match( '//u', $s ) && ! preg_match( '/[\x00-\x1f\x7f]/', $s );
    }
    private static function exact_keys( $a, $keys ) {
        return is_array( $a ) && count( $a ) === count( $keys )
            && ! array_diff( array_keys( $a ), $keys ) && ! array_diff( $keys, array_keys( $a ) );
    }
    /** CP96: compare source/connection/variant against a candidate observation.
     * An observation is not a server-verified provisioning receipt. */
    public static function scope( $requested, $observed, $now ) {
        $request_keys = array( 'connection_id', 'product_id', 'variant_id', 'generation', 'quote_ref',
            'source_key', 'provider_key', 'woo_order_id', 'quantity' );
        $observation_keys = array( 'connection_id', 'product_id', 'variant_id', 'generation',
            'quote_ref', 'source_key', 'provider_key', 'valid_until' );
        if ( ! is_int( $now ) || $now < 1 || ! self::exact_keys( $requested, $request_keys )
            || ! self::exact_keys( $observed, $observation_keys ) ) {
            return self::deny( 'SCOPE_INPUT_INVALID' );
        }
        foreach ( array( 'connection_id', 'product_id', 'variant_id', 'quote_ref', 'source_key', 'provider_key' ) as $field ) {
            if ( ! self::identifier( $requested[ $field ] ) || ! self::identifier( $observed[ $field ] )
                || ! hash_equals( $requested[ $field ], $observed[ $field ] ) ) {
                return self::deny( 'SCOPE_IDENTITY_MISMATCH' );
            }
        }
        if ( ! is_int( $requested['woo_order_id'] ) || $requested['woo_order_id'] < 1
            || ! is_string( $requested['quantity'] ) || ! preg_match( '/^[1-9][0-9]{0,11}$/D', $requested['quantity'] )
            || ! is_int( $requested['generation'] ) || $requested['generation'] < 1
            || ! is_int( $observed['generation'] ) || $requested['generation'] !== $observed['generation']
            || ! is_int( $observed['valid_until'] ) || $observed['valid_until'] <= $now
            || ! self::identifier( $observed['source_key'] ) || ! self::identifier( $observed['provider_key'] ) ) {
            return self::deny( 'SCOPE_GENERATION_OR_TTL_UNVERIFIED' );
        }
        return self::deny( 'SCOPE_CANDIDATE_MATCH_NOT_AUTHORIZED', array( 'candidate_scope_match' => true ) );
    }
    /** CP97: candidate supplier payable and wallet debit with explicit money unit.
     * Reuse the existing strict amount auditor; NEVER compare supplier cost with retail price. */
    public static function money( $scope, $supplier_quote, $wallet_debit, $now ) {
        if ( ! class_exists( 'Mobo_Core_Supplier_Wallet_Candidate_Audit' ) ) {
            return self::deny( 'MONEY_AUDITOR_UNAVAILABLE' );
        }
        $audit = Mobo_Core_Supplier_Wallet_Candidate_Audit::audit( $scope, $supplier_quote, $wallet_debit, $now );
        if ( ! is_array( $audit ) || ! isset( $audit['status'] )
            || ! array_key_exists( 'purchase_allowed', $audit ) || false !== $audit['purchase_allowed'] ) {
            return self::deny( 'MONEY_AUDITOR_INVALID_RESPONSE' );
        }
        return self::deny( $audit['status'], array(
            'candidate_payable_matches_debit' => isset( $audit['candidate_payable_matches_debit'] )
                && true === $audit['candidate_payable_matches_debit'] ) );
    }
    /** CP98: recalculate actual Plugin preflight from the entire cart/order.
     * Exact connection + generation + order + SHA256 digest must match; no trusted flag. */
    public static function intent( $frozen, $order_id, $connection_id, $generation, $lines, $secret ) {
        if ( ! class_exists( 'Mobo_Core_Order_Commit_Preflight' ) || ! is_array( $frozen ) ) {
            return self::deny( 'INTENT_INPUT_INVALID' );
        }
        $generated = Mobo_Core_Order_Commit_Preflight::freeze(
            $order_id, $connection_id, $generation, $lines, $secret );
        if ( ! isset( $generated['status'] ) || 'FROZEN_NOT_AUTHORIZED' !== $generated['status']
            || ! isset( $frozen['status'] ) || 'FROZEN_NOT_AUTHORIZED' !== $frozen['status'] ) {
            return self::deny( 'INTENT_INVALID' );
        }
        foreach ( array( 'order_id', 'connection_id', 'generation', 'payload_fingerprint', 'idempotency_key' ) as $key ) {
            if ( ! array_key_exists( $key, $frozen ) || ! array_key_exists( $key, $generated )
                || ( is_string( $generated[ $key ] )
                    ? ! is_string( $frozen[ $key ] ) || ! hash_equals( $generated[ $key ], $frozen[ $key ] )
                    : $generated[ $key ] !== $frozen[ $key ] ) ) {
                return self::deny( 'INTENT_FINGERPRINT_OR_SCOPE_CHANGED' );
            }
        }
        if ( ! isset( $frozen['purchase_allowed'], $frozen['provider_calls_permitted'] )
            || false !== $frozen['purchase_allowed'] || 0 !== $frozen['provider_calls_permitted'] ) {
            return self::deny( 'INTENT_FORGED_PERMISSION' );
        }
        return self::deny( 'INTENT_FINGERPRINT_MATCH_NOT_AUTHORIZED', array( 'candidate_intent_match' => true ) );
    }
    /** CP99: observe persisted outbox scope/state through existing read-only preflight.
     * No creation of tables, no DB write, no CAS dispatch, no Provider call. */
    public static function outbox( $frozen, $stored ) {
        if ( ! class_exists( 'Mobo_Core_Order_Commit_Preflight' ) ) {
            return self::deny( 'OUTBOX_AUDITOR_UNAVAILABLE' );
        }
        $result = Mobo_Core_Order_Commit_Preflight::inspect( $frozen, $stored );
        if ( ! is_array( $result ) || ! isset( $result['status'], $result['purchase_allowed'], $result['provider_calls_permitted'] )
            || false !== $result['purchase_allowed'] || 0 !== $result['provider_calls_permitted'] ) {
            return self::deny( 'OUTBOX_AUDITOR_INVALID_RESPONSE' );
        }
        return self::deny( $result['status'] );
    }
    /** Four phases together; a green candidate comparison is NEVER a GO signal. */
    public static function examine( $scope_request, $scope_observed, $financial_scope,
        $supplier_quote, $wallet_debit, $frozen, $order_id, $connection_id,
        $generation, $lines, $secret, $outbox_record, $now ) {
        $scope = self::scope( $scope_request, $scope_observed, $now );
        $money = self::money( $financial_scope, $supplier_quote, $wallet_debit, $now );
        $intent = self::intent( $frozen, $order_id, $connection_id, $generation, $lines, $secret );
        $outbox = self::outbox( $frozen, $outbox_record );
        $cross = is_array( $scope_request ) && is_array( $financial_scope ) && is_array( $supplier_quote )
            && is_array( $frozen ) && isset( $scope_request['connection_id'], $scope_request['generation'], $scope_request['quote_ref'],
                $financial_scope['connection_id'], $supplier_quote['connection_id'], $supplier_quote['quote_id'], $frozen['connection_id'], $frozen['generation'] )
            && is_string( $scope_request['connection_id'] ) && is_string( $financial_scope['connection_id'] )
            && is_string( $supplier_quote['connection_id'] ) && is_string( $supplier_quote['quote_id'] )
            && is_string( $frozen['connection_id'] ) && $scope_request['connection_id'] === $financial_scope['connection_id']
            && $scope_request['connection_id'] === $supplier_quote['connection_id']
            && $scope_request['connection_id'] === $frozen['connection_id']
            && $scope_request['generation'] === $frozen['generation']
            && $scope_request['quote_ref'] === $supplier_quote['quote_id']
            && isset( $scope_request['woo_order_id'], $scope_request['quantity'],
                $financial_scope['fingerprint'], $financial_scope['attempt_key'],
                $frozen['order_id'], $frozen['payload_fingerprint'], $frozen['idempotency_key'] )
            && is_int( $scope_request['woo_order_id'] ) && $scope_request['woo_order_id'] === $frozen['order_id']
            && is_string( $financial_scope['fingerprint'] ) && is_string( $frozen['payload_fingerprint'] )
            && hash_equals( $frozen['payload_fingerprint'], $financial_scope['fingerprint'] )
            && is_string( $financial_scope['attempt_key'] ) && is_string( $frozen['idempotency_key'] )
            && hash_equals( substr( $frozen['idempotency_key'], 6 ), $financial_scope['attempt_key'] )
            && is_array( $lines ) && count( $lines ) === 1 && is_array( $lines[0] )
            && isset( $lines[0]['product_id'], $lines[0]['variant_id'], $lines[0]['quantity'] )
            && is_string( $lines[0]['product_id'] ) && is_string( $lines[0]['variant_id'] )
            && is_string( $lines[0]['quantity'] ) && is_string( $scope_request['quantity'] )
            && $scope_request['product_id'] === $lines[0]['product_id']
            && $scope_request['variant_id'] === $lines[0]['variant_id']
            && $scope_request['quantity'] === $lines[0]['quantity'];
        $candidate = $cross && 'SCOPE_CANDIDATE_MATCH_NOT_AUTHORIZED' === $scope['status']
            && 'CANDIDATE_AMOUNT_MATCH_NOT_AUTHORIZED' === $money['status']
            && 'INTENT_FINGERPRINT_MATCH_NOT_AUTHORIZED' === $intent['status']
            && 'ATOMIC_DISPATCH_RECORD_REQUIRED' === $outbox['status'];
        return self::deny( $candidate ? 'FOUR_CANDIDATES_MATCH_RELEASE_BLOCKED' : 'BOUNDARY_REVIEW_REQUIRED', array(
            'cross_boundary_candidate_match' => $cross, 'candidate_all_four_match' => $candidate,
            'stages' => array( 'CP96_scope' => $scope, 'CP97_money' => $money,
                'CP98_intent' => $intent, 'CP99_outbox' => $outbox ),
            'blockers' => array( 'PROVIDER_AUTHORITATIVE_QUOTE_MISSING', 'SERVER_OWNED_PROVISIONING_UNPROVEN',
                'WALLET_DEBIT_CONTRACT_UNVERIFIED', 'ATOMIC_DISPATCH_AND_RECONCILIATION_UNPROVEN' ) ) );
    }
}
