<?php
/**
 * Durable Mobo backend connections.
 *
 * Multi-Connection foundation rules:
 * - Existing single-connection installs are adopted as one Default Connection.
 * - Legacy credential fields remain transitional inputs for Default Connection metadata; business settings do not mirror wp_options.
 * - WebHookId is the cross-system discriminator; it is not authentication.
 * - X-SEC remains the connection credential.
 * - An unbound Connection may adopt its Backend WebHookId only when X-SEC
 *   uniquely identifies exactly one enabled unbound Connection.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Connection_Repository {
	const DEFAULT_NAME = 'Default Connection';

	/**
	 * Backend URL that 10.33.x defined as the MOBO_API_BASE_URL default.
	 *
	 * Effective Backend URL precedence (see resolve_api_base_url()):
	 * 1. MOBO_API_BASE_URL constant (wp-config / environment override; always wins at runtime).
	 * 2. The Connection row's api_base_url (server-provisioned, or adopted below).
	 * 3./4. Only while adopting the legacy single connection as the Default
	 *    Connection during a pre-10.34.12 upgrade: the MOBO_API_BASE_URL
	 *    wp-config value, else this legacy default (the legacy option is
	 *    ignored: 10.33.x never used it at runtime). These are
	 *    written once into the Default row so upgraded sites keep their Backend
	 *    without waiting for provisioning. New/extra Connections never inherit them.
	 */
	const LEGACY_DEFAULT_API_BASE_URL = 'http://mobo.codeya.ir/';

	/** First release whose migration adopts the legacy single connection. */
	const LEGACY_ADOPTION_BEFORE_VERSION = '10.34.12';

	/** @var array<int,array>|null */
	private static $row_cache = array();
	/** @var bool|null Request-local positive table-existence cache. */
	private static $table_exists_cache = null;
	/** @var bool Whether the enabled Default Connection cache has been loaded. */
	private static $default_cache_loaded = false;
	/** @var array|null Request-local enabled Default Connection row. */
	private static $default_cache = null;

	public static function table_name() {
		global $wpdb;
		if ( class_exists( 'Mobo_Core_Storage_Schema' ) ) {
			$table = Mobo_Core_Storage_Schema::table_name( 'connections' );
			if ( '' !== $table ) { return $table; }
		}
		return $wpdb->prefix . 'mobo_connections';
	}

	public static function table_exists() {
		global $wpdb;
		if ( true === self::$table_exists_cache ) { return true; }
		$table = self::table_name();
		if ( '' === $table ) { return false; }
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$exists = (string) $found === (string) $table;
		/* Cache only a positive result. A table may be created later in the same
		 * upgrade request, so a transient negative probe must remain recoverable. */
		if ( $exists ) { self::$table_exists_cache = true; }
		return $exists;
	}

	/** Clear request-local Connection lookup caches after an in-request mutation. */
	private static function invalidate_request_cache( $connection_id = 0 ) {
		$id = absint( $connection_id );
		if ( $id > 0 ) { unset( self::$row_cache[ $id ] ); }
		else { self::$row_cache = array(); }
		self::$default_cache_loaded = false;
		self::$default_cache = null;
	}

	/** Tests/manual tooling only. */
	public static function reset_request_cache() {
		self::$row_cache = array();
		self::$table_exists_cache = null;
		self::$default_cache_loaded = false;
		self::$default_cache = null;
	}

	/**
	 * Create/adopt the single legacy connection idempotently.
	 *
	 * Legacy credential metadata is adopted conservatively for the Default
	 * Connection. Source/business settings are handled separately by the terminal
	 * Connection Settings migration and are removed from wp_options after adoption.
	 *
	 * @return int Default connection id, or 0 on failure.
	 */
	public static function ensure_default_connection() {
		global $wpdb;
		if ( ! self::table_exists() ) { return 0; }

		$table = self::table_name();
		$row   = $wpdb->get_row( "SELECT * FROM {$table} WHERE is_default = 1 ORDER BY id ASC LIMIT 1", ARRAY_A );
		if ( is_array( $row ) && absint( $row['id'] ?? 0 ) > 0 ) {
			$id = absint( $row['id'] );
			if ( ! self::ensure_default_source_key( $id, $row ) ) { return 0; }
			self::sync_default_from_legacy_options( $id, false );
			self::adopt_legacy_api_base_url( $id, $row );
			return $id;
		}

		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			$table,
			array(
				'connection_key' => wp_generate_uuid4(),
				'source_key'     => '',
				'provider_key'   => '',
				'display_name'   => self::DEFAULT_NAME,
				'webhook_id'     => null,
				/* Upgraded 10.33.x sites keep their legacy Backend immediately;
				 * fresh installs stay empty until server provisioning. */
				'api_base_url'   => self::legacy_adoption_api_base_url(),
				'source_site_url'=> '',
				'license_token'  => trim( (string) get_option( 'mobo_core_token', '' ) ),
				'security_code'  => self::legacy_security_code(),
				'is_default'     => 1,
				'is_enabled'     => 1,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) { return 0; }
		$id = absint( $wpdb->insert_id );
		self::invalidate_request_cache();
		return $id;
	}

	/**
	 * Mirror legacy single-connection settings into Default Connection.
	 * Empty legacy values intentionally overwrite the mirrored row when an admin
	 * explicitly clears a credential in the current UI.
	 *
	 * @param int  $connection_id Optional known default id.
	 * @param bool $force Always update row values.
	 * @return bool
	 */
	public static function sync_default_from_legacy_options( $connection_id = 0, $force = true ) {
		global $wpdb;
		if ( ! self::table_exists() ) { return false; }
		$id = absint( $connection_id );
		if ( $id <= 0 ) {
			$id = absint( $wpdb->get_var( 'SELECT id FROM ' . self::table_name() . ' WHERE is_default = 1 ORDER BY id ASC LIMIT 1' ) );
		}
		if ( $id <= 0 ) { return false; }

		$current = self::get_by_id( $id );
		if ( ! is_array( $current ) ) { return false; }

		$values = array(
			'license_token' => trim( (string) get_option( 'mobo_core_token', '' ) ),
			'security_code' => self::legacy_security_code(),
		);
		$changed = false;
		foreach ( $values as $key => $value ) {
			if ( $force || '' === (string) ( $current[ $key ] ?? '' ) ) {
				if ( (string) ( $current[ $key ] ?? '' ) !== (string) $value ) { $changed = true; }
				$current[ $key ] = $value;
			}
		}
		if ( ! $changed ) { return true; }

		$updated = $wpdb->update(
			self::table_name(),
			array(
				'license_token' => (string) $current['license_token'],
				'security_code' => (string) $current['security_code'],
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) { return false; }
		self::invalidate_request_cache( $id );
		return true;
	}

	public static function get_by_id( $connection_id ) {
		global $wpdb;
		$id = absint( $connection_id );
		if ( $id <= 0 || ! self::table_exists() ) { return null; }
		if ( array_key_exists( $id, self::$row_cache ) ) { return self::$row_cache[ $id ]; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', $id ), ARRAY_A );
		self::$row_cache[ $id ] = is_array( $row ) ? $row : null;
		return self::$row_cache[ $id ];
	}

	/**
	 * Uncached durable Connection read for irreversible order boundaries.
	 * Do not poison the request-local cache: callers compare this fresh identity
	 * against the routing snapshot they originally admitted for their attempt.
	 * A missing row or failed query must never fall back to Default.
	 *
	 * @param int $connection_id Connection owner.
	 * @return array|null
	 */
	public static function get_by_id_fresh( $connection_id ) {
		global $wpdb;
		$id = absint( $connection_id );
		if ( $id <= 0 || ! self::table_exists() ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Re-read owner availability immediately before an external side effect.
	 * Unlike get_by_id(), this intentionally bypasses the request-local row
	 * cache: another PHP request may have disabled this Source after a worker
	 * originally selected or claimed its webhook.
	 *
	 * @param int $connection_id Durable Connection id.
	 * @return bool
	 */
	public static function is_enabled_owner_fresh( $connection_id ) {
		global $wpdb;
		$id = absint( $connection_id );
		if ( $id <= 0 || ! self::table_exists() ) {
			return false;
		}
		$enabled = $wpdb->get_var(
			$wpdb->prepare( 'SELECT is_enabled FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', $id )
		);
		return 1 === absint( $enabled );
	}

	public static function get_default() {
		global $wpdb;
		if ( self::$default_cache_loaded ) { return self::$default_cache; }
		if ( ! self::table_exists() ) { return null; }
		$id = absint( $wpdb->get_var( 'SELECT id FROM ' . self::table_name() . ' WHERE is_default = 1 AND is_enabled = 1 ORDER BY id ASC LIMIT 1' ) );
		self::$default_cache = $id > 0 ? self::get_by_id( $id ) : null;
		self::$default_cache_loaded = true;
		return self::$default_cache;
	}

	/** Return the Default Connection for administration even when disabled. @return array|null */
	public static function get_default_any() {
		global $wpdb;
		if ( ! self::table_exists() ) { return null; }
		$id = absint( $wpdb->get_var( 'SELECT id FROM ' . self::table_name() . ' WHERE is_default = 1 ORDER BY id ASC LIMIT 1' ) );
		return $id > 0 ? self::get_by_id( $id ) : null;
	}

	public static function get_by_webhook_id( $webhook_id ) {
		global $wpdb;
		$webhook_id = self::normalize_webhook_id( $webhook_id );
		if ( '' === $webhook_id || ! self::table_exists() ) { return null; }
		/* Authorization must not use a cached row from before a second PHP request
		 * disabled this owner or rotated its X-SEC/WebHookId. One fresh read also
		 * avoids a second query between id lookup and credential verification. */
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE BINARY webhook_id = BINARY %s AND is_enabled = 1 LIMIT 1', $webhook_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Read the enabled Default without the request-local administration cache. */
	public static function get_default_fresh() {
		global $wpdb;
		if ( ! self::table_exists() ) { return null; }
		$row = $wpdb->get_row( 'SELECT * FROM ' . self::table_name() . ' WHERE is_default = 1 AND is_enabled = 1 ORDER BY id ASC LIMIT 1', ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function count_enabled() {
		global $wpdb;
		if ( ! self::table_exists() ) { return 0; }
		return absint( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE is_enabled = 1' ) );
	}


	/** @return array<int,array> */
	public static function list_enabled() {
		global $wpdb;
		if ( ! self::table_exists() ) { return array(); }
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . ' WHERE is_enabled = 1 ORDER BY is_default DESC, id ASC', ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,array> */
	public static function list_all() {
		global $wpdb;
		if ( ! self::table_exists() ) { return array(); }
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . ' ORDER BY is_default DESC, id ASC', ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Installation-global Legacy physical retirement may be newly activated only
	 * while the durable topology still consists solely of the enabled Default
	 * Connection. Once another durable Connection exists, ownership must remain
	 * reversible and no one Connection may authorize cleanup for another.
	 *
	 * Existing terminal fences are handled by their owning retirement classes;
	 * this guard controls only publication of a new irreversible fence.
	 *
	 * @return bool
	 */
	public static function legacy_terminal_retirement_topology_safe() {
		if ( ! self::table_exists() ) { return true; }
		$rows = self::list_all();
		$default = self::get_default();
		if ( 1 !== count( $rows ) || ! is_array( $default ) ) { return false; }
		$only = $rows[0];
		return absint( $only['id'] ?? 0 ) > 0
			&& absint( $only['id'] ?? 0 ) === absint( $default['id'] ?? 0 )
			&& 1 === absint( $only['is_default'] ?? 0 )
			&& 1 === absint( $only['is_enabled'] ?? 0 );
	}

	/**
	 * Multi-Connection is fail-closed until the ownership migration publishes its
	 * durable proof. Single-Connection installs do not depend on this gate.
	 *
	 * @return bool
	 */
	public static function connection_ownership_ready() {
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		if ( (string) Mobo_Core_Storage_Schema::VERSION !== (string) get_option( Mobo_Core_Storage_Schema::VERSION_OPTION, '' ) ) { return false; }
		if ( absint( get_option( 'mobo_core_103414_connection_ownership_ready', 0 ) ) <= 0 ) { return false; }
		$default = self::get_default();
		$default_id = is_array( $default ) ? absint( $default['id'] ?? 0 ) : 0;
		return $default_id > 0 && $default_id === absint( get_option( 'mobo_core_103414_connection_ownership_default_id', 0 ) );
	}

	public static function multi_connection_runtime_ready() {
		if ( self::count_enabled() <= 1 ) { return true; }
		return self::connection_ownership_ready();
	}


	/** Return the Connection whose stored license token exactly matches. @return array|null */
	public static function get_by_license_token( $license_token ) {
		global $wpdb;
		$token = Mobo_Core_Settings::normalize_opaque_secret( $license_token, 191 );
		if ( '' === $token || ! self::table_exists() ) { return null; }
		/* Signed provisioning must never authorize a rotated Token via the
		 * request-local row cache. Tokens are indexed, not UNIQUE: if legacy
		 * data has two binary-exact owners, fail closed. */
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table_name() . ' WHERE BINARY license_token = BINARY %s ORDER BY is_default DESC, id ASC LIMIT 2',
			$token
		), ARRAY_A );
		return is_array( $rows ) && 1 === count( $rows ) && is_array( $rows[0] ) ? $rows[0] : null;
	}

	/** Create an unprovisioned Connection that knows only its Token. @return int|WP_Error */
	public static function create_pending_connection( $license_token, $display_name = '' ) {
		global $wpdb;
		if ( ! self::table_exists() ) { return new WP_Error( 'mobo_core_connection_storage_missing', 'Connection storage is unavailable.' ); }
		$token = Mobo_Core_Settings::normalize_opaque_secret( $license_token, 191 );
		if ( '' === $token ) { return new WP_Error( 'mobo_core_license_token_required', 'License Token is required.' ); }
		if ( is_array( self::get_by_license_token( $token ) ) ) { return new WP_Error( 'mobo_core_license_token_exists', 'This Token is already assigned to a Connection.' ); }
		$name = sanitize_text_field( (string) $display_name );
		if ( '' === $name ) { $name = 'Pending Connection'; }
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'connection_key' => wp_generate_uuid4(),
				'source_key' => '',
				'provider_key' => '',
				'display_name' => $name,
				'webhook_id' => null,
				'api_base_url' => '',
				'source_site_url' => '',
				'license_token' => $token,
				'security_code' => '',
				'is_default' => 0,
				'is_enabled' => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s' )
		);
		if ( false === $inserted ) { return new WP_Error( 'mobo_core_connection_create_failed', 'Connection could not be created.' ); }
		$id = absint( $wpdb->insert_id );

		/* A newly-created Connection has no historical content-ownership policy. Use
		 * the safer defaults from day one: do not overwrite a store-managed title or
		 * slug unless the administrator explicitly enables those settings later. */
		if ( class_exists( 'Mobo_Core_Connection_Settings' ) && Mobo_Core_Connection_Settings::table_exists() ) {
			/* B1: initialize EVERY policy setting explicitly (stock/price/compare/
			 * image/category/order switches from the documented defaults), not only
			 * title/slug. Admin tabs and runtime then read the same stored values,
			 * so saving a tab without changes cannot silently switch updates off. */
			$seeded = method_exists( 'Mobo_Core_Connection_Settings', 'seed_policy_defaults' )
				? Mobo_Core_Connection_Settings::seed_policy_defaults( $id, array( 'global_product_auto_title' => '0', 'global_product_auto_slug' => '0' ) )
				: ( Mobo_Core_Connection_Settings::set( $id, 'global_product_auto_title', '0' ) && Mobo_Core_Connection_Settings::set( $id, 'global_product_auto_slug', '0' ) );
			$title_seeded = $seeded;
			$slug_seeded  = $seeded;
			if ( ! $title_seeded || ! $slug_seeded ) {
				Mobo_Core_Connection_Settings::delete_all_for_connection( $id );
				$wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
				self::invalidate_request_cache( $id );
				return new WP_Error( 'mobo_core_connection_defaults_failed', 'Connection defaults could not be initialized safely.' );
			}
		}

		self::invalidate_request_cache();
		return $id;
	}

	/**
	 * Remove a Connection that is still waiting for provisioning.
	 *
	 * Only non-default, disabled and unprovisioned rows are eligible. This keeps
	 * runtime-owned Connections and any durable Source binding fail-closed.
	 *
	 * @param int $connection_id Connection id.
	 * @return true|WP_Error
	 */
	public static function delete_pending_connection( $connection_id ) {
		global $wpdb;
		$id = absint( $connection_id );
		$row = self::get_by_id( $id );
		if ( $id <= 0 || ! is_array( $row ) ) {
			return new WP_Error( 'mobo_core_connection_missing', 'Connection not found.' );
		}
		if ( ! empty( $row['is_default'] ) ) {
			return new WP_Error( 'mobo_core_default_delete_forbidden', 'Default Connection cannot be removed.' );
		}
		if ( ! empty( $row['is_enabled'] ) ) {
			return new WP_Error( 'mobo_core_enabled_delete_forbidden', 'Enabled Connection cannot be removed.' );
		}
		if ( self::is_provisioned( $row ) ) {
			return new WP_Error( 'mobo_core_provisioned_delete_forbidden', 'Provisioned Connection cannot be removed from the pending flow.' );
		}

		$deleted = $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			return new WP_Error( 'mobo_core_connection_delete_failed', 'Pending Connection could not be removed.' );
		}
		if ( class_exists( 'Mobo_Core_Connection_Settings' ) && Mobo_Core_Connection_Settings::table_exists() ) {
			Mobo_Core_Connection_Settings::delete_all_for_connection( $id );
		}
		self::invalidate_request_cache( $id );
		return true;
	}

	/** Whether a row has complete server-owned routing metadata. @return bool */
	public static function is_provisioned( $row ) {
		if ( ! is_array( $row ) ) { return false; }
		$provider = class_exists( 'Mobo_Core_Provider_Registry' ) ? Mobo_Core_Provider_Registry::normalize_key( $row['provider_key'] ?? '' ) : '';
		return '' !== sanitize_key( (string) ( $row['source_key'] ?? '' ) )
			&& '' !== $provider
			&& class_exists( 'Mobo_Core_Provider_Registry' )
			&& Mobo_Core_Provider_Registry::exists( $provider )
			&& '' !== trim( (string) ( $row['api_base_url'] ?? '' ) )
			&& '' !== trim( (string) ( $row['source_site_url'] ?? '' ) );
	}

	/**
	 * Apply an idempotent server-owned provisioning contract to the Connection
	 * selected by Token. Ordinary provisioning can refresh URLs/name/status but
	 * cannot silently rebind an existing Connection to another Source or Provider.
	 *
	 * api_base_url policy (plain http stays allowed by owner decision):
	 * - private/loopback/link-local/reserved IP literals, local-only hostnames
	 *   and hostnames resolving to such addresses are rejected unless local
	 *   development is enabled (WP_DEBUG, MOBO_CORE_ALLOW_LOCAL_BACKEND or a
	 *   local/development environment type);
	 * - an already-provisioned Connection may change scheme/port/path freely but
	 *   may move to a different host only when the request additionally proves
	 *   the Connection's X-SEC (options['security_code']). The Token HMAC alone
	 *   is not enough because the Token is a customer-visible value.
	 *
	 * @param string $license_token License Token.
	 * @param array  $payload       Provisioning payload.
	 * @param array  $options       Optional: security_code (X-SEC header value).
	 * @return array|WP_Error Updated row.
	 */
	public static function provision_by_token( $license_token, $payload, $options = array() ) {
		global $wpdb;
		/* Provisioning is a durable routing mutation; do not admit new work
		 * while an upgrade/Unicode operation is draining the installation. */
		if ( ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::should_block_lock( 'connection_provisioning' ) )
			|| ( class_exists( 'Mobo_Core_Database_Unicode_Migration' ) && Mobo_Core_Database_Unicode_Migration::should_block_runtime_lock( 'connection_provisioning' ) ) ) {
			return new WP_Error( 'mobo_core_connection_maintenance_busy', 'Connection provisioning is blocked by maintenance.', array( 'status' => 503 ) );
		}
		$token = Mobo_Core_Settings::normalize_opaque_secret( $license_token, 191 );
		$row = self::get_by_license_token( $token );
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'mobo_core_provision_token_unknown', 'No Connection is waiting for this Token.', array( 'status' => 404 ) );
		}

		$payload = is_array( $payload ) ? $payload : array();
		$source_key = class_exists( 'Mobo_Core_Source_Registry' )
			? Mobo_Core_Source_Registry::normalize_key( $payload['source_key'] ?? '' )
			: substr( sanitize_key( (string) ( $payload['source_key'] ?? '' ) ), 0, 64 );
		$provider_key = class_exists( 'Mobo_Core_Provider_Registry' )
			? Mobo_Core_Provider_Registry::normalize_key( $payload['provider_key'] ?? '' )
			: strtolower( trim( (string) ( $payload['provider_key'] ?? '' ) ) );
		$api_base_url = class_exists( 'Mobo_Core_Source_Registry' )
			? Mobo_Core_Source_Registry::normalize_url( $payload['api_base_url'] ?? '' )
			: trailingslashit( esc_url_raw( (string) ( $payload['api_base_url'] ?? '' ) ) );
		$source_site_url = class_exists( 'Mobo_Core_Source_Registry' )
			? Mobo_Core_Source_Registry::normalize_url( $payload['source_site_url'] ?? '' )
			: trailingslashit( esc_url_raw( (string) ( $payload['source_site_url'] ?? '' ) ) );
		$display_name = sanitize_text_field( (string) ( $payload['source_display_name'] ?? '' ) );
		$enabled = ! array_key_exists( 'enabled', $payload ) || ! empty( $payload['enabled'] );

		$money_unit = class_exists( 'Mobo_Core_Money_Policy' )
			? Mobo_Core_Money_Policy::normalize_unit( $payload['money_unit'] ?? '' ) : '';
		$money_fraction_digits = class_exists( 'Mobo_Core_Money_Policy' )
			? Mobo_Core_Money_Policy::normalize_fraction_digits( $payload['money_fraction_digits'] ?? -1 ) : -1;
		$money_price_representation = class_exists( 'Mobo_Core_Money_Policy' )
			? Mobo_Core_Money_Policy::normalize_representation( $payload['money_price_representation'] ?? '' ) : '';
		$money_contract_version = class_exists( 'Mobo_Core_Money_Policy' )
			? Mobo_Core_Money_Policy::normalize_contract_version( $payload['money_contract_version'] ?? 0 ) : 0;

		if ( '' === $source_key || '' === $provider_key || '' === $api_base_url || '' === $source_site_url ) {
			return new WP_Error( 'mobo_core_provision_payload_invalid', 'Provisioning payload is incomplete.', array( 'status' => 400 ) );
		}
		$url_check = self::validate_backend_base_url( $api_base_url );
		if ( is_wp_error( $url_check ) ) {
			return new WP_Error( $url_check->get_error_code(), $url_check->get_error_message(), array( 'status' => 422 ) );
		}
		$api_base_url = $url_check;
		if ( self::is_provisioned( $row )
			&& ! self::same_url_host( (string) ( $row['api_base_url'] ?? '' ), $api_base_url ) ) {
			$options = is_array( $options ) ? $options : array();
			$proof = isset( $options['security_code'] ) ? (string) $options['security_code'] : '';
			if ( ! self::security_matches( self::security_code_for_row( $row ), $proof ) ) {
				return new WP_Error(
					'mobo_core_provision_api_host_change_forbidden',
					'A provisioned Connection cannot move to another Backend host without its X-SEC.',
					array( 'status' => 409 )
				);
			}
		}
		if ( ! class_exists( 'Mobo_Core_Provider_Registry' ) || ! Mobo_Core_Provider_Registry::exists( $provider_key ) ) {
			return new WP_Error( 'mobo_core_provision_provider_unknown', 'Provider is not supported by this plugin build.', array( 'status' => 422 ) );
		}
		if ( ! class_exists( 'Mobo_Core_Money_Policy' )
			|| '' === $money_unit
			|| $money_fraction_digits < 0
			|| Mobo_Core_Money_Policy::REPRESENTATION_MAJOR !== $money_price_representation
			|| $money_contract_version < Mobo_Core_Money_Policy::CONTRACT_VERSION ) {
			return new WP_Error(
				'mobo_core_provision_money_contract_invalid',
				'Provisioning monetary contract is missing or unsupported.',
				array( 'status' => 422 )
			);
		}

		$current_source = sanitize_key( (string) ( $row['source_key'] ?? '' ) );
		$current_provider = class_exists( 'Mobo_Core_Provider_Registry' )
			? Mobo_Core_Provider_Registry::normalize_key( $row['provider_key'] ?? '' ) : '';
		if ( '' !== $current_source && $current_source !== $source_key ) {
			return new WP_Error( 'mobo_core_provision_source_conflict', 'Connection is already bound to another Source.', array( 'status' => 409 ) );
		}
		if ( '' !== $current_provider && $current_provider !== $provider_key ) {
			return new WP_Error( 'mobo_core_provision_provider_conflict', 'Connection is already bound to another Provider.', array( 'status' => 409 ) );
		}

		$current_money_version = class_exists( 'Mobo_Core_Money_Policy' )
			? Mobo_Core_Money_Policy::normalize_contract_version( $row['money_contract_version'] ?? 0 ) : 0;
		if ( $current_money_version > $money_contract_version ) {
			return new WP_Error(
				'mobo_core_provision_money_contract_stale',
				'Provisioning monetary contract version is older than the durable Connection contract.',
				array( 'status' => 409 )
			);
		}
		if ( $current_money_version > 0 && $current_money_version === $money_contract_version ) {
			$current_contract = Mobo_Core_Money_Policy::contract_from_row( $row );
			if ( (string) $current_contract['unit'] !== $money_unit
				|| (int) $current_contract['fractionDigits'] !== $money_fraction_digits
				|| (string) $current_contract['priceRepresentation'] !== $money_price_representation ) {
				return new WP_Error(
					'mobo_core_provision_money_contract_version_conflict',
					'Provisioning attempted to change monetary semantics without increasing money_contract_version.',
					array( 'status' => 409 )
				);
			}
		}

		/* The Default is the installation controller, including Health and upgrade
		 * authority. Signed provisioning enforces the same disable invariant. */
		if ( ! $enabled && ! empty( $row['is_default'] ) ) {
			return new WP_Error( 'mobo_core_default_disable_forbidden', 'Default Connection cannot be disabled.', array( 'status' => 409 ) );
		}
		if ( $enabled && empty( $row['is_enabled'] ) && self::count_enabled() >= 1 && ! self::connection_ownership_ready() ) {
			return new WP_Error( 'mobo_core_multi_connection_runtime_not_ready', 'Connection cannot be enabled until Connection ownership migration is complete.', array( 'status' => 503 ) );
		}
		$prospective = $row;
		$prospective['money_unit'] = $money_unit;
		$prospective['money_fraction_digits'] = $money_fraction_digits;
		$prospective['money_price_representation'] = $money_price_representation;
		$prospective['money_contract_version'] = $money_contract_version;
		if ( $enabled && empty( $row['is_enabled'] ) ) {
			$money_status = Mobo_Core_Money_Policy::compatibility_status_for_row( $prospective );
			if ( empty( $money_status['compatible'] ) ) {
				return new WP_Error(
					'mobo_core_provision_money_unit_incompatible',
					(string) ( $money_status['message'] ?? 'Connection monetary contract is incompatible with WooCommerce.' ),
					array_merge( array( 'status' => 422 ), $money_status )
				);
			}
		}
		if ( '' === $display_name ) { $display_name = $source_key; }

		/* Exact-preimage CAS prevents signed provisioning from overwriting a newer
		 * admin mutation, credential rotation, or monetary contract. */
		$updated = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table_name() . " SET source_key = %s, provider_key = %s, display_name = %s, api_base_url = %s, source_site_url = %s, money_unit = %s, money_fraction_digits = %d, money_price_representation = %s, money_contract_version = %d, is_enabled = %d, updated_at = %s WHERE id = %d AND BINARY license_token = BINARY %s AND (source_key = '' OR BINARY source_key = BINARY %s) AND (provider_key = '' OR BINARY provider_key = BINARY %s) AND (%d = 1 OR is_default = 0) AND is_enabled = %d AND BINARY api_base_url = BINARY %s AND BINARY source_site_url = BINARY %s AND BINARY security_code = BINARY %s AND BINARY connection_key = BINARY %s AND BINARY money_unit = BINARY %s AND money_fraction_digits = %d AND BINARY money_price_representation = BINARY %s AND money_contract_version = %d",
			$source_key, $provider_key, $display_name, $api_base_url, $source_site_url,
			$money_unit, $money_fraction_digits, $money_price_representation, $money_contract_version,
			$enabled ? 1 : 0, current_time( 'mysql', true ), absint( $row['id'] ?? 0 ),
			$token, $source_key, $provider_key, $enabled ? 1 : 0,
			absint( $row['is_enabled'] ?? 0 ), (string) ( $row['api_base_url'] ?? '' ),
			(string) ( $row['source_site_url'] ?? '' ), (string) ( $row['security_code'] ?? '' ),
			(string) ( $row['connection_key'] ?? '' ), (string) ( $row['money_unit'] ?? '' ),
			absint( $row['money_fraction_digits'] ?? 0 ), (string) ( $row['money_price_representation'] ?? '' ),
			absint( $row['money_contract_version'] ?? 0 )
		) );
		if ( false === $updated ) {
			return new WP_Error( 'mobo_core_provision_update_failed', 'Connection provisioning could not be saved.', array( 'status' => 500 ) );
		}
		self::invalidate_request_cache( absint( $row['id'] ?? 0 ) );
		$fresh = self::get_by_id_fresh( absint( $row['id'] ?? 0 ) );
		if ( ! is_array( $fresh ) || ! hash_equals( $token, (string) ( $fresh['license_token'] ?? '' ) )
			|| $source_key !== (string) ( $fresh['source_key'] ?? '' )
			|| $provider_key !== (string) ( $fresh['provider_key'] ?? '' )
			|| $api_base_url !== (string) ( $fresh['api_base_url'] ?? '' )
			|| $source_site_url !== (string) ( $fresh['source_site_url'] ?? '' )
			|| $money_unit !== (string) ( $fresh['money_unit'] ?? '' )
			|| $money_fraction_digits !== absint( $fresh['money_fraction_digits'] ?? 0 )
			|| $money_price_representation !== (string) ( $fresh['money_price_representation'] ?? '' )
			|| $money_contract_version !== absint( $fresh['money_contract_version'] ?? 0 )
			|| (int) $enabled !== absint( $fresh['is_enabled'] ?? 0 ) ) {
			return new WP_Error( 'mobo_core_provision_concurrent_change', 'Connection provisioning was superseded by a concurrent change.', array( 'status' => 409 ) );
		}
		return $fresh;
	}

	/** Create one Connection for a registered Source. @return int|WP_Error */
	public static function create_connection( $source_key, $display_name = '', $webhook_id = '', $license_token = '', $security_code = '' ) {
		global $wpdb;
		if ( ! self::table_exists() ) { return new WP_Error( 'mobo_core_connection_storage_missing', 'Connection storage is unavailable.' ); }
		$source_key = class_exists( 'Mobo_Core_Source_Registry' ) ? Mobo_Core_Source_Registry::normalize_key( $source_key ) : sanitize_key( (string) $source_key );
		if ( '' === $source_key || ! class_exists( 'Mobo_Core_Source_Registry' ) || ! Mobo_Core_Source_Registry::exists( $source_key ) ) {
			return new WP_Error( 'mobo_core_source_unknown', 'Unknown Mobo source.' );
		}
		$original_webhook = trim( sanitize_text_field( (string) $webhook_id ) );
		$webhook_id = self::normalize_webhook_id( $webhook_id );
		if ( '' !== $original_webhook && '' === $webhook_id ) {
			return new WP_Error( 'mobo_core_webhook_id_invalid', 'WebHookId exceeds the supported length.' );
		}
		$security_code = Mobo_Core_Settings::normalize_security_code( $security_code );
		if ( '' !== $security_code && ! Mobo_Core_Settings::is_valid_security_code( $security_code ) ) {
			return new WP_Error( 'mobo_core_security_invalid', 'Security code is not valid for an HTTP header.' );
		}
		if ( '' !== $webhook_id && is_array( self::get_by_webhook_id( $webhook_id ) ) ) {
			return new WP_Error( 'mobo_core_webhook_id_exists', 'WebHookId is already assigned to another Connection.' );
		}
		if ( self::count_enabled() >= 1 && ! self::connection_ownership_ready() ) {
			return new WP_Error( 'mobo_core_multi_connection_runtime_not_ready', 'A second Connection cannot be enabled until Connection ownership migration is complete.' );
		}
		$source = Mobo_Core_Source_Registry::get( $source_key );
		$provider_key = class_exists( 'Mobo_Core_Provider_Registry' ) ? Mobo_Core_Provider_Registry::normalize_key( $source['provider'] ?? '' ) : '';
		$display_name = sanitize_text_field( (string) $display_name );
		if ( '' === $display_name ) { $display_name = sanitize_text_field( (string) ( $source['name'] ?? $source_key ) ); }
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'connection_key' => wp_generate_uuid4(),
				'source_key'     => $source_key,
				'provider_key'   => $provider_key,
				'display_name'   => $display_name,
				'webhook_id'     => '' !== $webhook_id ? $webhook_id : null,
				'api_base_url'   => Mobo_Core_Source_Registry::get_base_url( $source_key ),
				'source_site_url'=> Mobo_Core_Source_Registry::get_checkout_site_url( $source_key ),
				'license_token'  => Mobo_Core_Settings::normalize_opaque_secret( $license_token, 191 ),
				'security_code'  => $security_code,
				'is_default'     => 0,
				'is_enabled'     => 1,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s' )
		);
		if ( false === $inserted ) { return new WP_Error( 'mobo_core_connection_create_failed', 'Connection could not be created.' ); }
		$id = absint( $wpdb->insert_id );
		self::invalidate_request_cache();
		return $id;
	}

	/** Update editable Connection metadata/credentials. @return bool|WP_Error */
	public static function update_connection( $connection_id, $fields ) {
		global $wpdb;
		if ( ! is_array( $fields ) ) { return new WP_Error( 'mobo_core_connection_invalid_update', 'Invalid Connection update.' ); }
		if ( ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::should_block_lock( 'connection_admin_update' ) )
			|| ( class_exists( 'Mobo_Core_Database_Unicode_Migration' ) && Mobo_Core_Database_Unicode_Migration::should_block_runtime_lock( 'connection_admin_update' ) ) ) {
			return new WP_Error( 'mobo_core_connection_maintenance_busy', 'Connection changes are blocked by maintenance.' );
		}
		$token_guard = '';
		if ( array_key_exists( 'license_token', $fields ) ) {
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::should_block_lock( 'connection_admin_token' ) ) {
				return new WP_Error( 'mobo_core_connection_maintenance_busy', 'Connection changes are blocked by maintenance.' );
			}
			$db_name = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
			$token_guard = 'mobo_ct_' . substr( hash( 'sha256', $db_name . '|' . (string) $wpdb->prefix ), 0, 40 );
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $token_guard ) ) ) {
				return new WP_Error( 'mobo_core_connection_token_busy', 'Token change is already in progress.' );
			}
		}
		try {
			return self::update_connection_guarded( $connection_id, $fields );
		} finally {
			if ( '' !== $token_guard ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $token_guard ) ); }
		}
	}

	/** Execute the validated admin mutation within the optional installation-global Token guard. */
	private static function update_connection_guarded( $connection_id, $fields ) {
		global $wpdb;
		$id = absint( $connection_id );
		$row = self::get_by_id_fresh( $id );
		if ( $id <= 0 || ! is_array( $row ) || ! is_array( $fields ) ) { return new WP_Error( 'mobo_core_connection_missing', 'Connection not found.' ); }
		$data = array(); $formats = array();
		if ( array_key_exists( 'display_name', $fields ) ) { $data['display_name'] = sanitize_text_field( (string) $fields['display_name'] ); $formats[] = '%s'; }
		if ( array_key_exists( 'webhook_id', $fields ) ) {
			$original_webhook = trim( sanitize_text_field( (string) $fields['webhook_id'] ) );
			$webhook = self::normalize_webhook_id( $fields['webhook_id'] );
			if ( '' !== $original_webhook && '' === $webhook ) {
				return new WP_Error( 'mobo_core_webhook_id_invalid', 'WebHookId exceeds the supported length.' );
			}
			$owner = '' !== $webhook ? self::get_by_webhook_id( $webhook ) : null;
			if ( is_array( $owner ) && absint( $owner['id'] ?? 0 ) !== $id ) { return new WP_Error( 'mobo_core_webhook_id_exists', 'WebHookId is already assigned to another Connection.' ); }
			$data['webhook_id'] = '' !== $webhook ? $webhook : null; $formats[] = '%s';
		}
		if ( array_key_exists( 'security_code', $fields ) ) {
			$security = Mobo_Core_Settings::normalize_security_code( $fields['security_code'] );
			if ( '' !== $security && ! Mobo_Core_Settings::is_valid_security_code( $security ) ) { return new WP_Error( 'mobo_core_security_invalid', 'Security code is not valid for an HTTP header.' ); }
			$data['security_code'] = $security; $formats[] = '%s';
		}
		if ( array_key_exists( 'license_token', $fields ) ) {
			$token = Mobo_Core_Settings::normalize_opaque_secret( $fields['license_token'], 191 );
			if ( '' !== $token ) {
				$owner = $wpdb->get_var( $wpdb->prepare(
					'SELECT id FROM ' . self::table_name() . ' WHERE BINARY license_token = BINARY %s AND id <> %d LIMIT 1', $token, $id
				) );
				if ( '' !== (string) $wpdb->last_error || null !== $owner ) {
					return new WP_Error( 'mobo_core_license_token_exists', 'This Token is unavailable or assigned to another Connection.' );
				}
			}
			$data['license_token'] = $token; $formats[] = '%s';
		}
		if ( array_key_exists( 'is_enabled', $fields ) ) {
			$enabled = ! empty( $fields['is_enabled'] ) ? 1 : 0;
			if ( ! $enabled && ! empty( $row['is_default'] ) ) { return new WP_Error( 'mobo_core_default_disable_forbidden', 'Default Connection cannot be disabled.' ); }
			if ( $enabled && empty( $row['is_enabled'] ) && self::count_enabled() >= 1 && ! self::connection_ownership_ready() ) {
				return new WP_Error( 'mobo_core_multi_connection_runtime_not_ready', 'This Connection cannot be enabled until Connection ownership migration is complete.' );
			}
			if ( $enabled && empty( $row['is_enabled'] ) ) {
				if ( ! class_exists( 'Mobo_Core_Money_Policy' ) ) {
					return new WP_Error( 'mobo_core_money_policy_unavailable', 'Monetary compatibility policy is unavailable.' );
				}
				$money_status = Mobo_Core_Money_Policy::compatibility_status_for_row( $row );
				if ( empty( $money_status['compatible'] ) ) {
					return new WP_Error( 'mobo_core_money_contract_incompatible', (string) ( $money_status['message'] ?? 'Connection monetary contract is incompatible with WooCommerce.' ), $money_status );
				}
			}
			$data['is_enabled'] = $enabled; $formats[] = '%d';
		}
		if ( empty( $data ) ) { return true; }
		$data['updated_at'] = current_time( 'mysql', true ); $formats[] = '%s';
		/* The admin form may have been open while signed provisioning, credential
		 * rotation or a second admin update occurred. A plain UPDATE WHERE id
		 * silently clobbered those newer values. Bind the exact fresh preimage
		 * alongside the change in one SQL statement instead. */
		$assignments = array();
		$args = array();
		$i = 0;
		foreach ( $data as $key => $value ) {
			$assignments[] = $key . ' = ' . $formats[ $i++ ];
			$args[] = $value;
		}
		$where = " WHERE id = %d AND BINARY connection_key = BINARY %s
			AND BINARY source_key = BINARY %s AND BINARY provider_key = BINARY %s
			AND BINARY display_name = BINARY %s
			AND COALESCE(BINARY webhook_id, BINARY '') = BINARY %s
			AND BINARY api_base_url = BINARY %s AND BINARY source_site_url = BINARY %s
			AND BINARY license_token = BINARY %s AND BINARY security_code = BINARY %s
			AND BINARY money_unit = BINARY %s AND money_fraction_digits = %d
			AND BINARY money_price_representation = BINARY %s AND money_contract_version = %d
			AND is_enabled = %d AND is_default = %d";
		$args = array_merge( $args, array(
			$id, (string) ( $row['connection_key'] ?? '' ),
			(string) ( $row['source_key'] ?? '' ), (string) ( $row['provider_key'] ?? '' ),
			(string) ( $row['display_name'] ?? '' ), (string) ( $row['webhook_id'] ?? '' ),
			(string) ( $row['api_base_url'] ?? '' ), (string) ( $row['source_site_url'] ?? '' ),
			(string) ( $row['license_token'] ?? '' ), (string) ( $row['security_code'] ?? '' ),
			(string) ( $row['money_unit'] ?? '' ), absint( $row['money_fraction_digits'] ?? 0 ),
			(string) ( $row['money_price_representation'] ?? '' ), absint( $row['money_contract_version'] ?? 0 ),
			absint( $row['is_enabled'] ?? 0 ), absint( $row['is_default'] ?? 0 ),
		) );
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table_name() . ' SET ' . implode( ', ', $assignments ) . $where, $args ) );
		if ( false === $updated ) { return false; }
		self::invalidate_request_cache( $id );
		$fresh = self::get_by_id_fresh( $id );
		if ( ! is_array( $fresh ) ) { return new WP_Error( 'mobo_core_connection_readback_failed', 'Connection update cannot be verified.' ); }
		if ( 0 === (int) $updated ) {
			/* A true no-op is permitted only when the entire preimage is still
			 * present. Otherwise a competing writer won; do not claim success. */
			foreach ( array( 'connection_key', 'source_key', 'provider_key', 'display_name', 'webhook_id', 'api_base_url', 'source_site_url', 'license_token', 'security_code', 'money_unit', 'money_fraction_digits', 'money_price_representation', 'money_contract_version', 'is_enabled', 'is_default' ) as $key ) {
				if ( (string) ( $fresh[ $key ] ?? '' ) !== (string) ( $row[ $key ] ?? '' ) ) {
					return new WP_Error( 'mobo_core_connection_concurrent_change', 'Connection changed during the update; reload before retrying.' );
				}
			}
		}
		foreach ( $data as $key => $value ) {
			if ( (string) ( $fresh[ $key ] ?? '' ) !== (string) $value ) {
				return new WP_Error( 'mobo_core_connection_concurrent_change', 'Connection changed during the update; reload before retrying.' );
			}
		}
		return true;
	}

	/**
	 * Resolve a Portal-to-WordPress request to exactly one connection and verify
	 * its X-SEC. WebHookId selects a row; the secret authenticates it.
	 *
	 * @param string $webhook_id X-Mobo-Webhook-Id.
	 * @param string $provided_security X-SEC.
	 * @return array|WP_Error
	 */
	public static function authenticate_inbound( $webhook_id, $provided_security ) {
		$raw_webhook_id    = trim( sanitize_text_field( (string) $webhook_id ) );
		$webhook_id        = self::normalize_webhook_id( $webhook_id );
		$provided_security = Mobo_Core_Settings::normalize_security_code( $provided_security );
		if ( '' !== $raw_webhook_id && '' === $webhook_id ) {
			return new WP_Error( 'mobo_core_webhook_id_invalid', 'WebHookId exceeds the supported length.', array( 'status' => 400 ) );
		}

		if ( ! self::table_exists() ) {
			return new WP_Error( 'mobo_core_connection_storage_missing', 'Connection storage is unavailable.', array( 'status' => 503 ) );
		}

		$enabled_count = self::count_enabled();
		if ( $enabled_count <= 0 ) {
			return new WP_Error( 'mobo_core_connection_missing', 'No enabled Mobo connection is configured.', array( 'status' => 503 ) );
		}
		/* Multiple Sources are admitted only after the durable ownership migration
		 * has proved that every Source-owned row is assigned and the v10 composite
		 * identity indexes are active. Interrupted upgrades remain fail-closed. */
		if ( $enabled_count > 1 && ! self::multi_connection_runtime_ready() ) {
			return new WP_Error(
				'mobo_core_multi_connection_runtime_not_ready',
				'Multiple enabled Mobo connections are blocked until Connection ownership migration is complete.',
				array( 'status' => 503 )
			);
		}

		$connection = null;
		if ( '' !== $webhook_id ) {
			$connection = self::get_by_webhook_id( $webhook_id );
			if ( ! is_array( $connection ) ) {
				$candidates = self::find_unbound_security_candidates( $provided_security );
				if ( 1 === count( $candidates ) ) {
					$candidate_id = absint( $candidates[0]['id'] ?? 0 );
					$bound = self::bind_webhook_id_if_empty( $candidate_id, $webhook_id );
					if ( ! $bound ) {
						return new WP_Error( 'mobo_core_webhook_id_bind_failed', 'Could not bind WebHookId to the authenticated connection.', array( 'status' => 503 ) );
					}
					$connection = self::get_by_id_fresh( $candidate_id );
				} elseif ( count( $candidates ) > 1 ) {
					return new WP_Error(
						'mobo_core_webhook_id_bind_ambiguous',
						'WebHookId cannot be bound because X-SEC matches more than one unbound connection.',
						array( 'status' => 409 )
					);
				}
			}
			if ( ! is_array( $connection ) ) {
				return new WP_Error( 'mobo_core_unknown_webhook_id', 'Unknown Mobo WebHookId.', array( 'status' => 401 ) );
			}
		} else {
			/* Backward compatibility with Portal deployments predating the discriminator.
			 * Once more than one connection is enabled, ambiguity is unsafe and the header
			 * becomes mandatory. */
			if ( 1 !== $enabled_count ) {
				return new WP_Error( 'mobo_core_webhook_id_required', 'X-Mobo-Webhook-Id is required when multiple connections are enabled.', array( 'status' => 400 ) );
			}
			$connection = self::get_default_fresh();
			if ( ! is_array( $connection ) ) {
				return new WP_Error( 'mobo_core_default_connection_missing', 'Default Mobo connection is unavailable.', array( 'status' => 503 ) );
			}
		}

		/* A previous lookup is not permission to reuse a stale security code or
		 * disabled row. Never substitute a different Connection if this one moved. */
		$connection = self::get_by_id_fresh( absint( $connection['id'] ?? 0 ) );
		if ( ! is_array( $connection ) || empty( $connection['is_enabled'] )
			|| ( '' !== $webhook_id && ! hash_equals( $webhook_id, self::normalize_webhook_id( $connection['webhook_id'] ?? '' ) ) )
			|| ( '' === $webhook_id && empty( $connection['is_default'] ) ) ) {
			return new WP_Error( 'mobo_core_connection_changed_during_auth', 'Connection authentication authority changed.', array( 'status' => 409 ) );
		}

		$expected = self::security_code_for_row( $connection );
		if ( '' === $expected ) {
			return new WP_Error( 'mobo_core_security_missing', 'Security code is not configured for this connection.', array( 'status' => 403 ) );
		}
		if ( ! Mobo_Core_Settings::is_valid_security_code( $expected ) ) {
			return new WP_Error( 'mobo_core_security_invalid', 'Configured security code is not a valid visible-ASCII HTTP header value.', array( 'status' => 503 ) );
		}
		if ( ! self::security_matches( $expected, $provided_security ) ) {
			return new WP_Error( 'mobo_core_unauthorized', 'Unauthorized.', array( 'status' => 401 ) );
		}

		return $connection;
	}

	public static function get_runtime_connection() {
		$id = class_exists( 'Mobo_Core_Connection_Context' ) ? Mobo_Core_Connection_Context::get_current_connection_id() : 0;
		if ( $id > 0 ) {
			$row = self::get_by_id( $id );
			if ( is_array( $row ) && ! empty( $row['is_enabled'] ) ) { return $row; }

			/* An explicit durable owner must never drift to another Connection.
			 * Disabled/deleted/unknown owners therefore fail closed instead of
			 * inheriting the enabled Default Connection. */
			return null;
		}
		return self::get_default();
	}

	public static function get_runtime_value( $field, $fallback = '' ) {
		$field = sanitize_key( (string) $field );
		if ( ! in_array( $field, array( 'api_base_url', 'source_site_url', 'provider_key', 'license_token', 'security_code', 'webhook_id', 'display_name', 'connection_key', 'source_key', 'money_unit', 'money_fraction_digits', 'money_price_representation', 'money_contract_version' ), true ) ) {
			return $fallback;
		}
		$row = self::get_runtime_connection();
		if ( ! is_array( $row ) ) {
			/* Once the durable Connection table exists, missing runtime ownership is
			 * a routing failure, not permission to reuse legacy/Default credentials. */
			return self::table_exists() ? '' : $fallback;
		}
		$value = isset( $row[ $field ] ) ? $row[ $field ] : $fallback;
		return null === $value ? $fallback : $value;
	}

	/**
	 * Resolve an effective Backend URL without mutating the provisioned Connection.
	 *
	 * MOBO_API_BASE_URL is an explicit environment/developer override. It must
	 * continue to win at runtime even after durable Connection provisioning; the
	 * server-owned api_base_url remains stored unchanged for production routing.
	 *
	 * @param string $stored_url Provisioned Connection URL.
	 * @return string
	 */
	public static function resolve_api_base_url( $stored_url = '' ) {
		$override = defined( 'MOBO_API_BASE_URL' ) ? trim( (string) MOBO_API_BASE_URL ) : '';
		if ( '' !== $override ) {
			$override = trailingslashit( esc_url_raw( $override ) );
			if ( '' !== $override ) { return $override; }
		}

		$url = trim( (string) $stored_url );
		return '' !== $url ? trailingslashit( esc_url_raw( $url ) ) : '';
	}

	/** Resolve the active Connection's effective Backend URL. @return string */
	public static function get_runtime_base_url( $fallback = '' ) {
		$row = self::get_runtime_connection();
		if ( ! is_array( $row ) ) {
			if ( ! self::table_exists() ) { return self::resolve_api_base_url( $fallback ); }
			return '';
		}
		return self::resolve_api_base_url( (string) ( $row['api_base_url'] ?? '' ) );
	}

	/** Resolve the active Connection's provisioned storefront/source URL. @return string */
	public static function get_runtime_source_site_url( $fallback = '' ) {
		$row = self::get_runtime_connection();
		if ( ! is_array( $row ) ) { return self::table_exists() ? '' : $fallback; }
		$url = trim( (string) ( $row['source_site_url'] ?? '' ) );
		return '' !== $url ? untrailingslashit( esc_url_raw( $url ) ) : '';
	}

	/** Resolve the active Connection's provisioned provider key. @return string */
	public static function get_runtime_provider_key() {
		$row = self::get_runtime_connection();
		return is_array( $row ) && class_exists( 'Mobo_Core_Provider_Registry' )
			? Mobo_Core_Provider_Registry::normalize_key( $row['provider_key'] ?? '' )
			: '';
	}

	private static function ensure_default_source_key( $connection_id, $row = array() ) {
		unset( $connection_id, $row );
		/* Source identity is intentionally server-provisioned. */
		return true;
	}

	/**
	 * Find enabled Connections that have not been assigned a WebHookId and whose
	 * X-SEC matches the provided credential. Binding is allowed only when this
	 * returns exactly one row, which keeps multi-Connection bootstrap fail-closed.
	 *
	 * @param string $provided_security X-SEC header value.
	 * @return array<int,array>
	 */
	private static function find_unbound_security_candidates( $provided_security ) {
		$provided_security = Mobo_Core_Settings::normalize_security_code( $provided_security );
		if ( '' === $provided_security ) { return array(); }

		$matches = array();
		foreach ( self::list_enabled() as $row ) {
			if ( ! is_array( $row ) || '' !== self::normalize_webhook_id( $row['webhook_id'] ?? '' ) ) { continue; }
			$expected = self::security_code_for_row( $row );
			if ( self::security_matches( $expected, $provided_security ) ) {
				$matches[] = $row;
			}
		}
		return $matches;
	}

	private static function bind_webhook_id_if_empty( $connection_id, $webhook_id ) {
		global $wpdb;
		$id         = absint( $connection_id );
		$webhook_id = self::normalize_webhook_id( $webhook_id );
		if ( $id <= 0 || '' === $webhook_id ) { return false; }
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . " SET webhook_id = %s, updated_at = %s WHERE id = %d AND (webhook_id IS NULL OR webhook_id = '') AND is_enabled = 1",
				$webhook_id,
				current_time( 'mysql', true ),
				$id
			)
		);
		if ( false === $updated ) { return false; }
		self::invalidate_request_cache( $id );
		$row = self::get_by_id( $id );
		return is_array( $row ) && hash_equals( self::normalize_webhook_id( $row['webhook_id'] ?? '' ), $webhook_id );
	}

	private static function security_code_for_row( $row ) {
		$code = Mobo_Core_Settings::normalize_security_code( is_array( $row ) ? ( $row['security_code'] ?? '' ) : '' );
		if ( '' === $code && is_array( $row ) && ! empty( $row['is_default'] ) ) {
			$code = self::legacy_security_code();
		}
		return $code;
	}

	private static function security_matches( $expected, $provided ) {
		$expected = Mobo_Core_Settings::normalize_security_code( $expected );
		$provided = Mobo_Core_Settings::normalize_security_code( $provided );
		return '' !== $expected && '' !== $provided && hash_equals( $expected, $provided );
	}

	/* Also used by the REST authentication-status diagnostic. Never silently
	 * truncate an inbound identity into a different valid 191-byte identity. */
	public static function normalize_webhook_id( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		return strlen( $value ) <= 191 ? $value : '';
	}

	private static function legacy_security_code() {
		return Mobo_Core_Settings::normalize_security_code( get_option( 'mobo_core_security_code', '' ) );
	}

	/**
	 * Whether the running migration is adopting a pre-10.34.12 single-connection
	 * install. The previous release stays in mobo_core_db_version until the whole
	 * migration pipeline finalizes, so this is true exactly while upgrading from
	 * 10.33.x and false for fresh installs (empty version) and finished upgrades.
	 *
	 * @return bool
	 */
	public static function is_legacy_adoption_context() {
		$previous = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		return '' !== $previous && version_compare( $previous, self::LEGACY_ADOPTION_BEFORE_VERSION, '<' );
	}

	/**
	 * Backend URL to persist on the legacy Default Connection while adopting a
	 * 10.33.x install.
	 *
	 * Reproduces the 10.33.67 effective URL exactly: that release always defined
	 * MOBO_API_BASE_URL (wp-config value, else http://mobo.codeya.ir/) and its
	 * filter returned the constant first, so the legacy mobo_core_api_base_url
	 * option never took effect at runtime. A stale dev/staging value saved in that
	 * option must therefore NOT be adopted; it is ignored and logged.
	 *
	 * @param bool|null   $legacy_context Override for tests; null detects.
	 * @param string|null $constant_url   Override for tests; null reads MOBO_API_BASE_URL.
	 * @return string Normalized URL, or '' outside the legacy adoption context.
	 */
	public static function legacy_adoption_api_base_url( $legacy_context = null, $constant_url = null ) {
		$legacy_context = null === $legacy_context ? self::is_legacy_adoption_context() : (bool) $legacy_context;
		if ( ! $legacy_context ) { return ''; }
		if ( null === $constant_url ) {
			$constant_url = defined( 'MOBO_API_BASE_URL' ) ? (string) MOBO_API_BASE_URL : '';
		}
		$url      = self::LEGACY_DEFAULT_API_BASE_URL;
		$constant = trim( (string) $constant_url );
		if ( '' !== $constant ) {
			/* No DNS during migration; literal/local-name checks still apply. */
			$valid = self::validate_backend_base_url( $constant, null, false );
			if ( ! is_wp_error( $valid ) ) { $url = $valid; }
		}
		$option = trim( (string) get_option( 'mobo_core_api_base_url', '' ) );
		if ( '' !== $option && trailingslashit( $option ) !== $url && class_exists( 'Mobo_Core_Logger' ) ) {
			Mobo_Core_Logger::warning(
				'Legacy mobo_core_api_base_url option ignored during Default Connection adoption (10.33.x never used it at runtime).',
				array( 'ignored' => $option, 'adopted' => $url )
			);
		}
		return $url;
	}

	/**
	 * Backfill a Default row that a previous (interrupted) 10.34.x migration
	 * created with an empty URL. Only unprovisioned, URL-less rows are touched,
	 * and only while still upgrading from 10.33.x; a CAS keeps provisioning
	 * that wins a race intact.
	 *
	 * @param int   $connection_id Default Connection id.
	 * @param array $row           Row as read by the caller.
	 * @return void
	 */
	private static function adopt_legacy_api_base_url( $connection_id, $row ) {
		global $wpdb;
		$id = absint( $connection_id );
		if ( $id <= 0 || ! is_array( $row ) || empty( $row['is_default'] ) ) { return; }
		if ( '' !== trim( (string) ( $row['api_base_url'] ?? '' ) ) || '' !== trim( (string) ( $row['source_key'] ?? '' ) ) ) { return; }
		$url = self::legacy_adoption_api_base_url();
		if ( '' === $url ) { return; }
		$updated = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table_name() . " SET api_base_url = %s, updated_at = %s WHERE id = %d AND is_default = 1 AND api_base_url = '' AND source_key = ''",
			$url,
			current_time( 'mysql', true ),
			$id
		) );
		if ( false !== $updated ) { self::invalidate_request_cache( $id ); }
	}

	/**
	 * Local Backend targets are allowed only on explicit development installs.
	 *
	 * @return bool
	 */
	public static function local_backend_urls_allowed() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { return true; }
		if ( defined( 'MOBO_CORE_ALLOW_LOCAL_BACKEND' ) && MOBO_CORE_ALLOW_LOCAL_BACKEND ) { return true; }
		return function_exists( 'wp_get_environment_type' ) && in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	/**
	 * Validate a Backend base URL (http or https; plain http is intentionally allowed).
	 *
	 * @param string        $url         Candidate URL.
	 * @param bool|null     $allow_local Null = local_backend_urls_allowed().
	 * @param bool|callable $resolve     True = gethostbynamel(); false = skip DNS;
	 *                                   callable(host): string[]|false for tests.
	 * @return string|WP_Error Normalized URL with trailing slash.
	 */
	public static function validate_backend_base_url( $url, $allow_local = null, $resolve = true ) {
		$url = trim( (string) $url );
		$invalid = new WP_Error( 'mobo_core_backend_url_invalid', 'Backend URL must be an absolute http(s) URL without credentials, query or fragment.' );
		if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\s\x00-\x1f\x7f]/', $url ) ) { return $invalid; }
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return $invalid;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) { return $invalid; }
		if ( isset( $parts['port'] ) && ( absint( $parts['port'] ) < 1 || absint( $parts['port'] ) > 65535 ) ) { return $invalid; }

		$host = self::normalize_url_host( (string) $parts['host'] );
		if ( '' === $host ) { return $invalid; }
		$allow_local = null === $allow_local ? self::local_backend_urls_allowed() : (bool) $allow_local;
		if ( ! $allow_local ) {
			$local = new WP_Error( 'mobo_core_backend_url_local', 'Backend URL must not target a private, loopback, link-local or reserved address.' );
			if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
				if ( self::is_non_public_ip( $host ) ) { return $local; }
			} else {
				if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,62})(?:\.[a-z0-9](?:[a-z0-9-]{0,62}))*$/', $host ) ) { return $invalid; }
				if ( 'localhost' === $host || false === strpos( $host, '.' )
					|| preg_match( '/\.(localhost|local|localdomain|internal|lan|home|arpa)$/', $host ) ) {
					return $local;
				}
				/* Hosts that disable every PHP DNS function cannot run this check; the
				 * name-level guards above still apply (readiness reports dns_disabled). */
				if ( false !== $resolve && ( is_callable( $resolve ) || Mobo_Core_Environment::can( 'gethostbynamel' ) || Mobo_Core_Environment::can( 'dns_get_record' ) ) ) {
					$ips = is_callable( $resolve ) ? call_user_func( $resolve, $host ) : self::resolve_host_addresses( $host );
					if ( ! is_array( $ips ) || empty( $ips ) ) {
						return new WP_Error( 'mobo_core_backend_url_unresolvable', 'Backend URL host could not be resolved.' );
					}
					foreach ( $ips as $ip ) {
						if ( false === filter_var( (string) $ip, FILTER_VALIDATE_IP ) || self::is_non_public_ip( (string) $ip ) ) { return $local; }
					}
				}
			}
		}

		$normalized = trailingslashit( esc_url_raw( $url, array( 'http', 'https' ) ) );
		return '/' !== $normalized ? $normalized : $invalid;
	}

	/**
	 * Whether two URLs name the same host (scheme, port and path may differ).
	 *
	 * @return bool
	 */
	public static function same_url_host( $a, $b ) {
		$ha = self::normalize_url_host( (string) wp_parse_url( (string) $a, PHP_URL_HOST ) );
		$hb = self::normalize_url_host( (string) wp_parse_url( (string) $b, PHP_URL_HOST ) );
		return '' !== $ha && $ha === $hb;
	}

	/**
	 * Resolve A records, plus AAAA when available. Runtime only; tests inject a resolver.
	 *
	 * @param string $host Hostname.
	 * @return string[]
	 */
	private static function resolve_host_addresses( $host ) {
		$ips = Mobo_Core_Environment::resolve_ipv4( $host );
		$ips = is_array( $ips ) ? $ips : array();
		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( is_array( $record ) && ! empty( $record['ipv6'] ) ) { $ips[] = (string) $record['ipv6']; }
				}
			}
		}
		return $ips;
	}

	private static function normalize_url_host( $host ) {
		$host = strtolower( rtrim( trim( (string) $host ), '.' ) );
		if ( '' !== $host && '[' === $host[0] && ']' === substr( $host, -1 ) ) { $host = substr( $host, 1, -1 ); }
		return $host;
	}

	/**
	 * Version-independent private/reserved check (PHP's filter flags changed
	 * between 7.4 and 8.x). IPv4-mapped/compatible IPv6 is checked as IPv4.
	 *
	 * @param string $ip IP literal.
	 * @return bool True when the address is not a public unicast address.
	 */
	public static function is_non_public_ip( $ip ) {
		$packed = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed ) { return true; }
		if ( 16 === strlen( $packed ) ) {
			$prefix = substr( $packed, 0, 12 );
			$nat64 = "\x00\x64\xff\x9b" . str_repeat( "\0", 8 );
			if ( str_repeat( "\0", 10 ) . "\xff\xff" === $prefix || $nat64 === $prefix
				|| ( str_repeat( "\0", 12 ) === $prefix && "\0\0\0\0" !== substr( $packed, 12 ) && "\0\0\0\1" !== substr( $packed, 12 ) ) ) {
				$packed = substr( $packed, 12 );
			}
		}
		$ranges = 4 === strlen( $packed )
			? array( '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4' )
			: array( '::/127', '64:ff9b:1::/48', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8' );
		foreach ( $ranges as $cidr ) {
			list( $net, $bits ) = explode( '/', $cidr );
			$net = inet_pton( $net );
			if ( false === $net || strlen( $net ) !== strlen( $packed ) ) { continue; }
			$bits = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			if ( $bytes > 0 && substr( $packed, 0, $bytes ) !== substr( $net, 0, $bytes ) ) { continue; }
			$rem = $bits % 8;
			if ( $rem > 0 ) {
				$mask = ( 0xff << ( 8 - $rem ) ) & 0xff;
				if ( ( ord( $packed[ $bytes ] ) & $mask ) !== ( ord( $net[ $bytes ] ) & $mask ) ) { continue; }
			}
			return true;
		}
		return false;
	}
}
