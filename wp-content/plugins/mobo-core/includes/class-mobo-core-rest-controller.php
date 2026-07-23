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
			'/categories/ensure-sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ensure_categories_sync' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/cron/run',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'run_real_cron' ),
				'permission_callback' => array( $this, 'check_cron_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/cron/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_real_cron_status' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/worker/run',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'run_self_worker' ),
				'permission_callback' => array( $this, 'check_cron_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/worker/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_self_worker_status' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_health' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);


		register_rest_route(
			'mobo-core/v1',
			'/heartbeat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_heartbeat' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);


		register_rest_route(
			'mobo-core/v1',
			'/upgrade/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_upgrade_status' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/upgrade/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply_remote_upgrade' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/health/report-now',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'send_health_report_now' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

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
		$expected = Mobo_Core_Settings::normalize_security_code( get_option( 'mobo_core_security_code', '' ) );

		if ( '' === $expected ) {
			return new WP_Error(
				'mobo_core_security_missing',
				'Security code is not configured.',
				array( 'status' => 403 )
			);
		}

		if ( ! Mobo_Core_Settings::is_valid_security_code( $expected ) ) {
			return new WP_Error(
				'mobo_core_security_invalid',
				'Configured security code is not a valid visible-ASCII HTTP header value.',
				array( 'status' => 503 )
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
	 * Check real cron token.
	 *
	 * cPanel curl usually cannot send custom headers reliably for non-technical users,
	 * so this endpoint accepts a query/body token and also supports X-SEC as fallback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_cron_security( $request ) {
		$expected = (string) get_option( 'mobo_core_cron_token', '' );

		if ( '' === trim( $expected ) ) {
			return new WP_Error(
				'mobo_core_cron_token_missing',
				'Cron token is not configured.',
				array( 'status' => 403 )
			);
		}

		$provided = (string) $request->get_param( 'token' );

		if ( '' === $provided ) {
			$provided = (string) $request->get_header( 'X-SEC' );
		}

		if ( '' === $provided ) {
			$provided = (string) $request->get_header( 'x-sec' );
		}

		if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error(
				'mobo_core_cron_unauthorized',
				'Unauthorized cron request.',
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Ensure categories are synced if due.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function ensure_categories_sync( WP_REST_Request $request ) {
		$sync_id = sanitize_text_field( (string) $request->get_param( 'syncId' ) );
		$force   = $this->to_bool( $request->get_param( 'force' ) );

		$product_sync = new Mobo_Core_Product_Sync();
		$result       = $product_sync->ensure_categories_synced_if_due( $sync_id, $force );

		return rest_ensure_response( $result );
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

		$process_result = array(
			'processed'     => 0,
			'failed'        => 0,
			'remainingFile' => true,
		);

		if ( Mobo_Core_Settings::enabled( 'mobo_core_process_webhook_on_receive', '0' ) ) {
			$process_result = $queue->process();
		}

		$self_kick = array(
			'success' => true,
			'status'  => 'skipped',
			'message' => 'Self runner is not available.',
		);

		if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
			$self_kick = Mobo_Core_Self_Runner::kick( 'webhook', false );
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'status'   => 'accepted',
				'queue'    => array(
					'processed' => isset( $process_result['processed'] ) ? absint( $process_result['processed'] ) : 0,
					'failed'    => isset( $process_result['failed'] ) ? absint( $process_result['failed'] ) : 0,
					'remaining' => ! empty( $process_result['remainingFile'] ) || ! empty( $process_result['remainingTable'] ),
				),
				'selfKick' => $self_kick,
			)
		);
	}


	/**
	 * Run one real-cron slice.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function run_real_cron( $request ) {
		$runner = new Mobo_Core_Cron_Runner();

		return rest_ensure_response( $runner->run( 'real-cron' ) );
	}

	/**
	 * Return real-cron status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_real_cron_status( $request ) {
		return rest_ensure_response(
			array(
				'success' => true,
				'status'  => 'ok',
				'data'    => Mobo_Core_Cron_Runner::get_status(),
			)
		);
	}

	/**
	 * Run one local self-worker slice.
	 *
	 * This is called by the plugin itself through a non-blocking loopback request.
	 * It uses the same bounded runner as /cron/run, but records separate self-runner
	 * status and optionally chains another local slice if real progress was made.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function run_self_worker( $request ) {
		$source = sanitize_key( (string) $request->get_param( 'source' ) );

		if ( '' === $source ) {
			$source = 'self-worker';
		}

		$runner = new Mobo_Core_Cron_Runner();
		$result = $runner->run( $source );

		if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
			/*
			 * Continuation dispatch is centralized inside Mobo_Core_Cron_Runner so
			 * direct PHP cron, /cron/run and the self worker behave identically and
			 * cannot dispatch duplicate continuation requests.
			 */
			Mobo_Core_Self_Runner::record_run_result( $result );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Return local self-worker status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_self_worker_status( $request ) {
		$data = class_exists( 'Mobo_Core_Self_Runner' ) ? Mobo_Core_Self_Runner::get_status() : array();

		return rest_ensure_response(
			array(
				'success' => true,
				'status'  => 'ok',
				'data'    => $data,
			)
		);
	}

	/**
	 * Return local site health for MoboCore probe.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_health( $request ) {
		$reporter = new Mobo_Core_Health_Reporter();

		return rest_ensure_response( $reporter->get_local_status() );
	}


	/**
	 * Wake the site, execute one bounded shared-engine slice and return health.
	 *
	 * Portal calls this endpoint periodically. It is intentionally POST-only and
	 * explicitly non-cacheable so a CDN/page cache cannot answer without booting
	 * WordPress/PHP. The work is executed by the same cron runner used by cPanel
	 * and the self worker; no parallel sync implementation is introduced.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function run_heartbeat( $request ) {
		$started_at = microtime( true );
		$attempt_at = time();

		update_option( 'mobo_core_portal_heartbeat_last_attempt_at', $attempt_at, false );

		$work = array(
			'success' => false,
			'status'  => 'runner-unavailable',
		);

		$heartbeat_budget = Mobo_Core_Settings::get_int( 'mobo_core_heartbeat_time_budget_seconds', 12, 5, 25 );
		$remote_timeout  = Mobo_Core_Settings::get_int( 'mobo_core_heartbeat_remote_timeout_seconds', 10, 5, 20 );
		$overrides       = array(
			'mobo_core_api_request_timeout_seconds'       => $remote_timeout,
			'mobo_core_payload_pull_timeout_seconds'      => $remote_timeout,
			'mobo_core_real_cron_time_budget_seconds'     => $heartbeat_budget,
			'mobo_core_images_per_run'                    => 1,
			'mobo_core_image_refresh_per_run'             => 1,
			'mobo_core_webhook_files_per_run'             => 2,
			'mobo_core_reprice_batch_size'                => 5,
		);

		foreach ( $overrides as $key => $value ) {
			Mobo_Core_Settings::set_runtime_override( $key, $value );
		}

		try {
			$runner = new Mobo_Core_Cron_Runner();
			$work   = $runner->run(
				'portal-heartbeat',
				false,
				array(
					'maxTimeBudgetSeconds' => $heartbeat_budget,
					'maxRounds'             => Mobo_Core_Settings::get_int( 'mobo_core_heartbeat_max_rounds', 2, 1, 10 ),
					'productStepsPerRound'  => 1,
				)
			);
		} catch ( Throwable $e ) {
			$work = array(
				'success'        => false,
				'status'         => 'heartbeat-runner-exception',
				'message'        => $e->getMessage(),
				'exceptionClass' => get_class( $e ),
			);
		} finally {
			foreach ( array_keys( $overrides ) as $key ) {
				Mobo_Core_Settings::clear_runtime_override( $key );
			}
		}

		$reporter = new Mobo_Core_Health_Reporter();
		$health   = $reporter->get_local_status();
		$duration = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
		$status   = ! empty( $work['success'] ) ? 'online' : 'online-worker-warning';

		$result = array(
			'success'       => true,
			'status'        => $status,
			'heartbeatAt'   => gmdate( 'c' ),
			'durationMs'    => $duration,
			'pluginVersion' => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
			'worker'        => $this->compact_heartbeat_work( $work ),
			'data'          => isset( $health['data'] ) && is_array( $health['data'] ) ? $health['data'] : array(),
			'lastReport'    => isset( $health['lastReport'] ) && is_array( $health['lastReport'] ) ? $health['lastReport'] : array(),
		);

		$stored_result = array(
			'success'       => $result['success'],
			'status'        => $result['status'],
			'heartbeatAt'   => $result['heartbeatAt'],
			'durationMs'    => $result['durationMs'],
			'pluginVersion' => $result['pluginVersion'],
			'worker'        => $result['worker'],
		);

		update_option( 'mobo_core_portal_heartbeat_last_success_at', time(), false );
		update_option( 'mobo_core_portal_heartbeat_last_result', $stored_result, false );

		$response = new WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		$response->header( 'X-Mobo-Heartbeat', '1' );

		return $response;
	}

	/**
	 * Keep the heartbeat payload bounded while preserving operational evidence.
	 *
	 * @param array $work Runner result.
	 * @return array
	 */
	private function compact_heartbeat_work( $work ) {
		if ( ! is_array( $work ) ) {
			return array(
				'success' => false,
				'status'  => 'invalid-result',
			);
		}

		$runner = isset( $work['runner'] ) && is_array( $work['runner'] ) ? $work['runner'] : array();

		return array(
			'success'           => ! empty( $work['success'] ),
			'status'            => isset( $work['status'] ) ? sanitize_key( (string) $work['status'] ) : 'unknown',
			'executedAt'        => isset( $work['executedAt'] ) ? absint( $work['executedAt'] ) : 0,
			'needsContinuation' => ! empty( $work['needsContinuation'] ),
			'message'           => isset( $work['message'] ) ? sanitize_text_field( (string) $work['message'] ) : '',
			'runner'            => array(
				'elapsedMs'       => isset( $runner['elapsedMs'] ) ? absint( $runner['elapsedMs'] ) : 0,
				'rounds'          => isset( $runner['rounds'] ) ? absint( $runner['rounds'] ) : 0,
				'stopReason'      => isset( $runner['stopReason'] ) ? sanitize_key( (string) $runner['stopReason'] ) : '',
				'madeProgress'    => ! empty( $runner['madeProgress'] ),
				'hasImmediateWork' => ! empty( $runner['hasImmediateWork'] ),
			),
			'webhookQueue'      => isset( $work['webhookQueue'] ) && is_array( $work['webhookQueue'] ) ? $work['webhookQueue'] : array(),
			'productStatus'     => isset( $work['productStatus'] ) && is_array( $work['productStatus'] ) ? $work['productStatus'] : array(),
			'reconciliation'    => isset( $work['reconciliation'] ) && is_array( $work['reconciliation'] ) ? $work['reconciliation'] : array(),
		);
	}


	/**
	 * Return Portal-driven plugin upgrade state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_upgrade_status( $request ) {
		return rest_ensure_response( Mobo_Core_Remote_Updater::get_status() );
	}

	/**
	 * Download, verify and install one release selected in Portal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_remote_upgrade( $request ) {
		$result = Mobo_Core_Remote_Updater::apply( $request );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Force-send health report to MoboCore.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function send_health_report_now( $request ) {
		$reporter = new Mobo_Core_Health_Reporter();

		return rest_ensure_response(
			array(
				'success' => true,
				'status'  => 'ok',
				'data'    => $reporter->send_report( 'manual', true ),
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
		try {
			$queue = new Mobo_Core_Webhook_Queue();
			return rest_ensure_response( $queue->process() );
		} catch ( Throwable $e ) {
			$result = array(
				'success'        => false,
				'status'         => 'webhook-queue-exception',
				'processed'      => 0,
				'failed'         => 1,
				'remaining'      => true,
				'message'        => $e->getMessage(),
				'exceptionClass' => get_class( $e ),
				'file'           => $e->getFile(),
				'line'           => $e->getLine(),
			);

			update_option( 'mobo_core_webhook_queue_last_result', $result, false );
			update_option( 'mobo_core_webhook_queue_last_attempt_at', time(), false );

			return rest_ensure_response( $result );
		}
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
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				return rest_ensure_response( Mobo_Core_Upgrade_Coordinator::paused_result( 'sync-start' ) );
			}

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

		if ( ! empty( $result['success'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			$result['selfKick'] = Mobo_Core_Self_Runner::kick( 'sync-start', false );
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
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				return rest_ensure_response( Mobo_Core_Upgrade_Coordinator::paused_result( 'sync-run' ) );
			}

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

	private function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value === 1;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return ! empty( $value );
	}
}