<?php
/**
 * Immutable Mobo revenue ledger stored on WooCommerce orders.
 *
 * Source cost is frozen before/at successful Mobo submission. One immutable
 * financial record is created when the WooCommerce order reaches completed.
 * Only successfully submitted all-Mobo / single-Connection orders are eligible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Revenue_Ledger {

	const LEDGER_VERSION = 1;

	const META_LEDGER_VERSION = '_mobo_revenue_ledger_version';
	const META_LEDGER_RECORD  = '_mobo_revenue_ledger_v1';
	const META_CALCULATED_AT  = '_mobo_revenue_calculated_at';
	const META_CONNECTION_ID  = '_mobo_revenue_connection_id';

	const ITEM_META_SOURCE_UNIT_COST   = '_mobo_revenue_source_unit_cost';
	const ITEM_META_SOURCE_SNAPSHOT_AT = '_mobo_revenue_source_snapshot_at';
	const ITEM_META_SOURCE_UNIT        = '_mobo_revenue_source_unit';

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
		$identity_keys = method_exists( 'Mobo_Core_Checkout_Validator', 'get_internal_order_item_meta_keys' )
			? Mobo_Core_Checkout_Validator::get_internal_order_item_meta_keys()
			: array(
				'_mobo_identity_captured',
				'_mobo_identity_is_mobo',
				'_mobo_identity_product_guid',
				'_mobo_identity_variant_guid',
				'_mobo_identity_portal_product_id',
				'_mobo_identity_portal_variant_id',
				'_mobo_identity_sku',
				'_mobo_identity_captured_at',
				'_mobo_identity_connection_id',
			);

		return array_values(
			array_unique(
				array_merge(
					array( self::ITEM_META_SOURCE_UNIT_COST, self::ITEM_META_SOURCE_SNAPSHOT_AT, self::ITEM_META_SOURCE_UNIT ),
					$identity_keys
				)
			)
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
		$identity = $this->get_line_item_identity( $item, $product, true );

		if ( empty( $identity['known'] ) || empty( $identity['isMobo'] ) || absint( $identity['connectionId'] ?? 0 ) <= 0 ) {
			return;
		}

		$connection_id = absint( $identity['connectionId'] );
		$callback = function () use ( $item, $product, $connection_id ) {
			$cost = $this->get_source_unit_cost(
				absint( $item->get_product_id() ),
				absint( $item->get_variation_id() ),
				$product
			);
			if ( null !== $cost ) {
				$this->persist_item_cost_snapshot( $item, $cost, $connection_id );
			}
		};

		$current_connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( class_exists( 'Mobo_Core_Connection_Context' ) && $current_connection_id !== $connection_id ) {
			Mobo_Core_Connection_Context::run( $connection_id, $callback );
			return;
		}
		$callback();
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
			$identity = $this->get_line_item_identity( $item, $product, true );
			if ( empty( $identity['known'] ) || empty( $identity['isMobo'] ) ) {
				continue;
			}

			$result['moboItems']++;
			$connection_id = absint( $identity['connectionId'] ?? 0 );
			if ( $connection_id <= 0 ) {
				$result['missing']++;
				continue;
			}

			if ( null !== $this->get_item_cost_snapshot( $item ) ) {
				continue;
			}

			if ( ! $product instanceof WC_Product ) {
				$result['missing']++;
				continue;
			}

			$cost_callback = function () use ( $item, $product ) {
				return $this->get_source_unit_cost(
					absint( $item->get_product_id() ),
					absint( $item->get_variation_id() ),
					$product
				);
			};
			$current_connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
			$cost = class_exists( 'Mobo_Core_Connection_Context' ) && $current_connection_id !== $connection_id
				? Mobo_Core_Connection_Context::run( $connection_id, $cost_callback )
				: $cost_callback();

			if ( null === $cost ) {
				$result['missing']++;
				continue;
			}

			$this->persist_item_cost_snapshot( $item, $cost, $connection_id );
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

			$scope = $this->resolve_order_connection_scope( $order, false );
			if ( is_wp_error( $scope ) ) {
				return $scope;
			}
			$connection_id = absint( $scope['connectionId'] ?? 0 );

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

				$identity = $this->get_line_item_identity( $item, $item->get_product(), false );
				if ( empty( $identity['known'] ) || empty( $identity['isMobo'] ) || absint( $identity['connectionId'] ?? 0 ) !== $connection_id ) {
					return new WP_Error( 'mobo_core_revenue_order_scope_changed', 'Immutable order ownership no longer matches the revenue ledger Connection.' );
				}

				$unit_cost = $this->get_item_cost_snapshot( $item );
				if ( null === $unit_cost ) {
					return new WP_Error( 'mobo_core_revenue_source_cost_missing', 'A Mobo line item has no immutable source cost snapshot.' );
				}
				/* C4: the snapshot is in the Source unit; the sale is in the order
				 * currency. Legacy snapshots without a stored unit use the owning
				 * Connection's current Source contract unit. */
				$source_unit = (string) $item->get_meta( self::ITEM_META_SOURCE_UNIT, true );
				if ( '' === $source_unit ) {
					$source_unit = $this->source_unit_for_connection( $connection_id );
				}
				$unit_cost = self::convert_cost_to_order_currency( $unit_cost, $source_unit, (string) $order->get_currency() );
				if ( null === $unit_cost ) {
					return new WP_Error( 'mobo_core_revenue_currency_unconvertible', 'Source cost unit cannot be converted to the order currency.' );
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
				'connectionId'         => $connection_id,
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
			$order->update_meta_data( self::META_CONNECTION_ID, $connection_id );
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
				|| absint( $fresh_order->get_meta( self::META_CONNECTION_ID, true ) ) !== $connection_id
				|| absint( $fresh_record['connectionId'] ?? 0 ) !== $connection_id
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

	/**
	 * Backfill Connection ownership for pre-Multi-Connection immutable ledgers.
	 *
	 * Financial fields are never recalculated. Only missing ownership metadata is
	 * enriched, and only when ownership is provable from durable evidence:
	 * - embedded ledger Connection id;
	 * - order-level revenue Connection id;
	 * - one unambiguous immutable line-item Connection id; or
	 * - a single Connection installation (the historical pre-Multi-Connection case).
	 *
	 * Ambiguous/multi-Connection evidence is preserved fail-closed.
	 *
	 * @param int $limit Maximum candidate orders to inspect.
	 * @return array
	 */
	public function backfill_legacy_connection_ownership( $limit = 100 ) {
		global $wpdb;
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$result = array( 'status'=>'ok','inspected'=>0,'updated'=>0,'blocked'=>0,'remaining'=>0,'blockedIds'=>array() );
		if ( ! function_exists( 'wc_get_order' ) || ! class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			$result['status']='unavailable'; return $result;
		}
		$connections = Mobo_Core_Connection_Repository::list_all();
		$connections = is_array($connections)?$connections:array(); $known=array();
		foreach($connections as $row){$id=absint(is_array($row)?($row['id']??0):0);if($id>0){$known[$id]=true;}}
		$sole_connection_id = 1===count($known)?(int)array_key_first($known):0;

		/* Pre-HPOS ledgers can survive only in wp_postmeta even when current order
		 * authority is HPOS. Discover that durable legacy evidence directly, then
		 * perform every mutation and postcondition through WooCommerce CRUD. */
		$cursor = absint( get_option( 'mobo_core_103477_revenue_ledger_backfill_cursor', 0 ) );
		$blocked_ids = get_option( 'mobo_core_103477_revenue_ledger_backfill_blocked_ids', array() );
		$blocked_ids = is_array($blocked_ids)?array_values(array_unique(array_filter(array_map('absint',$blocked_ids)))):array();
		/* HPOS authoritative with compatibility sync off: ledgers written after the
		 * switch exist only in the HPOS meta table. A second phase walks that table
		 * (order_id cursor) after wp_postmeta is exhausted. */
		$hpos_meta_table = self::hpos_meta_table();
		$phase = sanitize_key( (string) get_option( 'mobo_core_103477_revenue_ledger_backfill_phase', 'postmeta' ) );
		if ( 'hpos' !== $phase || '' === $hpos_meta_table ) {
			$phase = 'postmeta';
		}
		$scan = self::backfill_candidate_sql( $phase, $hpos_meta_table );
		$wpdb->last_error = '';
		$ids = $wpdb->get_col( $wpdb->prepare( $scan['select'], self::META_LEDGER_RECORD, self::META_LEDGER_VERSION, (string)self::LEDGER_VERSION, $cursor, $limit ) );
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $ids ) ) {
			$result['status'] = 'read-failed'; $result['remaining'] = 1; return $result;
		}
		$ids=is_array($ids)?array_values(array_filter(array_map('absint',$ids))):array();
		$last_cursor=$cursor;

		foreach($ids as $order_id){
			$result['inspected']++; $last_cursor=max($last_cursor,$order_id);
			$order=wc_get_order($order_id); if(!$order instanceof WC_Order){$blocked_ids[]=$order_id;continue;}
			$order_record=$order->get_meta(self::META_LEDGER_RECORD,true);
			$legacy_record=get_post_meta($order_id,self::META_LEDGER_RECORD,true);
			$order_record=is_array($order_record)?$order_record:array(); $legacy_record=is_array($legacy_record)?$legacy_record:array();
			$record=!empty($order_record)?$order_record:$legacy_record;
			if(empty($record)||absint($record['schemaVersion']??0)!==self::LEDGER_VERSION||absint($record['orderId']??0)!==$order_id){$blocked_ids[]=$order_id;continue;}
			if(!empty($order_record)&&!empty($legacy_record)){
				$a=$order_record;$b=$legacy_record;unset($a['connectionId'],$b['connectionId']);
				if(maybe_serialize($a)!==maybe_serialize($b)){$blocked_ids[]=$order_id;continue;}
			}
			$evidence=array(); $embedded=absint($record['connectionId']??0); if($embedded>0)$evidence[$embedded]=true;
			$order_owner=absint($order->get_meta(self::META_CONNECTION_ID,true)); if($order_owner>0)$evidence[$order_owner]=true;
			foreach($order->get_items('line_item') as $item){if(!is_object($item)||!method_exists($item,'get_meta'))continue;$cid=absint($item->get_meta('_mobo_identity_connection_id',true));if($cid>0)$evidence[$cid]=true;}
			if(count($evidence)>1){$blocked_ids[]=$order_id;continue;}
			$connection_id=1===count($evidence)?(int)array_key_first($evidence):$sole_connection_id;
			if($connection_id<=0||!isset($known[$connection_id])){$blocked_ids[]=$order_id;continue;}
			$already_embedded=absint($record['connectionId']??0)===$connection_id;
			$already_owner=absint($order->get_meta(self::META_CONNECTION_ID,true))===$connection_id;
			$record['connectionId']=$connection_id;
			$order->update_meta_data(self::META_LEDGER_RECORD,$record); $order->update_meta_data(self::META_CONNECTION_ID,$connection_id);
			$saved=absint($order->save()); if($saved!==$order_id){$blocked_ids[]=$order_id;continue;}
			/* Enrich the legacy shadow too when it exists. This is ownership metadata
			 * only; immutable financial values are byte-for-byte preserved above. */
			if(!empty($legacy_record)){update_post_meta($order_id,self::META_LEDGER_RECORD,$record);update_post_meta($order_id,self::META_CONNECTION_ID,$connection_id);}
			$fresh=wc_get_order($order_id);$fresh_record=$fresh instanceof WC_Order?$fresh->get_meta(self::META_LEDGER_RECORD,true):null;
			$legacy_fresh=get_post_meta($order_id,self::META_LEDGER_RECORD,true);
			if(!$fresh instanceof WC_Order||absint($fresh->get_meta(self::META_CONNECTION_ID,true))!==$connection_id||!is_array($fresh_record)||absint($fresh_record['connectionId']??0)!==$connection_id||(!empty($legacy_record)&&(!is_array($legacy_fresh)||absint($legacy_fresh['connectionId']??0)!==$connection_id))){$blocked_ids[]=$order_id;continue;}
			$blocked_ids=array_values(array_diff($blocked_ids,array($order_id))); if(!$already_embedded||!$already_owner)$result['updated']++;
		}
		update_option('mobo_core_103477_revenue_ledger_backfill_cursor',$last_cursor,false);
		$more=absint($wpdb->get_var($wpdb->prepare($scan['more'],self::META_LEDGER_VERSION,(string)self::LEDGER_VERSION,$last_cursor)));
		$blocked_ids=array_values(array_unique(array_filter(array_map('absint',$blocked_ids)))); update_option('mobo_core_103477_revenue_ledger_backfill_blocked_ids',$blocked_ids,false);
		if($more>0){$result['remaining']=1;}
		elseif('postmeta'===$phase&&''!==$hpos_meta_table){
			/* wp_postmeta exhausted; continue with the HPOS-only ledgers. */
			update_option('mobo_core_103477_revenue_ledger_backfill_phase','hpos',false);
			delete_option('mobo_core_103477_revenue_ledger_backfill_cursor');
			$result['remaining']=1;
		}
		else{delete_option('mobo_core_103477_revenue_ledger_backfill_cursor');delete_option('mobo_core_103477_revenue_ledger_backfill_phase');$result['remaining']=count($blocked_ids);}
		$result['blocked']=count($blocked_ids);$result['blockedIds']=array_slice($blocked_ids,0,25);if($result['remaining']>0&&$result['updated']===0)$result['status']='blocked';
		if($result['updated']>0)$this->invalidate_summary_cache(); return $result;
	}


	/**
	 * HPOS order meta table when HPOS is the authoritative order store ('' otherwise).
	 *
	 * @return string
	 */
	public static function hpos_meta_table() {
		global $wpdb;
		$util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
		if ( ! class_exists( $util ) || ! method_exists( $util, 'custom_orders_table_usage_is_enabled' ) || ! call_user_func( array( $util, 'custom_orders_table_usage_is_enabled' ) ) ) {
			return '';
		}
		$store = '\\Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore';
		if ( class_exists( $store ) && method_exists( $store, 'get_meta_table_name' ) ) {
			return (string) call_user_func( array( $store, 'get_meta_table_name' ) );
		}
		return $wpdb->prefix . 'wc_orders_meta';
	}

	/**
	 * Pure: candidate discovery SQL for one backfill phase.
	 *
	 * @param string $phase postmeta|hpos.
	 * @param string $hpos_meta_table HPOS meta table.
	 * @return array{select:string,more:string}
	 */
	public static function backfill_candidate_sql( $phase, $hpos_meta_table ) {
		global $wpdb;
		if ( 'hpos' === $phase && '' !== (string) $hpos_meta_table ) {
			$t = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $hpos_meta_table );
			return array(
				'select' => "SELECT DISTINCT v.order_id FROM {$t} v INNER JOIN {$t} r ON r.order_id=v.order_id AND r.meta_key=%s WHERE v.meta_key=%s AND v.meta_value=%s AND v.order_id>%d ORDER BY v.order_id ASC LIMIT %d",
				'more'   => "SELECT MIN(v.order_id) FROM {$t} v WHERE v.meta_key=%s AND v.meta_value=%s AND v.order_id>%d",
			);
		}
		return array(
			'select' => "SELECT DISTINCT v.post_id FROM {$wpdb->postmeta} v INNER JOIN {$wpdb->postmeta} r ON r.post_id=v.post_id AND r.meta_key=%s WHERE v.meta_key=%s AND v.meta_value=%s AND v.post_id>%d ORDER BY v.post_id ASC LIMIT %d",
			'more'   => "SELECT MIN(v.post_id) FROM {$wpdb->postmeta} v WHERE v.meta_key=%s AND v.meta_value=%s AND v.post_id>%d",
		);
	}

	/**
	 * Build a revenue summary. A positive Connection id scopes every record to the
	 * authenticated owner; zero preserves the historical installation-wide helper
	 * for internal/backward-compatible callers only.
	 *
	 * @param int $connection_id Optional owner scope.
	 * @return array
	 */
	public function get_summary( $connection_id = 0 ) {
		$connection_id = absint( $connection_id );
		$revision = absint( get_option( self::OPTION_REVISION, 0 ) );
		$scope_key = $connection_id > 0 ? 'connection:' . $connection_id : 'all';
		$cache = get_option( self::OPTION_CACHE, array() );

		if ( ! is_array( $cache ) || absint( $cache['revision'] ?? -1 ) !== $revision || ! isset( $cache['summaries'] ) || ! is_array( $cache['summaries'] ) ) {
			$cache = array( 'revision' => $revision, 'summaries' => array() );
		}

		if ( isset( $cache['summaries'][ $scope_key ] ) && is_array( $cache['summaries'][ $scope_key ] ) ) {
			$summary = $cache['summaries'][ $scope_key ];
		} else {
			$summary = $this->build_summary( $connection_id );
			$cache['summaries'][ $scope_key ] = $summary;
			update_option( self::OPTION_CACHE, $cache, false );
		}

		$summary['generatedAt'] = gmdate( 'c' );
		$summary['pluginVersion'] = defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '';
		$summary['scope'] = $connection_id > 0 ? 'connection' : 'installation';
		$summary['connectionId'] = $connection_id;
		return $summary;
	}

	/**
	 * Pure: wc_get_orders() args for one page of ledger-bearing orders.
	 *
	 * Uses the top-level meta_key/meta_value shortcut, which BOTH order stores
	 * honour (HPOS OrdersTableQuery remaps meta_key/meta_value into its meta
	 * query; the CPT store passes them to WP_Query). A nested 'meta_query' is
	 * dropped by the CPT store (WC_Data_Store_WP::get_wp_query_args skips it and
	 * WC >= 9.2 logs _doing_it_wrong), so on posts storage the summary used to
	 * load EVERY shop_order object inside one REST request (envA T12, HPOS off).
	 *
	 * @param int $limit Page size.
	 * @param int $page  1-based page.
	 * @return array
	 */
	public static function summary_query_args( $limit, $page ) {
		return array(
			'type'       => 'shop_order',
			'limit'      => max( 1, absint( $limit ) ),
			'page'       => max( 1, absint( $page ) ),
			'return'     => 'ids',
			'orderby'    => 'ID',
			'order'      => 'ASC',
			'meta_key'   => self::META_LEDGER_VERSION, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded, paged; portable across CPT and HPOS.
			'meta_value' => (string) self::LEDGER_VERSION, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
		);
	}

	private function build_summary( $connection_id = 0 ) {
		$connection_id = absint( $connection_id );
		$all_time = array();
		$last_30 = array();
		$recent = array();
		$record_count = 0;
		$cutoff = time() - ( 30 * DAY_IN_SECONDS );
		$page = 1;
		$limit = 200;

		do {
			$ids = wc_get_orders( self::summary_query_args( $limit, $page ) );

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

				if ( $connection_id > 0 ) {
					$record_connection_id = $this->resolve_record_connection_id( $record, $order );
					if ( $record_connection_id !== $connection_id ) {
						continue;
					}
					if ( absint( $record['connectionId'] ?? 0 ) <= 0 ) {
						/* Response-only normalization for pre-UX28 immutable records. */
						$record['connectionId'] = $record_connection_id;
					}
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
			'connectionId'       => absint( isset( $record['connectionId'] ) ? $record['connectionId'] : 0 ),
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

	private function persist_item_cost_snapshot( $item, $cost, $connection_id = 0 ) {
		$item->update_meta_data( self::ITEM_META_SOURCE_UNIT_COST, wc_format_decimal( $cost, 6 ) );
		$item->update_meta_data( self::ITEM_META_SOURCE_SNAPSHOT_AT, time() );
		/* C4: the snapshot is in the SOURCE unit; remember which one. */
		$unit = $this->source_unit_for_connection( $connection_id );
		if ( '' !== $unit ) {
			$item->update_meta_data( self::ITEM_META_SOURCE_UNIT, $unit );
		}
	}

	/** Source monetary unit of a Connection ('' when unknown). */
	private function source_unit_for_connection( $connection_id ) {
		if ( ! class_exists( 'Mobo_Core_Money_Policy' ) ) {
			return '';
		}
		$contract = Mobo_Core_Money_Policy::get_source_contract( absint( $connection_id ) );
		return is_array( $contract ) ? (string) $contract['unit'] : '';
	}

	/**
	 * C4: convert a Source-unit cost into the order currency before subtracting
	 * it from the sale. Example: Source IRR, order IRT, cost 10,000,000 IRR ->
	 * 1,000,000 IRT (not 10,000,000 IRT, which produced a -733% margin).
	 *
	 * @param float  $cost           Unit cost in the Source unit.
	 * @param string $source_unit    Source unit ('' = unknown: unchanged).
	 * @param string $order_currency WooCommerce order currency.
	 * @return float|null Null when the pair cannot be converted.
	 */
	public static function convert_cost_to_order_currency( $cost, $source_unit, $order_currency ) {
		$cost = max( 0, (float) $cost );
		$normalize = static function ( $unit ) {
			if ( class_exists( 'Mobo_Core_Money_Policy' ) ) {
				return Mobo_Core_Money_Policy::normalize_unit( $unit );
			}
			$unit = strtoupper( trim( (string) $unit ) );
			return in_array( $unit, array( 'TOMAN', 'TOMANS' ), true ) ? 'IRT' : $unit;
		};
		$from = $normalize( $source_unit );
		$to = $normalize( $order_currency );
		if ( '' === $from || '' === $to || $from === $to ) {
			return $cost;
		}
		if ( ! class_exists( 'Mobo_Core_Money_Converter' ) ) {
			return null;
		}
		$pair = Mobo_Core_Money_Converter::pair_definition( $from, $to );
		if ( empty( $pair['supported'] ) || null === $pair['exponent'] ) {
			return null;
		}
		return round( $cost * pow( 10, (int) $pair['exponent'] ), 6 );
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

	/**
	 * Resolve immutable/current line ownership without inheriting the ambient
	 * Connection. Captured order-item metadata wins; live Product ownership is a
	 * checkout-time compatibility fallback only and is never used for ledger scope.
	 *
	 * @return array{known:bool,isMobo:bool,connectionId:int}
	 */
	private function get_line_item_identity( $item, $product = null, $allow_live_fallback = false ) {
		$out = array( 'known' => false, 'isMobo' => false, 'connectionId' => 0 );
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return $out;
		}

		if ( 'yes' === (string) $item->get_meta( '_mobo_identity_captured', true ) ) {
			$out['known'] = true;
			$out['isMobo'] = 'yes' === (string) $item->get_meta( '_mobo_identity_is_mobo', true );
			$out['connectionId'] = absint( $item->get_meta( '_mobo_identity_connection_id', true ) );
			return $out;
		}

		if ( ! $allow_live_fallback || ! $product instanceof WC_Product ) {
			return $out;
		}

		$product_id = absint( $item->get_product_id() );
		$variation_id = absint( $item->get_variation_id() );
		$connection_id = $this->resolve_product_owner_connection_id( $product_id, $variation_id );
		$out['known'] = class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready();
		$out['isMobo'] = $connection_id > 0;
		$out['connectionId'] = $connection_id;
		return $out;
	}

	/** Resolve installation-global Product/Variation ownership. @return int */
	private function resolve_product_owner_connection_id( $product_id, $variation_id = 0 ) {
		$product_id = absint( $product_id );
		$variation_id = absint( $variation_id );
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return 0;
		}

		$product_owner = 0;
		$variation_owner = 0;
		if ( $product_id > 0 && class_exists( 'Mobo_Core_Product_State_Repository' ) ) {
			$row = ( new Mobo_Core_Product_State_Repository() )->get_owner_by_woo_product_id( $product_id );
			$product_owner = is_array( $row ) ? absint( $row['connection_id'] ?? 0 ) : 0;
		}
		if ( $variation_id > 0 && class_exists( 'Mobo_Core_Variant_State_Repository' ) ) {
			$row = ( new Mobo_Core_Variant_State_Repository() )->get_owner_by_woo_variation_id( $variation_id );
			$variation_owner = is_array( $row ) ? absint( $row['connection_id'] ?? 0 ) : 0;
		}

		if ( $product_owner > 0 && $variation_owner > 0 && $product_owner !== $variation_owner ) {
			return 0;
		}
		return $variation_owner > 0 ? $variation_owner : $product_owner;
	}

	/**
	 * Revenue is valid only for the same all-Mobo/single-Connection ownership
	 * invariant required by automatic submission. Ledger creation never guesses
	 * ownership from the current catalogue when immutable line snapshots are absent.
	 *
	 * @return array|WP_Error
	 */
	private function resolve_order_connection_scope( $order, $allow_live_fallback = false ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_revenue_invalid_order_scope', 'Revenue ownership requires a valid WooCommerce order.' );
		}

		$connection_id = 0;
		$mobo_items = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$identity = $this->get_line_item_identity( $item, $item->get_product(), $allow_live_fallback );
			if ( empty( $identity['known'] ) ) {
				return new WP_Error( 'mobo_core_revenue_identity_unavailable', 'Immutable Mobo line-item ownership is unavailable.' );
			}
			if ( empty( $identity['isMobo'] ) ) {
				return new WP_Error( 'mobo_core_revenue_mixed_order_rejected', 'Mixed/non-Mobo orders are not eligible for the Mobo revenue ledger.' );
			}
			$item_connection_id = absint( $identity['connectionId'] ?? 0 );
			if ( $item_connection_id <= 0 ) {
				return new WP_Error( 'mobo_core_revenue_connection_unknown', 'Mobo line-item Connection ownership is unavailable.' );
			}
			if ( $connection_id > 0 && $connection_id !== $item_connection_id ) {
				return new WP_Error( 'mobo_core_revenue_multi_connection_rejected', 'Multi-Connection orders are not eligible for the Mobo revenue ledger.' );
			}
			$connection_id = $item_connection_id;
			$mobo_items++;
		}

		if ( $mobo_items <= 0 || $connection_id <= 0 ) {
			return new WP_Error( 'mobo_core_revenue_no_mobo_items', 'Completed order contains no immutable Mobo line item.' );
		}

		return array( 'connectionId' => $connection_id, 'moboItems' => $mobo_items );
	}

	/** Resolve one immutable ledger record owner without cross-Connection guessing. @return int */
	private function resolve_record_connection_id( $record, $order ) {
		$connection_id = absint( is_array( $record ) ? ( $record['connectionId'] ?? 0 ) : 0 );
		if ( $connection_id > 0 ) {
			return $connection_id;
		}
		if ( $order instanceof WC_Order ) {
			$connection_id = absint( $order->get_meta( self::META_CONNECTION_ID, true ) );
			if ( $connection_id > 0 ) {
				return $connection_id;
			}
			$scope = $this->resolve_order_connection_scope( $order, false );
			if ( ! is_wp_error( $scope ) ) {
				return absint( $scope['connectionId'] ?? 0 );
			}
		}
		return 0;
	}

	private function invalidate_summary_cache() {
		update_option( self::OPTION_REVISION, absint( get_option( self::OPTION_REVISION, 0 ) ) + 1, false );
		delete_option( self::OPTION_CACHE );
	}

	private function round_number( $value ) {
		return round( (float) $value, 6 );
	}
}
