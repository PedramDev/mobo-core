<?php
/**
 * Bounded Legacy dependency/write telemetry for Custom-Only retirement readiness.
 *
 * Read dependencies are correctness blockers for future Legacy retirement. Legacy
 * write volume is inventory only: 10.33.82 does not suppress any Product Map or
 * postmeta write. Both streams are request-buffered and flushed at shutdown.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class Mobo_Core_Storage_Legacy_Dependency {
	const READ_WORKER  = 'storage_legacy_read_dependency_v1';
	const WRITE_WORKER = 'storage_legacy_write_usage_v1';
	const MAX_UNIQUE_READS_PER_REQUEST  = 64;
	const MAX_UNIQUE_WRITES_PER_REQUEST = 128;
	const MAX_SAMPLES = 5;

	/** @var array<int,array{reads:array,writes:array}> */
	private static $buckets = array();
	/** @var bool */ private static $shutdown_registered = false;

	private static function connection_id() { return class_exists('Mobo_Core_Connection_Context') ? absint(Mobo_Core_Connection_Context::get_effective_connection_id()) : 0; }
	private static function ensure_bucket($cid) { if(!isset(self::$buckets[$cid])){self::$buckets[$cid]=array('reads'=>array(),'writes'=>array());} }

	public static function record_read_dependency($reason,$object_id=0,$meta_key='') {
		$cid=self::connection_id(); if($cid<=0||!self::observing()){return;} self::ensure_bucket($cid);
		if(count(self::$buckets[$cid]['reads'])>=self::MAX_UNIQUE_READS_PER_REQUEST){return;}
		$reason=sanitize_key((string)$reason); if(''===$reason){$reason='legacy_read_dependency';}
		$object_id=absint($object_id); $meta_key=sanitize_key((string)$meta_key); $key=$reason.'|'.$object_id.'|'.$meta_key;
		self::$buckets[$cid]['reads'][$key]=array('reason'=>$reason,'objectId'=>$object_id,'metaKey'=>$meta_key);
		if(class_exists('Mobo_Core_Storage_Postmeta_Suppression_Readiness')){Mobo_Core_Storage_Postmeta_Suppression_Readiness::record_legacy_read_dependency($reason,$object_id,$meta_key);}
		self::register_shutdown();
	}
	public static function record_legacy_write($surface,$object_id=0,$key='') {
		$cid=self::connection_id(); if($cid<=0||!self::observing()){return;} self::ensure_bucket($cid);
		if(count(self::$buckets[$cid]['writes'])>=self::MAX_UNIQUE_WRITES_PER_REQUEST){return;}
		$surface=sanitize_key((string)$surface); if(''===$surface){$surface='legacy_write';}
		$object_id=absint($object_id); $key=sanitize_key((string)$key); self::$buckets[$cid]['writes'][$surface.'|'.$object_id.'|'.$key]=array('surface'=>$surface,'objectId'=>$object_id,'key'=>$key);
		self::register_shutdown();
	}
	public static function flush() {
		if(empty(self::$buckets)){return true;} if(!class_exists('Mobo_Core_Storage_Schema')||!Mobo_Core_Storage_Schema::is_ready()){self::clear_request_buffer();return false;}
		$ok=true; foreach(self::$buckets as $cid=>$bucket){$cid=absint($cid);if($cid<=0){continue;}if(!empty($bucket['reads'])){$ok=self::increment($cid,self::READ_WORKER,$bucket['reads'],'reason')&&$ok;}if(!empty($bucket['writes'])){$ok=self::increment($cid,self::WRITE_WORKER,$bucket['writes'],'surface')&&$ok;}}
		self::clear_request_buffer(); return $ok;
	}
	public static function get_status() {
		global $wpdb; $cid=self::connection_id();
		$values=array(self::READ_WORKER=>array('count'=>0,'updatedAt'=>'','lastBatch'=>array()),self::WRITE_WORKER=>array('count'=>0,'updatedAt'=>'','lastBatch'=>array()));
		if($cid>0&&class_exists('Mobo_Core_Storage_Schema')&&Mobo_Core_Storage_Schema::is_ready()){$table=Mobo_Core_Storage_Schema::table_name('runtime');$rows=$wpdb->get_results($wpdb->prepare("SELECT worker_name,consecutive_failures,state_json,updated_at FROM {$table} WHERE connection_id=%d AND worker_name IN (%s,%s)",$cid,self::READ_WORKER,self::WRITE_WORKER),ARRAY_A);foreach(is_array($rows)?$rows:array() as $row){$name=sanitize_key((string)($row['worker_name']??''));if(!isset($values[$name])){continue;}$decoded=json_decode((string)($row['state_json']??''),true);$values[$name]['count']=absint($row['consecutive_failures']??0);$values[$name]['updatedAt']=sanitize_text_field((string)($row['updated_at']??''));$values[$name]['lastBatch']=is_array($decoded)?$decoded:array();}}
		return array('connectionId'=>$cid,'observing'=>self::observing(),'readDependencyCount'=>$values[self::READ_WORKER]['count'],'readDependencyUpdatedAt'=>$values[self::READ_WORKER]['updatedAt'],'lastReadBatch'=>$values[self::READ_WORKER]['lastBatch'],'legacyWriteCount'=>$values[self::WRITE_WORKER]['count'],'legacyWriteUpdatedAt'=>$values[self::WRITE_WORKER]['updatedAt'],'lastWriteBatch'=>$values[self::WRITE_WORKER]['lastBatch'],'perRequestCaps'=>array('reads'=>self::MAX_UNIQUE_READS_PER_REQUEST,'writes'=>self::MAX_UNIQUE_WRITES_PER_REQUEST));
	}
	public static function get_request_status() {$cid=self::connection_id();$bucket=isset(self::$buckets[$cid])?self::$buckets[$cid]:array('reads'=>array(),'writes'=>array());return array('readDependencies'=>count($bucket['reads']),'legacyWrites'=>count($bucket['writes']));}
	public static function reset_request_cache(){self::clear_request_buffer();}
	private static function observing(){if(!class_exists('Mobo_Core_Storage_Mode')){return false;}return Mobo_Core_Storage_Mode::CUSTOM_ONLY===Mobo_Core_Storage_Mode::requested();}
	private static function increment($cid,$worker_name,$items,$group_field){global $wpdb;$items=is_array($items)?$items:array();$count=count($items);if($cid<=0||$count<=0){return $count<=0;}$groups=array();$samples=array();foreach($items as $item){$group=sanitize_key((string)($item[$group_field]??''));if(''===$group){$group='other';}$groups[$group]=isset($groups[$group])?absint($groups[$group])+1:1;if(count($samples)<self::MAX_SAMPLES){$samples[]=$item;}}$table=Mobo_Core_Storage_Schema::table_name('runtime');$now=current_time('mysql',true);$state=wp_json_encode(array('connectionId'=>$cid,'lastBatchCount'=>$count,'groups'=>$groups,'samples'=>$samples,'flushedAt'=>gmdate('c')));if(!is_string($state)){$state='{}';}$sql="INSERT INTO {$table} (connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,last_error_code,state_json,created_at,updated_at) VALUES (%d,%s,%s,%s,'',%d,'closed','',%s,%s,%s) ON DUPLICATE KEY UPDATE last_started_at=VALUES(last_started_at),last_finished_at=VALUES(last_finished_at),consecutive_failures=consecutive_failures+VALUES(consecutive_failures),circuit_state='closed',last_error_code='',state_json=VALUES(state_json),updated_at=VALUES(updated_at)";return false!==$wpdb->query($wpdb->prepare($sql,$cid,$worker_name,$now,$now,$count,$state,$now,$now));}
	private static function register_shutdown(){if(self::$shutdown_registered){return;}self::$shutdown_registered=true;register_shutdown_function(array(__CLASS__,'flush'));}
	private static function clear_request_buffer(){self::$buckets=array();self::$shutdown_registered=false;}
}
