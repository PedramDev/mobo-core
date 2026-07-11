<?php
/**
 * Protect queue/processing settings while a manual Sync or Repair is active.
 *
 * Changing pagination, cursor, image or retry settings in the middle of a
 * resumable run can move page boundaries or alter the continuation strategy.
 * This guard keeps the stored configuration stable until the run finishes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Sync_Settings_Guard {

	const NOTICE_TRANSIENT_PREFIX = 'mobo_core_sync_settings_guard_notice_';

	/**
	 * Whether a notice has already been recorded in this request.
	 *
	 * @var bool
	 */
	private static $notice_recorded = false;

	/**
	 * Register option guards and the fallback admin notice.
	 *
	 * @return void
	 */
	public function init() {
		foreach ( self::protected_options() as $option_name ) {
			add_filter( 'pre_update_option_' . $option_name, array( $this, 'guard_option_update' ), 10, 3 );
		}

		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
	}

	/**
	 * Queue/processing options that must remain stable during Sync/Repair.
	 *
	 * @return array
	 */
	public static function protected_options() {
		return array(
			'mobo_core_sync_time_budget_seconds',
			'mobo_core_products_per_page',
			'mobo_core_product_cursor_sync_enabled',
			'mobo_core_variants_per_page',
			'mobo_core_variant_cursor_sync_enabled',
			'mobo_core_images_per_run',
			'mobo_core_image_queue_enabled',
			'mobo_core_image_queue_blocking',
			'mobo_core_image_max_try',
			'mobo_core_image_retry_base_seconds',
			'mobo_core_webhook_files_per_run',
			'mobo_core_webhook_max_try',
			'mobo_core_webhook_expire_days',
			'mobo_core_variant_parent_wait_timeout_seconds',
			'mobo_core_missing_variants_behavior',
		);
	}

	/**
	 * Get current Sync/Repair lock details without instantiating the full sync service.
	 *
	 * @return array
	 */
	public static function get_lock_state() {
		$state = get_option( 'mobo_core_sync_state', array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'idle';
		$locked = in_array( $status, array( 'running', 'waiting_for_portal' ), true );
		$is_repair = ! empty( $state['repairMode'] );

		return array(
			'locked'        => $locked,
			'status'        => $status,
			'repairMode'    => $is_repair,
			'operationLabel'=> $is_repair ? 'Repair محصولات' : 'همگام سازی محصولات',
			'syncId'        => isset( $state['syncId'] ) ? sanitize_text_field( (string) $state['syncId'] ) : '',
			'updatedAt'     => isset( $state['updatedAt'] ) ? absint( $state['updatedAt'] ) : 0,
		);
	}

	/**
	 * Whether queue settings are currently locked.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		$state = self::get_lock_state();

		return ! empty( $state['locked'] );
	}

	/**
	 * Reject direct update_option attempts while Sync/Repair is active.
	 *
	 * @param mixed  $new_value Proposed value.
	 * @param mixed  $old_value Stored value.
	 * @param string $option_name Option name.
	 * @return mixed
	 */
	public function guard_option_update( $new_value, $old_value, $option_name ) {
		if ( ! self::is_locked() || maybe_serialize( $new_value ) === maybe_serialize( $old_value ) ) {
			return $new_value;
		}

		$this->record_blocked_attempt( $option_name );

		return $old_value;
	}

	/**
	 * Record one user-scoped notice for attempts outside the Mobo settings form.
	 *
	 * @param string $option_name Blocked option.
	 * @return void
	 */
	private function record_blocked_attempt( $option_name ) {
		if ( self::$notice_recorded || ! function_exists( 'get_current_user_id' ) ) {
			return;
		}

		$user_id = absint( get_current_user_id() );
		if ( $user_id <= 0 ) {
			return;
		}

		$lock = self::get_lock_state();
		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . $user_id,
			array(
				'option'         => sanitize_key( (string) $option_name ),
				'operationLabel' => isset( $lock['operationLabel'] ) ? sanitize_text_field( (string) $lock['operationLabel'] ) : 'همگام سازی',
				'createdAt'      => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		self::$notice_recorded = true;
	}

	/**
	 * Render a fallback WordPress notice for blocked direct updates.
	 *
	 * @return void
	 */
	public function render_admin_notice() {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return;
		}

		$user_id = absint( get_current_user_id() );
		if ( $user_id <= 0 ) {
			return;
		}

		$key    = self::NOTICE_TRANSIENT_PREFIX . $user_id;
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $key );
		$operation = isset( $notice['operationLabel'] ) ? sanitize_text_field( (string) $notice['operationLabel'] ) : 'همگام سازی';
		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php echo esc_html( 'تنظیمات صف و پردازش در زمان اجرای ' . $operation . ' قفل است. تغییر ذخیره نشد؛ پس از پایان عملیات می توانید این تنظیمات را تغییر دهید.' ); ?></p>
		</div>
		<?php
	}
}
