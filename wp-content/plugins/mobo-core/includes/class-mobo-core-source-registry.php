<?php
/**
 * Optional first-party Source metadata registry.
 *
 * Mobo Core itself intentionally ships with no Source identity or URL. Runtime
 * routing is owned by the provisioned Connection row. This class remains only
 * as a compatibility/filter surface for first-party extensions and tests.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Source_Registry {
	/** @var array<string,array>|null */
	private static $cache = null;

	/** @return array<string,array> */
	public static function all() {
		if ( null !== self::$cache ) { return self::$cache; }
		$sources = apply_filters( 'mobo_core_source_registry', array() );
		$sources = is_array( $sources ) ? $sources : array();
		$normalized = array();
		foreach ( $sources as $candidate_key => $source ) {
			if ( ! is_array( $source ) ) { continue; }
			$key = self::normalize_key( isset( $source['key'] ) ? $source['key'] : $candidate_key );
			if ( '' === $key ) { continue; }
			$provider_key = isset( $source['provider'] ) && class_exists( 'Mobo_Core_Provider_Registry' )
				? Mobo_Core_Provider_Registry::normalize_key( $source['provider'] )
				: '';
			if ( '' === $provider_key || ! Mobo_Core_Provider_Registry::exists( $provider_key ) ) { continue; }
			$base_url = isset( $source['base_url'] ) ? self::normalize_url( $source['base_url'] ) : '';
			$site_url = isset( $source['checkout_site_url'] ) ? self::normalize_url( $source['checkout_site_url'] ) : '';
			$name = isset( $source['name'] ) ? sanitize_text_field( (string) $source['name'] ) : $key;
			$normalized[ $key ] = array_merge(
				$source,
				array(
					'key'               => $key,
					'name'              => '' !== $name ? $name : $key,
					'provider'          => $provider_key,
					'base_url'          => $base_url,
					'checkout_site_url' => $site_url,
				)
			);
		}
		self::$cache = $normalized;
		return self::$cache;
	}

	public static function get( $source_key ) {
		$key = self::normalize_key( $source_key );
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}
	public static function exists( $source_key ) { return is_array( self::get( $source_key ) ); }
	public static function get_base_url( $source_key ) {
		$source = self::get( $source_key );
		return is_array( $source ) ? (string) ( $source['base_url'] ?? '' ) : '';
	}
	public static function get_checkout_site_url( $source_key ) {
		$source = self::get( $source_key );
		return is_array( $source ) ? (string) ( $source['checkout_site_url'] ?? '' ) : '';
	}
	public static function get_provider_key( $source_key ) {
		$source = self::get( $source_key );
		return is_array( $source ) ? strtolower( trim( (string) ( $source['provider'] ?? '' ) ) ) : '';
	}
	public static function normalize_key( $source_key ) { return substr( sanitize_key( (string) $source_key ), 0, 64 ); }
	public static function normalize_url( $url ) {
		$url = trim( (string) $url );
		return '' !== $url ? trailingslashit( esc_url_raw( $url ) ) : '';
	}
	public static function reset_cache() { self::$cache = null; }
}
