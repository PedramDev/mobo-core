<?php
/**
 * Mobo Core custom-storage foundation schema.
 *
 * This schema is intentionally independent from the legacy runtime schema gate.
 * A failure here must not prevent existing installs from continuing in legacy
 * mode. Promotion beyond legacy is allowed only after these postconditions pass.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Storage_Schema {

	const VERSION = '15';

	const VERSION_OPTION    = 'mobo_core_storage_schema_version';
	const ERROR_OPTION      = 'mobo_core_storage_schema_last_error';
	const ERROR_TIME_OPTION = 'mobo_core_storage_schema_last_error_at';

	/* Durable positive validation cache (10.34.77). validate() issues ~200
	 * SHOW TABLES/COLUMNS/INDEX queries; is_ready() is consulted on most requests.
	 * A successful validation for this exact schema VERSION is trusted for
	 * VALIDATED_TTL seconds. Failures are never cached, ensure() always re-validates,
	 * and a real table loss still surfaces as fail-closed query errors. */
	const VALIDATED_OPTION = 'mobo_core_storage_schema_validated_v1';
	const VALIDATED_TTL    = 21600;

	/** @var bool|null */
	private static $ready_cache = null;

	/** @var string */
	private static $last_failure = '';

	/**
	 * Canonical logical table names.
	 *
	 * @return array
	 */
	public static function table_keys() {
		return array(
			'connections',
			'connection_settings',
			'products',
			'variants',
			'jobs',
			'media',
			'order_dispatch',
			'runtime',
			'migrations',
			'state',
			'work_queue',
			'seen_variants',
			'events',
			'entity_meta',
		);
	}

	/**
	 * Resolve one custom table name through the active WordPress prefix.
	 *
	 * @param string $key Logical table key.
	 * @return string
	 */
	public static function table_name( $key ) {
		global $wpdb;

		$key = sanitize_key( (string) $key );
		$map = array(
			'connections'          => 'mobo_connections',
			'connection_settings' => 'mobo_connection_settings',
			'products'       => 'mobo_products',
			'variants'       => 'mobo_variants',
			'jobs'           => 'mobo_jobs',
			'media'          => 'mobo_media',
			'order_dispatch' => 'mobo_order_dispatch',
			'runtime'        => 'mobo_runtime',
			'migrations'     => 'mobo_migrations',
			'state'          => 'mobo_state',
			'work_queue'     => 'mobo_work_queue',
			'seen_variants'  => 'mobo_seen_variants',
			'events'         => 'mobo_events',
			'entity_meta'    => 'mobo_entity_meta',
		);

		return isset( $map[ $key ] ) ? $wpdb->prefix . $map[ $key ] : '';
	}

	/**
	 * Return all resolved table names keyed by logical name.
	 *
	 * @return array
	 */
	public static function table_names() {
		$tables = array();
		foreach ( self::table_keys() as $key ) {
			$tables[ $key ] = self::table_name( $key );
		}
		return $tables;
	}

	/**
	 * Create/update all phase-1 custom tables and verify their postconditions.
	 *
	 * This method records its own schema state. It never stamps the plugin's legacy
	 * mobo_core_db_version and therefore cannot strand an existing site if custom
	 * storage DDL is rejected by a hosting provider.
	 *
	 * @return bool
	 */
	public static function ensure() {
		global $wpdb;

		if ( class_exists( 'Mobo_Core_Storage_Mode' ) ) {
			Mobo_Core_Storage_Mode::ensure_default();
		}
		if ( class_exists( 'Mobo_Core_Storage_Legacy_Write_Suppression' ) ) {
			Mobo_Core_Storage_Legacy_Write_Suppression::ensure_default();
		}
		if ( class_exists( 'Mobo_Core_Storage_Postmeta_Write_Suppression' ) ) {
			Mobo_Core_Storage_Postmeta_Write_Suppression::ensure_default();
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = Mobo_Core_Database_Charset::schema_charset_collate();
		$t               = self::table_names();

		$sql = array();

		$sql[] = "CREATE TABLE {$t['connections']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_key varchar(64) NOT NULL,
			source_key varchar(64) NOT NULL DEFAULT '',
			provider_key varchar(64) NOT NULL DEFAULT '',
			display_name varchar(191) NOT NULL DEFAULT '',
			webhook_id varchar(191) NULL DEFAULT NULL,
			api_base_url text NULL,
			source_site_url text NULL,
			money_unit varchar(16) NOT NULL DEFAULT '',
			money_fraction_digits tinyint(3) unsigned NOT NULL DEFAULT 0,
			money_price_representation varchar(16) NOT NULL DEFAULT '',
			money_contract_version smallint(5) unsigned NOT NULL DEFAULT 0,
			license_token varchar(191) NOT NULL DEFAULT '',
			security_code varchar(255) NOT NULL DEFAULT '',
			is_default tinyint(1) NOT NULL DEFAULT 0,
			is_enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY connection_key (connection_key),
			UNIQUE KEY webhook_id (webhook_id),
			KEY source_key (source_key,id),
			KEY provider_key (provider_key,id),
			KEY license_token (license_token,id),
			KEY enabled_default (is_enabled,is_default,id),
			KEY updated_at (updated_at,id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$t['connection_settings']} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		connection_id bigint(20) unsigned NOT NULL,
		setting_key varchar(128) NOT NULL,
		setting_value longtext NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY connection_setting (connection_id,setting_key),
		KEY setting_key (setting_key,connection_id),
		KEY updated_at (updated_at,id)
	) {$charset_collate};";

		$sql[] = "CREATE TABLE {$t['products']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_guid varchar(191) NOT NULL,
			woo_product_id bigint(20) unsigned NULL DEFAULT NULL,
			portal_product_id bigint(20) unsigned NULL DEFAULT NULL,
			simple_variant_guid varchar(191) NULL DEFAULT NULL,
			simple_portal_variant_id bigint(20) unsigned NULL DEFAULT NULL,
			product_seen_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			product_seen_revision_present tinyint(1) NOT NULL DEFAULT 0,
			product_applied_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			product_applied_revision_present tinyint(1) NOT NULL DEFAULT 0,
			variant_seen_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			variant_seen_revision_present tinyint(1) NOT NULL DEFAULT 0,
			variant_applied_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			variant_applied_revision_present tinyint(1) NOT NULL DEFAULT 0,
			product_source_hash char(64) NOT NULL DEFAULT '',
			product_source_hash_present tinyint(1) NOT NULL DEFAULT 0,
			sync_incomplete_meta tinyint(1) NOT NULL DEFAULT 0,
			sync_incomplete_meta_present tinyint(1) NOT NULL DEFAULT 0,
			sync_incomplete tinyint(1) NOT NULL DEFAULT 0,
			desired_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			applied_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			desired_hash char(64) NOT NULL DEFAULT '',
			applied_hash char(64) NOT NULL DEFAULT '',
			sync_status varchar(24) NOT NULL DEFAULT 'idle',
			dirty_flags bigint(20) unsigned NOT NULL DEFAULT 0,
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NULL,
			claimed_at datetime NULL,
			claim_token varchar(64) NOT NULL DEFAULT '',
			last_received_at datetime NULL,
			last_applied_at datetime NULL,
			last_error_at datetime NULL,
			last_error_code varchar(64) NOT NULL DEFAULT '',
			last_error longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_guid (connection_id,product_guid(180)),
			UNIQUE KEY woo_product_id (woo_product_id),
			KEY portal_product_id (connection_id,portal_product_id),
			UNIQUE KEY simple_variant_guid (connection_id,simple_variant_guid(180)),
			KEY simple_portal_variant_id (connection_id,simple_portal_variant_id),
			KEY sync_due (connection_id,sync_status,next_attempt_at,id),
			KEY revision_due (connection_id,desired_revision,applied_revision,id),
			KEY claim_lookup (connection_id,claimed_at,claim_token)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$t['variants']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			variant_guid varchar(191) NOT NULL,
			product_guid varchar(191) NOT NULL,
			woo_variation_id bigint(20) unsigned NULL DEFAULT NULL,
			woo_product_id bigint(20) unsigned NULL DEFAULT NULL,
			portal_variant_id bigint(20) unsigned NULL DEFAULT NULL,
			variant_seen_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			variant_seen_revision_present tinyint(1) NOT NULL DEFAULT 0,
			variant_applied_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			variant_applied_revision_present tinyint(1) NOT NULL DEFAULT 0,
			variant_source_hash char(64) NOT NULL DEFAULT '',
			variant_source_hash_present tinyint(1) NOT NULL DEFAULT 0,
			sync_incomplete_meta tinyint(1) NOT NULL DEFAULT 0,
			sync_incomplete_meta_present tinyint(1) NOT NULL DEFAULT 0,
			sync_incomplete tinyint(1) NOT NULL DEFAULT 0,
			desired_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			applied_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			desired_hash char(64) NOT NULL DEFAULT '',
			applied_hash char(64) NOT NULL DEFAULT '',
			sync_status varchar(24) NOT NULL DEFAULT 'idle',
			dirty_flags bigint(20) unsigned NOT NULL DEFAULT 0,
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NULL,
			claimed_at datetime NULL,
			claim_token varchar(64) NOT NULL DEFAULT '',
			last_received_at datetime NULL,
			last_applied_at datetime NULL,
			last_error_at datetime NULL,
			last_error_code varchar(64) NOT NULL DEFAULT '',
			last_error longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY variant_guid (connection_id,variant_guid(180)),
			UNIQUE KEY woo_variation_id (woo_variation_id),
			KEY product_guid (connection_id,product_guid(180)),
			KEY woo_parent (connection_id,woo_product_id,woo_variation_id),
			KEY portal_variant_id (connection_id,portal_variant_id),
			KEY sync_due (connection_id,sync_status,next_attempt_at,id),
			KEY revision_due (connection_id,desired_revision,applied_revision,id),
			KEY claim_lookup (connection_id,claimed_at,claim_token)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$t['jobs']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			job_type varchar(32) NOT NULL,
			entity_type varchar(24) NOT NULL DEFAULT '',
			entity_guid varchar(191) NOT NULL DEFAULT '',
			woo_object_id bigint(20) unsigned NULL DEFAULT NULL,
			target_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			priority smallint(5) unsigned NOT NULL DEFAULT 100,
			status varchar(24) NOT NULL DEFAULT 'available',
			attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
			max_attempts smallint(5) unsigned NOT NULL DEFAULT 10,
			available_at datetime NULL,
			claimed_at datetime NULL,
			claim_expires_at datetime NULL,
			claim_token varchar(64) NOT NULL DEFAULT '',
			dedupe_key varchar(191) NULL DEFAULT NULL,
			payload longtext NULL,
			last_error_code varchar(64) NOT NULL DEFAULT '',
			last_error longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			completed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY active_dedupe (connection_id,dedupe_key(180)),
			KEY status_available (connection_id,status,available_at,priority,id),
			KEY claim_expiry (connection_id,status,claim_expires_at,id),
			KEY entity_status (connection_id,entity_type,entity_guid(110),status),
			KEY woo_object_id (connection_id,woo_object_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$t['media']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			media_guid varchar(191) NOT NULL,
			product_guid varchar(191) NOT NULL DEFAULT '',
			variant_guid varchar(191) NOT NULL DEFAULT '',
			source_url longtext NULL,
			source_hash char(64) NOT NULL DEFAULT '',
			woo_attachment_id bigint(20) unsigned NULL DEFAULT NULL,
			position int(10) unsigned NOT NULL DEFAULT 0,
			media_type varchar(24) NOT NULL DEFAULT 'image',
			desired_state varchar(24) NOT NULL DEFAULT 'present',
			applied_state varchar(24) NOT NULL DEFAULT 'unknown',
			desired_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			applied_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			sync_status varchar(24) NOT NULL DEFAULT 'idle',
			last_seen_at datetime NULL,
			last_applied_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY media_guid (connection_id,media_guid(180)),
			KEY product_guid (connection_id,product_guid(145)),
			KEY variant_guid (connection_id,variant_guid(145)),
			KEY woo_attachment_id (woo_attachment_id),
			KEY sync_due (connection_id,sync_status,desired_revision,applied_revision,id),
			KEY source_hash (connection_id,source_hash)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$t['order_dispatch']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			woo_order_id bigint(20) unsigned NOT NULL,
			classification varchar(24) NOT NULL DEFAULT 'unknown',
			has_mobo_items tinyint(1) NOT NULL DEFAULT 0,
			has_non_mobo_items tinyint(1) NOT NULL DEFAULT 0,
			dispatch_status varchar(32) NOT NULL DEFAULT 'pending',
			request_id varchar(191) NOT NULL DEFAULT '',
			portal_order_id varchar(191) NOT NULL DEFAULT '',
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NULL,
			last_attempt_at datetime NULL,
			last_success_at datetime NULL,
			last_error_code varchar(64) NOT NULL DEFAULT '',
			last_error longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY woo_order_id (woo_order_id),
			KEY status_due (connection_id,dispatch_status,next_attempt_at,id),
			KEY classification (connection_id,classification),
			KEY request_id (request_id(150)),
			KEY portal_order_id (portal_order_id(150))
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$t['runtime']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			worker_name varchar(100) NOT NULL,
			last_started_at datetime NULL,
			last_finished_at datetime NULL,
			last_cursor varchar(191) NOT NULL DEFAULT '',
			consecutive_failures int(10) unsigned NOT NULL DEFAULT 0,
			circuit_state varchar(24) NOT NULL DEFAULT 'closed',
			circuit_open_until datetime NULL,
			last_error_code varchar(64) NOT NULL DEFAULT '',
			state_json longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY worker_name (connection_id,worker_name),
			KEY circuit_due (connection_id,circuit_state,circuit_open_until,id),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$t['migrations']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			migration_key varchar(191) NOT NULL,
			migration_version bigint(20) unsigned NOT NULL DEFAULT 1,
			status varchar(24) NOT NULL DEFAULT 'pending',
			cursor_bigint bigint(20) unsigned NOT NULL DEFAULT 0,
			cursor_text varchar(191) NOT NULL DEFAULT '',
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			last_error longtext NULL,
			started_at datetime NULL,
			completed_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY migration_key (connection_id,migration_key(180)),
			KEY status_updated (connection_id,status,updated_at,id)
		) {$charset_collate};";


		$sql[] = "CREATE TABLE {$t['state']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			state_key varchar(191) NOT NULL,
			state_value longtext NULL,
			value_hash char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY state_key (connection_id,state_key(180)),
			KEY updated_at (connection_id,updated_at,id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$t['work_queue']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			queue_type varchar(48) NOT NULL,
			dedupe_key varchar(191) NOT NULL,
			dedupe_hash char(64) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			payload longtext NULL,
			payload_hash char(64) NOT NULL DEFAULT '',
			queued_at_us bigint(20) unsigned NOT NULL DEFAULT 0,
			available_at datetime NULL,
			claim_token varchar(64) NOT NULL DEFAULT '',
			claim_expires_at datetime NULL,
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			last_error longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY queue_dedupe (connection_id,queue_type,dedupe_hash),
			KEY queue_due (connection_id,queue_type,available_at,id),
			KEY queue_order (connection_id,queue_type,queued_at_us,id),
			KEY queue_object (connection_id,queue_type,object_id,id),
			KEY claim_expiry (connection_id,queue_type,claim_expires_at,id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$t['seen_variants']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			scope_hash char(64) NOT NULL,
			product_guid varchar(191) NOT NULL DEFAULT '',
			sync_id varchar(191) NOT NULL DEFAULT '',
			variant_guid varchar(191) NOT NULL DEFAULT '',
			variant_hash char(64) NOT NULL DEFAULT '',
			is_scope_marker tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY scope_variant (connection_id,scope_hash,variant_hash),
			KEY scope_marker (connection_id,scope_hash,is_scope_marker,id),
			KEY product_scope (connection_id,product_guid(110),scope_hash)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$t['events']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_type varchar(64) NOT NULL,
			payload longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event_recent (connection_id,event_type,id),
			KEY created_at (connection_id,created_at,id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$t['entity_meta']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			woo_object_id bigint(20) unsigned NOT NULL,
			object_type varchar(24) NOT NULL,
			meta_key varchar(191) NOT NULL,
			meta_value longtext NULL,
			value_hash char(64) NOT NULL DEFAULT '',
			sort_order bigint(20) unsigned NOT NULL DEFAULT 0,
			legacy_meta_id bigint(20) unsigned NULL DEFAULT NULL,
			legacy_origin_meta_id bigint(20) unsigned NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_meta_id (legacy_meta_id),
			UNIQUE KEY legacy_origin_meta_id (legacy_origin_meta_id),
			KEY object_key (woo_object_id,meta_key(120),sort_order,id),
			KEY object_order (woo_object_id,sort_order,id),
			KEY key_object (meta_key(120),woo_object_id,id),
			KEY object_type (object_type,woo_object_id,id)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		if ( class_exists( 'Mobo_Core_Database_Charset' ) ) {
			Mobo_Core_Database_Charset::reset_cache();
		}

		/* Schema v14 adds immutable Legacy lineage for entity-meta crash recovery.
		 * Existing source-linked rows can be backfilled exactly because legacy_meta_id
		 * was already unique. A failed/ambiguous backfill keeps storage unready. */
		$wpdb->last_error = '';
		$lineage_backfill = $wpdb->query( "UPDATE {$t['entity_meta']} SET legacy_origin_meta_id=legacy_meta_id WHERE legacy_origin_meta_id IS NULL AND legacy_meta_id IS NOT NULL" );
		if ( false === $lineage_backfill || '' !== trim( (string) $wpdb->last_error ) ) {
			self::$last_failure = 'entity_meta legacy lineage backfill failed.';
			self::$ready_cache = false;
			update_option( self::ERROR_OPTION, 'Mobo custom storage foundation is incomplete: ' . self::$last_failure . ' Legacy mode remains active.', false );
			update_option( self::ERROR_TIME_OPTION, time(), false );
			return false;
		}

		/* dbDelta can leave a same-name index with an older column definition.
		 * Repair every Mobo-owned required index atomically before validation so
		 * schema readiness represents the actual query contract, not just DDL intent. */
		self::repair_required_indexes( $t );

		self::$ready_cache = null;
		$ready             = self::validate();
		if ( $ready ) {
			update_option( self::VALIDATED_OPTION, array( 'version' => self::VERSION, 'at' => time() ), false );
		} else {
			delete_option( self::VALIDATED_OPTION );
		}
		if ( $ready ) {
			update_option( self::VERSION_OPTION, self::VERSION, false );
			delete_option( self::ERROR_OPTION );
			delete_option( self::ERROR_TIME_OPTION );
			return true;
		}

		$error = '' !== self::$last_failure
			? 'Mobo custom storage foundation is incomplete: ' . self::$last_failure . ' Legacy mode remains active.'
			: 'Mobo custom storage foundation is incomplete. Legacy mode remains active.';
		update_option( self::ERROR_OPTION, $error, false );
		update_option( self::ERROR_TIME_OPTION, time(), false );
		return false;
	}

	/**
	 * Fast runtime readiness check with request-local caching.
	 *
	 * @return bool
	 */
	public static function is_ready() {
		if ( null !== self::$ready_cache ) {
			return (bool) self::$ready_cache;
		}

		if ( self::VERSION !== (string) get_option( self::VERSION_OPTION, '' ) ) {
			self::$ready_cache = false;
			return false;
		}

		if ( self::validation_cache_fresh( get_option( self::VALIDATED_OPTION, array() ), time() ) ) {
			self::$ready_cache = true;
			return true;
		}

		self::$ready_cache = self::validate();
		if ( self::$ready_cache ) {
			update_option( self::VALIDATED_OPTION, array( 'version' => self::VERSION, 'at' => time() ), false );
		} else {
			delete_option( self::VALIDATED_OPTION );
		}
		return (bool) self::$ready_cache;
	}

	/**
	 * Pure freshness check for the durable validation stamp (unit tested).
	 *
	 * @param mixed $stamp Stored option value.
	 * @param int   $now   Current Unix time.
	 * @return bool
	 */
	public static function validation_cache_fresh( $stamp, $now ) {
		if ( ! is_array( $stamp ) || self::VERSION !== (string) ( $stamp['version'] ?? '' ) ) { return false; }
		$at = absint( $stamp['at'] ?? 0 );
		$now = absint( $now );
		return $at > 0 && $at <= $now + 60 && ( $now - $at ) < self::VALIDATED_TTL;
	}

	/**
	 * Verify every required table, column and correctness-critical index.
	 *
	 * @return bool
	 */
	public static function validate() {
		global $wpdb;

		self::$last_failure = '';
		$tables             = self::table_names();

		if ( class_exists( 'Mobo_Core_Database_Charset' ) && ! Mobo_Core_Database_Charset::tables_are_full_unicode( array_values( $tables ) ) ) {
			self::$last_failure = 'one or more Mobo custom-storage text columns are not utf8mb4.';
			self::$ready_cache  = false;
			return false;
		}

		$required_columns = array(
			'connections' => array( 'id', 'connection_key', 'source_key', 'provider_key', 'display_name', 'webhook_id', 'api_base_url', 'source_site_url', 'money_unit', 'money_fraction_digits', 'money_price_representation', 'money_contract_version', 'license_token', 'security_code', 'is_default', 'is_enabled', 'created_at', 'updated_at' ),
			'connection_settings' => array( 'id', 'connection_id', 'setting_key', 'setting_value', 'created_at', 'updated_at' ),
			'products' => array( 'id', 'connection_id', 'product_guid', 'woo_product_id', 'portal_product_id', 'simple_variant_guid', 'simple_portal_variant_id', 'product_seen_revision', 'product_seen_revision_present', 'product_applied_revision', 'product_applied_revision_present', 'variant_seen_revision', 'variant_seen_revision_present', 'variant_applied_revision', 'variant_applied_revision_present', 'product_source_hash', 'product_source_hash_present', 'sync_incomplete_meta', 'sync_incomplete_meta_present', 'sync_incomplete', 'desired_revision', 'applied_revision', 'sync_status', 'dirty_flags', 'next_attempt_at', 'claimed_at', 'claim_token', 'created_at', 'updated_at' ),
			'variants' => array( 'id', 'connection_id', 'variant_guid', 'product_guid', 'woo_variation_id', 'woo_product_id', 'portal_variant_id', 'variant_seen_revision', 'variant_seen_revision_present', 'variant_applied_revision', 'variant_applied_revision_present', 'variant_source_hash', 'variant_source_hash_present', 'sync_incomplete_meta', 'sync_incomplete_meta_present', 'sync_incomplete', 'desired_revision', 'applied_revision', 'sync_status', 'dirty_flags', 'next_attempt_at', 'claimed_at', 'claim_token', 'created_at', 'updated_at' ),
			'jobs' => array( 'id', 'connection_id', 'job_type', 'entity_type', 'entity_guid', 'target_revision', 'priority', 'status', 'available_at', 'claim_expires_at', 'claim_token', 'dedupe_key', 'created_at', 'updated_at' ),
			'media' => array( 'id', 'connection_id', 'media_guid', 'product_guid', 'variant_guid', 'woo_attachment_id', 'desired_state', 'applied_state', 'desired_revision', 'applied_revision', 'sync_status', 'created_at', 'updated_at' ),
			'order_dispatch' => array( 'id', 'connection_id', 'woo_order_id', 'classification', 'has_mobo_items', 'has_non_mobo_items', 'dispatch_status', 'attempt_count', 'next_attempt_at', 'created_at', 'updated_at' ),
			'runtime' => array( 'id', 'connection_id', 'worker_name', 'last_cursor', 'consecutive_failures', 'circuit_state', 'circuit_open_until', 'state_json', 'created_at', 'updated_at' ),
			'migrations' => array( 'id', 'connection_id', 'migration_key', 'migration_version', 'status', 'cursor_bigint', 'cursor_text', 'attempt_count', 'created_at', 'updated_at' ),
			'state' => array( 'id', 'connection_id', 'state_key', 'state_value', 'value_hash', 'created_at', 'updated_at' ),
			'work_queue' => array( 'id', 'connection_id', 'queue_type', 'dedupe_key', 'dedupe_hash', 'object_id', 'payload', 'payload_hash', 'queued_at_us', 'available_at', 'claim_token', 'claim_expires_at', 'attempt_count', 'created_at', 'updated_at' ),
			'seen_variants' => array( 'id', 'connection_id', 'scope_hash', 'product_guid', 'sync_id', 'variant_guid', 'variant_hash', 'is_scope_marker', 'created_at' ),
			'events' => array( 'id', 'connection_id', 'event_type', 'payload', 'created_at' ),
			'entity_meta' => array( 'id', 'woo_object_id', 'object_type', 'meta_key', 'meta_value', 'value_hash', 'sort_order', 'legacy_meta_id', 'legacy_origin_meta_id', 'created_at', 'updated_at' ),
		);

		foreach ( $tables as $key => $table ) {
			if ( '' === $table ) {
				self::$last_failure = 'empty table name for ' . $key . '.';
				self::$ready_cache  = false;
				return false;
			}
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( (string) $found !== $table ) {
				self::$last_failure = 'missing table ' . $table . '.';
				self::$ready_cache  = false;
				return false;
			}

			foreach ( $required_columns[ $key ] as $column ) {
				$found_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
				if ( $column !== (string) $found_column ) {
					self::$last_failure = 'missing column ' . $table . '.' . $column . '.';
					self::$ready_cache  = false;
					return false;
				}
			}
		}

		$required_indexes = self::required_indexes( $tables );
		foreach ( $required_indexes as $requirement ) {
			if ( ! self::index_matches( $requirement[0], $requirement[1], $requirement[2], $requirement[3] ) ) {
				self::$last_failure = 'required index mismatch ' . $requirement[0] . '.' . $requirement[1] . '.';
				self::$ready_cache  = false;
				return false;
			}
		}

		self::$ready_cache = true;
		return true;
	}

	/**
	 * Return the last validation failure in this request.
	 *
	 * @return string
	 */
	public static function last_failure() {
		return (string) self::$last_failure;
	}


	/**
	 * Complete required index contract for Storage Schema v15.
	 *
	 * These indexes are either uniqueness/ownership constraints or are aligned with
	 * hot runtime ordering/filtering paths. Keeping the list in one place makes both
	 * repair and readiness validation enforce the same contract.
	 *
	 * @param array $tables Resolved table names.
	 * @return array
	 */
	private static function required_indexes( $tables ) {
		return array(
			array( $tables['connections'], 'connection_key', true, array( 'connection_key' ) ),
			array( $tables['connections'], 'webhook_id', true, array( 'webhook_id' ) ),
			array( $tables['connections'], 'source_key', false, array( 'source_key', 'id' ) ),
			array( $tables['connections'], 'provider_key', false, array( 'provider_key', 'id' ) ),
			array( $tables['connections'], 'license_token', false, array( 'license_token', 'id' ) ),
			array( $tables['connections'], 'enabled_default', false, array( 'is_enabled', 'is_default', 'id' ) ),
			array( $tables['connections'], 'updated_at', false, array( 'updated_at', 'id' ) ),
			array( $tables['connection_settings'], 'connection_setting', true, array( 'connection_id', 'setting_key' ) ),
			array( $tables['connection_settings'], 'setting_key', false, array( 'setting_key', 'connection_id' ) ),
			array( $tables['connection_settings'], 'updated_at', false, array( 'updated_at', 'id' ) ),
			array( $tables['products'], 'product_guid', true, array( 'connection_id', array( 'product_guid', 180 ) ) ),
			array( $tables['products'], 'woo_product_id', true, array( 'woo_product_id' ) ),
			array( $tables['products'], 'portal_product_id', false, array( 'connection_id', 'portal_product_id' ) ),
			array( $tables['products'], 'simple_variant_guid', true, array( 'connection_id', array( 'simple_variant_guid', 180 ) ) ),
			array( $tables['products'], 'simple_portal_variant_id', false, array( 'connection_id', 'simple_portal_variant_id' ) ),
			array( $tables['products'], 'sync_due', false, array( 'connection_id', 'sync_status', 'next_attempt_at', 'id' ) ),
			array( $tables['products'], 'revision_due', false, array( 'connection_id', 'desired_revision', 'applied_revision', 'id' ) ),
			array( $tables['products'], 'claim_lookup', false, array( 'connection_id', 'claimed_at', 'claim_token' ) ),
			array( $tables['variants'], 'variant_guid', true, array( 'connection_id', array( 'variant_guid', 180 ) ) ),
			array( $tables['variants'], 'woo_variation_id', true, array( 'woo_variation_id' ) ),
			array( $tables['variants'], 'product_guid', false, array( 'connection_id', array( 'product_guid', 180 ) ) ),
			array( $tables['variants'], 'woo_parent', false, array( 'connection_id', 'woo_product_id', 'woo_variation_id' ) ),
			array( $tables['variants'], 'portal_variant_id', false, array( 'connection_id', 'portal_variant_id' ) ),
			array( $tables['variants'], 'sync_due', false, array( 'connection_id', 'sync_status', 'next_attempt_at', 'id' ) ),
			array( $tables['variants'], 'revision_due', false, array( 'connection_id', 'desired_revision', 'applied_revision', 'id' ) ),
			array( $tables['variants'], 'claim_lookup', false, array( 'connection_id', 'claimed_at', 'claim_token' ) ),
			array( $tables['jobs'], 'active_dedupe', true, array( 'connection_id', array( 'dedupe_key', 180 ) ) ),
			array( $tables['jobs'], 'status_available', false, array( 'connection_id', 'status', 'available_at', 'priority', 'id' ) ),
			array( $tables['jobs'], 'claim_expiry', false, array( 'connection_id', 'status', 'claim_expires_at', 'id' ) ),
			array( $tables['jobs'], 'entity_status', false, array( 'connection_id', 'entity_type', array( 'entity_guid', 110 ), 'status' ) ),
			array( $tables['jobs'], 'woo_object_id', false, array( 'connection_id', 'woo_object_id' ) ),
			array( $tables['media'], 'media_guid', true, array( 'connection_id', array( 'media_guid', 180 ) ) ),
			array( $tables['media'], 'product_guid', false, array( 'connection_id', array( 'product_guid', 145 ) ) ),
			array( $tables['media'], 'variant_guid', false, array( 'connection_id', array( 'variant_guid', 145 ) ) ),
			array( $tables['media'], 'woo_attachment_id', false, array( 'woo_attachment_id' ) ),
			array( $tables['media'], 'sync_due', false, array( 'connection_id', 'sync_status', 'desired_revision', 'applied_revision', 'id' ) ),
			array( $tables['media'], 'source_hash', false, array( 'connection_id', 'source_hash' ) ),
			array( $tables['order_dispatch'], 'woo_order_id', true, array( 'woo_order_id' ) ),
			array( $tables['order_dispatch'], 'status_due', false, array( 'connection_id', 'dispatch_status', 'next_attempt_at', 'id' ) ),
			array( $tables['order_dispatch'], 'classification', false, array( 'connection_id', 'classification' ) ),
			array( $tables['order_dispatch'], 'request_id', false, array( array( 'request_id', 150 ) ) ),
			array( $tables['order_dispatch'], 'portal_order_id', false, array( array( 'portal_order_id', 150 ) ) ),
			array( $tables['runtime'], 'worker_name', true, array( 'connection_id', 'worker_name' ) ),
			array( $tables['runtime'], 'circuit_due', false, array( 'connection_id', 'circuit_state', 'circuit_open_until', 'id' ) ),
			array( $tables['runtime'], 'updated_at', false, array( 'updated_at' ) ),
			array( $tables['migrations'], 'migration_key', true, array( 'connection_id', array( 'migration_key', 180 ) ) ),
			array( $tables['migrations'], 'status_updated', false, array( 'connection_id', 'status', 'updated_at', 'id' ) ),
			array( $tables['state'], 'state_key', true, array( 'connection_id', array( 'state_key', 180 ) ) ),
			array( $tables['state'], 'updated_at', false, array( 'connection_id', 'updated_at', 'id' ) ),
			array( $tables['work_queue'], 'queue_dedupe', true, array( 'connection_id', 'queue_type', 'dedupe_hash' ) ),
			array( $tables['work_queue'], 'queue_due', false, array( 'connection_id', 'queue_type', 'available_at', 'id' ) ),
			array( $tables['work_queue'], 'queue_order', false, array( 'connection_id', 'queue_type', 'queued_at_us', 'id' ) ),
			array( $tables['work_queue'], 'queue_object', false, array( 'connection_id', 'queue_type', 'object_id', 'id' ) ),
			array( $tables['work_queue'], 'claim_expiry', false, array( 'connection_id', 'queue_type', 'claim_expires_at', 'id' ) ),
			array( $tables['seen_variants'], 'scope_variant', true, array( 'connection_id', 'scope_hash', 'variant_hash' ) ),
			array( $tables['seen_variants'], 'scope_marker', false, array( 'connection_id', 'scope_hash', 'is_scope_marker', 'id' ) ),
			array( $tables['seen_variants'], 'product_scope', false, array( 'connection_id', array( 'product_guid', 110 ), 'scope_hash' ) ),
			array( $tables['events'], 'event_recent', false, array( 'connection_id', 'event_type', 'id' ) ),
			array( $tables['events'], 'created_at', false, array( 'connection_id', 'created_at', 'id' ) ),
			array( $tables['entity_meta'], 'legacy_meta_id', true, array( 'legacy_meta_id' ) ),
			array( $tables['entity_meta'], 'legacy_origin_meta_id', true, array( 'legacy_origin_meta_id' ) ),
			array( $tables['entity_meta'], 'object_key', false, array( 'woo_object_id', array( 'meta_key', 120 ), 'sort_order', 'id' ) ),
			array( $tables['entity_meta'], 'object_order', false, array( 'woo_object_id', 'sort_order', 'id' ) ),
			array( $tables['entity_meta'], 'key_object', false, array( array( 'meta_key', 120 ), 'woo_object_id', 'id' ) ),
			array( $tables['entity_meta'], 'object_type', false, array( 'object_type', 'woo_object_id', 'id' ) ),
		);
	}

	/**
	 * Best-effort atomic repair of required indexes before readiness validation.
	 * Validation remains fail-closed if a host rejects the DDL.
	 *
	 * @param array $tables Resolved table names.
	 * @return void
	 */
	private static function repair_required_indexes( $tables ) {
		foreach ( self::required_indexes( $tables ) as $requirement ) {
			if ( self::index_matches( $requirement[0], $requirement[1], $requirement[2], $requirement[3] ) ) {
				continue;
			}
			self::repair_index( $requirement[0], $requirement[1], $requirement[2], $requirement[3] );
		}
	}

	/**
	 * Atomically create or replace one Mobo-owned required index.
	 *
	 * Prefix lengths from the CREATE TABLE compatibility DDL are preserved exactly for
	 * automatic repair so shared-host utf8mb4 key-length compatibility is retained.
	 *
	 * @param string $table Table name.
	 * @param string $index Index name.
	 * @param bool   $unique Expected uniqueness.
	 * @param array  $columns Ordered columns.
	 * @return bool
	 */
	private static function repair_index( $table, $index, $unique, $columns ) {
		global $wpdb;

		$table = trim( (string) $table );
		$index = trim( (string) $index );
		if ( '' === $table || '' === $index || empty( $columns ) ) {
			return false;
		}

		$quoted_table = '`' . str_replace( '`', '``', $table ) . '`';
		$quoted_index = '`' . str_replace( '`', '``', $index ) . '`';
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$quoted_table}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return false;
		}

		$exists = false;
		foreach ( $rows as $row ) {
			$key = isset( $row['Key_name'] ) ? (string) $row['Key_name'] : ( isset( $row['KEY_NAME'] ) ? (string) $row['KEY_NAME'] : '' );
			if ( $index === $key ) {
				$exists = true;
				break;
			}
		}

		$column_sql = array();
		foreach ( $columns as $column_spec ) {
			$column = is_array( $column_spec ) ? trim( (string) ( $column_spec[0] ?? '' ) ) : trim( (string) $column_spec );
			$prefix = is_array( $column_spec ) && isset( $column_spec[1] ) ? absint( $column_spec[1] ) : 0;
			if ( '' === $column ) {
				return false;
			}
			$column_sql[] = '`' . str_replace( '`', '``', $column ) . '`' . ( $prefix > 0 ? '(' . $prefix . ')' : '' );
		}

		$definition = 'ADD ' . ( $unique ? 'UNIQUE KEY ' : 'KEY ' ) . $quoted_index . ' (' . implode( ', ', $column_sql ) . ')';
		$sql = $exists
			? "ALTER TABLE {$quoted_table} DROP INDEX {$quoted_index}, {$definition}"
			: "ALTER TABLE {$quoted_table} {$definition}";

		if ( false === $wpdb->query( $sql ) ) {
			return false;
		}
		return self::index_matches( $table, $index, $unique, $columns );
	}

	/**
	 * Verify ordered index columns and uniqueness. Prefix lengths are validated when a required index intentionally uses one.
	 * This keeps automatic repair compatible with utf8mb4 key-length constraints and
	 * prevents a same-name but materially different index from passing readiness.
	 *
	 * @param string $table Table name.
	 * @param string $index Index name.
	 * @param bool   $unique Expected uniqueness.
	 * @param array  $columns Expected ordered columns.
	 * @return bool
	 */
	private static function index_matches( $table, $index, $unique, $columns ) {
		global $wpdb;

		$quoted = '`' . str_replace( '`', '``', (string) $table ) . '`';
		$rows   = $wpdb->get_results( "SHOW INDEX FROM {$quoted}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return false;
		}

		$found = array();
		foreach ( $rows as $row ) {
			$key = isset( $row['Key_name'] ) ? (string) $row['Key_name'] : ( isset( $row['KEY_NAME'] ) ? (string) $row['KEY_NAME'] : '' );
			if ( $index === $key ) {
				$found[] = $row;
			}
		}

		usort(
			$found,
			static function ( $a, $b ) {
				$left  = isset( $a['Seq_in_index'] ) ? absint( $a['Seq_in_index'] ) : absint( isset( $a['SEQ_IN_INDEX'] ) ? $a['SEQ_IN_INDEX'] : 0 );
				$right = isset( $b['Seq_in_index'] ) ? absint( $b['Seq_in_index'] ) : absint( isset( $b['SEQ_IN_INDEX'] ) ? $b['SEQ_IN_INDEX'] : 0 );
				return $left <=> $right;
			}
		);

		if ( count( $found ) !== count( $columns ) ) {
			return false;
		}

		foreach ( array_values( $columns ) as $offset => $expected_spec ) {
			$row             = $found[ $offset ];
			$expected        = is_array( $expected_spec ) ? (string) ( $expected_spec[0] ?? '' ) : (string) $expected_spec;
			$expected_prefix = is_array( $expected_spec ) && isset( $expected_spec[1] ) ? absint( $expected_spec[1] ) : 0;
			$column          = isset( $row['Column_name'] ) ? (string) $row['Column_name'] : ( isset( $row['COLUMN_NAME'] ) ? (string) $row['COLUMN_NAME'] : '' );
			$non_unique      = isset( $row['Non_unique'] ) ? absint( $row['Non_unique'] ) : absint( isset( $row['NON_UNIQUE'] ) ? $row['NON_UNIQUE'] : 1 );
			$actual_prefix   = array_key_exists( 'Sub_part', $row ) ? ( null === $row['Sub_part'] ? 0 : absint( $row['Sub_part'] ) ) : ( array_key_exists( 'SUB_PART', $row ) ? ( null === $row['SUB_PART'] ? 0 : absint( $row['SUB_PART'] ) ) : 0 );
			if ( $expected !== $column || $expected_prefix !== $actual_prefix ) {
				return false;
			}
			if ( $unique ? 0 !== $non_unique : 1 !== $non_unique ) {
				return false;
			}
		}

		return true;
	}
}
