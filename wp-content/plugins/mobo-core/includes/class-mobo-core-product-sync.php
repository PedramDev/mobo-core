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
	private $product_map;

	public function __construct() {
		$this->rules            = new Mobo_Core_Legacy_Rules();
		$this->price_calculator = new Mobo_Core_Price_Calculator( $this->rules );
		$this->image_sync       = new Mobo_Core_Image_Sync();
		$this->category_sync    = new Mobo_Core_Category_Sync();
		$this->product_map      = class_exists( 'Mobo_Core_Product_Map' ) ? new Mobo_Core_Product_Map() : null;
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
			'productCursor'                => 0,
			'productCursorMode'            => Mobo_Core_Settings::enabled( 'mobo_core_product_cursor_sync_enabled', '1' ) ? 'cursor' : 'page',
			'productCursorSupported'       => false,
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
			'currentVariantCursor'          => 0,

			'startedAt'                    => time(),
			'completedAt'                  => 0,
			'updatedAt'                    => time(),
			'lastMessage'                  => 'همگام‌سازی محصولات شروع شد.',
			'lastError'                    => '',
			'transientRetryCount'           => 0,
			'lastTransientError'            => '',
			'waitingForPortalSince'         => 0,
			'nextRetryAt'                  => 0,
			'cancelRequestedAt'            => 0,
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
		$now   = time();

		$state['status']            = 'cancelled';
		$state['completedAt']       = $now;
		$state['updatedAt']         = $now;
		$state['cancelRequestedAt'] = $now;
		$state['nextRetryAt']       = 0;
		$state['lastError']         = '';
		$state['lastTransientError'] = '';
		$state['lastMessage']       = 'همگام‌سازی محصولات متوقف شد.';

		$this->save_manual_sync_state( $state );

		if ( class_exists( 'Mobo_Core_Lock' ) ) {
			Mobo_Core_Lock::force_release( 'manual_sync_start' );
			Mobo_Core_Lock::force_release( 'self_runner_kick' );
		}

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
			'productCursor'                => 0,
			'productCursorMode'            => 'cursor',
			'productCursorSupported'       => false,
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
			'currentVariantCursor'          => 0,

			'startedAt'                    => 0,
			'completedAt'                  => 0,
			'updatedAt'                    => 0,
			'lastMessage'                  => '',
			'lastError'                    => '',
			'transientRetryCount'           => 0,
			'lastTransientError'            => '',
			'waitingForPortalSince'         => 0,
			'nextRetryAt'                  => 0,
			'cancelRequestedAt'            => 0,
		);

		$state = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return wp_parse_args( $state, $default );
	}

	public function get_manual_sync_status() {
		$state = $this->get_manual_sync_state();

		$total           = absint( $state['productTotalCount'] );
		$processed       = absint( $state['processedProducts'] );
		$last_error      = sanitize_text_field( (string) $state['lastError'] );
		$current_status  = sanitize_key( (string) $state['status'] );

		/*
		 * Portal totalCount can be a stale estimate while hasMore=false is the
		 * authoritative terminal signal. When sync is already done, the UI must not
		 * keep showing a phantom remaining product such as 529/530.
		 */
		if ( 'done' === $current_status ) {
			if ( $total <= 0 || $processed < $total ) {
				$total = $processed;
			}

			$remaining = 0;
			$progress  = 100;
		} else {
			$remaining = $total > 0 ? max( 0, $total - $processed ) : 0;
			$progress  = $total > 0 ? min( 100, round( ( $processed / $total ) * 100, 2 ) ) : 0;
		}

		$next_retry_at   = absint( $state['nextRetryAt'] ?? 0 );
		$is_waiting      = 'waiting_for_portal' === $current_status;
		$is_retry_due    = $is_waiting && ( 0 === $next_retry_at || $next_retry_at <= time() );
		$should_continue = ( 'running' === $current_status && '' === $last_error ) || ( $is_retry_due && '' === $last_error );

		return array(
			'syncId'                       => sanitize_text_field( (string) $state['syncId'] ),
			'status'                       => $current_status,
			'source'                       => sanitize_key( (string) $state['source'] ),

			'isRunning'                    => 'running' === $current_status,
			'isWaitingForPortal'            => $is_waiting,
			'isRetryDue'                    => $is_retry_due,
			'secondsUntilNextRetry'         => $is_waiting && $next_retry_at > time() ? max( 0, $next_retry_at - time() ) : 0,
			'isDone'                       => 'done' === $current_status,
			'isCancelled'                  => 'cancelled' === $current_status,

			'categorySynced'               => (bool) $state['categorySynced'],

			'productPage'                  => absint( $state['productPage'] ),
			'productCursor'                => absint( $state['productCursor'] ),
			'productCursorMode'            => sanitize_key( (string) $state['productCursorMode'] ),
			'productCursorSupported'       => (bool) $state['productCursorSupported'],
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
			'currentVariantCursor'          => absint( $state['currentVariantCursor'] ),

			'startedAt'                    => absint( $state['startedAt'] ),
			'completedAt'                  => absint( $state['completedAt'] ),
			'updatedAt'                    => absint( $state['updatedAt'] ),
			'waitingForPortalSince'         => absint( $state['waitingForPortalSince'] ?? 0 ),
			'nextRetryAt'                  => $next_retry_at,
			'cancelRequestedAt'            => absint( $state['cancelRequestedAt'] ?? 0 ),

			'lastMessage'                  => sanitize_text_field( (string) $state['lastMessage'] ),
			'lastError'                    => $last_error,
			'transientRetryCount'           => absint( $state['transientRetryCount'] ?? 0 ),
			'lastTransientError'            => sanitize_text_field( (string) ( $state['lastTransientError'] ?? '' ) ),

			'shouldContinue'               => $should_continue,
			'recommendedDelayMs'           => $should_continue ? 0 : ( $is_waiting && $next_retry_at > time() ? max( 1000, ( $next_retry_at - time() ) * 1000 ) : 5000 ),
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

		if ( 'waiting_for_portal' === $state['status'] ) {
			$next_retry_at = absint( $state['nextRetryAt'] ?? 0 );

			if ( $next_retry_at > 0 && $next_retry_at > time() ) {
				$state['updatedAt'] = time();
				$this->save_manual_sync_state( $state );

				return $this->result( true, 'Portal هنوز آماده تلاش مجدد نیست. وضعیت sync حفظ شده است.', $this->get_manual_sync_status() );
			}

			$state['status']              = 'running';
			$state['transientRetryCount'] = 0;
			$state['lastError']           = '';
			$state['lastMessage']         = 'اتصال به Portal دوباره بررسی می‌شود؛ ادامه از آخرین نقطه ذخیره‌شده.';
			$state['updatedAt']           = time();
			$this->save_manual_sync_state( $state );
		}

		/*
		 * Step 1: Sync categories once before products.
		 */
		if ( empty( $state['categorySynced'] ) ) {
			if ( $this->rules->should_update_categories() ) {
				$response = $api->get_categories( $state['syncId'] );

				if ( is_wp_error( $response ) ) {
					return $this->handle_transient_request_error( $state, $response, 'خطا در همگام‌سازی دسته‌بندی‌ها.' );
				}

				$this->clear_transient_request_error( $state );

				$category_result = $this->category_sync->sync_categories_payload( $response );
				
				update_option( 'mobo_core_categories_last_sync_at', time(), false );

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
			$use_product_cursor = Mobo_Core_Settings::enabled( 'mobo_core_product_cursor_sync_enabled', '1' ) && 'page-fallback' !== (string) $state['productCursorMode'];

			$response = $api->get_products_page(
				absint( $state['productPage'] ),
				$products_limit,
				$state['syncId'],
				absint( $state['productCursor'] ),
				$use_product_cursor
			);

			if ( is_wp_error( $response ) ) {
				return $this->handle_transient_request_error( $state, $response, 'خطا در دریافت صفحه محصولات.' );
			}

			$this->clear_transient_request_error( $state );

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

			$cursor_mode = sanitize_key( (string) $this->get_value( $response, 'cursorMode', '' ) );
			if ( '' !== $cursor_mode ) {
				$state['productCursorMode']      = $cursor_mode;
				$state['productCursorSupported'] = true;

				$next_cursor = $this->get_value( $response, 'nextCursor', null );
				if ( null !== $next_cursor && '' !== $next_cursor ) {
					$state['productCursor'] = absint( $next_cursor );
				}
			} elseif ( $use_product_cursor ) {
				/*
				 * Backward compatibility: older Portal builds ignore UseCursor/Cursor.
				 * After the first legacy response, fall back to page-number mode.
				 */
				$state['productCursorMode']      = 'page-fallback';
				$state['productCursorSupported'] = false;
			}

			foreach ( $items as $product_data ) {
				if ( is_array( $product_data ) ) {
					$state['productQueue'][] = $product_data;
				}
			}

			$state['productPage'] = absint( $state['productPage'] ) + 1;

			if ( empty( $state['productQueue'] ) && ! $this->to_bool( $has_more ) ) {
				/*
				 * If Portal says there is no next page, sync is complete even when a
				 * previously reported totalCount was one item higher. Persist the
				 * effective total so the admin UI does not show a phantom remaining item.
				 */
				$state['productTotalCount'] = absint( $state['processedProducts'] );
				$state['status']            = 'done';
				$state['completedAt']       = time();
				$state['lastError']         = '';
				$state['lastMessage']       = 'همگام‌سازی محصولات کامل شد.';
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
				$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
				$state['lastError']         = '';
				$state['lastMessage']       = 'محصول نامعتبر رد شد.';

				if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
					$state['status']      = 'done';
					$state['completedAt'] = time();
					$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
				}

				$this->save_manual_sync_state( $state );

				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			if ( $this->should_skip_product_by_url( $product_data ) ) {
				$skipped_url = sanitize_text_field( (string) $this->get_value( $product_data, 'url', '' ) );

				$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
				$state['lastError']         = '';
				$state['lastMessage']       = 'محصول به دلیل قرار داشتن آدرس در لیست عدم همگام‌سازی رد شد: ' . $skipped_url;

				if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
					$state['status']      = 'done';
					$state['completedAt'] = time();
					$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
				}

				$this->save_manual_sync_state( $state );

				return $this->result(
					true,
					$state['lastMessage'],
					$this->get_manual_sync_status()
				);
			}

			$product_guid = $this->extract_product_guid( $product_data );

			if ( '' !== $product_guid && $this->is_remote_product_trashed( $product_guid ) ) {
				$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
				$state['lastError']         = '';
				$state['lastMessage']       = 'محصول به دلیل قرار داشتن در سطل زباله وردپرس رد شد: ' . $product_guid;

				if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
					$state['status']      = 'done';
					$state['completedAt'] = time();
					$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
				}

				$this->save_manual_sync_state( $state );

				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			$was_existing = '' !== $product_guid && $this->find_product_id_by_guid( $product_guid ) > 0;

			/*
			 * In manual sync, images are processed as separate chunks after parent save.
			 */
			$product_id = $this->upsert_parent_product( $product_data, true );

			if ( $product_id <= 0 ) {
				$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
				$state['lastError']         = '';
				$state['lastMessage']       = 'محصول نامعتبر رد شد.';

				if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
					$state['status']      = 'done';
					$state['completedAt'] = time();
					$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
				}

				$this->save_manual_sync_state( $state );

				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
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
			$state['currentVariantCursor']          = 0;
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
					$image_offset,
					false
				);

				if ( empty( $image_result['done'] ) ) {
					$state['currentProductImageOffset'] = absint( $image_result['nextOffset'] );
					$state['lastError']                 = '';
					$state['lastMessage']               = sprintf( 'تصاویر محصول در حال پردازش است. پردازش‌شده: %d، باقی‌مانده صف: %d', isset( $image_result['processed'] ) ? absint( $image_result['processed'] ) : 0, isset( $image_result['pending'] ) ? absint( $image_result['pending'] ) : 0 );

					$this->save_manual_sync_state( $state );

					return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
				}
			}

			$state['currentProductImagesDone']   = true;
			$state['currentProductImageOffset']  = 0;
			$state['lastError']                  = '';
			$state['lastMessage']                = 'تصاویر محصول به صف امن منتقل شد و همگام‌سازی محصول ادامه پیدا می‌کند.';

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

		$use_variant_cursor = Mobo_Core_Settings::enabled( 'mobo_core_variant_cursor_sync_enabled', '1' );
		$variant_cursor     = max( 0, absint( $state['currentVariantCursor'] ?? 0 ) );

		$response = $api->get_variants_page(
			$product_guid,
			absint( $state['variantPage'] ),
			$variants_limit,
			$state['syncId'],
			$variant_cursor,
			$use_variant_cursor
		);

		if ( is_wp_error( $response ) ) {
			return $this->handle_transient_request_error( $state, $response, 'خطا در دریافت تنوع‌های محصول.' );
		}

		$this->clear_transient_request_error( $state );

		$variant_items       = $this->normalize_variant_items_from_response( $response );
		$variant_items_count = count( $variant_items );
		$variant_total_count = absint( $this->get_value( $response, 'totalCount', 0 ) );

		/*
		 * Some Portal/Swagger payloads may return totalCount=0 while data still contains variants.
		 * Treat the actual data as authoritative for this page; never mark existing variations
		 * as simple/out-of-stock just because totalCount is zero.
		 */
		if ( 0 === $variant_total_count && $variant_items_count > 0 ) {
			$variant_total_count = $variant_items_count;
		}

		$product_id = absint( $state['currentProductId'] );

		if ( $product_id <= 0 ) {
			$product_id = $this->find_product_id_by_guid( $product_guid );
		}

		if ( $product_id > 0 ) {
			$this->ensure_product_type_for_variants( $product_id, $variant_total_count );
		}

		if ( 0 === $variant_total_count && 0 === $variant_items_count ) {
			/*
			 * Safe behavior: do not force existing variations to stock=0 when the variant
			 * endpoint returns an empty/inconclusive page. If the product is truly simple,
			 * Step 5 handles it before reaching this point.
			 */
			$this->finish_current_product_state( $state, 'هیچ تنوعی از API دریافت نشد؛ تنوع‌های موجود تغییر نکردند.' );

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		$state['currentVariantTotalCount'] = $variant_total_count;
		$state['currentVariantTotalPages'] = absint( $this->get_value( $response, 'totalPages', 0 ) );

		$variant_cursor_mode = sanitize_key( (string) $this->get_value( $response, 'cursorMode', '' ) );
		$variant_next_cursor = $this->get_value( $response, 'nextCursor', null );
		if ( '' !== $variant_cursor_mode && null !== $variant_next_cursor && '' !== $variant_next_cursor ) {
			$state['currentVariantCursor'] = absint( $variant_next_cursor );
		}

		$payload = array(
			'event'                    => 'UpdateVariant',
			'variantListAuthoritative' => true,
			'syncId'                   => $state['syncId'],
			'productId'     => $product_guid,
			'totalCount'    => $this->get_value( $response, 'totalCount', 0 ),
			'pageNumber'    => $this->get_value( $response, 'pageNumber', $state['variantPage'] ),
			'recordPerPage' => $this->get_value( $response, 'recordPerPage', $variants_limit ),
			'hasMore'       => $this->get_value( $response, 'hasMore', false ),
			'isLastPage'    => $this->get_value( $response, 'isLastPage', null ),
			'data'          => $variant_items,
		);

		$result = $this->process_update_variant_payload( $payload );

		if ( empty( $result['success'] ) ) {
			$state['lastError']   = isset( $result['message'] ) ? $result['message'] : 'خطا در پردازش تنوع محصول.';
			$state['lastMessage'] = 'خطا در پردازش تنوع محصول.';
			$this->save_manual_sync_state( $state );

			return $this->result( false, $state['lastError'], $this->get_manual_sync_status() );
		}

		$state['currentVariantProcessedPages'] = absint( $state['currentVariantProcessedPages'] ) + 1;

		$variant_items     = $this->get_value( $payload, 'data', array() );
		$variant_has_more  = $this->to_bool( $payload['hasMore'] );
		$payload_last_page = $this->get_value( $payload, 'isLastPage', null );
		$total_pages       = absint( $state['currentVariantTotalPages'] );

		if ( null !== $payload_last_page && $this->to_bool( $payload_last_page ) ) {
			$variant_has_more = false;
		}

		if ( $total_pages > 0 && absint( $state['currentVariantProcessedPages'] ) >= $total_pages ) {
			$variant_has_more = false;
		}

		if ( is_array( $variant_items ) && empty( $variant_items ) ) {
			$variant_has_more = false;
		}

		if ( $variant_has_more ) {
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

		$product_guid = $this->extract_product_guid( $product_data );

		if ( '' !== $product_guid && $this->is_remote_product_trashed( $product_guid ) ) {
			$product_index++;
			$payload['_moboProductIndex'] = $product_index;
			$payload['_moboImageOffset']  = 0;

			if ( $product_index < count( $items ) ) {
				return $this->result(
					true,
					'ProductUpdated skipped trashed product; products remaining.',
					array(
						'deleteFile'  => false,
						'productGuid' => $product_guid,
					)
				);
			}

			unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );

			return $this->result(
				true,
				'ProductUpdated skipped trashed product.',
				array(
					'deleteFile'  => true,
					'productGuid' => $product_guid,
				)
			);
		}

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
				$image_offset,
				false
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

		/*
		 * Be tolerant of all known Portal shapes:
		 * 1) VariantSyncPagedResult: { productId, data: [...] }
		 * 2) EventModel wrapper: { event: UpdateVariant, data: { productId, data: [...] } }
		 * 3) Legacy list wrapper: { data: [ { productId, ... } ] }
		 * 4) Variant-specific metadata: { variantId/entityGuid } where parent can be found from product_map.
		 */
		$inner_data = $this->get_value( $payload, 'data', null );
		if ( is_array( $inner_data ) && ! $this->is_list_array( $inner_data ) ) {
			$inner_product_guid = $this->extract_product_guid( $inner_data );

			$inner_variants = $this->get_value( $inner_data, 'data', null );
			if ( '' !== $inner_product_guid || is_array( $inner_variants ) ) {
				$payload = array_merge( $payload, $inner_data );
			}
		}

		$product_guid = $this->extract_product_guid( $payload );
		$sync_id      = sanitize_text_field( (string) $this->get_value( $payload, 'syncId', '' ) );
		$page_number  = max( 1, absint( $this->get_value( $payload, 'pageNumber', 1 ) ) );
		$has_more     = $this->get_value( $payload, 'hasMore', false );
		$is_last_page = $this->get_value( $payload, 'isLastPage', null );
		$variants     = $this->get_value( $payload, 'data', array() );

		if ( is_array( $variants ) && ! $this->is_list_array( $variants ) ) {
			$nested_variants = $this->get_value( $variants, 'data', null );
			if ( is_array( $nested_variants ) ) {
				$variants = $nested_variants;
			}
		}

		if ( ! is_array( $variants ) ) {
			$variants = array();
		}

		if ( '' === $product_guid && isset( $variants[0] ) && is_array( $variants[0] ) ) {
			$product_guid = $this->extract_product_guid( $variants[0] );
		}


		if ( '' === $product_guid ) {
			$variant_guid = $this->extract_variant_guid( $payload );
			if ( '' !== $variant_guid ) {
				$product_guid = $this->find_parent_product_guid_by_variant_guid( $variant_guid );
			}
		}

		if ( '' === $product_guid ) {
			return $this->result( false, $this->build_missing_product_id_message( $payload, $variants ) );
		}

		foreach ( $variants as $variant_index => $variant_data ) {
			if ( is_array( $variant_data ) ) {
				$variant_product_guid = $this->extract_product_guid( $variant_data );

				if ( '' === $variant_product_guid ) {
					$variants[ $variant_index ]['productId'] = $product_guid;
				}
			}
		}

		if ( $this->is_remote_product_trashed( $product_guid ) ) {
			return $this->result(
				true,
				'محصول مادر در سطل زباله وردپرس است؛ تنوع‌های آن رد شدند.',
				array(
					'deleteFile'     => true,
					'productGuid'    => $product_guid,
					'skippedBecause' => 'parent_trashed',
				)
			);
		}

		if ( '' === $sync_id ) {
			$sync_id = 'no-sync-id-' . $product_guid;
		}

		$product_id = $this->find_product_id_by_guid( $product_guid );

		if ( $product_id <= 0 ) {
			return $this->result(
				true,
				'محصول مادر هنوز ساخته نشده است؛ پردازش تنوع‌ها برای تلاش بعدی به تعویق افتاد.',
				array(
					'deleteFile'       => false,
					'deferSeconds'     => 120,
					'waitingForParent' => true,
					'productGuid'      => $product_guid,
				)
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return $this->result( false, 'Invalid parent product.' );
		}

		$variant_list_authoritative = $this->is_authoritative_variant_list_payload( $payload, $variants );

		if ( $variant_list_authoritative && 1 === $page_number ) {
			$this->reset_seen_variants( $product_guid, $sync_id );
		}

		$this->ensure_product_type_for_variants( $product_id, absint( $this->get_value( $payload, 'totalCount', count( $variants ) ) ) );

		$product = wc_get_product( $product_id );

		$updated   = 0;
		$unchanged = 0;
		$skipped   = 0;

		foreach ( $variants as $variant_data ) {
			if ( ! is_array( $variant_data ) ) {
				$skipped++;
				continue;
			}

			$variation_result = $this->upsert_variation( $product, $variant_data );
			$variation_id     = is_array( $variation_result ) && isset( $variation_result['id'] ) ? absint( $variation_result['id'] ) : absint( $variation_result );

			if ( $variation_id > 0 ) {
				$variant_guid = $this->extract_variant_guid( $variant_data );

				if ( $variant_list_authoritative && '' !== $variant_guid ) {
					$this->mark_variant_seen( $product_guid, $sync_id, $variant_guid );
				}

				if ( is_array( $variation_result ) && empty( $variation_result['changed'] ) ) {
					$unchanged++;
				} else {
					$updated++;
				}
			} else {
				$skipped++;
			}
		}

		$is_last_page = null === $is_last_page ? ! $this->to_bool( $has_more ) : $this->to_bool( $is_last_page );

		if ( $is_last_page && $variant_list_authoritative ) {
			$expected_variant_total = absint( $this->get_value( $payload, 'totalCount', 0 ) );
			$seen_variant_count     = $this->count_seen_variants( $product_guid, $sync_id );

			if ( $expected_variant_total > 0 && $seen_variant_count >= $expected_variant_total ) {
				$this->finalize_missing_variants( $product, $product_guid, $sync_id );
			} else {
				update_post_meta( $product_id, '_mobo_missing_variants_finalize_skipped_at', gmdate( 'c' ) );
				update_post_meta( $product_id, '_mobo_missing_variants_finalize_reason', 'variant_list_not_authoritative' );
				update_post_meta( $product_id, '_mobo_missing_variants_seen_count', $seen_variant_count );
				update_post_meta( $product_id, '_mobo_missing_variants_expected_count', $expected_variant_total );
			}

			$this->clear_seen_variants( $product_guid, $sync_id );
		} elseif ( $is_last_page && ! $variant_list_authoritative ) {
			update_post_meta( $product_id, '_mobo_missing_variants_finalize_skipped_at', gmdate( 'c' ) );
			update_post_meta( $product_id, '_mobo_missing_variants_finalize_reason', 'variant_delta_webhook_not_authoritative' );
			update_post_meta( $product_id, '_mobo_missing_variants_payload_event', sanitize_text_field( (string) $this->get_value( $payload, 'event', '' ) ) );
		}

		if ( is_callable( array( 'WC_Product_Variable', 'sync' ) ) ) {
			WC_Product_Variable::sync( $product_id );
		}

		wc_delete_product_transients( $product_id );

		$message = sprintf(
			'تنوع‌های محصول پردازش شد. محصول: %s، صفحه: %d، بروزرسانی: %d، بدون تغییر: %d، رد شده: %d',
			$product_guid,
			$page_number,
			$updated,
			$unchanged,
			$skipped
		);

		return $this->result(
			true,
			$message,
			array(
				'deleteFile'  => true,
				'productGuid' => $product_guid,
				'pageNumber'  => $page_number,
				'updated'     => $updated,
				'unchanged'   => $unchanged,
				'skipped'     => $skipped,
				'isLastPage'  => $is_last_page,
			)
		);
	}


	/**
	 * Fetch remote categories only for admin mapping.
	 *
	 * This method never creates or updates WooCommerce product_cat terms and
	 * does not respect/change the automatic category update schedule.
	 *
	 * @param string $sync_id Optional sync ID.
	 * @return array
	 */
	public function preload_categories_for_mapping( $sync_id = '' ) {
		$sync_id = sanitize_text_field( (string) $sync_id );

		if ( '' === $sync_id ) {
			$sync_id = 'category-mapping-' . gmdate( 'YmdHis' );
		}

		$api      = new Mobo_Core_API_Client();
		$response = $api->get_categories( $sync_id );

		if ( is_wp_error( $response ) ) {
			return $this->result(
				false,
				$response->get_error_message(),
				array(
					'skipped' => false,
					'synced'  => false,
				)
			);
		}

		$category_result = $this->category_sync->load_categories_for_mapping_payload( $response );

		update_option( 'mobo_core_categories_mapping_loaded_at', time(), false );

		return $this->result(
			true,
			'دسته‌بندی‌ها فقط برای نگاشت لود شدند. هیچ دسته‌ای در ووکامرس ساخته یا بروزرسانی نشد.',
			array(
				'skipped'      => false,
				'synced'       => true,
				'mappingOnly'  => true,
				'created'      => absint( $category_result['created'] ),
				'updated'      => absint( $category_result['updated'] ),
				'skippedItems' => absint( $category_result['skipped'] ),
			)
		);
	}

	/**
	 * Ensure categories are synced if refresh interval has passed.
	 *
	 * This is intended to be called by C# through REST.
	 * It does not rely on WP-Cron.
	 *
	 * @param string $sync_id Optional sync ID.
	 * @param bool   $force Force sync.
	 * @return array
	 */
	public function ensure_categories_synced_if_due( $sync_id = '', $force = false ) {
		if ( ! $force && ! $this->rules->should_update_categories() ) {
			return $this->result(
				true,
				'آپدیت اتوماتیک دسته‌بندی‌ها غیرفعال است.',
				array(
					'skipped'  => true,
					'reason'   => 'disabled',
					'synced'   => false,
					'forced'   => (bool) $force,
				)
			);
		}

		$sync_id = sanitize_text_field( (string) $sync_id );

		if ( '' === $sync_id ) {
			$sync_id = 'category-refresh-' . gmdate( 'YmdHis' );
		}

		$last_sync_at = absint( get_option( 'mobo_core_categories_last_sync_at', 0 ) );

		$interval_hours = absint( get_option( 'mobo_core_categories_refresh_interval_hours', 12 ) );

		if ( $interval_hours <= 0 ) {
			$interval_hours = 12;
		}

		$interval_seconds = $interval_hours * HOUR_IN_SECONDS;
		$now              = time();

		if ( ! $force && $last_sync_at > 0 && ( $now - $last_sync_at ) < $interval_seconds ) {
			return $this->result(
				true,
				'هنوز زمان بروزرسانی دوره‌ای دسته‌بندی‌ها نرسیده است.',
				array(
					'skipped'        => true,
					'reason'         => 'not-due',
					'synced'         => false,
					'forced'         => false,
					'lastSyncAt'     => $last_sync_at,
					'nextSyncAt'     => $last_sync_at + $interval_seconds,
					'intervalHours'  => $interval_hours,
				)
			);
		}

		$api      = new Mobo_Core_API_Client();
		$response = $api->get_categories( $sync_id );

		if ( is_wp_error( $response ) ) {
			return $this->result(
				false,
				$response->get_error_message(),
				array(
					'skipped' => false,
					'synced'  => false,
					'forced'  => (bool) $force,
				)
			);
		}

		$category_result = $this->category_sync->sync_categories_payload( $response );

		update_option( 'mobo_core_categories_last_sync_at', $now, false );

		return $this->result(
			true,
			'دسته‌بندی‌ها بروزرسانی شدند.',
			array(
				'skipped'       => false,
				'synced'        => true,
				'forced'        => (bool) $force,
				'lastSyncAt'    => $now,
				'intervalHours' => $interval_hours,
				'created'       => absint( $category_result['created'] ),
				'updated'       => absint( $category_result['updated'] ),
				'skippedItems'  => absint( $category_result['skipped'] ),
			)
		);
	}

	private function save_manual_sync_state( $state ) {
		if ( ! is_array( $state ) ) {
			return false;
		}

		$incoming_status  = sanitize_key( (string) ( $state['status'] ?? '' ) );
		$incoming_sync_id = sanitize_text_field( (string) ( $state['syncId'] ?? '' ) );
		$current          = get_option( self::STATE_OPTION, array() );

		/*
		 * A running worker may have loaded the state before the admin clicked
		 * cancel. Without this guard, that stale worker can save its old
		 * "running" snapshot after the cancel request and make the UI look
		 * alive again. Once a syncId is cancelled, only another explicit start or
		 * resume action is allowed to move it out of cancelled state.
		 */
		if ( is_array( $current ) ) {
			$current_status  = sanitize_key( (string) ( $current['status'] ?? '' ) );
			$current_sync_id = sanitize_text_field( (string) ( $current['syncId'] ?? '' ) );

			if ( 'cancelled' === $current_status && 'cancelled' !== $incoming_status && '' !== $incoming_sync_id && $incoming_sync_id === $current_sync_id ) {
				return false;
			}
		}

		$state['updatedAt'] = time();
		update_option( self::STATE_OPTION, $state, false );

		return true;
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
		$state['currentVariantCursor']            = 0;
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
		$product_guid = $this->extract_product_guid( $data );

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
			$this->store_portal_product_id_on_product_object( $product, $data );
			$product->update_meta_data( 'mobo_sync_incomplete', '1' );

			$product_id = absint( $product->save() );

			if ( $product_id > 0 ) {
				$this->upsert_product_map( $product_guid, $product_id, true );
			}

			if ( $product_id <= 0 ) {
				return 0;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product ) {
				return 0;
			}
		} else {
			$product->update_meta_data( 'product_guid', $product_guid );
			$this->store_portal_product_id_on_product_object( $product, $data );
			$product->update_meta_data( 'mobo_sync_incomplete', '1' );
			$product_id = absint( $product->save() );
			$this->upsert_product_map( $product_guid, $product_id, true );
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
			$stock_present = false;
			$stock_value   = $this->get_stock_value_from_payload( $data, $stock_present );

			if ( $stock_present ) {
				$this->apply_api_stock( $product, $stock_value );
			} elseif ( $is_new_product ) {
				$this->apply_api_stock( $product, null );
			} else {
				$product->update_meta_data( '_mobo_stock_payload_missing', gmdate( 'c' ) );
			}
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
		$this->store_portal_product_id_on_product_object( $product, $data );

		$attributes = $this->build_product_attributes( $this->get_value( $data, 'attributes', array() ) );

		if ( ! empty( $attributes ) ) {
			$product->set_attributes( $attributes );
		} else {
			$product->set_attributes( array() );
		}

		$product->update_meta_data( 'mobo_sync_incomplete', '0' );

		$product_id = absint( $product->save() );

		if ( $product_id > 0 ) {
			$this->upsert_product_map( $product_guid, $product_id, false );
		}

		if ( $product_id <= 0 ) {
			return 0;
		}

		$this->store_product_attribute_guids( $product_id, $this->get_value( $data, 'attributes', array() ) );

		$product_category_refs = $this->get_product_category_refs_from_payload( $data );
		$this->store_product_category_refs( $product_id, $product_category_refs );

		$this->category_sync->assign_product_categories(
			$product_id,
			$product_category_refs,
			$this->rules->should_update_categories(),
			$is_new_product
		);

		if ( ! $skip_images && ( $is_new_product || $this->rules->should_update_images() ) ) {
			$this->image_sync->process_images(
				$product_id,
				$this->get_product_images_from_payload( $data ),
				0,
				false
			);
		}

		return $product_id;
	}

	private function upsert_variation( $parent, $data ) {
		if ( ! $parent instanceof WC_Product ) {
			return array(
				'id'      => 0,
				'changed' => false,
			);
		}

		$parent_id    = absint( $parent->get_id() );
		$variant_guid = $this->extract_variant_guid( $data );

		if ( $parent_id <= 0 || '' === $variant_guid ) {
			return array(
				'id'      => 0,
				'changed' => false,
			);
		}

		$product_guid  = $this->extract_product_guid( $data );
		$incoming_hash = $this->build_variation_source_hash( $data, $product_guid );

		if ( $this->is_remote_variation_trashed( $variant_guid ) ) {
			return array(
				'id'             => 0,
				'changed'        => false,
				'skipped_trash'  => true,
			);
		}

		$variation_id     = $this->find_variation_id_by_guid( $variant_guid );
		$is_new_variation = $variation_id <= 0;

		if ( ! $is_new_variation && $variation_id > 0 ) {
			$old_hash          = sanitize_text_field( (string) get_post_meta( $variation_id, '_mobo_variant_source_hash', true ) );
			$old_incomplete    = sanitize_text_field( (string) get_post_meta( $variation_id, 'mobo_sync_incomplete', true ) );
			$current_parent_id = absint( wp_get_post_parent_id( $variation_id ) );
			$current_parent_ok = 0 === $current_parent_id || $current_parent_id === $parent_id;

			if ( '' !== $incoming_hash && $old_hash === $incoming_hash && '1' !== $old_incomplete && $current_parent_ok ) {
				if ( ! $this->variation_stock_matches_payload( $variation_id, $data ) ) {
					update_post_meta( $variation_id, '_mobo_hash_skip_bypassed_reason', 'stock-mismatch' );
					update_post_meta( $variation_id, '_mobo_hash_skip_bypassed_at', gmdate( 'c' ) );
				} else {
					$this->store_portal_ids_on_variation_post( $variation_id, $data, $product_guid, $parent_id );
					$this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, false );

					return array(
						'id'      => $variation_id,
						'changed' => false,
					);
				}
			}
		}

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
			$this->store_portal_variant_id_on_product_object( $variation, $data );
			$this->store_portal_product_id_on_product_object( $variation, $data, $parent_id );
			$variation->update_meta_data( 'mobo_sync_incomplete', '1' );

			if ( '' !== $product_guid ) {
				$variation->update_meta_data( 'product_guid', $product_guid );
			}

			$variation_id = absint( $variation->save() );

			if ( $variation_id > 0 ) {
				$this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, true );
			}

			if ( $variation_id <= 0 ) {
				return array(
					'id'      => 0,
					'changed' => false,
				);
			}

			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				return array(
					'id'      => 0,
					'changed' => false,
				);
			}
		} else {
			$variation->set_parent_id( $parent_id );
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
			$stock_present = false;
			$stock_value   = $this->get_stock_value_from_payload( $data, $stock_present );

			if ( $stock_present ) {
				$this->apply_api_stock( $variation, $stock_value );
			} elseif ( $is_new_variation ) {
				$this->apply_api_stock( $variation, null );
			} else {
				$variation->update_meta_data( '_mobo_stock_payload_missing', gmdate( 'c' ) );
			}
		}

		$attrs = $this->normalize_variation_attributes( $this->get_value( $data, 'attributes', array() ) );

		if ( ! empty( $attrs ) ) {
			$variation->set_attributes( $attrs );
		}

		$variation->update_meta_data( 'variant_guid', $variant_guid );
		$this->store_portal_variant_id_on_product_object( $variation, $data );
		$this->store_portal_product_id_on_product_object( $variation, $data, $parent_id );

		if ( '' !== $product_guid ) {
			$variation->update_meta_data( 'product_guid', $product_guid );
		}

		$variation->update_meta_data( '_mobo_variant_source_hash', $incoming_hash );
		$variation->update_meta_data( '_mobo_variant_source_hash_updated_at', gmdate( 'c' ) );
		$variation->update_meta_data( 'mobo_sync_incomplete', '0' );

		$variation_id = absint( $variation->save() );

		if ( $variation_id > 0 ) {
			$this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, false );
		}

		return array(
			'id'      => $variation_id,
			'changed' => $variation_id > 0,
		);
	}



	private function store_portal_product_id_on_product_object( $product, $data, $fallback_product_id = 0 ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$portal_product_id = $this->extract_portal_product_id( $data );

		if ( $portal_product_id <= 0 && $fallback_product_id > 0 ) {
			$portal_product_id = absint( get_post_meta( absint( $fallback_product_id ), 'portal_product_id', true ) );
		}

		if ( $portal_product_id <= 0 ) {
			return;
		}

		$product->update_meta_data( 'portal_product_id', $portal_product_id );
		$product->update_meta_data( 'mobo_portal_product_id', $portal_product_id );
		$product->update_meta_data( '_mobo_portal_product_id', $portal_product_id );
	}

	private function store_portal_variant_id_on_product_object( $product, $data ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$portal_variant_id = $this->extract_portal_variant_id( $data );

		if ( $portal_variant_id <= 0 ) {
			return;
		}

		$product->update_meta_data( 'portal_variant_id', $portal_variant_id );
		$product->update_meta_data( 'mobo_portal_variant_id', $portal_variant_id );
		$product->update_meta_data( '_mobo_portal_variant_id', $portal_variant_id );
	}

	private function store_portal_ids_on_variation_post( $variation_id, $data, $product_guid = '', $parent_id = 0 ) {
		$variation_id = absint( $variation_id );

		if ( $variation_id <= 0 ) {
			return;
		}

		$portal_variant_id = $this->extract_portal_variant_id( $data );

		if ( $portal_variant_id > 0 ) {
			update_post_meta( $variation_id, 'portal_variant_id', $portal_variant_id );
			update_post_meta( $variation_id, 'mobo_portal_variant_id', $portal_variant_id );
			update_post_meta( $variation_id, '_mobo_portal_variant_id', $portal_variant_id );
		}

		$portal_product_id = $this->extract_portal_product_id( $data );

		if ( $portal_product_id <= 0 && $parent_id > 0 ) {
			$portal_product_id = absint( get_post_meta( absint( $parent_id ), 'portal_product_id', true ) );
		}

		if ( $portal_product_id > 0 ) {
			update_post_meta( $variation_id, 'portal_product_id', $portal_product_id );
			update_post_meta( $variation_id, 'mobo_portal_product_id', $portal_product_id );
			update_post_meta( $variation_id, '_mobo_portal_product_id', $portal_product_id );
		}

		if ( '' !== $product_guid ) {
			update_post_meta( $variation_id, 'product_guid', sanitize_text_field( (string) $product_guid ) );
		}
	}

	private function build_variation_source_hash( $data, $product_guid = '' ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$hash_data = array(
			'variantId'       => $this->extract_variant_guid( $data ),
			'productId'       => sanitize_text_field( (string) $product_guid ),
			'attributes'      => $this->get_value( $data, 'attributes', array() ),
			'updateTitle'     => $this->rules->should_update_title() ? 1 : 0,
			'updatePrice'     => $this->rules->should_update_price() ? 1 : 0,
			'updateCompare'   => $this->rules->should_update_compare_price() ? 1 : 0,
			'updateStock'     => $this->rules->should_update_stock() ? 1 : 0,
			'pricePolicyHash' => $this->build_price_policy_hash(),
		);

		if ( $this->rules->should_update_title() ) {
			$hash_data['title'] = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );
		}

		if ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) {
			$hash_data['price']        = $this->get_value( $data, 'price', null );
			$hash_data['comparePrice'] = $this->get_value( $data, 'comparePrice', null );
		}

		if ( $this->rules->should_update_stock() ) {
			$stock_present      = false;
			$hash_data['stock'] = $this->get_stock_value_from_payload( $data, $stock_present );
			$hash_data['stockPresent'] = $stock_present ? 1 : 0;
		}

		$hash_data = $this->sort_array_for_hash( $hash_data );
		$json      = wp_json_encode( $hash_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false === $json ? '' : md5( $json );
	}

	private function build_price_policy_hash() {
		$policy = array(
			'mobo_price_type'                   => (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' ),
			'global_additional_price'           => (string) Mobo_Core_Settings::get( 'global_additional_price', 0 ),
			'global_product_auto_compare_price' => (string) Mobo_Core_Settings::get( 'global_product_auto_compare_price', '0' ),
			'mobo_dynamic_price'                => (string) Mobo_Core_Settings::get( 'mobo_dynamic_price', '[]' ),
		);

		$json = wp_json_encode( $this->sort_array_for_hash( $policy ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false === $json ? '' : md5( $json );
	}

	private function sort_array_for_hash( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! $this->is_list_array( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->sort_array_for_hash( $item );
		}

		return $value;
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

	private function is_authoritative_variant_list_payload( $payload, $variants ) {
		if ( ! is_array( $payload ) ) {
			return false;
		}

		$explicit_keys = array(
			'variantListAuthoritative',
			'variant_list_authoritative',
			'authoritativeVariantList',
			'authoritative_variant_list',
			'isFullVariantSnapshot',
			'is_full_variant_snapshot',
			'fullVariantSync',
			'full_variant_sync',
		);

		foreach ( $explicit_keys as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				return $this->to_bool( $payload[ $key ] );
			}
		}

		/*
		 * Webhook UpdateVariant messages are deltas, not full snapshots. A payload like
		 * { event: UpdateVariant, data: { totalCount: 1, data: [one variant] } }
		 * must never mark the other product variations as missing/out-of-stock.
		 */
		$event = strtolower( sanitize_text_field( (string) $this->get_value( $payload, 'event', '' ) ) );

		if ( 'updatevariant' === $event ) {
			return false;
		}

		$entity_type = strtolower( sanitize_text_field( (string) $this->get_value( $payload, 'entityType', $this->get_value( $payload, 'entity_type', '' ) ) ) );

		if ( 'variant' === $entity_type ) {
			return false;
		}

		/*
		 * Safety rule: UpdateVariant payloads are treated as delta/webhook updates unless
		 * they explicitly opt in via variantListAuthoritative/isFullVariantSnapshot.
		 * This prevents four single-variant webhooks from marking each other's variants
		 * as missing/out-of-stock. Full manual/API syncs set the explicit flag above.
		 */
		return false;
	}

	private function normalize_variant_items_from_response( $response ) {
		$items = $this->get_value( $response, 'data', array() );

		if ( is_array( $items ) && ! $this->is_list_array( $items ) ) {
			$nested_items = $this->get_value( $items, 'data', null );
			if ( is_array( $nested_items ) ) {
				$items = $nested_items;
			}
		}

		return is_array( $items ) ? $items : array();
	}

	private function get_stock_value_from_payload( $data, &$present = false ) {
		$present = false;

		if ( ! is_array( $data ) ) {
			return null;
		}

		$keys = array(
			'stock',
			'Stock',
			'stock_quantity',
			'stockQuantity',
			'quantity',
			'Quantity',
			'inventory',
			'inventoryQuantity',
		);

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$present = true;
				return $data[ $key ];
			}
		}

		return null;
	}

	private function variation_stock_matches_payload( $variation_id, $data ) {
		if ( ! $this->rules->should_update_stock() ) {
			return true;
		}

		$stock_present = false;
		$stock_value   = $this->get_stock_value_from_payload( $data, $stock_present );

		if ( ! $stock_present ) {
			return true;
		}

		$normalized = $this->normalize_api_stock_quantity( $stock_value );

		if ( null === $normalized ) {
			return true;
		}

		$variation = wc_get_product( absint( $variation_id ) );

		if ( ! $variation instanceof WC_Product_Variation ) {
			return false;
		}

		$current_quantity = $variation->get_stock_quantity();
		$current_status   = (string) $variation->get_stock_status();
		$expected_status   = $normalized > 0 ? 'instock' : 'outofstock';

		if ( ! $variation->get_manage_stock() ) {
			return false;
		}

		if ( null === $current_quantity || (int) $current_quantity !== (int) $normalized ) {
			return false;
		}

		if ( $current_status !== $expected_status ) {
			return false;
		}

		return true;
	}

	private function normalize_api_stock_quantity( $stock ) {
		if ( null === $stock || '' === $stock || ! is_scalar( $stock ) ) {
			return null;
		}

		$raw_stock        = trim( (string) $stock );
		$normalized_stock = str_replace( ',', '.', $raw_stock );

		if ( '' === $raw_stock || ! is_numeric( $normalized_stock ) ) {
			return null;
		}

		return max( 0, (int) floor( (float) $normalized_stock ) );
	}

	private function apply_api_stock( $product, $stock ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( null === $stock || '' === $stock ) {
			$product->set_manage_stock( false );
			$product->set_stock_quantity( null );
			$product->set_stock_status( 'instock' );
			$product->update_meta_data( '_mobo_last_api_stock_raw', '' );
			$product->update_meta_data( '_mobo_stock_update_source', 'api-empty-stock' );
			return;
		}

		$raw_stock      = is_scalar( $stock ) ? trim( (string) $stock ) : '';
		$stock_quantity = $this->normalize_api_stock_quantity( $stock );

		if ( null === $stock_quantity ) {
			$product->update_meta_data( '_mobo_last_api_stock_raw', sanitize_text_field( $raw_stock ) );
			$product->update_meta_data( '_mobo_stock_update_source', 'api-invalid-stock-skipped' );
			$product->update_meta_data( '_mobo_stock_update_skipped_at', gmdate( 'c' ) );
			return;
		}

		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock_quantity );
		$product->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
		$product->update_meta_data( '_mobo_last_api_stock_raw', sanitize_text_field( $raw_stock ) );
		$product->update_meta_data( '_mobo_last_api_stock_quantity', $stock_quantity );
		$product->update_meta_data( '_mobo_last_api_stock_applied_at', gmdate( 'c' ) );
		$product->update_meta_data( '_mobo_stock_update_source', 'api-stock' );
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

		$raw_price         = $this->get_value( $data, 'price', null );
		$raw_compare_price = $this->get_value( $data, 'comparePrice', null );

		$product->update_meta_data( 'mobo_api_price', null === $raw_price || '' === $raw_price ? '' : wc_format_decimal( $raw_price ) );
		$product->update_meta_data( 'mobo_api_compare_price', null === $raw_compare_price || '' === $raw_compare_price ? '' : wc_format_decimal( $raw_compare_price ) );
		$product->update_meta_data( 'mobo_price_policy_type', (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' ) );
		$product->update_meta_data( 'mobo_price_policy_updated_at', gmdate( 'c' ) );

		$pair = $this->price_calculator->calculate_price_pair(
			absint( $product->get_id() ),
			$raw_price,
			$raw_compare_price,
			$context
		);

		if ( null !== $pair['regular_price'] && '' !== $pair['regular_price'] ) {
			$product->set_regular_price( $pair['regular_price'] );
			$product->update_meta_data( 'mobo_calculated_regular_price', $pair['regular_price'] );
		}

		if ( isset( $pair['sale_price'] ) ) {
			$product->set_sale_price( $pair['sale_price'] );
			$product->update_meta_data( 'mobo_calculated_sale_price', $pair['sale_price'] );
		}
	}


	/**
	 * Treat temporary API/HTTP failures as retryable manual-sync errors.
	 *
	 * A single timeout must not poison the sync state with lastError, because
	 * get_manual_sync_status() stops the self-runner when lastError is not empty.
	 *
	 * @param array    $state   Current manual sync state.
	 * @param WP_Error $error   Request error.
	 * @param string   $message Human message.
	 * @return array
	 */
	private function handle_transient_request_error( &$state, $error, $message ) {
		$try_count = absint( $state['transientRetryCount'] ?? 0 ) + 1;
		$max_try   = Mobo_Core_Settings::get_int( 'mobo_core_transient_retry_max_try', 10, 1, 50 );
		$error_msg = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;

		$state['transientRetryCount'] = $try_count;
		$state['lastTransientError']  = sanitize_text_field( $error_msg );
		$state['updatedAt']           = time();

		if ( $try_count >= $max_try ) {
			$delay_seconds = Mobo_Core_Settings::get_int( 'mobo_core_waiting_for_portal_retry_delay_seconds', 60, 10, 3600 );

			$state['status']                = 'waiting_for_portal';
			$state['waitingForPortalSince'] = empty( $state['waitingForPortalSince'] ) ? time() : absint( $state['waitingForPortalSince'] );
			$state['nextRetryAt']           = time() + $delay_seconds;
			$state['lastError']             = '';
			$state['lastMessage']           = sprintf( '%s اتصال به Portal پس از %d تلاش برقرار نشد. sync متوقف نشده؛ از همین نقطه در تلاش بعدی ادامه می‌دهد.', $message, $max_try );
			$this->save_manual_sync_state( $state );

			return $this->result( true, $state['lastMessage'] . ' ' . $error_msg, $this->get_manual_sync_status() );
		}

		/* Keep lastError empty so the self-runner keeps the sync resumable. */
		$state['status']      = 'running';
		$state['nextRetryAt'] = 0;
		$state['lastError']   = '';
		$state['lastMessage'] = sprintf( '%s خطای موقت؛ تلاش مجدد %d از %d. %s', $message, $try_count, $max_try, $error_msg );
		$this->save_manual_sync_state( $state );

		return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
	}

	/**
	 * Clear transient request retry state after a successful API response.
	 *
	 * @param array $state Current state.
	 * @return void
	 */
	private function clear_transient_request_error( &$state ) {
		$state['transientRetryCount']   = 0;
		$state['lastTransientError']    = '';
		$state['lastError']             = '';
		$state['waitingForPortalSince'] = 0;
		$state['nextRetryAt']           = 0;
		if ( 'waiting_for_portal' === sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
			$state['status'] = 'running';
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

			$guid = $this->extract_attribute_guid( $attribute_data );
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


	/**
	 * Extract attribute GUID from an attribute payload.
	 *
	 * @param array $attribute_data Attribute payload.
	 * @return string
	 */
	private function extract_attribute_guid( $attribute_data ) {
		if ( ! is_array( $attribute_data ) ) {
			return '';
		}

		$keys = array( 'attribute_guid', 'attributeGuid', 'attributeId', 'guid', 'remote_guid', 'remoteGuid', 'id' );

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $attribute_data, $key, '' ) );

			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		return '';
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

			if ( in_array( get_post_status( $variation_id ), array( 'trash', 'auto-draft' ), true ) ) {
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

	private function is_remote_product_trashed( $guid ) {
		return $this->remote_post_has_blocked_status( $guid, 'product', 'product_guid', 'product' );
	}

	private function is_remote_variation_trashed( $guid ) {
		return $this->remote_post_has_blocked_status( $guid, 'product_variation', 'variant_guid', 'variation' );
	}

	private function remote_post_has_blocked_status( $guid, $post_type, $meta_key, $object_type ) {
		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid ) {
			return false;
		}

		$status = '';

		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			if ( 'product' === $object_type && method_exists( $this->product_map, 'get_product_post_status' ) ) {
				$status = $this->product_map->get_product_post_status( $guid );
			} elseif ( 'variation' === $object_type && method_exists( $this->product_map, 'get_variation_post_status' ) ) {
				$status = $this->product_map->get_variation_post_status( $guid );
			}
		}

		if ( in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
			return true;
		}

		return $this->find_blocked_post_id_by_meta( $post_type, $meta_key, $guid ) > 0;
	}

	private function find_blocked_post_id_by_meta( $post_type, $meta_key, $meta_value ) {
		$meta_value = sanitize_text_field( (string) $meta_value );

		if ( '' === $meta_value ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => sanitize_key( $post_type ),
				'post_status'            => array( 'trash', 'auto-draft' ),
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

	private function find_product_id_by_guid( $guid ) {
		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid ) {
			return 0;
		}

		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			$product_id = $this->product_map->get_product_id( $guid );

			if ( $product_id > 0 ) {
				return $product_id;
			}
		}

		$product_id = $this->find_post_id_by_meta( 'product', 'product_guid', $guid );

		if ( $product_id > 0 ) {
			$this->upsert_product_map( $guid, $product_id, false );
		}

		return $product_id;
	}

	private function find_variation_id_by_guid( $guid ) {
		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid ) {
			return 0;
		}

		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			$variation_id = $this->product_map->get_variation_id( $guid );

			if ( $variation_id > 0 ) {
				return $variation_id;
			}
		}

		$variation_id = $this->find_post_id_by_meta( 'product_variation', 'variant_guid', $guid );

		if ( $variation_id > 0 ) {
			$parent_guid = sanitize_text_field( (string) get_post_meta( $variation_id, 'product_guid', true ) );
			$this->upsert_variation_map( $guid, $variation_id, $parent_guid, false );
		}

		return $variation_id;
	}

	/**
	 * Persist product GUID map if the table is available.
	 *
	 * @param string $product_guid Remote product GUID.
	 * @param int    $product_id Product ID.
	 * @param bool   $sync_incomplete Sync incomplete.
	 * @return void
	 */
	private function upsert_product_map( $product_guid, $product_id, $sync_incomplete = false ) {
		if ( ! ( $this->product_map instanceof Mobo_Core_Product_Map ) ) {
			return;
		}

		$this->product_map->upsert_product( $product_guid, $product_id, '', $sync_incomplete );
	}

	/**
	 * Persist variation GUID map if the table is available.
	 *
	 * @param string $variant_guid Remote variant GUID.
	 * @param int    $variation_id Variation ID.
	 * @param string $product_guid Parent remote product GUID.
	 * @param bool   $sync_incomplete Sync incomplete.
	 * @return void
	 */
	private function upsert_variation_map( $variant_guid, $variation_id, $product_guid = '', $sync_incomplete = false ) {
		if ( ! ( $this->product_map instanceof Mobo_Core_Product_Map ) ) {
			return;
		}

		$this->product_map->upsert_variation( $variant_guid, $variation_id, $product_guid, '', $sync_incomplete );
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

	private function count_seen_variants( $product_guid, $sync_id ) {
		$seen = get_option( $this->seen_option_name( $product_guid, $sync_id ), array() );

		return is_array( $seen ) ? count( $seen ) : 0;
	}


	/**
	 * Deprecated: product identity is GUID-only and must be present in payload.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function extract_product_guid_from_url( $url ) {
		return '';
	}

	private function build_missing_product_id_message( $payload, $variants ) {
		$payload_keys = is_array( $payload ) ? implode( ',', array_slice( array_keys( $payload ), 0, 20 ) ) : '';
		$data_keys    = '';
		$variant_keys = '';

		$data = $this->get_value( $payload, 'data', null );
		if ( is_array( $data ) ) {
			$data_keys = implode( ',', array_slice( array_keys( $data ), 0, 20 ) );
		}

		if ( is_array( $variants ) && isset( $variants[0] ) && is_array( $variants[0] ) ) {
			$variant_keys = implode( ',', array_slice( array_keys( $variants[0] ), 0, 20 ) );
		}

		$pulled_from = is_array( $payload ) ? (string) $this->get_value( $payload, '_moboPulledFrom', '' ) : '';

		return sprintf(
			'productId is required. PayloadKeys=%s DataKeys=%s FirstVariantKeys=%s PulledFrom=%s',
			$payload_keys,
			$data_keys,
			$variant_keys,
			$pulled_from
		);
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




	private function extract_portal_product_id( $data ) {
		return $this->extract_positive_int_from_payload(
			$data,
			array(
				'portal_product_id',
				'portalProductId',
				'PortalProductId',
				'portal_product_id_api',
				'portalId',
			)
		);
	}

	private function extract_portal_variant_id( $data ) {
		return $this->extract_positive_int_from_payload(
			$data,
			array(
				'portal_variant_id',
				'portalVariantId',
				'PortalVariantId',
				'portalVariantID',
			)
		);
	}

	private function extract_positive_int_from_payload( $data, $keys ) {
		if ( ! is_array( $data ) || ! is_array( $keys ) ) {
			return 0;
		}

		foreach ( $keys as $key ) {
			$value = $this->get_value( $data, $key, null );

			if ( null === $value || '' === $value || is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );

			if ( '' === $value || ! preg_match( '/^\d+$/', $value ) ) {
				continue;
			}

			$int_value = absint( $value );

			if ( $int_value > 0 ) {
				return $int_value;
			}
		}

		return 0;
	}

	/**
	 * Extract remote product GUID from a payload.
	 *
	 * @param array $data Payload.
	 * @return string
	 */
	private function extract_product_guid( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$keys = array( 'product_guid', 'productGuid', 'productId', 'parent_product_guid', 'parentProductId', 'parentGuid', 'remote_guid', 'remoteGuid', 'entity_guid', 'entityGuid', 'entityId', 'id' );

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $data, $key, '' ) );
			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Extract remote variation GUID from a payload.
	 *
	 * @param array $data Payload.
	 * @return string
	 */
	private function extract_variant_guid( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$keys = array( 'variant_guid', 'variantGuid', 'variantId', 'guid', 'remote_guid', 'remoteGuid', 'entity_guid', 'entityGuid', 'entity_id', 'entityId', 'id' );

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $data, $key, '' ) );
			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		return '';
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
	 * Return the first non-empty scalar value.
	 *
	 * @param array $values Values.
	 * @return string
	 */
	private function first_non_empty( $values ) {
		foreach ( (array) $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Determine whether an array is a zero-based list.
	 *
	 * @param array $array Array.
	 * @return bool
	 */
	private function is_list_array( $array ) {
		if ( ! is_array( $array ) ) {
			return false;
		}

		$expected = 0;
		foreach ( array_keys( $array ) as $key ) {
			if ( $key !== $expected ) {
				return false;
			}
			$expected++;
		}

		return true;
	}

	/**
	 * Resolve parent product GUID from a known remote variant GUID.
	 *
	 * @param string $variant_guid Remote variant GUID.
	 * @return string
	 */
	private function find_parent_product_guid_by_variant_guid( $variant_guid ) {
		global $wpdb;

		$variant_guid = sanitize_text_field( (string) $variant_guid );
		if ( '' === $variant_guid || ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists() ) {
			return '';
		}

		$table = Mobo_Core_Product_Map::table_name();
		$parent_guid = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT parent_remote_guid FROM {$table} WHERE remote_guid = %s AND object_type = %s LIMIT 1",
				$variant_guid,
				Mobo_Core_Product_Map::TYPE_VARIATION
			)
		);

		return sanitize_text_field( (string) $parent_guid );
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
 * Return product category refs from all supported payload field names.
 *
 * Identity must be based on category GUIDs, but the collection name can differ
 * between .NET serializers or older payload versions.
 *
 * @param array $data Product payload.
 * @return array
 */
private function get_product_category_refs_from_payload( $data ) {
	if ( ! is_array( $data ) ) {
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
		$value = $this->get_value( $data, $key, null );

		if ( is_array( $value ) && ! empty( $value ) ) {
			return $value;
		}
	}

	return array();
}


/**
 * Store product category refs for later category reapply runs.
 *
 * @param int   $product_id Product ID.
 * @param mixed $category_refs Raw category refs.
 * @return void
 */
private function store_product_category_refs( $product_id, $category_refs ) {
	$product_id = absint( $product_id );

	if ( $product_id <= 0 ) {
		return;
	}

	if ( ! is_array( $category_refs ) ) {
		delete_post_meta( $product_id, 'mobo_product_category_refs_json' );
		delete_post_meta( $product_id, 'mobo_product_category_guids' );
		return;
	}

	$normalized = array();
	$guids      = array();

	foreach ( $category_refs as $ref ) {
		if ( ! is_array( $ref ) ) {
			$guid = sanitize_text_field( (string) $ref );

			if ( $this->is_remote_guid_value( $guid ) ) {
				$normalized[] = array( 'id' => $guid );
				$guids[]      = $guid;
			}

			continue;
		}

		$guid = $this->extract_category_guid_for_storage( $ref );

		if ( '' === $guid ) {
			continue;
		}

		$normalized[] = array(
			'id'       => $guid,
			'title'    => sanitize_text_field( (string) $this->get_value( $ref, 'title', '' ) ),
			'url'      => sanitize_text_field( (string) $this->get_value( $ref, 'url', '' ) ),
			'parentId' => sanitize_text_field( (string) $this->get_value( $ref, 'parentId', '' ) ),
		);
		$guids[] = $guid;
	}

	if ( empty( $normalized ) ) {
		delete_post_meta( $product_id, 'mobo_product_category_refs_json' );
		delete_post_meta( $product_id, 'mobo_product_category_guids' );
		return;
	}

	update_post_meta( $product_id, 'mobo_product_category_refs_json', wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	update_post_meta( $product_id, 'mobo_product_category_guids', array_values( array_unique( array_filter( $guids ) ) ) );
}

/**
 * Extract category GUID for storage.
 *
 * @param array $ref Category ref.
 * @return string
 */
private function extract_category_guid_for_storage( $ref ) {
	$guids = $this->collect_category_guid_candidates_for_storage( $ref );

	return ! empty( $guids ) ? sanitize_text_field( (string) $guids[0] ) : '';
}

/**
 * Collect category GUID candidates for storage, preferring actual category GUIDs over relation IDs.
 *
 * @param mixed $ref Category ref.
 * @return array
 */
private function collect_category_guid_candidates_for_storage( $ref ) {
	$guids = array();

	if ( ! is_array( $ref ) ) {
		$value = sanitize_text_field( (string) $ref );
		return $this->is_remote_guid_value( $value ) ? array( $value ) : array();
	}

	$primary_keys = array( 'category_guid', 'categoryGuid', 'categoryId', 'categoryGUID', 'guid', 'remote_guid', 'remoteGuid', 'portal_category_id', 'portalCategoryId', 'category_portal_id', 'categoryPortalId' );
	foreach ( $primary_keys as $key ) {
		$this->append_category_guid_candidate_for_storage( $guids, $this->get_value( $ref, $key, '' ) );
	}

	$nested = $this->get_value( $ref, 'category', null );
	if ( is_array( $nested ) ) {
		foreach ( $this->collect_category_guid_candidates_for_storage( $nested ) as $nested_guid ) {
			$this->append_category_guid_candidate_for_storage( $guids, $nested_guid );
		}
	} else {
		$this->append_category_guid_candidate_for_storage( $guids, $nested );
	}

	$fallback_keys = array( 'product_category_id', 'productCategoryId', 'product_category_guid', 'productCategoryGuid', 'id' );
	foreach ( $fallback_keys as $key ) {
		$this->append_category_guid_candidate_for_storage( $guids, $this->get_value( $ref, $key, '' ) );
	}

	return array_values( array_unique( array_filter( $guids ) ) );
}

/**
 * Append storage GUID candidate.
 *
 * @param array $guids GUID list.
 * @param mixed $value Raw value.
 * @return void
 */
private function append_category_guid_candidate_for_storage( &$guids, $value ) {
	$value = trim( sanitize_text_field( (string) $value ) );
	if ( '' !== $value && $this->is_remote_guid_value( $value ) ) {
		$guids[] = $value;
	}
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