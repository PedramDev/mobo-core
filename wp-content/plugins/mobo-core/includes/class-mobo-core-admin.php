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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		add_action( 'admin_post_mobo_core_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_mobo_core_start_sync', array( $this, 'handle_start_sync' ) );
		add_action( 'admin_post_mobo_core_resume_sync', array( $this, 'handle_resume_sync' ) );
		add_action( 'admin_post_mobo_core_cancel_sync', array( $this, 'handle_cancel_sync' ) );
		add_action( 'admin_post_mobo_core_reset_sync', array( $this, 'handle_reset_sync' ) );
		add_action( 'admin_post_mobo_core_start_reprice', array( $this, 'handle_start_reprice' ) );
		add_action( 'admin_post_mobo_core_cancel_reprice', array( $this, 'handle_cancel_reprice' ) );
		add_action( 'admin_post_mobo_core_reset_reprice', array( $this, 'handle_reset_reprice' ) );
		add_action( 'admin_post_mobo_core_retry_failed_webhooks', array( $this, 'handle_retry_failed_webhooks' ) );
		add_action( 'wp_ajax_mobo_core_get_sync_status', array( $this, 'handle_ajax_sync_status' ) );
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
	 * Enqueue admin assets for the Mobo settings page.
	 *
	 * Uses WooCommerce's bundled SelectWoo when available. Native select fields
	 * remain as a safe fallback when SelectWoo is not registered.
	 *
	 * @param string $hook Admin hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		if ( wp_script_is( 'selectWoo', 'registered' ) ) {
			wp_enqueue_script( 'selectWoo' );
		} elseif ( wp_script_is( 'select2', 'registered' ) ) {
			wp_enqueue_script( 'select2' );
		}

		if ( wp_style_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
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

		$product_sync = new Mobo_Core_Product_Sync();
		$status       = $product_sync->get_manual_sync_status();
		$is_running   = ! empty( $status['isRunning'] );
		$is_waiting   = ! empty( $status['isWaitingForPortal'] );

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		$allowed_tabs = array(
			'dashboard',
			'connection',
			'product',
			'categories',
			'pricing',
			'filters',
			'queue',
			'cron',
			'checkout',
			'health',
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
					<strong data-mobo-sync-hero-status="1" class="<?php echo $is_waiting ? 'is-waiting' : ( $is_running ? 'is-running' : 'is-idle' ); ?>">
						<?php echo $is_waiting ? 'در انتظار اتصال Portal' : ( $is_running ? 'در حال همگام‌سازی' : esc_html( $this->status_label( $status['status'] ) ) ); ?>
					</strong>
				</div>
			</div>

			<?php $this->render_notices(); ?>

			<nav class="mobo-tabs" aria-label="Mobo settings tabs">
				<?php $this->tab_link( 'dashboard', 'داشبورد', $active_tab ); ?>
				<?php $this->tab_link( 'connection', 'اتصال', $active_tab ); ?>
				<?php $this->tab_link( 'product', 'محصول', $active_tab ); ?>
				<?php $this->tab_link( 'categories', 'دسته‌بندی', $active_tab ); ?>
				<?php $this->tab_link( 'pricing', 'قیمت‌گذاری', $active_tab ); ?>
				<?php $this->tab_link( 'filters', 'فیلترها', $active_tab ); ?>
				<?php $this->tab_link( 'queue', 'صف و پردازش', $active_tab ); ?>
				<?php $this->tab_link( 'cron', 'کران واقعی', $active_tab ); ?>
				<?php $this->tab_link( 'checkout', 'اعتبارسنجی خرید', $active_tab ); ?>
				<?php $this->tab_link( 'health', 'سلامت سایت', $active_tab ); ?>
			</nav>

			<div class="mobo-panel">
				<?php if ( 'dashboard' === $active_tab ) : ?>
					<?php $this->render_dashboard_tab( $status ); ?>
				<?php elseif ( 'connection' === $active_tab ) : ?>
					<?php $this->render_connection_tab(); ?>
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
				<?php elseif ( 'cron' === $active_tab ) : ?>
					<?php $this->render_cron_tab(); ?>
				<?php elseif ( 'checkout' === $active_tab ) : ?>
					<?php $this->render_checkout_tab(); ?>
				<?php elseif ( 'health' === $active_tab ) : ?>
					<?php $this->render_health_tab(); ?>
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
		$is_waiting = ! empty( $status['isWaitingForPortal'] );
		$is_done    = ! empty( $status['isDone'] );

		?>
		<div class="mobo-grid mobo-grid-dashboard">
			<div class="mobo-card mobo-card-wide" id="mobo-sync-status-card" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mobo_core_sync_status' ) ); ?>">
				<div class="mobo-card-head">
					<h2>وضعیت همگام‌سازی محصولات</h2>
					<p>نمای کلی از آخرین وضعیت sync محصول.</p>
				</div>

				<div class="mobo-progress-wrap">
					<div class="mobo-progress-meta">
						<span>پیشرفت</span>
						<strong><span data-mobo-sync-field="progressPercent"><?php echo esc_html( (string) $this->format_percent( $status['progressPercent'] ) ); ?></span>٪</strong>
					</div>
					<div class="mobo-progress">
						<div data-mobo-sync-progress-bar="1" style="width: <?php echo esc_attr( min( 100, max( 0, (float) $status['progressPercent'] ) ) ); ?>%;"></div>
					</div>
				</div>

				<div class="mobo-status-grid">
					<?php $this->status_box( 'وضعیت', $this->status_label( $status['status'] ), 'statusLabel' ); ?>
					<?php $this->status_box( 'Sync ID', $status['syncId'] ? $status['syncId'] : '—', 'syncId' ); ?>
					<?php $this->status_box( 'محصولات پردازش‌شده', absint( $status['processedProducts'] ), 'processedProducts' ); ?>
					<?php $this->status_box( 'محصولات باقی‌مانده', absint( $status['remainingProducts'] ), 'remainingProducts' ); ?>
					<?php $this->status_box( 'صفحه محصول', absint( $status['productPage'] ), 'productPage' ); ?>
					<?php $this->status_box( 'صفحه تنوع', absint( $status['variantPage'] ), 'variantPage' ); ?>
					<?php $this->status_box( 'تلاش بعدی', ! empty( $status['nextRetryAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['nextRetryAt'] ) ) : '—', 'nextRetryAt' ); ?>
				</div>

				<div class="mobo-message mobo-message-info" data-mobo-sync-message="lastMessage" style="<?php echo empty( $status['lastMessage'] ) ? 'display:none;' : ''; ?>">
					<?php echo esc_html( $status['lastMessage'] ); ?>
				</div>

				<div class="mobo-message mobo-message-error" data-mobo-sync-message="lastError" style="<?php echo empty( $status['lastError'] ) ? 'display:none;' : ''; ?>">
					<?php echo esc_html( $status['lastError'] ); ?>
				</div>

				<div class="mobo-message mobo-message-warning" data-mobo-sync-message="lastTransientError" style="<?php echo empty( $status['lastTransientError'] ) ? 'display:none;' : ''; ?>">
					<?php echo esc_html( $status['lastTransientError'] ); ?>
				</div>

				<div class="mobo-auto-refresh">
					<span data-mobo-sync-refresh-state>به‌روزرسانی خودکار فعال است.</span>
					<span data-mobo-sync-updated-at></span>
				</div>
			</div>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>عملیات دستی</h2>
					<p>شروع یا توقف همگام‌سازی محصول از داخل وردپرس.</p>
				</div>

				<div class="mobo-actions">
					<?php if ( $is_waiting ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mobo_core_resume_sync">
							<?php wp_nonce_field( 'mobo_core_resume_sync', 'mobo_core_nonce' ); ?>
							<button type="submit" class="mobo-btn mobo-btn-primary">ادامه از آخرین نقطه ذخیره‌شده</button>
						</form>
					<?php elseif ( ! $is_running ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mobo_core_start_sync">
							<?php wp_nonce_field( 'mobo_core_start_sync', 'mobo_core_nonce' ); ?>

							<button type="submit" class="mobo-btn mobo-btn-primary">
								شروع همگام‌سازی محصولات
							</button>
						</form>
					<?php endif; ?>

					<?php if ( $is_running || $is_waiting ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mobo_core_cancel_sync">
							<?php wp_nonce_field( 'mobo_core_cancel_sync', 'mobo_core_nonce' ); ?>

							<button type="submit" class="mobo-btn mobo-btn-danger">
								توقف همگام‌سازی
							</button>
						</form>
					<?php endif; ?>

					<?php if ( ! $is_running && ! $is_waiting && ! $is_done ) : ?>
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
					اجرای مرحله‌ای روی خود همین وردپرس انجام می‌شود. اگر Portal موقتاً قطع شود، sync از آخرین cursor/page ذخیره‌شده ادامه پیدا می‌کند.
				</div>
			</div>
		</div>
		<?php
	}

	 /**
	 * Render connection tab.
	 *
	 * @return void
	 */
	private function render_connection_tab() {
		$has_token         = '' !== (string) get_option( 'mobo_core_token', '' );
		$has_security_code = '' !== (string) get_option( 'mobo_core_security_code', '' );

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="connection">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-grid">
				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>اتصال به API موبو</h2>
						<p>توکن برای درخواست‌هایی استفاده می‌شود که وردپرس به سمت API موبو ارسال می‌کند.</p>
					</div>

					<?php
					$this->secret_field(
						'Token',
						'mobo_core_token',
						$has_token ? 'توکن قبلاً ثبت شده است. برای تغییر، مقدار جدید وارد کنید.' : 'توکن هنوز ثبت نشده است.'
					);
					?>

					<div class="mobo-note">
						این مقدار در دیتابیس با کلید <code>mobo_core_token</code> ذخیره می‌شود و در درخواست‌های API به عنوان Header با نام <code>Token</code> ارسال می‌شود.
					</div>
				</div>

				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>کد امنیتی وب‌هوک</h2>
						<p>این مقدار برای اعتبارسنجی درخواست‌هایی استفاده می‌شود که از سیستم مرکزی به وردپرس ارسال می‌شوند.</p>
					</div>

					<?php
					$this->secret_field(
						'Webhook Security Code',
						'mobo_core_security_code',
						$has_security_code ? 'کد امنیتی قبلاً ثبت شده است. برای تغییر، مقدار جدید وارد کنید.' : 'کد امنیتی هنوز ثبت نشده است.'
					);
					?>

					<div class="mobo-note">
						این مقدار در دیتابیس با کلید <code>mobo_core_security_code</code> ذخیره می‌شود و با Header <code>X-SEC</code> مقایسه می‌شود.
					</div>
				</div>
			</div>

			<?php $this->save_button(); ?>
		</form>
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
	 * Render checkout validation tab.
	 *
	 * @return void
	 */
	private function render_checkout_tab() {
		$validator = class_exists( 'Mobo_Core_Checkout_Validator' ) ? new Mobo_Core_Checkout_Validator() : null;
		$status    = $validator ? $validator->get_last_status() : array();
		$last      = isset( $status['lastResult'] ) && is_array( $status['lastResult'] ) ? $status['lastResult'] : array();
		$behavior  = sanitize_key( (string) Mobo_Core_Settings::get( 'mobo_core_checkout_external_error_behavior', 'allow' ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="checkout">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-grid">
				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>اعتبارسنجی قبل از خرید</h2>
						<p>این بخش قبل از پرداخت، آیتم‌های سبد خرید را بررسی می‌کند. پیش‌فرض غیرفعال است تا آپدیت مشتری‌های فعلی رفتار checkout را تغییر ندهد.</p>
					</div>

					<div class="mobo-fields-grid">
						<?php $this->bool_field( 'فعال بودن اعتبارسنجی خرید', 'mobo_core_checkout_validation_enabled' ); ?>
						<?php $this->bool_field( 'فقط محصولات sync شده موبو بررسی شوند', 'mobo_core_checkout_validate_only_mobo_products' ); ?>
						<?php $this->bool_field( 'نیاز به product_guid / variant_guid', 'mobo_core_checkout_require_remote_guid' ); ?>
						<?php $this->bool_field( 'مسدود کردن محصول با sync ناقص', 'mobo_core_checkout_block_incomplete_sync' ); ?>
						<?php $this->bool_field( 'بررسی محلی موجودی و قابل خرید بودن', 'mobo_core_checkout_local_stock_check_enabled' ); ?>
					</div>

					<div class="mobo-note">
						این validator با HPOS سازگار است، چون فقط cart itemها و WC_Product را بررسی می‌کند و مستقیم به جدول سفارش‌ها یا <code>shop_order</code> دست نمی‌زند.
					</div>
				</div>

				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>سرویس خارجی اعتبارسنجی</h2>
						<p>مقصد API خارجی اختیاری است. اگر URL خالی باشد، سیستم فقط local validation انجام می‌دهد.</p>
					</div>

					<div class="mobo-fields-grid">
						<?php $this->bool_field( 'فعال بودن اعتبارسنجی خارجی', 'mobo_core_checkout_external_validation_enabled' ); ?>
						<?php $this->url_field( 'آدرس API اعتبارسنجی خارجی', 'mobo_core_checkout_external_validation_url', 'مثال: https://api.example.com/pre-purchase/check' ); ?>
						<?php $this->int_field( 'Timeout سرویس خارجی / ثانیه', 'mobo_core_checkout_external_timeout_seconds', 1, 10 ); ?>
						<div class="mobo-field">
							<label for="mobo_core_checkout_external_error_behavior">رفتار هنگام خطای سرویس خارجی</label>
							<select id="mobo_core_checkout_external_error_behavior" name="mobo_core_checkout_external_error_behavior">
								<option value="allow" <?php selected( $behavior, 'allow' ); ?>>اجازه ادامه خرید</option>
								<option value="block" <?php selected( $behavior, 'block' ); ?>>مسدود کردن خرید</option>
							</select>
						</div>
					</div>

					<div class="mobo-note">
						درخواست خارجی با <code>POST JSON</code> ارسال می‌شود و اگر مقدارهای <code>X-SEC</code> و <code>Token</code> تنظیم شده باشند، به عنوان header ارسال می‌شوند.
					</div>
				</div>
			</div>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>آخرین وضعیت اعتبارسنجی</h2>
					<p>برای debug سریع checkout استفاده می‌شود.</p>
				</div>

				<div class="mobo-status-grid">
					<?php $this->status_box( 'وضعیت local validator', ! empty( $status['enabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
					<?php $this->status_box( 'اعتبارسنجی خارجی', ! empty( $status['external'] ) ? 'فعال' : 'غیرفعال' ); ?>
					<?php $this->status_box( 'آخرین تلاش', ! empty( $status['lastAttemptAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['lastAttemptAt'] ) ) : '—' ); ?>
					<?php $this->status_box( 'آخرین موفقیت', ! empty( $status['lastSuccessAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['lastSuccessAt'] ) ) : '—' ); ?>
					<?php $this->status_box( 'آخرین نتیجه', isset( $last['success'] ) ? ( ! empty( $last['success'] ) ? 'موفق' : 'ناموفق' ) : '—' ); ?>
					<?php $this->status_box( 'HTTP Status', isset( $last['status'] ) ? absint( $last['status'] ) : '—' ); ?>
				</div>
			</div>

			<?php $this->save_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render health reporting tab.
	 *
	 * @return void
	 */
	private function render_health_tab() {
		$reporter = new Mobo_Core_Health_Reporter();
		$status   = $reporter->get_last_report_status();
		$local    = $reporter->build_report();
		$health_url = rest_url( 'mobo-core/v1/health' );
		$manual_url = rest_url( 'mobo-core/v1/health/report-now' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="health">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-grid">
				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>گزارش سلامت سایت</h2>
						<p>این گزارش از داخل وردپرس تولید می‌شود و به Portal ارسال می‌شود تا وضعیت cron، memory، debug، disk و صف sync مشخص باشد.</p>
					</div>

					<div class="mobo-status-grid">
						<?php $this->status_box( 'ارسال Health Report', ! empty( $status['enabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
						<?php $this->status_box( 'آخرین تلاش', ! empty( $status['lastAttemptAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['lastAttemptAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'آخرین ارسال موفق', ! empty( $status['lastSuccessAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['lastSuccessAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'نسخه پلاگین', defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '—' ); ?>
					</div>

					<div class="mobo-field mobo-field-full">
						<label>Endpoint Probe داخلی پلاگین</label>
						<input type="text" readonly dir="ltr" value="<?php echo esc_attr( $health_url ); ?>" onclick="this.select();">
						<div class="mobo-help">Portal این endpoint را با header امنیتی <code>X-SEC</code> چک می‌کند.</div>
					</div>

					<div class="mobo-field mobo-field-full">
						<label>Endpoint ارسال دستی گزارش</label>
						<input type="text" readonly dir="ltr" value="<?php echo esc_attr( $manual_url ); ?>" onclick="this.select();">
					</div>
				</div>

				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>تنظیمات ارسال</h2>
						<p>اگر URL خالی باشد، پلاگین از API Base URL مقدار <code>/api/site-health/report</code> را می‌سازد.</p>
					</div>

					<div class="mobo-fields-grid">
						<?php $this->bool_field( 'ارسال Health Report به Portal', 'mobo_core_health_report_enabled' ); ?>
						<?php $this->int_field( 'حداقل فاصله ارسال / ثانیه', 'mobo_core_health_report_min_interval_seconds', 60, 3600 ); ?>
						<?php $this->int_field( 'Timeout ارسال / ثانیه', 'mobo_core_health_report_timeout_seconds', 5, 60 ); ?>
					</div>

					<?php $this->url_field( 'Health Report URL', 'mobo_core_health_report_url', 'اختیاری. مثال: https://portal.example.com/api/site-health/report' ); ?>
				</div>

				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>وضعیت محلی فعلی</h2>
						<p>این مقادیر همان چیزی است که به Portal گزارش می‌شود.</p>
					</div>

					<div class="mobo-status-grid">
						<?php $this->status_box( 'WP DEBUG', ! empty( $local['wpDebug'] ) ? 'فعال' : 'غیرفعال' ); ?>
						<?php $this->status_box( 'WP DEBUG DISPLAY', ! empty( $local['wpDebugDisplay'] ) ? 'فعال' : 'غیرفعال' ); ?>
						<?php $this->status_box( 'PHP Memory Limit', isset( $local['phpMemoryLimit'] ) ? $local['phpMemoryLimit'] : '—' ); ?>
						<?php $this->status_box( 'PHP Version', isset( $local['phpVersion'] ) ? $local['phpVersion'] : '—' ); ?>
						<?php $this->status_box( 'Disk Free', isset( $local['diskFreePercent'] ) && null !== $local['diskFreePercent'] ? $local['diskFreePercent'] . '%' : '—' ); ?>
						<?php $this->status_box( 'Pending Webhooks', isset( $local['pendingWebhookJobs'] ) ? absint( $local['pendingWebhookJobs'] ) : 0 ); ?>
						<?php $this->status_box( 'Failed Webhooks', isset( $local['failedWebhookJobs'] ) ? absint( $local['failedWebhookJobs'] ) : 0 ); ?>
						<div class="mobo-status-box">
							<div class="mobo-status-label">Retry Failed Webhooks</div>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
								<input type="hidden" name="action" value="mobo_core_retry_failed_webhooks">
								<?php wp_nonce_field( 'mobo_core_retry_failed_webhooks', 'mobo_core_nonce' ); ?>
								<button type="submit" class="button button-secondary">برگرداندن failed ها به صف</button>
							</form>
						</div>
						<?php $this->status_box( 'Cron Mode', isset( $local['cronMode'] ) ? $local['cronMode'] : '—' ); ?>
					</div>

					<?php if ( ! empty( $status['lastResult'] ) && is_array( $status['lastResult'] ) ) : ?>
						<div class="mobo-note" dir="ltr">
							<pre><?php echo esc_html( wp_json_encode( $status['lastResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<?php $this->save_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render real cron tab.
	 *
	 * @return void
	 */
	private function render_cron_tab() {
		$status   = Mobo_Core_Cron_Runner::get_status();
		$cron_url = isset( $status['cronUrl'] ) ? (string) $status['cronUrl'] : '';
		$command  = '' !== $cron_url ? '*/5 * * * * curl -fsS "' . $cron_url . '" >/dev/null 2>&1' : '';
		$self_status = class_exists( 'Mobo_Core_Self_Runner' ) ? Mobo_Core_Self_Runner::get_status() : array();
		$worker_url = isset( $self_status['workerUrl'] ) ? (string) $self_status['workerUrl'] : '';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="cron">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-grid">
				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>Self Runner / Real Cron</h2>
						<p>مسیر اصلی اجرای sync، worker داخلی پلاگین است که بعد از دریافت webhook خودش را بیدار می‌کند. Cron واقعی فقط fallback اختیاری است.</p>
					</div>

					<div class="mobo-status-grid">
						<?php $this->status_box( 'Self Runner', ! empty( $self_status['enabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
						<?php $this->status_box( 'آخرین Kick', ! empty( $self_status['lastKickAttemptAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $self_status['lastKickAttemptAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'آخرین اجرای Worker', ! empty( $self_status['lastRunAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $self_status['lastRunAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'Cron fallback', ! empty( $status['isActive'] ) ? 'فعال' : 'تشخیص داده نشده' ); ?>
					</div>

					<?php if ( '' !== $worker_url ) : ?>
						<div class="mobo-field mobo-field-full">
							<label>Endpoint داخلی Worker</label>
							<input type="text" readonly dir="ltr" value="<?php echo esc_attr( $worker_url ); ?>" onclick="this.select();">
							<div class="mobo-help">این URL را معمولاً لازم نیست دستی صدا بزنید؛ پلاگین بعد از webhook خودش آن را non-blocking اجرا می‌کند.</div>
						</div>
					<?php endif; ?>

					<?php if ( '' !== $command ) : ?>
						<div class="mobo-field mobo-field-full">
							<label>دستور پیشنهادی cPanel Cron</label>
							<textarea rows="3" readonly dir="ltr" onclick="this.select();"><?php echo esc_textarea( $command ); ?></textarea>
							<div class="mobo-help">این دستور فقط fallback است. اگر loopback/self-kick روی هاست بسته بود، می‌توانید آن را فعال کنید.</div>
						</div>
					<?php endif; ?>
				</div>

				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>تنظیمات Runner</h2>
						<p>هر اجرای Worker فقط یک slice محدود اجرا می‌کند تا هاست مشتری قفل نشود.</p>
					</div>

					<div class="mobo-fields-grid">
						<?php $this->int_field( 'بودجه زمانی هر اجرا / ثانیه', 'mobo_core_real_cron_time_budget_seconds', 5, 55 ); ?>
						<?php $this->int_field( 'حداکثر step محصول در هر اجرا', 'mobo_core_real_cron_max_sync_steps', 1, 20 ); ?>
						<?php $this->int_field( 'TTL قفل Cron / ثانیه', 'mobo_core_real_cron_lock_ttl_seconds', 30, 600 ); ?>
						<?php $this->bool_field( 'فعال بودن Self Runner', 'mobo_core_self_runner_enabled' ); ?>
						<?php $this->bool_field( 'ادامه خودکار تا خالی شدن صف', 'mobo_core_self_runner_continue_enabled' ); ?>
						<?php $this->int_field( 'حداقل فاصله Kick / ثانیه', 'mobo_core_self_runner_min_interval_seconds', 0, 60 ); ?>
						<?php $this->int_field( 'Timeout درخواست داخلی / ثانیه', 'mobo_core_self_runner_http_timeout_seconds', 1, 10 ); ?>
						<?php $this->bool_field( 'پردازش صف وب‌هوک در Runner', 'mobo_core_real_cron_process_webhooks' ); ?>
						<?php $this->bool_field( 'پردازش فوری وب‌هوک هنگام دریافت', 'mobo_core_process_webhook_on_receive' ); ?>
						<?php $this->bool_field( 'دریافت payload اصلی از Portal', 'mobo_core_pull_payload_enabled' ); ?>
						<?php $this->int_field( 'Timeout دریافت payload / ثانیه', 'mobo_core_payload_pull_timeout_seconds', 5, 180 ); ?>
						<?php $this->int_field( 'Timeout درخواست‌های sync API / ثانیه', 'mobo_core_api_request_timeout_seconds', 5, 180 ); ?>
						<?php $this->int_field( 'حداکثر تلاش مجدد خطاهای موقت sync', 'mobo_core_transient_retry_max_try', 1, 50 ); ?>
						<?php $this->int_field( 'فاصله تلاش مجدد پس از انتظار Portal / ثانیه', 'mobo_core_waiting_for_portal_retry_delay_seconds', 10, 3600 ); ?>
					</div>

					<?php $this->secret_field( 'Cron Token', 'mobo_core_cron_token', 'خالی بگذارید تا مقدار قبلی حفظ شود. این token داخل URL کران استفاده می‌شود.' ); ?>
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
					<p>دسته‌بندی اتوماتیک از API موبو دریافت می‌شود. mapping دستی فقط هنگام اختصاص دسته به محصول استفاده می‌شود و دسته‌های sync شده را overwrite نمی‌کند.</p>
				</div>

				<div class="mobo-fields-grid">
					<?php $this->bool_field( 'آپدیت اتوماتیک دسته‌بندی‌های محصول', 'global_update_categories' ); ?>
					<?php $this->bool_field( 'فعال بودن Category Mapping', 'mobo_core_category_mapping_enabled' ); ?>
					<?php $this->bool_field( 'اجباری بودن Mapping دستی', 'mobo_core_category_mapping_required' ); ?>
					<?php $this->category_dropdown_field( 'دسته‌بندی پیشفرض / جایگزین', 'mobo_default_category_id' ); ?>
				</div>

				<div class="mobo-note">
					ترتیب انتخاب دسته برای محصول: mapping دستی، دسته sync شده با category_guid، ساخت خودکار در صورت مجاز بودن، سپس دسته پیشفرض.
					اگر mapping اجباری باشد و برای category_guid دسته محلی انتخاب نشده باشد، دسته محصول تغییر نمی‌کند و GUIDهای گمشده در meta محصول ثبت می‌شوند.
				</div>
			</div>

			<?php $this->category_mapping_table(); ?>

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

			<?php $this->reprice_queue_ui(); ?>

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
						<?php $this->bool_field( 'استفاده از cursor برای sync محصول', 'mobo_core_product_cursor_sync_enabled' ); ?>
						<?php $this->int_field( 'تعداد تنوع در هر صفحه', 'mobo_core_variants_per_page', 1, 100 ); ?>
						<?php $this->bool_field( 'استفاده از cursor برای sync تنوع', 'mobo_core_variant_cursor_sync_enabled' ); ?>
						<?php $this->int_field( 'تعداد تصویر در هر اجرا', 'mobo_core_images_per_run', 0, 10 ); ?>
						<?php $this->bool_field( 'فعال بودن صف مستقل تصویر', 'mobo_core_image_queue_enabled' ); ?>
						<?php $this->bool_field( 'منتظر ماندن sync تا تکمیل تصاویر', 'mobo_core_image_queue_blocking' ); ?>
						<?php $this->int_field( 'حداکثر تلاش دانلود تصویر', 'mobo_core_image_max_try', 1, 20 ); ?>
						<?php $this->int_field( 'فاصله پایه retry تصویر / ثانیه', 'mobo_core_image_retry_base_seconds', 30, 900 ); ?>
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

			<select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" class="mobo-category-select2" data-placeholder="جستجو و انتخاب دسته">
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
	 * Category mapping table.
	 *
	 * @return void
	 */
	private function category_mapping_table() {
		if ( ! class_exists( 'Mobo_Core_Category_Map' ) ) {
			return;
		}

		$category_map = new Mobo_Core_Category_Map();
		$rows         = $category_map->list_mappings( 1000 );
		$terms        = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			$terms = array();
		}
		?>
		<div class="mobo-card">
			<div class="mobo-card-head">
				<h2>Mapping دسته‌بندی‌های Mobo به WooCommerce</h2>
				<p>اگر دسته‌بندی‌های فروشگاه مشتری با دسته‌بندی‌های Portal فرق دارند، برای هر category_guid یک دسته محلی انتخاب کن. انتخاب نکردن یعنی fallback به دسته sync شده.</p>
			</div>

			<?php if ( empty( $rows ) ) : ?>
				<div class="mobo-note">
					فعلاً دسته‌ای برای mapping وجود ندارد. بعد از اولین sync دسته‌بندی یا migration از term meta، این جدول پر می‌شود.
				</div>
			<?php else : ?>
				<div class="mobo-table-wrap">
					<table class="widefat striped">
						<thead>
							<tr>
								<th>دسته Mobo</th>
								<th>Remote GUID</th>
								<th>دسته sync شده</th>
								<th>دسته محلی دستی</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								$remote_guid = sanitize_text_field( (string) $row['remote_guid'] );
								$manual_id   = absint( isset( $row['manual_term_id'] ) ? $row['manual_term_id'] : 0 );
								$remote_name = sanitize_text_field( (string) ( isset( $row['remote_name'] ) ? $row['remote_name'] : '' ) );
								if ( '' === $remote_name ) {
									$remote_name = 'Mobo Category';
								}
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $remote_name ); ?></strong>
										<?php if ( ! empty( $row['remote_url'] ) ) : ?>
											<div class="mobo-help" dir="ltr"><?php echo esc_html( (string) $row['remote_url'] ); ?></div>
										<?php endif; ?>
									</td>
									<td dir="ltr"><code><?php echo esc_html( $remote_guid ); ?></code></td>
									<td>
										<?php echo ! empty( $row['synced_term_name'] ) ? esc_html( $row['synced_term_name'] ) : '—'; ?>
									</td>
									<td>
										<select class="mobo-category-select2" name="mobo_category_map[<?php echo esc_attr( $remote_guid ); ?>]" data-placeholder="جستجو و انتخاب دسته محلی">
											<option value="0">بدون mapping دستی / fallback</option>
											<?php foreach ( $terms as $term ) : ?>
												<option value="<?php echo esc_attr( absint( $term->term_id ) ); ?>" <?php selected( $manual_id, absint( $term->term_id ) ); ?>>
													<?php echo esc_html( $term->name ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
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
						<input type="number" id="global_additional_price" class="mobo-money-input" name="global_additional_price" value="<?php echo esc_attr( $static_price ); ?>" min="0" step="1">
						<div class="mobo-price-preview" data-empty="مقدار وارد نشده">—</div>
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

							<div class="mobo-price-input-wrap"><input type="number" class="mobo-money-input" name="mobo_dynamic_low[]" value="<?php echo esc_attr( $low ); ?>" min="0" step="1"><div class="mobo-price-preview" data-empty="—">—</div></div>
							<div class="mobo-price-input-wrap"><input type="number" class="mobo-money-input" name="mobo_dynamic_high[]" value="<?php echo esc_attr( $high ); ?>" min="0" step="1"><div class="mobo-price-preview" data-empty="—">—</div></div>

							<select name="mobo_dynamic_benefit_type[]">
								<option value="static" <?php selected( $benefit_type, 'static' ); ?>>مبلغ ثابت</option>
								<option value="percentage" <?php selected( $benefit_type, 'percentage' ); ?>>درصدی</option>
							</select>

							<div class="mobo-price-input-wrap"><input type="number" class="mobo-money-input mobo-benefit-input" name="mobo_dynamic_benefit[]" value="<?php echo esc_attr( $benefit ); ?>" min="0" step="0.01"><div class="mobo-price-preview" data-empty="—">—</div></div>

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
		?>
		<div class="mobo-card">
			<div class="mobo-card-head">
				<h2>اعمال مجدد سیاست قیمت‌گذاری</h2>
				<p>قیمت خام دریافتی از .NET در meta محصولات نگهداری می‌شود. با این ابزار می‌توانید پس از تغییر سیاست سود، قیمت همه محصولات و تنوع‌های sync شده را بدون دریافت مجدد دیتا از Portal محاسبه و اعمال کنید.</p>
			</div>

			<div class="mobo-progress-wrap">
				<div class="mobo-progress-meta">
					<span>پیشرفت اعمال قیمت</span>
					<strong><?php echo esc_html( $this->format_percent( $percent ) ); ?>٪</strong>
				</div>
				<div class="mobo-progress"><div style="width: <?php echo esc_attr( min( 100, max( 0, $percent ) ) ); ?>%;"></div></div>
			</div>

			<div class="mobo-status-grid">
				<?php $this->status_box( 'وضعیت', $this->reprice_status_label( $state ) ); ?>
				<?php $this->status_box( 'قابل پردازش', isset( $status['total'] ) ? absint( $status['total'] ) : 0 ); ?>
				<?php $this->status_box( 'پردازش‌شده', isset( $status['processed'] ) ? absint( $status['processed'] ) : 0 ); ?>
				<?php $this->status_box( 'به‌روزرسانی‌شده', isset( $status['updated'] ) ? absint( $status['updated'] ) : 0 ); ?>
				<?php $this->status_box( 'ناموفق', isset( $status['failed'] ) ? absint( $status['failed'] ) : 0 ); ?>
				<?php $this->status_box( 'آخرین ID', isset( $status['lastPostId'] ) ? absint( $status['lastPostId'] ) : 0 ); ?>
			</div>

			<?php if ( ! empty( $status['lastMessage'] ) ) : ?>
				<div class="mobo-message mobo-message-info"><?php echo esc_html( $status['lastMessage'] ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $status['lastError'] ) ) : ?>
				<div class="mobo-message mobo-message-error"><?php echo esc_html( $status['lastError'] ); ?></div>
			<?php endif; ?>

			<div class="mobo-actions">
				<?php if ( ! $is_running ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="mobo_core_start_reprice">
						<?php wp_nonce_field( 'mobo_core_start_reprice', 'mobo_core_nonce' ); ?>
						<button type="submit" class="mobo-btn mobo-btn-primary">اعمال مجدد قیمت روی همه محصولات</button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="mobo_core_cancel_reprice">
						<?php wp_nonce_field( 'mobo_core_cancel_reprice', 'mobo_core_nonce' ); ?>
						<button type="submit" class="mobo-btn mobo-btn-danger">توقف اعمال قیمت</button>
					</form>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mobo_core_reset_reprice">
					<?php wp_nonce_field( 'mobo_core_reset_reprice', 'mobo_core_nonce' ); ?>
					<button type="submit" class="mobo-btn mobo-btn-light">پاک کردن وضعیت</button>
				</form>
			</div>

			<div class="mobo-note">
				این عملیات محصول را از Portal دوباره دریافت نمی‌کند؛ فقط از metaهای <code>mobo_api_price</code> و <code>mobo_api_compare_price</code> استفاده می‌کند. پردازش مرحله‌ای است و توسط self-runner ادامه پیدا می‌کند.
			</div>
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
			default:
				return 'آماده';
		}
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
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>

			<input
				type="text"
				id="<?php echo esc_attr( $key ); ?>"
				name="<?php echo esc_attr( $key ); ?>"
				value=""
				placeholder="برای تغییر، مقدار جدید را وارد کنید"
				dir="ltr"
			>

			<?php if ( '' !== $help ) : ?>
				<div class="mobo-help"><?php echo esc_html( $help ); ?></div>
			<?php endif; ?>
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
	private function status_box( $label, $value, $field = '' ) {
		?>
		<div class="mobo-status-box" <?php echo '' !== $field ? 'data-mobo-sync-box="' . esc_attr( $field ) . '"' : ''; ?>>
			<span><?php echo esc_html( $label ); ?></span>
			<strong <?php echo '' !== $field ? 'data-mobo-sync-field="' . esc_attr( $field ) . '"' : ''; ?>><?php echo esc_html( (string) $value ); ?></strong>
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
			'mobo_core_category_mapping_enabled',
			'mobo_core_category_mapping_required',
			'mobo_core_only_in_stock',
			'mobo_core_real_cron_process_webhooks',
			'mobo_core_process_webhook_on_receive',
			'mobo_core_self_runner_enabled',
			'mobo_core_self_runner_continue_enabled',
			'mobo_core_health_report_enabled',
			'mobo_core_image_queue_enabled',
			'mobo_core_image_queue_blocking',
			'mobo_core_product_cursor_sync_enabled',
			'mobo_core_variant_cursor_sync_enabled',
			'mobo_core_checkout_validation_enabled',
			'mobo_core_checkout_validate_only_mobo_products',
			'mobo_core_checkout_require_remote_guid',
			'mobo_core_checkout_block_incomplete_sync',
			'mobo_core_checkout_local_stock_check_enabled',
			'mobo_core_checkout_external_validation_enabled',
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
			'mobo_core_image_max_try'        => array( 1, 20 ),
			'mobo_core_image_retry_base_seconds' => array( 30, 900 ),
			'mobo_core_webhook_files_per_run'=> array( 1, 10 ),
			'mobo_core_webhook_max_try'      => array( 1, 20 ),
			'mobo_core_webhook_expire_days'  => array( 1, 30 ),
			'mobo_core_real_cron_time_budget_seconds' => array( 5, 55 ),
			'mobo_core_real_cron_max_sync_steps' => array( 1, 20 ),
			'mobo_core_real_cron_lock_ttl_seconds' => array( 30, 600 ),
			'mobo_core_self_runner_min_interval_seconds' => array( 0, 60 ),
			'mobo_core_self_runner_http_timeout_seconds' => array( 1, 10 ),
			'mobo_core_health_report_min_interval_seconds' => array( 60, 3600 ),
			'mobo_core_health_report_timeout_seconds' => array( 5, 60 ),
			'mobo_core_checkout_external_timeout_seconds' => array( 1, 10 ),
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

		/*
		* Connection secrets.
		*
		* Do not overwrite existing secret values with empty input.
		*/
		if ( isset( $_POST['mobo_core_token'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_POST['mobo_core_token'] ) );

			if ( '' !== $token ) {
				update_option( 'mobo_core_token', $token, false );
			}
		}

		if ( isset( $_POST['mobo_core_security_code'] ) ) {
			$security_code = sanitize_text_field( wp_unslash( $_POST['mobo_core_security_code'] ) );

			if ( '' !== $security_code ) {
				update_option( 'mobo_core_security_code', $security_code, false );
			}
		}

		if ( isset( $_POST['mobo_core_cron_token'] ) ) {
			$cron_token = sanitize_text_field( wp_unslash( $_POST['mobo_core_cron_token'] ) );

			if ( '' !== $cron_token ) {
				update_option( 'mobo_core_cron_token', $cron_token, false );
			}
		}

		if ( isset( $_POST['mobo_core_health_report_url'] ) ) {
			update_option(
				'mobo_core_health_report_url',
				esc_url_raw( trim( wp_unslash( $_POST['mobo_core_health_report_url'] ) ) ),
				false
			);
		}

		if ( isset( $_POST['mobo_core_checkout_external_validation_url'] ) ) {
			update_option(
				'mobo_core_checkout_external_validation_url',
				esc_url_raw( trim( wp_unslash( $_POST['mobo_core_checkout_external_validation_url'] ) ) ),
				false
			);
		}

		if ( isset( $_POST['mobo_core_checkout_external_error_behavior'] ) ) {
			$checkout_error_behavior = sanitize_key( wp_unslash( $_POST['mobo_core_checkout_external_error_behavior'] ) );

			if ( ! in_array( $checkout_error_behavior, array( 'allow', 'block' ), true ) ) {
				$checkout_error_behavior = 'allow';
			}

			update_option( 'mobo_core_checkout_external_error_behavior', $checkout_error_behavior, false );
		}


		if ( isset( $_POST['mobo_category_map'] ) && is_array( $_POST['mobo_category_map'] ) && class_exists( 'Mobo_Core_Category_Map' ) ) {
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
		}

		$this->save_dynamic_price_rules();

		$this->redirect_with_message( 'تنظیمات ذخیره شد.', 'success', $tab );
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

		$status['statusLabel']          = $this->status_label( $status['status'] );
		$status['progressPercentLabel'] = $this->format_percent( $status['progressPercent'] );
		$status['nextRetryAtLabel']     = ! empty( $status['nextRetryAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['nextRetryAt'] ) ) : '—';
		$status['serverTime']           = wp_date( 'Y-m-d H:i:s' );

		wp_send_json_success( $status );
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
	 * Resume a waiting manual sync without resetting cursor/page state.
	 *
	 * @return void
	 */
	public function handle_resume_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_resume_sync', 'mobo_core_nonce' );

		$state = get_option( Mobo_Core_Product_Sync::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$state['status']              = 'running';
		$state['lastError']           = '';
		$state['transientRetryCount'] = 0;
		$state['nextRetryAt']         = 0;
		$state['lastMessage']         = 'همگام‌سازی از آخرین نقطه ذخیره‌شده ادامه داده می‌شود.';
		$state['updatedAt']           = time();
		update_option( Mobo_Core_Product_Sync::STATE_OPTION, $state, false );

		if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'admin-sync-resume', true );
		}

		$this->redirect_with_message( 'ادامه sync از آخرین نقطه ذخیره‌شده شروع شد.', 'success', 'dashboard' );
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
			case 'waiting_for_portal':
				return 'در انتظار اتصال Portal';
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
				function initCategorySelect2(context) {
					var $context = context ? $(context) : $(document);
					var $selects = $context.find('.mobo-category-select2');

					if (! $selects.length) {
						return;
					}

					if ($.fn.selectWoo) {
						$selects.each(function() {
							var $select = $(this);

							if ($select.data('select2') || $select.data('selectWoo')) {
								return;
							}

							$select.selectWoo({
								width: '100%',
								dir: 'rtl',
								allowClear: true,
								placeholder: $select.data('placeholder') || 'جستجو و انتخاب دسته'
							});
						});
						return;
					}

					if ($.fn.select2) {
						$selects.each(function() {
							var $select = $(this);

							if ($select.data('select2')) {
								return;
							}

							$select.select2({
								width: '100%',
								dir: 'rtl',
								allowClear: true,
								placeholder: $select.data('placeholder') || 'جستجو و انتخاب دسته'
							});
						});
					}
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

					if (formatted === '') {
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

					$preview.text(formatted + ' تومان');
				}

				function updateAllPricePreviews(context) {
					var $context = context ? $(context) : $(document);
					$context.find('.mobo-money-input').each(function() {
						updatePricePreview(this);
					});
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
					var row = ''
						+ '<div class="mobo-dynamic-row">'
						+ '<select name="mobo_dynamic_is_active[]"><option value="true">بله</option><option value="false">خیر</option></select>'
						+ '<div class="mobo-price-input-wrap"><input type="number" class="mobo-money-input" name="mobo_dynamic_low[]" value="" min="0" step="1"><div class="mobo-price-preview" data-empty="—">—</div></div>'
						+ '<div class="mobo-price-input-wrap"><input type="number" class="mobo-money-input" name="mobo_dynamic_high[]" value="" min="0" step="1"><div class="mobo-price-preview" data-empty="—">—</div></div>'
						+ '<select name="mobo_dynamic_benefit_type[]"><option value="static">مبلغ ثابت</option><option value="percentage">درصدی</option></select>'
						+ '<div class="mobo-price-input-wrap"><input type="number" class="mobo-money-input mobo-benefit-input" name="mobo_dynamic_benefit[]" value="" min="0" step="0.01"><div class="mobo-price-preview" data-empty="—">—</div></div>'
						+ '<button type="button" class="mobo-remove-row" aria-label="حذف">×</button>'
						+ '</div>';

					$('#mobo-dynamic-rows').append(row);
					updateAllPricePreviews($('#mobo-dynamic-rows').children().last());
				});

				$(document).on('input change', '.mobo-money-input', function() {
					updatePricePreview(this);
				});

				$(document).on('change', 'select[name="mobo_dynamic_benefit_type[]"]', function() {
					updatePricePreview($(this).closest('.mobo-dynamic-row').find('.mobo-benefit-input'));
				});

				$(document).on('click', '.mobo-remove-row', function() {
					var rows = $('.mobo-dynamic-row').not('.mobo-dynamic-row-head');

					if (rows.length <= 1) {
						$(this).closest('.mobo-dynamic-row').find('input').val('');
						return;
					}

					$(this).closest('.mobo-dynamic-row').remove();
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
						$('[data-mobo-sync-updated-at]').text(status.serverTime ? 'آخرین بررسی: ' + status.serverTime : '');

						var $hero = $('[data-mobo-sync-hero-status]');
						$hero.text(status.isWaitingForPortal ? 'در انتظار اتصال Portal' : (status.isRunning ? 'در حال همگام‌سازی' : statusLabel));
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
								$('[data-mobo-sync-refresh-state]').text('به‌روزرسانی خودکار فعال است.');
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


				initCategorySelect2();
				switchPriceSection();
				updateAllPricePreviews();
				initSyncStatusAutoRefresh();
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

			.mobo-wrap .select2-container,
			.mobo-wrap .select2-container--default,
			.mobo-wrap .select2-container--default .select2-selection--single,
			.mobo-wrap .select2-container--default .select2-selection--multiple {
				width: 100% !important;
			}

			.mobo-wrap .select2-container .select2-selection--single,
			.mobo-wrap .selectWoo-container .select2-selection--single {
				min-height: 42px;
				border-color: #dbe3ef;
				border-radius: 13px;
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

			.mobo-message-warning {
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