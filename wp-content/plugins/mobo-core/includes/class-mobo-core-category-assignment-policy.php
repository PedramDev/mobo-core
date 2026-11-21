<?php
/**
 * Shared category-assignment configuration/fallback policy.
 *
 * Runtime assignment and Admin diagnostics must interpret the same settings and
 * the same default-term validity. Actual mapping/upsert mutations remain in
 * Mobo_Core_Category_Sync.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Category_Assignment_Policy {

	/**
	 * Return the category assignment settings as one consistent snapshot.
	 *
	 * @param bool|null $auto_categories_override Runtime snapshot, when supplied.
	 * @return array
	 */
	public static function settings( $auto_categories_override = null ) {
		$auto_categories = null === $auto_categories_override
			? Mobo_Core_Settings::enabled( 'global_update_categories', '1' )
			: (bool) $auto_categories_override;

		return array(
			'mapping_enabled'          => Mobo_Core_Settings::enabled( 'mobo_core_category_mapping_enabled', '1' ),
			'mapping_required'         => Mobo_Core_Settings::enabled( 'mobo_core_category_mapping_required', '0' ),
			'auto_categories_enabled'  => $auto_categories,
			'default_category_id'      => absint( Mobo_Core_Settings::get( 'mobo_default_category_id', 0 ) ),
		);
	}

	/**
	 * Resolve whether the configured default/fallback category is usable now.
	 *
	 * @return array status=missing|invalid|valid, configured_id, term_id.
	 */
	public static function fallback_status() {
		$settings = self::settings();
		$id       = absint( $settings['default_category_id'] );

		if ( $id <= 0 ) {
			return array(
				'status'        => 'missing',
				'configured_id' => 0,
				'term_id'       => 0,
			);
		}

		$term = term_exists( $id, 'product_cat' );
		if ( empty( $term ) || is_wp_error( $term ) ) {
			return array(
				'status'        => 'invalid',
				'configured_id' => $id,
				'term_id'       => 0,
			);
		}

		$term_id = is_array( $term ) && isset( $term['term_id'] ) ? absint( $term['term_id'] ) : absint( $term );

		return array(
			'status'        => $term_id > 0 ? 'valid' : 'invalid',
			'configured_id' => $id,
			'term_id'       => $term_id,
		);
	}

	/**
	 * Return a usable default category term ID, or 0 when none is valid.
	 *
	 * @return int
	 */
	public static function default_term_id() {
		$status = self::fallback_status();
		return 'valid' === $status['status'] ? absint( $status['term_id'] ) : 0;
	}

	/**
	 * WooCommerce "Uncategorized" (default_product_cat), or 0.
	 *
	 * @return int
	 */
	public static function uncategorized_term_id() {
		$id = absint( get_option( 'default_product_cat', 0 ) );
		if ( $id <= 0 ) {
			return 0;
		}
		$term = term_exists( $id, 'product_cat' );
		return ( empty( $term ) || is_wp_error( $term ) ) ? 0 : $id;
	}

	/**
	 * Pure decision for a product whose Source category is not (yet) resolvable.
	 *
	 * Product create/update must never wait for categories. When nothing resolves:
	 *  - an existing product that already has categories keeps them;
	 *  - a new (or category-less) product temporarily gets the merchant fallback
	 *    category, or WooCommerce Uncategorized when no fallback is configured or
	 *    when manual mapping is required (the admin policy states that the
	 *    fallback never replaces a missing required mapping);
	 *  - the product is marked pending (when a later category sync or mapping can
	 *    still change the result under the current policy) and a category sync is
	 *    requested, so the correct category is re-applied when it arrives.
	 *
	 * @param array $in is_new_product, auto_categories_enabled, mapping_enabled,
	 *                  mapping_required, resolved_count, missing_count,
	 *                  current_count, default_term_id, uncategorized_term_id.
	 * @return array action (assign_resolved|assign_fallback|keep|none), term_id,
	 *               kind (default|uncategorized|''), pending, request_category_sync, reason.
	 */
	public static function decide_missing_category_fallback( $in ) {
		$in               = is_array( $in ) ? $in : array();
		$is_new           = ! empty( $in['is_new_product'] );
		$auto             = ! empty( $in['auto_categories_enabled'] );
		$mapping_enabled  = ! empty( $in['mapping_enabled'] );
		$mapping_required = $mapping_enabled && ! empty( $in['mapping_required'] );
		$resolved         = absint( isset( $in['resolved_count'] ) ? $in['resolved_count'] : 0 );
		$missing          = absint( isset( $in['missing_count'] ) ? $in['missing_count'] : 0 );
		$current          = absint( isset( $in['current_count'] ) ? $in['current_count'] : 0 );
		$default_id       = absint( isset( $in['default_term_id'] ) ? $in['default_term_id'] : 0 );
		$uncategorized_id = absint( isset( $in['uncategorized_term_id'] ) ? $in['uncategorized_term_id'] : 0 );

		$out = array(
			'action'                => 'none',
			'term_id'               => 0,
			'kind'                  => '',
			'pending'               => false,
			'request_category_sync' => false,
			'reason'                => '',
		);

		if ( 0 === $missing ) {
			$out['action'] = $resolved > 0 ? 'assign_resolved' : 'none';
			$out['reason'] = $resolved > 0 ? 'all-resolved' : 'no-source-categories';
			return $out;
		}

		/* Something is still unresolved. Only mark pending when a later category
		 * sync (automatic mode) or a manual mapping can change the outcome. */
		$out['pending']               = $auto || $mapping_enabled;
		$out['request_category_sync'] = true;

		if ( $resolved > 0 ) {
			$out['action'] = 'assign_resolved';
			$out['reason'] = 'partially-resolved';
			return $out;
		}

		if ( ! $is_new && $current > 0 ) {
			$out['action'] = 'keep';
			$out['reason'] = 'existing-product-keeps-current';
			return $out;
		}

		if ( ! $mapping_required && $default_id > 0 ) {
			$out['kind']    = 'default';
			$out['term_id'] = $default_id;
		} elseif ( $uncategorized_id > 0 ) {
			$out['kind']    = 'uncategorized';
			$out['term_id'] = $uncategorized_id;
		}

		$out['action'] = $out['term_id'] > 0 ? 'assign_fallback' : 'none';
		$out['reason'] = $mapping_required ? 'mapping-required-temporary-uncategorized' : ( 'default' === $out['kind'] ? 'temporary-default-category' : 'temporary-uncategorized' );
		return $out;
	}
}
