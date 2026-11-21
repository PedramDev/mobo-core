<?php
/**
 * Plugin-scoped error capture for remote support diagnostics.
 *
 * A bounded ring buffer (last ~300 distinct problems, < 1 MB) of problems that
 * originate in Mobo code: PHP fatals in plugin files (shutdown + error_get_last),
 * exceptions caught by job runners / cron stages / REST handlers, WP_Error
 * results of Backend/Source calls, and $wpdb errors of Mobo queries. Identical
 * problems are deduplicated (count + firstSeen/lastSeen).
 *
 * Storage: non-autoloaded options. One small index option (signature => chunk)
 * plus CHUNKS chunk options of CHUNK_SIZE entries each; when the head chunk is
 * full the oldest chunk is recycled, so the buffer always holds between
 * (CHUNKS-1)*CHUNK_SIZE and CHUNKS*CHUNK_SIZE entries. Captures are buffered in
 * memory and written once per request at shutdown (or when the buffer fills).
 *
 * Privacy: every message and context value passes scrub_text()/scrub_context():
 * secrets (license tokens, X-SEC, passwords, cookies, auth headers, signatures,
 * card/IBAN/wallet data) are removed, customer names/phones/emails/addresses are
 * masked, URLs lose their query string and absolute paths become plugin-relative.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Error_Log {

	const INDEX_OPTION    = 'mobo_core_diag_errlog_index';
	const CHUNK_PREFIX    = 'mobo_core_diag_errlog_c';
	const EVENTS_OPTION   = 'mobo_core_diag_events';
	const CHUNKS          = 12;
	const CHUNK_SIZE      = 25;
	const MAX_ENTRIES     = 300;
	const CHUNK_MAX_BYTES = 80000;
	const MAX_EVENTS      = 100;
	const MAX_BUFFER      = 40;
	const MESSAGE_MAX     = 500;

	/** @var string[] */
	const LEVELS = array( 'fatal', 'error', 'warning', 'notice' );

	/** @var array<string,array> Pending captures keyed by signature. */
	private static $buffer = array();

	/** @var array[] Pending recent events. */
	private static $events = array();

	/** @var bool */
	private static $bootstrapped = false;

	/** @var bool Re-entrancy guard (capture must never recurse). */
	private static $busy = false;

	/** @var string Last $wpdb->last_error already captured in this request. */
	private static $last_db_error = '';

	/**
	 * Register the fatal-error shutdown hook and the REST error observer.
	 * Called once from mobo-core.php; cheap (no I/O).
	 *
	 * @return void
	 */
	public static function bootstrap() {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;
		if ( class_exists( 'Mobo_Core_Environment' ) ) {
			Mobo_Core_Environment::on_shutdown( array( __CLASS__, 'on_shutdown' ) );
		}
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'observe_rest_response' ), 999, 3 );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Capture API
	 * ------------------------------------------------------------------ */

	/**
	 * Capture one plugin-originated problem. Never throws.
	 *
	 * @param string $level       fatal|error|warning|notice.
	 * @param string $code        Short machine code.
	 * @param string $message     Message (scrubbed here).
	 * @param array  $context     Context (scrubbed here).
	 * @param string $file        Source file.
	 * @param int    $line        Line.
	 * @param string $correlation Correlation id ('' = current).
	 * @return string Signature ('' when skipped).
	 */
	public static function capture( $level, $code, $message, $context = array(), $file = '', $line = 0, $correlation = '' ) {
		if ( self::$busy ) {
			return '';
		}
		self::$busy = true;
		try {
			$level = in_array( (string) $level, self::LEVELS, true ) ? (string) $level : 'error';
			$code  = substr( preg_replace( '/[^a-z0-9_.:\-]/', '', strtolower( (string) $code ) ), 0, 80 );
			$code  = '' === $code ? 'unknown' : $code;
			$text  = self::clip( self::scrub_text( is_scalar( $message ) ? (string) $message : '' ), self::MESSAGE_MAX );
			$file  = self::relative_file( $file );
			$line  = absint( $line );
			if ( '' === (string) $correlation ) {
				$correlation = class_exists( 'Mobo_Core_Correlation' ) ? Mobo_Core_Correlation::current() : '';
			}
			$correlation = substr( preg_replace( '/[^A-Za-z0-9_.:\-]/', '', (string) $correlation ), 0, 96 );
			$now = time();
			$sig = substr( md5( $level . '|' . $code . '|' . self::normalize_for_signature( $text ) . '|' . $file . '|' . $line ), 0, 16 );

			if ( isset( self::$buffer[ $sig ] ) ) {
				self::$buffer[ $sig ]['count']++;
				self::$buffer[ $sig ]['last'] = $now;
				self::$buffer[ $sig ]['cid']  = $correlation;
			} else {
				self::$buffer[ $sig ] = array(
					'sig'   => $sig,
					'first' => $now,
					'last'  => $now,
					'count' => 1,
					'level' => $level,
					'code'  => $code,
					'msg'   => $text,
					'file'  => $file,
					'line'  => $line,
					'cid'   => $correlation,
					'ctx'   => self::scrub_context( is_array( $context ) ? $context : array() ),
				);
			}
			if ( count( self::$buffer ) >= self::MAX_BUFFER ) {
				self::$busy = false;
				self::flush();
			}
			return $sig;
		} catch ( Throwable $e ) {
			return '';
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * Capture a caught exception.
	 *
	 * @param string    $code      Code (e.g. "cron_stage.webhookqueue").
	 * @param Throwable $throwable Exception.
	 * @param array     $context   Context.
	 * @param string    $level     Level.
	 * @return string
	 */
	public static function capture_exception( $code, $throwable, $context = array(), $level = 'error' ) {
		if ( ! $throwable instanceof Throwable ) {
			return '';
		}
		$context = is_array( $context ) ? $context : array();
		$context['exceptionClass'] = get_class( $throwable );
		return self::capture( $level, $code, $throwable->getMessage(), $context, $throwable->getFile(), $throwable->getLine() );
	}

	/**
	 * Capture a WP_Error returned by a Backend/Source call.
	 *
	 * @param string   $origin  backend|source|rest|...
	 * @param WP_Error $error   Error.
	 * @param array    $context method, endpoint (path only), ...
	 * @param string   $level   Level.
	 * @return string
	 */
	public static function capture_wp_error( $origin, $error, $context = array(), $level = 'warning' ) {
		if ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $error ) ) {
			return '';
		}
		$context = is_array( $context ) ? $context : array();
		$data    = $error->get_error_data();
		if ( is_array( $data ) ) {
			foreach ( array( 'status', 'failureClass', 'retryable', 'portal_status', 'reason' ) as $key ) {
				if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && ! isset( $context[ $key ] ) ) {
					$context[ $key ] = $data[ $key ];
				}
			}
		}
		if ( isset( $context['endpoint'] ) ) {
			$context['endpoint'] = self::endpoint_path( $context['endpoint'] );
		}
		$code = sanitize_key( (string) $origin ) . '.' . sanitize_key( (string) $error->get_error_code() );
		return self::capture( $level, $code, $error->get_error_message(), $context );
	}

	/**
	 * Capture $wpdb->last_error once (Mobo queries only when $only_mobo).
	 *
	 * @param string $where     Call site label.
	 * @param bool   $only_mobo Require the last query to touch a Mobo table/option.
	 * @return string
	 */
	public static function capture_db_error( $where = '', $only_mobo = false ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->last_error ) ) {
			return '';
		}
		$error = trim( (string) $wpdb->last_error );
		if ( '' === $error || $error === self::$last_db_error ) {
			return '';
		}
		$query = isset( $wpdb->last_query ) ? (string) $wpdb->last_query : '';
		if ( $only_mobo && false === stripos( $query, 'mobo' ) ) {
			return '';
		}
		self::$last_db_error = $error;
		return self::capture( 'error', 'db_error', $error, array( 'where' => (string) $where, 'query' => self::query_shape( $query ) ) );
	}

	/**
	 * Record one recent webhook/sync/order/job event (ids, status, duration).
	 *
	 * @param string $kind        webhook|sync|repair|order|...
	 * @param string $id          Event/run/order id (no secrets).
	 * @param string $status      Outcome.
	 * @param int    $duration_ms Duration.
	 * @param array  $context     Small extra context (scrubbed).
	 * @return void
	 */
	public static function record_event( $kind, $id, $status, $duration_ms = 0, $context = array() ) {
		try {
			self::$events[] = array(
				'at'         => time(),
				'kind'       => sanitize_key( (string) $kind ),
				'id'         => substr( preg_replace( '/[^A-Za-z0-9_.:\-]/', '', (string) $id ), 0, 64 ),
				'status'     => sanitize_key( (string) $status ),
				'durationMs' => absint( $duration_ms ),
				'cid'        => class_exists( 'Mobo_Core_Correlation' ) ? Mobo_Core_Correlation::current() : '',
				'ctx'        => self::scrub_context( is_array( $context ) ? $context : array(), 1 ),
			);
			if ( count( self::$events ) > self::MAX_EVENTS ) {
				self::$events = array_slice( self::$events, -self::MAX_EVENTS );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * REST observer (rest_request_after_callbacks): server-side failures of
	 * Mobo routes. Authentication refusals are not errors of the plugin.
	 *
	 * @param mixed $response Response.
	 * @param mixed $handler  Handler.
	 * @param mixed $request  Request.
	 * @return mixed Unchanged response.
	 */
	public static function observe_rest_response( $response, $handler = null, $request = null ) {
		try {
			$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
			if ( 0 !== strpos( $route, '/mobo-core/' ) ) {
				return $response;
			}
			$status = 200;
			$code   = '';
			$message = '';
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
				$data    = $response->get_error_data();
				$status  = is_array( $data ) && isset( $data['status'] ) ? absint( $data['status'] ) : 500;
				$code    = (string) $response->get_error_code();
				$message = (string) $response->get_error_message();
			} elseif ( is_object( $response ) && method_exists( $response, 'get_status' ) ) {
				$status = absint( $response->get_status() );
				$code   = 'http_' . $status;
			}
			if ( $status >= 500 || ( $status >= 400 && ! in_array( $status, array( 401, 403, 404, 409, 423, 429 ), true ) ) ) {
				self::capture(
					$status >= 500 ? 'error' : 'warning',
					'rest.' . sanitize_key( $code ),
					'' !== $message ? $message : 'REST route returned HTTP ' . $status . '.',
					array(
						'route'  => self::endpoint_path( $route ),
						'method' => is_object( $request ) && method_exists( $request, 'get_method' ) ? (string) $request->get_method() : '',
						'status' => $status,
					)
				);
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $response;
	}

	/**
	 * Shutdown: capture a fatal raised in a plugin file, a pending Mobo DB error,
	 * then persist the buffer.
	 *
	 * @return void
	 */
	public static function on_shutdown() {
		try {
			$error = function_exists( 'error_get_last' ) ? error_get_last() : null;
			$fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
			if ( is_array( $error ) && isset( $error['type'] ) && ( (int) $error['type'] & $fatal ) && self::is_plugin_file( isset( $error['file'] ) ? $error['file'] : '' ) ) {
				self::capture( 'fatal', 'php_fatal', isset( $error['message'] ) ? (string) $error['message'] : '', array( 'type' => (int) $error['type'] ), (string) $error['file'], isset( $error['line'] ) ? (int) $error['line'] : 0 );
			}
			self::capture_db_error( 'shutdown', true );
		} catch ( Throwable $e ) {
			unset( $e );
		}
		self::flush();
	}

	/**
	 * Whether a file path belongs to this plugin.
	 *
	 * @param string $file Path.
	 * @return bool
	 */
	public static function is_plugin_file( $file ) {
		$file = str_replace( '\\', '/', (string) $file );
		if ( '' === $file ) {
			return false;
		}
		$root = defined( 'MOBO_CORE_PLUGIN_DIR' ) ? (string) MOBO_CORE_PLUGIN_DIR : dirname( __DIR__ ) . '/';
		$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
		return 0 === strpos( $file, $root ) || false !== strpos( $file, '/plugins/mobo-core/' );
	}

	/* ------------------------------------------------------------------ *
	 * Persistence
	 * ------------------------------------------------------------------ */

	/**
	 * Persist buffered captures and events. Best effort; never throws.
	 *
	 * @return bool
	 */
	public static function flush() {
		if ( self::$busy || ( empty( self::$buffer ) && empty( self::$events ) ) || ! function_exists( 'get_option' ) ) {
			return true;
		}
		self::$busy = true;
		$buffer = self::$buffer;
		$events = self::$events;
		self::$buffer = array();
		self::$events = array();
		try {
			if ( ! empty( $buffer ) ) {
				self::merge_into_storage( $buffer );
			}
			if ( ! empty( $events ) ) {
				$stored = get_option( self::EVENTS_OPTION, array() );
				$stored = is_array( $stored ) ? array_values( $stored ) : array();
				$stored = array_slice( array_merge( $stored, $events ), -self::MAX_EVENTS );
				update_option( self::EVENTS_OPTION, $stored, false );
			}
			return true;
		} catch ( Throwable $e ) {
			return false;
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * Merge captures into the chunked ring buffer.
	 *
	 * @param array<string,array> $buffer Captures.
	 * @return void
	 */
	private static function merge_into_storage( $buffer ) {
		$index = self::read_index();
		$chunks = array();
		$load = static function ( $c ) use ( &$chunks ) {
			if ( ! isset( $chunks[ $c ] ) ) {
				$stored = get_option( self::CHUNK_PREFIX . $c, array() );
				$chunks[ $c ] = is_array( $stored ) ? $stored : array();
			}
		};

		foreach ( $buffer as $sig => $entry ) {
			if ( isset( $index['sigs'][ $sig ] ) ) {
				$c = absint( $index['sigs'][ $sig ] );
				$load( $c );
				if ( isset( $chunks[ $c ][ $sig ] ) && is_array( $chunks[ $c ][ $sig ] ) ) {
					$existing = $chunks[ $c ][ $sig ];
					$existing['count'] = absint( $existing['count'] ?? 0 ) + absint( $entry['count'] );
					$existing['last']  = max( absint( $existing['last'] ?? 0 ), absint( $entry['last'] ) );
					$existing['first'] = min( absint( $existing['first'] ?? $entry['first'] ), absint( $entry['first'] ) );
					$existing['cid']   = (string) $entry['cid'];
					$existing['ctx']   = $entry['ctx'];
					$chunks[ $c ][ $sig ] = $existing;
					continue;
				}
				unset( $index['sigs'][ $sig ] );
			}
			$c = absint( $index['head'] ) % self::CHUNKS;
			$load( $c );
			if ( count( $chunks[ $c ] ) >= self::CHUNK_SIZE ) {
				$c = ( $c + 1 ) % self::CHUNKS;
				$index['head'] = $c;
				$load( $c );
				foreach ( array_keys( $chunks[ $c ] ) as $old_sig ) {
					unset( $index['sigs'][ $old_sig ] );
				}
				$chunks[ $c ] = array();
			}
			$chunks[ $c ][ $sig ] = $entry;
			$index['sigs'][ $sig ] = $c;
		}

		foreach ( $chunks as $c => $entries ) {
			$entries = self::cap_chunk_bytes( $entries, $index );
			update_option( self::CHUNK_PREFIX . $c, $entries, false );
		}
		$index['updatedAt'] = time();
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Keep one chunk under CHUNK_MAX_BYTES: drop contexts first, then the
	 * least-recently-seen entries.
	 *
	 * @param array $entries Entries.
	 * @param array $index   Index (by reference for evictions).
	 * @return array
	 */
	private static function cap_chunk_bytes( $entries, &$index ) {
		if ( strlen( (string) wp_json_encode( $entries ) ) <= self::CHUNK_MAX_BYTES ) {
			return $entries;
		}
		foreach ( $entries as $sig => $entry ) {
			$entries[ $sig ]['ctx'] = array( 'truncated' => true );
		}
		uasort(
			$entries,
			static function ( $a, $b ) {
				return absint( $a['last'] ?? 0 ) <=> absint( $b['last'] ?? 0 );
			}
		);
		while ( ! empty( $entries ) && strlen( (string) wp_json_encode( $entries ) ) > self::CHUNK_MAX_BYTES ) {
			$sig = (string) key( $entries );
			unset( $entries[ $sig ], $index['sigs'][ $sig ] );
		}
		return $entries;
	}

	/** @return array Normalized index. */
	private static function read_index() {
		$index = get_option( self::INDEX_OPTION, array() );
		$index = is_array( $index ) ? $index : array();
		return array(
			'v'         => 1,
			'head'      => absint( $index['head'] ?? 0 ) % self::CHUNKS,
			'sigs'      => isset( $index['sigs'] ) && is_array( $index['sigs'] ) ? $index['sigs'] : array(),
			'updatedAt' => absint( $index['updatedAt'] ?? 0 ),
		);
	}

	/**
	 * All stored entries (raw), newest lastSeen first, including unflushed ones.
	 *
	 * @return array[]
	 */
	private static function all_entries() {
		$entries = array();
		if ( function_exists( 'get_option' ) ) {
			for ( $c = 0; $c < self::CHUNKS; $c++ ) {
				$stored = get_option( self::CHUNK_PREFIX . $c, array() );
				if ( is_array( $stored ) ) {
					foreach ( $stored as $sig => $entry ) {
						if ( is_array( $entry ) ) {
							$entries[ (string) $sig ] = $entry;
						}
					}
				}
			}
		}
		foreach ( self::$buffer as $sig => $entry ) {
			if ( isset( $entries[ $sig ] ) ) {
				$entries[ $sig ]['count'] = absint( $entries[ $sig ]['count'] ?? 0 ) + absint( $entry['count'] );
				$entries[ $sig ]['last']  = max( absint( $entries[ $sig ]['last'] ?? 0 ), absint( $entry['last'] ) );
				$entries[ $sig ]['cid']   = $entry['cid'];
			} else {
				$entries[ $sig ] = $entry;
			}
		}
		uasort(
			$entries,
			static function ( $a, $b ) {
				return absint( $b['last'] ?? 0 ) <=> absint( $a['last'] ?? 0 );
			}
		);
		return array_values( $entries );
	}

	/**
	 * Recent errors in the public format.
	 *
	 * @param int    $since_ts    Only entries seen at/after this timestamp (0 = all).
	 * @param string $level       Level filter ('' = all).
	 * @param string $correlation Correlation filter (substring, '' = all).
	 * @param int    $limit       Max rows.
	 * @return array[]
	 */
	public static function recent( $since_ts = 0, $level = '', $correlation = '', $limit = self::MAX_ENTRIES ) {
		$out = array();
		$level = (string) $level;
		$correlation = (string) $correlation;
		foreach ( self::all_entries() as $entry ) {
			if ( absint( $entry['last'] ?? 0 ) < absint( $since_ts ) ) {
				continue;
			}
			if ( '' !== $level && $level !== (string) ( $entry['level'] ?? '' ) ) {
				continue;
			}
			if ( '' !== $correlation && false === strpos( (string) ( $entry['cid'] ?? '' ), $correlation ) ) {
				continue;
			}
			$out[] = self::present( $entry );
			if ( count( $out ) >= max( 1, absint( $limit ) ) ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Public representation of one entry.
	 *
	 * @param array $entry Raw entry.
	 * @return array
	 */
	private static function present( $entry ) {
		$last  = absint( $entry['last'] ?? 0 );
		$first = absint( $entry['first'] ?? $last );
		return array(
			'time'          => $last > 0 ? gmdate( 'c', $last ) : null,
			'firstSeen'     => $first > 0 ? gmdate( 'c', $first ) : null,
			'lastSeen'      => $last > 0 ? gmdate( 'c', $last ) : null,
			'level'         => (string) ( $entry['level'] ?? 'error' ),
			'code'          => (string) ( $entry['code'] ?? '' ),
			'message'       => (string) ( $entry['msg'] ?? '' ),
			'file'          => (string) ( $entry['file'] ?? '' ),
			'line'          => absint( $entry['line'] ?? 0 ),
			'correlationId' => (string) ( $entry['cid'] ?? '' ),
			'count'         => max( 1, absint( $entry['count'] ?? 1 ) ),
			'context'       => isset( $entry['ctx'] ) && is_array( $entry['ctx'] ) ? $entry['ctx'] : array(),
		);
	}

	/**
	 * Recent events (newest first).
	 *
	 * @param int $since_ts Since timestamp.
	 * @return array[]
	 */
	public static function recent_events( $since_ts = 0 ) {
		$stored = function_exists( 'get_option' ) ? get_option( self::EVENTS_OPTION, array() ) : array();
		$stored = array_merge( is_array( $stored ) ? array_values( $stored ) : array(), self::$events );
		$out = array();
		foreach ( array_reverse( $stored ) as $event ) {
			if ( ! is_array( $event ) || absint( $event['at'] ?? 0 ) < absint( $since_ts ) ) {
				continue;
			}
			$out[] = array(
				'time'          => gmdate( 'c', absint( $event['at'] ?? 0 ) ),
				'kind'          => (string) ( $event['kind'] ?? '' ),
				'id'            => (string) ( $event['id'] ?? '' ),
				'status'        => (string) ( $event['status'] ?? '' ),
				'durationMs'    => absint( $event['durationMs'] ?? 0 ),
				'correlationId' => (string) ( $event['cid'] ?? '' ),
				'context'       => isset( $event['ctx'] ) && is_array( $event['ctx'] ) ? $event['ctx'] : array(),
			);
		}
		return $out;
	}

	/**
	 * Small health summary.
	 *
	 * @param array $disabled_impact disabledFunctionsImpact list (from the probe).
	 * @return array{errors24h:int,lastErrorAt:?string,lastErrorCode:string,topErrors:array,disabledFunctionsImpact:array}
	 */
	public static function summary( $disabled_impact = array() ) {
		$since  = time() - DAY_IN_SECONDS;
		$total  = 0;
		$codes  = array();
		$last   = 0;
		$last_code = '';
		foreach ( self::all_entries() as $entry ) {
			$seen = absint( $entry['last'] ?? 0 );
			if ( $seen > $last ) {
				$last      = $seen;
				$last_code = (string) ( $entry['code'] ?? '' );
			}
			if ( $seen < $since || 'notice' === ( $entry['level'] ?? '' ) ) {
				continue;
			}
			$count = max( 1, absint( $entry['count'] ?? 1 ) );
			$total += $count;
			$code = (string) ( $entry['code'] ?? '' );
			$codes[ $code ] = ( $codes[ $code ] ?? 0 ) + $count;
		}
		arsort( $codes );
		$top = array();
		foreach ( array_slice( $codes, 0, 5, true ) as $code => $count ) {
			$top[] = array( 'code' => (string) $code, 'count' => (int) $count );
		}
		return array(
			'errors24h'               => $total,
			'lastErrorAt'             => $last > 0 ? gmdate( 'c', $last ) : null,
			'lastErrorCode'           => $last_code,
			'topErrors'               => $top,
			'disabledFunctionsImpact' => array_values( is_array( $disabled_impact ) ? $disabled_impact : array() ),
		);
	}

	/**
	 * Delete the stored log and events.
	 *
	 * @return void
	 */
	public static function clear() {
		self::$buffer = array();
		self::$events = array();
		if ( ! function_exists( 'delete_option' ) ) {
			return;
		}
		for ( $c = 0; $c < self::CHUNKS; $c++ ) {
			delete_option( self::CHUNK_PREFIX . $c );
		}
		delete_option( self::INDEX_OPTION );
		delete_option( self::EVENTS_OPTION );
	}

	/* ------------------------------------------------------------------ *
	 * Scrubbing
	 * ------------------------------------------------------------------ */

	/**
	 * Remove secrets and mask PII inside free text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function scrub_text( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = (string) preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text );
		}
		$replace = array(
			/* Authorization headers / bearer tokens / cookies. */
			'/\b(authorization|cookie|set-cookie|x-sec|x-mobo-[a-z\-]*signature|x-wp-nonce)\s*[:=]\s*[^\r\n,;]+/i' => '$1: [redacted]',
			'/\b(bearer|basic)\s+[A-Za-z0-9._~+\/=\-]{6,}/i' => '$1 [redacted]',
			/* key=value / "key":"value" secrets. */
			'/(["\']?(?:license[_\-]?token|token|secret|password|passwd|pwd|pass|api[_\-]?key|security[_\-]?code|x[_\-]?sec|nonce|signature|sig|cookie|session|auth[a-z_\-]*|card[_\-]?number|cvv2?|iban|sheba|wallet[a-z_\-]*)["\']?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s&,;}\]]+)/i' => '$1[redacted]',
		);
		foreach ( $replace as $pattern => $replacement ) {
			$text = (string) preg_replace( $pattern, $replacement, $text );
		}
		$callbacks = array(
			/* URLs: drop query string and fragment. */
			'#\b(https?://[^\s?\#"\'<>]+)(\?[^\s"\'<>]*)?(\#[^\s"\'<>]*)?#i' => static function ( $m ) {
				return $m[1] . ( isset( $m[2] ) && '' !== $m[2] ? '?[query-removed]' : '' );
			},
			/* Emails. */
			'/[A-Za-z0-9._%+\-]+@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/' => static function ( $m ) {
				return self::mask( (string) strstr( $m[0], '@', true ) ) . '@' . self::mask( $m[1] );
			},
			/* IBAN / Sheba, then card-like numbers (13-19 digits). */
			'/\bIR\d{2}[\s\-]?(?:\d[\s\-]?){20,22}\b/i' => static function () {
				return '[iban-redacted]';
			},
			'/(?<!\d)(?:\d[ \-]?){12,18}\d(?!\d)/' => static function () {
				return '[number-redacted]';
			},
			/* Iranian mobile / international phone numbers. */
			'/(?<!\d)(?:\+98|0098|0)?9\d{9}(?!\d)|(?<![\d+])(?:\+|00)\d{10,14}(?!\d)/' => static function ( $m ) {
				$digits = (string) preg_replace( '/\D/', '', $m[0] );
				return substr( $digits, 0, 4 ) . str_repeat( '*', max( 0, strlen( $digits ) - 6 ) ) . substr( $digits, -2 );
			},
			/* Absolute paths => plugin-relative or basename. */
			'#(?:[A-Za-z]:)?[\\\\/](?:[^\s:\'"\\\\/]+[\\\\/])+[^\s:\'"\\\\/]+\.php#' => static function ( $m ) {
				return self::relative_file( $m[0] );
			},
			/* Long opaque blobs (tokens, hashes, base64) that mix letters and digits;
			 * UUIDs (event/sync ids) are kept. */
			'/(?<![A-Za-z0-9+_\-])(?=[A-Za-z0-9+_\-]*\d)(?=[A-Za-z0-9+_\-]*[A-Za-z])[A-Za-z0-9+_\-]{32,}={0,2}/' => static function ( $m ) {
				return preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $m[0] ) ? $m[0] : '[redacted-blob]';
			},
		);
		foreach ( $callbacks as $pattern => $callback ) {
			$text = (string) preg_replace_callback( $pattern, $callback, $text );
		}
		$text = (string) preg_replace( '/[۰-۹]{10,}/u', '[number-redacted]', $text );
		return $text;
	}

	/**
	 * Scrub a context array: drop secret keys, mask PII keys, scrub values.
	 *
	 * @param array $context Context.
	 * @param int   $depth   Depth.
	 * @return array
	 */
	public static function scrub_context( $context, $depth = 0 ) {
		if ( ! is_array( $context ) || $depth > 3 ) {
			return array();
		}
		$out = array();
		$n = 0;
		foreach ( $context as $key => $value ) {
			if ( ++$n > 25 ) {
				$out['truncated'] = true;
				break;
			}
			$key = substr( (string) $key, 0, 64 );
			if ( preg_match( '/token|secret|passw|pwd|cookie|nonce|signature|^sig$|auth|license|api[_\-]?key|security[_\-]?code|x[_\-]?sec|session|card|cvv|iban|sheba|wallet|credit|private[_\-]?key|^headers?$/i', $key ) ) {
				continue;
			}
			$pii = (bool) preg_match( '/name|phone|mobile|tel$|email|e_mail|address|addr|postcode|postal|zip|national|melli|recipient|customer_note|ip$/i', $key )
				&& ! preg_match( '/^(code|file|route|method|status|level|endpoint|stage|where|query|type|exceptionclass|job|step|event|kind)$/i', $key );
			if ( is_array( $value ) ) {
				$out[ $key ] = $pii ? '[masked]' : self::scrub_context( $value, $depth + 1 );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$out[ $key ] = $pii && ! is_bool( $value ) && null !== $value ? self::mask( (string) $value ) : $value;
			} elseif ( is_scalar( $value ) ) {
				$value = (string) $value;
				$out[ $key ] = $pii ? self::mask( $value ) : self::clip( self::scrub_text( $value ), 300 );
			}
		}
		return $out;
	}

	/**
	 * Mask a PII value: keep the first character (and last two for long values).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function mask( $value ) {
		$value = trim( (string) $value );
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		if ( $len <= 0 ) {
			return '';
		}
		$first = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 1, 'UTF-8' ) : substr( $value, 0, 1 );
		if ( $len <= 4 ) {
			return $first . '***';
		}
		$last = function_exists( 'mb_substr' ) ? mb_substr( $value, -2, 2, 'UTF-8' ) : substr( $value, -2 );
		return $first . '***' . $last;
	}

	/**
	 * Path of an endpoint without scheme/host/query (no secrets in query strings).
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function endpoint_path( $url ) {
		$url  = (string) $url;
		$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_PATH ) : parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$path = is_string( $path ) ? $path : '';
		/* Opaque path segments (signed tokens, GUID-like secrets) are shortened. */
		$path = preg_replace( '#/[A-Za-z0-9_\-]{40,}#', '/[id]', $path );
		return substr( (string) $path, 0, 200 );
	}

	/**
	 * SQL statement shape without literal values.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	public static function query_shape( $sql ) {
		$sql = (string) $sql;
		$sql = preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql );
		$sql = preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/s', '?', $sql );
		$sql = preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', $sql );
		$sql = preg_replace( '/\s+/', ' ', $sql );
		return self::clip( trim( (string) $sql ), 300 );
	}

	/**
	 * Plugin-relative path (or basename outside the plugin).
	 *
	 * @param string $file Path.
	 * @return string
	 */
	public static function relative_file( $file ) {
		$file = str_replace( '\\', '/', (string) $file );
		if ( '' === $file ) {
			return '';
		}
		$pos = strpos( $file, '/mobo-core/' );
		return false === $pos ? basename( $file ) : substr( $file, $pos + 1 );
	}

	/**
	 * Signature normalization: digits collapse so "product 12 failed" and
	 * "product 13 failed" dedupe into one entry.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize_for_signature( $text ) {
		return (string) preg_replace( '/\d+/', '#', strtolower( (string) $text ) );
	}

	/**
	 * Clip to $max characters.
	 *
	 * @param string $value Value.
	 * @param int    $max   Max.
	 * @return string
	 */
	private static function clip( $value, $max ) {
		$value = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $value, 0, $max, 'UTF-8' );
		}
		return substr( $value, 0, $max );
	}

	/** Test helper: drop in-memory state. @return void */
	public static function reset_runtime() {
		self::$buffer        = array();
		self::$events        = array();
		self::$busy          = false;
		self::$last_db_error = '';
	}
}
