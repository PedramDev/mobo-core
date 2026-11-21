<?php
/**
 * Internal storage rollout feature flag.
 *
 * Phase 8 keeps guarded custom-primary identity and exact operational-state reads.
 * Custom-only readiness is honored in 10.33.81 only through its independent short-lived
 * runtime lease. If that lease is absent or revoked, requested Custom-only degrades to
 * guarded custom-primary (and then to shadow-read if the Promotion Gate is unavailable).
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Storage_Mode {

	/** @var array<int,string> Request-local effective mode cache by durable Connection. */
	private static $effective_cache = array();

	const OPTION = 'mobo_core_storage_mode';

	const LEGACY         = 'legacy';
	const DUAL_WRITE     = 'dual-write';
	const SHADOW_READ    = 'shadow-read';
	const CUSTOM_PRIMARY = 'custom-primary';
	const CUSTOM_ONLY    = 'custom-only';

	/** Highest runtime mode implemented by this release. */
	const MAX_EFFECTIVE_MODE = self::CUSTOM_ONLY;

	/**
	 * Supported rollout modes in promotion order.
	 *
	 * @return array
	 */
	public static function supported_modes() {
		return array(
			self::LEGACY,
			self::DUAL_WRITE,
			self::SHADOW_READ,
			self::CUSTOM_PRIMARY,
			self::CUSTOM_ONLY,
		);
	}

	/**
	 * Normalize an untrusted or persisted mode. Unknown values fail closed.
	 *
	 * @param mixed $mode Candidate mode.
	 * @return string
	 */
	public static function normalize( $mode ) {
		$mode = sanitize_key( (string) $mode );
		return in_array( $mode, self::supported_modes(), true ) ? $mode : self::LEGACY;
	}

	/**
	 * Ensure the rollout option exists without autoloading it on storefront reads.
	 * Existing values are never overwritten.
	 *
	 * @return void
	 */
	public static function ensure_default() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::LEGACY, '', false );
		}
	}

	/**
	 * Return the explicitly requested rollout mode, normalized fail-closed.
	 *
	 * @return string
	 */
	public static function requested() {
		return self::normalize( get_option( self::OPTION, self::LEGACY ) );
	}

	/**
	 * Return the mode runtime code is allowed to use.
	 *
	 * A non-legacy request is never honored unless the independent custom-storage
	 * schema is healthy. This prevents a partially-created table set from changing
	 * existing Mobo behavior.
	 *
	 * @return string
	 */
	public static function effective() {
		$connection_id = self::connection_id();
		if ( isset( self::$effective_cache[ $connection_id ] ) ) { return self::$effective_cache[ $connection_id ]; }
		$requested = self::requested();
		if ( self::LEGACY === $requested ) {
			return self::$effective_cache[ $connection_id ] = self::LEGACY;
		}

		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return self::$effective_cache[ $connection_id ] = self::LEGACY;
		}

		/* 10.33.94 terminal operational-meta retirement is irreversible. Once the
		 * durable fence is published, Custom Storage is authoritative without any
		 * rollout lease; degrading to Legacy would reference physically retired rows. */
		if ( class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' ) && Mobo_Core_Storage_Operational_Meta_Retirement::is_fenced() ) {
			return self::$effective_cache[ $connection_id ] = self::CUSTOM_ONLY;
		}

		/* Custom-only is a leased capability, never a persisted assumption before terminal retirement. */
		if ( self::CUSTOM_ONLY === $requested ) {
			if ( class_exists( 'Mobo_Core_Storage_Custom_Only_Gate' ) && Mobo_Core_Storage_Custom_Only_Gate::is_runtime_eligible() ) {
				return self::$effective_cache[ $connection_id ] = self::CUSTOM_ONLY;
			}
			$requested = self::CUSTOM_PRIMARY;
		}

		if ( self::CUSTOM_PRIMARY === $requested ) {
			if ( ! class_exists( 'Mobo_Core_Storage_Promotion_Gate' ) || ! Mobo_Core_Storage_Promotion_Gate::is_runtime_eligible() ) {
				return self::$effective_cache[ $connection_id ] = self::SHADOW_READ;
			}
			return self::$effective_cache[ $connection_id ] = self::CUSTOM_PRIMARY;
		}

		return self::$effective_cache[ $connection_id ] = $requested;
	}

	/** Active durable Connection id for request-local cache isolation. @return int */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** Clear request-local mode memoization after tests/manual option changes. @return void */
	public static function reset_request_cache() { self::$effective_cache = array(); }

	/** @return string */
	public static function rollout_phase() {
		return self::MAX_EFFECTIVE_MODE;
	}

	/** @return bool */
	public static function writes_custom() {
		return in_array(
			self::effective(),
			array( self::DUAL_WRITE, self::SHADOW_READ, self::CUSTOM_PRIMARY, self::CUSTOM_ONLY ),
			true
		);
	}

	/** @return bool */
	public static function reads_custom_primary() {
		return in_array( self::effective(), array( self::CUSTOM_PRIMARY, self::CUSTOM_ONLY ), true );
	}

	/** Whether this request holds the stronger Custom-only lease. @return bool */
	public static function reads_custom_only() {
		return self::CUSTOM_ONLY === self::effective();
	}

	/** @return bool */
	public static function compares_shadow_reads() {
		return self::SHADOW_READ === self::effective();
	}
}
