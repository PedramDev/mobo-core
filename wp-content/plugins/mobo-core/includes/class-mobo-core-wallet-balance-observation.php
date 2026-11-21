<?php
/**
 * CP31: strictly bounded, display-only observation of Portal wallet balance.
 *
 * The provider's wallet balance endpoint supplies an amount without a
 * separately proven debit money unit. Never use this result as evidence for
 * a supplier payable, debit amount, quote, authorization or purchase.
 * No network/DB/WP hooks and no arithmetic used for settlement.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Mobo_Core_Wallet_Balance_Observation {
    private static function result( $status, $balance = null ) {
        return array(
            'status' => $status,
            'balance' => $balance,
            'money_unit_verified' => false,
            'debit_amount_verified' => false,
            'provider_quote_verified' => false,
            'purchase_allowed' => false,
            'wallet_debit_allowed' => false,
            'response_success_verified' => false,
        );
    }

    /**
     * CP34: inspect the actual JSON wire text before PHP converts a fractional
     * JSON number into a floating-point value. Only the top-level `amount`
     * property is represented as its original numeric token. Strings retain
     * JSON-decoded text. Duplicate security-relevant envelope keys fail closed.
     * This is strictly a display observation; no debit/quote authority.
     */
    public static function inspect_raw_response( $raw ) {
        if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 65536 ) {
            return self::result( 'INVALID_RAW_RESPONSE' );
        }
        $payload = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
            return self::result( 'INVALID_RESPONSE_ENVELOPE' );
        }
        $length = strlen( $raw );
        $offset = 0;
        self::skip_wire_whitespace( $raw, $offset, $length );
        if ( $offset >= $length || '{' !== $raw[$offset] ) {
            return self::result( 'INVALID_RESPONSE_ENVELOPE' );
        }
        ++$offset;
        $seen = array();
        $amount_token = null;
        while ( true ) {
            self::skip_wire_whitespace( $raw, $offset, $length );
            if ( $offset >= $length ) {
                return self::result( 'INVALID_RESPONSE_ENVELOPE' );
            }
            if ( '}' === $raw[$offset] ) {
                ++$offset;
                break;
            }
            $key_token = self::read_wire_string( $raw, $offset, $length );
            if ( null === $key_token ) {
                return self::result( 'INVALID_RESPONSE_ENVELOPE' );
            }
            $key = json_decode( $key_token );
            if ( ! is_string( $key ) ) {
                return self::result( 'INVALID_RESPONSE_ENVELOPE' );
            }
            // CP35: reject *all* duplicate top-level JSON property names,
            // including escaped aliases and non-financial metadata. PHP's
            // json_decode() silently keeps the last duplicate. Never let a
            // different consumer see a different representation of this wire.
            if ( array_key_exists( $key, $seen ) ) {
                return self::result( 'DUPLICATE_WIRE_FIELD' );
            }
            $seen[$key] = true;
            self::skip_wire_whitespace( $raw, $offset, $length );
            if ( $offset >= $length || ':' !== $raw[$offset] ) {
                return self::result( 'INVALID_RESPONSE_ENVELOPE' );
            }
            ++$offset;
            self::skip_wire_whitespace( $raw, $offset, $length );
            $start = $offset;
            $nested = 0;
            $inside_string = false;
            $escaped = false;
            for ( ; $offset < $length; ++$offset ) {
                $char = $raw[$offset];
                if ( $inside_string ) {
                    if ( $escaped ) { $escaped = false; }
                    elseif ( '\\' === $char ) { $escaped = true; }
                    elseif ( '"' === $char ) { $inside_string = false; }
                    continue;
                }
                if ( '"' === $char ) { $inside_string = true; continue; }
                if ( '{' === $char || '[' === $char ) { ++$nested; continue; }
                if ( '}' === $char || ']' === $char ) {
                    if ( 0 === $nested ) { break; }
                    --$nested;
                    continue;
                }
                if ( ',' === $char && 0 === $nested ) { break; }
            }
            $token = trim( substr( $raw, $start, $offset - $start ) );
            if ( '' === $token ) {
                return self::result( 'INVALID_RESPONSE_ENVELOPE' );
            }
            if ( 'amount' === $key ) {
                $amount_token = $token;
            }
            self::skip_wire_whitespace( $raw, $offset, $length );
            if ( $offset >= $length ) { return self::result( 'INVALID_RESPONSE_ENVELOPE' ); }
            if ( ',' === $raw[$offset] ) { ++$offset; continue; }
            if ( '}' === $raw[$offset] ) { ++$offset; break; }
            return self::result( 'INVALID_RESPONSE_ENVELOPE' );
        }
        self::skip_wire_whitespace( $raw, $offset, $length );
        if ( $offset !== $length ) {
            return self::result( 'INVALID_RESPONSE_ENVELOPE' );
        }
        if ( null !== $amount_token && preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $amount_token ) ) {
            // The wire token, not a lossy PHP float, is now the display candidate.
            $payload['amount'] = $amount_token;
        }
        return self::inspect_response( $payload );
    }

    private static function skip_wire_whitespace( $raw, &$offset, $length ) {
        while ( $offset < $length && ( ' ' === $raw[$offset] || "\n" === $raw[$offset] || "\r" === $raw[$offset] || "\t" === $raw[$offset] ) ) {
            ++$offset;
        }
    }

    private static function read_wire_string( $raw, &$offset, $length ) {
        if ( $offset >= $length || '"' !== $raw[$offset] ) { return null; }
        $start = $offset++;
        $escaped = false;
        for ( ; $offset < $length; ++$offset ) {
            $char = $raw[$offset];
            if ( $escaped ) { $escaped = false; continue; }
            if ( '\\' === $char ) { $escaped = true; continue; }
            if ( '"' === $char ) {
                ++$offset;
                return substr( $raw, $start, $offset - $start );
            }
        }
        return null;
    }

    /** Safe numeric *display value* only, not money arithmetic. */
    public static function inspect( $value ) {
        if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
            return self::result( 'INVALID_BALANCE_TYPE' );
        }
        // PHP is_numeric() accepts exponent notation and overflowing values.
        // JSON floats and PHP floats must be finite; reject all scientific form.
        if ( is_float( $value ) && ! is_finite( $value ) ) {
            return self::result( 'NONFINITE_BALANCE' );
        }
        $text = (string) $value;
        if ( ! preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $text ) ) {
            return self::result( 'INVALID_BALANCE_FORMAT' );
        }
        // At most 2^53-1: a PHP double can represent this integer exactly.
        // Money unit/scale remain *unverified*, regardless of this upper bound.
        $float = (float) $text;
        if ( ! is_finite( $float ) || abs( $float ) > 9007199254740991.0 ) {
            return self::result( 'BALANCE_OUT_OF_SAFE_RANGE' );
        }
        /* CP33: prevent silently replacing a Provider decimal with a rounded
         * PHP float. Amounts are display-only, but the displayed number must
         * still be faithful to the supplied decimal representation. For float
         * values json_decode() has already discarded original JSON spelling;
         * this comparison only certifies the in-memory value, NOT the wire
         * number, wallet currency, or payment precision. */
        $roundtrip = json_encode( $float, JSON_PRESERVE_ZERO_FRACTION );
        if ( ! is_string( $roundtrip ) || false !== stripos( $roundtrip, 'e' ) ) {
            return self::result( 'BALANCE_PRECISION_UNVERIFIED' );
        }
        $canonical = static function ( $number ) {
            $negative = 0 === strpos( $number, '-' );
            if ( $negative ) { $number = substr( $number, 1 ); }
            $parts = explode( '.', $number, 2 );
            $integer = ltrim( $parts[0], '0' );
            if ( '' === $integer ) { $integer = '0'; }
            $fraction = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';
            $normalized = $integer . ( '' === $fraction ? '' : '.' . $fraction );
            return $negative && '0' !== $normalized ? '-' . $normalized : $normalized;
        };
        if ( $canonical( $text ) !== $canonical( $roundtrip ) ) {
            return self::result( 'BALANCE_PRECISION_LOSS' );
        }
        if ( is_int( $value ) ) {
            return self::result( 'DISPLAY_ONLY_UNVERIFIED_UNIT', $value );
        }
        // Preserve integer balance shape where representable, without casting
        // a potentially overflowed float to PHP_INT_MIN.
        if ( floor( $float ) === $float ) {
            return self::result( 'DISPLAY_ONLY_UNVERIFIED_UNIT', (int) $float );
        }
        return self::result( 'DISPLAY_ONLY_UNVERIFIED_UNIT', $float );
    }
    /**
     * Validate the transport-decoded wallet response, without inferring its
     * debit currency, provider settlement semantics, or purchase authority.
     *
     * Some legacy Portal responses omit `success`; keep their historic
     * display-only behavior until real response samples settle the contract.
     * A PRESENT but malformed acknowledgement is never accepted, however.
     * Neither arrays nor objects are coerced to strings/booleans.
     */
    public static function inspect_response( $payload ) {
        if ( ! is_array( $payload ) ) {
            return self::result( 'INVALID_RESPONSE_ENVELOPE' );
        }
        $acknowledged = false;
        if ( array_key_exists( 'success', $payload ) ) {
            $value = $payload['success'];
            if ( is_bool( $value ) ) {
                $accepted = $value;
            } elseif ( is_int( $value ) && ( 0 === $value || 1 === $value ) ) {
                $accepted = ( 1 === $value );
            } elseif ( is_string( $value ) ) {
                $normalized = strtolower( trim( $value ) );
                if ( in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true ) ) {
                    $accepted = true;
                } elseif ( in_array( $normalized, array( '0', 'false', 'no', 'off' ), true ) ) {
                    $accepted = false;
                } else {
                    return self::result( 'INVALID_SUCCESS_ACKNOWLEDGEMENT' );
                }
            } else {
                return self::result( 'INVALID_SUCCESS_ACKNOWLEDGEMENT' );
            }
            if ( ! $accepted ) {
                return self::result( 'RESPONSE_NOT_SUCCESS' );
            }
            $acknowledged = true;
        }
        if ( ! array_key_exists( 'amount', $payload ) ) {
            return self::result( 'MISSING_AMOUNT' );
        }
        $observation = self::inspect( $payload['amount'] );
        $observation['response_success_verified'] = $acknowledged;
        return $observation;
    }

}
