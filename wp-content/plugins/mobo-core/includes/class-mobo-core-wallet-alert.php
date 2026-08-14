<?php
/**
 * Mobo wallet balance monitoring and one-shot low-balance SMS alerts.
 *
 * The balance is checked after every successful automatic Mobo order payment.
 * SMS delivery is delegated to Mobo_Core_SMS_Notifications / Persian WooCommerce SMS.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Wallet_Alert {

	const OPTION_LAST_BALANCE      = 'mobo_core_wallet_last_balance';
	const OPTION_LAST_CHECKED_AT   = 'mobo_core_wallet_last_checked_at';
	const OPTION_LAST_ERROR        = 'mobo_core_wallet_last_error';
	const OPTION_LAST_ORDER_ID     = 'mobo_core_wallet_last_order_id';
	const OPTION_NOTIFIED          = 'mobo_core_wallet_alert_notified';
	const OPTION_NOTIFIED_AT       = 'mobo_core_wallet_alert_notified_at';
	const OPTION_NOTIFIED_BALANCE  = 'mobo_core_wallet_alert_notified_balance';
	const OPTION_SMS_LAST_ATTEMPT_AT = 'mobo_core_wallet_sms_last_attempt_at';
	const OPTION_SMS_LAST_SUCCESS_AT = 'mobo_core_wallet_sms_last_success_at';
	const OPTION_SMS_LAST_ERROR      = 'mobo_core_wallet_sms_last_error';
	const OPTION_SMS_LAST_RESULT     = 'mobo_core_wallet_sms_last_result';

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'mobo_core_mobo_order_submission_success', array( $this, 'handle_mobo_order_submission_success' ), 10, 3 );
	}

	/**
	 * Check wallet after a successful Mobo order and optionally send one SMS alert.
	 *
	 * @param int   $order_id WooCommerce order ID.
	 * @param int   $mobo_order_id Mobo order ID.
	 * @param array $payment_json Mobo payment response.
	 * @return void
	 */
	public function handle_mobo_order_submission_success( $order_id, $mobo_order_id = 0, $payment_json = array() ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : false;
		$result = $this->check_balance_and_maybe_notify( absint( $order_id ) );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order->update_meta_data( '_mobo_wallet_balance_checked_at', time() );

		if ( is_wp_error( $result ) ) {
			$order->update_meta_data( '_mobo_wallet_balance_check_error', sanitize_text_field( $result->get_error_message() ) );
			$order->save();
			return;
		}

		$order->delete_meta_data( '_mobo_wallet_balance_check_error' );
		$order->update_meta_data( '_mobo_wallet_balance_after_purchase', $result['balance'] );
		$order->update_meta_data( '_mobo_wallet_alert_threshold', $result['threshold'] );
		$order->update_meta_data( '_mobo_wallet_alert_sms_sent', ! empty( $result['smsSent'] ) ? 'yes' : 'no' );
		if ( ! empty( $result['smsError'] ) ) {
			$order->update_meta_data( '_mobo_wallet_alert_sms_error', sanitize_text_field( (string) $result['smsError'] ) );
		} else {
			$order->delete_meta_data( '_mobo_wallet_alert_sms_error' );
		}
		$order->save();
	}

	/**
	 * Fetch current balance, persist state, and send a low-balance SMS once when needed.
	 *
	 * @return array|WP_Error
	 */
	public function check_balance_and_maybe_notify( $order_id = 0 ) {
		$order_id = absint( $order_id );
		if ( $order_id > 0 ) {
			update_option( self::OPTION_LAST_ORDER_ID, $order_id, false );
		}

		if ( ! class_exists( 'Mobo_Core_Checkout_Validator' ) ) {
			return new WP_Error( 'mobo_core_wallet_checkout_validator_missing', 'Mobo checkout client is unavailable.' );
		}

		$validator = new Mobo_Core_Checkout_Validator();
		$balance   = $validator->get_mobo_wallet_balance();
		$checked_at = time();

		update_option( self::OPTION_LAST_CHECKED_AT, $checked_at, false );

		if ( is_wp_error( $balance ) ) {
			update_option( self::OPTION_LAST_ERROR, sanitize_text_field( $balance->get_error_message() ), false );
			return $balance;
		}

		delete_option( self::OPTION_LAST_ERROR );
		update_option( self::OPTION_LAST_BALANCE, $balance, false );

		$threshold = Mobo_Core_Settings::get_int( 'mobo_core_wallet_alert_threshold', 0, 0, PHP_INT_MAX );
		$result = array(
			'balance'   => $balance,
			'threshold' => $threshold,
			'checkedAt' => $checked_at,
			'smsSent'   => false,
			'notified'  => self::is_notified(),
			'smsError'   => '',
		);

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_wallet_alert_enabled', '0' ) ) {
			return $result;
		}

		if ( (float) $balance > (float) $threshold ) {
			return $result;
		}

		if ( self::is_notified() ) {
			return $result;
		}

		/*
		 * Two orders may finish at nearly the same time. Serialize only the
		 * notification decision/send/flag update so the one-shot guarantee remains
		 * true even under concurrent successful purchases. Balance checks themselves
		 * remain independent and are still recorded for every order.
		 */
		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'wallet_alert_sms', 60 ) : false;
		if ( false === $lock ) {
			$result['notified'] = self::is_notified();
			return $result;
		}

		try {
			if ( self::is_notified() ) {
				$result['notified'] = true;
				return $result;
			}

			self::mark_sms_attempt();

			if ( ! class_exists( 'Mobo_Core_SMS_Notifications' ) ) {
				$send = new WP_Error( 'mobo_core_wallet_sms_service_missing', 'Mobo SMS notification service is unavailable.' );
				self::mark_sms_result( $send );
				$result['smsError'] = sanitize_text_field( $send->get_error_message() );
				return $result;
			}

			$sms  = new Mobo_Core_SMS_Notifications();
			$send = $sms->send_wallet_balance_alert( $balance, $threshold, $order_id );
			self::mark_sms_result( $send );

			if ( is_wp_error( $send ) ) {
				$result['smsError'] = sanitize_text_field( $send->get_error_message() );
				return $result;
			}

			if ( true === $send ) {
				update_option( self::OPTION_NOTIFIED, '1', false );
				update_option( self::OPTION_NOTIFIED_AT, time(), false );
				update_option( self::OPTION_NOTIFIED_BALANCE, $balance, false );
				$result['smsSent']  = true;
				$result['notified'] = true;
			}

			return $result;
		} finally {
			Mobo_Core_Lock::release( 'wallet_alert_sms', $lock );
		}
	}


	/**
	 * Send the configured wallet alert immediately as a transport/configuration test.
	 * This never consumes or changes the one-shot notification flag.
	 *
	 * @return true|WP_Error
	 */
	public function send_test_sms() {
		$threshold = Mobo_Core_Settings::get_int( 'mobo_core_wallet_alert_threshold', 0, 0, PHP_INT_MAX );
		$balance   = get_option( self::OPTION_LAST_BALANCE, null );
		if ( ! is_numeric( $balance ) ) {
			$balance = $threshold;
		}

		self::mark_sms_attempt();

		if ( ! class_exists( 'Mobo_Core_SMS_Notifications' ) ) {
			$result = new WP_Error( 'mobo_core_wallet_sms_service_missing', 'Mobo SMS notification service is unavailable.' );
			self::mark_sms_result( $result );
			return $result;
		}

		$last_order_id = absint( get_option( self::OPTION_LAST_ORDER_ID, 0 ) );
		$sms           = new Mobo_Core_SMS_Notifications();
		$result        = $sms->send_wallet_balance_alert( $balance, $threshold, $last_order_id );
		self::mark_sms_result( $result );
		return $result;
	}

	/**
	 * Persist a bounded SMS attempt marker without touching the one-shot state.
	 *
	 * @return void
	 */
	private static function mark_sms_attempt() {
		update_option( self::OPTION_SMS_LAST_ATTEMPT_AT, time(), false );
		update_option( self::OPTION_SMS_LAST_RESULT, 'attempting', false );
	}

	/**
	 * Persist the transport result separately from Mobo wallet-balance API errors.
	 *
	 * @param true|WP_Error|mixed $result Send result.
	 * @return void
	 */
	private static function mark_sms_result( $result ) {
		if ( true === $result ) {
			update_option( self::OPTION_SMS_LAST_SUCCESS_AT, time(), false );
			update_option( self::OPTION_SMS_LAST_RESULT, 'sent', false );
			delete_option( self::OPTION_SMS_LAST_ERROR );
			return;
		}

		$error = is_wp_error( $result ) ? $result->get_error_message() : 'SMS transport returned an unexpected result.';
		$error = sanitize_text_field( (string) $error );
		if ( function_exists( 'mb_substr' ) ) {
			$error = mb_substr( $error, 0, 500 );
		} else {
			$error = substr( $error, 0, 500 );
		}
		update_option( self::OPTION_SMS_LAST_RESULT, 'failed', false );
		update_option( self::OPTION_SMS_LAST_ERROR, $error, false );
	}

	/**
	 * Whether the one-shot low-balance notification has already been sent.
	 *
	 * @return bool
	 */
	public static function is_notified() {
		return '1' === (string) get_option( self::OPTION_NOTIFIED, '0' );
	}

	/**
	 * Explicitly re-arm the reminder. Balance changes never reset this automatically.
	 *
	 * @return void
	 */
	public static function rearm() {
		update_option( self::OPTION_NOTIFIED, '0', false );
		delete_option( self::OPTION_NOTIFIED_AT );
		delete_option( self::OPTION_NOTIFIED_BALANCE );
	}

	/**
	 * Current dashboard state.
	 *
	 * @return array
	 */
	public static function get_status() {
		$last_balance = get_option( self::OPTION_LAST_BALANCE, null );
		$has_balance  = null !== $last_balance && false !== $last_balance && '' !== (string) $last_balance;

		return array(
			'enabled'         => Mobo_Core_Settings::enabled( 'mobo_core_wallet_alert_enabled', '0' ),
			'threshold'       => Mobo_Core_Settings::get_int( 'mobo_core_wallet_alert_threshold', 0, 0, PHP_INT_MAX ),
			'hasBalance'      => $has_balance,
			'lastBalance'     => $has_balance ? $last_balance : null,
			'lastCheckedAt'   => absint( get_option( self::OPTION_LAST_CHECKED_AT, 0 ) ),
			'lastOrderId'     => absint( get_option( self::OPTION_LAST_ORDER_ID, 0 ) ),
			'lastError'       => sanitize_text_field( (string) get_option( self::OPTION_LAST_ERROR, '' ) ),
			'notified'        => self::is_notified(),
			'notifiedAt'      => absint( get_option( self::OPTION_NOTIFIED_AT, 0 ) ),
			'notifiedBalance' => get_option( self::OPTION_NOTIFIED_BALANCE, null ),
			'smsLastAttemptAt' => absint( get_option( self::OPTION_SMS_LAST_ATTEMPT_AT, 0 ) ),
			'smsLastSuccessAt' => absint( get_option( self::OPTION_SMS_LAST_SUCCESS_AT, 0 ) ),
			'smsLastResult'    => sanitize_key( (string) get_option( self::OPTION_SMS_LAST_RESULT, '' ) ),
			'smsLastError'     => sanitize_text_field( (string) get_option( self::OPTION_SMS_LAST_ERROR, '' ) ),
		);
	}
}
