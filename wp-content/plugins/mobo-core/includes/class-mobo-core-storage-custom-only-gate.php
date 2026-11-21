<?php
/**
 * Evidence gate for future Custom-Only cutover.
 *
 * 10.33.81 honors Custom-only only through this short-lived lease. The lease is
 * subordinate to the Promotion Gate and is revoked immediately by any new primary
 * fallback, Custom write failure, stale evidence, or request-local primary breaker.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class Mobo_Core_Storage_Custom_Only_Gate {

	const WORKER_NAME = 'storage_custom_only_gate_v1';
	const GATE_VERSION = 2;
	const MIN_IDENTITY_HITS = 1000;
	const MIN_STATE_HITS = 250;
	const MIN_CLEAN_SECONDS = 86400; // 24 hours of real Custom-Primary operation.
	const MAX_USAGE_STALENESS_SECONDS = 21600; // Require traffic evidence within six hours.
	const MIN_EVALUATION_INTERVAL = 300;
	const MAX_RUNTIME_GATE_AGE_SECONDS = 900;

	/** Active durable Connection owner for gate state. @return int */
	private static function connection_id() { return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0; }

	/** @return bool */
	public static function should_evaluate() {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Mode' )
			|| ! in_array( Mobo_Core_Storage_Mode::effective(), array( Mobo_Core_Storage_Mode::CUSTOM_PRIMARY, Mobo_Core_Storage_Mode::CUSTOM_ONLY ), true )
			|| ! class_exists( 'Mobo_Core_Storage_Schema' )
			|| ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}
		$connection_id = self::connection_id(); if ( $connection_id <= 0 ) { return false; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$updated_at = (string) $wpdb->get_var( $wpdb->prepare( "SELECT updated_at FROM {$table} WHERE connection_id=%d AND worker_name=%s LIMIT 1", $connection_id, self::WORKER_NAME ) );
		if ( '' === trim( $updated_at ) ) { return true; }
		$updated_ts = strtotime( $updated_at . ' UTC' );
		return ! $updated_ts || ( time() - $updated_ts ) >= self::MIN_EVALUATION_INTERVAL;
	}

	/** @return array */
	public static function evaluate( $force = false ) {
		if ( ! $force && ! self::should_evaluate() ) { return self::get_status(); }
		$state = self::assess_evidence( self::collect_evidence(), self::read_state(), time() );
		self::persist_state( $state );
		return $state;
	}

	/**
	 * Pure deterministic readiness decision for adversarial tests.
	 *
	 * @param array $evidence Current counters/status.
	 * @param array $previous Previous persisted gate state.
	 * @param int   $now Unix timestamp.
	 * @return array
	 */
	public static function assess_evidence( $evidence, $previous = array(), $now = 0 ) {
		$evidence = is_array( $evidence ) ? $evidence : array();
		$previous = is_array( $previous ) ? $previous : array();
		$now = absint( $now );
		if ( $now <= 0 ) { $now = time(); }

		$identity_hits = absint( isset( $evidence['identityHits'] ) ? $evidence['identityHits'] : 0 );
		$state_hits = absint( isset( $evidence['stateHits'] ) ? $evidence['stateHits'] : 0 );
		$fallbacks = absint( isset( $evidence['primaryFallbackCount'] ) ? $evidence['primaryFallbackCount'] : 0 );
		$write_failures = absint( isset( $evidence['dualWriteFailureCount'] ) ? $evidence['dualWriteFailureCount'] : 0 );
		$identity_updated_at = sanitize_text_field( (string) ( isset( $evidence['identityUpdatedAt'] ) ? $evidence['identityUpdatedAt'] : '' ) );
		$state_updated_at = sanitize_text_field( (string) ( isset( $evidence['stateUpdatedAt'] ) ? $evidence['stateUpdatedAt'] : '' ) );
		$identity_age = self::age_seconds( $identity_updated_at, $now );
		$state_age = self::age_seconds( $state_updated_at, $now );

		$hard_blockers = array();
		if ( empty( $evidence['schemaReady'] ) ) { $hard_blockers[] = 'schema_unavailable'; }
		if ( ! in_array( sanitize_key( (string) ( isset( $evidence['effectiveMode'] ) ? $evidence['effectiveMode'] : '' ) ), array( Mobo_Core_Storage_Mode::CUSTOM_PRIMARY, Mobo_Core_Storage_Mode::CUSTOM_ONLY ), true ) ) { $hard_blockers[] = 'custom_primary_not_active'; }
		if ( empty( $evidence['promotionRuntimeEligible'] ) ) { $hard_blockers[] = 'promotion_lease_not_eligible'; }

		$window = isset( $previous['window'] ) && is_array( $previous['window'] ) ? $previous['window'] : array();
		$baseline_identity = absint( isset( $window['baselineIdentityHits'] ) ? $window['baselineIdentityHits'] : 0 );
		$baseline_state = absint( isset( $window['baselineStateHits'] ) ? $window['baselineStateHits'] : 0 );
		$baseline_fallbacks = absint( isset( $window['baselinePrimaryFallbacks'] ) ? $window['baselinePrimaryFallbacks'] : 0 );
		$baseline_write_failures = absint( isset( $window['baselineWriteFailures'] ) ? $window['baselineWriteFailures'] : 0 );
		$started_at = absint( isset( $window['startedAt'] ) ? $window['startedAt'] : 0 );
		$reset_count = absint( isset( $window['resetCount'] ) ? $window['resetCount'] : 0 );
		$reset_reason = '';
		$previous_version = absint( isset( $previous['gateVersion'] ) ? $previous['gateVersion'] : 0 );

		$reset_baselines = function() use ( &$baseline_identity, &$baseline_state, &$baseline_fallbacks, &$baseline_write_failures, $identity_hits, $state_hits, $fallbacks, $write_failures ) {
			$baseline_identity = $identity_hits;
			$baseline_state = $state_hits;
			$baseline_fallbacks = $fallbacks;
			$baseline_write_failures = $write_failures;
		};

		if ( ! empty( $hard_blockers ) ) {
			$started_at = 0;
			$reset_baselines();
		} elseif ( self::GATE_VERSION !== $previous_version ) {
			$started_at = $now;
			$reset_baselines();
			if ( $previous_version > 0 ) { $reset_count++; }
			$reset_reason = 'gate_policy_changed';
		} elseif ( $started_at <= 0 ) {
			$started_at = $now;
			$reset_baselines();
			$reset_reason = 'window_started';
		} elseif ( $identity_hits < $baseline_identity || $state_hits < $baseline_state || $fallbacks < $baseline_fallbacks || $write_failures < $baseline_write_failures ) {
			$started_at = $now;
			$reset_baselines();
			$reset_count++;
			$reset_reason = 'counters_reset';
		} elseif ( $fallbacks > $baseline_fallbacks ) {
			$started_at = $now;
			$reset_baselines();
			$reset_count++;
			$reset_reason = 'custom_primary_fallback';
		} elseif ( $write_failures > $baseline_write_failures ) {
			$started_at = $now;
			$reset_baselines();
			$reset_count++;
			$reset_reason = 'dual_write_failure';
		}

		$window_identity = max( 0, $identity_hits - $baseline_identity );
		$window_state = max( 0, $state_hits - $baseline_state );
		$window_fallbacks = max( 0, $fallbacks - $baseline_fallbacks );
		$window_write_failures = max( 0, $write_failures - $baseline_write_failures );
		$clean_seconds = $started_at > 0 ? max( 0, $now - $started_at ) : 0;

		$blockers = $hard_blockers;
		if ( empty( $blockers ) ) {
			if ( $window_identity < self::MIN_IDENTITY_HITS ) { $blockers[] = 'insufficient_primary_identity_hits'; }
			if ( $window_state < self::MIN_STATE_HITS ) { $blockers[] = 'insufficient_primary_state_hits'; }
			if ( $clean_seconds < self::MIN_CLEAN_SECONDS ) { $blockers[] = 'clean_window_too_short'; }
			if ( $window_fallbacks > 0 ) { $blockers[] = 'primary_fallback_in_window'; }
			if ( $window_write_failures > 0 ) { $blockers[] = 'dual_write_failure_in_window'; }
			if ( null === $identity_age || $identity_age > self::MAX_USAGE_STALENESS_SECONDS ) { $blockers[] = 'identity_usage_stale'; }
			if ( null === $state_age || $state_age > self::MAX_USAGE_STALENESS_SECONDS ) { $blockers[] = 'state_usage_stale'; }
		}

		$eligible = empty( $blockers );
		$status = $eligible ? 'eligible' : ( ! empty( $hard_blockers ) ? 'blocked' : 'observing' );
		$eligible_at = $eligible ? ( absint( isset( $previous['eligibleAt'] ) ? $previous['eligibleAt'] : 0 ) ?: $now ) : 0;

		return array(
			'gateVersion' => self::GATE_VERSION,
			'eligible' => $eligible,
			'status' => $status,
			'blockers' => array_values( array_unique( $blockers ) ),
			'eligibleAt' => $eligible_at,
			'evaluatedAt' => $now,
			'thresholds' => self::thresholds(),
			'window' => array(
				'startedAt' => $started_at,
				'cleanSeconds' => $clean_seconds,
				'baselineIdentityHits' => $baseline_identity,
				'baselineStateHits' => $baseline_state,
				'baselinePrimaryFallbacks' => $baseline_fallbacks,
				'baselineWriteFailures' => $baseline_write_failures,
				'identityHits' => $window_identity,
				'stateHits' => $window_state,
				'primaryFallbacks' => $window_fallbacks,
				'writeFailures' => $window_write_failures,
				'resetCount' => $reset_count,
				'lastResetReason' => $reset_reason,
			),
			'usage' => array(
				'totalIdentityHits' => $identity_hits,
				'totalStateHits' => $state_hits,
				'identityUpdatedAt' => $identity_updated_at,
				'stateUpdatedAt' => $state_updated_at,
				'identityAgeSeconds' => $identity_age,
				'stateAgeSeconds' => $state_age,
			),
			'primaryFallbackCount' => $fallbacks,
			'dualWriteFailureCount' => $write_failures,
		);
	}

	/** @return array */
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
				'window' => array(),
				'usage' => array(),
			);
		}
		$state['thresholds'] = self::thresholds();
		return $state;
	}

	/** Controlled Custom-Only cutover uses this short-lived lease check. @return bool */
	public static function is_runtime_eligible() {
		$status = self::get_status();
		if ( self::GATE_VERSION !== absint( isset( $status['gateVersion'] ) ? $status['gateVersion'] : 0 ) ) { return false; }
		if ( empty( $status['eligible'] ) || 'eligible' !== sanitize_key( (string) ( isset( $status['status'] ) ? $status['status'] : '' ) ) ) { return false; }
		$evaluated_at = absint( isset( $status['evaluatedAt'] ) ? $status['evaluatedAt'] : 0 );
		if ( $evaluated_at <= 0 || ( time() - $evaluated_at ) > self::MAX_RUNTIME_GATE_AGE_SECONDS ) { return false; }
		if ( ! class_exists( 'Mobo_Core_Storage_Promotion_Gate' ) || ! Mobo_Core_Storage_Promotion_Gate::is_runtime_eligible() ) { return false; }
		if ( class_exists( 'Mobo_Core_Storage_Primary_Read' ) && ! Mobo_Core_Storage_Primary_Read::request_allowed() ) { return false; }
		$expected_fallbacks = absint( isset( $status['primaryFallbackCount'] ) ? $status['primaryFallbackCount'] : 0 );
		$current_fallbacks = class_exists( 'Mobo_Core_Storage_Primary_Read' ) ? Mobo_Core_Storage_Primary_Read::fallback_count() : 0;
		if ( $current_fallbacks !== $expected_fallbacks ) { return false; }
		$expected_write_failures = absint( isset( $status['dualWriteFailureCount'] ) ? $status['dualWriteFailureCount'] : 0 );
		$current_write_failures = class_exists( 'Mobo_Core_Storage_Bridge' ) ? Mobo_Core_Storage_Bridge::failure_count() : 0;
		return $current_write_failures === $expected_write_failures;
	}

	/** @return array */
	private static function collect_evidence() {
		$usage = class_exists( 'Mobo_Core_Storage_Primary_Usage' ) ? Mobo_Core_Storage_Primary_Usage::get_status() : array();
		return array(
			'schemaReady' => class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready(),
			'effectiveMode' => class_exists( 'Mobo_Core_Storage_Mode' ) ? Mobo_Core_Storage_Mode::effective() : 'legacy',
			'promotionRuntimeEligible' => class_exists( 'Mobo_Core_Storage_Promotion_Gate' ) && Mobo_Core_Storage_Promotion_Gate::is_runtime_eligible(),
			'identityHits' => absint( isset( $usage['identityHits'] ) ? $usage['identityHits'] : 0 ),
			'stateHits' => absint( isset( $usage['stateHits'] ) ? $usage['stateHits'] : 0 ),
			'identityUpdatedAt' => sanitize_text_field( (string) ( isset( $usage['identityUpdatedAt'] ) ? $usage['identityUpdatedAt'] : '' ) ),
			'stateUpdatedAt' => sanitize_text_field( (string) ( isset( $usage['stateUpdatedAt'] ) ? $usage['stateUpdatedAt'] : '' ) ),
			'primaryFallbackCount' => class_exists( 'Mobo_Core_Storage_Primary_Read' ) ? Mobo_Core_Storage_Primary_Read::fallback_count() : 0,
			'dualWriteFailureCount' => class_exists( 'Mobo_Core_Storage_Bridge' ) ? Mobo_Core_Storage_Bridge::failure_count() : 0,
		);
	}

	/** @return array */
	private static function thresholds() {
		return array(
			'minIdentityHits' => self::MIN_IDENTITY_HITS,
			'minStateHits' => self::MIN_STATE_HITS,
			'minCleanSeconds' => self::MIN_CLEAN_SECONDS,
			'maxUsageStalenessSeconds' => self::MAX_USAGE_STALENESS_SECONDS,
			'minEvaluationInterval' => self::MIN_EVALUATION_INTERVAL,
			'maxRuntimeGateAgeSeconds' => self::MAX_RUNTIME_GATE_AGE_SECONDS,
		);
	}

	/** @return int|null */
	private static function age_seconds( $mysql_or_iso, $now ) {
		$value = trim( (string) $mysql_or_iso );
		if ( '' === $value ) { return null; }
		$ts = strtotime( $value . ( false === strpos( $value, 'T' ) ? ' UTC' : '' ) );
		if ( ! $ts ) { return null; }
		return max( 0, absint( $now ) - $ts );
	}

	/** @return array */
	private static function read_state() {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return array(); }
		$connection_id = self::connection_id(); if ( $connection_id <= 0 ) { return array(); }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$json = (string) $wpdb->get_var( $wpdb->prepare( "SELECT state_json FROM {$table} WHERE connection_id=%d AND worker_name=%s LIMIT 1", $connection_id, self::WORKER_NAME ) );
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** @return bool */
	private static function persist_state( $state ) {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$connection_id = self::connection_id(); if ( $connection_id <= 0 ) { return false; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$now = current_time( 'mysql', true );
		$encoded = wp_json_encode( $state );
		if ( ! is_string( $encoded ) ) { $encoded = '{}'; }
		$status = sanitize_key( (string) ( isset( $state['status'] ) ? $state['status'] : 'blocked' ) );
		$blockers = isset( $state['blockers'] ) && is_array( $state['blockers'] ) ? $state['blockers'] : array();
		$first_blocker = ! empty( $blockers ) ? sanitize_key( (string) reset( $blockers ) ) : '';
		$identity_hits = absint( isset( $state['window']['identityHits'] ) ? $state['window']['identityHits'] : 0 );
		$failures = absint( isset( $state['window']['primaryFallbacks'] ) ? $state['window']['primaryFallbacks'] : 0 ) + absint( isset( $state['window']['writeFailures'] ) ? $state['window']['writeFailures'] : 0 );
		$sql = "INSERT INTO {$table}
			(connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,circuit_open_until,last_error_code,state_json,created_at,updated_at)
			VALUES (%d,%s,%s,%s,%s,%d,%s,NULL,%s,%s,%s,%s)
			ON DUPLICATE KEY UPDATE
				last_started_at=VALUES(last_started_at),last_finished_at=VALUES(last_finished_at),last_cursor=VALUES(last_cursor),
				consecutive_failures=VALUES(consecutive_failures),circuit_state=VALUES(circuit_state),circuit_open_until=NULL,
				last_error_code=VALUES(last_error_code),state_json=VALUES(state_json),updated_at=VALUES(updated_at)";
		return false !== $wpdb->query( $wpdb->prepare( $sql, $connection_id, self::WORKER_NAME, $now, $now, (string) $identity_hits, $failures, $status, $first_blocker, $encoded, $now, $now ) );
	}
}
