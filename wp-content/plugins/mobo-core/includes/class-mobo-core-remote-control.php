<?php
/**
 * Portal-initiated remote control for non-secret settings and long-running sync operations.
 *
 * All endpoints using this service are protected by the existing X-SEC credential.
 * No credential value is ever included in a response.
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Remote_Control {

	const LAST_OPERATION_OPTION = 'mobo_core_portal_last_operation';

	/**
	 * Return the Connection-scoped last-operation option key.
	 *
	 * Portal control endpoints are Source-scoped. Their auxiliary state must not
	 * remain installation-global, otherwise one authenticated Source can observe
	 * another Source's request id/status metadata.
	 *
	 * @return string
	 */
	private static function last_operation_option_name() {
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' )
			? absint( Mobo_Core_Connection_Context::get_current_connection_id() )
			: 0;

		return $connection_id > 0
			? self::LAST_OPERATION_OPTION . '_c' . $connection_id
			: self::LAST_OPERATION_OPTION;
	}

	/** @return array */
	private static function read_last_operation() {
		$record = get_option( self::last_operation_option_name(), array() );
		return is_array( $record ) ? $record : array();
	}

	/** @param array $record Record. @return bool */
	private static function write_last_operation( $record ) {
		return update_option( self::last_operation_option_name(), is_array( $record ) ? $record : array(), false );
	}

	/**
	 * Start a normal Sync or full Repair.
	 *
	 * @param string $operation Operation name: sync|repair.
	 * @param string $request_id Portal idempotency identifier.
	 * @return array
	 */
	public static function start_operation( $operation, $request_id = '' ) {
		$operation = sanitize_key( (string) $operation );
		$request_id = sanitize_text_field( (string) $request_id );

		if ( ! in_array( $operation, array( 'sync', 'repair' ), true ) ) {
			return array(
				'success' => false,
				'status'  => 'invalid-operation',
				'message' => 'عملیات فقط می‌تواند sync یا repair باشد.',
				'data'    => self::get_status(),
			);
		}

		if ( '' === $request_id ) {
			$request_id = wp_generate_uuid4();
		}

		$current = self::get_status();
		$last = isset( $current['lastOperation'] ) && is_array( $current['lastOperation'] ) ? $current['lastOperation'] : array();

		if ( ! empty( $current['isRunning'] ) ) {
			if ( isset( $last['requestId'], $last['operation'] ) && $request_id === (string) $last['requestId'] && $operation === (string) $last['operation'] ) {
				return array(
					'success' => true,
					'status'  => 'already-accepted',
					'message' => 'این درخواست قبلاً پذیرفته شده و هنوز در حال اجرا است.',
					'data'    => $current,
				);
			}

			return array(
				'success' => false,
				'status'  => 'busy',
				'message' => 'یک Sync یا Repair دیگر در حال اجرا است.',
				'data'    => $current,
			);
		}

		$repair_mode = 'repair' === $operation;
		$sync = new Mobo_Core_Product_Sync();
		$result = $sync->start_manual_sync( $request_id, 'portal-' . $operation, $repair_mode );

		if ( ! empty( $result['success'] ) ) {
			$record = array(
				'requestId'   => $request_id,
				'operation'   => $operation,
				'source'      => 'portal',
				'requestedAt' => time(),
				'acceptedAt'  => time(),
				'finishedAt'  => 0,
				'status'      => 'running',
				'message'     => isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '',
			);
			self::write_last_operation( $record );
		} else {
			/*
			 * Never let a rejected concurrent request replace the identity/status of
			 * the operation that actually owns the running manual-sync generation.
			 */
			$after = self::get_status();
			if ( empty( $after['isRunning'] ) ) {
				$record = array(
					'requestId'   => $request_id,
					'operation'   => $operation,
					'source'      => 'portal',
					'requestedAt' => time(),
					'acceptedAt'  => 0,
					'finishedAt'  => time(),
					'status'      => 'rejected',
					'message'     => isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '',
				);
				self::write_last_operation( $record );
			}
		}

		if ( ! empty( $result['success'] ) && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			$result['selfKick'] = Mobo_Core_Self_Runner::kick( 'portal-' . $operation . '-start', false );
		}

		return array(
			'success' => ! empty( $result['success'] ),
			'status'  => ! empty( $result['success'] ) ? 'accepted' : 'rejected',
			'message' => isset( $result['message'] ) ? $result['message'] : '',
			'data'    => self::get_status(),
		);
	}

	/**
	 * Cancel the active manual Sync/Repair while preserving the resumable state.
	 *
	 * @return array
	 */
	public static function cancel_operation() {
		$sync = new Mobo_Core_Product_Sync();
		$result = $sync->cancel_manual_sync();
		$record = self::read_last_operation();
		$record['status'] = ! empty( $result['success'] ) ? 'cancelled' : 'cancel-failed';
		$record['finishedAt'] = time();
		$record['message'] = isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '';
		self::write_last_operation( $record );

		return array(
			'success' => ! empty( $result['success'] ),
			'status'  => ! empty( $result['success'] ) ? 'cancelled' : 'cancel-failed',
			'message' => isset( $result['message'] ) ? $result['message'] : '',
			'data'    => self::get_status(),
		);
	}

	/**
	 * Return one compact operation status for Portal and health reports.
	 *
	 * @return array
	 */
	public static function get_status() {
		$sync = new Mobo_Core_Product_Sync();
		$manual = $sync->get_manual_sync_status();
		$reconciliation = class_exists( 'Mobo_Core_Sync_Health' ) ? ( method_exists( 'Mobo_Core_Sync_Health', 'get_operational_dashboard_status' ) ? Mobo_Core_Sync_Health::get_operational_dashboard_status() : Mobo_Core_Sync_Health::get_dashboard_status() ) : array();
		$upgrade = class_exists( 'Mobo_Core_Upgrade_Coordinator' ) ? Mobo_Core_Upgrade_Coordinator::get_status() : array();
		$last = self::read_last_operation();

		$is_running = ! empty( $manual['isRunning'] ) || ! empty( $manual['isWaitingForPortal'] );
		$operation = ! empty( $manual['repairMode'] ) ? 'repair' : ( $is_running ? 'sync' : '' );
		$status = isset( $manual['status'] ) ? sanitize_key( (string) $manual['status'] ) : 'idle';

		if ( $is_running ) {
			$status = ! empty( $manual['isWaitingForPortal'] ) ? 'waiting-for-portal' : 'running';
		} elseif ( ! empty( $manual['isDone'] ) ) {
			$status = 'done';
		} elseif ( ! empty( $manual['isCancelled'] ) ) {
			$status = 'cancelled';
		} elseif ( ! empty( $manual['lastError'] ) ) {
			$status = 'failed';
		} elseif ( ! empty( $upgrade['active'] ) ) {
			$status = 'paused-for-upgrade';
		}

		if ( '' === $operation && isset( $last['operation'] ) ) {
			$operation = sanitize_key( (string) $last['operation'] );
		}

		if ( ! empty( $last ) ) {
			$last['status'] = $status;
			if ( in_array( $status, array( 'done', 'cancelled', 'failed' ), true ) && empty( $last['finishedAt'] ) ) {
				$last['finishedAt'] = ! empty( $manual['completedAt'] ) ? absint( $manual['completedAt'] ) : time();
				self::write_last_operation( $last );
			}
		}

		return array(
			'schemaVersion'   => 1,
			'operation'       => $operation,
			'status'          => $status,
			'isRunning'       => $is_running,
			'isRepair'        => ! empty( $manual['repairMode'] ),
			'isWaiting'       => ! empty( $manual['isWaitingForPortal'] ),
			'isDone'          => ! empty( $manual['isDone'] ),
			'isCancelled'     => ! empty( $manual['isCancelled'] ),
			'progressPercent' => isset( $manual['progressPercent'] ) ? (float) $manual['progressPercent'] : 0,
			'processedProducts'=> isset( $manual['processedProducts'] ) ? absint( $manual['processedProducts'] ) : 0,
			'remainingProducts'=> isset( $manual['remainingProducts'] ) ? absint( $manual['remainingProducts'] ) : 0,
			'totalProducts'   => isset( $manual['productTotalCount'] ) ? absint( $manual['productTotalCount'] ) : 0,
			'syncId'          => isset( $manual['syncId'] ) ? sanitize_text_field( (string) $manual['syncId'] ) : '',
			'source'          => isset( $manual['source'] ) ? sanitize_key( (string) $manual['source'] ) : '',
			'startedAt'       => isset( $manual['startedAt'] ) ? absint( $manual['startedAt'] ) : 0,
			'updatedAt'       => isset( $manual['updatedAt'] ) ? absint( $manual['updatedAt'] ) : 0,
			'completedAt'     => isset( $manual['completedAt'] ) ? absint( $manual['completedAt'] ) : 0,
			'lastMessage'     => isset( $manual['lastMessage'] ) ? sanitize_text_field( (string) $manual['lastMessage'] ) : '',
			'lastError'       => isset( $manual['lastError'] ) ? sanitize_text_field( (string) $manual['lastError'] ) : '',
			'lastOperation'   => $last,
			'reconciliation'  => array(
				'status'        => isset( $reconciliation['state']['status'] ) ? sanitize_key( (string) $reconciliation['state']['status'] ) : ( isset( $reconciliation['status'] ) ? sanitize_key( (string) $reconciliation['status'] ) : 'idle' ),
				'mode'          => isset( $reconciliation['state']['mode'] ) ? sanitize_key( (string) $reconciliation['state']['mode'] ) : '',
				'pendingRepair' => isset( $reconciliation['pendingRepair'] ) ? absint( $reconciliation['pendingRepair'] ) : 0,
				'failedProducts'=> isset( $reconciliation['counts']['failed'] ) ? absint( $reconciliation['counts']['failed'] ) : 0,
			),
			'upgradeBarrier'  => array(
				'active' => ! empty( $upgrade['active'] ),
				'status' => isset( $upgrade['status'] ) ? sanitize_key( (string) $upgrade['status'] ) : '',
			),
		);
	}
}
