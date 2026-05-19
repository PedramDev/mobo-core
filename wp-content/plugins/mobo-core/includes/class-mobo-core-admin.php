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

	/**
	 * Bootstrap admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );

		add_action( 'admin_post_mobo_core_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_mobo_core_start_sync', array( $this, 'handle_start_sync' ) );
		add_action( 'admin_post_mobo_core_cancel_sync', array( $this, 'handle_cancel_sync' ) );
		add_action( 'admin_post_mobo_core_reset_sync', array( $this, 'handle_reset_sync' ) );
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

		$product_sync = new Mobo_Core_Product_Sync();
		$status       = $product_sync->get_manual_sync_status();
		$is_running   = ! empty( $status['isRunning'] );

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		$allowed_tabs = array(
			'dashboard',
			'product',
			'categories',
			'pricing',
			'filters',
			'queue',
		);

		if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
			$active_tab = 'dashboard';
		}

		$this->render_styles();
		$this->render_scripts();

		?>
		<div class="wrap mobo-wrap" dir="rtl">
			<div class="mobo-hero">
				<div>
					<h1>موبو | همگام‌سازی ووکامرس</h1>
					<p>مدیریت همگام‌سازی محصولات، قیمت‌گذاری، دسته‌بندی، تصاویر و صف وب‌هوک.</p>
				</div>

				<div class="mobo-hero-badge">
					<span>وضعیت</span>
					<strong class="<?php echo $is_running ? 'is-running' : 'is-idle'; ?>">
						<?php echo $is_running ? 'در حال همگام‌سازی' : esc_html( $this->status_label( $status['status'] ) ); ?>
					</strong>
				</div>
			</div>

			<?php $this->render_notices(); ?>

			<nav class="mobo-tabs" aria-label="Mobo settings tabs">
				<?php $this->tab_link( 'dashboard', 'داشبورد', $active_tab ); ?>
				<?php $this->tab_link( 'product', 'محصول', $active_tab ); ?>
				<?php $this->tab_link( 'categories', 'دسته‌بندی', $active_tab ); ?>
				<?php $this->tab_link( 'pricing', 'قیمت‌گذاری', $active_tab ); ?>
				<?php $this->tab_link( 'filters', 'فیلترها', $active_tab ); ?>
				<?php $this->tab_link( 'queue', 'صف و پردازش', $active_tab ); ?>
			</nav>

			<div class="mobo-panel">
				<?php if ( 'dashboard' === $active_tab ) : ?>
					<?php $this->render_dashboard_tab( $status ); ?>
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
				<?php endif; ?>
			</div>
		</div>
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
		$is_done    = ! empty( $status['isDone'] );

		?>
		<div class="mobo-grid mobo-grid-dashboard">
			<div class="mobo-card mobo-card-wide">
				<div class="mobo-card-head">
					<h2>وضعیت همگام‌سازی محصولات</h2>
					<p>نمای کلی از آخرین وضعیت sync محصول.</p>
				</div>

				<div class="mobo-progress-wrap">
					<div class="mobo-progress-meta">
						<span>پیشرفت</span>
						<strong><?php echo esc_html( (string) $this->format_percent( $status['progressPercent'] ) ); ?>٪</strong>
					</div>
					<div class="mobo-progress">
						<div style="width: <?php echo esc_attr( min( 100, max( 0, (float) $status['progressPercent'] ) ) ); ?>%;"></div>
					</div>
				</div>

				<div class="mobo-status-grid">
					<?php $this->status_box( 'وضعیت', $this->status_label( $status['status'] ) ); ?>
					<?php $this->status_box( 'Sync ID', $status['syncId'] ? $status['syncId'] : '—' ); ?>
					<?php $this->status_box( 'محصولات پردازش‌شده', absint( $status['processedProducts'] ) ); ?>
					<?php $this->status_box( 'محصولات باقی‌مانده', absint( $status['remainingProducts'] ) ); ?>
					<?php $this->status_box( 'صفحه محصول', absint( $status['productPage'] ) ); ?>
					<?php $this->status_box( 'صفحه تنوع', absint( $status['variantPage'] ) ); ?>
				</div>

				<?php if ( ! empty( $status['lastMessage'] ) ) : ?>
					<div class="mobo-message mobo-message-info">
						<?php echo esc_html( $status['lastMessage'] ); ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $status['lastError'] ) ) : ?>
					<div class="mobo-message mobo-message-error">
						<?php echo esc_html( $status['lastError'] ); ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>عملیات دستی</h2>
					<p>شروع یا توقف همگام‌سازی محصول از داخل وردپرس.</p>
				</div>

				<div class="mobo-actions">
					<?php if ( ! $is_running ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mobo_core_start_sync">
							<?php wp_nonce_field( 'mobo_core_start_sync', 'mobo_core_nonce' ); ?>

							<button type="submit" class="mobo-btn mobo-btn-primary">
								شروع همگام‌سازی محصولات
							</button>
						</form>
					<?php endif; ?>

					<?php if ( $is_running ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mobo_core_cancel_sync">
							<?php wp_nonce_field( 'mobo_core_cancel_sync', 'mobo_core_nonce' ); ?>

							<button type="submit" class="mobo-btn mobo-btn-danger">
								توقف همگام‌سازی
							</button>
						</form>
					<?php endif; ?>

					<?php if ( ! $is_running && ! $is_done ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mobo_core_reset_sync">
							<?php wp_nonce_field( 'mobo_core_reset_sync', 'mobo_core_nonce' ); ?>

							<button type="submit" class="mobo-btn mobo-btn-light">
								پاک‌سازی وضعیت sync
							</button>
						</form>
					<?php endif; ?>
				</div>

				<div class="mobo-note">
					اجرای مرحله‌ای توسط سیستم مرکزی انجام می‌شود. این دکمه فقط وضعیت sync را در وردپرس شروع می‌کند.
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render product tab.
	 *
	 * @return void
	 */
	private function render_product_tab() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="product">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>بروزرسانی محصول</h2>
					<p>این گزینه‌ها فقط روی محصول موجود اثر دارند. محصول جدید همیشه با اطلاعات اصلی اولیه ساخته می‌شود.</p>
				</div>

				<div class="mobo-fields-grid">
					<?php $this->bool_field( 'بروزرسانی اتوماتیک موجودی انبار', 'global_product_auto_stock' ); ?>
					<?php $this->bool_field( 'بروزرسانی اتوماتیک قیمت', 'global_product_auto_price' ); ?>
					<?php $this->bool_field( 'بروزرسانی اتوماتیک عنوان', 'global_product_auto_title' ); ?>
					<?php $this->bool_field( 'بروزرسانی اتوماتیک آدرس محصول', 'global_product_auto_slug' ); ?>
					<?php $this->bool_field( 'فقط محصولات موجود', 'mobo_core_only_in_stock' ); ?>
					<?php $this->bool_field( 'اعمال تخفیف‌های موبو', 'global_product_auto_compare_price' ); ?>
					<?php $this->bool_field( 'آپدیت اتوماتیک عکس‌های محصول', 'global_update_images' ); ?>
				</div>

				<div class="mobo-note">
					فیلد محتوا / caption در این نسخه همگام‌سازی نمی‌شود، چون از API مقدار قابل استفاده‌ای برای آن دریافت نمی‌شود.
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
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="categories">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>دسته‌بندی محصولات</h2>
					<p>دسته‌بندی اتوماتیک از API موبو دریافت می‌شود. دسته پیشفرض همیشه به عنوان جایگزین قابل استفاده است.</p>
				</div>

				<div class="mobo-fields-grid">
					<?php $this->bool_field( 'آپدیت اتوماتیک دسته‌بندی‌های محصول', 'global_update_categories' ); ?>
					<?php $this->category_dropdown_field( 'دسته‌بندی پیشفرض / جایگزین', 'mobo_default_category_id' ); ?>
				</div>

				<div class="mobo-note">
					اگر دسته‌بندی اتوماتیک خاموش باشد، دسته پیشفرض فقط برای محصول جدید اعمال می‌شود.
					اگر دسته‌بندی اتوماتیک روشن باشد ولی دسته‌بندی ارسال‌شده از موبو در وردپرس پیدا نشود، دسته پیشفرض به عنوان جایگزین اعمال می‌شود.
				</div>
			</div>

			<?php $this->save_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render pricing tab.
	 *
	 * @return void
	 */
	private function render_pricing_tab() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="pricing">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>نوع سود و قیمت‌گذاری</h2>
					<p>اگر برای یک variation مقدار mobo_additional_price ثبت شده باشد، همان مقدار سود مستقیم استفاده می‌شود و تنظیمات عمومی نادیده گرفته می‌شود.</p>
				</div>

				<?php $this->pricing_rules_ui(); ?>
			</div>

			<?php $this->save_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render filters tab.
	 *
	 * @return void
	 */
	private function render_filters_tab() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="filters">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>محصولات مستثنی از همگام‌سازی</h2>
					<p>آدرس محصولاتی را که نباید وارد وردپرس شوند، خط‌به‌خط وارد کنید.</p>
				</div>

				<?php $this->textarea_field( 'لیست آدرس محصولات مستثنی', 'mobo_core_excluded_product_urls', 'هر خط یک آدرس محصول. مثال: /1847 یا /products/test-product' ); ?>

				<div class="mobo-note">
					اگر URL محصول داخل این لیست باشد، محصول جدید ساخته نمی‌شود و محصول موجود هم بروزرسانی نمی‌شود.
				</div>
			</div>

			<?php $this->save_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render queue tab.
	 *
	 * @return void
	 */
	private function render_queue_tab() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="queue">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-grid">
				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>پردازش مرحله‌ای</h2>
						<p>برای کنترل فشار روی هاست مشتری و اجرای chunk-safe استفاده می‌شود.</p>
					</div>

					<div class="mobo-fields-grid">
						<?php $this->int_field( 'بودجه زمانی هر اجرا - ثانیه', 'mobo_core_sync_time_budget_seconds', 2, 25 ); ?>
						<?php $this->int_field( 'تعداد محصول در هر صفحه', 'mobo_core_products_per_page', 1, 20 ); ?>
						<?php $this->int_field( 'تعداد تنوع در هر صفحه', 'mobo_core_variants_per_page', 1, 100 ); ?>
						<?php $this->int_field( 'تعداد تصویر در هر اجرا', 'mobo_core_images_per_run', 0, 10 ); ?>
					</div>
				</div>

				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>صف وب‌هوک</h2>
						<p>وب‌هوک‌ها ابتدا فایل می‌شوند و سپس به صورت مرحله‌ای پردازش می‌شوند.</p>
					</div>

					<div class="mobo-fields-grid">
						<?php $this->int_field( 'تعداد فایل وب‌هوک در هر اجرا', 'mobo_core_webhook_files_per_run', 1, 10 ); ?>
						<?php $this->int_field( 'حداکثر تلاش برای هر وب‌هوک', 'mobo_core_webhook_max_try', 1, 20 ); ?>
						<?php $this->int_field( 'انقضای وب‌هوک - روز', 'mobo_core_webhook_expire_days', 1, 30 ); ?>
						<?php $this->missing_variants_field(); ?>
					</div>
				</div>
			</div>

			<?php $this->save_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		$message = isset( $_GET['mobo_message'] ) ? sanitize_text_field( wp_unslash( $_GET['mobo_message'] ) ) : '';
		$type    = isset( $_GET['mobo_type'] ) ? sanitize_key( wp_unslash( $_GET['mobo_type'] ) ) : 'success';

		if ( '' === $message ) {
			return;
		}

		$class = 'error' === $type ? 'mobo-alert-error' : 'mobo-alert-success';

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
		$url = add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);

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
	private function save_button() {
		?>
		<div class="mobo-save-row">
			<button type="submit" class="mobo-btn mobo-btn-primary">
				ذخیره تنظیمات
			</button>
		</div>
		<?php
	}

	/**
	 * Bool field.
	 *
	 * @param string $label Label.
	 * @param string $key Option key.
	 * @return void
	 */
	private function bool_field( $label, $key ) {
		$value = (string) Mobo_Core_Settings::get( $key, '0' );
		?>
		<div class="mobo-field mobo-toggle-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>

			<select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
				<option value="1" <?php selected( $value, '1' ); ?>>فعال</option>
				<option value="0" <?php selected( $value, '0' ); ?>>غیرفعال</option>
			</select>
		</div>
		<?php
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
	private function int_field( $label, $key, $min, $max ) {
		$value = absint( Mobo_Core_Settings::get( $key, $min ) );
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
	 * Category dropdown.
	 *
	 * @param string $label Label.
	 * @param string $key Option key.
	 * @return void
	 */
	private function category_dropdown_field( $label, $key ) {
		$selected = absint( Mobo_Core_Settings::get( $key, 0 ) );

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>

			<select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
				<option value="0">انتخاب نشده</option>

				<?php if ( ! is_wp_error( $terms ) && is_array( $terms ) ) : ?>
					<?php foreach ( $terms as $term ) : ?>
						<option value="<?php echo esc_attr( absint( $term->term_id ) ); ?>" <?php selected( $selected, absint( $term->term_id ) ); ?>>
							<?php echo esc_html( $term->name ); ?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>

			<div class="mobo-help">
				اگر دسته‌بندی اتوماتیک خاموش باشد یا دسته‌بندی ارسالی از موبو پیدا نشود، این دسته استفاده می‌شود.
			</div>
		</div>
		<?php
	}

	/**
	 * Missing variants field.
	 *
	 * @return void
	 */
	private function missing_variants_field() {
		$value = (string) Mobo_Core_Settings::get( 'mobo_missing_variants_behavior', 'outofstock' );
		?>
		<div class="mobo-field">
			<label for="mobo_missing_variants_behavior">رفتار تنوع‌های حذف‌شده از API</label>

			<select id="mobo_missing_variants_behavior" name="mobo_missing_variants_behavior">
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
	 * Pricing UI.
	 *
	 * @return void
	 */
	private function pricing_rules_ui() {
		$price_type = (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' );

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
						<label for="global_additional_price">مبلغ سود ثابت</label>
						<input type="number" id="global_additional_price" name="global_additional_price" value="<?php echo esc_attr( $static_price ); ?>" min="0" step="1">
						<div class="mobo-help">این مبلغ به قیمت محصول یا تنوع اضافه می‌شود.</div>
					</div>
				</div>
			</div>

			<div class="mobo-price-section" id="static-percentage" style="<?php echo 'static-percentage' === $price_type ? '' : 'display:none;'; ?>">
				<div class="mobo-fields-grid">
					<div class="mobo-field">
						<label for="global_additional_percentage">درصد سود</label>
						<input type="number" id="global_additional_percentage" name="global_additional_percentage" value="<?php echo esc_attr( $static_percentage ); ?>" min="0" step="0.01">
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
						<span>از قیمت</span>
						<span>تا قیمت</span>
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

							<input type="number" name="mobo_dynamic_low[]" value="<?php echo esc_attr( $low ); ?>" min="0" step="1">
							<input type="number" name="mobo_dynamic_high[]" value="<?php echo esc_attr( $high ); ?>" min="0" step="1">

							<select name="mobo_dynamic_benefit_type[]">
								<option value="static" <?php selected( $benefit_type, 'static' ); ?>>مبلغ ثابت</option>
								<option value="percentage" <?php selected( $benefit_type, 'percentage' ); ?>>درصدی</option>
							</select>

							<input type="number" name="mobo_dynamic_benefit[]" value="<?php echo esc_attr( $benefit ); ?>" min="0" step="0.01">

							<button type="button" class="mobo-remove-row" aria-label="حذف">×</button>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="mobo-note">
					اولین قانونی که بازه قیمت محصول با آن تطبیق داشته باشد اعمال می‌شود.
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Status box.
	 *
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private function status_box( $label, $value ) {
		?>
		<div class="mobo-status-box">
			<span><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( (string) $value ); ?></strong>
		</div>
		<?php
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

		$bool_options = array(
			'global_product_auto_stock',
			'global_product_auto_price',
			'global_product_auto_title',
			'global_product_auto_slug',
			'global_product_auto_compare_price',
			'global_update_categories',
			'global_update_images',
			'mobo_core_only_in_stock',
		);

		foreach ( $bool_options as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_option( $key, $this->sanitize_bool_value( wp_unslash( $_POST[ $key ] ) ), false );
			}
		}

		$int_options = array(
			'mobo_default_category_id'        => array( 0, PHP_INT_MAX ),
			'mobo_core_sync_time_budget_seconds' => array( 2, 25 ),
			'mobo_core_products_per_page'    => array( 1, 20 ),
			'mobo_core_variants_per_page'    => array( 1, 100 ),
			'mobo_core_images_per_run'       => array( 0, 10 ),
			'mobo_core_webhook_files_per_run'=> array( 1, 10 ),
			'mobo_core_webhook_max_try'      => array( 1, 20 ),
			'mobo_core_webhook_expire_days'  => array( 1, 30 ),
			'global_additional_price'        => array( 0, PHP_INT_MAX ),
		);

		foreach ( $int_options as $key => $range ) {
			if ( isset( $_POST[ $key ] ) ) {
				$value = absint( wp_unslash( $_POST[ $key ] ) );
				$value = max( absint( $range[0] ), min( absint( $range[1] ), $value ) );

				update_option( $key, $value, false );
			}
		}

		if ( isset( $_POST['global_additional_percentage'] ) ) {
			$value = wc_format_decimal( wp_unslash( $_POST['global_additional_percentage'] ) );
			$value = is_numeric( $value ) ? max( 0, (float) $value ) : 0;

			update_option( 'global_additional_percentage', $value, false );
		}

		if ( isset( $_POST['mobo_price_type'] ) ) {
			$price_type = sanitize_key( wp_unslash( $_POST['mobo_price_type'] ) );

			if ( ! in_array( $price_type, array( 'static-price', 'static-percentage', 'dynamic-price' ), true ) ) {
				$price_type = 'static-price';
			}

			update_option( 'mobo_price_type', $price_type, false );
		}

		if ( isset( $_POST['mobo_missing_variants_behavior'] ) ) {
			$behavior = sanitize_key( wp_unslash( $_POST['mobo_missing_variants_behavior'] ) );

			if ( ! in_array( $behavior, array( 'outofstock', 'ignore' ), true ) ) {
				$behavior = 'outofstock';
			}

			update_option( 'mobo_missing_variants_behavior', $behavior, false );
		}

		if ( isset( $_POST['mobo_core_excluded_product_urls'] ) ) {
			update_option(
				'mobo_core_excluded_product_urls',
				sanitize_textarea_field( wp_unslash( $_POST['mobo_core_excluded_product_urls'] ) ),
				false
			);
		}

		$this->save_dynamic_price_rules();

		$this->redirect_with_message( 'تنظیمات ذخیره شد.', 'success', $tab );
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

		$type    = ! empty( $result['success'] ) ? 'success' : 'error';
		$message = isset( $result['message'] ) ? $result['message'] : 'عملیات انجام شد.';

		$this->redirect_with_message( $message, $type, 'dashboard' );
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
		$product_sync->reset_manual_sync_state();

		$this->redirect_with_message( 'وضعیت همگام‌سازی پاک شد.', 'success', 'dashboard' );
	}

	/**
	 * Save dynamic price rules.
	 *
	 * @return void
	 */
	private function save_dynamic_price_rules() {
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
			$low     = isset( $low_values[ $i ] ) ? wc_format_decimal( $low_values[ $i ] ) : '';
			$high    = isset( $high_values[ $i ] ) ? wc_format_decimal( $high_values[ $i ] ) : '';
			$benefit = isset( $benefit_values[ $i ] ) ? wc_format_decimal( $benefit_values[ $i ] ) : '';

			if ( '' === $low && '' === $high && '' === $benefit ) {
				continue;
			}

			$benefit_type = isset( $benefit_type_values[ $i ] ) ? sanitize_key( $benefit_type_values[ $i ] ) : 'static';

			if ( ! in_array( $benefit_type, array( 'static', 'percentage' ), true ) ) {
				$benefit_type = 'static';
			}

			$is_active = isset( $is_active_values[ $i ] ) && 'false' === sanitize_text_field( $is_active_values[ $i ] ) ? 'false' : 'true';

			$rows[] = array(
				'is_active'    => $is_active,
				'low'          => is_numeric( $low ) ? (float) $low : 0,
				'high'         => is_numeric( $high ) ? (float) $high : 0,
				'benefit_type' => $benefit_type,
				'benefit'      => is_numeric( $benefit ) ? (float) $benefit : 0,
			);
		}

		update_option(
			'mobo_dynamic_price',
			wp_json_encode( $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			false
		);
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
		$url = add_query_arg(
			array(
				'page'         => self::MENU_SLUG,
				'tab'          => sanitize_key( $tab ),
				'mobo_message' => rawurlencode( sanitize_text_field( (string) $message ) ),
				'mobo_type'    => sanitize_key( $type ),
			),
			admin_url( 'admin.php' )
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
				function switchPriceSection() {
					var selectedValue = $('input[name="mobo_price_type"]:checked').val();

					$('.mobo-price-types label').removeClass('active');
					$('input[name="mobo_price_type"]:checked').closest('label').addClass('active');

					$('.mobo-price-section').hide();
					$('#' + selectedValue).show();
				}

				$(document).on('change', 'input[name="mobo_price_type"]', switchPriceSection);

				$(document).on('click', '#mobo-add-price-rule', function() {
					var row = ''
						+ '<div class="mobo-dynamic-row">'
						+ '<select name="mobo_dynamic_is_active[]"><option value="true">بله</option><option value="false">خیر</option></select>'
						+ '<input type="number" name="mobo_dynamic_low[]" value="" min="0" step="1">'
						+ '<input type="number" name="mobo_dynamic_high[]" value="" min="0" step="1">'
						+ '<select name="mobo_dynamic_benefit_type[]"><option value="static">مبلغ ثابت</option><option value="percentage">درصدی</option></select>'
						+ '<input type="number" name="mobo_dynamic_benefit[]" value="" min="0" step="0.01">'
						+ '<button type="button" class="mobo-remove-row" aria-label="حذف">×</button>'
						+ '</div>';

					$('#mobo-dynamic-rows').append(row);
				});

				$(document).on('click', '.mobo-remove-row', function() {
					var rows = $('.mobo-dynamic-row').not('.mobo-dynamic-row-head');

					if (rows.length <= 1) {
						$(this).closest('.mobo-dynamic-row').find('input').val('');
						return;
					}

					$(this).closest('.mobo-dynamic-row').remove();
				});

				switchPriceSection();
			});
		</script>
		<?php
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

			.mobo-save-row {
				margin-top: 16px;
				display: flex;
				justify-content: flex-start;
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

			.mobo-actions {
				display: flex;
				flex-direction: column;
				gap: 10px;
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
				.mobo-price-types {
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
		</style>
		<?php
	}
}