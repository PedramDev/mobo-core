<?php
/**
 * Bounded successful Custom-Primary usage telemetry.
 *
 * Release 10.33.79 records only successful Custom-Primary reads. Identity and
 * operational-state hits are de-duplicated in-request and flushed once at
 * shutdown into independent atomic counters in mobo_runtime. The counters are
 * evidence for Custom-Only readiness; they never change read/write behavior.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class Mobo_Core_Storage_Primary_Usage {

	const IDENTITY_WORKER = 'storage_primary_identity_usage_v1';
	const STATE_WORKER    = 'storage_primary_state_usage_v1';
	const MAX_UNIQUE_IDENTITY_HITS_PER_REQUEST = 64;
	const MAX_UNIQUE_STATE_HITS_PER_REQUEST    = 64;

	/** @var array<int,array{identity:array,state:array}> */
	private static $buckets = array();
	/** @var bool */
	private static $shutdown_registered = false;

	private static function connection_id() { return class_exists('Mobo_Core_Connection_Context') ? absint(Mobo_Core_Connection_Context::get_effective_connection_id()) : 0; }
	private static function ensure_bucket( $connection_id ) { if(!isset(self::$buckets[$connection_id])){self::$buckets[$connection_id]=array('identity'=>array(),'state'=>array());} }

	public static function record_identity_hit( $entity_type, $guid ) {
		$cid=self::connection_id(); $entity_type=sanitize_key((string)$entity_type); $guid=sanitize_text_field((string)$guid);
		if($cid<=0||''===$guid){return;} self::ensure_bucket($cid);
		if(count(self::$buckets[$cid]['identity'])>=self::MAX_UNIQUE_IDENTITY_HITS_PER_REQUEST){return;}
		self::$buckets[$cid]['identity'][$entity_type.'|'.$guid]=true; self::register_shutdown();
	}
	public static function record_state_hit( $object_id, $meta_key ) {
		$cid=self::connection_id(); $object_id=absint($object_id); $meta_key=sanitize_key((string)$meta_key);
		if($cid<=0||$object_id<=0||''===$meta_key){return;} self::ensure_bucket($cid);
		if(count(self::$buckets[$cid]['state'])>=self::MAX_UNIQUE_STATE_HITS_PER_REQUEST){return;}
		self::$buckets[$cid]['state'][$object_id.'|'.$meta_key]=true; self::register_shutdown();
	}
	public static function flush() {
		if(empty(self::$buckets)){return true;}
		if(!class_exists('Mobo_Core_Storage_Schema')||!Mobo_Core_Storage_Schema::is_ready()){self::clear_request_buffer();return false;}
		$ok=true; foreach(self::$buckets as $cid=>$bucket){$cid=absint($cid);if($cid<=0){continue;}$identity=count($bucket['identity']);$state=count($bucket['state']);if($identity>0){$ok=self::increment_counter($cid,self::IDENTITY_WORKER,$identity)&&$ok;}if($state>0){$ok=self::increment_counter($cid,self::STATE_WORKER,$state)&&$ok;}}
		self::clear_request_buffer(); return $ok;
	}
	public static function get_status() {
		global $wpdb; $values=array(self::IDENTITY_WORKER=>array('hits'=>0,'updatedAt'=>''),self::STATE_WORKER=>array('hits'=>0,'updatedAt'=>'')); $cid=self::connection_id();
		if($cid>0&&class_exists('Mobo_Core_Storage_Schema')&&Mobo_Core_Storage_Schema::is_ready()){$table=Mobo_Core_Storage_Schema::table_name('runtime');$rows=$wpdb->get_results($wpdb->prepare("SELECT worker_name,consecutive_failures,updated_at FROM {$table} WHERE connection_id=%d AND worker_name IN (%s,%s)",$cid,self::IDENTITY_WORKER,self::STATE_WORKER),ARRAY_A);foreach(is_array($rows)?$rows:array() as $row){$name=sanitize_key((string)($row['worker_name']??''));if(!isset($values[$name])){continue;}$values[$name]['hits']=absint($row['consecutive_failures']??0);$values[$name]['updatedAt']=sanitize_text_field((string)($row['updated_at']??''));}}
		return array('connectionId'=>$cid,'identityHits'=>$values[self::IDENTITY_WORKER]['hits'],'stateHits'=>$values[self::STATE_WORKER]['hits'],'identityUpdatedAt'=>$values[self::IDENTITY_WORKER]['updatedAt'],'stateUpdatedAt'=>$values[self::STATE_WORKER]['updatedAt'],'perRequestCaps'=>array('identity'=>self::MAX_UNIQUE_IDENTITY_HITS_PER_REQUEST,'state'=>self::MAX_UNIQUE_STATE_HITS_PER_REQUEST));
	}
	public static function reset_request_cache(){self::clear_request_buffer();}
	private static function increment_counter($cid,$worker_name,$amount){global $wpdb;$table=Mobo_Core_Storage_Schema::table_name('runtime');$now=current_time('mysql',true);$amount=min(1000000,absint($amount));if($cid<=0||$amount<=0){return $amount<=0;}$state=wp_json_encode(array('connectionId'=>$cid,'lastBatchHits'=>$amount,'flushedAt'=>gmdate('c')));if(!is_string($state)){$state='{}';}$sql="INSERT INTO {$table} (connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,last_error_code,state_json,created_at,updated_at) VALUES (%d,%s,%s,%s,'',%d,'closed','',%s,%s,%s) ON DUPLICATE KEY UPDATE last_started_at=VALUES(last_started_at),last_finished_at=VALUES(last_finished_at),consecutive_failures=consecutive_failures+VALUES(consecutive_failures),circuit_state='closed',last_error_code='',state_json=VALUES(state_json),updated_at=VALUES(updated_at)";return false!==$wpdb->query($wpdb->prepare($sql,$cid,$worker_name,$now,$now,$amount,$state,$now,$now));}
	private static function register_shutdown(){if(self::$shutdown_registered){return;}self::$shutdown_registered=true;Mobo_Core_Environment::on_shutdown(array(__CLASS__,'flush'));}
	private static function clear_request_buffer(){self::$buckets=array();self::$shutdown_registered=false;}
}
