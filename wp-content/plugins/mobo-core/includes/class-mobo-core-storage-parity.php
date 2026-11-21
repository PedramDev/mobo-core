<?php
/**
 * Read-only shadow parity verifier for custom Product/Variation storage.
 *
 * Legacy Product Map/postmeta remains authoritative. In shadow-read mode this
 * observer compares the already-selected legacy identity/state with custom
 * storage, records bounded diagnostics, and never changes the returned runtime
 * value. Telemetry is aggregated in-request and flushed once to mobo_runtime.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Storage_Parity {

	const WORKER_NAME = 'storage_parity_v1';
	const MAX_COMPARISONS_PER_REQUEST = 32;
	const MAX_MISMATCH_SAMPLES = 5;

	/** @var array<int,array> Request-local telemetry bucketed by durable Connection id. */
	private static $buckets = array();
	/** @var bool */
	private static $shutdown_registered = false;

	/** Observe one product GUID lookup without affecting its legacy result. @return void */
	public static function observe_product_lookup( $product_guid, $legacy_product_id ) {
		if ( ! self::enabled() ) {
			return;
		}

		$product_guid = sanitize_text_field( (string) $product_guid );
		$connection_id = self::connection_id();
		$key = 'product|' . $product_guid;
		if ( ! self::reserve( $key, $connection_id ) ) {
			return;
		}

		$legacy = Mobo_Core_Storage_Bridge::legacy_product_snapshot( $product_guid, absint( $legacy_product_id ), null );
		$custom = ( new Mobo_Core_Product_State_Repository() )->get_by_guid( $product_guid );
		self::compare( $connection_id, 'product', $product_guid, $legacy, $custom, self::product_fields() );
	}

	/** Observe one variation GUID lookup without affecting its legacy result. @return void */
	public static function observe_variant_lookup( $variant_guid, $legacy_variation_id ) {
		self::observe_variant_lookups( array( (string) $variant_guid => absint( $legacy_variation_id ) ) );
	}

	/**
	 * Observe a bulk variation-map read. Custom rows and legacy map metadata are
	 * fetched in sets so one authoritative UpdateVariant page does not create an
	 * N+1 shadow-read query pattern.
	 *
	 * @param array<string,int> $lookups GUID => legacy variation ID.
	 * @return void
	 */
	public static function observe_variant_lookups( $lookups ) {
		global $wpdb;

		if ( ! self::enabled() || ! is_array( $lookups ) || empty( $lookups ) ) {
			return;
		}

		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return; }

		$selected = array();
		foreach ( $lookups as $guid => $legacy_id ) {
			$guid = sanitize_text_field( (string) $guid );
			$key  = 'variant|' . $guid;
			if ( ! self::reserve( $key, $connection_id ) ) {
				continue;
			}
			$selected[ $guid ] = absint( $legacy_id );
		}
		if ( empty( $selected ) ) {
			return;
		}

		$guids = array_keys( $selected );
		$custom_by_guid = array();
		$custom_table = Mobo_Core_Variant_State_Repository::table_name();
		foreach ( array_chunk( $guids, self::MAX_COMPARISONS_PER_REQUEST ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql = "SELECT * FROM {$custom_table} WHERE connection_id=%d AND variant_guid IN ({$placeholders})";
			$args = array_merge( array( $connection_id ), $chunk );
			$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $args ) );
			$rows = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prepared from fixed table and placeholders.
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$row_guid = sanitize_text_field( (string) ( isset( $row['variant_guid'] ) ? $row['variant_guid'] : '' ) );
				if ( '' !== $row_guid ) {
					$custom_by_guid[ $row_guid ] = $row;
				}
			}
		}

		$legacy_map = array();
		if ( class_exists( 'Mobo_Core_Product_Map' ) && Mobo_Core_Product_Map::table_exists() ) {
			$map_table = Mobo_Core_Product_Map::table_name();
			foreach ( array_chunk( $guids, self::MAX_COMPARISONS_PER_REQUEST ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				$sql = "SELECT remote_guid, parent_remote_guid, sync_incomplete FROM {$map_table} WHERE connection_id=%d AND object_type = %s AND remote_guid IN ({$placeholders})";
				$args = array_merge( array( $connection_id, Mobo_Core_Product_Map::TYPE_VARIATION ), $chunk );
				$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $args ) );
				$rows = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prepared from fixed table and placeholders.
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$row_guid = sanitize_text_field( (string) ( isset( $row['remote_guid'] ) ? $row['remote_guid'] : '' ) );
					if ( '' !== $row_guid ) {
						$legacy_map[ $row_guid ] = $row;
					}
				}
			}
		}

		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $selected ) ) ) );
		if ( ! empty( $post_ids ) ) {
			update_meta_cache( 'post', $post_ids );
		}

		foreach ( $selected as $guid => $legacy_id ) {
			$map_row = isset( $legacy_map[ $guid ] ) && is_array( $legacy_map[ $guid ] ) ? $legacy_map[ $guid ] : array();
			$parent_guid = sanitize_text_field( (string) ( isset( $map_row['parent_remote_guid'] ) ? $map_row['parent_remote_guid'] : '' ) );
			$sync_incomplete = array_key_exists( 'sync_incomplete', $map_row ) ? ! empty( $map_row['sync_incomplete'] ) : null;
			$legacy = Mobo_Core_Storage_Bridge::legacy_variant_snapshot( $guid, $legacy_id, $parent_guid, $sync_incomplete );
			$custom = isset( $custom_by_guid[ $guid ] ) ? $custom_by_guid[ $guid ] : null;
			self::compare( $connection_id, 'variant', $guid, $legacy, $custom, self::variant_fields() );
		}
	}

	/** Persist this request's aggregate metrics exactly once per Connection. @return bool */
	public static function flush() {
		global $wpdb;

		if ( empty( self::$buckets ) ) {
			return true;
		}
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			self::clear_request_buffer();
			return false;
		}

		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$now   = current_time( 'mysql', true );
		$ok    = true;
		foreach ( self::$buckets as $connection_id => $bucket ) {
			$connection_id = absint( $connection_id );
			if ( $connection_id <= 0 || ! is_array( $bucket ) ) { continue; }
			$comparisons = absint( $bucket['comparisons'] ?? 0 );
			$skipped     = absint( $bucket['skipped'] ?? 0 );
			if ( $comparisons <= 0 && $skipped <= 0 ) { continue; }

			$reasons = isset( $bucket['reasons'] ) && is_array( $bucket['reasons'] ) ? $bucket['reasons'] : array();
			$last_reason = '';
			if ( ! empty( $reasons ) ) {
				arsort( $reasons );
				$last_reason = sanitize_key( (string) key( $reasons ) );
			}
			$state = array(
				'connectionId' => $connection_id,
				'mode'         => Mobo_Core_Storage_Mode::effective(),
				'comparisons'  => $comparisons,
				'matches'      => absint( $bucket['matches'] ?? 0 ),
				'mismatches'   => absint( $bucket['mismatches'] ?? 0 ),
				'skippedByCap' => $skipped,
				'reasons'      => $reasons,
				'samples'      => isset( $bucket['samples'] ) && is_array( $bucket['samples'] ) ? $bucket['samples'] : array(),
				'flushedAt'    => gmdate( 'c' ),
			);
			$encoded = wp_json_encode( $state );
			if ( ! is_string( $encoded ) ) { $encoded = '{}'; }

			$sql = "INSERT INTO {$table}
				(connection_id, worker_name, last_started_at, last_finished_at, last_cursor, consecutive_failures, circuit_state, circuit_open_until, last_error_code, state_json, created_at, updated_at)
				VALUES (%d, %s, %s, %s, %s, %d, 'closed', NULL, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					last_started_at = VALUES(last_started_at),
					last_finished_at = VALUES(last_finished_at),
					last_cursor = CAST(COALESCE(NULLIF(last_cursor,''),'0') AS UNSIGNED) + CAST(VALUES(last_cursor) AS UNSIGNED),
					consecutive_failures = consecutive_failures + VALUES(consecutive_failures),
					circuit_state = 'closed',
					circuit_open_until = NULL,
					last_error_code = VALUES(last_error_code),
					state_json = VALUES(state_json),
					updated_at = VALUES(updated_at)";

			$result = $wpdb->query(
				$wpdb->prepare(
					$sql,
					$connection_id,
					self::WORKER_NAME,
					$now,
					$now,
					(string) $comparisons,
					absint( $bucket['mismatches'] ?? 0 ),
					$last_reason,
					$encoded,
					$now,
					$now
				)
			);
			if ( false === $result ) { $ok = false; }
		}
		self::clear_request_buffer();
		return $ok;
	}

	/** Return durable shadow-parity health telemetry. @return array */
	public static function get_status() {
		global $wpdb;

		$base = array(
			'enabled'          => self::enabled(),
			'mode'             => class_exists( 'Mobo_Core_Storage_Mode' ) ? Mobo_Core_Storage_Mode::effective() : 'legacy',
			'maxPerRequest'    => self::MAX_COMPARISONS_PER_REQUEST,
			'totalComparisons' => 0,
			'totalMatches'     => 0,
			'totalMismatches'  => 0,
			'mismatchRate'     => 0.0,
			'lastReason'       => '',
			'lastBatch'        => array(),
			'updatedAt'        => '',
		);
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return $base;
		}

		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return $base; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT last_cursor, consecutive_failures, last_error_code, state_json, updated_at FROM {$table} WHERE connection_id=%d AND worker_name = %s LIMIT 1", $connection_id, self::WORKER_NAME ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return $base;
		}

		$total = absint( isset( $row['last_cursor'] ) ? $row['last_cursor'] : 0 );
		$mismatches = absint( isset( $row['consecutive_failures'] ) ? $row['consecutive_failures'] : 0 );
		$decoded = json_decode( (string) ( isset( $row['state_json'] ) ? $row['state_json'] : '' ), true );
		$base['totalComparisons'] = $total;
		$base['totalMismatches']  = $mismatches;
		$base['totalMatches']     = max( 0, $total - $mismatches );
		$base['mismatchRate']     = $total > 0 ? round( ( $mismatches * 100 ) / $total, 4 ) : 0.0;
		$base['lastReason']       = sanitize_key( (string) ( isset( $row['last_error_code'] ) ? $row['last_error_code'] : '' ) );
		$base['lastBatch']        = is_array( $decoded ) ? $decoded : array();
		$base['updatedAt']        = sanitize_text_field( (string) ( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ) );
		return $base;
	}

	/** Delete only the parity telemetry row; product/variant state is untouched. @return bool */
	public static function reset_metrics() {
		global $wpdb;
		self::clear_request_buffer();
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$result = $wpdb->delete( $table, array( 'connection_id' => $connection_id, 'worker_name' => self::WORKER_NAME ), array( '%d', '%s' ) );
		return false !== $result;
	}

	/** @return bool */
	private static function enabled() {
		return class_exists( 'Mobo_Core_Storage_Mode' )
			&& Mobo_Core_Storage_Mode::compares_shadow_reads()
			&& class_exists( 'Mobo_Core_Storage_Schema' )
			&& Mobo_Core_Storage_Schema::is_ready();
	}

	/** @return int */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** @return array */
	private static function empty_bucket() {
		return array(
			'seen'        => array(),
			'comparisons' => 0,
			'matches'     => 0,
			'mismatches'  => 0,
			'skipped'     => 0,
			'reasons'     => array(),
			'samples'     => array(),
		);
	}

	/** @return bool */
	private static function reserve( $key, $connection_id ) {
		$key = sanitize_text_field( (string) $key );
		$connection_id = absint( $connection_id );
		if ( '' === $key || $connection_id <= 0 ) { return false; }
		if ( ! isset( self::$buckets[ $connection_id ] ) || ! is_array( self::$buckets[ $connection_id ] ) ) {
			self::$buckets[ $connection_id ] = self::empty_bucket();
		}
		$bucket =& self::$buckets[ $connection_id ];
		if ( isset( $bucket['seen'][ $key ] ) ) { return false; }
		if ( absint( $bucket['comparisons'] ) >= self::MAX_COMPARISONS_PER_REQUEST ) {
			$bucket['skipped'] = absint( $bucket['skipped'] ) + 1;
			return false;
		}
		$bucket['seen'][ $key ] = true;
		$bucket['comparisons'] = absint( $bucket['comparisons'] ) + 1;
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			Mobo_Core_Environment::on_shutdown( array( __CLASS__, 'flush' ) );
		}
		return true;
	}

	/** @return void */
	private static function compare( $connection_id, $entity_type, $guid, $legacy, $custom, $fields ) {
		$connection_id = absint( $connection_id );
		if ( $connection_id <= 0 || ! isset( self::$buckets[ $connection_id ] ) ) { return; }
		if ( null === $legacy && null === $custom ) {
			self::$buckets[ $connection_id ]['matches']++;
			return;
		}
		if ( null === $legacy ) {
			self::mismatch( $connection_id, $entity_type, $guid, 'legacy_missing', array( 'customRow' => true ) );
			return;
		}
		if ( null === $custom ) {
			self::mismatch( $connection_id, $entity_type, $guid, 'custom_missing', array( 'legacyId' => absint( isset( $legacy['woo_product_id'] ) ? $legacy['woo_product_id'] : ( isset( $legacy['woo_variation_id'] ) ? $legacy['woo_variation_id'] : 0 ) ) ) );
			return;
		}

		$diff = array();
		foreach ( $fields as $field => $type ) {
			$left  = self::normalize_value( isset( $legacy[ $field ] ) ? $legacy[ $field ] : null, $type );
			$right = self::normalize_value( isset( $custom[ $field ] ) ? $custom[ $field ] : null, $type );
			if ( $left !== $right ) {
				$diff[ $field ] = array( 'legacy' => $left, 'custom' => $right );
			}
		}
		if ( empty( $diff ) ) {
			self::$buckets[ $connection_id ]['matches']++;
			return;
		}

		self::mismatch( $connection_id, $entity_type, $guid, 'field_mismatch', array( 'fields' => array_keys( $diff ), 'diff' => $diff ) );
	}

	/** @return void */
	private static function mismatch( $connection_id, $entity_type, $guid, $reason, $detail ) {
		$connection_id = absint( $connection_id );
		if ( $connection_id <= 0 || ! isset( self::$buckets[ $connection_id ] ) ) { return; }
		$bucket =& self::$buckets[ $connection_id ];
		$bucket['mismatches'] = absint( $bucket['mismatches'] ) + 1;
		$reason = sanitize_key( (string) $reason );
		$bucket['reasons'][ $reason ] = isset( $bucket['reasons'][ $reason ] ) ? absint( $bucket['reasons'][ $reason ] ) + 1 : 1;
		if ( count( $bucket['samples'] ) < self::MAX_MISMATCH_SAMPLES ) {
			$bucket['samples'][] = array(
				'entityType' => sanitize_key( (string) $entity_type ),
				'guid'       => sanitize_text_field( (string) $guid ),
				'reason'     => $reason,
				'detail'     => is_array( $detail ) ? $detail : array(),
			);
		}
	}

	/** @return mixed */
	private static function normalize_value( $value, $type ) {
		if ( 'int' === $type ) {
			return absint( $value );
		}
		if ( 'hash' === $type ) {
			return class_exists( 'Mobo_Core_Source_Hash_Policy' ) ? Mobo_Core_Source_Hash_Policy::normalize( $value ) : '';
		}
		if ( 'key' === $type ) {
			return sanitize_key( (string) $value );
		}
		return sanitize_text_field( (string) $value );
	}

	/** @return array<string,string> */
	private static function product_fields() {
		return array(
			'product_guid'     => 'string',
			'woo_product_id'   => 'int',
			'portal_product_id'=> 'int',
			'desired_revision' => 'int',
			'applied_revision' => 'int',
			'desired_hash'     => 'hash',
			'applied_hash'     => 'hash',
			'sync_status'      => 'key',
		);
	}

	/** @return array<string,string> */
	private static function variant_fields() {
		return array(
			'variant_guid'      => 'string',
			'product_guid'      => 'string',
			'woo_variation_id'  => 'int',
			'woo_product_id'    => 'int',
			'portal_variant_id' => 'int',
			'desired_revision'  => 'int',
			'applied_revision'  => 'int',
			'desired_hash'      => 'hash',
			'applied_hash'      => 'hash',
			'sync_status'       => 'key',
		);
	}

	/** @return void */
	private static function clear_request_buffer() {
		self::$buckets = array();
	}
}
