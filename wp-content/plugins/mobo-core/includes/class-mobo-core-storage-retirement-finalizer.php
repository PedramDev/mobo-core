<?php
/**
 * Terminal evidence gate for physical Product/Variation metadata retirement.
 *
 * 10.33.96 extends the terminal gate to all Mobo-owned Product/Variation metadata.
 * Retirement is complete only when operational, identity and generic entity-meta
 * irreversible fences are active, all three migrations report completion, and
 * SQL residue proofs for every retired scope are exactly zero.
 *
 * Order-item identity snapshots, attachment metadata, forensic markers and all
 * non-managed WooCommerce metadata remain outside this gate.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class Mobo_Core_Storage_Retirement_Finalizer {

	const WORKER_NAME = 'storage_retirement_finalizer_v4';
	const GATE_VERSION = 4;
	const MIN_EVALUATION_INTERVAL = 300;
	const MAX_RUNTIME_GATE_AGE_SECONDS = 900;

	/** @return bool */
	public static function should_evaluate() {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Mode' )
			|| ! class_exists( 'Mobo_Core_Storage_Schema' )
			|| ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$updated_at = (string) $wpdb->get_var( $wpdb->prepare( "SELECT updated_at FROM {$table} WHERE connection_id=0 AND worker_name=%s LIMIT 1", self::WORKER_NAME ) );
		if ( '' === trim( $updated_at ) ) { return true; }
		$updated_ts = strtotime( $updated_at . ' UTC' );
		return ! $updated_ts || ( time() - $updated_ts ) >= self::MIN_EVALUATION_INTERVAL;
	}

	/** @return array */
	public static function evaluate( $force = false ) {
		if ( ! $force && ! self::should_evaluate() ) { return self::get_status(); }
		$state = self::assess_evidence( self::collect_evidence(), time() );
		self::persist_state( $state );
		return $state;
	}

	/**
	 * Pure deterministic terminal-state decision.
	 *
	 * @param array $evidence Evidence snapshot.
	 * @param int   $now Unix time for deterministic tests.
	 * @return array
	 */
	public static function assess_evidence( $evidence, $now = 0 ) {
		$evidence = is_array( $evidence ) ? $evidence : array();
		$now = absint( $now ); if ( $now <= 0 ) { $now = time(); }

		$operational_keys = self::operational_managed_keys();
		$requested = self::normalize_keys( isset( $evidence['requestedKeys'] ) ? $evidence['requestedKeys'] : array(), $operational_keys );
		$identity_keys = self::identity_managed_keys();
		$reported_identity_keys = self::normalize_keys( isset( $evidence['identityManagedKeys'] ) ? $evidence['identityManagedKeys'] : array(), $identity_keys );

		$operational_fence = ! empty( $evidence['terminalFenceActive'] );
		$operational_complete = ! empty( $evidence['terminalComplete'] );
		$operational_residue = array_key_exists( 'physicalResidue', $evidence ) ? (int) $evidence['physicalResidue'] : -1;
		$identity_fence = ! empty( $evidence['identityFenceActive'] );
		$identity_complete = ! empty( $evidence['identityComplete'] );
		$identity_residue = array_key_exists( 'identityPhysicalResidue', $evidence ) ? (int) $evidence['identityPhysicalResidue'] : -1;
		$entity_fence = ! empty( $evidence['entityMetaFenceActive'] );
		$entity_complete = ! empty( $evidence['entityMetaComplete'] );
		$entity_residue = array_key_exists( 'entityMetaPhysicalResidue', $evidence ) ? (int) $evidence['entityMetaPhysicalResidue'] : -1;
		$entity_late_repair = ! empty( $evidence['entityMetaLateRepairPending'] );

		$blockers = array();
		if ( empty( $evidence['schemaReady'] ) ) { $blockers[] = 'schema_unavailable'; }
		if ( ! $operational_fence ) { $blockers[] = 'terminal_fence_not_active'; }
		if ( ! $operational_complete ) { $blockers[] = 'physical_retirement_not_complete'; }
		if ( 0 !== $operational_residue ) { $blockers[] = 'physical_residue_not_zero'; }
		if ( count( $requested ) !== count( $operational_keys ) ) { $blockers[] = 'not_all_operational_keys_requested'; }
		if ( ! $identity_fence ) { $blockers[] = 'identity_terminal_fence_not_active'; }
		if ( ! $identity_complete ) { $blockers[] = 'identity_physical_retirement_not_complete'; }
		if ( 0 !== $identity_residue ) { $blockers[] = 'identity_physical_residue_not_zero'; }
		if ( count( $reported_identity_keys ) !== count( $identity_keys ) ) { $blockers[] = 'identity_managed_key_scope_incomplete'; }
		if ( ! $entity_fence ) { $blockers[] = 'entity_meta_terminal_fence_not_active'; }
		if ( ! $entity_complete ) { $blockers[] = 'entity_meta_physical_retirement_not_complete'; }
		if ( 0 !== $entity_residue ) { $blockers[] = 'entity_meta_physical_residue_not_zero'; }
		if ( $entity_late_repair ) { $blockers[] = 'entity_meta_late_repair_pending'; }

		$complete = empty( $blockers );
		return array(
			'gateVersion' => self::GATE_VERSION,
			'logicalRetirementComplete' => $complete,
			'status' => $complete ? 'physical-retirement-complete' : ( $operational_fence || $identity_fence || $entity_fence ? 'terminal-cutover-in-progress' : 'observing' ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'managedKeys' => $operational_keys,
			'requestedKeys' => $requested,
			'eligibleKeys' => $operational_keys,
			'missingRequestedKeys' => array_values( array_diff( $operational_keys, $requested ) ),
			'missingEligibleKeys' => array(),
			'identityManagedKeys' => $identity_keys,
			'identityReportedManagedKeys' => $reported_identity_keys,
			'identityMissingManagedKeys' => array_values( array_diff( $identity_keys, $reported_identity_keys ) ),
			'operationalFenceActive' => $operational_fence,
			'operationalComplete' => $operational_complete,
			'operationalPhysicalResidue' => $operational_residue,
			'identityFenceActive' => $identity_fence,
			'identityComplete' => $identity_complete,
			'identityPhysicalResidue' => $identity_residue,
			'entityMetaFenceActive' => $entity_fence,
			'entityMetaComplete' => $entity_complete,
			'entityMetaPhysicalResidue' => $entity_residue,
			'entityMetaLateRepairPending' => $entity_late_repair,
			'evaluatedAt' => $now,
			'physicalDeletionAllowed' => $complete,
			'physicalDeletionBlockers' => $complete ? array() : array_values( array_unique( $blockers ) ),
			'terminalPolicy' => 'custom-only-physical-all-mobo-product-variation-postmeta-retirement',
			'note' => '10.34.10 terminal state requires durable Custom-only fences and verified zero Product/Variation operational, identity and remaining Mobo-owned metadata residue. WooCommerce-native product metadata, attachment metadata and order/order-item metadata remain outside this retirement scope.',
		);
	}

	/** @return array */
	public static function get_status() {
		$state = self::read_state();
		if ( empty( $state ) ) {
			return array(
				'gateVersion'=>self::GATE_VERSION,
				'logicalRetirementComplete'=>false,
				'runtimeComplete'=>false,
				'runtimeFresh'=>false,
				'status'=>'not-evaluated',
				'blockers'=>array('not_evaluated'),
				'physicalDeletionAllowed'=>false,
				'physicalDeletionBlockers'=>array('terminal_fence_not_active','physical_retirement_not_complete','physical_residue_not_zero','identity_terminal_fence_not_active','identity_physical_retirement_not_complete','identity_physical_residue_not_zero','entity_meta_terminal_fence_not_active','entity_meta_physical_retirement_not_complete','entity_meta_physical_residue_not_zero','entity_meta_late_repair_pending'),
			);
		}
		$evaluated = absint( isset( $state['evaluatedAt'] ) ? $state['evaluatedAt'] : 0 );
		$age = $evaluated > 0 ? max( 0, time() - $evaluated ) : null;
		$fresh = null !== $age && $age <= self::MAX_RUNTIME_GATE_AGE_SECONDS;
		$state['runtimeFresh'] = $fresh;
		$state['runtimeAgeSeconds'] = $age;
		$state['runtimeComplete'] = $fresh && ! empty( $state['logicalRetirementComplete'] );
		if ( ! $fresh && ! empty( $state['logicalRetirementComplete'] ) ) { $state['status'] = 'stale'; }
		return $state;
	}

	/** @return bool */
	public static function is_runtime_complete() {
		$state = self::read_state();
		if ( self::GATE_VERSION !== absint( isset( $state['gateVersion'] ) ? $state['gateVersion'] : 0 ) || empty( $state['logicalRetirementComplete'] ) ) { return false; }
		$evaluated = absint( isset( $state['evaluatedAt'] ) ? $state['evaluatedAt'] : 0 );
		return $evaluated > 0 && ( time() - $evaluated ) <= self::MAX_RUNTIME_GATE_AGE_SECONDS;
	}

	/** @return array<int,string> */
	private static function operational_managed_keys() {
		if ( class_exists( 'Mobo_Core_Storage_Postmeta_Write_Suppression' ) ) {
			return array_keys( Mobo_Core_Storage_Postmeta_Write_Suppression::safe_keys() );
		}
		return array(
			'_mobo_product_seen_revision','_mobo_product_applied_revision',
			'_mobo_variant_seen_revision','_mobo_variant_applied_revision',
			'_mobo_product_source_hash','_mobo_variant_source_hash','mobo_sync_incomplete',
		);
	}

	/** @return array<int,string> */
	private static function identity_managed_keys() {
		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) ) {
			return array_keys( Mobo_Core_Storage_Identity_Meta_Retirement::managed_keys() );
		}
		return array(
			'product_guid','variant_guid','mobo_variant_guid','_mobo_variant_guid',
			'portal_product_id','mobo_portal_product_id','_mobo_portal_product_id','PortalProductId',
			'portal_variant_id','mobo_portal_variant_id','_mobo_portal_variant_id','PortalVariantId',
		);
	}

	/** @return array<int,string> */
	private static function normalize_keys( $keys, $safe ) {
		$allowed = array_fill_keys( (array) $safe, true ); $out = array();
		foreach ( (array) $keys as $key ) { $key = (string) $key; if ( isset( $allowed[$key] ) ) { $out[$key] = true; } }
		return array_keys( $out );
	}

	/** @return array */
	private static function collect_evidence() {
		$operational = class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' ) ? Mobo_Core_Storage_Operational_Meta_Retirement::get_status() : array();
		$identity = class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) ? Mobo_Core_Storage_Identity_Meta_Retirement::get_status() : array();
		$entity = class_exists( 'Mobo_Core_Storage_Entity_Meta_Retirement' ) ? Mobo_Core_Storage_Entity_Meta_Retirement::get_status() : array();
		return array(
			'schemaReady' => class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready(),
			'effectiveMode' => class_exists( 'Mobo_Core_Storage_Mode' ) ? Mobo_Core_Storage_Mode::effective() : 'legacy',
			'requestedKeys' => class_exists( 'Mobo_Core_Storage_Postmeta_Write_Suppression' ) ? Mobo_Core_Storage_Postmeta_Write_Suppression::requested_keys() : array(),
			'terminalFenceActive' => ! empty( $operational['fenced'] ),
			'terminalComplete' => ! empty( $operational['complete'] ),
			'physicalResidue' => array_key_exists( 'physicalResidue', $operational ) && null !== $operational['physicalResidue'] ? (int) $operational['physicalResidue'] : -1,
			'identityFenceActive' => ! empty( $identity['fenced'] ),
			'identityComplete' => ! empty( $identity['complete'] ),
			'identityPhysicalResidue' => array_key_exists( 'physicalResidue', $identity ) && null !== $identity['physicalResidue'] ? (int) $identity['physicalResidue'] : -1,
			'identityManagedKeys' => isset( $identity['managedKeys'] ) && is_array( $identity['managedKeys'] ) ? $identity['managedKeys'] : array(),
			'entityMetaFenceActive' => ! empty( $entity['fenced'] ),
			'entityMetaComplete' => ! empty( $entity['complete'] ),
			'entityMetaPhysicalResidue' => array_key_exists( 'physicalResidue', $entity ) && null !== $entity['physicalResidue'] ? (int) $entity['physicalResidue'] : -1,
			'entityMetaLateRepairPending' => ! empty( $entity['lateRepairPending'] ),
		);
	}

	/** @return array */
	private static function read_state() {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return array(); }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$json = (string) $wpdb->get_var( $wpdb->prepare( "SELECT state_json FROM {$table} WHERE connection_id=0 AND worker_name=%s LIMIT 1", self::WORKER_NAME ) );
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** @return bool */
	private static function persist_state( $state ) {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' ); $now = current_time( 'mysql', true );
		$encoded = wp_json_encode( $state ); if ( ! is_string( $encoded ) ) { $encoded='{}'; }
		$sql = "INSERT INTO {$table} (connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,last_error_code,state_json,created_at,updated_at) VALUES (0,%s,%s,%s,'',0,'closed','',%s,%s,%s) ON DUPLICATE KEY UPDATE last_started_at=VALUES(last_started_at),last_finished_at=VALUES(last_finished_at),last_cursor='',consecutive_failures=0,circuit_state='closed',circuit_open_until=NULL,last_error_code='',state_json=VALUES(state_json),updated_at=VALUES(updated_at)";
		return false !== $wpdb->query( $wpdb->prepare( $sql, self::WORKER_NAME, $now, $now, $encoded, $now, $now ) );
	}
}
