<?php
/**
 * Re-apply current category mapping to synced published products.
 *
 * This worker is intentionally cursor-based and bounded. It only touches
 * published parent products that were synced by Mobo Core. Variations are not
 * processed because WooCommerce category assignment belongs to parent products.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Recategorize_Queue {

	const STATE_OPTION = 'mobo_core_recategorize_state';

	/**
	 * Category sync service.
	 *
	 * @var Mobo_Core_Category_Sync
	 */
	private $category_sync;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->category_sync = new Mobo_Core_Category_Sync();
	}

	/**
	 * Start a new category reapply run.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public function start( $source = 'admin' ) {
		$state = array(
			'status'      => 'running',
			'source'      => sanitize_key( (string) $source ),
			'lastPostId'  => 0,
			'processed'   => 0,
			'updated'     => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'total'       => $this->count_items(),
			'lastError'   => '',
			'lastMessage' => 'اعمال مجدد دسته‌بندی‌ها شروع شد.',
			'startedAt'   => time(),
			'updatedAt'   => time(),
			'completedAt' => 0,
		);

		update_option( self::STATE_OPTION, $state, false );

		return array(
			'success' => true,
			'message' => $state['lastMessage'],
			'status'  => $this->get_status(),
		);
	}

	/**
	 * Cancel current run.
	 *
	 * @return array
	 */
	public function cancel() {
		$state = $this->get_state();

		if ( ! in_array( $state['status'], array( 'running', 'waiting' ), true ) ) {
			return array( 'success' => true, 'message' => 'عملیات اعمال دسته‌بندی فعال نیست.' );
		}

		$state['status']      = 'cancelled';
		$state['lastMessage'] = 'اعمال مجدد دسته‌بندی‌ها متوقف شد.';
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

		return array( 'success' => true, 'message' => 'وضعیت اعمال مجدد دسته‌بندی‌ها پاک شد.' );
	}

	/**
	 * Process one bounded batch.
	 *
	 * @param int|null $limit Batch size.
	 * @return array
	 */
	public function process_batch( $limit = null ) {
		$state = $this->get_state();

		if ( 'running' !== $state['status'] ) {
			return array(
				'processed' => 0,
				'updated'   => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'remaining' => false,
				'status'    => $state['status'],
			);
		}

		$limit = null === $limit
			? Mobo_Core_Settings::get_int( 'mobo_core_recategorize_batch_size', 20, 1, 200 )
			: absint( $limit );

		if ( $limit <= 0 ) {
			$limit = 20;
		}

		$ids = $this->get_next_item_ids( absint( $state['lastPostId'] ), $limit );

		if ( empty( $ids ) ) {
			$state['status']      = 'done';
			$state['lastMessage'] = 'اعمال مجدد دسته‌بندی‌ها کامل شد.';
			$state['updatedAt']   = time();
			$state['completedAt'] = time();
			update_option( self::STATE_OPTION, $state, false );

			return array(
				'processed' => 0,
				'updated'   => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'remaining' => false,
				'status'    => 'done',
				'message'   => $state['lastMessage'],
			);
		}

		$processed = 0;
		$updated   = 0;
		$skipped   = 0;
		$failed    = 0;

		foreach ( $ids as $post_id ) {
			$post_id = absint( $post_id );
			$state['lastPostId'] = max( absint( $state['lastPostId'] ), $post_id );
			$processed++;

			try {
				$result = $this->recategorize_product( $post_id );

				if ( ! empty( $result['changed'] ) ) {
					$updated++;
				} elseif ( ! empty( $result['skipped'] ) ) {
					$skipped++;
				}
			} catch ( Throwable $e ) {
				$failed++;
				$state['lastError'] = sanitize_text_field( $e->getMessage() );
				error_log( 'Mobo Core recategorize failed for product ' . $post_id . ': ' . $e->getMessage() );
			}

			$checkpoint = $state;
			$checkpoint['processed']   = absint( $state['processed'] ) + $processed;
			$checkpoint['updated']     = absint( $state['updated'] ) + $updated;
			$checkpoint['skipped']     = absint( $state['skipped'] ) + $skipped;
			$checkpoint['failed']      = absint( $state['failed'] ) + $failed;
			$checkpoint['updatedAt']   = time();
			$checkpoint['lastMessage'] = sprintf( 'در حال اعمال مجدد دسته‌بندی؛ آخرین محصول بررسی‌شده: %d', $post_id );
			update_option( self::STATE_OPTION, $checkpoint, false );
		}

		$state['processed']   = absint( $state['processed'] ) + $processed;
		$state['updated']     = absint( $state['updated'] ) + $updated;
		$state['skipped']     = absint( $state['skipped'] ) + $skipped;
		$state['failed']      = absint( $state['failed'] ) + $failed;
		$state['updatedAt']   = time();
		$state['lastMessage'] = sprintf( 'در این مرحله %d محصول بررسی شد؛ %d محصول تغییر کرد، %d محصول رد شد.', $processed, $updated, $skipped );

		$remaining = count( $ids ) >= $limit;

		if ( ! $remaining ) {
			$state['status']      = 'done';
			$state['lastMessage'] = 'اعمال مجدد دسته‌بندی‌ها کامل شد.';
			$state['completedAt'] = time();
		}

		update_option( self::STATE_OPTION, $state, false );

		return array(
			'processed' => $processed,
			'updated'   => $updated,
			'skipped'   => $skipped,
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
			'status'         => $state['status'],
			'total'          => $total,
			'processed'      => $processed,
			'updated'        => absint( $state['updated'] ),
			'skipped'        => absint( $state['skipped'] ),
			'failed'         => absint( $state['failed'] ),
			'lastPostId'     => absint( $state['lastPostId'] ),
			'percent'        => $percent,
			'lastMessage'    => (string) $state['lastMessage'],
			'lastError'      => (string) $state['lastError'],
			'updatedAt'      => absint( $state['updatedAt'] ),
			'shouldContinue' => 'running' === $state['status'],
		);
	}

	/**
	 * Reapply categories to one published synced product.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	private function recategorize_product( $post_id ) {
		$post_id = absint( $post_id );
		$product_guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );

		if ( '' !== $product_guid && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			$lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 0, 120 );

			if ( false === $lock ) {
				return array( 'changed' => false, 'skipped' => true, 'reason' => 'product-lock-busy' );
			}

			try {
				return $this->recategorize_product_locked( $post_id );
			} finally {
				Mobo_Core_Product_Concurrency::release_product_lock( $lock );
			}
		}

		return $this->recategorize_product_locked( $post_id );
	}

	private function recategorize_product_locked( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $this->is_allowed_product( $post_id ) ) {
			return array( 'changed' => false, 'skipped' => true, 'reason' => 'not-allowed' );
		}

		$category_refs = $this->get_product_category_refs( $post_id );

		if ( empty( $category_refs ) ) {
			update_post_meta( $post_id, 'mobo_category_reapply_source', 'no-category-refs' );
			return array( 'changed' => false, 'skipped' => true, 'reason' => 'no-category-refs' );
		}

		$before = $this->get_product_term_ids( $post_id );

		/*
		 * This tool is intentionally mapping-only.
		 * It must re-apply the customer's saved category mapping to existing published products,
		 * not create new WooCommerce categories, not use the default category, and not mutate
		 * local category structures just because automatic category sync is enabled.
		 */
		$result = $this->category_sync->assign_product_categories(
			$post_id,
			$category_refs,
			false,
			false
		);

		$after   = $this->get_product_term_ids( $post_id );
		$changed = $before !== $after;

		update_post_meta( $post_id, 'mobo_category_reapply_at', gmdate( 'c' ) );
		update_post_meta( $post_id, 'mobo_category_reapply_source', isset( $result['source'] ) ? sanitize_text_field( (string) $result['source'] ) : '' );

		return array(
			'changed' => $changed,
			'skipped' => ! $changed,
			'result'  => $result,
		);
	}

	/**
	 * Check whether a product is eligible.
	 *
	 * @param int $post_id Product ID.
	 * @return bool
	 */
	private function is_allowed_product( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
			return false;
		}

		$product_guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );

		if ( '' !== $product_guid && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			if ( Mobo_Core_Product_Concurrency::is_non_canonical_product( $post_id ) ) {
				return false;
			}

			if ( Mobo_Core_Product_Concurrency::is_manual_sync_busy_for_product( $product_guid ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return saved category refs or recover them from latest product event.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	private function get_product_category_refs( $post_id ) {
		$post_id = absint( $post_id );
		$refs    = array();

		/*
		 * Merge all available sources instead of returning early. Some older plugin
		 * versions stored a wrapper/relation GUID in mobo_product_category_guids.
		 * Recovering from the latest event lets us include the actual category GUID
		 * as well, so partial mappings can still be applied.
		 */
		$stored_refs = $this->decode_refs_json( get_post_meta( $post_id, 'mobo_product_category_refs_json', true ) );
		if ( ! empty( $stored_refs ) ) {
			$refs = array_merge( $refs, $stored_refs );
		}

		$guids = get_post_meta( $post_id, 'mobo_product_category_guids', true );
		if ( is_array( $guids ) && ! empty( $guids ) ) {
			$refs = array_merge( $refs, $this->refs_from_guids( $guids ) );
		}

		$event_refs = $this->recover_refs_from_latest_event( $post_id );
		if ( ! empty( $event_refs ) ) {
			$refs = array_merge( $refs, $event_refs );
		}

		/*
		 * Older installs may have synced the product before category GUID meta
		 * existed and may also have no complete ProductUpdated payload left in the
		 * local event table. In that case, backfill directly from MoboCore by the
		 * product_guid and then apply the current mapping. This avoids forcing a
		 * full product sync just to refresh categories.
		 */
		if ( empty( $refs ) ) {
			$api_refs = $this->recover_refs_from_api( $post_id );
			if ( ! empty( $api_refs ) ) {
				$refs = array_merge( $refs, $api_refs );
			}
		}

		$refs = $this->normalize_category_refs( $refs );

		if ( ! empty( $refs ) ) {
			$this->store_category_refs_meta( $post_id, $refs );
		}

		return $refs;
	}


	/**
	 * Recover category refs directly from MoboCore by product GUID.
	 *
	 * This is the fallback that makes recategorize work for products synced by
	 * older plugin versions: the product already exists in WooCommerce, mapping
	 * is now configured, but no category_guid metadata is stored locally.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	private function recover_refs_from_api( $post_id ) {
		$post_id      = absint( $post_id );
		$product_guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );

		if ( $post_id <= 0 || '' === $product_guid || ! class_exists( 'Mobo_Core_API_Client' ) ) {
			return array();
		}

		$api     = new Mobo_Core_API_Client();
		$sync_id = 'category-backfill-' . gmdate( 'YmdHis' );
		$result  = $api->get_product_by_guid( $product_guid, $sync_id );

		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, 'mobo_category_backfill_error', sanitize_text_field( $result->get_error_message() ) );
			update_post_meta( $post_id, 'mobo_category_reapply_source', 'api-backfill-failed' );
			return array();
		}

		if ( ! is_array( $result ) ) {
			update_post_meta( $post_id, 'mobo_category_backfill_error', 'API returned an invalid product payload.' );
			update_post_meta( $post_id, 'mobo_category_reapply_source', 'api-backfill-invalid' );
			return array();
		}

		$refs = $this->get_product_category_refs_from_payload( $result );

		if ( empty( $refs ) ) {
			$product_payload = $this->find_product_payload_in_event( $result, $product_guid );
			$refs            = is_array( $product_payload ) ? $this->get_product_category_refs_from_payload( $product_payload ) : array();
		}

		$normalized = is_array( $refs ) ? $this->normalize_category_refs( $refs ) : array();

		if ( ! empty( $normalized ) ) {
			delete_post_meta( $post_id, 'mobo_category_backfill_error' );
			update_post_meta( $post_id, 'mobo_category_reapply_source', 'api-backfill' );
			$this->store_category_refs_meta( $post_id, $normalized );
		}

		return $normalized;
	}

	/**
	 * Decode refs JSON.
	 *
	 * @param mixed $json JSON value.
	 * @return array
	 */
	private function decode_refs_json( $json ) {
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $this->normalize_category_refs( $decoded ) : array();
	}

	/**
	 * Recover category refs from latest stored ProductUpdated event.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	private function recover_refs_from_latest_event( $post_id ) {
		global $wpdb;

		$post_id      = absint( $post_id );
		$product_guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );

		if ( '' === $product_guid || ! class_exists( 'Mobo_Core_Sync_Event_Store' ) || ! Mobo_Core_Sync_Event_Store::table_exists() ) {
			return array();
		}

		$table = Mobo_Core_Sync_Event_Store::table_name();
		$like = '%' . $wpdb->esc_like( $product_guid ) . '%';
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT payload_json FROM {$table}
				WHERE event_type = 'ProductUpdated'
				AND (entity_guid = %s OR payload_json LIKE %s)
				ORDER BY id DESC
				LIMIT 10",
				$product_guid,
				$like
			)
		);

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $json ) {
			if ( ! is_string( $json ) || '' === $json ) {
				continue;
			}

			$payload = json_decode( $json, true );

			if ( ! is_array( $payload ) ) {
				continue;
			}

		$refs = $this->get_product_category_refs_from_payload( $payload );

		if ( empty( $refs ) ) {
			$product_payload = $this->find_product_payload_in_event( $payload, $product_guid );
			$refs = is_array( $product_payload ) ? $this->get_product_category_refs_from_payload( $product_payload ) : array();
		}

		$normalized = is_array( $refs ) ? $this->normalize_category_refs( $refs ) : array();

			if ( ! empty( $normalized ) ) {
				return $normalized;
			}
		}

		return array();
	}


	/**
	 * Return product category refs from all supported payload field names.
	 *
	 * @param array $payload Product payload or event envelope.
	 * @return array
	 */
	private function get_product_category_refs_from_payload( $payload ) {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$keys = array(
			'product_categories',
			'productCategories',
			'ProductCategories',
			'category_refs',
			'categoryRefs',
			'categories',
			'Categories',
			'category_guids',
			'categoryGuids',
			'CategoryGuids',
		);

		foreach ( $keys as $key ) {
			$value = $this->get_value( $payload, $key, null );

			if ( is_array( $value ) && ! empty( $value ) ) {
				return $value;
			}
		}

		return array();
	}


	/**
	 * Find the product object inside a ProductUpdated event payload.
	 *
	 * ProductUpdated payloads can be either a single product object or a paged
	 * envelope with data: [ product, ... ]. Older reapply code treated data as
	 * an object and therefore could not recover productCategories for already
	 * synced products.
	 *
	 * @param array  $payload Event payload.
	 * @param string $product_guid Product GUID.
	 * @return array
	 */
	private function find_product_payload_in_event( $payload, $product_guid ) {
		$product_guid = sanitize_text_field( (string) $product_guid );

		if ( ! is_array( $payload ) ) {
			return array();
		}

		if ( $this->payload_matches_product_guid( $payload, $product_guid ) ) {
			return $payload;
		}

		$data = $this->get_value( $payload, 'data', array() );

		if ( ! is_array( $data ) ) {
			return array();
		}

		if ( $this->payload_matches_product_guid( $data, $product_guid ) ) {
			return $data;
		}

		foreach ( $data as $item ) {
			if ( is_array( $item ) && $this->payload_matches_product_guid( $item, $product_guid ) ) {
				return $item;
			}
		}

		/* If the event contains exactly one product, use it as a safe fallback. */
		if ( 1 === count( $data ) ) {
			$only = reset( $data );
			return is_array( $only ) ? $only : array();
		}

		return array();
	}

	/**
	 * Check whether a payload belongs to a product GUID.
	 *
	 * @param array  $payload Product payload.
	 * @param string $product_guid Product GUID.
	 * @return bool
	 */
	private function payload_matches_product_guid( $payload, $product_guid ) {
		if ( ! is_array( $payload ) || '' === $product_guid ) {
			return false;
		}

		$keys = array( 'product_guid', 'productGuid', 'productId', 'guid', 'remote_guid', 'remoteGuid', 'entity_guid', 'entityGuid', 'id' );

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $payload, $key, '' ) );
			if ( $this->is_remote_guid_value( $value ) && $product_guid === $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize and sanitize category refs.
	 *
	 * @param array $refs Raw refs.
	 * @return array
	 */
	private function normalize_category_refs( $refs ) {
		if ( ! is_array( $refs ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $refs as $ref ) {
			if ( ! is_array( $ref ) ) {
				$guid = sanitize_text_field( (string) $ref );
				if ( $this->is_remote_guid_value( $guid ) ) {
					$normalized[] = array( 'id' => $guid );
				}
				continue;
			}

			$guid = $this->extract_category_guid( $ref );

			if ( '' === $guid ) {
				continue;
			}

			$normalized[] = array(
				'id'       => $guid,
				'title'    => sanitize_text_field( (string) $this->get_value( $ref, 'title', '' ) ),
				'url'      => sanitize_text_field( (string) $this->get_value( $ref, 'url', '' ) ),
				'parentId' => sanitize_text_field( (string) $this->get_value( $ref, 'parentId', '' ) ),
			);
		}

		return $normalized;
	}

	/**
	 * Convert GUID list to category refs.
	 *
	 * @param array $guids GUIDs.
	 * @return array
	 */
	private function refs_from_guids( $guids ) {
		$refs = array();

		foreach ( $guids as $guid ) {
			$guid = sanitize_text_field( (string) $guid );
			if ( $this->is_remote_guid_value( $guid ) ) {
				$refs[] = array( 'id' => $guid );
			}
		}

		return $refs;
	}

	/**
	 * Store category refs meta for future reapply runs.
	 *
	 * @param int   $post_id Product ID.
	 * @param array $refs Refs.
	 * @return void
	 */
	private function store_category_refs_meta( $post_id, $refs ) {
		$post_id = absint( $post_id );
		$refs    = $this->normalize_category_refs( $refs );

		if ( $post_id <= 0 ) {
			return;
		}

		if ( empty( $refs ) ) {
			delete_post_meta( $post_id, 'mobo_product_category_refs_json' );
			delete_post_meta( $post_id, 'mobo_product_category_guids' );
			return;
		}

		$guids = array();
		foreach ( $refs as $ref ) {
			$guid = $this->extract_category_guid( $ref );
			if ( '' !== $guid ) {
				$guids[] = $guid;
			}
		}

		update_post_meta( $post_id, 'mobo_product_category_refs_json', wp_json_encode( $refs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		update_post_meta( $post_id, 'mobo_product_category_guids', array_values( array_unique( $guids ) ) );
	}

	/**
	 * Get product term IDs sorted.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	private function get_product_term_ids( $post_id ) {
		$terms = wp_get_object_terms( absint( $post_id ), 'product_cat', array( 'fields' => 'ids' ) );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$terms = array_values( array_unique( array_filter( array_map( 'absint', $terms ) ) ) );
		sort( $terms );

		return $terms;
	}

	/**
	 * Extract category GUID from ref.
	 *
	 * @param array $ref Ref.
	 * @return string
	 */
	private function extract_category_guid( $ref ) {
		$guids = $this->collect_category_guid_candidates( $ref );

		return ! empty( $guids ) ? sanitize_text_field( (string) $guids[0] ) : '';
	}

	/**
	 * Collect category GUID candidates from all supported payload shapes.
	 *
	 * @param mixed $ref Category ref.
	 * @return array
	 */
	private function collect_category_guid_candidates( $ref ) {
		$guids = array();

		if ( ! is_array( $ref ) ) {
			$value = sanitize_text_field( (string) $ref );
			return $this->is_remote_guid_value( $value ) ? array( $value ) : array();
		}

		$primary_keys = array( 'category_guid', 'categoryGuid', 'categoryId', 'categoryGUID', 'guid', 'remote_guid', 'remoteGuid', 'portal_category_id', 'portalCategoryId', 'category_portal_id', 'categoryPortalId' );
		foreach ( $primary_keys as $key ) {
			$this->append_category_guid_candidate( $guids, $this->get_value( $ref, $key, '' ) );
		}

		$nested = $this->get_value( $ref, 'category', null );
		if ( is_array( $nested ) ) {
			foreach ( $this->collect_category_guid_candidates( $nested ) as $nested_guid ) {
				$this->append_category_guid_candidate( $guids, $nested_guid );
			}
		} else {
			$this->append_category_guid_candidate( $guids, $nested );
		}

		$fallback_keys = array( 'product_category_id', 'productCategoryId', 'product_category_guid', 'productCategoryGuid', 'id' );
		foreach ( $fallback_keys as $key ) {
			$this->append_category_guid_candidate( $guids, $this->get_value( $ref, $key, '' ) );
		}

		return array_values( array_unique( array_filter( $guids ) ) );
	}

	/**
	 * Append a valid category GUID candidate.
	 *
	 * @param array $guids GUID list.
	 * @param mixed $value Raw value.
	 * @return void
	 */
	private function append_category_guid_candidate( &$guids, $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' !== $value && $this->is_remote_guid_value( $value ) ) {
			$guids[] = $value;
		}
	}


	/**
	 * Check whether a value is usable as a remote GUID.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function is_remote_guid_value( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return false;
		}

		if ( false !== strpos( $value, '/' ) || false !== strpos( $value, '\\' ) || false !== strpos( $value, '://' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Count products eligible for category reapply.
	 *
	 * @return int
	 */
	private function count_items() {
		global $wpdb;

		return absint( $wpdb->get_var( "
			SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pg ON pg.post_id = p.ID
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND pg.meta_key = 'product_guid'
			AND pg.meta_value <> ''
		" ) );
	}

	/**
	 * Get next eligible product IDs.
	 *
	 * @param int $last_id Last ID.
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
			INNER JOIN {$wpdb->postmeta} pg ON pg.post_id = p.ID
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND pg.meta_key = 'product_guid'
			AND pg.meta_value <> ''
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
			'skipped'     => 0,
			'failed'      => 0,
			'total'       => 0,
			'lastError'   => '',
			'lastMessage' => '',
			'startedAt'   => 0,
			'updatedAt'   => 0,
			'completedAt' => 0,
		);

		return array_merge( $defaults, $state );
	}

	/**
	 * Safe array value reader with PascalCase fallback.
	 *
	 * @param array  $array Array.
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private function get_value( $array, $key, $default = null ) {
		if ( ! is_array( $array ) ) {
			return $default;
		}

		if ( array_key_exists( $key, $array ) ) {
			return $array[ $key ];
		}

		$pascal = ucfirst( $key );

		if ( array_key_exists( $pascal, $array ) ) {
			return $array[ $pascal ];
		}

		return $default;
	}
}
