<?php
/**
 * REST controller.
 *
 * External C# runner uses:
 * - /sync/start
 * - /sync/run
 * - /sync/status
 * - /sync/cancel
 *
 * Webhook sender uses:
 * - /webhook
 *
 * PHP 7.4 compatible.
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
	 * Register REST routes.
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
			'/sync/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_product_sync' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/sync/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_product_sync' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/sync/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_product_sync_status' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/sync/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_product_sync' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);
	}

	/**
	 * Check external security header.
	 *
	 * Required header:
	 * X-SEC: configured security code
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
	 * Receive webhook, store it, and automatically try to process queue.
	 *
	 * This keeps webhook fire-and-forget:
	 * - payload is stored first
	 * - queue processing is best-effort
	 * - if host is weak, remaining files stay queued
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

		/*
		 * Auto-process a small part of the queue.
		 * This should not be relied on for long work; C# may still call /webhook/run.
		 */
		$process_result = $queue->process();

		return rest_ensure_response(
			array(
				'success' => true,
				'status'  => 'accepted',
				'queue'   => array(
					'processed' => isset( $process_result['processed'] ) ? absint( $process_result['processed'] ) : 0,
					'failed'    => isset( $process_result['failed'] ) ? absint( $process_result['failed'] ) : 0,
					'remaining' => ! empty( $process_result['remainingFile'] ),
				),
			)
		);
	}

	/**
	 * Run webhook queue.
	 *
	 * This endpoint is for C# automatic runner, not for manual UI usage.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function run_webhook_queue( $request ) {
		$queue = new Mobo_Core_Webhook_Queue();

		return rest_ensure_response( $queue->process() );
	}

	/**
	 * Start product sync from C#.
	 *
	 * Optional JSON body:
	 * {
	 *   "syncId": "..."
	 * }
	 *
	 * If another sync is already running, this endpoint does not silently overwrite it
	 * unless the previous sync is done/cancelled/idle.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function start_product_sync( $request ) {
		$lock = Mobo_Core_Lock::acquire( 'manual_sync_start', 20 );

		if ( false === $lock ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'status'  => 'locked',
					'message' => 'Sync start is locked.',
				)
			);
		}

		try {
			$sync          = new Mobo_Core_Product_Sync();
			$current_state = $sync->get_manual_sync_status();

			if ( ! empty( $current_state['isRunning'] ) ) {
				$result = array(
					'success' => false,
					'status'  => 'running',
					'message' => 'Product sync is already running.',
					'data'    => $current_state,
				);
			} else {
				$params  = $request->get_json_params();
				$sync_id = '';

				if ( is_array( $params ) && isset( $params['syncId'] ) ) {
					$sync_id = sanitize_text_field( (string) $params['syncId'] );
				}

				$result = $sync->start_manual_sync( $sync_id, 'external' );
			}
		} finally {
			Mobo_Core_Lock::release( 'manual_sync_start', $lock );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Run one product sync step.
	 *
	 * C# should call this repeatedly until /sync/status says isDone = true.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function run_product_sync( $request ) {
		$lock = Mobo_Core_Lock::acquire( 'manual_sync', 30 );

		if ( false === $lock ) {
			$sync = new Mobo_Core_Product_Sync();

			return rest_ensure_response(
				array(
					'success' => false,
					'status'  => 'locked',
					'message' => 'Product sync is locked.',
					'data'    => $sync->get_manual_sync_status(),
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

	/**
	 * Get product sync status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_product_sync_status( $request ) {
		$sync = new Mobo_Core_Product_Sync();

		return rest_ensure_response(
			array(
				'success' => true,
				'status'  => 'ok',
				'data'    => $sync->get_manual_sync_status(),
			)
		);
	}

	/**
	 * Cancel product sync.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function cancel_product_sync( $request ) {
		$sync = new Mobo_Core_Product_Sync();

		return rest_ensure_response( $sync->cancel_manual_sync() );
	}
}