<?php
/**
 * Product and variation sync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Product_Sync {

	const STATE_OPTION = 'mobo_core_sync_state';

	private $rules;
	private $price_calculator;
	private $image_sync;

	public function __construct() {
		$this->rules            = new Mobo_Core_Legacy_Rules();
		$this->price_calculator = new Mobo_Core_Price_Calculator( $this->rules );
		$this->image_sync       = new Mobo_Core_Image_Sync();
	}

	/**
	 * Process ProductUpdated payload.
	 *
	 * Payload may be partially processed if images are chunked.
	 *
	 * @param array $payload Payload passed by reference.
	 * @return array
	 */
	public function process_product_updated_payload( &$payload ) {
		if ( ! is_array( $payload ) ) {
			return $this->result( false, 'Invalid ProductUpdated payload.' );
		}

		$items = $this->get_value( $payload, 'data', array() );

		if ( ! is_array( $items ) ) {
			return $this->result( false, 'ProductUpdated data must be array.' );
		}

		$product_index = max( 0, absint( $this->get_value( $payload, '_moboProductIndex', 0 ) ) );
		$image_offset  = max( 0, absint( $this->get_value( $payload, '_moboImageOffset', 0 ) ) );

		if ( ! isset( $items[ $product_index ] ) || ! is_array( $items[ $product_index ] ) ) {
			unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );

			return $this->result(
				true,
				'ProductUpdated completed.',
				array(
					'deleteFile' => true,
				)
			);
		}

		$product_data = $items[ $product_index ];
		$product_id   = $this->upsert_parent_product( $product_data, false );

		if ( $product_id <= 0 ) {
			$product_index++;
			$payload['_moboProductIndex'] = $product_index;
			$payload['_moboImageOffset']  = 0;

			return $this->result(
				true,
				'Skipped invalid product.',
				array(
					'deleteFile' => false,
				)
			);
		}

		if ( $this->rules->should_update_images() ) {
			$image_result = $this->image_sync->process_images(
				$product_id,
				$this->get_value( $product_data, 'images', array() ),
				$image_offset
			);

			if ( empty( $image_result['done'] ) ) {
				$payload['_moboProductIndex'] = $product_index;
				$payload['_moboImageOffset']  = absint( $image_result['nextOffset'] );

				return $this->result(
					true,
					'ProductUpdated partially processed; images remaining.',
					array(
						'deleteFile' => false,
						'productId'  => $product_id,
						'offset'     => absint( $image_result['nextOffset'] ),
					)
				);
			}
		}

		$product_index++;
		$payload['_moboProductIndex'] = $product_index;
		$payload['_moboImageOffset']  = 0;

		if ( $product_index < count( $items ) ) {
			return $this->result(
				true,
				'ProductUpdated partially processed; products remaining.',
				array(
					'deleteFile' => false,
				)
			);
		}

		unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );

		return $this->result(
			true,
			'ProductUpdated processed.',
			array(
				'deleteFile' => true,
			)
		);
	}

	/**
	 * Process UpdateVariant payload.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	public function process_update_variant_payload( $payload ) {
		if ( ! is_array( $payload ) ) {
			return $this->result( false, 'Invalid UpdateVariant payload.' );
		}

		$product_guid = sanitize_text_field( (string) $this->get_value( $payload, 'productId', '' ) );
		$sync_id      = sanitize_text_field( (string) $this->get_value( $payload, 'syncId', '' ) );
		$page_number  = max( 1, absint( $this->get_value( $payload, 'pageNumber', 1 ) ) );
		$has_more     = $this->get_value( $payload, 'hasMore', false );
		$is_last_page = $this->get_value( $payload, 'isLastPage', null );
		$variants     = $this->get_value( $payload, 'data', array() );

		if ( ! is_array( $variants ) ) {
			$variants = array();
		}

		if ( '' === $product_guid && isset( $variants[0] ) && is_array( $variants[0] ) ) {
			$product_guid = sanitize_text_field( (string) $this->get_value( $variants[0], 'productId', '' ) );
		}

		if ( '' === $product_guid ) {
			return $this->result( false, 'productId is required.' );
		}

		if ( '' === $sync_id ) {
			$sync_id = 'no-sync-id-' . $product_guid;
		}

		$product_id = $this->find_product_id_by_guid( $product_guid );

		if ( $product_id <= 0 ) {
			return $this->result(
				false,
				'Parent product not found.',
				array(
					'productGuid' => $product_guid,
				)
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return $this->result( false, 'Invalid parent product.' );
		}

		if ( 1 === $page_number ) {
			$this->reset_seen_variants( $product_guid, $sync_id );
		}

		$updated = 0;
		$skipped = 0;

		foreach ( $variants as $variant_data ) {
			if ( ! is_array( $variant_data ) ) {
				$skipped++;
				continue;
			}

			$variation_id = $this->upsert_variation( $product, $variant_data );

			if ( $variation_id > 0 ) {
				$variant_guid = sanitize_text_field( (string) $this->get_value( $variant_data, 'variantId', '' ) );

				if ( '' !== $variant_guid ) {
					$this->mark_variant_seen( $product_guid, $sync_id, $variant_guid );
				}

				$updated++;
			} else {
				$skipped++;
			}
		}

		$is_last_page = null === $is_last_page ? ! $this->to_bool( $has_more ) : $this->to_bool( $is_last_page );

		if ( $is_last_page ) {
			$this->finalize_missing_variants( $product, $product_guid, $sync_id );
			$this->clear_seen_variants( $product_guid, $sync_id );
		}

		if ( is_callable( array( 'WC_Product_Variable', 'sync' ) ) ) {
			WC_Product_Variable::sync( $product->get_id() );
		}

		wc_delete_product_transients( $product->get_id() );

		return $this->result(
			true,
			'UpdateVariant processed.',
			array(
				'deleteFile'  => true,
				'productGuid' => $product_guid,
				'pageNumber'  => $page_number,
				'updated'     => $updated,
				'skipped'     => $skipped,
				'isLastPage'  => $is_last_page,
			)
		);
	}

	/**
	 * Reset manual sync state.
	 *
	 * @return void
	 */
	public function reset_manual_sync_state() {
		delete_option( self::STATE_OPTION );
	}

	/**
	 * Get manual sync state.
	 *
	 * @return array
	 */
	public function get_manual_sync_state() {
		$default = array(
			'syncId'             => wp_generate_uuid4(),
			'status'             => 'running',
			'productPage'        => 1,
			'productQueue'       => array(),
			'currentProductGuid' => '',
			'variantPage'        => 1,
			'lastMessage'        => '',
			'updatedAt'          => time(),
		);

		$state = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return wp_parse_args( $state, $default );
	}

	/**
	 * Run one manual API sync step.
	 *
	 * @return array
	 */
	public function run_manual_sync_step() {
		$api            = new Mobo_Core_API_Client();
		$state          = $this->get_manual_sync_state();
		$products_limit = Mobo_Core_Settings::get_int( 'mobo_core_products_per_page', 1, 1, 20 );
		$variants_limit = Mobo_Core_Settings::get_int( 'mobo_core_variants_per_page', 5, 1, 100 );

		if ( 'done' === $state['status'] ) {
			return $this->result( true, 'Manual sync already completed.', $state );
		}

		if ( empty( $state['productQueue'] ) && '' === $state['currentProductGuid'] ) {
			$response = $api->get_products_page( absint( $state['productPage'] ), $products_limit );

			if ( is_wp_error( $response ) ) {
				$state['lastMessage'] = $response->get_error_message();
				$this->save_manual_sync_state( $state );

				return $this->result( false, $response->get_error_message(), $state );
			}

			$items    = $this->get_value( $response, 'data', array() );
			$has_more = $this->get_value( $response, 'hasMore', false );

			if ( ! is_array( $items ) ) {
				$items = array();
			}

			foreach ( $items as $product_data ) {
				if ( is_array( $product_data ) ) {
					$state['productQueue'][] = $product_data;
				}
			}

			$state['productPage'] = absint( $state['productPage'] ) + 1;

			if ( empty( $state['productQueue'] ) && ! $this->to_bool( $has_more ) ) {
				$state['status']      = 'done';
				$state['lastMessage'] = 'Manual sync completed.';
				$this->save_manual_sync_state( $state );

				return $this->result( true, 'Manual sync completed.', $state );
			}
		}

		if ( '' === $state['currentProductGuid'] ) {
			$product_data = array_shift( $state['productQueue'] );
			$product_id   = $this->upsert_parent_product( $product_data, true );

			if ( $product_id <= 0 ) {
				$state['lastMessage'] = 'Skipped invalid product.';
				$this->save_manual_sync_state( $state );

				return $this->result( true, 'Skipped invalid product.', $state );
			}

			$product_guid = sanitize_text_field( (string) $this->get_value( $product_data, 'productId', '' ) );

			$state['currentProductGuid'] = $product_guid;
			$state['variantPage']        = 1;
			$state['lastMessage']        = 'Parent product synced: ' . $product_guid;

			$this->reset_seen_variants( $product_guid, $state['syncId'] );
			$this->save_manual_sync_state( $state );

			return $this->result( true, $state['lastMessage'], $state );
		}

		$product_guid = sanitize_text_field( (string) $state['currentProductGuid'] );
		$response     = $api->get_variants_page( $product_guid, absint( $state['variantPage'] ), $variants_limit );

		if ( is_wp_error( $response ) ) {
			$state['lastMessage'] = $response->get_error_message();
			$this->save_manual_sync_state( $state );

			return $this->result( false, $response->get_error_message(), $state );
		}

		$payload = array(
			'event'         => 'UpdateVariant',
			'syncId'        => $state['syncId'],
			'productId'     => $product_guid,
			'totalCount'    => $this->get_value( $response, 'totalCount', 0 ),
			'pageNumber'    => $this->get_value( $response, 'pageNumber', $state['variantPage'] ),
			'recordPerPage' => $this->get_value( $response, 'recordPerPage', $variants_limit ),
			'hasMore'       => $this->get_value( $response, 'hasMore', false ),
			'isLastPage'    => $this->get_value( $response, 'isLastPage', null ),
			'data'          => $this->get_value( $response, 'data', array() ),
		);

		$result = $this->process_update_variant_payload( $payload );

		if ( empty( $result['success'] ) ) {
			$state['lastMessage'] = $result['message'];
			$this->save_manual_sync_state( $state );

			return $result;
		}

		if ( $this->to_bool( $payload['hasMore'] ) ) {
			$state['variantPage'] = absint( $state['variantPage'] ) + 1;
		} else {
			$state['currentProductGuid'] = '';
			$state['variantPage']        = 1;
		}

		$state['lastMessage'] = $result['message'];
		$this->save_manual_sync_state( $state );

		return $this->result( true, $result['message'], $state );
	}

	private function save_manual_sync_state( $state ) {
		$state['updatedAt'] = time();
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Upsert parent product.
	 *
	 * @param array $data Product data.
	 * @param bool  $skip_images Whether to skip image handling.
	 * @return int
	 */
	private function upsert_parent_product( $data, $skip_images ) {
		$product_guid = sanitize_text_field( (string) $this->get_value( $data, 'productId', '' ) );

		if ( '' === $product_guid ) {
			return 0;
		}

		$product_id = $this->find_product_id_by_guid( $product_guid );

		if ( $product_id > 0 ) {
			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product_Variable ) {
				$product = new WC_Product_Variable( $product_id );
			}
		} else {
			$product = new WC_Product_Variable();
		}

		if ( $this->rules->should_update_title() || 0 === absint( $product->get_id() ) ) {
			$title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );

			if ( '' !== $title ) {
				$product->set_name( $title );
			}
		}

		if ( $this->rules->should_update_caption() ) {
			$caption = wp_kses_post( (string) $this->get_value( $data, 'caption', '' ) );

			if ( '' !== $caption ) {
				$product->set_short_description( $caption );
			}
		}

		$this->apply_price_to_product( $product, $data, 'product' );

		if ( $this->rules->should_update_slug() ) {
			$url  = sanitize_text_field( (string) $this->get_value( $data, 'url', '' ) );
			$slug = trim( $url, '/' );

			if ( '' !== $slug ) {
				$product->set_slug( sanitize_title( $slug ) );
			}
		}

		$published_at = sanitize_text_field( (string) $this->get_value( $data, 'publishedAt', '' ) );

		if ( '' !== $published_at ) {
			$product->update_meta_data( 'published_at', $published_at );
		}

		$url = sanitize_text_field( (string) $this->get_value( $data, 'url', '' ) );

		if ( '' !== $url ) {
			$product->update_meta_data( 'mobo_url', $url );
		}

		$product->update_meta_data( 'product_guid', $product_guid );

		$attributes = $this->build_product_attributes( $this->get_value( $data, 'attributes', array() ) );

		if ( ! empty( $attributes ) ) {
			$product->set_attributes( $attributes );
		}

		$product_id = absint( $product->save() );

		$this->store_product_attribute_guids( $product_id, $this->get_value( $data, 'attributes', array() ) );

		if ( $this->rules->should_update_categories() ) {
			$this->assign_existing_categories( $product_id, $this->get_value( $data, 'productCategories', array() ) );
		}

		if ( ! $skip_images && $this->rules->should_update_images() ) {
			$this->image_sync->process_images( $product_id, $this->get_value( $data, 'images', array() ), 0 );
		}

		return $product_id;
	}

	/**
	 * Upsert variation.
	 *
	 * @param WC_Product $parent Parent product.
	 * @param array      $data Variant data.
	 * @return int
	 */
	private function upsert_variation( $parent, $data ) {
		$parent_id    = absint( $parent->get_id() );
		$variant_guid = sanitize_text_field( (string) $this->get_value( $data, 'variantId', '' ) );

		if ( $parent_id <= 0 || '' === $variant_guid ) {
			return 0;
		}

		$variation_id = $this->find_variation_id_by_guid( $variant_guid );

		if ( $variation_id > 0 ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				$variation = new WC_Product_Variation( $variation_id );
			}
		} else {
			$variation = new WC_Product_Variation();
		}

		$variation->set_parent_id( $parent_id );
		$variation->set_status( 'publish' );

		if ( $this->rules->should_update_title() || 0 === absint( $variation->get_id() ) ) {
			$title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );

			if ( '' !== $title ) {
				$variation->set_name( $title );
			}
		}

		$this->apply_price_to_product( $variation, $data, 'variation' );

		if ( $this->rules->should_update_stock() ) {
			$stock = $this->get_value( $data, 'stock', null );

			$variation->set_manage_stock( true );

			if ( null === $stock || '' === $stock ) {
				$variation->set_stock_quantity( 0 );
				$variation->set_stock_status( 'outofstock' );
			} else {
				$stock_quantity = max( 0, absint( $stock ) );

				$variation->set_stock_quantity( $stock_quantity );
				$variation->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
			}
		}

		$attrs = $this->normalize_variation_attributes( $this->get_value( $data, 'attributes', array() ) );

		if ( ! empty( $attrs ) ) {
			$variation->set_attributes( $attrs );
		}

		$product_guid = sanitize_text_field( (string) $this->get_value( $data, 'productId', '' ) );

		$variation->update_meta_data( 'variant_guid', $variant_guid );

		if ( '' !== $product_guid ) {
			$variation->update_meta_data( 'product_guid', $product_guid );
		}

		return absint( $variation->save() );
	}

	/**
	 * Apply legacy price rules to product/variation.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $data Payload.
	 * @param string     $context Context.
	 * @return void
	 */
	private function apply_price_to_product( $product, $data, $context ) {
		if ( $this->rules->should_update_compare_price() ) {
			$compare_price = $this->price_calculator->calculate(
				$this->get_value( $data, 'comparePrice', null ),
				$context . '_compare'
			);

			if ( null !== $compare_price ) {
				$product->set_regular_price( $compare_price );

				if ( $this->rules->should_update_price() ) {
					$sale_price = $this->price_calculator->calculate(
						$this->get_value( $data, 'price', null ),
						$context . '_sale'
					);

					if ( null !== $sale_price ) {
						$product->set_sale_price( $sale_price );
					}
				}

				return;
			}
		}

		if ( $this->rules->should_update_price() ) {
			$price = $this->price_calculator->calculate(
				$this->get_value( $data, 'price', null ),
				$context
			);

			if ( null !== $price ) {
				$product->set_regular_price( $price );
				$product->set_sale_price( '' );
			}
		}
	}

	private function build_product_attributes( $attributes ) {
		$result   = array();
		$position = 0;

		if ( ! is_array( $attributes ) ) {
			return $result;
		}

		foreach ( $attributes as $attribute_data ) {
			if ( ! is_array( $attribute_data ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) $this->get_value( $attribute_data, 'name', '' ) );

			if ( '' === $name ) {
				continue;
			}

			$values = $this->get_value( $attribute_data, 'values', array() );

			if ( ! is_array( $values ) ) {
				continue;
			}

			$options = array();

			foreach ( $values as $value_data ) {
				if ( ! is_array( $value_data ) ) {
					continue;
				}

				$value = sanitize_text_field( (string) $this->get_value( $value_data, 'value', '' ) );

				if ( '' !== $value ) {
					$options[] = $value;
				}
			}

			$options = array_values( array_unique( $options ) );

			if ( empty( $options ) ) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( $options );
			$attribute->set_position( $position );
			$attribute->set_visible( true );
			$attribute->set_variation( true );

			$result[] = $attribute;
			$position++;
		}

		return $result;
	}

	private function store_product_attribute_guids( $product_id, $attributes ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! is_array( $attributes ) ) {
			return;
		}

		$map = array();

		foreach ( $attributes as $attribute_data ) {
			if ( ! is_array( $attribute_data ) ) {
				continue;
			}

			$guid = sanitize_text_field( (string) $this->get_value( $attribute_data, 'id', '' ) );
			$name = sanitize_text_field( (string) $this->get_value( $attribute_data, 'name', '' ) );

			if ( '' !== $guid && '' !== $name ) {
				$map[ $name ] = $guid;
			}
		}

		if ( ! empty( $map ) ) {
			update_post_meta( $product_id, 'attribute_guid', $map );
			update_post_meta( $product_id, 'mobo_attribute_guid_map', $map );
		}
	}

	private function normalize_variation_attributes( $attributes ) {
		$result = array();

		if ( ! is_array( $attributes ) ) {
			return $result;
		}

		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}

			$name   = sanitize_text_field( (string) $this->get_value( $attribute, 'name', '' ) );
			$option = sanitize_text_field( (string) $this->get_value( $attribute, 'option', '' ) );

			if ( '' === $name || '' === $option ) {
				continue;
			}

			$key = sanitize_title( $name );

			if ( '' !== $key ) {
				$result[ $key ] = $option;
			}
		}

		return $result;
	}

	private function assign_existing_categories( $product_id, $categories ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! is_array( $categories ) ) {
			return;
		}

		$term_ids = array();

		foreach ( $categories as $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}

			$guid = sanitize_text_field( (string) $this->get_value( $category, 'categoryId', '' ) );

			if ( '' === $guid ) {
				continue;
			}

			$term_id = $this->find_term_id_by_category_guid( $guid );

			if ( $term_id > 0 ) {
				$term_ids[] = $term_id;
			}
		}

		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $product_id, array_map( 'absint', $term_ids ), 'product_cat', false );
		}
	}

	private function finalize_missing_variants( $product, $product_guid, $sync_id ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( 'ignore' === $this->rules->missing_variants_behavior() ) {
			return;
		}

		if ( ! $this->rules->should_update_stock() ) {
			return;
		}

		$seen = get_option( $this->seen_option_name( $product_guid, $sync_id ), array() );

		if ( ! is_array( $seen ) ) {
			$seen = array();
		}

		$children = $product->get_children();

		if ( ! is_array( $children ) ) {
			return;
		}

		foreach ( $children as $variation_id ) {
			$variation_id = absint( $variation_id );

			if ( $variation_id <= 0 ) {
				continue;
			}

			$variant_guid = sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) );

			if ( '' === $variant_guid || isset( $seen[ $variant_guid ] ) ) {
				continue;
			}

			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 0 );
			$variation->set_stock_status( 'outofstock' );
			$variation->save();
		}
	}

	private function find_product_id_by_guid( $guid ) {
		return $this->find_post_id_by_meta( 'product', 'product_guid', $guid );
	}

	private function find_variation_id_by_guid( $guid ) {
		return $this->find_post_id_by_meta( 'product_variation', 'variant_guid', $guid );
	}

	private function find_post_id_by_meta( $post_type, $meta_key, $meta_value ) {
		$meta_value = sanitize_text_field( (string) $meta_value );

		if ( '' === $meta_value ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => sanitize_key( $post_type ),
				'post_status'            => array( 'publish', 'draft', 'private', 'pending', 'inherit' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => sanitize_key( $meta_key ),
						'value' => $meta_value,
					),
				),
			)
		);

		return ! empty( $query->posts[0] ) ? absint( $query->posts[0] ) : 0;
	}

	private function find_term_id_by_category_guid( $guid ) {
		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid ) {
			return 0;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 1,
				'meta_query' => array(
					array(
						'key'   => 'category_guid',
						'value' => $guid,
					),
				),
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms[0] ) ) {
			return 0;
		}

		return absint( $terms[0]->term_id );
	}

	private function reset_seen_variants( $product_guid, $sync_id ) {
		update_option( $this->seen_option_name( $product_guid, $sync_id ), array(), false );
	}

	private function mark_variant_seen( $product_guid, $sync_id, $variant_guid ) {
		$option_name = $this->seen_option_name( $product_guid, $sync_id );
		$seen        = get_option( $option_name, array() );

		if ( ! is_array( $seen ) ) {
			$seen = array();
		}

		$variant_guid = sanitize_text_field( (string) $variant_guid );

		if ( '' === $variant_guid ) {
			return;
		}

		$seen[ $variant_guid ] = 1;

		update_option( $option_name, $seen, false );
	}

	private function clear_seen_variants( $product_guid, $sync_id ) {
		delete_option( $this->seen_option_name( $product_guid, $sync_id ) );
	}

	private function seen_option_name( $product_guid, $sync_id ) {
		return 'mobo_seen_variants_' . md5( sanitize_text_field( (string) $product_guid ) . '|' . sanitize_text_field( (string) $sync_id ) );
	}

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

	private function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value === 1;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return ! empty( $value );
	}

	private function result( $success, $message, $data = array() ) {
		return array(
			'success' => (bool) $success,
			'message' => sanitize_text_field( (string) $message ),
			'data'    => is_array( $data ) ? $data : array(),
		);
	}
}