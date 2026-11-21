<?php
/**
 * Strict cutover policy for large/high-churn runtime storage.
 *
 * Before the 10.33.93 database migration is fully verified, legacy heavy
 * wp_options remain available only as upgrade fallback. Once the DB version is
 * stamped at 10.33.93+, those retired options are never used as a runtime
 * read/write fallback again; table loss becomes a fail-closed storage fault.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Large_Runtime_Storage {
	const STRICT_CUTOVER_VERSION = '10.33.93';

	/** @var bool|null */
	private static $legacy_fallback_allowed = null;

	/**
	 * Legacy heavy options may be used only while the strict cutover migration
	 * has not been durably completed. The DB version is stamped only after all
	 * required table migrations and residue checks pass.
	 *
	 * @return bool
	 */
	public static function legacy_fallback_allowed() {
		if ( null !== self::$legacy_fallback_allowed ) {
			return (bool) self::$legacy_fallback_allowed;
		}
		$installed = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		self::$legacy_fallback_allowed = ( '' === $installed || version_compare( $installed, self::STRICT_CUTOVER_VERSION, '<' ) );
		return (bool) self::$legacy_fallback_allowed;
	}

	/** @return bool */
	public static function strict_cutover_complete() {
		return ! self::legacy_fallback_allowed();
	}

	/** @return void */
	public static function reset_request_cache() {
		self::$legacy_fallback_allowed = null;
	}
}
