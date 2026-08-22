<?php
/**
 * Deferred, exact-product cache warmup queue.
 *
 * Product synchronization must never block on a front-end page render. After a
 * targeted page-cache purge succeeds, the current product permalink is queued
 * here. The real Mobo cron later performs an anonymous GET for that exact URL,
 * allowing the active page-cache layer to regenerate only the product page.
 *
 * No home/category/tag/shop URLs are warmed by this class.
 *
 * PHP 7.4 compatible.
 *
 * @package MoboCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Cache_Warmer {

	const OPTION_QUEUE       = 'mobo_core_cache_warmup_queue';
	const OPTION_LAST_RESULT = 'mobo_core_cache_warmup_last_result';
	const QUEUE_SCHEMA       = 1;
	const QUEUE_MAX_ITEMS    = 5000;
	const LOCK_NAME          = 'cache_warmup_queue';

	/**
	 * Queue the current permalink of one product.
	 *
	 * Re-queueing the same product/URL resets its retry state so the newest
	 * mutation always gets a fresh warmup attempt.
	 *
	 * @param int    $product_id WooCommerce product ID.
	 * @param string $url Optional current permalink.
	 * @param string $reason Queue reason.
	 * @return array
	 */
	public static function enqueue_product( $product_id, $url = '', $reason = 'product-updated' ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return array( 'success' => false, 'status' => 'invalid-product', 'queued' => false );
		}

		if ( '' === (string) $url ) {
			$url = get_permalink( $product_id );
		}

		$url = self::normalize_frontend_url( $url );
		if ( '' === $url ) {
			return array( 'success' => false, 'status' => 'invalid-url', 'queued' => false );
		}

		return self::enqueue_url( $url, $product_id, $reason );
	}

	/**
	 * Queue one exact same-origin front-end URL.
	 *
	 * @param string $url Absolute URL.
	 * @param int    $product_id Product ID for diagnostics/deduplication.
	 * @param string $reason Reason.
	 * @return array
	 */
	public static function enqueue_url( $url, $product_id = 0, $reason = 'product-updated' ) {
		$url        = self::normalize_frontend_url( $url );
		$product_id = absint( $product_id );
		$reason     = sanitize_key( (string) $reason );

		if ( '' === $url ) {
			return array( 'success' => false, 'status' => 'invalid-url', 'queued' => false );
		}

		$lock = self::acquire_lock();
		if ( false === $lock ) {
			return array( 'success' => false, 'status' => 'locked', 'queued' => false );
		}

		try {
			$queue    = self::read_queue();
			$now      = time();
			$key      = self::item_key( $url, $product_id );
			$debounce = Mobo_Core_Settings::get_int( 'mobo_core_cache_warmup_debounce_seconds', 15, 0, 120 );

			$queue['items'][ $key ] = array(
				'url'           => $url,
				'productId'     => $product_id,
				'reason'        => '' !== $reason ? $reason : 'product-updated',
				'queuedAt'      => isset( $queue['items'][ $key ]['queuedAt'] ) ? absint( $queue['items'][ $key ]['queuedAt'] ) : $now,
				'updatedAt'     => $now,
				'attempts'      => 0,
				/* Every new mutation pushes the same exact URL's warmup window forward. */
				'nextAttemptAt' => $now + $debounce,
				'lastError'     => '',
			);

			/* Keep newest items when a pathological bulk import exceeds the bound. */
			if ( count( $queue['items'] ) > self::QUEUE_MAX_ITEMS ) {
				uasort(
					$queue['items'],
					static function ( $a, $b ) {
						return absint( isset( $b['updatedAt'] ) ? $b['updatedAt'] : 0 ) <=> absint( isset( $a['updatedAt'] ) ? $a['updatedAt'] : 0 );
					}
				);
				$queue['items'] = array_slice( $queue['items'], 0, self::QUEUE_MAX_ITEMS, true );
			}

			$queue['updatedAt'] = $now;
			self::write_queue( $queue );

			return array(
				'success'      => true,
				'status'       => 'queued',
				'queued'       => true,
				'pendingCount' => count( $queue['items'] ),
			);
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Process a bounded number of due product warmups.
	 *
	 * @param int    $limit Maximum URLs in this call.
	 * @param int    $time_budget_seconds Cooperative time budget.
	 * @param string $source Runner source.
	 * @return array
	 */
	public static function process_batch( $limit = 2, $time_budget_seconds = 10, $source = 'real-cron' ) {
		$limit               = max( 1, min( 10, absint( $limit ) ) );
		$time_budget_seconds = max( 2, min( 30, absint( $time_budget_seconds ) ) );
		$source              = sanitize_key( (string) $source );
		$post_recovery_serial = class_exists( 'Mobo_Core_Recovery_Coordinator' ) && Mobo_Core_Recovery_Coordinator::post_recovery_warmup_pending();
		if ( $post_recovery_serial ) {
			$limit = 1;
		}
		$started             = microtime( true );
		$deadline            = $started + $time_budget_seconds;

		$result = array(
			'success'      => true,
			'status'       => 'empty',
			'processed'    => 0,
			'warmed'       => 0,
			'failed'       => 0,
			'dropped'      => 0,
			'deferred'     => 0,
			'remaining'    => false,
			'remainingDue' => false,
			'pendingCount' => 0,
			'lastError'    => '',
			'source'       => $source,
		);

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_cache_warmup_enabled', '1' ) ) {
			$result['status'] = 'disabled';
			self::persist_last_result( $result, $started );
			return $result;
		}

		/* Recovery owns the site mutation pipeline. Warmup is a strictly post-recovery
		 * side effect and must never race product recreation/cache invalidation. */
		if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) && Mobo_Core_Recovery_Coordinator::recovery_pending() ) {
			Mobo_Core_Recovery_Coordinator::mark_post_recovery_warmup_pending();
			$result['status']    = 'deferred-recovery';
			$result['remaining'] = true;
			self::persist_last_result( $result, $started );
			return $result;
		}

		$pipeline_lock = class_exists( 'Mobo_Core_Recovery_Coordinator' ) ? Mobo_Core_Recovery_Coordinator::acquire( 300 ) : '__mobo_no_lock__';
		if ( false === $pipeline_lock ) {
			$result['status']    = 'recovery-pipeline-locked';
			$result['remaining'] = true;
			return $result;
		}

		/* Close the schedule-vs-warmup race after obtaining the shared lease. */
		if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) && Mobo_Core_Recovery_Coordinator::recovery_pending() ) {
			Mobo_Core_Recovery_Coordinator::mark_post_recovery_warmup_pending();
			Mobo_Core_Recovery_Coordinator::release( $pipeline_lock );
			$result['status']    = 'deferred-recovery';
			$result['remaining'] = true;
			self::persist_last_result( $result, $started );
			return $result;
		}

		$lock = self::acquire_lock();
		if ( false === $lock ) {
			if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) ) {
				Mobo_Core_Recovery_Coordinator::release( $pipeline_lock );
			}
			$result['success'] = false;
			$result['status']  = 'locked';
			return $result;
		}

		try {
			$max_attempts = Mobo_Core_Settings::get_int( 'mobo_core_cache_warmup_max_attempts', 5, 1, 10 );
			$base_timeout = Mobo_Core_Settings::get_int( 'mobo_core_cache_warmup_timeout_seconds', 8, 2, 20 );

			while ( $result['processed'] < $limit && microtime( true ) < ( $deadline - 0.5 ) ) {
				/* Recovery scheduled after this batch started wins the next mutation slot. */
				if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) && Mobo_Core_Recovery_Coordinator::recovery_pending() ) {
					Mobo_Core_Recovery_Coordinator::mark_post_recovery_warmup_pending();
					$result['status']    = 'deferred-recovery';
					$result['remaining'] = true;
					break;
				}

				$queue = self::read_queue();
				if ( empty( $queue['items'] ) ) {
					break;
				}

				$due = array();
				$now = time();
				foreach ( $queue['items'] as $key => $item ) {
					if ( self::item_is_due( $item, $now ) ) {
						$due[ $key ] = $item;
					}
				}

				if ( empty( $due ) ) {
					break;
				}

				uasort(
					$due,
					static function ( $a, $b ) {
						$next_a = absint( isset( $a['nextAttemptAt'] ) ? $a['nextAttemptAt'] : 0 );
						$next_b = absint( isset( $b['nextAttemptAt'] ) ? $b['nextAttemptAt'] : 0 );
						if ( $next_a === $next_b ) {
							return absint( isset( $a['queuedAt'] ) ? $a['queuedAt'] : 0 ) <=> absint( isset( $b['queuedAt'] ) ? $b['queuedAt'] : 0 );
						}
						return $next_a <=> $next_b;
					}
				);

				$key  = (string) array_key_first( $due );
				$item = $due[ $key ];
				$url  = self::normalize_frontend_url( isset( $item['url'] ) ? $item['url'] : '' );
				if ( '' === $url ) {
					unset( $queue['items'][ $key ] );
					self::write_queue( $queue );
					$result['processed']++;
					$result['dropped']++;
					continue;
				}

				$remaining_seconds = max( 2, (int) floor( $deadline - microtime( true ) ) );
				$request_timeout   = max( 2, min( $base_timeout, $remaining_seconds ) );
				$claim_token       = wp_generate_uuid4();
				$claim_until       = time() + max( 120, $request_timeout + 30 );

				/*
				 * Persist a short claim, then release the queue mutex before the HTTP GET.
				 * enqueue_url() may now record a newer mutation while this request is in
				 * flight. A newer enqueue overwrites the claim, so the stale response below
				 * cannot delete or postpone that newer queue item.
				 */
				$queue['items'][ $key ]['processingToken'] = $claim_token;
				$queue['items'][ $key ]['processingUntil'] = $claim_until;
				$queue['items'][ $key ]['updatedAt']       = time();
				self::write_queue( $queue );

				self::release_lock( $lock );
				$lock = false;

				try {
					$response = self::warm_url( $url, $request_timeout );
				} catch ( Throwable $e ) {
					$response = array(
						'success' => false,
						'code'    => 0,
						'error'   => $e->getMessage(),
					);
				}
				$result['processed']++;

				$lock = self::acquire_lock();
				if ( false === $lock ) {
					$result['success']   = false;
					$result['status']    = 'commit-lock-lost';
					$result['remaining'] = true;
					self::persist_last_result( $result, $started );
					return $result;
				}

				$queue   = self::read_queue();
				$current = isset( $queue['items'][ $key ] ) && is_array( $queue['items'][ $key ] ) ? $queue['items'][ $key ] : array();
				$current_token = isset( $current['processingToken'] ) ? (string) $current['processingToken'] : '';

				if ( '' === $current_token || ! hash_equals( $claim_token, $current_token ) ) {
					/* A newer enqueue/worker superseded this result. Never commit stale work. */
					$result['deferred']++;
					continue;
				}

				$attempt = absint( isset( $current['attempts'] ) ? $current['attempts'] : 0 ) + 1;
				if ( ! empty( $response['success'] ) ) {
					unset( $queue['items'][ $key ] );
					self::write_queue( $queue );
					$result['warmed']++;
					continue;
				}

				$error = isset( $response['error'] ) ? sanitize_text_field( (string) $response['error'] ) : 'Cache warmup failed.';
				$code  = isset( $response['code'] ) ? absint( $response['code'] ) : 0;
				$result['failed']++;
				$result['lastError'] = $error;

				/* 4xx cacheability/access outcomes are not transient warmup failures. */
				$terminal = in_array( $code, array( 400, 401, 403, 404, 405, 410 ), true );
				if ( $terminal || $attempt >= $max_attempts ) {
					unset( $queue['items'][ $key ] );
					self::write_queue( $queue );
					$result['dropped']++;
					continue;
				}

				$queue['items'][ $key ]['attempts']      = $attempt;
				$queue['items'][ $key ]['updatedAt']     = time();
				$queue['items'][ $key ]['nextAttemptAt'] = time() + self::retry_delay_seconds( $attempt );
				$queue['items'][ $key ]['lastError']     = $error;
				unset( $queue['items'][ $key ]['processingToken'], $queue['items'][ $key ]['processingUntil'] );
				self::write_queue( $queue );
			}

			$queue         = self::read_queue();
			$due_remaining = 0;
			$now           = time();
			foreach ( $queue['items'] as $item ) {
				if ( self::item_is_due( $item, $now ) ) {
					$due_remaining++;
				}
			}

			$result['pendingCount'] = count( $queue['items'] );
			$result['remaining']    = ! empty( $queue['items'] );
			$result['remainingDue'] = $due_remaining > 0;
			$result['deferred']     = max( $result['deferred'], count( $queue['items'] ) - $due_remaining );
			$result['status']       = $result['failed'] > 0
				? ( $result['warmed'] > 0 ? 'partial' : 'failed' )
				: ( $result['warmed'] > 0 ? 'success' : ( $result['remaining'] ? 'waiting' : 'empty' ) );
			$result['success']      = 'failed' !== $result['status'];
			if ( empty( $result['remaining'] ) && class_exists( 'Mobo_Core_Recovery_Coordinator' ) && Mobo_Core_Recovery_Coordinator::post_recovery_warmup_pending() ) {
				Mobo_Core_Recovery_Coordinator::clear_post_recovery_warmup_pending();
			}
			self::persist_last_result( $result, $started );

			return $result;
		} finally {
			self::release_lock( $lock );
			if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) ) {
				Mobo_Core_Recovery_Coordinator::release( $pipeline_lock );
			}
		}
	}

	/**
	 * Whether immediately runnable warmup work exists.
	 *
	 * @return bool
	 */
	public static function has_due_work() {
		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_cache_warmup_enabled', '1' ) ) {
			return false;
		}

		$queue = self::read_queue();
		$now   = time();
		foreach ( $queue['items'] as $item ) {
			if ( self::item_is_due( $item, $now ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compact queue/last-run status for Site Health.
	 *
	 * No URLs or product IDs are exposed.
	 *
	 * @return array
	 */
	public static function get_status() {
		$queue = self::read_queue();
		$last  = get_option( self::OPTION_LAST_RESULT, array() );
		$last  = is_array( $last ) ? $last : array();
		$now   = time();
		$due   = 0;
		$next  = 0;

		foreach ( $queue['items'] as $item ) {
			$next_at = absint( isset( $item['nextAttemptAt'] ) ? $item['nextAttemptAt'] : 0 );
			if ( self::item_is_due( $item, $now ) ) {
				$due++;
			} elseif ( ! self::item_is_actively_processing( $item, $now ) && $next_at > $now && ( 0 === $next || $next_at < $next ) ) {
				$next = $next_at;
			}
		}

		return array(
			'enabled'         => Mobo_Core_Settings::enabled( 'mobo_core_cache_warmup_enabled', '1' ),
			'pendingCount'    => count( $queue['items'] ),
			'dueCount'        => $due,
			'nextAttemptAt'   => self::format_timestamp( $next ),
			'lastAttemptAt'   => self::format_timestamp( isset( $last['attemptedAt'] ) ? absint( $last['attemptedAt'] ) : 0 ),
			'lastCompletedAt' => self::format_timestamp( isset( $last['completedAt'] ) ? absint( $last['completedAt'] ) : 0 ),
			'lastStatus'      => isset( $last['status'] ) ? sanitize_key( (string) $last['status'] ) : 'never-run',
			'lastProcessed'   => isset( $last['processed'] ) ? absint( $last['processed'] ) : 0,
			'lastWarmed'      => isset( $last['warmed'] ) ? absint( $last['warmed'] ) : 0,
			'lastFailed'      => isset( $last['failed'] ) ? absint( $last['failed'] ) : 0,
			'lastError'       => isset( $last['lastError'] ) ? sanitize_text_field( (string) $last['lastError'] ) : '',
		);
	}

	/**
	 * Execute one anonymous same-origin GET.
	 *
	 * @param string $url URL.
	 * @param int    $timeout Timeout seconds.
	 * @return array
	 */
	private static function warm_url( $url, $timeout ) {
		$current = self::normalize_frontend_url( $url );
		if ( '' === $current ) {
			return array( 'success' => false, 'code' => 0, 'error' => 'Warmup URL is invalid or not same-origin.' );
		}

		$timeout = max( 2, min( 20, absint( $timeout ) ) );
		for ( $redirect = 0; $redirect < 3; $redirect++ ) {
			$response = wp_remote_get(
				$current,
				array(
					'timeout'     => $timeout,
					'redirection' => 0,
					'blocking'    => true,
					'user-agent'  => 'Mozilla/5.0 (compatible; MoboCore-Cache-Warmer/' . ( defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : 'unknown' ) . ')',
					'headers'     => array(
						'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'code'    => 0,
					'error'   => $response->get_error_message(),
				);
			}

			$code = absint( wp_remote_retrieve_response_code( $response ) );
			if ( $code >= 200 && $code < 300 ) {
				return array( 'success' => true, 'code' => $code, 'error' => '' );
			}

			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				$next     = self::resolve_redirect_url( $location, $current );
				if ( '' === $next ) {
					return array( 'success' => false, 'code' => $code, 'error' => 'Warmup redirect was missing or left the site origin.' );
				}
				$current = $next;
				continue;
			}

			return array(
				'success' => false,
				'code'    => $code,
				'error'   => 'Warmup HTTP status ' . $code . '.',
			);
		}

		return array( 'success' => false, 'code' => 310, 'error' => 'Warmup exceeded same-origin redirect limit.' );
	}

	/**
	 * Normalize and enforce same-origin public front-end URL.
	 *
	 * We deliberately use wp_remote_get() rather than wp_safe_remote_get() after
	 * this check because customer domains can resolve to private/local addresses
	 * from inside their own hosting network. The URL itself must still be the
	 * current site's own HTTP(S) origin.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalize_frontend_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) ) {
			return '';
		}

		$scheme    = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host      = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$home_host = isset( $home['host'] ) ? strtolower( (string) $home['host'] ) : '';

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || '' === $home_host || $host !== $home_host ) {
			return '';
		}

		/*
		 * Do not allow an otherwise valid customer hostname to be used as a loopback
		 * tunnel into an arbitrary local service port. Standard 80/443 redirects are
		 * accepted; a custom port must match the configured home URL exactly.
		 */
		$url_port  = isset( $parts['port'] ) ? absint( $parts['port'] ) : 0;
		$home_port = isset( $home['port'] ) ? absint( $home['port'] ) : 0;
		if ( $home_port > 0 ) {
			if ( $url_port !== $home_port ) {
				return '';
			}
		} elseif ( $url_port > 0 ) {
			$standard_port = ( 'https' === $scheme ) ? 443 : 80;
			if ( $url_port !== $standard_port ) {
				return '';
			}
		}

		return $url;
	}

	/**
	 * Resolve only same-origin absolute/root-relative redirects.
	 *
	 * @param string $location Redirect Location header.
	 * @param string $current Current absolute URL.
	 * @return string
	 */
	private static function resolve_redirect_url( $location, $current ) {
		$location = trim( (string) $location );
		if ( '' === $location ) {
			return '';
		}

		if ( 0 === strpos( $location, '//' ) ) {
			$current_parts = wp_parse_url( $current );
			$scheme = is_array( $current_parts ) && isset( $current_parts['scheme'] ) ? strtolower( (string) $current_parts['scheme'] ) : 'https';
			$location = $scheme . ':' . $location;
		} elseif ( 0 === strpos( $location, '/' ) ) {
			$current_parts = wp_parse_url( $current );
			if ( ! is_array( $current_parts ) || empty( $current_parts['scheme'] ) || empty( $current_parts['host'] ) ) {
				return '';
			}
			$authority = strtolower( (string) $current_parts['scheme'] ) . '://' . (string) $current_parts['host'];
			if ( isset( $current_parts['port'] ) && absint( $current_parts['port'] ) > 0 ) {
				$authority .= ':' . absint( $current_parts['port'] );
			}
			$location = $authority . $location;
		}

		return self::normalize_frontend_url( $location );
	}


	/**
	 * Whether a queue item is currently owned by a non-expired processor.
	 *
	 * @param array $item Queue item.
	 * @param int   $now Current epoch.
	 * @return bool
	 */
	private static function item_is_actively_processing( $item, $now ) {
		if ( ! is_array( $item ) ) {
			return false;
		}
		$token = isset( $item['processingToken'] ) ? trim( (string) $item['processingToken'] ) : '';
		$until = absint( isset( $item['processingUntil'] ) ? $item['processingUntil'] : 0 );
		return '' !== $token && $until > absint( $now );
	}

	/**
	 * Whether a queue item can be claimed now.
	 *
	 * Expired processing claims are deliberately reclaimable after a worker
	 * crash. A live claim is not reported as due, preventing duplicate GETs.
	 *
	 * @param array $item Queue item.
	 * @param int   $now Current epoch.
	 * @return bool
	 */
	private static function item_is_due( $item, $now ) {
		if ( ! is_array( $item ) || self::item_is_actively_processing( $item, $now ) ) {
			return false;
		}
		return absint( isset( $item['nextAttemptAt'] ) ? $item['nextAttemptAt'] : 0 ) <= absint( $now );
	}

	private static function retry_delay_seconds( $attempt ) {
		$attempt = max( 1, absint( $attempt ) );
		$delays  = array( 60, 300, 900, 3600, 21600 );
		$index   = min( count( $delays ) - 1, $attempt - 1 );
		return $delays[ $index ];
	}

	private static function item_key( $url, $product_id ) {
		return sha1( absint( $product_id ) . '|' . strtolower( (string) $url ) );
	}

	private static function read_queue() {
		$queue = get_option( self::OPTION_QUEUE, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		$queue['schemaVersion'] = self::QUEUE_SCHEMA;
		$queue['items']         = isset( $queue['items'] ) && is_array( $queue['items'] ) ? $queue['items'] : array();
		$queue['updatedAt']     = isset( $queue['updatedAt'] ) ? absint( $queue['updatedAt'] ) : 0;
		return $queue;
	}

	private static function write_queue( $queue ) {
		$queue['schemaVersion'] = self::QUEUE_SCHEMA;
		$queue['items']         = isset( $queue['items'] ) && is_array( $queue['items'] ) ? $queue['items'] : array();
		$queue['updatedAt']     = time();

		if ( empty( $queue['items'] ) ) {
			delete_option( self::OPTION_QUEUE );
			return;
		}

		if ( false === get_option( self::OPTION_QUEUE, false ) ) {
			add_option( self::OPTION_QUEUE, $queue, '', false );
		} else {
			update_option( self::OPTION_QUEUE, $queue, false );
		}
	}

	private static function acquire_lock() {
		if ( ! class_exists( 'Mobo_Core_Lock' ) ) {
			return '__mobo_no_lock__';
		}
		return Mobo_Core_Lock::acquire( self::LOCK_NAME, 45 );
	}

	private static function release_lock( $token ) {
		if ( '__mobo_no_lock__' === $token || false === $token || ! class_exists( 'Mobo_Core_Lock' ) ) {
			return;
		}
		Mobo_Core_Lock::release( self::LOCK_NAME, $token );
	}

	private static function persist_last_result( $result, $started ) {
		$record = is_array( $result ) ? $result : array();
		$record['pluginVersion'] = defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '';
		$record['attemptedAt']    = isset( $record['attemptedAt'] ) ? absint( $record['attemptedAt'] ) : time();
		$record['completedAt']    = time();
		$record['durationMs']     = max( 0, (int) round( ( microtime( true ) - (float) $started ) * 1000 ) );
		unset( $record['urls'], $record['items'] );
		update_option( self::OPTION_LAST_RESULT, $record, false );
	}

	private static function format_timestamp( $timestamp ) {
		$timestamp = absint( $timestamp );
		return $timestamp > 0 ? gmdate( 'c', $timestamp ) : '';
	}
}
