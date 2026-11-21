<?php
/**
 * C75B-P4G-P2: Store-wide target currency preference, STAGED ONLY.
 *
 * The Source currency is provisioned and signed by Backend per Connection.
 * WooCommerce owns checkout currency; this setting cannot alter WooCommerce.
 * No conversion pipeline invokes this helper yet; V1 remains fail-closed.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Money_Target_Settings {
    const OPTION = 'mobo_core_money_target_preference';
    const FOLLOW_WOO = 'store';

    /** Audited destination choices. Extend alongside approved pair converters. */
    public static function choices() {
        return array( self::FOLLOW_WOO => 'ارز WooCommerce (خودکار)', 'IRR' => 'ریال (IRR)', 'IRT' => 'تومان (IRT)' );
    }

    /** Strict input validation: never silently correct an invalid posted preference. */
    public static function valid_preference( $value ) {
        return is_string( $value ) && array_key_exists( $value, self::choices() );
    }

    public static function preference() {
        $raw = class_exists( 'Mobo_Core_Settings' )
            ? Mobo_Core_Settings::get( self::OPTION, self::FOLLOW_WOO )
            : self::FOLLOW_WOO;
        return self::valid_preference( $raw ) ? $raw : '';
    }

    /** Pure, auditable snapshot: does not cause any WordPress option writes. */
    public static function preview( $source, $woo_currency, $preference ) {
        if ( ! self::valid_preference( $preference ) ) {
            return array( 'ready' => false, 'code' => 'invalid_preference', 'target' => '', 'runtimeEnabled' => false );
        }
        $woo_currency = class_exists( 'Mobo_Core_Money_Policy' )
            ? Mobo_Core_Money_Policy::normalize_unit( $woo_currency ) : '';
        $target = self::FOLLOW_WOO === $preference ? $woo_currency : $preference;
        if ( '' === $target || '' === $woo_currency ) {
            return array( 'ready' => false, 'code' => 'store_currency_unknown', 'target' => $target, 'runtimeEnabled' => false );
        }
        if ( $target !== $woo_currency ) {
            return array( 'ready' => false, 'code' => 'target_store_mismatch', 'target' => $target, 'runtimeEnabled' => false );
        }
        $pair = class_exists( 'Mobo_Core_Money_Converter' )
            ? Mobo_Core_Money_Converter::pair_definition( $source, $target )
            : array( 'supported' => false, 'code' => 'converter_unavailable' );
        if ( empty( $pair['supported'] ) ) {
            return array( 'ready' => false, 'code' => 'unsupported_pair', 'target' => $target, 'runtimeEnabled' => false );
        }
        // Readiness means only UI-level input compatibility, NOT permission to mutate prices.
        return array( 'ready' => true, 'code' => 'preview_ready_not_active', 'target' => $target, 'runtimeEnabled' => false );
    }
}
