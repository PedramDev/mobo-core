<?php
/**
 * Runtime lock helper.
 *
 * Locks are stored as atomic, non-autoloaded option rows. The token and expiry
 * live in the same database value, so a missing transient-timeout row can no
 * longer turn a short runtime lock into a permanent lock.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Lock correctness depends on atomic reads/inserts/deletes against the current
 * site's options table. Values are generated internally and external values are
 * sanitized before being used in option names.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
class Mobo_Core_Lock {

	const RECOVERY_LOG_OPTION  = 'mobo_core_stale_lock_recovery_log';
	const RECOVERY_LAST_OPTION = 'mobo_core_stale_lock_recovery_last';
	const RECOVERY_LOG_LIMIT   = 20;

	/**
	 * Acquire a named lock.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl TTL in seconds.
	 * @return string|false Lock token or false.
	 */
	public static function acquire( $name, $ttl = 30 ) {
		$name = sanitize_key( (string) $name );
		$ttl  = max( 5, absint( $ttl ) );

		if ( '' === $name ) {
			return false;
		}

		/*
		 * Once an upgrade barrier is active, no new sync/queue/maintenance lease
		 * may start. Existing owners can still renew and release their own lease,
		 * allowing a graceful drain without force-unlocking live work.
		 */
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::should_block_lock( $name ) ) {
			return false;
		}

		$key   = self::option_key( $name );
		$now   = time();
		$token = wp_generate_uuid4();

		/*
		 * An invalid or expired row is stale. Delete only the exact value that was
		 * inspected so another request cannot lose a newly acquired lock.
		 */
		$existing_raw = self::read_raw_option( $key );
		if ( null !== $existing_raw ) {
			$recovery = self::recover_raw_lock_if_stale( $name, $key, $existing_raw, $now );
			if ( empty( $recovery['recovered'] ) ) {
				return false;
			}
		}

		$payload = wp_json_encode(
			array(
				'token'        => $token,
				'created_at'   => $now,
				'heartbeat_at' => $now,
				'expires_at'   => $now + $ttl,
			),
			JSON_UNESCAPED_SLASHES
		);

		if ( ! is_string( $payload ) || '' === $payload ) {
			return false;
		}

		if ( ! self::insert_raw_option( $key, $payload ) ) {
			return false;
		}

		/*
		 * Close the check/insert race with barrier activation. If the barrier was
		 * installed after our first check, surrender only the lease we just created.
		 */
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::should_block_lock( $name ) ) {
			self::release( $name, $token );
			return false;
		}

		return $token;
	}

	/**
	 * Renew a named lock owned by the supplied token.
	 *
	 * Renewal uses a compare-and-swap update against the exact payload that was
	 * read. If the lease expired, was replaced, or belongs to another process,
	 * the update fails and the caller must stop doing protected work.
	 *
	 * @param string $name  Lock name.
	 * @param string $token Lock token.
	 * @param int    $ttl   New TTL in seconds from now.
	 * @return bool
	 */
	public static function renew( $name, $token, $ttl = 30 ) {
		$name  = sanitize_key( (string) $name );
		$token = sanitize_text_field( (string) $token );
		$ttl   = max( 5, absint( $ttl ) );

		if ( '' === $name || '' === $token ) {
			return false;
		}

		$key = self::option_key( $name );
		$raw = self::read_raw_option( $key );

		if ( null === $raw ) {
			return false;
		}

		$payload = self::decode_payload( $raw );
		$now     = time();

		if ( ! is_array( $payload ) || $payload['expires_at'] <= $now ) {
			self::recover_raw_lock_if_stale( $name, $key, $raw, $now );
			return false;
		}

		if ( ! hash_equals( $payload['token'], $token ) ) {
			return false;
		}

		$renewed = wp_json_encode(
			array(
				'token'        => $payload['token'],
				'created_at'   => $payload['created_at'] > 0 ? $payload['created_at'] : $now,
				'heartbeat_at' => $now,
				'expires_at'   => $now + $ttl,
			),
			JSON_UNESCAPED_SLASHES
		);

		if ( ! is_string( $renewed ) || '' === $renewed ) {
			return false;
		}

		/*
		 * acquire() and the first renew() can run within the same second. Since
		 * lock timestamps use second precision, the renewed payload may be
		 * byte-for-byte identical to the stored payload. MySQL reports zero
		 * affected rows for that no-op UPDATE, which does not mean ownership
		 * was lost. Re-read the exact value so a concurrent replacement or
		 * forced release is still detected before renewal is accepted.
		 */
		if ( hash_equals( $raw, $renewed ) ) {
			$current_raw = self::read_raw_option( $key );

			return null !== $current_raw && hash_equals( $raw, $current_raw );
		}

		return self::update_raw_option_if_value( $key, $raw, $renewed );
	}

	/**
	 * Return non-secret runtime information for one lock.
	 *
	 * Expired or malformed rows are removed during inspection.
	 *
	 * @param string $name Lock name.
	 * @return array
	 */
	public static function get_status( $name ) {
		$name = sanitize_key( (string) $name );
		$now  = time();

		if ( '' === $name ) {
			return array(
				'active'           => false,
				'acquiredAt'       => 0,
				'lastHeartbeatAt'  => 0,
				'expiresAt'        => 0,
				'remainingSeconds' => 0,
			);
		}

		$key = self::option_key( $name );
		$raw = self::read_raw_option( $key );

		if ( null === $raw ) {
			return array(
				'active'           => false,
				'acquiredAt'       => 0,
				'lastHeartbeatAt'  => 0,
				'expiresAt'        => 0,
				'remainingSeconds' => 0,
			);
		}

		$payload = self::decode_payload( $raw );

		$recovery = self::recover_raw_lock_if_stale( $name, $key, $raw, $now );
		if ( ! is_array( $payload ) || $payload['expires_at'] <= $now || ! empty( $recovery['recovered'] ) ) {
			return array(
				'active'           => false,
				'acquiredAt'       => 0,
				'lastHeartbeatAt'  => 0,
				'expiresAt'        => 0,
				'remainingSeconds' => 0,
			);
		}

		return array(
			'active'           => true,
			'acquiredAt'       => absint( $payload['created_at'] ),
			'lastHeartbeatAt'  => absint( $payload['heartbeat_at'] ),
			'expiresAt'        => absint( $payload['expires_at'] ),
			'remainingSeconds' => max( 0, absint( $payload['expires_at'] ) - $now ),
		);
	}

	/**
	 * Release a named lock owned by the supplied token.
	 *
	 * @param string $name Lock name.
	 * @param string $token Lock token.
	 * @return bool
	 */
	public static function release( $name, $token ) {
		$name  = sanitize_key( (string) $name );
		$token = sanitize_text_field( (string) $token );

		if ( '' === $name || '' === $token ) {
			return false;
		}

		$key = self::option_key( $name );
		$raw = self::read_raw_option( $key );

		if ( null === $raw ) {
			return true;
		}

		$payload = self::decode_payload( $raw );

		if ( ! is_array( $payload ) ) {
			self::recover_raw_lock_if_stale( $name, $key, $raw, time() );
			return false;
		}

		if ( ! hash_equals( $payload['token'], $token ) ) {
			return false;
		}

		return self::delete_raw_option_if_value( $key, $raw );
	}

	/**
	 * Release a lease only when its non-secret snapshot is still unchanged.
	 *
	 * This is intended for coordinator cleanup of a lease that is known to be
	 * pre-work (for example a self-runner HTTP handoff). The exact raw option
	 * value is deleted with compare-and-delete semantics, so a concurrent renew
	 * or ownership transfer makes the deletion fail instead of removing live work.
	 *
	 * @param string $name Lock name.
	 * @param array  $snapshot Snapshot returned by get_status().
	 * @return bool True when the matching lease was removed or was already absent.
	 */
	public static function release_if_snapshot_matches( $name, $snapshot ) {
		$name = sanitize_key( (string) $name );

		if ( '' === $name || ! is_array( $snapshot ) ) {
			return false;
		}

		$key = self::option_key( $name );
		$raw = self::read_raw_option( $key );
		if ( null === $raw ) {
			return true;
		}

		$payload = self::decode_payload( $raw );
		if ( ! is_array( $payload ) || $payload['expires_at'] <= time() ) {
			$recovery = self::recover_raw_lock_if_stale( $name, $key, $raw, time() );
			return ! empty( $recovery['recovered'] );
		}

		$expected_created   = isset( $snapshot['acquiredAt'] ) ? absint( $snapshot['acquiredAt'] ) : 0;
		$expected_heartbeat = isset( $snapshot['lastHeartbeatAt'] ) ? absint( $snapshot['lastHeartbeatAt'] ) : 0;
		$expected_expires   = isset( $snapshot['expiresAt'] ) ? absint( $snapshot['expiresAt'] ) : 0;

		if (
			$expected_created <= 0
			|| $expected_expires <= 0
			|| $payload['created_at'] !== $expected_created
			|| $payload['heartbeat_at'] !== $expected_heartbeat
			|| $payload['expires_at'] !== $expected_expires
		) {
			return false;
		}

		return self::delete_raw_option_if_value( $key, $raw );
	}

	/**
	 * Check whether a named lock currently exists.
	 *
	 * Expired or malformed rows are removed while checking.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	public static function is_locked( $name ) {
		$name = sanitize_key( (string) $name );

		if ( '' === $name ) {
			return false;
		}

		$key = self::option_key( $name );
		$raw = self::read_raw_option( $key );

		if ( null === $raw ) {
			return false;
		}

		$payload = self::decode_payload( $raw );
		$now     = time();

		if ( is_array( $payload ) && $payload['expires_at'] > $now ) {
			/* A valid, bounded live lease is never force-released. */
			$recovery = self::recover_raw_lock_if_stale( $name, $key, $raw, $now );
			return empty( $recovery['recovered'] );
		}

		self::recover_raw_lock_if_stale( $name, $key, $raw, $now );
		return false;
	}

	/**
	 * Recover stale runtime locks without ever deleting a healthy live lease.
	 *
	 * Normal leases are reclaimed only after expires_at. A second guard handles
	 * corrupted far-future expiry values: the heartbeat must be older than the
	 * lock-specific hard safety ceiling and the stored lease span itself must be
	 * implausibly larger than that ceiling. Every deletion is compare-and-delete
	 * against the exact raw payload that was inspected.
	 *
	 * @param array $names Optional lock names. Empty scans all Mobo runtime locks.
	 * @return array Recovery summary.
	 */
	public static function recover_stale_locks( $names = array() ) {
		global $wpdb;

		$requested = array();
		foreach ( is_array( $names ) ? $names : array() as $name ) {
			$name = sanitize_key( (string) $name );
			if ( '' !== $name ) {
				$requested[ $name ] = true;
			}
		}

		$rows = array();
		if ( ! empty( $requested ) ) {
			foreach ( array_keys( $requested ) as $name ) {
				$key = self::option_key( $name );
				$raw = self::read_raw_option( $key );
				if ( null !== $raw ) {
					$rows[] = array( 'name' => $name, 'key' => $key, 'raw' => $raw );
				}
			}
		} else {
			$prefix = 'mobo_core_runtime_lock_';
			$like   = $wpdb->esc_like( $prefix ) . '%';
			$found  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
					$like
				),
				ARRAY_A
			);
			foreach ( is_array( $found ) ? $found : array() as $row ) {
				$key = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
				if ( 0 !== strpos( $key, $prefix ) ) {
					continue;
				}
				$name = sanitize_key( substr( $key, strlen( $prefix ) ) );
				if ( '' === $name ) {
					continue;
				}
				$rows[] = array( 'name' => $name, 'key' => $key, 'raw' => isset( $row['option_value'] ) ? (string) $row['option_value'] : '' );
			}
		}

		$summary = array(
			'scanned'   => count( $rows ),
			'recovered' => 0,
			'active'    => 0,
			'raced'     => 0,
			'items'     => array(),
		);
		$now = time();

		foreach ( $rows as $row ) {
			$result = self::recover_raw_lock_if_stale( $row['name'], $row['key'], $row['raw'], $now );
			if ( ! empty( $result['recovered'] ) ) {
				$summary['recovered']++;
				$summary['items'][] = array( 'name' => $row['name'], 'reason' => $result['reason'] );
			} elseif ( ! empty( $result['raced'] ) ) {
				$summary['raced']++;
			} else {
				$summary['active']++;
			}
		}

		return $summary;
	}

	/** Return non-secret stale-lock recovery diagnostics. */
	public static function get_recovery_status() {
		$last = get_option( self::RECOVERY_LAST_OPTION, array() );
		$log  = get_option( self::RECOVERY_LOG_OPTION, array() );
		return array(
			'last' => is_array( $last ) ? $last : array(),
			'log'  => is_array( $log ) ? array_slice( $log, 0, self::RECOVERY_LOG_LIMIT ) : array(),
		);
	}

	/**
	 * Return all currently active Mobo runtime locks.
	 *
	 * Malformed and expired rows are removed through get_status(). This method is
	 * used by the plugin upgrade drain to observe live work without releasing it.
	 *
	 * @return array<string,array>
	 */
	public static function get_active_locks() {
		global $wpdb;

		$prefix = 'mobo_core_runtime_lock_';
		$like   = $wpdb->esc_like( $prefix ) . '%';
		$names  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
				$like
			)
		);

		$result = array();
		foreach ( is_array( $names ) ? $names : array() as $option_name ) {
			$option_name = (string) $option_name;
			if ( 0 !== strpos( $option_name, $prefix ) ) {
				continue;
			}

			$name   = sanitize_key( substr( $option_name, strlen( $prefix ) ) );
			$status = self::get_status( $name );
			if ( '' !== $name && ! empty( $status['active'] ) ) {
				$result[ $name ] = $status;
			}
		}

		ksort( $result );
		return $result;
	}

	/**
	 * Force delete one current or legacy lock.
	 *
	 * Use only for migration/admin/debug cleanup.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function force_release( $name ) {
		$name = sanitize_key( (string) $name );

		if ( '' === $name ) {
			return;
		}

		self::delete_raw_option( self::option_key( $name ) );
		self::delete_legacy_transient( $name );
	}

	/**
	 * Remove every Mobo runtime lock during plugin activation/upgrade.
	 *
	 * Both the current atomic option rows and the legacy transient rows are
	 * removed. The fixed legacy names are also evicted from an external object
	 * cache through delete_transient().
	 *
	 * @return array Cleanup summary.
	 */
	public static function force_release_all() {
		global $wpdb;

		$known_names = array(
			'real_cron_runner',
			'plugin_upgrade_barrier',
			'remote_plugin_upgrade',
			'image_refresh_automation',
			'maintenance_cleanup',
			'manual_sync_start',
			'manual_sync',
			'webhook_queue',
			'self_runner_kick',
		);

		foreach ( $known_names as $name ) {
			self::delete_legacy_transient( $name );
		}

		$current_like        = $wpdb->esc_like( 'mobo_core_runtime_lock_' ) . '%';
		$legacy_value_like   = $wpdb->esc_like( '_transient_mobo_core_lock_' ) . '%';
		$legacy_timeout_like = $wpdb->esc_like( '_transient_timeout_mobo_core_lock_' ) . '%';

		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name
				FROM {$wpdb->options}
				WHERE option_name LIKE %s
					OR option_name LIKE %s
					OR option_name LIKE %s",
				$current_like,
				$legacy_value_like,
				$legacy_timeout_like
			)
		);

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE %s
					OR option_name LIKE %s
					OR option_name LIKE %s",
				$current_like,
				$legacy_value_like,
				$legacy_timeout_like
			)
		);

		if ( is_array( $option_names ) ) {
			foreach ( $option_names as $option_name ) {
				self::clear_option_cache( sanitize_key( (string) $option_name ) );

				if ( 0 === strpos( (string) $option_name, '_transient_mobo_core_lock_' ) ) {
					$transient_name = substr( (string) $option_name, strlen( '_transient_' ) );
					wp_cache_delete( $transient_name, 'transient' );
				}
			}
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return array(
			'deleted' => false === $deleted ? 0 : absint( $deleted ),
			'found'   => is_array( $option_names ) ? count( $option_names ) : 0,
		);
	}

	/** Recover one inspected row when it is provably stale. */
	private static function recover_raw_lock_if_stale( $name, $key, $raw, $now ) {
		$payload = self::decode_payload( $raw );
		$reason  = '';

		if ( ! is_array( $payload ) ) {
			$reason = 'malformed';
		} elseif ( $payload['expires_at'] <= $now ) {
			$reason = 'expired';
		} else {
			$heartbeat = max( absint( $payload['created_at'] ), absint( $payload['heartbeat_at'] ) );
			$ceiling   = self::hard_stale_ceiling( $name );
			$span      = $heartbeat > 0 ? max( 0, absint( $payload['expires_at'] ) - $heartbeat ) : 0;

			/* Only recover a still-unexpired row when both timestamps prove the
			 * expiry itself is corrupted/far-future. This never shortens a normal TTL. */
			if ( $heartbeat > 0 && ( $now - $heartbeat ) > $ceiling && $span > $ceiling ) {
				$reason = 'heartbeat-stale';
			}
		}

		if ( '' === $reason ) {
			return array( 'recovered' => false, 'raced' => false, 'reason' => 'active' );
		}

		$deleted = self::delete_raw_option_if_value( $key, $raw );
		if ( ! $deleted ) {
			return array( 'recovered' => false, 'raced' => true, 'reason' => $reason );
		}

		self::record_recovery( $name, $reason, is_array( $payload ) ? $payload : array(), $now );
		return array( 'recovered' => true, 'raced' => false, 'reason' => $reason );
	}

	/** Hard ceiling used only to detect corrupted far-future leases. */
	private static function hard_stale_ceiling( $name ) {
		$name = sanitize_key( (string) $name );
		$ceilings = array(
			'manual_sync_start'          => 120,
			'manual_sync'                => 420,
			'self_runner_kick'            => 180,
			'worker_dispatcher'           => 720,
			'real_cron_runner'            => 720,
			'webhook_queue'               => 420,
			'image_queue_worker'          => 420,
			'image_refresh_queue_worker'  => 420,
			'reprice_queue_worker'        => 420,
			'recategorize_queue_worker'   => 420,
			'maintenance_cleanup'         => 420,
			'remote_plugin_upgrade'       => 1200,
			'plugin_upgrade_barrier'      => 2100,
		);
		return isset( $ceilings[ $name ] ) ? absint( $ceilings[ $name ] ) : 1800;
	}

	/** Store a bounded, non-secret audit trail for automatic recovery. */
	private static function record_recovery( $name, $reason, $payload, $now ) {
		$event = array(
			'name'            => sanitize_key( (string) $name ),
			'reason'          => sanitize_key( (string) $reason ),
			'recovered_at'    => absint( $now ),
			'created_at'      => isset( $payload['created_at'] ) ? absint( $payload['created_at'] ) : 0,
			'heartbeat_at'    => isset( $payload['heartbeat_at'] ) ? absint( $payload['heartbeat_at'] ) : 0,
			'expires_at'      => isset( $payload['expires_at'] ) ? absint( $payload['expires_at'] ) : 0,
		);

		update_option( self::RECOVERY_LAST_OPTION, $event, false );
		$log = get_option( self::RECOVERY_LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, $event );
		$log = array_slice( $log, 0, self::RECOVERY_LOG_LIMIT );
		update_option( self::RECOVERY_LOG_OPTION, $log, false );
	}

	/**
	 * Build the atomic option key.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private static function option_key( $name ) {
		return 'mobo_core_runtime_lock_' . sanitize_key( (string) $name );
	}

	/**
	 * Build the legacy transient name.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private static function legacy_transient_name( $name ) {
		return 'mobo_core_lock_' . sanitize_key( (string) $name );
	}

	/**
	 * Decode and validate a stored lock payload.
	 *
	 * @param string $raw Raw option value.
	 * @return array|null
	 */
	private static function decode_payload( $raw ) {
		$payload = json_decode( (string) $raw, true );

		if ( ! is_array( $payload ) || empty( $payload['token'] ) || empty( $payload['expires_at'] ) ) {
			return null;
		}

		$token        = sanitize_text_field( (string) $payload['token'] );
		$created_at   = isset( $payload['created_at'] ) ? absint( $payload['created_at'] ) : 0;
		$heartbeat_at = isset( $payload['heartbeat_at'] ) ? absint( $payload['heartbeat_at'] ) : $created_at;
		$expires_at   = absint( $payload['expires_at'] );

		if ( '' === $token || $expires_at <= 0 ) {
			return null;
		}

		return array(
			'token'        => $token,
			'created_at'   => $created_at,
			'heartbeat_at' => $heartbeat_at,
			'expires_at'   => $expires_at,
		);
	}

	/**
	 * Read a raw option value directly from the database.
	 *
	 * @param string $key Option key.
	 * @return string|null
	 */
	private static function read_raw_option( $key ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$key
			)
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * Atomically insert a raw non-autoloaded option.
	 *
	 * @param string $key Option key.
	 * @param string $value Option value.
	 * @return bool
	 */
	private static function insert_raw_option( $key, $value ) {
		global $wpdb;

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$key,
				$value,
				'no'
			)
		);

		self::clear_option_cache( $key );

		return 1 === (int) $inserted;
	}

	/**
	 * Update an option only when its value still matches the inspected value.
	 *
	 * @param string $key       Option key.
	 * @param string $old_value Exact current value.
	 * @param string $new_value Replacement value.
	 * @return bool
	 */
	private static function update_raw_option_if_value( $key, $old_value, $new_value ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s, autoload = %s WHERE option_name = %s AND option_value = %s",
				$new_value,
				'no',
				$key,
				$old_value
			)
		);

		self::clear_option_cache( $key );

		return 1 === (int) $updated;
	}

	/**
	 * Delete an option only when its value still matches the inspected value.
	 *
	 * @param string $key Option key.
	 * @param string $value Exact current value.
	 * @return bool
	 */
	private static function delete_raw_option_if_value( $key, $value ) {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				$value
			)
		);

		self::clear_option_cache( $key );

		return 1 === (int) $deleted;
	}

	/**
	 * Delete a raw option without ownership checks.
	 *
	 * @param string $key Option key.
	 * @return void
	 */
	private static function delete_raw_option( $key ) {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => $key ), array( '%s' ) );
		self::clear_option_cache( $key );
	}

	/**
	 * Delete one legacy transient from database and object cache.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	private static function delete_legacy_transient( $name ) {
		global $wpdb;

		$transient_name = self::legacy_transient_name( $name );
		$value_option   = '_transient_' . $transient_name;
		$timeout_option = '_transient_timeout_' . $transient_name;

		delete_transient( $transient_name );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
				$value_option,
				$timeout_option
			)
		);

		self::clear_option_cache( $value_option );
		self::clear_option_cache( $timeout_option );
		wp_cache_delete( $transient_name, 'transient' );
	}

	/**
	 * Clear option cache entries after direct database writes.
	 *
	 * @param string $key Option key.
	 * @return void
	 */
	private static function clear_option_cache( $key ) {
		wp_cache_delete( (string) $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
