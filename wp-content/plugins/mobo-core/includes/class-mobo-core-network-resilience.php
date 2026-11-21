<?php
/**
 * Bounded dependency-failure classification and cross-request backoff.
 *
 * Network failures must be observable without leaking remote response bodies,
 * signed URLs, credentials, or filesystem details. Retry decisions are explicit
 * and bounded; financial side effects are never retried here.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Network_Resilience {
	const WORKER_PREFIX = 'network_dependency_';
	const MAX_BACKOFF_SECONDS = 900;

	/** Normalize a dependency name for the runtime table. */
	private static function worker_name( $dependency ) {
		$dependency = sanitize_key( (string) $dependency );
		if ( '' === $dependency ) {
			$dependency = 'unknown';
		}
		return self::WORKER_PREFIX . substr( $dependency, 0, 96 );
	}

	/** Resolve the effective Connection without guessing another owner. */
	private static function connection_id( $connection_id = 0 ) {
		$id = absint( $connection_id );
		if ( $id > 0 ) {
			return $id;
		}
		if ( class_exists( 'Mobo_Core_Connection_Context' ) ) {
			return absint( Mobo_Core_Connection_Context::get_effective_connection_id() );
		}
		return 0;
	}

	/** Stable, non-secret scope fingerprint for endpoint-aware backoff state. */
	private static function scope_hash( $scope ) {
		$scope = trim( strtolower( (string) $scope ) );
		return '' !== $scope ? hash( 'sha256', $scope ) : '';
	}

	/** Read a persisted scope fingerprint from a runtime row. */
	private static function row_scope_hash( $row ) {
		if ( ! is_array( $row ) ) { return ''; }
		$state = json_decode( (string) ( isset( $row['state_json'] ) ? $row['state_json'] : '' ), true );
		return is_array( $state ) ? sanitize_text_field( (string) ( isset( $state['scopeHash'] ) ? $state['scopeHash'] : '' ) ) : '';
	}

	/** Empty scope keeps legacy behavior; a supplied scope must match exactly. */
	private static function row_matches_scope( $row, $scope ) {
		$scope_hash = self::scope_hash( $scope );
		if ( '' === $scope_hash ) { return true; }
		$row_hash = self::row_scope_hash( $row );
		return '' !== $row_hash && hash_equals( $scope_hash, $row_hash );
	}

	/**
	 * Parse Retry-After seconds or an RFC/HTTP date. Returned values are bounded.
	 */
	public static function parse_retry_after( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( preg_match( '/^[0-9]+$/D', $value ) ) {
			return min( self::MAX_BACKOFF_SECONDS, max( 0, absint( $value ) ) );
		}
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return 0;
		}
		return min( self::MAX_BACKOFF_SECONDS, max( 0, (int) $timestamp - time() ) );
	}

	/** Classify a WordPress transport error without exposing its raw message. */
	public static function classify_transport_error( $error ) {
		$code = is_wp_error( $error ) ? sanitize_key( (string) $error->get_error_code() ) : '';
		$message = is_wp_error( $error ) ? strtolower( (string) $error->get_error_message() ) : '';
		$failure = 'transport_failure';
		$operator = false;

		if ( false !== strpos( $message, 'resolve host' ) || false !== strpos( $message, 'could not resolve' ) || false !== strpos( $message, 'name resolution' ) || false !== strpos( $message, 'dns' ) ) {
			$failure = 'dns_failure';
		} elseif ( false !== strpos( $message, 'certificate' ) || false !== strpos( $message, 'ssl' ) || false !== strpos( $message, 'tls' ) ) {
			$failure = 'tls_failure';
			$operator = true;
		} elseif ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) || 'http_request_failed' === $code && false !== strpos( $message, 'operation timed out' ) ) {
			$failure = 'timeout';
		} elseif ( false !== strpos( $message, 'connection refused' ) || false !== strpos( $message, 'failed to connect' ) || false !== strpos( $message, 'could not connect' ) ) {
			$failure = 'connect_failure';
		}

		return array(
			'failureClass'      => $failure,
			'status'            => 0,
			'retryable'         => true,
			'operatorAction'    => $operator,
			'retryAfterSeconds' => 0,
			'originalError'     => $code,
		);
	}

	/** Classify an HTTP response. */
	public static function classify_http_status( $status, $retry_after = '' ) {
		$status = absint( $status );
		$failure = 'http_error';
		$retryable = false;
		$operator = false;
		$retry_seconds = self::parse_retry_after( $retry_after );

		if ( 401 === $status ) {
			$failure = 'authentication_failed';
			$operator = true;
		} elseif ( 403 === $status ) {
			$failure = 'access_denied';
			$operator = true;
		} elseif ( in_array( $status, array( 404, 405, 410, 501 ), true ) ) {
			$failure = 'endpoint_unavailable';
			$operator = true;
		} elseif ( 408 === $status ) {
			$failure = 'timeout';
			$retryable = true;
		} elseif ( 429 === $status ) {
			$failure = 'rate_limited';
			$retryable = true;
		} elseif ( $status >= 500 && $status <= 599 ) {
			$failure = 'upstream_failure';
			$retryable = true;
		} elseif ( $status >= 400 && $status <= 499 ) {
			$failure = 'request_rejected';
			$operator = true;
		}

		return array(
			'failureClass'      => $failure,
			'status'            => $status,
			'retryable'         => $retryable,
			'operatorAction'    => $operator,
			'retryAfterSeconds' => $retry_seconds,
			'originalError'     => '',
		);
	}

	/** Invalid/empty dependency responses are retryable, but never accepted as authoritative. */
	public static function invalid_response_failure( $kind = 'invalid_response' ) {
		$kind = sanitize_key( (string) $kind );
		return array(
			'failureClass'      => '' !== $kind ? $kind : 'invalid_response',
			'status'            => 0,
			'retryable'         => true,
			'operatorAction'    => false,
			'retryAfterSeconds' => 0,
			'originalError'     => '',
		);
	}

	/** Stable operator-safe error object. */
	public static function to_wp_error( $code, $message, $failure, $extra = array() ) {
		$data = array_merge(
			array(
				'failure_class'       => sanitize_key( (string) ( isset( $failure['failureClass'] ) ? $failure['failureClass'] : 'unknown' ) ),
				'status'              => absint( isset( $failure['status'] ) ? $failure['status'] : 0 ),
				'retryable'           => ! empty( $failure['retryable'] ),
				'operator_action'      => ! empty( $failure['operatorAction'] ),
				'retry_after_seconds' => absint( isset( $failure['retryAfterSeconds'] ) ? $failure['retryAfterSeconds'] : 0 ),
			),
			is_array( $extra ) ? $extra : array()
		);
		if ( ! empty( $failure['originalError'] ) ) {
			$data['original_error'] = sanitize_key( (string) $failure['originalError'] );
		}
		return new WP_Error( sanitize_key( (string) $code ), sanitize_text_field( (string) $message ), $data );
	}

	/** Read persisted dependency state. */
	private static function read_row( $dependency, $connection_id = 0 ) {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return null;
		}
		$id = self::connection_id( $connection_id );
		if ( $id <= 0 ) {
			return null;
		}
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT consecutive_failures,circuit_state,circuit_open_until,last_error_code,state_json,updated_at FROM {$table} WHERE connection_id=%d AND worker_name=%s LIMIT 1",
				$id,
				self::worker_name( $dependency )
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/** Return a fail-fast error while a dependency backoff is still active. */
	public static function before_request( $dependency, $connection_id = 0, $scope = '' ) {
		$row = self::read_row( $dependency, $connection_id );
		if ( is_array( $row ) && ! self::row_matches_scope( $row, $scope ) ) {
			return true;
		}
		if ( ! is_array( $row ) || 'open' !== (string) ( isset( $row['circuit_state'] ) ? $row['circuit_state'] : '' ) || empty( $row['circuit_open_until'] ) ) {
			return true;
		}
		$until = strtotime( (string) $row['circuit_open_until'] . ' UTC' );
		if ( false === $until || $until <= time() ) {
			return true;
		}
		$state = json_decode( (string) ( isset( $row['state_json'] ) ? $row['state_json'] : '' ), true );
		$failure = is_array( $state ) && isset( $state['failure'] ) && is_array( $state['failure'] ) ? $state['failure'] : array();
		$failure['retryAfterSeconds'] = max( 1, $until - time() );
		return self::to_wp_error(
			'mobo_core_dependency_backoff',
			'Dependency request deferred by bounded network backoff.',
			$failure,
			array( 'dependency' => sanitize_key( (string) $dependency ) )
		);
	}

	/** Persist a bounded circuit and sanitized failure evidence. */
	public static function record_failure( $dependency, $failure, $connection_id = 0, $scope = '' ) {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}
		$id = self::connection_id( $connection_id );
		if ( $id <= 0 || ! is_array( $failure ) ) {
			return false;
		}
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$worker = self::worker_name( $dependency );
		$previous = self::read_row( $dependency, $id );
		$previous_count = is_array( $previous ) && self::row_matches_scope( $previous, $scope )
			? absint( isset( $previous['consecutive_failures'] ) ? $previous['consecutive_failures'] : 0 )
			: 0;
		$count = min( 1000, 1 + $previous_count );
		$requested = absint( isset( $failure['retryAfterSeconds'] ) ? $failure['retryAfterSeconds'] : 0 );
		if ( $requested <= 0 ) {
			$base = ! empty( $failure['retryable'] ) ? 5 : 60;
			$requested = $base * (int) pow( 2, min( 6, max( 0, $count - 1 ) ) );
		}
		$delay = min( self::MAX_BACKOFF_SECONDS, max( 1, $requested ) );
		$now = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', time() + $delay );
		$safe_failure = array(
			'failureClass'      => sanitize_key( (string) ( isset( $failure['failureClass'] ) ? $failure['failureClass'] : 'unknown' ) ),
			'status'            => absint( isset( $failure['status'] ) ? $failure['status'] : 0 ),
			'retryable'         => ! empty( $failure['retryable'] ),
			'operatorAction'    => ! empty( $failure['operatorAction'] ),
			'retryAfterSeconds' => $delay,
		);
		if ( ! empty( $failure['originalError'] ) ) {
			$safe_failure['originalError'] = sanitize_key( (string) $failure['originalError'] );
		}
		$scope_hash = self::scope_hash( $scope );
		$state = wp_json_encode(
			array(
				'dependency'   => sanitize_key( (string) $dependency ),
				'connectionId' => $id,
				'scopeHash'    => $scope_hash,
				'failure'      => $safe_failure,
				'failedAt'     => gmdate( 'c' ),
				'backoffUntil' => gmdate( 'c', time() + $delay ),
			)
		);
		if ( ! is_string( $state ) ) {
			$state = '{}';
		}
		$sql = "INSERT INTO {$table} (connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,circuit_open_until,last_error_code,state_json,created_at,updated_at) VALUES (%d,%s,%s,%s,'',%d,'open',%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE last_started_at=VALUES(last_started_at),last_finished_at=VALUES(last_finished_at),consecutive_failures=VALUES(consecutive_failures),circuit_state='open',circuit_open_until=VALUES(circuit_open_until),last_error_code=VALUES(last_error_code),state_json=VALUES(state_json),updated_at=VALUES(updated_at)";
		return false !== $wpdb->query(
			$wpdb->prepare(
				$sql,
				$id,
				$worker,
				$now,
				$now,
				$count,
				$until,
				sanitize_key( (string) $safe_failure['failureClass'] ),
				$state,
				$now,
				$now
			)
		);
	}

	/** Clear a previously failed dependency only when there is state to clear. */
	public static function record_success( $dependency, $connection_id = 0, $scope = '' ) {
		global $wpdb;
		$row = self::read_row( $dependency, $connection_id );
		if ( ! is_array( $row ) || ( 0 === absint( isset( $row['consecutive_failures'] ) ? $row['consecutive_failures'] : 0 ) && 'closed' === (string) ( isset( $row['circuit_state'] ) ? $row['circuit_state'] : '' ) ) ) {
			return true;
		}
		$id = self::connection_id( $connection_id );
		if ( $id <= 0 || ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$now = current_time( 'mysql', true );
		$state = wp_json_encode( array( 'dependency' => sanitize_key( (string) $dependency ), 'connectionId' => $id, 'scopeHash' => self::scope_hash( $scope ), 'recoveredAt' => gmdate( 'c' ) ) );
		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET last_finished_at=%s,consecutive_failures=0,circuit_state='closed',circuit_open_until=NULL,last_error_code='',state_json=%s,updated_at=%s WHERE connection_id=%d AND worker_name=%s",
				$now,
				is_string( $state ) ? $state : '{}',
				$now,
				$id,
				self::worker_name( $dependency )
			)
		);
	}

	/** Compact health snapshot for the current Connection. */
	public static function get_status( $connection_id = 0 ) {
		global $wpdb;
		$id = self::connection_id( $connection_id );
		if ( $id <= 0 || ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return array();
		}
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$like = $wpdb->esc_like( self::WORKER_PREFIX ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT worker_name,consecutive_failures,circuit_state,circuit_open_until,last_error_code,state_json,updated_at FROM {$table} WHERE connection_id=%d AND worker_name LIKE %s ORDER BY worker_name ASC",
				$id,
				$like
			),
			ARRAY_A
		);
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = (string) ( isset( $row['worker_name'] ) ? $row['worker_name'] : '' );
			$dependency = 0 === strpos( $name, self::WORKER_PREFIX ) ? substr( $name, strlen( self::WORKER_PREFIX ) ) : $name;
			$state = json_decode( (string) ( isset( $row['state_json'] ) ? $row['state_json'] : '' ), true );
			$failure = is_array( $state ) && isset( $state['failure'] ) && is_array( $state['failure'] ) ? $state['failure'] : array();
			$out[ $dependency ] = array(
				'consecutiveFailures' => absint( isset( $row['consecutive_failures'] ) ? $row['consecutive_failures'] : 0 ),
				'circuitState'        => sanitize_key( (string) ( isset( $row['circuit_state'] ) ? $row['circuit_state'] : '' ) ),
				'circuitOpenUntil'    => sanitize_text_field( (string) ( isset( $row['circuit_open_until'] ) ? $row['circuit_open_until'] : '' ) ),
				'failureClass'        => sanitize_key( (string) ( isset( $failure['failureClass'] ) ? $failure['failureClass'] : ( isset( $row['last_error_code'] ) ? $row['last_error_code'] : '' ) ) ),
				'status'              => absint( isset( $failure['status'] ) ? $failure['status'] : 0 ),
				'retryable'           => ! empty( $failure['retryable'] ),
				'operatorAction'      => ! empty( $failure['operatorAction'] ),
				'scopeHash'           => is_array( $state ) ? sanitize_text_field( (string) ( isset( $state['scopeHash'] ) ? $state['scopeHash'] : '' ) ) : '',
				'updatedAt'           => sanitize_text_field( (string) ( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ) ),
			);
		}
		return $out;
	}
}
