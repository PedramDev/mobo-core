<?php
/**
 * webhook-batch-v1: parsing, limits and result mapping (pure orchestration).
 *
 * Request: existing webhook route, header `X-Mobo-Batch: 1`, body
 *   {"batch":[{...event JSON..., "eventId":"...", "eventType":"ProductUpdated|UpdateVariant|..."}, ...]}
 * Limits: at most 200 events and 1 MB body (larger => HTTP 413).
 * Response: {"success":true,"results":[{"eventId","ok","needFullSnapshot","error"}, ...]}
 * with exactly one result per received event, in order.
 *
 * Every event is handled atomically: it is either applied inline completely (only
 * quick product-delta-v1 events, while the inline time budget lasts), or stored
 * completely in the existing durable webhook queue (counts as ok:true), or
 * rejected (ok:false). An event is never partially applied.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Webhook_Batch {

	const CAPABILITY = 'webhook-batch-v1';
	const MAX_EVENTS = 200;
	const MAX_BYTES  = 1048576;

	/**
	 * Capabilities advertised to the Backend.
	 *
	 * @return array
	 */
	public static function capabilities() {
		/* Not advertised while the runner is migration-only: the Backend would start
		 * delta/batch delivery that this site cannot apply yet (full-snapshot flood). */
		if ( ! self::normal_runtime_ready() ) {
			return array();
		}
		return array( 'product-delta-v1', self::CAPABILITY );
	}

	/**
	 * Whether ordinary runtime work (inline deltas included) may run now. A stamped
	 * database is always ready and costs one option read; otherwise the migration's
	 * structural readiness decides.
	 *
	 * @return bool
	 */
	public static function normal_runtime_ready() {
		if ( defined( 'MOBO_CORE_VERSION' ) && MOBO_CORE_VERSION === trim( (string) get_option( 'mobo_core_db_version', '' ) ) ) {
			return true;
		}
		if ( ! class_exists( 'Mobo_Core_Migration' ) || ! method_exists( 'Mobo_Core_Migration', 'normal_runtime_ready' ) ) {
			return false;
		}
		return (bool) Mobo_Core_Migration::normal_runtime_ready();
	}

	/**
	 * Whether the request header asks for batch mode.
	 *
	 * @param mixed $header_value Header value.
	 * @return bool
	 */
	public static function is_batch_header( $header_value ) {
		return is_scalar( $header_value ) && in_array( strtolower( trim( (string) $header_value ) ), array( '1', 'true', 'yes' ), true );
	}

	/**
	 * Parse and bound a batch body.
	 *
	 * @param string     $raw_body Raw request body.
	 * @param array|null $decoded Already-decoded JSON (optional).
	 * @return array{status:int,error:string,events:array}
	 */
	public static function parse( $raw_body, $decoded = null ) {
		$raw_body = (string) $raw_body;
		if ( strlen( $raw_body ) > self::MAX_BYTES ) {
			return array( 'status' => 413, 'error' => 'batch_too_large', 'events' => array() );
		}
		if ( ! is_array( $decoded ) ) {
			$decoded = '' === $raw_body ? null : json_decode( $raw_body, true );
		}
		if ( ! is_array( $decoded ) ) {
			return array( 'status' => 400, 'error' => 'invalid_json', 'events' => array() );
		}
		$batch = array_key_exists( 'batch', $decoded ) ? $decoded['batch'] : ( array_key_exists( 'Batch', $decoded ) ? $decoded['Batch'] : null );
		if ( ! is_array( $batch ) ) {
			return array( 'status' => 400, 'error' => 'batch_missing', 'events' => array() );
		}
		$batch = array_values( $batch );
		if ( count( $batch ) > self::MAX_EVENTS ) {
			return array( 'status' => 413, 'error' => 'batch_too_many_events', 'events' => array() );
		}
		return array( 'status' => 200, 'error' => '', 'events' => $batch );
	}

	/**
	 * Event id of one batch row ('' when absent).
	 *
	 * @param mixed $event Event.
	 * @return string
	 */
	public static function event_id( $event ) {
		if ( ! is_array( $event ) ) {
			return '';
		}
		foreach ( array( 'eventId', 'EventId', 'id', 'Id' ) as $key ) {
			if ( isset( $event[ $key ] ) && is_scalar( $event[ $key ] ) && '' !== trim( (string) $event[ $key ] ) ) {
				return substr( preg_replace( '/[^A-Za-z0-9_\-:.]/', '', (string) $event[ $key ] ), 0, 128 );
			}
		}
		return '';
	}

	/**
	 * Event type of one batch row ('' when absent).
	 *
	 * @param mixed $event Event.
	 * @return string
	 */
	public static function event_type( $event ) {
		if ( ! is_array( $event ) ) {
			return '';
		}
		foreach ( array( 'eventType', 'EventType', 'event', 'Event', 'type', 'Type' ) as $key ) {
			if ( isset( $event[ $key ] ) && is_scalar( $event[ $key ] ) && '' !== trim( (string) $event[ $key ] ) ) {
				$value = trim( (string) $event[ $key ] );
				if ( is_numeric( $value ) && class_exists( 'Mobo_Core_Event_Type_Policy' ) ) {
					return Mobo_Core_Event_Type_Policy::map_numeric( $value );
				}
				return preg_replace( '/[^A-Za-z0-9_]/', '', $value );
			}
		}
		return '';
	}

	/**
	 * Normalize one batch row into the single-event payload shape the existing
	 * queue understands (`event` key), without dropping any original field.
	 *
	 * @param array  $event Event.
	 * @param string $type Event type.
	 * @return array
	 */
	public static function to_single_payload( $event, $type ) {
		$payload = is_array( $event ) ? $event : array();
		if ( '' !== $type && ( ! isset( $payload['event'] ) || ! is_scalar( $payload['event'] ) || '' === trim( (string) $payload['event'] ) ) ) {
			$payload['event'] = $type;
		}
		return $payload;
	}

	/**
	 * Normalized result row.
	 *
	 * @param string $event_id Event id.
	 * @param bool   $ok Ok.
	 * @param bool   $need_full Need full snapshot.
	 * @param string $error Error code/message.
	 * @return array
	 */
	public static function result( $event_id, $ok, $need_full = false, $error = '', $note = '' ) {
		$row = array(
			'eventId'          => (string) $event_id,
			'ok'               => (bool) $ok,
			'needFullSnapshot' => (bool) $need_full,
			'error'            => (string) $error,
		);
		if ( '' !== (string) $note ) {
			$row['note'] = (string) $note; // e.g. "quarantined": accepted, not applied, do not retry.
		}
		return $row;
	}

	/**
	 * Process a parsed batch.
	 *
	 * $inline( array $payload, string $type ) may apply a quick event immediately and
	 * returns null when it declines (=> enqueue) or an array with keys
	 * ok/needFullSnapshot/error. It must be all-or-nothing per event.
	 * $enqueue( array $payload ) stores the event durably and returns true or a
	 * WP_Error/string error.
	 *
	 * @param array    $events Events.
	 * @param callable $inline Inline handler.
	 * @param callable $enqueue Enqueue handler.
	 * @param float    $inline_deadline microtime() after which nothing runs inline.
	 * @return array Result rows (one per event, in order).
	 */
	public static function process( $events, $inline, $enqueue, $inline_deadline ) {
		$results = array();
		foreach ( array_values( (array) $events ) as $event ) {
			$event_id = self::event_id( $event );
			if ( ! is_array( $event ) ) {
				$results[] = self::result( $event_id, false, false, 'invalid_event' );
				continue;
			}
			$type = self::event_type( $event );
			if ( '' === $type ) {
				$results[] = self::result( $event_id, false, false, 'event_type_missing' );
				continue;
			}
			$payload = self::to_single_payload( $event, $type );
			try {
				$outcome = null;
				if ( is_callable( $inline ) && microtime( true ) < (float) $inline_deadline ) {
					$outcome = call_user_func( $inline, $payload, $type );
				}
				if ( is_array( $outcome ) ) {
					$results[] = self::result(
						$event_id,
						! empty( $outcome['ok'] ),
						! empty( $outcome['needFullSnapshot'] ),
						isset( $outcome['error'] ) && is_scalar( $outcome['error'] ) ? (string) $outcome['error'] : '',
						isset( $outcome['note'] ) && is_scalar( $outcome['note'] ) ? (string) $outcome['note'] : ''
					);
					continue;
				}
				$stored = call_user_func( $enqueue, $payload );
				if ( true === $stored ) {
					$results[] = self::result( $event_id, true );
				} else {
					$error = is_wp_error( $stored ) ? $stored->get_error_code() : ( is_scalar( $stored ) && '' !== (string) $stored ? (string) $stored : 'enqueue_failed' );
					$results[] = self::result( $event_id, false, false, $error );
				}
			} catch ( Throwable $e ) {
				$results[] = self::result( $event_id, false, false, 'processor_exception_' . strtolower( preg_replace( '/[^A-Za-z0-9_]/', '', get_class( $e ) ) ) );
			}
		}
		return $results;
	}
}
