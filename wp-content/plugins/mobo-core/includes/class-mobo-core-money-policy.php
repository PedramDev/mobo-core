<?php
/**
 * Connection-owned monetary contract.
 *
 * Prices from a Source are meaningful only together with their unit and scale.
 * The signed .NET provisioning contract owns those semantics per Connection.
 * Runtime price mutation is fail-closed unless the Source contract is complete
 * and the WooCommerce store uses the same monetary unit.
 *
 * No FX or IRR<->IRT conversion is performed here. A future conversion feature
 * must be a separate audited subsystem with an explicit rate/time/rounding
 * contract; silently multiplying or dividing prices is forbidden.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Money_Policy {
	const CONTRACT_VERSION = 1;
	const REPRESENTATION_MAJOR = 'major';
	const UNIT_IRT = 'IRT';
	const UNIT_IRR = 'IRR';

	public static function normalize_unit( $value ) {
		$unit = strtoupper( trim( (string) $value ) );
		if ( in_array( $unit, array( 'TOMAN', 'TOMANS' ), true ) ) {
			return self::UNIT_IRT;
		}
		if ( ! preg_match( '/^[A-Z0-9_]{2,16}$/D', $unit ) ) {
			return '';
		}
		return $unit;
	}

	public static function normalize_representation( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return self::REPRESENTATION_MAJOR === $value ? $value : '';
	}

	public static function normalize_fraction_digits( $value ) {
		if ( is_bool( $value ) || ! is_numeric( $value ) ) {
			return -1;
		}
		$raw = trim( (string) $value );
		if ( ! preg_match( '/^[0-9]+$/D', $raw ) ) {
			return -1;
		}
		$digits = (int) $raw;
		return $digits >= 0 && $digits <= 6 ? $digits : -1;
	}

	public static function normalize_contract_version( $value ) {
		if ( is_bool( $value ) || ! is_numeric( $value ) ) {
			return 0;
		}
		$raw = trim( (string) $value );
		return preg_match( '/^[0-9]+$/D', $raw ) ? max( 0, (int) $raw ) : 0;
	}

	public static function connection_row_has_contract( $row ) {
		if ( ! is_array( $row ) ) { return false; }
		return '' !== self::normalize_unit( $row['money_unit'] ?? '' )
			&& self::normalize_fraction_digits( $row['money_fraction_digits'] ?? -1 ) >= 0
			&& self::REPRESENTATION_MAJOR === self::normalize_representation( $row['money_price_representation'] ?? '' )
			&& self::normalize_contract_version( $row['money_contract_version'] ?? 0 ) >= self::CONTRACT_VERSION;
	}

	public static function contract_from_row( $row ) {
		$row = is_array( $row ) ? $row : array();
		return array(
			'unit'                => self::normalize_unit( $row['money_unit'] ?? '' ),
			'fractionDigits'      => self::normalize_fraction_digits( $row['money_fraction_digits'] ?? -1 ),
			'priceRepresentation' => self::normalize_representation( $row['money_price_representation'] ?? '' ),
			'contractVersion'     => self::normalize_contract_version( $row['money_contract_version'] ?? 0 ),
		);
	}

	public static function get_source_contract( $connection_id = 0, $fresh = false ) {
		$row = null;
		$id = absint( $connection_id );
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			if ( $id > 0 ) {
				$row = $fresh && method_exists( 'Mobo_Core_Connection_Repository', 'get_by_id_fresh' )
					? Mobo_Core_Connection_Repository::get_by_id_fresh( $id )
					: Mobo_Core_Connection_Repository::get_by_id( $id );
			} else {
				$row = Mobo_Core_Connection_Repository::get_runtime_connection();
			}
		}
		return self::contract_from_row( $row );
	}

	public static function store_unit() {
		$raw = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		return self::normalize_unit( $raw );
	}

	public static function compatibility_status( $connection_id = 0, $fresh = false ) {
		$contract = self::get_source_contract( $connection_id, $fresh );
		$store_unit = self::store_unit();
		$status = array(
			'compatible'          => false,
			'code'                => 'money_contract_missing',
			'sourceUnit'          => (string) $contract['unit'],
			'storeUnit'           => $store_unit,
			'fractionDigits'      => (int) $contract['fractionDigits'],
			'priceRepresentation' => (string) $contract['priceRepresentation'],
			'contractVersion'     => (int) $contract['contractVersion'],
			'requiresReprovision' => false,
			'message'             => '',
		);

		if ( (int) $contract['contractVersion'] < self::CONTRACT_VERSION
			|| '' === (string) $contract['unit']
			|| (int) $contract['fractionDigits'] < 0
			|| self::REPRESENTATION_MAJOR !== (string) $contract['priceRepresentation'] ) {
			$status['requiresReprovision'] = true;
			$status['message'] = 'Source monetary contract is missing or unsupported; signed Connection provisioning must refresh it.';
			return $status;
		}
		if ( '' === $store_unit ) {
			$status['code'] = 'store_money_unit_unknown';
			$status['message'] = 'WooCommerce store currency is unavailable or unsupported.';
			return $status;
		}
		if ( $store_unit !== (string) $contract['unit'] ) {
			$status['code'] = 'money_unit_mismatch';
			$status['message'] = sprintf( 'Source monetary unit %s does not match WooCommerce store unit %s.', (string) $contract['unit'], $store_unit );
			return $status;
		}
		$status['compatible'] = true;
		$status['code'] = 'compatible';
		$status['message'] = 'Source and WooCommerce monetary units are compatible.';
		return $status;
	}

	public static function compatibility_status_for_row( $row ) {
		$contract = self::contract_from_row( $row );
		$store_unit = self::store_unit();
		$status = array(
			'compatible' => false,
			'code' => 'money_contract_missing',
			'sourceUnit' => (string) $contract['unit'],
			'storeUnit' => $store_unit,
			'fractionDigits' => (int) $contract['fractionDigits'],
			'priceRepresentation' => (string) $contract['priceRepresentation'],
			'contractVersion' => (int) $contract['contractVersion'],
			'requiresReprovision' => false,
			'message' => '',
		);
		if ( ! self::connection_row_has_contract( $row ) ) {
			$status['requiresReprovision'] = true;
			$status['message'] = 'Source monetary contract is missing or unsupported.';
			return $status;
		}
		if ( '' === $store_unit ) {
			$status['code'] = 'store_money_unit_unknown';
			$status['message'] = 'WooCommerce store currency is unavailable or unsupported.';
			return $status;
		}
		if ( $store_unit !== (string) $contract['unit'] ) {
			$status['code'] = 'money_unit_mismatch';
			$status['message'] = sprintf( 'Source monetary unit %s does not match WooCommerce store unit %s.', (string) $contract['unit'], $store_unit );
			return $status;
		}
		$status['compatible'] = true;
		$status['code'] = 'compatible';
		$status['message'] = 'Source and WooCommerce monetary units are compatible.';
		return $status;
	}

	public static function assert_compatible( $connection_id = 0, $fresh = false ) {
		$status = self::compatibility_status( $connection_id, $fresh );
		if ( ! empty( $status['compatible'] ) ) { return true; }
		$code = sanitize_key( (string) ( $status['code'] ?? 'money_contract_incompatible' ) );
		return new WP_Error(
			'mobo_core_' . $code,
			sanitize_text_field( (string) ( $status['message'] ?? 'Mobo monetary contract is incompatible.' ) ),
			$status
		);
	}

	public static function normalize_source_number( $value ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) { return null; }
		$raw = trim( (string) $value );
		if ( '' === $raw ) { return null; }
		$canonical = str_replace( array( "\xC2\xA0", ' ' ), '', $raw );
		if ( 1 === preg_match( '/^[+]?[0-9]+(?:\.[0-9]+)?$/D', $canonical ) ) {
			$normalized = ltrim( $canonical, '+' );
		} elseif ( 1 === preg_match( '/^[+]?[0-9]+(?:,[0-9]+)?$/D', $canonical ) ) {
			$normalized = str_replace( ',', '.', ltrim( $canonical, '+' ) );
		} else {
			return null;
		}
		if ( function_exists( 'wc_format_decimal' ) ) {
			$normalized = wc_format_decimal( $normalized, false );
		}
		if ( '' === (string) $normalized || ! is_numeric( $normalized ) ) { return null; }
		$number = (float) $normalized;
		return is_finite( $number ) && $number >= 0 && $number <= PHP_INT_MAX ? $number : null;
	}

	private static function raw_fraction_digits( $value ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) { return PHP_INT_MAX; }
		$raw = str_replace( array( "\xC2\xA0", ' ' ), '', trim( (string) $value ) );
		$raw = ltrim( $raw, '+' );
		if ( ! preg_match( '/^[0-9]+(?:[\.,]([0-9]+))?$/D', $raw, $m ) ) { return PHP_INT_MAX; }
		return isset( $m[1] ) ? strlen( rtrim( $m[1], '0' ) ) : 0;
	}

	/**
	 * Opt-in store pricing currency bridge. Only Product Sync, Reprice and Repair
	 * may call this path. It deliberately does NOT relax assert_compatible(),
	 * which continues to protect Checkout, purchase, shipping and wallet.
	 *
	 * No implicit activation on upgrades: the administrator must explicitly save
	 * a destination preference, even when the selected value is "store".
	 */
	public static function pricing_conversion_context( $connection_id = 0 ) {
		$id = absint( $connection_id );
		if ( 0 === $id && class_exists( 'Mobo_Core_Connection_Context' ) ) {
			$id = absint( Mobo_Core_Connection_Context::get_effective_connection_id() );
		}
		if ( $id <= 0 || ! class_exists( 'Mobo_Core_Connection_Repository' )
			|| ! class_exists( 'Mobo_Core_Money_Target_Settings' )
			|| ! class_exists( 'Mobo_Core_Money_Converter' ) ) {
			return new WP_Error( 'mobo_core_money_price_connection_missing', 'Source Connection for money conversion is unavailable.' );
		}
		$row = Mobo_Core_Connection_Repository::get_by_id( $id );
		if ( ! is_array( $row ) || absint( $row['id'] ?? 0 ) !== $id
			|| 1 !== absint( $row['is_enabled'] ?? 0 ) || ! self::connection_row_has_contract( $row ) ) {
			return new WP_Error( 'mobo_core_money_price_contract_invalid', 'Source Connection monetary contract is invalid or disabled.' );
		}
		// Distinguish unsaved default from an explicit administrator choice.
		$chosen = get_option( Mobo_Core_Money_Target_Settings::OPTION, null );
		/* C1: the catalog was priced in the Source unit and the store currency was
		 * changed afterwards. Without this, every later Sync/Reprice failed closed
		 * forever and the (now 10x wrong) prices could never be corrected. While a
		 * detected post-sync currency change is pending, follow the WooCommerce
		 * currency so the Reprice the admin is asked to run actually fixes prices. */
		if ( null === $chosen && class_exists( 'Mobo_Core_Store_Currency_Guard' ) && Mobo_Core_Store_Currency_Guard::implicit_follow_store_allowed() ) {
			$chosen = Mobo_Core_Money_Target_Settings::FOLLOW_WOO;
		}
		if ( null === $chosen || ! Mobo_Core_Money_Target_Settings::valid_preference( $chosen ) ) {
			return new WP_Error( 'mobo_core_money_price_conversion_not_enabled', 'Currency conversion has not been explicitly selected.' );
		}
		$contract = self::contract_from_row( $row );
		$store = self::store_unit();
		$preview = Mobo_Core_Money_Target_Settings::preview( $contract['unit'], $store, $chosen );
		if ( empty( $preview['ready'] ) ) {
			return new WP_Error( 'mobo_core_money_price_target_invalid', 'Selected destination currency is incompatible with WooCommerce or Source.' );
		}
		$digits = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : -1;
		if ( $digits < 0 || $digits > 6 ) {
			return new WP_Error( 'mobo_core_money_price_precision_invalid', 'WooCommerce price precision is unsupported.' );
		}
		return array( 'id' => $id, 'row' => $row, 'store' => $store,
			'preference' => $chosen, 'digits' => $digits );
	}

	/** Only pricing entry points may opt in to this compatibility check. */
	public static function assert_pricing_compatible( $connection_id = 0 ) {
		$strict = self::assert_compatible( $connection_id );
		if ( ! is_wp_error( $strict ) ) { return true; }
		if ( 'mobo_core_money_unit_mismatch' !== $strict->get_error_code() ) { return $strict; }
		$context = self::pricing_conversion_context( $connection_id );
		return is_wp_error( $context ) ? $context : true;
	}

	public static function validate_source_amount( $value, $connection_id = 0, $pricing_only = false ) {
		if ( null === $value || '' === $value ) { return true; }
		$compat = $pricing_only ? self::assert_pricing_compatible( $connection_id ) : self::assert_compatible( $connection_id );
		if ( is_wp_error( $compat ) ) { return $compat; }
		$number = self::normalize_source_number( $value );
		if ( null === $number ) {
			return new WP_Error( 'mobo_core_money_amount_invalid', 'Source monetary amount is malformed.' );
		}
		$contract = self::get_source_contract( $connection_id );
		$digits = max( 0, (int) $contract['fractionDigits'] );
		if ( self::raw_fraction_digits( $value ) > $digits ) {
			return new WP_Error( 'mobo_core_money_fraction_invalid', 'Source monetary amount exceeds the provisioned fraction precision.' );
		}
		return true;
	}

	public static function is_valid_source_amount( $value ) {
		if ( null === $value || '' === $value ) { return true; }
		return null !== self::normalize_source_number( $value );
	}

	public static function get_mobo_api_price( $variation_id, $product_id ) {
		$ids = array_values( array_unique( array_filter( array( absint( $variation_id ), absint( $product_id ) ) ) ) );
		foreach ( $ids as $id ) {
			$value = get_post_meta( $id, 'mobo_api_price', true );
			$number = self::normalize_source_number( $value );
			if ( null !== $number ) {
				return (float) $number;
			}
		}
		return null;
	}

	/** Shipping never resolves a missing owner through a default Connection. */
	private static function shipping_owner_id( $connection_id ) {
		$id = absint( $connection_id );
		if ( $id <= 0 && class_exists( 'Mobo_Core_Connection_Context' ) ) {
			$id = absint( Mobo_Core_Connection_Context::get_effective_connection_id() );
		}
		if ( $id <= 0 || ! class_exists( 'Mobo_Core_Connection_Repository' ) ) { return 0; }
		$row = Mobo_Core_Connection_Repository::get_by_id( $id );
		return is_array( $row ) && absint( $row['id'] ?? 0 ) === $id
			&& 1 === absint( $row['is_enabled'] ?? 0 ) && self::connection_row_has_contract( $row ) ? $id : 0;
	}

	/**
	 * Shipping-only Source -> Store bridge. Source shipping tariffs and API prices
	 * are governed by the signed Connection contract. An explicitly saved Store
	 * destination is required when units differ. This function deliberately does
	 * NOT authorize a Provider purchase or Wallet payment in a different unit.
	 * Invalid precision and non-exact conversions return null (fail closed).
	 */
	public static function shipping_source_to_store( $amount, $connection_id = 0 ) {
		$connection_id = self::shipping_owner_id( $connection_id );
		if ( $connection_id <= 0 ) { return null; }
		if ( ! is_wp_error( self::assert_compatible( $connection_id ) ) ) {
			// Never use the runtime/default owner's contract for an explicit Shipping Connection.
			if ( ! class_exists( 'Mobo_Core_Money_Converter' ) ) { return null; }
			$contract = self::get_source_contract( $connection_id );
			$digits = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : -1;
			$result = Mobo_Core_Money_Converter::convert_exact(
				$amount, $contract['unit'], self::store_unit(), (int) $contract['fractionDigits'], $digits
			);
			return ! empty( $result['ok'] ) ? (float) $result['amount'] : null;
		}
		$context = self::pricing_conversion_context( $connection_id );
		if ( is_wp_error( $context ) || ! class_exists( 'Mobo_Core_Money_Converter' ) ) { return null; }
		$contract = self::contract_from_row( $context['row'] );
		$result = Mobo_Core_Money_Converter::convert_exact(
			$amount, $contract['unit'], $context['store'], (int) $contract['fractionDigits'], (int) $context['digits']
		);
		return ! empty( $result['ok'] ) ? (float) $result['amount'] : null;
	}

	/**
	 * Shipping rule bounds are expressed in SOURCE major units. Fallback Woo
	 * line totals are in STORE major units: convert once, never apply a markup.
	 * Inverse conversion cannot round; an unrepresentable subtotal is rejected.
	 */
	public static function shipping_store_to_source( $amount, $connection_id = 0 ) {
		$connection_id = self::shipping_owner_id( $connection_id );
		if ( $connection_id <= 0 ) { return null; }
		if ( ! is_wp_error( self::assert_compatible( $connection_id ) ) ) {
			if ( ! class_exists( 'Mobo_Core_Money_Converter' ) ) { return null; }
			$contract = self::get_source_contract( $connection_id );
			$digits = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : -1;
			$result = Mobo_Core_Money_Converter::convert_exact(
				$amount, self::store_unit(), $contract['unit'], $digits, (int) $contract['fractionDigits']
			);
			return ! empty( $result['ok'] ) ? (float) $result['amount'] : null;
		}
		$context = self::pricing_conversion_context( $connection_id );
		if ( is_wp_error( $context ) || ! class_exists( 'Mobo_Core_Money_Converter' ) ) { return null; }
		$contract = self::contract_from_row( $context['row'] );
		$result = Mobo_Core_Money_Converter::convert_exact(
			$amount, $context['store'], $contract['unit'], (int) $context['digits'], (int) $contract['fractionDigits']
		);
		return ! empty( $result['ok'] ) ? (float) $result['amount'] : null;
	}

	/** Shipping visibility never overrides the stricter purchase-money gate. */
	public static function shipping_currency_ready( $connection_id = 0 ) {
		$connection_id = self::shipping_owner_id( $connection_id );
		if ( $connection_id <= 0 ) { return false; }
		if ( ! is_wp_error( self::assert_compatible( $connection_id ) ) ) { return true; }
		return ! is_wp_error( self::pricing_conversion_context( $connection_id ) );
	}

	public static function source_amount_to_store_amount( $amount ) {
		$compat = self::assert_compatible();
		if ( is_wp_error( $compat ) ) { return null; }
		$valid = self::validate_source_amount( $amount );
		if ( is_wp_error( $valid ) ) { return null; }
		$value = self::normalize_source_number( $amount );
		if ( null === $value ) { return null; }
		$contract = self::get_source_contract();
		return round( $value, max( 0, (int) $contract['fractionDigits'] ), PHP_ROUND_HALF_UP );
	}

	public static function store_amount_to_source_amount( $amount ) {
		$compat = self::assert_compatible();
		if ( is_wp_error( $compat ) || is_bool( $amount ) || ! is_numeric( $amount ) ) { return null; }
		$value = (float) $amount;
		if ( ! is_finite( $value ) || $value < 0 || $value > PHP_INT_MAX ) { return null; }
		$contract = self::get_source_contract();
		return round( $value, max( 0, (int) $contract['fractionDigits'] ), PHP_ROUND_HALF_UP );
	}
}
