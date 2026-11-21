<?php
/**
 * Evidence gate for future custom-primary Product/Variation reads.
 *
 * 10.33.78 still never changes the requested storage mode automatically. It
 * persists the bounded confidence lease that Storage_Mode must validate before
 * honoring a requested custom-primary read path. The window starts only
 * after a gap-free backfill and exact legacy/custom identity coverage. Any new
 * shadow mismatch or custom dual-write failure resets the clean window.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Storage_Promotion_Gate {

	const WORKER_NAME = 'storage_promotion_gate_v1';
	const GATE_VERSION = 5;
	const MIN_COMPARISONS = 500;
	const MIN_STATE_COMPARISONS = 100;
	const MIN_CLEAN_SECONDS = 21600; // Six hours of clean shadow operation.
	const MAX_PARITY_STALENESS_SECONDS = 86400;
	const MIN_EVALUATION_INTERVAL = 300;
	const MAX_RUNTIME_GATE_AGE_SECONDS = 900;

	/** Active durable Connection owner for gate evidence/state. @return int */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** Whether the low-priority runner should evaluate the gate now. @return bool */
	public static function should_evaluate() {
		global $wpdb;

		if ( ! class_exists( 'Mobo_Core_Storage_Mode' )
			|| ! in_array( Mobo_Core_Storage_Mode::effective(), array( Mobo_Core_Storage_Mode::SHADOW_READ, Mobo_Core_Storage_Mode::CUSTOM_PRIMARY, Mobo_Core_Storage_Mode::CUSTOM_ONLY ), true )
			|| ! class_exists( 'Mobo_Core_Storage_Schema' )
			|| ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}

		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$updated_at = (string) $wpdb->get_var( $wpdb->prepare( "SELECT updated_at FROM {$table} WHERE connection_id=%d AND worker_name = %s LIMIT 1", $connection_id, self::WORKER_NAME ) );
		if ( '' === trim( $updated_at ) ) {
			return true;
		}
		$updated_ts = strtotime( $updated_at . ' UTC' );
		return ! $updated_ts || ( time() - $updated_ts ) >= self::MIN_EVALUATION_INTERVAL;
	}

	/**
	 * Evaluate and persist one confidence checkpoint from live storage evidence.
	 *
	 * @param bool $force Ignore the five-minute runner throttle. Tests/manual tools only.
	 * @return array
	 */
	public static function evaluate( $force = false ) {
		if ( ! $force && ! self::should_evaluate() ) {
			return self::get_status();
		}

		$evidence = self::collect_evidence();
		$state = self::assess_evidence( $evidence, self::read_state(), time() );
		self::persist_state( $state );
		return $state;
	}

	/**
	 * Pure deterministic gate decision used by runtime and adversarial tests.
	 * This method does not read/write WordPress state and cannot promote mode.
	 *
	 * @param array $evidence Current evidence snapshot.
	 * @param array $previous Previous persisted gate state.
	 * @param int   $now Unix timestamp.
	 * @return array
	 */
	public static function assess_evidence( $evidence, $previous = array(), $now = 0 ) {
		$evidence = is_array( $evidence ) ? $evidence : array();
		$previous = is_array( $previous ) ? $previous : array();
		$now = absint( $now );
		if ( $now <= 0 ) { $now = time(); }

		$backfill = isset( $evidence['backfill'] ) && is_array( $evidence['backfill'] ) ? $evidence['backfill'] : array();
		$parity = isset( $evidence['parity'] ) && is_array( $evidence['parity'] ) ? $evidence['parity'] : array();
		$state_parity = isset( $evidence['stateParity'] ) && is_array( $evidence['stateParity'] ) ? $evidence['stateParity'] : array();
		$coverage = isset( $evidence['coverage'] ) && is_array( $evidence['coverage'] ) ? $evidence['coverage'] : array();
		$reconciliation = isset( $evidence['reconciliation'] ) && is_array( $evidence['reconciliation'] ) ? $evidence['reconciliation'] : array();
		$write_failures = absint( isset( $evidence['dualWriteFailureCount'] ) ? $evidence['dualWriteFailureCount'] : 0 );
		$primary_fallbacks = absint( isset( $evidence['primaryFallbackCount'] ) ? $evidence['primaryFallbackCount'] : 0 );
		$hard_blockers = array();

		if ( empty( $evidence['schemaReady'] ) ) { $hard_blockers[] = 'schema_unavailable'; }
		$evidence_mode = sanitize_key( (string) ( isset( $evidence['effectiveMode'] ) ? $evidence['effectiveMode'] : '' ) );
		if ( ! in_array( $evidence_mode, array( Mobo_Core_Storage_Mode::SHADOW_READ, Mobo_Core_Storage_Mode::CUSTOM_PRIMARY, Mobo_Core_Storage_Mode::CUSTOM_ONLY ), true ) ) {
			$hard_blockers[] = 'mode_not_observable_read';
		}
		$backfill_status = sanitize_key( (string) ( isset( $backfill['status'] ) ? $backfill['status'] : 'unavailable' ) );
		if ( 'completed' !== $backfill_status ) { $hard_blockers[] = 'backfill_not_clean_complete'; }
		if ( absint( isset( $backfill['gaps'] ) ? $backfill['gaps'] : 0 ) > 0 ) { $hard_blockers[] = 'backfill_gaps'; }
		if ( '' !== trim( (string) ( isset( $backfill['lastError'] ) ? $backfill['lastError'] : '' ) ) ) { $hard_blockers[] = 'backfill_error'; }
		$reconciliation_status = sanitize_key( (string) ( isset( $reconciliation['status'] ) ? $reconciliation['status'] : 'not-run' ) );
		if ( 'clean' !== $reconciliation_status ) { $hard_blockers[] = 'reconciliation_not_clean'; }
		if ( absint( isset( $reconciliation['ambiguous'] ) ? $reconciliation['ambiguous'] : 0 ) > 0 || 'ambiguous' === $reconciliation_status ) { $hard_blockers[] = 'reconciliation_ambiguous'; }
		if ( absint( isset( $reconciliation['failed'] ) ? $reconciliation['failed'] : 0 ) > 0 || 'error' === $reconciliation_status ) { $hard_blockers[] = 'reconciliation_error'; }
		if ( empty( $hard_blockers ) ) {
			foreach ( self::coverage_blockers( $coverage ) as $blocker ) { $hard_blockers[] = $blocker; }
		}

		$total_comparisons = absint( isset( $parity['totalComparisons'] ) ? $parity['totalComparisons'] : 0 );
		$total_mismatches = absint( isset( $parity['totalMismatches'] ) ? $parity['totalMismatches'] : 0 );
		$total_state_comparisons = absint( isset( $state_parity['totalComparisons'] ) ? $state_parity['totalComparisons'] : 0 );
		$total_state_mismatches = absint( isset( $state_parity['totalMismatches'] ) ? $state_parity['totalMismatches'] : 0 );
		$window = isset( $previous['window'] ) && is_array( $previous['window'] ) ? $previous['window'] : array();
		$baseline_comparisons = absint( isset( $window['baselineComparisons'] ) ? $window['baselineComparisons'] : 0 );
		$baseline_mismatches = absint( isset( $window['baselineMismatches'] ) ? $window['baselineMismatches'] : 0 );
		$baseline_state_comparisons = absint( isset( $window['baselineStateComparisons'] ) ? $window['baselineStateComparisons'] : 0 );
		$baseline_state_mismatches = absint( isset( $window['baselineStateMismatches'] ) ? $window['baselineStateMismatches'] : 0 );
		$baseline_write_failures = absint( isset( $window['baselineWriteFailures'] ) ? $window['baselineWriteFailures'] : 0 );
		$baseline_primary_fallbacks = absint( isset( $window['baselinePrimaryFallbacks'] ) ? $window['baselinePrimaryFallbacks'] : 0 );
		$window_started_at = absint( isset( $window['startedAt'] ) ? $window['startedAt'] : 0 );
		$reset_count = absint( isset( $window['resetCount'] ) ? $window['resetCount'] : 0 );
		$reset_reason = '';
		$previous_gate_version = absint( isset( $previous['gateVersion'] ) ? $previous['gateVersion'] : 0 );

		$reset_baselines = function() use ( &$baseline_comparisons, &$baseline_mismatches, &$baseline_state_comparisons, &$baseline_state_mismatches, &$baseline_write_failures, &$baseline_primary_fallbacks, $total_comparisons, $total_mismatches, $total_state_comparisons, $total_state_mismatches, $write_failures, $primary_fallbacks ) {
			$baseline_comparisons = $total_comparisons;
			$baseline_mismatches = $total_mismatches;
			$baseline_state_comparisons = $total_state_comparisons;
			$baseline_state_mismatches = $total_state_mismatches;
			$baseline_write_failures = $write_failures;
			$baseline_primary_fallbacks = $primary_fallbacks;
		};

		if ( ! empty( $hard_blockers ) ) {
			$window_started_at = 0;
			$reset_baselines();
		} elseif ( self::GATE_VERSION !== $previous_gate_version ) {
			$window_started_at = $now;
			$reset_baselines();
			if ( $previous_gate_version > 0 ) { $reset_count++; }
			$reset_reason = 'gate_policy_changed';
		} elseif ( $window_started_at <= 0 ) {
			$window_started_at = $now;
			$reset_baselines();
			$reset_reason = 'window_started';
		} elseif ( $total_comparisons < $baseline_comparisons || $total_mismatches < $baseline_mismatches || $total_state_comparisons < $baseline_state_comparisons || $total_state_mismatches < $baseline_state_mismatches || $write_failures < $baseline_write_failures || $primary_fallbacks < $baseline_primary_fallbacks ) {
			$window_started_at = $now;
			$reset_baselines();
			$reset_count++;
			$reset_reason = 'counters_reset';
		} elseif ( $total_mismatches > $baseline_mismatches ) {
			$window_started_at = $now;
			$reset_baselines();
			$reset_count++;
			$reset_reason = 'shadow_mismatch';
		} elseif ( $total_state_mismatches > $baseline_state_mismatches ) {
			$window_started_at = $now;
			$reset_baselines();
			$reset_count++;
			$reset_reason = 'state_shadow_mismatch';
		} elseif ( $write_failures > $baseline_write_failures ) {
			$window_started_at = $now;
			$reset_baselines();
			$reset_count++;
			$reset_reason = 'dual_write_failure';
		} elseif ( $primary_fallbacks > $baseline_primary_fallbacks ) {
			$window_started_at = $now;
			$reset_baselines();
			$reset_count++;
			$reset_reason = 'custom_primary_fallback';
		}

		$window_comparisons = max( 0, $total_comparisons - $baseline_comparisons );
		$window_mismatches = max( 0, $total_mismatches - $baseline_mismatches );
		$window_state_comparisons = max( 0, $total_state_comparisons - $baseline_state_comparisons );
		$window_state_mismatches = max( 0, $total_state_mismatches - $baseline_state_mismatches );
		$window_write_failures = max( 0, $write_failures - $baseline_write_failures );
		$window_primary_fallbacks = max( 0, $primary_fallbacks - $baseline_primary_fallbacks );
		$clean_seconds = $window_started_at > 0 ? max( 0, $now - $window_started_at ) : 0;
		$parity_updated_at = sanitize_text_field( (string) ( isset( $parity['updatedAt'] ) ? $parity['updatedAt'] : '' ) );
		$parity_updated_ts = '' !== $parity_updated_at ? strtotime( $parity_updated_at . ' UTC' ) : false;
		$parity_age = $parity_updated_ts ? max( 0, $now - $parity_updated_ts ) : null;
		$state_parity_updated_at = sanitize_text_field( (string) ( isset( $state_parity['updatedAt'] ) ? $state_parity['updatedAt'] : '' ) );
		$state_parity_updated_ts = '' !== $state_parity_updated_at ? strtotime( $state_parity_updated_at . ' UTC' ) : false;
		$state_parity_age = $state_parity_updated_ts ? max( 0, $now - $state_parity_updated_ts ) : null;

		$blockers = $hard_blockers;
		if ( empty( $hard_blockers ) ) {
			if ( $window_comparisons < self::MIN_COMPARISONS ) { $blockers[] = 'insufficient_comparisons'; }
			if ( $window_state_comparisons < self::MIN_STATE_COMPARISONS ) { $blockers[] = 'insufficient_state_comparisons'; }
			if ( $clean_seconds < self::MIN_CLEAN_SECONDS ) { $blockers[] = 'insufficient_clean_time'; }
			if ( $window_mismatches > 0 ) { $blockers[] = 'shadow_mismatch'; }
			if ( $window_state_mismatches > 0 ) { $blockers[] = 'state_shadow_mismatch'; }
			if ( $window_write_failures > 0 ) { $blockers[] = 'dual_write_failure'; }
			if ( $window_primary_fallbacks > 0 ) { $blockers[] = 'custom_primary_fallback'; }
			if ( null === $parity_age ) { $blockers[] = 'parity_missing'; }
			elseif ( $parity_age > self::MAX_PARITY_STALENESS_SECONDS ) { $blockers[] = 'parity_stale'; }
			if ( null === $state_parity_age ) { $blockers[] = 'state_parity_missing'; }
			elseif ( $state_parity_age > self::MAX_PARITY_STALENESS_SECONDS ) { $blockers[] = 'state_parity_stale'; }
		}
		$blockers = array_values( array_unique( array_map( 'sanitize_key', $blockers ) ) );
		$eligible = empty( $blockers );
		$eligible_at = $eligible ? absint( isset( $previous['eligibleAt'] ) ? $previous['eligibleAt'] : 0 ) : 0;
		if ( $eligible && $eligible_at <= 0 ) { $eligible_at = $now; }

		return array(
			'gateVersion' => self::GATE_VERSION,
			'eligible' => $eligible,
			'status' => $eligible ? 'eligible' : ( empty( $hard_blockers ) ? 'observing' : 'blocked' ),
			'blockers' => $blockers,
			'hardBlockers' => array_values( array_unique( array_map( 'sanitize_key', $hard_blockers ) ) ),
			'eligibleAt' => $eligible_at,
			'evaluatedAt' => $now,
			'evaluatedAtIso' => gmdate( 'c', $now ),
			'thresholds' => self::thresholds(),
			'backfill' => array(
				'status' => $backfill_status,
				'gaps' => absint( isset( $backfill['gaps'] ) ? $backfill['gaps'] : 0 ),
				'attemptCount' => absint( isset( $backfill['attemptCount'] ) ? $backfill['attemptCount'] : 0 ),
				'lastError' => sanitize_text_field( (string) ( isset( $backfill['lastError'] ) ? $backfill['lastError'] : '' ) ),
			),
			'coverage' => $coverage,
			'reconciliation' => array(
				'status' => $reconciliation_status,
				'failed' => absint( isset( $reconciliation['failed'] ) ? $reconciliation['failed'] : 0 ),
				'ambiguous' => absint( isset( $reconciliation['ambiguous'] ) ? $reconciliation['ambiguous'] : 0 ),
				'lastRunAt' => absint( isset( $reconciliation['lastRunAt'] ) ? $reconciliation['lastRunAt'] : 0 ),
			),
			'window' => array(
				'startedAt' => $window_started_at,
				'startedAtIso' => $window_started_at > 0 ? gmdate( 'c', $window_started_at ) : '',
				'cleanSeconds' => $clean_seconds,
				'baselineComparisons' => $baseline_comparisons,
				'baselineMismatches' => $baseline_mismatches,
				'baselineStateComparisons' => $baseline_state_comparisons,
				'baselineStateMismatches' => $baseline_state_mismatches,
				'baselineWriteFailures' => $baseline_write_failures,
				'baselinePrimaryFallbacks' => $baseline_primary_fallbacks,
				'comparisons' => $window_comparisons,
				'mismatches' => $window_mismatches,
				'stateComparisons' => $window_state_comparisons,
				'stateMismatches' => $window_state_mismatches,
				'writeFailures' => $window_write_failures,
				'primaryFallbacks' => $window_primary_fallbacks,
				'resetCount' => $reset_count,
				'lastResetReason' => $reset_reason,
			),
			'parity' => array(
				'totalComparisons' => $total_comparisons,
				'totalMismatches' => $total_mismatches,
				'updatedAt' => $parity_updated_at,
				'ageSeconds' => $parity_age,
			),
			'stateParity' => array(
				'totalComparisons' => $total_state_comparisons,
				'totalMismatches' => $total_state_mismatches,
				'updatedAt' => $state_parity_updated_at,
				'ageSeconds' => $state_parity_age,
			),
			'dualWriteFailureCount' => $write_failures,
			'primaryFallbackCount' => $primary_fallbacks,
		);
	}

	/** Return the last durable gate checkpoint without mutating anything. @return array */
	public static function get_status() {
		$state = self::read_state();
		if ( empty( $state ) ) {
			return array(
				'eligible' => false,
				'status' => 'not-evaluated',
				'blockers' => array( 'not_evaluated' ),
				'eligibleAt' => 0,
				'evaluatedAt' => 0,
				'thresholds' => self::thresholds(),
				'coverage' => array(),
				'window' => array(),
			);
		}
		$state['thresholds'] = self::thresholds();
		return $state;
	}

	/** Future releases may use only this method to unlock custom-primary. @return bool */
	public static function is_eligible() {
		$status = self::get_status();
		return ! empty( $status['eligible'] ) && 'eligible' === sanitize_key( (string) ( isset( $status['status'] ) ? $status['status'] : '' ) );
	}


	/**
	 * Request-time lease check for Custom-Primary.
	 *
	 * This intentionally avoids calling Storage_Mode or the reconciliation/backfill
	 * helpers so it cannot recurse through effective(). A gate checkpoint is valid
	 * only briefly and is revoked immediately when a new Custom write failure or
	 * guarded-primary fallback is durably observed.
	 *
	 * @return bool
	 */
	public static function is_runtime_eligible() {
		$status = self::get_status();
		if ( self::GATE_VERSION !== absint( isset( $status['gateVersion'] ) ? $status['gateVersion'] : 0 ) ) { return false; }
		if ( empty( $status['eligible'] ) || 'eligible' !== sanitize_key( (string) ( isset( $status['status'] ) ? $status['status'] : '' ) ) ) { return false; }
		$evaluated_at = absint( isset( $status['evaluatedAt'] ) ? $status['evaluatedAt'] : 0 );
		if ( $evaluated_at <= 0 || ( time() - $evaluated_at ) > self::MAX_RUNTIME_GATE_AGE_SECONDS ) { return false; }
		$expected_write_failures = absint( isset( $status['dualWriteFailureCount'] ) ? $status['dualWriteFailureCount'] : 0 );
		$current_write_failures = class_exists( 'Mobo_Core_Storage_Bridge' ) ? Mobo_Core_Storage_Bridge::failure_count() : 0;
		if ( $current_write_failures !== $expected_write_failures ) { return false; }
		$expected_state_mismatches = absint( isset( $status['stateParity']['totalMismatches'] ) ? $status['stateParity']['totalMismatches'] : 0 );
		$current_state_mismatches = class_exists( 'Mobo_Core_Storage_State_Parity' ) ? absint( Mobo_Core_Storage_State_Parity::get_status()['totalMismatches'] ?? 0 ) : 0;
		if ( $current_state_mismatches !== $expected_state_mismatches ) { return false; }
		$expected_fallbacks = absint( isset( $status['primaryFallbackCount'] ) ? $status['primaryFallbackCount'] : 0 );
		$current_fallbacks = class_exists( 'Mobo_Core_Storage_Primary_Read' ) ? Mobo_Core_Storage_Primary_Read::fallback_count() : 0;
		return $current_fallbacks === $expected_fallbacks;
	}

	/** @return array */
	private static function collect_evidence() {
		$backfill = class_exists( 'Mobo_Core_Storage_Backfill' ) ? Mobo_Core_Storage_Backfill::get_status() : array( 'status' => 'unavailable' );
		$parity = class_exists( 'Mobo_Core_Storage_Parity' ) ? Mobo_Core_Storage_Parity::get_status() : array();
		$state_parity = class_exists( 'Mobo_Core_Storage_State_Parity' ) ? Mobo_Core_Storage_State_Parity::get_status() : array();
		$reconciliation = class_exists( 'Mobo_Core_Storage_Reconciler' ) ? Mobo_Core_Storage_Reconciler::get_status() : array( 'status' => 'unavailable' );
		$schema_ready = class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready();
		$effective_mode = class_exists( 'Mobo_Core_Storage_Mode' ) ? Mobo_Core_Storage_Mode::effective() : 'legacy';
		$coverage = array();
		if ( $schema_ready && in_array( $effective_mode, array( Mobo_Core_Storage_Mode::SHADOW_READ, Mobo_Core_Storage_Mode::CUSTOM_PRIMARY, Mobo_Core_Storage_Mode::CUSTOM_ONLY ), true )
			&& 'completed' === sanitize_key( (string) ( isset( $backfill['status'] ) ? $backfill['status'] : '' ) )
			&& 0 === absint( isset( $backfill['gaps'] ) ? $backfill['gaps'] : 0 )
			&& '' === trim( (string) ( isset( $backfill['lastError'] ) ? $backfill['lastError'] : '' ) ) ) {
			$coverage = self::coverage_snapshot();
		}
		return array(
			'schemaReady' => $schema_ready,
			'effectiveMode' => $effective_mode,
			'backfill' => $backfill,
			'coverage' => $coverage,
			'reconciliation' => $reconciliation,
			'parity' => $parity,
			'stateParity' => $state_parity,
			'dualWriteFailureCount' => class_exists( 'Mobo_Core_Storage_Bridge' ) ? Mobo_Core_Storage_Bridge::failure_count() : 0,
			'primaryFallbackCount' => class_exists( 'Mobo_Core_Storage_Primary_Read' ) ? Mobo_Core_Storage_Primary_Read::fallback_count() : 0,
		);
	}

	/** @return array */
	private static function thresholds() {
		return array(
			'minComparisons' => self::MIN_COMPARISONS,
			'minStateComparisons' => self::MIN_STATE_COMPARISONS,
			'minCleanSeconds' => self::MIN_CLEAN_SECONDS,
			'maxParityStalenessSeconds' => self::MAX_PARITY_STALENESS_SECONDS,
			'minEvaluationInterval' => self::MIN_EVALUATION_INTERVAL,
			'maxRuntimeGateAgeSeconds' => self::MAX_RUNTIME_GATE_AGE_SECONDS,
		);
	}

	/** Strict identity coverage between active Legacy map rows and Custom Storage. @return array */
	private static function coverage_snapshot() {
		global $wpdb;
		$map = Mobo_Core_Product_Map::table_name();
		$posts = $wpdb->posts;
		$products = Mobo_Core_Storage_Schema::table_name( 'products' );
		$variants = Mobo_Core_Storage_Schema::table_name( 'variants' );
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return array(); }
		$active_product = "m.object_type = %s AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft')";
		$active_variant = "m.object_type = %s AND p.post_type = 'product_variation' AND p.post_status NOT IN ('trash','auto-draft')";

		$legacy_products = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$map} m INNER JOIN {$posts} p ON p.ID = m.wp_post_id WHERE m.connection_id=%d AND {$active_product}", $connection_id, Mobo_Core_Product_Map::TYPE_PRODUCT ) ) );
		$matched_products = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$map} m INNER JOIN {$posts} p ON p.ID = m.wp_post_id INNER JOIN {$products} c ON c.connection_id=m.connection_id AND c.product_guid = m.remote_guid AND c.woo_product_id = m.wp_post_id WHERE m.connection_id=%d AND {$active_product}", $connection_id, Mobo_Core_Product_Map::TYPE_PRODUCT ) ) );
		$custom_products = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$products} WHERE connection_id=%d", $connection_id ) ) );
		$legacy_variants = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$map} m INNER JOIN {$posts} p ON p.ID = m.wp_post_id WHERE m.connection_id=%d AND {$active_variant}", $connection_id, Mobo_Core_Product_Map::TYPE_VARIATION ) ) );
		$matched_variants = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$map} m INNER JOIN {$posts} p ON p.ID = m.wp_post_id INNER JOIN {$variants} c ON c.connection_id=m.connection_id AND c.variant_guid = m.remote_guid AND c.woo_variation_id = m.wp_post_id WHERE m.connection_id=%d AND {$active_variant}", $connection_id, Mobo_Core_Product_Map::TYPE_VARIATION ) ) );
		$custom_variants = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$variants} WHERE connection_id=%d", $connection_id ) ) );

		return array(
			'legacyProducts' => $legacy_products,
			'matchedProducts' => $matched_products,
			'customProducts' => $custom_products,
			'missingProducts' => max( 0, $legacy_products - $matched_products ),
			'orphanProducts' => max( 0, $custom_products - $matched_products ),
			'legacyVariants' => $legacy_variants,
			'matchedVariants' => $matched_variants,
			'customVariants' => $custom_variants,
			'missingVariants' => max( 0, $legacy_variants - $matched_variants ),
			'orphanVariants' => max( 0, $custom_variants - $matched_variants ),
		);
	}

	/** @return array */
	private static function coverage_blockers( $coverage ) {
		if ( empty( $coverage ) ) { return array( 'coverage_unavailable' ); }
		$blockers = array();
		if ( absint( isset( $coverage['missingProducts'] ) ? $coverage['missingProducts'] : 0 ) > 0 ) { $blockers[] = 'coverage_product_missing'; }
		if ( absint( isset( $coverage['orphanProducts'] ) ? $coverage['orphanProducts'] : 0 ) > 0 ) { $blockers[] = 'coverage_product_orphan'; }
		if ( absint( isset( $coverage['missingVariants'] ) ? $coverage['missingVariants'] : 0 ) > 0 ) { $blockers[] = 'coverage_variant_missing'; }
		if ( absint( isset( $coverage['orphanVariants'] ) ? $coverage['orphanVariants'] : 0 ) > 0 ) { $blockers[] = 'coverage_variant_orphan'; }
		return $blockers;
	}

	/** @return array */
	private static function read_state() {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return array(); }
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return array(); }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$json = (string) $wpdb->get_var( $wpdb->prepare( "SELECT state_json FROM {$table} WHERE connection_id=%d AND worker_name = %s LIMIT 1", $connection_id, self::WORKER_NAME ) );
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** @return bool */
	private static function persist_state( $state ) {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$now = current_time( 'mysql', true );
		$encoded = wp_json_encode( $state );
		if ( ! is_string( $encoded ) ) { $encoded = '{}'; }
		$status = sanitize_key( (string) ( isset( $state['status'] ) ? $state['status'] : 'blocked' ) );
		$blockers = isset( $state['blockers'] ) && is_array( $state['blockers'] ) ? $state['blockers'] : array();
		$first_blocker = ! empty( $blockers ) ? sanitize_key( (string) reset( $blockers ) ) : '';
		$comparisons = absint( isset( $state['window']['comparisons'] ) ? $state['window']['comparisons'] : 0 );
		$failures = absint( isset( $state['window']['mismatches'] ) ? $state['window']['mismatches'] : 0 ) + absint( isset( $state['window']['stateMismatches'] ) ? $state['window']['stateMismatches'] : 0 ) + absint( isset( $state['window']['writeFailures'] ) ? $state['window']['writeFailures'] : 0 );
		$sql = "INSERT INTO {$table}
			(connection_id, worker_name, last_started_at, last_finished_at, last_cursor, consecutive_failures, circuit_state, circuit_open_until, last_error_code, state_json, created_at, updated_at)
			VALUES (%d, %s, %s, %s, %s, %d, %s, NULL, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				last_started_at = VALUES(last_started_at), last_finished_at = VALUES(last_finished_at), last_cursor = VALUES(last_cursor),
				consecutive_failures = VALUES(consecutive_failures), circuit_state = VALUES(circuit_state), circuit_open_until = NULL,
				last_error_code = VALUES(last_error_code), state_json = VALUES(state_json), updated_at = VALUES(updated_at)";
		$result = $wpdb->query( $wpdb->prepare( $sql, $connection_id, self::WORKER_NAME, $now, $now, (string) $comparisons, $failures, $status, $first_blocker, $encoded, $now, $now ) );
		return false !== $result;
	}
}
