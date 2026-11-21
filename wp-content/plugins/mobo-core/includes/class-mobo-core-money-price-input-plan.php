<?php
/**
 * C75B-P4G-P3: explicit Connection-owned, side-effect-free pricing INPUT plan.
 *
 * This is a STAGED preview primitive, NOT an authorization to modify Woo prices.
 * The caller must resolve the durable object-owner mapping independently, then
 * supply the owner ID and the corresponding signed-provisioned Connection row.
 * This helper checks their consistency, but does not itself query object maps.
 * Source fields stay untouched in mobo_api_price/mobo_api_compare_price.
 *
 * Fixed profits and dynamic price thresholds belong to the store currency.
 * The conversion must happen BEFORE those rules, not after their final result.
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Money_Price_Input_Plan {
    private static function rejected( $code ) {
        return array( 'ok' => false, 'code' => $code, 'price' => null,
            'comparePrice' => null, 'sourceUnit' => '', 'targetUnit' => '',
            'runtimeEnabled' => false );
    }

    private static function valid_id( $id ) {
        return ( is_int( $id ) || ( is_string( $id ) && preg_match( '/^[1-9][0-9]*$/D', $id ) ) )
            && (int) $id > 0 && (string) (int) $id === (string) $id;
    }

    /**
     * Prepare price inputs for a PRICING PREVIEW, without persisting anything.
     *
     * @param mixed $price Raw Source price (required; compare-only deltas are rejected).
     * @param mixed $compare Raw Source comparePrice, or null/empty.
     * @param mixed $owner_id Durable product/variation owner, NOT default Connection.
     * @param mixed $row Explicit provisioned Connection row (must match owner_id).
     * @param string $woo_unit Actual WooCommerce checkout currency.
     * @param string $preference Validated store target preference.
     * @param int $woo_digits WooCommerce store price decimal precision (0..6).
     * @return array Canonical strings or a non-destructive rejection.
     */
    public static function prepare( $price, $compare, $owner_id, $row, $woo_unit, $preference, $woo_digits ) {
        if ( ! self::valid_id( $owner_id ) || ! is_array( $row ) ||
            ! isset( $row['id'] ) || ! self::valid_id( $row['id'] ) || (int) $row['id'] !== (int) $owner_id ) {
            return self::rejected( 'owner_connection_mismatch' );
        }
        if ( ! isset( $row['is_enabled'] ) ||
            ! in_array( $row['is_enabled'], array( 1, '1', true ), true ) ) {
            return self::rejected( 'connection_not_enabled' );
        }
        if ( ! class_exists( 'Mobo_Core_Money_Policy' ) ||
            ! Mobo_Core_Money_Policy::connection_row_has_contract( $row ) ) {
            return self::rejected( 'source_contract_invalid' );
        }
        if ( ! class_exists( 'Mobo_Core_Money_Target_Settings' ) ||
            ! class_exists( 'Mobo_Core_Money_Converter' ) ) {
            return self::rejected( 'converter_unavailable' );
        }
        $contract = Mobo_Core_Money_Policy::contract_from_row( $row );
        $check = Mobo_Core_Money_Target_Settings::preview( $contract['unit'], $woo_unit, $preference );
        if ( empty( $check['ready'] ) ) {
            return self::rejected( (string) ( $check['code'] ?? 'target_incompatible' ) );
        }
        if ( ! is_int( $woo_digits ) || $woo_digits < 0 || $woo_digits > 6 ) {
            return self::rejected( 'invalid_store_precision' );
        }
        if ( null === $price || '' === $price ) {
            return self::rejected( 'source_price_missing' );
        }
        $target = (string) $check['target'];
        $src_unit = (string) $contract['unit'];
        $src_digits = (int) $contract['fractionDigits'];
        $converted = Mobo_Core_Money_Converter::convert_exact( $price, $src_unit, $target, $src_digits, $woo_digits );
        if ( empty( $converted['ok'] ) ) {
            return self::rejected( 'price_' . (string) $converted['code'] );
        }
        $compare_converted = null;
        if ( null !== $compare && '' !== $compare ) {
            $second = Mobo_Core_Money_Converter::convert_exact( $compare, $src_unit, $target, $src_digits, $woo_digits );
            if ( empty( $second['ok'] ) ) {
                return self::rejected( 'compare_' . (string) $second['code'] );
            }
            $compare_converted = (string) $second['amount'];
        }
        /* The current legacy calculator uses intval() for its base and dynamic
         * range checks. Never let a fractional store-price be silently truncated.
         * Decimal pricing needs a separate audited calculator migration. */
        if ( false !== strpos( (string) $converted['amount'], '.' ) ||
            ( null !== $compare_converted && false !== strpos( $compare_converted, '.' ) ) ) {
            return self::rejected( 'fractional_calculator_not_supported' );
        }
        return array(
            'ok' => true,
            'code' => 'preview_only',
            'price' => (string) $converted['amount'],
            'comparePrice' => $compare_converted,
            'sourceUnit' => $src_unit,
            'targetUnit' => $target,
            'ownerConnectionId' => (int) $owner_id,
            'conversionPolicy' => (string) $converted['policy'],
            'runtimeEnabled' => false,
        );
    }
}
