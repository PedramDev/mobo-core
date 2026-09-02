<?php
/**
 * Backward-compatibility tombstone for the retired automatic Product Recovery.
 *
 * Recovery mutations are no longer executed automatically. Historical migration
 * calls remain safe no-ops so upgrades from old versions cannot re-arm the worker.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Product_Recovery {
	const RUNTIME_ENABLED = false;
	const VARIATION_INTEGRITY_REASON = 'upgrade-10-33-35-variation-integrity';

	public static function runtime_enabled() {
		return false;
	}

	public static function is_pending() {
		return false;
	}

	public static function schedule( $reason = 'retired' ) {
		return;
	}

	public static function schedule_followup( $reason = 'retired' ) {
		return;
	}

	public static function get_status() {
		return array( 'status' => 'retired', 'pending' => false );
	}

	public function process_batch( $limit = 0, $budget_seconds = 0 ) {
		return array( 'success' => true, 'status' => 'retired', 'remaining' => false, 'processed' => 0, 'recovered' => 0 );
	}
}
