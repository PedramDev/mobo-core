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
	const OPTION_DELIVERY_FENCE_AT = 'mobo_core_wallet_alert_delivery_fence_at';
	const OPTION_SMS_LAST_ATTEMPT_AT = 'mobo_core_wallet_sms_last_attempt_at';
	const OPTION_SMS_LAST_SUCCESS_AT = 'mobo_core_wallet_sms_last_success_at';
	const OPTION_SMS_LAST_ERROR      = 'mobo_core_wallet_sms_last_error';
	const OPTION_SMS_LAST_RESULT     = 'mobo_core_wallet_sms_last_result';
	const OPTION_TEST_SMS_LAST_ATTEMPT_AT = 'mobo_core_wallet_test_sms_last_attempt_at';
	const OPTION_TEST_SMS_LAST_SUCCESS_AT = 'mobo_core_wallet_test_sms_last_success_at';
	const OPTION_TEST_SMS_LAST_ERROR      = 'mobo_core_wallet_test_sms_last_error';
	const OPTION_TEST_SMS_LAST_RESULT     = 'mobo_core_wallet_test_sms_last_result';

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
		/* Wallet monitoring is provider-capability-specific. A future provider without
		 * a wallet endpoint must not turn every otherwise-successful order into a
		 * persistent wallet-health error. */
		if ( ! self::is_wallet_runtime_ready_for_current_connection() ) {
			return;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : false;
		$result = $this->check_balance_and_maybe_notify( absint( $order_id ) );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order->update_meta_data( '_mobo_wallet_balance_checked_at', time() );

		if ( is_wp_error( $result ) ) {
			$order->update_meta_data( '_mobo_wallet_balance_check_error', self::public_error_message( $result, 'Wallet balance request failed.' ) );
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
		if ( ! self::is_wallet_runtime_ready_for_current_connection() ) {
			return new WP_Error( 'mobo_core_wallet_owner_unavailable', 'Wallet owner is unavailable; no post-order balance request was started.' );
		}
		if ( $order_id > 0 ) {
			Mobo_Core_Settings::update( self::OPTION_LAST_ORDER_ID, $order_id, false );
		}

		if ( ! class_exists( 'Mobo_Core_Checkout_Validator' ) ) {
			return new WP_Error( 'mobo_core_wallet_checkout_validator_missing', 'Mobo checkout client is unavailable.' );
		}

		$validator = new Mobo_Core_Checkout_Validator();
		$balance   = $validator->get_mobo_wallet_balance();
		$checked_at = time();

		Mobo_Core_Settings::update( self::OPTION_LAST_CHECKED_AT, $checked_at, false );

		if ( is_wp_error( $balance ) ) {
			Mobo_Core_Settings::update( self::OPTION_LAST_ERROR, self::public_error_message( $balance, 'Wallet balance request failed.' ), false );
			return $balance;
		}

		Mobo_Core_Settings::delete( self::OPTION_LAST_ERROR );
		Mobo_Core_Settings::update( self::OPTION_LAST_BALANCE, $balance, false );

		$threshold = Mobo_Core_Settings::get_int( 'mobo_core_wallet_alert_threshold', 0, 0, PHP_INT_MAX );
		$fence_at = self::get_delivery_fence_at();
		$notified = self::is_notified();
		$result = array(
			'balance'   => $balance,
			'threshold' => $threshold,
			'checkedAt' => $checked_at,
			'smsSent'   => false,
			'notified'  => $notified,
			'uncertain' => $fence_at > 0 && ! $notified,
			'smsError'   => '',
		);

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_wallet_alert_enabled', '0' ) ) {
			return $result;
		}

		if ( (float) $balance > (float) $threshold ) {
			return $result;
		}

		if ( $notified ) {
			return $result;
		}

		if ( $fence_at > 0 ) {
			$result['uncertain'] = true;
			$result['smsError']  = 'A previous wallet-alert SMS crossed the remote-send boundary but its durable one-shot state is uncertain. Automatic resend is blocked until an administrator explicitly re-arms the alert.';
			return $result;
		}

		/*
		 * Two orders may finish at nearly the same time. Serialize only the
		 * notification decision/send/flag update so the one-shot guarantee remains
		 * true even under concurrent successful purchases. Balance checks themselves
		 * remain independent and are still recorded for every order.
		 */
		$lock_name = self::delivery_lock_name();
		$lock = ( '' !== $lock_name && class_exists( 'Mobo_Core_Lock' ) ) ? Mobo_Core_Lock::acquire( $lock_name, 60 ) : false;
		if ( false === $lock ) {
			$result['notified'] = self::is_notified();
			return $result;
		}

		try {
			if ( self::is_notified() ) {
				$result['notified'] = true;
				return $result;
			}

			$fence_at = self::get_delivery_fence_at();
			if ( $fence_at > 0 ) {
				$result['uncertain'] = true;
				$result['smsError']  = 'A previous wallet-alert SMS crossed the remote-send boundary but its durable one-shot state is uncertain. Automatic resend is blocked until an administrator explicitly re-arms the alert.';
				return $result;
			}

			/*
			 * Persist a replay fence before any remote SMS side effect. If durable local
			 * state cannot be proven, do not send. Once the remote boundary is crossed,
			 * the fence is retained on ambiguous/commit-failure outcomes so a later
			 * order cannot silently duplicate the one-shot alert.
			 */
			$fence_at = time();
			if ( ! self::write_verified( self::OPTION_DELIVERY_FENCE_AT, $fence_at ) ) {
				$error = new WP_Error( 'mobo_core_wallet_sms_fence_write_failed', 'Wallet SMS was not sent because the durable one-shot fence could not be stored.' );
				self::mark_sms_result( $error );
				$result['smsError'] = self::public_error_message( $error, 'Wallet SMS was not dispatched.' );
				return $result;
			}

			self::mark_sms_attempt();

			if ( ! class_exists( 'Mobo_Core_SMS_Notifications' ) ) {
				$send = new WP_Error( 'mobo_core_wallet_sms_service_missing', 'Mobo SMS notification service is unavailable.' );
				self::mark_sms_result( $send );
				self::clear_delivery_fence(); // No remote send was attempted.
				$result['smsError'] = self::public_error_message( $send, 'Wallet SMS request failed.' );
				return $result;
			}

			$sms  = new Mobo_Core_SMS_Notifications();
			$send = $sms->send_wallet_balance_alert( $balance, $threshold, $order_id );

			if ( is_wp_error( $send ) ) {
				self::mark_sms_result( $send );
				/* Configuration/template failures occur before dispatch and are safe to retry.
				 * Gateway/transport failures can be ambiguous, so retain the replay fence. */
				if ( self::is_pre_dispatch_sms_error( $send ) ) {
					self::clear_delivery_fence();
				} else {
					self::mark_sms_uncertain( 'SMS gateway outcome is not safe to replay automatically. Explicitly re-arm after checking the gateway/recipient.' );
					$result['uncertain'] = true;
				}
				$result['smsError'] = self::public_error_message( $send, 'Wallet SMS request failed.' );
				return $result;
			}

			if ( true === $send ) {
				self::mark_sms_result( true );
				$notified_at = time();
				$committed = self::write_verified( self::OPTION_NOTIFIED, '1' )
					&& self::write_verified( self::OPTION_NOTIFIED_AT, $notified_at )
					&& self::write_verified( self::OPTION_NOTIFIED_BALANCE, $balance );

				if ( ! $committed ) {
					self::mark_sms_uncertain( 'SMS gateway accepted the wallet alert, but the durable one-shot state could not be verified. Automatic resend is blocked until explicit re-arm.' );
					$result['smsSent']   = true;
					$result['uncertain'] = true;
					$result['smsError']  = 'SMS gateway accepted the request, but the one-shot state could not be committed safely.';
					return $result;
				}

				self::clear_delivery_fence();
				$result['smsSent']  = true;
				$result['notified'] = true;
			}

			return $result;
		} finally {
			Mobo_Core_Lock::release( $lock_name, $lock );
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
		$balance   = Mobo_Core_Settings::get( self::OPTION_LAST_BALANCE, null );
		if ( ! is_numeric( $balance ) ) {
			$balance = $threshold;
		}

		self::mark_test_sms_attempt();

		if ( ! class_exists( 'Mobo_Core_SMS_Notifications' ) ) {
			$result = new WP_Error( 'mobo_core_wallet_sms_service_missing', 'Mobo SMS notification service is unavailable.' );
			self::mark_test_sms_result( $result );
			return $result;
		}

		$last_order_id = absint( Mobo_Core_Settings::get( self::OPTION_LAST_ORDER_ID, 0 ) );
		$sms           = new Mobo_Core_SMS_Notifications();
		$result        = $sms->send_wallet_balance_alert( $balance, $threshold, $last_order_id );
		self::mark_test_sms_result( $result );
		return $result;
	}

	/**
	 * Whether the current runtime Connection's provider implements wallet balance.
	 *
	 * @return bool
	 */
	public static function is_wallet_supported_for_current_connection() {
		if ( ! class_exists( 'Mobo_Core_Provider_Registry' ) ) {
			return false;
		}

		$provider = Mobo_Core_Provider_Registry::get_for_runtime_connection();
		if ( ! is_object( $provider ) || ! method_exists( $provider, 'get_endpoint' ) ) {
			return false;
		}

		try {
			return '' !== trim( (string) $provider->get_endpoint( 'wallet_balance' ) );
		} catch ( Throwable $e ) {
			return false;
		}
	}


	/** Whether the current Connection is enabled/provisioned/ownership-ready and wallet-capable. */
	public static function is_wallet_runtime_ready_for_current_connection() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			return false;
		}

		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $connection_id <= 0 || ! method_exists( 'Mobo_Core_Connection_Repository', 'get_by_id_fresh' ) ) { return false; }
		$connection = Mobo_Core_Connection_Repository::get_by_id_fresh( $connection_id );
		if ( ! is_array( $connection ) || empty( $connection['is_enabled'] ) || ! Mobo_Core_Connection_Repository::is_provisioned( $connection ) ) {
			return false;
		}
		/* The provider registry and API client can still hold the old request-local
		 * route after a concurrent reprovision. Never run a balance check through
		 * that cached route even though the new durable owner is enabled. */
		$runtime = Mobo_Core_Connection_Repository::get_runtime_connection();
		if ( ! is_array( $runtime ) || absint( $runtime['id'] ?? 0 ) !== $connection_id ) { return false; }
		foreach ( array( 'provider_key', 'source_key', 'api_base_url', 'source_site_url', 'connection_key', 'license_token', 'security_code' ) as $field ) {
			if ( (string) ( $runtime[ $field ] ?? '' ) !== (string) ( $connection[ $field ] ?? '' ) ) { return false; }
		}

		$enabled_count = Mobo_Core_Connection_Repository::count_enabled();
		if ( $enabled_count > 1 && ! Mobo_Core_Connection_Repository::multi_connection_runtime_ready() ) {
			return false;
		}

		return self::is_wallet_supported_for_current_connection();
	}

	/** Both SMS delivery and explicit re-arm serialize on the same durable
	 * per-Connection key. One noisy Source cannot lock out another Source. */
	private static function delivery_lock_name() {
		$id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		/* Mobo_Core_Lock::option_key already namespaces this logical name by owner. */
		return $id > 0 ? 'wallet_alert_sms' : '';
	}

	/** Diagnostic boundary: never store raw upstream/gateway exception text. */
	private static function public_error_message( $error, $fallback = 'Wallet service returned an error.' ) {
		if ( ! is_wp_error( $error ) ) { return $fallback; }
		$code = substr( sanitize_key( (string) $error->get_error_code() ), 0, 64 );
		return $fallback . ( '' !== $code ? ' [' . $code . ']' : '' );
	}

	/** Read the durable post-send replay fence timestamp. */
	private static function get_delivery_fence_at() {
		return absint( Mobo_Core_Settings::get( self::OPTION_DELIVERY_FENCE_AT, 0 ) );
	}

	/** Store a state value and verify it through the authoritative ownership read path. */
	private static function write_verified( $key, $value ) {
		Mobo_Core_Settings::update( $key, $value, false );
		$stored = Mobo_Core_Settings::get( $key, null );
		return maybe_serialize( $stored ) === maybe_serialize( $value );
	}

	/** Clear the replay fence; the read-back is authoritative even when delete() returns false. */
	private static function clear_delivery_fence() {
		Mobo_Core_Settings::delete( self::OPTION_DELIVERY_FENCE_AT );
		return 0 === self::get_delivery_fence_at();
	}

	/** Errors that are known to occur before PWSMS->send_sms() is invoked. */
	private static function is_pre_dispatch_sms_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		return in_array(
			(string) $error->get_error_code(),
			array(
				'mobo_core_wallet_sms_empty_recipients',
				'mobo_core_wallet_sms_empty_template',
				'mobo_core_wallet_sms_template_exception',
				'mobo_core_wallet_sms_empty_message',
				'mobo_core_sms_pwsms_missing',
				'mobo_core_sms_pwsms_bootstrap_exception',
			),
			true
		);
	}

	/** Mark a crossed remote boundary as replay-unsafe until explicit administrator re-arm. */
	private static function mark_sms_uncertain( $message ) {
		$message = sanitize_text_field( (string) $message );
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 500 ) : substr( $message, 0, 500 );
		Mobo_Core_Settings::update( self::OPTION_SMS_LAST_RESULT, 'uncertain', false );
		Mobo_Core_Settings::update( self::OPTION_SMS_LAST_ERROR, $message, false );
	}

	/**
	 * Persist a bounded SMS attempt marker without touching the one-shot state.
	 *
	 * @return void
	 */
	private static function mark_sms_attempt() {
		Mobo_Core_Settings::update( self::OPTION_SMS_LAST_ATTEMPT_AT, time(), false );
		Mobo_Core_Settings::update( self::OPTION_SMS_LAST_RESULT, 'attempting', false );
	}

	/**
	 * Persist the transport result separately from Mobo wallet-balance API errors.
	 *
	 * @param true|WP_Error|mixed $result Send result.
	 * @return void
	 */
	private static function mark_sms_result( $result ) {
		if ( true === $result ) {
			Mobo_Core_Settings::update( self::OPTION_SMS_LAST_SUCCESS_AT, time(), false );
			Mobo_Core_Settings::update( self::OPTION_SMS_LAST_RESULT, 'sent', false );
			Mobo_Core_Settings::delete( self::OPTION_SMS_LAST_ERROR );
			return;
		}

		$error = self::public_error_message( $result, 'SMS transport returned an error.' );
		$error = sanitize_text_field( (string) $error );
		if ( function_exists( 'mb_substr' ) ) {
			$error = mb_substr( $error, 0, 500 );
		} else {
			$error = substr( $error, 0, 500 );
		}
		Mobo_Core_Settings::update( self::OPTION_SMS_LAST_RESULT, 'failed', false );
		Mobo_Core_Settings::update( self::OPTION_SMS_LAST_ERROR, $error, false );
	}



	/** Persist an administrator transport-test attempt without touching live alert health. */
	private static function mark_test_sms_attempt() {
		Mobo_Core_Settings::update( self::OPTION_TEST_SMS_LAST_ATTEMPT_AT, time(), false );
		Mobo_Core_Settings::update( self::OPTION_TEST_SMS_LAST_RESULT, 'attempting', false );
	}

	/** Persist administrator transport-test result separately from live one-shot delivery. */
	private static function mark_test_sms_result( $result ) {
		if ( true === $result ) {
			Mobo_Core_Settings::update( self::OPTION_TEST_SMS_LAST_SUCCESS_AT, time(), false );
			Mobo_Core_Settings::update( self::OPTION_TEST_SMS_LAST_RESULT, 'sent', false );
			Mobo_Core_Settings::delete( self::OPTION_TEST_SMS_LAST_ERROR );
			return;
		}

		$error = self::public_error_message( $result, 'SMS transport returned an error.' );
		$error = sanitize_text_field( (string) $error );
		$error = function_exists( 'mb_substr' ) ? mb_substr( $error, 0, 500 ) : substr( $error, 0, 500 );
		Mobo_Core_Settings::update( self::OPTION_TEST_SMS_LAST_RESULT, 'failed', false );
		Mobo_Core_Settings::update( self::OPTION_TEST_SMS_LAST_ERROR, $error, false );
	}

	/**
	 * Whether the one-shot low-balance notification has already been sent.
	 *
	 * @return bool
	 */
	public static function is_notified() {
		return '1' === (string) Mobo_Core_Settings::get( self::OPTION_NOTIFIED, '0' );
	}

	/**
	 * Explicitly re-arm the reminder. Balance changes never reset this automatically.
	 *
	 * @return bool True only when re-arm state is read-back verified.
	 */
	public static function rearm() {
		/* A concurrent re-arm must not clear the replay fence while SMS is in-flight.
		 * Reject and let the administrator retry after the owning lease finishes. */
		$lock_name = self::delivery_lock_name();
		$lock = ( '' !== $lock_name && class_exists( 'Mobo_Core_Lock' ) ) ? Mobo_Core_Lock::acquire( $lock_name, 60 ) : false;
		if ( false === $lock ) { return false; }
		try {
		Mobo_Core_Settings::update( self::OPTION_NOTIFIED, '0', false );
		Mobo_Core_Settings::delete( self::OPTION_NOTIFIED_AT );
		Mobo_Core_Settings::delete( self::OPTION_NOTIFIED_BALANCE );
		$fence_cleared = self::clear_delivery_fence();
		if ( 'uncertain' === sanitize_key( (string) Mobo_Core_Settings::get( self::OPTION_SMS_LAST_RESULT, '' ) ) ) {
			Mobo_Core_Settings::delete( self::OPTION_SMS_LAST_ERROR );
			Mobo_Core_Settings::update( self::OPTION_SMS_LAST_RESULT, 'rearmed', false );
		}
		return $fence_cleared && '0' === (string) Mobo_Core_Settings::get( self::OPTION_NOTIFIED, '0' );
		} finally {
			Mobo_Core_Lock::release( $lock_name, $lock );
		}
	}

	/**
	 * Current dashboard state.
	 *
	 * @return array
	 */
	public static function get_status() {
		$last_balance = Mobo_Core_Settings::get( self::OPTION_LAST_BALANCE, null );
		$has_balance  = null !== $last_balance && false !== $last_balance && '' !== (string) $last_balance;
		$fence_at     = self::get_delivery_fence_at();
		$notified     = self::is_notified();

		return array(
			'supported'       => self::is_wallet_supported_for_current_connection(),
			'runtimeReady'    => self::is_wallet_runtime_ready_for_current_connection(),
			'enabled'         => Mobo_Core_Settings::enabled( 'mobo_core_wallet_alert_enabled', '0' ),
			'threshold'       => Mobo_Core_Settings::get_int( 'mobo_core_wallet_alert_threshold', 0, 0, PHP_INT_MAX ),
			'hasBalance'      => $has_balance,
			'lastBalance'     => $has_balance ? $last_balance : null,
			'lastCheckedAt'   => absint( Mobo_Core_Settings::get( self::OPTION_LAST_CHECKED_AT, 0 ) ),
			'lastOrderId'     => absint( Mobo_Core_Settings::get( self::OPTION_LAST_ORDER_ID, 0 ) ),
			'lastError'       => sanitize_text_field( (string) Mobo_Core_Settings::get( self::OPTION_LAST_ERROR, '' ) ),
			'notified'        => $notified,
			'uncertain'       => $fence_at > 0 && ! $notified,
			'uncertainAt'     => $fence_at,
			'notifiedAt'      => absint( Mobo_Core_Settings::get( self::OPTION_NOTIFIED_AT, 0 ) ),
			'notifiedBalance' => Mobo_Core_Settings::get( self::OPTION_NOTIFIED_BALANCE, null ),
			'smsLastAttemptAt' => absint( Mobo_Core_Settings::get( self::OPTION_SMS_LAST_ATTEMPT_AT, 0 ) ),
			'smsLastSuccessAt' => absint( Mobo_Core_Settings::get( self::OPTION_SMS_LAST_SUCCESS_AT, 0 ) ),
			'smsLastResult'    => sanitize_key( (string) Mobo_Core_Settings::get( self::OPTION_SMS_LAST_RESULT, '' ) ),
			'smsLastError'     => sanitize_text_field( (string) Mobo_Core_Settings::get( self::OPTION_SMS_LAST_ERROR, '' ) ),
			'testSmsLastAttemptAt' => absint( Mobo_Core_Settings::get( self::OPTION_TEST_SMS_LAST_ATTEMPT_AT, 0 ) ),
			'testSmsLastSuccessAt' => absint( Mobo_Core_Settings::get( self::OPTION_TEST_SMS_LAST_SUCCESS_AT, 0 ) ),
			'testSmsLastResult'    => sanitize_key( (string) Mobo_Core_Settings::get( self::OPTION_TEST_SMS_LAST_RESULT, '' ) ),
			'testSmsLastError'     => sanitize_text_field( (string) Mobo_Core_Settings::get( self::OPTION_TEST_SMS_LAST_ERROR, '' ) ),
		);
	}
}
