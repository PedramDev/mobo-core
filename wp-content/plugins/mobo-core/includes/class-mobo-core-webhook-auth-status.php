<?php
/**
 * Stores the last Portal-to-WordPress webhook credential test result.
 *
 * No secret value is persisted in this status record.
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Webhook_Auth_Status {

	const OPTION_KEY = 'mobo_core_webhook_auth_status';

	/**
	 * Record a credential test result.
	 *
	 * @param string $status  valid|invalid|missing|misconfigured.
	 * @param string $source  Request source.
	 * @param string $message Human-readable message.
	 * @return array
	 */
	public static function record( $status, $source = 'portal', $message = '' ) {
		$status = sanitize_key( (string) $status );
		if ( ! in_array( $status, array( 'valid', 'invalid', 'missing', 'misconfigured' ), true ) ) {
			$status = 'unknown';
		}

		$data = array(
			'schemaVersion' => 1,
			'status'        => $status,
			'source'        => sanitize_key( (string) $source ),
			'checkedAt'     => time(),
			'message'       => sanitize_text_field( (string) $message ),
			'pluginVersion' => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
		);

		update_option( self::OPTION_KEY, $data, false );
		return $data;
	}

	/**
	 * Return the last safe credential-test status.
	 *
	 * @return array
	 */
	public static function get_status() {
		$data = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		return array(
			'schemaVersion' => 1,
			'status'        => isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'unknown',
			'source'        => isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : '',
			'checkedAt'     => isset( $data['checkedAt'] ) ? absint( $data['checkedAt'] ) : 0,
			'message'       => isset( $data['message'] ) ? sanitize_text_field( (string) $data['message'] ) : '',
			'pluginVersion' => isset( $data['pluginVersion'] ) ? sanitize_text_field( (string) $data['pluginVersion'] ) : '',
		);
	}

	/**
	 * Whether this request is an explicit Portal credential test or heartbeat probe.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function should_record_request( $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return false;
		}

		$test = (string) $request->get_header( 'x-mobo-webhook-test' );
		$heartbeat = (string) $request->get_header( 'x-mobo-heartbeat-request' );
		$route = (string) $request->get_route();

		return '1' === $test || '1' === $heartbeat || false !== strpos( $route, '/portal/webhook-test' );
	}
}
