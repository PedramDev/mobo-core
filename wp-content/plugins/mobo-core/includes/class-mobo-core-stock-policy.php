<?php
/**
 * Pure stock decisions for Mobo-managed products and variations.
 *
 * Owner rules:
 * - Source stock 0 (or a negative balance) is ALWAYS out of stock. Backorders are
 *   forced to 'no' on every Mobo-managed object, so WooCommerce can never turn a
 *   zero quantity into 'onbackorder' (B13).
 * - Unknown stock (field absent or JSON null) never makes a NEW product/variation
 *   purchasable without limit: it is created out of stock until the Source sends a
 *   numeric quantity. An EXISTING object keeps its last known stock state (B16).
 * - An object the Source hides/deactivates is persisted out of stock in the database
 *   (manage_stock=no, stock_status=outofstock) so feeds, lookup tables and REST see the
 *   same state as the storefront. The natural (Source) state is remembered separately
 *   and restored when the Source publishes the object again (B14).
 *
 * A state is array( 'manage' => bool, 'qty' => int|null, 'status' => string ).
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Stock_Policy {

	const SOURCE_NUMERIC     = 'api-stock';
	const SOURCE_UNKNOWN_NEW = 'api-unknown-stock-new';
	const SOURCE_UNKNOWN_KEPT = 'api-unknown-stock-kept';

	/**
	 * Normalize an arbitrary state array.
	 *
	 * @param mixed $state State.
	 * @return array
	 */
	public static function normalize_state( $state ) {
		$state  = is_array( $state ) ? $state : array();
		$manage = ! empty( $state['manage'] );
		$qty    = isset( $state['qty'] ) && is_numeric( $state['qty'] ) ? (int) $state['qty'] : null;
		$status = isset( $state['status'] ) ? (string) $state['status'] : '';
		if ( ! in_array( $status, array( 'instock', 'outofstock' ), true ) ) {
			/* 'onbackorder' (or anything unknown) is never a valid Mobo state. */
			$status = 'outofstock';
		}
		if ( $manage ) {
			if ( null === $qty ) {
				$qty = 0;
			}
			$status = $qty > 0 ? 'instock' : 'outofstock';
		} else {
			$qty = null;
		}
		return array(
			'manage' => $manage,
			'qty'    => $qty,
			'status' => $status,
		);
	}

	/**
	 * Natural (Source-driven) state for a Source stock value.
	 *
	 * @param int|null $quantity Normalized Source quantity; null = unknown.
	 * @param bool     $has_known_state Whether Mobo already applied a stock state to the object.
	 * @param array    $current Current natural state of the object.
	 * @return array State plus 'source' diagnostic code.
	 */
	public static function natural_state( $quantity, $has_known_state, $current ) {
		if ( null !== $quantity ) {
			$quantity = max( 0, (int) $quantity );
			return array(
				'manage' => true,
				'qty'    => $quantity,
				'status' => $quantity > 0 ? 'instock' : 'outofstock',
				'source' => self::SOURCE_NUMERIC,
			);
		}

		if ( ! $has_known_state ) {
			return array(
				'manage' => false,
				'qty'    => null,
				'status' => 'outofstock',
				'source' => self::SOURCE_UNKNOWN_NEW,
			);
		}

		$kept           = self::normalize_state( $current );
		$kept['source'] = self::SOURCE_UNKNOWN_KEPT;
		return $kept;
	}

	/**
	 * State that is written to WooCommerce for a natural state.
	 *
	 * @param array $natural Natural state.
	 * @param bool  $blocked Whether the Source hides/deactivates the object.
	 * @return array
	 */
	public static function persisted_state( $natural, $blocked ) {
		if ( $blocked ) {
			return array(
				'manage' => false,
				'qty'    => null,
				'status' => 'outofstock',
			);
		}
		return self::normalize_state( $natural );
	}

	/**
	 * Whether the current WooCommerce stock props equal the desired persisted state.
	 *
	 * @param array  $current Current props: manage, qty, status, backorders.
	 * @param array  $desired Desired persisted state.
	 * @return bool
	 */
	public static function props_match( $current, $desired ) {
		$current = is_array( $current ) ? $current : array();
		$desired = self::normalize_state( $desired );
		$manage  = ! empty( $current['manage'] );
		$status  = isset( $current['status'] ) ? (string) $current['status'] : '';
		if ( $manage !== $desired['manage'] || $status !== $desired['status'] ) {
			return false;
		}
		if ( ! $desired['manage'] ) {
			return true;
		}
		$qty        = isset( $current['qty'] ) && is_numeric( $current['qty'] ) ? (int) $current['qty'] : null;
		$backorders = isset( $current['backorders'] ) ? (string) $current['backorders'] : 'no';
		return null !== $qty && $qty === $desired['qty'] && 'no' === $backorders;
	}
}
