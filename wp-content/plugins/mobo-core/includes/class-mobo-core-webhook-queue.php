<?php
/**
 * File-based webhook queue.
 *
 * Webhook flow:
 * 1. REST /webhook receives JSON.
 * 2. Payload is stored as a JSON file.
 * 3. Queue processing is best-effort and chunk-safe.
 * 4. Failed/expired files are moved to webhook-files/failed/.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Webhook_Queue {

	/**
	 * Store webhook payload as a file.
	 *
	 * @param array $payload Payload.
	 * @return string|WP_Error
	 */
	public function store( $payload ) {
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'mobo_core_invalid_webhook_payload', 'Invalid webhook payload.' );
		}

		$this->ensure_dirs();

		$event = $this->detect_event( $payload );

		if ( '' === $event ) {
			return new WP_Error( 'mobo_core_missing_webhook_event', 'Webhook event is missing.' );
		}

		$sync_id = $this->get_value( $payload, 'syncId', '' );

		$envelope = array(
			'id'        => wp_generate_uuid4(),
			'event'     => sanitize_text_field( (string) $event ),
			'syncId'    => sanitize_text_field( (string) $sync_id ),
			'try'       => 0,
			'createdAt' => time(),
			'updatedAt' => time(),
			'expiresAt' => time() + ( DAY_IN_SECONDS * Mobo_Core_Settings::get_int( 'mobo_core_webhook_expire_days', 2, 1, 30 ) ),
			'payload'   => $payload,
		);

		$filename = $this->build_filename( $envelope['event'], $envelope['id'] );
		$path     = trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . $filename;

		$json = wp_json_encode( $envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return new WP_Error( 'mobo_core_webhook_encode_failed', 'Could not encode webhook payload.' );
		}

		$written = file_put_contents( $path, $json, LOCK_EX );

		if ( false === $written ) {
			return new WP_Error( 'mobo_core_webhook_write_failed', 'Could not write webhook file.' );
		}

		return $path;
	}

	/**
	 * Process webhook queue.
	 *
	 * @return array
	 */
	public function process() {
		$this->ensure_dirs();

		$lock = Mobo_Core_Lock::acquire( 'webhook_queue', 30 );

		if ( false === $lock ) {
			return array(
				'success'       => false,
				'status'        => 'locked',
				'processed'     => 0,
				'failed'        => 0,
				'remainingFile' => true,
				'messages'      => array( 'صف وب‌هوک در حال پردازش است.' ),
			);
		}

		try {
			$result = $this->process_locked();
		} finally {
			Mobo_Core_Lock::release( 'webhook_queue', $lock );
		}

		return $result;
	}

	/**
	 * Process queue while lock is held.
	 *
	 * @return array
	 */
	private function process_locked() {
		$started_at = time();
		$budget     = Mobo_Core_Settings::get_int( 'mobo_core_sync_time_budget_seconds', 8, 2, 25 );
		$max_files  = Mobo_Core_Settings::get_int( 'mobo_core_webhook_files_per_run', 1, 1, 10 );

		$processed = 0;
		$failed    = 0;
		$messages  = array();

		$files = $this->get_queue_files();

		if ( empty( $files ) ) {
			return array(
				'success'       => true,
				'status'        => 'empty',
				'processed'     => 0,
				'failed'        => 0,
				'remainingFile' => false,
				'messages'      => array( 'صف وب‌هوک خالی است.' ),
			);
		}

		foreach ( $files as $file ) {
			if ( $processed >= $max_files ) {
				break;
			}

			if ( ( time() - $started_at ) >= $budget ) {
				$messages[] = 'بودجه زمانی پردازش صف به پایان رسید.';
				break;
			}

			$item = $this->read_file( $file );

			if ( is_wp_error( $item ) ) {
				$this->move_to_failed( $file, 'invalid-json' );
				$failed++;
				$messages[] = 'یک فایل وب‌هوک نامعتبر به failed منتقل شد.';
				continue;
			}

			$item = $this->normalize_queue_item( $item, $file );

			if ( empty( $item['event'] ) || empty( $item['payload'] ) || ! is_array( $item['payload'] ) ) {
				$this->move_to_failed( $file, 'invalid-envelope' );
				$failed++;
				$messages[] = 'ساختار فایل وب‌هوک نامعتبر بود.';
				continue;
			}

			if ( ! empty( $item['expiresAt'] ) && time() > absint( $item['expiresAt'] ) ) {
				$this->move_to_failed( $file, 'expired' );
				$failed++;
				$messages[] = 'یک وب‌هوک منقضی شد و به failed منتقل شد.';
				continue;
			}

			$result = $this->process_item( $item );

			if ( ! is_array( $result ) ) {
				$result = array(
					'success' => false,
					'message' => 'Invalid processor result.',
					'data'    => array(),
				);
			}

			if ( ! empty( $result['success'] ) ) {
				$data        = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
				$delete_file = array_key_exists( 'deleteFile', $data ) ? (bool) $data['deleteFile'] : true;

				if ( $delete_file ) {
					@unlink( $file );
				} else {
					$item['try']       = absint( $item['try'] );
					$item['updatedAt'] = time();

					if ( isset( $item['payload'] ) && is_array( $item['payload'] ) && isset( $result['payload'] ) && is_array( $result['payload'] ) ) {
						$item['payload'] = $result['payload'];
					}

					$this->write_item( $file, $item );
				}

				$processed++;
				$messages[] = isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : 'وب‌هوک پردازش شد.';
				continue;
			}

			$item['try']       = absint( $item['try'] ) + 1;
			$item['updatedAt'] = time();
			$item['lastError'] = isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : 'Webhook processing failed.';

			$max_try = Mobo_Core_Settings::get_int( 'mobo_core_webhook_max_try', 5, 1, 20 );

			if ( $item['try'] >= $max_try ) {
				$this->write_item( $file, $item );
				$this->move_to_failed( $file, 'max-try' );
				$failed++;
				$messages[] = 'یک وب‌هوک پس از چند تلاش ناموفق به failed منتقل شد.';
				continue;
			}

			$this->write_item( $file, $item );
			$failed++;
			$messages[] = 'پردازش وب‌هوک ناموفق بود و برای تلاش بعدی در صف ماند.';
		}

		return array(
			'success'       => true,
			'status'        => 'processed',
			'processed'     => $processed,
			'failed'        => $failed,
			'remainingFile' => ! empty( $this->get_queue_files() ),
			'messages'      => $messages,
		);
	}

	/**
	 * Process one webhook item.
	 *
	 * @param array $item Queue item.
	 * @return array
	 */
	private function process_item( $item ) {
		$event   = sanitize_text_field( (string) $item['event'] );
		$payload = isset( $item['payload'] ) && is_array( $item['payload'] ) ? $item['payload'] : array();

		$product_sync = new Mobo_Core_Product_Sync();

		switch ( $event ) {
			case 'ProductUpdated':
				$result = $product_sync->process_product_updated_payload( $payload );

				if ( is_array( $result ) ) {
					$result['payload'] = $payload;
				}

				return $result;

			case 'UpdateVariant':
				return $product_sync->process_update_variant_payload( $payload );

			default:
				return array(
					'success' => false,
					'message' => 'Unsupported webhook event: ' . $event,
					'data'    => array(),
				);
		}
	}

	/**
	 * Get queue files sorted by created time.
	 *
	 * @return array
	 */
	private function get_queue_files() {
		$dir = trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR );

		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files = glob( $dir . '*.json' );

		if ( ! is_array( $files ) ) {
			return array();
		}

		usort(
			$files,
			function ( $a, $b ) {
				$at = filemtime( $a );
				$bt = filemtime( $b );

				if ( $at === $bt ) {
					return strcmp( basename( $a ), basename( $b ) );
				}

				return $at < $bt ? -1 : 1;
			}
		);

		return $files;
	}

	/**
	 * Read JSON file.
	 *
	 * @param string $file File path.
	 * @return array|WP_Error
	 */
	private function read_file( $file ) {
		if ( ! is_string( $file ) || ! file_exists( $file ) || ! is_readable( $file ) ) {
			return new WP_Error( 'mobo_core_webhook_file_not_readable', 'Webhook file is not readable.' );
		}

		$contents = file_get_contents( $file );

		if ( false === $contents || '' === trim( $contents ) ) {
			return new WP_Error( 'mobo_core_webhook_file_empty', 'Webhook file is empty.' );
		}

		$json = json_decode( $contents, true );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'mobo_core_webhook_file_invalid_json', 'Webhook file contains invalid JSON.' );
		}

		return $json;
	}

	/**
	 * Write item back to file.
	 *
	 * @param string $file File path.
	 * @param array  $item Item.
	 * @return bool
	 */
	private function write_item( $file, $item ) {
		$json = wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return false;
		}

		return false !== file_put_contents( $file, $json, LOCK_EX );
	}

	/**
	 * Normalize new envelope or old raw payload.
	 *
	 * @param array  $data File data.
	 * @param string $file File path.
	 * @return array
	 */
	private function normalize_queue_item( $data, $file ) {
		if ( isset( $data['payload'] ) && is_array( $data['payload'] ) ) {
			$event = isset( $data['event'] ) ? sanitize_text_field( (string) $data['event'] ) : $this->detect_event( $data['payload'] );

			$data['event']     = $event;
			$data['try']       = isset( $data['try'] ) ? absint( $data['try'] ) : 0;
			$data['createdAt'] = isset( $data['createdAt'] ) ? absint( $data['createdAt'] ) : filemtime( $file );
			$data['updatedAt'] = isset( $data['updatedAt'] ) ? absint( $data['updatedAt'] ) : time();
			$data['expiresAt'] = isset( $data['expiresAt'] ) ? absint( $data['expiresAt'] ) : time() + DAY_IN_SECONDS;

			return $data;
		}

		/*
		 * Legacy raw payload support.
		 */
		$event = $this->detect_event( $data );

		return array(
			'id'        => wp_generate_uuid4(),
			'event'     => sanitize_text_field( (string) $event ),
			'syncId'    => sanitize_text_field( (string) $this->get_value( $data, 'syncId', '' ) ),
			'try'       => 0,
			'createdAt' => filemtime( $file ),
			'updatedAt' => time(),
			'expiresAt' => time() + ( DAY_IN_SECONDS * Mobo_Core_Settings::get_int( 'mobo_core_webhook_expire_days', 2, 1, 30 ) ),
			'payload'   => $data,
		);
	}

	/**
	 * Detect event name from payload.
	 *
	 * Supports:
	 * - event
	 * - type
	 * - Type
	 *
	 * Also supports old C# EventWebhook wrapper:
	 * {
	 *   "type": "ProductUpdated",
	 *   "data": "{...json string...}"
	 * }
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	private function detect_event( $payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}

		$event = $this->get_value( $payload, 'event', '' );

		if ( '' === $event ) {
			$event = $this->get_value( $payload, 'type', '' );
		}

		if ( is_numeric( $event ) ) {
			$event = $this->map_numeric_event_type( absint( $event ) );
		}

		return sanitize_text_field( (string) $event );
	}

	/**
	 * Move file to failed directory.
	 *
	 * @param string $file File.
	 * @param string $reason Reason.
	 * @return void
	 */
	private function move_to_failed( $file, $reason ) {
		$this->ensure_dirs();

		if ( ! file_exists( $file ) ) {
			return;
		}

		$failed_dir = trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/';
		$target     = trailingslashit( $failed_dir ) . gmdate( 'Ymd-His' ) . '-' . sanitize_file_name( $reason ) . '-' . basename( $file );

		@rename( $file, $target );
	}

	/**
	 * Build queue filename.
	 *
	 * @param string $event Event.
	 * @param string $id ID.
	 * @return string
	 */
	private function build_filename( $event, $id ) {
		return gmdate( 'Y-m-d_H-i-s' ) . '--' . sanitize_file_name( $event ) . '--' . sanitize_file_name( $id ) . '.json';
	}

	/**
	 * Ensure queue directories and protections.
	 *
	 * @return void
	 */
	private function ensure_dirs() {
		$this->protect_dir( MOBO_CORE_WEBHOOK_FILE_DIR );
		$this->protect_dir( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/' );
	}

	/**
	 * Protect directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function protect_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n" );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}
	}

	/**
	 * Map old numeric event type if required.
	 *
	 * Adjust these numbers if old enum values differ.
	 *
	 * @param int $type Numeric event type.
	 * @return string
	 */
	private function map_numeric_event_type( $type ) {
		$map = array(
			1 => 'UpdateVariant',
			2 => 'ProductUpdated',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : '';
	}

	/**
	 * Case-tolerant getter.
	 *
	 * @param array  $array Source.
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private function get_value( $array, $key, $default = null ) {
		if ( ! is_array( $array ) ) {
			return $default;
		}

		if ( array_key_exists( $key, $array ) ) {
			return $array[ $key ];
		}

		$pascal = ucfirst( $key );

		if ( array_key_exists( $pascal, $array ) ) {
			return $array[ $pascal ];
		}

		return $default;
	}
}