<?php
/**
 * Mark-and-skip support for the 10.34.77 terminal postmeta retirement workers.
 *
 * Before this helper existed, one malformed/ambiguous Product (for example a
 * WooCommerce "Duplicate product" copy that kept product_guid, or two physical
 * rows of the same identity key) parked a whole retirement worker in `blocked`
 * forever. Because the database version is stamped only after retirement, and
 * the real runner used to execute nothing but migration until then, a single bad
 * row froze webhooks, sync and orders on the whole site.
 *
 * The policy implemented here:
 * - Exact duplicate meta rows (same post, same key, byte-identical value) are
 *   safe to collapse: the oldest row (the one WordPress already reads) is kept
 *   and only the redundant copies are deleted.
 * - Anything that cannot be proven safe is MARKED (quarantined) per object and
 *   the worker continues with the rest of the catalog. Marked objects are never
 *   deleted, never mirrored by guesswork, and their legacy postmeta rows are left
 *   physically untouched (excluded from cleanup and from residue proofs).
 * - Objects owned by another Connection are excluded from the Default
 *   Connection's installation-global proofs/cleanup instead of blocking them.
 * - After the database version is stamped, marked objects are re-probed
 *   periodically (and after Repair) and released once their state is consistent.
 *
 * Every count is reported honestly to admins (admin notice + Sync Health tab) and
 * to the Backend through the health payload; nothing is ever stamped as complete
 * to hide a real problem.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Migration_Quarantine {

	/** Marked objects: post_id => entry. autoload=no. */
	const OPTION = 'mobo_core_migration_quarantine_v1';

	/** Per-object consecutive mirror/verify failure counters (transient errors are retried first). */
	const FAILURES_OPTION = 'mobo_core_migration_object_failures_v1';

	/** Compact migration status snapshot written by the budgeted slice; read by admin/health without heavy queries. */
	const STATUS_OPTION = 'mobo_core_migration_status_v1';

	/** Unix time of the next allowed re-probe pass. */
	const REPROBE_OPTION = 'mobo_core_migration_quarantine_next_probe_at';

	/** Hard cap: beyond this the worker keeps its honest `blocked` state instead of growing an unbounded list.
	 * The list is stored in pages of PAGE_SIZE entries (OPTION, OPTION_p1, ...), so a
	 * site with more than a thousand inconsistent objects can still finish retirement,
	 * stamp its DB version and keep receiving hotfixes; entries of deleted posts are
	 * pruned before the cap is ever enforced. */
	const MAX_ITEMS = 20000;

	/** Entries per stored option page. */
	const PAGE_SIZE = 1000;

	/** Upper bound of extra pages scanned when reading (MAX_ITEMS / PAGE_SIZE). */
	const MAX_PAGES = 20;

	/** Option recording attempts that exceeded MAX_ITEMS ("needs support"). */
	const OVERFLOW_OPTION = 'mobo_core_migration_quarantine_overflow_v1';

	/** A successful background run within this window means sync really continues. */
	const RUNTIME_HEALTHY_WINDOW = 600;

	/** Consecutive mirror/verify failures on one object before it is marked instead of retried. */
	const FAILURE_THRESHOLD = 3;

	const REPROBE_INTERVAL = 1800;
	const REPROBE_BATCH    = 25;

	/** @var array|null Request-local cache of the marked list. */
	private static $items = null;

	/** @var array|null Request-local cache of the failure counters. */
	private static $failures = null;

	/** @var bool|null Request-local cache: more than the Default Connection exists. */
	private static $other_connections = null;

	/** @var int|null Request-local cache of the Default Connection id. */
	private static $default_id = null;

	/** @return void */
	public static function reset_request_cache() {
		self::$items = null;
		self::$failures = null;
		self::$other_connections = null;
		self::$default_id = null;
	}

	/* ------------------------------------------------------------------
	 * Marked-object store
	 * ------------------------------------------------------------------ */

	/** @return array<int,array> */
	public static function all() {
		if ( null === self::$items ) {
			self::$items = array();
			$raw = get_option( self::OPTION, array() );
			for ( $page = 0; $page <= self::MAX_PAGES; $page++ ) {
				if ( $page > 0 ) {
					$raw = get_option( self::page_option( $page ), null );
					if ( ! is_array( $raw ) ) { break; }
				}
				if ( is_array( $raw ) ) {
					foreach ( $raw as $post_id => $entry ) {
						$post_id = absint( $post_id );
						if ( $post_id > 0 && is_array( $entry ) ) { self::$items[ $post_id ] = $entry; }
					}
				}
			}
		}
		return self::$items;
	}

	/** @return int */
	public static function count() { return count( self::all() ); }

	/** @return int[] */
	public static function ids() { return array_map( 'absint', array_keys( self::all() ) ); }

	/** @return bool */
	public static function is_marked( $post_id ) {
		$items = self::all();
		return isset( $items[ absint( $post_id ) ] );
	}

	/**
	 * Mark one object so retirement workers skip it and keep its legacy rows.
	 *
	 * @param int    $post_id Woo Product/Variation id.
	 * @param string $worker  operational|identity.
	 * @param string $reason  Stable reason code.
	 * @param string $detail  Optional short diagnostic (meta key, other owner id...).
	 * @return bool False only when the list is full (caller must stay honestly blocked).
	 */
	public static function mark( $post_id, $worker, $reason, $detail = '' ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) { return false; }
		$items = self::all();
		if ( isset( $items[ $post_id ] ) ) { return true; }
		if ( count( $items ) >= self::MAX_ITEMS ) {
			/* Entries of posts that no longer exist are dead weight: prune them first. */
			$items = self::prune_missing_posts( $items );
			if ( count( $items ) >= self::MAX_ITEMS ) {
				self::record_overflow( $post_id, $reason );
				return false;
			}
		}
		$type = function_exists( 'get_post_type' ) ? (string) get_post_type( $post_id ) : '';
		$items[ $post_id ] = array(
			'w'      => sanitize_key( (string) $worker ),
			'r'      => sanitize_key( (string) $reason ),
			'd'      => substr( sanitize_text_field( (string) $detail ), 0, 80 ),
			't'      => sanitize_key( $type ),
			'parent' => 'product_variation' === $type && function_exists( 'wp_get_post_parent_id' ) ? absint( wp_get_post_parent_id( $post_id ) ) : 0,
			'at'     => time(),
			'probes' => 0,
			'probeAt'=> 0,
			'probe'  => '',
		);
		if ( ! self::persist_items( $items ) ) { return false; }
		self::clear_failure( $post_id );
		return true;
	}

	/** @return bool */
	public static function unmark( $post_id ) {
		$post_id = absint( $post_id );
		$items = self::all();
		if ( ! isset( $items[ $post_id ] ) ) { return true; }
		unset( $items[ $post_id ] );
		return self::persist_items( $items );
	}

	/** @return bool */
	private static function persist_items( $items ) {
		ksort( $items );
		self::$items = $items;
		$pages = array_chunk( $items, self::PAGE_SIZE, true );
		$ok    = true;
		if ( empty( $pages ) ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $pages[0], false );
			$check = get_option( self::OPTION, array() );
			$ok    = is_array( $check ) && count( $check ) === count( $pages[0] );
		}
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$name = self::page_option( $page );
			if ( isset( $pages[ $page ] ) ) {
				update_option( $name, $pages[ $page ], false );
				$check = get_option( $name, array() );
				$ok    = $ok && is_array( $check ) && count( $check ) === count( $pages[ $page ] );
			} elseif ( false !== get_option( $name, false ) ) {
				delete_option( $name );
			} else {
				break;
			}
		}
		return $ok;
	}

	/** @return string Option name of one extra list page. */
	private static function page_option( $page ) {
		return self::OPTION . '_p' . absint( $page );
	}

	/**
	 * Drop entries whose post no longer exists (one integer-only query per 500 ids).
	 * On a read error nothing is pruned.
	 *
	 * @param array $items Marked list.
	 * @return array
	 */
	private static function prune_missing_posts( $items ) {
		global $wpdb;
		$existing = array();
		foreach ( array_chunk( array_map( 'absint', array_keys( $items ) ), 500 ) as $chunk ) {
			$chunk = array_values( array_filter( $chunk ) );
			if ( empty( $chunk ) ) { continue; }
			$wpdb->last_error = '';
			$found = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', $chunk ) . ')' );
			if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $found ) ) {
				return $items;
			}
			foreach ( $found as $id ) { $existing[ absint( $id ) ] = true; }
		}
		$kept = self::filter_existing( $items, $existing );
		if ( count( $kept ) !== count( $items ) ) {
			self::persist_items( $kept );
		}
		return $kept;
	}

	/**
	 * Pure helper (unit tested): keep entries whose post id is in $existing.
	 *
	 * @param array $items    Marked list.
	 * @param array $existing Existing post ids => true.
	 * @return array
	 */
	public static function filter_existing( $items, $existing ) {
		$kept = array();
		foreach ( (array) $items as $post_id => $entry ) {
			if ( isset( $existing[ absint( $post_id ) ] ) ) { $kept[ $post_id ] = $entry; }
		}
		return $kept;
	}

	/** Record a refused mark beyond MAX_ITEMS so admins/the Backend see "needs support". @return void */
	private static function record_overflow( $post_id, $reason ) {
		$raw = get_option( self::OVERFLOW_OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		update_option(
			self::OVERFLOW_OPTION,
			array(
				'count'      => absint( $raw['count'] ?? 0 ) + 1,
				'lastPostId' => absint( $post_id ),
				'lastReason' => sanitize_key( (string) $reason ),
				'lastAt'     => time(),
			),
			false
		);
	}

	/** @return array Overflow diagnostics (empty when the list never overflowed). */
	public static function overflow_status() {
		$raw = get_option( self::OVERFLOW_OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Count one more consecutive mirror/verify failure for an object.
	 *
	 * @return bool True when the threshold is reached and the caller should mark the object.
	 */
	public static function record_failure( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) { return false; }
		if ( null === self::$failures ) {
			$raw = get_option( self::FAILURES_OPTION, array() );
			self::$failures = is_array( $raw ) ? $raw : array();
		}
		$count = absint( self::$failures[ $post_id ] ?? 0 ) + 1;
		self::$failures[ $post_id ] = $count;
		/* Keep the counter list small: it only needs the objects currently failing. */
		if ( count( self::$failures ) > 200 ) { self::$failures = array_slice( self::$failures, -200, null, true ); }
		update_option( self::FAILURES_OPTION, self::$failures, false );
		return self::failure_threshold_reached( $count );
	}

	/** Pure decision helper (unit tested). @return bool */
	public static function failure_threshold_reached( $count ) {
		return absint( $count ) >= self::FAILURE_THRESHOLD;
	}

	/** @return void */
	public static function clear_failure( $post_id ) {
		$post_id = absint( $post_id );
		if ( null === self::$failures ) {
			$raw = get_option( self::FAILURES_OPTION, array() );
			self::$failures = is_array( $raw ) ? $raw : array();
		}
		if ( ! isset( self::$failures[ $post_id ] ) ) { return; }
		unset( self::$failures[ $post_id ] );
		if ( empty( self::$failures ) ) { delete_option( self::FAILURES_OPTION ); }
		else { update_option( self::FAILURES_OPTION, self::$failures, false ); }
	}

	/* ------------------------------------------------------------------
	 * SQL exclusion fragments
	 * ------------------------------------------------------------------ */

	/**
	 * Integer-only `AND col NOT IN (...)` fragment for marked objects. Values are
	 * absint()-ed ids, the same integer-only pattern used elsewhere in the plugin;
	 * the fragment contains no '%' so it is safe to embed before $wpdb->prepare().
	 *
	 * @param string $column Trusted, code-owned column expression.
	 * @return string
	 */
	public static function marked_exclusion_sql( $column ) {
		$ids = array_values( array_filter( self::ids() ) );
		if ( empty( $ids ) ) { return ''; }
		return ' AND ' . $column . ' NOT IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')';
	}

	/** @return int */
	public static function default_connection_id() {
		if ( null === self::$default_id ) {
			self::$default_id = 0;
			if ( class_exists( 'Mobo_Core_Connection_Repository' ) ) {
				$default = Mobo_Core_Connection_Repository::get_default();
				if ( ! is_array( $default ) && method_exists( 'Mobo_Core_Connection_Repository', 'get_default_any' ) ) {
					$default = Mobo_Core_Connection_Repository::get_default_any();
				}
				self::$default_id = is_array( $default ) ? absint( $default['id'] ?? 0 ) : 0;
			}
		}
		return (int) self::$default_id;
	}

	/** @return bool */
	public static function other_connections_exist() {
		if ( null === self::$other_connections ) {
			self::$other_connections = false;
			if ( class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists() ) {
				self::$other_connections = count( Mobo_Core_Connection_Repository::list_all() ) > 1;
			}
		}
		return (bool) self::$other_connections;
	}

	/**
	 * Exclude Woo objects durably owned by any non-Default Connection. Legacy
	 * postmeta is installation-global; the Default Connection must neither adopt
	 * nor clean up rows that belong to another Source. Empty on single-Connection
	 * installs, so the common path pays nothing.
	 *
	 * @param string $column Trusted, code-owned post-id column expression.
	 * @return string
	 */
	public static function foreign_exclusion_sql( $column ) {
		global $wpdb;
		if ( ! self::other_connections_exist() ) { return ''; }
		$default_id = self::default_connection_id();
		if ( $default_id <= 0 || ! class_exists( 'Mobo_Core_Product_Map' ) || ! class_exists( 'Mobo_Core_Storage_Schema' ) ) { return ''; }
		$map = Mobo_Core_Product_Map::table_name();
		$pt  = Mobo_Core_Storage_Schema::table_name( 'products' );
		$vt  = Mobo_Core_Storage_Schema::table_name( 'variants' );
		return $wpdb->prepare(
			" AND NOT EXISTS (SELECT 1 FROM {$map} mq_fm WHERE mq_fm.wp_post_id={$column} AND mq_fm.connection_id<>%d)"
			. " AND NOT EXISTS (SELECT 1 FROM {$pt} mq_fp WHERE mq_fp.woo_product_id={$column} AND mq_fp.connection_id<>%d)"
			. " AND NOT EXISTS (SELECT 1 FROM {$vt} mq_fv WHERE mq_fv.woo_variation_id={$column} AND mq_fv.connection_id<>%d)",
			$default_id,
			$default_id,
			$default_id
		);
	}

	/** Both exclusions. @return string */
	public static function exclusion_sql( $column ) {
		return self::marked_exclusion_sql( $column ) . self::foreign_exclusion_sql( $column );
	}

	/**
	 * Whether installation-global retirement may proceed with the current
	 * Connection topology. A lone enabled Default is always fine. With additional
	 * (even disabled) Connections the Default's data may still be retired once the
	 * ownership migration has durably attributed every row, because foreign-owned
	 * objects are then excluded by foreign_exclusion_sql().
	 *
	 * @return bool
	 */
	public static function retirement_topology_allowed() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) ) { return true; }
		if ( Mobo_Core_Connection_Repository::legacy_terminal_retirement_topology_safe() ) { return true; }
		return self::default_connection_id() > 0 && Mobo_Core_Connection_Repository::connection_ownership_ready();
	}

	/* ------------------------------------------------------------------
	 * Exact-duplicate normalization
	 * ------------------------------------------------------------------ */

	/**
	 * Pure classification of the physical rows of one (post, meta_key) pair.
	 * Only byte-identical values (same type, same bytes, NULL kept distinct) are
	 * duplicates. The oldest row (lowest meta_id) is what WordPress reads, so it is
	 * the one kept.
	 *
	 * @param array $rows Rows with meta_id/meta_value.
	 * @return array{action:string,keep:int,remove:int[]}
	 */
	public static function classify_duplicate_rows( $rows ) {
		$out = array( 'action' => 'none', 'keep' => 0, 'remove' => array() );
		$clean = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$id = absint( $row['meta_id'] ?? 0 );
			if ( $id <= 0 ) { continue; }
			$clean[ $id ] = array_key_exists( 'meta_value', $row ) ? $row['meta_value'] : null;
		}
		if ( count( $clean ) < 2 ) { return $out; }
		ksort( $clean );
		$ids = array_keys( $clean );
		$keep = (int) $ids[0];
		$reference = $clean[ $keep ];
		foreach ( $clean as $id => $value ) {
			if ( null === $reference || null === $value ) {
				if ( $reference !== $value ) { $out['action'] = 'conflict'; return $out; }
				continue;
			}
			if ( ! is_scalar( $value ) || ! is_scalar( $reference ) || (string) $value !== (string) $reference ) {
				$out['action'] = 'conflict';
				return $out;
			}
		}
		$out['action'] = 'dedupe';
		$out['keep'] = $keep;
		$out['remove'] = array_values( array_map( 'intval', array_slice( $ids, 1 ) ) );
		return $out;
	}

	/**
	 * Bounded pass: collapse exact duplicates and mark posts whose duplicate rows
	 * disagree. Marked/foreign objects are excluded from discovery.
	 *
	 * @param string[] $keys   Managed meta keys of the calling worker.
	 * @param string   $worker Worker label for marks.
	 * @param int      $limit  Max (post,key) groups this call.
	 * @return array{deduped:int,rowsRemoved:int,marked:int,groups:int,more:bool,error:string,full:bool}
	 */
	public static function dedupe_exact_duplicate_meta( $keys, $worker, $limit = 50 ) {
		global $wpdb;
		$out = array( 'deduped' => 0, 'rowsRemoved' => 0, 'marked' => 0, 'groups' => 0, 'more' => false, 'error' => '', 'full' => false );
		$keys = array_values( array_filter( array_map( 'strval', (array) $keys ) ) );
		if ( empty( $keys ) ) { return $out; }
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$excl = self::exclusion_sql( 'pm.post_id' );
		$wpdb->last_error = '';
		$groups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id,pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
				 WHERE p.post_type IN ('product','product_variation') AND pm.meta_key IN ({$ph}){$excl}
				 GROUP BY pm.post_id,pm.meta_key HAVING COUNT(*)>1 ORDER BY pm.post_id ASC LIMIT %d",
				array_merge( $keys, array( $limit ) )
			),
			ARRAY_A
		);
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $groups ) ) { $out['error'] = 'duplicate_query_failed'; return $out; }
		$out['groups'] = count( $groups );
		$out['more'] = count( $groups ) >= $limit;
		foreach ( $groups as $group ) {
			$post_id = absint( $group['post_id'] ?? 0 );
			$key = (string) ( $group['meta_key'] ?? '' );
			if ( $post_id <= 0 || '' === $key || self::is_marked( $post_id ) ) { continue; }
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id ASC", $post_id, $key ), ARRAY_A );
			if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $rows ) ) { $out['error'] = 'duplicate_rows_read_failed'; return $out; }
			$decision = self::classify_duplicate_rows( $rows );
			if ( 'conflict' === $decision['action'] ) {
				if ( ! self::mark( $post_id, $worker, 'duplicate_meta_conflict', $key ) ) { $out['full'] = true; return $out; }
				$out['marked']++;
				continue;
			}
			if ( 'dedupe' !== $decision['action'] || empty( $decision['remove'] ) ) { continue; }
			$remove = array_map( 'absint', $decision['remove'] );
			$idph = implode( ',', array_fill( 0, count( $remove ), '%d' ) );
			$wpdb->last_error = '';
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s AND meta_id IN ({$idph})", array_merge( array( $post_id, $key ), $remove ) ) );
			if ( false === $deleted || '' !== trim( (string) $wpdb->last_error ) ) { $out['error'] = 'duplicate_delete_failed'; return $out; }
			wp_cache_delete( $post_id, 'post_meta' );
			/* Post-condition: exactly the kept row survives with the same value. */
			$left = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s", $post_id, $key ), ARRAY_A );
			if ( ! is_array( $left ) || 1 !== count( $left ) || absint( $left[0]['meta_id'] ?? 0 ) !== absint( $decision['keep'] ) ) {
				$out['error'] = 'duplicate_postcondition_failed';
				return $out;
			}
			$out['deduped']++;
			$out['rowsRemoved'] += absint( $deleted );
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Re-probe of marked objects (post-stamp only)
	 * ------------------------------------------------------------------ */

	/**
	 * Pure helper: combine per-worker re-probe outcomes. An object is released
	 * only when every terminal worker reports nothing left for it.
	 *
	 * @param string[] $outcomes clear|keep|error per worker.
	 * @return string release|keep|error
	 */
	public static function combine_reprobe_outcomes( $outcomes ) {
		$outcomes = array_values( (array) $outcomes );
		if ( empty( $outcomes ) ) { return 'keep'; }
		if ( in_array( 'error', $outcomes, true ) ) { return 'error'; }
		foreach ( $outcomes as $outcome ) {
			if ( 'clear' !== $outcome ) { return 'keep'; }
		}
		return 'release';
	}

	/** @return bool */
	public static function reprobe_due() {
		if ( self::count() <= 0 ) { return false; }
		if ( MOBO_CORE_VERSION !== trim( (string) get_option( 'mobo_core_db_version', '' ) ) ) { return false; }
		return time() >= absint( get_option( self::REPROBE_OPTION, 0 ) );
	}

	/** Ask for a re-probe on the next runner slice (e.g. after Repair). @return void */
	public static function request_reprobe() {
		if ( self::count() > 0 ) { update_option( self::REPROBE_OPTION, 0, false ); }
	}

	/**
	 * Re-probe a bounded set of marked objects. Must be called only by the
	 * budgeted migration slice (holding the migration lock) after the DB version
	 * is stamped, i.e. after every terminal fence is active. Before that a marked
	 * object must stay marked: unmarking it while a proof is still running could
	 * let cleanup remove rows that were never mirrored.
	 *
	 * @return array
	 */
	public static function run_reprobe_batch( $limit = self::REPROBE_BATCH ) {
		$out = array( 'examined' => 0, 'released' => 0, 'kept' => 0, 'errors' => 0 );
		if ( MOBO_CORE_VERSION !== trim( (string) get_option( 'mobo_core_db_version', '' ) ) ) { return $out; }
		if ( ! class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) || ! class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' ) ) { return $out; }
		if ( ! Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced() || ! Mobo_Core_Storage_Operational_Meta_Retirement::is_fenced() ) { return $out; }
		$items = self::all();
		if ( empty( $items ) ) { return $out; }
		/* Oldest probe first so every object eventually gets its turn. */
		uasort( $items, static function ( $a, $b ) { return absint( $a['probeAt'] ?? 0 ) <=> absint( $b['probeAt'] ?? 0 ); } );
		$batch = array_slice( array_keys( $items ), 0, max( 1, absint( $limit ) ) );
		$default_id = self::default_connection_id();
		$runner = static function ( $post_id ) {
			$identity = Mobo_Core_Storage_Identity_Meta_Retirement::reprobe_quarantined_object( $post_id );
			if ( 'clear' !== $identity ) { return array( $identity ); }
			return array( $identity, Mobo_Core_Storage_Operational_Meta_Retirement::reprobe_quarantined_object( $post_id ) );
		};
		$current = self::all();
		foreach ( $batch as $post_id ) {
			$post_id = absint( $post_id );
			$out['examined']++;
			$outcomes = ( $default_id > 0 && class_exists( 'Mobo_Core_Connection_Context' ) )
				? Mobo_Core_Connection_Context::run( $default_id, static function () use ( $runner, $post_id ) { return $runner( $post_id ); } )
				: $runner( $post_id );
			$decision = self::combine_reprobe_outcomes( is_array( $outcomes ) ? $outcomes : array( 'error' ) );
			if ( 'release' === $decision ) {
				unset( $current[ $post_id ] );
				$out['released']++;
				continue;
			}
			if ( isset( $current[ $post_id ] ) ) {
				$current[ $post_id ]['probes'] = absint( $current[ $post_id ]['probes'] ?? 0 ) + 1;
				$current[ $post_id ]['probeAt'] = time();
				$current[ $post_id ]['probe'] = $decision;
			}
			if ( 'error' === $decision ) { $out['errors']++; } else { $out['kept']++; }
		}
		self::persist_items( $current );
		update_option( self::REPROBE_OPTION, time() + self::REPROBE_INTERVAL, false );
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Runner gating helpers (pure, unit tested)
	 * ------------------------------------------------------------------ */

	/**
	 * Decide what one runner invocation does.
	 *
	 * @param bool $migration_needed needs_budgeted_migration_slice().
	 * @param bool $runtime_ready    Migration::normal_runtime_ready().
	 * @return string normal|shared|migration-only
	 */
	public static function decide_runner_mode( $migration_needed, $runtime_ready ) {
		if ( ! $migration_needed ) { return 'normal'; }
		return $runtime_ready ? 'shared' : 'migration-only';
	}

	/**
	 * Wall-clock share of a runner slice granted to migration when both run in the
	 * same request. Leaves at least ~60% of the slice to normal work.
	 *
	 * @param int $effective_budget Runner effective budget in seconds.
	 * @return int Seconds (>=2 as required by run_budgeted_slice()).
	 */
	public static function shared_migration_budget( $effective_budget ) {
		$effective_budget = absint( $effective_budget );
		return (int) max( 2, min( 8, (int) floor( $effective_budget * 0.4 ) ) );
	}

	/* ------------------------------------------------------------------
	 * Status for admins and the Backend
	 * ------------------------------------------------------------------ */

	/** @return array */
	public static function status_snapshot() {
		$raw = get_option( self::STATUS_OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/** Persist a compact snapshot (called by the budgeted slice). @return void */
	public static function save_status_snapshot( $snapshot ) {
		if ( ! is_array( $snapshot ) ) { return; }
		$snapshot['updatedAt'] = time();
		update_option( self::STATUS_OPTION, $snapshot, false );
	}

	/** @return array<string,string> */
	public static function reason_labels() {
		return array(
			'duplicate_meta_conflict'            => 'چند ردیف از یک فیلد با مقدار متفاوت',
			'identity_conflict'                  => 'شناسه موبوی ناسازگار',
			'malformed_identity'                 => 'شناسه موبوی نامعتبر',
			'missing_identity'                   => 'شناسه محصول موجود نیست',
			'parent_identity_missing'            => 'شناسه محصول مادر موجود نیست',
			'parent_identity_conflict'           => 'شناسه محصول مادر ناسازگار است',
			'parent_quarantined'                 => 'محصول مادر علامت‌گذاری شده است',
			'identity_owned_by_other_object'     => 'این شناسه متعلق به محصول دیگری است (مثلاً محصول کپی‌شده)',
			'identity_duplicate'                 => 'چند محصول با یک شناسه موبو',
			'map_conflict'                       => 'تعارض در نگاشت محصول',
			'autodraft_with_identity'            => 'پیش‌نویس خودکار دارای شناسه موبو',
			'invalid_map_guid'                   => 'شناسه نامعتبر در جدول نگاشت',
			'mirror_failed'                      => 'انتقال داده پس از چند تلاش ناموفق ماند',
			'duplicate_map_object'               => 'نگاشت تکراری برای یک محصول',
			'custom_coverage_missing'            => 'رکورد ذخیره‌سازی جدید برای محصول یافت نشد',
			'alias_conflict'                     => 'مقادیر متفاوت در کلیدهای هم‌معنای شناسه',
		);
	}

	/** @return string */
	public static function reason_label( $reason ) {
		$labels = self::reason_labels();
		$reason = sanitize_key( (string) $reason );
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
	}

	/**
	 * Health payload section for the Backend. Reads only options (no catalog scan).
	 *
	 * @return array
	 */
	public static function health_payload() {
		$items = self::all();
		$list = array();
		foreach ( array_slice( $items, 0, 100, true ) as $post_id => $entry ) {
			$list[] = array(
				'postId'      => absint( $post_id ),
				'postType'    => sanitize_key( (string) ( $entry['t'] ?? '' ) ),
				'parentId'    => absint( $entry['parent'] ?? 0 ),
				'worker'      => sanitize_key( (string) ( $entry['w'] ?? '' ) ),
				'reason'      => sanitize_key( (string) ( $entry['r'] ?? '' ) ),
				'detail'      => sanitize_text_field( (string) ( $entry['d'] ?? '' ) ),
				'markedAt'    => absint( $entry['at'] ?? 0 ),
				'probes'      => absint( $entry['probes'] ?? 0 ),
				'lastProbeAt' => absint( $entry['probeAt'] ?? 0 ),
				'lastProbe'   => sanitize_key( (string) ( $entry['probe'] ?? '' ) ),
			);
		}
		$snapshot = self::status_snapshot();
		$db_version = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		return array(
			'targetVersion'      => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
			'databaseVersion'    => $db_version,
			'stamped'            => defined( 'MOBO_CORE_VERSION' ) && MOBO_CORE_VERSION === $db_version,
			'snapshot'           => $snapshot,
			'markedCount'        => count( $items ),
			'markedCapacity'     => self::MAX_ITEMS,
			'marked'             => $list,
			'markedListTruncated'=> count( $items ) > count( $list ),
			'markedOverflow'     => self::overflow_status(),
			'nextReprobeAt'      => absint( get_option( self::REPROBE_OPTION, 0 ) ),
		);
	}

	/**
	 * Pure classification (unit tested) of background activity for the notice.
	 *
	 * @param int   $last_success Latest successful runner/self-runner run (unix).
	 * @param array $lease        Dispatcher lease status (Mobo_Core_Lock::get_status()).
	 * @param int   $now          Unix time.
	 * @return array{state:string,lockRemaining:int}
	 */
	public static function classify_runtime_activity( $last_success, $lease, $now ) {
		$last_success = absint( $last_success );
		$now          = absint( $now );
		if ( $last_success > 0 && $last_success <= $now + 60 && ( $now - $last_success ) <= self::RUNTIME_HEALTHY_WINDOW ) {
			return array( 'state' => 'healthy', 'lockRemaining' => 0 );
		}
		if ( is_array( $lease ) && ! empty( $lease['active'] ) ) {
			return array( 'state' => 'locked', 'lockRemaining' => absint( $lease['remainingSeconds'] ?? 0 ) );
		}
		return array( 'state' => 'idle', 'lockRemaining' => 0 );
	}

	/** @return array{state:string,lockRemaining:int} Live background activity. */
	public static function runtime_activity_state() {
		$last_success = max(
			absint( get_option( 'mobo_core_real_cron_last_success_at', 0 ) ),
			absint( get_option( 'mobo_core_self_runner_last_run_success_at', 0 ) )
		);
		$lease = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_status( 'worker_dispatcher' ) : array();
		return self::classify_runtime_activity( $last_success, $lease, time() );
	}

	/**
	 * Admin notice (manage_options only). Session-dismissible via sessionStorage;
	 * the dismissal key includes the current state, so a new problem re-appears.
	 *
	 * @return void
	 */
	public static function render_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$db_version = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		$stamped = MOBO_CORE_VERSION === $db_version;
		$snapshot = self::status_snapshot();
		$marked = self::count();
		$workers = isset( $snapshot['workers'] ) && is_array( $snapshot['workers'] ) ? $snapshot['workers'] : array();
		$complete = $stamped && ! empty( $snapshot['retirementComplete'] );
		if ( $stamped && ! $complete && class_exists( 'Mobo_Core_Migration' ) && ! Mobo_Core_Migration::new_connections_blocked() ) {
			/* The snapshot can predate completion (e.g. the final pass ran on an
			 * ordinary request). Confirm live once and remember the result. */
			$complete = true;
			$snapshot['retirementComplete'] = true;
			self::save_status_snapshot( $snapshot );
		}
		if ( $complete && 0 === $marked ) { return; }
		if ( ! $stamped && class_exists( 'Mobo_Core_Migration' ) ) {
			/* Live (options + cached schema) so the notice is right even before the
			 * runner has written its first snapshot. */
			$live_reason = '';
			$snapshot['runtimeReady'] = Mobo_Core_Migration::normal_runtime_ready( $live_reason );
			$snapshot['runtimeReason'] = $live_reason;
		}

		$lines = array();
		$class = 'notice-info';
		if ( ! $stamped ) {
			$runtime_ready = ! empty( $snapshot['runtimeReady'] );
			if ( $runtime_ready ) {
				/* Claim "continues normally" only when a background run really succeeded
				 * recently; otherwise say what is actually going on (lease held / no run). */
				$activity = self::runtime_activity_state();
				if ( 'healthy' === $activity['state'] ) {
					$lines[] = 'موبوکور: انتقال داده‌ها به ساختار ذخیره‌سازی جدید در پس‌زمینه در حال انجام است. همگام‌سازی، وب‌هوک‌ها و سفارش‌ها به‌طور عادی ادامه دارند.';
				} elseif ( 'locked' === $activity['state'] ) {
					$class = 'notice-warning';
					$lines[] = sprintf( 'موبوکور: انتقال داده‌ها در پس‌زمینه در حال انجام است، اما در چند دقیقه اخیر هیچ اجرای موفق پس‌زمینه ثبت نشده و قفل اجرای کار (worker) هنوز فعال است (حداکثر %d ثانیه دیگر آزاد می‌شود). همگام‌سازی، وب‌هوک‌ها و سفارش‌ها پس از آزاد شدن قفل ادامه می‌یابند.', absint( $activity['lockRemaining'] ) );
				} else {
					$class = 'notice-warning';
					$lines[] = 'موبوکور: انتقال داده‌ها به ساختار جدید در انتظار اجرای پس‌زمینه است؛ در چند دقیقه اخیر هیچ اجرای موفق Cron ثبت نشده است. همگام‌سازی، وب‌هوک‌ها و سفارش‌ها تا اجرای بعدی Cron منتظر می‌مانند. تنظیم Cron سایت را بررسی کنید.';
				}
			} else {
				$class = 'notice-warning';
				$reason = sanitize_key( (string) ( $snapshot['runtimeReason'] ?? '' ) );
				$lines[] = 'موبوکور: پیش‌نیازهای به‌روزرسانی پایگاه داده هنوز آماده نیست و تا آماده شدن آن فقط مهاجرت اجرا می‌شود.' . ( '' !== $reason ? ' علت: ' . $reason : '' );
				$schema_error = sanitize_text_field( (string) get_option( 'mobo_core_schema_last_error', '' ) );
				if ( '' !== $schema_error ) { $lines[] = 'آخرین خطای ساختار پایگاه داده: ' . $schema_error; }
			}
		} elseif ( ! $complete ) {
			$lines[] = 'موبوکور: پایگاه داده به‌روز شده است؛ پاک‌سازی داده‌های قدیمی در پس‌زمینه ادامه دارد و مانع همگام‌سازی نیست.';
		}
		$labels = array( 'operational' => 'وضعیت عملیاتی', 'identity' => 'شناسه‌ها', 'entity' => 'سایر فیلدها' );
		$parts = array();
		foreach ( $labels as $key => $label ) {
			if ( ! isset( $workers[ $key ] ) || ! is_array( $workers[ $key ] ) ) { continue; }
			$w = $workers[ $key ];
			$state = ! empty( $w['complete'] ) ? 'کامل' : sanitize_key( (string) ( $w['status'] ?? '' ) );
			$parts[] = $label . ': ' . $state;
		}
		if ( ! empty( $parts ) && ( ! $stamped || ! $complete ) ) { $lines[] = 'مراحل: ' . implode( '، ', $parts ); }
		if ( $marked > 0 ) {
			$class = 'notice-warning';
			$lines[] = sprintf( '%d محصول/متغیر به دلیل داده‌های ناسازگار علامت‌گذاری شد و از انتقال خودکار کنار گذاشته شد. هیچ محصولی حذف نشده است؛ داده‌های قدیمی این موارد دست‌نخورده نگه داشته شده‌اند. فهرست در تب «سلامت همگام‌سازی» است و پس از رفع مشکل (مثلاً حذف محصول کپی‌شده یا اجرای Repair) خودکار دوباره بررسی می‌شوند.', $marked );
		}
		$overflow = self::overflow_status();
		if ( absint( $overflow['count'] ?? 0 ) > 0 && ! $complete ) {
			$class = 'notice-error';
			$lines[] = sprintf( 'موبوکور: تعداد موارد ناسازگار از ظرفیت %d عبور کرد و انتقال خودکار متوقف ماند. لطفاً با پشتیبانی تماس بگیرید؛ هیچ داده‌ای حذف نشده است.', self::MAX_ITEMS );
		}
		if ( empty( $lines ) ) { return; }
		$key = 'mobo_mig_notice_' . substr( md5( wp_json_encode( array( $db_version, $marked, $complete, $snapshot['runtimeReady'] ?? null ) ) ), 0, 12 );
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible mobo-migration-notice" id="' . esc_attr( $key ) . '" dir="rtl">';
		foreach ( $lines as $line ) { echo '<p>' . esc_html( $line ) . '</p>'; }
		echo '</div>';
		/* Per-session dismissal; purely cosmetic, so storage failures are ignored. */
		echo '<script>(function(){var k=' . wp_json_encode( $key ) . ',n=document.getElementById(k);if(!n){return;}try{if(window.sessionStorage&&sessionStorage.getItem(k)){n.style.display="none";}}catch(e){}n.addEventListener("click",function(ev){if(ev.target&&ev.target.classList&&ev.target.classList.contains("notice-dismiss")){try{sessionStorage.setItem(k,"1");}catch(e){}}});})();</script>';
	}

	/**
	 * Small list for the Sync Health tab.
	 *
	 * @param int $limit Rows to show.
	 * @return void
	 */
	public static function render_admin_list( $limit = 50 ) {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$items = self::all();
		$snapshot = self::status_snapshot();
		$db_version = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		echo '<div class="mobo-card"><div class="mobo-admin-card-heading"><div><h2 class="mobo-admin-section-title">انتقال داده به ساختار جدید</h2>';
		echo '<p>' . esc_html( MOBO_CORE_VERSION === $db_version ? 'پایگاه داده به‌روز است.' : 'به‌روزرسانی پایگاه داده در حال انجام است.' ) . ' ';
		echo esc_html( sprintf( 'موارد علامت‌گذاری‌شده: %d', count( $items ) ) ) . '</p></div></div>';
		if ( ! empty( $snapshot['updatedAt'] ) && class_exists( 'Mobo_Core_Iran_Date' ) ) {
			echo '<p class="description">' . esc_html( 'آخرین به‌روزرسانی وضعیت: ' . Mobo_Core_Iran_Date::format( 'Y-m-d H:i:s', absint( $snapshot['updatedAt'] ) ) ) . '</p>';
		}
		if ( empty( $items ) ) { echo '<p>موردی علامت‌گذاری نشده است.</p></div>'; return; }
		echo '<p>این محصولات حذف نشده‌اند و فروشگاه به کار خود ادامه می‌دهد. پس از رفع مشکل (حذف محصول کپی‌شده، اصلاح شناسه یا اجرای Repair) به‌صورت خودکار دوباره بررسی می‌شوند.</p>';
		echo '<table class="widefat striped"><thead><tr><th>شناسه</th><th>عنوان</th><th>نوع</th><th>علت</th><th>زمان</th></tr></thead><tbody>';
		foreach ( array_slice( $items, 0, max( 1, absint( $limit ) ), true ) as $post_id => $entry ) {
			$post_id = absint( $post_id );
			$title = function_exists( 'get_the_title' ) ? get_the_title( $post_id ) : '';
			$edit_id = 'product_variation' === ( $entry['t'] ?? '' ) && ! empty( $entry['parent'] ) ? absint( $entry['parent'] ) : $post_id;
			$link = function_exists( 'get_edit_post_link' ) ? get_edit_post_link( $edit_id, 'raw' ) : '';
			$at = absint( $entry['at'] ?? 0 );
			echo '<tr><td>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( (string) $post_id ) . '</a>' : esc_html( (string) $post_id ) ) . '</td>';
			echo '<td>' . esc_html( (string) $title ) . '</td>';
			echo '<td>' . esc_html( 'product_variation' === ( $entry['t'] ?? '' ) ? 'متغیر' : 'محصول' ) . '</td>';
			echo '<td>' . esc_html( self::reason_label( $entry['r'] ?? '' ) . ( ! empty( $entry['d'] ) ? ' (' . $entry['d'] . ')' : '' ) ) . '</td>';
			echo '<td>' . esc_html( $at > 0 && class_exists( 'Mobo_Core_Iran_Date' ) ? Mobo_Core_Iran_Date::format( 'Y-m-d H:i', $at ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( count( $items ) > $limit ) { echo '<p class="description">' . esc_html( sprintf( '%d مورد دیگر در گزارش سلامت ارسالی به سرویس مرکزی موجود است.', count( $items ) - $limit ) ) . '</p>'; }
		echo '</div>';
	}
}
