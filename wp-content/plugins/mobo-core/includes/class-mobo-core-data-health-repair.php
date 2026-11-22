<?php
/**
 * Data health repairs: one finding at a time, always
 *   re-verify live state -> snapshot "before" into the backup table (verified)
 *   -> change -> snapshot "after" -> mark applied.
 * Undo restores "before" only when the live state still equals "after"
 * (compare-and-swap); otherwise the row is reported as a conflict and left alone.
 *
 * Owner rules enforced here:
 * - No product, variation or attachment is ever deleted or trashed. A duplicate
 *   product copy is hidden the same way the Source "hidden" state works (URL
 *   kept, out of stock, not purchasable, hidden from catalog/search). A duplicate
 *   or orphan variation is disabled (post_status private = WooCommerce's
 *   "Enabled" checkbox off) and set out of stock.
 * - Only derived/index rows that point at objects which no longer exist are
 *   removed (term relationships, WooCommerce lookup rows, broken map rows), and
 *   each removed row is kept in the backup table so it can be put back.
 * - Writes are plain SQL on postmeta/posts/terms (one code path for production
 *   and tests), followed by WordPress/WooCommerce cache invalidation, the
 *   variable-parent sync and a lookup-row rebuild.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Data_Health_Repair {

	const STOCK_KEYS = array( '_stock_status', '_manage_stock', '_stock', '_backorders' );

	const MARKER_HIDDEN   = '_mobo_data_health_hidden';
	const MARKER_DISABLED = '_mobo_data_health_disabled';
	const MARKER_RETIRED  = '_mobo_data_health_retired_identity';

	/** Same key as Mobo_Core_Source_Visibility::META_QUARANTINE_BLOCKED (storefront guard). */
	const META_SALE_BLOCKED = '_mobo_quarantine_sale_blocked';

	/** Actions that can be undone from the backup table. */
	const UNDOABLE = array( 'hide_copy', 'disable_variation', 'delete_row', 'regen_lookup', 'clear_media_ref', 'reclaim_row', 'seed_map' );

	/* ------------------------------------------------------------------ */
	/* Public API                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Apply one stored finding.
	 *
	 * @param array  $f Finding row (findings table).
	 * @param string $batch_id Batch id.
	 * @return array{result:string,note:string}
	 */
	public static function apply_finding( array $f, $batch_id ) {
		$action = (string) $f['action'];
		$detail = json_decode( (string) $f['detail'], true );
		$detail = is_array( $detail ) ? $detail : array();
		$plan   = self::plan( $action, $f, $detail );
		if ( is_string( $plan ) ) {
			return array( 'result' => 'skipped', 'note' => $plan );
		}
		$before = self::snapshot( $plan['spec'] );
		if ( false === $before ) {
			return array( 'result' => 'failed', 'note' => 'snapshot_read_failed' );
		}
		$backup_id = Mobo_Core_Data_Health_Store::backup_begin(
			array(
				'batch_id'   => $batch_id,
				'detector'   => (string) $f['detector'],
				'action'     => $action,
				'finding_id' => absint( $f['id'] ),
				'object_id'  => absint( $f['object_id'] ),
				'object_ref' => (string) $f['object_ref'],
				'before'     => array( 'spec' => $plan['spec'], 'snap' => $before ),
			)
		);
		if ( $backup_id <= 0 ) {
			return array( 'result' => 'failed', 'note' => 'backup_write_failed' );
		}
		$ok = false;
		try {
			$ok = (bool) call_user_func( $plan['act'] );
		} catch ( Throwable $e ) {
			$ok = false;
		}
		if ( ! $ok ) {
			/* Put back exactly what was captured; the object is left as before. */
			self::restore( $plan['spec'], $before );
			self::after_change( $plan['spec'] );
			Mobo_Core_Data_Health_Store::backup_finish( $backup_id, 'failed', null, 'action_failed_rolled_back' );
			return array( 'result' => 'failed', 'note' => 'action_failed_rolled_back' );
		}
		self::after_change( $plan['spec'] );
		$after = self::snapshot( $plan['spec'] );
		Mobo_Core_Data_Health_Store::backup_finish( $backup_id, 'applied', false === $after ? array() : $after );
		return array( 'result' => 'applied', 'note' => '' );
	}

	/**
	 * Undo one backup row (compare-and-swap).
	 *
	 * @param array $row Backup row.
	 * @return array{result:string,note:string}
	 */
	public static function undo_row( array $row ) {
		if ( 'pending' === (string) $row['status'] ) {
			return self::settle_interrupted( $row );
		}
		if ( 'applied' !== (string) $row['status'] ) {
			return array( 'result' => 'skipped', 'note' => 'not_applied' );
		}
		if ( ! in_array( (string) $row['action'], self::UNDOABLE, true ) ) {
			return array( 'result' => 'skipped', 'note' => 'not_undoable' );
		}
		$before = json_decode( (string) $row['before_json'], true );
		$after  = json_decode( (string) $row['after_json'], true );
		if ( ! is_array( $before ) || ! isset( $before['spec'], $before['snap'] ) || ! is_array( $after ) ) {
			return array( 'result' => 'failed', 'note' => 'backup_unreadable' );
		}
		$current = self::snapshot( $before['spec'] );
		if ( false === $current ) {
			return array( 'result' => 'failed', 'note' => 'snapshot_read_failed' );
		}
		if ( self::canonical_json( $current ) !== self::canonical_json( $after ) ) {
			Mobo_Core_Data_Health_Store::backup_finish( absint( $row['id'] ), 'conflict', null, 'changed_after_repair' );
			return array( 'result' => 'conflict', 'note' => 'changed_after_repair' );
		}
		if ( ! self::restore( $before['spec'], $before['snap'] ) ) {
			self::restore( $before['spec'], $current );
			self::after_change( $before['spec'] );
			Mobo_Core_Data_Health_Store::backup_finish( absint( $row['id'] ), 'failed', null, 'restore_failed' );
			return array( 'result' => 'failed', 'note' => 'restore_failed' );
		}
		self::after_change( $before['spec'] );
		Mobo_Core_Data_Health_Store::backup_finish( absint( $row['id'] ), 'undone', null, '' );
		return array( 'result' => 'undone', 'note' => '' );
	}

	/**
	 * A backup row left 'pending' by a request that died between backup and
	 * finish. When the live state still equals "before", nothing was applied;
	 * otherwise the change cannot be proven, so it is reported, never guessed.
	 *
	 * @param array $row Backup row.
	 * @return array{result:string,note:string}
	 */
	private static function settle_interrupted( array $row ) {
		$before = json_decode( (string) $row['before_json'], true );
		if ( ! is_array( $before ) || ! isset( $before['spec'], $before['snap'] ) ) {
			return array( 'result' => 'failed', 'note' => 'backup_unreadable' );
		}
		$current = self::snapshot( $before['spec'] );
		if ( false === $current ) {
			return array( 'result' => 'failed', 'note' => 'snapshot_read_failed' );
		}
		if ( self::canonical_json( $current ) === self::canonical_json( $before['snap'] ) ) {
			Mobo_Core_Data_Health_Store::backup_finish( absint( $row['id'] ), 'skipped', null, 'interrupted_before_change' );
			return array( 'result' => 'skipped', 'note' => 'interrupted_before_change' );
		}
		Mobo_Core_Data_Health_Store::backup_finish( absint( $row['id'] ), 'conflict', null, 'interrupted_apply_needs_support' );
		return array( 'result' => 'conflict', 'note' => 'interrupted_apply_needs_support' );
	}

	/* ------------------------------------------------------------------ */
	/* Plans: verify + snapshot spec + action closure                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Build the plan or return a skip reason (string).
	 *
	 * @param string $action Action.
	 * @param array  $f Finding.
	 * @param array  $detail Decoded detail.
	 * @return array|string
	 */
	private static function plan( $action, array $f, array $detail ) {
		$id  = absint( $f['object_id'] );
		$ref = (string) $f['object_ref'];
		switch ( $action ) {
			case 'hide_copy':
				return self::plan_hide_copy( $id, $ref, absint( $f['canonical_id'] ), $detail );
			case 'disable_variation':
				return self::plan_disable_variation( $id, $ref, absint( $f['canonical_id'] ), $detail, (string) $f['detector'] );
			case 'delete_row':
				return self::plan_delete_row( $ref );
			case 'regen_lookup':
				return self::plan_regen_lookup( $id );
			case 'clear_media_ref':
				return self::plan_clear_media_ref( $id );
			case 'seed_map':
				return self::plan_seed_map( $id, $ref, $detail );
			case 'recover_locks':
				return self::plan_recover_lock( $ref );
			case 'reclaim_row':
				return self::plan_reclaim_row( $ref );
		}
		return 'unknown_action';
	}

	/** @return array|null Post row (ID, post_type, post_status, post_parent). */
	private static function post( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_status, post_parent FROM {$wpdb->posts} WHERE ID=%d", absint( $id ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return bool */
	private static function is_live( $post ) {
		return is_array( $post ) && ! in_array( (string) $post['post_status'], array( 'trash', 'auto-draft' ), true );
	}

	/**
	 * Whether $id still claims $guid through identity meta.
	 *
	 * @param int      $id Post.
	 * @param string   $guid GUID.
	 * @param string[] $keys Keys.
	 * @return bool
	 */
	private static function claims_guid( $id, $guid, array $keys ) {
		global $wpdb;
		if ( '' === $guid ) {
			return false;
		}
		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_value=%s AND meta_key IN (" . Mobo_Core_Data_Health_Detectors::in_strings( $keys ) . ')', $id, $guid ) ) ) > 0;
	}

	/** @return array|string */
	private static function plan_hide_copy( $id, $guid, $canonical, array $detail ) {
		$post  = self::post( $id );
		$owner = self::post( $canonical );
		if ( ! is_array( $post ) || 'product' !== $post['post_type'] || ! self::is_live( $post ) ) {
			return 'object_not_live_product';
		}
		if ( $canonical <= 0 || $canonical === $id || ! is_array( $owner ) || 'product' !== $owner['post_type'] || ! self::is_live( $owner ) ) {
			return 'canonical_not_live';
		}
		$auth = Mobo_Core_Data_Health_Detectors::authoritative_owned( array( $id, $canonical ), 'product' );
		if ( isset( $auth[ $id ] ) || ! isset( $auth[ $canonical ] ) ) {
			return 'ownership_changed';
		}
		$retire = ! empty( $detail['retireIdentity'] ) && self::claims_guid( $id, $guid, Mobo_Core_Data_Health_Detectors::product_guid_keys() );
		if ( ! $retire && '1' === self::meta_single( $id, self::MARKER_HIDDEN ) ) {
			return 'already_hidden';
		}
		$children = self::child_ids( $id );
		$keys     = array_merge( self::STOCK_KEYS, array( self::META_SALE_BLOCKED, self::MARKER_HIDDEN, self::MARKER_RETIRED ), Mobo_Core_Data_Health_Detectors::product_identity_keys() );
		$spec     = array(
			'posts'      => array( $id ),
			'meta'       => array( $id => $keys ),
			'terms'      => array( $id ),
			'taxonomies' => array( 'product_visibility' ),
			'rows'       => array(),
			'lookup'     => array_merge( array( $id ), $children ),
			'parents'    => array(),
		);
		foreach ( $children as $child ) {
			$spec['meta'][ $child ] = array_merge( self::STOCK_KEYS, array( self::META_SALE_BLOCKED ) );
			$spec['terms'][]        = $child;
		}
		return array(
			'spec' => $spec,
			'act'  => static function () use ( $id, $children, $retire, $canonical ) {
				$ok = self::set_meta( $id, self::META_SALE_BLOCKED, '1' )
					&& self::set_meta( $id, self::MARKER_HIDDEN, '1' )
					&& self::set_out_of_stock( $id )
					&& self::add_visibility_terms( $id, array( 'exclude-from-catalog', 'exclude-from-search', 'outofstock' ) );
				foreach ( $children as $child ) {
					$ok = $ok && self::set_out_of_stock( $child ) && self::set_meta( $child, self::META_SALE_BLOCKED, '1' ) && self::add_visibility_terms( $child, array( 'outofstock' ) );
				}
				if ( $ok && $retire ) {
					$ok = self::retire_identity( $id, Mobo_Core_Data_Health_Detectors::product_identity_keys(), $canonical );
				}
				return $ok;
			},
		);
	}

	/** @return array|string */
	private static function plan_disable_variation( $id, $guid, $canonical, array $detail, $detector ) {
		$post = self::post( $id );
		if ( ! is_array( $post ) || 'product_variation' !== $post['post_type'] ) {
			return 'object_not_variation';
		}
		if ( 'publish' !== $post['post_status'] ) {
			return 'already_disabled';
		}
		$reason = isset( $detail['reason'] ) ? sanitize_key( (string) $detail['reason'] ) : $detector;
		if ( in_array( $reason, array( 'parent_missing', 'parent_not_product' ), true ) ) {
			$parent = self::post( absint( $post['post_parent'] ) );
			if ( is_array( $parent ) && 'product' === $parent['post_type'] ) {
				return 'parent_present_again';
			}
		} else {
			$owner = self::post( $canonical );
			if ( $canonical <= 0 || $canonical === $id || ! is_array( $owner ) || 'product_variation' !== $owner['post_type'] || 'publish' !== $owner['post_status'] ) {
				return 'canonical_not_live';
			}
			$auth = Mobo_Core_Data_Health_Detectors::authoritative_owned( array( $id, $canonical ), 'variation' );
			if ( isset( $auth[ $id ] ) || ! isset( $auth[ $canonical ] ) ) {
				return 'ownership_changed';
			}
			if ( 'duplicate_attribute_set' === $reason && absint( $owner['post_parent'] ) !== absint( $post['post_parent'] ) ) {
				return 'parent_changed';
			}
		}
		$retire = ! empty( $detail['retireIdentity'] ) && self::claims_guid( $id, $guid, Mobo_Core_Data_Health_Detectors::variant_guid_keys() );
		$keys   = array_merge( self::STOCK_KEYS, array( self::MARKER_DISABLED, self::MARKER_RETIRED ) );
		if ( $retire ) {
			$keys = array_merge( $keys, Mobo_Core_Data_Health_Detectors::variation_identity_keys() );
		}
		$parent_id = absint( $post['post_parent'] );
		$spec      = array(
			'posts'      => array( $id ),
			'meta'       => array( $id => $keys ),
			'terms'      => array( $id ),
			'taxonomies' => array( 'product_visibility' ),
			'rows'       => array(),
			'lookup'     => array( $id ),
			'parents'    => $parent_id > 0 ? array( $parent_id ) : array(),
		);
		return array(
			'spec' => $spec,
			'act'  => static function () use ( $id, $reason, $retire, $canonical ) {
				global $wpdb;
				$ok = false !== $wpdb->update( $wpdb->posts, array( 'post_status' => 'private' ), array( 'ID' => $id ) )
					&& self::set_meta( $id, self::MARKER_DISABLED, $reason )
					&& self::set_out_of_stock( $id )
					&& self::add_visibility_terms( $id, array( 'outofstock' ) );
				if ( $ok && $retire ) {
					$ok = self::retire_identity( $id, Mobo_Core_Data_Health_Detectors::variation_identity_keys(), $canonical );
				}
				return $ok;
			},
		);
	}

	/**
	 * Parse an object_ref into a row target.
	 *
	 * @param string $ref Reference.
	 * @return array|null [table_key, where(assoc), verify-closure]
	 */
	private static function row_target( $ref ) {
		global $wpdb;
		$parts = explode( ':', (string) $ref );
		$kind  = $parts[0];
		if ( 'map' === $kind && isset( $parts[1] ) ) {
			$id = absint( $parts[1] );
			return array(
				'map',
				array( 'id' => $id ),
				static function () use ( $id, $wpdb ) {
					$row = $wpdb->get_row( $wpdb->prepare( 'SELECT m.object_type, p.ID AS pid, p.post_type FROM ' . Mobo_Core_Data_Health_Detectors::table( 'map' ) . " m LEFT JOIN {$wpdb->posts} p ON p.ID=m.wp_post_id WHERE m.id=%d", $id ), ARRAY_A );
					if ( ! is_array( $row ) ) {
						return false;
					}
					$expected = 'product' === $row['object_type'] ? 'product' : 'product_variation';
					return null === $row['pid'] || $row['post_type'] !== $expected;
				},
			);
		}
		if ( 'tr' === $kind && isset( $parts[1], $parts[2] ) ) {
			$oid  = absint( $parts[1] );
			$ttid = absint( $parts[2] );
			return array(
				'term_relationships',
				array( 'object_id' => $oid, 'term_taxonomy_id' => $ttid ),
				static function () use ( $oid, $ttid ) {
					return null === self::post( $oid ) && self::is_product_tt( $ttid );
				},
			);
		}
		if ( 'lookup' === $kind && isset( $parts[1] ) ) {
			$pid = absint( $parts[1] );
			return array(
				'lookup',
				array( 'product_id' => $pid ),
				static function () use ( $pid ) {
					return null === self::post( $pid );
				},
			);
		}
		if ( 'attr' === $kind && isset( $parts[1] ) ) {
			$pid = absint( $parts[1] );
			return array(
				'attr_lookup',
				array( 'product_id' => $pid ),
				static function () use ( $pid ) {
					return null === self::post( $pid );
				},
			);
		}
		return null;
	}

	/** @return bool */
	private static function is_product_tt( $ttid ) {
		global $wpdb;
		$taxonomy = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT taxonomy FROM ' . Mobo_Core_Data_Health_Detectors::table( 'term_taxonomy' ) . ' WHERE term_taxonomy_id=%d', $ttid ) );
		return in_array( $taxonomy, Mobo_Core_Data_Health_Detectors::PRODUCT_TAXONOMIES, true ) || 0 === strpos( $taxonomy, 'pa_' );
	}

	/** @return array|string */
	private static function plan_delete_row( $ref ) {
		$target = self::row_target( $ref );
		if ( null === $target ) {
			return 'unknown_row_ref';
		}
		list( $key, $where, $verify ) = $target;
		if ( ! Mobo_Core_Data_Health_Detectors::has( $key ) ) {
			return 'table_missing';
		}
		$rows = self::read_rows( $key, $where );
		if ( false === $rows ) {
			return 'row_read_failed';
		}
		if ( empty( $rows ) ) {
			return 'row_already_gone';
		}
		if ( ! call_user_func( $verify ) ) {
			return 'condition_no_longer_true';
		}
		$spec = array( 'posts' => array(), 'meta' => array(), 'terms' => array(), 'taxonomies' => array(), 'rows' => array( array( $key, $where ) ), 'lookup' => array(), 'parents' => array() );
		return array(
			'spec' => $spec,
			'act'  => static function () use ( $key, $where ) {
				global $wpdb;
				$ok = false !== $wpdb->delete( Mobo_Core_Data_Health_Detectors::table( $key ), $where );
				if ( $ok && 'term_relationships' === $key && isset( $where['term_taxonomy_id'] ) ) {
					self::recount_terms( array( absint( $where['term_taxonomy_id'] ) ) );
				}
				return $ok;
			},
		);
	}

	/** @return array|string */
	private static function plan_regen_lookup( $id ) {
		$post = self::post( $id );
		if ( ! self::is_live( $post ) || ! in_array( (string) $post['post_type'], array( 'product', 'product_variation' ), true ) ) {
			return 'object_not_live_product';
		}
		if ( ! Mobo_Core_Data_Health_Detectors::has( 'lookup' ) ) {
			return 'table_missing';
		}
		$spec = array( 'posts' => array(), 'meta' => array(), 'terms' => array(), 'taxonomies' => array(), 'rows' => array( array( 'lookup', array( 'product_id' => $id ) ) ), 'lookup' => array(), 'parents' => array() );
		return array(
			'spec' => $spec,
			'act'  => static function () use ( $id ) {
				return self::rebuild_lookup_row( $id );
			},
		);
	}

	/** @return array|string */
	private static function plan_clear_media_ref( $id ) {
		global $wpdb;
		$post = self::post( $id );
		if ( ! self::is_live( $post ) ) {
			return 'object_not_live';
		}
		$thumb   = self::meta_values( $id, '_thumbnail_id' );
		$gallery = self::meta_values( $id, '_product_image_gallery' );
		$ids     = array();
		foreach ( array_merge( $thumb, $gallery ) as $value ) {
			foreach ( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) as $aid ) {
				$ids[ $aid ] = true;
			}
		}
		if ( empty( $ids ) ) {
			return 'nothing_referenced';
		}
		$exists = array();
		foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND ID IN (" . Mobo_Core_Data_Health_Detectors::in_ints( array_keys( $ids ) ) . ')' ) as $aid ) {
			$exists[ absint( $aid ) ] = true;
		}
		$missing = array_diff( array_keys( $ids ), array_keys( $exists ) );
		if ( empty( $missing ) ) {
			return 'references_valid_again';
		}
		$spec = array( 'posts' => array(), 'meta' => array( $id => array( '_thumbnail_id', '_product_image_gallery' ) ), 'terms' => array(), 'taxonomies' => array(), 'rows' => array(), 'lookup' => array(), 'parents' => array() );
		return array(
			'spec' => $spec,
			'act'  => static function () use ( $id, $thumb, $gallery, $exists ) {
				$ok = true;
				foreach ( $thumb as $value ) {
					if ( ! isset( $exists[ absint( $value ) ] ) ) {
						$ok = $ok && self::delete_meta( $id, '_thumbnail_id' );
					}
				}
				foreach ( $gallery as $value ) {
					$keep = array();
					foreach ( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) as $aid ) {
						if ( isset( $exists[ $aid ] ) ) {
							$keep[] = $aid;
						}
					}
					$ok = $ok && self::set_meta( $id, '_product_image_gallery', implode( ',', $keep ) );
				}
				return $ok;
			},
		);
	}

	/** @return array|string */
	private static function plan_seed_map( $id, $guid, array $detail ) {
		global $wpdb;
		if ( Mobo_Core_Data_Health_Detectors::custom_identity_mode() ) {
			return 'identity_fenced';
		}
		if ( ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Data_Health_Detectors::has( 'map' ) ) {
			return 'product_map_unavailable';
		}
		$post = self::post( $id );
		$type = isset( $detail['type'] ) && 'variation' === $detail['type'] ? 'variation' : 'product';
		if ( ! self::is_live( $post ) || ( 'product' === $type ? 'product' : 'product_variation' ) !== $post['post_type'] ) {
			return 'object_not_live';
		}
		$keys = 'product' === $type ? Mobo_Core_Data_Health_Detectors::product_guid_keys() : Mobo_Core_Data_Health_Detectors::variant_guid_keys();
		if ( ! self::claims_guid( $id, $guid, $keys ) ) {
			return 'identity_changed';
		}
		$claims = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_value=%s AND pm.meta_key IN (" . Mobo_Core_Data_Health_Detectors::in_strings( $keys ) . ') AND p.post_status NOT IN ' . Mobo_Core_Data_Health_Detectors::LIVE, $guid ) ) );
		$mapped = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Mobo_Core_Data_Health_Detectors::table( 'map' ) . ' WHERE remote_guid=%s AND object_type=%s', $guid, $type ) ) );
		if ( 1 !== $claims || 0 !== $mapped ) {
			return 'guid_not_unique_or_already_mapped';
		}
		$parent_guid = '';
		if ( 'variation' === $type ) {
			$parent_guid = self::meta_single( absint( $post['post_parent'] ), 'product_guid' );
			if ( '' === $parent_guid ) {
				$parent_guid = self::meta_single( $id, 'product_guid' );
			}
		}
		$spec = array( 'posts' => array(), 'meta' => array(), 'terms' => array(), 'taxonomies' => array(), 'rows' => array( array( 'map', array( 'remote_guid' => $guid, 'object_type' => $type ) ) ), 'lookup' => array(), 'parents' => array() );
		return array(
			'spec' => $spec,
			'act'  => static function () use ( $id, $guid, $type, $parent_guid ) {
				$map = new Mobo_Core_Product_Map();
				$ok  = 'product' === $type ? $map->upsert_product( $guid, $id ) : $map->upsert_variation( $guid, $id, $parent_guid );
				return false !== $ok && ! is_wp_error( $ok );
			},
		);
	}

	/** @return array|string */
	private static function plan_recover_lock( $name ) {
		$name = sanitize_key( (string) $name );
		if ( '' === $name || ! class_exists( 'Mobo_Core_Lock' ) ) {
			return 'lock_api_unavailable';
		}
		$spec = array( 'posts' => array(), 'meta' => array(), 'terms' => array(), 'taxonomies' => array(), 'rows' => array( array( 'options', array( 'option_name' => 'mobo_core_runtime_lock_' . $name ) ) ), 'lookup' => array(), 'parents' => array() );
		return array(
			'spec' => $spec,
			'act'  => static function () use ( $name ) {
				/* The lock API itself decides; only expired leases are released. */
				Mobo_Core_Lock::recover_stale_locks( array( $name ) );
				return true;
			},
		);
	}

	/** @return array|string */
	private static function plan_reclaim_row( $ref ) {
		global $wpdb;
		$parts = explode( ':', (string) $ref );
		$key   = isset( $parts[0] ) ? $parts[0] : '';
		$id    = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
		if ( ! in_array( $key, array( 'sync_events', 'image_queue' ), true ) || $id <= 0 || ! Mobo_Core_Data_Health_Detectors::has( $key ) ) {
			return 'unknown_row_ref';
		}
		$table = Mobo_Core_Data_Health_Detectors::table( $key );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT status, locked_until, updated_at FROM {$table} WHERE id=%d", $id ), ARRAY_A );
		if ( ! is_array( $row ) || 'processing' !== (string) $row['status'] ) {
			return 'no_longer_processing';
		}
		if ( ! empty( $row['locked_until'] ) && (string) $row['locked_until'] >= gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) {
			return 'claim_live_again';
		}
		$spec = array( 'posts' => array(), 'meta' => array(), 'terms' => array(), 'taxonomies' => array(), 'rows' => array( array( $key, array( 'id' => $id ) ) ), 'lookup' => array(), 'parents' => array() );
		return array(
			'spec' => $spec,
			'act'  => static function () use ( $table, $id, $key ) {
				global $wpdb;
				$set = "status='pending', locked_until=NULL";
				if ( in_array( 'claim_token', Mobo_Core_Data_Health_Detectors::columns( $key ), true ) ) {
					$set .= ", claim_token=''";
				}
				return false !== $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$set} WHERE id=%d AND status='processing'", $id ) );
			},
		);
	}

	/* ------------------------------------------------------------------ */
	/* Snapshot / restore                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Read the exact state described by a spec.
	 *
	 * @param array $spec Spec.
	 * @return array|false
	 */
	public static function snapshot( array $spec ) {
		global $wpdb;
		$snap = array( 'posts' => array(), 'meta' => array(), 'terms' => array(), 'rows' => array() );
		foreach ( isset( $spec['posts'] ) ? $spec['posts'] : array() as $id ) {
			$post = self::post( $id );
			if ( '' !== (string) $wpdb->last_error ) {
				return false;
			}
			$snap['posts'][ (string) absint( $id ) ] = is_array( $post ) ? (string) $post['post_status'] : null;
		}
		foreach ( isset( $spec['meta'] ) ? $spec['meta'] : array() as $id => $keys ) {
			$keys = array_values( array_unique( array_map( 'strval', (array) $keys ) ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN (" . Mobo_Core_Data_Health_Detectors::in_strings( $keys ) . ') ORDER BY meta_id ASC', absint( $id ) ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
				return false;
			}
			$values = array();
			foreach ( $keys as $key ) {
				$values[ $key ] = array();
			}
			foreach ( $rows as $row ) {
				$values[ (string) $row['meta_key'] ][] = (string) $row['meta_value'];
			}
			$snap['meta'][ (string) absint( $id ) ] = $values;
		}
		$taxonomies = isset( $spec['taxonomies'] ) ? (array) $spec['taxonomies'] : array();
		if ( ! empty( $taxonomies ) ) {
			foreach ( isset( $spec['terms'] ) ? $spec['terms'] : array() as $id ) {
				$tt_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT tr.term_taxonomy_id FROM ' . Mobo_Core_Data_Health_Detectors::table( 'term_relationships' ) . ' tr INNER JOIN ' . Mobo_Core_Data_Health_Detectors::table( 'term_taxonomy' ) . ' tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=%d AND tt.taxonomy IN (' . Mobo_Core_Data_Health_Detectors::in_strings( $taxonomies ) . ') ORDER BY tr.term_taxonomy_id ASC', absint( $id ) ) );
				if ( '' !== (string) $wpdb->last_error ) {
					return false;
				}
				$snap['terms'][ (string) absint( $id ) ] = array_map( 'absint', $tt_ids );
			}
		}
		foreach ( isset( $spec['rows'] ) ? $spec['rows'] : array() as $i => $target ) {
			$rows = self::read_rows( $target[0], $target[1] );
			if ( false === $rows ) {
				return false;
			}
			$snap['rows'][ (string) $i ] = $rows;
		}
		return $snap;
	}

	/**
	 * Read rows of a logical table matching an exact-equality WHERE.
	 *
	 * @param string $key Table key.
	 * @param array  $where Column => value.
	 * @return array|false
	 */
	private static function read_rows( $key, array $where ) {
		global $wpdb;
		$table = Mobo_Core_Data_Health_Detectors::table( $key );
		if ( '' === $table ) {
			return false;
		}
		$conds = array();
		foreach ( $where as $col => $value ) {
			$conds[] = preg_replace( '/[^a-z0-9_]/i', '', (string) $col ) . '=' . $wpdb->prepare( is_int( $value ) ? '%d' : '%s', $value );
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $conds ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return false;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$clean = array();
			foreach ( $row as $col => $value ) {
				$clean[ (string) $col ] = null === $value ? null : (string) $value;
			}
			ksort( $clean );
			$out[] = $clean;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( json_encode( $a ), json_encode( $b ) );
			}
		);
		return $out;
	}

	/**
	 * Write a snapshot back.
	 *
	 * @param array $spec Spec.
	 * @param array $snap Snapshot.
	 * @return bool
	 */
	public static function restore( array $spec, array $snap ) {
		global $wpdb;
		$ok = true;
		foreach ( isset( $snap['posts'] ) ? $snap['posts'] : array() as $id => $status ) {
			if ( null !== $status ) {
				$ok = false !== $wpdb->update( $wpdb->posts, array( 'post_status' => (string) $status ), array( 'ID' => absint( $id ) ) ) && $ok;
			}
		}
		foreach ( isset( $snap['meta'] ) ? $snap['meta'] : array() as $id => $by_key ) {
			foreach ( (array) $by_key as $key => $values ) {
				$ok = self::replace_meta_values( absint( $id ), (string) $key, (array) $values ) && $ok;
			}
		}
		$taxonomies = isset( $spec['taxonomies'] ) ? (array) $spec['taxonomies'] : array();
		$recount    = array();
		if ( ! empty( $taxonomies ) ) {
			$tr = Mobo_Core_Data_Health_Detectors::table( 'term_relationships' );
			foreach ( isset( $snap['terms'] ) ? $snap['terms'] : array() as $id => $tt_ids ) {
				$current = $wpdb->get_col( $wpdb->prepare( "SELECT tr.term_taxonomy_id FROM {$tr} tr INNER JOIN " . Mobo_Core_Data_Health_Detectors::table( 'term_taxonomy' ) . ' tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=%d AND tt.taxonomy IN (' . Mobo_Core_Data_Health_Detectors::in_strings( $taxonomies ) . ')', absint( $id ) ) );
				$current = array_map( 'absint', (array) $current );
				$wanted  = array_map( 'absint', (array) $tt_ids );
				foreach ( array_diff( $current, $wanted ) as $tt ) {
					$ok        = false !== $wpdb->delete( $tr, array( 'object_id' => absint( $id ), 'term_taxonomy_id' => $tt ) ) && $ok;
					$recount[] = $tt;
				}
				foreach ( array_diff( $wanted, $current ) as $tt ) {
					$ok        = false !== $wpdb->insert( $tr, array( 'object_id' => absint( $id ), 'term_taxonomy_id' => $tt, 'term_order' => 0 ) ) && $ok;
					$recount[] = $tt;
				}
			}
		}
		foreach ( isset( $spec['rows'] ) ? $spec['rows'] : array() as $i => $target ) {
			$rows  = isset( $snap['rows'][ (string) $i ] ) ? (array) $snap['rows'][ (string) $i ] : array();
			$table = Mobo_Core_Data_Health_Detectors::table( $target[0] );
			$ok    = false !== $wpdb->delete( $table, $target[1] ) && $ok;
			foreach ( $rows as $row ) {
				$ok = false !== $wpdb->insert( $table, $row ) && $ok;
				if ( 'term_relationships' === $target[0] && isset( $row['term_taxonomy_id'] ) ) {
					$recount[] = absint( $row['term_taxonomy_id'] );
				}
			}
			if ( 'term_relationships' === $target[0] && isset( $target[1]['term_taxonomy_id'] ) ) {
				$recount[] = absint( $target[1]['term_taxonomy_id'] );
			}
		}
		if ( ! empty( $recount ) ) {
			self::recount_terms( $recount );
		}
		return $ok;
	}

	/**
	 * Stable JSON for compare-and-swap.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function canonical_json( $value ) {
		$sort = static function ( $v ) use ( &$sort ) {
			if ( is_array( $v ) ) {
				$assoc = array_keys( $v ) !== range( 0, count( $v ) - 1 );
				if ( $assoc ) {
					ksort( $v );
				}
				foreach ( $v as $k => $item ) {
					$v[ $k ] = $sort( $item );
				}
			} elseif ( is_int( $v ) || is_float( $v ) ) {
				$v = (string) $v;
			}
			return $v;
		};
		return (string) json_encode( $sort( $value ) );
	}

	/* ------------------------------------------------------------------ */
	/* Low-level writes                                                   */
	/* ------------------------------------------------------------------ */

	/** @return string First value of a meta key ('' when absent). */
	private static function meta_single( $id, $key ) {
		$values = self::meta_values( $id, $key );
		return isset( $values[0] ) ? (string) $values[0] : '';
	}

	/** @return string[] */
	private static function meta_values( $id, $key ) {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id ASC", absint( $id ), (string) $key ) ) );
	}

	/** @return bool Set a single-valued meta key (replaces all rows). */
	private static function set_meta( $id, $key, $value ) {
		return self::replace_meta_values( $id, $key, array( (string) $value ) );
	}

	/** @return bool */
	private static function delete_meta( $id, $key ) {
		return self::replace_meta_values( $id, $key, array() );
	}

	/**
	 * Replace every row of one meta key with $values (in order).
	 *
	 * @param int      $id Post.
	 * @param string   $key Key.
	 * @param string[] $values Values.
	 * @return bool
	 */
	private static function replace_meta_values( $id, $key, array $values ) {
		global $wpdb;
		if ( false === $wpdb->delete( $wpdb->postmeta, array( 'post_id' => absint( $id ), 'meta_key' => (string) $key ) ) ) {
			return false;
		}
		foreach ( $values as $value ) {
			if ( false === $wpdb->insert( $wpdb->postmeta, array( 'post_id' => absint( $id ), 'meta_key' => (string) $key, 'meta_value' => (string) $value ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return bool Persist WooCommerce out-of-stock props. */
	private static function set_out_of_stock( $id ) {
		return self::set_meta( $id, '_stock_status', 'outofstock' )
			&& self::set_meta( $id, '_manage_stock', 'no' )
			&& self::set_meta( $id, '_backorders', 'no' );
	}

	/**
	 * Move identity meta into the forensic marker and remove it, so the copy no
	 * longer competes for the canonical object's Source identity.
	 *
	 * @param int      $id Post.
	 * @param string[] $keys Identity keys.
	 * @param int      $canonical Canonical owner.
	 * @return bool
	 */
	private static function retire_identity( $id, array $keys, $canonical ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN (" . Mobo_Core_Data_Health_Detectors::in_strings( $keys ) . ') ORDER BY meta_id ASC', absint( $id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		$saved = array();
		foreach ( (array) $rows as $row ) {
			$saved[ (string) $row['meta_key'] ][] = (string) $row['meta_value'];
		}
		$marker = json_encode( array( 'identity' => $saved, 'canonical' => absint( $canonical ), 'at' => gmdate( 'c' ) ) );
		if ( ! self::set_meta( $id, self::MARKER_RETIRED, (string) $marker ) ) {
			return false;
		}
		foreach ( array_keys( $saved ) as $key ) {
			if ( ! self::delete_meta( $id, $key ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Add product_visibility terms by slug (missing terms are skipped).
	 *
	 * @param int      $id Object.
	 * @param string[] $slugs Slugs.
	 * @return bool
	 */
	private static function add_visibility_terms( $id, array $slugs ) {
		global $wpdb;
		$tt  = Mobo_Core_Data_Health_Detectors::table( 'term_taxonomy' );
		$t   = Mobo_Core_Data_Health_Detectors::table( 'terms' );
		$tr  = Mobo_Core_Data_Health_Detectors::table( 'term_relationships' );
		$ids = $wpdb->get_col( "SELECT tt.term_taxonomy_id FROM {$tt} tt INNER JOIN {$t} t ON t.term_id=tt.term_id WHERE tt.taxonomy='product_visibility' AND t.slug IN (" . Mobo_Core_Data_Health_Detectors::in_strings( $slugs ) . ')' );
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		$added = array();
		foreach ( array_map( 'absint', (array) $ids ) as $ttid ) {
			$exists = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tr} WHERE object_id=%d AND term_taxonomy_id=%d", absint( $id ), $ttid ) ) );
			if ( 0 === $exists ) {
				if ( false === $wpdb->insert( $tr, array( 'object_id' => absint( $id ), 'term_taxonomy_id' => $ttid, 'term_order' => 0 ) ) ) {
					return false;
				}
				$added[] = $ttid;
			}
		}
		if ( ! empty( $added ) ) {
			self::recount_terms( $added );
		}
		return true;
	}

	/**
	 * Recount term_taxonomy.count.
	 *
	 * @param int[] $tt_ids IDs.
	 * @return void
	 */
	private static function recount_terms( array $tt_ids ) {
		global $wpdb;
		$tt_ids = array_values( array_unique( array_filter( array_map( 'absint', $tt_ids ) ) ) );
		if ( empty( $tt_ids ) ) {
			return;
		}
		$tt = Mobo_Core_Data_Health_Detectors::table( 'term_taxonomy' );
		if ( function_exists( 'wp_update_term_count_now' ) ) {
			$rows = $wpdb->get_results( "SELECT term_taxonomy_id, taxonomy FROM {$tt} WHERE term_taxonomy_id IN (" . Mobo_Core_Data_Health_Detectors::in_ints( $tt_ids ) . ')', ARRAY_A );
			$by   = array();
			foreach ( (array) $rows as $row ) {
				$by[ (string) $row['taxonomy'] ][] = absint( $row['term_taxonomy_id'] );
			}
			foreach ( $by as $taxonomy => $ids ) {
				wp_update_term_count_now( $ids, $taxonomy );
			}
			return;
		}
		$tr = Mobo_Core_Data_Health_Detectors::table( 'term_relationships' );
		$wpdb->query( "UPDATE {$tt} SET count=(SELECT COUNT(*) FROM {$tr} WHERE {$tr}.term_taxonomy_id={$tt}.term_taxonomy_id) WHERE term_taxonomy_id IN (" . Mobo_Core_Data_Health_Detectors::in_ints( $tt_ids ) . ')' );
	}

	/** @return int[] Non-trash variation children. */
	private static function child_ids( $parent ) {
		global $wpdb;
		return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent=%d AND post_type='product_variation' AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC", absint( $parent ) ) ) );
	}

	/**
	 * Invalidate caches, re-sync variable parents and rebuild lookup rows after
	 * a change or a restore.
	 *
	 * @param array $spec Spec.
	 * @return void
	 */
	private static function after_change( array $spec ) {
		$touched = array_unique(
			array_merge(
				isset( $spec['posts'] ) ? (array) $spec['posts'] : array(),
				array_keys( isset( $spec['meta'] ) ? (array) $spec['meta'] : array() ),
				isset( $spec['terms'] ) ? (array) $spec['terms'] : array(),
				isset( $spec['lookup'] ) ? (array) $spec['lookup'] : array()
			)
		);
		foreach ( $touched as $id ) {
			$id = absint( $id );
			if ( $id <= 0 ) {
				continue;
			}
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $id, 'post_meta' );
				wp_cache_delete( $id, 'posts' );
				wp_cache_delete( $id, 'product_visibility_relationships' );
			}
			if ( function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( $id );
			}
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $id );
			}
		}
		foreach ( isset( $spec['parents'] ) ? (array) $spec['parents'] : array() as $parent ) {
			$parent = absint( $parent );
			if ( $parent > 0 && class_exists( 'WC_Product_Variable' ) && method_exists( 'WC_Product_Variable', 'sync' ) && function_exists( 'wc_get_product' ) ) {
				try {
					WC_Product_Variable::sync( $parent );
				} catch ( Throwable $e ) {
					/* The parent price range is rebuilt by the next save/Sync. */
					unset( $e );
				}
			}
			if ( $parent > 0 && function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $parent );
			}
		}
		if ( Mobo_Core_Data_Health_Detectors::has( 'lookup' ) ) {
			foreach ( array_merge( isset( $spec['lookup'] ) ? (array) $spec['lookup'] : array(), isset( $spec['parents'] ) ? (array) $spec['parents'] : array() ) as $id ) {
				$post = self::post( $id );
				if ( self::is_live( $post ) ) {
					self::rebuild_lookup_row( absint( $id ) );
				}
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* WooCommerce product meta lookup                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Compute the wc_product_meta_lookup row from the object's own meta, the
	 * same sources WooCommerce uses.
	 *
	 * @param int $id Product/variation.
	 * @return array|null Column => value (only existing columns).
	 */
	public static function lookup_row( $id ) {
		global $wpdb;
		$id      = absint( $id );
		$columns = Mobo_Core_Data_Health_Detectors::columns( 'lookup' );
		if ( empty( $columns ) ) {
			return null;
		}
		$keys = array( '_sku', '_global_unique_id', '_virtual', '_downloadable', '_price', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to', '_stock', '_stock_status', '_wc_rating_count', '_wc_average_rating', 'total_sales', '_tax_status', '_tax_class' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN (" . Mobo_Core_Data_Health_Detectors::in_strings( $keys ) . ') ORDER BY meta_id ASC', $id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}
		$meta = array();
		foreach ( (array) $rows as $row ) {
			$meta[ (string) $row['meta_key'] ][] = (string) $row['meta_value'];
		}
		$first  = static function ( $key, $default = '' ) use ( $meta ) {
			return isset( $meta[ $key ][0] ) ? $meta[ $key ][0] : $default;
		};
		$prices = array();
		foreach ( isset( $meta['_price'] ) ? $meta['_price'] : array() as $value ) {
			if ( '' !== trim( $value ) && is_numeric( $value ) ) {
				$prices[] = (float) $value;
			}
		}
		$children = self::child_ids( $id );
		$onsale   = self::is_on_sale( $first( '_regular_price' ), $first( '_sale_price' ), $first( '_sale_price_dates_from' ), $first( '_sale_price_dates_to' ) );
		if ( ! empty( $children ) && ! $onsale ) {
			foreach ( $children as $child ) {
				if ( 'publish' !== (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID=%d", $child ) ) ) {
					continue;
				}
				if ( self::is_on_sale( self::meta_single( $child, '_regular_price' ), self::meta_single( $child, '_sale_price' ), self::meta_single( $child, '_sale_price_dates_from' ), self::meta_single( $child, '_sale_price_dates_to' ) ) ) {
					$onsale = true;
					break;
				}
			}
		}
		$rating = $first( '_wc_rating_count' );
		$counts = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $rating ) : @unserialize( $rating ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WooCommerce stores a serialized int array.
		$stock  = $first( '_stock', '' );
		$status = $first( '_stock_status', 'instock' );
		$data   = array(
			'product_id'       => $id,
			'sku'              => $first( '_sku' ),
			'global_unique_id' => $first( '_global_unique_id' ),
			'virtual'          => 'yes' === $first( '_virtual' ) ? 1 : 0,
			'downloadable'     => 'yes' === $first( '_downloadable' ) ? 1 : 0,
			'min_price'        => empty( $prices ) ? null : min( $prices ),
			'max_price'        => empty( $prices ) ? null : max( $prices ),
			'onsale'           => $onsale ? 1 : 0,
			'stock_quantity'   => ( '' !== $stock && is_numeric( $stock ) ) ? (float) $stock : null,
			'stock_status'     => in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $status : 'instock',
			'rating_count'     => is_array( $counts ) ? array_sum( array_map( 'intval', $counts ) ) : 0,
			'average_rating'   => (float) $first( '_wc_average_rating', '0' ),
			'total_sales'      => absint( $first( 'total_sales', '0' ) ),
			'tax_status'       => '' !== $first( '_tax_status' ) ? $first( '_tax_status' ) : 'taxable',
			'tax_class'        => $first( '_tax_class' ),
		);
		$out = array();
		foreach ( $data as $col => $value ) {
			if ( in_array( $col, $columns, true ) ) {
				$out[ $col ] = $value;
			}
		}
		return $out;
	}

	/** @return bool */
	private static function is_on_sale( $regular, $sale, $from, $to ) {
		if ( '' === (string) $sale || ! is_numeric( $sale ) ) {
			return false;
		}
		if ( '' !== (string) $regular && is_numeric( $regular ) && (float) $sale >= (float) $regular ) {
			return false;
		}
		$now = time();
		if ( '' !== (string) $from && is_numeric( $from ) && (int) $from > $now ) {
			return false;
		}
		if ( '' !== (string) $to && is_numeric( $to ) && (int) $to < $now ) {
			return false;
		}
		return true;
	}

	/**
	 * Rebuild one lookup row (delete + insert).
	 *
	 * @param int $id Product/variation.
	 * @return bool
	 */
	public static function rebuild_lookup_row( $id ) {
		global $wpdb;
		$row = self::lookup_row( $id );
		if ( null === $row ) {
			return false;
		}
		$table = Mobo_Core_Data_Health_Detectors::table( 'lookup' );
		if ( false === $wpdb->delete( $table, array( 'product_id' => absint( $id ) ) ) ) {
			return false;
		}
		return false !== $wpdb->insert( $table, $row );
	}
}
