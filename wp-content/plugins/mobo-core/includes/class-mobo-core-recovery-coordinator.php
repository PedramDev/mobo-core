<?php
/**
 * Site-wide recovery/warmup coordinator.
 *
 * Product recovery is a durable multi-request workflow. The state option marks the
 * whole recovery generation as pending, while this coordinator provides the atomic
 * execution lease used by each short batch. Cache warmup uses the same lease only
 * after recovery has fully converged, so the two mutation-heavy operations can
 * never overlap on the same WordPress site.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Recovery_Coordinator {
	const LOCK_NAME                  = 'recovery_pipeline';
	const OPTION_POST_WARMUP_PENDING = 'mobo_core_post_recovery_warmup_pending';

	/**
	 * Acquire the atomic site-wide execution lease.
	 *
	 * @param int $ttl Lease TTL in seconds.
	 * @return string|false
	 */
	public static function acquire( $ttl = 120 ) {
		if ( ! class_exists( 'Mobo_Core_Lock' ) ) {
			return '__mobo_no_lock__';
		}

		return Mobo_Core_Lock::acquire( self::LOCK_NAME, max( 30, min( 600, absint( $ttl ) ) ) );
	}

	/**
	 * Release the site-wide execution lease.
	 *
	 * @param string $token Lease token.
	 * @return bool
	 */
	public static function release( $token ) {
		if ( '__mobo_no_lock__' === $token || ! class_exists( 'Mobo_Core_Lock' ) ) {
			return true;
		}

		return Mobo_Core_Lock::release( self::LOCK_NAME, $token );
	}

	/**
	 * Whether the durable product-recovery generation is still active.
	 *
	 * @return bool
	 */
	public static function recovery_pending() {
		return class_exists( 'Mobo_Core_Product_Recovery' ) && Mobo_Core_Product_Recovery::is_pending();
	}

	/**
	 * Mark that cache warmup accumulated during recovery must be drained once,
	 * after the recovery generation has completed.
	 *
	 * @return void
	 */
	public static function mark_post_recovery_warmup_pending() {
		if ( '1' === (string) get_option( self::OPTION_POST_WARMUP_PENDING, '0' ) ) {
			return;
		}

		update_option( self::OPTION_POST_WARMUP_PENDING, '1', false );
	}

	/**
	 * Whether a post-recovery serial warmup drain is pending.
	 *
	 * @return bool
	 */
	public static function post_recovery_warmup_pending() {
		return '1' === (string) get_option( self::OPTION_POST_WARMUP_PENDING, '0' );
	}

	/**
	 * Clear the one-shot post-recovery warmup marker after the queue converges.
	 *
	 * @return void
	 */
	public static function clear_post_recovery_warmup_pending() {
		delete_option( self::OPTION_POST_WARMUP_PENDING );
	}
}
