<?php
/**
 * Admin UI.
 *
 * Persian-only compact tabbed admin page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Admin {

	const MENU_SLUG = 'mobo-core';

	/** @var array<int,array> Request-local Woo Products-list Mobo context cache. */
	private $product_list_context_cache = array();

	/** @var array<int,string> Request-local Connection pricing-summary cache. */
	private $product_list_pricing_summary_cache = array();

	/** @var array<int,array|null> Request-local owner Connection cache for Woo Products list. */
	private $product_list_connection_cache = array();

	/** @var bool|null Request-local multi-Connection topology readiness cache. */
	private $product_list_topology_ready = null;

	/**
	 * Bootstrap admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'activate_admin_connection_context' ), 1 );
		/* 10.34.77: database migration progress / marked-product notice (manage_options only). */
		add_action( 'admin_notices', array( 'Mobo_Core_Migration_Quarantine', 'render_admin_notice' ) );
		/* utf8mb3 tables: recommend the manual utf8mb4 conversion tool. */
		add_action( 'admin_notices', array( $this, 'render_db_charset_notice' ) );
		/* Support access: "پشتیبانی" tab handlers, request/Source notices, expiry. */
		Mobo_Core_Support_Admin::register_hooks();
		/* Data health: "سلامت داده" tab handlers, AJAX slices, hourly tick schedule. */
		Mobo_Core_Data_Health_Admin::register_hooks();
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'register_admin_bar_menu' ), 80 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_head-edit.php', array( $this, 'render_product_list_badge_styles' ) );
		add_action( 'admin_footer-edit.php', array( $this, 'render_product_pricing_override_modal' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_mobo_product_source_filter' ), 30, 2 );
		add_filter( 'posts_where', array( $this, 'filter_product_list_where' ), 20, 2 );
		add_filter( 'display_post_states', array( $this, 'add_mobo_product_post_state' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'add_mobo_product_pricing_row_action' ), 20, 2 );
		add_action( 'wp_ajax_mobo_core_save_product_pricing_override', array( $this, 'handle_ajax_save_product_pricing_override' ) );

		add_action( 'admin_post_mobo_core_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_mobo_core_create_connection', array( $this, 'handle_create_connection' ) );
		add_action( 'admin_post_mobo_core_cancel_pending_connection', array( $this, 'handle_cancel_pending_connection' ) );
		add_action( 'admin_post_mobo_core_rearm_wallet_alert', array( $this, 'handle_rearm_wallet_alert' ) );
		add_action( 'admin_post_mobo_core_test_wallet_sms', array( $this, 'handle_test_wallet_sms' ) );
		add_action( 'admin_post_mobo_core_php_diagnostics', array( $this, 'handle_php_diagnostics' ) );
		add_action( 'admin_post_mobo_core_start_database_unicode_migration', array( $this, 'handle_start_database_unicode_migration' ) );
		add_action( 'admin_post_mobo_core_resume_database_unicode_migration', array( $this, 'handle_resume_database_unicode_migration' ) );
		add_action( 'admin_post_mobo_core_pause_database_unicode_migration', array( $this, 'handle_pause_database_unicode_migration' ) );
		add_action( 'admin_post_mobo_core_precheck_database_unicode_migration', array( $this, 'handle_precheck_database_unicode_migration' ) );
		add_action( 'admin_post_mobo_core_tool_test_mobo_login', array( $this, 'handle_admin_tool_action' ) );
		add_action( 'admin_post_mobo_core_tool_clear_mobo_debug_log', array( $this, 'handle_admin_tool_action' ) );
		add_action( 'admin_post_mobo_core_tool_clear_shipping_diagnostics', array( $this, 'handle_admin_tool_action' ) );
		add_action( 'admin_post_mobo_core_tool_sync_address_mapping', array( $this, 'handle_admin_tool_action' ) );
		add_action( 'admin_post_mobo_core_tool_sync_remote_shipping_methods', array( $this, 'handle_admin_tool_action' ) );
		add_action( 'admin_post_mobo_core_tool_install_automatic_shipping', array( $this, 'handle_admin_tool_action' ) );
		add_action( 'admin_post_mobo_core_tool_run_cron_now', array( $this, 'handle_admin_tool_action' ) );
		add_action( 'admin_post_mobo_core_start_sync', array( $this, 'handle_start_sync' ) );
		add_action( 'admin_post_mobo_core_start_repair', array( $this, 'handle_start_repair' ) );
		add_action( 'admin_post_mobo_core_sync_categories', array( $this, 'handle_sync_categories' ) );
		add_action( 'admin_post_mobo_core_resume_sync', array( $this, 'handle_resume_sync' ) );
		add_action( 'admin_post_mobo_core_cancel_sync', array( $this, 'handle_cancel_sync' ) );
		add_action( 'admin_post_mobo_core_reset_sync', array( $this, 'handle_reset_sync' ) );
		add_action( 'admin_post_mobo_core_quarantine_duplicate_products', array( $this, 'handle_quarantine_duplicate_products' ) );
		add_action( 'admin_post_mobo_core_start_reprice', array( $this, 'handle_start_reprice' ) );
		add_action( 'admin_post_mobo_core_resume_reprice', array( $this, 'handle_resume_reprice' ) );
		add_action( 'admin_post_mobo_core_cancel_reprice', array( $this, 'handle_cancel_reprice' ) );
		add_action( 'admin_post_mobo_core_reset_reprice', array( $this, 'handle_reset_reprice' ) );
		add_action( 'admin_post_mobo_core_reprice_recover', array( $this, 'handle_reprice_recover' ) );
		add_action( 'admin_post_mobo_core_reprice_skip_item', array( $this, 'handle_reprice_skip_item' ) );
		add_action( 'admin_post_mobo_core_start_recategorize', array( $this, 'handle_start_recategorize' ) );
		add_action( 'admin_post_mobo_core_cancel_recategorize', array( $this, 'handle_cancel_recategorize' ) );
		add_action( 'admin_post_mobo_core_reset_recategorize', array( $this, 'handle_reset_recategorize' ) );
		add_action( 'admin_post_mobo_core_retry_failed_webhooks', array( $this, 'handle_retry_failed_webhooks' ) );
		add_action( 'admin_post_mobo_core_start_image_refresh_automation', array( $this, 'handle_start_image_refresh_automation' ) );
		add_action( 'admin_post_mobo_core_pause_image_refresh_automation', array( $this, 'handle_pause_image_refresh_automation' ) );
		add_action( 'admin_post_mobo_core_approve_image_refresh_delete_old', array( $this, 'handle_approve_image_refresh_delete_old' ) );
		add_action( 'admin_post_mobo_core_legacy_image_audit_run', array( $this, 'handle_legacy_image_audit_run' ) );
		add_action( 'admin_post_mobo_core_legacy_image_audit_stop', array( $this, 'handle_legacy_image_audit_stop' ) );
		add_action( 'admin_post_mobo_core_legacy_image_audit_reset', array( $this, 'handle_legacy_image_audit_reset' ) );
		add_action( 'wp_ajax_mobo_core_get_sync_status', array( $this, 'handle_ajax_sync_status' ) );
		add_action( 'wp_ajax_mobo_core_get_reprice_status', array( $this, 'handle_ajax_reprice_status' ) );
		add_action( 'wp_ajax_mobo_core_get_recategorize_status', array( $this, 'handle_ajax_recategorize_status' ) );
		add_action( 'wp_ajax_mobo_core_get_image_refresh_live_status', array( $this, 'handle_ajax_image_refresh_live_status' ) );
	}

	/**
	 * Select the admin-request Connection before any Source-owned setting,
	 * queue or API state is read. An explicit unknown/disabled positive id is
	 * preserved as explicit context so downstream durable routing fails closed;
	 * Default fallback is allowed only when no selector was supplied.
	 *
	 * @return void
	 */
	public function activate_admin_connection_context() {
		if ( ! class_exists( 'Mobo_Core_Connection_Context' ) || ! class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			return;
		}

		$connection_id = $this->requested_admin_connection_id();
		if ( $connection_id > 0 ) {
			Mobo_Core_Connection_Context::set_current_connection_id( $connection_id );
		}
	}

	/** @return int */
	private function requested_admin_connection_id() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::table_exists() ) {
			return 0;
		}

		$has_explicit_selector = isset( $_REQUEST['mobo_connection_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing selector only; mutation handlers still require their own nonce/capability checks.
		$requested = $has_explicit_selector ? absint( wp_unslash( $_REQUEST['mobo_connection_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $has_explicit_selector ) {
			if ( $requested <= 0 ) { return 0; }
			$row = Mobo_Core_Connection_Repository::get_by_id( $requested );
			if ( is_array( $row ) && ! empty( $row['is_enabled'] ) ) { return $requested; }
			/* Preserve an unavailable explicit positive id. Connection Repository,
			 * Settings and transports treat it as fail-closed and must not inherit Default. */
			return $requested;
		}

		$default = method_exists( 'Mobo_Core_Connection_Repository', 'get_default_any' ) ? Mobo_Core_Connection_Repository::get_default_any() : Mobo_Core_Connection_Repository::get_default();
		return is_array( $default ) ? absint( $default['id'] ?? 0 ) : 0;
	}

	/** @return int */
	private function current_admin_connection_id() {
		if ( class_exists( 'Mobo_Core_Connection_Context' ) ) {
			$id = Mobo_Core_Connection_Context::get_effective_connection_id();
			if ( $id > 0 ) {
				return $id;
			}
		}
		return $this->requested_admin_connection_id();
	}

	/** @return array|null */
	private function current_admin_connection() {
		$id = $this->current_admin_connection_id();
		return $id > 0 && class_exists( 'Mobo_Core_Connection_Repository' )
			? Mobo_Core_Connection_Repository::get_by_id( $id )
			: null;
	}

	/** @return string */
	private function current_admin_license_token() {
		$row = $this->current_admin_connection();
		if ( is_array( $row ) ) {
			/* Disabled durable owners remain selectable for administration, but they
			 * are unavailable runtime owners and must expose no usable credential. */
			return ! empty( $row['is_enabled'] ) ? trim( (string) ( $row['license_token'] ?? '' ) ) : '';
		}

		/* Once durable Connections exist, an unavailable explicit owner must never
		 * inherit the legacy/default credential merely because the admin helper could
		 * not resolve its row. Legacy options are only a pre-Connection fallback. */
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists() ) {
			return '';
		}
		return trim( (string) get_option( 'mobo_core_token', '' ) );
	}

	/** @return string */
	private function current_admin_security_code() {
		$row = $this->current_admin_connection();
		if ( is_array( $row ) ) {
			/* Match runtime routing: disabled Connection rows are not credential owners. */
			return ! empty( $row['is_enabled'] )
				? Mobo_Core_Settings::normalize_security_code( (string) ( $row['security_code'] ?? '' ) )
				: '';
		}

		/* Mirror current_admin_license_token(): no durable row means no credential
		 * after the Connection layer exists. This preserves explicit fail-closed
		 * semantics for disabled/deleted/unknown admin selectors. */
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists() ) {
			return '';
		}
		return Mobo_Core_Settings::normalize_security_code( (string) get_option( 'mobo_core_security_code', '' ) );
	}

	/** @return string */
	private function admin_page_url( $args = array() ) {
		$args = is_array( $args ) ? $args : array();
		$args['page'] = self::MENU_SLUG;
		$connection_id = $this->current_admin_connection_id();
		if ( $connection_id > 0 ) {
			$args['mobo_connection_id'] = $connection_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/** @return string */
	private function admin_post_url( $args = array() ) {
		$args = is_array( $args ) ? $args : array();
		$connection_id = $this->current_admin_connection_id();
		if ( $connection_id > 0 ) {
			$args['mobo_connection_id'] = $connection_id;
		}
		return empty( $args ) ? admin_url( 'admin-post.php' ) : add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/** @return string */
	private function admin_ajax_url() {
		$connection_id = $this->current_admin_connection_id();
		return $connection_id > 0
			? add_query_arg( 'mobo_connection_id', $connection_id, admin_url( 'admin-ajax.php' ) )
			: admin_url( 'admin-ajax.php' );
	}

	/**
	 * Render selector for enabled Connections.
	 *
	 * @param string $active_tab Active tab.
	 * @param bool   $advanced_view Whether advanced view is active.
	 * @return void
	 */
	private function render_connection_selector( $active_tab, $advanced_view ) {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::table_exists() ) {
			return;
		}
		$connections = Mobo_Core_Connection_Repository::list_all();
		if ( empty( $connections ) ) {
			return;
		}
		$current_id = $this->current_admin_connection_id();
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="mobo-connection-selector">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $active_tab ); ?>">
			<?php if ( $advanced_view ) : ?>
				<input type="hidden" name="view" value="advanced">
			<?php endif; ?>
			<label for="mobo-admin-connection-selector"><strong>انتخاب اتصال:</strong></label>
			<select id="mobo-admin-connection-selector" name="mobo_connection_id" onchange="this.form.submit()">
				<?php foreach ( $connections as $connection ) : ?>
					<?php
					$id = absint( $connection['id'] ?? 0 );
					$source_key = sanitize_key( (string) ( $connection['source_key'] ?? '' ) );
					$name = sanitize_text_field( (string) ( $connection['display_name'] ?? '' ) );
					if ( '' === $name ) { $name = 'اتصال #' . $id; }
					$source_name = '' !== $source_key ? $source_key : 'در انتظار';
					$is_provisioned = Mobo_Core_Connection_Repository::is_provisioned( $connection );
					if ( ! $is_provisioned ) {
						$status = 'در انتظار آماده‌سازی';
					} elseif ( ! empty( $connection['is_enabled'] ) ) {
						$status = 'فعال';
					} else {
						$status = 'آماده ولی غیرفعال';
					}
					$default_badge = ! empty( $connection['is_default'] ) ? ' — پیش‌فرض' : '';
					$label = $name . ' — ' . $source_name . ' — ' . $status . $default_badge . ' (#' . $id . ')';
					?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $current_id, $id ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<noscript><button type="submit" class="button">انتخاب</button></noscript>
		</form>
		<?php
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			'موبو',
			'موبو',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-update-alt',
			56
		);

		$submenus = $this->get_admin_navigation_items();
		foreach ( $submenus as $tab => $label ) {
			$menu_slug = 'dashboard' === $tab ? self::MENU_SLUG : self::MENU_SLUG . '&tab=' . $tab;
			add_submenu_page(
				self::MENU_SLUG,
				$label . ' موبو',
				$label,
				'manage_options',
				$menu_slug,
				array( $this, 'render' )
			);
		}

		add_submenu_page(
			self::MENU_SLUG,
			'تنظیمات پیشرفته موبو',
			'تنظیمات پیشرفته',
			'manage_options',
			self::MENU_SLUG . '&view=advanced&tab=filters',
			array( $this, 'render' )
		);
	}

	/**
	 * Navigation items shared by the side menu and the WordPress admin bar.
	 *
	 * @return array<string,string>
	 */
	private function get_admin_navigation_items( $include_advanced = false ) {
		$items = array(
			'dashboard'   => 'نمای کلی',
			'connection'  => 'اتصال و لایسنس',
			'purchase'    => 'خرید و فعال‌سازی',
			'product'     => 'تنظیمات محصول',
			'categories'  => 'دسته‌بندی‌ها',
			'pricing'     => 'قیمت‌گذاری',
			'sync-health' => 'سلامت همگام‌سازی',
			'health'      => 'سلامت سایت',
			'data-health' => 'سلامت داده',
			'support'     => 'پشتیبانی',
		);

		if ( $include_advanced ) {
			$items = array_merge( $items, $this->get_advanced_navigation_items() );
		}

		return $items;
	}

	/**
	 * Technical pages hidden from the default manager view.
	 *
	 * @return array<string,string>
	 */
	private function get_advanced_navigation_items() {
		return array(
			'filters'       => 'فیلتر محصولات',
			'queue'         => 'سرعت و صف‌ها',
			'image-refresh' => 'نوسازی تصاویر',
			'cron'          => 'Cron و Worker',
			'checkout'      => 'سفارش موبو',
			'sms'           => 'پیامک',
		);
	}

	/**
	 * Add quick Mobo navigation to the top admin toolbar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar.
	 * @return void
	 */
	public function register_admin_bar_menu( $wp_admin_bar ) {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) || ! is_object( $wp_admin_bar ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'mobo-core',
				'title' => '<span class="ab-icon dashicons-update-alt" aria-hidden="true"></span><span class="ab-label">موبو</span>',
				'href'  => $this->admin_page_url(),
				'meta'  => array( 'class' => 'mobo-core-admin-bar' ),
			)
		);

		foreach ( $this->get_admin_navigation_items() as $tab => $label ) {
			$url = $this->admin_page_url( 'dashboard' === $tab ? array() : array( 'tab' => $tab ) );
			$wp_admin_bar->add_node(
				array(
					'id'     => 'mobo-core-' . $tab,
					'parent' => 'mobo-core',
					'title'  => $label,
					'href'   => $url,
				)
			);
		}

		$wp_admin_bar->add_node(
			array(
				'id'     => 'mobo-core-advanced',
				'parent' => 'mobo-core',
				'title'  => 'تنظیمات پیشرفته',
				'href'   => $this->admin_page_url( array( 'view' => 'advanced', 'tab' => 'filters' ) ),
			)
		);
	}



	/**
	 * Render the Mobo ownership filter on the WooCommerce Products list.
	 *
	 * Default/empty means all products. The classification is backed by the
	 * shared durable identity policy rather than by one legacy meta key.
	 *
	 * @param string $post_type Current post type.
	 * @param string $which     Tablenav position.
	 * @return void
	 */
	public function render_mobo_product_source_filter( $post_type, $which = 'top' ) {
		if ( 'product' !== $post_type || 'top' !== $which ) {
			return;
		}

		$selected = $this->get_product_source_filter_value();
		$authority = $this->product_list_identity_authority_status();
		?>
		<label class="screen-reader-text" for="mobo-core-product-source-filter">فیلتر محصولات بر اساس مالکیت موبو در همه اتصال‌ها</label>
		<select name="mobo_core_product_source" id="mobo-core-product-source-filter" <?php disabled( empty( $authority['available'] ) ); ?>>
			<option value="" <?php selected( '', $selected ); ?>>همه محصولات</option>
			<option value="mobo" <?php selected( 'mobo', $selected ); ?>>محصولات موبویی — همه اتصال‌ها</option>
			<option value="non_mobo" <?php selected( 'non_mobo', $selected ); ?>>محصولات غیر موبویی</option>
		</select>
		<?php if ( empty( $authority['available'] ) ) : ?>
			<span class="description mobo-product-source-filter-note">هویت موبو موقتاً قابل خواندن نیست؛ فیلتر اعمال نشده است. ابتدا سلامت ذخیره‌سازی MoboCore را بررسی کنید.</span>
		<?php endif; ?>
		<?php
	}

	/**
	 * Whether the Products list has an authoritative identity source.
	 * Terminal Custom-Storage ownership must never fall back to retired postmeta.
	 *
	 * @return array{available:bool,terminal:bool}
	 */
	private function product_list_identity_authority_status() {
		$terminal = class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced();
		if ( ! $terminal ) {
			return array( 'available' => true, 'terminal' => false );
		}

		$available = class_exists( 'Mobo_Core_Storage_Schema' )
			&& Mobo_Core_Storage_Schema::is_ready()
			&& class_exists( 'Mobo_Core_Product_State_Repository' );
		return array( 'available' => (bool) $available, 'terminal' => true );
	}

	/**
	 * Restrict the main WooCommerce product-list query by durable Mobo identity.
	 *
	 * A correlated EXISTS keeps the normal WooCommerce query shape intact and
	 * avoids JOIN duplication when an object has more than one identity meta row.
	 *
	 * @param string   $where Existing WHERE SQL.
	 * @param WP_Query $query Query object.
	 * @return string
	 */
	public function filter_product_list_where( $where, $query ) {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return $where;
		}

		$post_type = $query->get( 'post_type' );
		if ( 'product' !== $post_type ) {
			return $where;
		}

		$filter = $this->get_product_source_filter_value();
		if ( ! in_array( $filter, array( 'mobo', 'non_mobo' ), true ) || ! class_exists( 'Mobo_Core_Product_Identity_Policy' ) ) {
			return $where;
		}

		global $wpdb;

		/* After the terminal identity fence, Product identity no longer exists in
		 * wp_postmeta. If Custom authority is temporarily unavailable, do not apply
		 * a misleading legacy filter: show the unfiltered Woo list instead. */
		$authority = $this->product_list_identity_authority_status();
		if ( ! empty( $authority['terminal'] ) && empty( $authority['available'] ) ) {
			return $where;
		}
		if ( ! empty( $authority['terminal'] ) ) {
			$products_table = Mobo_Core_Storage_Schema::table_name( 'products' );
			$exists_sql = "EXISTS (SELECT 1 FROM {$products_table} AS mobo_identity_custom WHERE mobo_identity_custom.woo_product_id = {$wpdb->posts}.ID AND (mobo_identity_custom.product_guid <> '' OR mobo_identity_custom.portal_product_id > 0 OR mobo_identity_custom.simple_variant_guid <> '' OR mobo_identity_custom.simple_portal_variant_id > 0))";
			$where .= ' AND ' . ( 'mobo' === $filter ? $exists_sql : 'NOT ' . $exists_sql );
			return $where;
		}

		$numeric_keys = Mobo_Core_Product_Identity_Policy::numeric_identity_meta_keys();
		$string_keys  = array_values(
			array_diff(
				Mobo_Core_Product_Identity_Policy::identity_meta_keys(),
				$numeric_keys
			)
		);

		$identity_parts = array();
		$prepare_values = array();

		if ( ! empty( $string_keys ) ) {
			$identity_parts[] = '(mobo_identity_pm.meta_key IN (' . implode( ', ', array_fill( 0, count( $string_keys ), '%s' ) ) . ") AND TRIM(COALESCE(mobo_identity_pm.meta_value, '')) <> '')";
			$prepare_values  = array_merge( $prepare_values, $string_keys );
		}

		if ( ! empty( $numeric_keys ) ) {
			$identity_parts[] = '(mobo_identity_pm.meta_key IN (' . implode( ', ', array_fill( 0, count( $numeric_keys ), '%s' ) ) . ') AND CAST(mobo_identity_pm.meta_value AS UNSIGNED) > 0)';
			$prepare_values  = array_merge( $prepare_values, $numeric_keys );
		}

		if ( empty( $identity_parts ) ) {
			return $where;
		}

		$identity_predicate = $wpdb->prepare( implode( ' OR ', $identity_parts ), $prepare_values );
		$exists_sql         = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS mobo_identity_pm WHERE mobo_identity_pm.post_id = {$wpdb->posts}.ID AND ({$identity_predicate}))";

		$where .= ' AND ' . ( 'mobo' === $filter ? $exists_sql : 'NOT ' . $exists_sql );
		return $where;
	}

	/**
	 * Read and normalize the current Products-list Mobo filter.
	 *
	 * @return string
	 */
	private function get_product_source_filter_value() {
		$value = isset( $_GET['mobo_core_product_source'] ) ? sanitize_key( wp_unslash( $_GET['mobo_core_product_source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filtering.
		return in_array( $value, array( 'mobo', 'non_mobo' ), true ) ? $value : '';
	}


	/**
	 * Resolve one Woo Product against installation-global durable ownership.
	 * Products-list UI is outside the Mobo Connection selector, so it must not
	 * inherit whichever Connection happens to be the request default.
	 *
	 * @param int $product_id Woo Product ID.
	 * @return array
	 */
	private function product_list_product_context( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return array( 'available' => false, 'isMobo' => false, 'ownerId' => 0, 'owner' => null, 'excluded' => false, 'pricing' => array(), 'mutationReady' => false, 'connectionLabel' => '' );
		}
		if ( isset( $this->product_list_context_cache[ $product_id ] ) ) {
			return $this->product_list_context_cache[ $product_id ];
		}

		$authority = $this->product_list_identity_authority_status();
		$context = array(
			'available'       => ! empty( $authority['available'] ),
			'isMobo'          => false,
			'ownerId'         => 0,
			'owner'           => null,
			'excluded'        => false,
			'pricing'         => array(),
			'mutationReady'   => false,
			'connectionLabel' => '',
		);

		if ( ! empty( $authority['terminal'] ) ) {
			if ( empty( $authority['available'] ) ) {
				$this->product_list_context_cache[ $product_id ] = $context;
				return $context;
			}
			$row = ( new Mobo_Core_Product_State_Repository() )->get_owner_by_woo_product_id( $product_id );
			if ( is_array( $row ) ) {
				$has_identity = '' !== trim( (string) ( $row['product_guid'] ?? '' ) )
					|| absint( $row['portal_product_id'] ?? 0 ) > 0
					|| '' !== trim( (string) ( $row['simple_variant_guid'] ?? '' ) )
					|| absint( $row['simple_portal_variant_id'] ?? 0 ) > 0;
				if ( $has_identity ) {
					$context['isMobo']  = true;
					$context['ownerId'] = absint( $row['connection_id'] ?? 0 );
				}
			}
		} elseif ( class_exists( 'Mobo_Core_Product_Identity_Policy' ) ) {
			$context['isMobo'] = Mobo_Core_Product_Identity_Policy::is_mobo_object_id( $product_id );
			$context['ownerId'] = $context['isMobo'] ? $this->current_admin_connection_id() : 0;
		}

		if ( ! $context['isMobo'] ) {
			$this->product_list_context_cache[ $product_id ] = $context;
			return $context;
		}

		$owner_id = absint( $context['ownerId'] );
		$owner = null;
		if ( $owner_id > 0 && class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			if ( array_key_exists( $owner_id, $this->product_list_connection_cache ) ) {
				$owner = $this->product_list_connection_cache[ $owner_id ];
			} else {
				$fetched_owner = Mobo_Core_Connection_Repository::get_by_id( $owner_id );
				$owner = is_array( $fetched_owner ) ? $fetched_owner : null;
				$this->product_list_connection_cache[ $owner_id ] = $owner;
			}
		}
		$context['owner'] = is_array( $owner ) ? $owner : null;
		if ( is_array( $owner ) ) {
			$name = sanitize_text_field( (string) ( $owner['display_name'] ?? '' ) );
			$source = sanitize_key( (string) ( $owner['source_key'] ?? '' ) );
			$context['connectionLabel'] = '' !== $name ? $name : ( '' !== $source ? $source : 'اتصال #' . $owner_id );
			$provisioned = Mobo_Core_Connection_Repository::is_provisioned( $owner );
			if ( null === $this->product_list_topology_ready ) {
				$this->product_list_topology_ready = ! method_exists( 'Mobo_Core_Connection_Repository', 'multi_connection_runtime_ready' ) || Mobo_Core_Connection_Repository::multi_connection_runtime_ready();
			}
			$context['mutationReady'] = ! empty( $owner['is_enabled'] ) && $provisioned && (bool) $this->product_list_topology_ready;
		} elseif ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::table_exists() ) {
			/* Legacy/single-Connection upgrade compatibility before the durable table exists. */
			$context['mutationReady'] = true;
		}

		$read_owner_state = static function () use ( $product_id ) {
			$excluded = class_exists( 'Mobo_Core_Product_Exclusions' ) ? Mobo_Core_Product_Exclusions::is_local_product_excluded( $product_id, false ) : false;
			$pricing = class_exists( 'Mobo_Core_Product_Pricing_Policy' ) ? Mobo_Core_Product_Pricing_Policy::get_parent_state( $product_id ) : array();
			return array( 'excluded' => (bool) $excluded, 'pricing' => is_array( $pricing ) ? $pricing : array() );
		};
		$owner_state = $owner_id > 0 && class_exists( 'Mobo_Core_Connection_Context' )
			? Mobo_Core_Connection_Context::run( $owner_id, $read_owner_state )
			: call_user_func( $read_owner_state );
		$context['excluded'] = ! empty( $owner_state['excluded'] );
		$context['pricing']  = isset( $owner_state['pricing'] ) && is_array( $owner_state['pricing'] ) ? $owner_state['pricing'] : array();

		$this->product_list_context_cache[ $product_id ] = $context;
		return $context;
	}

	/** Return a plain-text summary of the owning Connection's global pricing policy. */
	private function product_list_global_pricing_summary( $connection_id ) {
		$connection_id = absint( $connection_id );
		if ( isset( $this->product_list_pricing_summary_cache[ $connection_id ] ) ) {
			return $this->product_list_pricing_summary_cache[ $connection_id ];
		}
		$reader = static function () {
			$type = sanitize_key( (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' ) );
			if ( 'static-percentage' === $type ) {
				return 'سیاست عمومی این اتصال: سود درصدی ' . (string) Mobo_Core_Settings::get( 'global_additional_percentage', '0' ) . '%';
			}
			if ( 'dynamic-price' === $type ) {
				$rows = json_decode( (string) Mobo_Core_Settings::get( 'mobo_dynamic_price', '[]' ), true );
				$active = 0;
				if ( is_array( $rows ) ) {
					foreach ( $rows as $row ) {
						if ( is_array( $row ) && 'true' === (string) ( $row['is_active'] ?? 'false' ) ) { $active++; }
					}
				}
				return 'سیاست عمومی این اتصال: قیمت‌گذاری پویا (' . $active . ' بازه فعال)';
			}
			return 'سیاست عمومی این اتصال: سود مبلغ ثابت +' . (string) Mobo_Core_Settings::get( 'global_additional_price', '0' );
		};
		$summary = $connection_id > 0 && class_exists( 'Mobo_Core_Connection_Context' )
			? Mobo_Core_Connection_Context::run( $connection_id, $reader )
			: call_user_func( $reader );
		$this->product_list_pricing_summary_cache[ $connection_id ] = sanitize_text_field( (string) $summary );
		return $this->product_list_pricing_summary_cache[ $connection_id ];
	}

	/**
	 * Add a clear Mobo marker beside product names in WooCommerce product list.
	 *
	 * @param array<string,string> $post_states Existing states.
	 * @param WP_Post              $post Post.
	 * @return array<string,string>
	 */
	public function add_mobo_product_post_state( $post_states, $post ) {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return $post_states;
		}

		$context = $this->product_list_product_context( $post->ID );
		if ( empty( $context['available'] ) || empty( $context['isMobo'] ) ) {
			return $post_states;
		}

		$post_states['mobo_core_product'] = '<span class="mobo-product-state" title="محصول متصل به موبو"><span class="dashicons dashicons-update-alt" aria-hidden="true"></span> موبویی</span>';
		if ( ! empty( $context['excluded'] ) ) {
			$post_states['mobo_core_excluded'] = '<span class="mobo-excluded-state" title="این محصول موبویی است اما از تغییرات خودکار منبع مستثنا شده است">مستثنا از همگام‌سازی</span>';
		}
		if ( empty( $context['mutationReady'] ) ) {
			$post_states['mobo_core_connection_unavailable'] = '<span class="mobo-connection-state" title="اتصال مالک این محصول برای تغییرات MoboCore آماده نیست">اتصال آماده نیست</span>';
		}
		$pricing = isset( $context['pricing'] ) && is_array( $context['pricing'] ) ? $context['pricing'] : array();
		if ( 'custom' === (string) ( $pricing['mode'] ?? '' ) ) {
			$post_states['mobo_core_pricing_override'] = '<span class="mobo-pricing-state" title="سود اختصاصی این محصول">سود اختصاصی +' . esc_html( (string) ( $pricing['value'] ?? '' ) ) . '%</span>';
		}
		if ( ! empty( $pricing['error'] ) ) {
			$post_states['mobo_core_pricing_error'] = '<span class="mobo-pricing-error-state" title="' . esc_attr( $pricing['error'] ) . '">خطای سیاست قیمت</span>';
		}
		if ( ! empty( $pricing['pending'] ) ) {
			$post_states['mobo_core_pricing_pending'] = '<span class="mobo-pricing-pending-state" title="درخواست قیمت‌گذاری منتظر اجرای امن است">قیمت‌گذاری در صف</span>';
		}
		return $post_states;
	}

	/**
	 * Product-list styles are kept scoped to edit.php?post_type=product.
	 *
	 * @return void
	 */
	public function render_product_list_badge_styles() {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'product' !== $post_type ) {
			return;
		}
		?>
		<style>
			.post-state .mobo-product-state,
			.mobo-product-state {
			display:inline-flex;
			align-items:center;
			gap:3px;
			padding:2px 7px;
			border-radius:999px;
			background:#eef6ff;
			border:1px solid #93c5fd;
			color:#1d4ed8;
			font-size:11px;
			font-weight:700;
			line-height:18px;
			vertical-align:middle;
		}
		.mobo-product-state .dashicons {
			font-size:14px;
			width:14px;
			height:14px;
			line-height:14px;
		}
		.mobo-pricing-state,.mobo-pricing-pending-state,.mobo-pricing-error-state,.mobo-excluded-state,.mobo-connection-state{display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:700;line-height:18px;vertical-align:middle}
		.mobo-pricing-state{background:#f0fdf4;border:1px solid #86efac;color:#166534}
		.mobo-pricing-pending-state{background:#fffbeb;border:1px solid #fcd34d;color:#92400e}
		.mobo-pricing-error-state{background:#fcf0f1;border:1px solid #f0a8ad;color:#8a2424}
		.mobo-excluded-state{background:#f6f7f7;border:1px solid #c3c4c7;color:#50575e}
		.mobo-connection-state{background:#fff8e5;border:1px solid #dba617;color:#6e4b00}
		.mobo-pricing-row-action{font-weight:600}
		.mobo-pricing-row-action-disabled{color:#646970;cursor:help}
		.mobo-product-source-filter-note{margin-inline-start:8px}
		</style>
		<?php
	}


	/** Add per-product Mobo pricing action to WooCommerce product rows. */
	public function add_mobo_product_pricing_row_action( $actions, $post ) {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) { return $actions; }
		$context = $this->product_list_product_context( $post->ID );
		if ( empty( $context['available'] ) || empty( $context['isMobo'] ) ) { return $actions; }
		if ( ! empty( $context['excluded'] ) ) {
			$actions['mobo_pricing'] = '<span class="mobo-pricing-row-action-disabled" title="محصول در استثناهای همگام‌سازی است و از قیمت‌گذاری منبع خارج شده است">قیمت‌گذاری موبو: مستثنا</span>';
			return $actions;
		}
		if ( empty( $context['mutationReady'] ) ) {
			$actions['mobo_pricing'] = '<span class="mobo-pricing-row-action-disabled" title="اتصال مالک محصول فعال/آماده نیست">قیمت‌گذاری موبو: اتصال آماده نیست</span>';
			return $actions;
		}
		$state = isset( $context['pricing'] ) && is_array( $context['pricing'] ) ? $context['pricing'] : array();
		$actions['mobo_pricing'] = sprintf(
			'<a href="#" class="mobo-pricing-row-action" data-product-id="%1$d" data-product-name="%2$s" data-mode="%3$s" data-value="%4$s" data-owner-id="%5$d" data-connection-label="%6$s" data-global-description="%7$s">قیمت‌گذاری موبو</a>',
			absint( $post->ID ),
			esc_attr( get_the_title( $post->ID ) ),
			esc_attr( (string) ( $state['mode'] ?? 'global' ) ),
			esc_attr( (string) ( $state['value'] ?? '' ) ),
			absint( $context['ownerId'] ?? 0 ),
			esc_attr( (string) ( $context['connectionLabel'] ?? '' ) ),
			esc_attr( $this->product_list_global_pricing_summary( absint( $context['ownerId'] ?? 0 ) ) )
		);
		return $actions;
	}

	/** Render the small product-pricing modal only on the WooCommerce Products list. */
	public function render_product_pricing_override_modal() {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'product' !== $post_type || ! current_user_can( 'edit_products' ) ) { return; }
		$nonce = wp_create_nonce( 'mobo_core_product_pricing_override' );
		?>
		<div id="mobo-pricing-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100100;align-items:center;justify-content:center">
			<div role="dialog" aria-modal="true" aria-labelledby="mobo-pricing-title" style="direction:rtl;background:#fff;width:min(480px,calc(100vw - 32px));border-radius:10px;box-shadow:0 18px 60px rgba(0,0,0,.25);padding:22px">
				<h2 id="mobo-pricing-title" style="margin:0 0 8px">قیمت‌گذاری اختصاصی موبو</h2>
				<p id="mobo-pricing-product" style="margin:0 0 6px;font-weight:600"></p>
				<p id="mobo-pricing-connection" style="margin:0 0 14px;color:#646970"></p>
				<label style="display:block;margin:10px 0"><input type="radio" name="mobo_pricing_mode" value="global"> استفاده از تنظیمات عمومی اتصال مالک</label>
				<label style="display:block;margin:10px 0"><input type="radio" name="mobo_pricing_mode" value="custom"> سود اختصاصی درصدی برای این محصول و تنوع‌های موبویی آن (به‌جز واریانت‌هایی که سود اختصاصی خودشان را دارند)</label>
				<div id="mobo-pricing-custom-wrap" style="margin:12px 0"><label for="mobo-pricing-value">درصد سود اختصاصی</label><div style="display:flex;align-items:center;gap:6px;margin-top:6px"><input id="mobo-pricing-value" type="number" min="0" max="10000" step="0.01" style="width:150px" inputmode="decimal"><strong>%</strong></div></div>
				<p id="mobo-pricing-global-description" style="color:#646970"></p>
				<div id="mobo-pricing-message" role="status" aria-live="polite" style="display:none;margin:10px 0;padding:8px 10px;border-radius:6px"></div>
				<div style="display:flex;gap:8px;justify-content:flex-start;margin-top:18px"><button type="button" class="button button-primary" id="mobo-pricing-save">ذخیره و قیمت‌گذاری مجدد</button><button type="button" class="button" id="mobo-pricing-cancel">انصراف</button></div>
			</div>
		</div>
		<script>
		(function(){
			var modal=document.getElementById('mobo-pricing-modal'), pid=0, save=document.getElementById('mobo-pricing-save'), msg=document.getElementById('mobo-pricing-message'), value=document.getElementById('mobo-pricing-value'), wrap=document.getElementById('mobo-pricing-custom-wrap'), opener=null;
			function mode(){var e=document.querySelector('input[name="mobo_pricing_mode"]:checked');return e?e.value:'global';}
			function refresh(){wrap.style.display=mode()==='custom'?'block':'none';}
			function closeModal(){modal.style.display='none';if(opener&&typeof opener.focus==='function'){opener.focus();}}
			document.addEventListener('click',function(e){var a=e.target.closest('.mobo-pricing-row-action');if(!a)return;e.preventDefault();opener=a;pid=parseInt(a.dataset.productId||'0',10);document.getElementById('mobo-pricing-product').textContent=a.dataset.productName||('محصول #'+pid);document.getElementById('mobo-pricing-connection').textContent='اتصال مالک: '+(a.dataset.connectionLabel||('#'+(a.dataset.ownerId||'')));document.getElementById('mobo-pricing-global-description').textContent=a.dataset.globalDescription||'';var m=a.dataset.mode==='custom'?'custom':'global';document.querySelector('input[name="mobo_pricing_mode"][value="'+m+'"]').checked=true;value.value=a.dataset.value||'';msg.style.display='none';save.disabled=false;refresh();modal.style.display='flex';setTimeout(function(){var checked=document.querySelector('input[name="mobo_pricing_mode"]:checked');if(checked)checked.focus();},0);});
			document.querySelectorAll('input[name="mobo_pricing_mode"]').forEach(function(r){r.addEventListener('change',refresh);});
			document.getElementById('mobo-pricing-cancel').addEventListener('click',closeModal);
			modal.addEventListener('click',function(e){if(e.target===modal)closeModal();});
			document.addEventListener('keydown',function(e){if(e.key==='Escape'&&modal.style.display!=='none'){e.preventDefault();closeModal();}});
			save.addEventListener('click',function(){var m=mode(), v=value.value;if(m==='custom'&&(v===''||isNaN(Number(v))||Number(v)<0||Number(v)>10000)){msg.textContent='درصد سود باید عددی بین ۰ تا ۱۰۰۰۰ باشد.';msg.style.cssText='display:block;margin:10px 0;padding:8px 10px;border-radius:6px;background:#fcf0f1;color:#8a2424';return;}save.disabled=true;msg.textContent='در حال ذخیره و قیمت‌گذاری…';msg.style.cssText='display:block;margin:10px 0;padding:8px 10px;border-radius:6px;background:#f0f6fc;color:#135e96';var body=new URLSearchParams({action:'mobo_core_save_product_pricing_override',nonce:'<?php echo esc_js( $nonce ); ?>',product_id:String(pid),mode:m,value:v});fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(function(r){return r.json();}).then(function(r){if(!r||!r.success)throw new Error(r&&r.data&&r.data.message?r.data.message:'ذخیره انجام نشد.');msg.textContent=r.data.message||'ذخیره شد.';msg.style.cssText='display:block;margin:10px 0;padding:8px 10px;border-radius:6px;background:#edfaef;color:#166534';setTimeout(function(){window.location.reload();},650);}).catch(function(err){msg.textContent=err.message||'خطا در ذخیره.';msg.style.cssText='display:block;margin:10px 0;padding:8px 10px;border-radius:6px;background:#fcf0f1;color:#8a2424';save.disabled=false;});});
		})();
		</script>
		<?php
	}

	/** Secure AJAX endpoint for the durable parent-level pricing request. */
	public function handle_ajax_save_product_pricing_override() {
		check_ajax_referer( 'mobo_core_product_pricing_override', 'nonce' );
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		if ( $product_id <= 0 || ! current_user_can( 'edit_post', $product_id ) || ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی برای ویرایش این محصول وجود ندارد.' ), 403 );
		}
		$context = $this->product_list_product_context( $product_id );
		if ( empty( $context['available'] ) ) {
			wp_send_json_error( array( 'message' => 'هویت/مالکیت موبو در حال حاضر قابل اثبات نیست؛ قیمت‌گذاری انجام نشد.' ), 503 );
		}
		if ( empty( $context['isMobo'] ) || absint( $context['ownerId'] ?? 0 ) <= 0 ) {
			wp_send_json_error( array( 'message' => 'اتصال مالک این محصول قابل تشخیص نیست؛ قیمت‌گذاری انجام نشد.' ), 409 );
		}
		if ( ! empty( $context['excluded'] ) ) {
			wp_send_json_error( array( 'message' => 'این محصول در استثناهای همگام‌سازی قرار دارد و قابل قیمت‌گذاری از این مسیر نیست.' ), 409 );
		}
		if ( empty( $context['mutationReady'] ) ) {
			wp_send_json_error( array( 'message' => 'اتصال مالک محصول فعال و آماده نیست؛ قیمت‌گذاری انجام نشد.' ), 409 );
		}
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'global';
		$value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
		$owner_id = absint( $context['ownerId'] );
		$runner = static function () use ( $product_id, $mode, $value ) {
			return Mobo_Core_Product_Pricing_Policy::request( $product_id, $mode, $value );
		};
		$result = class_exists( 'Mobo_Core_Connection_Context' ) ? Mobo_Core_Connection_Context::run( $owner_id, $runner ) : call_user_func( $runner );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
		if ( ! empty( $result['queued'] ) ) {
			wp_send_json_success( array( 'status' => 'queued', 'message' => 'درخواست ذخیره شد و برای اجرای امن پس از آزاد شدن قفل محصول در صف قرار گرفت.' ) );
		}
		wp_send_json_success( array( 'status' => 'applied', 'message' => sprintf( 'سیاست قیمت‌گذاری ذخیره شد و روی %d مورد موبویی اعمال شد.', isset( $result['processed'] ) ? absint( $result['processed'] ) : 0 ) ) );
	}



	/**
	 * Enqueue admin assets for the Mobo settings page.
	 *
	 * Searchable selects use MoboCore's own scoped picker. This avoids Select2/SelectWoo
	 * CSS/positioning conflicts with other wp-admin plugins. Native selects remain the
	 * mobile fallback and the submitted form control.
	 *
	 * @param string $hook Admin hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::MENU_SLUG !== $page && 0 !== strpos( $page, self::MENU_SLUG . '&tab=' ) && 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		$asset_version = static function ( $relative ) {
			$path = MOBO_CORE_PLUGIN_DIR . ltrim( (string) $relative, '/' );
			return is_file( $path ) ? (string) filemtime( $path ) : ( defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : null );
		};

		wp_enqueue_style(
			'mobo-core-admin-core',
			MOBO_CORE_PLUGIN_URL . 'assets/admin/css/mobo-admin-core.css',
			array(),
			$asset_version( 'assets/admin/css/mobo-admin-core.css' )
		);
		wp_enqueue_style(
			'mobo-core-admin-components',
			MOBO_CORE_PLUGIN_URL . 'assets/admin/css/mobo-admin-components.css',
			array( 'mobo-core-admin-core' ),
			$asset_version( 'assets/admin/css/mobo-admin-components.css' )
		);
		wp_enqueue_script(
			'mobo-core-admin-core',
			MOBO_CORE_PLUGIN_URL . 'assets/admin/js/mobo-admin-core.js',
			array(),
			$asset_version( 'assets/admin/js/mobo-admin-core.js' ),
			true
		);
		wp_enqueue_script(
			'mobo-core-admin-picker',
			MOBO_CORE_PLUGIN_URL . 'assets/admin/js/mobo-admin-picker.js',
			array( 'mobo-core-admin-core' ),
			$asset_version( 'assets/admin/js/mobo-admin-picker.js' ),
			true
		);

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'connection' === $active_tab ) {
			wp_enqueue_style(
				'mobo-core-admin-connection',
				MOBO_CORE_PLUGIN_URL . 'assets/admin/css/pages/connection.css',
				array( 'mobo-core-admin-components' ),
				$asset_version( 'assets/admin/css/pages/connection.css' )
			);
			wp_enqueue_script(
				'mobo-core-admin-connection',
				MOBO_CORE_PLUGIN_URL . 'assets/admin/js/pages/connection.js',
				array( 'mobo-core-admin-core' ),
				$asset_version( 'assets/admin/js/pages/connection.js' ),
				true
			);
		}

		$page_styles = array(
			'dashboard' => 'dashboard.css',
			'purchase'  => 'purchase.css',
			'product'   => 'product.css',
		);
		if ( isset( $page_styles[ $active_tab ] ) ) {
			$relative_style = 'assets/admin/css/pages/' . $page_styles[ $active_tab ];
			wp_enqueue_style(
				'mobo-core-admin-' . $active_tab,
				MOBO_CORE_PLUGIN_URL . $relative_style,
				array( 'mobo-core-admin-components' ),
				$asset_version( $relative_style )
			);
		}

		$remaining_tabs = array( 'categories', 'pricing', 'filters', 'queue', 'sync-health', 'image-refresh', 'cron', 'checkout', 'sms', 'health', 'data-health', 'support' );
		if ( in_array( $active_tab, $remaining_tabs, true ) ) {
			$remaining_style = 'assets/admin/css/pages/remaining.css';
			wp_enqueue_style(
				'mobo-core-admin-remaining',
				MOBO_CORE_PLUGIN_URL . $remaining_style,
				array( 'mobo-core-admin-components' ),
				$asset_version( $remaining_style )
			);

			$remaining_script = 'assets/admin/js/pages/remaining.js';
			wp_enqueue_script(
				'mobo-core-admin-remaining',
				MOBO_CORE_PLUGIN_URL . $remaining_script,
				array( 'mobo-core-admin-core' ),
				$asset_version( $remaining_script ),
				true
			);
		}
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		// Defense in depth: admin_init normally establishes this context first, but
		// render() must never instantiate Source-owned services before the selected
		// Connection has been applied.
		$this->activate_admin_connection_context();

		$product_sync = new Mobo_Core_Product_Sync();
		$status       = $product_sync->get_manual_sync_status();
		$is_running        = ! empty( $status['isRunning'] );
		$is_waiting        = ! empty( $status['isWaitingForPortal'] );
		$is_repair_run     = ! empty( $status['repairMode'] );
		$sync_display_label = $this->sync_status_label_for_display( $status );

		// Read-only admin navigation parameter; no state is changed here.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$advanced_tabs = array_keys( $this->get_advanced_navigation_items() );
		$advanced_view = 'advanced' === $requested_view || in_array( $active_tab, $advanced_tabs, true );

		$allowed_tabs = array(
			'dashboard',
			'connection',
			'purchase',
			'product',
			'categories',
			'pricing',
			'filters',
			'queue',
			'sync-health',
			'image-refresh',
			'cron',
			'checkout',
			'sms',
			'health',
			'data-health',
			'support',
		);

		if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
			$active_tab = 'dashboard';
		}

		$this->render_styles();
		$this->render_scripts();

		$page_titles = array(
			'dashboard'   => array( 'نمای کلی', 'وضعیت کلی همگام‌سازی، سلامت اتصال و عملیات روزمره MoboCore.' ),
			'connection'  => array( 'اتصال و لایسنس', 'اتصال‌های MoboCore، وضعیت سرویس مرکزی و اطلاعات لایسنس را از این بخش مدیریت کنید.' ),
			'purchase'    => array( 'خرید و فعال‌سازی', 'مدیریت خرید، فعال‌سازی و راهنمای راه‌اندازی سرویس.' ),
			'product'     => array( 'تنظیمات محصول', 'کنترل رفتار همگام‌سازی محصول، موجودی، قیمت و تصاویر.' ),
			'categories'  => array( 'دسته‌بندی‌ها', 'مدیریت نگاشت و سیاست همگام‌سازی دسته‌بندی‌های ووکامرس.' ),
			'pricing'     => array( 'قیمت‌گذاری', 'مدیریت سیاست‌ها و قواعد قیمت‌گذاری محصولات موبویی.' ),
			'filters'     => array( 'فیلتر محصولات', 'استثناهای همگام‌سازی را برای محصولاتی مدیریت کنید که منبع نباید آن‌ها را تغییر دهد.' ),
			'queue'       => array( 'سرعت و صف‌ها', 'تنظیم ظرفیت پردازش برای هماهنگی با توان هاست.' ),
			'sync-health' => array( 'سلامت همگام‌سازی', 'وضعیت عملیاتی فعلی محصولات موبویی و مسیر بازیابی موارد ناهماهنگ.' ),
			'image-refresh' => array( 'نوسازی تصاویر', 'اجرای چرخه امن دریافت مجدد، جایگزینی WebP و پاک‌سازی کنترل‌شده رسانه‌های قدیمی.' ),
			'cron'        => array( 'Cron و Worker', 'بررسی اجرای زمان‌بندی‌شده و Workerهای MoboCore.' ),
			'checkout'    => array( 'سفارش موبو', 'کنترل ثبت خودکار، اعتبارسنجی، آدرس و روش ارسال سفارش موبویی.' ),
			'sms'         => array( 'پیامک', 'مدیریت پیامک سفارش و هشدار موجودی کیف پول.' ),
			'health'      => array( 'سلامت سایت', 'بررسی پیش‌نیازهای فنی اثرگذار بر اجرای MoboCore.' ),
			'data-health' => array( 'سلامت داده', 'یافتن و ترمیم امن داده‌های خراب‌شده محصولات، با پیش‌نمایش، پشتیبان و امکان بازگردانی.' ),
			'support'     => array( 'پشتیبانی', 'دسترسی امن پشتیبانی موبو بدون رد و بدل کردن رمز عبور، و بررسی مواردی که باید قبل از پشتیبانی اصلاح شوند.' ),
		);
		$page_heading = isset( $page_titles[ $active_tab ] ) ? $page_titles[ $active_tab ] : $page_titles['dashboard'];

		?>
		<div id="mobo-core-admin" class="wrap mobo-wrap mobo-admin mobo-admin--<?php echo esc_attr( $active_tab ); ?>" dir="rtl">
			<div class="mobo-hero">
				<div>
					<h1><?php echo esc_html( $page_heading[0] ); ?></h1>
					<p><?php echo esc_html( $page_heading[1] ); ?></p>
				</div>

				<?php if ( 'connection' === $active_tab ) : ?>
					<div class="mobo-admin-hero-actions">
						<a class="mobo-admin-btn" href="<?php echo esc_url( $this->admin_page_url( array( 'tab' => 'connection' ) ) ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span>به‌روزرسانی وضعیت</a>
					</div>
				<?php else : ?>
					<div class="mobo-hero-badge">
						<span>همگام‌سازی محصولات</span>
						<strong data-mobo-sync-hero-status="1" class="<?php echo $is_waiting ? 'is-waiting' : ( $is_running ? 'is-running' : 'is-idle' ); ?>">
							<?php echo esc_html( $sync_display_label ); ?>
						</strong>
					</div>
				<?php endif; ?>
			</div>

			<?php $this->render_connection_selector( $active_tab, $advanced_view ); ?>
			<?php $this->render_notices(); ?>


			<nav class="mobo-tabs <?php echo $advanced_view ? 'mobo-tabs--advanced' : ''; ?>" aria-label="زبانه‌های تنظیمات موبو">
				<?php $nav_items = $advanced_view ? $this->get_advanced_navigation_items() : $this->get_admin_navigation_items( false ); ?>
				<?php foreach ( $nav_items as $tab => $label ) : ?>
					<?php $this->tab_link( $tab, $label, $active_tab ); ?>
				<?php endforeach; ?>
				<?php if ( $advanced_view ) : ?>
					<a class="mobo-tab mobo-tab-view" href="<?php echo esc_url( $this->admin_page_url() ); ?>">بازگشت به تنظیمات اصلی</a>
				<?php else : ?>
					<a class="mobo-tab mobo-tab-view" href="<?php echo esc_url( $this->admin_page_url( array( 'view' => 'advanced', 'tab' => 'filters' ) ) ); ?>">تنظیمات پیشرفته</a>
				<?php endif; ?>
			</nav>

			<div class="mobo-panel">
				<?php if ( 'dashboard' === $active_tab ) : ?>
					<?php $this->render_dashboard_tab( $status ); ?>
				<?php elseif ( 'connection' === $active_tab ) : ?>
					<?php $this->render_connection_tab(); ?>
				<?php elseif ( 'purchase' === $active_tab ) : ?>
					<?php $this->render_purchase_tab(); ?>
				<?php elseif ( 'product' === $active_tab ) : ?>
					<?php $this->render_product_tab(); ?>
				<?php elseif ( 'categories' === $active_tab ) : ?>
					<?php $this->render_categories_tab(); ?>
				<?php elseif ( 'pricing' === $active_tab ) : ?>
					<?php $this->render_pricing_tab(); ?>
				<?php elseif ( 'filters' === $active_tab ) : ?>
					<?php $this->render_filters_tab(); ?>
				<?php elseif ( 'queue' === $active_tab ) : ?>
					<?php $this->render_queue_tab(); ?>
				<?php elseif ( 'sync-health' === $active_tab ) : ?>
					<?php $this->render_sync_health_tab(); ?>
				<?php elseif ( 'image-refresh' === $active_tab ) : ?>
					<?php $this->render_image_refresh_tab(); ?>
				<?php elseif ( 'cron' === $active_tab ) : ?>
					<?php $this->render_cron_tab(); ?>
				<?php elseif ( 'checkout' === $active_tab ) : ?>
					<?php $this->render_checkout_tab(); ?>
				<?php elseif ( 'sms' === $active_tab ) : ?>
					<?php $this->render_sms_tab(); ?>
				<?php elseif ( 'health' === $active_tab ) : ?>
					<?php $this->render_health_tab(); ?>
				<?php elseif ( 'data-health' === $active_tab ) : ?>
					<?php Mobo_Core_Data_Health_Admin::render_tab(); ?>
				<?php elseif ( 'support' === $active_tab ) : ?>
					<?php Mobo_Core_Support_Admin::render_tab(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Return the category-fallback warning when the configured fallback cannot
	 * currently be used. Product creation itself is intentionally not blocked by
	 * this condition; the warning exists to prevent that behavior from being
	 * mistaken for a failed import.
	 *
	 * @return array Empty when healthy, otherwise title/message.
	 */
	private function get_category_fallback_warning() {
		$policy            = Mobo_Core_Category_Assignment_Policy::settings();
		$fallback          = Mobo_Core_Category_Assignment_Policy::fallback_status();
		$mapping_required  = (bool) $policy['mapping_required'];
		$auto_categories   = (bool) $policy['auto_categories_enabled'];

		if ( 'valid' === $fallback['status'] ) {
			return array();
		}

		if ( 'invalid' === $fallback['status'] ) {
			$title   = 'دسته پیش‌فرض معتبر نیست';
			$message = 'دسته‌ای که قبلاً به‌عنوان پیش‌فرض انتخاب شده دیگر در ووکامرس وجود ندارد.';
		} else {
			$title   = 'دسته پیش‌فرض انتخاب نشده است';
			$message = 'اگر دسته منبع از مسیر عادی تعیین نشود، دسته پیش‌فرض آخرین مسیر جایگزین است.';
		}

		if ( $mapping_required ) {
			$message .= ' چون نگاشت دستی اجباری است، فقط نگاشت معتبر پذیرفته می‌شود و دسته پیش‌فرض جای نگاشت اجباری را نمی‌گیرد.';
		} elseif ( $auto_categories ) {
			$message .= ' ساخت خودکار دسته روشن است؛ بنابراین این هشدار فقط برای مواردی است که دسته منبع قابل تشخیص یا ساخت نباشد.';
		} else {
			$message .= ' ساخت خودکار دسته خاموش است؛ بهتر است یک دسته پیش‌فرض معتبر انتخاب کنید.';
		}

		return array(
			'title'   => $title,
			'message' => $message,
		);
	}

	/**
	 * Render category fallback warning in dashboard/categories.
	 *
	 * @param bool $with_link Add categories-tab link.
	 * @return void
	 */
	private function render_category_fallback_warning( $with_link = false ) {
		$warning = $this->get_category_fallback_warning();
		if ( empty( $warning ) ) {
			return;
		}
		?>
		<?php if ( $with_link ) : ?>
			<div class="mobo-dashboard-attention" data-mobo-category-fallback-warning>
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<div>
					<strong><?php echo esc_html( (string) $warning['title'] ); ?></strong>
					<span><?php echo esc_html( (string) $warning['message'] ); ?></span>
				</div>
				<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( $this->admin_page_url( array( 'tab' => 'categories' ) ) ); ?>">بررسی دسته‌بندی‌ها</a>
			</div>
		<?php else : ?>
			<div class="mobo-message mobo-message-warning" data-mobo-category-fallback-warning>
				<strong><?php echo esc_html( (string) $warning['title'] ); ?>:</strong>
				<?php echo esc_html( (string) $warning['message'] ); ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render dashboard tab.
	 *
	 * @param array $status Status.
	 * @return void
	 */
	private function render_dashboard_tab( $status ) {
		$is_running = ! empty( $status['isRunning'] );
		$is_waiting = ! empty( $status['isWaitingForPortal'] );
		$is_done    = ! empty( $status['isDone'] );
		$repair_completed_at = class_exists( 'Mobo_Core_Product_Sync' ) ? Mobo_Core_Product_Sync::get_repair_completed_at() : 0;
		$is_repair_completed = $repair_completed_at > 0;
		$legacy_repair_required = '1' === (string) get_option( 'mobo_core_legacy_repair_required', '0' ) && ! $is_repair_completed;
		$version_status      = $this->get_build_integrity_status();
		$security_code       = $this->current_admin_security_code();
		$security_code_valid = $this->is_webhook_security_code_valid( $security_code );
		$build_ok            = ! empty( $version_status['allMatch'] );
		$sync_summary_label  = $this->sync_status_label_for_display( $status );
		$sync_summary_class  = $is_waiting ? 'is-warning' : ( $is_running || $is_done ? 'is-success' : '' );
		$has_sync_progress   = $is_running || $is_waiting || $is_done || absint( $status['processedProducts'] ?? 0 ) > 0 || absint( $status['remainingProducts'] ?? 0 ) > 0;
		$pricing_warning     = $this->get_pricing_health_warning();
		$duplicate_groups_count = class_exists( 'Mobo_Core_Product_Concurrency' ) ? Mobo_Core_Product_Concurrency::count_duplicate_product_groups() : 0;
		$category_fallback_warning = $this->get_category_fallback_warning();
		$wallet_status = class_exists( 'Mobo_Core_Wallet_Alert' ) ? Mobo_Core_Wallet_Alert::get_status() : array();

		$has_token    = '' !== $this->current_admin_license_token();
		$license_info = $has_token ? $this->get_license_info_for_display() : array(
			'error'     => true,
			'isExpired' => false,
			'message'   => 'توکن API برای این اتصال ثبت نشده است.',
			'raw'       => array(),
			'checkedAt' => 0,
		);
		$license_error   = ! empty( $license_info['error'] );
		$license_expired = ! empty( $license_info['isExpired'] );

		if ( ! $has_token ) {
			$service_summary_label = 'توکن ثبت نشده';
			$service_summary_class = 'is-warning';
			$license_summary_label = 'توکن ثبت نشده';
			$license_summary_class = 'is-warning';
		} else {
			$service_view_dashboard = $this->connection_service_status_for_display( $license_info, true, true );
			$license_view_dashboard = $this->license_status_for_display( $license_info );
			$service_summary_label = $service_view_dashboard['label'];
			$service_summary_class = 'is-' . ( 'info' === $service_view_dashboard['state'] ? 'warning' : $service_view_dashboard['state'] );
			$license_summary_label = $license_view_dashboard['label'];
			$license_summary_class = 'is-' . ( 'info' === $license_view_dashboard['state'] ? 'warning' : $license_view_dashboard['state'] );
		}

		$webhook_status = class_exists( 'Mobo_Core_Webhook_Auth_Status' )
			? Mobo_Core_Webhook_Auth_Status::get_status()
			: array( 'status' => 'unknown' );
		$webhook_code = isset( $webhook_status['status'] ) ? sanitize_key( (string) $webhook_status['status'] ) : 'unknown';
		if ( '' === $security_code ) {
			$webhook_summary_label = 'ثبت نشده';
			$webhook_summary_class = 'is-danger';
		} elseif ( ! $security_code_valid || in_array( $webhook_code, array( 'invalid', 'misconfigured', 'missing' ), true ) ) {
			$webhook_summary_label = 'نیازمند بررسی';
			$webhook_summary_class = 'is-danger';
		} elseif ( 'valid' === $webhook_code ) {
			$webhook_summary_label = 'تأیید شده';
			$webhook_summary_class = 'is-success';
		} else {
			$webhook_summary_label = 'تست نشده';
			$webhook_summary_class = 'is-warning';
		}

		$license_checked_at    = isset( $license_info['checkedAt'] ) ? absint( $license_info['checkedAt'] ) : 0;
		$license_checked_label = $this->dashboard_checked_label( $license_checked_at );
		$webhook_checked_at    = isset( $webhook_status['checkedAt'] ) ? absint( $webhook_status['checkedAt'] ) : 0;
		$webhook_checked_label = $this->dashboard_checked_label( $webhook_checked_at );
		$wallet_supported      = ! empty( $wallet_status['supported'] );
		$wallet_runtime_ready  = ! empty( $wallet_status['runtimeReady'] );
		$wallet_has_issue      = $wallet_runtime_ready && ( ! empty( $wallet_status['lastError'] ) || ( ! empty( $wallet_status['enabled'] ) && ! empty( $wallet_status['smsLastError'] ) ) );
		$sync_has_issue        = ! empty( $status['lastError'] ) || ! empty( $status['lastTransientError'] );
		$dashboard_has_active_issue = $legacy_repair_required
			|| 'is-success' !== $service_summary_class
			|| 'is-success' !== $license_summary_class
			|| 'is-success' !== $webhook_summary_class
			|| ! $build_ok
			|| ! empty( $category_fallback_warning )
			|| '' !== $pricing_warning
			|| $duplicate_groups_count > 0
			|| $wallet_has_issue
			|| $sync_has_issue;

		?>
		<div class="mobo-dashboard-summary" aria-label="خلاصه وضعیت MoboCore">
			<div class="mobo-dashboard-summary__item <?php echo esc_attr( $sync_summary_class ); ?>">
				<span class="mobo-dashboard-summary__label"><span class="dashicons dashicons-update" aria-hidden="true"></span>همگام‌سازی</span>
				<strong class="mobo-dashboard-summary__value" data-mobo-dashboard-sync-summary><?php echo esc_html( $sync_summary_label ); ?></strong>
			</div>
			<div class="mobo-dashboard-summary__item <?php echo esc_attr( $service_summary_class ); ?>">
				<span class="mobo-dashboard-summary__label"><span class="dashicons dashicons-cloud" aria-hidden="true"></span>سرویس مرکزی</span>
				<strong class="mobo-dashboard-summary__value"><?php echo esc_html( $service_summary_label ); ?></strong>
				<small class="mobo-dashboard-summary__meta">آخرین بررسی: <?php echo esc_html( $license_checked_label ); ?></small>
			</div>
			<div class="mobo-dashboard-summary__item <?php echo esc_attr( $license_summary_class ); ?>">
				<span class="mobo-dashboard-summary__label"><span class="dashicons dashicons-awards" aria-hidden="true"></span>لایسنس</span>
				<strong class="mobo-dashboard-summary__value"><?php echo esc_html( $license_summary_label ); ?></strong>
				<small class="mobo-dashboard-summary__meta">آخرین بررسی: <?php echo esc_html( $license_checked_label ); ?></small>
			</div>
			<div class="mobo-dashboard-summary__item <?php echo esc_attr( $webhook_summary_class ); ?>">
				<span class="mobo-dashboard-summary__label"><span class="dashicons dashicons-shield" aria-hidden="true"></span>وب‌هوک</span>
				<strong class="mobo-dashboard-summary__value"><?php echo esc_html( $webhook_summary_label ); ?></strong>
				<small class="mobo-dashboard-summary__meta">آخرین تست: <?php echo esc_html( $webhook_checked_label ); ?></small>
			</div>
		</div>
		<?php if ( ! $dashboard_has_active_issue ) : ?>
			<div class="mobo-message mobo-message-success mobo-dashboard-clean-state">
				<strong>در حال حاضر موردی نیاز به بررسی ندارد.</strong>
				<span>اتصال، لایسنس، وب‌هوک و تنظیمات اصلی بررسی‌شده در وضعیت عادی هستند.</span>
			</div>
		<?php endif; ?>
		<?php if ( $legacy_repair_required ) : ?>
			<div class="mobo-card" data-mobo-legacy-repair-required>
				<div class="mobo-card-head">
					<h2>نیاز به Repair بعد از ارتقا از نسخه قدیمی</h2>
					<p>برای سایت‌هایی که از نسخه‌های قدیمی مثل ۷ به نسخه جدید آمده‌اند، یک Repair کامل لازم است تا محصول‌ها، map داخلی و صف تصاویر با ساختار جدید همخوان شوند.</p>
				</div>
				<div class="mobo-message mobo-message-warning">
					نوسازی تصاویر Repair یا Reconciliation محصولات را خودکار شروع نمی‌کند. ابتدا Repair را با تصمیم مدیر تکمیل کنید؛ سپس Workflow تصاویر فقط مراحل مربوط به رسانه را انجام می‌دهد.
				</div>
			</div>
		<?php endif; ?>

		<?php if ( '' === $security_code ) : ?>
			<div class="mobo-message mobo-message-error"><strong>وب هوک آماده نیست:</strong> کد امنیتی وب هوک ثبت نشده است. از تب اتصال کد معتبر دریافت و ذخیره کنید.</div>
		<?php elseif ( ! $security_code_valid ) : ?>
			<div class="mobo-message mobo-message-error"><strong>وب‌هوک قابل ارسال نیست:</strong> کد امنیتی فعلی برای Header معتبر نیست. مقدار باید فقط شامل ASCII قابل‌چاپ و بدون فاصله باشد؛ نمادهایی مانند <code dir="ltr">@ # $ % &amp; * _ [ ] -</code> مجاز هستند، اما حروف فارسی و سایر Unicodeها مجاز نیستند.</div>
		<?php endif; ?>

		<?php if ( $security_code_valid && in_array( $webhook_code, array( 'invalid', 'missing', 'misconfigured' ), true ) ) : ?>
			<?php $this->render_webhook_auth_test_status(); ?>
		<?php endif; ?>

		<?php $this->render_category_fallback_warning( true ); ?>

		<div class="mobo-grid mobo-grid-dashboard">
			<div class="mobo-card mobo-card-wide mobo-dashboard-sync-card" id="mobo-sync-status-card" data-ajax-url="<?php echo esc_url( $this->admin_ajax_url() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mobo_core_sync_status' ) ); ?>">
				<div class="mobo-card-head">
					<h2>وضعیت همگام‌سازی محصولات</h2>
					<p>نمای کلی از آخرین وضعیت sync محصول.</p>
				</div>

				<?php if ( $has_sync_progress ) : ?>
					<div class="mobo-progress-wrap">
						<div class="mobo-progress-meta">
							<span>پیشرفت</span>
							<strong><span data-mobo-sync-field="progressPercent"><?php echo esc_html( (string) $this->format_percent( $status['progressPercent'] ) ); ?></span>٪</strong>
						</div>
						<div class="mobo-progress">
							<div data-mobo-sync-progress-bar="1" style="width: <?php echo esc_attr( min( 100, max( 0, (float) $status['progressPercent'] ) ) ); ?>%;"></div>
						</div>
					</div>
				<?php else : ?>
					<div class="mobo-message mobo-message-info mobo-dashboard-sync-idle">هنوز اجرای همگام‌سازی شروع نشده است. برای شروع از بخش «همگام‌سازی محصولات» استفاده کنید.</div>
				<?php endif; ?>

				<div class="mobo-status-grid mobo-dashboard-sync-primary-facts<?php echo $has_sync_progress ? '' : ' is-idle'; ?>">
					<?php $this->status_box( 'وضعیت', $sync_summary_label, 'statusLabel' ); ?>
					<?php if ( $has_sync_progress ) : ?>
						<?php $this->status_box( 'پردازش‌شده در این اجرا', absint( $status['processedProducts'] ), 'processedProducts' ); ?>
						<?php $this->status_box( 'باقی‌مانده این اجرا', absint( $status['remainingProducts'] ), 'remainingProducts' ); ?>
					<?php endif; ?>
				</div>

				<details class="mobo-admin-details mobo-dashboard-sync-details">
					<summary>جزئیات اجرای همگام‌سازی</summary>
					<div class="mobo-admin-details__body">
						<div class="mobo-status-grid">
							<?php if ( ! empty( $status['syncId'] ) ) : ?>
								<?php $this->status_box( 'شناسه همگام‌سازی', $status['syncId'], 'syncId' ); ?>
							<?php endif; ?>
							<?php $this->status_box( 'نوع اجرا', ! empty( $status['repairMode'] ) ? 'ترمیم کامل / عبور از Hash' : 'همگام‌سازی عادی' ); ?>
							<?php if ( ! empty( $status['nextRetryAt'] ) ) : ?>
								<?php $this->status_box( 'تلاش بعدی', Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $status['nextRetryAt'] ) ), 'nextRetryAt' ); ?>
							<?php endif; ?>
							<?php if ( absint( $status['productPage'] ?? 0 ) > 0 ) : ?>
								<?php $this->status_box( 'صفحه محصول', absint( $status['productPage'] ), 'productPage' ); ?>
							<?php endif; ?>
							<?php if ( absint( $status['variantPage'] ?? 0 ) > 0 ) : ?>
								<?php $this->status_box( 'صفحه تنوع', absint( $status['variantPage'] ), 'variantPage' ); ?>
							<?php endif; ?>
							<?php if ( $is_repair_completed || ! empty( $status['repairMode'] ) || $legacy_repair_required ) : ?>
								<?php $this->status_box( 'ترمیم کامل', $is_repair_completed ? 'انجام شده: ' . Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', $repair_completed_at ) : 'انجام نشده' ); ?>
							<?php endif; ?>
							<?php if ( ! empty( $status['repairMode'] ) ) : ?>
								<?php $this->status_box( 'وضعیت دسته‌بندی Repair', ! empty( $status['categorySyncDegraded'] ) ? 'degraded؛ محصولات ادامه می‌یابند' : ( ! empty( $status['categorySynced'] ) ? 'کامل' : 'در حال بررسی' ) ); ?>
								<?php $this->status_box( 'دسته‌بندی unresolved', absint( $status['categorySyncSkippedRows'] ?? 0 ) ); ?>
								<?php $this->status_box( 'دسته‌بندی source-retired', absint( $status['categoryRetiredRows'] ?? 0 ) ); ?>
								<?php $this->status_box( 'محصول quarantine به‌علت lock', absint( $status['repairProductLockQuarantined'] ?? 0 ) ); ?>
							<?php endif; ?>
						</div>
					</div>
				</details>

				<div class="mobo-message mobo-message-info" data-mobo-sync-message="lastMessage" style="<?php echo empty( $status['lastMessage'] ) ? 'display:none;' : ''; ?>">
					<?php echo esc_html( $status['lastMessage'] ); ?>
				</div>

				<div class="mobo-message mobo-message-error" data-mobo-sync-message="lastError" style="<?php echo empty( $status['lastError'] ) ? 'display:none;' : ''; ?>">
					<?php echo esc_html( $status['lastError'] ); ?>
				</div>

				<div class="mobo-message mobo-message-warning" data-mobo-sync-message="lastTransientError" style="<?php echo empty( $status['lastTransientError'] ) ? 'display:none;' : ''; ?>">
					<?php echo esc_html( $status['lastTransientError'] ); ?>
				</div>

				<?php $this->render_job_diagnostics_block( Mobo_Core_Job_Diagnostics::manual_sync_job( $status ), $status, 'sync' ); ?>

				<?php if ( ! empty( $status['repairMode'] ) && ( ! empty( $status['categorySyncDegraded'] ) || absint( $status['repairProductLockQuarantined'] ?? 0 ) > 0 ) ) : ?>
					<div class="mobo-message mobo-message-warning">
						Repair برای جلوگیری از توقف بی‌نهایت از بعضی موارد عبور محافظه‌کارانه کرده است. دسته‌بندی‌های unresolved و محصولات lock-quarantined در شمارنده‌های بالا باقی می‌مانند و به‌عنوان convergence کامل پنهان نمی‌شوند.
					</div>
				<?php endif; ?>

				<?php if ( '' !== $pricing_warning ) : ?>
					<div class="mobo-message mobo-message-warning">
						<strong>هشدار قیمت‌گذاری:</strong> <?php echo esc_html( $pricing_warning ); ?>
					</div>
				<?php endif; ?>

				<?php if ( class_exists( 'Mobo_Core_Product_Concurrency' ) ) : ?>
					<?php
					/* M8: converge quarantined copies (off sale / released) while an admin looks. */
					if ( class_exists( 'Mobo_Core_Quarantine_Sale_Guard' ) && current_user_can( 'manage_woocommerce' ) ) {
						Mobo_Core_Quarantine_Sale_Guard::reconcile( 10 );
					}
					?>
					<?php if ( $duplicate_groups_count > 0 ) : ?>
						<div class="mobo-message mobo-message-error mobo-duplicate-incident">
							<strong>محصول تکراری نیاز به تصمیم دارد.</strong>
							<p><?php echo esc_html( sprintf( '%d شناسه محصول موبو هم‌زمان روی چند محصول ووکامرس فعال دیده شده است. برای جلوگیری از بروزرسانی اشتباه، فقط محصول اصلی پردازش می‌شود تا نسخه‌های تکراری تعیین تکلیف شوند.', absint( $duplicate_groups_count ) ) ); ?></p>
							<?php $duplicate_groups = Mobo_Core_Product_Concurrency::get_duplicate_product_groups( 3 ); ?>
							<?php if ( ! empty( $duplicate_groups ) ) : ?>
								<div class="mobo-duplicate-groups">
									<?php $duplicate_map = class_exists( 'Mobo_Core_Product_Map' ) ? new Mobo_Core_Product_Map() : null; ?>
									<?php foreach ( $duplicate_groups as $duplicate_group ) : ?>
										<?php
										$product_guid = sanitize_text_field( (string) ( $duplicate_group['product_guid'] ?? '' ) );
										$product_ids  = array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $duplicate_group['ids'] ?? '' ) ) ) ) );
										$preferred_id = absint( $duplicate_group['canonical_id'] ?? 0 ) > 0 ? absint( $duplicate_group['canonical_id'] ) : ( $duplicate_map instanceof Mobo_Core_Product_Map ? absint( $duplicate_map->get_product_id( $product_guid ) ) : 0 );
										$canonical_id = $preferred_id > 0 && in_array( $preferred_id, $product_ids, true ) ? $preferred_id : ( ! empty( $product_ids ) ? absint( $product_ids[0] ) : 0 );
										?>
										<div class="mobo-duplicate-group">
											<div class="mobo-duplicate-group__head">
												<span>شناسه منبع</span>
												<code dir="ltr"><?php echo esc_html( $product_guid ); ?></code>
											</div>
											<div class="mobo-duplicate-products">
												<?php foreach ( $product_ids as $product_id ) : ?>
													<?php
													$title = trim( wp_strip_all_tags( get_the_title( $product_id ) ) );
													if ( '' === $title ) {
														$title = 'محصول #' . $product_id;
													}
													$edit_url = get_edit_post_link( $product_id, 'raw' );
													$status_label = 'publish' === get_post_status( $product_id ) ? 'منتشرشده' : ( 'draft' === get_post_status( $product_id ) ? 'پیش‌نویس' : 'فعال محلی' );
													?>
													<div class="mobo-duplicate-product<?php echo $product_id === $canonical_id ? ' is-canonical' : ''; ?>">
														<div>
															<strong><?php echo $product_id === $canonical_id ? 'محصول اصلی' : 'نسخه تکراری'; ?></strong>
															<span><?php echo esc_html( $status_label ); ?></span>
														</div>
														<?php if ( $edit_url ) : ?>
															<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $title . ' (#' . $product_id . ')' ); ?></a>
														<?php else : ?>
															<span><?php echo esc_html( $title . ' (#' . $product_id . ')' ); ?></span>
														<?php endif; ?>
													</div>
												<?php endforeach; ?>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<div class="mobo-help">با این اقدام، محصول اصلی دست‌نخورده می‌ماند و نسخه‌های تکراری فقط به پیش‌نویس منتقل می‌شوند. اطلاعات حذف نمی‌شود و در صورت نیاز می‌توانید بعداً آن‌ها را بررسی کنید. پس از تعیین تکلیف موفق، این هشدار دیگر در نمای کلی نمایش داده نمی‌شود.</div>
							<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1" onsubmit="return confirm('محصول اصلی حفظ شود و نسخه‌های تکراری به پیش‌نویس منتقل شوند؟ اطلاعات حذف نمی‌شود.');" style="margin-top:10px;">
								<input type="hidden" name="action" value="mobo_core_quarantine_duplicate_products">
								<?php wp_nonce_field( 'mobo_core_quarantine_duplicate_products', 'mobo_core_nonce' ); ?>
								<button type="submit" class="mobo-btn mobo-btn-danger">حفظ محصول اصلی و کنار گذاشتن نسخه‌های تکراری</button>
							</form>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<div class="mobo-auto-refresh">
					<span data-mobo-sync-refresh-state>به‌روزرسانی خودکار فعال است.</span>
					<span data-mobo-sync-updated-at></span>
				</div>
			</div>

			<div class="mobo-card mobo-dashboard-actions-card">
				<div class="mobo-card-head">
					<h2>همگام‌سازی محصولات</h2>
					<p>در حالت عادی فقط اقدام پیشنهادی نمایش داده می‌شود. ابزارهای کم‌استفاده در بخش پیشرفته قرار دارند.</p>
				</div>

				<div class="mobo-dashboard-primary-action">
					<?php if ( $is_waiting ) : ?>
						<div class="mobo-dashboard-action-copy">
							<strong>اجرای قبلی قابل ادامه است.</strong>
							<span>همگام‌سازی از آخرین نقطه ذخیره‌شده ادامه پیدا می‌کند.</span>
						</div>
						<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1">
							<input type="hidden" name="action" value="mobo_core_resume_sync">
							<?php wp_nonce_field( 'mobo_core_resume_sync', 'mobo_core_nonce' ); ?>
							<button type="submit" class="mobo-btn mobo-btn-primary">ادامه همگام‌سازی</button>
						</form>
					<?php elseif ( $is_running ) : ?>
						<div class="mobo-dashboard-action-copy">
							<strong><?php echo ! empty( $status['repairMode'] ) ? 'Repair در حال اجرا است.' : 'همگام‌سازی در حال اجرا است.'; ?></strong>
							<span>برای جلوگیری از تداخل، تا پایان اجرای جاری عملیات دیگری شروع نمی‌شود.</span>
						</div>
						<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1" data-mobo-cancel-sync-form="1">
							<input type="hidden" name="action" value="mobo_core_cancel_sync">
							<?php wp_nonce_field( 'mobo_core_cancel_sync', 'mobo_core_nonce' ); ?>
							<button type="submit" class="mobo-btn mobo-btn-danger" data-mobo-cancel-sync-button="1">توقف همگام‌سازی</button>
						</form>
					<?php elseif ( $legacy_repair_required ) : ?>
						<div class="mobo-dashboard-action-copy">
							<strong>برای این نصب یک Repair کامل پیشنهاد می‌شود.</strong>
							<span>این مرحله برای همگام‌کردن ساختار قدیمی با وضعیت فعلی MoboCore است.</span>
						</div>
						<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1" onsubmit="return confirm('Repair محصولات اجرا شود؟ ابتدا داده‌های قدیمیِ قابل‌ترمیم به‌صورت محافظه‌کارانه اصلاح می‌شوند، سپس Sync کامل با وضعیت فعلی Mobo انجام می‌شود. موارد مبهم حذف نمی‌شوند.');">
							<input type="hidden" name="action" value="mobo_core_start_repair">
							<?php wp_nonce_field( 'mobo_core_start_repair', 'mobo_core_nonce' ); ?>
							<button type="submit" class="mobo-btn mobo-btn-primary">اجرای Repair پیشنهادی</button>
						</form>
					<?php else : ?>
						<div class="mobo-dashboard-action-copy">
							<strong>همگام‌سازی کامل محصولات آماده اجرا است.</strong>
							<span>محصولات از آخرین وضعیت منبع دریافت و به‌صورت مرحله‌ای پردازش می‌شوند.</span>
						</div>
						<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1">
							<input type="hidden" name="action" value="mobo_core_start_sync">
							<?php wp_nonce_field( 'mobo_core_start_sync', 'mobo_core_nonce' ); ?>
							<button type="submit" class="mobo-btn mobo-btn-primary">شروع همگام‌سازی محصولات</button>
						</form>
					<?php endif; ?>
				</div>

				<?php if ( ! $is_running && ! $is_waiting ) : ?>
					<details class="mobo-admin-details mobo-dashboard-advanced-actions">
						<summary>عملیات پیشرفته</summary>
						<div class="mobo-admin-details__body">
							<p class="mobo-dashboard-advanced-intro">این ابزارها برای نگهداری، عیب‌یابی یا سناریوهای خاص هستند و در استفاده روزمره لازم نیستند.</p>
							<div class="mobo-actions">
								<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1">
									<input type="hidden" name="action" value="mobo_core_sync_categories">
									<?php wp_nonce_field( 'mobo_core_sync_categories', 'mobo_core_nonce' ); ?>
									<button type="submit" class="mobo-btn mobo-btn-light">به‌روزرسانی دسته‌بندی‌ها برای نگاشت</button>
								</form>

								<?php if ( ! $legacy_repair_required ) : ?>
									<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1" onsubmit="return confirm('Repair محصولات اجرا شود؟ ابتدا داده‌های قدیمیِ قابل‌ترمیم به‌صورت محافظه‌کارانه اصلاح می‌شوند، سپس Sync کامل با وضعیت فعلی Mobo انجام می‌شود. موارد مبهم حذف نمی‌شوند.');">
										<input type="hidden" name="action" value="mobo_core_start_repair">
										<?php wp_nonce_field( 'mobo_core_start_repair', 'mobo_core_nonce' ); ?>
										<button type="submit" class="mobo-btn mobo-btn-light">Repair کامل محصولات</button>
									</form>
								<?php else : ?>
									<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1" onsubmit="return confirm('همگام‌سازی عادی بدون Repair پیشنهادی اجرا شود؟ برای نصب‌های قدیمی بهتر است ابتدا Repair کامل انجام شود.');">
										<input type="hidden" name="action" value="mobo_core_start_sync">
										<?php wp_nonce_field( 'mobo_core_start_sync', 'mobo_core_nonce' ); ?>
										<button type="submit" class="mobo-btn mobo-btn-light">همگام‌سازی عادی بدون Repair</button>
									</form>
								<?php endif; ?>

								<?php if ( ! $is_done ) : ?>
									<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1" onsubmit="return confirm('وضعیت ذخیره‌شده همگام‌سازی پاک شود؟ این ابزار فقط برای عیب‌یابی وضعیت متوقف یا خراب است.');">
										<input type="hidden" name="action" value="mobo_core_reset_sync">
										<?php wp_nonce_field( 'mobo_core_reset_sync', 'mobo_core_nonce' ); ?>
										<button type="submit" class="mobo-btn mobo-btn-light">پاک‌سازی وضعیت ذخیره‌شده</button>
									</form>
								<?php endif; ?>
							</div>
						</div>
					</details>
				<?php endif; ?>
			</div>
		</div>

		<?php $this->render_license_info_card( $license_info, true ); ?>

		<div class="mobo-dashboard-secondary-stack">
		<details class="mobo-admin-details mobo-dashboard-technical-details">
			<summary>جزئیات نسخه و یکپارچگی افزونه <span class="mobo-dashboard-detail-state <?php echo $build_ok ? 'is-ok' : 'is-error'; ?>"><?php echo $build_ok ? 'سالم' : 'نیازمند بررسی'; ?></span></summary>
			<div class="mobo-admin-details__body">

			<div class="mobo-status-grid">
				<?php $this->status_box( 'نسخه در حال اجرا', isset( $version_status['runtimeVersion'] ) ? $version_status['runtimeVersion'] : '—' ); ?>
				<?php $this->status_box( 'نسخه فایل اصلی', isset( $version_status['fileVersion'] ) ? $version_status['fileVersion'] : '—' ); ?>
				<?php $this->status_box( 'نسخه دیتابیس افزونه', isset( $version_status['databaseVersion'] ) ? $version_status['databaseVersion'] : '—' ); ?>
				<?php $this->status_box( 'یکپارچگی فایل ها', ! empty( $version_status['filesMatch'] ) ? 'سالم و مطابق بسته نسخه' : 'عدم تطابق شناسایی شد' ); ?>
			</div>
			<?php if ( ! empty( $version_status['allMatch'] ) ) : ?>
				<div class="mobo-message mobo-message-success">نسخه افزونه و فایل‌های نصب‌شده با بسته این نسخه مطابقت دارند.</div>
			<?php else : ?>
				<?php
				$changed_files = ! empty( $version_status['changedFiles'] ) && is_array( $version_status['changedFiles'] )
					? array_values( $version_status['changedFiles'] )
					: array();
				$changed_count = count( $changed_files );
				?>
				<div class="mobo-message mobo-message-error">
					<strong>فایل‌های افزونه نیاز به بررسی دارند.</strong>
					<span>
						<?php
						if ( $changed_count > 0 ) {
							echo esc_html( sprintf( '%d فایل یا رکورد نسخه با بسته نصب‌شده مطابقت ندارد.', $changed_count ) );
						} else {
							echo esc_html( isset( $version_status['message'] ) ? $version_status['message'] : 'بین نسخه اجراشده و بسته نصب‌شده اختلاف وجود دارد.' );
						}
						?>
					</span>
					<?php if ( $changed_count > 0 ) : ?>
						<details class="mobo-admin-inline-details">
							<summary>نمایش فایل‌های مشکل‌دار</summary>
							<ul class="mobo-admin-file-list" dir="ltr">
								<?php foreach ( array_slice( $changed_files, 0, 20 ) as $changed_file ) : ?>
									<li><code><?php echo esc_html( 'manifest-version' === $changed_file ? 'نسخه ثبت‌شده در manifest' : $changed_file ); ?></code></li>
								<?php endforeach; ?>
							</ul>
							<?php if ( $changed_count > 20 ) : ?>
								<p><?php echo esc_html( sprintf( '%d مورد دیگر نیز وجود دارد.', $changed_count - 20 ) ); ?></p>
							<?php endif; ?>
						</details>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			</div>
		</details>
		<details class="mobo-admin-details mobo-dashboard-technical-details mobo-dashboard-wallet-details">
			<summary>کیف پول موبو و هشدار موجودی</summary>
			<div class="mobo-admin-details__body">
				<?php $this->render_wallet_alert_dashboard_card(); ?>
			</div>
		</details>
		</div>
		<?php
	}

	/**
	 * Render purchase and activation tab.
	 *
	 * @return void
	 */
	private function render_purchase_tab() {
		$purchase_url    = defined( 'MOBO_CORE_PURCHASE_URL' ) ? MOBO_CORE_PURCHASE_URL : 'https://codeya.ir/';
		$github_url      = defined( 'MOBO_CORE_GITHUB_URL' ) ? MOBO_CORE_GITHUB_URL : 'https://github.com/PedramDev/mobo-core';
		$sales_phone     = defined( 'MOBO_CORE_SALES_PHONE' ) ? MOBO_CORE_SALES_PHONE : '+989124508218';
		$sales_tel       = defined( 'MOBO_CORE_SALES_TEL_URL' ) ? MOBO_CORE_SALES_TEL_URL : 'tel:+989124508218';
		$sales_tg        = defined( 'MOBO_CORE_SALES_TELEGRAM_URL' ) ? MOBO_CORE_SALES_TELEGRAM_URL : 'https://t.me/yazdan_ghadiri';
		$sales_wa        = defined( 'MOBO_CORE_SALES_WHATSAPP_URL' ) ? MOBO_CORE_SALES_WHATSAPP_URL : 'https://wa.me/989124508218';
		$tech_phone      = defined( 'MOBO_CORE_TECH_PHONE' ) ? MOBO_CORE_TECH_PHONE : '+989367362228';
		$tech_tg         = defined( 'MOBO_CORE_TECH_TELEGRAM_URL' ) ? MOBO_CORE_TECH_TELEGRAM_URL : 'https://t.me/Codeya';
		$connection_url  = $this->admin_page_url( array( 'tab' => 'connection' ) );
		$connection      = $this->current_admin_connection();
		$is_provisioned  = class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::is_provisioned( $connection );
		$is_enabled      = is_array( $connection ) && ! empty( $connection['is_enabled'] );
		$source_key      = is_array( $connection )
			? sanitize_key( (string) ( $connection['source_key'] ?? '' ) )
			: ( class_exists( 'Mobo_Core_Connection_Repository' ) ? sanitize_key( (string) Mobo_Core_Connection_Repository::get_runtime_value( 'source_key', '' ) ) : '' );
		$provider_key    = is_array( $connection ) && class_exists( 'Mobo_Core_Provider_Registry' )
			? Mobo_Core_Provider_Registry::normalize_key( $connection['provider_key'] ?? '' )
			: ( class_exists( 'Mobo_Core_Connection_Repository' ) ? trim( (string) Mobo_Core_Connection_Repository::get_runtime_value( 'provider_key', '' ) ) : '' );
		$display_name    = is_array( $connection ) ? sanitize_text_field( (string) ( $connection['display_name'] ?? '' ) ) : '';

		/* Purchase/Admin status must reflect credentials stored on the selected
		 * durable row, including a Pending/disabled Connection. Runtime credential
		 * helpers intentionally hide secrets for disabled owners and are therefore
		 * not valid evidence for this administrative setup summary. */
		$stored_token = is_array( $connection )
			? Mobo_Core_Settings::normalize_opaque_secret( (string) ( $connection['license_token'] ?? '' ), 191 )
			: $this->current_admin_license_token();
		$security_code = is_array( $connection )
			? Mobo_Core_Settings::normalize_security_code( (string) ( $connection['security_code'] ?? '' ) )
			: $this->current_admin_security_code();
		$token_set       = '' !== $stored_token;
		$security_valid  = '' !== $security_code && Mobo_Core_Settings::is_valid_security_code( $security_code );
		$source_ready    = $is_provisioned && '' !== $source_key;
		$provider_ready  = $is_provisioned && '' !== $provider_key;
		$source_label    = $source_ready
			? ( '' !== $display_name ? $display_name : $source_key )
			: ( '' !== $source_key ? $source_key . ' — آماده‌سازی ناقص' : 'هنوز تعیین نشده' );
		$provider_label  = $provider_ready ? $provider_key : ( '' !== $provider_key ? $provider_key . ' — آماده‌سازی ناقص' : 'هنوز تعیین نشده' );
		$connection_state_label = ! $is_provisioned ? 'در انتظار آماده‌سازی' : ( $is_enabled ? 'فعال' : 'آماده ولی غیرفعال' );
		$connection_state_class = $is_provisioned && $is_enabled ? 'is-success' : 'is-warning';

		?>
		<div class="mobo-purchase-layout">
			<div class="mobo-purchase-primary">
				<section class="mobo-purchase-callout" aria-labelledby="mobo-purchase-title">
					<div class="mobo-purchase-callout__eyebrow"><span class="dashicons dashicons-cart" aria-hidden="true"></span>راه‌اندازی سرویس MoboCore</div>
					<h2 id="mobo-purchase-title">خرید لایسنس و فعال‌سازی اتصال</h2>
					<p>برای شروع، لایسنس را تهیه کنید و توکن همان لایسنس را در بخش «اتصال و لایسنس» وارد کنید. اطلاعات منبع، ارائه‌دهنده و سرویس مرکزی بعد از تأیید، توسط Backend مجاز روی همان اتصال ثبت می‌شوند. وضعیت زنده سرویس مرکزی و لایسنس نیز از همان صفحه «اتصال و لایسنس» بررسی می‌شود.</p>
					<div class="mobo-purchase-actions">
						<a class="mobo-btn mobo-btn-primary" href="<?php echo esc_url( $purchase_url ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-external" aria-hidden="true"></span>خرید یا ورود به پنل MoboCore</a>
						<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( $connection_url ); ?>"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span>رفتن به تنظیمات اتصال</a>
					</div>
				</section>

				<section class="mobo-purchase-status" aria-label="وضعیت راه‌اندازی">
					<div class="mobo-purchase-status__item <?php echo $is_provisioned ? 'is-success' : 'is-warning'; ?>">
						<span>آماده‌سازی اتصال</span>
						<strong><?php echo $is_provisioned ? 'تکمیل شده' : 'در انتظار تأیید سرویس مرکزی'; ?></strong>
					</div>
					<div class="mobo-purchase-status__item <?php echo $token_set ? 'is-success' : 'is-warning'; ?>">
						<span>توکن اتصال</span>
						<strong><?php echo $token_set ? 'ثبت شده' : 'ثبت نشده'; ?></strong>
					</div>
					<div class="mobo-purchase-status__item <?php echo $security_valid ? 'is-success' : 'is-warning'; ?>">
						<span>کد امنیتی وب‌هوک</span>
						<strong><?php echo $security_valid ? 'ثبت شده؛ قالب معتبر' : ( '' === $security_code ? 'ثبت نشده' : 'نیازمند اصلاح' ); ?></strong>
					</div>
					<div class="mobo-purchase-status__item <?php echo $source_ready ? 'is-success' : 'is-warning'; ?>">
						<span>منبع</span>
						<strong><?php echo esc_html( $source_label ); ?></strong>
					</div>
					<div class="mobo-purchase-status__item <?php echo $provider_ready ? 'is-success' : 'is-warning'; ?>">
						<span>ارائه‌دهنده</span>
						<strong><?php echo esc_html( $provider_label ); ?></strong>
					</div>
					<div class="mobo-purchase-status__item <?php echo esc_attr( $connection_state_class ); ?>">
						<span>وضعیت اتصال</span>
						<strong><?php echo esc_html( $connection_state_label ); ?></strong>
					</div>
				</section>
			</div>

			<div class="mobo-purchase-secondary">
				<section class="mobo-card mobo-purchase-card">
					<div class="mobo-purchase-card__head">
						<h3>مراحل راه‌اندازی</h3>
						<p>ترتیب پیشنهادی برای یک اتصال جدید؛ بدون نیاز به واردکردن Provider یا آدرس Backend داخل افزونه.</p>
					</div>
					<div class="mobo-purchase-steps">
						<div class="mobo-purchase-step"><div><strong>تهیه لایسنس</strong><span>از پنل فروش MoboCore لایسنس مناسب منبع موردنظر را تهیه کنید.</span></div></div>
						<div class="mobo-purchase-step"><div><strong>ثبت توکن در وردپرس</strong><span>در «اتصال و لایسنس» توکن را وارد کنید تا اتصال در وضعیت انتظار ساخته شود.</span></div></div>
						<div class="mobo-purchase-step"><div><strong>ثبت دامنه در Backend</strong><span>دامنه همین فروشگاه را برای همان لایسنس در پنل MoboCore ثبت کنید.</span></div></div>
						<div class="mobo-purchase-step"><div><strong>ارسال آماده‌سازی اتصال</strong><span>Backend اطلاعات منبع، ارائه‌دهنده و آدرس سرویس را با امضای معتبر به WordPress ارسال می‌کند.</span></div></div>
						<div class="mobo-purchase-step"><div><strong>ثبت کد امنیتی وب‌هوک</strong><span>کد امنیتی همان اتصال را در WordPress ذخیره کنید و وضعیت را بررسی کنید.</span></div></div>
						<div class="mobo-purchase-step"><div><strong>تکمیل تنظیمات فروشگاه</strong><span>تنظیمات محصول را تکمیل کنید و اگر ثبت خودکار سفارش را فعال می‌کنید، اطلاعات فرستنده و نگاشت روش‌های ارسال را هم بررسی کنید. Location Resolver مرکزی برای مقصد سفارش استفاده می‌شود و نگاشت دستی آدرس پیش‌نیاز نیست.</span></div></div>
					</div>
				</section>

				<section class="mobo-card mobo-purchase-card">
					<div class="mobo-purchase-card__head">
						<h3>فروش و پشتیبانی</h3>
						<p>برای خرید و فعال‌سازی از بخش فروش و برای نصب، اتصال و خطاهای فنی از پشتیبانی استفاده کنید.</p>
					</div>
					<div class="mobo-purchase-contact-grid">
						<div class="mobo-purchase-contact">
							<div class="mobo-purchase-contact__title"><strong>بخش فروش</strong><span dir="ltr"><?php echo esc_html( $sales_phone ); ?></span></div>
							<div class="mobo-actions">
								<a class="mobo-btn mobo-btn-primary" href="<?php echo esc_url( $sales_tel ); ?>">تماس تلفنی</a>
								<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( $sales_wa ); ?>" target="_blank" rel="noopener noreferrer">واتساپ</a>
								<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( $sales_tg ); ?>" target="_blank" rel="noopener noreferrer">تلگرام</a>
							</div>
						</div>
						<div class="mobo-purchase-contact">
							<div class="mobo-purchase-contact__title"><strong>پشتیبانی فنی</strong><span dir="ltr"><?php echo esc_html( $tech_phone ); ?></span></div>
							<div class="mobo-actions">
								<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( 'tel:' . $tech_phone ); ?>">تماس تلفنی</a>
								<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( $tech_tg ); ?>" target="_blank" rel="noopener noreferrer">تلگرام</a>
							</div>
						</div>
					</div>
				</section>
			</div>

			<details class="mobo-admin-details mobo-purchase-technical">
				<summary>اطلاعات فنی و سرویس خارجی مورد استفاده</summary>
				<div class="mobo-admin-details__body">
					<div class="mobo-note">
						Backend و Source قبل از Provisioning داخل افزونه تعریف نشده‌اند و بعد از تأیید، با درخواست امضاشده سرویس مرکزی روی همان Connection ذخیره می‌شوند.<br>
						داده‌های احتمالی شامل دامنه سایت، Token، وضعیت لایسنس، اطلاعات محصول و تنوع، وضعیت صف‌ها، گزارش سلامت، وب‌هوک‌ها و اطلاعات لازم برای بررسی یا ثبت سفارش موبویی است. ثبت Token مسیر آماده‌سازی Connection را ایجاد می‌کند؛ تبادل‌های runtime فقط وقتی انجام می‌شوند که همان Connection برای runtime فعال باشد و قابلیت مربوطه واقعاً استفاده شود. معتبر بودن قالب کد امنیتی در این صفحه به معنی تأیید زنده وب‌هوک نیست؛ وضعیت واقعی وب‌هوک از صفحه «اتصال و لایسنس» بررسی می‌شود.
					</div>
					<div class="mobo-purchase-actions">
						<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( $github_url ); ?>" target="_blank" rel="noopener noreferrer">مشاهده سورس در GitHub</a>
					</div>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Render the last Portal webhook credential test result.
	 *
	 * @return void
	 */
	private function render_webhook_auth_test_status() {
		$status = class_exists( 'Mobo_Core_Webhook_Auth_Status' )
			? Mobo_Core_Webhook_Auth_Status::get_status()
			: array( 'status' => 'unknown', 'checkedAt' => 0, 'message' => '' );

		$code = isset( $status['status'] ) ? sanitize_key( (string) $status['status'] ) : 'unknown';
		$checked_at = isset( $status['checkedAt'] ) ? absint( $status['checkedAt'] ) : 0;
		$message = isset( $status['message'] ) ? (string) $status['message'] : '';
		$time = $checked_at > 0 ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', $checked_at ) : 'هنوز تست نشده';

		$labels = array(
			'valid'         => array( 'success', 'کد وب‌هوک صحیح است', 'آخرین درخواست تست سرویس مرکزی با هدر امنیتی معتبر پذیرفته شد.' ),
			'invalid'       => array( 'error', 'کد وب‌هوک سرویس مرکزی اشتباه است', 'کد ثبت‌شده در سرویس مرکزی با کد ذخیره‌شده در این افزونه یکسان نیست. هر دو سمت را با یک مقدار واحد ذخیره کنید.' ),
			'missing'       => array( 'error', 'کد وب‌هوک در افزونه ثبت نشده', 'ابتدا یک کد امنیتی معتبر در همین صفحه ذخیره کنید و همان مقدار را در Portal قرار دهید.' ),
			'misconfigured' => array( 'error', 'ساختار کد وب‌هوک نامعتبر است', 'کد باید فقط شامل ASCII قابل‌چاپ و بدون فاصله باشد.' ),
			'unknown'       => array( 'warning', 'هنوز تست نشده', 'پس از اجرای بررسی سلامت یا تست وب‌هوک در سرویس مرکزی، نتیجه اینجا ثبت می‌شود.' ),
		);

		$view = isset( $labels[ $code ] ) ? $labels[ $code ] : $labels['unknown'];
		if ( 'valid' === $code ) {
			return;
		}
		?>
		<div class="mobo-message mobo-message-<?php echo esc_attr( $view[0] ); ?>">
			<strong><?php echo esc_html( $view[1] ); ?>:</strong>
			<?php echo esc_html( '' !== $message ? $message : $view[2] ); ?>
			<span style="display:block;margin-top:6px;opacity:.78;">آخرین بررسی: <?php echo esc_html( $time ); ?> به وقت تهران</span>
		</div>
		<?php
	}

	/**
	 * Build a manager-facing service-connectivity state from the LicenseInfo probe.
	 * Application responses (401/403/etc.) prove that the service was reached and
	 * must not be presented as a transport outage.
	 *
	 * @param array $license_info License display payload.
	 * @param bool  $provisioned Whether routing metadata is complete.
	 * @param bool  $enabled Whether the Connection is enabled.
	 * @return array{state:string,label:string,detail:string}
	 */
	private function connection_service_status_for_display( $license_info, $provisioned, $enabled ) {
		if ( ! $provisioned ) {
			return array( 'state' => 'info', 'label' => 'قابل بررسی نیست', 'detail' => 'پس از آماده‌شدن اتصال، وضعیت سرویس مرکزی از همین صفحه قابل بررسی است.' );
		}
		if ( ! $enabled ) {
			return array( 'state' => 'info', 'label' => 'قابل بررسی نیست', 'detail' => 'این اتصال آماده است اما فعال نیست؛ تا زمان فعال‌شدن، وضعیت سرویس مرکزی به‌صورت زنده بررسی نمی‌شود.' );
		}
		if ( ! empty( $license_info['deferred'] ) ) {
			return array( 'state' => 'info', 'label' => 'قابل بررسی نیست', 'detail' => (string) ( $license_info['message'] ?? '' ) );
		}
		if ( empty( $license_info['error'] ) ) {
			return array( 'state' => 'success', 'label' => 'متصل', 'detail' => 'ارتباط با سرویس مرکزی برقرار است.' );
		}

		$message = strtolower( (string) ( $license_info['message'] ?? '' ) );
		$raw = isset( $license_info['raw'] ) && is_array( $license_info['raw'] ) ? $license_info['raw'] : array();
		$error_code = strtolower( (string) ( $raw['errorCode'] ?? '' ) );
		$haystack = $message . ' ' . $error_code;

		if ( false !== strpos( $haystack, '401' ) || false !== strpos( $haystack, '403' ) || false !== strpos( $haystack, 'unauthor' ) || false !== strpos( $haystack, 'forbidden' ) ) {
			return array( 'state' => 'danger', 'label' => 'احراز هویت رد شد', 'detail' => 'سرویس مرکزی پاسخ می‌دهد، اما توکن یا مجوز این اتصال پذیرفته نشد. توکن اتصال را بررسی کنید.' );
		}
		if ( false !== strpos( $haystack, 'ssl' ) || false !== strpos( $haystack, 'certificate' ) || false !== strpos( $haystack, 'tls' ) ) {
			return array( 'state' => 'warning', 'label' => 'خطای ارتباط امن', 'detail' => 'ارتباط به سرویس مرکزی رسید، اما اتصال امن کامل نشد. گواهی SSL و نشانی سرویس را بررسی کنید.' );
		}
		if ( false !== strpos( $haystack, 'timed out' ) || false !== strpos( $haystack, 'timeout' ) || false !== strpos( $haystack, 'curl error 28' ) ) {
			return array( 'state' => 'warning', 'label' => 'پاسخ دریافت نشد', 'detail' => 'سرویس مرکزی در زمان مقرر پاسخ نداد. وضعیت سرویس یا ارتباط شبکه را بررسی کنید.' );
		}
		if ( false !== strpos( $haystack, 'curl error 7' ) || false !== strpos( $haystack, 'failed to connect' ) || false !== strpos( $haystack, 'could not connect' ) || false !== strpos( $haystack, 'connection refused' ) ) {
			return array( 'state' => 'danger', 'label' => 'در دسترس نیست', 'detail' => 'ارتباط شبکه با سرویس مرکزی برقرار نشد. روشن بودن سرویس و نشانی اتصال را بررسی کنید.' );
		}
		if ( false !== strpos( $haystack, 'could not resolve' ) || false !== strpos( $haystack, 'name resolution' ) || false !== strpos( $haystack, 'dns' ) ) {
			return array( 'state' => 'danger', 'label' => 'نشانی قابل دسترس نیست', 'detail' => 'نام میزبان سرویس مرکزی پیدا نشد. دامنه و تنظیمات DNS را بررسی کنید.' );
		}

		return array( 'state' => 'danger', 'label' => 'بررسی ناموفق', 'detail' => 'از سرویس مرکزی پاسخ قابل استفاده‌ای دریافت نشد. جزئیات فنی لایسنس را بررسی کنید.' );
	}


	/**
	 * Normalize durable Health telemetry into a manager-facing observability view.
	 * This method is intentionally pure: it only consumes the already-sanitized
	 * Health snapshot and must not execute probes, workers or network requests.
	 *
	 * @param array $local Health reporter payload.
	 * @return array{moneyState:string,moneyLabel:string,moneyDetail:string,networkIssues:array,networkIssueCount:int,pendingWork:int,stalled:bool,lastSyncSuccessAt:string,lastSyncLabel:string}
	 */
	private function health_observability_for_display( $local ) {
		$local = is_array( $local ) ? $local : array();
		$money = isset( $local['moneyContract'] ) && is_array( $local['moneyContract'] ) ? $local['moneyContract'] : array();
		$money_state = 'info';
		$money_label = 'نامشخص';
		$money_detail = 'وضعیت قرارداد پولی در گزارش Health موجود نیست.';

		if ( ! empty( $money ) ) {
			$money_code = sanitize_key( (string) ( $money['code'] ?? '' ) );
			$source_unit = sanitize_text_field( (string) ( $money['sourceUnit'] ?? '' ) );
			$store_unit = sanitize_text_field( (string) ( $money['storeUnit'] ?? '' ) );
			if ( ! empty( $money['compatible'] ) ) {
				$money_state = 'success';
				$money_label = 'سازگار';
				$money_detail = ( '' !== $source_unit && '' !== $store_unit )
					? sprintf( 'واحد پول Source و فروشگاه هر دو %s هستند.', $store_unit )
					: 'قرارداد پولی Source با واحد پول WooCommerce سازگار است.';
			} else {
				$money_state = 'danger';
				$money_label = 'ناسازگار';
				if ( 'money_unit_mismatch' === $money_code ) {
					$money_detail = sprintf( 'واحد پول Source (%s) با WooCommerce (%s) یکسان نیست.', $source_unit ?: 'نامشخص', $store_unit ?: 'نامشخص' );
				} elseif ( ! empty( $money['requiresReprovision'] ) ) {
					$money_detail = 'قرارداد پولی Connection ناقص/قدیمی است و Connection باید دوباره Provision شود.';
				} else {
					$money_detail = sanitize_text_field( (string) ( $money['message'] ?? 'قرارداد پولی معتبر نیست.' ) );
				}
				if ( '' !== $money_code ) {
					$money_detail .= ' [code: ' . $money_code . ']';
				}
			}
		}

		$dependencies = isset( $local['networkDependencies'] ) && is_array( $local['networkDependencies'] ) ? $local['networkDependencies'] : array();
		$network_issues = array();
		$labels = array(
			'authentication_failed' => 'احراز هویت سرویس رد شد',
			'access_denied'         => 'دسترسی سرویس با HTTP 403 رد شد',
			'timeout'               => 'Timeout شبکه/سرویس',
			'dns_failure'           => 'خطای DNS',
			'tls_failure'           => 'خطای TLS/SSL',
			'connect_failure'       => 'خطای اتصال شبکه',
			'rate_limited'          => 'Rate limit سرویس',
			'upstream_failure'      => 'خطای سرویس بالادستی',
			'transport_failure'     => 'خطای Transport',
		);
		foreach ( $dependencies as $dependency => $dependency_status ) {
			if ( ! is_array( $dependency_status ) ) {
				continue;
			}
			$consecutive = absint( $dependency_status['consecutiveFailures'] ?? 0 );
			$circuit = sanitize_key( (string) ( $dependency_status['circuitState'] ?? '' ) );
			$failure_class = sanitize_key( (string) ( $dependency_status['failureClass'] ?? '' ) );
			if ( $consecutive <= 0 && 'open' !== $circuit && '' === $failure_class ) {
				continue;
			}
			$status_code = absint( $dependency_status['status'] ?? 0 );
			$operator_action = ! empty( $dependency_status['operatorAction'] );
			$retryable = ! empty( $dependency_status['retryable'] );
			$label = isset( $labels[ $failure_class ] ) ? $labels[ $failure_class ] : ( '' !== $failure_class ? $failure_class : 'خطای شبکه' );
			$detail = sanitize_text_field( (string) $dependency ) . ': ' . $label;
			if ( $status_code > 0 ) {
				$detail .= ' (HTTP ' . $status_code . ')';
			}
			if ( $consecutive > 0 ) {
				$detail .= '؛ خطای متوالی: ' . $consecutive;
			}
			if ( 'open' === $circuit ) {
				$detail .= '؛ Circuit باز است';
			}
			$network_issues[] = array(
				'dependency'     => sanitize_key( (string) $dependency ),
				'failureClass'   => $failure_class,
				'status'         => $status_code,
				'state'          => $operator_action ? 'danger' : ( $retryable ? 'warning' : 'danger' ),
				'label'          => $label,
				'detail'         => $detail,
				'operatorAction' => $operator_action,
				'retryable'      => $retryable,
			);
		}

		$pending_work = absint( $local['pendingWebhookJobs'] ?? 0 )
			+ absint( $local['pendingImageJobs'] ?? 0 )
			+ absint( $local['pendingSyncJobs'] ?? 0 );
		$cron = isset( $local['cronRunner'] ) && is_array( $local['cronRunner'] ) ? $local['cronRunner'] : array();
		$stalled = $pending_work > 0 && ! empty( $cron['isOverdue'] );
		$last_sync_raw = sanitize_text_field( (string) ( $local['lastSyncSuccessAt'] ?? '' ) );
		$last_sync_label = 'ثبت نشده';
		if ( '' !== $last_sync_raw ) {
			if ( is_numeric( $last_sync_raw ) ) {
				$last_sync_label = Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $last_sync_raw ) );
			} else {
				$last_sync_timestamp = ( class_exists( 'Mobo_Core_Environment' ) ? Mobo_Core_Environment::utc_strtotime( $last_sync_raw ) : strtotime( $last_sync_raw ) );
				$last_sync_label = false !== $last_sync_timestamp
					? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', $last_sync_timestamp )
					: $last_sync_raw;
			}
		}

		return array(
			'moneyState'        => $money_state,
			'moneyLabel'        => $money_label,
			'moneyDetail'       => $money_detail,
			'networkIssues'     => $network_issues,
			'networkIssueCount' => count( $network_issues ),
			'pendingWork'       => $pending_work,
			'stalled'           => $stalled,
			'lastSyncSuccessAt' => $last_sync_raw,
			'lastSyncLabel'     => $last_sync_label,
		);
	}

	/**
	 * Resolve the semantic License state from both explicit status fields and
	 * compatibility booleans. Missing fields must never be treated as Active.
	 *
	 * @param array $license_info License display payload.
	 * @return array{state:string,label:string,headline:string}
	 */
	private function license_status_for_display( $license_info ) {
		if ( ! empty( $license_info['deferred'] ) ) {
			return array( 'state' => 'info', 'label' => 'قابل بررسی نیست', 'headline' => 'وضعیت لایسنس هنوز قابل بررسی نیست' );
		}
		if ( ! empty( $license_info['error'] ) ) {
			return array( 'state' => 'warning', 'label' => 'نامشخص', 'headline' => 'وضعیت لایسنس مشخص نیست' );
		}

		$raw = isset( $license_info['raw'] ) && is_array( $license_info['raw'] ) ? $license_info['raw'] : array();
		if ( ! empty( $license_info['isExpired'] ) ) {
			return array( 'state' => 'danger', 'label' => 'منقضی شده', 'headline' => 'لایسنس منقضی شده است' );
		}

		$status = $this->first_license_value(
			$raw,
			array( 'status', 'licenseStatus', 'license_status', 'Status', 'LicenseStatus', 'state', 'licenseState' )
		);
		$status_normalized = '—' === $status ? '' : strtolower( trim( (string) $status ) );

		$active_values = array( 'active', 'enabled', 'valid', 'approved', 'ok', 'فعال', 'معتبر' );
		$expired_values = array( 'expired', 'منقضی', 'منقضی شده' );
		$suspended_values = array( 'suspended', 'paused', 'hold', 'onhold', 'تعلیق', 'تعلیق شده', 'معلق' );
		$revoked_values = array( 'revoked', 'cancelled', 'canceled', 'disabled', 'inactive', 'blocked', 'لغو شده', 'غیرفعال', 'مسدود' );

		if ( in_array( $status_normalized, $expired_values, true ) ) {
			return array( 'state' => 'danger', 'label' => 'منقضی شده', 'headline' => 'لایسنس منقضی شده است' );
		}
		if ( in_array( $status_normalized, $suspended_values, true ) ) {
			return array( 'state' => 'warning', 'label' => 'تعلیق شده', 'headline' => 'لایسنس موقتاً تعلیق شده است' );
		}
		if ( in_array( $status_normalized, $revoked_values, true ) ) {
			return array( 'state' => 'danger', 'label' => 'غیرفعال', 'headline' => 'لایسنس فعال نیست' );
		}
		if ( in_array( $status_normalized, $active_values, true ) ) {
			return array( 'state' => 'success', 'label' => 'فعال', 'headline' => 'لایسنس فعال است' );
		}

		$active_keys = array( 'isActive', 'is_active', 'active', 'licenseActive', 'IsActive' );
		foreach ( $active_keys as $key ) {
			if ( array_key_exists( $key, $raw ) && is_scalar( $raw[ $key ] ) ) {
				return $this->license_bool_value( $raw[ $key ] )
					? array( 'state' => 'success', 'label' => 'فعال', 'headline' => 'لایسنس فعال است' )
					: array( 'state' => 'danger', 'label' => 'غیرفعال', 'headline' => 'لایسنس فعال نیست' );
			}
		}

		/*
		 * Some older LicenseInfo responses expose expiry only. If the response is
		 * successful and includes a concrete expiry date/count that is not expired,
		 * retain backward-compatible Active semantics; otherwise remain Unknown.
		 */
		$has_expiry_signal = false;
		foreach ( array( 'remainingDays', 'remainDays', 'daysRemaining', 'expiresAt', 'expireAt', 'expirationDate', 'validUntil', 'endDate' ) as $key ) {
			if ( array_key_exists( $key, $raw ) && is_scalar( $raw[ $key ] ) && '' !== trim( (string) $raw[ $key ] ) ) {
				$has_expiry_signal = true;
				break;
			}
		}
		if ( $has_expiry_signal ) {
			return array( 'state' => 'success', 'label' => 'فعال', 'headline' => 'لایسنس فعال است' );
		}

		return array( 'state' => 'warning', 'label' => 'نامشخص', 'headline' => 'وضعیت لایسنس در پاسخ سرویس مشخص نشده است' );
	}

	 /**
	 * Render connection tab.
	 *
	 * @return void
	 */
	private function render_connection_tab() {
		$connection        = $this->current_admin_connection();
		$connection_id     = is_array( $connection ) ? absint( $connection['id'] ?? 0 ) : 0;
		$source_key        = is_array( $connection ) ? sanitize_key( (string) ( $connection['source_key'] ?? '' ) ) : '';
		$provider_key      = is_array( $connection ) && class_exists( 'Mobo_Core_Provider_Registry' ) ? Mobo_Core_Provider_Registry::normalize_key( $connection['provider_key'] ?? '' ) : '';
		$display_name      = is_array( $connection ) ? sanitize_text_field( (string) ( $connection['display_name'] ?? '' ) ) : '';
		$api_base_url      = is_array( $connection ) ? esc_url_raw( (string) ( $connection['api_base_url'] ?? '' ) ) : '';
		$source_site_url   = is_array( $connection ) ? esc_url_raw( (string) ( $connection['source_site_url'] ?? '' ) ) : '';
		$provisioned       = class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::is_provisioned( $connection );
		$is_enabled        = is_array( $connection ) && ! empty( $connection['is_enabled'] );
		$is_default        = is_array( $connection ) && ! empty( $connection['is_default'] );

		/*
		 * Admin display must show credentials that belong to the selected durable
		 * row even while it is waiting for provisioning. Runtime helpers remain
		 * fail-closed for disabled owners.
		 */
		$stored_token      = is_array( $connection ) ? Mobo_Core_Settings::normalize_opaque_secret( (string) ( $connection['license_token'] ?? '' ), 191 ) : $this->current_admin_license_token();
		$security_code     = is_array( $connection ) ? Mobo_Core_Settings::normalize_security_code( (string) ( $connection['security_code'] ?? '' ) ) : $this->current_admin_security_code();
		$has_token         = '' !== $stored_token;
		$has_security_code = '' !== $security_code;
		$security_valid    = $this->is_webhook_security_code_valid( $security_code );

		if ( ! $provisioned ) {
			$license_info = array(
				'deferred'  => true,
				'error'     => false,
				'isExpired' => false,
				'message'   => 'پس از آماده‌سازی اتصال توسط سرویس مرکزی، وضعیت لایسنس از همین صفحه قابل بررسی است.',
				'raw'       => array(),
				'checkedAt' => 0,
			);
		} elseif ( ! $is_enabled ) {
			$license_info = array(
				'deferred'  => true,
				'error'     => false,
				'isExpired' => false,
				'message'   => 'این اتصال در حال حاضر غیرفعال است. برای بررسی زنده لایسنس باید اتصال فعال باشد.',
				'raw'       => array(),
				'checkedAt' => 0,
			);
		} elseif ( ! $has_token ) {
			$license_info = array(
				'deferred'  => true,
				'error'     => false,
				'isExpired' => false,
				'message'   => 'ابتدا توکن اتصال را ثبت کنید تا وضعیت سرویس مرکزی و لایسنس قابل بررسی باشد.',
				'raw'       => array(),
				'checkedAt' => 0,
			);
		} else {
			$license_info = $this->get_license_info_for_display();
		}

		$service_view = $this->connection_service_status_for_display( $license_info, $provisioned, $is_enabled );
		$license_view = $this->license_status_for_display( $license_info );

		if ( ! $provisioned ) {
			$connection_label = 'در انتظار آماده‌سازی';
			$connection_state = 'warning';
		} elseif ( $is_enabled ) {
			$connection_label = 'فعال';
			$connection_state = 'success';
		} else {
			$connection_label = 'آماده ولی غیرفعال';
			$connection_state = 'info';
		}

		$webhook_status = class_exists( 'Mobo_Core_Webhook_Auth_Status' )
			? Mobo_Core_Webhook_Auth_Status::get_status()
			: array( 'status' => 'unknown' );
		$webhook_code = isset( $webhook_status['status'] ) ? sanitize_key( (string) $webhook_status['status'] ) : 'unknown';

		if ( ! $provisioned ) {
			$webhook_label = $has_security_code ? 'پس از آماده‌سازی قابل تست است' : 'ثبت نشده';
			$webhook_state = $has_security_code ? 'info' : 'warning';
		} elseif ( ! $is_enabled ) {
			$webhook_label = 'اتصال غیرفعال';
			$webhook_state = 'info';
		} elseif ( ! $has_security_code ) {
			$webhook_label = 'ثبت نشده';
			$webhook_state = 'danger';
		} elseif ( ! $security_valid || in_array( $webhook_code, array( 'invalid', 'misconfigured' ), true ) ) {
			$webhook_label = 'نیاز به بررسی';
			$webhook_state = 'danger';
		} elseif ( 'valid' === $webhook_code ) {
			$webhook_label = 'تأیید شده';
			$webhook_state = 'success';
		} else {
			$webhook_label = 'ثبت شده؛ هنوز تست نشده';
			$webhook_state = 'info';
		}

		$webhook_checked_at = isset( $webhook_status['checkedAt'] ) ? absint( $webhook_status['checkedAt'] ) : 0;
		$webhook_checked_label = $webhook_checked_at > 0
			? Mobo_Core_Iran_Date::format( 'Y-m-d H:i', $webhook_checked_at ) . ' تهران'
			: 'هنوز تست نشده';
		$security_summary_label = ! $has_security_code
			? 'ثبت نشده'
			: ( $security_valid ? 'آماده' : 'نیاز به اصلاح' );
		$security_summary_state = ! $has_security_code || ! $security_valid ? 'danger' : 'success';
		?>

		<div class="mobo-admin-connection-status-grid" aria-label="خلاصه وضعیت اتصال">
			<div class="mobo-admin-status-tile mobo-admin-status-tile--<?php echo esc_attr( $connection_state ); ?>">
				<div class="mobo-admin-status-tile__top"><span class="mobo-admin-status-tile__label">اتصال</span><span class="dashicons dashicons-database" aria-hidden="true"></span></div>
				<div class="mobo-admin-status-tile__value"><?php echo esc_html( $connection_label ); ?></div>
			</div>
			<div class="mobo-admin-status-tile mobo-admin-status-tile--<?php echo esc_attr( $service_view['state'] ); ?>">
				<div class="mobo-admin-status-tile__top"><span class="mobo-admin-status-tile__label">سرویس مرکزی</span><span class="dashicons dashicons-cloud" aria-hidden="true"></span></div>
				<div class="mobo-admin-status-tile__value"><?php echo esc_html( $service_view['label'] ); ?></div>
			</div>
			<div class="mobo-admin-status-tile mobo-admin-status-tile--<?php echo esc_attr( $license_view['state'] ); ?>">
				<div class="mobo-admin-status-tile__top"><span class="mobo-admin-status-tile__label">لایسنس</span><span class="dashicons dashicons-awards" aria-hidden="true"></span></div>
				<div class="mobo-admin-status-tile__value"><?php echo esc_html( $license_view['label'] ); ?></div>
			</div>
			<div class="mobo-admin-status-tile mobo-admin-status-tile--<?php echo $has_token ? 'success' : 'danger'; ?>">
				<div class="mobo-admin-status-tile__top"><span class="mobo-admin-status-tile__label">توکن اتصال</span><span class="dashicons dashicons-admin-network" aria-hidden="true"></span></div>
				<div class="mobo-admin-status-tile__value"><?php echo esc_html( $has_token ? 'ثبت شده' : 'ثبت نشده' ); ?></div>
			</div>
			<div class="mobo-admin-status-tile mobo-admin-status-tile--<?php echo esc_attr( $webhook_state ); ?>">
				<div class="mobo-admin-status-tile__top"><span class="mobo-admin-status-tile__label">وب‌هوک</span><span class="dashicons dashicons-shield" aria-hidden="true"></span></div>
				<div class="mobo-admin-status-tile__value"><?php echo esc_html( $webhook_label ); ?></div>
			</div>
		</div>

		<?php if ( $provisioned && $is_enabled && 'success' !== $service_view['state'] ) : ?>
			<div class="mobo-message mobo-message-<?php echo 'danger' === $service_view['state'] ? 'error' : 'warning'; ?> mobo-admin-service-guidance">
				<strong>سرویس مرکزی:</strong> <?php echo esc_html( $service_view['detail'] ); ?>
			</div>
		<?php endif; ?>

		<div class="mobo-card mobo-admin-connection-card">
			<div class="mobo-admin-card-heading">
				<div>
					<h2 class="mobo-admin-section-title"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span>اطلاعات اتصال</h2>
					<p>این اطلاعات توسط سرویس مرکزی ثبت شده‌اند و برای جلوگیری از خطا از داخل وردپرس قابل تغییر نیستند.</p>
				</div>
				<div class="mobo-admin-connection-badges">
					<?php if ( $is_default ) : ?><span class="mobo-admin-badge mobo-admin-badge--info">پیش‌فرض</span><?php endif; ?>
					<span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( $connection_state ); ?>"><?php echo esc_html( $connection_label ); ?></span>
				</div>
			</div>

			<?php if ( ! $provisioned ) : ?>
				<div class="mobo-admin-connection-setup">
					<div class="mobo-admin-connection-setup__intro">
						<strong>توکن اتصال ثبت شده و این اتصال منتظر آماده‌سازی است.</strong>
						<span>پس از آماده‌سازی توسط سرویس مرکزی، اطلاعات منبع، ارائه‌دهنده و نشانی سرویس به‌صورت خودکار روی همین اتصال ثبت می‌شوند.</span>
					</div>
					<ol class="mobo-admin-connection-steps">
						<li class="<?php echo $has_token ? 'is-done' : ''; ?>"><strong>۱</strong><span>ثبت توکن اتصال</span></li>
						<li class="is-current"><strong>۲</strong><span>دریافت تأیید از سرویس مرکزی</span></li>
						<li><strong>۳</strong><span>دریافت اطلاعات اتصال</span></li>
						<li><strong>۴</strong><span>ثبت و تست کد امنیتی وب‌هوک</span></li>
					</ol>
					<div class="mobo-admin-pending-actions">
						<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( $this->admin_page_url( array( 'tab' => 'connection' ) ) ); ?>">به‌روزرسانی وضعیت</a>
						<?php if ( ! $is_default && ! $is_enabled ) : ?>
							<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" onsubmit="return confirm('این اتصال هنوز آماده نشده است. آیا می‌خواهید اتصال در انتظار و توکن ذخیره‌شده آن حذف شود؟');">
								<input type="hidden" name="action" value="mobo_core_cancel_pending_connection">
								<input type="hidden" name="connection_id" value="<?php echo esc_attr( (string) $connection_id ); ?>">
								<?php wp_nonce_field( 'mobo_core_cancel_pending_connection', 'mobo_core_nonce' ); ?>
								<button type="submit" class="mobo-btn mobo-btn-danger">لغو اتصال در انتظار</button>
							</form>
						<?php endif; ?>
					</div>
					<?php if ( ! $is_default && ! $is_enabled ) : ?>
						<p class="mobo-admin-pending-note">لغو این مورد فقط اتصال ناقص فعلی و توکن ذخیره‌شده آن را حذف می‌کند و روی اتصال‌های فعال اثری ندارد.</p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<div class="mobo-admin-connection-meta">
					<div class="mobo-admin-meta-row" data-mobo-copy-row><span class="mobo-admin-meta-row__label">نام نمایشی</span><div class="mobo-admin-meta-row__value"><span data-mobo-copy-value="<?php echo esc_attr( '' !== $display_name ? $display_name : 'اتصال #' . $connection_id ); ?>"><?php echo esc_html( '' !== $display_name ? $display_name : 'اتصال #' . $connection_id ); ?></span></div></div>
					<div class="mobo-admin-meta-row" data-mobo-copy-row><span class="mobo-admin-meta-row__label">ارائه‌دهنده</span><div class="mobo-admin-meta-row__value"><code dir="ltr" data-mobo-copy-value="<?php echo esc_attr( '' !== $provider_key ? $provider_key : '—' ); ?>"><?php echo esc_html( '' !== $provider_key ? $provider_key : '—' ); ?></code><?php if ( '' !== $provider_key ) : ?><button type="button" class="mobo-admin-copy-button" data-mobo-copy aria-label="کپی ارائه‌دهنده"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button><?php endif; ?></div></div>
					<div class="mobo-admin-meta-row" data-mobo-copy-row><span class="mobo-admin-meta-row__label">منبع</span><div class="mobo-admin-meta-row__value"><code dir="ltr" data-mobo-copy-value="<?php echo esc_attr( '' !== $source_key ? $source_key : '—' ); ?>"><?php echo esc_html( '' !== $source_key ? $source_key : '—' ); ?></code><?php if ( '' !== $source_key ) : ?><button type="button" class="mobo-admin-copy-button" data-mobo-copy aria-label="کپی منبع"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button><?php endif; ?></div></div>
					<div class="mobo-admin-meta-row" data-mobo-copy-row><span class="mobo-admin-meta-row__label">سرویس مرکزی</span><div class="mobo-admin-meta-row__value"><code dir="ltr" data-mobo-copy-value="<?php echo esc_attr( '' !== $api_base_url ? $api_base_url : '—' ); ?>"><?php echo esc_html( '' !== $api_base_url ? $api_base_url : '—' ); ?></code><?php if ( '' !== $api_base_url ) : ?><button type="button" class="mobo-admin-copy-button" data-mobo-copy aria-label="کپی نشانی سرویس"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button><?php endif; ?></div></div>
					<div class="mobo-admin-meta-row" data-mobo-copy-row><span class="mobo-admin-meta-row__label">سایت منبع</span><div class="mobo-admin-meta-row__value"><code dir="ltr" data-mobo-copy-value="<?php echo esc_attr( '' !== $source_site_url ? $source_site_url : '—' ); ?>"><?php echo esc_html( '' !== $source_site_url ? $source_site_url : '—' ); ?></code><?php if ( '' !== $source_site_url ) : ?><button type="button" class="mobo-admin-copy-button" data-mobo-copy aria-label="کپی سایت منبع"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button><?php endif; ?></div></div>
					<div class="mobo-admin-meta-row"><span class="mobo-admin-meta-row__label">شناسه داخلی اتصال</span><div class="mobo-admin-meta-row__value"><code dir="ltr">#<?php echo esc_html( (string) $connection_id ); ?></code></div></div>
				</div>

                <?php
                // C75B-P4G-P1: read-only visibility only. No conversion is enabled
                // until product/reprice/shipping/checkout integration is audited.
                $source_money = class_exists( 'Mobo_Core_Money_Policy' )
                    ? Mobo_Core_Money_Policy::contract_from_row( $connection ) : array();
                $source_unit = (string) ( $source_money['unit'] ?? '' );
                $store_unit = class_exists( 'Mobo_Core_Money_Policy' ) ? Mobo_Core_Money_Policy::store_unit() : '';
                $conversion_pair = class_exists( 'Mobo_Core_Money_Converter' )
                    ? Mobo_Core_Money_Converter::pair_definition( $source_unit, $store_unit )
                    : array( 'supported' => false, 'code' => 'unavailable' );
                $conversion_label = 'جفت ارز پشتیبانی نمی‌شود';
                if ( ! empty( $conversion_pair['supported'] ) ) {
                    $conversion_label = 'identity' === (string) $conversion_pair['code']
                        ? 'یکسان؛ تبدیل لازم نیست'
                        : 'ضریب ثابت ریال/تومان موجود است؛ هنوز فعال نشده';
                }
                ?>
                <div class="mobo-admin-connection-meta" aria-label="قرارداد مالی اتصال — فقط خواندنی">
                    <div class="mobo-admin-meta-row"><span class="mobo-admin-meta-row__label">ارز منبع (اعلام‌شده از Backend)</span><div class="mobo-admin-meta-row__value"><code dir="ltr"><?php echo esc_html( '' !== $source_unit ? $source_unit : '—' ); ?></code></div></div>
                    <div class="mobo-admin-meta-row"><span class="mobo-admin-meta-row__label">ارز فروشگاه WooCommerce</span><div class="mobo-admin-meta-row__value"><code dir="ltr"><?php echo esc_html( '' !== $store_unit ? $store_unit : '—' ); ?></code></div></div>
                    <div class="mobo-admin-meta-row"><span class="mobo-admin-meta-row__label">قابلیت تبدیل</span><div class="mobo-admin-meta-row__value"><?php echo esc_html( $conversion_label ); ?></div></div>
                    <div class="mobo-admin-meta-row"><span class="mobo-admin-meta-row__label">نسخه قرارداد منبع</span><div class="mobo-admin-meta-row__value"><code dir="ltr"><?php echo esc_html( (string) ( $source_money['contractVersion'] ?? 0 ) ); ?></code></div></div>
                </div>
                <?php
                $selected_preference = class_exists( 'Mobo_Core_Money_Target_Settings' )
                    ? Mobo_Core_Money_Target_Settings::preference() : '';
                $destination_preview = class_exists( 'Mobo_Core_Money_Target_Settings' )
                    ? Mobo_Core_Money_Target_Settings::preview( $source_unit, $store_unit, $selected_preference )
                    : array( 'code' => 'target_settings_unavailable', 'target' => '' );
                ?>
                <div class="mobo-admin-connection-meta" aria-label="وضعیت ارز مقصد فروشگاه">
                    <div class="mobo-admin-meta-row"><span class="mobo-admin-meta-row__label">ارز مقصد انتخاب‌شده در Plugin</span><div class="mobo-admin-meta-row__value"><code dir="ltr"><?php echo esc_html( (string) ( $destination_preview['target'] ?? '' ) ); ?></code></div></div>
                    <div class="mobo-admin-meta-row"><span class="mobo-admin-meta-row__label">سازگاری مقصد با WooCommerce</span><div class="mobo-admin-meta-row__value"><?php echo esc_html( (string) ( $destination_preview['code'] ?? '' ) ); ?></div></div>
                    <div class="mobo-admin-meta-row"><span class="mobo-admin-meta-row__label">وضعیت تبدیل در Runtime</span><div class="mobo-admin-meta-row__value">غیرفعال — در انتظار تکمیل و تست پایپ‌لاین مالی</div></div>
                </div>
                <p class="description">این وضعیت صرفاً پیش‌نمایش است؛ Source Currency فقط از Backend دریافت می‌شود و تبدیل قیمت/ارسال/ثبت سفارش هنوز فعال نیست.</p>
				<?php if ( 0 === strpos( strtolower( $api_base_url ), 'http://' ) ) : ?>
					<div class="mobo-alert mobo-alert-warning mobo-admin-http-warning">
						این اتصال طبق پیکربندی سرویس مرکزی با HTTP برقرار می‌شود؛ داده‌های این مسیر در سطح HTTP رمزگذاری نمی‌شوند.
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<form id="mobo-connection-settings-form" method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="connection">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-card mobo-admin-settings-card">
				<div class="mobo-admin-card-heading">
					<div>
						<h2 class="mobo-admin-section-title"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>تنظیمات اتصال و فروشگاه</h2>
						<p>توکن و کد امنیتی را فقط برای تغییر وارد کنید. واحد پول Source توسط Backend مدیریت می‌شود؛ انتخاب ارز مقصد در این نسخه فقط پیش‌نمایش است.</p>
					</div>
				</div>

				<div class="mobo-admin-settings-grid">
					<div class="mobo-admin-settings-panel">
						<h3><span class="dashicons dashicons-admin-network" aria-hidden="true"></span>توکن اتصال</h3>
						<p>این توکن سایت را به لایسنس و اتصال شما در MoboCore مرتبط می‌کند. برای هر سایت و لایسنس باید اختصاصی باشد.</p>
						<?php $this->secret_field( 'توکن اتصال', 'mobo_core_token', $has_token ? 'توکن قبلاً ثبت شده است. فقط برای تغییر، مقدار جدید وارد کنید.' : 'توکن هنوز ثبت نشده است.' ); ?>
					</div>
					<div class="mobo-admin-settings-panel">
						<h3><span class="dashicons dashicons-shield" aria-hidden="true"></span>کد امنیتی وب‌هوک</h3>
						<p>این کد درخواست‌هایی را که از سرویس مرکزی به سایت شما می‌رسند تأیید می‌کند.</p>
						<?php $this->secret_field( 'کد امنیتی وب‌هوک', 'mobo_core_security_code', $has_security_code ? 'کد امنیتی قبلاً ثبت شده است. فقط برای تغییر، مقدار جدید وارد کنید.' : 'کد امنیتی هنوز ثبت نشده است.' ); ?>
					</div>
				</div>

				<div class="mobo-admin-security-summary" aria-label="خلاصه امنیت اتصال">
					<div class="mobo-admin-security-summary__item mobo-admin-security-summary__item--<?php echo esc_attr( $security_summary_state ); ?>">
						<span>کد امنیتی وب‌هوک</span>
						<strong><?php echo esc_html( $security_summary_label ); ?></strong>
						<?php if ( $has_security_code && ! $security_valid ) : ?>
							<small><?php echo esc_html( Mobo_Core_Settings::get_security_code_validation_error( $security_code ) ); ?></small>
						<?php elseif ( ! $has_security_code ) : ?>
							<small>برای اعتبارسنجی وب‌هوک باید کد اختصاصی این اتصال ثبت شود.</small>
						<?php else : ?>
							<small>فرمت کد برای استفاده در درخواست‌های وب‌هوک معتبر است.</small>
						<?php endif; ?>
					</div>
					<div class="mobo-admin-security-summary__item mobo-admin-security-summary__item--<?php echo esc_attr( $webhook_state ); ?>">
						<span>آخرین تست وب‌هوک</span>
						<strong><?php echo esc_html( $webhook_label ); ?></strong>
						<small><?php echo esc_html( $webhook_checked_label ); ?></small>
					</div>
				</div>

                <div class="mobo-admin-settings-panel" aria-label="تنظیمات ارز مقصد فروشگاه">
                    <h3>ارز مقصد قیمت محصولات Mobo</h3>
                    <label for="mobo_core_money_target_preference">انتخاب ارز مقصد — مشترک برای همه Connectionها</label>
                    <select name="mobo_core_money_target_preference" id="mobo_core_money_target_preference">
                        <?php
                        $money_current_preference = class_exists( 'Mobo_Core_Money_Target_Settings' ) ? Mobo_Core_Money_Target_Settings::preference() : '';
                        $money_target_choices = class_exists( 'Mobo_Core_Money_Target_Settings' ) ? Mobo_Core_Money_Target_Settings::choices() : array();
                        foreach ( $money_target_choices as $money_target_key => $money_target_label ) : ?>
                            <option value="<?php echo esc_attr( $money_target_key ); ?>" <?php selected( $money_current_preference, $money_target_key ); ?>><?php echo esc_html( $money_target_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">ارز مقصد باید با ارز WooCommerce یکسان باشد. بعد از ذخیره، تبدیل ریال/تومان در محاسبه قیمت Product Sync و Reprice اعمال می‌شود. تغییر ارز، قیمت محصولات قبلاً همگام‌شده را خودکار بازنویسی نمی‌کند؛ برای یکسان‌شدن قیمت‌ها باید اجرای جدید Reprice را از ابتدا شروع کنید. ادامه صفی که سیاست ارز آن تغییر کرده باشد، مسدود می‌شود. تبدیل مبالغ Shipping و خرید Provider هنوز فعال نیست و محافظ ناسازگاری ارز آن مسیرها حفظ می‌شود.</p>
                </div>

				<?php $this->save_button( 'ذخیره اطلاعات اتصال' ); ?>
			</div>
			<?php $this->render_license_info_card( $license_info, true ); ?>

			<details class="mobo-admin-help-accordion">
				<summary>راهنمای اتصال و امنیت</summary>
				<div class="mobo-admin-help-accordion__body">
					<?php
					$this->guide_box(
						'این صفحه چه چیزی را مدیریت می‌کند؟',
						array(
							array( 'title' => '۱. توکن اتصال را ثبت کنید', 'text' => 'توکن، این سایت را به لایسنس شما در MoboCore مرتبط می‌کند. برای هر اتصال باید توکن اختصاصی خودش استفاده شود.' ),
							array( 'title' => '۲. منتظر آماده‌شدن اتصال بمانید', 'text' => 'سرویس مرکزی اطلاعات منبع، ارائه‌دهنده و نشانی‌های لازم را به‌صورت خودکار روی همین اتصال ثبت می‌کند.' ),
							array( 'title' => '۳. کد امنیتی وب‌هوک را ثبت کنید', 'text' => 'همان کدی که برای این اتصال در سرویس مرکزی تعریف شده است در وردپرس ذخیره شود تا درخواست‌های ورودی قابل تأیید باشند.' ),
							array( 'title' => '۴. وضعیت اتصال را بررسی کنید', 'text' => 'پس از فعال‌شدن اتصال، وضعیت سرویس مرکزی، لایسنس و آخرین تست وب‌هوک از همین صفحه قابل مشاهده است.' ),
						),
						'اطلاعات ارائه‌دهنده، منبع و نشانی سرویس توسط سرویس مرکزی مدیریت می‌شوند و از داخل وردپرس قابل تغییر نیستند. توکن اتصال و کد امنیتی وب‌هوک را نیز بین سایت‌ها مشترک نکنید.'
					);
					?>
				</div>
			</details>
		</form>

		<?php $this->render_create_connection_form(); ?>
		<?php
	}

	/**
	 * Render the create-Connection form from registered Sources only.
	 * Base URLs are never customer-editable.
	 *
	 * @return void
	 */
	private function render_create_connection_form() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) ) { return; }
		?>
		<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_create_connection">
			<?php wp_nonce_field( 'mobo_core_create_connection', 'mobo_core_nonce' ); ?>
			<div class="mobo-card mobo-admin-create-card">
				<div class="mobo-admin-card-heading">
					<div>
						<h2 class="mobo-admin-section-title"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>افزودن اتصال جدید</h2>
						<p>فقط توکن اتصال را وارد کنید. بقیه اطلاعات این اتصال به‌صورت خودکار از سرویس مرکزی دریافت می‌شوند.</p>
					</div>
				</div>
				<ol class="mobo-admin-create-flow" aria-label="مراحل افزودن اتصال">
					<li><strong>۱</strong><span>توکن اتصال را ثبت کنید.</span></li>
					<li><strong>۲</strong><span>اتصال در حالت «در انتظار آماده‌سازی» ساخته می‌شود.</span></li>
					<li><strong>۳</strong><span>سرویس مرکزی اطلاعات اتصال را ثبت می‌کند.</span></li>
					<li><strong>۴</strong><span>کد امنیتی وب‌هوک را ثبت و نتیجه تست را بررسی کنید.</span></li>
				</ol>
				<div class="mobo-admin-create-row">
					<div class="mobo-field">
						<label for="mobo_new_connection_token">توکن اتصال</label>
						<input type="password" id="mobo_new_connection_token" name="mobo_new_connection_token" dir="ltr" autocomplete="new-password" spellcheck="false" autocapitalize="none" placeholder="توکن اتصال را وارد کنید" required>
						<div class="mobo-help">پس از ایجاد، اتصال تا زمان دریافت اطلاعات از سرویس مرکزی در وضعیت «در انتظار آماده‌سازی» باقی می‌ماند.</div>
					</div>
					<?php $this->save_button( 'ایجاد اتصال' ); ?>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Render license information card.
	 *
	 * @param array|null $license_info Optional preloaded license response.
	 * @param bool       $compact Compact connection-page presentation.
	 * @return void
	 */
	private function render_license_info_card( $license_info = null, $compact = false ) {
		if ( ! is_array( $license_info ) ) {
			$license_info = $this->get_license_info_for_display();
		}
		$is_error     = ! empty( $license_info['error'] );
		$is_expired   = ! empty( $license_info['isExpired'] );
		$is_deferred  = ! empty( $license_info['deferred'] );
		$license_view = $this->license_status_for_display( $license_info );
		$status_text  = $license_view['label'];
		$message      = isset( $license_info['message'] ) ? (string) $license_info['message'] : '';
		$raw          = isset( $license_info['raw'] ) && is_array( $license_info['raw'] ) ? $license_info['raw'] : array();
		$details      = $this->license_info_display_details( $raw );
		$remaining    = $this->first_license_value( $raw, array( 'remainingDays', 'remainDays', 'daysRemaining', 'leftDays', 'remainingDayCount', 'RemainingDays', 'DaysRemaining' ) );
		$expires      = $this->first_license_value( $raw, array( 'expires_at_persian', 'expired_at_persian', 'expiresAtPersian', 'expiredAtPersian', 'expiresAt', 'expireAt', 'expirationDate', 'expiryDate', 'expireDate', 'validUntil', 'endDate', 'licenseEndAt', 'ExpiresAt', 'ExpireDate', 'ValidUntil' ) );

		$checked_at = isset( $license_info['checkedAt'] ) ? absint( $license_info['checkedAt'] ) : 0;

		$webhook_suspended = $this->first_license_value( $raw, array( 'webhook_suspended', 'webhookSuspended', 'WebhookSuspended' ) );
		$webhook_suspended = in_array( strtolower( (string) $webhook_suspended ), array( '1', 'true', 'بله', 'فعال' ), true );
		$webhook_suspended_until = $this->first_license_value( $raw, array( 'webhook_suspended_until_persian', 'webhookSuspendedUntilPersian', 'webhook_suspended_until', 'webhookSuspendedUntil', 'WebhookSuspendedUntil' ) );
		$webhook_suspension_reason = $this->first_license_value( $raw, array( 'webhook_suspension_reason', 'webhookSuspensionReason', 'WebhookSuspensionReason' ) );

		if ( $compact ) :
			$friendly_message = $message;
			if ( $is_deferred ) {
				$friendly_message = '' !== trim( $message ) ? $message : 'پس از آماده و فعال شدن اتصال، وضعیت لایسنس از سرویس مرکزی بررسی می‌شود.';
			} elseif ( $is_error ) {
				$friendly_message = 'اطلاعات لایسنس از سرویس مرکزی دریافت نشد.';
				$message_lower = strtolower( $message );
				if ( false !== strpos( $message_lower, 'توکن api' ) || ( false !== strpos( $message_lower, 'token' ) && false !== strpos( $message_lower, 'ثبت نشده' ) ) ) {
					$friendly_message = 'ابتدا توکن اتصال را ثبت کنید.';
				} elseif ( false !== strpos( $message_lower, 'curl error 7' ) || false !== strpos( $message_lower, 'failed to connect' ) || false !== strpos( $message_lower, 'could not connect' ) ) {
					$friendly_message = 'ارتباط با سرویس مرکزی برقرار نشد. سرویس مرکزی در دسترس نیست یا اجرا نشده است.';
				} elseif ( false !== strpos( $message_lower, 'ssl connection' ) || false !== strpos( $message_lower, 'certificate' ) ) {
					$friendly_message = 'برقراری ارتباط امن با سرویس مرکزی ناموفق بود.';
				} elseif ( false !== strpos( $message_lower, 'timed out' ) || false !== strpos( $message_lower, 'timeout' ) ) {
					$friendly_message = 'پاسخ سرویس مرکزی در زمان مقرر دریافت نشد.';
				} elseif ( false !== strpos( $message_lower, '401' ) || false !== strpos( $message_lower, '403' ) ) {
					$friendly_message = 'احراز هویت لایسنس توسط سرویس مرکزی رد شد.';
				}
			} elseif ( '' === trim( $friendly_message ) ) {
				if ( 'success' === $license_view['state'] ) {
					$friendly_message = 'اطلاعات لایسنس با موفقیت دریافت شد.';
				} elseif ( 'danger' === $license_view['state'] ) {
					$friendly_message = 'این لایسنس برای استفاده فعال نیست.';
				} elseif ( 'warning' === $license_view['state'] ) {
					$friendly_message = 'وضعیت لایسنس نیاز به بررسی دارد.';
				}
			}
			?>
			<div class="mobo-card mobo-card-license" style="margin-top:16px;">
				<div class="mobo-admin-card-heading">
					<div>
						<h2 class="mobo-admin-section-title"><span class="dashicons dashicons-id" aria-hidden="true"></span>اطلاعات لایسنس</h2>
						<p>وضعیت لایسنس همین اتصال از سرویس مرکزی دریافت می‌شود.</p>
					</div>
					<span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( $license_view['state'] ); ?>"><?php echo esc_html( $status_text ); ?></span>
				</div>

				<div class="mobo-admin-license-grid<?php echo ( $is_error || $is_deferred ) ? ' is-error-only' : ''; ?>">
					<?php if ( ! $is_error && ! $is_deferred ) : ?>
						<div class="mobo-admin-license-facts">
							<?php if ( '—' !== $expires ) : ?>
								<div class="mobo-admin-license-fact"><span>زمان انقضا</span><strong><?php echo esc_html( $expires ); ?></strong></div>
							<?php endif; ?>
							<?php if ( '—' !== $remaining ) : ?>
								<div class="mobo-admin-license-fact"><span>روزهای باقی‌مانده</span><strong><?php echo esc_html( $remaining ); ?></strong></div>
							<?php endif; ?>
							<?php if ( $checked_at > 0 ) : ?>
								<div class="mobo-admin-license-fact"><span>آخرین بررسی</span><strong><?php echo esc_html( Mobo_Core_Iran_Date::format( 'Y-m-d H:i', $checked_at ) ); ?> تهران</strong></div>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<div class="mobo-admin-license-state mobo-admin-license-state--<?php echo esc_attr( $license_view['state'] ); ?>">
						<h3><?php echo esc_html( $license_view['headline'] ); ?></h3>
						<p><?php echo esc_html( $friendly_message ); ?></p>
					</div>
				</div>

				<?php if ( $webhook_suspended ) : ?>
					<div class="mobo-alert mobo-alert-warning" style="margin-top:14px;">ارسال وب‌هوک از سرویس مرکزی موقتاً متوقف شده است<?php echo '—' !== $webhook_suspended_until ? ' تا ' . esc_html( $webhook_suspended_until ) : ''; ?>. دلیل: <?php echo esc_html( '—' !== $webhook_suspension_reason ? $webhook_suspension_reason : 'خطاهای متوالی در دریافت وب‌هوک' ); ?></div>
				<?php endif; ?>

				<?php if ( $is_error && '' !== trim( $message ) ) : ?>
					<details class="mobo-admin-details">
						<summary>جزئیات فنی خطا (برای پشتیبانی)</summary>
						<div class="mobo-admin-details__body">
							<code class="mobo-admin-technical-error" dir="ltr"><?php echo esc_html( $message ); ?></code>
						</div>
					</details>
				<?php endif; ?>

				<?php if ( ! $is_error && ! $is_deferred && ! empty( $details ) ) : ?>
					<details class="mobo-admin-details">
						<summary>جزئیات فنی لایسنس (برای پشتیبانی)</summary>
						<div class="mobo-admin-details__body mobo-guide-table-wrap">
							<table class="widefat striped mobo-guide-table">
								<thead><tr><th>فیلد</th><th>مقدار</th></tr></thead>
								<tbody>
								<?php foreach ( $details as $row ) : ?>
									<tr><td><code><?php echo esc_html( $row['key'] ); ?></code></td><td><?php echo esc_html( $row['value'] ); ?></td></tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</details>
				<?php endif; ?>
			</div>
			<?php
			return;
		endif;
		?>
		<div class="mobo-card mobo-card-license" style="margin-top:16px;">
			<div class="mobo-card-head">
				<h2>اطلاعات لایسنس</h2>
				<p>این بخش از endpoint قدیمی <code>LicenseInfo</code> خوانده می‌شود و وضعیت اعتبار لایسنس را نمایش می‌دهد.</p>
			</div>

			<div class="mobo-status-grid">
				<?php $this->status_box( 'وضعیت لایسنس', $status_text ); ?>
				<?php $this->status_box( 'روزهای باقی‌مانده', $remaining ); ?>
				<?php $this->status_box( 'تاریخ پایان', $expires ); ?>
			</div>

			<?php if ( '' !== trim( $message ) ) : ?>
				<div class="mobo-message <?php echo in_array( $license_view['state'], array( 'danger', 'warning' ), true ) ? 'mobo-message-error' : 'mobo-message-info'; ?>"><?php echo esc_html( $message ); ?></div>
			<?php endif; ?>

			<?php if ( $webhook_suspended ) : ?>
				<div class="mobo-alert mobo-alert-warning">ارسال webhook از MoboCore برای این سایت موقتاً معلق شده است<?php echo '—' !== $webhook_suspended_until ? ' تا ' . esc_html( $webhook_suspended_until ) : ''; ?>. دلیل: <?php echo esc_html( '—' !== $webhook_suspension_reason ? $webhook_suspension_reason : 'خطاهای متوالی در دریافت webhook' ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $details ) ) : ?>
				<div class="mobo-guide-table-wrap">
					<table class="widefat striped mobo-guide-table">
						<thead><tr><th>فیلد</th><th>مقدار</th></tr></thead>
						<tbody>
						<?php foreach ( $details as $row ) : ?>
							<tr><td><code><?php echo esc_html( $row['key'] ); ?></code></td><td><?php echo esc_html( $row['value'] ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<div class="mobo-note">اگر این بخش خطای اتصال نشان دهد، معمولاً Token یا API Base URL مشکل دارد. این بخش sync را متوقف نمی‌کند، فقط وضعیت لایسنس را از MoboCore نمایش می‌دهد.</div>
		</div>
		<?php
	}

	/**
	 * Fetch license info for admin display.
	 *
	 * @return array
	 */
	private function get_license_info_for_display() {
		$checked_at = time();
		$api_client = new Mobo_Core_API_Client();
		$response   = $api_client->get_license_info();

		if ( is_wp_error( $response ) ) {
			return array(
				'error'     => true,
				'isExpired' => false,
				'message'   => 'اطلاعات لایسنس دریافت نشد: ' . $response->get_error_message(),
				'raw'       => array(
					'errorCode'    => $response->get_error_code(),
					'errorMessage' => $response->get_error_message(),
				),
				'checkedAt' => $checked_at,
			);
		}

		if ( ! is_array( $response ) ) {
			return array(
				'error'     => true,
				'isExpired' => false,
				'message'   => 'پاسخ LicenseInfo معتبر نیست.',
				'raw'       => array(),
				'checkedAt' => $checked_at,
			);
		}

		return array(
			'error'     => false,
			'isExpired' => $this->license_bool_value( isset( $response['isExpired'] ) ? $response['isExpired'] : false ),
			'message'   => isset( $response['message'] ) && is_scalar( $response['message'] ) ? (string) $response['message'] : '',
			'raw'       => $response,
			'checkedAt' => $checked_at,
		);
	}

	/**
	 * Convert mixed API boolean value to bool.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function license_bool_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'بله', 'on' ), true );
	}

	/**
	 * Read the first available license response field.
	 *
	 * @param array $info Raw license info.
	 * @param array $keys Candidate keys.
	 * @return string
	 */
	private function first_license_value( $info, $keys ) {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $info ) && is_scalar( $info[ $key ] ) && '' !== trim( (string) $info[ $key ] ) ) {
				return (string) $info[ $key ];
			}
		}

		return '—';
	}

	/**
	 * Prepare scalar LicenseInfo fields for display.
	 *
	 * @param array $info Raw license info.
	 * @return array
	 */
	private function license_info_display_details( $info ) {
		$rows         = array();
		$hidden_keys  = array( 'token', 'licenseToken', 'Token', 'LicenseToken' );
		$primary_keys = array( 'isExpired', 'message' );

		foreach ( $info as $key => $value ) {
			$key = (string) $key;

			if ( in_array( $key, $hidden_keys, true ) || in_array( $key, $primary_keys, true ) ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$value = $value ? 'بله' : 'خیر';
			} elseif ( is_array( $value ) || is_object( $value ) ) {
				continue;
			} elseif ( null === $value || '' === trim( (string) $value ) ) {
				continue;
			} elseif ( preg_match( '/(?:at|until|date)$/i', $key ) && false !== strtotime( (string) $value ) && false === stripos( $key, 'persian' ) && false === stripos( $key, 'display' ) ) {
				$value = Mobo_Core_Iran_Date::format_value( $value );
			}

			$rows[] = array(
				'key'   => $key,
				'value' => (string) $value,
			);
		}

		return $rows;
	}

	/**
	 * Render product tab.
	 *
	 * @return void
	 */
	private function render_product_tab() {
		?>
		<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="mobo-settings-form mobo-product-settings-shell">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="product">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<section class="mobo-product-settings-intro">
				<div>
					<h2>چه اطلاعاتی در همگام‌سازی‌های بعدی به‌روزرسانی شوند؟</h2>
					<p>این تنظیمات مشخص می‌کنند کدام بخش‌های <strong>محصولات موجود</strong> اجازه بازنویسی دارند. محصول جدید در اولین دریافت با اطلاعات اصلی منبع ساخته می‌شود؛ خاموش کردن یک گزینه در این صفحه به معنی ساخته‌شدن محصول جدید بدون آن اطلاعات نیست.</p>
				</div>
				<span class="mobo-product-settings-intro__badge">برای اتصال فعلی</span>
			</section>

			<div class="mobo-product-settings-grid">
				<section class="mobo-product-setting-group">
					<div class="mobo-product-setting-group__head">
						<span class="mobo-product-setting-group__icon"><span class="dashicons dashicons-chart-line" aria-hidden="true"></span></span>
						<div><h3>موجودی و قیمت</h3><p>فیلدهای عملیاتی فروش که معمولاً باید با منبع هماهنگ بمانند.</p></div>
					</div>
					<div class="mobo-fields-grid">
						<?php $this->bool_field( 'بروزرسانی موجودی', 'global_product_auto_stock', false, '', 'اگر روشن باشد، موجودی محصول و تنوع‌ها در همگام‌سازی‌های بعدی با منبع اصلی هماهنگ می‌شود.' ); ?>
						<?php $this->bool_field( 'بروزرسانی قیمت از منبع', 'global_product_auto_price', false, '', 'اگر روشن باشد، تغییر قیمت پایه در منبع روی محصول موجود اعمال می‌شود و سپس قوانین قیمت‌گذاری MoboCore روی قیمت نهایی اثر می‌گذارند.' ); ?>
						<?php $this->bool_field( 'اعمال قیمت تخفیف منبع', 'global_product_auto_compare_price', false, '', 'اگر منبع قیمت عادی و قیمت فروش معتبر ارسال کند، WooCommerce آن را به‌صورت قیمت عادی و قیمت فروش ثبت می‌کند. روشن بودن این گزینه می‌تواند جفت قیمت محصول را حتی مستقل از گزینه «بروزرسانی قیمت از منبع» دوباره محاسبه کند.' ); ?>
						<?php $this->bool_field( 'دریافت فقط محصولات موجود', 'mobo_core_only_in_stock', false, '', 'اگر روشن باشد، در دریافت فهرست محصولات فقط کالاهای موجود درخواست می‌شوند. این گزینه پوشش کاتالوگ فروشگاه را کمتر می‌کند و معمولاً باید خاموش بماند.' ); ?>
					</div>
				</section>

				<section class="mobo-product-setting-group">
					<div class="mobo-product-setting-group__head">
						<span class="mobo-product-setting-group__icon"><span class="dashicons dashicons-edit-page" aria-hidden="true"></span></span>
						<div><h3>محتوا و آدرس</h3><p>برای سایت فعال، تغییر عنوان و نشانی محصول باید با سیاست محتوایی فروشگاه هماهنگ باشد.</p></div>
					</div>
					<div class="mobo-fields-grid">
						<?php $this->bool_field( 'بروزرسانی عنوان محصول', 'global_product_auto_title', false, '', 'اگر روشن باشد، تغییر عنوان در منبع می‌تواند عنوانی را که داخل WooCommerce ویرایش کرده‌اید بازنویسی کند. برای سایت فعال معمولاً بهتر است خاموش باشد.' ); ?>
						<?php $this->bool_field( 'بروزرسانی نامک و آدرس محصول', 'global_product_auto_slug', false, '', 'اگر روشن باشد، تغییر نامک در منبع می‌تواند URL محصول را عوض کند. برای سایت فعال معمولاً بهتر است خاموش باشد.' ); ?>
						<?php $this->bool_field( 'دریافت توضیحات محصول از منبع', 'global_product_auto_description', false, '', 'فقط برای همین اتصال. پیش‌فرض خاموش است. اگر روشن باشد و منبع توضیحات محصول را ارسال کند، توضیحات WooCommerce بازنویسی می‌شود. منبع mobomobo.ir توضیحات ارسال نمی‌کند.' ); ?>
					</div>
				</section>

				<section class="mobo-product-setting-group">
					<div class="mobo-product-setting-group__head">
						<span class="mobo-product-setting-group__icon"><span class="dashicons dashicons-format-image" aria-hidden="true"></span></span>
						<div><h3>تصاویر و کش</h3><p>پردازش تصویر و کش جدا از نوشتن اطلاعات اصلی محصول کنترل می‌شود.</p></div>
					</div>
					<div class="mobo-fields-grid">
						<?php $this->bool_field( 'بروزرسانی تصاویر محصول', 'global_update_images', false, '', 'اگر روشن باشد، تصاویر جدید یا تغییرکرده در صف مستقل تصویر دریافت و بروزرسانی می‌شوند.' ); ?>
						<?php $this->cache_integration_mode_field(); ?>
						<?php $this->bool_field( 'پیش‌بارگذاری صفحه محصول بعد از بروزرسانی', 'mobo_core_cache_warmup_enabled', false, '', 'اختیاری و به‌طور پیش‌فرض خاموش. بعد از تغییر محصول، صفحه همان محصول یک‌بار در پس‌زمینه باز می‌شود تا کش آن برای بازدید بعدی آماده باشد. این کار فقط در اجرای پس‌زمینه موبو، با فاصله و تعداد محدود انجام می‌شود و هنگام فشار روی هاست یا تغییرات انبوه قیمت متوقف می‌شود. اگر Preload افزونه کش (مثل WP Rocket) روشن است، معمولاً به این گزینه نیازی ندارید.' ); ?>
						<div class="mobo-field">
							<label for="mobo_core_cache_warmup_policy">سیاست انتخاب محصولات برای Warmup</label>
							<select id="mobo_core_cache_warmup_policy" name="mobo_core_cache_warmup_policy">
								<option value="selective" <?php selected( Mobo_Core_Settings::get( 'mobo_core_cache_warmup_policy', 'selective' ), 'selective' ); ?>>انتخابی (پیشنهادی برای کاتالوگ بزرگ)</option>
								<option value="all" <?php selected( Mobo_Core_Settings::get( 'mobo_core_cache_warmup_policy', 'selective' ), 'all' ); ?>>همه محصولات تغییرکرده (سازگاری با رفتار قبلی)</option>
							</select>
							<div class="mobo-help">در حالت انتخابی، محصولات بدون سابقه تقاضا فقط Purge می‌شوند و بازدید اول کش را می‌سازد. سابقه فروش سیگنال WARM است؛ یک سیستم آمار بازدید می‌تواند با فیلتر mobo_core_cache_warmup_product_tier اولویت HOT را تعیین کند. این تنظیمات صف را به‌طور خودکار با ۳۰ هزار URL پر نمی‌کند.</div>
						</div>
						<?php $this->cache_archive_purge_interval_field(); ?>
					</div>
				</section>
			</div>

			<details class="mobo-admin-details mobo-product-technical-notes">
				<summary>جزئیات فنی همگام‌سازی و کش</summary>
				<div class="mobo-admin-details__body mobo-product-notes">
					<div class="mobo-note">تغییرات محصول طوری پردازش می‌شوند که همگام‌سازی اصلی منتظر دانلود تصویر یا آماده‌شدن کش نماند. در حالت امن، کش صفحه خود محصول (حداکثر ۲۵ صفحه در هر درخواست) تازه می‌شود؛ در تغییرات انبوه (مثلاً تغییر قیمت هزاران محصول از منبع) همه صفحات در یک صف تجمیعی قرار می‌گیرند و به‌صورت محدود و مرحله‌ای پاک‌سازی می‌شوند. صفحات فروشگاه، دسته‌ها و برچسب‌ها طبق فاصله انتخاب‌شده پاک‌سازی می‌شوند. موبو هرگز کل کش سایت را پاک نمی‌کند و Preload یا RUCSS افزونه WP Rocket را مستقیماً اجرا نمی‌کند.</div>
					<div class="mobo-note">در این نسخه محتوای توضیحی/caption از منبع همگام‌سازی نمی‌شود. محصول یا تنوعی که داخل سطل زباله باشد نیز به‌طور خودکار بازیابی یا منتشر نمی‌شود.</div>
				</div>
			</details>

			<div class="mobo-product-help-stack">
				<details class="mobo-admin-details">
					<summary>راهنمای رفتار بروزرسانی محصول</summary>
					<div class="mobo-admin-details__body">
						<?php
						$this->guide_box(
							'راهنمای بروزرسانی محصول',
							array(
								array( 'title' => 'محصول جدید', 'text' => 'محصول جدید در اولین دریافت با اطلاعات اصلی منبع ساخته می‌شود. گزینه‌های این صفحه عمدتاً تعیین می‌کنند در دفعات بعد کدام اطلاعات اجازه بازنویسی داشته باشند.' ),
								array( 'title' => 'محصول موجود', 'text' => 'اگر یک گزینه خاموش باشد، همان بخش از محصول موجود در همگام‌سازی‌های بعدی حفظ می‌شود و تغییر دستی فروشگاه بازنویسی نمی‌شود.' ),
								array( 'title' => 'قیمت', 'text' => 'قیمت منبع مبنای محاسبه است و سیاست قیمت‌گذاری MoboCore بعد از آن اعمال می‌شود. گزینه «قیمت تخفیف منبع» نیز می‌تواند جفت قیمت عادی/فروش را دوباره محاسبه کند.' ),
								array( 'title' => 'تصاویر', 'text' => 'تصاویر در صف مستقل پردازش می‌شوند تا همگام‌سازی محصول منتظر دانلود تصویر نماند. خطای تصویر به‌تنهایی محصول را متوقف نمی‌کند.' ),
							),
							'اگر فروشگاه فعال و SEO شده است، عنوان و نامک را خاموش نگه دارید مگر اینکه منبع مالک قطعی محتوای محصول باشد.'
						);
						?>
					</div>
				</details>

				<details class="mobo-admin-details">
					<summary>تنظیمات پیشنهادی برای سایت فعال</summary>
					<div class="mobo-admin-details__body">
						<?php
						$this->recommendation_box(
							'تنظیمات پیشنهادی بروزرسانی محصول',
							array(
								array( 'setting' => 'موجودی', 'value' => 'روشن', 'reason' => 'موجودی یک داده عملیاتی است و معمولاً باید با منبع اصلی هماهنگ بماند.' ),
								array( 'setting' => 'قیمت از منبع', 'value' => 'روشن', 'reason' => 'تغییر قیمت پایه منبع روی محصول موجود اعمال می‌شود و سیاست قیمت‌گذاری MoboCore بعد از آن اجرا می‌شود.' ),
								array( 'setting' => 'قیمت تخفیف منبع', 'value' => 'روشن', 'reason' => 'از price/comparePrice منبع برای ساخت قیمت عادی و فروش WooCommerce استفاده می‌شود؛ این گزینه می‌تواند جفت قیمت را مستقل از گزینه قیمت پایه نیز بازحساب کند.' ),
								array( 'setting' => 'عنوان محصول', 'value' => 'خاموش برای سایت فعال', 'reason' => 'روشن بودن آن می‌تواند عنوانی را که در WooCommerce ویرایش کرده‌اید بازنویسی کند.' ),
								array( 'setting' => 'نامک و آدرس محصول', 'value' => 'خاموش برای سایت فعال', 'reason' => 'تغییر نامک URL محصول را عوض می‌کند و ممکن است به redirect نیاز داشته باشد.' ),
								array( 'setting' => 'دریافت فقط محصولات موجود', 'value' => 'معمولاً خاموش', 'reason' => 'روشن کردن آن محصولات ناموجود را از دریافت اولیه حذف می‌کند و پوشش کاتالوگ را کاهش می‌دهد.' ),
								array( 'setting' => 'تصاویر محصول', 'value' => 'روشن', 'reason' => 'تصاویر در صف مستقل پردازش می‌شوند و همگام‌سازی اصلی را متوقف نمی‌کنند.' ),
							),
							'برای نصب جدید، عنوان و نامک به‌صورت پیش‌فرض خاموش هستند. ارتقای سایت‌های موجود مقدار فعلی آن‌ها را تغییر نمی‌دهد.'
						);
						?>
					</div>
				</details>
			</div>

			<div class="mobo-product-save-bar"><?php $this->save_button(); ?></div>
		</form>
		<?php
	}



	/**
	 * Render checkout validation tab.
	 *
	 * @return void
	 */

	/** Manager-friendly checkout overview. */
	private function render_checkout_tab() {
		$validator = class_exists( 'Mobo_Core_Checkout_Validator' ) ? new Mobo_Core_Checkout_Validator() : null;
		$order_enabled = Mobo_Core_Order_Submission_Policy::is_enabled();
		$checkout_enabled = Mobo_Core_Settings::enabled( 'mobo_core_checkout_validation_enabled', '0' );
		$cart_enabled = $checkout_enabled && Mobo_Core_Settings::enabled( 'mobo_core_checkout_mobo_cart_validation_enabled', '0' );
		$external_enabled = $checkout_enabled && Mobo_Core_Settings::enabled( 'mobo_core_checkout_external_validation_enabled', '0' );
		$external_url = trim( (string) Mobo_Core_Settings::get( 'mobo_core_checkout_external_validation_url', '' ) );
		$readiness = $this->get_mobo_order_submission_readiness();
		$ready = ! empty( $readiness['ready'] );
		$badge_class = ! $order_enabled ? 'info' : ( $ready ? 'success' : 'warning' );
		$badge_label = ! $order_enabled ? 'غیرفعال' : ( $ready ? 'پیکربندی آماده' : 'نیازمند تکمیل' );
		$optional_checks = ( Mobo_Core_Settings::enabled( 'mobo_core_checkout_local_stock_check_enabled', '0' ) ? 1 : 0 )
			+ ( Mobo_Core_Settings::enabled( 'mobo_core_checkout_mobo_cart_validation_enabled', '0' ) ? 1 : 0 )
			+ ( Mobo_Core_Settings::enabled( 'mobo_core_checkout_external_validation_enabled', '0' ) ? 1 : 0 );
		?>
		<div class="mobo-card mobo-ux-primary-card">
			<div class="mobo-admin-card-heading"><div><h2 class="mobo-admin-section-title">ثبت و اعتبارسنجی سفارش</h2><p>آمادگی محلی ثبت خودکار، سیاست نوع سفارش و کنترل‌های اختیاری قبل از پرداخت را بررسی کنید.</p></div><span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span></div>
			<div class="mobo-ux-summary-grid">
				<?php $this->status_box( 'ثبت خودکار سفارش', $order_enabled ? 'روشن' : 'خاموش' ); ?>
				<?php $this->status_box( 'Trigger ثبت خودکار', 'WooCommerce processing' ); ?>
				<?php $this->status_box( 'سفارش قابل ارسال', 'فقط تماماً موبو + یک Connection' ); ?>
				<?php $this->status_box( 'کنترل‌های اختیاری قبل از پرداخت', $checkout_enabled ? ( $optional_checks . ' فعال' ) : 'خاموش' ); ?>
				<?php $this->status_box( 'بررسی سبد موبو', $cart_enabled ? 'روشن' : 'خاموش' ); ?>
				<?php $this->status_box( 'اعتبارسنجی خارجی', $external_enabled ? ( '' !== $external_url ? 'روشن' : 'روشن / URL تنظیم نشده' ) : 'خاموش' ); ?>
				<?php $this->status_box( 'Resolver آدرس', ! empty( $readiness['resolverReady'] ) ? 'Backend مرکزی' : 'در دسترس نیست' ); ?>
				<?php $this->status_box( 'روش‌های ارسال موبو', absint( isset( $readiness['shippingCount'] ) ? $readiness['shippingCount'] : 0 ) ); ?>
				<?php $this->status_box( 'نگاشت روش‌های فعال WooCommerce', absint( isset( $readiness['shippingMapped'] ) ? $readiness['shippingMapped'] : 0 ) . ' از ' . absint( isset( $readiness['shippingRequired'] ) ? $readiness['shippingRequired'] : 0 ) ); ?>
			</div>
			<?php if ( $order_enabled && ! $ready ) : ?>
				<div class="mobo-message mobo-message-warning"><strong>ثبت خودکار روشن است اما پیکربندی محلی کامل نیست.</strong> <?php $visible_issues = ! empty( $readiness['issues'] ) ? array_slice( $readiness['issues'], 0, 3 ) : array(); $issue_text = ! empty( $visible_issues ) ? implode( ' | ', $visible_issues ) : 'جزئیات را در بخش تنظیمات بررسی کنید.'; if ( ! empty( $readiness['issues'] ) && count( $readiness['issues'] ) > count( $visible_issues ) ) { $issue_text .= ' | و ' . ( count( $readiness['issues'] ) - count( $visible_issues ) ) . ' مورد دیگر'; } echo esc_html( $issue_text ); ?></div>
			<?php elseif ( $order_enabled ) : ?>
				<div class="mobo-message mobo-message-success">پیش‌نیازهای محلی ثبت خودکار آماده‌اند. این وضعیت، دسترسی لحظه‌ای شبکه یا موفقیت خرید واقعی در Source را تضمین نمی‌کند؛ آن بخش فقط با اجرای runtime قابل اثبات است.</div>
			<?php else : ?>
				<div class="mobo-message mobo-message-info">ثبت خودکار خاموش است. Checkout طبق رفتار عادی WooCommerce ادامه می‌دهد و سفارش‌ها به موبو ارسال نمی‌شوند.</div>
			<?php endif; ?>
			<div class="mobo-note"><strong>سیاست Fail-Closed سفارش:</strong> فقط سفارش <strong>تماماً موبو</strong> که مالکیت آن به یک Connection مشخص قابل انتساب باشد برای ثبت در موبو واجد شرایط است. سفارش ترکیبی، غیرموبو یا Multi-Connection هیچ بخشی را به موبو ارسال نمی‌کند.</div>
		</div>
		<details class="mobo-admin-details mobo-ux-secondary-details">
			<summary>تنظیمات، نگاشت آدرس و روش‌های ارسال</summary>
			<div class="mobo-admin-details__body"><?php $this->render_checkout_technical_tab(); ?></div>
		</details>
		<?php
	}

	private function render_checkout_technical_tab() {
		$validator = class_exists( 'Mobo_Core_Checkout_Validator' ) ? new Mobo_Core_Checkout_Validator() : null;
		$status    = $validator ? $validator->get_last_status() : array();
		$last      = isset( $status['lastResult'] ) && is_array( $status['lastResult'] ) ? $status['lastResult'] : array();
		$debug_log = $validator && method_exists( $validator, 'get_mobo_debug_log' ) ? $validator->get_mobo_debug_log() : array();
		$address_mapping = class_exists( 'Mobo_Core_Address_Mapping' ) ? new Mobo_Core_Address_Mapping() : null;
		$address_status  = $address_mapping && method_exists( $address_mapping, 'get_status' ) ? $address_mapping->get_status() : array();
		$address_manual_status = isset( $address_status['manualMapping'] ) && is_array( $address_status['manualMapping'] ) ? $address_status['manualMapping'] : array();
		$remote_shipping_manager = class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ? new Mobo_Core_Remote_Shipping_Methods() : null;
		$remote_shipping_status  = $remote_shipping_manager && method_exists( $remote_shipping_manager, 'get_status' ) ? $remote_shipping_manager->get_status() : array();
		$remote_shipping_methods = $remote_shipping_manager && method_exists( $remote_shipping_manager, 'get_methods' ) ? $remote_shipping_manager->get_methods() : array();
		$remote_shipping_snapshot = Mobo_Core_Settings::get( 'mobo_core_remote_shipping_methods_snapshot', array() );
		$remote_shipping_changed_at = absint( Mobo_Core_Settings::get( 'mobo_core_remote_shipping_methods_changed_at', 0 ) );
		$portal_webhook_delivery_status = Mobo_Core_Settings::get( 'mobo_core_portal_webhook_delivery_status', array() );
		$shipping_diagnostics = class_exists( 'Mobo_Core_Shipping_Diagnostics' ) ? new Mobo_Core_Shipping_Diagnostics() : null;
		$shipping_report = $shipping_diagnostics && method_exists( $shipping_diagnostics, 'get_last_report' ) ? $shipping_diagnostics->get_last_report() : array();
		$persian_wc_plugins = $this->get_active_persian_woocommerce_plugins();
		$poina_allowlist_status = $this->get_poina_domain_allowlist_status();
		$order_submission_enabled = Mobo_Core_Order_Submission_Policy::is_enabled();
		$checkout_master_enabled = Mobo_Core_Settings::enabled( 'mobo_core_checkout_validation_enabled', '0' );
		$mobo_cart_validation_enabled = $checkout_master_enabled && Mobo_Core_Settings::enabled( 'mobo_core_checkout_mobo_cart_validation_enabled', '0' );
		$external_validation_enabled = $checkout_master_enabled && Mobo_Core_Settings::enabled( 'mobo_core_checkout_external_validation_enabled', '0' );
		$order_readiness = $this->get_mobo_order_submission_readiness();

		?>
		<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="checkout">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>


			<?php if ( ! empty( $poina_allowlist_status['installed'] ) ) : ?>
				<div class="mobo-alert mobo-alert-warning">
					افزونه <code>poina-domain-allowlist</code> روی این سایت پیدا شد<?php echo ! empty( $poina_allowlist_status['active'] ) ? ' و فعال است' : ' اما فعال نیست'; ?>. اگر این افزونه درخواست‌های خروجی را محدود می‌کند، دامنه‌های provision‌شده روی Connection باید به allowlist آن اضافه شوند؛ در غیر این صورت عملیات‌هایی مثل دریافت اطلاعات لایسنس/MoboCore، ورود به موبو، بررسی سبد موبو و ثبت سفارش خودکار ممکن است انجام نشوند.
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $persian_wc_plugins ) ) : ?>
				<div class="mobo-alert mobo-alert-info">
					افزونه فارسی‌ساز/پرشین ووکامرس روی سایت فعال است: <?php echo esc_html( implode( '، ', $persian_wc_plugins ) ); ?>. Mobo Core مالک فیلدهای آدرس Checkout نیست؛ این افزونه‌ها می‌توانند فیلدهای خودشان را مدیریت کنند و هنگام ثبت سفارش، country/state/city ذخیره‌شده روی سفارش به Resolver مرکزی ارسال می‌شود. Cache شهرهای موبو فقط یک قابلیت سازگاری اختیاری است.
				</div>
			<?php endif; ?>

			<div class="mobo-grid">
				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>سازگاری سیستم و checkout</h2>
						<p>این وضعیت‌ها فقط برای جلوگیری از خطاهای محیطی و تداخل افزونه‌ها نمایش داده می‌شوند.</p>
					</div>

					<div class="mobo-status-grid">
						<?php $this->status_box( 'محدودسازی دامنه Poina', ! empty( $poina_allowlist_status['installed'] ) ? ( ! empty( $poina_allowlist_status['active'] ) ? 'نصب و فعال' : 'نصب شده / غیرفعال' ) : 'پیدا نشد' ); ?>
						<?php $this->status_box( 'دامنه‌های لازم برای allowlist', 'از Connection provision‌شده خوانده می‌شود' ); ?>
						<?php $this->status_box( 'افزونه‌های فارسی ووکامرس', ! empty( $persian_wc_plugins ) ? implode( '، ', $persian_wc_plugins ) : 'پیدا نشد' ); ?>
					</div>

					<div class="mobo-note">
						اگر ثبت سفارش خودکار موبو خاموش باشد، Checkout کاملاً با رفتار عادی WooCommerce کار می‌کند. برای ثبت خودکار، آدرس native سفارش توسط Resolver مرکزی MoboCore تبدیل می‌شود؛ در این صفحه فقط credentialها، اطلاعات فرستنده و نگاشت روش‌های ارسال را کامل کنید.
					</div>
				</div>

				<?php if ( ! empty( $portal_webhook_delivery_status ) || ! empty( $remote_shipping_snapshot ) ) : ?>
					<div class="mobo-card">
						<div class="mobo-card-head">
							<h2>وضعیت MoboCore و روش‌های ارسال موبو</h2>
							<p>این بخش تغییراتی را نشان می‌دهد که از MoboCore مرکزی یا webhook سیستمی دریافت شده‌اند. اعمال نهایی تغییرات روش ارسال باید توسط مدیر انجام شود.</p>
						</div>
						<div class="mobo-status-grid">
							<?php if ( ! empty( $portal_webhook_delivery_status ) && is_array( $portal_webhook_delivery_status ) ) : ?>
								<?php $this->status_box( 'وضعیت ارسال webhook از MoboCore', isset( $portal_webhook_delivery_status['status'] ) ? $portal_webhook_delivery_status['status'] : 'دریافت شده' ); ?>
								<?php $this->status_box( 'تعلیق تا', ! empty( $portal_webhook_delivery_status['suspendedUntil'] ) ? $portal_webhook_delivery_status['suspendedUntil'] : '—' ); ?>
								<?php $this->status_box( 'دلیل', ! empty( $portal_webhook_delivery_status['reason'] ) ? $portal_webhook_delivery_status['reason'] : ( ! empty( $portal_webhook_delivery_status['suspensionReason'] ) ? $portal_webhook_delivery_status['suspensionReason'] : '—' ) ); ?>
							<?php endif; ?>
							<?php if ( ! empty( $remote_shipping_snapshot ) && is_array( $remote_shipping_snapshot ) ) : ?>
								<?php $shipping_items = isset( $remote_shipping_snapshot['shippings'] ) && is_array( $remote_shipping_snapshot['shippings'] ) ? $remote_shipping_snapshot['shippings'] : array(); ?>
								<?php $this->status_box( 'آخرین تغییر روش‌های ارسال موبو', $remote_shipping_changed_at > 0 ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', $remote_shipping_changed_at ) : 'دریافت شده' ); ?>
								<?php $this->status_box( 'تعداد روش‌های ارسال دریافت‌شده', count( $shipping_items ) ); ?>
							<?php endif; ?>
						</div>
						<?php if ( ! empty( $remote_shipping_snapshot['shippings'] ) && is_array( $remote_shipping_snapshot['shippings'] ) ) : ?>
							<div class="mobo-note">روش‌های ارسال جدید از MoboCore دریافت شده‌اند. این لیست فقط برای نگاشت روش‌های WooCommerce به shipping_id موبو استفاده می‌شود و هیچ گزینه‌ای به Checkout اضافه نمی‌کند.</div>
							<div style="max-height:240px;overflow:auto;border:1px solid #e5e7eb;background:#fff;border-radius:10px;padding:10px;">
								<?php foreach ( array_slice( $remote_shipping_snapshot['shippings'], 0, 12 ) as $shipping_item ) : ?>
									<div style="padding:8px 0;border-bottom:1px solid #f3f4f6;">
										<strong><?php echo esc_html( isset( $shipping_item['title'] ) ? $shipping_item['title'] : 'روش ارسال موبو' ); ?></strong>
										<span style="color:#6b7280;"> — هزینه: <?php echo esc_html( isset( $shipping_item['cost'] ) && null !== $shipping_item['cost'] ? number_format_i18n( (float) $shipping_item['cost'] ) : '—' ); ?></span>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>اعتبارسنجی قبل از خرید</h2>
						<p>این بخش قبل از پرداخت، آیتم‌های سبد خرید را بررسی می‌کند. در حالت عادی اختیاری است؛ اما با فعال شدن ثبت خودکار سفارش، تست واقعی افزودن کالا به سبد موبو الزامی و خودکار می‌شود.</p>
					</div>

					<div class="mobo-fields-grid">
						<?php $this->bool_field( 'فعال بودن کنترل‌های قبل از پرداخت', 'mobo_core_checkout_validation_enabled' ); ?>
						<div class="mobo-field mobo-field-full"><div class="mobo-help">این گزینه کلید اصلی کنترل‌های اختیاری است و به تنهایی هیچ موجودی را بررسی نمی‌کند. با روشن شدن آن، فقط کنترل‌هایی اجرا می‌شوند که در ادامه جداگانه فعال کرده‌اید. ثبت سفارش خودکار، بررسی سبد موبو را مستقل از این کلید اجباری می‌کند.</div></div>
						<div data-mobo-ui-group="master-checkout">
							<?php $this->bool_field( 'بررسی موجودی ثبت شده در همین سایت', 'mobo_core_checkout_local_stock_check_enabled' ); ?>
							<div class="mobo-field mobo-field-full"><div class="mobo-help">این کنترل فقط اطلاعات محلی WooCommerce را می‌خواند: قابل خرید بودن محصول، وضعیت موجود/ناموجود و در صورت مدیریت موجودی، کافی بودن تعداد برای مقدار سبد. هیچ درخواست لحظه‌ای به موبو نمی‌فرستد. وقتی بررسی سبد موبو فعال و موثر باشد، کنترل محلی برای جلوگیری از خطای تکراری اجرا نمی‌شود و نتیجه لحظه‌ای موبو معیار است.</div></div>
						</div>
						<div data-mobo-ui-group="master-checkout">
							<?php $this->bool_field( 'اعتبارسنجی خارجی Checkout', 'mobo_core_checkout_external_validation_enabled' ); ?>
							<div class="mobo-field mobo-field-full"><div class="mobo-help">این قابلیت اختیاری، payload سبد خرید را با درخواست POST به URL تنظیم‌شده می‌فرستد. اگر URL متعلق به origin همان Portal provision‌شده باشد، credentialهای Connection طبق قرارداد امن همان origin اضافه می‌شوند؛ برای مقصد ثالث Token/X-SEC ارسال نمی‌شود.</div></div>
							<div data-mobo-ui-group="external-validation">
								<?php $this->url_field( 'URL سرویس اعتبارسنجی خارجی', 'mobo_core_checkout_external_validation_url', 'اگر خالی باشد، External Validation عملاً درخواستی ارسال نمی‌کند. برای سرویس ثالث فقط URL مورد اعتماد خودتان را وارد کنید.' ); ?>
								<?php $this->int_field( 'Timeout اعتبارسنجی خارجی / ثانیه', 'mobo_core_checkout_external_timeout_seconds', 1, 10 ); ?>
								<div class="mobo-field">
									<label for="mobo_core_checkout_external_error_behavior">اگر سرویس خارجی خطا داد</label>
									<select id="mobo_core_checkout_external_error_behavior" name="mobo_core_checkout_external_error_behavior">
										<option value="allow" <?php selected( (string) Mobo_Core_Settings::get( 'mobo_core_checkout_external_error_behavior', 'allow' ), 'allow' ); ?>>Checkout ادامه پیدا کند (Fail-Open)</option>
										<option value="block" <?php selected( (string) Mobo_Core_Settings::get( 'mobo_core_checkout_external_error_behavior', 'allow' ), 'block' ); ?>>Checkout متوقف شود (Fail-Closed)</option>
									</select>
									<div class="mobo-help">Fail-Closed فقط وقتی انتخاب شود که سرویس خارجی بخشی از مسیر حیاتی فروش شماست؛ قطعی شبکه یا پاسخ نامعتبر می‌تواند پرداخت مشتری را متوقف کند.</div>
								</div>
							</div>
						</div>
						<?php if ( $external_validation_enabled && '' === trim( (string) Mobo_Core_Settings::get( 'mobo_core_checkout_external_validation_url', '' ) ) ) : ?>
							<div class="mobo-field mobo-field-full"><div class="mobo-message mobo-message-warning">External Validation روشن است اما URL ذخیره‌شده خالی است؛ در حالت عادی هیچ درخواست خارجی ارسال نمی‌شود مگر اینکه توسعه‌دهنده URL را با filter کد تأمین کرده باشد.</div></div>
						<?php endif; ?>
						<div class="mobo-field mobo-field-full" data-mobo-ui-message="master-off"><div class="mobo-help">کنترل‌های اختیاری قبل از پرداخت خاموش است. ثبت خودکار سفارش، کنترل سخت‌گیرانه سبد موبو را در زمان ارسال خود سفارش انجام می‌دهد و Checkout مشتری را مجبور به استفاده از سبد مشترک موبو نمی‌کند.</div></div>
					</div>

					<div class="mobo-note">
						ترتیب تصمیم‌گیری روشن است: کنترل محلی فقط از داده همین فروشگاه استفاده می‌کند؛ کنترل سبد موبو یک بررسی اختیاری قبل از پرداخت است؛ و ثبت سفارش خودکار در زمان ارسال سفارش به موبو، مستقل از این گزینه، سبد موبو را پاک/بازسازی/تطبیق می‌دهد.
					</div>
				</div>

				<?php $this->render_mobo_shared_connection_box(); ?>

				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>بررسی سبد خرید موبو</h2>
						<p>اگر این گزینه فعال باشد، افزونه هنگام checkout کالاها را با portal_variant_id به سبد موبو اضافه می‌کند و snapshot نهایی را با update=true می‌خواند. این کنترل اختیاری است؛ ثبت سفارش خودکار کنترل سخت‌گیرانه خودش را فقط هنگام ارسال سفارش به موبو انجام می‌دهد.</p>
					</div>

					<div class="mobo-fields-grid">
						<div data-mobo-ui-group="master-checkout">
							<?php $this->bool_field( 'بررسی موجودی لحظه‌ای در موبو', 'mobo_core_checkout_mobo_cart_validation_enabled' ); ?>
							<div class="mobo-field mobo-field-full"><div class="mobo-help">این گزینه فقط کنترل لحظه‌ای قبل از پرداخت را فعال می‌کند. روشن بودن ثبت سفارش خودکار این گزینه را به‌صورت ضمنی فعال نمی‌کند؛ کنترل نهایی سبد در مسیر ارسال سفارش به موبو جداگانه و اجباری باقی می‌ماند.</div></div>
						</div>

						<div data-mobo-ui-group="mobo-cart-debug">
							<?php $this->bool_field( 'ثبت گزارش عیب‌یابی سبد موبو', 'mobo_core_checkout_mobo_debug_enabled' ); ?>
							<?php $this->int_field( 'حداکثر زمان انتظار برای بررسی سبد / ثانیه', 'mobo_core_checkout_mobo_cart_lock_wait_seconds', 0, 45 ); ?>
							<?php $this->int_field( 'مدت نگهداری نوبت بررسی سبد / ثانیه', 'mobo_core_checkout_mobo_cart_lock_ttl_seconds', 15, 300 ); ?>
						</div>

						<?php $this->bool_field( 'گزارش مشکل روش‌های ارسال ووکامرس', 'mobo_core_shipping_diagnostics_enabled' ); ?>
						<?php $this->bool_field( 'ثبت خودکار سفارش در موبو', 'mobo_core_mobo_order_submission_enabled' ); ?>


						<div data-mobo-ui-group="auto-order">
							<?php $this->bool_field( 'تکمیل خودکار سفارش‌های تماما موبو بعد از ثبت موفق', 'mobo_core_mobo_order_auto_complete_enabled' ); ?>
							<?php $this->bool_field( 'بررسی اختلاف قیمت قبل از خرید خودکار', 'mobo_core_source_price_guard_enabled', false, '', 'پیش از پرداخت، قیمت هر عدد در سبد Source با قیمت همگام‌شده مقایسه می‌شود و در صورت اختلاف سفارش به بررسی دستی می‌رود. فقط اگر قالب قیمت Source ناسازگار است و پشتیبانی موبو تأیید کرده، خاموش کنید.', '1' ); ?>
							<?php $this->text_field( 'نام فروشگاه یا فرستنده', 'mobo_core_mobo_order_sender_name', 'نامی که در اطلاعات سفارش موبو به عنوان فرستنده ثبت می‌شود.' ); ?>
							<?php $this->text_field( 'شماره موبایل فروشگاه یا فرستنده', 'mobo_core_mobo_order_sender_mobile', 'شماره موبایلی که در اطلاعات سفارش موبو ثبت می‌شود.' ); ?>
							<div class="mobo-field mobo-field-full"><div class="mobo-help">روش ارسال قابل مشاهده برای مشتری فقط با WooCommerce است. روش ارسال موبو در بخش پایین، بر اساس استان نگاشت‌شده و ساعت وردپرس برای ثبت سفارش اتوماتیک انتخاب می‌شود.</div></div>
						</div>

						<div class="mobo-field mobo-field-full" data-mobo-ui-message="mobo-login-off"><div class="mobo-help">تا وقتی «بررسی موجودی لحظه‌ای در موبو» یا «ثبت خودکار سفارش در موبو» روشن نباشد، اطلاعات اتصال به موبو لازم نیست.</div></div>
						<div class="mobo-field mobo-field-full" data-mobo-ui-message="auto-order-off"><div class="mobo-help">ثبت خودکار سفارش در موبو خاموش است؛ تنظیمات فرستنده، تکمیل خودکار سفارش و روش‌های ارسال موبو فعلا لازم نیستند.</div></div>
					</div>


					<div class="mobo-note">
						اگر «بررسی موجودی لحظه‌ای در موبو» یا «ثبت خودکار سفارش در موبو» روشن باشد، پلاگین از اطلاعات ورود موبو استفاده می‌کند. در حالت ثبت خودکار، ابتدا قابل افزودن بودن تمام آیتم‌ها به سبد موبو در checkout تایید می‌شود و سپس سفارش نهایی ووکامرس برای خرید خودکار در موبو وارد صف می‌شود.
					</div>
				</div>

			</div>

			<div class="mobo-card mobo-card-wide" data-mobo-ui-card="auto-order">
				<div class="mobo-card-head">
					<h2>Location Resolver مرکزی</h2>
					<p>ثبت سفارش اتوماتیک country/state/city ذخیره‌شده روی سفارش WooCommerce را به Resolver مرکزی Backend می‌فرستد. MoboCore دیگر فایل یا انتخابگر شهر برای Checkout تولید نمی‌کند.</p>
				</div>

				<div class="mobo-status-grid">
					<?php $this->status_box( 'Resolver مرکزی', ! empty( $order_readiness['resolverReady'] ) ? 'در دسترس' : 'در دسترس نیست' ); ?>
					<?php $this->status_box( 'مالکیت فیلدهای checkout', 'WooCommerce' ); ?>
					<?php $this->status_box( 'روش Resolve', 'مرکزی / Provider-level در Backend' ); ?>
					<?php $this->status_box( 'وابستگی به افزونه شهر', 'ندارد' ); ?>
				</div>

				<div class="mobo-note">
					فیلدهای آدرس WooCommerce دست‌نخورده باقی می‌مانند. در زمان ثبت خودکار سفارش، Mobo Core country/state/city ذخیره‌شده را به Resolver مرکزی می‌فرستد. موارد نامشخص برای بررسی مدیر در Backend متوقف می‌شوند؛ این عملیات به فایل شهر یا Mapping محلی Checkout وابسته نیست.
				</div>
			</div>

			<div class="mobo-card mobo-card-wide mobo-shipping-admin-card">
				<div class="mobo-card-head">
					<h2>نگاشت روش‌های ارسال WooCommerce به موبو</h2>
					<p>روش‌های ارسال موبو فقط به‌عنوان مقصد نگاشت ذخیره می‌شوند. Mobo Core هیچ نرخ یا روش ارسالی در Checkout ایجاد نمی‌کند.</p>
				</div>

				<div class="mobo-fields-grid">
					<?php $this->int_field( 'بازه بروزرسانی روش‌های ارسال از MoboCore / ساعت', 'mobo_core_remote_shipping_sync_interval_hours', 1, 168, 1 ); ?>
				</div>

				<div class="mobo-note">
					<strong>سیاست جدید حمل‌ونقل:</strong> Mobo Core هیچ Shipping Zone، Shipping Method، Package یا Rate در Checkout ایجاد یا تغییر نمی‌دهد. مشتری فقط روش‌های ارسال عادی خود فروشگاه را می‌بیند. این صفحه فقط همان روش انتخاب‌شده در WooCommerce را به <code>shipping_id</code> موبو نگاشت می‌کند تا هنگام ارسال سفارش به موبو استفاده شود.
				</div>

				<div class="mobo-status-grid">
					<?php $this->status_box( 'مالک Checkout و نرخ ارسال', 'فقط WooCommerce / افزونه حمل‌ونقل فروشگاه' ); ?>
					<?php $this->status_box( 'کاربرد روش‌های موبو', 'فقط Mapping هنگام Submit Order' ); ?>
					<?php $this->status_box( 'تعداد روش‌های ارسال cache شده', isset( $remote_shipping_status['count'] ) ? absint( $remote_shipping_status['count'] ) : 0 ); ?>
					<?php $this->status_box( 'آخرین بروزرسانی موفق', ! empty( $remote_shipping_status['lastSuccessAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $remote_shipping_status['lastSuccessAt'] ) ) : '—' ); ?>
					<?php $this->status_box( 'آخرین تغییر از MoboCore', ! empty( $remote_shipping_status['lastChangedAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $remote_shipping_status['lastChangedAt'] ) ) : '—' ); ?>
					<?php $this->status_box( 'آخرین خطا', ! empty( $remote_shipping_status['lastError'] ) ? $remote_shipping_status['lastError'] : '—' ); ?>
				</div>



				<?php $this->render_mobo_shipping_method_catalog( $remote_shipping_methods ); ?>

				<?php
				$this->guide_box(
					'راهنمای نگاشت روش ارسال فروشگاه به موبو',
					array(
						array( 'title' => 'روش‌های فروشگاه', 'text' => 'تمام Zoneها و روش‌های قابل مشاهده برای مشتری را فقط در WooCommerce یا افزونه حمل‌ونقل خودتان مدیریت کنید.' ),
						array( 'title' => 'نگاشت', 'text' => 'برای هر روش فعال WooCommerce یک روش موبو انتخاب کنید. این انتخاب در Checkout نمایش داده نمی‌شود.' ),
						array( 'title' => 'ثبت سفارش موبو', 'text' => 'وقتی وضعیت سفارش شرایط ارسال به موبو را پیدا کند، افزونه Shipping Method ذخیره‌شده روی همان سفارش WooCommerce را می‌خواند و shipping_id نگاشت‌شده را در درخواست موبو قرار می‌دهد.' ),
					),
					'این نگاشت فقط برای سفارش تماماً موبو با یک Connection استفاده می‌شود. سفارش ترکیبی یا Multi-Connection هیچ بخشی را به موبو ارسال نمی‌کند.'
				);
				?>

				<div data-mobo-ui-group="auto-order">
					<?php $this->render_mobo_shipping_rules( $remote_shipping_methods ); ?>
				</div>
				<div class="mobo-note" data-mobo-ui-message="auto-order-off">ثبت خودکار سفارش در موبو خاموش است؛ این تنظیمات فقط زمانی استفاده می‌شود که سفارش موبو به صورت خودکار ساخته شود. روش ارسال قابل مشاهده در checkout همیشه با WooCommerce است.</div>
			</div>

			<?php
			$this->guide_box(
				'راهنمای اعتبارسنجی خرید و ثبت سفارش موبو',
				array(
					array( 'title' => 'کنترل‌های قبل از پرداخت', 'text' => 'این کلید فقط کنترل‌های اختیاری زیرمجموعه را فعال می‌کند و به تنهایی موجودی را بررسی نمی‌کند. اگر ثبت سفارش خودکار روشن باشد، بررسی واقعی سبد موبو مستقل از این کلید اجباری است.' ),
					array( 'title' => 'بررسی موجودی همین سایت', 'text' => 'از داده محلی WooCommerce استفاده می‌کند: is_purchasable، is_in_stock و در محصولات دارای مدیریت موجودی، کافی بودن تعداد سبد. این کنترل به موبو درخواست نمی‌فرستد و هنگام فعال بودن کنترل سبد موبو اجرا نمی‌شود.' ),
					array( 'title' => 'اعتبارسنجی خارجی', 'text' => 'در صورت فعال‌سازی، payload سبد به URL تنظیم‌شده POST می‌شود. مقصد ثالث credentialهای Portal را دریافت نمی‌کند. رفتار خطای سرویس صریحاً Fail-Open یا Fail-Closed انتخاب می‌شود.' ),
					array( 'title' => 'بررسی سبد موبو', 'text' => 'اگر فعال شود، افزونه فقط هنگام submit checkout سبد مشترک موبو را lock، پاکسازی، بازسازی و با سبد ووکامرس مقایسه می‌کند. پیش‌فرض این گزینه نیز غیرفعال است.' ),
					array( 'title' => 'گزارش عیب‌یابی سبد موبو', 'text' => 'فقط برای زمانی است که می‌خواهید دلیل خطای بررسی سبد موبو را ببینید. در حالت عادی خاموش بماند.' ),
					array( 'title' => 'ثبت سفارش خودکار', 'text' => 'اگر فعال باشد، فقط سفارش تماماً موبو با مالکیت یک Connection پس از ورود به وضعیت مجاز وارد صف ثبت موبو می‌شود. آدرس native سفارش با Resolver مرکزی تبدیل می‌شود و روش ارسالی که مشتری می‌بیند همچنان متعلق به WooCommerce است.' ),
					array( 'title' => 'نوع سفارش', 'text' => 'فقط سفارش تماماً موبو با دقیقاً یک Connection قابل ارسال است. سفارش ترکیبی، غیرموبو یا Multi-Connection به‌صورت Fail-Closed از ثبت موبو خارج می‌شود و هیچ بخش جزئی از آن ارسال نمی‌شود.' ),
				),
				'قبل از استفاده روی سایت اصلی، Connection، credentialهای موبو، اطلاعات فرستنده، Resolver مرکزی و نگاشت روش‌های ارسال را با یک سفارش تماماً موبویی آزمایش کنید.'
			);

			$this->guide_box(
				'راهنمای سازگاری افزونه‌های جانبی',
				array(
					array( 'title' => 'poina-domain-allowlist', 'text' => 'اگر این افزونه روی سایت نصب است و خروجی‌های HTTP را محدود می‌کند، دامنه Backend و Source provision‌شده باید به allowlist اضافه شوند. در غیر این صورت ارتباط با MoboCore، ورود به موبو، بررسی سبد موبو یا ثبت سفارش خودکار ممکن است fail شود.' ),
					array( 'title' => 'آدرس استاندارد WooCommerce', 'text' => 'MoboCore برای شهرها JavaScript یا selector اختصاصی ووکامرس فارسی ندارد. فقط مقادیر native آدرس سفارش به Resolver مرکزی Backend برای خرید خودکار فرستاده می‌شوند.' ),
					array( 'title' => 'مالکیت فیلدهای آدرس', 'text' => 'فیلدهای آدرس همیشه در اختیار WooCommerce/افزونه آدرس فروشگاه می‌مانند. Mobo Core در زمان ثبت سفارش country/state/city ذخیره‌شده را به Resolver مرکزی می‌فرستد و مالک Checkout نمی‌شود.' ),
				),
				'اگر افزونه دیگری فیلدهای Checkout را تغییر می‌دهد، یک سفارش تست با country/state/city واقعی ثبت کنید و نتیجه Resolver مرکزی را در مسیر ثبت سفارش بررسی کنید.'
			);

			$this->guide_box(
				'راهنمای نمایش ندادن گزینه های حمل و نقل ووکامرس',
				array(
					array( 'title' => 'اول تنظیمات ارسال ووکامرس را بررسی کنید', 'text' => 'اگر در checkout پیام «هیچ گزینه ای ارسال در دسترس نیست» نمایش داده شد، همیشه مشکل از موبو نیست. در بعضی سایت ها وقتی محدوده فروش یا ارسال روی حالت خیلی باز مثل «فروش به همه جا / همه کشورها» باشد، WooCommerce یا افزونه های حمل و نقل ایرانی مقصد را درست match نمی کنند.' ),
					array( 'title' => 'کشورهای قابل ارسال را مشخص کنید', 'text' => 'در تنظیمات ووکامرس، بخش کشورهایی که به آنها ارسال می کنید را خالی یا روی حالت عمومی رها نکنید. برای فروشگاه ایرانی معمولا آن را روی «ایران» بگذارید. اگر ساختار فروشگاه نیاز دارد، می توانید محدوده را روی «آسیا» تنظیم کنید، اما مقدار باید مشخص و کنترل شده باشد.' ),
					array( 'title' => 'بعد از تغییر، محاسبه ارسال را دوباره بسازید', 'text' => 'بعد از اصلاح کشور مقصد ارسال، customer sessions و transients ووکامرس را پاک کنید و سبد خرید را دوباره تست بگیرید. اگر cache نرخ ارسال قبلی باقی مانده باشد، checkout ممکن است همچنان پیام قبلی را نشان دهد.' ),
				),
				'مسیر پیشنهادی بررسی: WooCommerce > پیکربندی > همگانی، سپس بخش «فروش به» و «ارسال به». برای جلوگیری از خطای checkout، مقدار ارسال را مشخص کنید؛ برای فروشگاه ایران، بهترین حالت معمولا «ارسال فقط به ایران» است.'
			);
			?>

			<?php
			$this->recommendation_box(
				'تنظیمات پیشنهادی اعتبارسنجی خرید',
				array(
					array( 'setting' => 'فعال بودن کنترل‌های قبل از پرداخت', 'value' => 'غیرفعال به عنوان پیش‌فرض', 'reason' => 'تا وقتی sync کامل، GUIDها و checkout تست نشده‌اند، نباید خرید مشتری مسدود شود.' ),
					array( 'setting' => 'بررسی موجودی همین سایت', 'value' => 'روشن فقط وقتی موجودی WooCommerce به‌روز و قابل اعتماد است', 'reason' => 'این گزینه فقط داده محلی را بررسی می‌کند. اگر کنترل لحظه‌ای موبو فعال باشد، افزونه کنترل محلی را خودکار کنار می‌گذارد تا دو منبع همزمان مشتری را مسدود نکنند.' ),
					array( 'setting' => 'بررسی سبد موبو', 'value' => 'غیرفعال به عنوان پیش‌فرض', 'reason' => 'سبد موبو مشترک است و فقط بعد از اطمینان از lock و portal_variant_id باید فعال شود.' ),
					array( 'setting' => 'لاگ حرفه‌ای سبد موبو', 'value' => 'غیرفعال به عنوان پیش‌فرض', 'reason' => 'برای debug روشن شود و بعد از رفع مشکل خاموش بماند تا حجم option/log بالا نرود.' ),
				),
				'پیشنهاد عملی: پس از کامل شدن وضعیت «پیکربندی آماده»، ثبت واقعی را ابتدا با یک سفارش کاملاً موبویی و یک Connection در محیط تست بررسی کنید؛ سپس در صورت نیاز کنترل‌های اختیاری Checkout را جداگانه فعال کنید.'
			);
			?>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>دیباگ حمل و نقل ووکامرس</h2>
					<p>این بخش فقط گزارش می‌گیرد و هیچ مقداری را در checkout یا shipping تغییر نمی‌دهد. بعد از مشاهده پیام «هیچ گزینه ارسال در دسترس نیست»، اینجا آخرین destination، zone و rateهای محاسبه‌شده نمایش داده می‌شود.</p>
				</div>

				<?php if ( empty( $shipping_report ) ) : ?>
					<div class="mobo-note">هنوز گزارشی ثبت نشده است. یک بار checkout را باز کنید، کشور/استان/شهر را انتخاب کنید و بعد این صفحه را refresh کنید.</div>
				<?php else : ?>
					<div class="mobo-status-grid">
						<?php $this->status_box( 'آخرین رویداد', isset( $shipping_report['event'] ) ? $shipping_report['event'] : '—' ); ?>
						<?php $this->status_box( 'زمان ثبت', ! empty( $shipping_report['capturedat'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $shipping_report['capturedat'] ) ) : '—' ); ?>
						<?php $customer_report = isset( $shipping_report['customer'] ) && is_array( $shipping_report['customer'] ) ? $shipping_report['customer'] : array(); ?>
						<?php $this->status_box( 'کشور ارسال', isset( $customer_report['shipping_country'] ) ? $customer_report['shipping_country'] : '—' ); ?>
						<?php $this->status_box( 'استان ارسال', isset( $customer_report['shipping_state'] ) ? $customer_report['shipping_state'] : '—' ); ?>
						<?php $this->status_box( 'شهر ارسال', isset( $customer_report['shipping_city'] ) ? $customer_report['shipping_city'] : '—' ); ?>
						<?php $cart_report = isset( $shipping_report['cart'] ) && is_array( $shipping_report['cart'] ) ? $shipping_report['cart'] : array(); ?>
						<?php $this->status_box( 'سبد نیازمند ارسال', isset( $cart_report['needsshipping'] ) ? ( $cart_report['needsshipping'] ? 'بله' : 'خیر' ) : '—' ); ?>
					</div>

					<details style="margin-top:12px;">
						<summary style="cursor:pointer;">نمایش جزئیات فنی گزارش ارسال</summary>
						<pre style="direction:ltr;text-align:left;white-space:pre-wrap;max-height:420px;overflow:auto;background:#111827;color:#e5e7eb;padding:14px;border-radius:10px;"><?php echo esc_html( wp_json_encode( $shipping_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
					</details>
				<?php endif; ?>
			</div>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>وضعیت کنترل‌های Checkout و سبد موبو</h2>
					<p>نتیجه تاریخی زیر فقط به بررسی سبد موبو مربوط است؛ Local Stock و External Validation تاریخچه مشترک این فیلد را ندارند.</p>
				</div>

				<div class="mobo-status-grid">
					<?php $this->status_box( 'کنترل‌های قبل از پرداخت', ! empty( $status['enabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
					<?php $this->status_box( 'هر قابلیت Checkout وابسته به موبو', ! empty( $status['runtimeEnabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
					<?php $this->status_box( 'بررسی محلی موجودی', ! empty( $status['localStockEnabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
					<?php $this->status_box( 'بررسی سبد موبو', ! empty( $status['moboCartEnabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
					<?php $this->status_box( 'اعتبارسنجی خارجی', ! empty( $status['external'] ) ? 'فعال' : 'غیرفعال' ); ?>
					<?php $this->status_box( 'آخرین موفقیت بررسی سبد موبو', ! empty( $status['lastSuccessAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $status['lastSuccessAt'] ) ) : '—' ); ?>
					<?php $this->status_box( 'آخرین ورود موفق موبو', ! empty( $status['lastMoboLoginAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $status['lastMoboLoginAt'] ) ) : '—' ); ?>
					<?php $this->status_box( 'آخرین تست ورود', Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_login_test_at' ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_login_test_at' ) ) ) : '—' ); ?>
					<?php $this->status_box( 'نتیجه تست ورود', Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_login_test_result', '—' ) ); ?>
					<?php $this->status_box( 'آخرین Cart موفق موبو', ! empty( $status['lastMoboCartAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $status['lastMoboCartAt'] ) ) : '—' ); ?>
					<?php $lock_disabled = empty( $status['moboCartEnabled'] ) && empty( $status['autoOrderEnabled'] ); ?>
					<?php $shared_cart_lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_status( 'shared_mobo_cart' ) : array(); ?>
					<?php $shared_cart_locked = is_array( $shared_cart_lock ) && ! empty( $shared_cart_lock['active'] ); ?>
					<?php $this->status_box( 'وضعیت lock سبد موبو', $lock_disabled ? 'غیرفعال - بررسی سبد و ثبت سفارش خاموش است' : ( $shared_cart_locked ? 'فعال / در حال استفاده' : 'آزاد' ) ); ?>
					<?php $this->status_box( 'ثبت سفارش خودکار موبو', Mobo_Core_Order_Submission_Policy::is_enabled() ? 'فعال' : 'غیرفعال' ); ?>
					<?php $this->status_box( 'آخرین نتیجه بررسی سبد موبو', isset( $last['success'] ) ? ( ! empty( $last['success'] ) ? 'موفق' : 'ناموفق' ) : '—' ); ?>
					<?php $this->status_box( 'HTTP بررسی سبد موبو', isset( $last['status'] ) ? absint( $last['status'] ) : '—' ); ?>
				</div>
			</div>

			<?php $this->render_mobo_cart_debug_log( $debug_log ); ?>

			<script>
			(function() {
				var form = document.querySelector('.mobo-settings-form');
				if (!form) { return; }

				function valueOf(name) {
					var checked = form.querySelector('input[name="' + name + '"]:checked');
					if (checked) { return String(checked.value); }
					var el = form.querySelector('[name="' + name + '"]') || form.querySelector('#' + name);
					return el ? String(el.value) : '0';
				}

				function setVisible(selector, visible) {
					form.querySelectorAll(selector).forEach(function(node) {
						node.style.display = visible ? '' : 'none';
						node.querySelectorAll('input, select, textarea, button').forEach(function(input) {
							input.disabled = !visible;
						});
					});
				}

				function updateMoboConnectionRequired(required) {
					form.querySelectorAll('[data-mobo-connection-required="1"]').forEach(function(input) {
						var isPassword = input.id === 'mobo_core_checkout_mobo_password';
						var hasSecret = input.getAttribute('data-has-secret') === '1';
						input.required = !!required && (!isPassword || !hasSecret);
					});
				}

				function refreshMoboCheckoutUi() {
					var master = valueOf('mobo_core_checkout_validation_enabled') === '1';
					var autoOrder = valueOf('mobo_core_mobo_order_submission_enabled') === '1';
					var moboCart = master && valueOf('mobo_core_checkout_mobo_cart_validation_enabled') === '1';
					var externalValidation = master && valueOf('mobo_core_checkout_external_validation_enabled') === '1';
					var needsMoboLogin = moboCart || autoOrder;

					setVisible('[data-mobo-ui-group="master-checkout"]', master);
					setVisible('[data-mobo-ui-message="master-off"]', !master);
					setVisible('[data-mobo-ui-group="mobo-cart-debug"]', moboCart);
					setVisible('[data-mobo-ui-group="external-validation"]', externalValidation);
					setVisible('[data-mobo-ui-card="mobo-connection"]', needsMoboLogin);
					setVisible('[data-mobo-ui-group="mobo-login"]', needsMoboLogin);
					setVisible('[data-mobo-ui-message="mobo-login-off"]', !needsMoboLogin);
					setVisible('[data-mobo-ui-group="auto-order"]', autoOrder);
					setVisible('[data-mobo-ui-card="auto-order"]', autoOrder);
					setVisible('[data-mobo-ui-message="auto-order-off"]', !autoOrder);
					updateMoboConnectionRequired(needsMoboLogin);
				}

				['mobo_core_checkout_validation_enabled', 'mobo_core_checkout_mobo_cart_validation_enabled', 'mobo_core_checkout_external_validation_enabled', 'mobo_core_mobo_order_submission_enabled'].forEach(function(name) {
					form.querySelectorAll('[name="' + name + '"]').forEach(function(el) {
						el.addEventListener('change', refreshMoboCheckoutUi);
					});
				});

				if (window.jQuery) {
					window.jQuery(form).on('change', '[name="mobo_core_checkout_validation_enabled"], [name="mobo_core_checkout_mobo_cart_validation_enabled"], [name="mobo_core_checkout_external_validation_enabled"], [name="mobo_core_mobo_order_submission_enabled"]', refreshMoboCheckoutUi);
				}

				refreshMoboCheckoutUi();
			})();
			</script>

			<?php $this->render_checkout_support_tools_box(); ?>

			<?php $this->save_button(); ?>
		</form>
		<?php
	}


	/**
	 * Render the standalone three-step shipping wizard after the settings form.
	 *
	 * @param array $methods Remote Mobo methods.
	 * @param array $status Wizard status.
	 * @return void
	 */
	private function render_shipping_wizard_form( $methods, $status ) {
		unset( $methods, $status );
		// Deprecated since 10.33.0. Shipping configuration belongs to WooCommerce.
	}

	/**
	 * Render Mobo cart debug log in a session-oriented table.
	 *
	 * @param array $debug_log Debug log entries.
	 * @return void
	 */
	private function render_mobo_cart_debug_log( $debug_log ) {
		$debug_log = is_array( $debug_log ) ? $debug_log : array();
		$sessions  = array();
		$last_time = 0;
		$last_err  = '—';

		foreach ( $debug_log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$session = isset( $entry['session'] ) ? sanitize_text_field( (string) $entry['session'] ) : 'unknown';
			$action  = isset( $entry['action'] ) ? sanitize_key( (string) $entry['action'] ) : 'unknown';
			$time    = isset( $entry['time'] ) ? absint( $entry['time'] ) : 0;
			$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();

			if ( ! isset( $sessions[ $session ] ) ) {
				$sessions[ $session ] = array(
					'count'       => 0,
					'first'       => $time,
					'last'        => $time,
					'actions'     => array(),
					'variants'    => array(),
					'cartItems'   => array(),
					'cartCookie'  => '',
					'userCookie'  => '',
					'entries'     => array(),
				);
			}

			$sessions[ $session ]['count']++;
			$sessions[ $session ]['first'] = $sessions[ $session ]['first'] ? min( $sessions[ $session ]['first'], $time ) : $time;
			$sessions[ $session ]['last']  = max( $sessions[ $session ]['last'], $time );
			$sessions[ $session ]['actions'][ $action ] = isset( $sessions[ $session ]['actions'][ $action ] ) ? $sessions[ $session ]['actions'][ $action ] + 1 : 1;

			$variant = $this->debug_context_value( $context, array( 'portalVariantId', 'variantid', 'variant_id' ) );
			if ( '' !== $variant ) {
				$sessions[ $session ]['variants'][ $variant ] = true;
			}

			$cart_item = $this->debug_context_value( $context, array( 'cartItemId', 'cartitemid', 'cart_item_id' ) );
			if ( '' !== $cart_item ) {
				$sessions[ $session ]['cartItems'][ $cart_item ] = true;
			}

			$cookie_summary = $this->debug_cookie_summary( $context );
			if ( '' !== $cookie_summary['cart'] ) {
				$sessions[ $session ]['cartCookie'] = $cookie_summary['cart'];
			}
			if ( '' !== $cookie_summary['userauth'] ) {
				$sessions[ $session ]['userCookie'] = $cookie_summary['userauth'];
			}

			if ( $time > $last_time ) {
				$last_time = $time;
			}

			$error = $this->debug_context_value( $context, array( 'error' ) );
			if ( '' !== $error ) {
				$last_err = $error;
			}

			$sessions[ $session ]['entries'][] = $entry;
		}

		uasort( $sessions, function( $a, $b ) {
			return (int) $b['last'] <=> (int) $a['last'];
		} );
		?>
		<div class="mobo-card mobo-card-wide">
			<div class="mobo-card-head">
				<h2>لاگ حرفه‌ای سبد موبو</h2>
				<p>برای تست چند کاربر همزمان، هر WooCommerce session جدا نمایش داده می‌شود. cookie واقعی ذخیره نمی‌شود؛ فقط hash کوتاه آن نشان داده می‌شود.</p>
			</div>

			<div class="mobo-status-grid">
				<?php $this->status_box( 'تعداد رخدادها', count( $debug_log ) ); ?>
				<?php $this->status_box( 'تعداد Sessionها', count( $sessions ) ); ?>
				<?php $this->status_box( 'آخرین رخداد', $last_time ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', $last_time ) : '—' ); ?>
				<?php $this->status_box( 'آخرین خطا', $last_err ); ?>
			</div>

			<?php if ( empty( $debug_log ) ) : ?>
				<div class="mobo-note">هنوز لاگی ثبت نشده است. لاگ را فعال کن، سپس با دو مرورگر یا incognito عملیات add/update/delete انجام بده.</div>
			<?php else : ?>
				<div class="mobo-note">در تست دو کاربر، باید حداقل دو مقدار متفاوت در ستون <code>Session</code> و ترجیحاً دو hash متفاوت برای cookie <code>cart</code> ببینی. اگر Session متفاوت است ولی cart hash یکی است، یعنی موبو سمت خودش cart را برای همان userauth مشترک نگه می‌دارد.</div>

				<?php foreach ( array_slice( $sessions, 0, 20, true ) as $session_id => $session ) : ?>
					<details class="mobo-note" open>
						<summary>
							<strong dir="ltr">Session <?php echo esc_html( $session_id ); ?></strong>
							— رخداد: <?php echo esc_html( (string) $session['count'] ); ?>
							— آخرین: <?php echo esc_html( $session['last'] ? Mobo_Core_Iran_Date::format( 'H:i:s', $session['last'] ) : '—' ); ?>
							— cart cookie: <code dir="ltr"><?php echo esc_html( '' !== $session['cartCookie'] ? $session['cartCookie'] : '—' ); ?></code>
						</summary>

						<div style="margin:8px 0;">
							<strong>Actionها:</strong> <code dir="ltr"><?php echo esc_html( wp_json_encode( $session['actions'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></code><br>
							<strong>Variantها:</strong> <code dir="ltr"><?php echo esc_html( implode( ', ', array_keys( $session['variants'] ) ) ?: '—' ); ?></code><br>
							<strong>Cart item idها:</strong> <code dir="ltr"><?php echo esc_html( implode( ', ', array_keys( $session['cartItems'] ) ) ?: '—' ); ?></code>
						</div>

						<div style="overflow:auto; max-height:520px; border:1px solid #e5e7eb; background:#fff;">
							<table class="widefat striped" style="min-width:1350px;">
								<thead>
									<tr>
										<th>زمان</th>
										<th>عملیات</th>
										<th>تنوع</th>
										<th>تعداد</th>
										<th>آیتم سبد</th>
										<th>HTTP</th>
										<th>متد</th>
										<th>مسیر</th>
										<th>کوکی سبد</th>
										<th>احراز هویت کاربر</th>
										<th>نگاشت</th>
										<th>خطا / توضیح</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( array_slice( $session['entries'], 0, 80 ) as $entry ) : ?>
										<?php
										$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
										$cookies = $this->debug_cookie_summary( $context );
										?>
										<tr>
											<td dir="ltr"><?php echo esc_html( ! empty( $entry['time'] ) ? Mobo_Core_Iran_Date::format( 'H:i:s', absint( $entry['time'] ) ) : '—' ); ?></td>
											<td><code dir="ltr"><?php echo esc_html( isset( $entry['action'] ) ? (string) $entry['action'] : '—' ); ?></code></td>
											<td dir="ltr"><?php echo esc_html( $this->debug_context_value( $context, array( 'portalVariantId', 'variantid', 'variant_id' ) ) ?: '—' ); ?></td>
											<td dir="ltr"><?php echo esc_html( $this->debug_context_value( $context, array( 'quantity' ) ) ?: '—' ); ?></td>
											<td dir="ltr"><?php echo esc_html( $this->debug_context_value( $context, array( 'cartItemId', 'cartitemid', 'cart_item_id' ) ) ?: '—' ); ?></td>
											<td dir="ltr"><?php echo esc_html( $this->debug_context_value( $context, array( 'httpStatus', 'httpstatus', 'status' ) ) ?: '—' ); ?></td>
											<td dir="ltr"><?php echo esc_html( $this->debug_context_value( $context, array( 'method' ) ) ?: '—' ); ?></td>
											<td dir="ltr" style="max-width:260px; white-space:normal;"><?php echo esc_html( $this->debug_context_value( $context, array( 'path' ) ) ?: '—' ); ?></td>
											<td dir="ltr"><code><?php echo esc_html( $cookies['cart'] ?: '—' ); ?></code></td>
											<td dir="ltr"><code><?php echo esc_html( $cookies['userauth'] ?: '—' ); ?></code></td>
											<td dir="ltr" style="max-width:220px; white-space:normal;"><code><?php echo esc_html( $this->debug_compact_value( isset( $context['map'] ) ? $context['map'] : '' ) ); ?></code></td>
											<td style="max-width:300px; white-space:normal;"><?php echo esc_html( $this->debug_context_value( $context, array( 'error', 'message', 'note' ) ) ?: $this->debug_compact_value( $context ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</details>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Return a scalar value from debug context by trying multiple possible keys.
	 *
	 * @param array $context Context.
	 * @param array $keys Candidate keys.
	 * @return string
	 */
	private function debug_context_value( $context, $keys ) {
		if ( ! is_array( $context ) || ! is_array( $keys ) ) {
			return '';
		}

		foreach ( $keys as $key ) {
			if ( isset( $context[ $key ] ) && ! is_array( $context[ $key ] ) && null !== $context[ $key ] ) {
				return sanitize_text_field( (string) $context[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Return masked Mobo cookie hashes from context.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	private function debug_cookie_summary( $context ) {
		$result = array( 'cart' => '', 'userauth' => '' );

		if ( ! is_array( $context ) || empty( $context['cookieJar'] ) || ! is_array( $context['cookieJar'] ) ) {
			return $result;
		}

		foreach ( array( 'cart', 'userauth' ) as $name ) {
			if ( isset( $context['cookieJar'][ $name ] ) && is_array( $context['cookieJar'][ $name ] ) && ! empty( $context['cookieJar'][ $name ]['hash'] ) ) {
				$result[ $name ] = sanitize_text_field( (string) $context['cookieJar'][ $name ]['hash'] );
			}
		}

		return $result;
	}

	/**
	 * Compact debug context for table display.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function debug_compact_value( $value ) {
		if ( '' === $value || null === $value ) {
			return '—';
		}

		if ( is_array( $value ) ) {
			$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$value = false !== $json ? $json : '';
		}

		$value = sanitize_text_field( (string) $value );

		if ( strlen( $value ) > 260 ) {
			$value = substr( $value, 0, 260 ) . '...';
		}

		return $value;
	}



	/**
	 * Render configuration for support/admin tools.
	 *
	 * Tool buttons are intentionally submitted through JavaScript-created forms so they
	 * do not submit or save the settings form around them.
	 *
	 * @param array<int,array<string,string>> $tools Tool definitions.
	 * @return void
	 */
	private function render_admin_tool_forms( $tools ) {
		unset( $tools );
		?>
		<span class="mobo-admin-tool-config" data-mobo-admin-tool-action-url="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-admin-tool-nonce="<?php echo esc_attr( wp_create_nonce( 'mobo_core_admin_tool' ) ); ?>" style="display:none;"></span>
		<?php
	}

	/**
	 * Render a support/admin tool button without saving the current settings form.
	 *
	 * @param string $action Admin-post action.
	 * @param string $label  Button label.
	 * @param string $class  Button CSS class.
	 * @param string $confirm Optional confirmation text.
	 * @return void
	 */
	private function admin_tool_button( $action, $label, $class = 'button button-secondary', $confirm = '' ) {
		$tab = 'mobo_core_tool_run_cron_now' === $action ? 'cron' : 'checkout';
		?>
		<button type="button" class="<?php echo esc_attr( $class ); ?>" data-mobo-admin-tool-action="<?php echo esc_attr( $action ); ?>" data-mobo-admin-tool-tab="<?php echo esc_attr( $tab ); ?>" data-mobo-admin-tool-confirm="<?php echo esc_attr( $confirm ); ?>"><?php echo esc_html( $label ); ?></button>
		<?php
	}

	/**
	 * Render checkout support tools separated from the settings submit action.
	 *
	 * @return void
	 */
	private function render_checkout_support_tools_box() {
		$tools = array(
			array( 'action' => 'mobo_core_tool_test_mobo_login', 'tab' => 'checkout' ),
			array( 'action' => 'mobo_core_tool_sync_address_mapping', 'tab' => 'checkout' ),
			array( 'action' => 'mobo_core_tool_sync_remote_shipping_methods', 'tab' => 'checkout' ),
			array( 'action' => 'mobo_core_tool_clear_mobo_debug_log', 'tab' => 'checkout' ),
			array( 'action' => 'mobo_core_tool_clear_shipping_diagnostics', 'tab' => 'checkout' ),
		);
		$this->render_admin_tool_forms( $tools );
		?>
		<div class="mobo-card mobo-card-wide mobo-support-tools-card">
			<details>
				<summary>ابزارهای دستی و پشتیبانی checkout</summary>
				<div class="mobo-support-tools-body">
					<div class="mobo-help">این دکمه‌ها تنظیمات فرم را ذخیره نمی‌کنند. اگر تغییری در تنظیمات داده‌اید، ابتدا دکمه «ذخیره تنظیمات» همین تب را بزنید.</div>
					<div class="mobo-support-tools-actions">
						<span data-mobo-ui-group="mobo-login"><?php $this->admin_tool_button( 'mobo_core_tool_test_mobo_login', 'تست اتصال به موبو' ); ?></span>
						<?php $this->admin_tool_button( 'mobo_core_tool_sync_address_mapping', 'بروزرسانی آدرس موبو و ساخت فایل شهرهای Checkout' ); ?>
						<?php $this->admin_tool_button( 'mobo_core_tool_sync_remote_shipping_methods', 'بروزرسانی روش‌های ارسال از MoboCore' ); ?>
						<span data-mobo-ui-group="mobo-cart-debug"><?php $this->admin_tool_button( 'mobo_core_tool_clear_mobo_debug_log', 'پاک کردن گزارش سبد موبو', 'button button-secondary', 'گزارش‌های بررسی سبد موبو پاک شود؟' ); ?></span>
						<?php $this->admin_tool_button( 'mobo_core_tool_clear_shipping_diagnostics', 'پاک کردن گزارش ارسال', 'button button-secondary', 'گزارش مشکل ارسال پاک شود؟' ); ?>
					</div>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Render cron support tools separated from the settings submit action.
	 *
	 * @return void
	 */
	private function render_cron_support_tools_box() {
		$tools = array(
			array( 'action' => 'mobo_core_tool_run_cron_now', 'tab' => 'cron' ),
		);
		$this->render_admin_tool_forms( $tools );
		?>
		<div class="mobo-support-tools-inline">
			<details>
				<summary>ابزار اجرای دستی Worker</summary>
				<div class="mobo-support-tools-body">
					<div class="mobo-help"><strong>این عملیات Diagnostic-only نیست.</strong> یک slice واقعی از همان Runner اجرا می‌شود و می‌تواند Webhook/Product/Variation/Image/Repair و سایر صف‌های آماده را تغییر دهد. اجرای دستی، سالم بودن Cron Job هاست را اثبات نمی‌کند. اگر تنظیمات را تغییر داده‌اید، ابتدا ذخیره کنید.</div>
					<div class="mobo-support-tools-actions">
						<?php $this->admin_tool_button( 'mobo_core_tool_run_cron_now', 'اجرای دستی یک Slice واقعی Worker', 'button button-secondary', 'یک Slice واقعی Worker اجرا شود؟ این عملیات می‌تواند صف‌های واقعی MoboCore را پردازش و داده‌های فروشگاه را تغییر دهد.' ); ?>
					</div>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Render Mobo wallet balance / low-balance reminder state on the dashboard.
	 *
	 * @return void
	 */
	private function render_wallet_alert_dashboard_card() {
		$status = class_exists( 'Mobo_Core_Wallet_Alert' ) ? Mobo_Core_Wallet_Alert::get_status() : array();
		$supported = ! empty( $status['supported'] );
		$runtime_ready = ! empty( $status['runtimeReady'] );
		$enabled = ! empty( $status['enabled'] );
		$notified = ! empty( $status['notified'] );
		$uncertain = ! empty( $status['uncertain'] );
		$has_balance = ! empty( $status['hasBalance'] );
		$last_balance = $has_balance ? number_format_i18n( (float) $status['lastBalance'], 0 ) : '—';
		$threshold = isset( $status['threshold'] ) ? number_format_i18n( (float) $status['threshold'], 0 ) : '0';
		$last_checked = ! empty( $status['lastCheckedAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $status['lastCheckedAt'] ) ) : 'هنوز بررسی نشده';
		$notified_at = ! empty( $status['notifiedAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $status['notifiedAt'] ) ) : '—';
		$sms_last_attempt = ! empty( $status['smsLastAttemptAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $status['smsLastAttemptAt'] ) ) : '—';
		$sms_last_success = ! empty( $status['smsLastSuccessAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $status['smsLastSuccessAt'] ) ) : '—';
		$sms_last_result  = isset( $status['smsLastResult'] ) ? sanitize_key( (string) $status['smsLastResult'] ) : '';
		$sms_result_label = 'sent' === $sms_last_result ? 'پذیرفته‌شده توسط درگاه' : ( 'uncertain' === $sms_last_result ? 'نامطمئن؛ replay متوقف' : ( 'failed' === $sms_last_result ? 'ناموفق' : ( 'attempting' === $sms_last_result ? 'در حال تلاش' : ( 'rearmed' === $sms_last_result ? 'توسط مدیر دوباره فعال شد' : 'هنوز ارسال زنده‌ای ثبت نشده' ) ) ) );
		?>
		<div class="mobo-card mobo-card-wide">
			<div class="mobo-card-head">
				<h2>موجودی کیف پول موبو</h2>
				<p>بعد از هر ثبت و پرداخت موفق سفارش موبو، موجودی حساب موبو بررسی و ذخیره می‌شود. هشدار کمبود موجودی فقط یک بار ارسال می‌شود تا زمانی که مدیر آن را دوباره فعال کند.</p>
			</div>

			<div class="mobo-status-grid">
				<?php $this->status_box( 'آخرین موجودی', $last_balance ); ?>
				<?php $this->status_box( 'حد هشدار', $threshold ); ?>
				<?php $this->status_box( 'آخرین بررسی', $last_checked ); ?>
				<?php $this->status_box( 'هشدار پیامکی', ! $supported ? 'Provider پشتیبانی نمی‌کند' : ( ! $runtime_ready ? 'Connection برای runtime آماده نیست' : ( $enabled ? ( $uncertain ? 'نامطمئن؛ ارسال خودکار متوقف' : ( $notified ? 'اطلاع داده شده' : 'آماده ارسال' ) ) : 'غیرفعال' ) ) ); ?>
				<?php $this->status_box( 'آخرین تلاش SMS', $sms_last_attempt ); ?>
				<?php $this->status_box( 'نتیجه Transport', $sms_result_label ); ?>
				<?php $this->status_box( 'آخرین درخواست پذیرفته‌شده', $sms_last_success ); ?>
			</div>

			<?php if ( ! $supported ) : ?>
				<div class="mobo-message mobo-message-info">Provider این Connection قابلیت خواندن موجودی کیف پول را ارائه نمی‌کند؛ پایش Wallet برای این اتصال اجرا نمی‌شود.</div>
			<?php elseif ( ! $runtime_ready ) : ?>
				<div class="mobo-message mobo-message-warning">Connection انتخاب‌شده برای runtime فعال/provisioned/ownership-ready نیست؛ تا آماده‌شدن آن، پایش کیف پول اجرا نمی‌شود.</div>
			<?php elseif ( $uncertain ) : ?>
				<div class="mobo-message mobo-message-error">نتیجه یک ارسال قبلی از مرز درگاه عبور کرده اما ثبت durable وضعیت one-shot قابل تأیید نبوده است. برای جلوگیری از SMS تکراری، ارسال خودکار متوقف شده است. ابتدا وضعیت درگاه/گیرنده را بررسی کنید؛ سپس فقط اگر تصمیم دارید هشدار دوباره مجاز شود، آن را صریحاً فعال کنید.</div>
				<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" style="margin-top:12px;">
					<input type="hidden" name="action" value="mobo_core_rearm_wallet_alert">
					<?php wp_nonce_field( 'mobo_core_rearm_wallet_alert' ); ?>
					<?php submit_button( 'تأیید بررسی و فعال‌سازی مجدد', 'secondary', 'submit', false ); ?>
				</form>
			<?php elseif ( $notified ) : ?>
				<div class="mobo-message mobo-message-warning">
					هشدار کمبود موجودی قبلاً ارسال شده است<?php echo '—' !== $notified_at ? ' در ' . esc_html( $notified_at ) : ''; ?>. حتی اگر موجودی موبو افزایش پیدا کند، ارسال خودکار دوباره فعال نمی‌شود تا این دکمه را بزنید.
				</div>
				<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" style="margin-top:12px;">
					<input type="hidden" name="action" value="mobo_core_rearm_wallet_alert">
					<?php wp_nonce_field( 'mobo_core_rearm_wallet_alert' ); ?>
					<?php submit_button( 'فعال‌سازی مجدد یادآوری موجودی', 'secondary', 'submit', false ); ?>
				</form>
			<?php elseif ( $enabled ) : ?>
				<div class="mobo-message mobo-message-success">یادآوری کمبود موجودی فعال است و اگر موجودی در بررسی بعد از خرید به حد تنظیم‌شده یا کمتر برسد، پیامک ارسال می‌شود.</div>
			<?php else : ?>
				<div class="mobo-message mobo-message-info">هشدار موجودی غیرفعال است. تنظیمات آن در بخش «پیامک و هشدار موجودی» در نمای پیشرفته قرار دارد.</div>
			<?php endif; ?>

			<?php if ( $runtime_ready && ! empty( $status['lastError'] ) ) : ?>
				<div class="mobo-message mobo-message-error"><strong>آخرین خطای دریافت موجودی کیف پول:</strong> <?php echo esc_html( $status['lastError'] ); ?></div>
			<?php endif; ?>
			<?php if ( $runtime_ready && $enabled && ! empty( $status['smsLastError'] ) ) : ?>
				<div class="mobo-message mobo-message-error"><strong>آخرین خطای ارسال SMS هشدار کیف پول:</strong> <?php echo esc_html( $status['smsLastError'] ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render SMS notifications tab.
	 *
	 * @return void
	 */

	/** Resolve the local Persian WooCommerce SMS transport surface without sending anything. */
	private function get_sms_transport_admin_status() {
		$status = array(
			'available'    => false,
			'gatewayLabel' => '—',
			'message'      => 'افزونه «پیامک حرفه‌ای ووکامرس» یا API ارسال آن در دسترس نیست.',
		);

		if ( ! function_exists( 'PWSMS' ) ) {
			return $status;
		}

		try {
			$client = PWSMS();
		} catch ( Throwable $e ) {
			$status['message'] = 'افزونه پیامک هنگام بارگذاری خطا داد؛ تا رفع خطا ارسال قابل اتکا نیست.';
			return $status;
		}

		if ( ! is_object( $client ) || ! method_exists( $client, 'send_sms' ) ) {
			return $status;
		}

		$status['available'] = true;
		$status['message']   = 'API ارسال افزونه پیامک در دسترس است. این فقط آمادگی محلی را نشان می‌دهد و تحویل واقعی SMS را اثبات نمی‌کند.';

		if ( method_exists( $client, 'get_sms_gateway' ) ) {
			try {
				$gateway = $client->get_sms_gateway();
				if ( is_object( $gateway ) && method_exists( $gateway, 'name' ) ) {
					$status['gatewayLabel'] = sanitize_text_field( (string) $gateway->name() );
				}
			} catch ( Throwable $e ) {
				$status['gatewayLabel'] = 'نام درگاه قابل خواندن نیست';
			}
		}

		return $status;
	}

	/** Manager-friendly SMS overview. */
	private function render_sms_tab() {
		$sms_enabled      = Mobo_Core_Settings::enabled( 'mobo_core_sms_notifications_enabled', '0' );
		$wallet_status    = class_exists( 'Mobo_Core_Wallet_Alert' ) ? Mobo_Core_Wallet_Alert::get_status() : array();
		$wallet_enabled   = ! empty( $wallet_status['enabled'] );
		$wallet_supported = ! empty( $wallet_status['supported'] );
		$wallet_runtime_ready = ! empty( $wallet_status['runtimeReady'] );
		$wallet_notified  = ! empty( $wallet_status['notified'] );
		$wallet_uncertain = ! empty( $wallet_status['uncertain'] );
		$threshold        = isset( $wallet_status['threshold'] ) ? max( 0, (int) $wallet_status['threshold'] ) : 0;
		$transport        = $this->get_sms_transport_admin_status();
		$scenarios        = class_exists( 'Mobo_Core_SMS_Notifications' ) ? ( new Mobo_Core_SMS_Notifications() )->get_scenarios() : array( 'non_mobo' => 'غیر موبو', 'mobo_only' => 'فقط موبو', 'mixed' => 'ترکیبی' );
		$enabled_scenarios = 0;
		$ready_scenarios   = 0;

		foreach ( array_keys( $scenarios ) as $scenario ) {
			$key = sanitize_key( $scenario );
			if ( ! Mobo_Core_Settings::enabled( 'mobo_core_sms_' . $key . '_enabled', '0' ) ) {
				continue;
			}
			$enabled_scenarios++;
			$recipients = trim( (string) Mobo_Core_Settings::get( 'mobo_core_sms_' . $key . '_recipients', '' ) );
			$template   = trim( (string) Mobo_Core_Settings::get( 'mobo_core_sms_' . $key . '_template', '' ) );
			if ( '' !== $recipients && '' !== $template ) {
				$ready_scenarios++;
			}
		}

		$wallet_recipients = trim( (string) Mobo_Core_Settings::get( 'mobo_core_wallet_alert_recipients', '' ) );
		$wallet_template   = trim( (string) Mobo_Core_Settings::get( 'mobo_core_wallet_alert_template', '' ) );
		$wallet_configured = '' !== $wallet_recipients && '' !== $wallet_template;
		$has_active_feature = $sms_enabled || $wallet_enabled;
		$has_blocker = false;

		if ( $sms_enabled && ( empty( $transport['available'] ) || 0 === $enabled_scenarios || $ready_scenarios < $enabled_scenarios ) ) {
			$has_blocker = true;
		}
		if ( $wallet_enabled && ( empty( $transport['available'] ) || ! $wallet_runtime_ready || ! $wallet_configured ) ) {
			$has_blocker = true;
		}

		if ( ! $has_active_feature ) {
			$badge_class = 'info';
			$badge_label = 'غیرفعال';
		} elseif ( $has_blocker ) {
			$badge_class = 'warning';
			$badge_label = 'نیازمند تکمیل';
		} elseif ( $wallet_enabled && $wallet_uncertain ) {
			$badge_class = 'danger';
			$badge_label = 'وضعیت ارسال نامطمئن';
		} elseif ( $wallet_enabled && $wallet_notified ) {
			$badge_class = 'warning';
			$badge_label = 'هشدار کیف پول ارسال شده';
		} else {
			$badge_class = 'success';
			$badge_label = 'پیکربندی آماده';
		}

		$order_state = ! $sms_enabled
			? 'خاموش'
			: ( 0 === $enabled_scenarios ? 'سناریویی فعال نیست' : ( $ready_scenarios === $enabled_scenarios ? $ready_scenarios . ' سناریو آماده' : $ready_scenarios . ' از ' . $enabled_scenarios . ' آماده' ) );

		if ( ! $wallet_enabled ) {
			$wallet_state = 'خاموش';
		} elseif ( ! $wallet_supported ) {
			$wallet_state = 'Provider پشتیبانی نمی‌کند';
		} elseif ( ! $wallet_runtime_ready ) {
			$wallet_state = 'Connection برای runtime آماده نیست';
		} elseif ( ! $wallet_configured ) {
			$wallet_state = 'نیازمند گیرنده/Template';
		} elseif ( $wallet_uncertain ) {
			$wallet_state = 'نامطمئن؛ ارسال خودکار متوقف';
		} elseif ( $wallet_notified ) {
			$wallet_state = 'ارسال شده؛ نیازمند فعال‌سازی مجدد';
		} else {
			$wallet_state = 'آماده ارسال';
		}

		$connection = $this->current_admin_connection();
		$connection_label = is_array( $connection ) && '' !== trim( (string) ( $connection['display_name'] ?? '' ) )
			? trim( (string) $connection['display_name'] )
			: ( is_array( $connection ) ? ( 'Connection #' . absint( $connection['id'] ?? 0 ) ) : 'اتصال انتخاب‌شده' );
		?>
		<div class="mobo-card mobo-ux-primary-card">
			<div class="mobo-admin-card-heading"><div><h2 class="mobo-admin-section-title">پیامک و هشدار موجودی</h2><p>پیامک سفارش برای کل فروشگاه است؛ هشدار کیف پول فقط به Connection انتخاب‌شده تعلق دارد.</p></div><span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span></div>
			<div class="mobo-ux-summary-grid">
				<?php $this->status_box( 'سرویس پیامک', ! empty( $transport['available'] ) ? ( 'API در دسترس' . ( '—' !== $transport['gatewayLabel'] ? ' — ' . $transport['gatewayLabel'] : '' ) ) : 'در دسترس نیست' ); ?>
				<?php $this->status_box( 'پیامک سفارش — سراسری', $order_state ); ?>
				<?php $this->status_box( 'هشدار کیف پول — ' . $connection_label, $wallet_state ); ?>
				<?php $this->status_box( 'حد هشدار کیف پول', number_format_i18n( $threshold ) ); ?>
			</div>
			<?php if ( empty( $transport['available'] ) && $has_active_feature ) : ?>
				<div class="mobo-message mobo-message-warning"><?php echo esc_html( $transport['message'] ); ?></div>
			<?php elseif ( $has_active_feature ) : ?>
				<div class="mobo-note"><?php echo esc_html( $transport['message'] ); ?></div>
			<?php else : ?>
				<div class="mobo-note">هیچ ارسال خودکاری از این صفحه فعال نیست. می‌توانید تنظیمات را آماده کنید و بعداً فقط قابلیت موردنیاز را روشن کنید.</div>
			<?php endif; ?>
		</div>
		<details class="mobo-admin-details mobo-ux-secondary-details">
			<summary>تنظیم پیام‌ها، گیرنده‌ها و قالب‌ها</summary>
			<div class="mobo-admin-details__body"><?php $this->render_sms_technical_tab(); ?></div>
		</details>
		<?php
	}

	private function render_sms_technical_tab() {
		$transport        = $this->get_sms_transport_admin_status();
		$sms_available    = ! empty( $transport['available'] );
		$gateway_label    = isset( $transport['gatewayLabel'] ) ? (string) $transport['gatewayLabel'] : '—';
		$wallet_status    = class_exists( 'Mobo_Core_Wallet_Alert' ) ? Mobo_Core_Wallet_Alert::get_status() : array();
		$wallet_supported = ! empty( $wallet_status['supported'] );
		$wallet_runtime_ready = ! empty( $wallet_status['runtimeReady'] );
		$wallet_notified  = ! empty( $wallet_status['notified'] );
		$wallet_uncertain = ! empty( $wallet_status['uncertain'] );
		$wallet_recipients = trim( (string) Mobo_Core_Settings::get( 'mobo_core_wallet_alert_recipients', '' ) );
		$wallet_template   = trim( (string) Mobo_Core_Settings::get( 'mobo_core_wallet_alert_template', '' ) );
		$wallet_test_ready = $sms_available && '' !== $wallet_recipients && '' !== $wallet_template;
		$connection = $this->current_admin_connection();
		$connection_label = is_array( $connection ) && '' !== trim( (string) ( $connection['display_name'] ?? '' ) )
			? trim( (string) $connection['display_name'] )
			: ( is_array( $connection ) ? ( 'Connection #' . absint( $connection['id'] ?? 0 ) ) : 'اتصال انتخاب‌شده' );

		$scenarios = class_exists( 'Mobo_Core_SMS_Notifications' ) ? ( new Mobo_Core_SMS_Notifications() )->get_scenarios() : array(
			'non_mobo'  => 'سفارش غیر موبو',
			'mobo_only' => 'سفارش فقط محصولات موبو',
			'mixed'     => 'سفارش ترکیبی موبو و غیرموبو',
		);
		?>
		<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="sms">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-grid">
				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>پیامک سفارش <span class="mobo-admin-badge mobo-admin-badge--info">سراسری فروشگاه</span></h2>
						<p>این تنظیمات global هستند و با تغییر Connection عوض نمی‌شوند. پیامک در زمان ساخته‌شدن سفارش توسط Checkout ارسال می‌شود؛ منتظر پرداخت موفق نمی‌ماند.</p>
					</div>

					<?php if ( $sms_available ) : ?>
						<div class="mobo-message mobo-message-success">API افزونه «پیامک حرفه‌ای ووکامرس» در دسترس است<?php echo '—' !== $gateway_label ? '؛ درگاه گزارش‌شده: ' . esc_html( $gateway_label ) : ''; ?>. این وضعیت تحویل واقعی SMS را اثبات نمی‌کند.</div>
					<?php else : ?>
						<div class="mobo-message mobo-message-warning"><?php echo esc_html( $transport['message'] ); ?> تنظیمات ذخیره می‌شوند اما ارسال انجام نمی‌شود.</div>
					<?php endif; ?>

					<?php $this->bool_field( 'فعال‌سازی پیامک‌های تکمیلی سفارش', 'mobo_core_sms_notifications_enabled', false, '', 'این کلید فقط ارسال‌های MoboCore را کنترل می‌کند و تنظیمات اصلی افزونه پیامک WooCommerce را تغییر نمی‌دهد.' ); ?>
					<div class="mobo-message mobo-message-info">درخواست پذیرفته‌شده برای هر سفارش/سناریو فقط یک بار ثبت می‌شود. خطای یا نتیجه نامطمئن در Order Note ثبت می‌شود و صف Retry مستقل ندارد؛ رفع خطای درگاه به‌تنهایی SMS قبلی را دوباره ارسال نمی‌کند.</div>
				</div>

				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>هشدار موجودی کیف پول <span class="mobo-admin-badge mobo-admin-badge--info"><?php echo esc_html( $connection_label ); ?></span></h2>
						<p>این تنظیمات و سابقه هشدار Connection-scoped هستند. موجودی فقط بعد از خرید موفق موبو بررسی می‌شود و هشدار پایین‌بودن موجودی one-shot است.</p>
					</div>

					<?php if ( ! $wallet_supported ) : ?>
						<div class="mobo-message mobo-message-warning">Provider اتصال انتخاب‌شده endpoint موجودی کیف پول ندارد. در این وضعیت پایش کیف پول اجرا نمی‌شود و نباید به‌عنوان خطای عملیاتی ثبت شود.</div>
					<?php elseif ( ! $wallet_runtime_ready ) : ?>
						<div class="mobo-message mobo-message-warning">Connection انتخاب‌شده هنوز برای runtime فعال/provisioned/ownership-ready نیست؛ پایش Wallet تا آماده‌شدن Connection متوقف می‌ماند.</div>
					<?php endif; ?>

					<?php $this->bool_field( 'فعال‌سازی هشدار موجودی', 'mobo_core_wallet_alert_enabled', false, '', 'این هشدار مستقل از master پیامک سفارش است؛ برای ارسال فقط به سرویس پیامک، گیرنده و Template معتبر نیاز دارد.' ); ?>

					<div class="mobo-field">
						<label for="mobo_core_wallet_alert_threshold">حد موجودی برای ارسال هشدار</label>
						<input type="number" min="0" step="1" id="mobo_core_wallet_alert_threshold" name="mobo_core_wallet_alert_threshold" value="<?php echo esc_attr( (string) Mobo_Core_Settings::get_int( 'mobo_core_wallet_alert_threshold', 0, 0, PHP_INT_MAX ) ); ?>">
						<div class="mobo-help">مقایسه با مقدار عددی <code dir="ltr">amount</code> API موجودی همین Provider انجام می‌شود. مقدار ۰ نیز یک حد معتبر است.</div>
					</div>

					<div class="mobo-field mobo-field-full">
						<label for="mobo_core_wallet_alert_recipients">شماره گیرنده هشدار موجودی</label>
						<textarea id="mobo_core_wallet_alert_recipients" name="mobo_core_wallet_alert_recipients" rows="3" dir="ltr" style="text-align:left;"><?php echo esc_textarea( (string) Mobo_Core_Settings::get( 'mobo_core_wallet_alert_recipients', '' ) ); ?></textarea>
						<div class="mobo-help">یک یا چند شماره مدیر فروشگاه را با کاما یا خط جدید جدا کنید.</div>
					</div>

					<div class="mobo-field mobo-field-full">
						<label for="mobo_core_wallet_alert_template">Template اختصاصی هشدار موجودی</label>
						<textarea id="mobo_core_wallet_alert_template" name="mobo_core_wallet_alert_template" rows="8" dir="rtl"><?php echo esc_textarea( (string) Mobo_Core_Settings::get( 'mobo_core_wallet_alert_template', '' ) ); ?></textarea>
						<div class="mobo-help">متغیرها: <code dir="ltr">{mobo_wallet_balance}</code>، <code dir="ltr">{mobo_wallet_balance_formatted}</code>، <code dir="ltr">{mobo_wallet_threshold}</code>، <code dir="ltr">{mobo_wallet_threshold_formatted}</code>، <code dir="ltr">{site_name}</code> و <code dir="ltr">{site_url}</code>.</div>
					</div>

					<textarea rows="5" readonly dir="ltr" onclick="this.select();">pattern:67890
balance:{mobo_wallet_balance}
threshold:{mobo_wallet_threshold}
site:{site_name}</textarea>
				</div>

				<?php foreach ( $scenarios as $scenario => $label ) : ?>
					<?php $this->render_sms_scenario_box( $scenario, $label ); ?>
				<?php endforeach; ?>

				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>راهنمای Template</h2>
						<p>هم متن ساده و هم الگوی pattern افزونه پیامک فارسی ووکامرس قابل استفاده است.</p>
					</div>

					<div class="mobo-message mobo-message-info">برای ارسال الگو، متن را با ساختار همان افزونه وارد کنید. شورت‌کدهای سفارش مثل <code dir="ltr">{order_id}</code>، <code dir="ltr">{price}</code>، <code dir="ltr">{all_items}</code>، <code dir="ltr">{phone}</code> و <code dir="ltr">{shipping_method}</code> توسط افزونه پیامک جایگزین می‌شوند.</div>

					<textarea rows="7" readonly dir="ltr" onclick="this.select();">pattern:12345
order_id:{order_id}
price:{price}
type:{mobo_order_type_label}</textarea>

					<div class="mobo-help">شورت‌کدهای اختصاصی MoboCore: <code dir="ltr">{mobo_order_type}</code>، <code dir="ltr">{mobo_order_type_label}</code>، <code dir="ltr">{mobo_items_count}</code>، <code dir="ltr">{non_mobo_items_count}</code>.</div>
					<div class="mobo-help">در گیرنده می‌توانید شماره ثابت یا <code dir="ltr">{customer_mobile}</code> را استفاده کنید. چند شماره را با کاما یا خط جدید جدا کنید.</div>
				</div>
			</div>

			<?php submit_button( 'ذخیره تنظیمات پیامک و هشدار', 'primary' ); ?>
		</form>

		<div class="mobo-grid" style="margin-top:16px;">
			<div class="mobo-card mobo-card-wide">
				<div class="mobo-card-head">
					<h2>وضعیت one-shot هشدار کیف پول</h2>
					<p>افزایش موجودی یا تغییر حد هشدار، ارسال را خودکار re-arm نمی‌کند. بعد از هشدار قطعی یا هر نتیجه نامطمئن پس از عبور از مرز درگاه، تصمیم برای فعال‌سازی مجدد باید صریحاً توسط مدیر انجام شود.</p>
				</div>
				<?php if ( $wallet_uncertain ) : ?>
					<div class="mobo-message mobo-message-error">نتیجه ارسال قبلی replay-safe نیست. MoboCore برای جلوگیری از SMS تکراری، این Connection را پشت fence نگه می‌دارد. ابتدا گزارش درگاه/شماره گیرنده را بررسی کنید؛ فعال‌سازی مجدد یعنی اجازه یک ارسال جدید را می‌دهید.</div>
					<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>">
						<input type="hidden" name="action" value="mobo_core_rearm_wallet_alert">
						<input type="hidden" name="mobo_return_tab" value="sms">
						<?php wp_nonce_field( 'mobo_core_rearm_wallet_alert' ); ?>
						<?php submit_button( 'تأیید بررسی و فعال‌سازی مجدد', 'secondary', 'submit', false ); ?>
					</form>
				<?php elseif ( $wallet_notified ) : ?>
					<div class="mobo-message mobo-message-warning">هشدار این Connection قبلاً ارسال شده است و تا فعال‌سازی مجدد، خریدهای بعدی SMS کمبود موجودی دیگری ارسال نمی‌کنند.</div>
					<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>">
						<input type="hidden" name="action" value="mobo_core_rearm_wallet_alert">
						<input type="hidden" name="mobo_return_tab" value="sms">
						<?php wp_nonce_field( 'mobo_core_rearm_wallet_alert' ); ?>
						<?php submit_button( 'فعال‌سازی مجدد هشدار این Connection', 'secondary', 'submit', false ); ?>
					</form>
				<?php elseif ( ! empty( $wallet_status['enabled'] ) ) : ?>
					<div class="mobo-message mobo-message-success">هشدار این Connection هنوز مصرف نشده و برای اولین عبور موجودی به حد تنظیم‌شده یا کمتر آماده است.</div>
				<?php else : ?>
					<div class="mobo-message mobo-message-info">هشدار کیف پول برای این Connection خاموش است.</div>
				<?php endif; ?>
			</div>

			<div class="mobo-card mobo-card-wide">
				<div class="mobo-card-head">
					<h2>تست مستقیم transport پیامک کیف پول</h2>
					<p>این تست فقط مسیر MoboCore → Persian WooCommerce SMS → درگاه را امتحان می‌کند؛ موجودی را از Provider نمی‌خواند و one-shot واقعی را مصرف یا پاک نمی‌کند.</p>
				</div>
				<?php
				$test_result  = isset( $wallet_status['testSmsLastResult'] ) ? sanitize_key( (string) $wallet_status['testSmsLastResult'] ) : '';
				$test_attempt = ! empty( $wallet_status['testSmsLastAttemptAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $wallet_status['testSmsLastAttemptAt'] ) ) : '—';
				$test_success = ! empty( $wallet_status['testSmsLastSuccessAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $wallet_status['testSmsLastSuccessAt'] ) ) : '—';
				?>
				<div class="mobo-status-grid">
					<?php $this->status_box( 'آخرین تست', $test_attempt ); ?>
					<?php $this->status_box( 'نتیجه transport', 'sent' === $test_result ? 'پذیرفته‌شده توسط درگاه' : ( 'failed' === $test_result ? 'ناموفق' : ( 'attempting' === $test_result ? 'در حال تلاش' : '—' ) ) ); ?>
					<?php $this->status_box( 'آخرین تست پذیرفته‌شده', $test_success ); ?>
				</div>
				<?php if ( ! empty( $wallet_status['testSmsLastError'] ) ) : ?>
					<div class="mobo-message mobo-message-error"><strong>خطای آخرین تست:</strong> <?php echo esc_html( $wallet_status['testSmsLastError'] ); ?></div>
				<?php endif; ?>
				<div class="mobo-help">مقدار balance تست از آخرین موجودی ثبت‌شده استفاده می‌کند؛ اگر هنوز موجودی خوانده نشده باشد، threshold جایگزین می‌شود. پذیرش توسط API درگاه به معنی تحویل نهایی به گوشی نیست.</div>
				<?php if ( $wallet_test_ready ) : ?>
					<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" style="margin-top:12px;">
						<input type="hidden" name="action" value="mobo_core_test_wallet_sms">
						<?php wp_nonce_field( 'mobo_core_test_wallet_sms' ); ?>
						<?php submit_button( 'ارسال SMS آزمایشی', 'secondary', 'submit', false ); ?>
					</form>
				<?php else : ?>
					<div class="mobo-message mobo-message-warning">برای تست، ابتدا API افزونه پیامک باید در دسترس باشد و گیرنده و Template هشدار کیف پول ذخیره شده باشند.</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one SMS scenario card.
	 *
	 * @param string $scenario Scenario key.
	 * @param string $label Scenario label.
	 * @return void
	 */
	private function render_sms_scenario_box( $scenario, $label ) {
		$scenario = sanitize_key( $scenario );
		$enabled_key = 'mobo_core_sms_' . $scenario . '_enabled';
		$recipients_key = 'mobo_core_sms_' . $scenario . '_recipients';
		$template_key = 'mobo_core_sms_' . $scenario . '_template';
		$recipients = (string) Mobo_Core_Settings::get( $recipients_key, '' );
		$template = (string) Mobo_Core_Settings::get( $template_key, '' );
		?>
		<div class="mobo-card mobo-card-wide">
			<div class="mobo-card-head">
				<h2><?php echo esc_html( $label ); ?></h2>
				<p>برای این نوع سفارش مشخص کن پیامک ارسال شود یا نه، به کدام شماره ها، و با چه متن/الگویی.</p>
			</div>

			<?php $this->bool_field( 'ارسال پیامک برای این نوع سفارش', $enabled_key, false, '', 'برای سناریوی فعال، گیرنده و Template هر دو باید مقدار داشته باشند.' ); ?>

			<div class="mobo-field mobo-field-full">
				<label for="<?php echo esc_attr( $recipients_key ); ?>">شماره گیرنده</label>
				<textarea id="<?php echo esc_attr( $recipients_key ); ?>" name="<?php echo esc_attr( $recipients_key ); ?>" rows="3" dir="ltr" style="text-align:left;"><?php echo esc_textarea( $recipients ); ?></textarea>
				<div class="mobo-help">مثال: <code dir="ltr">09123456789, 09351234567</code> یا <code dir="ltr">{customer_mobile}</code>.</div>
			</div>

			<div class="mobo-field mobo-field-full">
				<label for="<?php echo esc_attr( $template_key ); ?>">متن یا Template پیامک</label>
				<textarea id="<?php echo esc_attr( $template_key ); ?>" name="<?php echo esc_attr( $template_key ); ?>" rows="8" dir="rtl"><?php echo esc_textarea( $template ); ?></textarea>
				<div class="mobo-help">برای درگاه های pattern، همان فرمت pattern افزونه پیامک فارسی ووکامرس را وارد کن. برای متن ساده هم می توانی شورت کدهای سفارش را داخل متن بگذاری.</div>
			</div>
		</div>
		<?php
	}


	/**
	 * Pre-check report, downtime warning, progress bar and per-table results.
	 *
	 * @param array $migration Migration status.
	 * @return void
	 */
	private function render_unicode_conversion_panel( $migration ) {
		$migration = is_array( $migration ) ? $migration : array();
		$precheck  = class_exists( 'Mobo_Core_Database_Unicode_Migration' ) ? Mobo_Core_Database_Unicode_Migration::get_precheck() : array();
		$server    = isset( $precheck['server'] ) && is_array( $precheck['server'] ) ? $precheck['server'] : array();
		$total     = absint( $migration['totalTables'] ?? 0 );
		$cursor    = absint( $migration['cursor'] ?? 0 );
		$percent   = $total > 0 ? min( 100, (int) round( $cursor * 100 / $total ) ) : 0;
		$labels    = array( 'converted' => 'تبدیل شد', 'already' => 'از قبل utf8mb4', 'skipped' => 'رد شد', 'failed' => 'خطا' );
		?>
		<div class="mobo-alert mobo-alert-warning" style="margin-top:12px">
			<strong>هشدار توقف موقت (Downtime):</strong> تبدیل هر جدول با <code dir="ltr">ALTER TABLE … CONVERT</code> یک نسخه کامل از جدول می‌سازد و در این مدت نوشتن روی همان جدول (مثلاً ثبت سفارش یا ویرایش محصول) مسدود می‌شود. روی MySQL 5.7 و MariaDB قدیمی این زمان طولانی‌تر است. تبدیل را در ساعات کم‌بازدید انجام دهید.
			<br><strong>Backup:</strong> افزونه روی این هاست نمی‌تواند از دیتابیس نسخه پشتیبان بگیرد؛ قبل از شروع از طریق پنل هاست (phpMyAdmin / cPanel / DirectAdmin) Backup کامل بگیرید.
			<br>تبدیل فقط با کلیک شما و جدول‌به‌جدول انجام می‌شود (اول جدول‌های دسته‌بندی و جدول‌های Mobo، سپس کوچک‌ترها) و هیچ تبدیل خودکاری وجود ندارد. wp-config.php ویرایش نمی‌شود؛ اگر DB_CHARSET هنوز utf8 است پس از تبدیل آن را دستی روی <code dir="ltr">utf8mb4</code> بگذارید.
		</div>
		<?php if ( $total > 0 ) : ?>
			<div style="margin-top:12px;background:#eee;border-radius:4px;height:14px;overflow:hidden" aria-label="پیشرفت تبدیل"><div style="background:#2271b1;height:14px;width:<?php echo esc_attr( (string) $percent ); ?>%"></div></div>
			<p class="mobo-help"><?php echo esc_html( sprintf( 'پیشرفت: %d از %d جدول (%d%%).', $cursor, $total, $percent ) ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $migration['tableResults'] ) && is_array( $migration['tableResults'] ) ) : ?>
			<table class="widefat striped" style="margin-top:8px"><thead><tr><th>جدول</th><th>نتیجه</th><th>ردیف قبل/بعد</th><th>زمان (ثانیه)</th><th>توضیح/خطا</th></tr></thead><tbody>
			<?php foreach ( array_slice( $migration['tableResults'], -100, null, true ) as $table => $row ) : ?>
				<tr>
					<td dir="ltr"><?php echo esc_html( (string) $table ); ?></td>
					<td><?php echo esc_html( $labels[ (string) ( $row['status'] ?? '' ) ] ?? (string) ( $row['status'] ?? '' ) ); ?></td>
					<td dir="ltr"><?php echo esc_html( isset( $row['rowsBefore'] ) ? (string) $row['rowsBefore'] . ' / ' . (string) ( $row['rowsAfter'] ?? '—' ) : '—' ); ?></td>
					<td dir="ltr"><?php echo esc_html( isset( $row['seconds'] ) ? (string) $row['seconds'] : '—' ); ?></td>
					<td><?php echo esc_html( (string) ( $row['error'] ?? '' ) . ( ! empty( $row['rowFormatDynamic'] ) ? ' (ROW_FORMAT=DYNAMIC)' : '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
		<?php if ( ! empty( $precheck['success'] ) ) : ?>
			<details class="mobo-collapsible-log" style="margin-top:12px" <?php echo in_array( (string) ( $migration['status'] ?? 'idle' ), array( 'idle', '' ), true ) ? 'open' : ''; ?>>
				<summary><?php echo esc_html( sprintf( 'نتیجه پیش‌بررسی (%s)', wp_date( 'Y-m-d H:i', absint( $precheck['checkedAt'] ?? 0 ) ) ) ); ?></summary>
				<p><?php echo esc_html( sprintf( 'سرور: %s | charset اتصال: %s | DB_CHARSET: %s | charset پیش‌فرض دیتابیس: %s', (string) ( $server['version'] ?? '—' ), (string) ( $precheck['connectionCharset'] ?? '—' ), (string) ( $precheck['configCharset'] ?? '—' ), (string) ( $precheck['databaseCharset'] ?? '—' ) ) ); ?></p>
				<p><?php echo esc_html( sprintf( 'جدول‌های نیازمند تبدیل: %d | از قبل utf8mb4: %d | غیرقابل تبدیل به‌خاطر طول ایندکس: %d | حجم: %s | زمان تقریبی کل: %s', absint( $precheck['tablesToConvert'] ?? 0 ), absint( $precheck['tablesAlreadyUtf8mb4'] ?? 0 ), absint( $precheck['tablesSkippedByIndex'] ?? 0 ), size_format( absint( $precheck['totalBytes'] ?? 0 ) ), human_time_diff( 0, max( 1, absint( $precheck['totalEstimatedSeconds'] ?? 0 ) ) ) ) ); ?></p>
				<?php if ( ! empty( $server['oldServer'] ) ) : ?><div class="mobo-alert mobo-alert-error">این سرور MySQL/MariaDB قدیمی است (قبل از MySQL 5.7.7 / MariaDB 10.2.2)؛ تبدیل کندتر است و جدول‌ها در طول تبدیل قفل می‌شوند.</div><?php endif; ?>
				<table class="widefat striped"><thead><tr><th>#</th><th>جدول</th><th>Engine / Row format</th><th>Collation فعلی</th><th>ستون‌های غیر utf8mb4</th><th>حجم</th><th>ردیف (تقریبی)</th><th>زمان تقریبی</th><th>روش</th></tr></thead><tbody>
				<?php foreach ( (array) ( $precheck['entries'] ?? array() ) as $i => $entry ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
						<td dir="ltr"><?php echo esc_html( (string) $entry['table'] ); ?></td>
						<td dir="ltr"><?php echo esc_html( (string) $entry['engine'] . ' / ' . (string) $entry['rowFormat'] ); ?></td>
						<td dir="ltr"><?php echo esc_html( (string) $entry['collation'] ); ?></td>
						<td dir="ltr"><?php echo esc_html( implode( ', ', array_slice( (array) $entry['badColumns'], 0, 6 ) ) ); ?></td>
						<td dir="ltr"><?php echo esc_html( size_format( absint( $entry['bytes'] ) ) ); ?></td>
						<td dir="ltr"><?php echo esc_html( number_format_i18n( absint( $entry['rows'] ) ) ); ?></td>
						<td dir="ltr"><?php echo esc_html( absint( $entry['estimatedSeconds'] ) . 's' ); ?></td>
						<td><?php echo esc_html( 'skip' === $entry['strategy'] ? 'رد می‌شود: ' . (string) $entry['reason'] : ( 'convert_dynamic' === $entry['strategy'] ? 'تبدیل + ROW_FORMAT=DYNAMIC' : 'تبدیل' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
			</details>
		<?php endif; ?>
		<?php
	}

	/**
	 * Start the explicit utf8mb4 database remediation workflow.
	 *
	 * @return void
	 */
	public function handle_start_database_unicode_migration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mobo_core_start_database_unicode_migration', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Database_Unicode_Migration' ) ) {
			$this->redirect_with_message( 'موتور تبدیل Unicode دیتابیس در دسترس نیست.', 'error', 'health' );
		}
		if ( ! Mobo_Core_Database_Unicode_Migration::precheck_is_fresh() ) {
			$this->redirect_with_message( 'ابتدا «پیش‌بررسی دیتابیس» را اجرا و نتیجه را مطالعه کنید (اعتبار پیش‌بررسی یک ساعت است).', 'warning', 'health' );
		}
		if ( empty( $_POST['mobo_backup_confirmed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$this->redirect_with_message( 'برای شروع، تأیید کنید که از دیتابیس نسخه پشتیبان (Backup) گرفته‌اید.', 'warning', 'health' );
		}

		$current = Mobo_Core_Database_Unicode_Migration::get_status();
		$current_status = isset( $current['status'] ) ? sanitize_key( (string) $current['status'] ) : 'idle';
		if ( in_array( $current_status, array( 'paused', 'failed', 'awaiting-config' ), true ) ) {
			$result = Mobo_Core_Database_Unicode_Migration::resume( 'admin' );
		} elseif ( ! empty( $current['active'] ) ) {
			$result = Mobo_Core_Database_Unicode_Migration::process_slice( 'admin' );
		} else {
			$result = Mobo_Core_Database_Unicode_Migration::start( 'admin' );
		}

		if ( empty( $result['success'] ) ) {
			$message = ! empty( $result['lastError'] ) ? (string) $result['lastError'] : 'شروع تبدیل utf8mb4 ناموفق بود.';
			$this->redirect_with_message( 'تبدیل Unicode شروع نشد: ' . $message, 'error', 'health' );
		}

		$status = isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : '';
		if ( 'completed' === $status ) {
			$this->redirect_with_message( 'تبدیل Unicode کامل شد و round-trip چهار‌بایتی نیز تأیید شد.', 'success', 'health' );
		}
		if ( 'awaiting-config' === $status ) {
			$this->redirect_with_message( 'تبدیل فیزیکی دیتابیس کامل شد. اکنون DB_CHARSET را در wp-config.php روی utf8mb4 قرار دهید و سپس «بررسی مجدد» را اجرا کنید.', 'warning', 'health' );
		}
		$this->redirect_with_message( $this->unicode_step_message( $result ), ! empty( $result['blocked'] ) ? 'warning' : 'success', 'health' );
	}

	/**
	 * Resume/re-check an existing Unicode conversion checkpoint.
	 *
	 * @return void
	 */
	public function handle_resume_database_unicode_migration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mobo_core_resume_database_unicode_migration', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Database_Unicode_Migration' ) ) {
			$this->redirect_with_message( 'موتور تبدیل Unicode دیتابیس در دسترس نیست.', 'error', 'health' );
		}
		$result = Mobo_Core_Database_Unicode_Migration::resume( 'admin' );
		if ( empty( $result['success'] ) ) {
			$message = ! empty( $result['lastError'] ) ? (string) $result['lastError'] : 'ادامه تبدیل ناموفق بود.';
			$this->redirect_with_message( 'ادامه تبدیل Unicode ناموفق بود: ' . $message, 'error', 'health' );
		}

		$status = isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : '';
		if ( 'completed' === $status ) {
			$this->redirect_with_message( 'سازگاری utf8mb4 به‌طور کامل تأیید شد.', 'success', 'health' );
		}
		if ( 'awaiting-config' === $status ) {
			$this->redirect_with_message( 'دیتابیس تبدیل شده است اما wp-config.php/اتصال جاری هنوز utf8mb4 نیست. DB_CHARSET را اصلاح و دوباره بررسی کنید.', 'warning', 'health' );
		}
		$this->redirect_with_message( $this->unicode_step_message( $result ), ! empty( $result['blocked'] ) || ! empty( $result['stepLocked'] ) ? 'warning' : 'success', 'health' );
	}

	/**
	 * Persian summary of one manual conversion step.
	 *
	 * @param array $result Step result.
	 * @return string
	 */
	private function unicode_step_message( $result ) {
		$result = is_array( $result ) ? $result : array();
		$status = sanitize_key( (string) ( $result['status'] ?? '' ) );
		if ( ! empty( $result['blocked'] ) || ! empty( $result['stepLocked'] ) ) {
			return (string) ( $result['lastError'] ?? 'مرحله اجرا نشد؛ دوباره تلاش کنید.' );
		}
		if ( 'partial' === $status ) {
			return 'تبدیل به پایان رسید اما بعضی جدول‌ها تبدیل نشدند؛ خطای هر جدول در جدول نتایج آمده است.';
		}
		if ( 'paused' === $status ) {
			return 'تبدیل متوقف شد؛ هر زمان خواستید «ادامه» را بزنید.';
		}
		return sprintf(
			'مرحله انجام شد: %d از %d جدول. %s',
			absint( $result['cursor'] ?? 0 ),
			absint( $result['totalTables'] ?? 0 ),
			! empty( $result['nextTable'] ) ? 'جدول بعدی: ' . sanitize_text_field( (string) $result['nextTable'] ) . ' — برای ادامه «تبدیل جدول بعدی» را بزنید.' : ''
		);
	}

	/**
	 * Run the read-only pre-check (charset, sizes, server version, estimate).
	 *
	 * @return void
	 */
	public function handle_precheck_database_unicode_migration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mobo_core_precheck_database_unicode_migration', 'mobo_core_nonce' );
		if ( ! class_exists( 'Mobo_Core_Database_Unicode_Migration' ) ) {
			$this->redirect_with_message( 'موتور تبدیل Unicode دیتابیس در دسترس نیست.', 'error', 'health' );
		}
		$precheck = Mobo_Core_Database_Unicode_Migration::precheck();
		if ( empty( $precheck['success'] ) ) {
			$this->redirect_with_message( 'پیش‌بررسی ناموفق بود: ' . sanitize_text_field( (string) ( $precheck['error'] ?? '' ) ), 'error', 'health' );
		}
		$this->redirect_with_message( 'پیش‌بررسی انجام شد؛ نتیجه را در بخش «تبدیل دیتابیس به utf8mb4» مطالعه کنید.', 'success', 'health' );
	}

	/**
	 * Admin notice on Mobo pages + dashboard when WP tables cannot hold
	 * four-byte characters. Uses the 6h cached column snapshot (cheap).
	 *
	 * @return void
	 */
	public function render_db_charset_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Mobo_Core_Unicode_Guard' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : '';
		if ( 'dashboard' !== $screen_id && false === strpos( $screen_id, self::MENU_SLUG ) ) {
			return;
		}
		try {
			$snapshot = Mobo_Core_Unicode_Guard::snapshot();
		} catch ( Throwable $e ) {
			return;
		}
		if ( 'ok' === $snapshot['status'] ) {
			return;
		}
		$url = $this->admin_page_url( array( 'tab' => 'health' ) );
		?>
		<div class="notice notice-warning">
			<p><strong>MoboCore:</strong>
			<?php
			echo esc_html(
				sprintf(
					'%d ستون از جدول‌های دیتابیس وردپرس از ایموجی/کاراکترهای ۴ بایتی پشتیبانی نمی‌کند (utf8/utf8mb3). همگام‌سازی متوقف نمی‌شود و این کاراکترها موقتاً جایگزین می‌شوند، اما برای نمایش دقیق عنوان‌ها توصیه می‌شود پس از گرفتن Backup، ابزار «تبدیل دیتابیس به utf8mb4» را دستی اجرا کنید.',
					count( $snapshot['unsupportedColumns'] )
				)
			);
			?>
			<a href="<?php echo esc_url( $url ); ?>">باز کردن ابزار تبدیل</a></p>
		</div>
		<?php
	}

	/**
	 * Pause Unicode conversion after the current atomic DDL boundary.
	 *
	 * @return void
	 */
	public function handle_pause_database_unicode_migration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mobo_core_pause_database_unicode_migration', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Database_Unicode_Migration' ) ) {
			$this->redirect_with_message( 'موتور تبدیل Unicode دیتابیس در دسترس نیست.', 'error', 'health' );
		}
		$result = Mobo_Core_Database_Unicode_Migration::pause();
		if ( 'paused' === sanitize_key( (string) ( $result['status'] ?? '' ) ) ) {
			$this->redirect_with_message( 'تبدیل Unicode در مرز امن متوقف شد؛ checkpoint برای ادامه بعدی حفظ شده است.', 'warning', 'health' );
		}
		if ( ! empty( $result['pauseRequested'] ) ) {
			$this->redirect_with_message( 'درخواست Pause ثبت شد. barrier تا پایان DDL جاری حفظ می‌شود و سپس فرآیند در اولین مرز امن متوقف خواهد شد.', 'warning', 'health' );
		}
		$this->redirect_with_message( 'فرآیند فعالی برای توقف وجود نداشت.', 'warning', 'health' );
	}

	/**
	 * Render bounded administrator-only PHP diagnostics without exposing phpinfo().
	 *
	 * @return void
	 */
	public function handle_php_diagnostics() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'mobo_core_php_diagnostics' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		header( 'Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; base-uri \'none\'; frame-ancestors \'self\'' );

		$settings = array(
			'PHP Version'        => PHP_VERSION,
			'SAPI'               => PHP_SAPI,
			'Memory Limit'       => Mobo_Core_Environment::ini( 'memory_limit' ),
			'Max Execution Time' => Mobo_Core_Environment::ini( 'max_execution_time' ),
			'Upload Max Filesize'=> Mobo_Core_Environment::ini( 'upload_max_filesize' ),
			'Post Max Size'      => Mobo_Core_Environment::ini( 'post_max_size' ),
			'Max Input Vars'     => Mobo_Core_Environment::ini( 'max_input_vars' ),
			'Default Socket Timeout' => Mobo_Core_Environment::ini( 'default_socket_timeout' ),
			'WordPress Version'  => get_bloginfo( 'version' ),
			'WooCommerce Version'=> defined( 'WC_VERSION' ) ? WC_VERSION : 'not loaded',
			'Mobo Core Version'  => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
		);
		$extensions = get_loaded_extensions();
		sort( $extensions, SORT_NATURAL | SORT_FLAG_CASE );

		echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<title>جزئیات محیط PHP - موبو</title><style>body{font-family:Tahoma,Arial,sans-serif;margin:24px;line-height:1.8;color:#1d2327}table{border-collapse:collapse;width:100%;max-width:960px}th,td{border:1px solid #dcdcde;padding:8px 10px;text-align:right;vertical-align:top}th{width:260px;background:#f6f7f7}code{direction:ltr;unicode-bidi:embed}.wrap{max-width:1100px;margin:auto}.note{background:#f6f7f7;padding:12px 16px;border-right:4px solid #2271b1}</style></head><body><div class="wrap">';
		echo '<h1>جزئیات امن محیط PHP</h1><p class="note">این صفحه عمداً phpinfo() کامل، مسیرهای فایل، Environment Variables، Headerها و مقادیر حساس را نمایش نمی‌دهد.</p><table>';
		foreach ( $settings as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
		}
		echo '</table><h2>PHP Extensions</h2><p><code>' . esc_html( implode( ', ', $extensions ) ) . '</code></p></div></body></html>';
		exit;
	}


	/**
	 * Render health reporting tab.
	 *
	 * @return void
	 */

	/** Manager-friendly technical health overview. */
	private function render_health_tab() {
		$reporter = new Mobo_Core_Health_Reporter();
		$local = $reporter->build_report( true );
		$observability = $this->health_observability_for_display( $local );
		$image = isset( $local['imageProcessing'] ) && is_array( $local['imageProcessing'] ) ? $local['imageProcessing'] : array();
		$cron = isset( $local['cronRunner'] ) && is_array( $local['cronRunner'] ) ? $local['cronRunner'] : array();
		$cron_last_run = isset( $cron['lastRun'] ) && is_array( $cron['lastRun'] ) ? $cron['lastRun'] : array();
		$wp_cron_disabled = ! empty( $local['wpCronDisabled'] );
		$cron_last_hit = absint( isset( $cron['lastHitAt'] ) ? $cron['lastHitAt'] : 0 );
		$cron_last_success = absint( isset( $cron['lastSuccessAt'] ) ? $cron['lastSuccessAt'] : 0 );
		$cron_overdue = ! empty( $cron['isOverdue'] );
		$cron_last_status = isset( $cron_last_run['status'] ) ? sanitize_key( (string) $cron_last_run['status'] ) : '';
		$cron_last_executed = absint( isset( $cron_last_run['executedAt'] ) ? $cron_last_run['executedAt'] : 0 );
		$cron_last_partial = 'partial' === $cron_last_status;
		$cron_last_failed = $cron_last_executed > 0
			&& ! in_array( $cron_last_status, array( 'ok', 'paused-for-upgrade', 'baseline-suspended' ), true )
			&& ( empty( $cron_last_run['success'] ) || $cron_last_partial || 'lock-lost' === $cron_last_status );
		$failed_webhooks = absint( isset( $local['failedWebhookJobs'] ) ? $local['failedWebhookJobs'] : 0 );
		$pending_webhooks = absint( isset( $local['pendingWebhookJobs'] ) ? $local['pendingWebhookJobs'] : 0 );
		$failed_images = absint( isset( $local['failedImageJobs'] ) ? $local['failedImageJobs'] : 0 );
		$image_ok = ! empty( $image['uploadsWritable'] ) && ( ! empty( $image['imagickLoaded'] ) || ! empty( $image['gdLoaded'] ) );
		list( $database_unicode, $database_unicode_ok, $database_unicode_message, $database_unicode_migration, $database_unicode_migration_status, $database_unicode_migration_active, $database_unicode_migration_label ) = $this->health_database_unicode_view( $local );
		$schema_ok = ! empty( $local['databaseSchemaReady'] );
		$php_ok = version_compare( PHP_VERSION, '7.4', '>=' );
		$wp_version = isset( $local['wordpressVersion'] ) ? (string) $local['wordpressVersion'] : get_bloginfo( 'version' );
		$wp_ok = '' !== $wp_version && version_compare( $wp_version, '5.8', '>=' );
		$wc_version = isset( $local['wooCommerceVersion'] ) ? (string) $local['wooCommerceVersion'] : '';
		$wc_ok = '' !== $wc_version && version_compare( $wc_version, '8.2', '>=' );
		$versions_ok = $php_ok && $wp_ok && $wc_ok;

		$current_connection = $this->current_admin_connection();
		$security_code = $this->current_admin_security_code();
		$connection_table_exists = class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists();
		$enabled_connection_count = $connection_table_exists ? Mobo_Core_Connection_Repository::count_enabled() : 0;
		$connection_enabled = $connection_table_exists
			? ( is_array( $current_connection ) && ! empty( $current_connection['is_enabled'] ) )
			: true;
		$connection_runtime_ready = ! $connection_table_exists
			|| ( $enabled_connection_count > 0 && ( $enabled_connection_count <= 1 || Mobo_Core_Connection_Repository::multi_connection_runtime_ready() ) );
		$health_auth_ready = $connection_enabled && $connection_runtime_ready && '' !== $security_code && Mobo_Core_Settings::is_valid_security_code( $security_code );

		$quota_available = ! empty( $local['accountDiskQuotaAvailable'] );
		$quota_unlimited = ! empty( $local['accountDiskQuotaUnlimited'] );
		$quota_percent = isset( $local['accountDiskFreePercent'] ) && null !== $local['accountDiskFreePercent'] ? (float) $local['accountDiskFreePercent'] : null;
		$quota_under_limit = isset( $local['accountDiskUnderLimit'] ) ? $local['accountDiskUnderLimit'] : null;
		$inode_under_limit = isset( $local['accountUnderInodeLimit'] ) ? $local['accountUnderInodeLimit'] : null;
		$write_probe_failed = array_key_exists( 'storageWriteProbeOk', $local ) && false === $local['storageWriteProbeOk'];
		$storage_hard_failure = ( $quota_available && ! $quota_unlimited && ( false === $quota_under_limit || false === $inode_under_limit ) ) || $write_probe_failed;
		$quota_label = $quota_unlimited
			? 'نامحدود'
			: ( $quota_available && null !== $quota_percent ? $quota_percent . '% آزاد' : 'قابل تشخیص نیست' );

		$cron_label = 'آماده';
		if ( ! $wp_cron_disabled ) {
			$cron_label = 'WP-Cron داخلی فعال است';
		} elseif ( $cron_last_hit <= 0 ) {
			$cron_label = 'هنوز اجرا ثبت نشده';
		} elseif ( $cron_overdue ) {
			$cron_label = 'از زمان‌بندی عقب افتاده';
		} elseif ( $cron_last_partial ) {
			$cron_label = 'آخرین اجرا ناقص';
		} elseif ( $cron_last_failed && $cron_last_success <= 0 ) {
			$cron_label = 'اجرای سالم ثبت نشده';
		} elseif ( $cron_last_failed ) {
			$cron_label = 'آخرین اجرا ناموفق';
		} elseif ( '' === $cron_last_status || 'never-run' === $cron_last_status ) {
			$cron_label = 'نتیجه زمان‌بندی‌شده جدید ثبت نشده';
		} elseif ( $cron_last_success <= 0 ) {
			$cron_label = 'اجرای کاملاً سالم ثبت نشده';
		}

		$danger_notices = array();
		$warning_notices = array();
		$info_notices = array();
		if ( ! $health_auth_ready ) {
			$danger_notices[] = 'پیکربندی احراز هویت Health برای Connection فعلی آماده نیست؛ فعال بودن Connection، آمادگی مالکیت Multi-Connection و کد امنیتی X-SEC را در «اتصال و لایسنس» بررسی کنید.';
		}
		if ( ! $versions_ok ) {
			$danger_notices[] = sprintf( 'حداقل نسخه‌های پشتیبانی‌شده MoboCore رعایت نشده است: WordPress 5.8+، PHP 7.4+ و WooCommerce 8.2+ لازم است. وضعیت فعلی: WP %s، PHP %s، WC %s.', $wp_version ?: 'نامشخص', PHP_VERSION, $wc_version ?: 'نامشخص' );
		}
		if ( ! $schema_ok ) {
			$danger_notices[] = 'Schema دیتابیس MoboCore با نسخه فعلی افزونه آماده/هماهنگ نیست. قبل از اتکا به عملیات پس‌زمینه، وضعیت Upgrade/Schema باید بررسی شود.';
		}
		if ( 'failed' === $database_unicode_migration_status ) {
			$danger_notices[] = 'فرآیند تبدیل utf8mb4 در یک checkpoint متوقف شده است. خطای ثبت‌شده را بررسی و فقط پس از رفع علت، Resume کنید.';
		} elseif ( 'partial' === $database_unicode_migration_status ) {
			$warning_notices[] = 'تبدیل utf8mb4 تمام شد اما بعضی جدول‌ها تبدیل نشدند؛ جدول نتایج را بررسی کنید. همگام‌سازی با جایگزینی کاراکترهای ۴ بایتی ادامه دارد.';
		} elseif ( 'ready' === $database_unicode_migration_status ) {
			$warning_notices[] = 'تبدیل utf8mb4 نیمه‌کاره است و فقط با کلیک دستی ادامه پیدا می‌کند («تبدیل جدول بعدی»).';
		} elseif ( 'awaiting-config' === $database_unicode_migration_status ) {
			$warning_notices[] = 'تبدیل فیزیکی دیتابیس انجام شده اما wp-config.php/اتصال جاری هنوز utf8mb4 را اثبات نمی‌کند.';
		} elseif ( 'paused' === $database_unicode_migration_status ) {
			$warning_notices[] = 'فرآیند تبدیل utf8mb4 به‌صورت دستی Pause شده و checkpoint آن حفظ شده است.';
		} elseif ( $database_unicode_migration_active ) {
			$warning_notices[] = 'تبدیل utf8mb4 در حال اجرا است؛ Workerهای عادی تا پایان مرزهای DDL توسط barrier متوقف می‌مانند.';
		}
		if ( $cron_overdue || $cron_last_partial || ( $cron_last_failed && $cron_last_success <= 0 ) ) {
			$danger_notices[] = 'Runner زمان‌بندی‌شده سالم اثبات نشده است؛ Cron واقعی، نتیجه آخر Runner و آخرین اجرای کاملاً سالم را در صفحه Cron / Worker بررسی کنید.';
		} elseif ( ! $wp_cron_disabled || $cron_last_hit <= 0 || $cron_last_failed || $cron_last_success <= 0 || '' === $cron_last_status || 'never-run' === $cron_last_status ) {
			$warning_notices[] = 'پیکربندی یا سابقه اجرای Cron هنوز وضعیت ایده‌آل را اثبات نمی‌کند؛ جزئیات Cron / Worker را بررسی کنید.';
		}
		if ( ! $image_ok ) {
			$danger_notices[] = 'محیط پردازش تصویر آماده نیست؛ دسترسی نوشتن uploads و فعال بودن Imagick یا GD را بررسی کنید.';
		}
		if ( $storage_hard_failure ) {
			$danger_notices[] = 'یک خطای قطعی در سهمیه/نوشتن Storage تشخیص داده شده است؛ quota، inode و امکان نوشتن واقعی در uploads را بررسی کنید.';
		}
		if ( $failed_webhooks > 0 ) {
			$danger_notices[] = sprintf( '%s وب‌هوک در وضعیت ناموفق فعلی وجود دارد.', number_format_i18n( $failed_webhooks ) );
		}
		if ( $failed_images > 0 ) {
			$danger_notices[] = sprintf( '%s کار تصویر در وضعیت ناموفق فعلی وجود دارد.', number_format_i18n( $failed_images ) );
		}
		if ( $pending_webhooks > 0 ) {
			$info_notices[] = sprintf( '%s وب‌هوک در صف انتظار است. Pending به‌تنهایی خطا نیست و باید همراه سن/روند صف تفسیر شود.', number_format_i18n( $pending_webhooks ) );
		}

		if ( 'danger' === $observability['moneyState'] ) {
			$danger_notices[] = 'قرارداد پولی Connection ناسازگار است: ' . $observability['moneyDetail'];
		}
		foreach ( $observability['networkIssues'] as $network_issue ) {
			$network_notice = 'وابستگی شبکه: ' . $network_issue['detail'];
			if ( 'warning' === $network_issue['state'] ) {
				$warning_notices[] = $network_notice;
			} else {
				$danger_notices[] = $network_notice;
			}
		}
		if ( ! empty( $observability['stalled'] ) ) {
			$danger_notices[] = sprintf( 'پردازش Stalled تشخیص داده شد: %s کار در انتظار است و Runner از زمان‌بندی عقب افتاده است.', number_format_i18n( $observability['pendingWork'] ) );
		} elseif ( $observability['pendingWork'] > 0 ) {
			$info_notices[] = sprintf( '%s کار در انتظار/بازیابی ثبت شده است؛ تا وقتی Runner overdue نیست، این عدد به‌تنهایی Stalled محسوب نمی‌شود.', number_format_i18n( $observability['pendingWork'] ) );
		}
		if ( ! $quota_available ) {
			$info_notices[] = 'سهمیه اکانت هاست از این درخواست non-blocking قابل تشخیص نیست؛ ظرفیت Filesystem سرور در جزئیات فنی جدا نمایش داده می‌شود و با quota اکانت یکی نیست.';
		}

		$state = ! empty( $danger_notices ) ? 'danger' : ( ! empty( $warning_notices ) ? 'warning' : 'success' );
		$state_label = 'success' === $state ? 'مناسب' : ( 'warning' === $state ? 'نیازمند بررسی تنظیمات' : 'نیازمند اقدام' );
		?>
		<div class="mobo-card mobo-ux-primary-card">
			<div class="mobo-admin-card-heading"><div><h2 class="mobo-admin-section-title">سلامت فنی سایت</h2><p>فقط پیش‌نیازها و خرابی‌هایی که می‌توانند روی اجرای MoboCore اثر عملیاتی بگذارند در این خلاصه آمده‌اند.</p></div><span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $state_label ); ?></span></div>
			<div class="mobo-ux-summary-grid mobo-ux-summary-grid--3">
				<?php $this->status_box( 'سازگاری نسخه‌ها', $versions_ok ? 'آماده' : 'نیازمند بررسی' ); ?>
				<?php $this->status_box( 'Schema دیتابیس', $schema_ok ? 'آماده' : 'نیازمند بررسی' ); ?>
				<?php $this->status_box( 'پیکربندی Health', $health_auth_ready ? 'احراز هویت آماده' : 'نیازمند تنظیم' ); ?>
				<?php $this->status_box( 'قرارداد پولی', $observability['moneyLabel'] ); ?>
				<?php $this->status_box( 'خطای وابستگی شبکه', $observability['networkIssueCount'] ); ?>
				<?php $this->status_box( 'کار در انتظار / Recovery', $observability['pendingWork'] ); ?>
				<?php $this->status_box( 'آخرین Sync موفق', $observability['lastSyncLabel'] ); ?>
				<?php $this->status_box( 'Cron / Worker', $cron_label ); ?>
				<?php $this->status_box( 'پردازش تصویر', $image_ok ? 'آماده' : 'نیازمند بررسی' ); ?>
				<?php $this->status_box( 'وب‌هوک ناموفق', $failed_webhooks ); ?>
				<?php $this->status_box( 'تصویر ناموفق', $failed_images ); ?>
				<?php $this->status_box( 'وب‌هوک در انتظار', $pending_webhooks ); ?>
				<?php $this->status_box( 'سهمیه خالی اکانت هاست', $quota_label ); ?>
			</div>
			<?php foreach ( $danger_notices as $notice ) : ?><div class="mobo-message mobo-message-error"><?php echo esc_html( $notice ); ?></div><?php endforeach; ?>
			<?php foreach ( $warning_notices as $notice ) : ?><div class="mobo-message mobo-message-warning"><?php echo esc_html( $notice ); ?></div><?php endforeach; ?>
			<?php foreach ( $info_notices as $notice ) : ?><div class="mobo-message mobo-message-info"><?php echo esc_html( $notice ); ?></div><?php endforeach; ?>
			<?php if ( empty( $danger_notices ) && empty( $warning_notices ) ) : ?><div class="mobo-message mobo-message-success">در حال حاضر در پیش‌نیازهای فنی بررسی‌شده موردی نیازمند اقدام دیده نشد.</div><?php endif; ?>
			<div class="mobo-note"><strong>نکته:</strong> وضعیت «پیکربندی Health» فقط آمادگی محلی احراز هویت/Connection را نشان می‌دهد و reachability شبکه از Portal را اثبات نمی‌کند. GET <code dir="ltr">/health</code> فقط وضعیت را به‌صورت read-only گزارش می‌کند؛ Recovery/اجرای bounded worker از endpoint جداگانه POST <code dir="ltr">/heartbeat</code> انجام می‌شود و نتیجه Heartbeat معیار دسترس‌پذیری خود Health نیست.</div>
		</div>
		<?php
		/* The manual utf8mb4 card lives in this report: keep it open while a pre-check
		 * result is waiting for the start step or a migration needs the next action,
		 * so the pre-check notice ("read the result in the utf8mb4 section") points
		 * at something the admin can actually see. */
		$health_details_open = $database_unicode_migration_active
			|| in_array( $database_unicode_migration_status, array( 'ready', 'paused', 'failed', 'partial', 'awaiting-config' ), true )
			|| ( ! $database_unicode_ok && class_exists( 'Mobo_Core_Database_Unicode_Migration' ) && Mobo_Core_Database_Unicode_Migration::precheck_is_fresh() );
		?>
		<details class="mobo-admin-details mobo-ux-secondary-details"<?php echo $health_details_open ? ' open' : ''; ?>>
			<summary>گزارش فنی کامل و جزئیات تشخیصی</summary>
			<div class="mobo-admin-details__body"><?php $this->render_health_technical_tab( $local ); ?></div>
		</details>
		<?php
	}

	/**
	 * Database Unicode (utf8mb4) guard + manual migration view model shared by the
	 * Health summary and the technical report (which renders the migration card).
	 *
	 * @param array $local Health report.
	 * @return array Positional: unicode, ok, message, migration, status, active, label.
	 */
	private function health_database_unicode_view( $local ) {
		$local = is_array( $local ) ? $local : array();
		$database_unicode = isset( $local['databaseUnicode'] ) && is_array( $local['databaseUnicode'] ) ? $local['databaseUnicode'] : array();
		$database_unicode_ok = ! empty( $database_unicode['ready'] );
		$database_unicode_message = isset( $database_unicode['message'] ) ? sanitize_text_field( (string) $database_unicode['message'] ) : '';
		$database_unicode_migration = isset( $local['databaseUnicodeMigration'] ) && is_array( $local['databaseUnicodeMigration'] )
			? $local['databaseUnicodeMigration']
			: ( class_exists( 'Mobo_Core_Database_Unicode_Migration' ) ? Mobo_Core_Database_Unicode_Migration::get_status() : array( 'status' => 'unavailable' ) );
		$database_unicode_migration_status = sanitize_key( (string) ( $database_unicode_migration['status'] ?? 'idle' ) );
		$database_unicode_migration_active = ! empty( $database_unicode_migration['active'] );
		$database_unicode_migration_labels = array(
			'idle' => 'شروع نشده',
			'draining' => 'در حال Drain Workerها',
			'migrating' => 'در حال تبدیل',
			'paused' => 'متوقف‌شده با حفظ Checkpoint',
			'failed' => 'متوقف‌شده به‌علت خطا',
			'awaiting-config' => 'منتظر اصلاح wp-config.php',
			'ready' => 'آماده تبدیل جدول بعدی (دستی)',
			'partial' => 'کامل با خطا در بعضی جدول‌ها',
			'completed' => 'کامل و تأییدشده',
			'unavailable' => 'در دسترس نیست',
		);
		$database_unicode_migration_label = isset( $database_unicode_migration_labels[ $database_unicode_migration_status ] )
			? $database_unicode_migration_labels[ $database_unicode_migration_status ]
			: $database_unicode_migration_status;
		return array( $database_unicode, $database_unicode_ok, $database_unicode_message, $database_unicode_migration, $database_unicode_migration_status, $database_unicode_migration_active, $database_unicode_migration_label );
	}

	private function render_health_technical_tab( $local = null ) {
		if ( ! is_array( $local ) ) {
			$reporter = new Mobo_Core_Health_Reporter();
			/* Keep wp-admin rendering non-blocking; full probes belong to explicit diagnostics. */
			$local = $reporter->build_report( true );
		}
		list( $database_unicode, $database_unicode_ok, $database_unicode_message, $database_unicode_migration, $database_unicode_migration_status, $database_unicode_migration_active, $database_unicode_migration_label ) = $this->health_database_unicode_view( $local );
		$image          = isset( $local['imageProcessing'] ) && is_array( $local['imageProcessing'] ) ? $local['imageProcessing'] : array();
		$cache_purge    = isset( $local['cachePurge'] ) && is_array( $local['cachePurge'] ) ? $local['cachePurge'] : array();
		$archive_cache_queue = isset( $cache_purge['archiveQueue'] ) && is_array( $cache_purge['archiveQueue'] ) ? $cache_purge['archiveQueue'] : array();
		$cache_warmup_queue = isset( $cache_purge['warmupQueue'] ) && is_array( $cache_purge['warmupQueue'] ) ? $cache_purge['warmupQueue'] : array();
		$cache_purge_status_labels = array(
			'success'   => 'موفق',
			'partial'   => 'نیمه‌موفق؛ حداقل یک Integration خطا داشته',
			'failed'    => 'ناموفق',
			'never_run' => 'هنوز اجرا نشده',
		);
		$cache_purge_status = isset( $cache_purge['status'] ) ? sanitize_key( (string) $cache_purge['status'] ) : 'never_run';
		$runtime_diagnostics = isset( $local['runtimeDiagnostics'] ) && is_array( $local['runtimeDiagnostics'] ) ? $local['runtimeDiagnostics'] : array();
		$runtime_counters    = isset( $runtime_diagnostics['counters'] ) && is_array( $runtime_diagnostics['counters'] ) ? $runtime_diagnostics['counters'] : array();
		$runtime_stages      = isset( $runtime_diagnostics['stages'] ) && is_array( $runtime_diagnostics['stages'] ) ? $runtime_diagnostics['stages'] : array();
		$runtime_queue       = isset( $runtime_diagnostics['queueHealth'] ) && is_array( $runtime_diagnostics['queueHealth'] ) ? $runtime_diagnostics['queueHealth'] : array();
		$runtime_recommendations = isset( $runtime_diagnostics['recommendations'] ) && is_array( $runtime_diagnostics['recommendations'] ) ? $runtime_diagnostics['recommendations'] : array();
		$runtime_runner      = isset( $runtime_diagnostics['runner'] ) && is_array( $runtime_diagnostics['runner'] ) ? $runtime_diagnostics['runner'] : array();
		$runtime_slow        = isset( $runtime_diagnostics['slowOperations'] ) && is_array( $runtime_diagnostics['slowOperations'] ) ? $runtime_diagnostics['slowOperations'] : array();
		$runtime_adaptive    = isset( $runtime_diagnostics['adaptiveTuning'] ) && is_array( $runtime_diagnostics['adaptiveTuning'] ) ? $runtime_diagnostics['adaptiveTuning'] : array();
		$runtime_adaptive_stability = isset( $runtime_adaptive['stability'] ) && is_array( $runtime_adaptive['stability'] ) ? $runtime_adaptive['stability'] : array();
		$runtime_adaptive_decisions = isset( $runtime_adaptive['decisions'] ) && is_array( $runtime_adaptive['decisions'] ) ? $runtime_adaptive['decisions'] : array();
		$runtime_budget      = isset( $runtime_diagnostics['adaptiveBudget'] ) && is_array( $runtime_diagnostics['adaptiveBudget'] ) ? $runtime_diagnostics['adaptiveBudget'] : array();
		$runtime_circuits    = isset( $runtime_diagnostics['circuitBreakers'] ) && is_array( $runtime_diagnostics['circuitBreakers'] ) ? $runtime_diagnostics['circuitBreakers'] : array();
		$runtime_scheduler   = isset( $runtime_diagnostics['lastDecision'] ) && is_array( $runtime_diagnostics['lastDecision'] ) ? $runtime_diagnostics['lastDecision'] : array();
		$runtime_scheduler_stages = isset( $runtime_diagnostics['schedulerStages'] ) && is_array( $runtime_diagnostics['schedulerStages'] ) ? $runtime_diagnostics['schedulerStages'] : array();
		$wp_cron_ok     = $this->is_wp_cron_disabled();
		$current_connection = $this->current_admin_connection();
		$security_code = $this->current_admin_security_code();
		$connection_table_exists = class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists();
		$enabled_connection_count = $connection_table_exists ? Mobo_Core_Connection_Repository::count_enabled() : 0;
		$connection_enabled = $connection_table_exists
			? ( is_array( $current_connection ) && ! empty( $current_connection['is_enabled'] ) )
			: true;
		$connection_runtime_ready = ! $connection_table_exists
			|| ( $enabled_connection_count > 0 && ( $enabled_connection_count <= 1 || Mobo_Core_Connection_Repository::multi_connection_runtime_ready() ) );
		$health_auth_ready = $connection_enabled && $connection_runtime_ready && '' !== $security_code && Mobo_Core_Settings::is_valid_security_code( $security_code );
		$portal_heartbeat = isset( $local['portalHeartbeat'] ) && is_array( $local['portalHeartbeat'] ) ? $local['portalHeartbeat'] : array();
		$php_diagnostics_url = wp_nonce_url( $this->admin_post_url( array( 'action' => 'mobo_core_php_diagnostics' ) ), 'mobo_core_php_diagnostics' );
		?>
		<div class="mobo-grid">
				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>گزارش سلامت و Heartbeat</h2>
						<p>Portal وضعیت را از Health به‌صورت Pull می‌خواند. Health فقط read-only است؛ Heartbeat مسیر جداگانه Recovery است و یک slice محدود از همان Runner مشترک را اجرا می‌کند.</p>
					</div>

					<div class="mobo-status-grid">
						<?php $this->status_box( 'مدل دریافت سلامت', 'Pull توسط Portal' ); ?>
						<?php $this->status_box( 'پیکربندی احراز هویت Health', $health_auth_ready ? 'آماده' : 'نیازمند بررسی Connection / X-SEC' ); ?>
						<?php $this->status_box( 'ارسال دوره‌ای افزونه', 'ندارد' ); ?>
						<?php $this->status_box( 'آخرین Heartbeat موفق', ! empty( $portal_heartbeat['lastSuccessAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $portal_heartbeat['lastSuccessAt'] ) ) : 'ثبت نشده' ); ?>
						<?php $this->status_box( 'نسخه افزونه', defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '—' ); ?>
					</div>

					<div class="mobo-field mobo-field-full">
						<label>Health — فقط گزارش وضعیت</label>
						<input type="text" readonly dir="ltr" value="<?php echo esc_attr( rest_url( 'mobo-core/v1/health' ) ); ?>" onclick="this.select();">
						<div class="mobo-help">GET احراز هویت‌شده با X-SEC و مالکیت Connection است. این endpoint وضعیت فعلی را گزارش می‌کند و Worker اجرا نمی‌کند. آماده بودن تنظیمات این صفحه به‌تنهایی reachability شبکه از Portal را اثبات نمی‌کند.</div>
					</div>

					<div class="mobo-field mobo-field-full">
						<label>Heartbeat — Recovery جداگانه</label>
						<input type="text" readonly dir="ltr" value="<?php echo esc_attr( rest_url( 'mobo-core/v1/heartbeat' ) ); ?>" onclick="this.select();">
						<div class="mobo-help">POST احراز هویت‌شده و no-cache است. Portal در صورت قدیمی شدن Cron می‌تواند آن را جداگانه برای اجرای bounded recovery فراخوانی کند؛ موفق یا ناموفق بودن Heartbeat به‌تنهایی وضعیت دسترس‌پذیری Health را تعیین نمی‌کند.</div>
					</div>
				</div>

				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>کارایی Runtime و تشخیص خودکار</h2>
						<p>این آمار سبک و تجمیعی است؛ برای هر Event رکورد جدا ساخته نمی‌شود. پنجره آماری حداکثر ۲۴ ساعت است و Front-end هزینه مستقیمی بابت آن ندارد.</p>
					</div>
					<div class="mobo-status-grid">
						<?php $this->status_box( 'Product No-op', absint( isset( $runtime_counters['product_noop'] ) ? $runtime_counters['product_noop'] : 0 ) ); ?>
						<?php $this->status_box( 'Product Save واقعی', absint( isset( $runtime_counters['product_save'] ) ? $runtime_counters['product_save'] : 0 ) ); ?>
						<?php $this->status_box( 'Variation No-op', absint( isset( $runtime_counters['variation_noop'] ) ? $runtime_counters['variation_noop'] : 0 ) ); ?>
						<?php $this->status_box( 'Variation Save واقعی', absint( isset( $runtime_counters['variation_save'] ) ? $runtime_counters['variation_save'] : 0 ) ); ?>
						<?php $this->status_box( 'Runner Run', absint( isset( $runtime_runner['runs'] ) ? $runtime_runner['runs'] : 0 ) ); ?>
						<?php $runtime_runner_avg = absint( isset( $runtime_runner['runs'] ) ? $runtime_runner['runs'] : 0 ) > 0 ? round( absint( isset( $runtime_runner['totalMs'] ) ? $runtime_runner['totalMs'] : 0 ) / absint( $runtime_runner['runs'] ) ) : 0; ?>
						<?php $this->status_box( 'میانگین Runner', absint( $runtime_runner_avg ) . ' ms' ); ?>
						<?php $runtime_webhook_health = isset( $runtime_queue['webhook'] ) && is_array( $runtime_queue['webhook'] ) ? $runtime_queue['webhook'] : array(); ?>
						<?php $runtime_image_health = isset( $runtime_queue['image'] ) && is_array( $runtime_queue['image'] ) ? $runtime_queue['image'] : array(); ?>
						<?php $runtime_parent_health = isset( $runtime_queue['parentFinalize'] ) && is_array( $runtime_queue['parentFinalize'] ) ? $runtime_queue['parentFinalize'] : array(); ?>
						<?php $this->status_box( 'Webhook Pending / Due', absint( isset( $runtime_webhook_health['pendingCount'] ) ? $runtime_webhook_health['pendingCount'] : 0 ) . ' / ' . absint( isset( $runtime_webhook_health['dueCount'] ) ? $runtime_webhook_health['dueCount'] : 0 ) ); ?>
						<?php $this->status_box( 'Image Pending / Due', absint( isset( $runtime_image_health['pending'] ) ? $runtime_image_health['pending'] : 0 ) . ' / ' . absint( isset( $runtime_image_health['due'] ) ? $runtime_image_health['due'] : 0 ) ); ?>
						<?php $this->status_box( 'Parent Finalize Pending / Due', absint( isset( $runtime_parent_health['pendingCount'] ) ? $runtime_parent_health['pendingCount'] : 0 ) . ' / ' . absint( isset( $runtime_parent_health['dueCount'] ) ? $runtime_parent_health['dueCount'] : 0 ) ); ?>
						<?php $this->status_box( 'Adaptive Tuning', ! empty( $runtime_adaptive['enabled'] ) ? ( isset( $runtime_adaptive['mode'] ) ? $runtime_adaptive['mode'] : 'active' ) : 'غیرفعال' ); ?>
						<?php $this->status_box( 'علت Profile تطبیقی', isset( $runtime_adaptive['reason'] ) ? $runtime_adaptive['reason'] : '—' ); ?>
						<?php $this->status_box( 'Adaptive Hold', count( isset( $runtime_adaptive_stability['heldStages'] ) && is_array( $runtime_adaptive_stability['heldStages'] ) ? $runtime_adaptive_stability['heldStages'] : array() ) ); ?>
						<?php $this->status_box( 'Adaptive Changed', count( isset( $runtime_adaptive_stability['changedStages'] ) && is_array( $runtime_adaptive_stability['changedStages'] ) ? $runtime_adaptive_stability['changedStages'] : array() ) ); ?>
						<?php $this->status_box( 'Budget Pool', absint( isset( $runtime_budget['distributableMs'] ) ? $runtime_budget['distributableMs'] : 0 ) . ' ms' ); ?>
						<?php $this->status_box( 'Fair Scheduler Slot', absint( isset( $runtime_scheduler['backgroundCapacity'] ) ? $runtime_scheduler['backgroundCapacity'] : 0 ) ); ?>
						<?php $this->status_box( 'Circuit Open/Degraded', absint( isset( $runtime_circuits['openCount'] ) ? $runtime_circuits['openCount'] : 0 ) ); ?>
						<?php $this->status_box( 'Circuit Half-open', absint( isset( $runtime_circuits['halfOpenCount'] ) ? $runtime_circuits['halfOpenCount'] : 0 ) ); ?>
					</div>

					<?php if ( ! empty( $runtime_adaptive['limits'] ) && is_array( $runtime_adaptive['limits'] ) ) : ?>
						<div class="mobo-help" style="margin-top:16px"><strong>ظرفیت فعلی Adaptive:</strong> <code dir="ltr"><?php echo esc_html( wp_json_encode( $runtime_adaptive['limits'], JSON_UNESCAPED_SLASHES ) ); ?></code></div>
					<?php endif; ?>

					<?php if ( ! empty( $runtime_adaptive_decisions ) ) : ?>
						<table class="widefat striped" style="margin-top:16px">
							<thead><tr><th>Adaptive Stage</th><th>Anchor</th><th>Ideal</th><th>Applied</th><th>Confidence</th><th>Cooldown</th><th>Decision</th></tr></thead>
							<tbody>
							<?php foreach ( $runtime_adaptive_decisions as $runtime_adaptive_stage => $runtime_adaptive_decision ) : ?>
								<tr>
									<td><code><?php echo esc_html( $runtime_adaptive_stage ); ?></code></td>
									<td><?php echo esc_html( absint( isset( $runtime_adaptive_decision['anchor'] ) ? $runtime_adaptive_decision['anchor'] : 0 ) ); ?></td>
									<td><?php echo esc_html( absint( isset( $runtime_adaptive_decision['ideal'] ) ? $runtime_adaptive_decision['ideal'] : 0 ) ); ?></td>
									<td><?php echo esc_html( absint( isset( $runtime_adaptive_decision['applied'] ) ? $runtime_adaptive_decision['applied'] : 0 ) ); ?></td>
									<td><?php echo esc_html( absint( isset( $runtime_adaptive_decision['confidencePermille'] ) ? $runtime_adaptive_decision['confidencePermille'] : 0 ) ); ?>/1000</td>
									<td><?php echo ! empty( $runtime_adaptive_decision['cooldownActive'] ) ? 'فعال' : '—'; ?></td>
									<td><code><?php echo esc_html( isset( $runtime_adaptive_decision['reason'] ) ? $runtime_adaptive_decision['reason'] : '—' ); ?></code></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>


					<?php if ( ! empty( $runtime_budget['activeStages'] ) && is_array( $runtime_budget['activeStages'] ) ) : ?>
						<table class="widefat striped" style="margin-top:16px">
							<thead><tr><th>Budget Stage</th><th>Backlog</th><th>Trend</th><th>Pressure</th><th>Weight</th><th>Budget</th></tr></thead>
							<tbody>
							<?php foreach ( $runtime_budget['activeStages'] as $runtime_budget_stage ) : ?>
								<tr>
									<td><code><?php echo esc_html( $runtime_budget_stage ); ?></code></td>
									<td><?php echo esc_html( absint( isset( $runtime_budget['backlog'][ $runtime_budget_stage ] ) ? $runtime_budget['backlog'][ $runtime_budget_stage ] : 0 ) ); ?></td>
									<td><?php echo esc_html( (int) ( isset( $runtime_budget['backlogTrend'][ $runtime_budget_stage ] ) ? $runtime_budget['backlogTrend'][ $runtime_budget_stage ] : 0 ) ); ?></td>
									<td><?php echo esc_html( absint( isset( $runtime_budget['pressurePermille'][ $runtime_budget_stage ] ) ? $runtime_budget['pressurePermille'][ $runtime_budget_stage ] : 0 ) ); ?>/1000</td>
									<td><?php echo esc_html( absint( isset( $runtime_budget['weights'][ $runtime_budget_stage ] ) ? $runtime_budget['weights'][ $runtime_budget_stage ] : 0 ) ); ?></td>
									<td><?php echo esc_html( absint( isset( $runtime_budget['stageBudgetMs'][ $runtime_budget_stage ] ) ? $runtime_budget['stageBudgetMs'][ $runtime_budget_stage ] : 0 ) ); ?> ms</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<?php $runtime_circuit_stages = isset( $runtime_circuits['stages'] ) && is_array( $runtime_circuits['stages'] ) ? $runtime_circuits['stages'] : array(); ?>
					<?php if ( ! empty( $runtime_circuit_stages ) ) : ?>
						<table class="widefat striped" style="margin-top:16px">
							<thead><tr><th>Circuit Stage</th><th>State</th><th>Failure Streak</th><th>Fail EWMA</th><th>Open Until</th><th>Reason</th></tr></thead>
							<tbody>
							<?php foreach ( $runtime_circuit_stages as $runtime_circuit_stage => $runtime_circuit ) : ?>
								<tr>
									<td><code><?php echo esc_html( $runtime_circuit_stage ); ?></code></td>
									<td><code><?php echo esc_html( isset( $runtime_circuit['state'] ) ? $runtime_circuit['state'] : 'closed' ); ?></code></td>
									<td><?php echo esc_html( absint( isset( $runtime_circuit['failureStreak'] ) ? $runtime_circuit['failureStreak'] : 0 ) ); ?></td>
									<td><?php echo esc_html( absint( isset( $runtime_circuit['failurePermille'] ) ? $runtime_circuit['failurePermille'] : 0 ) ); ?>/1000</td>
									<td><?php echo esc_html( absint( isset( $runtime_circuit['openUntil'] ) ? $runtime_circuit['openUntil'] : 0 ) > 0 ? wp_date( 'Y-m-d H:i:s', absint( $runtime_circuit['openUntil'] ) ) : '—' ); ?></td>
									<td><code><?php echo esc_html( isset( $runtime_circuit['reason'] ) ? $runtime_circuit['reason'] : '—' ); ?></code></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<?php if ( ! empty( $runtime_scheduler_stages ) ) : ?>
						<table class="widefat striped" style="margin-top:16px">
							<thead><tr><th>Fair Stage</th><th>Selected</th><th>Deferred</th><th>Last Selected</th></tr></thead>
							<tbody>
							<?php foreach ( $runtime_scheduler_stages as $runtime_scheduler_stage => $runtime_scheduler_stat ) : ?>
								<tr>
									<td><code><?php echo esc_html( $runtime_scheduler_stage ); ?></code></td>
									<td><?php echo esc_html( absint( isset( $runtime_scheduler_stat['selected'] ) ? $runtime_scheduler_stat['selected'] : 0 ) ); ?></td>
									<td><?php echo esc_html( absint( isset( $runtime_scheduler_stat['deferred'] ) ? $runtime_scheduler_stat['deferred'] : 0 ) ); ?></td>
									<td><?php echo esc_html( absint( isset( $runtime_scheduler_stat['lastSelectedAt'] ) ? $runtime_scheduler_stat['lastSelectedAt'] : 0 ) > 0 ? wp_date( 'Y-m-d H:i:s', absint( $runtime_scheduler_stat['lastSelectedAt'] ) ) : '—' ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<?php if ( ! empty( $runtime_stages ) ) : ?>
						<table class="widefat striped" style="margin-top:16px">
							<thead><tr><th>Stage</th><th>اجرا</th><th>میانگین</th><th>EWMA ms/item</th><th>P50/P95 Run</th><th>Latency Trend</th><th>Failure Streak</th><th>Processed</th><th>Deferred</th><th>Failed</th></tr></thead>
							<tbody>
							<?php foreach ( $runtime_stages as $runtime_stage_name => $runtime_stage ) : ?>
								<?php $runtime_stage_runs = absint( isset( $runtime_stage['runs'] ) ? $runtime_stage['runs'] : 0 ); ?>
								<tr>
									<td><code><?php echo esc_html( $runtime_stage_name ); ?></code></td>
									<td><?php echo esc_html( $runtime_stage_runs ); ?></td>
									<td><?php echo esc_html( $runtime_stage_runs > 0 ? round( absint( isset( $runtime_stage['totalMs'] ) ? $runtime_stage['totalMs'] : 0 ) / $runtime_stage_runs ) : 0 ); ?> ms</td>
									<td><?php echo esc_html( absint( isset( $runtime_stage['ewmaMsPerItem'] ) ? $runtime_stage['ewmaMsPerItem'] : ( isset( $runtime_stage['recentMsPerItem'] ) ? $runtime_stage['recentMsPerItem'] : 0 ) ) ); ?> ms</td>
									<td><?php echo esc_html( absint( isset( $runtime_stage['p50Ms'] ) ? $runtime_stage['p50Ms'] : 0 ) ); ?> / <?php echo esc_html( absint( isset( $runtime_stage['p95Ms'] ) ? $runtime_stage['p95Ms'] : 0 ) ); ?> ms</td>
									<td><?php echo esc_html( (int) ( isset( $runtime_stage['latencyTrendPermille'] ) ? $runtime_stage['latencyTrendPermille'] : 0 ) ); ?>/1000</td>
									<td><?php echo esc_html( absint( isset( $runtime_stage['failureStreak'] ) ? $runtime_stage['failureStreak'] : 0 ) ); ?></td>
									<td><?php echo esc_html( absint( isset( $runtime_stage['processed'] ) ? $runtime_stage['processed'] : 0 ) ); ?></td>
									<td><?php echo esc_html( absint( isset( $runtime_stage['deferred'] ) ? $runtime_stage['deferred'] : 0 ) ); ?></td>
									<td><?php echo esc_html( absint( isset( $runtime_stage['failed'] ) ? $runtime_stage['failed'] : 0 ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<?php if ( ! empty( $runtime_slow ) ) : ?>
						<details class="mobo-collapsible-log" style="margin-top:16px">
							<summary>آخرین عملیات کند (آستانه پیش‌فرض ۲۰۰۰ms)</summary>
							<div class="mobo-log-box"><pre dir="ltr"><?php echo esc_html( wp_json_encode( array_slice( $runtime_slow, 0, 8 ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre></div>
						</details>
					<?php endif; ?>

					<?php if ( ! empty( $runtime_recommendations ) ) : ?>
						<div class="mobo-help" style="margin-top:16px">
							<strong>پیشنهادهای خودکار:</strong>
							<ul style="margin:8px 18px 0">
							<?php foreach ( $runtime_recommendations as $runtime_recommendation ) : ?>
								<li><?php echo esc_html( isset( $runtime_recommendation['message'] ) ? $runtime_recommendation['message'] : '' ); ?></li>
							<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>

				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>PHP و پردازش تصویر</h2>
						<p>خلاصه امن این اطلاعات در پاسخ endpoint سلامت قرار می‌گیرد. جزئیات امن محیط PHP فقط برای مدیر واردشده قابل مشاهده است.</p>
					</div>
					<div class="mobo-status-grid">
						<?php $this->status_box( 'نسخه PHP', isset( $local['phpVersion'] ) ? $local['phpVersion'] : '—' ); ?>
						<?php $this->status_box( 'GD', ! empty( $image['gdLoaded'] ) ? ( ! empty( $image['gdWebp'] ) ? 'فعال با WebP' : 'فعال بدون WebP' ) : 'نصب نیست' ); ?>
						<?php $this->status_box( 'Imagick', ! empty( $image['imagickLoaded'] ) ? ( ! empty( $image['imagickWebp'] ) ? 'فعال با WebP' : 'فعال بدون WebP' ) : 'نصب نیست' ); ?>
						<?php $this->status_box( 'پشتیبانی WebP وردپرس', ! empty( $image['wordpressWebp'] ) ? 'دارد' : 'ندارد' ); ?>
						<?php $this->status_box( 'دسترسی نوشتن uploads', ! empty( $image['uploadsWritable'] ) ? 'دارد' : 'ندارد' ); ?>
					</div>
					<p><a class="button button-secondary" href="<?php echo esc_url( $php_diagnostics_url ); ?>" target="_blank" rel="noopener">مشاهده جزئیات امن محیط PHP</a></p>
					<div class="mobo-help">این صفحه دارای nonce کوتاه‌مدت است، فقط برای مدیر واردشده باز می‌شود و مسیرهای فایل/متغیرهای حساس phpinfo را نمایش نمی‌دهد.</div>
				</div>

				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>سلامت پاک‌سازی Cache محصول</h2>
						<p>آخرین نتیجه Purge هدفمند و نسخه Integrationهای Cache در پاسخ سلامت Portal قرار می‌گیرد. خطای Cache Sync محصول را متوقف نمی‌کند.</p>
					</div>

					<div class="mobo-status-grid">
						<?php $this->status_box( 'وضعیت آخرین Purge', isset( $cache_purge_status_labels[ $cache_purge_status ] ) ? $cache_purge_status_labels[ $cache_purge_status ] : $cache_purge_status ); ?>
						<?php $this->status_box( 'نسخه Mobo هنگام تست', ! empty( $cache_purge['pluginVersion'] ) ? $cache_purge['pluginVersion'] : '—' ); ?>
						<?php $this->status_box( 'آخرین تلاش', ! empty( $cache_purge['lastAttemptAt'] ) ? Mobo_Core_Iran_Date::format_value( $cache_purge['lastAttemptAt'] ) : '—' ); ?>
						<?php $this->status_box( 'آخرین موفقیت کامل', ! empty( $cache_purge['lastSuccessAt'] ) ? Mobo_Core_Iran_Date::format_value( $cache_purge['lastSuccessAt'] ) : '—' ); ?>
						<?php $this->status_box( 'محصول / URL', absint( isset( $cache_purge['productCount'] ) ? $cache_purge['productCount'] : 0 ) . ' / ' . absint( isset( $cache_purge['urlCount'] ) ? $cache_purge['urlCount'] : 0 ) ); ?>
						<?php $this->status_box( 'خطاهای متوالی', absint( isset( $cache_purge['consecutiveFailures'] ) ? $cache_purge['consecutiveFailures'] : 0 ) ); ?>
						<?php $this->status_box( 'فاصله Purge آرشیو', absint( isset( $archive_cache_queue['intervalMinutes'] ) ? $archive_cache_queue['intervalMinutes'] : 0 ) > 0 ? absint( $archive_cache_queue['intervalMinutes'] ) . ' دقیقه' : 'غیرفعال' ); ?>
						<?php $this->status_box( 'صف آرشیو URL / Product', absint( isset( $archive_cache_queue['pendingUrlCount'] ) ? $archive_cache_queue['pendingUrlCount'] : 0 ) . ' / ' . absint( isset( $archive_cache_queue['pendingProductCount'] ) ? $archive_cache_queue['pendingProductCount'] : 0 ) ); ?>
						<?php $this->status_box( 'موعد Purge آرشیو', ! empty( $archive_cache_queue['dueAt'] ) ? Mobo_Core_Iran_Date::format_value( $archive_cache_queue['dueAt'] ) : '—' ); ?>
						<?php $this->status_box( 'آخرین Purge آرشیو', ! empty( $archive_cache_queue['lastCompletedAt'] ) ? Mobo_Core_Iran_Date::format_value( $archive_cache_queue['lastCompletedAt'] ) : '—' ); ?>
						<?php $this->status_box( 'Warmup صفحه محصول', ! empty( $cache_warmup_queue['enabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
						<?php $this->status_box( 'صف Warmup / آماده اجرا', absint( isset( $cache_warmup_queue['pendingCount'] ) ? $cache_warmup_queue['pendingCount'] : 0 ) . ' / ' . absint( isset( $cache_warmup_queue['dueCount'] ) ? $cache_warmup_queue['dueCount'] : 0 ) ); ?>
						<?php $this->status_box( 'صف HOT / WARM', absint( isset( $cache_warmup_queue['hotPendingCount'] ) ? $cache_warmup_queue['hotPendingCount'] : 0 ) . ' / ' . absint( isset( $cache_warmup_queue['warmPendingCount'] ) ? $cache_warmup_queue['warmPendingCount'] : 0 ) ); ?>
						<?php $this->status_box( 'Warmup کل Connectionها / Due', absint( isset( $cache_warmup_queue['installation']['pendingCount'] ) ? $cache_warmup_queue['installation']['pendingCount'] : 0 ) . ' / ' . absint( isset( $cache_warmup_queue['installation']['dueCount'] ) ? $cache_warmup_queue['installation']['dueCount'] : 0 ) ); ?>
						<?php $this->status_box( 'قدیمی‌ترین آیتم / آیتم در حال اجرا', absint( isset( $cache_warmup_queue['oldestPendingAgeSeconds'] ) ? $cache_warmup_queue['oldestPendingAgeSeconds'] : 0 ) . ' ثانیه / ' . absint( isset( $cache_warmup_queue['claimedCount'] ) ? $cache_warmup_queue['claimedCount'] : 0 ) ); ?>
						<?php $this->status_box( 'آخرین Warmup موفق', absint( isset( $cache_warmup_queue['lastWarmed'] ) ? $cache_warmup_queue['lastWarmed'] : 0 ) . ' URL — ' . ( ! empty( $cache_warmup_queue['lastCompletedAt'] ) ? Mobo_Core_Iran_Date::format_value( $cache_warmup_queue['lastCompletedAt'] ) : '—' ) ); ?>
						<?php $this->status_box( 'Cache HIT تأییدشده / نتیجه نامشخص', absint( isset( $cache_warmup_queue['lastVerifiedHit'] ) ? $cache_warmup_queue['lastVerifiedHit'] : 0 ) . ' / ' . absint( isset( $cache_warmup_queue['lastUnverified'] ) ? $cache_warmup_queue['lastUnverified'] : 0 ) ); ?>
						<?php $this->status_box( 'Cold Skip / ظرفیت تکمیل / خطای Queue', absint( isset( $cache_warmup_queue['skippedColdTotal'] ) ? $cache_warmup_queue['skippedColdTotal'] : 0 ) . ' / ' . absint( isset( $cache_warmup_queue['capacityRejectedTotal'] ) ? $cache_warmup_queue['capacityRejectedTotal'] : 0 ) . ' / ' . absint( isset( $cache_warmup_queue['writeFailuresTotal'] ) ? $cache_warmup_queue['writeFailuresTotal'] : 0 ) ); ?>
					</div>

					<?php if ( ! empty( $cache_purge['lastError'] ) ) : ?>
						<div class="mobo-alert mobo-alert-error"><strong>آخرین خطای Cache Purge:</strong> <?php echo esc_html( $cache_purge['lastError'] ); ?></div>
					<?php endif; ?>

					<?php if ( ! empty( $cache_warmup_queue['lastError'] ) ) : ?>
						<div class="mobo-alert mobo-alert-error"><strong>آخرین خطای Product Cache Warmup:</strong> <?php echo esc_html( $cache_warmup_queue['lastError'] ); ?></div>
					<?php endif; ?>

					<?php if ( ! empty( $cache_purge['integrations'] ) && is_array( $cache_purge['integrations'] ) ) : ?>
						<details class="mobo-collapsible-log">
							<summary>جزئیات نسخه و نتیجه Integrationهای Cache</summary>
							<div class="mobo-log-box"><pre dir="ltr"><?php echo esc_html( wp_json_encode( $cache_purge['integrations'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre></div>
						</details>
					<?php endif; ?>
				</div>

				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>وضعیت محلی فعلی</h2>
						<p>این مقادیر در پاسخ سلامت Portal نیز قرار می‌گیرند.</p>
					</div>

					<div class="<?php echo $wp_cron_ok ? 'mobo-alert mobo-alert-success' : 'mobo-alert mobo-alert-error'; ?>">
						<strong>DISABLE_WP_CRON:</strong>
						<?php if ( $wp_cron_ok ) : ?>درست است و WP-Cron داخلی غیرفعال شده است.<?php else : ?>تعریف نشده یا false است. در <code>wp-config.php</code> و قبل از خط پایان ویرایش‌ها قرار دهید: <code dir="ltr">define('DISABLE_WP_CRON', true);</code><?php endif; ?>
					</div>

					<div class="mobo-status-grid">
						<?php $this->status_box( 'WordPress / WooCommerce', ( isset( $local['wordpressVersion'] ) ? $local['wordpressVersion'] : '—' ) . ' / ' . ( ! empty( $local['wooCommerceVersion'] ) ? $local['wooCommerceVersion'] : '—' ) ); ?>
						<?php $this->status_box( 'DB / Schema نسخه افزونه', ( ! empty( $local['pluginDatabaseVersion'] ) ? $local['pluginDatabaseVersion'] : '—' ) . ' / ' . ( ! empty( $local['databaseSchemaVersion'] ) ? $local['databaseSchemaVersion'] : '—' ) ); ?>
						<?php $this->status_box( 'آمادگی Schema', ! empty( $local['databaseSchemaReady'] ) ? 'آماده' : 'نیازمند بررسی' ); ?>
						<?php $this->status_box( 'سازگاری Unicode دیتابیس', $database_unicode_ok ? 'آماده (utf8mb4)' : 'ناسازگار / نیازمند اصلاح' ); ?>
						<?php $this->status_box( 'دیباگ وردپرس', ! empty( $local['wpDebug'] ) ? 'فعال' : 'غیرفعال' ); ?>
						<?php $this->status_box( 'نمایش خطا روی سایت', ! empty( $local['wpDebugDisplay'] ) ? 'فعال' : 'غیرفعال' ); ?>
						<?php $this->status_box( 'محدودیت حافظه PHP', isset( $local['phpMemoryLimitRaw'] ) ? $local['phpMemoryLimitRaw'] : '—' ); ?>
						<?php $this->status_box( 'سهمیه خالی اکانت هاست', ! empty( $local['accountDiskQuotaUnlimited'] ) ? 'نامحدود' : ( isset( $local['accountDiskFreePercent'] ) && null !== $local['accountDiskFreePercent'] ? $local['accountDiskFreePercent'] . '%' : 'قابل تشخیص نیست' ) ); ?>
						<?php $this->status_box( 'فضای خالی Filesystem سرور', isset( $local['filesystemDiskFreePercent'] ) && null !== $local['filesystemDiskFreePercent'] ? $local['filesystemDiskFreePercent'] . '%' : '—' ); ?>
						<?php $this->status_box( 'وب‌هوک‌های در صف', isset( $local['pendingWebhookJobs'] ) ? absint( $local['pendingWebhookJobs'] ) : 0 ); ?>
						<?php $this->status_box( 'وب‌هوک‌های ناموفق', isset( $local['failedWebhookJobs'] ) ? absint( $local['failedWebhookJobs'] ) : 0 ); ?>
						<?php $this->status_box( 'تصویرهای ناموفق', isset( $local['failedImageJobs'] ) ? absint( $local['failedImageJobs'] ) : 0 ); ?>
						<?php $this->status_box( 'حالت اجرای کران', isset( $local['cronMode'] ) ? $local['cronMode'] : '—' ); ?>
					</div>
					<div class="mobo-help">عدد Filesystem ظرفیت کل Filesystem قابل مشاهده برای PHP است و ممکن است سهمیه واقعی اکانت هاست شما نباشد. برای تصمیم quota فقط فیلد سهمیه اکانت معتبر است.</div>
					<?php if ( ! $database_unicode_ok ) : ?>
						<div class="mobo-alert mobo-alert-error" style="margin-top:12px"><strong>Unicode دیتابیس:</strong> <?php echo esc_html( '' !== $database_unicode_message ? $database_unicode_message : 'حداقل utf8mb4 در تمام لایه‌های لازم تأیید نشده است.' ); ?></div>
					<?php endif; ?>
					<?php if ( ! empty( $database_unicode ) ) : ?>
						<details class="mobo-collapsible-log" style="margin-top:12px">
							<summary>جزئیات فنی سازگاری Unicode دیتابیس</summary>
							<div class="mobo-log-box"><pre dir="ltr"><?php echo esc_html( wp_json_encode( $database_unicode, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre></div>
						</details>
					<?php endif; ?>

					<div class="mobo-card" style="margin-top:16px">
						<div class="mobo-card-head">
							<h3>تبدیل کنترل‌شده دیتابیس به utf8mb4</h3>
							<p>این عملیات فقط با اقدام صریح مدیر شروع می‌شود، Workerهای Mobo را با barrier متوقف می‌کند و هر بار حداکثر یک مرز DDL را انجام می‌دهد.</p>
						</div>
						<div class="mobo-status-grid">
							<?php $this->status_box( 'وضعیت Migration', $database_unicode_migration_label ?: '—' ); ?>
							<?php $this->status_box( 'جدول‌های تبدیل‌شده / کل', absint( $database_unicode_migration['convertedTables'] ?? 0 ) . ' / ' . absint( $database_unicode_migration['totalTables'] ?? 0 ) ); ?>
							<?php $this->status_box( 'جدول‌های باقی‌مانده', absint( $database_unicode_migration['remainingTables'] ?? 0 ) ); ?>
							<?php $this->status_box( 'آخرین جدول کامل‌شده', ! empty( $database_unicode_migration['lastCompletedTable'] ) ? (string) $database_unicode_migration['lastCompletedTable'] : '—' ); ?>
						</div>

						<?php $this->render_unicode_conversion_panel( $database_unicode_migration ); ?>

						<?php if ( ! empty( $database_unicode_migration['lastError'] ) ) : ?>
							<div class="mobo-alert mobo-alert-error" style="margin-top:12px"><strong>آخرین وضعیت/خطا:</strong> <?php echo esc_html( (string) $database_unicode_migration['lastError'] ); ?></div>
						<?php endif; ?>

						<div class="mobo-actions" style="margin-top:12px">
							<?php if ( $database_unicode_migration_active || 'ready' === $database_unicode_migration_status ) : ?>
								<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1">
									<input type="hidden" name="action" value="mobo_core_resume_database_unicode_migration">
									<?php wp_nonce_field( 'mobo_core_resume_database_unicode_migration', 'mobo_core_nonce' ); ?>
									<button type="submit" class="mobo-btn mobo-btn-primary">تبدیل جدول بعدی<?php echo ! empty( $database_unicode_migration['nextTable'] ) ? ' (' . esc_html( (string) $database_unicode_migration['nextTable'] ) . ')' : ''; ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1">
									<input type="hidden" name="action" value="mobo_core_pause_database_unicode_migration">
									<?php wp_nonce_field( 'mobo_core_pause_database_unicode_migration', 'mobo_core_nonce' ); ?>
									<button type="submit" class="mobo-btn mobo-btn-light">توقف (Pause)</button>
								</form>
							<?php elseif ( in_array( $database_unicode_migration_status, array( 'paused', 'failed', 'partial', 'awaiting-config' ), true ) ) : ?>
								<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1">
									<input type="hidden" name="action" value="mobo_core_resume_database_unicode_migration">
									<?php wp_nonce_field( 'mobo_core_resume_database_unicode_migration', 'mobo_core_nonce' ); ?>
									<button type="submit" class="mobo-btn mobo-btn-primary"><?php echo 'awaiting-config' === $database_unicode_migration_status ? 'بررسی مجدد بعد از اصلاح wp-config.php' : 'ادامه از Checkpoint'; ?></button>
								</form>
							<?php elseif ( ! $database_unicode_ok ) : ?>
								<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1">
									<input type="hidden" name="action" value="mobo_core_precheck_database_unicode_migration">
									<?php wp_nonce_field( 'mobo_core_precheck_database_unicode_migration', 'mobo_core_nonce' ); ?>
									<button type="submit" class="mobo-btn mobo-btn-light">پیش‌بررسی دیتابیس</button>
								</form>
								<?php if ( class_exists( 'Mobo_Core_Database_Unicode_Migration' ) && Mobo_Core_Database_Unicode_Migration::precheck_is_fresh() ) : ?>
								<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1" onsubmit="return confirm('تبدیل دیتابیس به utf8mb4 شروع شود؟ در حین تبدیل هر جدول، نوشتن روی همان جدول مسدود است.');">
									<input type="hidden" name="action" value="mobo_core_start_database_unicode_migration">
									<?php wp_nonce_field( 'mobo_core_start_database_unicode_migration', 'mobo_core_nonce' ); ?>
									<label style="display:block;margin-bottom:6px"><input type="checkbox" name="mobo_backup_confirmed" value="1" required> از دیتابیس نسخه پشتیبان (Backup) کامل گرفته‌ام.</label>
									<button type="submit" class="mobo-btn mobo-btn-primary">شروع تبدیل دستی utf8mb4 (اولین جدول)</button>
								</form>
								<?php endif; ?>
							<?php else : ?>
								<span class="mobo-admin-badge mobo-admin-badge--success">نیازی به Migration نیست</span>
							<?php endif; ?>
						</div>

						<details class="mobo-collapsible-log" style="margin-top:12px">
							<summary>جزئیات فنی Migration Unicode</summary>
							<div class="mobo-log-box"><pre dir="ltr"><?php echo esc_html( wp_json_encode( $database_unicode_migration, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre></div>
						</details>
					</div>
					<?php if ( ! empty( $local['databaseSchemaLastError'] ) ) : ?><details class="mobo-collapsible-log" style="margin-top:12px"><summary>جزئیات فنی آخرین خطای Schema</summary><div class="mobo-log-box"><pre dir="ltr"><?php echo esc_html( $local['databaseSchemaLastError'] ); ?></pre></div></details><?php endif; ?>

				</div>
			</div>

			<?php
			$this->guide_box(
				'راهنمای سلامت سایت',
				array(
					array( 'title' => 'دریافت خودکار', 'text' => 'Portal endpoint سلامت را با X-SEC به‌صورت read-only فراخوانی می‌کند. افزونه هیچ گزارش دوره‌ای به بیرون Push نمی‌کند و Health خودش Worker اجرا نمی‌کند.' ),
					array( 'title' => 'Heartbeat', 'text' => 'اگر Cron قدیمی باشد، Portal می‌تواند POST /heartbeat را جداگانه برای Recovery فراخوانی کند. Heartbeat یک slice محدود از همان Runner مشترک اجرا می‌کند.' ),
					array( 'title' => 'PHP Diagnostics', 'text' => 'خلاصه غیرحساس PHP، افزونه‌های PHP و قابلیت WebP در پاسخ سلامت قرار می‌گیرد. جزئیات امن محیط PHP فقط از لینک محافظت‌شده مدیر قابل مشاهده است.' ),
					array( 'title' => 'کران', 'text' => 'DISABLE_WP_CRON=true فقط از اجرای همزمان WP-Cron داخلی جلوگیری می‌کند؛ سالم بودن زمان‌بندی باید با hit/success واقعی Cron نیز اثبات شود.' ),
				),
				'اگر Schema، پردازش تصویر، quota/write، Cron یا صف‌های failed در وضعیت خطا هستند، همان مورد را در صفحه تخصصی مربوطه اصلاح کنید.'
			);
			$this->recommendation_box(
				'تنظیمات پیشنهادی سلامت سایت',
				array(
					array( 'setting' => 'فاصله Health Poll در Portal', 'value' => '۳۰۰ ثانیه', 'reason' => 'برای دریافت وضعیت نسبتاً تازه بدون Push افزونه؛ این Poll جای Cron یا Heartbeat Recovery نیست.' ),
					array( 'setting' => 'Timeout Health در Portal', 'value' => '۳۰ ثانیه', 'reason' => 'برای پاسخ read-only سلامت کافی است؛ Recovery از endpoint جداگانه Heartbeat انجام می‌شود.' ),
					array( 'setting' => 'DISABLE_WP_CRON', 'value' => 'true', 'reason' => 'در معماری فعلی اجرای اصلی صف‌ها با Cron واقعی/Runner کنترل‌شده انجام می‌شود و WP-Cron داخلی نباید مسیر موازی بسازد.' ),
				),
				'بعد از اصلاح هر پیش‌نیاز، این صفحه را دوباره باز کنید تا snapshot non-blocking تازه از وضعیت محلی ساخته شود.'
			);
			?>

		<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="mobo-inline-action-form">
			<input type="hidden" name="action" value="mobo_core_retry_failed_webhooks">
			<?php wp_nonce_field( 'mobo_core_retry_failed_webhooks', 'mobo_core_nonce' ); ?>
			<button type="submit" class="button button-secondary">برگرداندن وب‌هوک‌های ناموفق به صف</button>
		</form>
		<?php
	}
	/**
	 * Render real cron tab.
	 *
	 * @return void
	 */

	/** Manager-friendly Cron overview. */
	private function render_cron_tab() {
		$status = Mobo_Core_Cron_Runner::get_status();
		$self = class_exists( 'Mobo_Core_Self_Runner' ) ? Mobo_Core_Self_Runner::get_status() : array();
		$wp_cron_disabled = $this->is_wp_cron_disabled();
		$active = ! empty( $status['isActive'] );
		$overdue = ! empty( $status['isOverdue'] );
		$last_result = isset( $status['schedulerLastResult'] ) && is_array( $status['schedulerLastResult'] ) ? $status['schedulerLastResult'] : array();
		$last_status = isset( $last_result['status'] ) ? sanitize_key( (string) $last_result['status'] ) : '';
		$last_executed_at = absint( isset( $last_result['executedAt'] ) ? $last_result['executedAt'] : 0 );
		$last_healthy = 'ok' === $last_status && ! empty( $last_result['success'] );
		$last_partial = 'partial' === $last_status;
		$last_failed = $last_executed_at > 0
			&& ! in_array( $last_status, array( 'ok', 'paused-for-upgrade', 'baseline-suspended' ), true )
			&& ( empty( $last_result['success'] ) || $last_partial || 'lock-lost' === $last_status );

		$cron_result_label = 'هنوز اجرا ثبت نشده';
		if ( 'ok' === $last_status ) {
			$cron_result_label = 'سالم';
		} elseif ( 'partial' === $last_status ) {
			$cron_result_label = 'تکمیل با خطای مرحله';
		} elseif ( 'paused-for-upgrade' === $last_status ) {
			$cron_result_label = 'موقتاً متوقف برای بروزرسانی';
		} elseif ( 'baseline-suspended' === $last_status ) {
			$cron_result_label = 'تست محلی موقتاً متوقف';
		} elseif ( 'locked' === $last_status ) {
			$cron_result_label = 'هم‌زمانی / Worker دیگر فعال';
		} elseif ( '' !== $last_status ) {
			$cron_result_label = 'نیازمند بررسی (' . $last_status . ')';
		}

		$self_enabled = ! empty( $self['enabled'] );
		$self_kick = isset( $self['lastKickResult'] ) && is_array( $self['lastKickResult'] ) ? $self['lastKickResult'] : array();
		$self_run = isset( $self['lastRunResult'] ) && is_array( $self['lastRunResult'] ) ? $self['lastRunResult'] : array();
		$self_kick_status = isset( $self_kick['status'] ) ? sanitize_key( (string) $self_kick['status'] ) : '';
		$self_run_status = isset( $self_run['status'] ) ? sanitize_key( (string) $self_run['status'] ) : '';
		$self_problem_statuses = array( 'missing-token', 'request-failed', 'dispatch-timeout-backoff' );
		$self_problem = $self_enabled && (
			in_array( $self_kick_status, $self_problem_statuses, true )
			|| ( '' !== $self_run_status && ! in_array( $self_run_status, array( 'ok', 'paused-for-upgrade', 'baseline-suspended' ), true ) )
		);
		$self_backoff = $self_enabled && ( ! empty( $self['dispatchRetryAt'] ) && absint( $self['dispatchRetryAt'] ) > time() );
		$self_label = 'غیرفعال';
		if ( $self_enabled ) {
			if ( $self_problem ) {
				$self_label = $self_backoff ? 'فعال؛ در تأخیر خودکار پس از خطا' : 'فعال؛ نیازمند بررسی';
			} elseif ( 'ok' === $self_run_status ) {
				$self_label = 'فعال؛ آخرین اجرا سالم';
			} elseif ( ! empty( $self['dispatchPending'] ) ) {
				$self_label = 'فعال؛ در انتظار شروع داخلی';
			} else {
				$self_label = 'فعال؛ آماده';
			}
		}

		$state = 'success';
		$state_label = 'Cron اصلی سالم';
		if ( $last_failed || $last_partial ) {
			$state = 'danger';
			$state_label = 'پردازش صف نیازمند اقدام';
		} elseif ( ! $active || $overdue || ! $wp_cron_disabled || ! $last_healthy || $self_problem ) {
			$state = 'warning';
			$state_label = 'نیازمند بررسی';
		}
		?>
		<div class="mobo-card mobo-ux-primary-card">
			<div class="mobo-admin-card-heading"><div><h2 class="mobo-admin-section-title">Cron و Worker MoboCore</h2><p>Cron واقعی MoboCore مسیر اصلی اجرای صف‌هاست. Worker داخلی فقط برای ادامه سریع‌تر صف‌هاست و جای Cron واقعی هاست را نمی‌گیرد.</p></div><span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $state_label ); ?></span></div>
			<div class="mobo-ux-summary-grid">
				<?php $this->status_box( 'Cron واقعی MoboCore', $active ? ( $overdue ? 'اجرای قبلی ثبت شده؛ اکنون عقب افتاده' : 'اجرای اخیر ثبت شده' ) : 'تشخیص داده نشده' ); ?>
				<?php $this->status_box( 'نتیجه آخر پردازش', $cron_result_label ); ?>
				<?php $this->status_box( 'آخرین اجرای کاملاً سالم', ! empty( $status['lastSuccessAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $status['lastSuccessAt'] ) ) : 'ثبت نشده' ); ?>
				<?php $this->status_box( 'WP-Cron داخلی', $wp_cron_disabled ? 'خاموش؛ Job خارجی باید جدا تنظیم شود' : 'روشن / تنظیم نشده' ); ?>
				<?php $this->status_box( 'Worker داخلی', $self_label ); ?>
			</div>
			<?php if ( ! $active ) : ?>
				<div class="mobo-message mobo-message-warning">هیچ اجرای تازه‌ای از Cron واقعی MoboCore ثبت نشده است. دستور هاست را در بخش فنی تنظیم کنید؛ اجرای دستی این صفحه جای Cron هاست نیست.</div>
			<?php elseif ( $overdue ) : ?>
				<div class="mobo-message mobo-message-warning">اجرای Cron قبلاً ثبت شده، اما اجرای بعدی از فاصله مورد انتظار عقب افتاده است. Cron Job هاست و مقدار «فاصله مورد انتظار» را با زمان‌بندی واقعی تطبیق دهید.</div>
			<?php elseif ( $last_partial ) : ?>
				<div class="mobo-message mobo-message-error">Cron به سایت می‌رسد، اما آخرین پردازش صف با خطای یک یا چند مرحله تکمیل شده است. جزئیات فنی آخرین نتیجه، مرحله‌های ناموفق را مشخص می‌کند.</div>
			<?php elseif ( $last_failed ) : ?>
				<div class="mobo-message mobo-message-error">Cron به سایت رسیده اما آخرین پردازش صف سالم تمام نشده است. رسیدن Cron به‌تنهایی به معنی سلامت پردازش پس‌زمینه نیست.</div>
			<?php elseif ( ! $last_healthy ) : ?>
				<div class="mobo-message mobo-message-warning">رسیدن Cron ثبت شده است، اما هنوز یک پردازش زمان‌بندی‌شده کاملاً سالم برای وضعیت فعلی اثبات نشده است.</div>
			<?php elseif ( ! $wp_cron_disabled ) : ?>
				<div class="mobo-message mobo-message-warning">Cron اصلی MoboCore سالم است، اما WP-Cron داخلی هنوز خاموش نشده و ممکن است اجرای موازی بسازد.</div>
			<?php elseif ( $self_problem ) : ?>
				<div class="mobo-message mobo-message-warning">Cron اصلی MoboCore سالم است، اما Worker داخلی اخیراً خطا یا تأخیر خودکار داشته است. Cron واقعی همچنان مسیر اصلی است؛ جزئیات فنی Worker داخلی را بررسی کنید.</div>
			<?php else : ?>
				<div class="mobo-message mobo-message-success">Cron اصلی MoboCore و آخرین Runner سالم هستند. خاموش بودن WP-Cron داخلی فقط تنظیم محلی را ثابت می‌کند؛ افزونه نمی‌تواند از داخل سایت ثابت کند Cron خارجی <code dir="ltr">wp-cron.php</code> در هاست واقعاً ثبت شده است.</div>
			<?php endif; ?>
			<?php $this->render_cron_support_tools_box(); ?>
		</div>
		<details class="mobo-admin-details mobo-ux-secondary-details">
			<summary>دستورهای Cron، وضعیت Worker و تنظیمات تخصصی</summary>
			<div class="mobo-admin-details__body"><?php $this->render_cron_technical_tab(); ?></div>
		</details>
		<?php
	}

	private function render_cron_technical_tab() {
		$status         = Mobo_Core_Cron_Runner::get_status();
		$cron_endpoint  = isset( $status['cronEndpoint'] ) ? (string) $status['cronEndpoint'] : rest_url( 'mobo-core/v1/cron/run' );
		$script_path    = MOBO_CORE_PLUGIN_DIR . 'mobo-cron.php';
		$wp_cron_path   = ABSPATH . 'wp-cron.php';
		$cpanel_mobo_command       = '/usr/local/bin/php -q ' . $script_path;
		$directadmin_mobo_command  = '/usr/local/bin/php ' . $script_path;
		$cpanel_wp_command         = '/usr/local/bin/php -q ' . $wp_cron_path;
		$directadmin_wp_command    = '/usr/local/bin/php ' . $wp_cron_path;
		$wp_cron_disabled          = $this->is_wp_cron_disabled();
		$self_status    = class_exists( 'Mobo_Core_Self_Runner' ) ? Mobo_Core_Self_Runner::get_status() : array();
		$worker_url     = isset( $self_status['workerUrl'] ) ? (string) $self_status['workerUrl'] : '';
		$webhook_queue  = class_exists( 'Mobo_Core_Webhook_Queue' ) ? new Mobo_Core_Webhook_Queue() : null;
		$webhook_status = $webhook_queue ? $webhook_queue->get_status() : array();
		$table_timing   = isset( $webhook_status['tableTiming'] ) && is_array( $webhook_status['tableTiming'] ) ? $webhook_status['tableTiming'] : array();
		$file_timing    = isset( $webhook_status['fileTiming'] ) && is_array( $webhook_status['fileTiming'] ) ? $webhook_status['fileTiming'] : array();
		$next_cron_at   = isset( $status['nextEstimatedAt'] ) ? absint( $status['nextEstimatedAt'] ) : 0;
		$next_deferred  = 0;

		if ( ! empty( $table_timing['nextDeferredAt'] ) ) {
			$next_deferred = absint( $table_timing['nextDeferredAt'] );
		}

		if ( ! empty( $file_timing['nextDeferredAt'] ) && ( 0 === $next_deferred || absint( $file_timing['nextDeferredAt'] ) < $next_deferred ) ) {
			$next_deferred = absint( $file_timing['nextDeferredAt'] );
		}

		$next_webhook_at = 0;
		if ( ! empty( $webhook_status['hasDue'] ) ) {
			$next_webhook_at = $next_cron_at;
		} elseif ( ! empty( $webhook_status['hasPending'] ) && $next_deferred > 0 ) {
			$next_webhook_at = $next_cron_at > 0 ? max( $next_cron_at, $next_deferred ) : $next_deferred;
		}
		?>
		<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="cron">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>


			<div class="mobo-grid">
				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>اجرای خودکار / کران واقعی</h2>
						<p>مسیر اصلی و مطمئن برای اجرای صف‌ها، Cron واقعی با فایل PHP داخل پلاگین است. برای اجرای مطمئن، Cron موبو را هر ۱ دقیقه و wp-cron.php را هر ۵ دقیقه از Cron واقعی هاست اجرا کنید.</p>
					</div>

					<div class="mobo-status-grid">
						<?php $this->status_box( 'Cron واقعی MoboCore', ! empty( $status['isActive'] ) ? ( ! empty( $status['isOverdue'] ) ? 'Hit قبلی ثبت شده؛ اکنون عقب افتاده' : 'Hit اخیر ثبت شده' ) : ( ! empty( $status['driverActive'] ) ? 'Cron سرور تشخیص داده نشد؛ کارها با Heartbeat بک‌اند اجرا می‌شوند' : 'تشخیص داده نشده' ) ); ?>
						<?php $this->status_box( 'آخرین اجرای کران واقعی', ! empty( $status['lastHitAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $status['lastHitAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'آخرین اجرای کاملاً سالم Runner', ! empty( $status['lastSuccessAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $status['lastSuccessAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'اجرای بعدی Cron، تقریبی', ! empty( $status['lastHitAt'] ) && ! empty( $status['nextEstimatedAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $status['nextEstimatedAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'فاصله مورد انتظار Cron', ! empty( $status['expectedIntervalSeconds'] ) ? absint( $status['expectedIntervalSeconds'] ) . ' ثانیه' : '—' ); ?>
						<?php $this->status_box( 'فاصله واقعی دو اجرای آخر Cron', ! empty( $status['lastIntervalSeconds'] ) ? absint( $status['lastIntervalSeconds'] ) . ' ثانیه (delta=' . intval( isset( $status['lastIntervalDeltaSeconds'] ) ? $status['lastIntervalDeltaSeconds'] : 0 ) . 's)' : '—' ); ?>
						<?php
						$cron_schedule_status = isset( $status['scheduleStatus'] ) ? sanitize_key( (string) $status['scheduleStatus'] ) : 'unknown';
						$cron_schedule_text = '—';
						if ( 'on-time' === $cron_schedule_status ) { $cron_schedule_text = 'به‌موقع (±۵ ثانیه)'; }
						elseif ( 'late' === $cron_schedule_status ) { $cron_schedule_text = 'دیرتر از انتظار'; }
						elseif ( 'early' === $cron_schedule_status ) { $cron_schedule_text = 'زودتر از انتظار'; }
						if ( ! empty( $status['isOverdue'] ) ) { $cron_schedule_text .= ' / اجرای بعدی عقب افتاده'; }
						$this->status_box( 'وضعیت زمان‌بندی Cron', $cron_schedule_text );
						?>
						<?php $this->status_box( 'آخرین اجرای Worker داخلی', ! empty( $self_status['lastRunAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $self_status['lastRunAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'آخرین اجرای سالم Worker داخلی', ! empty( $self_status['lastRunSuccessAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $self_status['lastRunSuccessAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'آخرین Kick داخلی', ! empty( $self_status['lastKickAttemptAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $self_status['lastKickAttemptAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'اجرای داخلی', ! empty( $self_status['enabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
						<?php $cron_lock = isset( $status['lock'] ) && is_array( $status['lock'] ) ? $status['lock'] : array(); ?>
						<?php $this->status_box( 'Lease اجرای Cron', ! empty( $cron_lock['active'] ) ? 'فعال - ' . absint( isset( $cron_lock['remainingSeconds'] ) ? $cron_lock['remainingSeconds'] : 0 ) . ' ثانیه تا انقضا' : 'آزاد' ); ?>
						<?php $this->status_box( 'آخرین Heartbeat قفل', ! empty( $cron_lock['lastHeartbeatAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $cron_lock['lastHeartbeatAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'آخرین تلاش پردازش وب‌هوک', ! empty( $webhook_status['lastAttemptAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $webhook_status['lastAttemptAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'آخرین اجرای موفق صف وب‌هوک', ! empty( $webhook_status['lastSuccessAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $webhook_status['lastSuccessAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'آخرین فعالیت واقعی وب‌هوک', ! empty( $webhook_status['lastActivityAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $webhook_status['lastActivityAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'اجرای بعدی وب‌هوک، تقریبی', $next_webhook_at > 0 ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', $next_webhook_at ) : '—' ); ?>
						<?php $webhook_metrics_ok = ! empty( $webhook_status['tableMetricsAvailable'] ) && empty( $webhook_status['tableMetricsReadFailed'] ); ?>
						<?php $webhook_metric_text = $webhook_metrics_ok ? ( absint( isset( $webhook_status['pendingTableEvents'] ) ? $webhook_status['pendingTableEvents'] : 0 ) . ' / ' . absint( isset( $webhook_status['processingTableEvents'] ) ? $webhook_status['processingTableEvents'] : 0 ) . ' / ' . absint( isset( $webhook_status['dueTableEvents'] ) ? $webhook_status['dueTableEvents'] : 0 ) . ' / ' . absint( isset( $webhook_status['failedTableEvents'] ) ? $webhook_status['failedTableEvents'] : 0 ) ) : 'نامعتبر / خطای خواندن DB'; ?>
						<?php $this->status_box( 'وب‌هوک pending / processing / due / failed قابل اقدام', $webhook_metric_text ); ?>
						<?php $this->status_box( 'وب‌هوک failed تاریخی / superseded', $webhook_metrics_ok ? absint( isset( $webhook_status['historicalFailedTableEvents'] ) ? $webhook_status['historicalFailedTableEvents'] : 0 ) : '—' ); ?>
						<div class="mobo-help">failed قابل اقدام فقط آخرین failure حل‌نشده برای هر identity است؛ failed تاریخی/superseded صرفاً سابقه forensic است و با Retry Failed دوباره پخش نمی‌شود. اگر read جدول queue خطا بدهد، عدد صفر ساختگی نمایش داده نمی‌شود.</div>
						<?php $this->status_box( 'فایل‌های pending قدیمی', absint( isset( $webhook_status['pendingFiles'] ) ? $webhook_status['pendingFiles'] : 0 ) ); ?>
					</div>
					<div class="<?php echo $wp_cron_disabled ? 'mobo-alert mobo-alert-success' : 'mobo-alert mobo-alert-error'; ?>">
						<strong>وضعیت DISABLE_WP_CRON:</strong>
						<?php if ( $wp_cron_disabled ) : ?>WP-Cron داخلی خاموش است. توجه: این فقط از اجرای خودکار داخل درخواست‌های سایت جلوگیری می‌کند و وجود Cron Job خارجی برای <code dir="ltr">wp-cron.php</code> را اثبات نمی‌کند.<?php else : ?>تعریف نشده یا false است. در فایل <code>wp-config.php</code> و قبل از خط پایان ویرایش‌ها اضافه کنید: <code dir="ltr">define('DISABLE_WP_CRON', true);</code><?php endif; ?>
					</div>
					<div class="mobo-note">
						اگر Cron را با فایل <code dir="ltr">mobo-cron.php</code> تنظیم کرده‌اید، معیار اصلی «آخرین اجرای کران واقعی» است. «اجرای بعدی» فقط تخمین پلاگین بر اساس آخرین اجرا و فاصله مورد انتظار است؛ زمان دقیق بعدی در خود cPanel نگهداری می‌شود. اگر «آخرین اجرای کران واقعی» تغییر نمی‌کند، cron اصلاً فایل را اجرا نکرده یا قبل از لود وردپرس خطا می‌دهد.
					</div>


					<?php if ( ! empty( $status['schedulerLastResult'] ) && is_array( $status['schedulerLastResult'] ) ) : ?>
						<details class="mobo-collapsible-log"><summary>آخرین نتیجه Cron واقعی زمان‌بندی‌شده</summary><div class="mobo-log-box"><pre dir="ltr"><?php echo esc_html( wp_json_encode( $status['schedulerLastResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre></div></details>
					<?php endif; ?>

					<?php if ( ! empty( $status['lastResult'] ) && is_array( $status['lastResult'] ) ) : ?>
						<details class="mobo-collapsible-log"><summary>آخرین نتیجه Runner مشترک (هر منبع)</summary><div class="mobo-log-box"><pre dir="ltr"><?php echo esc_html( wp_json_encode( $status['lastResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre></div></details>
					<?php endif; ?>

					<?php if ( ! empty( $webhook_status['lastResult'] ) && is_array( $webhook_status['lastResult'] ) ) : ?>
						<details class="mobo-collapsible-log"><summary>آخرین نتیجه صف وب‌هوک</summary><div class="mobo-log-box"><pre dir="ltr"><?php echo esc_html( wp_json_encode( $webhook_status['lastResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre></div></details>
					<?php endif; ?>

					<?php if ( '' !== $worker_url ) : ?>
						<div class="mobo-field mobo-field-full">
							<label>Endpoint داخلی Worker</label>
							<input type="text" readonly dir="ltr" value="<?php echo esc_attr( $worker_url ); ?>" onclick="this.select();">
							<div class="mobo-help">این URL را معمولاً لازم نیست دستی صدا بزنید؛ پلاگین بعد از webhook خودش آن را non-blocking اجرا می‌کند.</div>
						</div>
					<?php endif; ?>

					<div class="mobo-field mobo-field-full">
						<label>مسیر فایل PHP برای Cron واقعی</label>
						<input type="text" readonly dir="ltr" value="<?php echo esc_attr( $script_path ); ?>" onclick="this.select();">
						<div class="mobo-help">اگر هاست فقط PHP Script قبول می‌کند، همین مسیر فایل را وارد کنید. این روش مسیر اصلی پیشنهادی است و به wget، curl و عملگرهای shell نیاز ندارد.</div>
					</div>

					<div class="mobo-field mobo-field-full">
						<label>mobo-cron.php برای cPanel — هر ۱ دقیقه</label>
						<textarea rows="2" readonly dir="ltr" onclick="this.select();"><?php echo esc_textarea( $cpanel_mobo_command ); ?></textarea>
					</div>
					<div class="mobo-field mobo-field-full">
						<label>mobo-cron.php برای DirectAdmin — هر ۱ دقیقه</label>
						<textarea rows="2" readonly dir="ltr" onclick="this.select();"><?php echo esc_textarea( $directadmin_mobo_command ); ?></textarea>
					</div>
					<div class="mobo-field mobo-field-full">
						<label>wp-cron.php برای cPanel — هر ۵ دقیقه</label>
						<textarea rows="2" readonly dir="ltr" onclick="this.select();"><?php echo esc_textarea( $cpanel_wp_command ); ?></textarea>
					</div>
					<div class="mobo-field mobo-field-full">
						<label>wp-cron.php برای DirectAdmin — هر ۵ دقیقه</label>
						<textarea rows="2" readonly dir="ltr" onclick="this.select();"><?php echo esc_textarea( $directadmin_wp_command ); ?></textarea>
						<div class="mobo-help">در cPanel از <code>-q</code> و در DirectAdmin بدون <code>-q</code> استفاده کنید. مسیر PHP ممکن است روی بعضی هاست‌ها متفاوت باشد.</div>
					</div>
					<div class="mobo-field mobo-field-full">
						<label>خط لازم در wp-config.php</label>
						<textarea rows="2" readonly dir="ltr" onclick="this.select();">define('DISABLE_WP_CRON', true);</textarea>
						<div class="mobo-help">این خط باید قبل از عبارت «پایان ویرایش» در wp-config.php قرار بگیرد. سپس اجرای wp-cron.php را به Cron واقعی هاست بسپارید.</div>
					</div>

					<?php if ( '' !== $cron_endpoint ) : ?>
						<div class="mobo-field mobo-field-full">
							<label>Endpoint HTTP جایگزین، بدون Secret</label>
							<input type="text" readonly dir="ltr" value="<?php echo esc_attr( $cron_endpoint ); ?>" onclick="this.select();">
							<div class="mobo-help">این endpoint فقط fallback است. برای احراز هویت، Cron Token را در Header <code dir="ltr"><?php echo esc_html( Mobo_Core_Rest_Controller::CRON_TOKEN_HEADER ); ?></code> بفرستید (Header <code dir="ltr">X-SEC</code> هم پذیرفته می‌شود). URL دارای <code dir="ltr">?token=</code> فقط برای سازگاری با Cron Jobهای قدیمی پشتیبانی می‌شود و چون Secret را وارد access log وب‌سرور و history می‌کند، توصیه نمی‌شود و در UI نمایش داده نمی‌شود. مسیر اصلی پیشنهادی همچنان اجرای مستقیم <code dir="ltr">mobo-cron.php</code> با PHP CLI است.</div>
						</div>
						<?php
						$cron_header_line = Mobo_Core_Rest_Controller::CRON_TOKEN_HEADER . ': YOUR_CRON_TOKEN';
						$cron_curl_header = 'curl -fsS -o /dev/null -H "' . $cron_header_line . '" "' . $cron_endpoint . '"';
						$cron_wget_header = 'wget -q -O /dev/null --header="' . $cron_header_line . '" "' . $cron_endpoint . '"';
						?>
						<div class="mobo-field mobo-field-full">
							<label>Cron HTTP با Header (پیشنهادی برای HTTP) — curl</label>
							<textarea rows="2" readonly dir="ltr" onclick="this.select();"><?php echo esc_textarea( $cron_curl_header ); ?></textarea>
						</div>
						<div class="mobo-field mobo-field-full">
							<label>Cron HTTP با Header (پیشنهادی برای HTTP) — wget</label>
							<textarea rows="2" readonly dir="ltr" onclick="this.select();"><?php echo esc_textarea( $cron_wget_header ); ?></textarea>
							<div class="mobo-help">به‌جای <code dir="ltr">YOUR_CRON_TOKEN</code> مقدار فیلد «توکن Cron / Worker» را قرار دهید. اگر Cron Job فعلی شما از <code dir="ltr">?token=</code> استفاده می‌کند همچنان کار می‌کند؛ برای خارج نگه داشتن Secret از access log، آن را با یکی از این دو دستور جایگزین کنید.</div>
						</div>
					<?php endif; ?>
				</div>

				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>راهنمای تنظیم Cron در هاست‌ها</h2>
						<p>دو Cron Job پیشنهاد می‌شود: اجرای فایل <code dir="ltr">mobo-cron.php</code> هر ۱ دقیقه برای صف‌های موبو و اجرای <code dir="ltr">wp-cron.php</code> هر ۵ دقیقه برای رویدادهای استاندارد وردپرس.</p>
					</div>
					<div class="mobo-note">
						<strong>cPanel با محدودیت «Only PHP scripts»:</strong> در بخش Cron Jobs حالت PHP Script را انتخاب کنید، زمان‌بندی را روی هر ۱ دقیقه قرار دهید و فقط مسیر فایل <code dir="ltr"><?php echo esc_html( $script_path ); ?></code> را وارد کنید. از <code>wget</code>، <code>curl</code>، <code>&gt;/dev/null</code> و <code>2&gt;&amp;1</code> استفاده نکنید.
					</div>
					<div class="mobo-note">
						<strong>cPanel با Command کامل:</strong> دستور <code dir="ltr"><?php echo esc_html( $cpanel_mobo_command ); ?></code> را با زمان‌بندی هر ۱ دقیقه ثبت کنید. اگر مسیر PHP فرق داشت، فقط مسیر PHP را اصلاح کنید.
					</div>
					<div class="mobo-note">
						<strong>DirectAdmin:</strong> از Advanced Features &gt; Cron Jobs یک job با دقیقه <code dir="ltr">*</code> یا حالت هر ۱ دقیقه بسازید. برای mobo-cron از دستور بدون <code>-q</code> نمایش‌داده‌شده در بالا استفاده کنید و برای wp-cron نیز دستور مخصوص DirectAdmin را هر ۵ دقیقه ثبت کنید.
					</div>
					<div class="mobo-note">
						<strong>Plesk یا هاست‌های عمومی:</strong> در Scheduled Tasks، نوع Task را PHP Script قرار دهید، زمان‌بندی را هر ۱ دقیقه بگذارید و فایل <code dir="ltr">mobo-cron.php</code> را از مسیر پلاگین انتخاب کنید. URL task فقط fallback است.
					</div>
				</div>

				<div class="mobo-card mobo-queue-preset-card" data-mobo-cron-preset-card>
					<div class="mobo-card-head">
						<h2>تنظیم سریع ظرفیت Runner بر اساس توان هاست</h2>
						<p>این presetها فقط ظرفیت/Timeoutهای Worker را داخل فرم تغییر می‌دهند و Cron Job واقعی هاست را ایجاد یا زمان‌بندی نمی‌کنند. برای ثبت نهایی، دکمه ذخیره پایین صفحه را بزنید.</p>
					</div>

					<div class="mobo-queue-preset-actions" aria-label="تنظیم سریع کران واقعی">
						<button type="button" class="button button-secondary" data-mobo-cron-preset="vps">VPS</button>
						<button type="button" class="button button-secondary" data-mobo-cron-preset="strong">هاست قوی</button>
						<button type="button" class="button button-secondary" data-mobo-cron-preset="medium">هاست متوسط</button>
						<button type="button" class="button button-secondary" data-mobo-cron-preset="weak">هاست ضعیف</button>
					</div>

					<div class="mobo-help" data-mobo-cron-preset-message>
						Preset مقدار «فاصله مورد انتظار Cron» را تغییر نمی‌دهد. آن فیلد باید دقیقاً با schedule واقعی Cron Job هاست (مثلاً ۶۰، ۱۲۰ یا ۳۰۰ ثانیه) هماهنگ باشد.
					</div>
				</div>

				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>تنظیمات Runner</h2>
						<p>هر Worker چند دور منصفانه از همه صف‌ها را تا پایان بودجه زمانی امن اجرا می‌کند؛ سپس در صورت باقی‌ماندن کار، Worker بعدی را non-blocking بیدار می‌کند.</p>
					</div>

					<div class="mobo-fields-grid">
						<?php $this->int_field( 'بودجه زمانی هر اجرا / ثانیه', 'mobo_core_real_cron_time_budget_seconds', 5, 55 ); ?>
						<?php $this->int_field( 'حداکثر step محصول در هر دور', 'mobo_core_real_cron_max_sync_steps', 1, 20 ); ?>
						<?php $this->int_field( 'حداکثر دور پردازش در هر اجرا', 'mobo_core_real_cron_max_rounds', 1, 500 ); ?>
						<?php $this->int_field( 'حاشیه امن پایان اجرا / ثانیه', 'mobo_core_real_cron_safety_margin_seconds', 1, 10 ); ?>
						<?php $this->int_field( 'حداقل TTL قفل Cron / ثانیه', 'mobo_core_real_cron_lock_ttl_seconds', 30, 600 ); ?>
						<?php $this->int_field( 'فاصله مورد انتظار Cron / ثانیه', 'mobo_core_real_cron_expected_interval_seconds', 60, 3600 ); ?>
						<?php $this->bool_field( 'فعال بودن اجرای داخلی', 'mobo_core_self_runner_enabled' ); ?>
						<?php $this->bool_field( 'ادامه خودکار تا خالی شدن صف', 'mobo_core_self_runner_continue_enabled' ); ?>
						<?php $this->int_field( 'حداقل فاصله Kick / ثانیه', 'mobo_core_self_runner_min_interval_seconds', 0, 60 ); ?>
						<?php $this->int_field( 'Timeout درخواست داخلی / ثانیه', 'mobo_core_self_runner_http_timeout_seconds', 1, 10 ); ?>
						<?php $this->bool_field( 'پردازش صف وب‌هوک در Runner', 'mobo_core_real_cron_process_webhooks' ); ?>
						<?php $this->bool_field( 'تنظیم تطبیقی ظرفیت Worker بر اساس سرعت/حافظه', 'mobo_core_adaptive_execution_enabled' ); ?>
						<?php $this->bool_field( 'پردازش فوری وب‌هوک هنگام دریافت', 'mobo_core_process_webhook_on_receive' ); ?>
						<?php $this->bool_field( 'دریافت payload اصلی از MoboCore', 'mobo_core_pull_payload_enabled' ); ?>
						<?php $this->int_field( 'Timeout دریافت payload / ثانیه', 'mobo_core_payload_pull_timeout_seconds', 5, 180 ); ?>
						<?php $this->int_field( 'Timeout درخواست‌های sync API / ثانیه', 'mobo_core_api_request_timeout_seconds', 5, 180 ); ?>
						<?php $this->int_field( 'حداکثر تلاش مجدد خطاهای موقت sync', 'mobo_core_transient_retry_max_try', 1, 50 ); ?>
						<?php $this->int_field( 'فاصله تلاش مجدد پس از انتظار MoboCore / ثانیه', 'mobo_core_waiting_for_portal_retry_delay_seconds', 10, 3600 ); ?>
					</div>
					<div class="mobo-help"><strong>فاصله مورد انتظار Cron</strong> فقط برای مانیتورینگ است و schedule هاست را تغییر نمی‌دهد. اگر Cron Job را از هر ۱ دقیقه به هر ۲ یا ۵ دقیقه تغییر می‌دهید، این مقدار را نیز به‌ترتیب روی ۱۲۰ یا ۳۰۰ ثانیه قرار دهید.</div>

					<?php $this->secret_field( 'توکن Cron / Worker', 'mobo_core_cron_token', 'خالی بگذارید تا مقدار قبلی حفظ شود. Self Runner و Endpointهای HTTP داخلی این Secret را از طریق X-SEC استفاده می‌کنند؛ اجرای مستقیم فایل mobo-cron.php با PHP CLI به توکن نیاز ندارد.' ); ?>

					<?php
					$this->guide_box(
						'راهنمای Runner و Cron',
						array(
							array( 'title' => 'اجرای داخلی', 'text' => 'بعد از webhook یا polling، پلاگین با Loopback و X-SEC تلاش می‌کند Worker داخلی را بیدار کند؛ dispatch موفق هنوز به‌تنهایی اجرای Worker را اثبات نمی‌کند، بنابراین Cron واقعی باید فعال باشد.' ),
							array( 'title' => 'Cron واقعی', 'text' => 'دو Cron Job پیشنهاد می‌شود: اجرای مستقیم mobo-cron.php هر ۱ دقیقه برای صف‌های موبو و اجرای wp-cron.php هر ۵ دقیقه برای رویدادهای استاندارد وردپرس.' ),
							array( 'title' => 'بودجه زمانی', 'text' => 'زمان و تعداد step را کوچک نگه دارید تا هاست مشتری timeout یا فشار CPU نگیرد.' ),
						),
						'روی هاست اشتراکی معمولاً تعداد step محصول ۱ تا ۳ و تعداد تصویر ۳ مقدار امن‌تری است. روی VPS می‌توان تدریجی افزایش داد.'
					);
					$this->recommendation_box(
						'تنظیمات پیشنهادی Runner و Cron',
						array(
							array( 'setting' => 'بودجه زمانی هر اجرا', 'value' => '۲۰ تا ۲۵ ثانیه روی هاست معمولی؛ ۳۰ تا ۴۵ ثانیه روی VPS', 'reason' => 'کمتر از این sync کند می‌شود؛ بیشتر از این احتمال timeout و مصرف CPU را بالا می‌برد.' ),
							array( 'setting' => 'step محصول در هر دور', 'value' => '۲ یا ۳ روی هاست اشتراکی؛ ۵ روی VPS', 'reason' => 'Runner چند دور منصفانه اجرا می‌کند؛ این عدد سهم محصول در هر دور است تا صف‌های دیگر گرسنه نمانند.' ),
							array( 'setting' => 'حداقل TTL قفل Cron', 'value' => '۱۲۰ ثانیه', 'reason' => 'Runner آن را با توجه به طولانی‌ترین Timeout شبکه افزایش می‌دهد، در طول اجرا Heartbeat می‌زند و پس از Crash در زمان محدود منقضی می‌شود.' ),
							array( 'setting' => 'Cron واقعی موبو', 'value' => 'mobo-cron.php هر ۱ دقیقه', 'reason' => 'اگر worker داخلی تکان نخورد، Cron واقعی صف webhook، محصول، واریانت و تصویر را جلو می‌برد.' ),
							array( 'setting' => 'اجرای داخلی و ادامه خودکار', 'value' => 'روشن', 'reason' => 'بعد از webhook تلاش می‌کند صف را سریع‌تر جلو ببرد، اما جایگزین Cron واقعی نیست.' ),
							array( 'setting' => 'پردازش فوری وب‌هوک هنگام دریافت', 'value' => 'خاموش', 'reason' => 'درخواست ورودی MoboCore نباید منتظر پردازش سنگین وردپرس بماند.' ),
							array( 'setting' => 'Timeout API و payload', 'value' => '۶۰ ثانیه', 'reason' => 'برای payloadهای بزرگ و هاست کند معقول است؛ مقادیر خیلی بالا worker را قفل می‌کند.' ),
						),
						'برای جلوگیری از pending ماندن webhookها، mobo-cron.php را ترجیحاً هر ۱ دقیقه اجرا کنید. برای رویدادهای استاندارد وردپرس نیز wp-cron.php را هر ۵ دقیقه اجرا کنید و DISABLE_WP_CRON را true بگذارید. اگر schedule واقعی MoboCore را به ۲ یا ۵ دقیقه افزایش می‌دهید، «فاصله مورد انتظار Cron» را هم دقیقاً با همان schedule هماهنگ کنید.'
					);
					?>
				</div>
			</div>

			<?php $this->save_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render categories tab.
	 *
	 * @return void
	 */
	private function render_categories_tab() {
		$recategorize_queue   = class_exists( 'Mobo_Core_Recategorize_Queue' ) ? new Mobo_Core_Recategorize_Queue() : null;
		$recategorize_status  = $recategorize_queue ? $recategorize_queue->get_status() : array();
		$recategorize_running = isset( $recategorize_status['status'] ) && 'running' === (string) $recategorize_status['status'];
		$mapping_enabled      = Mobo_Core_Settings::enabled( 'mobo_core_category_mapping_enabled', '1' );
		$mapping_required     = Mobo_Core_Settings::enabled( 'mobo_core_category_mapping_required', '0' );
		$auto_categories      = Mobo_Core_Settings::enabled( 'global_update_categories', '1' );
		$mapping_loaded_at    = absint( Mobo_Core_Settings::get( 'mobo_core_categories_mapping_loaded_at', 0 ) );
		$category_synced_at   = absint( Mobo_Core_Settings::get( 'mobo_core_categories_last_sync_at', 0 ) );
		$active_snapshot_at   = absint( Mobo_Core_Settings::get( 'mobo_core_category_mapping_active_snapshot_at', 0 ) );
		$last_received_at     = max( $mapping_loaded_at, $category_synced_at, $active_snapshot_at );
		$active_guids         = Mobo_Core_Settings::get( 'mobo_core_category_mapping_active_guids', array() );
		$active_count         = is_array( $active_guids ) && $active_snapshot_at > 0 ? count( array_filter( $active_guids, 'strlen' ) ) : 0;
		if ( $active_snapshot_at <= 0 && $last_received_at > 0 && class_exists( 'Mobo_Core_Category_Map' ) ) {
			$active_count = count( ( new Mobo_Core_Category_Map() )->list_mappings( 2000 ) );
		}
		?>
		<div class="mobo-card mobo-ux-intro-card">
			<div class="mobo-card-head">
				<h2>دسته‌بندی محصولات</h2>
				<p>فهرست دسته‌های منبع را دریافت کنید، سیاست دسته‌بندی را مشخص کنید و فقط مواردی را که لازم است به دسته‌های ووکامرس نگاشت کنید.</p>
			</div>
			<div class="mobo-ux-summary-grid mobo-ux-summary-grid--3">
				<?php $this->status_box( 'دسته‌های فعال منبع', $last_received_at > 0 ? number_format_i18n( $active_count ) : 'هنوز دریافت نشده' ); ?>
				<?php $this->status_box( 'آخرین دریافت موفق', $last_received_at > 0 ? $this->dashboard_checked_label( $last_received_at ) : 'هنوز انجام نشده' ); ?>
				<?php $this->status_box( 'نگاشت دستی', $mapping_enabled ? ( $mapping_required ? 'الزامی' : 'فعال و اختیاری' ) : 'غیرفعال' ); ?>
			</div>
			<div class="mobo-ux-steps mobo-ux-steps--compact">
				<span><b>۱</b> دریافت تازه‌ترین فهرست دسته‌ها</span>
				<span><b>۲</b> بررسی نتیجه و تکمیل نگاشت‌های لازم</span>
				<span><b>۳</b> ذخیره؛ در صورت نیاز اعمال روی محصولات موجود</span>
			</div>
			<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="mobo-inline-form" data-mobo-busy-submit="1">
				<input type="hidden" name="action" value="mobo_core_sync_categories">
				<?php wp_nonce_field( 'mobo_core_sync_categories', 'mobo_core_nonce' ); ?>
				<div class="mobo-actions"><button type="submit" class="mobo-btn mobo-btn-primary">دریافت تازه‌ترین فهرست دسته‌ها</button></div>
			</form>
		</div>

		<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="mobo-settings-form" data-mobo-category-policy-form="1" data-mobo-no-save-shortcut="1">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="categories">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>
			<div class="mobo-card">
				<div class="mobo-card-head"><h2>رفتار دسته‌بندی محصولات</h2><p>مشخص کنید MoboCore هنگام همگام‌سازی اجازه ساخت دسته، استفاده از نگاشت دستی و استفاده از دسته جایگزین را داشته باشد یا نه.</p></div>
				<?php $this->render_category_fallback_warning( false ); ?>
				<div class="mobo-fields-grid mobo-ux-settings-grid">
					<?php $this->bool_field( 'همگام‌سازی خودکار دسته‌بندی محصول', 'global_update_categories', false, '', 'اگر روشن باشد، دسته‌بندی محصولات موجود با وضعیت منبع هماهنگ می‌شود و در صورت نیاز دسته جدید ساخته می‌شود. اگر خاموش باشد، محصول موجود بدون نگاشت دستی تغییر دسته نمی‌دهد.' ); ?>
					<?php $this->bool_field( 'استفاده از نگاشت دستی', 'mobo_core_category_mapping_enabled', false, '', 'اگر روشن باشد، نگاشت‌هایی که در جدول پایین انتخاب می‌کنید بر دسته خودکار منبع اولویت دارند.' ); ?>
					<?php $this->bool_field( 'الزام نگاشت دستی', 'mobo_core_category_mapping_required', ! $mapping_enabled, ! $mapping_enabled ? 'برای استفاده از این گزینه ابتدا «استفاده از نگاشت دستی» را روشن کنید.' : '', 'اگر روشن باشد، دسته منبعی که نگاشت معتبر ندارد به‌صورت خودکار ساخته یا جایگزین نمی‌شود.' ); ?>
					<?php $this->category_dropdown_field( 'دسته جایگزین', 'mobo_default_category_id' ); ?>
				</div>
				<?php if ( ! $mapping_enabled && $mapping_required ) : ?>
					<div class="mobo-message mobo-message-warning">تنظیم قدیمی «الزام نگاشت دستی» هنوز روشن است، اما نگاشت دستی غیرفعال شده. با ذخیره این بخش، این حالت مبهم به‌صورت امن خاموش می‌شود.</div>
				<?php endif; ?>
				<div class="mobo-note" data-mobo-category-policy-note>
					<?php if ( $mapping_required ) : ?>در حالت نگاشت اجباری، دسته جایگزین جای نگاشت گمشده را نمی‌گیرد.<?php else : ?>دسته جایگزین فقط زمانی استفاده می‌شود که runtime نتواند دسته معتبری پیدا کند؛ محصول موجود بدون نتیجه معتبر بی‌دلیل جابه‌جا نمی‌شود.<?php endif; ?>
				</div>
			</div>
			<?php $this->save_button( 'ذخیره رفتار دسته‌بندی' ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="mobo-settings-form mobo-category-mapping-form" data-mobo-no-save-shortcut="1">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="categories">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>
			<?php $this->category_mapping_table(); ?>
			<?php $this->save_button( 'ذخیره نگاشت‌های این صفحه' ); ?>
		</form>

		<details id="mobo-category-apply-existing" class="mobo-admin-details mobo-ux-secondary-details" <?php echo $recategorize_running ? 'open' : ''; ?>>
			<summary><?php echo $recategorize_running ? 'اعمال تغییر دسته‌بندی روی محصولات موجود در حال اجراست' : 'اعمال نگاشت فعلی روی محصولات موجود'; ?></summary>
			<div class="mobo-admin-details__body"><?php $this->recategorize_queue_ui(); ?></div>
		</details>

		<details class="mobo-admin-details mobo-ux-secondary-details">
			<summary>راهنمای دسته‌بندی</summary>
			<div class="mobo-admin-details__body">
				<?php $this->category_sync_guide_box(); ?>
			</div>
		</details>
		<?php
	}

	/**
	 * Render pricing tab.
	 *
	 * @return void
	 */
	private function render_pricing_tab() {
		$reprice_queue = class_exists( 'Mobo_Core_Reprice_Queue' ) ? new Mobo_Core_Reprice_Queue() : null;
		$reprice_status = $reprice_queue ? $reprice_queue->get_status() : array();
		$reprice_state = isset( $reprice_status['status'] ) ? (string) $reprice_status['status'] : 'idle';
		$reprice_running = 'running' === $reprice_state;
		$reprice_cancelled = 'cancelled' === $reprice_state;
		$reprice_invalidated = 'invalidated' === $reprice_state;
		?>
		<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="pricing">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>
			<div class="mobo-card">
				<div class="mobo-card-head"><h2>سیاست قیمت فروش</h2><p>یک روش قیمت‌گذاری عمومی انتخاب کنید. سود اختصاصی هر واریانت، در صورت وجود، همیشه بالاترین اولویت را دارد.</p></div>
				<?php if ( $reprice_running ) : ?>
					<div class="mobo-message mobo-message-warning"><strong>سیاست قیمت موقتاً قفل است.</strong> اعمال قیمت روی کاتالوگ در حال اجراست؛ برای جلوگیری از قیمت‌گذاری یک اجرا با دو فرمول متفاوت، ابتدا عملیات را کامل یا متوقف کنید.</div>
				<?php elseif ( $reprice_cancelled ) : ?>
					<div class="mobo-message mobo-message-info">یک اجرای متوقف‌شده دارید. اگر سیاست قیمت را تغییر دهید، checkpoint قبلی دیگر قابل ادامه نیست و اجرای بعدی باید از ابتدا شروع شود.</div>
				<?php elseif ( $reprice_invalidated ) : ?>
					<div class="mobo-message mobo-message-warning">سیاست قیمت بعد از اجرای قبلی تغییر کرده است. برای یکدست شدن کل کاتالوگ، Reprice بعدی را از ابتدا اجرا کنید.</div>
				<?php endif; ?>
				<fieldset class="mobo-pricing-settings-fieldset" <?php disabled( $reprice_running, true ); ?> aria-disabled="<?php echo $reprice_running ? 'true' : 'false'; ?>">
					<?php $pricing_warning = $this->get_pricing_health_warning(); ?>
					<?php if ( '' !== $pricing_warning ) : ?><div class="mobo-message mobo-message-warning"><strong>نیاز به بررسی:</strong> <?php echo esc_html( $pricing_warning ); ?></div><?php endif; ?>
					<?php if ( $reprice_queue && ! $reprice_queue->price_updates_enabled() ) : ?>
						<div class="mobo-message mobo-message-info"><strong>به‌روزرسانی قیمت از منبع خاموش است.</strong> سوئیچ‌های «بروزرسانی قیمت» و «قیمت مقایسه‌ای» در تب محصول خاموش‌اند؛ Reprice عمومی قیمت‌های دستی را تغییر نمی‌دهد. فقط سود اختصاصی که صریحاً برای یک محصول/واریانت ذخیره کنید اعمال می‌شود.</div>
					<?php endif; ?>
					<?php $this->pricing_rules_ui(); ?>
					<div class="mobo-note">ترتیب اعمال قیمت: سود اختصاصی واریانت ← سود اختصاصی درصدی محصول/والد ← سیاست عمومی این صفحه. بعد از تغییر سیاست عمومی، برای اعمال روی قیمت‌های موجود از بخش «اعمال قیمت جدید روی محصولات موجود» استفاده کنید.</div>
					<?php $this->save_button( 'ذخیره سیاست قیمت' ); ?>
				</fieldset>
			</div>
		</form>

		<details class="mobo-admin-details mobo-ux-secondary-details" <?php echo ( $reprice_running || $reprice_cancelled || $reprice_invalidated || 'stalled' === $reprice_state || 'stalled' === (string) ( $reprice_status['health'] ?? '' ) ) ? 'open' : ''; ?>>
			<summary><?php echo $reprice_running ? 'اعمال قیمت جدید در حال اجراست' : ( $reprice_invalidated ? 'اعمال قیمت جدید باید از ابتدا اجرا شود' : 'اعمال قیمت جدید روی محصولات موجود' ); ?></summary>
			<div class="mobo-admin-details__body"><?php $this->reprice_queue_ui(); ?></div>
		</details>
		<details class="mobo-admin-details mobo-ux-secondary-details">
			<summary>راهنما و پیشنهادهای قیمت‌گذاری</summary>
			<div class="mobo-admin-details__body">
				<?php
				$this->guide_box( 'راهنمای قیمت‌گذاری', array(
					array( 'title' => 'اولویت سود اختصاصی واریانت', 'text' => 'اگر mobo_additional_price روی یک واریانت وجود داشته باشد، هم سود اختصاصی درصدی والد و هم قانون عمومی برای همان واریانت نادیده گرفته می‌شوند.' ),
					array( 'title' => 'سود اختصاصی محصول', 'text' => 'درصد اختصاصی سطح محصول روی خود محصول و واریانت‌های موبویی آن اعمال می‌شود، مگر واریانتی که سود اختصاصی خودش را داشته باشد.' ),
					array( 'title' => 'قوانین داینامیک', 'text' => 'بازه بر اساس price خام منبع انتخاب می‌شود، نه comparePrice. اولین قانون فعالِ منطبق اعمال می‌شود.' ),
					array( 'title' => 'اعمال مجدد قیمت', 'text' => 'برای تغییر سود لازم نیست کل محصول دوباره از منبع دریافت شود؛ Reprice از قیمت خام ذخیره‌شده استفاده می‌کند.' ),
				), 'قبل از اجرای عمومی، یک محصول ساده و یک محصول متغیر دارای سود اختصاصی واریانت را کنترل کنید.' );
				$this->recommendation_box( 'پیشنهادهای قیمت‌گذاری', array(
					array( 'setting' => 'نوع سود', 'value' => 'داینامیک برای کاتالوگ متنوع؛ ثابت برای فروشگاه ساده', 'reason' => 'قیمت‌های خیلی متفاوت معمولاً به سود یکسان پاسخ خوبی نمی‌دهند.' ),
					array( 'setting' => 'سود اختصاصی', 'value' => 'فقط برای استثناها', 'reason' => 'کنترل مرکزی قوانین را ساده نگه می‌دارد.' ),
					array( 'setting' => 'Reprice', 'value' => 'پس از هر تغییر مهم در فرمول', 'reason' => 'قیمت‌های قبلی را بدون دریافت مجدد محصول بروزرسانی می‌کند.' ),
				), '' );
				?>
			</div>
		</details>
		<?php
	}

	/**
	 * Render filters tab.
	 *
	 * @return void
	 */
	private function render_filters_tab() {
		$raw_exclusions = (string) Mobo_Core_Settings::get( 'mobo_core_excluded_product_urls', '' );
		$parsed = class_exists( 'Mobo_Core_Product_Exclusions' )
			? Mobo_Core_Product_Exclusions::parse_input( $raw_exclusions )
			: array( 'paths' => array(), 'inputCount' => 0, 'duplicateCount' => 0, 'invalidCount' => 0 );
		$excluded = isset( $parsed['paths'] ) && is_array( $parsed['paths'] ) ? $parsed['paths'] : array();
		$excluded_count = count( $excluded );
		$duplicate_count = isset( $parsed['duplicateCount'] ) ? absint( $parsed['duplicateCount'] ) : 0;
		$invalid_count = isset( $parsed['invalidCount'] ) ? absint( $parsed['invalidCount'] ) : 0;
		?>
		<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="filters">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>
			<div class="mobo-card">
				<div class="mobo-admin-card-heading">
					<div><h2 class="mobo-admin-section-title">استثناهای همگام‌سازی محصول</h2><p>برای محصولاتی که می‌خواهید محلی و دستی نگه دارید، URL یا مسیر منبع را وارد کنید. MoboCore پیش از هر تغییر خودکار از سمت منبع، این فهرست را دوباره بررسی می‌کند.</p></div>
					<span class="mobo-admin-badge mobo-admin-badge--<?php echo $excluded_count > 0 ? 'info' : 'success'; ?>"><?php echo esc_html( $excluded_count > 0 ? number_format_i18n( $excluded_count ) . ' استثنای فعال' : 'بدون استثنا' ); ?></span>
				</div>

				<?php if ( $excluded_count > 0 && class_exists( 'Mobo_Core_Product_Exclusions' ) ) : ?>
					<div class="mobo-message mobo-message-warning"><strong>محصولات مستثنا دیگر همگام نمی‌شوند.</strong> <?php echo esc_html( Mobo_Core_Product_Exclusions::exclusion_warning_fa() ); ?></div>
					<?php $suspicious = Mobo_Core_Product_Exclusions::suspicious_paths( $excluded ); ?>
					<?php if ( ! empty( $suspicious ) ) : ?>
						<div class="mobo-message mobo-message-warning"><strong>احتمال خرابی آدرس:</strong> <?php echo esc_html( 'نسخه‌های قبلی هنگام ذخیره، بخش فارسی کدشده (%XX) آدرس را حذف می‌کردند. این مسیرها ناقص به نظر می‌رسند؛ آدرس اصلی محصول را دوباره از مرورگر کپی و ذخیره کنید: ' . implode( '، ', array_slice( $suspicious, 0, 10 ) ) ); ?></div>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $invalid_count > 0 || $duplicate_count > 0 ) : ?>
					<div class="mobo-message mobo-message-warning"><strong>فهرست فعلی نیاز به پاک‌سازی دارد.</strong> <?php echo esc_html( sprintf( '%d خط نامعتبر و %d مورد تکراری شناسایی شد. با ذخیره بعدی فقط مسیرهای یکتای معتبر نگه داشته می‌شوند.', $invalid_count, $duplicate_count ) ); ?></div>
				<?php elseif ( 0 === $excluded_count ) : ?>
					<div class="mobo-message mobo-message-success">در حال حاضر هیچ محصولی از بروزرسانی‌های منبع مستثنی نشده است.</div>
				<?php endif; ?>

				<?php $this->textarea_field( 'URL یا مسیر محصول منبع', 'mobo_core_excluded_product_urls', "نمونه‌ها:
https://source.example/products/test-product/
/products/test-product
/1847

هر خط فقط یک محصول. دامنه، query string و اسلش انتهایی در تطبیق نهایی حذف می‌شوند." ); ?>

				<div class="mobo-ux-summary-grid mobo-ux-summary-grid--3">
					<?php $this->status_box( 'استثناهای فعال', number_format_i18n( $excluded_count ) ); ?>
					<?php $this->status_box( 'تغییرات خودکار منبع', $excluded_count > 0 ? 'برای این موارد متوقف' : 'بدون محدودیت' ); ?>
					<?php $this->status_box( 'هویت و سفارش موبو', 'همچنان فعال' ); ?>
				</div>

				<div class="mobo-message mobo-message-info"><strong>اثر این فهرست فقط توقف تغییرات خودکار از سمت منبع است.</strong> محصول موجود حذف یا Trash نمی‌شود و هویت موبویی آن نیز پاک نمی‌شود؛ بنابراین قابلیت‌هایی مثل تشخیص محصول موبو در Checkout یا ثبت سفارش می‌توانند همچنان آن را محصول موبویی بدانند.</div>
				<div class="mobo-note">افزودن استثنا روی کارهای از قبل صف‌شده‌ای که هنوز پردازش نشده‌اند هم اثر دارد، چون فهرست هنگام پردازش دوباره بررسی می‌شود. حذف استثنا محصول را برای کارهای بعدی دوباره مجاز می‌کند، اما تغییراتی که در زمان استثنا دریافت نشده‌اند خودکار جبران نمی‌شوند؛ برای دریافت دوباره آن تغییرات، همگام‌سازی یا Repair مناسب را اجرا کنید.</div>
			</div>
			<?php $this->save_button( 'ذخیره استثناها' ); ?>
		</form>

		<details class="mobo-admin-details mobo-ux-secondary-details">
			<summary>جزئیات رفتار استثناهای همگام‌سازی</summary>
			<div class="mobo-admin-details__body">
				<?php $this->guide_box( 'این سیاست دقیقاً چه کاری انجام می‌دهد؟', array(
					array( 'title' => 'تطبیق بر اساس مسیر', 'text' => 'URL کامل و path هر دو قابل قبول‌اند. runtime دامنه، query string و اسلش انتهایی را کنار می‌گذارد و مسیر استاندارد را به‌صورت exact مقایسه می‌کند؛ /0338 و /338 دو مورد متفاوت‌اند.' ),
					array( 'title' => 'مسیرهای تحت حفاظت', 'text' => 'Product Sync، ProductUpdated، Variant، Repair، Reprice، Recategorize، Parent Finalize و مسیرهای بازیابی/نوسازی تصویر پیش از تغییر محصول از همین فهرست مشترک استفاده می‌کنند.' ),
					array( 'title' => 'کارهای صف‌شده', 'text' => 'اگر یک محصول را پس از ساخته‌شدن صف و پیش از پردازش آن مستثنی کنید، Worker هنگام رسیدن به آن دوباره فهرست استثناها را بررسی می‌کند و نباید تغییر منبع را روی آن اعمال کند.' ),
					array( 'title' => 'هویت موبویی حفظ می‌شود', 'text' => 'Exclusion محصول را به non-Mobo تبدیل نمی‌کند و شناسه‌های مالکیت آن را پاک نمی‌کند. این گزینه برای توقف تغییرات خودکار منبع است، نه قطع کامل محصول از اکوسیستم موبو.' ),
					array( 'title' => 'بازفعال‌سازی و دریافت تغییرات عقب‌مانده', 'text' => 'با حذف مسیر، رویدادها و اجراهای بعدی دوباره اجازه تغییر محصول را دارند. برای جبران تغییرات از دست‌رفته در دوره استثنا، Sync یا Repair مناسب را دوباره اجرا کنید.' ),
				), 'برای حذف دائمی محصول از فروشگاه یا تبدیل آن به محصول غیرموبویی از این صفحه استفاده نکنید.' ); ?>
			</div>
		</details>
		<?php
	}

	/**
	 * Build a manager-facing queue/throughput snapshot for the selected Connection.
	 *
	 * The queue page must not infer health from configured batch sizes alone. This
	 * helper reads the same durable queue summaries used by the workers, keeps
	 * historical failures separate from actionable failures, and exposes Adaptive
	 * Tuning as an effective runtime layer above the administrator baseline.
	 *
	 * @return array
	 */
	private function get_queue_admin_snapshot() {
		$connection_table_exists = class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists();
		$connection              = $this->current_admin_connection();
		$connection_ready        = true;
		$connection_reason       = '';

		if ( $connection_table_exists ) {
			$enabled_count = Mobo_Core_Connection_Repository::count_enabled();
			$enabled       = is_array( $connection ) && ! empty( $connection['is_enabled'] );
			$provisioned   = $enabled && Mobo_Core_Connection_Repository::is_provisioned( $connection );
			$ownership_ok  = $enabled_count <= 1 || Mobo_Core_Connection_Repository::multi_connection_runtime_ready();
			$connection_ready = $enabled && $provisioned && $ownership_ok;

			if ( ! $enabled ) {
				$connection_reason = 'Connection انتخاب‌شده برای runtime فعال نیست.';
			} elseif ( ! $provisioned ) {
				$connection_reason = 'Connection هنوز provisioning کامل ندارد.';
			} elseif ( ! $ownership_ok ) {
				$connection_reason = 'مالکیت Multi-Connection برای runtime آماده نیست.';
			}
		}

		$webhook = array();
		if ( class_exists( 'Mobo_Core_Webhook_Queue' ) ) {
			try {
				$webhook = ( new Mobo_Core_Webhook_Queue() )->get_status();
			} catch ( Throwable $e ) {
				$webhook = array( 'tableMetricsAvailable' => false, 'tableMetricsReadFailed' => true );
			}
		}
		$webhook = is_array( $webhook ) ? $webhook : array();

		$image = array();
		if ( class_exists( 'Mobo_Core_Image_Queue' ) ) {
			try {
				$image = ( new Mobo_Core_Image_Queue() )->get_summary();
			} catch ( Throwable $e ) {
				$image = array( 'available' => false, 'readFailed' => true );
			}
		}
		$image = is_array( $image ) ? $image : array();

		$parent = array();
		if ( class_exists( 'Mobo_Core_Parent_Finalize_Queue' ) ) {
			try {
				$parent = Mobo_Core_Parent_Finalize_Queue::get_status();
			} catch ( Throwable $e ) {
				$parent = array();
			}
		}
		$parent = is_array( $parent ) ? $parent : array();

		$diagnostics = array();
		if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
			try {
				$diagnostics = Mobo_Core_Runtime_Diagnostics::get_health_status();
			} catch ( Throwable $e ) {
				$diagnostics = array();
			}
		}
		$diagnostics = is_array( $diagnostics ) ? $diagnostics : array();
		$adaptive    = isset( $diagnostics['adaptiveTuning'] ) && is_array( $diagnostics['adaptiveTuning'] ) ? $diagnostics['adaptiveTuning'] : array();

		$webhook_metrics_available = ! empty( $webhook['tableMetricsAvailable'] );
		$webhook_read_failed       = ! empty( $webhook['tableMetricsReadFailed'] );
		$image_enabled             = Mobo_Core_Settings::enabled( 'mobo_core_image_queue_enabled', '1' );
		$image_available           = array_key_exists( 'available', $image ) ? ! empty( $image['available'] ) : ( class_exists( 'Mobo_Core_Image_Queue' ) && Mobo_Core_Image_Queue::table_exists() );
		$image_read_failed         = ! empty( $image['readFailed'] );

		$webhook_active     = absint( isset( $webhook['activeTableEvents'] ) ? $webhook['activeTableEvents'] : 0 ) + absint( isset( $webhook['pendingFiles'] ) ? $webhook['pendingFiles'] : 0 );
		$webhook_due        = absint( isset( $webhook['dueTableEvents'] ) ? $webhook['dueTableEvents'] : 0 ) + absint( isset( $webhook['pendingFiles'] ) ? $webhook['pendingFiles'] : 0 );
		$webhook_actionable = absint( isset( $webhook['failedTableEvents'] ) ? $webhook['failedTableEvents'] : 0 );
		$webhook_historical = absint( isset( $webhook['historicalFailedTableEvents'] ) ? $webhook['historicalFailedTableEvents'] : 0 );
		$image_pending      = absint( isset( $image['pending'] ) ? $image['pending'] : 0 );
		$image_due          = absint( isset( $image['due'] ) ? $image['due'] : 0 );
		$image_failed       = absint( isset( $image['failed'] ) ? $image['failed'] : 0 );
		$parent_pending     = absint( isset( $parent['pendingCount'] ) ? $parent['pendingCount'] : 0 );
		$parent_due         = absint( isset( $parent['dueCount'] ) ? $parent['dueCount'] : 0 );

		$data_available = $connection_ready
			&& ! $webhook_read_failed
			&& ( $webhook_metrics_available || absint( isset( $webhook['pendingFiles'] ) ? $webhook['pendingFiles'] : 0 ) > 0 )
			&& ( ! $image_enabled || ( $image_available && ! $image_read_failed ) );

		$actionable_failures = $webhook_actionable + ( $image_enabled ? $image_failed : 0 );
		$due_work            = $webhook_due + ( $image_enabled ? $image_due : 0 ) + $parent_due;

		if ( ! $connection_ready ) {
			$state = 'warning';
			$label = 'Connection آماده نیست';
		} elseif ( ! $data_available ) {
			$state = 'warning';
			$label = 'داده صف کامل نیست';
		} elseif ( $actionable_failures > 0 ) {
			$state = 'danger';
			$label = 'نیازمند بررسی';
		} elseif ( $due_work > 0 ) {
			$state = 'info';
			$label = 'در حال پردازش';
		} else {
			$state = 'success';
			$label = 'موردی نیاز به بررسی ندارد';
		}

		return array(
			'state'                   => $state,
			'label'                   => $label,
			'connectionReady'         => $connection_ready,
			'connectionReason'        => $connection_reason,
			'dataAvailable'           => $data_available,
			'webhook'                 => $webhook,
			'webhookActive'           => $webhook_active,
			'webhookDue'              => $webhook_due,
			'webhookActionableFailed' => $webhook_actionable,
			'webhookHistoricalFailed' => $webhook_historical,
			'image'                   => $image,
			'imageEnabled'            => $image_enabled,
			'imageAvailable'          => $image_available,
			'imagePending'            => $image_pending,
			'imageDue'                => $image_due,
			'imageFailed'             => $image_failed,
			'parent'                  => $parent,
			'parentPending'           => $parent_pending,
			'parentDue'               => $parent_due,
			'actionableFailures'      => $actionable_failures,
			'dueWork'                 => $due_work,
			'diagnostics'             => $diagnostics,
			'adaptive'                => $adaptive,
		);
	}

	/**
	 * Render queue settings with operational evidence first.
	 *
	 * @return void
	 */
	private function render_queue_tab() {
		$lock     = class_exists( 'Mobo_Core_Sync_Settings_Guard' ) ? Mobo_Core_Sync_Settings_Guard::get_lock_state() : array();
		$locked   = ! empty( $lock['locked'] );
		$label    = isset( $lock['operationLabel'] ) ? sanitize_text_field( (string) $lock['operationLabel'] ) : 'همگام‌سازی';
		$snapshot = $this->get_queue_admin_snapshot();
		$adaptive_enabled = Mobo_Core_Settings::enabled( 'mobo_core_adaptive_execution_enabled', '1' );
		$adaptive = isset( $snapshot['adaptive'] ) && is_array( $snapshot['adaptive'] ) ? $snapshot['adaptive'] : array();
		$adaptive_mode = isset( $adaptive['mode'] ) ? sanitize_key( (string) $adaptive['mode'] ) : '';
		$adaptive_labels = array(
			'learning'     => 'در حال یادگیری',
			'balanced'     => 'متعادل',
			'conservative' => 'محافظه‌کار',
			'cautious'     => 'محافظه‌کار',
			'disabled'     => 'غیرفعال',
		);
		$adaptive_label = ! $adaptive_enabled ? 'غیرفعال' : ( isset( $adaptive_labels[ $adaptive_mode ] ) ? $adaptive_labels[ $adaptive_mode ] : 'فعال؛ هنوز نمونه کافی نیست' );
		$webhook_label = ! empty( $snapshot['dataAvailable'] ) || absint( $snapshot['webhookActive'] ) > 0
			? sprintf( '%s فعال / %s آماده', number_format_i18n( absint( $snapshot['webhookActive'] ) ), number_format_i18n( absint( $snapshot['webhookDue'] ) ) )
			: 'در دسترس نیست';
		$image_label = empty( $snapshot['imageEnabled'] )
			? 'صف خاموش؛ پردازش مستقیم'
			: ( ! empty( $snapshot['imageAvailable'] )
				? sprintf( '%s در انتظار / %s آماده', number_format_i18n( absint( $snapshot['imagePending'] ) ), number_format_i18n( absint( $snapshot['imageDue'] ) ) )
				: 'در دسترس نیست' );
		?>
		<div class="mobo-card mobo-ux-primary-card">
			<div class="mobo-card-head"><h2>وضعیت ظرفیت و صف‌ها</h2><p>ابتدا وضعیت واقعی صف را ببینید؛ فقط وقتی backlog رو به رشد است یا Runner به محدودیت منابع می‌رسد ظرفیت را تغییر دهید.</p></div>
			<div class="mobo-ux-summary-grid">
				<?php $this->status_box( 'وضعیت عملیاتی', $snapshot['label'] ); ?>
				<?php $this->status_box( 'صف وب‌هوک', $webhook_label ); ?>
				<?php $this->status_box( 'صف تصویر', $image_label ); ?>
				<?php $this->status_box( 'خطای قابل اقدام', number_format_i18n( absint( $snapshot['actionableFailures'] ) ) ); ?>
				<?php $this->status_box( 'Adaptive Execution', $adaptive_label ); ?>
				<?php $this->status_box( 'ویرایش تنظیمات', $locked ? 'موقتاً قفل' : 'مجاز' ); ?>
			</div>

			<?php if ( 'danger' === $snapshot['state'] ) : ?>
				<div class="mobo-message mobo-message-error">یک یا چند failure فعلی در صف‌ها نیازمند بررسی است. قبل از افزایش batch size، علت failure را برطرف کنید؛ افزایش ظرفیت معمولاً خطای منطقی/شبکه/رسانه را درمان نمی‌کند.</div>
			<?php elseif ( 'warning' === $snapshot['state'] ) : ?>
				<div class="mobo-message mobo-message-warning"><?php echo esc_html( ! empty( $snapshot['connectionReason'] ) ? $snapshot['connectionReason'] : 'وضعیت کامل صف‌ها قابل اثبات نیست. صفرهای ناشی از نبود داده به‌عنوان صف خالی در نظر گرفته نمی‌شوند.' ); ?></div>
			<?php elseif ( 'info' === $snapshot['state'] ) : ?>
				<div class="mobo-message mobo-message-info">کار آماده در صف وجود دارد. Pending به‌تنهایی خطا نیست؛ اگر تعداد آماده‌ها در چند اجرای Worker کاهش پیدا نکند، ابتدا صفحه Cron / Worker و جزئیات فنی پایین را بررسی کنید.</div>
			<?php else : ?>
				<div class="mobo-message mobo-message-success">در حال حاضر failure قابل اقدام یا backlog آماده‌ای که نیاز به مداخله مدیر داشته باشد دیده نمی‌شود.</div>
			<?php endif; ?>

			<?php if ( $locked ) : ?>
				<div class="mobo-note">تنظیمات throughput در زمان اجرای <?php echo esc_html( $label ); ?> قفل می‌شوند تا page/cursor و checkpoint همان اجرای resumable وسط کار تغییر نکند.</div>
			<?php endif; ?>
			<div class="mobo-note"><strong>اعداد این صفحه baseline هستند.</strong> اگر Adaptive Execution در صفحه Cron / Worker روشن باشد، Runner می‌تواند ظرفیت واقعی بعضی stageها را بر اساس latency، failure و memory headroom در همان اجرا کاهش یا به‌صورت محدود افزایش دهد.</div>
		</div>

		<details class="mobo-admin-details mobo-ux-secondary-details">
			<summary>جزئیات فنی وضعیت صف‌ها</summary>
			<div class="mobo-admin-details__body">
				<div class="mobo-status-grid">
					<?php $this->status_box( 'Webhook failure فعلی / تاریخی', number_format_i18n( absint( $snapshot['webhookActionableFailed'] ) ) . ' / ' . number_format_i18n( absint( $snapshot['webhookHistoricalFailed'] ) ) ); ?>
					<?php $this->status_box( 'Image failed', ! empty( $snapshot['imageEnabled'] ) ? number_format_i18n( absint( $snapshot['imageFailed'] ) ) : 'صف غیرفعال' ); ?>
					<?php $this->status_box( 'Parent Finalize در انتظار / آماده', number_format_i18n( absint( $snapshot['parentPending'] ) ) . ' / ' . number_format_i18n( absint( $snapshot['parentDue'] ) ) ); ?>
					<?php $this->status_box( 'آخرین فعالیت Webhook', ! empty( $snapshot['webhook']['lastActivityAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $snapshot['webhook']['lastActivityAt'] ) ) : '—' ); ?>
				</div>
				<?php if ( ! empty( $adaptive['limits'] ) && is_array( $adaptive['limits'] ) ) : ?>
					<div class="mobo-note"><strong>Limit مؤثر آخرین Adaptive profile:</strong> <code dir="ltr"><?php echo esc_html( wp_json_encode( $adaptive['limits'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></code></div>
				<?php endif; ?>
				<?php if ( ! empty( $snapshot['diagnostics']['recommendations'] ) && is_array( $snapshot['diagnostics']['recommendations'] ) ) : ?>
					<div class="mobo-note"><strong>پیشنهادهای Runtime Diagnostics:</strong><ul class="mobo-compact-list">
						<?php foreach ( array_slice( $snapshot['diagnostics']['recommendations'], 0, 5 ) as $recommendation ) : ?>
							<?php if ( is_array( $recommendation ) && ! empty( $recommendation['message'] ) ) : ?><li><?php echo esc_html( (string) $recommendation['message'] ); ?></li><?php endif; ?>
						<?php endforeach; ?>
					</ul></div>
				<?php endif; ?>
			</div>
		</details>

		<details class="mobo-admin-details mobo-ux-secondary-details">
			<summary>تنظیمات تخصصی ظرفیت و سیاست Retry</summary>
			<div class="mobo-admin-details__body"><?php $this->render_queue_technical_tab(); ?></div>
		</details>
		<?php
	}

	private function render_queue_technical_tab() {
		$queue_settings_lock = class_exists( 'Mobo_Core_Sync_Settings_Guard' )
			? Mobo_Core_Sync_Settings_Guard::get_lock_state()
			: array( 'locked' => false, 'operationLabel' => 'همگام سازی محصولات' );
		$queue_settings_locked = ! empty( $queue_settings_lock['locked'] );
		$queue_operation_label = isset( $queue_settings_lock['operationLabel'] )
			? sanitize_text_field( (string) $queue_settings_lock['operationLabel'] )
			: 'همگام سازی محصولات';
		?>
		<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="queue">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<?php if ( $queue_settings_locked ) : ?>
				<div class="mobo-message mobo-message-warning mobo-queue-settings-lock-message" role="status">
					<strong>تنظیمات ظرفیت موقتاً قفل است.</strong><br>
					در حال اجرای <?php echo esc_html( $queue_operation_label ); ?> هستید. تغییر page/cursor وسط یک اجرای resumable می‌تواند مرز ادامه را جابه‌جا کند؛ پس از پایان عملیات دوباره ذخیره کنید.
				</div>
			<?php endif; ?>

			<fieldset class="mobo-queue-settings-fieldset" <?php disabled( $queue_settings_locked, true ); ?> aria-disabled="<?php echo $queue_settings_locked ? 'true' : 'false'; ?>">
			<div class="mobo-card mobo-queue-preset-card" data-mobo-queue-preset-card>
				<div class="mobo-card-head">
					<h2>نقطه شروع بر اساس توان هاست</h2>
					<p>Preset فقط baselineهای throughput را تغییر می‌دهد؛ سیاست Retry، نگهداری event، Cursor، Missing Variant و روشن/خاموش بودن صف‌ها دست‌نخورده می‌مانند.</p>
				</div>
				<div class="mobo-queue-preset-actions" aria-label="تنظیم سریع ظرفیت پردازش">
					<button type="button" class="button button-secondary" data-mobo-queue-preset="vps">VPS</button>
					<button type="button" class="button button-secondary" data-mobo-queue-preset="strong">هاست قوی</button>
					<button type="button" class="button button-secondary" data-mobo-queue-preset="medium">هاست متوسط</button>
					<button type="button" class="button button-secondary" data-mobo-queue-preset="weak">هاست ضعیف</button>
				</div>
				<div class="mobo-help" data-mobo-queue-preset-message>این مقادیر تضمین سرعت نیستند؛ فقط نقطه شروع‌اند. روند backlog و Runtime Diagnostics را بعد از چند اجرای واقعی بررسی کنید.</div>
			</div>

			<div class="mobo-grid">
				<div class="mobo-card">
					<div class="mobo-card-head"><h2>همگام‌سازی محصول و تنوع</h2><p>اندازه page و روش ادامه snapshot دستی/Repair را تعیین می‌کند.</p></div>
					<div class="mobo-fields-grid">
						<?php $this->int_field( 'تعداد محصول در هر صفحه', 'mobo_core_products_per_page', 1, 20 ); ?>
						<?php $this->bool_field( 'استفاده از Cursor برای Sync محصول', 'mobo_core_product_cursor_sync_enabled' ); ?>
						<?php $this->int_field( 'تعداد تنوع در هر صفحه', 'mobo_core_variants_per_page', 1, 100 ); ?>
						<?php $this->bool_field( 'استفاده از Cursor برای Sync تنوع', 'mobo_core_variant_cursor_sync_enabled' ); ?>
					</div>
					<div class="mobo-help">Cursor برای ادامه امن snapshot توصیه می‌شود. اگر API مجبور شود به page fallback برود، runtime خودش آن وضعیت را مدیریت می‌کند.</div>
				</div>

				<div class="mobo-card">
					<div class="mobo-card-head"><h2>صف تصویر</h2><p>در مسیر فعلی Sync، تصاویر به‌صورت non-blocking در Desired-State queue ثبت می‌شوند و Worker آن‌ها را جداگانه پردازش می‌کند.</p></div>
					<div class="mobo-fields-grid">
						<?php $this->int_field( 'حد پایه تصویر در هر Worker slice', 'mobo_core_images_per_run', 1, 10 ); ?>
						<?php $this->int_field( 'سقف دریافت تصویر از هر میزبان تصویر Source / دقیقه', 'mobo_core_image_host_rate_per_minute', 1, 120, 30 ); ?>
						<?php $this->bool_field( 'فعال بودن صف مستقل تصویر', 'mobo_core_image_queue_enabled' ); ?>
						<?php $this->int_field( 'تعداد تلاش سریع تصویر قبل از Retry بلندمدت', 'mobo_core_image_max_try', 1, 20 ); ?>
						<?php $this->int_field( 'فاصله پایه Retry سریع تصویر / ثانیه', 'mobo_core_image_retry_base_seconds', 30, 900 ); ?>
						<?php $this->int_field( 'فاصله Retry بلندمدت تصویر / ثانیه', 'mobo_core_image_long_retry_seconds', 3600, 604800 ); ?>
					</div>
					<div class="mobo-message mobo-message-warning"><strong>خاموش‌کردن صف به معنی خاموش‌کردن تصاویر نیست.</strong> MoboCore به مسیر مستقیم/synchronous برمی‌گردد و latency همگام‌سازی می‌تواند بیشتر شود. برای حالت عادی صف را روشن نگه دارید.</div>
					<div class="mobo-help">مقدار تصویر در هر اجرا حداقل ۱ است. مقدار قدیمی ۰ دیگر به‌عنوان «هیچ تصویری پردازش نشود» پذیرفته نمی‌شود، چون می‌توانست Desired State را بدون enqueue به‌اشتباه converged جلو ببرد.</div>
				</div>

				<div class="mobo-card">
					<div class="mobo-card-head"><h2>صف وب‌هوک</h2><p>Eventهای جدید ابتدا در Sync Event Store ذخیره می‌شوند؛ فایل JSON فقط fallback سازگاری/خطای storage است.</p></div>
					<div class="mobo-fields-grid">
						<?php $this->int_field( 'بودجه زمانی پردازش صف وب‌هوک / ثانیه', 'mobo_core_sync_time_budget_seconds', 2, 25 ); ?>
						<?php $this->int_field( 'حد پایه Event وب‌هوک در هر slice', 'mobo_core_webhook_files_per_run', 1, 10, 4 ); ?>
						<?php $this->int_field( 'حداکثر تلاش هر Event وب‌هوک', 'mobo_core_webhook_max_try', 1, 20 ); ?>
						<?php $this->int_field( 'انقضای Event جدید / روز', 'mobo_core_webhook_expire_days', 1, 30 ); ?>
					</div>
					<div class="mobo-help">نام داخلی `sync_time_budget` میراث نسخه‌های قدیمی است؛ در runtime فعلی این فیلد بودجه صف وب‌هوک است، نه زمان کل Product Sync. تغییر «انقضا» فقط روی Eventهایی که بعد از ذخیره ساخته می‌شوند اثر دارد و expiry ردیف‌های موجود را بازنویسی نمی‌کند.</div>
				</div>

				<div class="mobo-card">
					<div class="mobo-card-head"><h2>رفتار snapshot محصول</h2><p>این بخش policy است، نه تنظیم سرعت؛ Presetهای توان هاست آن را تغییر نمی‌دهند.</p></div>
					<div class="mobo-fields-grid"><?php $this->missing_variants_field(); ?></div>
					<div class="mobo-help">UpdateVariantی که هنوز Product مادرش را ندارد صرفاً به‌خاطر سن event حذف نمی‌شود؛ Desired-State evidence تا supersession/حل مالکیت حفظ می‌شود. تنظیم قدیمی «مهلت انتظار محصول مادر» برای backward compatibility نگه داشته شده ولی در policy فعلی retirement زمانی ایجاد نمی‌کند.</div>
				</div>
			</div>

			<?php
			$this->guide_box(
				'راهنمای ظرفیت و صف‌ها',
				array(
					array( 'title' => 'Baseline در برابر ظرفیت واقعی', 'text' => 'اعداد این صفحه baseline هستند. وقتی Adaptive Execution روشن است، Worker بر اساس نمونه‌های latency/failure/memory limit مؤثر بعضی stageها را در همان اجرا تنظیم می‌کند.' ),
					array( 'title' => 'Backlog را قبل از افزایش ظرفیت بخوانید', 'text' => 'Pending معمولاً کار عادی است. افزایش ظرفیت زمانی منطقی است که کار آماده در چند اجرای سالم کاهش پیدا نکند؛ failure قابل اقدام را ابتدا باید ریشه‌یابی کرد.' ),
					array( 'title' => 'تصاویر non-blocking هستند', 'text' => 'مسیرهای فعلی Product Sync و Missing Image Recovery صریحاً پردازش تصویر را non-blocking enqueue می‌کنند. گزینه legacy «منتظر ماندن Sync تا تکمیل تصاویر» دیگر در UI ارائه نمی‌شود.' ),
					array( 'title' => 'Webhook Store', 'text' => 'مسیر اصلی جدول Sync Event Store است. JSON file queue فقط fallback است؛ بنابراین «تعداد فایل» دیگر مترادف کل backlog وب‌هوک نیست.' ),
					array( 'title' => 'Missing Variant', 'text' => 'این policy تعیین می‌کند تنوعی که در snapshot معتبر بعدی دیگر دیده نمی‌شود چگونه نهایی شود؛ آن را بر اساس سیاست فروشگاه انتخاب کنید، نه توان CPU.' ),
				),
				'اگر مشکل اصلی Cron/Worker، Connection، شبکه یا failure یک stage است، افزایش batch size راه‌حل مناسبی نیست.'
			);

			$this->recommendation_box(
				'Baseline پیشنهادی برای شروع',
				array(
					array( 'setting' => 'محصول در هر صفحه', 'value' => '۱ تا ۲ هاست معمولی؛ ۴ تا ۶ VPS', 'reason' => 'تعداد کم checkpointهای کوچک‌تر و recovery امن‌تری می‌دهد.' ),
					array( 'setting' => 'تنوع در هر صفحه', 'value' => '۵ تا ۱۰ هاست معمولی؛ ۲۰ تا ۳۵ VPS', 'reason' => 'Variation write در WooCommerce نسبتاً پرهزینه است.' ),
					array( 'setting' => 'تصویر در Worker slice', 'value' => '۱ تا ۳ هاست معمولی؛ ۴ تا ۶ VPS', 'reason' => 'دانلود/ساخت رسانه هم شبکه و هم CPU/IO مصرف می‌کند؛ صفر مقدار معتبر نیست.' ),
					array( 'setting' => 'Event وب‌هوک در slice', 'value' => '۱ تا ۳ هاست معمولی؛ ۵ تا ۸ VPS', 'reason' => 'این مقدار baseline است و Adaptive Tuning می‌تواند limit مؤثر را تغییر دهد.' ),
					array( 'setting' => 'صف مستقل تصویر', 'value' => 'روشن', 'reason' => 'مسیر async مانع وابسته‌شدن Product Sync به latency رسانه می‌شود.' ),
					array( 'setting' => 'Cursor محصول و تنوع', 'value' => 'روشن', 'reason' => 'برای resume پایدار snapshot توصیه می‌شود و Preset throughput آن را تغییر نمی‌دهد.' ),
					array( 'setting' => 'Missing variants', 'value' => 'معمولاً outofstock', 'reason' => 'این انتخاب policy فروشگاه است و نباید با انتخاب VPS/هاست ضعیف تغییر کند.' ),
				),
				'بعد از هر تغییر، چند اجرای واقعی را مشاهده کنید؛ هدف کاهش پایدار backlog بدون افزایش failure، timeout یا memory pressure است.'
			);
			?>

			<?php $this->save_button( $queue_settings_locked ? 'پس از پایان Sync/Repair قابل ذخیره است' : 'ذخیره تنظیمات ظرفیت' ); ?>
			</fieldset>
		</form>
		<?php
	}

	/**
	 * Render legacy image refresh tab.
	 *
	 * @return void
	 */

	/**
	 * Render adaptive reconciliation health dashboard.
	 *
	 * @return void
	 */
	private function render_sync_health_tab() {
		$detail_limit = 15;
		$status = array();
		$snapshot_pending = false;

		/* Admin reads are cache-first. A missing snapshot requests background work
		 * instead of making the page synchronously walk a large WooCommerce catalog. */
		if ( class_exists( 'Mobo_Core_Health_Read_Cache' ) && method_exists( 'Mobo_Core_Health_Read_Cache', 'get_sync_health_operational_snapshot' ) ) {
			$status = Mobo_Core_Health_Read_Cache::get_sync_health_operational_snapshot( $detail_limit );
			if ( ! is_array( $status ) ) {
				$snapshot_pending = true;
				$status = array(
					'operationalStatus' => 'unavailable',
					'operationalReason' => 'snapshot-pending',
					'operational'       => array(),
				);
			}
		} elseif ( class_exists( 'Mobo_Core_Sync_Health' ) ) {
			$status = method_exists( 'Mobo_Core_Sync_Health', 'get_operational_dashboard_status' )
				? Mobo_Core_Sync_Health::get_operational_dashboard_status( $detail_limit, false )
				: Mobo_Core_Sync_Health::get_dashboard_status();
		}

		$status = is_array( $status ) ? $status : array();
		$raw_counts = isset( $status['rawCounts'] ) && is_array( $status['rawCounts'] ) ? $status['rawCounts'] : ( isset( $status['counts'] ) && is_array( $status['counts'] ) ? $status['counts'] : array() );
		$op = isset( $status['operational'] ) && is_array( $status['operational'] ) ? $status['operational'] : array();
		$operational_status = sanitize_key( (string) ( isset( $status['operationalStatus'] ) ? $status['operationalStatus'] : '' ) );
		$operational_reason = sanitize_key( (string) ( isset( $status['operationalReason'] ) ? $status['operationalReason'] : '' ) );
		$data_available = ! $snapshot_pending && '' !== $operational_status && 'unavailable' !== $operational_status;

		$current = absint( isset( $op['currentProducts'] ) ? $op['currentProducts'] : 0 );
		$excluded = absint( isset( $op['excludedByPolicy'] ) ? $op['excludedByPolicy'] : 0 );
		$monitored = array_key_exists( 'monitoredProducts', $op ) ? absint( $op['monitoredProducts'] ) : max( 0, $current - $excluded );
		$converged = absint( isset( $op['converged'] ) ? $op['converged'] : 0 );
		$retry = absint( isset( $op['activeRetryOwner'] ) ? $op['activeRetryOwner'] : 0 );
		$unowned = absint( isset( $op['unownedConvergenceGap'] ) ? $op['unownedConvergenceGap'] : 0 );
		$invalid = absint( isset( $op['terminalUpstreamInvalid'] ) ? $op['terminalUpstreamInvalid'] : 0 );
		$verify = absint( isset( $op['needsVerification'] ) ? $op['needsVerification'] : ( isset( $op['unverifiedHealthGap'] ) ? $op['unverifiedHealthGap'] : 0 ) );
		$historical = absint( isset( $op['historicalExcluded'] ) ? $op['historicalExcluded'] : 0 );
		$delta = intval( isset( $op['accountingDelta'] ) ? $op['accountingDelta'] : 0 );

		$last_success_at = absint( Mobo_Core_Settings::get( 'mobo_core_sync_health_snapshot_last_success_at', 0 ) );
		$last_background_success_at = absint( Mobo_Core_Settings::get( 'mobo_core_sync_health_snapshot_last_background_success_at', 0 ) );
		$refresh_requested_at = absint( Mobo_Core_Settings::get( 'mobo_core_sync_health_snapshot_refresh_requested_at', 0 ) );
		$last_refresh_error = sanitize_text_field( (string) Mobo_Core_Settings::get( 'mobo_core_sync_health_snapshot_last_error', '' ) );
		$snapshot_age = $last_success_at > 0 ? max( 0, time() - $last_success_at ) : PHP_INT_MAX;
		$snapshot_stale = $data_available && $snapshot_age > 600;
		$last_check_label = $last_success_at > 0 ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', $last_success_at ) : 'هنوز انجام نشده';

		if ( ! $data_available ) {
			$state = in_array( $operational_reason, array( 'current-product-map-query-failed', 'sync-event-ownership-query-failed', 'sync-health-history-query-failed', 'product-map-unavailable' ), true ) ? 'danger' : 'info';
			$label = 'snapshot-pending' === $operational_reason ? 'در انتظار بررسی' : ( 'connection-unavailable' === $operational_reason ? 'اتصال آماده نیست' : 'داده در دسترس نیست' );
		} elseif ( 0 !== $delta || $unowned > 0 ) {
			$state = 'danger';
			$label = 'نیازمند اقدام';
		} elseif ( $snapshot_stale || $invalid > 0 || $verify > 0 ) {
			$state = 'warning';
			$label = 'نیازمند بررسی';
		} elseif ( $retry > 0 ) {
			$state = 'info';
			$label = 'در حال بازیابی';
		} elseif ( 0 === $monitored ) {
			$state = 'info';
			$label = 'موردی برای پایش نیست';
		} else {
			$state = 'success';
			$label = 'سالم';
		}
		?>
		<div class="mobo-card mobo-ux-primary-card">
			<div class="mobo-admin-card-heading"><div><h2 class="mobo-admin-section-title">سلامت همگام‌سازی</h2><p>وضعیت فعلی محصولات تحت پایش؛ رکوردهای تاریخی و محصولات مستثنا از خرابی جاری جدا محاسبه می‌شوند.</p></div><span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $label ); ?></span></div>

			<?php if ( $data_available ) : ?>
				<div class="mobo-ux-summary-grid mobo-ux-summary-grid--5">
					<?php $this->status_box( 'محصول تحت پایش', $monitored ); ?>
					<?php $this->status_box( 'هماهنگ و سالم', $converged ); ?>
					<?php $this->status_box( 'در بازیابی خودکار', $retry ); ?>
					<?php $this->status_box( 'مسدود از سمت منبع', $invalid ); ?>
					<?php $this->status_box( 'نیازمند اقدام داخلی', $unowned ); ?>
				</div>
				<div class="mobo-health-freshness"><span>آخرین بررسی کامل: <strong><?php echo esc_html( $last_check_label ); ?></strong></span><?php if ( $excluded > 0 ) : ?><span><?php echo esc_html( number_format_i18n( $excluded ) ); ?> محصول عمداً از پایش همگام‌سازی مستثنا است.</span><?php endif; ?></div>
			<?php else : ?>
				<div class="mobo-ux-summary-grid mobo-ux-summary-grid--3">
					<?php $this->status_box( 'وضعیت داده', 'در دسترس نیست' ); ?>
					<?php $this->status_box( 'آخرین بررسی کامل', $last_check_label ); ?>
					<?php $this->status_box( 'به‌روزرسانی پس‌زمینه', $refresh_requested_at > 0 ? 'درخواست شده' : 'در انتظار Worker' ); ?>
				</div>
			<?php endif; ?>

			<?php $has_health_notice = false; ?>
			<?php if ( ! $data_available ) : $has_health_notice = true; ?>
				<?php if ( 'snapshot-pending' === $operational_reason ) : ?>
					<div class="mobo-message mobo-message-info">هنوز snapshot عملیاتی قابل اتکایی ثبت نشده است. بررسی در پس‌زمینه برای Worker درخواست شده و این صفحه تا قبل از نتیجه، وضعیت را «سالم» فرض نمی‌کند.</div>
				<?php elseif ( 'connection-unavailable' === $operational_reason ) : ?>
					<div class="mobo-message mobo-message-warning">اتصال فعالی برای ارزیابی سلامت همگام‌سازی در دسترس نیست. ابتدا وضعیت «اتصال و لایسنس» را بررسی کنید.</div>
				<?php else : ?>
					<div class="mobo-message mobo-message-error">بررسی کامل سلامت همگام‌سازی در حال حاضر قابل انجام نیست. صفرهای این وضعیت به‌عنوان «سالم» تفسیر نمی‌شوند؛ جزئیات فنی پایین صفحه علت را نشان می‌دهد.</div>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $data_available && $snapshot_stale ) : $has_health_notice = true; ?><div class="mobo-message mobo-message-warning">آخرین snapshot سالم بیش از ۱۰ دقیقه قدمت دارد. داده فعلی برای مرجع نمایش داده می‌شود، اما تا تکمیل refresh بعدی نباید آن را وضعیت لحظه‌ای در نظر گرفت.</div><?php endif; ?>
			<?php if ( $data_available && '' !== $last_refresh_error ) : $has_health_notice = true; ?><div class="mobo-message mobo-message-warning">آخرین تلاش برای تازه‌سازی کامل نشد؛ آخرین snapshot سالم حفظ شده و نمایش داده می‌شود. خطای فنی در بخش جزئیات قرار دارد.</div><?php endif; ?>
			<?php if ( $data_available && 0 !== $delta ) : $has_health_notice = true; ?><div class="mobo-message mobo-message-error">جمع وضعیت‌های عملیاتی با Product Map همخوان نیست. این یک ناسازگاری داخلی در accounting سلامت است و باید قبل از اتکا به شمارنده‌ها بررسی شود.</div><?php endif; ?>
			<?php if ( $data_available && $unowned > 0 ) : $has_health_notice = true; ?><div class="mobo-message mobo-message-error"><?php echo esc_html( number_format_i18n( $unowned ) ); ?> محصول مشکل محلی تأییدشده دارد ولی Recovery فعالی برای آن ثبت نشده است. محصولات نمونه را در جزئیات بررسی کنید؛ برای بازسازی وضعیت منبع، Sync/Repair مناسب از «نمای کلی» قابل اجرا است.</div><?php endif; ?>
			<?php if ( $data_available && $invalid > 0 ) : $has_health_notice = true; ?><div class="mobo-message mobo-message-warning"><?php echo esc_html( number_format_i18n( $invalid ) ); ?> محصول به‌دلیل داده نامعتبر منبع متوقف است. این موارد با Repair داخل وردپرس حل نمی‌شوند و باید داده منبع اصلاح شود.</div><?php endif; ?>
			<?php if ( $data_available && $verify > 0 ) : $has_health_notice = true; ?><div class="mobo-message mobo-message-info"><?php echo esc_html( number_format_i18n( $verify ) ); ?> محصول فقط نیازمند راستی‌آزمایی است؛ این عدد به معنی خرابی قطعی محصول نیست.</div><?php endif; ?>
			<?php if ( $data_available && $retry > 0 ) : $has_health_notice = true; ?><div class="mobo-message mobo-message-info"><?php echo esc_html( number_format_i18n( $retry ) ); ?> محصول Recovery فعال دارد و Worker در حال رسیدگی است. تا زمانی که این موارد در همین state هستند، اقدام دستی لازم نیست.</div><?php endif; ?>
			<?php if ( $data_available && 0 === $monitored ) : $has_health_notice = true; ?><div class="mobo-message mobo-message-info"><?php echo $current > 0 ? esc_html( 'محصول موبویی فعلی وجود دارد، اما همه آن‌ها عمداً از mutation منبع مستثنا شده‌اند؛ بنابراین موردی برای پایش Sync باقی نمانده است.' ) : esc_html( 'هنوز محصول موبویی فعالی برای ارزیابی سلامت همگام‌سازی وجود ندارد.' ); ?></div><?php endif; ?>
			<?php if ( $data_available && ! $has_health_notice ) : ?><div class="mobo-message mobo-message-success">برای محصولات تحت پایش مشکل عملیاتی شناخته‌شده‌ای دیده نشد.</div><?php endif; ?>
			<?php if ( $data_available && $monitored > 0 ) : ?><div class="mobo-note"><strong>راهنمای اقدام:</strong> «مسدود از سمت منبع» را در Source اصلاح کنید؛ «در بازیابی خودکار» را به Worker بسپارید؛ فقط «نیازمند اقدام داخلی» به Sync/Repair یا بررسی فنی وردپرس نیاز دارد.</div><?php endif; ?>
		</div>

		<?php if ( $data_available ) : ?>
			<?php $this->render_sync_health_product_list( 'محصولات نیازمند اقدام داخلی', isset( $status['unownedRows'] ) && is_array( $status['unownedRows'] ) ? $status['unownedRows'] : array(), 'unowned' ); ?>
			<?php $this->render_sync_health_product_list( 'محصولات مسدود از سمت منبع', isset( $status['terminalRows'] ) && is_array( $status['terminalRows'] ) ? $status['terminalRows'] : array(), 'terminal' ); ?>
			<?php $this->render_sync_health_product_list( 'موارد نیازمند راستی‌آزمایی', isset( $status['verificationRows'] ) && is_array( $status['verificationRows'] ) ? $status['verificationRows'] : array(), 'verification' ); ?>
			<?php $this->render_sync_health_product_list( 'نمونه‌های در بازیابی خودکار', isset( $status['activeRetryRows'] ) && is_array( $status['activeRetryRows'] ) ? $status['activeRetryRows'] : array(), 'retry' ); ?>
		<?php endif; ?>

		<details class="mobo-admin-details mobo-ux-secondary-details">
			<summary>جزئیات و آمار تشخیصی</summary>
			<div class="mobo-admin-details__body">
				<div class="mobo-status-grid">
					<?php $this->status_box( 'وضعیت operational', '' !== $operational_status ? $operational_status : '—' ); ?>
					<?php $this->status_box( 'علت/منبع وضعیت', '' !== $operational_reason ? $operational_reason : '—' ); ?>
					<?php $this->status_box( 'کل محصول موبویی فعلی', $current ); ?>
					<?php $this->status_box( 'مستثنا از پایش', $excluded ); ?>
					<?php $this->status_box( 'شکاف بدون Recovery Owner', $unowned ); ?>
					<?php $this->status_box( 'داده نامعتبر منبع', $invalid ); ?>
					<?php $this->status_box( 'نیازمند راستی‌آزمایی Health', $verify ); ?>
					<?php $this->status_box( 'رکورد تاریخی خارج از محاسبه', $historical ); ?>
					<?php $this->status_box( 'Accounting delta', $delta ); ?>
					<?php $this->status_box( 'Raw synced', absint( isset( $raw_counts['synced'] ) ? $raw_counts['synced'] : 0 ) ); ?>
					<?php $this->status_box( 'Raw behind', absint( isset( $raw_counts['behind'] ) ? $raw_counts['behind'] : 0 ) ); ?>
					<?php $this->status_box( 'Raw failed', absint( isset( $raw_counts['failed'] ) ? $raw_counts['failed'] : 0 ) ); ?>
					<?php $this->status_box( 'آخرین refresh پس‌زمینه', $last_background_success_at > 0 ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', $last_background_success_at ) : '—' ); ?>
					<?php $this->status_box( 'درخواست refresh', $refresh_requested_at > 0 ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', $refresh_requested_at ) : '—' ); ?>
				</div>
				<?php if ( '' !== $last_refresh_error ) : ?><div class="mobo-admin-technical-error"><strong>آخرین خطای refresh:</strong> <code dir="ltr"><?php echo esc_html( $last_refresh_error ); ?></code></div><?php endif; ?>
				<div class="mobo-note">behind/failed خام به‌تنهایی خرابی جاری را اثبات نمی‌کند و پردازش جدید ایجاد نمی‌کند. ادامه کار فقط از event پایدار pending/processing یا Sync/Webhook جدید انجام می‌شود. محصولات مستثنا نیز عمداً از denominator عملیاتی کنار گذاشته می‌شوند.</div>
			</div>
		</details>
		<?php
	}

	/** Render a bounded list of concrete products behind one Sync Health bucket. */
	private function render_sync_health_product_list( $title, $rows, $kind ) {
		$rows = is_array( $rows ) ? $rows : array();
		if ( empty( $rows ) ) {
			return;
		}
		$kind = sanitize_key( (string) $kind );
		$labels = array(
			'unowned'      => 'مشکل محلی بدون Recovery فعال',
			'terminal'     => 'نیازمند اصلاح داده منبع',
			'verification' => 'نیازمند راستی‌آزمایی',
			'retry'        => 'در بازیابی خودکار',
		);
		$row_label = isset( $labels[ $kind ] ) ? $labels[ $kind ] : 'نیازمند بررسی';
		?>
		<details class="mobo-admin-details mobo-ux-secondary-details mobo-sync-health-product-details"<?php echo 'unowned' === $kind || 'terminal' === $kind ? ' open' : ''; ?>>
			<summary><?php echo esc_html( $title . ' (' . number_format_i18n( count( $rows ) ) . ' نمونه)' ); ?></summary>
			<div class="mobo-admin-details__body"><div class="mobo-sync-health-product-list">
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$row = is_array( $row ) ? $row : array();
					$product_id = absint( isset( $row['wpProductId'] ) ? $row['wpProductId'] : 0 );
					$title_text = $product_id > 0 ? trim( wp_strip_all_tags( get_the_title( $product_id ) ) ) : '';
					if ( '' === $title_text ) {
						$title_text = $product_id > 0 ? 'محصول #' . $product_id : 'محصول شناسایی‌نشده';
					}
					$edit_url = $product_id > 0 ? get_edit_post_link( $product_id, 'raw' ) : '';
					$guid = sanitize_text_field( (string) ( isset( $row['productGuid'] ) ? $row['productGuid'] : '' ) );
					$health_status = sanitize_key( (string) ( isset( $row['healthStatus'] ) ? $row['healthStatus'] : '' ) );
					$last_error = sanitize_textarea_field( (string) ( isset( $row['terminalEventError'] ) && '' !== $row['terminalEventError'] ? $row['terminalEventError'] : ( isset( $row['lastError'] ) ? $row['lastError'] : '' ) ) );
					?>
					<div class="mobo-sync-health-product-row">
						<div><strong><?php echo esc_html( $row_label ); ?></strong><span><?php echo esc_html( $product_id > 0 ? '#' . $product_id : 'بدون شناسه محلی' ); ?></span></div>
						<div class="mobo-sync-health-product-main">
							<?php if ( $edit_url ) : ?><a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $title_text ); ?></a><?php else : ?><strong><?php echo esc_html( $title_text ); ?></strong><?php endif; ?>
							<?php if ( '' !== $guid || '' !== $health_status || '' !== $last_error ) : ?><details class="mobo-sync-health-technical"><summary>جزئیات فنی</summary><div><?php if ( '' !== $health_status ) : ?><span>Health: <code dir="ltr"><?php echo esc_html( $health_status ); ?></code></span><?php endif; ?><?php if ( '' !== $guid ) : ?><span>GUID: <code dir="ltr"><?php echo esc_html( $guid ); ?></code></span><?php endif; ?><?php if ( '' !== $last_error ) : ?><span>خطا: <code dir="ltr"><?php echo esc_html( $last_error ); ?></code></span><?php endif; ?></div></details><?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div></div>
		</details>
		<?php
		/* Products marked (not deleted) during the 10.34.77 storage migration. */
		if ( class_exists( 'Mobo_Core_Migration_Quarantine' ) ) {
			Mobo_Core_Migration_Quarantine::render_admin_list( 50 );
		}
	}

	/**
	 * Read-only legacy image scan: summary, sample list, Run/Continue/Stop/Reset.
	 * No deletion or replacement is reachable from this card.
	 *
	 * @return void
	 */
	private function render_legacy_image_audit_card() {
		if ( ! class_exists( 'Mobo_Core_Legacy_Image_Audit' ) || ! class_exists( 'Mobo_Core_Legacy_Image_Policy' ) ) {
			return;
		}
		$state   = Mobo_Core_Legacy_Image_Audit::get_state();
		$summary = Mobo_Core_Legacy_Image_Audit::health_summary();
		$status  = sanitize_key( (string) $state['status'] );
		$running = 'running' === $status;
		$labels  = array(
			Mobo_Core_Legacy_Image_Policy::CLASS_DELETE_CANDIDATE          => 'بدون استفاده با اثبات قوی مالکیت Mobo (قابل حذف پس از اثبات نهایی)',
			Mobo_Core_Legacy_Image_Policy::CLASS_REPLACE_THEN_DELETE       => 'فقط در محصول Mobo؛ WebP موجود (اول جایگزینی)',
			Mobo_Core_Legacy_Image_Policy::CLASS_IN_USE_NO_REPLACEMENT     => 'در استفاده؛ WebP جایگزین شناخته نشده (نگه داشته می‌شود)',
			Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_REFERENCED      => 'مرجع در محتوا/Elementor/ACF/تنظیمات/اسلایدر/CSS (محافظت‌شده)',
			Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_FOREIGN_USE     => 'در محصول فروشگاه یا پیوست به نوشته غیر Mobo (محافظت‌شده)',
			Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_LIVE            => 'تصویر فعلی صف تصاویر/نوسازی فعال (محافظت‌شده)',
			Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_SHARED_FILE     => 'فایل مشترک با پیوست دیگر (محافظت‌شده)',
			Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_SHARED_MEDIA    => 'Shared Media (محافظت‌شده)',
			Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_WEAK_OWNERSHIP  => 'نامطمئن: فقط نشانگر، بدون اثبات قوی مالکیت (محافظت‌شده)',
			Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_UNCERTAIN       => 'نامطمئن (محافظت‌شده)',
			Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_NOT_MOBO        => 'آپلود فروشگاه / غیر Mobo (هرگز لمس نمی‌شود)',
		);
		$source_labels = array(
			'content'     => 'محتوای نوشته/برگه',
			'revision'    => 'فقط در بازنگری‌ها (revision)',
			'elementor'   => 'Elementor (داده/قالب/کیت)',
			'acf'         => 'فیلدهای ACF',
			'postmeta'    => 'سایر متاهای نوشته',
			'termmeta'    => 'دسته‌ها و برچسب‌ها',
			'options'     => 'تنظیمات سایت/پوسته/ابزارک‌ها',
			'usermeta'    => 'متای کاربران',
			'css'         => 'فایل‌های CSS المنتور',
			'slider'      => 'اسلایدرها (Revolution/Smart/Layer)',
			'translation' => 'ترجمه‌های پیوست (WPML/Polylang)',
			'sharedfile'  => 'فایل/برش مشترک در متادیتای پیوست دیگر',
			'plugin'      => 'جداول افزونه‌های دیگر (مثلاً همگام‌سازی باسلام)',
		);
		$phase_labels = array(
			'attachments' => 'مرحله ۱ از ۳: مالکیت و استفاده مستقیم پیوست‌ها',
			'refs'        => 'مرحله ۲ از ۳: جست‌وجوی مراجع در کل سایت',
			'finalize'    => 'مرحله ۳ از ۳: نتیجه‌گیری',
		);
		$phase      = sanitize_key( (string) $state['phase'] );
		$total      = absint( $state['estimatedTotal'] );
		$scanned    = absint( $state['scanned'] );
		$sources    = is_array( $state['sources'] ) ? $state['sources'] : array();
		if ( 'attachments' === $phase ) {
			$percent = $total > 0 ? min( 100, round( ( $scanned / $total ) * 100, 1 ) ) : 0;
		} elseif ( 'refs' === $phase ) {
			$percent = count( $sources ) > 0 ? min( 100, round( ( absint( $state['sourceIndex'] ) / count( $sources ) ) * 100, 1 ) ) : 0;
		} else {
			$percent = 100;
		}
		$last_label = absint( $state['lastRunAt'] ) > 0 ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $state['lastRunAt'] ) ) : 'هنوز اجرا نشده';
		?>
		<div class="mobo-card mobo-card-wide mobo-legacy-image-audit-card" id="mobo-legacy-image-audit">
			<div class="mobo-admin-card-heading">
				<div>
					<h2 class="mobo-admin-section-title">پاک‌سازی تصاویر قدیمی — بررسی (فقط خواندنی)</h2>
					<p>پیوست‌های JPG/PNG که نسخه‌های قدیمی افزونه از فروشگاه منبع دانلود کرده‌اند شناسایی، دسته‌بندی و حجمشان برآورد می‌شود. این بخش هیچ فایل، پیوست یا محصولی را تغییر نمی‌دهد. فقط پیوستی کاندید است که هم نشانگر Mobo دارد و هم اثبات قوی (اتصال به محصول Mobo یا نام فایلی که کد Mobo ساخته)، و در هیچ جای سایت (محتوا، Elementor، ACF، تنظیمات، دسته‌ها، اسلایدرها، CSS) به آن ارجاع نشده است. آپلودهای خود فروشگاه هرگز کاندید نمی‌شوند.</p>
				</div>
				<span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( 'complete' === $status ? 'success' : ( $running ? 'info' : 'warning' ) ); ?>"><?php echo esc_html( array( 'idle' => 'اجرا نشده', 'running' => 'در حال بررسی', 'stopped' => 'متوقف‌شده', 'complete' => 'کامل' )[ $status ] ?? $status ); ?></span>
			</div>
			<div class="mobo-status-grid">
				<?php $this->status_box( 'بدون استفاده (اثبات قوی)', number_format_i18n( absint( $summary['deleteCandidates'] ) ) ); ?>
				<?php $this->status_box( 'حجم بدون استفاده (فایل‌های موجود / متادیتا)', size_format( absint( $summary['unusedBytes'] ), 1 ) . ' / ' . size_format( absint( $summary['unusedMetadataBytes'] ), 1 ) ); ?>
				<?php $this->status_box( 'محافظت‌شده چون مرجع دارد', number_format_i18n( absint( $summary['referenced'] ) ) ); ?>
				<?php $this->status_box( 'نامطمئن (مالکیت ضعیف / سایر)', number_format_i18n( absint( $summary['unsureWeakOwnership'] ) ) . ' / ' . number_format_i18n( absint( $summary['unsure'] ) ) ); ?>
				<?php $this->status_box( 'متعلق به فروشگاه (غیر Mobo)', number_format_i18n( absint( $summary['merchant'] ) ) ); ?>
				<?php $this->status_box( 'پیوست JPG/PNG بررسی‌شده', number_format_i18n( $scanned ) . ' / ' . number_format_i18n( $total ) ); ?>
				<?php $this->status_box( 'آخرین اجرا', $last_label ); ?>
			</div>
			<?php if ( $running || 'stopped' === $status ) : ?>
				<div class="mobo-progress-wrap"><div class="mobo-progress-meta"><span><?php echo esc_html( isset( $phase_labels[ $phase ] ) ? $phase_labels[ $phase ] : $phase ); ?><?php if ( 'refs' === $phase && isset( $sources[ absint( $state['sourceIndex'] ) ]['id'] ) ) : ?> — <code dir="ltr"><?php echo esc_html( (string) $sources[ absint( $state['sourceIndex'] ) ]['id'] ); ?></code><?php endif; ?></span><strong><?php echo esc_html( number_format_i18n( $percent, 1 ) . '٪' ); ?></strong></div><div class="mobo-progress"><div style="width: <?php echo esc_attr( $percent ); ?>%;"></div></div></div>
			<?php endif; ?>
			<?php if ( '' !== (string) $state['lastError'] ) : ?><div class="mobo-alert mobo-alert-warning"><?php echo esc_html( (string) $state['lastError'] ); ?></div><?php endif; ?>
			<?php if ( empty( $summary['referenceIndexComplete'] ) ) : ?><div class="mobo-alert mobo-alert-warning">جست‌وجوی مراجع کامل نبود (ردیف/فایل بسیار بزرگ یا ظرفیت). هیچ پیوستی «بدون استفاده» اعلام نمی‌شود و همه کاندیدها «نامطمئن» می‌مانند.<?php if ( ! empty( $summary['incompleteReasons'] ) ) : ?> <code dir="ltr"><?php echo esc_html( implode( ' | ', $summary['incompleteReasons'] ) ); ?></code><?php endif; ?></div><?php endif; ?>
			<table class="widefat striped" style="margin:12px 0;">
				<thead><tr><th>دسته</th><th>تعداد</th><th>حجم موجود</th><th>حجم متادیتا</th><th>فایل‌ها (مفقود)</th></tr></thead>
				<tbody>
				<?php foreach ( $labels as $class => $label ) : $row = isset( $state['classes'][ $class ] ) ? $state['classes'][ $class ] : array(); ?>
					<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $row['count'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( size_format( absint( $row['bytes'] ?? 0 ), 1 ) ); ?></td><td><?php echo esc_html( size_format( absint( $row['metaBytes'] ?? 0 ), 1 ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $row['files'] ?? 0 ) ) . ' (' . number_format_i18n( absint( $row['missingFiles'] ?? 0 ) ) . ')' ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( absint( $summary['referenced'] ) > 0 ) : ?>
				<table class="widefat striped" style="margin:0 0 12px;">
					<thead><tr><th>محافظت‌شده چون مرجع دارد — منبع مرجع</th><th>تعداد پیوست</th></tr></thead>
					<tbody>
					<?php foreach ( $source_labels as $source => $label ) : $count = absint( isset( $summary['referencedBySource'][ $source ] ) ? $summary['referencedBySource'][ $source ] : 0 ); if ( $count <= 0 ) { continue; } ?>
						<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( number_format_i18n( $count ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">یک پیوست ممکن است در چند منبع مرجع داشته باشد.</p>
			<?php endif; ?>
			<?php if ( ! empty( $state['samples'] ) ) : ?>
				<details><summary>نمونه موارد (حداکثر <?php echo esc_html( number_format_i18n( Mobo_Core_Legacy_Image_Audit::SAMPLE_LIMIT ) ); ?>)</summary>
					<table class="widefat striped"><thead><tr><th>شناسه پیوست</th><th>فایل</th><th>دسته / دلیل</th><th>حجم</th></tr></thead><tbody>
					<?php foreach ( $state['samples'] as $sample ) : ?>
						<tr><td><?php echo esc_html( (string) absint( $sample['id'] ) ); ?></td><td dir="ltr"><code><?php echo esc_html( (string) $sample['file'] ); ?></code></td><td><?php echo esc_html( (string) $sample['class'] . ' / ' . (string) $sample['reason'] ); ?></td><td><?php echo esc_html( size_format( max( absint( $sample['bytes'] ), absint( $sample['metaBytes'] ) ), 1 ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				</details>
			<?php endif; ?>
			<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
				<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-legacy-audit-continue="<?php echo $running ? '1' : '0'; ?>">
					<input type="hidden" name="action" value="mobo_core_legacy_image_audit_run">
					<?php wp_nonce_field( 'mobo_core_legacy_image_audit_run', 'mobo_core_nonce' ); ?>
					<button type="submit" class="button button-primary"><?php echo esc_html( in_array( $status, array( 'running', 'stopped' ), true ) ? 'ادامه بررسی' : ( 'complete' === $status ? 'بررسی دوباره' : 'شروع بررسی فقط‌خواندنی' ) ); ?></button>
				</form>
				<?php if ( $running ) : ?>
					<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>">
						<input type="hidden" name="action" value="mobo_core_legacy_image_audit_stop">
						<?php wp_nonce_field( 'mobo_core_legacy_image_audit_stop', 'mobo_core_nonce' ); ?>
						<button type="submit" class="button">توقف بررسی</button>
					</form>
				<?php endif; ?>
				<?php if ( 'idle' !== $status ) : ?>
					<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" onsubmit="return confirm('نتایج بررسی پاک شود؟ هیچ تصویری تغییر نمی‌کند.');">
						<input type="hidden" name="action" value="mobo_core_legacy_image_audit_reset">
						<?php wp_nonce_field( 'mobo_core_legacy_image_audit_reset', 'mobo_core_nonce' ); ?>
						<button type="submit" class="button-link">پاک کردن نتایج</button>
					</form>
				<?php endif; ?>
			</div>
			<div class="mobo-note">حذف در این نسخه فعال نیست. «بدون استفاده» یعنی شناسه پیوست و نام هیچ‌کدام از فایل‌ها/برش‌هایش در محتوای همه نوشته‌ها (همه وضعیت‌ها و بازنگری‌ها)، همه متاهای نوشته (Elementor، ACF، ووکامرس)، دسته‌ها، کاربران، تنظیمات سایت، جداول اسلایدر و فایل‌های CSS المنتور پیدا نشد. بررسی در چند بخش کوتاه و قابل ادامه اجرا می‌شود؛ تغییراتی که پس از اسکن در سایت داده شود دیده نمی‌شود، پس پیش از هر حذف آینده اثبات دوباره لازم است.</div>
			<?php if ( $running ) : ?>
				<script>
				(function () {
					var form = document.querySelector('[data-mobo-legacy-audit-continue="1"]');
					if (!form) { return; }
					window.setTimeout(function () { if (document.visibilityState !== 'hidden') { form.submit(); } }, 1500);
				})();
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_image_refresh_tab() {
		$automation_status = class_exists( 'Mobo_Core_Image_Refresh_Automation' ) ? Mobo_Core_Image_Refresh_Automation::get_status() : array();
		$automation_status = is_array( $automation_status ) ? $automation_status : array();
		$automation_active = ! empty( $automation_status['enabled'] );
		$automation_state  = isset( $automation_status['status'] ) ? sanitize_key( (string) $automation_status['status'] ) : 'idle';
		$automation_step   = absint( isset( $automation_status['currentStep'] ) ? $automation_status['currentStep'] : 0 );
		$message           = isset( $automation_status['message'] ) ? sanitize_text_field( (string) $automation_status['message'] ) : '';
		$batch_stuck       = ! empty( $automation_status['batchPossiblyStuck'] );
		$last_result       = isset( $automation_status['lastResult'] ) && is_array( $automation_status['lastResult'] ) ? $automation_status['lastResult'] : array();
		$last_result_failed = array_key_exists( 'success', $last_result ) && empty( $last_result['success'] );

		$queue_class_ready = class_exists( 'Mobo_Core_Image_Refresh_Queue' );
		$queue_table_ready = $queue_class_ready && Mobo_Core_Image_Refresh_Queue::table_exists();
		$queue_status      = $queue_table_ready ? ( new Mobo_Core_Image_Refresh_Queue() )->get_status() : array();
		$queue_status      = is_array( $queue_status ) ? $queue_status : array();

		$orphan_class_ready = class_exists( 'Mobo_Core_Orphan_Image_Cleanup' );
		$orphan_table_ready = $orphan_class_ready && Mobo_Core_Orphan_Image_Cleanup::table_exists();
		$orphan_status      = $orphan_table_ready ? ( new Mobo_Core_Orphan_Image_Cleanup() )->get_status() : array();
		$orphan_status      = is_array( $orphan_status ) ? $orphan_status : array();

		$components_ready = class_exists( 'Mobo_Core_Image_Refresh_Automation' )
			&& class_exists( 'Mobo_Core_Image_Refresh_Service' )
			&& $queue_table_ready
			&& $orphan_table_ready;

		$repair_status    = class_exists( 'Mobo_Core_Product_Sync' ) ? ( new Mobo_Core_Product_Sync() )->get_manual_sync_status() : array();
		$repair_status    = is_array( $repair_status ) ? $repair_status : array();
		$repair_completed = ! empty( $repair_status['isRepairCompleted'] );
		$repair_active    = ! empty( $repair_status['repairMode'] ) && ( ! empty( $repair_status['isRunning'] ) || ! empty( $repair_status['isWaitingForPortal'] ) );

		$current_connection      = $this->current_admin_connection();
		$connection_table_exists = class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists();
		$enabled_connection_count = $connection_table_exists ? Mobo_Core_Connection_Repository::count_enabled() : 0;
		$connection_enabled      = ! $connection_table_exists || ( is_array( $current_connection ) && ! empty( $current_connection['is_enabled'] ) );
		$connection_provisioned  = ! $connection_table_exists || ( is_array( $current_connection ) && Mobo_Core_Connection_Repository::is_provisioned( $current_connection ) );
		$connection_runtime_ready = ! $connection_table_exists
			|| ( $enabled_connection_count > 0 && ( $enabled_connection_count <= 1 || Mobo_Core_Connection_Repository::multi_connection_runtime_ready() ) );
		$connection_ready = $connection_enabled && $connection_provisioned && $connection_runtime_ready;

		$generation_stats = isset( $automation_status['generationStats'] ) && is_array( $automation_status['generationStats'] ) ? $automation_status['generationStats'] : array();
		$fresh_downloads  = absint( isset( $generation_stats['freshDownloads'] ) ? $generation_stats['freshDownloads'] : 0 );
		$reused_links     = absint( isset( $generation_stats['reusedLinks'] ) ? $generation_stats['reusedLinks'] : 0 );
		$bytes_before     = absint( isset( $generation_stats['bytesBefore'] ) ? $generation_stats['bytesBefore'] : 0 );
		$bytes_after      = absint( isset( $generation_stats['bytesAfter'] ) ? $generation_stats['bytesAfter'] : 0 );
		$bytes_measured   = $fresh_downloads > 0 || $bytes_before > 0 || $bytes_after > 0;
		$bytes_delta      = $bytes_after - $bytes_before;
		if ( ! $bytes_measured ) {
			$bytes_delta_label = 'هنوز اندازه‌گیری نشده';
		} elseif ( $bytes_delta < 0 ) {
			$bytes_delta_label = 'کاهش ' . size_format( abs( $bytes_delta ), 1 );
		} elseif ( $bytes_delta > 0 ) {
			$bytes_delta_label = 'افزایش ' . size_format( $bytes_delta, 1 );
		} else {
			$bytes_delta_label = 'بدون تغییر';
		}

		$queue_failed       = absint( isset( $queue_status['failed'] ) ? $queue_status['failed'] : 0 );
		$subsize_quarantined = Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_subsize_quarantined', '0' );
		$stage7             = Mobo_Core_Settings::get( 'mobo_core_image_refresh_last_replaced_delete', array() );
		$stage7             = is_array( $stage7 ) ? $stage7 : array();
		$stage7_blocked     = absint( isset( $stage7['blocked'] ) ? $stage7['blocked'] : 0 );
		$stage7_errors      = absint( isset( $stage7['errors'] ) ? $stage7['errors'] : 0 );
		$orphan_failed      = absint( isset( $orphan_status['failed'] ) ? $orphan_status['failed'] : 0 );
		$has_retained_items = $queue_failed > 0 || $subsize_quarantined || $stage7_blocked > 0 || $stage7_errors > 0 || $orphan_failed > 0;

		$started_at   = absint( isset( $automation_status['startedAt'] ) ? $automation_status['startedAt'] : 0 );
		$completed_at = absint( isset( $automation_status['completedAt'] ) ? $automation_status['completedAt'] : 0 );
		$last_run_at  = absint( isset( $automation_status['lastRunAt'] ) ? $automation_status['lastRunAt'] : 0 );
		$started_label   = $started_at > 0 ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', $started_at ) : 'هنوز شروع نشده';
		$completed_label = $completed_at > 0 ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', $completed_at ) : '—';
		$last_run_label  = $last_run_at > 0 ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', $last_run_at ) : 'هنوز اجرا ثبت نشده';

		$blocking_statuses = array( 'image-environment-not-ready', 'missing-components', 'missing-orphan-cleanup', 'missing-preflight-cleanup', 'automation-exception' );
		$waiting_statuses  = array( 'waiting-retry', 'waiting-active-processor', 'waiting-subsize-retry', 'waiting-preflight-worker', 'deferred-recovery', 'pipeline-locked', 'locked', 'waiting-delete-approval' );
		$automation_paused = ! empty( $automation_status['paused'] );
		$delete_approved   = ! empty( $automation_status['deleteApproved'] );
		$waiting_approval  = $automation_active && 'waiting-delete-approval' === $automation_state;

		$state_class = 'info';
		$state_label = 'آماده شروع';
		if ( ! $connection_ready ) {
			$state_class = 'danger';
			$state_label = 'اتصال آماده نیست';
		} elseif ( ! $components_ready ) {
			$state_class = 'danger';
			$state_label = 'اجزای لازم در دسترس نیست';
		} elseif ( $batch_stuck ) {
			$state_class = 'danger';
			$state_label = 'Worker نیازمند بررسی';
		} elseif ( $automation_paused ) {
			$state_class = 'warning';
			$state_label = 'متوقف‌شده توسط مدیر';
		} elseif ( $waiting_approval ) {
			$state_class = 'warning';
			$state_label = 'منتظر تأیید حذف توسط مدیر';
		} elseif ( 'completed' === $automation_state ) {
			$state_class = $has_retained_items ? 'warning' : 'success';
			$state_label = $has_retained_items ? 'تکمیل با موارد حفظ‌شده' : 'کامل و پایدار';
		} elseif ( $automation_active && ( in_array( $automation_state, $blocking_statuses, true ) || $last_result_failed ) ) {
			$state_class = 'warning';
			$state_label = 'منتظر رفع مشکل / Retry';
		} elseif ( $automation_active && in_array( $automation_state, $waiting_statuses, true ) ) {
			$state_class = 'info';
			$state_label = 'در انتظار ادامه خودکار';
		} elseif ( $automation_active ) {
			$state_class = 'info';
			$state_label = $has_retained_items ? 'در حال اجرا با موارد حفظ‌شده' : 'در حال اجرا';
		} elseif ( ! $repair_completed ) {
			$state_class = $repair_active ? 'info' : 'warning';
			$state_label = $repair_active ? 'در انتظار پایان Repair' : 'ابتدا Repair لازم است';
		}

		$step_labels = array(
			0 => 'پاک‌سازی اولیه و بررسی پیش‌نیاز',
			1 => 'بررسی تصاویر Mobo و منبع آن‌ها',
			2 => 'ساخت صف نوسازی',
			3 => 'دریافت و جایگزینی تصاویر',
			4 => 'بررسی سلامت برش‌های WebP',
			5 => 'بازسازی خودکار برش‌ها',
			6 => 'Safety Audit پیوست‌های قدیمی',
			7 => 'انتقال مراجع و پاک‌سازی امن',
			8 => 'بررسی خانواده‌های فایل بدون پیوست',
			9 => 'پاک‌سازی امن فایل‌های یتیم',
		);
		$current_stage = isset( $step_labels[ $automation_step ] ) ? $step_labels[ $automation_step ] : 'در حال ادامه خودکار';
		if ( $repair_active ) {
			$current_stage = 'Repair مستقل محصولات — ' . absint( isset( $repair_status['progressPercent'] ) ? $repair_status['progressPercent'] : 0 ) . '٪';
		} elseif ( 'completed' === $automation_state ) {
			$current_stage = 'چرخه کامل شده است';
		} elseif ( ! $automation_active && $repair_completed ) {
			$current_stage = 'آماده شروع چرخه جدید';
		}

		$stage_done   = 0;
		$stage_total  = 0;
		$stage_detail = '';
		if ( $repair_active ) {
			$stage_done   = absint( isset( $repair_status['processedProducts'] ) ? $repair_status['processedProducts'] : 0 );
			$stage_total  = absint( isset( $repair_status['productTotalCount'] ) ? $repair_status['productTotalCount'] : 0 );
			$stage_detail = 'Image Refresh منتظر تکمیل Repair مستقل محصولات است.';
		} elseif ( $automation_active && 1 === $automation_step ) {
			$scan         = Mobo_Core_Settings::get( 'mobo_core_image_refresh_last_scan', array() );
			$scan         = is_array( $scan ) ? $scan : array();
			$stage_done   = absint( isset( $scan['scanned'] ) ? $scan['scanned'] : 0 );
			$stage_total  = absint( isset( $scan['estimatedTotal'] ) ? $scan['estimatedTotal'] : 0 );
			$stage_detail = sprintf( '%s تصویر بررسی شده', number_format_i18n( $stage_done ) );
		} elseif ( $automation_active && 2 === $automation_step ) {
			$enqueue      = Mobo_Core_Settings::get( 'mobo_core_image_refresh_last_enqueue', array() );
			$enqueue      = is_array( $enqueue ) ? $enqueue : array();
			$stage_done   = absint( isset( $enqueue['scanned'] ) ? $enqueue['scanned'] : 0 );
			$stage_total  = absint( isset( $enqueue['estimatedTotal'] ) ? $enqueue['estimatedTotal'] : 0 );
			$stage_detail = sprintf( '%s ردیف بررسی و %s ردیف وارد صف شده', number_format_i18n( $stage_done ), number_format_i18n( absint( isset( $enqueue['enqueued'] ) ? $enqueue['enqueued'] : 0 ) + absint( isset( $enqueue['requeued'] ) ? $enqueue['requeued'] : 0 ) ) );
		} elseif ( $automation_active && 3 === $automation_step ) {
			$stage_done   = absint( isset( $queue_status['done'] ) ? $queue_status['done'] : 0 ) + absint( isset( $queue_status['skipped'] ) ? $queue_status['skipped'] : 0 ) + $queue_failed;
			$stage_total  = $stage_done + absint( isset( $queue_status['pending'] ) ? $queue_status['pending'] : 0 );
			$stage_detail = sprintf(
				'%s انجام‌شده/ردشده/قرنطینه، %s در انتظار یا پردازش',
				number_format_i18n( $stage_done ),
				number_format_i18n( absint( isset( $queue_status['pending'] ) ? $queue_status['pending'] : 0 ) )
			);
		} elseif ( $automation_active ) {
			$stage_detail = '' !== $message ? $message : 'این مرحله در batchهای محدود و خودکار ادامه پیدا می‌کند.';
		}
		$stage_percent = $stage_total > 0 ? min( 100, round( ( $stage_done / $stage_total ) * 100, 1 ) ) : 0;

		$live_polling = $automation_active || $repair_active;
		$live_refresh_nonce    = wp_create_nonce( 'mobo_core_image_refresh_live_status' );
		$live_refresh_interval = $automation_active ? 4000 : 8000;
		$start_allowed = ! $automation_active && $repair_completed && $connection_ready && $components_ready;
		$start_label = 'completed' === $automation_state ? 'شروع چرخه جدید نوسازی تصاویر' : ( $automation_paused ? 'ادامه نوسازی تصاویر' : 'نوسازی کامل تصاویر از منبع' );
		?>
		<div id="mobo-image-refresh-live-root" data-mobo-image-refresh-live-root data-ajax-url="<?php echo esc_url( $this->admin_ajax_url() ); ?>" data-nonce="<?php echo esc_attr( $live_refresh_nonce ); ?>" data-refresh-interval="<?php echo esc_attr( $live_refresh_interval ); ?>" data-live-polling="<?php echo $live_polling ? '1' : '0'; ?>" data-automation-active="<?php echo $automation_active ? '1' : '0'; ?>">
			<div class="mobo-card mobo-card-wide mobo-image-refresh-command-card">
				<div class="mobo-admin-card-heading">
					<div>
						<h2 class="mobo-admin-section-title">نوسازی کامل تصاویر از منبع</h2>
						<p>یک چرخه خودکار برای دریافت دوباره تصاویر Mobo، جایگزینی امن WebP، بررسی برش‌ها و پاک‌سازی رسانه‌های قدیمیِ اثبات‌شده.</p>
					</div>
					<span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( $state_class ); ?>"><?php echo esc_html( $state_label ); ?></span>
				</div>

				<div class="mobo-status-grid">
					<?php $this->status_box( 'مرحله فعلی', $current_stage ); ?>
					<?php $this->status_box( 'صف در انتظار/پردازش', $queue_table_ready ? absint( isset( $queue_status['pending'] ) ? $queue_status['pending'] : 0 ) : 'در دسترس نیست' ); ?>
					<?php $this->status_box( 'جایگزینی موفق', $queue_table_ready ? absint( isset( $queue_status['done'] ) ? $queue_status['done'] : 0 ) : 'در دسترس نیست' ); ?>
					<?php $this->status_box( 'قرنطینه صف تصاویر', $queue_table_ready ? $queue_failed : 'در دسترس نیست' ); ?>
					<?php $this->status_box( 'دریافت تازه این چرخه', $fresh_downloads ); ?>
					<?php $this->status_box( 'استفاده مجدد این چرخه', $reused_links ); ?>
					<?php $this->status_box( 'تغییر حجم ثبت‌شده', $bytes_delta_label ); ?>
				</div>

				<?php if ( $automation_active || $repair_active ) : ?>
					<div class="mobo-progress-wrap">
						<div class="mobo-progress-meta">
							<span>پیشرفت مرحله فعلی — <?php echo esc_html( $current_stage ); ?></span>
							<strong><?php echo $stage_total > 0 ? esc_html( number_format_i18n( $stage_percent, 1 ) . '٪' ) : esc_html( 'در حال اجرا' ); ?></strong>
						</div>
						<?php if ( $stage_total > 0 ) : ?><div class="mobo-progress"><div style="width: <?php echo esc_attr( $stage_percent ); ?>%;"></div></div><?php endif; ?>
						<?php if ( '' !== $stage_detail ) : ?><p class="description"><?php echo esc_html( $stage_detail ); ?></p><?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! $connection_ready ) : ?>
					<div class="mobo-alert mobo-alert-error">Connection انتخاب‌شده برای اجرای runtime آماده نیست. ابتدا provisioning/enabled بودن Connection و در حالت Multi-Connection، تکمیل ownership migration را در «اتصال و لایسنس» بررسی کنید.</div>
				<?php elseif ( ! $components_ready ) : ?>
					<div class="mobo-alert mobo-alert-error">اجزای داخلی یا جدول‌های لازم برای نوسازی تصاویر در دسترس نیستند. عملیات شروع نمی‌شود تا schema/upgrade افزونه کامل شود.</div>
				<?php elseif ( ! $repair_completed ) : ?>
					<div class="mobo-alert <?php echo $repair_active ? 'mobo-alert-info' : 'mobo-alert-warning'; ?>">
						<?php echo esc_html( $repair_active ? 'Repair کامل محصولات در حال اجرا است. Image Refresh آن را شروع یا Retry نمی‌کند و فقط بعد از تکمیل موفق Repair قابل شروع است.' : 'پیش‌نیاز این عملیات یک Repair کامل و موفق محصولات است. Image Refresh طبق سیاست فعلی Repair را خودکار شروع نمی‌کند.' ); ?>
					</div>
				<?php endif; ?>

				<?php if ( $batch_stuck ) : ?>
					<div class="mobo-alert mobo-alert-error">آخرین batch بیش از محدوده عادی باز مانده و Worker ممکن است گیر کرده باشد. تا روشن‌شدن وضعیت، شروع چرخه جدید انجام ندهید و صفحه «Cron و Worker» را بررسی کنید.</div>
				<?php elseif ( 'completed' === $automation_state && $has_retained_items ) : ?>
					<div class="mobo-alert mobo-alert-warning">چرخه به نقطه پایدار رسیده، اما همه رسانه‌ها حذف/ترمیم نشده‌اند. مواردی که Safety Audit یا Retry نتوانسته ایمن اصلاح کند عمداً حفظ شده‌اند؛ جزئیات فنی پایین صفحه را برای شمارنده‌ها ببینید.</div>
				<?php elseif ( 'completed' === $automation_state ) : ?>
					<div class="mobo-alert mobo-alert-success">چرخه کامل شده و مورد نگه‌داشته‌شده یا خطای عملیاتی شناخته‌شده‌ای در نتیجه فعلی ثبت نشده است.</div>
				<?php elseif ( $automation_active && ( $last_result_failed || in_array( $automation_state, $blocking_statuses, true ) ) ) : ?>
					<div class="mobo-alert mobo-alert-warning"><?php echo esc_html( '' !== $message ? $message : 'مرحله فعلی موقتاً قابل ادامه نیست؛ Workflow فعال می‌ماند و طبق backoff/Worker دوباره تلاش می‌کند.' ); ?></div>
				<?php elseif ( $automation_active ) : ?>
					<div class="mobo-alert mobo-alert-info"><?php echo esc_html( '' !== $message ? $message : 'چرخه فعال است و با batchهای محدود توسط Cron/Self Runner ادامه پیدا می‌کند.' ); ?></div>
				<?php endif; ?>

				<?php if ( $automation_paused ) : ?>
					<div class="mobo-alert mobo-alert-warning">نوسازی تصاویر توسط مدیر متوقف شده است. هیچ دانلود، جایگزینی یا حذفی انجام نمی‌شود. پیشرفت حفظ شده و با «ادامه نوسازی تصاویر» از همان مرحله ادامه می‌یابد؛ تأیید حذف باید دوباره داده شود.</div>
				<?php endif; ?>

				<?php if ( $start_allowed ) : ?>
					<div class="mobo-message mobo-message-warning mobo-image-refresh-backup-warning"><strong>قبل از شروع:</strong> از دیتابیس و <code>wp-content/uploads</code> پشتیبان قابل بازیابی داشته باشید. بدون تأیید صریح زیر، این چرخه هیچ پیوست یا فایلی را حذف نمی‌کند و مرحله‌های حذف منتظر تأیید می‌مانند.</div>
					<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1" onsubmit="return confirm('چرخه نوسازی تصاویر شروع/ادامه شود؟ حذف دائمی فقط وقتی انجام می‌شود که هر دو گزینه تأیید را علامت زده باشید.');">
						<input type="hidden" name="action" value="mobo_core_start_image_refresh_automation">
						<?php wp_nonce_field( 'mobo_core_start_image_refresh_automation', 'mobo_core_nonce' ); ?>
						<p><label><input type="checkbox" name="mobo_backup_confirmed" value="1"> از دیتابیس و پوشه uploads پشتیبان قابل بازیابی دارم.</label></p>
						<p><label><input type="checkbox" name="mobo_approve_delete" value="1"> حذف دائمی پیوست‌های قدیمیِ جایگزین‌شده و فایل‌های یتیمِ اثبات‌شده در همین چرخه را تأیید می‌کنم (اختیاری).</label></p>
						<button type="submit" class="button button-primary"><?php echo esc_html( $start_label ); ?></button>
					</form>
				<?php endif; ?>

				<?php if ( $automation_active ) : ?>
					<div class="mobo-image-refresh-controls" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;margin-top:8px;">
						<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1" onsubmit="return confirm('نوسازی تصاویر متوقف شود؟ واحد کاری در حال اجرا تمام می‌شود و پس از آن هیچ دانلود، جایگزینی یا حذفی انجام نمی‌شود.');">
							<input type="hidden" name="action" value="mobo_core_pause_image_refresh_automation">
							<?php wp_nonce_field( 'mobo_core_pause_image_refresh_automation', 'mobo_core_nonce' ); ?>
							<button type="submit" class="button">توقف نوسازی تصاویر</button>
						</form>
						<?php if ( ! $delete_approved ) : ?>
							<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1" onsubmit="return confirm('حذف دائمی پیوست‌ها و فایل‌های قدیمیِ اثبات‌شده برای همین چرخه تأیید شود؟ این کار برگشت‌پذیر نیست مگر از Backup.');">
								<input type="hidden" name="action" value="mobo_core_approve_image_refresh_delete_old">
								<?php wp_nonce_field( 'mobo_core_approve_image_refresh_delete_old', 'mobo_core_nonce' ); ?>
								<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="mobo_backup_confirmed" value="1"> Backup قابل بازیابی دارم.</label>
								<button type="submit" class="button<?php echo $waiting_approval ? ' button-primary' : ''; ?>">تأیید حذف دائمی برای این چرخه</button>
							</form>
						<?php else : ?>
							<span class="mobo-admin-badge mobo-admin-badge--warning">حذف دائمی برای این چرخه تأیید شده است</span>
						<?php endif; ?>
					</div>
				<?php elseif ( ! $start_allowed && ! $repair_completed && ! $repair_active ) : ?>
					<a class="mobo-btn mobo-btn-primary" href="<?php echo esc_url( $this->admin_page_url() ); ?>">رفتن به نمای کلی برای اجرای Repair</a>
				<?php endif; ?>

				<div class="mobo-note">تصاویر Mobo در هر چرخه برای refresh منبع بررسی می‌شوند؛ URL یکسان مانع refresh نسل جدید نیست. Shared Media همچنان توسط مسیر مرکزی خودش مدیریت می‌شود. خطای یک فایل کل چرخه را متوقف نمی‌کند و Retry/backoff مستقل است.</div>
			</div>

			<?php $this->render_legacy_image_audit_card(); ?>

			<details class="mobo-ux-secondary-details">
				<summary>جزئیات فنی و نتیجه چرخه</summary>
				<div class="mobo-ux-summary-grid mobo-ux-summary-grid--4">
					<?php $this->status_box( 'شروع چرخه', $started_label ); ?>
					<?php $this->status_box( 'آخرین اجرای Worker', $last_run_label ); ?>
					<?php $this->status_box( 'پایان چرخه', $completed_label ); ?>
					<?php $this->status_box( 'وضعیت داخلی', $automation_state ); ?>
				</div>
				<div class="mobo-ux-summary-grid mobo-ux-summary-grid--4">
					<?php $this->status_box( 'Subsize قرنطینه', $subsize_quarantined ? 'بله' : 'خیر' ); ?>
					<?php $this->status_box( 'Stage 7 حفظ‌شده', $stage7_blocked ); ?>
					<?php $this->status_box( 'Stage 7 خطا', $stage7_errors ); ?>
					<?php $this->status_box( 'Orphan cleanup ناموفق', $orphan_table_ready ? $orphan_failed : 'در دسترس نیست' ); ?>
				</div>
				<?php if ( '' !== $message ) : ?><div class="mobo-note"><strong>آخرین پیام Workflow:</strong> <?php echo esc_html( $message ); ?></div><?php endif; ?>
			</details>

			<div class="mobo-auto-refresh mobo-image-refresh-live-bar">
				<span data-mobo-image-refresh-live-message><?php echo esc_html( $live_polling ? 'تا زمان اجرای فعال، وضعیت این صفحه خودکار به‌روزرسانی می‌شود.' : 'در وضعیت فعلی به‌روزرسانی خودکار لازم نیست.' ); ?></span>
				<span data-mobo-image-refresh-live-time><?php echo esc_html( $live_polling ? 'آخرین به‌روزرسانی: همین حالا' : 'برای مشاهده تغییرات خارجی صفحه را تازه کنید.' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Persian label for internal image queue/cleanup statuses.
	 *
	 * @param string $status Internal status.
	 * @return string
	 */
	private function image_refresh_status_label( $status ) {
		$status = sanitize_key( (string) $status );
		$labels = array(
			'pending'             => 'در انتظار',
			'processing'          => 'در حال پردازش',
			'due'                 => 'آماده پردازش',
			'done'                => 'انجام شده',
			'failed'              => 'ناموفق',
			'skipped'             => 'رد شده',
			'disabled'            => 'غیرفعال',
			'empty'               => 'صف خالی',
			'processed'           => 'پردازش انجام شد',
			'locked_until_repair' => 'تا پایان ترمیم محصولات قفل است',
			'workflow_blocked'    => 'پیش نیاز مرحله کامل نشده است',
			'candidate'           => 'آماده حذف',
			'deleted'             => 'حذف شده',
			'missing_table'       => 'جدول در دسترس نیست',
			'registered'          => 'ثبت شده در وردپرس',
			'referenced'          => 'دارای مرجع',
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ( '' !== $status ? $status : '—' );
	}

	/**
	 * Translate legacy English audit messages already stored in queue tables.
	 * New rows are written in Persian, but old installations can still contain
	 * English notes from previous versions.
	 *
	 * @param string $message Stored message.
	 * @return string
	 */
	private function translate_image_refresh_message( $message ) {
		$message = trim( (string) $message );
		if ( '' === $message ) {
			return '';
		}

		$exact = array(
			'Complete unregistered family is safe.' => 'این خانواده کامل است، در وردپرس ثبت نشده و برای حذف آماده است.',
			'Matching WebP attachment/file is missing.' => 'پیوست یا فایل WebP متناظر وجود ندارد.',
			'Legacy family is empty.' => 'خانواده تصویر قدیمی خالی است.',
			'Family contains an invalid or mismatched file.' => 'خانواده شامل فایل نامعتبر یا نامرتبط است.',
			'Family belongs to a WordPress attachment.' => 'این خانواده متعلق به یک پیوست ثبت شده وردپرس است.',
			'At least one family file is referenced in database content/meta/options.' => 'حداقل یکی از فایل های خانواده در محتوا، متادیتا یا تنظیمات دیتابیس مرجع دارد.',
			'Invalid refresh queue row.' => 'اطلاعات این ردیف صف ناقص یا نامعتبر است.',
			'Product does not exist.' => 'محصول مربوط به این ردیف دیگر وجود ندارد.',
			'Old attachment does not exist.' => 'پیوست تصویر قدیمی دیگر وجود ندارد.',
			'Product no longer uses old attachment.' => 'محصول دیگر از تصویر قدیمی استفاده نمی کند.',
			'WebP import failed.' => 'دریافت یا ثبت تصویر WebP ناموفق بود.',
			'Imported attachment is not a valid image.' => 'فایل دریافت شده یک تصویر معتبر نیست.',
			'Replacement attachment is not a distinct WebP image.' => 'تصویر جایگزین یک فایل WebP مستقل و معتبر نیست.',
			'Old attachment kept.' => 'تصویر قدیمی نگه داشته شد.',
			'Old attachment deleted safely.' => 'تصویر قدیمی با موفقیت و به صورت امن حذف شد.',
			'Old attachment and registered WordPress cuts deleted safely.' => 'تصویر قدیمی و برش های ثبت شده آن با موفقیت حذف شدند.',
		);
		if ( isset( $exact[ $message ] ) ) {
			return $exact[ $message ];
		}

		$message = str_replace( 'Extra unregistered leftover cuts removed:', 'تعداد برش های ثبت نشده حذف شده:', $message );
		$message = str_replace( 'Referenced/registered leftovers kept:', 'تعداد فایل های دارای مرجع که نگه داشته شد:', $message );
		$message = str_replace( 'WordPress WebP subsizes updated:', 'تعداد برش های WebP تکمیل شده:', $message );
		$message = str_replace( 'Attachment metadata still references missing files:', 'متادیتای تصویر هنوز به این فایل های مفقود اشاره می کند:', $message );

		return $message;
	}

	/**
	 * Render notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		// Read-only redirect notice parameters produced by this plugin.
		$message = isset( $_GET['mobo_message'] ) ? sanitize_text_field( wp_unslash( $_GET['mobo_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type    = isset( $_GET['mobo_type'] ) ? sanitize_key( wp_unslash( $_GET['mobo_type'] ) ) : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $message ) {
			return;
		}

		$class = 'error' === $type ? 'mobo-alert-error' : ( 'warning' === $type ? 'mobo-alert-warning' : 'mobo-alert-success' );

		?>
		<div class="mobo-alert <?php echo esc_attr( $class ); ?>">
			<?php echo esc_html( $message ); ?>
		</div>
		<?php
	}

	/**
	 * Render tab link.
	 *
	 * @param string $tab Tab.
	 * @param string $label Label.
	 * @param string $active_tab Active tab.
	 * @return void
	 */
	private function tab_link( $tab, $label, $active_tab ) {
		$url = $this->admin_page_url( array( 'tab' => $tab ) );

		?>
		<a class="<?php echo $tab === $active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
			<?php echo esc_html( $label ); ?>
		</a>
		<?php
	}

	/**
	 * Render save button.
	 *
	 * @return void
	 */
	private function save_button( $label = 'ذخیره تنظیمات' ) {
		?>
		<div class="mobo-save-row">
			<button type="submit" class="mobo-btn mobo-btn-primary">
				<?php echo esc_html( $label ); ?>
			</button>
		</div>
		<?php
	}



	private function normalize_admin_persian_label( $value ) {
		$value = trim( (string) $value );
		$value = str_replace( array( 'ي', 'ك', 'ة', 'ۀ', 'ـ' ), array( 'ی', 'ک', 'ه', 'ه', '' ), $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		return null === $value ? '' : $value;
	}

	/**
	 * Render cached complete Mobo shipping-method details.
	 *
	 * @param array $methods Remote methods.
	 * @return void
	 */
	private function render_mobo_shipping_method_catalog( $methods ) {
		if ( empty( $methods ) || ! is_array( $methods ) ) {
			return;
		}
		?>
		<div class="mobo-field mobo-field-full">
			<h3>جزئیات روش‌های ارسال دریافت‌شده از موبو</h3>
			<div style="overflow:auto;max-height:520px;">
				<table class="widefat striped">
					<thead><tr><th>ترتیب / شناسه</th><th>روش</th><th>وضعیت</th><th>هزینه و قواعد</th><th>محدوده</th></tr></thead>
					<tbody>
					<?php foreach ( $methods as $method ) : ?>
						<?php
						$method_id   = isset( $method['id'] ) ? absint( $method['id'] ) : 0;
						$method_type = isset( $method['type'] ) ? sanitize_key( $method['type'] ) : '';
						$method_cost = array_key_exists( 'cost', $method ) ? $method['cost'] : null;
						$rules       = isset( $method['rules'] ) && is_array( $method['rules'] ) ? $method['rules'] : array();
						$status      = isset( $method['status'] ) && is_array( $method['status'] ) ? implode( '، ', $method['status'] ) : 'approved';
						$locations   = array();
						$limits      = array();
						$type_labels = array( 'free' => 'رایگان', 'static' => 'هزینه ثابت', 'rules' => 'قواعد بازه‌ای' );
						foreach ( array( 'countries' => 'کشور', 'states' => 'استان', 'cities' => 'شهر' ) as $key => $label ) {
							if ( ! empty( $method[ $key ] ) && is_array( $method[ $key ] ) ) {
								$names = array_values( array_filter( array_map( 'sanitize_text_field', wp_list_pluck( $method[ $key ], 'name' ) ) ) );
								if ( ! empty( $names ) ) {
									$locations[] = $label . ': ' . implode( '، ', $names );
								}
							}
						}
						$subtotal_range = $this->format_mobo_shipping_range( $method, 'minimum_subtotal', 'maximum_subtotal' );
						$weight_range   = $this->format_mobo_shipping_range( $method, 'minimum_weight', 'maximum_weight' );
						if ( '' !== $subtotal_range ) {
							$limits[] = 'مبلغ: ' . $subtotal_range;
						}
						if ( '' !== $weight_range ) {
							$limits[] = 'وزن: ' . $weight_range;
						}
						?>
						<tr>
							<td><?php echo esc_html( ( isset( $method['position'] ) ? intval( $method['position'] ) : '—' ) . ' / ' . $method_id ); ?></td>
							<td><strong><?php echo esc_html( isset( $method['title'] ) ? $method['title'] : ( 'روش ارسال #' . $method_id ) ); ?></strong><br><code><?php echo esc_html( isset( $type_labels[ $method_type ] ) ? $type_labels[ $method_type ] . ' / ' . $method_type : $method_type ); ?></code><?php if ( ! empty( $method['description'] ) ) : ?><br><?php echo esc_html( $method['description'] ); ?><?php endif; ?></td>
							<td><?php echo esc_html( $status ); ?></td>
							<td>
								پایه: <?php echo esc_html( null !== $method_cost && is_numeric( $method_cost ) ? number_format_i18n( (float) $method_cost ) : '—' ); ?>
								<?php if ( ! empty( $limits ) ) : ?><br><small><?php echo esc_html( implode( ' | ', $limits ) ); ?></small><?php endif; ?>
								<?php foreach ( $rules as $rule ) : ?>
									<?php
									$rule_subtotal = $this->format_mobo_shipping_range( $rule, 'minimum_subtotal', 'maximum_subtotal' );
									$rule_weight   = $this->format_mobo_shipping_range( $rule, 'minimum_weight', 'maximum_weight' );
									$rule_cost     = isset( $rule['cost'] ) && is_numeric( $rule['cost'] ) ? number_format_i18n( (float) $rule['cost'] ) : '—';
									?>
									<br><small><?php echo esc_html( 'قاعده: مبلغ ' . ( '' !== $rule_subtotal ? $rule_subtotal : 'عمومی' ) . ( '' !== $rule_weight ? ' | وزن ' . $rule_weight : '' ) . ' ← ' . $rule_cost ); ?></small>
								<?php endforeach; ?>
							</td>
							<td><?php echo esc_html( empty( $locations ) ? 'عمومی / طبق تنظیم منبع' : implode( ' | ', $locations ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render WooCommerce shipping-method to Mobo shipping-method mapping rules.
	 *
	 * @param array $methods Remote Mobo shipping methods.
	 * @return void
	 */
	private function render_mobo_shipping_rules( $methods ) {
		$manager = class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ? new Mobo_Core_Remote_Shipping_Methods() : null;
		$zones   = $this->get_woocommerce_shipping_zones_for_admin();
		?>
		<div class="mobo-note">Checkout کاملاً در اختیار WooCommerce است. این نگاشت فقط هنگام ثبت سفارش در موبو خوانده می‌شود و هیچ Rate یا روش ارسالی به مشتری اضافه نمی‌کند.</div>
		<?php if ( empty( $methods ) ) : ?>
			<div class="mobo-alert mobo-alert-warning">هنوز روش ارسال موبو در cache پلاگین وجود ندارد. ابتدا «بروزرسانی روش‌های ارسال از MoboCore» را اجرا کنید.</div>
		<?php endif; ?>
		<?php if ( empty( $zones ) ) : ?>
			<div class="mobo-alert mobo-alert-warning"><strong>الزامی:</strong> هیچ روش حمل‌ونقل فعالی در Shipping Zoneهای WooCommerce پیدا نشد. ابتدا روش‌های واقعی فروشگاه را در WooCommerce تعریف کنید.</div>
			<?php return; ?>
		<?php endif; ?>
		<div class="mobo-card mobo-card-wide mobo-shipping-scenario-card">
			<h3>نگاشت روش‌های ارسال فروشگاه به موبو</h3>
			<p class="mobo-help">این نگاشت فقط زمانی مصرف می‌شود که سفارش تماماً موبو و متعلق به یک Connection باشد. سفارش ترکیبی یا Multi-Connection قبل از Resolve روش ارسال Fail-Closed می‌شود.</p>
			<?php foreach ( $zones as $zone ) : ?>
				<?php $zone_id = absint( isset( $zone['id'] ) ? $zone['id'] : 0 ); ?>
				<details class="mobo-shipping-accordion" data-mobo-shipping-zone="<?php echo esc_attr( $zone_id ); ?>" open>
					<summary class="mobo-shipping-accordion-summary"><span><strong><?php echo esc_html( isset( $zone['name'] ) ? $zone['name'] : ( 'Shipping Zone #' . $zone_id ) ); ?></strong><small>روش‌های فعال: <?php echo esc_html( isset( $zone['methods'] ) && is_array( $zone['methods'] ) ? count( $zone['methods'] ) : 0 ); ?></small></span></summary>
					<div class="mobo-shipping-accordion-body">
						<div class="mobo-shipping-method-map-list">
						<?php foreach ( $zone['methods'] as $method ) : ?>
							<?php
							$method_id   = isset( $method['methodId'] ) ? sanitize_key( $method['methodId'] ) : '';
							$instance_id = absint( isset( $method['instanceId'] ) ? $method['instanceId'] : 0 );
							if ( '' === $method_id ) { continue; }
							$ids_key = $manager && method_exists( $manager, 'build_legacy_wc_method_rule_option_key' ) ? $manager->build_legacy_wc_method_rule_option_key( $zone_id, $method_id, $instance_id ) : ( 'mobo_core_wc_shipping_method_map_zone_' . $zone_id . '_' . $method_id . '_' . $instance_id );
							if ( empty( $this->get_shipping_selected_ids_for_admin( $ids_key ) ) && $manager && method_exists( $manager, 'build_wc_method_rule_option_key' ) ) {
								$legacy_values = array();
								foreach ( array( 'mobo_only', 'mixed' ) as $legacy_scenario ) {
									$legacy_key = $manager->build_wc_method_rule_option_key( $zone_id, $method_id, $instance_id, $legacy_scenario );
									$legacy_ids = $this->get_shipping_selected_ids_for_admin( $legacy_key );
									if ( ! empty( $legacy_ids ) ) { $legacy_values[] = absint( $legacy_ids[0] ); }
								}
								$legacy_values = array_values( array_unique( array_filter( $legacy_values ) ) );
								if ( 1 === count( $legacy_values ) ) { Mobo_Core_Settings::update( $ids_key, (string) $legacy_values[0], false ); }
							}
							?>
							<div class="mobo-card mobo-shipping-method-map-card">
								<div class="mobo-shipping-wc-method-info"><strong><?php echo esc_html( isset( $method['title'] ) && '' !== trim( (string) $method['title'] ) ? $method['title'] : 'روش حمل‌ونقل ووکامرس' ); ?></strong><span>نوع: <?php echo esc_html( isset( $method['methodTitle'] ) && '' !== trim( (string) $method['methodTitle'] ) ? $method['methodTitle'] : $method_id ); ?> | Instance #<?php echo esc_html( $instance_id ); ?></span></div>
								<?php $this->render_shipping_method_single_select( 'روش ارسال موبو', $ids_key, $methods, 'اگر مشتری این روش WooCommerce را انتخاب کند، هنگام ثبت بخش موبویی سفارش همین shipping_id به موبو ارسال می‌شود.', array( 'required' => 'required', 'data-mobo-wc-shipping-zone-id' => $zone_id, 'data-mobo-wc-shipping-method-id' => $method_id, 'data-mobo-wc-shipping-instance-id' => $instance_id ) ); ?>
							</div>
						<?php endforeach; ?>
						</div>
					</div>
				</details>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Get WooCommerce shipping zones and active shipping method instances for admin mapping.
	 *
	 * @return array
	 */
	private function get_woocommerce_shipping_zones_for_admin() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! class_exists( 'WC_Shipping_Zone' ) ) {
			return array();
		}

		$zone_ids = array();
		$raw_zones = WC_Shipping_Zones::get_zones();
		if ( is_array( $raw_zones ) ) {
			foreach ( $raw_zones as $zone_data ) {
				$zone_id = 0;
				if ( is_array( $zone_data ) ) {
					$zone_id = isset( $zone_data['id'] ) ? absint( $zone_data['id'] ) : ( isset( $zone_data['zone_id'] ) ? absint( $zone_data['zone_id'] ) : 0 );
				} elseif ( is_object( $zone_data ) && isset( $zone_data->id ) ) {
					$zone_id = absint( $zone_data->id );
				}
				if ( $zone_id > 0 ) {
					$zone_ids[] = $zone_id;
				}
			}
		}

		$zone_ids = array_values( array_unique( array_filter( array_map( 'absint', $zone_ids ) ) ) );
		sort( $zone_ids );

		// Zone 0 is WooCommerce's fallback zone: "Locations not covered by your other zones".
		$zone_ids[] = 0;

		$out = array();
		foreach ( $zone_ids as $zone_id ) {
			$zone = new WC_Shipping_Zone( $zone_id );
			$methods = method_exists( $zone, 'get_shipping_methods' ) ? $zone->get_shipping_methods( true, 'admin' ) : array();
			$normalized_methods = array();

			if ( is_array( $methods ) ) {
				foreach ( $methods as $method ) {
					if ( ! is_object( $method ) ) {
						continue;
					}

					$method_id   = isset( $method->id ) ? sanitize_key( (string) $method->id ) : '';
					$instance_id = isset( $method->instance_id ) ? absint( $method->instance_id ) : 0;
					if ( '' === $method_id ) {
						continue;
					}

					$method_title = method_exists( $method, 'get_method_title' ) ? $method->get_method_title() : $method_id;
					$title = method_exists( $method, 'get_title' ) ? $method->get_title() : ( isset( $method->title ) ? $method->title : $method_id );
					$instance_title = '';

					if ( method_exists( $method, 'get_option' ) ) {
						$instance_title = $method->get_option( 'title', '' );
					}

					if ( '' === trim( (string) $instance_title ) && isset( $method->instance_settings ) && is_array( $method->instance_settings ) && isset( $method->instance_settings['title'] ) ) {
						$instance_title = $method->instance_settings['title'];
					}

					if ( '' !== trim( (string) $instance_title ) ) {
						$title = $instance_title;
					}

					$normalized_methods[] = array(
						'methodId'       => $method_id,
						'instanceId'     => $instance_id,
						'title'          => sanitize_text_field( (string) $title ),
						'instanceTitle'  => sanitize_text_field( (string) $instance_title ),
						'methodTitle'    => sanitize_text_field( (string) $method_title ),
					);
				}
			}

			if ( empty( $normalized_methods ) ) {
				continue;
			}

			$locations = array();
			if ( method_exists( $zone, 'get_zone_locations' ) ) {
				foreach ( $zone->get_zone_locations() as $location ) {
					$locations[] = $this->format_wc_shipping_zone_location_for_admin( $location );
				}
			}

			$out[] = array(
				'id'        => $zone_id,
				'name'      => method_exists( $zone, 'get_zone_name' ) ? sanitize_text_field( (string) $zone->get_zone_name() ) : ( 'Shipping Zone #' . $zone_id ),
				'locations' => array_values( array_filter( $locations ) ),
				'methods'   => $normalized_methods,
			);
		}

		return $out;
	}

	/**
	 * Format one WooCommerce shipping zone location object for admin display.
	 *
	 * @param object|array $location WooCommerce zone location.
	 * @return string
	 */
	private function format_wc_shipping_zone_location_for_admin( $location ) {
		$type = '';
		$code = '';

		if ( is_object( $location ) ) {
			$type = isset( $location->type ) ? sanitize_key( (string) $location->type ) : '';
			$code = isset( $location->code ) ? sanitize_text_field( (string) $location->code ) : '';
		} elseif ( is_array( $location ) ) {
			$type = isset( $location['type'] ) ? sanitize_key( (string) $location['type'] ) : '';
			$code = isset( $location['code'] ) ? sanitize_text_field( (string) $location['code'] ) : '';
		}

		if ( '' === $code ) {
			return '';
		}

		if ( 'state' === $type && false !== strpos( $code, ':' ) ) {
			list( $country, $state ) = explode( ':', $code, 2 );
			$country = strtoupper( sanitize_text_field( $country ) );
			$state   = sanitize_text_field( $state );
			$states  = function_exists( 'WC' ) && WC() && isset( WC()->countries ) ? WC()->countries->get_states( $country ) : array();
			$state_label = is_array( $states ) && isset( $states[ $state ] ) ? sanitize_text_field( (string) $states[ $state ] ) : $state;
			return 'استان: ' . $country . ' - ' . $state_label;
		}

		if ( 'country' === $type ) {
			$countries = function_exists( 'WC' ) && WC() && isset( WC()->countries ) ? WC()->countries->get_countries() : array();
			$label = is_array( $countries ) && isset( $countries[ $code ] ) ? sanitize_text_field( (string) $countries[ $code ] ) : $code;
			return 'کشور: ' . $label;
		}

		if ( 'postcode' === $type ) {
			return 'کدپستی: ' . $code;
		}

		if ( 'continent' === $type ) {
			return 'قاره: ' . $code;
		}

		return $type . ': ' . $code;
	}

	/**
	 * Get mapped Mobo states from the approved manual address mapping.
	 *
	 * @return array
	 */
	private function get_mobo_shipping_mapped_states_for_admin() {
		if ( ! class_exists( 'Mobo_Core_Address_Mapping' ) ) {
			return array();
		}

		$address_mapping = new Mobo_Core_Address_Mapping();
		if ( ! method_exists( $address_mapping, 'get_manual_mapping' ) || ! method_exists( $address_mapping, 'get_cached_mapping' ) ) {
			return array();
		}

		$manual = $address_mapping->get_manual_mapping();
		$cached = $address_mapping->get_cached_mapping();
		$states = isset( $manual['states'] ) && is_array( $manual['states'] ) ? $manual['states'] : array();
		if ( empty( $states ) ) {
			return array();
		}

		$out = array();
		foreach ( $states as $local_key => $mobo_state_id ) {
			$mobo_state_id = absint( $mobo_state_id );
			if ( $mobo_state_id <= 0 ) {
				continue;
			}

			$parts = explode( '|', (string) $local_key, 2 );
			$country = isset( $parts[0] ) ? strtoupper( sanitize_text_field( $parts[0] ) ) : '';
			$state_code = isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '';
			$local_label = $this->get_admin_local_state_label( $country, $state_code );

			if ( ! isset( $out[ $mobo_state_id ] ) ) {
				$out[ $mobo_state_id ] = array(
					'moboStateId'   => $mobo_state_id,
					'moboStateName' => $this->find_admin_mobo_state_name( $cached, $mobo_state_id ),
					'localLabels'   => array(),
				);
			}

			if ( '' !== $local_label ) {
				$out[ $mobo_state_id ]['localLabels'][] = $local_label;
			}
		}

		foreach ( $out as $id => $row ) {
			$out[ $id ]['localLabels'] = array_values( array_unique( array_filter( $row['localLabels'] ) ) );
		}

		uasort( $out, function( $a, $b ) {
			$a_name = isset( $a['moboStateName'] ) ? (string) $a['moboStateName'] : '';
			$b_name = isset( $b['moboStateName'] ) ? (string) $b['moboStateName'] : '';

			$a_is_tehran = $this->is_admin_mobo_state_tehran_label( $a_name );
			$b_is_tehran = $this->is_admin_mobo_state_tehran_label( $b_name );

			if ( $a_is_tehran && ! $b_is_tehran ) {
				return -1;
			}

			if ( ! $a_is_tehran && $b_is_tehran ) {
				return 1;
			}

			return strcasecmp( $a_name, $b_name );
		} );

		return array_values( $out );
	}

	/**
	 * Check whether an admin state label is Tehran so it can be shown first.
	 *
	 * @param string $name State name.
	 * @return bool
	 */
	private function is_admin_mobo_state_tehran_label( $name ) {
		$normalized = $this->normalize_admin_persian_label( $name );
		$lower      = strtolower( $normalized );

		return 'تهران' === $normalized
			|| false !== strpos( $normalized, 'تهران' )
			|| 'tehran' === $lower
			|| false !== strpos( $lower, 'tehran' );
	}

	/**
	 * Validate required address mapping and WooCommerce-to-Mobo shipping mapping.
	 *
	 * @return true|WP_Error
	 */
	private function get_mobo_order_submission_readiness() {
		$result = array(
			'ready'             => false,
			'issues'            => array(),
			'warnings'          => array(),
			'resolverReady'     => false,
			'connectionReady'   => false,
			'credentialsReady'  => false,
			'senderReady'       => false,
			'shippingCount'     => 0,
			'shippingRequired'  => 0,
			'shippingMapped'    => 0,
		);

		if ( class_exists( 'Mobo_Core_Address_Mapping' ) && class_exists( 'Mobo_Core_API_Client' ) ) {
			$result['resolverReady'] = true;
		} else {
			$result['issues'][] = 'ماژول Location Resolver مرکزی در دسترس نیست.';
		}

		$connection_table_exists = class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists();
		$connection = $this->current_admin_connection();
		if ( $connection_table_exists ) {
			$enabled_count = Mobo_Core_Connection_Repository::count_enabled();
			$enabled = is_array( $connection ) && ! empty( $connection['is_enabled'] );
			$provisioned = $enabled && Mobo_Core_Connection_Repository::is_provisioned( $connection );
			$ownership_ready = $enabled_count <= 1 || Mobo_Core_Connection_Repository::multi_connection_runtime_ready();
			if ( ! $enabled ) {
				$result['issues'][] = 'Connection انتخاب‌شده برای runtime فعال نیست.';
			} elseif ( ! $provisioned ) {
				$result['issues'][] = 'Connection هنوز metadata کامل Provider/Source/URL را از Backend دریافت نکرده است.';
			} elseif ( ! $ownership_ready ) {
				$result['issues'][] = 'مالکیت Multi-Connection هنوز برای runtime آماده نیست.';
			} else {
				$provider_key = class_exists( 'Mobo_Core_Provider_Registry' ) ? Mobo_Core_Provider_Registry::normalize_key( $connection['provider_key'] ?? '' ) : '';
				$provider = '' !== $provider_key && class_exists( 'Mobo_Core_Provider_Registry' ) ? Mobo_Core_Provider_Registry::get( $provider_key ) : null;
				if ( ! $provider instanceof Mobo_Core_Provider_Interface ) {
					$result['issues'][] = 'Provider این Connection در build فعلی پشتیبانی نمی‌شود.';
				} elseif ( 'replace' !== sanitize_key( (string) $provider->get_cart_sync_strategy() ) ) {
					$result['issues'][] = 'Provider این Connection قرارداد deterministic replace-cart لازم برای ثبت سفارش را ندارد.';
				} else {
					$result['connectionReady'] = true;
				}
			}
		} else {
			// Legacy single-Connection fallback while an old install is being adopted.
			$site_url = class_exists( 'Mobo_Core_Connection_Repository' ) ? Mobo_Core_Connection_Repository::get_runtime_source_site_url( '' ) : '';
			if ( '' === trim( (string) $site_url ) ) {
				$result['issues'][] = 'آدرس Source برای اتصال موبو در دسترس نیست.';
			} else {
				$result['connectionReady'] = true;
			}
		}

		$username = trim( (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_username', '' ) );
		$password = (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_password', '' );
		if ( '' === $username || '' === $password ) {
			$result['issues'][] = 'نام کاربری و رمز عبور موبو برای ثبت سفارش خودکار لازم است.';
		} else {
			$result['credentialsReady'] = true;
		}

		$sender_name = trim( (string) Mobo_Core_Settings::get( 'mobo_core_mobo_order_sender_name', '' ) );
		$sender_mobile = trim( (string) Mobo_Core_Settings::get( 'mobo_core_mobo_order_sender_mobile', '' ) );
		if ( '' === $sender_name || '' === $sender_mobile ) {
			$result['issues'][] = 'نام و شماره موبایل فرستنده برای payload سفارش موبو لازم است.';
		} else {
			$result['senderReady'] = true;
		}

		if ( ! class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ) {
			$result['issues'][] = 'ماژول نگاشت روش ارسال موبو در دسترس نیست.';
			$result['issues'] = array_values( array_unique( $result['issues'] ) );
			return $result;
		}

		$manager = new Mobo_Core_Remote_Shipping_Methods();
		$remote_methods = method_exists( $manager, 'get_methods' ) ? $manager->get_methods() : array();
		$remote_methods = is_array( $remote_methods ) ? $remote_methods : array();
		$result['shippingCount'] = count( $remote_methods );
		$remote_ids = array();
		foreach ( $remote_methods as $remote_method ) {
			$id = is_array( $remote_method ) ? absint( $remote_method['id'] ?? 0 ) : 0;
			if ( $id > 0 ) { $remote_ids[ $id ] = true; }
		}
		if ( empty( $remote_ids ) ) {
			$result['issues'][] = 'هنوز هیچ روش ارسال فعلی از MoboCore دریافت نشده است.';
		}

		$zones = $this->get_woocommerce_shipping_zones_for_admin();
		if ( empty( $zones ) ) {
			$result['issues'][] = 'حداقل یک Shipping Zone و یک روش حمل‌ونقل فعال WooCommerce لازم است.';
		} else {
			$missing = array();
			$stale = array();
			foreach ( $zones as $zone ) {
				$zone_id   = absint( $zone['id'] ?? 0 );
				$zone_name = isset( $zone['name'] ) ? (string) $zone['name'] : ( 'Shipping Zone #' . $zone_id );
				$methods   = isset( $zone['methods'] ) && is_array( $zone['methods'] ) ? $zone['methods'] : array();
				foreach ( $methods as $method ) {
					$method_id   = isset( $method['methodId'] ) ? sanitize_key( $method['methodId'] ) : '';
					$instance_id = absint( $method['instanceId'] ?? 0 );
					if ( '' === $method_id ) { continue; }
					$result['shippingRequired']++;
					$key = method_exists( $manager, 'build_legacy_wc_method_rule_option_key' )
						? $manager->build_legacy_wc_method_rule_option_key( $zone_id, $method_id, $instance_id )
						: ( 'mobo_core_wc_shipping_method_map_zone_' . $zone_id . '_' . $method_id . '_' . $instance_id );
					$ids = $this->get_shipping_selected_ids_for_admin( $key );
					$method_title = isset( $method['title'] ) && '' !== trim( (string) $method['title'] ) ? (string) $method['title'] : ( isset( $method['methodTitle'] ) ? (string) $method['methodTitle'] : $method_id );
					$label = $zone_name . ' / ' . $method_title;
					if ( empty( $ids ) ) {
						$missing[] = $label;
						continue;
					}
					$selected_id = absint( $ids[0] );
					if ( $selected_id <= 0 || ! isset( $remote_ids[ $selected_id ] ) ) {
						$stale[] = $label . ' → #' . $selected_id;
						continue;
					}
					$result['shippingMapped']++;
				}
			}
			if ( ! empty( $missing ) ) {
				$preview = implode( '، ', array_slice( $missing, 0, 4 ) );
				if ( count( $missing ) > 4 ) { $preview .= ' و ' . ( count( $missing ) - 4 ) . ' مورد دیگر'; }
				$result['issues'][] = 'برای همه روش‌های فعال WooCommerce نگاشت موبو لازم است. ناقص: ' . $preview;
			}
			if ( ! empty( $stale ) ) {
				$preview = implode( '، ', array_slice( $stale, 0, 4 ) );
				if ( count( $stale ) > 4 ) { $preview .= ' و ' . ( count( $stale ) - 4 ) . ' مورد دیگر'; }
				$result['issues'][] = 'بعضی نگاشت‌ها به shipping_idای اشاره می‌کنند که دیگر در snapshot فعلی MoboCore وجود ندارد: ' . $preview;
			}
		}

		$result['issues'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $result['issues'] ) ) ) );
		$result['ready'] = empty( $result['issues'] );
		return $result;
	}

	/**
	 * Validate required automatic-order configuration against the same readiness
	 * contract shown in the admin summary.
	 *
	 * @return true|WP_Error
	 */
	private function validate_mobo_order_submission_required_config() {
		$readiness = $this->get_mobo_order_submission_readiness();
		if ( ! empty( $readiness['ready'] ) ) {
			return true;
		}
		$issues = isset( $readiness['issues'] ) && is_array( $readiness['issues'] ) ? $readiness['issues'] : array();
		$message = ! empty( $issues ) ? implode( ' | ', array_slice( $issues, 0, 5 ) ) : 'پیش‌نیازهای ثبت سفارش خودکار کامل نیست.';
		return new WP_Error( 'mobo_order_submission_not_ready', $message );
	}

	/**
	 * Get readable local state label for admin.
	 *
	 * @param string $country Country code.
	 * @param string $state_code State code.
	 * @return string
	 */
	private function get_admin_local_state_label( $country, $state_code ) {
		$country = strtoupper( sanitize_text_field( (string) $country ) );
		$state_code = sanitize_text_field( (string) $state_code );
		$name = '';
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_object( WC()->countries ) && method_exists( WC()->countries, 'get_states' ) ) {
			$states = WC()->countries->get_states( $country );
			if ( is_array( $states ) && isset( $states[ $state_code ] ) ) {
				$name = sanitize_text_field( (string) $states[ $state_code ] );
			}
		}
		if ( '' === $name ) {
			$name = $state_code;
		}
		return '' !== $country ? $country . ' - ' . $name : $name;
	}

	/**
	 * Find Mobo state name in cached mapping.
	 *
	 * @param array $cached Cached address mapping.
	 * @param int   $state_id Mobo state ID.
	 * @return string
	 */
	private function find_admin_mobo_state_name( $cached, $state_id ) {
		$state_id = absint( $state_id );
		$states = isset( $cached['states'] ) && is_array( $cached['states'] ) ? $cached['states'] : array();
		foreach ( $states as $state ) {
			if ( is_array( $state ) && isset( $state['id'] ) && absint( $state['id'] ) === $state_id ) {
				$name = isset( $state['name'] ) ? sanitize_text_field( (string) $state['name'] ) : '';
				return '' !== $name ? $name : ( '#' . $state_id );
			}
		}
		return '#' . $state_id;
	}

	/**
	 * Render shipping method single-select.
	 *
	 * @param string $label Label.
	 * @param string $ids_key Option key.
	 * @param array  $methods Methods.
	 * @param string $help Help.
	 * @return void
	 */
	private function render_shipping_method_single_select( $label, $ids_key, $methods, $help, $extra_attrs = array() ) {
		$selected_ids = $this->get_shipping_selected_ids_for_admin( $ids_key );
		$selected_id  = ! empty( $selected_ids ) ? absint( $selected_ids[0] ) : 0;
		$attr_html = '';
		if ( is_array( $extra_attrs ) ) {
			foreach ( $extra_attrs as $attr_key => $attr_value ) {
				$attr_key = sanitize_key( (string) $attr_key );
				if ( '' === $attr_key ) {
					continue;
				}
				$attr_html .= ' ' . esc_attr( $attr_key ) . '="' . esc_attr( (string) $attr_value ) . '"';
			}
		}
		?>
		<div class="mobo-field mobo-field-full">
			<label><?php echo esc_html( $label ); ?></label>
			<?php if ( empty( $methods ) ) : ?>
				<div class="mobo-help">هنوز روش ارسال موبو در cache پلاگین وجود ندارد. از دکمه «بروزرسانی روش‌های ارسال از MoboCore» استفاده کنید.</div>
			<?php else : ?>
				<input type="hidden" name="<?php echo esc_attr( $ids_key ); ?>_posted" value="1">
				<select name="<?php echo esc_attr( $ids_key ); ?>" class="mobo-shipping-select2 mobo-shipping-single-select" data-placeholder="یک روش ارسال موبو انتخاب کنید" style="width:100%;"<?php echo $attr_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<option value="">— انتخاب نشده —</option>
					<?php foreach ( $methods as $method ) : ?>
						<?php $id = absint( isset( $method['id'] ) ? $method['id'] : 0 ); ?>
						<?php if ( $id <= 0 ) { continue; } ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $selected_id ); ?>>
							<?php echo esc_html( isset( $method['title'] ) ? $method['title'] : ( 'روش ارسال #' . $id ) ); ?> — <?php echo esc_html( isset( $method['type'] ) ? $method['type'] : '' ); ?> — <?php echo esc_html( isset( $method['cost'] ) ? number_format_i18n( (float) $method['cost'] ) : '0' ); ?> تومان — <?php echo esc_html( $id ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<div class="mobo-help"><?php echo esc_html( $help ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get selected shipping IDs for admin select.
	 *
	 * @param string $ids_key Option key.
	 * @return array
	 */
	private function get_shipping_selected_ids_for_admin( $ids_key ) {
		$stored = Mobo_Core_Settings::get( $ids_key, '' );
		$selected_ids = array();
		foreach ( preg_split( '/[\s,]+/', is_array( $stored ) ? implode( ',', $stored ) : (string) $stored ) as $part ) {
			$id = absint( $part );
			if ( $id > 0 ) {
				$selected_ids[] = $id;
			}
		}
		return array_values( array_unique( $selected_ids ) );
	}

	/**
	 * Format a nullable Mobo shipping range for the administrator table.
	 *
	 * @param array  $item Item or rule.
	 * @param string $minimum_key Minimum field.
	 * @param string $maximum_key Maximum field.
	 * @return string
	 */
	private function format_mobo_shipping_range( $item, $minimum_key, $maximum_key ) {
		if ( ! is_array( $item ) ) {
			return '';
		}

		$minimum = array_key_exists( $minimum_key, $item ) && is_numeric( $item[ $minimum_key ] ) ? (float) $item[ $minimum_key ] : null;
		$maximum = array_key_exists( $maximum_key, $item ) && is_numeric( $item[ $maximum_key ] ) ? (float) $item[ $maximum_key ] : null;
		if ( null === $minimum && null === $maximum ) {
			return '';
		}

		return ( null === $minimum ? '—' : number_format_i18n( $minimum ) ) . ' تا ' . ( null === $maximum ? '—' : number_format_i18n( $maximum ) );
	}

	/**
	 * Render WooCommerce product shipping-class selector for Mobo products.
	 *
	 * @return void
	 */
	private function render_mobo_shipping_class_select() {
		$selected = Mobo_Core_Settings::get_int( 'mobo_core_mobo_shipping_class_id', 0, 0, PHP_INT_MAX );
		$terms = taxonomy_exists( 'product_shipping_class' )
			? get_terms( array( 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ) )
			: array();
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		?>
		<div class="mobo-field mobo-field-full">
			<label for="mobo_core_mobo_shipping_class_id">کلاس حمل‌ونقل مجازی محصولات موبو</label>
			<select id="mobo_core_mobo_shipping_class_id" name="mobo_core_mobo_shipping_class_id" class="mobo-category-select2" style="width:100%;">
				<option value="0">— بدون تغییر کلاس —</option>
				<?php foreach ( $terms as $term ) : ?>
					<option value="<?php echo esc_attr( absint( $term->term_id ) ); ?>" <?php selected( absint( $term->term_id ), $selected ); ?>>
						<?php echo esc_html( $term->name . ' (' . $term->slug . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<div class="mobo-help">اگر فهرست خالی است، ابتدا کلاس را در تنظیمات حمل‌ونقل WooCommerce بسازید و صفحه را تازه کنید.</div>
		</div>
		<?php
	}

	/**
	 * B1: the value a toggle must DISPLAY is the value runtime uses. A hard-coded
	 * '0' fallback showed "off" for every unset key of a new Connection although
	 * runtime used the documented default ('1' for stock/price/images/...), and
	 * one Save then really switched those updates off. Unset keys now fall back to
	 * Mobo_Core_Settings::defaults(); unknown keys keep '0'.
	 *
	 * @param string $key Option key.
	 * @return string '1' or '0'.
	 */
	public static function effective_bool_value( $key ) {
		$defaults = Mobo_Core_Settings::defaults();
		$fallback = array_key_exists( $key, $defaults ) ? $defaults[ $key ] : '0';
		$raw = Mobo_Core_Settings::get( $key, $fallback );
		return in_array( strtolower( trim( (string) $raw ) ), array( '1', 'yes', 'true', 'on' ), true ) ? '1' : '0';
	}

	/**
	 * Bool field.
	 *
	 * @param string $label Label.
	 * @param string $key Option key.
	 * @return void
	 */
	private function bool_field( $label, $key, $disabled = false, $disabled_reason = '', $help = '', $default = null ) {
		$value = ( null !== $default && ! array_key_exists( $key, Mobo_Core_Settings::defaults() ) ) ? ( in_array( strtolower( trim( (string) Mobo_Core_Settings::get( $key, $default ) ) ), array( '1', 'yes', 'true', 'on' ), true ) ? '1' : '0' ) : self::effective_bool_value( $key );
		$on_id = $key . '_on';
		$off_id = $key . '_off';
		?>
		<div class="mobo-field mobo-toggle-field<?php echo $disabled ? ' mobo-field-disabled' : ''; ?>">
			<span class="mobo-toggle-field__label"><?php echo esc_html( $label ); ?></span>
			<div class="mobo-bool-control" role="group" aria-label="<?php echo esc_attr( $label ); ?>">
				<input class="mobo-bool-control__input" type="radio" id="<?php echo esc_attr( $on_id ); ?>" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $value, '1' ); ?> <?php disabled( $disabled ); ?>>
				<label class="mobo-bool-control__option" for="<?php echo esc_attr( $on_id ); ?>">روشن</label>
				<input class="mobo-bool-control__input" type="radio" id="<?php echo esc_attr( $off_id ); ?>" name="<?php echo esc_attr( $key ); ?>" value="0" <?php checked( $value, '0' ); ?> <?php disabled( $disabled ); ?>>
				<label class="mobo-bool-control__option" for="<?php echo esc_attr( $off_id ); ?>">خاموش</label>
			</div>
			<?php if ( '' !== trim( (string) $help ) ) : ?>
				<div class="mobo-help"><?php echo esc_html( $help ); ?></div>
			<?php endif; ?>
			<?php if ( $disabled && '' !== (string) $disabled_reason ) : ?>
				<small class="mobo-disabled-reason"><?php echo esc_html( $disabled_reason ); ?></small>
			<?php endif; ?>
		</div>
		<?php
	}


	/**
	 * Cache-plugin integration mode (installation-wide).
	 *
	 * @return void
	 */
	private function cache_integration_mode_field() {
		$key   = 'mobo_core_cache_integration_mode';
		$value = Mobo_Core_Settings::sanitize_cache_integration_mode( get_option( $key, 'safe' ) );
		$options = array(
			'safe'       => 'امن — پیشنهادی',
			'aggressive' => 'تهاجمی',
			'off'        => 'خاموش',
		);
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>">حالت هماهنگی با افزونه کش</label>
			<select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
				<?php foreach ( $options as $option_value => $label ) : ?>
					<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<div class="mobo-help">
				این تنظیم مشخص می‌کند موبو بعد از همگام‌سازی محصولات چطور با افزونه‌های کش (WP Rocket، LiteSpeed Cache، W3 Total Cache، WP Super Cache) هماهنگ شود.
				<br><strong>امن (پیشنهادی):</strong> فقط کش صفحه محصولات تغییرکرده و با سقف محدود در هر درخواست پاک می‌شود. اگر تعداد زیادی محصول در مدت کوتاه تغییر کند (مثل تغییر قیمت انبوه از منبع)، پاک‌سازی‌ها تجمیع و مرحله‌ای انجام می‌شوند. صفحه اصلی و کل کش سایت هرگز از طرف همگام‌سازی پاک نمی‌شود. مناسب هاست‌های اشتراکی.
				<br><strong>تهاجمی:</strong> سقف‌ها بزرگ‌تر است و صفحه اصلی هم در پاک‌سازی تجمیعی دوره‌ای تازه می‌شود. فقط برای سرورهای قوی.
				<br><strong>خاموش:</strong> موبو هیچ پاک‌سازی کش یا پیش‌بارگذاری انجام نمی‌دهد و صفحات کش‌شده تا پایان عمر کش (مثلاً ۱۰ ساعت در WP Rocket) ممکن است قیمت قدیمی نشان دهند. در هر سه حالت، موبو جلوی پاک‌سازی پشت‌سرهم کل صفحات مرتبط و صفحه اصلی توسط WP Rocket را هنگام ذخیره هر محصول در همگام‌سازی می‌گیرد.
			</div>
		</div>
		<?php
	}

	/**
	 * Save the installation-wide cache-plugin integration mode.
	 *
	 * @return void
	 */
	private function save_cache_integration_mode_from_post() {
		$key = 'mobo_core_cache_integration_mode';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_save_settings() verifies mobo_core_nonce before dispatching to this private helper.
		if ( ! isset( $_POST[ $key ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is verified by the caller; value is whitelisted.
		$value = Mobo_Core_Settings::sanitize_cache_integration_mode( wp_unslash( $_POST[ $key ] ) );
		update_option( $key, $value, false );
	}

	/**
	 * Deferred archive page-cache purge interval.
	 *
	 * @return void
	 */
	private function cache_archive_purge_interval_field() {
		$key     = 'mobo_core_cache_archive_purge_interval_minutes';
		$value   = Mobo_Core_Settings::sanitize_cache_archive_purge_interval( Mobo_Core_Settings::get( $key, '15' ), 15 );
		$recommended_options = array(
			0  => 'غیرفعال',
			5  => 'هر 5 دقیقه',
			15 => 'هر 15 دقیقه — پیشنهادی',
			30 => 'هر 30 دقیقه',
			60 => 'هر 1 ساعت',
		);
		$other_options = array(
			10 => 'هر 10 دقیقه',
			20 => 'هر 20 دقیقه',
			25 => 'هر 25 دقیقه',
			45 => 'هر 45 دقیقه',
		);
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>">فاصله پاک‌سازی صفحات مرتبط</label>
			<select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
				<optgroup label="گزینه‌های پیشنهادی">
					<?php foreach ( $recommended_options as $minutes => $label ) : ?>
						<option value="<?php echo esc_attr( $minutes ); ?>" <?php selected( $value, $minutes ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</optgroup>
				<optgroup label="گزینه‌های دیگر">
					<?php foreach ( $other_options as $minutes => $label ) : ?>
						<option value="<?php echo esc_attr( $minutes ); ?>" <?php selected( $value, $minutes ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			</select>
			<div class="mobo-help">کش خود محصول بلافاصله تازه می‌شود. صفحات فروشگاه، دسته‌ها، برچسب‌ها و صفحه اصلی با فاصله انتخاب‌شده پاک‌سازی می‌شوند تا فشار روی سایت کمتر شود.</div>
		</div>
		<?php
	}

	/**
	 * Save the product-tab deferred archive purge interval.
	 *
	 * @return void
	 */
	private function save_cache_archive_purge_interval_from_post() {
		$key = 'mobo_core_cache_archive_purge_interval_minutes';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_save_settings() verifies mobo_core_nonce before dispatching to this private helper.
		if ( ! isset( $_POST[ $key ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is verified by the caller; the dedicated sanitizer validates the allowed interval set.
		$value = Mobo_Core_Settings::sanitize_cache_archive_purge_interval( wp_unslash( $_POST[ $key ] ), 15 );
		update_option( $key, (string) $value, false );

		if ( class_exists( 'Mobo_Core_Cache_Purger' ) && method_exists( 'Mobo_Core_Cache_Purger', 'handle_archive_interval_changed' ) ) {
			Mobo_Core_Cache_Purger::handle_archive_interval_changed( $value );
		}
	}

	/**
	 * Integer field.
	 *
	 * @param string $label Label.
	 * @param string $key Option key.
	 * @param int    $min Min.
	 * @param int    $max Max.
	 * @return void
	 */
	private function int_field( $label, $key, $min, $max, $default = null ) {
		if ( null === $default ) {
			$default = $min;
		}
		$value = absint( Mobo_Core_Settings::get( $key, $default ) );
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>

			<input
				type="number"
				id="<?php echo esc_attr( $key ); ?>"
				name="<?php echo esc_attr( $key ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				min="<?php echo esc_attr( absint( $min ) ); ?>"
				max="<?php echo esc_attr( absint( $max ) ); ?>"
				dir="ltr"
				style="text-align:left;"
				step="1"
			>
		</div>
		<?php
	}

	/**
	 * Textarea field.
	 *
	 * @param string $label Label.
	 * @param string $key Option key.
	 * @param string $help Help.
	 * @return void
	 */
	private function textarea_field( $label, $key, $help = '' ) {
		$value = (string) Mobo_Core_Settings::get( $key, '' );
		?>
		<div class="mobo-field mobo-field-full">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>

			<textarea
				id="<?php echo esc_attr( $key ); ?>"
				name="<?php echo esc_attr( $key ); ?>"
				rows="8"
				dir="ltr"
			><?php echo esc_textarea( $value ); ?></textarea>

			<?php if ( '' !== $help ) : ?>
				<div class="mobo-help"><?php echo esc_html( $help ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a generic guide box.
	 *
	 * @param string $title Guide title.
	 * @param array  $items Guide cards.
	 * @param string $warning Optional warning.
	 * @param array  $flow Optional ordered flow items.
	 * @return void
	 */
	private function guide_box( $title, $items, $warning = '', $flow = array() ) {
		?>
		<div class="mobo-guide-box" aria-label="<?php echo esc_attr( $title ); ?>">
			<div class="mobo-guide-title"><?php echo esc_html( $title ); ?></div>

			<?php if ( ! empty( $items ) && is_array( $items ) ) : ?>
				<div class="mobo-guide-summary">
					<?php foreach ( $items as $item ) : ?>
						<div>
							<strong><?php echo esc_html( isset( $item['title'] ) ? (string) $item['title'] : '' ); ?></strong>
							<span><?php echo esc_html( isset( $item['text'] ) ? (string) $item['text'] : '' ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( '' !== (string) $warning ) : ?>
				<div class="mobo-guide-warning"><?php echo esc_html( $warning ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $flow ) && is_array( $flow ) ) : ?>
				<div class="mobo-guide-flow">
					<strong>ترتیب پیشنهادی کار</strong>
					<?php foreach ( $flow as $step ) : ?>
						<span><?php echo esc_html( (string) $step ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}


	/**
	 * Render recommended settings table.
	 *
	 * @param string $title Box title.
	 * @param array  $rows Recommendation rows.
	 * @param string $note Optional note.
	 * @return void
	 */
	private function recommendation_box( $title, $rows, $note = '' ) {
		?>
		<div class="mobo-guide-box mobo-recommendation-box" aria-label="<?php echo esc_attr( $title ); ?>">
			<div class="mobo-guide-title"><?php echo esc_html( $title ); ?></div>

			<div class="mobo-guide-table-wrap">
				<table class="widefat mobo-guide-table">
					<thead>
						<tr>
							<th>تنظیم</th>
							<th>مقدار پیشنهادی</th>
							<th>دلیل</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><strong><?php echo esc_html( isset( $row['setting'] ) ? (string) $row['setting'] : '' ); ?></strong></td>
								<td><?php echo esc_html( isset( $row['value'] ) ? (string) $row['value'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $row['reason'] ) ? (string) $row['reason'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( '' !== (string) $note ) : ?>
				<div class="mobo-guide-warning"><?php echo esc_html( $note ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render category sync guide.
	 *
	 * @return void
	 */
	private function category_sync_guide_box() {
		?>
		<div class="mobo-guide-box" aria-label="راهنمای تنظیمات دسته‌بندی">
			<div class="mobo-guide-title">سیستم چگونه دسته محصول را انتخاب می‌کند؟</div>
			<div class="mobo-guide-flow">
				<span>۱) اگر برای دسته منبع نگاشت دستی انتخاب کرده باشید و نگاشت روشن باشد، همان دسته استفاده می‌شود.</span>
				<span>۲) در غیر این صورت، اگر دسته متناظر منبع قبلاً در ووکامرس وجود داشته باشد، همان استفاده می‌شود.</span>
				<span>۳) اگر همگام‌سازی خودکار روشن باشد و دسته‌ای وجود نداشته باشد، MoboCore در صورت داشتن اطلاعات کامل آن را می‌سازد.</span>
				<span>۴) اگر نتیجه معتبری پیدا نشود، محصول موجود بی‌دلیل جابه‌جا نمی‌شود؛ برای محصول جدید می‌توان از دسته جایگزین استفاده کرد.</span>
				<span>۵) اگر نگاشت دستی اجباری باشد، این قانون بر مراحل خودکار اولویت دارد: بدون نگاشت دستی معتبر، حتی دسته متناظر یا ساخت خودکار جای آن را نمی‌گیرد.</span>
			</div>
			<div class="mobo-guide-warning">بعد از تغییر نگاشت‌ها، محصولات جدید و همگام‌سازی‌های بعدی از تنظیم جدید استفاده می‌کنند. برای اعمال فوری روی محصولات موجود، بخش «اعمال نگاشت فعلی روی محصولات موجود» را اجرا کنید.</div><div class="mobo-note">دسته‌های موجود ووکامرس به‌صورت محافظه‌کارانه حفظ می‌شوند؛ MoboCore معمولاً نام، نامک و والد دسته‌ای را که قبلاً در فروشگاه وجود دارد بازنویسی نمی‌کند و فقط در صورت نیاز دسته تازه می‌سازد یا وضعیت داخلی دسته‌های ساخته‌شده توسط خودش را ترمیم می‌کند.</div>
			<details class="mobo-admin-inline-details">
				<summary>جزئیات فنی</summary>
				<div class="mobo-note">هویت دسته منبع با شناسه پایدار منبع نگهداری می‌شود. نام، مسیر و slug فقط برای نمایش و تشخیص بهتر مدیر هستند و جای شناسه پایدار را نمی‌گیرند. دسته‌ای که از snapshot معتبر منبع حذف شود، از فهرست فعال نگاشت کنار گذاشته می‌شود؛ سابقه نگاشت آن برای audit حذف نمی‌شود.</div>
			</details>
		</div>
		<?php
	}


	/** Build readable WooCommerce product-category breadcrumb paths. @return array */
	private function product_category_path_map( $terms ) {
		$by_id = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( $term instanceof WP_Term ) { $by_id[ absint( $term->term_id ) ] = $term; }
		}
		$paths = array();
		$resolve = function ( $term_id, $seen = array() ) use ( &$resolve, &$paths, $by_id ) {
			$term_id = absint( $term_id );
			if ( $term_id <= 0 || ! isset( $by_id[ $term_id ] ) ) { return ''; }
			if ( isset( $paths[ $term_id ] ) ) { return $paths[ $term_id ]; }
			if ( isset( $seen[ $term_id ] ) || count( $seen ) > 30 ) { return sanitize_text_field( (string) $by_id[ $term_id ]->name ); }
			$seen[ $term_id ] = true;
			$name = sanitize_text_field( (string) $by_id[ $term_id ]->name );
			$parent = absint( $by_id[ $term_id ]->parent );
			$parent_path = $parent > 0 ? $resolve( $parent, $seen ) : '';
			$paths[ $term_id ] = '' !== $parent_path ? $parent_path . ' › ' . $name : $name;
			return $paths[ $term_id ];
		};
		foreach ( array_keys( $by_id ) as $term_id ) { $resolve( $term_id ); }
		return $paths;
	}

	/** Build readable remote category breadcrumb paths from the authoritative mapping metadata. @return array */
	private function remote_category_path_map( $rows ) {
		$by_guid = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$guid = sanitize_text_field( (string) ( $row['remote_guid'] ?? '' ) );
			if ( '' !== $guid ) { $by_guid[ $guid ] = $row; }
		}
		$paths = array();
		$resolve = function ( $guid, $seen = array() ) use ( &$resolve, &$paths, $by_guid ) {
			$guid = sanitize_text_field( (string) $guid );
			if ( '' === $guid || ! isset( $by_guid[ $guid ] ) ) { return ''; }
			if ( isset( $paths[ $guid ] ) ) { return $paths[ $guid ]; }
			if ( isset( $seen[ $guid ] ) || count( $seen ) > 30 ) {
				return sanitize_text_field( (string) ( $by_guid[ $guid ]['remote_name'] ?? '' ) );
			}
			$seen[ $guid ] = true;
			$name = sanitize_text_field( (string) ( $by_guid[ $guid ]['remote_name'] ?? '' ) );
			if ( '' === $name ) { $name = 'دسته بدون نام'; }
			$parent_guid = sanitize_text_field( (string) ( $by_guid[ $guid ]['parent_remote_guid'] ?? '' ) );
			$parent_path = '' !== $parent_guid ? $resolve( $parent_guid, $seen ) : '';
			$paths[ $guid ] = '' !== $parent_path ? $parent_path . ' › ' . $name : $name;
			return $paths[ $guid ];
		};
		foreach ( array_keys( $by_guid ) as $guid ) { $resolve( $guid ); }
		return $paths;
	}

	/**
	 * Category mapping table.
	 *
	 * @return void
	 */
	private function category_mapping_table() {
		if ( ! class_exists( 'Mobo_Core_Category_Map' ) ) { return; }

		$category_map = new Mobo_Core_Category_Map();
		$raw_rows     = $category_map->list_mappings( 2000 );
		$terms        = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) { $terms = array(); }
		$term_paths   = $this->product_category_path_map( $terms );
		$remote_paths = $this->remote_category_path_map( $raw_rows );

		$active_snapshot_at = absint( Mobo_Core_Settings::get( 'mobo_core_category_mapping_active_snapshot_at', 0 ) );
		$active_guids = Mobo_Core_Settings::get( 'mobo_core_category_mapping_active_guids', array() );
		$active_set = array();
		if ( $active_snapshot_at > 0 && is_array( $active_guids ) ) {
			foreach ( $active_guids as $guid ) {
				$guid = sanitize_text_field( (string) $guid );
				if ( '' !== $guid ) { $active_set[ $guid ] = true; }
			}
		}
		$all_rows = $raw_rows;
		$retired_count = 0;
		if ( $active_snapshot_at > 0 ) {
			$all_rows = array_values( array_filter( $raw_rows, static function ( $row ) use ( $active_set ) {
				$guid = sanitize_text_field( (string) ( $row['remote_guid'] ?? '' ) );
				return '' !== $guid && isset( $active_set[ $guid ] );
			} ) );
			$retired_count = max( 0, count( $raw_rows ) - count( $all_rows ) );
		}

		$mapping_enabled  = Mobo_Core_Settings::enabled( 'mobo_core_category_mapping_enabled', '1' );
		$mapping_required = Mobo_Core_Settings::enabled( 'mobo_core_category_mapping_required', '0' );
		$auto_categories  = Mobo_Core_Settings::enabled( 'global_update_categories', '1' );
		$default_id       = absint( Mobo_Core_Settings::get( 'mobo_default_category_id', 0 ) );
		$default_path     = isset( $term_paths[ $default_id ] ) ? $term_paths[ $default_id ] : '';
		$total_count      = count( $all_rows );
		$mapped_count     = 0;
		foreach ( $all_rows as $row ) { $manual_id = absint( $row['manual_term_id'] ?? 0 ); if ( $manual_id > 0 && isset( $term_paths[ $manual_id ] ) ) { $mapped_count++; } }
		$unmapped_count   = max( 0, $total_count - $mapped_count );
		$needs_mapping    = $mapping_enabled && $mapping_required ? $unmapped_count : 0;

		$filter = isset( $_GET['mobo_cat_filter'] ) ? sanitize_key( wp_unslash( $_GET['mobo_cat_filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $filter, array( 'all', 'mapped', 'unmapped' ), true ) ) { $filter = 'all'; }
		$search = isset( $_GET['mobo_cat_search'] ) ? sanitize_text_field( wp_unslash( $_GET['mobo_cat_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows = array_values( array_filter( $all_rows, function ( $row ) use ( $filter, $search, $remote_paths, $term_paths ) {
			$manual_id = absint( $row['manual_term_id'] ?? 0 );
			$mapped = $manual_id > 0 && isset( $term_paths[ $manual_id ] );
			if ( 'mapped' === $filter && ! $mapped ) { return false; }
			if ( 'unmapped' === $filter && $mapped ) { return false; }
			if ( '' === $search ) { return true; }
			$guid = sanitize_text_field( (string) ( $row['remote_guid'] ?? '' ) );
			$manual_id = absint( $row['manual_term_id'] ?? 0 );
			$synced_id = absint( $row['synced_term_id'] ?? 0 );
			$haystack = implode( ' ', array_filter( array(
				$remote_paths[ $guid ] ?? '', $guid, (string) ( $row['remote_url'] ?? '' ),
				$term_paths[ $manual_id ] ?? '', $term_paths[ $synced_id ] ?? '',
			) ) );
			return function_exists( 'mb_stripos' ) ? false !== mb_stripos( $haystack, $search, 0, 'UTF-8' ) : false !== stripos( $haystack, $search );
		} ) );

		$per_page = 50;
		$page = isset( $_GET['mobo_cat_page'] ) ? max( 1, absint( wp_unslash( $_GET['mobo_cat_page'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page_count = max( 1, (int) ceil( count( $rows ) / $per_page ) );
		$page = min( $page, $page_count );
		$visible_rows = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
		$filter_url = function ( $value, $page_number = 1 ) use ( $search ) {
			$args = array( 'tab' => 'categories', 'mobo_cat_filter' => $value, 'mobo_cat_page' => max( 1, absint( $page_number ) ) );
			if ( '' !== $search ) { $args['mobo_cat_search'] = $search; }
			return $this->admin_page_url( $args );
		};
		?>
		<div class="mobo-card mobo-category-map-card">
			<div class="mobo-admin-card-heading">
				<div><h2 class="mobo-admin-section-title">نگاشت دسته‌های منبع به ووکامرس</h2><p>ستون «نتیجه فعلی» نشان می‌دهد با تنظیمات فعلی، دسته منبع چگونه تفسیر می‌شود. نگاشت دستی فقط وقتی انتخاب کنید که می‌خواهید این نتیجه را صریحاً تغییر دهید.</p></div>
				<span class="mobo-admin-badge mobo-admin-badge--<?php echo $needs_mapping > 0 ? 'warning' : ( $unmapped_count > 0 ? 'info' : 'success' ); ?>"><?php echo esc_html( $needs_mapping > 0 ? number_format_i18n( $needs_mapping ) . ' دسته نیازمند نگاشت' : ( $unmapped_count > 0 ? 'نگاشت دستی اختیاری' : 'نگاشت دستی کامل' ) ); ?></span>
			</div>
			<div class="mobo-ux-summary-grid mobo-ux-summary-grid--3">
				<?php $this->status_box( 'دسته فعال منبع', number_format_i18n( $total_count ) ); ?>
				<?php $this->status_box( 'نگاشت دستی ثبت‌شده', number_format_i18n( $mapped_count ) ); ?>
				<?php $this->status_box( $mapping_required && $mapping_enabled ? 'نیازمند نگاشت' : 'بدون نگاشت دستی', number_format_i18n( $mapping_required && $mapping_enabled ? $needs_mapping : $unmapped_count ) ); ?>
			</div>
			<?php if ( $retired_count > 0 ) : ?><div class="mobo-note"><?php echo esc_html( sprintf( '%d دسته که دیگر در آخرین فهرست معتبر منبع وجود ندارند، از فهرست فعال کنار گذاشته شده‌اند. سابقه نگاشت آن‌ها حذف نشده است.', $retired_count ) ); ?></div><?php endif; ?>
			<?php if ( empty( $all_rows ) ) : ?>
				<div class="mobo-message mobo-message-info">هنوز دسته فعالی از منبع دریافت نشده است. ابتدا «دریافت تازه‌ترین فهرست دسته‌ها» را اجرا کنید.</div>
			<?php else : ?>
				<div class="mobo-ux-filterbar">
					<div class="mobo-ux-filter-tabs">
						<a class="<?php echo 'all' === $filter ? 'is-active' : ''; ?>" href="<?php echo esc_url( $filter_url( 'all' ) ); ?>">همه</a>
						<a class="<?php echo 'unmapped' === $filter ? 'is-active' : ''; ?>" href="<?php echo esc_url( $filter_url( 'unmapped' ) ); ?>">بدون نگاشت دستی</a>
						<a class="<?php echo 'mapped' === $filter ? 'is-active' : ''; ?>" href="<?php echo esc_url( $filter_url( 'mapped' ) ); ?>">دارای نگاشت دستی</a>
					</div>
					<input type="search" class="mobo-ux-table-search" data-mobo-category-global-search value="<?php echo esc_attr( $search ); ?>" placeholder="جستجو در همه دسته‌های فعال..." aria-label="جستجو در همه دسته‌های فعال">
					<button type="button" class="mobo-btn" data-mobo-category-search-submit>جستجو</button>
					<?php if ( '' !== $search ) : ?><a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( $this->admin_page_url( array( 'tab' => 'categories', 'mobo_cat_filter' => $filter ) ) ); ?>">پاک کردن جستجو</a><?php endif; ?>
					<button type="button" class="mobo-btn" data-mobo-auto-map-categories>پیشنهاد نگاشت برای همین صفحه</button>
					<span class="mobo-help" data-mobo-auto-map-result></span>
				</div>
				<?php if ( empty( $rows ) ) : ?>
					<div class="mobo-message mobo-message-info">برای این جستجو یا فیلتر موردی پیدا نشد.</div>
				<?php else : ?>
				<div class="mobo-table-wrap mobo-category-map-table-wrap">
					<table class="widefat striped mobo-category-map-table" data-mobo-category-table>
						<thead><tr><th>دسته منبع</th><th>نتیجه فعلی</th><th>نگاشت دستی</th></tr></thead>
						<tbody>
						<?php foreach ( $visible_rows as $row ) :
							$remote_guid = sanitize_text_field( (string) ( $row['remote_guid'] ?? '' ) );
							$manual_id   = absint( $row['manual_term_id'] ?? 0 );
							$synced_id   = absint( $row['synced_term_id'] ?? 0 );
							$remote_name = sanitize_text_field( (string) ( $row['remote_name'] ?? '' ) );
							$remote_path = $remote_paths[ $remote_guid ] ?? ( '' !== $remote_name ? $remote_name : 'دسته بدون نام' );
							$result_text = '';
							$result_meta = '';
							$result_state = 'neutral';
							if ( $mapping_enabled && $manual_id > 0 && isset( $term_paths[ $manual_id ] ) ) {
								$result_text = $term_paths[ $manual_id ]; $result_meta = 'نگاشت دستی'; $result_state = 'success';
							} elseif ( $mapping_enabled && $mapping_required ) {
								$result_text = 'نیازمند نگاشت دستی'; $result_meta = 'در حالت نگاشت اجباری، دسته متناظر خودکار هم جای نگاشت دستی را نمی‌گیرد'; $result_state = 'warning';
							} elseif ( $synced_id > 0 && isset( $term_paths[ $synced_id ] ) ) {
								$result_text = $term_paths[ $synced_id ]; $result_meta = 'دسته متناظر منبع'; $result_state = 'success';
							} elseif ( ! $mapping_enabled && $mapping_required ) {
								$result_text = 'نیازمند اصلاح تنظیمات'; $result_meta = 'نگاشت خاموش است اما الزام نگاشت هنوز روشن مانده؛ تا ذخیره تنظیمات، دسته تازه به‌صورت خودکار ساخته نمی‌شود'; $result_state = 'warning';
							} elseif ( $auto_categories ) {
								$result_text = 'در صورت نیاز خودکار ساخته می‌شود'; $result_meta = 'اطلاعات کامل منبع برای ساخت دسته لازم است'; $result_state = 'info';
							} else {
								$result_text = 'برای محصول موجود بدون تغییر'; $result_meta = '' !== $default_path ? 'محصول جدید بدون نتیجه معتبر: ' . $default_path : 'برای محصول جدید دسته جایگزین انتخاب نشده است'; $result_state = 'neutral';
							}
						?>
							<tr data-mobo-category-row>
								<td><strong><?php echo esc_html( $remote_path ); ?></strong><?php if ( '' === $remote_name ) : ?><div class="mobo-help">نام دسته از منبع دریافت نشده است.</div><?php endif; ?></td>
								<td><div class="mobo-category-result mobo-category-result--<?php echo esc_attr( $result_state ); ?>"><strong><?php echo esc_html( $result_text ); ?></strong><?php if ( '' !== $result_meta ) : ?><span><?php echo esc_html( $result_meta ); ?></span><?php endif; ?></div></td>
								<td>
									<select class="mobo-category-select2 mobo-search-select" data-mobo-category-map-select data-remote-name="<?php echo esc_attr( $remote_name ); ?>" data-remote-path="<?php echo esc_attr( $remote_path ); ?>" name="mobo_category_map[<?php echo esc_attr( $remote_guid ); ?>]" data-placeholder="جستجو و انتخاب دسته محلی">
										<option value="0">بدون نگاشت دستی</option>
										<?php foreach ( $terms as $term ) : $term_id = absint( $term->term_id ); $term_path = $term_paths[ $term_id ] ?? $term->name; ?><option value="<?php echo esc_attr( $term_id ); ?>" data-term-name="<?php echo esc_attr( $term->name ); ?>" data-term-path="<?php echo esc_attr( $term_path ); ?>" data-term-slug="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $manual_id, $term_id ); ?>><?php echo esc_html( $term_path ); ?></option><?php endforeach; ?>
									</select>
									<span class="mobo-category-suggestion-note" data-mobo-category-suggestion hidden>پیشنهاد خودکار — قبل از ذخیره بررسی کنید</span>
									<?php if ( ! $mapping_enabled && $manual_id > 0 ) : ?><div class="mobo-help">این نگاشت ذخیره شده است اما تا وقتی «استفاده از نگاشت دستی» خاموش باشد اعمال نمی‌شود.</div><?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php if ( $page_count > 1 ) : ?><div class="mobo-ux-pagination"><span>صفحه <?php echo esc_html( $page ); ?> از <?php echo esc_html( $page_count ); ?> — <?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?> نتیجه</span><div><?php if ( $page > 1 ) : ?><a class="mobo-btn" href="<?php echo esc_url( $filter_url( $filter, $page - 1 ) ); ?>">قبلی</a><?php endif; ?><?php if ( $page < $page_count ) : ?><a class="mobo-btn" href="<?php echo esc_url( $filter_url( $filter, $page + 1 ) ); ?>">بعدی</a><?php endif; ?></div></div><?php endif; ?>
				<?php endif; ?>
				<div class="mobo-note mobo-category-after-save-note"><span>نگاشت‌های ذخیره‌شده در محصولات جدید و همگام‌سازی‌های بعدی استفاده می‌شوند. برای اعمال همین تصمیم روی محصولات موجود، بعد از ذخیره عملیات زیر را اجرا کنید.</span><a class="mobo-btn mobo-btn-light" href="#mobo-category-apply-existing">اعمال روی محصولات موجود</a></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Category dropdown.
	 *
	 * @param string $label Label.
	 * @param string $key Option key.
	 * @return void
	 */
	private function category_dropdown_field( $label, $key ) {
		$selected = absint( Mobo_Core_Settings::get( $key, 0 ) );
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) { $terms = array(); }
		$paths = $this->product_category_path_map( $terms );
		$mapping_required = Mobo_Core_Settings::enabled( 'mobo_core_category_mapping_required', '0' );
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" class="mobo-category-select2 mobo-search-select" data-placeholder="جستجو و انتخاب دسته">
				<option value="0">انتخاب نشده</option>
				<?php foreach ( $terms as $term ) : $term_id = absint( $term->term_id ); $path = $paths[ $term_id ] ?? $term->name; ?>
					<option value="<?php echo esc_attr( $term_id ); ?>" data-term-name="<?php echo esc_attr( $term->name ); ?>" data-term-path="<?php echo esc_attr( $path ); ?>" <?php selected( $selected, $term_id ); ?>><?php echo esc_html( $path ); ?></option>
				<?php endforeach; ?>
			</select>
			<div class="mobo-help" data-mobo-category-fallback-help><?php echo esc_html( $mapping_required ? 'در حالت نگاشت اجباری، این دسته جای نگاشت گمشده را نمی‌گیرد.' : 'اگر سیستم برای یک محصول جدید نتیجه معتبر دیگری پیدا نکند، این دسته می‌تواند به‌عنوان آخرین مسیر جایگزین استفاده شود.' ); ?></div>
		</div>
		<?php
	}

	/**
	 * Missing variants field.
	 *
	 * @return void
	 */
	private function missing_variants_field() {
		$value = (string) Mobo_Core_Settings::get( 'mobo_core_missing_variants_behavior', 'outofstock' );
		?>
		<div class="mobo-field">
			<label for="mobo_core_missing_variants_behavior">رفتار تنوع‌های حذف‌شده از API</label>

			<select id="mobo_core_missing_variants_behavior" name="mobo_core_missing_variants_behavior">
				<option value="outofstock" <?php selected( $value, 'outofstock' ); ?>>ناموجود شود</option>
				<option value="ignore" <?php selected( $value, 'ignore' ); ?>>تغییر نکند</option>
			</select>

			<div class="mobo-help">
				اگر تنوعی در آخرین صفحه sync دیده نشود، می‌تواند ناموجود شود.
			</div>
		</div>
		<?php
	}

	/**
	 * Get a manager-friendly warning when pricing has no profit configured.
	 *
	 * @return string
	 */
	private function get_pricing_health_warning() {
		$price_type = (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' );

		if ( 'static-price' === $price_type ) {
			$amount = (float) Mobo_Core_Settings::get( 'global_additional_price', 0 );
			return $amount > 0 ? '' : 'برای سیاست عمومی مبلغ ثابت، سودی ثبت نشده است. مواردی که سود اختصاصی واریانت یا محصول ندارند بدون سود عمومی محاسبه می‌شوند.';
		}

		if ( 'static-percentage' === $price_type ) {
			$percentage = (float) Mobo_Core_Settings::get( 'global_additional_percentage', 0 );
			return $percentage > 0 ? '' : 'برای سیاست عمومی درصدی، درصد سود ۰ است. مواردی که سود اختصاصی واریانت یا محصول ندارند بدون سود عمومی محاسبه می‌شوند.';
		}

		if ( 'dynamic-price' === $price_type ) {
			$rows = json_decode( (string) Mobo_Core_Settings::get( 'mobo_dynamic_price', '[]' ), true );

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				return 'قیمت‌گذاری داینامیک فعال است، اما هیچ بازه‌ای برای سود عمومی تعریف نشده است. سودهای اختصاصی واریانت/محصول همچنان مستقل اعمال می‌شوند.';
			}

			/* B12: coverage gaps (disabled row, capped last row). Runtime now falls
			 * back to the nearest active rule, but the merchant must fix the table. */
			if ( class_exists( 'Mobo_Core_Dynamic_Price_Rules' ) ) {
				$problems = Mobo_Core_Dynamic_Price_Rules::coverage_problems( $rows );
				if ( ! empty( $problems ) ) {
					return 'پوشش قوانین داینامیک ناقص است: ' . implode( ' ', $problems ) . ' تا اصلاح جدول، برای قیمت‌های بدون قانون سود نزدیک‌ترین قانون فعال اعمال می‌شود.';
				}
			}

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$is_active = isset( $row['is_active'] ) ? (string) $row['is_active'] : 'false';
				$benefit   = isset( $row['benefit'] ) ? (float) $row['benefit'] : 0;

				if ( 'true' === $is_active && $benefit > 0 ) {
					return '';
				}
			}

			return 'قیمت‌گذاری داینامیک فعال است، اما هیچ بازه فعال با سود عمومی بیشتر از ۰ وجود ندارد. سودهای اختصاصی واریانت/محصول همچنان مستقل اعمال می‌شوند.';
		}

		return '';
	}

	/**
	 * Pricing UI.
	 *
	 * @return void
	 */
	private function pricing_rules_ui() {
		$price_type = (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' );
		$currency_label = $this->store_currency_label();
		$rounding = class_exists( 'Mobo_Core_Price_Rounding' ) ? Mobo_Core_Price_Rounding::current_policy() : array( 'step' => 0, 'mode' => 'up' );

		$static_price      = absint( Mobo_Core_Settings::get( 'global_additional_price', 0 ) );
		$static_percentage = (float) Mobo_Core_Settings::get( 'global_additional_percentage', 0 );

		$dynamic_json = (string) Mobo_Core_Settings::get( 'mobo_dynamic_price', '[]' );
		$rows         = json_decode( $dynamic_json, true );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		if ( empty( $rows ) ) {
			$rows[] = array(
				'is_active'    => 'true',
				'low'          => '',
				'high'         => '',
				'benefit_type' => 'static',
				'benefit'      => '',
			);
		}

		?>
		<div class="mobo-pricing">

			<div class="mobo-price-types">
				<label class="<?php echo 'static-price' === $price_type ? 'active' : ''; ?>">
					<input type="radio" name="mobo_price_type" value="static-price" <?php checked( $price_type, 'static-price' ); ?>>
					<span>مبلغ ثابت</span>
				</label>

				<label class="<?php echo 'static-percentage' === $price_type ? 'active' : ''; ?>">
					<input type="radio" name="mobo_price_type" value="static-percentage" <?php checked( $price_type, 'static-percentage' ); ?>>
					<span>درصدی</span>
				</label>

				<label class="<?php echo 'dynamic-price' === $price_type ? 'active' : ''; ?>">
					<input type="radio" name="mobo_price_type" value="dynamic-price" <?php checked( $price_type, 'dynamic-price' ); ?>>
					<span>داینامیک</span>
				</label>
			</div>

			<div class="mobo-price-section" id="static-price" style="<?php echo 'static-price' === $price_type ? '' : 'display:none;'; ?>">
				<div class="mobo-fields-grid">
					<div class="mobo-field">
						<label for="global_additional_price">مبلغ سود ثابت (<?php echo esc_html( $currency_label ); ?>)</label>
						<input type="number" dir="ltr" style="text-align:left;" id="global_additional_price" class="mobo-money-input" name="global_additional_price" value="<?php echo esc_attr( $static_price ); ?>" min="0" step="1">
						<div class="mobo-price-preview" data-empty="مقدار وارد نشده">—</div>
						<div class="mobo-help">این مبلغ به ارز فروشگاه (<?php echo esc_html( $currency_label ); ?>) به قیمت محصول یا تنوع اضافه می‌شود.</div>
					</div>
				</div>
			</div>

			<div class="mobo-price-section" id="static-percentage" style="<?php echo 'static-percentage' === $price_type ? '' : 'display:none;'; ?>">
				<div class="mobo-fields-grid">
					<div class="mobo-field">
						<label for="global_additional_percentage">درصد سود</label>
						<input type="number" dir="ltr" style="text-align:left;" id="global_additional_percentage" name="global_additional_percentage" value="<?php echo esc_attr( $static_percentage ); ?>" min="0" step="0.01">
						<div class="mobo-help">مثلاً ۲۰ یعنی قیمت × ۱.۲ و ۱۰۰ یعنی قیمت × ۲.</div>
					</div>
				</div>
			</div>

			<div class="mobo-price-section" id="dynamic-price" style="<?php echo 'dynamic-price' === $price_type ? '' : 'display:none;'; ?>">
				<div class="mobo-dynamic-head">
					<strong>قوانین قیمت‌گذاری داینامیک</strong>
					<button type="button" class="mobo-btn mobo-btn-light" id="mobo-add-price-rule">افزودن قانون</button>
				</div>

				<div class="mobo-dynamic-table" id="mobo-dynamic-rows">
					<div class="mobo-dynamic-row mobo-dynamic-row-head">
						<span>فعال</span>
						<span>شروع بازه (خودکار)</span>
						<span>تا قیمت (<?php echo esc_html( $currency_label ); ?>)</span>
						<span>نوع سود</span>
						<span>مقدار سود</span>
						<span></span>
					</div>

					<?php foreach ( $rows as $row ) : ?>
						<?php
						$is_active    = isset( $row['is_active'] ) ? (string) $row['is_active'] : 'true';
						$low          = isset( $row['low'] ) ? $row['low'] : '';
						$high         = isset( $row['high'] ) ? $row['high'] : '';
						$benefit_type = isset( $row['benefit_type'] ) ? (string) $row['benefit_type'] : 'static';
						$benefit      = isset( $row['benefit'] ) ? $row['benefit'] : '';
						?>
						<div class="mobo-dynamic-row">
							<select name="mobo_dynamic_is_active[]">
								<option value="true" <?php selected( $is_active, 'true' ); ?>>بله</option>
								<option value="false" <?php selected( $is_active, 'false' ); ?>>خیر</option>
							</select>

							<div class="mobo-price-input-wrap"><input type="number" dir="ltr" style="text-align:left;" class="mobo-money-input" name="mobo_dynamic_low[]" value="<?php echo esc_attr( $low ); ?>" min="0" step="1" readonly aria-readonly="true"><div class="mobo-price-preview" data-empty="—">—</div></div>
							<div class="mobo-price-input-wrap"><input type="number" dir="ltr" style="text-align:left;" class="mobo-money-input" name="mobo_dynamic_high[]" value="<?php echo esc_attr( $high ); ?>" min="0" step="1"><div class="mobo-price-preview" data-empty="—">—</div></div>

							<select name="mobo_dynamic_benefit_type[]">
								<option value="static" <?php selected( $benefit_type, 'static' ); ?>>مبلغ ثابت</option>
								<option value="percentage" <?php selected( $benefit_type, 'percentage' ); ?>>درصدی</option>
							</select>

							<div class="mobo-price-input-wrap"><input type="number" dir="ltr" style="text-align:left;" class="mobo-money-input mobo-benefit-input" name="mobo_dynamic_benefit[]" value="<?php echo esc_attr( $benefit ); ?>" min="0" step="0.01"><div class="mobo-price-preview" data-empty="—">—</div></div>

							<button type="button" class="mobo-remove-row" aria-label="حذف">×</button>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="mobo-note">
					شروع هر بازه خودکار است: ردیف اول از ۰ شروع می‌شود و هر ردیف بعدی از عدد بعد از سقف ردیف قبل. فقط «تا قیمت» را تعیین کنید؛ سقف خالی یا ۰ در آخرین ردیف یعنی «بدون سقف». انتخاب بازه همیشه بر اساس price خام منبع است، نه comparePrice.
				</div>
				<div class="mobo-message mobo-message-info" id="mobo-dynamic-range-status" style="display:none;"></div>
				<div class="mobo-note">اگر قیمتی در هیچ بازه فعالی نیفتد، به‌جای فروش بدون سود، سود نزدیک‌ترین قانون فعال اعمال می‌شود. ردیف غیرفعال یا سقف برای آخرین ردیف هنگام ذخیره پذیرفته نمی‌شود.</div>
			</div>

			<?php if ( class_exists( 'Mobo_Core_Price_Rounding' ) ) : ?>
			<div class="mobo-fields-grid">
				<div class="mobo-field">
					<label for="mobo_core_price_rounding_step">گرد کردن قیمت نهایی (بعد از سود)</label>
					<select id="mobo_core_price_rounding_step" name="mobo_core_price_rounding_step">
						<option value="0" <?php selected( (int) $rounding['step'], 0 ); ?>>بدون گرد کردن (رفتار فعلی)</option>
						<?php foreach ( array( 100, 1000, 10000 ) as $step_option ) : ?>
							<option value="<?php echo esc_attr( $step_option ); ?>" <?php selected( (int) $rounding['step'], $step_option ); ?>><?php echo esc_html( 'به مضرب ' . number_format_i18n( $step_option ) . ' ' . $currency_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<div class="mobo-help">مضرب به ارز فروشگاه (<?php echo esc_html( $currency_label ); ?>) است و روی Sync، Reprice، سود اختصاصی محصول/واریانت و به‌روزرسانی‌های جزئی یکسان اعمال می‌شود.</div>
				</div>
				<div class="mobo-field">
					<label for="mobo_core_price_rounding_mode">جهت گرد کردن</label>
					<select id="mobo_core_price_rounding_mode" name="mobo_core_price_rounding_mode">
						<option value="up" <?php selected( (string) $rounding['mode'], 'up' ); ?>>رو به بالا (مثلاً ۱٬۴۱۹٬۷۵۲ ← ۱٬۴۲۰٬۰۰۰)</option>
						<option value="down" <?php selected( (string) $rounding['mode'], 'down' ); ?>>رو به پایین (۱٬۴۱۹٬۷۵۲ ← ۱٬۴۱۹٬۰۰۰)</option>
						<option value="nearest" <?php selected( (string) $rounding['mode'], 'nearest' ); ?>>نزدیک‌ترین (۱٬۴۱۹٬۴۹۹ ← ۱٬۴۱۹٬۰۰۰ و ۱٬۴۱۹٬۵۰۰ ← ۱٬۴۲۰٬۰۰۰)</option>
					</select>
					<div class="mobo-help">اگر قیمت تخفیفی و عادی پس از گرد کردن برابر شوند، تخفیف حذف و همان قیمت گردشده نمایش داده می‌شود. تغییر این گزینه نیاز به اجرای Reprice دارد.</div>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Reprice queue UI.
	 *
	 * @return void
	 */
	private function reprice_queue_ui() {
		$queue = class_exists( 'Mobo_Core_Reprice_Queue' ) ? new Mobo_Core_Reprice_Queue() : null;
		$status = $queue ? $queue->get_status() : array();
		$state = isset( $status['status'] ) ? (string) $status['status'] : 'idle';
		$percent = isset( $status['percent'] ) ? (float) $status['percent'] : 0;
		$is_running = 'running' === $state;
		$is_idle = 'idle' === $state;
		$is_cancelled = 'cancelled' === $state;
		$is_invalidated = 'invalidated' === $state;
		$total_available = ! isset( $status['totalAvailable'] ) || ! empty( $status['totalAvailable'] );
		$total_label = $total_available ? number_format_i18n( isset( $status['total'] ) ? absint( $status['total'] ) : 0 ) : '—';
		$failed_attempts = isset( $status['failed'] ) ? absint( $status['failed'] ) : 0;
		?>
		<div class="mobo-card" id="mobo-reprice-status-card" data-ajax-url="<?php echo esc_url( $this->admin_ajax_url() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mobo_core_reprice_status' ) ); ?>" data-auto-refresh="<?php echo $is_running ? '1' : '0'; ?>">
			<div class="mobo-card-head">
				<h2>اعمال قیمت فعلی روی محصولات موجود</h2>
				<p>این عملیات قیمت‌های موجود را با سیاست فعلی دوباره محاسبه می‌کند و نیازی به دریافت مجدد کاتالوگ از منبع ندارد.</p>
			</div>

			<?php if ( $is_idle ) : ?>
				<div class="mobo-message mobo-message-info">هنوز Reprice سراسری اجرا نشده است. فقط زمانی اجرا کنید که سیاست قیمت را تغییر داده‌اید یا می‌خواهید قیمت‌های موجود دوباره با سیاست فعلی محاسبه شوند.</div>
			<?php elseif ( $is_invalidated ) : ?>
				<div class="mobo-message mobo-message-warning"><strong>checkpoint قبلی قابل ادامه نیست.</strong> سیاست قیمت پس از آن اجرا تغییر کرده است؛ برای جلوگیری از قیمت‌های مخلوط، اجرای بعدی باید از ابتدا شروع شود.</div>
			<?php else : ?>
				<div class="mobo-progress-wrap">
					<div class="mobo-progress-meta">
						<span><?php echo $is_running ? 'پیشرفت اجرای جاری' : 'پیشرفت آخرین اجرا'; ?></span>
						<strong><span data-mobo-reprice-field="percent"><?php echo esc_html( $this->format_percent( $percent ) ); ?></span>٪</strong>
					</div>
					<div class="mobo-progress"><div data-mobo-reprice-progress-bar="1" style="width: <?php echo esc_attr( min( 100, max( 0, $percent ) ) ); ?>%;"></div></div>
				</div>
			<?php endif; ?>

			<div class="mobo-status-grid">
				<div class="mobo-status-box"><span>وضعیت</span><strong data-mobo-reprice-field="statusLabel"><?php echo esc_html( $this->reprice_status_label( $state ) ); ?></strong></div>
				<div class="mobo-status-box"><span>کاندیدای اولیه</span><strong data-mobo-reprice-field="total"><?php echo esc_html( $total_label ); ?></strong></div>
				<div class="mobo-status-box"><span>بررسی‌شده</span><strong data-mobo-reprice-field="processed"><?php echo esc_html( isset( $status['processed'] ) ? absint( $status['processed'] ) : 0 ); ?></strong></div>
				<div class="mobo-status-box"><span>قیمت تغییرکرده</span><strong data-mobo-reprice-field="updated"><?php echo esc_html( isset( $status['updated'] ) ? absint( $status['updated'] ) : 0 ); ?></strong></div>
			</div>

			<?php
			$health       = isset( $status['health'] ) ? (string) $status['health'] : 'idle';
			$is_stalled   = 'stalled' === $health || 'stalled' === $state;
			$status_line  = isset( $status['statusLine'] ) ? (string) $status['statusLine'] : '';
			$problem_item = isset( $status['problemItemId'] ) ? absint( $status['problemItemId'] ) : 0;
			?>
			<div class="mobo-message <?php echo $is_stalled ? 'mobo-message-error' : 'mobo-message-info'; ?>" data-mobo-reprice-field="statusLine" style="<?php echo '' === $status_line ? 'display:none;' : ''; ?>"><?php echo esc_html( $status_line ); ?></div>

			<?php if ( $is_stalled || ( ( $is_running || 'stalled' === $state ) && $problem_item > 0 ) ) : ?>
				<div class="mobo-actions">
					<?php if ( $is_stalled ) : ?>
						<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1">
							<input type="hidden" name="action" value="mobo_core_reprice_recover">
							<?php wp_nonce_field( 'mobo_core_reprice_recover', 'mobo_core_nonce' ); ?>
							<button type="submit" class="mobo-btn mobo-btn-primary">ادامه</button>
						</form>
					<?php endif; ?>
					<?php if ( $problem_item > 0 ) : ?>
						<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1">
							<input type="hidden" name="action" value="mobo_core_reprice_skip_item">
							<?php wp_nonce_field( 'mobo_core_reprice_skip_item', 'mobo_core_nonce' ); ?>
							<button type="submit" class="mobo-btn mobo-btn-light"><?php echo esc_html( sprintf( 'رد کردن مورد مشکل‌دار (شناسه %d)', $problem_item ) ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $total_available ) : ?>
				<div class="mobo-message mobo-message-warning">تعداد کاندیداهای فعلی قابل خواندن نیست. اجرای جدید تا زمانی که این شمارش معتبر نباشد شروع نمی‌شود.</div>
			<?php endif; ?>

			<div class="mobo-message mobo-message-info" data-mobo-reprice-message="lastMessage" style="<?php echo empty( $status['lastMessage'] ) ? 'display:none;' : ''; ?>">
				<?php echo esc_html( isset( $status['lastMessage'] ) ? $status['lastMessage'] : '' ); ?>
			</div>

			<div class="mobo-message mobo-message-warning" data-mobo-reprice-failure-summary style="<?php echo $failed_attempts > 0 ? '' : 'display:none;'; ?>">
				<?php echo esc_html( $failed_attempts > 0 ? sprintf( '%d تلاش ناموفق در این اجرا ثبت شده است. این شمارنده تاریخچه تلاش‌هاست و لزوماً خطای حل‌نشده نیست؛ جزئیات فنی را در بخش پایین ببینید.', $failed_attempts ) : '' ); ?>
			</div>

			<?php if ( $is_running ) : ?>
				<div class="mobo-auto-refresh">
					<span data-mobo-reprice-refresh-state>به‌روزرسانی خودکار وضعیت قیمت‌گذاری فعال است.</span>
					<span data-mobo-reprice-updated-at></span>
				</div>
			<?php endif; ?>

			<div class="mobo-actions">
				<?php if ( $is_running ) : ?>
					<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1">
						<input type="hidden" name="action" value="mobo_core_cancel_reprice">
						<?php wp_nonce_field( 'mobo_core_cancel_reprice', 'mobo_core_nonce' ); ?>
						<button type="submit" class="mobo-btn mobo-btn-danger">توقف اعمال قیمت</button>
					</form>
				<?php else : ?>
					<?php if ( $is_cancelled ) : ?>
						<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1">
							<input type="hidden" name="action" value="mobo_core_resume_reprice">
							<?php wp_nonce_field( 'mobo_core_resume_reprice', 'mobo_core_nonce' ); ?>
							<button type="submit" class="mobo-btn mobo-btn-primary">ادامه از checkpoint</button>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1">
						<input type="hidden" name="action" value="mobo_core_start_reprice">
						<?php wp_nonce_field( 'mobo_core_start_reprice', 'mobo_core_nonce' ); ?>
						<button type="submit" class="mobo-btn <?php echo $is_cancelled ? 'mobo-btn-light' : 'mobo-btn-primary'; ?>">اعمال قیمت روی همه کاندیداها از ابتدا</button>
					</form>
				<?php endif; ?>

				<?php if ( ! $is_idle && ! $is_running ) : ?>
					<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1">
						<input type="hidden" name="action" value="mobo_core_reset_reprice">
						<?php wp_nonce_field( 'mobo_core_reset_reprice', 'mobo_core_nonce' ); ?>
						<button type="submit" class="mobo-btn mobo-btn-light">پاک کردن گزارش قبلی</button>
					</form>
				<?php endif; ?>
			</div>

			<?php if ( ! $is_idle ) : ?>
			<details class="mobo-admin-inline-details">
				<summary>جزئیات فنی اجرا</summary>
				<div class="mobo-status-grid">
					<div class="mobo-status-box"><span>تلاش‌های ناموفق</span><strong data-mobo-reprice-field="failed"><?php echo esc_html( $failed_attempts ); ?></strong></div>
					<div class="mobo-status-box"><span>آخرین شناسه بررسی‌شده</span><strong data-mobo-reprice-field="lastPostId"><?php echo esc_html( isset( $status['lastPostId'] ) ? absint( $status['lastPostId'] ) : 0 ); ?></strong></div>
					<div class="mobo-status-box"><span>نوع سیاست شروع اجرا</span><strong><?php echo esc_html( isset( $status['policyType'] ) && '' !== (string) $status['policyType'] ? (string) $status['policyType'] : '—' ); ?></strong></div>
				</div>
				<div class="mobo-message mobo-message-error" data-mobo-reprice-message="lastError" style="<?php echo empty( $status['lastError'] ) ? 'display:none;' : ''; ?>"><?php echo esc_html( isset( $status['lastError'] ) ? $status['lastError'] : '' ); ?></div>
				<?php if ( ! empty( $status['totalError'] ) ) : ?><div class="mobo-message mobo-message-error"><?php echo esc_html( (string) $status['totalError'] ); ?></div><?php endif; ?>
				<div class="mobo-note">منبع محاسبه Reprice، قیمت خام ذخیره‌شده Mobo برای هر محصول/واریانت است. شناسه‌ها، خطای خام و نوع policy فقط برای عیب‌یابی نمایش داده می‌شوند.</div>
			</details>
			<?php endif; ?>

			<?php
			$debug_bundle = $queue ? wp_json_encode( $queue->debug_bundle(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) : '';
			?>
			<?php if ( is_string( $debug_bundle ) && '' !== $debug_bundle ) : ?>
				<div class="mobo-actions">
					<button type="button" class="mobo-btn mobo-btn-light" data-mobo-copy-target="mobo-reprice-debug-bundle">کپی گزارش عیب‌یابی</button>
					<span class="mobo-help" data-mobo-copy-status="mobo-reprice-debug-bundle"></span>
				</div>
				<textarea id="mobo-reprice-debug-bundle" readonly hidden aria-hidden="true"><?php echo esc_textarea( $debug_bundle ); ?></textarea>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Reprice status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function reprice_status_label( $status ) {
		switch ( (string) $status ) {
			case 'running':
				return 'در حال اعمال قیمت';
			case 'done':
				return 'کامل شده';
			case 'cancelled':
				return 'متوقف شده';
			case 'invalidated':
				return 'نیازمند اجرای جدید';
			case 'stalled':
				return 'گیر کرده';
			default:
				return 'آماده';
		}
	}



	/**
	 * Recategorize queue UI.
	 *
	 * @return void
	 */
	private function recategorize_queue_ui() {
		$queue      = class_exists( 'Mobo_Core_Recategorize_Queue' ) ? new Mobo_Core_Recategorize_Queue() : null;
		$status     = $queue ? $queue->get_status() : array();
		$state      = isset( $status['status'] ) ? (string) $status['status'] : 'idle';
		$percent    = isset( $status['percent'] ) ? (float) $status['percent'] : 0;
		$is_running = 'running' === $state;
		$is_idle    = 'idle' === $state;
		?>
		<div class="mobo-card" id="mobo-recategorize-status-card" data-ajax-url="<?php echo esc_url( $this->admin_ajax_url() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mobo_core_recategorize_status' ) ); ?>">
			<div class="mobo-card-head">
				<h2>اعمال نگاشت فعلی روی محصولات موجود</h2>
				<p>اگر نگاشت یا سیاست دسته‌بندی را تغییر داده‌اید، این عملیات دسته محصولات منتشرشده و همگام‌شده را بر اساس تصمیم فعلی دوباره بررسی می‌کند؛ خود محصول دوباره از منبع دریافت نمی‌شود.</p>
			</div>
			<?php if ( $is_idle ) : ?>
				<div class="mobo-message mobo-message-info">هنوز اجرای اعمال مجدد دسته‌بندی شروع نشده است. فقط وقتی نگاشت‌ها را تغییر داده‌اید و می‌خواهید تغییر فوراً روی محصولات موجود اعمال شود، این عملیات را اجرا کنید.</div>
			<?php else : ?>
				<div class="mobo-progress-wrap">
					<div class="mobo-progress-meta"><span>پیشرفت</span><strong><span data-mobo-recategorize-field="percent"><?php echo esc_html( $this->format_percent( $percent ) ); ?></span>٪</strong></div>
					<div class="mobo-progress"><div data-mobo-recategorize-progress-bar="1" style="width: <?php echo esc_attr( min( 100, max( 0, $percent ) ) ); ?>%;"></div></div>
				</div>
				<div class="mobo-status-grid">
					<div class="mobo-status-box"><span>وضعیت</span><strong data-mobo-recategorize-field="statusLabel"><?php echo esc_html( $this->recategorize_status_label( $state ) ); ?></strong></div>
					<div class="mobo-status-box"><span>کل محصولات</span><strong data-mobo-recategorize-field="total"><?php echo esc_html( absint( $status['total'] ?? 0 ) ); ?></strong></div>
					<div class="mobo-status-box"><span>پردازش‌شده</span><strong data-mobo-recategorize-field="processed"><?php echo esc_html( absint( $status['processed'] ?? 0 ) ); ?></strong></div>
					<div class="mobo-status-box"><span>تغییرکرده</span><strong data-mobo-recategorize-field="updated"><?php echo esc_html( absint( $status['updated'] ?? 0 ) ); ?></strong></div>
					<div class="mobo-status-box"><span>ناموفق</span><strong data-mobo-recategorize-field="failed"><?php echo esc_html( absint( $status['failed'] ?? 0 ) ); ?></strong></div>
				</div>
			<?php endif; ?>

			<div class="mobo-message mobo-message-info" data-mobo-recategorize-message="lastMessage" style="<?php echo empty( $status['lastMessage'] ) ? 'display:none;' : ''; ?>"><?php echo esc_html( (string) ( $status['lastMessage'] ?? '' ) ); ?></div>
			<div class="mobo-message mobo-message-error" data-mobo-recategorize-message="lastError" style="<?php echo empty( $status['lastError'] ) ? 'display:none;' : ''; ?>"><?php echo esc_html( (string) ( $status['lastError'] ?? '' ) ); ?></div>
			<?php if ( ! $is_idle ) : ?><div class="mobo-auto-refresh"><span data-mobo-recategorize-refresh-state>به‌روزرسانی خودکار وضعیت فعال است.</span><span data-mobo-recategorize-updated-at></span></div><?php endif; ?>

			<div class="mobo-actions">
				<?php if ( ! $is_running ) : ?>
					<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1"><input type="hidden" name="action" value="mobo_core_start_recategorize"><?php wp_nonce_field( 'mobo_core_start_recategorize', 'mobo_core_nonce' ); ?><button type="submit" class="mobo-btn mobo-btn-primary">اعمال روی محصولات موجود</button></form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1"><input type="hidden" name="action" value="mobo_core_cancel_recategorize"><?php wp_nonce_field( 'mobo_core_cancel_recategorize', 'mobo_core_nonce' ); ?><button type="submit" class="mobo-btn mobo-btn-danger">توقف عملیات</button></form>
				<?php endif; ?>
				<?php if ( ! $is_idle && ! $is_running ) : ?><form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" data-mobo-busy-submit="1"><input type="hidden" name="action" value="mobo_core_reset_recategorize"><?php wp_nonce_field( 'mobo_core_reset_recategorize', 'mobo_core_nonce' ); ?><button type="submit" class="mobo-btn mobo-btn-light">پاک کردن گزارش قبلی</button></form><?php endif; ?>
			</div>

			<?php if ( ! $is_idle && ( absint( $status['skipped'] ?? 0 ) > 0 || absint( $status['lastPostId'] ?? 0 ) > 0 ) ) : ?>
			<details class="mobo-admin-inline-details"><summary>جزئیات فنی اجرا</summary><div class="mobo-status-grid"><div class="mobo-status-box"><span>بدون تغییر / ردشده</span><strong data-mobo-recategorize-field="skipped"><?php echo esc_html( absint( $status['skipped'] ?? 0 ) ); ?></strong></div><div class="mobo-status-box"><span>آخرین شناسه محصول</span><strong data-mobo-recategorize-field="lastPostId"><?php echo esc_html( absint( $status['lastPostId'] ?? 0 ) ); ?></strong></div></div></details>
			<?php endif; ?>
			<?php $this->render_job_diagnostics_block( 'recategorize', $status, 'recategorize', $queue ); ?>
			<div class="mobo-note">این عملیات فقط محصولات اصلی منتشرشده‌ای را بررسی می‌کند که قبلاً با MoboCore همگام شده‌اند. واریانت‌ها دسته مستقل ندارند.</div>
		</div>
		<?php
	}

	/**
	 * Persian watchdog status line + «کپی گزارش عیب‌یابی» for Sync/Repair and
	 * Recategorize (same contract as the Reprice card).
	 *
	 * @param string     $job    sync|repair|recategorize.
	 * @param array      $status Status payload of the job.
	 * @param string     $ui     UI key (sync|recategorize).
	 * @param object|null $queue Queue instance providing debug_bundle() (recategorize).
	 * @return void
	 */
	private function render_job_diagnostics_block( $job, $status, $ui, $queue = null ) {
		if ( ! class_exists( 'Mobo_Core_Job_Diagnostics' ) ) {
			return;
		}
		$status = is_array( $status ) ? $status : array();
		try {
			if ( 'recategorize' === $job ) {
				$extras = $status;
				$bundle = $queue && method_exists( $queue, 'debug_bundle' ) ? $queue->debug_bundle() : array();
			} else {
				$extras = Mobo_Core_Job_Diagnostics::manual_sync_extras( $status );
				$bundle = Mobo_Core_Job_Diagnostics::job_bundle( $job, $status, Mobo_Core_Job_Diagnostics::MANUAL_SYNC_LOCK );
			}
		} catch ( Throwable $e ) {
			return;
		}
		$line    = isset( $extras['statusLine'] ) ? (string) $extras['statusLine'] : '';
		$stalled = isset( $extras['health'] ) && 'stalled' === $extras['health'];
		$json    = wp_json_encode( $bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		$target  = 'mobo-' . sanitize_key( $ui ) . '-debug-bundle';
		?>
		<div class="mobo-message <?php echo $stalled ? 'mobo-message-error' : 'mobo-message-info'; ?>" data-mobo-job-status-line="<?php echo esc_attr( $ui ); ?>" style="<?php echo '' === $line ? 'display:none;' : ''; ?>"><?php echo esc_html( $line ); ?></div>
		<?php if ( is_string( $json ) && '' !== $json ) : ?>
			<div class="mobo-actions">
				<button type="button" class="mobo-btn mobo-btn-light" data-mobo-copy-target="<?php echo esc_attr( $target ); ?>">کپی گزارش عیب‌یابی</button>
				<span class="mobo-help" data-mobo-copy-status="<?php echo esc_attr( $target ); ?>"></span>
			</div>
			<textarea id="<?php echo esc_attr( $target ); ?>" readonly hidden aria-hidden="true"><?php echo esc_textarea( $json ); ?></textarea>
		<?php endif; ?>
		<?php
	}

	/**
	 * Recategorize status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function recategorize_status_label( $status ) {
		switch ( (string) $status ) {
			case 'running':
				return 'در حال اعمال دسته‌بندی';
			case 'done':
				return 'کامل شده';
			case 'cancelled':
				return 'متوقف شده';
			default:
				return 'آماده';
		}
	}



	/**
	 * Render shared Mobo connection box.
	 *
	 * @return void
	 */
	private function render_mobo_shared_connection_box() {
		$site_url     = class_exists( 'Mobo_Core_Connection_Repository' ) ? Mobo_Core_Connection_Repository::get_runtime_source_site_url( '' ) : '';
		$username     = (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_username', '' );
		$has_password = '' !== (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_password', '' );
		$timeout      = absint( Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_timeout_seconds', 8 ) );
		if ( $timeout < 2 ) {
			$timeout = 8;
		}
		?>
		<div class="mobo-card" data-mobo-ui-card="mobo-connection">
			<div class="mobo-card-head">
				<h2>اطلاعات اتصال به موبو</h2>
				<p>این اطلاعات بین بررسی موجودی لحظه‌ای، ثبت سفارش خودکار و تست اتصال مشترک است. فقط وقتی یکی از این قابلیت‌ها روشن باشد لازم می‌شود.</p>
			</div>
			<div class="mobo-fields-grid">
				<div class="mobo-field mobo-field-full">
					<label for="mobo_core_checkout_mobo_site_url">آدرس سایت موبو</label>
					<input type="url" id="mobo_core_checkout_mobo_site_url" value="<?php echo esc_attr( $site_url ); ?>" dir="ltr" readonly data-mobo-connection-required="1">
					<div class="mobo-help">این آدرس توسط Backend provision شده و از پنل وردپرس قابل تغییر نیست.</div>
				</div>
				<div class="mobo-field">
					<label for="mobo_core_checkout_mobo_username">نام کاربری موبو</label>
					<input type="text" id="mobo_core_checkout_mobo_username" name="mobo_core_checkout_mobo_username" value="<?php echo esc_attr( $username ); ?>" dir="ltr" data-mobo-connection-required="1">
				</div>
				<div class="mobo-field">
					<label for="mobo_core_checkout_mobo_password">رمز عبور موبو</label>
					<input type="password" autocomplete="new-password" spellcheck="false" id="mobo_core_checkout_mobo_password" name="mobo_core_checkout_mobo_password" value="" placeholder="<?php echo $has_password ? esc_attr( 'رمز قبلی ذخیره شده؛ برای تغییر مقدار جدید وارد کنید' ) : esc_attr( 'رمز عبور موبو را وارد کنید' ); ?>" dir="ltr" data-mobo-connection-required="1" data-has-secret="<?php echo $has_password ? '1' : '0'; ?>">
					<div class="mobo-help"><?php echo $has_password ? 'اگر این قسمت را خالی بگذارید، رمز قبلی حفظ می‌شود.' : 'برای فعال شدن قابلیت‌های وابسته به موبو، رمز عبور لازم است.'; ?></div>
				</div>
				<div class="mobo-field">
					<label for="mobo_core_checkout_mobo_timeout_seconds">زمان انتظار پاسخ موبو / ثانیه</label>
					<input type="number" dir="ltr" style="text-align:left;" id="mobo_core_checkout_mobo_timeout_seconds" name="mobo_core_checkout_mobo_timeout_seconds" value="<?php echo esc_attr( $timeout ); ?>" min="2" max="20" step="1" data-mobo-connection-required="1">
				</div>
			</div>
			<div class="mobo-note">اگر هم بررسی موجودی لحظه‌ای و هم ثبت سفارش خودکار خاموش باشند، این بخش مخفی می‌شود و ذخیره تب checkout این اطلاعات را تغییر نمی‌دهد.</div>
		</div>
		<?php
	}

	/**
	 * Text field.
	 *
	 * @param string $label Label.
	 * @param string $key Option key.
	 * @param string $help Help text.
	 * @return void
	 */
	private function text_field( $label, $key, $help = '' ) {
		$value = (string) Mobo_Core_Settings::get( $key, '' );
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input
				type="text"
				id="<?php echo esc_attr( $key ); ?>"
				name="<?php echo esc_attr( $key ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				dir="ltr"
			>
			<?php if ( '' !== $help ) : ?>
				<div class="mobo-help"><?php echo esc_html( $help ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Readonly text field.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	private function readonly_field( $label, $value ) {
		?>
		<div class="mobo-field mobo-field-full">
			<label><?php echo esc_html( $label ); ?></label>
			<input type="text" readonly dir="ltr" value="<?php echo esc_attr( (string) $value ); ?>" onclick="this.select();">
		</div>
		<?php
	}

	/**
	 * URL field.
	 *
	 * @param string $label Label.
	 * @param string $key Option key.
	 * @param string $help Help text.
	 * @return void
	 */
	private function url_field( $label, $key, $help = '' ) {
		$value = (string) Mobo_Core_Settings::get( $key, '' );
		?>
		<div class="mobo-field mobo-field-full">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input
				type="url"
				id="<?php echo esc_attr( $key ); ?>"
				name="<?php echo esc_attr( $key ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				dir="ltr"
			>
			<?php if ( '' !== $help ) : ?>
				<div class="mobo-help"><?php echo esc_html( $help ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Secret field.
	 *
	 * Secret values are not rendered back into HTML.
	 * Empty input keeps the previous saved value.
	 *
	 * @param string $label Label.
	 * @param string $key Option key.
	 * @param string $help Help text.
	 * @return void
	 */
	private function secret_field( $label, $key, $help = '' ) {
		$is_security_code = 'mobo_core_security_code' === $key;
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<div class="mobo-admin-secret-row">
				<input
					type="password"
					id="<?php echo esc_attr( $key ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value=""
					placeholder="برای تغییر، مقدار جدید را وارد کنید"
					dir="ltr"
					autocomplete="new-password"
					spellcheck="false"
					autocapitalize="none"
					<?php if ( $is_security_code ) : ?>
						pattern="[!-~]{1,255}"
						maxlength="255"
						title="فقط ASCII قابل‌چاپ و بدون فاصله؛ حروف، عدد و نمادها مجاز هستند"
					<?php endif; ?>
				>
				<button type="button" class="mobo-admin-secret-toggle" data-mobo-secret-toggle aria-label="نمایش مقدار"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></button>
			</div>

			<?php if ( '' !== $help ) : ?>
				<div class="mobo-help"><?php echo esc_html( $help ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Compare runtime, plugin header, DB schema version and packaged file hashes.
	 *
	 * @return array<string,mixed>
	 */
	private function get_build_integrity_status() {
		$runtime_version  = defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '';
		$file_version     = '';
		$database_version = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		$manifest_path    = MOBO_CORE_PLUGIN_DIR . 'mobo-core-manifest.json';
		$changed_files    = array();
		$files_match      = false;

		if ( ! function_exists( 'get_file_data' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( function_exists( 'get_file_data' ) && is_file( MOBO_CORE_PLUGIN_FILE ) ) {
			$file_data    = get_file_data( MOBO_CORE_PLUGIN_FILE, array( 'Version' => 'Version' ), 'plugin' );
			$file_version = isset( $file_data['Version'] ) ? trim( (string) $file_data['Version'] ) : '';
		}

		if ( is_file( $manifest_path ) && is_readable( $manifest_path ) ) {
			$manifest = json_decode( (string) file_get_contents( $manifest_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_array( $manifest ) && isset( $manifest['files'] ) && is_array( $manifest['files'] ) ) {
				$files_match = true;

				foreach ( $manifest['files'] as $key => $entry ) {
					$relative      = '';
					$expected_hash = '';

					// Release manifests use a path => hash map for Remote Updater validation.
					// Accept the older {path, sha256} row shape for local integrity diagnostics.
					if ( is_array( $entry ) ) {
						$relative      = isset( $entry['path'] ) ? (string) $entry['path'] : '';
						$expected_hash = isset( $entry['sha256'] ) ? (string) $entry['sha256'] : '';
					} elseif ( is_string( $key ) && is_scalar( $entry ) ) {
						$relative      = (string) $key;
						$expected_hash = (string) $entry;
					}

					$relative = ltrim( str_replace( '\\\\', '/', $relative ), '/' );
					if ( '' === $relative || ! preg_match( '/^[a-f0-9]{64}$/i', $expected_hash ) ) {
						$files_match     = false;
						$changed_files[] = '' !== $relative ? $relative : 'manifest-entry';
						continue;
					}

					$file   = MOBO_CORE_PLUGIN_DIR . $relative;
					$actual = is_file( $file ) && is_readable( $file ) ? hash_file( 'sha256', $file ) : '';
					if ( '' === $actual || ! hash_equals( strtolower( $expected_hash ), strtolower( (string) $actual ) ) ) {
						$files_match     = false;
						$changed_files[] = $relative;
					}
				}
				if ( isset( $manifest['version'] ) && (string) $manifest['version'] !== $runtime_version ) {
					$files_match     = false;
					$changed_files[] = 'manifest-version';
				}
			}
		} else {
			$changed_files[] = 'mobo-core-manifest.json';
		}

		$versions_match = '' !== $runtime_version
			&& $runtime_version === $file_version
			&& $runtime_version === $database_version;
		$all_match = $versions_match && $files_match;

		$message_parts = array();
		if ( ! $versions_match ) {
			$message_parts[] = 'شماره نسخه اجرا، فایل اصلی یا دیتابیس با هم یکسان نیست.';
		}
		if ( ! $files_match ) {
			$message_parts[] = 'یک یا چند فایل با checksum بسته این نسخه تطابق ندارد یا manifest در دسترس نیست.';
		}

		return array(
			'runtimeVersion'  => '' !== $runtime_version ? $runtime_version : '—',
			'fileVersion'     => '' !== $file_version ? $file_version : '—',
			'databaseVersion' => '' !== $database_version ? $database_version : '—',
			'versionsMatch'   => $versions_match,
			'filesMatch'      => $files_match,
			'allMatch'        => $all_match,
			'changedFiles'    => array_values( array_unique( $changed_files ) ),
			'message'         => implode( ' ', $message_parts ),
		);
	}

	/**
	 * Webhook codes must be visible ASCII suitable for an HTTP header value.
	 *
	 * @param string $code Security code.
	 * @return bool
	 */
	private function is_webhook_security_code_valid( $code ) {
		return Mobo_Core_Settings::is_valid_security_code( $code );
	}

	/**
	 * Determine whether WordPress built-in traffic-driven cron is disabled.
	 *
	 * @return bool
	 */
	private function is_wp_cron_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && true === DISABLE_WP_CRON;
	}

	/**
	 * Inspect image editor requirements for WebP regeneration.
	 *
	 * @return array<string,mixed>
	 */
	private function get_image_environment_status() {
		$wordpress_version = get_bloginfo( 'version' );
		$wordpress_ready   = version_compare( $wordpress_version, '5.8', '>=' );
		$php_ready         = version_compare( PHP_VERSION, '7.4', '>=' );
		$gd_loaded         = extension_loaded( 'gd' );
		$gd_webp           = $gd_loaded && function_exists( 'imagewebp' );
		$imagick_loaded    = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$imagick_webp      = false;
		$editor_support    = false;
		$uploads_writable  = false;

		if ( $imagick_loaded ) {
			try {
				$formats      = Imagick::queryFormats( 'WEBP' );
				$imagick_webp = is_array( $formats ) && ! empty( $formats );
			} catch ( Throwable $e ) {
				$imagick_webp = false;
			}
		}

		if ( ! function_exists( 'wp_image_editor_supports' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( function_exists( 'wp_image_editor_supports' ) ) {
			$editor_support = (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		}

		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$uploads_writable = wp_is_writable( $uploads['basedir'] );
		}

		$image_engine_ready = $gd_webp || $imagick_webp;
		$local_ready        = $wordpress_ready && $php_ready && $image_engine_ready && $editor_support && $uploads_writable;
		$shared_configured  = class_exists( 'Mobo_Core_Shared_Media' )
			&& ( method_exists( 'Mobo_Core_Shared_Media', 'is_configured' ) ? Mobo_Core_Shared_Media::is_configured() : Mobo_Core_Shared_Media::is_enabled() );
		$shared_ready       = $shared_configured && Mobo_Core_Shared_Media::is_enabled();
		$shared_fallback    = $shared_configured && Mobo_Core_Shared_Media::allow_download_fallback();

		/* On strict Shared Media sites the central worker owns image generation; local
		 * GD/Imagick/uploads writability are not prerequisites while the repository is
		 * readable. If explicit fallback is enabled, local readiness becomes the backup. */
		$ready = $shared_configured
			? ( $wordpress_ready && $php_ready && ( $shared_ready || ( $shared_fallback && $local_ready ) ) )
			: $local_ready;

		return array(
			'wordpressVersion'     => $wordpress_version,
			'wordpressReady'       => $wordpress_ready,
			'phpVersion'           => PHP_VERSION,
			'phpReady'             => $php_ready,
			'gdLoaded'             => $gd_loaded,
			'gdWebp'               => $gd_webp,
			'imagickLoaded'        => $imagick_loaded,
			'imagickWebp'          => $imagick_webp,
			'imageEngineReady'     => $image_engine_ready,
			'editorSupported'      => $editor_support,
			'uploadsWritable'      => $uploads_writable,
			'localReady'           => $local_ready,
			'sharedConfigured'     => $shared_configured,
			'sharedRepositoryReady'=> $shared_ready,
			'sharedFallback'       => $shared_fallback,
			'ready'                => $ready,
		);
	}

	/**
	 * Build one clear, centralized view of image-refresh execution.
	 *
	 * @return array
	 */
	private function get_image_refresh_live_overview( $automation, $workflow, $queue_status, $scan, $enqueue, $subsize_scan, $subsize_repair, $replaced_scan, $replaced_delete, $orphan_status, $orphan_scan, $orphan_delete, $locked ) {
		$automation    = is_array( $automation ) ? $automation : array();
		$workflow      = is_array( $workflow ) ? $workflow : array();
		$flags         = isset( $workflow['flags'] ) && is_array( $workflow['flags'] ) ? $workflow['flags'] : array();
		$queue_status  = is_array( $queue_status ) ? $queue_status : array();
		$orphan_status = is_array( $orphan_status ) ? $orphan_status : array();
		$last_result   = isset( $automation['lastResult'] ) && is_array( $automation['lastResult'] ) ? $automation['lastResult'] : array();
		$operation     = isset( $last_result['operation'] ) && is_array( $last_result['operation'] ) ? $last_result['operation'] : array();
		$enabled       = ! empty( $automation['enabled'] );
		$waiting       = isset( $automation['waitingApproval'] ) ? sanitize_key( (string) $automation['waitingApproval'] ) : '';
		$status_key    = isset( $automation['status'] ) ? sanitize_key( (string) $automation['status'] ) : 'idle';
		$batch_running = ! empty( $automation['batchRunning'] );
		$batch_stuck   = ! empty( $automation['batchPossiblyStuck'] );
		$last_run_at   = absint( isset( $automation['lastRunAt'] ) ? $automation['lastRunAt'] : 0 );
		$last_run_age  = $last_run_at > 0 ? max( 0, time() - $last_run_at ) : 0;

		$cron_status = class_exists( 'Mobo_Core_Cron_Runner' ) ? Mobo_Core_Cron_Runner::get_status() : array();
		$self_status = class_exists( 'Mobo_Core_Self_Runner' ) ? Mobo_Core_Self_Runner::get_status() : array();
		$cron_last   = absint( isset( $cron_status['lastHitAt'] ) ? $cron_status['lastHitAt'] : 0 );
		$self_last   = absint( isset( $self_status['lastRunAt'] ) ? $self_status['lastRunAt'] : 0 );
		$runner_last = max( $cron_last, $self_last );
		$expected    = max( 60, absint( isset( $cron_status['expectedIntervalSeconds'] ) ? $cron_status['expectedIntervalSeconds'] : 60 ) );
		$stale_after  = max( 180, $expected * 3 );
		$passive_wait = in_array( $status_key, array( 'waiting-retry', 'waiting-active-processor', 'locked' ), true );
		$stalled      = $enabled && '' === $waiting && ! $batch_running && ! $passive_wait && $last_run_at > 0 && $last_run_age > $stale_after;

		$step_titles = array(
			1 => 'بررسی تصاویر قدیمی',
			2 => 'ساخت صف نوسازی',
			3 => 'جایگزینی تصاویر و پردازش صف',
			4 => 'کنترل سلامت برش های WebP',
			5 => 'بازسازی برش های ناقص WebP',
			6 => 'شناسایی پیوست های قدیمی جایگزین شده',
			7 => 'حذف امن پیوست های قدیمی',
			8 => 'اسکن خانواده های فایل بدون پیوست',
			9 => 'پاکسازی کنترل شده خانواده های فایل',
		);

		$step = $this->detect_image_refresh_display_step( $flags, $queue_status, $subsize_scan, $subsize_repair, $replaced_scan, $replaced_delete, $orphan_status, $orphan_scan, $orphan_delete, $automation );
		$step_percent = $this->get_image_refresh_step_percent( $step, $queue_status, $scan, $enqueue, $subsize_scan, $subsize_repair, $replaced_scan, $replaced_delete, $orphan_status, $orphan_scan, $orphan_delete );
		$overall_percent = 'completed' === $status_key ? 100 : ( $step > 0 ? min( 99.9, round( ( ( $step - 1 ) + ( $step_percent / 100 ) ) / 9 * 100, 1 ) ) : 0 );

		$error = array_key_exists( 'success', $last_result ) && false === (bool) $last_result['success'];
		$tone = 'idle';
		$state_label = 'شروع نشده';
		$headline = 'عملیات هنوز شروع نشده است';
		$description = 'برای شروع، دکمه «شروع یا ادامه اجرای خودکار امن» را بزنید.';

		if ( $locked ) {
			$tone = 'warning';
			$state_label = 'قفل شده';
			$headline = 'نوسازی تصاویر هنوز اجازه اجرا ندارد';
			$description = 'ابتدا ترمیم کامل محصولات را از داشبورد موبو تمام کنید.';
		} elseif ( 'completed' === $status_key ) {
			$tone = 'success';
			$state_label = 'کامل شده';
			$headline = 'چرخه نوسازی تصاویر کامل و تایید شده است';
			$description = ! empty( $automation['message'] ) ? (string) $automation['message'] : 'همه مراحل تمام شده و کلیدهای حذفی خاموش هستند.';
		} elseif ( '' !== $waiting ) {
			$tone = 'warning';
			$state_label = 'منتظر تایید مدیر';
			$headline = 'اجرای خودکار در یک نقطه امن متوقف شده است';
			$description = 'delete-old' === $waiting
				? 'فهرست پیوست های قدیمی را بررسی کنید. تا تایید شما هیچ پیوستی حذف نمی شود.'
				: 'فهرست خانواده های بدون پیوست را بررسی کنید. تا تایید شما هیچ فایلی حذف نمی شود.';
		} elseif ( $batch_stuck ) {
			$tone = 'error';
			$state_label = 'اجرای گیرکرده';
			$headline = 'یک batch شروع شده ولی پایان آن ثبت نشده است';
			$description = 'قفل اجرا، خطای PHP یا timeout هاست را بررسی کنید. تا روشن شدن وضعیت، عملیات حذفی انجام ندهید.';
		} elseif ( $error ) {
			$tone = 'error';
			$state_label = 'متوقف به دلیل خطا';
			$headline = 'اجرای خودکار متوقف شده و نیازمند بررسی است';
			$description = ! empty( $automation['message'] ) ? (string) $automation['message'] : 'جزئیات خطا را در جدول مرحله فعلی بررسی کنید.';
		} elseif ( $batch_running ) {
			$tone = 'running';
			$state_label = 'در حال اجرای batch';
			$headline = 'هم اکنون یک batch در حال اجرا است';
			$description = 'صفحه را باز نگه دارید یا منتظر پایان Cron/Self Runner بمانید. وضعیت پس از پایان batch خودکار تازه می شود.';
		} elseif ( 'waiting-retry' === $status_key ) {
			$tone = 'warning';
			$state_label = 'منتظر زمان تلاش مجدد';
			$headline = 'عملیات متوقف نشده؛ ردیف ها هنوز به زمان retry نرسیده اند';
			$description = ! empty( $automation['message'] ) ? (string) $automation['message'] : 'Cron بعدی پس از رسیدن زمان retry صف را ادامه می دهد.';
		} elseif ( 'waiting-active-processor' === $status_key ) {
			$tone = 'warning';
			$state_label = 'منتظر پایان پردازش دیگر';
			$headline = 'یک پردازش دیگر در حال استفاده از صف است';
			$description = ! empty( $automation['message'] ) ? (string) $automation['message'] : 'پس از آزاد شدن صف، اجرای بعدی ادامه پیدا می کند.';
		} elseif ( 'locked' === $status_key ) {
			$tone = 'warning';
			$state_label = 'قفل موقت اجرا';
			$headline = 'یک اجرای دیگر هم اکنون قفل نوسازی را در اختیار دارد';
			$description = 'این وضعیت معمولا موقت است. اگر بیش از چند دقیقه باقی ماند، timeout یا قفل رها نشده را بررسی کنید.';
		} elseif ( $stalled ) {
			$tone = 'error';
			$state_label = 'فعال ولی بدون پیشرفت';
			$headline = 'اجرای خودکار فعال است، اما اجرای جدید دیده نمی شود';
			$description = sprintf( 'آخرین فعالیت مربوط به %s قبل است. وضعیت Cron واقعی و Self Runner را بررسی یا یک batch را دستی اجرا کنید.', human_time_diff( $last_run_at, time() ) );
		} elseif ( $enabled ) {
			$tone = 'running';
			$state_label = $last_run_at > 0 ? 'فعال و در حال پیشرفت' : 'فعال؛ منتظر اولین batch';
			$headline = $last_run_at > 0 ? 'عملیات فعال است و به صورت مرحله ای جلو می رود' : 'اجرای خودکار فعال شده و منتظر اولین اجرا است';
			$description = ! empty( $automation['message'] ) ? (string) $automation['message'] : 'Cron واقعی یا Self Runner batch بعدی را اجرا می کند.';
		} elseif ( 'paused' === $status_key ) {
			$tone = 'warning';
			$state_label = 'متوقف شده';
			$headline = 'اجرای خودکار متوقف شده است';
			$description = ! empty( $automation['message'] ) ? (string) $automation['message'] : 'پیشرفت حفظ شده و قابل ادامه است.';
		} elseif ( $step > 0 && ( ! empty( $scan['checkedAt'] ) || ! empty( $enqueue['checkedAt'] ) ) ) {
			$tone = 'warning';
			$state_label = 'پیشرفت ذخیره شده؛ اجرا غیرفعال';
			$headline = 'عملیات نیمه کاره است ولی اجرای خودکار خاموش است';
			$description = 'برای ادامه از همین نقطه، دکمه «شروع یا ادامه اجرای خودکار امن» را بزنید.';
		}

		$current_progress_text = $this->get_image_refresh_step_progress_text( $step, $queue_status, $scan, $enqueue, $subsize_scan, $subsize_repair, $replaced_scan, $replaced_delete, $orphan_status, $orphan_scan, $orphan_delete );
		$last_activity_text = $last_run_at > 0 ? human_time_diff( $last_run_at, time() ) . ' قبل' : 'هنوز ثبت نشده';
		$runner_text = 'هیچ اجرای Cron یا Self Runner ثبت نشده است';
		$runner_tone = 'warning';
		if ( $runner_last > 0 ) {
			$runner_age = max( 0, time() - $runner_last );
			$runner_text = ( $self_last >= $cron_last ? 'Self Runner' : 'Cron واقعی' ) . '؛ آخرین اجرا ' . human_time_diff( $runner_last, time() ) . ' قبل';
			$runner_tone = $runner_age <= $stale_after ? 'success' : 'error';
		}

		$last_batch_text = ! empty( $automation['message'] ) ? (string) $automation['message'] : 'هنوز batch ثبت نشده است.';
		if ( ! empty( $operation ) ) {
			$parts = array();
			foreach ( array(
				'scanned' => 'بررسی شده', 'enqueued' => 'صف شده', 'processed' => 'پردازش شده', 'done' => 'موفق',
				'repaired' => 'بازسازی شده', 'generatedFiles' => 'فایل ساخته شده', 'deleted' => 'حذف شده',
				'deletedFamilies' => 'خانواده حذف شده', 'failed' => 'ناموفق', 'remainingFamilies' => 'باقی مانده',
			) as $key => $label ) {
				if ( isset( $operation[ $key ] ) && is_numeric( $operation[ $key ] ) ) {
					$parts[] = $label . ': ' . absint( $operation[ $key ] );
				}
			}
			if ( ! empty( $parts ) ) {
				$last_batch_text .= ' — ' . implode( '، ', array_slice( $parts, 0, 6 ) );
			}
		}

		$timeline = array();
		for ( $i = 1; $i <= 9; $i++ ) {
			$timeline_state = 'pending';
			if ( 'completed' === $status_key || ( $step > 0 && $i < $step ) ) {
				$timeline_state = 'done';
			} elseif ( $i === $step ) {
				$timeline_state = $error || $stalled || $batch_stuck ? 'error' : ( '' !== $waiting ? 'waiting' : ( $enabled || $batch_running ? 'active' : 'paused' ) );
			}
			$timeline[] = array( 'number' => $i, 'title' => $step_titles[ $i ], 'state' => $timeline_state );
		}

		return array(
			'tone'                => $tone,
			'stateLabel'          => $state_label,
			'headline'            => $headline,
			'description'         => $description,
			'step'                => $step,
			'stepTitle'           => $step > 0 && isset( $step_titles[ $step ] ) ? $step_titles[ $step ] : ( 'completed' === $status_key ? 'چرخه کامل شده' : 'هنوز مرحله ای فعال نیست' ),
			'stepPercent'         => $step_percent,
			'overallPercent'      => $overall_percent,
			'currentProgressText' => $current_progress_text,
			'lastActivityText'    => $last_activity_text,
			'runnerText'          => $runner_text,
			'runnerTone'          => $runner_tone,
			'lastBatchText'       => $last_batch_text,
			'timeline'            => $timeline,
			'batchRunning'        => $batch_running,
			'stalled'             => $stalled,
		);
	}

	/**
	 * Detect the stage that still needs work, independently of the last status message.
	 *
	 * @return int
	 */
	private function detect_image_refresh_display_step( $flags, $queue_status, $subsize_scan, $subsize_repair, $replaced_scan, $replaced_delete, $orphan_status, $orphan_scan, $orphan_delete, $automation ) {
		if ( empty( $flags['scanComplete'] ) ) return 1;
		if ( empty( $flags['enqueueComplete'] ) ) return 2;
		if ( absint( isset( $queue_status['pending'] ) ? $queue_status['pending'] : 0 ) > 0 || absint( isset( $queue_status['failed'] ) ? $queue_status['failed'] : 0 ) > 0 ) return 3;

		$scan_time   = absint( isset( $subsize_scan['checkedAt'] ) ? $subsize_scan['checkedAt'] : 0 );
		$repair_time = absint( isset( $subsize_repair['checkedAt'] ) ? $subsize_repair['checkedAt'] : 0 );
		if ( empty( $subsize_scan['cycleComplete'] ) || ( ! empty( $subsize_repair['cycleComplete'] ) && $repair_time >= $scan_time ) ) return 4;
		if ( absint( isset( $subsize_scan['needsRepair'] ) ? $subsize_scan['needsRepair'] : 0 ) > 0 ) return 5;

		$replaced_scan_time   = absint( isset( $replaced_scan['checkedAt'] ) ? $replaced_scan['checkedAt'] : 0 );
		$replaced_delete_time = absint( isset( $replaced_delete['checkedAt'] ) ? $replaced_delete['checkedAt'] : 0 );
		if ( empty( $replaced_scan['cycleComplete'] ) ) return 6;
		$replaced_actionable = absint( isset( $replaced_scan['ready'] ) ? $replaced_scan['ready'] : 0 )
			+ absint( isset( $replaced_scan['migrationCandidates'] ) ? $replaced_scan['migrationCandidates'] : 0 );
		$delete_newer = ! empty( $replaced_delete['cycleComplete'] ) && $replaced_delete_time >= $replaced_scan_time;
		$delete_progress = absint( isset( $replaced_delete['passProgress'] )
			? $replaced_delete['passProgress']
			: absint( isset( $replaced_delete['deleted'] ) ? $replaced_delete['deleted'] : 0 )
				+ absint( isset( $replaced_delete['referenceRowsUpdated'] ) ? $replaced_delete['referenceRowsUpdated'] : 0 ) );
		if ( $replaced_actionable > 0 && ( ! $delete_newer || $delete_progress > 0 ) ) return 7;

		$orphan_scan_time   = absint( isset( $orphan_scan['checkedAt'] ) ? $orphan_scan['checkedAt'] : 0 );
		$orphan_delete_time = absint( isset( $orphan_delete['executedAt'] ) ? $orphan_delete['executedAt'] : 0 );
		$orphan_needs_rescan = $orphan_delete_time > 0 && $orphan_delete_time >= $orphan_scan_time;
		if ( empty( $orphan_scan['cycleComplete'] ) || $orphan_needs_rescan ) return 8;
		if ( absint( isset( $orphan_status['candidate'] ) ? $orphan_status['candidate'] : 0 ) > 0 ) return 9;
		return 'completed' === ( isset( $automation['status'] ) ? $automation['status'] : '' ) ? 0 : 8;
	}

	/** @return float */
	private function get_image_refresh_result_percent( $result, $processed_key = 'scanned' ) {
		$result = is_array( $result ) ? $result : array();
		if ( ! empty( $result['cycleComplete'] ) ) return 100;
		$total = absint( isset( $result['estimatedTotal'] ) ? $result['estimatedTotal'] : 0 );
		$done  = absint( isset( $result[ $processed_key ] ) ? $result[ $processed_key ] : 0 );
		return $total > 0 ? min( 99.9, round( $done / $total * 100, 1 ) ) : 0;
	}

	/** @return float */
	private function get_image_refresh_step_percent( $step, $queue_status, $scan, $enqueue, $subsize_scan, $subsize_repair, $replaced_scan, $replaced_delete, $orphan_status, $orphan_scan, $orphan_delete ) {
		switch ( absint( $step ) ) {
			case 1: return $this->get_image_refresh_result_percent( $scan, 'scanned' );
			case 2: return $this->get_image_refresh_result_percent( $enqueue, 'scanned' );
			case 3:
				$done = absint( isset( $queue_status['done'] ) ? $queue_status['done'] : 0 ) + absint( isset( $queue_status['skipped'] ) ? $queue_status['skipped'] : 0 );
				$total = $done + absint( isset( $queue_status['pending'] ) ? $queue_status['pending'] : 0 ) + absint( isset( $queue_status['failed'] ) ? $queue_status['failed'] : 0 );
				return $total > 0 ? min( 100, round( $done / $total * 100, 1 ) ) : 100;
			case 4: return $this->get_image_refresh_result_percent( $subsize_scan, 'scanned' );
			case 5: return $this->get_image_refresh_result_percent( $subsize_repair, 'scanned' );
			case 6: return $this->get_image_refresh_result_percent( $replaced_scan, 'scanned' );
			case 7:
				if ( ! empty( $replaced_delete['cycleComplete'] ) && ! empty( $replaced_delete['needsAnotherPass'] ) ) return 99.9;
				return $this->get_image_refresh_result_percent( $replaced_delete, 'scanned' );
			case 8: return $this->get_image_refresh_result_percent( $orphan_scan, 'processedAttachments' );
			case 9:
				$remaining = absint( isset( $orphan_status['candidate'] ) ? $orphan_status['candidate'] : 0 );
				$deleted = absint( isset( $orphan_status['deleted'] ) ? $orphan_status['deleted'] : 0 );
				$total = $remaining + $deleted;
				return $total > 0 ? min( 100, round( $deleted / $total * 100, 1 ) ) : 100;
		}
		return 0;
	}

	/** @return string */
	private function get_image_refresh_step_progress_text( $step, $queue_status, $scan, $enqueue, $subsize_scan, $subsize_repair, $replaced_scan, $replaced_delete, $orphan_status, $orphan_scan, $orphan_delete ) {
		switch ( absint( $step ) ) {
			case 1: return $this->format_scan_progress( $scan, 'scanned' );
			case 2: return $this->format_scan_progress( $enqueue, 'scanned' );
			case 3: return sprintf( 'موفق/ردشده: %d، در انتظار: %d، آماده اکنون: %d، ناموفق: %d', absint( isset( $queue_status['done'] ) ? $queue_status['done'] : 0 ) + absint( isset( $queue_status['skipped'] ) ? $queue_status['skipped'] : 0 ), absint( isset( $queue_status['pending'] ) ? $queue_status['pending'] : 0 ), absint( isset( $queue_status['due'] ) ? $queue_status['due'] : 0 ), absint( isset( $queue_status['failed'] ) ? $queue_status['failed'] : 0 ) );
			case 4: return $this->format_scan_progress( $subsize_scan, 'scanned' );
			case 5: return $this->format_scan_progress( $subsize_repair, 'scanned' );
			case 6: return $this->format_scan_progress( $replaced_scan, 'scanned' );
			case 7:
				if ( ! empty( $replaced_delete['cycleComplete'] ) && ! empty( $replaced_delete['needsAnotherPass'] ) ) {
					return sprintf( 'این گذر کامل شد: %d حذف، %d ردیف مرجع منتقل شد؛ گذر بعدی خودکار اجرا می شود.', absint( isset( $replaced_delete['deleted'] ) ? $replaced_delete['deleted'] : 0 ), absint( isset( $replaced_delete['referenceRowsUpdated'] ) ? $replaced_delete['referenceRowsUpdated'] : 0 ) );
				}
				if ( ! empty( $replaced_delete['stableComplete'] ) ) {
					return sprintf( 'Stage 7 به نقطه پایدار رسید؛ %d Safety Block و %d خطای عملیاتی در آخرین گذر باقی ماند.', absint( isset( $replaced_delete['blocked'] ) ? $replaced_delete['blocked'] : 0 ), absint( isset( $replaced_delete['errors'] ) ? $replaced_delete['errors'] : 0 ) );
				}
				return $this->format_scan_progress( $replaced_delete, 'scanned' );
			case 8: return $this->format_scan_progress( $orphan_scan, 'processedAttachments' );
			case 9: return sprintf( 'خانواده باقی مانده: %d، حذف شده: %d، ناموفق: %d', absint( isset( $orphan_status['candidate'] ) ? $orphan_status['candidate'] : 0 ), absint( isset( $orphan_status['deleted'] ) ? $orphan_status['deleted'] : 0 ), absint( isset( $orphan_status['failed'] ) ? $orphan_status['failed'] : 0 ) );
		}
		return 'هنوز داده ای برای این مرحله ثبت نشده است.';
	}

	/**
	 * Render the first and authoritative status panel for image refresh.
	 *
	 * @return void
	 */
	private function render_image_refresh_command_center( $overview, $automation, $active, $waiting, $state ) {
		$overview = is_array( $overview ) ? $overview : array();
		$tone = isset( $overview['tone'] ) ? sanitize_key( (string) $overview['tone'] ) : 'idle';
		$step_percent = isset( $overview['stepPercent'] ) ? (float) $overview['stepPercent'] : 0;
		$overall_percent = isset( $overview['overallPercent'] ) ? (float) $overview['overallPercent'] : 0;
		$source_key = isset( $automation['lastTickSource'] ) ? sanitize_key( (string) $automation['lastTickSource'] ) : '';
		$source_labels = array(
			'real-cron' => 'Cron واقعی',
			'server-cron' => 'Cron سرور',
			'cpanel-local-php-cron' => 'Cron سی پنل',
			'directadmin-local-php-cron' => 'Cron دایرکت ادمین',
			'self-runner' => 'Self Runner',
			'admin-manual' => 'اجرای دستی مدیر',
			'admin-image-refresh-live-status' => 'بیدارسازی از نمایش زنده',
		);
		$source_label = '' !== $source_key ? ( isset( $source_labels[ $source_key ] ) ? $source_labels[ $source_key ] : $source_key ) : '';
		?>
		<div class="mobo-image-command-center mobo-image-command-center-<?php echo esc_attr( $tone ); ?>">
			<div class="mobo-image-command-main">
				<div class="mobo-image-state-line">
					<span class="mobo-image-state-dot <?php echo ! empty( $overview['batchRunning'] ) ? 'is-pulsing' : ''; ?>"></span>
					<strong><?php echo esc_html( isset( $overview['stateLabel'] ) ? $overview['stateLabel'] : 'نامشخص' ); ?></strong>
					<span><?php echo esc_html( isset( $overview['step'] ) && $overview['step'] > 0 ? 'مرحله ' . absint( $overview['step'] ) . ' از ۹' : 'چرخه نوسازی' ); ?></span>
				</div>
				<h2><?php echo esc_html( isset( $overview['headline'] ) ? $overview['headline'] : 'وضعیت نامشخص است' ); ?></h2>
				<p class="mobo-image-command-description"><?php echo esc_html( isset( $overview['description'] ) ? $overview['description'] : '' ); ?></p>
			</div>

			<div class="mobo-image-command-grid">
				<div><span>مرحله جاری</span><strong><?php echo esc_html( isset( $overview['stepTitle'] ) ? $overview['stepTitle'] : '—' ); ?></strong></div>
				<div><span>پیشرفت همین مرحله</span><strong><?php echo esc_html( $this->format_percent( $step_percent ) ); ?>٪</strong><small><?php echo esc_html( isset( $overview['currentProgressText'] ) ? $overview['currentProgressText'] : '' ); ?></small></div>
				<div><span>پیشرفت تقریبی کل چرخه</span><strong><?php echo esc_html( $this->format_percent( $overall_percent ) ); ?>٪</strong><small>بر اساس ۹ مرحله کنترل شده</small></div>
				<div><span>آخرین فعالیت واقعی</span><strong><?php echo esc_html( isset( $overview['lastActivityText'] ) ? $overview['lastActivityText'] : '—' ); ?></strong><small><?php echo esc_html( '' !== $source_label ? 'منبع: ' . $source_label : 'منبع هنوز ثبت نشده' ); ?></small></div>
			</div>

			<div class="mobo-image-progress-block">
				<div class="mobo-progress-meta"><span>پیشرفت مرحله جاری</span><strong><?php echo esc_html( $this->format_percent( $step_percent ) ); ?>٪</strong></div>
				<div class="mobo-progress"><div style="width:<?php echo esc_attr( min( 100, max( 0, $step_percent ) ) ); ?>%"></div></div>
			</div>

			<div class="mobo-image-runner-line mobo-image-runner-<?php echo esc_attr( isset( $overview['runnerTone'] ) ? $overview['runnerTone'] : 'warning' ); ?>">
				<strong>موتور اجرا:</strong> <?php echo esc_html( isset( $overview['runnerText'] ) ? $overview['runnerText'] : 'نامشخص' ); ?>
			</div>

			<div class="mobo-image-last-batch">
				<strong>آخرین کاری که انجام شد:</strong>
				<span><?php echo esc_html( isset( $overview['lastBatchText'] ) ? $overview['lastBatchText'] : 'هنوز گزارشی ثبت نشده است.' ); ?></span>
			</div>

			<div class="mobo-image-timeline" aria-label="مراحل نوسازی تصاویر">
				<?php foreach ( isset( $overview['timeline'] ) && is_array( $overview['timeline'] ) ? $overview['timeline'] : array() as $item ) : ?>
					<div class="mobo-image-timeline-item is-<?php echo esc_attr( isset( $item['state'] ) ? $item['state'] : 'pending' ); ?>">
						<span><?php echo esc_html( absint( isset( $item['number'] ) ? $item['number'] : 0 ) ); ?></span>
						<small><?php echo esc_html( isset( $item['title'] ) ? $item['title'] : '' ); ?></small>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a bounded percentage for multi-step image scans.
	 *
	 * @param array $result Scan result.
	 * @param string $processed_key Processed counter.
	 * @return string
	 */
	private function format_scan_progress( $result, $processed_key = 'scanned' ) {
		if ( ! is_array( $result ) || empty( $result['checkedAt'] ) ) {
			return 'شروع نشده';
		}
		if ( ! empty( $result['cycleComplete'] ) ) {
			return '۱۰۰٪ - دوره کامل شد';
		}

		$processed = absint( isset( $result[ $processed_key ] ) ? $result[ $processed_key ] : 0 );
		$total     = absint( isset( $result['estimatedTotal'] ) ? $result['estimatedTotal'] : 0 );
		if ( $total > 0 ) {
			$percent = min( 99.9, round( ( $processed / $total ) * 100, 1 ) );
			return sprintf( '%s%% - %d از حدود %d', $percent, $processed, $total );
		}

		return sprintf(
			'در حال ادامه - %d مورد بررسی شده، cursor: %d',
			$processed,
			absint( isset( $result['cursorEnd'] ) ? $result['cursorEnd'] : 0 )
		);
	}



	/**
	 * Render one workflow-aware operation button.
	 *
	 * @param array  $steps Workflow steps.
	 * @param int    $step Step number.
	 * @param string $class CSS classes.
	 * @param string $fallback_label Fallback label.
	 * @return void
	 */
	private function image_workflow_button( $steps, $step, $class, $fallback_label ) {
		$step_data = isset( $steps[ $step ] ) && is_array( $steps[ $step ] ) ? $steps[ $step ] : array();
		$enabled   = ! empty( $step_data['enabled'] );
		$label     = isset( $step_data['label'] ) && '' !== (string) $step_data['label'] ? (string) $step_data['label'] : (string) $fallback_label;
		$reason    = isset( $step_data['reason'] ) ? (string) $step_data['reason'] : '';

		?>
		<button
			type="submit"
			class="<?php echo esc_attr( $class ); ?>"
			title="<?php echo esc_attr( $reason ); ?>"
			aria-disabled="<?php echo $enabled ? 'false' : 'true'; ?>"
			<?php disabled( ! $enabled ); ?>
		><?php echo esc_html( $label ); ?></button>
		<?php if ( ! $enabled && '' !== $reason ) : ?>
			<small class="mobo-disabled-reason"><?php echo esc_html( $reason ); ?></small>
		<?php endif; ?>
		<?php
	}

	/**
	 * Status box.
	 *
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private function status_box( $label, $value, $field = '' ) {
		?>
		<div class="mobo-status-box" <?php echo '' !== $field ? 'data-mobo-sync-box="' . esc_attr( $field ) . '"' : ''; ?>>
			<span><?php echo esc_html( $label ); ?></span>
			<strong <?php echo '' !== $field ? 'data-mobo-sync-field="' . esc_attr( $field ) . '"' : ''; ?>><?php echo esc_html( (string) $value ); ?></strong>
		</div>
		<?php
	}


	// The following private save helpers are called only by handle_save_settings(),
	// after capability and mobo_core_save_settings nonce verification.
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	/**
	 * Save Mobo shipping rules for automatic order submission.
	 *
	 * @return void
	 */
	private function save_mobo_shipping_rules_from_post() {
		foreach ( $_POST as $key => $value ) {
			$key = (string) $key;
			if ( preg_match( '/^mobo_core_wc_shipping_method_map_(?:mobo_only|mixed)_zone_\d+_[a-z0-9_-]+_\d+(?:_posted)?$/', $key ) || preg_match( '/^mobo_core_wc_shipping_method_map_zone_\d+_[a-z0-9_-]+_\d+(?:_posted)?$/', $key ) || preg_match( '/^mobo_core_shipping_allowed_ids_(mobo_only|mixed)_state_\d+_(before12|after12)(?:_posted)?$/', $key ) ) {
				if ( false !== substr( $key, -7 ) && '_posted' === substr( $key, -7 ) ) {
					$ids_key = substr( $key, 0, -7 );
					$this->save_shipping_ids_option_from_post( $ids_key );
				} else {
					$this->save_shipping_ids_option_from_post( $key );
				}
			}
		}
	}

	/**
	 * Save selected Mobo shipping ID when the select was rendered.
	 *
	 * @param string $ids_key Option key.
	 * @return void
	 */
	private function save_shipping_ids_option_from_post( $ids_key ) {
		$ids_were_rendered = isset( $_POST[ $ids_key . '_posted' ] ) || isset( $_POST[ $ids_key ] );

		if ( ! $ids_were_rendered ) {
			return;
		}

		$id = 0;
		if ( isset( $_POST[ $ids_key ] ) ) {
			$raw = wp_unslash( $_POST[ $ids_key ] );
			if ( is_array( $raw ) ) {
				$raw = reset( $raw );
			}
			$id = absint( $raw );
		}

		Mobo_Core_Settings::update( $ids_key, $id > 0 ? (string) $id : '', false );
	}

	/**
	 * Save a list of boolean options from the current tab only.
	 *
	 * @param array $keys Option keys.
	 * @return void
	 */
	private function save_bool_options_from_post( $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				Mobo_Core_Settings::update( $key, $this->sanitize_bool_value( wp_unslash( $_POST[ $key ] ) ), false );
			}
		}
	}

	/**
	 * Save a list of bounded integer options from the current tab only.
	 *
	 * @param array $ranges Option key => array(min,max).
	 * @return void
	 */
	private function save_int_options_from_post( $ranges ) {
		foreach ( $ranges as $key => $range ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$value = absint( wp_unslash( $_POST[ $key ] ) );
			$value = max( absint( $range[0] ), min( absint( $range[1] ), $value ) );

			Mobo_Core_Settings::update( $key, $value, false );
		}
	}

	/**
	 * Save a text option if it exists in current tab POST.
	 *
	 * @param string $key Option key.
	 * @return void
	 */
	private function save_text_option_from_post( $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			Mobo_Core_Settings::update( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ), false );
		}
	}

	/**
	 * Save a URL option if it exists in current tab POST.
	 *
	 * @param string $key Option key.
	 * @return void
	 */
	private function save_url_option_from_post( $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			Mobo_Core_Settings::update( $key, esc_url_raw( trim( wp_unslash( $_POST[ $key ] ) ) ), false );
		}
	}

	/**
	 * Save a secret option only when admin entered a new value.
	 *
	 * @param string $key Option key.
	 * @return void
	 */
	private function save_secret_option_from_post( $key ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return;
		}

		if ( 'mobo_core_security_code' === $key ) {
			$value = Mobo_Core_Settings::normalize_security_code( wp_unslash( $_POST[ $key ] ) );

			if ( '' !== $value && Mobo_Core_Settings::is_valid_security_code( $value ) ) {
				Mobo_Core_Settings::update( $key, $value, false );
			}

			return;
		}

		$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );

		if ( '' !== $value ) {
			Mobo_Core_Settings::update( $key, $value, false );
		}
	}

	/**
	 * Save pricing settings. This method is intentionally only called from pricing tab.
	 *
	 * @return void
	 */
	private function save_pricing_tab_from_post() {
		if ( isset( $_POST['global_additional_price'] ) ) {
			$value = absint( wp_unslash( $_POST['global_additional_price'] ) );
			Mobo_Core_Settings::update( 'global_additional_price', $value, false );
		}

		if ( isset( $_POST['global_additional_percentage'] ) ) {
			$value = wc_format_decimal( wp_unslash( $_POST['global_additional_percentage'] ) );
			$value = is_numeric( $value ) ? max( 0, (float) $value ) : 0;
			Mobo_Core_Settings::update( 'global_additional_percentage', $value, false );
		}

		if ( isset( $_POST['mobo_price_type'] ) ) {
			$price_type = sanitize_key( wp_unslash( $_POST['mobo_price_type'] ) );

			if ( ! in_array( $price_type, array( 'static-price', 'static-percentage', 'dynamic-price' ), true ) ) {
				$price_type = 'static-price';
			}

			Mobo_Core_Settings::update( 'mobo_price_type', $price_type, false );
		}

		/* B11: optional rounding after markup. */
		if ( class_exists( 'Mobo_Core_Price_Rounding' ) && isset( $_POST[ Mobo_Core_Price_Rounding::OPTION_STEP ] ) ) {
			$step = Mobo_Core_Price_Rounding::normalize_step( sanitize_text_field( wp_unslash( $_POST[ Mobo_Core_Price_Rounding::OPTION_STEP ] ) ) );
			Mobo_Core_Settings::update( Mobo_Core_Price_Rounding::OPTION_STEP, $step, false );
		}
		if ( class_exists( 'Mobo_Core_Price_Rounding' ) && isset( $_POST[ Mobo_Core_Price_Rounding::OPTION_MODE ] ) ) {
			$mode = Mobo_Core_Price_Rounding::normalize_mode( sanitize_key( wp_unslash( $_POST[ Mobo_Core_Price_Rounding::OPTION_MODE ] ) ) );
			Mobo_Core_Settings::update( Mobo_Core_Price_Rounding::OPTION_MODE, $mode, false );
		}

		$this->save_dynamic_price_rules();
	}

	/**
	 * C5: label of the WooCommerce store currency for money inputs. Fixed
	 * markups and dynamic thresholds are evaluated in the STORE currency.
	 *
	 * @return string
	 */
	private function store_currency_label() {
		$unit = class_exists( 'Mobo_Core_Money_Policy' ) ? Mobo_Core_Money_Policy::store_unit() : '';
		if ( class_exists( 'Mobo_Core_Store_Currency_Guard' ) && '' !== $unit ) {
			$label = Mobo_Core_Store_Currency_Guard::unit_label( $unit );
			if ( $label !== $unit ) {
				return $label;
			}
		}
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = trim( wp_strip_all_tags( html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ) );
			if ( '' !== $symbol ) {
				return $symbol;
			}
		}
		return '' !== $unit ? $unit : 'ارز فروشگاه';
	}

	/**
	 * Save category mapping table from categories tab only.
	 *
	 * @return void
	 */
	private function save_category_mapping_from_post() {
		if ( ! isset( $_POST['mobo_category_map'] ) || ! is_array( $_POST['mobo_category_map'] ) || ! class_exists( 'Mobo_Core_Category_Map' ) ) {
			return;
		}

		$category_map = new Mobo_Core_Category_Map();
		$raw_map      = wp_unslash( $_POST['mobo_category_map'] );

		foreach ( $raw_map as $remote_guid => $term_id ) {
			$remote_guid = sanitize_text_field( (string) $remote_guid );
			$term_id     = absint( $term_id );

			if ( '' === $remote_guid ) {
				continue;
			}

			$category_map->update_manual_mapping( $remote_guid, $term_id );
		}

		/* Products that received a temporary category while their Source category
		 * was unmapped get the newly mapped category now (bounded, cron-driven). */
		if ( class_exists( 'Mobo_Core_Recategorize_Queue' ) ) {
			Mobo_Core_Recategorize_Queue::maybe_start_pending_reapply( 'manual-mapping-save' );
		}
	}

	/**
	 * Apply checkout-only dependent rules after checkout tab is saved.
	 *
	 * @return void
	 */
	private function apply_checkout_save_dependencies() {
		/* Hard-forced checkout safety rules; these are no longer optional UI settings. */
		Mobo_Core_Settings::update( 'mobo_core_checkout_validate_only_mobo_products', '1', false );
		Mobo_Core_Settings::update( 'mobo_core_checkout_require_remote_guid', '1', false );
		Mobo_Core_Settings::update( 'mobo_core_checkout_block_incomplete_sync', '1', false );

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_checkout_validation_enabled', '0' ) ) {
			Mobo_Core_Settings::update( 'mobo_core_checkout_local_stock_check_enabled', '0', false );
			Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_cart_validation_enabled', '0', false );
			Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_debug_enabled', '0', false );
			Mobo_Core_Settings::update( 'mobo_core_checkout_external_validation_enabled', '0', false );
			delete_option( 'mobo_core_shared_mobo_cart_lock' );
		}

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_checkout_mobo_cart_validation_enabled', '0' ) ) {
			Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_debug_enabled', '0', false );
		}

		if ( ! Mobo_Core_Order_Submission_Policy::is_enabled() ) {
			Mobo_Core_Settings::update( 'mobo_core_mobo_order_auto_complete_enabled', '0', false );
		}

	}


	/**
	 * Persist one setting and verify the authoritative read path afterwards.
	 * update_option()-compatible writers can return false when the value is unchanged,
	 * so read-back equality rather than the raw return value is the success contract.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $value Setting value.
	 * @return bool
	 */
	private function save_verified_admin_setting( $key, $value ) {
		Mobo_Core_Settings::update( $key, $value, false );
		$stored = Mobo_Core_Settings::get( $key, null );
		return self::admin_setting_read_back_matches( $stored, $value );
	}

	/**
	 * Compare a read-back setting with the value that was written.
	 *
	 * wp_options returns scalars as strings once the value comes from the
	 * database (e.g. "8"), while a fresh update_option() caches the written type
	 * (int 8). When the posted value is unchanged, update_option() writes nothing
	 * and the read-back is the DB string, so a strict int/string comparison would
	 * report a false failure on every re-save. Scalars are therefore compared by
	 * their stored string form; arrays/objects keep the serialized comparison.
	 *
	 * @param mixed $stored   Value read back through Mobo_Core_Settings::get().
	 * @param mixed $expected Value that was written.
	 * @return bool
	 */
	public static function admin_setting_read_back_matches( $stored, $expected ) {
		if ( null === $stored || null === $expected ) {
			return $stored === $expected;
		}
		if ( is_scalar( $stored ) && is_scalar( $expected ) ) {
			$normalize = static function ( $v ) {
				if ( is_bool( $v ) ) {
					return $v ? '1' : '';
				}
				return (string) $v;
			};
			return $normalize( $stored ) === $normalize( $expected );
		}
		return maybe_serialize( $stored ) === maybe_serialize( $expected );
	}

	/**
	 * Save SMS notification settings and return actionable configuration warnings.
	 *
	 * Order-SMS settings are installation-global. Wallet-alert settings are routed
	 * through Mobo_Core_Settings and therefore remain Connection-scoped.
	 *
	 * @return array|WP_Error
	 */
	private function save_sms_notification_settings_from_post() {
		$updates = array();

		foreach ( array( 'mobo_core_sms_notifications_enabled', 'mobo_core_wallet_alert_enabled' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$updates[ $key ] = $this->sanitize_bool_value( wp_unslash( $_POST[ $key ] ) );
			}
		}

		if ( isset( $_POST['mobo_core_wallet_alert_threshold'] ) ) {
			$updates['mobo_core_wallet_alert_threshold'] = max( 0, (int) wp_unslash( $_POST['mobo_core_wallet_alert_threshold'] ) );
		}
		if ( isset( $_POST['mobo_core_wallet_alert_recipients'] ) ) {
			$updates['mobo_core_wallet_alert_recipients'] = sanitize_textarea_field( wp_unslash( $_POST['mobo_core_wallet_alert_recipients'] ) );
		}
		if ( isset( $_POST['mobo_core_wallet_alert_template'] ) ) {
			$updates['mobo_core_wallet_alert_template'] = sanitize_textarea_field( wp_unslash( $_POST['mobo_core_wallet_alert_template'] ) );
		}

		$scenarios = class_exists( 'Mobo_Core_SMS_Notifications' ) ? ( new Mobo_Core_SMS_Notifications() )->get_scenarios() : array(
			'non_mobo'  => 'سفارش غیر موبو',
			'mobo_only' => 'سفارش فقط محصولات موبو',
			'mixed'     => 'سفارش ترکیبی موبو و غیرموبو',
		);

		foreach ( array_keys( $scenarios ) as $scenario ) {
			$scenario       = sanitize_key( $scenario );
			$enabled_key    = 'mobo_core_sms_' . $scenario . '_enabled';
			$recipients_key = 'mobo_core_sms_' . $scenario . '_recipients';
			$template_key   = 'mobo_core_sms_' . $scenario . '_template';

			if ( isset( $_POST[ $enabled_key ] ) ) {
				$updates[ $enabled_key ] = $this->sanitize_bool_value( wp_unslash( $_POST[ $enabled_key ] ) );
			}
			if ( isset( $_POST[ $recipients_key ] ) ) {
				$updates[ $recipients_key ] = sanitize_textarea_field( wp_unslash( $_POST[ $recipients_key ] ) );
			}
			if ( isset( $_POST[ $template_key ] ) ) {
				$updates[ $template_key ] = sanitize_textarea_field( wp_unslash( $_POST[ $template_key ] ) );
			}
		}

		$failed = array();
		foreach ( $updates as $key => $value ) {
			if ( ! $this->save_verified_admin_setting( $key, $value ) ) {
				$failed[] = $key;
			}
		}

		if ( ! empty( $failed ) ) {
			return new WP_Error(
				'mobo_core_sms_settings_save_failed',
				'ذخیره یا read-back برخی تنظیمات پیامک قابل تأیید نبود. برای جلوگیری از موفقیت کاذب، نتیجه ذخیره ناموفق اعلام شد.'
			);
		}

		$warnings = array();
		if ( Mobo_Core_Settings::enabled( 'mobo_core_sms_notifications_enabled', '0' ) ) {
			$enabled_count = 0;
			foreach ( $scenarios as $scenario => $label ) {
				$scenario = sanitize_key( $scenario );
				if ( ! Mobo_Core_Settings::enabled( 'mobo_core_sms_' . $scenario . '_enabled', '0' ) ) {
					continue;
				}
				$enabled_count++;
				$recipients = trim( (string) Mobo_Core_Settings::get( 'mobo_core_sms_' . $scenario . '_recipients', '' ) );
				$template   = trim( (string) Mobo_Core_Settings::get( 'mobo_core_sms_' . $scenario . '_template', '' ) );
				if ( '' === $recipients || '' === $template ) {
					$warnings[] = 'سناریوی «' . sanitize_text_field( (string) $label ) . '» فعال است اما گیرنده یا Template کامل نیست.';
				}
			}
			if ( 0 === $enabled_count ) {
				$warnings[] = 'master پیامک سفارش روشن است اما هیچ سناریوی سفارشی فعال نیست.';
			}
		}

		if ( Mobo_Core_Settings::enabled( 'mobo_core_wallet_alert_enabled', '0' ) ) {
			if ( '' === trim( (string) Mobo_Core_Settings::get( 'mobo_core_wallet_alert_recipients', '' ) ) || '' === trim( (string) Mobo_Core_Settings::get( 'mobo_core_wallet_alert_template', '' ) ) ) {
				$warnings[] = 'هشدار کیف پول روشن است اما گیرنده یا Template آن کامل نیست.';
			}
			if ( class_exists( 'Mobo_Core_Wallet_Alert' ) && ! Mobo_Core_Wallet_Alert::is_wallet_supported_for_current_connection() ) {
				$warnings[] = 'Provider انتخاب‌شده قابلیت خواندن موجودی کیف پول را ارائه نمی‌کند.';
			} elseif ( class_exists( 'Mobo_Core_Wallet_Alert' ) && ! Mobo_Core_Wallet_Alert::is_wallet_runtime_ready_for_current_connection() ) {
				$warnings[] = 'Connection انتخاب‌شده هنوز برای runtime فعال/provisioned/ownership-ready نیست.';
			}
		}

		$transport = $this->get_sms_transport_admin_status();
		if ( ( Mobo_Core_Settings::enabled( 'mobo_core_sms_notifications_enabled', '0' ) || Mobo_Core_Settings::enabled( 'mobo_core_wallet_alert_enabled', '0' ) ) && empty( $transport['available'] ) ) {
			$warnings[] = 'API افزونه Persian WooCommerce SMS در دسترس نیست؛ تنظیمات ذخیره شده‌اند اما ارسال فعلاً انجام نمی‌شود.';
		}

		return array( 'warnings' => array_values( array_unique( $warnings ) ) );
	}



	/**
	 * Save browser-owned credentials of the selected Connection. Server-owned routing
	 * metadata and display name are intentionally not accepted here. Secret fields
	 * preserve the old value when left empty. Default Connection mirrors credentials into
	 * legacy options only for rollback/backward compatibility.
	 *
	 * @return void
	 */
	private function save_selected_connection_from_post() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			return;
		}

		$connection = $this->current_admin_connection();
		$connection_id = is_array( $connection ) ? absint( $connection['id'] ?? 0 ) : 0;
		if ( $connection_id <= 0 ) {
			return;
		}

		$fields = array();
		if ( isset( $_POST['mobo_core_token'] ) ) {
			$token = Mobo_Core_Settings::normalize_opaque_secret( wp_unslash( $_POST['mobo_core_token'] ), 191 );
			if ( '' !== $token ) {
				$fields['license_token'] = $token;
			}
		}
		if ( isset( $_POST['mobo_core_security_code'] ) ) {
			$security = Mobo_Core_Settings::normalize_security_code( wp_unslash( $_POST['mobo_core_security_code'] ) );
			if ( '' !== $security ) {
				$fields['security_code'] = $security;
			}
		}

		$result = Mobo_Core_Connection_Repository::update_connection( $connection_id, $fields );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_message( 'اتصال ذخیره نشد: ' . $result->get_error_message(), 'error', 'connection' );
		}
		if ( false === $result ) {
			$this->redirect_with_message( 'اتصال ذخیره نشد؛ خطای دیتابیس رخ داد.', 'error', 'connection' );
		}

		$updated = Mobo_Core_Connection_Repository::get_by_id( $connection_id );
		if ( is_array( $updated ) && ! empty( $updated['is_default'] ) ) {
			if ( array_key_exists( 'license_token', $fields ) ) {
				update_option( 'mobo_core_token', (string) $updated['license_token'], false );
			}
			if ( array_key_exists( 'security_code', $fields ) ) {
				update_option( 'mobo_core_security_code', (string) $updated['security_code'], false );
			}
		}

		if ( array_key_exists( 'security_code', $fields ) && class_exists( 'Mobo_Core_Webhook_Auth_Status' ) ) {
			Mobo_Core_Settings::delete( Mobo_Core_Webhook_Auth_Status::OPTION_KEY );
		}
	}

	/**
	 * Normalize and durably save the Product Exclusions tab.
	 *
	 * @return array|WP_Error Parsed normalized input on success.
	 */
	private function save_filters_tab_from_post() {
		if ( ! class_exists( 'Mobo_Core_Product_Exclusions' ) ) {
			return new WP_Error( 'mobo_core_exclusions_unavailable', 'سیاست استثنای محصولات در دسترس نیست و تغییری ذخیره نشد.' );
		}

		/* B4: sanitize_textarea_field() removes %XX octets and destroyed Persian
		 * slugs. parse_input() cleans each line with sanitize_source_url() (tags,
		 * control characters, whitespace) and stores the decoded canonical path. */
		$raw = isset( $_POST['mobo_core_excluded_product_urls'] ) && is_string( $_POST['mobo_core_excluded_product_urls'] )
			? (string) wp_unslash( $_POST['mobo_core_excluded_product_urls'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per line by Mobo_Core_Product_Exclusions::parse_input().
			: '';
		$parsed = Mobo_Core_Product_Exclusions::parse_input( $raw );
		$invalid_count = isset( $parsed['invalidCount'] ) ? absint( $parsed['invalidCount'] ) : 0;

		if ( $invalid_count > 0 ) {
			return new WP_Error(
				'mobo_core_exclusions_invalid_input',
				sprintf( '%d خط قابل تبدیل به مسیر محصول نیست. URL باید مسیر محصول داشته باشد؛ فهرست قبلی بدون تغییر باقی ماند.', $invalid_count )
			);
		}

		$canonical = isset( $parsed['canonical'] ) ? (string) $parsed['canonical'] : '';
		if ( ! Mobo_Core_Settings::update( 'mobo_core_excluded_product_urls', $canonical, false ) ) {
			return new WP_Error( 'mobo_core_exclusions_save_failed', 'استثناهای همگام‌سازی در storage اتصال ذخیره نشدند؛ مقدار قبلی را معتبر در نظر بگیرید.' );
		}

		$stored = (string) Mobo_Core_Settings::get( 'mobo_core_excluded_product_urls', '' );
		if ( ! hash_equals( $canonical, $stored ) ) {
			return new WP_Error( 'mobo_core_exclusions_verify_failed', 'ذخیره استثناها قابل تأیید نبود؛ برای جلوگیری از وضعیت مبهم، ذخیره موفق اعلام نشد.' );
		}

		return $parsed;
	}


	/**
	 * Save the Queue/Performance tab through the authoritative Connection-scoped
	 * settings path and verify every posted value after normalization.
	 *
	 * Legacy no-op controls (`mobo_core_image_queue_blocking` and
	 * `mobo_core_variant_parent_wait_timeout_seconds`) are intentionally not
	 * accepted from the redesigned Admin form. Their stored values remain for
	 * backward compatibility with older/custom integrations.
	 *
	 * @return array|WP_Error
	 */
	private function save_queue_settings_from_post() {
		$updates  = array();
		$warnings = array();

		foreach ( array( 'mobo_core_product_cursor_sync_enabled', 'mobo_core_variant_cursor_sync_enabled', 'mobo_core_image_queue_enabled' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$updates[ $key ] = $this->sanitize_bool_value( wp_unslash( $_POST[ $key ] ) );
			}
		}

		$integer_rules = array(
			'mobo_core_sync_time_budget_seconds' => array( 2, 25 ),
			'mobo_core_products_per_page'        => array( 1, 20 ),
			'mobo_core_variants_per_page'        => array( 1, 100 ),
			'mobo_core_images_per_run'           => array( 1, 10 ),
			'mobo_core_image_host_rate_per_minute' => array( 1, 120 ),
			'mobo_core_image_max_try'            => array( 1, 20 ),
			'mobo_core_image_retry_base_seconds' => array( 30, 900 ),
			'mobo_core_image_long_retry_seconds' => array( 3600, 604800 ),
			'mobo_core_webhook_files_per_run'    => array( 1, 10 ),
			'mobo_core_webhook_max_try'          => array( 1, 20 ),
			'mobo_core_webhook_expire_days'      => array( 1, 30 ),
		);

		foreach ( $integer_rules as $key => $bounds ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$raw = intval( wp_unslash( $_POST[ $key ] ) );
			$value = max( absint( $bounds[0] ), min( absint( $bounds[1] ), $raw ) );
			$updates[ $key ] = $value;
			if ( 'mobo_core_images_per_run' === $key && $raw < 1 ) {
				$warnings[] = 'مقدار «تصویر در هر Worker slice» نمی‌تواند صفر باشد و برای جلوگیری از همگرایی کاذب تصاویر به ۱ اصلاح شد.';
			}
		}

		if ( isset( $_POST['mobo_core_missing_variants_behavior'] ) ) {
			$behavior = sanitize_key( wp_unslash( $_POST['mobo_core_missing_variants_behavior'] ) );
			if ( ! in_array( $behavior, array( 'outofstock', 'ignore' ), true ) ) {
				$behavior = 'outofstock';
			}
			$updates['mobo_core_missing_variants_behavior'] = $behavior;
		}

		$failed = array();
		foreach ( $updates as $key => $value ) {
			if ( ! $this->save_verified_admin_setting( $key, $value ) ) {
				$failed[] = $key;
			}
		}

		if ( ! empty( $failed ) ) {
			return new WP_Error(
				'mobo_core_queue_settings_save_failed',
				'ذخیره یا read-back برخی تنظیمات ظرفیت/صف قابل تأیید نبود. برای جلوگیری از موفقیت کاذب، نتیجه ذخیره ناموفق اعلام شد.'
			);
		}

		if ( isset( $updates['mobo_core_image_queue_enabled'] ) && '0' === (string) $updates['mobo_core_image_queue_enabled'] ) {
			$warnings[] = 'صف مستقل تصویر خاموش است؛ تصاویر خاموش نمی‌شوند و مسیر مستقیم می‌تواند latency همگام‌سازی را افزایش دهد.';
		}
		if ( Mobo_Core_Settings::enabled( 'mobo_core_adaptive_execution_enabled', '1' ) ) {
			$warnings[] = 'Adaptive Execution روشن است؛ مقادیر ذخیره‌شده baseline هستند و limit مؤثر Worker ممکن است در runtime تغییر کند.';
		}

		return array( 'warnings' => array_values( array_unique( $warnings ) ) );
	}

	/**
	 * Save settings for the active tab only.
	 *
	 * @param string $tab Active tab.
	 * @return void
	 */
	private function save_current_tab_settings( $tab ) {
		switch ( $tab ) {
			case 'connection':
				$this->save_selected_connection_from_post();
				break;

			case 'product':
				$this->save_bool_options_from_post(
					array(
						'global_product_auto_stock',
						'global_product_auto_price',
						'global_product_auto_title',
						'global_product_auto_slug',
						'mobo_core_only_in_stock',
						'global_product_auto_compare_price',
						'global_update_images',
						'global_product_auto_description',
						'mobo_core_cache_warmup_enabled',
					)
				);
				$this->save_cache_archive_purge_interval_from_post();
				$this->save_cache_integration_mode_from_post();
				$policy = isset( $_POST['mobo_core_cache_warmup_policy'] ) ? sanitize_key( wp_unslash( $_POST['mobo_core_cache_warmup_policy'] ) ) : 'selective';
				Mobo_Core_Settings::update( 'mobo_core_cache_warmup_policy', in_array( $policy, array( 'selective', 'all' ), true ) ? $policy : 'selective', false );
				break;

			case 'categories':
				$this->save_bool_options_from_post( array( 'global_update_categories', 'mobo_core_category_mapping_enabled', 'mobo_core_category_mapping_required' ) );
				if ( isset( $_POST['mobo_core_category_mapping_enabled'] ) && '0' === $this->sanitize_bool_value( wp_unslash( $_POST['mobo_core_category_mapping_enabled'] ) ) ) {
					Mobo_Core_Settings::update( 'mobo_core_category_mapping_required', '0', false );
				}
				$this->save_int_options_from_post( array( 'mobo_default_category_id' => array( 0, PHP_INT_MAX ) ) );
				$this->save_category_mapping_from_post();
				break;

			case 'pricing':
				$this->save_pricing_tab_from_post();
				break;

			case 'filters':
				/* Saved through save_filters_tab_from_post() in handle_save_settings() so
				 * normalization, persistence verification and user feedback are atomic. */
				break;

			case 'queue':
				/* Saved and read-back verified atomically in handle_save_settings(). */
				break;


			case 'sync-health':
				/* Sync Health is observational only; retired reconciliation settings are ignored. */
				break;

			case 'image-refresh':
				/*
				 * Image Refresh has no administrator-tunable destructive decisions. Legacy
				 * cached forms may still POST these fields, so fail closed and keep the
				 * destructive switches owned exclusively by the autonomous stage machine.
				 */
				Mobo_Core_Settings::update( 'mobo_core_image_refresh_enabled', '0', false );
				Mobo_Core_Settings::update( 'mobo_core_image_refresh_delete_old', '0', false );
				update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
				Mobo_Core_Settings::update( 'mobo_core_image_refresh_generate_subsizes', '1', false );
				Mobo_Core_Settings::update( 'mobo_core_image_refresh_cleanup_leftover_subsizes', '1', false );
				break;

			case 'cron':
				$this->save_bool_options_from_post(
					array(
						'mobo_core_self_runner_enabled',
						'mobo_core_self_runner_continue_enabled',
						'mobo_core_real_cron_process_webhooks',
						'mobo_core_adaptive_execution_enabled',
						'mobo_core_process_webhook_on_receive',
						'mobo_core_pull_payload_enabled',
					)
				);
				$this->save_int_options_from_post(
					array(
						'mobo_core_real_cron_time_budget_seconds' => array( 5, 55 ),
						'mobo_core_real_cron_max_sync_steps' => array( 1, 20 ),
						'mobo_core_real_cron_max_rounds' => array( 1, 500 ),
						'mobo_core_real_cron_safety_margin_seconds' => array( 1, 10 ),
						'mobo_core_real_cron_lock_ttl_seconds' => array( 30, 600 ),
						'mobo_core_real_cron_expected_interval_seconds' => array( 60, 3600 ),
						'mobo_core_self_runner_min_interval_seconds' => array( 0, 60 ),
						'mobo_core_self_runner_http_timeout_seconds' => array( 1, 10 ),
						'mobo_core_payload_pull_timeout_seconds' => array( 5, 180 ),
						'mobo_core_api_request_timeout_seconds' => array( 5, 180 ),
						'mobo_core_transient_retry_max_try' => array( 1, 50 ),
						'mobo_core_waiting_for_portal_retry_delay_seconds' => array( 10, 3600 ),
					)
				);
				$this->save_secret_option_from_post( 'mobo_core_cron_token' );
				break;

			case 'checkout':
				$this->save_bool_options_from_post(
					array(
						'mobo_core_checkout_validation_enabled',
						'mobo_core_checkout_local_stock_check_enabled',
						'mobo_core_checkout_mobo_cart_validation_enabled',
						'mobo_core_checkout_mobo_debug_enabled',
						'mobo_core_shipping_diagnostics_enabled',
						'mobo_core_mobo_order_submission_enabled',
						'mobo_core_mobo_order_auto_complete_enabled',
						'mobo_core_source_price_guard_enabled',
						'mobo_core_checkout_external_validation_enabled',
						'mobo_core_address_mapping_show_all_countries',
					)
				);
				$this->save_int_options_from_post(
					array(
						'mobo_core_checkout_mobo_timeout_seconds' => array( 2, 20 ),
						'mobo_core_checkout_mobo_cart_lock_wait_seconds' => array( 0, 45 ),
						'mobo_core_checkout_mobo_cart_lock_ttl_seconds' => array( 15, 300 ),
						'mobo_core_checkout_external_timeout_seconds' => array( 1, 10 ),
						'mobo_core_address_mapping_sync_interval_days' => array( 1, 30 ),
						'mobo_core_remote_shipping_sync_interval_hours' => array( 1, 168 ),
					)
				);
				$old_mobo_username = (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_username', '' );
				$current_connection = $this->current_admin_connection();
				if ( ! is_array( $current_connection ) || ! empty( $current_connection['is_default'] ) ) {
					// Legacy rollback mirror only; runtime checkout routing is Source Registry-owned.
					delete_option( 'mobo_core_checkout_mobo_site_url' );
				}
				$this->save_text_option_from_post( 'mobo_core_checkout_mobo_username' );
				$this->save_text_option_from_post( 'mobo_core_mobo_order_sender_name' );
				$this->save_text_option_from_post( 'mobo_core_mobo_order_sender_mobile' );
				$this->save_url_option_from_post( 'mobo_core_checkout_external_validation_url' );
				if ( isset( $_POST['mobo_core_checkout_mobo_password'] ) ) {
					$mobo_password = (string) wp_unslash( $_POST['mobo_core_checkout_mobo_password'] );
					if ( '' !== $mobo_password ) {
						Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_password', Mobo_Core_Settings::normalize_opaque_secret( $mobo_password ), false );
						Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_cookie_reset_pending', '1', false );
					}
				}
				if ( $old_mobo_username !== (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_username', '' ) ) {
					Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_cookie_reset_pending', '1', false );
				}
				if ( isset( $_POST['mobo_core_checkout_external_error_behavior'] ) ) {
					$checkout_error_behavior = sanitize_key( wp_unslash( $_POST['mobo_core_checkout_external_error_behavior'] ) );
					if ( ! in_array( $checkout_error_behavior, array( 'allow', 'block' ), true ) ) {
						$checkout_error_behavior = 'allow';
					}
					Mobo_Core_Settings::update( 'mobo_core_checkout_external_error_behavior', $checkout_error_behavior, false );
				}
				$this->save_mobo_shipping_rules_from_post();
				$this->apply_checkout_save_dependencies();
				break;

			case 'sms':
				/* Saved atomically in handle_save_settings() so write/read-back failures and
				 * configuration warnings can produce accurate feedback. */
				break;

			case 'health':
				// Health is pull-only. Keep legacy options explicitly disabled.
				update_option( 'mobo_core_health_report_enabled', '0', false );
				delete_option( 'mobo_core_health_report_url' );
				break;
		}
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized



	/**
	 * Apply the standalone shipping wizard.
	 *
	 * @return void
	 */
	public function handle_apply_shipping_wizard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}
		$this->redirect_with_message( 'ویزارد حمل‌ونقل از نسخه 10.33.0 حذف شده است. روش‌های ارسال را در WooCommerce مدیریت و فقط نگاشت روش ارسال به موبو را در همین تب تنظیم کنید.', 'warning', 'checkout' );
	}

	/**
	 * Handle separated admin/support tool actions without saving tab settings.
	 *
	 * @return void
	 */
	public function handle_admin_tool_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_admin_tool', 'mobo_core_tool_nonce' );

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$tab    = isset( $_POST['mobo_tool_tab'] ) ? sanitize_key( wp_unslash( $_POST['mobo_tool_tab'] ) ) : 'dashboard';

		switch ( $action ) {
			case 'mobo_core_tool_clear_mobo_debug_log':
				if ( class_exists( 'Mobo_Core_Checkout_Validator' ) ) {
					$validator = new Mobo_Core_Checkout_Validator();
					if ( method_exists( $validator, 'clear_mobo_debug_log' ) ) {
						$validator->clear_mobo_debug_log();
					}
				}
				$this->redirect_with_message( 'لاگ دیباگ سبد موبو پاک شد.', 'success', 'checkout' );
				break;

			case 'mobo_core_tool_clear_shipping_diagnostics':
				if ( class_exists( 'Mobo_Core_Shipping_Diagnostics' ) ) {
					$shipping_diagnostics = new Mobo_Core_Shipping_Diagnostics();
					if ( method_exists( $shipping_diagnostics, 'clear' ) ) {
						$shipping_diagnostics->clear();
					}
				}
				$this->redirect_with_message( 'گزارش دیباگ حمل و نقل پاک شد.', 'success', 'checkout' );
				break;

			case 'mobo_core_tool_sync_address_mapping':
				if ( ! class_exists( 'Mobo_Core_Address_Mapping' ) ) {
					$this->redirect_with_message( 'کلاس بروزرسانی آدرس‌ها در دسترس نیست.', 'error', 'checkout' );
				}

				$address_mapping = new Mobo_Core_Address_Mapping();
				$result = $address_mapping->sync_now( 'manual', true );

				if ( empty( $result['success'] ) ) {
					$message = isset( $result['message'] ) ? $result['message'] : 'بروزرسانی آدرس‌ها ناموفق بود.';
					$this->redirect_with_message( 'بروزرسانی آدرس‌ها ناموفق بود: ' . $message, 'error', 'checkout' );
				}

				$this->redirect_with_message( 'کشور، استان و شهرها از MoboCore بروزرسانی و فایل‌های شهر Checkout ساخته شدند.', 'success', 'checkout' );
				break;

			case 'mobo_core_tool_sync_remote_shipping_methods':
				if ( ! class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ) {
					$this->redirect_with_message( 'کلاس بروزرسانی روش‌های ارسال موبو در دسترس نیست.', 'error', 'checkout' );
				}

				$remote_shipping = new Mobo_Core_Remote_Shipping_Methods();
				$result = $remote_shipping->sync_now( 'manual', true );

				if ( empty( $result['success'] ) ) {
					$message = isset( $result['message'] ) ? $result['message'] : 'بروزرسانی روش‌های ارسال موبو ناموفق بود.';
					$this->redirect_with_message( 'بروزرسانی روش‌های ارسال موبو ناموفق بود: ' . $message, 'error', 'checkout' );
				}

				$this->redirect_with_message( 'روش‌های ارسال موبو از MoboCore بروزرسانی شدند.', 'success', 'checkout' );
				break;

			case 'mobo_core_tool_install_automatic_shipping':
				if ( ! class_exists( 'Mobo_Core_Automatic_Shipping' ) || ! class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ) {
					$this->redirect_with_message( 'ماژول نگاشت روش‌های ارسال در دسترس نیست.', 'error', 'checkout' );
				}
				$cleanup = ( new Mobo_Core_Automatic_Shipping() )->retire_legacy_runtime( 'admin-mapping-repair' );
				if ( empty( $cleanup['success'] ) ) {
					$failed_count = isset( $cleanup['failures'] ) && is_array( $cleanup['failures'] ) ? count( $cleanup['failures'] ) : 0;
					$this->redirect_with_message( sprintf( 'پاکسازی روش‌های ارسال قدیمی موبو کامل نشد (%d خطا). هیچ وضعیت موفقی ثبت نشد و اجرای بعدی دوباره تلاش می‌کند.', absint( $failed_count ) ), 'error', 'checkout' );
				}
				$sync = ( new Mobo_Core_Remote_Shipping_Methods() )->sync_now( 'admin-mapping-repair', true );
				if ( empty( $sync['success'] ) ) {
					$message = isset( $sync['message'] ) ? $sync['message'] : 'بروزرسانی روش‌های ارسال موبو ناموفق بود.';
					$this->redirect_with_message( 'ترمیم نگاشت ناموفق بود: ' . $message, 'error', 'checkout' );
				}
				$message = sprintf(
					'نگاشت آماده شد و روش‌های موبو بروزرسانی شدند. پاکسازی نسخه‌های قدیمی: %1$d روش موبو، %2$d Flat Rate پشتیبان، %3$d کپی روش فروشگاه غیرفعال و %4$d Zone خالی قدیمی موبو حذف شد.',
					absint( isset( $cleanup['disabledMoboMethods'] ) ? $cleanup['disabledMoboMethods'] : 0 ),
					absint( isset( $cleanup['disabledFallbackMethods'] ) ? $cleanup['disabledFallbackMethods'] : 0 ),
					absint( isset( $cleanup['disabledMirrorMethods'] ) ? $cleanup['disabledMirrorMethods'] : 0 ),
					absint( isset( $cleanup['deletedLegacyZones'] ) ? $cleanup['deletedLegacyZones'] : 0 )
				);
				$this->redirect_with_message( $message, 'success', 'checkout' );
				break;

			case 'mobo_core_tool_test_mobo_login':
				if ( ! class_exists( 'Mobo_Core_Checkout_Validator' ) ) {
					$this->redirect_with_message( 'کلاس اعتبارسنجی خرید در دسترس نیست.', 'error', 'checkout' );
				}

				$validator = new Mobo_Core_Checkout_Validator();
				$result    = $validator->test_mobo_login();

				if ( is_wp_error( $result ) ) {
					$this->redirect_with_message( 'تست ورود ناموفق بود: ' . $result->get_error_message(), 'error', 'checkout' );
				}

				$this->redirect_with_message( 'تست ورود به موبو موفق بود.', 'success', 'checkout' );
				break;

			case 'mobo_core_tool_run_cron_now':
				if ( ! class_exists( 'Mobo_Core_Cron_Runner' ) ) {
					$this->redirect_with_message( 'کلاس Cron Runner در دسترس نیست.', 'error', 'cron' );
				}

				$result = $this->run_cron_under_dispatcher( 'admin-manual-cron-test' );
				$webhook_processed = 0;
				$webhook_failed    = 0;
				$result_status     = is_array( $result ) && isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : 'unknown';
				$runner            = is_array( $result ) && isset( $result['runner'] ) && is_array( $result['runner'] ) ? $result['runner'] : array();
				$rounds            = absint( isset( $runner['rounds'] ) ? $runner['rounds'] : 0 );
				$failed_stages     = isset( $runner['failedStages'] ) && is_array( $runner['failedStages'] ) ? array_values( array_filter( array_map( 'sanitize_key', $runner['failedStages'] ) ) ) : array();

				if ( is_array( $result ) && isset( $result['webhookQueue'] ) && is_array( $result['webhookQueue'] ) ) {
					$webhook_processed = isset( $result['webhookQueue']['processed'] ) ? absint( $result['webhookQueue']['processed'] ) : 0;
					$webhook_failed    = isset( $result['webhookQueue']['failed'] ) ? absint( $result['webhookQueue']['failed'] ) : 0;
				}

				$message = sprintf(
					'یک Slice واقعی Worker به‌صورت دستی اجرا شد. وضعیت: %1$s، دورها: %2$d، وب‌هوک پردازش‌شده: %3$d، خطا: %4$d%5$s. این اجرای دستی سالم بودن Cron Job هاست را اثبات نمی‌کند.',
					$result_status,
					$rounds,
					$webhook_processed,
					$webhook_failed,
					! empty( $failed_stages ) ? '، stageهای ناموفق: ' . implode( ', ', $failed_stages ) : ''
				);
				$notice_type = 'ok' === $result_status ? 'success' : ( 'partial' === $result_status ? 'warning' : 'error' );
				$this->redirect_with_message( $message, $notice_type, 'cron' );
				break;

			default:
				$this->redirect_with_message( 'عملیات پشتیبانی شناخته نشد.', 'error', $tab );
				break;
		}
	}

	/**
	 * Run an administrator-requested cron slice under the same site dispatcher used
	 * by REST, heartbeat and self-runner requests. Duplicate requests fail fast.
	 *
	 * @param string $source Runner source.
	 * @return array
	 */
	private function run_cron_under_dispatcher( $source ) {
		$claim = class_exists( 'Mobo_Core_Self_Runner' )
			? Mobo_Core_Self_Runner::claim_worker_request( '' )
			: array( 'success' => true, 'token' => '' );
		if ( empty( $claim['success'] ) ) {
			$result = array(
				'success'    => false,
				'status'     => isset( $claim['status'] ) ? sanitize_key( (string) $claim['status'] ) : 'dispatcher-locked',
				'httpStatus' => isset( $claim['httpStatus'] ) ? absint( $claim['httpStatus'] ) : 423,
				'message'    => 'یک Worker دیگر موبو در این سایت در حال اجرا است.',
				'source'     => sanitize_key( (string) $source ),
				'executedAt' => time(),
				'runner'     => array(
					'pluginVersion'  => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
					'stopReason'     => 'dispatcher-locked',
					'madeProgress'   => false,
					'contendedStages'=> array( 'workerDispatcher' ),
				),
			);
			/* A rejected manual attempt is diagnostic state only. It must never move the
			 * real-scheduler hit clock, otherwise the Cron timing metric can report a
			 * manual/admin request as an on-time Real Cron execution. */
			update_option( 'mobo_core_real_cron_last_result', $result, false );
			return $result;
		}

		$token  = isset( $claim['token'] ) ? (string) $claim['token'] : '';
		$runner = new Mobo_Core_Cron_Runner();
		try {
			$result = $runner->run( sanitize_key( (string) $source ), false, array( 'suppressContinuationKick' => true, 'dispatcherToken' => $token ) );
		} finally {
			if ( '' !== $token && class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::release_worker_request( $token );
			}
		}

		if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
			$pending = Mobo_Core_Self_Runner::consume_pending_dispatch();
			if ( $pending || Mobo_Core_Self_Runner::should_continue_after_result( $result ) ) {
				$result['continuationKick'] = Mobo_Core_Self_Runner::kick( 'admin-cron-continuation', true );
			}
		}

		return $result;
	}

	/**
	 * Explicitly re-arm the one-shot Mobo wallet reminder.
	 *
	 * @return void
	 */
	public function handle_rearm_wallet_alert() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_rearm_wallet_alert' );

		if ( ! class_exists( 'Mobo_Core_Wallet_Alert' ) ) {
			$this->redirect_with_message( 'سرویس یادآوری موجودی موبو در دسترس نیست.', 'error', 'dashboard' );
		}

		$rearmed = Mobo_Core_Wallet_Alert::rearm();
		$return_tab = isset( $_POST['mobo_return_tab'] ) ? sanitize_key( wp_unslash( $_POST['mobo_return_tab'] ) ) : 'dashboard';
		if ( ! in_array( $return_tab, array( 'dashboard', 'sms' ), true ) ) {
			$return_tab = 'dashboard';
		}
		if ( ! $rearmed ) {
			$this->redirect_with_message( 'فعال‌سازی مجدد قابل تأیید نبود؛ برای جلوگیری از ارسال تکراری، وضعیت قبلی را معتبر در نظر بگیرید و storage/Connection را بررسی کنید.', 'error', $return_tab );
		}
		$this->redirect_with_message( 'یادآوری موجودی موبو برای Connection انتخاب‌شده دوباره فعال شد. در خرید موفق بعدی موجودی بررسی می‌شود.', 'success', $return_tab );
	}


	/**
	 * Send an administrator-triggered wallet SMS transport test.
	 *
	 * @return void
	 */
	public function handle_test_wallet_sms() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_test_wallet_sms' );

		if ( ! class_exists( 'Mobo_Core_Wallet_Alert' ) ) {
			$this->redirect_with_message( 'سرویس هشدار کیف پول موبو در دسترس نیست.', 'error', 'sms' );
		}

		$wallet = new Mobo_Core_Wallet_Alert();
		$result = $wallet->send_test_sms();
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_message( 'SMS آزمایشی ارسال نشد: ' . $result->get_error_message(), 'error', 'sms' );
		}

		$this->redirect_with_message( 'API درگاه درخواست SMS آزمایشی را پذیرفت. این نتیجه تحویل نهایی پیام به گوشی را اثبات نمی‌کند.', 'success', 'sms' );
	}


	/**
	 * Create one Connection from a registered Source definition.
	 * Source URLs are code-owned and are never accepted from the browser.
	 *
	 * @return void
	 */
	public function handle_create_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}
		check_admin_referer( 'mobo_core_create_connection', 'mobo_core_nonce' );
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			$this->redirect_with_message( 'زیرساخت چنداتصالی در دسترس نیست.', 'error', 'connection' );
		}
		/* Existing extra Connections keep working, but a NEW one is refused until the
		 * Default Connection's one-way storage retirement has finished. */
		if ( class_exists( 'Mobo_Core_Migration' ) && Mobo_Core_Migration::new_connections_blocked() ) {
			$this->redirect_with_message( 'افزودن اتصال جدید تا پایان انتقال داده‌های فروشگاه به ساختار ذخیره‌سازی جدید ممکن نیست. این فرایند خودکار در پس‌زمینه ادامه دارد؛ پس از اتمام دوباره تلاش کنید.', 'error', 'connection' );
		}
		$token = isset( $_POST['mobo_new_connection_token'] ) ? Mobo_Core_Settings::normalize_opaque_secret( wp_unslash( $_POST['mobo_new_connection_token'] ), 191 ) : '';
		$result = Mobo_Core_Connection_Repository::create_pending_connection( $token, '' );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_message( 'اتصال ساخته نشد: ' . $result->get_error_message(), 'error', 'connection' );
		}
		$connection_id = absint( $result );
		if ( $connection_id > 0 && class_exists( 'Mobo_Core_Connection_Context' ) ) {
			Mobo_Core_Connection_Context::set_current_connection_id( $connection_id );
		}
		$this->redirect_with_message( 'توکن ذخیره شد. اتصال در انتظار آماده‌سازی از سرویس مرکزی است.', 'success', 'connection' );
	}

	/**
	 * Cancel an unprovisioned pending Connection.
	 *
	 * @return void
	 */
	public function handle_cancel_pending_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}
		check_admin_referer( 'mobo_core_cancel_pending_connection', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			$this->redirect_with_message( 'زیرساخت اتصال در دسترس نیست.', 'error', 'connection' );
		}

		$connection_id = isset( $_POST['connection_id'] ) ? absint( wp_unslash( $_POST['connection_id'] ) ) : 0;
		$result = Mobo_Core_Connection_Repository::delete_pending_connection( $connection_id );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_message( 'اتصال در انتظار حذف نشد: ' . $result->get_error_message(), 'error', 'connection' );
		}

		$default = method_exists( 'Mobo_Core_Connection_Repository', 'get_default_any' )
			? Mobo_Core_Connection_Repository::get_default_any()
			: Mobo_Core_Connection_Repository::get_default();
		$default_id = is_array( $default ) ? absint( $default['id'] ?? 0 ) : 0;

		$args = array(
			'page'         => self::MENU_SLUG,
			'tab'          => 'connection',
			'mobo_message' => rawurlencode( 'اتصال در انتظار و توکن ذخیره‌شده آن حذف شد.' ),
			'mobo_type'    => 'success',
		);
		if ( $default_id > 0 ) {
			$args['mobo_connection_id'] = $default_id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle save settings.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_save_settings', 'mobo_core_nonce' );

		$tab = isset( $_POST['mobo_active_tab'] ) ? sanitize_key( wp_unslash( $_POST['mobo_active_tab'] ) ) : 'dashboard';
		$allowed_tabs = array( 'connection', 'product', 'categories', 'pricing', 'filters', 'queue', 'image-refresh', 'cron', 'checkout', 'sms', 'health' );
		if ( ! in_array( $tab, $allowed_tabs, true ) ) {
			$tab = 'dashboard';
		}

		if ( 'pricing' === $tab && class_exists( 'Mobo_Core_Reprice_Queue' ) ) {
			$reprice_status = ( new Mobo_Core_Reprice_Queue() )->get_status();
			if ( isset( $reprice_status['status'] ) && 'running' === (string) $reprice_status['status'] ) {
				$this->redirect_with_message(
					'سیاست قیمت در زمان اجرای Reprice قفل است و هیچ تغییری ذخیره نشد. ابتدا عملیات را کامل یا متوقف کنید.',
					'warning',
					'pricing'
				);
			}
		}

		if ( 'pricing' === $tab ) {
			$pricing_errors = $this->validate_pricing_post();
			if ( ! empty( $pricing_errors ) ) {
				$this->redirect_with_message(
					'سیاست قیمت ذخیره نشد: ' . implode( ' | ', $pricing_errors ),
					'error',
					'pricing'
				);
			}
		}

		if ( 'queue' === $tab && class_exists( 'Mobo_Core_Sync_Settings_Guard' ) ) {
			$queue_settings_lock = Mobo_Core_Sync_Settings_Guard::get_lock_state();
			if ( ! empty( $queue_settings_lock['locked'] ) ) {
				$operation_label = isset( $queue_settings_lock['operationLabel'] )
					? sanitize_text_field( (string) $queue_settings_lock['operationLabel'] )
					: 'همگام سازی محصولات';

				$this->redirect_with_message(
					'تنظیمات صف و پردازش در زمان اجرای ' . $operation_label . ' قفل است و هیچ تغییری ذخیره نشد. پس از پایان عملیات دوباره تلاش کنید.',
					'warning',
					'queue'
				);
			}
		}


		if ( 'queue' === $tab ) {
			$queue_save = $this->save_queue_settings_from_post();
			if ( is_wp_error( $queue_save ) ) {
				$this->redirect_with_message( $queue_save->get_error_message(), 'error', 'queue' );
			}
			$warnings = isset( $queue_save['warnings'] ) && is_array( $queue_save['warnings'] ) ? $queue_save['warnings'] : array();
			if ( ! empty( $warnings ) ) {
				$this->redirect_with_message( 'تنظیمات ظرفیت ذخیره و read-back شدند. نکته: ' . implode( ' | ', array_map( 'sanitize_text_field', $warnings ) ), 'warning', 'queue' );
			}
			$this->redirect_with_message( 'تنظیمات ظرفیت و صف ذخیره و read-back شدند.', 'success', 'queue' );
		}

		if ( 'connection' === $tab && isset( $_POST['mobo_core_security_code'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- security codes must preserve printable ASCII exactly; normalize_security_code() + is_valid_security_code() enforce the credential format.
			$posted_security_code = Mobo_Core_Settings::normalize_security_code( wp_unslash( $_POST['mobo_core_security_code'] ) );

			if ( '' !== $posted_security_code && ! Mobo_Core_Settings::is_valid_security_code( $posted_security_code ) ) {
				$this->redirect_with_message(
					'کد امنیتی وب‌هوک ذخیره نشد. ' . Mobo_Core_Settings::get_security_code_validation_error( $posted_security_code ) . ' مقدار قبلی بدون تغییر باقی ماند.',
					'error',
					'connection'
				);
			}
		}

		if ( 'filters' === $tab ) {
			$filter_save = $this->save_filters_tab_from_post();
			if ( is_wp_error( $filter_save ) ) {
				$this->redirect_with_message( $filter_save->get_error_message(), 'error', 'filters' );
			}

			$active_count = isset( $filter_save['paths'] ) && is_array( $filter_save['paths'] ) ? count( $filter_save['paths'] ) : 0;
			$duplicate_count = isset( $filter_save['duplicateCount'] ) ? absint( $filter_save['duplicateCount'] ) : 0;
			$message = 0 === $active_count
				? 'همه استثناهای همگام‌سازی حذف شدند؛ محصولات برای کارهای بعدی منبع دوباره مجاز هستند.'
				: sprintf( '%d استثنای یکتا ذخیره شد.', $active_count );
			if ( $duplicate_count > 0 ) {
				$message .= sprintf( ' %d مورد تکراری نیز هنگام ذخیره حذف شد.', $duplicate_count );
			}
			$message .= ' حذف استثنا، تغییرات از دست‌رفته قبلی را خودکار جبران نمی‌کند.';
			$this->redirect_with_message( $message, 'success', 'filters' );
		}


		if ( 'sms' === $tab ) {
			$sms_save = $this->save_sms_notification_settings_from_post();
			if ( is_wp_error( $sms_save ) ) {
				$this->redirect_with_message( $sms_save->get_error_message(), 'error', 'sms' );
			}

			$warnings = isset( $sms_save['warnings'] ) && is_array( $sms_save['warnings'] ) ? $sms_save['warnings'] : array();
			if ( ! empty( $warnings ) ) {
				$this->redirect_with_message( 'تنظیمات ذخیره شد، اما این موارد هنوز نیازمند تکمیل هستند: ' . implode( ' | ', array_map( 'sanitize_text_field', $warnings ) ), 'warning', 'sms' );
			}

			$this->redirect_with_message( 'تنظیمات پیامک و هشدار موجودی ذخیره و read-back شدند.', 'success', 'sms' );
		}

        // C75B-P4G-P2: reject invalid target currency before any settings mutation.
        // This is only a staged preference: the V1 strict money guard remains active.
        $money_target_posted = null;
        if ( 'connection' === $tab && array_key_exists( 'mobo_core_money_target_preference', $_POST ) ) {
            $money_raw_preference = wp_unslash( $_POST['mobo_core_money_target_preference'] );
            if ( ! class_exists( 'Mobo_Core_Money_Target_Settings' )
                || ! Mobo_Core_Money_Target_Settings::valid_preference( $money_raw_preference ) ) {
                $this->redirect_with_message( 'ارز مقصد انتخاب‌شده معتبر نیست؛ هیچ تنظیمی ذخیره نشد.', 'error', 'connection' );
            }
            $money_target_posted = $money_raw_preference;
        }

		$was_order_submission_enabled = Mobo_Core_Order_Submission_Policy::is_enabled();

		$this->save_current_tab_settings( $tab );

		/* Support access: one Source login check when the Source credentials changed
		 * on save (never on unrelated saves, to avoid locking the Source account). */
		if ( 'checkout' === $tab && class_exists( 'Mobo_Core_Support_Access' ) ) {
			try {
				Mobo_Core_Support_Access::check_after_settings_save();
			} catch ( Throwable $support_error ) {
				unset( $support_error );
			}
		}

        if ( null !== $money_target_posted ) {
            // Global store-owned option; never write Backend-signed Connection fields.
            Mobo_Core_Settings::update( Mobo_Core_Money_Target_Settings::OPTION, $money_target_posted, false );
            if ( (string) get_option( Mobo_Core_Money_Target_Settings::OPTION, '' ) !== $money_target_posted ) {
                $this->redirect_with_message( 'ذخیره ارز مقصد تأیید نشد؛ قیمت‌ها تغییر نکردند.', 'error', 'connection' );
            }
        }

		if ( isset( $_POST['mobo_core_clear_mobo_debug_log'] ) && 'checkout' === $tab ) {
			if ( class_exists( 'Mobo_Core_Checkout_Validator' ) ) {
				$validator = new Mobo_Core_Checkout_Validator();
				if ( method_exists( $validator, 'clear_mobo_debug_log' ) ) {
					$validator->clear_mobo_debug_log();
				}
			}

			$this->redirect_with_message( 'لاگ دیباگ سبد موبو پاک شد.', 'success', 'checkout' );
		}

		if ( isset( $_POST['mobo_core_clear_shipping_diagnostics'] ) && 'checkout' === $tab ) {
			if ( class_exists( 'Mobo_Core_Shipping_Diagnostics' ) ) {
				$shipping_diagnostics = new Mobo_Core_Shipping_Diagnostics();
				if ( method_exists( $shipping_diagnostics, 'clear' ) ) {
					$shipping_diagnostics->clear();
				}
			}

			$this->redirect_with_message( 'گزارش دیباگ حمل و نقل پاک شد.', 'success', 'checkout' );
		}

		if ( isset( $_POST['mobo_core_sync_address_mapping'] ) && 'checkout' === $tab ) {
			if ( ! class_exists( 'Mobo_Core_Address_Mapping' ) ) {
				$this->redirect_with_message( 'کلاس بروزرسانی آدرس‌ها در دسترس نیست.', 'error', 'checkout' );
			}

			$address_mapping = new Mobo_Core_Address_Mapping();
			$result = $address_mapping->sync_now( 'manual', true );

			if ( empty( $result['success'] ) ) {
				$message = isset( $result['message'] ) ? $result['message'] : 'بروزرسانی آدرس‌ها ناموفق بود.';
				$this->redirect_with_message( 'بروزرسانی آدرس‌ها ناموفق بود: ' . $message, 'error', 'checkout' );
			}

			$this->redirect_with_message( 'کشور، استان و شهرها از MoboCore بروزرسانی و فایل‌های شهر Checkout ساخته شدند.', 'success', 'checkout' );
		}

		if ( isset( $_POST['mobo_core_sync_remote_shipping_methods'] ) && 'checkout' === $tab ) {
			if ( ! class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ) {
				$this->redirect_with_message( 'کلاس بروزرسانی روش‌های ارسال موبو در دسترس نیست.', 'error', 'checkout' );
			}

			$remote_shipping = new Mobo_Core_Remote_Shipping_Methods();
			$result = $remote_shipping->sync_now( 'manual', true );

			if ( empty( $result['success'] ) ) {
				$message = isset( $result['message'] ) ? $result['message'] : 'بروزرسانی روش‌های ارسال موبو ناموفق بود.';
				$this->redirect_with_message( 'بروزرسانی روش‌های ارسال موبو ناموفق بود: ' . $message, 'error', 'checkout' );
			}

			$this->redirect_with_message( 'روش‌های ارسال موبو از MoboCore بروزرسانی شدند.', 'success', 'checkout' );
		}

		if ( isset( $_POST['mobo_core_test_mobo_login'] ) && 'checkout' === $tab ) {
			if ( ! class_exists( 'Mobo_Core_Checkout_Validator' ) ) {
				$this->redirect_with_message( 'کلاس اعتبارسنجی خرید در دسترس نیست.', 'error', 'checkout' );
			}

			$validator = new Mobo_Core_Checkout_Validator();
			$result    = $validator->test_mobo_login();

			if ( is_wp_error( $result ) ) {
				$this->redirect_with_message( 'تست ورود ناموفق بود: ' . $result->get_error_message(), 'error', 'checkout' );
			}

			$this->redirect_with_message( 'تست ورود به موبو موفق بود.', 'success', 'checkout' );
		}

		if ( isset( $_POST['mobo_core_run_cron_now'] ) && 'cron' === $tab ) {
			if ( ! class_exists( 'Mobo_Core_Cron_Runner' ) ) {
				$this->redirect_with_message( 'کلاس Cron Runner در دسترس نیست.', 'error', 'cron' );
			}

			$result = $this->run_cron_under_dispatcher( 'admin-manual-cron-test' );
			$webhook_processed = 0;
			$webhook_failed    = 0;
			$result_status     = is_array( $result ) && isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : 'unknown';
			$runner            = is_array( $result ) && isset( $result['runner'] ) && is_array( $result['runner'] ) ? $result['runner'] : array();
			$rounds            = absint( isset( $runner['rounds'] ) ? $runner['rounds'] : 0 );
			$failed_stages     = isset( $runner['failedStages'] ) && is_array( $runner['failedStages'] ) ? array_values( array_filter( array_map( 'sanitize_key', $runner['failedStages'] ) ) ) : array();

			if ( is_array( $result ) && isset( $result['webhookQueue'] ) && is_array( $result['webhookQueue'] ) ) {
				$webhook_processed = isset( $result['webhookQueue']['processed'] ) ? absint( $result['webhookQueue']['processed'] ) : 0;
				$webhook_failed    = isset( $result['webhookQueue']['failed'] ) ? absint( $result['webhookQueue']['failed'] ) : 0;
			}

			$message = sprintf(
				'یک Slice واقعی Worker به‌صورت دستی اجرا شد. وضعیت: %1$s، دورها: %2$d، وب‌هوک پردازش‌شده: %3$d، خطا: %4$d%5$s. این اجرای دستی سالم بودن Cron Job هاست را اثبات نمی‌کند.',
				$result_status,
				$rounds,
				$webhook_processed,
				$webhook_failed,
				! empty( $failed_stages ) ? '، stageهای ناموفق: ' . implode( ', ', $failed_stages ) : ''
			);
			$notice_type = 'ok' === $result_status ? 'success' : ( 'partial' === $result_status ? 'warning' : 'error' );
			$this->redirect_with_message( $message, $notice_type, 'cron' );
		}

		if ( 'checkout' === $tab && Mobo_Core_Order_Submission_Policy::is_enabled() ) {
			if ( Mobo_Core_Order_Submission_Policy::is_enabled() && class_exists( 'Mobo_Core_Address_Mapping' ) ) {
				$address_mapping = new Mobo_Core_Address_Mapping();
				$address_status  = method_exists( $address_mapping, 'get_status' ) ? $address_mapping->get_status() : array();
				$counts = isset( $address_status['counts'] ) && is_array( $address_status['counts'] ) ? $address_status['counts'] : array();
				if ( ! $was_order_submission_enabled || empty( $counts['countries'] ) || empty( $counts['states'] ) || empty( $counts['cities'] ) ) {
					$address_mapping->sync_now( 'auto-order-enabled', true );
				}
			}

			if ( class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ) {
				$remote_shipping = new Mobo_Core_Remote_Shipping_Methods();
				$status = method_exists( $remote_shipping, 'get_status' ) ? $remote_shipping->get_status() : array();
				if ( ! $was_order_submission_enabled || empty( $status['count'] ) ) {
					$remote_shipping->sync_now( 'auto-order-enabled', true );
				}
			}

			if ( Mobo_Core_Order_Submission_Policy::is_enabled() ) {
				$required_validation = $this->validate_mobo_order_submission_required_config();
				if ( is_wp_error( $required_validation ) ) {
					/* Fail closed. The previous implementation reported an error only after the
					 * invalid enable had already been persisted, leaving runtime order submission
					 * active with incomplete credentials/mapping. Keep the rest of the submitted
					 * configuration so the administrator can fix it, but disable the side effect. */
					Mobo_Core_Settings::update( 'mobo_core_mobo_order_submission_enabled', '0', false );
					Mobo_Core_Settings::update( 'mobo_core_mobo_order_auto_complete_enabled', '0', false );
					$this->redirect_with_message( 'تنظیمات ذخیره شد، اما ثبت خودکار سفارش برای جلوگیری از ارسال ناقص غیرفعال شد: ' . $required_validation->get_error_message(), 'error', 'checkout' );
				}
			}
		}

		if ( 'categories' === $tab && isset( $_POST['mobo_category_map'] ) ) {
			$this->redirect_with_message( 'نگاشت‌های دسته‌بندی ذخیره شدند. محصولات جدید و همگام‌سازی‌های بعدی از این تصمیم استفاده می‌کنند؛ برای محصولات موجود در صورت نیاز «اعمال روی محصولات موجود» را اجرا کنید.', 'success', 'categories' );
		}

		/* If automatic Mobo order submission was just re-enabled, immediately wake the
		 * existing Self Runner. Orders deferred while the switch was off remain in the
		 * durable queue and should resume without waiting for the next external cron. */
		if ( 'checkout' === $tab
			&& ! $was_order_submission_enabled
			&& Mobo_Core_Order_Submission_Policy::is_enabled()
			&& class_exists( 'Mobo_Core_Self_Runner' )
		) {
			Mobo_Core_Self_Runner::kick( 'auto-order-re-enabled', true );
		}

		$this->redirect_with_message( 'تنظیمات همین تب ذخیره شد.', 'success', $tab );
	}

	/**
	 * Return current sync status for admin auto-refresh.
	 *
	 * @return void
	 */
	public function handle_ajax_sync_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
		}

		check_ajax_referer( 'mobo_core_sync_status', 'nonce' );

		$product_sync = new Mobo_Core_Product_Sync();
		$status       = $product_sync->get_manual_sync_status();

		if ( ! empty( $status['isWaitingForPortal'] ) && ! empty( $status['isRetryDue'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'admin-waiting-for-portal-resume', true );
		}

		/*
		 * The admin page polls this endpoint every few seconds. If the non-blocking
		 * self-runner/loopback request is blocked by hosting, SSL, firewall, or REST
		 * routing, the visible sync status can stay on the last successful message
		 * such as "UpdateVariant processed." without showing an error.
		 *
		 * As a safe fallback, when a running sync has made no progress for a short
		 * period, the authenticated admin poll advances a small time-bounded burst
		 * of canonical manual-sync steps. This keeps the UI moving without creating
		 * parallel workers or bypassing any normal lock/checkpoint boundary.
		 */
		$admin_fallback = array(
			'attempted'  => false,
			'steps'      => 0,
			'stopReason' => 'not-needed',
		);

		if ( ! empty( $status['shouldContinue'] ) ) {
			$updated_at = isset( $status['updatedAt'] ) ? absint( $status['updatedAt'] ) : 0;
			/*
			 * The admin page polls every three seconds. Waiting eight seconds before
			 * advancing one fallback step made Repair effectively unusable on hosts
			 * where loopback/Self Runner is unavailable: a product needs several
			 * durable steps (page fetch, parent, image desired-state, variants, final).
			 *
			 * Treat one missed poll as degraded runner health, then execute a small
			 * cooperative catch-up burst. Every iteration still enters
			 * run_manual_sync_step(), so the canonical manual_sync lease, product
			 * locks, ordering fences and durable checkpoints remain authoritative.
			 */
			$stale_after_seconds = ! empty( $status['repairMode'] ) ? 3 : 5;
			$is_stale            = $updated_at > 0 && ( time() - $updated_at ) >= $stale_after_seconds;

			if ( $is_stale ) {
				$admin_fallback['attempted'] = true;
				$admin_fallback['stopReason'] = 'step-limit';

				/* The browser fallback is a real worker too. Claim the same site-wide
				 * dispatcher used by REST, heartbeat, Self Runner and Real Cron before
				 * entering manual_sync. This prevents the admin poll from racing cron. */
				$dispatcher_claim = class_exists( 'Mobo_Core_Self_Runner' )
					? Mobo_Core_Self_Runner::claim_worker_request( '' )
					: array( 'success' => true, 'token' => '' );
				if ( empty( $dispatcher_claim['success'] ) ) {
					$admin_fallback['stopReason'] = 'dispatcher-locked';
				} else {
					$dispatcher_token = isset( $dispatcher_claim['token'] ) ? (string) $dispatcher_claim['token'] : '';
					$max_steps = ! empty( $status['repairMode'] ) ? 4 : 3;
					$deadline  = microtime( true ) + ( ! empty( $status['repairMode'] ) ? 2.25 : 1.50 );

					try {
					while ( $admin_fallback['steps'] < $max_steps && microtime( true ) < $deadline ) {
					/* Foreground Webhook freshness always wins over Repair catch-up. */
					if ( class_exists( 'Mobo_Core_Webhook_Queue' ) ) {
						$priority_queue = new Mobo_Core_Webhook_Queue();
						if ( method_exists( $priority_queue, 'has_priority_work_due_now' ) && $priority_queue->has_priority_work_due_now() ) {
							$admin_fallback['stopReason'] = 'webhook-pressure';
							break;
						}
					}

					$before = $this->manual_sync_progress_fingerprint( $status );
					$step   = $product_sync->run_manual_sync_step();
					$admin_fallback['steps']++;
					$status = $product_sync->get_manual_sync_status();
					if ( class_exists( 'Mobo_Core_Job_Diagnostics' ) ) {
						Mobo_Core_Job_Diagnostics::observe_manual_sync( $status );
					}

					if ( empty( $step['success'] ) || ! empty( $status['lastError'] ) ) {
						$admin_fallback['stopReason'] = 'error';
						break;
					}

					if ( ! empty( $step['data']['locked'] ) ) {
						$admin_fallback['stopReason'] = 'worker-locked';
						break;
					}

					if ( empty( $status['shouldContinue'] ) ) {
						$admin_fallback['stopReason'] = ! empty( $status['isDone'] ) ? 'done' : 'not-runnable';
						break;
					}

					if ( $before === $this->manual_sync_progress_fingerprint( $status ) ) {
						/* A successful step that made no observable cursor/state progress must
						 * not be hot-looped by the browser fallback. */
						$admin_fallback['stopReason'] = 'no-progress';
						break;
					}

					if ( microtime( true ) >= $deadline ) {
						$admin_fallback['stopReason'] = 'time-budget';
						break;
					}
					}
					} finally {
						if ( '' !== $dispatcher_token && class_exists( 'Mobo_Core_Self_Runner' ) ) {
							Mobo_Core_Self_Runner::release_worker_request( $dispatcher_token );
						}
					}
				}
			} elseif ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::kick( 'admin-status-continue', false );
			}
		}

		$status['adminFallback'] = $admin_fallback;

		/*
		 * Product sync no longer blocks on images, otherwise one slow/bad source image
		 * can freeze the whole import. That means product rows may appear before their
		 * images are attached. While the admin sync page is open, process a small,
		 * bounded image-queue slice on every status poll so pending images continue
		 * moving even on hosts where real cron/loopback is unreliable.
		 */
		if ( class_exists( 'Mobo_Core_Image_Sync' ) ) {
			$image_sync          = new Mobo_Core_Image_Sync();
			$image_queue_before  = $image_sync->get_queue_status();
			$image_poll_limit    = max( 3, Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 3, 1, 10 ) );
			$image_queue_result  = array( 'processed' => 0, 'failed' => 0, 'status' => 'skipped' );

			/* If this admin request had to rescue a stalled Sync/Repair, spend the
			 * request budget on authoritative product progress. Image desired-state is
			 * already durable in its queue and can be drained by the Self/Real Cron
			 * worker or a later idle poll. This avoids slow image I/O immediately after
			 * the catch-up burst making Repair appear stalled again. */
			$repair_catchup_active = ! empty( $status['repairMode'] ) && ! empty( $admin_fallback['steps'] );

			if ( ! $repair_catchup_active && ! empty( $image_queue_before['due'] ) ) {
				$image_queue_result = $image_sync->process_queue( $image_poll_limit );
			} elseif ( $repair_catchup_active ) {
				$image_queue_result['status'] = 'deferred-for-repair-catchup';
			}

			$status['imageQueue'] = array(
				'before'   => $image_queue_before,
				'after'    => $image_sync->get_queue_status(),
				'lastRun'  => $image_queue_result,
				'pollLimit'=> $image_poll_limit,
			);
		}

		$status['statusLabel']          = $this->sync_status_label_for_display( $status );
		$status['progressPercentLabel'] = $this->format_percent( $status['progressPercent'] );
		if ( class_exists( 'Mobo_Core_Job_Diagnostics' ) ) {
			$status += Mobo_Core_Job_Diagnostics::manual_sync_extras( $status );
		}
		$status['nextRetryAtLabel']     = ! empty( $status['nextRetryAt'] ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $status['nextRetryAt'] ) ) : '—';
		$status['serverTime']           = Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s' );

		wp_send_json_success( $status );
	}

	/**
	 * Build a compact observable progress fingerprint for browser-side Sync fallback.
	 *
	 * This intentionally contains only durable/cursor fields. Messages and wall-clock
	 * timestamps are excluded so a repeated no-op step cannot masquerade as progress.
	 *
	 * @param array $status Manual sync status.
	 * @return string
	 */
	private function manual_sync_progress_fingerprint( $status ) {
		$status = is_array( $status ) ? $status : array();

		$progress = array(
			'status'                     => sanitize_key( (string) ( $status['status'] ?? '' ) ),
			'repairIntegrityPhase'       => sanitize_key( (string) ( $status['repairIntegrityPhase'] ?? '' ) ),
			'repairIntegrityCursor'      => absint( $status['repairIntegrityCursor'] ?? 0 ),
			'repairIntegrityComplete'    => ! empty( $status['repairIntegrityComplete'] ),
			'categorySynced'             => ! empty( $status['categorySynced'] ),
			'categorySnapshotRetryCount'=> absint( $status['categorySnapshotRetryCount'] ?? 0 ),
			'categorySyncDegraded'        => ! empty( $status['categorySyncDegraded'] ),
			'categorySyncSkippedRows'     => absint( $status['categorySyncSkippedRows'] ?? 0 ),
			'repairLivenessLockKey'       => sanitize_text_field( (string) ( $status['repairLivenessLockKey'] ?? '' ) ),
			'repairLivenessLockCount'     => absint( $status['repairLivenessLockCount'] ?? 0 ),
			'repairProductLockQuarantined'=> absint( $status['repairProductLockQuarantined'] ?? 0 ),
			'productPage'                => absint( $status['productPage'] ?? 0 ),
			'productCursor'              => absint( $status['productCursor'] ?? 0 ),
			'queuedProducts'             => absint( $status['queuedProducts'] ?? 0 ),
			'currentProductGuid'         => sanitize_text_field( (string) ( $status['currentProductGuid'] ?? '' ) ),
			'currentProductImageOffset'  => absint( $status['currentProductImageOffset'] ?? 0 ),
			'currentProductImagesDone'   => ! empty( $status['currentProductImagesDone'] ),
			'variantPage'                => absint( $status['variantPage'] ?? 0 ),
			'currentVariantCursor'       => absint( $status['currentVariantCursor'] ?? 0 ),
			'currentVariantProcessedPages'=> absint( $status['currentVariantProcessedPages'] ?? 0 ),
			'processedProducts'          => absint( $status['processedProducts'] ?? 0 ),
			'postSyncIntegrityPhase'     => sanitize_key( (string) ( $status['postSyncIntegrityPhase'] ?? '' ) ),
			'postSyncIntegrityCursor'    => absint( $status['postSyncIntegrityCursor'] ?? 0 ),
			'postSyncIntegrityComplete'  => ! empty( $status['postSyncIntegrityComplete'] ),
			'missingImageRecoveryCursor'=> absint( $status['missingImageRecoveryCursor'] ?? 0 ),
			'missingImageRecoveryScanned'=> absint( $status['missingImageRecoveryScanned'] ?? 0 ),
			'missingImageRecoveryComplete'=> ! empty( $status['missingImageRecoveryComplete'] ),
		);

		return md5( wp_json_encode( $progress ) );
	}


	/**
	 * Return current repricing status for AJAX polling on the pricing tab.
	 *
	 * @return void
	 */
	public function handle_ajax_reprice_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
		}

		check_ajax_referer( 'mobo_core_reprice_status', 'nonce' );

		if ( ! class_exists( 'Mobo_Core_Reprice_Queue' ) ) {
			wp_send_json_error( array( 'message' => 'صف اعمال مجدد قیمت در دسترس نیست.' ), 500 );
		}

		$queue  = new Mobo_Core_Reprice_Queue();
		/* Self-healing: the watchdog acts only after the stall window and is bounded. */
		$queue->watchdog_tick( 'admin-status' );
		$status = $queue->get_status();

		/*
		 * A non-blocking loopback can report dispatched even when the hosting stack
		 * never executes the request. Reprice is an explicit finite admin job, so a
		 * stale authenticated status poll may advance one tiny bounded batch itself.
		 * process_batch() still owns the global Reprice worker lease and product-level
		 * concurrency locks, so this cannot race a live Cron/Self Runner worker.
		 * A historical lastError must not disable this rescue: per-item failures are
		 * already bounded by the retry limit, and blocking the rescue on any old
		 * error was one way a Reprice stayed "running" forever with no worker.
		 */
		if ( ! empty( $status['shouldContinue'] ) ) {
			$updated_at = isset( $status['updatedAt'] ) ? absint( $status['updatedAt'] ) : 0;
			$is_stale   = $updated_at > 0 && ( time() - $updated_at ) >= 8;

			if ( $is_stale ) {
				$rescue_result = $queue->process_batch( 1, 2 );
				$status        = $queue->get_status();
				$status['adminRescue'] = array(
					'status'    => isset( $rescue_result['status'] ) ? sanitize_key( (string) $rescue_result['status'] ) : '',
					'processed' => isset( $rescue_result['processed'] ) ? absint( $rescue_result['processed'] ) : 0,
				);
			}
		}

		if ( ! empty( $status['shouldContinue'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'admin-reprice-status', false );
		}

		$status['statusLabel']  = $this->reprice_status_label( isset( $status['status'] ) ? $status['status'] : 'idle' );
		$status['percentLabel'] = $this->format_percent( isset( $status['percent'] ) ? (float) $status['percent'] : 0 );
		$status['serverTime']   = Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s' );

		wp_send_json_success( $status );
	}



	/**
	 * Return current recategorize status for AJAX polling on the categories tab.
	 *
	 * @return void
	 */
	public function handle_ajax_recategorize_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
		}

		check_ajax_referer( 'mobo_core_recategorize_status', 'nonce' );

		if ( ! class_exists( 'Mobo_Core_Recategorize_Queue' ) ) {
			wp_send_json_error( array( 'message' => 'صف اعمال مجدد دسته‌بندی در دسترس نیست.' ), 500 );
		}

		$queue  = new Mobo_Core_Recategorize_Queue();
		$status = $queue->get_status();

		if ( ! empty( $status['shouldContinue'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'admin-recategorize-status', false );
		}

		$status['statusLabel']  = $this->recategorize_status_label( isset( $status['status'] ) ? $status['status'] : 'idle' );
		$status['percentLabel'] = $this->format_percent( isset( $status['percent'] ) ? (float) $status['percent'] : 0 );
		$status['serverTime']   = Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s' );

		wp_send_json_success( $status );
	}


	/**
	 * Return the complete image-refresh tab fragment for lightweight live polling.
	 *
	 * The browser replaces only the current tab content. No workflow action is
	 * executed here; at most a non-blocking Self Runner kick is requested while
	 * safe automation is active.
	 *
	 * @return void
	 */
	public function handle_ajax_image_refresh_live_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز است.' ), 403 );
		}

		check_ajax_referer( 'mobo_core_image_refresh_live_status', 'nonce' );

		$automation_status = class_exists( 'Mobo_Core_Image_Refresh_Automation' ) ? Mobo_Core_Image_Refresh_Automation::get_status() : array();
		$rescue_result     = array();

		if ( ! empty( $automation_status['enabled'] ) && empty( $automation_status['waitingApproval'] ) ) {
			$status_key = isset( $automation_status['status'] ) ? sanitize_key( (string) $automation_status['status'] ) : '';
			$passive_wait = in_array(
				$status_key,
				array( 'waiting-retry', 'waiting-active-processor', 'waiting-subsize-retry', 'waiting-product-repair-retry', 'image-environment-not-ready', 'missing-components', 'missing-orphan-cleanup' ),
				true
			);
			$last_activity = max(
				absint( isset( $automation_status['lastRunAt'] ) ? $automation_status['lastRunAt'] : 0 ),
				absint( isset( $automation_status['lastTickFinishedAt'] ) ? $automation_status['lastTickFinishedAt'] : 0 )
			);
			$stale = $last_activity <= 0 || ( time() - $last_activity ) >= 12;

			/*
			 * A non-blocking loopback request can report "dispatched" even when the
			 * hosting stack never executes that request. Product Sync already has an
			 * authenticated admin-poll fallback for this class of host. Image Refresh
			 * needs the same protection: when the workflow is enabled, not intentionally
			 * waiting and no batch is currently running, a stale live-status poll runs
			 * exactly one bounded automation tick directly. run_tick() still owns the
			 * normal image_refresh_automation lock, so this cannot create a parallel
			 * deletion worker beside real cron or a live Self Runner request.
			 */
			if ( $stale && empty( $automation_status['batchRunning'] ) && ! $passive_wait && class_exists( 'Mobo_Core_Image_Refresh_Automation' ) ) {
				$automation    = new Mobo_Core_Image_Refresh_Automation();
				$rescue_result = $automation->run_tick( 'admin-live-rescue' );
				$automation_status = Mobo_Core_Image_Refresh_Automation::get_status();

				if ( ! empty( $rescue_result['success'] ) && ! empty( $rescue_result['needsContinuation'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
					Mobo_Core_Self_Runner::kick( 'admin-image-refresh-live-rescue', true );
				}
			} elseif ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::kick( 'admin-image-refresh-live-status', false );
			}
		}

		ob_start();
		$this->render_image_refresh_tab();
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'         => $html,
				'serverTime'   => Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s' ),
				'rescueResult' => $rescue_result,
			)
		);
	}

	/**
	 * Sync categories before product sync so the customer can complete mapping.
	 *
	 * @return void
	 */
	public function handle_sync_categories() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_sync_categories', 'mobo_core_nonce' );

		$product_sync = new Mobo_Core_Product_Sync();
		$result       = $product_sync->preload_categories_for_mapping( '' );

		$type    = ! empty( $result['success'] ) ? 'success' : 'error';
		$message = isset( $result['message'] ) ? $result['message'] : 'دسته‌بندی‌ها برای نگاشت لود شدند.';

		if ( ! empty( $result['data'] ) && is_array( $result['data'] ) && ! empty( $result['data']['synced'] ) ) {
			$created = isset( $result['data']['created'] ) ? absint( $result['data']['created'] ) : 0;
			$updated = isset( $result['data']['updated'] ) ? absint( $result['data']['updated'] ) : 0;
			$active  = isset( $result['data']['active'] ) ? absint( $result['data']['active'] ) : 0;
			$message = sprintf( 'فهرست دسته‌های منبع دریافت شد. دسته فعال: %d، ردیف جدید: %d، بروزرسانی‌شده: %d.', $active, $created, $updated );
		}

		$this->redirect_with_message( $message, $type, 'categories' );
	}

	/**
	 * Handle start sync.
	 *
	 * @return void
	 */
	public function handle_start_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_start_sync', 'mobo_core_nonce' );

		$product_sync = new Mobo_Core_Product_Sync();
		$result       = $product_sync->start_manual_sync( '', 'admin' );

		if ( ! empty( $result['success'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'admin-sync-start', false );
		}

		$type    = ! empty( $result['success'] ) ? 'success' : 'error';
		$message = isset( $result['message'] ) ? $result['message'] : 'عملیات انجام شد.';

		$this->redirect_with_message( $message, $type, 'dashboard' );
	}



	/**
	 * Move duplicate products to draft without deleting data.
	 *
	 * @return void
	 */
	public function handle_quarantine_duplicate_products() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_quarantine_duplicate_products', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			$this->redirect_with_message( 'ابزار بررسی محصول تکراری در دسترس نیست.', 'error', 'dashboard' );
		}

		$result = Mobo_Core_Product_Concurrency::quarantine_duplicate_products( 100 );
		update_option( 'mobo_core_last_duplicate_quarantine_result', $result, false );
		update_option( 'mobo_core_last_duplicate_quarantine_at', time(), false );

		$remaining = Mobo_Core_Product_Concurrency::count_duplicate_product_groups();
		if ( $remaining > 0 ) {
			$message = sprintf(
				'نسخه‌های تکراری قابل‌پردازش به پیش‌نویس منتقل شدند، اما %d گروه هنوز نیاز به بررسی دارد. انتقال‌یافته: %d، ردشده: %d.',
				absint( $remaining ),
				isset( $result['quarantined'] ) ? absint( $result['quarantined'] ) : 0,
				isset( $result['skipped'] ) ? absint( $result['skipped'] ) : 0
			);
			$this->redirect_with_message( $message, 'warning', 'dashboard' );
		}

		$message = sprintf(
			'محصولات تکراری تعیین تکلیف شدند. %d نسخه تکراری به پیش‌نویس منتقل شد و محصول اصلی دست‌نخورده باقی ماند.',
			isset( $result['quarantined'] ) ? absint( $result['quarantined'] ) : 0
		);

		$this->redirect_with_message( $message, 'success', 'dashboard' );
	}

	/**
	 * Handle start repair sync.
	 *
	 * @return void
	 */
	public function handle_start_repair() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_start_repair', 'mobo_core_nonce' );

		$product_sync = new Mobo_Core_Product_Sync();
		$result       = $product_sync->start_manual_sync( '', 'repair', true );

		/* Give products marked during the storage migration a fresh re-probe. */
		if ( ! empty( $result['success'] ) && class_exists( 'Mobo_Core_Migration_Quarantine' ) ) {
			Mobo_Core_Migration_Quarantine::request_reprobe();
		}

		if ( ! empty( $result['success'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'admin-repair-start', false );
		}

		$type    = ! empty( $result['success'] ) ? 'success' : 'error';
		$message = isset( $result['message'] ) ? $result['message'] : 'Repair شروع شد.';

		$this->redirect_with_message( $message, $type, 'dashboard' );
	}



	/**
	 * Resume a waiting manual sync without resetting cursor/page state.
	 *
	 * @return void
	 */
	public function handle_resume_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_resume_sync', 'mobo_core_nonce' );

		$product_sync = new Mobo_Core_Product_Sync();
		$result       = $product_sync->resume_manual_sync();
		$success      = ! empty( $result['success'] );

		if ( $success && empty( $result['data']['alreadyRunning'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'admin-sync-resume', true );
		}

		$this->redirect_with_message(
			isset( $result['message'] ) ? (string) $result['message'] : 'Resume انجام نشد.',
			$success ? 'success' : 'error',
			'dashboard'
		);
	}

	/**
	 * Handle cancel sync.
	 *
	 * @return void
	 */
	public function handle_cancel_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_cancel_sync', 'mobo_core_nonce' );

		$product_sync = new Mobo_Core_Product_Sync();
		$result       = $product_sync->cancel_manual_sync();

		$type    = ! empty( $result['success'] ) ? 'success' : 'error';
		$message = isset( $result['message'] ) ? $result['message'] : 'عملیات انجام شد.';

		$this->redirect_with_message( $message, $type, 'dashboard' );
	}

	/**
	 * Handle reset sync.
	 *
	 * @return void
	 */
	public function handle_reset_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_reset_sync', 'mobo_core_nonce' );

		$product_sync = new Mobo_Core_Product_Sync();
		$reset        = $product_sync->reset_manual_sync_state();

		if ( false === $reset ) {
			$this->redirect_with_message( 'در حال حاضر یک Worker همگام‌سازی فعال است. پس از پایان مرحله جاری دوباره Reset را انجام دهید.', 'error', 'dashboard' );
		}

		$this->redirect_with_message( 'وضعیت همگام‌سازی پاک شد.', 'success', 'dashboard' );
	}

	/**
	 * Start repricing all synced WooCommerce products/variations.
	 *
	 * @return void
	 */
	public function handle_start_reprice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_start_reprice', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Reprice_Queue' ) ) {
			$this->redirect_with_message( 'صف اعمال مجدد قیمت در دسترس نیست.', 'error', 'pricing' );
		}

		try {
			$queue  = new Mobo_Core_Reprice_Queue();
			$result = $queue->start( 'admin' );

			/*
			 * Wake the bounded local worker. If loopback is disabled, the real cron
			 * will continue the queue and the UI still shows the queued state instead
			 * of throwing a fatal admin-post error.
			 */
			if ( ! empty( $result['success'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::kick( 'admin-reprice-start', true );
			}

			$message = isset( $result['message'] ) ? $result['message'] : 'اعمال مجدد سیاست قیمت‌گذاری شروع شد.';
			$this->redirect_with_message( $message, ! empty( $result['success'] ) ? 'success' : 'error', 'pricing' );
		} catch ( Throwable $e ) {
			Mobo_Core_Logger::error( 'Mobo Core reprice start failed: ' . $e->getMessage() );
			$this->redirect_with_message( 'شروع اعمال مجدد قیمت با خطا مواجه شد: ' . $e->getMessage(), 'error', 'pricing' );
		}
	}

	/**
	 * Resume a manually cancelled repricing generation.
	 *
	 * @return void
	 */
	public function handle_resume_reprice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_resume_reprice', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Reprice_Queue' ) ) {
			$this->redirect_with_message( 'صف اعمال مجدد قیمت در دسترس نیست.', 'error', 'pricing' );
		}

		try {
			$queue  = new Mobo_Core_Reprice_Queue();
			$result = $queue->resume();

			if ( ! empty( $result['success'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::kick( 'admin-reprice-resume', true );
			}

			$message = isset( $result['message'] ) ? $result['message'] : 'اعمال مجدد قیمت ادامه یافت.';
			$this->redirect_with_message( $message, ! empty( $result['success'] ) ? 'success' : 'error', 'pricing' );
		} catch ( Throwable $e ) {
			Mobo_Core_Logger::error( 'Mobo Core reprice resume failed: ' . $e->getMessage() );
			$this->redirect_with_message( 'ادامه اعمال مجدد قیمت با خطا مواجه شد: ' . $e->getMessage(), 'error', 'pricing' );
		}
	}

	/**
	 * «ادامه»: resume a stalled Reprice and run one small inline batch.
	 *
	 * @return void
	 */
	public function handle_reprice_recover() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_reprice_recover', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Reprice_Queue' ) ) {
			$this->redirect_with_message( 'صف اعمال مجدد قیمت در دسترس نیست.', 'error', 'pricing' );
		}

		try {
			$result = ( new Mobo_Core_Reprice_Queue() )->admin_recover();
			$message = isset( $result['message'] ) ? (string) $result['message'] : 'اجرا ادامه یافت.';
			$this->redirect_with_message( $message, ! empty( $result['success'] ) ? 'success' : 'error', 'pricing' );
		} catch ( Throwable $e ) {
			Mobo_Core_Logger::error( 'Mobo Core reprice recover failed: ' . $e->getMessage() );
			$this->redirect_with_message( 'ادامه اعمال قیمت با خطا مواجه شد: ' . $e->getMessage(), 'error', 'pricing' );
		}
	}

	/**
	 * «رد کردن مورد مشکل‌دار»: skip the item blocking the Reprice cursor.
	 *
	 * @return void
	 */
	public function handle_reprice_skip_item() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_reprice_skip_item', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Reprice_Queue' ) ) {
			$this->redirect_with_message( 'صف اعمال مجدد قیمت در دسترس نیست.', 'error', 'pricing' );
		}

		try {
			$result = ( new Mobo_Core_Reprice_Queue() )->skip_problem_item();
			if ( ! empty( $result['success'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::kick( 'admin-reprice-skip', true );
			}
			$message = isset( $result['message'] ) ? (string) $result['message'] : '';
			$this->redirect_with_message( $message, ! empty( $result['success'] ) ? 'success' : 'error', 'pricing' );
		} catch ( Throwable $e ) {
			Mobo_Core_Logger::error( 'Mobo Core reprice skip failed: ' . $e->getMessage() );
			$this->redirect_with_message( 'رد کردن مورد با خطا مواجه شد: ' . $e->getMessage(), 'error', 'pricing' );
		}
	}

	/**
	 * Cancel current repricing run.
	 *
	 * @return void
	 */
	public function handle_cancel_reprice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_cancel_reprice', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Reprice_Queue' ) ) {
			$this->redirect_with_message( 'صف اعمال مجدد قیمت در دسترس نیست.', 'error', 'pricing' );
		}

		try {
			$queue  = new Mobo_Core_Reprice_Queue();
			$result = $queue->cancel();
			$message = isset( $result['message'] ) ? $result['message'] : 'اعمال مجدد قیمت متوقف شد.';

			$this->redirect_with_message( $message, ! empty( $result['success'] ) ? 'success' : 'error', 'pricing' );
		} catch ( Throwable $e ) {
			Mobo_Core_Logger::error( 'Mobo Core reprice cancel failed: ' . $e->getMessage() );
			$this->redirect_with_message( 'توقف اعمال مجدد قیمت با خطا مواجه شد: ' . $e->getMessage(), 'error', 'pricing' );
		}
	}

	/**
	 * Reset repricing status.
	 *
	 * @return void
	 */
	public function handle_reset_reprice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_reset_reprice', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Reprice_Queue' ) ) {
			$this->redirect_with_message( 'صف اعمال مجدد قیمت در دسترس نیست.', 'error', 'pricing' );
		}

		try {
			$queue  = new Mobo_Core_Reprice_Queue();
			$result = $queue->reset();
			$message = isset( $result['message'] ) ? $result['message'] : 'وضعیت اعمال مجدد قیمت پاک شد.';

			$this->redirect_with_message( $message, ! empty( $result['success'] ) ? 'success' : 'error', 'pricing' );
		} catch ( Throwable $e ) {
			Mobo_Core_Logger::error( 'Mobo Core reprice reset failed: ' . $e->getMessage() );
			$this->redirect_with_message( 'پاک کردن وضعیت اعمال مجدد قیمت با خطا مواجه شد: ' . $e->getMessage(), 'error', 'pricing' );
		}
	}



	/**
	 * Start applying current category mapping to published synced products.
	 *
	 * @return void
	 */
	public function handle_start_recategorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_start_recategorize', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Recategorize_Queue' ) ) {
			$this->redirect_with_message( 'صف اعمال مجدد دسته‌بندی در دسترس نیست.', 'error', 'categories' );
		}

		try {
			$queue  = new Mobo_Core_Recategorize_Queue();
			$result = $queue->start( 'admin' );

			if ( ! empty( $result['success'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::kick( 'admin-recategorize-start', true );
			}

			$message = isset( $result['message'] ) ? $result['message'] : 'اعمال مجدد دسته‌بندی‌ها شروع شد.';
			$this->redirect_with_message( $message, ! empty( $result['success'] ) ? 'success' : 'error', 'categories' );
		} catch ( Throwable $e ) {
			Mobo_Core_Logger::error( 'Mobo Core recategorize start failed: ' . $e->getMessage() );
			$this->redirect_with_message( 'شروع اعمال مجدد دسته‌بندی با خطا مواجه شد: ' . $e->getMessage(), 'error', 'categories' );
		}
	}

	/**
	 * Cancel current category reapply run.
	 *
	 * @return void
	 */
	public function handle_cancel_recategorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_cancel_recategorize', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Recategorize_Queue' ) ) {
			$this->redirect_with_message( 'صف اعمال مجدد دسته‌بندی در دسترس نیست.', 'error', 'categories' );
		}

		try {
			$queue   = new Mobo_Core_Recategorize_Queue();
			$result  = $queue->cancel();
			$message = isset( $result['message'] ) ? $result['message'] : 'اعمال مجدد دسته‌بندی متوقف شد.';

			$this->redirect_with_message( $message, ! empty( $result['success'] ) ? 'success' : 'error', 'categories' );
		} catch ( Throwable $e ) {
			Mobo_Core_Logger::error( 'Mobo Core recategorize cancel failed: ' . $e->getMessage() );
			$this->redirect_with_message( 'توقف اعمال مجدد دسته‌بندی با خطا مواجه شد: ' . $e->getMessage(), 'error', 'categories' );
		}
	}

	/**
	 * Reset category reapply state.
	 *
	 * @return void
	 */
	public function handle_reset_recategorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_reset_recategorize', 'mobo_core_nonce' );

		if ( ! class_exists( 'Mobo_Core_Recategorize_Queue' ) ) {
			$this->redirect_with_message( 'صف اعمال مجدد دسته‌بندی در دسترس نیست.', 'error', 'categories' );
		}

		try {
			$queue   = new Mobo_Core_Recategorize_Queue();
			$result  = $queue->reset();
			$message = isset( $result['message'] ) ? $result['message'] : 'وضعیت اعمال مجدد دسته‌بندی پاک شد.';

			$this->redirect_with_message( $message, ! empty( $result['success'] ) ? 'success' : 'error', 'categories' );
		} catch ( Throwable $e ) {
			Mobo_Core_Logger::error( 'Mobo Core recategorize reset failed: ' . $e->getMessage() );
			$this->redirect_with_message( 'پاک کردن وضعیت اعمال مجدد دسته‌بندی با خطا مواجه شد: ' . $e->getMessage(), 'error', 'categories' );
		}
	}

	/**
	 * Re-queue failed table-backed webhook events and wake the worker.
	 *
	 * @return void
	 */
	public function handle_retry_failed_webhooks() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_retry_failed_webhooks', 'mobo_core_nonce' );

		$updated = 0;
		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) && Mobo_Core_Sync_Event_Store::table_exists() ) {
			$store   = new Mobo_Core_Sync_Event_Store();
			$updated = method_exists( $store, 'retry_failed_events' ) ? $store->retry_failed_events( 1000 ) : 0;
		}

		if ( $updated > 0 && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'admin-retry-failed-webhooks', true );
		}

		$this->redirect_with_message( sprintf( '%d وب‌هوک failed دوباره به صف برگشت.', absint( $updated ) ), $updated > 0 ? 'success' : 'warning', 'health' );
	}


	/**
	 * Whether image refresh/orphan cleanup is allowed.
	 *
	 * @return bool
	 */
	private function is_image_refresh_unlocked() {
		return class_exists( 'Mobo_Core_Product_Sync' ) && Mobo_Core_Product_Sync::is_repair_completed();
	}

	/**
	 * Redirect when image refresh is locked by missing repair pass.
	 *
	 * @return bool
	 */
	private function redirect_if_image_refresh_locked() {
		if ( $this->is_image_refresh_unlocked() ) {
			return false;
		}

		$this->redirect_with_message( 'نوسازی تصاویر تا قبل از تکمیل ترمیم محصولات قفل است.', 'warning', 'image-refresh' );
		return true;
	}


	/**
	 * Build one authoritative state machine for the image refresh workflow.
	 *
	 * The same state is used by the UI, the "next step" recommendation and
	 * server-side POST guards so a stale page or direct request cannot bypass
	 * prerequisites.
	 *
	 * @return array
	 */
	private function get_image_refresh_workflow_state() {
		$queue  = class_exists( 'Mobo_Core_Image_Refresh_Queue' ) ? new Mobo_Core_Image_Refresh_Queue() : null;
		$status = $queue ? $queue->get_status() : array();
		$automation_status = class_exists( 'Mobo_Core_Image_Refresh_Automation' ) ? Mobo_Core_Image_Refresh_Automation::get_status() : array();
		$automation_active = ! empty( $automation_status['enabled'] );
		$automation_waiting = isset( $automation_status['waitingApproval'] ) ? sanitize_key( (string) $automation_status['waitingApproval'] ) : '';

		$scan           = isset( $status['lastScan'] ) && is_array( $status['lastScan'] ) ? $status['lastScan'] : array();
		$enqueue        = isset( $status['lastEnqueue'] ) && is_array( $status['lastEnqueue'] ) ? $status['lastEnqueue'] : array();
		$subsize_scan   = Mobo_Core_Settings::get( 'mobo_core_image_refresh_last_subsize_scan', array() );
		$subsize_repair = Mobo_Core_Settings::get( 'mobo_core_image_refresh_last_subsize_repair', array() );
		$replaced_scan  = Mobo_Core_Settings::get( 'mobo_core_image_refresh_last_replaced_scan', array() );
		$replaced_delete = Mobo_Core_Settings::get( 'mobo_core_image_refresh_last_replaced_delete', array() );

		$subsize_scan    = is_array( $subsize_scan ) ? $subsize_scan : array();
		$subsize_repair  = is_array( $subsize_repair ) ? $subsize_repair : array();
		$replaced_scan   = is_array( $replaced_scan ) ? $replaced_scan : array();
		$replaced_delete = is_array( $replaced_delete ) ? $replaced_delete : array();

		$orphan_cleanup = class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ? new Mobo_Core_Orphan_Image_Cleanup() : null;
		$orphan_status  = $orphan_cleanup ? $orphan_cleanup->get_status() : array();
		$orphan_scan    = isset( $orphan_status['lastScan'] ) && is_array( $orphan_status['lastScan'] ) ? $orphan_status['lastScan'] : array();
		$orphan_delete  = isset( $orphan_status['lastDelete'] ) && is_array( $orphan_status['lastDelete'] ) ? $orphan_status['lastDelete'] : array();

		$image_environment     = $this->get_image_environment_status();
		/* Image refresh execution has no separate safety switch anymore. Entering the
		 * manual stage or starting automation is the explicit execution intent. */
		$image_refresh_enabled = true;
		$delete_old_enabled    = Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' );
		$orphan_delete_enabled = Mobo_Core_Settings::enabled( 'mobo_core_orphan_image_cleanup_enabled', '0' );
		$scan_limit            = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_scan_limit', 50, 10, 5000 );

		$scan_time        = absint( isset( $scan['checkedAt'] ) ? $scan['checkedAt'] : 0 );
		$enqueue_time     = absint( isset( $enqueue['checkedAt'] ) ? $enqueue['checkedAt'] : 0 );
		$scan_cycle_id    = ! empty( $scan['cycleId'] ) ? sanitize_text_field( (string) $scan['cycleId'] ) : '';
		$enqueue_scan_cycle_id = ! empty( $enqueue['sourceScanCycleId'] ) ? sanitize_text_field( (string) $enqueue['sourceScanCycleId'] ) : '';
		$enqueue_matches_scan = '' !== $scan_cycle_id
			? hash_equals( $scan_cycle_id, $enqueue_scan_cycle_id )
			: $enqueue_time >= $scan_time;
		$scan_complete    = $scan_time > 0 && ! empty( $scan['cycleComplete'] );
		$enqueue_complete = $enqueue_time > 0
			&& ! empty( $enqueue['cycleComplete'] )
			&& $enqueue_matches_scan;

		$pending           = absint( isset( $status['pending'] ) ? $status['pending'] : 0 );
		$due               = absint( isset( $status['due'] ) ? $status['due'] : 0 );
		$failed            = absint( isset( $status['failed'] ) ? $status['failed'] : 0 );
		$active_processing = absint( isset( $status['activeProcessing'] ) ? $status['activeProcessing'] : 0 );
		$waiting_retry     = absint( isset( $status['waitingRetry'] ) ? $status['waitingRetry'] : 0 );
		$queue_terminal    = $enqueue_complete && 0 === $pending && 0 === $failed;

		$subsize_scan_time   = absint( isset( $subsize_scan['checkedAt'] ) ? $subsize_scan['checkedAt'] : 0 );
		$subsize_repair_time = absint( isset( $subsize_repair['checkedAt'] ) ? $subsize_repair['checkedAt'] : 0 );
		$subsize_scan_complete = $subsize_scan_time > 0 && ! empty( $subsize_scan['cycleComplete'] );
		$subsize_repair_complete = $subsize_repair_time > 0 && ! empty( $subsize_repair['cycleComplete'] );
		$subsize_repair_newer = $subsize_repair_time > 0 && $subsize_repair_time >= $subsize_scan_time;
		$subsize_needs_repair = absint( isset( $subsize_scan['needsRepair'] ) ? $subsize_scan['needsRepair'] : 0 ) > 0;
		$has_split_repair_counts = array_key_exists( 'localNeedsRepair', $subsize_scan ) || array_key_exists( 'sharedNeedsRepair', $subsize_scan );
		$local_needs_repair      = absint( isset( $subsize_scan['localNeedsRepair'] ) ? $subsize_scan['localNeedsRepair'] : 0 );
		$shared_needs_repair     = absint( isset( $subsize_scan['sharedNeedsRepair'] ) ? $subsize_scan['sharedNeedsRepair'] : 0 );
		$subsize_repair_environment_ready = $has_split_repair_counts
			? ( ( 0 === $local_needs_repair || ! empty( $image_environment['localReady'] ) )
				&& ( 0 === $shared_needs_repair || ! empty( $image_environment['sharedRepositoryReady'] ) ) )
			: ! empty( $image_environment['ready'] );
		$subsize_hard_errors  = absint( isset( $subsize_scan['unsupportedEditor'] ) ? $subsize_scan['unsupportedEditor'] : 0 )
			+ absint( isset( $subsize_scan['missingOriginal'] ) ? $subsize_scan['missingOriginal'] : 0 );
		$subsize_healthy = $subsize_scan_complete
			&& ! $subsize_needs_repair
			&& 0 === $subsize_hard_errors
			&& $subsize_scan_time >= $subsize_repair_time;
		$subsize_requires_rescan = $subsize_repair_newer
			&& $subsize_repair_complete
			&& 0 === absint( isset( $subsize_repair['failed'] ) ? $subsize_repair['failed'] : 0 );

		$replaced_scan_time   = absint( isset( $replaced_scan['checkedAt'] ) ? $replaced_scan['checkedAt'] : 0 );
		$replaced_delete_time = absint( isset( $replaced_delete['checkedAt'] ) ? $replaced_delete['checkedAt'] : 0 );
		$replaced_scan_complete = $replaced_scan_time > 0 && ! empty( $replaced_scan['cycleComplete'] );
		$replaced_ready         = absint( isset( $replaced_scan['ready'] ) ? $replaced_scan['ready'] : 0 );
		$replaced_migration_candidates = absint( isset( $replaced_scan['migrationCandidates'] ) ? $replaced_scan['migrationCandidates'] : 0 );
		$replaced_actionable    = $replaced_ready + $replaced_migration_candidates;
		$replaced_delete_newer = $replaced_delete_time > 0
			&& ! empty( $replaced_delete['cycleComplete'] )
			&& $replaced_delete_time >= $replaced_scan_time;
		$replaced_delete_progress = absint( isset( $replaced_delete['passProgress'] )
			? $replaced_delete['passProgress']
			: absint( isset( $replaced_delete['deleted'] ) ? $replaced_delete['deleted'] : 0 )
				+ absint( isset( $replaced_delete['referenceRowsUpdated'] ) ? $replaced_delete['referenceRowsUpdated'] : 0 ) );
		$replaced_delete_needs_followup = $replaced_delete_newer && $replaced_delete_progress > 0;
		$replaced_delete_stable = $replaced_delete_newer && 0 === $replaced_delete_progress;
		/* "Resolved" means Stage 7 converged: either there was nothing to process or
		 * a complete pass made no additional safe progress. Blocked items are kept. */
		$replaced_verified_clean = $replaced_scan_complete
			&& ( 0 === $replaced_actionable || $replaced_delete_stable );

		$orphan_scan_time   = absint( isset( $orphan_scan['checkedAt'] ) ? $orphan_scan['checkedAt'] : 0 );
		$orphan_delete_time = absint( isset( $orphan_delete['executedAt'] ) ? $orphan_delete['executedAt'] : 0 );
		$orphan_scan_complete = $orphan_scan_time > 0 && ! empty( $orphan_scan['cycleComplete'] );
		$orphan_candidates    = absint( isset( $orphan_scan['candidateFamilies'] ) ? $orphan_scan['candidateFamilies'] : 0 );
		$orphan_pending_candidates = absint( isset( $orphan_status['actionable'] ) ? $orphan_status['actionable'] : ( isset( $orphan_status['candidate'] ) ? $orphan_status['candidate'] : 0 ) );
		$orphan_delete_newer  = $orphan_delete_time > 0 && $orphan_delete_time >= $orphan_scan_time;
		$orphan_delete_success = $orphan_delete_newer
			&& 0 === $orphan_pending_candidates
			&& 0 === absint( isset( $orphan_delete['failedFamilies'] ) ? $orphan_delete['failedFamilies'] : ( isset( $orphan_delete['failed'] ) ? $orphan_delete['failed'] : 0 ) );
		$orphan_verified_clean = $orphan_scan_complete
			&& 0 === $orphan_candidates
			&& $orphan_scan_time >= $orphan_delete_time;

		$enqueue_total     = absint( isset( $enqueue['estimatedTotal'] ) ? $enqueue['estimatedTotal'] : ( isset( $scan['estimatedTotal'] ) ? $scan['estimatedTotal'] : 0 ) );
		$enqueue_scanned   = absint( isset( $enqueue['scanned'] ) ? $enqueue['scanned'] : 0 );
		$enqueue_remaining = max( 0, $enqueue_total - $enqueue_scanned );
		$enqueue_clicks    = $enqueue_complete ? 0 : max( 1, (int) ceil( max( 1, $enqueue_remaining ) / max( 1, $scan_limit ) ) );

		$steps = array();
		$steps[1] = array(
			'enabled' => ! $scan_complete,
			'label'   => ! empty( $scan['checkedAt'] ) ? '۱) ادامه بررسی تصاویر قدیمی' : '۱) شروع بررسی تصاویر قدیمی',
			'reason'  => $scan_complete ? 'مرحله ۱ کامل شده است. برای اسکن دوره جدید از ریست کامل استفاده کنید.' : 'هر بار یک بخش از تصاویر بررسی می شود.',
		);
		$steps[2] = array(
			'enabled' => $scan_complete && ! $enqueue_complete,
			'label'   => ! empty( $enqueue['checkedAt'] )
				? sprintf( '۲) ادامه ساخت صف - حدود %d اجرا باقی مانده', $enqueue_clicks )
				: sprintf( '۲) شروع ساخت صف - حدود %d اجرا', $enqueue_clicks ),
			'reason'  => ! $scan_complete
				? 'ابتدا مرحله ۱ باید یک دوره کامل شود.'
				: ( $enqueue_complete ? 'ساخت صف یک دوره کامل شده است.' : 'این دکمه را تا نمایش ۱۰۰٪ و «دوره کامل شد» تکرار کنید.' ),
		);
		$steps[3] = array(
			'enabled' => $enqueue_complete && $due > 0 && 0 === $active_processing && $image_refresh_enabled && ! empty( $image_environment['ready'] ),
			'label'   => '۳) پردازش مرحله ای صف',
			'reason'  => ! $enqueue_complete
				? 'ابتدا ساخت صف باید کامل شود.'
				: ( empty( $image_environment['ready'] )
						? 'پیش نیازهای پردازش WebP آماده نیستند.'
						: ( $active_processing > 0
							? 'یک پردازش دیگر هم اکنون فعال است.'
							: ( $due <= 0
								? ( $waiting_retry > 0 ? 'ردیف ها تا زمان تلاش مجدد در انتظار هستند.' : 'ردیف آماده پردازش وجود ندارد.' )
								: '' ) ) ),
		);
		$steps[4] = array(
			'enabled' => $queue_terminal && ( ! $subsize_scan_complete || $subsize_requires_rescan ),
			'label'   => $subsize_requires_rescan ? '۴) اسکن مجدد و تایید برش های WebP' : ( $subsize_scan_time > 0 ? '۴) ادامه اسکن سلامت برش های WebP' : '۴) شروع اسکن سلامت برش های WebP' ),
			'reason'  => ! $queue_terminal
				? 'صف باید کاملا تخلیه شود و ردیف ناموفق نداشته باشد.'
				: ( $subsize_scan_complete && ! $subsize_requires_rescan ? 'اسکن برش ها کامل شده است.' : 'اسکن را تا پایان دوره ادامه دهید.' ),
		);
		$steps[5] = array(
			'enabled' => $queue_terminal
				&& $subsize_scan_complete
				&& $subsize_needs_repair
				&& ! $subsize_requires_rescan
				&& $subsize_repair_environment_ready,
			'label'   => $subsize_repair_time > 0 && ! $subsize_repair_complete ? '۵) ادامه بازسازی برش های ناقص WebP' : '۵) بازسازی برش های ناقص WebP',
			'reason'  => ! $subsize_scan_complete
				? 'ابتدا مرحله ۴ باید کامل شود.'
				: ( ! $subsize_needs_repair
					? 'برش ناقصی برای بازسازی گزارش نشده است.'
					: ( $subsize_requires_rescan
						? 'بازسازی کامل شده؛ اکنون مرحله ۴ را برای تایید دوباره اجرا کنید.'
						: ( ! $subsize_repair_environment_ready ? 'پیش نیاز Repair برای فایل های محلی یا مخزن Shared Media آماده نیست.' : 'بازسازی را تا پایان دوره ادامه دهید.' ) ) ),
		);
		$steps[6] = array(
			'enabled' => $subsize_healthy && ! $replaced_scan_complete,
			'label'   => $replaced_scan_time > 0 ? '۶) ادامه اسکن پیوست های قدیمی جایگزین شده' : '۶) شروع اسکن پیوست های قدیمی جایگزین شده',
			'reason'  => ! $subsize_healthy
				? 'ابتدا سلامت نهایی برش های WebP باید تایید شود.'
				: ( $replaced_scan_complete ? 'اسکن پیوست های قدیمی کامل شده است؛ Stage 7 نتیجه را تا نقطه پایدار ادامه می دهد.' : 'اسکن را تا پایان دوره ادامه دهید.' ),
		);
		$steps[7] = array(
			'enabled' => $subsize_healthy
				&& $replaced_scan_complete
				&& $replaced_actionable > 0
				&& ! $replaced_delete_stable
				&& $delete_old_enabled,
			'label'   => ! empty( $replaced_delete['checkedAt'] ) && ( empty( $replaced_delete['cycleComplete'] ) || $replaced_delete_needs_followup )
				? '۷) ادامه خودکار انتقال مراجع و حذف امن'
				: '۷) انتقال مراجع و حذف امن پیوست های قدیمی',
			'reason'  => ! $replaced_scan_complete
				? 'ابتدا مرحله ۶ باید کامل شود.'
				: ( $replaced_actionable <= 0
					? 'پیوست آماده حذف یا نیازمند انتقال مرجع وجود ندارد.'
					: ( ! $delete_old_enabled
						? 'پس از کنترل پشتیبان، گزینه حذف پیوست قدیمی را روشن و ذخیره کنید.'
						: ( $replaced_delete_stable
							? 'Stage 7 به نقطه پایدار رسیده است؛ موارد باقی مانده توسط Safety Audit نگه داشته شده اند.'
							: 'Stage 7 با Cron/Self Runner به صورت چندگذری ادامه پیدا می کند و نیازی به کلیک تکراری ندارد.' ) ) ),
		);
		$steps[8] = array(
			'enabled' => $subsize_healthy && $replaced_verified_clean && ( ! $orphan_scan_complete || $orphan_delete_success ),
			'label'   => $orphan_delete_success ? '۸) اسکن مجدد و تایید خانواده های قدیمی' : ( $orphan_scan_time > 0 ? '۸) ادامه اسکن خانواده های قدیمی' : '۸) شروع اسکن خانواده های قدیمی' ),
			'reason'  => ! $replaced_verified_clean
				? 'Stage 7 باید به نقطه پایدار برسد؛ موارد Safety-Blocked می توانند بدون حذف باقی بمانند.'
				: ( $orphan_scan_complete && ! $orphan_delete_success ? 'اسکن خانواده ها کامل شده است.' : 'اسکن را تا پایان دوره ادامه دهید.' ),
		);
		$steps[9] = array(
			'enabled' => $replaced_verified_clean
				&& $orphan_scan_complete
				&& $orphan_pending_candidates > 0
				&& ! $orphan_delete_success
				&& $orphan_delete_enabled,
			'label'   => $orphan_delete_time > 0 ? '۹) ادامه حذف کنترل شده خانواده های آماده حذف' : '۹) حذف کنترل شده خانواده های آماده حذف',
			'reason'  => ! $orphan_scan_complete
				? 'ابتدا مرحله ۸ باید کامل شود.'
				: ( $orphan_pending_candidates <= 0
					? 'خانواده آماده حذف وجود ندارد.'
					: ( ! $orphan_delete_enabled
						? 'پس از بررسی لیست و پشتیبان، پاکسازی فایل های بدون پیوست را روشن و ذخیره کنید.'
						: ( $orphan_delete_success ? 'حذف انجام شده؛ مرحله ۸ باید دوباره نتیجه را تایید کند.' : 'حذف را محدود و مرحله ای اجرا کنید.' ) ) ),
		);

		$next = array(
			'step'    => 0,
			'type'    => 'success',
			'message' => 'همه مراحل کامل و تایید شده اند. برای بررسی دوره جدید، از «شروع کامل از مرحله ۱» استفاده کنید.',
		);

		if ( ! $scan_complete ) {
			$next = array( 'step' => 1, 'type' => 'info', 'message' => 'دکمه مرحله ۱ را بزنید و آن را تا نمایش «۱۰۰٪ - دوره کامل شد» ادامه دهید. تا آن زمان تمام مرحله های بعدی قفل هستند.' );
		} elseif ( ! $enqueue_complete ) {
			$next = array(
				'step'    => 2,
				'type'    => 'info',
				'message' => sprintf(
					'مرحله ۲ را ادامه دهید. با محدودیت فعلی %d پیوست در هر اجرا، تقریبا %d بار دیگر باید دکمه ساخت صف را بزنید؛ توقف زمانی است که پیشرفت ۱۰۰٪ و «دوره کامل شد» نمایش داده شود.',
					$scan_limit,
					$enqueue_clicks
				),
			);
		} elseif ( $active_processing > 0 ) {
			$next = array( 'step' => 3, 'type' => 'info', 'message' => 'یک پردازش صف در حال اجرا است. تا آزاد شدن قفل، دکمه های اجرایی و ریست غیرفعال می مانند.' );
		} elseif ( $failed > 0 ) {
			$next = array( 'step' => 3, 'type' => 'warning', 'message' => sprintf( '%d ردیف ناموفق وجود دارد. علت را رفع کنید، دکمه «تلاش دوباره فقط برای ردیف های ناموفق» را بزنید و سپس مرحله ۳ را ادامه دهید.', $failed ) );
		} elseif ( $due > 0 ) {
			$next = array(
				'step'    => 3,
				'type'    => empty( $image_environment['ready'] ) ? 'warning' : 'info',
				'message' => $steps[3]['enabled'] ? 'مرحله ۳ را اجرا کنید تا صف تخلیه شود.' : $steps[3]['reason'],
			);
		} elseif ( $waiting_retry > 0 || $pending > 0 ) {
			$next = array( 'step' => 3, 'type' => 'info', 'message' => sprintf( '%d ردیف در انتظار زمان تلاش مجدد یا پایان پردازش است. پس از رسیدن زمان retry صفحه را تازه کنید؛ Cron نیز می تواند آن ها را ادامه دهد.', max( $waiting_retry, $pending ) ) );
		} elseif ( ! $subsize_scan_complete || $subsize_requires_rescan ) {
			$next = array( 'step' => 4, 'type' => 'info', 'message' => 'دکمه مرحله ۴ را تا نمایش «۱۰۰٪ - دوره کامل شد» اجرا کنید. اگر مرحله ۵ قبلا اجرا شده، این اسکن تایید نهایی بازسازی است.' );
		} elseif ( $subsize_needs_repair ) {
			$next = array( 'step' => 5, 'type' => 'warning', 'message' => $steps[5]['reason'] );
		} elseif ( $subsize_hard_errors > 0 ) {
			$next = array( 'step' => 4, 'type' => 'warning', 'message' => 'فایل اصلی مفقود یا موتور WebP ناسازگار است. مشکل محیط را رفع و مرحله ۴ را دوباره اجرا کنید.' );
		} elseif ( ! $replaced_scan_complete ) {
			$next = array( 'step' => 6, 'type' => 'info', 'message' => 'دکمه مرحله ۶ را تا پایان دوره اجرا کنید. این مرحله فقط پیوست های قدیمی آماده حذف یا نیازمند انتقال مرجع را شناسایی می کند و چیزی تغییر نمی دهد.' );
		} elseif ( $replaced_actionable > 0 && ! $replaced_delete_stable ) {
			$next = array( 'step' => 7, 'type' => 'warning', 'message' => $steps[7]['reason'] );
		} elseif ( ! $orphan_scan_complete || $orphan_delete_success ) {
			$next = array( 'step' => 8, 'type' => 'info', 'message' => 'دکمه مرحله ۸ را تا پایان دوره اجرا کنید. این مرحله فقط خانواده های فایل بدون پیوست را شناسایی می کند.' );
		} elseif ( $orphan_pending_candidates > 0 ) {
			$next = array( 'step' => 9, 'type' => 'warning', 'message' => $steps[9]['reason'] );
		} elseif ( ! $orphan_verified_clean ) {
			$next = array( 'step' => 8, 'type' => 'info', 'message' => 'مرحله ۸ را یک بار دیگر اجرا کنید تا نتیجه نهایی پاکسازی تایید شود.' );
		}


		if ( $automation_active ) {
			foreach ( $steps as $step_number => $step_data ) {
				$steps[ $step_number ]['enabled'] = false;
				$steps[ $step_number ]['reason']  = 'اجرای خودکار امن فعال است و این مرحله از طریق Cron یا Self Runner انجام می شود. برای اجرای دستی ابتدا اجرای خودکار را متوقف کنید.';
			}

			if ( 'delete-old' === $automation_waiting ) {
				$next = array( 'step' => 7, 'type' => 'warning', 'message' => 'اجرای خودکار به مرحله حذف امن رسیده است. اگر تنظیم دائمی حذف پیوست قدیمی روشن باشد، Stage 7 بدون تایید جداگانه ادامه پیدا می کند؛ در غیر این صورت همان تنظیم را روشن و ذخیره کنید.' );
			} elseif ( 'delete-orphan' === $automation_waiting ) {
				$next = array( 'step' => 9, 'type' => 'warning', 'message' => 'اجرای خودکار در نقطه امن پاکسازی متوقف شده است. فهرست خانواده های آماده حذف و پشتیبان را بررسی و فقط یک بار تایید پاکسازی را بزنید.' );
			} else {
				$current_step = absint( isset( $automation_status['currentStep'] ) ? $automation_status['currentStep'] : 0 );
				$next = array(
					'step'    => $current_step,
					'type'    => 'info',
					'message' => $current_step > 0
						? sprintf( 'اجرای خودکار فعال است و مرحله %d را با batchهای محدود ادامه می دهد. نیازی به کلیک روی دکمه های مراحل نیست؛ فقط سلامت Cron یا Self Runner را کنترل کنید.', $current_step )
						: 'اجرای خودکار فعال است. نیازی به اجرای دستی مراحل نیست؛ Cron یا Self Runner وضعیت بعدی را تعیین می کند.',
				);
			}
		}

		$has_queue_state = ! empty( $enqueue['checkedAt'] )
			|| $pending > 0
			|| $failed > 0
			|| absint( isset( $status['done'] ) ? $status['done'] : 0 ) > 0
			|| absint( isset( $status['skipped'] ) ? $status['skipped'] : 0 ) > 0;
		$has_any_state = ! empty( $scan['checkedAt'] )
			|| $has_queue_state
			|| $subsize_scan_time > 0
			|| $subsize_repair_time > 0
			|| $replaced_scan_time > 0
			|| $replaced_delete_time > 0
			|| $orphan_scan_time > 0
			|| $orphan_delete_time > 0;

		return array(
			'steps' => $steps,
			'next'  => $next,
			'flags' => array(
				'scanComplete'          => $scan_complete,
				'enqueueComplete'       => $enqueue_complete,
				'queueTerminal'         => $queue_terminal,
				'subsizeHealthy'        => $subsize_healthy,
				'replacedVerifiedClean' => $replaced_verified_clean,
				'replacedDeleteStable'  => $replaced_delete_stable,
				'replacedDeleteFollowup'=> $replaced_delete_needs_followup,
				'orphanVerifiedClean'   => $orphan_verified_clean,
				'activeProcessing'      => $active_processing,
				'enqueueClicks'         => $enqueue_clicks,
				'canResetQueue'         => ! $automation_active && 0 === $active_processing && $scan_complete && $has_queue_state,
				'canResetAll'           => ! $automation_active && 0 === $active_processing && $has_any_state,
				'canRetryFailed'        => ! $automation_active && 0 === $active_processing && $failed > 0,
				'canResetOrphan'        => ! $automation_active && 0 === $active_processing && ( $orphan_scan_time > 0 || $orphan_delete_time > 0 || ! empty( $orphan_status['candidate'] ) || ! empty( $orphan_status['failed'] ) ),
				'canEnableDeleteOld'     => ! $automation_active && $subsize_healthy && $replaced_scan_complete && $replaced_actionable > 0 && ! $replaced_delete_stable,
				'canEnableOrphanDelete'  => ! $automation_active && $replaced_verified_clean && $orphan_scan_complete && $orphan_pending_candidates > 0 && ! $orphan_delete_success,
				'canEnableRefresh'       => ! $automation_active && $enqueue_complete && ( $pending > 0 || $failed > 0 ),
				'automationActive'       => $automation_active,
			),
		);
	}

	/**
	 * Enforce one workflow step on the server side.
	 *
	 * @param int $step Step number.
	 * @return bool True when redirected/blocked.
	 */
	private function redirect_if_image_refresh_step_blocked( $step ) {
		$workflow = $this->get_image_refresh_workflow_state();
		$step     = absint( $step );

		if ( isset( $workflow['steps'][ $step ] ) && ! empty( $workflow['steps'][ $step ]['enabled'] ) ) {
			return false;
		}

		$reason = isset( $workflow['steps'][ $step ]['reason'] ) ? (string) $workflow['steps'][ $step ]['reason'] : 'این مرحله در وضعیت فعلی قابل اجرا نیست.';
		$this->redirect_with_message( $reason, 'warning', 'image-refresh' );
		return true;
	}

	/**
	 * Start or resume the safe automated image-refresh workflow.
	 *
	 * @return void
	 */
	public function handle_start_image_refresh_automation() {
		$this->verify_image_refresh_automation_action( 'mobo_core_start_image_refresh_automation', false );

		if ( class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists() ) {
			$connection = $this->current_admin_connection();
			$enabled_count = Mobo_Core_Connection_Repository::count_enabled();
			$connection_ready = is_array( $connection )
				&& ! empty( $connection['is_enabled'] )
				&& Mobo_Core_Connection_Repository::is_provisioned( $connection )
				&& $enabled_count > 0
				&& ( $enabled_count <= 1 || Mobo_Core_Connection_Repository::multi_connection_runtime_ready() );
			if ( ! $connection_ready ) {
				$this->redirect_with_message( 'Connection انتخاب‌شده برای اجرای نوسازی تصاویر آماده نیست. ابتدا وضعیت اتصال/provisioning را اصلاح کنید.', 'warning', 'image-refresh' );
				return;
			}
		}

		if ( ! class_exists( 'Mobo_Core_Image_Refresh_Service' )
			|| ! class_exists( 'Mobo_Core_Image_Refresh_Queue' )
			|| ! Mobo_Core_Image_Refresh_Queue::table_exists()
			|| ! class_exists( 'Mobo_Core_Orphan_Image_Cleanup' )
			|| ! Mobo_Core_Orphan_Image_Cleanup::table_exists() ) {
			$this->redirect_with_message( 'اجزای داخلی یا جدول‌های لازم برای نوسازی تصاویر آماده نیستند. ابتدا schema/upgrade افزونه را کامل کنید.', 'error', 'image-refresh' );
			return;
		}

		/* Permanent deletion needs an explicit, separate opt-in in the same form. */
		$approve_delete = isset( $_POST['mobo_approve_delete'], $_POST['mobo_backup_confirmed'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_image_refresh_automation_action().
			&& '1' === sanitize_key( wp_unslash( $_POST['mobo_approve_delete'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			&& '1' === sanitize_key( wp_unslash( $_POST['mobo_backup_confirmed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$automation = new Mobo_Core_Image_Refresh_Automation();
		$result     = $automation->start( $approve_delete );
		$success    = ! empty( $result['success'] );

		if ( $success && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'image-refresh-auto-start', true );
		}

		$this->redirect_with_message(
			isset( $result['message'] ) ? (string) $result['message'] : 'وضعیت اجرای خودکار به روز شد.',
			$success ? 'success' : 'warning',
			'image-refresh'
		);
	}

	/**
	 * Pause automated image refresh safely.
	 *
	 * @return void
	 */
	public function handle_pause_image_refresh_automation() {
		/* Stop must always work, even when Repair state changed meanwhile. */
		$this->verify_image_refresh_automation_action( 'mobo_core_pause_image_refresh_automation', false );

		$automation = new Mobo_Core_Image_Refresh_Automation();
		$result     = $automation->pause( 'admin' );
		$this->redirect_with_message( isset( $result['message'] ) ? (string) $result['message'] : 'نوسازی تصاویر متوقف شد.', 'success', 'image-refresh' );
	}

	/**
	 * Run one bounded automation slice immediately from wp-admin.
	 *
	 * @return void
	 */
	public function handle_run_image_refresh_automation_now() {
		$this->verify_image_refresh_automation_action( 'mobo_core_run_image_refresh_automation_now' );

		$automation = new Mobo_Core_Image_Refresh_Automation();
		$result     = $automation->run_tick( 'admin-manual' );
		if ( ! empty( $result['progressed'] ) && ! empty( $result['needsContinuation'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'image-refresh-auto-manual', true );
		}

		$this->redirect_with_message(
			isset( $result['message'] ) ? (string) $result['message'] : 'یک مرحله خودکار اجرا شد.',
			empty( $result['success'] ) ? 'warning' : 'success',
			'image-refresh'
		);
	}

	/**
	 * Approve automatic deletion of replaced old attachments for the current cycle.
	 *
	 * @return void
	 */
	public function handle_approve_image_refresh_delete_old() {
		$this->verify_image_refresh_automation_action( 'mobo_core_approve_image_refresh_delete_old' );

		$backup_confirmed = isset( $_POST['mobo_backup_confirmed'] ) && '1' === sanitize_key( wp_unslash( $_POST['mobo_backup_confirmed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		if ( ! $backup_confirmed ) {
			$this->redirect_with_message( 'برای تأیید حذف دائمی، ابتدا تهیه Backup از دیتابیس و uploads را تأیید کنید.', 'warning', 'image-refresh' );
			return;
		}

		$automation = new Mobo_Core_Image_Refresh_Automation();
		$result     = $automation->approve_delete_old();
		$success    = ! empty( $result['success'] );
		if ( $success && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'image-refresh-auto-delete-old', true );
		}

		$this->redirect_with_message( isset( $result['message'] ) ? (string) $result['message'] : 'تایید مرحله ثبت نشد.', $success ? 'success' : 'warning', 'image-refresh' );
	}

	/**
	 * Approve automatic deletion of orphan raster families for the current cycle.
	 *
	 * @return void
	 */
	public function handle_approve_image_refresh_delete_orphans() {
		$this->verify_image_refresh_automation_action( 'mobo_core_approve_image_refresh_delete_orphans' );

		$automation = new Mobo_Core_Image_Refresh_Automation();
		$result     = $automation->approve_delete_orphans();
		$success    = ! empty( $result['success'] );
		if ( $success && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'image-refresh-auto-delete-orphans', true );
		}

		$this->redirect_with_message( isset( $result['message'] ) ? (string) $result['message'] : 'تایید مرحله ثبت نشد.', $success ? 'success' : 'warning', 'image-refresh' );
	}

	/**
	 * Run (or continue) the read-only legacy image scan for one bounded slice.
	 *
	 * @return void
	 */
	public function handle_legacy_image_audit_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}
		check_admin_referer( 'mobo_core_legacy_image_audit_run', 'mobo_core_nonce' );
		if ( ! class_exists( 'Mobo_Core_Legacy_Image_Audit' ) ) {
			$this->redirect_with_message( 'ماژول بررسی تصاویر قدیمی در دسترس نیست.', 'error', 'image-refresh' );
			return;
		}
		$audit = new Mobo_Core_Legacy_Image_Audit();
		$state = $audit->run( 8.0, Mobo_Core_Legacy_Image_Audit::BATCH_DEFAULT );
		$done  = 'complete' === $state['status'];
		$msg   = '' !== (string) $state['lastError']
			? 'بررسی تصاویر قدیمی متوقف ماند: ' . (string) $state['lastError']
			: ( $done
				? sprintf( 'بررسی فقط‌خواندنی تصاویر قدیمی کامل شد: %s پیوست JPG/PNG بررسی شد. هیچ فایلی تغییر نکرد.', number_format_i18n( absint( $state['scanned'] ) ) )
				: sprintf( 'بخشی از بررسی انجام شد (%s پیوست). ادامه خودکار تا پایان یا تا زدن «توقف بررسی».', number_format_i18n( absint( $state['scanned'] ) ) ) );
		$this->redirect_with_message( $msg, '' !== (string) $state['lastError'] ? 'warning' : 'success', 'image-refresh' );
	}

	/**
	 * Stop the read-only legacy image scan.
	 *
	 * @return void
	 */
	public function handle_legacy_image_audit_stop() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}
		check_admin_referer( 'mobo_core_legacy_image_audit_stop', 'mobo_core_nonce' );
		if ( class_exists( 'Mobo_Core_Legacy_Image_Audit' ) ) {
			( new Mobo_Core_Legacy_Image_Audit() )->request_stop();
		}
		$this->redirect_with_message( 'بررسی تصاویر قدیمی متوقف شد. نتایج تا همین نقطه حفظ شده و با «ادامه بررسی» از همان‌جا ادامه می‌یابد.', 'success', 'image-refresh' );
	}

	/**
	 * Forget legacy scan results (no media changes).
	 *
	 * @return void
	 */
	public function handle_legacy_image_audit_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}
		check_admin_referer( 'mobo_core_legacy_image_audit_reset', 'mobo_core_nonce' );
		if ( class_exists( 'Mobo_Core_Legacy_Image_Audit' ) ) {
			( new Mobo_Core_Legacy_Image_Audit() )->reset();
		}
		$this->redirect_with_message( 'نتایج بررسی تصاویر قدیمی پاک شد. هیچ تصویری تغییر نکرد.', 'success', 'image-refresh' );
	}

	/**
	 * Validate common automation admin actions.
	 *
	 * @param string $nonce_action Nonce action.
	 * @param bool   $require_repair Whether legacy manual actions require completed Repair.
	 * @return void
	 */
	private function verify_image_refresh_automation_action( $nonce_action, $require_repair = true ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( $nonce_action, 'mobo_core_nonce' );
		if ( ! class_exists( 'Mobo_Core_Image_Refresh_Automation' ) ) {
			$this->redirect_with_message( 'ماژول اجرای خودکار نوسازی تصاویر در دسترس نیست.', 'error', 'image-refresh' );
			exit;
		}
		if ( $require_repair && $this->redirect_if_image_refresh_locked() ) {
			exit;
		}
	}

	/**
	 * Clear automation tracking when workflow state is reset.
	 *
	 * @return void
	 */
	private function reset_image_refresh_automation_tracking() {
		$options = array(
			'mobo_core_image_refresh_automation_enabled',
			'mobo_core_image_refresh_auto_delete_old_approved',
			'mobo_core_image_refresh_auto_delete_orphan_approved',
			'mobo_core_image_refresh_automation_started_at',
			'mobo_core_image_refresh_automation_completed_at',
			'mobo_core_image_refresh_automation_last_result',
			'mobo_core_image_refresh_automation_last_run_at',
			'mobo_core_image_refresh_subsize_retry_count',
			'mobo_core_image_refresh_subsize_retry_at',
			'mobo_core_image_refresh_subsize_quarantined',
			'mobo_core_image_refresh_repair_retry_count',
			'mobo_core_image_refresh_repair_retry_at',
		);

		foreach ( $options as $option ) {
			Mobo_Core_Settings::delete( $option );
		}
	}

	/**
	 * Scan old Mobo images.
	 *
	 * @return void
	 */
	public function handle_scan_legacy_images() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_scan_legacy_images', 'mobo_core_nonce' );

		if ( $this->redirect_if_image_refresh_locked() ) {
			return;
		}

		if ( $this->redirect_if_image_refresh_step_blocked( 1 ) ) {
			return;
		}

		$service = new Mobo_Core_Image_Refresh_Service();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_scan_limit', 50, 10, 5000 );
		$result  = $service->scan_legacy_images( $limit );

		$progress = ! empty( $result['cycleComplete'] ) ? 'دوره پیمایش کامل شد.' : 'ادامه پیمایش از شناسه پیوست ' . absint( isset( $result['cursorEnd'] ) ? $result['cursorEnd'] : 0 ) . '.';

		$this->redirect_with_message(
			sprintf( 'جمع اسکن دوره جاری: %d پیوست، %d تصویر قدیمی، %d قابل صف شدن، %d محصول محلی بدون تصویر، حجم خانواده های قدیمی %s. %s', absint( isset( $result['scanned'] ) ? $result['scanned'] : 0 ), absint( $result['legacyRaster'] ), absint( $result['queueable'] ), absint( isset( $result['missingImageProducts'] ) ? $result['missingImageProducts'] : 0 ), $this->format_bytes( isset( $result['totalLegacyBytes'] ) ? $result['totalLegacyBytes'] : 0 ), $progress ),
			'success',
			'image-refresh'
		);
	}

	/**
	 * Enqueue legacy image refresh jobs.
	 *
	 * @return void
	 */
	public function handle_enqueue_image_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_enqueue_image_refresh', 'mobo_core_nonce' );

		if ( $this->redirect_if_image_refresh_locked() ) {
			return;
		}

		if ( $this->redirect_if_image_refresh_step_blocked( 2 ) ) {
			return;
		}

		$service = new Mobo_Core_Image_Refresh_Service();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_scan_limit', 50, 10, 5000 );
		$result  = $service->enqueue_legacy_images( $limit );

		$progress = ! empty( $result['cycleComplete'] ) ? 'دوره پیمایش کامل شد.' : 'ادامه پیمایش از شناسه پیوست ' . absint( isset( $result['cursorEnd'] ) ? $result['cursorEnd'] : 0 ) . '.';
		$changed  = absint( isset( $result['enqueued'] ) ? $result['enqueued'] : 0 ) + absint( isset( $result['requeued'] ) ? $result['requeued'] : 0 ) + absint( isset( $result['missingImageQueued'] ) ? $result['missingImageQueued'] : 0 );

		$this->redirect_with_message(
			sprintf( 'جمع ساخت صف دوره جاری: نوسازی قدیمی جدید %d، بازگشت به صف %d، از قبل در صف %d، قبلا انجام شده %d، بدون نشانی تصویر جدید %d، رد شده %d؛ محصولات بدون تصویر صف شده %d، رد شده %d، ناموفق %d. %s', absint( isset( $result['enqueued'] ) ? $result['enqueued'] : 0 ), absint( isset( $result['requeued'] ) ? $result['requeued'] : 0 ), absint( isset( $result['alreadyQueued'] ) ? $result['alreadyQueued'] : 0 ), absint( isset( $result['alreadyDone'] ) ? $result['alreadyDone'] : 0 ), absint( isset( $result['withoutSourceUrl'] ) ? $result['withoutSourceUrl'] : 0 ), absint( isset( $result['skipped'] ) ? $result['skipped'] : 0 ), absint( isset( $result['missingImageQueued'] ) ? $result['missingImageQueued'] : 0 ), absint( isset( $result['missingImageSkipped'] ) ? $result['missingImageSkipped'] : 0 ), absint( isset( $result['missingImageFailed'] ) ? $result['missingImageFailed'] : 0 ), $progress ),
			$changed > 0 ? 'success' : 'warning',
			'image-refresh'
		);
	}

	/**
	 * Process image refresh queue.
	 *
	 * @return void
	 */
	public function handle_process_image_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_process_image_refresh', 'mobo_core_nonce' );

		if ( $this->redirect_if_image_refresh_locked() ) {
			return;
		}

		if ( $this->redirect_if_image_refresh_step_blocked( 3 ) ) {
			return;
		}

		$service = new Mobo_Core_Image_Refresh_Service();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_per_run', 2, 1, 20 );
		$result  = $service->process_queue( $limit );

		if ( ! empty( $result['remaining'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'image-refresh-admin', true );
		}

		$this->redirect_with_message(
			sprintf( 'پردازش صف انجام شد: موفق %d، خطا %d، رد شده %d.', absint( isset( $result['processed'] ) ? $result['processed'] : 0 ), absint( isset( $result['failed'] ) ? $result['failed'] : 0 ), absint( isset( $result['skipped'] ) ? $result['skipped'] : 0 ) ),
			'failed' === ( isset( $result['status'] ) ? $result['status'] : '' ) ? 'error' : 'success',
			'image-refresh'
		);
	}


	/**
	 * Scan WebP attachment subsize health without changing files.
	 *
	 * @return void
	 */
	public function handle_scan_webp_subsizes() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_scan_webp_subsizes', 'mobo_core_nonce' );
		if ( $this->redirect_if_image_refresh_locked() ) {
			return;
		}

		if ( $this->redirect_if_image_refresh_step_blocked( 4 ) ) {
			return;
		}

		$service = new Mobo_Core_Image_Refresh_Service();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_scan_limit', 50, 10, 5000 );
		$result  = $service->audit_webp_subsizes( $limit, false );
		$progress = ! empty( $result['cycleComplete'] ) ? 'دوره پیمایش کامل شد.' : 'ادامه پیمایش از شناسه پیوست ' . absint( isset( $result['cursorEnd'] ) ? $result['cursorEnd'] : 0 ) . '.';

		$this->redirect_with_message(
			sprintf( 'اسکن برش ها: %d تصویر WebP بررسی شد، %d سالم و %d نیازمند بازسازی بود. ناتوانی موتور تصویر در بازسازی WebP: %d، فایل اصلی مفقود: %d. %s', absint( isset( $result['webpChecked'] ) ? $result['webpChecked'] : 0 ), absint( isset( $result['healthy'] ) ? $result['healthy'] : 0 ), absint( isset( $result['needsRepair'] ) ? $result['needsRepair'] : 0 ), absint( isset( $result['unsupportedEditor'] ) ? $result['unsupportedEditor'] : 0 ), absint( isset( $result['missingOriginal'] ) ? $result['missingOriginal'] : 0 ), $progress ),
			( ! empty( $result['needsRepair'] ) || ! empty( $result['unsupportedEditor'] ) ) ? 'warning' : 'success',
			'image-refresh'
		);
	}

	/**
	 * Regenerate missing WebP attachment subsizes and verify the result.
	 *
	 * @return void
	 */
	public function handle_repair_webp_subsizes() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_repair_webp_subsizes', 'mobo_core_nonce' );
		if ( $this->redirect_if_image_refresh_locked() ) {
			return;
		}

		if ( $this->redirect_if_image_refresh_step_blocked( 5 ) ) {
			return;
		}

		$service = new Mobo_Core_Image_Refresh_Service();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_scan_limit', 50, 10, 5000 );
		$result  = $service->audit_webp_subsizes( $limit, true );
		$progress = ! empty( $result['cycleComplete'] ) ? 'دوره پیمایش کامل شد.' : 'ادامه پیمایش از شناسه پیوست ' . absint( isset( $result['cursorEnd'] ) ? $result['cursorEnd'] : 0 ) . '.';

		$this->redirect_with_message(
			sprintf( 'بازسازی برش ها: %d تصویر نیازمند اصلاح بود، %d تصویر اصلاح شد، %d فایل برش ساخته شد و %d تصویر همچنان ناموفق است. %s', absint( isset( $result['needsRepair'] ) ? $result['needsRepair'] : 0 ), absint( isset( $result['repaired'] ) ? $result['repaired'] : 0 ), absint( isset( $result['generatedFiles'] ) ? $result['generatedFiles'] : 0 ), absint( isset( $result['failed'] ) ? $result['failed'] : 0 ), $progress ),
			! empty( $result['failed'] ) ? 'warning' : 'success',
			'image-refresh'
		);
	}


	/**
	 * Scan old attachments that were already replaced by healthy WebP images.
	 *
	 * @return void
	 */
	public function handle_scan_replaced_images() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_scan_replaced_images', 'mobo_core_nonce' );
		if ( $this->redirect_if_image_refresh_locked() ) {
			return;
		}

		if ( $this->redirect_if_image_refresh_step_blocked( 6 ) ) {
			return;
		}

		$service = new Mobo_Core_Image_Refresh_Service();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_scan_limit', 50, 10, 5000 );
		$result  = $service->audit_replaced_legacy_attachments( $limit, false );
		$progress = ! empty( $result['cycleComplete'] ) ? 'دوره پیمایش کامل شد.' : 'ادامه پیمایش از شناسه پیوست ' . absint( isset( $result['cursorEnd'] ) ? $result['cursorEnd'] : 0 ) . '.';

		/* If Stage 6 just proved the replacements safe and the persistent delete-old
		 * preference is already enabled, immediately hand Stage 7 to automation. */
		if ( ! empty( $result['cycleComplete'] )
			&& Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' )
			&& class_exists( 'Mobo_Core_Image_Refresh_Automation' ) ) {
			$automation = new Mobo_Core_Image_Refresh_Automation();
			$resume     = $automation->arm_delete_old_autodrain();
			if ( ! empty( $resume['armed'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::kick( 'image-refresh-stage6-to-stage7', true );
			}
		}

		$this->redirect_with_message(
			sprintf(
				'جمع اسکن پیوست های جایگزین شده در دوره جاری: %d بررسی شد، %d آماده حذف مستقیم، %d هنوز در محصول، %d دارای مرجع دیگر، %d کاندید انتقال مرجع، %d با جایگزین نامعتبر و %d با برش ناقص. %s',
				absint( isset( $result['scanned'] ) ? $result['scanned'] : 0 ),
				absint( isset( $result['ready'] ) ? $result['ready'] : 0 ),
				absint( isset( $result['stillUsed'] ) ? $result['stillUsed'] : 0 ),
				absint( isset( $result['externalReferences'] ) ? $result['externalReferences'] : 0 ),
				absint( isset( $result['migrationCandidates'] ) ? $result['migrationCandidates'] : 0 ),
				absint( isset( $result['invalidReplacement'] ) ? $result['invalidReplacement'] : 0 ),
				absint( isset( $result['invalidSubsizes'] ) ? $result['invalidSubsizes'] : 0 ),
				$progress
			),
			! empty( $result['ready'] ) || ! empty( $result['migrationCandidates'] ) || ! empty( $result['invalidReplacement'] ) || ! empty( $result['invalidSubsizes'] ) ? 'warning' : 'success',
			'image-refresh'
		);
	}

	/**
	 * Safely delete old attachments that were already replaced.
	 *
	 * @return void
	 */
	public function handle_delete_replaced_images() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_delete_replaced_images', 'mobo_core_nonce' );
		if ( $this->redirect_if_image_refresh_locked() ) {
			return;
		}

		if ( $this->redirect_if_image_refresh_step_blocked( 7 ) ) {
			return;
		}

		$service = new Mobo_Core_Image_Refresh_Service();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_orphan_image_delete_per_run', 20, 1, 200 );
		$result  = $service->audit_replaced_legacy_attachments( $limit, true );

		if ( 'disabled' === ( isset( $result['status'] ) ? $result['status'] : '' ) ) {
			$this->redirect_with_message( isset( $result['message'] ) ? (string) $result['message'] : 'حذف پیوست های قدیمی غیرفعال است.', 'warning', 'image-refresh' );
			return;
		}

		/* A manual Stage 7 click is now only the first bounded slice. When more safe
		 * work remains, arm the same automatic coordinator and let it converge without
		 * requiring another administrator click. */
		if ( class_exists( 'Mobo_Core_Image_Refresh_Automation' ) ) {
			$automation = new Mobo_Core_Image_Refresh_Automation();
			$resume     = $automation->arm_delete_old_autodrain();
			if ( ! empty( $resume['armed'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::kick( 'image-refresh-stage7-autodrain', true );
			}
		}

		$progress = ! empty( $result['cycleComplete'] ) ? 'دوره پیمایش کامل شد.' : 'ادامه پیمایش از شناسه پیوست ' . absint( isset( $result['cursorEnd'] ) ? $result['cursorEnd'] : 0 ) . '.';
		$this->redirect_with_message(
			sprintf(
				'جمع مرحله ۷ در دوره جاری: %d پیوست حذف شد، مراجع %d پیوست در %d ردیف منتقل شد، %d مورد با Safety Audit نگه داشته شد و %d خطای عملیاتی ثبت شد. %s',
				absint( isset( $result['deleted'] ) ? $result['deleted'] : 0 ),
				absint( isset( $result['referencesMigrated'] ) ? $result['referencesMigrated'] : 0 ),
				absint( isset( $result['referenceRowsUpdated'] ) ? $result['referenceRowsUpdated'] : 0 ),
				absint( isset( $result['blocked'] ) ? $result['blocked'] : 0 ),
				absint( isset( $result['errors'] ) ? $result['errors'] : 0 ),
				$progress
			),
			! empty( $result['blocked'] ) || ! empty( $result['errors'] ) ? 'warning' : 'success',
			'image-refresh'
		);
	}

	/**
	 * Retry failed image refresh rows.
	 *
	 * @return void
	 */
	public function handle_retry_image_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_retry_image_refresh', 'mobo_core_nonce' );

		if ( $this->redirect_if_image_refresh_locked() ) {
			return;
		}

		$workflow = $this->get_image_refresh_workflow_state();
		if ( empty( $workflow['flags']['canRetryFailed'] ) ) {
			$this->redirect_with_message( 'ردیف ناموفق قابل تلاش دوباره وجود ندارد یا یک پردازش دیگر فعال است.', 'warning', 'image-refresh' );
			return;
		}

		$queue   = new Mobo_Core_Image_Refresh_Queue();
		$updated = $queue->retry_failed();
		if ( $updated > 0 ) {
			$service = new Mobo_Core_Image_Refresh_Service();
			$service->invalidate_post_queue_verification_state();
		}

		$this->redirect_with_message( sprintf( '%d ردیف ناموفق دوباره در انتظار پردازش قرار گرفت. ردیف های رد شده عمدا تغییر نکردند.', absint( $updated ) ), $updated > 0 ? 'success' : 'warning', 'image-refresh' );
	}

	/**
	 * Reset image refresh queue.
	 *
	 * @return void
	 */
	public function handle_reset_image_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_reset_image_refresh', 'mobo_core_nonce' );

		if ( $this->redirect_if_image_refresh_locked() ) {
			return;
		}

		$workflow = $this->get_image_refresh_workflow_state();
		if ( empty( $workflow['flags']['canResetQueue'] ) ) {
			$this->redirect_with_message( 'ریست صف در وضعیت فعلی مجاز نیست؛ یا مرحله ۱ کامل نشده یا یک پردازش فعال است.', 'warning', 'image-refresh' );
			return;
		}

		$queue   = new Mobo_Core_Image_Refresh_Queue();
		$deleted = $queue->reset( false );
		$service = new Mobo_Core_Image_Refresh_Service();
		$service->reset_workflow_state( true );

		$orphan_deleted = 0;
		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			$cleanup        = new Mobo_Core_Orphan_Image_Cleanup();
			$orphan_deleted = $cleanup->reset( true );
		}

		Mobo_Core_Settings::update( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
		$this->reset_image_refresh_automation_tracking();

		$this->redirect_with_message(
			sprintf(
				'مرحله ۱ حفظ شد و صف و مراحل ۲ تا ۹ ریست شدند. ردیف صف حذف شده: %d، ردیف فهرست خانواده ها: %d. هیچ فایل یا محصولی تغییر نکرد.',
				absint( $deleted ),
				absint( $orphan_deleted )
			),
			'success',
			'image-refresh'
		);
	}

	/**
	 * Reset the complete image refresh workflow without touching media files.
	 *
	 * @return void
	 */
	public function handle_reset_image_refresh_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_reset_image_refresh_all', 'mobo_core_nonce' );

		if ( $this->redirect_if_image_refresh_locked() ) {
			return;
		}

		$workflow = $this->get_image_refresh_workflow_state();
		if ( empty( $workflow['flags']['canResetAll'] ) ) {
			$this->redirect_with_message( 'ریست کامل در وضعیت فعلی مجاز نیست؛ وضعیت ثبت شده ای وجود ندارد یا یک پردازش فعال است.', 'warning', 'image-refresh' );
			return;
		}

		$queue   = new Mobo_Core_Image_Refresh_Queue();
		$deleted = $queue->reset( false );
		$service = new Mobo_Core_Image_Refresh_Service();
		$service->reset_workflow_state( false );

		$orphan_deleted = 0;
		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			$cleanup        = new Mobo_Core_Orphan_Image_Cleanup();
			$orphan_deleted = $cleanup->reset( false );
		}

		Mobo_Core_Settings::update( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
		$this->reset_image_refresh_automation_tracking();

		$this->redirect_with_message(
			sprintf(
				'تمام وضعیت مراحل ۱ تا ۹ پاک شد و فرایند از مرحله ۱ شروع می شود. ردیف صف حذف شده: %d، ردیف فهرست خانواده ها: %d. هیچ فایل یا محصولی بازگردانی یا حذف نشد.',
				absint( $deleted ),
				absint( $orphan_deleted )
			),
			'success',
			'image-refresh'
		);
	}

	/**
	 * Scan orphan old raster files that match final Mobo WebP filenames.
	 *
	 * @return void
	 */
	public function handle_scan_orphan_images() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_scan_orphan_images', 'mobo_core_nonce' );

		if ( $this->redirect_if_image_refresh_locked() ) {
			return;
		}

		if ( $this->redirect_if_image_refresh_step_blocked( 8 ) ) {
			return;
		}

		$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_orphan_image_scan_limit', 50, 10, 5000 );
		$result  = $cleanup->scan( $limit );

		$progress = ! empty( $result['cycleComplete'] ) ? 'دوره پیمایش کامل شد.' : 'ادامه پیمایش از WebP شناسه پیوست ' . absint( isset( $result['cursorEnd'] ) ? $result['cursorEnd'] : 0 ) . '.';

		$this->redirect_with_message(
			sprintf( 'جمع اسکن خانواده ها در دوره جاری: %d WebP بررسی شد، %d خانواده شامل %d فایل آماده حذف شد، %d خانواده ثبت شده وردپرس و %d خانواده دارای مرجع نادیده گرفته شد؛ حجم قابل حذف %s. %s', absint( isset( $result['scannedWebp'] ) ? $result['scannedWebp'] : 0 ), absint( isset( $result['candidateFamilies'] ) ? $result['candidateFamilies'] : 0 ), absint( isset( $result['candidateFiles'] ) ? $result['candidateFiles'] : 0 ), absint( isset( $result['registeredFamilies'] ) ? $result['registeredFamilies'] : 0 ), absint( isset( $result['referencedFamilies'] ) ? $result['referencedFamilies'] : 0 ), $this->format_bytes( isset( $result['totalBytes'] ) ? $result['totalBytes'] : 0 ), $progress ),
			'success',
			'image-refresh'
		);
	}

	/**
	 * Delete safe orphan old raster files.
	 *
	 * @return void
	 */
	public function handle_delete_orphan_images() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_delete_orphan_images', 'mobo_core_nonce' );

		if ( $this->redirect_if_image_refresh_locked() ) {
			return;
		}

		if ( $this->redirect_if_image_refresh_step_blocked( 9 ) ) {
			return;
		}

		$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_orphan_image_delete_per_run', 20, 1, 200 );
		$result  = $cleanup->delete_candidates( $limit );

			$this->redirect_with_message(
				sprintf( 'حذف کنترل شده: %d خانواده و %d فایل حذف شد، %d خانواده رد شده و %d خانواده ناموفق شد؛ %d خانواده هنوز آماده حذف است؛ حجم آزاد شده %s.', absint( isset( $result['deletedFamilies'] ) ? $result['deletedFamilies'] : 0 ), absint( isset( $result['deletedFiles'] ) ? $result['deletedFiles'] : ( isset( $result['deleted'] ) ? $result['deleted'] : 0 ) ), absint( isset( $result['skippedFamilies'] ) ? $result['skippedFamilies'] : ( isset( $result['skipped'] ) ? $result['skipped'] : 0 ) ), absint( isset( $result['failedFamilies'] ) ? $result['failedFamilies'] : ( isset( $result['failed'] ) ? $result['failed'] : 0 ) ), absint( isset( $result['remainingFamilies'] ) ? $result['remainingFamilies'] : 0 ), $this->format_bytes( isset( $result['bytes'] ) ? $result['bytes'] : 0 ) ),
			! empty( $result['failedFamilies'] ) ? 'warning' : 'success',
			'image-refresh'
		);
	}

	/**
	 * Reset orphan file list only.
	 *
	 * @return void
	 */
	public function handle_reset_orphan_images() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_reset_orphan_images', 'mobo_core_nonce' );

		if ( $this->redirect_if_image_refresh_locked() ) {
			return;
		}

		$workflow = $this->get_image_refresh_workflow_state();
		if ( empty( $workflow['flags']['canResetOrphan'] ) ) {
			$this->redirect_with_message( 'فهرست مرحله ۸ هنوز ساخته نشده یا یک پردازش دیگر فعال است.', 'warning', 'image-refresh' );
			return;
		}

		$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
		$deleted = $cleanup->reset( true );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
		$this->reset_image_refresh_automation_tracking();

		$this->redirect_with_message( sprintf( 'فهرست خانواده های یتیم و cursor مرحله ۸ ریست شد. ردیف های حذف شده از فهرست: %d. هیچ فایل فیزیکی حذف نشد.', absint( $deleted ) ), 'success', 'image-refresh' );
	}

	// Pricing POST data is read only after handle_save_settings() verifies the admin nonce.
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	/**
	 * Save dynamic price rules.
	 *
	 * @return void
	 */
	private function save_dynamic_price_rules() {
		$rows = $this->collect_dynamic_price_rows_from_post();

		Mobo_Core_Settings::update(
			'mobo_dynamic_price',
			wp_json_encode( $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			false
		);
	}

	/**
	 * B12: validate the posted pricing policy before anything is saved.
	 *
	 * @return array<int,string> Persian errors (empty = valid).
	 */
	private function validate_pricing_post() {
		$type = isset( $_POST['mobo_price_type'] ) ? sanitize_key( wp_unslash( $_POST['mobo_price_type'] ) ) : (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' );
		if ( 'dynamic-price' !== $type || ! class_exists( 'Mobo_Core_Dynamic_Price_Rules' ) ) {
			return array();
		}
		return Mobo_Core_Dynamic_Price_Rules::coverage_problems( $this->collect_dynamic_price_rows_from_post() );
	}

	/**
	 * Normalized dynamic rows from the posted form.
	 *
	 * @return array
	 */
	private function collect_dynamic_price_rows_from_post() {
		$is_active_values    = isset( $_POST['mobo_dynamic_is_active'] ) && is_array( $_POST['mobo_dynamic_is_active'] ) ? wp_unslash( $_POST['mobo_dynamic_is_active'] ) : array();
		$low_values          = isset( $_POST['mobo_dynamic_low'] ) && is_array( $_POST['mobo_dynamic_low'] ) ? wp_unslash( $_POST['mobo_dynamic_low'] ) : array();
		$high_values         = isset( $_POST['mobo_dynamic_high'] ) && is_array( $_POST['mobo_dynamic_high'] ) ? wp_unslash( $_POST['mobo_dynamic_high'] ) : array();
		$benefit_type_values = isset( $_POST['mobo_dynamic_benefit_type'] ) && is_array( $_POST['mobo_dynamic_benefit_type'] ) ? wp_unslash( $_POST['mobo_dynamic_benefit_type'] ) : array();
		$benefit_values      = isset( $_POST['mobo_dynamic_benefit'] ) && is_array( $_POST['mobo_dynamic_benefit'] ) ? wp_unslash( $_POST['mobo_dynamic_benefit'] ) : array();

		$count = max(
			count( $is_active_values ),
			count( $low_values ),
			count( $high_values ),
			count( $benefit_type_values ),
			count( $benefit_values )
		);

		$rows = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$low_raw     = isset( $low_values[ $i ] ) ? wc_format_decimal( $low_values[ $i ] ) : '';
			$high_raw    = isset( $high_values[ $i ] ) ? wc_format_decimal( $high_values[ $i ] ) : '';
			$benefit_raw = isset( $benefit_values[ $i ] ) ? wc_format_decimal( $benefit_values[ $i ] ) : '';

			if ( '' === $low_raw && '' === $high_raw && '' === $benefit_raw ) {
				continue;
			}

			$benefit_type = isset( $benefit_type_values[ $i ] ) ? sanitize_key( $benefit_type_values[ $i ] ) : 'static';

			if ( ! in_array( $benefit_type, array( 'static', 'percentage' ), true ) ) {
				$benefit_type = 'static';
			}

			$is_active = isset( $is_active_values[ $i ] ) && 'false' === sanitize_text_field( $is_active_values[ $i ] ) ? 'false' : 'true';

			$rows[] = array(
				'is_active'    => $is_active,
				'low'          => is_numeric( $low_raw ) ? max( 0, (float) $low_raw ) : 0,
				'high'         => is_numeric( $high_raw ) ? max( 0, (float) $high_raw ) : 0,
				'benefit_type' => $benefit_type,
				'benefit'      => is_numeric( $benefit_raw ) ? max( 0, (float) $benefit_raw ) : 0,
			);
		}

		return $this->normalize_dynamic_price_rows( $rows );
	}

	/**
	 * Whether pricing fields are present in current POST payload.
	 *
	 * @return bool
	 */
	private function pricing_fields_are_present_in_post() {
		$fields = array(
			'mobo_price_type',
			'global_additional_price',
			'global_additional_percentage',
			'mobo_dynamic_is_active',
			'mobo_dynamic_low',
			'mobo_dynamic_high',
			'mobo_dynamic_benefit_type',
			'mobo_dynamic_benefit',
		);

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				return true;
			}
		}

		return false;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized


	/**
	 * Normalize dynamic pricing rows so active/store-visible ranges do not have gaps.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private function normalize_dynamic_price_rows( $rows ) {
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$normalized = array();
		$next_low   = 0;
		$last_index = count( $rows ) - 1;

		foreach ( array_values( $rows ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$low     = $next_low;
			$high    = isset( $row['high'] ) ? (float) $row['high'] : 0;
			$benefit = isset( $row['benefit'] ) ? max( 0, (float) $row['benefit'] ) : 0;

			if ( $index === $last_index ) {
				$high = $high > 0 ? max( $low, $high ) : 0;
			} else {
				$high = $high > 0 ? max( $low, $high ) : $low;
				$next_low = $high + 1;
			}

			$benefit_type = isset( $row['benefit_type'] ) ? sanitize_key( (string) $row['benefit_type'] ) : 'static';
			if ( ! in_array( $benefit_type, array( 'static', 'percentage' ), true ) ) {
				$benefit_type = 'static';
			}

			$is_active = isset( $row['is_active'] ) && 'false' === sanitize_text_field( (string) $row['is_active'] ) ? 'false' : 'true';

			$normalized[] = array(
				'is_active'    => $is_active,
				'low'          => $this->format_dynamic_price_number_for_storage( $low ),
				'high'         => $this->format_dynamic_price_number_for_storage( $high ),
				'benefit_type' => $benefit_type,
				'benefit'      => $this->format_dynamic_price_number_for_storage( $benefit ),
			);
		}

		return $normalized;
	}

	/**
	 * Format dynamic pricing number for stable option storage.
	 *
	 * @param float|int $value Value.
	 * @return float|int
	 */
	private function format_dynamic_price_number_for_storage( $value ) {
		$value = max( 0, (float) $value );

		if ( floor( $value ) === $value ) {
			return (int) $value;
		}

		return (float) wc_format_decimal( $value );
	}

	/**
	 * Format bytes for admin UI.
	 *
	 * @param mixed $bytes Bytes.
	 * @return string
	 */
	private function format_bytes( $bytes ) {
		$bytes = max( 0, (float) $bytes );

		if ( $bytes < 1024 ) {
			return absint( $bytes ) . ' B';
		}

		$units = array( 'KB', 'MB', 'GB', 'TB' );
		$value = $bytes / 1024;

		foreach ( $units as $unit ) {
			if ( $value < 1024 || 'TB' === $unit ) {
				return number_format_i18n( $value, 2 ) . ' ' . $unit;
			}

			$value = $value / 1024;
		}

		return absint( $bytes ) . ' B';
	}

	/**
	 * Redirect with message.
	 *
	 * @param string $message Message.
	 * @param string $type Type.
	 * @param string $tab Tab.
	 * @return void
	 */
	private function redirect_with_message( $message, $type = 'success', $tab = 'dashboard' ) {
		$url = $this->admin_page_url(
			array(
				'tab'          => sanitize_key( $tab ),
				'mobo_message' => rawurlencode( sanitize_text_field( (string) $message ) ),
				'mobo_type'    => sanitize_key( $type ),
			)
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Sanitize bool select value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function sanitize_bool_value( $value ) {
		return '1' === (string) $value ? '1' : '0';
	}

	/**
	 * Format a compact manager-facing "last checked" label.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function dashboard_checked_label( $timestamp ) {
		$timestamp = absint( $timestamp );
		if ( $timestamp < 1 ) {
			return 'بررسی نشده';
		}

		$now  = time();
		$diff = max( 0, $now - $timestamp );
		if ( $diff < MINUTE_IN_SECONDS ) {
			return 'همین حالا';
		}

		return human_time_diff( $timestamp, $now ) . ' پیش';
	}

	/**
	 * Format percent.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function format_percent( $value ) {
		$value = is_numeric( $value ) ? (float) $value : 0;

		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Resolve the user-facing Sync status from the complete runtime state.
	 *
	 * The raw status alone is not sufficient while a run is waiting for the
	 * central service or executing Repair, so every admin surface uses this
	 * helper to avoid contradictory labels.
	 *
	 * @param array $status Runtime Sync status.
	 * @return string
	 */
	private function sync_status_label_for_display( $status ) {
		if ( ! is_array( $status ) ) {
			return 'نامشخص';
		}

		if ( ! empty( $status['isWaitingForPortal'] ) ) {
			return 'در انتظار سرویس مرکزی';
		}

		if ( ! empty( $status['isRunning'] ) ) {
			return ! empty( $status['repairMode'] ) ? 'ترمیم کامل در حال اجرا' : 'همگام‌سازی در حال اجرا';
		}

		return $this->status_label( isset( $status['status'] ) ? $status['status'] : '' );
	}

	/**
	 * Status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function status_label( $status ) {
		switch ( sanitize_key( (string) $status ) ) {
			case 'running':
				return 'در حال اجرا';
			case 'done':
				return 'کامل شده';
			case 'waiting_for_portal':
				return 'در انتظار سرویس مرکزی';
			case 'cancelled':
				return 'متوقف شده';
			case 'idle':
				return 'شروع نشده';
			default:
				return 'نامشخص';
		}
	}

	/**
	 * Render scripts.
	 *
	 * @return void
	 */
	private function render_scripts() {
		?>
		<script>
			jQuery(function($) {
				/* C5: money previews use the WooCommerce store currency label. */
				var moboStoreCurrencyLabel = <?php echo wp_json_encode( $this->store_currency_label() ); ?>;

				$(document).on('click', '[data-mobo-copy-target]', function(e) {
					e.preventDefault();
					var targetId = String($(this).data('mobo-copy-target') || '');
					var $target = $('#' + targetId);
					var $status = $('[data-mobo-copy-status="' + targetId + '"]');
					var text = $target.length ? String($target.val() || '') : '';
					function done(ok) {
						$status.text(ok ? 'گزارش عیب‌یابی کپی شد.' : 'کپی خودکار ممکن نشد؛ متن را دستی انتخاب و کپی کنید.');
						if (!ok) { $target.removeAttr('hidden').attr('aria-hidden', 'false').css({width: '100%', minHeight: '160px'}).trigger('select'); }
					}
					if (navigator.clipboard && window.isSecureContext) {
						navigator.clipboard.writeText(text).then(function() { done(true); }, function() { done(false); });
						return;
					}
					try {
						$target.removeAttr('hidden');
						$target.trigger('select');
						var ok = document.execCommand('copy');
						$target.attr('hidden', 'hidden');
						done(ok);
					} catch (err) {
						done(false);
					}
				});

				function normalizeMoboNumberInputs(context) {
					var $context = context ? $(context) : $(document);
					$context.find('input[type="number"]').attr('dir', 'ltr').css('text-align', 'left');
				}


				function initAdminToolButtons() {
					$(document).on('click', '[data-mobo-admin-tool-action]', function(e) {
						e.preventDefault();

						var button = this;
						var action = button.getAttribute('data-mobo-admin-tool-action') || '';
						var tab = button.getAttribute('data-mobo-admin-tool-tab') || 'dashboard';
						var confirmText = button.getAttribute('data-mobo-admin-tool-confirm') || '';
						var config = document.querySelector('.mobo-admin-tool-config');

						if (!action || !config) {
							return;
						}

						if (confirmText && !window.confirm(confirmText)) {
							return;
						}

						var form = document.createElement('form');
						form.method = 'post';
						form.action = config.getAttribute('data-mobo-admin-tool-action-url') || ajaxurl;
						form.style.display = 'none';

						var fields = {
							action: action,
							mobo_tool_tab: tab,
							mobo_core_tool_nonce: config.getAttribute('data-mobo-admin-tool-nonce') || ''
						};

						Object.keys(fields).forEach(function(name) {
							var input = document.createElement('input');
							input.type = 'hidden';
							input.name = name;
							input.value = fields[name];
							form.appendChild(input);
						});

						document.body.appendChild(form);
						form.submit();
					});
				}

				function initTabSaveShortcuts() {
					$('.mobo-settings-form').each(function() {
						var $form = $(this);

						if ($form.is('[data-mobo-no-save-shortcut="1"]')) {
							return;
						}

						if ($form.children('.mobo-tab-savebar').length) {
							return;
						}

						var tab = $form.find('input[name="mobo_active_tab"]').val() || '';
						var label = 'ذخیره تنظیمات همین تب';

						if (tab === 'pricing') {
							label = 'ذخیره قیمت‌گذاری';
						} else if (tab === 'checkout') {
							label = 'ذخیره اعتبارسنجی و سفارش';
						} else if (tab === 'categories') {
							label = 'ذخیره دسته‌بندی همین تب';
						}

						var $bar = $('<div class="mobo-tab-savebar" />');
						$bar.append('<div><strong>ذخیره همین صفحه</strong><span>با این دکمه فقط تنظیمات همین تب ذخیره می‌شود؛ تب‌های دیگر تغییر نمی‌کنند.</span></div>');
						$bar.append($('<button type="submit" class="mobo-btn mobo-btn-primary" />').text(label));
						$form.prepend($bar);
					});
				}

				function initCategorySelect2(context) {
					if (!window.MoboCorePicker || typeof window.MoboCorePicker.refresh !== 'function') {
						return;
					}
					var node = context && context.jquery ? context.get(0) : context;
					window.MoboCorePicker.refresh(node || document.getElementById('mobo-core-admin'));
				}


				function initMoboShippingRuleCopyTools() {
					$(document).off('click.moboShippingCopyFirst', '[data-mobo-copy-first-shipping-rule]');
					$(document).on('click.moboShippingCopyFirst', '[data-mobo-copy-first-shipping-rule]', function(event) {
						event.preventDefault();
						event.stopPropagation();
						var $button = $(this);
						var scenario = String($button.data('scenario') || '');
						var $card = $button.closest('[data-mobo-shipping-scenario]');

						if (! $card.length) {
							$card = $('[data-mobo-shipping-scenario="' + scenario + '"]').first();
						}

						var $sourceState = $card.find('[data-mobo-shipping-state]').first();
						if (! $sourceState.length) {
							return;
						}

						var copied = 0;
						['before12', 'after12'].forEach(function(slot) {
							var $source = $sourceState.find('select[data-mobo-shipping-slot="' + slot + '"]').first();
							if (! $source.length) {
								return;
							}
							var value = $source.val() || '';
							$card.find('[data-mobo-shipping-state]').not($sourceState).each(function() {
								var $target = $(this).find('select[data-mobo-shipping-slot="' + slot + '"]').first();
								if (! $target.length) {
									return;
								}
								$target.val(value).trigger('change');
								copied++;
							});
						});

						var oldText = $button.text();
						$button.text(copied > 0 ? 'کپی شد' : 'استان دیگری برای کپی وجود ندارد');
						window.setTimeout(function() {
							$button.text(oldText);
						}, 1800);
					});
				}

				function formatMoboNumber(value) {
					var normalized = String(value || '').replace(/,/g, '').trim();

					if (normalized === '' || isNaN(normalized)) {
						return '';
					}

					var parts = normalized.split('.');
					parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');

					return parts.join('.');
				}

				function updatePricePreview(input) {
					var $input = $(input);
					var $preview = $input.closest('.mobo-price-input-wrap, .mobo-field').find('.mobo-price-preview').first();
					var formatted = formatMoboNumber($input.val());

					if (!$preview.length) {
						return;
					}

					if (formatted === '' || ($input.attr('name') === 'mobo_dynamic_high[]' && String($input.val()).trim() === '0')) {
						var isLastDynamicHigh = $input.attr('name') === 'mobo_dynamic_high[]' && $input.closest('.mobo-dynamic-row').is($('#mobo-dynamic-rows .mobo-dynamic-row').not('.mobo-dynamic-row-head').last());

						if (isLastDynamicHigh) {
							$preview.text('بدون سقف');
							return;
						}

						$preview.text($preview.data('empty') || '—');
						return;
					}

					if ($input.hasClass('mobo-benefit-input')) {
						var benefitType = $input.closest('.mobo-dynamic-row').find('select[name="mobo_dynamic_benefit_type[]"]').val();

						if (benefitType === 'percentage') {
							$preview.text(formatted + ' درصد');
							return;
						}
					}

					$preview.text(formatted + ' ' + moboStoreCurrencyLabel);
				}

				function updateAllPricePreviews(context) {
					var $context = context ? $(context) : $(document);
					$context.find('.mobo-money-input').each(function() {
						updatePricePreview(this);
					});
				}

				function parseMoboInteger(value) {
					var normalized = String(value || '').replace(/,/g, '').trim();

					if (normalized === '' || isNaN(normalized)) {
						return null;
					}

					return Math.max(0, Math.floor(Number(normalized)));
				}

				function normalizeDynamicRanges() {
					var $rows = $('#mobo-dynamic-rows .mobo-dynamic-row').not('.mobo-dynamic-row-head');
					var nextLow = 0;
					var warnings = [];

					$rows.each(function(index) {
						var $row = $(this);
						var isLast = index === $rows.length - 1;
						var $low = $row.find('input[name="mobo_dynamic_low[]"]');
						var $high = $row.find('input[name="mobo_dynamic_high[]"]');
						var high = parseMoboInteger($high.val());
						var rowNumber = index + 1;

						$low.val(String(nextLow));

						if (!isLast) {
							if (high === null || high < nextLow) {
								warnings.push('ردیف ' + rowNumber + ': سقف بازه را وارد کنید؛ در غیر این صورت هنگام ذخیره سقف برابر شروع همین بازه می‌شود.');
								nextLow = nextLow + 1;
							} else {
								nextLow = high + 1;
							}
						} else if (high !== null && high > 0 && high < nextLow) {
							warnings.push('ردیف آخر: سقف از شروع بازه کمتر است و هنگام ذخیره به شروع بازه اصلاح می‌شود.');
						}
					});

					updateAllPricePreviews($('#mobo-dynamic-rows'));

					var $status = $('#mobo-dynamic-range-status');
					if ($status.length) {
						if (warnings.length) {
							$status.html(warnings.slice(0, 3).join('<br>')).show();
						} else {
							$status.hide().text('');
						}
					}

					return false;
				}

				function suggestNextDynamicLow() {
					var $rows = $('#mobo-dynamic-rows .mobo-dynamic-row').not('.mobo-dynamic-row-head');
					var $last = $rows.last();

					if (!$last.length) {
						return '0';
					}

					var high = parseMoboInteger($last.find('input[name="mobo_dynamic_high[]"]').val());

					if (high !== null && high > 0) {
						return String(high + 1);
					}

					return '';
				}

				function switchPriceSection() {
					var selectedValue = $('input[name="mobo_price_type"]:checked').val();

					$('.mobo-price-types label').removeClass('active');
					$('input[name="mobo_price_type"]:checked').closest('label').addClass('active');

					$('.mobo-price-section').hide();
					$('#' + selectedValue).show();
				}

				$(document).on('change', 'input[name="mobo_price_type"]', switchPriceSection);

				$(document).on('click', '#mobo-add-price-rule', function() {
					var suggestedLow = suggestNextDynamicLow();
					var row = ''
						+ '<div class="mobo-dynamic-row">'
						+ '<select name="mobo_dynamic_is_active[]"><option value="true">بله</option><option value="false">خیر</option></select>'
						+ '<div class="mobo-price-input-wrap"><input type="number" dir="ltr" style="text-align:left;" class="mobo-money-input" name="mobo_dynamic_low[]" value="' + suggestedLow + '" min="0" step="1" readonly aria-readonly="true"><div class="mobo-price-preview" data-empty="—">—</div></div>'
						+ '<div class="mobo-price-input-wrap"><input type="number" dir="ltr" style="text-align:left;" class="mobo-money-input" name="mobo_dynamic_high[]" value="" min="0" step="1"><div class="mobo-price-preview" data-empty="—">—</div></div>'
						+ '<select name="mobo_dynamic_benefit_type[]"><option value="static">مبلغ ثابت</option><option value="percentage">درصدی</option></select>'
						+ '<div class="mobo-price-input-wrap"><input type="number" dir="ltr" style="text-align:left;" class="mobo-money-input mobo-benefit-input" name="mobo_dynamic_benefit[]" value="" min="0" step="0.01"><div class="mobo-price-preview" data-empty="—">—</div></div>'
						+ '<button type="button" class="mobo-remove-row" aria-label="حذف">×</button>'
						+ '</div>';

					$('#mobo-dynamic-rows').append(row);
					normalizeMoboNumberInputs($('#mobo-dynamic-rows').children().last());
					updateAllPricePreviews($('#mobo-dynamic-rows').children().last());
					normalizeDynamicRanges();
				});

				$(document).on('input change', '.mobo-money-input', function() {
					updatePricePreview(this);
				});

				$(document).on('blur', 'input[name="mobo_dynamic_high[]"]', function() {
					normalizeDynamicRanges();
				});

				$(document).on('change', 'select[name="mobo_dynamic_benefit_type[]"]', function() {
					updatePricePreview($(this).closest('.mobo-dynamic-row').find('.mobo-benefit-input'));
				});

				$(document).on('click', '.mobo-remove-row', function() {
					var rows = $('.mobo-dynamic-row').not('.mobo-dynamic-row-head');

					if (rows.length <= 1) {
						$(this).closest('.mobo-dynamic-row').find('input').val('');
						updateAllPricePreviews($('#mobo-dynamic-rows'));
						normalizeDynamicRanges();
						return;
					}

					$(this).closest('.mobo-dynamic-row').remove();
					updateAllPricePreviews($('#mobo-dynamic-rows'));
					normalizeDynamicRanges();
				});

				$(document).on('submit', '[data-mobo-cancel-sync-form]', function() {
					var $button = $(this).find('[data-mobo-cancel-sync-button]');
					$button.prop('disabled', true).text('در حال توقف...');
					$('[data-mobo-sync-refresh-state]').text('درخواست توقف ثبت شد...');
				});


				function initSyncStatusAutoRefresh() {
					var $card = $('#mobo-sync-status-card');

					if (! $card.length) {
						return;
					}

					var ajaxUrl = $card.data('ajax-url');
					var nonce = $card.data('nonce');
					var busy = false;

					function setMessage(name, value) {
						var $message = $('[data-mobo-sync-message="' + name + '"]');
						value = value || '';

						if (! $message.length) {
							return;
						}

						$message.text(value);

						if (value === '') {
							$message.hide();
						} else {
							$message.show();
						}
					}

					function applyStatus(status) {
						if (! status) {
							return;
						}

						var percent = parseFloat(status.progressPercent || 0);
						percent = Math.max(0, Math.min(100, isNaN(percent) ? 0 : percent));
						var statusLabel = status.statusLabel || status.status || '—';

						$('[data-mobo-sync-field="progressPercent"]').text(status.progressPercentLabel || percent);
						$('[data-mobo-sync-progress-bar]').css('width', percent + '%');
						$('[data-mobo-sync-field="statusLabel"]').text(statusLabel);
						$('[data-mobo-sync-field="syncId"]').text(status.syncId || '—');
						$('[data-mobo-sync-field="processedProducts"]').text(status.processedProducts || 0);
						$('[data-mobo-sync-field="remainingProducts"]').text(status.remainingProducts || 0);
						$('[data-mobo-sync-field="productPage"]').text(status.productPage || 0);
						$('[data-mobo-sync-field="variantPage"]').text(status.variantPage || 0);
						$('[data-mobo-sync-field="nextRetryAt"]').text(status.nextRetryAtLabel || '—');
						setMessage('lastMessage', status.lastMessage || '');
						setMessage('lastError', status.lastError || '');
						setMessage('lastTransientError', status.lastTransientError || '');
						var $syncLine = $('[data-mobo-job-status-line="sync"]');
						if ($syncLine.length) {
							$syncLine.text(status.statusLine || '').toggle(!!status.statusLine).toggleClass('mobo-message-error', status.health === 'stalled').toggleClass('mobo-message-info', status.health !== 'stalled');
						}
						$('[data-mobo-sync-updated-at]').text(status.serverTime ? 'آخرین بررسی: ' + status.serverTime : '');

						var $hero = $('[data-mobo-sync-hero-status]');
						$hero.text(statusLabel);
						$('[data-mobo-dashboard-sync-summary]').text(statusLabel);
						$hero.toggleClass('is-running', !!status.isRunning).toggleClass('is-idle', !status.isRunning && !status.isWaitingForPortal).toggleClass('is-waiting', !!status.isWaitingForPortal);
					}

					function refreshStatus() {
						if (busy) {
							return;
						}

						busy = true;
						$('[data-mobo-sync-refresh-state]').text('در حال دریافت وضعیت...');

						$.post(ajaxUrl, {
							action: 'mobo_core_get_sync_status',
							nonce: nonce
						}).done(function(response) {
							if (response && response.success) {
								applyStatus(response.data);
								var fallback = response.data && response.data.adminFallback ? response.data.adminFallback : null;
								if (fallback && fallback.attempted && parseInt(fallback.steps || 0, 10) > 0) {
									$('[data-mobo-sync-refresh-state]').text('Self Runner کند/مسدود است؛ مرورگر ' + fallback.steps + ' مرحله امن را جلو برد.');
								} else {
									$('[data-mobo-sync-refresh-state]').text('به‌روزرسانی خودکار فعال است.');
								}
							} else {
								$('[data-mobo-sync-refresh-state]').text('خطا در دریافت وضعیت.');
							}
						}).fail(function() {
							$('[data-mobo-sync-refresh-state]').text('خطا در دریافت وضعیت.');
						}).always(function() {
							busy = false;
						});
					}

					refreshStatus();
					window.setInterval(refreshStatus, 3000);
				}


				function initRepriceStatusAutoRefresh() {
					var $card = $('#mobo-reprice-status-card');

					if (! $card.length || String($card.data('auto-refresh')) !== '1') {
						return;
					}

					var ajaxUrl = $card.data('ajax-url');
					var nonce = $card.data('nonce');
					var busy = false;
					var timer = null;
					var reloadScheduled = false;
					var formSubmitting = false;

					/* A reload scheduled when the run ends must never cancel an admin's own
					 * form submission (e.g. «توقف اعمال قیمت» clicked as the last batch
					 * finishes): the newer navigation would silently drop the POST result. */
					$(document).on('submit', 'form', function(event) {
						if (! event.isDefaultPrevented()) {
							formSubmitting = true;
							if (timer) {
								window.clearInterval(timer);
							}
						}
					});

					function setRepriceMessage(name, value) {
						var $message = $('[data-mobo-reprice-message="' + name + '"]');
						value = value || '';

						if (! $message.length) {
							return;
						}

						$message.text(value);
						if (value === '') {
							$message.hide();
						} else {
							$message.show();
						}
					}

					function applyRepriceStatus(status) {
						if (! status) {
							return;
						}

						var percent = parseFloat(status.percent || 0);
						percent = Math.max(0, Math.min(100, isNaN(percent) ? 0 : percent));

						$('[data-mobo-reprice-field="percent"]').text(status.percentLabel || percent);
						$('[data-mobo-reprice-progress-bar]').css('width', percent + '%');
						$('[data-mobo-reprice-field="statusLabel"]').text(status.statusLabel || status.status || '—');
						$('[data-mobo-reprice-field="total"]').text(status.totalAvailable === false ? '—' : (status.total || 0));
						$('[data-mobo-reprice-field="processed"]').text(status.processed || 0);
						$('[data-mobo-reprice-field="updated"]').text(status.updated || 0);
						$('[data-mobo-reprice-field="failed"]').text(status.failed || 0);
						$('[data-mobo-reprice-field="lastPostId"]').text(status.lastPostId || 0);
						setRepriceMessage('lastMessage', status.lastMessage || '');
						setRepriceMessage('lastError', status.lastError || '');
						var $statusLine = $('[data-mobo-reprice-field="statusLine"]');
						if ($statusLine.length) {
							$statusLine.text(status.statusLine || '');
							$statusLine.toggle(!!status.statusLine);
							$statusLine.toggleClass('mobo-message-error', status.health === 'stalled').toggleClass('mobo-message-info', status.health !== 'stalled');
						}
						$('[data-mobo-reprice-updated-at]').text(status.serverTime ? 'آخرین بررسی: ' + status.serverTime : '');

						var failed = parseInt(status.failed || 0, 10);
						var $failureSummary = $('[data-mobo-reprice-failure-summary]');
						if ($failureSummary.length) {
							if (failed > 0) {
								$failureSummary.text(failed + ' تلاش ناموفق در این اجرا ثبت شده است. این شمارنده تاریخچه تلاش‌هاست و لزوماً خطای حل‌نشده نیست.').show();
							} else {
								$failureSummary.hide().text('');
							}
						}

						if (! status.shouldContinue && ! reloadScheduled) {
							reloadScheduled = true;
							if (timer) {
								window.clearInterval(timer);
							}
							$('[data-mobo-reprice-refresh-state]').text('اجرای قیمت‌گذاری پایان یافته است؛ وضعیت نهایی در حال بارگذاری است.');
							window.setTimeout(function(){
								if (! formSubmitting) {
									window.location.reload();
								}
							}, 600);
						}
					}

					function refreshRepriceStatus() {
						if (busy || reloadScheduled || formSubmitting) {
							return;
						}

						busy = true;
						$('[data-mobo-reprice-refresh-state]').text('در حال دریافت وضعیت قیمت‌گذاری...');

						$.post(ajaxUrl, {
							action: 'mobo_core_get_reprice_status',
							nonce: nonce
						}).done(function(response) {
							if (response && response.success) {
								applyRepriceStatus(response.data);
								if (!reloadScheduled) {
									$('[data-mobo-reprice-refresh-state]').text('به‌روزرسانی خودکار وضعیت قیمت‌گذاری فعال است.');
								}
							} else {
								$('[data-mobo-reprice-refresh-state]').text('خطا در دریافت وضعیت قیمت‌گذاری.');
							}
						}).fail(function() {
							$('[data-mobo-reprice-refresh-state]').text('خطا در دریافت وضعیت قیمت‌گذاری.');
						}).always(function() {
							busy = false;
						});
					}

					refreshRepriceStatus();
					timer = window.setInterval(refreshRepriceStatus, 3000);
				}


				function initRecategorizeStatusAutoRefresh() {
					var $card = $('#mobo-recategorize-status-card');

					if (! $card.length) {
						return;
					}

					var ajaxUrl = $card.data('ajax-url');
					var nonce = $card.data('nonce');
					var busy = false;

					function setRecategorizeMessage(name, value) {
						var $message = $('[data-mobo-recategorize-message="' + name + '"]');
						value = value || '';

						if (! $message.length) {
							return;
						}

						$message.text(value);
						if (value === '') {
							$message.hide();
						} else {
							$message.show();
						}
					}

					function applyRecategorizeStatus(status) {
						if (! status) {
							return;
						}

						var percent = parseFloat(status.percent || 0);
						percent = Math.max(0, Math.min(100, isNaN(percent) ? 0 : percent));

						$('[data-mobo-recategorize-field="percent"]').text(status.percentLabel || percent);
						$('[data-mobo-recategorize-progress-bar]').css('width', percent + '%');
						$('[data-mobo-recategorize-field="statusLabel"]').text(status.statusLabel || status.status || '—');
						$('[data-mobo-recategorize-field="total"]').text(status.total || 0);
						$('[data-mobo-recategorize-field="processed"]').text(status.processed || 0);
						$('[data-mobo-recategorize-field="updated"]').text(status.updated || 0);
						$('[data-mobo-recategorize-field="skipped"]').text(status.skipped || 0);
						$('[data-mobo-recategorize-field="failed"]').text(status.failed || 0);
						$('[data-mobo-recategorize-field="lastPostId"]').text(status.lastPostId || 0);
						setRecategorizeMessage('lastMessage', status.lastMessage || '');
						setRecategorizeMessage('lastError', status.lastError || '');
						var $recatLine = $('[data-mobo-job-status-line="recategorize"]');
						if ($recatLine.length) {
							$recatLine.text(status.statusLine || '').toggle(!!status.statusLine).toggleClass('mobo-message-error', status.health === 'stalled').toggleClass('mobo-message-info', status.health !== 'stalled');
						}
						$('[data-mobo-recategorize-updated-at]').text(status.serverTime ? 'آخرین بررسی: ' + status.serverTime : '');
					}

					function refreshRecategorizeStatus() {
						if (busy) {
							return;
						}

						busy = true;
						$('[data-mobo-recategorize-refresh-state]').text('در حال دریافت وضعیت دسته‌بندی...');

						$.post(ajaxUrl, {
							action: 'mobo_core_get_recategorize_status',
							nonce: nonce
						}).done(function(response) {
							if (response && response.success) {
								applyRecategorizeStatus(response.data);
								$('[data-mobo-recategorize-refresh-state]').text('به‌روزرسانی خودکار وضعیت دسته‌بندی فعال است.');
							} else {
								$('[data-mobo-recategorize-refresh-state]').text('خطا در دریافت وضعیت دسته‌بندی.');
							}
						}).fail(function() {
							$('[data-mobo-recategorize-refresh-state]').text('خطا در دریافت وضعیت دسته‌بندی.');
						}).always(function() {
							busy = false;
						});
					}

					refreshRecategorizeStatus();
					window.setInterval(refreshRecategorizeStatus, 3000);
				}


				

				function initImageRefreshLiveStatus() {
					if (window.MoboImageRefreshLiveStatusInitialized) {
						return;
					}

					var initialRoot = document.querySelector('[data-mobo-image-refresh-live-root]');
					if (!initialRoot) {
						return;
					}

					window.MoboImageRefreshLiveStatusInitialized = true;
					var busy = false;
					var dirty = false;
					var submitting = false;
					var consecutiveErrors = 0;
					var timer = null;

					function getRoot() {
						return document.querySelector('[data-mobo-image-refresh-live-root]');
					}

					function setLiveMessage(message, isError) {
						var root = getRoot();
						if (!root) return;
						var node = root.querySelector('[data-mobo-image-refresh-live-message]');
						if (node) {
							node.textContent = message;
							node.style.color = isError ? '#991b1b' : '';
						}
					}

					function pollingEnabled(root) {
						return !!root && root.getAttribute('data-live-polling') === '1';
					}

					function schedule(delay) {
						window.clearTimeout(timer);
						var root = getRoot();
						if (!pollingEnabled(root)) return;
						timer = window.setTimeout(refreshStatus, Math.max(1500, delay || 5000));
					}

					function currentInterval(root) {
						var configured = parseInt(root.getAttribute('data-refresh-interval') || '12000', 10);
						if (isNaN(configured) || configured < 3000) configured = 12000;
						return Math.min(60000, configured * Math.max(1, Math.pow(2, consecutiveErrors)));
					}

					function refreshStatus() {
						var root = getRoot();
						if (!root || !pollingEnabled(root)) {
							return;
						}

						if (document.hidden || busy || submitting) {
							schedule(currentInterval(root));
							return;
						}

						var activeElement = document.activeElement;
						if (activeElement && root.contains(activeElement) && /^(INPUT|SELECT|TEXTAREA)$/.test(activeElement.tagName || '')) {
							setLiveMessage('به روزرسانی خودکار تا پایان ویرایش فیلد فعلی متوقف است.', false);
							schedule(5000);
							return;
						}

						if (dirty) {
							setLiveMessage('به روزرسانی خودکار موقتا متوقف است؛ ابتدا تغییرات ذخیره نشده را ذخیره یا صفحه را بازخوانی کنید.', false);
							schedule(15000);
							return;
						}

						var ajaxUrl = root.getAttribute('data-ajax-url') || window.ajaxurl;
						var nonce = root.getAttribute('data-nonce') || '';
						if (!ajaxUrl || !nonce) {
							setLiveMessage('اطلاعات لازم برای به روزرسانی خودکار در دسترس نیست.', true);
							return;
						}

						busy = true;
						setLiveMessage('در حال بروزرسانی نمایش وضعیت...', false);

						$.post(ajaxUrl, {
							action: 'mobo_core_get_image_refresh_live_status',
							nonce: nonce
						}).done(function(response) {
							if (!response || !response.success || !response.data || !response.data.html) {
								consecutiveErrors++;
								setLiveMessage('دریافت وضعیت ناموفق بود؛ تلاش بعدی خودکار انجام می شود.', true);
								return;
							}

							var parser = new window.DOMParser();
							var doc = parser.parseFromString(response.data.html, 'text/html');
							var freshRoot = doc.querySelector('[data-mobo-image-refresh-live-root]');
							var currentRoot = getRoot();
							if (!freshRoot || !currentRoot) {
								consecutiveErrors++;
								setLiveMessage('پاسخ وضعیت قابل پردازش نبود.', true);
								return;
							}

							currentRoot.innerHTML = freshRoot.innerHTML;
							Array.prototype.slice.call(freshRoot.attributes).forEach(function(attribute) {
								currentRoot.setAttribute(attribute.name, attribute.value);
							});
							consecutiveErrors = 0;
							normalizeMoboNumberInputs(currentRoot);
							initTabSaveShortcuts();

							var timeNode = currentRoot.querySelector('[data-mobo-image-refresh-live-time]');
							if (timeNode) {
								timeNode.textContent = 'آخرین بروزرسانی صفحه: ' + (response.data.serverTime || 'همین حالا');
							}
							setLiveMessage('نمایش وضعیت بروزرسانی شد.', false);
						}).fail(function() {
							consecutiveErrors++;
							setLiveMessage('ارتباط برای دریافت وضعیت برقرار نشد؛ تلاش بعدی خودکار انجام می شود.', true);
						}).always(function() {
							busy = false;
							var latestRoot = getRoot();
							if (pollingEnabled(latestRoot)) schedule(currentInterval(latestRoot));
						});
					}

					$(document).on('input change', '[data-mobo-image-refresh-live-root] input, [data-mobo-image-refresh-live-root] select, [data-mobo-image-refresh-live-root] textarea', function() {
						dirty = true;
					});

					$(document).on('submit', '[data-mobo-image-refresh-live-root] form', function(event) {
						window.setTimeout(function() {
							if (!event.isDefaultPrevented()) {
								submitting = true;
							}
						}, 0);
					});

					document.addEventListener('visibilitychange', function() {
						var root = getRoot();
						if (!document.hidden && !dirty && !submitting && pollingEnabled(root)) {
							schedule(500);
						}
					});

					if (pollingEnabled(initialRoot)) {
						schedule(2500);
					}
				}


				function initCronPresets() {
					var presets = {
						vps: { label: 'VPS', values: {
							mobo_core_real_cron_time_budget_seconds: 45,
							mobo_core_real_cron_max_sync_steps: 7,
							mobo_core_real_cron_max_rounds: 200,
							mobo_core_real_cron_safety_margin_seconds: 3,
							mobo_core_real_cron_lock_ttl_seconds: 180,
							mobo_core_self_runner_enabled: '1',
							mobo_core_self_runner_continue_enabled: '1',
							mobo_core_self_runner_min_interval_seconds: 1,
							mobo_core_self_runner_http_timeout_seconds: 2,
							mobo_core_real_cron_process_webhooks: '1',
							mobo_core_adaptive_execution_enabled: '1',
							mobo_core_process_webhook_on_receive: '0',
							mobo_core_pull_payload_enabled: '1',
							mobo_core_payload_pull_timeout_seconds: 75,
							mobo_core_api_request_timeout_seconds: 75,
							mobo_core_transient_retry_max_try: 12,
							mobo_core_waiting_for_portal_retry_delay_seconds: 45
						} },
						strong: { label: 'هاست قوی', values: {
							mobo_core_real_cron_time_budget_seconds: 30,
							mobo_core_real_cron_max_sync_steps: 4,
							mobo_core_real_cron_max_rounds: 150,
							mobo_core_real_cron_safety_margin_seconds: 3,
							mobo_core_real_cron_lock_ttl_seconds: 150,
							mobo_core_self_runner_enabled: '1',
							mobo_core_self_runner_continue_enabled: '1',
							mobo_core_self_runner_min_interval_seconds: 2,
							mobo_core_self_runner_http_timeout_seconds: 1,
							mobo_core_real_cron_process_webhooks: '1',
							mobo_core_adaptive_execution_enabled: '1',
							mobo_core_process_webhook_on_receive: '0',
							mobo_core_pull_payload_enabled: '1',
							mobo_core_payload_pull_timeout_seconds: 60,
							mobo_core_api_request_timeout_seconds: 60,
							mobo_core_transient_retry_max_try: 10,
							mobo_core_waiting_for_portal_retry_delay_seconds: 60
						} },
						medium: { label: 'هاست متوسط', values: {
							mobo_core_real_cron_time_budget_seconds: 20,
							mobo_core_real_cron_max_sync_steps: 2,
							mobo_core_real_cron_max_rounds: 100,
							mobo_core_real_cron_safety_margin_seconds: 3,
							mobo_core_real_cron_lock_ttl_seconds: 120,
							mobo_core_self_runner_enabled: '1',
							mobo_core_self_runner_continue_enabled: '1',
							mobo_core_self_runner_min_interval_seconds: 5,
							mobo_core_self_runner_http_timeout_seconds: 1,
							mobo_core_real_cron_process_webhooks: '1',
							mobo_core_adaptive_execution_enabled: '1',
							mobo_core_process_webhook_on_receive: '0',
							mobo_core_pull_payload_enabled: '1',
							mobo_core_payload_pull_timeout_seconds: 45,
							mobo_core_api_request_timeout_seconds: 45,
							mobo_core_transient_retry_max_try: 8,
							mobo_core_waiting_for_portal_retry_delay_seconds: 90
						} },
						weak: { label: 'هاست ضعیف', values: {
							mobo_core_real_cron_time_budget_seconds: 10,
							mobo_core_real_cron_max_sync_steps: 1,
							mobo_core_real_cron_max_rounds: 50,
							mobo_core_real_cron_safety_margin_seconds: 2,
							mobo_core_real_cron_lock_ttl_seconds: 90,
							mobo_core_self_runner_enabled: '1',
							mobo_core_self_runner_continue_enabled: '1',
							mobo_core_self_runner_min_interval_seconds: 10,
							mobo_core_self_runner_http_timeout_seconds: 1,
							mobo_core_real_cron_process_webhooks: '1',
							mobo_core_adaptive_execution_enabled: '1',
							mobo_core_process_webhook_on_receive: '0',
							mobo_core_pull_payload_enabled: '1',
							mobo_core_payload_pull_timeout_seconds: 30,
							mobo_core_api_request_timeout_seconds: 30,
							mobo_core_transient_retry_max_try: 5,
							mobo_core_waiting_for_portal_retry_delay_seconds: 120
						} }
					};

					$(document).off('click.moboCronPreset', '[data-mobo-cron-preset]');
					$(document).on('click.moboCronPreset', '[data-mobo-cron-preset]', function(event) {
						event.preventDefault();
						var $button = $(this);
						var preset = presets[String($button.data('mobo-cron-preset') || '')];

						if (! preset) { return; }

						$.each(preset.values, function(fieldName, value) {
							var $field = $('[name="' + fieldName + '"]');
							if ($field.length) { $field.val(String(value)).trigger('change'); }
						});

						$('[data-mobo-cron-preset]').removeClass('button-primary').addClass('button-secondary');
						$button.removeClass('button-secondary').addClass('button-primary');
						$('[data-mobo-cron-preset-message]').text('ظرفیت Runner «' + preset.label + '» روی فرم اعمال شد. schedule واقعی هاست و «فاصله مورد انتظار Cron» تغییر نکردند؛ برای ثبت نهایی، دکمه ذخیره تنظیمات همین تب را بزنید.');
					});
				}

				function initQueuePresets() {
					var presets = {
						vps: { label: 'VPS', values: {
							mobo_core_sync_time_budget_seconds: 18,
							mobo_core_products_per_page: 6,
							mobo_core_variants_per_page: 35,
							mobo_core_images_per_run: 6,
							mobo_core_webhook_files_per_run: 8
						} },
						strong: { label: 'هاست قوی', values: {
							mobo_core_sync_time_budget_seconds: 12,
							mobo_core_products_per_page: 4,
							mobo_core_variants_per_page: 20,
							mobo_core_images_per_run: 4,
							mobo_core_webhook_files_per_run: 5
						} },
						medium: { label: 'هاست متوسط', values: {
							mobo_core_sync_time_budget_seconds: 8,
							mobo_core_products_per_page: 2,
							mobo_core_variants_per_page: 10,
							mobo_core_images_per_run: 2,
							mobo_core_webhook_files_per_run: 3
						} },
						weak: { label: 'هاست ضعیف', values: {
							mobo_core_sync_time_budget_seconds: 5,
							mobo_core_products_per_page: 1,
							mobo_core_variants_per_page: 5,
							mobo_core_images_per_run: 1,
							mobo_core_webhook_files_per_run: 1
						} }
					};

					$(document).off('click.moboQueuePreset', '[data-mobo-queue-preset]');
					$(document).on('click.moboQueuePreset', '[data-mobo-queue-preset]', function(event) {
						event.preventDefault();
						var $button = $(this);
						var key = String($button.data('mobo-queue-preset') || '');
						var preset = presets[key];

						if (! preset) { return; }

						$.each(preset.values, function(fieldName, value) {
							var $field = $('[name="' + fieldName + '"]');
							if ($field.length) { $field.val(String(value)).trigger('change'); }
						});

						$('[data-mobo-queue-preset]').removeClass('button-primary').addClass('button-secondary');
						$button.removeClass('button-secondary').addClass('button-primary');
						$('[data-mobo-queue-preset-message]').text('Baseline throughput «' + preset.label + '» روی فرم اعمال شد. Cursor، Retry، retention، Missing Variant و روشن/خاموش بودن صف‌ها تغییر نکردند. برای ثبت نهایی ذخیره کنید.');
					});
				}

				function initCategoryAutoMapping() {
					function normalizeCategoryName(value) {
						return String(value || '')
							.toLowerCase()
							.replace(/[يى]/g, 'ی')
							.replace(/ك/g, 'ک')
							.replace(/[\u200c\u200d\u200e\u200f]/g, ' ')
							.replace(/[^a-z0-9\u0600-\u06ff]+/g, ' ')
							.replace(/دسته\s*بندی/g, ' ')
							.replace(/(^|\s)(دسته|محصولات|محصول|category|products|product)(?=\s|$)/g, ' ')
							.replace(/\s+/g, ' ')
							.trim();
					}

					function levenshtein(a, b) {
						var previous = [], current = [], i, j;
						if (!a.length) { return b.length; }
						if (!b.length) { return a.length; }
						for (j = 0; j <= a.length; j++) { previous[j] = j; }
						for (i = 1; i <= b.length; i++) {
							current = [i];
							for (j = 1; j <= a.length; j++) {
								current[j] = b.charAt(i - 1) === a.charAt(j - 1) ? previous[j - 1] : Math.min(previous[j - 1] + 1, current[j - 1] + 1, previous[j] + 1);
							}
							previous = current;
						}
						return previous[a.length];
					}

					function similarity(a, b) {
						var max = Math.max(a.length, b.length);
						return max ? 1 - (levenshtein(a, b) / max) : 1;
					}

					function tokenOverlap(a, b) {
						var aTokens = a.split(' ').filter(Boolean), bTokens = b.split(' ').filter(Boolean), matches = 0;
						if (!aTokens.length || !bTokens.length) { return 0; }
						$.each(aTokens, function(index, token) { if ($.inArray(token, bTokens) !== -1) { matches++; } });
						return matches / Math.max(aTokens.length, bTokens.length);
					}

					function buildCategoryCandidates() {
						var $source = $('[data-mobo-category-map-select]').first();
						var candidates = [], nameCounts = {}, pathCounts = {}, exactName = {}, exactPath = {}, exactSlug = {};
						$source.find('option[value!="0"]').each(function() {
							var name = normalizeCategoryName($(this).data('term-name') || $(this).text());
							var path = normalizeCategoryName($(this).data('term-path') || $(this).text());
							var slug = normalizeCategoryName($(this).data('term-slug'));
							var candidate = { value: String(this.value), name: name, path: path, slug: slug };
							candidates.push(candidate);
							if (name) { nameCounts[name] = (nameCounts[name] || 0) + 1; exactName[name] = candidate.value; }
							if (path) { pathCounts[path] = (pathCounts[path] || 0) + 1; exactPath[path] = candidate.value; }
							if (slug && !exactSlug[slug]) { exactSlug[slug] = candidate.value; }
						});
						return { list: candidates, nameCounts: nameCounts, pathCounts: pathCounts, exactName: exactName, exactPath: exactPath, exactSlug: exactSlug };
					}

					function markSuggestion($select) {
						$select.closest('td').find('[data-mobo-category-suggestion]').prop('hidden', false);
						$select.closest('[data-mobo-category-row]').addClass('is-auto-suggested');
					}

					$(document).off('change.moboCategorySuggestion', '[data-mobo-category-map-select]');
					$(document).on('change.moboCategorySuggestion', '[data-mobo-category-map-select]', function() {
						var $select = $(this);
						if ($select.data('mobo-auto-applying')) { return; }
						$select.closest('td').find('[data-mobo-category-suggestion]').prop('hidden', true);
						$select.closest('[data-mobo-category-row]').removeClass('is-auto-suggested');
					});

					$(document).off('click.moboAutoCategoryMap', '[data-mobo-auto-map-categories]');
					$(document).on('click.moboAutoCategoryMap', '[data-mobo-auto-map-categories]', function() {
						var index = buildCategoryCandidates(), mapped = 0, unmatched = 0;
						$('[data-mobo-category-map-select]').each(function() {
							var $select = $(this), remoteName, remotePath, exactValue = '', ranked, best, second, safe;
							if (String($select.val() || '0') !== '0') { return; }
							remoteName = normalizeCategoryName($select.data('remote-name'));
							remotePath = normalizeCategoryName($select.data('remote-path'));
							if (!remoteName && !remotePath) { unmatched++; return; }

							if (remotePath && index.pathCounts[remotePath] === 1) { exactValue = index.exactPath[remotePath]; }
							else if (remoteName && index.nameCounts[remoteName] === 1) { exactValue = index.exactName[remoteName]; }
							if (exactValue) {
								$select.data('mobo-auto-applying', true).val(exactValue).trigger('change').removeData('mobo-auto-applying');
								markSuggestion($select); mapped++; return;
							}

							ranked = $.map(index.list, function(candidate) {
								var leafScore = remoteName && candidate.name ? Math.max(similarity(remoteName, candidate.name), tokenOverlap(remoteName, candidate.name)) : 0;
								var pathScore = remotePath && candidate.path ? Math.max(similarity(remotePath, candidate.path), tokenOverlap(remotePath, candidate.path)) : 0;
								var slugScore = remoteName && candidate.slug ? Math.max(similarity(remoteName, candidate.slug), tokenOverlap(remoteName, candidate.slug)) : 0;
								return { value: candidate.value, score: Math.max(pathScore, leafScore, slugScore) };
							});
							ranked.sort(function(a, b) { return b.score - a.score; });
							best = ranked[0]; second = ranked[1];
							safe = best && Math.max(remoteName.length, remotePath.length) >= 4 && best.score >= 0.91 && (!second || best.score - second.score >= 0.08);
							if (safe) {
								$select.data('mobo-auto-applying', true).val(best.value).trigger('change').removeData('mobo-auto-applying');
								markSuggestion($select); mapped++;
							} else { unmatched++; }
						});
						$('[data-mobo-auto-map-result]').text(mapped + ' پیشنهاد امن برای همین صفحه آماده شد؛ ' + unmatched + ' مورد نیاز به بررسی دستی دارد. هیچ پیشنهادی تا زمان ذخیره ثبت نمی‌شود.');
					});
				}


				window.MoboCoreInitSelect2 = initCategorySelect2; // Backward-compatible bridge for older inline tools.
				initCategorySelect2($('#mobo-core-admin'));

				document.addEventListener('toggle', function(event) {
					var details = event.target;
					if (details && details.open && details.matches && details.matches('#mobo-core-admin details')) {
						initCategorySelect2(details);
					}
				}, true);

				initCategoryAutoMapping();
				initMoboShippingRuleCopyTools();
				initQueuePresets();
					initCronPresets();
				initAdminToolButtons();
				normalizeMoboNumberInputs();
				switchPriceSection();
				updateAllPricePreviews();
				normalizeDynamicRanges();
				initSyncStatusAutoRefresh();
				initRepriceStatusAutoRefresh();
				initRecategorizeStatusAutoRefresh();
				initImageRefreshLiveStatus();
			});
		</script>
		<?php
	}


	/**
	 * Return installed/active status for Poina Domain Allowlist.
	 *
	 * @return array
	 */
	private function get_poina_domain_allowlist_status() {
		$status = array(
			'installed' => false,
			'active'    => false,
			'plugins'   => array(),
		);

		$plugins = $this->get_installed_plugins_safe();
		if ( empty( $plugins ) ) {
			return $status;
		}

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$plugin_file_lower = strtolower( (string) $plugin_file );
			$name = isset( $plugin_data['Name'] ) ? strtolower( (string) $plugin_data['Name'] ) : '';
			$description = isset( $plugin_data['Description'] ) ? strtolower( (string) $plugin_data['Description'] ) : '';

			if ( false === strpos( $plugin_file_lower, 'poina-domain-allowlist' ) && false === strpos( $name, 'poina' ) && false === strpos( $description, 'poina-domain-allowlist' ) ) {
				continue;
			}

			$status['installed'] = true;
			$status['plugins'][] = (string) $plugin_file;
			if ( $this->is_plugin_file_active_safe( (string) $plugin_file ) ) {
				$status['active'] = true;
			}
		}

		return $status;
	}


	/**
	 * Get installed plugins safely.
	 *
	 * @return array
	 */
	private function get_installed_plugins_safe() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			return array();
		}

		$plugins = get_plugins();
		return is_array( $plugins ) ? $plugins : array();
	}

	/**
	 * Check plugin active state safely, including network activation.
	 *
	 * @param string $plugin_file Plugin file.
	 * @return bool
	 */
	private function is_plugin_file_active_safe( $plugin_file ) {
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = false;
		if ( function_exists( 'is_plugin_active' ) ) {
			$active = is_plugin_active( $plugin_file );
		}

		if ( ! $active && function_exists( 'is_plugin_active_for_network' ) ) {
			$active = is_plugin_active_for_network( $plugin_file );
		}

		return (bool) $active;
	}


	/**
	 * Detect active Persian WooCommerce compatibility plugins.
	 *
	 * @return array
	 */
	private function get_active_persian_woocommerce_plugins() {
		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			$active = array();
		}

		$sitewide = is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : array();
		if ( is_array( $sitewide ) && ! empty( $sitewide ) ) {
			$active = array_merge( $active, array_keys( $sitewide ) );
		}

		$matches = array();
		foreach ( $active as $plugin_file ) {
			$plugin_file = (string) $plugin_file;
			$needle = strtolower( $plugin_file );
			if ( false === strpos( $needle, 'persian' ) && false === strpos( $needle, 'parsi' ) && false === strpos( $needle, 'iran' ) && false === strpos( $needle, 'woocommerce-persian' ) ) {
				continue;
			}
			if ( false === strpos( $needle, 'woocommerce' ) && false === strpos( $needle, 'woo' ) ) {
				continue;
			}

			$label = dirname( $plugin_file );
			if ( '.' === $label || '' === $label ) {
				$label = basename( $plugin_file );
			}
			$matches[] = $label;
		}

		return array_values( array_unique( array_filter( $matches ) ) );
	}

	/**
	 * Render styles.
	 *
	 * @return void
	 */
	private function render_styles() {
		?>
		<style>
			.mobo-wrap {
				max-width: 1180px;
			}

			.mobo-wrap input[type="number"] {
				direction: ltr !important;
				text-align: left !important;
			}

			.mobo-hero {
				margin: 22px 0 18px;
				padding: 22px 24px;
				border-radius: 24px;
				background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 50%, #7c3aed 100%);
				color: #fff;
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 20px;
				box-shadow: 0 18px 45px rgba(15, 23, 42, 0.22);
			}

			.mobo-hero h1 {
				color: #fff;
				margin: 0 0 8px;
				font-size: 24px;
				font-weight: 900;
			}

			.mobo-hero p {
				margin: 0;
				color: rgba(255,255,255,0.78);
				font-size: 14px;
			}

			.mobo-hero-badge {
				background: rgba(255,255,255,0.12);
				border: 1px solid rgba(255,255,255,0.22);
				border-radius: 18px;
				padding: 12px 16px;
				min-width: 160px;
				text-align: center;
			}

			.mobo-hero-badge span {
				display: block;
				font-size: 12px;
				color: rgba(255,255,255,0.72);
				margin-bottom: 5px;
			}

			.mobo-hero-badge strong {
				font-size: 14px;
				color: #fff;
			}

			.mobo-hero-badge strong.is-running {
				color: #a7f3d0;
			}

			.mobo-tabs {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin: 0 0 14px;
				background: #fff;
				padding: 10px;
				border-radius: 18px;
				border: 1px solid #e5e7eb;
				box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
			}

			.mobo-tabs a {
				text-decoration: none;
				color: #475569;
				padding: 10px 14px;
				border-radius: 13px;
				font-weight: 800;
				font-size: 13px;
				transition: all .16s ease;
			}

			.mobo-tabs a:hover {
				background: #f1f5f9;
				color: #111827;
			}

			.mobo-tabs a.active {
				background: #2563eb;
				color: #fff;
				box-shadow: 0 8px 18px rgba(37, 99, 235, 0.25);
			}

			.mobo-panel {
				margin-top: 14px;
			}

			.mobo-grid {
				display: grid;
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 16px;
			}

			.mobo-grid-dashboard {
				grid-template-columns: minmax(0, 1.45fr) minmax(320px, .55fr);
			}

			.mobo-card {
				background: #fff;
				border: 1px solid #e5e7eb;
				border-radius: 22px;
				padding: 18px;
				box-shadow: 0 12px 30px rgba(15, 23, 42, 0.05);
			}

			.mobo-card-wide {
				grid-column: auto;
			}

			.mobo-queue-preset-card {
				margin: 0 0 16px;
			}

			.mobo-queue-preset-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 10px;
				margin-bottom: 12px;
			}

			.mobo-queue-preset-actions .button {
				border-radius: 12px;
				font-weight: 800;
				min-height: 38px;
				padding: 4px 18px;
			}

			.mobo-card-head {
				margin-bottom: 16px;
				padding-bottom: 14px;
				border-bottom: 1px solid #eef2f7;
			}

			.mobo-card-head h2 {
				margin: 0 0 6px;
				font-size: 17px;
				font-weight: 900;
				color: #111827;
			}

			.mobo-card-head p {
				margin: 0;
				color: #64748b;
				font-size: 13px;
				line-height: 1.9;
			}

			.mobo-fields-grid {
				display: grid;
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 14px 16px;
			}

			.mobo-field {
				display: flex;
				flex-direction: column;
				gap: 7px;
			}

			.mobo-field-full {
				grid-column: 1 / -1;
			}

			.mobo-field label {
				font-size: 13px;
				font-weight: 900;
				color: #1f2937;
			}

			.mobo-field input,
			.mobo-field select,
			.mobo-field textarea,
			.mobo-dynamic-row input,
			.mobo-dynamic-row select {
				width: 100%;
				border: 1px solid #dbe3ef;
				border-radius: 13px;
				padding: 9px 11px;
				background: #fff;
				color: #111827;
				box-shadow: none;
				min-height: 42px;
			}

			.mobo-field textarea {
				min-height: 170px;
				direction: ltr;
				font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
			}

			.mobo-help {
				font-size: 12px;
				color: #64748b;
				line-height: 1.8;
			}

			.mobo-shipping-admin-card {
				width: 100%;
				max-width: 100%;
				box-sizing: border-box;
				background: #f9fafb;
			}

			.mobo-clock-panel {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 16px;
				margin: 14px 0;
				padding: 16px 18px;
				border-radius: 18px;
				border: 1px solid #bfdbfe;
				background: #eff6ff;
				box-sizing: border-box;
			}

			.mobo-clock-panel span,
			.mobo-clock-panel small {
				display: block;
				color: #475569;
			}

			.mobo-clock-panel strong {
				display: block;
				margin: 5px 0;
				font-size: 20px;
				font-weight: 900;
				color: #0f172a;
				direction: ltr;
				text-align: right;
			}

			.mobo-shipping-accordion {
				width: 100%;
				max-width: 100%;
				box-sizing: border-box;
				margin-top: 16px;
				border: 1px solid #dbeafe;
				border-radius: 18px;
				background: #fff;
				overflow: hidden;
			}

			.mobo-shipping-accordion-summary {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 16px;
				cursor: pointer;
				padding: 16px 18px;
				background: #f8fafc;
				box-sizing: border-box;
			}

			.mobo-shipping-accordion-summary strong,
			.mobo-shipping-accordion-summary small {
				display: block;
			}

			.mobo-shipping-accordion-summary strong {
				font-size: 15px;
				font-weight: 900;
				color: #111827;
			}

			.mobo-shipping-accordion-summary small {
				margin-top: 4px;
				color: #64748b;
				line-height: 1.8;
			}

			.mobo-shipping-accordion-body {
				padding: 16px;
				box-sizing: border-box;
			}

			.mobo-shipping-scenario-note {
				margin-bottom: 14px;
				width: 100%;
			}

			.mobo-shipping-state-card {
				width: 100%;
				max-width: 100%;
				box-sizing: border-box;
				background: #fff;
				border-color: #e5e7eb;
				margin-top: 14px;
			}

			.mobo-shipping-state-head h4 {
				margin: 0;
			}

			.mobo-shipping-state-head p {
				margin: 6px 0 0;
				color: #6b7280;
			}

			.mobo-shipping-slot-list {
				display: grid;
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 14px;
				width: 100%;
				max-width: 100%;
				box-sizing: border-box;
			}

			.mobo-shipping-slot-list .mobo-field-full {
				width: 100%;
				max-width: 100%;
				box-sizing: border-box;
				margin-top: 0;
			}

			.mobo-shipping-zone-locations {
				display: flex;
				flex-wrap: wrap;
				align-items: center;
				gap: 8px;
				margin-bottom: 14px;
			}

			.mobo-shipping-zone-locations > strong {
				margin-inline-end: 4px;
				color: #111827;
			}

			.mobo-shipping-location-badge {
				display: inline-flex;
				align-items: center;
				padding: 4px 10px;
				border-radius: 999px;
				background: #f1f5f9;
				border: 1px solid #e2e8f0;
				color: #334155;
				font-size: 12px;
			}

			.mobo-shipping-method-map-list {
				display: grid;
				grid-template-columns: 1fr;
				gap: 12px;
			}

			.mobo-shipping-method-map-card {
				display: grid;
				grid-template-columns: minmax(240px, 0.75fr) minmax(320px, 1.25fr);
				align-items: start;
				gap: 16px;
				width: 100%;
				max-width: 100%;
				box-sizing: border-box;
				margin-top: 0;
				border-color: #e5e7eb;
				background: #fff;
			}

			.mobo-shipping-wc-method-info strong,
			.mobo-shipping-wc-method-info span,
			.mobo-shipping-wc-method-info code {
				display: block;
			}

			.mobo-shipping-wc-method-info strong {
				font-size: 14px;
				font-weight: 900;
				color: #111827;
			}

			.mobo-shipping-wc-method-info span {
				margin-top: 5px;
				color: #64748b;
			}

			.mobo-shipping-wc-method-info code {
				margin-top: 8px;
				direction: ltr;
				text-align: left;
				background: #f8fafc;
				border: 1px solid #e2e8f0;
				border-radius: 8px;
				padding: 6px 8px;
			}

			.mobo-shipping-method-map-card .mobo-field-full {
				margin-top: 0;
			}


			.mobo-price-input-wrap {
				display: flex;
				flex-direction: column;
				gap: 5px;
			}

			.mobo-price-preview {
				font-size: 12px;
				font-weight: 800;
				color: #0f766e;
				background: #ecfdf5;
				border: 1px solid #bbf7d0;
				border-radius: 10px;
				padding: 5px 8px;
				min-height: 18px;
			}


			.mobo-address-map-result {
				margin: 10px 0 12px;
			}

			.mobo-wrap tr.mobo-map-row-matched td {
				background: #f0fdf4;
			}

			.mobo-wrap tr.mobo-map-row-ambiguous td {
				background: #fffbeb;
			}

			.mobo-wrap tr.mobo-map-row-missing td {
				background: #fff1f2;
			}

			.mobo-note {
				margin-top: 14px;
				background: #f8fafc;
				border: 1px solid #e2e8f0;
				border-radius: 16px;
				padding: 12px 14px;
				color: #475569;
				font-size: 13px;
				line-height: 1.9;
			}

			.mobo-card,
			.mobo-note,
			.mobo-message,
			.mobo-alert,
			.mobo-status-box {
				min-width: 0;
				max-width: 100%;
			}

			.mobo-note,
			.mobo-message,
			.mobo-alert,
			.mobo-status-box {
				overflow: hidden;
				overflow-wrap: anywhere;
				word-break: break-word;
			}

			.mobo-note pre,
			.mobo-log-box pre {
				box-sizing: border-box;
				max-width: 100%;
				max-height: 360px;
				overflow: auto;
				white-space: pre;
				word-break: normal;
				direction: ltr;
				text-align: left;
			}

			.mobo-collapsible-log {
				margin-top: 14px;
				border: 1px solid #e2e8f0;
				border-radius: 14px;
				background: #f8fafc;
				max-width: 100%;
				overflow: hidden;
			}

			.mobo-collapsible-log summary {
				cursor: pointer;
				padding: 12px 14px;
				font-weight: 800;
				color: #334155;
			}

			.mobo-log-box {
				border-top: 1px solid #e2e8f0;
				padding: 0 12px 12px;
				max-width: 100%;
				overflow: hidden;
			}

			.mobo-inline-action-form {
				margin: 14px 0;
			}

			.mobo-category-auto-map-actions {
				margin-bottom: 12px;
				align-items: center;
			}

			.mobo-guide-box {
				margin-top: 16px;
				border: 1px solid #bfdbfe;
				background: linear-gradient(180deg, #eff6ff 0%, #ffffff 100%);
				border-radius: 18px;
				padding: 16px;
			}

			.mobo-guide-title {
				font-size: 15px;
				font-weight: 900;
				color: #1e3a8a;
				margin-bottom: 12px;
			}

			.mobo-guide-summary {
				display: grid;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				gap: 10px;
				margin-bottom: 14px;
			}

			.mobo-guide-summary div {
				background: #fff;
				border: 1px solid #dbeafe;
				border-radius: 14px;
				padding: 11px 12px;
			}

			.mobo-guide-summary strong {
				display: block;
				font-size: 13px;
				font-weight: 900;
				color: #0f172a;
				margin-bottom: 5px;
			}

			.mobo-guide-summary span,
			.mobo-guide-flow span {
				display: block;
				font-size: 12px;
				line-height: 1.85;
				color: #475569;
			}

			.mobo-guide-table-wrap {
				overflow-x: auto;
				margin-top: 12px;
			}

			.mobo-guide-table th {
				font-weight: 900;
				color: #0f172a;
			}

			.mobo-guide-table td {
				vertical-align: top;
				line-height: 1.9;
				font-size: 12px;
			}

			.mobo-pill {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				border-radius: 999px;
				padding: 3px 9px;
				font-size: 11px;
				font-weight: 900;
			}

			.mobo-pill-on {
				background: #dcfce7;
				color: #166534;
			}

			.mobo-pill-off {
				background: #fee2e2;
				color: #991b1b;
			}

			.mobo-guide-flow {
				margin-top: 14px;
				background: #fff;
				border: 1px solid #e0f2fe;
				border-radius: 14px;
				padding: 12px 14px;
			}

			.mobo-guide-flow strong {
				display: block;
				margin-bottom: 6px;
				color: #075985;
			}

			.mobo-guide-warning {
				margin-top: 12px;
				background: #fffbeb;
				border: 1px solid #fde68a;
				border-radius: 14px;
				padding: 11px 12px;
				color: #92400e;
				font-size: 12px;
				font-weight: 800;
				line-height: 1.85;
			}

			.mobo-save-row {
				margin-top: 16px;
				display: flex;
				justify-content: flex-start;
			}


			.mobo-tab-savebar {
				position: sticky;
				top: 32px;
				z-index: 30;
				margin: 0 0 16px;
				padding: 12px 14px;
				border: 1px solid #bfdbfe;
				border-radius: 16px;
				background: rgba(239, 246, 255, 0.96);
				box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 14px;
			}

			.mobo-tab-savebar strong {
				display: block;
				font-size: 13px;
				font-weight: 900;
				color: #1e3a8a;
				margin-bottom: 4px;
			}

			.mobo-mini-list {
				margin-top: 10px;
				padding: 10px 12px;
				border-radius: 12px;
				background: rgba(255,255,255,.62);
				line-height: 1.9;
			}

			.mobo-tab-savebar span {
				display: block;
				font-size: 12px;
				font-weight: 700;
				color: #475569;
			}

			.mobo-btn {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				border: 0;
				border-radius: 14px;
				padding: 10px 16px;
				font-size: 13px;
				font-weight: 900;
				cursor: pointer;
				text-decoration: none;
				min-height: 42px;
			}

			.mobo-btn-primary {
				background: #2563eb;
				color: #fff;
				box-shadow: 0 8px 18px rgba(37, 99, 235, 0.24);
			}

			.mobo-btn-danger {
				background: #ef4444;
				color: #fff;
				box-shadow: 0 8px 18px rgba(239, 68, 68, 0.22);
			}

			.mobo-btn-light {
				background: #f1f5f9;
				color: #0f172a;
			}

			.mobo-btn:disabled,
			.mobo-btn[aria-disabled="true"] {
				cursor: not-allowed;
				opacity: .48;
				box-shadow: none;
				filter: grayscale(.35);
			}

			.mobo-actions form {
				display: flex;
				flex-direction: column;
				align-items: stretch;
				gap: 5px;
			}

			.mobo-disabled-reason {
				display: block;
				max-width: 440px;
				font-size: 11px;
				line-height: 1.7;
				color: #64748b;
			}

			.mobo-field-disabled {
				opacity: .72;
			}

			.mobo-actions {
				display: flex;
				flex-direction: column;
				gap: 10px;
			}


			.mobo-support-tools-card {
				margin-top: 16px;
				background: #f8fafc;
				border-style: dashed;
			}

			.mobo-support-tools-inline {
				margin-top: 14px;
				padding: 12px;
				border: 1px dashed #cbd5e1;
				border-radius: 16px;
				background: #f8fafc;
			}

			.mobo-support-tools-card summary,
			.mobo-support-tools-inline summary {
				cursor: pointer;
				font-size: 13px;
				font-weight: 900;
				color: #0f172a;
			}

			.mobo-support-tools-body {
				margin-top: 12px;
				padding-top: 12px;
				border-top: 1px solid #e2e8f0;
			}

			.mobo-support-tools-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 10px;
				margin-top: 12px;
			}

			.mobo-support-tools-actions .button {
				border-radius: 12px;
				font-weight: 800;
				min-height: 36px;
			}

			.mobo-progress-wrap {
				margin-bottom: 16px;
			}

			.mobo-progress-meta {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 8px;
				color: #475569;
			}

			.mobo-progress-meta strong {
				color: #111827;
				font-size: 18px;
			}

			.mobo-progress {
				height: 12px;
				background: #e5e7eb;
				border-radius: 999px;
				overflow: hidden;
			}

			.mobo-progress div {
				height: 100%;
				background: linear-gradient(90deg, #2563eb, #7c3aed);
				border-radius: 999px;
			}

			.mobo-status-grid {
				display: grid;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				gap: 12px;
			}

			.mobo-status-box {
				background: #f8fafc;
				border: 1px solid #e2e8f0;
				border-radius: 16px;
				padding: 12px;
			}

			.mobo-status-box span {
				display: block;
				font-size: 12px;
				color: #64748b;
				margin-bottom: 6px;
			}

			.mobo-status-box strong {
				display: block;
				font-size: 13px;
				color: #111827;
				word-break: break-word;
			}

			.mobo-message,

			.mobo-image-command-center {
				margin: 0 0 18px;
				padding: 20px;
				border: 2px solid #cbd5e1;
				border-radius: 20px;
				background: #fff;
				box-shadow: 0 12px 30px rgba(15,23,42,.08);
			}

			.mobo-image-command-center-running { border-color:#60a5fa; background:linear-gradient(180deg,#eff6ff 0,#fff 48%); }
			.mobo-image-command-center-success { border-color:#4ade80; background:linear-gradient(180deg,#f0fdf4 0,#fff 48%); }
			.mobo-image-command-center-warning { border-color:#fbbf24; background:linear-gradient(180deg,#fffbeb 0,#fff 48%); }
			.mobo-image-command-center-error { border-color:#f87171; background:linear-gradient(180deg,#fef2f2 0,#fff 48%); }
			.mobo-image-command-center-idle { border-color:#cbd5e1; background:linear-gradient(180deg,#f8fafc 0,#fff 48%); }

			.mobo-image-state-line { display:flex; align-items:center; gap:9px; flex-wrap:wrap; color:#475569; font-size:13px; }
			.mobo-image-state-line strong { font-size:15px; color:#0f172a; }
			.mobo-image-state-dot { width:12px; height:12px; border-radius:999px; background:#94a3b8; box-shadow:0 0 0 4px rgba(148,163,184,.15); }
			.mobo-image-command-center-running .mobo-image-state-dot { background:#2563eb; box-shadow:0 0 0 4px rgba(37,99,235,.16); }
			.mobo-image-command-center-success .mobo-image-state-dot { background:#16a34a; box-shadow:0 0 0 4px rgba(22,163,74,.16); }
			.mobo-image-command-center-warning .mobo-image-state-dot { background:#d97706; box-shadow:0 0 0 4px rgba(217,119,6,.16); }
			.mobo-image-command-center-error .mobo-image-state-dot { background:#dc2626; box-shadow:0 0 0 4px rgba(220,38,38,.16); }
			.mobo-image-state-dot.is-pulsing { animation:moboImagePulse 1.3s infinite; }
			@keyframes moboImagePulse { 0%{transform:scale(1);opacity:1} 60%{transform:scale(1.45);opacity:.55} 100%{transform:scale(1);opacity:1} }

			.mobo-image-command-main h2 { margin:12px 0 5px; font-size:24px; line-height:1.45; color:#0f172a; }
			.mobo-image-command-description { margin:0; color:#475569; font-size:14px; line-height:1.9; }
			.mobo-image-command-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-top:18px; }
			.mobo-image-command-grid > div { background:rgba(255,255,255,.82); border:1px solid #e2e8f0; border-radius:14px; padding:12px; min-width:0; }
			.mobo-image-command-grid span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
			.mobo-image-command-grid strong { display:block; color:#0f172a; font-size:15px; line-height:1.7; word-break:break-word; }
			.mobo-image-command-grid small { display:block; color:#64748b; margin-top:5px; line-height:1.65; }
			.mobo-image-progress-block { margin-top:16px; }
			.mobo-image-runner-line, .mobo-image-last-batch { margin-top:14px; border-radius:12px; padding:10px 12px; line-height:1.8; font-size:13px; }
			.mobo-image-runner-line { background:#f8fafc; border:1px solid #e2e8f0; }
			.mobo-image-runner-success { color:#166534; background:#f0fdf4; border-color:#bbf7d0; }
			.mobo-image-runner-warning { color:#92400e; background:#fffbeb; border-color:#fde68a; }
			.mobo-image-runner-error { color:#991b1b; background:#fef2f2; border-color:#fecaca; }
			.mobo-image-last-batch { background:#fff; border:1px dashed #cbd5e1; color:#334155; }
			.mobo-image-last-batch strong { margin-left:5px; color:#0f172a; }
			.mobo-image-timeline { display:grid; grid-template-columns:repeat(9,minmax(90px,1fr)); gap:7px; margin-top:18px; overflow-x:auto; padding-bottom:4px; }
			.mobo-image-timeline-item { min-width:90px; border:1px solid #e2e8f0; border-radius:12px; padding:9px; background:#f8fafc; color:#64748b; }
			.mobo-image-timeline-item > span { display:flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:999px; background:#e2e8f0; color:#475569; font-weight:900; margin-bottom:7px; }
			.mobo-image-timeline-item small { display:block; line-height:1.55; font-weight:800; }
			.mobo-image-timeline-item.is-done { border-color:#bbf7d0; background:#f0fdf4; color:#166534; }
			.mobo-image-timeline-item.is-done > span { background:#16a34a; color:#fff; }
			.mobo-image-timeline-item.is-active { border-color:#93c5fd; background:#eff6ff; color:#1d4ed8; box-shadow:0 0 0 2px rgba(37,99,235,.08); }
			.mobo-image-timeline-item.is-active > span { background:#2563eb; color:#fff; }
			.mobo-image-timeline-item.is-waiting { border-color:#fde68a; background:#fffbeb; color:#92400e; }
			.mobo-image-timeline-item.is-waiting > span { background:#d97706; color:#fff; }
			.mobo-image-timeline-item.is-error { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
			.mobo-image-timeline-item.is-error > span { background:#dc2626; color:#fff; }
			.mobo-image-timeline-item.is-paused { border-color:#cbd5e1; background:#f8fafc; color:#475569; }

			@media (max-width: 1100px) {
				.mobo-image-command-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
			}
			@media (max-width: 680px) {
				.mobo-image-command-center { padding:15px; }
				.mobo-image-command-main h2 { font-size:19px; }
				.mobo-image-command-grid { grid-template-columns:1fr; }
			}

			.mobo-alert {
				margin-top: 14px;
				padding: 12px 14px;
				border-radius: 14px;
				font-size: 13px;
				font-weight: 700;
				line-height: 1.8;
			}

			.mobo-message-info,
			.mobo-alert-success {
				background: #ecfdf5;
				border: 1px solid #bbf7d0;
				color: #166534;
			}

			.mobo-message-error,
			.mobo-alert-error {
				background: #fef2f2;
				border: 1px solid #fecaca;
				color: #991b1b;
			}

			.mobo-message-warning,
			.mobo-alert-warning {
				background: #fffbeb;
				border: 1px solid #fde68a;
				color: #92400e;
			}

			.mobo-auto-refresh {
				margin-top: 12px;
				display: flex;
				justify-content: space-between;
				gap: 10px;
				font-size: 12px;
				font-weight: 800;
				color: #64748b;
			}

			.mobo-image-refresh-live-bar {
				margin: 0 0 14px;
				padding: 10px 12px;
				border: 1px solid #bfdbfe;
				border-radius: 12px;
				background: #eff6ff;
				color: #1e3a8a;
			}

			.mobo-alert {
				margin: 0 0 14px;
			}

			.mobo-price-types {
				display: grid;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				gap: 10px;
				margin-bottom: 16px;
			}

			.mobo-price-types label {
				border: 1px solid #dbe3ef;
				background: #f8fafc;
				border-radius: 16px;
				padding: 12px;
				cursor: pointer;
				font-weight: 900;
				color: #334155;
				text-align: center;
			}

			.mobo-price-types label.active {
				border-color: #2563eb;
				background: #eff6ff;
				color: #1d4ed8;
				box-shadow: 0 8px 18px rgba(37,99,235,.08);
			}

			.mobo-price-types input {
				display: none;
			}

			.mobo-price-section {
				margin-top: 12px;
			}

			.mobo-dynamic-head {
				display: flex;
				align-items: center;
				justify-content: space-between;
				margin-bottom: 12px;
			}

			.mobo-dynamic-table {
				display: flex;
				flex-direction: column;
				gap: 8px;
			}

			.mobo-dynamic-row {
				display: grid;
				grid-template-columns: .7fr 1fr 1fr 1fr 1fr 44px;
				gap: 8px;
				align-items: center;
			}

			.mobo-dynamic-row-head {
				color: #64748b;
				font-size: 12px;
				font-weight: 900;
				padding: 0 4px;
			}

			.mobo-remove-row {
				width: 42px;
				height: 42px;
				border-radius: 13px;
				border: 0;
				background: #fee2e2;
				color: #991b1b;
				font-size: 20px;
				cursor: pointer;
			}

			@media (max-width: 960px) {
				.mobo-hero,
				.mobo-grid-dashboard {
					display: block;
				}

				.mobo-hero-badge {
					margin-top: 16px;
				}

				.mobo-grid {
					grid-template-columns: 1fr;
				}

				.mobo-card {
					margin-bottom: 16px;
				}

				.mobo-fields-grid,
				.mobo-status-grid,
				.mobo-price-types,
				.mobo-guide-summary {
					grid-template-columns: 1fr;
				}

				.mobo-clock-panel,
				.mobo-shipping-accordion-summary {
					flex-direction: column;
					align-items: flex-start;
				}

				.mobo-shipping-slot-list {
					grid-template-columns: 1fr;
				}

				.mobo-shipping-method-map-card {
					grid-template-columns: 1fr;
				}

				.mobo-dynamic-row {
					grid-template-columns: 1fr;
					border: 1px solid #e5e7eb;
					border-radius: 16px;
					padding: 10px;
				}

				.mobo-dynamic-row-head {
					display: none;
				}
			}

			.mobo-queue-settings-fieldset {
				border: 0;
				margin: 0;
				padding: 0;
				min-width: 0;
			}

			.mobo-queue-settings-fieldset[disabled] {
				opacity: .62;
			}

			.mobo-queue-settings-fieldset[disabled] .mobo-card,
			.mobo-queue-settings-fieldset[disabled] .mobo-guide-box {
				box-shadow: none;
			}

			.mobo-queue-settings-lock-message {
				margin-bottom: 18px;
				line-height: 1.9;
			}

		</style>
		<?php
	}
}
