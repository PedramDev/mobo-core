<?php
/**
 * REST controller.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Rest_Controller {

	/**
	 * Init REST routes.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'mobo-core/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive_webhook' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/webhook/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_webhook_queue' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/sync/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_manual_sync' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);
	}

	/**
	 * Check X-SEC header.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_security( $request ) {
		$expected = (string) get_option( 'mobo_core_security_code', '' );

		if ( '' === $expected ) {
			return new WP_Error(
				'mobo_core_security_missing',
				'Security code is not configured.',
				array( 'status' => 403 )
			);
		}

		$provided = (string) $request->get_header( 'X-SEC' );

		if ( '' === $provided ) {
			$provided = (string) $request->get_header( 'x-sec' );
		}

		if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error(
				'mobo_core_unauthorized',
				'Unauthorized.',
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Receive webhook and store it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive_webhook( $request ) {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'mobo_core_invalid_payload',
				'Invalid JSON payload.',
				array( 'status' => 400 )
			);
		}

		$queue = new Mobo_Core_Webhook_Queue();
		$file  = $queue->store( $payload );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'status'  => 'accepted',
			)
		);
	}

	/**
	 * Run queue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function run_webhook_queue( $request ) {
		$queue = new Mobo_Core_Webhook_Queue();

		return rest_ensure_response( $queue->process() );
	}

	/**
	 * Run manual sync step.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function run_manual_sync( $request ) {
		$lock = Mobo_Core_Lock::acquire( 'manual_sync', 30 );

		if ( false === $lock ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'status'  => 'locked',
					'message' => 'Manual sync is locked.',
				)
			);
		}

		try {
			$sync   = new Mobo_Core_Product_Sync();
			$result = $sync->run_manual_sync_step();
		} finally {
			Mobo_Core_Lock::release( 'manual_sync', $lock );
		}

		return rest_ensure_response( $result );
	}
}