<?php
/**
 * Secure Portal-controlled self updater for Mobo Core.
 *
 * Commands are authenticated twice:
 * - the REST/webhook request must contain the configured X-SEC value;
 * - persisted command metadata must carry an HMAC-SHA256 signature made with
 *   the same secret, so a modified pending option cannot change package URL,
 *   version, expiry or hash.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Auto_Updater {

	const OPTION_PENDING = 'mobo_core_remote_update_pending';
	const OPTION_STATUS  = 'mobo_core_remote_update_status';
	const OPTION_ACK     = 'mobo_core_remote_update_pending_ack';
	const LOCK_NAME      = 'remote_plugin_update';
	const MAX_PACKAGE_BYTES = 52428800; // 50 MiB.

	/**
	 * Register lightweight execution hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run_on_admin_request' ), 1 );
	}

	/**
	 * Whether Portal-controlled updates are enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( defined( 'MOBO_CORE_REMOTE_UPDATES_ENABLED' ) ) {
			return (bool) MOBO_CORE_REMOTE_UPDATES_ENABLED;
		}

		return true;
	}

	/**
	 * Accept and persist a signed update command.
	 *
	 * @param array $command Command data.
	 * @return array|WP_Error
	 */
	public static function accept_command( $command ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'mobo_core_remote_update_disabled', 'Remote plugin updates are disabled by wp-config.php.' );
		}

		$normalized = self::validate_command( $command );
		if ( is_wp_error( $normalized ) ) {
			self::save_status(
				array(
					'status'  => 'rejected',
					'success' => false,
					'error'   => $normalized->get_error_message(),
				)
			);
			return $normalized;
		}

		$current_pending = get_option( self::OPTION_PENDING, array() );
		if ( is_array( $current_pending ) && ! empty( $current_pending['deploymentId'] ) ) {
			$current_id = sanitize_text_field( (string) $current_pending['deploymentId'] );
			if ( $current_id === $normalized['deploymentId'] ) {
				return array(
					'success'       => true,
					'status'        => 'already-accepted',
					'deploymentId'  => $normalized['deploymentId'],
					'targetVersion' => $normalized['targetVersion'],
				);
			}

			return new WP_Error(
				'mobo_update_another_pending',
				'Another plugin update deployment is already pending and must finish before a new command is accepted.'
			);
		}

		update_option( self::OPTION_PENDING, $normalized, false );
		self::save_status(
			array(
				'success'       => true,
				'status'        => 'queued',
				'deploymentId'  => $normalized['deploymentId'],
				'fromVersion'   => self::installed_version(),
				'targetVersion' => $normalized['targetVersion'],
				'queuedAt'      => time(),
			)
		);

		self::send_ack( $normalized, 'queued', true, '' );
		self::kick();

		return array(
			'success'       => true,
			'status'        => 'accepted',
			'deploymentId'  => $normalized['deploymentId'],
			'targetVersion' => $normalized['targetVersion'],
		);
	}

	/**
	 * Run pending update on an authenticated direct endpoint or real Cron.
	 *
	 * @param string $source Execution source.
	 * @return array
	 */
	public static function run_pending( $source = 'remote-update' ) {
		if ( ! self::is_enabled() ) {
			return self::result( false, 'disabled', 'Remote plugin updates are disabled.' );
		}

		$pending = get_option( self::OPTION_PENDING, array() );
		if ( ! is_array( $pending ) || empty( $pending['deploymentId'] ) ) {
			return self::result( true, 'idle', 'No pending plugin update command.' );
		}

		$validated = self::validate_command( $pending, true );
		if ( is_wp_error( $validated ) ) {
			delete_option( self::OPTION_PENDING );
			$result = self::result( false, 'rejected', $validated->get_error_message(), $pending );
			self::save_status( $result );
			self::send_ack( $pending, 'rejected', false, $validated->get_error_message() );
			return $result;
		}
		$pending = $validated;

		/*
		 * The package may have been installed successfully immediately before PHP
		 * terminated (for example during process restart). Treat an already matching
		 * installed version as recovered success only after the persisted command has
		 * passed its HMAC, host, hash and expiry validation.
		 */
		if ( 0 === version_compare( self::installed_version(), $pending['targetVersion'] ) ) {
			delete_option( self::OPTION_PENDING );
			$result = array(
				'success'          => true,
				'status'           => 'succeeded',
				'deploymentId'     => $pending['deploymentId'],
				'fromVersion'      => isset( $pending['fromVersion'] ) ? $pending['fromVersion'] : '',
				'targetVersion'    => $pending['targetVersion'],
				'installedVersion' => self::installed_version(),
				'recovered'        => true,
				'completedAt'      => time(),
			);
			self::save_status( $result );
			self::send_ack( $pending, 'succeeded', true, '' );
			return $result;
		}

		$lock = Mobo_Core_Lock::acquire( self::LOCK_NAME, 600 );
		if ( false === $lock ) {
			return self::result( false, 'locked', 'Another plugin update process is running.', $pending );
		}

		$from_version = self::installed_version();
		$temp_file    = '';
		$backup_dir   = '';

		try {
			self::save_status(
				array(
					'success'       => true,
					'status'        => 'downloading',
					'deploymentId'  => $pending['deploymentId'],
					'fromVersion'   => $from_version,
					'targetVersion' => $pending['targetVersion'],
					'source'        => sanitize_key( (string) $source ),
					'startedAt'     => time(),
				)
			);
			self::send_ack( $pending, 'downloading', true, '' );

			$temp_file = self::download_package( $pending );
			if ( is_wp_error( $temp_file ) ) {
				throw new RuntimeException( $temp_file->get_error_message() );
			}

			$verification = self::verify_package( $temp_file, $pending );
			if ( is_wp_error( $verification ) ) {
				throw new RuntimeException( $verification->get_error_message() );
			}

			self::save_status(
				array(
					'success'       => true,
					'status'        => 'installing',
					'deploymentId'  => $pending['deploymentId'],
					'fromVersion'   => $from_version,
					'targetVersion' => $pending['targetVersion'],
					'startedAt'     => time(),
				)
			);
			self::send_ack( $pending, 'installing', true, '' );

			$backup_dir = self::create_backup( $pending['deploymentId'] );
			if ( is_wp_error( $backup_dir ) ) {
				throw new RuntimeException( $backup_dir->get_error_message() );
			}

			$installed = self::install_package( $temp_file );
			if ( is_wp_error( $installed ) ) {
				throw new RuntimeException( $installed->get_error_message() );
			}

			$installed_version = self::read_version_from_file( WP_PLUGIN_DIR . '/mobo-core/mobo-core.php' );
			if ( $installed_version !== $pending['targetVersion'] ) {
				throw new RuntimeException( 'Installed plugin version does not match the requested target version.' );
			}

			delete_option( self::OPTION_PENDING );
			self::cleanup_backup( $backup_dir );

			$result = array(
				'success'       => true,
				'status'        => 'succeeded',
				'deploymentId'  => $pending['deploymentId'],
				'fromVersion'   => $from_version,
				'targetVersion' => $pending['targetVersion'],
				'installedVersion' => $installed_version,
				'completedAt'   => time(),
			);
			self::save_status( $result );
			self::send_ack( $pending, 'succeeded', true, '' );
			return $result;
		} catch ( Throwable $error ) {
			delete_option( self::OPTION_PENDING );
			$error_message = $error->getMessage();
			$rollback_ok   = null;
			if ( is_string( $backup_dir ) && '' !== $backup_dir && is_dir( $backup_dir ) ) {
				$restore = self::restore_backup( $backup_dir );
				if ( is_wp_error( $restore ) ) {
					$rollback_ok   = false;
					$error_message .= ' Rollback also failed: ' . $restore->get_error_message();
				} else {
					$rollback_ok = true;
					self::cleanup_backup( $backup_dir );
					$backup_dir = '';
				}
			}
			$result = array(
				'success'       => false,
				'status'        => 'failed',
				'deploymentId'  => isset( $pending['deploymentId'] ) ? $pending['deploymentId'] : '',
				'fromVersion'   => $from_version,
				'targetVersion' => isset( $pending['targetVersion'] ) ? $pending['targetVersion'] : '',
				'rollbackSucceeded' => $rollback_ok,
				'backupRetained' => false === $rollback_ok,
				'error'         => sanitize_text_field( $error_message ),
				'completedAt'   => time(),
			);
			self::save_status( $result );
			self::send_ack( $pending, 'failed', false, $error_message );
			return $result;
		} finally {
			if ( is_string( $temp_file ) && '' !== $temp_file && is_file( $temp_file ) ) {
				@unlink( $temp_file );
			}
			Mobo_Core_Lock::release( self::LOCK_NAME, $lock );
		}
	}

	/**
	 * Run on an administrator request when a prior loopback could not execute.
	 *
	 * @return void
	 */
	public static function maybe_run_on_admin_request() {
		self::retry_pending_ack();

		if ( get_option( self::OPTION_PENDING, array() ) ) {
			self::run_pending( 'admin-fallback' );
		}
	}

	/**
	 * Retry a previously failed deployment acknowledgement.
	 *
	 * @return bool
	 */
	public static function retry_pending_ack() {
		$pending_ack = get_option( self::OPTION_ACK, array() );
		if ( ! is_array( $pending_ack ) || empty( $pending_ack['command'] ) || empty( $pending_ack['status'] ) ) {
			return true;
		}

		return self::send_ack_request(
			$pending_ack['command'],
			$pending_ack['status'],
			! empty( $pending_ack['success'] ),
			isset( $pending_ack['error'] ) ? $pending_ack['error'] : ''
		);
	}

	/**
	 * Current public update status without secrets.
	 *
	 * @return array
	 */
	public static function get_status() {
		$status = get_option( self::OPTION_STATUS, array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}

		if ( ! function_exists( 'get_filesystem_method' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$filesystem_method = function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : '';
		$plugin_writable   = is_dir( MOBO_CORE_PLUGIN_DIR ) && is_writable( MOBO_CORE_PLUGIN_DIR ) && is_writable( WP_PLUGIN_DIR );
		$file_mods_allowed = ! defined( 'DISALLOW_FILE_MODS' ) || ! DISALLOW_FILE_MODS;

		return array_merge(
			array(
				'enabled'          => self::is_enabled(),
				'installedVersion' => self::installed_version(),
				'hasPending'       => (bool) get_option( self::OPTION_PENDING, array() ),
				'hasPendingAck'    => (bool) get_option( self::OPTION_ACK, array() ),
				'filesystemMethod' => sanitize_key( (string) $filesystem_method ),
				'pluginWritable'   => $plugin_writable,
				'fileModsAllowed'  => $file_mods_allowed,
				'unattendedReady'  => self::is_enabled() && $file_mods_allowed && 'direct' === $filesystem_method && $plugin_writable,
			),
			$status
		);
	}

	/**
	 * Validate and normalize a command.
	 *
	 * @param array $command Command.
	 * @param bool  $allow_installed_target Allow equality for crash recovery during execution.
	 * @return array|WP_Error
	 */
	private static function validate_command( $command, $allow_installed_target = false ) {
		if ( ! is_array( $command ) ) {
			return new WP_Error( 'mobo_update_invalid_command', 'Update command must be a JSON object.' );
		}

		$data = isset( $command['data'] ) && is_array( $command['data'] ) ? $command['data'] : $command;
		$get  = function ( $camel, $pascal = '' ) use ( $data ) {
			if ( array_key_exists( $camel, $data ) ) {
				return $data[ $camel ];
			}
			if ( '' !== $pascal && array_key_exists( $pascal, $data ) ) {
				return $data[ $pascal ];
			}
			return '';
		};

		$normalized = array(
			'deploymentId' => sanitize_text_field( (string) $get( 'deploymentId', 'DeploymentId' ) ),
			'targetVersion'=> sanitize_text_field( (string) $get( 'targetVersion', 'TargetVersion' ) ),
			'packageUrl'   => esc_url_raw( (string) $get( 'packageUrl', 'PackageUrl' ) ),
			'packageSha256'=> strtolower( sanitize_text_field( (string) $get( 'packageSha256', 'PackageSha256' ) ) ),
			'packageSize'  => absint( $get( 'packageSize', 'PackageSize' ) ),
			'downloadToken'=> sanitize_text_field( (string) $get( 'downloadToken', 'DownloadToken' ) ),
			'expiresAt'    => absint( $get( 'expiresAt', 'ExpiresAt' ) ),
			'signature'    => strtolower( sanitize_text_field( (string) $get( 'signature', 'Signature' ) ) ),
			'ackUrl'       => esc_url_raw( (string) $get( 'ackUrl', 'AckUrl' ) ),
		);

		if ( ! preg_match( '/^[a-f0-9-]{36}$/i', $normalized['deploymentId'] ) ) {
			return new WP_Error( 'mobo_update_invalid_deployment', 'Deployment ID is invalid.' );
		}
		if ( ! preg_match( '/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $normalized['targetVersion'] ) ) {
			return new WP_Error( 'mobo_update_invalid_version', 'Target plugin version is invalid.' );
		}
		$version_operator = $allow_installed_target ? '<' : '<=';
		if ( version_compare( $normalized['targetVersion'], self::installed_version(), $version_operator ) ) {
			return new WP_Error( 'mobo_update_not_newer', 'Target plugin version is not newer than the installed version.' );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $normalized['packageSha256'] ) ) {
			return new WP_Error( 'mobo_update_invalid_hash', 'Package SHA-256 is invalid.' );
		}
		if ( $normalized['packageSize'] <= 0 || $normalized['packageSize'] > self::MAX_PACKAGE_BYTES ) {
			return new WP_Error( 'mobo_update_invalid_size', 'Package size is outside the allowed range.' );
		}
		if ( strlen( $normalized['downloadToken'] ) < 32 ) {
			return new WP_Error( 'mobo_update_invalid_download_token', 'Download token is invalid.' );
		}
		if ( $normalized['expiresAt'] <= time() || $normalized['expiresAt'] > time() + DAY_IN_SECONDS * 2 ) {
			return new WP_Error( 'mobo_update_expired', 'Update command is expired or has an excessive lifetime.' );
		}
		if ( ! self::is_allowed_portal_url( $normalized['packageUrl'] ) || ! self::is_allowed_portal_url( $normalized['ackUrl'] ) ) {
			return new WP_Error( 'mobo_update_untrusted_url', 'Package or ACK URL is not on the configured Portal host.' );
		}

		$security_code = Mobo_Core_Settings::normalize_security_code( get_option( 'mobo_core_security_code', '' ) );
		if ( '' === $security_code ) {
			return new WP_Error( 'mobo_update_security_missing', 'Webhook security code is not configured.' );
		}
		$expected = hash_hmac( 'sha256', self::canonical_command( $normalized ), $security_code );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $normalized['signature'] ) || ! hash_equals( $expected, $normalized['signature'] ) ) {
			return new WP_Error( 'mobo_update_signature_invalid', 'Update command signature is invalid.' );
		}

		return $normalized;
	}

	/**
	 * Canonical HMAC input. Must match Portal implementation.
	 *
	 * @param array $command Command.
	 * @return string
	 */
	private static function canonical_command( $command ) {
		return implode(
			"\n",
			array(
				(string) $command['deploymentId'],
				(string) $command['targetVersion'],
				(string) $command['packageSha256'],
				(string) $command['packageSize'],
				(string) $command['packageUrl'],
				(string) $command['ackUrl'],
				(string) $command['expiresAt'],
				(string) $command['downloadToken'],
			)
		);
	}

	/**
	 * Only trust URLs hosted by the configured Portal/API host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_allowed_portal_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		$base = apply_filters( 'mobo_core_api_base_url', '' );
		if ( '' === trim( (string) $base ) ) {
			$base = (string) Mobo_Core_Settings::get( 'mobo_core_api_base_url', '' );
		}
		$base_parts = wp_parse_url( $base );
		if ( ! is_array( $base_parts ) || empty( $base_parts['host'] ) ) {
			return false;
		}

		return strtolower( (string) $parts['host'] ) === strtolower( (string) $base_parts['host'] );
	}

	/**
	 * Download package to a temporary file with deployment token header.
	 *
	 * @param array $command Command.
	 * @return string|WP_Error
	 */
	private static function download_package( $command ) {
		$temp_file = wp_tempnam( 'mobo-core-update-' . $command['deploymentId'] . '.zip' );
		if ( ! $temp_file ) {
			return new WP_Error( 'mobo_update_temp_failed', 'Could not create a temporary update file.' );
		}

		$response = wp_safe_remote_get(
			$command['packageUrl'],
			array(
				'timeout'     => 120,
				'redirection' => 2,
				'stream'      => true,
				'filename'    => $temp_file,
				'headers'     => array(
					'X-Mobo-Update-Token' => $command['downloadToken'],
					'Accept'               => 'application/zip',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $temp_file );
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			@unlink( $temp_file );
			return new WP_Error( 'mobo_update_download_http', 'Portal package download returned HTTP ' . absint( $code ) . '.' );
		}
		if ( ! is_file( $temp_file ) ) {
			return new WP_Error( 'mobo_update_download_missing', 'Downloaded package file is missing.' );
		}
		$size = @filesize( $temp_file );
		if ( false === $size || (int) $size !== (int) $command['packageSize'] ) {
			@unlink( $temp_file );
			return new WP_Error( 'mobo_update_size_mismatch', 'Downloaded package size does not match Portal metadata.' );
		}
		$hash = @hash_file( 'sha256', $temp_file );
		if ( ! is_string( $hash ) || ! hash_equals( $command['packageSha256'], strtolower( $hash ) ) ) {
			@unlink( $temp_file );
			return new WP_Error( 'mobo_update_hash_mismatch', 'Downloaded package SHA-256 does not match Portal metadata.' );
		}

		return $temp_file;
	}

	/**
	 * Verify ZIP root, main file version and internal manifest before replacing code.
	 *
	 * @param string $zip_file ZIP path.
	 * @param array  $command Command.
	 * @return true|WP_Error
	 */
	private static function verify_package( $zip_file, $command ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$stage = trailingslashit( get_temp_dir() ) . 'mobo-core-verify-' . wp_generate_password( 12, false, false );
		wp_mkdir_p( $stage );
		$result = unzip_file( $zip_file, $stage );
		if ( is_wp_error( $result ) ) {
			self::delete_tree( $stage );
			return $result;
		}

		$stage_items = array_values( array_diff( (array) @scandir( $stage ), array( '.', '..' ) ) );
		if ( 1 !== count( $stage_items ) || 'mobo-core' !== $stage_items[0] || ! is_dir( trailingslashit( $stage ) . 'mobo-core' ) || is_link( trailingslashit( $stage ) . 'mobo-core' ) ) {
			self::delete_tree( $stage );
			return new WP_Error( 'mobo_update_invalid_root', 'ZIP must contain exactly one top-level mobo-core directory.' );
		}

		$root      = trailingslashit( $stage ) . 'mobo-core/';
		$main_file = $root . 'mobo-core.php';
		if ( ! is_file( $main_file ) || ! is_file( $root . 'mobo-core-manifest.json' ) ) {
			self::delete_tree( $stage );
			return new WP_Error( 'mobo_update_invalid_structure', 'ZIP must contain mobo-core/mobo-core.php and the internal manifest.' );
		}

		$version = self::read_version_from_file( $main_file );
		if ( $version !== $command['targetVersion'] ) {
			self::delete_tree( $stage );
			return new WP_Error( 'mobo_update_version_mismatch', 'ZIP plugin version does not match target version.' );
		}

		$manifest_check = self::verify_internal_manifest( $root );
		self::delete_tree( $stage );
		return $manifest_check;
	}

	/**
	 * Verify internal SHA-256 manifest.
	 *
	 * @param string $root Plugin root.
	 * @return true|WP_Error
	 */
	private static function verify_internal_manifest( $root ) {
		$root = trailingslashit( $root );
		$raw  = @file_get_contents( $root . 'mobo-core-manifest.json' );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$files = is_array( $data ) && isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : array();
		if ( empty( $files ) ) {
			return new WP_Error( 'mobo_update_manifest_invalid', 'Internal plugin manifest is missing or invalid.' );
		}

		$manifest_paths = array();
		foreach ( $files as $relative => $expected ) {
			$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
			if ( '' === $relative || false !== strpos( $relative, '../' ) || false !== strpos( $relative, "\0" ) || 'mobo-core-manifest.json' === $relative ) {
				return new WP_Error( 'mobo_update_manifest_path_invalid', 'Internal manifest contains an unsafe path.' );
			}
			if ( isset( $manifest_paths[ $relative ] ) ) {
				return new WP_Error( 'mobo_update_manifest_duplicate', 'Internal manifest contains a duplicate path: ' . $relative );
			}
			if ( ! preg_match( '/^[a-f0-9]{64}$/i', (string) $expected ) ) {
				return new WP_Error( 'mobo_update_manifest_hash_invalid', 'Internal manifest contains an invalid SHA-256: ' . $relative );
			}
			$manifest_paths[ $relative ] = strtolower( (string) $expected );
		}

		$actual_paths = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iterator as $file_info ) {
			$path = $file_info->getPathname();
			if ( is_link( $path ) ) {
				return new WP_Error( 'mobo_update_symlink_rejected', 'Plugin ZIP may not contain symbolic links.' );
			}
			if ( ! $file_info->isFile() ) {
				continue;
			}
			$relative = ltrim( str_replace( '\\', '/', substr( $path, strlen( $root ) ) ), '/' );
			if ( 'mobo-core-manifest.json' === $relative ) {
				continue;
			}
			$actual_paths[ $relative ] = $path;
		}

		$manifest_names = array_keys( $manifest_paths );
		$actual_names   = array_keys( $actual_paths );
		sort( $manifest_names, SORT_STRING );
		sort( $actual_names, SORT_STRING );
		if ( $manifest_names !== $actual_names ) {
			$missing = array_values( array_diff( $manifest_names, $actual_names ) );
			$extra   = array_values( array_diff( $actual_names, $manifest_names ) );
			return new WP_Error(
				'mobo_update_manifest_coverage_mismatch',
				'ZIP files do not exactly match the internal manifest. Missing: ' . implode( ', ', array_slice( $missing, 0, 10 ) ) . '; extra: ' . implode( ', ', array_slice( $extra, 0, 10 ) )
			);
		}

		foreach ( $manifest_paths as $relative => $expected ) {
			$actual = hash_file( 'sha256', $actual_paths[ $relative ] );
			if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
				return new WP_Error( 'mobo_update_manifest_hash_mismatch', 'Internal manifest hash mismatch: ' . $relative );
			}
		}

		return true;
	}

	/**
	 * Create a rollback copy outside the plugin directory.
	 *
	 * @param string $deployment_id Deployment UUID.
	 * @return string|WP_Error
	 */
	private static function create_backup( $deployment_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'mobo_update_filesystem_unavailable', 'WordPress filesystem access is unavailable without credentials.' );
		}

		$base = trailingslashit( get_temp_dir() ) . 'mobo-core-update-backup-' . sanitize_file_name( $deployment_id ) . '/';
		self::delete_tree( $base );
		wp_mkdir_p( $base );
		if ( is_dir( $base ) ) {
			@chmod( $base, 0700 );
		}
		$copied = copy_dir( MOBO_CORE_PLUGIN_DIR, $base . 'mobo-core/' );
		if ( is_wp_error( $copied ) ) {
			self::delete_tree( $base );
			return $copied;
		}
		return $base;
	}

	/**
	 * Install local verified ZIP using WordPress upgrader APIs.
	 *
	 * @param string $zip_file ZIP path.
	 * @return true|WP_Error
	 */
	private static function install_package( $zip_file ) {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return new WP_Error( 'mobo_update_file_mods_disabled', 'WordPress file modifications are disabled by DISALLOW_FILE_MODS.' );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'mobo_update_filesystem_credentials', 'WordPress filesystem method requires credentials; unattended update is not possible on this host.' );
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->run(
			array(
				'package'           => $zip_file,
				'destination'       => WP_PLUGIN_DIR,
				'clear_destination' => true,
				'clear_working'     => true,
				'abort_if_destination_exists' => false,
				'hook_extra'        => array(
					'plugin' => 'mobo-core/mobo-core.php',
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
			return new WP_Error( 'mobo_update_install_failed', 'WordPress upgrader returned failure.' );
		}

		wp_clean_plugins_cache( true );
		return true;
	}

	/**
	 * Restore rollback copy.
	 *
	 * @param string $backup_dir Backup root.
	 * @return true|WP_Error
	 */
	private static function restore_backup( $backup_dir ) {
		$source = trailingslashit( $backup_dir ) . 'mobo-core/';
		if ( ! is_dir( $source ) ) {
			return new WP_Error( 'mobo_update_backup_missing', 'Rollback backup is missing.' );
		}
		self::delete_tree( WP_PLUGIN_DIR . '/mobo-core' );
		wp_mkdir_p( WP_PLUGIN_DIR . '/mobo-core' );
		$copied = copy_dir( $source, WP_PLUGIN_DIR . '/mobo-core/' );
		return is_wp_error( $copied ) ? $copied : true;
	}

	/**
	 * Non-blocking authenticated loopback to execute pending update.
	 *
	 * @return void
	 */
	private static function kick() {
		$security_code = Mobo_Core_Settings::normalize_security_code( get_option( 'mobo_core_security_code', '' ) );
		if ( '' === $security_code ) {
			return;
		}
		wp_remote_post(
			rest_url( 'mobo-core/v1/update/run' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'remote_update_loopback' ),
				'headers'   => array( 'X-SEC' => $security_code ),
			)
		);
	}

	/**
	 * Report deployment state back to Portal.
	 *
	 * @param array  $command Command.
	 * @param string $status Status.
	 * @param bool   $success Success.
	 * @param string $error Error.
	 * @return void
	 */
	private static function send_ack( $command, $status, $success, $error ) {
		$ack = array(
			'command' => self::ack_command_subset( $command ),
			'status'  => sanitize_key( (string) $status ),
			'success' => (bool) $success,
			'error'   => sanitize_text_field( (string) $error ),
			'queuedAt'=> time(),
		);
		update_option( self::OPTION_ACK, $ack, false );
		self::send_ack_request( $ack['command'], $ack['status'], $ack['success'], $ack['error'] );
	}

	/**
	 * Send one acknowledgement and keep it queued when Portal is unavailable.
	 *
	 * @param array  $command Command subset.
	 * @param string $status Status.
	 * @param bool   $success Success.
	 * @param string $error Error.
	 * @return bool
	 */
	private static function send_ack_request( $command, $status, $success, $error ) {
		if ( ! is_array( $command ) || empty( $command['ackUrl'] ) || empty( $command['deploymentId'] ) ) {
			delete_option( self::OPTION_ACK );
			return false;
		}

		$security_code = Mobo_Core_Settings::normalize_security_code( get_option( 'mobo_core_security_code', '' ) );
		if ( '' === $security_code || ! self::is_allowed_portal_url( $command['ackUrl'] ) ) {
			return false;
		}

		$status_option = get_option( self::OPTION_STATUS, array() );
		$from_version  = is_array( $status_option ) && ! empty( $status_option['fromVersion'] )
			? sanitize_text_field( (string) $status_option['fromVersion'] )
			: self::installed_version();
		$installed_version = self::read_version_from_file( WP_PLUGIN_DIR . '/mobo-core/mobo-core.php' );

		$response = wp_safe_remote_post(
			$command['ackUrl'],
			array(
				'timeout'   => 15,
				'blocking'  => true,
				'sslverify' => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'remote_update_ack' ),
				'headers'   => array(
					'X-SEC'        => $security_code,
					'Content-Type' => 'application/json',
				),
				'body'      => wp_json_encode(
					array(
						'deploymentId'   => $command['deploymentId'],
						'status'         => sanitize_key( (string) $status ),
						'success'        => (bool) $success,
						'fromVersion'    => $from_version,
						'targetVersion'  => isset( $command['targetVersion'] ) ? $command['targetVersion'] : '',
						'installedVersion'=> $installed_version,
						'error'          => sanitize_text_field( (string) $error ),
						'reportedAt'     => time(),
					)
				),
			)
		);

		$http_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( ! is_wp_error( $response ) && $http_code >= 200 && $http_code < 300 ) {
			delete_option( self::OPTION_ACK );
			$status_option = is_array( $status_option ) ? $status_option : array();
			$status_option['ackPending'] = false;
			$status_option['ackLastSuccessAt'] = time();
			unset( $status_option['ackLastError'] );
			self::save_status( $status_option );
			return true;
		}

		$status_option = is_array( $status_option ) ? $status_option : array();
		$status_option['ackPending'] = true;
		$status_option['ackLastAttemptAt'] = time();
		$status_option['ackLastError'] = is_wp_error( $response )
			? sanitize_text_field( $response->get_error_message() )
			: 'Portal ACK returned HTTP ' . absint( $http_code ) . '.';
		self::save_status( $status_option );
		return false;
	}

	/**
	 * Keep only non-package-secret command fields for ACK retry.
	 *
	 * @param array $command Command.
	 * @return array
	 */
	private static function ack_command_subset( $command ) {
		return array(
			'deploymentId' => is_array( $command ) && isset( $command['deploymentId'] ) ? sanitize_text_field( (string) $command['deploymentId'] ) : '',
			'targetVersion'=> is_array( $command ) && isset( $command['targetVersion'] ) ? sanitize_text_field( (string) $command['targetVersion'] ) : '',
			'ackUrl'       => is_array( $command ) && isset( $command['ackUrl'] ) ? esc_url_raw( (string) $command['ackUrl'] ) : '',
		);
	}

	/** @return string */
	private static function installed_version() {
		return defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : self::read_version_from_file( MOBO_CORE_PLUGIN_FILE );
	}

	/** @param string $path @return string */
	private static function read_version_from_file( $path ) {
		$raw = is_file( $path ) ? @file_get_contents( $path, false, null, 0, 8192 ) : false;
		if ( ! is_string( $raw ) ) {
			return '';
		}
		if ( preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $raw, $match ) ) {
			return trim( $match[1] );
		}
		if ( preg_match( "/define\(\s*'MOBO_CORE_VERSION'\s*,\s*'([^']+)'\s*\)/", $raw, $match ) ) {
			return trim( $match[1] );
		}
		return '';
	}

	/** @param array $status @return void */
	private static function save_status( $status ) {
		$status = is_array( $status ) ? $status : array();
		$status['updatedAt'] = time();
		unset( $status['downloadToken'], $status['signature'], $status['packageUrl'], $status['ackUrl'] );
		update_option( self::OPTION_STATUS, $status, false );
	}

	/** @return array */
	private static function result( $success, $status, $message, $command = array() ) {
		return array(
			'success'       => (bool) $success,
			'status'        => sanitize_key( $status ),
			'message'       => sanitize_text_field( $message ),
			'deploymentId'  => is_array( $command ) && isset( $command['deploymentId'] ) ? sanitize_text_field( (string) $command['deploymentId'] ) : '',
			'targetVersion' => is_array( $command ) && isset( $command['targetVersion'] ) ? sanitize_text_field( (string) $command['targetVersion'] ) : '',
		);
	}

	/** @param string $path @return void */
	private static function cleanup_backup( $path ) {
		if ( is_string( $path ) && '' !== $path ) {
			self::delete_tree( $path );
		}
	}

	/** @param string $path @return void */
	private static function delete_tree( $path ) {
		if ( ! is_string( $path ) || '' === trim( $path ) || ! file_exists( $path ) ) {
			return;
		}
		if ( is_file( $path ) || is_link( $path ) ) {
			@unlink( $path );
			return;
		}
		$items = @scandir( $path );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			self::delete_tree( trailingslashit( $path ) . $item );
		}
		@rmdir( $path );
	}
}
