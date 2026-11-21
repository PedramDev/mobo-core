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
    /** Exact conversion only (staged preview / legacy behavior). */
    const ROUNDING_EXACT = 'exact';
    /** C2: ceiling to WooCommerce precision; store price never below Source cost. */
    const ROUNDING_UP = 'up';

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
     * @param string $rounding ROUNDING_EXACT (reject inexact) or ROUNDING_UP.
     * @return array Canonical strings or a non-destructive rejection.
     */
    public static function prepare( $price, $compare, $owner_id, $row, $woo_unit, $preference, $woo_digits, $rounding = self::ROUNDING_EXACT ) {
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
        $round_up = self::ROUNDING_UP === $rounding;
        /* C2: an IRR Source price that is not a multiple of 10 used to be rejected
         * in a Toman store (1,234,565 IRR -> 123,456.5 IRT with 0 decimals), which
         * kept the previous (stale) storefront price. Price inputs now round UP to
         * the store precision: 123,456.5 -> 123,457 IRT (never below Source cost). */
        $converted = $round_up
            ? Mobo_Core_Money_Converter::convert_rounded( $price, $src_unit, $target, $src_digits, $woo_digits, 'up' )
            : Mobo_Core_Money_Converter::convert_exact( $price, $src_unit, $target, $src_digits, $woo_digits );
        if ( empty( $converted['ok'] ) ) {
            return self::rejected( 'price_' . (string) $converted['code'] );
        }
        $compare_converted = null;
        $compare_rounded = false;
        if ( null !== $compare && '' !== $compare ) {
            $second = $round_up
                ? Mobo_Core_Money_Converter::convert_rounded( $compare, $src_unit, $target, $src_digits, $woo_digits, 'up' )
                : Mobo_Core_Money_Converter::convert_exact( $compare, $src_unit, $target, $src_digits, $woo_digits );
            if ( empty( $second['ok'] ) ) {
                return self::rejected( 'compare_' . (string) $second['code'] );
            }
            $compare_converted = (string) $second['amount'];
            $compare_rounded = ! empty( $second['rounded'] );
        }
        /* C3: the calculator keeps decimal precision (no intval truncation), so a
         * fractional store amount within WooCommerce precision is valid input. The
         * strict exact mode keeps its historical rejection for staged previews. */
        if ( ! $round_up && ( false !== strpos( (string) $converted['amount'], '.' ) ||
            ( null !== $compare_converted && false !== strpos( $compare_converted, '.' ) ) ) ) {
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
            'rounded' => ! empty( $converted['rounded'] ) || $compare_rounded,
            'runtimeEnabled' => false,
        );
    }
}
