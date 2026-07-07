<?php
/**
 * Re-apply pricing policy to synced products and variations.
 *
 * This worker recalculates WooCommerce prices from the raw API prices stored
 * in post meta by product sync. It is intentionally cursor-based and bounded
 * so large stores do not time out when the pricing policy changes.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Reprice_Queue {

	const STATE_OPTION = 'mobo_core_reprice_state';

	/**
	 * Price calculator.
	 *
	 * @var Mobo_Core_Price_Calculator
	 */
	private $price_calculator;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->price_calculator = new Mobo_Core_Price_Calculator( new Mobo_Core_Legacy_Rules() );
	}

	/**
	 * Start a new repricing run.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public function start( $source = 'admin' ) {
		$state = array(
			'status'       => 'running',
			'source'       => sanitize_key( (string) $source ),
			'lastPostId'   => 0,
			'processed'    => 0,
			'updated'      => 0,
			'failed'       => 0,
			'total'        => $this->count_items(),
			'lastError'    => '',
			'lastMessage'  => 'اعمال مجدد سیاست قیمت‌گذاری شروع شد.',
			'startedAt'    => time(),
			'updatedAt'    => time(),
			'completedAt'  => 0,
			'policyType'   => (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' ),
		);

		update_option( self::STATE_OPTION, $state, false );

		return array(
			'success' => true,
			'message' => $state['lastMessage'],
			'status'  => $this->get_status(),
		);
	}

	/**
	 * Cancel current repricing run.
	 *
	 * @return array
	 */
	public function cancel() {
		$state = $this->get_state();

		if ( ! in_array( $state['status'], array( 'running', 'waiting' ), true ) ) {
			return array( 'success' => true, 'message' => 'عملیات قیمت‌گذاری فعال نیست.' );
		}

		$state['status']      = 'cancelled';
		$state['lastMessage'] = 'اعمال مجدد قیمت‌گذاری متوقف شد.';
		$state['updatedAt']   = time();
		update_option( self::STATE_OPTION, $state, false );

		return array( 'success' => true, 'message' => $state['lastMessage'] );
	}

	/**
	 * Reset state.
	 *
	 * @return array
	 */
	public function reset() {
		delete_option( self::STATE_OPTION );

		return array( 'success' => true, 'message' => 'وضعیت اعمال مجدد قیمت‌گذاری پاک شد.' );
	}

	/**
	 * Process one bounded batch.
	 *
	 * @param int $limit Batch size.
	 * @return array
	 */
	public function process_batch( $limit = null ) {
		$state = $this->get_state();

		if ( 'running' !== $state['status'] ) {
			return array(
				'processed' => 0,
				'updated'   => 0,
				'failed'    => 0,
				'remaining' => false,
				'status'    => $state['status'],
			);
		}

		$limit = null === $limit
			? Mobo_Core_Settings::get_int( 'mobo_core_reprice_batch_size', 20, 1, 200 )
			: absint( $limit );

		if ( $limit <= 0 ) {
			$limit = 20;
		}

		$ids = $this->get_next_item_ids( absint( $state['lastPostId'] ), $limit );

		if ( empty( $ids ) ) {
			$state['status']      = 'done';
			$state['lastMessage'] = 'اعمال مجدد سیاست قیمت‌گذاری کامل شد.';
			$state['updatedAt']   = time();
			$state['completedAt'] = time();
			update_option( self::STATE_OPTION, $state, false );

			return array(
				'processed' => 0,
				'updated'   => 0,
				'failed'    => 0,
				'remaining' => false,
				'status'    => 'done',
				'message'   => $state['lastMessage'],
			);
		}

		$processed = 0;
		$updated   = 0;
		$failed    = 0;
		$parents_to_sync = array();

		foreach ( $ids as $post_id ) {
			$post_id = absint( $post_id );
			$state['lastPostId'] = max( absint( $state['lastPostId'] ), $post_id );
			$processed++;

			try {
				$result = $this->reprice_object( $post_id );

				if ( ! empty( $result['updated'] ) ) {
					$updated++;
				}

				if ( ! empty( $result['parentId'] ) ) {
					$parents_to_sync[ absint( $result['parentId'] ) ] = true;
				}
			} catch ( Throwable $e ) {
				$failed++;
				$state['lastError'] = sanitize_text_field( $e->getMessage() );
			}

			/*
			 * Persist cursor after each object. If PHP max_execution_time,
			 * web server shutdown, or app shutdown happens mid-batch, the next
			 * worker run resumes after the last successfully attempted post ID
			 * instead of replaying the whole batch. Repricing is idempotent, but
			 * this keeps large stores moving predictably.
			 */
			$checkpoint = $state;
			$checkpoint['processed']   = absint( $state['processed'] ) + $processed;
			$checkpoint['updated']     = absint( $state['updated'] ) + $updated;
			$checkpoint['failed']      = absint( $state['failed'] ) + $failed;
			$checkpoint['updatedAt']   = time();
			$checkpoint['lastMessage'] = sprintf( 'در حال اعمال مجدد قیمت؛ آخرین شناسه بررسی‌شده: %d', $post_id );
			update_option( self::STATE_OPTION, $checkpoint, false );
		}

		foreach ( array_keys( $parents_to_sync ) as $parent_id ) {
			if ( function_exists( 'wc_get_product' ) && class_exists( 'WC_Product_Variable' ) ) {
				try {
					WC_Product_Variable::sync( absint( $parent_id ) );
					wc_delete_product_transients( absint( $parent_id ) );
				} catch ( Throwable $e ) {
					$failed++;
					$state['lastError'] = 'خطا در sync محصول متغیر ' . absint( $parent_id ) . ': ' . sanitize_text_field( $e->getMessage() );
					error_log( 'Mobo Core reprice parent sync failed for product ' . absint( $parent_id ) . ': ' . $e->getMessage() );
				}
			}
		}

		$state['processed']   = absint( $state['processed'] ) + $processed;
		$state['updated']     = absint( $state['updated'] ) + $updated;
		$state['failed']      = absint( $state['failed'] ) + $failed;
		$state['updatedAt']   = time();
		$state['lastMessage'] = sprintf( 'در این مرحله %d مورد بررسی شد؛ %d مورد به‌روزرسانی شد.', $processed, $updated );

		$remaining = count( $ids ) >= $limit;

		if ( ! $remaining ) {
			$state['status']      = 'done';
			$state['lastMessage'] = 'اعمال مجدد سیاست قیمت‌گذاری کامل شد.';
			$state['completedAt'] = time();
		}

		update_option( self::STATE_OPTION, $state, false );

		return array(
			'processed' => $processed,
			'updated'   => $updated,
			'failed'    => $failed,
			'remaining' => $remaining,
			'status'    => $state['status'],
			'state'     => $state,
		);
	}

	/**
	 * Return UI/status payload.
	 *
	 * @return array
	 */
	public function get_status() {
		$state = $this->get_state();
		$total = absint( $state['total'] );

		if ( $total <= 0 && in_array( $state['status'], array( 'idle', 'done' ), true ) ) {
			$total = $this->count_items();
		}

		$processed = absint( $state['processed'] );
		$percent   = $total > 0 ? min( 100, round( ( $processed / $total ) * 100, 2 ) ) : 0;

		return array(
			'status'      => $state['status'],
			'total'       => $total,
			'processed'   => $processed,
			'updated'     => absint( $state['updated'] ),
			'failed'      => absint( $state['failed'] ),
			'lastPostId'  => absint( $state['lastPostId'] ),
			'percent'     => $percent,
			'lastMessage' => (string) $state['lastMessage'],
			'lastError'   => (string) $state['lastError'],
			'updatedAt'   => absint( $state['updatedAt'] ),
			'policyType'  => (string) $state['policyType'],
			'shouldContinue' => 'running' === $state['status'],
		);
	}

	/**
	 * Reprice one product or variation from saved API price meta.
	 *
	 * @param int $post_id Product/variation ID.
	 * @return array
	 */
	private function reprice_object( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $this->is_reprice_allowed_post( $post_id ) ) {
			return array( 'updated' => false, 'skipped' => true );
		}

		$product = wc_get_product( $post_id );

		if ( ! $product instanceof WC_Product ) {
			return array( 'updated' => false );
		}

		$raw_price = get_post_meta( $post_id, 'mobo_api_price', true );

		if ( '' === $raw_price || null === $raw_price ) {
			return array( 'updated' => false );
		}

		$raw_compare_price = get_post_meta( $post_id, 'mobo_api_compare_price', true );
		$context = $product->is_type( 'variation' ) ? 'variation' : 'product';

		$pair = $this->price_calculator->calculate_price_pair(
			$post_id,
			$raw_price,
			$raw_compare_price,
			$context
		);

		$changed = false;

		if ( null !== $pair['regular_price'] && '' !== $pair['regular_price'] ) {
			if ( (string) $product->get_regular_price( 'edit' ) !== (string) $pair['regular_price'] ) {
				$product->set_regular_price( $pair['regular_price'] );
				$changed = true;
			}
			$product->update_meta_data( 'mobo_calculated_regular_price', $pair['regular_price'] );
		}

		$sale_price = isset( $pair['sale_price'] ) ? $pair['sale_price'] : '';
		if ( (string) $product->get_sale_price( 'edit' ) !== (string) $sale_price ) {
			$product->set_sale_price( $sale_price );
			$changed = true;
		}
		$product->update_meta_data( 'mobo_calculated_sale_price', $sale_price );
		$product->update_meta_data( 'mobo_price_policy_type', (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' ) );
		$product->update_meta_data( 'mobo_price_policy_updated_at', gmdate( 'c' ) );

		$product->save();
		wc_delete_product_transients( $post_id );

		return array(
			'updated'  => true,
			'changed'  => $changed,
			'parentId' => $product->is_type( 'variation' ) ? absint( $product->get_parent_id() ) : 0,
		);
	}

	/**
	 * Check whether a product/variation is allowed to be repriced.
	 *
	 * Reprice is intentionally limited to published synced objects. A variation
	 * is only eligible when both the variation and its parent product are
	 * published. Draft/private/pending/trash objects are left untouched.
	 *
	 * @param int $post_id Product or variation post ID.
	 * @return bool
	 */
	private function is_reprice_allowed_post( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		if ( 'product' === $post->post_type ) {
			return true;
		}

		if ( 'product_variation' !== $post->post_type ) {
			return false;
		}

		$parent_id = absint( $post->post_parent );

		if ( $parent_id <= 0 ) {
			return false;
		}

		$parent = get_post( $parent_id );

		return $parent instanceof WP_Post
			&& 'product' === $parent->post_type
			&& 'publish' === $parent->post_status;
	}

	/**
	 * Count repricable objects.
	 *
	 * @return int
	 */
	private function count_items() {
		global $wpdb;

		return absint( $wpdb->get_var( "
			SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
			WHERE p.post_type IN ('product', 'product_variation')
			AND p.post_status = 'publish'
			AND (p.post_type = 'product' OR (p.post_type = 'product_variation' AND parent.post_type = 'product' AND parent.post_status = 'publish'))
			AND pm.meta_key = 'mobo_api_price'
			AND pm.meta_value <> ''
		" ) );
	}

	/**
	 * Get next IDs using stable ID cursor.
	 *
	 * @param int $last_id Last post ID.
	 * @param int $limit Limit.
	 * @return array
	 */
	private function get_next_item_ids( $last_id, $limit ) {
		global $wpdb;

		$last_id = absint( $last_id );
		$limit   = max( 1, absint( $limit ) );

		$sql = $wpdb->prepare(
			"
			SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
			WHERE p.post_type IN ('product', 'product_variation')
			AND p.post_status = 'publish'
			AND (p.post_type = 'product' OR (p.post_type = 'product_variation' AND parent.post_type = 'product' AND parent.post_status = 'publish'))
			AND pm.meta_key = 'mobo_api_price'
			AND pm.meta_value <> ''
			AND p.ID > %d
			ORDER BY p.ID ASC
			LIMIT %d
			",
			$last_id,
			$limit
		);

		$ids = $wpdb->get_col( $sql );

		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/**
	 * Get normalized state.
	 *
	 * @return array
	 */
	private function get_state() {
		$state = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$defaults = array(
			'status'      => 'idle',
			'source'      => '',
			'lastPostId'  => 0,
			'processed'   => 0,
			'updated'     => 0,
			'failed'      => 0,
			'total'       => 0,
			'lastError'   => '',
			'lastMessage' => '',
			'startedAt'   => 0,
			'updatedAt'   => 0,
			'completedAt' => 0,
			'policyType'  => '',
		);

		return array_merge( $defaults, $state );
	}
}
