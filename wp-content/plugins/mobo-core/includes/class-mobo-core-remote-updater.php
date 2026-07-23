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

		return array(
			'success'        => true,
			'status'         => isset( $status['status'] ) ? sanitize_key( (string) $status['status'] ) : 'idle',
			'currentVersion' => defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '',
			'deploymentId'   => isset( $status['deploymentId'] ) ? sanitize_text_field( (string) $status['deploymentId'] ) : '',
			'fromVersion'    => isset( $status['fromVersion'] ) ? sanitize_text_field( (string) $status['fromVersion'] ) : '',
			'targetVersion'  => isset( $status['targetVersion'] ) ? sanitize_text_field( (string) $status['targetVersion'] ) : '',
			'startedAt'      => isset( $status['startedAt'] ) ? sanitize_text_field( (string) $status['startedAt'] ) : '',
			'completedAt'    => isset( $status['completedAt'] ) ? sanitize_text_field( (string) $status['completedAt'] ) : '',
			'lastError'      => isset( $status['lastError'] ) ? sanitize_text_field( (string) $status['lastError'] ) : '',
			'rollbackReady'  => is_dir( self::backup_plugin_dir() ),
			'upgradeBarrier' => class_exists( 'Mobo_Core_Upgrade_Coordinator' ) ? Mobo_Core_Upgrade_Coordinator::get_status() : array( 'active' => false, 'status' => 'unavailable' ),
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
		$download_token = isset( $params['downloadToken'] ) ? trim( (string) $params['downloadToken'] ) : '';
		$issued_at = isset( $params['issuedAt'] ) ? absint( $params['issuedAt'] ) : 0;
		$request_signature = isset( $params['requestSignature'] ) ? strtolower( trim( (string) $params['requestSignature'] ) ) : '';

		if ( '' === $deployment_id || '' === $target_version || '' === $package_url || ! preg_match( '/^[a-f0-9]{64}$/', $package_sha256 ) ) {
			return new WP_Error( 'mobo_core_upgrade_missing_fields', 'deploymentId, targetVersion, packageUrl and packageSha256 are required.', array( 'status' => 400 ) );
		}

		if ( strlen( $download_token ) < 32 || strlen( $download_token ) > 512 ) {
			return new WP_Error( 'mobo_core_upgrade_invalid_token', 'Package download token is invalid.', array( 'status' => 400 ) );
		}

		if ( ! self::verify_portal_signature( $deployment_id, $expected_current, $target_version, $package_url, $package_sha256, $download_token, $issued_at, $request_signature ) ) {
			return new WP_Error( 'mobo_core_upgrade_signature_invalid', 'Portal deployment signature is invalid or expired.', array( 'status' => 401 ) );
		}

		if ( ! self::is_trusted_package_url( $package_url ) ) {
			return new WP_Error( 'mobo_core_upgrade_untrusted_url', 'Package URL does not match the secure Portal package endpoint policy.', array( 'status' => 400 ) );
		}

		$current_version = defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '';
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

		if ( version_compare( $target_version, $current_version, '<=' ) ) {
			return new WP_Error(
				'mobo_core_upgrade_not_newer',
				'Target version must be newer than the installed version.',
				array( 'status' => 409, 'currentVersion' => $current_version )
			);
		}

		$lock = Mobo_Core_Lock::acquire( 'remote_plugin_upgrade', 900 );
		if ( false === $lock ) {
			return new WP_Error( 'mobo_core_upgrade_locked', 'Another plugin upgrade is already running.', array( 'status' => 423, 'retryAfter' => 60 ) );
		}

		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 360 );
		}

		$tmp_file = '';
		$staging_dir = '';
		$backup_created = false;
		$destination_touched = false;
		$barrier_token = false;
		$barrier_activated = false;

		self::write_status(
			array(
				'status'        => 'downloading',
				'deploymentId'  => $deployment_id,
				'fromVersion'   => $current_version,
				'targetVersion' => $target_version,
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
			$validation = self::validate_package( $tmp_file, $target_version, $deployment_id );
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

			$drain_timeout = class_exists( 'Mobo_Core_Settings' )
				? Mobo_Core_Settings::get_int( 'mobo_core_upgrade_drain_timeout_seconds', 120, 15, 300 )
				: 120;
			$drained = Mobo_Core_Upgrade_Coordinator::wait_for_quiescence( $barrier_token, $drain_timeout, 900 );
			if ( is_wp_error( $drained ) ) {
				$data = $drained->get_error_data();
				$data = is_array( $data ) ? $data : array();
				$blocked = array(
					'status'        => 'blocked-site-busy',
					'deploymentId'  => $deployment_id,
					'fromVersion'   => $current_version,
					'targetVersion' => $target_version,
					'startedAt'     => self::current_status_value( 'startedAt' ),
					'completedAt'   => gmdate( 'c' ),
					'lastError'     => sanitize_text_field( $drained->get_error_message() ),
				);
				self::write_status( $blocked );
				return new WP_Error(
					$drained->get_error_code(),
					$drained->get_error_message(),
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

			if ( ! Mobo_Core_Lock::renew( 'remote_plugin_upgrade', $lock, 900 ) || ! Mobo_Core_Upgrade_Coordinator::renew( $barrier_token, 900 ) ) {
				return new WP_Error( 'mobo_core_upgrade_lease_lost', 'Upgrade lease was lost before filesystem replacement.', array( 'status' => 409, 'retryAfter' => 60 ) );
			}

			self::update_status_stage( 'backing-up' );
			$backup = self::create_backup();
			if ( is_wp_error( $backup ) ) {
				throw new RuntimeException( $backup->get_error_message() );
			}
			$backup_created = true;

			self::update_status_stage( 'installing' );
			$destination_touched = true;
			$installed = self::install_package( $tmp_file );
			if ( is_wp_error( $installed ) ) {
				throw new RuntimeException( $installed->get_error_message() );
			}

			$disk_version = self::read_disk_plugin_version();
			if ( ! hash_equals( $target_version, $disk_version ) ) {
				throw new RuntimeException( 'Installed files do not report the requested target version.' );
			}

			$completed = array(
				'status'        => 'completed',
				'deploymentId'  => $deployment_id,
				'fromVersion'   => $current_version,
				'targetVersion' => $target_version,
				'startedAt'     => self::current_status_value( 'startedAt' ),
				'completedAt'   => gmdate( 'c' ),
				'lastError'     => '',
			);
			self::write_status( $completed );
			self::append_history( $completed );

			return array(
				'success'          => true,
				'status'           => 'completed',
				'deploymentId'     => $deployment_id,
				'previousVersion'  => $current_version,
				'installedVersion' => $disk_version,
				'restartRequired'  => false,
				'queuesResumable'  => true,
			);
		} catch ( Throwable $e ) {
			$rollback_error = '';
			if ( $backup_created && $destination_touched ) {
				$rollback = self::restore_backup();
				if ( is_wp_error( $rollback ) ) {
					$rollback_error = ' Rollback failed: ' . $rollback->get_error_message();
				}
			}

			$message = sanitize_text_field( $e->getMessage() . $rollback_error );
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
				@unlink( $tmp_file );
			}
			if ( '' !== $staging_dir && is_dir( $staging_dir ) ) {
				self::delete_tree( $staging_dir );
			}
			if ( $barrier_activated && false !== $barrier_token && class_exists( 'Mobo_Core_Upgrade_Coordinator' ) ) {
				Mobo_Core_Upgrade_Coordinator::release( $barrier_token );
			}
			Mobo_Core_Lock::release( 'remote_plugin_upgrade', $lock );

			/* Resume preserved queue/sync cursors after the barrier is gone. */
			if ( $barrier_activated && class_exists( 'Mobo_Core_Self_Runner' ) ) {
				try {
					Mobo_Core_Self_Runner::kick( 'plugin-upgrade-release', true );
				} catch ( Throwable $ignored ) {
					// The next real cron/heartbeat will resume work.
				}
			}
		}
	}

	private static function download_package( $url, $token ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = wp_tempnam( 'mobo-core-update.zip' );
		if ( ! $tmp ) {
			return new WP_Error( 'mobo_core_upgrade_temp_failed', 'Could not create a temporary package file.' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 90,
				'redirection'         => 3,
				'sslverify'           => true,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => self::MAX_PACKAGE_BYTES,
				'headers'             => array(
					'Accept'                  => 'application/zip, application/octet-stream',
					'X-Mobo-Package-Token'    => $token,
					'Cache-Control'            => 'no-store',
					'X-Mobo-Plugin-Version'    => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			@unlink( $tmp );
			return new WP_Error( 'mobo_core_upgrade_download_http', 'Portal package endpoint returned HTTP ' . $code . '.' );
		}

		$size = is_file( $tmp ) ? filesize( $tmp ) : 0;
		if ( ! $size || $size > self::MAX_PACKAGE_BYTES ) {
			@unlink( $tmp );
			return new WP_Error( 'mobo_core_upgrade_package_size', 'Downloaded package is empty or exceeds 50 MiB.' );
		}

		return $tmp;
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

	private static function validate_package( $zip_file, $target_version, $deployment_id ) {
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

		return array( 'stagingDir' => $staging, 'version' => $version );
	}

	private static function validate_manifest( $staging, $expected_version ) {
		$plugin_root = trailingslashit( $staging ) . 'mobo-core';
		$manifest_file = trailingslashit( $plugin_root ) . 'mobo-core-manifest.json';
		$raw = @file_get_contents( $manifest_file );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return new WP_Error( 'mobo_core_upgrade_manifest_missing', 'Plugin package manifest could not be read.' );
		}

		$manifest = json_decode( $raw, true );
		if ( ! is_array( $manifest ) ||
			empty( $manifest['files'] ) ||
			! is_array( $manifest['files'] ) ||
			'sha256' !== strtolower( isset( $manifest['algorithm'] ) ? (string) $manifest['algorithm'] : '' ) ||
			! hash_equals( $expected_version, self::clean_version( isset( $manifest['version'] ) ? $manifest['version'] : '' ) ) ) {
			return new WP_Error( 'mobo_core_upgrade_manifest_invalid', 'Plugin package manifest is invalid or does not match targetVersion.' );
		}

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
			$real_root = realpath( $plugin_root );
			$real_file = realpath( $file );

			if ( false !== $real_root ) {
				$real_root = trailingslashit( wp_normalize_path( $real_root ) );
			}

			if ( false !== $real_file ) {
				$real_file = wp_normalize_path( $real_file );
			}

			if (
				false === $real_root ||
				false === $real_file ||
				0 !== strpos( $real_file, $real_root ) ||
				! is_file( $real_file )
			) {
				return new WP_Error( 'mobo_core_upgrade_manifest_file_missing', 'Plugin manifest references a missing file: ' . sanitize_text_field( $relative ) );
			}

			$actual_hash = hash_file( 'sha256', $real_file );
			if ( ! is_string( $actual_hash ) || ! hash_equals( $expected_hash, strtolower( $actual_hash ) ) ) {
				return new WP_Error( 'mobo_core_upgrade_manifest_hash_mismatch', 'Plugin file hash does not match manifest: ' . sanitize_text_field( $relative ) );
			}
			$tracked[ $relative ] = true;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $plugin_root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iterator as $file_info ) {
			if ( ! $file_info->isFile() || $file_info->isLink() ) {
				continue;
			}
			$absolute = str_replace( '\\', '/', $file_info->getPathname() );
			$root = trailingslashit( str_replace( '\\', '/', $plugin_root ) );
			$relative = ltrim( substr( $absolute, strlen( $root ) ), '/' );
			if ( 'mobo-core-manifest.json' === $relative ) {
				continue;
			}
			if ( ! isset( $tracked[ $relative ] ) ) {
				return new WP_Error( 'mobo_core_upgrade_manifest_untracked_file', 'Plugin package contains an untracked file: ' . sanitize_text_field( $relative ) );
			}
		}

		return true;
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

	private static function create_backup() {
		$source = untrailingslashit( MOBO_CORE_PLUGIN_DIR );
		$backup_root = self::backup_root();
		$backup = self::backup_plugin_dir();

		self::delete_tree( $backup_root );
		if ( ! wp_mkdir_p( $backup_root ) ) {
			return new WP_Error( 'mobo_core_upgrade_backup_dir', 'Could not create plugin backup directory.' );
		}
		if ( ! self::copy_tree( $source, $backup ) ) {
			self::delete_tree( $backup_root );
			return new WP_Error( 'mobo_core_upgrade_backup_failed', 'Could not create a complete local plugin backup.' );
		}

		return true;
	}

	private static function restore_backup() {
		$backup = self::backup_plugin_dir();
		$destination = untrailingslashit( MOBO_CORE_PLUGIN_DIR );
		if ( ! is_dir( $backup ) ) {
			return new WP_Error( 'mobo_core_upgrade_backup_missing', 'Local rollback backup is missing.' );
		}

		self::delete_tree( $destination );
		if ( ! self::copy_tree( $backup, $destination ) ) {
			return new WP_Error( 'mobo_core_upgrade_restore_failed', 'Could not restore the previous plugin files.' );
		}
		return true;
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
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		$items = scandir( $path );
		if ( false !== $items ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				self::delete_tree( $path . DIRECTORY_SEPARATOR . $item );
			}
		}
		@rmdir( $path );
	}

	private static function verify_portal_signature( $deployment_id, $expected_current, $target_version, $package_url, $package_sha256, $download_token, $issued_at, $provided_signature ) {
		if ( $issued_at <= 0 || abs( time() - $issued_at ) > 300 || ! preg_match( '/^[a-f0-9]{64}$/', $provided_signature ) ) {
			return false;
		}

		$security_code = Mobo_Core_Settings::normalize_security_code( get_option( 'mobo_core_security_code', '' ) );
		if ( '' === $security_code ) {
			return false;
		}

		$canonical = implode(
			"\n",
			array(
				$deployment_id,
				$expected_current,
				$target_version,
				$package_url,
				$package_sha256,
				$download_token,
				(string) $issued_at,
			)
		);
		$expected_signature = hash_hmac( 'sha256', $canonical, $security_code );
		return hash_equals( $expected_signature, $provided_signature );
	}

	private static function is_trusted_package_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || empty( $parts['path'] ) ) {
			return false;
		}
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return false;
		}
		if ( false === strpos( (string) $parts['path'], '/api/plugin-packages/' ) ) {
			return false;
		}

		$configured_hosts = get_option( 'mobo_core_remote_update_allowed_hosts', '' );
		$allowed = is_string( $configured_hosts ) ? preg_split( '/[\s,;]+/', $configured_hosts ) : array();
		$allowed = apply_filters( 'mobo_core_remote_update_allowed_hosts', is_array( $allowed ) ? $allowed : array() );
		$allowed = array_values( array_unique( array_filter( array_map( 'strtolower', is_array( $allowed ) ? $allowed : array() ) ) ) );

		if ( empty( $allowed ) ) {
			return true;
		}

		return in_array( strtolower( (string) $parts['host'] ), $allowed, true );
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

	private static function backup_root() {
		return trailingslashit( MOBO_CORE_DATA_DIR ) . 'upgrade-backups/latest';
	}

	private static function backup_plugin_dir() {
		return trailingslashit( self::backup_root() ) . 'mobo-core';
	}
}
