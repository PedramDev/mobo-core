<?php
/** Durable authoritative variation seen-set outside wp_options. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Seen_Variants_Store {
	private static $availability = null;

	public static function table_name() {
		global $wpdb;
		$table = class_exists( 'Mobo_Core_Storage_Schema' ) ? Mobo_Core_Storage_Schema::table_name( 'seen_variants' ) : '';
		return '' !== $table ? $table : $wpdb->prefix . 'mobo_seen_variants';
	}

	public static function reset_availability_cache() { self::$availability = null; }

	public static function is_available() {
		global $wpdb;
		if ( null !== self::$availability ) { return (bool) self::$availability; }
		$table = self::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		self::$availability = ( (string) $found === $table );
		return (bool) self::$availability;
	}

	public static function scope_hash( $product_guid, $sync_id ) {
		return hash( 'sha256', self::connection_id() . '|' . sanitize_text_field( (string) $product_guid ) . '|' . sanitize_text_field( (string) $sync_id ) );
	}

	public static function initialize( $product_guid, $sync_id ) {
		global $wpdb;
		if ( ! self::is_available() ) { return false; }
		$scope = self::scope_hash( $product_guid, $sync_id );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$sql   = $wpdb->prepare(
			'INSERT INTO ' . self::table_name() . " (connection_id,scope_hash,product_guid,sync_id,variant_guid,variant_hash,is_scope_marker,created_at) VALUES (%d,%s,%s,%s,'','',1,%s) ON DUPLICATE KEY UPDATE product_guid=VALUES(product_guid),sync_id=VALUES(sync_id)",
			self::connection_id(), $scope,
			sanitize_text_field( (string) $product_guid ),
			sanitize_text_field( (string) $sync_id ),
			$now
		);
		return false !== $wpdb->query( $sql );
	}

	public static function reset( $product_guid, $sync_id ) {
		return self::clear( $product_guid, $sync_id ) && self::initialize( $product_guid, $sync_id );
	}

	public static function add_many( $product_guid, $sync_id, $variant_guids ) {
		global $wpdb;
		if ( ! self::initialize( $product_guid, $sync_id ) ) { return false; }
		$scope = self::scope_hash( $product_guid, $sync_id );
		$table = self::table_name();
		$now   = gmdate( 'Y-m-d H:i:s' );
		foreach ( (array) $variant_guids as $guid ) {
			$guid = sanitize_text_field( (string) $guid );
			if ( '' === $guid ) { continue; }
			$variant_hash = hash( 'sha256', $guid );
			$sql = $wpdb->prepare(
				"INSERT INTO {$table} (connection_id,scope_hash,product_guid,sync_id,variant_guid,variant_hash,is_scope_marker,created_at) VALUES (%d,%s,%s,%s,%s,%s,0,%s) ON DUPLICATE KEY UPDATE variant_guid=VALUES(variant_guid)",
				self::connection_id(), $scope,
				sanitize_text_field( (string) $product_guid ),
				sanitize_text_field( (string) $sync_id ),
				$guid,
				$variant_hash,
				$now
			);
			if ( false === $wpdb->query( $sql ) ) { return false; }
		}
		return true;
	}

	/**
	 * Load the authoritative seen set as the historical GUID=>1 shape.
	 *
	 * A missing scope marker is fail-closed after the strict cutover. Before the
	 * cutover only, one lazy legacy-option migration is permitted for upgrade
	 * compatibility.
	 *
	 * @return array|null Null means the authoritative checkpoint is unavailable.
	 */
	public static function load_set( $product_guid, $sync_id ) {
		global $wpdb;
		if ( ! self::is_available() ) { return null; }
		$scope = self::scope_hash( $product_guid, $sync_id );
		$table = self::table_name();
		$marker = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE connection_id=%d AND scope_hash=%s AND is_scope_marker=1", self::connection_id(), $scope ) );
		if ( '' !== trim( (string) $wpdb->last_error ) ) { return null; }
		if ( absint( $marker ) < 1 ) {
			$legacy_allowed = ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed();
			if ( $legacy_allowed && self::migrate_legacy_scope( $product_guid, $sync_id ) ) {
				return self::load_set( $product_guid, $sync_id );
			}
			return null;
		}
		$guids = $wpdb->get_col( $wpdb->prepare( "SELECT variant_guid FROM {$table} WHERE connection_id=%d AND scope_hash=%s AND is_scope_marker=0 ORDER BY id ASC", self::connection_id(), $scope ) );
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $guids ) ) { return null; }
		$set = array();
		foreach ( $guids as $guid ) {
			$guid = sanitize_text_field( (string) $guid );
			if ( '' !== $guid ) { $set[ $guid ] = 1; }
		}
		return $set;
	}

	public static function count( $product_guid, $sync_id ) {
		$set = self::load_set( $product_guid, $sync_id );
		return is_array( $set ) ? count( $set ) : -1;
	}

	public static function clear( $product_guid, $sync_id ) {
		global $wpdb;
		if ( ! self::is_available() ) { return false; }
		$scope = self::scope_hash( $product_guid, $sync_id );
		return false !== $wpdb->delete( self::table_name(), array( 'connection_id' => self::connection_id(), 'scope_hash' => $scope ), array( '%d', '%s' ) );
	}

	public static function migrate_legacy_scope( $product_guid, $sync_id ) {
		global $wpdb;
		if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { return false; }
		if ( ! self::is_available() ) { return false; }
		if ( ! self::is_default_connection() ) { return false; }

		$option   = 'mobo_seen_variants_' . md5( sanitize_text_field( (string) $product_guid ) . '|' . sanitize_text_field( (string) $sync_id ) );
		$sentinel = new stdClass();
		$legacy   = get_option( $option, $sentinel );
		if ( $sentinel === $legacy ) { return false; }
		if ( ! is_array( $legacy ) ) { return false; }

		$scope        = self::scope_hash( $product_guid, $sync_id );
		$table        = self::table_name();
		$product_guid = sanitize_text_field( (string) $product_guid );
		$sync_id      = sanitize_text_field( (string) $sync_id );
		$legacy_guids = array();
		foreach ( array_keys( $legacy ) as $guid ) {
			$guid = sanitize_text_field( (string) $guid );
			if ( '' !== $guid ) { $legacy_guids[ $guid ] = 1; }
		}
		$legacy_guids = array_keys( $legacy_guids );
		sort( $legacy_guids, SORT_STRING );

		/* Publish the scope marker last. If migration is interrupted while rows are
		 * being rebuilt, load_set() sees no marker and cannot mistake a partial set
		 * for an authoritative finalization checkpoint. */
		if ( false === $wpdb->delete( $table, array( 'connection_id' => self::connection_id(), 'scope_hash' => $scope ), array( '%d', '%s' ) ) ) { return false; }
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $legacy_guids as $guid ) {
			$sql = $wpdb->prepare(
				"INSERT INTO {$table} (connection_id,scope_hash,product_guid,sync_id,variant_guid,variant_hash,is_scope_marker,created_at) VALUES (%d,%s,%s,%s,%s,%s,0,%s) ON DUPLICATE KEY UPDATE variant_guid=VALUES(variant_guid)",
				self::connection_id(), $scope,
				$product_guid,
				$sync_id,
				$guid,
				hash( 'sha256', $guid ),
				$now
			);
			if ( false === $wpdb->query( $sql ) ) { return false; }
		}

		$stored_guids = $wpdb->get_col( $wpdb->prepare( "SELECT variant_guid FROM {$table} WHERE connection_id=%d AND scope_hash=%s AND is_scope_marker=0 ORDER BY id ASC", self::connection_id(), $scope ) );
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $stored_guids ) ) { return false; }
		$stored_guids = array_values( array_map( 'strval', $stored_guids ) );
		sort( $stored_guids, SORT_STRING );
		if ( maybe_serialize( $stored_guids ) !== maybe_serialize( $legacy_guids ) ) { return false; }

		if ( ! self::initialize( $product_guid, $sync_id ) ) { return false; }
		$verified = self::load_set( $product_guid, $sync_id );
		if ( ! is_array( $verified ) ) { return false; }
		$verified_guids = array_keys( $verified );
		sort( $verified_guids, SORT_STRING );
		if ( maybe_serialize( $verified_guids ) !== maybe_serialize( $legacy_guids ) ) { return false; }

		delete_option( $option );
		wp_cache_delete( $option, 'options' );
		if ( $sentinel !== get_option( $option, $sentinel ) ) { return false; }
		return true;
	}

	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? Mobo_Core_Connection_Context::get_effective_connection_id() : 0;
	}

	private static function is_default_connection() {
		$id = self::connection_id();
		if ( $id <= 0 || ! class_exists( 'Mobo_Core_Connection_Repository' ) ) { return false; }
		$row = Mobo_Core_Connection_Repository::get_by_id( $id );
		return is_array( $row ) && 1 === absint( $row['is_default'] ?? 0 );
	}
}
