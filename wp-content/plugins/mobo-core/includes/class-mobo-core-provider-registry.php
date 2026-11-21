<?php
/**
 * Internal provider registry.
 *
 * Provider selection is deliberately not customer-configurable. A provisioned
 * Connection stores provider_key supplied by the licensed .NET deployment. This
 * registry resolves only adapters compiled into Mobo Core.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Provider_Registry {
	const PORTAL = 'portal.ir';

	/** Normalize provider keys without exposing a settings surface. @return string */
	public static function normalize_key( $provider_key ) {
		return strtolower( trim( (string) $provider_key ) );
	}

	/** @return Mobo_Core_Provider_Interface|null */
	public static function get( $provider_key ) {
		$key = self::normalize_key( $provider_key );
		if ( self::PORTAL === $key ) {
			return new Mobo_Core_Provider_Portal();
		}
		return null;
	}

	/** @return bool */
	public static function exists( $provider_key ) {
		return self::get( $provider_key ) instanceof Mobo_Core_Provider_Interface;
	}

	/** Resolve a provider declared by an optional first-party Source extension. @return Mobo_Core_Provider_Interface|null */
	public static function get_for_source( $source_key ) {
		if ( ! class_exists( 'Mobo_Core_Source_Registry' ) ) { return null; }
		return self::get( Mobo_Core_Source_Registry::get_provider_key( $source_key ) );
	}

	/** Resolve the provider provisioned onto the active Connection. @return Mobo_Core_Provider_Interface|null */
	public static function get_for_runtime_connection() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) ) { return null; }
		$connection = Mobo_Core_Connection_Repository::get_runtime_connection();
		if ( ! is_array( $connection ) ) { return null; }
		return self::get( $connection['provider_key'] ?? '' );
	}
}
