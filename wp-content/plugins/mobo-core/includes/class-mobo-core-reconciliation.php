<?php
/**
 * Backward-compatibility facade for the retired reconciliation subsystem.
 *
 * Product-mutating reconciliation was retired permanently. New internal code
 * uses Mobo_Core_Sync_Health directly; this facade prevents older integrations
 * from fatalling while exposing observational health only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Reconciliation {
	const RUNTIME_ENABLED = false;

	public static function runtime_enabled() {
		return false;
	}

	public static function table_name() {
		return Mobo_Core_Sync_Health::table_name();
	}

	public static function create_table() {
		Mobo_Core_Sync_Health::create_table();
	}

	public function run_tick( $source = 'retired', $force = false, $force_deep = false ) {
		return array(
			'success'             => true,
			'status'              => 'retired',
			'source'              => sanitize_key( (string) $source ),
			'processedProducts'   => 0,
			'processedVariations' => 0,
			'needsContinuation'   => false,
		);
	}

	public static function local_product_structure_is_sane( $product_id ) {
		return Mobo_Core_Sync_Health::local_product_structure_is_sane( $product_id );
	}

	public static function mark_behind( $guid, $wp_id = 0, $revision = 0, $hash = '', $portal_id = 0 ) {
		return Mobo_Core_Sync_Health::mark_behind( $guid, $wp_id, $revision, $hash, $portal_id );
	}

	public static function mark_repairing( $guid, $wp_id = 0, $revision = 0, $hash = '', $portal_id = 0 ) {
		return Mobo_Core_Sync_Health::mark_repairing( $guid, $wp_id, $revision, $hash, $portal_id );
	}

	public static function mark_synced( $guid, $wp_id = 0, $revision = 0, $hash = '', $portal_id = 0 ) {
		return Mobo_Core_Sync_Health::mark_synced( $guid, $wp_id, $revision, $hash, $portal_id );
	}

	public static function mark_failed( $guid, $wp_id = 0, $error = '', $revision = 0, $portal_id = 0 ) {
		return Mobo_Core_Sync_Health::mark_failed( $guid, $wp_id, $error, $revision, $portal_id );
	}

	public static function record_webhook_result( $event, $payload, $result ) {
		Mobo_Core_Sync_Health::record_webhook_result( $event, $payload, $result );
	}

	public static function get_dashboard_status() {
		return Mobo_Core_Sync_Health::get_dashboard_status();
	}
}
