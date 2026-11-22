<?php
/**
 * Controlled per-key operational-postmeta write suppression.
 *
 * Release 10.33.89 extends controlled suppression to the final operational marker,
 * mobo_sync_incomplete. Revision watermarks, canonical 32-hex source hashes and the
 * completion marker are all Custom-first only after an exact retained-Legacy/Custom
 * commit-group match. Any missing row, duplicate Legacy meta, identity drift, CAS race
 * or Custom write failure immediately restores the normal Legacy mutation path.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Storage_Postmeta_Write_Suppression {

	const OPTION = 'mobo_core_storage_postmeta_write_suppression_keys';
	const MAX_CAS_ATTEMPTS = 3;
	const MAX_SAMPLES = 5;
	const SUCCESS_PREFIX = 'storage_pm_suppress_v1_';
	const FALLBACK_PREFIX = 'storage_pm_suppress_fallback_v1_';

	/** @var array<int,array{blocked:bool,suppressed:array,fallbacks:array,samples:array}> */
	private static $buckets = array();
	/** @var bool */
	private static $shutdown_registered = false;

	/** @return int */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** @return void */
	private static function ensure_bucket( $connection_id ) {
		$connection_id = absint( $connection_id );
		if ( ! isset( self::$buckets[ $connection_id ] ) ) {
			self::$buckets[ $connection_id ] = array( 'blocked'=>false, 'suppressed'=>array(), 'fallbacks'=>array(), 'samples'=>array() );
		}
	}

	/** @return array */
	private static function current_bucket() {
		$connection_id = self::connection_id();
		self::ensure_bucket( $connection_id );
		return self::$buckets[ $connection_id ];
	}

	/** @return void */
	public static function ensure_default() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, array(), '', false );
		}
	}

	/** Exact keys that are safe to suppress in 10.33.89. @return array<string,string> */
	public static function safe_keys() {
		return array(
			'_mobo_product_seen_revision'     => 'product_seen',
			'_mobo_product_applied_revision'  => 'product_applied',
			'_mobo_variant_seen_revision'     => 'variant_seen',
			'_mobo_variant_applied_revision'  => 'variant_applied',
			'_mobo_product_source_hash'       => 'product_hash',
			'_mobo_variant_source_hash'       => 'variant_hash',
			'mobo_sync_incomplete'             => 'sync_incomplete',
		);
	}

	/** @return array<int,string> */
	public static function normalize_requested_keys( $value ) {
		$safe = self::safe_keys();
		if ( is_string( $value ) ) {
			$value = trim( $value );
			if ( '' === $value || 'off' === sanitize_key( $value ) ) { return array(); }
			$value = preg_split( '/\s*,\s*/', $value );
		}
		if ( ! is_array( $value ) ) { return array(); }
		$out = array();
		foreach ( $value as $key ) {
			$key = (string) $key;
			if ( isset( $safe[ $key ] ) ) { $out[ $key ] = true; }
		}
		return array_keys( $out );
	}

	/** @return array<int,string> */
	public static function requested_keys() {
		return self::normalize_requested_keys( get_option( self::OPTION, array() ) );
	}

	/** @return bool */
	public static function is_requested( $meta_key ) {
		return in_array( (string) $meta_key, self::requested_keys(), true );
	}

	/**
	 * pre_update_post_metadata filter. Returns true only after Custom state is
	 * durably committed; null preserves the normal Legacy mutation path.
	 *
	 * @return mixed
	 */
	public static function filter_update( $check, $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		if ( null !== $check ) { return $check; }
		$object_id = absint( $object_id );
		$meta_key = (string) $meta_key;
		if ( $object_id <= 0 || ! isset( self::safe_keys()[ $meta_key ] ) || ! self::is_requested( $meta_key ) ) { return $check; }

		/* Preserve update_metadata()'s conditional old-value semantics. */
		if ( '' !== $prev_value && null !== $prev_value ) { return $check; }
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return $check; }
		$authority = self::runtime_authority( $object_id, $meta_key );
		if ( false === $authority ) { return $check; }

		$is_hash = in_array( $meta_key, array( '_mobo_product_source_hash', '_mobo_variant_source_hash' ), true );
		if ( 'mobo_sync_incomplete' === $meta_key ) {
			$value = self::normalize_incomplete( $meta_value );
			if ( null === $value ) { return $check; }
			$result = self::apply_incomplete_mutation( $object_id, $value, $authority );
		} elseif ( $is_hash ) {
			$value = self::normalize_hash( $meta_value );
			if ( '' === $value ) { return $check; }
			$result = self::apply_hash_mutation( $object_id, $meta_key, $value, $authority );
		} else {
			$value = self::normalize_revision( $meta_value );
			if ( null === $value ) { return $check; }
			$result = self::apply_revision_mutation( $object_id, $meta_key, $value );
		}
		$status = sanitize_key( (string) ( isset( $result['status'] ) ? $result['status'] : 'failure' ) );
		if ( in_array( $status, array( 'applied', 'noop' ), true ) ) {
			self::record_suppressed( $object_id, $meta_key, $value, $status );
			if ( class_exists( 'Mobo_Core_Storage_Primary_State_Read', false ) ) {
				Mobo_Core_Storage_Primary_State_Read::invalidate_object( $object_id );
			}
			return true;
		}

		/* A Custom write/race/identity failure restores Legacy immediately. */
		if ( in_array( $status, array( 'failure', 'conflict', 'missing' ), true ) ) {
			self::trip_request_breaker( isset( $result['reason'] ) ? $result['reason'] : 'custom_write_failed', $object_id, $meta_key );
		}
		return $check;
	}


	/**
	 * Terminal Custom-only scalar read used after physical operational-postmeta retirement.
	 * This never consults retained Legacy postmeta.
	 *
	 * @return array{status:string,reason:string,present:bool,value:mixed}
	 */
	public static function terminal_current( $object_id, $meta_key ) {
		global $wpdb;
		$object_id = absint( $object_id );
		$meta_key  = (string) $meta_key;
		$type      = $object_id > 0 ? get_post_type( $object_id ) : false;
		$product_map = array(
			'_mobo_product_seen_revision'    => array( 'product_seen_revision', 'revision' ),
			'_mobo_product_applied_revision' => array( 'product_applied_revision', 'revision' ),
			'_mobo_variant_seen_revision'    => array( 'variant_seen_revision', 'revision' ),
			'_mobo_variant_applied_revision' => array( 'variant_applied_revision', 'revision' ),
			'_mobo_product_source_hash'      => array( 'product_source_hash', 'hash' ),
			'mobo_sync_incomplete'            => array( 'sync_incomplete_meta', 'incomplete' ),
		);
		$variant_map = array(
			'_mobo_variant_seen_revision'    => array( 'variant_seen_revision', 'revision' ),
			'_mobo_variant_applied_revision' => array( 'variant_applied_revision', 'revision' ),
			'_mobo_variant_source_hash'      => array( 'variant_source_hash', 'hash' ),
			'mobo_sync_incomplete'            => array( 'sync_incomplete_meta', 'incomplete' ),
		);
		if ( 'product' === $type && isset( $product_map[ $meta_key ] ) ) {
			$spec = $product_map[ $meta_key ];
			$row  = self::terminal_row( 'products', 'woo_product_id', $product_map, $object_id );
		} elseif ( 'product_variation' === $type && isset( $variant_map[ $meta_key ] ) ) {
			$spec = $variant_map[ $meta_key ];
			$row  = self::terminal_row( 'variants', 'woo_variation_id', $variant_map, $object_id );
		} else {
			return array( 'status'=>'not-applicable', 'reason'=>'unsupported_object_or_key', 'present'=>false, 'value'=>'' );
		}
		if ( ! is_array( $row ) ) { return array( 'status'=>'missing', 'reason'=>'custom_row_missing', 'present'=>false, 'value'=>'' ); }
		$present = 1 === absint( isset( $row[ $spec[0] . '_present' ] ) ? $row[ $spec[0] . '_present' ] : 0 );
		$value = isset( $row[ $spec[0] ] ) ? $row[ $spec[0] ] : '';
		if ( 'revision' === $spec[1] ) { $value = absint( $value ); }
		elseif ( 'incomplete' === $spec[1] ) { $value = 1 === absint( $value ) ? '1' : '0'; }
		else { $value = (string) $value; }
		return array( 'status'=>'hit', 'reason'=>'', 'present'=>$present, 'value'=>$value );
	}

	/** @var int Terminal row SELECTs issued in this request (tests/diagnostics). */
	public static $terminal_row_queries = 0;

	/**
	 * All terminal scalar columns of one Product/Variant row in ONE query, cached in
	 * the request-local row cache (a write to the table invalidates the bucket).
	 * Runtime-30s: terminal_current() used to issue one SELECT per meta key per
	 * object, which dominated the post-fence Sync Health scan.
	 *
	 * @param string $bucket    'products' | 'variants'.
	 * @param string $id_column Woo id column.
	 * @param array  $map       meta key => array( column, kind ).
	 * @param int    $object_id Woo object id.
	 * @return array|null Row, or null when missing / on error.
	 */
	private static function terminal_row( $bucket, $id_column, $map, $object_id ) {
		global $wpdb;
		$connection_id = self::connection_id();
		$table         = Mobo_Core_Storage_Schema::table_name( $bucket );
		$cache_key     = 'terminal:' . $connection_id . ':' . absint( $object_id );
		$cacheable     = class_exists( 'Mobo_Core_Storage_Row_Cache' );
		if ( $cacheable ) {
			$hit    = false;
			$cached = Mobo_Core_Storage_Row_Cache::get( $bucket, $cache_key, $hit );
			if ( $hit ) {
				return is_array( $cached ) ? $cached : null;
			}
		}
		$columns = array();
		foreach ( $map as $spec ) {
			$columns[ $spec[0] ] = $spec[0] . ',' . $spec[0] . '_present';
		}
		$wpdb->last_error = '';
		self::$terminal_row_queries++;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . implode( ',', $columns ) . " FROM {$table} WHERE connection_id=%d AND {$id_column}=%d LIMIT 1", $connection_id, absint( $object_id ) ), ARRAY_A );
		$row = is_array( $row ) ? $row : null;
		if ( $cacheable && '' === trim( (string) $wpdb->last_error ) ) {
			Mobo_Core_Storage_Row_Cache::register_table( $bucket, $table );
			Mobo_Core_Storage_Row_Cache::set( $bucket, $cache_key, $row, absint( $object_id ) );
		}
		return $row;
	}

	/**
	 * Terminal Custom-only update. A non-success result MUST be short-circuited by
	 * the caller; falling through to WordPress postmeta is forbidden after fence.
	 *
	 * @return array{status:string,reason:string}
	 */
	public static function terminal_set( $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		$object_id = absint( $object_id );
		$meta_key  = (string) $meta_key;
		$current = self::terminal_current( $object_id, $meta_key );
		if ( 'not-applicable' === $current['status'] ) { return array( 'status'=>'not-applicable', 'reason'=>$current['reason'] ); }
		if ( 'missing' === $current['status'] ) { return array( 'status'=>'missing', 'reason'=>$current['reason'] ); }
		if ( '' !== $prev_value && null !== $prev_value ) {
			if ( empty( $current['present'] ) || (string) $current['value'] !== (string) $prev_value ) {
				return array( 'status'=>'conflict', 'reason'=>'previous_value_mismatch' );
			}
		}
		$authority = array_fill_keys( array_keys( self::safe_keys() ), true );
		if ( 'mobo_sync_incomplete' === $meta_key ) {
			$value = self::normalize_incomplete( $meta_value );
			if ( null === $value ) { return array( 'status'=>'conflict', 'reason'=>'invalid_incomplete_value' ); }
			$result = self::apply_incomplete_mutation( $object_id, $value, $authority );
		} elseif ( in_array( $meta_key, array( '_mobo_product_source_hash','_mobo_variant_source_hash' ), true ) ) {
			$value = self::normalize_hash( $meta_value );
			if ( '' === $value ) { return array( 'status'=>'conflict', 'reason'=>'invalid_hash_value' ); }
			$result = self::apply_hash_mutation( $object_id, $meta_key, $value, $authority );
		} else {
			$value = self::normalize_revision( $meta_value );
			if ( null === $value ) { return array( 'status'=>'conflict', 'reason'=>'invalid_revision_value' ); }
			$result = self::apply_revision_mutation( $object_id, $meta_key, $value );
		}
		if ( in_array( sanitize_key( (string) ( $result['status'] ?? '' ) ), array( 'applied','noop' ), true ) && class_exists( 'Mobo_Core_Storage_Primary_State_Read', false ) ) {
			Mobo_Core_Storage_Primary_State_Read::invalidate_object( $object_id );
		}
		return $result;
	}

	/**
	 * Terminal Custom-only delete with optimistic CAS. Presence, not only scalar
	 * value, is part of the persisted contract after physical postmeta retirement.
	 *
	 * @return array{status:string,reason:string}
	 */
	public static function terminal_delete( $object_id, $meta_key, $meta_value = '' ) {
		global $wpdb;
		$object_id = absint( $object_id );
		$meta_key  = (string) $meta_key;
		$current = self::terminal_current( $object_id, $meta_key );
		if ( 'hit' !== $current['status'] ) { return array( 'status'=>$current['status'], 'reason'=>$current['reason'] ); }
		if ( empty( $current['present'] ) ) { return array( 'status'=>'missing', 'reason'=>'custom_meta_absent' ); }
		if ( '' !== $meta_value && null !== $meta_value && (string) $current['value'] !== (string) $meta_value ) {
			return array( 'status'=>'conflict', 'reason'=>'delete_value_mismatch' );
		}
		$type = get_post_type( $object_id );
		$table = 'product' === $type ? Mobo_Core_Storage_Schema::table_name( 'products' ) : Mobo_Core_Storage_Schema::table_name( 'variants' );
		$id_col = 'product' === $type ? 'woo_product_id' : 'woo_variation_id';
		for ( $attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++ ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id=%d AND {$id_col}=%d LIMIT 1", self::connection_id(), $object_id ), ARRAY_A );
			if ( ! is_array( $row ) ) { return array( 'status'=>'missing', 'reason'=>'custom_row_missing' ); }
			if ( 'product' === $type ) {
				$result = self::terminal_delete_product_row( $table, $row, $meta_key );
			} elseif ( 'product_variation' === $type ) {
				$result = self::terminal_delete_variant_row( $table, $row, $meta_key );
			} else {
				return array( 'status'=>'not-applicable', 'reason'=>'unsupported_post_type' );
			}
			if ( 'retry' === ( $result['status'] ?? '' ) ) { continue; }
			if ( in_array( $result['status'] ?? '', array( 'applied','noop' ), true ) && class_exists( 'Mobo_Core_Storage_Primary_State_Read', false ) ) {
				Mobo_Core_Storage_Primary_State_Read::invalidate_object( $object_id );
			}
			return $result;
		}
		return array( 'status'=>'failure', 'reason'=>'terminal_delete_cas_exhausted' );
	}

	/** @return array{status:string,reason:string} */
	private static function terminal_delete_product_row( $table, $row, $meta_key ) {
		global $wpdb;
		$map = array(
			'_mobo_product_seen_revision'    => 'product_seen_revision',
			'_mobo_product_applied_revision' => 'product_applied_revision',
			'_mobo_variant_seen_revision'    => 'variant_seen_revision',
			'_mobo_variant_applied_revision' => 'variant_applied_revision',
			'_mobo_product_source_hash'      => 'product_source_hash',
			'mobo_sync_incomplete'           => 'sync_incomplete_meta',
		);
		if ( ! isset( $map[ $meta_key ] ) ) { return array( 'status'=>'not-applicable', 'reason'=>'key_not_applicable' ); }
		$field = $map[ $meta_key ];
		$presence = $field . '_present';
		if ( 1 !== absint( $row[ $presence ] ?? 0 ) ) { return array( 'status'=>'noop', 'reason'=>'' ); }

		$n = $row;
		$n[ $field ] = 'product_source_hash' === $field ? '' : 0;
		$n[ $presence ] = 0;
		if ( 'sync_incomplete_meta' === $field ) {
			$n['sync_incomplete'] = 0;
		}

		$ps = absint( $n['product_seen_revision'] ?? 0 );
		$pa = absint( $n['product_applied_revision'] ?? 0 );
		$vs = absint( $n['variant_seen_revision'] ?? 0 );
		$va = absint( $n['variant_applied_revision'] ?? 0 );
		$desired = max( $ps, $vs );
		$required = array();
		if ( $ps > 0 ) { $required[] = $pa; }
		if ( $vs > 0 ) { $required[] = $va; }
		$applied = $required ? min( $required ) : max( $pa, $va );
		$incomplete = 1 === absint( $n['sync_incomplete'] ?? 0 );
		if ( ! $incomplete ) { $applied = max( $applied, $desired ); }
		$desired = max( $desired, $applied );
		$hash = 1 === absint( $n['product_source_hash_present'] ?? 0 ) ? (string) ( $n['product_source_hash'] ?? '' ) : '';
		$applied_hash = $incomplete ? '' : $hash;
		$status = ( $incomplete || $desired > $applied ) ? 'pending' : 'applied';
		$dirty = absint( $row['dirty_flags'] ?? 0 );
		$dirty = 'pending' === $status ? ( $dirty | 1 ) : ( $dirty & ~1 );
		$now = current_time( 'mysql', true );

		$set = array();
		$args = array();
		if ( 'sync_incomplete_meta' !== $field ) {
			$set[] = $field . '=' . ( 'product_source_hash' === $field ? '%s' : '%d' );
			$args[] = 'product_source_hash' === $field ? '' : 0;
			$set[] = $presence . '=0';
		}
		$set[] = 'sync_incomplete_meta=%d'; $args[] = absint( $n['sync_incomplete_meta'] ?? 0 );
		$set[] = 'sync_incomplete_meta_present=%d'; $args[] = absint( $n['sync_incomplete_meta_present'] ?? 0 );
		$set[] = 'sync_incomplete=%d'; $args[] = absint( $n['sync_incomplete'] ?? 0 );
		$set[] = 'desired_revision=%d'; $args[] = $desired;
		$set[] = 'applied_revision=%d'; $args[] = $applied;
		$set[] = 'desired_hash=%s'; $args[] = $hash;
		$set[] = 'applied_hash=%s'; $args[] = $applied_hash;
		$set[] = 'sync_status=%s'; $args[] = $status;
		$set[] = 'dirty_flags=%d'; $args[] = $dirty;
		$set[] = 'updated_at=%s'; $args[] = $now;

		$sql = "UPDATE {$table} SET " . implode( ',', $set ) . " WHERE id=%d AND product_seen_revision=%d AND product_seen_revision_present=%d AND product_applied_revision=%d AND product_applied_revision_present=%d AND variant_seen_revision=%d AND variant_seen_revision_present=%d AND variant_applied_revision=%d AND variant_applied_revision_present=%d AND product_source_hash=%s AND product_source_hash_present=%d AND sync_incomplete_meta=%d AND sync_incomplete_meta_present=%d AND sync_incomplete=%d";
		$args[] = absint( $row['id'] ?? 0 );
		foreach ( array( 'product_seen_revision','product_seen_revision_present','product_applied_revision','product_applied_revision_present','variant_seen_revision','variant_seen_revision_present','variant_applied_revision','variant_applied_revision_present' ) as $k ) { $args[] = absint( $row[ $k ] ?? 0 ); }
		$args[] = (string) ( $row['product_source_hash'] ?? '' );
		$args[] = absint( $row['product_source_hash_present'] ?? 0 );
		$args[] = absint( $row['sync_incomplete_meta'] ?? 0 );
		$args[] = absint( $row['sync_incomplete_meta_present'] ?? 0 );
		$args[] = absint( $row['sync_incomplete'] ?? 0 );
		$changed = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		if ( false === $changed ) { return array( 'status'=>'failure', 'reason'=>'product_terminal_delete_failed' ); }
		return $changed > 0 ? array( 'status'=>'applied', 'reason'=>'' ) : array( 'status'=>'retry', 'reason'=>'product_terminal_delete_race' );
	}

	/** @return array{status:string,reason:string} */
	private static function terminal_delete_variant_row( $table, $row, $meta_key ) {
		global $wpdb;
		$map = array(
			'_mobo_variant_seen_revision'    => 'variant_seen_revision',
			'_mobo_variant_applied_revision' => 'variant_applied_revision',
			'_mobo_variant_source_hash'      => 'variant_source_hash',
			'mobo_sync_incomplete'           => 'sync_incomplete_meta',
		);
		if ( ! isset( $map[ $meta_key ] ) ) { return array( 'status'=>'not-applicable', 'reason'=>'key_not_applicable' ); }
		$field = $map[ $meta_key ];
		$presence = $field . '_present';
		if ( 1 !== absint( $row[ $presence ] ?? 0 ) ) { return array( 'status'=>'noop', 'reason'=>'' ); }

		$n = $row;
		$n[ $field ] = 'variant_source_hash' === $field ? '' : 0;
		$n[ $presence ] = 0;
		if ( 'sync_incomplete_meta' === $field ) {
			$n['sync_incomplete'] = 0;
		}
		$vs = absint( $n['variant_seen_revision'] ?? 0 );
		$va = absint( $n['variant_applied_revision'] ?? 0 );
		$desired = max( $vs, $va );
		$applied = $va;
		$incomplete = 1 === absint( $n['sync_incomplete'] ?? 0 );
		$hash = 1 === absint( $n['variant_source_hash_present'] ?? 0 ) ? (string) ( $n['variant_source_hash'] ?? '' ) : '';
		$applied_hash = $incomplete ? '' : $hash;
		$status = ( $incomplete || $desired > $applied ) ? 'pending' : 'applied';
		$dirty = absint( $row['dirty_flags'] ?? 0 );
		$dirty = 'pending' === $status ? ( $dirty | 1 ) : ( $dirty & ~1 );
		$now = current_time( 'mysql', true );

		$set = array();
		$args = array();
		if ( 'sync_incomplete_meta' !== $field ) {
			$set[] = $field . '=' . ( 'variant_source_hash' === $field ? '%s' : '%d' );
			$args[] = 'variant_source_hash' === $field ? '' : 0;
			$set[] = $presence . '=0';
		}
		$set[] = 'sync_incomplete_meta=%d'; $args[] = absint( $n['sync_incomplete_meta'] ?? 0 );
		$set[] = 'sync_incomplete_meta_present=%d'; $args[] = absint( $n['sync_incomplete_meta_present'] ?? 0 );
		$set[] = 'sync_incomplete=%d'; $args[] = absint( $n['sync_incomplete'] ?? 0 );
		$set[] = 'desired_revision=%d'; $args[] = $desired;
		$set[] = 'applied_revision=%d'; $args[] = $applied;
		$set[] = 'desired_hash=%s'; $args[] = $hash;
		$set[] = 'applied_hash=%s'; $args[] = $applied_hash;
		$set[] = 'sync_status=%s'; $args[] = $status;
		$set[] = 'dirty_flags=%d'; $args[] = $dirty;
		$set[] = 'updated_at=%s'; $args[] = $now;

		$sql = "UPDATE {$table} SET " . implode( ',', $set ) . " WHERE id=%d AND variant_seen_revision=%d AND variant_seen_revision_present=%d AND variant_applied_revision=%d AND variant_applied_revision_present=%d AND variant_source_hash=%s AND variant_source_hash_present=%d AND sync_incomplete_meta=%d AND sync_incomplete_meta_present=%d AND sync_incomplete=%d";
		$args[] = absint( $row['id'] ?? 0 );
		$args[] = absint( $row['variant_seen_revision'] ?? 0 );
		$args[] = absint( $row['variant_seen_revision_present'] ?? 0 );
		$args[] = absint( $row['variant_applied_revision'] ?? 0 );
		$args[] = absint( $row['variant_applied_revision_present'] ?? 0 );
		$args[] = (string) ( $row['variant_source_hash'] ?? '' );
		$args[] = absint( $row['variant_source_hash_present'] ?? 0 );
		$args[] = absint( $row['sync_incomplete_meta'] ?? 0 );
		$args[] = absint( $row['sync_incomplete_meta_present'] ?? 0 );
		$args[] = absint( $row['sync_incomplete'] ?? 0 );
		$changed = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		if ( false === $changed ) { return array( 'status'=>'failure', 'reason'=>'variant_terminal_delete_failed' ); }
		return $changed > 0 ? array( 'status'=>'applied', 'reason'=>'' ) : array( 'status'=>'retry', 'reason'=>'variant_terminal_delete_race' );
	}

	/** @return bool */
	public static function request_allowed() { $b=self::current_bucket(); return empty( $b['blocked'] ); }

	/** @return array */
	public static function get_request_status() {
		$b = self::current_bucket();
		return array(
			'connectionId' => self::connection_id(),
			'requestedKeys' => self::requested_keys(),
			'requestBlocked' => ! empty( $b['blocked'] ),
			'suppressedWrites' => array_sum( $b['suppressed'] ),
			'suppressedByKey' => $b['suppressed'],
			'emergencyFallbacks' => array_sum( $b['fallbacks'] ),
			'fallbacksByKey' => $b['fallbacks'],
			'samples' => $b['samples'],
		);
	}

	/** Durable per-key suppression/fallback counters in one bounded query. @return array */
	public static function get_metrics_status() {
		global $wpdb;
		$out = array();
		$workers = array();
		foreach ( self::safe_keys() as $meta_key => $slug ) {
			$out[ $meta_key ] = array(
				'requested' => self::is_requested( $meta_key ),
				'suppressedWrites' => 0,
				'suppressedUpdatedAt' => '',
				'fallbacks' => 0,
				'fallbackUpdatedAt' => '',
			);
			$workers[ self::SUCCESS_PREFIX . $slug ] = array( $meta_key, 'success' );
			$workers[ self::FALLBACK_PREFIX . $slug ] = array( $meta_key, 'fallback' );
		}
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return $out; }
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return $out; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$names = array_keys( $workers );
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$args = array_merge( array( $connection_id ), $names );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT worker_name,consecutive_failures,updated_at FROM {$table} WHERE connection_id=%d AND worker_name IN ({$placeholders})", $args ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = (string) ( isset( $row['worker_name'] ) ? $row['worker_name'] : '' );
			if ( ! isset( $workers[ $name ] ) ) { continue; }
			list( $meta_key, $kind ) = $workers[ $name ];
			$count = absint( isset( $row['consecutive_failures'] ) ? $row['consecutive_failures'] : 0 );
			$updated = sanitize_text_field( (string) ( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ) );
			if ( 'success' === $kind ) { $out[ $meta_key ]['suppressedWrites']=$count; $out[ $meta_key ]['suppressedUpdatedAt']=$updated; }
			else { $out[ $meta_key ]['fallbacks']=$count; $out[ $meta_key ]['fallbackUpdatedAt']=$updated; }
		}
		return $out;
	}

	/** @return array */
	public static function get_status() {
		return array(
			'safeKeys' => array_keys( self::safe_keys() ),
			'requestedKeys' => self::requested_keys(),
			'request' => self::get_request_status(),
			'metrics' => self::get_metrics_status(),
			'note' => '10.33.89 suppresses opted-in revision watermarks, canonical 32-hex source hashes and commit-group-guarded sync_incomplete updates; direct add/delete and REST metadata writes remain Legacy.',
		);
	}

	/** Persist bounded request telemetry. @return bool */
	public static function flush() {
		$ok = true;
		foreach ( self::$buckets as $connection_id => $bucket ) {
			$connection_id = absint( $connection_id );
			if ( $connection_id <= 0 ) { continue; }
			$ok = self::increment_metric( $connection_id, self::SUCCESS_PREFIX, $bucket['suppressed'] ) && $ok;
			$ok = self::increment_metric( $connection_id, self::FALLBACK_PREFIX, $bucket['fallbacks'] ) && $ok;
		}
		self::clear_request_state();
		return $ok;
	}

	/** Tests/manual tooling only. @return void */
	public static function reset_request_state() { self::clear_request_state(); }

	/**
	 * Resolve the exact Custom-authoritative key set once for this mutation.
	 * Cheap request/object guards run before the bounded readiness query.
	 *
	 * @return array<string,bool>|false
	 */
	private static function runtime_authority( $object_id, $meta_key ) {
		$request_bucket = self::current_bucket();
		if ( ! empty( $request_bucket['blocked'] ) ) { return false; }
		if ( ! class_exists( 'Mobo_Core_Storage_Mode' ) || Mobo_Core_Storage_Mode::CUSTOM_ONLY !== Mobo_Core_Storage_Mode::effective() ) { return false; }
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		if ( class_exists( 'Mobo_Core_Storage_Primary_Read' ) && ! Mobo_Core_Storage_Primary_Read::request_allowed() ) { return false; }
		if ( class_exists( 'Mobo_Core_Storage_Primary_State_Read' ) && Mobo_Core_Storage_Primary_State_Read::has_legacy_mutation( $object_id ) ) { return false; }
		if ( class_exists( 'Mobo_Core_Storage_Meta_Commit_Bridge' ) && Mobo_Core_Storage_Meta_Commit_Bridge::has_pending_object( $object_id ) ) { return false; }
		if ( class_exists( 'Mobo_Core_Storage_Legacy_Dependency' ) ) {
			$dep = Mobo_Core_Storage_Legacy_Dependency::get_request_status();
			if ( absint( isset( $dep['readDependencies'] ) ? $dep['readDependencies'] : 0 ) > 0 ) { return false; }
		}
		$type = get_post_type( $object_id );
		if ( 'product' === $type ) {
			if ( ! in_array( $meta_key, array( '_mobo_product_seen_revision','_mobo_product_applied_revision','_mobo_variant_seen_revision','_mobo_variant_applied_revision','_mobo_product_source_hash','mobo_sync_incomplete' ), true ) ) { return false; }
		} elseif ( 'product_variation' === $type ) {
			if ( ! in_array( $meta_key, array( '_mobo_variant_seen_revision','_mobo_variant_applied_revision','_mobo_variant_source_hash','mobo_sync_incomplete' ), true ) ) { return false; }
		} else {
			return false;
		}
		$authority = self::custom_authoritative_keys();
		return ! empty( $authority[ $meta_key ] ) ? $authority : false;
	}

	/** Exact keys whose retained Legacy copy may intentionally be stale. @return array<string,bool> */
	private static function custom_authoritative_keys() {
		if ( ! class_exists( 'Mobo_Core_Storage_Postmeta_Suppression_Readiness' ) ) { return array(); }
		$eligible = Mobo_Core_Storage_Postmeta_Suppression_Readiness::runtime_eligible_keys();
		$requested = self::requested_keys(); $out = array();
		foreach ( $requested as $key ) { if ( in_array( $key, $eligible, true ) ) { $out[ $key ] = true; } }
		return $out;
	}

	/** @return bool */
	private static function row_incomplete_consistent( $row ) {
		$present = 1 === absint( $row['sync_incomplete_meta_present'] ?? 0 );
		$meta = absint( $row['sync_incomplete_meta'] ?? 0 );
		$derived = absint( $row['sync_incomplete'] ?? 0 );
		if ( ! in_array( $meta, array( 0, 1 ), true ) || ! in_array( $derived, array( 0, 1 ), true ) ) { return false; }
		if ( ! $present ) { return 0 === $meta && 0 === $derived; }
		return $meta === $derived;
	}

	/** @return int|null */
	private static function normalize_revision( $value ) {
		if ( is_int( $value ) ) { return $value >= 0 ? $value : null; }
		if ( is_string( $value ) && preg_match( '/^\d+$/', $value ) ) { return max( 0, (int) $value ); }
		return null;
	}

	/** Normalize the exact persisted scalar contract for mobo_sync_incomplete. @return int|null */
	private static function normalize_incomplete( $value ) {
		if ( true === $value ) { return 1; }
		if ( is_int( $value ) && in_array( $value, array( 0, 1 ), true ) ) { return $value; }
		if ( is_string( $value ) && in_array( $value, array( '0', '1' ), true ) ) { return (int) $value; }
		return null;
	}

	/** @return array{status:string,reason:string} */
	private static function apply_revision_mutation( $object_id, $meta_key, $revision ) {
		$type = get_post_type( $object_id );
		if ( 'product' === $type ) { return self::apply_product_revision( $object_id, $meta_key, $revision ); }
		if ( 'product_variation' === $type ) { return self::apply_variant_revision( $object_id, $meta_key, $revision ); }
		return array( 'status'=>'not-applicable', 'reason'=>'unsupported_post_type' );
	}

	/** @return array{status:string,reason:string} */
	private static function apply_product_revision( $product_id, $meta_key, $revision ) {
		global $wpdb;
		$map = array(
			'_mobo_product_seen_revision' => 'product_seen_revision',
			'_mobo_product_applied_revision' => 'product_applied_revision',
			'_mobo_variant_seen_revision' => 'variant_seen_revision',
			'_mobo_variant_applied_revision' => 'variant_applied_revision',
		);
		if ( ! isset( $map[ $meta_key ] ) ) { return array( 'status'=>'not-applicable', 'reason'=>'key_not_applicable' ); }
		$field = $map[ $meta_key ]; $presence = $field . '_present';
		$table = Mobo_Core_Storage_Schema::table_name( 'products' );

		for ( $attempt=0; $attempt<self::MAX_CAS_ATTEMPTS; $attempt++ ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id=%d AND woo_product_id=%d LIMIT 1", self::connection_id(), $product_id ), ARRAY_A );
			if ( ! is_array( $row ) ) { return array( 'status'=>'missing', 'reason'=>'product_custom_row_missing' ); }
			if ( ! class_exists( 'Mobo_Core_Remote_Identity_Policy' ) || ! Mobo_Core_Remote_Identity_Policy::is_valid( (string) ( isset($row['product_guid'])?$row['product_guid']:'' ) ) ) { return array( 'status'=>'conflict', 'reason'=>'product_custom_identity_invalid' ); }
			$current = absint( isset( $row[$field] ) ? $row[$field] : 0 );
			$current_present = 1 === absint( isset( $row[$presence] ) ? $row[$presence] : 0 );
			if ( $revision < $current ) { return array( 'status'=>'conflict', 'reason'=>'revision_regression' ); }
			if ( $revision === $current && $current_present ) { return array( 'status'=>'noop', 'reason'=>'' ); }

			$ps=absint($row['product_seen_revision']??0); $pa=absint($row['product_applied_revision']??0); $vs=absint($row['variant_seen_revision']??0); $va=absint($row['variant_applied_revision']??0);
			if ( 'product_seen_revision' === $field ) { $ps=$revision; }
			elseif ( 'product_applied_revision' === $field ) { $pa=$revision; }
			elseif ( 'variant_seen_revision' === $field ) { $vs=$revision; }
			else { $va=$revision; }
			$sync_incomplete = 1 === absint( $row['sync_incomplete'] ?? 0 );
			$desired = max( $ps, $vs );
			$required = array(); if($ps>0){$required[]=$pa;} if($vs>0){$required[]=$va;}
			$applied = !empty($required) ? min($required) : max($pa,$va);
			if ( ! $sync_incomplete ) { $applied=max($applied,$desired); }
			$desired=max($desired,$applied);
			$status = ( $sync_incomplete || $desired>$applied ) ? 'pending' : 'applied';
			$dirty = absint( $row['dirty_flags'] ?? 0 ); $dirty = 'pending'===$status ? ($dirty|1) : ($dirty&~1);
			$now=current_time('mysql',true);
			$set = "{$field}=%d, {$presence}=1, desired_revision=%d, applied_revision=%d, sync_status=%s, dirty_flags=%d, last_received_at=%s, updated_at=%s";
			$args = array($revision,$desired,$applied,$status,$dirty,$now,$now);
			if ( 'applied' === $status ) { $set .= ', last_applied_at=%s'; $args[]=$now; }
			$sql = "UPDATE {$table} SET {$set} WHERE id=%d AND product_seen_revision=%d AND product_seen_revision_present=%d AND product_applied_revision=%d AND product_applied_revision_present=%d AND variant_seen_revision=%d AND variant_seen_revision_present=%d AND variant_applied_revision=%d AND variant_applied_revision_present=%d AND sync_incomplete=%d";
			$args[] = absint($row['id']??0); $args[] = absint($row['product_seen_revision']??0); $args[] = absint($row['product_seen_revision_present']??0); $args[] = absint($row['product_applied_revision']??0); $args[] = absint($row['product_applied_revision_present']??0); $args[] = absint($row['variant_seen_revision']??0); $args[] = absint($row['variant_seen_revision_present']??0); $args[] = absint($row['variant_applied_revision']??0); $args[] = absint($row['variant_applied_revision_present']??0); $args[] = absint($row['sync_incomplete']??0);
			$changed = $wpdb->query( $wpdb->prepare( $sql, $args ) );
			if ( false === $changed ) { return array( 'status'=>'failure', 'reason'=>'product_custom_update_failed' ); }
			if ( $changed > 0 ) { return array( 'status'=>'applied', 'reason'=>'' ); }
		}
		return array( 'status'=>'failure', 'reason'=>'product_custom_cas_exhausted' );
	}

	/** @return array{status:string,reason:string} */
	private static function apply_variant_revision( $variation_id, $meta_key, $revision ) {
		global $wpdb;
		$map = array(
			'_mobo_variant_seen_revision' => 'variant_seen_revision',
			'_mobo_variant_applied_revision' => 'variant_applied_revision',
		);
		if ( ! isset( $map[ $meta_key ] ) ) { return array( 'status'=>'not-applicable', 'reason'=>'key_not_applicable' ); }
		$field=$map[$meta_key]; $presence=$field.'_present'; $table=Mobo_Core_Storage_Schema::table_name('variants');
		for($attempt=0;$attempt<self::MAX_CAS_ATTEMPTS;$attempt++){
			$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE connection_id=%d AND woo_variation_id=%d LIMIT 1",self::connection_id(),$variation_id),ARRAY_A);
			if(!is_array($row)){return array('status'=>'missing','reason'=>'variant_custom_row_missing');}
			if(!class_exists('Mobo_Core_Remote_Identity_Policy')||!Mobo_Core_Remote_Identity_Policy::is_valid((string)($row['variant_guid']??''))||!Mobo_Core_Remote_Identity_Policy::is_valid((string)($row['product_guid']??''))){return array('status'=>'conflict','reason'=>'variant_custom_identity_invalid');}
			$parent=absint(wp_get_post_parent_id($variation_id)); if($parent<=0||$parent!==absint($row['woo_product_id']??0)){return array('status'=>'conflict','reason'=>'variant_parent_mismatch');}
			$current=absint($row[$field]??0); $current_present=1===absint($row[$presence]??0);
			if($revision<$current){return array('status'=>'conflict','reason'=>'revision_regression');}
			if($revision===$current&&$current_present){return array('status'=>'noop','reason'=>'');}
			$vs=absint($row['variant_seen_revision']??0); $va=absint($row['variant_applied_revision']??0); if('variant_seen_revision'===$field){$vs=$revision;}else{$va=$revision;}
			$sync_incomplete=1===absint($row['sync_incomplete']??0); $desired=max($vs,$va); $applied=$va; $status=($sync_incomplete||$desired>$applied)?'pending':'applied';
			$dirty=absint($row['dirty_flags']??0); $dirty='pending'===$status?($dirty|1):($dirty&~1); $now=current_time('mysql',true);
			$set="{$field}=%d, {$presence}=1, desired_revision=%d, applied_revision=%d, sync_status=%s, dirty_flags=%d, last_received_at=%s, updated_at=%s"; $args=array($revision,$desired,$applied,$status,$dirty,$now,$now);
			if('applied'===$status){$set.=', last_applied_at=%s';$args[]=$now;}
			$sql="UPDATE {$table} SET {$set} WHERE id=%d AND variant_seen_revision=%d AND variant_seen_revision_present=%d AND variant_applied_revision=%d AND variant_applied_revision_present=%d AND sync_incomplete=%d";
			$args[]=absint($row['id']??0);$args[]=absint($row['variant_seen_revision']??0);$args[]=absint($row['variant_seen_revision_present']??0);$args[]=absint($row['variant_applied_revision']??0);$args[]=absint($row['variant_applied_revision_present']??0);$args[]=absint($row['sync_incomplete']??0);
			$changed=$wpdb->query($wpdb->prepare($sql,$args)); if(false===$changed){return array('status'=>'failure','reason'=>'variant_custom_update_failed');} if($changed>0){return array('status'=>'applied','reason'=>'');}
		}
		return array('status'=>'failure','reason'=>'variant_custom_cas_exhausted');
	}


	/** @return string */
	private static function normalize_hash( $value ) {
		return class_exists( 'Mobo_Core_Source_Hash_Policy' ) ? Mobo_Core_Source_Hash_Policy::normalize( $value ) : '';
	}

	/**
	 * Raw retained-Legacy snapshot that bypasses Custom read filters.
	 * Duplicate rows are rejected because their single-meta semantics are ambiguous.
	 *
	 * @return array{ok:bool,values:array,presence:array,reason:string}
	 */
	private static function legacy_group_snapshot( $object_id, $keys ) {
		global $wpdb;
		$object_id = absint( $object_id );
		$keys = array_values( array_unique( array_map( 'strval', (array) $keys ) ) );
		if ( $object_id <= 0 || empty( $keys ) ) { return array( 'ok'=>false, 'values'=>array(), 'presence'=>array(), 'reason'=>'legacy_group_invalid' ); }
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$args = array_merge( array( $object_id ), $keys );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ({$placeholders}) ORDER BY meta_id ASC", $args ),
			ARRAY_A
		);
		$values = array(); $presence = array(); $counts = array();
		foreach ( $keys as $key ) { $values[ $key ]=''; $presence[ $key ]=false; $counts[ $key ]=0; }
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = (string) ( isset( $row['meta_key'] ) ? $row['meta_key'] : '' );
			if ( ! array_key_exists( $key, $counts ) ) { continue; }
			$counts[ $key ]++;
			if ( $counts[ $key ] > 1 ) { return array( 'ok'=>false, 'values'=>$values, 'presence'=>$presence, 'reason'=>'legacy_group_duplicate_meta' ); }
			$presence[ $key ]=true; $values[ $key ]=(string) ( isset( $row['meta_value'] ) ? $row['meta_value'] : '' );
		}
		return array( 'ok'=>true, 'values'=>$values, 'presence'=>$presence, 'reason'=>'' );
	}

	/** @return bool */
	private static function row_matches_legacy_field( $row, $snapshot, $meta_key, $field ) {
		$present = ! empty( $snapshot['presence'][ $meta_key ] );
		$row_present = 1 === absint( isset( $row[ $field . '_present' ] ) ? $row[ $field . '_present' ] : 0 );
		if ( $present !== $row_present ) { return false; }
		if ( ! $present ) { return 0 === absint( isset( $row[ $field ] ) ? $row[ $field ] : 0 ); }
		$value = isset( $snapshot['values'][ $meta_key ] ) ? (string) $snapshot['values'][ $meta_key ] : '';
		if ( ! preg_match( '/^\d+$/', $value ) ) { return false; }
		return absint( $row[ $field ] ?? 0 ) === absint( $value );
	}

	/** @return bool */
	private static function row_matches_legacy_incomplete( $row, $snapshot ) {
		$key = 'mobo_sync_incomplete';
		$present = ! empty( $snapshot['presence'][ $key ] );
		$row_present = 1 === absint( $row['sync_incomplete_meta_present'] ?? 0 );
		if ( $present !== $row_present ) { return false; }
		if ( ! $present ) {
			return 0 === absint( $row['sync_incomplete_meta'] ?? 0 )
				&& 0 === absint( $row['sync_incomplete'] ?? 0 );
		}
		$raw = (string) ( $snapshot['values'][ $key ] ?? '' );
		if ( ! in_array( $raw, array( '0', '1' ), true ) ) { return false; }
		$expected = (int) $raw;
		return $expected === absint( $row['sync_incomplete_meta'] ?? 0 )
			&& $expected === absint( $row['sync_incomplete'] ?? 0 );
	}

	/** Compare one retained Legacy source-hash row with its exact Custom value/presence. @return bool */
	private static function row_matches_legacy_hash( $row, $snapshot, $meta_key, $field ) {
		$present = ! empty( $snapshot['presence'][ $meta_key ] );
		$row_present = 1 === absint( $row[ $field . '_present' ] ?? 0 );
		if ( $present !== $row_present ) { return false; }
		if ( ! $present ) { return '' === (string) ( $row[ $field ] ?? '' ); }
		$legacy_raw = (string) ( $snapshot['values'][ $meta_key ] ?? '' );
		$legacy = self::normalize_hash( $legacy_raw );
		$row_value = (string) ( $row[ $field ] ?? '' );
		if ( '' === $legacy_raw ) { return '' === $row_value; }
		return '' !== $legacy && hash_equals( $legacy, $row_value );
	}

	/** @return array{status:string,reason:string} */
	private static function apply_incomplete_mutation( $object_id, $incomplete, $authority ) {
		$type = get_post_type( $object_id );
		if ( 'product' === $type ) { return self::apply_product_incomplete( $object_id, $incomplete, $authority ); }
		if ( 'product_variation' === $type ) { return self::apply_variant_incomplete( $object_id, $incomplete, $authority ); }
		return array( 'status'=>'not-applicable', 'reason'=>'incomplete_key_not_applicable' );
	}

	/** @return array{status:string,reason:string} */
	private static function apply_product_incomplete( $product_id, $incomplete, $authority ) {
		global $wpdb;
		$authority=is_array($authority)?$authority:array();
		$guard_keys=array('_mobo_product_seen_revision','_mobo_product_applied_revision','_mobo_variant_seen_revision','_mobo_variant_applied_revision','_mobo_product_source_hash');
		$keys=array_values(array_filter($guard_keys,static function($key)use($authority){return empty($authority[$key]);}));
		$snapshot=empty($keys)?array('ok'=>true,'values'=>array(),'presence'=>array(),'reason'=>''):self::legacy_group_snapshot($product_id,$keys);
		if(empty($snapshot['ok'])){return array('status'=>'conflict','reason'=>(string)$snapshot['reason']);}
		$table = Mobo_Core_Storage_Schema::table_name( 'products' );
		for ( $attempt=0; $attempt<self::MAX_CAS_ATTEMPTS; $attempt++ ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id=%d AND woo_product_id=%d LIMIT 1", self::connection_id(), $product_id ), ARRAY_A );
			if ( ! is_array( $row ) ) { return array( 'status'=>'missing', 'reason'=>'product_custom_row_missing' ); }
			if ( ! class_exists( 'Mobo_Core_Remote_Identity_Policy' ) || ! Mobo_Core_Remote_Identity_Policy::is_valid( (string) ( $row['product_guid'] ?? '' ) ) ) { return array( 'status'=>'conflict', 'reason'=>'product_custom_identity_invalid' ); }
			if(!self::row_incomplete_consistent($row)){return array('status'=>'conflict','reason'=>'product_incomplete_state_invalid');}
			$matches=(!empty($authority['_mobo_product_seen_revision'])||self::row_matches_legacy_field($row,$snapshot,'_mobo_product_seen_revision','product_seen_revision'))
				&&(!empty($authority['_mobo_product_applied_revision'])||self::row_matches_legacy_field($row,$snapshot,'_mobo_product_applied_revision','product_applied_revision'))
				&&(!empty($authority['_mobo_variant_seen_revision'])||self::row_matches_legacy_field($row,$snapshot,'_mobo_variant_seen_revision','variant_seen_revision'))
				&&(!empty($authority['_mobo_variant_applied_revision'])||self::row_matches_legacy_field($row,$snapshot,'_mobo_variant_applied_revision','variant_applied_revision'))
				&&(!empty($authority['_mobo_product_source_hash'])||self::row_matches_legacy_hash($row,$snapshot,'_mobo_product_source_hash','product_source_hash'));
			if ( ! $matches ) { return array( 'status'=>'conflict', 'reason'=>'product_incomplete_commit_group_mismatch' ); }
			if ( 1 === absint( $row['sync_incomplete_meta_present'] ?? 0 ) && absint( $row['sync_incomplete_meta'] ?? 0 ) === $incomplete ) { return array( 'status'=>'noop', 'reason'=>'' ); }

			$ps=absint($row['product_seen_revision']??0); $pa=absint($row['product_applied_revision']??0);
			$vs=absint($row['variant_seen_revision']??0); $va=absint($row['variant_applied_revision']??0);
			$desired=max($ps,$vs); $required=array(); if($ps>0){$required[]=$pa;} if($vs>0){$required[]=$va;}
			$applied=!empty($required)?min($required):max($pa,$va);
			if ( 0 === $incomplete ) { $applied=max($applied,$desired); }
			$desired=max($desired,$applied);
			$hash=(string)($row['product_source_hash']??'');
			$desired_hash=$hash; $applied_hash=1===$incomplete?'':$hash;
			$status=(1===$incomplete||$desired>$applied)?'pending':'applied';
			$dirty=absint($row['dirty_flags']??0); $dirty='pending'===$status?($dirty|1):($dirty&~1);
			$now=current_time('mysql',true);
			$set='sync_incomplete_meta=%d,sync_incomplete_meta_present=1,sync_incomplete=%d,desired_revision=%d,applied_revision=%d,desired_hash=%s,applied_hash=%s,sync_status=%s,dirty_flags=%d,last_received_at=%s,updated_at=%s';
			$args=array($incomplete,$incomplete,$desired,$applied,$desired_hash,$applied_hash,$status,$dirty,$now,$now);
			if('applied'===$status){$set.=',last_applied_at=%s';$args[]=$now;}
			$sql="UPDATE {$table} SET {$set} WHERE id=%d AND product_seen_revision=%d AND product_seen_revision_present=%d AND product_applied_revision=%d AND product_applied_revision_present=%d AND variant_seen_revision=%d AND variant_seen_revision_present=%d AND variant_applied_revision=%d AND variant_applied_revision_present=%d AND product_source_hash=%s AND product_source_hash_present=%d AND sync_incomplete_meta=%d AND sync_incomplete_meta_present=%d AND sync_incomplete=%d";
			$args[]=absint($row['id']??0);
			$args[]=absint($row['product_seen_revision']??0);$args[]=absint($row['product_seen_revision_present']??0);
			$args[]=absint($row['product_applied_revision']??0);$args[]=absint($row['product_applied_revision_present']??0);
			$args[]=absint($row['variant_seen_revision']??0);$args[]=absint($row['variant_seen_revision_present']??0);
			$args[]=absint($row['variant_applied_revision']??0);$args[]=absint($row['variant_applied_revision_present']??0);
			$args[]=(string)($row['product_source_hash']??'');$args[]=absint($row['product_source_hash_present']??0);
			$args[]=absint($row['sync_incomplete_meta']??0);$args[]=absint($row['sync_incomplete_meta_present']??0);$args[]=absint($row['sync_incomplete']??0);
			$changed=$wpdb->query($wpdb->prepare($sql,$args));
			if(false===$changed){return array('status'=>'failure','reason'=>'product_incomplete_custom_update_failed');}
			if($changed>0){return array('status'=>'applied','reason'=>'');}
		}
		return array('status'=>'failure','reason'=>'product_incomplete_custom_cas_exhausted');
	}

	/** @return array{status:string,reason:string} */
	private static function apply_variant_incomplete( $variation_id, $incomplete, $authority ) {
		global $wpdb;
		$authority=is_array($authority)?$authority:array();$guard_keys=array('_mobo_variant_seen_revision','_mobo_variant_applied_revision','_mobo_variant_source_hash');
		$keys=array_values(array_filter($guard_keys,static function($key)use($authority){return empty($authority[$key]);}));
		$snapshot=empty($keys)?array('ok'=>true,'values'=>array(),'presence'=>array(),'reason'=>''):self::legacy_group_snapshot($variation_id,$keys);
		if(empty($snapshot['ok'])){return array('status'=>'conflict','reason'=>(string)$snapshot['reason']);}
		$table=Mobo_Core_Storage_Schema::table_name('variants');
		for($attempt=0;$attempt<self::MAX_CAS_ATTEMPTS;$attempt++){
			$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE connection_id=%d AND woo_variation_id=%d LIMIT 1",self::connection_id(),$variation_id),ARRAY_A);
			if(!is_array($row)){return array('status'=>'missing','reason'=>'variant_custom_row_missing');}
			if(!class_exists('Mobo_Core_Remote_Identity_Policy')||!Mobo_Core_Remote_Identity_Policy::is_valid((string)($row['variant_guid']??''))||!Mobo_Core_Remote_Identity_Policy::is_valid((string)($row['product_guid']??''))){return array('status'=>'conflict','reason'=>'variant_custom_identity_invalid');}
			$parent=absint(wp_get_post_parent_id($variation_id));if($parent<=0||$parent!==absint($row['woo_product_id']??0)){return array('status'=>'conflict','reason'=>'variant_parent_mismatch');}
			if(!self::row_incomplete_consistent($row)){return array('status'=>'conflict','reason'=>'variant_incomplete_state_invalid');}
			$matches=(!empty($authority['_mobo_variant_seen_revision'])||self::row_matches_legacy_field($row,$snapshot,'_mobo_variant_seen_revision','variant_seen_revision'))
				&&(!empty($authority['_mobo_variant_applied_revision'])||self::row_matches_legacy_field($row,$snapshot,'_mobo_variant_applied_revision','variant_applied_revision'))
				&&(!empty($authority['_mobo_variant_source_hash'])||self::row_matches_legacy_hash($row,$snapshot,'_mobo_variant_source_hash','variant_source_hash'));
			if(!$matches){return array('status'=>'conflict','reason'=>'variant_incomplete_commit_group_mismatch');}
			if(1===absint($row['sync_incomplete_meta_present']??0)&&absint($row['sync_incomplete_meta']??0)===$incomplete){return array('status'=>'noop','reason'=>'');}
			$vs=absint($row['variant_seen_revision']??0);$va=absint($row['variant_applied_revision']??0);
			$desired=max($vs,$va);$applied=$va;if(0===$incomplete){$applied=max($applied,$desired);}$desired=max($desired,$applied);
			$hash=(string)($row['variant_source_hash']??'');$desired_hash=$hash;$applied_hash=1===$incomplete?'':$hash;
			$status=(1===$incomplete||$desired>$applied)?'pending':'applied';$dirty=absint($row['dirty_flags']??0);$dirty='pending'===$status?($dirty|1):($dirty&~1);$now=current_time('mysql',true);
			$set='sync_incomplete_meta=%d,sync_incomplete_meta_present=1,sync_incomplete=%d,desired_revision=%d,applied_revision=%d,desired_hash=%s,applied_hash=%s,sync_status=%s,dirty_flags=%d,last_received_at=%s,updated_at=%s';
			$args=array($incomplete,$incomplete,$desired,$applied,$desired_hash,$applied_hash,$status,$dirty,$now,$now);if('applied'===$status){$set.=',last_applied_at=%s';$args[]=$now;}
			$sql="UPDATE {$table} SET {$set} WHERE id=%d AND variant_seen_revision=%d AND variant_seen_revision_present=%d AND variant_applied_revision=%d AND variant_applied_revision_present=%d AND variant_source_hash=%s AND variant_source_hash_present=%d AND sync_incomplete_meta=%d AND sync_incomplete_meta_present=%d AND sync_incomplete=%d";
			$args[]=absint($row['id']??0);$args[]=absint($row['variant_seen_revision']??0);$args[]=absint($row['variant_seen_revision_present']??0);$args[]=absint($row['variant_applied_revision']??0);$args[]=absint($row['variant_applied_revision_present']??0);$args[]=(string)($row['variant_source_hash']??'');$args[]=absint($row['variant_source_hash_present']??0);$args[]=absint($row['sync_incomplete_meta']??0);$args[]=absint($row['sync_incomplete_meta_present']??0);$args[]=absint($row['sync_incomplete']??0);
			$changed=$wpdb->query($wpdb->prepare($sql,$args));if(false===$changed){return array('status'=>'failure','reason'=>'variant_incomplete_custom_update_failed');}if($changed>0){return array('status'=>'applied','reason'=>'');}
		}
		return array('status'=>'failure','reason'=>'variant_incomplete_custom_cas_exhausted');
	}

	/** @return array{status:string,reason:string} */
	private static function apply_hash_mutation( $object_id, $meta_key, $hash, $authority ) {
		$type = get_post_type( $object_id );
		if ( 'product' === $type && '_mobo_product_source_hash' === $meta_key ) { return self::apply_product_hash( $object_id, $hash, $authority ); }
		if ( 'product_variation' === $type && '_mobo_variant_source_hash' === $meta_key ) { return self::apply_variant_hash( $object_id, $hash, $authority ); }
		return array( 'status'=>'not-applicable', 'reason'=>'hash_key_not_applicable' );
	}

	/** @return array{status:string,reason:string} */
	private static function apply_product_hash( $product_id, $hash, $authority ) {
		global $wpdb;
		$authority = is_array( $authority ) ? $authority : array();
		$guard_keys = array( '_mobo_product_seen_revision','_mobo_product_applied_revision','_mobo_variant_seen_revision','_mobo_variant_applied_revision','mobo_sync_incomplete' );
		$keys = array_values( array_filter( $guard_keys, static function( $key ) use ( $authority ) { return empty( $authority[ $key ] ); } ) );
		$snapshot = empty( $keys ) ? array( 'ok'=>true, 'values'=>array(), 'presence'=>array(), 'reason'=>'' ) : self::legacy_group_snapshot( $product_id, $keys );
		if ( empty( $snapshot['ok'] ) ) { return array( 'status'=>'conflict', 'reason'=>(string) $snapshot['reason'] ); }
		$table = Mobo_Core_Storage_Schema::table_name( 'products' );
		for ( $attempt=0; $attempt<self::MAX_CAS_ATTEMPTS; $attempt++ ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id=%d AND woo_product_id=%d LIMIT 1", self::connection_id(), $product_id ), ARRAY_A );
			if ( ! is_array( $row ) ) { return array( 'status'=>'missing', 'reason'=>'product_custom_row_missing' ); }
			if ( ! class_exists( 'Mobo_Core_Remote_Identity_Policy' ) || ! Mobo_Core_Remote_Identity_Policy::is_valid( (string) ( $row['product_guid'] ?? '' ) ) ) { return array( 'status'=>'conflict', 'reason'=>'product_custom_identity_invalid' ); }
			if ( ! self::row_incomplete_consistent( $row ) ) { return array( 'status'=>'conflict', 'reason'=>'product_incomplete_state_invalid' ); }
			$matches = ( ! empty( $authority['_mobo_product_seen_revision'] ) || self::row_matches_legacy_field( $row, $snapshot, '_mobo_product_seen_revision', 'product_seen_revision' ) )
				&& ( ! empty( $authority['_mobo_product_applied_revision'] ) || self::row_matches_legacy_field( $row, $snapshot, '_mobo_product_applied_revision', 'product_applied_revision' ) )
				&& ( ! empty( $authority['_mobo_variant_seen_revision'] ) || self::row_matches_legacy_field( $row, $snapshot, '_mobo_variant_seen_revision', 'variant_seen_revision' ) )
				&& ( ! empty( $authority['_mobo_variant_applied_revision'] ) || self::row_matches_legacy_field( $row, $snapshot, '_mobo_variant_applied_revision', 'variant_applied_revision' ) )
				&& ( ! empty( $authority['mobo_sync_incomplete'] ) || self::row_matches_legacy_incomplete( $row, $snapshot ) );
			if ( ! $matches ) { return array( 'status'=>'conflict', 'reason'=>'product_hash_commit_group_mismatch' ); }
			if ( 1 === absint( $row['product_source_hash_present'] ?? 0 ) && hash_equals( (string) ( $row['product_source_hash'] ?? '' ), $hash ) ) { return array( 'status'=>'noop', 'reason'=>'' ); }

			$incomplete = 1 === absint( $row['sync_incomplete'] ?? 0 );
			$desired = max( absint($row['product_seen_revision']??0), absint($row['variant_seen_revision']??0), absint($row['applied_revision']??0) );
			$applied = absint( $row['applied_revision'] ?? 0 );
			$applied_hash = $incomplete || $desired>$applied ? (string)($row['applied_hash']??'') : $hash;
			$status = ( $incomplete || $desired>$applied || $hash !== $applied_hash ) ? 'pending' : 'applied';
			$dirty = absint( $row['dirty_flags'] ?? 0 ); $dirty = 'pending'===$status ? ($dirty|1) : ($dirty&~1);
			$now=current_time('mysql',true);
			$sql="UPDATE {$table} SET product_source_hash=%s,product_source_hash_present=1,desired_hash=%s,applied_hash=%s,sync_status=%s,dirty_flags=%d,last_received_at=%s,updated_at=%s WHERE id=%d AND product_seen_revision=%d AND product_seen_revision_present=%d AND product_applied_revision=%d AND product_applied_revision_present=%d AND variant_seen_revision=%d AND variant_seen_revision_present=%d AND variant_applied_revision=%d AND variant_applied_revision_present=%d AND sync_incomplete_meta=%d AND sync_incomplete_meta_present=%d AND product_source_hash=%s AND product_source_hash_present=%d";
			$args=array($hash,$hash,$applied_hash,$status,$dirty,$now,$now,
				absint($row['id']??0),
				absint($row['product_seen_revision']??0),absint($row['product_seen_revision_present']??0),
				absint($row['product_applied_revision']??0),absint($row['product_applied_revision_present']??0),
				absint($row['variant_seen_revision']??0),absint($row['variant_seen_revision_present']??0),
				absint($row['variant_applied_revision']??0),absint($row['variant_applied_revision_present']??0),
				absint($row['sync_incomplete_meta']??0),absint($row['sync_incomplete_meta_present']??0),
				(string)($row['product_source_hash']??''),absint($row['product_source_hash_present']??0));
			$changed=$wpdb->query($wpdb->prepare($sql,$args));
			if(false===$changed){return array('status'=>'failure','reason'=>'product_hash_custom_update_failed');}
			if($changed>0){return array('status'=>'applied','reason'=>'');}
		}
		return array('status'=>'failure','reason'=>'product_hash_custom_cas_exhausted');
	}

	/** @return array{status:string,reason:string} */
	private static function apply_variant_hash( $variation_id, $hash, $authority ) {
		global $wpdb;
		$authority=is_array($authority)?$authority:array();$guard_keys=array('_mobo_variant_seen_revision','_mobo_variant_applied_revision','mobo_sync_incomplete');
		$keys=array_values(array_filter($guard_keys,static function($key)use($authority){return empty($authority[$key]);}));
		$snapshot=empty($keys)?array('ok'=>true,'values'=>array(),'presence'=>array(),'reason'=>''):self::legacy_group_snapshot($variation_id,$keys);
		if(empty($snapshot['ok'])){return array('status'=>'conflict','reason'=>(string)$snapshot['reason']);}
		$table=Mobo_Core_Storage_Schema::table_name('variants');
		for($attempt=0;$attempt<self::MAX_CAS_ATTEMPTS;$attempt++){
			$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE connection_id=%d AND woo_variation_id=%d LIMIT 1",self::connection_id(),$variation_id),ARRAY_A);
			if(!is_array($row)){return array('status'=>'missing','reason'=>'variant_custom_row_missing');}
			if(!class_exists('Mobo_Core_Remote_Identity_Policy')||!Mobo_Core_Remote_Identity_Policy::is_valid((string)($row['variant_guid']??''))||!Mobo_Core_Remote_Identity_Policy::is_valid((string)($row['product_guid']??''))){return array('status'=>'conflict','reason'=>'variant_custom_identity_invalid');}
			$parent=absint(wp_get_post_parent_id($variation_id));if($parent<=0||$parent!==absint($row['woo_product_id']??0)){return array('status'=>'conflict','reason'=>'variant_parent_mismatch');}
			if(!self::row_incomplete_consistent($row)){return array('status'=>'conflict','reason'=>'variant_incomplete_state_invalid');}
			$matches=(!empty($authority['_mobo_variant_seen_revision'])||self::row_matches_legacy_field($row,$snapshot,'_mobo_variant_seen_revision','variant_seen_revision'))
				&&(!empty($authority['_mobo_variant_applied_revision'])||self::row_matches_legacy_field($row,$snapshot,'_mobo_variant_applied_revision','variant_applied_revision'))
				&&(!empty($authority['mobo_sync_incomplete'])||self::row_matches_legacy_incomplete($row,$snapshot));
			if(!$matches){return array('status'=>'conflict','reason'=>'variant_hash_commit_group_mismatch');}
			if(1===absint($row['variant_source_hash_present']??0)&&hash_equals((string)($row['variant_source_hash']??''),$hash)){return array('status'=>'noop','reason'=>'');}
			$incomplete=1===absint($row['sync_incomplete']??0);$desired=max(absint($row['variant_seen_revision']??0),absint($row['variant_applied_revision']??0));$applied=absint($row['variant_applied_revision']??0);
			$applied_hash=$incomplete||$desired>$applied?(string)($row['applied_hash']??''):$hash;$status=($incomplete||$desired>$applied||$hash!==$applied_hash)?'pending':'applied';
			$dirty=absint($row['dirty_flags']??0);$dirty='pending'===$status?($dirty|1):($dirty&~1);$now=current_time('mysql',true);
			$sql="UPDATE {$table} SET variant_source_hash=%s,variant_source_hash_present=1,desired_hash=%s,applied_hash=%s,sync_status=%s,dirty_flags=%d,last_received_at=%s,updated_at=%s WHERE id=%d AND variant_seen_revision=%d AND variant_seen_revision_present=%d AND variant_applied_revision=%d AND variant_applied_revision_present=%d AND sync_incomplete_meta=%d AND sync_incomplete_meta_present=%d AND variant_source_hash=%s AND variant_source_hash_present=%d";
			$args=array($hash,$hash,$applied_hash,$status,$dirty,$now,$now,absint($row['id']??0),absint($row['variant_seen_revision']??0),absint($row['variant_seen_revision_present']??0),absint($row['variant_applied_revision']??0),absint($row['variant_applied_revision_present']??0),absint($row['sync_incomplete_meta']??0),absint($row['sync_incomplete_meta_present']??0),(string)($row['variant_source_hash']??''),absint($row['variant_source_hash_present']??0));
			$changed=$wpdb->query($wpdb->prepare($sql,$args));if(false===$changed){return array('status'=>'failure','reason'=>'variant_hash_custom_update_failed');}if($changed>0){return array('status'=>'applied','reason'=>'');}
		}
		return array('status'=>'failure','reason'=>'variant_hash_custom_cas_exhausted');
	}

	/** @return void */
	private static function record_suppressed( $object_id, $meta_key, $value, $status ) {
		$connection_id=self::connection_id(); if($connection_id<=0){return;} self::ensure_bucket($connection_id);
		$b=&self::$buckets[$connection_id];
		$b['suppressed'][$meta_key]=isset($b['suppressed'][$meta_key])?$b['suppressed'][$meta_key]+1:1;
		if(count($b['samples'])<self::MAX_SAMPLES){
			$sample=array('type'=>'suppressed','connectionId'=>$connection_id,'objectId'=>absint($object_id),'metaKey'=>(string)$meta_key,'status'=>sanitize_key((string)$status));
			if(in_array((string)$meta_key,array('_mobo_product_source_hash','_mobo_variant_source_hash'),true)){$sample['hash']=self::normalize_hash($value);}
			elseif('mobo_sync_incomplete'===(string)$meta_key){$sample['incomplete']=absint($value);}
			else{$sample['revision']=absint($value);}
			$b['samples'][]=$sample;
		}
		self::register_shutdown();
	}

	/** @return void */
	private static function trip_request_breaker( $reason, $object_id, $meta_key ) {
		$connection_id=self::connection_id(); if($connection_id<=0){return;} self::ensure_bucket($connection_id); $b=&self::$buckets[$connection_id];
		$b['blocked']=true; $b['fallbacks'][$meta_key]=isset($b['fallbacks'][$meta_key])?$b['fallbacks'][$meta_key]+1:1;
		if(count($b['samples'])<self::MAX_SAMPLES){$b['samples'][]=array('type'=>'fallback','connectionId'=>$connection_id,'objectId'=>absint($object_id),'metaKey'=>(string)$meta_key,'reason'=>sanitize_key((string)$reason));}
		if(class_exists('Mobo_Core_Storage_Primary_Read')){Mobo_Core_Storage_Primary_Read::record_fallback('postmeta-suppression','',sanitize_key((string)$reason),absint($object_id),absint($object_id));}
		if(class_exists('Mobo_Core_Storage_Mode')){Mobo_Core_Storage_Mode::reset_request_cache();}
		self::register_shutdown();
	}

	/** @return bool */
	private static function increment_metric( $connection_id, $prefix, $counts ) {
		global $wpdb; $connection_id=absint($connection_id); if($connection_id<=0||empty($counts)){return true;} if(!class_exists('Mobo_Core_Storage_Schema')||!Mobo_Core_Storage_Schema::is_ready()){return false;}
		$safe=self::safe_keys(); $table=Mobo_Core_Storage_Schema::table_name('runtime'); $now=current_time('mysql',true); $values=array(); $args=array();
		foreach($counts as $meta_key=>$count){if(!isset($safe[$meta_key])||absint($count)<=0){continue;} $state=wp_json_encode(array('connectionId'=>$connection_id,'metaKey'=>$meta_key,'count'=>absint($count),'flushedAt'=>gmdate('c')));if(!is_string($state)){$state='{}';}$values[]='(%d,%s,%s,%s,%d,%s,%s,%s)';$args[]=$connection_id;$args[]=$prefix.$safe[$meta_key];$args[]=$now;$args[]=$now;$args[]=absint($count);$args[]=$state;$args[]=$now;$args[]=$now;}
		if(empty($values)){return true;}
		$sql="INSERT INTO {$table} (connection_id,worker_name,last_started_at,last_finished_at,consecutive_failures,state_json,created_at,updated_at) VALUES ".implode(',',$values)." ON DUPLICATE KEY UPDATE last_started_at=VALUES(last_started_at),last_finished_at=VALUES(last_finished_at),consecutive_failures=consecutive_failures+VALUES(consecutive_failures),state_json=VALUES(state_json),updated_at=VALUES(updated_at)";
		return false!==$wpdb->query($wpdb->prepare($sql,$args));
	}

	/** @return void */
	private static function register_shutdown(){if(self::$shutdown_registered){return;}self::$shutdown_registered=true;Mobo_Core_Environment::on_shutdown(array(__CLASS__,'flush'));}
	/** @return void */
	private static function clear_request_state(){self::$buckets=array();self::$shutdown_registered=false;}
}
