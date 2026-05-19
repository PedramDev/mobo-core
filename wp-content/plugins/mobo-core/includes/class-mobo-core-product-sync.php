<?php
/**
 * Product and variation sync.
 *
 * Clean v2 implementation preserving legacy behavior/options/GUIDs.
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Product_Sync {

	const STATE_OPTION = 'mobo_core_sync_state';

	private $rules;
	private $price_calculator;
	private $image_sync;
	private $category_sync;

	public function __construct() {
		$this->rules            = new Mobo_Core_Legacy_Rules();
		$this->price_calculator = new Mobo_Core_Price_Calculator( $this->rules );
		$this->image_sync       = new Mobo_Core_Image_Sync();
		$this->category_sync    = new Mobo_Core_Category_Sync();
	}

	public function start_manual_sync( $sync_id = '', $source = 'admin' ) {
		$sync_id = sanitize_text_field( (string) $sync_id );

		if ( '' === $sync_id ) {
			$sync_id = wp_generate_uuid4();
		}

		$state = array(
			'syncId'                       => $sync_id,
			'status'                       => 'running',
			'source'                       => sanitize_key( (string) $source ),

			'categorySynced'               => false,

			'productPage'                  => 1,
			'productQueue'                 => array(),

			'currentProductGuid'           => '',
			'currentProductId'             => 0,
			'currentProductImages'         => array(),
			'currentProductImageOffset'    => 0,
			'currentProductWasExisting'    => false,
			'currentProductImagesDone'     => false,
			'currentProductCanHaveVariants'=> false,

			'variantPage'                  => 1,

			'productTotalCount'            => 0,
			'productTotalPages'            => 0,
			'processedProducts'            => 0,
			'remainingProducts'            => 0,

			'currentVariantTotalCount'     => 0,
			'currentVariantTotalPages'     => 0,
			'currentVariantProcessedPages' => 0,

			'startedAt'                    => time(),
			'completedAt'                  => 0,
			'updatedAt'                    => time(),
			'lastMessage'                  => 'همگام‌سازی محصولات شروع شد.',
			'lastError'                    => '',
		);

		update_option( self::STATE_OPTION, $state, false );

		return $this->result(
			true,
			'همگام‌سازی محصولات شروع شد.',
			$this->get_manual_sync_status()
		);
	}

	public function cancel_manual_sync() {
		$state = $this->get_manual_sync_state();

		$state['status']      = 'cancelled';
		$state['completedAt'] = time();
		$state['updatedAt']   = time();
		$state['lastMessage'] = 'همگام‌سازی محصولات متوقف شد.';

		$this->save_manual_sync_state( $state );

		return $this->result(
			true,
			'همگام‌سازی محصولات متوقف شد.',
			$this->get_manual_sync_status()
		);
	}

	public function reset_manual_sync_state() {
		delete_option( self::STATE_OPTION );
	}

	public function get_manual_sync_state() {
		$default = array(
			'syncId'                       => '',
			'status'                       => 'idle',
			'source'                       => '',

			'categorySynced'               => false,

			'productPage'                  => 1,
			'productQueue'                 => array(),

			'currentProductGuid'           => '',
			'currentProductId'             => 0,
			'currentProductImages'         => array(),
			'currentProductImageOffset'    => 0,
			'currentProductWasExisting'    => false,
			'currentProductImagesDone'     => false,
			'currentProductCanHaveVariants'=> false,

			'variantPage'                  => 1,

			'productTotalCount'            => 0,
			'productTotalPages'            => 0,
			'processedProducts'            => 0,
			'remainingProducts'            => 0,

			'currentVariantTotalCount'     => 0,
			'currentVariantTotalPages'     => 0,
			'currentVariantProcessedPages' => 0,

			'startedAt'                    => 0,
			'completedAt'                  => 0,
			'updatedAt'                    => 0,
			'lastMessage'                  => '',
			'lastError'                    => '',
		);

		$state = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return wp_parse_args( $state, $default );
	}

	public function get_manual_sync_status() {
		$state = $this->get_manual_sync_state();

		$total     = absint( $state['productTotalCount'] );
		$processed = absint( $state['processedProducts'] );
		$remaining = $total > 0 ? max( 0, $total - $processed ) : 0;
		$progress  = $total > 0 ? min( 100, round( ( $processed / $total ) * 100, 2 ) ) : 0;

		$last_error      = sanitize_text_field( (string) $state['lastError'] );
		$current_status  = sanitize_key( (string) $state['status'] );
		$should_continue = 'running' === $current_status && '' === $last_error;

		return array(
			'syncId'                       => sanitize_text_field( (string) $state['syncId'] ),
			'status'                       => $current_status,
			'source'                       => sanitize_key( (string) $state['source'] ),

			'isRunning'                    => 'running' === $current_status,
			'isDone'                       => 'done' === $current_status,
			'isCancelled'                  => 'cancelled' === $current_status,

			'categorySynced'               => (bool) $state['categorySynced'],

			'productPage'                  => absint( $state['productPage'] ),
			'queuedProducts'               => is_array( $state['productQueue'] ) ? count( $state['productQueue'] ) : 0,

			'currentProductGuid'           => sanitize_text_field( (string) $state['currentProductGuid'] ),
			'currentProductId'             => absint( $state['currentProductId'] ),
			'currentProductImageOffset'    => absint( $state['currentProductImageOffset'] ),
			'currentProductImagesCount'    => is_array( $state['currentProductImages'] ) ? count( $state['currentProductImages'] ) : 0,
			'currentProductImagesDone'     => (bool) $state['currentProductImagesDone'],
			'currentProductCanHaveVariants'=> (bool) $state['currentProductCanHaveVariants'],

			'variantPage'                  => absint( $state['variantPage'] ),

			'productTotalCount'            => $total,
			'productTotalPages'            => absint( $state['productTotalPages'] ),
			'processedProducts'            => $processed,
			'remainingProducts'            => $remaining,
			'progressPercent'              => $progress,

			'currentVariantTotalCount'     => absint( $state['currentVariantTotalCount'] ),
			'currentVariantTotalPages'     => absint( $state['currentVariantTotalPages'] ),
			'currentVariantProcessedPages' => absint( $state['currentVariantProcessedPages'] ),

			'startedAt'                    => absint( $state['startedAt'] ),
			'completedAt'                  => absint( $state['completedAt'] ),
			'updatedAt'                    => absint( $state['updatedAt'] ),

			'lastMessage'                  => sanitize_text_field( (string) $state['lastMessage'] ),
			'lastError'                    => $last_error,

			'shouldContinue'               => $should_continue,
			'recommendedDelayMs'           => $should_continue ? 0 : 5000,
		);
	}

	public function run_manual_sync_step() {
		$api            = new Mobo_Core_API_Client();
		$state          = $this->get_manual_sync_state();
		$products_limit = Mobo_Core_Settings::get_int( 'mobo_core_products_per_page', 1, 1, 20 );
		$variants_limit = Mobo_Core_Settings::get_int( 'mobo_core_variants_per_page', 5, 1, 100 );

		if ( 'idle' === $state['status'] || '' === $state['syncId'] ) {
			return $this->result( false, 'همگام‌سازی شروع نشده است.', $this->get_manual_sync_status() );
		}

		if ( 'cancelled' === $state['status'] ) {
			return $this->result( false, 'همگام‌سازی متوقف شده است.', $this->get_manual_sync_status() );
		}

		if ( 'done' === $state['status'] ) {
			return $this->result( true, 'همگام‌سازی قبلاً کامل شده است.', $this->get_manual_sync_status() );
		}

		/*
		 * Step 1: Sync categories once before products.
		 */
		if ( empty( $state['categorySynced'] ) ) {
			if ( $this->rules->should_update_categories() ) {
				$response = $api->get_categories( $state['syncId'] );

				if ( is_wp_error( $response ) ) {
					$state['lastError']   = $response->get_error_message();
					$state['lastMessage'] = 'خطا در همگام‌سازی دسته‌بندی‌ها.';
					$this->save_manual_sync_state( $state );

					return $this->result( false, $response->get_error_message(), $this->get_manual_sync_status() );
				}

				$category_result = $this->category_sync->sync_categories_payload( $response );

				$state['categorySynced'] = true;
				$state['lastError']      = '';
				$state['lastMessage']    = sprintf(
					'دسته‌بندی‌ها همگام شدند. ایجاد: %d، بروزرسانی: %d، رد شده: %d',
					absint( $category_result['created'] ),
					absint( $category_result['updated'] ),
					absint( $category_result['skipped'] )
				);

				$this->save_manual_sync_state( $state );

				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			$state['categorySynced'] = true;
			$state['lastError']      = '';
			$state['lastMessage']    = 'همگام‌سازی دسته‌بندی غیرفعال است؛ در صورت ایجاد محصول جدید، دسته پیشفرض استفاده می‌شود.';
			$this->save_manual_sync_state( $state );

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Step 2: Fetch product page when queue is empty and no current product exists.
		 */
		if ( empty( $state['productQueue'] ) && '' === $state['currentProductGuid'] ) {
			$response = $api->get_products_page(
				absint( $state['productPage'] ),
				$products_limit,
				$state['syncId']
			);

			if ( is_wp_error( $response ) ) {
				$state['lastError']   = $response->get_error_message();
				$state['lastMessage'] = 'خطا در دریافت صفحه محصولات.';
				$this->save_manual_sync_state( $state );

				return $this->result( false, $response->get_error_message(), $this->get_manual_sync_status() );
			}

			$items       = $this->get_value( $response, 'data', array() );
			$has_more    = $this->get_value( $response, 'hasMore', false );
			$total_count = absint( $this->get_value( $response, 'totalCount', 0 ) );
			$total_pages = absint( $this->get_value( $response, 'totalPages', 0 ) );

			if ( $total_count > 0 ) {
				$state['productTotalCount'] = $total_count;
			}

			if ( $total_pages > 0 ) {
				$state['productTotalPages'] = $total_pages;
			}

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
				$state['completedAt'] = time();
				$state['lastError']   = '';
				$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
				$this->save_manual_sync_state( $state );

				return $this->result( true, 'همگام‌سازی محصولات کامل شد.', $this->get_manual_sync_status() );
			}

			$this->save_manual_sync_state( $state );

			return $this->result( true, 'صفحه محصولات دریافت شد.', $this->get_manual_sync_status() );
		}

		/*
		 * Step 3: Upsert one parent product.
		 */
		if ( '' === $state['currentProductGuid'] ) {
			$product_data = array_shift( $state['productQueue'] );

			if ( ! is_array( $product_data ) ) {
				$state['lastError']   = '';
				$state['lastMessage'] = 'محصول نامعتبر رد شد.';
				$this->save_manual_sync_state( $state );

				return $this->result( true, 'محصول نامعتبر رد شد.', $this->get_manual_sync_status() );
			}

			if ( $this->should_skip_product_by_url( $product_data ) ) {
				$skipped_url = sanitize_text_field( (string) $this->get_value( $product_data, 'url', '' ) );

				$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
				$state['lastError']         = '';
				$state['lastMessage']       = 'محصول به دلیل قرار داشتن آدرس در لیست عدم همگام‌سازی رد شد: ' . $skipped_url;

				$this->save_manual_sync_state( $state );

				return $this->result(
					true,
					$state['lastMessage'],
					$this->get_manual_sync_status()
				);
			}

			$product_guid = sanitize_text_field( (string) $this->get_value( $product_data, 'productId', '' ) );
			$was_existing = '' !== $product_guid && $this->find_product_id_by_guid( $product_guid ) > 0;

			/*
			 * In manual sync, images are processed as separate chunks after parent save.
			 */
			$product_id = $this->upsert_parent_product( $product_data, true );

			if ( $product_id <= 0 ) {
				$state['lastError']   = '';
				$state['lastMessage'] = 'محصول نامعتبر رد شد.';
				$this->save_manual_sync_state( $state );

				return $this->result( true, 'محصول نامعتبر رد شد.', $this->get_manual_sync_status() );
			}

			$state['currentProductGuid']            = $product_guid;
			$state['currentProductId']              = absint( $product_id );
			$state['currentProductImages']          = $this->get_product_images_from_payload( $product_data );
			$state['currentProductImageOffset']     = 0;
			$state['currentProductWasExisting']     = $was_existing;
			$state['currentProductImagesDone']      = false;
			$state['currentProductCanHaveVariants'] = $this->product_has_variation_attributes( $product_data );

			$state['variantPage']                   = 1;
			$state['currentVariantTotalCount']      = 0;
			$state['currentVariantTotalPages']      = 0;
			$state['currentVariantProcessedPages']  = 0;
			$state['lastError']                     = '';
			$state['lastMessage']                   = 'محصول اصلی همگام شد: ' . $product_guid;

			$this->reset_seen_variants( $product_guid, $state['syncId'] );
			$this->save_manual_sync_state( $state );

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Step 4: Process product images in chunks before variants/simple finish.
		 */
		if ( empty( $state['currentProductImagesDone'] ) ) {
			$product_id   = absint( $state['currentProductId'] );
			$images       = is_array( $state['currentProductImages'] ) ? $state['currentProductImages'] : array();
			$image_offset = absint( $state['currentProductImageOffset'] );
			$was_existing = ! empty( $state['currentProductWasExisting'] );

			$should_process_images = $product_id > 0 && ! empty( $images ) && ( ! $was_existing || $this->rules->should_update_images() );

			if ( $should_process_images ) {
				$image_result = $this->image_sync->process_images(
					$product_id,
					$images,
					$image_offset
				);

				if ( empty( $image_result['done'] ) ) {
					$state['currentProductImageOffset'] = absint( $image_result['nextOffset'] );
					$state['lastError']                 = '';
					$state['lastMessage']               = 'تصاویر محصول در حال پردازش است.';

					$this->save_manual_sync_state( $state );

					return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
				}
			}

			$state['currentProductImagesDone']   = true;
			$state['currentProductImageOffset']  = 0;
			$state['lastError']                  = '';
			$state['lastMessage']                = 'تصاویر محصول پردازش شد.';

			$this->save_manual_sync_state( $state );

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Step 5: Product without variation attributes is simple.
		 */
		if ( empty( $state['currentProductCanHaveVariants'] ) ) {
			$product_guid = sanitize_text_field( (string) $state['currentProductGuid'] );
			$product_id   = absint( $state['currentProductId'] );

			if ( $product_id <= 0 && '' !== $product_guid ) {
				$product_id = $this->find_product_id_by_guid( $product_guid );
			}

			if ( $product_id > 0 ) {
				$this->force_product_simple_if_needed( $product_id );
				wc_delete_product_transients( $product_id );
			}

			$this->finish_current_product_state( $state, 'محصول بدون ویژگی متغیر است؛ به عنوان محصول ساده پردازش شد.' );

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Step 6: Sync one variants page for current product.
		 */
		$product_guid = sanitize_text_field( (string) $state['currentProductGuid'] );

		$response = $api->get_variants_page(
			$product_guid,
			absint( $state['variantPage'] ),
			$variants_limit,
			$state['syncId']
		);

		if ( is_wp_error( $response ) ) {
			$state['lastError']   = $response->get_error_message();
			$state['lastMessage'] = 'خطا در دریافت تنوع‌های محصول.';
			$this->save_manual_sync_state( $state );

			return $this->result( false, $response->get_error_message(), $this->get_manual_sync_status() );
		}

		$variant_total_count = absint( $this->get_value( $response, 'totalCount', 0 ) );
		$product_id          = absint( $state['currentProductId'] );

		if ( $product_id <= 0 ) {
			$product_id = $this->find_product_id_by_guid( $product_guid );
		}

		if ( $product_id > 0 ) {
			$this->ensure_product_type_for_variants( $product_id, $variant_total_count );
		}

		if ( 0 === $variant_total_count ) {
			if ( $product_id > 0 ) {
				$this->force_product_simple_if_needed( $product_id );
				wc_delete_product_transients( $product_id );
			}

			$this->finish_current_product_state( $state, 'محصول ساده پردازش شد.' );

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		$state['currentVariantTotalCount'] = $variant_total_count;
		$state['currentVariantTotalPages'] = absint( $this->get_value( $response, 'totalPages', 0 ) );

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
			$state['lastError']   = isset( $result['message'] ) ? $result['message'] : 'خطا در پردازش تنوع محصول.';
			$state['lastMessage'] = 'خطا در پردازش تنوع محصول.';
			$this->save_manual_sync_state( $state );

			return $this->result( false, $state['lastError'], $this->get_manual_sync_status() );
		}

		$state['currentVariantProcessedPages'] = absint( $state['currentVariantProcessedPages'] ) + 1;

		if ( $this->to_bool( $payload['hasMore'] ) ) {
			$state['variantPage'] = absint( $state['variantPage'] ) + 1;
			$state['lastError']   = '';
			$state['lastMessage'] = $result['message'];

			$this->save_manual_sync_state( $state );

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		$this->finish_current_product_state( $state, $result['message'] );

		return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
	}

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

			return $this->result( true, 'ProductUpdated completed.', array( 'deleteFile' => true ) );
		}

		$product_data = $items[ $product_index ];
		if ( $this->should_skip_product_by_url( $product_data ) ) {
			$product_index++;

			$payload['_moboProductIndex'] = $product_index;
			$payload['_moboImageOffset']  = 0;

			if ( $product_index < count( $items ) ) {
				return $this->result(
					true,
					'ProductUpdated skipped excluded product; products remaining.',
					array(
						'deleteFile' => false,
					)
				);
			}

			unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );

			return $this->result(
				true,
				'ProductUpdated skipped excluded product.',
				array(
					'deleteFile' => true,
				)
			);
		}

		$product_guid = sanitize_text_field( (string) $this->get_value( $product_data, 'productId', '' ) );
		$was_existing = '' !== $product_guid && $this->find_product_id_by_guid( $product_guid ) > 0;

		$product_id = $this->upsert_parent_product( $product_data, true );

		if ( $product_id <= 0 ) {
			$product_index++;
			$payload['_moboProductIndex'] = $product_index;
			$payload['_moboImageOffset']  = 0;

			return $this->result( true, 'Skipped invalid product.', array( 'deleteFile' => false ) );
		}

		if ( ! $was_existing || $this->rules->should_update_images() ) {
			$image_result = $this->image_sync->process_images(
				$product_id,
				$this->get_product_images_from_payload( $product_data ),
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
			return $this->result( true, 'ProductUpdated partially processed; products remaining.', array( 'deleteFile' => false ) );
		}

		unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );

		return $this->result( true, 'ProductUpdated processed.', array( 'deleteFile' => true ) );
	}

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
			return $this->result( false, 'Parent product not found.', array( 'productGuid' => $product_guid ) );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return $this->result( false, 'Invalid parent product.' );
		}

		if ( 1 === $page_number ) {
			$this->reset_seen_variants( $product_guid, $sync_id );
		}

		$this->ensure_product_type_for_variants( $product_id, absint( $this->get_value( $payload, 'totalCount', count( $variants ) ) ) );

		$product = wc_get_product( $product_id );

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
			WC_Product_Variable::sync( $product_id );
		}

		wc_delete_product_transients( $product_id );

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

	private function save_manual_sync_state( $state ) {
		$state['updatedAt'] = time();
		update_option( self::STATE_OPTION, $state, false );
	}

	private function finish_current_product_state( &$state, $message ) {
		$state['processedProducts']              = absint( $state['processedProducts'] ) + 1;
		$state['currentProductGuid']             = '';
		$state['currentProductId']               = 0;
		$state['currentProductImages']           = array();
		$state['currentProductImageOffset']      = 0;
		$state['currentProductWasExisting']      = false;
		$state['currentProductImagesDone']       = false;
		$state['currentProductCanHaveVariants']  = false;
		$state['variantPage']                    = 1;
		$state['currentVariantTotalCount']       = 0;
		$state['currentVariantTotalPages']       = 0;
		$state['currentVariantProcessedPages']   = 0;
		$state['lastError']                      = '';
		$state['lastMessage']                    = sanitize_text_field( (string) $message );

		if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
			$state['status']      = 'done';
			$state['completedAt'] = time();
			$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
		}

		$this->save_manual_sync_state( $state );
	}

	private function upsert_parent_product( $data, $skip_images ) {
		$product_guid = sanitize_text_field( (string) $this->get_value( $data, 'productId', '' ) );

		if ( '' === $product_guid ) {
			return 0;
		}

		$product_id     = $this->find_product_id_by_guid( $product_guid );
		$is_new_product = $product_id <= 0;

		if ( $product_id > 0 ) {
			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product ) {
				$product = new WC_Product_Simple( $product_id );
			}
		} else {
			$product = new WC_Product_Simple();
		}

		/*
		 * Persist product_guid as early as possible.
		 */
		if ( $is_new_product ) {
			$initial_title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );

			if ( '' === $initial_title ) {
				$initial_title = 'Mobo Product ' . $product_guid;
			}

			$product->set_name( $initial_title );
			$product->set_status( 'publish' );
			$product->update_meta_data( 'product_guid', $product_guid );
			$product->update_meta_data( 'mobo_sync_incomplete', '1' );

			$product_id = absint( $product->save() );

			if ( $product_id <= 0 ) {
				return 0;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product ) {
				return 0;
			}
		} else {
			$product->update_meta_data( 'product_guid', $product_guid );
			$product->update_meta_data( 'mobo_sync_incomplete', '1' );
			$product->save();
		}

		if ( $is_new_product || $this->rules->should_update_title() ) {
			$title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );

			if ( '' !== $title ) {
				$product->set_name( $title );
			}
		}

		if ( $is_new_product || $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) {
			$this->apply_price_to_product( $product, $data, 'product', $is_new_product );
		}

		if ( $is_new_product || $this->rules->should_update_stock() ) {
			$this->apply_api_stock( $product, $this->get_value( $data, 'stock', null ) );
		}

		if ( $is_new_product || $this->rules->should_update_slug() ) {
			$this->apply_product_slug( $product, $data );
		}

		$this->apply_product_dates( $product, $data );

		$url = sanitize_text_field( (string) $this->get_value( $data, 'url', '' ) );

		if ( '' !== $url ) {
			$product->update_meta_data( 'mobo_url', $url );
		}

		$product->update_meta_data( 'product_guid', $product_guid );

		$attributes = $this->build_product_attributes( $this->get_value( $data, 'attributes', array() ) );

		if ( ! empty( $attributes ) ) {
			$product->set_attributes( $attributes );
		} else {
			$product->set_attributes( array() );
		}

		$product->update_meta_data( 'mobo_sync_incomplete', '0' );

		$product_id = absint( $product->save() );

		if ( $product_id <= 0 ) {
			return 0;
		}

		$this->store_product_attribute_guids( $product_id, $this->get_value( $data, 'attributes', array() ) );

		$this->category_sync->assign_product_categories(
			$product_id,
			$this->get_value( $data, 'productCategories', array() ),
			$this->rules->should_update_categories(),
			$is_new_product
		);

		if ( ! $skip_images && ( $is_new_product || $this->rules->should_update_images() ) ) {
			$this->image_sync->process_images(
				$product_id,
				$this->get_product_images_from_payload( $data ),
				0
			);
		}

		return $product_id;
	}

	private function upsert_variation( $parent, $data ) {
		if ( ! $parent instanceof WC_Product ) {
			return 0;
		}

		$parent_id    = absint( $parent->get_id() );
		$variant_guid = sanitize_text_field( (string) $this->get_value( $data, 'variantId', '' ) );

		if ( $parent_id <= 0 || '' === $variant_guid ) {
			return 0;
		}

		$product_guid = sanitize_text_field( (string) $this->get_value( $data, 'productId', '' ) );

		$variation_id     = $this->find_variation_id_by_guid( $variant_guid );
		$is_new_variation = $variation_id <= 0;

		if ( $variation_id > 0 ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				$variation = new WC_Product_Variation( $variation_id );
			}
		} else {
			$variation = new WC_Product_Variation();
		}

		if ( $is_new_variation ) {
			$initial_title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );

			if ( '' === $initial_title ) {
				$initial_title = 'Mobo Variant ' . $variant_guid;
			}

			$variation->set_parent_id( $parent_id );
			$variation->set_status( 'publish' );
			$variation->set_name( $initial_title );
			$variation->update_meta_data( 'variant_guid', $variant_guid );
			$variation->update_meta_data( 'mobo_sync_incomplete', '1' );

			if ( '' !== $product_guid ) {
				$variation->update_meta_data( 'product_guid', $product_guid );
			}

			$variation_id = absint( $variation->save() );

			if ( $variation_id <= 0 ) {
				return 0;
			}

			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				return 0;
			}
		} else {
			$variation->set_parent_id( $parent_id );
			$variation->set_status( 'publish' );
			$variation->update_meta_data( 'variant_guid', $variant_guid );
			$variation->update_meta_data( 'mobo_sync_incomplete', '1' );

			if ( '' !== $product_guid ) {
				$variation->update_meta_data( 'product_guid', $product_guid );
			}

			$variation->save();
		}

		if ( $is_new_variation || $this->rules->should_update_title() ) {
			$title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );

			if ( '' !== $title ) {
				$variation->set_name( $title );
			}
		}

		if ( $is_new_variation || $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) {
			$this->apply_price_to_product( $variation, $data, 'variation', $is_new_variation );
		}

		if ( $is_new_variation || $this->rules->should_update_stock() ) {
			$this->apply_api_stock( $variation, $this->get_value( $data, 'stock', null ) );
		}

		$attrs = $this->normalize_variation_attributes( $this->get_value( $data, 'attributes', array() ) );

		if ( ! empty( $attrs ) ) {
			$variation->set_attributes( $attrs );
		}

		$variation->update_meta_data( 'variant_guid', $variant_guid );

		if ( '' !== $product_guid ) {
			$variation->update_meta_data( 'product_guid', $product_guid );
		}

		$variation->update_meta_data( 'mobo_sync_incomplete', '0' );

		return absint( $variation->save() );
	}

	private function product_has_variation_attributes( $data ) {
		$attributes = $this->get_value( $data, 'attributes', array() );

		if ( ! is_array( $attributes ) || empty( $attributes ) ) {
			return false;
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

			if ( ! is_array( $values ) || empty( $values ) ) {
				continue;
			}

			foreach ( $values as $value_data ) {
				if ( ! is_array( $value_data ) ) {
					continue;
				}

				$value = sanitize_text_field( (string) $this->get_value( $value_data, 'value', '' ) );

				if ( '' !== $value ) {
					return true;
				}
			}
		}

		return false;
	}

	private function force_product_simple_if_needed( $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( $product instanceof WC_Product_Variable ) {
			$children = $product->get_children();

			if ( is_array( $children ) ) {
				foreach ( $children as $variation_id ) {
					$variation = wc_get_product( absint( $variation_id ) );

					if ( ! $variation instanceof WC_Product_Variation ) {
						continue;
					}

					$variation->set_manage_stock( true );
					$variation->set_stock_quantity( 0 );
					$variation->set_stock_status( 'outofstock' );
					$variation->save();
				}
			}
		}

		wp_set_object_terms( $product_id, 'simple', 'product_type', false );

		$simple = new WC_Product_Simple( $product_id );
		$simple->save();

		wc_delete_product_transients( $product_id );
	}

	private function ensure_product_type_for_variants( $product_id, $variant_total_count ) {
		$product_id          = absint( $product_id );
		$variant_total_count = absint( $variant_total_count );

		if ( $product_id <= 0 ) {
			return null;
		}

		$current = wc_get_product( $product_id );

		if ( ! $current instanceof WC_Product ) {
			return null;
		}

		if ( $variant_total_count > 0 ) {
			if ( $current instanceof WC_Product_Variable ) {
				return $current;
			}

			wp_set_object_terms( $product_id, 'variable', 'product_type', false );

			$variable = new WC_Product_Variable( $product_id );
			$variable->save();

			return $variable;
		}

		$this->force_product_simple_if_needed( $product_id );

		return wc_get_product( $product_id );
	}

	private function apply_api_stock( $product, $stock ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( null === $stock || '' === $stock ) {
			$product->set_manage_stock( false );
			$product->set_stock_quantity( null );
			$product->set_stock_status( 'instock' );
			return;
		}

		$stock_quantity = max( 0, absint( $stock ) );

		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock_quantity );
		$product->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
	}

	private function apply_product_slug( $product, $data ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		/*
		 * Current behavior: use url as slug source.
		 * If API later sends explicit slug, prefer slug.
		 */
		$slug = sanitize_title( (string) $this->get_value( $data, 'slug', '' ) );

		if ( '' === $slug ) {
			$slug = sanitize_title( trim( (string) $this->get_value( $data, 'url', '' ), '/' ) );
		}

		if ( '' === $slug ) {
			return;
		}

		$product->set_slug( $slug );
	}

	private function apply_product_dates( $product, $data ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$published_at = sanitize_text_field( (string) $this->get_value( $data, 'publishedAt', '' ) );

		if ( '' === $published_at ) {
			return;
		}

		$timestamp = strtotime( $published_at );

		if ( false === $timestamp || $timestamp <= 0 ) {
			$product->update_meta_data( 'published_at', $published_at );
			return;
		}

		$gmt_date   = gmdate( 'Y-m-d H:i:s', $timestamp );
		$local_date = get_date_from_gmt( $gmt_date, 'Y-m-d H:i:s' );

		$date = new WC_DateTime( '@' . $timestamp );
		$date->setTimezone( new DateTimeZone( 'UTC' ) );

		$product->set_date_created( $date );
		$product->set_date_modified( $date );

		$product->update_meta_data( 'published_at', $published_at );
		$product->update_meta_data( 'mobo_published_at_gmt', $gmt_date );
		$product->update_meta_data( 'mobo_published_at_local', $local_date );
	}

	private function apply_price_to_product( $product, $data, $context, $is_new_object = false ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( ! $is_new_object && ! $this->rules->should_update_price() && ! $this->rules->should_update_compare_price() ) {
			return;
		}

		$pair = $this->price_calculator->calculate_price_pair(
			absint( $product->get_id() ),
			$this->get_value( $data, 'price', null ),
			$this->get_value( $data, 'comparePrice', null ),
			$context
		);

		if ( null !== $pair['regular_price'] && '' !== $pair['regular_price'] ) {
			$product->set_regular_price( $pair['regular_price'] );
		}

		if ( isset( $pair['sale_price'] ) ) {
			$product->set_sale_price( $pair['sale_price'] );
		}
	}

	private function get_product_images_from_payload( $data ) {
		$images = $this->get_value( $data, 'images', array() );

		return is_array( $images ) ? $images : array();
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

		$children = method_exists( $product, 'get_children' ) ? $product->get_children() : array();

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

	/**
 * Check if product should be excluded by URL.
 *
 * @param array $product_data Product payload.
 * @return bool
 */
private function should_skip_product_by_url( $product_data ) {
	$product_url = sanitize_text_field( (string) $this->get_value( $product_data, 'url', '' ) );

	if ( '' === $product_url ) {
		return false;
	}

	$product_url = $this->normalize_product_url_for_exclusion( $product_url );

	if ( '' === $product_url ) {
		return false;
	}

	$excluded_urls = $this->get_excluded_product_urls();

	return in_array( $product_url, $excluded_urls, true );
}

/**
 * Get excluded product URLs from settings.
 *
 * @return array
 */
private function get_excluded_product_urls() {
	$raw = (string) get_option( 'mobo_core_excluded_product_urls', '' );

	if ( '' === trim( $raw ) ) {
		return array();
	}

	$lines = preg_split( '/\r\n|\r|\n/', $raw );

	if ( ! is_array( $lines ) ) {
		return array();
	}

	$urls = array();

	foreach ( $lines as $line ) {
		$url = $this->normalize_product_url_for_exclusion( $line );

		if ( '' !== $url ) {
			$urls[] = $url;
		}
	}

	return array_values( array_unique( $urls ) );
}

/**
 * Normalize product URL/path for exclusion matching.
 *
 * Examples:
 * https://example.com/products/test/ => /products/test
 * /products/test/                    => /products/test
 * products/test                      => /products/test
 *
 * @param string $url URL or path.
 * @return string
 */
private function normalize_product_url_for_exclusion( $url ) {
	$url = trim( sanitize_text_field( (string) $url ) );

	if ( '' === $url ) {
		return '';
	}

	$path = wp_parse_url( $url, PHP_URL_PATH );

	if ( ! is_string( $path ) || '' === $path ) {
		$path = $url;
	}

	$path = trim( $path );

	if ( '' === $path ) {
		return '';
	}

	$path = '/' . ltrim( $path, '/' );
	$path = untrailingslashit( $path );

	return strtolower( $path );
}
}