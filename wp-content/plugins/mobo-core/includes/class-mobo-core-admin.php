<?php

/**
 * Admin UI.
 *
 * Persian admin interface for Mobo Core.
 * PHP 7.4 compatible.
 */

if (! defined('ABSPATH')) {
	exit;
}

class Mobo_Core_Admin
{

	/**
	 * Init admin hooks.
	 *
	 * @return void
	 */
	public function init()
	{
		add_action('admin_menu', array($this, 'menu'));
		add_action('admin_post_mobo_core_save_settings', array($this, 'save_settings'));
		add_action('admin_post_mobo_core_start_sync', array($this, 'start_sync'));
		add_action('admin_post_mobo_core_run_sync_step', array($this, 'run_sync_step'));
		add_action('admin_post_mobo_core_cancel_sync', array($this, 'cancel_sync'));
		add_action('admin_post_mobo_core_reset_sync', array($this, 'reset_sync'));
		add_action('admin_post_mobo_core_run_webhook_queue', array($this, 'run_webhook_queue'));
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function menu()
	{
		add_menu_page(
			'Mobo Core',
			'Mobo Core',
			'manage_options',
			'mobo-core',
			array($this, 'render'),
			'dashicons-update',
			56
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render()
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Access denied.', 'mobo-core'));
		}

		$sync     = new Mobo_Core_Product_Sync();
		$status   = $sync->get_manual_sync_status();
		$progress = isset($status['progressPercent']) ? (float) $status['progressPercent'] : 0;

?>
		<div class="wrap mobo-core-wrap" dir="rtl">
			<style>
				.mobo-core-wrap {
					max-width: 1280px;
					font-family: Tahoma, Arial, sans-serif;
				}

				.mobo-core-wrap * {
					box-sizing: border-box;
				}

				.mobo-hero {
					background: linear-gradient(135deg, #111827 0%, #312e81 52%, #7c3aed 100%);
					color: #fff;
					border-radius: 24px;
					padding: 30px 32px;
					margin: 20px 0 22px;
					box-shadow: 0 22px 50px rgba(49, 46, 129, 0.25);
					position: relative;
					overflow: hidden;
				}

				.mobo-hero:before {
					content: "";
					position: absolute;
					width: 180px;
					height: 180px;
					left: 38px;
					bottom: -100px;
					background: rgba(236, 72, 153, 0.25);
					border-radius: 999px;
					filter: blur(2px);
				}

				.mobo-hero:after {
					content: "";
					position: absolute;
					width: 280px;
					height: 280px;
					right: -85px;
					top: -105px;
					background: rgba(255, 255, 255, 0.13);
					border-radius: 999px;
				}

				.mobo-hero h1 {
					color: #fff;
					font-size: 30px;
					margin: 0 0 10px;
					font-weight: 900;
					letter-spacing: -0.6px;
					position: relative;
					z-index: 2;
				}

				.mobo-hero p {
					font-size: 14px;
					margin: 0;
					color: rgba(255, 255, 255, 0.84);
					position: relative;
					z-index: 2;
				}

				.mobo-grid {
					display: grid;
					grid-template-columns: repeat(12, 1fr);
					gap: 18px;
					margin-top: 18px;
				}

				.mobo-card {
					background: #fff;
					border: 1px solid #e5e7eb;
					border-radius: 20px;
					box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
					padding: 20px;
				}

				.mobo-card h2 {
					margin: 0 0 15px;
					font-size: 18px;
					font-weight: 900;
					color: #111827;
				}

				.mobo-col-12 {
					grid-column: span 12;
				}

				.mobo-col-8 {
					grid-column: span 8;
				}

				.mobo-col-6 {
					grid-column: span 6;
				}

				.mobo-col-4 {
					grid-column: span 4;
				}

				@media (max-width: 960px) {

					.mobo-col-8,
					.mobo-col-6,
					.mobo-col-4 {
						grid-column: span 12;
					}
				}

				.mobo-status-badge {
					display: inline-flex;
					align-items: center;
					gap: 8px;
					border-radius: 999px;
					padding: 8px 13px;
					font-weight: 900;
					font-size: 12px;
				}

				.mobo-status-running {
					background: #ecfeff;
					color: #0e7490;
				}

				.mobo-status-done {
					background: #ecfdf5;
					color: #047857;
				}

				.mobo-status-cancelled {
					background: #fff7ed;
					color: #c2410c;
				}

				.mobo-status-idle {
					background: #f3f4f6;
					color: #374151;
				}

				.mobo-dot {
					width: 8px;
					height: 8px;
					border-radius: 50%;
					background: currentColor;
					display: inline-block;
				}

				.mobo-progress {
					width: 100%;
					height: 15px;
					background: #eef2ff;
					border-radius: 999px;
					overflow: hidden;
					margin-top: 14px;
				}

				.mobo-progress-bar {
					height: 100%;
					background: linear-gradient(90deg, #6366f1, #8b5cf6, #ec4899);
					border-radius: 999px;
					transition: width .25s ease;
				}

				.mobo-stat-grid {
					display: grid;
					grid-template-columns: repeat(4, 1fr);
					gap: 12px;
					margin-top: 16px;
				}

				@media (max-width: 900px) {
					.mobo-stat-grid {
						grid-template-columns: repeat(2, 1fr);
					}
				}

				.mobo-stat {
					background: #f9fafb;
					border: 1px solid #eef2f7;
					border-radius: 15px;
					padding: 14px;
				}

				.mobo-stat .label {
					color: #6b7280;
					font-size: 12px;
					font-weight: 900;
					margin-bottom: 8px;
				}

				.mobo-stat .value {
					color: #111827;
					font-size: 22px;
					font-weight: 900;
					word-break: break-word;
				}

				.mobo-detail-table {
					width: 100%;
					border-collapse: collapse;
					margin-top: 13px;
				}

				.mobo-detail-table th,
				.mobo-detail-table td {
					text-align: right;
					border-bottom: 1px solid #f1f5f9;
					padding: 10px 8px;
					vertical-align: top;
				}

				.mobo-detail-table th {
					width: 210px;
					color: #6b7280;
					font-weight: 900;
				}

				.mobo-actions {
					display: flex;
					flex-wrap: wrap;
					gap: 10px;
					margin-top: 18px;
				}

				.mobo-actions form {
					margin: 0;
				}

				.mobo-btn {
					border: 0;
					border-radius: 13px;
					padding: 10px 15px;
					font-weight: 900;
					cursor: pointer;
					color: #fff;
					box-shadow: 0 8px 20px rgba(0, 0, 0, 0.10);
					transition: transform .15s ease, opacity .15s ease;
				}

				.mobo-btn:hover {
					opacity: .93;
					transform: translateY(-1px);
				}

				.mobo-btn-primary {
					background: #4f46e5;
				}

				.mobo-btn-green {
					background: #059669;
				}

				.mobo-btn-orange {
					background: #ea580c;
				}

				.mobo-btn-red {
					background: #dc2626;
				}

				.mobo-btn-gray {
					background: #4b5563;
				}

				.mobo-settings-grid {
					display: grid;
					grid-template-columns: repeat(2, minmax(0, 1fr));
					gap: 16px 22px;
				}

				@media (max-width: 960px) {
					.mobo-settings-grid {
						grid-template-columns: 1fr;
					}
				}

				.mobo-field label {
					display: block;
					font-weight: 900;
					margin-bottom: 7px;
					color: #374151;
				}

				.mobo-field input[type="text"],
				.mobo-field input[type="url"],
				.mobo-field input[type="password"],
				.mobo-field input[type="number"],
				.mobo-field select,
				.mobo-field textarea {
					width: 100%;
					max-width: 100%;
					border-radius: 12px;
					border: 1px solid #d1d5db;
					padding: 9px 11px;
					background: #fff;
				}

				.mobo-help {
					color: #6b7280;
					font-size: 12px;
					margin-top: 6px;
					line-height: 1.8;
				}

				.mobo-section-title {
					grid-column: 1 / -1;
					margin: 10px 0 0;
					padding-top: 15px;
					border-top: 1px solid #eef2f7;
					font-size: 15px;
					color: #111827;
					font-weight: 900;
				}

				.mobo-message {
					border-radius: 14px;
					padding: 12px 14px;
					background: #ecfdf5;
					color: #047857;
					border: 1px solid #a7f3d0;
					margin: 14px 0;
					font-weight: 900;
				}

				.mobo-code {
					display: block;
					background: #111827;
					color: #e5e7eb;
					border-radius: 12px;
					padding: 12px;
					overflow-x: auto;
					direction: ltr;
					text-align: left;
					font-size: 12px;
				}

				.mobo-pricing-box {
					grid-column: 1/-1;
					background: #f8fafc;
					border: 1px solid #e2e8f0;
					border-radius: 18px;
					padding: 18px;
				}

				.mobo-price-type-cards {
					display: grid;
					grid-template-columns: repeat(3, 1fr);
					gap: 12px;
					margin-top: 10px;
				}

				@media (max-width: 900px) {
					.mobo-price-type-cards {
						grid-template-columns: 1fr;
					}
				}

				.mobo-price-card {
					position: relative;
					display: block;
					border: 2px solid #e5e7eb;
					background: #fff;
					border-radius: 16px;
					padding: 16px;
					cursor: pointer;
					transition: all .18s ease;
				}

				.mobo-price-card:hover {
					border-color: #8b5cf6;
					box-shadow: 0 10px 25px rgba(139, 92, 246, .12);
				}

				.mobo-price-card input {
					position: absolute;
					opacity: 0;
					pointer-events: none;
				}

				.mobo-price-card.active {
					border-color: #4f46e5;
					background: #eef2ff;
				}

				.mobo-price-card strong {
					display: block;
					font-size: 15px;
					color: #111827;
					margin-bottom: 6px;
				}

				.mobo-price-card span {
					display: block;
					color: #6b7280;
					font-size: 12px;
					line-height: 1.8;
				}

				.mobo-pricing-panel {
					display: none;
					margin-top: 18px;
					background: #fff;
					border: 1px solid #e5e7eb;
					border-radius: 16px;
					padding: 18px;
				}

				.mobo-pricing-panel.active {
					display: block;
				}

				.mobo-pricing-panel h3 {
					margin: 0 0 12px;
					font-size: 16px;
					font-weight: 900;
					color: #111827;
				}

				.mobo-inline-fields {
					display: grid;
					grid-template-columns: repeat(2, minmax(0, 1fr));
					gap: 14px;
				}

				@media (max-width: 800px) {
					.mobo-inline-fields {
						grid-template-columns: 1fr;
					}
				}

				.mobo-dynamic-table-wrap {
					overflow-x: auto;
				}

				.mobo-dynamic-table {
					width: 100%;
					border-collapse: separate;
					border-spacing: 0 10px;
					min-width: 850px;
				}

				.mobo-dynamic-table th {
					text-align: right;
					color: #475569;
					font-weight: 900;
					font-size: 12px;
					padding: 0 8px 4px;
				}

				.mobo-dynamic-table td {
					background: #f8fafc;
					border-top: 1px solid #e2e8f0;
					border-bottom: 1px solid #e2e8f0;
					padding: 10px 8px;
				}

				.mobo-dynamic-table td:first-child {
					border-right: 1px solid #e2e8f0;
					border-radius: 0 12px 12px 0;
				}

				.mobo-dynamic-table td:last-child {
					border-left: 1px solid #e2e8f0;
					border-radius: 12px 0 0 12px;
				}

				.mobo-dynamic-table input,
				.mobo-dynamic-table select {
					width: 100%;
					border-radius: 10px !important;
				}

				.mobo-small-btn {
					border: 0;
					border-radius: 10px;
					padding: 8px 12px;
					font-weight: 900;
					cursor: pointer;
				}

				.mobo-add-row {
					background: #4f46e5;
					color: #fff;
				}

				.mobo-remove-row {
					background: #fee2e2;
					color: #b91c1c;
				}

				.mobo-pricing-note {
					background: #fffbeb;
					border: 1px solid #fde68a;
					color: #92400e;
					padding: 12px;
					border-radius: 12px;
					margin-top: 12px;
					font-size: 13px;
					line-height: 1.9;
				}
			</style>

			<div class="mobo-hero">
				<h1>هسته موبو نسخه ۲</h1>
				<p>همگام‌سازی مرحله‌ای محصولات، تنوع‌ها، دسته‌بندی‌ها، تصاویر و وب‌هوک‌ها برای ووکامرس.</p>
			</div>

			<?php if (isset($_GET['mobo_message'])) : ?>
				<div class="mobo-message">
					<?php echo esc_html(sanitize_text_field(wp_unslash($_GET['mobo_message']))); ?>
				</div>
			<?php endif; ?>

			<div class="mobo-grid">
				<div class="mobo-card mobo-col-8">
					<h2>وضعیت همگام‌سازی محصولات</h2>

					<?php echo wp_kses_post($this->status_badge($status)); ?>

					<div class="mobo-progress">
						<div class="mobo-progress-bar" style="width: <?php echo esc_attr(min(100, max(0, $progress))); ?>%;"></div>
					</div>

					<div class="mobo-stat-grid">
						<div class="mobo-stat">
							<div class="label">درصد پیشرفت</div>
							<div class="value"><?php echo esc_html($progress); ?>%</div>
						</div>
						<div class="mobo-stat">
							<div class="label">کل محصولات</div>
							<div class="value"><?php echo esc_html(absint($status['productTotalCount'])); ?></div>
						</div>
						<div class="mobo-stat">
							<div class="label">پردازش‌شده</div>
							<div class="value"><?php echo esc_html(absint($status['processedProducts'])); ?></div>
						</div>
						<div class="mobo-stat">
							<div class="label">باقی‌مانده</div>
							<div class="value"><?php echo esc_html(absint($status['remainingProducts'])); ?></div>
						</div>
					</div>

					<table class="mobo-detail-table">
						<tbody>
							<tr>
								<th>شناسه همگام‌سازی</th>
								<td><?php echo esc_html($status['syncId']); ?></td>
							</tr>
							<tr>
								<th>دسته‌بندی همگام شده؟</th>
								<td><?php echo ! empty($status['categorySynced']) ? 'بله' : 'خیر'; ?></td>
							</tr>
							<tr>
								<th>صفحه محصول</th>
								<td><?php echo esc_html(absint($status['productPage'])); ?></td>
							</tr>
							<tr>
								<th>محصولات داخل صف</th>
								<td><?php echo esc_html(absint($status['queuedProducts'])); ?></td>
							</tr>
							<tr>
								<th>محصول فعلی</th>
								<td><?php echo esc_html($status['currentProductGuid']); ?></td>
							</tr>
							<tr>
								<th>صفحه تنوع فعلی</th>
								<td><?php echo esc_html(absint($status['variantPage'])); ?></td>
							</tr>
							<tr>
								<th>صفحات تنوع محصول فعلی</th>
								<td>
									<?php
									echo esc_html(
										absint($status['currentVariantProcessedPages']) .
											' / ' .
											absint($status['currentVariantTotalPages'])
									);
									?>
								</td>
							</tr>
							<tr>
								<th>آخرین پیام</th>
								<td><?php echo esc_html($status['lastMessage']); ?></td>
							</tr>
							<?php if (! empty($status['lastError'])) : ?>
								<tr>
									<th>آخرین خطا</th>
									<td style="color:#dc2626;font-weight:900;"><?php echo esc_html($status['lastError']); ?></td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>

					<div class="mobo-actions">
						<?php $this->button_form('mobo_core_start_sync', 'شروع همگام‌سازی محصولات', 'mobo-btn mobo-btn-primary'); ?>
						<?php $this->button_form('mobo_core_cancel_sync', 'لغو همگام‌سازی', 'mobo-btn mobo-btn-red'); ?>
						<?php $this->button_form('mobo_core_reset_sync', 'ریست وضعیت', 'mobo-btn mobo-btn-gray'); ?>
					</div>
				</div>

				<div class="mobo-card mobo-col-4">
					<h2>آدرس‌های اتصال خارجی</h2>

					<p><strong>شروع همگام‌سازی</strong></p>
					<code class="mobo-code"><?php echo esc_html(rest_url('mobo-core/v1/sync/start')); ?></code>

					<p><strong>اجرای یک مرحله</strong></p>
					<code class="mobo-code"><?php echo esc_html(rest_url('mobo-core/v1/sync/run')); ?></code>

					<p><strong>وضعیت همگام‌سازی</strong></p>
					<code class="mobo-code"><?php echo esc_html(rest_url('mobo-core/v1/sync/status')); ?></code>

					<p><strong>دریافت وب‌هوک</strong></p>
					<code class="mobo-code"><?php echo esc_html(rest_url('mobo-core/v1/webhook')); ?></code>

					<p class="mobo-help">
						همه درخواست‌های خارجی باید هدر <code>X-SEC</code> داشته باشند.
					</p>
				</div>

				<div class="mobo-card mobo-col-12">
					<h2>تنظیمات</h2>

					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('mobo_core_save_settings', 'mobo_core_nonce'); ?>
						<input type="hidden" name="action" value="mobo_core_save_settings">

						<div class="mobo-settings-grid">
							<div class="mobo-section-title">اتصال و امنیت</div>

							<?php $this->text_field('کد امنیتی', 'mobo_core_security_code', 'در درخواست‌های خارجی با هدر X-SEC ارسال می‌شود.'); ?>
							<?php $this->password_field('توکن API', 'mobo_core_api_token', 'در صورت نیاز به صورت Bearer Token ارسال می‌شود.'); ?>

							<div class="mobo-section-title">قوانین بروزرسانی محصول</div>

							<?php $this->bool_field('فقط کالاهای موجود دریافت شوند', 'mobo_core_only_in_stock'); ?>
							<?php $this->bool_field('بروزرسانی خودکار موجودی', 'global_product_auto_stock'); ?>
							<?php $this->bool_field('بروزرسانی خودکار قیمت', 'global_product_auto_price'); ?>
							<?php $this->bool_field('بروزرسانی خودکار عنوان', 'global_product_auto_title'); ?>
							<?php $this->bool_field('بروزرسانی خودکار توضیح کوتاه', 'global_product_auto_caption'); ?>
							<?php $this->bool_field('بروزرسانی خودکار قیمت مقایسه‌ای', 'global_product_auto_compare_price'); ?>
							<?php $this->bool_field('بروزرسانی خودکار آدرس محصول', 'global_product_auto_slug'); ?>
							<?php $this->bool_field('بروزرسانی خودکار دسته‌بندی‌ها', 'global_update_categories'); ?>
							<?php $this->bool_field('بروزرسانی خودکار تصاویر', 'global_update_images'); ?>
							<?php $this->category_dropdown_field('دسته‌بندی پیشفرض', 'mobo_default_category_id'); ?>

							<div class="mobo-section-title">قیمت‌گذاری</div>

							<?php $this->pricing_rules_ui(); ?>

							<div class="mobo-section-title">پردازش مرحله‌ای و صف</div>

							<?php $this->int_field('بودجه زمانی هر اجرا - ثانیه', 'mobo_core_sync_time_budget_seconds', 2, 25); ?>
							<?php $this->int_field('تعداد فایل وب‌هوک در هر اجرا', 'mobo_core_webhook_files_per_run', 1, 10); ?>
							<?php $this->int_field('حداکثر تلاش برای هر وب‌هوک', 'mobo_core_webhook_max_try', 1, 20); ?>
							<?php $this->int_field('انقضای وب‌هوک - روز', 'mobo_core_webhook_expire_days', 1, 30); ?>
							<?php $this->int_field('تعداد محصول در هر صفحه', 'mobo_core_products_per_page', 1, 20); ?>
							<?php $this->int_field('تعداد تنوع در هر صفحه', 'mobo_core_variants_per_page', 1, 100); ?>
							<?php $this->int_field('تعداد تصویر در هر اجرا', 'mobo_core_images_per_run', 0, 10); ?>
							<?php $this->missing_variants_field(); ?>
						</div>

						<p style="margin-top:22px;">
							<button type="submit" class="mobo-btn mobo-btn-primary">
								ذخیره تنظیمات
							</button>
						</p>
					</form>
				</div>
			</div>
		</div>
	<?php
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public function save_settings()
	{
		$this->require_admin_and_nonce('mobo_core_save_settings');
		Mobo_Core_Settings::save_from_post($_POST);
		$this->redirect('تنظیمات ذخیره شد.');
	}

	/**
	 * Start sync.
	 *
	 * @return void
	 */
	public function start_sync()
	{
		$this->require_admin_and_nonce('mobo_core_start_sync');

		$sync   = new Mobo_Core_Product_Sync();
		$result = $sync->start_manual_sync('', 'admin');

		$this->redirect($result['message']);
	}

	/**
	 * Cancel sync.
	 *
	 * @return void
	 */
	public function cancel_sync()
	{
		$this->require_admin_and_nonce('mobo_core_cancel_sync');

		$sync   = new Mobo_Core_Product_Sync();
		$result = $sync->cancel_manual_sync();

		$this->redirect($result['message']);
	}

	/**
	 * Reset sync state.
	 *
	 * @return void
	 */
	public function reset_sync()
	{
		$this->require_admin_and_nonce('mobo_core_reset_sync');

		$sync = new Mobo_Core_Product_Sync();
		$sync->reset_manual_sync_state();

		$this->redirect('وضعیت همگام‌سازی ریست شد.');
	}

	/**
	 * Require admin capability and nonce.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function require_admin_and_nonce($action)
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Access denied.', 'mobo-core'));
		}

		check_admin_referer($action, 'mobo_core_nonce');
	}

	/**
	 * Redirect back to plugin page.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function redirect($message)
	{
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'mobo-core',
					'mobo_message' => rawurlencode(sanitize_text_field($message)),
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * Render status badge.
	 *
	 * @param array $status Status.
	 * @return string
	 */
	private function status_badge($status)
	{
		$current = isset($status['status']) ? sanitize_key($status['status']) : 'idle';

		$class = 'mobo-status-idle';
		$text  = 'آماده';

		if ('running' === $current) {
			$class = 'mobo-status-running';
			$text  = 'در حال اجرا';
		} elseif ('done' === $current) {
			$class = 'mobo-status-done';
			$text  = 'تکمیل شده';
		} elseif ('cancelled' === $current) {
			$class = 'mobo-status-cancelled';
			$text  = 'لغو شده';
		}

		return '<span class="mobo-status-badge ' . esc_attr($class) . '"><span class="mobo-dot"></span>' . esc_html($text) . '</span>';
	}

	/**
	 * Render Persian pricing rules UI.
	 *
	 * @return void
	 */
	private function pricing_rules_ui()
	{
		$price_type = Mobo_Core_Settings::get('mobo_price_type', 'static-price');

		if (! in_array($price_type, array('static-price', 'static-percentage', 'dynamic-price'), true)) {
			$price_type = 'static-price';
		}

		$dynamic_rows = json_decode((string) Mobo_Core_Settings::get('mobo_dynamic_price', '[]'), true);

		if (! is_array($dynamic_rows)) {
			$dynamic_rows = array();
		}

		if (empty($dynamic_rows)) {
			$dynamic_rows = array(
				array(
					'is_active'    => 'true',
					'low'          => '',
					'high'         => '',
					'benefit_type' => 'static',
					'benefit'      => '',
				),
			);
		}

	?>
		<div class="mobo-field mobo-pricing-box">
			<label style="font-size:16px;font-weight:900;color:#111827;">
				نوع سود و قیمت‌گذاری
			</label>

			<div class="mobo-price-type-cards" id="mobo-price-type-cards">
				<label class="mobo-price-card <?php echo 'static-price' === $price_type ? 'active' : ''; ?>" data-price-type="static-price">
					<input type="radio" name="mobo_price_type" value="static-price" <?php checked($price_type, 'static-price'); ?>>
					<strong>سود ثابت</strong>
					<span>یک مبلغ ثابت به قیمت محصول یا تنوع اضافه می‌شود.</span>
				</label>

				<label class="mobo-price-card <?php echo 'static-percentage' === $price_type ? 'active' : ''; ?>" data-price-type="static-percentage">
					<input type="radio" name="mobo_price_type" value="static-percentage" <?php checked($price_type, 'static-percentage'); ?>>
					<strong>سود درصدی</strong>
					<span>قیمت با ضریب درصدی محاسبه می‌شود. مثلاً ۲۰ یعنی ضریب ۱.۲۰.</span>
				</label>

				<label class="mobo-price-card <?php echo 'dynamic-price' === $price_type ? 'active' : ''; ?>" data-price-type="dynamic-price">
					<input type="radio" name="mobo_price_type" value="dynamic-price" <?php checked($price_type, 'dynamic-price'); ?>>
					<strong>سود داینامیک</strong>
					<span>بر اساس بازه قیمت، سود ثابت یا درصدی متفاوت اعمال می‌شود.</span>
				</label>
			</div>

			<div id="mobo-panel-static-price" class="mobo-pricing-panel <?php echo 'static-price' === $price_type ? 'active' : ''; ?>">
				<h3>تنظیم سود ثابت</h3>
				<div class="mobo-inline-fields">
					<div class="mobo-field">
						<label for="global_additional_price">مبلغ سود ثابت</label>
						<input type="number" min="0" step="1" id="global_additional_price" name="global_additional_price" value="<?php echo esc_attr(Mobo_Core_Settings::get('global_additional_price', '0')); ?>">
						<div class="mobo-help">این مبلغ به قیمت اصلی اضافه می‌شود.</div>
					</div>
				</div>
			</div>

			<div id="mobo-panel-static-percentage" class="mobo-pricing-panel <?php echo 'static-percentage' === $price_type ? 'active' : ''; ?>">
				<h3>تنظیم سود درصدی</h3>
				<div class="mobo-inline-fields">
					<div class="mobo-field">
						<label for="global_additional_percentage">درصد سود</label>
						<input type="number" min="0" step="1" id="global_additional_percentage" name="global_additional_percentage" value="<?php echo esc_attr(Mobo_Core_Settings::get('global_additional_percentage', '0')); ?>">
						<div class="mobo-help">مثلاً مقدار ۲۰ یعنی قیمت در ۱.۲۰ ضرب می‌شود.</div>
					</div>
				</div>
			</div>

			<div id="mobo-panel-dynamic-price" class="mobo-pricing-panel <?php echo 'dynamic-price' === $price_type ? 'active' : ''; ?>">
				<h3>تنظیم سود داینامیک</h3>

				<div class="mobo-dynamic-table-wrap">
					<table class="mobo-dynamic-table" id="mobo-dynamic-price-table">
						<thead>
							<tr>
								<th>فعال</th>
								<th>از قیمت</th>
								<th>تا قیمت</th>
								<th>نوع سود</th>
								<th>مقدار سود</th>
								<th>عملیات</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($dynamic_rows as $index => $row) : ?>
								<?php
								$is_active    = isset($row['is_active']) ? (string) $row['is_active'] : 'true';
								$low          = isset($row['low']) ? (string) $row['low'] : '';
								$high         = isset($row['high']) ? (string) $row['high'] : '';
								$benefit_type = isset($row['benefit_type']) ? (string) $row['benefit_type'] : 'static';
								$benefit      = isset($row['benefit']) ? (string) $row['benefit'] : '';
								?>
								<tr>
									<td>
										<select name="mobo_dynamic_price_rows[<?php echo esc_attr($index); ?>][is_active]">
											<option value="true" <?php selected($is_active, 'true'); ?>>بله</option>
											<option value="false" <?php selected($is_active, 'false'); ?>>خیر</option>
										</select>
									</td>
									<td>
										<input type="number" min="0" step="1" name="mobo_dynamic_price_rows[<?php echo esc_attr($index); ?>][low]" value="<?php echo esc_attr($low); ?>">
									</td>
									<td>
										<input type="number" min="0" step="1" name="mobo_dynamic_price_rows[<?php echo esc_attr($index); ?>][high]" value="<?php echo esc_attr($high); ?>">
									</td>
									<td>
										<select name="mobo_dynamic_price_rows[<?php echo esc_attr($index); ?>][benefit_type]">
											<option value="static" <?php selected($benefit_type, 'static'); ?>>مبلغ ثابت</option>
											<option value="percentage" <?php selected($benefit_type, 'percentage'); ?>>درصدی</option>
										</select>
									</td>
									<td>
										<input type="number" min="0" step="1" name="mobo_dynamic_price_rows[<?php echo esc_attr($index); ?>][benefit]" value="<?php echo esc_attr($benefit); ?>">
									</td>
									<td>
										<button type="button" class="mobo-small-btn mobo-remove-row">حذف</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<button type="button" class="mobo-small-btn mobo-add-row" id="mobo-add-dynamic-row">افزودن بازه جدید</button>

				<div class="mobo-pricing-note">
					در حالت داینامیک، اولین بازه فعالی که قیمت محصول داخل آن قرار بگیرد اعمال می‌شود.
					اگر نوع سود «درصدی» باشد، مقدار ۲۰ یعنی قیمت در ۱.۲۰ ضرب می‌شود.
				</div>
			</div>

			<script>
				jQuery(function($) {
					function refreshPricePanels() {
						var selected = $('input[name="mobo_price_type"]:checked').val();

						$('.mobo-price-card').removeClass('active');
						$('.mobo-price-card[data-price-type="' + selected + '"]').addClass('active');

						$('.mobo-pricing-panel').removeClass('active');
						$('#mobo-panel-' + selected).addClass('active');
					}

					$(document).on('change', 'input[name="mobo_price_type"]', refreshPricePanels);

					$('.mobo-price-card').on('click', function() {
						$(this).find('input[type="radio"]').prop('checked', true).trigger('change');
					});

					$('#mobo-add-dynamic-row').on('click', function() {
						var $tbody = $('#mobo-dynamic-price-table tbody');
						var index = $tbody.find('tr').length;

						var row = '' +
							'<tr>' +
							'<td><select name="mobo_dynamic_price_rows[' + index + '][is_active]"><option value="true">بله</option><option value="false">خیر</option></select></td>' +
							'<td><input type="number" min="0" step="1" name="mobo_dynamic_price_rows[' + index + '][low]" value=""></td>' +
							'<td><input type="number" min="0" step="1" name="mobo_dynamic_price_rows[' + index + '][high]" value=""></td>' +
							'<td><select name="mobo_dynamic_price_rows[' + index + '][benefit_type]"><option value="static">مبلغ ثابت</option><option value="percentage">درصدی</option></select></td>' +
							'<td><input type="number" min="0" step="1" name="mobo_dynamic_price_rows[' + index + '][benefit]" value=""></td>' +
							'<td><button type="button" class="mobo-small-btn mobo-remove-row">حذف</button></td>' +
							'</tr>';

						$tbody.append(row);
					});

					$(document).on('click', '.mobo-remove-row', function() {
						var $tbody = $('#mobo-dynamic-price-table tbody');

						if ($tbody.find('tr').length <= 1) {
							$(this).closest('tr').find('input').val('');
							$(this).closest('tr').find('select').prop('selectedIndex', 0);
							return;
						}

						$(this).closest('tr').remove();
					});

					refreshPricePanels();
				});
			</script>
		</div>
	<?php
	}

	private function text_field($label, $key, $help = '')
	{
	?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
			<input type="text" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(Mobo_Core_Settings::get($key, '')); ?>">
			<?php if ('' !== $help) : ?>
				<div class="mobo-help"><?php echo esc_html($help); ?></div>
			<?php endif; ?>
		</div>
	<?php
	}

	private function password_field($label, $key, $help = '')
	{
	?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
			<input type="password" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(Mobo_Core_Settings::get($key, '')); ?>">
			<?php if ('' !== $help) : ?>
				<div class="mobo-help"><?php echo esc_html($help); ?></div>
			<?php endif; ?>
		</div>
	<?php
	}

	private function bool_field($label, $key)
	{
	?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
			<select id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>">
				<option value="0" <?php selected(Mobo_Core_Settings::get($key, '0'), '0'); ?>>خیر</option>
				<option value="1" <?php selected(Mobo_Core_Settings::get($key, '0'), '1'); ?>>بله</option>
			</select>
		</div>
	<?php
	}

	private function int_field($label, $key, $min, $max)
	{
	?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
			<input type="number" min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(Mobo_Core_Settings::get_int($key, 1, $min, $max)); ?>">
		</div>
	<?php
	}

	private function category_dropdown_field($label, $key)
	{
		$selected = absint(Mobo_Core_Settings::get($key, 0));

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

	?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
			<select id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>">
				<option value="0">انتخاب نشده</option>
				<?php if (! is_wp_error($terms) && is_array($terms)) : ?>
					<?php foreach ($terms as $term) : ?>
						<option value="<?php echo esc_attr(absint($term->term_id)); ?>" <?php selected($selected, absint($term->term_id)); ?>>
							<?php echo esc_html($term->name); ?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>
			<div class="mobo-help">وقتی بروزرسانی خودکار دسته‌بندی خاموش باشد، محصول به این دسته وصل می‌شود.</div>
		</div>
	<?php
	}

	private function missing_variants_field()
	{
		$value = Mobo_Core_Settings::get('mobo_core_missing_variants_behavior', 'outofstock');

	?>
		<div class="mobo-field">
			<label for="mobo_core_missing_variants_behavior">رفتار با تنوع‌های حذف‌شده</label>
			<select id="mobo_core_missing_variants_behavior" name="mobo_core_missing_variants_behavior">
				<option value="outofstock" <?php selected($value, 'outofstock'); ?>>ناموجود شوند</option>
				<option value="ignore" <?php selected($value, 'ignore'); ?>>نادیده گرفته شوند</option>
			</select>
		</div>
	<?php
	}

	private function button_form($action, $label, $class)
	{
	?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<?php wp_nonce_field($action, 'mobo_core_nonce'); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
			<button type="submit" class="<?php echo esc_attr($class); ?>">
				<?php echo esc_html($label); ?>
			</button>
		</form>
<?php
	}
}
