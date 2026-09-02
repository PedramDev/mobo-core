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

	/** @var string Last concrete schema validation/repair failure for diagnostics. */
	private static $schema_last_failure = '';

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

		self::ensure_defaults();
		self::apply_10331613_runtime_option_autoload_cleanup( '' );
		self::apply_10307_default_adjustments( '' );
		self::ensure_cron_token();
		self::ensure_webhook_dirs();
		self::cleanup_legacy_private_city_assets();
		self::cleanup_deprecated_pw_option_enforcement_state();
		$schema_ready = self::create_database_tables();
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
		self::maybe_mark_legacy_repair_required( '' );
		self::seed_product_map_from_legacy_meta();
		self::seed_category_map_from_legacy_meta();
		self::discard_legacy_webhook_queue();
		self::clear_legacy_cron_hooks();

		self::finalize_database_version( $schema_ready );
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

		self::ensure_defaults();
		self::apply_10331613_runtime_option_autoload_cleanup( $current );
		self::apply_10307_default_adjustments( $current );
		self::ensure_cron_token();
		self::ensure_webhook_dirs();
		self::cleanup_legacy_private_city_assets();
		self::cleanup_deprecated_pw_option_enforcement_state();
		$schema_ready = self::create_database_tables();
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
		self::maybe_mark_legacy_repair_required( $current );
		self::seed_product_map_from_legacy_meta();
		self::seed_category_map_from_legacy_meta();
		self::discard_legacy_webhook_queue();
		self::clear_legacy_cron_hooks();

		/*
		 * Cleanup old beta option if it exists.
		 * WP-Cron is not used in final architecture.
		 */
		delete_option( 'mobo_core_enable_wp_cron' );

		self::finalize_database_version( $schema_ready );
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
	 * also stores webhook fallback data. Public city assets are regenerated in
	 * uploads/mobo-core-public/assets by Mobo_Core_City_Assets.
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
	 * Create/update custom sync tables.
	 *
	 * @return bool True only when required runtime schema postconditions exist.
	 */
	private static function create_database_tables() {
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

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			Mobo_Core_Orphan_Image_Cleanup::create_table();
		}

		if ( class_exists( 'Mobo_Core_Sync_Health' ) ) {
			Mobo_Core_Sync_Health::create_table();
		}

		$ready = self::database_schema_ready();
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

		$classes = array(
			'Mobo_Core_Product_Map',
			'Mobo_Core_Product_Ledger',
			'Mobo_Core_Sync_Event_Store',
			'Mobo_Core_Category_Map',
			'Mobo_Core_Image_Queue',
			'Mobo_Core_Image_Refresh_Queue',
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

		$health_table   = Mobo_Core_Sync_Health::table_name();
		$portal_version = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$health_table} LIKE %s", 'portal_version' ) );
		if ( 'portal_version' !== (string) $portal_version ) {
			self::$schema_last_failure = 'required column is missing or inaccessible: ' . $health_table . '.portal_version.';
			return false;
		}

		/*
		 * Identity/ownership indexes are correctness constraints, not merely query
		 * optimizations. A partial dbDelta that creates columns but misses one of these
		 * indexes must never advance the schema version. Validate both the column order
		 * and uniqueness flag; the Product Map GUID prefixes are also part of the old
		 * utf8mb4 key-length compatibility contract.
		 */
		$required_indexes = array(
			array( Mobo_Core_Product_Map::table_name(), 'remote_object', true, array( array( 'remote_guid', 150 ), array( 'object_type', 0 ) ) ),
			array( Mobo_Core_Product_Map::table_name(), 'parent_object', false, array( array( 'parent_remote_guid', 120 ), array( 'object_type', 0 ) ) ),
			array( Mobo_Core_Product_Ledger::table_name(), 'product_guid', true, array( array( 'product_guid', null ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'event_uuid', true, array( array( 'event_uuid', 0 ) ) ),
			array( Mobo_Core_Sync_Event_Store::table_name(), 'claim_token', false, array( array( 'claim_token', 0 ) ) ),
			array( Mobo_Core_Category_Map::table_name(), 'remote_guid', true, array( array( 'remote_guid', 0 ) ) ),
			array( Mobo_Core_Image_Queue::table_name(), 'queue_key', true, array( array( 'queue_key', 0 ) ) ),
			array( Mobo_Core_Image_Refresh_Queue::table_name(), 'queue_key', true, array( array( 'queue_key', 0 ) ) ),
			array( Mobo_Core_Orphan_Image_Cleanup::table_name(), 'file_key', true, array( array( 'file_key', 0 ) ) ),
			array( Mobo_Core_Sync_Health::table_name(), 'product_guid', true, array( array( 'product_guid', 0 ) ) ),
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

		return true;
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
			self::$schema_last_failure = 'SHOW INDEX failed for ' . $table . ': ' . (string) $wpdb->last_error;
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
				? 'could not atomically replace required index ' . $table . '.' . $index . '; the previous table definition was retained: ' . (string) $wpdb->last_error
				: 'could not create required index ' . $table . '.' . $index . ': ' . (string) $wpdb->last_error;
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
		if ( $ready ) {
			update_option( 'mobo_core_db_version', MOBO_CORE_VERSION, false );
			delete_option( 'mobo_core_schema_last_error' );
			delete_option( 'mobo_core_schema_last_error_at' );
			return;
		}

		$error = '' !== self::$schema_last_failure
			? 'Mobo Core database migration did not satisfy required schema postconditions: ' . self::$schema_last_failure . ' It will retry on the next request.'
			: 'Mobo Core database migration did not satisfy required schema postconditions; it will retry on the next request.';
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
			delete_option( $option_name );
		}

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
			$cleanup->reset( true );
		}

		update_option( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_catalog_rescan_migrated_at', time(), false );
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
			delete_option( $option_name );
		}

		update_option( 'mobo_core_image_refresh_automation_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
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
			delete_option( $option_name );
		}

		update_option( 'mobo_core_image_refresh_automation_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
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
			delete_option( $option_name );
		}

		update_option( 'mobo_core_image_refresh_automation_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
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

		delete_option( 'mobo_core_image_refresh_auto_delete_old_approved' );

		$last = get_option( 'mobo_core_image_refresh_automation_last_result', array() );
		if ( is_array( $last ) && isset( $last['waitingApproval'] ) && 'delete-old' === sanitize_key( (string) $last['waitingApproval'] ) ) {
			unset( $last['waitingApproval'] );
			$delete_enabled = Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' );
			$last['status'] = $delete_enabled ? 'delete-old-setting-enabled' : 'waiting-delete-old-setting';
			$last['needsContinuation'] = $delete_enabled;
			$last['message'] = $delete_enabled
				? 'تنظیم دائمی حذف پیوست قدیمی فعال است و مرحله ۷ بدون تایید مجدد ادامه پیدا می کند.'
				: 'برای مرحله ۷، گزینه حذف پیوست قدیمی بعد از جایگزینی امن را یک بار فعال کنید؛ این انتخاب در ادامه حفظ می شود.';
			update_option( 'mobo_core_image_refresh_automation_last_result', $last, false );
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

		$delete_state = get_option( 'mobo_core_image_refresh_last_replaced_delete', array() );
		if ( is_array( $delete_state ) && 'delete' === ( isset( $delete_state['mode'] ) ? (string) $delete_state['mode'] : '' ) ) {
			$legacy_failed = absint( isset( $delete_state['failed'] ) ? $delete_state['failed'] : 0 );
			if ( $legacy_failed > 0 && ! isset( $delete_state['blocked'] ) ) {
				/* 10.33.14 did not distinguish safety blocks from operational errors.
				 * Preserve them conservatively as blocked legacy items for reporting. */
				$delete_state['blocked'] = $legacy_failed;
				$delete_state['errors']  = 0;
				update_option( 'mobo_core_image_refresh_last_replaced_delete', $delete_state, false );
			}
		}

		$last = get_option( 'mobo_core_image_refresh_automation_last_result', array() );
		if ( is_array( $last ) && 'delete-old-failed' === sanitize_key( isset( $last['status'] ) ? (string) $last['status'] : '' ) ) {
			update_option( 'mobo_core_image_refresh_automation_enabled', '1', false );
			$last['success']           = true;
			$last['status']            = 'stage7-resumed-after-upgrade';
			$last['step']              = 7;
			$last['needsContinuation'] = true;
			$last['progressed']        = false;
			$last['message']           = 'مرحله ۷ پس از ارتقا با منطق غیرمسدودکننده ادامه پیدا می کند؛ پیوست های دارای Safety Block ثبت و رد می شوند و کل چرخه را متوقف نمی کنند.';
			update_option( 'mobo_core_image_refresh_automation_last_result', $last, false );
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

		$scan = get_option( 'mobo_core_image_refresh_last_replaced_scan', array() );
		$scan = is_array( $scan ) ? $scan : array();
		$delete = get_option( 'mobo_core_image_refresh_last_replaced_delete', array() );
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
			update_option( 'mobo_core_image_refresh_last_replaced_delete', $delete, false );
		}

		$last = get_option( 'mobo_core_image_refresh_automation_last_result', array() );
		$last = is_array( $last ) ? $last : array();
		$last_status = sanitize_key( isset( $last['status'] ) ? (string) $last['status'] : '' );
		$delete_in_progress = ! empty( $delete ) && empty( $delete['cycleComplete'] );
		$needs_followup = ! empty( $delete['cycleComplete'] ) && $delete_progress > 0;
		$was_false_complete = 'completed' === $last_status && $actionable > 0;

		if ( $actionable > 0
			&& Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' )
			&& ( $delete_in_progress || $needs_followup || $was_false_complete ) ) {
			update_option( 'mobo_core_image_refresh_automation_enabled', '1', false );
			update_option( 'mobo_core_image_refresh_automation_completed_at', 0, false );
			$last['success']           = true;
			$last['status']            = 'stage7-auto-resumed-after-upgrade';
			$last['step']              = 7;
			$last['needsContinuation'] = true;
			$last['progressed']        = true;
			$last['message']           = 'Stage 7 با منطق چندگذری خودکار دوباره فعال شد. Cursor فعلی حفظ شده و Cron/Self Runner تا رسیدن به یک گذر کامل بدون پیشرفت جدید، انتقال مراجع و حذف امن را ادامه می دهد.';
			update_option( 'mobo_core_image_refresh_automation_last_result', $last, false );
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

		$scan = get_option( 'mobo_core_image_refresh_last_replaced_scan', array() );
		$scan = is_array( $scan ) ? $scan : array();
		$delete = get_option( 'mobo_core_image_refresh_last_replaced_delete', array() );
		$delete = is_array( $delete ) ? $delete : array();

		$actionable = ! empty( $scan['cycleComplete'] )
			? absint( isset( $scan['ready'] ) ? $scan['ready'] : 0 ) + absint( isset( $scan['migrationCandidates'] ) ? $scan['migrationCandidates'] : 0 )
			: 0;
		$delete_in_progress = ! empty( $delete ) && empty( $delete['cycleComplete'] );
		$needs_followup = ! empty( $delete['needsAnotherPass'] );

		if ( $actionable > 0
			&& Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' )
			&& ( $delete_in_progress || $needs_followup || '1' === (string) get_option( 'mobo_core_image_refresh_automation_enabled', '0' ) ) ) {
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

		$scan   = get_option( 'mobo_core_image_refresh_last_replaced_scan', array() );
		$delete = get_option( 'mobo_core_image_refresh_last_replaced_delete', array() );
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
			update_option( 'mobo_core_image_refresh_automation_enabled', '1', false );
			update_option( 'mobo_core_image_refresh_automation_completed_at', 0, false );
			update_option( 'mobo_core_stage7_resume_kick_pending', '1', false );

			$last = get_option( 'mobo_core_image_refresh_automation_last_result', array() );
			$last = is_array( $last ) ? $last : array();
			$last['success']           = true;
			$last['status']            = 'stage7-autodrain-armed-after-upgrade';
			$last['step']              = 7;
			$last['needsContinuation'] = true;
			$last['progressed']        = false;
			$last['message']           = 'Stage 7 پس از ارتقا برای ادامه خودکار فعال شد. Cursor و Safety Audit فعلی بدون ریست حفظ شده اند.';
			update_option( 'mobo_core_image_refresh_automation_last_result', $last, false );
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
		update_option( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_generate_subsizes', '1', false );
		update_option( 'mobo_core_image_refresh_cleanup_leftover_subsizes', '1', false );
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
		delete_option( 'mobo_core_image_subsize_scan_cursor' );
		delete_option( 'mobo_core_image_subsize_repair_cursor' );
		delete_option( 'mobo_core_image_refresh_last_subsize_scan' );
		delete_option( 'mobo_core_image_refresh_last_subsize_repair' );

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
		/*
		 * Upgrade activation can leave several independent durable intents behind.
		 * Never fire one loopback per intent: collect them and hand the dispatcher a
		 * single wake-up after all cheap/local repair scheduling is complete.
		 */
		$worker_reasons = array();

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

		$worker_reasons = array_values( array_unique( array_filter( array_map( 'sanitize_key', $worker_reasons ) ) ) );
		if ( empty( $worker_reasons ) || ! class_exists( 'Mobo_Core_Self_Runner' ) || ! method_exists( 'Mobo_Core_Self_Runner', 'kick' ) ) {
			return;
		}

		$kick = Mobo_Core_Self_Runner::kick( 'deferred-repair-coalesced', true );
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

		if ( ! class_exists( 'Mobo_Core_Category_Map' ) || ! Mobo_Core_Category_Map::table_exists() ) {
			return $result;
		}

		$table = Mobo_Core_Category_Map::table_name();
		$rows  = $wpdb->get_results(
			"SELECT remote_guid, synced_term_id, remote_name
			FROM {$table}
			WHERE synced_term_id > 0 AND remote_name <> ''
			ORDER BY id ASC
			LIMIT 1000",
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

		delete_option( 'mobo_core_image_refresh_scan_cursor' );
		delete_option( 'mobo_core_image_refresh_enqueue_cursor' );
		delete_option( 'mobo_core_image_refresh_last_scan' );
		delete_option( 'mobo_core_image_refresh_last_enqueue' );
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

		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
		delete_option( 'mobo_core_image_subsize_scan_cursor' );
		delete_option( 'mobo_core_image_subsize_repair_cursor' );
		delete_option( 'mobo_core_image_replaced_scan_cursor' );
		delete_option( 'mobo_core_image_replaced_delete_cursor' );
		delete_option( 'mobo_core_image_refresh_last_subsize_scan' );
		delete_option( 'mobo_core_image_refresh_last_subsize_repair' );
		delete_option( 'mobo_core_image_refresh_last_replaced_scan' );
		delete_option( 'mobo_core_image_refresh_last_replaced_delete' );
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
		update_option( 'mobo_core_checkout_mobo_site_url', defined( 'MOBO_CORE_CHECKOUT_SITE_URL' ) ? MOBO_CORE_CHECKOUT_SITE_URL : 'https://mobomobo.ir', false );
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
		delete_option( 'mobo_core_health_last_report_attempt_at' );
		delete_option( 'mobo_core_health_last_report_success_at' );
		delete_option( 'mobo_core_health_last_report_result' );
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

		update_option( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
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

		update_option( 'mobo_core_image_refresh_automation_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_auto_delete_old_approved', '0', false );
		update_option( 'mobo_core_image_refresh_auto_delete_orphan_approved', '0', false );
		update_option( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
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

		delete_option( 'mobo_core_repair_completed_at' );
		delete_option( 'mobo_core_repair_last_sync_id' );
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

		delete_option( 'mobo_core_image_refresh_preflight_cleanup_completed_at' );
		delete_option( 'mobo_core_image_refresh_repair_retry_count' );
		delete_option( 'mobo_core_image_refresh_repair_retry_at' );
		delete_option( 'mobo_core_incomplete_collision_cleanup_cursor' );
		delete_option( 'mobo_core_incomplete_collision_cleanup_last_result' );

		$refresh_scan = absint( get_option( 'mobo_core_image_refresh_scan_limit', 50 ) );
		if ( $refresh_scan <= 0 || $refresh_scan > 50 ) {
			update_option( 'mobo_core_image_refresh_scan_limit', 50, false );
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

		update_option( 'mobo_core_image_refresh_force_source_reimport', '1', false );
		if ( '1' !== (string) get_option( 'mobo_core_image_refresh_automation_enabled', '0' ) ) {
			delete_option( 'mobo_core_image_refresh_generation_id' );
			delete_option( 'mobo_core_image_refresh_generation_started_at' );
			delete_option( 'mobo_core_image_refresh_generation_stats' );
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

		$map    = new Mobo_Core_Product_Map();
		$result = $map->seed_from_legacy_meta( 500 );

		update_option( 'mobo_core_product_map_last_seed_result', $result, false );
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
		$result = $map->seed_from_legacy_term_meta( 500 );

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
