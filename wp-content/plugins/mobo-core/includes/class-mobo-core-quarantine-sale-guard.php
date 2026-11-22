<?php
/**
 * M8: quarantined non-canonical product copies must not sell with a frozen price.
 *
 * A WooCommerce "Duplicate product" copy (or any second object carrying another
 * object's Mobo identity) is marked by the terminal identity retirement with
 * reason identity_owned_by_other_object ("#<owner id>"). Sync never updates it
 * again (the canonical owner receives every update), so its price and stock
 * freeze while it stayed purchasable.
 *
 * Policy (owner contract):
 * - A quarantined non-canonical copy that is live (publish/private/pending/future)
 *   is taken off sale: flagged, persisted out of stock (product + variations, via
 *   the Source-visibility overlay so DB/lookup table/feeds/REST agree), hidden from
 *   catalog and search. Its URL stays; nothing is ever deleted.
 * - Trashing it or moving it to draft releases the quarantine cleanly (after the
 *   database version is stamped, i.e. when every terminal fence is active): the
 *   object and its marked variations are unmarked, the copy stays out of stock and
 *   hidden (no stale stock is ever restored), and the late-residue pass of the
 *   identity retirement may then remove its duplicate legacy identity rows.
 * - If the migration re-probe itself releases a copy (it became the consistent
 *   owner), the catalog visibility is restored but the persisted out-of-stock
 *   overlay is kept until the next Source update writes fresh stock and price.
 * - Orders that already contain such items keep going to manual review (M4).
 *
 * The guard only uses the public Mobo_Core_Migration_Quarantine API and reacts to
 * changes of its stored list, so the retirement workers are not modified.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Quarantine_Sale_Guard {

	/** Same key as Mobo_Core_Source_Visibility::META_QUARANTINE_BLOCKED. */
	const META_BLOCKED = '_mobo_quarantine_sale_blocked';

	const META_PREV_VISIBILITY = '_mobo_quarantine_prev_visibility';

	/** Audit marker: why/when the guard released the quarantine (e.g. "off_sale:trash@..."). */
	const META_RELEASED = '_mobo_quarantine_released';

	/** Objects this guard took off sale: post_id => blocked-at. autoload=no. */
	const REGISTRY_OPTION = 'mobo_core_quarantine_sale_guard_v1';

	/** Quarantine reasons that prove "this object is a non-canonical copy". */
	const COPY_REASONS = array( 'identity_owned_by_other_object' );

	const BATCH = 25;

	/** Upper bound of copy entries inspected per reconcile pass (post status + flag reads). */
	const MAX_SCAN = 500;

	/** @var bool */
	private static $scheduled = false;

	/* ------------------------------------------------------------------
	 * Pure helpers (unit tested)
	 * ------------------------------------------------------------------ */

	/** @return bool */
	public static function is_copy_entry( $entry ) {
		return is_array( $entry ) && in_array( sanitize_key( (string) ( $entry['r'] ?? '' ) ), self::COPY_REASONS, true );
	}

	/** @return int Canonical owner id recorded by the identity proof ("#123"), 0 when unknown. */
	public static function owner_id( $entry ) {
		$detail = is_array( $entry ) ? trim( (string) ( $entry['d'] ?? '' ) ) : '';
		return 1 === preg_match( '/^#([1-9][0-9]*)$/', $detail, $m ) ? absint( $m[1] ) : 0;
	}

	/**
	 * Decide what to do with one marked object.
	 *
	 * @param array  $entry Quarantine entry.
	 * @param string $post_status Current post status ('' when missing).
	 * @param bool   $flagged Already taken off sale by this guard.
	 * @param bool   $stamped Database version stamped (terminal fences active).
	 * @return string block|release|none
	 */
	public static function decide( $entry, $post_status, $flagged, $stamped ) {
		if ( ! self::is_copy_entry( $entry ) ) {
			return 'none';
		}
		$post_status = sanitize_key( (string) $post_status );
		if ( in_array( $post_status, array( 'publish', 'private', 'pending', 'future' ), true ) ) {
			return $flagged ? 'none' : 'block';
		}
		if ( in_array( $post_status, array( 'draft', 'trash' ), true ) ) {
			return $stamped ? 'release' : 'none';
		}
		return 'none';
	}

	/* ------------------------------------------------------------------
	 * Runtime
	 * ------------------------------------------------------------------ */

	/** @return bool */
	public static function stamped() {
		return defined( 'MOBO_CORE_VERSION' ) && MOBO_CORE_VERSION === trim( (string) get_option( 'mobo_core_db_version', '' ) );
	}

	/** @return bool */
	public static function is_sale_blocked( $post_id ) {
		return '1' === (string) get_post_meta( absint( $post_id ), self::META_BLOCKED, true );
	}

	/** Reconcile once at shutdown after the quarantine list changed. @return void */
	public static function schedule_reconcile() {
		if ( self::$scheduled || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$scheduled = true;
		add_action( 'shutdown', array( __CLASS__, 'reconcile_at_shutdown' ), 5 );
	}

	/** @return void */
	public static function reconcile_at_shutdown() {
		try {
			if ( class_exists( 'Mobo_Core_Migration_Quarantine' ) ) {
				Mobo_Core_Migration_Quarantine::reset_request_cache();
			}
			self::reconcile( self::BATCH );
		} catch ( Throwable $e ) {
			if ( class_exists( 'Mobo_Core_Logger' ) ) {
				Mobo_Core_Logger::warning( 'Quarantine sale guard reconcile failed; it will retry on the next quarantine change or dashboard view.', array( 'error' => $e->getMessage() ) );
			}
		}
	}

	/**
	 * Bring every quarantined copy to its policy state (bounded).
	 *
	 * @param int $limit Max block/release/restore operations.
	 * @return array{blocked:int,released:int,restored:int}
	 */
	public static function reconcile( $limit = self::BATCH ) {
		$out = array( 'blocked' => 0, 'released' => 0, 'restored' => 0 );
		if ( ! class_exists( 'Mobo_Core_Migration_Quarantine' ) || ! function_exists( 'get_post_status' ) ) {
			return $out;
		}
		$limit    = max( 1, absint( $limit ) );
		$done     = 0;
		$stamped  = self::stamped();
		$items    = Mobo_Core_Migration_Quarantine::all();
		$scanned  = 0;
		foreach ( $items as $post_id => $entry ) {
			if ( $done >= $limit || $scanned >= self::MAX_SCAN ) {
				break;
			}
			/* The list can hold up to 20k marks of other reasons: touch only copies. */
			if ( ! self::is_copy_entry( $entry ) ) {
				continue;
			}
			$scanned++;
			$post_id  = absint( $post_id );
			$decision = self::decide( $entry, (string) get_post_status( $post_id ), self::is_sale_blocked( $post_id ), $stamped );
			if ( 'block' === $decision ) {
				if ( self::block( $post_id ) ) { $out['blocked']++; }
				$done++;
			} elseif ( 'release' === $decision ) {
				if ( self::release_off_sale( $post_id, (string) get_post_status( $post_id ) ) ) { $out['released']++; }
				$done++;
			}
		}

		/* Objects this guard blocked that are no longer marked. */
		$items    = Mobo_Core_Migration_Quarantine::all();
		$registry = self::registry();
		foreach ( array_keys( $registry ) as $post_id ) {
			if ( $done >= $limit ) {
				break;
			}
			$post_id = absint( $post_id );
			if ( isset( $items[ $post_id ] ) ) {
				continue;
			}
			if ( '' === (string) get_post_meta( $post_id, self::META_RELEASED, true ) && self::is_sale_blocked( $post_id ) ) {
				/* The re-probe released it: it is the consistent owner now. */
				if ( self::restore_after_reprobe( $post_id ) ) { $out['restored']++; }
				$done++;
			}
			unset( $registry[ $post_id ] );
		}
		self::save_registry( $registry );
		return $out;
	}

	/**
	 * Take one copy off sale (idempotent). Never changes post_status.
	 *
	 * @param int $post_id Product or variation id.
	 * @return bool Whether the object is now flagged.
	 */
	public static function block( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$is_variation = method_exists( $product, 'is_type' ) && $product->is_type( 'variation' );
		if ( ! self::is_sale_blocked( $post_id ) ) {
			if ( ! $is_variation ) {
				$current = (string) $product->get_catalog_visibility( 'edit' );
				update_post_meta( $post_id, self::META_PREV_VISIBILITY, '' !== $current ? $current : 'visible' );
			}
			update_post_meta( $post_id, self::META_BLOCKED, '1' );
			delete_post_meta( $post_id, self::META_RELEASED );
			$registry = self::registry();
			$registry[ $post_id ] = time();
			self::save_registry( $registry );
			$product = wc_get_product( $post_id );
			if ( ! $product instanceof WC_Product ) {
				return true;
			}
		}
		if ( ! $is_variation && 'hidden' !== (string) $product->get_catalog_visibility( 'edit' ) ) {
			$product->set_catalog_visibility( 'hidden' );
			$product->save();
		}
		if ( class_exists( 'Mobo_Core_Source_Visibility' ) ) {
			Mobo_Core_Source_Visibility::sync_persisted_stock( $post_id, ! $is_variation );
		}
		self::log( 'Quarantined duplicate copy taken off sale (out of stock, not purchasable, hidden from catalog; URL kept, nothing deleted).', array( 'postId' => $post_id ) );
		return true;
	}

	/**
	 * Trash/draft release: unmark the copy (and its marked variations), keep it out
	 * of stock and hidden, drop the overlay bookkeeping so no stale stock can ever
	 * be restored. Only after the database version is stamped.
	 *
	 * @param int    $post_id Product or variation id.
	 * @param string $status New post status.
	 * @return bool
	 */
	public static function release_off_sale( $post_id, $status ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! self::stamped() || ! class_exists( 'Mobo_Core_Migration_Quarantine' ) ) {
			return false;
		}
		$items = Mobo_Core_Migration_Quarantine::all();
		if ( ! isset( $items[ $post_id ] ) || ! self::is_copy_entry( $items[ $post_id ] ) ) {
			return false;
		}
		$targets = array( $post_id );
		foreach ( $items as $id => $entry ) {
			if ( absint( $entry['parent'] ?? 0 ) === $post_id ) {
				$targets[] = absint( $id );
			}
		}
		$marker = 'off_sale:' . sanitize_key( (string) $status ) . '@' . time();
		foreach ( $targets as $id ) {
			update_post_meta( $id, self::META_RELEASED, $marker );
		}
		$ok = true;
		foreach ( $targets as $id ) {
			$ok = Mobo_Core_Migration_Quarantine::unmark( $id ) && $ok;
		}
		foreach ( $targets as $id ) {
			self::drop_flag_keep_off_sale( $id );
		}
		$registry = self::registry();
		foreach ( $targets as $id ) {
			unset( $registry[ $id ] );
		}
		self::save_registry( $registry );
		self::log( 'Quarantined duplicate copy released after it was moved to ' . sanitize_key( (string) $status ) . '; it stays out of stock and hidden.', array( 'postId' => $post_id, 'released' => $targets ) );
		return $ok;
	}

	/**
	 * The migration re-probe released a flagged object (now the consistent owner):
	 * restore catalog visibility; keep the persisted out-of-stock overlay so the next
	 * Source update writes fresh stock and price together.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function restore_after_reprobe( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		delete_post_meta( $post_id, self::META_BLOCKED );
		$product = wc_get_product( $post_id );
		if ( $product instanceof WC_Product && ! ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) ) {
			$previous = sanitize_key( (string) get_post_meta( $post_id, self::META_PREV_VISIBILITY, true ) );
			$previous = in_array( $previous, array( 'visible', 'catalog', 'search', 'hidden' ), true ) ? $previous : 'visible';
			$source_hidden = class_exists( 'Mobo_Core_Source_Visibility' ) && '1' === (string) get_post_meta( $post_id, Mobo_Core_Source_Visibility::META_HIDDEN, true );
			if ( ! $source_hidden && 'hidden' === (string) $product->get_catalog_visibility( 'edit' ) && 'hidden' !== $previous ) {
				$product->set_catalog_visibility( $previous );
				$product->save();
			}
		}
		delete_post_meta( $post_id, self::META_PREV_VISIBILITY );
		self::log( 'Quarantine released by the migration re-probe; catalog visibility restored, stock waits for the next Source update.', array( 'postId' => $post_id ) );
		return true;
	}

	/**
	 * transition_post_status: trash/draft of a live quarantined copy.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function handle_transition( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || ! in_array( $new_status, array( 'trash', 'draft' ), true ) ) {
			return;
		}
		if ( in_array( $old_status, array( 'new', 'auto-draft', 'trash', 'draft' ), true ) ) {
			return;
		}
		$post_id   = is_object( $post ) && isset( $post->ID ) ? absint( $post->ID ) : 0;
		$post_type = is_object( $post ) && isset( $post->post_type ) ? (string) $post->post_type : '';
		if ( $post_id <= 0 || 'product' !== $post_type || ! class_exists( 'Mobo_Core_Migration_Quarantine' ) ) {
			return;
		}
		if ( Mobo_Core_Migration_Quarantine::count() <= 0 || ! Mobo_Core_Migration_Quarantine::is_marked( $post_id ) ) {
			return;
		}
		if ( ! self::release_off_sale( $post_id, $new_status ) ) {
			/* Not stamped yet: it is off sale anyway; reconcile releases it later. */
			self::schedule_reconcile();
		}
	}

	/**
	 * Admin duplicates card rows for post-migration quarantined copies, grouped by
	 * their canonical owner. Same shape as Product_Concurrency groups plus
	 * canonical_id. Reads options and a few post rows only.
	 *
	 * @param int $limit Max groups.
	 * @return array
	 */
	public static function duplicate_groups( $limit = 10 ) {
		if ( ! class_exists( 'Mobo_Core_Migration_Quarantine' ) || ! function_exists( 'get_post_status' ) ) {
			return array();
		}
		$by_owner = array();
		foreach ( Mobo_Core_Migration_Quarantine::all() as $post_id => $entry ) {
			$post_id = absint( $post_id );
			if ( 'product' !== (string) ( $entry['t'] ?? '' ) || ! self::is_copy_entry( $entry ) ) {
				continue;
			}
			$owner = self::owner_id( $entry );
			if ( $owner <= 0 || $owner === $post_id ) {
				continue;
			}
			if ( ! in_array( (string) get_post_status( $post_id ), array( 'publish', 'private', 'pending', 'future' ), true ) ) {
				continue;
			}
			if ( 'product' !== (string) get_post_type( $owner ) ) {
				continue;
			}
			$by_owner[ $owner ][] = $post_id;
		}
		$rows = array();
		foreach ( $by_owner as $owner => $copies ) {
			sort( $copies );
			$rows[] = array(
				'product_guid'  => self::owner_guid( $owner ),
				'product_count' => 1 + count( $copies ),
				'ids'           => implode( ',', array_merge( array( absint( $owner ) ), array_map( 'absint', $copies ) ) ),
				'canonical_id'  => absint( $owner ),
				'source'        => 'migration_quarantine',
			);
			if ( count( $rows ) >= max( 1, absint( $limit ) ) ) {
				break;
			}
		}
		return $rows;
	}

	/** @return int Number of post-migration duplicate groups. */
	public static function count_duplicate_groups() {
		return count( self::duplicate_groups( 1000 ) );
	}

	/* ------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/** @return string */
	private static function owner_guid( $owner ) {
		if ( ! class_exists( 'Mobo_Core_Product_State_Repository' ) ) {
			return '';
		}
		try {
			$row = ( new Mobo_Core_Product_State_Repository() )->get_by_woo_product_id( absint( $owner ) );
		} catch ( Throwable $e ) {
			return '';
		}
		return is_array( $row ) ? sanitize_text_field( (string) ( $row['product_guid'] ?? '' ) ) : '';
	}

	/** Remove the flag but leave the copy out of stock/hidden with no restorable overlay. @return void */
	private static function drop_flag_keep_off_sale( $post_id ) {
		delete_post_meta( $post_id, self::META_BLOCKED );
		delete_post_meta( $post_id, self::META_PREV_VISIBILITY );
		if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'Mobo_Core_Source_Visibility' ) ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$objects = array( $product );
		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) && method_exists( $product, 'get_children' ) ) {
			foreach ( (array) $product->get_children() as $child_id ) {
				$child = wc_get_product( absint( $child_id ) );
				if ( $child instanceof WC_Product ) {
					$objects[] = $child;
				}
			}
		}
		foreach ( $objects as $object ) {
			if ( Mobo_Core_Source_Visibility::is_blocked( $object ) ) {
				continue; /* Still Source-hidden/inactive: that overlay stays authoritative. */
			}
			if ( Mobo_Core_Source_Visibility::forget_natural_stock( $object ) ) {
				$object->save();
			}
		}
	}

	/** @return array<int,int> */
	private static function registry() {
		$raw = get_option( self::REGISTRY_OPTION, array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id => $at ) {
			if ( absint( $id ) > 0 ) {
				$out[ absint( $id ) ] = absint( $at );
			}
		}
		return $out;
	}

	/** @return void */
	private static function save_registry( $registry ) {
		if ( empty( $registry ) ) {
			if ( false !== get_option( self::REGISTRY_OPTION, false ) && function_exists( 'delete_option' ) ) {
				delete_option( self::REGISTRY_OPTION );
			}
			return;
		}
		update_option( self::REGISTRY_OPTION, $registry, false );
	}

	/** @return void */
	private static function log( $message, $context = array() ) {
		if ( class_exists( 'Mobo_Core_Logger' ) ) {
			Mobo_Core_Logger::warning( $message, $context );
		}
	}
}
