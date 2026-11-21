<?php
/**
 * Evidence-only gate for future Legacy write retirement.
 *
 * 10.33.89 keeps this as the global short-lived retirement lease underneath both
 * the separately opted-in Product Map suppression path and per-key operational
 * postmeta readiness. It does not authorize physical Legacy deletion. Any new read
 * dependency, primary fallback, or Custom write failure revokes the lease before
 * another gated Legacy write may be suppressed.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class Mobo_Core_Storage_Legacy_Retirement_Gate {

	const WORKER_NAME = 'storage_legacy_retirement_gate_v1';
	const GATE_VERSION = 2;
	const MIN_IDENTITY_HITS = 2000;
	const MIN_STATE_HITS = 500;
	const MIN_CLEAN_SECONDS = 259200; // 72 hours.
	const MAX_USAGE_STALENESS_SECONDS = 21600; // Six hours.
	const MIN_EVALUATION_INTERVAL = 300;
	const MAX_RUNTIME_GATE_AGE_SECONDS = 900;

	/** Active durable Connection owner for gate state. @return int */
	private static function connection_id() { return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0; }

	/** @return bool */
	public static function should_evaluate() {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Mode' )
			|| Mobo_Core_Storage_Mode::CUSTOM_ONLY !== Mobo_Core_Storage_Mode::effective()
			|| ! class_exists( 'Mobo_Core_Storage_Schema' )
			|| ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
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

	/** Pure deterministic retirement-readiness decision. @return array */
	public static function assess_evidence( $evidence, $previous = array(), $now = 0 ) {
		$evidence = is_array( $evidence ) ? $evidence : array();
		$previous = is_array( $previous ) ? $previous : array();
		$now = absint( $now ); if ( $now <= 0 ) { $now = time(); }

		$identity_hits = absint( isset( $evidence['identityHits'] ) ? $evidence['identityHits'] : 0 );
		$state_hits = absint( isset( $evidence['stateHits'] ) ? $evidence['stateHits'] : 0 );
		$dependencies = absint( isset( $evidence['readDependencyCount'] ) ? $evidence['readDependencyCount'] : 0 );
		$fallbacks = absint( isset( $evidence['primaryFallbackCount'] ) ? $evidence['primaryFallbackCount'] : 0 );
		$write_failures = absint( isset( $evidence['dualWriteFailureCount'] ) ? $evidence['dualWriteFailureCount'] : 0 );
		$legacy_writes = absint( isset( $evidence['legacyWriteCount'] ) ? $evidence['legacyWriteCount'] : 0 );
		$identity_updated_at = sanitize_text_field( (string) ( isset( $evidence['identityUpdatedAt'] ) ? $evidence['identityUpdatedAt'] : '' ) );
		$state_updated_at = sanitize_text_field( (string) ( isset( $evidence['stateUpdatedAt'] ) ? $evidence['stateUpdatedAt'] : '' ) );
		$identity_age = self::age_seconds( $identity_updated_at, $now );
		$state_age = self::age_seconds( $state_updated_at, $now );

		$hard_blockers = array();
		if ( empty( $evidence['schemaReady'] ) ) { $hard_blockers[] = 'schema_unavailable'; }
		if ( Mobo_Core_Storage_Mode::CUSTOM_ONLY !== sanitize_key( (string) ( isset( $evidence['effectiveMode'] ) ? $evidence['effectiveMode'] : '' ) ) ) { $hard_blockers[] = 'custom_only_not_active'; }
		if ( empty( $evidence['customOnlyRuntimeEligible'] ) ) { $hard_blockers[] = 'custom_only_lease_not_eligible'; }

		$window = isset( $previous['window'] ) && is_array( $previous['window'] ) ? $previous['window'] : array();
		$baseline_identity = absint( isset( $window['baselineIdentityHits'] ) ? $window['baselineIdentityHits'] : 0 );
		$baseline_state = absint( isset( $window['baselineStateHits'] ) ? $window['baselineStateHits'] : 0 );
		$baseline_dependencies = absint( isset( $window['baselineReadDependencies'] ) ? $window['baselineReadDependencies'] : 0 );
		$baseline_fallbacks = absint( isset( $window['baselinePrimaryFallbacks'] ) ? $window['baselinePrimaryFallbacks'] : 0 );
		$baseline_failures = absint( isset( $window['baselineWriteFailures'] ) ? $window['baselineWriteFailures'] : 0 );
		$baseline_legacy_writes = absint( isset( $window['baselineLegacyWrites'] ) ? $window['baselineLegacyWrites'] : 0 );
		$started_at = absint( isset( $window['startedAt'] ) ? $window['startedAt'] : 0 );
		$reset_count = absint( isset( $window['resetCount'] ) ? $window['resetCount'] : 0 );
		$reset_reason = '';
		$previous_version = absint( isset( $previous['gateVersion'] ) ? $previous['gateVersion'] : 0 );

		$reset = function() use ( &$baseline_identity, &$baseline_state, &$baseline_dependencies, &$baseline_fallbacks, &$baseline_failures, &$baseline_legacy_writes, $identity_hits, $state_hits, $dependencies, $fallbacks, $write_failures, $legacy_writes ) {
			$baseline_identity = $identity_hits; $baseline_state = $state_hits; $baseline_dependencies = $dependencies;
			$baseline_fallbacks = $fallbacks; $baseline_failures = $write_failures; $baseline_legacy_writes = $legacy_writes;
		};

		if ( ! empty( $hard_blockers ) ) { $started_at = 0; $reset(); }
		elseif ( self::GATE_VERSION !== $previous_version ) { $started_at = $now; $reset(); if ( $previous_version > 0 ) { $reset_count++; } $reset_reason = 'gate_policy_changed'; }
		elseif ( $started_at <= 0 ) { $started_at = $now; $reset(); $reset_reason = 'window_started'; }
		elseif ( $identity_hits < $baseline_identity || $state_hits < $baseline_state || $dependencies < $baseline_dependencies || $fallbacks < $baseline_fallbacks || $write_failures < $baseline_failures || $legacy_writes < $baseline_legacy_writes ) {
			$started_at = $now; $reset(); $reset_count++; $reset_reason = 'counters_reset';
		} elseif ( $dependencies > $baseline_dependencies ) {
			$started_at = $now; $reset(); $reset_count++; $reset_reason = 'legacy_read_dependency';
		} elseif ( $fallbacks > $baseline_fallbacks ) {
			$started_at = $now; $reset(); $reset_count++; $reset_reason = 'custom_primary_fallback';
		} elseif ( $write_failures > $baseline_failures ) {
			$started_at = $now; $reset(); $reset_count++; $reset_reason = 'dual_write_failure';
		}

		$window_identity = max( 0, $identity_hits - $baseline_identity );
		$window_state = max( 0, $state_hits - $baseline_state );
		$window_dependencies = max( 0, $dependencies - $baseline_dependencies );
		$window_fallbacks = max( 0, $fallbacks - $baseline_fallbacks );
		$window_failures = max( 0, $write_failures - $baseline_failures );
		$window_legacy_writes = max( 0, $legacy_writes - $baseline_legacy_writes );
		$clean_seconds = $started_at > 0 ? max( 0, $now - $started_at ) : 0;

		$blockers = $hard_blockers;
		if ( empty( $blockers ) ) {
			if ( $window_identity < self::MIN_IDENTITY_HITS ) { $blockers[] = 'insufficient_custom_only_identity_hits'; }
			if ( $window_state < self::MIN_STATE_HITS ) { $blockers[] = 'insufficient_custom_only_state_hits'; }
			if ( $clean_seconds < self::MIN_CLEAN_SECONDS ) { $blockers[] = 'clean_window_too_short'; }
			if ( $window_dependencies > 0 ) { $blockers[] = 'legacy_read_dependency_in_window'; }
			if ( $window_fallbacks > 0 ) { $blockers[] = 'primary_fallback_in_window'; }
			if ( $window_failures > 0 ) { $blockers[] = 'dual_write_failure_in_window'; }
			if ( null === $identity_age || $identity_age > self::MAX_USAGE_STALENESS_SECONDS ) { $blockers[] = 'identity_usage_stale'; }
			if ( null === $state_age || $state_age > self::MAX_USAGE_STALENESS_SECONDS ) { $blockers[] = 'state_usage_stale'; }
		}

		$eligible = empty( $blockers );
		return array(
			'gateVersion'=>self::GATE_VERSION,
			'eligible'=>$eligible,
			'status'=>$eligible ? 'eligible' : ( ! empty( $hard_blockers ) ? 'blocked' : 'observing' ),
			'blockers'=>array_values( array_unique( $blockers ) ),
			'eligibleAt'=>$eligible ? ( absint( isset( $previous['eligibleAt'] ) ? $previous['eligibleAt'] : 0 ) ?: $now ) : 0,
			'evaluatedAt'=>$now,
			'thresholds'=>self::thresholds(),
			'window'=>array(
				'startedAt'=>$started_at, 'cleanSeconds'=>$clean_seconds,
				'baselineIdentityHits'=>$baseline_identity, 'baselineStateHits'=>$baseline_state,
				'baselineReadDependencies'=>$baseline_dependencies, 'baselinePrimaryFallbacks'=>$baseline_fallbacks,
				'baselineWriteFailures'=>$baseline_failures, 'baselineLegacyWrites'=>$baseline_legacy_writes,
				'identityHits'=>$window_identity, 'stateHits'=>$window_state,
				'readDependencies'=>$window_dependencies, 'primaryFallbacks'=>$window_fallbacks,
				'writeFailures'=>$window_failures, 'legacyWrites'=>$window_legacy_writes,
				'resetCount'=>$reset_count, 'lastResetReason'=>$reset_reason,
			),
			'usage'=>array( 'identityUpdatedAt'=>$identity_updated_at, 'stateUpdatedAt'=>$state_updated_at, 'identityAgeSeconds'=>$identity_age, 'stateAgeSeconds'=>$state_age ),
			'readDependencyCount'=>$dependencies, 'legacyWriteCount'=>$legacy_writes,
			'primaryFallbackCount'=>$fallbacks, 'dualWriteFailureCount'=>$write_failures,
			'note'=>'Eligibility is a global prerequisite for explicitly requested Product Map and per-key operational-postmeta suppression; it never authorizes physical Legacy deletion by itself.',
		);
	}

	/** @return array */
	public static function get_status() {
		$state = self::read_state();
		if ( empty( $state ) ) { return array( 'eligible'=>false, 'status'=>'not-evaluated', 'blockers'=>array('not_evaluated'), 'thresholds'=>self::thresholds(), 'window'=>array() ); }
		$state['thresholds'] = self::thresholds();
		return $state;
	}

	/**
	 * Short-lived request-time lease for controlled Legacy write suppression.
	 *
	 * This deliberately avoids Storage_Mode::effective() so callers that are already
	 * resolving the effective suppression mode cannot recurse through the mode gate.
	 *
	 * @return bool
	 */
	public static function is_runtime_eligible() {
		$status = self::get_status();
		if ( self::GATE_VERSION !== absint( isset( $status['gateVersion'] ) ? $status['gateVersion'] : 0 ) ) { return false; }
		if ( empty( $status['eligible'] ) || 'eligible' !== sanitize_key( (string) ( isset( $status['status'] ) ? $status['status'] : '' ) ) ) { return false; }
		$evaluated_at = absint( isset( $status['evaluatedAt'] ) ? $status['evaluatedAt'] : 0 );
		if ( $evaluated_at <= 0 || ( time() - $evaluated_at ) > self::MAX_RUNTIME_GATE_AGE_SECONDS ) { return false; }
		if ( ! class_exists( 'Mobo_Core_Storage_Custom_Only_Gate' ) || ! Mobo_Core_Storage_Custom_Only_Gate::is_runtime_eligible() ) { return false; }
		if ( class_exists( 'Mobo_Core_Storage_Primary_Read' ) && ! Mobo_Core_Storage_Primary_Read::request_allowed() ) { return false; }
		if ( class_exists( 'Mobo_Core_Storage_Legacy_Dependency' ) ) {
			$request = Mobo_Core_Storage_Legacy_Dependency::get_request_status();
			if ( absint( isset( $request['readDependencies'] ) ? $request['readDependencies'] : 0 ) > 0 ) { return false; }
			$durable = Mobo_Core_Storage_Legacy_Dependency::get_status();
			if ( absint( isset( $durable['readDependencyCount'] ) ? $durable['readDependencyCount'] : 0 ) !== absint( isset( $status['readDependencyCount'] ) ? $status['readDependencyCount'] : 0 ) ) { return false; }
		}
		$current_fallbacks = class_exists( 'Mobo_Core_Storage_Primary_Read' ) ? Mobo_Core_Storage_Primary_Read::fallback_count() : 0;
		if ( $current_fallbacks !== absint( isset( $status['primaryFallbackCount'] ) ? $status['primaryFallbackCount'] : 0 ) ) { return false; }
		$current_failures = class_exists( 'Mobo_Core_Storage_Bridge' ) ? Mobo_Core_Storage_Bridge::failure_count() : 0;
		return $current_failures === absint( isset( $status['dualWriteFailureCount'] ) ? $status['dualWriteFailureCount'] : 0 );
	}

	/** @return array */
	private static function collect_evidence() {
		$usage = class_exists( 'Mobo_Core_Storage_Primary_Usage' ) ? Mobo_Core_Storage_Primary_Usage::get_status() : array();
		$legacy = class_exists( 'Mobo_Core_Storage_Legacy_Dependency' ) ? Mobo_Core_Storage_Legacy_Dependency::get_status() : array();
		return array(
			'schemaReady'=>class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready(),
			'effectiveMode'=>class_exists( 'Mobo_Core_Storage_Mode' ) ? Mobo_Core_Storage_Mode::effective() : 'legacy',
			'customOnlyRuntimeEligible'=>class_exists( 'Mobo_Core_Storage_Custom_Only_Gate' ) && Mobo_Core_Storage_Custom_Only_Gate::is_runtime_eligible(),
			'identityHits'=>absint( isset( $usage['identityHits'] ) ? $usage['identityHits'] : 0 ),
			'stateHits'=>absint( isset( $usage['stateHits'] ) ? $usage['stateHits'] : 0 ),
			'identityUpdatedAt'=>sanitize_text_field( (string) ( isset( $usage['identityUpdatedAt'] ) ? $usage['identityUpdatedAt'] : '' ) ),
			'stateUpdatedAt'=>sanitize_text_field( (string) ( isset( $usage['stateUpdatedAt'] ) ? $usage['stateUpdatedAt'] : '' ) ),
			'readDependencyCount'=>absint( isset( $legacy['readDependencyCount'] ) ? $legacy['readDependencyCount'] : 0 ),
			'legacyWriteCount'=>absint( isset( $legacy['legacyWriteCount'] ) ? $legacy['legacyWriteCount'] : 0 ),
			'primaryFallbackCount'=>class_exists( 'Mobo_Core_Storage_Primary_Read' ) ? Mobo_Core_Storage_Primary_Read::fallback_count() : 0,
			'dualWriteFailureCount'=>class_exists( 'Mobo_Core_Storage_Bridge' ) ? Mobo_Core_Storage_Bridge::failure_count() : 0,
		);
	}

	/** @return array */
	private static function thresholds() { return array( 'minIdentityHits'=>self::MIN_IDENTITY_HITS, 'minStateHits'=>self::MIN_STATE_HITS, 'minCleanSeconds'=>self::MIN_CLEAN_SECONDS, 'maxUsageStalenessSeconds'=>self::MAX_USAGE_STALENESS_SECONDS, 'minEvaluationInterval'=>self::MIN_EVALUATION_INTERVAL, 'maxRuntimeGateAgeSeconds'=>self::MAX_RUNTIME_GATE_AGE_SECONDS ); }

	/** @return int|null */
	private static function age_seconds( $value, $now ) { $value=trim((string)$value); if(''===$value){return null;} $ts=strtotime($value.(false===strpos($value,'T')?' UTC':'')); return $ts?max(0,absint($now)-$ts):null; }

	/** @return array */
	private static function read_state() { global $wpdb; if(!class_exists('Mobo_Core_Storage_Schema')||!Mobo_Core_Storage_Schema::is_ready()){return array();} $connection_id=self::connection_id(); if($connection_id<=0){return array();} $table=Mobo_Core_Storage_Schema::table_name('runtime'); $json=(string)$wpdb->get_var($wpdb->prepare("SELECT state_json FROM {$table} WHERE connection_id=%d AND worker_name=%s LIMIT 1",$connection_id,self::WORKER_NAME)); $decoded=json_decode($json,true); return is_array($decoded)?$decoded:array(); }

	/** @return bool */
	private static function persist_state( $state ) {
		global $wpdb; if(!class_exists('Mobo_Core_Storage_Schema')||!Mobo_Core_Storage_Schema::is_ready()){return false;}
		$connection_id=self::connection_id(); if($connection_id<=0){return false;}
		$table=Mobo_Core_Storage_Schema::table_name('runtime'); $now=current_time('mysql',true); $encoded=wp_json_encode($state); if(!is_string($encoded)){$encoded='{}';}
		$status=sanitize_key((string)(isset($state['status'])?$state['status']:'blocked')); $blockers=isset($state['blockers'])&&is_array($state['blockers'])?$state['blockers']:array(); $first=!empty($blockers)?sanitize_key((string)reset($blockers)):'';
		$dependencies=absint(isset($state['window']['readDependencies'])?$state['window']['readDependencies']:0); $fallbacks=absint(isset($state['window']['primaryFallbacks'])?$state['window']['primaryFallbacks']:0); $failures=absint(isset($state['window']['writeFailures'])?$state['window']['writeFailures']:0);
		$sql="INSERT INTO {$table} (connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,circuit_open_until,last_error_code,state_json,created_at,updated_at) VALUES (%d,%s,%s,%s,'',%d,%s,NULL,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE last_started_at=VALUES(last_started_at),last_finished_at=VALUES(last_finished_at),consecutive_failures=VALUES(consecutive_failures),circuit_state=VALUES(circuit_state),circuit_open_until=NULL,last_error_code=VALUES(last_error_code),state_json=VALUES(state_json),updated_at=VALUES(updated_at)";
		return false!==$wpdb->query($wpdb->prepare($sql,$connection_id,self::WORKER_NAME,$now,$now,$dependencies+$fallbacks+$failures,$status,$first,$encoded,$now,$now));
	}
}
