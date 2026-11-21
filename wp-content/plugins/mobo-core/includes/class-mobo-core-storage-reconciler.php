<?php
/**
 * Bounded reconciliation between authoritative Legacy Product Map/Woo state and
 * the new Product/Variation custom-storage mirrors.
 *
 * 10.33.75 keeps Legacy authoritative for reconciliation. This worker only
 * refreshes Custom Storage from Legacy, removes rows that point to deleted/auto-draft Woo objects,
 * preserves trashed Product/Variation identity for exact Repair recovery, while
 * explicit Variation quarantine still retires identity through its lifecycle primitive,
 * and reports ambiguous active identity drift. It never mutates Product Map,
 * WooCommerce objects, or storage mode.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Storage_Reconciler {

	/** Active Source owner for all reconciler rows and telemetry. */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	const WORKER_NAME = 'storage_reconciler_v1';
	const DEFAULT_BATCH = 24;
	const MAX_BATCH = 100;
	const CLEANUP_BATCH = 12;
	const MIN_RUN_INTERVAL = 60;

	/** Whether a low-priority reconciliation pass may run now. @return bool */
	public static function should_run() {
		if ( ! class_exists( 'Mobo_Core_Storage_Mode' ) || ! Mobo_Core_Storage_Mode::writes_custom() ) {
			return false;
		}
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}
		if ( ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists() ) {
			return false;
		}
		$backfill = class_exists( 'Mobo_Core_Storage_Backfill' ) ? Mobo_Core_Storage_Backfill::get_status() : array();
		if ( 'completed' !== sanitize_key( (string) ( isset( $backfill['status'] ) ? $backfill['status'] : '' ) )
			|| 0 !== absint( isset( $backfill['gaps'] ) ? $backfill['gaps'] : 0 )
			|| '' !== trim( (string) ( isset( $backfill['lastError'] ) ? $backfill['lastError'] : '' ) ) ) {
			return false;
		}
		$status = self::get_status();
		$last = absint( isset( $status['lastRunAt'] ) ? $status['lastRunAt'] : 0 );
		return $last <= 0 || ( time() - $last ) >= self::MIN_RUN_INTERVAL;
	}

	/**
	 * Refresh at most one bounded slice of active Legacy mappings and clean a
	 * bounded number of Custom rows whose Woo object is definitely retired.
	 *
	 * @param int $limit Active Legacy rows to refresh.
	 * @return array
	 */
	public static function process_batch( $limit = self::DEFAULT_BATCH, $cleanup_limit = self::CLEANUP_BATCH ) {
		global $wpdb;
		$limit = max( 1, min( self::MAX_BATCH, absint( $limit ) ) );
		$cleanup_limit = max( 0, min( self::MAX_BATCH, absint( $cleanup_limit ) ) );
		$result = array(
			'success' => true,
			'status' => 'dormant',
			'processed' => 0,
			'mirrored' => 0,
			'cleaned' => 0,
			'failed' => 0,
			'ambiguous' => 0,
			'cursor' => 0,
			'wrapped' => false,
			'coverage' => array(),
			'samples' => array(),
		);
		if ( ! self::should_run() ) {
			$status = self::get_status();
			$result['status'] = Mobo_Core_Storage_Mode::writes_custom() ? 'blocked' : 'dormant';
			$result['cursor'] = absint( isset( $status['cursor'] ) ? $status['cursor'] : 0 );
			return $result;
		}

		$state = self::read_runtime_row();
		$cursor = absint( isset( $state['last_cursor'] ) ? $state['last_cursor'] : 0 );
		$result['cursor'] = $cursor;
		$map = Mobo_Core_Product_Map::table_name();
		$posts = $wpdb->posts;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.remote_guid, m.wp_post_id, m.object_type, m.parent_remote_guid, m.sync_incomplete
				 FROM {$map} m
				 INNER JOIN {$posts} p ON p.ID = m.wp_post_id
				 WHERE m.connection_id = %d
				   AND m.id > %d
				   AND m.object_type IN (%s,%s)
				   AND ((m.object_type=%s AND p.post_type='product') OR (m.object_type=%s AND p.post_type='product_variation'))
				   AND p.post_status NOT IN ('trash','auto-draft')
				 ORDER BY m.id ASC LIMIT %d",
				self::connection_id(),
				$cursor,
				Mobo_Core_Product_Map::TYPE_PRODUCT,
				Mobo_Core_Product_Map::TYPE_VARIATION,
				Mobo_Core_Product_Map::TYPE_PRODUCT,
				Mobo_Core_Product_Map::TYPE_VARIATION,
				$limit
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			$result['success'] = false;
			$result['status'] = 'error';
			$result['failed'] = 1;
			self::append_sample( $result['samples'], array( 'reason' => 'legacy_map_read_failed', 'message' => sanitize_text_field( (string) $wpdb->last_error ) ) );
			self::persist_result( $result, $cursor );
			return $result;
		}

		if ( empty( $rows ) && $cursor > 0 ) {
			$cursor = 0;
			$result['cursor'] = 0;
			$result['wrapped'] = true;
		}

		foreach ( $rows as $row ) {
			$id = absint( isset( $row['id'] ) ? $row['id'] : 0 );
			$guid = sanitize_text_field( (string) ( isset( $row['remote_guid'] ) ? $row['remote_guid'] : '' ) );
			$post_id = absint( isset( $row['wp_post_id'] ) ? $row['wp_post_id'] : 0 );
			$type = sanitize_key( (string) ( isset( $row['object_type'] ) ? $row['object_type'] : '' ) );
			$parent_guid = sanitize_text_field( (string) ( isset( $row['parent_remote_guid'] ) ? $row['parent_remote_guid'] : '' ) );
			$incomplete = ! empty( $row['sync_incomplete'] );
			if ( $id <= 0 ) { continue; }
			$ok = false;
			if ( Mobo_Core_Product_Map::TYPE_PRODUCT === $type ) {
				$ok = Mobo_Core_Storage_Bridge::mirror_product_legacy_state( $guid, $post_id, $incomplete );
			} elseif ( Mobo_Core_Product_Map::TYPE_VARIATION === $type ) {
				$ok = Mobo_Core_Storage_Bridge::mirror_variant_legacy_state( $guid, $post_id, $parent_guid, $incomplete );
			}
			$result['processed']++;
			if ( $ok ) {
				$result['mirrored']++;
			} else {
				if ( self::has_custom_identity_collision( $type, $guid, $post_id ) ) {
					$result['ambiguous']++;
					self::append_sample( $result['samples'], array( 'reason' => 'identity_collision', 'type' => $type, 'guid' => $guid, 'wooId' => $post_id ) );
				} else {
					$result['failed']++;
					self::append_sample( $result['samples'], array( 'reason' => 'mirror_failed', 'type' => $type, 'guid' => $guid, 'wooId' => $post_id ) );
				}
			}
			$cursor = $id;
		}
		$result['cursor'] = $cursor;

		if ( $cleanup_limit > 0 ) {
			$result['cleaned'] += self::cleanup_retired_products( $cleanup_limit, $result );
			$result['cleaned'] += self::cleanup_retired_variants( $cleanup_limit, $result );
		}
		$result['coverage'] = self::coverage_snapshot();

		if ( $result['failed'] > 0 ) {
			$result['success'] = false;
			$result['status'] = 'error';
		} elseif ( $result['ambiguous'] > 0 || absint( isset( $result['coverage']['activeOrphanProducts'] ) ? $result['coverage']['activeOrphanProducts'] : 0 ) > 0 || absint( isset( $result['coverage']['activeOrphanVariants'] ) ? $result['coverage']['activeOrphanVariants'] : 0 ) > 0 ) {
			$result['status'] = 'ambiguous';
		} elseif ( absint( isset( $result['coverage']['missingProducts'] ) ? $result['coverage']['missingProducts'] : 0 ) > 0 || absint( isset( $result['coverage']['missingVariants'] ) ? $result['coverage']['missingVariants'] : 0 ) > 0 || absint( isset( $result['coverage']['retiredProducts'] ) ? $result['coverage']['retiredProducts'] : 0 ) > 0 || absint( isset( $result['coverage']['retiredVariants'] ) ? $result['coverage']['retiredVariants'] : 0 ) > 0 ) {
			$result['status'] = 'repairing';
		} else {
			$result['status'] = 'clean';
		}
		self::persist_result( $result, $cursor );
		return $result;
	}

	/**
	 * Hook: a plain merchant Trash action retains Custom identity so an exact
	 * authoritative Repair may restore the object after physical postmeta retirement.
	 * Explicit Variation quarantine clears identity separately and remains terminal.
	 */
	public static function handle_trashed_post( $post_id ) {
		unset( $post_id );
	}

	/** Hook: permanent deletion removes any Custom row that still points at it. */
	public static function handle_deleted_post( $post_id, $post = null ) {
		self::cleanup_retired_post_id( $post_id, $post );
	}

	/** Hook: if a mapping survived/restored an untrash, mirror it in its owner Connection. */
	public static function handle_untrashed_post( $post_id ) {
		global $wpdb;
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! class_exists( 'Mobo_Core_Storage_Mode' ) || ! Mobo_Core_Storage_Mode::writes_custom() || ! Mobo_Core_Storage_Schema::is_ready() || ! Mobo_Core_Product_Map::table_exists() || ! class_exists( 'Mobo_Core_Connection_Context' ) ) { return; }
		$type = get_post_type( $post_id );
		$object_type = 'product' === $type ? Mobo_Core_Product_Map::TYPE_PRODUCT : ( 'product_variation' === $type ? Mobo_Core_Product_Map::TYPE_VARIATION : '' );
		if ( '' === $object_type ) { return; }
		$map = Mobo_Core_Product_Map::table_name();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT connection_id,remote_guid,parent_remote_guid,sync_incomplete FROM {$map} WHERE wp_post_id=%d AND object_type=%s ORDER BY id DESC LIMIT 1", $post_id, $object_type ), ARRAY_A );
		$connection_id = is_array( $row ) ? absint( $row['connection_id'] ?? 0 ) : 0;
		if ( $connection_id <= 0 ) { return; }
		Mobo_Core_Connection_Context::run(
			$connection_id,
			function () use ( $row, $post_id, $object_type ) {
				if ( Mobo_Core_Product_Map::TYPE_PRODUCT === $object_type ) {
					Mobo_Core_Storage_Bridge::mirror_product_legacy_state( (string) $row['remote_guid'], $post_id, ! empty( $row['sync_incomplete'] ) );
				} else {
					Mobo_Core_Storage_Bridge::mirror_variant_legacy_state( (string) $row['remote_guid'], $post_id, (string) $row['parent_remote_guid'], ! empty( $row['sync_incomplete'] ) );
				}
			}
		);
	}

	/** Last durable reconciliation telemetry. @return array */
	public static function get_status() {
		$row = self::read_runtime_row();
		$state = array();
		if ( isset( $row['state_json'] ) ) {
			$decoded = json_decode( (string) $row['state_json'], true );
			if ( is_array( $decoded ) ) { $state = $decoded; }
		}
		return array(
			'status' => sanitize_key( (string) ( isset( $state['status'] ) ? $state['status'] : 'not-run' ) ),
			'lastRunAt' => absint( isset( $state['lastRunAt'] ) ? $state['lastRunAt'] : 0 ),
			'lastRunAtIso' => sanitize_text_field( (string) ( isset( $state['lastRunAtIso'] ) ? $state['lastRunAtIso'] : '' ) ),
			'cursor' => absint( isset( $row['last_cursor'] ) ? $row['last_cursor'] : 0 ),
			'processed' => absint( isset( $state['processed'] ) ? $state['processed'] : 0 ),
			'mirrored' => absint( isset( $state['mirrored'] ) ? $state['mirrored'] : 0 ),
			'cleaned' => absint( isset( $state['cleaned'] ) ? $state['cleaned'] : 0 ),
			'failed' => absint( isset( $state['failed'] ) ? $state['failed'] : 0 ),
			'ambiguous' => absint( isset( $state['ambiguous'] ) ? $state['ambiguous'] : 0 ),
			'coverage' => isset( $state['coverage'] ) && is_array( $state['coverage'] ) ? $state['coverage'] : array(),
			'samples' => isset( $state['samples'] ) && is_array( $state['samples'] ) ? $state['samples'] : array(),
			'runnable' => self::should_run_without_throttle(),
		);
	}

	/** @return bool */
	private static function should_run_without_throttle() {
		if ( ! class_exists( 'Mobo_Core_Storage_Mode' ) || ! Mobo_Core_Storage_Mode::writes_custom() || ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() || ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists() ) { return false; }
		$backfill = class_exists( 'Mobo_Core_Storage_Backfill' ) ? Mobo_Core_Storage_Backfill::get_status() : array();
		return 'completed' === sanitize_key( (string) ( isset( $backfill['status'] ) ? $backfill['status'] : '' ) ) && 0 === absint( isset( $backfill['gaps'] ) ? $backfill['gaps'] : 0 ) && '' === trim( (string) ( isset( $backfill['lastError'] ) ? $backfill['lastError'] : '' ) );
	}

	/** @return array */
	private static function read_runtime_row() {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return array(); }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT last_cursor,state_json,last_finished_at FROM {$table} WHERE connection_id=%d AND worker_name=%s LIMIT 1", self::connection_id(), self::WORKER_NAME ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	/** @return bool */
	private static function persist_result( $result, $cursor ) {
		global $wpdb;
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$now_mysql = current_time( 'mysql', true );
		$now = time();
		$state = array(
			'status' => sanitize_key( (string) ( isset( $result['status'] ) ? $result['status'] : 'error' ) ),
			'lastRunAt' => $now,
			'lastRunAtIso' => gmdate( 'c', $now ),
			'processed' => absint( isset( $result['processed'] ) ? $result['processed'] : 0 ),
			'mirrored' => absint( isset( $result['mirrored'] ) ? $result['mirrored'] : 0 ),
			'cleaned' => absint( isset( $result['cleaned'] ) ? $result['cleaned'] : 0 ),
			'failed' => absint( isset( $result['failed'] ) ? $result['failed'] : 0 ),
			'ambiguous' => absint( isset( $result['ambiguous'] ) ? $result['ambiguous'] : 0 ),
			'coverage' => isset( $result['coverage'] ) && is_array( $result['coverage'] ) ? $result['coverage'] : array(),
			'samples' => isset( $result['samples'] ) && is_array( $result['samples'] ) ? array_slice( $result['samples'], 0, 5 ) : array(),
		);
		$json = wp_json_encode( $state );
		if ( ! is_string( $json ) ) { $json = '{}'; }
		$failures = absint( $state['failed'] );
		$status = sanitize_key( (string) $state['status'] );
		$sql = "INSERT INTO {$table} (connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,last_error_code,state_json,created_at,updated_at)
			VALUES (%d,%s,%s,%s,%s,%d,'closed',%s,%s,%s,%s)
			ON DUPLICATE KEY UPDATE last_started_at=VALUES(last_started_at),last_finished_at=VALUES(last_finished_at),last_cursor=VALUES(last_cursor),consecutive_failures=VALUES(consecutive_failures),circuit_state='closed',last_error_code=VALUES(last_error_code),state_json=VALUES(state_json),updated_at=VALUES(updated_at)";
		$written = $wpdb->query( $wpdb->prepare( $sql, self::connection_id(), self::WORKER_NAME, $now_mysql, $now_mysql, (string) absint( $cursor ), $failures, 'error' === $status ? 'reconcile_failed' : '', $json, $now_mysql, $now_mysql ) );
		return false !== $written;
	}

	/** @return int */
	private static function cleanup_retired_products( $limit, &$result ) {
		global $wpdb;
		$table = Mobo_Core_Storage_Schema::table_name( 'products' );
		$posts = $wpdb->posts;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.id,c.product_guid,c.woo_product_id FROM {$table} c LEFT JOIN {$posts} p ON p.ID=c.woo_product_id WHERE c.connection_id=%d AND c.woo_product_id IS NOT NULL AND (p.ID IS NULL OR p.post_type<>'product' OR p.post_status='auto-draft') ORDER BY c.id ASC LIMIT %d", self::connection_id(), absint( $limit ) ), ARRAY_A );
		if ( ! is_array( $rows ) ) { $result['failed']++; self::append_sample( $result['samples'], array( 'reason' => 'retired_product_read_failed' ) ); return 0; }
		$repo = new Mobo_Core_Product_State_Repository(); $cleaned = 0;
		foreach ( $rows as $row ) { if ( $repo->delete_exact( absint( $row['id'] ), (string) $row['product_guid'], absint( $row['woo_product_id'] ) ) ) { $cleaned++; } else { $result['failed']++; } }
		return $cleaned;
	}

	/** @return int */
	private static function cleanup_retired_variants( $limit, &$result ) {
		global $wpdb;
		$table = Mobo_Core_Storage_Schema::table_name( 'variants' );
		$posts = $wpdb->posts;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.id,c.variant_guid,c.woo_variation_id FROM {$table} c LEFT JOIN {$posts} p ON p.ID=c.woo_variation_id WHERE c.connection_id=%d AND c.woo_variation_id IS NOT NULL AND (p.ID IS NULL OR p.post_type<>'product_variation' OR p.post_status='auto-draft') ORDER BY c.id ASC LIMIT %d", self::connection_id(), absint( $limit ) ), ARRAY_A );
		if ( ! is_array( $rows ) ) { $result['failed']++; self::append_sample( $result['samples'], array( 'reason' => 'retired_variant_read_failed' ) ); return 0; }
		$repo = new Mobo_Core_Variant_State_Repository(); $cleaned = 0;
		foreach ( $rows as $row ) { if ( $repo->delete_exact( absint( $row['id'] ), (string) $row['variant_guid'], absint( $row['woo_variation_id'] ) ) ) { $cleaned++; } else { $result['failed']++; } }
		return $cleaned;
	}

	/** Clean one definitely retired post ID on lifecycle hooks. */
	private static function cleanup_retired_post_id( $post_id, $post = null ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! class_exists( 'Mobo_Core_Storage_Mode' ) || ! Mobo_Core_Storage_Mode::writes_custom() || ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() || ! class_exists( 'Mobo_Core_Connection_Context' ) ) { return; }
		$type = is_object( $post ) && isset( $post->post_type ) ? sanitize_key( (string) $post->post_type ) : sanitize_key( (string) get_post_type( $post_id ) );
		if ( 'product' === $type ) {
			$repo = new Mobo_Core_Product_State_Repository();
			$owner = $repo->get_owner_by_woo_product_id( $post_id );
			$connection_id = is_array( $owner ) ? absint( $owner['connection_id'] ?? 0 ) : 0;
			if ( $connection_id > 0 ) { Mobo_Core_Connection_Context::run( $connection_id, function () use ( $repo, $post_id ) { $repo->delete_by_woo_id( $post_id ); } ); }
		} elseif ( 'product_variation' === $type ) {
			$repo = new Mobo_Core_Variant_State_Repository();
			$owner = $repo->get_owner_by_woo_variation_id( $post_id );
			$connection_id = is_array( $owner ) ? absint( $owner['connection_id'] ?? 0 ) : 0;
			if ( $connection_id > 0 ) { Mobo_Core_Connection_Context::run( $connection_id, function () use ( $repo, $post_id ) { $repo->delete_by_woo_id( $post_id ); } ); }
		}
	}

	/** @return bool */
	private static function has_custom_identity_collision( $type, $guid, $woo_id ) {
		global $wpdb;
		if ( Mobo_Core_Product_Map::TYPE_PRODUCT === $type ) {
			$table = Mobo_Core_Storage_Schema::table_name( 'products' );
			$other = (string) $wpdb->get_var( $wpdb->prepare( "SELECT product_guid FROM {$table} WHERE connection_id=%d AND woo_product_id=%d LIMIT 1", self::connection_id(), absint( $woo_id ) ) );
		} else {
			$table = Mobo_Core_Storage_Schema::table_name( 'variants' );
			$other = (string) $wpdb->get_var( $wpdb->prepare( "SELECT variant_guid FROM {$table} WHERE connection_id=%d AND woo_variation_id=%d LIMIT 1", self::connection_id(), absint( $woo_id ) ) );
		}
		return '' !== $other && $other !== (string) $guid;
	}

	/** Compact coverage/drift snapshot used by Health and promotion diagnostics. @return array */
	private static function coverage_snapshot() {
		global $wpdb;
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return array(); }
		$map = Mobo_Core_Product_Map::table_name(); $posts = $wpdb->posts;
		$products = Mobo_Core_Storage_Schema::table_name( 'products' ); $variants = Mobo_Core_Storage_Schema::table_name( 'variants' );
		$missing_products = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$map} m INNER JOIN {$posts} p ON p.ID=m.wp_post_id LEFT JOIN {$products} c ON c.connection_id=%d AND c.product_guid=m.remote_guid AND c.woo_product_id=m.wp_post_id WHERE m.connection_id=%d AND m.object_type=%s AND p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft') AND c.id IS NULL", $connection_id, $connection_id, Mobo_Core_Product_Map::TYPE_PRODUCT ) ) );
		$missing_variants = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$map} m INNER JOIN {$posts} p ON p.ID=m.wp_post_id LEFT JOIN {$variants} c ON c.connection_id=%d AND c.variant_guid=m.remote_guid AND c.woo_variation_id=m.wp_post_id WHERE m.connection_id=%d AND m.object_type=%s AND p.post_type='product_variation' AND p.post_status NOT IN ('trash','auto-draft') AND c.id IS NULL", $connection_id, $connection_id, Mobo_Core_Product_Map::TYPE_VARIATION ) ) );
		$active_orphan_products = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$products} c INNER JOIN {$posts} p ON p.ID=c.woo_product_id LEFT JOIN {$map} m ON m.connection_id=%d AND m.remote_guid=c.product_guid AND m.wp_post_id=c.woo_product_id AND m.object_type=%s WHERE c.connection_id=%d AND p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft') AND m.id IS NULL", $connection_id, Mobo_Core_Product_Map::TYPE_PRODUCT, $connection_id ) ) );
		$active_orphan_variants = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$variants} c INNER JOIN {$posts} p ON p.ID=c.woo_variation_id LEFT JOIN {$map} m ON m.connection_id=%d AND m.remote_guid=c.variant_guid AND m.wp_post_id=c.woo_variation_id AND m.object_type=%s WHERE c.connection_id=%d AND p.post_type='product_variation' AND p.post_status NOT IN ('trash','auto-draft') AND m.id IS NULL", $connection_id, Mobo_Core_Product_Map::TYPE_VARIATION, $connection_id ) ) );
		$retired_products = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$products} c LEFT JOIN {$posts} p ON p.ID=c.woo_product_id WHERE c.connection_id=%d AND c.woo_product_id IS NOT NULL AND (p.ID IS NULL OR p.post_type<>'product' OR p.post_status='auto-draft')", $connection_id ) ) );
		$retired_variants = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$variants} c LEFT JOIN {$posts} p ON p.ID=c.woo_variation_id WHERE c.connection_id=%d AND c.woo_variation_id IS NOT NULL AND (p.ID IS NULL OR p.post_type<>'product_variation' OR p.post_status='auto-draft')", $connection_id ) ) );
		return array( 'missingProducts'=>$missing_products, 'missingVariants'=>$missing_variants, 'activeOrphanProducts'=>$active_orphan_products, 'activeOrphanVariants'=>$active_orphan_variants, 'retiredProducts'=>$retired_products, 'retiredVariants'=>$retired_variants );
	}

	/** Keep diagnostic payload bounded. */
	private static function append_sample( &$samples, $sample ) {
		if ( ! is_array( $samples ) ) { $samples = array(); }
		if ( count( $samples ) < 5 ) { $samples[] = $sample; }
	}
}
