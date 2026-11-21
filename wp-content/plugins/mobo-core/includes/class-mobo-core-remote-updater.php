<?php
/**
 * Secure Portal-driven self updater for Mobo Core.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Remote_Updater {

	const STATUS_OPTION = 'mobo_core_remote_upgrade_status';
	const HISTORY_OPTION = 'mobo_core_remote_upgrade_history';
	const MAX_PACKAGE_BYTES = 52428800; // 50 MiB.
	const MAX_TREE_FILES    = 5000;

	/** @var array|null Request-local verified installed-build state. */
	private static $build_integrity_cache = null;

	/** @var array|null Request-local verified rollback snapshot state. */
	private static $rollback_integrity_cache = null;

	/**
	 * Return compact deployment state.
	 *
	 * @return array
	 */
	public static function get_status() {
		$status = get_option( self::STATUS_OPTION, array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}
		$build_integrity = self::get_build_integrity_status();
		$rollback_integrity = self::get_rollback_integrity_status();
		$barrier_status = class_exists( 'Mobo_Core_Upgrade_Coordinator' ) ? Mobo_Core_Upgrade_Coordinator::get_status() : array( 'active' => false, 'status' => 'unavailable' );
		$upgrade_lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_status( 'remote_plugin_upgrade' ) : array( 'active' => false );
		$stored_status = isset( $status['status'] ) ? sanitize_key( (string) $status['status'] ) : 'idle';
		$current_version = defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '';
		$effective_status = $stored_status;
		$interrupted = false;
		$recovery_mode = '';
		$recovery_hold = class_exists( 'Mobo_Core_Upgrade_Coordinator' )
			&& false !== get_option( Mobo_Core_Upgrade_Coordinator::RECOVERY_HOLD_OPTION, false );
		$migration_gate = self::get_migration_gate_status();

		$transient_stages = array( 'downloading', 'validating', 'draining', 'backing-up', 'installing', 'verifying' );
		if ( in_array( $stored_status, $transient_stages, true ) && empty( $upgrade_lock['active'] ) && empty( $barrier_status['active'] ) ) {
			$interrupted = true;
			$target_version = isset( $status['targetVersion'] ) ? self::clean_version( $status['targetVersion'] ) : '';
			$target_fingerprint = isset( $status['targetBuildFingerprint'] ) ? strtolower( trim( (string) $status['targetBuildFingerprint'] ) ) : '';
			$installed_target = '' !== $target_version && hash_equals( $target_version, $current_version ) && ! empty( $build_integrity['ready'] );
			if ( ! $recovery_hold && $installed_target && ( '' === $target_fingerprint || ( ! empty( $build_integrity['fingerprint'] ) && hash_equals( $target_fingerprint, (string) $build_integrity['fingerprint'] ) ) ) ) {
				$effective_status = 'completed-recovered';
				$recovery_mode = 'installed-build-verified';
			} else {
				$effective_status = 'interrupted';
				$recovery_mode = $recovery_hold ? 'manual-recovery-required' : ( ! empty( $rollback_integrity['ready'] ) ? 'signed-retry-with-verified-backup' : 'manual-recovery-required' );
			}
		}

		return array(
			'success'        => true,
			'manualRecoveryHold' => $recovery_hold,
			'status'         => $effective_status,
			'storedStatus'   => $stored_status,
			'interrupted'    => $interrupted,
			'recoveryMode'   => $recovery_mode,
			'currentVersion' => $current_version,
			/* Backend push gate: upgrade only when migration.upgradeAllowed. */
			'dbVersion'      => $migration_gate['dbVersion'],
			'migrationInProgress' => ! empty( $migration_gate['inProgress'] ),
			'migration'      => $migration_gate,
			'buildFingerprint' => ! empty( $build_integrity['ready'] ) ? (string) $build_integrity['fingerprint'] : '',
			'buildIntegrity' => $build_integrity,
			'deploymentId'   => isset( $status['deploymentId'] ) ? sanitize_text_field( (string) $status['deploymentId'] ) : '',
			'fromVersion'    => isset( $status['fromVersion'] ) ? sanitize_text_field( (string) $status['fromVersion'] ) : '',
			'targetVersion'  => isset( $status['targetVersion'] ) ? sanitize_text_field( (string) $status['targetVersion'] ) : '',
			'targetBuildFingerprint' => isset( $status['targetBuildFingerprint'] ) ? sanitize_text_field( (string) $status['targetBuildFingerprint'] ) : '',
			'startedAt'      => isset( $status['startedAt'] ) ? sanitize_text_field( (string) $status['startedAt'] ) : '',
			'completedAt'    => isset( $status['completedAt'] ) ? sanitize_text_field( (string) $status['completedAt'] ) : '',
			/* Older installations can retain an unredacted exception in status.
			 * Never reflect legacy contents into REST/Health after upgrading. */
			'lastError'      => self::safe_status_error_for_output( $status['lastError'] ?? '' ),
			'rollbackReady'  => ! empty( $rollback_integrity['ready'] ),
			'rollbackSnapshot' => $rollback_integrity,
			'upgradeBarrier' => $barrier_status,
			'upgradeLock'    => array(
				'active' => ! empty( $upgrade_lock['active'] ),
				'acquiredAt' => absint( $upgrade_lock['acquiredAt'] ?? 0 ),
				'expiresAt' => absint( $upgrade_lock['expiresAt'] ?? 0 ),
			),
		);
	}

	/**
	 * Apply one verified plugin package supplied by Portal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function apply( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'mobo_core_upgrade_invalid_payload', 'Invalid JSON payload.', array( 'status' => 400 ) );
		}

		$deployment_id = self::clean_identifier( isset( $params['deploymentId'] ) ? $params['deploymentId'] : '' );
		$target_version = self::clean_version( isset( $params['targetVersion'] ) ? $params['targetVersion'] : '' );
		$expected_current = self::clean_version( isset( $params['expectedCurrentVersion'] ) ? $params['expectedCurrentVersion'] : '' );
		$package_url = isset( $params['packageUrl'] ) ? esc_url_raw( (string) $params['packageUrl'] ) : '';
		$package_sha256 = isset( $params['packageSha256'] ) ? strtolower( trim( (string) $params['packageSha256'] ) ) : '';
		$target_build_fingerprint = isset( $params['targetBuildFingerprint'] ) ? strtolower( trim( (string) $params['targetBuildFingerprint'] ) ) : '';
		$signature_version = isset( $params['signatureVersion'] ) ? absint( $params['signatureVersion'] ) : 1;
		$download_token = isset( $params['downloadToken'] ) ? trim( (string) $params['downloadToken'] ) : '';
		$issued_at = isset( $params['issuedAt'] ) ? absint( $params['issuedAt'] ) : 0;
		$request_signature = isset( $params['requestSignature'] ) ? strtolower( trim( (string) $params['requestSignature'] ) ) : '';

		if ( '' === $deployment_id || '' === $target_version || '' === $package_url || ! preg_match( '/^[a-f0-9]{64}$/', $package_sha256 ) ) {
			return new WP_Error( 'mobo_core_upgrade_missing_fields', 'deploymentId, targetVersion, packageUrl and packageSha256 are required.', array( 'status' => 400 ) );
		}

		if ( strlen( $download_token ) < 32 || strlen( $download_token ) > 512 ) {
			return new WP_Error( 'mobo_core_upgrade_invalid_token', 'Package download token is invalid.', array( 'status' => 400 ) );
		}

		if ( ! self::verify_portal_signature( $deployment_id, $expected_current, $target_version, $target_build_fingerprint, $package_url, $package_sha256, $download_token, $issued_at, $request_signature, $signature_version ) ) {
			return new WP_Error( 'mobo_core_upgrade_signature_invalid', 'Portal deployment signature is invalid or expired.', array( 'status' => 401 ) );
		}

		if ( ! self::is_trusted_package_url( $package_url ) ) {
			return new WP_Error( 'mobo_core_upgrade_untrusted_url', 'Package URL does not match the secure Portal package endpoint policy.', array( 'status' => 400 ) );
		}

		$current_version = defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '';
		$recovery_hold = class_exists( 'Mobo_Core_Upgrade_Coordinator' )
			&& false !== get_option( Mobo_Core_Upgrade_Coordinator::RECOVERY_HOLD_OPTION, false );
		if ( '' !== $expected_current && ! hash_equals( $expected_current, $current_version ) ) {
			return new WP_Error(
				'mobo_core_upgrade_version_mismatch',
				'Installed version does not match the requested source version.',
				array(
					'status' => 409,
					'currentVersion' => $current_version,
					'expectedCurrentVersion' => $expected_current,
				)
			);
		}

		$version_comparison = version_compare( $target_version, $current_version );
		$current_build_fingerprint = self::get_build_fingerprint();
		if ( $version_comparison < 0 ) {
			return new WP_Error(
				'mobo_core_upgrade_downgrade_rejected',
				'Target version is older than the installed version.',
				array( 'status' => 409, 'currentVersion' => $current_version, 'buildFingerprint' => $current_build_fingerprint )
			);
		}
		if ( 0 === $version_comparison ) {
			if ( 2 !== $signature_version || ! preg_match( '/^[a-f0-9]{64}$/', $target_build_fingerprint ) ) {
				return new WP_Error(
					'mobo_core_upgrade_same_version_requires_build_identity',
					'Same-version deployment requires signed build identity protocol v2.',
					array( 'status' => 409, 'currentVersion' => $current_version, 'buildFingerprint' => $current_build_fingerprint )
				);
			}
			/* A manifest match cached in this request cannot waive a persisted
			 * unverified-rollback hold. Require the full signed replacement path and
			 * final forced disk verification before declaring queues resumable. */
			if ( ! $recovery_hold && preg_match( '/^[a-f0-9]{64}$/', $current_build_fingerprint ) && hash_equals( $target_build_fingerprint, $current_build_fingerprint ) ) {
				return array(
					'success'          => true,
					'status'           => 'already-current-build',
					'deploymentId'     => $deployment_id,
					'previousVersion'  => $current_version,
					'installedVersion' => $current_version,
					'buildFingerprint' => $current_build_fingerprint,
					'restartRequired'  => false,
					'queuesResumable'  => true,
				);
			}
		}

		/* Never replace plugin files while the database migration for the
		 * installed build is unfinished or its mutex is held. 423 + retryAfter is
		 * what the Backend classifies as busy/retryable (409 is treated as a
		 * permanent block by the current Portal worker). allowPendingMigration
		 * lets the Backend push a fix-forward build for a stalled (not running)
		 * migration; it never bypasses a held migration lock. */
		$migration_gate = self::get_migration_gate_status();
		$allow_pending  = ! empty( $params['allowPendingMigration'] ) && true === filter_var( $params['allowPendingMigration'], FILTER_VALIDATE_BOOLEAN );
		if ( ! empty( $migration_gate['lockHeld'] ) || ( empty( $migration_gate['dbVersionMatches'] ) && ! $allow_pending ) ) {
			return new WP_Error(
				'mobo_core_upgrade_migration_in_progress',
				'Database migration for the installed version is not finished; retry the upgrade later.',
				array(
					'status'         => 423,
					'retryAfter'     => absint( $migration_gate['retryAfter'] ) > 0 ? absint( $migration_gate['retryAfter'] ) : 120,
					'reason'         => 'migration_in_progress',
					'currentVersion' => $current_version,
					'migration'      => $migration_gate,
				)
			);
		}

		$lock = Mobo_Core_Lock::acquire( 'remote_plugin_upgrade', 900 );
		if ( false === $lock ) {
			return new WP_Error( 'mobo_core_upgrade_locked', 'Another plugin upgrade is already running.', array( 'status' => 423, 'retryAfter' => 60 ) );
		}

		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- remote deployment must survive hosts with a short default execution limit.
			@set_time_limit( 360 );
		}

		$tmp_file = '';
		$staging_dir = '';
		$backup_created = false;
		$backup_meta = array();
		$destination_touched = false;
		$barrier_token = false;
		$barrier_activated = false;
		$barrier_result = 'not-started';
		$barrier_error = '';

		self::write_status(
			array(
				'status'        => 'downloading',
				'deploymentId'  => $deployment_id,
				'fromVersion'   => $current_version,
				'targetVersion' => $target_version,
				'targetBuildFingerprint' => $target_build_fingerprint,
				'startedAt'     => gmdate( 'c' ),
				'completedAt'   => '',
				'lastError'     => '',
			)
		);

		try {
			/* Download and fully validate before pausing site workers. */
			$tmp_file = self::download_package( $package_url, $download_token );
			if ( is_wp_error( $tmp_file ) ) {
				throw new RuntimeException( $tmp_file->get_error_message() );
			}

			$actual_sha256 = hash_file( 'sha256', $tmp_file );
			if ( ! is_string( $actual_sha256 ) || ! hash_equals( $package_sha256, strtolower( $actual_sha256 ) ) ) {
				throw new RuntimeException( 'Downloaded package SHA-256 does not match the Portal release.' );
			}

			self::update_status_stage( 'validating' );
			$validation = self::validate_package( $tmp_file, $target_version, $deployment_id, $target_build_fingerprint );
			if ( is_wp_error( $validation ) ) {
				throw new RuntimeException( $validation->get_error_message() );
			}
			$staging_dir = isset( $validation['stagingDir'] ) ? (string) $validation['stagingDir'] : '';

			self::update_status_stage( 'draining' );
			$barrier_token = class_exists( 'Mobo_Core_Upgrade_Coordinator' )
				? Mobo_Core_Upgrade_Coordinator::activate( $deployment_id, 900 )
				: false;
			if ( false === $barrier_token ) {
				$busy = array(
					'status'        => 'blocked-site-busy',
					'deploymentId'  => $deployment_id,
					'fromVersion'   => $current_version,
					'targetVersion' => $target_version,
					'startedAt'     => self::current_status_value( 'startedAt' ),
					'completedAt'   => gmdate( 'c' ),
					'lastError'     => 'Another upgrade barrier is already active.',
				);
				self::write_status( $busy );
				return new WP_Error( 'mobo_core_upgrade_barrier_locked', $busy['lastError'], array( 'status' => 423, 'retryAfter' => 60 ) );
			}
			$barrier_activated = true;
			$barrier_result = 'draining';

			$drain_timeout = self::resolve_effective_drain_timeout();
			$drained = Mobo_Core_Upgrade_Coordinator::wait_for_quiescence( $barrier_token, $drain_timeout, 900 );
			if ( is_wp_error( $drained ) ) {
				$data = $drained->get_error_data();
				$data = is_array( $data ) ? $data : array();
				$drain_message = self::with_blocking_lock_summary( $drained->get_error_message(), isset( $data['activeLocks'] ) ? $data['activeLocks'] : array() );
				$blocked = array(
					'status'        => 'blocked-site-busy',
					'deploymentId'  => $deployment_id,
					'fromVersion'   => $current_version,
					'targetVersion' => $target_version,
					'startedAt'     => self::current_status_value( 'startedAt' ),
					'completedAt'   => gmdate( 'c' ),
					'lastError'     => sanitize_text_field( $drain_message ),
				);
				self::write_status( $blocked );
				$barrier_result = 'blocked-site-busy';
				$barrier_error  = $blocked['lastError'];
				return new WP_Error(
					$drained->get_error_code(),
					$drain_message,
					array_merge(
						$data,
						array(
							'status'        => isset( $data['status'] ) ? absint( $data['status'] ) : 423,
							'retryAfter'    => isset( $data['retryAfter'] ) ? absint( $data['retryAfter'] ) : 60,
							'currentVersion'=> $current_version,
						)
					)
				);
			}

			$barrier_result = 'drained';
			Mobo_Core_Upgrade_Coordinator::mark_stage( $barrier_token, 'drained' );

			if ( ! Mobo_Core_Lock::renew( 'remote_plugin_upgrade', $lock, 900 ) || ! Mobo_Core_Upgrade_Coordinator::renew( $barrier_token, 900 ) ) {
				$lease_message = 'Upgrade lease was lost before filesystem replacement.';
				$barrier_result = 'failed';
				$barrier_error  = $lease_message;
				Mobo_Core_Upgrade_Coordinator::mark_stage( $barrier_token, 'failed', array( 'lastError' => $lease_message ) );
				$failed = array(
					'status'        => 'failed',
					'deploymentId'  => $deployment_id,
					'fromVersion'   => $current_version,
					'targetVersion' => $target_version,
					'startedAt'     => self::current_status_value( 'startedAt' ),
					'completedAt'   => gmdate( 'c' ),
					'lastError'     => $lease_message,
				);
				self::write_status( $failed );
				self::append_history( $failed );
				return new WP_Error( 'mobo_core_upgrade_lease_lost', $lease_message, array( 'status' => 409, 'retryAfter' => 60 ) );
			}

			self::update_status_stage( 'backing-up' );
			$barrier_result = 'backing-up';
			Mobo_Core_Upgrade_Coordinator::mark_stage( $barrier_token, 'backing-up' );
			$backup = self::create_backup( $deployment_id, $current_version );
			if ( is_wp_error( $backup ) ) {
				throw new RuntimeException( $backup->get_error_message() );
			}
			$backup_meta = is_array( $backup ) ? $backup : array();
			$backup_created = true;

			if ( ! Mobo_Core_Lock::renew( 'remote_plugin_upgrade', $lock, 900 ) || ! Mobo_Core_Upgrade_Coordinator::renew( $barrier_token, 900 ) ) {
				throw new RuntimeException( 'Upgrade lease was lost after backup and before plugin installation.' );
			}

			self::update_status_stage( 'installing' );
			$barrier_result = 'installing';
			Mobo_Core_Upgrade_Coordinator::mark_stage( $barrier_token, 'installing' );
			$destination_touched = true;
			$installed = self::install_package( $tmp_file );
			if ( is_wp_error( $installed ) ) {
				throw new RuntimeException( $installed->get_error_message() );
			}

			self::update_status_stage( 'verifying' );
			$barrier_result = 'verifying';
			Mobo_Core_Upgrade_Coordinator::mark_stage( $barrier_token, 'verifying' );

			$disk_version = self::read_disk_plugin_version();
			if ( ! hash_equals( $target_version, $disk_version ) ) {
				throw new RuntimeException( 'Installed files do not report the requested target version.' );
			}
			$disk_integrity = self::get_build_integrity_status( true, $target_version );
			if ( empty( $disk_integrity['ready'] ) ) {
				$integrity_message = isset( $disk_integrity['message'] ) ? (string) $disk_integrity['message'] : 'Installed plugin file integrity verification failed.';
				throw new RuntimeException( $integrity_message );
			}
			$disk_build_fingerprint = isset( $disk_integrity['fingerprint'] ) ? (string) $disk_integrity['fingerprint'] : '';
			if ( '' !== $target_build_fingerprint && ! hash_equals( $target_build_fingerprint, $disk_build_fingerprint ) ) {
				throw new RuntimeException( 'Installed files do not match the requested target build fingerprint.' );
			}

			$completed = array(
				'status'        => 'completed',
				'deploymentId'  => $deployment_id,
				'fromVersion'   => $current_version,
				'targetVersion' => $target_version,
				'targetBuildFingerprint' => $disk_build_fingerprint,
				'startedAt'     => self::current_status_value( 'startedAt' ),
				'completedAt'   => gmdate( 'c' ),
				'lastError'     => '',
			);
			/* A previous failed rollback may have left a durable recovery hold. It
			 * can be retired only AFTER this replacement passed the on-disk manifest
			 * and fingerprint readback above. A failed option delete is not success. */
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' )
				&& false !== get_option( Mobo_Core_Upgrade_Coordinator::RECOVERY_HOLD_OPTION, false ) ) {
				delete_option( Mobo_Core_Upgrade_Coordinator::RECOVERY_HOLD_OPTION );
				if ( false !== get_option( Mobo_Core_Upgrade_Coordinator::RECOVERY_HOLD_OPTION, false ) ) {
					throw new RuntimeException( 'The recovery hold could not be cleared after verified deployment.' );
				}
			}
			self::write_status( $completed );
			self::append_history( $completed );
			$barrier_result = 'completed';
			Mobo_Core_Upgrade_Coordinator::mark_stage( $barrier_token, 'completed' );

			return array(
				'success'          => true,
				'status'           => 'completed',
				'deploymentId'     => $deployment_id,
				'previousVersion'  => $current_version,
				'installedVersion' => $disk_version,
				'buildFingerprint' => $disk_build_fingerprint,
				'restartRequired'  => false,
				'queuesResumable'  => true,
			);
		} catch ( Throwable $e ) {
			$rollback_failed = false;
			if ( $backup_created && $destination_touched ) {
				try {
					$rollback = self::restore_backup( $current_version, $backup_meta );
				} catch ( Throwable $rollback_exception ) {
					/* An exception during restore is just as unverified as WP_Error.
					 * Never emit its raw message or skip the recovery hold. */
					$rollback = false;
				}
				if ( true !== $rollback ) {
					$rollback_failed = true;
				}
			}

			/* Third-party WP_Error/Exception messages can include signed URLs, paths,
			 * SQL, tokens or request headers. Neither REST nor durable status/history
			 * should copy them. Stage and rollback verification are stable evidence. */
			$failed_stage = sanitize_key( (string) self::current_status_value( 'status' ) );
			if ( ! in_array( $failed_stage, array( 'downloading', 'validating', 'draining', 'backing-up', 'installing', 'verifying' ), true ) ) {
				$failed_stage = 'unknown';
			}
			$message = 'Plugin upgrade failed during ' . $failed_stage . '.';
			if ( $rollback_failed ) {
				$message .= ' Verified rollback was not completed; manual recovery is required.';
				if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) ) {
					update_option(
						Mobo_Core_Upgrade_Coordinator::RECOVERY_HOLD_OPTION,
						array( 'status' => 'rollback-unverified', 'deploymentId' => $deployment_id, 'failedAt' => time() ),
						false
					);
				}
			}
			$barrier_result = 'failed';
			$barrier_error  = $message;
			if ( $barrier_activated && false !== $barrier_token && class_exists( 'Mobo_Core_Upgrade_Coordinator' ) ) {
				Mobo_Core_Upgrade_Coordinator::mark_stage( $barrier_token, 'failed', array( 'lastError' => $message ) );
			}
			$failed = array(
				'status'        => 'failed',
				'deploymentId'  => $deployment_id,
				'fromVersion'   => $current_version,
				'targetVersion' => $target_version,
				'startedAt'     => self::current_status_value( 'startedAt' ),
				'completedAt'   => gmdate( 'c' ),
				'lastError'     => $message,
			);
			self::write_status( $failed );
			self::append_history( $failed );

			return new WP_Error( 'mobo_core_upgrade_failed', $message, array( 'status' => 500 ) );
		} finally {
			if ( is_string( $tmp_file ) && '' !== $tmp_file && is_file( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			if ( '' !== $staging_dir && is_dir( $staging_dir ) ) {
				self::delete_tree( $staging_dir );
			}
			if ( $barrier_activated && false !== $barrier_token && class_exists( 'Mobo_Core_Upgrade_Coordinator' ) ) {
				Mobo_Core_Upgrade_Coordinator::release(
					$barrier_token,
					$barrier_result,
					array( 'lastError' => $barrier_error )
				);
			}
			Mobo_Core_Lock::release( 'remote_plugin_upgrade', $lock );

			/* Resume preserved queue/sync cursors after the barrier is gone. */
			if ( $barrier_activated && class_exists( 'Mobo_Core_Self_Runner' )
				&& ( ! class_exists( 'Mobo_Core_Upgrade_Coordinator' )
					|| false === get_option( Mobo_Core_Upgrade_Coordinator::RECOVERY_HOLD_OPTION, false ) ) ) {
				try {
					Mobo_Core_Self_Runner::kick( 'plugin-upgrade-release', true );
				} catch ( Throwable $ignored ) {
					// The next real cron/heartbeat will resume work.
				}
			}
		}
	}


	/**
	 * Resolve the drain timeout with enough headroom for configured blocking Mobo
	 * HTTP calls. This does not wait for lease TTLs; live workers still need to
	 * release at a safe boundary and the existing hard cap remains 300 seconds.
	 *
	 * @return int
	 */
	private static function resolve_effective_drain_timeout() {
		$configured = class_exists( 'Mobo_Core_Settings' )
			? Mobo_Core_Settings::get_int( 'mobo_core_upgrade_drain_timeout_seconds', 120, 15, 300 )
			: 120;

		if ( ! class_exists( 'Mobo_Core_Settings' ) ) {
			return max( 15, min( 300, absint( $configured ) ) );
		}

		$api_timeout     = Mobo_Core_Settings::get_int( 'mobo_core_api_request_timeout_seconds', 60, 5, 180 );
		$payload_timeout = Mobo_Core_Settings::get_int( 'mobo_core_payload_pull_timeout_seconds', 60, 5, 180 );

		/* Plugin upgrade drain is installation-global and starts before any one
		 * Connection can be authoritative for all in-flight work. Checkout timeout is
		 * Source-owned, so a selected/default Connection must not size the global
		 * barrier. Budget its documented upper bound to cover every Connection. */
		$checkout_timeout = 20;
		$blocking_timeout = max( 15, $api_timeout, $payload_timeout, $checkout_timeout );
		$minimum_safe     = min( 300, $blocking_timeout + 30 );

		return min( 300, max( absint( $configured ), $minimum_safe ) );
	}

	/**
	 * Add bounded lock diagnostics to a retryable busy-upgrade error.
	 *
	 * @param string $message Base message.
	 * @param array  $locks Blocking lock map.
	 * @return string
	 */
	private static function with_blocking_lock_summary( $message, $locks ) {
		$message = sanitize_text_field( (string) $message );
		if ( ! is_array( $locks ) || empty( $locks ) ) {
			return $message;
		}

		$parts = array();
		foreach ( array_slice( $locks, 0, 5, true ) as $name => $status ) {
			$name = sanitize_key( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$remaining = is_array( $status ) && isset( $status['remainingSeconds'] ) ? absint( $status['remainingSeconds'] ) : 0;
			$parts[] = $remaining > 0 ? $name . ' (' . $remaining . 's)' : $name;
		}

		if ( empty( $parts ) ) {
			return $message;
		}

		return $message . ' Blocking locks: ' . implode( ', ', $parts ) . '.';
	}

	private static function download_package( $url, $token ) {
		$license_token = class_exists( 'Mobo_Core_Connection_Repository' )
			? trim( (string) Mobo_Core_Connection_Repository::get_runtime_value( 'license_token', get_option( 'mobo_core_token', '' ) ) )
			: trim( (string) get_option( 'mobo_core_token', '' ) );
		if ( '' === $license_token ) {
			return new WP_Error( 'mobo_core_upgrade_missing_license_token', 'کد لایسنس برای دانلود بسته Deploy ثبت نشده است.' );
		}
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $license_token ) ) {
			return new WP_Error( 'mobo_core_upgrade_invalid_license_token', 'ساختار کد لایسنس برای دانلود بسته Deploy معتبر نیست.' );
		}
		if ( ! self::is_trusted_package_url( $url ) ) {
			return new WP_Error( 'mobo_core_upgrade_untrusted_redirect', 'Package URL is outside the trusted package policy.' );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = wp_tempnam( 'mobo-core-update.zip' );
		if ( ! $tmp ) {
			return new WP_Error( 'mobo_core_upgrade_temp_failed', 'Could not create a temporary package file.' );
		}

		$initial_url = esc_url_raw( (string) $url );
		$current_url = $initial_url;
		$response    = null;
		for ( $redirects = 0; $redirects <= 3; $redirects++ ) {
			@file_put_contents( $tmp, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temp stream target must be truncated between manually validated redirects.
			$headers = array(
				'Accept'                => 'application/zip, application/octet-stream',
				'Token'                 => $license_token,
				'X-Mobo-Package-Token'  => $token,
				'Cache-Control'          => 'no-store',
				'X-Mobo-Plugin-Version'  => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
			);
			$headers = Mobo_Core_Portal_Request_Signer::sign_headers( 'GET', $current_url, '', $headers );
			if ( is_wp_error( $headers ) ) {
				wp_delete_file( $tmp );
				return $headers;
			}

			$response = wp_remote_get(
				$current_url,
				array(
					'timeout'             => 90,
					'redirection'         => 0,
					'sslverify'           => true,
					'stream'              => true,
					'filename'            => $tmp,
					'limit_response_size' => self::MAX_PACKAGE_BYTES,
					'headers'             => $headers,
				)
			);

			if ( is_wp_error( $response ) ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'mobo_core_upgrade_package_transport_failed', 'Portal package transport failed.', array( 'transportCode' => sanitize_key( (string) $response->get_error_code() ) ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				break;
			}
			if ( $redirects >= 3 ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'mobo_core_upgrade_too_many_redirects', 'Portal package endpoint exceeded the redirect limit.' );
			}
			$next = self::resolve_package_redirect_url( $current_url, wp_remote_retrieve_header( $response, 'location' ) );
			if ( is_wp_error( $next ) || ! self::is_trusted_package_url( $next ) || ! self::same_url_origin( $initial_url, $next ) ) {
				wp_delete_file( $tmp );
				return is_wp_error( $next ) ? $next : new WP_Error( 'mobo_core_upgrade_untrusted_redirect', 'Package redirect left the trusted package origin.' );
			}
			$current_url = $next;
		}

		$code = is_array( $response ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'mobo_core_upgrade_download_http', 'Portal package endpoint returned HTTP ' . $code . '.' );
		}
		$size = is_file( $tmp ) ? filesize( $tmp ) : 0;
		if ( ! $size || $size > self::MAX_PACKAGE_BYTES ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'mobo_core_upgrade_package_size', 'Downloaded package is empty or exceeds 50 MiB.' );
		}
		return $tmp;
	}

	private static function resolve_package_redirect_url( $current_url, $location ) {
		$location = trim( (string) $location );
		if ( '' === $location ) {
			return new WP_Error( 'mobo_core_upgrade_redirect_location_missing', 'Package redirect did not include a Location header.' );
		}
		if ( preg_match( '#^https?://#i', $location ) ) {
			return esc_url_raw( $location );
		}
		$parts = wp_parse_url( $current_url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'mobo_core_upgrade_redirect_base_invalid', 'Package redirect base URL is invalid.' );
		}
		$port   = isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';
		$origin = $parts['scheme'] . '://' . $parts['host'] . $port;
		if ( 0 === strpos( $location, '/' ) ) {
			return esc_url_raw( $origin . $location );
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$dir  = preg_replace( '#/[^/]*$#', '/', $path );
		return esc_url_raw( $origin . $dir . $location );
	}

	private static function same_url_origin( $a, $b ) {
		$pa = wp_parse_url( $a );
		$pb = wp_parse_url( $b );
		if ( ! is_array( $pa ) || ! is_array( $pb ) || empty( $pa['scheme'] ) || empty( $pa['host'] ) || empty( $pb['scheme'] ) || empty( $pb['host'] ) ) {
			return false;
		}
		$sa = strtolower( (string) $pa['scheme'] );
		$sb = strtolower( (string) $pb['scheme'] );
		$ha = strtolower( rtrim( (string) $pa['host'], '.' ) );
		$hb = strtolower( rtrim( (string) $pb['host'], '.' ) );
		$porta = isset( $pa['port'] ) ? absint( $pa['port'] ) : ( 'https' === $sa ? 443 : 80 );
		$portb = isset( $pb['port'] ) ? absint( $pb['port'] ) : ( 'https' === $sb ? 443 : 80 );
		return $sa === $sb && $ha === $hb && $porta === $portb;
	}

	/**
	 * Initialize the only filesystem mode suitable for unattended REST upgrades.
	 * FTP/SSH credential prompts cannot be completed by a background deployment.
	 *
	 * @return true|WP_Error
	 */
	private static function initialize_direct_filesystem() {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		global $wp_filesystem;
		if ( is_object( $wp_filesystem ) ) {
			return true;
		}

		$force_direct = static function () {
			return 'direct';
		};
		add_filter( 'filesystem_method', $force_direct, PHP_INT_MAX );
		$initialized = WP_Filesystem( array(), ABSPATH, true );
		remove_filter( 'filesystem_method', $force_direct, PHP_INT_MAX );

		if ( ! $initialized || ! is_object( $wp_filesystem ) ) {
			return new WP_Error(
				'mobo_core_upgrade_filesystem',
				'Could not access filesystem. PHP must have direct write access to wp-content and the plugin directory.'
			);
		}

		if ( ! $wp_filesystem->is_writable( WP_CONTENT_DIR ) || ! $wp_filesystem->is_writable( WP_PLUGIN_DIR ) ) {
			return new WP_Error(
				'mobo_core_upgrade_filesystem_not_writable',
				'WordPress content or plugin directory is not writable by PHP.'
			);
		}

		return true;
	}

	private static function validate_package( $zip_file, $target_version, $deployment_id, $target_build_fingerprint = '' ) {
		$filesystem = self::initialize_direct_filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}

		$staging = trailingslashit( MOBO_CORE_DATA_DIR ) . 'upgrade-staging/' . sanitize_file_name( $deployment_id );
		self::delete_tree( $staging );
		if ( ! wp_mkdir_p( $staging ) ) {
			return new WP_Error( 'mobo_core_upgrade_staging_failed', 'Could not create the validation directory.' );
		}

		$result = unzip_file( $zip_file, $staging );
		if ( is_wp_error( $result ) ) {
			self::delete_tree( $staging );
			return $result;
		}

		$main_file = trailingslashit( $staging ) . 'mobo-core/mobo-core.php';
		if ( ! is_file( $main_file ) ) {
			self::delete_tree( $staging );
			return new WP_Error( 'mobo_core_upgrade_invalid_layout', 'Package must contain mobo-core/mobo-core.php.' );
		}

		$version = self::read_header_version( $main_file );
		if ( '' === $version || ! hash_equals( $target_version, $version ) ) {
			self::delete_tree( $staging );
			return new WP_Error( 'mobo_core_upgrade_version_invalid', 'Package plugin header does not match targetVersion.' );
		}

		$required_files = array(
			'mobo-core/includes/class-mobo-core-rest-controller.php',
			'mobo-core/includes/class-mobo-core-remote-updater.php',
			'mobo-core/includes/class-mobo-core-portal-request-signer.php',
			'mobo-core/includes/class-mobo-core-upgrade-coordinator.php',
			'mobo-core/includes/class-mobo-core-migration.php',
			'mobo-core/mobo-core-manifest.json',
		);
		foreach ( $required_files as $relative ) {
			if ( ! is_file( trailingslashit( $staging ) . $relative ) ) {
				self::delete_tree( $staging );
				return new WP_Error( 'mobo_core_upgrade_required_file_missing', 'Package is missing required file: ' . $relative );
			}
		}

		$manifest_validation = self::validate_manifest( $staging, $version );
		if ( is_wp_error( $manifest_validation ) ) {
			self::delete_tree( $staging );
			return $manifest_validation;
		}
		if ( '' !== $target_build_fingerprint && ! hash_equals( $target_build_fingerprint, $manifest_validation ) ) {
			self::delete_tree( $staging );
			return new WP_Error( 'mobo_core_upgrade_build_fingerprint_mismatch', 'Plugin package build fingerprint does not match targetBuildFingerprint.' );
		}

		return array( 'stagingDir' => $staging, 'version' => $version, 'buildFingerprint' => $manifest_validation );
	}

	private static function validate_manifest( $staging, $expected_version ) {
		$plugin_root = trailingslashit( $staging ) . 'mobo-core';
		$inspection = self::inspect_manifest_root( $plugin_root, $expected_version );
		if ( is_wp_error( $inspection ) ) {
			return $inspection;
		}

		return isset( $inspection['fingerprint'] ) ? (string) $inspection['fingerprint'] : '';
	}

	/**
	 * Verify the active plugin tree against the installed manifest, not merely the
	 * hashes declared by the manifest. This is intentionally request-cached because
	 * Health and upgrade status can ask for the same evidence more than once.
	 *
	 * @param bool $force Ignore the request-local cache.
	 * @return array
	 */
	public static function get_build_integrity_status( $force = false, $expected_version = '' ) {
		$expected_version = self::clean_version( $expected_version );
		$default_expected = '' === $expected_version;
		if ( $default_expected ) {
			$expected_version = defined( 'MOBO_CORE_VERSION' ) ? self::clean_version( (string) MOBO_CORE_VERSION ) : '';
		}
		if ( $default_expected && ! $force && is_array( self::$build_integrity_cache ) ) {
			return self::$build_integrity_cache;
		}
		$inspection = self::inspect_manifest_root( untrailingslashit( MOBO_CORE_PLUGIN_DIR ), $expected_version );
		if ( is_wp_error( $inspection ) ) {
			$result = array(
				'ready'        => false,
				'fingerprint'  => '',
				'filesChecked' => 0,
				'errorCode'    => sanitize_key( (string) $inspection->get_error_code() ),
				'message'      => 'Installed plugin manifest verification failed.',
			);
			if ( $default_expected ) {
				self::$build_integrity_cache = $result;
			}
			return $result;
		}

		$result = array(
			'ready'        => true,
			'fingerprint'  => isset( $inspection['fingerprint'] ) ? (string) $inspection['fingerprint'] : '',
			'filesChecked' => absint( $inspection['filesChecked'] ?? 0 ),
			'errorCode'    => '',
			'message'      => 'Installed plugin files match the packaged manifest.',
		);
		if ( $default_expected ) {
			self::$build_integrity_cache = $result;
		}
		return $result;
	}

	/**
	 * Validate one plugin root against its manifest and reject missing, modified,
	 * untracked or symlinked files. The manifest file itself is excluded from the
	 * file map so build fingerprints stay stable across generatedAt changes.
	 *
	 * @param string $plugin_root Plugin root directory.
	 * @param string $expected_version Expected plugin/manifest version.
	 * @return array|WP_Error
	 */
	private static function inspect_manifest_root( $plugin_root, $expected_version ) {
		$plugin_root = untrailingslashit( (string) $plugin_root );
		$manifest_file = trailingslashit( $plugin_root ) . 'mobo-core-manifest.json';
		$raw = @file_get_contents( $manifest_file );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return new WP_Error( 'mobo_core_upgrade_manifest_missing', 'Plugin manifest could not be read.' );
		}

		$manifest = json_decode( $raw, true );
		if ( ! is_array( $manifest ) ||
			empty( $manifest['files'] ) ||
			! is_array( $manifest['files'] ) ||
			'sha256' !== strtolower( isset( $manifest['algorithm'] ) ? (string) $manifest['algorithm'] : '' ) ||
			( '' !== $expected_version && ! hash_equals( $expected_version, self::clean_version( isset( $manifest['version'] ) ? $manifest['version'] : '' ) ) ) ) {
			return new WP_Error( 'mobo_core_upgrade_manifest_invalid', 'Plugin manifest is invalid or does not match the installed version.' );
		}

		$real_root = realpath( $plugin_root );
		if ( false === $real_root || ! is_dir( $real_root ) ) {
			return new WP_Error( 'mobo_core_upgrade_manifest_root_missing', 'Plugin root could not be resolved for integrity verification.' );
		}
		$real_root = trailingslashit( wp_normalize_path( $real_root ) );

		$tracked = array();
		foreach ( $manifest['files'] as $relative => $expected_hash ) {
			$relative = str_replace( '\\', '/', trim( (string) $relative ) );
			$expected_hash = strtolower( trim( (string) $expected_hash ) );
			if ( '' === $relative ||
				0 === strpos( $relative, '/' ) ||
				false !== strpos( $relative, '../' ) ||
				! preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) ) {
				return new WP_Error( 'mobo_core_upgrade_manifest_entry_invalid', 'Plugin manifest contains an unsafe or invalid entry.' );
			}

			$file = trailingslashit( $plugin_root ) . $relative;
			if ( is_link( $file ) ) {
				return new WP_Error( 'mobo_core_upgrade_symlink_rejected', 'Plugin tree contains a symbolic link: ' . sanitize_text_field( $relative ) );
			}
			$real_file = realpath( $file );
			$real_file = false !== $real_file ? wp_normalize_path( $real_file ) : false;
			if ( false === $real_file || 0 !== strpos( $real_file, $real_root ) || ! is_file( $real_file ) ) {
				return new WP_Error( 'mobo_core_upgrade_manifest_file_missing', 'Plugin manifest references a missing file: ' . sanitize_text_field( $relative ) );
			}

			$actual_hash = hash_file( 'sha256', $real_file );
			if ( ! is_string( $actual_hash ) || ! hash_equals( $expected_hash, strtolower( $actual_hash ) ) ) {
				return new WP_Error( 'mobo_core_upgrade_manifest_hash_mismatch', 'Plugin file hash does not match manifest: ' . sanitize_text_field( $relative ) );
			}
			$tracked[ $relative ] = true;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $plugin_root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
		} catch ( UnexpectedValueException $e ) {
			return new WP_Error( 'mobo_core_upgrade_manifest_scan_failed', 'Plugin tree could not be scanned for untracked files.' );
		}

		foreach ( $iterator as $file_info ) {
			if ( $file_info->isLink() ) {
				return new WP_Error( 'mobo_core_upgrade_symlink_rejected', 'Plugin tree contains a symbolic link, which is not allowed.' );
			}
			if ( ! $file_info->isFile() ) {
				continue;
			}
			$absolute = wp_normalize_path( $file_info->getPathname() );
			if ( 0 !== strpos( $absolute, $real_root ) ) {
				return new WP_Error( 'mobo_core_upgrade_manifest_scan_escape', 'Plugin tree scan escaped the expected root.' );
			}
			$relative = ltrim( substr( $absolute, strlen( $real_root ) ), '/' );
			if ( 'mobo-core-manifest.json' === $relative ) {
				continue;
			}
			if ( ! isset( $tracked[ $relative ] ) ) {
				return new WP_Error( 'mobo_core_upgrade_manifest_untracked_file', 'Plugin tree contains an untracked file: ' . sanitize_text_field( $relative ) );
			}
		}

		$fingerprint = self::build_fingerprint_from_files( $manifest['files'] );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return new WP_Error( 'mobo_core_upgrade_build_fingerprint_invalid', 'Plugin build fingerprint could not be computed.' );
		}

		return array(
			'fingerprint'  => $fingerprint,
			'filesChecked' => count( $tracked ),
		);
	}

	private static function install_package( $zip_file ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$filesystem = self::initialize_direct_filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}

		$skin = class_exists( 'Automatic_Upgrader_Skin' ) ? new Automatic_Upgrader_Skin() : new WP_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result = $upgrader->run(
			array(
				'package'           => $zip_file,
				'destination'       => WP_PLUGIN_DIR,
				'clear_destination' => true,
				'clear_working'     => true,
				'abort_if_destination_exists' => false,
				'hook_extra'        => array(
					'plugin' => plugin_basename( MOBO_CORE_PLUGIN_FILE ),
					'type'   => 'plugin',
					'action' => 'update',
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			$errors = method_exists( $skin, 'get_errors' ) ? $skin->get_errors() : null;
			if ( is_wp_error( $errors ) && $errors->has_errors() ) {
				return $errors;
			}
			return new WP_Error( 'mobo_core_upgrade_install_failed', 'WordPress upgrader returned failure.' );
		}

		return true;
	}

	/**
	 * Create a verified rollback snapshot without destroying an already-good backup
	 * until the replacement snapshot is complete. If the active plugin tree is
	 * already damaged (for example after a killed previous install), preserve and
	 * reuse a verified backup for the same source version instead of overwriting it.
	 *
	 * @param string $deployment_id Deployment identifier.
	 * @param string $current_version Expected source version.
	 * @return array|WP_Error
	 */
	private static function create_backup( $deployment_id, $current_version ) {
		$source = untrailingslashit( MOBO_CORE_PLUGIN_DIR );
		$current_version = self::clean_version( $current_version );
		$source_integrity = self::get_build_integrity_status( true );

		if ( empty( $source_integrity['ready'] ) ) {
			$existing = self::verify_backup_snapshot( $current_version );
			if ( ! is_wp_error( $existing ) ) {
				$existing['reused'] = true;
				return $existing;
			}

			return new WP_Error(
				'mobo_core_upgrade_source_integrity_failed',
				'Installed plugin files are not manifest-valid and no verified rollback backup for the current version is available.'
			);
		}

		$source_tree = self::tree_snapshot( $source );
		if ( is_wp_error( $source_tree ) ) {
			return $source_tree;
		}

		$backup_container = self::backup_container_dir();
		$pending_root = trailingslashit( $backup_container ) . 'pending-' . substr( hash( 'sha256', sanitize_text_field( (string) $deployment_id ) . '|' . wp_generate_uuid4() ), 0, 24 );
		$pending_plugin = trailingslashit( $pending_root ) . 'mobo-core';
		self::delete_tree( $pending_root );
		if ( ! wp_mkdir_p( $pending_root ) ) {
			return new WP_Error( 'mobo_core_upgrade_backup_dir', 'Could not create the pending rollback directory.' );
		}

		if ( ! self::copy_tree( $source, $pending_plugin ) ) {
			self::delete_tree( $pending_root );
			return new WP_Error( 'mobo_core_upgrade_backup_failed', 'Could not create a complete local plugin backup.' );
		}

		$pending_integrity = self::inspect_manifest_root( $pending_plugin, $current_version );
		$pending_tree = self::tree_snapshot( $pending_plugin );
		if ( is_wp_error( $pending_integrity ) || is_wp_error( $pending_tree ) ) {
			self::delete_tree( $pending_root );
			$message = is_wp_error( $pending_integrity ) ? $pending_integrity->get_error_message() : $pending_tree->get_error_message();
			return new WP_Error( 'mobo_core_upgrade_backup_verify_failed', 'Rollback backup verification failed: ' . sanitize_text_field( (string) $message ) );
		}

		if ( ! hash_equals( (string) $source_tree['fingerprint'], (string) $pending_tree['fingerprint'] ) || absint( $source_tree['fileCount'] ) !== absint( $pending_tree['fileCount'] ) ) {
			self::delete_tree( $pending_root );
			return new WP_Error( 'mobo_core_upgrade_backup_tree_mismatch', 'Rollback backup bytes do not match the active plugin tree.' );
		}

		$backup_root = self::backup_root();
		$previous_root = self::previous_backup_root();
		self::delete_tree( $previous_root );
		if ( is_dir( $backup_root ) && ! self::move_tree( $backup_root, $previous_root ) ) {
			self::delete_tree( $pending_root );
			return new WP_Error( 'mobo_core_upgrade_backup_rotate_failed', 'Could not rotate the previous rollback backup.' );
		}

		if ( ! self::move_tree( $pending_root, $backup_root ) ) {
			if ( is_dir( $previous_root ) && ! is_dir( $backup_root ) ) {
				self::move_tree( $previous_root, $backup_root );
			}
			self::delete_tree( $pending_root );
			return new WP_Error( 'mobo_core_upgrade_backup_promote_failed', 'Could not promote the verified rollback backup.' );
		}

		$verified = self::verify_backup_snapshot( $current_version );
		if ( is_wp_error( $verified ) ) {
			self::delete_tree( $backup_root );
			if ( is_dir( $previous_root ) ) {
				self::move_tree( $previous_root, $backup_root );
			}
			return $verified;
		}
		self::delete_tree( $previous_root );
		self::$rollback_integrity_cache = null;
		$verified['reused'] = false;
		return $verified;
	}

	/**
	 * Restore only from a backup that verifies before the live plugin directory is
	 * touched, then prove the restored tree matches the backup byte-for-byte.
	 *
	 * @param string $expected_version Source version being restored.
	 * @param array  $expected_meta Snapshot returned by create_backup().
	 * @return true|WP_Error
	 */
	private static function restore_backup( $expected_version, $expected_meta = array() ) {
		$backup = self::backup_plugin_dir();
		$destination = untrailingslashit( MOBO_CORE_PLUGIN_DIR );
		$verified = self::verify_backup_snapshot( $expected_version );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		if ( is_array( $expected_meta ) && ! empty( $expected_meta['treeFingerprint'] ) && ! hash_equals( (string) $expected_meta['treeFingerprint'], (string) $verified['treeFingerprint'] ) ) {
			return new WP_Error( 'mobo_core_upgrade_backup_changed', 'Rollback backup changed after it was verified.' );
		}

		self::delete_tree( $destination );
		if ( ! self::copy_tree( $backup, $destination ) ) {
			return new WP_Error( 'mobo_core_upgrade_restore_failed', 'Could not restore the previous plugin files.' );
		}

		$restored_integrity = self::inspect_manifest_root( $destination, self::clean_version( $expected_version ) );
		$restored_tree = self::tree_snapshot( $destination );
		if ( is_wp_error( $restored_integrity ) || is_wp_error( $restored_tree ) ) {
			return new WP_Error( 'mobo_core_upgrade_restore_verify_failed', 'Restored plugin files could not be verified.' );
		}
		if ( ! hash_equals( (string) $verified['treeFingerprint'], (string) $restored_tree['fingerprint'] ) || absint( $verified['fileCount'] ) !== absint( $restored_tree['fileCount'] ) ) {
			return new WP_Error( 'mobo_core_upgrade_restore_tree_mismatch', 'Restored plugin bytes do not match the verified rollback backup.' );
		}

		self::$build_integrity_cache = null;
		return true;
	}

	/**
	 * Return bounded verified rollback evidence for Portal/status diagnostics.
	 *
	 * @param bool $force Ignore request-local cache.
	 * @return array
	 */
	private static function get_rollback_integrity_status( $force = false ) {
		if ( ! $force && is_array( self::$rollback_integrity_cache ) ) {
			return self::$rollback_integrity_cache;
		}

		$backup = self::backup_plugin_dir();
		$version = self::read_header_version( trailingslashit( $backup ) . 'mobo-core.php' );
		if ( '' === $version ) {
			$result = array( 'ready' => false, 'version' => '', 'buildFingerprint' => '', 'fileCount' => 0, 'message' => 'No verified rollback snapshot is available.' );
			self::$rollback_integrity_cache = $result;
			return $result;
		}

		$verified = self::verify_backup_snapshot( $version );
		if ( is_wp_error( $verified ) ) {
			$result = array(
				'ready' => false,
				'version' => $version,
				'buildFingerprint' => '',
				'fileCount' => 0,
				'message' => 'Rollback backup could not be verified.',
			);
			self::$rollback_integrity_cache = $result;
			return $result;
		}

		$result = array(
			'ready' => true,
			'version' => $version,
			'buildFingerprint' => (string) ( $verified['buildFingerprint'] ?? '' ),
			'fileCount' => absint( $verified['fileCount'] ?? 0 ),
			'message' => 'Rollback snapshot is manifest-valid and byte-verifiable.',
		);
		self::$rollback_integrity_cache = $result;
		return $result;
	}

	/** @return array|WP_Error */
	private static function verify_backup_snapshot( $expected_version ) {
		$backup = self::backup_plugin_dir();
		if ( ! is_dir( $backup ) ) {
			return new WP_Error( 'mobo_core_upgrade_backup_missing', 'Local rollback backup is missing.' );
		}
		$integrity = self::inspect_manifest_root( $backup, self::clean_version( $expected_version ) );
		if ( is_wp_error( $integrity ) ) {
			return new WP_Error( 'mobo_core_upgrade_backup_manifest_invalid', 'Rollback backup manifest verification failed: ' . sanitize_text_field( $integrity->get_error_message() ) );
		}
		$tree = self::tree_snapshot( $backup );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		return array(
			'buildFingerprint' => isset( $integrity['fingerprint'] ) ? (string) $integrity['fingerprint'] : '',
			'treeFingerprint'  => (string) $tree['fingerprint'],
			'fileCount'        => absint( $tree['fileCount'] ),
			'version'          => self::clean_version( $expected_version ),
		);
	}

	/**
	 * Build a deterministic digest of every actual file in a directory. This is a
	 * copy/restore proof, separate from the release-manifest identity.
	 *
	 * @param string $root Root directory.
	 * @return array|WP_Error
	 */
	private static function tree_snapshot( $root ) {
		$root = untrailingslashit( (string) $root );
		$real_root = realpath( $root );
		if ( false === $real_root || ! is_dir( $real_root ) ) {
			return new WP_Error( 'mobo_core_upgrade_tree_root_missing', 'Plugin tree could not be resolved.' );
		}
		$real_root = trailingslashit( wp_normalize_path( $real_root ) );
		$files = array();
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
		} catch ( UnexpectedValueException $e ) {
			return new WP_Error( 'mobo_core_upgrade_tree_scan_failed', 'Plugin tree could not be scanned.' );
		}

		foreach ( $iterator as $file_info ) {
			if ( $file_info->isLink() ) {
				return new WP_Error( 'mobo_core_upgrade_tree_symlink', 'Plugin tree contains a symbolic link.' );
			}
			if ( ! $file_info->isFile() ) {
				continue;
			}
			$absolute = wp_normalize_path( $file_info->getPathname() );
			if ( 0 !== strpos( $absolute, $real_root ) ) {
				return new WP_Error( 'mobo_core_upgrade_tree_escape', 'Plugin tree scan escaped the expected root.' );
			}
			$relative = ltrim( substr( $absolute, strlen( $real_root ) ), '/' );
			$hash = hash_file( 'sha256', $absolute );
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', strtolower( $hash ) ) ) {
				return new WP_Error( 'mobo_core_upgrade_tree_hash_failed', 'Could not hash plugin tree file: ' . sanitize_text_field( $relative ) );
			}
			$files[ $relative ] = strtolower( $hash );
			if ( count( $files ) > self::MAX_TREE_FILES ) {
				return new WP_Error( 'mobo_core_upgrade_tree_too_large', 'Plugin tree exceeds the bounded file-count limit.' );
			}
		}

		ksort( $files, SORT_STRING );
		$canonical = '';
		foreach ( $files as $relative => $hash ) {
			$canonical .= $relative . '=' . $hash . "\n";
		}
		return array(
			'fingerprint' => hash( 'sha256', $canonical ),
			'fileCount'   => count( $files ),
		);
	}

	/** @return bool */
	private static function move_tree( $source, $destination ) {
		$filesystem = self::initialize_direct_filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return false;
		}
		global $wp_filesystem;
		return is_object( $wp_filesystem ) && (bool) $wp_filesystem->move( $source, $destination, true );
	}

	private static function copy_tree( $source, $destination ) {
		if ( is_link( $source ) ) {
			return false;
		}
		if ( is_file( $source ) ) {
			$parent = dirname( $destination );
			if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
				return false;
			}
			return copy( $source, $destination );
		}
		if ( ! is_dir( $source ) ) {
			return false;
		}
		if ( ! is_dir( $destination ) && ! wp_mkdir_p( $destination ) ) {
			return false;
		}
		$items = scandir( $source );
		if ( false === $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			if ( ! self::copy_tree( $source . DIRECTORY_SEPARATOR . $item, $destination . DIRECTORY_SEPARATOR . $item ) ) {
				return false;
			}
		}
		return true;
	}

	private static function delete_tree( $path ) {
		if ( '' === $path || '/' === $path || ! file_exists( $path ) ) {
			return;
		}

		$filesystem = self::initialize_direct_filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return;
		}

		global $wp_filesystem;
		if ( is_object( $wp_filesystem ) ) {
			$wp_filesystem->delete( $path, true );
		}
	}

	private static function verify_portal_signature( $deployment_id, $expected_current, $target_version, $target_build_fingerprint, $package_url, $package_sha256, $download_token, $issued_at, $provided_signature, $signature_version = 1 ) {
		if ( $issued_at <= 0 || abs( time() - $issued_at ) > 300 || ! preg_match( '/^[a-f0-9]{64}$/', $provided_signature ) ) {
			return false;
		}
		if ( 1 !== $signature_version && 2 !== $signature_version ) {
			return false;
		}
		if ( 2 === $signature_version && ! preg_match( '/^[a-f0-9]{64}$/', $target_build_fingerprint ) ) {
			return false;
		}

		$security_source = class_exists( 'Mobo_Core_Connection_Repository' )
			? Mobo_Core_Connection_Repository::get_runtime_value( 'security_code', get_option( 'mobo_core_security_code', '' ) )
			: get_option( 'mobo_core_security_code', '' );
		$security_code = Mobo_Core_Settings::normalize_security_code( $security_source );
		if ( '' === $security_code ) {
			return false;
		}

		$parts = array(
			$deployment_id,
			$expected_current,
			$target_version,
		);
		if ( 2 === $signature_version ) {
			$parts[] = $target_build_fingerprint;
		}
		$parts[] = $package_url;
		$parts[] = $package_sha256;
		$parts[] = $download_token;
		$parts[] = (string) $issued_at;
		$canonical = implode( "\n", $parts );
		$expected_signature = hash_hmac( 'sha256', $canonical, $security_code );
		return hash_equals( $expected_signature, $provided_signature );
	}

	/**
	 * Stable installed-build identity derived only from the manifest file map.
	 * generatedAt and ZIP bytes are deliberately excluded.
	 *
	 * @return string Lowercase SHA-256 hex, or empty when unavailable.
	 */
	public static function get_build_fingerprint() {
		$integrity = self::get_build_integrity_status();
		return ! empty( $integrity['ready'] ) && isset( $integrity['fingerprint'] )
			? (string) $integrity['fingerprint']
			: '';
	}

	/**
	 * @param array $files Manifest files map.
	 * @return string
	 */
	private static function build_fingerprint_from_files( $files ) {
		if ( ! is_array( $files ) || empty( $files ) ) {
			return '';
		}
		$normalized = array();
		foreach ( $files as $relative => $hash ) {
			$relative = str_replace( '\\', '/', trim( (string) $relative ) );
			$hash = strtolower( trim( (string) $hash ) );
			if ( '' === $relative || 0 === strpos( $relative, '/' ) || false !== strpos( $relative, '../' ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
				return '';
			}
			$normalized[ $relative ] = $hash;
		}
		ksort( $normalized, SORT_STRING );
		$canonical = '';
		foreach ( $normalized as $relative => $hash ) {
			$canonical .= $relative . '=' . $hash . "\n";
		}
		return hash( 'sha256', $canonical );
	}

	private static function is_trusted_package_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || empty( $parts['path'] ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			return false;
		}
		$debug_http_allowed = defined( 'WP_DEBUG' ) && WP_DEBUG && 'http' === $scheme;
		if ( false === strpos( (string) $parts['path'], '/api/plugin-packages/' ) ) {
			return false;
		}

		/* The Backend intentionally stays on plain http (owner decision), so the
		 * update after this one must be deliverable over http. Plain-http packages
		 * are trusted ONLY from the exact origin (scheme, host, effective port) of
		 * the authenticated Connection's Backend. The administrator host allowlist
		 * never widens http; package bytes stay bound by the X-SEC-signed sha256. */
		if ( 'http' === $scheme && ! $debug_http_allowed ) {
			$api_base = self::trusted_backend_base_url();
			return '' !== trim( $api_base ) && self::same_url_origin( $api_base, $url );
		}

		$configured_hosts = get_option( 'mobo_core_remote_update_allowed_hosts', '' );
		$allowed = is_string( $configured_hosts ) ? preg_split( '/[\s,;]+/', $configured_hosts ) : array();
		$allowed = apply_filters( 'mobo_core_remote_update_allowed_hosts', is_array( $allowed ) ? $allowed : array() );
		$allowed = array_values( array_unique( array_filter( array_map( 'strtolower', is_array( $allowed ) ? $allowed : array() ) ) ) );

		if ( empty( $allowed ) ) {
			$api_base = self::trusted_backend_base_url();

			/* License/package credentials are sent to this URL. With no explicit
			 * administrator allowlist, require the exact Portal origin, including
			 * scheme and effective port—not merely the same hostname. */
			return '' !== trim( $api_base ) && self::same_url_origin( $api_base, $url );
		}

		return in_array( strtolower( (string) $parts['host'] ), $allowed, true );
	}

	/**
	 * Effective Backend base URL of the authenticated Connection (constant
	 * override > Connection api_base_url; legacy fallbacks only pre-table).
	 *
	 * @return string
	 */
	private static function trusted_backend_base_url() {
		$api_base = class_exists( 'Mobo_Core_Connection_Repository' )
			? (string) Mobo_Core_Connection_Repository::get_runtime_base_url( '' )
			: '';
		if ( '' === trim( (string) $api_base ) ) {
			$api_base = apply_filters( 'mobo_core_api_base_url', '' );
		}
		if ( ! is_string( $api_base ) || '' === trim( $api_base ) ) {
			$api_base = (string) Mobo_Core_Settings::get( 'mobo_core_api_base_url', '' );
		}
		return is_string( $api_base ) ? $api_base : '';
	}

	/**
	 * Read-only migration gate for /upgrade/apply and /upgrade/status.
	 *
	 * Never acquires or refreshes the migration mutex. Mirrors the two lock
	 * forms used by Mobo_Core_Migration: the connection-scoped GET_LOCK name and
	 * the fallback option lease (MIGRATION_LOCK_OPTION, JSON {owner, expiresAt}).
	 *
	 * @return array{dbVersion:string,codeVersion:string,dbVersionMatches:bool,lockHeld:bool,lockKind:string,inProgress:bool,state:string,upgradeAllowed:bool,retryAfter:int}
	 */
	public static function get_migration_gate_status() {
		global $wpdb;
		$db_version   = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		$code_version = defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '';
		$matches      = '' !== $code_version && $db_version === $code_version;

		$lock_kind = '';
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$db_name   = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
			$lock_name = 'mobo_mig_' . substr( sha1( $db_name . '|' . (string) $wpdb->prefix ), 0, 40 );
			$wpdb->last_error = '';
			$holder = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $lock_name ) );
			if ( '' === trim( (string) $wpdb->last_error ) && null !== $holder && '' !== (string) $holder && '0' !== (string) $holder ) {
				$lock_kind = 'mysql';
			}
			if ( '' === $lock_kind ) {
				$option_name = ( class_exists( 'Mobo_Core_Migration' ) && defined( 'Mobo_Core_Migration::MIGRATION_LOCK_OPTION' ) )
					? (string) Mobo_Core_Migration::MIGRATION_LOCK_OPTION
					: 'mobo_core_migration_request_lock_v1';
				$wpdb->last_error = '';
				$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name ) );
				if ( '' === trim( (string) $wpdb->last_error ) && is_string( $raw ) && '' !== $raw ) {
					$decoded = json_decode( $raw, true );
					/* An undecodable lease is treated as held: fail closed, retry later. */
					if ( ! is_array( $decoded ) || absint( $decoded['expiresAt'] ?? 0 ) >= time() ) {
						$lock_kind = 'option';
					}
				}
			}
		}
		$lock_held = '' !== $lock_kind;
		$state = $lock_held ? 'running' : ( $matches ? 'complete' : 'pending' );

		return array(
			'dbVersion'        => sanitize_text_field( $db_version ),
			'codeVersion'      => $code_version,
			'dbVersionMatches' => $matches,
			'lockHeld'         => $lock_held,
			'lockKind'         => $lock_kind,
			'inProgress'       => 'complete' !== $state,
			'state'            => $state,
			'upgradeAllowed'   => 'complete' === $state,
			'retryAfter'       => 'complete' === $state ? 0 : 120,
		);
	}

	private static function read_disk_plugin_version() {
		return self::read_header_version( untrailingslashit( MOBO_CORE_PLUGIN_DIR ) . '/mobo-core.php' );
	}

	private static function read_header_version( $file ) {
		$contents = @file_get_contents( $file, false, null, 0, 8192 );
		if ( ! is_string( $contents ) ) {
			return '';
		}
		if ( preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $contents, $matches ) ) {
			return self::clean_version( trim( $matches[1] ) );
		}
		return '';
	}

	private static function clean_version( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^[0-9]+(?:\.[0-9]+){1,3}$/', $value ) ? $value : '';
	}

	private static function clean_identifier( $value ) {
		$value = sanitize_text_field( (string) $value );
		return ( '' !== $value && strlen( $value ) <= 128 && preg_match( '/^[A-Za-z0-9._:-]+$/', $value ) ) ? $value : '';
	}

	/** Only explicitly generated bounded errors are allowed into public status. */
	private static function safe_status_error_for_output( $message ) {
		$message = (string) $message;
		if ( '' === $message ) { return ''; }
		if ( preg_match( '/\APlugin upgrade failed during (downloading|validating|draining|backing-up|installing|verifying|unknown)\.( Verified rollback was not completed; manual recovery is required\.)?\z/D', $message ) ) {
			return $message;
		}
		return 'A previous upgrade error was withheld from public diagnostics.';
	}

	private static function write_status( $status ) {
		update_option( self::STATUS_OPTION, is_array( $status ) ? $status : array(), false );
	}

	private static function update_status_stage( $stage ) {
		$status = get_option( self::STATUS_OPTION, array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}
		$status['status'] = sanitize_key( $stage );
		self::write_status( $status );
	}

	private static function current_status_value( $key ) {
		$status = get_option( self::STATUS_OPTION, array() );
		return is_array( $status ) && isset( $status[ $key ] ) ? sanitize_text_field( (string) $status[ $key ] ) : '';
	}

	private static function append_history( $item ) {
		$history = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		array_unshift( $history, $item );
		$history = array_slice( $history, 0, 20 );
		update_option( self::HISTORY_OPTION, $history, false );
	}

	private static function backup_container_dir() {
		return trailingslashit( MOBO_CORE_DATA_DIR ) . 'upgrade-backups';
	}

	private static function backup_root() {
		return trailingslashit( self::backup_container_dir() ) . 'latest';
	}

	private static function previous_backup_root() {
		return trailingslashit( self::backup_container_dir() ) . 'previous';
	}

	private static function backup_plugin_dir() {
		$latest = trailingslashit( self::backup_root() ) . 'mobo-core';
		if ( is_dir( $latest ) ) {
			return $latest;
		}
		$previous = trailingslashit( self::previous_backup_root() ) . 'mobo-core';
		return is_dir( $previous ) ? $previous : $latest;
	}
}
