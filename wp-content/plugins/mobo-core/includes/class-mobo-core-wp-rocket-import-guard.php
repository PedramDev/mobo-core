<?php
/**
 * Backward-compatible facade for the pre-10.33.2 WP Rocket-only guard name.
 *
 * New code should use Mobo_Core_Cache_Mutation_Guard. Keeping this class avoids
 * breaking custom integrations that started using the 10.33.1 internal API.
 *
 * @package MoboCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_WP_Rocket_Import_Guard {
	public static function begin( $reason = '' ) {
		Mobo_Core_Cache_Mutation_Guard::begin( $reason );
	}

	public static function end() {
		Mobo_Core_Cache_Mutation_Guard::end();
	}

	public static function run( $callback, $reason = '' ) {
		return Mobo_Core_Cache_Mutation_Guard::run( $callback, $reason );
	}

	public static function filter_rocket_is_importing( $is_importing = false ) {
		return Mobo_Core_Cache_Mutation_Guard::filter_rocket_is_importing( $is_importing );
	}

	public static function is_active() {
		return Mobo_Core_Cache_Mutation_Guard::is_active();
	}

	public static function get_state() {
		return Mobo_Core_Cache_Mutation_Guard::get_state();
	}
}
