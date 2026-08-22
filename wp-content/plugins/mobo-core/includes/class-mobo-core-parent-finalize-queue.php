<?php
/**
 * Deferred parent-product finalization for variation delta bursts.
 *
 * Individual UpdateVariant events can update several variations of the same
 * variable product in one webhook batch. Recalculating the parent after every
 * variation is wasteful and also causes repeated page-cache invalidation. This
 * queue coalesces those mutations and finalizes each parent once per runner
 * pass, after the webhook batch has converged as far as that pass can take it.
 *
 * PHP 7.4 compatible.
 *
 * @package MoboCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Parent_Finalize_Queue {

	const OPTION_QUEUE = 'mobo_core_parent_finalize_queue';
	const LOCK_NAME    = 'parent_finalize_queue';
	const MAX_ITEMS    = 5000;

	/**
	 * Queue one variable-product parent for a later single recalculation.
	 *
	 * Repeated calls for the same product are intentionally collapsed.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $product_guid Remote product GUID.
	 * @param string $reason Reason.
	 * @return array
	 */
	public static function enqueue( $product_id, $product_guid = '', $reason = 'variant-delta' ) {
		$product_id   = absint( $product_id );
		$product_guid = sanitize_text_field( (string) $product_guid );
		$reason       = sanitize_key( (string) $reason );

		if ( $product_id <= 0 ) {
			return array( 'success' => false, 'status' => 'invalid-product' );
		}

		$lock = self::acquire_lock();
		if ( false === $lock ) {
			return array( 'success' => false, 'status' => 'locked' );
		}

		try {
			$queue = self::read_queue();
			$key   = (string) $product_id;
			$now   = time();
			$old   = isset( $queue[ $key ] ) && is_array( $queue[ $key ] ) ? $queue[ $key ] : array();

			$queue[ $key ] = array(
				'productId'    => $product_id,
				'productGuid'  => '' !== $product_guid ? $product_guid : ( isset( $old['productGuid'] ) ? sanitize_text_field( (string) $old['productGuid'] ) : '' ),
				'reason'       => '' !== $reason ? $reason : 'variant-delta',
				'queuedAt'     => isset( $old['queuedAt'] ) ? absint( $old['queuedAt'] ) : $now,
				'updatedAt'    => $now,
				'attempts'     => 0,
				'nextAttemptAt'=> $now,
				'lastError'    => '',
			);

			if ( count( $queue ) > self::MAX_ITEMS ) {
				uasort(
					$queue,
					static function ( $a, $b ) {
						return absint( isset( $b['updatedAt'] ) ? $b['updatedAt'] : 0 ) <=> absint( isset( $a['updatedAt'] ) ? $a['updatedAt'] : 0 );
					}
				);
				$queue = array_slice( $queue, 0, self::MAX_ITEMS, true );
			}

			self::write_queue( $queue );
			update_post_meta( $product_id, '_mobo_parent_sync_pending', '1' );

			return array(
				'success'      => true,
				'status'       => 'queued',
				'pendingCount' => count( $queue ),
			);
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Finalize a bounded set of parent products.
	 *
	 * @param int $limit Limit.
	 * @param int $time_budget_seconds Cooperative time budget.
	 * @return array
	 */
	public static function process_batch( $limit = 10, $time_budget_seconds = 5 ) {
		$limit               = max( 1, min( 50, absint( $limit ) ) );
		$time_budget_seconds = max( 1, min( 20, absint( $time_budget_seconds ) ) );
		$deadline            = microtime( true ) + $time_budget_seconds;

		$result = array(
			'success'      => true,
			'status'       => 'empty',
			'processed'    => 0,
			'finalized'    => 0,
			'dropped'      => 0,
			'failed'       => 0,
			'deferred'     => 0,
			'remaining'    => false,
			'remainingDue' => false,
			'pendingCount' => 0,
		);

		$lock = self::acquire_lock();
		if ( false === $lock ) {
			$result['success'] = false;
			$result['status']  = 'locked';
			return $result;
		}

		try {
			while ( $result['processed'] < $limit && microtime( true ) < ( $deadline - 0.1 ) ) {
				$queue = self::read_queue();
				if ( empty( $queue ) ) {
					break;
				}

				/* Oldest due parent first; live claims are not due. */
				$due = array();
				$now = time();
				foreach ( $queue as $key => $item ) {
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
						return absint( isset( $a['queuedAt'] ) ? $a['queuedAt'] : 0 ) <=> absint( isset( $b['queuedAt'] ) ? $b['queuedAt'] : 0 );
					}
				);

				$key        = (string) array_key_first( $due );
				$item       = $due[ $key ];
				$product_id = absint( isset( $item['productId'] ) ? $item['productId'] : 0 );

				if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
					unset( $queue[ $key ] );
					self::write_queue( $queue );
					$result['processed']++;
					$result['dropped']++;
					continue;
				}

				$claim_token = wp_generate_uuid4();
				$queue[ $key ]['processingToken'] = $claim_token;
				$queue[ $key ]['processingUntil'] = time() + 120;
				$queue[ $key ]['updatedAt']       = time();
				self::write_queue( $queue );

				/*
				 * Do the potentially expensive WC variable sync with the queue mutex
				 * released. enqueue() can therefore record a newer product mutation.
				 */
				self::release_lock( $lock );
				$lock = false;
				$failure = null;

				try {
					$product = wc_get_product( $product_id );
					if ( ! $product instanceof WC_Product ) {
						$failure = new RuntimeException( 'WooCommerce product could not be loaded.' );
					} else {
						$has_children = method_exists( $product, 'get_children' ) && ! empty( $product->get_children() );
						$finalize_callback = static function () use ( $product_id, $product, $has_children ) {
							if ( is_callable( array( 'WC_Product_Variable', 'sync' ) ) && ( $product instanceof WC_Product_Variable || $has_children ) ) {
								WC_Product_Variable::sync( $product_id );
							}
							delete_post_meta( $product_id, '_mobo_parent_sync_pending' );
							wc_delete_product_transients( $product_id );
						};

						if ( class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) ) {
							Mobo_Core_Cache_Mutation_Guard::run( $finalize_callback, 'variant-parent-finalize' );
						} else {
							call_user_func( $finalize_callback );
						}

						if ( class_exists( 'Mobo_Core_Cache_Purger' ) ) {
							if ( method_exists( 'Mobo_Core_Cache_Purger', 'unsuppress_product_for_request' ) ) {
								Mobo_Core_Cache_Purger::unsuppress_product_for_request( $product_id );
							}
							Mobo_Core_Cache_Purger::queue_product( $product_id, 'variant-delta-converged' );
						}
					}
				} catch ( Throwable $e ) {
					$failure = $e;
				}
				$result['processed']++;

				$lock = self::acquire_lock();
				if ( false === $lock ) {
					/* The persisted claim expires and becomes retryable after a crash/lock miss. */
					update_post_meta( $product_id, '_mobo_parent_sync_pending', '1' );
					$result['success']   = false;
					$result['status']    = 'commit-lock-lost';
					$result['remaining'] = true;
					return $result;
				}

				$queue   = self::read_queue();
				$current = isset( $queue[ $key ] ) && is_array( $queue[ $key ] ) ? $queue[ $key ] : array();
				$current_token = isset( $current['processingToken'] ) ? (string) $current['processingToken'] : '';

				if ( '' === $current_token || ! hash_equals( $claim_token, $current_token ) ) {
					/* A newer enqueue superseded this finalization; preserve its pending marker. */
					update_post_meta( $product_id, '_mobo_parent_sync_pending', '1' );
					$result['deferred']++;
					continue;
				}

				if ( null === $failure ) {
					unset( $queue[ $key ] );
					self::write_queue( $queue );
					$result['finalized']++;
					continue;
				}

				$result['failed']++;
				$attempts = 1 + absint( isset( $current['attempts'] ) ? $current['attempts'] : 0 );
				$delay    = min( 600, 30 * (int) pow( 2, min( 4, $attempts - 1 ) ) );
				$queue[ $key ]['attempts']      = $attempts;
				$queue[ $key ]['nextAttemptAt'] = time() + $delay;
				$queue[ $key ]['updatedAt']     = time();
				$queue[ $key ]['lastError']     = substr( sanitize_text_field( $failure->getMessage() ), 0, 500 );
				unset( $queue[ $key ]['processingToken'], $queue[ $key ]['processingUntil'] );
				update_post_meta( $product_id, '_mobo_parent_sync_pending', '1' );
				self::write_queue( $queue );
			}

			$queue = self::read_queue();
			$result['pendingCount'] = count( $queue );
			$result['remaining']    = ! empty( $queue );
			$result['remainingDue'] = self::queue_has_due_items( $queue );
			$result['status']       = $result['failed'] > 0
				? ( $result['finalized'] > 0 ? 'partial' : 'failed' )
				: ( $result['finalized'] > 0 ? 'success' : ( $result['remaining'] ? 'waiting' : 'empty' ) );
			$result['success']      = 'failed' !== $result['status'];

			return $result;
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Whether parent finalization is waiting.
	 *
	 * @return bool
	 */
	public static function has_due_work() {
		return self::queue_has_due_items( self::read_queue() );
	}

	/**
	 * Compact status.
	 *
	 * @return array
	 */
	public static function get_status() {
		$queue     = self::read_queue();
		$due       = 0;
		$oldest_at = 0;
		$now       = time();
		foreach ( $queue as $item ) {
			if ( self::item_is_due( $item, $now ) ) {
				$due++;
			}
			$queued_at = is_array( $item ) ? absint( isset( $item['queuedAt'] ) ? $item['queuedAt'] : 0 ) : 0;
			if ( $queued_at > 0 && ( 0 === $oldest_at || $queued_at < $oldest_at ) ) {
				$oldest_at = $queued_at;
			}
		}
		return array(
			'pendingCount'    => count( $queue ),
			'dueCount'        => $due,
			'hasPending'      => ! empty( $queue ),
			'hasDue'          => $due > 0,
			'oldestPendingAt' => $oldest_at,
		);
	}

	/**
	 * Whether an in-memory queue contains work due now.
	 *
	 * @param array $queue Queue.
	 * @return bool
	 */
	private static function queue_has_due_items( $queue ) {
		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return false;
		}
		$now = time();
		foreach ( $queue as $item ) {
			if ( self::item_is_due( $item, $now ) ) {
				return true;
			}
		}
		return false;
	}


	/**
	 * Whether a parent queue item has a live processing claim.
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
	 * Whether a parent item can be claimed now.
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

	private static function read_queue() {
		$queue = get_option( self::OPTION_QUEUE, array() );
		return is_array( $queue ) ? $queue : array();
	}

	private static function write_queue( $queue ) {
		$queue = is_array( $queue ) ? $queue : array();
		if ( empty( $queue ) ) {
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
}
