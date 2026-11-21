<?php
/**
 * Terminal Product/Variation identity-postmeta retirement.
 *
 * 10.33.95 makes custom Product/Variant tables the sole runtime authority for
 * Mobo identity. Legacy identity postmeta is copied/proven before a redundant
 * durable fence is published, then physically removed in bounded batches after
 * a grace period. New identity mutations after the fence are written directly
 * to Custom Storage and can never recreate retired postmeta.
 *
 * Order-item identity snapshots and forensic/quarantine metadata are out of
 * scope. PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Storage_Identity_Meta_Retirement {
	const TARGET_VERSION = '10.33.95';
	const WORKER_NAME = 'storage_identity_meta_retirement_v1';
	const FENCE_VERSION = 1;
	const FENCE_OPTION = 'mobo_core_identity_meta_terminal_fence_v1';
	const PROOF_BATCH = 100;
	const CLEANUP_BATCH = 200;
	const GRACE_SECONDS = 120;

	/** @var bool|null */
	private static $fenced = null;

	/** @var bool Request-local cache: terminal zero-residue was already proven. */
	private static $zero_residue_verified = false;

	/** @var array<int,int> Scoped authoritative-Legacy read bypass depth by Connection for migration snapshots only. */
	private static $legacy_read_bypass_depth = array();

	/** Bounded proof-batch snapshots; never durable and never authoritative beyond the current batch. */
	private static $proof_legacy_scope = array();
	private static $proof_legacy_rows = array();
	private static $proof_legacy_first = array();
	private static $proof_product_scope = array();
	private static $proof_product_rows = array();
	private static $proof_variant_scope = array();
	private static $proof_variant_rows = array();
	private static $proof_map_scope = array();
	private static $proof_map_rows = array();

	/** @return array<string,string> */
	public static function managed_keys() {
		return array(
			'product_guid' => 'product_guid',
			'variant_guid' => 'variant_guid',
			'mobo_variant_guid' => 'variant_guid',
			'_mobo_variant_guid' => 'variant_guid',
			'portal_product_id' => 'portal_product_id',
			'mobo_portal_product_id' => 'portal_product_id',
			'_mobo_portal_product_id' => 'portal_product_id',
			'PortalProductId' => 'portal_product_id',
			'portal_variant_id' => 'portal_variant_id',
			'mobo_portal_variant_id' => 'portal_variant_id',
			'_mobo_portal_variant_id' => 'portal_variant_id',
			'PortalVariantId' => 'portal_variant_id',
		);
	}

	/** @return bool */
	public static function key_applies_to_object( $object_id, $meta_key ) {
		$object_id = absint( $object_id );
		if ( $object_id <= 0 || ! isset( self::managed_keys()[ (string) $meta_key ] ) ) { return false; }
		return in_array( get_post_type( $object_id ), array( 'product', 'product_variation' ), true );
	}

	/** Active durable Connection for request-local bypass isolation. @return int */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** Default Connection that owns installation-global Legacy identity. @return int */
	private static function default_connection_id() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) ) { return 0; }
		$default = Mobo_Core_Connection_Repository::get_default();
		return is_array( $default ) ? absint( $default['id'] ?? 0 ) : 0;
	}

	/** Current Connection's authoritative-Legacy bypass depth. @return int */
	private static function legacy_read_bypass_depth() {
		$connection_id = self::connection_id();
		return isset( self::$legacy_read_bypass_depth[ $connection_id ] ) ? absint( self::$legacy_read_bypass_depth[ $connection_id ] ) : 0;
	}

	/** Enter a scoped authoritative-Legacy read bypass for Storage Bridge migration snapshots. @return void */
	public static function begin_legacy_read_bypass() {
		$connection_id = self::connection_id();
		self::$legacy_read_bypass_depth[ $connection_id ] = self::legacy_read_bypass_depth() + 1;
	}

	/** Leave one scoped authoritative-Legacy read bypass. @return void */
	public static function end_legacy_read_bypass() {
		$connection_id = self::connection_id();
		$depth = max( 0, self::legacy_read_bypass_depth() - 1 );
		if ( $depth > 0 ) { self::$legacy_read_bypass_depth[ $connection_id ] = $depth; }
		else { unset( self::$legacy_read_bypass_depth[ $connection_id ] ); }
	}

	/** Whether terminal identity read interception is temporarily bypassed for an authoritative Legacy snapshot. @return bool */
	public static function is_legacy_read_bypassed() { return self::legacy_read_bypass_depth() > 0; }

	/** @return bool */
	public static function is_fenced() {
		if ( true === self::$fenced ) { return false !== apply_filters( 'mobo_core_identity_meta_terminal_fence_active', true ); }
		$option_fence = self::FENCE_VERSION === absint( get_option( self::FENCE_OPTION, 0 ) );
		$version_fence = version_compare( (string) get_option( 'mobo_core_db_version', '0' ), self::TARGET_VERSION, '>=' );
		if ( $option_fence || $version_fence ) {
			self::$fenced = true;
			return false !== apply_filters( 'mobo_core_identity_meta_terminal_fence_active', true );
		}
		$state = self::load_state();
		$active = ! empty( $state['fenceActive'] ) && self::FENCE_VERSION === absint( isset( $state['fenceVersion'] ) ? $state['fenceVersion'] : 0 );
		if ( $active ) {
			self::$fenced = true;
			update_option( self::FENCE_OPTION, self::FENCE_VERSION, false );
		}
		return $active && false !== apply_filters( 'mobo_core_identity_meta_terminal_fence_active', true );
	}


	/**
	 * Whether runtime identity lookups must use Connection-owned custom storage.
	 *
	 * Multi-Connection cannot safely fall back to global wp_postmeta because two
	 * Sources may legitimately reuse the same remote GUID. This predicate is
	 * intentionally broader than the irreversible retirement fence: it changes
	 * lookup authority only, not whether legacy meta is physically writable/readable
	 * for rollback or migration purposes.
	 *
	 * @return bool
	 */
	public static function must_use_custom_reads() {
		if ( self::is_fenced() ) {
			return true;
		}
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::table_exists() ) {
			return false;
		}

		/* Legacy identity postmeta predates durable Connection ownership and may be
		 * consulted only by the enabled Default Connection. A non-Default explicit
		 * owner must use Connection-scoped Custom Storage even when it is the only
		 * enabled Connection (for example after Default is disabled). */
		if ( class_exists( 'Mobo_Core_Connection_Context' ) ) {
			$connection_id = absint( Mobo_Core_Connection_Context::get_effective_connection_id() );
			$row = $connection_id > 0 ? Mobo_Core_Connection_Repository::get_by_id( $connection_id ) : null;
			if ( ! is_array( $row ) || empty( $row['is_enabled'] ) || empty( $row['is_default'] ) ) {
				return true;
			}
		}

		/* Even Default must avoid global GUID/postmeta lookup once another Source is
		 * enabled because provider identities may legitimately collide. */
		return Mobo_Core_Connection_Repository::count_enabled() > 1;
	}

	/** @return void */
	public static function reset_request_cache() { self::$fenced = null; self::$zero_residue_verified = false; self::$legacy_read_bypass_depth = array(); self::reset_proof_batch_cache(); }


	/** @return int */
	private static function proof_batch_size() { return class_exists( 'Mobo_Core_Migration' ) && Mobo_Core_Migration::is_accelerated_context() ? 500 : self::PROOF_BATCH; }
	/** @return int */
	private static function cleanup_batch_size() { return class_exists( 'Mobo_Core_Migration' ) && Mobo_Core_Migration::is_accelerated_context() ? 2000 : self::CLEANUP_BATCH; }

	/** @return array */
	public static function get_status() {
		$state = self::load_state();
		unset( $state['__persistedStateJson'], $state['__stateLoadError'] );
		$state['workerName'] = self::WORKER_NAME;
		$state['targetVersion'] = self::TARGET_VERSION;
		$state['fenced'] = self::is_fenced();
		$state['managedKeys'] = array_keys( self::managed_keys() );
		$state['scope'] = 'product/product_variation identity postmeta only; order-item snapshots retained';
		return $state;
	}

	/** @return bool */
	public static function run_migration_step() {
		if ( class_exists( 'Mobo_Core_Migration' ) && ! Mobo_Core_Migration::accelerated_slice_lock_is_valid() ) { return false; }
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() || (string) get_option( Mobo_Core_Storage_Schema::VERSION_OPTION, '' ) !== Mobo_Core_Storage_Schema::VERSION ) { return false; }
		if ( ! class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' ) || ! Mobo_Core_Storage_Operational_Meta_Retirement::is_physically_retired() ) { return false; }
		if ( ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists() || absint( get_option( 'mobo_core_product_map_seed_completed_at', 0 ) ) <= 0 ) { return false; }

		$state = self::load_state();
		if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		if ( self::is_fenced() && empty( $state['fenceActive'] ) ) {
			$state['fenceActive'] = true; $state['fenceVersion'] = self::FENCE_VERSION; $state['fenceActivatedAt'] = time(); $state['status'] = 'fence-state-recovered'; $state['lastError'] = '';
			if ( ! self::persist_state( $state ) ) { return false; }
		}
		if ( ! empty( $state['complete'] ) ) {
			$late_residue=self::has_physical_residue();if(null===$late_residue){return false;}if(!$late_residue){self::$zero_residue_verified=true;return true;}
			$state['complete']=false;$state['status']='late-residue-recovery';$state['physicalResidue']=null;$state['lastError']='';
			if(!self::persist_state($state)){return false;}$state=self::load_state();if(!empty($state['__stateLoadError'])){return false;}
		}

		if ( empty( $state['fenceActive'] ) ) {
			/* Identity postmeta is installation-global physical state; an unscoped
			 * proof could combine identities from independent Sources.
			 * 10.34.77 hardening: additional Connections no longer block the Default
			 * Connection's retirement. Objects durably owned by another Connection are
			 * excluded from proof/cleanup (foreign_exclusion_sql), which is sound once
			 * the ownership migration has attributed every row; wait for that proof. */
			if ( class_exists( 'Mobo_Core_Migration_Quarantine' ) && ! Mobo_Core_Migration_Quarantine::retirement_topology_allowed() ) {
				$state['status'] = 'waiting-connection-ownership';
				$state['blockers'] = array( 'multi_connection_ownership_pending' => 1 );
				$state['lastError'] = '';
				self::persist_state( $state );
				return false;
			}
			$proof = self::advance_exact_proof( $state );
			if ( ! $proof['complete'] ) { return false; }
			/* Collapse exact duplicates / mark unresolvable objects in bounded passes. */
			$normalized = self::normalize_global_blockers();
			if ( ! empty( $normalized['progress'] ) ) {
				$state = self::load_state(); $state['status'] = 'normalizing'; $state['lastError'] = ''; $state['blockers'] = array();
				$state['dedupedGroups'] = absint( $state['dedupedGroups'] ?? 0 ) + absint( $normalized['deduped'] ?? 0 );
				$state['dedupedRows'] = absint( $state['dedupedRows'] ?? 0 ) + absint( $normalized['rowsRemoved'] ?? 0 );
				$state['markedObjects'] = absint( $state['markedObjects'] ?? 0 ) + absint( $normalized['marked'] ?? 0 );
				self::persist_state( $state );
				return false;
			}
			$blockers = ! empty( $normalized['blockers'] ) ? $normalized['blockers'] : self::global_blockers();
			if ( ! empty( $blockers ) ) {
				$state = self::load_state(); $state['status'] = 'blocked'; $state['blockers'] = $blockers; $state['lastError'] = sanitize_key( (string) key( $blockers ) ); self::persist_state( $state ); return false;
			}
			self::activate_fence();
			return false;
		}

		$fenced_at = absint( isset( $state['fenceActivatedAt'] ) ? $state['fenceActivatedAt'] : 0 );
		if ( $fenced_at <= 0 ) { self::record_state_error( $state, 'fence_timestamp_missing', 'blocked' ); return false; }
		if ( time() < $fenced_at + self::GRACE_SECONDS ) {
			$state['status'] = 'fenced-grace'; $state['graceRemainingSeconds'] = max( 0, $fenced_at + self::GRACE_SECONDS - time() ); self::persist_state( $state ); return false;
		}
		$cleaned = self::cleanup_one_batch( absint( $state['cleanupCursor'] ?? 0 ) );
		if ( false === $cleaned ) { self::record_state_error( self::load_state(), 'physical_cleanup_failed', 'cleanup-error' ); return false; }
		if ( absint( $cleaned['deleted'] ?? 0 ) > 0 ) {
			$state = self::load_state(); $state['status'] = 'physical-cleanup'; $state['graceRemainingSeconds'] = 0; $state['cleanedRows'] = absint( isset( $state['cleanedRows'] ) ? $state['cleanedRows'] : 0 ) + absint( $cleaned['deleted'] ); $state['cleanupCursor'] = max( absint( $state['cleanupCursor'] ?? 0 ), absint( $cleaned['cursor'] ?? 0 ) ); if ( ! self::persist_state( $state ) ) { return false; } return false;
		}
		$residue = self::physical_residue_count();
		if ( null === $residue || $residue > 0 ) { $state=self::load_state();$state['status']='residue-proof-failed';$state['physicalResidue']=null===$residue?-1:$residue;if($residue>0){$state['cleanupCursor']=0;}self::persist_state($state);return false; }
		$state=self::load_state();$state['status']='physical-retirement-complete';$state['graceRemainingSeconds']=0;$state['complete']=true;$state['completedAt']=time();$state['physicalResidue']=0;$state['lastError']='';if(!self::persist_state($state,true)){return false;}self::$zero_residue_verified=true;return true;
	}

	/** get_post_metadata filter. @return mixed */
	public static function filter_read( $check, $object_id, $meta_key, $single = false ) {
		if ( self::is_legacy_read_bypassed() || null !== $check || ! self::is_fenced() || ! self::key_applies_to_object( $object_id, $meta_key ) ) { return $check; }
		$current = self::terminal_current( $object_id, $meta_key );
		if ( empty( $current['present'] ) ) { return $single ? '' : array(); }
		/* Return a one-item array for present single values. get_metadata_raw()
		 * unwraps it, while metadata_exists() sees a truthy array even for valid
		 * false-y values such as '', '0' or false. */
		return array( $current['value'] );
	}

	/** pre_add_post_metadata filter. @return mixed */
	public static function filter_add( $check, $object_id, $meta_key, $meta_value, $unique = false ) {
		unset( $unique );
		if ( null !== $check || ! self::is_fenced() || ! self::key_applies_to_object( $object_id, $meta_key ) ) { return $check; }
		$current = self::terminal_current( $object_id, $meta_key );
		if ( ! empty( $current['present'] ) ) { return false; }
		return self::terminal_set( $object_id, $meta_key, $meta_value );
	}

	/** pre_update_post_metadata filter. @return mixed */
	public static function filter_update( $check, $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		if ( null !== $check || ! self::is_fenced() || ! self::key_applies_to_object( $object_id, $meta_key ) ) { return $check; }
		$current = self::terminal_current( $object_id, $meta_key );
		if ( '' !== (string) $prev_value && ( ! $current['present'] || (string) $current['value'] !== (string) $prev_value ) ) { return false; }
		return self::terminal_set( $object_id, $meta_key, $meta_value );
	}

	/** pre_delete_post_metadata filter. @return mixed */
	public static function filter_delete( $check, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
		if ( null !== $check || ! self::is_fenced() ) { return $check; }
		if ( $delete_all && isset( self::managed_keys()[ (string) $meta_key ] ) ) { return false; }
		if ( ! self::key_applies_to_object( $object_id, $meta_key ) ) { return $check; }
		$current = self::terminal_current( $object_id, $meta_key );
		if ( '' !== (string) $meta_value && ( ! $current['present'] || (string) $current['value'] !== (string) $meta_value ) ) { return false; }
		$type = get_post_type( $object_id ); $family = self::managed_keys()[ (string) $meta_key ];
		if ( 'product' === $type && in_array( $family, array( 'variant_guid','portal_variant_id' ), true ) ) {
			$repo = new Mobo_Core_Product_State_Repository();
			return 'variant_guid' === $family ? $repo->terminal_set_simple_identity( $object_id, '', null ) : $repo->terminal_set_simple_identity( $object_id, null, 0 );
		}
		if ( 'trash' === get_post_status( $object_id ) ) {
			/* Trashed Product identity is retained in Custom Storage for Repair recovery. */
			return 'product_variation' === $type ? ( new Mobo_Core_Variant_State_Repository() )->delete_by_woo_id( $object_id ) : false;
		}
		/* Core ownership identity on a live object is never generically deletable. */
		return false;
	}

	/** @return array{present:bool,value:mixed} */
	private static function terminal_current( $object_id, $meta_key ) {
		$object_id=absint($object_id);$meta_key=(string)$meta_key;$family=self::managed_keys()[$meta_key]??'';$type=get_post_type($object_id);
		if('product'===$type){$row=(new Mobo_Core_Product_State_Repository())->get_by_woo_product_id($object_id);if(!is_array($row)){return array('present'=>false,'value'=>'');}
			if('product_guid'===$family){$v=sanitize_text_field((string)($row['product_guid']??''));return array('present'=>Mobo_Core_Remote_Identity_Policy::is_valid($v),'value'=>$v);}
			if('portal_product_id'===$family){$v=absint($row['portal_product_id']??0);return array('present'=>$v>0,'value'=>$v);}
			if('variant_guid'===$family){$v=sanitize_text_field((string)($row['simple_variant_guid']??''));return array('present'=>Mobo_Core_Remote_Identity_Policy::is_valid($v),'value'=>$v);}
			$v=absint($row['simple_portal_variant_id']??0);return array('present'=>$v>0,'value'=>$v);
		}
		if('product_variation'===$type){$vrow=(new Mobo_Core_Variant_State_Repository())->get_by_woo_variation_id($object_id);
			/* A Mobo parent does not own every merchant-created child. Project parent
			 * identity only for a Variation bound in this Connection's Custom Storage. */
			if(!is_array($vrow)){return array('present'=>false,'value'=>'');}
			$parent=absint(wp_get_post_parent_id($object_id));$prow=$parent>0?(new Mobo_Core_Product_State_Repository())->get_by_woo_product_id($parent):null;
			if('product_guid'===$family){$v=sanitize_text_field((string)($vrow['product_guid']??''));return array('present'=>Mobo_Core_Remote_Identity_Policy::is_valid($v),'value'=>$v);}
			if('portal_product_id'===$family){$v=is_array($prow)?absint($prow['portal_product_id']??0):0;return array('present'=>$v>0,'value'=>$v);}
			if('variant_guid'===$family){$v=is_array($vrow)?sanitize_text_field((string)($vrow['variant_guid']??'')):'';return array('present'=>Mobo_Core_Remote_Identity_Policy::is_valid($v),'value'=>$v);}
			$v=is_array($vrow)?absint($vrow['portal_variant_id']??0):0;return array('present'=>$v>0,'value'=>$v);
		}
		return array('present'=>false,'value'=>'');
	}

	/** @return bool */
	private static function terminal_set( $object_id, $meta_key, $meta_value ) {
		$object_id=absint($object_id);$family=self::managed_keys()[(string)$meta_key]??'';$type=get_post_type($object_id);
		if('product'===$type){$repo=new Mobo_Core_Product_State_Repository();
			if('product_guid'===$family){$guid=sanitize_text_field((string)$meta_value);return Mobo_Core_Remote_Identity_Policy::is_valid($guid)&&$repo->terminal_bind_product($object_id,$guid);}
			if('portal_product_id'===$family){$id=absint($meta_value);return $id>0&&$repo->terminal_set_portal_product_id($object_id,$id);}
			if('variant_guid'===$family){$guid=sanitize_text_field((string)$meta_value);return Mobo_Core_Remote_Identity_Policy::is_valid($guid)&&$repo->terminal_set_simple_identity($object_id,$guid,null);}
			$id=absint($meta_value);return $id>0&&$repo->terminal_set_simple_identity($object_id,null,$id);
		}
		if('product_variation'===$type){$parent=absint(wp_get_post_parent_id($object_id));if($parent<=0){return false;}$prepo=new Mobo_Core_Product_State_Repository();$prow=$prepo->get_by_woo_product_id($parent);if(!is_array($prow)){return false;}$product_guid=sanitize_text_field((string)($prow['product_guid']??''));if(!Mobo_Core_Remote_Identity_Policy::is_valid($product_guid)){return false;}$vrepo=new Mobo_Core_Variant_State_Repository();
			if('variant_guid'===$family){$guid=sanitize_text_field((string)$meta_value);return Mobo_Core_Remote_Identity_Policy::is_valid($guid)&&$vrepo->terminal_bind_variant($object_id,$parent,$guid,$product_guid);}
			if('product_guid'===$family){return sanitize_text_field((string)$meta_value)===$product_guid;}
			if('portal_product_id'===$family){$id=absint($meta_value);if($id<=0){return false;}$current=absint($prow['portal_product_id']??0);return $current===$id||($current<=0&&$prepo->terminal_set_portal_product_id($parent,$id));}
			$id=absint($meta_value);return $id>0&&$vrepo->terminal_set_portal_variant_id($object_id,$id);
		}
		return false;
	}

	/**
	 * One bounded identity proof page.
	 *
	 * 10.34.77 hardening: deterministic per-object data problems (malformed or
	 * conflicting identity, missing GUIDs, a GUID already owned by a different Woo
	 * object such as a WooCommerce "Duplicate product" copy) no longer park the
	 * whole worker. The object is marked via Mobo_Core_Migration_Quarantine, keeps
	 * its legacy rows untouched and the page continues. Read errors still fail the
	 * step and are retried; mirror/verify failures are retried FAILURE_THRESHOLD
	 * times before the object is marked.
	 *
	 * @return array{complete:bool}
	 */
	private static function advance_exact_proof( $state ) {
		global $wpdb;$cursor=absint($state['proofCursor']??0);$keys=array_keys(self::managed_keys());$ph=implode(',',array_fill(0,count($keys),'%s'));$args=array_merge(array($cursor),$keys,array(self::proof_batch_size()));
		/* Objects owned by another Connection are never adopted into the Default Connection. */
		$excl=class_exists('Mobo_Core_Migration_Quarantine')?Mobo_Core_Migration_Quarantine::foreign_exclusion_sql('pm.post_id'):'';
		$rows=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.post_id>%d AND p.post_type IN ('product','product_variation') AND pm.meta_key IN ({$ph}){$excl} ORDER BY pm.post_id ASC LIMIT %d",$args));
		if(''!==(string)$wpdb->last_error){self::record_state_error($state,'proof_query_failed','proof-error');return array('complete'=>false);}if(empty($rows)){$state['proofComplete']=true;$state['status']='proof-complete';$state['lastError']='';self::persist_state($state);return array('complete'=>true);}

		/* Snapshot the bounded proof page (plus Variation parents) with set-based reads.
		 * These caches are request-local only: durable correctness still comes from the
		 * same mirrors, CAS checkpoint and terminal proofs, so any kill simply replays. */
		$proof_post_ids=array_values(array_unique(array_filter(array_map('absint',$rows))));
		if(!self::prime_proof_batch_cache($proof_post_ids)){self::record_state_error($state,'proof_batch_prefetch_failed','proof-error');return array('complete'=>false);}

		$recovered=0;$custom_recovered=0;$map_recovered=0;$stale_trashed_variation_residues=0;$proof_noops=0;$proof_mirrors=0;$proof_marked=0;
		foreach($rows as $post_id){
			$post_id=absint($post_id);$cursor=max($cursor,$post_id);$type=get_post_type($post_id);$status=sanitize_key((string)get_post_status($post_id));
			if('auto-draft'===$status){continue;}
			if(class_exists('Mobo_Core_Migration_Quarantine')&&Mobo_Core_Migration_Quarantine::is_marked($post_id)){continue;}
			if(null===self::legacy_rows_for_post($post_id)){self::record_state_error($state,'identity_rows_read_'.$post_id,'proof-error');return array('complete'=>false);}
			if(self::object_has_malformed_identity($post_id)){
				if(self::proof_mark($post_id,'malformed_identity','',$proof_marked)){continue;}
				self::record_state_error($state,'malformed_identity_'.$post_id,'proof-blocked');return array('complete'=>false);
			}
			$identity=self::resolve_proof_identity($post_id,$type);$identity_status=sanitize_key((string)($identity['status']??''));
			if('error'===$identity_status){self::record_state_error($state,'identity_recovery_read_'.$post_id,'proof-error');return array('complete'=>false);}
			if('conflict'===$identity_status){
				if(self::proof_mark($post_id,'identity_conflict','',$proof_marked)){continue;}
				self::record_state_error($state,('product'===$type?'product_identity_conflict_':'variation_identity_conflict_').$post_id,'proof-blocked');return array('complete'=>false);
			}
			if('product'===$type){
				$guid=sanitize_text_field((string)($identity['guid']??''));
				if(!Mobo_Core_Remote_Identity_Policy::is_valid($guid)){
					if(self::proof_mark($post_id,'missing_identity','product_guid',$proof_marked)){continue;}
					self::record_state_error($state,'product_guid_missing_'.$post_id,'proof-blocked');return array('complete'=>false);
				}
				/* Most objects were already materialized by Operational/backfill. Verify the
				 * set-based snapshot first and avoid replaying the heavyweight legacy bridge
				 * when Custom identity is already exact. A mismatch still uses the original
				 * mirror + fresh-read verification path. */
				if(!self::object_matches_custom($post_id,$type,$guid,'')){
					/* Never let a legacy copy steal a GUID that Custom Storage or the Product
					 * Map already binds to a different Woo object (the Custom upsert would
					 * otherwise rebind ownership to this copy). */
					$owner=self::guid_owned_by_other_object('product',$guid,$post_id);
					if(null===$owner){self::record_state_error($state,'identity_owner_read_'.$post_id,'proof-error');return array('complete'=>false);}
					if($owner>0){
						if(self::proof_mark($post_id,'identity_owned_by_other_object','#'.$owner,$proof_marked)){continue;}
						self::record_state_error($state,'product_identity_conflict_'.$post_id,'proof-blocked');return array('complete'=>false);
					}
					$mirrored=Mobo_Core_Storage_Bridge::mirror_product_legacy_state($guid,$post_id,null);
					if($mirrored){self::invalidate_product_state_cache($post_id);$mirrored=self::object_matches_custom($post_id,$type,$guid,'');}
					if(!$mirrored){
						if(self::proof_failure_mark($post_id,'product_identity_proof_'.$post_id,$proof_marked)){continue;}
						self::record_state_error($state,'product_identity_proof_'.$post_id,'proof-error');return array('complete'=>false);
					}
					$proof_mirrors++;
				}else{$proof_noops++;}
				if(!empty($identity['recovered'])){$recovered++;if('map'===(string)($identity['recovered_source']??'')){$map_recovered++;}else{$custom_recovered++;}}
				if(class_exists('Mobo_Core_Migration_Quarantine')){Mobo_Core_Migration_Quarantine::clear_failure($post_id);}
			}
			elseif('product_variation'===$type){
				$guid=sanitize_text_field((string)($identity['guid']??''));$product_guid=sanitize_text_field((string)($identity['parent_guid']??''));
				if(!Mobo_Core_Remote_Identity_Policy::is_valid($guid)){
					if('trash'===$status&&'missing'===$identity_status){
						$stale_status=self::trashed_variation_parent_only_residue_status($post_id);
						if('error'===$stale_status){self::record_state_error($state,'trash_variation_residue_read_'.$post_id,'proof-error');return array('complete'=>false);}
						if('stale'===$stale_status){$stale_trashed_variation_residues++;continue;}
						if('conflict'===$stale_status){
							if(self::proof_mark($post_id,'identity_conflict','trash',$proof_marked)){continue;}
							self::record_state_error($state,'variation_identity_conflict_'.$post_id,'proof-blocked');return array('complete'=>false);
						}
					}
					if(self::proof_mark($post_id,'missing_identity','variant_guid',$proof_marked)){continue;}
					self::record_state_error($state,'variation_guid_missing_'.$post_id,'proof-blocked');return array('complete'=>false);
				}
				if('parent-missing'===$identity_status||!Mobo_Core_Remote_Identity_Policy::is_valid($product_guid)){
					if(self::proof_mark($post_id,'parent_identity_missing','',$proof_marked)){continue;}
					self::record_state_error($state,'variation_parent_guid_missing_'.$post_id,'proof-blocked');return array('complete'=>false);
				}
				$parent_id=absint(wp_get_post_parent_id($post_id));
				if($parent_id>0&&class_exists('Mobo_Core_Migration_Quarantine')&&Mobo_Core_Migration_Quarantine::is_marked($parent_id)){
					if(self::proof_mark($post_id,'parent_quarantined','#'.$parent_id,$proof_marked)){continue;}
					self::record_state_error($state,'variation_parent_identity_conflict_'.$post_id,'proof-blocked');return array('complete'=>false);
				}
				/* The parent may be materialized by ensure_variation_parent_custom(); guard it
				 * against stealing exactly like a Product. */
				if($parent_id>0&&!self::object_matches_custom($parent_id,'product',$product_guid,'')){
					$parent_owner=self::guid_owned_by_other_object('product',$product_guid,$parent_id);
					if(null===$parent_owner){self::record_state_error($state,'identity_owner_read_'.$post_id,'proof-error');return array('complete'=>false);}
					if($parent_owner>0){
						if(self::proof_mark($post_id,'parent_identity_conflict','#'.$parent_owner,$proof_marked)){continue;}
						self::record_state_error($state,'variation_parent_identity_conflict_'.$post_id,'proof-blocked');return array('complete'=>false);
					}
				}
				$parent_custom=self::ensure_variation_parent_custom($post_id,$product_guid);
				if('conflict'===$parent_custom){
					if(self::proof_mark($post_id,'parent_identity_conflict','',$proof_marked)){continue;}
					self::record_state_error($state,'variation_parent_identity_conflict_'.$post_id,'proof-blocked');return array('complete'=>false);
				}
				if('ok'!==$parent_custom){
					if(self::proof_failure_mark($post_id,'variation_parent_identity_proof_'.$post_id,$proof_marked)){continue;}
					self::record_state_error($state,'variation_parent_identity_proof_'.$post_id,'proof-error');return array('complete'=>false);
				}
				if(!self::object_matches_custom($post_id,$type,$guid,$product_guid)){
					$owner=self::guid_owned_by_other_object('variation',$guid,$post_id);
					if(null===$owner){self::record_state_error($state,'identity_owner_read_'.$post_id,'proof-error');return array('complete'=>false);}
					if($owner>0){
						if(self::proof_mark($post_id,'identity_owned_by_other_object','#'.$owner,$proof_marked)){continue;}
						self::record_state_error($state,'variation_identity_conflict_'.$post_id,'proof-blocked');return array('complete'=>false);
					}
					$mirrored=Mobo_Core_Storage_Bridge::mirror_variant_legacy_state($guid,$post_id,$product_guid,null);
					if($mirrored){self::invalidate_variant_state_cache($post_id);$mirrored=self::object_matches_custom($post_id,$type,$guid,$product_guid);}
					if(!$mirrored){
						if(self::proof_failure_mark($post_id,'variation_identity_proof_'.$post_id,$proof_marked)){continue;}
						self::record_state_error($state,'variation_identity_proof_'.$post_id,'proof-error');return array('complete'=>false);
					}
					$proof_mirrors++;
				}else{$proof_noops++;}
				if(!empty($identity['recovered'])){$recovered++;if('map'===(string)($identity['recovered_source']??'')){$map_recovered++;}else{$custom_recovered++;}}
				if(class_exists('Mobo_Core_Migration_Quarantine')){Mobo_Core_Migration_Quarantine::clear_failure($post_id);}
			}
		}
		$state['proofCursor']=$cursor;$state['status']='proof-running';$state['lastError']='';if($recovered>0){$state['durableIdentityRecoveries']=absint($state['durableIdentityRecoveries']??0)+$recovered;}if($custom_recovered>0){$state['customIdentityRecoveries']=absint($state['customIdentityRecoveries']??0)+$custom_recovered;}if($map_recovered>0){$state['mapIdentityRecoveries']=absint($state['mapIdentityRecoveries']??0)+$map_recovered;}if($stale_trashed_variation_residues>0){$state['staleTrashedVariationResidues']=absint($state['staleTrashedVariationResidues']??0)+$stale_trashed_variation_residues;}if($proof_noops>0){$state['proofNoopVerified']=absint($state['proofNoopVerified']??0)+$proof_noops;}if($proof_mirrors>0){$state['proofMirrorWrites']=absint($state['proofMirrorWrites']??0)+$proof_mirrors;}if($proof_marked>0){$state['markedObjects']=absint($state['markedObjects']??0)+$proof_marked;}self::persist_state($state);return array('complete'=>false);
	}

	/** Mark one deterministic proof problem. @return bool False when marking is unavailable/full. */
	private static function proof_mark( $post_id, $reason, $detail, &$marked ) {
		if ( ! class_exists( 'Mobo_Core_Migration_Quarantine' ) || ! Mobo_Core_Migration_Quarantine::mark( $post_id, 'identity', $reason, $detail ) ) { return false; }
		$marked++;
		return true;
	}

	/** Count a mirror/verify failure; mark only after the retry threshold. @return bool */
	private static function proof_failure_mark( $post_id, $code, &$marked ) {
		if ( ! class_exists( 'Mobo_Core_Migration_Quarantine' ) || ! Mobo_Core_Migration_Quarantine::record_failure( $post_id ) ) { return false; }
		return self::proof_mark( $post_id, 'mirror_failed', $code, $marked );
	}

	/**
	 * Return the id of a different Woo object that already owns this GUID in the
	 * Default Connection's Custom Storage or Product Map, 0 when none, null on a
	 * read error.
	 *
	 * @param string $kind    product|variation.
	 * @param string $guid    Remote GUID.
	 * @param int    $post_id Object being proven.
	 * @return int|null
	 */
	private static function guid_owned_by_other_object( $kind, $guid, $post_id ) {
		global $wpdb;
		$post_id = absint( $post_id ); $guid = sanitize_text_field( (string) $guid );
		if ( ! Mobo_Core_Remote_Identity_Policy::is_valid( $guid ) ) { return 0; }
		$wpdb->last_error = '';
		if ( 'product' === $kind ) {
			$row = ( new Mobo_Core_Product_State_Repository() )->get_by_guid( $guid );
			if ( '' !== (string) $wpdb->last_error ) { return null; }
			$owner = is_array( $row ) ? absint( $row['woo_product_id'] ?? 0 ) : 0;
		} else {
			$row = ( new Mobo_Core_Variant_State_Repository() )->get_by_guid( $guid );
			if ( '' !== (string) $wpdb->last_error ) { return null; }
			$owner = is_array( $row ) ? absint( $row['woo_variation_id'] ?? 0 ) : 0;
		}
		if ( $owner > 0 && $owner !== $post_id ) { return $owner; }
		$map_connection = self::default_connection_id();
		if ( $map_connection <= 0 || ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists() ) { return 0; }
		$table = Mobo_Core_Product_Map::table_name();
		$object_type = 'product' === $kind ? Mobo_Core_Product_Map::TYPE_PRODUCT : Mobo_Core_Product_Map::TYPE_VARIATION;
		$wpdb->last_error = '';
		$mapped = $wpdb->get_var( $wpdb->prepare( "SELECT wp_post_id FROM {$table} WHERE connection_id=%d AND remote_guid=%s AND object_type=%s LIMIT 1", $map_connection, $guid, $object_type ) );
		if ( '' !== (string) $wpdb->last_error ) { return null; }
		$mapped = absint( $mapped );
		/* Only a live mapped owner counts; a stale map row to a deleted post is not ownership evidence. */
		if ( $mapped > 0 && $mapped !== $post_id && in_array( get_post_type( $mapped ), array( 'product', 'product_variation' ), true ) ) { return $mapped; }
		return 0;
	}

	/**
	 * Resolve one proof identity from every already-durable source without inventing
	 * identifiers. Missing legacy GUIDs may be recovered only from the Custom row
	 * bound to the exact Woo object ID; any disagreement remains fail-closed.
	 * @return array{status:string,guid:string,parent_guid:string,recovered:bool}
	 */
	private static function resolve_proof_identity( $post_id, $type ) {
		global $wpdb;$post_id=absint($post_id);$type=sanitize_key((string)$type);$guid_candidates=array();$parent_candidates=array();$legacy_guid='';$custom_guid=false;$map_guid=false;
		if('product'===$type){
			$legacy_guid=self::legacy_scalar($post_id,'product_guid');if(Mobo_Core_Remote_Identity_Policy::is_valid($legacy_guid)){$guid_candidates[]=$legacy_guid;}
			$wpdb->last_error='';$row=self::proof_product_state_row($post_id);if(''!==(string)$wpdb->last_error){return array('status'=>'error','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}
			if(is_array($row)){if(absint($row['woo_product_id']??0)!==$post_id){return array('status'=>'conflict','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}$custom=sanitize_text_field((string)($row['product_guid']??''));if(Mobo_Core_Remote_Identity_Policy::is_valid($custom)){$guid_candidates[]=$custom;$custom_guid=true;}}
			$mapped=self::exact_map_identity($post_id,Mobo_Core_Product_Map::TYPE_PRODUCT);if('error'===$mapped['status']){return array('status'=>'error','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}if('conflict'===$mapped['status']){return array('status'=>'conflict','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}if('ok'===$mapped['status']){$guid_candidates[]=(string)$mapped['guid'];$map_guid=true;}
		}else{
			if('product_variation'!==$type){return array('status'=>'missing','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}
			$parent=absint(wp_get_post_parent_id($post_id));if($parent<=0||'product'!==get_post_type($parent)){return array('status'=>'parent-missing','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}
			$legacy_guid=self::family_guid($post_id,array('variant_guid','mobo_variant_guid','_mobo_variant_guid'));if(Mobo_Core_Remote_Identity_Policy::is_valid($legacy_guid)){$guid_candidates[]=$legacy_guid;}
			$parent_legacy=self::legacy_scalar($parent,'product_guid');if(Mobo_Core_Remote_Identity_Policy::is_valid($parent_legacy)){$parent_candidates[]=$parent_legacy;}
			$stored_parent=self::legacy_scalar($post_id,'product_guid');if(Mobo_Core_Remote_Identity_Policy::is_valid($stored_parent)){$parent_candidates[]=$stored_parent;}
			$wpdb->last_error='';$vrow=self::proof_variant_state_row($post_id);if(''!==(string)$wpdb->last_error){return array('status'=>'error','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}
			if(is_array($vrow)){if(absint($vrow['woo_variation_id']??0)!==$post_id||absint($vrow['woo_product_id']??0)!==$parent){return array('status'=>'conflict','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}$vg=sanitize_text_field((string)($vrow['variant_guid']??''));$pg=sanitize_text_field((string)($vrow['product_guid']??''));if(Mobo_Core_Remote_Identity_Policy::is_valid($vg)){$guid_candidates[]=$vg;$custom_guid=true;}if(Mobo_Core_Remote_Identity_Policy::is_valid($pg)){$parent_candidates[]=$pg;}}
			$wpdb->last_error='';$prow=self::proof_product_state_row($parent);if(''!==(string)$wpdb->last_error){return array('status'=>'error','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}
			if(is_array($prow)){if(absint($prow['woo_product_id']??0)!==$parent){return array('status'=>'conflict','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}$pg=sanitize_text_field((string)($prow['product_guid']??''));if(Mobo_Core_Remote_Identity_Policy::is_valid($pg)){$parent_candidates[]=$pg;}}
			$mapped=self::exact_map_identity($post_id,Mobo_Core_Product_Map::TYPE_VARIATION);if('error'===$mapped['status']){return array('status'=>'error','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}if('conflict'===$mapped['status']){return array('status'=>'conflict','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}if('ok'===$mapped['status']){$guid_candidates[]=(string)$mapped['guid'];$map_guid=true;if(''!==(string)$mapped['parent_guid']){$parent_candidates[]=(string)$mapped['parent_guid'];}}
			$parent_mapped=self::exact_map_identity($parent,Mobo_Core_Product_Map::TYPE_PRODUCT);if('error'===$parent_mapped['status']){return array('status'=>'error','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}if('conflict'===$parent_mapped['status']){return array('status'=>'conflict','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}if('ok'===$parent_mapped['status']){$parent_candidates[]=(string)$parent_mapped['guid'];}
		}
		$guid_candidates=array_values(array_unique(array_filter(array_map('strval',$guid_candidates))));$parent_candidates=array_values(array_unique(array_filter(array_map('strval',$parent_candidates))));
		if(count($guid_candidates)>1||count($parent_candidates)>1){return array('status'=>'conflict','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}
		if(empty($guid_candidates)){return array('status'=>'missing','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}
		if('product_variation'===$type&&empty($parent_candidates)){return array('status'=>'parent-missing','guid'=>'','parent_guid'=>'','recovered'=>false,'recovered_source'=>'');}
		$guid=(string)$guid_candidates[0];$parent_guid='product_variation'===$type?(string)$parent_candidates[0]:'';$recovered=!Mobo_Core_Remote_Identity_Policy::is_valid($legacy_guid);$source=$recovered?($custom_guid?'custom':($map_guid?'map':'')):'';
		return array('status'=>'ok','guid'=>$guid,'parent_guid'=>$parent_guid,'recovered'=>$recovered,'recovered_source'=>$source);
	}

	/**
	 * Read a Product Map identity bound to one exact Woo object ID. The map owns
	 * uniqueness by remote GUID, not by wp_post_id, so migration accepts it only
	 * when the reverse binding is singular. Multiple rows, malformed GUIDs, or a
	 * malformed Variation parent GUID are durable ambiguity and remain fail-closed.
	 * @return array{status:string,guid:string,parent_guid:string}
	 */
	private static function exact_map_identity( $post_id, $object_type ) {
		global $wpdb;$post_id=absint($post_id);$object_type=sanitize_key((string)$object_type);
		if($post_id<=0||!class_exists('Mobo_Core_Product_Map')||!Mobo_Core_Product_Map::table_exists()){return array('status'=>'missing','guid'=>'','parent_guid'=>'');}
		if(!in_array($object_type,array(Mobo_Core_Product_Map::TYPE_PRODUCT,Mobo_Core_Product_Map::TYPE_VARIATION),true)){return array('status'=>'conflict','guid'=>'','parent_guid'=>'');}
		$connection_id=self::default_connection_id();if($connection_id<=0){return array('status'=>'missing','guid'=>'','parent_guid'=>'');}
		$table=Mobo_Core_Product_Map::table_name();
		if(isset(self::$proof_map_scope[$post_id])){$rows=self::$proof_map_rows[$post_id.'|'.$object_type]??array();}
		else{$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare("SELECT remote_guid,parent_remote_guid,wp_post_id,object_type FROM {$table} WHERE connection_id=%d AND wp_post_id=%d AND object_type=%s ORDER BY id ASC LIMIT 2",$connection_id,$post_id,$object_type),ARRAY_A);if(''!==(string)$wpdb->last_error||!is_array($rows)){return array('status'=>'error','guid'=>'','parent_guid'=>'');}}
		if(empty($rows)){return array('status'=>'missing','guid'=>'','parent_guid'=>'');}
		if(1!==count($rows)){return array('status'=>'conflict','guid'=>'','parent_guid'=>'');}
		$row=$rows[0];if(absint($row['wp_post_id']??0)!==$post_id||sanitize_key((string)($row['object_type']??''))!==$object_type){return array('status'=>'conflict','guid'=>'','parent_guid'=>'');}
		$guid=sanitize_text_field((string)($row['remote_guid']??''));if(!Mobo_Core_Remote_Identity_Policy::is_valid($guid)){return array('status'=>'conflict','guid'=>'','parent_guid'=>'');}
		$parent_guid=sanitize_text_field((string)($row['parent_remote_guid']??''));if(Mobo_Core_Product_Map::TYPE_VARIATION===$object_type&&''!==$parent_guid&&!Mobo_Core_Remote_Identity_Policy::is_valid($parent_guid)){return array('status'=>'conflict','guid'=>'','parent_guid'=>'');}
		return array('status'=>'ok','guid'=>$guid,'parent_guid'=>Mobo_Core_Product_Map::TYPE_VARIATION===$object_type?$parent_guid:'');
	}


	/**
	 * Recognize a trashed Woo Variation that never had durable Variation identity
	 * of its own and only retains a stale copy of its parent Product identity.
	 *
	 * This is intentionally narrower than "trash means ignorable". The residue is
	 * skippable only when there is no Variant State row, no Variation Product Map
	 * binding, no non-empty Variant GUID/Portal Variant ID metadata, and every
	 * surviving Product-level identity value agrees with a singular durable parent
	 * Product identity proven by Product State and/or Product Map. Any ambiguity or disagreement remains fail-closed.
	 *
	 * @return string stale|not-stale|conflict|error
	 */
	private static function trashed_variation_parent_only_residue_status( $post_id ) {
		global $wpdb;$post_id=absint($post_id);
		if($post_id<=0||'product_variation'!==get_post_type($post_id)||'trash'!==sanitize_key((string)get_post_status($post_id))){return 'not-stale';}
		$parent=absint(wp_get_post_parent_id($post_id));if($parent<=0||'product'!==get_post_type($parent)){return 'not-stale';}
		$wpdb->last_error='';$vrow=self::proof_variant_state_row($post_id);if(''!==(string)$wpdb->last_error){return 'error';}if(is_array($vrow)){return 'not-stale';}
		$mapped=self::exact_map_identity($post_id,Mobo_Core_Product_Map::TYPE_VARIATION);if('error'===$mapped['status']){return 'error';}if('conflict'===$mapped['status']){return 'conflict';}if('ok'===$mapped['status']){return 'not-stale';}
		/* Parent identity may already be in Product State, or it may still exist only
		 * in the durable Product Map while Product State backfill has not reached the
		 * parent yet. Accept either durable source, but only when all available parent
		 * evidence agrees exactly. This keeps trash-residue handling fail-closed while
		 * avoiding a false dependency on Product State backfill ordering. */
		if('publish'!==sanitize_key((string)get_post_status($parent))){return 'not-stale';}
		$parent_guid_candidates=array();$parent_portal_candidates=array();$has_durable_parent_identity=false;
		$wpdb->last_error='';$prow=self::proof_product_state_row($parent);if(''!==(string)$wpdb->last_error){return 'error';}
		if(is_array($prow)){if(absint($prow['woo_product_id']??0)!==$parent){return 'conflict';}$state_guid=sanitize_text_field((string)($prow['product_guid']??''));if(!Mobo_Core_Remote_Identity_Policy::is_valid($state_guid)){return 'conflict';}$parent_guid_candidates[]=$state_guid;$state_portal_id=absint($prow['portal_product_id']??0);if($state_portal_id>0){$parent_portal_candidates[]=$state_portal_id;}$has_durable_parent_identity=true;}
		$parent_mapped=self::exact_map_identity($parent,Mobo_Core_Product_Map::TYPE_PRODUCT);if('error'===$parent_mapped['status']){return 'error';}if('conflict'===$parent_mapped['status']){return 'conflict';}if('ok'===$parent_mapped['status']){$parent_guid_candidates[]=(string)$parent_mapped['guid'];$has_durable_parent_identity=true;}
		$parent_identity_keys=array('product_guid','portal_product_id','mobo_portal_product_id','_mobo_portal_product_id','PortalProductId');$parent_rows=self::legacy_rows_for_post($parent);if(null===$parent_rows){return 'error';}
		foreach($parent_rows as $parent_row){$parent_key=(string)($parent_row['meta_key']??'');if(!in_array($parent_key,$parent_identity_keys,true)){continue;}$parent_raw=trim((string)maybe_unserialize($parent_row['meta_value']??''));if(''===$parent_raw){continue;}if('product_guid'===$parent_key){$parent_legacy_guid=sanitize_text_field($parent_raw);if(!Mobo_Core_Remote_Identity_Policy::is_valid($parent_legacy_guid)){return 'conflict';}$parent_guid_candidates[]=$parent_legacy_guid;continue;}if(!preg_match('/^[0-9]+$/',$parent_raw)||absint($parent_raw)<=0){return 'conflict';}$parent_portal_candidates[]=absint($parent_raw);}
		$parent_guid_candidates=array_values(array_unique(array_filter(array_map('strval',$parent_guid_candidates))));$parent_portal_candidates=array_values(array_unique(array_map('absint',$parent_portal_candidates)));
		if(!$has_durable_parent_identity||1!==count($parent_guid_candidates)||count($parent_portal_candidates)>1){return 'conflict';}
		$parent_guid=(string)$parent_guid_candidates[0];$parent_portal_id=1===count($parent_portal_candidates)?absint($parent_portal_candidates[0]):0;
		$rows=self::legacy_rows_for_post($post_id);if(null===$rows){return 'error';}
		$saw_parent_identity=false;
		foreach($rows as $row){$key=(string)($row['meta_key']??'');$family=self::managed_keys()[$key]??'';$raw=trim((string)maybe_unserialize($row['meta_value']??''));if(''===$raw){continue;}
			if(in_array($family,array('variant_guid','portal_variant_id'),true)){return 'not-stale';}
			if('product_guid'===$family){$value=sanitize_text_field($raw);if(!Mobo_Core_Remote_Identity_Policy::is_valid($value)||$value!==$parent_guid){return 'conflict';}$saw_parent_identity=true;continue;}
			if('portal_product_id'===$family){if(!preg_match('/^[0-9]+$/',$raw)||absint($raw)<=0||$parent_portal_id<=0||absint($raw)!==$parent_portal_id){return 'conflict';}$saw_parent_identity=true;continue;}
			return 'conflict';
		}
		return $saw_parent_identity?'stale':'not-stale';
	}

	/**
	 * Materialize and verify the parent Product State before proving a live Variation.
	 *
	 * Proof scans Woo objects by post ID, so a Variation may be visited before its
	 * parent Product. A valid Variant State can therefore exist while Parent Product
	 * State has not yet been mirrored. Resolve the parent against all durable evidence,
	 * require exact agreement with the Variation's resolved parent GUID, then use the
	 * normal Product bridge and parity check. No provisional parity exception is used.
	 *
	 * @return string ok|conflict|error
	 */
	private static function ensure_variation_parent_custom( $variation_id, $resolved_product_guid ) {
		$variation_id=absint($variation_id);$resolved_product_guid=sanitize_text_field((string)$resolved_product_guid);
		if($variation_id<=0||'product_variation'!==get_post_type($variation_id)||!Mobo_Core_Remote_Identity_Policy::is_valid($resolved_product_guid)){return 'conflict';}
		$parent=absint(wp_get_post_parent_id($variation_id));if($parent<=0||'product'!==get_post_type($parent)){return 'conflict';}
		$parent_identity=self::resolve_proof_identity($parent,'product');$status=sanitize_key((string)($parent_identity['status']??''));
		if('error'===$status){return 'error';}if('ok'!==$status){return 'conflict';}
		$parent_guid=sanitize_text_field((string)($parent_identity['guid']??''));if(!Mobo_Core_Remote_Identity_Policy::is_valid($parent_guid)||$parent_guid!==$resolved_product_guid){return 'conflict';}
		/* Parent Product may already be exact in the page snapshot. Do not replay the
		 * full Product bridge for every Variation unless recovery is actually needed. */
		if(self::object_matches_custom($parent,'product',$parent_guid,'')){return 'ok';}
		if(!Mobo_Core_Storage_Bridge::mirror_product_legacy_state($parent_guid,$parent,null)){return 'error';}
		self::invalidate_product_state_cache($parent);
		return self::object_matches_custom($parent,'product',$parent_guid,'')?'ok':'error';
	}

	/** @return bool */
	private static function object_matches_custom( $post_id, $type, $resolved_guid = '', $resolved_parent_guid = '' ) {
		if('product'===$type){$row=self::proof_product_state_row($post_id);if(!is_array($row)){return false;}$guid=Mobo_Core_Remote_Identity_Policy::is_valid($resolved_guid)?sanitize_text_field((string)$resolved_guid):self::legacy_scalar($post_id,'product_guid');if((string)($row['product_guid']??'')!==$guid){return false;}$pid=self::family_int($post_id,array('portal_product_id','mobo_portal_product_id','_mobo_portal_product_id','PortalProductId'));if($pid>0&&absint($row['portal_product_id']??0)!==$pid){return false;}$vg=self::family_guid($post_id,array('variant_guid','mobo_variant_guid','_mobo_variant_guid'));$vid=self::family_int($post_id,array('portal_variant_id','mobo_portal_variant_id','_mobo_portal_variant_id','PortalVariantId'));if(''!==$vg&&(string)($row['simple_variant_guid']??'')!==$vg){return false;}if($vid>0&&absint($row['simple_portal_variant_id']??0)!==$vid){return false;}return true;}
		$row=self::proof_variant_state_row($post_id);if(!is_array($row)){return false;}$vg=Mobo_Core_Remote_Identity_Policy::is_valid($resolved_guid)?sanitize_text_field((string)$resolved_guid):self::family_guid($post_id,array('variant_guid','mobo_variant_guid','_mobo_variant_guid'));$pid=self::family_int($post_id,array('portal_variant_id','mobo_portal_variant_id','_mobo_portal_variant_id','PortalVariantId'));$parent=absint(wp_get_post_parent_id($post_id));$pg=Mobo_Core_Remote_Identity_Policy::is_valid($resolved_parent_guid)?sanitize_text_field((string)$resolved_parent_guid):($parent>0?self::legacy_scalar($parent,'product_guid'):'');$stored_pg=self::legacy_scalar($post_id,'product_guid');if(''!==$stored_pg&&$stored_pg!==$pg){return false;}if((string)($row['variant_guid']??'')!==$vg||(string)($row['product_guid']??'')!==$pg||absint($row['woo_product_id']??0)!==$parent){return false;}if($pid>0&&absint($row['portal_variant_id']??0)!==$pid){return false;}$ppid=self::family_int($post_id,array('portal_product_id','mobo_portal_product_id','_mobo_portal_product_id','PortalProductId'));if($ppid>0){$prow=self::proof_product_state_row($parent);if(!is_array($prow)||absint($prow['portal_product_id']??0)!==$ppid){return false;}}return true;
	}

	/**
	 * Bounded normalization before the global identity proof: collapse exact
	 * duplicate identity rows, mark objects whose duplicate rows disagree and
	 * objects whose alias keys carry different values.
	 *
	 * @return array{progress:bool,deduped:int,rowsRemoved:int,marked:int,blockers:array}
	 */
	private static function normalize_global_blockers() {
		$out = array( 'progress' => false, 'deduped' => 0, 'rowsRemoved' => 0, 'marked' => 0, 'blockers' => array() );
		if ( ! class_exists( 'Mobo_Core_Migration_Quarantine' ) ) { return $out; }
		$limit = class_exists( 'Mobo_Core_Migration' ) && Mobo_Core_Migration::is_accelerated_context() ? 200 : 50;
		$dedupe = Mobo_Core_Migration_Quarantine::dedupe_exact_duplicate_meta( array_keys( self::managed_keys() ), 'identity', $limit );
		if ( '' !== $dedupe['error'] ) { $out['blockers'] = array( 'duplicate_normalization_error' => 1 ); return $out; }
		if ( ! empty( $dedupe['full'] ) ) { $out['blockers'] = array( 'quarantine_capacity_reached' => 1 ); return $out; }
		$out['deduped'] = $dedupe['deduped']; $out['rowsRemoved'] = $dedupe['rowsRemoved']; $out['marked'] = $dedupe['marked'];
		if ( $dedupe['deduped'] > 0 || $dedupe['marked'] > 0 || ! empty( $dedupe['more'] ) ) { $out['progress'] = true; return $out; }
		foreach ( array( array( 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid' ), array( 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id', 'PortalProductId' ), array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id', 'PortalVariantId' ) ) as $family ) {
			$ids = self::find_conflicting_alias_objects( $family, $limit );
			if ( null === $ids ) { $out['blockers'] = array( 'alias_conflict_query_error' => 1 ); return $out; }
			foreach ( $ids as $post_id ) {
				if ( ! Mobo_Core_Migration_Quarantine::mark( $post_id, 'identity', 'alias_conflict', $family[0] ) ) { $out['blockers'] = array( 'quarantine_capacity_reached' => 1 ); return $out; }
				$out['marked']++; $out['progress'] = true;
			}
		}
		return $out;
	}

	/** @return int[]|null */
	private static function find_conflicting_alias_objects( $keys, $limit ) {
		global $wpdb;$ph=implode(',',array_fill(0,count($keys),'%s'));$excl=self::retirement_exclusion_sql();
		$sql="SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND pm.meta_key IN ({$ph}) AND TRIM(pm.meta_value)<>''{$excl} GROUP BY pm.post_id HAVING COUNT(DISTINCT TRIM(pm.meta_value))>1 ORDER BY pm.post_id ASC LIMIT %d";
		$wpdb->last_error='';$ids=$wpdb->get_col($wpdb->prepare($sql,array_merge($keys,array(max(1,absint($limit))))));
		if(''!==(string)$wpdb->last_error||!is_array($ids)){return null;}
		return array_values(array_filter(array_map('absint',$ids)));
	}

	/** @return array<string,int> */
	private static function global_blockers() {
		global $wpdb;$out=array();$keys=array_keys(self::managed_keys());$ph=implode(',',array_fill(0,count($keys),'%s'));$excl=self::retirement_exclusion_sql();
		$wpdb->last_error='';$dup=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND pm.meta_key IN ({$ph}){$excl} GROUP BY pm.post_id,pm.meta_key HAVING COUNT(*)>1 LIMIT 1",$keys));if(''!==(string)$wpdb->last_error){return array('duplicate_query_error'=>1);}if(null!==$dup){$out['duplicate_identity_meta']=1;}
		/* Alias families must not disagree. Healthy databases stop at the first conflict. */
		foreach(array(array('variant_guid','mobo_variant_guid','_mobo_variant_guid'),array('portal_product_id','mobo_portal_product_id','_mobo_portal_product_id','PortalProductId'),array('portal_variant_id','mobo_portal_variant_id','_mobo_portal_variant_id','PortalVariantId')) as $family){$conf=self::has_conflicting_alias_object($family);if(null===$conf){$out['alias_conflict_query_error']=1;break;}if($conf){$out['conflicting_identity_aliases']=1;break;}}
		return $out;
	}

	/** @return bool|null */
	private static function has_conflicting_alias_object( $keys ) {
		global $wpdb;$ph=implode(',',array_fill(0,count($keys),'%s'));$excl=self::retirement_exclusion_sql();$sql="SELECT 1 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND pm.meta_key IN ({$ph}) AND TRIM(pm.meta_value)<>''{$excl} GROUP BY pm.post_id HAVING COUNT(DISTINCT TRIM(pm.meta_value))>1 LIMIT 1";$wpdb->last_error='';$hit=$wpdb->get_var($wpdb->prepare($sql,$keys));return ''!==(string)$wpdb->last_error?null:null!==$hit;
	}

	/**
	 * Cleanup/residue scope exclusion: marked objects keep their (never proven)
	 * legacy rows and objects owned by another Connection are not the Default
	 * Connection's to delete. The marked count is reported separately.
	 *
	 * @return string
	 */
	private static function retirement_exclusion_sql() {
		return class_exists( 'Mobo_Core_Migration_Quarantine' ) ? Mobo_Core_Migration_Quarantine::exclusion_sql( 'pm.post_id' ) : '';
	}

	/**
	 * Post-fence re-probe of one marked object (only after the DB version is
	 * stamped, under the migration lock, in the Default Connection context).
	 *
	 * The object is cleared when it no longer carries legacy identity rows, or
	 * when its legacy identity is consistent and Custom Storage holds exactly the
	 * same identity for this Woo object (e.g. after Repair, after the conflicting
	 * copy was deleted, or after a transient mirror failure). In the last case the
	 * terminal (non-stealing) Custom bind API is used to fill only missing values;
	 * existing Custom values are never overwritten. Only then are the redundant
	 * legacy identity rows of this object removed.
	 *
	 * @param int $post_id Woo object id.
	 * @return string clear|keep|error
	 */
	public static function reprobe_quarantined_object( $post_id ) {
		global $wpdb;
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! self::is_fenced() ) { return 'keep'; }
		self::reset_proof_batch_cache();
		$rows = self::legacy_rows_for_post( $post_id );
		if ( null === $rows ) { return 'error'; }
		if ( empty( $rows ) ) { return 'clear'; }
		$type = get_post_type( $post_id );
		if ( ! in_array( $type, array( 'product', 'product_variation' ), true ) ) { return 'clear'; }
		if ( self::object_has_malformed_identity( $post_id ) ) { return 'keep'; }
		$identity = self::resolve_proof_identity( $post_id, $type );
		$status = sanitize_key( (string) ( $identity['status'] ?? '' ) );
		if ( 'error' === $status ) { return 'error'; }
		if ( 'product_variation' === $type && 'missing' === $status && 'trash' === sanitize_key( (string) get_post_status( $post_id ) ) ) {
			$stale = self::trashed_variation_parent_only_residue_status( $post_id );
			if ( 'error' === $stale ) { return 'error'; }
			if ( 'stale' === $stale ) { return self::delete_legacy_identity_rows( $post_id ) ? 'clear' : 'error'; }
		}
		if ( 'ok' !== $status ) { return 'keep'; }
		$guid = sanitize_text_field( (string) ( $identity['guid'] ?? '' ) );
		if ( 'product' === $type ) {
			if ( ! self::object_matches_custom( $post_id, 'product', $guid, '' ) ) {
				$owner = self::guid_owned_by_other_object( 'product', $guid, $post_id );
				if ( null === $owner ) { return 'error'; }
				if ( $owner > 0 ) { return 'keep'; }
				$repo = new Mobo_Core_Product_State_Repository();
				if ( ! $repo->terminal_bind_product( $post_id, $guid ) ) { return 'keep'; }
				$row = $repo->get_by_woo_product_id( $post_id );
				if ( ! is_array( $row ) ) { return 'keep'; }
				$pid = self::family_int( $post_id, array( 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id', 'PortalProductId' ) );
				if ( $pid > 0 && absint( $row['portal_product_id'] ?? 0 ) <= 0 ) { $repo->terminal_set_portal_product_id( $post_id, $pid ); }
				$vg = self::family_guid( $post_id, array( 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid' ) );
				$vid = self::family_int( $post_id, array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id', 'PortalVariantId' ) );
				$set_vg = ( '' !== $vg && '' === (string) ( $row['simple_variant_guid'] ?? '' ) ) ? $vg : null;
				$set_vid = ( $vid > 0 && absint( $row['simple_portal_variant_id'] ?? 0 ) <= 0 ) ? $vid : null;
				if ( null !== $set_vg || null !== $set_vid ) { $repo->terminal_set_simple_identity( $post_id, $set_vg, $set_vid ); }
				self::invalidate_product_state_cache( $post_id );
				if ( ! self::object_matches_custom( $post_id, 'product', $guid, '' ) ) { return 'keep'; }
			}
			return self::delete_legacy_identity_rows( $post_id ) ? 'clear' : 'error';
		}
		$product_guid = sanitize_text_field( (string) ( $identity['parent_guid'] ?? '' ) );
		$parent = absint( wp_get_post_parent_id( $post_id ) );
		if ( $parent <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) ) { return 'keep'; }
		if ( class_exists( 'Mobo_Core_Migration_Quarantine' ) && Mobo_Core_Migration_Quarantine::is_marked( $parent ) ) { return 'keep'; }
		$prow = ( new Mobo_Core_Product_State_Repository() )->get_by_woo_product_id( $parent );
		if ( ! is_array( $prow ) || (string) ( $prow['product_guid'] ?? '' ) !== $product_guid ) { return 'keep'; }
		if ( ! self::object_matches_custom( $post_id, 'product_variation', $guid, $product_guid ) ) {
			$owner = self::guid_owned_by_other_object( 'variation', $guid, $post_id );
			if ( null === $owner ) { return 'error'; }
			if ( $owner > 0 ) { return 'keep'; }
			$vrepo = new Mobo_Core_Variant_State_Repository();
			if ( ! $vrepo->terminal_bind_variant( $post_id, $parent, $guid, $product_guid ) ) { return 'keep'; }
			$vrow = $vrepo->get_by_woo_variation_id( $post_id );
			$vid = self::family_int( $post_id, array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id', 'PortalVariantId' ) );
			if ( is_array( $vrow ) && $vid > 0 && absint( $vrow['portal_variant_id'] ?? 0 ) <= 0 ) { $vrepo->terminal_set_portal_variant_id( $post_id, $vid ); }
			self::invalidate_variant_state_cache( $post_id );
			if ( ! self::object_matches_custom( $post_id, 'product_variation', $guid, $product_guid ) ) { return 'keep'; }
		}
		return self::delete_legacy_identity_rows( $post_id ) ? 'clear' : 'error';
	}

	/** Remove this object's (now redundant) legacy identity rows. @return bool */
	private static function delete_legacy_identity_rows( $post_id ) {
		global $wpdb;$post_id=absint($post_id);if($post_id<=0){return false;}$keys=array_keys(self::managed_keys());$ph=implode(',',array_fill(0,count($keys),'%s'));
		$wpdb->last_error='';$deleted=$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ({$ph})",array_merge(array($post_id),$keys)));
		if(false===$deleted||''!==(string)$wpdb->last_error){return false;}wp_cache_delete($post_id,'post_meta');return true;
	}

	/** @return bool */
	private static function activate_fence() {
		$state=self::load_state();if(!empty($state['__stateLoadError'])){return false;}update_option(self::FENCE_OPTION,self::FENCE_VERSION,false);if(self::FENCE_VERSION!==absint(get_option(self::FENCE_OPTION,0))){self::record_state_error($state,'fence_marker_write_failed','fence-error');return false;}$state['fenceActive']=true;$state['fenceVersion']=self::FENCE_VERSION;$state['fenceActivatedAt']=time();$state['status']='fenced-grace';$state['blockers']=array();$state['lastError']='';if(!self::persist_state($state)){self::$fenced=true;return false;}self::$fenced=true;return true;
	}

	/** Cursor-based physical cleanup; a failed/stale checkpoint can only replay, never skip. @return array|false */
	private static function cleanup_one_batch( $cursor ) {
		global $wpdb;$cursor=absint($cursor);$keys=array_keys(self::managed_keys());$ph=implode(',',array_fill(0,count($keys),'%s'));$args=array_merge(array($cursor),$keys,array(self::cleanup_batch_size()));$excl=self::retirement_exclusion_sql();$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare("SELECT pm.meta_id,pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_id>%d AND p.post_type IN ('product','product_variation') AND pm.meta_key IN ({$ph}){$excl} ORDER BY pm.meta_id ASC LIMIT %d",$args),ARRAY_A);if(''!==(string)$wpdb->last_error){return false;}if(empty($rows)){return array('deleted'=>0,'cursor'=>$cursor);}$ids=array();$posts=array();$next=$cursor;foreach($rows as $row){$id=absint($row['meta_id']??0);if($id>0){$ids[]=$id;$next=max($next,$id);}$post_id=absint($row['post_id']??0);if($post_id>0){$posts[$post_id]=true;}}$ids=array_values(array_filter($ids));if(empty($ids)){return false;}$idph=implode(',',array_fill(0,count($ids),'%d'));$wpdb->last_error='';$deleted=$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ({$idph})",$ids));if(false===$deleted||''!==(string)$wpdb->last_error){return false;}foreach(array_keys($posts) as $post_id){wp_cache_delete($post_id,'post_meta');}return array('deleted'=>absint($deleted),'cursor'=>$next);
	}

	/** Fast boolean residue probe used by prerequisite/complete gates. @return bool|null */
	private static function has_physical_residue() {
		global $wpdb;$keys=array_keys(self::managed_keys());$ph=implode(',',array_fill(0,count($keys),'%s'));$excl=self::retirement_exclusion_sql();$wpdb->last_error='';$hit=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND pm.meta_key IN ({$ph}){$excl} LIMIT 1",$keys));if(''!==(string)$wpdb->last_error){return null;}return null!==$hit;
	}

	/** Durable terminal prerequisite with request-local zero-residue caching. @return bool */
	public static function is_physically_retired() {
		if(!self::is_fenced()){return false;}$state=self::load_state();if(empty($state['complete'])){return false;}if(self::$zero_residue_verified){return true;}$has=self::has_physical_residue();if(null===$has||$has){return false;}self::$zero_residue_verified=true;return true;
	}

	/** @return int|null */
	public static function physical_residue_count() {
		global $wpdb;$keys=array_keys(self::managed_keys());$ph=implode(',',array_fill(0,count($keys),'%s'));$excl=self::retirement_exclusion_sql();$wpdb->last_error='';$count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND pm.meta_key IN ({$ph}){$excl}",$keys));return ''!==(string)$wpdb->last_error?null:absint($count);
	}

	/** Reset request-local proof snapshot. @return void */
	private static function reset_proof_batch_cache() {
		self::$proof_legacy_scope=array();self::$proof_legacy_rows=array();self::$proof_legacy_first=array();self::$proof_product_scope=array();self::$proof_product_rows=array();self::$proof_variant_scope=array();self::$proof_variant_rows=array();self::$proof_map_scope=array();self::$proof_map_rows=array();
	}

	/** Set-based snapshot for one bounded proof page plus its parents. @return bool */
	private static function prime_proof_batch_cache( $post_ids ) {
		global $wpdb;self::reset_proof_batch_cache();$post_ids=array_values(array_unique(array_filter(array_map('absint',(array)$post_ids))));if(empty($post_ids)){return true;}
		if(function_exists('_prime_post_caches')){_prime_post_caches($post_ids,false,false);}
		$parents=array();foreach($post_ids as $post_id){if('product_variation'===get_post_type($post_id)){$parent=absint(wp_get_post_parent_id($post_id));if($parent>0){$parents[$parent]=true;}}}
		$scope=array_values(array_unique(array_merge($post_ids,array_keys($parents))));if(function_exists('_prime_post_caches')&&!empty($parents)){_prime_post_caches(array_keys($parents),false,false);}/* Do not prime the full WordPress postmeta cache here. Identity proof already snapshots only the managed keys below; retaining full meta for dozens of pages in one accelerated request caused unbounded request memory growth on large catalogs. */
		foreach($scope as $id){self::$proof_legacy_scope[$id]=true;self::$proof_product_scope[$id]=true;self::$proof_variant_scope[$id]=true;}
		$keys=array_keys(self::managed_keys());$idph=implode(',',array_fill(0,count($scope),'%d'));$keyph=implode(',',array_fill(0,count($keys),'%s'));$args=array_merge($scope,$keys);$wpdb->last_error='';
		$rows=$wpdb->get_results($wpdb->prepare("SELECT post_id,meta_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$idph}) AND meta_key IN ({$keyph}) ORDER BY post_id ASC,meta_id ASC",$args),ARRAY_A);if(''!==(string)$wpdb->last_error||!is_array($rows)){return false;}
		foreach($rows as $row){$id=absint($row['post_id']??0);$key=(string)($row['meta_key']??'');if($id<=0||''===$key){continue;}self::$proof_legacy_rows[$id][]=$row;if(!isset(self::$proof_legacy_first[$id])||!array_key_exists($key,self::$proof_legacy_first[$id])){self::$proof_legacy_first[$id][$key]=$row['meta_value']??'';}}
		$connection_id=self::connection_id();if($connection_id<=0){return false;}
		$pt=Mobo_Core_Storage_Schema::table_name('products');$wpdb->last_error='';$prows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$pt} WHERE connection_id=%d AND woo_product_id IN ({$idph})",array_merge(array($connection_id),$scope)),ARRAY_A);if(''!==(string)$wpdb->last_error||!is_array($prows)){return false;}foreach($prows as $row){$id=absint($row['woo_product_id']??0);if($id>0){self::$proof_product_rows[$id]=$row;}}
		$vt=Mobo_Core_Storage_Schema::table_name('variants');$wpdb->last_error='';$vrows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$vt} WHERE connection_id=%d AND woo_variation_id IN ({$idph})",array_merge(array($connection_id),$scope)),ARRAY_A);if(''!==(string)$wpdb->last_error||!is_array($vrows)){return false;}foreach($vrows as $row){$id=absint($row['woo_variation_id']??0);if($id>0){self::$proof_variant_rows[$id]=$row;}}
		$map_connection_id=self::default_connection_id();if($map_connection_id<=0){return false;}$map=Mobo_Core_Product_Map::table_name();$wpdb->last_error='';$mrows=$wpdb->get_results($wpdb->prepare("SELECT id,remote_guid,parent_remote_guid,wp_post_id,object_type FROM {$map} WHERE connection_id=%d AND wp_post_id IN ({$idph}) AND object_type IN ('product','variation') ORDER BY id ASC",array_merge(array($map_connection_id),$scope)),ARRAY_A);if(''!==(string)$wpdb->last_error||!is_array($mrows)){return false;}foreach($scope as $id){self::$proof_map_scope[$id]=true;}foreach($mrows as $row){$id=absint($row['wp_post_id']??0);$type=sanitize_key((string)($row['object_type']??''));if($id>0&&''!==$type){self::$proof_map_rows[$id.'|'.$type][]=$row;}}
		return true;
	}

	/** @return array|null */
	private static function proof_product_state_row( $post_id ) {$post_id=absint($post_id);if(isset(self::$proof_product_scope[$post_id])){return self::$proof_product_rows[$post_id]??null;}return (new Mobo_Core_Product_State_Repository())->get_by_woo_product_id($post_id);}
	/** @return array|null */
	private static function proof_variant_state_row( $post_id ) {$post_id=absint($post_id);if(isset(self::$proof_variant_scope[$post_id])){return self::$proof_variant_rows[$post_id]??null;}return (new Mobo_Core_Variant_State_Repository())->get_by_woo_variation_id($post_id);}
	/** After a mirror, force verification to read the newly persisted row. @return void */
	private static function invalidate_product_state_cache( $post_id ) {$post_id=absint($post_id);unset(self::$proof_product_scope[$post_id],self::$proof_product_rows[$post_id]);}
	/** @return void */
	private static function invalidate_variant_state_cache( $post_id ) {$post_id=absint($post_id);unset(self::$proof_variant_scope[$post_id],self::$proof_variant_rows[$post_id]);}

	/** @return array|null */
	private static function legacy_rows_for_post( $post_id ) {
		global $wpdb;$post_id=absint($post_id);if(isset(self::$proof_legacy_scope[$post_id])){return self::$proof_legacy_rows[$post_id]??array();}$keys=array_keys(self::managed_keys());$ph=implode(',',array_fill(0,count($keys),'%s'));$args=array_merge(array($post_id),$keys);$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare("SELECT post_id,meta_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ({$ph}) ORDER BY meta_id ASC",$args),ARRAY_A);return ''!==(string)$wpdb->last_error||!is_array($rows)?null:$rows;
	}

	/** @return string */
	private static function legacy_scalar( $post_id, $key ) {global $wpdb;$post_id=absint($post_id);$key=(string)$key;if(isset(self::$proof_legacy_scope[$post_id])){$v=self::$proof_legacy_first[$post_id][$key]??'';return is_scalar($v)?sanitize_text_field((string)maybe_unserialize($v)):'';}$wpdb->last_error='';$v=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id ASC LIMIT 1",$post_id,$key));return is_scalar($v)?sanitize_text_field((string)maybe_unserialize($v)):'';}
	/** @return string */
	private static function family_guid( $post_id, $keys ) {foreach($keys as $key){$v=self::legacy_scalar($post_id,$key);if(Mobo_Core_Remote_Identity_Policy::is_valid($v)){return $v;}}return '';}
	/** @return int */
	private static function family_int( $post_id, $keys ) {foreach($keys as $key){$v=self::legacy_scalar($post_id,$key);if(preg_match('/^[0-9]+$/',$v)&&absint($v)>0){return absint($v);}}return 0;}

	/** Reject non-empty legacy identity values that cannot be represented canonically. @return bool */
	private static function object_has_malformed_identity( $post_id ) {
		$rows=self::legacy_rows_for_post($post_id);if(null===$rows){return true;}foreach($rows as $row){$key=(string)($row['meta_key']??'');$raw=trim((string)maybe_unserialize($row['meta_value']??''));if(''===$raw){continue;}$family=self::managed_keys()[$key]??'';if(in_array($family,array('product_guid','variant_guid'),true)){if(!Mobo_Core_Remote_Identity_Policy::is_valid(sanitize_text_field($raw))){return true;}}elseif(in_array($family,array('portal_product_id','portal_variant_id'),true)){if(!preg_match('/^[0-9]+$/',$raw)||absint($raw)<=0){return true;}}}return false;
	}

	/** @return array */
	private static function load_state() {
		global $wpdb;
		$base=array('status'=>'pending','proofCursor'=>0,'proofComplete'=>false,'cleanupCursor'=>0,'fenceActive'=>false,'fenceVersion'=>0,'fenceActivatedAt'=>0,'cleanedRows'=>0,'physicalResidue'=>null,'complete'=>false,'lastError'=>'','blockers'=>array(),'stateRevision'=>0,'__persistedStateJson'=>null,'__stateLoadError'=>false);
		if(!class_exists('Mobo_Core_Storage_Schema')||!Mobo_Core_Storage_Schema::is_ready()){return $base;}
		$table=Mobo_Core_Storage_Schema::table_name('runtime');$wpdb->last_error='';
		$row=$wpdb->get_row($wpdb->prepare("SELECT state_json FROM {$table} WHERE connection_id=0 AND worker_name=%s LIMIT 1",self::WORKER_NAME),ARRAY_A);
		if(''!==trim((string)$wpdb->last_error)){$base['__stateLoadError']=true;return $base;}
		if(!is_array($row)){return $base;}
		$json=(string)($row['state_json']??'');$base['__persistedStateJson']=$json;
		$decoded=json_decode($json,true);if(!is_array($decoded)){$base['__stateLoadError']=true;return $base;}
		$out=array_merge($base,$decoded);$out['__persistedStateJson']=$json;$out['__stateLoadError']=false;return $out;
	}
	/** CAS-persist worker state so stale lock owners cannot overwrite newer progress. @return bool */
	private static function persist_state( $state, $finished = false ) {
		if ( class_exists( 'Mobo_Core_Migration' ) && ! Mobo_Core_Migration::accelerated_slice_lock_is_valid() ) { return false; }
		if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		global $wpdb;if(!class_exists('Mobo_Core_Storage_Schema')||!Mobo_Core_Storage_Schema::is_ready()){return false;}
		$table=Mobo_Core_Storage_Schema::table_name('runtime');$now=current_time('mysql',true);$expected_raw=$state['__persistedStateJson']??null;
		unset($state['__persistedStateJson'],$state['__stateLoadError']);$state['stateRevision']=absint($state['stateRevision']??0)+1;
		$json=wp_json_encode($state);if(!is_string($json)){return false;}$finished_at=$finished?$now:'';$cursor=(string)absint($state['proofCursor']??0);$error=sanitize_key((string)($state['lastError']??''));
		if(null===$expected_raw){$sql="INSERT IGNORE INTO {$table} (connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,last_error_code,state_json,created_at,updated_at) VALUES (0,%s,%s,NULLIF(%s,''),%s,0,'closed',%s,%s,%s,%s)";return 1===absint($wpdb->query($wpdb->prepare($sql,self::WORKER_NAME,$now,$finished_at,$cursor,$error,$json,$now,$now)));}
		$sql="UPDATE {$table} SET last_started_at=%s,last_finished_at=IF(%s='',last_finished_at,%s),last_cursor=%s,last_error_code=%s,state_json=%s,updated_at=%s WHERE connection_id=0 AND worker_name=%s AND BINARY state_json=BINARY %s";
		return 1===absint($wpdb->query($wpdb->prepare($sql,$now,$finished_at,$finished_at,$cursor,$error,$json,$now,self::WORKER_NAME,(string)$expected_raw)));
	}

	/** @return void */
	private static function record_state_error( $state, $error, $status ) { $state['status']=sanitize_key((string)$status);$state['lastError']=sanitize_key((string)$error);self::persist_state($state); }
}
