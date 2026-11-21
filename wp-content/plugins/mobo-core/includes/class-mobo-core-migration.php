<?php
/**
 * Migration helper.
 *
 * Responsibilities:
 * - create missing defaults
 * - create/update local data directories
 * - discard legacy plugin-directory webhook queue files that are no longer needed
 * - create/update local sync database tables
 * - seed product/variation map from legacy meta in bounded batches
 * - clear old WP-Cron hooks from previous versions
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/*
 * This component operates on Mobo Core's internal queue/map tables. Direct
 * database access is required for atomic batching and cursor updates; table
 * identifiers are generated internally and all external values are prepared.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
class Mobo_Core_Migration {

	/** Shared-host-safe fallback mutex when connection-scoped MySQL locks are unavailable. */
	const MIGRATION_LOCK_OPTION = 'mobo_core_migration_request_lock_v1';

	/** @var bool Request-local flag enabling larger retirement batches inside a bounded cron migration slice. */
	private static $accelerated_context = false;

	/** @var string Outer migration mutex owned by the current budgeted slice. */
	private static $budgeted_slice_lock_token = '';

	/** @var array|null Lightweight per-slice benchmark telemetry; null outside accelerated migration. */
	private static $slice_telemetry = null;

	/** @var float Absolute microtime after which maybe_run() stops between steps (0 = no deadline). */
	private static $pass_deadline = 0.0;

	/* Prefix of the (real) schema-postcondition failure message. */
	const POSTCONDITION_ERROR_PREFIX = 'Mobo Core database migration did not satisfy required schema postconditions';

	/* Seed batch per object type while a pass deadline drives the Product Map seed. */
	const SEED_DEADLINE_BATCH = 500;

	/* Fallback lease only. Normal MySQL/MariaDB installs use a connection-scoped
	 * advisory lock that disappears automatically when a killed PHP request loses
	 * its DB connection. Keep the fallback lease bounded for unusual hosts where
	 * GET_LOCK() is unavailable. */
	const MIGRATION_LOCK_TTL    = 180;

	/* Ordinary (non-cron) requests run at most one full pipeline pass per this many
	 * seconds site-wide while the DB version is unstamped. The real runner's
	 * budgeted slice is not throttled. */
	const ORDINARY_PASS_INTERVAL = 60;

	/* Bounds for the post-retirement convergence self-heal / singleton dedupe. */
	const SELFHEAL_EVENT_SCAN_CAP     = 2000;
	const SELFHEAL_PAYLOAD_LOOKUP_CAP = 100;
	const SINGLETON_DEDUPE_WINDOW     = 5000;
	const ORDINARY_PASS_OPTION   = 'mobo_core_migration_last_ordinary_pass_at';

	/* Durable cache of the legacy runtime-table schema validation (~100 SHOW
	 * queries) keyed by plugin version, so unstamped requests do not repeat it. */
	const RUNTIME_SCHEMA_VALIDATED_OPTION = 'mobo_core_runtime_schema_validated_v1';
	const RUNTIME_SCHEMA_VALIDATED_TTL    = 21600;

	/** @var string Last concrete schema validation/repair failure for diagnostics. */
	private static $schema_last_failure = '';

	/**
	 * Let the direct local PHP cron claim the dispatcher before any pending-only
	 * init relay. No DB writes or lease changes happen in this predicate.
	 *
	 * @param bool $pending_only_relay True only when pending-dispatch is the
	 *                                 sole work reason.
	 * @return bool
	 */
	public static function should_defer_pending_relay_to_local_cron( $pending_only_relay ) {
		return (bool) $pending_only_relay
			&& defined( 'MOBO_CORE_LOCAL_PHP_CRON' )
			&& true === MOBO_CORE_LOCAL_PHP_CRON;
	}

	/**
	 * Activation hook.
	 *
	 * Activation must be safe for existing customer installs. It must never delete
	 * the active uploads-based webhook queue. Legacy plugin-directory JSON files
	 * from 7.4 are intentionally discarded because they are no longer required.
	 *
	 * @return void
	 */
	public static function activate() {
		/* Preserve the pre-activation version before finalize_database_version() stamps
		 * the new build. Recovery migrations must make their upgrade/fresh-install
		 * decision from this value, not from the version written at the end. */
		$previous_version = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		$fresh_install = '' === $previous_version
			&& false === get_option( 'mobo_core_103412_connection_migrated_at', false )
			&& false === get_option( 'global_product_auto_title', false )
			&& false === get_option( 'global_product_auto_slug', false )
			&& false === get_option( 'mobo_price_type', false );

		self::ensure_defaults();
		self::apply_10331613_runtime_option_autoload_cleanup( '' );
		self::apply_10307_default_adjustments( '' );
		self::ensure_cron_token();
		self::ensure_webhook_dirs();
		self::cleanup_legacy_private_city_assets();
		self::cleanup_deprecated_pw_option_enforcement_state();
		$schema_ready = self::create_database_tables();
		$connection_foundation_ready = self::migrate_103412_connection_foundation();
		$connection_settings_ready = self::migrate_103413_connection_settings_foundation();
		if ( $fresh_install && $connection_settings_ready ) {
			self::apply_fresh_install_product_content_defaults();
		}
		$connection_ownership_ready = self::migrate_103414_connection_ownership();
		$large_runtime_ready = self::migrate_103393_strict_large_runtime_cutover();
		self::apply_10331614_table_housekeeping_fast_path( '' );
		self::apply_103171_runtime_lock_recovery( '' );
		self::apply_103164_image_family_migration( '' );
		self::apply_103165_image_refresh_safety( '' );
		self::apply_103166_admin_health_defaults( '' );
		self::apply_103167_image_workflow_safety( '' );
		self::apply_103168_image_automation_safety( '' );
		self::apply_103177_desired_state_repair( '' );
		self::apply_103194_health_pull_only( '' );
		self::apply_103198_manual_initial_sync_safety( '' );
		self::apply_103199_image_queue_recovery( '' );
		self::apply_10333_archive_purge_interval_migration( '' );
		self::apply_10338_legacy_image_catalog_rescan( '' );
		self::apply_10339_image_reference_migration( '' );
		self::apply_103310_structured_image_reference_migration( '' );
		self::apply_103311_replaced_attachment_scan_runtime_safety( '' );
		self::apply_103313_persistent_delete_old_preference( '' );
		self::apply_103315_nonblocking_stage7( '' );
		self::apply_103316_automatic_stage7_convergence( '' );
		self::apply_1033161_install_bootstrap_safety( '' );
		self::apply_1033171_stage7_autodrain( '' );
		self::apply_103320_image_cleanup_recovery( '' );
		self::apply_103324_image_storage_integrity_reaudit( '' );
		self::apply_103325_system_integrity_safety( '' );
		self::apply_103328_legacy_map_reseed_safety( '' );
		/* Manual deactivate/replace/activate upgrades do not necessarily get a later
		 * maybe_run() with the old DB version. Schedule parent-product retention
		 * recovery here as well, using the captured pre-activation version. */
		self::apply_103329_product_retention_recovery( $previous_version );
		self::apply_103333_product_recovery_reaudit( $previous_version );
		self::apply_103335_variation_integrity_reaudit( $previous_version );
		self::apply_103336_recovery_state_selfheal( $previous_version );
		self::apply_103337_variation_integrity_reason_selfheal( $previous_version );
		self::apply_1033443_nullable_stock_webhook_recovery( $previous_version );
		self::apply_1033446_disable_reconciliation( $previous_version );
		self::apply_1033447_disable_product_recovery( $previous_version );
		self::apply_1033448_image_storage_preflight( $previous_version );
		self::apply_1033449_full_source_image_refresh( $previous_version );
		self::apply_10334411_retire_automatic_recovery_runtime( $previous_version );
		self::apply_10334412_persistence_integrity_backfill( $previous_version );
		self::apply_103353_health_snapshot_real_cron_ownership( $previous_version );
		self::apply_103354_health_legacy_cron_cleanup( $previous_version );
		self::apply_103357_failed_variant_checkpoint_recovery( $previous_version );
		self::apply_103358_image_prune_queue_schedule( $previous_version );
		self::apply_103353_convergence_residue_selfheal_schedule( $previous_version );
		self::apply_103454_convergence_residue_selfheal_schedule( $previous_version );
		self::apply_103454_singleton_meta_dedupe_schedule( $previous_version );
		self::apply_103455_release_residue_finalization_schedule( $previous_version );
		self::apply_103462_custom_state_residue_finalization_schedule( $previous_version );
		self::apply_103463_post_retirement_residue_finalization_schedule( $previous_version );
		self::apply_103464_historical_residue_finalization_schedule( $previous_version );
		self::apply_103465_variant_applied_state_residue_finalization_schedule( $previous_version );
		self::apply_103466_custom_variant_authority_residue_finalization_schedule( $previous_version );
		self::apply_103467_variant_checkpoint_order_residue_finalization_schedule( $previous_version );
		self::apply_103473_post_upgrade_convergence_recovery_schedule( $previous_version );
		self::maybe_mark_legacy_repair_required( '' );
		self::seed_product_map_from_legacy_meta();
		$operational_meta_ready = self::migrate_103394_operational_postmeta_retirement();
		$identity_meta_ready = self::migrate_103395_identity_postmeta_retirement();
		$entity_meta_ready = self::migrate_103396_entity_postmeta_retirement();
		self::seed_category_map_from_legacy_meta();
		self::discard_legacy_webhook_queue();
		self::clear_legacy_cron_hooks();

		/* Final replay after historical option migrations: dynamic Source-owned options
		 * created/reset later in this pipeline must have durable Default Connection rows
		 * before the contract marker and DB version are treated as converged. */
		$connection_settings_ready = self::migrate_103413_connection_settings_foundation() && $connection_settings_ready;
		if ( $fresh_install && $connection_settings_ready ) {
			self::apply_fresh_install_product_content_defaults();
		}

		self::finalize_database_version( $schema_ready && $connection_foundation_ready && $connection_settings_ready && $connection_ownership_ready && $large_runtime_ready && $operational_meta_ready && $identity_meta_ready && $entity_meta_ready );
	}

	/**
	 * Run lightweight migrations if version changed.
	 *
	 * Important:
	 * This method never deletes the active uploads-based webhook queue. It only
	 * discards legacy plugin-directory JSON files from 7.4.
	 *
	 * @return void
	 */
	public static function maybe_run() {
		$current = get_option( 'mobo_core_db_version', '' );

		if ( MOBO_CORE_VERSION === $current ) {
			return;
		}

		/* A budgeted cron slice owns the same mutex across all of its direct worker
		 * iterations. In that context maybe_run() reuses the outer lease instead of
		 * dropping and reacquiring it between batches. Ordinary requests continue to
		 * acquire/release the mutex exactly as before. */
		$outer_lock_owned = '' !== self::$budgeted_slice_lock_token;
		$lock_token = '';
		if ( ! $outer_lock_owned ) {
			/* Never write the database while an upgrade/Unicode barrier drains or
			 * replaces files; the pass resumes once the barrier is released. */
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				return;
			}
			/* Bounded plugins_loaded work: one full pass per ORDINARY_PASS_INTERVAL
			 * site-wide instead of ~400 queries on every storefront page view. */
			$last_pass = absint( get_option( self::ORDINARY_PASS_OPTION, 0 ) );
			if ( ! self::ordinary_pass_due( $last_pass, time() ) ) {
				return;
			}
			$lock_token = self::acquire_migration_request_lock();
			if ( '' === $lock_token ) {
				return;
			}
			update_option( self::ORDINARY_PASS_OPTION, time(), false );
		}

		try {
		self::ensure_defaults();
		self::apply_10331613_runtime_option_autoload_cleanup( $current );
		self::apply_10307_default_adjustments( $current );
		self::ensure_cron_token();
		self::ensure_webhook_dirs();
		self::cleanup_legacy_private_city_assets();
		self::cleanup_deprecated_pw_option_enforcement_state();
		$schema_ready = self::create_database_tables();
		/* Deadline checkpoints (budgeted cron slice only): every step is idempotent
		 * and resumable, so stopping between steps and resuming on the next request
		 * is safe; finalize_database_version() simply does not run this time. */
		if ( self::pass_deadline_reached() ) { return; }
		$connection_foundation_ready = self::migrate_103412_connection_foundation();
		$connection_settings_ready = self::migrate_103413_connection_settings_foundation();
		$connection_ownership_ready = self::migrate_103414_connection_ownership();
		if ( self::pass_deadline_reached() ) { return; }
		$large_runtime_ready = self::migrate_103393_strict_large_runtime_cutover();
		if ( self::pass_deadline_reached() ) { return; }
		self::apply_10331614_table_housekeeping_fast_path( $current );
		self::apply_103171_runtime_lock_recovery( $current );
		self::apply_103164_image_family_migration( $current );
		self::apply_103165_image_refresh_safety( $current );
		self::apply_103166_admin_health_defaults( $current );
		self::apply_103167_image_workflow_safety( $current );
		self::apply_103168_image_automation_safety( $current );
		self::apply_103177_desired_state_repair( $current );
		self::apply_103194_health_pull_only( $current );
		self::apply_103198_manual_initial_sync_safety( $current );
		self::apply_103199_image_queue_recovery( $current );
		self::apply_10333_archive_purge_interval_migration( $current );
		self::apply_10338_legacy_image_catalog_rescan( $current );
		self::apply_10339_image_reference_migration( $current );
		self::apply_103310_structured_image_reference_migration( $current );
		self::apply_103311_replaced_attachment_scan_runtime_safety( $current );
		self::apply_103313_persistent_delete_old_preference( $current );
		self::apply_103315_nonblocking_stage7( $current );
		self::apply_103316_automatic_stage7_convergence( $current );
		self::apply_1033161_install_bootstrap_safety( $current );
		self::apply_1033171_stage7_autodrain( $current );
		self::apply_103320_image_cleanup_recovery( $current );
		self::apply_103324_image_storage_integrity_reaudit( $current );
		self::apply_103325_system_integrity_safety( $current );
		self::apply_103328_legacy_map_reseed_safety( $current );
		self::apply_103329_product_retention_recovery( $current );
		self::apply_103333_product_recovery_reaudit( $current );
		self::apply_103335_variation_integrity_reaudit( $current );
		self::apply_103336_recovery_state_selfheal( $current );
		self::apply_103337_variation_integrity_reason_selfheal( $current );
		self::apply_1033443_nullable_stock_webhook_recovery( $current );
		self::apply_1033446_disable_reconciliation( $current );
		self::apply_1033447_disable_product_recovery( $current );
		self::apply_1033448_image_storage_preflight( $current );
		self::apply_1033449_full_source_image_refresh( $current );
		self::apply_10334411_retire_automatic_recovery_runtime( $current );
		self::apply_10334412_persistence_integrity_backfill( $current );
		self::apply_103353_health_snapshot_real_cron_ownership( $current );
		self::apply_103354_health_legacy_cron_cleanup( $current );
		self::apply_103357_failed_variant_checkpoint_recovery( $current );
		self::apply_103358_image_prune_queue_schedule( $current );
		self::apply_103353_convergence_residue_selfheal_schedule( $current );
		self::apply_103454_convergence_residue_selfheal_schedule( $current );
		self::apply_103454_singleton_meta_dedupe_schedule( $current );
		self::apply_103455_release_residue_finalization_schedule( $current );
		self::apply_103462_custom_state_residue_finalization_schedule( $current );
		self::apply_103463_post_retirement_residue_finalization_schedule( $current );
		self::apply_103464_historical_residue_finalization_schedule( $current );
		self::apply_103465_variant_applied_state_residue_finalization_schedule( $current );
		self::apply_103466_custom_variant_authority_residue_finalization_schedule( $current );
		self::apply_103467_variant_checkpoint_order_residue_finalization_schedule( $current );
		self::apply_103473_post_upgrade_convergence_recovery_schedule( $current );
		self::maybe_mark_legacy_repair_required( $current );
		if ( self::pass_deadline_reached() ) { return; }
		self::seed_product_map_from_legacy_meta();
		if ( self::pass_deadline_reached() ) { return; }
		$operational_meta_ready = self::migrate_103394_operational_postmeta_retirement();
		if ( self::pass_deadline_reached() ) { return; }
		$identity_meta_ready = self::migrate_103395_identity_postmeta_retirement();
		if ( self::pass_deadline_reached() ) { return; }
		$entity_meta_ready = self::migrate_103396_entity_postmeta_retirement();
		if ( self::pass_deadline_reached() ) { return; }
		self::seed_category_map_from_legacy_meta();
		self::discard_legacy_webhook_queue();
		self::clear_legacy_cron_hooks();
		if ( self::pass_deadline_reached() ) { return; }

		/*
		 * Cleanup old beta option if it exists.
		 * WP-Cron is not used in final architecture.
		 */
		delete_option( 'mobo_core_enable_wp_cron' );

		/* Reconcile after every historical migration has had a chance to create or
		 * reset dynamic Source-owned options. The migration is missing-row-only. */
		$connection_settings_ready = self::migrate_103413_connection_settings_foundation() && $connection_settings_ready;

		self::finalize_database_version( $schema_ready && $connection_foundation_ready && $connection_settings_ready && $connection_ownership_ready && $large_runtime_ready && $operational_meta_ready && $identity_meta_ready && $entity_meta_ready );
		} finally {
			if ( ! $outer_lock_owned ) {
				self::release_migration_request_lock( $lock_token );
			}
		}
	}


	/**
	 * Pure throttle decision for ordinary-request migration passes (unit tested).
	 *
	 * @param int $last_pass Unix time of the last ordinary pass (0 = never).
	 * @param int $now       Current Unix time.
	 * @return bool
	 */
	public static function ordinary_pass_due( $last_pass, $now ) {
		$last_pass = absint( $last_pass );
		$now = absint( $now );
		if ( $last_pass <= 0 || $last_pass > $now + 60 ) { return true; }
		return ( $now - $last_pass ) >= self::ORDINARY_PASS_INTERVAL;
	}

	/**
	 * Pure checkpoint decision (unit tested).
	 *
	 * @param float $deadline Absolute microtime deadline; <= 0 means none.
	 * @param float $now      Current microtime.
	 * @return bool
	 */
	public static function deadline_reached( $deadline, $now ) {
		$deadline = (float) $deadline;
		return $deadline > 0 && (float) $now >= $deadline;
	}

	/** @return bool Whether the current budgeted pass must stop before its next step. */
	private static function pass_deadline_reached() {
		return self::deadline_reached( self::$pass_deadline, microtime( true ) );
	}

	/**
	 * Whether retirement workers may use their accelerated batch sizes.
	 * Normal storefront/admin requests remain on the conservative batch sizes;
	 * only the explicit budgeted migration slice enables this flag.
	 *
	 * @return bool
	 */
	public static function is_accelerated_context() {
		return (bool) self::$accelerated_context;
	}

	/**
	 * Whether real Cron should enter the bounded migration/recovery slice. Even after
	 * the plugin DB version is current, terminal workers are lightly re-probed so an
	 * abnormally late pre-fence Legacy write cannot remain stranded forever.
	 *
	 * @return bool
	 */
	public static function needs_budgeted_migration_slice() {
		if ( MOBO_CORE_VERSION !== trim( (string) get_option( 'mobo_core_db_version', '' ) ) ) { return true; }
		if ( ! self::terminal_retirements_physically_complete() ) { return true; }
		if ( self::deferred_release_repairs_pending() ) { return true; }
		/* Periodic re-probe of objects marked during retirement (bounded, post-stamp only). */
		return class_exists( 'Mobo_Core_Migration_Quarantine' ) && Mobo_Core_Migration_Quarantine::reprobe_due();
	}

	/**
	 * Whether the ordinary runner (webhooks, sync, orders, repair) may run in this
	 * request even though migration work is still pending.
	 *
	 * Safety reasoning (10.34.77 hardening):
	 * - After the DB version is stamped every terminal fence is active, writes go
	 *   to Custom Storage and the remaining retirement work is physical cleanup of
	 *   rows the read/write filters already hide. Normal work is always safe.
	 * - Before the stamp, the 10.34.77 runtime is already executing on every
	 *   storefront/admin/REST request (the old code is gone after the update), so
	 *   the in-between state is a designed runtime state: pre-fence workers keep
	 *   Storage Mode at dual-write (Legacy authoritative, Custom mirrored) and each
	 *   fence switches authority atomically through durable markers and metadata
	 *   filters. Proof pages verify every mirror against a fresh snapshot, so a
	 *   concurrent Mobo write causes a retry, not divergence. What the runner truly
	 *   depends on are the structural prerequisites checked below: Custom schema,
	 *   Connection rows/settings/ownership and the large-runtime table cutover.
	 * - The legacy Product Map seed is NOT a runtime prerequisite any more: product
	 *   and variation lookups resolve unmapped objects through their legacy identity
	 *   (and lazily write the map row), so stock/price updates, orders and webhooks
	 *   for existing products are safe. Only *creating* a new product could duplicate
	 *   an unmapped one, so Product Sync defers product creation alone until the seed
	 *   completes (Mobo_Core_Product_Sync::product_creation_allowed()).
	 *
	 * Options-only checks plus the cached schema validation: no catalog scan.
	 *
	 * @param string|null $reason Receives a short machine reason when not ready.
	 * @return bool
	 */
	public static function normal_runtime_ready( &$reason = null ) {
		$reason = '';
		$db_version = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		if ( MOBO_CORE_VERSION === $db_version ) { return true; }
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) { $reason = 'custom-storage-schema-not-ready'; return false; }
		if ( absint( get_option( 'mobo_core_103412_connection_migrated_at', 0 ) ) <= 0 ) { $reason = 'connection-foundation-pending'; return false; }
		if ( defined( 'MOBO_CORE_CONNECTION_SETTINGS_CONTRACT_VERSION' )
			&& (string) MOBO_CORE_CONNECTION_SETTINGS_CONTRACT_VERSION !== (string) get_option( 'mobo_core_connection_settings_contract_version', '' ) ) { $reason = 'connection-settings-pending'; return false; }
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::connection_ownership_ready() ) { $reason = 'connection-ownership-pending'; return false; }
		if ( ( '' === $db_version || version_compare( $db_version, '10.33.93', '<' ) )
			&& absint( get_option( 'mobo_core_103393_large_runtime_strict_cutover_at', 0 ) ) <= 0 ) { $reason = 'large-runtime-cutover-pending'; return false; }
		return true;
	}

	/**
	 * New/extra Connections are refused while the Default Connection's terminal
	 * retirement is unfinished (existing extra Connections keep working).
	 *
	 * @return bool
	 */
	public static function new_connections_blocked() {
		if ( MOBO_CORE_VERSION !== trim( (string) get_option( 'mobo_core_db_version', '' ) ) ) { return true; }
		return ! self::terminal_retirements_physically_complete();
	}

	/**
	 * Status of the migration request mutex for the upgrade quiescence drain.
	 *
	 * @return array{active:bool,mode:string,expiresAt:int,remainingSeconds:int}
	 */
	public static function get_migration_request_lock_status() {
		global $wpdb;
		$out = array( 'active' => false, 'mode' => '', 'acquiredAt' => 0, 'lastHeartbeatAt' => 0, 'expiresAt' => 0, 'remainingSeconds' => 0 );
		$wpdb->last_error = '';
		$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT IF(IS_USED_LOCK(%s)=CONNECTION_ID(),-1,IFNULL(IS_USED_LOCK(%s),0))', self::migration_lock_name(), self::migration_lock_name() ) );
		/* -1 means this very request owns it (it cannot wait for itself). */
		if ( '' === trim( (string) $wpdb->last_error ) && null !== $owner && (int) $owner > 0 ) {
			$out['active'] = true;
			$out['mode'] = 'mysql';
			$out['expiresAt'] = time() + self::MIGRATION_LOCK_TTL;
			$out['remainingSeconds'] = self::MIGRATION_LOCK_TTL;
			return $out;
		}
		$raw = self::read_fallback_migration_lock_raw();
		$decoded = '' !== $raw ? json_decode( $raw, true ) : null;
		$expires = is_array( $decoded ) ? absint( $decoded['expiresAt'] ?? 0 ) : 0;
		if ( $expires >= time() ) {
			$out['active'] = true;
			$out['mode'] = 'option';
			$out['expiresAt'] = $expires;
			$out['remainingSeconds'] = max( 0, $expires - time() );
		}
		return $out;
	}

	/** @return string Connection-scoped advisory lock name for this database/prefix. */
	private static function migration_lock_name() {
		global $wpdb;
		$db_name = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		return 'mobo_mig_' . substr( sha1( $db_name . '|' . (string) $wpdb->prefix ), 0, 40 );
	}

	/** Persist the compact status snapshot shown to admins and the Backend. @return void */
	private static function save_migration_status_snapshot( $workers ) {
		if ( ! class_exists( 'Mobo_Core_Migration_Quarantine' ) ) { return; }
		$reason = '';
		$ready = self::normal_runtime_ready( $reason );
		$compact = array();
		foreach ( (array) $workers as $name => $state ) {
			if ( ! is_array( $state ) ) { continue; }
			$compact[ sanitize_key( (string) $name ) ] = array(
				'status'        => sanitize_key( (string) ( $state['status'] ?? '' ) ),
				'fenced'        => ! empty( $state['fenced'] ),
				'complete'      => ! empty( $state['complete'] ),
				'lastError'     => sanitize_key( (string) ( $state['lastError'] ?? '' ) ),
				'blockers'      => isset( $state['blockers'] ) && is_array( $state['blockers'] ) ? array_map( 'absint', $state['blockers'] ) : array(),
				'markedObjects' => absint( $state['markedObjects'] ?? 0 ),
				'dedupedRows'   => absint( $state['dedupedRows'] ?? 0 ),
			);
		}
		Mobo_Core_Migration_Quarantine::save_status_snapshot( array(
			'databaseVersion'    => trim( (string) get_option( 'mobo_core_db_version', '' ) ),
			'targetVersion'      => MOBO_CORE_VERSION,
			'runtimeReady'       => $ready,
			'runtimeReason'      => $reason,
			'retirementComplete' => self::terminal_retirements_physically_complete(),
			'deferredRepairsPending' => self::deferred_release_repairs_pending(),
			'workers'            => $compact,
			'markedCount'        => Mobo_Core_Migration_Quarantine::count(),
		) );
	}

	/**
	 * Advance the retry-safe database migration repeatedly inside a strict wall-clock
	 * budget. This is intended for the existing real/cPanel cron path: it removes the
	 * old one-HTTP-request/one-migration-step coupling without requiring WP-CLI.
	 *
	 * Progress is persisted by the existing workers after every bounded batch. If a
	 * worker reaches a grace/wait/block/error state, or one iteration makes no durable
	 * progress, the slice stops immediately and the next cron request resumes it.
	 *
	 * @param string $source Source label for diagnostics.
	 * @param int    $time_budget_seconds Maximum wall-clock budget.
	 * @param int    $max_iterations Hard safety cap even on a very fast database.
	 * @return array
	 */
	public static function run_budgeted_slice( $source = 'real-cron', $time_budget_seconds = 12, $max_iterations = 200 ) {
		/* One correlation id per slice so errors and events of this slice can be
		 * followed in the remote diagnostics bundle. */
		$slice_id    = ( defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '0' ) . '-' . time();
		$correlation = class_exists( 'Mobo_Core_Correlation' ) ? Mobo_Core_Correlation::push( 'migration', $slice_id ) : 0;
		$started     = microtime( true );
		try {
			$result = self::run_budgeted_slice_scoped( $source, $time_budget_seconds, $max_iterations );
			if ( class_exists( 'Mobo_Core_Error_Log' ) ) {
				Mobo_Core_Error_Log::record_event( 'migration', $slice_id, is_array( $result ) && isset( $result['status'] ) ? (string) $result['status'] : 'unknown', (int) round( ( microtime( true ) - $started ) * 1000 ), array( 'source' => sanitize_key( (string) $source ) ) );
				Mobo_Core_Error_Log::capture_db_error( 'migration-slice' );
			}
			return $result;
		} finally {
			if ( $correlation > 0 ) {
				Mobo_Core_Correlation::pop( $correlation );
			}
		}
	}

	/**
	 * Body of run_budgeted_slice() (inside its correlation scope).
	 *
	 * @param string $source Source label.
	 * @param int    $time_budget_seconds Budget.
	 * @param int    $max_iterations Cap.
	 * @return array
	 */
	private static function run_budgeted_slice_scoped( $source, $time_budget_seconds, $max_iterations ) {
		global $wpdb;
		$source              = sanitize_key( (string) $source );
		$time_budget_seconds = max( 2, min( 25, absint( $time_budget_seconds ) ) );
		$max_iterations      = max( 1, min( 500, absint( $max_iterations ) ) );
		$started             = microtime( true );
		$deadline            = $started + $time_budget_seconds;
		/* The slice never outlives the request deadline (REQUEST_TIME_FLOAT based),
		 * whatever share the caller asked for. */
		if ( class_exists( 'Mobo_Core_Request_Deadline' ) ) {
			$deadline = min( $deadline, Mobo_Core_Request_Deadline::deadline() );
		}
		/* Never start a new migration batch at the hard request boundary. Individual
		 * SQL statements cannot be pre-empted safely by PHP, so reserve a small exit
		 * margin for the final checkpoint/release path. */
		$safety_margin       = min( 3.0, max( 1.0, max( 0.0, $deadline - $started ) * 0.20 ) );
		$work_deadline       = $deadline - $safety_margin;
		if ( $work_deadline <= $started + 0.25 ) {
			return array(
				'success' => true, 'status' => 'deferred-budget', 'source' => $source,
				'iterations' => 0, 'progressedIterations' => 0, 'elapsedMs' => 0,
				'databaseVersion' => trim( (string) get_option( 'mobo_core_db_version', '' ) ),
				'targetVersion' => MOBO_CORE_VERSION, 'needsContinuation' => true,
			);
		}
		$iterations          = 0;
		$memory_stopped      = false;
		$progressed          = 0;
		$pipeline_passes     = 0;
		$pipeline_elapsed_ms = 0;
		$db_queries_started  = isset( $wpdb->num_queries ) ? absint( $wpdb->num_queries ) : 0;
		$memory_started      = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0;

		$reprobe_due = class_exists( 'Mobo_Core_Migration_Quarantine' ) && Mobo_Core_Migration_Quarantine::reprobe_due();
		if ( MOBO_CORE_VERSION === trim( (string) get_option( 'mobo_core_db_version', '' ) )
			&& self::terminal_retirements_physically_complete()
			&& ! self::deferred_release_repairs_pending()
			&& ! $reprobe_due ) {
			return array(
				'success' => true, 'status' => 'complete', 'source' => $source,
				'iterations' => 0, 'progressedIterations' => 0, 'elapsedMs' => 0,
				'databaseVersion' => MOBO_CORE_VERSION, 'needsContinuation' => false,
			);
		}

		/* Hold the same migration mutex for the complete accelerated slice. Without
		 * this outer lease, a normal storefront/admin request could acquire maybe_run()
		 * between direct iterations and race the terminal worker state machine. */
		$lock_token = self::acquire_migration_request_lock();
		if ( '' === $lock_token ) {
			return array(
				'success' => true, 'status' => 'migration-locked', 'source' => $source,
				'iterations' => 0, 'progressedIterations' => 0,
				'elapsedMs' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'databaseVersion' => trim( (string) get_option( 'mobo_core_db_version', '' ) ),
				'targetVersion' => MOBO_CORE_VERSION, 'needsContinuation' => true,
				'workers' => self::migration_worker_statuses(),
			);
		}

		self::$budgeted_slice_lock_token = $lock_token;
		self::$slice_telemetry = array(
			'workerCalls' => array( 'operational' => 0, 'identity' => 0, 'entity' => 0 ),
			'workerElapsedMs' => array( 'operational' => 0, 'identity' => 0, 'entity' => 0 ),
		);
		$previous = self::migration_progress_fingerprint();
		self::$accelerated_context = true;
		/* The full pipeline pass (first and final iteration) stops between steps at
		 * the slice's work deadline instead of running unbounded (8 s share took 38 s). */
		self::$pass_deadline = $work_deadline;
		$reprobe = null;
		try {
			/* Post-stamp only: give marked objects their periodic chance to be released. */
			if ( $reprobe_due && self::refresh_migration_request_lock( $lock_token ) ) {
				$reprobe = Mobo_Core_Migration_Quarantine::run_reprobe_batch();
			}
			while ( $iterations < $max_iterations && microtime( true ) < $work_deadline ) {
				/* Re-validate/refresh the lease before every new unit of work. Advisory
				 * locks are connection-owned; fallback option leases are owner-checked. */
				if ( ! self::refresh_migration_request_lock( $lock_token ) ) {
					break;
				}
				/* Runtime-30s: never start another unit at >= 75 % of memory_limit (two
				 * 256 MB fatals on griffinstore). Drop request-local caches once and stop
				 * if that does not help; the durable cursors resume next request. */
				if ( $iterations > 0 && class_exists( 'Mobo_Core_Request_Deadline' ) && Mobo_Core_Request_Deadline::memory_pressure( 0.75 ) ) {
					self::release_request_memory();
					if ( Mobo_Core_Request_Deadline::memory_pressure( 0.75 ) ) {
						$memory_stopped = true;
						break;
					}
				}
				$iterations++;
				if ( 1 === $iterations ) {
					$pipeline_started = microtime( true );
					if ( MOBO_CORE_VERSION === trim( (string) get_option( 'mobo_core_db_version', '' ) ) ) {
						/* Version is already stamped: finish terminal retirement first, then
						 * drain bounded proof-only release repairs under the same Cron budget. */
						if ( self::terminal_retirements_physically_complete() ) {
							self::advance_deferred_release_repair_step();
						} else {
							self::advance_terminal_retirement_step();
						}
					} else {
						/* One full pipeline pass establishes/repairs all prerequisites. */
						self::maybe_run();
						$pipeline_passes++;
					}
					$pipeline_elapsed_ms += (int) round( ( microtime( true ) - $pipeline_started ) * 1000 );
				} elseif ( self::terminal_retirements_physically_complete() ) {
					self::advance_deferred_release_repair_step();
				} elseif ( ! self::advance_terminal_retirement_step() ) {
					/* All terminal workers are complete (or unavailable); one final full
					 * pass verifies postconditions and stamps the database version. */
					$pipeline_started = microtime( true );
					self::maybe_run();
					$pipeline_passes++;
					$pipeline_elapsed_ms += (int) round( ( microtime( true ) - $pipeline_started ) * 1000 );
				}
				$current = self::migration_progress_fingerprint();
				if ( $current !== $previous ) {
					$progressed++;
				}
				if ( MOBO_CORE_VERSION === trim( (string) get_option( 'mobo_core_db_version', '' ) )
					&& self::terminal_retirements_physically_complete()
					&& ! self::deferred_release_repairs_pending() ) {
					break;
				}
				if ( $current === $previous || self::migration_fingerprint_is_waiting( $current ) ) {
					break;
				}
				$previous = $current;
			}
		} finally {
			self::$accelerated_context = false;
			self::$pass_deadline = 0.0;
			self::$budgeted_slice_lock_token = '';
			self::release_migration_request_lock( $lock_token );
		}

		$version   = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		$complete  = MOBO_CORE_VERSION === $version
			&& self::terminal_retirements_physically_complete()
			&& ! self::deferred_release_repairs_pending();
		$telemetry = is_array( self::$slice_telemetry ) ? self::$slice_telemetry : array();
		self::$slice_telemetry = null;
		$telemetry['pipelinePasses'] = $pipeline_passes;
		$telemetry['pipelineElapsedMs'] = $pipeline_elapsed_ms;
		$telemetry['dbQueries'] = max( 0, ( isset( $wpdb->num_queries ) ? absint( $wpdb->num_queries ) : 0 ) - $db_queries_started );
		$telemetry['memoryStartBytes'] = absint( $memory_started );
		$telemetry['memoryStopped'] = $memory_stopped;
		$telemetry['memoryPeakBytes'] = function_exists( 'memory_get_peak_usage' ) ? absint( memory_get_peak_usage( true ) ) : 0;
		$workers = self::migration_worker_statuses();
		self::save_migration_status_snapshot( $workers );
		return array(
			'success'              => true,
			'status'               => $complete ? 'complete' : ( $progressed > 0 ? 'progressed' : 'waiting' ),
			'quarantine'           => array(
				'marked'  => class_exists( 'Mobo_Core_Migration_Quarantine' ) ? Mobo_Core_Migration_Quarantine::count() : 0,
				'reprobe' => $reprobe,
			),
			'source'               => $source,
			'iterations'           => $iterations,
			'progressedIterations' => $progressed,
			'elapsedMs'            => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'databaseVersion'      => $version,
			'targetVersion'        => MOBO_CORE_VERSION,
			'needsContinuation'    => ! $complete,
			'workers'              => $workers,
			'telemetry'            => $telemetry,
		);
	}



	/**
	 * Free request-local caches between migration units (memory pressure only).
	 * The WordPress runtime cache is flushed only when no persistent object cache
	 * is in use, so a shared Redis/Memcached cache is never wiped.
	 *
	 * @return void
	 */
	private static function release_request_memory() {
		if ( class_exists( 'Mobo_Core_Storage_Row_Cache', false ) ) {
			Mobo_Core_Storage_Row_Cache::flush_all();
		}
		$external = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		if ( ! $external && function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}
	}

	/** @return bool */
	private static function deferred_release_repairs_pending() {
		return '1' === (string) get_option( 'mobo_core_103353_convergence_residue_selfheal_pending', '0' )
			|| '1' === (string) get_option( 'mobo_core_103454_singleton_meta_dedupe_pending', '0' );
	}

	/**
	 * Advance one bounded proof-only post-upgrade repair after WooCommerce init.
	 *
	 * @return bool True when a repair worker was invoked.
	 */
	private static function advance_deferred_release_repair_step() {
		if ( ! did_action( 'woocommerce_init' ) ) {
			return false;
		}
		$invoked = false;
		if ( '1' === (string) get_option( 'mobo_core_103353_convergence_residue_selfheal_pending', '0' ) ) {
			$invoked = true;
			/* A fail-closed (false) self-heal pass must not starve the independent
			 * singleton normalization; fall through to it in the same step. */
			if ( self::run_103353_convergence_residue_selfheal() ) { return true; }
		}
		if ( '1' === (string) get_option( 'mobo_core_103454_singleton_meta_dedupe_pending', '0' ) ) {
			self::run_103454_singleton_meta_dedupe();
			return true;
		}
		return $invoked;
	}

	/**
	 * Advance only the currently active terminal postmeta retirement worker. This
	 * avoids replaying the complete historical migration pipeline for every batch
	 * inside a cron slice while preserving the same worker state machines.
	 *
	 * @return bool True when a terminal worker was invoked.
	 */
	private static function advance_terminal_retirement_step() {
		$workers = array(
			'operational' => array( 'Mobo_Core_Storage_Operational_Meta_Retirement', 'migrate_103394_operational_postmeta_retirement' ),
			'identity'    => array( 'Mobo_Core_Storage_Identity_Meta_Retirement', 'migrate_103395_identity_postmeta_retirement' ),
			'entity'      => array( 'Mobo_Core_Storage_Entity_Meta_Retirement', 'migrate_103396_entity_postmeta_retirement' ),
		);
		foreach ( $workers as $worker ) {
			$class = $worker[0];
			if ( ! class_exists( $class ) || ! method_exists( $class, 'get_status' ) || ! method_exists( $class, 'run_migration_step' ) ) { return false; }
			$status = call_user_func( array( $class, 'get_status' ) );
			$physical_complete = method_exists( $class, 'is_physically_retired' )
				? (bool) call_user_func( array( $class, 'is_physically_retired' ) )
				: ! empty( $status['complete'] );
			if ( ! $physical_complete ) {
				call_user_func( array( __CLASS__, $worker[1] ) );
				return true;
			}
		}
		return false;
	}

	/** @return bool */
	private static function terminal_retirements_physically_complete() {
		$classes = array(
			'Mobo_Core_Storage_Operational_Meta_Retirement',
			'Mobo_Core_Storage_Identity_Meta_Retirement',
			'Mobo_Core_Storage_Entity_Meta_Retirement',
		);
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) || ! method_exists( $class, 'is_physically_retired' ) || ! call_user_func( array( $class, 'is_physically_retired' ) ) ) { return false; }
		}
		return true;
	}

	/** @return array */
	private static function migration_worker_statuses() {
		$out = array();
		$classes = array(
			'operational' => 'Mobo_Core_Storage_Operational_Meta_Retirement',
			'identity'    => 'Mobo_Core_Storage_Identity_Meta_Retirement',
			'entity'      => 'Mobo_Core_Storage_Entity_Meta_Retirement',
		);
		foreach ( $classes as $key => $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'get_status' ) ) {
				$status = call_user_func( array( $class, 'get_status' ) );
				$out[ $key ] = is_array( $status ) ? $status : array();
			}
		}
		return $out;
	}

	/** @return string */
	private static function migration_progress_fingerprint() {
		$parts = array( trim( (string) get_option( 'mobo_core_db_version', '' ) ) );
		foreach ( self::migration_worker_statuses() as $name => $state ) {
			$parts[] = $name . ':' . wp_json_encode( array(
				'status' => isset( $state['status'] ) ? $state['status'] : '',
				'stateRevision' => isset( $state['stateRevision'] ) ? $state['stateRevision'] : 0,
				'proofCursor' => isset( $state['proofCursor'] ) ? $state['proofCursor'] : 0,
				'copyCursor' => isset( $state['copyCursor'] ) ? $state['copyCursor'] : 0,
				'cleanupCursor' => isset( $state['cleanupCursor'] ) ? $state['cleanupCursor'] : 0,
				'reconcileCursor' => isset( $state['reconcileCursor'] ) ? $state['reconcileCursor'] : 0,
				'backfillStatus' => isset( $state['backfillStatus'] ) ? $state['backfillStatus'] : '',
				'backfillCursor' => isset( $state['backfillCursor'] ) ? $state['backfillCursor'] : 0,
				'cleanedRows' => isset( $state['cleanedRows'] ) ? $state['cleanedRows'] : 0,
				'complete' => ! empty( $state['complete'] ),
				'lastError' => isset( $state['lastError'] ) ? $state['lastError'] : '',
			) );
		}
		$repair_result = get_option( 'mobo_core_103353_convergence_residue_selfheal', array() );
		$repair_result = is_array( $repair_result ) ? $repair_result : array();
		$singleton_result = get_option( 'mobo_core_103454_singleton_meta_dedupe', array() );
		$singleton_result = is_array( $singleton_result ) ? $singleton_result : array();
		$parts[] = 'releaseRepair:' . wp_json_encode( array(
			'convergencePending' => '1' === (string) get_option( 'mobo_core_103353_convergence_residue_selfheal_pending', '0' ),
			'convergenceCursor' => absint( get_option( 'mobo_core_103353_convergence_residue_selfheal_cursor', 0 ) ),
			'convergenceExamined' => absint( $repair_result['examined'] ?? 0 ),
			'convergenceHealed' => absint( $repair_result['healedCount'] ?? 0 ),
			'singletonPending' => '1' === (string) get_option( 'mobo_core_103454_singleton_meta_dedupe_pending', '0' ),
			'singletonRemaining' => absint( $singleton_result['remaining'] ?? 0 ),
			'singletonCursor' => absint( get_option( 'mobo_core_103454_singleton_meta_dedupe_cursor', 0 ) ),
			'marked' => class_exists( 'Mobo_Core_Migration_Quarantine' ) ? Mobo_Core_Migration_Quarantine::count() : 0,
		) );
		/* Seed cursors are durable progress too: a slice whose first pass only advanced
		 * the Product Map seed may continue with the next unit of work. */
		$parts[] = 'seed:' . absint( get_option( 'mobo_core_product_map_product_cursor', 0 ) )
			. ':' . absint( get_option( 'mobo_core_product_map_variation_cursor', 0 ) )
			. ':' . ( absint( get_option( 'mobo_core_product_map_seed_completed_at', 0 ) ) > 0 ? '1' : '0' );
		return implode( '|', $parts );
	}

	/**
	 * Whether the active terminal worker is intentionally waiting/blocked.
	 *
	 * Do not scan the serialized fingerprint for generic words such as "error":
	 * every fingerprint contains the JSON field name `lastError`, which made the
	 * accelerated Cron loop stop after its first iteration even when the value was
	 * empty. Inspect only actual status/error values so one real Cron request can
	 * consume its bounded wall-clock slice as designed.
	 *
	 * @param string $fingerprint Progress fingerprint (retained for call-site/API stability).
	 * @return bool
	 */
	private static function migration_fingerprint_is_waiting( $fingerprint ) {
		unset( $fingerprint );
		foreach ( self::migration_worker_statuses() as $state ) {
			if ( ! is_array( $state ) ) { continue; }
			$status = strtolower( sanitize_key( (string) ( $state['status'] ?? '' ) ) );
			$error  = trim( (string) ( $state['lastError'] ?? '' ) );
			if ( '' !== $error ) { return true; }
			if ( false !== strpos( $status, 'grace' ) ) { return true; }
			if ( 'waiting-backfill' === $status ) {
				/* A running backfill is active bounded work, not an external wait. The
				 * durable stateRevision/backfillCursor fingerprint proves progress, so
				 * the same Cron request may execute another small backfill unit while
				 * time remains. Any non-running backfill state still stops fail-closed. */
				$backfill_status = strtolower( sanitize_key( (string) ( $state['backfillStatus'] ?? '' ) ) );
				if ( 'running' === $backfill_status ) { continue; }
				return true;
			}
			if ( 0 === strpos( $status, 'waiting-' ) ) { return true; }
			if ( false !== strpos( $status, 'blocked' ) ) { return true; }
			if ( false !== strpos( $status, 'error' ) ) { return true; }
		}
		return false;
	}

	/**
	 * Acquire the migration mutex.
	 *
	 * Preferred path: connection-scoped MySQL/MariaDB GET_LOCK(). A dead DB
	 * connection releases it automatically and no WordPress cache participates.
	 * Compatibility path: an autoload=no wp_option lease using direct-DB CAS for
	 * stale reclaim and heartbeat. Direct reads are intentional: a long-lived PHP
	 * request must not trust a request-local get_option() cache for lock ownership.
	 *
	 * @return string Owner token, or empty string when another live request owns it.
	 */
	private static function acquire_migration_request_lock() {
		global $wpdb;

		$lock_name = self::migration_lock_name();
		$wpdb->last_error = '';
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) );
		if ( '' === trim( (string) $wpdb->last_error ) ) {
			if ( '1' === (string) $got ) {
				$token = 'mysql:' . $lock_name;
				Mobo_Core_Environment::on_shutdown( array( __CLASS__, 'release_migration_request_lock' ), $token );
				return $token;
			}
			/* GET_LOCK() returning 0 means another live connection owns the mutex.
			 * Do not fall back to a second lock namespace in that case. */
			if ( '0' === (string) $got ) { return ''; }
		}

		$owner = wp_generate_uuid4();
		$now   = time();
		$value = wp_json_encode( array( 'owner'=>$owner, 'expiresAt'=>$now + self::MIGRATION_LOCK_TTL ) );
		if ( ! is_string( $value ) ) { return ''; }
		if ( add_option( self::MIGRATION_LOCK_OPTION, $value, '', false ) ) {
			$token = 'option:' . $owner;
			Mobo_Core_Environment::on_shutdown( array( __CLASS__, 'release_migration_request_lock' ), $token );
			return $token;
		}

		$current = self::read_fallback_migration_lock_raw();
		$decoded = is_string( $current ) ? json_decode( $current, true ) : null;
		$expires = is_array( $decoded ) ? absint( $decoded['expiresAt'] ?? 0 ) : 0;
		if ( is_string( $current ) && '' !== $current && $expires >= $now ) { return ''; }

		/* Reclaim only the exact stale value we inspected. If another contender has
		 * refreshed/replaced it, the CAS delete affects zero rows and we back off. */
		if ( is_string( $current ) && '' !== $current ) {
			$deleted = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name=%s AND BINARY option_value=BINARY %s",
				self::MIGRATION_LOCK_OPTION,
				$current
			) );
			if ( 1 !== absint( $deleted ) ) { return ''; }
			self::invalidate_fallback_migration_lock_cache();
		}
		if ( add_option( self::MIGRATION_LOCK_OPTION, $value, '', false ) ) {
			$token = 'option:' . $owner;
			Mobo_Core_Environment::on_shutdown( array( __CLASS__, 'release_migration_request_lock' ), $token );
			return $token;
		}
		return '';
	}

	/** Direct uncached read of the fallback lease. @return string */
	private static function read_fallback_migration_lock_raw() {
		global $wpdb;
		$wpdb->last_error = '';
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", self::MIGRATION_LOCK_OPTION ) );
		return '' !== trim( (string) $wpdb->last_error ) || null === $value ? '' : (string) $value;
	}

	/** @return void */
	private static function invalidate_fallback_migration_lock_cache() {
		wp_cache_delete( self::MIGRATION_LOCK_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Verify/heartbeat ownership immediately before another migration unit and
	 * before worker checkpoints. Fallback refresh is a direct DB compare-and-swap.
	 *
	 * @param string $token Lock token returned by acquire_migration_request_lock().
	 * @return bool
	 */
	private static function refresh_migration_request_lock( $token ) {
		global $wpdb;
		$token = (string) $token;
		if ( 0 === strpos( $token, 'mysql:' ) ) {
			$name = substr( $token, 6 );
			$wpdb->last_error = '';
			$owned = $wpdb->get_var( $wpdb->prepare( 'SELECT IF(IS_USED_LOCK(%s)=CONNECTION_ID(),1,0)', $name ) );
			return '' === trim( (string) $wpdb->last_error ) && '1' === (string) $owned;
		}
		if ( 0 !== strpos( $token, 'option:' ) ) { return false; }
		$owner = substr( $token, 7 );
		$current = self::read_fallback_migration_lock_raw();
		$decoded = is_string( $current ) ? json_decode( $current, true ) : null;
		if ( ! is_array( $decoded ) || ! isset( $decoded['owner'] ) || ! hash_equals( (string) $decoded['owner'], $owner ) ) { return false; }
		$decoded['expiresAt'] = time() + self::MIGRATION_LOCK_TTL;
		$next = wp_json_encode( $decoded );
		if ( ! is_string( $next ) ) { return false; }
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s",
			$next,
			self::MIGRATION_LOCK_OPTION,
			$current
		) );
		if ( 1 === absint( $updated ) ) {
			self::invalidate_fallback_migration_lock_cache();
			return true;
		}
		/* Zero rows may mean the second-resolution heartbeat encoded the same value.
		 * Re-read uncached and accept only the same owner with a still-live lease. */
		$verify = self::read_fallback_migration_lock_raw();
		$v = is_string( $verify ) ? json_decode( $verify, true ) : null;
		return is_array( $v ) && isset( $v['owner'] ) && hash_equals( (string) $v['owner'], $owner ) && absint( $v['expiresAt'] ?? 0 ) >= time();
	}


	/**
	 * Worker-side guard used by accelerated terminal workers. Normal web-request
	 * migration calls do not own an outer slice token and therefore pass through.
	 *
	 * @return bool
	 */
	public static function accelerated_slice_lock_is_valid() {
		return '' === self::$budgeted_slice_lock_token || self::refresh_migration_request_lock( self::$budgeted_slice_lock_token );
	}

	/** Release only our own migration lease. @param string $token Owner token. */
	public static function release_migration_request_lock( $token ) {
		global $wpdb;
		$token = (string) $token;
		if ( 0 === strpos( $token, 'mysql:' ) ) {
			$name = substr( $token, 6 );
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			return;
		}
		if ( 0 !== strpos( $token, 'option:' ) ) { return; }
		$owner = substr( $token, 7 );
		$current = self::read_fallback_migration_lock_raw();
		$decoded = is_string( $current ) ? json_decode( $current, true ) : null;
		if ( ! is_array( $decoded ) || ! isset( $decoded['owner'] ) || ! hash_equals( (string) $decoded['owner'], $owner ) ) { return; }
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name=%s AND BINARY option_value=BINARY %s",
			self::MIGRATION_LOCK_OPTION,
			$current
		) );
		self::invalidate_fallback_migration_lock_cache();
	}

	/**
	 * Safer content ownership defaults for a brand-new installation only.
	 *
	 * Existing installations keep their current Title/Slug policy unchanged. The
	 * global legacy defaults deliberately remain untouched so an old install with
	 * a genuinely missing row preserves its historical behavior.
	 *
	 * @return void
	 */
	private static function apply_fresh_install_product_content_defaults() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! class_exists( 'Mobo_Core_Connection_Settings' ) || ! Mobo_Core_Connection_Settings::table_exists() ) {
			return;
		}

		$default = method_exists( 'Mobo_Core_Connection_Repository', 'get_default_any' )
			? Mobo_Core_Connection_Repository::get_default_any()
			: Mobo_Core_Connection_Repository::get_default();
		$connection_id = is_array( $default ) ? absint( $default['id'] ?? 0 ) : 0;
		if ( $connection_id <= 0 ) {
			return;
		}

		Mobo_Core_Connection_Settings::set( $connection_id, 'global_product_auto_title', '0' );
		Mobo_Core_Connection_Settings::set( $connection_id, 'global_product_auto_slug', '0' );
	}

	/**
	 * Add missing default options only.
	 *
	 * Existing customer settings are never overwritten.
	 *
	 * @return void
	 */
	private static function ensure_defaults() {
		$defaults = Mobo_Core_Settings::defaults();
		if ( method_exists( 'Mobo_Core_Settings', 'prime_options' ) ) {
			Mobo_Core_Settings::prime_options( array_keys( $defaults ) );
		}

		foreach ( $defaults as $key => $value ) {
			/* Source-owned defaults are materialized in mobo_connection_settings by the
			 * Connection migration; never recreate terminally retired wp_options rows. */
			if ( class_exists( 'Mobo_Core_Connection_Settings' ) && Mobo_Core_Connection_Settings::is_scoped_key( $key ) ) {
				continue;
			}
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $value, '', false );
			}
		}
	}


	/**
	 * Schedule an early bounded housekeeping pass after the 10.33.16.14 indexes
	 * have been created by dbDelta. No active queue rows are modified here; the
	 * real-cron maintenance worker applies the normal conservative retention rules.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10331614_table_housekeeping_fast_path( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.16.14', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_maintenance_next_due_at', time() + 60, false );
		update_option( 'mobo_core_10331614_housekeeping_scheduled_at', time(), false );
	}

	/**
	 * Normalize Mobo runtime/settings options to non-autoloaded storage.
	 *
	 * Current installs already create every Mobo option with autoload=false. Very
	 * old installations can still carry queue/state/history rows in alloptions,
	 * making every storefront request deserialize worker data it never uses. Keep
	 * the intended architecture consistent by moving legacy mobo_core_* rows out
	 * of the global autoload payload without changing their values.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10331613_runtime_option_autoload_cleanup( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.16.13', '>=' ) ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb->options ) ) {
			return;
		}

		$autoload_values = array( 'yes', 'on', 'auto-on', 'auto' );
		$placeholders     = implode( ',', array_fill( 0, count( $autoload_values ), '%s' ) );
		$sql = $wpdb->prepare(
			"UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name LIKE %s AND autoload IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( array( $wpdb->esc_like( 'mobo_core_' ) . '%' ), $autoload_values )
		);
		$changed = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the immediate result of wpdb::prepare() above.
		$changed = false === $changed ? 0 : absint( $changed );

		/* alloptions may already be resident in this upgrade request. Force the next
		 * read to rebuild it without the rows moved above. */
		wp_cache_delete( 'alloptions', 'options' );
		update_option( 'mobo_core_10331613_autoload_cleanup_count', $changed, false );
		update_option( 'mobo_core_10331613_autoload_cleanup_at', time(), false );
	}

	/**
	 * Adjust defaults introduced in 10.30.7 without disturbing custom values.
	 *
	 * Existing values are changed only when they still match the old defaults.
	 * This keeps customer overrides intact while moving untouched installs to the
	 * safer requested defaults.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10307_default_adjustments( $previous_version ) {
		if ( '' !== (string) $previous_version && version_compare( (string) $previous_version, '10.30.7', '>=' ) ) {
			return;
		}

		self::update_option_if_current_value( 'mobo_core_webhook_files_per_run', 1, 4 );
		self::update_option_if_current_value( 'mobo_core_missing_variants_behavior', 'ignore', 'outofstock' );
		self::update_option_if_current_value( 'mobo_core_checkout_mobo_cart_validation_enabled', '1', '0' );
		self::update_option_if_current_value( 'mobo_core_checkout_mobo_debug_enabled', '1', '0' );
		self::update_option_if_current_value( 'mobo_core_checkout_validation_enabled', '0', '0' );
	}

	/**
	 * Update an option only if it is absent or still equals a known old default.
	 *
	 * @param string $key Option key.
	 * @param mixed  $old_value Old default value.
	 * @param mixed  $new_value New default value.
	 * @return void
	 */
	private static function update_option_if_current_value( $key, $old_value, $new_value ) {
		$current = get_option( $key, false );

		if ( false === $current || (string) $current === (string) $old_value ) {
			update_option( $key, $new_value, false );
		}
	}

	/**
	 * Ensure each install has a private real-cron/self-runner token.
	 *
	 * @return void
	 */
	private static function ensure_cron_token() {
		$token = (string) get_option( 'mobo_core_cron_token', '' );

		if ( '' !== trim( $token ) ) {
			return;
		}

		update_option( 'mobo_core_cron_token', wp_generate_password( 48, false, false ), false );
	}

	/**
	 * Ensure webhook directories exist and are protected.
	 *
	 * @return void
	 */
	private static function ensure_webhook_dirs() {
		self::protect_dir( MOBO_CORE_DATA_DIR );
		self::protect_dir( MOBO_CORE_WEBHOOK_FILE_DIR );
		self::protect_dir( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/' );
	}



	/**
	 * Remove generated city JS from the private data tree used before 10.31.56.
	 *
	 * The parent directory intentionally remains denied from the web because it
	 * also stores webhook fallback data. The retired private city assets are
	 * cleaned up during this legacy maintenance step.
	 *
	 * @return void
	 */
	private static function cleanup_legacy_private_city_assets() {
		$legacy_dir = trailingslashit( MOBO_CORE_DATA_DIR ) . 'assets/';
		foreach ( array( 'iran_cities.js', 'iran_cities.min.js' ) as $filename ) {
			$path = $legacy_dir . $filename;
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}


	/**
	 * Remove runtime state from the retired Persian WooCommerce option-enforcement feature.
	 *
	 * Mobo Core now shows guidance only and does not read, write, block, or
	 * periodically inspect Persian WooCommerce option values.
	 *
	 * @return void
	 */
	private static function cleanup_deprecated_pw_option_enforcement_state() {
		delete_option( 'mobo_core_pw_options_last_check_at' );
		delete_option( 'mobo_core_pw_options_last_enforced' );
		delete_transient( 'mobo_core_pw_options_enforced_notice' );
	}

	/**
	 * 10.33.92: move high-churn/large runtime payloads out of wp_options.
	 *
	 * Queue maps become row-based in mobo_work_queue; checkpoint/snapshot blobs
	 * move to mobo_state; checkout debug events move to mobo_events; authoritative
	 * variation seen-sets use mobo_seen_variants. Legacy options are deleted only
	 * after verified persistence in the new store.
	 *
	 * @return bool
	 */
	private static function migrate_103392_large_runtime_state() {
		if ( ! class_exists( 'Mobo_Core_State_Store' ) || ! class_exists( 'Mobo_Core_Work_Queue_Store' ) || ! class_exists( 'Mobo_Core_Event_Store' ) || ! class_exists( 'Mobo_Core_Seen_Variants_Store' ) ) {
			return false;
		}
		Mobo_Core_State_Store::reset_availability_cache();
		Mobo_Core_Work_Queue_Store::reset_availability_cache();
		Mobo_Core_Event_Store::reset_availability_cache();
		Mobo_Core_Seen_Variants_Store::reset_availability_cache();
		if ( ! Mobo_Core_State_Store::is_available() || ! Mobo_Core_Work_Queue_Store::is_available() || ! Mobo_Core_Event_Store::is_available() || ! Mobo_Core_Seen_Variants_Store::is_available() ) {
			return false;
		}

		$ok = true;
		$ok = Mobo_Core_Work_Queue_Store::migrate_option_map( 'mobo_core_cache_warmup_queue', 'cache_warmup', 'items' ) && $ok;
		$ok = Mobo_Core_Work_Queue_Store::migrate_option_map( 'mobo_core_parent_finalize_queue', 'parent_finalize' ) && $ok;
		$ok = Mobo_Core_Work_Queue_Store::migrate_option_map( 'mobo_core_mobo_order_submission_queue', 'order_submission' ) && $ok;

		$state_options = array(
			'mobo_core_cache_archive_purge_queue'       => 'cache_archive_purge_queue',
			'mobo_core_sync_state'                      => 'sync_state',
			'mobo_core_address_mapping_data'            => 'address_mapping_data',
			'mobo_core_health_rest_snapshot_last_good_v2'=> 'health_rest_snapshot_last_good_v2',
			'mobo_core_health_aux_snapshot_v2'           => 'health_aux_snapshot_v2',
			'mobo_core_health_cpanel_http_snapshot_v2'   => 'health_cpanel_http_snapshot_v2',
		);
		foreach ( $state_options as $option_name => $state_key ) {
			$ok = Mobo_Core_State_Store::migrate_option( $option_name, $state_key ) && $ok;
		}
		$sync_health_prefix = 'mobo_core_sync_health_operational_last_good_v2_';
		global $wpdb;
		$sync_health_like = $wpdb->esc_like( $sync_health_prefix ) . '%';
		$sync_health_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 100", $sync_health_like ) );
		foreach ( is_array( $sync_health_options ) ? $sync_health_options : array() as $sync_health_option ) {
			$suffix = substr( (string) $sync_health_option, strlen( $sync_health_prefix ) );
			$ok = Mobo_Core_State_Store::migrate_option( $sync_health_option, 'sync_health_operational_last_good_v2_' . sanitize_key( $suffix ) ) && $ok;
		}
		/* Independent order-recovery markers are queue rows, not state blobs. */
		$recovery_prefix = 'mobo_core_mobo_order_recovery_';
		$recovery_like   = $wpdb->esc_like( $recovery_prefix ) . '%';
		$recovery_names  = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 200", $recovery_like ) );
		foreach ( is_array( $recovery_names ) ? $recovery_names : array() as $recovery_name ) {
			$recovery_item = get_option( $recovery_name, null );
			$recovery_key  = substr( (string) $recovery_name, strlen( $recovery_prefix ) );
			if ( '' === $recovery_key || ! is_array( $recovery_item ) || ! Mobo_Core_Work_Queue_Store::put( 'order_recovery', $recovery_key, $recovery_item ) ) {
				$ok = false;
				continue;
			}
			delete_option( $recovery_name );
			wp_cache_delete( $recovery_name, 'options' );
		}
		$ok = Mobo_Core_Event_Store::migrate_option_log( 'mobo_core_checkout_mobo_debug_log', 'checkout_debug', 500 ) && $ok;

		/* The legacy seen-set key is a hash, so only the active scope can be mapped
		 * losslessly. Every other row belongs to an obsolete sync generation and is
		 * safe to retire. */
		$sync_state = Mobo_Core_State_Store::get( 'sync_state', array() );
		$active_product_guid = is_array( $sync_state ) ? sanitize_text_field( (string) ( isset( $sync_state['currentProductGuid'] ) ? $sync_state['currentProductGuid'] : '' ) ) : '';
		$active_sync_id      = is_array( $sync_state ) ? sanitize_text_field( (string) ( isset( $sync_state['syncId'] ) ? $sync_state['syncId'] : '' ) ) : '';
		$active_option = ( '' !== $active_product_guid && '' !== $active_sync_id )
			? 'mobo_seen_variants_' . md5( $active_product_guid . '|' . $active_sync_id )
			: '';
		$active_seen_migrated = true;
		if ( '' !== $active_option && false !== get_option( $active_option, false ) ) {
			$active_seen_migrated = Mobo_Core_Seen_Variants_Store::migrate_legacy_scope( $active_product_guid, $active_sync_id );
			$ok = $active_seen_migrated && $ok;
		}
		global $wpdb;
		$like = $wpdb->esc_like( 'mobo_seen_variants_' ) . '%';
		$legacy_seen = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		foreach ( is_array( $legacy_seen ) ? $legacy_seen : array() as $option_name ) {
			if ( '' !== $active_option && (string) $option_name === $active_option && ! $active_seen_migrated ) {
				continue;
			}
			delete_option( (string) $option_name );
		}

		if ( $ok ) {
			update_option( 'mobo_core_103392_large_state_migrated_at', time(), false );
			delete_option( 'mobo_core_103392_large_state_migration_error' );
		} else {
			update_option( 'mobo_core_103392_large_state_migration_error', 'One or more large runtime states could not be verified in custom tables; migration will retry.', false );
		}
		return $ok;
	}


	/**
	 * Persist one concise strict-cutover failure reason for upgrade diagnostics.
	 *
	 * @param string $message Failure reason.
	 * @return false
	 */
	private static function fail_103393_large_runtime_cutover( $message ) {
		update_option( 'mobo_core_103393_large_runtime_cutover_error', sanitize_text_field( (string) $message ), false );
		update_option( 'mobo_core_103393_large_runtime_cutover_error_at', time(), false );
		return false;
	}

	/**
	 * 10.33.93: finish the one-time large-runtime cutover and prove there is no
	 * legacy heavy-state residue before the plugin DB version can advance.
	 *
	 * This wraps the 10.33.92 copy/verify/delete migration, drains the temporary
	 * order-recovery state bridge used by partial 10.33.92 requests, and then
	 * requires every retired fixed/prefix option to be absent. A bounded prefix
	 * batch that leaves residue therefore keeps the DB version on the prior build
	 * and the next request resumes migration instead of falsely declaring success.
	 *
	 * @return bool
	 */
	/**
	 * 10.33.94 terminal Product/Variation operational-postmeta retirement.
	 * Database version remains on the previous release until physical zero-residue
	 * proof succeeds; the durable fence itself protects the in-between requests.
	 *
	 * @return bool
	 */
	private static function migrate_103394_operational_postmeta_retirement() {
		if ( ! class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' ) ) {
			return false;
		}
		return self::run_retirement_worker_with_telemetry( 'operational', array( 'Mobo_Core_Storage_Operational_Meta_Retirement', 'run_migration_step' ) );
	}


	/**
	 * 10.33.95 terminal Product/Variation identity-postmeta retirement.
	 * Storage Schema v5 and the completed 10.33.94 operational fence are hard
	 * prerequisites. DB version stamping waits for physical zero-residue proof.
	 *
	 * @return bool
	 */
	private static function migrate_103395_identity_postmeta_retirement() {
		if ( ! class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) ) { return false; }
		return self::run_retirement_worker_with_telemetry( 'identity', array( 'Mobo_Core_Storage_Identity_Meta_Retirement', 'run_migration_step' ) );
	}

	/**
	 * 10.33.96 terminal retirement for the remaining Mobo-owned Product/Variation
	 * metadata. Schema v6 mirrors exact legacy meta_id rows, transitions to Custom
	 * authority, drains late pre-transition writes, and stamps only after physical
	 * zero-residue proof. Woo native, attachment and order metadata are excluded.
	 *
	 * @return bool
	 */
	private static function migrate_103396_entity_postmeta_retirement() {
		if ( ! class_exists( 'Mobo_Core_Storage_Entity_Meta_Retirement' ) ) { return false; }
		return self::run_retirement_worker_with_telemetry( 'entity', array( 'Mobo_Core_Storage_Entity_Meta_Retirement', 'run_migration_step' ) );
	}

	/** Run one terminal retirement worker and collect low-overhead accelerated-slice telemetry. */
	private static function run_retirement_worker_with_telemetry( $name, $callback ) {
		$started = microtime( true );
		$result = call_user_func( $callback );
		if ( self::$accelerated_context && is_array( self::$slice_telemetry ) ) {
			$name = sanitize_key( (string) $name );
			if ( isset( self::$slice_telemetry['workerCalls'][ $name ] ) ) {
				self::$slice_telemetry['workerCalls'][ $name ] = absint( self::$slice_telemetry['workerCalls'][ $name ] ) + 1;
				self::$slice_telemetry['workerElapsedMs'][ $name ] = absint( self::$slice_telemetry['workerElapsedMs'][ $name ] ) + (int) round( ( microtime( true ) - $started ) * 1000 );
			}
		}
		return $result;
	}

	private static function migrate_103393_strict_large_runtime_cutover() {
		$installed = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		if ( '' !== $installed && version_compare( $installed, '10.33.93', '>=' ) ) { return true; }
		if ( ! self::migrate_103392_large_runtime_state() ) {
			return self::fail_103393_large_runtime_cutover( 'Base large-runtime migration is incomplete.' );
		}
		if ( ! class_exists( 'Mobo_Core_State_Store' ) || ! class_exists( 'Mobo_Core_Work_Queue_Store' ) || ! class_exists( 'Mobo_Core_Event_Store' ) || ! class_exists( 'Mobo_Core_Seen_Variants_Store' ) ) {
			return self::fail_103393_large_runtime_cutover( 'Required large-runtime storage class is unavailable.' );
		}
		if ( ! Mobo_Core_State_Store::is_available() || ! Mobo_Core_Work_Queue_Store::is_available() || ! Mobo_Core_Event_Store::is_available() || ! Mobo_Core_Seen_Variants_Store::is_available() ) {
			return self::fail_103393_large_runtime_cutover( 'Required large-runtime storage table is unavailable.' );
		}

		/* A request that ran 10.33.92 could have moved recovery options into the
		 * state table before moving them into the row queue. Finish that bridge here
		 * so strict cutover never leaves a second authoritative representation. */
		global $wpdb;
		$recovery_prefix = 'mobo_core_mobo_order_recovery_';
		$recovery_bridge = Mobo_Core_State_Store::list_prefix( $recovery_prefix, 10000 );
		if ( '' !== trim( (string) $wpdb->last_error ) ) { return self::fail_103393_large_runtime_cutover( 'Order-recovery bridge read failed.' ); }
		foreach ( $recovery_bridge as $legacy_key => $legacy_item ) {
			if ( ! is_array( $legacy_item ) || empty( $legacy_item['orderId'] ) ) {
				if ( ! Mobo_Core_State_Store::delete( $legacy_key ) ) { return self::fail_103393_large_runtime_cutover( 'Invalid order-recovery bridge row could not be retired.' ); }
				continue;
			}
			$key = substr( (string) $legacy_key, strlen( $recovery_prefix ) );
			if ( '' === $key || ! Mobo_Core_Work_Queue_Store::put( 'order_recovery', $key, $legacy_item ) ) {
				return self::fail_103393_large_runtime_cutover( 'Order-recovery bridge row could not be persisted to the work queue.' );
			}
			if ( ! Mobo_Core_State_Store::delete( $legacy_key ) ) {
				return self::fail_103393_large_runtime_cutover( 'Migrated order-recovery bridge row could not be retired.' );
			}
		}
		$recovery_residue = Mobo_Core_State_Store::list_prefix( $recovery_prefix, 1 );
		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			return self::fail_103393_large_runtime_cutover( 'Order-recovery bridge residue proof failed.' );
		}
		if ( ! empty( $recovery_residue ) ) {
			return self::fail_103393_large_runtime_cutover( 'Order-recovery bridge residue remains; migration will resume.' );
		}

		$fixed = array(
			'mobo_core_cache_warmup_queue',
			'mobo_core_parent_finalize_queue',
			'mobo_core_mobo_order_submission_queue',
			'mobo_core_cache_archive_purge_queue',
			'mobo_core_sync_state',
			'mobo_core_address_mapping_data',
			'mobo_core_health_rest_snapshot_last_good_v2',
			'mobo_core_health_aux_snapshot_v2',
			'mobo_core_health_cpanel_http_snapshot_v2',
			'mobo_core_checkout_mobo_debug_log',
		);
		/* Query physical option-row existence instead of get_option(..., false): a
		 * serialized boolean false is still residue and must not become invisible to
		 * the strict cutover proof. */
		$fixed_placeholders = implode( ',', array_fill( 0, count( $fixed ), '%s' ) );
		$fixed_remaining = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($fixed_placeholders) LIMIT 1", $fixed ) );
		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			return self::fail_103393_large_runtime_cutover( 'Fixed legacy-option residue proof failed.' );
		}
		if ( null !== $fixed_remaining ) {
			return self::fail_103393_large_runtime_cutover( 'Retired heavy option residue remains: ' . (string) $fixed_remaining );
		}

		$prefixes = array(
			'mobo_core_sync_health_operational_last_good_v2_',
			'mobo_core_mobo_order_recovery_',
			'mobo_seen_variants_',
		);
		foreach ( $prefixes as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", $like ) );
			if ( '' !== trim( (string) $wpdb->last_error ) ) {
				return self::fail_103393_large_runtime_cutover( 'Prefixed legacy-option residue proof failed for ' . $prefix );
			}
			if ( null !== $remaining ) {
				return self::fail_103393_large_runtime_cutover( 'Retired prefixed option residue remains: ' . (string) $remaining );
			}
		}

		update_option( 'mobo_core_103393_large_runtime_strict_cutover_at', time(), false );
		delete_option( 'mobo_core_103392_large_state_migration_error' );
		delete_option( 'mobo_core_103393_large_runtime_cutover_error' );
		delete_option( 'mobo_core_103393_large_runtime_cutover_error_at' );
		return true;
	}

	/**
	 * 10.34.12 multi-connection foundation.
	 *
	 * Adopt every existing installation as one Default Connection and attach all
	 * pre-existing durable webhook events to it. This is deliberately idempotent:
	 * no legacy option is deleted and an interrupted upgrade can safely retry.
	 *
	 * @return bool
	 */
	private static function migrate_103412_connection_foundation() {
		global $wpdb;

		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::table_exists() ) {
			update_option( 'mobo_core_103412_connection_migration_error', 'Connection table is unavailable.', false );
			return false;
		}

		$default_id = Mobo_Core_Connection_Repository::ensure_default_connection();
		if ( $default_id <= 0 ) {
			update_option( 'mobo_core_103412_connection_migration_error', 'Default Connection could not be created or adopted.', false );
			return false;
		}

		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) && Mobo_Core_Sync_Event_Store::table_exists() ) {
			$table = Mobo_Core_Sync_Event_Store::table_name();
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET connection_id = %d WHERE connection_id = 0", $default_id ) );
			if ( false === $updated ) {
				update_option( 'mobo_core_103412_connection_migration_error', 'Existing webhook events could not be assigned to Default Connection.', false );
				return false;
			}
			$unowned = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE connection_id = 0" ) );
			if ( $unowned > 0 ) {
				update_option( 'mobo_core_103412_connection_migration_error', 'Unowned webhook events remain after connection migration.', false );
				return false;
			}
		}

		update_option( 'mobo_core_103412_default_connection_id', $default_id, false );
		update_option( 'mobo_core_103412_connection_migrated_at', time(), false );
		delete_option( 'mobo_core_103412_connection_migration_error' );
		return true;
	}


	/**
	 * Repair an expanded per-Connection settings contract even when a replacement
	 * build keeps the same plugin/database version. The durable contract marker is
	 * independent from MOBO_CORE_VERSION so missing Default rows can self-heal
	 * without rerunning the full migration pipeline on ordinary requests.
	 *
	 * @return bool
	 */
	public static function ensure_connection_settings_contract() {
		$target = defined( 'MOBO_CORE_CONNECTION_SETTINGS_CONTRACT_VERSION' )
			? (string) MOBO_CORE_CONNECTION_SETTINGS_CONTRACT_VERSION
			: '';
		if ( '' !== $target && $target === (string) get_option( 'mobo_core_connection_settings_contract_version', '' ) ) {
			return true;
		}
		return self::migrate_103413_connection_settings_foundation();
	}

	/**
	 * 10.34.13 per-Connection settings storage foundation.
	 *
	 * Existing single-source options are copied into Default Connection only when
	 * the corresponding row is missing, then terminally removed from wp_options.
	 * After this cutover mobo_connection_settings is the sole Source-owned settings
	 * authority, including for the Default Connection.
	 *
	 * @return bool
	 */
	private static function migrate_103413_connection_settings_foundation() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! class_exists( 'Mobo_Core_Connection_Settings' ) ) {
			update_option( 'mobo_core_103413_connection_settings_migration_error', 'Connection settings classes are unavailable.', false );
			return false;
		}
		$default = Mobo_Core_Connection_Repository::get_default();
		$default_id = is_array( $default ) ? absint( $default['id'] ?? 0 ) : 0;
		if ( $default_id <= 0 ) {
			update_option( 'mobo_core_103413_connection_settings_migration_error', 'Default Connection is unavailable.', false );
			return false;
		}
		if ( ! Mobo_Core_Connection_Settings::migrate_legacy_options( $default_id ) ) {
			update_option( 'mobo_core_103413_connection_settings_migration_error', 'Legacy Source-dependent settings could not be migrated.', false );
			return false;
		}
		if ( ! Mobo_Core_Connection_Settings::retire_legacy_options( $default_id ) ) {
			update_option( 'mobo_core_103413_connection_settings_migration_error', 'Legacy Source-dependent wp_options could not be terminally retired.', false );
			return false;
		}
		update_option( 'mobo_core_103413_connection_settings_migrated_at', time(), false );
		update_option( 'mobo_core_103413_connection_settings_legacy_retired_at', time(), false );
		if ( defined( 'MOBO_CORE_CONNECTION_SETTINGS_CONTRACT_VERSION' ) ) {
			update_option( 'mobo_core_connection_settings_contract_version', (string) MOBO_CORE_CONNECTION_SETTINGS_CONTRACT_VERSION, true );
		}
		delete_option( 'mobo_core_103413_connection_settings_migration_error' );
		return true;
	}


	/**
	 * 10.34.14 connection-ownership terminal migration.
	 *
	 * Every durable row that predates Multi-Connection belongs to the Default
	 * Connection.  This migration assigns only unowned rows (connection_id = 0),
	 * then proves that no Source-owned table still contains an unowned row before
	 * publishing the readiness flag used by the runtime guard.  It is idempotent
	 * and restart-safe: a failed/interrupted pass simply retries on the next request.
	 *
	 * @return bool
	 */
	private static function migrate_103414_connection_ownership() {
		global $wpdb;

		/* Readiness is a terminal proof, not a sticky historical marker. Clear it
		 * before every attempt so an interrupted/failed rerun cannot leave a stale
		 * green gate visible to a second Connection. */
		delete_option( 'mobo_core_103414_connection_ownership_ready' );
		delete_option( 'mobo_core_103414_connection_ownership_default_id' );

		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! class_exists( 'Mobo_Core_Storage_Schema' ) ) {
			update_option( 'mobo_core_103414_connection_ownership_error', 'Connection ownership classes are unavailable.', false );
			return false;
		}
		if ( ! Mobo_Core_Storage_Schema::is_ready() ) {
			/* No silent legacy fallback exists here: without the Custom schema the
			 * version cannot stamp. The runner reports this honestly through
			 * normal_runtime_ready() (custom-storage-schema-not-ready). */
			update_option( 'mobo_core_103414_connection_ownership_error', 'Custom storage schema is not ready for Connection ownership adoption.', false );
			return false;
		}

		$default = Mobo_Core_Connection_Repository::get_default();
		$default_id = is_array( $default ) ? absint( isset( $default['id'] ) ? $default['id'] : 0 ) : 0;
		if ( $default_id <= 0 ) {
			$default_id = absint( Mobo_Core_Connection_Repository::ensure_default_connection() );
		}
		if ( $default_id <= 0 ) {
			update_option( 'mobo_core_103414_connection_ownership_error', 'Default Connection is unavailable.', false );
			return false;
		}

		/* 10.34.14 adds more Source-owned settings/health keys to the scoped
		 * contract. Seed only missing Default rows so the migration is retry-safe
		 * and never overwrites values already customized per Connection. */
		if ( ! class_exists( 'Mobo_Core_Connection_Settings' ) || ! Mobo_Core_Connection_Settings::migrate_legacy_options( $default_id ) ) {
			update_option( 'mobo_core_103414_connection_ownership_error', 'Could not seed newly scoped Default Connection settings.', false );
			return false;
		}

		/* Reprice/recategorize historically kept one installation-global option.
		 * That legacy state belongs to the Default Connection only. */
		if ( ! class_exists( 'Mobo_Core_State_Store' ) || ! Mobo_Core_State_Store::is_available() || ! class_exists( 'Mobo_Core_Connection_Context' ) ) {
			update_option( 'mobo_core_103414_connection_ownership_error', 'Connection-owned state storage is unavailable.', false );
			return false;
		}
		$legacy_state_migrated = Mobo_Core_Connection_Context::run(
			$default_id,
			static function () {
				return Mobo_Core_State_Store::migrate_option( 'mobo_core_reprice_state', 'mobo_core_reprice_state' )
					&& Mobo_Core_State_Store::migrate_option( 'mobo_core_recategorize_state', 'mobo_core_recategorize_state' );
			}
		);
		if ( ! $legacy_state_migrated ) {
			update_option( 'mobo_core_103414_connection_ownership_error', 'Could not migrate legacy Reprice/Recategorize state into the Default Connection.', false );
			return false;
		}

		/* Adopt only tables whose rows are semantically owned by a Source. The
		 * runtime table also has connection_id, but many rows are installation-wide
		 * storage/upgrade infrastructure and must remain at connection_id=0. */
		$storage_tables = Mobo_Core_Storage_Schema::table_names();
		$tables = array();
		foreach ( array( 'products', 'variants', 'jobs', 'media', 'order_dispatch', 'state', 'work_queue', 'seen_variants', 'events', 'migrations' ) as $storage_key ) {
			if ( ! empty( $storage_tables[ $storage_key ] ) ) { $tables[] = (string) $storage_tables[ $storage_key ]; }
		}
		$class_tables = array(
			'Mobo_Core_Product_Map',
			'Mobo_Core_Product_Ledger',
			'Mobo_Core_Sync_Event_Store',
			'Mobo_Core_Category_Map',
			'Mobo_Core_Image_Queue',
			'Mobo_Core_Image_Refresh_Queue',
			'Mobo_Core_Image_Prune_Queue',
			'Mobo_Core_Sync_Health',
		);
		foreach ( $class_tables as $class_name ) {
			if ( class_exists( $class_name ) && is_callable( array( $class_name, 'table_name' ) ) ) {
				$table = (string) call_user_func( array( $class_name, 'table_name' ) );
				if ( '' !== $table ) { $tables[] = $table; }
			}
		}
		$tables = array_values( array_unique( array_filter( $tables ) ) );

		if ( empty( $tables ) ) {
			update_option( 'mobo_core_103414_connection_ownership_error', 'No Source-owned tables were available for Connection ownership adoption.', false );
			return false;
		}

		foreach ( $tables as $table ) {
			$quoted = '`' . str_replace( '`', '``', (string) $table ) . '`';
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( (string) $table ) ) );
			if ( (string) $exists !== (string) $table ) {
				update_option( 'mobo_core_103414_connection_ownership_error', 'Required Source-owned table is missing or inaccessible: ' . (string) $table . '.', false );
				return false;
			}
			$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$quoted} LIKE %s", 'connection_id' ) );
			if ( 'connection_id' !== (string) $column ) {
				update_option( 'mobo_core_103414_connection_ownership_error', 'Required Connection ownership column is missing or inaccessible: ' . (string) $table . '.connection_id.', false );
				return false;
			}
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$quoted} SET connection_id = %d WHERE connection_id = 0", $default_id ) );
			if ( false === $updated ) {
				update_option( 'mobo_core_103414_connection_ownership_error', 'Could not assign legacy rows in ' . (string) $table . '; database query failed.', false );
				return false;
			}
		}

		foreach ( $tables as $table ) {
			$quoted = '`' . str_replace( '`', '``', (string) $table ) . '`';
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( (string) $table ) ) );
			if ( (string) $exists !== (string) $table ) {
				update_option( 'mobo_core_103414_connection_ownership_error', 'Required Source-owned table disappeared during ownership proof: ' . (string) $table . '.', false );
				return false;
			}
			$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$quoted} LIKE %s", 'connection_id' ) );
			if ( 'connection_id' !== (string) $column ) {
				update_option( 'mobo_core_103414_connection_ownership_error', 'Connection ownership column disappeared during proof: ' . (string) $table . '.connection_id.', false );
				return false;
			}
			$wpdb->last_error = '';
			$unowned = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$quoted} WHERE connection_id = 0" ) );
			if ( '' !== trim( (string) $wpdb->last_error ) || $unowned > 0 ) {
				update_option( 'mobo_core_103414_connection_ownership_error', 'Unowned rows remain in ' . (string) $table . '.', false );
				return false;
			}
		}

		update_option( 'mobo_core_103414_connection_ownership_ready', time(), false );
		update_option( 'mobo_core_103414_connection_ownership_default_id', $default_id, false );
		delete_option( 'mobo_core_103414_connection_ownership_error' );
		return true;
	}

	/**
	 * Create/update custom sync tables.
	 *
	 * @return bool True only when required runtime schema postconditions exist.
	 */
	private static function create_database_tables() {
		/* Shared-host fast path: once this exact build has already proved the full
		 * schema, later requests that are only advancing resumable data migration
		 * must not repeat every dbDelta/ALTER check. Re-validate read-only first; if
		 * anything drifted, fall through to the normal repair path below. */
		if ( MOBO_CORE_VERSION === (string) get_option( 'mobo_core_schema_version', '' )
			&& class_exists( 'Mobo_Core_Storage_Schema' )
			&& Mobo_Core_Storage_Schema::is_ready()
			&& ( self::runtime_schema_validation_cached() || self::database_schema_ready() ) ) {
			return true;
		}

		/* dbDelta() cannot alter an existing index; for a same-name index whose text
		 * differs it emits `ADD KEY <existing name>` and logs "Duplicate key name"
		 * (52 errors per upgraded site). Drop index lines whose name already exists
		 * from CREATE statements of existing tables; repair_database_index() below
		 * remains the only path that replaces a mismatched index. */
		add_filter( 'dbdelta_create_queries', array( __CLASS__, 'filter_dbdelta_existing_indexes' ), 10, 1 );
		try {
			self::create_runtime_tables_via_dbdelta();
		} finally {
			remove_filter( 'dbdelta_create_queries', array( __CLASS__, 'filter_dbdelta_existing_indexes' ), 10 );
		}

		/* Remove only indexes that are exact left-prefix duplicates of a verified
		 * replacement. Failure to drop is non-fatal; correctness/readiness depends on
		 * the covering index, while an extra old index only costs write amplification. */
		self::drop_redundant_runtime_indexes();

		return self::finish_create_database_tables();
	}

	/**
	 * Strip index definitions that already exist (by name) from dbDelta CREATE
	 * statements of existing tables. New tables are left untouched.
	 *
	 * @param array $cqueries dbDelta create queries keyed by table.
	 * @return array
	 */
	public static function filter_dbdelta_existing_indexes( $cqueries ) {
		global $wpdb;
		if ( ! is_array( $cqueries ) ) {
			return $cqueries;
		}
		foreach ( $cqueries as $table => $query ) {
			if ( ! is_string( $query ) || ! preg_match( '/^\s*CREATE\s+TABLE/i', $query ) ) {
				continue;
			}
			$table_name = trim( (string) $table, '` ' );
			if ( '' === $table_name || ! preg_match( '/^[A-Za-z0-9_$]+$/', $table_name ) ) {
				continue;
			}
			$wpdb->last_error = '';
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
			if ( '' !== trim( (string) $wpdb->last_error ) || null === $exists || '' === (string) $exists ) {
				continue;
			}
			$rows = $wpdb->get_results( 'SHOW INDEX FROM `' . $table_name . '`', ARRAY_A );
			if ( ! is_array( $rows ) || empty( $rows ) ) {
				continue;
			}
			$existing = array();
			foreach ( $rows as $row ) {
				$name = is_array( $row ) ? (string) ( $row['Key_name'] ?? ( $row['KEY_NAME'] ?? ( $row['key_name'] ?? '' ) ) ) : '';
				if ( '' !== $name ) {
					$existing[ strtolower( $name ) ] = true;
				}
			}
			$cqueries[ $table ] = self::strip_existing_index_lines( $query, $existing );
		}
		return $cqueries;
	}

	/**
	 * Pure helper (unit tested): remove index lines whose name is in $existing.
	 *
	 * @param string $query    CREATE TABLE statement.
	 * @param array  $existing Lower-case index names => true (PRIMARY for the primary key).
	 * @return string
	 */
	public static function strip_existing_index_lines( $query, $existing ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $query );
		$out   = array();
		foreach ( $lines as $line ) {
			$name = '';
			if ( preg_match( '/^\s*PRIMARY\s+KEY\b/i', $line ) ) {
				$name = 'primary';
			} elseif ( preg_match( '/^\s*(?:UNIQUE|FULLTEXT|SPATIAL)?\s*(?:KEY|INDEX)\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $line, $m ) ) {
				$name = strtolower( $m[1] );
			}
			if ( '' !== $name && isset( $existing[ $name ] ) ) {
				continue;
			}
			$out[] = $line;
		}
		return implode( "\n", $out );
	}

	/** dbDelta() of every legacy runtime table plus the Custom Storage schema. @return void */
	private static function create_runtime_tables_via_dbdelta() {
		if ( class_exists( 'Mobo_Core_Product_Map' ) ) {
			Mobo_Core_Product_Map::create_table();
		}

		if ( class_exists( 'Mobo_Core_Product_Ledger' ) ) {
			Mobo_Core_Product_Ledger::create_table();
		}

		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) ) {
			Mobo_Core_Sync_Event_Store::create_table();
		}

		if ( class_exists( 'Mobo_Core_Category_Map' ) ) {
			Mobo_Core_Category_Map::create_table();
		}

		if ( class_exists( 'Mobo_Core_Image_Queue' ) ) {
			Mobo_Core_Image_Queue::create_table();
		}

		if ( class_exists( 'Mobo_Core_Image_Refresh_Queue' ) ) {
			Mobo_Core_Image_Refresh_Queue::create_table();
		}

		if ( class_exists( 'Mobo_Core_Image_Prune_Queue' ) ) {
			Mobo_Core_Image_Prune_Queue::create_table();
		}

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			Mobo_Core_Orphan_Image_Cleanup::create_table();
		}

		if ( class_exists( 'Mobo_Core_Sync_Health' ) ) {
			Mobo_Core_Sync_Health::create_table();
		}
	}

	/** Custom Storage schema, backfill registration and readiness verdict. @return bool */
	private static function finish_create_database_tables() {
		/*
		 * Phase-1 custom storage has its own readiness/version boundary.
		 *
		 * Honest note (10.34.77): there is no legacy runtime fallback for a site whose
		 * host rejects this DDL (missing CREATE/ALTER privilege, or a utf8mb4 probe
		 * failure). The DB version stamp requires this schema, and the real runner
		 * stays in migration-only mode (Migration::normal_runtime_ready()) until it is
		 * ready. Such a site is surfaced, not hidden: admin notice, schema_last_error
		 * and the health payload report the exact reason. Ordinary requests retry at
		 * most once per ORDINARY_PASS_INTERVAL.
		 */
		if ( class_exists( 'Mobo_Core_Storage_Schema' ) ) {
			add_filter( 'dbdelta_create_queries', array( __CLASS__, 'filter_dbdelta_existing_indexes' ), 10, 1 );
			try {
				Mobo_Core_Storage_Schema::ensure();
			} finally {
				remove_filter( 'dbdelta_create_queries', array( __CLASS__, 'filter_dbdelta_existing_indexes' ), 10 );
			}
		}
		/* 10.33.70: register the resumable Product/Variation state backfill.
		 * Scheduling is metadata-only; catalog rows are processed later by the
		 * low-priority real runner and only while dual-write is enabled. */
		if ( class_exists( 'Mobo_Core_Storage_Backfill' ) ) {
			Mobo_Core_Storage_Backfill::schedule();
		}

		$ready = self::database_schema_ready();
		if ( ! $ready ) {
			delete_option( self::RUNTIME_SCHEMA_VALIDATED_OPTION );
		}
		if ( $ready ) {
			update_option( 'mobo_core_schema_version', MOBO_CORE_VERSION, false );
			delete_option( 'mobo_core_schema_last_error' );
			delete_option( 'mobo_core_schema_last_error_at' );
		} else {
			$error = '' !== self::$schema_last_failure
				? 'Mobo Core database schema is incomplete: ' . self::$schema_last_failure . ' Migration will retry automatically.'
				: 'Mobo Core database schema is incomplete; migration will retry automatically.';
			update_option( 'mobo_core_schema_last_error', $error, false );
			update_option( 'mobo_core_schema_last_error_at', time(), false );
		}

		return $ready;
	}

	/**
	 * Verify postconditions that runtime queues depend on before stamping a DB version.
	 *
	 * @return bool
	 */
	private static function database_schema_ready() {
		global $wpdb;
		self::$schema_last_failure = '';

		/* The terminal database-version stamp requires the current custom-storage
		 * contract to be ready. Do not pin this gate to a historical schema
		 * generation: Storage Schema::is_ready() already verifies that the database
		 * matches the VERSION compiled into this exact Plugin build. */
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' )
			|| ! Mobo_Core_Storage_Schema::is_ready() ) {
			self::$schema_last_failure = 'current custom storage schema is incomplete or inaccessible.';
			return false;
		}

		$classes = array(
			'Mobo_Core_Product_Map',
			'Mobo_Core_Product_Ledger',
			'Mobo_Core_Sync_Event_Store',
			'Mobo_Core_Category_Map',
			'Mobo_Core_Image_Queue',
			'Mobo_Core_Image_Refresh_Queue',
			'Mobo_Core_Image_Prune_Queue',
			'Mobo_Core_Orphan_Image_Cleanup',
			'Mobo_Core_Sync_Health',
		);

		foreach ( $classes as $class_name ) {
			if ( ! class_exists( $class_name ) || ! is_callable( array( $class_name, 'table_name' ) ) ) {
				self::$schema_last_failure = 'required schema class is unavailable: ' . $class_name . '.';
				return false;
			}
			$table = call_user_func( array( $class_name, 'table_name' ) );
			if ( ! is_string( $table ) || '' === $table ) {
				self::$schema_last_failure = 'required schema class returned an empty table name: ' . $class_name . '.';
				return false;
			}
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( (string) $found !== $table ) {
				self::$schema_last_failure = 'required table is missing or inaccessible: ' . $table . '.';
				return false;
			}
		}

		$event_table = Mobo_Core_Sync_Event_Store::table_name();
		$claim_token = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$event_table} LIKE %s", 'claim_token' ) );
		if ( 'claim_token' !== (string) $claim_token ) {
			self::$schema_last_failure = 'required column is missing or inaccessible: ' . $event_table . '.claim_token.';
			return false;
		}

		$connection_id = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$event_table} LIKE %s", 'connection_id' ) );
		if ( 'connection_id' !== (string) $connection_id ) {
			self::$schema_last_failure = 'required column is missing or inaccessible: ' . $event_table . '.connection_id.';
			return false;
		}

		$health_table   = Mobo_Core_Sync_Health::table_name();
		$portal_version = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$health_table} LIKE %s", 'portal_version' ) );
		if ( 'portal_version' !== (string) $portal_version ) {
			self::$schema_last_failure = 'required column is missing or inaccessible: ' . $health_table . '.portal_version.';
			return false;
		}

		$prune_table = Mobo_Core_Image_Prune_Queue::table_name();
		foreach ( array( 'connection_id', 'attachment_id', 'image_guid', 'phase', 'postmeta_cursor', 'option_cursor', 'post_cursor', 'proof_pass' ) as $column_name ) {
			$found_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$prune_table} LIKE %s", $column_name ) );
			if ( $column_name !== (string) $found_column ) {
				self::$schema_last_failure = 'required column is missing or inaccessible: ' . $prune_table . '.' . $column_name . '.';
				return false;
			}
		}

		/*
		 * Identity/ownership indexes are correctness constraints, not merely query
		 * optimizations. A partial dbDelta that creates columns but misses one of these
		 * indexes must never advance the schema version. Validate both the column order
		 * and uniqueness flag; the Product Map GUID prefixes are also part of the old
		 * utf8mb4 key-length compatibility contract.
		 */
		$required_indexes = array(
			array( Mobo_Core_Product_Map::table_name(), 'remote_object', true, array( array( 'connection_id', 0 ), array( 'remote_guid', 140 ), array( 'object_type', 0 ) ) ),
			array( Mobo_Core_Product_Map::table_name(), 'wp_post_id', false, array( array( 'connection_id', 0 ), array( 'wp_post_id', 0 ) ) ),
			array( Mobo_Core_Product_Map::table_name(), 'object_type', false, array( array( 'connection_id', 0 ), array( 'object_type', 0 ) ) ),
			array( Mobo_Core_Product_Map::table_name(), 'parent_remote_guid', false, array( array( 'connection_id', 0 ), array( 'parent_remote_guid', 140 ) ) ),
			array( Mobo_Core_Product_Map::table_name(), 'parent_object', false, array( array( 'connection_id', 0 ), array( 'parent_remote_guid', 110 ), array( 'object_type', 0 ) ) ),
			array( Mobo_Core_Product_Map::table_name(), 'updated_id', false, array( array( 'connection_id', 0 ), array( 'updated_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Product_Ledger::table_name(), 'product_guid', true, array( array( 'connection_id', 0 ), array( 'product_guid', 180 ) ) ),
			array( Mobo_Core_Product_Ledger::table_name(), 'last_wp_product_id', false, array( array( 'connection_id', 0 ), array( 'last_wp_product_id', 0 ) ) ),
			array( Mobo_Core_Product_Ledger::table_name(), 'last_seen_at', false, array( array( 'connection_id', 0 ), array( 'last_seen_at', 0 ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'event_uuid', true, array( array( 'connection_id', 0 ), array( 'event_uuid', 0 ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'connection_status', false, array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'next_retry_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'remote_event_id', false, array( array( 'connection_id', 0 ), array( 'remote_event_id', 150 ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'status_retry_id', false, array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'next_retry_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'locked_until', false, array( array( 'connection_id', 0 ), array( 'locked_until', 0 ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'claim_token', false, array( array( 'connection_id', 0 ), array( 'claim_token', 0 ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'status_locked_id', false, array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'locked_until', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'status_updated_id', false, array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'updated_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'event_entity_id', false, array( array( 'connection_id', 0 ), array( 'event_type', 0 ), array( 'entity_guid', 100 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'entity_lookup', false, array( array( 'connection_id', 0 ), array( 'entity_type', 0 ), array( 'entity_guid', 120 ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'created_at', false, array( array( 'connection_id', 0 ), array( 'created_at', 0 ) ) ),
			array( Mobo_Core_Category_Map::table_name(), 'remote_guid', true, array( array( 'connection_id', 0 ), array( 'remote_guid', 180 ) ) ),
			array( Mobo_Core_Category_Map::table_name(), 'manual_term_id', false, array( array( 'connection_id', 0 ), array( 'manual_term_id', 0 ) ) ),
			array( Mobo_Core_Category_Map::table_name(), 'synced_term_id', false, array( array( 'connection_id', 0 ), array( 'synced_term_id', 0 ) ) ),
			array( Mobo_Core_Category_Map::table_name(), 'parent_remote_guid', false, array( array( 'connection_id', 0 ), array( 'parent_remote_guid', 180 ) ) ),
			array( Mobo_Core_Image_Queue::table_name(), 'queue_key', true, array( array( 'connection_id', 0 ), array( 'queue_key', 0 ) ) ),
			array( Mobo_Core_Image_Queue::table_name(), 'product_status', false, array( array( 'connection_id', 0 ), array( 'product_id', 0 ), array( 'status', 0 ), array( 'next_retry_at', 0 ) ) ),
			array( Mobo_Core_Image_Queue::table_name(), 'product_position', false, array( array( 'connection_id', 0 ), array( 'product_id', 0 ), array( 'position_index', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Image_Queue::table_name(), 'image_guid', false, array( array( 'connection_id', 0 ), array( 'image_guid', 160 ) ) ),
			array( Mobo_Core_Image_Queue::table_name(), 'status_retry_id', false, array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'next_retry_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Image_Queue::table_name(), 'locked_until', false, array( array( 'connection_id', 0 ), array( 'locked_until', 0 ) ) ),
			array( Mobo_Core_Image_Queue::table_name(), 'status_locked_id', false, array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'locked_until', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Image_Queue::table_name(), 'status_updated_id', false, array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'updated_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Image_Queue::table_name(), 'updated_id', false, array( array( 'connection_id', 0 ), array( 'updated_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Image_Queue::table_name(), 'attachment_id', false, array( array( 'connection_id', 0 ), array( 'attachment_id', 0 ) ) ),
			array( Mobo_Core_Image_Refresh_Queue::table_name(), 'queue_key', true, array( array( 'connection_id', 0 ), array( 'queue_key', 0 ) ) ),
			array( Mobo_Core_Image_Refresh_Queue::table_name(), 'product_status', false, array( array( 'connection_id', 0 ), array( 'product_id', 0 ), array( 'status', 0 ), array( 'next_retry_at', 0 ) ) ),
			array( Mobo_Core_Image_Refresh_Queue::table_name(), 'image_guid', false, array( array( 'connection_id', 0 ), array( 'image_guid', 160 ) ) ),
			array( Mobo_Core_Image_Refresh_Queue::table_name(), 'old_attachment_id', false, array( array( 'connection_id', 0 ), array( 'old_attachment_id', 0 ) ) ),
			array( Mobo_Core_Image_Refresh_Queue::table_name(), 'new_attachment_id', false, array( array( 'connection_id', 0 ), array( 'new_attachment_id', 0 ) ) ),
			array( Mobo_Core_Image_Refresh_Queue::table_name(), 'status_retry_id', false, array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'next_retry_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Image_Refresh_Queue::table_name(), 'locked_until', false, array( array( 'connection_id', 0 ), array( 'locked_until', 0 ) ) ),
			array( Mobo_Core_Image_Refresh_Queue::table_name(), 'status_locked_id', false, array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'locked_until', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Image_Refresh_Queue::table_name(), 'status_updated_id', false, array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'updated_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Image_Prune_Queue::table_name(), 'attachment_id', true, array( array( 'connection_id', 0 ), array( 'attachment_id', 0 ) ) ),
			array( Mobo_Core_Image_Prune_Queue::table_name(), 'attachment_lookup', false, array( array( 'attachment_id', 0 ) ) ),
			array( Mobo_Core_Image_Prune_Queue::table_name(), 'status_retry_id', false, array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'next_retry_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Image_Prune_Queue::table_name(), 'image_guid', false, array( array( 'connection_id', 0 ), array( 'image_guid', 0 ) ) ),
			array( Mobo_Core_Image_Prune_Queue::table_name(), 'owner_product', false, array( array( 'connection_id', 0 ), array( 'owner_product_id', 0 ), array( 'status', 0 ) ) ),
			array( Mobo_Core_Image_Prune_Queue::table_name(), 'updated_id', false, array( array( 'connection_id', 0 ), array( 'updated_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Image_Prune_Queue::table_name(), 'status_updated_id', false, array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'updated_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Orphan_Image_Cleanup::table_name(), 'file_key', true, array( array( 'file_key', 0 ) ) ),
			array( Mobo_Core_Orphan_Image_Cleanup::table_name(), 'family_key', false, array( array( 'family_key', 0 ) ) ),
			array( Mobo_Core_Orphan_Image_Cleanup::table_name(), 'matched_webp_attachment_id', false, array( array( 'matched_webp_attachment_id', 0 ) ) ),
			array( Mobo_Core_Orphan_Image_Cleanup::table_name(), 'status_updated', false, array( array( 'status', 0 ), array( 'updated_at', 0 ) ) ),
			array( Mobo_Core_Sync_Health::table_name(), 'product_guid', true, array( array( 'connection_id', 0 ), array( 'product_guid', 180 ) ) ),
			array( Mobo_Core_Sync_Health::table_name(), 'wp_product_id', false, array( array( 'connection_id', 0 ), array( 'wp_product_id', 0 ) ) ),
			array( Mobo_Core_Sync_Health::table_name(), 'portal_product_id', false, array( array( 'connection_id', 0 ), array( 'portal_product_id', 0 ) ) ),
			array( Mobo_Core_Sync_Health::table_name(), 'sync_status', false, array( array( 'connection_id', 0 ), array( 'sync_status', 0 ) ) ),
			array( Mobo_Core_Sync_Health::table_name(), 'portal_revision', false, array( array( 'connection_id', 0 ), array( 'portal_revision', 0 ) ) ),
		);
		foreach ( $required_indexes as $requirement ) {
			if ( self::database_index_matches( $requirement[0], $requirement[1], $requirement[2], $requirement[3] ) ) {
				continue;
			}

			/*
			 * dbDelta does not reliably replace an existing index whose name stayed the
			 * same while its prefix/column definition changed. Repair only Mobo Core's
			 * own named indexes, then verify the exact postcondition again.
			 */
			if ( ! self::repair_database_index( $requirement[0], $requirement[1], $requirement[2], $requirement[3] )
				|| ! self::database_index_matches( $requirement[0], $requirement[1], $requirement[2], $requirement[3] ) ) {
				if ( '' === self::$schema_last_failure ) {
					self::$schema_last_failure = 'required index does not match after automatic repair: ' . $requirement[0] . '.' . $requirement[1] . '.';
				}
				return false;
			}
		}

		/* A replacement may have been repaired in the loop above, so retry the
		 * best-effort redundant-index cleanup once the covering definitions are exact. */
		self::drop_redundant_runtime_indexes();

		update_option( self::RUNTIME_SCHEMA_VALIDATED_OPTION, array( 'version' => MOBO_CORE_VERSION, 'at' => time() ), false );
		return true;
	}

	/**
	 * Whether this exact plugin build validated the legacy runtime schema recently.
	 * Only a positive result is cached; any failure path re-validates. The final
	 * DB-version stamp (finalize_database_version) still performs a fresh check.
	 *
	 * @return bool
	 */
	private static function runtime_schema_validation_cached() {
		$stamp = get_option( self::RUNTIME_SCHEMA_VALIDATED_OPTION, array() );
		if ( ! is_array( $stamp ) || MOBO_CORE_VERSION !== (string) ( $stamp['version'] ?? '' ) ) { return false; }
		$at = absint( $stamp['at'] ?? 0 );
		return $at > 0 && $at <= time() + 60 && ( time() - $at ) < self::RUNTIME_SCHEMA_VALIDATED_TTL;
	}


	/**
	 * Drop exact left-prefix duplicate indexes only after the covering index is
	 * verified. Extra indexes are not a correctness failure, so a denied DROP is
	 * tolerated and can be retried on a later upgrade request.
	 *
	 * @return void
	 */
	private static function drop_redundant_runtime_indexes() {
		$requirements = array(
			array( Mobo_Core_Image_Queue::table_name(), 'status_retry', 'status_retry_id', array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'next_retry_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Image_Refresh_Queue::table_name(), 'status_retry', 'status_retry_id', array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'next_retry_at', 0 ), array( 'id', 0 ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'status_retry', 'status_retry_id', array( array( 'connection_id', 0 ), array( 'status', 0 ), array( 'next_retry_at', 0 ), array( 'id', 0 ) ) ),
		);

		foreach ( $requirements as $requirement ) {
			$table = (string) $requirement[0];
			$old   = (string) $requirement[1];
			$new   = (string) $requirement[2];
			if ( '' === $table || ! self::database_index_matches( $table, $new, false, $requirement[3] ) ) {
				continue;
			}
			self::drop_database_index_if_present( $table, $old );
		}
	}

	/**
	 * Best-effort drop for one Mobo-owned obsolete index.
	 *
	 * @param string $table Table name.
	 * @param string $index Index name.
	 * @return bool
	 */
	private static function drop_database_index_if_present( $table, $index ) {
		global $wpdb;
		$table = trim( (string) $table );
		$index = trim( (string) $index );
		if ( '' === $table || '' === $index ) { return false; }
		$quoted_table = '`' . str_replace( '`', '``', $table ) . '`';
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$quoted_table}", ARRAY_A );
		if ( ! is_array( $rows ) ) { return false; }
		$exists = false;
		foreach ( $rows as $row ) {
			$key = isset( $row['Key_name'] ) ? (string) $row['Key_name'] : ( isset( $row['KEY_NAME'] ) ? (string) $row['KEY_NAME'] : '' );
			if ( $index === $key ) { $exists = true; break; }
		}
		if ( ! $exists ) { return true; }
		$quoted_index = '`' . str_replace( '`', '``', $index ) . '`';
		return false !== $wpdb->query( "ALTER TABLE {$quoted_table} DROP INDEX {$quoted_index}" );
	}

	/**
	 * Verify one required database index exactly enough for runtime correctness.
	 *
	 * @param string $table Table name.
	 * @param string $index Index name.
	 * @param bool   $unique Whether the index must be unique.
	 * @param array  $columns Ordered array of [column_name, required_prefix_length].
	 * @return bool
	 */
	private static function database_index_matches( $table, $index, $unique, $columns ) {
		global $wpdb;

		/*
		 * Do not depend on metadata schemas here. Shared-hosting database users can
		 * legitimately be denied visibility into them even though the application
		 * has full access to its own WordPress tables. SHOW INDEX operates against
		 * the target table itself and is therefore a better portability contract.
		 */
		$table = is_string( $table ) ? trim( $table ) : '';
		$index = is_string( $index ) ? trim( $index ) : '';
		if ( '' === $table || '' === $index || ! is_array( $columns ) ) {
			return false;
		}

		/* Table names originate from Mobo Core table_name() methods. Quote the
		 * identifier defensively rather than interpolating it unescaped. */
		$quoted_table = '`' . str_replace( '`', '``', $table ) . '`';
		$all_rows     = $wpdb->get_results( "SHOW INDEX FROM {$quoted_table}", ARRAY_A );
		if ( ! is_array( $all_rows ) || empty( $all_rows ) ) {
			return false;
		}

		$rows = array();
		foreach ( $all_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$key_name = '';
			if ( isset( $row['Key_name'] ) ) {
				$key_name = (string) $row['Key_name'];
			} elseif ( isset( $row['KEY_NAME'] ) ) {
				$key_name = (string) $row['KEY_NAME'];
			} elseif ( isset( $row['key_name'] ) ) {
				$key_name = (string) $row['key_name'];
			}

			if ( $index === $key_name ) {
				$rows[] = $row;
			}
		}

		if ( count( $rows ) !== count( $columns ) ) {
			return false;
		}

		usort(
			$rows,
			static function ( $left, $right ) {
				$left_seq  = isset( $left['Seq_in_index'] ) ? absint( $left['Seq_in_index'] ) : ( isset( $left['SEQ_IN_INDEX'] ) ? absint( $left['SEQ_IN_INDEX'] ) : 0 );
				$right_seq = isset( $right['Seq_in_index'] ) ? absint( $right['Seq_in_index'] ) : ( isset( $right['SEQ_IN_INDEX'] ) ? absint( $right['SEQ_IN_INDEX'] ) : 0 );
				return $left_seq <=> $right_seq;
			}
		);

		foreach ( array_values( $columns ) as $offset => $expected ) {
			$row = isset( $rows[ $offset ] ) && is_array( $rows[ $offset ] ) ? $rows[ $offset ] : array();
			$expected_name   = isset( $expected[0] ) ? (string) $expected[0] : '';
			$expected_prefix = isset( $expected[1] ) ? absint( $expected[1] ) : 0;

			if ( isset( $row['Column_name'] ) ) {
				$actual_name = (string) $row['Column_name'];
			} elseif ( isset( $row['COLUMN_NAME'] ) ) {
				$actual_name = (string) $row['COLUMN_NAME'];
			} else {
				$actual_name = '';
			}

			if ( array_key_exists( 'Sub_part', $row ) ) {
				$actual_prefix = null !== $row['Sub_part'] ? absint( $row['Sub_part'] ) : 0;
			} elseif ( array_key_exists( 'SUB_PART', $row ) ) {
				$actual_prefix = null !== $row['SUB_PART'] ? absint( $row['SUB_PART'] ) : 0;
			} else {
				$actual_prefix = 0;
			}

			if ( $expected_name !== $actual_name || $expected_prefix !== $actual_prefix ) {
				return false;
			}
		}

		$first_row = $rows[0];
		if ( isset( $first_row['Non_unique'] ) ) {
			$non_unique = absint( $first_row['Non_unique'] );
		} elseif ( isset( $first_row['NON_UNIQUE'] ) ) {
			$non_unique = absint( $first_row['NON_UNIQUE'] );
		} else {
			return false;
		}

		return $unique ? 0 === $non_unique : 1 === $non_unique;
	}


	/**
	 * Repair one Mobo-owned required index using table-local SHOW/ALTER statements.
	 *
	 * This intentionally avoids metadata schemas because shared-hosting database
	 * users may not be allowed to inspect them. Index/table/column identifiers are
	 * internal constants assembled by Mobo Core; no request data reaches this SQL.
	 *
	 * @param string $table Table name.
	 * @param string $index Index name.
	 * @param bool   $unique Whether the index must be unique.
	 * @param array  $columns Ordered array of [column_name, required_prefix_length].
	 * @return bool
	 */
	private static function repair_database_index( $table, $index, $unique, $columns ) {
		global $wpdb;

		$table = is_string( $table ) ? trim( $table ) : '';
		$index = is_string( $index ) ? trim( $index ) : '';
		if ( '' === $table || '' === $index || ! is_array( $columns ) || empty( $columns ) ) {
			self::$schema_last_failure = 'automatic index repair received an invalid requirement.';
			return false;
		}

		/*
		 * Build and validate the complete replacement definition before issuing any
		 * DDL. The old implementation could DROP the current index and only then
		 * discover an invalid column requirement or fail while adding the replacement.
		 */
		$column_sql = array();
		foreach ( array_values( $columns ) as $expected ) {
			$name   = isset( $expected[0] ) ? trim( (string) $expected[0] ) : '';
			$prefix = isset( $expected[1] ) ? absint( $expected[1] ) : 0;
			if ( '' === $name ) {
				self::$schema_last_failure = 'automatic index repair received an empty column for ' . $table . '.' . $index . '.';
				return false;
			}
			$quoted_column = '`' . str_replace( '`', '``', $name ) . '`';
			$column_sql[]  = $quoted_column . ( $prefix > 0 ? '(' . $prefix . ')' : '' );
		}

		$quoted_table = '`' . str_replace( '`', '``', $table ) . '`';
		$quoted_index = '`' . str_replace( '`', '``', $index ) . '`';
		$rows         = $wpdb->get_results( "SHOW INDEX FROM {$quoted_table}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			self::$schema_last_failure = 'SHOW INDEX failed for ' . $table . '; database query failed.';
			return false;
		}

		$index_exists = false;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key_name = isset( $row['Key_name'] ) ? (string) $row['Key_name'] : ( isset( $row['KEY_NAME'] ) ? (string) $row['KEY_NAME'] : ( isset( $row['key_name'] ) ? (string) $row['key_name'] : '' ) );
			if ( $index === $key_name ) {
				$index_exists = true;
				break;
			}
		}

		$kind       = $unique ? 'UNIQUE KEY' : 'KEY';
		$definition = "ADD {$kind} {$quoted_index} (" . implode( ', ', $column_sql ) . ')';

		/*
		 * Replace a same-name mismatched index in one ALTER statement. MySQL/MariaDB
		 * apply ALTER TABLE as one table-definition operation: if the replacement
		 * cannot be created, the DROP is not committed independently. This avoids the
		 * old DROP-success/ADD-failure window where an ownership UNIQUE constraint
		 * disappeared and duplicate identities could be admitted before retry.
		 */
		$sql = $index_exists
			? "ALTER TABLE {$quoted_table} DROP INDEX {$quoted_index}, {$definition}"
			: "ALTER TABLE {$quoted_table} {$definition}";

		$repaired = $wpdb->query( $sql );
		if ( false === $repaired ) {
			self::$schema_last_failure = $index_exists
				? 'could not atomically replace required index ' . $table . '.' . $index . '; the previous table definition was retained; database query failed.'
				: 'could not create required index ' . $table . '.' . $index . '; database query failed.';
			return false;
		}

		if ( ! self::database_index_matches( $table, $index, $unique, $columns ) ) {
			self::$schema_last_failure = 'required index did not match after automatic repair: ' . $table . '.' . $index . '.';
			return false;
		}

		return true;
	}

	/**
	 * Stamp the plugin DB version only after schema postconditions are verified.
	 *
	 * @param bool $initial_ready Result immediately after dbDelta/create-table calls.
	 * @return void
	 */
	private static function finalize_database_version( $initial_ready ) {
		$ready = (bool) $initial_ready && self::database_schema_ready();
		/* create_database_tables() stamps mobo_core_schema_version only after it
		 * proved the schema in this pass, so this costs no extra SHOW queries. */
		$schema_ready = ! $initial_ready && MOBO_CORE_VERSION === (string) get_option( 'mobo_core_schema_version', '' );
		if ( ! $ready && $schema_ready ) {
			/* The schema postconditions ARE satisfied; only resumable data-migration
			 * prerequisites (Connection foundation, retirement workers) are still in
			 * progress. That is normal progress, not a schema error: do not report one
			 * to admins/the Backend, and clear the false message older builds wrote.
			 * Real failures of those steps keep their own dedicated error options. */
			if ( 0 === strpos( (string) get_option( 'mobo_core_schema_last_error', '' ), self::POSTCONDITION_ERROR_PREFIX ) ) {
				delete_option( 'mobo_core_schema_last_error' );
				delete_option( 'mobo_core_schema_last_error_at' );
			}
			return;
		}
		if ( $ready ) {
			update_option( 'mobo_core_db_version', MOBO_CORE_VERSION, false );
			if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) ) {
				Mobo_Core_Large_Runtime_Storage::reset_request_cache();
			}
			delete_option( 'mobo_core_schema_last_error' );
			delete_option( 'mobo_core_schema_last_error_at' );
			return;
		}

		$error = '' !== self::$schema_last_failure
			? self::POSTCONDITION_ERROR_PREFIX . ': ' . self::$schema_last_failure . ' It will retry on the next request.'
			: self::POSTCONDITION_ERROR_PREFIX . '; it will retry on the next request.';
		update_option( 'mobo_core_schema_last_error', $error, false );
		update_option( 'mobo_core_schema_last_error_at', time(), false );
	}


	/**
	 * Recover stale runtime locks when installing/upgrading to 10.31.71.
	 *
	 * Legacy transient locks could become permanent when their value option
	 * existed without the matching timeout option. The new lock helper stores
	 * token and expiry atomically, and this migration removes every old/current
	 * Mobo runtime lock before normal workers resume.
	 *
	 * Category placeholder repair is deferred until `init`, where WooCommerce has
	 * registered the product_cat taxonomy.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103171_runtime_lock_recovery( $previous_version ) {
		$installed_version = trim( (string) $previous_version );

		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.71', '>=' ) ) {
			return;
		}

		$lock_result = array(
			'deleted' => 0,
			'found'   => 0,
		);

		if ( class_exists( 'Mobo_Core_Lock' ) && method_exists( 'Mobo_Core_Lock', 'force_release_all' ) ) {
			$lock_result = Mobo_Core_Lock::force_release_all();
		}

		update_option( 'mobo_core_103171_lock_cleanup_result', $lock_result, false );
		update_option( 'mobo_core_103171_lock_cleanup_at', time(), false );
		update_option( 'mobo_core_category_placeholder_repair_pending', '1', false );
	}



	/**
	 * Replace the legacy immediate archive-purge boolean with a deferred interval.
	 *
	 * Legacy OFF remains disabled. Legacy ON migrates to the shortest supported
	 * window (15 minutes), preserving freshness without reintroducing per-save
	 * archive purges. New installs keep the normal default from Settings.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10333_archive_purge_interval_migration( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.3', '>=' ) ) {
			return;
		}

		$legacy = get_option( 'mobo_core_cache_purge_archives_on_product_update', false );
		if ( false !== $legacy ) {
			$interval = in_array( strtolower( trim( (string) $legacy ) ), array( '1', 'yes', 'true', 'on' ), true ) ? 15 : 0;
			update_option( 'mobo_core_cache_archive_purge_interval_minutes', (string) $interval, false );
			delete_option( 'mobo_core_cache_purge_archives_on_product_update' );
		}
	}

	/**
	 * Force one safe legacy-image discovery pass after 10.33.8 introduced
	 * catalog-based recovery for old JPEG/PNG attachments without Mobo markers.
	 *
	 * Existing refresh/image queue rows are preserved. Only cached scan and
	 * verification state is invalidated so an in-progress or previously paused
	 * automation cannot skip the new discovery logic. Destructive switches are
	 * turned off and still require the normal explicit approval gate.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10338_legacy_image_catalog_rescan( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.8', '>=' ) ) {
			return;
		}

		$options_to_delete = array(
			'mobo_core_image_refresh_scan_cursor',
			'mobo_core_image_refresh_missing_scan_cursor',
			'mobo_core_image_refresh_enqueue_cursor',
			'mobo_core_image_refresh_missing_enqueue_cursor',
			'mobo_core_image_subsize_scan_cursor',
			'mobo_core_image_subsize_repair_cursor',
			'mobo_core_image_replaced_scan_cursor',
			'mobo_core_image_replaced_delete_cursor',
			'mobo_core_image_refresh_last_scan',
			'mobo_core_image_refresh_last_enqueue',
			'mobo_core_image_refresh_last_result',
			'mobo_core_image_refresh_last_subsize_scan',
			'mobo_core_image_refresh_last_subsize_repair',
			'mobo_core_image_refresh_last_replaced_scan',
			'mobo_core_image_refresh_last_replaced_delete',
			'mobo_core_image_refresh_automation_last_result',
			'mobo_core_image_refresh_automation_completed_at',
			'mobo_core_image_refresh_auto_delete_old_approved',
			'mobo_core_image_refresh_auto_delete_orphan_approved',
			'mobo_core_orphan_image_scan_cursor',
			'mobo_core_orphan_image_cleanup_last_scan',
			'mobo_core_orphan_image_cleanup_last_delete',
		);

		foreach ( $options_to_delete as $option_name ) {
			Mobo_Core_Settings::delete( $option_name );
		}

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
			$cleanup->reset( true );
		}

		Mobo_Core_Settings::update('mobo_core_image_refresh_enabled', '0', false );
		Mobo_Core_Settings::update('mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
		Mobo_Core_Settings::update('mobo_core_image_refresh_catalog_rescan_migrated_at', time(), false );
	}


	/**
	 * Re-open only the replaced-attachment cleanup stages after 10.33.9 added
	 * global old-attachment reference migration before safe deletion.
	 *
	 * Existing image refresh queue rows, downloaded WebP files and completed
	 * product replacements are preserved. Destructive approval is revoked so the
	 * administrator must explicitly review the new migration-aware cleanup step.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10339_image_reference_migration( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.9', '>=' ) ) {
			return;
		}

		foreach ( array(
			'mobo_core_image_replaced_scan_cursor',
			'mobo_core_image_replaced_delete_cursor',
			'mobo_core_image_refresh_last_replaced_scan',
			'mobo_core_image_refresh_last_replaced_delete',
			'mobo_core_image_refresh_automation_last_result',
			'mobo_core_image_refresh_automation_completed_at',
			'mobo_core_image_refresh_auto_delete_old_approved',
		) as $option_name ) {
			Mobo_Core_Settings::delete( $option_name );
		}

		Mobo_Core_Settings::update('mobo_core_image_refresh_automation_enabled', '0', false );
		Mobo_Core_Settings::update('mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_image_reference_migration_enabled_at', time(), false );
	}


	/**
	 * Re-open replaced-attachment cleanup after 10.33.10 introduced structured
	 * JSON and PHP-serialized reference migration.
	 *
	 * Existing queue rows, downloaded WebP files and completed replacements are
	 * preserved. Only Stages 6/7 and destructive approval are reset so retained
	 * legacy images are retried with the safer structured migrator.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103310_structured_image_reference_migration( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.10', '>=' ) ) {
			return;
		}

		foreach ( array(
			'mobo_core_image_replaced_scan_cursor',
			'mobo_core_image_replaced_delete_cursor',
			'mobo_core_image_refresh_last_replaced_scan',
			'mobo_core_image_refresh_last_replaced_delete',
			'mobo_core_image_refresh_automation_last_result',
			'mobo_core_image_refresh_automation_completed_at',
			'mobo_core_image_refresh_auto_delete_old_approved',
		) as $option_name ) {
			Mobo_Core_Settings::delete( $option_name );
		}

		Mobo_Core_Settings::update('mobo_core_image_refresh_automation_enabled', '0', false );
		Mobo_Core_Settings::update('mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_structured_image_reference_migration_enabled_at', time(), false );
	}


	/**
	 * Re-open Stages 6/7 after 10.33.11 made replaced-attachment scanning
	 * timeout-safe and moved the expensive global reference audit to Stage 7.
	 *
	 * 10.33.10 advanced the Stage 6/7 cursor when a batch was fetched, before all
	 * rows were processed. If PHP was interrupted, part of that batch could be
	 * skipped on the next request. Reset only these verification cursors/results;
	 * existing WebP files, queue rows and completed product replacements remain.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103311_replaced_attachment_scan_runtime_safety( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.11', '>=' ) ) {
			return;
		}

		foreach ( array(
			'mobo_core_image_replaced_scan_cursor',
			'mobo_core_image_replaced_delete_cursor',
			'mobo_core_image_refresh_last_replaced_scan',
			'mobo_core_image_refresh_last_replaced_delete',
			'mobo_core_image_refresh_automation_last_result',
			'mobo_core_image_refresh_automation_completed_at',
			'mobo_core_image_refresh_auto_delete_old_approved',
		) as $option_name ) {
			Mobo_Core_Settings::delete( $option_name );
		}

		Mobo_Core_Settings::update('mobo_core_image_refresh_automation_enabled', '0', false );
		Mobo_Core_Settings::update('mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_replaced_attachment_scan_runtime_safety_at', time(), false );
	}


	/**
	 * Make replaced-old-attachment deletion a persistent administrator preference.
	 *
	 * 10.33.12 and earlier used a separate one-time approval option and could
	 * automatically switch mobo_core_image_refresh_delete_old back to 0 while
	 * starting, pausing, invalidating verification state or completing a cycle.
	 * From 10.33.13 the actual setting is authoritative and is never changed by
	 * workflow state transitions. This migration only removes stale approval state;
	 * it deliberately preserves the administrator's current delete-old setting.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103313_persistent_delete_old_preference( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.13', '>=' ) ) {
			return;
		}

		Mobo_Core_Settings::delete('mobo_core_image_refresh_auto_delete_old_approved');

		$last = Mobo_Core_Settings::get('mobo_core_image_refresh_automation_last_result', array() );
		if ( is_array( $last ) && isset( $last['waitingApproval'] ) && 'delete-old' === sanitize_key( (string) $last['waitingApproval'] ) ) {
			unset( $last['waitingApproval'] );
			$delete_enabled = Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' );
			$last['status'] = $delete_enabled ? 'delete-old-setting-enabled' : 'waiting-delete-old-setting';
			$last['needsContinuation'] = $delete_enabled;
			$last['message'] = $delete_enabled
				? 'تنظیم دائمی حذف پیوست قدیمی فعال است و مرحله ۷ بدون تایید مجدد ادامه پیدا می کند.'
				: 'برای مرحله ۷، گزینه حذف پیوست قدیمی بعد از جایگزینی امن را یک بار فعال کنید؛ این انتخاب در ادامه حفظ می شود.';
			Mobo_Core_Settings::update('mobo_core_image_refresh_automation_last_result', $last, false );
		}

		update_option( 'mobo_core_persistent_delete_old_preference_migrated_at', time(), false );
	}


	/**
	 * 10.33.15: Stage 7 is non-blocking per attachment.
	 *
	 * 10.33.14 could disable the whole automation when one old attachment was
	 * retained by the safety audit. If an installation was paused specifically by
	 * that status, resume the existing Stage 7 cursor after upgrade. User-initiated
	 * pauses and unrelated errors are intentionally left untouched.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103315_nonblocking_stage7( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.15', '>=' ) ) {
			return;
		}

		$delete_state = Mobo_Core_Settings::get('mobo_core_image_refresh_last_replaced_delete', array() );
		if ( is_array( $delete_state ) && 'delete' === ( isset( $delete_state['mode'] ) ? (string) $delete_state['mode'] : '' ) ) {
			$legacy_failed = absint( isset( $delete_state['failed'] ) ? $delete_state['failed'] : 0 );
			if ( $legacy_failed > 0 && ! isset( $delete_state['blocked'] ) ) {
				/* 10.33.14 did not distinguish safety blocks from operational errors.
				 * Preserve them conservatively as blocked legacy items for reporting. */
				$delete_state['blocked'] = $legacy_failed;
				$delete_state['errors']  = 0;
				Mobo_Core_Settings::update('mobo_core_image_refresh_last_replaced_delete', $delete_state, false );
			}
		}

		$last = Mobo_Core_Settings::get('mobo_core_image_refresh_automation_last_result', array() );
		if ( is_array( $last ) && 'delete-old-failed' === sanitize_key( isset( $last['status'] ) ? (string) $last['status'] : '' ) ) {
			Mobo_Core_Settings::update('mobo_core_image_refresh_automation_enabled', '1', false );
			$last['success']           = true;
			$last['status']            = 'stage7-resumed-after-upgrade';
			$last['step']              = 7;
			$last['needsContinuation'] = true;
			$last['progressed']        = false;
			$last['message']           = 'مرحله ۷ پس از ارتقا با منطق غیرمسدودکننده ادامه پیدا می کند؛ پیوست های دارای Safety Block ثبت و رد می شوند و کل چرخه را متوقف نمی کنند.';
			Mobo_Core_Settings::update('mobo_core_image_refresh_automation_last_result', $last, false );
		}

		update_option( 'mobo_core_stage7_nonblocking_migrated_at', time(), false );
	}


	/**
	 * 10.33.16: make Stage 7 a fully automatic convergence loop.
	 *
	 * 10.33.15 treated one complete delete pass as authoritative. A site could
	 * therefore report the full image-refresh cycle as completed even though a
	 * later manual Stage 7 pass was still able to migrate references and delete
	 * more legacy attachments. The new workflow repeats full Stage 7 passes while
	 * each pass makes progress and stops only after a zero-progress safety pass.
	 *
	 * Existing Stage 1-6 state, WebP replacements and the Stage 7 cursor are
	 * preserved. If an installation was incorrectly marked completed while Stage 7
	 * still has work, automation is re-enabled directly at Stage 7.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103316_automatic_stage7_convergence( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.16', '>=' ) ) {
			return;
		}

		$scan = Mobo_Core_Settings::get('mobo_core_image_refresh_last_replaced_scan', array() );
		$scan = is_array( $scan ) ? $scan : array();
		$delete = Mobo_Core_Settings::get('mobo_core_image_refresh_last_replaced_delete', array() );
		$delete = is_array( $delete ) ? $delete : array();

		$actionable = ! empty( $scan['cycleComplete'] )
			? absint( isset( $scan['ready'] ) ? $scan['ready'] : 0 ) + absint( isset( $scan['migrationCandidates'] ) ? $scan['migrationCandidates'] : 0 )
			: 0;
		$delete_progress = absint( isset( $delete['passProgress'] )
			? $delete['passProgress']
			: absint( isset( $delete['deleted'] ) ? $delete['deleted'] : 0 )
				+ absint( isset( $delete['referenceRowsUpdated'] ) ? $delete['referenceRowsUpdated'] : 0 ) );

		if ( ! empty( $delete['cycleComplete'] ) ) {
			$delete['passProgress']     = $delete_progress;
			$delete['needsAnotherPass'] = $delete_progress > 0;
			$delete['stableComplete']   = 0 === $delete_progress;
			Mobo_Core_Settings::update('mobo_core_image_refresh_last_replaced_delete', $delete, false );
		}

		$last = Mobo_Core_Settings::get('mobo_core_image_refresh_automation_last_result', array() );
		$last = is_array( $last ) ? $last : array();
		$last_status = sanitize_key( isset( $last['status'] ) ? (string) $last['status'] : '' );
		$delete_in_progress = ! empty( $delete ) && empty( $delete['cycleComplete'] );
		$needs_followup = ! empty( $delete['cycleComplete'] ) && $delete_progress > 0;
		$was_false_complete = 'completed' === $last_status && $actionable > 0;

		if ( $actionable > 0
			&& Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' )
			&& ( $delete_in_progress || $needs_followup || $was_false_complete ) ) {
			Mobo_Core_Settings::update('mobo_core_image_refresh_automation_enabled', '1', false );
			Mobo_Core_Settings::update('mobo_core_image_refresh_automation_completed_at', 0, false );
			$last['success']           = true;
			$last['status']            = 'stage7-auto-resumed-after-upgrade';
			$last['step']              = 7;
			$last['needsContinuation'] = true;
			$last['progressed']        = true;
			$last['message']           = 'Stage 7 با منطق چندگذری خودکار دوباره فعال شد. Cursor فعلی حفظ شده و Cron/Self Runner تا رسیدن به یک گذر کامل بدون پیشرفت جدید، انتقال مراجع و حذف امن را ادامه می دهد.';
			Mobo_Core_Settings::update('mobo_core_image_refresh_automation_last_result', $last, false );
			/*
			 * maybe_run() executes on plugins_loaded, before rest_url() is safe on
			 * all WordPress installations. Queue a one-shot kick and dispatch it
			 * from run_deferred_repairs() after init instead of performing HTTP
			 * during the migration transaction/bootstrap.
			 */
			update_option( 'mobo_core_stage7_resume_kick_pending', '1', false );
		}

		update_option( 'mobo_core_stage7_automatic_convergence_migrated_at', time(), false );
	}


	/**
	 * 10.33.16.1: installation/bootstrap safety hotfix.
	 *
	 * - Re-arms the deferred Stage 7 self-runner kick for installations that
	 *   upgraded from 10.33.16 or were interrupted during the previous migration.
	 * - The sync-event table schema itself is recreated/updated by dbDelta before
	 *   this method, using an index prefix compatible with older 1000-byte limits.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_1033161_install_bootstrap_safety( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.16.1', '>=' ) ) {
			return;
		}

		$scan = Mobo_Core_Settings::get('mobo_core_image_refresh_last_replaced_scan', array() );
		$scan = is_array( $scan ) ? $scan : array();
		$delete = Mobo_Core_Settings::get('mobo_core_image_refresh_last_replaced_delete', array() );
		$delete = is_array( $delete ) ? $delete : array();

		$actionable = ! empty( $scan['cycleComplete'] )
			? absint( isset( $scan['ready'] ) ? $scan['ready'] : 0 ) + absint( isset( $scan['migrationCandidates'] ) ? $scan['migrationCandidates'] : 0 )
			: 0;
		$delete_in_progress = ! empty( $delete ) && empty( $delete['cycleComplete'] );
		$needs_followup = ! empty( $delete['needsAnotherPass'] );

		if ( $actionable > 0
			&& Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' )
			&& ( $delete_in_progress || $needs_followup || '1' === (string) Mobo_Core_Settings::get('mobo_core_image_refresh_automation_enabled', '0' ) ) ) {
			update_option( 'mobo_core_stage7_resume_kick_pending', '1', false );
		}

		update_option( 'mobo_core_1033161_install_bootstrap_safety_at', time(), false );
	}


	/**
	 * 10.33.17.1: automatically arm a safe manual Stage 7 after upgrade.
	 *
	 * Older versions only re-armed Stage 7 when a delete pass had already started.
	 * A store that completed Stage 6 manually, enabled the persistent delete-old
	 * preference and had not yet completed the first delete pass could therefore
	 * still require one administrator click per batch. Preserve all cursors and
	 * simply hand that already-verified Stage 7 to the existing automation runner.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_1033171_stage7_autodrain( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.17.1', '>=' ) ) {
			return;
		}

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' ) ) {
			update_option( 'mobo_core_1033171_stage7_autodrain_migrated_at', time(), false );
			return;
		}

		$scan   = Mobo_Core_Settings::get('mobo_core_image_refresh_last_replaced_scan', array() );
		$delete = Mobo_Core_Settings::get('mobo_core_image_refresh_last_replaced_delete', array() );
		$scan   = is_array( $scan ) ? $scan : array();
		$delete = is_array( $delete ) ? $delete : array();

		$actionable = ! empty( $scan['cycleComplete'] )
			? absint( isset( $scan['ready'] ) ? $scan['ready'] : 0 ) + absint( isset( $scan['migrationCandidates'] ) ? $scan['migrationCandidates'] : 0 )
			: 0;
		$delete_newer = ! empty( $delete['cycleComplete'] )
			&& absint( isset( $delete['checkedAt'] ) ? $delete['checkedAt'] : 0 ) >= absint( isset( $scan['checkedAt'] ) ? $scan['checkedAt'] : 0 );
		$delete_progress = absint( isset( $delete['passProgress'] )
			? $delete['passProgress']
			: absint( isset( $delete['deleted'] ) ? $delete['deleted'] : 0 )
				+ absint( isset( $delete['referenceRowsUpdated'] ) ? $delete['referenceRowsUpdated'] : 0 ) );
		$stable = $delete_newer && 0 === $delete_progress;

		if ( $actionable > 0 && ! $stable ) {
			Mobo_Core_Settings::update('mobo_core_image_refresh_automation_enabled', '1', false );
			Mobo_Core_Settings::update('mobo_core_image_refresh_automation_completed_at', 0, false );
			update_option( 'mobo_core_stage7_resume_kick_pending', '1', false );

			$last = Mobo_Core_Settings::get('mobo_core_image_refresh_automation_last_result', array() );
			$last = is_array( $last ) ? $last : array();
			$last['success']           = true;
			$last['status']            = 'stage7-autodrain-armed-after-upgrade';
			$last['step']              = 7;
			$last['needsContinuation'] = true;
			$last['progressed']        = false;
			$last['message']           = 'Stage 7 پس از ارتقا برای ادامه خودکار فعال شد. Cursor و Safety Audit فعلی بدون ریست حفظ شده اند.';
			Mobo_Core_Settings::update('mobo_core_image_refresh_automation_last_result', $last, false );
		}

		update_option( 'mobo_core_1033171_stage7_autodrain_migrated_at', time(), false );
	}

	/**
	 * Enable the non-destructive image-maintenance invariants introduced in
	 * 10.33.20 and reopen the orphan-family scan for old collision leftovers.
	 * No media file is deleted by this migration.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103320_image_cleanup_recovery( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' !== $installed_version && version_compare( $installed_version, '10.33.20', '>=' ) ) {
			return;
		}

		/* Retire the old hidden execution switch; automation state or an explicit manual action now controls execution. */
		Mobo_Core_Settings::update('mobo_core_image_refresh_enabled', '0', false );
		Mobo_Core_Settings::update('mobo_core_image_refresh_generate_subsizes', '1', false );
		Mobo_Core_Settings::update('mobo_core_image_refresh_cleanup_leftover_subsizes', '1', false );
		delete_option( 'mobo_core_orphan_image_scan_cursor' );
		delete_option( 'mobo_core_orphan_image_cleanup_last_scan' );
		delete_option( 'mobo_core_orphan_image_cleanup_last_delete' );

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) && Mobo_Core_Orphan_Image_Cleanup::table_exists() ) {
			$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
			$cleanup->reset( true );
		}

		update_option( 'mobo_core_103320_image_cleanup_recovery_at', time(), false );
	}

	/**
	 * Re-open non-destructive image integrity audits introduced in 10.33.24.
	 * Existing files are never deleted here; maintenance/refresh workers re-check
	 * local dimensions, subsize metadata and WooCommerce linkage asynchronously.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103324_image_storage_integrity_reaudit( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' !== $installed_version && version_compare( $installed_version, '10.33.24', '>=' ) ) {
			return;
		}

		/* The stronger 10.33.24 health rules need one complete pass from the start;
		 * keeping an old cursor could postpone detection of stale dimensions/files. */
		delete_option( 'mobo_core_image_queue_file_audit_cursor' );
		Mobo_Core_Settings::delete('mobo_core_image_subsize_scan_cursor');
		Mobo_Core_Settings::delete('mobo_core_image_subsize_repair_cursor');
		Mobo_Core_Settings::delete('mobo_core_image_refresh_last_subsize_scan');
		Mobo_Core_Settings::delete('mobo_core_image_refresh_last_subsize_repair');

		/* Reuse the deferred queue recovery hook so existing duplicate/missing gallery
		 * linkage is repaired after WooCommerce has registered its product runtime. */
		update_option( 'mobo_core_image_queue_recovery_pending', '1', false );
		update_option( 'mobo_core_103324_image_storage_reaudit_at', time(), false );
	}


	/**
	 * Apply system-integrity safety guards introduced in 10.33.25.
	 *
	 * This migration is intentionally non-destructive. It retires the legacy cart
	 * option lock and aborts only a pre-10.33.25 deep reconciliation sweep whose
	 * catalog completion was never proven by the stronger response contract.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103325_system_integrity_safety( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' !== $installed_version && version_compare( $installed_version, '10.33.25', '>=' ) ) {
			return;
		}

		delete_option( 'mobo_core_shared_mobo_cart_lock' );

		$state = get_option( 'mobo_core_reconciliation_state', array() );
		if ( is_array( $state )
			&& 'deep' === ( isset( $state['mode'] ) ? (string) $state['mode'] : '' )
			&& 'sweep' === ( isset( $state['phase'] ) ? (string) $state['phase'] : '' )
			&& empty( $state['catalogCompletionValidated'] ) ) {
			$state['status']                     = 'idle';
			$state['phase']                      = '';
			$state['pending']                    = array();
			$state['deepSeen']                   = array();
			$state['catalogValidatedPages']      = 0;
			$state['catalogCompletionValidated'] = false;
			$state['scanCursor']                 = 0;
			$state['nextScanCursor']             = 0;
			$state['scanHasMore']                = false;
			$state['sweepCursor']                = 0;
			$state['updatedAt']                  = time();
			$state['lastError']                  = 'Unsafe legacy deep sweep was aborted during the 10.33.25 integrity migration.';
			$state['lastMessage']                = 'Deep reconciliation will restart from a fully validated catalog snapshot.';
			update_option( 'mobo_core_reconciliation_state', $state, false );
			update_option( 'mobo_core_103325_legacy_sweep_aborted_at', time(), false );
		}

		update_option( 'mobo_core_103325_integrity_safety_at', time(), false );
	}

	/**
	 * Run repairs that require taxonomies registered on WordPress init.
	 *
	 * @return void
	 */
	public static function run_deferred_repairs() {
		self::ensure_103477_release_hygiene_intents();

		/*
		 * Upgrade activation can leave several independent durable intents behind.
		 * Never fire one loopback per intent: collect them and hand the dispatcher a
		 * single wake-up after all cheap/local repair scheduling is complete.
		 */
		$worker_reasons = array();

		/* The convergence-residue self-heal and the singleton-meta dedupe used to run
		 * here, i.e. on storefront `init` of ordinary page views, without a lock. They
		 * now run only inside the real runner's budgeted migration slice
		 * (advance_deferred_release_repair_step(), under the migration mutex), which
		 * needs_budgeted_migration_slice() keeps scheduling while either pending flag
		 * is set. Nothing to do on this request. */

		if ( '1' === (string) get_option( 'mobo_core_103477_revenue_ledger_backfill_pending', '0' )
			&& did_action( 'woocommerce_init' )
			&& class_exists( 'Mobo_Core_Revenue_Ledger' ) ) {
			$ledger = new Mobo_Core_Revenue_Ledger();
			$result = method_exists( $ledger, 'backfill_legacy_connection_ownership' )
				? $ledger->backfill_legacy_connection_ownership( 100 )
				: array( 'status' => 'unavailable', 'remaining' => 1 );
			update_option( 'mobo_core_103477_revenue_ledger_backfill_result', $result, false );
			update_option( 'mobo_core_103477_revenue_ledger_backfill_at', time(), false );
			if ( is_array( $result ) && 0 === absint( $result['remaining'] ?? 0 ) ) {
				delete_option( 'mobo_core_103477_revenue_ledger_backfill_pending' );
			}
		}

		self::maybe_finalize_103477_release_hygiene();

		if ( '1' === (string) get_option( 'mobo_core_worker_dispatch_pending', '0' ) ) {
			$worker_reasons[] = 'pending-dispatch';
		}

		if ( '1' === (string) get_option( 'mobo_core_stage7_resume_kick_pending', '0' ) ) {
			delete_option( 'mobo_core_stage7_resume_kick_pending' );
			$worker_reasons[] = 'stage7-auto-resume-upgrade';
		}

		if ( '1' === (string) get_option( 'mobo_core_category_placeholder_repair_pending', '0' )
			&& taxonomy_exists( 'product_cat' ) ) {
			$result = self::repair_placeholder_category_titles_from_map();
			update_option( 'mobo_core_category_placeholder_repair_result', $result, false );
			update_option( 'mobo_core_category_placeholder_repair_at', time(), false );
			delete_option( 'mobo_core_category_placeholder_repair_pending' );
		}

		$product_recovery_requested = '1' === (string) get_option( 'mobo_core_product_recovery_kick_pending', '0' );
		if ( $product_recovery_requested ) {
			delete_option( 'mobo_core_product_recovery_kick_pending' );
			$worker_reasons[] = 'product-retention-recovery';
		}

		if ( '1' === (string) get_option( 'mobo_core_image_queue_recovery_pending', '0' )
			&& class_exists( 'Mobo_Core_Image_Queue' )
			&& Mobo_Core_Image_Queue::table_exists() ) {
			$failed_recovery = method_exists( 'Mobo_Core_Image_Queue', 'recover_legacy_failed' )
				? Mobo_Core_Image_Queue::recover_legacy_failed( 500 )
				: array( 'status' => 'unavailable', 'recovered' => 0, 'remaining' => 0 );
			$linkage_recovery = method_exists( 'Mobo_Core_Image_Queue', 'schedule_linkage_repairs' )
				? Mobo_Core_Image_Queue::schedule_linkage_repairs( 200 )
				: array( 'status' => 'unavailable', 'scheduled' => 0 );

			$result = array(
				'failedRows' => $failed_recovery,
				'linkage'    => $linkage_recovery,
				'executedAt' => time(),
			);
			update_option( 'mobo_core_103199_image_queue_recovery_result', $result, false );
			update_option( 'mobo_core_103199_image_queue_recovery_at', time(), false );

			$scheduled_work = ( isset( $failed_recovery['recovered'] ) ? absint( $failed_recovery['recovered'] ) : 0 )
				+ ( isset( $linkage_recovery['scheduled'] ) ? absint( $linkage_recovery['scheduled'] ) : 0 );
			if ( $scheduled_work > 0 ) {
				$worker_reasons[] = 'image-queue-recovery';
			}
			if ( empty( $failed_recovery['remaining'] ) ) {
				delete_option( 'mobo_core_image_queue_recovery_pending' );
			}
		}


		if ( '1' === (string) get_option( 'mobo_core_103473_post_upgrade_convergence_pending', '0' )
			&& did_action( 'woocommerce_init' )
			&& self::terminal_retirements_physically_complete()
			&& class_exists( 'Mobo_Core_Product_Sync' ) ) {
			$start = null;
			$default = null;
			$default_id = 0;
			$provisioned = false;
			if ( class_exists( 'Mobo_Core_Connection_Repository' ) ) {
				$default = method_exists( 'Mobo_Core_Connection_Repository', 'get_default_fresh' )
					? Mobo_Core_Connection_Repository::get_default_fresh()
					: Mobo_Core_Connection_Repository::get_default();
				$default_id = is_array( $default ) ? absint( $default['id'] ?? 0 ) : 0;
				$provisioned = $default_id > 0
					&& method_exists( 'Mobo_Core_Connection_Repository', 'is_provisioned' )
					&& Mobo_Core_Connection_Repository::is_provisioned( $default );
			}

			/* Do not freeze/consume the authoritative target set against the pre-
			 * provisioning Source boundary. Signed provisioning may invalidate old
			 * terminal evidence and make rows newly actionable. Preserve the durable
			 * pending intent until a fully provisioned Default Connection exists; the
			 * next ordinary request/real-cron slice will classify against that boundary. */
			if ( ! $provisioned ) {
				update_option( 'mobo_core_103473_post_upgrade_convergence_deferred_reason', 'awaiting-provisioning', false );
				update_option( 'mobo_core_103473_post_upgrade_convergence_deferred_at', time(), false );
			} else {
				delete_option( 'mobo_core_103473_post_upgrade_convergence_deferred_reason' );
				$runner = static function () {
					$sync = new Mobo_Core_Product_Sync();
					return $sync->start_targeted_convergence_recovery( 'post-upgrade-convergence' );
				};
				if ( class_exists( 'Mobo_Core_Connection_Context' ) ) {
					$start = Mobo_Core_Connection_Context::run( $default_id, $runner );
				}
				if ( null === $start ) { $start = $runner(); }
				update_option( 'mobo_core_103473_post_upgrade_convergence_start_result', $start, false );
				update_option( 'mobo_core_103473_post_upgrade_convergence_start_at', time(), false );
				if ( is_array( $start ) && ! empty( $start['success'] ) ) {
					delete_option( 'mobo_core_103473_post_upgrade_convergence_pending' );
					$target_count = absint( $start['data']['targetCount'] ?? 0 );
					$already = ! empty( $start['data']['alreadyAccepted'] );
					if ( $target_count > 0 || $already ) {
						$worker_reasons[] = 'post-upgrade-convergence';
					}
				}
			}
		}

		$worker_reasons = array_values( array_unique( array_filter( array_map( 'sanitize_key', $worker_reasons ) ) ) );
		if ( empty( $worker_reasons ) || ! class_exists( 'Mobo_Core_Self_Runner' ) || ! method_exists( 'Mobo_Core_Self_Runner', 'kick' ) ) {
			return;
		}

		/* A pending-only dispatch is an internal relay, not a fresh producer.
		 * Unlike an actual newly scheduled repair, it must respect the same
		 * recurring Real-Cron fairness window as bounded worker continuation.
		 * Preserve the durable pending option; the real cron claim consumes it. */
		$pending_only_relay = 1 === count( $worker_reasons ) && 'pending-dispatch' === $worker_reasons[0];

		/* mobo-cron.php defines its context before loading WordPress. During
		 * init, a pending-only relay must not launch a competing worker before
		 * mobo-cron.php can claim the dispatcher itself. The pending flag remains
		 * durable: the real cron claim consumes it only after acquiring the lock.
		 * Real repair producers and ordinary web/worker boots remain unchanged. */
		if ( self::should_defer_pending_relay_to_local_cron( $pending_only_relay ) ) {
			return;
		}

		$kick_reason = $pending_only_relay ? 'deferred-pending-dispatch' : 'deferred-repair-coalesced';
		$kick = Mobo_Core_Self_Runner::kick( $kick_reason, true );
		update_option(
			'mobo_core_deferred_repair_coalesced_kick_result',
			array(
				'reasons' => $worker_reasons,
				'kick'    => $kick,
				'at'      => time(),
			),
			false
		);

		if ( in_array( 'stage7-auto-resume-upgrade', $worker_reasons, true ) ) {
			update_option( 'mobo_core_stage7_resume_deferred_kick_result', $kick, false );
			update_option( 'mobo_core_stage7_resume_deferred_kick_at', time(), false );
		}
		if ( in_array( 'product-retention-recovery', $worker_reasons, true ) ) {
			update_option( 'mobo_core_103329_product_recovery_kick_result', $kick, false );
			update_option( 'mobo_core_103329_product_recovery_kick_at', time(), false );
			if ( ! is_array( $kick ) || empty( $kick['success'] ) ) {
				update_option( 'mobo_core_product_recovery_kick_pending', '1', false );
			}
		}
	}



	/**
	 * Repair old `Mobo Category <GUID>` names when the mapping table already has
	 * a real remote category name.
	 *
	 * Normal customer-edited category names are never changed.
	 *
	 * @return array Repair summary.
	 */
	private static function repair_placeholder_category_titles_from_map() {
		global $wpdb;

		$result = array(
			'checked'  => 0,
			'repaired' => 0,
			'skipped'  => 0,
			'failed'   => 0,
		);

		if ( ! class_exists( 'Mobo_Core_Category_Map' ) || ! Mobo_Core_Category_Map::table_exists()
			|| ! class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			return $result;
		}
		$default = Mobo_Core_Connection_Repository::get_default();
		$default_id = is_array( $default ) ? absint( $default['id'] ?? 0 ) : 0;
		if ( $default_id <= 0 ) { return $result; }

		$table = Mobo_Core_Category_Map::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT remote_guid, synced_term_id, remote_name
				FROM {$table}
				WHERE connection_id=%d AND synced_term_id > 0 AND remote_name <> ''
				ORDER BY id ASC
				LIMIT 1000",
				$default_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return $result;
		}

		foreach ( $rows as $row ) {
			$result['checked']++;

			$guid        = trim( sanitize_text_field( isset( $row['remote_guid'] ) ? (string) $row['remote_guid'] : '' ) );
			$term_id     = absint( isset( $row['synced_term_id'] ) ? $row['synced_term_id'] : 0 );
			$remote_name = trim( sanitize_text_field( isset( $row['remote_name'] ) ? (string) $row['remote_name'] : '' ) );

			if (
				'' === $guid
				|| $term_id <= 0
				|| '' === $remote_name
				|| 0 === strcasecmp( $remote_name, $guid )
				|| 0 === strcasecmp( $remote_name, 'Mobo Category ' . $guid )
				|| 1 === preg_match( '/^Mobo Category\s+[0-9a-f-]{16,}$/i', $remote_name )
			) {
				$result['skipped']++;
				continue;
			}

			$term = get_term( $term_id, 'product_cat' );

			if ( ! $term instanceof WP_Term || 0 !== strcasecmp( trim( (string) $term->name ), 'Mobo Category ' . $guid ) ) {
				$result['skipped']++;
				continue;
			}

			$updated = wp_update_term(
				$term_id,
				'product_cat',
				array( 'name' => $remote_name )
			);

			if ( is_wp_error( $updated ) ) {
				$result['failed']++;
				continue;
			}

			$result['repaired']++;
		}

		return $result;
	}


	/**
	 * Convert orphan-image cleanup from noisy per-file rows to one row per image
	 * family and reset bounded scan cursors introduced in 10.31.64.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103164_image_family_migration( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' === $installed_version ) {
			$installed_version = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		}

		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.64', '>=' ) ) {
			return;
		}

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) && method_exists( 'Mobo_Core_Orphan_Image_Cleanup', 'migrate_to_family_rows' ) ) {
			Mobo_Core_Orphan_Image_Cleanup::migrate_to_family_rows();
		}

		Mobo_Core_Settings::delete('mobo_core_image_refresh_scan_cursor');
		Mobo_Core_Settings::delete('mobo_core_image_refresh_enqueue_cursor');
		Mobo_Core_Settings::delete('mobo_core_image_refresh_last_scan');
		Mobo_Core_Settings::delete('mobo_core_image_refresh_last_enqueue');
	}


	/**
	 * Apply the conservative image-maintenance defaults introduced in 10.31.65.
	 *
	 * Old releases defaulted destructive image cleanup options to enabled. On the
	 * first 10.31.65 run they are switched off so the administrator must complete
	 * the new health scan and explicitly opt in again. Non-destructive refresh
	 * processing may continue, but old attachments and orphan families are kept.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103165_image_refresh_safety( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' === $installed_version ) {
			$installed_version = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		}

		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.65', '>=' ) ) {
			return;
		}

		Mobo_Core_Settings::update('mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
		Mobo_Core_Settings::delete('mobo_core_image_subsize_scan_cursor');
		Mobo_Core_Settings::delete('mobo_core_image_subsize_repair_cursor');
		Mobo_Core_Settings::delete('mobo_core_image_replaced_scan_cursor');
		Mobo_Core_Settings::delete('mobo_core_image_replaced_delete_cursor');
		Mobo_Core_Settings::delete('mobo_core_image_refresh_last_subsize_scan');
		Mobo_Core_Settings::delete('mobo_core_image_refresh_last_subsize_repair');
		Mobo_Core_Settings::delete('mobo_core_image_refresh_last_replaced_scan');
		Mobo_Core_Settings::delete('mobo_core_image_refresh_last_replaced_delete');
	}


	/**
	 * Enforce non-editable Mobo endpoints and initialize legacy health options.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103166_admin_health_defaults( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.66', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_health_report_enabled', '1', false );
		delete_option( 'mobo_core_health_report_url' );
		delete_option( 'mobo_core_checkout_mobo_site_url' );
	}



	/**
	 * Re-open recoverable image failures and repair the old import/linkage race.
	 *
	 * The work is deferred and bounded so plugin activation/upgrade does not run a
	 * product Sync or Repair. Existing queue rows are reused.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103199_image_queue_recovery( $previous_version ) {
		$installed_version = trim( (string) $previous_version );

		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.99', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_image_queue_recovery_pending', '1', false );
	}

	/**
	 * Prevent legacy migration flags from starting a heavy Repair automatically.
	 * Existing active operations are not cancelled.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103198_manual_initial_sync_safety( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.98', '>=' ) ) {
			return;
		}

		delete_option( 'mobo_core_desired_state_repair_queue_pending' );
	}

	/**
	 * Disable the retired outbound health-report path.
	 *
	 * Portal 51+ pulls the authenticated /health endpoint. Existing installs may
	 * still have the legacy option enabled from older releases, so normalize it
	 * once when upgrading to 10.31.94.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103194_health_pull_only( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.94', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_health_report_enabled', '0', false );
		delete_option( 'mobo_core_health_report_url' );
		Mobo_Core_Settings::delete( 'mobo_core_health_last_report_attempt_at' );
		Mobo_Core_Settings::delete( 'mobo_core_health_last_report_success_at' );
		Mobo_Core_Settings::delete( 'mobo_core_health_last_report_result' );
	}

	/**
	 * Start the strict image workflow with destructive switches disabled.
	 *
	 * Existing scan and queue progress is kept. Refresh execution and both
	 * destructive opt-ins are turned off so the new state machine can unlock each
	 * one at the correct stage.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103167_image_workflow_safety( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.67', '>=' ) ) {
			return;
		}

		Mobo_Core_Settings::update('mobo_core_image_refresh_enabled', '0', false );
		Mobo_Core_Settings::update('mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
	}


	/**
	 * Introduce image-refresh automation with every destructive approval off.
	 *
	 * Existing scan/queue progress is preserved. The coordinator starts only after
	 * an administrator explicitly presses the safe automation button.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103168_image_automation_safety( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.68', '>=' ) ) {
			return;
		}

		Mobo_Core_Settings::update('mobo_core_image_refresh_automation_enabled', '0', false );
		Mobo_Core_Settings::update('mobo_core_image_refresh_auto_delete_old_approved', '0', false );
		Mobo_Core_Settings::update('mobo_core_image_refresh_auto_delete_orphan_approved', '0', false );
		Mobo_Core_Settings::update('mobo_core_image_refresh_enabled', '0', false );
		Mobo_Core_Settings::update('mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
	}

	/**
	 * Mark one authoritative manual Repair as required after installing the
	 * desired-state variation engine. The migration never starts that heavy job.
	 *
	 * @param string $previous_version Previously stored DB version.
	 * @return void
	 */
	private static function apply_103177_desired_state_repair( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.31.77', '>=' ) ) {
			return;
		}

		if ( ! self::has_legacy_mobo_content() ) {
			return;
		}

		Mobo_Core_Settings::delete( 'mobo_core_repair_completed_at' );
		Mobo_Core_Settings::delete( 'mobo_core_repair_last_sync_id' );
		update_option( 'mobo_core_legacy_repair_required', '1', false );
		update_option( 'mobo_core_desired_state_repair_required', '1', false );
		delete_option( 'mobo_core_desired_state_repair_queue_pending' );
		update_option( 'mobo_core_desired_state_repair_marked_at', time(), false );
	}

	/**
	 * Mark legacy installs as requiring one manual Repair pass.
	 *
	 * Version 7 installs can have products/images created before the new map,
	 * image queue and hash-bypass repair flow existed. We do not run Repair during
	 * upgrade because it can be heavy; we only persist a clear admin requirement.
	 *
	 * @param string $previous_version Previously stored DB version.
	 * @return void
	 */

	/**
	 * Backfill persistence fields that older builds wrote inconsistently.
	 *
	 * Product/variation source hashes already exist in postmeta, so copying them to
	 * the indexed product map is deterministic. sync_incomplete is likewise mirrored
	 * from the canonical postmeta crash marker. No storefront field is mutated here.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10334412_persistence_integrity_backfill( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.44.12', '>=' ) ) {
			return;
		}
		if ( ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists() ) {
			return;
		}

		/*
		 * MOBO-4444: retire the original bulk Product Map backfill.
		 *
		 * Legacy postmeta is bootstrap evidence, not canonical Map evidence. The old
		 * migration overwrote last_hash/sync_incomplete and deleted variation rows via
		 * unlocked bulk SQL. Current sync/Repair and the lock-safe legacy seeder now own
		 * convergence. Keeping this historical migration read/write-neutral avoids
		 * destructive canonical election during upgrades from pre-10.33.44.12 builds.
		 */
		update_option( 'mobo_core_10334412_map_hash_backfill_count', 0, false );
		update_option( 'mobo_core_10334412_map_incomplete_backfill_count', 0, false );
		update_option( 'mobo_core_10334412_stale_parent_variation_map_cleanup_count', 0, false );
		update_option( 'mobo_core_10334412_persistence_backfill_retired_safe', '1', false );
		update_option( 'mobo_core_10334412_persistence_backfill_at', time(), false );
	}

	private static function maybe_mark_legacy_repair_required( $previous_version ) {
		$previous_version = trim( (string) $previous_version );

		if ( class_exists( 'Mobo_Core_Product_Sync' ) && Mobo_Core_Product_Sync::is_repair_completed() ) {
			update_option( 'mobo_core_legacy_repair_required', '0', false );
			return;
		}

		$looks_legacy = false;
		if ( '' !== $previous_version ) {
			$looks_legacy = version_compare( $previous_version, '10.0.0', '<' );
		} else {
			$looks_legacy = self::has_legacy_mobo_content();
		}

		if ( $looks_legacy ) {
			update_option( 'mobo_core_legacy_repair_required', '1', false );
		}
	}

	/**
	 * Detect legacy Mobo products/attachments on installs that do not have a stored DB version.
	 *
	 * @return bool
	 */
	private static function has_legacy_mobo_content() {
		global $wpdb;

		$product_meta_count = absint(
			$wpdb->get_var(
				"SELECT COUNT(1)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type IN ('product','product_variation')
				AND p.post_status NOT IN ('trash','auto-draft')
				AND pm.meta_key IN ('product_guid','variant_guid','portal_product_id','mobo_portal_product_id','_mobo_portal_product_id','PortalProductId','mobo_url')
				AND pm.meta_value <> ''
				LIMIT 1"
			)
		);

		if ( $product_meta_count > 0 ) {
			return true;
		}

		$attachment_meta_count = absint(
			$wpdb->get_var(
				"SELECT COUNT(1)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = 'attachment'
				AND pm.meta_key IN ('image_guid','img_guid','mobo_source_url')
				AND pm.meta_value <> ''
				LIMIT 1"
			)
		);

		return $attachment_meta_count > 0;
	}




	/**
	 * Establish append-only product ownership and schedule one automatic recovery
	 * pass for upgrades from versions that could physically delete parent products.
	 * Existing customer settings, including OnlyInStock, are never changed.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103329_product_retention_recovery( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.29', '>=' ) ) {
			return;
		}

		$has_existing_evidence = false;
		if ( class_exists( 'Mobo_Core_Product_Ledger' ) ) {
			$seed = Mobo_Core_Product_Ledger::seed_existing_evidence();
			update_option( 'mobo_core_103329_product_ledger_seed_result', $seed, false );
			update_option( 'mobo_core_103329_product_ledger_seed_at', time(), false );
			$has_existing_evidence = ! empty( Mobo_Core_Product_Ledger::get_after_id( 0, 1 ) );
		}

		/*
		 * A blank DB version normally means a fresh install. Treat it as an existing
		 * legacy installation when durable Mobo product evidence is already present;
		 * this keeps recovery available after unusual/manual DB-version loss.
		 */
		if ( ( '' !== $previous_version || $has_existing_evidence ) && class_exists( 'Mobo_Core_Product_Recovery' ) ) {
			Mobo_Core_Product_Recovery::schedule( 'upgrade-10.33.29' );
			update_option( 'mobo_core_103329_product_recovery_scheduled_at', time(), false );
			update_option( 'mobo_core_maintenance_next_due_at', time(), false );
			update_option( 'mobo_core_product_recovery_kick_pending', '1', false );
		}
	}


	/**
	 * One-time 10.33.33 recovery re-audit.
	 *
	 * 10.33.32 could miss the 10.33.29 recovery scheduling step when an upgrade
	 * was performed through deactivate/replace/activate: activation stamped the
	 * new DB version before maybe_run() could observe the old version. Re-arm one
	 * bounded site-scoped recovery generation for every existing pre-10.33.33
	 * installation so those sites are healed automatically as well. Fresh installs
	 * remain quiet unless durable local Mobo product evidence already exists.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103333_product_recovery_reaudit( $previous_version ) {
		$previous_version = trim( (string) $previous_version );

		/* Fresh installs and upgrades from pre-10.33.29 are already handled by the
		 * original retention migration immediately above. This re-audit exists only
		 * for the 10.33.29..10.33.32 window where an activation-style upgrade could
		 * have stamped the version without ever scheduling that migration. */
		if ( '' === $previous_version
			|| version_compare( $previous_version, '10.33.29', '<' )
			|| version_compare( $previous_version, '10.33.33', '>=' ) ) {
			return;
		}

		/* Schema self-heal may intentionally leave the old DB version in place for
		 * another request. Do not repeat the full evidence seed on every request once
		 * this one-shot generation has already been armed. */
		if ( absint( get_option( 'mobo_core_103333_product_recovery_reaudit_scheduled_at', 0 ) ) > 0 ) {
			return;
		}

		if ( class_exists( 'Mobo_Core_Product_Ledger' ) ) {
			$seed = Mobo_Core_Product_Ledger::seed_existing_evidence();
			update_option( 'mobo_core_103333_product_ledger_reaudit_seed_result', $seed, false );
			update_option( 'mobo_core_103333_product_ledger_reaudit_seed_at', time(), false );
		}

		/* A versioned installation may have Portal-only delivery evidence even when
		 * every local trace of a previously deleted product has already vanished. */
		if ( class_exists( 'Mobo_Core_Product_Recovery' ) ) {
			Mobo_Core_Product_Recovery::schedule( 'upgrade-10.33.33-reaudit' );
			update_option( 'mobo_core_103333_product_recovery_reaudit_scheduled_at', time(), false );
			update_option( 'mobo_core_maintenance_next_due_at', time(), false );
			update_option( 'mobo_core_product_recovery_kick_pending', '1', false );
		}
	}

	/**
	 * One-time 10.33.35 storefront-integrity re-audit.
	 *
	 * Older installations could keep a locally missing product or a variation with
	 * only a subset of the parent's attributes while Portal ContentHash remained
	 * unchanged. Re-run the existing site-scoped recovery ledger once; healthy local
	 * products are skipped cheaply, while missing/drifted products take the exact GUID
	 * desired-state path.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103335_variation_integrity_reaudit( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.35', '>=' ) ) {
			return;
		}

		if ( absint( get_option( 'mobo_core_103335_variation_integrity_reaudit_scheduled_at', 0 ) ) > 0 ) {
			return;
		}

		if ( class_exists( 'Mobo_Core_Product_Ledger' ) ) {
			$seed = Mobo_Core_Product_Ledger::seed_existing_evidence();
			update_option( 'mobo_core_103335_variation_integrity_ledger_seed_result', $seed, false );
			update_option( 'mobo_core_103335_variation_integrity_ledger_seed_at', time(), false );
		}

		if ( class_exists( 'Mobo_Core_Product_Recovery' ) ) {
			Mobo_Core_Product_Recovery::schedule_followup( Mobo_Core_Product_Recovery::VARIATION_INTEGRITY_REASON );
			update_option( 'mobo_core_103335_variation_integrity_reaudit_scheduled_at', time(), false );
			update_option( 'mobo_core_maintenance_next_due_at', time(), false );
			update_option( 'mobo_core_product_recovery_kick_pending', '1', false );
		}
	}


	/**
	 * One-time 10.33.36 recovery-state self-heal.
	 *
	 * Versions through 10.33.35 could arm product-recovery pending without creating
	 * the state row when that option was entirely absent. schedule_followup() also
	 * treated pending + empty-array state as an active generation. Re-arm one fresh
	 * site-scoped generation after upgrading so installs that crossed that window
	 * cannot remain permanently pending with no generation/cursor. If an older
	 * generation is genuinely active, the fixed follow-up scheduler queues this
	 * self-heal behind it instead of overlapping it.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103336_recovery_state_selfheal( $previous_version ) {
		$previous_version = trim( (string) $previous_version );

		if ( '' === $previous_version || version_compare( $previous_version, '10.33.36', '>=' ) ) {
			return;
		}

		if ( absint( get_option( 'mobo_core_103336_recovery_state_selfheal_scheduled_at', 0 ) ) > 0 ) {
			return;
		}

		if ( class_exists( 'Mobo_Core_Product_Recovery' ) ) {
			Mobo_Core_Product_Recovery::schedule_followup( 'upgrade-10.33.36-recovery-state-selfheal' );
			update_option( 'mobo_core_103336_recovery_state_selfheal_scheduled_at', time(), false );
			update_option( 'mobo_core_maintenance_next_due_at', time(), false );
			update_option( 'mobo_core_product_recovery_kick_pending', '1', false );
		}
	}

	/**
	 * One-time 10.33.38 variation-integrity reason self-heal.
	 *
	 * 10.33.35/10.33.36 sanitized a dotted reason to upgrade-103335-..., while
	 * the recovery worker compared against a hyphenated literal. Sites that already
	 * crossed those builds therefore need one serialized authoritative ledger pass.
	 * Older sites execute the corrected 10.33.35 migration directly and are not
	 * scheduled twice.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103337_variation_integrity_reason_selfheal( $previous_version ) {
		$previous_version = trim( (string) $previous_version );

		if ( '' === $previous_version
			|| version_compare( $previous_version, '10.33.35', '<' )
			|| version_compare( $previous_version, '10.33.38', '>=' ) ) {
			return;
		}

		if ( absint( get_option( 'mobo_core_103337_variation_integrity_reason_selfheal_scheduled_at', 0 ) ) > 0 ) {
			return;
		}

		if ( class_exists( 'Mobo_Core_Product_Recovery' ) ) {
			Mobo_Core_Product_Recovery::schedule_followup( Mobo_Core_Product_Recovery::VARIATION_INTEGRITY_REASON );
			update_option( 'mobo_core_103337_variation_integrity_reason_selfheal_scheduled_at', time(), false );
			update_option( 'mobo_core_maintenance_next_due_at', time(), false );
			update_option( 'mobo_core_product_recovery_kick_pending', '1', false );
		}
	}

	/**
	 * Re-arm active webhook rows stranded by the old nullable-stock exception path.
	 *
	 * Terminal failed history is deliberately left untouched: replaying an old
	 * desired state after a newer event completed could regress stock. Only active
	 * retry rows and expired processing leases are reset, then the normal ordered
	 * queue worker applies them with the current nullable-stock contract.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_1033443_nullable_stock_webhook_recovery( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.44.3', '>=' ) ) {
			return;
		}

		$recovered = 0;
		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) && Mobo_Core_Sync_Event_Store::table_exists() ) {
			$store = new Mobo_Core_Sync_Event_Store();
			if ( method_exists( $store, 'recover_legacy_nullable_stock_blockers' ) ) {
				$recovered = $store->recover_legacy_nullable_stock_blockers( 1000 );
			}
		}

		update_option( 'mobo_core_1033443_nullable_stock_webhook_recovered', absint( $recovered ), false );
		update_option( 'mobo_core_1033443_nullable_stock_webhook_recovery_at', time(), false );

		if ( $recovered > 0 ) {
			update_option( 'mobo_core_worker_dispatch_pending', '1', false );
		}
	}

	/**
	 * Disable product-mutating reconciliation until its API snapshots carry the
	 * same post-lock webhook ordering protection as manual Repair.
	 *
	 * Any persisted in-flight state is made idle and its cached pending snapshots
	 * are discarded. Product data and webhook health rows are not changed.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_1033446_disable_reconciliation( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.44.6', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_auto_reconciliation_enabled', '0', false );

		$state = get_option( 'mobo_core_reconciliation_state', array() );
		if ( is_array( $state ) && ! empty( $state ) ) {
			$state['status']                     = 'idle';
			$state['mode']                       = '';
			$state['phase']                      = '';
			$state['source']                     = 'disabled-by-build';
			$state['pending']                    = array();
			$state['deepSeen']                   = array();
			$state['catalogValidatedPages']      = 0;
			$state['catalogCompletionValidated'] = false;
			$state['scanCursor']                 = 0;
			$state['nextScanCursor']             = 0;
			$state['scanHasMore']                = false;
			$state['sweepCursor']                = 0;
			$state['moreChanges']                = false;
			$state['revisionFailed']             = false;
			$state['updatedAt']                  = time();
			$state['completedAt']                = time();
			$state['lastMessage']                = 'Reconciliation is disabled by this Mobo Core build.';
			$state['lastError']                  = '';
			update_option( 'mobo_core_reconciliation_state', $state, false );
		}

		update_option(
			'mobo_core_reconciliation_last_result',
			array(
				'success'             => true,
				'status'              => 'disabled-by-build',
				'processedProducts'   => 0,
				'processedVariations' => 0,
				'needsContinuation'   => false,
				'executedAt'          => time(),
			),
			false
		);
	}

	/**
	 * Retire automatic Product Recovery now that authoritative manual Repair and
	 * ordered webhooks own product restoration. This prevents an upgrade-scheduled
	 * generation from replaying a persisted repair payload or consuming runner/image
	 * budget after a successful full Repair.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_1033447_disable_product_recovery( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.44.7', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_product_recovery_pending', '0', false );
		delete_option( 'mobo_core_product_recovery_followup_reason' );
		delete_option( 'mobo_core_product_recovery_kick_pending' );

		$state = get_option( 'mobo_core_product_recovery_state', array() );
		if ( is_array( $state ) && ! empty( $state ) ) {
			$state['status']          = 'disabled-by-build';
			$state['phase']           = 'disabled-by-build';
			$state['manifestBuffer']  = array();
			$state['currentSource']   = '';
			$state['currentGuid']     = '';
			$state['currentCursor']   = 0;
			$state['currentPayload']  = array();
			$state['currentAttempts'] = 0;
			$state['nextRetryAt']     = 0;
			$state['lastError']       = '';
			$state['updatedAt']       = time();
			$state['completedAt']     = time();
			update_option( 'mobo_core_product_recovery_state', $state, false );
		}

		delete_option( 'mobo_core_post_recovery_warmup_pending' );
		if ( class_exists( 'Mobo_Core_Lock' ) ) {
			Mobo_Core_Lock::force_release( 'recovery_pipeline' );
			Mobo_Core_Lock::force_release( 'product_recovery' );
			Mobo_Core_Lock::force_release( 'adaptive_reconciliation' );
		}

		/* Rows already beyond the new exact-attempt escape hatch would otherwise
		 * never execute it. Re-arm only the distinctive readiness failure at attempt
		 * two; the next normal queue run performs attempt three and applies the same
		 * bounded quarantine path as a newly failing row. No attachment/file is
		 * changed by this migration itself. */
		$rearmed = 0;
		if ( class_exists( 'Mobo_Core_Image_Queue' ) && Mobo_Core_Image_Queue::table_exists() ) {
			global $wpdb;
			$table = Mobo_Core_Image_Queue::table_name();
			$like  = $wpdb->esc_like( 'Image file exists but final WebP/subsize validation failed:' ) . '%';
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET status = 'pending', try_count = 2, next_retry_at = NULL, locked_until = NULL
					WHERE status IN ('pending','processing','attaching')
						AND try_count > 2
						AND last_error LIKE %s",
					$like
				)
			);
			$rearmed = false === $result ? 0 : absint( $result );
		}
		update_option( 'mobo_core_1033447_image_fresh_reimport_rearmed', $rearmed, false );
		update_option( 'mobo_core_1033447_runtime_shutdown_at', time(), false );
	}

	/**
	 * Make disk cleanup the first image-refresh stage and keep every product repair
	 * decision outside the image workflow.
	 *
	 * Existing large scan settings are capped because one wildcard-heavy media audit
	 * can otherwise exhaust a shared-host request budget before persisting its cursor.
	 * Deleted cleanup history is retained; only rescanable candidate state is reset.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_1033448_image_storage_preflight( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.44.8', '>=' ) ) {
			return;
		}

		Mobo_Core_Settings::delete('mobo_core_image_refresh_preflight_cleanup_completed_at');
		Mobo_Core_Settings::delete('mobo_core_image_refresh_repair_retry_count');
		Mobo_Core_Settings::delete('mobo_core_image_refresh_repair_retry_at');
		delete_option( 'mobo_core_incomplete_collision_cleanup_cursor' );
		delete_option( 'mobo_core_incomplete_collision_cleanup_last_result' );

		$refresh_scan = absint( Mobo_Core_Settings::get('mobo_core_image_refresh_scan_limit', 50 ) );
		if ( $refresh_scan <= 0 || $refresh_scan > 50 ) {
			Mobo_Core_Settings::update('mobo_core_image_refresh_scan_limit', 50, false );
		}
		$orphan_scan = absint( get_option( 'mobo_core_orphan_image_scan_limit', 50 ) );
		if ( $orphan_scan <= 0 || $orphan_scan > 50 ) {
			update_option( 'mobo_core_orphan_image_scan_limit', 50, false );
		}

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
			$cleanup->reset( true );
		}

		update_option( 'mobo_core_1033448_image_storage_preflight_at', time(), false );
	}

	/**
	 * Re-arm Image Refresh as a full canonical-source generation. Completed queue
	 * rows from older releases must not suppress a fresh download when the remote
	 * file changed behind the same URL.
	 *
	 * An already-running workflow is never reset mid-flight. It can finish safely;
	 * the next administrator start creates the new generation.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_1033449_full_source_image_refresh( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.44.9', '>=' ) ) {
			return;
		}

		Mobo_Core_Settings::update('mobo_core_image_refresh_force_source_reimport', '1', false );
		if ( '1' !== (string) Mobo_Core_Settings::get('mobo_core_image_refresh_automation_enabled', '0' ) ) {
			Mobo_Core_Settings::delete('mobo_core_image_refresh_generation_id');
			Mobo_Core_Settings::delete('mobo_core_image_refresh_generation_started_at');
			Mobo_Core_Settings::delete('mobo_core_image_refresh_generation_stats');
			if ( class_exists( 'Mobo_Core_Image_Refresh_Queue' ) && Mobo_Core_Image_Refresh_Queue::table_exists() ) {
				$queue = new Mobo_Core_Image_Refresh_Queue();
				$queue->reset( false );
			}
			if ( class_exists( 'Mobo_Core_Image_Refresh_Service' ) ) {
				$service = new Mobo_Core_Image_Refresh_Service();
				$service->reset_workflow_state( false );
			}
		}

		update_option( 'mobo_core_1033449_full_source_image_refresh_at', time(), false );
	}

	/**
	 * Re-run legacy identity-map seeding through bounded maintenance.
	 *
	 * Versions before 10.33.28 could advance a legacy seed cursor after a failed
	 * mapping write and migration itself only ran one bounded batch. Reset only the
	 * cursors/completion markers; existing map rows remain intact and are idempotently
	 * re-verified by maintenance until the scan actually completes.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103328_legacy_map_reseed_safety( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.28', '>=' ) ) {
			return;
		}

		delete_option( 'mobo_core_product_map_product_cursor' );
		delete_option( 'mobo_core_product_map_variation_cursor' );
		delete_option( 'mobo_core_product_map_seed_completed_at' );
		delete_option( 'mobo_core_category_map_cursor' );
		delete_option( 'mobo_core_category_map_seed_completed_at' );
		delete_option( 'mobo_core_103328_legacy_map_reseed_completed_at' );
		update_option( 'mobo_core_103328_legacy_map_reseed_scheduled_at', time(), false );
		update_option( 'mobo_core_maintenance_next_due_at', time() + 60, false );
	}

	/**
	 * Seed product/variation map from old post meta without blocking upgrades.
	 *
	 * This is bounded and repeatable. If a large site is not fully seeded during
	 * upgrade, normal product sync lookup still falls back to legacy meta_query and
	 * repairs missing map rows lazily.
	 *
	 * @return void
	 */
	private static function seed_product_map_from_legacy_meta() {
		if ( ! class_exists( 'Mobo_Core_Product_Map' ) ) {
			return;
		}

		$map = new Mobo_Core_Product_Map();
		if ( absint( get_option( 'mobo_core_product_map_seed_completed_at', 0 ) ) > 0 ) {
			return;
		}

		/* Outside a budgeted slice (activation/WP-CLI): one small bounded batch. */
		if ( self::$pass_deadline <= 0 ) {
			$result = $map->seed_from_legacy_meta( 100 );
			update_option( 'mobo_core_product_map_last_seed_result', $result, false );
			update_option( 'mobo_core_product_map_last_seed_at', time(), false );
			return;
		}

		/* Budgeted slice: larger batches until the seed completes, stalls or the
		 * pass deadline arrives. Rows whose GUID is already mapped are skipped by one
		 * batched lookup (no per-row locks), so re-validating a complete map is fast. */
		$total  = array( 'products' => 0, 'variations' => 0, 'stalled' => false, 'batches' => 0 );
		$guard  = 0;
		while ( $guard++ < 400 && ! self::pass_deadline_reached() ) {
			$result = $map->seed_from_legacy_meta( self::SEED_DEADLINE_BATCH );
			$total['batches']++;
			$total['products']   += absint( $result['products'] ?? 0 );
			$total['variations'] += absint( $result['variations'] ?? 0 );
			$total['stalled']     = ! empty( $result['stalled'] );
			if ( ! empty( $result['stalled'] ) || absint( get_option( 'mobo_core_product_map_seed_completed_at', 0 ) ) > 0 ) {
				break;
			}
			if ( 0 === absint( $result['products'] ?? 0 ) && 0 === absint( $result['variations'] ?? 0 ) ) {
				break;
			}
		}

		update_option( 'mobo_core_product_map_last_seed_result', $total, false );
		update_option( 'mobo_core_product_map_last_seed_at', time(), false );
	}


	/**
	 * Seed category map from legacy product_cat term meta without blocking upgrades.
	 *
	 * @return void
	 */
	private static function seed_category_map_from_legacy_meta() {
		if ( ! class_exists( 'Mobo_Core_Category_Map' ) ) {
			return;
		}

		$map    = new Mobo_Core_Category_Map();
		$result = $map->seed_from_legacy_term_meta( 100 );

		update_option( 'mobo_core_category_map_last_seed_result', $result, false );
		update_option( 'mobo_core_category_map_last_seed_at', time(), false );
	}

	/**
	 * Discard old file-based webhook queue from the plugin directory.
	 *
	 * Previous versions used:
	 * wp-content/plugins/mobo-core/webhook-files/
	 *
	 * Current versions use uploads for the active queue. The legacy queued JSON
	 * files are intentionally not migrated because stale webhook payloads from
	 * 7.4 are not required and can replay outdated product/category changes.
	 *
	 * Only JSON payload files inside the legacy plugin-directory queue are removed.
	 * Protection files such as index.php, .htaccess and .gitignore are left intact.
	 * The current uploads-based queue is never touched here.
	 *
	 * @return void
	 */
	private static function discard_legacy_webhook_queue() {
		if ( ! defined( 'MOBO_CORE_LEGACY_WEBHOOK_FILE_DIR' ) ) {
			return;
		}

		$legacy_dir = trailingslashit( MOBO_CORE_LEGACY_WEBHOOK_FILE_DIR );
		$new_dir    = trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR );

		if ( $legacy_dir === $new_dir || ! is_dir( $legacy_dir ) ) {
			return;
		}

		$deleted = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$legacy_dir,
				RecursiveDirectoryIterator::SKIP_DOTS
			),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );

			if ( 'json' !== $extension ) {
				continue;
			}

			$file_path = $file->getPathname();
			wp_delete_file( $file_path );

			if ( ! file_exists( $file_path ) ) {
				$deleted++;
			}
		}

		update_option( 'mobo_core_legacy_webhook_queue_discarded_at', time(), false );
		update_option( 'mobo_core_legacy_webhook_queue_discarded_count', $deleted, false );
	}

	/**
	 * Protect directory with index.php and .htaccess.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function protect_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n" );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}
	}

	/**
	 * Clear old WP-Cron hooks from previous plugin versions.
	 *
	 * Final architecture does not rely on WP-Cron.
	 *
	 * @return void
	 */
	private static function clear_legacy_cron_hooks() {
		if ( class_exists( 'Mobo_Core_Maintenance' ) ) {
			$hooks = Mobo_Core_Maintenance::mobo_cron_hooks();
		} else {
			$hooks = array(
				'mobo_core_cron',
				'mobo_core_sync_cron',
				'mobo_core_product_sync_cron',
				'mobo_core_products_sync_cron',
				'mobo_core_webhook_cron',
				'mobo_core_webhook_queue_cron',
				'mobo_core_process_webhook_queue',
				'mobo_core_run_webhooks',
				'mobo_core_update_products',
				'mobo_core_update_variants',
				'mobo_core_process_queued_mobo_orders',
				'mobo_core_queue_mobo_order_submission',
				'mobo_core_read_webhook_interval',
				'mobo_core_sync_products_24_event',
				'mobo_core_sync_products_event',
				'mobo_core_sync_categories_event',
				'mobo_cron_hook',
				'mobo_sync_cron_hook',
				'mobo_webhook_cron_hook',
			);
		}

		/**
		 * Allow old/custom installs to register extra legacy cron hooks for cleanup.
		 *
		 * @param array $hooks Cron hook names.
		 */
		$hooks = apply_filters( 'mobo_core_legacy_cron_hooks', $hooks );

		foreach ( $hooks as $hook ) {
			$hook = sanitize_key( (string) $hook );

			if ( '' === $hook ) {
				continue;
			}

			wp_clear_scheduled_hook( $hook );
		}
	}


	/**
	 * Recover a legacy Repair checkpoint that failed after fetching a variant
	 * page but before that page was durably applied.
	 *
	 * Older builds advanced currentVariantCursor before the page commit. If the
	 * page then failed during Simple -> Variable conversion, resuming that state
	 * could skip the failed page. Preserve the Repair generation and its current
	 * product, but rewind only that product's authoritative variant pass to page
	 * one/cursor zero.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103357_failed_variant_checkpoint_recovery( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.57', '>=' ) ) {
			return;
		}

		$state = ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() )
			? Mobo_Core_State_Store::get( 'sync_state', array() )
			: get_option( 'mobo_core_sync_state', array() );
		if ( ! is_array( $state ) || empty( $state ) ) {
			return;
		}

		if ( 'running' !== sanitize_key( (string) ( $state['status'] ?? '' ) ) || empty( $state['repairMode'] ) ) {
			return;
		}

		$legacy_error = trim( (string) ( $state['lastError'] ?? '' ) );
		if ( 'WooCommerce variable product could not be durably saved after type conversion.' !== $legacy_error ) {
			return;
		}

		if ( class_exists( 'Mobo_Core_Lock' ) && Mobo_Core_Lock::is_locked( 'manual_sync' ) ) {
			return;
		}

		$now = time();
		$state['legacyFailedVariantCheckpoint'] = array(
			'error'                        => $legacy_error,
			'variantPage'                  => absint( $state['variantPage'] ?? 1 ),
			'currentVariantCursor'         => absint( $state['currentVariantCursor'] ?? 0 ),
			'currentVariantProcessedPages' => absint( $state['currentVariantProcessedPages'] ?? 0 ),
			'recoveredAt'                  => $now,
			'recoveredByVersion'           => '10.33.57',
		);

		$state['variantPage']                  = 1;
		$state['currentVariantCursor']         = 0;
		$state['currentVariantTotalCount']     = 0;
		$state['currentVariantTotalPages']     = 0;
		$state['currentVariantProcessedPages'] = 0;
		$state['lastError']                    = '';
		$state['transientRetryCount']          = 0;
		$state['lastTransientError']           = '';
		$state['waitingForPortalSince']        = 0;
		$state['nextRetryAt']                  = 0;
		$state['staleRecoveryCount']           = absint( $state['staleRecoveryCount'] ?? 0 ) + 1;
		$state['lastStaleRecoveryAt']          = $now;
		$state['lastStaleRecoveryReason']      = 'upgrade-rewind-failed-variant-page';
		$state['lastMessage']                  = 'Repair قدیمی پس از ارتقای افزونه از ابتدای تنوع‌های محصول جاری به‌صورت امن ادامه داده می‌شود.';
		$state['updatedAt']                    = $now;

		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() ) {
			Mobo_Core_State_Store::set( 'sync_state', $state );
		} else {
			Mobo_Core_Durable_State_Policy::update_option_verified( 'mobo_core_sync_state', $state, false );
		}
		
	}


	/**
	 * Schedule bounded migration of legacy image-prune debt.
	 *
	 * Do not scan wp_postmeta during plugin activation/upgrade. The new background
	 * prune queue reads the two exact legacy meta keys with primary-key cursors on
	 * later image-worker slices. Fresh installs have no legacy debt.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103358_image_prune_queue_schedule( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.58', '>=' ) ) {
			return;
		}

		update_option(
			Mobo_Core_Image_Prune_Queue::LEGACY_SEED_STATE_OPTION,
			array(
				'phase'      => 'product',
				'cursor'     => 0,
				'complete'   => false,
				'scheduledAt'=> time(),
			),
			false
		);
	}


	/**
	 * Move Health / Sync Health snapshot refresh ownership from WP-Cron to the
	 * deterministic real-cron runner.
	 *
	 * 10.33.51 could keep several overdue health hooks forever on installations
	 * with DISABLE_WP_CRON=true even though the main Mobo real cron was healthy.
	 * Queue one durable refresh request and remove every v1/v2 health WP-Cron row.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103353_health_snapshot_real_cron_ownership( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.53', '>=' ) ) {
			return;
		}

		foreach ( array(
			'mobo_core_health_snapshot_refresh_v1',
			'mobo_core_health_snapshot_refresh_v2',
			'mobo_core_sync_health_snapshot_refresh_v1',
			'mobo_core_sync_health_snapshot_refresh_v2',
		) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		$now = time();
		Mobo_Core_Settings::update( 'mobo_core_health_snapshot_refresh_requested_at', $now, false );
		Mobo_Core_Settings::update( 'mobo_core_sync_health_snapshot_refresh_requested_at', $now, false );
		Mobo_Core_Settings::update( 'mobo_core_sync_health_snapshot_refresh_requested_limit', 20, false );
		update_option( 'mobo_core_103353_health_real_cron_migrated_at', $now, false );
	}


	/**
	 * Remove every legacy Health / Sync Health WP-Cron event regardless of args.
	 *
	 * wp_clear_scheduled_hook( $hook ) only clears events whose args match the
	 * supplied args (empty by default). Older Sync Health schedules used non-empty
	 * args, so 10.33.53 could leave those rows behind on DISABLE_WP_CRON installs.
	 * This migration mutates only the four retired Mobo Health hook buckets and
	 * preserves every unrelated cron event.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103354_health_legacy_cron_cleanup( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.54', '>=' ) ) {
			return;
		}

		$hooks = array(
			'mobo_core_health_snapshot_refresh_v1',
			'mobo_core_health_snapshot_refresh_v2',
			'mobo_core_sync_health_snapshot_refresh_v1',
			'mobo_core_sync_health_snapshot_refresh_v2',
		);

		$cron = get_option( 'cron', array() );
		if ( ! is_array( $cron ) ) {
			update_option( 'mobo_core_103354_health_legacy_cron_cleanup_at', time(), false );
			update_option( 'mobo_core_103354_health_legacy_cron_cleanup_removed', -1, false );
			return;
		}

		$removed = 0;
		foreach ( $cron as $timestamp => $events ) {
			if ( 'version' === (string) $timestamp || ! is_array( $events ) ) {
				continue;
			}

			foreach ( $hooks as $hook ) {
				if ( ! isset( $cron[ $timestamp ][ $hook ] ) ) {
					continue;
				}

				$removed += is_array( $cron[ $timestamp ][ $hook ] )
					? count( $cron[ $timestamp ][ $hook ] )
					: 1;
				unset( $cron[ $timestamp ][ $hook ] );
			}

			if ( isset( $cron[ $timestamp ] ) && is_array( $cron[ $timestamp ] ) && empty( $cron[ $timestamp ] ) ) {
				unset( $cron[ $timestamp ] );
			}
		}

		if ( $removed > 0 ) {
			update_option( 'cron', $cron );
		}

		update_option( 'mobo_core_103354_health_legacy_cron_cleanup_at', time(), false );
		update_option( 'mobo_core_103354_health_legacy_cron_cleanup_removed', absint( $removed ), false );
	}


	/**
	 * Queue a one-time post-WooCommerce repair for the exact pre-10.33.51
	 * variable-product completion race signature.
	 *
	 * Migration itself runs on plugins_loaded, where wc_get_product() is not safe.
	 * Only persist intent here; run_deferred_repairs() executes the bounded proof
	 * after WooCommerce has registered product types and fired woocommerce_init.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103353_convergence_residue_selfheal_schedule( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.53', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_103353_convergence_residue_selfheal_pending', '1', false );
		update_option( 'mobo_core_103353_convergence_residue_selfheal_scheduled_at', time(), false );
	}


	/**
	 * Arm one final 10.34.77 release-hygiene pass even on same-version candidate
	 * reinstalls. This repairs state discovered by the full pre-release Plugin Gate
	 * without relying on WP-CLI or on a DB-version transition.
	 *
	 * @return void
	 */
	private static function ensure_103477_release_hygiene_intents() {
		$revision = (string) get_option( 'mobo_core_103477_release_hygiene_revision', '' );
		$scheduled = absint( get_option( 'mobo_core_103477_release_hygiene_scheduled_at', 0 ) );
		if ( '4' === $revision && $scheduled > 0 ) {
			return;
		}

		/* Revision 4 completes singleton media discovery across product and variation Custom Storage and
		 * postmeta-only pre-HPOS Revenue Ledger residue. Re-arm both bounded
		 * repairs on same-version candidate replacement so a false completion from
		 * the narrower REL28/REL29 discovery cannot suppress the corrected pass. */
		update_option( 'mobo_core_103454_singleton_meta_dedupe_pending', '1', false );
		update_option( 'mobo_core_103477_revenue_ledger_backfill_pending', '1', false );
		delete_option( 'mobo_core_103477_revenue_ledger_backfill_cursor' );
		delete_option( 'mobo_core_103477_revenue_ledger_backfill_blocked_ids' );
		update_option( 'mobo_core_103477_release_hygiene_revision', '4', false );
		update_option( 'mobo_core_103477_release_hygiene_scheduled_at', time(), false );
		delete_option( 'mobo_core_103477_release_hygiene_completed_at' );
	}

	/**
	 * Mark the one-time hygiene pass complete only after both durable intents converge.
	 *
	 * @return void
	 */
	private static function maybe_finalize_103477_release_hygiene() {
		if ( absint( get_option( 'mobo_core_103477_release_hygiene_scheduled_at', 0 ) ) <= 0 ) {
			return;
		}
		if ( '1' === (string) get_option( 'mobo_core_103454_singleton_meta_dedupe_pending', '0' )
			|| '1' === (string) get_option( 'mobo_core_103477_revenue_ledger_backfill_pending', '0' ) ) {
			return;
		}
		if ( absint( get_option( 'mobo_core_103477_release_hygiene_completed_at', 0 ) ) <= 0 ) {
			update_option( 'mobo_core_103477_release_hygiene_completed_at', time(), false );
		}
	}


	/**
	 * Re-run the historical convergence-residue proof on 10.34.54 upgrades.
	 *
	 * The original 10.33.53 pass was intentionally bounded to 200 `behind` rows
	 * and cleared its pending marker after that page. Large catalogs could therefore
	 * retain the same proven-complete variable-product residue on later pages, and
	 * rows whose observational Health status had already become `synced` were never
	 * candidates. 10.34.54 keeps the same strict proof boundary but makes the scan
	 * resumable across map-id pages and includes every incomplete mapped product.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103454_convergence_residue_selfheal_schedule( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.34.54', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_103353_convergence_residue_selfheal_pending', '1', false );
		update_option( 'mobo_core_103353_convergence_residue_selfheal_cursor', 0, false );
		update_option( 'mobo_core_103454_convergence_residue_selfheal_scheduled_at', time(), false );
	}

	/**
	 * Queue bounded normalization for semantically singleton WooCommerce metadata.
	 *
	 * `_thumbnail_id` is a WordPress/WooCommerce singleton. Historical duplicate
	 * physical rows do not change the effective first value but make release/audit
	 * invariants ambiguous. The repair preserves the oldest/effective row and only
	 * deletes later duplicates for current Mobo-managed products.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103454_singleton_meta_dedupe_schedule( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.34.54', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_103454_singleton_meta_dedupe_pending', '1', false );
		update_option( 'mobo_core_103454_singleton_meta_dedupe_scheduled_at', time(), false );
	}


	/**
	 * Re-arm release-residue repairs for the 10.34.55 durable-proof finalizer.
	 *
	 * 10.34.54 intentionally kept the original historical event-history proof.
	 * Real pre-release databases showed that retained current product/map state can
	 * outlive the done-event rows needed by that proof. 10.34.55 therefore re-runs
	 * the same bounded scan with a current durable-state proof and re-runs singleton
	 * thumbnail canonicalization. Cursor reset is intentional so a completed 10.34.54
	 * scan is not mistaken for completion of the stronger current-state finalizer.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103455_release_residue_finalization_schedule( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.34.55', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_103353_convergence_residue_selfheal_pending', '1', false );
		update_option( 'mobo_core_103353_convergence_residue_selfheal_cursor', 0, false );
		update_option( 'mobo_core_103454_singleton_meta_dedupe_pending', '1', false );
		update_option( 'mobo_core_103455_release_residue_finalization_scheduled_at', time(), false );
	}


	/**
	 * Re-arm the release-residue finalizer for 10.34.62 using canonical Custom
	 * Storage state instead of observational Sync Health as the candidate boundary.
	 *
	 * 10.34.55 removed the dependency on retained done-event history, but its
	 * candidate SELECT still INNER JOINed mobo_sync_health and treated the Health
	 * revision/hash as authoritative proof inputs. Health is observational and may
	 * legitimately lag durable Product/Variant state, which allowed a completed scan
	 * to leave old mobo_sync_incomplete markers behind. 10.34.62 resets the cursor
	 * and re-runs the bounded proof against Product Map + Custom Storage state.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103462_custom_state_residue_finalization_schedule( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.34.62', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_103353_convergence_residue_selfheal_pending', '1', false );
		update_option( 'mobo_core_103353_convergence_residue_selfheal_cursor', 0, false );
		update_option( 'mobo_core_103462_custom_state_residue_finalization_scheduled_at', time(), false );
	}


	/**
	 * Re-arm the convergence-residue finalizer after the terminal Custom Storage
	 * retirement ordering bug fixed in 10.34.63.
	 *
	 * 10.34.62 could invoke the deferred worker from wp_loaded before Entity/Custom
	 * Storage retirement had reached its physical terminal state. An empty early scan
	 * then cleared the pending flag; later Entity migration could faithfully import
	 * historical incomplete markers with no finalizer left to revisit them. 10.34.63
	 * re-arms the same proof worker and the worker itself refuses to scan until all
	 * terminal retirements are physically complete.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103463_post_retirement_residue_finalization_schedule( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.34.63', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_103353_convergence_residue_selfheal_pending', '1', false );
		update_option( 'mobo_core_103353_convergence_residue_selfheal_cursor', 0, false );
		update_option( 'mobo_core_103463_post_retirement_residue_finalization_scheduled_at', time(), false );
	}


	/**
	 * Re-arm the residue finalizer for the 10.34.64 historical-state proof.
	 *
	 * 10.34.63 proved that the remaining release cohort is not an early-run race:
	 * canonical Product state is current for the historical-complete rows, while
	 * Variant Custom Storage may be intentionally absent for pre-retirement history.
	 * Re-run from map id zero so the proof can distinguish safely-complete historical
	 * rows from true desired-state gaps and terminal upstream-invalid products.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103464_historical_residue_finalization_schedule( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.34.64', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_103353_convergence_residue_selfheal_pending', '1', false );
		update_option( 'mobo_core_103353_convergence_residue_selfheal_cursor', 0, false );
		delete_option( 'mobo_core_103353_convergence_residue_selfheal' );
		update_option( 'mobo_core_103464_historical_residue_finalization_scheduled_at', time(), false );
	}


	/**
	 * Re-arm the residue finalizer for the 10.34.65 Variant applied-state proof.
	 *
	 * 10.34.64 incorrectly required legacy Variant seen/applied source watermarks to
	 * be numerically equal. The Variant State repository does not define equality as
	 * the applied-state invariant: a migrated row may legitimately have applied > seen
	 * while desired=max(seen,applied), sync_status=applied, dirty_flags=0, and no claim.
	 * Re-run from map id zero so exact complete child Custom Storage can be proven from
	 * the repository's canonical applied-state contract rather than the stricter legacy
	 * watermark equality assumption.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103465_variant_applied_state_residue_finalization_schedule( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.34.65', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_103353_convergence_residue_selfheal_pending', '1', false );
		update_option( 'mobo_core_103353_convergence_residue_selfheal_cursor', 0, false );
		delete_option( 'mobo_core_103353_convergence_residue_selfheal' );
		update_option( 'mobo_core_103465_variant_applied_state_residue_finalization_scheduled_at', time(), false );
	}


	/**
	 * Re-arm the residue finalizer for the 10.34.66 Custom Variant authority proof.
	 *
	 * In Custom-Only mode Product Map writes are intentionally suppressible once
	 * Custom Storage retirement is proven. 10.34.65 still required complete child
	 * Variation Map coverage even when exact canonical Variant State already covered
	 * every live child, so valid post-retirement history could never pass the proof.
	 * Re-run from map id zero and use Custom Variant State as the authoritative child
	 * boundary when it exists; the legacy child Map remains a fail-closed fallback only
	 * when no Custom Variant State exists for the parent.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103466_custom_variant_authority_residue_finalization_schedule( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.34.66', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_103353_convergence_residue_selfheal_pending', '1', false );
		update_option( 'mobo_core_103353_convergence_residue_selfheal_cursor', 0, false );
		delete_option( 'mobo_core_103353_convergence_residue_selfheal' );
		update_option( 'mobo_core_103466_custom_variant_authority_residue_finalization_scheduled_at', time(), false );
	}


	/**
	 * Re-arm the residue finalizer for the 10.34.67 canonical Variant convergence proof.
	 *
	 * 10.34.66 still treated optional migrated Variant source-watermark presence as a
	 * convergence requirement. Historical Custom Variant rows may legitimately lack those
	 * legacy-presence bits while their canonical desired/applied state is fully applied.
	 * The safe post-retirement proof therefore uses exact child coverage plus canonical
	 * desired/applied, status, dirty, claim and hash invariants. Optional parent source
	 * checkpoints, when present, must still preserve monotonic seen<=applied ordering.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103467_variant_checkpoint_order_residue_finalization_schedule( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.34.67', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_103353_convergence_residue_selfheal_pending', '1', false );
		update_option( 'mobo_core_103353_convergence_residue_selfheal_cursor', 0, false );
		delete_option( 'mobo_core_103353_convergence_residue_selfheal' );
		update_option( 'mobo_core_103467_canonical_variant_convergence_residue_finalization_scheduled_at', time(), false );
	}


	/**
	 * Heal historical incomplete-marker residue using current durable convergence proof.
	 *
	 * Safety boundary: this never guesses from a lone Health row or local shape alone.
	 * A candidate must have a clean local variable topology, authoritative Product
	 * convergence evidence, matching current source hashes, a completed canonical
	 * desired-state cycle, exact applied child authority (or the legacy Map fallback), and
	 * no active queue owner. Historical done-event rows are intentionally not required:
	 * retention may delete them after the same durable state has already converged.
	 *
	 * @return bool True only when the bounded scan executed to completion.
	 */

	/**
	 * Arm a targeted, network-resumable convergence owner for upgrades that cross
	 * 10.34.77. The migration itself remains local/offline-safe; exact Backend reads
	 * are deferred to the ordinary Product Sync cron lane after terminal retirement
	 * and after the Default Connection reaches its signed provisioning boundary.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103473_post_upgrade_convergence_recovery_schedule( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.34.77', '>=' ) ) {
			return;
		}
		update_option( 'mobo_core_103473_post_upgrade_convergence_pending', '1', false );
		update_option( 'mobo_core_103473_post_upgrade_convergence_scheduled_at', time(), false );
	}

	private static function run_103353_convergence_residue_selfheal() {
		global $wpdb;

		/* Only the real runner's locked budgeted slice runs this repair. */
		if ( '' === self::$budgeted_slice_lock_token ) {
			return false;
		}

		if ( ! did_action( 'woocommerce_init' ) || ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Variable' ) ) {
			return false;
		}
		if ( ! class_exists( 'Mobo_Core_Sync_Health' ) || ! class_exists( 'Mobo_Core_Product_Map' ) || ! class_exists( 'Mobo_Core_Sync_Event_Store' )
			|| ! class_exists( 'Mobo_Core_Product_State_Repository' ) || ! class_exists( 'Mobo_Core_Variant_State_Repository' ) ) {
			return false;
		}
		if ( ! Mobo_Core_Product_Map::table_exists() || ! Mobo_Core_Sync_Event_Store::table_exists()
			|| ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}
		/* Never let an early wp_loaded/deferred-repair pass consume the worker before
		 * Entity/Custom Storage retirement has imported the final authoritative state.
		 * Returning false preserves the pending flag and cursor for the post-retirement
		 * budgeted slice. */
		if ( ! self::terminal_retirements_physically_complete() ) {
			update_option( 'mobo_core_103463_residue_finalizer_deferred_at', time(), false );
			return false;
		}
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! class_exists( 'Mobo_Core_Connection_Context' ) ) { return false; }
		$default = Mobo_Core_Connection_Repository::get_default();
		$default_id = is_array( $default ) ? absint( $default['id'] ?? 0 ) : 0;
		if ( $default_id <= 0 ) { return false; }
		$current_id = absint( Mobo_Core_Connection_Context::get_current_connection_id() );
		if ( $current_id > 0 && $current_id !== $default_id ) { return false; }
		if ( $current_id !== $default_id ) {
			return (bool) Mobo_Core_Connection_Context::run( $default_id, static function () {
				return self::run_103353_convergence_residue_selfheal();
			} );
		}

		$map_table     = Mobo_Core_Product_Map::table_name();
		$event_table   = Mobo_Core_Sync_Event_Store::table_name();
		$product_table = Mobo_Core_Product_State_Repository::table_name();
		$variant_table = Mobo_Core_Variant_State_Repository::table_name();

		$cursor = absint( get_option( 'mobo_core_103353_convergence_residue_selfheal_cursor', 0 ) );
		$wpdb->last_error = '';
		/* Sync Health is deliberately absent from the candidate boundary. It is an
		 * observational projection and cannot be the authority used to clear durable
		 * incomplete markers. Candidate identity/revision/hash comes from Product Map
		 * plus canonical Custom Storage state. */
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id AS map_id,m.remote_guid AS product_guid,m.wp_post_id AS wp_product_id,m.last_hash AS map_last_hash,
				        p.portal_product_id,p.product_seen_revision,p.product_seen_revision_present,
				        p.product_applied_revision,p.product_applied_revision_present,
				        p.variant_seen_revision,p.variant_seen_revision_present,
				        p.variant_applied_revision,p.variant_applied_revision_present,
				        p.product_source_hash,p.product_source_hash_present,
				        p.sync_incomplete_meta,p.sync_incomplete_meta_present,p.sync_incomplete,
				        p.desired_revision,p.applied_revision,p.desired_hash,p.applied_hash,p.sync_status,p.dirty_flags,p.claim_token
				 FROM {$map_table} m
				 INNER JOIN {$product_table} p
				   ON p.connection_id=m.connection_id
				  AND BINARY p.product_guid=BINARY m.remote_guid
				  AND p.woo_product_id=m.wp_post_id
				 WHERE m.connection_id=%d AND m.object_type='product' AND m.id>%d
				   AND m.sync_incomplete=1
				 ORDER BY m.id ASC
				 LIMIT 50",
				$default_id,
				$cursor
			),
			ARRAY_A
		);
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $candidates ) ) {
			return false;
		}

		/* Fail closed if any active product mutation cannot be attributed to its
		 * canonical product. Variant events often use the variant GUID as entity_guid,
		 * so ownership is resolved through Custom Variant State and, only for events
		 * whose entity is unknown there, from that single event's payload.
		 *
		 * 10.34.77: this used to SELECT every pending event including payload_json
		 * with no LIMIT (memory fatals on ordinary page views). It is now a keyset
		 * scan of GUID columns only with a hard cap; a backlog above the cap fails
		 * closed exactly like an unattributable event and is retried later. */
		$busy = self::residue_selfheal_busy_guids( $default_id, wp_list_pluck( $candidates, 'product_guid' ), $event_table, $product_table, $variant_table );
		if ( null === $busy ) {
			update_option( 'mobo_core_103353_convergence_residue_selfheal_deferred_reason', 'event-ownership-unresolved-or-backlog', false );
			return false;
		}
		delete_option( 'mobo_core_103353_convergence_residue_selfheal_deferred_reason' );
		$active_product_guids   = $busy['active'];
		$terminal_product_guids = $busy['terminal'];

		$product_map = new Mobo_Core_Product_Map();
		$healed      = array();
		$examined    = 0;
		$proof_rejected = 0;
		$health_refresh_failed = 0;
		$health_refresh_failed_guids = array();
		$last_map_id = $cursor;

		foreach ( $candidates as $row ) {
			$examined++;
			$last_map_id = max( $last_map_id, absint( $row['map_id'] ?? 0 ) );
			$guid       = sanitize_text_field( (string) ( $row['product_guid'] ?? '' ) );
			$product_id = absint( $row['wp_product_id'] ?? 0 );
			$portal_id  = absint( $row['portal_product_id'] ?? 0 );
			$map_hash   = sanitize_text_field( (string) ( $row['map_last_hash'] ?? '' ) );
			$source_hash = sanitize_text_field( (string) ( $row['product_source_hash'] ?? '' ) );
			$product_seen    = absint( $row['product_seen_revision'] ?? 0 );
			$product_applied = absint( $row['product_applied_revision'] ?? 0 );
			$variant_seen    = absint( $row['variant_seen_revision'] ?? 0 );
			$variant_applied = absint( $row['variant_applied_revision'] ?? 0 );
			$desired_revision = absint( $row['desired_revision'] ?? 0 );
			$applied_revision = absint( $row['applied_revision'] ?? 0 );
			$claim_token = trim( (string) ( $row['claim_token'] ?? '' ) );

			$custom_incomplete = absint( $row['sync_incomplete'] ?? 0 );
			$meta_incomplete   = absint( $row['sync_incomplete_meta'] ?? 0 );
			$sync_status       = sanitize_key( (string) ( $row['sync_status'] ?? '' ) );
			$dirty_flags       = absint( $row['dirty_flags'] ?? 0 );
			/* Product and Variant revisions are independent source streams. Historical
			 * residue must never require a Variant revision to equal the Product revision.
			 * Product Custom Storage is the authoritative parent boundary. When the optional
			 * parent Variant checkpoint pair exists, both values must exist and preserve
			 * monotonic ordering (seen<=applied); canonical migrated state may legitimately
			 * have applied ahead of seen. Child authority is proven separately below. */
			$product_presence = 1 === absint( $row['product_seen_revision_present'] ?? 0 )
				&& 1 === absint( $row['product_applied_revision_present'] ?? 0 );
			$variant_seen_present    = 1 === absint( $row['variant_seen_revision_present'] ?? 0 );
			$variant_applied_present = 1 === absint( $row['variant_applied_revision_present'] ?? 0 );
			$variant_checkpoint_present = $variant_seen_present || $variant_applied_present;
			$variant_checkpoint_valid = ! $variant_checkpoint_present
				|| ( $variant_seen_present && $variant_applied_present && $variant_seen <= $variant_applied );
			$current_state_proven = '' !== $guid && $product_id > 0 && '' !== $map_hash && '' !== $source_hash
				&& $product_presence
				&& $variant_checkpoint_valid
				&& 1 === absint( $row['product_source_hash_present'] ?? 0 )
				&& 1 === absint( $row['sync_incomplete_meta_present'] ?? 0 )
				&& in_array( $custom_incomplete, array( 0, 1 ), true )
				&& $custom_incomplete === $meta_incomplete
				&& $product_seen > 0
				&& $product_seen === $product_applied
				&& $desired_revision <= $applied_revision
				&& '' === $claim_token
				&& hash_equals( $map_hash, $source_hash );
			$desired_hash = sanitize_text_field( (string) ( $row['desired_hash'] ?? '' ) );
			$applied_hash = sanitize_text_field( (string) ( $row['applied_hash'] ?? '' ) );
			if ( $current_state_proven && '' !== $desired_hash && ! hash_equals( $source_hash, $desired_hash ) ) {
				$current_state_proven = false;
			}
			if ( $current_state_proven && '' !== $applied_hash && ! hash_equals( $source_hash, $applied_hash ) ) {
				$current_state_proven = false;
			}
			/* A canonical complete Product row must itself be terminal-applied. An
			 * incomplete row may legitimately still say pending solely because this stale
			 * crash marker has not yet been cleared; desired<=applied proves no work is due. */
			if ( $current_state_proven && 0 === $custom_incomplete ) {
				$current_state_proven = 'applied' === $sync_status
					&& 0 === ( $dirty_flags & 1 )
					&& '' !== $applied_hash
					&& hash_equals( $source_hash, $applied_hash );
			}
			if ( ! $current_state_proven ) {
				$proof_rejected++;
				continue;
			}
			$portal_revision = $product_applied;

			if ( 'product' !== get_post_type( $product_id ) || 'trash' === get_post_status( $product_id ) ) {
				$proof_rejected++;
				continue;
			}
			$post_incomplete_value = (string) get_post_meta( $product_id, 'mobo_sync_incomplete', true );
			if ( ( 1 === $custom_incomplete && '1' !== $post_incomplete_value )
				|| ( 0 === $custom_incomplete && '1' === $post_incomplete_value )
				|| '1' === (string) get_post_meta( $product_id, '_mobo_desired_state_rebuild_pending', true ) ) {
				$proof_rejected++;
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product || ! Mobo_Core_Sync_Health::local_product_structure_is_sane( $product_id ) ) {
				$proof_rejected++;
				continue;
			}
			if ( isset( $active_product_guids[ $guid ] ) || isset( $terminal_product_guids[ $guid ] ) ) {
				$proof_rejected++;
				continue;
			}

			$missing_expected = absint( get_post_meta( $product_id, '_mobo_missing_variants_expected_count', true ) );
			$missing_reason   = sanitize_key( (string) get_post_meta( $product_id, '_mobo_missing_variants_finalize_reason', true ) );
			if ( $missing_expected > 0 || 'authoritative_snapshot_incomplete' === $missing_reason ) {
				$proof_rejected++;
				continue;
			}

			if ( $product instanceof WC_Product_Variable ) {
				$children = array_values( array_filter( array_map( 'absint', (array) $product->get_children() ) ) );
				if ( empty( $children ) ) {
					$proof_rejected++;
					continue;
				}

				/* Post-retirement child authority is Custom Variant State when exact canonical
				 * rows exist for this parent. Product Map variation writes are intentionally
				 * suppressible in Custom-Only mode, so absent/stale Legacy child Map rows cannot
				 * veto an otherwise exact applied Custom child set. If no Custom Variant rows
				 * exist at all, retain the older hashed-Map fallback for pre-Custom history. */
				$wpdb->last_error = '';
				$custom_parent_rows = absint( $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$variant_table} v
					 WHERE v.connection_id=%d AND BINARY v.product_guid=BINARY %s AND v.woo_product_id=%d",
					$default_id,
					$guid,
					$product_id
				) ) );
				$custom_child_rows = absint( $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(DISTINCT v.woo_variation_id)
					 FROM {$variant_table} v
					 INNER JOIN {$wpdb->posts} wpv
					   ON wpv.ID=v.woo_variation_id AND wpv.post_parent=%d AND wpv.post_type='product_variation'
					  AND wpv.post_status NOT IN ('trash','auto-draft')
					 WHERE v.connection_id=%d AND BINARY v.product_guid=BINARY %s AND v.woo_product_id=%d",
					$product_id,
					$default_id,
					$guid,
					$product_id
				) ) );
				$proven_children = absint( $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(DISTINCT v.woo_variation_id)
					 FROM {$variant_table} v
					 INNER JOIN {$wpdb->posts} wpv
					   ON wpv.ID=v.woo_variation_id AND wpv.post_parent=%d AND wpv.post_type='product_variation'
					  AND wpv.post_status NOT IN ('trash','auto-draft')
					 WHERE v.connection_id=%d AND BINARY v.product_guid=BINARY %s AND v.woo_product_id=%d
					   AND v.variant_guid<>'' AND v.sync_incomplete=0
					   /* Canonical post-retirement convergence is carried by desired/applied state.
					    * Historical migrated rows may lack optional legacy source-watermark presence
					    * bits even when the current child is fully applied and clean. */
					   AND v.desired_revision<=v.applied_revision
					   AND v.sync_status='applied' AND (v.dirty_flags & 1)=0
					   AND (v.claim_token IS NULL OR v.claim_token='')
					   AND (v.applied_hash='' OR v.variant_source_hash='' OR BINARY v.applied_hash=BINARY v.variant_source_hash)",
					$product_id,
					$default_id,
					$guid,
					$product_id
				) ) );
				if ( '' !== trim( (string) $wpdb->last_error ) ) {
					$proof_rejected++;
					continue;
				}

				if ( $custom_parent_rows > 0 ) {
					/* Exact Custom authority: no missing live child and no stale extra row for
					 * this parent may remain. Unique repository keys make each Woo child/GUID
					 * installation-owned, while the predicates above prove applied state. */
					if ( $custom_parent_rows !== count( $children ) || $custom_child_rows !== count( $children ) || $proven_children !== count( $children ) ) {
						$proof_rejected++;
						continue;
					}
				} else {
					$wpdb->last_error = '';
					$mapped_children = absint( $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(DISTINCT m.wp_post_id)
						 FROM {$map_table} m
						 INNER JOIN {$wpdb->posts} wpv
						   ON wpv.ID=m.wp_post_id AND wpv.post_parent=%d AND wpv.post_type='product_variation'
						  AND wpv.post_status NOT IN ('trash','auto-draft')
						 WHERE m.connection_id=%d AND m.object_type='variation'
						   AND BINARY m.parent_remote_guid=BINARY %s AND m.sync_incomplete=0 AND m.last_hash<>''",
						$product_id,
						$default_id,
						$guid
					) ) );
					$incomplete_child_maps = absint( $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$map_table}
						 WHERE connection_id=%d AND object_type='variation'
						   AND BINARY parent_remote_guid=BINARY %s AND sync_incomplete=1",
						$default_id,
						$guid
					) ) );
					if ( '' !== trim( (string) $wpdb->last_error ) || $mapped_children !== count( $children ) || $incomplete_child_maps > 0 ) {
						$proof_rejected++;
						continue;
					}
				}
			}

			/* Map first, product marker second, Health last. Health is refreshed only
			 * after durable convergence has already been proven; it never participates
			 * in deciding whether the durable marker may be cleared. */
			if ( ! $product_map->upsert_product( $guid, $product_id, $source_hash, false ) ) {
				$proof_rejected++;
				continue;
			}
			if ( 1 === $custom_incomplete ) {
				update_post_meta( $product_id, 'mobo_sync_incomplete', '0' );
				if ( '0' !== (string) get_post_meta( $product_id, 'mobo_sync_incomplete', true ) ) {
					$product_map->upsert_product( $guid, $product_id, $source_hash, true );
					$proof_rejected++;
					continue;
				}
			}

			$product_version = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_product_applied_event_version', true ) );
			$variant_version = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_variant_applied_event_version', true ) );
			$portal_version = ( '' !== $product_version && hash_equals( $product_version, $variant_version ) ) ? $product_version : '';
			/* Sync Health is an observational projection. Once the durable proof above has
			 * succeeded and the canonical incomplete markers have been cleared, a Health
			 * write failure must not make Health authoritative by rolling durable state
			 * backwards. Record the refresh failure for diagnostics and allow the normal
			 * Health/dashboard residue classifier to treat any stale row separately. */
			if ( ! Mobo_Core_Sync_Health::mark_synced( $guid, $product_id, $portal_revision, $source_hash, $portal_id, $portal_version, false ) ) {
				$health_refresh_failed++;
				if ( count( $health_refresh_failed_guids ) < 100 ) {
					$health_refresh_failed_guids[] = $guid;
				}
			}

			$healed[] = $guid;
		}

		$previous_result = get_option( 'mobo_core_103353_convergence_residue_selfheal', array() );
		$previous_result = is_array( $previous_result ) ? $previous_result : array();
		if ( '10.34.67-v11' !== (string) ( $previous_result['version'] ?? '' ) ) {
			$previous_result = array();
		}
		$previous_healed = isset( $previous_result['healedGuids'] ) && is_array( $previous_result['healedGuids'] )
			? $previous_result['healedGuids']
			: array();
		$all_healed = array_values( array_unique( array_merge( $previous_healed, $healed ) ) );
		$cumulative_healed = absint( $previous_result['healedCount'] ?? 0 ) + count( $healed );
		$cumulative_rejected = absint( $previous_result['proofRejectedCount'] ?? 0 ) + $proof_rejected;
		$cumulative_health_refresh_failed = absint( $previous_result['healthRefreshFailedCount'] ?? 0 ) + $health_refresh_failed;
		$previous_health_refresh_failed_guids = isset( $previous_result['healthRefreshFailedGuids'] ) && is_array( $previous_result['healthRefreshFailedGuids'] )
			? $previous_result['healthRefreshFailedGuids']
			: array();
		$all_health_refresh_failed_guids = array_values( array_unique( array_merge( $previous_health_refresh_failed_guids, $health_refresh_failed_guids ) ) );
		$has_more = count( $candidates ) >= 50;

		update_option(
			'mobo_core_103353_convergence_residue_selfheal',
			array(
				'version'      => '10.34.67-v11',
				'proofSource'  => 'post-retirement-product-state+canonical-custom-variant-convergence',
				'updatedAt'    => time(),
				'completedAt'  => $has_more ? 0 : time(),
				'cursor'       => $last_map_id,
				'candidateCount' => absint( $previous_result['candidateCount'] ?? 0 ) + count( $candidates ),
				'examined'     => absint( $previous_result['examined'] ?? 0 ) + $examined,
				'healedCount'  => $cumulative_healed,
				'proofRejectedCount' => $cumulative_rejected,
				'healthRefreshFailedCount' => $cumulative_health_refresh_failed,
				'healthRefreshFailedGuids' => array_slice( $all_health_refresh_failed_guids, -100 ),
				'healedGuids'  => array_slice( $all_healed, -500 ),
				'lastBatch'    => array(
					'examined' => $examined,
					'healed'   => count( $healed ),
					'proofRejected' => $proof_rejected,
					'healthRefreshFailed' => $health_refresh_failed,
				),
			),
			false
		);

		if ( $has_more ) {
			update_option( 'mobo_core_103353_convergence_residue_selfheal_cursor', $last_map_id, false );
		} else {
			delete_option( 'mobo_core_103353_convergence_residue_selfheal_cursor' );
			delete_option( 'mobo_core_103353_convergence_residue_selfheal_pending' );
		}
		return true;
	}


	/**
	 * Which candidate products still own an active (or historically terminal
	 * incomplete-attribute) ProductUpdated/UpdateVariant event.
	 *
	 * Bounded: keyset pages of GUID columns only (no payload_json bulk load), at
	 * most SELFHEAL_EVENT_SCAN_CAP events and SELFHEAL_PAYLOAD_LOOKUP_CAP single-row
	 * payload reads per call. Returns null (fail closed) when an event cannot be
	 * attributed or the backlog exceeds the cap.
	 *
	 * @return array{active:array<string,bool>,terminal:array<string,bool>}|null
	 */
	private static function residue_selfheal_busy_guids( $connection_id, $candidate_guids, $event_table, $product_table, $variant_table ) {
		global $wpdb;
		$candidates = array();
		foreach ( (array) $candidate_guids as $guid ) {
			$guid = sanitize_text_field( (string) $guid );
			if ( '' !== $guid ) { $candidates[ $guid ] = true; }
		}
		$out = array( 'active' => array(), 'terminal' => array() );
		if ( empty( $candidates ) ) { return $out; }
		$like = $wpdb->esc_like( 'Incomplete Mobo variant attributes.' ) . '%';
		$cursor = 0;
		$scanned = 0;
		$payload_reads = 0;
		do {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id,event_type,entity_type,entity_guid,status FROM {$event_table}
				 WHERE connection_id=%d AND id>%d AND event_type IN ('ProductUpdated','UpdateVariant')
				   AND ( status IN ('pending','processing','retry','deferred')
				         OR (event_type='UpdateVariant' AND status='failed' AND try_count>=5 AND last_error LIKE %s) )
				 ORDER BY id ASC LIMIT 200",
				absint( $connection_id ), $cursor, $like
			), ARRAY_A );
			if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $rows ) ) { return null; }
			if ( empty( $rows ) ) { break; }
			$scanned += count( $rows );
			if ( $scanned > self::SELFHEAL_EVENT_SCAN_CAP ) { return null; }

			/* Resolve entity GUIDs of this page set-wise through Custom Storage. */
			$guids = array();
			foreach ( $rows as $row ) { $g = sanitize_text_field( (string) ( $row['entity_guid'] ?? '' ) ); if ( '' !== $g ) { $guids[ $g ] = true; } }
			$known_products = array();
			$variant_owner = array();
			if ( ! empty( $guids ) ) {
				$list = array_keys( $guids );
				$ph = implode( ',', array_fill( 0, count( $list ), '%s' ) );
				$wpdb->last_error = '';
				$found = $wpdb->get_col( $wpdb->prepare( "SELECT product_guid FROM {$product_table} WHERE connection_id=%d AND product_guid IN ({$ph})", array_merge( array( absint( $connection_id ) ), $list ) ) );
				if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $found ) ) { return null; }
				foreach ( $found as $g ) { $known_products[ (string) $g ] = true; }
				$wpdb->last_error = '';
				$vrows = $wpdb->get_results( $wpdb->prepare( "SELECT variant_guid,product_guid FROM {$variant_table} WHERE connection_id=%d AND variant_guid IN ({$ph})", array_merge( array( absint( $connection_id ) ), $list ) ), ARRAY_A );
				if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $vrows ) ) { return null; }
				foreach ( $vrows as $vrow ) { $variant_owner[ (string) ( $vrow['variant_guid'] ?? '' ) ] = (string) ( $vrow['product_guid'] ?? '' ); }
			}

			foreach ( $rows as $row ) {
				$cursor = max( $cursor, absint( $row['id'] ?? 0 ) );
				$event_type = sanitize_text_field( (string) ( $row['event_type'] ?? '' ) );
				$entity_type = sanitize_key( (string) ( $row['entity_type'] ?? '' ) );
				$entity_guid = sanitize_text_field( (string) ( $row['entity_guid'] ?? '' ) );
				$entity_is_product = 'ProductUpdated' === $event_type || ( 'UpdateVariant' === $event_type && 'product' === $entity_type );
				$owner = '';
				if ( '' !== $entity_guid && ! $entity_is_product && isset( $variant_owner[ $entity_guid ] ) ) {
					$owner = $variant_owner[ $entity_guid ];
				} elseif ( $entity_is_product && preg_match( '/^[0-9a-f-]{32,36}$/i', $entity_guid ) ) {
					/* Product-level events carry the product GUID as entity_guid. */
					$owner = $entity_guid;
				} elseif ( '' !== $entity_guid && ( isset( $candidates[ $entity_guid ] ) || isset( $known_products[ $entity_guid ] ) ) ) {
					$owner = $entity_guid;
				} else {
					/* Unknown entity: read only this one event's payload. */
					if ( ++$payload_reads > self::SELFHEAL_PAYLOAD_LOOKUP_CAP ) { return null; }
					$wpdb->last_error = '';
					$payload_json = $wpdb->get_var( $wpdb->prepare( "SELECT payload_json FROM {$event_table} WHERE id=%d LIMIT 1", absint( $row['id'] ?? 0 ) ) );
					if ( '' !== trim( (string) $wpdb->last_error ) ) { return null; }
					$payload = json_decode( (string) $payload_json, true );
					unset( $payload_json );
					$owner = is_array( $payload ) ? self::extract_product_guid_for_residue_repair( $payload ) : '';
					unset( $payload );
					if ( '' === $owner && $entity_is_product && preg_match( '/^[0-9a-f-]{32,36}$/i', $entity_guid ) ) { $owner = $entity_guid; }
					if ( '' === $owner ) { return null; }
				}
				if ( ! isset( $candidates[ $owner ] ) ) { continue; }
				if ( 'failed' === sanitize_key( (string) ( $row['status'] ?? '' ) ) ) {
					/* Historical terminal evidence remains a conservative exclusion. */
					$out['terminal'][ $owner ] = true;
				} else {
					$out['active'][ $owner ] = true;
				}
			}
		} while ( count( $rows ) >= 200 );
		return $out;
	}

	/**
	 * Extract the canonical product GUID from a webhook payload for release-residue ownership.
	 *
	 * @param mixed $data Decoded webhook payload fragment.
	 * @return string
	 */
	private static function extract_product_guid_for_residue_repair( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		foreach ( array( 'productId', 'productGuid', 'product_guid', 'guid' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$guid = sanitize_text_field( (string) $data[ $key ] );
				if ( preg_match( '/^[0-9a-f-]{32,36}$/i', $guid ) ) {
					return $guid;
				}
			}
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$found = self::extract_product_guid_for_residue_repair( $value );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}
		return '';
	}


	/**
	 * Remove provably redundant physical `_thumbnail_id` duplicates for Mobo-managed products.
	 *
	 * This repair is deliberately proof-only: the oldest physical row is retained only
	 * when the normal WordPress singleton read resolves to that exact value. This allows
	 * conflicting later duplicates to be removed without changing the effective thumbnail.
	 * If public-read and oldest-row values disagree, the product is left untouched for
	 * operator review. At most 100 duplicate products are examined per request.
	 *
	 * @return bool True when the bounded audit executed successfully.
	 */
	private static function run_103454_singleton_meta_dedupe() {
		global $wpdb;

		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! class_exists( 'Mobo_Core_Connection_Context' ) ) {
			return false;
		}
		/* Only the locked budgeted slice may run this repair (never storefront init). */
		if ( '' === self::$budgeted_slice_lock_token ) {
			return false;
		}

		$default = Mobo_Core_Connection_Repository::get_default();
		$default_id = is_array( $default ) ? absint( $default['id'] ?? 0 ) : 0;
		if ( $default_id <= 0 ) {
			return false;
		}
		$current_id = absint( Mobo_Core_Connection_Context::get_current_connection_id() );
		if ( $current_id > 0 && $current_id !== $default_id ) {
			return false;
		}
		if ( $current_id !== $default_id ) {
			return (bool) Mobo_Core_Connection_Context::run( $default_id, static function () {
				return self::run_103454_singleton_meta_dedupe();
			} );
		}

		/* 10.34.77: keyset scan by post-id window instead of re-running a catalog-wide
		 * UNION/GROUP BY/HAVING (plus a second full COUNT) on every batch. Each call
		 * inspects one bounded window of `_thumbnail_id` rows; ownership is then
		 * checked only for the (<=100) duplicate candidates found. One full pass ends
		 * the repair; products left untouched for review are recorded, not rescanned
		 * forever. */
		$cursor = absint( get_option( 'mobo_core_103454_singleton_meta_dedupe_cursor', 0 ) );
		$wpdb->last_error = '';
		$max_id = absint( $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) );
		if ( '' !== trim( (string) $wpdb->last_error ) ) { return false; }
		$window_end = $cursor + self::SINGLETON_DEDUPE_WINDOW;
		$wpdb->last_error = '';
		$duplicates = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft')
			 WHERE pm.meta_key='_thumbnail_id' AND pm.post_id>%d AND pm.post_id<=%d
			 GROUP BY pm.post_id HAVING COUNT(pm.meta_id)>1 ORDER BY pm.post_id ASC LIMIT 100",
			$cursor,
			$window_end
		) );
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $duplicates ) ) {
			return false;
		}
		$duplicates = array_values( array_filter( array_map( 'absint', $duplicates ) ) );
		$next_cursor = count( $duplicates ) >= 100 ? (int) end( $duplicates ) : $window_end;
		$owned = self::singleton_owned_post_ids( $default_id, $duplicates );
		if ( null === $owned ) { return false; }

		$normalized = 0;
		$failed = array();
		foreach ( $owned as $post_id ) {
			$meta_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_thumbnail_id' ORDER BY meta_id ASC",
					$post_id
				), ARRAY_A
			);
			$meta_rows = is_array( $meta_rows ) ? $meta_rows : array();
			if ( count( $meta_rows ) <= 1 ) { continue; }
			$meta_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $meta_rows, 'meta_id' ) ) ) );
			$oldest_value = (string) ( $meta_rows[0]['meta_value'] ?? '' );
			$effective_value = (string) get_post_meta( $post_id, '_thumbnail_id', true );
			if ( ! hash_equals( $oldest_value, $effective_value ) ) { $failed[] = $post_id; continue; }
			$remove_ids = array_values( array_filter( array_map( 'absint', array_slice( $meta_ids, 1 ) ) ) );
			if ( empty( $remove_ids ) ) { continue; }
			$idph = implode( ',', array_fill( 0, count( $remove_ids ), '%d' ) );
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_thumbnail_id' AND meta_id IN ({$idph})", array_merge( array( $post_id ), $remove_ids ) ) );
			if ( false === $deleted ) { $failed[] = $post_id; continue; }
			wp_cache_delete( $post_id, 'post_meta' );
			$remaining = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_thumbnail_id'", $post_id ) ) );
			$remaining_effective = (string) get_post_meta( $post_id, '_thumbnail_id', true );
			if ( 1 !== $remaining || ! hash_equals( $oldest_value, $remaining_effective ) ) { $failed[] = $post_id; continue; }
			$normalized++;
		}

		$previous = get_option( 'mobo_core_103454_singleton_meta_dedupe', array() );
		$previous = is_array( $previous ) ? $previous : array();
		$previous_failed = isset( $previous['failedProductIds'] ) && is_array( $previous['failedProductIds'] ) && 0 !== $cursor ? $previous['failedProductIds'] : array();
		$all_failed = array_values( array_unique( array_map( 'absint', array_merge( $previous_failed, $failed ) ) ) );
		$done = $next_cursor >= $max_id;
		update_option( 'mobo_core_103454_singleton_meta_dedupe', array(
			'updatedAt' => time(),
			'normalizedThisRun' => $normalized,
			'normalizedTotal' => ( 0 !== $cursor ? absint( $previous['normalizedTotal'] ?? 0 ) : 0 ) + $normalized,
			'cursor' => $done ? 0 : $next_cursor,
			'maxPostId' => $max_id,
			/* Products deliberately left for operator review (public read and oldest row disagree). */
			'remaining' => count( $all_failed ),
			'failedProductIds' => array_slice( $all_failed, 0, 25 ),
			'completedAt' => $done ? time() : 0,
		), false );
		if ( $done ) {
			delete_option( 'mobo_core_103454_singleton_meta_dedupe_cursor' );
			delete_option( 'mobo_core_103454_singleton_meta_dedupe_pending' );
		} else {
			update_option( 'mobo_core_103454_singleton_meta_dedupe_cursor', $next_cursor, false );
		}
		return true;
	}

	/**
	 * Filter candidate post ids to the Default Connection's Mobo-owned objects
	 * (Custom Product/Variant State, Product Map, or historical identity postmeta).
	 *
	 * @param int   $default_id Default Connection id.
	 * @param int[] $post_ids   Candidates (<=100).
	 * @return int[]|null
	 */
	private static function singleton_owned_post_ids( $default_id, $post_ids ) {
		global $wpdb;
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) );
		if ( empty( $post_ids ) ) { return array(); }
		$ph = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$owned = array();
		$queries = array();
		if ( class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready() ) {
			$queries[] = $wpdb->prepare( "SELECT woo_product_id FROM " . Mobo_Core_Storage_Schema::table_name( 'products' ) . " WHERE connection_id=%d AND woo_product_id IN ({$ph})", array_merge( array( $default_id ), $post_ids ) );
			$queries[] = $wpdb->prepare( "SELECT woo_variation_id FROM " . Mobo_Core_Storage_Schema::table_name( 'variants' ) . " WHERE connection_id=%d AND woo_variation_id IN ({$ph})", array_merge( array( $default_id ), $post_ids ) );
		}
		if ( class_exists( 'Mobo_Core_Product_Map' ) && Mobo_Core_Product_Map::table_exists() ) {
			$queries[] = $wpdb->prepare( "SELECT wp_post_id FROM " . Mobo_Core_Product_Map::table_name() . " WHERE connection_id=%d AND object_type IN ('product','variation') AND wp_post_id IN ({$ph})", array_merge( array( $default_id ), $post_ids ) );
		}
		$queries[] = $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE post_id IN ({$ph}) AND ( LEFT(meta_key,6)='_mobo_' OR meta_key IN ('product_guid','variant_guid','portal_product_id','mobo_portal_product_id','portal_variant_id','mobo_portal_variant_id') )", $post_ids );
		foreach ( $queries as $sql ) {
			$wpdb->last_error = '';
			$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
			if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $ids ) ) { return null; }
			foreach ( $ids as $id ) { $owned[ absint( $id ) ] = true; }
		}
		return array_values( array_intersect( $post_ids, array_keys( $owned ) ) );
	}

	/**
	 * Permanently retire automatic Reconciliation/Product Recovery runtime state.
	 *
	 * The mobo_sync_health table is retained because it is observational and is
	 * written by ordered Webhook/Product Sync paths. Only obsolete worker settings,
	 * cursors, pending markers and leases are removed.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10334411_retire_automatic_recovery_runtime( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.44.11', '>=' ) ) {
			return;
		}

		foreach ( array(
			'mobo_core_auto_reconciliation_enabled',
			'mobo_core_reconciliation_fast_interval',
			'mobo_core_reconciliation_products_per_run',
			'mobo_core_reconciliation_variation_batch',
			'mobo_core_reconciliation_deep_schedule',
			'mobo_core_reconciliation_state',
			'mobo_core_reconciliation_revision',
			'mobo_core_reconciliation_fallback_cursor',
			'mobo_core_reconciliation_last_check_at',
			'mobo_core_reconciliation_last_success_at',
			'mobo_core_reconciliation_last_deep_at',
			'mobo_core_reconciliation_last_result',
			'mobo_core_reconciliation_changes_endpoint',
			'mobo_core_product_recovery_state',
			'mobo_core_product_recovery_pending',
			'mobo_core_product_recovery_followup_reason',
			'mobo_core_product_recovery_kick_pending',
			'mobo_core_post_recovery_warmup_pending'
		) as $option_name ) {
			delete_option( $option_name );
		}

		if ( class_exists( 'Mobo_Core_Lock' ) ) {
			Mobo_Core_Lock::force_release( 'adaptive_reconciliation' );
			Mobo_Core_Lock::force_release( 'product_recovery' );
			Mobo_Core_Lock::force_release( 'recovery_pipeline' );
		}

		update_option( 'mobo_core_10334411_automatic_recovery_retired_at', time(), false );
	}

}
