<?php
/**
 * SQLite-backed $wpdb for standalone queue tests (no MySQL, no WordPress).
 *
 * Executes the plugin's real SQL against an in-memory SQLite database after a
 * few MySQL -> SQLite rewrites (IF(), GREATEST(), BINARY, UPDATE ... LIMIT,
 * SHOW TABLES LIKE). Failure injection: set $wpdb->fail_pattern to a regex;
 * the next matching statement returns false with a MySQL "disk full" error.
 *
 * PHP 7.4 compatible.
 */

final class Sqlite_Test_WPDB {
	public $prefix     = 'wp_';
	public $posts      = 'wp_posts';
	public $postmeta   = 'wp_postmeta';
	public $options    = 'wp_options';
	public $last_error = '';
	public $insert_id  = 0;
	public $queries    = array();
	/** @var string Regex; matching statements fail (sticky until cleared). */
	public $fail_pattern = '';
	/** @var PDO */
	public $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
		$this->pdo->sqliteCreateFunction( 'mobo_if', static function ( $c, $a, $b ) { return $c ? $a : $b; }, 3 );
		$this->pdo->sqliteCreateFunction( 'GREATEST', static function () { return max( func_get_args() ); } );
		$this->pdo->sqliteCreateFunction( 'FIND_IN_SET', static function ( $needle, $list ) { $i = array_search( (string) $needle, explode( ',', (string) $list ), true ); return false === $i ? 0 : $i + 1; }, 2 );
		$this->pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT DEFAULT \'post\', post_status TEXT DEFAULT \'publish\', post_content TEXT DEFAULT \'\', post_excerpt TEXT DEFAULT \'\', guid TEXT DEFAULT \'\')' );
		$this->pdo->exec( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT, option_value TEXT)' );
	}

	public function esc_like( $text ) { return (string) $text; }

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i   = 0;
		$pdo = $this->pdo;
		return preg_replace_callback(
			'/%%|%d|%s|%f/',
			static function ( $m ) use ( &$i, $args, $pdo ) {
				if ( '%%' === $m[0] ) {
					return '%';
				}
				$v = array_key_exists( $i, $args ) ? $args[ $i ] : '';
				$i++;
				if ( '%d' === $m[0] ) {
					return (string) (int) $v;
				}
				if ( '%f' === $m[0] ) {
					return (string) (float) $v;
				}
				return $pdo->quote( (string) $v );
			},
			$query
		);
	}

	private function translate( $sql ) {
		$sql = preg_replace( '/\bIF\s*\(/i', 'mobo_if(', $sql );
		$sql = preg_replace( '/\bBINARY\s+/i', '', $sql );
		$sql = preg_replace( '/^\s*INSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $sql );
		if ( preg_match( '/^\s*(UPDATE|DELETE)\b/i', $sql ) ) {
			$sql = preg_replace( '/\s+ORDER BY\s+[^)]*?(\s+LIMIT\s+\d+)?\s*$/i', '', $sql );
			$sql = preg_replace( '/\s+LIMIT\s+\d+\s*$/i', '', $sql );
		}
		return $sql;
	}

	private function fails( $sql ) {
		$this->queries[] = $sql;
		$this->last_error = '';
		if ( '' !== $this->fail_pattern && preg_match( $this->fail_pattern, $sql ) ) {
			$this->last_error = 'The table is full / Disk full (errno: 28)';
			return true;
		}
		return false;
	}

	public function query( $sql ) {
		if ( $this->fails( $sql ) ) {
			return false;
		}
		$sql = $this->translate( $sql );
		if ( preg_match( '/^\s*SELECT/i', $sql ) ) {
			$st = $this->pdo->query( $sql );
			return $st ? count( $st->fetchAll() ) : false;
		}
		$n = $this->pdo->exec( $sql );
		if ( false === $n ) {
			$err = $this->pdo->errorInfo();
			$this->last_error = (string) $err[2];
			return false;
		}
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return $n;
	}

	private function select( $sql ) {
		if ( $this->fails( $sql ) ) {
			return null;
		}
		if ( preg_match( "/^\s*SHOW TABLES LIKE '(.*)'\s*$/i", $sql, $m ) ) {
			$st = $this->pdo->query( "SELECT name FROM sqlite_master WHERE type='table' AND name=" . $this->pdo->quote( stripslashes( $m[1] ) ) );
			return $st ? $st->fetchAll( PDO::FETCH_ASSOC ) : array();
		}
		$st = $this->pdo->query( $this->translate( $sql ) );
		if ( ! $st ) {
			$err = $this->pdo->errorInfo();
			$this->last_error = (string) $err[2];
			return null;
		}
		return $st->fetchAll( PDO::FETCH_ASSOC );
	}

	public function get_results( $sql, $output = 'OBJECT' ) {
		$rows = $this->select( $sql );
		if ( null === $rows ) {
			return null;
		}
		return 'ARRAY_A' === $output ? $rows : array_map( static function ( $r ) { return (object) $r; }, $rows );
	}

	public function get_row( $sql, $output = 'OBJECT' ) {
		$rows = $this->get_results( $sql, $output );
		return is_array( $rows ) && isset( $rows[0] ) ? $rows[0] : null;
	}

	public function get_col( $sql ) {
		$rows = $this->select( $sql );
		if ( null === $rows ) {
			return array();
		}
		return array_map( static function ( $r ) { return reset( $r ); }, $rows );
	}

	public function get_var( $sql ) {
		$rows = $this->select( $sql );
		if ( empty( $rows ) ) {
			return null;
		}
		$first = reset( $rows );
		return reset( $first );
	}

	public function insert( $table, $data ) {
		$cols = array_keys( $data );
		$vals = array();
		foreach ( $data as $v ) {
			$vals[] = null === $v ? 'NULL' : $this->pdo->quote( (string) $v );
		}
		return $this->query( 'INSERT INTO ' . $table . ' (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ')' );
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$sets = array();
		foreach ( $data as $k => $v ) {
			$sets[] = $k . ' = ' . ( null === $v ? 'NULL' : $this->pdo->quote( (string) $v ) );
		}
		$conds = array();
		foreach ( $where as $k => $v ) {
			$conds[] = $k . ' = ' . $this->pdo->quote( (string) $v );
		}
		return $this->query( 'UPDATE ' . $table . ' SET ' . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $conds ) );
	}

	public function delete( $table, $where ) {
		$conds = array();
		foreach ( $where as $k => $v ) {
			$conds[] = $k . ' = ' . $this->pdo->quote( (string) $v );
		}
		return $this->query( 'DELETE FROM ' . $table . ' WHERE ' . implode( ' AND ', $conds ) );
	}

	/** Test helper: one row as array. */
	public function row( $table, $id, $pk = 'id' ) {
		$st = $this->pdo->query( 'SELECT * FROM ' . $table . ' WHERE ' . $pk . ' = ' . (int) $id );
		$r  = $st ? $st->fetch( PDO::FETCH_ASSOC ) : false;
		return is_array( $r ) ? $r : array();
	}
}
