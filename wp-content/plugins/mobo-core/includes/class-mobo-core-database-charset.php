<?php
/**
 * Database full-Unicode / utf8mb4 readiness policy.
 *
 * Mobo Core must never silently inherit a three-byte WordPress database charset
 * for its custom tables. This class centralizes schema collation selection and
 * runtime diagnostics for configuration, connection, database defaults and the
 * text tables Mobo actually writes.
 *
 * Conversion is intentionally not performed here. Broad ALTER/CONVERT work must
 * remain an explicit maintenance operation because it can lock/rebuild large
 * WordPress/WooCommerce tables.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Database_Charset {

	/** @var array|null */
	private static $status_cache = null;

	/**
	 * Current accepted full-Unicode charset set.
	 *
	 * A future charset must be added deliberately through product compatibility
	 * work/tests rather than accepted by a loose "utf8*" prefix.
	 *
	 * @return array
	 */
	public static function supported_charsets() {
		$charsets = array( 'utf8mb4' );
		$charsets = apply_filters( 'mobo_core_full_unicode_charsets', $charsets );
		$out = array();
		foreach ( is_array( $charsets ) ? $charsets : array() as $charset ) {
			$charset = self::normalize_charset( $charset );
			if ( '' !== $charset ) {
				$out[ $charset ] = true;
			}
		}
		return array_keys( $out );
	}

	/** @return bool */
	public static function is_full_unicode_charset( $charset ) {
		$charset = self::normalize_charset( $charset );
		return '' !== $charset && in_array( $charset, self::supported_charsets(), true );
	}

	/** @return bool */
	public static function is_full_unicode_collation( $collation ) {
		$collation = strtolower( trim( (string) $collation ) );
		if ( '' === $collation ) {
			return false;
		}
		foreach ( self::supported_charsets() as $charset ) {
			if ( 0 === strpos( $collation, $charset . '_' ) || $collation === $charset ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return the only charset/collation clause Mobo-owned CREATE TABLE statements
	 * should use. Never return an utf8/utf8mb3 clause inherited from wpdb.
	 *
	 * @return string
	 */
	public static function schema_charset_collate() {
		global $wpdb;

		$charset = 'utf8mb4';
		$supported = self::server_supports_charset( $charset );
		$collations = $supported ? self::server_collations( $charset ) : array();

		$candidates = array();
		if ( defined( 'DB_COLLATE' ) && self::is_full_unicode_collation( DB_COLLATE ) ) {
			$candidates[] = strtolower( trim( (string) DB_COLLATE ) );
		}
		if ( isset( $wpdb->collate ) && self::is_full_unicode_collation( $wpdb->collate ) ) {
			$candidates[] = strtolower( trim( (string) $wpdb->collate ) );
		}
		$candidates[] = 'utf8mb4_unicode_ci';
		$candidates[] = 'utf8mb4_general_ci';

		foreach ( $candidates as $candidate ) {
			if ( empty( $collations ) || isset( $collations[ $candidate ] ) ) {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE ' . $candidate;
			}
		}

		/*
		 * Do not fall back to $wpdb->get_charset_collate(). On a server without
		 * utf8mb4 this DDL will fail visibly instead of creating a three-byte table
		 * and allowing the installation to be marked healthy.
		 */
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	/**
	 * Runtime readiness snapshot. By default this is metadata-only and safe for
	 * frequent Site Health reads. An explicit maintenance/release probe may request
	 * a real supplementary-Unicode round-trip through the live DB connection.
	 *
	 * @param bool $round_trip Whether to execute a temporary-table 4-byte probe.
	 * @param bool $force Force metadata refresh.
	 * @return array
	 */
	public static function get_status( $round_trip = false, $force = false ) {
		global $wpdb;

		if ( ! $round_trip && ! $force && is_array( self::$status_cache ) ) {
			return self::$status_cache;
		}

		$config_charset = defined( 'DB_CHARSET' ) ? self::normalize_charset( DB_CHARSET ) : '';
		$config_collate = defined( 'DB_COLLATE' ) ? strtolower( trim( (string) DB_COLLATE ) ) : '';
		$wpdb_charset   = isset( $wpdb->charset ) ? self::normalize_charset( $wpdb->charset ) : '';
		$wpdb_collate   = isset( $wpdb->collate ) ? strtolower( trim( (string) $wpdb->collate ) ) : '';

		$variables = self::connection_variables();
		$database  = self::database_defaults();

		$mobo_tables = self::mobo_owned_tables();
		$mobo_audit  = self::audit_tables( $mobo_tables );

		$required_tables = self::required_wordpress_woocommerce_tables();
		$required_audit  = self::audit_tables( $required_tables );

		$config_ready = self::is_full_unicode_charset( $config_charset )
			&& ( '' === $config_collate || self::is_full_unicode_collation( $config_collate ) );
		$wpdb_ready = self::is_full_unicode_charset( $wpdb_charset )
			&& ( '' === $wpdb_collate || self::is_full_unicode_collation( $wpdb_collate ) );

		$connection_ready = true;
		foreach ( array( 'client', 'connection', 'results' ) as $key ) {
			if ( ! self::is_full_unicode_charset( isset( $variables[ $key ] ) ? $variables[ $key ] : '' ) ) {
				$connection_ready = false;
				break;
			}
		}

		$database_ready = self::is_full_unicode_charset( isset( $database['charset'] ) ? $database['charset'] : '' )
			&& self::is_full_unicode_collation( isset( $database['collation'] ) ? $database['collation'] : '' );

		$round_trip_result = array(
			'checked' => false,
			'success' => null,
			'error'   => '',
		);
		if ( $round_trip ) {
			$round_trip_result = self::round_trip_probe();
		}

		$ready = self::server_supports_charset( 'utf8mb4' )
			&& $config_ready
			&& $wpdb_ready
			&& $connection_ready
			&& $database_ready
			&& empty( $mobo_audit['error'] )
			&& empty( $required_audit['error'] )
			&& empty( $mobo_audit['unsupported'] )
			&& empty( $required_audit['unsupported'] );

		if ( $round_trip ) {
			$ready = $ready && ! empty( $round_trip_result['success'] );
		}

		$status = array(
			'ready'                    => (bool) $ready,
			'requiredCharset'          => 'utf8mb4',
			'serverSupportsUtf8mb4'    => self::server_supports_charset( 'utf8mb4' ),
			'configCharset'            => $config_charset,
			'configCollation'          => $config_collate,
			'configReady'              => (bool) $config_ready,
			'wpdbCharset'              => $wpdb_charset,
			'wpdbCollation'            => $wpdb_collate,
			'wpdbReady'                => (bool) $wpdb_ready,
			'connectionCharsets'       => $variables,
			'connectionReady'          => (bool) $connection_ready,
			'databaseDefaultCharset'   => isset( $database['charset'] ) ? (string) $database['charset'] : '',
			'databaseDefaultCollation' => isset( $database['collation'] ) ? (string) $database['collation'] : '',
			'databaseDefaultReady'     => (bool) $database_ready,
			'moboTablesChecked'        => count( $mobo_tables ),
			'moboUnsupportedColumns'   => $mobo_audit['unsupported'],
			'requiredTablesChecked'    => count( $required_tables ),
			'requiredUnsupportedColumns' => $required_audit['unsupported'],
			'roundTrip'                => $round_trip_result,
			'message'                  => self::status_message(
				$config_ready,
				$wpdb_ready,
				$connection_ready,
				$database_ready,
				$mobo_audit,
				$required_audit,
				$round_trip_result,
				$round_trip
			),
		);

		if ( ! $round_trip ) {
			self::$status_cache = $status;
		}
		return $status;
	}

	/**
	 * Verify only a supplied set of tables. Used by custom-storage readiness.
	 *
	 * @param array $tables Table names.
	 * @return bool
	 */
	public static function tables_are_full_unicode( $tables ) {
		$audit = self::audit_tables( is_array( $tables ) ? $tables : array() );
		return empty( $audit['error'] ) && empty( $audit['unsupported'] );
	}

	/** Clear request-local diagnostic cache after schema/config changes. */
	public static function reset_cache() {
		self::$status_cache = null;
	}

	/**
	 * Execute a real four-byte write/read proof without persistent data.
	 *
	 * @return array
	 */
	public static function round_trip_probe() {
		global $wpdb;

		$result = array(
			'checked' => true,
			'success' => false,
			'error'   => '',
		);

		if ( ! self::server_supports_charset( 'utf8mb4' ) ) {
			$result['error'] = 'Database server does not report utf8mb4 support.';
			return $result;
		}

		$table = $wpdb->prefix . 'mobo_unicode_probe_' . strtolower( wp_generate_password( 8, false, false ) );
		$table = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
		$collate = self::schema_charset_collate();
		$probe = "Mobo-\xF0\x9F\x9A\x80-\xF0\x9F\xA7\xAA";
		$expected_hex = strtoupper( bin2hex( $probe ) );
		$old_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';

		try {
			$created = $wpdb->query( "CREATE TEMPORARY TABLE `{$table}` (probe varchar(64) NOT NULL) {$collate}" );
			if ( false === $created ) {
				$result['error'] = self::bounded_db_error( 'Could not create utf8mb4 probe table.' );
				return $result;
			}

			$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (probe) VALUES (%s)", $probe ) );
			if ( false === $inserted || 1 !== absint( $inserted ) ) {
				$result['error'] = self::bounded_db_error( 'Could not write supplementary Unicode through the active DB connection.' );
				return $result;
			}

			$actual_hex = strtoupper( (string) $wpdb->get_var( "SELECT HEX(probe) FROM `{$table}` LIMIT 1" ) );
			if ( '' === $actual_hex || ! hash_equals( $expected_hex, $actual_hex ) ) {
				$result['error'] = 'Supplementary Unicode round-trip changed bytes.';
				return $result;
			}

			$result['success'] = true;
			return $result;
		} finally {
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS `{$table}`" );
			$wpdb->last_error = $old_error;
		}
	}

	/** @return array */
	private static function connection_variables() {
		global $wpdb;
		$row = $wpdb->get_row(
			'SELECT @@character_set_client AS client, @@character_set_connection AS connection, @@character_set_results AS results',
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return array( 'client' => '', 'connection' => '', 'results' => '' );
		}
		return array(
			'client'     => self::normalize_charset( isset( $row['client'] ) ? $row['client'] : '' ),
			'connection' => self::normalize_charset( isset( $row['connection'] ) ? $row['connection'] : '' ),
			'results'    => self::normalize_charset( isset( $row['results'] ) ? $row['results'] : '' ),
		);
	}

	/** @return array */
	private static function database_defaults() {
		global $wpdb;
		$db_name = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		if ( '' === $db_name ) {
			return array( 'charset' => '', 'collation' => '' );
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT DEFAULT_CHARACTER_SET_NAME AS charset_name, DEFAULT_COLLATION_NAME AS collation_name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s LIMIT 1',
				$db_name
			),
			ARRAY_A
		);
		return array(
			'charset'   => self::normalize_charset( is_array( $row ) && isset( $row['charset_name'] ) ? $row['charset_name'] : '' ),
			'collation' => strtolower( trim( (string) ( is_array( $row ) && isset( $row['collation_name'] ) ? $row['collation_name'] : '' ) ) ),
		);
	}

	/** @return array */
	private static function mobo_owned_tables() {
		global $wpdb;
		$db_name = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		if ( '' === $db_name ) {
			return array();
		}
		$pattern = $wpdb->esc_like( $wpdb->prefix . 'mobo_' ) . '%';
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME',
				$db_name,
				$pattern
			)
		);
		return array_values( array_unique( array_filter( array_map( 'strval', is_array( $rows ) ? $rows : array() ) ) ) );
	}

	/** @return array */
	private static function required_wordpress_woocommerce_tables() {
		global $wpdb;
		$candidates = array(
			$wpdb->options,
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->terms,
			$wpdb->term_taxonomy,
			$wpdb->termmeta,
			$wpdb->comments,
			$wpdb->commentmeta,
			$wpdb->prefix . 'woocommerce_order_items',
			$wpdb->prefix . 'woocommerce_order_itemmeta',
			$wpdb->prefix . 'wc_orders',
			$wpdb->prefix . 'wc_order_addresses',
			$wpdb->prefix . 'wc_orders_meta',
			$wpdb->prefix . 'wc_order_operational_data',
		);

		$existing = array();
		foreach ( array_values( array_unique( array_filter( $candidates ) ) ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( (string) $found === (string) $table ) {
				$existing[] = (string) $table;
			}
		}
		return $existing;
	}

	/**
	 * @param array $tables Exact table names.
	 * @return array
	 */
	private static function audit_tables( $tables ) {
		global $wpdb;

		$tables = array_values( array_unique( array_filter( array_map( 'strval', is_array( $tables ) ? $tables : array() ) ) ) );
		$out = array( 'unsupported' => array(), 'error' => '' );
		if ( empty( $tables ) ) {
			return $out;
		}
		if ( ! defined( 'DB_NAME' ) || '' === (string) DB_NAME ) {
			$out['error'] = 'Database name is unavailable for Unicode column inspection.';
			return $out;
		}

		$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$args = array_merge( array( (string) DB_NAME ), $tables );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = %s
				  AND TABLE_NAME IN ({$placeholders})
				  AND CHARACTER_SET_NAME IS NOT NULL
				ORDER BY TABLE_NAME, ORDINAL_POSITION",
				$args
			),
			ARRAY_A
		);

		/* Missing metadata is not evidence that every text column is utf8mb4. */
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			$out['error'] = 'Could not inspect database text columns for Unicode readiness.';
			return $out;
		}
		foreach ( $rows as $row ) {
			$charset = self::normalize_charset( isset( $row['CHARACTER_SET_NAME'] ) ? $row['CHARACTER_SET_NAME'] : '' );
			$collation = strtolower( trim( (string) ( isset( $row['COLLATION_NAME'] ) ? $row['COLLATION_NAME'] : '' ) ) );
			if ( self::is_full_unicode_charset( $charset ) && self::is_full_unicode_collation( $collation ) ) {
				continue;
			}
			$out['unsupported'][] = array(
				'table'     => sanitize_text_field( (string) ( isset( $row['TABLE_NAME'] ) ? $row['TABLE_NAME'] : '' ) ),
				'column'    => sanitize_text_field( (string) ( isset( $row['COLUMN_NAME'] ) ? $row['COLUMN_NAME'] : '' ) ),
				'charset'   => $charset,
				'collation' => $collation,
			);
			if ( count( $out['unsupported'] ) >= 50 ) {
				break;
			}
		}

		return $out;
	}

	/** @return bool */
	private static function server_supports_charset( $charset ) {
		global $wpdb;
		$charset = self::normalize_charset( $charset );
		if ( '' === $charset ) {
			return false;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CHARACTER SET LIKE %s', $charset ), ARRAY_A );
		return is_array( $row ) && ! empty( $row );
	}

	/** @return array<string,bool> */
	private static function server_collations( $charset ) {
		global $wpdb;
		$charset = self::normalize_charset( $charset );
		if ( '' === $charset ) {
			return array();
		}
		$rows = $wpdb->get_col( $wpdb->prepare( 'SHOW COLLATION WHERE Charset = %s', $charset ), 0 );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $collation ) {
			$collation = strtolower( trim( (string) $collation ) );
			if ( '' !== $collation ) {
				$out[ $collation ] = true;
			}
		}
		return $out;
	}

	/** @return string */
	private static function normalize_charset( $charset ) {
		$charset = strtolower( trim( (string) $charset ) );
		if ( 'utf8' === $charset || 'utf8mb3' === $charset ) {
			return $charset;
		}
		return preg_replace( '/[^a-z0-9_]/', '', $charset );
	}

	/** @return string */
	private static function bounded_db_error( $fallback ) {
		global $wpdb;
		$error = trim( (string) $wpdb->last_error );
		if ( '' === $error ) {
			return (string) $fallback;
		}
		return substr( sanitize_text_field( $error ), 0, 300 );
	}

	/** @return string */
	private static function status_message( $config_ready, $wpdb_ready, $connection_ready, $database_ready, $mobo_audit, $required_audit, $round_trip, $round_trip_requested ) {
		if ( ! self::server_supports_charset( 'utf8mb4' ) ) {
			return 'Database server does not support utf8mb4.';
		}
		if ( ! $config_ready ) {
			return 'WordPress DB_CHARSET/DB_COLLATE is not full-Unicode utf8mb4.';
		}
		if ( ! $wpdb_ready || ! $connection_ready ) {
			return 'The active WordPress database connection is not using utf8mb4 end-to-end.';
		}
		if ( ! $database_ready ) {
			return 'The WordPress database default charset/collation is not utf8mb4.';
		}
		if ( ! empty( $mobo_audit['error'] ) || ! empty( $required_audit['error'] ) ) {
			return 'Database Unicode column inspection is incomplete; readiness could not be verified.';
		}
		if ( ! empty( $mobo_audit['unsupported'] ) ) {
			return 'One or more Mobo Core text columns are not utf8mb4.';
		}
		if ( ! empty( $required_audit['unsupported'] ) ) {
			return 'One or more WordPress/WooCommerce text columns required by Mobo Core are not utf8mb4.';
		}
		if ( $round_trip_requested && empty( $round_trip['success'] ) ) {
			return ! empty( $round_trip['error'] ) ? (string) $round_trip['error'] : 'Supplementary Unicode round-trip failed.';
		}
		return 'Database Unicode compatibility is ready.';
	}
}
