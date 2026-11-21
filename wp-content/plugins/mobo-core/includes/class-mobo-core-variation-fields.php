<?php
/**
 * WooCommerce variation custom fields.
 *
 * Adds mobo_additional_price to Mobo-owned variations only. This local Woo
 * setting is the highest-priority valid pricing override for the variation;
 * durable ownership itself remains authoritative in Custom Storage.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Variation_Fields {

	/** @var array<int,array> */
	private $context_cache = array();

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_additional_price_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_additional_price_field' ), 10, 2 );
	}

	/**
	 * Render additional price field in WooCommerce variation pricing section.
	 *
	 * The field is deliberately absent on manual/non-Mobo variations. In
	 * terminal Custom-Storage mode, an unavailable ownership authority also
	 * fails closed rather than falling back to retired identity postmeta.
	 *
	 * @param int     $loop Variation loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation Variation post.
	 * @return void
	 */
	public function render_additional_price_field( $loop, $variation_data, $variation ) {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		$variation_id = isset( $variation->ID ) ? absint( $variation->ID ) : 0;
		if ( $variation_id <= 0 ) {
			return;
		}

		$context = $this->resolve_variation_context( $variation_id );
		if ( empty( $context['available'] ) || empty( $context['isMobo'] ) ) {
			return;
		}

		$value       = get_post_meta( $variation_id, 'mobo_additional_price', true );
		$description = 'بالاترین اولویت قیمت‌گذاری این واریانت است: این مبلغ به قیمت منبع اضافه می‌شود و سود اختصاصی درصدی محصول/والد و قانون عمومی را برای همین واریانت کنار می‌زند. خالی یا ۰ یعنی بدون سود اختصاصی واریانت.';

		if ( ! empty( $context['connectionLabel'] ) ) {
			$description .= ' اتصال مالک: ' . $context['connectionLabel'] . '.';
		}
		if ( ! empty( $context['excluded'] ) ) {
			$description .= ' این محصول اکنون از mutationهای منبع مستثناست؛ مقدار ذخیره می‌شود ولی تا بازگشت محصول به جریان قیمت‌گذاری اعمال نخواهد شد.';
		} elseif ( empty( $context['mutationReady'] ) ) {
			$description .= ' اتصال مالک اکنون برای mutation آماده نیست؛ مقدار محلی ذخیره می‌شود و پس از آماده‌شدن اتصال در اجرای بعدی قیمت‌گذاری اثر می‌کند.';
		} else {
			$description .= ' تغییر این فیلد قیمت فعلی را همان لحظه بازمحاسبه نمی‌کند؛ در اجرای بعدی Sync/Reprice/Repair که قیمت را اعمال می‌کند اثر خواهد کرد.';
		}

		woocommerce_wp_text_input(
			array(
				'id'                => 'mobo_additional_price_' . absint( $loop ),
				'name'              => 'mobo_additional_price[' . absint( $loop ) . ']',
				'value'             => '' === $value ? '' : esc_attr( $value ),
				'label'             => 'سود اختصاصی موبو (مبلغ)',
				'desc_tip'          => true,
				'description'       => $description,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'wrapper_class'     => 'form-row form-row-full',
			)
		);
	}

	/**
	 * Save additional price field.
	 *
	 * WooCommerce saves variations both from the normal product form and via
	 * its dedicated save-variations AJAX endpoint. Accept only the nonce that
	 * WooCommerce itself uses for the active route, then independently verify
	 * variation ownership before mutating the local override.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop Variation loop index.
	 * @return void
	 */
	public function save_additional_price_field( $variation_id, $loop ) {
		$variation_id = absint( $variation_id );
		$loop         = absint( $loop );

		if ( $variation_id <= 0 || 'product_variation' !== get_post_type( $variation_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $variation_id ) && ! current_user_can( 'edit_products' ) ) {
			return;
		}
		if ( ! $this->request_is_authorized() ) {
			return;
		}

		/* A partial/paginated payload that does not include this field must never
		 * be interpreted as an instruction to clear an existing override. */
		if ( ! isset( $_POST['mobo_additional_price'] ) || ! is_array( $_POST['mobo_additional_price'] ) || ! array_key_exists( $loop, $_POST['mobo_additional_price'] ) ) {
			return;
		}

		$context = $this->resolve_variation_context( $variation_id );
		if ( empty( $context['available'] ) || empty( $context['isMobo'] ) ) {
			$this->add_admin_error( 'سود اختصاصی موبو ذخیره نشد؛ مالکیت معتبر این واریانت در MoboCore قابل اثبات نیست.' );
			return;
		}

		$posted = wp_unslash( $_POST['mobo_additional_price'][ $loop ] );
		if ( ! is_scalar( $posted ) ) {
			$this->add_admin_error( 'سود اختصاصی موبو باید یک عدد صحیح صفر یا بزرگ‌تر باشد.' );
			return;
		}

		$value = trim( sanitize_text_field( (string) $posted ) );
		if ( '' === $value || '0' === $value ) {
			if ( ! $this->delete_override_verified( $variation_id ) ) {
				$this->add_admin_error( 'حذف سود اختصاصی موبو قابل تأیید نبود؛ مقدار قبلی حفظ/بازبینی شود.' );
				return;
			}
			$this->queue_cache_purge( $variation_id, 'variation-additional-price-removed' );
			return;
		}

		if ( ! preg_match( '/^[0-9]+$/', $value ) ) {
			$this->add_admin_error( 'سود اختصاصی موبو باید عدد صحیح بدون علامت، اعشار یا جداکننده باشد. مقدار قبلی تغییر نکرد.' );
			return;
		}
		$normalized = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $normalized ) {
			$this->add_admin_error( 'سود اختصاصی موبو خارج از محدوده عدد صحیح معتبر است. مقدار قبلی تغییر نکرد.' );
			return;
		}
		$normalized = (int) $normalized;

		$current = get_post_meta( $variation_id, 'mobo_additional_price', true );
		if ( (string) $normalized === (string) $current ) {
			return;
		}

		update_post_meta( $variation_id, 'mobo_additional_price', $normalized );
		clean_post_cache( $variation_id );
		if ( (string) $normalized !== (string) get_post_meta( $variation_id, 'mobo_additional_price', true ) ) {
			$this->add_admin_error( 'ذخیره سود اختصاصی موبو قابل تأیید نبود؛ قیمت‌گذاری بعدی نباید بر مقدار جدید تکیه کند.' );
			return;
		}

		$this->queue_cache_purge( $variation_id, 'variation-additional-price-updated' );
	}

	/** @return bool */
	private function request_is_authorized() {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
			if ( 'woocommerce_save_variations' === $action ) {
				$nonce = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
				return '' !== $nonce && (bool) wp_verify_nonce( $nonce, 'save-variations' );
			}
		}

		$nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ) : '';
		return '' !== $nonce && (bool) wp_verify_nonce( $nonce, 'woocommerce_save_data' );
	}

	/** @return bool */
	private function delete_override_verified( $variation_id ) {
		$current = get_post_meta( $variation_id, 'mobo_additional_price', true );
		if ( '' === (string) $current ) {
			return true;
		}
		delete_post_meta( $variation_id, 'mobo_additional_price' );
		clean_post_cache( $variation_id );
		return '' === (string) get_post_meta( $variation_id, 'mobo_additional_price', true );
	}

	/** @return void */
	private function queue_cache_purge( $variation_id, $reason ) {
		if ( class_exists( 'Mobo_Core_Cache_Purger' ) ) {
			Mobo_Core_Cache_Purger::queue_product( absint( $variation_id ), sanitize_key( (string) $reason ) );
		}
	}

	/** @return void */
	private function add_admin_error( $message ) {
		$message = sanitize_text_field( (string) $message );
		if ( '' === $message ) {
			return;
		}
		if ( class_exists( 'WC_Admin_Meta_Boxes' ) && is_callable( array( 'WC_Admin_Meta_Boxes', 'add_error' ) ) ) {
			WC_Admin_Meta_Boxes::add_error( $message );
		}
	}

	/**
	 * Resolve one variation against installation-global durable ownership.
	 *
	 * @param int $variation_id Woo variation ID.
	 * @return array
	 */
	private function resolve_variation_context( $variation_id ) {
		$variation_id = absint( $variation_id );
		if ( $variation_id <= 0 ) {
			return $this->empty_context( false );
		}
		if ( isset( $this->context_cache[ $variation_id ] ) ) {
			return $this->context_cache[ $variation_id ];
		}

		$parent_id = absint( wp_get_post_parent_id( $variation_id ) );
		$terminal  = class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced();
		$context   = $this->empty_context( true );
		$context['parentId'] = $parent_id;

		if ( $terminal ) {
			$authority_ready = class_exists( 'Mobo_Core_Storage_Schema' )
				&& Mobo_Core_Storage_Schema::is_ready()
				&& class_exists( 'Mobo_Core_Variant_State_Repository' )
				&& class_exists( 'Mobo_Core_Product_State_Repository' );
			if ( ! $authority_ready ) {
				$context['available'] = false;
				$this->context_cache[ $variation_id ] = $context;
				return $context;
			}

			$variant_row = ( new Mobo_Core_Variant_State_Repository() )->get_owner_by_woo_variation_id( $variation_id );
			if ( ! is_array( $variant_row ) ) {
				$this->context_cache[ $variation_id ] = $context;
				return $context;
			}
			$owner_id = absint( $variant_row['connection_id'] ?? 0 );
			$row_parent_id = absint( $variant_row['woo_product_id'] ?? 0 );
			$has_variant_identity = '' !== trim( (string) ( $variant_row['variant_guid'] ?? '' ) ) || absint( $variant_row['portal_variant_id'] ?? 0 ) > 0;
			if ( $owner_id <= 0 || $parent_id <= 0 || $row_parent_id !== $parent_id || ! $has_variant_identity ) {
				$this->context_cache[ $variation_id ] = $context;
				return $context;
			}

			$product_row = ( new Mobo_Core_Product_State_Repository() )->get_owner_by_woo_product_id( $parent_id );
			$has_product_identity = is_array( $product_row ) && ( '' !== trim( (string) ( $product_row['product_guid'] ?? '' ) ) || absint( $product_row['portal_product_id'] ?? 0 ) > 0 );
			if ( ! $has_product_identity || absint( $product_row['connection_id'] ?? 0 ) !== $owner_id ) {
				$this->context_cache[ $variation_id ] = $context;
				return $context;
			}

			$context['isMobo']  = true;
			$context['ownerId'] = $owner_id;
		} else {
			$is_mobo = class_exists( 'Mobo_Core_Product_Identity_Policy' )
				&& Mobo_Core_Product_Identity_Policy::is_mobo_object_id( $variation_id )
				&& ( $parent_id <= 0 || Mobo_Core_Product_Identity_Policy::is_mobo_object_id( $parent_id ) );
			if ( ! $is_mobo ) {
				$this->context_cache[ $variation_id ] = $context;
				return $context;
			}
			$context['isMobo'] = true;
			$context['ownerId'] = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		}

		$owner_id = absint( $context['ownerId'] );
		$owner    = null;
		if ( $owner_id > 0 && class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists() ) {
			$owner = Mobo_Core_Connection_Repository::get_by_id( $owner_id );
			if ( ! is_array( $owner ) ) {
				$context['available'] = false;
				$context['isMobo']    = false;
				$this->context_cache[ $variation_id ] = $context;
				return $context;
			}
			$name   = sanitize_text_field( (string) ( $owner['display_name'] ?? '' ) );
			$source = sanitize_key( (string) ( $owner['source_key'] ?? '' ) );
			$context['connectionLabel'] = '' !== $name ? $name : ( '' !== $source ? $source : 'اتصال #' . $owner_id );
			$topology_ready = ! method_exists( 'Mobo_Core_Connection_Repository', 'multi_connection_runtime_ready' ) || Mobo_Core_Connection_Repository::multi_connection_runtime_ready();
			$context['mutationReady'] = ! empty( $owner['is_enabled'] ) && Mobo_Core_Connection_Repository::is_provisioned( $owner ) && $topology_ready;
		} elseif ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::table_exists() ) {
			$context['mutationReady'] = true;
		}

		$read_excluded = static function () use ( $parent_id ) {
			return $parent_id > 0 && class_exists( 'Mobo_Core_Product_Exclusions' )
				? (bool) Mobo_Core_Product_Exclusions::is_local_product_excluded( $parent_id, false )
				: false;
		};
		$context['excluded'] = $owner_id > 0 && class_exists( 'Mobo_Core_Connection_Context' )
			? (bool) Mobo_Core_Connection_Context::run( $owner_id, $read_excluded )
			: (bool) call_user_func( $read_excluded );

		$this->context_cache[ $variation_id ] = $context;
		return $context;
	}

	/** @return array */
	private function empty_context( $available ) {
		return array(
			'available'       => (bool) $available,
			'isMobo'          => false,
			'ownerId'         => 0,
			'parentId'        => 0,
			'excluded'        => false,
			'mutationReady'   => false,
			'connectionLabel' => '',
		);
	}
}
