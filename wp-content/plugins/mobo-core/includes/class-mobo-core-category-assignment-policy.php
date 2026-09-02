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
			'default_category_id'      => absint( get_option( 'mobo_default_category_id', 0 ) ),
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
}
