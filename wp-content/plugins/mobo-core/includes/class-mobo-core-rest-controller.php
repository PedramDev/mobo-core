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
		add_filter( 'rest_post_dispatch', array( $this, 'harden_rest_response_cache' ), 10, 3 );
	}

	/**
	 * Prevent authenticated Mobo REST responses from being stored by reverse
	 * proxies/CDNs/browser caches. This applies to the whole namespace, including
	 * Source-scoped settings/status responses and mutating acknowledgements.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public function harden_rest_response_cache( $response, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request || 0 !== strpos( (string) $request->get_route(), '/mobo-core/v1/' ) ) {
			return $response;
		}

		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		}

		return $response;
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
				'permission_callback' => array( $this, 'check_installation_security' ),
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
				'permission_callback' => array( $this, 'check_installation_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_health' ),
				'permission_callback' => array( $this, 'check_installation_security' ),
			)
		);


		register_rest_route(
			'mobo-core/v1',
			'/heartbeat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_heartbeat' ),
				'permission_callback' => array( $this, 'check_installation_security' ),
			)
		);


		register_rest_route(
			'mobo-core/v1',
			'/upgrade/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_upgrade_status' ),
				'permission_callback' => array( $this, 'check_installation_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/upgrade/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply_remote_upgrade' ),
				'permission_callback' => array( $this, 'check_installation_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/health/report-now',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'send_health_report_now' ),
				'permission_callback' => array( $this, 'check_installation_security' ),
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
			'/connection/provision',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'provision_connection' ),
				'permission_callback' => array( $this, 'check_provisioning_signature' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/license-installation/challenge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'license_installation_challenge' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/portal/webhook-test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_webhook_connection' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/portal/revenue-summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_revenue_summary' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/portal/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_portal_settings' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/portal/operations/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_portal_operation' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/portal/operations/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_portal_operation_status' ),
				'permission_callback' => array( $this, 'check_security' ),
			)
		);

		register_rest_route(
			'mobo-core/v1',
			'/portal/operations/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_portal_operation' ),
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
		/* REST permission callbacks can be invoked more than once in one PHP
		 * process. Failed authentication must never inherit a prior Source context. */
		if ( class_exists( 'Mobo_Core_Connection_Context' ) ) {
			Mobo_Core_Connection_Context::clear();
		}
		$record_test = class_exists( 'Mobo_Core_Webhook_Auth_Status' ) && Mobo_Core_Webhook_Auth_Status::should_record_request( $request );
		$provided = (string) $request->get_header( 'X-SEC' );
		if ( '' === $provided ) {
			$provided = (string) $request->get_header( 'x-sec' );
		}
		$webhook_id = (string) $request->get_header( 'X-Mobo-Webhook-Id' );
		if ( '' === $webhook_id ) {
			$webhook_id = (string) $request->get_header( 'x-mobo-webhook-id' );
		}

		/* Connection-aware path. WebHookId selects a connection; X-SEC authenticates
		 * that exact row. A single upgraded installation may adopt its first WebHookId
		 * only after X-SEC matches the migrated Default Connection. */
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists() ) {
			$connection = Mobo_Core_Connection_Repository::authenticate_inbound( $webhook_id, $provided );
			if ( is_wp_error( $connection ) ) {
				if ( $record_test ) {
					$status = 'mobo_core_security_missing' === $connection->get_error_code() ? 'missing' : ( 'mobo_core_security_invalid' === $connection->get_error_code() ? 'misconfigured' : 'invalid' );
					$this->record_webhook_auth_status_for_candidate( $webhook_id, $status, $connection->get_error_message() );
				}
				return $connection;
			}

			$connection_id = absint( $connection['id'] ?? 0 );
			if ( $connection_id <= 0 ) {
				return new WP_Error( 'mobo_core_connection_resolution_failed', 'Resolved connection is invalid.', array( 'status' => 503 ) );
			}
			if ( class_exists( 'Mobo_Core_Connection_Context' ) ) {
				Mobo_Core_Connection_Context::set_current_connection_id( $connection_id );
			}
			if ( $record_test ) {
				Mobo_Core_Webhook_Auth_Status::record( 'valid', 'portal', 'کد وب‌هوک Portal و افزونه برای Connection صحیح معتبر است.' );
			}
			return true;
		}

		/* Terminal multi-Connection runtime: inbound REST authentication requires the
		 * durable Connection table. Falling back to the legacy installation-wide
		 * X-SEC while that authority is missing would authenticate a request without
		 * proving its owner and could cross Source boundaries. Provisioning uses its
		 * separate signed-token endpoint and therefore does not need this fallback. */
		if ( $record_test ) {
			Mobo_Core_Webhook_Auth_Status::record( 'misconfigured', 'portal', 'ذخیره‌سازی Connection در دسترس نیست؛ احراز هویت Runtime تا بازیابی آن متوقف است.' );
		}
		return new WP_Error(
			'mobo_core_connection_storage_unavailable',
			'Connection authentication storage is unavailable.',
			array( 'status' => 503 )
		);
	}



	/**
	 * Authorize an installation-global REST surface.
	 *
	 * Source-scoped credentials authenticate only their own Source operations.
	 * Site-wide Health/Worker visibility and plugin upgrades are controlled by the
	 * enabled Default Connection, which acts as the Installation Controller.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_installation_security( $request ) {
		$authenticated = $this->check_security( $request );
		if ( is_wp_error( $authenticated ) ) {
			return $authenticated;
		}

		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::table_exists() ) {
			return new WP_Error(
				'mobo_core_installation_controller_unavailable',
				'Installation controller is unavailable.',
				array( 'status' => 503 )
			);
		}

		$current_id = class_exists( 'Mobo_Core_Connection_Context' )
			? absint( Mobo_Core_Connection_Context::get_current_connection_id() )
			: 0;
		/* The administrative request-local Default cache may predate an admin
		 * change. Revalidate the authenticated owner, including its enabled and
		 * installation-controller role, against the durable row. */
		$fresh_owner = $current_id > 0 ? Mobo_Core_Connection_Repository::get_by_id_fresh( $current_id ) : null;
		$controller = Mobo_Core_Connection_Repository::get_default_fresh();
		if ( $current_id <= 0 || ! is_array( $fresh_owner ) || empty( $fresh_owner['is_enabled'] ) || ! is_array( $controller ) ) {
			return new WP_Error(
				'mobo_core_installation_controller_unavailable',
				'Installation controller is unavailable.',
				array( 'status' => 503 )
			);
		}

		/* If bad historical data has two is_default rows, only the same
		 * canonical controller returned by fresh ordered lookup may exercise
		 * installation-wide authority. */
		if ( empty( $fresh_owner['is_default'] ) || $current_id !== absint( $controller['id'] ?? 0 ) ) {
			return new WP_Error(
				'mobo_core_installation_scope_forbidden',
				'This route is restricted to the installation controller Connection.',
				array( 'status' => 403 )
			);
		}

		return true;
	}



	/**
	 * Record explicit webhook-auth diagnostics under the Connection identified by
	 * WebHookId without treating that identifier as authentication. The candidate
	 * context is used only for the scoped diagnostic write; the request still fails
	 * unless authenticate_inbound() has independently validated X-SEC.
	 *
	 * @param string $webhook_id WebHookId header value.
	 * @param string $status     valid|invalid|missing|misconfigured.
	 * @param string $message    Safe diagnostic message.
	 * @return void
	 */
	private function record_webhook_auth_status_for_candidate( $webhook_id, $status, $message ) {
		$callback = static function () use ( $status, $message ) {
			Mobo_Core_Webhook_Auth_Status::record( $status, 'portal', $message );
		};

		$candidate = null;
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists() ) {
			$normalized_webhook_id = Mobo_Core_Connection_Repository::normalize_webhook_id( $webhook_id );
			if ( '' !== $normalized_webhook_id ) {
				$candidate = Mobo_Core_Connection_Repository::get_by_webhook_id( $normalized_webhook_id );
			} elseif ( 1 === Mobo_Core_Connection_Repository::count_enabled() ) {
				/* With exactly one enabled Connection the diagnostic owner is
				 * unambiguous even when that Connection is not the Default. This
				 * does not authenticate the request; X-SEC remains mandatory. */
				$enabled = Mobo_Core_Connection_Repository::list_enabled();
				$candidate = 1 === count( $enabled ) ? $enabled[0] : null;
			}
		}

		$connection_id = is_array( $candidate ) ? absint( $candidate['id'] ?? 0 ) : 0;
		if ( $connection_id > 0 && class_exists( 'Mobo_Core_Connection_Context' ) ) {
			Mobo_Core_Connection_Context::run( $connection_id, $callback );
			return;
		}

		call_user_func( $callback );
	}



	/**
	 * Authenticate .NET -> WordPress Connection provisioning with the License Token
	 * already stored by the customer. No Source URL or provider is needed locally.
	 *
	 * Signature = HMAC-SHA256(token, timestamp + "\n" + nonce + "\n" + rawBody)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_provisioning_signature( $request ) {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::table_exists() ) {
			return new WP_Error( 'mobo_core_provision_storage_missing', 'Connection storage is unavailable.', array( 'status' => 503 ) );
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$token = Mobo_Core_Settings::normalize_opaque_secret( $params['token'] ?? '', 191 );
		if ( '' === $token || ! is_array( Mobo_Core_Connection_Repository::get_by_license_token( $token ) ) ) {
			return new WP_Error( 'mobo_core_provision_unauthorized', 'Unauthorized.', array( 'status' => 401 ) );
		}
		$timestamp = trim( (string) $request->get_header( 'X-Mobo-Provision-Timestamp' ) );
		$nonce = trim( (string) $request->get_header( 'X-Mobo-Provision-Nonce' ) );
		$signature = strtolower( trim( (string) $request->get_header( 'X-Mobo-Provision-Signature' ) ) );
		if ( ! ctype_digit( $timestamp ) || '' === $nonce || strlen( $nonce ) > 128 || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return new WP_Error( 'mobo_core_provision_signature_invalid', 'Provisioning signature headers are invalid.', array( 'status' => 401 ) );
		}
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return new WP_Error( 'mobo_core_provision_signature_expired', 'Provisioning request timestamp is outside the allowed window.', array( 'status' => 401 ) );
		}
		$raw = (string) $request->get_body();
		$expected = hash_hmac( 'sha256', $timestamp . "\n" . $nonce . "\n" . $raw, $token );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'mobo_core_provision_unauthorized', 'Unauthorized.', array( 'status' => 401 ) );
		}

		/* Replay reservation must be atomic. The expired-row cleanup must ALSO be
		 * conditional: delete_option() after a stale get_option() could delete a newer
		 * nonce reservation made by another concurrent valid signed request. */
		$nonce_key = '_mobo_provision_nonce_' . hash( 'sha256', $token . "\n" . $nonce );
		$expires_at = time() + ( 10 * MINUTE_IN_SECONDS );
		global $wpdb;
		$existing_raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $nonce_key ) );
		if ( null !== $existing_raw ) {
			if ( ! ctype_digit( (string) $existing_raw ) || (int) $existing_raw >= time() ) {
				return new WP_Error( 'mobo_core_provision_replay', 'Provisioning request was already used.', array( 'status' => 409 ) );
			}
			$deleted = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s",
				$nonce_key, (string) $existing_raw
			) );
			if ( 1 !== $deleted ) {
				return new WP_Error( 'mobo_core_provision_replay', 'Provisioning request was already used.', array( 'status' => 409 ) );
			}
			/* Direct SQL bypasses WordPress's option caches. Invalidate the exact
			 * key before using its atomic unique-index INSERT via add_option(). */
			wp_cache_delete( $nonce_key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		if ( ! add_option( $nonce_key, $expires_at, '', false ) ) {
			return new WP_Error( 'mobo_core_provision_replay', 'Provisioning request was already used.', array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * Apply server-owned Source/Provider routing to the Token-selected Connection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function provision_connection( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$token = Mobo_Core_Settings::normalize_opaque_secret( $params['token'] ?? '', 191 );
		/* 10.34.77: provisioning a NEW (non-Default) Connection is refused until the
		 * Default Connection's one-way storage retirement has completed. The Default
		 * Connection itself can always be (re)provisioned, and already provisioned
		 * extra Connections keep running; this only stops the topology from growing
		 * mid-migration. Retryable: the Backend may try again later. */
		$target = '' !== $token ? Mobo_Core_Connection_Repository::get_by_license_token( $token ) : null;
		if ( is_array( $target ) && empty( $target['is_default'] ) && ! Mobo_Core_Connection_Repository::is_provisioned( $target )
			&& class_exists( 'Mobo_Core_Migration' ) && Mobo_Core_Migration::new_connections_blocked() ) {
			return new WP_Error(
				'mobo_core_connection_migration_in_progress',
				'New Connections cannot be provisioned until the store finishes its storage migration. Retry later.',
				array( 'status' => 409, 'retryAfter' => 900 )
			);
		}
		/* Optional X-SEC proof: required only to move an already-provisioned
		 * Connection to another Backend host. Today's Backend client does not send
		 * it and is unaffected while its configured Backend host is unchanged. */
		$result = Mobo_Core_Connection_Repository::provision_by_token(
			$token,
			$params,
			array( 'security_code' => (string) $request->get_header( 'X-SEC' ) )
		);
		if ( is_wp_error( $result ) ) { return $result; }
		$money_status = class_exists( 'Mobo_Core_Money_Policy' )
			? Mobo_Core_Money_Policy::compatibility_status_for_row( $result )
			: array( 'compatible' => false, 'code' => 'money_policy_unavailable' );
		return rest_ensure_response(
			array(
				'success' => true,
				'status' => ! empty( $result['is_enabled'] ) ? 'active' : 'disabled',
				'connection' => array(
					'id' => absint( $result['id'] ?? 0 ),
					'source_key' => sanitize_key( (string) ( $result['source_key'] ?? '' ) ),
					'provider_key' => class_exists( 'Mobo_Core_Provider_Registry' ) ? Mobo_Core_Provider_Registry::normalize_key( $result['provider_key'] ?? '' ) : '',
					'provisioned' => Mobo_Core_Connection_Repository::is_provisioned( $result ),
					'money_unit' => class_exists( 'Mobo_Core_Money_Policy' ) ? Mobo_Core_Money_Policy::normalize_unit( $result['money_unit'] ?? '' ) : '',
					'money_fraction_digits' => class_exists( 'Mobo_Core_Money_Policy' ) ? Mobo_Core_Money_Policy::normalize_fraction_digits( $result['money_fraction_digits'] ?? -1 ) : -1,
					'money_price_representation' => class_exists( 'Mobo_Core_Money_Policy' ) ? Mobo_Core_Money_Policy::normalize_representation( $result['money_price_representation'] ?? '' ) : '',
					'money_contract_version' => class_exists( 'Mobo_Core_Money_Policy' ) ? Mobo_Core_Money_Policy::normalize_contract_version( $result['money_contract_version'] ?? 0 ) : 0,
					'money_compatible' => ! empty( $money_status['compatible'] ),
				),
			)
		);
	}


	/**
	 * Sign a Portal challenge to prove that registration controls this exact
	 * X-SEC protected WordPress installation. No private key material is exposed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function license_installation_challenge( $request ) {
		$challenge = sanitize_text_field( (string) $request->get_param( 'challenge' ) );
		$installation_id = sanitize_text_field( (string) $request->get_param( 'installationId' ) );
		$result = Mobo_Core_Portal_Request_Signer::create_challenge_response( $challenge, $installation_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Explicit Portal-to-WordPress webhook credential test.
	 *
	 * The permission callback validates X-SEC and records the result. This
	 * callback only returns a safe acknowledgement; no credential is exposed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function test_webhook_connection( $request ) {
		$status = class_exists( 'Mobo_Core_Webhook_Auth_Status' )
			? Mobo_Core_Webhook_Auth_Status::get_status()
			: array( 'status' => 'valid', 'checkedAt' => time() );

		return rest_ensure_response(
			array(
				'success' => true,
				'status'  => 'valid',
				'message' => 'کد وب‌هوک Portal و افزونه معتبر و یکسان است.',
				'data'    => array(
					'siteUrl'       => home_url( '/' ),
					'pluginVersion' => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
					'checkedAt'     => isset( $status['checkedAt'] ) ? absint( $status['checkedAt'] ) : time(),
					'credential'    => $status,
				),
			)
		);
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
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? Mobo_Core_Connection_Context::get_current_connection_id() : 0;
		$file  = $queue->store( $payload, $connection_id );

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
		if ( class_exists( 'Mobo_Core_Cron_Runner' ) ) {
			Mobo_Core_Cron_Runner::record_real_cron_hit();
		}

		$claim = class_exists( 'Mobo_Core_Self_Runner' )
			? Mobo_Core_Self_Runner::claim_worker_request( '' )
			: array( 'success' => true, 'token' => '' );

		if ( empty( $claim['success'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'status'  => isset( $claim['status'] ) ? sanitize_key( (string) $claim['status'] ) : 'dispatcher-locked',
					'message' => 'Another Mobo worker request is already active for this site.',
				),
				isset( $claim['httpStatus'] ) ? absint( $claim['httpStatus'] ) : 423
			);
		}

		$token  = isset( $claim['token'] ) ? (string) $claim['token'] : '';
		$runner = new Mobo_Core_Cron_Runner();
		try {
			$result = $runner->run( 'real-cron', false, array( 'suppressContinuationKick' => true, 'dispatcherToken' => $token, 'schedulerHitRecorded' => true ) );
		} finally {
			if ( '' !== $token && class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::release_worker_request( $token );
			}
		}

		if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
			$pending = Mobo_Core_Self_Runner::consume_pending_dispatch();
			if ( $pending || Mobo_Core_Self_Runner::should_continue_after_result( $result ) ) {
				$result['continuationKick'] = Mobo_Core_Self_Runner::kick( 'cron-continuation', true );
			}
		}

		return rest_ensure_response( $result );
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
				'data'    => method_exists( 'Mobo_Core_Cron_Runner', 'get_external_status' )
					? Mobo_Core_Cron_Runner::get_external_status()
					: Mobo_Core_Cron_Runner::get_status(),
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

		$transferred = sanitize_text_field( (string) $request->get_header( 'x-mobo-dispatch-token' ) );
		$claim = class_exists( 'Mobo_Core_Self_Runner' )
			? Mobo_Core_Self_Runner::claim_worker_request( $transferred )
			: array( 'success' => true, 'token' => '' );

		if ( empty( $claim['success'] ) ) {
			$status = isset( $claim['status'] ) ? sanitize_key( (string) $claim['status'] ) : 'dispatcher-locked';
			$http   = isset( $claim['httpStatus'] ) ? absint( $claim['httpStatus'] ) : 423;
			return new WP_REST_Response(
				array(
					'success' => false,
					'status'  => $status,
					'message' => 409 === $http
						? 'The transferred worker lease is stale or no longer owned by this request.'
						: 'Another Mobo worker request is already active for this site.',
				),
				$http
			);
		}

		$token  = isset( $claim['token'] ) ? (string) $claim['token'] : '';
		$runner = new Mobo_Core_Cron_Runner();
		try {
			$result = $runner->run( $source, false, array( 'suppressContinuationKick' => true, 'dispatcherToken' => $token ) );
		} finally {
			if ( '' !== $token && class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::release_worker_request( $token );
			}
		}

		if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
			$pending = Mobo_Core_Self_Runner::consume_pending_dispatch();
			if ( $pending || Mobo_Core_Self_Runner::should_continue_after_result( $result ) ) {
				$result['continuationKick'] = Mobo_Core_Self_Runner::kick( 'worker-continuation', true );
			}
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

		$response = rest_ensure_response( $reporter->get_local_status( $this->health_may_include_license_token() ) );
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		}
		return $response;
	}

	/**
	 * The full License Token is returned only on a response whose request passed
	 * the X-SEC installation check in this request (check_security() sets the
	 * authenticated Connection context). Portal's health/heartbeat ingestion uses
	 * it for license/domain diagnostics. Every other path gets a last-4 mask.
	 *
	 * @return bool
	 */
	private function health_may_include_license_token() {
		return class_exists( 'Mobo_Core_Connection_Context' )
			&& absint( Mobo_Core_Connection_Context::get_current_connection_id() ) > 0;
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
		/* Capture X-SEC authentication before workers may switch Connection context. */
		$include_license_token = $this->health_may_include_license_token();

		Mobo_Core_Settings::update( 'mobo_core_portal_heartbeat_last_attempt_at', $attempt_at, false );

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

		$dispatch_claim = class_exists( 'Mobo_Core_Self_Runner' )
			? Mobo_Core_Self_Runner::claim_worker_request( '' )
			: array( 'success' => true, 'token' => '' );
		$dispatch_token = ! empty( $dispatch_claim['success'] ) && isset( $dispatch_claim['token'] ) ? (string) $dispatch_claim['token'] : '';

		try {
			if ( empty( $dispatch_claim['success'] ) ) {
				$work = array(
					'success'    => false,
					'status'     => isset( $dispatch_claim['status'] ) ? sanitize_key( (string) $dispatch_claim['status'] ) : 'dispatcher-locked',
					'httpStatus' => isset( $dispatch_claim['httpStatus'] ) ? absint( $dispatch_claim['httpStatus'] ) : 423,
					'message'    => 'Heartbeat skipped worker execution because another site worker owns the dispatcher.',
				);
			} else {
				$runner = new Mobo_Core_Cron_Runner();
				$work   = $runner->run(
					'portal-heartbeat',
					false,
					array(
						'maxTimeBudgetSeconds'    => $heartbeat_budget,
						'maxRounds'                => Mobo_Core_Settings::get_int( 'mobo_core_heartbeat_max_rounds', 2, 1, 10 ),
						'productStepsPerRound'     => 1,
						'suppressContinuationKick' => true,
						'dispatcherToken'          => $dispatch_token,
					)
				);
			}
		} catch ( Throwable $e ) {
			$this->record_rest_exception( 'heartbeat-runner', $e );
			$work = array(
				'success' => false,
				'status'  => 'heartbeat-runner-exception',
				'message' => 'Heartbeat worker failed. See local MoboCore diagnostics.',
			);
		} finally {
			if ( '' !== $dispatch_token && class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::release_worker_request( $dispatch_token );
			}
			foreach ( array_keys( $overrides ) as $key ) {
				Mobo_Core_Settings::clear_runtime_override( $key );
			}
		}

		if ( ! empty( $dispatch_claim['success'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			$pending = Mobo_Core_Self_Runner::consume_pending_dispatch();
			if ( $pending || Mobo_Core_Self_Runner::should_continue_after_result( $work ) ) {
				$work['continuationKick'] = Mobo_Core_Self_Runner::kick( 'heartbeat-continuation', true );
			}
		}

		$reporter = new Mobo_Core_Health_Reporter();
		$health   = $reporter->get_local_status( $include_license_token );
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

		Mobo_Core_Settings::update( 'mobo_core_portal_heartbeat_last_success_at', time(), false );
		Mobo_Core_Settings::update( 'mobo_core_portal_heartbeat_last_result', $stored_result, false );

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
		$connection_count = 0;
		$successful_connections = 0;
		$failed_connections = 0;
		if ( isset( $work['connections'] ) && is_array( $work['connections'] ) ) {
			foreach ( $work['connections'] as $connection_result ) {
				if ( ! is_array( $connection_result ) || empty( $connection_result['result'] ) || ! is_array( $connection_result['result'] ) ) {
					continue;
				}
				$connection_count++;
				if ( ! empty( $connection_result['result']['success'] ) ) {
					$successful_connections++;
				} else {
					$failed_connections++;
				}
			}
		}

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
				'multiConnection' => ! empty( $runner['multiConnection'] ) || $connection_count > 1,
				'connectionCount' => $connection_count > 0 ? $connection_count : absint( $runner['connectionCount'] ?? 0 ),
				'successfulConnections' => $successful_connections,
				'failedConnections' => $failed_connections,
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
	 * Return a local health snapshot for backward-compatible diagnostics.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function send_health_report_now( $request ) {
		$reporter = new Mobo_Core_Health_Reporter();

		return rest_ensure_response(
			array(
				'success' => true,
				'status'  => 'portal-pull-only',
				'message' => 'Portal گزارش سلامت را مستقیماً از endpoint افزونه دریافت می‌کند.',
				'data'    => $reporter->build_report( false, $this->health_may_include_license_token() ),
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
			$this->record_rest_exception( 'webhook-queue', $e );
			$result = array(
				'success'   => false,
				'status'    => 'webhook-queue-exception',
				'processed' => 0,
				'failed'    => 1,
				'remaining' => true,
				'message'   => 'Webhook queue execution failed. See local MoboCore diagnostics.',
			);

			Mobo_Core_Settings::update( 'mobo_core_webhook_queue_last_result', $result, false );
			Mobo_Core_Settings::update( 'mobo_core_webhook_queue_last_attempt_at', time(), false );

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
		$params  = $request->get_json_params();
		$sync_id = '';

		if ( is_array( $params ) && isset( $params['syncId'] ) ) {
			$sync_id = sanitize_text_field( (string) $params['syncId'] );
		}

		$sync   = new Mobo_Core_Product_Sync();
		$result = $sync->start_manual_sync( $sync_id, 'external' );

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
		$sync   = new Mobo_Core_Product_Sync();
		$result = $sync->run_manual_sync_step();

		return rest_ensure_response( $result );
	}


	/**
	 * Return current product Sync/Repair status.
	 *
	 * This route has existed in the public contract for years; 10.33.38
	 * registered the callback but accidentally omitted the method itself.
	 * Keep the response as the status object at the root because external
	 * runners poll isDone/isRunning directly.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_product_sync_status( $request ) {
		$sync = new Mobo_Core_Product_Sync();
		return rest_ensure_response( $sync->get_manual_sync_status() );
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


	/**
	 * Return immutable Mobo revenue summary for Portal Site Health.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function get_revenue_summary( $request ) {
		if ( ! class_exists( 'Mobo_Core_Revenue_Ledger' ) ) {
			return new WP_Error(
				'mobo_core_revenue_ledger_unavailable',
				'Revenue ledger is unavailable.',
				array( 'status' => 503 )
			);
		}

		$ledger = new Mobo_Core_Revenue_Ledger();
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' )
			? absint( Mobo_Core_Connection_Context::get_effective_connection_id() )
			: 0;
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists() && $connection_id <= 0 ) {
			return new WP_Error(
				'mobo_core_revenue_connection_scope_missing',
				'Revenue summary requires an authenticated Connection scope.',
				array( 'status' => 503 )
			);
		}

		return array(
			'success'      => true,
			'status'       => 'ok',
			'connectionId' => $connection_id,
			'message'      => $connection_id > 0
				? 'Revenue summary generated from immutable completed-order ledger records for the authenticated Connection.'
				: 'Revenue summary generated from immutable completed-order ledger records.',
			'data'         => $ledger->get_summary( $connection_id ),
		);
	}

	/**
	 * Return every non-secret configurable setting to Portal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_portal_settings( $request ) {
		return rest_ensure_response(
			array(
				'success' => true,
				'status'  => 'ok',
				'message' => 'تنظیمات غیرمحرمانه افزونه دریافت شد.',
				'data'    => Mobo_Core_Settings::get_portal_settings_snapshot(),
			)
		);
	}

	/**
	 * Start a Portal-requested Sync or Repair.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function start_portal_operation( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$operation = isset( $params['operation'] ) ? sanitize_key( (string) $params['operation'] ) : '';
		$request_id = isset( $params['requestId'] ) ? sanitize_text_field( (string) $params['requestId'] ) : '';
		return rest_ensure_response( Mobo_Core_Remote_Control::start_operation( $operation, $request_id ) );
	}

	/**
	 * Return Portal-requested operation status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_portal_operation_status( $request ) {
		return rest_ensure_response(
			array(
				'success' => true,
				'status'  => 'ok',
				'data'    => Mobo_Core_Remote_Control::get_status(),
			)
		);
	}

	/**
	 * Cancel current Portal/manual operation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function cancel_portal_operation( $request ) {
		return rest_ensure_response( Mobo_Core_Remote_Control::cancel_operation() );
	}

	/**
	 * Record bounded, non-secret exception diagnostics. Exception messages can
	 * contain request URLs, tokens, headers and SQL parameters; even a local PHP
	 * error log is not a suitable place to persist those unfiltered values.
	 *
	 * @param string    $scope Stable operation label.
	 * @param Throwable $error Exception.
	 * @return void
	 */
	private function record_rest_exception( $scope, $error ) {
		$scope = sanitize_key( (string) $scope );
		if ( ! ( $error instanceof Throwable ) ) {
			return;
		}
		if ( class_exists( 'Mobo_Core_Logger' ) ) {
			Mobo_Core_Logger::error(
				'An internal REST operation failed.',
				array(
					'mobo_scope'           => $scope,
					'mobo_exception_class' => sanitize_text_field( get_class( $error ) ),
				)
			);
		}
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