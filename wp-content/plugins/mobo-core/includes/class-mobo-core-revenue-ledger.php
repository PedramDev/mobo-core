<?php
/**
 * Immutable Mobo revenue ledger stored on WooCommerce orders.
 *
 * Source cost is frozen before/at successful Mobo submission. One immutable
 * financial record is created when the WooCommerce order reaches completed.
 * Mixed orders include only Mobo line items.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Revenue_Ledger {

	const LEDGER_VERSION = 1;

	const META_LEDGER_VERSION = '_mobo_revenue_ledger_version';
	const META_LEDGER_RECORD  = '_mobo_revenue_ledger_v1';
	const META_CALCULATED_AT  = '_mobo_revenue_calculated_at';

	const ITEM_META_SOURCE_UNIT_COST   = '_mobo_revenue_source_unit_cost';
	const ITEM_META_SOURCE_SNAPSHOT_AT = '_mobo_revenue_source_snapshot_at';

	const OPTION_REVISION = 'mobo_core_revenue_ledger_revision';
	const OPTION_CACHE    = 'mobo_core_revenue_summary_cache';

	/**
	 * Internal line-item metadata must remain stored for immutable revenue
	 * calculations, but it must never leak into order screens, customer order
	 * details, emails, or invoice plugins that use WooCommerce formatted meta.
	 *
	 * @return string[]
	 */
	public static function get_hidden_item_meta_keys() {
		return array(
			self::ITEM_META_SOURCE_UNIT_COST,
			self::ITEM_META_SOURCE_SNAPSHOT_AT,
			'_mobo_identity_captured',
			'_mobo_identity_is_mobo',
			'_mobo_identity_product_guid',
			'_mobo_identity_variant_guid',
			'_mobo_identity_portal_product_id',
			'_mobo_identity_portal_variant_id',
			'_mobo_identity_sku',
			'_mobo_identity_captured_at',
		);
	}

	/**
	 * Hide the revenue snapshot fields in WooCommerce's admin order-item meta UI.
	 * Raw metadata remains available to the ledger through WC_Order_Item::get_meta().
	 *
	 * @param string[] $hidden_meta Existing hidden item-meta keys.
	 * @return string[]
	 */
	public static function hide_internal_order_item_meta( $hidden_meta ) {
		$hidden_meta = is_array( $hidden_meta ) ? $hidden_meta : array();
		return array_values( array_unique( array_merge( $hidden_meta, self::get_hidden_item_meta_keys() ) ) );
	}

	/**
	 * Remove internal revenue fields from display-oriented formatted item meta.
	 * This covers storefront order details, emails, and invoice/PDF integrations
	 * that consume WC_Order_Item::get_formatted_meta_data().
	 *
	 * @param array         $formatted_meta Formatted WC_Meta_Data objects.
	 * @param WC_Order_Item $item           Order item being formatted.
	 * @return array
	 */
	public static function filter_internal_formatted_item_meta( $formatted_meta, $item = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WooCommerce filter signature.
		if ( ! is_array( $formatted_meta ) || empty( $formatted_meta ) ) {
			return $formatted_meta;
		}

		$hidden = array_fill_keys( self::get_hidden_item_meta_keys(), true );

		foreach ( $formatted_meta as $index => $meta ) {
			$key = '';
			if ( is_object( $meta ) && isset( $meta->key ) ) {
				$key = (string) $meta->key;
			} elseif ( is_array( $meta ) && isset( $meta['key'] ) ) {
				$key = (string) $meta['key'];
			}

			if ( '' !== $key && isset( $hidden[ $key ] ) ) {
				unset( $formatted_meta[ $index ] );
			}
		}

		return array_values( $formatted_meta );
	}

	public function capture_checkout_line_item_source_cost( $item, $cart_item_key, $values, $order ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$product = isset( $values['data'] ) && $values['data'] instanceof WC_Product
			? $values['data']
			: $item->get_product();

		if ( ! $product instanceof WC_Product || ! $this->is_mobo_product( $product ) ) {
			return;
		}

		$cost = $this->get_source_unit_cost(
			absint( $item->get_product_id() ),
			absint( $item->get_variation_id() ),
			$product
		);

		if ( null !== $cost ) {
			$this->persist_item_cost_snapshot( $item, $cost );
		}
	}

	public function handle_mobo_order_submission_success( $order_id, $mobo_order_id = 0, $payment_json = array() ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->snapshot_missing_source_costs( $order );

		if ( $order->has_status( 'completed' ) ) {
			$this->record_order_if_eligible( $order );
		}
	}

	public function handle_order_completed( $order_id, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( absint( $order_id ) );
		}

		if ( $order instanceof WC_Order ) {
			$this->record_order_if_eligible( $order );
		}
	}

	public function snapshot_missing_source_costs( $order ) {
		$result = array(
			'snapshotted' => 0,
			'missing'     => 0,
			'moboItems'   => 0,
		);

		if ( ! $order instanceof WC_Order ) {
			return $result;
		}

		$changed = false;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$identity_captured = 'yes' === (string) $item->get_meta( '_mobo_identity_captured', true );
			$is_mobo_item = $identity_captured
				? 'yes' === (string) $item->get_meta( '_mobo_identity_is_mobo', true )
				: ( $product instanceof WC_Product && $this->is_mobo_product( $product ) );
			if ( ! $is_mobo_item ) {
				continue;
			}

			$result['moboItems']++;

			if ( null !== $this->get_item_cost_snapshot( $item ) ) {
				continue;
			}

			if ( ! $product instanceof WC_Product ) {
				$result['missing']++;
				continue;
			}

			$cost = $this->get_source_unit_cost(
				absint( $item->get_product_id() ),
				absint( $item->get_variation_id() ),
				$product
			);

			if ( null === $cost ) {
				$result['missing']++;
				continue;
			}

			$this->persist_item_cost_snapshot( $item, $cost );
			$item_id = absint( $item->save() );
			$stored_cost = $item_id > 0 && function_exists( 'wc_get_order_item_meta' )
				? wc_get_order_item_meta( $item_id, self::ITEM_META_SOURCE_UNIT_COST, true )
				: '';
			$stored_at = $item_id > 0 && function_exists( 'wc_get_order_item_meta' )
				? wc_get_order_item_meta( $item_id, self::ITEM_META_SOURCE_SNAPSHOT_AT, true )
				: '';
			if ( $item_id <= 0 || '' === (string) $stored_cost || ! is_numeric( $stored_cost ) || absint( $stored_at ) <= 0 ) {
				$result['missing']++;
				continue;
			}
			$result['snapshotted']++;
			$changed = true;
		}

		if ( $changed ) {
			$order->save();
		}

		return $result;
	}

	public function record_order_if_eligible( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_revenue_invalid_order', 'Revenue ledger requires a valid WooCommerce order.' );
		}

		$existing = $order->get_meta( self::META_LEDGER_RECORD, true );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			return $existing;
		}

		if ( ! $order->has_status( 'completed' ) ) {
			return new WP_Error( 'mobo_core_revenue_order_not_completed', 'Revenue ledger is created only for completed WooCommerce orders.' );
		}

		if (
			'yes' !== (string) $order->get_meta( '_mobo_order_submitted', true )
			|| absint( $order->get_meta( '_mobo_order_id', true ) ) <= 0
		) {
			return new WP_Error( 'mobo_core_revenue_not_mobo_order', 'Order has no successful Mobo submission.' );
		}

		$order_id = absint( $order->get_id() );
		$lock = class_exists( 'Mobo_Core_Lock' )
			? Mobo_Core_Lock::acquire( 'revenue_order_' . $order_id, 30 )
			: false;

		if ( class_exists( 'Mobo_Core_Lock' ) && false === $lock ) {
			return new WP_Error( 'mobo_core_revenue_order_locked', 'Revenue calculation is already running for this order.' );
		}

		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return new WP_Error( 'mobo_core_revenue_order_missing', 'Order disappeared before revenue calculation.' );
			}

			$existing = $order->get_meta( self::META_LEDGER_RECORD, true );
			if ( is_array( $existing ) && ! empty( $existing ) ) {
				return $existing;
			}

			$snapshot = $this->snapshot_missing_source_costs( $order );
			if ( absint( $snapshot['moboItems'] ) <= 0 ) {
				return new WP_Error( 'mobo_core_revenue_no_mobo_items', 'Completed order contains no Mobo line item.' );
			}
			if ( absint( $snapshot['missing'] ) > 0 ) {
				return new WP_Error(
					'mobo_core_revenue_source_cost_missing',
					sprintf( 'Source cost snapshot is missing for %d Mobo order item(s).', absint( $snapshot['missing'] ) )
				);
			}

			$item_count = 0;
			$quantity = 0.0;
			$customer_sales = 0.0;
			$customer_tax = 0.0;
			$mobo_cost = 0.0;

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$product = $item->get_product();
				$identity_captured = 'yes' === (string) $item->get_meta( '_mobo_identity_captured', true );
				$is_mobo_item = $identity_captured
					? 'yes' === (string) $item->get_meta( '_mobo_identity_is_mobo', true )
					: ( $product instanceof WC_Product && $this->is_mobo_product( $product ) );
				if ( ! $is_mobo_item ) {
					continue;
				}

				$unit_cost = $this->get_item_cost_snapshot( $item );
				if ( null === $unit_cost ) {
					return new WP_Error( 'mobo_core_revenue_source_cost_missing', 'A Mobo line item has no immutable source cost snapshot.' );
				}

				$line_quantity = max( 0, (float) $item->get_quantity() );
				$item_count++;
				$quantity += $line_quantity;
				$customer_sales += (float) $item->get_total();
				$customer_tax += (float) $item->get_total_tax();
				$mobo_cost += $unit_cost * $line_quantity;
			}

			$gross_profit = $customer_sales - $mobo_cost;
			$margin = $customer_sales > 0 ? ( $gross_profit / $customer_sales ) * 100 : 0.0;
			$date_completed = $order->get_date_completed();
			$completed_at = $date_completed instanceof WC_DateTime ? $date_completed->getTimestamp() : time();
			$calculated_at = time();

			$record = array(
				'schemaVersion'        => self::LEDGER_VERSION,
				'orderId'              => $order_id,
				'moboOrderId'          => absint( $order->get_meta( '_mobo_order_id', true ) ),
				'currency'             => strtoupper( sanitize_text_field( (string) $order->get_currency() ) ),
				'itemCount'            => $item_count,
				'quantity'             => $this->round_number( $quantity ),
				'customerSales'        => $this->round_number( $customer_sales ),
				'customerTax'          => $this->round_number( $customer_tax ),
				'moboCost'             => $this->round_number( $mobo_cost ),
				'grossProfit'          => $this->round_number( $gross_profit ),
				'grossMarginPercent'   => $this->round_number( $margin ),
				'orderCompletedAt'     => gmdate( 'c', $completed_at ),
				'orderCompletedAtUnix' => $completed_at,
				'calculatedAt'         => gmdate( 'c', $calculated_at ),
				'calculatedAtUnix'     => $calculated_at,
				'costBasis'            => 'mobo-api-price-snapshot',
			);

			$order->update_meta_data( self::META_LEDGER_VERSION, (string) self::LEDGER_VERSION );
			$order->update_meta_data( self::META_LEDGER_RECORD, $record );
			$order->update_meta_data( self::META_CALCULATED_AT, $calculated_at );
			$saved_order_id = absint( $order->save() );
			if ( $saved_order_id !== $order_id ) {
				return new WP_Error( 'mobo_core_revenue_ledger_persist_failed', 'Revenue ledger could not be persisted on the WooCommerce order.' );
			}

			$fresh_order = wc_get_order( $order_id );
			$fresh_record = $fresh_order instanceof WC_Order ? $fresh_order->get_meta( self::META_LEDGER_RECORD, true ) : null;
			if ( ! $fresh_order instanceof WC_Order
				|| ! is_array( $fresh_record )
				|| absint( isset( $fresh_record['orderId'] ) ? $fresh_record['orderId'] : 0 ) !== $order_id
				|| absint( $fresh_order->get_meta( self::META_LEDGER_VERSION, true ) ) !== self::LEDGER_VERSION
				|| absint( $fresh_order->get_meta( self::META_CALCULATED_AT, true ) ) !== $calculated_at
			) {
				return new WP_Error( 'mobo_core_revenue_ledger_postcondition_failed', 'Revenue ledger save failed its durable post-write verification.' );
			}

			$this->invalidate_summary_cache();
			do_action( 'mobo_core_revenue_ledger_recorded', $order_id, $fresh_record );

			return $fresh_record;
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && false !== $lock ) {
				Mobo_Core_Lock::release( 'revenue_order_' . $order_id, $lock );
			}
		}
	}

	public function get_summary() {
		$revision = absint( get_option( self::OPTION_REVISION, 0 ) );
		$cache = get_option( self::OPTION_CACHE, array() );

		if (
			is_array( $cache )
			&& isset( $cache['revision'], $cache['summary'] )
			&& absint( $cache['revision'] ) === $revision
			&& is_array( $cache['summary'] )
		) {
			$summary = $cache['summary'];
		} else {
			$summary = $this->build_summary();
			update_option(
				self::OPTION_CACHE,
				array( 'revision' => $revision, 'summary' => $summary ),
				false
			);
		}

		$summary['generatedAt'] = gmdate( 'c' );
		$summary['pluginVersion'] = defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '';
		return $summary;
	}

	private function build_summary() {
		$all_time = array();
		$last_30 = array();
		$recent = array();
		$record_count = 0;
		$cutoff = time() - ( 30 * DAY_IN_SECONDS );
		$page = 1;
		$limit = 200;

		do {
			$ids = wc_get_orders(
				array(
					'type'       => 'shop_order',
					'limit'      => $limit,
					'page'       => $page,
					'return'     => 'ids',
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded/paged WooCommerce CRUD query keeps CPT and HPOS compatibility; direct SQL would be less portable.
						array(
							'key'     => self::META_LEDGER_VERSION,
							'value'   => (string) self::LEDGER_VERSION,
							'compare' => '=',
						),
					),
				)
			);

			if ( ! is_array( $ids ) || empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $order_id ) {
				$order = wc_get_order( absint( $order_id ) );
				if ( ! $order instanceof WC_Order ) {
					continue;
				}

				$record = $order->get_meta( self::META_LEDGER_RECORD, true );
				if ( ! is_array( $record ) || empty( $record ) ) {
					continue;
				}

				$record_count++;
				$this->aggregate_record( $all_time, $record );

				if ( absint( isset( $record['orderCompletedAtUnix'] ) ? $record['orderCompletedAtUnix'] : 0 ) >= $cutoff ) {
					$this->aggregate_record( $last_30, $record );
				}

				$recent[] = $record;
			}

			$page++;
		} while ( count( $ids ) === $limit );

		usort(
			$recent,
			function ( $a, $b ) {
				$a_ts = absint( isset( $a['calculatedAtUnix'] ) ? $a['calculatedAtUnix'] : 0 );
				$b_ts = absint( isset( $b['calculatedAtUnix'] ) ? $b['calculatedAtUnix'] : 0 );
				if ( $a_ts === $b_ts ) {
					return absint( isset( $b['orderId'] ) ? $b['orderId'] : 0 ) <=> absint( isset( $a['orderId'] ) ? $a['orderId'] : 0 );
				}
				return $b_ts <=> $a_ts;
			}
		);

		$public_recent = array();
		foreach ( array_slice( $recent, 0, 10 ) as $record ) {
			$public_recent[] = $this->public_recent_record( $record );
		}

		return array(
			'ledgerVersion' => self::LEDGER_VERSION,
			'recordCount'   => $record_count,
			'allTime'       => $this->finalize_aggregate( $all_time ),
			'last30Days'    => $this->finalize_aggregate( $last_30 ),
			'recent'        => $public_recent,
		);
	}

	private function aggregate_record( &$buckets, $record ) {
		$currency = isset( $record['currency'] ) && '' !== trim( (string) $record['currency'] )
			? strtoupper( sanitize_text_field( (string) $record['currency'] ) )
			: '-';

		if ( ! isset( $buckets[ $currency ] ) ) {
			$buckets[ $currency ] = array(
				'currency'      => $currency,
				'orderCount'    => 0,
				'itemCount'     => 0,
				'quantity'      => 0.0,
				'customerSales' => 0.0,
				'customerTax'   => 0.0,
				'moboCost'      => 0.0,
				'grossProfit'   => 0.0,
			);
		}

		$buckets[ $currency ]['orderCount']++;
		$buckets[ $currency ]['itemCount'] += absint( isset( $record['itemCount'] ) ? $record['itemCount'] : 0 );
		$buckets[ $currency ]['quantity'] += (float) ( isset( $record['quantity'] ) ? $record['quantity'] : 0 );
		$buckets[ $currency ]['customerSales'] += (float) ( isset( $record['customerSales'] ) ? $record['customerSales'] : 0 );
		$buckets[ $currency ]['customerTax'] += (float) ( isset( $record['customerTax'] ) ? $record['customerTax'] : 0 );
		$buckets[ $currency ]['moboCost'] += (float) ( isset( $record['moboCost'] ) ? $record['moboCost'] : 0 );
		$buckets[ $currency ]['grossProfit'] += (float) ( isset( $record['grossProfit'] ) ? $record['grossProfit'] : 0 );
	}

	private function finalize_aggregate( $buckets ) {
		$rows = array();
		foreach ( $buckets as $row ) {
			$sales = (float) $row['customerSales'];
			$profit = (float) $row['grossProfit'];
			$row['quantity'] = $this->round_number( $row['quantity'] );
			$row['customerSales'] = $this->round_number( $sales );
			$row['customerTax'] = $this->round_number( $row['customerTax'] );
			$row['moboCost'] = $this->round_number( $row['moboCost'] );
			$row['grossProfit'] = $this->round_number( $profit );
			$row['grossMarginPercent'] = $this->round_number( $sales > 0 ? ( $profit / $sales ) * 100 : 0 );
			$rows[] = $row;
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( (string) $a['currency'], (string) $b['currency'] );
			}
		);

		return array_values( $rows );
	}

	private function public_recent_record( $record ) {
		return array(
			'orderId'            => absint( isset( $record['orderId'] ) ? $record['orderId'] : 0 ),
			'moboOrderId'        => absint( isset( $record['moboOrderId'] ) ? $record['moboOrderId'] : 0 ),
			'currency'           => isset( $record['currency'] ) ? (string) $record['currency'] : '-',
			'itemCount'          => absint( isset( $record['itemCount'] ) ? $record['itemCount'] : 0 ),
			'quantity'           => (float) ( isset( $record['quantity'] ) ? $record['quantity'] : 0 ),
			'customerSales'      => (float) ( isset( $record['customerSales'] ) ? $record['customerSales'] : 0 ),
			'moboCost'           => (float) ( isset( $record['moboCost'] ) ? $record['moboCost'] : 0 ),
			'grossProfit'        => (float) ( isset( $record['grossProfit'] ) ? $record['grossProfit'] : 0 ),
			'grossMarginPercent' => (float) ( isset( $record['grossMarginPercent'] ) ? $record['grossMarginPercent'] : 0 ),
			'orderCompletedAt'   => isset( $record['orderCompletedAt'] ) ? (string) $record['orderCompletedAt'] : '',
			'calculatedAt'       => isset( $record['calculatedAt'] ) ? (string) $record['calculatedAt'] : '',
		);
	}

	private function persist_item_cost_snapshot( $item, $cost ) {
		$item->update_meta_data( self::ITEM_META_SOURCE_UNIT_COST, wc_format_decimal( $cost, 6 ) );
		$item->update_meta_data( self::ITEM_META_SOURCE_SNAPSHOT_AT, time() );
	}

	private function get_item_cost_snapshot( $item ) {
		$value = $item->get_meta( self::ITEM_META_SOURCE_UNIT_COST, true );
		if ( '' === (string) $value || ! is_numeric( $value ) ) {
			return null;
		}
		return max( 0, (float) $value );
	}

	private function get_source_unit_cost( $product_id, $variation_id, $product = null ) {
		$ids = array_values( array_unique( array_filter( array( absint( $variation_id ), absint( $product_id ) ) ) ) );

		if ( $product instanceof WC_Product ) {
			$ids[] = absint( $product->get_id() );
			if ( $product->is_type( 'variation' ) ) {
				$ids[] = absint( $product->get_parent_id() );
			}
			$ids = array_values( array_unique( array_filter( $ids ) ) );
		}

		foreach ( $ids as $id ) {
			$value = get_post_meta( $id, 'mobo_api_price', true );
			if ( '' !== (string) $value && is_numeric( $value ) ) {
				return max( 0, (float) $value );
			}
		}

		return null;
	}

	private function is_mobo_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$product_id = absint( $product->get_id() );
		$parent_id = $product->is_type( 'variation' ) ? absint( $product->get_parent_id() ) : $product_id;
		$ids = array_values( array_unique( array_filter( array( $product_id, $parent_id ) ) ) );

		foreach ( $ids as $id ) {
			if (
				'' !== trim( (string) get_post_meta( $id, 'product_guid', true ) )
				|| '' !== trim( (string) get_post_meta( $id, 'variant_guid', true ) )
				|| absint( get_post_meta( $id, 'portal_product_id', true ) ) > 0
				|| absint( get_post_meta( $id, 'portal_variant_id', true ) ) > 0
				|| '' !== trim( (string) get_post_meta( $id, 'mobo_url', true ) )
			) {
				return true;
			}
		}

		return false;
	}

	private function invalidate_summary_cache() {
		update_option( self::OPTION_REVISION, absint( get_option( self::OPTION_REVISION, 0 ) ) + 1, false );
		delete_option( self::OPTION_CACHE );
	}

	private function round_number( $value ) {
		return round( (float) $value, 6 );
	}
}
