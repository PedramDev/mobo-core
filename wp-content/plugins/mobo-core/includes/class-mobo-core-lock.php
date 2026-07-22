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

		$key   = self::option_key( $name );
		$now   = time();
		$token = wp_generate_uuid4();

		/*
		 * An invalid or expired row is stale. Delete only the exact value that was
		 * inspected so another request cannot lose a newly acquired lock.
		 */
		$existing_raw = self::read_raw_option( $key );
		if ( null !== $existing_raw ) {
			$existing = self::decode_payload( $existing_raw );

			if ( is_array( $existing ) && $existing['expires_at'] > $now ) {
				return false;
			}

			self::delete_raw_option_if_value( $key, $existing_raw );
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

		if ( ! is_array( $payload ) || $payload['expires_at'] <= $now || ! hash_equals( $payload['token'], $token ) ) {
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
		 * acquire() and the first renew() may run within the same second.
		 * Because lock timestamps use second precision, the renewed payload can be
		 * byte-for-byte identical to the stored payload. MySQL reports zero affected
		 * rows for a no-op UPDATE, which must not be interpreted as lost ownership.
		 *
		 * Re-read the row to ensure it still contains the exact owned payload.
		 */
		if ( hash_equals( $raw, $renewed ) ) {
			$current_raw = self::read_raw_option( $key );

			return null !== $current_raw
				&& hash_equals( $raw, $current_raw );
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

		if ( ! is_array( $payload ) || $payload['expires_at'] <= $now ) {
			self::delete_raw_option_if_value( $key, $raw );
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

		if ( ! is_array( $payload ) || ! hash_equals( $payload['token'], $token ) ) {
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

		if ( is_array( $payload ) && $payload['expires_at'] > time() ) {
			return true;
		}

		self::delete_raw_option_if_value( $key, $raw );
		return false;
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
