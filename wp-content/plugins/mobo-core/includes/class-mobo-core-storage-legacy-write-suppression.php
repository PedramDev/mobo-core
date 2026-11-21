<?php
/**
 * Controlled Legacy write suppression after proven Custom-Only retirement readiness.
 *
 * Release 10.33.83 suppresses only Product Map writes emitted by the Product Sync
 * commit boundary, and only after an explicit opt-in plus a fresh retirement lease.
 * Operational postmeta and every other Legacy surface remain unchanged. If the
 * Custom mirror fails, the same commit falls back to the Product Map immediately
 * and suppression is disabled for the rest of the request.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class Mobo_Core_Storage_Legacy_Write_Suppression {

	const OPTION = 'mobo_core_storage_legacy_write_suppression';
	const OFF = 'off';
	const PRODUCT_MAP = 'product-map';
	const WORKER_NAME = 'storage_legacy_write_suppression_v1';
	const MAX_SAMPLES = 5;

	/** @var array<int,array{blocked:bool,suppressed:int,fallbacks:int,groups:array,samples:array}> */
	private static $buckets = array();
	/** @var bool */
	private static $shutdown_registered = false;

	/** @return int */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** @return void */
	private static function ensure_bucket( $connection_id ) {
		$connection_id=absint($connection_id);
		if(!isset(self::$buckets[$connection_id])){self::$buckets[$connection_id]=array('blocked'=>false,'suppressed'=>0,'fallbacks'=>0,'groups'=>array(),'samples'=>array());}
	}

	/** @return array */
	private static function current_bucket(){ $cid=self::connection_id(); self::ensure_bucket($cid); return self::$buckets[$cid]; }

	/** @return void */
	public static function ensure_default() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::OFF, '', false );
		}
	}

	/** @return string */
	public static function normalize( $value ) {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( self::OFF, self::PRODUCT_MAP ), true ) ? $value : self::OFF;
	}

	/** @return string */
	public static function requested() {
		return self::normalize( get_option( self::OPTION, self::OFF ) );
	}

	/**
	 * Effective suppression mode. Unknown/unsafe states fail closed to OFF.
	 *
	 * @return string
	 */
	public static function effective() {
		$bucket=self::current_bucket();
		if ( self::PRODUCT_MAP !== self::requested() || ! empty($bucket['blocked']) ) { return self::OFF; }
		if ( ! class_exists( 'Mobo_Core_Storage_Mode' ) || Mobo_Core_Storage_Mode::CUSTOM_ONLY !== Mobo_Core_Storage_Mode::effective() ) { return self::OFF; }
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return self::OFF; }
		if ( ! class_exists( 'Mobo_Core_Storage_Legacy_Retirement_Gate' ) || ! Mobo_Core_Storage_Legacy_Retirement_Gate::is_runtime_eligible() ) { return self::OFF; }
		if ( class_exists( 'Mobo_Core_Storage_Primary_Read' ) && ! Mobo_Core_Storage_Primary_Read::request_allowed() ) { return self::OFF; }
		if ( class_exists( 'Mobo_Core_Storage_Legacy_Dependency' ) ) {
			$request = Mobo_Core_Storage_Legacy_Dependency::get_request_status();
			if ( absint( isset( $request['readDependencies'] ) ? $request['readDependencies'] : 0 ) > 0 ) { return self::OFF; }
		}
		return self::PRODUCT_MAP;
	}

	/** @return bool */
	public static function suppresses_product_map() {
		return self::PRODUCT_MAP === self::effective();
	}

	/**
	 * Product Sync commit boundary for Product identity/state.
	 *
	 * Suppression path: Custom first; on failure, immediately restore Legacy Map.
	 * Normal path: Legacy Map first; Custom remains fail-open as before.
	 *
	 * @param Mobo_Core_Product_Map $map Product Map instance.
	 * @return bool
	 */
	public static function commit_product( $map, $product_guid, $product_id, $last_hash = '', $sync_incomplete = false ) {
		if ( ! ( $map instanceof Mobo_Core_Product_Map ) ) { return false; }
		if ( ! self::suppresses_product_map() || ! self::map_write_is_redundant( $product_guid, $product_id, Mobo_Core_Product_Map::TYPE_PRODUCT, '', $last_hash, $sync_incomplete ) ) {
			$stored = (bool) $map->upsert_product( $product_guid, $product_id, $last_hash, $sync_incomplete );
			if ( ! $stored ) { return false; }
			if ( class_exists( 'Mobo_Core_Storage_Bridge' ) ) { Mobo_Core_Storage_Bridge::mirror_product_legacy_state( $product_guid, $product_id, $sync_incomplete ); }
			return true;
		}

		$mirrored = class_exists( 'Mobo_Core_Storage_Bridge' )
			&& Mobo_Core_Storage_Bridge::mirror_product_legacy_state( $product_guid, $product_id, $sync_incomplete );
		if ( $mirrored ) {
			self::record_suppressed( 'product', $product_id, $product_guid );
			return true;
		}

		self::trip_request_breaker( 'product_custom_mirror_failed', $product_id, $product_guid );
		return (bool) $map->upsert_product( $product_guid, $product_id, $last_hash, $sync_incomplete );
	}

	/** Product Sync commit boundary for Variation identity/state. @return bool */
	public static function commit_variation( $map, $variant_guid, $variation_id, $product_guid = '', $last_hash = '', $sync_incomplete = false ) {
		if ( ! ( $map instanceof Mobo_Core_Product_Map ) ) { return false; }
		if ( ! self::suppresses_product_map() || ! self::map_write_is_redundant( $variant_guid, $variation_id, Mobo_Core_Product_Map::TYPE_VARIATION, $product_guid, $last_hash, $sync_incomplete ) ) {
			$stored = (bool) $map->upsert_variation( $variant_guid, $variation_id, $product_guid, $last_hash, $sync_incomplete );
			if ( ! $stored ) { return false; }
			if ( class_exists( 'Mobo_Core_Storage_Bridge' ) ) { Mobo_Core_Storage_Bridge::mirror_variant_legacy_state( $variant_guid, $variation_id, $product_guid, $sync_incomplete ); }
			return true;
		}

		$mirrored = class_exists( 'Mobo_Core_Storage_Bridge' )
			&& Mobo_Core_Storage_Bridge::mirror_variant_legacy_state( $variant_guid, $variation_id, $product_guid, $sync_incomplete );
		if ( $mirrored ) {
			self::record_suppressed( 'variation', $variation_id, $variant_guid );
			return true;
		}

		self::trip_request_breaker( 'variation_custom_mirror_failed', $variation_id, $variant_guid );
		return (bool) $map->upsert_variation( $variant_guid, $variation_id, $product_guid, $last_hash, $sync_incomplete );
	}

	/** @return void */
	public static function trip_request_breaker( $reason, $object_id = 0, $guid = '' ) {
		$connection_id=self::connection_id(); if($connection_id<=0){return;} self::ensure_bucket($connection_id); $b=&self::$buckets[$connection_id];
		$b['blocked']=true; $b['fallbacks']++;
		$reason=sanitize_key((string)$reason); if(''===$reason){$reason='legacy_write_fallback';}
		$b['groups'][$reason]=isset($b['groups'][$reason])?absint($b['groups'][$reason])+1:1;
		if(count($b['samples'])<self::MAX_SAMPLES){$b['samples'][]=array('connectionId'=>$connection_id,'kind'=>'fallback','reason'=>$reason,'objectId'=>absint($object_id),'guid'=>sanitize_text_field((string)$guid));}
		self::register_shutdown();
	}

	/** @return bool */
	public static function request_allowed() { $b=self::current_bucket(); return empty($b['blocked']); }

	/** @return array */
	public static function get_request_status() {
		$b=self::current_bucket();
		return array('connectionId'=>self::connection_id(),'blocked'=>!empty($b['blocked']),'suppressedWrites'=>absint($b['suppressed']),'emergencyFallbacks'=>absint($b['fallbacks']));
	}

	/** Persist bounded suppression telemetry once per request. @return bool */
	public static function flush() {
		global $wpdb;
		if(empty(self::$buckets)){return true;}
		if(!class_exists('Mobo_Core_Storage_Schema')||!Mobo_Core_Storage_Schema::is_ready()){self::clear_request_buffer();return false;}
		$table=Mobo_Core_Storage_Schema::table_name('runtime'); $now=current_time('mysql',true); $ok=true;
		foreach(self::$buckets as $connection_id=>$b){
			$connection_id=absint($connection_id); if($connection_id<=0||($b['suppressed']<=0&&$b['fallbacks']<=0)){continue;}
			$state=wp_json_encode(array('connectionId'=>$connection_id,'lastSuppressed'=>absint($b['suppressed']),'lastEmergencyFallbacks'=>absint($b['fallbacks']),'groups'=>$b['groups'],'samples'=>$b['samples'],'flushedAt'=>gmdate('c'))); if(!is_string($state)){$state='{}';}
			$sql="INSERT INTO {$table} (connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,last_error_code,state_json,created_at,updated_at) VALUES (%d,%s,%s,%s,%s,%d,'closed','',%s,%s,%s) ON DUPLICATE KEY UPDATE last_started_at=VALUES(last_started_at),last_finished_at=VALUES(last_finished_at),last_cursor=CAST(COALESCE(NULLIF(last_cursor,''),'0') AS UNSIGNED)+CAST(VALUES(last_cursor) AS UNSIGNED),consecutive_failures=consecutive_failures+VALUES(consecutive_failures),circuit_state='closed',last_error_code='',state_json=VALUES(state_json),updated_at=VALUES(updated_at)";
			$written=$wpdb->query($wpdb->prepare($sql,$connection_id,self::WORKER_NAME,$now,$now,(string)absint($b['suppressed']),absint($b['fallbacks']),$state,$now,$now)); if(false===$written){$ok=false;}
		}
		self::clear_request_buffer(); return $ok;
	}

	/** @return array */
	public static function get_status() {
		global $wpdb;
		$base = array(
			'requested'=>self::requested(), 'effective'=>self::effective(),
			'suppressedProductMapWrites'=>0, 'emergencyFallbacks'=>0,
			'updatedAt'=>'', 'lastBatch'=>array(), 'request'=>self::get_request_status(),
		);
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return $base; }
		$connection_id=self::connection_id(); if($connection_id<=0){return $base;}
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT last_cursor,consecutive_failures,state_json,updated_at FROM {$table} WHERE connection_id=%d AND worker_name=%s LIMIT 1", $connection_id, self::WORKER_NAME ), ARRAY_A );
		if ( ! is_array( $row ) ) { return $base; }
		$decoded = json_decode( (string) ( isset( $row['state_json'] ) ? $row['state_json'] : '' ), true );
		$base['suppressedProductMapWrites'] = absint( isset( $row['last_cursor'] ) ? $row['last_cursor'] : 0 );
		$base['emergencyFallbacks'] = absint( isset( $row['consecutive_failures'] ) ? $row['consecutive_failures'] : 0 );
		$base['updatedAt'] = sanitize_text_field( (string) ( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ) );
		$base['lastBatch'] = is_array( $decoded ) ? $decoded : array();
		return $base;
	}

	/** Tests/manual tooling only. @return void */
	public static function reset_request_state() { self::clear_request_buffer(); }

	/**
	 * Suppress only a true Product Map no-op. New identities, identity migrations,
	 * parent changes, hash/incomplete changes, and reverse-map cleanup still use the
	 * Legacy write path so rollback evidence never becomes stale.
	 *
	 * @return bool
	 */
	private static function map_write_is_redundant( $guid, $post_id, $object_type, $parent_guid, $last_hash, $sync_incomplete ) {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists() ) { return false; }
		$guid = sanitize_text_field( (string) $guid ); $post_id = absint( $post_id );
		$object_type = sanitize_key( (string) $object_type ); $parent_guid = sanitize_text_field( (string) $parent_guid );
		$last_hash = sanitize_text_field( (string) $last_hash ); $incomplete = $sync_incomplete ? 1 : 0;
		if ( '' === $guid || $post_id <= 0 ) { return false; }
		$table = Mobo_Core_Product_Map::table_name();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT remote_guid,wp_post_id,parent_remote_guid,last_hash,sync_incomplete FROM {$table} WHERE connection_id=%d AND remote_guid=%s AND object_type=%s LIMIT 1",
			self::connection_id(), $guid, $object_type
		), ARRAY_A );
		if ( ! is_array( $row )
			|| (string) $row['remote_guid'] !== $guid
			|| absint( $row['wp_post_id'] ) !== $post_id
			|| (string) $row['parent_remote_guid'] !== $parent_guid
			|| (string) $row['last_hash'] !== $last_hash
			|| absint( $row['sync_incomplete'] ) !== $incomplete ) { return false; }
		$reverse_count = absint( $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE connection_id=%d AND wp_post_id=%d AND object_type=%s",
			self::connection_id(), $post_id, $object_type
		) ) );
		return 1 === $reverse_count;
	}

	/** @return void */
	private static function record_suppressed( $entity_type, $object_id, $guid ) {
		$connection_id=self::connection_id(); if($connection_id<=0){return;} self::ensure_bucket($connection_id); $b=&self::$buckets[$connection_id];
		$b['suppressed']++; $group=sanitize_key((string)$entity_type); if(''===$group){$group='entity';}
		$b['groups']['suppressed_'.$group]=isset($b['groups']['suppressed_'.$group])?absint($b['groups']['suppressed_'.$group])+1:1;
		if(count($b['samples'])<self::MAX_SAMPLES){$b['samples'][]=array('connectionId'=>$connection_id,'kind'=>'suppressed','entityType'=>$group,'objectId'=>absint($object_id),'guid'=>sanitize_text_field((string)$guid));}
		self::register_shutdown();
	}

	/** @return void */
	private static function register_shutdown(){if(self::$shutdown_registered){return;}self::$shutdown_registered=true;register_shutdown_function(array(__CLASS__,'flush'));}
	/** @return void */
	private static function clear_request_buffer(){self::$buckets=array();self::$shutdown_registered=false;}
}
