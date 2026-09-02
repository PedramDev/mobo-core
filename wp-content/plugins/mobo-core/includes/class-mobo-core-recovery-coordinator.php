<?php
/**
 * Shared site mutation coordinator.
 *
 * The historical automatic Product Recovery workflow is retired. The existing
 * lock name is retained for upgrade compatibility and is now used only to keep
 * cache warmup and image-refresh automation from overlapping mutation-heavy work.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Recovery_Coordinator {
	const LOCK_NAME = 'recovery_pipeline'; // Persisted lock name retained for compatibility.

	public static function acquire( $ttl = 120 ) {
		if ( ! class_exists( 'Mobo_Core_Lock' ) ) {
			return '__mobo_no_lock__';
		}
		return Mobo_Core_Lock::acquire( self::LOCK_NAME, max( 30, min( 600, absint( $ttl ) ) ) );
	}

	public static function release( $token ) {
		if ( '__mobo_no_lock__' === $token || '__mobo_no_pipeline_lock__' === $token || ! class_exists( 'Mobo_Core_Lock' ) ) {
			return true;
		}
		return Mobo_Core_Lock::release( self::LOCK_NAME, $token );
	}

	/** Retired compatibility API: automatic Product Recovery is never pending. */
	public static function recovery_pending() {
		return false;
	}

	/** Retired compatibility API. */
	public static function mark_post_recovery_warmup_pending() {
		delete_option( 'mobo_core_post_recovery_warmup_pending' );
	}

	/** Retired compatibility API. */
	public static function post_recovery_warmup_pending() {
		return false;
	}

	/** Retired compatibility API. */
	public static function clear_post_recovery_warmup_pending() {
		delete_option( 'mobo_core_post_recovery_warmup_pending' );
	}
}
