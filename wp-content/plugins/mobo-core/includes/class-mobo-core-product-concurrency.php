<?php
/**
 * Product-level concurrency and duplicate GUID helpers.
 *
 * Protects product writes from running concurrently across repair/manual sync,
 * webhook queue, reprice and recategorize workers. Also provides lightweight
 * duplicate product detection for product_guid collisions.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Product_Concurrency {

	/**
	 * Acquire a product GUID lock.
	 *
	 * Uses MySQL GET_LOCK when available because it is atomic. Falls back to the
	 * existing transient lock helper if the database does not support named locks.
	 *
	 * @param string $product_guid Product GUID.
	 * @param int    $wait_seconds Maximum wait seconds for MySQL lock.
	 * @param int    $ttl_seconds Fallback transient TTL.
	 * @return array|false Lock token array or false.
	 */
	public static function acquire_product_lock( $product_guid, $wait_seconds = 5, $ttl_seconds = 180 ) {
		global $wpdb;

		$product_guid = sanitize_text_field( (string) $product_guid );

		if ( '' === $product_guid ) {
			return false;
		}

		$wait_seconds = max( 0, min( 10, absint( $wait_seconds ) ) );
		$ttl_seconds  = max( 30, absint( $ttl_seconds ) );
		$lock_name    = self::mysql_lock_name( $product_guid );

		if ( $wpdb instanceof wpdb ) {
			$previous_suppress = $wpdb->suppress_errors( true );
			$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, $wait_seconds ) );
			$wpdb->suppress_errors( $previous_suppress );

			if ( '1' === (string) $result ) {
				return array(
					'type' => 'mysql',
					'name' => $lock_name,
				);
			}
		}

		if ( class_exists( 'Mobo_Core_Lock' ) ) {
			$fallback_name = self::fallback_lock_name( $product_guid );
			$token         = Mobo_Core_Lock::acquire( $fallback_name, $ttl_seconds );

			if ( false !== $token ) {
				return array(
					'type'  => 'transient',
					'name'  => $fallback_name,
					'token' => $token,
				);
			}
		}

		return false;
	}

	/**
	 * Release a product GUID lock.
	 *
	 * @param array|false $lock Lock token.
	 * @return void
	 */
	public static function release_product_lock( $lock ) {
		global $wpdb;

		if ( ! is_array( $lock ) || empty( $lock['type'] ) || empty( $lock['name'] ) ) {
			return;
		}

		if ( 'mysql' === $lock['type'] ) {
			if ( $wpdb instanceof wpdb ) {
				$previous_suppress = $wpdb->suppress_errors( true );
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', sanitize_text_field( (string) $lock['name'] ) ) );
				$wpdb->suppress_errors( $previous_suppress );
			}
			return;
		}

		if ( 'transient' === $lock['type'] && class_exists( 'Mobo_Core_Lock' ) && ! empty( $lock['token'] ) ) {
			Mobo_Core_Lock::release( sanitize_key( (string) $lock['name'] ), sanitize_text_field( (string) $lock['token'] ) );
		}
	}

	/**
	 * Check if manual/repair sync is currently working on this product GUID.
	 *
	 * @param string $product_guid Product GUID.
	 * @return bool
	 */
	public static function is_manual_sync_busy_for_product( $product_guid ) {
		$product_guid = sanitize_text_field( (string) $product_guid );

		if ( '' === $product_guid ) {
			return false;
		}

		$state = get_option( 'mobo_core_sync_state', array() );

		if ( ! is_array( $state ) ) {
			return false;
		}

		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : '';

		if ( ! in_array( $status, array( 'running', 'waiting_for_portal' ), true ) ) {
			return false;
		}

		$current_guid = isset( $state['currentProductGuid'] ) ? sanitize_text_field( (string) $state['currentProductGuid'] ) : '';

		if ( $current_guid !== $product_guid ) {
			return false;
		}

		$updated_at = isset( $state['updatedAt'] ) ? absint( $state['updatedAt'] ) : 0;

		return 0 === $updated_at || ( time() - $updated_at ) < HOUR_IN_SECONDS;
	}

	/**
	 * Build a standard defer result for webhook processing.
	 *
	 * @param string $product_guid Product GUID.
	 * @param string $reason Reason code.
	 * @param int    $seconds Retry delay.
	 * @return array
	 */
	public static function defer_result( $product_guid, $reason, $seconds = 30 ) {
		$product_guid = sanitize_text_field( (string) $product_guid );
		$reason       = sanitize_key( (string) $reason );
		$seconds      = max( 15, min( 300, absint( $seconds ) ) );

		return array(
			'success' => true,
			'message' => 'این محصول همزمان در مسیر دیگری در حال پردازش است؛ رویداد برای تلاش بعدی نگه داشته شد.',
			'data'    => array(
				'deleteFile'          => false,
				'deferSeconds'        => $seconds,
				'productGuid'         => $product_guid,
				'waitingForProduct'   => true,
				'waitingReason'       => $reason,
			),
		);
	}

	/**
	 * Return active product IDs that share one product_guid.
	 *
	 * @param string $product_guid Product GUID.
	 * @return int[]
	 */
	public static function get_product_ids_by_guid( $product_guid ) {
		global $wpdb;

		$product_guid = sanitize_text_field( (string) $product_guid );

		if ( '' === $product_guid ) {
			return array();
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = 'product'
				AND p.post_status IN ('publish','draft','private','pending')
				AND pm.meta_key = 'product_guid'
				AND pm.meta_value = %s
				ORDER BY p.ID ASC",
				$product_guid
			)
		);

		return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
	}

	/**
	 * Choose canonical product ID for a GUID and repair the map table if needed.
	 *
	 * @param string $product_guid Product GUID.
	 * @param int    $preferred_id Preferred product ID, usually from map table.
	 * @return int
	 */
	public static function get_canonical_product_id( $product_guid, $preferred_id = 0 ) {
		$product_guid = sanitize_text_field( (string) $product_guid );
		$preferred_id = absint( $preferred_id );

		if ( '' === $product_guid ) {
			return 0;
		}

		$ids = self::get_product_ids_by_guid( $product_guid );

		if ( empty( $ids ) ) {
			return 0;
		}

		if ( $preferred_id > 0 && in_array( $preferred_id, $ids, true ) ) {
			return $preferred_id;
		}

		$canonical = absint( $ids[0] );

		if ( $canonical > 0 && class_exists( 'Mobo_Core_Product_Map' ) ) {
			$map = new Mobo_Core_Product_Map();
			$map->upsert_product( $product_guid, $canonical, '', false );
		}

		return $canonical;
	}

	/**
	 * Check whether a product is an active duplicate but not canonical.
	 *
	 * @param int $post_id Product ID.
	 * @return bool
	 */
	public static function is_non_canonical_product( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return false;
		}

		$product_guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );

		if ( '' === $product_guid ) {
			return false;
		}

		$canonical = self::get_canonical_product_id( $product_guid, 0 );

		return $canonical > 0 && $canonical !== $post_id;
	}

	/**
	 * Count product_guid groups with more than one active product.
	 *
	 * @return int
	 */
	public static function count_duplicate_product_groups() {
		global $wpdb;

		$sql = "SELECT COUNT(*) FROM (
			SELECT pm.meta_value
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE p.post_type = 'product'
			AND p.post_status IN ('publish','draft','private','pending')
			AND pm.meta_key = 'product_guid'
			AND pm.meta_value <> ''
			GROUP BY pm.meta_value
			HAVING COUNT(DISTINCT p.ID) > 1
		) x";

		return absint( $wpdb->get_var( $sql ) );
	}

	/**
	 * Return a few duplicate groups for admin diagnostics.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function get_duplicate_product_groups( $limit = 10 ) {
		global $wpdb;

		$limit = max( 1, min( 50, absint( $limit ) ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS product_guid, COUNT(DISTINCT p.ID) AS product_count, GROUP_CONCAT(DISTINCT p.ID ORDER BY p.ID ASC) AS ids
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = 'product'
				AND p.post_status IN ('publish','draft','private','pending')
				AND pm.meta_key = 'product_guid'
				AND pm.meta_value <> ''
				GROUP BY pm.meta_value
				HAVING COUNT(DISTINCT p.ID) > 1
				ORDER BY MAX(p.ID) DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}


	/**
	 * Move non-canonical duplicate products to draft so they stop showing in shop.
	 *
	 * This is intentionally reversible. It does not delete products, variations,
	 * images or metadata.
	 *
	 * @param int $limit_groups Max duplicate GUID groups to process.
	 * @return array
	 */
	public static function quarantine_duplicate_products( $limit_groups = 50 ) {
		$groups = self::get_duplicate_product_groups( $limit_groups );
		$result = array(
			'groups'      => 0,
			'quarantined' => 0,
			'skipped'     => 0,
			'items'       => array(),
		);

		if ( empty( $groups ) ) {
			return $result;
		}

		$map = class_exists( 'Mobo_Core_Product_Map' ) ? new Mobo_Core_Product_Map() : null;

		foreach ( $groups as $group ) {
			$product_guid = isset( $group['product_guid'] ) ? sanitize_text_field( (string) $group['product_guid'] ) : '';

			if ( '' === $product_guid ) {
				continue;
			}

			$preferred_id = $map instanceof Mobo_Core_Product_Map ? absint( $map->get_product_id( $product_guid ) ) : 0;
			$canonical_id = self::get_canonical_product_id( $product_guid, $preferred_id );
			$ids          = self::get_product_ids_by_guid( $product_guid );

			if ( $canonical_id <= 0 || empty( $ids ) ) {
				$result['skipped']++;
				continue;
			}

			$result['groups']++;

			foreach ( $ids as $product_id ) {
				$product_id = absint( $product_id );

				if ( $product_id <= 0 || $product_id === $canonical_id ) {
					continue;
				}

				$post = get_post( $product_id );

				if ( ! ( $post instanceof WP_Post ) || 'product' !== $post->post_type ) {
					$result['skipped']++;
					continue;
				}

				$updated = wp_update_post(
					array(
						'ID'          => $product_id,
						'post_status' => 'draft',
					),
					true
				);

				if ( is_wp_error( $updated ) ) {
					$result['skipped']++;
					continue;
				}

				update_post_meta( $product_id, '_mobo_duplicate_quarantined', '1' );
				update_post_meta( $product_id, '_mobo_duplicate_canonical_product_id', $canonical_id );
				update_post_meta( $product_id, '_mobo_duplicate_quarantined_at', gmdate( 'c' ) );

				$result['quarantined']++;
				$result['items'][] = array(
					'productGuid' => $product_guid,
					'keptId'      => $canonical_id,
					'draftId'     => $product_id,
				);
			}
		}

		return $result;
	}

	/**
	 * Build MySQL lock name within MySQL's 64-byte lock name practical limit.
	 *
	 * @param string $product_guid Product GUID.
	 * @return string
	 */
	private static function mysql_lock_name( $product_guid ) {
		global $wpdb;

		$prefix = isset( $wpdb->prefix ) ? sanitize_key( (string) $wpdb->prefix ) : 'wp_';

		return substr( 'mobo:' . $prefix . ':product:' . md5( sanitize_text_field( (string) $product_guid ) ), 0, 64 );
	}

	/**
	 * Fallback transient lock name.
	 *
	 * @param string $product_guid Product GUID.
	 * @return string
	 */
	private static function fallback_lock_name( $product_guid ) {
		return 'product_' . md5( sanitize_text_field( (string) $product_guid ) );
	}
}
