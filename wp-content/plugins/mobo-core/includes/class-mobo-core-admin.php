<?php
/**
 * Admin UI.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Admin {

	/**
	 * Init admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_mobo_core_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_mobo_core_start_sync', array( $this, 'start_sync' ) );
		add_action( 'admin_post_mobo_core_run_sync_step', array( $this, 'run_sync_step' ) );
		add_action( 'admin_post_mobo_core_cancel_sync', array( $this, 'cancel_sync' ) );
		add_action( 'admin_post_mobo_core_reset_sync', array( $this, 'reset_sync' ) );
		add_action( 'admin_post_mobo_core_run_webhook_queue', array( $this, 'run_webhook_queue' ) );
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			'Mobo Core',
			'Mobo Core',
			'manage_options',
			'mobo-core',
			array( $this, 'render' ),
			'dashicons-update',
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
			wp_die( esc_html__( 'Access denied.', 'mobo-core' ) );
		}

		$sync        = new Mobo_Core_Product_Sync();
		$status      = $sync->get_manual_sync_status();
		$is_running  = ! empty( $status['isRunning'] );
		$is_done     = ! empty( $status['isDone'] );
		$is_cancelled = ! empty( $status['isCancelled'] );
		$progress    = isset( $status['progressPercent'] ) ? (float) $status['progressPercent'] : 0;

		?>
		<div class="wrap mobo-core-wrap">
			<style>
				.mobo-core-wrap {
					max-width: 1280px;
				}

				.mobo-hero {
					background: linear-gradient(135deg, #111827 0%, #312e81 55%, #7c3aed 100%);
					color: #fff;
					border-radius: 22px;
					padding: 28px 30px;
					margin: 20px 0 22px;
					box-shadow: 0 20px 45px rgba(49, 46, 129, 0.25);
					position: relative;
					overflow: hidden;
				}

				.mobo-hero:after {
					content: "";
					position: absolute;
					width: 260px;
					height: 260px;
					right: -70px;
					top: -90px;
					background: rgba(255,255,255,0.12);
					border-radius: 999px;
				}

				.mobo-hero h1 {
					color: #fff;
					font-size: 30px;
					margin: 0 0 8px;
					font-weight: 800;
					letter-spacing: -0.4px;
				}

				.mobo-hero p {
					font-size: 14px;
					margin: 0;
					color: rgba(255,255,255,0.82);
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
					border-radius: 18px;
					box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
					padding: 20px;
				}

				.mobo-card h2 {
					margin: 0 0 15px;
					font-size: 18px;
					font-weight: 800;
					color: #111827;
				}

				.mobo-card h3 {
					margin: 18px 0 10px;
					font-size: 15px;
					font-weight: 800;
					color: #374151;
				}

				.mobo-col-12 { grid-column: span 12; }
				.mobo-col-8 { grid-column: span 8; }
				.mobo-col-6 { grid-column: span 6; }
				.mobo-col-4 { grid-column: span 4; }

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
					gap: 7px;
					border-radius: 999px;
					padding: 7px 12px;
					font-weight: 700;
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
					height: 14px;
					background: #eef2ff;
					border-radius: 999px;
					overflow: hidden;
					margin-top: 12px;
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
					border-radius: 14px;
					padding: 14px;
				}

				.mobo-stat .label {
					color: #6b7280;
					font-size: 12px;
					font-weight: 700;
					margin-bottom: 7px;
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
					margin-top: 12px;
				}

				.mobo-detail-table th,
				.mobo-detail-table td {
					text-align: left;
					border-bottom: 1px solid #f1f5f9;
					padding: 10px 8px;
					vertical-align: top;
				}

				.mobo-detail-table th {
					width: 210px;
					color: #6b7280;
					font-weight: 800;
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
					border-radius: 12px;
					padding: 10px 14px;
					font-weight: 800;
					cursor: pointer;
					color: #fff;
					box-shadow: 0 8px 20px rgba(0,0,0,0.10);
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

				.mobo-btn:hover {
					opacity: .92;
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
					font-weight: 800;
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

				.mobo-field textarea {
					min-height: 110px;
					font-family: Consolas, Monaco, monospace;
					direction: ltr;
				}

				.mobo-help {
					color: #6b7280;
					font-size: 12px;
					margin-top: 6px;
				}

				.mobo-section-title {
					grid-column: 1 / -1;
					margin: 10px 0 0;
					padding-top: 15px;
					border-top: 1px solid #eef2f7;
					font-size: 14px;
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
					font-weight: 700;
				}

				.mobo-code {
					display: block;
					background: #111827;
					color: #e5e7eb;
					border-radius: 12px;
					padding: 12px;
					overflow-x: auto;
					direction: ltr;
					font-size: 12px;
				}
			</style>

			<div class="mobo-hero">
				<h1><?php echo esc_html__( 'Mobo Core v2', 'mobo-core' ); ?></h1>
				<p><?php echo esc_html__( 'Chunked product sync, file-based webhook queue, and production-safe WooCommerce updates.', 'mobo-core' ); ?></p>
			</div>

			<?php if ( isset( $_GET['mobo_message'] ) ) : ?>
				<div class="mobo-message">
					<?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['mobo_message'] ) ) ); ?>
				</div>
			<?php endif; ?>

			<div class="mobo-grid">
				<div class="mobo-card mobo-col-8">
					<h2><?php echo esc_html__( 'Product Sync Status', 'mobo-core' ); ?></h2>

					<?php echo wp_kses_post( $this->status_badge( $status ) ); ?>

					<div class="mobo-progress">
						<div class="mobo-progress-bar" style="width: <?php echo esc_attr( min( 100, max( 0, $progress ) ) ); ?>%;"></div>
					</div>

					<div class="mobo-stat-grid">
						<div class="mobo-stat">
							<div class="label"><?php echo esc_html__( 'Progress', 'mobo-core' ); ?></div>
							<div class="value"><?php echo esc_html( $progress ); ?>%</div>
						</div>
						<div class="mobo-stat">
							<div class="label"><?php echo esc_html__( 'Total Products', 'mobo-core' ); ?></div>
							<div class="value"><?php echo esc_html( absint( $status['productTotalCount'] ) ); ?></div>
						</div>
						<div class="mobo-stat">
							<div class="label"><?php echo esc_html__( 'Processed', 'mobo-core' ); ?></div>
							<div class="value"><?php echo esc_html( absint( $status['processedProducts'] ) ); ?></div>
						</div>
						<div class="mobo-stat">
							<div class="label"><?php echo esc_html__( 'Remaining', 'mobo-core' ); ?></div>
							<div class="value"><?php echo esc_html( absint( $status['remainingProducts'] ) ); ?></div>
						</div>
					</div>

					<table class="mobo-detail-table">
						<tbody>
							<tr>
								<th><?php echo esc_html__( 'Sync ID', 'mobo-core' ); ?></th>
								<td><?php echo esc_html( $status['syncId'] ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Category Synced', 'mobo-core' ); ?></th>
								<td><?php echo ! empty( $status['categorySynced'] ) ? esc_html__( 'Yes', 'mobo-core' ) : esc_html__( 'No', 'mobo-core' ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Product Page', 'mobo-core' ); ?></th>
								<td><?php echo esc_html( absint( $status['productPage'] ) ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Queued Products', 'mobo-core' ); ?></th>
								<td><?php echo esc_html( absint( $status['queuedProducts'] ) ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Current Product', 'mobo-core' ); ?></th>
								<td><?php echo esc_html( $status['currentProductGuid'] ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Variant Page', 'mobo-core' ); ?></th>
								<td><?php echo esc_html( absint( $status['variantPage'] ) ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Current Variant Pages', 'mobo-core' ); ?></th>
								<td>
									<?php
									echo esc_html(
										absint( $status['currentVariantProcessedPages'] ) .
										' / ' .
										absint( $status['currentVariantTotalPages'] )
									);
									?>
								</td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Last Message', 'mobo-core' ); ?></th>
								<td><?php echo esc_html( $status['lastMessage'] ); ?></td>
							</tr>
							<?php if ( ! empty( $status['lastError'] ) ) : ?>
								<tr>
									<th><?php echo esc_html__( 'Last Error', 'mobo-core' ); ?></th>
									<td style="color:#dc2626;font-weight:700;"><?php echo esc_html( $status['lastError'] ); ?></td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>

					<div class="mobo-actions">
						<?php $this->button_form( 'mobo_core_start_sync', 'Start Product Sync', 'mobo-btn mobo-btn-primary' ); ?>
						<?php $this->button_form( 'mobo_core_run_sync_step', 'Run One Step', 'mobo-btn mobo-btn-green' ); ?>
						<?php $this->button_form( 'mobo_core_cancel_sync', 'Cancel Sync', 'mobo-btn mobo-btn-red' ); ?>
						<?php $this->button_form( 'mobo_core_reset_sync', 'Reset State', 'mobo-btn mobo-btn-gray' ); ?>
						<?php $this->button_form( 'mobo_core_run_webhook_queue', 'Run Webhook Queue', 'mobo-btn mobo-btn-orange' ); ?>
					</div>
				</div>

				<div class="mobo-card mobo-col-4">
					<h2><?php echo esc_html__( 'External Endpoints', 'mobo-core' ); ?></h2>

					<p><strong>Start Sync</strong></p>
					<code class="mobo-code"><?php echo esc_html( rest_url( 'mobo-core/v1/sync/start' ) ); ?></code>

					<p><strong>Run Sync Step</strong></p>
					<code class="mobo-code"><?php echo esc_html( rest_url( 'mobo-core/v1/sync/run' ) ); ?></code>

					<p><strong>Sync Status</strong></p>
					<code class="mobo-code"><?php echo esc_html( rest_url( 'mobo-core/v1/sync/status' ) ); ?></code>

					<p><strong>Webhook Receive</strong></p>
					<code class="mobo-code"><?php echo esc_html( rest_url( 'mobo-core/v1/webhook' ) ); ?></code>

					<p class="mobo-help">
						<?php echo esc_html__( 'All external requests must include X-SEC header.', 'mobo-core' ); ?>
					</p>
				</div>

				<div class="mobo-card mobo-col-12">
					<h2><?php echo esc_html__( 'Settings', 'mobo-core' ); ?></h2>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>
						<input type="hidden" name="action" value="mobo_core_save_settings">

						<div class="mobo-settings-grid">
							<div class="mobo-section-title"><?php echo esc_html__( 'Connection', 'mobo-core' ); ?></div>

							<?php $this->text_field( 'Security Code', 'mobo_core_security_code', 'Used in REST header: X-SEC' ); ?>
							<?php $this->url_field( 'API Base URL', 'mobo_core_api_base_url', 'Example: https://localhost:7015/' ); ?>
							<?php $this->password_field( 'API Token', 'mobo_core_api_token', 'Optional Bearer token.' ); ?>

							<div class="mobo-section-title"><?php echo esc_html__( 'Legacy Update Rules', 'mobo-core' ); ?></div>

							<?php $this->bool_field( 'Only In Stock', 'mobo_core_only_in_stock' ); ?>
							<?php $this->bool_field( 'Auto Update Stock', 'global_product_auto_stock' ); ?>
							<?php $this->bool_field( 'Auto Update Price', 'global_product_auto_price' ); ?>
							<?php $this->bool_field( 'Auto Update Title', 'global_product_auto_title' ); ?>
							<?php $this->bool_field( 'Auto Update Caption', 'global_product_auto_caption' ); ?>
							<?php $this->bool_field( 'Auto Update Compare Price', 'global_product_auto_compare_price' ); ?>
							<?php $this->bool_field( 'Auto Update Slug', 'global_product_auto_slug' ); ?>
							<?php $this->bool_field( 'Update Categories Automatically', 'global_update_categories' ); ?>
							<?php $this->bool_field( 'Update Images Automatically', 'global_update_images' ); ?>
							<?php $this->category_dropdown_field( 'Default Category', 'mobo_default_category_id' ); ?>

							<div class="mobo-section-title"><?php echo esc_html__( 'Pricing', 'mobo-core' ); ?></div>

							<?php $this->price_type_field(); ?>
							<?php $this->number_field( 'Additional Price', 'global_additional_price', 0, 999999999, '1' ); ?>
							<?php $this->number_field( 'Additional Percentage', 'global_additional_percentage', 0, 1000, '1' ); ?>
							<?php $this->textarea_field( 'Dynamic Price JSON', 'mobo_dynamic_price', 'Used when mobo_price_type = dynamic-price.' ); ?>

							<div class="mobo-section-title"><?php echo esc_html__( 'Chunking & Queue', 'mobo-core' ); ?></div>

							<?php $this->wp_cron_field(); ?>
							<?php $this->int_field( 'Time Budget Seconds', 'mobo_core_sync_time_budget_seconds', 2, 25 ); ?>
							<?php $this->int_field( 'Webhook Files Per Run', 'mobo_core_webhook_files_per_run', 1, 10 ); ?>
							<?php $this->int_field( 'Webhook Max Try', 'mobo_core_webhook_max_try', 1, 20 ); ?>
							<?php $this->int_field( 'Webhook Expire Days', 'mobo_core_webhook_expire_days', 1, 30 ); ?>
							<?php $this->int_field( 'Products Per Page', 'mobo_core_products_per_page', 1, 20 ); ?>
							<?php $this->int_field( 'Variants Per Page', 'mobo_core_variants_per_page', 1, 100 ); ?>
							<?php $this->int_field( 'Images Per Run', 'mobo_core_images_per_run', 0, 10 ); ?>
							<?php $this->missing_variants_field(); ?>
						</div>

						<p style="margin-top:22px;">
							<button type="submit" class="mobo-btn mobo-btn-primary">
								<?php echo esc_html__( 'Save Settings', 'mobo-core' ); ?>
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
	public function save_settings() {
		$this->require_admin_and_nonce( 'mobo_core_save_settings' );
		Mobo_Core_Settings::save_from_post( $_POST );
		$this->redirect( 'Settings saved.' );
	}

	/**
	 * Start sync.
	 *
	 * @return void
	 */
	public function start_sync() {
		$this->require_admin_and_nonce( 'mobo_core_start_sync' );

		$sync   = new Mobo_Core_Product_Sync();
		$result = $sync->start_manual_sync( '', 'admin' );

		$this->redirect( $result['message'] );
	}

	/**
	 * Run sync step.
	 *
	 * @return void
	 */
	public function run_sync_step() {
		$this->require_admin_and_nonce( 'mobo_core_run_sync_step' );

		$lock = Mobo_Core_Lock::acquire( 'manual_sync', 30 );

		if ( false === $lock ) {
			$this->redirect( 'Product sync is locked.' );
		}

		try {
			$sync   = new Mobo_Core_Product_Sync();
			$result = $sync->run_manual_sync_step();
		} finally {
			Mobo_Core_Lock::release( 'manual_sync', $lock );
		}

		$this->redirect( $result['message'] );
	}

	/**
	 * Cancel sync.
	 *
	 * @return void
	 */
	public function cancel_sync() {
		$this->require_admin_and_nonce( 'mobo_core_cancel_sync' );

		$sync   = new Mobo_Core_Product_Sync();
		$result = $sync->cancel_manual_sync();

		$this->redirect( $result['message'] );
	}

	/**
	 * Reset sync state.
	 *
	 * @return void
	 */
	public function reset_sync() {
		$this->require_admin_and_nonce( 'mobo_core_reset_sync' );

		$sync = new Mobo_Core_Product_Sync();
		$sync->reset_manual_sync_state();

		$this->redirect( 'Sync state reset.' );
	}

	/**
	 * Run webhook queue.
	 *
	 * @return void
	 */
	public function run_webhook_queue() {
		$this->require_admin_and_nonce( 'mobo_core_run_webhook_queue' );

		$queue  = new Mobo_Core_Webhook_Queue();
		$result = $queue->process();

		$this->redirect( ! empty( $result['messages'][0] ) ? $result['messages'][0] : 'Webhook queue processed.' );
	}

	/**
	 * Require admin capability and nonce.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function require_admin_and_nonce( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'mobo-core' ) );
		}

		check_admin_referer( $action, 'mobo_core_nonce' );
	}

	/**
	 * Redirect back to plugin page.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function redirect( $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'mobo-core',
					'mobo_message' => rawurlencode( sanitize_text_field( $message ) ),
				),
				admin_url( 'admin.php' )
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
	private function status_badge( $status ) {
		$current = isset( $status['status'] ) ? sanitize_key( $status['status'] ) : 'idle';

		$class = 'mobo-status-idle';
		$text  = 'Idle';

		if ( 'running' === $current ) {
			$class = 'mobo-status-running';
			$text  = 'Running';
		} elseif ( 'done' === $current ) {
			$class = 'mobo-status-done';
			$text  = 'Done';
		} elseif ( 'cancelled' === $current ) {
			$class = 'mobo-status-cancelled';
			$text  = 'Cancelled';
		}

		return '<span class="mobo-status-badge ' . esc_attr( $class ) . '"><span class="mobo-dot"></span>' . esc_html( $text ) . '</span>';
	}

	private function text_field( $label, $key, $help = '' ) {
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( Mobo_Core_Settings::get( $key, '' ) ); ?>">
			<?php if ( '' !== $help ) : ?>
				<div class="mobo-help"><?php echo esc_html( $help ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function url_field( $label, $key, $help = '' ) {
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="url" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( Mobo_Core_Settings::get( $key, '' ) ); ?>">
			<?php if ( '' !== $help ) : ?>
				<div class="mobo-help"><?php echo esc_html( $help ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function password_field( $label, $key, $help = '' ) {
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="password" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( Mobo_Core_Settings::get( $key, '' ) ); ?>">
			<?php if ( '' !== $help ) : ?>
				<div class="mobo-help"><?php echo esc_html( $help ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function bool_field( $label, $key ) {
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
				<option value="0" <?php selected( Mobo_Core_Settings::get( $key, '0' ), '0' ); ?>>No</option>
				<option value="1" <?php selected( Mobo_Core_Settings::get( $key, '0' ), '1' ); ?>>Yes</option>
			</select>
		</div>
		<?php
	}

	private function int_field( $label, $key, $min, $max ) {
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="number" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( Mobo_Core_Settings::get_int( $key, 1, $min, $max ) ); ?>">
		</div>
		<?php
	}

	private function number_field( $label, $key, $min, $max, $step ) {
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="number" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="<?php echo esc_attr( $step ); ?>" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( Mobo_Core_Settings::get( $key, '0' ) ); ?>">
		</div>
		<?php
	}

	private function textarea_field( $label, $key, $help = '' ) {
		?>
		<div class="mobo-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<textarea id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"><?php echo esc_textarea( Mobo_Core_Settings::get( $key, '[]' ) ); ?></textarea>
			<?php if ( '' !== $help ) : ?>
				<div class="mobo-help"><?php echo esc_html( $help ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

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
				<option value="0"><?php echo esc_html__( 'None', 'mobo-core' ); ?></option>
				<?php if ( ! is_wp_error( $terms ) && is_array( $terms ) ) : ?>
					<?php foreach ( $terms as $term ) : ?>
						<option value="<?php echo esc_attr( absint( $term->term_id ) ); ?>" <?php selected( $selected, absint( $term->term_id ) ); ?>>
							<?php echo esc_html( $term->name ); ?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>
			<div class="mobo-help"><?php echo esc_html__( 'Used when automatic category update is disabled.', 'mobo-core' ); ?></div>
		</div>
		<?php
	}

	private function price_type_field() {
		$value = Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' );

		?>
		<div class="mobo-field">
			<label for="mobo_price_type"><?php echo esc_html__( 'Price Type', 'mobo-core' ); ?></label>
			<select id="mobo_price_type" name="mobo_price_type">
				<option value="static-price" <?php selected( $value, 'static-price' ); ?>>Static Price</option>
				<option value="static-percentage" <?php selected( $value, 'static-percentage' ); ?>>Static Percentage</option>
				<option value="dynamic-price" <?php selected( $value, 'dynamic-price' ); ?>>Dynamic Price</option>
			</select>
		</div>
		<?php
	}

	private function wp_cron_field() {
		$value = Mobo_Core_Settings::get( 'mobo_core_enable_wp_cron', 'no' );

		?>
		<div class="mobo-field">
			<label for="mobo_core_enable_wp_cron"><?php echo esc_html__( 'Enable WP-Cron', 'mobo-core' ); ?></label>
			<select id="mobo_core_enable_wp_cron" name="mobo_core_enable_wp_cron">
				<option value="no" <?php selected( $value, 'no' ); ?>>No - external C# runner</option>
				<option value="yes" <?php selected( $value, 'yes' ); ?>>Yes</option>
			</select>
		</div>
		<?php
	}

	private function missing_variants_field() {
		$value = Mobo_Core_Settings::get( 'mobo_core_missing_variants_behavior', 'outofstock' );

		?>
		<div class="mobo-field">
			<label for="mobo_core_missing_variants_behavior"><?php echo esc_html__( 'Missing Variants', 'mobo-core' ); ?></label>
			<select id="mobo_core_missing_variants_behavior" name="mobo_core_missing_variants_behavior">
				<option value="outofstock" <?php selected( $value, 'outofstock' ); ?>>Set out of stock</option>
				<option value="ignore" <?php selected( $value, 'ignore' ); ?>>Ignore</option>
			</select>
		</div>
		<?php
	}

	private function button_form( $action, $label, $class ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( $action, 'mobo_core_nonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<button type="submit" class="<?php echo esc_attr( $class ); ?>">
				<?php echo esc_html( $label ); ?>
			</button>
		</form>
		<?php
	}
}