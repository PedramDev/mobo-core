<?php
/**
 * Coordinates a safe in-place Mobo Core plugin upgrade.
 *
 * The upgrade barrier prevents new queue/sync workers from starting while an
 * upgrade is draining active work. Existing workers are not force-unlocked;
 * they stop at their next safe boundary and release their own leases.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Upgrade_Coordinator {

	const BARRIER_LOCK = 'plugin_upgrade_barrier';
	const STATE_OPTION = 'mobo_core_upgrade_barrier_state';
	const AUDIT_OPTION = 'mobo_core_last_upgrade_barrier_audit';
	const DEFAULT_TTL_SECONDS = 900;
	const DEFAULT_DRAIN_TIMEOUT_SECONDS = 120;
	const DEFAULT_RETRY_AFTER_SECONDS = 60;

	/**
	 * Locks required by the updater itself and therefore exempt from the barrier.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	public static function is_exempt_lock( $name ) {
		$name = sanitize_key( (string) $name );

		return in_array(
			$name,
			array(
				'remote_plugin_upgrade',
				self::BARRIER_LOCK,
			),
			true
		);
	}

	/**
	 * Whether acquisition of a runtime lock must be rejected during upgrade.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	public static function should_block_lock( $name ) {
		$name = sanitize_key( (string) $name );

		if ( '' === $name || self::is_exempt_lock( $name ) ) {
			return false;
		}

		return self::is_active();
	}

	/**
	 * Activate the global upgrade barrier.
	 *
	 * @param string $deployment_id Deployment identifier.
	 * @param int    $ttl_seconds Barrier lease TTL.
	 * @return string|false Barrier token or false.
	 */
	public static function activate( $deployment_id, $ttl_seconds = self::DEFAULT_TTL_SECONDS ) {
		$deployment_id = sanitize_text_field( (string) $deployment_id );
		$ttl_seconds    = max( 120, min( 1800, absint( $ttl_seconds ) ) );
		$token          = Mobo_Core_Lock::acquire( self::BARRIER_LOCK, $ttl_seconds );

		if ( false === $token ) {
			return false;
		}

		$now = time();
		self::write_state(
			array(
				'active'           => true,
				'status'           => 'draining',
				'deploymentId'     => $deployment_id,
				'activatedAt'      => $now,
				'lastRenewedAt'    => $now,
				'expiresAt'        => $now + $ttl_seconds,
				'drainStartedAt'   => $now,
				'drainCompletedAt' => 0,
				'blockingLocks'    => array(),
				'retryAfter'       => self::DEFAULT_RETRY_AFTER_SECONDS,
			)
		);

		/* A non-blocking self-runner request can own worker_dispatcher before its
		 * HTTP request actually reaches WordPress. That handoff is not live work.
		 * Cancel only the exact unchanged pre-claim lease; a concurrent claim/renew
		 * changes the lease snapshot and therefore cannot be deleted here. */
		if ( class_exists( 'Mobo_Core_Self_Runner' ) && method_exists( 'Mobo_Core_Self_Runner', 'cancel_pending_handoff_for_upgrade' ) ) {
			$handoff = Mobo_Core_Self_Runner::cancel_pending_handoff_for_upgrade( $now );
			$state = self::read_state();
			$state['dispatchHandoffDrain'] = is_array( $handoff ) ? $handoff : array();
			self::write_state( $state );
		}

		return $token;
	}

	/**
	 * Renew the global barrier while download/install work is still active.
	 *
	 * @param string $token Barrier token.
	 * @param int    $ttl_seconds Lease TTL.
	 * @return bool
	 */
	public static function renew( $token, $ttl_seconds = self::DEFAULT_TTL_SECONDS ) {
		$ttl_seconds = max( 120, min( 1800, absint( $ttl_seconds ) ) );
		$renewed     = Mobo_Core_Lock::renew( self::BARRIER_LOCK, $token, $ttl_seconds );

		if ( ! $renewed ) {
			return false;
		}

		$state                  = self::read_state();
		$state['active']        = true;
		$state['lastRenewedAt'] = time();
		$state['expiresAt']     = time() + $ttl_seconds;
		self::write_state( $state );

		return true;
	}

	/**
	 * Move the barrier lifecycle to a named stage while preserving ownership.
	 *
	 * @param string $token Barrier token.
	 * @param string $stage Lifecycle stage.
	 * @param array  $details Optional bounded diagnostics.
	 * @return bool
	 */
	public static function mark_stage( $token, $stage, $details = array() ) {
		$stage = sanitize_key( (string) $stage );
		if ( '' === $stage || ! self::renew( $token, self::DEFAULT_TTL_SECONDS ) ) {
			return false;
		}

		$state           = self::read_state();
		$state['active'] = true;
		$state['status'] = $stage;
		$now             = time();

		if ( 'drained' === $stage && empty( $state['drainCompletedAt'] ) ) {
			$state['drainCompletedAt'] = $now;
		} elseif ( 'backing-up' === $stage && empty( $state['backupStartedAt'] ) ) {
			$state['backupStartedAt'] = $now;
		} elseif ( 'installing' === $stage && empty( $state['installStartedAt'] ) ) {
			$state['installStartedAt'] = $now;
		} elseif ( 'verifying' === $stage && empty( $state['verifyStartedAt'] ) ) {
			$state['verifyStartedAt'] = $now;
		}

		if ( isset( $details['lastError'] ) ) {
			$state['lastError'] = sanitize_text_field( (string) $details['lastError'] );
		}
		self::write_state( $state );

		return true;
	}

	/**
	 * Release the barrier and allow queues to resume from their stored cursors.
	 *
	 * @param string $token Barrier token.
	 * @return bool
	 */
	public static function release( $token, $result = 'released', $details = array() ) {
		$state = self::read_state();
		$released = Mobo_Core_Lock::release( self::BARRIER_LOCK, $token );

		if ( $released ) {
			$released_at = time();
			$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_status( self::BARRIER_LOCK ) : array();
			$audit = array(
				'deploymentId'     => isset( $state['deploymentId'] ) ? sanitize_text_field( (string) $state['deploymentId'] ) : '',
				'result'           => sanitize_key( (string) $result ),
				'activatedAt'      => isset( $state['activatedAt'] ) ? absint( $state['activatedAt'] ) : 0,
				'drainStartedAt'   => isset( $state['drainStartedAt'] ) ? absint( $state['drainStartedAt'] ) : 0,
				'drainCompletedAt' => isset( $state['drainCompletedAt'] ) ? absint( $state['drainCompletedAt'] ) : 0,
				'backupStartedAt'  => isset( $state['backupStartedAt'] ) ? absint( $state['backupStartedAt'] ) : 0,
				'installStartedAt' => isset( $state['installStartedAt'] ) ? absint( $state['installStartedAt'] ) : 0,
				'verifyStartedAt'  => isset( $state['verifyStartedAt'] ) ? absint( $state['verifyStartedAt'] ) : 0,
				'releasedAt'       => $released_at,
				'lastStage'        => isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : '',
				'blockingLocks'    => isset( $state['blockingLocks'] ) && is_array( $state['blockingLocks'] ) ? $state['blockingLocks'] : array(),
				'lock'             => is_array( $lock ) ? $lock : array(),
				'lastError'        => isset( $details['lastError'] ) ? sanitize_text_field( (string) $details['lastError'] ) : '',
			);
			update_option( self::AUDIT_OPTION, $audit, false );
			delete_option( self::STATE_OPTION );
		}

		return $released;
	}

	/**
	 * Whether the barrier currently owns a live lease.
	 *
	 * @return bool
	 */
	public static function is_active() {
		$active = class_exists( 'Mobo_Core_Lock' ) && Mobo_Core_Lock::is_locked( self::BARRIER_LOCK );

		if ( ! $active ) {
			delete_option( self::STATE_OPTION );
		}

		return $active;
	}

	/**
	 * Wait until all non-upgrade runtime leases have drained.
	 *
	 * Stale leases are naturally removed by Mobo_Core_Lock::get_active_locks().
	 * Live leases are never force released.
	 *
	 * @param string $barrier_token Barrier token.
	 * @param int    $timeout_seconds Maximum wait.
	 * @param int    $ttl_seconds Barrier TTL.
	 * @return true|WP_Error
	 */
	public static function wait_for_quiescence( $barrier_token, $timeout_seconds = self::DEFAULT_DRAIN_TIMEOUT_SECONDS, $ttl_seconds = self::DEFAULT_TTL_SECONDS ) {
		$timeout_seconds = max( 10, min( 300, absint( $timeout_seconds ) ) );
		$deadline        = microtime( true ) + $timeout_seconds;
		$last_locks      = array();

		$state = self::read_state();
		$state['drainTimeoutSeconds'] = $timeout_seconds;
		self::write_state( $state );

		do {
			if ( ! self::renew( $barrier_token, $ttl_seconds ) ) {
				return new WP_Error(
					'mobo_core_upgrade_barrier_lost',
					'Upgrade barrier ownership was lost before the site became idle.',
					array(
						'status'     => 409,
						'retryAfter' => self::DEFAULT_RETRY_AFTER_SECONDS,
					)
				);
			}

			$last_locks = self::get_blocking_locks();
			$state      = self::read_state();
			$state['status']        = empty( $last_locks ) ? 'drained' : 'draining';
			$state['blockingLocks'] = $last_locks;
			if ( empty( $last_locks ) ) {
				$state['drainCompletedAt'] = time();
			}
			self::write_state( $state );

			if ( empty( $last_locks ) ) {
				return true;
			}

			usleep( 250000 );
		} while ( microtime( true ) < $deadline );

		return new WP_Error(
			'mobo_core_upgrade_site_busy',
			'Site workers did not reach an idle boundary before the upgrade drain timeout.',
			array(
				'status'        => 423,
				'retryAfter'    => self::DEFAULT_RETRY_AFTER_SECONDS,
				'activeLocks'   => $last_locks,
				'barrierStatus' => self::get_status(),
			)
		);
	}

	/**
	 * Return active non-upgrade lock names and bounded diagnostics.
	 *
	 * @return array
	 */
	public static function get_blocking_locks() {
		$active = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_active_locks() : array();
		$result = array();

		foreach ( $active as $name => $status ) {
			$name = sanitize_key( (string) $name );
			if ( '' === $name || self::is_exempt_lock( $name ) ) {
				continue;
			}

			$result[ $name ] = array(
				'acquiredAt'       => isset( $status['acquiredAt'] ) ? absint( $status['acquiredAt'] ) : 0,
				'lastHeartbeatAt'  => isset( $status['lastHeartbeatAt'] ) ? absint( $status['lastHeartbeatAt'] ) : 0,
				'expiresAt'        => isset( $status['expiresAt'] ) ? absint( $status['expiresAt'] ) : 0,
				'remainingSeconds' => isset( $status['remainingSeconds'] ) ? absint( $status['remainingSeconds'] ) : 0,
			);
		}

		ksort( $result );
		return $result;
	}

	/**
	 * Compact barrier status for REST/health responses.
	 *
	 * @return array
	 */
	public static function get_status() {
		$active = self::is_active();
		$state  = self::read_state();
		$lock   = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_status( self::BARRIER_LOCK ) : array();

		$audit = get_option( self::AUDIT_OPTION, array() );
		if ( ! is_array( $audit ) ) {
			$audit = array();
		}

		return array(
			'active'           => $active,
			'status'           => $active ? ( isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'active' ) : 'inactive',
			'deploymentId'     => isset( $state['deploymentId'] ) ? sanitize_text_field( (string) $state['deploymentId'] ) : '',
			'activatedAt'      => isset( $state['activatedAt'] ) ? absint( $state['activatedAt'] ) : 0,
			'lastRenewedAt'    => isset( $state['lastRenewedAt'] ) ? absint( $state['lastRenewedAt'] ) : 0,
			'drainStartedAt'   => isset( $state['drainStartedAt'] ) ? absint( $state['drainStartedAt'] ) : 0,
			'drainCompletedAt' => isset( $state['drainCompletedAt'] ) ? absint( $state['drainCompletedAt'] ) : 0,
			'backupStartedAt'  => isset( $state['backupStartedAt'] ) ? absint( $state['backupStartedAt'] ) : 0,
			'installStartedAt' => isset( $state['installStartedAt'] ) ? absint( $state['installStartedAt'] ) : 0,
			'verifyStartedAt'  => isset( $state['verifyStartedAt'] ) ? absint( $state['verifyStartedAt'] ) : 0,
			'retryAfter'       => isset( $state['retryAfter'] ) ? absint( $state['retryAfter'] ) : self::DEFAULT_RETRY_AFTER_SECONDS,
			'drainTimeoutSeconds' => isset( $state['drainTimeoutSeconds'] ) ? absint( $state['drainTimeoutSeconds'] ) : 0,
			'dispatchHandoffDrain' => isset( $state['dispatchHandoffDrain'] ) && is_array( $state['dispatchHandoffDrain'] ) ? $state['dispatchHandoffDrain'] : array(),
			'blockingLocks'    => $active ? self::get_blocking_locks() : array(),
			'lock'             => is_array( $lock ) ? $lock : array(),
			'lastAudit'        => $audit,
		);
	}

	/**
	 * Standard response for work deferred by the upgrade barrier.
	 *
	 * @param string $context Worker context.
	 * @return array
	 */
	public static function paused_result( $context = 'worker' ) {
		return array(
			'success'           => true,
			'status'            => 'paused-for-upgrade',
			'context'           => sanitize_key( (string) $context ),
			'message'           => 'Work is temporarily paused while the plugin upgrade reaches a safe filesystem boundary.',
			'retryAfter'        => self::DEFAULT_RETRY_AFTER_SECONDS,
			'needsContinuation' => false,
			'upgradeBarrier'    => self::get_status(),
		);
	}

	/**
	 * @return array
	 */
	private static function read_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param array $state State.
	 * @return void
	 */
	private static function write_state( $state ) {
		update_option( self::STATE_OPTION, is_array( $state ) ? $state : array(), false );
	}
}
