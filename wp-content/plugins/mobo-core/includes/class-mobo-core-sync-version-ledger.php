<?php
/**
 * Per-product applied sync versions stored as WordPress post meta.
 *
 * Prevents a delayed older Portal snapshot from overwriting a newer version.
 * Equal-version pages are still accepted so multi-page variant snapshots can
 * complete before the version is committed on the last page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Sync_Version_Ledger {
	const PRODUCT_META   = '_mobo_applied_product_version';
	const VARIANTS_META  = '_mobo_applied_variant_version';
	const AGGREGATE_META = '_mobo_applied_aggregate_version';

	/**
	 * Determine whether an incoming event is older than the applied version.
	 *
	 * @param string $event Event name.
	 * @param array  $payload Normalized payload.
	 * @return bool
	 */
	public static function is_stale( $event, $payload ) {
		$metadata = self::metadata( $event, $payload );
		if ( $metadata['version'] <= 0 || '' === $metadata['productGuid'] ) {
			return false;
		}

		$product_id = self::find_product_id( $metadata['productGuid'] );
		if ( $product_id <= 0 ) {
			return false;
		}

		$meta_key = 'variants' === $metadata['component'] ? self::VARIANTS_META : self::PRODUCT_META;
		$applied  = absint( get_post_meta( $product_id, $meta_key, true ) );

		return $applied > $metadata['version'];
	}

	/**
	 * Persist the version after the component was fully applied.
	 *
	 * @param array $item Queue item.
	 * @return void
	 */
	public static function record_applied( $item ) {
		$item    = is_array( $item ) ? $item : array();
		$event   = isset( $item['event'] ) ? sanitize_text_field( (string) $item['event'] ) : '';
		$payload = isset( $item['payload'] ) && is_array( $item['payload'] ) ? $item['payload'] : array();
		$metadata = self::metadata( $event, $payload );

		if ( $metadata['version'] <= 0 || '' === $metadata['productGuid'] || ! self::is_final_page( $payload ) ) {
			return;
		}

		$product_id = self::find_product_id( $metadata['productGuid'] );
		if ( $product_id <= 0 ) {
			return;
		}

		$meta_key = 'variants' === $metadata['component'] ? self::VARIANTS_META : self::PRODUCT_META;
		$current  = absint( get_post_meta( $product_id, $meta_key, true ) );
		if ( $metadata['version'] > $current ) {
			update_post_meta( $product_id, $meta_key, $metadata['version'] );
		}

		$aggregate_current = absint( get_post_meta( $product_id, self::AGGREGATE_META, true ) );
		if ( $metadata['aggregateVersion'] > $aggregate_current ) {
			update_post_meta( $product_id, self::AGGREGATE_META, $metadata['aggregateVersion'] );
		}
	}

	/**
	 * Return a compact status for diagnostics.
	 *
	 * @return array
	 */
	public static function get_status() {
		return array(
			'enabled'        => true,
			'productMeta'    => self::PRODUCT_META,
			'variantsMeta'   => self::VARIANTS_META,
			'aggregateMeta'  => self::AGGREGATE_META,
			'staleRule'      => 'stored-version-greater-than-incoming',
		);
	}

	private static function metadata( $event, $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		$component = isset( $payload['_moboDeliveryComponent'] ) ? sanitize_key( (string) $payload['_moboDeliveryComponent'] ) : '';
		if ( '' === $component ) {
			$component = 'UpdateVariant' === $event ? 'variants' : 'product';
		}

		return array(
			'component'        => $component,
			'version'          => absint( isset( $payload['_moboEntityVersion'] ) ? $payload['_moboEntityVersion'] : ( isset( $payload['componentVersion'] ) ? $payload['componentVersion'] : 0 ) ),
			'aggregateVersion' => absint( isset( $payload['_moboAggregateVersion'] ) ? $payload['_moboAggregateVersion'] : ( isset( $payload['aggregateVersion'] ) ? $payload['aggregateVersion'] : 0 ) ),
			'productGuid'      => self::extract_product_guid( $payload ),
		);
	}

	private static function extract_product_guid( $payload ) {
		foreach ( array( 'productId', 'productGuid', 'product_guid', 'entityGuid', 'entityId' ) as $key ) {
			if ( isset( $payload[ $key ] ) && '' !== trim( (string) $payload[ $key ] ) ) {
				return sanitize_text_field( (string) $payload[ $key ] );
			}
		}

		$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			foreach ( array( 'productId', 'productGuid', 'product_guid', 'id' ) as $key ) {
				if ( isset( $data[0][ $key ] ) && '' !== trim( (string) $data[0][ $key ] ) ) {
					return sanitize_text_field( (string) $data[0][ $key ] );
				}
			}
		}

		return '';
	}

	private static function is_final_page( $payload ) {
		foreach ( array( 'isLastPage', 'is_last_page' ) as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				return self::to_bool( $payload[ $key ] );
			}
		}

		foreach ( array( 'hasMore', 'has_more' ) as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				return ! self::to_bool( $payload[ $key ] );
			}
		}

		$page_number = max( 1, absint( isset( $payload['pageNumber'] ) ? $payload['pageNumber'] : 1 ) );
		$per_page    = absint( isset( $payload['recordPerPage'] ) ? $payload['recordPerPage'] : 0 );
		$total       = absint( isset( $payload['totalCount'] ) ? $payload['totalCount'] : 0 );
		if ( $per_page > 0 ) {
			return ( $page_number * $per_page ) >= $total;
		}

		return true;
	}

	private static function find_product_id( $product_guid ) {
		global $wpdb;
		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( '' === $product_guid ) {
			return 0;
		}

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					WHERE p.post_type = 'product'
					  AND pm.meta_key = 'product_guid'
					  AND pm.meta_value = %s
					LIMIT 1",
					$product_guid
				)
			)
		);
	}

	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
