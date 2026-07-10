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
		add_action( 'admin_post_mobo_core_tool_test_mobo_login', array( $this, 'handle_admin_tool_action' ) );
		add_action( 'admin_post_mobo_core_tool_clear_mobo_debug_log', array( $this, 'handle_admin_tool_action' ) );
		add_action( 'admin_post_mobo_core_tool_clear_shipping_diagnostics', array( $this, 'handle_admin_tool_action' ) );
		add_action( 'admin_post_mobo_core_tool_sync_address_mapping', array( $this, 'handle_admin_tool_action' ) );
		add_action( 'admin_post_mobo_core_tool_sync_remote_shipping_methods', array( $this, 'handle_admin_tool_action' ) );
		add_action( 'admin_post_mobo_core_tool_run_cron_now', array( $this, 'handle_admin_tool_action' ) );
		add_action( 'admin_post_mobo_core_start_sync', array( $this, 'handle_start_sync' ) );
		add_action( 'admin_post_mobo_core_start_repair', array( $this, 'handle_start_repair' ) );
		add_action( 'admin_post_mobo_core_sync_categories', array( $this, 'handle_sync_categories' ) );
		add_action( 'admin_post_mobo_core_resume_sync', array( $this, 'handle_resume_sync' ) );
		add_action( 'admin_post_mobo_core_cancel_sync', array( $this, 'handle_cancel_sync' ) );
		add_action( 'admin_post_mobo_core_reset_sync', array( $this, 'handle_reset_sync' ) );
		add_action( 'admin_post_mobo_core_quarantine_duplicate_products', array( $this, 'handle_quarantine_duplicate_products' ) );
		add_action( 'admin_post_mobo_core_start_reprice', array( $this, 'handle_start_reprice' ) );
		add_action( 'admin_post_mobo_core_cancel_reprice', array( $this, 'handle_cancel_reprice' ) );
		add_action( 'admin_post_mobo_core_reset_reprice', array( $this, 'handle_reset_reprice' ) );
		add_action( 'admin_post_mobo_core_start_recategorize', array( $this, 'handle_start_recategorize' ) );
		add_action( 'admin_post_mobo_core_cancel_recategorize', array( $this, 'handle_cancel_recategorize' ) );
		add_action( 'admin_post_mobo_core_reset_recategorize', array( $this, 'handle_reset_recategorize' ) );
		add_action( 'admin_post_mobo_core_retry_failed_webhooks', array( $this, 'handle_retry_failed_webhooks' ) );
		add_action( 'admin_post_mobo_core_scan_legacy_images', array( $this, 'handle_scan_legacy_images' ) );
		add_action( 'admin_post_mobo_core_enqueue_image_refresh', array( $this, 'handle_enqueue_image_refresh' ) );
		add_action( 'admin_post_mobo_core_process_image_refresh', array( $this, 'handle_process_image_refresh' ) );
		add_action( 'admin_post_mobo_core_retry_image_refresh', array( $this, 'handle_retry_image_refresh' ) );
		add_action( 'admin_post_mobo_core_reset_image_refresh', array( $this, 'handle_reset_image_refresh' ) );
		add_action( 'admin_post_mobo_core_scan_orphan_images', array( $this, 'handle_scan_orphan_images' ) );
		add_action( 'admin_post_mobo_core_delete_orphan_images', array( $this, 'handle_delete_orphan_images' ) );
		add_action( 'admin_post_mobo_core_reset_orphan_images', array( $this, 'handle_reset_orphan_images' ) );
		add_action( 'wp_ajax_mobo_core_get_sync_status', array( $this, 'handle_ajax_sync_status' ) );
		add_action( 'wp_ajax_mobo_core_get_reprice_status', array( $this, 'handle_ajax_reprice_status' ) );
		add_action( 'wp_ajax_mobo_core_get_recategorize_status', array( $this, 'handle_ajax_recategorize_status' ) );
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
		$is_repair_run = ! empty( $status['repairMode'] );

		// Read-only admin navigation parameter; no state is changed here.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$allowed_tabs = array(
			'dashboard',
			'connection',
			'purchase',
			'product',
			'categories',
			'pricing',
			'filters',
			'queue',
			'image-refresh',
			'cron',
			'checkout',
			'sms',
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
						<?php echo $is_waiting ? 'در انتظار اتصال MoboCore' : ( $is_running ? ( $is_repair_run ? 'در حال Repair' : 'در حال همگام‌سازی' ) : esc_html( $this->status_label( $status['status'] ) ) ); ?>
					</strong>
				</div>
			</div>

			<?php $this->render_notices(); ?>

			<nav class="mobo-tabs" aria-label="Mobo settings tabs">
				<?php $this->tab_link( 'dashboard', 'داشبورد', $active_tab ); ?>
				<?php $this->tab_link( 'connection', 'اتصال', $active_tab ); ?>
				<?php $this->tab_link( 'purchase', 'خرید و فعال سازی', $active_tab ); ?>
				<?php $this->tab_link( 'product', 'محصول', $active_tab ); ?>
				<?php $this->tab_link( 'categories', 'دسته‌بندی', $active_tab ); ?>
				<?php $this->tab_link( 'pricing', 'قیمت‌گذاری', $active_tab ); ?>
				<?php $this->tab_link( 'filters', 'فیلترها', $active_tab ); ?>
				<?php $this->tab_link( 'queue', 'صف و پردازش', $active_tab ); ?>
				<?php $this->tab_link( 'image-refresh', 'نوسازی تصاویر', $active_tab ); ?>
				<?php $this->tab_link( 'cron', 'کران واقعی', $active_tab ); ?>
				<?php $this->tab_link( 'checkout', 'اعتبارسنجی خرید', $active_tab ); ?>
				<?php $this->tab_link( 'sms', 'پیامک سفارش', $active_tab ); ?>
				<?php $this->tab_link( 'health', 'سلامت سایت', $active_tab ); ?>
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
		$repair_completed_at = class_exists( 'Mobo_Core_Product_Sync' ) ? Mobo_Core_Product_Sync::get_repair_completed_at() : 0;
		$is_repair_completed = $repair_completed_at > 0;
		$legacy_repair_required = '1' === (string) get_option( 'mobo_core_legacy_repair_required', '0' ) && ! $is_repair_completed;

		?>
		<?php if ( $legacy_repair_required ) : ?>
			<div class="mobo-card" data-mobo-legacy-repair-required>
				<div class="mobo-card-head">
					<h2>نیاز به Repair بعد از ارتقا از نسخه قدیمی</h2>
					<p>برای سایت‌هایی که از نسخه‌های قدیمی مثل ۷ به نسخه جدید آمده‌اند، یک Repair کامل لازم است تا محصول‌ها، map داخلی و صف تصاویر با ساختار جدید همخوان شوند.</p>
				</div>
				<div class="mobo-message mobo-message-warning">
					قبل از نوسازی تصاویر یا اتکا به صف‌های جدید، دکمه «شروع Repair محصولات» را اجرا کن و بگذار کامل شود. بعد از اتمام، زمان Repair در دیتابیس با option <code dir="ltr">mobo_core_repair_completed_at</code> ذخیره می‌شود.
				</div>
			</div>
		<?php endif; ?>

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
					<?php $this->status_box( 'نوع اجرا', ! empty( $status['repairMode'] ) ? 'Repair / hash bypass' : 'Normal sync' ); ?>
					<?php $this->status_box( 'محصولات پردازش‌شده', absint( $status['processedProducts'] ), 'processedProducts' ); ?>
					<?php $this->status_box( 'محصولات باقی‌مانده', absint( $status['remainingProducts'] ), 'remainingProducts' ); ?>
					<?php $this->status_box( 'صفحه محصول', absint( $status['productPage'] ), 'productPage' ); ?>
					<?php $this->status_box( 'صفحه تنوع', absint( $status['variantPage'] ), 'variantPage' ); ?>
					<?php $this->status_box( 'تلاش بعدی', ! empty( $status['nextRetryAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['nextRetryAt'] ) ) : '—', 'nextRetryAt' ); ?>
					<?php $this->status_box( 'Repair', $is_repair_completed ? 'انجام شده: ' . wp_date( 'Y-m-d H:i:s', $repair_completed_at ) : 'انجام نشده' ); ?>
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

				<?php $pricing_warning = $this->get_pricing_health_warning(); ?>
				<?php if ( '' !== $pricing_warning ) : ?>
					<div class="mobo-message mobo-message-warning">
						<strong>هشدار قیمت‌گذاری:</strong> <?php echo esc_html( $pricing_warning ); ?>
					</div>
				<?php endif; ?>

				<?php if ( class_exists( 'Mobo_Core_Product_Concurrency' ) ) : ?>
					<?php $duplicate_groups_count = Mobo_Core_Product_Concurrency::count_duplicate_product_groups(); ?>
					<?php if ( $duplicate_groups_count > 0 ) : ?>
						<div class="mobo-message mobo-message-error">
							<strong>هشدار محصول تکراری:</strong>
							<?php echo esc_html( sprintf( '%d شناسه محصول موبو روی بیش از یک محصول ووکامرس دیده شد. تا زمان پاکسازی، فقط محصول اصلی بروزرسانی می‌شود و نسخه‌های تکراری در صف قیمت‌گذاری و دسته‌بندی رد می‌شوند.', absint( $duplicate_groups_count ) ) ); ?>
							<?php $duplicate_groups = Mobo_Core_Product_Concurrency::get_duplicate_product_groups( 3 ); ?>
							<?php if ( ! empty( $duplicate_groups ) ) : ?>
								<div class="mobo-mini-list">
									<?php foreach ( $duplicate_groups as $duplicate_group ) : ?>
										<div>
											<?php echo esc_html( 'شناسه موبو: ' . (string) $duplicate_group['product_guid'] . ' — محصولات ووکامرس: ' . (string) $duplicate_group['ids'] ); ?>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('محصولات تکراری به حالت پیش‌نویس منتقل شوند؟ محصول اصلی حذف نمی‌شود و این کار قابل برگشت است.');" style="margin-top:10px;">
								<input type="hidden" name="action" value="mobo_core_quarantine_duplicate_products">
								<?php wp_nonce_field( 'mobo_core_quarantine_duplicate_products', 'mobo_core_nonce' ); ?>
								<button type="submit" class="mobo-btn mobo-btn-danger">انتقال نسخه‌های تکراری به پیش‌نویس</button>
							</form>
						</div>
					<?php endif; ?>
				<?php endif; ?>

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
					<?php if ( ! $is_running && ! $is_waiting ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mobo_core_sync_categories">
							<?php wp_nonce_field( 'mobo_core_sync_categories', 'mobo_core_nonce' ); ?>
							<button type="submit" class="mobo-btn mobo-btn-light">
								همگام‌سازی دسته‌بندی‌ها برای نگاشت
							</button>
						</form>
					<?php endif; ?>

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

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Repair محصولات اجرا شود؟ این اجرا فقط hash check را bypass می‌کند و همچنان از تنظیمات فعلی sync پیروی می‌کند.');">
							<input type="hidden" name="action" value="mobo_core_start_repair">
							<?php wp_nonce_field( 'mobo_core_start_repair', 'mobo_core_nonce' ); ?>

							<button type="submit" class="mobo-btn mobo-btn-light">
								شروع Repair محصولات
							</button>
						</form>
					<?php endif; ?>

					<?php if ( $is_running || $is_waiting ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-mobo-cancel-sync-form="1">
							<input type="hidden" name="action" value="mobo_core_cancel_sync">
							<?php wp_nonce_field( 'mobo_core_cancel_sync', 'mobo_core_nonce' ); ?>

							<button type="submit" class="mobo-btn mobo-btn-danger" data-mobo-cancel-sync-button="1">
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
					اجرای مرحله‌ای روی خود همین وردپرس انجام می‌شود. اگر MoboCore موقتاً قطع شود، sync از آخرین cursor/page ذخیره‌شده ادامه پیدا می‌کند. Repair فقط hash check را bypass می‌کند و بقیه فیلدها تابع تنظیمات فعلی هستند.
				</div>

			</div>
		</div>

		<?php $this->render_license_info_card(); ?>
		<?php
	}

	/**
	 * Render purchase and activation tab.
	 *
	 * @return void
	 */
	private function render_purchase_tab() {
		$purchase_url   = defined( 'MOBO_CORE_PURCHASE_URL' ) ? MOBO_CORE_PURCHASE_URL : 'https://mobo.codeya.ir/';
		$github_url     = defined( 'MOBO_CORE_GITHUB_URL' ) ? MOBO_CORE_GITHUB_URL : 'https://github.com/PedramDev/mobo-core';
		$sales_phone   = defined( 'MOBO_CORE_SALES_PHONE' ) ? MOBO_CORE_SALES_PHONE : '+989124508218';
		$sales_tel     = defined( 'MOBO_CORE_SALES_TEL_URL' ) ? MOBO_CORE_SALES_TEL_URL : 'tel:+989124508218';
		$sales_tg      = defined( 'MOBO_CORE_SALES_TELEGRAM_URL' ) ? MOBO_CORE_SALES_TELEGRAM_URL : 'https://t.me/yazdan_ghadiri';
		$sales_wa      = defined( 'MOBO_CORE_SALES_WHATSAPP_URL' ) ? MOBO_CORE_SALES_WHATSAPP_URL : 'https://wa.me/989124508218';
		$tech_phone    = defined( 'MOBO_CORE_TECH_PHONE' ) ? MOBO_CORE_TECH_PHONE : '+989367362228';
		$tech_tg       = defined( 'MOBO_CORE_TECH_TELEGRAM_URL' ) ? MOBO_CORE_TECH_TELEGRAM_URL : 'https://t.me/Codeya';
		$connection_url = add_query_arg(
			array(
				'page' => 'mobo-core',
				'tab'  => 'connection',
			),
			admin_url( 'admin.php' )
		);

		?>
		<div class="mobo-grid">
			<div class="mobo-card mobo-card-wide">
				<div class="mobo-card-head">
					<h2>خرید، فعال سازی و اتصال به MoboCore</h2>
					<p>این افزونه برای فروشگاه های ایران طراحی شده و منبع اصلی محصولات و سفارش های موبویی آن <code dir="ltr">mobomobo.ir</code> است. برای استفاده از همگام سازی، ثبت سفارش اتوماتیک، وب هوک و گزارش سلامت، سایت باید در MoboCore ثبت و لایسنس فعال دریافت کند.</p>
				</div>

				<div class="mobo-status-grid">
					<?php $this->status_box( 'آدرس خرید و پنل', 'mobo.codeya.ir' ); ?>
					<?php $this->status_box( 'بازار هدف', 'فروشگاه های ایران' ); ?>
					<?php $this->status_box( 'منبع اصلی', 'mobomobo.ir' ); ?>
					<?php $this->status_box( 'وضعیت اتصال', '' !== (string) get_option( 'mobo_core_token', '' ) ? 'Token ثبت شده' : 'Token ثبت نشده' ); ?>
					<?php $this->status_box( 'وب هوک', '' !== (string) get_option( 'mobo_core_security_code', '' ) ? 'کد امنیتی ثبت شده' : 'کد امنیتی ثبت نشده' ); ?>
				</div>

				<div class="mobo-actions" style="margin-top:16px;">
					<a class="mobo-btn mobo-btn-primary" href="<?php echo esc_url( $purchase_url ); ?>" target="_blank" rel="noopener noreferrer">خرید یا ورود به MoboCore</a>
					<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( $connection_url ); ?>">رفتن به تنظیمات اتصال</a>
					<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( $github_url ); ?>" target="_blank" rel="noopener noreferrer">مشاهده سورس در GitHub</a>
				</div>
			</div>

			<div class="mobo-card mobo-card-wide">
				<div class="mobo-card-head">
					<h2>تماس برای خرید و پشتیبانی</h2>
					<p>برای خرید لایسنس، راه اندازی اولیه یا سوال فنی، از مسیر مناسب زیر استفاده کنید. بخش فروش برای خرید و فعال سازی است؛ بخش فنی برای نصب، اتصال، خطا و راه اندازی افزونه است.</p>
				</div>
				<div class="mobo-status-grid">
					<div class="mobo-status-box">
						<strong>بخش فروش</strong>
						<span dir="ltr"><?php echo esc_html( $sales_phone ); ?></span>
						<div class="mobo-actions" style="margin-top:10px;">
							<a class="mobo-btn mobo-btn-primary" href="<?php echo esc_url( $sales_tel ); ?>">تماس تلفنی</a>
							<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( $sales_wa ); ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
							<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( $sales_tg ); ?>" target="_blank" rel="noopener noreferrer">Telegram</a>
						</div>
					</div>
					<div class="mobo-status-box">
						<strong>بخش فنی</strong>
						<span dir="ltr"><?php echo esc_html( $tech_phone ); ?></span>
						<div class="mobo-actions" style="margin-top:10px;">
							<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( 'tel:' . $tech_phone ); ?>">تماس تلفنی</a>
							<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( $tech_tg ); ?>" target="_blank" rel="noopener noreferrer">Telegram</a>
						</div>
					</div>
				</div>
			</div>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>مراحل راه اندازی</h2>
					<p>این مسیر برای مدیر سایت است و نیازی به تنظیمات فنی اضافه ندارد.</p>
				</div>
				<div class="mobo-guide-table-wrap">
					<table class="widefat striped mobo-guide-table">
						<tbody>
							<tr><td>۱</td><td>از طریق <a href="<?php echo esc_url( $purchase_url ); ?>" target="_blank" rel="noopener noreferrer">mobo.codeya.ir</a> لایسنس تهیه یا وارد پنل شوید.</td></tr>
							<tr><td>۲</td><td>دامنه همین سایت را در MoboCore ثبت کنید. این اتصال برای فروشگاه های ایران و منبع <code dir="ltr">mobomobo.ir</code> طراحی شده است.</td></tr>
							<tr><td>۳</td><td>Token و Webhook Security Code را از پنل MoboCore بردارید.</td></tr>
							<tr><td>۴</td><td>در تب «اتصال»، Token و Webhook Security Code را ذخیره کنید.</td></tr>
							<tr><td>۵</td><td>در تب «اعتبارسنجی خرید»، نگاشت آدرس و روش های ارسال را تکمیل کنید.</td></tr>
							<tr><td>۶</td><td>اگر از نسخه قدیمی مثل ۷ ارتقا داده اید، یک بار Repair کامل محصولات را از داشبورد اجرا کنید.</td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>سرویس خارجی مورد استفاده</h2>
					<p>این افزونه برای کارکرد اصلی خود به سرویس MoboCore متصل می شود و برای بررسی یا ثبت سفارش موبویی، منبع اصلی آن <code dir="ltr">mobomobo.ir</code> است.</p>
				</div>
				<div class="mobo-note">
					دامنه MoboCore: <code dir="ltr">mobo.codeya.ir</code><br>
					منبع اصلی کالا و سفارش موبویی: <code dir="ltr">mobomobo.ir</code><br>
					محدوده استفاده: فروشگاه های ایران که با موجودی، قیمت، آدرس و روش های ارسال قابل استفاده در ایران کار می کنند.<br>
					داده های احتمالی: دامنه سایت، Token، وضعیت لایسنس، اطلاعات محصول و تنوع، وضعیت صف ها، گزارش سلامت، وب هوک ها و اطلاعات لازم برای بررسی یا ثبت سفارش موبویی. اتصال فقط بعد از وارد کردن Token و فعال سازی تنظیمات مربوطه انجام می شود.
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

			<?php $this->render_license_info_card(); ?>

			<?php
			$this->guide_box(
				'راهنمای اتصال و امنیت',
				array(
					array( 'title' => 'Token', 'text' => 'برای درخواست‌هایی است که وردپرس به MoboCore یا API موبو می‌فرستد. اگر خالی ذخیره شود، مقدار قبلی حذف نمی‌شود.' ),
					array( 'title' => 'Webhook Security Code', 'text' => 'برای اعتبارسنجی درخواست‌های ورودی از MoboCore استفاده می‌شود و با header امنیتی X-SEC مقایسه می‌شود.' ),
					array( 'title' => 'تعویض اطلاعات', 'text' => 'برای تغییر هر secret فقط مقدار جدید را وارد کنید. نمایش ندادن مقدار قبلی به معنی پاک شدن آن نیست.' ),
				),
				'این اطلاعات را در اختیار کاربر نهایی قرار ندهید. تغییر Token یا Security Code باید با تنظیمات MoboCore هماهنگ باشد.'
			);
			$this->recommendation_box(
				'تنظیمات پیشنهادی اتصال',
				array(
					array( 'setting' => 'Token', 'value' => 'یک مقدار اختصاصی برای هر سایت', 'reason' => 'اگر چند سایت از یک Token مشترک استفاده کنند، ردیابی و محدودسازی خطا سخت می‌شود.' ),
					array( 'setting' => 'Webhook Security Code', 'value' => 'یک مقدار قوی و هماهنگ با MoboCore', 'reason' => 'این مقدار جلوی پذیرش webhook جعلی را می‌گیرد و باید با تنظیمات MoboCore یکی باشد.' ),
					array( 'setting' => 'تعویض secretها', 'value' => 'فقط هنگام جابه‌جایی لایسنس یا نشت اطلاعات', 'reason' => 'تغییر بی‌برنامه باعث reject شدن درخواست‌های MoboCore یا API می‌شود.' ),
				),
				'برای امنیت و پشتیبانی دقیق‌تر، Token و Security Code باید برای همین سایت اختصاصی باشند. از مقدار مشترک بین چند سایت استفاده نکنید.'
			);
			?>

			<?php $this->save_button(); ?>
		</form>
		<?php
	}


	/**
	 * Render license information card.
	 *
	 * @return void
	 */
	private function render_license_info_card() {
		$license_info = $this->get_license_info_for_display();
		$is_error     = ! empty( $license_info['error'] );
		$is_expired   = ! empty( $license_info['isExpired'] );
		$status_text  = $is_error ? 'نامشخص' : ( $is_expired ? 'منقضی شده' : 'فعال' );
		$message      = isset( $license_info['message'] ) ? (string) $license_info['message'] : '';
		$raw          = isset( $license_info['raw'] ) && is_array( $license_info['raw'] ) ? $license_info['raw'] : array();
		$details      = $this->license_info_display_details( $raw );

		?>
		<div class="mobo-card mobo-card-license" style="margin-top:16px;">
			<div class="mobo-card-head">
				<h2>اطلاعات لایسنس</h2>
				<p>این بخش از endpoint قدیمی <code>LicenseInfo</code> خوانده می‌شود و وضعیت اعتبار لایسنس را نمایش می‌دهد.</p>
			</div>

			<div class="mobo-status-grid">
				<?php $this->status_box( 'وضعیت لایسنس', $status_text ); ?>
				<?php $this->status_box( 'روزهای باقی‌مانده', $this->first_license_value( $raw, array( 'remainingDays', 'remainDays', 'daysRemaining', 'leftDays', 'remainingDayCount', 'RemainingDays', 'DaysRemaining' ) ) ); ?>
				<?php $this->status_box( 'تاریخ پایان', $this->first_license_value( $raw, array( 'expiresAt', 'expireAt', 'expirationDate', 'expiryDate', 'expireDate', 'validUntil', 'endDate', 'licenseEndAt', 'ExpiresAt', 'ExpireDate', 'ValidUntil' ) ) ); ?>
			</div>

			<?php if ( '' !== trim( $message ) ) : ?>
				<div class="mobo-message <?php echo $is_error || $is_expired ? 'mobo-message-error' : 'mobo-message-info'; ?>">
					<?php echo esc_html( $message ); ?>
				</div>
			<?php endif; ?>

			<?php
			$webhook_suspended = $this->first_license_value( $raw, array( 'webhook_suspended', 'webhookSuspended', 'WebhookSuspended' ) );
			$webhook_suspended = in_array( strtolower( (string) $webhook_suspended ), array( '1', 'true', 'yes', 'فعال' ), true );
			$webhook_suspended_until = $this->first_license_value( $raw, array( 'webhook_suspended_until', 'webhookSuspendedUntil', 'WebhookSuspendedUntil' ) );
			$webhook_suspension_reason = $this->first_license_value( $raw, array( 'webhook_suspension_reason', 'webhookSuspensionReason', 'WebhookSuspensionReason' ) );
			?>
			<?php if ( $webhook_suspended ) : ?>
				<div class="mobo-alert mobo-alert-warning">
					ارسال webhook از MoboCore برای این سایت موقتاً معلق شده است<?php echo '—' !== $webhook_suspended_until ? ' تا ' . esc_html( $webhook_suspended_until ) : ''; ?>. دلیل: <?php echo esc_html( '—' !== $webhook_suspension_reason ? $webhook_suspension_reason : 'خطاهای متوالی در دریافت webhook' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $details ) ) : ?>
				<div class="mobo-guide-table-wrap">
					<table class="widefat striped mobo-guide-table">
						<thead>
							<tr>
								<th>فیلد</th>
								<th>مقدار</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $details as $row ) : ?>
								<tr>
									<td><code><?php echo esc_html( $row['key'] ); ?></code></td>
									<td><?php echo esc_html( $row['value'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<div class="mobo-note">
				اگر این بخش خطای اتصال نشان دهد، معمولاً Token یا API Base URL مشکل دارد. این بخش sync را متوقف نمی‌کند، فقط وضعیت لایسنس را از MoboCore نمایش می‌دهد.
			</div>
		</div>
		<?php
	}

	/**
	 * Fetch license info for admin display.
	 *
	 * @return array
	 */
	private function get_license_info_for_display() {
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
			);
		}

		if ( ! is_array( $response ) ) {
			return array(
				'error'     => true,
				'isExpired' => false,
				'message'   => 'پاسخ LicenseInfo معتبر نیست.',
				'raw'       => array(),
			);
		}

		return array(
			'error'     => false,
			'isExpired' => $this->license_bool_value( isset( $response['isExpired'] ) ? $response['isExpired'] : false ),
			'message'   => isset( $response['message'] ) && is_scalar( $response['message'] ) ? (string) $response['message'] : '',
			'raw'       => $response,
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

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
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
				$value = $value ? 'true' : 'false';
			} elseif ( is_array( $value ) || is_object( $value ) ) {
				continue;
			} elseif ( null === $value || '' === trim( (string) $value ) ) {
				continue;
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

				<?php
				$this->guide_box(
					'راهنمای بروزرسانی محصول',
					array(
						array( 'title' => 'محصول جدید', 'text' => 'محصول جدید با اطلاعات پایه دریافتی از MoboCore ساخته می‌شود و معمولاً status آن publish است.' ),
						array( 'title' => 'محصول موجود', 'text' => 'گزینه‌های این بخش تعیین می‌کنند کدام فیلدهای محصول موجود دوباره نوشته شوند؛ اگر گزینه خاموش باشد، همان فیلد حفظ می‌شود.' ),
						array( 'title' => 'تصاویر', 'text' => 'تصاویر در صف مستقل پردازش می‌شوند تا Sync محصول روی دانلود تصویر قفل نشود. خطای تصویر، خود محصول را متوقف نمی‌کند.' ),
						array( 'title' => 'Trash', 'text' => 'محصول یا واریانت داخل سطل زباله آپدیت، restore یا publish نمی‌شود و duplicate جدید از همان GUID ساخته نمی‌شود.' ),
					),
					'برای جلوگیری از write اضافی، پلاگین برای واریانت‌ها hash نگه می‌دارد و اگر داده تغییر نکرده باشد، save سنگین ووکامرس اجرا نمی‌شود.'
				);
				?>
				<?php
				$this->recommendation_box(
					'تنظیمات پیشنهادی بروزرسانی محصول',
					array(
						array( 'setting' => 'بروزرسانی موجودی', 'value' => 'روشن', 'reason' => 'موجودی یک فیلد عملیاتی است و باید با منبع اصلی هماهنگ بماند.' ),
						array( 'setting' => 'بروزرسانی قیمت', 'value' => 'روشن', 'reason' => 'قیمت و سود وابسته به MoboCore هستند و باید در syncهای بعدی اصلاح شوند.' ),
						array( 'setting' => 'بروزرسانی عنوان', 'value' => 'خاموش برای سایت‌های SEO شده، روشن فقط در راه‌اندازی اولیه', 'reason' => 'تغییر عنوان محصول می‌تواند محتوای دستی و ساختار سئوی فروشگاه را خراب کند.' ),
						array( 'setting' => 'بروزرسانی آدرس محصول / slug', 'value' => 'خاموش بعد از انتشار سایت', 'reason' => 'تغییر slug باعث تغییر URL و نیاز به redirect می‌شود.' ),
						array( 'setting' => 'فقط محصولات موجود', 'value' => 'خاموش در حالت عمومی، روشن برای فروشگاه‌هایی که کالای ناموجود نمی‌خواهند', 'reason' => 'اگر روشن باشد، محصول ناموجود از ابتدا وارد سایت نمی‌شود و پوشش کاتالوگ کمتر می‌شود.' ),
						array( 'setting' => 'آپدیت عکس‌ها', 'value' => 'روشن همراه با صف مستقل تصویر', 'reason' => 'تصویرها باید sync شوند، اما نباید اجرای محصول را قفل کنند.' ),
					),
					'برای سایت فعال، Title و Slug را فقط وقتی روشن کنید که MoboCore مالک قطعی محتوای محصول باشد.'
				);
				?>
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
		$debug_log = $validator && method_exists( $validator, 'get_mobo_debug_log' ) ? $validator->get_mobo_debug_log() : array();
		$address_mapping = class_exists( 'Mobo_Core_Address_Mapping' ) ? new Mobo_Core_Address_Mapping() : null;
		$address_status  = $address_mapping && method_exists( $address_mapping, 'get_status' ) ? $address_mapping->get_status() : array();
		$address_manual_status = isset( $address_status['manualMapping'] ) && is_array( $address_status['manualMapping'] ) ? $address_status['manualMapping'] : array();
		$remote_shipping_manager = class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ? new Mobo_Core_Remote_Shipping_Methods() : null;
		$remote_shipping_status  = $remote_shipping_manager && method_exists( $remote_shipping_manager, 'get_status' ) ? $remote_shipping_manager->get_status() : array();
		$remote_shipping_methods = $remote_shipping_manager && method_exists( $remote_shipping_manager, 'get_methods' ) ? $remote_shipping_manager->get_methods() : array();
		$remote_shipping_snapshot = get_option( 'mobo_core_remote_shipping_methods_snapshot', array() );
		$remote_shipping_changed_at = absint( get_option( 'mobo_core_remote_shipping_methods_changed_at', 0 ) );
		$portal_webhook_delivery_status = get_option( 'mobo_core_portal_webhook_delivery_status', array() );
		$shipping_diagnostics = class_exists( 'Mobo_Core_Shipping_Diagnostics' ) ? new Mobo_Core_Shipping_Diagnostics() : null;
		$shipping_report = $shipping_diagnostics && method_exists( $shipping_diagnostics, 'get_last_report' ) ? $shipping_diagnostics->get_last_report() : array();
		$persian_wc_plugins = $this->get_active_persian_woocommerce_plugins();
		$persian_wc_status  = $this->get_persian_woocommerce_status();
		$poina_allowlist_status = $this->get_poina_domain_allowlist_status();
		$order_submission_enabled = Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' );
		$address_mapping_enabled = $order_submission_enabled;
		$address_checkout_active = ! empty( $address_status['checkoutActive'] );
		$checkout_master_enabled = Mobo_Core_Settings::enabled( 'mobo_core_checkout_validation_enabled', '0' );
		$mobo_cart_validation_enabled = $checkout_master_enabled && Mobo_Core_Settings::enabled( 'mobo_core_checkout_mobo_cart_validation_enabled', '0' );

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="checkout">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<?php if ( $address_mapping_enabled && ! $order_submission_enabled ) : ?>
				<div class="mobo-alert mobo-alert-warning">
					ثبت سفارش خودکار موبو غیرفعال است؛ بنابراین فیلدهای کشور/استان/شهر موبو روی checkout اعمال نمی‌شوند تا روش‌های حمل و نقل ووکامرس با کشور و استان استاندارد خودش نمایش داده شوند. cache شهرها همچنان قابل بروزرسانی است.
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $poina_allowlist_status['installed'] ) ) : ?>
				<div class="mobo-alert mobo-alert-warning">
					افزونه <code>poina-domain-allowlist</code> روی این سایت پیدا شد<?php echo ! empty( $poina_allowlist_status['active'] ) ? ' و فعال است' : ' اما فعال نیست'; ?>. اگر این افزونه درخواست‌های خروجی را محدود می‌کند، دامنه‌های <code>mobo.codeya.ir</code> و <code>mobomobo.ir</code> باید به allowlist آن اضافه شوند؛ در غیر این صورت عملیات‌هایی مثل دریافت اطلاعات لایسنس/MoboCore، ورود به موبو، بررسی سبد موبو و ثبت سفارش خودکار ممکن است انجام نشوند.
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $persian_wc_plugins ) ) : ?>
				<div class="mobo-alert mobo-alert-warning">
					افزونه فارسی‌ساز/پرشین ووکامرس روی سایت فعال است: <?php echo esc_html( implode( '، ', $persian_wc_plugins ) ); ?>. برای ثبت سفارش خودکار موبو، کافی است نگاشت کشور، استان و شهر را در همین صفحه کامل کنید. افزونه مقدار انتخاب‌شده در checkout را به شناسه‌های موبو تبدیل می‌کند و لازم نیست مدیر سایت با تنظیمات داخلی افزونه‌های دیگر درگیر شود.
				</div>
			<?php endif; ?>

			<div class="mobo-grid">
				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>سازگاری سیستم و checkout</h2>
						<p>این وضعیت‌ها فقط برای جلوگیری از خطاهای محیطی و تداخل افزونه‌ها نمایش داده می‌شوند.</p>
					</div>

					<div class="mobo-status-grid">
						<?php $this->status_box( 'Poina Domain Allowlist', ! empty( $poina_allowlist_status['installed'] ) ? ( ! empty( $poina_allowlist_status['active'] ) ? 'نصب و فعال' : 'نصب شده / غیرفعال' ) : 'پیدا نشد' ); ?>
						<?php $this->status_box( 'دامنه‌های لازم برای allowlist', 'mobo.codeya.ir / mobomobo.ir' ); ?>
						<?php $this->status_box( 'افزونه‌های فارسی ووکامرس', ! empty( $persian_wc_plugins ) ? implode( '، ', $persian_wc_plugins ) : 'پیدا نشد' ); ?>
					</div>

					<div class="mobo-note">
						اگر ثبت سفارش خودکار موبو خاموش باشد، checkout کاملا با روش عادی ووکامرس کار می‌کند. اگر آن را روشن می‌کنید، نگاشت آدرس و روش‌های ارسال موبو را در همین صفحه تکمیل و با یک سفارش تست بررسی کنید.
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
								<?php $this->status_box( 'آخرین تغییر روش‌های ارسال موبو', $remote_shipping_changed_at > 0 ? wp_date( 'Y-m-d H:i:s', $remote_shipping_changed_at ) : 'دریافت شده' ); ?>
								<?php $this->status_box( 'تعداد روش‌های ارسال دریافت‌شده', count( $shipping_items ) ); ?>
							<?php endif; ?>
						</div>
						<?php if ( ! empty( $remote_shipping_snapshot['shippings'] ) && is_array( $remote_shipping_snapshot['shippings'] ) ) : ?>
							<div class="mobo-note">روش‌های ارسال جدید از MoboCore دریافت شده‌اند. برای استفاده در checkout، در بخش «روش‌های ارسال برای ثبت سفارش در موبو» مشخص کنید کدام روش‌ها برای هر نوع سبد خرید مجاز باشند.</div>
							<div style="max-height:240px;overflow:auto;border:1px solid #e5e7eb;background:#fff;border-radius:10px;padding:10px;">
								<?php foreach ( array_slice( $remote_shipping_snapshot['shippings'], 0, 12 ) as $shipping_item ) : ?>
									<div style="padding:8px 0;border-bottom:1px solid #f3f4f6;">
										<strong><?php echo esc_html( isset( $shipping_item['title'] ) ? $shipping_item['title'] : 'روش ارسال موبو' ); ?></strong>
										<span style="color:#6b7280;"> — هزینه: <?php echo esc_html( isset( $shipping_item['cost'] ) ? number_format_i18n( (float) $shipping_item['cost'] ) : '0' ); ?></span>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>اعتبارسنجی قبل از خرید</h2>
						<p>این بخش قبل از پرداخت، آیتم‌های سبد خرید را بررسی می‌کند. پیش‌فرض غیرفعال است تا آپدیت مشتری‌های فعلی رفتار checkout را تغییر ندهد.</p>
					</div>

					<div class="mobo-fields-grid">
						<?php $this->bool_field( 'فعال بودن کنترل‌های قبل از پرداخت', 'mobo_core_checkout_validation_enabled' ); ?>
						<div data-mobo-ui-group="master-checkout">
							<?php $this->bool_field( 'بررسی موجودی ثبت شده در همین سایت', 'mobo_core_checkout_local_stock_check_enabled' ); ?>
						</div>
						<div class="mobo-field mobo-field-full" data-mobo-ui-message="master-off"><div class="mobo-help">کنترل‌های قبل از پرداخت خاموش است؛ بنابراین این افزونه در checkout بررسی اضافه‌ای انجام نمی‌دهد.</div></div>
					</div>

					<div class="mobo-note">
						این گزینه کل کنترل‌های خرید را روشن یا خاموش می‌کند. اگر فقط می‌خواهید سایت مثل ووکامرس عادی کار کند، آن را خاموش بگذارید. برای بررسی موجودی لحظه‌ای موبو باید گزینه «بررسی موجودی لحظه‌ای در موبو» را هم فعال کنید.
					</div>
				</div>

				<?php $this->render_mobo_shared_connection_box(); ?>

				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>بررسی سبد خرید موبو</h2>
						<p>اگر بررسی لحظه‌ای موجودی را فعال کنید، افزونه هنگام checkout سبد موبو را با سبد مشتری مقایسه می‌کند. این بخش فقط وقتی لازم است که می‌خواهید قبل از پرداخت، موجودی و قابل خرید بودن محصولات موبو دوباره بررسی شود.</p>
					</div>

					<div class="mobo-fields-grid">
						<div data-mobo-ui-group="master-checkout">
							<?php $this->bool_field( 'بررسی موجودی لحظه‌ای در موبو', 'mobo_core_checkout_mobo_cart_validation_enabled' ); ?>
							<div class="mobo-field mobo-field-full"><div class="mobo-help">اگر فعال باشد، قبل از ثبت سفارش، همین سبد خرید در موبو هم بررسی می‌شود تا مشخص شود کالاها در همان لحظه قابل خرید هستند یا نه.</div></div>
						</div>

						<div data-mobo-ui-group="mobo-cart-debug">
							<?php $this->bool_field( 'ثبت گزارش عیب‌یابی سبد موبو', 'mobo_core_checkout_mobo_debug_enabled' ); ?>
							<?php $this->int_field( 'حداکثر زمان انتظار برای بررسی سبد / ثانیه', 'mobo_core_checkout_mobo_cart_lock_wait_seconds', 0, 45 ); ?>
							<?php $this->int_field( 'مدت نگهداری نوبت بررسی سبد / ثانیه', 'mobo_core_checkout_mobo_cart_lock_ttl_seconds', 15, 300 ); ?>
						</div>

						<?php $this->bool_field( 'گزارش مشکل روش‌های ارسال ووکامرس', 'mobo_core_shipping_diagnostics_enabled' ); ?>
						<?php $this->bool_field( 'ثبت خودکار سفارش در موبو', 'mobo_core_mobo_order_submission_enabled' ); ?>


						<div data-mobo-ui-group="auto-order">
							<?php $this->bool_field( 'تکمیل خودکار سفارش در سایت بعد از ثبت موفق در موبو', 'mobo_core_mobo_order_auto_complete_enabled' ); ?>
							<?php $this->text_field( 'نام فروشگاه یا فرستنده', 'mobo_core_mobo_order_sender_name', 'نامی که در اطلاعات سفارش موبو به عنوان فرستنده ثبت می‌شود.' ); ?>
							<?php $this->text_field( 'شماره موبایل فروشگاه یا فرستنده', 'mobo_core_mobo_order_sender_mobile', 'شماره موبایلی که در اطلاعات سفارش موبو ثبت می‌شود.' ); ?>
							<div class="mobo-field mobo-field-full"><div class="mobo-help">روش ارسال قابل مشاهده برای مشتری فقط با WooCommerce است. روش ارسال موبو در بخش پایین، بر اساس استان نگاشت‌شده و ساعت وردپرس برای ثبت سفارش اتوماتیک انتخاب می‌شود.</div></div>
						</div>

						<div class="mobo-field mobo-field-full" data-mobo-ui-message="mobo-login-off"><div class="mobo-help">تا وقتی «بررسی موجودی لحظه‌ای در موبو» یا «ثبت خودکار سفارش در موبو» روشن نباشد، اطلاعات اتصال به موبو لازم نیست.</div></div>
						<div class="mobo-field mobo-field-full" data-mobo-ui-message="auto-order-off"><div class="mobo-help">ثبت خودکار سفارش در موبو خاموش است؛ تنظیمات فرستنده، تکمیل خودکار سفارش و روش‌های ارسال موبو فعلا لازم نیستند.</div></div>
					</div>


					<div class="mobo-note">
						اگر «بررسی موجودی لحظه‌ای در موبو» یا «ثبت خودکار سفارش در موبو» روشن باشد، پلاگین برای همین کار از اطلاعات ورود موبو استفاده می‌کند. ثبت سفارش در موبو فقط بعد از نهایی شدن سفارش ووکامرس انجام می‌شود.
					</div>
				</div>

			</div>

			<div class="mobo-card mobo-card-wide" data-mobo-ui-card="auto-order">
				<div class="mobo-card-head">
					<h2>نگاشت آدرس برای ثبت سفارش در موبو</h2>
					<p>برای ثبت سفارش خودکار، افزونه باید بداند کشور، استان و شهر انتخاب شده در checkout معادل کدام مورد در موبو است. پیشنهاد خودکار فقط کمک می‌کند؛ تصمیم نهایی با مدیر سایت است.</p>
				</div>

				<div class="mobo-fields-grid">
					<?php $this->int_field( 'بازه بروزرسانی از MoboCore / روز', 'mobo_core_address_mapping_sync_interval_days', 1, 30 ); ?>
				</div>


				<div class="mobo-status-grid">
					<?php $counts = isset( $address_status['counts'] ) && is_array( $address_status['counts'] ) ? $address_status['counts'] : array(); ?>
					<?php $this->status_box( 'نیاز عملیاتی', $order_submission_enabled ? 'الزامی - ثبت سفارش خودکار فعال است' : 'غیرفعال - ثبت سفارش خودکار خاموش است' ); ?>
					<?php $this->status_box( 'کشورها', isset( $counts['countries'] ) ? absint( $counts['countries'] ) : 0 ); ?>
					<?php $this->status_box( 'استان‌ها', isset( $counts['states'] ) ? absint( $counts['states'] ) : 0 ); ?>
					<?php $this->status_box( 'شهرها', isset( $counts['cities'] ) ? absint( $counts['cities'] ) : 0 ); ?>
					<?php $this->status_box( 'مالکیت فیلدهای checkout', 'WooCommerce / ووکامرس فارسی' ); ?>
					<?php $this->status_box( 'کشورهای نگاشت‌شده', ( isset( $address_manual_status['countriesMapped'] ) ? absint( $address_manual_status['countriesMapped'] ) : 0 ) . ' از ' . ( isset( $address_manual_status['countriesTotal'] ) ? absint( $address_manual_status['countriesTotal'] ) : 0 ) ); ?>
					<?php $this->status_box( 'استان‌های نگاشت‌شده', ( isset( $address_manual_status['statesMapped'] ) ? absint( $address_manual_status['statesMapped'] ) : 0 ) . ' از ' . ( isset( $address_manual_status['statesTotal'] ) ? absint( $address_manual_status['statesTotal'] ) : 0 ) ); ?>
					<?php $this->status_box( 'شهرهای نگاشت‌شده', ( isset( $address_manual_status['citiesMapped'] ) ? absint( $address_manual_status['citiesMapped'] ) : 0 ) . ' از ' . ( isset( $address_manual_status['citiesTotal'] ) ? absint( $address_manual_status['citiesTotal'] ) : 0 ) ); ?>
					<?php $this->status_box( 'آخرین بروزرسانی موفق', ! empty( $address_status['lastSuccessAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $address_status['lastSuccessAt'] ) ) : '—' ); ?>
					<?php $this->status_box( 'آخرین خطا', ! empty( $address_status['lastError'] ) ? $address_status['lastError'] : '—' ); ?>
				</div>

				<div class="mobo-note">
					اگر ثبت سفارش خودکار را فعال کنید، پلاگین ابتدا دیتای خام کشور/استان/شهر موبو را از MoboCore دریافت می‌کند. اما تبدیل مقدارهای checkout به city_id/state_id/country_id موبو فقط از نگاشت دستی تایید شده انجام می‌شود. دکمه auto-map فقط پیشنهاد می‌دهد و تا زمانی که مدیر ذخیره نکند اعمال نمی‌شود.
				</div>

				<?php if ( $address_mapping && method_exists( $address_mapping, 'get_cached_mapping' ) ) : ?>
					<?php $this->render_address_manual_mapping_ui( $address_mapping ); ?>
				<?php endif; ?>
			</div>

			<div class="mobo-card mobo-card-wide mobo-shipping-admin-card" data-mobo-ui-card="auto-order">
				<div class="mobo-card-head">
					<h2>روش‌های ارسال برای ثبت سفارش در موبو</h2>
					<p>نمایش و هزینه ارسال در checkout کاملا با WooCommerce است. این بخش فقط مشخص می‌کند هنگام ثبت خودکار سفارش در موبو، کدام shipping_id برای موبو ارسال شود.</p>
				</div>

				<div class="mobo-fields-grid">
					<?php $this->int_field( 'بازه بروزرسانی روش‌های ارسال از MoboCore / ساعت', 'mobo_core_remote_shipping_sync_interval_hours', 1, 168, 1 ); ?>
				</div>


				<div class="mobo-note">
					در این نسخه، انتخاب <code>shipping_id</code> موبو بر اساس همان روش ارسالی انجام می‌شود که کاربر در checkout ووکامرس انتخاب کرده است. ساعت سایت و استان موبو دیگر معیار انتخاب روش ارسال موبو نیستند؛ استان و شهر همچنان فقط برای آدرس سفارش موبو لازم هستند.
				</div>

				<div class="mobo-status-grid">
					<?php $this->status_box( 'نمایش روش ارسال در checkout', 'کاملا با WooCommerce' ); ?>
					<?php $this->status_box( 'نگاشت روش‌های ارسال ووکامرس', 'فعال برای انتخاب shipping_id موبو' ); ?>
					<?php $this->status_box( 'تعداد روش‌های ارسال cache شده', isset( $remote_shipping_status['count'] ) ? absint( $remote_shipping_status['count'] ) : 0 ); ?>
					<?php $this->status_box( 'آخرین بروزرسانی موفق', ! empty( $remote_shipping_status['lastSuccessAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $remote_shipping_status['lastSuccessAt'] ) ) : '—' ); ?>
					<?php $this->status_box( 'آخرین تغییر از MoboCore', ! empty( $remote_shipping_status['lastChangedAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $remote_shipping_status['lastChangedAt'] ) ) : '—' ); ?>
					<?php $this->status_box( 'آخرین خطا', ! empty( $remote_shipping_status['lastError'] ) ? $remote_shipping_status['lastError'] : '—' ); ?>
				</div>

				<div data-mobo-ui-group="auto-order">
					<?php $this->render_mobo_shipping_rules( $remote_shipping_methods ); ?>
				</div>
				<div class="mobo-note" data-mobo-ui-message="auto-order-off">ثبت خودکار سفارش در موبو خاموش است؛ این تنظیمات فقط زمانی استفاده می‌شود که سفارش موبو به صورت خودکار ساخته شود. روش ارسال قابل مشاهده در checkout همیشه با WooCommerce است.</div>
			</div>

			<?php
			$this->guide_box(
				'راهنمای اعتبارسنجی خرید و ثبت سفارش موبو',
				array(
					array( 'title' => 'کنترل‌های قبل از پرداخت', 'text' => 'این گزینه مشخص می‌کند افزونه قبل از پرداخت بررسی اضافه انجام بدهد یا checkout را مثل ووکامرس عادی رها کند.' ),
					array( 'title' => 'بررسی سبد موبو', 'text' => 'اگر فعال شود، افزونه فقط هنگام submit checkout سبد مشترک موبو را lock، پاکسازی، بازسازی و با سبد ووکامرس مقایسه می‌کند. پیش‌فرض این گزینه نیز غیرفعال است.' ),
					array( 'title' => 'گزارش عیب‌یابی سبد موبو', 'text' => 'فقط برای زمانی است که می‌خواهید دلیل خطای بررسی سبد موبو را ببینید. در حالت عادی خاموش بماند.' ),
					array( 'title' => 'ثبت سفارش خودکار', 'text' => 'اگر فعال باشد، بعد از ثبت سفارش ووکامرس، آیتم‌های موبو با آدرس نگاشت‌شده و روش ارسال موبو ثبت می‌شوند. روش ارسالی که مشتری در checkout می‌بیند همچنان مربوط به WooCommerce است.' ),
					array( 'title' => 'نوع سبد خرید', 'text' => 'برای سفارش فقط موبو و سفارش ترکیبی، روش ارسال موبو بر اساس Shipping Zone و روش ارسال انتخاب‌شده در WooCommerce انتخاب می‌شود. سفارش فقط غیرموبو وارد فرآیند ثبت سفارش موبو نمی‌شود.' ),
				),
				'قبل از استفاده روی سایت اصلی، اطلاعات اتصال موبو، نگاشت آدرس و روش‌های ارسال را با یک سفارش تست بررسی کنید.'
			);

			$this->guide_box(
				'راهنمای سازگاری افزونه‌های جانبی',
				array(
					array( 'title' => 'poina-domain-allowlist', 'text' => 'اگر این افزونه روی سایت نصب است و خروجی‌های HTTP را محدود می‌کند، دامنه‌های mobo.codeya.ir و mobomobo.ir باید به allowlist اضافه شوند. در غیر این صورت ارتباط با MoboCore، ورود به موبو، بررسی سبد موبو یا ثبت سفارش خودکار ممکن است fail شود.' ),
					array( 'title' => 'ووکامرس فارسی و شهرهای ایران', 'text' => 'اگر ووکامرس فارسی شهر و استان را کشویی می‌کند، مشکلی نیست؛ فقط نگاشت دستی همین صفحه باید کامل باشد تا مقدار انتخاب‌شده به شناسه شهر و استان موبو تبدیل شود.' ),
					array( 'title' => 'مالکیت فیلدهای آدرس', 'text' => 'وقتی ثبت سفارش خودکار موبو خاموش است، فیلدهای آدرس باید دست WooCommerce و افزونه‌های حمل و نقل بماند. وقتی ثبت سفارش خودکار روشن است، mapping موبو باید منبع اصلی country/state/city باشد تا payload سفارش موبو معتبر بماند.' ),
				),
				'اگر افزونه دیگری فیلدهای checkout را تغییر می‌دهد، بعد از ذخیره نگاشت آدرس یک سفارش تست با استان و شهر واقعی بگیرید.'
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
					array( 'setting' => 'بررسی محلی موجودی', 'value' => 'غیرفعال مگر برای تست کنترل‌شده', 'reason' => 'منبع عملیاتی موجودی باید مشخص باشد؛ همزمان کردن چند منبع می‌تواند خطای کاذب ایجاد کند.' ),
					array( 'setting' => 'بررسی سبد موبو', 'value' => 'غیرفعال به عنوان پیش‌فرض', 'reason' => 'سبد موبو مشترک است و فقط بعد از اطمینان از lock و portal_variant_id باید فعال شود.' ),
					array( 'setting' => 'لاگ حرفه‌ای سبد موبو', 'value' => 'غیرفعال به عنوان پیش‌فرض', 'reason' => 'برای debug روشن شود و بعد از رفع مشکل خاموش بماند تا حجم option/log بالا نرود.' ),
				),
				'پیشنهاد عملی: اول با ثبت سفارش خودکار و یک سفارش کاملاً موبویی تست کنید؛ سپس در صورت نیاز اعتبارسنجی checkout و بررسی سبد موبو را جداگانه فعال کنید.'
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
						<?php $this->status_box( 'زمان ثبت', ! empty( $shipping_report['capturedat'] ) ? wp_date( 'Y-m-d H:i:s', absint( $shipping_report['capturedat'] ) ) : '—' ); ?>
						<?php $customer_report = isset( $shipping_report['customer'] ) && is_array( $shipping_report['customer'] ) ? $shipping_report['customer'] : array(); ?>
						<?php $this->status_box( 'Shipping Country', isset( $customer_report['shipping_country'] ) ? $customer_report['shipping_country'] : '—' ); ?>
						<?php $this->status_box( 'Shipping State', isset( $customer_report['shipping_state'] ) ? $customer_report['shipping_state'] : '—' ); ?>
						<?php $this->status_box( 'Shipping City', isset( $customer_report['shipping_city'] ) ? $customer_report['shipping_city'] : '—' ); ?>
						<?php $cart_report = isset( $shipping_report['cart'] ) && is_array( $shipping_report['cart'] ) ? $shipping_report['cart'] : array(); ?>
						<?php $this->status_box( 'Cart needs shipping', isset( $cart_report['needsshipping'] ) ? ( $cart_report['needsshipping'] ? 'yes' : 'no' ) : '—' ); ?>
					</div>

					<details style="margin-top:12px;">
						<summary style="cursor:pointer;">نمایش جزئیات فنی گزارش ارسال</summary>
						<pre style="direction:ltr;text-align:left;white-space:pre-wrap;max-height:420px;overflow:auto;background:#111827;color:#e5e7eb;padding:14px;border-radius:10px;"><?php echo esc_html( wp_json_encode( $shipping_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
					</details>
				<?php endif; ?>
			</div>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>آخرین وضعیت اعتبارسنجی</h2>
					<p>برای debug سریع checkout استفاده می‌شود.</p>
				</div>

				<div class="mobo-status-grid">
					<?php $this->status_box( 'کنترل‌های قبل از پرداخت', ! empty( $status['enabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
					<?php $this->status_box( 'اجرای checkout توسط موبو', ! empty( $status['runtimeEnabled'] ) ? 'فعال' : 'غیرفعال - کنترل‌های قبل از پرداخت یا ثبت سفارش خودکار فعال نیستند' ); ?>
					<?php $this->status_box( 'بررسی محلی موجودی', ! empty( $status['localStockEnabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
					<?php $this->status_box( 'بررسی سبد موبو', ! empty( $status['moboCartEnabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
					<?php $this->status_box( 'آخرین تلاش', ! empty( $status['lastAttemptAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['lastAttemptAt'] ) ) : '—' ); ?>
					<?php $this->status_box( 'آخرین موفقیت', ! empty( $status['lastSuccessAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['lastSuccessAt'] ) ) : '—' ); ?>
					<?php $this->status_box( 'آخرین ورود موفق موبو', ! empty( $status['lastMoboLoginAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['lastMoboLoginAt'] ) ) : '—' ); ?>
					<?php $this->status_box( 'آخرین تست ورود', get_option( 'mobo_core_checkout_mobo_login_test_at' ) ? wp_date( 'Y-m-d H:i:s', absint( get_option( 'mobo_core_checkout_mobo_login_test_at' ) ) ) : '—' ); ?>
					<?php $this->status_box( 'نتیجه تست ورود', get_option( 'mobo_core_checkout_mobo_login_test_result', '—' ) ); ?>
					<?php $this->status_box( 'آخرین Cart موفق موبو', ! empty( $status['lastMoboCartAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['lastMoboCartAt'] ) ) : '—' ); ?>
					<?php $lock_disabled = empty( $status['moboCartEnabled'] ) && empty( $status['autoOrderEnabled'] ); ?>
					<?php $this->status_box( 'وضعیت lock سبد موبو', $lock_disabled ? 'غیرفعال - بررسی سبد و ثبت سفارش خاموش است' : ( get_option( 'mobo_core_shared_mobo_cart_lock' ) ? 'فعال / در حال استفاده' : 'آزاد' ) ); ?>
					<?php $this->status_box( 'ثبت سفارش خودکار موبو', Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' ) ? 'فعال' : 'غیرفعال' ); ?>
					<?php $this->status_box( 'آخرین نتیجه', isset( $last['success'] ) ? ( ! empty( $last['success'] ) ? 'موفق' : 'ناموفق' ) : '—' ); ?>
					<?php $this->status_box( 'HTTP Status', isset( $last['status'] ) ? absint( $last['status'] ) : '—' ); ?>
				</div>
			</div>

			<?php $this->render_mobo_cart_debug_log( $debug_log ); ?>

			<script>
			(function() {
				var form = document.querySelector('.mobo-settings-form');
				if (!form) { return; }

				function valueOf(id) {
					var el = form.querySelector('#' + id);
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
					var moboCart = master && valueOf('mobo_core_checkout_mobo_cart_validation_enabled') === '1';
					var autoOrder = valueOf('mobo_core_mobo_order_submission_enabled') === '1';
					var needsMoboLogin = moboCart || autoOrder;

					setVisible('[data-mobo-ui-group="master-checkout"]', master);
					setVisible('[data-mobo-ui-message="master-off"]', !master);
					setVisible('[data-mobo-ui-group="mobo-cart-debug"]', moboCart);
					setVisible('[data-mobo-ui-card="mobo-connection"]', needsMoboLogin);
					setVisible('[data-mobo-ui-group="mobo-login"]', needsMoboLogin);
					setVisible('[data-mobo-ui-message="mobo-login-off"]', !needsMoboLogin);
					setVisible('[data-mobo-ui-group="auto-order"]', autoOrder);
					setVisible('[data-mobo-ui-card="auto-order"]', autoOrder);
					setVisible('[data-mobo-ui-message="auto-order-off"]', !autoOrder);
					updateMoboConnectionRequired(needsMoboLogin);
				}

				['mobo_core_checkout_validation_enabled', 'mobo_core_checkout_mobo_cart_validation_enabled', 'mobo_core_mobo_order_submission_enabled'].forEach(function(id) {
					var el = form.querySelector('#' + id);
					if (el) { el.addEventListener('change', refreshMoboCheckoutUi); }
				});

				if (window.jQuery) {
					window.jQuery(form).on('change', '#mobo_core_checkout_validation_enabled, #mobo_core_checkout_mobo_cart_validation_enabled, #mobo_core_mobo_order_submission_enabled', refreshMoboCheckoutUi);
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
				<?php $this->status_box( 'آخرین رخداد', $last_time ? wp_date( 'Y-m-d H:i:s', $last_time ) : '—' ); ?>
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
							— آخرین: <?php echo esc_html( $session['last'] ? wp_date( 'H:i:s', $session['last'] ) : '—' ); ?>
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
										<th>Action</th>
										<th>Variant</th>
										<th>Qty</th>
										<th>Cart item</th>
										<th>HTTP</th>
										<th>Method</th>
										<th>Path</th>
										<th>Cart cookie</th>
										<th>Userauth</th>
										<th>Map</th>
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
											<td dir="ltr"><?php echo esc_html( ! empty( $entry['time'] ) ? wp_date( 'H:i:s', absint( $entry['time'] ) ) : '—' ); ?></td>
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
		<span class="mobo-admin-tool-config" data-mobo-admin-tool-action-url="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-mobo-admin-tool-nonce="<?php echo esc_attr( wp_create_nonce( 'mobo_core_admin_tool' ) ); ?>" style="display:none;"></span>
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
						<?php $this->admin_tool_button( 'mobo_core_tool_sync_address_mapping', 'بروزرسانی کشور، استان و شهر از MoboCore' ); ?>
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
				<summary>ابزارهای تست Cron</summary>
				<div class="mobo-support-tools-body">
					<div class="mobo-help">این دکمه تنظیمات کران را ذخیره نمی‌کند. اگر تنظیمات را تغییر داده‌اید، ابتدا ذخیره کنید.</div>
					<div class="mobo-support-tools-actions">
						<?php $this->admin_tool_button( 'mobo_core_tool_run_cron_now', 'اجرای تست Cron و پردازش وب‌هوک‌ها' ); ?>
					</div>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Render SMS notifications tab.
	 *
	 * @return void
	 */
	private function render_sms_tab() {
		$sms_available = function_exists( 'PWSMS' ) && is_object( PWSMS() ) && method_exists( PWSMS(), 'send_sms' );
		$gateway_label = '—';

		if ( $sms_available && method_exists( PWSMS(), 'get_sms_gateway' ) ) {
			try {
				$gateway = PWSMS()->get_sms_gateway();
				if ( is_object( $gateway ) && method_exists( $gateway, 'name' ) ) {
					$gateway_label = $gateway::name();
				}
			} catch ( Throwable $e ) {
				$gateway_label = 'خطا در خواندن درگاه فعال';
			}
		}

		$scenarios = class_exists( 'Mobo_Core_SMS_Notifications' ) ? ( new Mobo_Core_SMS_Notifications() )->get_scenarios() : array(
			'non_mobo'  => 'سفارش غیر موبو',
			'mobo_only' => 'سفارش فقط محصولات موبو',
			'mixed'     => 'سفارش ترکیبی موبو و غیرموبو',
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="sms">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-grid">
				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>ارسال پیامک بر اساس نوع سفارش</h2>
						<p>ارسال واقعی پیامک از طریق افزونه «پیامک حرفه ای ووکامرس» انجام می شود؛ بنابراین هر درگاهی که آن افزونه پشتیبانی کند، این بخش هم پشتیبانی می کند.</p>
						<p>پیش نیاز این بخش: نصب، فعال سازی و تنظیم درگاه در افزونه <a href="https://wordpress.org/plugins/persian-woocommerce-sms/" target="_blank" rel="noopener noreferrer">پیامک حرفه ای ووکامرس</a>.</p>
					</div>

					<?php if ( $sms_available ) : ?>
						<div class="mobo-message mobo-message-success">افزونه پیامک شناسایی شد. درگاه فعال فعلی: <?php echo esc_html( $gateway_label ); ?></div>
					<?php else : ?>
						<div class="mobo-message mobo-message-warning">افزونه «پیامک حرفه ای ووکامرس» فعال نیست یا تابع PWSMS در دسترس نیست. تنظیمات ذخیره می شود، اما تا زمان فعال شدن آن افزونه پیامکی ارسال نمی شود.</div>
					<?php endif; ?>

					<div class="mobo-field mobo-toggle-field">
						<label for="mobo_core_sms_notifications_enabled">فعال سازی پیامک های موبو</label>
						<select id="mobo_core_sms_notifications_enabled" name="mobo_core_sms_notifications_enabled" class="mobo-category-select2" data-placeholder="وضعیت">
							<option value="1" <?php selected( (string) Mobo_Core_Settings::get( 'mobo_core_sms_notifications_enabled', '0' ), '1' ); ?>>فعال</option>
							<option value="0" <?php selected( (string) Mobo_Core_Settings::get( 'mobo_core_sms_notifications_enabled', '0' ), '0' ); ?>>غیرفعال</option>
						</select>
						<div class="mobo-help">ارسال فقط یک بار برای هر سفارش و هر نوع سفارش انجام می شود و نتیجه داخل Order Notes و آرشیو پیامک افزونه پیامک ثبت می شود.</div>
					</div>
				</div>

				<?php foreach ( $scenarios as $scenario => $label ) : ?>
					<?php $this->render_sms_scenario_box( $scenario, $label ); ?>
				<?php endforeach; ?>

				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>راهنمای Template</h2>
						<p>هم متن ساده و هم الگوی pattern افزونه پیامک فارسی ووکامرس قابل استفاده است.</p>
					</div>

					<div class="mobo-message mobo-message-info">
						برای ارسال الگو، متن را با ساختار همان افزونه وارد کن. مثال: <code dir="ltr">pattern:12345</code> و در خط های بعدی نام متغیرها و مقدارها را بگذار. شورت کدهای سفارش مثل <code dir="ltr">{order_id}</code>، <code dir="ltr">{price}</code>، <code dir="ltr">{all_items}</code>، <code dir="ltr">{phone}</code> و <code dir="ltr">{shipping_method}</code> توسط افزونه پیامک جایگزین می شوند.
					</div>

					<textarea rows="7" readonly dir="ltr" onclick="this.select();">pattern:12345
order_id:{order_id}
price:{price}
type:{mobo_order_type_label}</textarea>

					<div class="mobo-help">شورت کدهای اختصاصی موبو: <code dir="ltr">{mobo_order_type}</code>، <code dir="ltr">{mobo_order_type_label}</code>، <code dir="ltr">{mobo_items_count}</code>، <code dir="ltr">{non_mobo_items_count}</code>.</div>
					<div class="mobo-help">در فیلد شماره گیرنده می توانی شماره ثابت وارد کنی یا از <code dir="ltr">{customer_mobile}</code> برای شماره مشتری استفاده کنی. چند شماره را با کاما یا خط جدید جدا کن.</div>
				</div>
			</div>

			<?php submit_button( 'ذخیره تنظیمات پیامک', 'primary' ); ?>
		</form>
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
		$enabled = (string) Mobo_Core_Settings::get( $enabled_key, '0' );
		$recipients = (string) Mobo_Core_Settings::get( $recipients_key, '' );
		$template = (string) Mobo_Core_Settings::get( $template_key, '' );
		?>
		<div class="mobo-card mobo-card-wide">
			<div class="mobo-card-head">
				<h2><?php echo esc_html( $label ); ?></h2>
				<p>برای این نوع سفارش مشخص کن پیامک ارسال شود یا نه، به کدام شماره ها، و با چه متن/الگویی.</p>
			</div>

			<div class="mobo-field mobo-toggle-field">
				<label for="<?php echo esc_attr( $enabled_key ); ?>">ارسال پیامک برای این نوع سفارش</label>
				<select id="<?php echo esc_attr( $enabled_key ); ?>" name="<?php echo esc_attr( $enabled_key ); ?>" class="mobo-category-select2" data-placeholder="وضعیت">
					<option value="1" <?php selected( $enabled, '1' ); ?>>بله، ارسال شود</option>
					<option value="0" <?php selected( $enabled, '0' ); ?>>خیر، ارسال نشود</option>
				</select>
			</div>

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
						<p>این گزارش از داخل وردپرس تولید می‌شود و به MoboCore ارسال می‌شود تا وضعیت cron، memory، debug، disk و صف sync مشخص باشد.</p>
					</div>

					<div class="mobo-status-grid">
						<?php $this->status_box( 'ارسال گزارش سلامت', ! empty( $status['enabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
						<?php $this->status_box( 'آخرین تلاش', ! empty( $status['lastAttemptAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['lastAttemptAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'آخرین ارسال موفق', ! empty( $status['lastSuccessAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['lastSuccessAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'نسخه پلاگین', defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '—' ); ?>
					</div>

					<div class="mobo-field mobo-field-full">
						<label>آدرس بررسی داخلی پلاگین</label>
						<input type="text" readonly dir="ltr" value="<?php echo esc_attr( $health_url ); ?>" onclick="this.select();">
						<div class="mobo-help">MoboCore این endpoint را با header امنیتی <code>X-SEC</code> چک می‌کند.</div>
					</div>

					<div class="mobo-field mobo-field-full">
						<label>آدرس ارسال دستی گزارش</label>
						<input type="text" readonly dir="ltr" value="<?php echo esc_attr( $manual_url ); ?>" onclick="this.select();">
					</div>
				</div>

				<div class="mobo-card">
					<div class="mobo-card-head">
						<h2>تنظیمات ارسال</h2>
						<p>اگر URL خالی باشد، پلاگین از API Base URL مقدار <code>/api/site-health/report</code> را می‌سازد.</p>
					</div>

					<div class="mobo-fields-grid">
						<?php $this->bool_field( 'ارسال گزارش سلامت به MoboCore', 'mobo_core_health_report_enabled' ); ?>
						<?php $this->int_field( 'حداقل فاصله ارسال / ثانیه', 'mobo_core_health_report_min_interval_seconds', 60, 3600 ); ?>
						<?php $this->int_field( 'Timeout ارسال / ثانیه', 'mobo_core_health_report_timeout_seconds', 5, 60 ); ?>
					</div>

					<?php $this->url_field( 'آدرس گزارش سلامت', 'mobo_core_health_report_url', 'اختیاری. مثال: https://portal.example.com/api/site-health/report' ); ?>
				</div>

				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>وضعیت محلی فعلی</h2>
						<p>این مقادیر همان چیزی است که به MoboCore گزارش می‌شود.</p>
					</div>

					<div class="mobo-status-grid">
						<?php $this->status_box( 'دیباگ وردپرس', ! empty( $local['wpDebug'] ) ? 'فعال' : 'غیرفعال' ); ?>
						<?php $this->status_box( 'دیباگ وردپرس DISPLAY', ! empty( $local['wpDebugDisplay'] ) ? 'فعال' : 'غیرفعال' ); ?>
						<?php $this->status_box( 'محدودیت حافظه PHP', isset( $local['phpMemoryLimit'] ) ? $local['phpMemoryLimit'] : '—' ); ?>
						<?php $this->status_box( 'نسخه PHP', isset( $local['phpVersion'] ) ? $local['phpVersion'] : '—' ); ?>
						<?php $this->status_box( 'فضای خالی دیسک', isset( $local['diskFreePercent'] ) && null !== $local['diskFreePercent'] ? $local['diskFreePercent'] . '%' : '—' ); ?>
						<?php $this->status_box( 'وب‌هوک‌های در صف', isset( $local['pendingWebhookJobs'] ) ? absint( $local['pendingWebhookJobs'] ) : 0 ); ?>
						<?php $this->status_box( 'وب‌هوک‌های ناموفق', isset( $local['failedWebhookJobs'] ) ? absint( $local['failedWebhookJobs'] ) : 0 ); ?>
						<div class="mobo-status-box">
							<div class="mobo-status-label">Retry وب‌هوک‌های ناموفق</div>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
								<input type="hidden" name="action" value="mobo_core_retry_failed_webhooks">
								<?php wp_nonce_field( 'mobo_core_retry_failed_webhooks', 'mobo_core_nonce' ); ?>
								<button type="submit" class="button button-secondary">برگرداندن failed ها به صف</button>
							</form>
						</div>
						<?php $this->status_box( 'حالت اجرای کران', isset( $local['cronMode'] ) ? $local['cronMode'] : '—' ); ?>
					</div>

					<?php if ( ! empty( $status['lastResult'] ) && is_array( $status['lastResult'] ) ) : ?>
						<div class="mobo-note" dir="ltr">
							<pre><?php echo esc_html( wp_json_encode( $status['lastResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<?php
			$this->guide_box(
				'راهنمای سلامت سایت',
				array(
					array( 'title' => 'امتیازدهی MoboCore', 'text' => 'MoboCore از همین گزارش برای بررسی memory، debug، disk، cron و صف‌های pending/failed استفاده می‌کند.' ),
					array( 'title' => 'گزارش دستی', 'text' => 'Endpoint ارسال دستی برای تست سریع است. در حالت عادی، گزارش با فاصله زمانی تنظیم‌شده ارسال می‌شود.' ),
					array( 'title' => 'چند دامنه روی یک لایسنس', 'text' => 'اطلاعات licenseToken و دامنه در گزارش ارسال می‌شود تا استفاده غیرعادی از یک لایسنس در MoboCore قابل تشخیص باشد.' ),
				),
				'اگر failed webhook زیاد شد، ابتدا علت خطا را بررسی کنید؛ دکمه Retry فقط آن‌ها را به صف برمی‌گرداند و خطای اصلی را رفع نمی‌کند.'
			);
			?>

			<?php
			$this->recommendation_box(
				'تنظیمات پیشنهادی سلامت سایت',
				array(
					array( 'setting' => 'ارسال گزارش سلامت', 'value' => 'روشن', 'reason' => 'برای تشخیص cron، صف‌ها، خطاها و مصرف منابع در MoboCore لازم است.' ),
					array( 'setting' => 'حداقل فاصله ارسال', 'value' => '۹۰۰ ثانیه در حالت عادی؛ ۳۰۰ ثانیه فقط برای راه‌اندازی اولیه یا عیب‌یابی', 'reason' => 'فاصله کوتاه‌تر تعداد گزارش‌های بیشتری تولید می‌کند و می‌تواند روی منابع هاست و MoboCore فشار اضافه ایجاد کند.' ),
					array( 'setting' => 'Timeout ارسال', 'value' => '۱۰ تا ۱۵ ثانیه', 'reason' => 'کمتر از این ممکن است روی هاست‌های کند خطای کاذب بدهد؛ بیشتر از این worker را بی‌دلیل نگه می‌دارد.' ),
					array( 'setting' => 'آدرس گزارش سلامت', 'value' => 'خالی مگر MoboCore جداگانه باشد', 'reason' => 'اگر خالی باشد از API Base URL ساخته می‌شود و خطای تنظیم دستی کمتر است.' ),
				),
				'Health را روشن نگه دارید، اما فاصله ارسال را کمتر از ۵ دقیقه نگذارید مگر در زمان راه‌اندازی اولیه یا عیب‌یابی.'
			);
			?>

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
		$status         = Mobo_Core_Cron_Runner::get_status();
		$cron_url       = isset( $status['cronUrl'] ) ? (string) $status['cronUrl'] : '';
		$script_path    = MOBO_CORE_PLUGIN_DIR . 'mobo-cron.php';
		$php_command    = '/usr/local/bin/php -q ' . $script_path;
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
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="cron">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>


			<div class="mobo-grid">
				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>اجرای خودکار / کران واقعی</h2>
						<p>مسیر اصلی و مطمئن برای اجرای صف‌ها، Cron واقعی با فایل PHP داخل پلاگین است. برای جلوگیری از pending ماندن webhookها، فقط یک Cron Job برای همین فایل تنظیم کنید.</p>
					</div>

					<div class="mobo-status-grid">
						<?php $this->status_box( 'کران واقعی', ! empty( $status['isActive'] ) ? 'فعال' : 'تشخیص داده نشده' ); ?>
						<?php $this->status_box( 'آخرین اجرای کران واقعی', ! empty( $status['lastHitAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['lastHitAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'آخرین اجرای موفق کران', ! empty( $status['lastSuccessAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['lastSuccessAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'اجرای بعدی Cron، تقریبی', ! empty( $status['lastHitAt'] ) && ! empty( $status['nextEstimatedAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['nextEstimatedAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'فاصله مورد انتظار Cron', ! empty( $status['expectedIntervalSeconds'] ) ? absint( $status['expectedIntervalSeconds'] ) . ' ثانیه' : '—' ); ?>
						<?php $this->status_box( 'وضعیت زمان‌بندی Cron', empty( $status['lastHitAt'] ) ? '—' : ( ! empty( $status['isOverdue'] ) ? 'عقب‌افتاده' : 'به‌موقع' ) ); ?>
						<?php $this->status_box( 'آخرین اجرای Worker داخلی', ! empty( $self_status['lastRunAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $self_status['lastRunAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'آخرین Kick داخلی', ! empty( $self_status['lastKickAttemptAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $self_status['lastKickAttemptAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'اجرای داخلی', ! empty( $self_status['enabled'] ) ? 'فعال' : 'غیرفعال' ); ?>
						<?php $this->status_box( 'آخرین تلاش پردازش وب‌هوک', ! empty( $webhook_status['lastAttemptAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $webhook_status['lastAttemptAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'آخرین اجرای موفق صف وب‌هوک', ! empty( $webhook_status['lastSuccessAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $webhook_status['lastSuccessAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'آخرین فعالیت واقعی وب‌هوک', ! empty( $webhook_status['lastActivityAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $webhook_status['lastActivityAt'] ) ) : '—' ); ?>
						<?php $this->status_box( 'اجرای بعدی وب‌هوک، تقریبی', $next_webhook_at > 0 ? wp_date( 'Y-m-d H:i:s', $next_webhook_at ) : '—' ); ?>
						<?php $this->status_box( 'وب‌هوک‌های pending / due / failed', absint( isset( $webhook_status['pendingTableEvents'] ) ? $webhook_status['pendingTableEvents'] : 0 ) . ' / ' . absint( isset( $webhook_status['dueTableEvents'] ) ? $webhook_status['dueTableEvents'] : 0 ) . ' / ' . absint( isset( $webhook_status['failedTableEvents'] ) ? $webhook_status['failedTableEvents'] : 0 ) ); ?>
						<?php $this->status_box( 'فایل‌های pending قدیمی', absint( isset( $webhook_status['pendingFiles'] ) ? $webhook_status['pendingFiles'] : 0 ) ); ?>
					</div>
					<div class="mobo-note">
						اگر Cron را با فایل <code dir="ltr">mobo-cron.php</code> تنظیم کرده‌اید، معیار اصلی «آخرین اجرای کران واقعی» است. «اجرای بعدی» فقط تخمین پلاگین بر اساس آخرین اجرا و فاصله مورد انتظار است؛ زمان دقیق بعدی در خود cPanel نگهداری می‌شود. اگر «آخرین اجرای کران واقعی» تغییر نمی‌کند، cron اصلاً فایل را اجرا نکرده یا قبل از لود وردپرس خطا می‌دهد.
					</div>

					<?php $this->render_cron_support_tools_box(); ?>

					<?php if ( ! empty( $status['lastResult'] ) && is_array( $status['lastResult'] ) ) : ?>
						<div class="mobo-note">
							<strong>آخرین نتیجه Cron:</strong>
							<pre dir="ltr"><?php echo esc_html( wp_json_encode( $status['lastResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $webhook_status['lastResult'] ) && is_array( $webhook_status['lastResult'] ) ) : ?>
						<div class="mobo-note">
							<strong>آخرین نتیجه صف وب‌هوک:</strong>
							<pre dir="ltr"><?php echo esc_html( wp_json_encode( $webhook_status['lastResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
						</div>
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
						<label>دستور کامل Cron پیشنهادی</label>
						<textarea rows="3" readonly dir="ltr" onclick="this.select();"><?php echo esc_textarea( $php_command ); ?></textarea>
						<div class="mobo-help">اگر هاست Command کامل می‌پذیرد، همین دستور را هر ۱ دقیقه اجرا کنید. این دستور مستقیم فایل mobo-cron.php را اجرا می‌کند و به HTTP/loopback وابسته نیست.</div>
					</div>

					<?php if ( '' !== $cron_url ) : ?>
						<div class="mobo-field mobo-field-full">
							<label>Endpoint HTTP جایگزین، فقط در صورت نیاز</label>
							<input type="text" readonly dir="ltr" value="<?php echo esc_attr( $cron_url ); ?>" onclick="this.select();">
							<div class="mobo-help">این URL فقط fallback است. مسیر اصلی Cron واقعی، اجرای فایل PHP بالا با PHP CLI است.</div>
						</div>
					<?php endif; ?>
				</div>

				<div class="mobo-card mobo-card-wide">
					<div class="mobo-card-head">
						<h2>راهنمای تنظیم Cron در هاست‌ها</h2>
						<p>برای این پلاگین معمولاً فقط یک Cron Job کافی است. پیشنهاد اصلی، اجرای فایل <code dir="ltr">mobo-cron.php</code> هر ۱ دقیقه است تا webhookهای pending سریع‌تر پردازش شوند.</p>
					</div>
					<div class="mobo-note">
						<strong>cPanel با محدودیت «Only PHP scripts»:</strong> در بخش Cron Jobs حالت PHP Script را انتخاب کنید، زمان‌بندی را روی هر ۱ دقیقه قرار دهید و فقط مسیر فایل <code dir="ltr"><?php echo esc_html( $script_path ); ?></code> را وارد کنید. از <code>wget</code>، <code>curl</code>، <code>&gt;/dev/null</code> و <code>2&gt;&amp;1</code> استفاده نکنید.
					</div>
					<div class="mobo-note">
						<strong>cPanel با Command کامل:</strong> دستور <code dir="ltr"><?php echo esc_html( $php_command ); ?></code> را با زمان‌بندی هر ۱ دقیقه ثبت کنید. اگر مسیر PHP فرق داشت، فقط مسیر PHP را اصلاح کنید.
					</div>
					<div class="mobo-note">
						<strong>DirectAdmin:</strong> از Advanced Features &gt; Cron Jobs یک job با دقیقه <code dir="ltr">*</code> یا حالت هر ۱ دقیقه بسازید. اگر Command کامل مجاز است دستور PHP بالا را وارد کنید؛ اگر فقط فایل PHP می‌خواهد، مسیر فایل را وارد کنید.
					</div>
					<div class="mobo-note">
						<strong>Plesk یا هاست‌های عمومی:</strong> در Scheduled Tasks، نوع Task را PHP Script قرار دهید، زمان‌بندی را هر ۱ دقیقه بگذارید و فایل <code dir="ltr">mobo-cron.php</code> را از مسیر پلاگین انتخاب کنید. URL task فقط fallback است.
					</div>
				</div>

				<div class="mobo-card mobo-queue-preset-card" data-mobo-cron-preset-card>
					<div class="mobo-card-head">
						<h2>تنظیم سریع کران واقعی بر اساس توان هاست</h2>
						<p>این دکمه‌ها فقط فیلدهای Runner و Cron همین صفحه را داخل فرم تغییر می‌دهند. برای ثبت نهایی، دکمه ذخیره پایین صفحه را بزنید.</p>
					</div>

					<div class="mobo-queue-preset-actions" aria-label="تنظیم سریع کران واقعی">
						<button type="button" class="button button-secondary" data-mobo-cron-preset="vps">VPS</button>
						<button type="button" class="button button-secondary" data-mobo-cron-preset="strong">هاست قوی</button>
						<button type="button" class="button button-secondary" data-mobo-cron-preset="medium">هاست متوسط</button>
						<button type="button" class="button button-secondary" data-mobo-cron-preset="weak">هاست ضعیف</button>
					</div>

					<div class="mobo-help" data-mobo-cron-preset-message>
						اگر Cron واقعی هر ۱ دقیقه تنظیم شده، این presetها مقدارهای Runner را متناسب با توان هاست تنظیم می‌کنند.
					</div>
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
						<?php $this->int_field( 'فاصله مورد انتظار Cron / ثانیه', 'mobo_core_real_cron_expected_interval_seconds', 60, 3600 ); ?>
						<?php $this->bool_field( 'فعال بودن اجرای داخلی', 'mobo_core_self_runner_enabled' ); ?>
						<?php $this->bool_field( 'ادامه خودکار تا خالی شدن صف', 'mobo_core_self_runner_continue_enabled' ); ?>
						<?php $this->int_field( 'حداقل فاصله Kick / ثانیه', 'mobo_core_self_runner_min_interval_seconds', 0, 60 ); ?>
						<?php $this->int_field( 'Timeout درخواست داخلی / ثانیه', 'mobo_core_self_runner_http_timeout_seconds', 1, 10 ); ?>
						<?php $this->bool_field( 'پردازش صف وب‌هوک در Runner', 'mobo_core_real_cron_process_webhooks' ); ?>
						<?php $this->bool_field( 'پردازش فوری وب‌هوک هنگام دریافت', 'mobo_core_process_webhook_on_receive' ); ?>
						<?php $this->bool_field( 'دریافت payload اصلی از MoboCore', 'mobo_core_pull_payload_enabled' ); ?>
						<?php $this->int_field( 'Timeout دریافت payload / ثانیه', 'mobo_core_payload_pull_timeout_seconds', 5, 180 ); ?>
						<?php $this->int_field( 'Timeout درخواست‌های sync API / ثانیه', 'mobo_core_api_request_timeout_seconds', 5, 180 ); ?>
						<?php $this->int_field( 'حداکثر تلاش مجدد خطاهای موقت sync', 'mobo_core_transient_retry_max_try', 1, 50 ); ?>
						<?php $this->int_field( 'فاصله تلاش مجدد پس از انتظار MoboCore / ثانیه', 'mobo_core_waiting_for_portal_retry_delay_seconds', 10, 3600 ); ?>
					</div>

					<?php $this->secret_field( 'توکن کران', 'mobo_core_cron_token', 'خالی بگذارید تا مقدار قبلی حفظ شود. این توکن فقط برای Endpoint HTTP لازم است؛ اجرای مستقیم فایل mobo-cron.php با PHP CLI به توکن نیاز ندارد.' ); ?>

					<?php
					$this->guide_box(
						'راهنمای Runner و Cron',
						array(
							array( 'title' => 'اجرای داخلی', 'text' => 'بعد از webhook یا polling، پلاگین تلاش می‌کند worker داخلی را بیدار کند؛ اما روی بعضی هاست‌ها loopback قابل اعتماد نیست، بنابراین Cron واقعی باید فعال باشد.' ),
							array( 'title' => 'Cron واقعی', 'text' => 'مسیر اصلی پیشنهادی، اجرای مستقیم فایل mobo-cron.php با PHP CLI است. یک Cron Job هر ۱ دقیقه کافی است.' ),
							array( 'title' => 'بودجه زمانی', 'text' => 'زمان و تعداد step را کوچک نگه دارید تا هاست مشتری timeout یا فشار CPU نگیرد.' ),
						),
						'روی هاست اشتراکی معمولاً تعداد step محصول ۱ تا ۳ و تعداد تصویر ۳ مقدار امن‌تری است. روی VPS می‌توان تدریجی افزایش داد.'
					);
					$this->recommendation_box(
						'تنظیمات پیشنهادی Runner و Cron',
						array(
							array( 'setting' => 'بودجه زمانی هر اجرا', 'value' => '۲۰ تا ۲۵ ثانیه روی هاست معمولی؛ ۳۰ تا ۴۵ ثانیه روی VPS', 'reason' => 'کمتر از این sync کند می‌شود؛ بیشتر از این احتمال timeout و مصرف CPU را بالا می‌برد.' ),
							array( 'setting' => 'حداکثر step محصول', 'value' => '۲ یا ۳ روی هاست اشتراکی؛ ۵ روی VPS', 'reason' => 'هر step ممکن است product، variant و image queue را درگیر کند.' ),
							array( 'setting' => 'TTL قفل Cron', 'value' => '۱۲۰ ثانیه', 'reason' => 'باید بزرگ‌تر از بودجه زمانی باشد تا اجرای همزمان ساخته نشود، ولی آنقدر زیاد نباشد که lock مرده طولانی بماند.' ),
							array( 'setting' => 'Cron واقعی', 'value' => 'هر ۱ دقیقه، فقط یک job', 'reason' => 'اگر worker داخلی تکان نخورد، Cron واقعی صف webhook، محصول، واریانت و تصویر را جلو می‌برد.' ),
							array( 'setting' => 'اجرای داخلی و ادامه خودکار', 'value' => 'روشن', 'reason' => 'بعد از webhook تلاش می‌کند صف را سریع‌تر جلو ببرد، اما جایگزین Cron واقعی نیست.' ),
							array( 'setting' => 'پردازش فوری وب‌هوک هنگام دریافت', 'value' => 'خاموش', 'reason' => 'درخواست ورودی MoboCore نباید منتظر پردازش سنگین وردپرس بماند.' ),
							array( 'setting' => 'Timeout API و payload', 'value' => '۶۰ ثانیه', 'reason' => 'برای payloadهای بزرگ و هاست کند معقول است؛ مقادیر خیلی بالا worker را قفل می‌کند.' ),
						),
						'برای جلوگیری از pending ماندن webhookها، فقط یک Cron واقعی با فایل PHP پلاگین و زمان‌بندی هر ۱ دقیقه تنظیم کنید. چند Cron موازی برای worker، cron URL و wp-cron لازم نیست. اگر هاست محدودیت سخت‌گیرانه دارد، بازه را به ۲ یا ۵ دقیقه افزایش دهید.'
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
		?>
		<div class="mobo-card">
			<div class="mobo-card-head">
				<h2>لود دسته‌بندی قبل از Sync محصول</h2>
				<p>این عملیات فقط دسته‌بندی‌های موبو را برای جدول نگاشت دریافت می‌کند؛ هیچ دسته‌ای در ووکامرس ساخته یا بروزرسانی نمی‌شود و Sync محصول هم شروع نمی‌شود.</p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-inline-form">
				<input type="hidden" name="action" value="mobo_core_sync_categories">
				<?php wp_nonce_field( 'mobo_core_sync_categories', 'mobo_core_nonce' ); ?>
				<div class="mobo-actions">
					<button type="submit" class="mobo-btn mobo-btn-primary">همگام‌سازی دسته‌بندی‌ها</button>
				</div>
			</form>
			<div class="mobo-note">
				پس از اجرای موفق، همین صفحه را بررسی کنید و نگاشت دستی را ذخیره کنید. این دکمه مستقل از گزینه «آپدیت اتوماتیک دسته‌بندی‌های محصول» است و فقط Mapping را آماده می‌کند.
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="categories">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>دسته‌بندی محصولات</h2>
					<p>اول دسته‌بندی‌ها را از موبو دریافت کنید، بعد برای هر دسته‌ی موبو یک دسته‌ی محلی ووکامرس انتخاب کنید. این نگاشت قبل از همگام‌سازی محصول استفاده می‌شود.</p>
				</div>

				<div class="mobo-fields-grid">
					<?php $this->bool_field( 'آپدیت اتوماتیک دسته‌بندی‌های محصول', 'global_update_categories' ); ?>
					<?php $this->bool_field( 'فعال بودن نگاشت دسته‌بندی', 'mobo_core_category_mapping_enabled' ); ?>
					<?php $this->bool_field( 'اجباری بودن نگاشت دستی', 'mobo_core_category_mapping_required' ); ?>
					<?php $this->category_dropdown_field( 'دسته‌بندی پیشفرض / جایگزین', 'mobo_default_category_id' ); ?>
				</div>

				<div class="mobo-note">
					ترتیب انتخاب دسته برای محصول: نگاشت دستی، دسته همگام‌شده با category_guid، ساخت خودکار دسته جدید در صورت مجاز بودن، سپس دسته پیشفرض فقط برای محصول جدید. دسته‌های موجود ووکامرس به‌صورت پیش‌فرض بروزرسانی نمی‌شوند؛ پلاگین فقط دسته‌های جدید را می‌سازد و نام، slug، parent و متادیتای دسته‌های قبلی را تغییر نمی‌دهد.
					اگر نگاشت اجباری باشد و برای category_guid دسته محلی انتخاب نشده باشد، دسته محصول تغییر نمی‌کند و GUIDهای گمشده در meta محصول ثبت می‌شوند.
				</div>

				<?php $this->category_sync_guide_box(); ?>

				<?php
				$this->recommendation_box(
					'تنظیمات پیشنهادی دسته‌بندی',
					array(
						array( 'setting' => 'آپدیت اتوماتیک دسته‌بندی‌های محصول', 'value' => 'خاموش اگر همه دسته‌ها دستی mapping می‌شوند؛ روشن اگر دسته‌های جدید باید خودکار ساخته شوند', 'reason' => 'در هر دو حالت دسته‌های موجود آپدیت نمی‌شوند؛ روشن بودن فقط اجازه ساخت دسته جدید را می‌دهد.' ),
						array( 'setting' => 'فعال بودن نگاشت دسته‌بندی', 'value' => 'روشن', 'reason' => 'برای جلوگیری از رفتن محصولات روی default و کنترل ساختار فروشگاه لازم است.' ),
						array( 'setting' => 'اجباری بودن نگاشت دستی', 'value' => 'روشن برای فروشگاه‌های حساس؛ خاموش در import اولیه', 'reason' => 'اگر روشن باشد محصول بدون mapping دسته‌اش را تغییر نمی‌دهد و خطای mapping قابل پیگیری می‌شود.' ),
						array( 'setting' => 'دسته پیشفرض', 'value' => 'یک دسته مشخص مثل «نیازمند نگاشت»', 'reason' => 'Uncategorized عمومی پیدا کردن محصولات بدون mapping را سخت می‌کند.' ),
					),
					'پیشنهاد راه‌اندازی: اول دسته‌ها را لود کنید، mapping را ذخیره کنید، سپس Sync محصول را اجرا کنید. اگر mapping کامل است، required را روشن کنید.'
				);
				?>
			</div>

			<?php $this->category_mapping_table(); ?>

			<?php $this->save_button( 'ذخیره تنظیمات و نگاشت دسته‌بندی' ); ?>
		</form>

		<?php $this->recategorize_queue_ui(); ?>
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

				<?php $pricing_warning = $this->get_pricing_health_warning(); ?>
				<?php if ( '' !== $pricing_warning ) : ?>
					<div class="mobo-message mobo-message-warning">
						<strong>هشدار:</strong> <?php echo esc_html( $pricing_warning ); ?>
					</div>
				<?php endif; ?>

				<?php $this->pricing_rules_ui(); ?>

				<?php
				$this->guide_box(
					'راهنمای قیمت‌گذاری',
					array(
						array( 'title' => 'سود مستقیم واریانت', 'text' => 'اگر mobo_additional_price برای یک variation وجود داشته باشد، همان مقدار اولویت دارد و قانون عمومی روی آن اعمال نمی‌شود.' ),
						array( 'title' => 'قوانین بازه‌ای', 'text' => 'در قیمت‌گذاری داینامیک، اولین قانونی که بازه قیمت خام با آن تطبیق داشته باشد اعمال می‌شود.' ),
						array( 'title' => 'اعمال مجدد قیمت', 'text' => 'بعد از تغییر سیاست سود، لازم نیست محصول‌ها دوباره از سامانه دریافت شوند؛ ابزار اعمال مجدد قیمت از meta قیمت خام استفاده می‌کند.' ),
					),
					'قبل از اجرای reprice روی کل محصولات، یک نمونه محصول ساده و یک محصول متغیر را دستی بررسی کنید تا فرمول سود مطابق انتظار باشد.'
				);
				?>
				<?php
				$this->recommendation_box(
					'تنظیمات پیشنهادی قیمت‌گذاری',
					array(
						array( 'setting' => 'نوع سود', 'value' => 'داینامیک برای کاتالوگ بزرگ؛ ثابت فقط برای فروشگاه ساده', 'reason' => 'محصول ارزان و گران نباید الزاماً یک سود عددی/درصدی یکسان داشته باشند.' ),
						array( 'setting' => 'سود مستقیم واریانت', 'value' => 'فقط برای استثناها', 'reason' => 'اگر برای همه استفاده شود، کنترل مرکزی قوانین قیمت سخت می‌شود.' ),
						array( 'setting' => 'بازه‌های داینامیک', 'value' => 'پیوسته و بدون فاصله خالی', 'reason' => 'ردیف اول از ۰ شروع می‌شود و ردیف بعدی باید از عدد بعد از سقف ردیف قبلی شروع شود.' ),
						array( 'setting' => 'Reprice', 'value' => 'بعد از تغییر قوانین، مرحله‌ای اجرا شود', 'reason' => 'برای اعمال سود جدید لازم نیست همه محصولات دوباره از سامانه دریافت شوند.' ),
					),
					'قبل از reprice عمومی، حداقل یک محصول ساده و یک محصول متغیر را دستی بررسی کنید.'
				);
				?>
			</div>

			<?php $this->save_button(); ?>
		</form>

		<?php $this->reprice_queue_ui(); ?>
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

				<?php
				$this->guide_box(
					'راهنمای فیلتر محصولات',
					array(
						array( 'title' => 'فرمت ورودی', 'text' => 'هر خط یک URL یا مسیر محصول است. بهتر است همان فرمتی را وارد کنید که از MoboCore برای محصول دریافت می‌شود.' ),
						array( 'title' => 'اثر روی محصول جدید', 'text' => 'اگر محصول هنوز در ووکامرس ساخته نشده باشد و URL آن در این لیست باشد، اصلاً ایجاد نمی‌شود.' ),
						array( 'title' => 'اثر روی محصول موجود', 'text' => 'اگر محصول قبلاً sync شده باشد، با قرار گرفتن URL در این لیست، بروزرسانی بعدی آن متوقف می‌شود.' ),
					),
					'این بخش برای حذف محصول از ووکامرس نیست؛ فقط جلوی syncهای بعدی را می‌گیرد.'
				);
				?>
				<?php
				$this->recommendation_box(
					'تنظیمات پیشنهادی فیلتر محصولات',
					array(
						array( 'setting' => 'فرمت URL', 'value' => 'همان URL یا path دریافتی از MoboCore، هر خط یک مورد', 'reason' => 'اگر فرمت متفاوت باشد، محصول match نمی‌شود و همچنان sync خواهد شد.' ),
						array( 'setting' => 'زمان ثبت فیلتر', 'value' => 'قبل از اولین sync', 'reason' => 'اگر محصول قبلاً ساخته شده باشد، فیلتر فقط جلوی بروزرسانی بعدی را می‌گیرد و حذف انجام نمی‌دهد.' ),
						array( 'setting' => 'کاربرد پیشنهادی', 'value' => 'محصولات تستی، ممنوع، ناقص یا خارج از سیاست فروشگاه', 'reason' => 'این بخش برای مدیریت استثناست، نه پاکسازی کاتالوگ.' ),
					),
					'بعد از اضافه کردن فیلتر، اگر محصول قبلاً ساخته شده، برای حذف یا trash کردن آن باید جداگانه در WooCommerce تصمیم بگیرید.'
				);
				?>
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


			<div class="mobo-card mobo-queue-preset-card" data-mobo-queue-preset-card>
				<div class="mobo-card-head">
					<h2>تنظیم سریع بر اساس توان هاست</h2>
					<p>با انتخاب هر گزینه، مقدارهای همین تب به صورت موقت داخل فرم تنظیم می‌شوند. برای اعمال نهایی باید دکمه ذخیره را بزنید.</p>
				</div>

				<div class="mobo-queue-preset-actions" aria-label="تنظیم سریع صف و پردازش">
					<button type="button" class="button button-secondary" data-mobo-queue-preset="vps">VPS</button>
					<button type="button" class="button button-secondary" data-mobo-queue-preset="strong">هاست قوی</button>
					<button type="button" class="button button-secondary" data-mobo-queue-preset="medium">هاست متوسط</button>
					<button type="button" class="button button-secondary" data-mobo-queue-preset="weak">هاست ضعیف</button>
				</div>

				<div class="mobo-help" data-mobo-queue-preset-message>
					این دکمه‌ها فقط فیلدهای همین صفحه را تغییر می‌دهند و چیزی را ذخیره نمی‌کنند. بعد از انتخاب، تنظیمات را بررسی و ذخیره کنید.
				</div>
			</div>


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
						<?php $this->int_field( 'تعداد فایل وب‌هوک در هر اجرا', 'mobo_core_webhook_files_per_run', 1, 10, 4 ); ?>
						<?php $this->int_field( 'حداکثر تلاش برای هر وب‌هوک', 'mobo_core_webhook_max_try', 1, 20 ); ?>
						<?php $this->int_field( 'انقضای وب‌هوک - روز', 'mobo_core_webhook_expire_days', 1, 30 ); ?>
						<?php $this->int_field( 'مهلت انتظار محصول مادر برای تنوع - ثانیه', 'mobo_core_variant_parent_wait_timeout_seconds', 60, 86400, 600 ); ?>
						<?php $this->missing_variants_field(); ?>
					</div>
				</div>
			</div>

			<?php
			$this->guide_box(
				'راهنمای صف و پردازش مرحله‌ای',
				array(
					array( 'title' => 'محصول و واریانت', 'text' => 'تعداد صفحه‌ها را کوچک نگه دارید تا WooCommerce در هر اجرا فقط چند write کنترل‌شده انجام دهد.' ),
					array( 'title' => 'صف تصویر', 'text' => 'صف مستقل تصویر باعث می‌شود محصول روی دانلود عکس گیر نکند. اگر عکس‌ها دیر می‌آیند، وضعیت wp_mobo_image_queue را بررسی کنید.' ),
					array( 'title' => 'صف وب‌هوک', 'text' => 'وب‌هوک‌ها ابتدا فایل می‌شوند و بعد مرحله‌ای پردازش می‌شوند تا درخواست ورودی MoboCore timeout نشود.' ),
					array( 'title' => 'Missing variants', 'text' => 'رفتار واریانت‌هایی که دیگر از MoboCore نمی‌آیند اینجا تعیین می‌شود؛ معمولاً outofstock امن‌تر از حذف است.' ),
					array( 'title' => 'مهلت انتظار محصول مادر', 'text' => 'اگر UpdateVariant زودتر از ProductUpdated برسد، افزونه فقط تا این مدت منتظر ساخته شدن محصول مادر می‌ماند. بعد از اتمام مهلت، event از صف خارج می‌شود تا صف روی چند تنوع گیر نکند.' ),
				),
				'برای هاست اشتراکی مقدارهای کوچک‌تر پایدارتر هستند؛ افزایش ناگهانی تعداد محصول، واریانت یا تصویر می‌تواند باعث timeout و lock شود.'
			);

			$this->recommendation_box(
				'تنظیمات پیشنهادی صف و پردازش مرحله‌ای',
				array(
					array( 'setting' => 'بودجه زمانی Sync', 'value' => '۸ تا ۱۲ ثانیه روی هاست اشتراکی؛ ۱۵ تا ۲۰ ثانیه روی VPS', 'reason' => 'بودجه کوتاه‌تر از timeout جلوگیری می‌کند؛ بودجه خیلی بلند lock و CPU را زیاد می‌کند.' ),
					array( 'setting' => 'تعداد محصول در هر صفحه', 'value' => '۱ تا ۳ روی هاست اشتراکی؛ ۵ روی VPS', 'reason' => 'هر محصول می‌تواند ده‌ها واریانت و تصویر داشته باشد.' ),
					array( 'setting' => 'Cursor محصول و تنوع', 'value' => 'روشن', 'reason' => 'ادامه از آخرین نقطه پایدارتر از page ساده است و برای قطعی موقت امن‌تر است.' ),
					array( 'setting' => 'تعداد تنوع در هر صفحه', 'value' => '۵ تا ۱۰ برای هاست معمولی؛ ۲۰ برای VPS', 'reason' => 'واریانت‌ها پرهزینه‌تر از محصول ساده هستند، مخصوصاً روی WooCommerce.' ),
					array( 'setting' => 'تعداد تصویر در هر اجرا', 'value' => '۳ روی هاست معمولی؛ ۵ روی VPS', 'reason' => 'دانلود تصویر کند و وابسته به شبکه است؛ عدد بالا باعث timeout می‌شود.' ),
					array( 'setting' => 'صف مستقل تصویر', 'value' => 'روشن', 'reason' => 'محصول نباید به خاطر چند تصویر کند یا خراب قفل شود.' ),
					array( 'setting' => 'منتظر ماندن تا تکمیل تصاویر', 'value' => 'خاموش', 'reason' => 'این گزینه همان نقطه‌ای است که می‌تواند Sync محصول را روی تصاویر نگه دارد.' ),
					array( 'setting' => 'حداکثر تلاش تصویر / retry base', 'value' => '۵ تلاش، ۱۲۰ ثانیه', 'reason' => 'برای خطای موقت کافی است و برای URL خراب بی‌نهایت retry نمی‌سازد.' ),
					array( 'setting' => 'وب‌هوک در هر اجرا', 'value' => '۴', 'reason' => 'پیش‌فرض جدید ۴ فایل است؛ برای هاست ضعیف کمتر و برای VPS بعد از تست تدریجی بیشتر شود.' ),
					array( 'setting' => 'مهلت انتظار محصول مادر برای تنوع', 'value' => '۶۰۰ ثانیه / ۱۰ دقیقه', 'reason' => 'اگر parent در این بازه نرسید، آن UpdateVariant دیگر نگه داشته نمی‌شود و runner سراغ باقی eventها می‌رود.' ),
					array( 'setting' => 'Missing variants', 'value' => 'outofstock', 'reason' => 'از حذف ناخواسته واریانت‌ها جلوگیری می‌کند و همچنان فروش را متوقف می‌کند.' ),
				),
				'برای پایداری سایت، افزایش سرعت پردازش را تدریجی انجام دهید و مقدارها را متناسب با توان هاست نگه دارید.'
			);
			?>

			<?php $this->save_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render legacy image refresh tab.
	 *
	 * @return void
	 */
	private function render_image_refresh_tab() {
		$queue  = class_exists( 'Mobo_Core_Image_Refresh_Queue' ) ? new Mobo_Core_Image_Refresh_Queue() : null;
		$status = $queue ? $queue->get_status() : array();
		$scan   = isset( $status['lastScan'] ) && is_array( $status['lastScan'] ) ? $status['lastScan'] : array();
		$last   = isset( $status['lastResult'] ) && is_array( $status['lastResult'] ) ? $status['lastResult'] : array();
		$rows   = $queue ? $queue->get_recent_rows( 20 ) : array();
		$orphan_cleanup = class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ? new Mobo_Core_Orphan_Image_Cleanup() : null;
		$orphan_status  = $orphan_cleanup ? $orphan_cleanup->get_status() : array();
		$orphan_scan    = isset( $orphan_status['lastScan'] ) && is_array( $orphan_status['lastScan'] ) ? $orphan_status['lastScan'] : array();
		$orphan_delete  = isset( $orphan_status['lastDelete'] ) && is_array( $orphan_status['lastDelete'] ) ? $orphan_status['lastDelete'] : array();
		$orphan_rows    = $orphan_cleanup ? $orphan_cleanup->get_recent_rows( 20 ) : array();
		$repair_completed_at = class_exists( 'Mobo_Core_Product_Sync' ) ? Mobo_Core_Product_Sync::get_repair_completed_at() : 0;
		$image_refresh_locked = $repair_completed_at <= 0;
		?>
		<?php if ( $image_refresh_locked ) : ?>
			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>نوسازی تصاویر قفل است</h2>
					<p>قبل از نوسازی تصویر باید Repair محصولات یک بار کامل شود. Repair فقط hash check را bypass می‌کند و بقیه رفتارها از تنظیمات sync پیروی می‌کنند.</p>
				</div>
				<div class="mobo-message mobo-message-warning">
					ابتدا از تب داشبورد گزینه «شروع Repair محصولات» را اجرا کن. بعد از کامل شدن Repair، عملیات نوسازی تصویر و پاکسازی فایل های قدیمی فعال می‌شود.
				</div>
			</div>
			<?php return; ?>
		<?php endif; ?>

		<div class="mobo-grid">
			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>وضعیت نوسازی تصاویر قدیمی</h2>
					<p>فقط attachmentهایی بررسی می‌شوند که با متاهای موبو شناخته شده باشند. حذف فایل قدیمی فقط بعد از جایگزینی موفق انجام می‌شود.</p>
				</div>

				<div class="mobo-status-grid">
					<?php $this->status_box( 'صف pending / due / failed', absint( isset( $status['pending'] ) ? $status['pending'] : 0 ) . ' / ' . absint( isset( $status['due'] ) ? $status['due'] : 0 ) . ' / ' . absint( isset( $status['failed'] ) ? $status['failed'] : 0 ) ); ?>
					<?php $this->status_box( 'انجام شده / skip', absint( isset( $status['done'] ) ? $status['done'] : 0 ) . ' / ' . absint( isset( $status['skipped'] ) ? $status['skipped'] : 0 ) ); ?>
					<?php $this->status_box( 'آخرین اجرای صف', ! empty( $last['executedAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $last['executedAt'] ) ) : '—' ); ?>
					<?php $this->status_box( 'نتیجه آخر', ! empty( $last['status'] ) ? $last['status'] : '—' ); ?>
				</div>
			</div>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>آخرین اسکن</h2>
					<p>این بخش فقط گزارش می‌دهد و چیزی را حذف یا جایگزین نمی‌کند.</p>
				</div>

				<div class="mobo-status-grid">
					<?php $this->status_box( 'تصاویر بررسی‌شده', absint( isset( $scan['scanned'] ) ? $scan['scanned'] : 0 ) ); ?>
					<?php $this->status_box( 'تصاویر قدیمی jpg/png', absint( isset( $scan['legacyRaster'] ) ? $scan['legacyRaster'] : 0 ) ); ?>
					<?php $this->status_box( 'قابل صف شدن', absint( isset( $scan['queueable'] ) ? $scan['queueable'] : 0 ) ); ?>
					<?php $this->status_box( 'حجم تقریبی قدیمی', $this->format_bytes( isset( $scan['totalLegacyBytes'] ) ? $scan['totalLegacyBytes'] : 0 ) ); ?>
					<?php $this->status_box( 'بدون محصول / بدون URL', absint( isset( $scan['withoutProduct'] ) ? $scan['withoutProduct'] : 0 ) . ' / ' . absint( isset( $scan['withoutSourceUrl'] ) ? $scan['withoutSourceUrl'] : 0 ) ); ?>
					<?php $this->status_box( 'آخرین اسکن', ! empty( $scan['checkedAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $scan['checkedAt'] ) ) : '—' ); ?>
				</div>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mobo-settings-form">
			<input type="hidden" name="action" value="mobo_core_save_settings">
			<input type="hidden" name="mobo_active_tab" value="image-refresh">
			<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>

			<div class="mobo-card">
				<div class="mobo-card-head">
					<h2>تنظیمات ایمنی</h2>
					<p>مقادیر کوچک‌تر روی هاست مشتری امن‌تر هستند. حذف قدیمی‌ها فقط بعد از جایگزینی و بررسی استفاده انجام می‌شود.</p>
				</div>

				<div class="mobo-fields-grid">
					<?php $this->bool_field( 'فعال بودن نوسازی تصاویر قدیمی', 'mobo_core_image_refresh_enabled' ); ?>
					<?php $this->bool_field( 'حذف attachment قدیمی بعد از جایگزینی امن', 'mobo_core_image_refresh_delete_old' ); ?>
					<?php $this->int_field( 'تعداد پردازش در هر اجرا', 'mobo_core_image_refresh_per_run', 1, 20 ); ?>
					<?php $this->int_field( 'حداکثر اسکن در هر بار', 'mobo_core_image_refresh_scan_limit', 50, 5000 ); ?>
					<?php $this->int_field( 'حداکثر تلاش نوسازی تصویر', 'mobo_core_image_refresh_max_try', 1, 20 ); ?>
					<?php $this->int_field( 'فاصله پایه retry نوسازی / ثانیه', 'mobo_core_image_refresh_retry_base_seconds', 30, 1800 ); ?>
					<?php $this->bool_field( 'فعال بودن پاکسازی فایل های قدیمی بدون attachment', 'mobo_core_orphan_image_cleanup_enabled' ); ?>
					<?php $this->int_field( 'حداکثر WebP مرجع برای اسکن فایل های یتیم', 'mobo_core_orphan_image_scan_limit', 50, 5000 ); ?>
					<?php $this->int_field( 'تعداد حذف فایل یتیم در هر اجرا', 'mobo_core_orphan_image_delete_per_run', 1, 200 ); ?>
				</div>

				<?php $this->save_button(); ?>
			</div>
		</form>

		<div class="mobo-card">
			<div class="mobo-card-head">
				<h2>عملیات کنترل‌شده</h2>
				<p>ترتیب پیشنهادی: اول بررسی، بعد ساخت صف، بعد پردازش مرحله‌ای.</p>
			</div>

			<div class="mobo-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mobo_core_scan_legacy_images">
					<?php wp_nonce_field( 'mobo_core_scan_legacy_images', 'mobo_core_nonce' ); ?>
					<button type="submit" class="mobo-btn mobo-btn-light">فقط بررسی کن</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mobo_core_enqueue_image_refresh">
					<?php wp_nonce_field( 'mobo_core_enqueue_image_refresh', 'mobo_core_nonce' ); ?>
					<button type="submit" class="mobo-btn mobo-btn-primary">ساخت صف نوسازی</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mobo_core_process_image_refresh">
					<?php wp_nonce_field( 'mobo_core_process_image_refresh', 'mobo_core_nonce' ); ?>
					<button type="submit" class="mobo-btn mobo-btn-primary">پردازش مرحله‌ای صف</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mobo_core_retry_image_refresh">
					<?php wp_nonce_field( 'mobo_core_retry_image_refresh', 'mobo_core_nonce' ); ?>
					<button type="submit" class="mobo-btn mobo-btn-light">Retry خطاها</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('صف نوسازی تصویر ریست شود؟ فایل و محصول حذف نمی‌شود، فقط جدول صف خالی می‌شود.');">
					<input type="hidden" name="action" value="mobo_core_reset_image_refresh">
					<?php wp_nonce_field( 'mobo_core_reset_image_refresh', 'mobo_core_nonce' ); ?>
					<button type="submit" class="mobo-btn mobo-btn-danger">ریست صف</button>
				</form>
			</div>
		</div>

		<div class="mobo-card">
			<div class="mobo-card-head">
				<h2>آخرین آیتم‌های صف</h2>
				<p>برای کنترل اینکه چه محصولی، چه attachment قدیمی و چه URL جدیدی در صف است.</p>
			</div>

			<div class="mobo-table-wrap">
				<table class="widefat striped">
					<thead>
						<tr>
							<th>ID</th>
							<th>Product</th>
							<th>Image GUID</th>
							<th>Old</th>
							<th>New</th>
							<th>Status</th>
							<th>Last Error / Note</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="7">فعلاً آیتمی در صف نیست.</td></tr>
						<?php else : ?>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( absint( $row['id'] ) ); ?></td>
									<td><?php echo esc_html( absint( $row['product_id'] ) ); ?></td>
									<td><code><?php echo esc_html( (string) $row['image_guid'] ); ?></code></td>
									<td><?php echo esc_html( absint( $row['old_attachment_id'] ) . ' / ' . $this->format_bytes( isset( $row['old_file_size'] ) ? $row['old_file_size'] : 0 ) ); ?></td>
									<td><?php echo esc_html( absint( $row['new_attachment_id'] ) ); ?></td>
									<td><?php echo esc_html( (string) $row['status'] ); ?></td>
									<td><?php echo esc_html( isset( $row['last_error'] ) ? (string) $row['last_error'] : '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="mobo-card">
			<div class="mobo-card-head">
				<h2>فایل های قدیمی بدون attachment</h2>
				<p>این بخش فقط فایل های jpg / jpeg / png داخل uploads را لیست و حذف می کند که هم نام نسخه WebP نهایی موبو باشند، در Media Library ثبت نشده باشند و در دیتابیس هم reference نداشته باشند.</p>
			</div>

			<div class="mobo-status-grid">
				<?php $this->status_box( 'کاندید حذف / skip / failed', absint( isset( $orphan_status['candidate'] ) ? $orphan_status['candidate'] : 0 ) . ' / ' . absint( isset( $orphan_status['skipped'] ) ? $orphan_status['skipped'] : 0 ) . ' / ' . absint( isset( $orphan_status['failed'] ) ? $orphan_status['failed'] : 0 ) ); ?>
				<?php $this->status_box( 'حذف شده', absint( isset( $orphan_status['deleted'] ) ? $orphan_status['deleted'] : 0 ) ); ?>
				<?php $this->status_box( 'آخرین اسکن فایل', ! empty( $orphan_scan['checkedAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $orphan_scan['checkedAt'] ) ) : '—' ); ?>
				<?php $this->status_box( 'کاندید / حجم قابل حذف', absint( isset( $orphan_scan['candidateFiles'] ) ? $orphan_scan['candidateFiles'] : 0 ) . ' / ' . $this->format_bytes( isset( $orphan_scan['totalBytes'] ) ? $orphan_scan['totalBytes'] : 0 ) ); ?>
				<?php $this->status_box( 'آخرین حذف فایل', ! empty( $orphan_delete['executedAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $orphan_delete['executedAt'] ) ) : '—' ); ?>
				<?php $this->status_box( 'حذف آخر / حجم آزاد شده', absint( isset( $orphan_delete['deleted'] ) ? $orphan_delete['deleted'] : 0 ) . ' / ' . $this->format_bytes( isset( $orphan_delete['bytes'] ) ? $orphan_delete['bytes'] : 0 ) ); ?>
			</div>

			<div class="mobo-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mobo_core_scan_orphan_images">
					<?php wp_nonce_field( 'mobo_core_scan_orphan_images', 'mobo_core_nonce' ); ?>
					<button type="submit" class="mobo-btn mobo-btn-light">لیست کردن فایل های قدیمی</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('فقط فایل های candidate حذف می شوند و قبل از حذف دوباره بررسی ایمنی انجام می شود. ادامه می دهید؟');">
					<input type="hidden" name="action" value="mobo_core_delete_orphan_images">
					<?php wp_nonce_field( 'mobo_core_delete_orphan_images', 'mobo_core_nonce' ); ?>
					<button type="submit" class="mobo-btn mobo-btn-danger">حذف کنترل شده candidate ها</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('لیست فایل های یتیم ریست شود؟ فایل فیزیکی حذف نمی شود.');">
					<input type="hidden" name="action" value="mobo_core_reset_orphan_images">
					<?php wp_nonce_field( 'mobo_core_reset_orphan_images', 'mobo_core_nonce' ); ?>
					<button type="submit" class="mobo-btn mobo-btn-light">ریست لیست</button>
				</form>
			</div>
		</div>

		<div class="mobo-card">
			<div class="mobo-card-head">
				<h2>آخرین فایل های پیدا شده</h2>
				<p>فقط ردیف های candidate در عملیات حذف پردازش می شوند. ردیف های skipped یعنی سیستم یک وابستگی یا ریسک پیدا کرده و فایل را دست نزده است.</p>
			</div>

			<div class="mobo-table-wrap">
				<table class="widefat striped">
					<thead>
						<tr>
							<th>ID</th>
							<th>File</th>
							<th>Matched WebP</th>
							<th>Size</th>
							<th>Status</th>
							<th>Reason</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $orphan_rows ) ) : ?>
							<tr><td colspan="6">فعلا فایلی لیست نشده است.</td></tr>
						<?php else : ?>
							<?php foreach ( $orphan_rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( absint( $row['id'] ) ); ?></td>
									<td><code><?php echo esc_html( isset( $row['relative_path'] ) ? (string) $row['relative_path'] : '' ); ?></code></td>
									<td><code><?php echo esc_html( isset( $row['matched_webp_relative_path'] ) ? (string) $row['matched_webp_relative_path'] : '' ); ?></code></td>
									<td><?php echo esc_html( $this->format_bytes( isset( $row['file_size'] ) ? $row['file_size'] : 0 ) ); ?></td>
									<td><?php echo esc_html( isset( $row['status'] ) ? (string) $row['status'] : '' ); ?></td>
									<td><?php echo esc_html( isset( $row['last_error'] ) ? (string) $row['last_error'] : '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
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
	private function save_button( $label = 'ذخیره تنظیمات' ) {
		?>
		<div class="mobo-save-row">
			<button type="submit" class="mobo-btn mobo-btn-primary">
				<?php echo esc_html( $label ); ?>
			</button>
		</div>
		<?php
	}



	/**
	 * Render manual address mapping UI.
	 *
	 * @param Mobo_Core_Address_Mapping $address_mapping Address mapping service.
	 * @return void
	 */
	private function render_address_manual_mapping_ui( $address_mapping ) {
		$local = method_exists( $address_mapping, 'get_local_location_candidates' ) ? $address_mapping->get_local_location_candidates() : array();
		$mobo  = method_exists( $address_mapping, 'get_cached_mapping' ) ? $address_mapping->get_cached_mapping() : array();
		$manual = method_exists( $address_mapping, 'get_manual_mapping' ) ? $address_mapping->get_manual_mapping() : array();

		$countries = isset( $local['countries'] ) && is_array( $local['countries'] ) ? $local['countries'] : array();
		$states    = isset( $local['states'] ) && is_array( $local['states'] ) ? $local['states'] : array();
		$cities    = isset( $local['cities'] ) && is_array( $local['cities'] ) ? $local['cities'] : array();
		$mobo_countries = isset( $mobo['countries'] ) && is_array( $mobo['countries'] ) ? $mobo['countries'] : array();
		$mobo_states    = isset( $mobo['states'] ) && is_array( $mobo['states'] ) ? $mobo['states'] : array();
		$mobo_cities    = isset( $mobo['cities'] ) && is_array( $mobo['cities'] ) ? $mobo['cities'] : array();

		$manual_countries = isset( $manual['countries'] ) && is_array( $manual['countries'] ) ? $manual['countries'] : array();
		$manual_states    = isset( $manual['states'] ) && is_array( $manual['states'] ) ? $manual['states'] : array();
		$manual_cities    = isset( $manual['cities'] ) && is_array( $manual['cities'] ) ? $manual['cities'] : array();
		$show_all_countries = Mobo_Core_Settings::enabled( 'mobo_core_address_mapping_show_all_countries', '0' );
		$country_scope_is_limited = false;
		foreach ( $countries as $country_row ) {
			if ( ! empty( $country_row['isDefaultScope'] ) ) {
				$country_scope_is_limited = true;
				break;
			}
		}

		$mobo_city_lookup = array();
		$cities_by_state  = array();
		foreach ( $mobo_cities as $city ) {
			$id = isset( $city['id'] ) ? absint( $city['id'] ) : 0;
			$state_id = isset( $city['stateId'] ) ? absint( $city['stateId'] ) : 0;
			$name = isset( $city['name'] ) ? sanitize_text_field( (string) $city['name'] ) : '';
			if ( $id <= 0 || $state_id <= 0 || '' === $name ) {
				continue;
			}
			$mobo_city_lookup[ $id ] = $name;
			if ( ! isset( $cities_by_state[ $state_id ] ) ) {
				$cities_by_state[ $state_id ] = array();
			}
			$cities_by_state[ $state_id ][] = array( 'id' => $id, 'name' => $name );
		}

		$js_payload = array(
			'countries' => $mobo_countries,
			'states'    => $mobo_states,
			'citiesByState' => $cities_by_state,
		);
		?>
		<div class="mobo-address-manual-map" data-mobo-address-map-root>
			<div class="mobo-card" style="background:#f8fafc;margin-top:16px;">
				<div class="mobo-card-head">
					<h3>نگاشت دستی آدرس WooCommerce به موبو</h3>
					<p>داده خام موبو از MoboCore دریافت می‌شود، اما نگاشت نهایی باید توسط مدیر تایید شود. دکمه پیشنهاد خودکار فقط فیلدهای مشابه را در UI انتخاب می‌کند؛ تا زمانی که تنظیمات را ذخیره نکنید اعمال نمی‌شود.</p>
				</div>

				<p>
					<button type="button" class="button button-secondary" data-mobo-address-auto-map>پیشنهاد خودکار نگاشت</button>
				</p>

				<div class="mobo-address-map-result" data-mobo-address-map-result style="display:none;"></div>

				<label class="mobo-inline-check" style="display:flex;align-items:center;gap:8px;margin:12px 0;">
					<input type="hidden" name="mobo_core_address_mapping_show_all_countries" value="0"><input type="checkbox" name="mobo_core_address_mapping_show_all_countries" value="1" <?php checked( $show_all_countries ); ?>>
					<span>نمایش همه کشورها برای نگاشت</span>
				</label>

				<?php if ( $country_scope_is_limited ) : ?>
					<div class="mobo-alert mobo-alert-warning">
						در ووکامرس ارسال به همه کشورها فعال است. برای ساده تر شدن کار، فعلا فقط کشور اصلی فروشگاه/ایران نمایش داده می شود. اگر واقعا به کشورهای دیگر سفارش می فرستید، گزینه «نمایش همه کشورها برای نگاشت» را روشن و ذخیره کنید.
					</div>
				<?php endif; ?>

				<div class="mobo-note">
					برای خرید اتوماتیک، checkout می‌تواند همچنان توسط WooCommerce یا ووکامرس فارسی کنترل شود. پلاگین در زمان ساخت سفارش، کشور/استان/شهر انتخاب شده را فقط از همین نگاشت دستی به شناسه‌های موبو تبدیل می‌کند.
				</div>

				<h4>کشورها</h4>
				<div style="max-height:280px;overflow:auto;border:1px solid #e5e7eb;background:#fff;border-radius:10px;">
					<table class="widefat striped" style="margin:0;">
						<thead><tr><th>کشور WooCommerce</th><th>شناسه کشور موبو</th></tr></thead>
						<tbody>
						<?php if ( empty( $countries ) ) : ?>
							<tr><td colspan="2">کشوری از WooCommerce قابل تشخیص نیست.</td></tr>
						<?php else : ?>
							<?php foreach ( $countries as $country ) : ?>
								<?php
								$code = isset( $country['code'] ) ? strtoupper( sanitize_text_field( (string) $country['code'] ) ) : '';
								$name = isset( $country['name'] ) ? sanitize_text_field( (string) $country['name'] ) : $code;
								$selected = isset( $manual_countries[ $code ] ) ? absint( $manual_countries[ $code ] ) : 0;
								?>
								<tr>
									<td><strong><?php echo esc_html( $name ); ?></strong> <code dir="ltr"><?php echo esc_html( $code ); ?></code></td>
									<td>
										<select name="mobo_address_map_country[<?php echo esc_attr( $code ); ?>]" class="mobo-address-map-country mobo-address-select2" data-placeholder="جستجو و انتخاب کشور موبو" data-local-label="<?php echo esc_attr( $name ); ?>" data-country-code="<?php echo esc_attr( $code ); ?>">
											<option value="">— انتخاب نشده —</option>
											<?php foreach ( $mobo_countries as $mobo_country ) : ?>
												<?php $id = isset( $mobo_country['id'] ) ? absint( $mobo_country['id'] ) : 0; ?>
												<?php if ( $id <= 0 ) { continue; } ?>
												<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $selected, $id ); ?> data-iso="<?php echo esc_attr( isset( $mobo_country['isoCode'] ) ? strtoupper( sanitize_text_field( (string) $mobo_country['isoCode'] ) ) : '' ); ?>"><?php echo esc_html( isset( $mobo_country['name'] ) ? $mobo_country['name'] : ( '#' . $id ) ); ?><?php echo ! empty( $mobo_country['isoCode'] ) ? ' — ' . esc_html( strtoupper( sanitize_text_field( (string) $mobo_country['isoCode'] ) ) ) : ''; ?> — <?php echo esc_html( $id ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>

				<h4 style="margin-top:18px;">استان‌ها</h4>
				<div style="max-height:360px;overflow:auto;border:1px solid #e5e7eb;background:#fff;border-radius:10px;">
					<table class="widefat striped" style="margin:0;">
						<thead><tr><th>استان WooCommerce</th><th>شناسه استان موبو</th></tr></thead>
						<tbody>
						<?php if ( empty( $states ) ) : ?>
							<tr><td colspan="2">استانی از WooCommerce قابل تشخیص نیست.</td></tr>
						<?php else : ?>
							<?php foreach ( $states as $state ) : ?>
								<?php
								$country = isset( $state['country'] ) ? strtoupper( sanitize_text_field( (string) $state['country'] ) ) : 'IR';
								$code = isset( $state['code'] ) ? sanitize_text_field( (string) $state['code'] ) : '';
								$name = isset( $state['name'] ) ? sanitize_text_field( (string) $state['name'] ) : $code;
								$key = $country . '|' . $code;
								$selected = isset( $manual_states[ $key ] ) ? absint( $manual_states[ $key ] ) : 0;
								?>
								<tr>
									<td><strong><?php echo esc_html( $name ); ?></strong> <code dir="ltr"><?php echo esc_html( $country . ':' . $code ); ?></code></td>
									<td>
										<select name="mobo_address_map_state[<?php echo esc_attr( $key ); ?>]" class="mobo-address-map-state mobo-address-select2" data-placeholder="جستجو و انتخاب استان موبو" data-local-label="<?php echo esc_attr( $name ); ?>" data-state-key="<?php echo esc_attr( $key ); ?>" data-country-code="<?php echo esc_attr( $country ); ?>">
											<option value="">— انتخاب نشده —</option>
											<?php foreach ( $mobo_states as $mobo_state ) : ?>
												<?php $id = isset( $mobo_state['id'] ) ? absint( $mobo_state['id'] ) : 0; ?>
												<?php if ( $id <= 0 ) { continue; } ?>
												<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $selected, $id ); ?> data-country-id="<?php echo esc_attr( isset( $mobo_state['countryId'] ) ? absint( $mobo_state['countryId'] ) : 0 ); ?>"><?php echo esc_html( isset( $mobo_state['name'] ) ? $mobo_state['name'] : ( '#' . $id ) ); ?> — <?php echo esc_html( $id ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>

				<h4 style="margin-top:18px;">شهرها</h4>
				<?php if ( empty( $cities ) ) : ?>
					<div class="mobo-alert mobo-alert-warning">لیست شهرهای محلی از WooCommerce/ووکامرس فارسی قابل تشخیص نیست. اگر ووکامرس فارسی شهرها را dropdown می‌کند اما این جدول خالی است، باید منبع شهرهای آن افزونه را به فیلتر <code>mobo_core_local_city_candidates</code> وصل کنیم.</div>
				<?php else : ?>
					<div style="max-height:520px;overflow:auto;border:1px solid #e5e7eb;background:#fff;border-radius:10px;">
						<table class="widefat striped" style="margin:0;">
							<thead><tr><th>شهر محلی</th><th>شناسه شهر موبو</th></tr></thead>
							<tbody>
							<?php foreach ( $cities as $city ) : ?>
								<?php
								$country = isset( $city['country'] ) ? strtoupper( sanitize_text_field( (string) $city['country'] ) ) : 'IR';
								$state = isset( $city['state'] ) ? sanitize_text_field( (string) $city['state'] ) : '';
								$name = isset( $city['name'] ) ? sanitize_text_field( (string) $city['name'] ) : '';
								$row_key = md5( $country . '|' . $state . '|' . $name );
								$manual_key = $country . '|' . $state . '|' . $this->normalize_admin_persian_label( $name );
								$selected = 0;
								if ( isset( $manual_cities[ $manual_key ] ) ) {
									$entry = $manual_cities[ $manual_key ];
									$selected = is_array( $entry ) && isset( $entry['id'] ) ? absint( $entry['id'] ) : absint( $entry );
								}
								$selected_label = $selected > 0 && isset( $mobo_city_lookup[ $selected ] ) ? $mobo_city_lookup[ $selected ] : '';
								?>
								<tr>
									<td><strong><?php echo esc_html( $name ); ?></strong> <code dir="ltr"><?php echo esc_html( $country . ':' . $state ); ?></code></td>
									<td>
										<input type="hidden" name="mobo_address_map_city_country[<?php echo esc_attr( $row_key ); ?>]" value="<?php echo esc_attr( $country ); ?>">
										<input type="hidden" name="mobo_address_map_city_state[<?php echo esc_attr( $row_key ); ?>]" value="<?php echo esc_attr( $state ); ?>">
										<input type="hidden" name="mobo_address_map_city_name[<?php echo esc_attr( $row_key ); ?>]" value="<?php echo esc_attr( $name ); ?>">
										<select name="mobo_address_map_city_id[<?php echo esc_attr( $row_key ); ?>]" class="mobo-address-map-city mobo-address-select2" data-placeholder="جستجو و انتخاب شهر موبو" data-local-label="<?php echo esc_attr( $name ); ?>" data-state-key="<?php echo esc_attr( $country . '|' . $state ); ?>" data-current="<?php echo esc_attr( $selected ); ?>">
											<option value="">— انتخاب نشده —</option>
											<?php if ( $selected > 0 ) : ?>
												<option value="<?php echo esc_attr( $selected ); ?>" selected><?php echo esc_html( '' !== $selected_label ? $selected_label : ( '#' . $selected ) ); ?> — <?php echo esc_html( $selected ); ?></option>
											<?php endif; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
			<script>
			(function(){
				var root = document.querySelector('[data-mobo-address-map-root]');
				if (!root || root.getAttribute('data-bound') === '1') return;
				root.setAttribute('data-bound', '1');
				var data = <?php echo wp_json_encode( $js_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?> || {};
				var resultBox = root.querySelector('[data-mobo-address-map-result]');

				function norm(value) {
					return String(value || '')
						.toLowerCase()
						.replace(/[\u064b-\u065f\u0670]/g, '')
						.replace(/ي/g, 'ی')
						.replace(/ك/g, 'ک')
						.replace(/ة|ۀ/g, 'ه')
						.replace(/ـ/g, '')
						.replace(/[،,]/g, ' ')
						.replace(/\s+/g, ' ')
						.trim();
				}
				function cleanAdministrativeWords(value) {
					return norm(value).replace(/^(استان|شهر|شهرستان)\s+/g, '').replace(/\s+(استان|شهر|شهرستان)$/g, '').trim();
				}
				function variants(value) {
					var raw = String(value || '');
					var items = [norm(raw), cleanAdministrativeWords(raw)];
					var match;
					var re = /\(([^)]+)\)|\uFF08([^\uFF09]+)\uFF09/g;
					while ((match = re.exec(raw)) !== null) {
						items.push(norm(match[1] || match[2] || ''));
						items.push(cleanAdministrativeWords(match[1] || match[2] || ''));
					}
					items.push(norm(raw.replace(/\([^)]*\)/g, ' ')));
					items.push(cleanAdministrativeWords(raw.replace(/\([^)]*\)/g, ' ')));
					var out = [];
					items.forEach(function(item){
						if (item && out.indexOf(item) === -1) out.push(item);
					});
					return out;
				}
				function uniqueById(rows) {
					var seen = {};
					return (rows || []).filter(function(row){
						var id = String(row && row.id || '');
						if (!id || seen[id]) return false;
						seen[id] = true;
						return true;
					});
				}
				function rowIso(row) {
					return String((row && (row.isoCode || row.iso_code || row.iso || row.code || row.countryCode || row.country_code)) || '').toUpperCase().trim();
				}
				function matchRows(rows, label, parentKey, parentValue) {
					var names = variants(label);
					var pool = (rows || []).filter(function(row){
						if (!row) return false;
						if (parentKey && String(row[parentKey] || '') !== String(parentValue || '')) return false;
						return true;
					});
					for (var i = 0; i < names.length; i++) {
						var matched = uniqueById(pool.filter(function(row){ return variants(row.name || '').indexOf(names[i]) !== -1; }));
						if (matched.length === 1) return {status:'matched', row: matched[0]};
						if (matched.length > 1) return {status:'ambiguous'};
					}
					return {status:'missing'};
				}
				function matchCountry(select) {
					var code = String(select.getAttribute('data-country-code') || '').toUpperCase();
					var isoMatches = uniqueById((data.countries || []).filter(function(row){ return rowIso(row) === code; }));
					if (isoMatches.length === 1) return {status:'matched', row: isoMatches[0]};
					if (isoMatches.length > 1) return {status:'ambiguous'};
					return matchRows(data.countries || [], select.getAttribute('data-local-label') || '');
				}
				function selectSet(select, value) {
					if (!select) return false;
					value = String(value || '0');
					select.value = value;
					select.setAttribute('data-current', value);
					if (window.jQuery) window.jQuery(select).trigger('change');
					return value !== '0' && value !== '';
				}
				function setRowState(select, state) {
					var row = select ? select.closest('tr') : null;
					if (!row) return;
					row.classList.remove('mobo-map-row-matched', 'mobo-map-row-missing', 'mobo-map-row-ambiguous');
					if (state) row.classList.add('mobo-map-row-' + state);
				}
				function findSelectByData(className, attr, value) {
					var found = null;
					root.querySelectorAll('.' + className).forEach(function(select){
						if (found) return;
						if (String(select.getAttribute(attr) || '') === String(value || '')) found = select;
					});
					return found;
				}
				function getCountryMoboId(code) {
					var select = findSelectByData('mobo-address-map-country', 'data-country-code', code);
					return select ? select.value : '';
				}
				function getStateMoboId(stateKey) {
					var select = findSelectByData('mobo-address-map-state', 'data-state-key', stateKey);
					return select ? select.value : '';
				}
				function rebuildCitySelect(select) {
					if (!select) return;
					var stateId = getStateMoboId(select.getAttribute('data-state-key') || '');
					var current = select.value && select.value !== '0' ? select.value : (select.getAttribute('data-current') || '0');
					var currentText = '';
					if (select.selectedIndex >= 0 && select.options[select.selectedIndex]) {
						currentText = select.options[select.selectedIndex].textContent || '';
					}
					var rows = stateId ? (data.citiesByState[String(stateId)] || []) : [];
					var hadCurrent = false;
					if (window.jQuery) {
						var $select = window.jQuery(select);
						try {
							if ($select.data('selectWoo')) { $select.selectWoo('destroy'); }
							else if ($select.data('select2')) { $select.select2('destroy'); }
						} catch(e) {}
					}
					select.innerHTML = '<option value="">— انتخاب نشده —</option>';
					rows.forEach(function(row){
						var option = document.createElement('option');
						option.value = String(row.id || 0);
						option.textContent = String(row.name || ('#' + row.id)) + ' — ' + String(row.id || '');
						if (String(row.id || '') === String(current || '')) { option.selected = true; hadCurrent = true; }
						select.appendChild(option);
					});
					if (current && current !== '0' && !hadCurrent) {
						var preserved = document.createElement('option');
						preserved.value = String(current);
						preserved.textContent = currentText || ('#' + current);
						preserved.selected = true;
						select.appendChild(preserved);
					}
					select.setAttribute('data-current', current || '0');
					if (window.jQuery) {
						window.jQuery(select).trigger('change');
						if (window.MoboCoreInitSelect2) window.MoboCoreInitSelect2(select.parentNode || root);
					}
				}

				function rebuildCitiesForState(stateKey) {
					root.querySelectorAll('.mobo-address-map-city').forEach(function(select){
						if (String(select.getAttribute('data-state-key') || '') === String(stateKey || '')) rebuildCitySelect(select);
					});
				}
				function showResult(message, type) {
					if (!resultBox) return;
					resultBox.style.display = 'block';
					resultBox.className = 'mobo-address-map-result mobo-alert ' + (type === 'warning' ? 'mobo-alert-warning' : 'mobo-alert-success');
					resultBox.textContent = message;
				}

				root.querySelectorAll('.mobo-address-map-state').forEach(function(select){
					select.addEventListener('change', function(){ rebuildCitiesForState(select.getAttribute('data-state-key') || ''); });
				});
				root.querySelectorAll('.mobo-address-map-city').forEach(rebuildCitySelect);

				var button = root.querySelector('[data-mobo-address-auto-map]');
				if (button) {
					button.addEventListener('click', function(){
						var matched = {countries:0, states:0, cities:0};
						var ambiguous = 0;
						var missing = 0;
						root.querySelectorAll('.mobo-address-map-country').forEach(function(select){
							if (select.value && select.value !== '0') return;
							var result = matchCountry(select);
							if (result.status === 'matched' && selectSet(select, result.row.id)) { matched.countries++; setRowState(select, 'matched'); }
							else { result.status === 'ambiguous' ? ambiguous++ : missing++; setRowState(select, result.status === 'ambiguous' ? 'ambiguous' : 'missing'); }
						});
						root.querySelectorAll('.mobo-address-map-state').forEach(function(select){
							if (select.value && select.value !== '0') return;
							var countryId = getCountryMoboId(select.getAttribute('data-country-code') || 'IR');
							var result = countryId ? matchRows(data.states || [], select.getAttribute('data-local-label') || '', 'countryId', countryId) : {status:'missing'};
							if (result.status === 'matched' && selectSet(select, result.row.id)) { matched.states++; setRowState(select, 'matched'); rebuildCitiesForState(select.getAttribute('data-state-key') || ''); }
							else { result.status === 'ambiguous' ? ambiguous++ : missing++; setRowState(select, result.status === 'ambiguous' ? 'ambiguous' : 'missing'); }
						});
						root.querySelectorAll('.mobo-address-map-city').forEach(function(select){
							if (select.value && select.value !== '0') return;
							var stateId = getStateMoboId(select.getAttribute('data-state-key') || '');
							var rows = stateId ? (data.citiesByState[String(stateId)] || []) : [];
							var result = matchRows(rows, select.getAttribute('data-local-label') || '');
							if (result.status === 'matched' && selectSet(select, result.row.id)) { matched.cities++; setRowState(select, 'matched'); }
							else { result.status === 'ambiguous' ? ambiguous++ : missing++; setRowState(select, result.status === 'ambiguous' ? 'ambiguous' : 'missing'); }
						});
						showResult('پیشنهاد خودکار انجام شد. کشور: ' + matched.countries + '، استان: ' + matched.states + '، شهر: ' + matched.cities + '. موارد مبهم: ' + ambiguous + '، بدون پیشنهاد: ' + missing + '. قبل از ذخیره، انتخاب ها را بررسی کنید.', ambiguous || missing ? 'warning' : 'success');
					});
				}
			})();
			</script>

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
	 * Render WooCommerce shipping-method to Mobo shipping-method mapping rules.
	 *
	 * @param array $methods Remote Mobo shipping methods.
	 * @return void
	 */
	private function render_mobo_shipping_rules( $methods ) {
		$manager = class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ? new Mobo_Core_Remote_Shipping_Methods() : null;
		$zones   = $this->get_woocommerce_shipping_zones_for_admin();

		?>
		<div class="mobo-note">
			نمایش روش ارسال در checkout همچنان کاملا توسط WooCommerce انجام می‌شود. این بخش فقط مشخص می‌کند وقتی سفارش به صورت خودکار در موبو ساخته می‌شود، روش ارسال انتخاب‌شده در سفارش WooCommerce به کدام روش ارسال موبو تبدیل شود.
		</div>

		<?php if ( empty( $methods ) ) : ?>
			<div class="mobo-alert mobo-alert-warning">هنوز روش ارسال موبو در cache پلاگین وجود ندارد. ابتدا از دکمه «بروزرسانی روش‌های ارسال از MoboCore» استفاده کنید.</div>
		<?php endif; ?>

		<?php if ( empty( $zones ) ) : ?>
			<div class="mobo-alert mobo-alert-warning"><strong>الزامی:</strong> هیچ روش حمل و نقل فعالی در Shipping Zone های WooCommerce پیدا نشد. اول از مسیر WooCommerce > Settings > Shipping حداقل یک منطقه و روش حمل و نقل فعال تعریف کنید.</div>
			<?php return; ?>
		<?php endif; ?>

		<?php $scenarios = $manager && method_exists( $manager, 'get_scenarios' ) ? $manager->get_scenarios() : array( 'mobo_only' => 'سفارش فقط محصولات موبو', 'mixed' => 'سفارش ترکیبی موبو و غیرموبو' ); ?>
		<?php foreach ( $scenarios as $scenario_key => $scenario_label ) : ?>
			<div class="mobo-card mobo-card-wide mobo-shipping-scenario-card">
				<h3><?php echo esc_html( $scenario_label ); ?></h3>
				<p class="mobo-help">
					<?php if ( 'mixed' === $scenario_key ) : ?>
						این نگاشت فقط وقتی استفاده می‌شود که سفارش هم محصول موبو و هم محصول غیرموبو داشته باشد. فقط آیتم‌های موبویی به موبو ارسال می‌شوند، اما shipping_id موبو از همین جدول انتخاب می‌شود.
					<?php else : ?>
						این نگاشت فقط برای سفارش‌هایی استفاده می‌شود که همه آیتم‌های سفارش محصول موبو باشند.
					<?php endif; ?>
				</p>

				<?php foreach ( $zones as $zone ) : ?>
					<?php $zone_id = absint( isset( $zone['id'] ) ? $zone['id'] : 0 ); ?>
					<details class="mobo-shipping-accordion" data-mobo-shipping-scenario="<?php echo esc_attr( $scenario_key ); ?>" data-mobo-shipping-zone="<?php echo esc_attr( $zone_id ); ?>" open>
						<summary class="mobo-shipping-accordion-summary">
							<span>
								<strong><?php echo esc_html( isset( $zone['name'] ) ? $zone['name'] : ( 'Shipping Zone #' . $zone_id ) ); ?></strong>
								<small>تعداد روش‌های حمل و نقل فعال: <?php echo esc_html( isset( $zone['methods'] ) && is_array( $zone['methods'] ) ? count( $zone['methods'] ) : 0 ); ?></small>
							</span>
						</summary>
						<div class="mobo-shipping-accordion-body">
							<div class="mobo-shipping-zone-locations">
								<strong>ناحیه‌های انتخاب شده در این منطقه:</strong>
								<?php $locations = isset( $zone['locations'] ) && is_array( $zone['locations'] ) ? $zone['locations'] : array(); ?>
								<?php if ( empty( $locations ) ) : ?>
									<span class="mobo-shipping-location-badge">سایر ناحیه‌هایی که در منطقه‌های دیگر نیستند</span>
								<?php else : ?>
									<?php foreach ( $locations as $location_label ) : ?>
										<span class="mobo-shipping-location-badge"><?php echo esc_html( $location_label ); ?></span>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>

							<div class="mobo-shipping-method-map-list">
								<?php foreach ( $zone['methods'] as $method ) : ?>
									<?php
									$method_id   = isset( $method['methodId'] ) ? sanitize_key( $method['methodId'] ) : '';
									$instance_id = absint( isset( $method['instanceId'] ) ? $method['instanceId'] : 0 );
									if ( '' === $method_id ) {
										continue;
									}
									$ids_key = $manager && method_exists( $manager, 'build_wc_method_rule_option_key' ) ? $manager->build_wc_method_rule_option_key( $zone_id, $method_id, $instance_id, $scenario_key ) : ( 'mobo_core_wc_shipping_method_map_' . $scenario_key . '_zone_' . $zone_id . '_' . $method_id . '_' . $instance_id );
									if ( 'mobo_only' === $scenario_key && $manager && method_exists( $manager, 'build_legacy_wc_method_rule_option_key' ) && empty( $this->get_shipping_selected_ids_for_admin( $ids_key ) ) ) {
										$legacy_key = $manager->build_legacy_wc_method_rule_option_key( $zone_id, $method_id, $instance_id );
										$legacy_ids = $this->get_shipping_selected_ids_for_admin( $legacy_key );
										if ( ! empty( $legacy_ids ) ) {
											update_option( $ids_key, (string) absint( $legacy_ids[0] ), false );
										}
									}
									?>
									<div class="mobo-card mobo-shipping-method-map-card">
										<div class="mobo-shipping-wc-method-info">
											<strong><?php echo esc_html( isset( $method['title'] ) && '' !== trim( (string) $method['title'] ) ? $method['title'] : 'روش حمل و نقل ووکامرس' ); ?></strong>
											<span>نوع روش: <?php echo esc_html( isset( $method['methodTitle'] ) && '' !== trim( (string) $method['methodTitle'] ) ? $method['methodTitle'] : 'روش ارسال' ); ?></span>
										</div>
										<?php $this->render_shipping_method_single_select( 'روش ارسال موبو برای ' . $scenario_label, $ids_key, $methods, 'وقتی این نوع سفارش با این روش حمل و نقل ووکامرس ثبت شود، همین روش ارسال در سفارش موبو استفاده می‌شود.', array( 'required' => 'required', 'data-mobo-wc-shipping-scenario' => $scenario_key, 'data-mobo-wc-shipping-zone-id' => $zone_id, 'data-mobo-wc-shipping-method-id' => $method_id, 'data-mobo-wc-shipping-instance-id' => $instance_id ) ); ?>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					</details>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
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
	private function validate_mobo_order_submission_required_config() {
		$mapped_states = $this->get_mobo_shipping_mapped_states_for_admin();
		if ( empty( $mapped_states ) ) {
			return new WP_Error( 'mobo_required_state_mapping_missing', 'برای ثبت سفارش خودکار در موبو، نگاشت استان اجباری است. اول بخش «نگاشت دستی آدرس WooCommerce به موبو» را کامل و ذخیره کنید.' );
		}

		if ( ! class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ) {
			return true;
		}

		$manager = new Mobo_Core_Remote_Shipping_Methods();
		$zones   = $this->get_woocommerce_shipping_zones_for_admin();
		if ( empty( $zones ) ) {
			return new WP_Error( 'mobo_required_wc_shipping_methods_missing', 'برای ثبت سفارش خودکار در موبو، حداقل یک Shipping Zone و یک روش حمل و نقل فعال در WooCommerce لازم است.' );
		}

		$missing = array();
		$scenarios = method_exists( $manager, 'get_scenarios' ) ? $manager->get_scenarios() : array( 'mobo_only' => 'سفارش فقط محصولات موبو', 'mixed' => 'سفارش ترکیبی موبو و غیرموبو' );
		foreach ( $scenarios as $scenario_key => $scenario_label ) {
			foreach ( $zones as $zone ) {
				$zone_id   = absint( isset( $zone['id'] ) ? $zone['id'] : 0 );
				$zone_name = isset( $zone['name'] ) ? (string) $zone['name'] : ( 'Shipping Zone #' . $zone_id );
				$methods   = isset( $zone['methods'] ) && is_array( $zone['methods'] ) ? $zone['methods'] : array();

				foreach ( $methods as $method ) {
					$method_id   = isset( $method['methodId'] ) ? sanitize_key( $method['methodId'] ) : '';
					$instance_id = absint( isset( $method['instanceId'] ) ? $method['instanceId'] : 0 );
					if ( '' === $method_id ) {
						continue;
					}

					$key = method_exists( $manager, 'build_wc_method_rule_option_key' ) ? $manager->build_wc_method_rule_option_key( $zone_id, $method_id, $instance_id, $scenario_key ) : ( 'mobo_core_wc_shipping_method_map_' . $scenario_key . '_zone_' . $zone_id . '_' . $method_id . '_' . $instance_id );
					if ( empty( $this->get_shipping_selected_ids_for_admin( $key ) ) ) {
						$method_title = isset( $method['title'] ) && '' !== trim( (string) $method['title'] ) ? (string) $method['title'] : ( isset( $method['methodTitle'] ) && '' !== trim( (string) $method['methodTitle'] ) ? (string) $method['methodTitle'] : 'روش حمل و نقل ووکامرس' );
						$missing[] = $scenario_label . ' / ' . $zone_name . ' / ' . $method_title;
					}
				}
			}
		}

		if ( ! empty( $missing ) ) {
			$preview = implode( '، ', array_slice( $missing, 0, 4 ) );
			if ( count( $missing ) > 4 ) {
				$preview .= ' و ' . ( count( $missing ) - 4 ) . ' مورد دیگر';
			}
			return new WP_Error( 'mobo_required_shipping_method_mapping_missing', 'برای ثبت سفارش خودکار در موبو، نگاشت همه روش‌های حمل و نقل فعال WooCommerce به روش ارسال موبو اجباری است. موارد ناقص: ' . $preview );
		}

		return true;
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
		$stored = get_option( $ids_key, '' );
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
			<div class="mobo-guide-title">راهنمای رفتار تنظیمات دسته‌بندی</div>

			<div class="mobo-guide-summary">
				<div>
					<strong>آپدیت اتوماتیک دسته‌بندی‌های محصول</strong>
					<span>مشخص می‌کند پلاگین اجازه دارد دسته‌های جدید ووکامرس را از روی داده موبو بسازد یا نه. دسته‌های موجود به‌صورت پیش‌فرض بروزرسانی نمی‌شوند.</span>
				</div>
				<div>
					<strong>نگاشت دسته‌بندی</strong>
					<span>مستقل از ساخت خودکار دسته است. اگر «فعال بودن نگاشت دسته‌بندی» روشن باشد، پلاگین ابتدا دسته متناظر را از روی نگاشت‌های ذخیره‌شده پیدا می‌کند و محصول را روی همان دسته محلی ووکامرس قرار می‌دهد.</span>
				</div>
				<div>
					<strong>دسته‌بندی پیشفرض / جایگزین</strong>
					<span>اگر نگاشت دسته‌بندی فعال باشد اما برای دسته محصول، نگاشت معتبری پیدا نشود، پلاگین از دسته‌ای استفاده می‌کند که در گزینه «دسته‌بندی پیشفرض / جایگزین» انتخاب شده است. برای جلوگیری از این حالت، نگاشت همه دسته‌های موردنیاز را ذخیره کنید یا گزینه «اجباری بودن نگاشت دستی» را فعال کنید.</span>
				</div>
				<div>
					<strong>حفاظت از دسته‌های قبلی</strong>
					<span>اگر دسته‌ای قبلاً در ووکامرس وجود داشته باشد و با category_guid یا نگاشت شناسایی شود، پلاگین نام، slug، parent و metadata آن را تغییر نمی‌دهد؛ فقط ارتباط داخلی Mapping را حفظ می‌کند.</span>
				</div>
			</div>

			<div class="mobo-guide-table-wrap">
				<table class="widefat mobo-guide-table">
					<thead>
						<tr>
							<th>آپدیت اتوماتیک دسته‌بندی</th>
							<th>رفتار پلاگین</th>
							<th>پیشنهاد استفاده</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><span class="mobo-pill mobo-pill-off">خاموش</span></td>
							<td>دسته جدید ساخته نمی‌شود و دسته‌های قبلی تغییر نمی‌کنند. اگر نگاشت فعال باشد، ابتدا نگاشت دستی اعمال می‌شود؛ اگر نگاشت متناظر پیدا نشود، دسته پیشفرض / جایگزین استفاده می‌شود؛ مگر اینکه نگاشت دستی اجباری باشد.</td>
							<td>مناسب وقتی دسته‌های سایت مشتری باید کاملاً دستی کنترل شوند.</td>
						</tr>
						<tr>
							<td><span class="mobo-pill mobo-pill-on">روشن</span></td>
							<td>اگر دسته موردنیاز در ووکامرس وجود نداشته باشد ساخته می‌شود. اگر دسته موجود باشد، بروزرسانی نمی‌شود و نام، slug، parent و متادیتای آن دست‌نخورده می‌ماند.</td>
							<td>حالت پیشنهادی وقتی می‌خواهید دسته‌های جدید اضافه شوند ولی ساختار دسته‌های قبلی مشتری خراب نشود.</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="mobo-guide-warning">
				برای جلوگیری از رفتن محصولات روی دسته پیشفرض، اول دکمه «همگام‌سازی دسته‌بندی‌ها» را بزنید، بعد نگاشت‌ها را انتخاب و ذخیره کنید، سپس Sync محصول را اجرا کنید.
			</div>

			<div class="mobo-guide-flow">
				<strong>ترتیب تصمیم‌گیری هنگام Sync محصول:</strong>
				<span>۱) اگر نگاشت دستی برای دسته موبو وجود داشته باشد، همان استفاده می‌شود.</span>
				<span>۲) اگر نگاشت نباشد و دسته sync شده معتبر باشد، همان استفاده می‌شود.</span>
				<span>۳) اگر آپدیت اتوماتیک روشن باشد، فقط دسته جدید در صورت نیاز ساخته می‌شود.</span>
				<span>۴) اگر نگاشت فعال باشد اما دسته متناظر پیدا نشود، دسته پیشفرض / جایگزین استفاده می‌شود؛ اگر نگاشت دستی اجباری باشد، دسته محصول تغییر نمی‌کند و GUID گمشده ثبت می‌شود.</span>
			</div>
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
				<h2>نگاشت دسته‌بندی‌های موبو به ووکامرس</h2>
				<p>اگر دسته‌بندی‌های فروشگاه مشتری با دسته‌بندی‌های موبو فرق دارد، برای هر شناسه دسته‌بندی موبو یک دسته محلی انتخاب کنید. دکمه لود دسته‌بندی فقط این جدول را پر می‌کند و دسته جدید در ووکامرس نمی‌سازد.</p>
			</div>

			<?php if ( empty( $rows ) ) : ?>
				<div class="mobo-note">
					فعلاً دسته‌ای برای نگاشت وجود ندارد. دکمه «همگام‌سازی دسته‌بندی‌ها» را بزنید تا دسته‌ها قبل از Sync محصول لود شوند.
				</div>
			<?php else : ?>
				<div class="mobo-table-wrap">
					<table class="widefat striped">
						<thead>
							<tr>
								<th>دسته موبو</th>
								<th>شناسه دسته موبو</th>
								<th>دسته همگام‌شده</th>
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
									$remote_name = 'دسته موبو';
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
											<option value="0">بدون نگاشت دستی / استفاده از fallback</option>
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
			return $amount > 0 ? '' : 'برای قیمت‌گذاری مبلغ ثابت، هیچ سودی ثبت نشده است. قیمت محصولات بدون سود محاسبه می‌شود.';
		}

		if ( 'static-percentage' === $price_type ) {
			$percentage = (float) Mobo_Core_Settings::get( 'global_additional_percentage', 0 );
			return $percentage > 0 ? '' : 'برای قیمت‌گذاری درصدی، درصد سود ۰ است. قیمت محصولات بدون سود محاسبه می‌شود.';
		}

		if ( 'dynamic-price' === $price_type ) {
			$rows = json_decode( (string) Mobo_Core_Settings::get( 'mobo_dynamic_price', '[]' ), true );

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				return 'قیمت‌گذاری داینامیک فعال است، اما هیچ بازه‌ای برای سود تعریف نشده است.';
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

			return 'قیمت‌گذاری داینامیک فعال است، اما هیچ بازه فعال با سود بیشتر از ۰ وجود ندارد.';
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
						<input type="number" dir="ltr" style="text-align:left;" id="global_additional_price" class="mobo-money-input" name="global_additional_price" value="<?php echo esc_attr( $static_price ); ?>" min="0" step="1">
						<div class="mobo-price-preview" data-empty="مقدار وارد نشده">—</div>
						<div class="mobo-help">این مبلغ به قیمت محصول یا تنوع اضافه می‌شود.</div>
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

							<div class="mobo-price-input-wrap"><input type="number" dir="ltr" style="text-align:left;" class="mobo-money-input" name="mobo_dynamic_low[]" value="<?php echo esc_attr( $low ); ?>" min="0" step="1"><div class="mobo-price-preview" data-empty="—">—</div></div>
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
					عددها را آزادانه وارد کنید. افزونه هنگام ذخیره، بازه‌ها را بدون فاصله خالی مرتب می‌کند؛ ردیف اول از ۰ شروع می‌شود و ردیف بعدی از عدد بعد از سقف ردیف قبلی محاسبه می‌شود. سقف خالی یا ۰ در آخرین ردیف یعنی «بدون سقف».
				</div>
				<div class="mobo-message mobo-message-info" id="mobo-dynamic-range-status" style="display:none;"></div>
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
		<div class="mobo-card" id="mobo-reprice-status-card" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mobo_core_reprice_status' ) ); ?>">
			<div class="mobo-card-head">
				<h2>اعمال مجدد سیاست قیمت‌گذاری</h2>
				<p>قیمت خام دریافتی از سامانه در meta محصولات نگهداری می‌شود. با این ابزار می‌توانید پس از تغییر سیاست سود، قیمت همه محصولات و تنوع‌های همگام‌شده را بدون دریافت مجدد اطلاعات محصول محاسبه و اعمال کنید.</p>
			</div>

			<div class="mobo-progress-wrap">
				<div class="mobo-progress-meta">
					<span>پیشرفت اعمال قیمت</span>
					<strong><span data-mobo-reprice-field="percent"><?php echo esc_html( $this->format_percent( $percent ) ); ?></span>٪</strong>
				</div>
				<div class="mobo-progress"><div data-mobo-reprice-progress-bar="1" style="width: <?php echo esc_attr( min( 100, max( 0, $percent ) ) ); ?>%;"></div></div>
			</div>

			<div class="mobo-status-grid">
				<div class="mobo-status-box"><span>وضعیت</span><strong data-mobo-reprice-field="statusLabel"><?php echo esc_html( $this->reprice_status_label( $state ) ); ?></strong></div>
				<div class="mobo-status-box"><span>قابل پردازش</span><strong data-mobo-reprice-field="total"><?php echo esc_html( isset( $status['total'] ) ? absint( $status['total'] ) : 0 ); ?></strong></div>
				<div class="mobo-status-box"><span>پردازش‌شده</span><strong data-mobo-reprice-field="processed"><?php echo esc_html( isset( $status['processed'] ) ? absint( $status['processed'] ) : 0 ); ?></strong></div>
				<div class="mobo-status-box"><span>به‌روزرسانی‌شده</span><strong data-mobo-reprice-field="updated"><?php echo esc_html( isset( $status['updated'] ) ? absint( $status['updated'] ) : 0 ); ?></strong></div>
				<div class="mobo-status-box"><span>ناموفق</span><strong data-mobo-reprice-field="failed"><?php echo esc_html( isset( $status['failed'] ) ? absint( $status['failed'] ) : 0 ); ?></strong></div>
				<div class="mobo-status-box"><span>آخرین ID</span><strong data-mobo-reprice-field="lastPostId"><?php echo esc_html( isset( $status['lastPostId'] ) ? absint( $status['lastPostId'] ) : 0 ); ?></strong></div>
			</div>

			<div class="mobo-message mobo-message-info" data-mobo-reprice-message="lastMessage" style="<?php echo empty( $status['lastMessage'] ) ? 'display:none;' : ''; ?>">
				<?php echo esc_html( isset( $status['lastMessage'] ) ? $status['lastMessage'] : '' ); ?>
			</div>

			<div class="mobo-message mobo-message-error" data-mobo-reprice-message="lastError" style="<?php echo empty( $status['lastError'] ) ? 'display:none;' : ''; ?>">
				<?php echo esc_html( isset( $status['lastError'] ) ? $status['lastError'] : '' ); ?>
			</div>

			<div class="mobo-auto-refresh">
				<span data-mobo-reprice-refresh-state>به‌روزرسانی خودکار وضعیت قیمت‌گذاری فعال است.</span>
				<span data-mobo-reprice-updated-at></span>
			</div>

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
				این عملیات محصول را دوباره دریافت نمی‌کند؛ فقط از metaهای <code>mobo_api_price</code> و <code>mobo_api_compare_price</code> استفاده می‌کند. پردازش مرحله‌ای است و در پس‌زمینه ادامه پیدا می‌کند.
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
		?>
		<div class="mobo-card" id="mobo-recategorize-status-card" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mobo_core_recategorize_status' ) ); ?>">
			<div class="mobo-card-head">
				<h2>اعمال مجدد دسته‌بندی‌ها</h2>
				<p>بعد از تغییر Mapping، با این ابزار می‌توانید دسته‌بندی محصولات منتشرشده و همگام‌شده را بر اساس نگاشت فعلی دوباره اعمال کنید؛ بدون اینکه کل محصول دوباره از سامانه دریافت شود.</p>
			</div>

			<div class="mobo-progress-wrap">
				<div class="mobo-progress-meta">
					<span>پیشرفت اعمال دسته‌بندی</span>
					<strong><span data-mobo-recategorize-field="percent"><?php echo esc_html( $this->format_percent( $percent ) ); ?></span>٪</strong>
				</div>
				<div class="mobo-progress"><div data-mobo-recategorize-progress-bar="1" style="width: <?php echo esc_attr( min( 100, max( 0, $percent ) ) ); ?>%;"></div></div>
			</div>

			<div class="mobo-status-grid">
				<div class="mobo-status-box"><span>وضعیت</span><strong data-mobo-recategorize-field="statusLabel"><?php echo esc_html( $this->recategorize_status_label( $state ) ); ?></strong></div>
				<div class="mobo-status-box"><span>قابل پردازش</span><strong data-mobo-recategorize-field="total"><?php echo esc_html( isset( $status['total'] ) ? absint( $status['total'] ) : 0 ); ?></strong></div>
				<div class="mobo-status-box"><span>پردازش‌شده</span><strong data-mobo-recategorize-field="processed"><?php echo esc_html( isset( $status['processed'] ) ? absint( $status['processed'] ) : 0 ); ?></strong></div>
				<div class="mobo-status-box"><span>تغییرکرده</span><strong data-mobo-recategorize-field="updated"><?php echo esc_html( isset( $status['updated'] ) ? absint( $status['updated'] ) : 0 ); ?></strong></div>
				<div class="mobo-status-box"><span>ردشده/بدون تغییر</span><strong data-mobo-recategorize-field="skipped"><?php echo esc_html( isset( $status['skipped'] ) ? absint( $status['skipped'] ) : 0 ); ?></strong></div>
				<div class="mobo-status-box"><span>ناموفق</span><strong data-mobo-recategorize-field="failed"><?php echo esc_html( isset( $status['failed'] ) ? absint( $status['failed'] ) : 0 ); ?></strong></div>
				<div class="mobo-status-box"><span>آخرین ID</span><strong data-mobo-recategorize-field="lastPostId"><?php echo esc_html( isset( $status['lastPostId'] ) ? absint( $status['lastPostId'] ) : 0 ); ?></strong></div>
			</div>

			<div class="mobo-message mobo-message-info" data-mobo-recategorize-message="lastMessage" style="<?php echo empty( $status['lastMessage'] ) ? 'display:none;' : ''; ?>">
				<?php echo esc_html( isset( $status['lastMessage'] ) ? $status['lastMessage'] : '' ); ?>
			</div>

			<div class="mobo-message mobo-message-error" data-mobo-recategorize-message="lastError" style="<?php echo empty( $status['lastError'] ) ? 'display:none;' : ''; ?>">
				<?php echo esc_html( isset( $status['lastError'] ) ? $status['lastError'] : '' ); ?>
			</div>

			<div class="mobo-auto-refresh">
				<span data-mobo-recategorize-refresh-state>به‌روزرسانی خودکار وضعیت دسته‌بندی فعال است.</span>
				<span data-mobo-recategorize-updated-at></span>
			</div>

			<div class="mobo-actions">
				<?php if ( ! $is_running ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="mobo_core_start_recategorize">
						<?php wp_nonce_field( 'mobo_core_start_recategorize', 'mobo_core_nonce' ); ?>
						<button type="submit" class="mobo-btn mobo-btn-primary">اعمال مجدد دسته‌بندی روی محصولات منتشرشده</button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="mobo_core_cancel_recategorize">
						<?php wp_nonce_field( 'mobo_core_cancel_recategorize', 'mobo_core_nonce' ); ?>
						<button type="submit" class="mobo-btn mobo-btn-danger">توقف اعمال دسته‌بندی</button>
					</form>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mobo_core_reset_recategorize">
					<?php wp_nonce_field( 'mobo_core_reset_recategorize', 'mobo_core_nonce' ); ?>
					<button type="submit" class="mobo-btn mobo-btn-light">پاک کردن وضعیت</button>
				</form>
			</div>

			<div class="mobo-note">
				این ابزار فقط روی محصولات اصلی <strong>publish</strong> که قبلاً همگام‌سازی شده‌اند کار می‌کند. واریانت‌ها جداگانه دسته‌بندی نمی‌شوند. اگر محصولی دسته‌های منبع ذخیره‌شده نداشته باشد، پلاگین تلاش می‌کند آن را از آخرین رویداد محصول بازیابی کند؛ در غیر این صورت آن محصول رد می‌شود.
			</div>
		</div>
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
		$site_url     = (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_site_url', 'https://mobomobo.ir' );
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
					<input type="url" id="mobo_core_checkout_mobo_site_url" name="mobo_core_checkout_mobo_site_url" value="<?php echo esc_attr( $site_url ); ?>" dir="ltr" data-mobo-connection-required="1">
					<div class="mobo-help">معمولا همین مقدار است: https://mobomobo.ir</div>
				</div>
				<div class="mobo-field">
					<label for="mobo_core_checkout_mobo_username">نام کاربری موبو</label>
					<input type="text" id="mobo_core_checkout_mobo_username" name="mobo_core_checkout_mobo_username" value="<?php echo esc_attr( $username ); ?>" dir="ltr" data-mobo-connection-required="1">
				</div>
				<div class="mobo-field">
					<label for="mobo_core_checkout_mobo_password">رمز عبور موبو</label>
					<input type="text" id="mobo_core_checkout_mobo_password" name="mobo_core_checkout_mobo_password" value="" placeholder="<?php echo $has_password ? esc_attr( 'رمز قبلی ذخیره شده؛ برای تغییر مقدار جدید وارد کنید' ) : esc_attr( 'رمز عبور موبو را وارد کنید' ); ?>" dir="ltr" data-mobo-connection-required="1" data-has-secret="<?php echo $has_password ? '1' : '0'; ?>">
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

		update_option( $ids_key, $id > 0 ? (string) $id : '', false );
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
				update_option( $key, $this->sanitize_bool_value( wp_unslash( $_POST[ $key ] ) ), false );
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

			update_option( $key, $value, false );
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
			update_option( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ), false );
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
			update_option( $key, esc_url_raw( trim( wp_unslash( $_POST[ $key ] ) ) ), false );
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

		$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );

		if ( '' !== $value ) {
			update_option( $key, $value, false );
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
			update_option( 'global_additional_price', $value, false );
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

		$this->save_dynamic_price_rules();
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
	}

	/**
	 * Apply checkout-only dependent rules after checkout tab is saved.
	 *
	 * @return void
	 */
	private function apply_checkout_save_dependencies() {
		/* Hard-forced checkout safety rules; these are no longer optional UI settings. */
		update_option( 'mobo_core_checkout_validate_only_mobo_products', '1', false );
		update_option( 'mobo_core_checkout_require_remote_guid', '1', false );
		update_option( 'mobo_core_checkout_block_incomplete_sync', '1', false );

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_checkout_validation_enabled', '0' ) ) {
			update_option( 'mobo_core_checkout_local_stock_check_enabled', '0', false );
			update_option( 'mobo_core_checkout_mobo_cart_validation_enabled', '0', false );
			update_option( 'mobo_core_checkout_mobo_debug_enabled', '0', false );
			update_option( 'mobo_core_checkout_external_validation_enabled', '0', false );
			delete_option( 'mobo_core_shared_mobo_cart_lock' );
		}

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_checkout_mobo_cart_validation_enabled', '0' ) ) {
			update_option( 'mobo_core_checkout_mobo_debug_enabled', '0', false );
		}

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' ) ) {
			update_option( 'mobo_core_mobo_order_auto_complete_enabled', '0', false );
		}

		if ( Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' ) ) {
			/* Address mapping is mandatory for automatic Mobo order submission. */
			update_option( 'mobo_core_address_mapping_enabled', '1', false );
		}
	}


	/**
	 * Save SMS notification settings.
	 *
	 * @return void
	 */
	private function save_sms_notification_settings_from_post() {
		$this->save_bool_options_from_post( array( 'mobo_core_sms_notifications_enabled' ) );

		$scenarios = class_exists( 'Mobo_Core_SMS_Notifications' ) ? ( new Mobo_Core_SMS_Notifications() )->get_scenarios() : array(
			'non_mobo'  => 'سفارش غیر موبو',
			'mobo_only' => 'سفارش فقط محصولات موبو',
			'mixed'     => 'سفارش ترکیبی موبو و غیرموبو',
		);

		foreach ( array_keys( $scenarios ) as $scenario ) {
			$scenario = sanitize_key( $scenario );
			$enabled_key = 'mobo_core_sms_' . $scenario . '_enabled';
			$recipients_key = 'mobo_core_sms_' . $scenario . '_recipients';
			$template_key = 'mobo_core_sms_' . $scenario . '_template';

			$this->save_bool_options_from_post( array( $enabled_key ) );

			if ( isset( $_POST[ $recipients_key ] ) ) {
				$recipients = sanitize_textarea_field( wp_unslash( $_POST[ $recipients_key ] ) );
				update_option( $recipients_key, $recipients, false );
			}

			if ( isset( $_POST[ $template_key ] ) ) {
				$template = sanitize_textarea_field( wp_unslash( $_POST[ $template_key ] ) );
				update_option( $template_key, $template, false );
			}
		}
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
				$this->save_secret_option_from_post( 'mobo_core_token' );
				$this->save_secret_option_from_post( 'mobo_core_security_code' );
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
					)
				);
				break;

			case 'categories':
				$this->save_bool_options_from_post( array( 'global_update_categories', 'mobo_core_category_mapping_enabled', 'mobo_core_category_mapping_required' ) );
				$this->save_int_options_from_post( array( 'mobo_default_category_id' => array( 0, PHP_INT_MAX ) ) );
				$this->save_category_mapping_from_post();
				break;

			case 'pricing':
				$this->save_pricing_tab_from_post();
				break;

			case 'filters':
				if ( isset( $_POST['mobo_core_excluded_product_urls'] ) ) {
					update_option( 'mobo_core_excluded_product_urls', sanitize_textarea_field( wp_unslash( $_POST['mobo_core_excluded_product_urls'] ) ), false );
				}
				break;

			case 'queue':
				$this->save_bool_options_from_post(
					array(
						'mobo_core_product_cursor_sync_enabled',
						'mobo_core_variant_cursor_sync_enabled',
						'mobo_core_image_queue_enabled',
						'mobo_core_image_queue_blocking',
					)
				);
				$this->save_int_options_from_post(
					array(
						'mobo_core_sync_time_budget_seconds' => array( 2, 25 ),
						'mobo_core_products_per_page' => array( 1, 20 ),
						'mobo_core_variants_per_page' => array( 1, 100 ),
						'mobo_core_images_per_run' => array( 0, 10 ),
						'mobo_core_image_max_try' => array( 1, 20 ),
						'mobo_core_image_retry_base_seconds' => array( 30, 900 ),
						'mobo_core_webhook_files_per_run' => array( 1, 10 ),
						'mobo_core_webhook_max_try' => array( 1, 20 ),
						'mobo_core_webhook_expire_days' => array( 1, 30 ),
						'mobo_core_variant_parent_wait_timeout_seconds' => array( 60, 86400 ),
					)
				);
				if ( isset( $_POST['mobo_core_missing_variants_behavior'] ) ) {
					$behavior = sanitize_key( wp_unslash( $_POST['mobo_core_missing_variants_behavior'] ) );
					if ( ! in_array( $behavior, array( 'outofstock', 'ignore' ), true ) ) {
						$behavior = 'outofstock';
					}
					update_option( 'mobo_core_missing_variants_behavior', $behavior, false );
				}
				break;

			case 'image-refresh':
				$this->save_bool_options_from_post( array( 'mobo_core_image_refresh_enabled', 'mobo_core_image_refresh_delete_old', 'mobo_core_orphan_image_cleanup_enabled' ) );
				$this->save_int_options_from_post(
					array(
						'mobo_core_image_refresh_per_run' => array( 1, 20 ),
						'mobo_core_image_refresh_scan_limit' => array( 50, 5000 ),
						'mobo_core_image_refresh_max_try' => array( 1, 20 ),
						'mobo_core_image_refresh_retry_base_seconds' => array( 30, 1800 ),
						'mobo_core_orphan_image_scan_limit' => array( 50, 5000 ),
						'mobo_core_orphan_image_delete_per_run' => array( 1, 200 ),
					)
				);
				break;

			case 'cron':
				$this->save_bool_options_from_post(
					array(
						'mobo_core_self_runner_enabled',
						'mobo_core_self_runner_continue_enabled',
						'mobo_core_real_cron_process_webhooks',
						'mobo_core_process_webhook_on_receive',
						'mobo_core_pull_payload_enabled',
					)
				);
				$this->save_int_options_from_post(
					array(
						'mobo_core_real_cron_time_budget_seconds' => array( 5, 55 ),
						'mobo_core_real_cron_max_sync_steps' => array( 1, 20 ),
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
				$old_mobo_site_url = (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_site_url', 'https://mobomobo.ir' );
				$old_mobo_username = (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_username', '' );
				$this->save_url_option_from_post( 'mobo_core_checkout_mobo_site_url' );
				$this->save_text_option_from_post( 'mobo_core_checkout_mobo_username' );
				$this->save_text_option_from_post( 'mobo_core_mobo_order_sender_name' );
				$this->save_text_option_from_post( 'mobo_core_mobo_order_sender_mobile' );
				$this->save_url_option_from_post( 'mobo_core_checkout_external_validation_url' );
				if ( isset( $_POST['mobo_core_checkout_mobo_password'] ) ) {
					$mobo_password = (string) wp_unslash( $_POST['mobo_core_checkout_mobo_password'] );
					if ( '' !== $mobo_password ) {
						update_option( 'mobo_core_checkout_mobo_password', sanitize_text_field( $mobo_password ), false );
						delete_option( 'mobo_core_checkout_mobo_cookie_jar' );
					}
				}
				if ( $old_mobo_site_url !== (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_site_url', 'https://mobomobo.ir' ) || $old_mobo_username !== (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_username', '' ) ) {
					delete_option( 'mobo_core_checkout_mobo_cookie_jar' );
				}
				if ( isset( $_POST['mobo_core_checkout_external_error_behavior'] ) ) {
					$checkout_error_behavior = sanitize_key( wp_unslash( $_POST['mobo_core_checkout_external_error_behavior'] ) );
					if ( ! in_array( $checkout_error_behavior, array( 'allow', 'block' ), true ) ) {
						$checkout_error_behavior = 'allow';
					}
					update_option( 'mobo_core_checkout_external_error_behavior', $checkout_error_behavior, false );
				}
				$this->save_mobo_shipping_rules_from_post();
				if ( class_exists( 'Mobo_Core_Address_Mapping' ) ) {
					$address_mapping_for_save = new Mobo_Core_Address_Mapping();
					if ( method_exists( $address_mapping_for_save, 'save_manual_mapping_from_post' ) ) {
						$address_mapping_for_save->save_manual_mapping_from_post( $_POST );
					}
				}
				$this->apply_checkout_save_dependencies();
				break;

			case 'sms':
				$this->save_sms_notification_settings_from_post();
				break;

			case 'health':
				$this->save_bool_options_from_post( array( 'mobo_core_health_report_enabled' ) );
				$this->save_int_options_from_post(
					array(
						'mobo_core_health_report_min_interval_seconds' => array( 60, 3600 ),
						'mobo_core_health_report_timeout_seconds' => array( 5, 60 ),
					)
				);
				$this->save_url_option_from_post( 'mobo_core_health_report_url' );
				break;
		}
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized


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

				$this->redirect_with_message( 'کشور، استان و شهرها از MoboCore بروزرسانی شدند.', 'success', 'checkout' );
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

				$runner = new Mobo_Core_Cron_Runner();
				$result = $runner->run( 'admin-manual-cron-test' );
				$webhook_processed = 0;
				$webhook_failed    = 0;

				if ( is_array( $result ) && isset( $result['webhookQueue'] ) && is_array( $result['webhookQueue'] ) ) {
					$webhook_processed = isset( $result['webhookQueue']['processed'] ) ? absint( $result['webhookQueue']['processed'] ) : 0;
					$webhook_failed    = isset( $result['webhookQueue']['failed'] ) ? absint( $result['webhookQueue']['failed'] ) : 0;
				}

				$message = sprintf( 'تست Cron اجرا شد. وب‌هوک پردازش‌شده: %d، خطا: %d. جزئیات در آخرین نتیجه Cron ثبت شد.', $webhook_processed, $webhook_failed );
				$this->redirect_with_message( $message, ! empty( $result['success'] ) ? 'success' : 'error', 'cron' );
				break;

			default:
				$this->redirect_with_message( 'عملیات پشتیبانی شناخته نشد.', 'error', $tab );
				break;
		}
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

		$was_order_submission_enabled = Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' );

		$this->save_current_tab_settings( $tab );

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

			$this->redirect_with_message( 'کشور، استان و شهرها از MoboCore بروزرسانی شدند.', 'success', 'checkout' );
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

			$runner = new Mobo_Core_Cron_Runner();
			$result = $runner->run( 'admin-manual-cron-test' );
			$webhook_processed = 0;
			$webhook_failed    = 0;

			if ( is_array( $result ) && isset( $result['webhookQueue'] ) && is_array( $result['webhookQueue'] ) ) {
				$webhook_processed = isset( $result['webhookQueue']['processed'] ) ? absint( $result['webhookQueue']['processed'] ) : 0;
				$webhook_failed    = isset( $result['webhookQueue']['failed'] ) ? absint( $result['webhookQueue']['failed'] ) : 0;
			}

			$message = sprintf( 'تست Cron اجرا شد. وب‌هوک پردازش‌شده: %d، خطا: %d. جزئیات در آخرین نتیجه Cron ثبت شد.', $webhook_processed, $webhook_failed );
			$this->redirect_with_message( $message, ! empty( $result['success'] ) ? 'success' : 'error', 'cron' );
		}

		if ( 'checkout' === $tab && Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' ) ) {
			if ( class_exists( 'Mobo_Core_Address_Mapping' ) ) {
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

			$required_validation = $this->validate_mobo_order_submission_required_config();
			if ( is_wp_error( $required_validation ) ) {
				$this->redirect_with_message( $required_validation->get_error_message(), 'error', 'checkout' );
			}
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
		 * period, the authenticated admin poll advances exactly one manual-sync step.
		 * This keeps the UI moving without creating parallel workers.
		 */
		if ( ! empty( $status['shouldContinue'] ) && empty( $status['lastError'] ) ) {
			$updated_at = isset( $status['updatedAt'] ) ? absint( $status['updatedAt'] ) : 0;
			$is_stale   = $updated_at > 0 && ( time() - $updated_at ) >= 8;

			if ( $is_stale && class_exists( 'Mobo_Core_Lock' ) ) {
				$step_lock = Mobo_Core_Lock::acquire( 'manual_sync', 30 );

				if ( false !== $step_lock ) {
					try {
						$product_sync->run_manual_sync_step();
					} finally {
						Mobo_Core_Lock::release( 'manual_sync', $step_lock );
					}

					$status = $product_sync->get_manual_sync_status();
				}
			} elseif ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::kick( 'admin-status-continue', false );
			}
		}

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
			$image_poll_limit    = max( 3, Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 3, 0, 10 ) );
			$image_queue_result  = array( 'processed' => 0, 'failed' => 0, 'status' => 'skipped' );

			if ( ! empty( $image_queue_before['due'] ) ) {
				$image_queue_result = $image_sync->process_queue( $image_poll_limit );
			}

			$status['imageQueue'] = array(
				'before'   => $image_queue_before,
				'after'    => $image_sync->get_queue_status(),
				'lastRun'  => $image_queue_result,
				'pollLimit'=> $image_poll_limit,
			);
		}

		$status['statusLabel']          = $this->status_label( $status['status'] );
		$status['progressPercentLabel'] = $this->format_percent( $status['progressPercent'] );
		$status['nextRetryAtLabel']     = ! empty( $status['nextRetryAt'] ) ? wp_date( 'Y-m-d H:i:s', absint( $status['nextRetryAt'] ) ) : '—';
		$status['serverTime']           = wp_date( 'Y-m-d H:i:s' );

		wp_send_json_success( $status );
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
		$status = $queue->get_status();

		if ( ! empty( $status['shouldContinue'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'admin-reprice-status', false );
		}

		$status['statusLabel']  = $this->reprice_status_label( isset( $status['status'] ) ? $status['status'] : 'idle' );
		$status['percentLabel'] = $this->format_percent( isset( $status['percent'] ) ? (float) $status['percent'] : 0 );
		$status['serverTime']   = wp_date( 'Y-m-d H:i:s' );

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
		$status['serverTime']   = wp_date( 'Y-m-d H:i:s' );

		wp_send_json_success( $status );
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
			$message .= ' ردیف نگاشت جدید: ' . $created . '، ردیف بروزرسانی‌شده: ' . $updated . '.';
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

		$message = sprintf(
			'بررسی محصولات تکراری انجام شد. گروه‌ها: %d، انتقال به پیش‌نویس: %d، رد شده: %d',
			isset( $result['groups'] ) ? absint( $result['groups'] ) : 0,
			isset( $result['quarantined'] ) ? absint( $result['quarantined'] ) : 0,
			isset( $result['skipped'] ) ? absint( $result['skipped'] ) : 0
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

		$this->redirect_with_message( 'نوسازی تصاویر تا قبل از تکمیل Repair محصولات قفل است.', 'warning', 'image-refresh' );
		return true;
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

		$service = new Mobo_Core_Image_Refresh_Service();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_scan_limit', 500, 50, 5000 );
		$result  = $service->scan_legacy_images( $limit );

		$this->redirect_with_message(
			sprintf( 'اسکن انجام شد: %d تصویر قدیمی، %d قابل صف شدن، حجم تقریبی %s.', absint( $result['legacyRaster'] ), absint( $result['queueable'] ), $this->format_bytes( isset( $result['totalLegacyBytes'] ) ? $result['totalLegacyBytes'] : 0 ) ),
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

		$service = new Mobo_Core_Image_Refresh_Service();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_scan_limit', 500, 50, 5000 );
		$result  = $service->enqueue_legacy_images( $limit );

		$this->redirect_with_message(
			sprintf( '%d آیتم برای نوسازی تصویر وارد صف شد. skipped: %d، بدون URL: %d.', absint( $result['enqueued'] ), absint( $result['skipped'] ), absint( $result['withoutSourceUrl'] ) ),
			$result['enqueued'] > 0 ? 'success' : 'warning',
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

		$service = new Mobo_Core_Image_Refresh_Service();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_per_run', 2, 1, 20 );
		$result  = $service->process_queue( $limit );

		if ( ! empty( $result['remaining'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'image-refresh-admin', true );
		}

		$this->redirect_with_message(
			sprintf( 'پردازش صف انجام شد: موفق %d، خطا %d، skip %d.', absint( isset( $result['processed'] ) ? $result['processed'] : 0 ), absint( isset( $result['failed'] ) ? $result['failed'] : 0 ), absint( isset( $result['skipped'] ) ? $result['skipped'] : 0 ) ),
			'failed' === ( isset( $result['status'] ) ? $result['status'] : '' ) ? 'error' : 'success',
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

		$queue   = new Mobo_Core_Image_Refresh_Queue();
		$updated = $queue->retry_failed();

		$this->redirect_with_message( sprintf( '%d آیتم failed/skipped دوباره pending شد.', absint( $updated ) ), $updated > 0 ? 'success' : 'warning', 'image-refresh' );
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

		$queue   = new Mobo_Core_Image_Refresh_Queue();
		$deleted = $queue->reset( false );

		$this->redirect_with_message( sprintf( 'صف نوسازی تصویر ریست شد. ردیف‌های حذف‌شده: %d.', absint( $deleted ) ), 'success', 'image-refresh' );
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

		$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_orphan_image_scan_limit', 500, 50, 5000 );
		$result  = $cleanup->scan( $limit );

		$this->redirect_with_message(
			sprintf( 'لیست فایل های قدیمی ساخته شد: %d کاندید حذف، %d skip، حجم تقریبی %s.', absint( $result['candidateFiles'] ), absint( $result['skippedFiles'] ), $this->format_bytes( isset( $result['totalBytes'] ) ? $result['totalBytes'] : 0 ) ),
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

		$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
		$limit   = Mobo_Core_Settings::get_int( 'mobo_core_orphan_image_delete_per_run', 20, 1, 200 );
		$result  = $cleanup->delete_candidates( $limit );

		$this->redirect_with_message(
			sprintf( 'حذف کنترل شده انجام شد: حذف %d، skip %d، خطا %d، حجم آزاد شده %s.', absint( $result['deleted'] ), absint( $result['skipped'] ), absint( $result['failed'] ), $this->format_bytes( isset( $result['bytes'] ) ? $result['bytes'] : 0 ) ),
			! empty( $result['failed'] ) ? 'warning' : 'success',
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

		$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
		$deleted = $cleanup->reset( true );

		$this->redirect_with_message( sprintf( 'لیست فایل های یتیم ریست شد. ردیف های حذف شده از لیست: %d.', absint( $deleted ) ), 'success', 'image-refresh' );
	}

	// Pricing POST data is read only after handle_save_settings() verifies the admin nonce.
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

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

		$rows = $this->normalize_dynamic_price_rows( $rows );

		update_option(
			'mobo_dynamic_price',
			wp_json_encode( $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			false
		);
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
				return 'در انتظار اتصال MoboCore';
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
					var $context = context ? $(context) : $(document);
					var $selects = $context.find('.mobo-category-select2, .mobo-address-select2, .mobo-shipping-select2');

					if (! $selects.length) {
						return;
					}

					function dedupeOptions($select) {
						var seen = {};
						$select.find('option').each(function() {
							var option = this;
							var value = String(option.value || '');
							if (value === '') {
								return;
							}
							if (seen[value]) {
								if (option.selected && !seen[value].selected) {
									seen[value].selected = true;
								}
								$(option).remove();
								return;
							}
							seen[value] = option;
						});
					}

					function selectedAwareMatcher(params, data) {
						if (data && data.element) {
							var $element = $(data.element);
							var $select = $element.closest('select');
							if ($select.prop('multiple') && $element.prop('selected')) {
								return null;
							}
						}

						if ($.fn.select2 && $.fn.select2.defaults && $.fn.select2.defaults.defaults && $.fn.select2.defaults.defaults.matcher) {
							return $.fn.select2.defaults.defaults.matcher(params, data);
						}

						var term = $.trim(params.term || '').toLowerCase();
						if (term === '') {
							return data;
						}
						return String(data.text || '').toLowerCase().indexOf(term) > -1 ? data : null;
					}

					function initOne($select) {
						dedupeOptions($select);

						if ($.fn.selectWoo) {
							if ($select.data('select2') || $select.data('selectWoo')) {
								return;
							}
							$select.selectWoo({
								width: '100%',
								dir: 'rtl',
								allowClear: true,
								placeholder: $select.data('placeholder') || 'جستجو و انتخاب',
								matcher: selectedAwareMatcher
							});
							return;
						}

						if ($.fn.select2) {
							if ($select.data('select2')) {
								return;
							}
							$select.select2({
								width: '100%',
								dir: 'rtl',
								allowClear: true,
								placeholder: $select.data('placeholder') || 'جستجو و انتخاب',
								matcher: selectedAwareMatcher
							});
						}
					}

					$selects.each(function() {
						initOne($(this));
					});
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

					$preview.text(formatted + ' تومان');
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
						var currentLow = parseMoboInteger($low.val());
						var high = parseMoboInteger($high.val());
						var rowNumber = index + 1;

						if (currentLow !== null && currentLow !== nextLow) {
							warnings.push('ردیف ' + rowNumber + ': بعد از ذخیره، مقدار «از قیمت» به ' + formatMoboNumber(nextLow) + ' تغییر می‌کند.');
						}

						if (!isLast) {
							if (high === null || high < nextLow) {
								warnings.push('ردیف ' + rowNumber + ': برای محاسبه بازه بعدی، سقف این ردیف باید تکمیل شود.');
								nextLow = nextLow + 1;
							} else {
								nextLow = high + 1;
							}
					} else if (high !== null && high > 0 && high < nextLow) {
							warnings.push('ردیف آخر: سقف این ردیف از شروع بازه کمتر است و هنگام ذخیره اصلاح می‌شود.');
					}
					});

					updateAllPricePreviews($('#mobo-dynamic-rows'));

					var $status = $('#mobo-dynamic-range-status');

					if ($status.length) {
						if (warnings.length) {
							$status.html('عددها هنگام تایپ تغییر نمی‌کنند. هنگام ذخیره، افزونه بازه‌ها را بدون فاصله خالی مرتب می‌کند.<br>' + warnings.slice(0, 3).join('<br>')).show();
						} else {
							$status.text('عددها هنگام تایپ تغییر نمی‌کنند. هنگام ذخیره، بازه‌ها بدون فاصله خالی مرتب می‌شوند.').show();
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
						+ '<div class="mobo-price-input-wrap"><input type="number" dir="ltr" style="text-align:left;" class="mobo-money-input" name="mobo_dynamic_low[]" value="' + suggestedLow + '" min="0" step="1"><div class="mobo-price-preview" data-empty="—">—</div></div>'
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

				$(document).on('blur', 'input[name="mobo_dynamic_low[]"], input[name="mobo_dynamic_high[]"]', function() {
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
						$('[data-mobo-sync-updated-at]').text(status.serverTime ? 'آخرین بررسی: ' + status.serverTime : '');

						var $hero = $('[data-mobo-sync-hero-status]');
						$hero.text(status.isWaitingForPortal ? 'در انتظار اتصال MoboCore' : (status.isRunning ? (status.repairMode ? 'در حال Repair' : 'در حال همگام‌سازی') : statusLabel));
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


				function initRepriceStatusAutoRefresh() {
					var $card = $('#mobo-reprice-status-card');

					if (! $card.length) {
						return;
					}

					var ajaxUrl = $card.data('ajax-url');
					var nonce = $card.data('nonce');
					var busy = false;

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
						$('[data-mobo-reprice-field="total"]').text(status.total || 0);
						$('[data-mobo-reprice-field="processed"]').text(status.processed || 0);
						$('[data-mobo-reprice-field="updated"]').text(status.updated || 0);
						$('[data-mobo-reprice-field="failed"]').text(status.failed || 0);
						$('[data-mobo-reprice-field="lastPostId"]').text(status.lastPostId || 0);
						setRepriceMessage('lastMessage', status.lastMessage || '');
						setRepriceMessage('lastError', status.lastError || '');
						$('[data-mobo-reprice-updated-at]').text(status.serverTime ? 'آخرین بررسی: ' + status.serverTime : '');
					}

					function refreshRepriceStatus() {
						if (busy) {
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
								$('[data-mobo-reprice-refresh-state]').text('به‌روزرسانی خودکار وضعیت قیمت‌گذاری فعال است.');
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
					window.setInterval(refreshRepriceStatus, 3000);
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


				

				function initCronPresets() {
					var presets = {
						vps: { label: 'VPS', values: {
							mobo_core_real_cron_time_budget_seconds: 45,
							mobo_core_real_cron_max_sync_steps: 7,
							mobo_core_real_cron_lock_ttl_seconds: 180,
							mobo_core_real_cron_expected_interval_seconds: 60,
							mobo_core_self_runner_enabled: '1',
							mobo_core_self_runner_continue_enabled: '1',
							mobo_core_self_runner_min_interval_seconds: 1,
							mobo_core_self_runner_http_timeout_seconds: 2,
							mobo_core_real_cron_process_webhooks: '1',
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
							mobo_core_real_cron_lock_ttl_seconds: 150,
							mobo_core_real_cron_expected_interval_seconds: 60,
							mobo_core_self_runner_enabled: '1',
							mobo_core_self_runner_continue_enabled: '1',
							mobo_core_self_runner_min_interval_seconds: 2,
							mobo_core_self_runner_http_timeout_seconds: 1,
							mobo_core_real_cron_process_webhooks: '1',
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
							mobo_core_real_cron_lock_ttl_seconds: 120,
							mobo_core_real_cron_expected_interval_seconds: 60,
							mobo_core_self_runner_enabled: '1',
							mobo_core_self_runner_continue_enabled: '1',
							mobo_core_self_runner_min_interval_seconds: 5,
							mobo_core_self_runner_http_timeout_seconds: 1,
							mobo_core_real_cron_process_webhooks: '1',
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
							mobo_core_real_cron_lock_ttl_seconds: 90,
							mobo_core_real_cron_expected_interval_seconds: 120,
							mobo_core_self_runner_enabled: '1',
							mobo_core_self_runner_continue_enabled: '0',
							mobo_core_self_runner_min_interval_seconds: 10,
							mobo_core_self_runner_http_timeout_seconds: 1,
							mobo_core_real_cron_process_webhooks: '1',
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
						$('[data-mobo-cron-preset-message]').text('تنظیمات کران «' + preset.label + '» روی فرم اعمال شد. برای ثبت نهایی، دکمه ذخیره تنظیمات همین تب را بزنید.');
					});
				}

				function initQueuePresets() {
					var presets = {
						vps: {
							label: 'VPS',
							values: {
								mobo_core_sync_time_budget_seconds: 18,
								mobo_core_products_per_page: 6,
								mobo_core_product_cursor_sync_enabled: '1',
								mobo_core_variants_per_page: 35,
								mobo_core_variant_cursor_sync_enabled: '1',
								mobo_core_images_per_run: 6,
								mobo_core_image_queue_enabled: '1',
								mobo_core_image_queue_blocking: '0',
								mobo_core_image_max_try: 6,
								mobo_core_image_retry_base_seconds: 60,
								mobo_core_webhook_files_per_run: 8,
								mobo_core_webhook_max_try: 8,
								mobo_core_webhook_expire_days: 14,
								mobo_core_variant_parent_wait_timeout_seconds: 600,
								mobo_core_missing_variants_behavior: 'outofstock'
							}
						},
						strong: {
							label: 'هاست قوی',
							values: {
								mobo_core_sync_time_budget_seconds: 12,
								mobo_core_products_per_page: 4,
								mobo_core_product_cursor_sync_enabled: '1',
								mobo_core_variants_per_page: 20,
								mobo_core_variant_cursor_sync_enabled: '1',
								mobo_core_images_per_run: 4,
								mobo_core_image_queue_enabled: '1',
								mobo_core_image_queue_blocking: '0',
								mobo_core_image_max_try: 5,
								mobo_core_image_retry_base_seconds: 90,
								mobo_core_webhook_files_per_run: 5,
								mobo_core_webhook_max_try: 7,
								mobo_core_webhook_expire_days: 14,
								mobo_core_variant_parent_wait_timeout_seconds: 600,
								mobo_core_missing_variants_behavior: 'outofstock'
							}
						},
						medium: {
							label: 'هاست متوسط',
							values: {
								mobo_core_sync_time_budget_seconds: 8,
								mobo_core_products_per_page: 2,
								mobo_core_product_cursor_sync_enabled: '1',
								mobo_core_variants_per_page: 10,
								mobo_core_variant_cursor_sync_enabled: '1',
								mobo_core_images_per_run: 2,
								mobo_core_image_queue_enabled: '1',
								mobo_core_image_queue_blocking: '0',
								mobo_core_image_max_try: 5,
								mobo_core_image_retry_base_seconds: 120,
								mobo_core_webhook_files_per_run: 3,
								mobo_core_webhook_max_try: 5,
								mobo_core_webhook_expire_days: 14,
								mobo_core_variant_parent_wait_timeout_seconds: 600,
								mobo_core_missing_variants_behavior: 'outofstock'
							}
						},
						weak: {
							label: 'هاست ضعیف',
							values: {
								mobo_core_sync_time_budget_seconds: 5,
								mobo_core_products_per_page: 1,
								mobo_core_product_cursor_sync_enabled: '1',
								mobo_core_variants_per_page: 5,
								mobo_core_variant_cursor_sync_enabled: '1',
								mobo_core_images_per_run: 1,
								mobo_core_image_queue_enabled: '1',
								mobo_core_image_queue_blocking: '0',
								mobo_core_image_max_try: 3,
								mobo_core_image_retry_base_seconds: 180,
								mobo_core_webhook_files_per_run: 1,
								mobo_core_webhook_max_try: 3,
								mobo_core_webhook_expire_days: 7,
								mobo_core_variant_parent_wait_timeout_seconds: 600,
								mobo_core_missing_variants_behavior: 'outofstock'
							}
						}
					};

					$(document).off('click.moboQueuePreset', '[data-mobo-queue-preset]');
					$(document).on('click.moboQueuePreset', '[data-mobo-queue-preset]', function(event) {
						event.preventDefault();
						var $button = $(this);
						var key = String($button.data('mobo-queue-preset') || '');
						var preset = presets[key];

						if (! preset) {
							return;
						}

						$.each(preset.values, function(fieldName, value) {
							var $field = $('[name="' + fieldName + '"]');

							if (! $field.length) {
								return;
							}

							$field.val(String(value)).trigger('change');
						});

						$('[data-mobo-queue-preset]').removeClass('button-primary').addClass('button-secondary');
						$button.removeClass('button-secondary').addClass('button-primary');
						$('[data-mobo-queue-preset-message]').text('تنظیمات «' + preset.label + '» روی فرم اعمال شد. برای ثبت نهایی، دکمه ذخیره تنظیمات همین تب را بزنید.');
					});
				}


				window.MoboCoreInitSelect2 = initCategorySelect2;
				initCategorySelect2();
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
	 * Return Persian WooCommerce city dropdown status.
	 *
	 * @return array
	 */
	private function get_persian_woocommerce_status() {
		$options = get_option( 'PW_Options', array() );
		$raw = '';
		if ( is_array( $options ) && array_key_exists( 'enable_iran_cities', $options ) ) {
			$raw = $options['enable_iran_cities'];
		}

		return array(
			'activePlugins'      => $this->get_active_persian_woocommerce_plugins(),
			'pwOptionsExists'    => is_array( $options ) && ! empty( $options ),
			'iranCitiesRaw'      => is_scalar( $raw ) ? (string) $raw : '',
			'iranCitiesEnabled'  => $this->truthy_option_value( $raw ),
		);
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
	 * Interpret option values saved by third-party plugins.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function truthy_option_value( $value ) {
		if ( true === $value || 1 === $value ) {
			return true;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			return in_array( $value, array( '1', 'yes', 'on', 'true', 'enabled', 'enable' ), true );
		}

		return false;
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
		</style>
		<?php
	}
}
