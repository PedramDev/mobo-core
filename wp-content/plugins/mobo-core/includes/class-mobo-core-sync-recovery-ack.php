<?php
/**
 * Durable ACK sender for Portal product/variant sync recovery.
 *
 * Portal considers a webhook delivered only after WordPress has actually
 * applied it and this class acknowledges the exact Portal event ID. Failed
 * ACK requests are retained in wp_options and retried by the real cron.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Sync_Recovery_Ack {
	const OPTION_QUEUE        = 'mobo_core_sync_recovery_ack_queue';
	const OPTION_LAST_ATTEMPT = 'mobo_core_sync_recovery_ack_last_attempt_at';
	const OPTION_LAST_SUCCESS = 'mobo_core_sync_recovery_ack_last_success_at';
	const OPTION_LAST_ERROR   = 'mobo_core_sync_recovery_ack_last_error';
	const MAX_QUEUE_ITEMS     = 1000;

	/**
	 * Queue and immediately attempt a successful apply acknowledgement.
	 *
	 * @param array $item Queue item.
	 * @return bool
	 */
	public static function queue_success( $item ) {
		if ( class_exists( 'Mobo_Core_Sync_Version_Ledger' ) ) {
			Mobo_Core_Sync_Version_Ledger::record_applied( $item );
		}

		return self::queue( $item, true, '' );
	}

	/**
	 * Queue and immediately attempt a final apply failure acknowledgement.
	 *
	 * @param array  $item Queue item.
	 * @param string $error Error message.
	 * @return bool
	 */
	public static function queue_failure( $item, $reason, $error = '' ) {
		if ( '' === trim( (string) $error ) ) {
			$error = (string) $reason;
		}

		return self::queue( $item, false, $error, $reason );
	}

	/**
	 * Retry pending acknowledgements.
	 *
	 * @param int $limit Maximum items.
	 * @return array
	 */
	public static function retry_pending( $limit = 10 ) {
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$queue = self::get_queue();

		if ( empty( $queue ) ) {
			return array(
				'success'   => true,
				'status'    => 'empty',
				'processed' => 0,
				'failed'    => 0,
				'pending'   => 0,
			);
		}

		$processed = 0;
		$failed    = 0;

		foreach ( array_keys( $queue ) as $event_id ) {
			if ( $processed >= $limit ) {
				break;
			}

			$payload = isset( $queue[ $event_id ] ) && is_array( $queue[ $event_id ] ) ? $queue[ $event_id ] : array();
			$result  = self::send( $payload );
			$processed++;

			if ( ! is_wp_error( $result ) ) {
				unset( $queue[ $event_id ] );
				update_option( self::OPTION_LAST_SUCCESS, time(), false );
				delete_option( self::OPTION_LAST_ERROR );
			} else {
				$failed++;
				$queue[ $event_id ]['attempts']    = isset( $queue[ $event_id ]['attempts'] ) ? absint( $queue[ $event_id ]['attempts'] ) + 1 : 1;
				$queue[ $event_id ]['lastError']   = sanitize_text_field( $result->get_error_message() );
				$queue[ $event_id ]['lastAttempt'] = time();
				update_option( self::OPTION_LAST_ERROR, $result->get_error_message(), false );
			}
		}

		self::save_queue( $queue );

		return array(
			'success'   => 0 === $failed,
			'status'    => 0 === $failed ? 'sent' : 'partial',
			'processed' => $processed,
			'failed'    => $failed,
			'pending'   => count( $queue ),
		);
	}

	/**
	 * Get health/status data.
	 *
	 * @return array
	 */
	public static function get_status() {
		$queue = self::get_queue();
		return array(
			'enabled'       => true,
			'pending'       => count( $queue ),
			'lastAttemptAt' => absint( get_option( self::OPTION_LAST_ATTEMPT, 0 ) ),
			'lastSuccessAt' => absint( get_option( self::OPTION_LAST_SUCCESS, 0 ) ),
			'lastError'     => sanitize_text_field( (string) get_option( self::OPTION_LAST_ERROR, '' ) ),
			'endpoint'      => self::get_endpoint_url(),
		);
	}

	private static function queue( $item, $success, $error, $reason = '' ) {
		$payload = self::build_payload( $item, $success, $error, $reason );
		if ( empty( $payload['eventId'] ) ) {
			return false;
		}

		$queue = self::get_queue();
		$event_id = (string) $payload['eventId'];
		$payload['queuedAt'] = time();
		$payload['attempts'] = isset( $queue[ $event_id ]['attempts'] ) ? absint( $queue[ $event_id ]['attempts'] ) : 0;
		$queue[ $event_id ] = $payload;

		if ( count( $queue ) > self::MAX_QUEUE_ITEMS ) {
			uasort(
				$queue,
				function ( $left, $right ) {
					return absint( isset( $left['queuedAt'] ) ? $left['queuedAt'] : 0 ) <=> absint( isset( $right['queuedAt'] ) ? $right['queuedAt'] : 0 );
				}
			);
			$queue = array_slice( $queue, -self::MAX_QUEUE_ITEMS, null, true );
		}

		self::save_queue( $queue );
		self::retry_pending( 1 );
		return true;
	}

	private static function build_payload( $item, $success, $error, $reason = '' ) {
		$item    = is_array( $item ) ? $item : array();
		$payload = isset( $item['payload'] ) && is_array( $item['payload'] ) ? $item['payload'] : array();
		$event   = isset( $item['event'] ) ? sanitize_text_field( (string) $item['event'] ) : '';

		$event_id = self::first_non_empty(
			array(
				isset( $payload['_moboPortalEventId'] ) ? $payload['_moboPortalEventId'] : '',
				isset( $payload['eventId'] ) ? $payload['eventId'] : '',
				isset( $item['portalEventId'] ) ? $item['portalEventId'] : '',
			)
		);

		if ( ! self::is_uuid( $event_id ) ) {
			return array();
		}

		$component = self::first_non_empty(
			array(
				isset( $payload['_moboDeliveryComponent'] ) ? $payload['_moboDeliveryComponent'] : '',
				isset( $payload['deliveryComponent'] ) ? $payload['deliveryComponent'] : '',
			)
		);
		if ( '' === $component ) {
			$component = 'UpdateVariant' === $event ? 'variants' : 'product';
		}

		$entity_version = absint(
			self::first_non_empty(
				array(
					isset( $payload['_moboEntityVersion'] ) ? $payload['_moboEntityVersion'] : '',
					isset( $item['eventVersion'] ) ? $item['eventVersion'] : '',
					isset( $payload['componentVersion'] ) ? $payload['componentVersion'] : '',
				)
			)
		);
		$aggregate_version = absint(
			self::first_non_empty(
				array(
					isset( $payload['_moboAggregateVersion'] ) ? $payload['_moboAggregateVersion'] : '',
					isset( $payload['aggregateVersion'] ) ? $payload['aggregateVersion'] : '',
				)
			)
		);

		return array(
			'eventId'          => $event_id,
			'status'           => $success ? 'applied' : 'failed',
			'success'          => (bool) $success,
			'error'            => $success ? '' : sanitize_text_field( (string) $error ),
			'failureKind'      => $success ? '' : sanitize_key( (string) $reason ),
			'component'        => sanitize_key( $component ),
			'entityVersion'    => $entity_version,
			'aggregateVersion' => $aggregate_version,
			'productId'        => self::extract_product_id( $payload ),
			'siteUrl'          => home_url( '/' ),
			'pluginVersion'    => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
			'appliedAt'        => gmdate( 'c' ),
		);
	}

	private static function extract_product_id( $payload ) {
		$candidates = array(
			isset( $payload['productId'] ) ? $payload['productId'] : '',
			isset( $payload['product_guid'] ) ? $payload['product_guid'] : '',
			isset( $payload['productGuid'] ) ? $payload['productGuid'] : '',
			isset( $payload['entityGuid'] ) ? $payload['entityGuid'] : '',
		);

		$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			$candidates[] = isset( $data[0]['productId'] ) ? $data[0]['productId'] : '';
			$candidates[] = isset( $data[0]['id'] ) ? $data[0]['id'] : '';
		}

		return sanitize_text_field( self::first_non_empty( $candidates ) );
	}

	private static function send( $payload ) {
		$url = self::get_endpoint_url();
		if ( '' === $url ) {
			return new WP_Error( 'mobo_core_sync_ack_missing_url', 'Portal Sync Recovery ACK URL is unavailable.' );
		}

		$security_code = Mobo_Core_Settings::normalize_security_code( Mobo_Core_Settings::get( 'mobo_core_security_code', '' ) );
		if ( '' === $security_code || ! Mobo_Core_Settings::is_valid_security_code( $security_code ) ) {
			return new WP_Error( 'mobo_core_sync_ack_missing_security', 'Webhook security code is missing or invalid.' );
		}

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json; charset=utf-8',
			'X-SEC'        => $security_code,
		);
		$token = trim( (string) Mobo_Core_Settings::get( 'mobo_core_token', '' ) );
		if ( '' !== $token ) {
			$headers['Token'] = $token;
		}

		update_option( self::OPTION_LAST_ATTEMPT, time(), false );
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => Mobo_Core_Settings::get_int( 'mobo_core_sync_ack_timeout_seconds', 15, 5, 60 ),
				'redirection' => 2,
				'sslverify'   => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'sync_recovery_ack' ),
				'headers'     => $headers,
				'body'        => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = absint( wp_remote_retrieve_response_code( $response ) );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'mobo_core_sync_ack_http_error', 'Portal ACK returned HTTP ' . $code . '.' );
		}
		return true;
	}

	private static function get_endpoint_url() {
		$base_url = apply_filters( 'mobo_core_api_base_url', '' );
		if ( ! is_string( $base_url ) || '' === trim( $base_url ) ) {
			$base_url = (string) Mobo_Core_Settings::get( 'mobo_core_api_base_url', '' );
		}
		$base_url = esc_url_raw( trim( (string) $base_url ) );
		if ( '' === $base_url ) {
			return '';
		}
		return trailingslashit( $base_url ) . 'api/mobo/sync-recovery/ack';
	}

	private static function get_queue() {
		$queue = get_option( self::OPTION_QUEUE, array() );
		return is_array( $queue ) ? $queue : array();
	}

	private static function save_queue( $queue ) {
		update_option( self::OPTION_QUEUE, is_array( $queue ) ? $queue : array(), false );
	}

	private static function first_non_empty( $values ) {
		foreach ( (array) $values as $value ) {
			if ( null !== $value && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private static function is_uuid( $value ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim( (string) $value ) );
	}
}
