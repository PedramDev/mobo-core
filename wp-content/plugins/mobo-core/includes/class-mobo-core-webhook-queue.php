<?php
/**
 * Production-ready file-based webhook queue.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Webhook_Queue {

	/**
	 * Store payload as queue envelope.
	 *
	 * @param array $payload Payload.
	 * @return string|WP_Error
	 */
	public function store( $payload ) {
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'mobo_core_invalid_payload', 'Invalid webhook payload.' );
		}

		$this->ensure_directories();

		$event   = isset( $payload['event'] ) ? sanitize_key( $payload['event'] ) : 'event';
		$sync_id = isset( $payload['syncId'] ) ? sanitize_text_field( (string) $payload['syncId'] ) : '';

		$envelope = array(
			'id'        => wp_generate_uuid4(),
			'event'     => $event,
			'syncId'    => $sync_id,
			'createdAt' => time(),
			'expiredAt' => time() + ( DAY_IN_SECONDS * Mobo_Core_Settings::get_int( 'mobo_core_webhook_expire_days', 2, 1, 30 ) ),
			'try'       => 0,
			'maxTry'    => Mobo_Core_Settings::get_int( 'mobo_core_webhook_max_try', 5, 1, 20 ),
			'lastError' => '',
			'payload'   => $payload,
		);

		$file_name = gmdate( 'Y-m-d_H-i-s' ) . '--' . time() . '--' . $event . '--' . wp_generate_password( 8, false, false ) . '.json';
		$path      = trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . $file_name;

		if ( false === $this->write_json( $path, $envelope ) ) {
			return new WP_Error( 'mobo_core_queue_write_failed', 'Could not write webhook file.' );
		}

		return $path;
	}

	/**
	 * Process queue.
	 *
	 * @return array
	 */
	public function process() {
		$time_budget = Mobo_Core_Settings::get_int( 'mobo_core_sync_time_budget_seconds', 8, 2, 25 );
		$max_files   = Mobo_Core_Settings::get_int( 'mobo_core_webhook_files_per_run', 1, 1, 10 );
		$lock        = Mobo_Core_Lock::acquire( 'webhook_queue', $time_budget + 15 );

		if ( false === $lock ) {
			return array(
				'success' => false,
				'status'  => 'locked',
				'message' => 'Webhook queue is locked.',
			);
		}

		$started   = microtime( true );
		$processed = 0;
		$failed    = 0;
		$messages  = array();

		try {
			while ( $processed < $max_files ) {
				if ( ( microtime( true ) - $started ) >= $time_budget ) {
					$messages[] = 'Time budget reached.';
					break;
				}

				$file = $this->first_file();

				if ( '' === $file ) {
					$messages[] = 'No webhook files.';
					break;
				}

				$result = $this->process_file( $file );

				if ( ! empty( $result['success'] ) ) {
					$processed++;
					$messages[] = $result['message'];

					$delete_file = true;

					if ( isset( $result['data']['deleteFile'] ) ) {
						$delete_file = (bool) $result['data']['deleteFile'];
					}

					if ( $delete_file && file_exists( $file ) ) {
						unlink( $file );
					}

					if ( ! $delete_file ) {
						break;
					}

					continue;
				}

				$failed++;
				$messages[] = $result['message'];
				break;
			}
		} finally {
			Mobo_Core_Lock::release( 'webhook_queue', $lock );
		}

		return array(
			'success'       => true,
			'status'        => 'done',
			'processed'     => $processed,
			'failed'        => $failed,
			'timeBudget'    => $time_budget,
			'remainingFile' => '' !== $this->first_file(),
			'messages'      => $messages,
		);
	}

	/**
	 * Process one file.
	 *
	 * @param string $file File path.
	 * @return array
	 */
	private function process_file( $file ) {
		$envelope = $this->read_envelope( $file );

		if ( is_wp_error( $envelope ) ) {
			$this->move_to_failed( $file, $envelope->get_error_message() );

			return array(
				'success' => false,
				'message' => $envelope->get_error_message(),
			);
		}

		if ( time() > absint( $envelope['expiredAt'] ) ) {
			$this->move_to_failed( $file, 'Webhook expired.' );

			return array(
				'success' => false,
				'message' => 'Webhook expired and moved to failed.',
			);
		}

		$payload = isset( $envelope['payload'] ) && is_array( $envelope['payload'] ) ? $envelope['payload'] : array();
		$event   = isset( $payload['event'] ) ? sanitize_text_field( $payload['event'] ) : '';

		$sync = new Mobo_Core_Product_Sync();

		if ( 'ProductUpdated' === $event ) {
			$result = $sync->process_product_updated_payload( $payload );
		} elseif ( 'UpdateVariant' === $event ) {
			$result = $sync->process_update_variant_payload( $payload );
		} else {
			$result = array(
				'success' => false,
				'message' => 'Unsupported event: ' . $event,
				'data'    => array(),
			);
		}

		if ( ! empty( $result['success'] ) ) {
			$delete_file = true;

			if ( isset( $result['data']['deleteFile'] ) ) {
				$delete_file = (bool) $result['data']['deleteFile'];
			}

			if ( ! $delete_file ) {
				$envelope['payload']   = $payload;
				$envelope['lastError'] = '';
				$this->write_json( $file, $envelope );
			}

			return $result;
		}

		$envelope['try']       = absint( $envelope['try'] ) + 1;
		$envelope['lastError'] = sanitize_text_field( $result['message'] );

		if ( absint( $envelope['try'] ) >= absint( $envelope['maxTry'] ) ) {
			$this->write_json( $file, $envelope );
			$this->move_to_failed( $file, $result['message'] );

			return array(
				'success' => false,
				'message' => 'Webhook failed permanently: ' . $result['message'],
			);
		}

		$this->write_json( $file, $envelope );

		return array(
			'success' => false,
			'message' => 'Webhook failed, will retry later: ' . $result['message'],
		);
	}

	/**
	 * Read envelope, supporting old raw payload files too.
	 *
	 * @param string $file File path.
	 * @return array|WP_Error
	 */
	private function read_envelope( $file ) {
		if ( ! is_readable( $file ) ) {
			return new WP_Error( 'mobo_core_file_not_readable', 'Webhook file is not readable.' );
		}

		$raw = file_get_contents( $file );

		if ( false === $raw || '' === trim( $raw ) ) {
			return new WP_Error( 'mobo_core_empty_file', 'Webhook file is empty.' );
		}

		$json = json_decode( $raw, true );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'mobo_core_invalid_json', 'Webhook file is invalid JSON.' );
		}

		if ( isset( $json['payload'] ) && is_array( $json['payload'] ) ) {
			$json['try']       = isset( $json['try'] ) ? absint( $json['try'] ) : 0;
			$json['maxTry']    = isset( $json['maxTry'] ) ? absint( $json['maxTry'] ) : Mobo_Core_Settings::get_int( 'mobo_core_webhook_max_try', 5, 1, 20 );
			$json['expiredAt'] = isset( $json['expiredAt'] ) ? absint( $json['expiredAt'] ) : time() + DAY_IN_SECONDS;

			return $json;
		}

		$event = isset( $json['event'] ) ? sanitize_key( $json['event'] ) : 'event';

		return array(
			'id'        => wp_generate_uuid4(),
			'event'     => $event,
			'syncId'    => isset( $json['syncId'] ) ? sanitize_text_field( (string) $json['syncId'] ) : '',
			'createdAt' => file_exists( $file ) ? filemtime( $file ) : time(),
			'expiredAt' => time() + ( DAY_IN_SECONDS * Mobo_Core_Settings::get_int( 'mobo_core_webhook_expire_days', 2, 1, 30 ) ),
			'try'       => 0,
			'maxTry'    => Mobo_Core_Settings::get_int( 'mobo_core_webhook_max_try', 5, 1, 20 ),
			'lastError' => '',
			'payload'   => $json,
		);
	}

	private function first_file() {
		$this->ensure_directories();

		$files = glob( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . '*.json' );

		if ( empty( $files ) || ! is_array( $files ) ) {
			return '';
		}

		usort(
			$files,
			function ( $a, $b ) {
				$a_time = file_exists( $a ) ? filemtime( $a ) : 0;
				$b_time = file_exists( $b ) ? filemtime( $b ) : 0;

				if ( $a_time === $b_time ) {
					return strcmp( $a, $b );
				}

				return $a_time <=> $b_time;
			}
		);

		return isset( $files[0] ) ? $files[0] : '';
	}

	private function move_to_failed( $file, $reason ) {
		$this->ensure_directories();

		$failed_dir = trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/';
		$target     = $failed_dir . basename( $file );

		if ( file_exists( $file ) ) {
			@rename( $file, $target );
		}

		$reason_file = $target . '.error.txt';
		file_put_contents( $reason_file, sanitize_textarea_field( (string) $reason ), LOCK_EX );
	}

	private function write_json( $path, $data ) {
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return false;
		}

		return false !== file_put_contents( $path, $json, LOCK_EX );
	}

	private function ensure_directories() {
		if ( ! is_dir( MOBO_CORE_WEBHOOK_FILE_DIR ) ) {
			wp_mkdir_p( MOBO_CORE_WEBHOOK_FILE_DIR );
		}

		$failed = trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/';

		if ( ! is_dir( $failed ) ) {
			wp_mkdir_p( $failed );
		}

		$this->protect_dir( MOBO_CORE_WEBHOOK_FILE_DIR );
		$this->protect_dir( $failed );
	}

	private function protect_dir( $dir ) {
		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n" );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}
	}
}