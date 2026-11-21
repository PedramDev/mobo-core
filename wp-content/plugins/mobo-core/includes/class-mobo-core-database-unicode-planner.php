<?php
/**
 * Pure planner for the manual utf8mb4 conversion tool.
 *
 * Input is plain metadata (information_schema rows and server variables); output
 * is an ordered, per-table plan. No database access, so ordering, skip and
 * index-length decisions are unit-testable on any PHP.
 *
 * Ordering: wp_terms / wp_term_taxonomy / wp_termmeta first (they unblock
 * emoji category titles fastest), then Mobo tables, then everything else; each
 * group smallest first.
 *
 * Index key length: ALTER ... CONVERT keeps index definitions, so every text
 * key part grows to 4 bytes per character. InnoDB COMPACT/REDUNDANT rows allow
 * only 767 bytes per key; DYNAMIC/COMPRESSED allow 3072 when large prefixes are
 * available. A table that only fits with DYNAMIC is converted together with
 * ROW_FORMAT=DYNAMIC; a table that cannot fit at all is skipped with a clear
 * message (never truncated, never dropped).
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Database_Unicode_Planner {

	const STRATEGY_CONVERT         = 'convert';
	const STRATEGY_CONVERT_DYNAMIC = 'convert_dynamic';
	const STRATEGY_SKIP            = 'skip';

	/** Conservative shared-host ALTER COPY throughput (bytes/second). */
	const BYTES_PER_SECOND = 4194304;

	/**
	 * Parse SELECT VERSION() plus large-prefix variables.
	 *
	 * @param string      $version Version string.
	 * @param string|null $large_prefix @@innodb_large_prefix (null when absent).
	 * @param string|null $file_format @@innodb_file_format (null when absent).
	 * @return array
	 */
	public static function server_info( $version, $large_prefix = null, $file_format = null ) {
		$version    = strtolower( trim( (string) $version ) );
		$is_mariadb = false !== strpos( $version, 'mariadb' );
		$numeric    = preg_match( '/([0-9]+(?:\.[0-9]+){1,2})/', $version, $m ) ? $m[1] : '0.0.0';
		$modern     = $is_mariadb ? version_compare( $numeric, '10.2.2', '>=' ) : version_compare( $numeric, '5.7.7', '>=' );
		$lp         = null === $large_prefix ? null : in_array( strtolower( trim( (string) $large_prefix ) ), array( '1', 'on', 'true' ), true );
		$ff         = null === $file_format ? null : strtolower( trim( (string) $file_format ) );
		/* Old MySQL 5.6 / MariaDB < 10.2 rebuild the whole table and block writes for
		 * ALTER CONVERT; newer versions still copy the table (CONVERT is never
		 * in-place) but are much faster. Both need the downtime warning. */
		return array(
			'version'          => substr( $version, 0, 80 ),
			'numeric'          => $numeric,
			'isMariaDb'        => $is_mariadb,
			'modernPrefix'     => $modern,
			'largePrefixOn'    => $modern ? true : (bool) $lp,
			'barracuda'        => $modern ? true : ( null === $ff ? false : 'barracuda' === $ff ),
			'oldServer'        => ! $modern,
		);
	}

	/**
	 * @param string $table Table.
	 * @param string $prefix Site prefix.
	 * @return int 0 = term tables, 1 = Mobo tables, 2 = other.
	 */
	public static function table_priority( $table, $prefix ) {
		$short = 0 === strpos( (string) $table, (string) $prefix ) ? substr( (string) $table, strlen( (string) $prefix ) ) : (string) $table;
		if ( in_array( $short, array( 'terms', 'term_taxonomy', 'termmeta' ), true ) ) {
			return 0;
		}
		if ( 0 === strpos( $short, 'mobo_' ) ) {
			return 1;
		}
		return 2;
	}

	/**
	 * Whether a table needs CONVERT (table default or any text column not utf8mb4).
	 *
	 * @param string $table_collation Table collation.
	 * @param array  $column_charsets Charsets of text columns.
	 * @return bool
	 */
	public static function needs_conversion( $table_collation, $column_charsets ) {
		$collation = strtolower( trim( (string) $table_collation ) );
		if ( '' === $collation || 0 !== strpos( $collation, 'utf8mb4_' ) ) {
			return true;
		}
		foreach ( is_array( $column_charsets ) ? $column_charsets : array() as $charset ) {
			if ( 'utf8mb4' !== strtolower( trim( (string) $charset ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Estimated key bytes per index after conversion.
	 *
	 * @param array $rows information_schema.STATISTICS joined with COLUMNS rows for one table
	 *                    (INDEX_NAME, SUB_PART, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
	 *                    CHARACTER_OCTET_LENGTH, CHARACTER_SET_NAME).
	 * @return array<string,int>
	 */
	public static function index_bytes( $rows ) {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$index = (string) ( isset( $row['INDEX_NAME'] ) ? $row['INDEX_NAME'] : '' );
			if ( ! isset( $out[ $index ] ) ) {
				$out[ $index ] = 0;
			}
			$type = strtolower( (string) ( isset( $row['DATA_TYPE'] ) ? $row['DATA_TYPE'] : '' ) );
			$sub  = absint( isset( $row['SUB_PART'] ) ? $row['SUB_PART'] : 0 );
			if ( '' !== (string) ( isset( $row['CHARACTER_SET_NAME'] ) ? $row['CHARACTER_SET_NAME'] : '' ) ) {
				if ( 'fulltext' === strtolower( (string) ( isset( $row['INDEX_TYPE'] ) ? $row['INDEX_TYPE'] : '' ) ) ) {
					continue; /* FULLTEXT has no byte-prefix limit. */
				}
				$chars = $sub > 0 ? $sub : absint( isset( $row['CHARACTER_MAXIMUM_LENGTH'] ) ? $row['CHARACTER_MAXIMUM_LENGTH'] : 0 );
				$out[ $index ] += $chars * 4;
			} else {
				$octets = absint( isset( $row['CHARACTER_OCTET_LENGTH'] ) ? $row['CHARACTER_OCTET_LENGTH'] : 0 );
				$out[ $index ] += $sub > 0 ? $sub : ( $octets > 0 ? $octets : self::fixed_bytes( $type ) );
			}
		}
		return $out;
	}

	/**
	 * Decide how (or whether) a table can be converted without an index error.
	 *
	 * @param string $engine Engine.
	 * @param string $row_format Row format.
	 * @param int    $max_index_bytes Largest estimated key after conversion.
	 * @param array  $server server_info().
	 * @return array strategy, limit, reason.
	 */
	public static function index_strategy( $engine, $row_format, $max_index_bytes, $server ) {
		$engine     = strtoupper( trim( (string) $engine ) );
		$row_format = strtoupper( trim( (string) $row_format ) );
		$bytes      = absint( $max_index_bytes );
		$server     = is_array( $server ) ? $server : array();
		$large      = ! empty( $server['largePrefixOn'] );
		$dynamic_ok = ! empty( $server['modernPrefix'] ) || ( $large && ! empty( $server['barracuda'] ) );

		if ( 'MYISAM' === $engine ) {
			return $bytes <= 1000
				? array( 'strategy' => self::STRATEGY_CONVERT, 'limit' => 1000, 'reason' => '' )
				: array( 'strategy' => self::STRATEGY_SKIP, 'limit' => 1000, 'reason' => sprintf( 'ایندکس این جدول MyISAM پس از تبدیل %d بایت می‌شود و از سقف 1000 بایت بیشتر است؛ جدول تبدیل نشد. ابتدا طول ایندکس را کوتاه کنید یا جدول را InnoDB کنید.', $bytes ) );
		}
		if ( 'INNODB' !== $engine ) {
			return array( 'strategy' => self::STRATEGY_CONVERT, 'limit' => 3072, 'reason' => '' );
		}

		$is_dynamic = in_array( $row_format, array( 'DYNAMIC', 'COMPRESSED' ), true );
		$limit      = ( $is_dynamic && ( $large || ! empty( $server['modernPrefix'] ) ) ) ? 3072 : 767;
		if ( $bytes <= $limit ) {
			return array( 'strategy' => self::STRATEGY_CONVERT, 'limit' => $limit, 'reason' => '' );
		}
		if ( ! $is_dynamic && $dynamic_ok && $bytes <= 3072 ) {
			return array( 'strategy' => self::STRATEGY_CONVERT_DYNAMIC, 'limit' => 3072, 'reason' => sprintf( 'ایندکس پس از تبدیل %d بایت است (بیش از 767 بایت برای ROW_FORMAT=%s)؛ جدول همراه با ROW_FORMAT=DYNAMIC تبدیل می‌شود.', $bytes, '' !== $row_format ? $row_format : 'COMPACT' ) );
		}
		return array(
			'strategy' => self::STRATEGY_SKIP,
			'limit'    => $limit,
			'reason'   => sprintf( 'ایندکس این جدول پس از تبدیل %1$d بایت می‌شود و از سقف %2$d بایت این سرور بیشتر است؛ برای جلوگیری از خطا/از دست رفتن داده تبدیل نشد. (نیازمند MySQL 5.7.7+/MariaDB 10.2.2+ یا innodb_large_prefix=ON با Barracuda.)', $bytes, $dynamic_ok ? 3072 : $limit ),
		);
	}

	/**
	 * @param int $bytes Data+index bytes.
	 * @param int $rows Approximate rows.
	 * @param array $server server_info().
	 * @return int Seconds (>= 1).
	 */
	public static function estimate_seconds( $bytes, $rows, $server = array() ) {
		$seconds = absint( $bytes ) / self::BYTES_PER_SECOND + absint( $rows ) / 40000;
		if ( ! empty( $server['oldServer'] ) ) {
			$seconds *= 2;
		}
		return max( 1, (int) ceil( $seconds ) );
	}

	/**
	 * Build the ordered plan.
	 *
	 * @param array  $tables TABLES rows (TABLE_NAME, ENGINE, ROW_FORMAT, TABLE_COLLATION, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH).
	 * @param array  $columns COLUMNS rows (TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME).
	 * @param array  $index_rows STATISTICS rows (TABLE_NAME + index_bytes() fields).
	 * @param array  $server server_info().
	 * @param string $prefix Site prefix.
	 * @param array  $excluded Tables to leave out of this run.
	 * @return array entries (ordered list) + alreadyReady (names) + totals.
	 */
	public static function build( $tables, $columns, $index_rows, $server, $prefix, $excluded = array() ) {
		$excluded   = array_fill_keys( array_map( 'strval', is_array( $excluded ) ? $excluded : array() ), true );
		$col_by     = array();
		$bad_by     = array();
		foreach ( is_array( $columns ) ? $columns : array() as $col ) {
			$t       = (string) ( isset( $col['TABLE_NAME'] ) ? $col['TABLE_NAME'] : '' );
			$charset = strtolower( (string) ( isset( $col['CHARACTER_SET_NAME'] ) ? $col['CHARACTER_SET_NAME'] : '' ) );
			if ( '' === $t || '' === $charset ) {
				continue;
			}
			$col_by[ $t ][] = $charset;
			if ( 'utf8mb4' !== $charset && count( isset( $bad_by[ $t ] ) ? $bad_by[ $t ] : array() ) < 12 ) {
				$bad_by[ $t ][] = (string) ( isset( $col['COLUMN_NAME'] ) ? $col['COLUMN_NAME'] : '' ) . ':' . $charset;
			}
		}
		$idx_by = array();
		foreach ( is_array( $index_rows ) ? $index_rows : array() as $row ) {
			$idx_by[ (string) ( isset( $row['TABLE_NAME'] ) ? $row['TABLE_NAME'] : '' ) ][] = $row;
		}

		$entries = array();
		$ready   = array();
		foreach ( is_array( $tables ) ? $tables : array() as $row ) {
			$name = (string) ( isset( $row['TABLE_NAME'] ) ? $row['TABLE_NAME'] : '' );
			if ( '' === $name || 0 !== strpos( $name, (string) $prefix ) ) {
				continue;
			}
			if ( ! self::needs_conversion( isset( $row['TABLE_COLLATION'] ) ? $row['TABLE_COLLATION'] : '', isset( $col_by[ $name ] ) ? $col_by[ $name ] : array() ) ) {
				$ready[] = $name;
				continue;
			}
			if ( isset( $excluded[ $name ] ) ) {
				continue;
			}
			$bytes     = absint( isset( $row['DATA_LENGTH'] ) ? $row['DATA_LENGTH'] : 0 ) + absint( isset( $row['INDEX_LENGTH'] ) ? $row['INDEX_LENGTH'] : 0 );
			$rows      = absint( isset( $row['TABLE_ROWS'] ) ? $row['TABLE_ROWS'] : 0 );
			$idx       = self::index_bytes( isset( $idx_by[ $name ] ) ? $idx_by[ $name ] : array() );
			$max_index = empty( $idx ) ? 0 : max( $idx );
			$decision  = self::index_strategy( isset( $row['ENGINE'] ) ? $row['ENGINE'] : '', isset( $row['ROW_FORMAT'] ) ? $row['ROW_FORMAT'] : '', $max_index, $server );
			$entries[] = array(
				'table'            => $name,
				'priority'         => self::table_priority( $name, $prefix ),
				'bytes'            => $bytes,
				'rows'             => $rows,
				'engine'           => (string) ( isset( $row['ENGINE'] ) ? $row['ENGINE'] : '' ),
				'rowFormat'        => (string) ( isset( $row['ROW_FORMAT'] ) ? $row['ROW_FORMAT'] : '' ),
				'collation'        => (string) ( isset( $row['TABLE_COLLATION'] ) ? $row['TABLE_COLLATION'] : '' ),
				'badColumns'       => isset( $bad_by[ $name ] ) ? $bad_by[ $name ] : array(),
				'maxIndexBytes'    => $max_index,
				'indexLimit'       => $decision['limit'],
				'strategy'         => $decision['strategy'],
				'reason'           => $decision['reason'],
				'estimatedSeconds' => self::estimate_seconds( $bytes, $rows, $server ),
			);
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				if ( $a['priority'] !== $b['priority'] ) {
					return $a['priority'] < $b['priority'] ? -1 : 1;
				}
				if ( $a['bytes'] !== $b['bytes'] ) {
					return $a['bytes'] < $b['bytes'] ? -1 : 1;
				}
				return strcmp( $a['table'], $b['table'] );
			}
		);

		$total_seconds = 0;
		$total_bytes   = 0;
		foreach ( $entries as $entry ) {
			if ( self::STRATEGY_SKIP !== $entry['strategy'] ) {
				$total_seconds += $entry['estimatedSeconds'];
				$total_bytes   += $entry['bytes'];
			}
		}
		return array(
			'entries'               => $entries,
			'alreadyReady'          => $ready,
			'totalEstimatedSeconds' => $total_seconds,
			'totalBytes'            => $total_bytes,
		);
	}

	/** @return int */
	private static function fixed_bytes( $type ) {
		$map = array(
			'tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'integer' => 4, 'bigint' => 8,
			'float' => 4, 'double' => 8, 'real' => 8, 'date' => 3, 'time' => 3, 'timestamp' => 4, 'datetime' => 8,
			'year' => 1, 'bit' => 8, 'decimal' => 16, 'numeric' => 16,
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : 16;
	}
}
