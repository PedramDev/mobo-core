<?php
/**
 * Admin UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Admin {

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_mobo_core_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_mobo_core_reset_sync', array( $this, 'reset_sync' ) );
		add_action( 'admin_post_mobo_core_run_sync_step', array( $this, 'run_sync_step' ) );
		add_action( 'admin_post_mobo_core_run_webhook_queue', array( $this, 'run_webhook_queue' ) );
	}

	/**
	 * Add menu.
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

		$sync  = new Mobo_Core_Product_Sync();
		$state = $sync->get_manual_sync_state();

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Mobo Core v2', 'mobo-core' ); ?></h1>

			<?php if ( isset( $_GET['mobo_message'] ) ) : ?>
				<div class="notice notice-success">
					<p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['mobo_message'] ) ) ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Settings', 'mobo-core' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mobo_core_save_settings', 'mobo_core_nonce' ); ?>
				<input type="hidden" name="action" value="mobo_core_save_settings">

				<table class="form-table" role="presentation">
					<?php $this->text_field( 'Security Code', 'mobo_core_security_code' ); ?>
					<?php $this->url_field( 'API Base URL', 'mobo_core_api_base_url' ); ?>
					<?php $this->password_field( 'API Token', 'mobo_core_api_token' ); ?>

					<?php $this->bool_field( 'Only In Stock', 'mobo_core_only_in_stock' ); ?>

					<?php $this->bool_field( 'Auto Update Stock', 'global_product_auto_stock' ); ?>
					<?php $this->bool_field( 'Auto Update Price', 'global_product_auto_price' ); ?>
					<?php $this->bool_field( 'Auto Update Title', 'global_product_auto_title' ); ?>
					<?php $this->bool_field( 'Auto Update Caption', 'global_product_auto_caption' ); ?>
					<?php $this->bool_field( 'Auto Update Compare Price', 'global_product_auto_compare_price' ); ?>
					<?php $this->bool_field( 'Auto Update Slug', 'global_product_auto_slug' ); ?>
					<?php $this->bool_field( 'Update Images', 'global_update_images' ); ?>

					
					<?php $this->bool_field( 'Update Categories', 'global_update_categories' ); ?>
					<?php $this->category_dropdown_field( 'Default Category', 'mobo_default_category_id' ); ?>


					<?php $this->text_field( 'Price Type', 'mobo_price_type' ); ?>
					<?php $this->number_field( 'Additional Price', 'global_additional_price', 0, 999999999, '0.01' ); ?>
					<?php $this->number_field( 'Additional Percentage', 'global_additional_percentage', 0, 1000, '0.01' ); ?>
					<?php $this->bool_field( 'Dynamic Price', 'mobo_dynamic_price' ); ?>

					<tr>
						<th><label for="mobo_core_enable_wp_cron">Enable WP-Cron</label></th>
						<td>
							<select id="mobo_core_enable_wp_cron" name="mobo_core_enable_wp_cron">
								<option value="no" <?php selected( Mobo_Core_Settings::get( 'mobo_core_enable_wp_cron', 'no' ), 'no' ); ?>>No - external runner</option>
								<option value="yes" <?php selected( Mobo_Core_Settings::get( 'mobo_core_enable_wp_cron', 'no' ), 'yes' ); ?>>Yes</option>
							</select>
						</td>
					</tr>

					<?php $this->int_field( 'Time Budget Seconds', 'mobo_core_sync_time_budget_seconds', 2, 25 ); ?>
					<?php $this->int_field( 'Webhook Files Per Run', 'mobo_core_webhook_files_per_run', 1, 10 ); ?>
					<?php $this->int_field( 'Webhook Max Try', 'mobo_core_webhook_max_try', 1, 20 ); ?>
					<?php $this->int_field( 'Webhook Expire Days', 'mobo_core_webhook_expire_days', 1, 30 ); ?>
					<?php $this->int_field( 'Products Per Page', 'mobo_core_products_per_page', 1, 20 ); ?>
					<?php $this->int_field( 'Variants Per Page', 'mobo_core_variants_per_page', 1, 100 ); ?>
					<?php $this->int_field( 'Images Per Run', 'mobo_core_images_per_run', 0, 10 ); ?>

					<tr>
						<th><label for="mobo_core_missing_variants_behavior">Missing Variants</label></th>
						<td>
							<select id="mobo_core_missing_variants_behavior" name="mobo_core_missing_variants_behavior">
								<option value="outofstock" <?php selected( Mobo_Core_Settings::get( 'mobo_core_missing_variants_behavior', 'outofstock' ), 'outofstock' ); ?>>Set out of stock</option>
								<option value="ignore" <?php selected( Mobo_Core_Settings::get( 'mobo_core_missing_variants_behavior', 'outofstock' ), 'ignore' ); ?>>Ignore</option>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<hr>

			<h2><?php echo esc_html__( 'Manual API Sync', 'mobo-core' ); ?></h2>

			<table class="widefat striped" style="max-width:900px;">
				<tbody>
					<tr><th>Sync ID</th><td><?php echo esc_html( $state['syncId'] ); ?></td></tr>
					<tr><th>Status</th><td><?php echo esc_html( $state['status'] ); ?></td></tr>
					<tr><th>Product Page</th><td><?php echo esc_html( absint( $state['productPage'] ) ); ?></td></tr>
					<tr><th>Queued Products</th><td><?php echo esc_html( count( (array) $state['productQueue'] ) ); ?></td></tr>
					<tr><th>Current Product</th><td><?php echo esc_html( $state['currentProductGuid'] ); ?></td></tr>
					<tr><th>Variant Page</th><td><?php echo esc_html( absint( $state['variantPage'] ) ); ?></td></tr>
					<tr><th>Last Message</th><td><?php echo esc_html( $state['lastMessage'] ); ?></td></tr>
				</tbody>
			</table>

			<p>
				<?php $this->button_form( 'mobo_core_run_sync_step', 'Run One Sync Step', 'primary' ); ?>
				<?php $this->button_form( 'mobo_core_reset_sync', 'Reset Sync State', 'secondary' ); ?>
				<?php $this->button_form( 'mobo_core_run_webhook_queue', 'Run Webhook Queue', 'secondary' ); ?>
			</p>

			<hr>

			<h2>External URLs</h2>
			<p><code><?php echo esc_html( rest_url( 'mobo-core/v1/webhook' ) ); ?></code></p>
			<p><code><?php echo esc_html( rest_url( 'mobo-core/v1/webhook/run' ) ); ?></code></p>
			<p><code><?php echo esc_html( rest_url( 'mobo-core/v1/sync/run' ) ); ?></code></p>
		</div>
		<?php
	}

	public function save_settings() {
		$this->require_admin_and_nonce( 'mobo_core_save_settings' );
		Mobo_Core_Settings::save_from_post( $_POST );
		$this->redirect( 'Settings saved.' );
	}

	public function reset_sync() {
		$this->require_admin_and_nonce( 'mobo_core_reset_sync' );
		$sync = new Mobo_Core_Product_Sync();
		$sync->reset_manual_sync_state();
		$this->redirect( 'Sync state reset.' );
	}

	public function run_sync_step() {
		$this->require_admin_and_nonce( 'mobo_core_run_sync_step' );
		$sync   = new Mobo_Core_Product_Sync();
		$result = $sync->run_manual_sync_step();
		$this->redirect( $result['message'] );
	}

	public function run_webhook_queue() {
		$this->require_admin_and_nonce( 'mobo_core_run_webhook_queue' );
		$queue  = new Mobo_Core_Webhook_Queue();
		$result = $queue->process();
		$this->redirect( ! empty( $result['messages'][0] ) ? $result['messages'][0] : 'Webhook queue processed.' );
	}

	private function require_admin_and_nonce( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'mobo-core' ) );
		}

		check_admin_referer( $action, 'mobo_core_nonce' );
	}

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

	private function text_field( $label, $key ) {
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input type="text" class="regular-text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( Mobo_Core_Settings::get( $key, '' ) ); ?>"></td>
		</tr>
		<?php
	}

	private function url_field( $label, $key ) {
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input type="url" class="regular-text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( Mobo_Core_Settings::get( $key, '' ) ); ?>"></td>
		</tr>
		<?php
	}

	private function password_field( $label, $key ) {
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input type="password" class="regular-text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( Mobo_Core_Settings::get( $key, '' ) ); ?>"></td>
		</tr>
		<?php
	}

	private function bool_field( $label, $key ) {
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
					<option value="0" <?php selected( Mobo_Core_Settings::get( $key, '0' ), '0' ); ?>>No</option>
					<option value="1" <?php selected( Mobo_Core_Settings::get( $key, '0' ), '1' ); ?>>Yes</option>
				</select>
			</td>
		</tr>
		<?php
	}

	private function int_field( $label, $key, $min, $max ) {
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input type="number" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( Mobo_Core_Settings::get_int( $key, 1, $min, $max ) ); ?>"></td>
		</tr>
		<?php
	}

	private function number_field( $label, $key, $min, $max, $step ) {
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input type="number" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="<?php echo esc_attr( $step ); ?>" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( Mobo_Core_Settings::get( $key, '0' ) ); ?>"></td>
		</tr>
		<?php
	}

	private function button_form( $action, $label, $class ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
			<?php wp_nonce_field( $action, 'mobo_core_nonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php submit_button( $label, $class, 'submit', false ); ?>
		</form>
		<?php
	}

	/**
 * Render product category dropdown.
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
	<tr>
		<th>
			<label for="<?php echo esc_attr( $key ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
		</th>
		<td>
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
			<p class="description">
				<?php echo esc_html__( 'Used when automatic category update is disabled.', 'mobo-core' ); ?>
			</p>
		</td>
	</tr>
	<?php
}
}