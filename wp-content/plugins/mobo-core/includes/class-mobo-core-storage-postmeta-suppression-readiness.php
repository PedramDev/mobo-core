<?php
/**
 * Per-key readiness evidence for future operational-postmeta write suppression.
 *
 * Release 10.33.89 extends the sustained per-key lease to all seven operational
 * metadata keys, including mobo_sync_incomplete. Initial qualification still
 * requires Custom reads, exercised Legacy writes and a clean window. Once an
 * eligible key is actively suppressed, fresh successful suppression telemetry may
 * keep the lease alive without pretending suppressed writes are still Legacy writes.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class Mobo_Core_Storage_Postmeta_Suppression_Readiness {

	const GATE_WORKER = 'storage_postmeta_suppression_readiness_gate_v4';
	const GATE_VERSION = 4;
	const MIN_CUSTOM_READS = 250;
	const MIN_LEGACY_WRITES = 50;
	const MIN_CLEAN_SECONDS = 259200; // 72 hours.
	const MAX_USAGE_STALENESS_SECONDS = 21600; // Six hours.
	const MIN_EVALUATION_INTERVAL = 300;
	const MAX_RUNTIME_GATE_AGE_SECONDS = 900;
	const MAX_UNIQUE_EVENTS_PER_REQUEST = 128;

	/** @var array<int,array{custom:array,legacy_reads:array,legacy_writes:array}> */
	private static $buckets = array();
	/** @var bool */ private static $shutdown_registered = false;

	private static function connection_id(){return class_exists('Mobo_Core_Connection_Context')?absint(Mobo_Core_Connection_Context::get_effective_connection_id()):0;}
	private static function ensure_bucket($cid){if(!isset(self::$buckets[$cid])){self::$buckets[$cid]=array('custom'=>array(),'legacy_reads'=>array(),'legacy_writes'=>array());}}
	private static function current_bucket(){ $cid=self::connection_id(); self::ensure_bucket($cid); return isset(self::$buckets[$cid])?self::$buckets[$cid]:array('custom'=>array(),'legacy_reads'=>array(),'legacy_writes'=>array()); }

	/** @return array<string,string> meta key => compact worker slug */
	public static function managed_keys() {
		return array(
			'_mobo_product_seen_revision'    => 'product_seen',
			'_mobo_product_applied_revision' => 'product_applied',
			'_mobo_variant_seen_revision'    => 'variant_seen',
			'_mobo_variant_applied_revision' => 'variant_applied',
			'_mobo_product_source_hash'      => 'product_hash',
			'_mobo_variant_source_hash'      => 'variant_hash',
			'mobo_sync_incomplete'           => 'sync_incomplete',
		);
	}

	/** @return bool */
	public static function is_managed_key( $meta_key ) {
		$keys = self::managed_keys();
		return isset( $keys[ (string) $meta_key ] );
	}

	/** Record one successful Custom-Only operational-state read. @return void */
	public static function record_custom_read($object_id,$meta_key){$cid=self::connection_id();if($cid<=0||!self::observing()||!self::is_managed_key($meta_key)){return;}self::ensure_bucket($cid);if(count(self::$buckets[$cid]['custom'])>=self::MAX_UNIQUE_EVENTS_PER_REQUEST){return;}$object_id=absint($object_id);if($object_id<=0||!self::managed_object($object_id)){return;}$key=$object_id.'|'.(string)$meta_key;self::$buckets[$cid]['custom'][$key]=array('objectId'=>$object_id,'metaKey'=>(string)$meta_key);self::register_shutdown();}

	/** Record one read that still needed Legacy for this exact operational key. @return void */
	public static function record_legacy_read_dependency($reason,$object_id,$meta_key){$cid=self::connection_id();if($cid<=0||!self::observing()||!self::is_managed_key($meta_key)){return;}self::ensure_bucket($cid);if(count(self::$buckets[$cid]['legacy_reads'])>=self::MAX_UNIQUE_EVENTS_PER_REQUEST){return;}$object_id=absint($object_id);if($object_id<=0||!self::managed_object($object_id)){return;}$reason=sanitize_key((string)$reason);if(''===$reason){$reason='legacy_read_dependency';}$key=$object_id.'|'.(string)$meta_key.'|'.$reason;self::$buckets[$cid]['legacy_reads'][$key]=array('objectId'=>$object_id,'metaKey'=>(string)$meta_key,'reason'=>$reason);self::register_shutdown();}

	/** Record one retained Legacy write for this exact operational key. @return void */
	public static function record_legacy_write($object_id,$meta_key){$cid=self::connection_id();if($cid<=0||!self::observing()||!self::is_managed_key($meta_key)){return;}self::ensure_bucket($cid);if(count(self::$buckets[$cid]['legacy_writes'])>=self::MAX_UNIQUE_EVENTS_PER_REQUEST){return;}$object_id=absint($object_id);if($object_id<=0||!self::managed_object($object_id)){return;}$key=$object_id.'|'.(string)$meta_key;self::$buckets[$cid]['legacy_writes'][$key]=array('objectId'=>$object_id,'metaKey'=>(string)$meta_key);self::register_shutdown();}

	/** Persist bounded metric increments per durable Connection. @return bool */
	public static function flush(){if(empty(self::$buckets)){return true;}if(!class_exists('Mobo_Core_Storage_Schema')||!Mobo_Core_Storage_Schema::is_ready()){self::clear_request_buffer();return false;}$ok=true;foreach(self::$buckets as $cid=>$b){$cid=absint($cid);if($cid<=0){continue;}$ok=self::increment_metric($cid,'read',$b['custom'])&&$ok;$ok=self::increment_metric($cid,'dependency',$b['legacy_reads'])&&$ok;$ok=self::increment_metric($cid,'write',$b['legacy_writes'])&&$ok;}self::clear_request_buffer();return $ok;}

	/** Request-local diagnostics for current Connection. @return array */
	public static function get_request_status(){$cid=self::connection_id();$b=isset(self::$buckets[$cid])?self::$buckets[$cid]:array('custom'=>array(),'legacy_reads'=>array(),'legacy_writes'=>array());return array('connectionId'=>$cid,'customReads'=>count($b['custom']),'legacyReadDependencies'=>count($b['legacy_reads']),'legacyWrites'=>count($b['legacy_writes']),'maxUniqueEvents'=>self::MAX_UNIQUE_EVENTS_PER_REQUEST);}

	/** Durable per-key metric totals in one bounded query. @return array */
	public static function get_metrics_status() {
		global $wpdb;
		$keys = self::managed_keys();
		$out = array();
		foreach ( $keys as $meta_key => $slug ) {
			$out[ $meta_key ] = array(
				'customReads'=>0, 'customReadsUpdatedAt'=>'',
				'legacyReadDependencies'=>0, 'legacyReadUpdatedAt'=>'',
				'legacyWrites'=>0, 'legacyWritesUpdatedAt'=>'',
			);
		}
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return $out; }
		$worker_map = array();
		foreach ( $keys as $meta_key => $slug ) {
			foreach ( array( 'read', 'dependency', 'write' ) as $metric ) {
				$worker_map[ self::metric_worker( $metric, $slug ) ] = array( $meta_key, $metric );
			}
		}
		$connection_id=self::connection_id(); if($connection_id<=0){return $out;}
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$names = array_keys( $worker_map );
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$args=array_merge(array($connection_id),$names); $sql = $wpdb->prepare( "SELECT worker_name,consecutive_failures,updated_at FROM {$table} WHERE connection_id=%d AND worker_name IN ({$placeholders})", $args );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = (string) ( isset( $row['worker_name'] ) ? $row['worker_name'] : '' );
			if ( ! isset( $worker_map[ $name ] ) ) { continue; }
			list( $meta_key, $metric ) = $worker_map[ $name ];
			$count = absint( isset( $row['consecutive_failures'] ) ? $row['consecutive_failures'] : 0 );
			$updated = sanitize_text_field( (string) ( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ) );
			if ( 'read' === $metric ) { $out[ $meta_key ]['customReads']=$count; $out[ $meta_key ]['customReadsUpdatedAt']=$updated; }
			elseif ( 'dependency' === $metric ) { $out[ $meta_key ]['legacyReadDependencies']=$count; $out[ $meta_key ]['legacyReadUpdatedAt']=$updated; }
			else { $out[ $meta_key ]['legacyWrites']=$count; $out[ $meta_key ]['legacyWritesUpdatedAt']=$updated; }
		}
		return $out;
	}

	/** @return bool */
	public static function should_evaluate() {
		global $wpdb;
		if ( ! self::observing() || ! class_exists( 'Mobo_Core_Storage_Mode' ) || Mobo_Core_Storage_Mode::CUSTOM_ONLY !== Mobo_Core_Storage_Mode::effective()
			|| ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$connection_id=self::connection_id(); if($connection_id<=0){return false;}
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$updated_at = (string) $wpdb->get_var( $wpdb->prepare( "SELECT updated_at FROM {$table} WHERE connection_id=%d AND worker_name=%s LIMIT 1", $connection_id, self::GATE_WORKER ) );
		if ( '' === trim( $updated_at ) ) { return true; }
		$ts = strtotime( $updated_at . ' UTC' );
		return ! $ts || ( time() - $ts ) >= self::MIN_EVALUATION_INTERVAL;
	}

	/** @return array */
	public static function evaluate( $force = false ) {
		if ( ! $force && ! self::should_evaluate() ) { return self::get_status(); }
		$state = self::assess_evidence( self::collect_evidence(), self::read_gate_state(), time() );
		self::persist_gate_state( $state );
		return $state;
	}

	/**
	 * Pure deterministic per-key readiness decision.
	 *
	 * @param array $evidence Current cumulative counters/status.
	 * @param array $previous Previous persisted gate state.
	 * @param int   $now Unix time.
	 * @return array
	 */
	public static function assess_evidence( $evidence, $previous = array(), $now = 0 ) {
		$evidence = is_array( $evidence ) ? $evidence : array();
		$previous = is_array( $previous ) ? $previous : array();
		$now = absint( $now ); if ( $now <= 0 ) { $now = time(); }
		$previous_version = absint( isset( $previous['gateVersion'] ) ? $previous['gateVersion'] : 0 );
		$fallbacks = absint( isset( $evidence['primaryFallbackCount'] ) ? $evidence['primaryFallbackCount'] : 0 );
		$failures = absint( isset( $evidence['dualWriteFailureCount'] ) ? $evidence['dualWriteFailureCount'] : 0 );
		$global = isset( $previous['globalWindow'] ) && is_array( $previous['globalWindow'] ) ? $previous['globalWindow'] : array();
		$baseline_fallbacks = absint( isset( $global['baselinePrimaryFallbacks'] ) ? $global['baselinePrimaryFallbacks'] : 0 );
		$baseline_failures = absint( isset( $global['baselineWriteFailures'] ) ? $global['baselineWriteFailures'] : 0 );
		$global_reset_count = absint( isset( $global['resetCount'] ) ? $global['resetCount'] : 0 );
		$global_reset_reason = '';

		$global_blockers = array();
		if ( empty( $evidence['schemaReady'] ) ) { $global_blockers[] = 'schema_unavailable'; }
		if ( ! class_exists( 'Mobo_Core_Storage_Mode' ) || Mobo_Core_Storage_Mode::CUSTOM_ONLY !== sanitize_key( (string) ( isset( $evidence['effectiveMode'] ) ? $evidence['effectiveMode'] : '' ) ) ) { $global_blockers[] = 'custom_only_not_active'; }
		if ( empty( $evidence['retirementRuntimeEligible'] ) ) { $global_blockers[] = 'legacy_retirement_lease_not_eligible'; }

		$global_reset = false;
		if ( self::GATE_VERSION !== $previous_version ) { $global_reset=true; $global_reset_reason='gate_policy_changed'; if($previous_version>0){$global_reset_count++;} }
		elseif ( $fallbacks < $baseline_fallbacks || $failures < $baseline_failures ) { $global_reset=true; $global_reset_reason='counters_reset'; $global_reset_count++; }
		elseif ( $fallbacks > $baseline_fallbacks ) { $global_reset=true; $global_reset_reason='custom_primary_fallback'; $global_reset_count++; }
		elseif ( $failures > $baseline_failures ) { $global_reset=true; $global_reset_reason='dual_write_failure'; $global_reset_count++; }
		if ( $global_reset || $previous_version <= 0 ) { $baseline_fallbacks=$fallbacks; $baseline_failures=$failures; }

		$metric_keys = isset( $evidence['keys'] ) && is_array( $evidence['keys'] ) ? $evidence['keys'] : array();
		$suppression_keys = isset( $evidence['suppression'] ) && is_array( $evidence['suppression'] ) ? $evidence['suppression'] : array();
		$previous_keys = isset( $previous['keys'] ) && is_array( $previous['keys'] ) ? $previous['keys'] : array();
		$key_states = array(); $eligible_keys = array();

		foreach ( self::managed_keys() as $meta_key => $slug ) {
			$m = isset( $metric_keys[ $meta_key ] ) && is_array( $metric_keys[ $meta_key ] ) ? $metric_keys[ $meta_key ] : array();
			$sm = isset( $suppression_keys[ $meta_key ] ) && is_array( $suppression_keys[ $meta_key ] ) ? $suppression_keys[ $meta_key ] : array();
			$p = isset( $previous_keys[ $meta_key ] ) && is_array( $previous_keys[ $meta_key ] ) ? $previous_keys[ $meta_key ] : array();
			$window = isset( $p['window'] ) && is_array( $p['window'] ) ? $p['window'] : array();

			$reads=absint(isset($m['customReads'])?$m['customReads']:0);
			$deps=absint(isset($m['legacyReadDependencies'])?$m['legacyReadDependencies']:0);
			$writes=absint(isset($m['legacyWrites'])?$m['legacyWrites']:0);
			$suppressed=absint(isset($sm['suppressedWrites'])?$sm['suppressedWrites']:0);
			$suppression_fallbacks=absint(isset($sm['fallbacks'])?$sm['fallbacks']:0);
			$suppression_requested=!empty($sm['requested']);

			$base_reads=absint(isset($window['baselineCustomReads'])?$window['baselineCustomReads']:0);
			$base_deps=absint(isset($window['baselineLegacyReadDependencies'])?$window['baselineLegacyReadDependencies']:0);
			$base_writes=absint(isset($window['baselineLegacyWrites'])?$window['baselineLegacyWrites']:0);
			$base_suppression_fallbacks=absint(isset($window['baselineSuppressionFallbacks'])?$window['baselineSuppressionFallbacks']:0);
			$started=absint(isset($window['startedAt'])?$window['startedAt']:0);
			$resets=absint(isset($window['resetCount'])?$window['resetCount']:0);
			$reason='';

			$reset_key = false;
			if ( ! empty( $global_blockers ) ) { $started=0; $reset_key=true; }
			elseif ( $global_reset ) { $started=$now; $reset_key=true; $reason=$global_reset_reason; }
			elseif ( $started <= 0 ) { $started=$now; $reset_key=true; $reason='window_started'; }
			elseif ( $reads<$base_reads || $deps<$base_deps || $writes<$base_writes || $suppression_fallbacks<$base_suppression_fallbacks ) { $started=$now; $reset_key=true; $resets++; $reason='counters_reset'; }
			elseif ( $deps>$base_deps ) { $started=$now; $reset_key=true; $resets++; $reason='legacy_read_dependency'; }
			elseif ( $suppression_fallbacks>$base_suppression_fallbacks ) { $started=$now; $reset_key=true; $resets++; $reason='suppression_fallback'; }
			if ( $reset_key ) {
				$base_reads=$reads; $base_deps=$deps; $base_writes=$writes; $base_suppression_fallbacks=$suppression_fallbacks;
			}

			$window_reads=max(0,$reads-$base_reads);
			$window_deps=max(0,$deps-$base_deps);
			$window_writes=max(0,$writes-$base_writes);
			$clean=$started>0?max(0,$now-$started):0;

			$read_updated=sanitize_text_field((string)(isset($m['customReadsUpdatedAt'])?$m['customReadsUpdatedAt']:''));
			$write_updated=sanitize_text_field((string)(isset($m['legacyWritesUpdatedAt'])?$m['legacyWritesUpdatedAt']:''));
			$suppressed_updated=sanitize_text_field((string)(isset($sm['suppressedUpdatedAt'])?$sm['suppressedUpdatedAt']:''));
			$read_age=self::age_seconds($read_updated,$now);
			$write_age=self::age_seconds($write_updated,$now);
			$suppressed_age=self::age_seconds($suppressed_updated,$now);

			/*
			 * Initial qualification always requires fresh Legacy-write exercise.
			 * After a previously eligible key is explicitly suppressed, a fresh
			 * successful suppression stream may replace only the freshness check;
			 * the original >=50 Legacy-write exercise remains preserved in window.
			 */
			$previously_eligible = ! empty( $p['eligible'] );
			$suppression_live = ! $global_reset
				&& $previously_eligible
				&& $suppression_requested
				&& $suppressed > 0
				&& null !== $suppressed_age
				&& $suppressed_age <= self::MAX_USAGE_STALENESS_SECONDS;

			$blockers=$global_blockers;
			if ( empty( $blockers ) ) {
				if($window_reads<self::MIN_CUSTOM_READS){$blockers[]='insufficient_custom_reads';}
				if($window_writes<self::MIN_LEGACY_WRITES){$blockers[]='insufficient_legacy_write_exercise';}
				if($clean<self::MIN_CLEAN_SECONDS){$blockers[]='clean_window_too_short';}
				if($window_deps>0){$blockers[]='legacy_read_dependency_in_window';}
				if(null===$read_age||$read_age>self::MAX_USAGE_STALENESS_SECONDS){$blockers[]='custom_read_evidence_stale';}
				if((null===$write_age||$write_age>self::MAX_USAGE_STALENESS_SECONDS)&&!$suppression_live){$blockers[]='legacy_write_evidence_stale';}
			}
			$eligible=empty($blockers); if($eligible){$eligible_keys[]=$meta_key;}
			$key_states[$meta_key]=array(
				'eligible'=>$eligible,
				'status'=>$eligible?'eligible':(!empty($global_blockers)?'blocked':'observing'),
				'blockers'=>array_values(array_unique($blockers)),
				'window'=>array(
					'startedAt'=>$started,'cleanSeconds'=>$clean,
					'baselineCustomReads'=>$base_reads,
					'baselineLegacyReadDependencies'=>$base_deps,
					'baselineLegacyWrites'=>$base_writes,
					'baselineSuppressionFallbacks'=>$base_suppression_fallbacks,
					'customReads'=>$window_reads,
					'legacyReadDependencies'=>$window_deps,
					'legacyWrites'=>$window_writes,
					'resetCount'=>$resets,
					'lastResetReason'=>$reason,
				),
				'usage'=>array(
					'customReadsUpdatedAt'=>$read_updated,
					'legacyWritesUpdatedAt'=>$write_updated,
					'suppressedWritesUpdatedAt'=>$suppressed_updated,
					'customReadAgeSeconds'=>$read_age,
					'legacyWriteAgeSeconds'=>$write_age,
					'suppressedWriteAgeSeconds'=>$suppressed_age,
					'suppressionLive'=>$suppression_live,
				),
				'totals'=>array(
					'customReads'=>$reads,
					'legacyReadDependencies'=>$deps,
					'legacyWrites'=>$writes,
					'suppressedWrites'=>$suppressed,
					'suppressionFallbacks'=>$suppression_fallbacks,
				),
			);
		}

		$all_eligible = count( $eligible_keys ) === count( self::managed_keys() );
		return array(
			'gateVersion'=>self::GATE_VERSION,
			'eligible'=>!empty($eligible_keys), 'allEligible'=>$all_eligible,
			'status'=>$all_eligible?'eligible':(!empty($eligible_keys)?'partially-eligible':(!empty($global_blockers)?'blocked':'observing')),
			'eligibleKeys'=>$eligible_keys, 'globalBlockers'=>array_values(array_unique($global_blockers)), 'evaluatedAt'=>$now,
			'thresholds'=>self::thresholds(), 'keys'=>$key_states,
			'globalWindow'=>array('baselinePrimaryFallbacks'=>$baseline_fallbacks,'baselineWriteFailures'=>$baseline_failures,'resetCount'=>$global_reset_count,'lastResetReason'=>$global_reset_reason),
			'primaryFallbackCount'=>$fallbacks, 'dualWriteFailureCount'=>$failures,
			'note'=>'10.33.89 may suppress explicitly opted-in revision, canonical source-hash and sync_incomplete update_post_meta writes after a fresh exact-key lease; direct add/delete and REST metadata paths remain Legacy.',
		);
	}

	/** @return array */
	public static function get_status() {
		$state=self::read_gate_state();
		if(empty($state)){$state=array('gateVersion'=>self::GATE_VERSION,'eligible'=>false,'allEligible'=>false,'status'=>'not-evaluated','eligibleKeys'=>array(),'globalBlockers'=>array('not_evaluated'),'keys'=>array(),'evaluatedAt'=>0);}
		$state['thresholds']=self::thresholds(); $state['metrics']=self::get_metrics_status(); $state['request']=self::get_request_status(); return $state;
	}

	/** All exact-key runtime leases that are valid at this instant. @return array<int,string> */
	public static function runtime_eligible_keys() {
		// Read the durable lease directly so exact-key metrics are scanned only once below.
		$status=self::read_gate_state();
		if(self::GATE_VERSION!==absint(isset($status['gateVersion'])?$status['gateVersion']:0)){return array();}
		$evaluated=absint(isset($status['evaluatedAt'])?$status['evaluatedAt']:0); if($evaluated<=0||(time()-$evaluated)>self::MAX_RUNTIME_GATE_AGE_SECONDS){return array();}
		if(!class_exists('Mobo_Core_Storage_Legacy_Retirement_Gate')||!Mobo_Core_Storage_Legacy_Retirement_Gate::is_runtime_eligible()){return array();}
		$request_bucket=self::current_bucket(); if(!empty($request_bucket['legacy_reads'])){return array();}
		if(class_exists('Mobo_Core_Storage_Primary_Read')&&!Mobo_Core_Storage_Primary_Read::request_allowed()){return array();}
		if(class_exists('Mobo_Core_Storage_Primary_Read')&&Mobo_Core_Storage_Primary_Read::fallback_count()!==absint(isset($status['primaryFallbackCount'])?$status['primaryFallbackCount']:0)){return array();}
		$current_failures=class_exists('Mobo_Core_Storage_Bridge')?Mobo_Core_Storage_Bridge::failure_count():0;
		if($current_failures!==absint(isset($status['dualWriteFailureCount'])?$status['dualWriteFailureCount']:0)){return array();}
		$current=self::get_metrics_status(); $eligible=array();
		foreach(self::managed_keys() as $meta_key=>$slug){
			unset($slug);
			$key_state=isset($status['keys'][$meta_key])&&is_array($status['keys'][$meta_key])?$status['keys'][$meta_key]:array();
			if(empty($key_state['eligible'])){continue;}
			$deps_now=absint(isset($current[$meta_key]['legacyReadDependencies'])?$current[$meta_key]['legacyReadDependencies']:0);
			$deps_gate=absint(isset($key_state['totals']['legacyReadDependencies'])?$key_state['totals']['legacyReadDependencies']:0);
			if($deps_now!==$deps_gate){continue;}
			$eligible[]=$meta_key;
		}
		return $eligible;
	}

	/** Exact-key runtime lease used by controlled suppression. @return bool */
	public static function is_key_runtime_eligible( $meta_key ) {
		if(!self::is_managed_key($meta_key)){return false;}
		return in_array((string)$meta_key,self::runtime_eligible_keys(),true);
	}

	/** Tests/manual tooling only. @return void */
	public static function reset_request_state(){self::clear_request_buffer();}

	/** @return array */
	private static function collect_evidence(){
		return array(
			'schemaReady'=>class_exists('Mobo_Core_Storage_Schema')&&Mobo_Core_Storage_Schema::is_ready(),
			'effectiveMode'=>class_exists('Mobo_Core_Storage_Mode')?Mobo_Core_Storage_Mode::effective():'legacy',
			'retirementRuntimeEligible'=>class_exists('Mobo_Core_Storage_Legacy_Retirement_Gate')&&Mobo_Core_Storage_Legacy_Retirement_Gate::is_runtime_eligible(),
			'primaryFallbackCount'=>class_exists('Mobo_Core_Storage_Primary_Read')?Mobo_Core_Storage_Primary_Read::fallback_count():0,
			'dualWriteFailureCount'=>class_exists('Mobo_Core_Storage_Bridge')?Mobo_Core_Storage_Bridge::failure_count():0,
			'keys'=>self::get_metrics_status(),
			'suppression'=>class_exists('Mobo_Core_Storage_Postmeta_Write_Suppression')?Mobo_Core_Storage_Postmeta_Write_Suppression::get_metrics_status():array(),
		);
	}

	/** @return bool */
	private static function increment_metric($connection_id,$metric,$events){global $wpdb;$connection_id=absint($connection_id);if($connection_id<=0||empty($events)){return true;}$counts=array();$samples=array();$keys=self::managed_keys();foreach($events as $event){$meta=(string)($event['metaKey']??'');if(!isset($keys[$meta])){continue;}$counts[$meta]=isset($counts[$meta])?$counts[$meta]+1:1;if(!isset($samples[$meta])){$samples[$meta]=array();}if(count($samples[$meta])<3){$samples[$meta][]=$event;}}if(empty($counts)){return true;}$table=Mobo_Core_Storage_Schema::table_name('runtime');$now=current_time('mysql',true);$values=array();$args=array();foreach($counts as $meta=>$count){$state=wp_json_encode(array('connectionId'=>$connection_id,'metric'=>$metric,'metaKey'=>$meta,'lastBatchCount'=>$count,'samples'=>$samples[$meta],'flushedAt'=>gmdate('c')));if(!is_string($state)){$state='{}';}$values[]='(%d,%s,%s,%s,%d,%s,%s,%s)';$args[]=$connection_id;$args[]=self::metric_worker($metric,$keys[$meta]);$args[]=$now;$args[]=$now;$args[]=absint($count);$args[]=$state;$args[]=$now;$args[]=$now;}if(empty($values)){return true;}$sql="INSERT INTO {$table} (connection_id,worker_name,last_started_at,last_finished_at,consecutive_failures,state_json,created_at,updated_at) VALUES ".implode(',',$values)." ON DUPLICATE KEY UPDATE last_started_at=VALUES(last_started_at),last_finished_at=VALUES(last_finished_at),consecutive_failures=consecutive_failures+VALUES(consecutive_failures),state_json=VALUES(state_json),updated_at=VALUES(updated_at)";return false!==$wpdb->query($wpdb->prepare($sql,$args));}

	/** @return string */
	private static function metric_worker($metric,$slug){$prefix=array('read'=>'storage_pm_read_v1_','dependency'=>'storage_pm_dep_v1_','write'=>'storage_pm_write_v1_'); return (isset($prefix[$metric])?$prefix[$metric]:'storage_pm_other_v1_').sanitize_key((string)$slug);}
	/** @return bool */
	private static function observing(){return class_exists('Mobo_Core_Storage_Mode')&&Mobo_Core_Storage_Mode::CUSTOM_ONLY===Mobo_Core_Storage_Mode::requested();}
	/** @return bool */
	private static function managed_object($object_id){$type=get_post_type(absint($object_id)); return 'product'===$type||'product_variation'===$type;}
	/** @return void */
	private static function register_shutdown(){if(self::$shutdown_registered){return;} self::$shutdown_registered=true; register_shutdown_function(array(__CLASS__,'flush'));}
	/** @return void */
	private static function clear_request_buffer(){self::$buckets=array();self::$shutdown_registered=false;}
	/** @return array */
	private static function thresholds(){return array('minCustomReadsPerKey'=>self::MIN_CUSTOM_READS,'minLegacyWritesPerKey'=>self::MIN_LEGACY_WRITES,'minCleanSeconds'=>self::MIN_CLEAN_SECONDS,'maxUsageStalenessSeconds'=>self::MAX_USAGE_STALENESS_SECONDS,'minEvaluationInterval'=>self::MIN_EVALUATION_INTERVAL,'maxRuntimeGateAgeSeconds'=>self::MAX_RUNTIME_GATE_AGE_SECONDS);}
	/** @return int|null */
	private static function age_seconds($value,$now){$value=trim((string)$value); if(''===$value){return null;} $ts=strtotime($value.(false===strpos($value,'T')?' UTC':'')); return $ts?max(0,absint($now)-$ts):null;}
	/** @return array */
	private static function read_gate_state(){global $wpdb;if(!class_exists('Mobo_Core_Storage_Schema')||!Mobo_Core_Storage_Schema::is_ready()){return array();}$connection_id=self::connection_id();if($connection_id<=0){return array();}$table=Mobo_Core_Storage_Schema::table_name('runtime');$json=(string)$wpdb->get_var($wpdb->prepare("SELECT state_json FROM {$table} WHERE connection_id=%d AND worker_name=%s LIMIT 1",$connection_id,self::GATE_WORKER)); $decoded=json_decode($json,true); return is_array($decoded)?$decoded:array();}
	/** @return bool */
	private static function persist_gate_state($state){global $wpdb;if(!class_exists('Mobo_Core_Storage_Schema')||!Mobo_Core_Storage_Schema::is_ready()){return false;}$connection_id=self::connection_id();if($connection_id<=0){return false;}$table=Mobo_Core_Storage_Schema::table_name('runtime');$now=current_time('mysql',true);$encoded=wp_json_encode($state);if(!is_string($encoded)){$encoded='{}';}$status=sanitize_key((string)($state['status']??'blocked'));$eligible=count(isset($state['eligibleKeys'])&&is_array($state['eligibleKeys'])?$state['eligibleKeys']:array());$sql="INSERT INTO {$table} (connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,last_error_code,state_json,created_at,updated_at) VALUES (%d,%s,%s,%s,%s,0,%s,'',%s,%s,%s) ON DUPLICATE KEY UPDATE last_started_at=VALUES(last_started_at),last_finished_at=VALUES(last_finished_at),last_cursor=VALUES(last_cursor),consecutive_failures=0,circuit_state=VALUES(circuit_state),last_error_code='',state_json=VALUES(state_json),updated_at=VALUES(updated_at)";return false!==$wpdb->query($wpdb->prepare($sql,$connection_id,self::GATE_WORKER,$now,$now,(string)$eligible,$status,$encoded,$now,$now));}
}
