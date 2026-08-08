<?php
/**
 * Scoped cache-mutation guard for Mobo-owned WooCommerce mutations.
 *
 * Mobo Core owns a targeted, deduplicated cache invalidation pipeline at
 * shutdown. Cache plugins can also listen to normal WooCommerce CRUD hooks
 * and invalidate related archives on every save. This guard keeps those
 * native invalidations from bypassing Mobo's deferred archive-purge interval while a
 * Mobo Sync/Repair/Webhook/queue mutation is in progress.
 *
 * Supported native safeguards:
 * - WP Rocket: marks Mobo mutations as imports via rocket_is_importing.
 * - LiteSpeed Cache: removes broad/related public purge tags while archive
 *   purging is disabled, retaining direct post/URL purges.
 * - W3 Total Cache: vetoes native post/posts/all flushes while archive purging
 *   is disabled; Mobo performs the direct URL invalidation at shutdown.
 * - WP Super Cache: vetoes native post-cache clearing during the Mobo mutation
 *   scope; Mobo's final purger performs the configured targeted invalidation.
 *   The related-page filter is also installed as a compatibility fallback.
 *
 * WordPress/WooCommerce object/transient invalidation is intentionally not
 * suppressed because it is data-consistency invalidation, not full-page
 * archive invalidation.
 *
 * The guard is request-local, reference-counted, nested-operation safe, and
 * removed in finally blocks by run(). Normal wp-admin/WooCommerce edits that
 * do not originate from Mobo are unaffected.
 *
 * PHP 7.4 compatible.
 *
 * @package MoboCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Cache_Mutation_Guard {

	private static $depth                 = 0;
	private static $reasons               = array();
	private static $archive_purge_interval_minutes = 0;
	private static $protections           = array();

	/**
	 * Enter a Mobo-owned mutation scope.
	 *
	 * @param string $reason Diagnostic reason.
	 * @return void
	 */
	public static function begin( $reason = '' ) {
		$reason = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $reason ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $reason ) );
		if ( '' !== $reason ) {
			self::$reasons[ $reason ] = true;
		}

		self::$depth++;
		if ( 1 !== self::$depth ) {
			return;
		}

		self::$archive_purge_interval_minutes = self::archive_purge_interval_minutes();
		self::$protections           = array();

		// WP Rocket documents rocket_is_importing for bulk/import mutations.
		add_filter( 'rocket_is_importing', array( __CLASS__, 'filter_rocket_is_importing' ), PHP_INT_MAX );
		self::$protections['wpRocketImport'] = true;

		// LiteSpeed Cache: keep direct post/URL invalidation but strip related/global tags
		// during every Mobo mutation. If archive purging is enabled, Mobo's final
		// purger performs the broad post purge once after this guard is removed.
		add_filter( 'litespeed_purge_tags', array( __CLASS__, 'filter_litespeed_purge_tags' ), PHP_INT_MAX, 2 );
		self::$protections['liteSpeedRelatedTags'] = true;

		// W3 Total Cache: veto native post/posts/all flushes for the mutation scope.
		// Mobo's final purger performs either direct URL invalidation or one broad
		// post invalidation according to the archive-purge policy.
		add_filter( 'w3tc_preflush_post', array( __CLASS__, 'filter_w3tc_preflush' ), PHP_INT_MAX, 2 );
		add_filter( 'w3tc_preflush_posts', array( __CLASS__, 'filter_w3tc_preflush' ), PHP_INT_MAX, 2 );
		add_filter( 'w3tc_preflush_all', array( __CLASS__, 'filter_w3tc_preflush' ), PHP_INT_MAX, 2 );
		self::$protections['w3TotalCacheNativeFlush'] = true;

		// WP Super Cache checks wp_super_cache_clear_post_cache before clearing a
		// changed post. Veto it during Mobo mutations so its direct and related
		// cache deletion is deferred to Mobo's final targeted purger. Keep the
		// related-page filter too as a compatibility fallback for older releases.
		add_filter( 'wp_super_cache_clear_post_cache', array( __CLASS__, 'filter_wp_super_cache_clear_post_cache' ), PHP_INT_MAX, 2 );
		add_filter( 'wpsc_delete_related_pages_on_edit', array( __CLASS__, 'filter_wp_super_cache_related_pages' ), PHP_INT_MAX );
		self::$protections['wpSuperCacheNativePostFlush'] = true;
		self::$protections['wpSuperCacheRelatedPages']    = true;

		/**
		 * Fires after Mobo installs cache mutation safeguards.
		 *
		 * Third-party cache integrations can attach their own request-local guard
		 * without changing Mobo core. The matching end action always fires when
		 * the outermost scope exits.
		 *
		 * @param array $state Guard state.
		 */
		do_action( 'mobo_core_cache_mutation_guard_begin', self::get_state() );
	}

	/**
	 * Leave a Mobo-owned mutation scope.
	 *
	 * @return void
	 */
	public static function end() {
		if ( self::$depth <= 0 ) {
			self::reset_state();
			return;
		}

		self::$depth--;
		if ( self::$depth > 0 ) {
			return;
		}

		$state = self::get_state();

		remove_filter( 'rocket_is_importing', array( __CLASS__, 'filter_rocket_is_importing' ), PHP_INT_MAX );

		if ( isset( self::$protections['liteSpeedRelatedTags'] ) ) {
			remove_filter( 'litespeed_purge_tags', array( __CLASS__, 'filter_litespeed_purge_tags' ), PHP_INT_MAX );
		}
		if ( isset( self::$protections['w3TotalCacheNativeFlush'] ) ) {
			remove_filter( 'w3tc_preflush_post', array( __CLASS__, 'filter_w3tc_preflush' ), PHP_INT_MAX );
			remove_filter( 'w3tc_preflush_posts', array( __CLASS__, 'filter_w3tc_preflush' ), PHP_INT_MAX );
			remove_filter( 'w3tc_preflush_all', array( __CLASS__, 'filter_w3tc_preflush' ), PHP_INT_MAX );
		}
		if ( isset( self::$protections['wpSuperCacheNativePostFlush'] ) ) {
			remove_filter( 'wp_super_cache_clear_post_cache', array( __CLASS__, 'filter_wp_super_cache_clear_post_cache' ), PHP_INT_MAX );
		}
		if ( isset( self::$protections['wpSuperCacheRelatedPages'] ) ) {
			remove_filter( 'wpsc_delete_related_pages_on_edit', array( __CLASS__, 'filter_wp_super_cache_related_pages' ), PHP_INT_MAX );
		}

		/**
		 * Fires after the outermost Mobo cache mutation guard is removed.
		 *
		 * @param array $state State captured immediately before cleanup.
		 */
		do_action( 'mobo_core_cache_mutation_guard_end', $state );

		self::reset_state();
	}

	/**
	 * Execute a callback inside a guarded scope.
	 *
	 * @param callable $callback Callback.
	 * @param string   $reason Diagnostic reason.
	 * @return mixed
	 */
	public static function run( $callback, $reason = '' ) {
		self::begin( $reason );

		try {
			return call_user_func( $callback );
		} finally {
			self::end();
		}
	}

	/**
	 * WP Rocket import-state callback.
	 *
	 * @param bool $is_importing Existing import state.
	 * @return bool
	 */
	public static function filter_rocket_is_importing( $is_importing = false ) {
		return self::is_active() ? true : (bool) $is_importing;
	}

	/**
	 * LiteSpeed Cache purge-tag callback.
	 *
	 * LiteSpeed prefixes purge tags with an underscore before this filter. We
	 * normalize that prefix for classification and remove only known broad or
	 * related tags. Direct post (Po.) and URL (URL.) tags are preserved, as are
	 * unknown/custom tags.
	 *
	 * @param array $purge_tags Purge tags.
	 * @param bool  $is_private Private-cache purge flag.
	 * @return array
	 */
	public static function filter_litespeed_purge_tags( $purge_tags, $is_private = false ) {
		if ( ! self::is_active() || $is_private || ! is_array( $purge_tags ) ) {
			return $purge_tags;
		}

		$exact    = self::litespeed_related_exact_tags();
		$prefixes = self::litespeed_related_prefixes();
		$filtered = array();

		foreach ( $purge_tags as $tag ) {
			$tag = (string) $tag;
			$normalized = ltrim( $tag, '_' );

			if ( '*' === $normalized || in_array( $normalized, $exact, true ) ) {
				continue;
			}

			$remove = false;
			foreach ( $prefixes as $prefix ) {
				if ( '' !== $prefix && 0 === strpos( $normalized, $prefix ) ) {
					$remove = true;
					break;
				}
			}

			if ( ! $remove ) {
				$filtered[] = $tag;
			}
		}

		// LiteSpeed expects at least one synthetic tag when everything is suppressed.
		return $filtered ? array_values( $filtered ) : array( '_mobo_nothing' );
	}

	/**
	 * W3 Total Cache preflush callback.
	 *
	 * @param bool  $do_flush Existing decision.
	 * @param mixed $extras Extra W3TC context.
	 * @return bool
	 */
	public static function filter_w3tc_preflush( $do_flush, $extras = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( self::is_active() ) {
			return false;
		}

		return (bool) $do_flush;
	}

	/**
	 * WP Super Cache native post-clear callback.
	 *
	 * @param bool  $clear Existing decision.
	 * @param mixed $post Post object/context.
	 * @return bool
	 */
	public static function filter_wp_super_cache_clear_post_cache( $clear, $post = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( self::is_active() ) {
			return false;
		}

		return (bool) $clear;
	}

	/**
	 * WP Super Cache related-page deletion callback.
	 *
	 * @param mixed $delete_related Existing decision.
	 * @return mixed
	 */
	public static function filter_wp_super_cache_related_pages( $delete_related ) {
		if ( self::is_active() ) {
			return 0;
		}

		return $delete_related;
	}

	/**
	 * Whether a guarded Mobo mutation is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::$depth > 0;
	}

	/**
	 * Compact diagnostic state.
	 *
	 * @return array
	 */
	public static function get_state() {
		return array(
			'active'              => self::is_active(),
			'depth'               => absint( self::$depth ),
			'reasons'             => array_values( array_keys( self::$reasons ) ),
			'archivePurgeEnabled'         => self::$archive_purge_interval_minutes > 0,
			'archivePurgeIntervalMinutes' => absint( self::$archive_purge_interval_minutes ),
			'protections'         => array_values( array_keys( self::$protections ) ),
		);
	}

	/**
	 * Read the archive purge policy without relying on class load order.
	 *
	 * @return int
	 */
	private static function archive_purge_interval_minutes() {
		$value = get_option( 'mobo_core_cache_archive_purge_interval_minutes', '15' );
		if ( class_exists( 'Mobo_Core_Settings' ) && method_exists( 'Mobo_Core_Settings', 'sanitize_cache_archive_purge_interval' ) ) {
			return Mobo_Core_Settings::sanitize_cache_archive_purge_interval( $value, 15 );
		}

		$value = absint( $value );
		return in_array( $value, array( 0, 5, 10, 15, 20, 25, 30, 45, 60 ), true ) ? $value : 15;
	}

	/**
	 * Known exact LiteSpeed tags representing broad/related cache groups.
	 *
	 * @return array
	 */
	private static function litespeed_related_exact_tags() {
		$names = array(
			'TYPE_FEED',
			'TYPE_FRONTPAGE',
			'TYPE_HOME',
			'TYPE_PAGES',
			'TYPE_PAGES_WITH_RECENT_POSTS',
			'TYPE_REST',
			'TYPE_LIST',
		);

		return self::litespeed_tag_constants( $names );
	}

	/**
	 * Known LiteSpeed tag prefixes representing archives or broad related groups.
	 *
	 * @return array
	 */
	private static function litespeed_related_prefixes() {
		$names = array(
			'TYPE_ARCHIVE_POSTTYPE',
			'TYPE_ARCHIVE_TERM',
			'TYPE_AUTHOR',
			'TYPE_ARCHIVE_DATE',
			'TYPE_BLOG',
			'TYPE_WIDGET',
		);

		return self::litespeed_tag_constants( $names );
	}

	/**
	 * Resolve LiteSpeed Tag constants only when the plugin exposes them.
	 *
	 * @param array $names Constant names.
	 * @return array
	 */
	private static function litespeed_tag_constants( $names ) {
		// Current LiteSpeed Cache tag values. These fallbacks intentionally cover
		// only broad/related groups that Mobo may suppress. Direct post/URL tags
		// are never classified from these fallbacks, so unknown future tags remain
		// untouched rather than being suppressed speculatively.
		$fallbacks = array(
			'TYPE_FEED'                    => 'FD',
			'TYPE_FRONTPAGE'               => 'F',
			'TYPE_HOME'                     => 'H',
			'TYPE_PAGES'                    => 'PGS',
			'TYPE_PAGES_WITH_RECENT_POSTS' => 'PGSRP',
			'TYPE_ARCHIVE_POSTTYPE'        => 'PT.',
			'TYPE_ARCHIVE_TERM'            => 'T.',
			'TYPE_AUTHOR'                  => 'A.',
			'TYPE_ARCHIVE_DATE'            => 'D.',
			'TYPE_BLOG'                    => 'B.',
			'TYPE_WIDGET'                  => 'W.',
			'TYPE_REST'                    => 'REST',
			'TYPE_LIST'                    => 'LIST',
		);

		$values = array();
		foreach ( (array) $names as $name ) {
			$constant = 'LiteSpeed\\Tag::' . $name;
			$value    = '';

			if ( defined( $constant ) ) {
				$value = (string) constant( $constant );
			} elseif ( isset( $fallbacks[ $name ] ) ) {
				$value = $fallbacks[ $name ];
			}

			if ( '' !== $value ) {
				$values[] = $value;
			}
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * Reset request-local state after the outermost scope exits.
	 *
	 * @return void
	 */
	private static function reset_state() {
		self::$depth                 = 0;
		self::$reasons               = array();
		self::$archive_purge_interval_minutes = 0;
		self::$protections           = array();
	}
}
