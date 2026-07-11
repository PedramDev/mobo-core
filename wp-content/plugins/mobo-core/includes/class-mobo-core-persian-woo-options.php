<?php
/**
 * Enforce Persian WooCommerce city/state options required by automatic Mobo checkout.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Persian_Woo_Options {

	const OPTION_NAME = 'PW_Options';
	const CHECK_INTERVAL = 900;
	const NOTICE_TRANSIENT = 'mobo_core_pw_options_enforced_notice';
	const LAST_CHECK_OPTION = 'mobo_core_pw_options_last_check_at';
	const LAST_ENFORCED_OPTION = 'mobo_core_pw_options_last_enforced';

	/**
	 * Prevent internal writes from being reported as manual attempts.
	 *
	 * @var bool
	 */
	private static $internal_update = false;

	/**
	 * Bootstrap hooks and immediately enforce requirements when needed.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'pre_update_option_' . self::OPTION_NAME, array( __CLASS__, 'filter_pre_update_option' ), PHP_INT_MAX, 3 );
		add_action( 'delete_option_' . self::OPTION_NAME, array( __CLASS__, 'restore_after_delete' ), PHP_INT_MAX );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );

		self::ensure_required_options( 'bootstrap', true );
	}

	/**
	 * Whether Persian WooCommerce options are mandatory now.
	 *
	 * @return bool
	 */
	public static function is_required() {
		return class_exists( 'Mobo_Core_Settings' )
			&& Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' );
	}

	/**
	 * Force required Persian WooCommerce options on.
	 *
	 * @param string $source Source identifier.
	 * @param bool   $force  Ignore the periodic throttle.
	 * @return array
	 */
	public static function ensure_required_options( $source = 'runtime', $force = false ) {
		$source = sanitize_key( (string) $source );
		$now    = time();

		if ( ! self::is_required() ) {
			return array(
				'success'  => true,
				'status'   => 'not-required',
				'required' => false,
				'changed'  => array(),
			);
		}

		$last_check = absint( get_option( self::LAST_CHECK_OPTION, 0 ) );
		if ( ! $force && $last_check > 0 && ( $now - $last_check ) < self::CHECK_INTERVAL ) {
			$status = self::get_status();
			$status['status'] = ! empty( $status['compliant'] ) ? 'throttled-ok' : 'throttled-noncompliant';
			return $status;
		}

		update_option( self::LAST_CHECK_OPTION, $now, false );

		$options = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$changed = array();
		foreach ( self::required_keys() as $key ) {
			if ( ! isset( $options[ $key ] ) || ! self::is_truthy( $options[ $key ] ) ) {
				$options[ $key ] = '1';
				$changed[]       = $key;
			}
		}

		if ( ! empty( $changed ) ) {
			self::$internal_update = true;
			$updated = update_option( self::OPTION_NAME, $options, false );
			self::$internal_update = false;

			$stored = get_option( self::OPTION_NAME, array() );
			if ( ! is_array( $stored ) || ! self::options_are_compliant( $stored ) ) {
				return array(
					'success'  => false,
					'status'   => 'write-failed',
					'required' => true,
					'changed'  => $changed,
					'updated'  => (bool) $updated,
					'message'  => 'تنظیمات شهرهای ایران ووکامرس فارسی قابل ذخیره نیستند.',
				);
			}

			self::record_enforcement( $source, $changed, false );
		}

		$status = self::get_status();
		$status['status']  = empty( $changed ) ? 'already-compliant' : 'enforced';
		$status['changed'] = $changed;

		return $status;
	}

	/**
	 * Protect PW_Options against manual disabling while auto order is enabled.
	 *
	 * @param mixed  $value     New value.
	 * @param mixed  $old_value Old value.
	 * @param string $option    Option name.
	 * @return array
	 */
	public static function filter_pre_update_option( $value, $old_value, $option ) {
		if ( ! self::is_required() ) {
			return $value;
		}

		if ( ! is_array( $value ) ) {
			$value = is_array( $old_value ) ? $old_value : array();
		}

		$attempted = array();
		foreach ( self::required_keys() as $key ) {
			if ( ! isset( $value[ $key ] ) || ! self::is_truthy( $value[ $key ] ) ) {
				$attempted[]   = $key;
				$value[ $key ] = '1';
			}
		}

		if ( ! empty( $attempted ) && ! self::$internal_update ) {
			self::record_enforcement( 'manual-option-update', $attempted, true );
		}

		return $value;
	}

	/**
	 * Recreate PW_Options if another component deletes it while required.
	 *
	 * @return void
	 */
	public static function restore_after_delete() {
		if ( ! self::is_required() ) {
			return;
		}

		self::$internal_update = true;
		add_option(
			self::OPTION_NAME,
			array(
				'enable_iran_cities' => '1',
				'flip_state_city'     => '1',
			),
			'',
			false
		);
		self::$internal_update = false;

		self::record_enforcement( 'option-delete', self::required_keys(), true );
	}

	/**
	 * Current requirement status for admin/health output.
	 *
	 * @return array
	 */
	public static function get_status() {
		$options = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$enable_iran_cities = isset( $options['enable_iran_cities'] ) && self::is_truthy( $options['enable_iran_cities'] );
		$flip_state_city     = isset( $options['flip_state_city'] ) && self::is_truthy( $options['flip_state_city'] );

		return array(
			'success'            => true,
			'required'           => self::is_required(),
			'enableIranCities'   => $enable_iran_cities,
			'flipStateCity'      => $flip_state_city,
			'compliant'          => $enable_iran_cities && $flip_state_city,
			'lastCheckAt'        => absint( get_option( self::LAST_CHECK_OPTION, 0 ) ),
			'lastEnforcement'    => get_option( self::LAST_ENFORCED_OPTION, array() ),
		);
	}

	/**
	 * Admin notice after a forced restoration or manual disable attempt.
	 *
	 * @return void
	 */
	public static function render_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_required() ) {
			return;
		}

		$notice = get_transient( self::NOTICE_TRANSIENT );
		$status = self::get_status();

		if ( empty( $notice ) && ! empty( $status['compliant'] ) ) {
			return;
		}

		if ( ! empty( $notice ) ) {
			delete_transient( self::NOTICE_TRANSIENT );
		}

		$message = 'به دلیل فعال بودن ثبت خودکار سفارش در موبو، گزینه‌های «فعال‌سازی شهرهای ایران» و «جابجایی ترتیب استان و شهر» در ووکامرس فارسی اجباری هستند و نمی‌توان آن‌ها را غیرفعال کرد.';
		if ( empty( $status['compliant'] ) ) {
			$message .= ' افزونه نتوانست این تنظیمات را اصلاح کند؛ دسترسی نوشتن Optionهای وردپرس را بررسی کنید.';
		} else {
			$message .= ' تنظیمات لازم دوباره به‌صورت خودکار فعال شدند.';
		}

		echo '<div class="notice notice-warning is-dismissible"><p><strong>Mobo Core:</strong> ' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Required PW_Options keys.
	 *
	 * @return array
	 */
	private static function required_keys() {
		return array( 'enable_iran_cities', 'flip_state_city' );
	}

	/**
	 * Whether both options are enabled.
	 *
	 * @param array $options PW options.
	 * @return bool
	 */
	private static function options_are_compliant( $options ) {
		foreach ( self::required_keys() as $key ) {
			if ( ! isset( $options[ $key ] ) || ! self::is_truthy( $options[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Interpret checkbox-like values.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_truthy( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * Persist enforcement details and queue an admin notice.
	 *
	 * @param string $source   Source identifier.
	 * @param array  $keys     Changed keys.
	 * @param bool   $manual   Manual attempt.
	 * @return void
	 */
	private static function record_enforcement( $source, $keys, $manual ) {
		$event = array(
			'at'      => time(),
			'source'  => sanitize_key( (string) $source ),
			'keys'    => array_values( array_intersect( self::required_keys(), array_map( 'sanitize_key', (array) $keys ) ) ),
			'manual'  => (bool) $manual,
		);

		update_option( self::LAST_ENFORCED_OPTION, $event, false );
		set_transient( self::NOTICE_TRANSIENT, $event, 10 * MINUTE_IN_SECONDS );
	}
}
