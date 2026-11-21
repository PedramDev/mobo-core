<?php
/**
 * Buffered Legacy-postmeta commit bridge for Custom Storage.
 *
 * 10.33.75 closes the write-coverage gap that existed when operational Product
 * or Variation metadata was committed outside Product Map upserts. Relevant
 * metadata changes are deduplicated per Woo object and mirrored from the final
 * authoritative Legacy snapshot in bounded chunks. No Custom state is used to
 * decide the Legacy write and mirror failures remain fail-open for the caller
 * while revoking promotion through the existing Storage Bridge failure counter.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Storage_Meta_Commit_Bridge {

	const MAX_PENDING_ENTITIES = 100;

	/** @var array<int,array{products:array<int,bool>,variants:array<int,bool>}> */
	private static $buckets = array();
	/** @var bool */
	private static $shutdown_registered = false;
	/** @var bool */
	private static $flushing = false;

	/**
	 * Receive one already-committed postmeta change after the tiny bootstrap key
	 * filter has established that this is Mobo operational state.
	 *
	 * @param mixed  $meta_id Meta row id(s); intentionally unused.
	 * @param int    $object_id Post id.
	 * @param string $meta_key Meta key.
	 * @param mixed  $meta_value New/deleted value; final state is read at flush.
	 * @return void
	 */
	public static function handle_post_meta_change( $meta_id, $object_id, $meta_key, $meta_value = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $meta_id, $meta_value );
		$object_id = absint( $object_id );
		$meta_key  = (string) $meta_key;
		if ( $object_id <= 0 || ! self::is_interested_key( $meta_key ) || ! self::enabled() ) { return; }

		$post_type = get_post_type( $object_id );
		if ( ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) { return; }
		$connection_id = self::resolve_connection_id( $object_id, $post_type );
		if ( $connection_id <= 0 ) { return; }

		self::with_connection( $connection_id, static function () use ( $object_id, $meta_key ) {
			if ( 'custom-only' === sanitize_key( (string) get_option( 'mobo_core_storage_mode', 'legacy' ) ) && class_exists( 'Mobo_Core_Storage_Legacy_Dependency' ) ) {
				Mobo_Core_Storage_Legacy_Dependency::record_legacy_write( 'postmeta', $object_id, $meta_key );
			}
			if ( class_exists( 'Mobo_Core_Storage_Primary_State_Read', false ) ) { Mobo_Core_Storage_Primary_State_Read::invalidate_object( $object_id ); }
			if ( 'custom-only' === sanitize_key( (string) get_option( 'mobo_core_storage_mode', 'legacy' ) )
				&& class_exists( 'Mobo_Core_Storage_Postmeta_Suppression_Readiness' ) ) {
				Mobo_Core_Storage_Postmeta_Suppression_Readiness::record_legacy_write( $object_id, $meta_key );
			}
		} );

		self::ensure_bucket( $connection_id );
		if ( 'product' === $post_type ) {
			self::$buckets[ $connection_id ]['products'][ $object_id ] = true;
		} else {
			self::$buckets[ $connection_id ]['variants'][ $object_id ] = true;
		}

		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );
		}

		if ( self::total_pending_count() >= self::MAX_PENDING_ENTITIES ) {
			self::flush();
		}
	}

	/**
	 * Mirror one bounded set of final Legacy snapshots.
	 *
	 * @return array{products:int,variants:int,skipped:int,failed:int}
	 */
	public static function flush() {
		if ( self::$flushing ) { return array( 'products'=>0, 'variants'=>0, 'skipped'=>0, 'failed'=>0 ); }
		self::$flushing = true;
		$buckets = self::$buckets;
		self::$buckets = array();
		$summary = array( 'products'=>0, 'variants'=>0, 'skipped'=>0, 'failed'=>0 );

		try {
			foreach ( $buckets as $connection_id => $bucket ) {
				$connection_id = absint( $connection_id );
				$product_ids = array_keys( isset( $bucket['products'] ) && is_array( $bucket['products'] ) ? $bucket['products'] : array() );
				$variant_ids = array_keys( isset( $bucket['variants'] ) && is_array( $bucket['variants'] ) ? $bucket['variants'] : array() );
				if ( $connection_id <= 0 ) { $summary['skipped'] += count( $product_ids ) + count( $variant_ids ); continue; }
				$part = self::with_connection( $connection_id, static function () use ( $product_ids, $variant_ids ) {
					return self::flush_bucket( $product_ids, $variant_ids );
				} );
				if ( ! is_array( $part ) ) { $summary['failed'] += count( $product_ids ) + count( $variant_ids ); continue; }
				foreach ( $summary as $key => $unused ) { $summary[ $key ] += absint( $part[ $key ] ?? 0 ); }
			}
		} finally {
			self::$flushing = false;
		}
		return $summary;
	}

	/** @return array{products:int,variants:int,skipped:int,failed:int} */
	private static function flush_bucket( $product_ids, $variant_ids ) {
		$summary = array( 'products'=>0, 'variants'=>0, 'skipped'=>0, 'failed'=>0 );
		if ( ! self::enabled() ) {
			$summary['skipped'] = count( $product_ids ) + count( $variant_ids );
			return $summary;
		}

		foreach ( $product_ids as $product_id ) {
			$product_id = absint( $product_id );
			if ( ! self::active_type( $product_id, 'product' ) ) { $summary['skipped']++; continue; }
			$product_guid = sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) );
			if ( ! self::valid_guid( $product_guid ) ) { $summary['skipped']++; continue; }
			$incomplete = '1' === (string) get_post_meta( $product_id, 'mobo_sync_incomplete', true );
			if ( Mobo_Core_Storage_Bridge::mirror_product_legacy_state( $product_guid, $product_id, $incomplete ) ) { $summary['products']++; }
			else { $summary['failed']++; }
		}

		foreach ( $variant_ids as $variation_id ) {
			$variation_id = absint( $variation_id );
			if ( ! self::active_type( $variation_id, 'product_variation' ) ) { $summary['skipped']++; continue; }
			$variant_guid = sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) );
			$parent_id = absint( wp_get_post_parent_id( $variation_id ) );
			$product_guid = $parent_id > 0 ? sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) ) : '';
			if ( ! self::valid_guid( $variant_guid ) || ! self::valid_guid( $product_guid ) ) { $summary['skipped']++; continue; }
			$incomplete = '1' === (string) get_post_meta( $variation_id, 'mobo_sync_incomplete', true );
			if ( Mobo_Core_Storage_Bridge::mirror_variant_legacy_state( $variant_guid, $variation_id, $product_guid, $incomplete ) ) { $summary['variants']++; }
			else { $summary['failed']++; }
		}
		return $summary;
	}

	/** @return bool */
	public static function is_interested_key( $meta_key ) {
		return in_array(
			(string) $meta_key,
			array(
				'product_guid', 'variant_guid',
				'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id', 'PortalProductId',
				'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id', 'PortalVariantId',
				'_mobo_product_seen_revision', '_mobo_product_applied_revision',
				'_mobo_variant_seen_revision', '_mobo_variant_applied_revision',
				'_mobo_product_source_hash', '_mobo_variant_source_hash',
				'mobo_sync_incomplete',
			),
			true
		);
	}

	/** Whether one Woo object has an unflushed authoritative Legacy mutation for the active Connection. @return bool */
	public static function has_pending_object( $object_id ) {
		$object_id = absint( $object_id );
		$connection_id = self::current_connection_id();
		if ( $object_id <= 0 || $connection_id <= 0 || empty( self::$buckets[ $connection_id ] ) ) { return false; }
		$bucket = self::$buckets[ $connection_id ];
		return isset( $bucket['products'][ $object_id ] ) || isset( $bucket['variants'][ $object_id ] );
	}

	/** Whether the bridge is currently constructing canonical Legacy snapshots. @return bool */
	public static function is_flushing() { return self::$flushing; }

	/** @return int */
	public static function pending_count() {
		$connection_id = self::current_connection_id();
		if ( $connection_id <= 0 || empty( self::$buckets[ $connection_id ] ) ) { return 0; }
		return count( self::$buckets[ $connection_id ]['products'] ) + count( self::$buckets[ $connection_id ]['variants'] );
	}

	/** Compact request-local diagnostics; performs no database write. @return array */
	public static function get_status() {
		return array(
			'enabled' => self::enabled(),
			'pendingEntities' => self::pending_count(),
			'maxPendingEntities' => self::MAX_PENDING_ENTITIES,
		);
	}

	/** Tests/manual tooling only. @return void */
	public static function reset_request_state() {
		self::$buckets = array(); self::$flushing = false;
	}

	/** @return int */
	private static function current_connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** @return void */
	private static function ensure_bucket( $connection_id ) {
		$connection_id = absint( $connection_id );
		if ( $connection_id > 0 && ! isset( self::$buckets[ $connection_id ] ) ) {
			self::$buckets[ $connection_id ] = array( 'products'=>array(), 'variants'=>array() );
		}
	}

	/** @return int */
	private static function total_pending_count() {
		$count = 0;
		foreach ( self::$buckets as $bucket ) {
			$count += count( isset( $bucket['products'] ) && is_array( $bucket['products'] ) ? $bucket['products'] : array() );
			$count += count( isset( $bucket['variants'] ) && is_array( $bucket['variants'] ) ? $bucket['variants'] : array() );
		}
		return $count;
	}

	/** Resolve the durable Source owner while the original mutation context is still available. @return int */
	private static function resolve_connection_id( $object_id, $post_type ) {
		global $wpdb;
		$object_id = absint( $object_id );
		if ( $object_id <= 0 ) { return 0; }
		$owner = 0;
		if ( class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready() ) {
			if ( 'product' === $post_type && class_exists( 'Mobo_Core_Product_State_Repository' ) ) {
				$table = Mobo_Core_Product_State_Repository::table_name();
				$owner = absint( $wpdb->get_var( $wpdb->prepare( "SELECT connection_id FROM {$table} WHERE woo_product_id=%d LIMIT 1", $object_id ) ) );
			} elseif ( 'product_variation' === $post_type && class_exists( 'Mobo_Core_Variant_State_Repository' ) ) {
				$table = Mobo_Core_Variant_State_Repository::table_name();
				$owner = absint( $wpdb->get_var( $wpdb->prepare( "SELECT connection_id FROM {$table} WHERE woo_variation_id=%d LIMIT 1", $object_id ) ) );
			}
		}
		if ( $owner <= 0 ) { $owner = self::current_connection_id(); }
		if ( $owner <= 0 ) { return 0; }
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists() ) {
			$row = Mobo_Core_Connection_Repository::get_by_id( $owner );
			if ( ! is_array( $row ) || empty( $row['is_enabled'] ) ) { return 0; }
		}
		return $owner;
	}

	/** @return mixed */
	private static function with_connection( $connection_id, $callback ) {
		$connection_id = absint( $connection_id );
		if ( $connection_id <= 0 || ! is_callable( $callback ) ) { return null; }
		if ( ! class_exists( 'Mobo_Core_Connection_Context' ) ) { return null; }
		return Mobo_Core_Connection_Context::run( $connection_id, $callback );
	}

	/** @return bool */
	private static function enabled() {
		return class_exists( 'Mobo_Core_Storage_Bridge' )
			&& Mobo_Core_Storage_Bridge::should_write_custom()
			&& class_exists( 'Mobo_Core_Storage_Schema' )
			&& Mobo_Core_Storage_Schema::is_ready();
	}

	/** @return bool */
	private static function valid_guid( $guid ) {
		return class_exists( 'Mobo_Core_Remote_Identity_Policy' ) && Mobo_Core_Remote_Identity_Policy::is_valid( $guid );
	}

	/** @return bool */
	private static function active_type( $post_id, $expected_type ) {
		if ( $expected_type !== get_post_type( $post_id ) ) { return false; }
		$status = sanitize_key( (string) get_post_status( $post_id ) );
		return ! in_array( $status, array( 'trash', 'auto-draft' ), true );
	}
}
