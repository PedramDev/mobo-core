<?php
/**
 * Targeted product cache invalidation for Mobo-linked WooCommerce products.
 *
 * The purger deliberately avoids full object/page-cache flushes. Product IDs,
 * related archive URLs and old/new taxonomy URLs are collected during the
 * request and invalidated once at shutdown.
 *
 * Integration modes (mobo_core_cache_integration_mode, installation-wide):
 * - off:        no page-cache plugin calls and no warmup; WordPress/WooCommerce
 *               object-cache invalidation still runs (data consistency).
 * - safe:       default. At most SAFE_MAX_IMMEDIATE_URLS product URLs are purged
 *               per request; a burst (mass Source price change) switches to one
 *               coalesced, capped deferred purge; never a home/domain purge.
 * - aggressive: larger caps, home-page purge in the deferred archive pass and
 *               WP Super Cache overflow fallback. Still never a domain purge.
 * Mobo never calls WP Rocket preload/RUCSS APIs; WP Rocket schedules its own
 * preload after a targeted purge.
 *
 * PHP 7.4 compatible.
 *
 * @package MoboCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Cache_Purger {

	private static $initialized       = false;
	private static $flushing          = false;
	private static $has_change        = false;
	private static $object_ids        = array();
	private static $product_ids       = array();
	private static $urls              = array();
	private static $custom_urls       = array();
	private static $archive_urls      = array();
	private static $reasons           = array();
	private static $collection_errors = array();
	private static $native_registered = false;
	private static $suppressed_product_ids = array();

	const OPTION_LAST_RESULT = 'mobo_core_cache_purge_last_result';
	const OPTION_ARCHIVE_QUEUE = 'mobo_core_cache_archive_purge_queue'; // legacy migration source only
	const STATE_ARCHIVE_QUEUE  = 'cache_archive_purge_queue';
	const OPTION_ARCHIVE_LAST_RESULT = 'mobo_core_cache_archive_purge_last_result'; // legacy Default-only migration source
	const STATE_ARCHIVE_LAST_RESULT  = 'cache_archive_purge_last_result';
	const ARCHIVE_QUEUE_SCHEMA_VERSION = 1;
	const ARCHIVE_QUEUE_MAX_URLS = 5000;
	const ARCHIVE_QUEUE_MAX_PRODUCTS = 5000;
	const HEALTH_SCHEMA_VERSION = 4;
	const ERROR_MAX_LENGTH = 1000;

	const OPTION_INTEGRATION_MODE = 'mobo_core_cache_integration_mode';
	const MODE_OFF        = 'off';
	const MODE_SAFE       = 'safe';
	const MODE_AGGRESSIVE = 'aggressive';
	const OPTION_BURST    = 'mobo_core_cache_purge_burst_window';
	const BURST_WINDOW_SECONDS = 600;
	const SAFE_BURST_THRESHOLD_PRODUCTS       = 60;
	const AGGRESSIVE_BURST_THRESHOLD_PRODUCTS = 600;
	const SAFE_MAX_IMMEDIATE_URLS       = 25;
	const AGGRESSIVE_MAX_IMMEDIATE_URLS = 200;
	const SAFE_MAX_DEFERRED_URLS_PER_PASS       = 200;
	const AGGRESSIVE_MAX_DEFERRED_URLS_PER_PASS = 1000;
	const DEFERRED_CONTINUATION_SECONDS = 60;
	const CLAIM_SECONDS = 180;

	/**
	 * Register WooCommerce/WordPress listeners and deferred flush handlers.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		add_action( 'woocommerce_new_product', array( __CLASS__, 'handle_product_saved' ), 20, 2 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'handle_product_saved' ), 20, 2 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'handle_product_saved' ), 20, 2 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'handle_product_saved' ), 20, 2 );
		add_action( 'set_object_terms', array( __CLASS__, 'handle_object_terms_set' ), 20, 6 );
		add_action( 'post_updated', array( __CLASS__, 'handle_post_updated' ), 20, 3 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), PHP_INT_MAX );
		add_filter( 'rocket_disable_url_validation', array( __CLASS__, 'filter_rocket_disable_product_category_url_validation' ), PHP_INT_MAX, 1 );

		/*
		 * Direct cron runners load WordPress normally, but the native fallback also
		 * protects non-standard exits where the WordPress shutdown action is skipped.
		 */
		if ( ! self::$native_registered ) {
			self::$native_registered = true;
			register_shutdown_function( array( __CLASS__, 'flush' ) );
		}
	}


	/**
	 * Disable WP Rocket taxonomy URL validation only for WooCommerce product categories.
	 *
	 * Some stores intentionally serve hierarchical product-category URLs while SEO
	 * canonical generation returns a flattened term URL. WP Rocket rejects that
	 * mismatch and never creates index-https.html. Limiting the bypass to product_cat
	 * preserves normal URL validation everywhere else.
	 *
	 * @param bool $disabled Existing decision.
	 * @return bool
	 */
	public static function filter_rocket_disable_product_category_url_validation( $disabled ) {
		if ( $disabled ) {
			return true;
		}

		$product_cat = sanitize_title( (string) get_query_var( 'product_cat', '' ) );
		if ( '' !== $product_cat ) {
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request_uri ) {
			return false;
		}

		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}

		$base       = self::product_category_base();
		$base_prefix = '/' . trim( $base, '/' ) . '/';

		return '' !== $base && 0 === strpos( '/' . ltrim( $path, '/' ), $base_prefix );
	}

	/**
	 * Queue a Mobo-linked product or variation after WooCommerce CRUD save.
	 *
	 * @param int        $product_id Product or variation ID.
	 * @param WC_Product $product Optional product object.
	 * @return void
	 */
	public static function handle_product_saved( $product_id, $product = null ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! self::is_mobo_linked_object( $product_id ) ) {
			return;
		}

		self::queue_product( $product_id, 'woocommerce-crud-save' );
	}

	/**
	 * Queue category/tag changes and preserve URLs of removed terms.
	 *
	 * @param int    $object_id Object ID.
	 * @param array  $terms Terms supplied to wp_set_object_terms().
	 * @param array  $tt_ids New term-taxonomy IDs.
	 * @param string $taxonomy Taxonomy name.
	 * @param bool   $append Whether terms were appended.
	 * @param array  $old_tt_ids Previous term-taxonomy IDs.
	 * @return void
	 */
	public static function handle_object_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ) {
		$object_id = absint( $object_id );
		$taxonomy  = sanitize_key( (string) $taxonomy );

		if ( $object_id <= 0 || ! in_array( $taxonomy, array( 'product_cat', 'product_tag', 'product_type' ), true ) ) {
			return;
		}

		if ( ! self::is_mobo_linked_object( $object_id ) ) {
			return;
		}

		if ( self::archive_purge_interval_minutes() > 0 && in_array( $taxonomy, array( 'product_cat', 'product_tag' ), true ) ) {
			try {
				self::capture_term_taxonomy_urls( $old_tt_ids, $taxonomy );
				self::capture_term_taxonomy_urls( $tt_ids, $taxonomy );
			} catch ( Throwable $e ) {
				self::capture_collection_error( 'product-terms-updated', $e );
			}
		}

		self::queue_product( $object_id, 'product-terms-updated' );
	}

	/**
	 * Preserve the previous permalink when a Mobo product slug/status changes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post_after New post state.
	 * @param WP_Post $post_before Previous post state.
	 * @return void
	 */
	public static function handle_post_updated( $post_id, $post_after, $post_before ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 || ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post ) {
			return;
		}

		if ( ! in_array( $post_after->post_type, array( 'product', 'product_variation' ), true ) || ! self::is_mobo_linked_object( $post_id ) ) {
			return;
		}

		if ( (string) $post_before->post_name !== (string) $post_after->post_name || (string) $post_before->post_status !== (string) $post_after->post_status ) {
			try {
				$old_url = get_permalink( $post_before );
				if ( is_string( $old_url ) && '' !== $old_url ) {
					self::store_url( $old_url );
				}
			} catch ( Throwable $e ) {
				self::capture_collection_error( 'product-old-permalink', $e );
			}

			self::queue_product( $post_id, 'product-permalink-or-status-updated' );
		}
	}

	/**
	 * Capture current product/archive URLs before a known direct mutation.
	 * This method does not request a purge until queue_product() is called.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $reason Snapshot reason.
	 * @return void
	 */
	public static function snapshot_product( $product_id, $reason = '' ) {
		self::init();
		$resolved = self::resolve_product_ids( $product_id );

		if ( $resolved['product_id'] <= 0 ) {
			return;
		}

		try {
			self::capture_related_urls( $resolved['product_id'] );
		} catch ( Throwable $e ) {
			self::capture_collection_error( 'snapshot-product', $e );
		}

		if ( '' !== (string) $reason ) {
			self::$reasons[ sanitize_key( (string) $reason ) ] = true;
		}
	}

	/**
	 * Suppress targeted page-cache invalidation for one parent product until the
	 * current request ends. Used by intermediate pages of an authoritative Mobo
	 * variants snapshot; the final page performs one converged purge/warmup.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	public static function suppress_product_for_request( $product_id ) {
		self::init();
		$resolved = self::resolve_product_ids( $product_id );
		if ( $resolved['product_id'] > 0 ) {
			self::$suppressed_product_ids[ $resolved['product_id'] ] = true;
		}
	}

	/**
	 * Remove request-local suppression for one parent product.
	 *
	 * Variation delta bursts suppress CRUD-triggered purges while individual
	 * variations are saved. The deferred parent finalizer calls this immediately
	 * before queuing the single converged purge for the parent.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	public static function unsuppress_product_for_request( $product_id ) {
		self::init();
		$resolved = self::resolve_product_ids( $product_id );
		if ( $resolved['product_id'] > 0 ) {
			unset( self::$suppressed_product_ids[ $resolved['product_id'] ] );
		}
	}

	/**
	 * Queue targeted invalidation for a product or variation.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $reason Change reason.
	 * @return void
	 */
	public static function queue_product( $product_id, $reason = '' ) {
		self::init();
		$resolved = self::resolve_product_ids( $product_id );

		if ( $resolved['product_id'] <= 0 ) {
			return;
		}

		if ( isset( self::$suppressed_product_ids[ $resolved['product_id'] ] ) ) {
			return;
		}

		self::$has_change = true;
		self::$product_ids[ $resolved['product_id'] ] = true;
		self::$object_ids[ $resolved['product_id'] ]  = true;

		if ( $resolved['object_id'] > 0 ) {
			self::$object_ids[ $resolved['object_id'] ] = true;
		}

		if ( '' !== (string) $reason ) {
			self::$reasons[ sanitize_key( (string) $reason ) ] = true;
		}

		try {
			self::capture_related_urls( $resolved['product_id'] );
		} catch ( Throwable $e ) {
			self::capture_collection_error( 'queue-product', $e );
		}
	}

	/**
	 * Queue an additional front-end URL for the next targeted purge.
	 *
	 * @param string $url Absolute URL.
	 * @param string $reason Change reason.
	 * @return void
	 */
	public static function queue_url( $url, $reason = '' ) {
		self::init();
		$url = self::normalize_url( $url );

		if ( '' === $url ) {
			return;
		}

		self::$has_change = true;
		self::$urls[ $url ]        = true;
		self::$custom_urls[ $url ] = true;

		if ( '' !== (string) $reason ) {
			self::$reasons[ sanitize_key( (string) $reason ) ] = true;
		}
	}

	/**
	 * Flush all queued targeted caches once.
	 *
	 * @return array Summary useful for tests/integrations.
	 */
	public static function flush() {
		if ( self::$flushing || ! self::$has_change ) {
			return array(
				'flushed'      => false,
				'products'     => 0,
				'objects'      => 0,
				'urls'         => 0,
				'archiveUrlsQueued' => 0,
				'integrations' => array(),
				'status'       => 'not-run',
			);
		}

		self::$flushing = true;

		$started_at       = microtime( true );
		$attempted_at     = time();
		$product_ids      = array();
		$object_ids       = array();
		$urls             = array();
		$custom_urls      = array();
		$archive_urls     = array();
		$reasons          = array();
		$integration_data = array();
		$archive_interval = self::archive_purge_interval_minutes();
		$archive_queue    = array( 'queued' => false, 'urlCount' => 0, 'productCount' => 0, 'dueAt' => 0 );
		$warmup_queue     = array( 'queued' => false, 'queuedCount' => 0, 'pendingCount' => 0, 'status' => 'not-run' );
		$overall_status   = 'failed';
		$last_error       = '';
		$mode             = self::integration_mode();
		$burst            = array( 'active' => false, 'count' => 0 );
		$deferred_count   = 0;
		$stale_count      = 0;
		/* Captured before flush opens its own guard scope for object-cache work. */
		$mobo_request     = ! class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) || Mobo_Core_Cache_Mutation_Guard::was_used_in_request();

		try {
			$product_ids = array_values( array_unique( array_filter( array_map( 'absint', array_keys( self::$product_ids ) ) ) ) );
			$object_ids  = array_values( array_unique( array_filter( array_map( 'absint', array_keys( self::$object_ids ) ) ) ) );
			$reasons     = array_values( array_filter( array_map( 'sanitize_key', array_keys( self::$reasons ) ) ) );
			$collection_errors = array_values( array_filter( array_map( array( __CLASS__, 'sanitize_error' ), self::$collection_errors ) ) );

			foreach ( $product_ids as $product_id ) {
				try {
					self::capture_related_urls( $product_id );
				} catch ( Throwable $e ) {
					self::capture_collection_error( 'flush-product-urls', $e );
					$collection_errors[] = self::sanitize_error( 'flush-product-urls: ' . $e->getMessage() );
				}
			}

			$urls = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_url' ), array_keys( self::$urls ) ) ) ) );
			$base_urls = $urls;
			$queued_custom_urls = array_values( array_filter( array_map( array( __CLASS__, 'normalize_url' ), array_keys( self::$custom_urls ) ) ) );
			$archive_urls = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_url' ), array_keys( self::$archive_urls ) ) ) ) );
			$custom_hook_error = self::sanitize_error( implode( ' | ', array_slice( $collection_errors, 0, 5 ) ) );

			try {
				$filtered_urls = apply_filters( 'mobo_core_cache_purge_urls', $urls, $product_ids, $reasons );
				if ( is_array( $filtered_urls ) ) {
					$urls = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_url' ), $filtered_urls ) ) ) );
				}
			} catch ( Throwable $e ) {
				$filter_error = self::sanitize_error( 'mobo_core_cache_purge_urls: ' . $e->getMessage() );
				$custom_hook_error = '' !== $custom_hook_error ? self::sanitize_error( $custom_hook_error . ' | ' . $filter_error ) : $filter_error;
				self::log_warning( 'Custom cache purge URL filter failed: ' . $custom_hook_error, array( 'integration' => 'custom-hooks' ) );
			}

			if ( $archive_interval > 0 && ! empty( $archive_urls ) ) {
				try {
					$filtered_archive_urls = apply_filters( 'mobo_core_cache_archive_purge_urls', $archive_urls, $product_ids, $reasons, $archive_interval );
					if ( is_array( $filtered_archive_urls ) ) {
						$archive_urls = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_url' ), $filtered_archive_urls ) ) ) );
					}
				} catch ( Throwable $e ) {
					$filter_error = self::sanitize_error( 'mobo_core_cache_archive_purge_urls: ' . $e->getMessage() );
					$custom_hook_error = '' !== $custom_hook_error ? self::sanitize_error( $custom_hook_error . ' | ' . $filter_error ) : $filter_error;
				}
			}

			$custom_urls = array_values(
				array_unique(
					array_merge(
						array_values( array_intersect( $queued_custom_urls, $urls ) ),
						array_values( array_diff( $urls, $base_urls ) )
					)
				)
			);

			/*
			 * Bound the immediate page-cache work of one request. A Source mass
			 * price change touches thousands of products across many slices; each
			 * slice must not purge an unbounded URL list. During a burst every
			 * product URL is coalesced into the single deferred queue instead.
			 */
			$immediate_urls        = $urls;
			$immediate_product_ids = $product_ids;
			$deferred_urls         = array();
			$deferred_product_ids  = array();
			if ( self::MODE_OFF !== $mode ) {
				$burst = self::register_burst( count( $product_ids ) );
				$cap   = self::max_immediate_urls( $mode );
				if ( ! empty( $burst['active'] ) && $archive_interval > 0 ) {
					$deferred_urls         = $urls;
					$deferred_product_ids  = $product_ids;
					$immediate_urls        = array();
					$immediate_product_ids = array();
				} elseif ( count( $urls ) > $cap || count( $product_ids ) > $cap ) {
					$immediate_urls        = array_slice( $urls, 0, $cap );
					$immediate_product_ids = array_slice( $product_ids, 0, $cap );
					$deferred_urls         = array_slice( $urls, $cap );
					$deferred_product_ids  = array_slice( $product_ids, $cap );
				}
				if ( $archive_interval <= 0 && ( ! empty( $deferred_urls ) || ! empty( $deferred_product_ids ) ) ) {
					/* No deferred queue configured: leave these pages to the cache
					 * plugin's own lifespan instead of purging without bound. */
					$stale_count          = max( count( $deferred_urls ), count( $deferred_product_ids ) );
					$deferred_urls        = array();
					$deferred_product_ids = array();
				}
				$custom_urls    = array_values( array_intersect( $custom_urls, $immediate_urls ) );
				$deferred_count = count( $deferred_urls );
			} else {
				$immediate_urls        = array();
				$immediate_product_ids = array();
				$custom_urls           = array();
			}

			$queue_urls = array_values( array_unique( array_merge( $deferred_urls, $archive_urls ) ) );
			if ( self::MODE_OFF !== $mode && $archive_interval > 0 && ! empty( $queue_urls ) ) {
				$queue_products = ! empty( $deferred_product_ids ) ? array_values( array_unique( array_merge( $deferred_product_ids, $product_ids ) ) ) : $product_ids;
				$archive_queue  = self::enqueue_deferred_archive_purge( $queue_urls, $queue_products, $reasons, $archive_interval );
			}

			/* Clear the request queue before integrations run, preventing recursive duplicate work. */
			self::reset_queue();

			$inventory = self::get_current_integration_inventory();

			$integration_data['wordpressWooCommerce'] = self::run_integration(
				'wordpressWooCommerce',
				$inventory['wordpressWooCommerce'],
				static function () use ( $object_ids, $product_ids ) {
					/* clean_post_cache() fires WP Rocket's rocket_clean_post() listener,
					 * which purges home + every related archive and dispatches a
					 * preload. Run object-cache invalidation inside the guard. */
					if ( class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) ) {
						Mobo_Core_Cache_Mutation_Guard::run(
							static function () use ( $object_ids, $product_ids ) {
								self::purge_wordpress_and_woocommerce( $object_ids, $product_ids );
							},
							'cache-purger-object-cache'
						);
						return;
					}
					self::purge_wordpress_and_woocommerce( $object_ids, $product_ids );
				}
			);

			if ( self::MODE_OFF === $mode ) {
				foreach ( array( 'wpRocket', 'liteSpeedCache', 'w3TotalCache', 'wpSuperCache', 'customHooks' ) as $skipped_key ) {
					$integration_data[ $skipped_key ] = self::skipped_integration( $skipped_key, $inventory[ $skipped_key ], 'disabled-by-mode' );
				}
				$warmup_queue['status'] = 'disabled-by-mode';
			}

			$urls                  = $immediate_urls;
			$product_ids_for_pages = $immediate_product_ids;

			/* Product URLs are purged immediately up to the per-request cap; archives and overflow are deferred. */
			if ( self::MODE_OFF !== $mode ) {
				$integration_data['wpRocket'] = self::run_integration(
					'wpRocket',
					$inventory['wpRocket'],
					static function () use ( $product_ids_for_pages, $urls, $custom_urls ) {
						if ( empty( $urls ) && empty( $custom_urls ) ) {
							return; // Everything was coalesced into the deferred queue.
						}
						if ( ! self::purge_wp_rocket( $product_ids_for_pages, $urls, $custom_urls, false ) ) {
							throw new RuntimeException( 'WP Rocket targeted purge API is unavailable.' );
						}
					}
				);

				$integration_data['liteSpeedCache'] = self::run_integration(
					'liteSpeedCache',
					$inventory['liteSpeedCache'],
					static function () use ( $product_ids_for_pages, $urls ) {
						if ( empty( $urls ) ) {
							return;
						}
						if ( ! self::purge_litespeed( $product_ids_for_pages, $urls, false ) ) {
							throw new RuntimeException( 'LiteSpeed targeted purge hooks are unavailable.' );
						}
					}
				);

				$integration_data['w3TotalCache'] = self::run_integration(
					'w3TotalCache',
					$inventory['w3TotalCache'],
					static function () use ( $product_ids_for_pages, $urls ) {
						if ( empty( $urls ) ) {
							return;
						}
						if ( ! self::purge_w3_total_cache( $product_ids_for_pages, $urls, false ) ) {
							throw new RuntimeException( 'W3 Total Cache targeted purge API is unavailable.' );
						}
					}
				);

				$integration_data['wpSuperCache'] = self::run_integration(
					'wpSuperCache',
					$inventory['wpSuperCache'],
					static function () use ( $product_ids_for_pages ) {
						if ( empty( $product_ids_for_pages ) ) {
							return;
						}
						if ( ! self::purge_wp_super_cache( $product_ids_for_pages, false ) ) {
							throw new RuntimeException( 'WP Super Cache targeted purge API is unavailable.' );
						}
					}
				);

				$context = array(
					'product_ids'                  => $product_ids,
					'object_ids'                   => $object_ids,
					'urls'                         => $urls,
					'custom_urls'                  => $custom_urls,
					'reasons'                      => $reasons,
					'archivePurgeEnabled'          => $archive_interval > 0,
					'archivePurgeDeferred'         => true,
					'archivePurgeIntervalMinutes'  => $archive_interval,
					'archiveUrlsQueued'            => isset( $archive_queue['urlCount'] ) ? absint( $archive_queue['urlCount'] ) : 0,
					'archivePurgeDueAt'            => isset( $archive_queue['dueAt'] ) ? absint( $archive_queue['dueAt'] ) : 0,
					'integrationResults'           => $integration_data,
					'integrationMode'              => $mode,
					'burstActive'                  => ! empty( $burst['active'] ),
					'deferredProductUrls'          => $deferred_count,
				);

				$integration_data['customHooks'] = self::run_custom_hooks_integration( $inventory['customHooks'], $context, $custom_hook_error );

				/*
				 * Warm only the current product permalink after at least one detected
				 * page-cache integration was purged successfully. The warmup itself is
				 * deferred to the real cron so sync/shutdown never waits for page render.
				 */
				if ( ! empty( $burst['active'] ) ) {
					$warmup_queue['status'] = 'skipped-burst';
				} elseif ( ! $mobo_request ) {
					/* Storefront requests (e.g. checkout stock changes) never warm. */
					$warmup_queue['status'] = 'skipped-non-mobo-request';
				} else {
					try {
						$warmup_queue = self::queue_product_warmups( $product_ids_for_pages, $inventory, $integration_data, $reasons );
					} catch ( Throwable $e ) {
						$warmup_queue['status'] = 'enqueue-failed';
						self::log_warning( 'Product cache warmup admission failed; synchronization continues: ' . self::sanitize_error( $e->getMessage() ), array( 'integration' => 'cache-warmup' ) );
					}
				}
			} // End page-cache integrations (mode is not off).

			$overall_status = self::resolve_overall_status( $integration_data );
			$last_error     = self::resolve_last_integration_error( $integration_data );
		} catch ( Throwable $e ) {
			self::reset_queue();
			$last_error = self::sanitize_error( $e->getMessage() );
			$integration_data['moboCorePurger'] = array(
				'label'      => 'Mobo Core cache purger',
				'detected'   => true,
				'version'    => defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '',
				'status'     => 'failed',
				'durationMs' => self::elapsed_ms( $started_at ),
				'error'      => $last_error,
			);
			$overall_status = 'failed';
			self::log_warning( 'Targeted cache purge pipeline failed but product synchronization will continue: ' . $last_error, array( 'integration' => 'mobo-core-purger' ) );
		} finally {
			$completed_at = time();
			$record = array(
				'schemaVersion'                => self::HEALTH_SCHEMA_VERSION,
				'pluginVersion'                => defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '',
				'status'                       => $overall_status,
				'attemptedAt'                  => $attempted_at,
				'completedAt'                  => $completed_at,
				'productCount'                 => count( $product_ids ),
				'objectCount'                  => count( $object_ids ),
				'urlCount'                     => count( $urls ),
				'customUrlCount'               => count( $custom_urls ),
				'archivePurgeIntervalMinutes'  => $archive_interval,
				'archiveUrlsQueued'            => isset( $archive_queue['urlCount'] ) ? absint( $archive_queue['urlCount'] ) : 0,
				'archivePurgeDueAt'            => isset( $archive_queue['dueAt'] ) ? absint( $archive_queue['dueAt'] ) : 0,
				'warmupQueuedCount'             => isset( $warmup_queue['queuedCount'] ) ? absint( $warmup_queue['queuedCount'] ) : 0,
				'warmupPendingCount'            => isset( $warmup_queue['pendingCount'] ) ? absint( $warmup_queue['pendingCount'] ) : 0,
				'warmupQueueStatus'             => isset( $warmup_queue['status'] ) ? sanitize_key( (string) $warmup_queue['status'] ) : 'not-run',
				'durationMs'                   => self::elapsed_ms( $started_at ),
				'reasons'                      => array_slice( $reasons, 0, 20 ),
				'archivePurgeEnabled'           => $archive_interval > 0,
				'integrationMode'              => $mode,
				'burstActive'                  => ! empty( $burst['active'] ),
				'burstProductCount'            => isset( $burst['count'] ) ? absint( $burst['count'] ) : 0,
				'deferredUrlCount'             => $deferred_count,
				'staleUrlCount'                => $stale_count,
				'lastError'                    => $last_error,
				'integrations'                 => $integration_data,
			);
			self::persist_health_record( $record );
			self::$flushing = false;
		}

		$successful_integrations = array();
		foreach ( $integration_data as $key => $integration ) {
			if ( isset( $integration['status'] ) && 'success' === $integration['status'] ) {
				$successful_integrations[] = $key;
			}
		}

		return array(
			'flushed'           => true,
			'products'          => count( $product_ids ),
			'objects'           => count( $object_ids ),
			'urls'              => count( $urls ),
			'archiveUrlsQueued' => isset( $archive_queue['urlCount'] ) ? absint( $archive_queue['urlCount'] ) : 0,
			'archivePurgeDueAt' => isset( $archive_queue['dueAt'] ) ? absint( $archive_queue['dueAt'] ) : 0,
			'warmupQueued'      => isset( $warmup_queue['queuedCount'] ) ? absint( $warmup_queue['queuedCount'] ) : 0,
			'warmupPending'     => isset( $warmup_queue['pendingCount'] ) ? absint( $warmup_queue['pendingCount'] ) : 0,
			'warmupStatus'      => isset( $warmup_queue['status'] ) ? (string) $warmup_queue['status'] : '',
			'integrations'      => $successful_integrations,
			'status'            => $overall_status,
			'lastError'         => $last_error,
			'mode'              => $mode,
			'burstActive'       => ! empty( $burst['active'] ),
			'deferredUrls'      => $deferred_count,
			'staleUrls'         => $stale_count,
		);
	}

	/**
	 * Current cache-plugin integration mode (installation-wide).
	 *
	 * @return string off|safe|aggressive
	 */
	public static function integration_mode() {
		try {
			$value = get_option( self::OPTION_INTEGRATION_MODE, self::MODE_SAFE );
		} catch ( Throwable $e ) {
			return self::MODE_SAFE;
		}
		return self::sanitize_integration_mode( $value );
	}

	/**
	 * @param mixed $value Raw mode.
	 * @return string
	 */
	public static function sanitize_integration_mode( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( self::MODE_OFF, self::MODE_SAFE, self::MODE_AGGRESSIVE ), true ) ? $value : self::MODE_SAFE;
	}

	/**
	 * Whether a product-change burst (e.g. a Source mass price change) is active.
	 * While active, product URLs are coalesced into one deferred purge and
	 * warmup is paused.
	 *
	 * @return bool
	 */
	public static function is_burst_active() {
		try {
			$state = get_option( self::OPTION_BURST, array() );
		} catch ( Throwable $e ) {
			return false;
		}
		return is_array( $state ) && isset( $state['burstUntil'] ) && absint( $state['burstUntil'] ) > time();
	}

	/**
	 * Record purged products in the rolling window and return burst state.
	 *
	 * @param int $product_count Products in this flush.
	 * @return array
	 */
	private static function register_burst( $product_count ) {
		$product_count = absint( $product_count );
		try {
			$now   = time();
			$state = get_option( self::OPTION_BURST, array() );
			$state = is_array( $state ) ? $state : array();
			$start = isset( $state['windowStart'] ) ? absint( $state['windowStart'] ) : 0;
			$count = isset( $state['count'] ) ? absint( $state['count'] ) : 0;
			$until = isset( $state['burstUntil'] ) ? absint( $state['burstUntil'] ) : 0;
			if ( $start <= 0 || $now - $start >= self::BURST_WINDOW_SECONDS ) {
				$start = $now;
				$count = 0;
			}
			$count += $product_count;
			$threshold = (int) apply_filters(
				'mobo_core_cache_burst_threshold_products',
				self::MODE_AGGRESSIVE === self::integration_mode() ? self::AGGRESSIVE_BURST_THRESHOLD_PRODUCTS : self::SAFE_BURST_THRESHOLD_PRODUCTS
			);
			$threshold = max( 1, $threshold );
			if ( $count > $threshold ) {
				$until = $now + self::BURST_WINDOW_SECONDS;
			}
			if ( $product_count > 0 ) {
				update_option( self::OPTION_BURST, array( 'windowStart' => $start, 'count' => $count, 'burstUntil' => $until ), false );
			}
			return array( 'active' => $until > $now, 'count' => $count );
		} catch ( Throwable $e ) {
			return array( 'active' => false, 'count' => 0 );
		}
	}

	private static function max_immediate_urls( $mode ) {
		$cap = self::MODE_AGGRESSIVE === $mode ? self::AGGRESSIVE_MAX_IMMEDIATE_URLS : self::SAFE_MAX_IMMEDIATE_URLS;
		return max( 1, min( 1000, (int) apply_filters( 'mobo_core_cache_max_immediate_urls', $cap, $mode ) ) );
	}

	private static function max_deferred_urls_per_pass( $mode ) {
		$cap = self::MODE_AGGRESSIVE === $mode ? self::AGGRESSIVE_MAX_DEFERRED_URLS_PER_PASS : self::SAFE_MAX_DEFERRED_URLS_PER_PASS;
		return max( 1, min( 5000, (int) apply_filters( 'mobo_core_cache_max_deferred_urls_per_pass', $cap, $mode ) ) );
	}

	private static function skipped_integration( $key, $inventory, $reason ) {
		return array(
			'label'      => isset( $inventory['label'] ) ? (string) $inventory['label'] : (string) $key,
			'detected'   => ! empty( $inventory['detected'] ),
			'version'    => isset( $inventory['version'] ) ? (string) $inventory['version'] : '',
			'status'     => 'skipped',
			'durationMs' => 0,
			'error'      => '',
			'reason'     => sanitize_key( (string) $reason ),
		);
	}

	/**
	 * React immediately when the admin changes the deferred archive purge interval.
	 * Disabling clears pending archive work so it cannot fire unexpectedly later.
	 * Shortening an interval may move the current due time earlier; new changes never
	 * push an existing batching window further into the future.
	 *
	 * @param int $minutes Interval in minutes.
	 * @return void
	 */
	public static function handle_archive_interval_changed( $minutes ) {
		$minutes = self::sanitize_archive_interval( $minutes );
		$lock    = self::acquire_archive_queue_lock();
		if ( false === $lock ) {
			/* The setting itself is already durable; the next queue pass will reconcile it. */
			return;
		}

		try {
			if ( $minutes <= 0 ) {
				if ( class_exists( 'Mobo_Core_State_Store' ) ) { Mobo_Core_State_Store::delete( self::STATE_ARCHIVE_QUEUE ); }
				self::delete_legacy_archive_queue();
				return;
			}

			$queue = self::read_archive_queue();
			if ( empty( $queue['urls'] ) ) {
				return;
			}

			$now            = time();
			$new_due        = $now + ( $minutes * MINUTE_IN_SECONDS );
			$current_due    = isset( $queue['dueAt'] ) ? absint( $queue['dueAt'] ) : 0;
			$queue['dueAt'] = $current_due > 0 ? min( $current_due, $new_due ) : $new_due;
			$queue['intervalMinutes'] = $minutes;
			$queue['updatedAt']       = $now;
			$queue = self::strip_claim( $queue );
			self::write_archive_queue( $queue );
		} finally {
			self::release_archive_queue_lock( $lock );
		}
	}

	/**
	 * Process deferred archive page-cache invalidation when its batching window is due.
	 * This is called by the real Mobo cron runner, not WP-Cron.
	 *
	 * @param string $source Runner source.
	 * @return array
	 */
	public static function process_due_archive_purge( $source = 'real-cron' ) {
		$source   = sanitize_key( (string) $source );
		$interval = self::archive_purge_interval_minutes();
		$mode     = self::integration_mode();
		if ( self::MODE_OFF === $mode ) {
			/* Merchant disabled cache-plugin integration: never call purge APIs. */
			return array( 'success' => true, 'status' => 'disabled-by-mode', 'processed' => false, 'urlCount' => 0, 'productCount' => 0 );
		}
		$queue    = self::read_archive_queue();
		$now      = time();

		if ( $interval <= 0 ) {
			if ( ! empty( $queue['urls'] ) ) {
				if ( class_exists( 'Mobo_Core_State_Store' ) ) { Mobo_Core_State_Store::delete( self::STATE_ARCHIVE_QUEUE ); }
				self::delete_legacy_archive_queue();
			}
			return array( 'success' => true, 'status' => 'disabled', 'processed' => false, 'urlCount' => 0, 'productCount' => 0 );
		}

		if ( empty( $queue['urls'] ) ) {
			return array( 'success' => true, 'status' => 'empty', 'processed' => false, 'urlCount' => 0, 'productCount' => 0 );
		}

		$due_at = isset( $queue['dueAt'] ) ? absint( $queue['dueAt'] ) : 0;
		if ( $due_at > 0 && $now < $due_at ) {
			return array(
				'success'         => true,
				'status'          => 'waiting',
				'processed'       => false,
				'urlCount'        => count( $queue['urls'] ),
				'productCount'    => count( $queue['productIds'] ),
				'dueAt'           => $due_at,
				'secondsUntilDue' => max( 0, $due_at - $now ),
			);
		}

		$lock = self::acquire_archive_queue_lock();
		if ( false === $lock ) {
			return array(
				'success'      => false,
				'status'       => 'locked',
				'processed'    => false,
				'urlCount'     => count( $queue['urls'] ),
				'productCount' => count( $queue['productIds'] ),
				'dueAt'        => $due_at,
			);
		}

		$started          = microtime( true );
		$integration_data = array();
		$overall_status   = 'failed';
		$last_error       = '';
		$urls             = array();
		$product_ids      = array();
		$reasons          = array();
		$claim_token      = '';

		try {
			/* Re-read after acquiring the mutex; another request may have changed the generation. */
			$queue = self::read_archive_queue();
			$now   = time();
			$due_at = isset( $queue['dueAt'] ) ? absint( $queue['dueAt'] ) : 0;
			if ( empty( $queue['urls'] ) ) {
				self::release_archive_queue_lock( $lock );
				$lock = false;
				return array( 'success' => true, 'status' => 'not-due-after-lock', 'processed' => false, 'urlCount' => 0, 'productCount' => 0, 'dueAt' => $due_at );
			}
			if ( $due_at <= 0 ) {
				$due_at = $now + ( $interval * MINUTE_IN_SECONDS );
				$queue['dueAt']           = $due_at;
				$queue['intervalMinutes'] = $interval;
				$queue['updatedAt']       = $now;
				self::write_archive_queue( $queue );
				self::release_archive_queue_lock( $lock );
				$lock = false;
				return array( 'success' => true, 'status' => 'waiting', 'processed' => false, 'urlCount' => count( $queue['urls'] ), 'productCount' => count( $queue['productIds'] ), 'dueAt' => $due_at );
			}
			if ( $now < $due_at ) {
				self::release_archive_queue_lock( $lock );
				$lock = false;
				return array( 'success' => true, 'status' => 'not-due-after-lock', 'processed' => false, 'urlCount' => count( $queue['urls'] ), 'productCount' => count( $queue['productIds'] ), 'dueAt' => $due_at );
			}

			$processing_token = isset( $queue['processingToken'] ) ? trim( (string) $queue['processingToken'] ) : '';
			$processing_until = absint( isset( $queue['processingUntil'] ) ? $queue['processingUntil'] : 0 );
			if ( '' !== $processing_token && $processing_until > $now ) {
				self::release_archive_queue_lock( $lock );
				$lock = false;
				return array(
					'success'      => true,
					'status'       => 'processing',
					'processed'    => false,
					'urlCount'     => count( $queue['urls'] ),
					'productCount' => count( $queue['productIds'] ),
					'dueAt'        => $due_at,
				);
			}

			$all_urls        = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_url' ), $queue['urls'] ) ) ) );
			$all_product_ids = array_values( array_unique( array_filter( array_map( 'absint', $queue['productIds'] ) ) ) );
			$product_overflow = ! empty( $queue['productOverflow'] );
			$reasons     = array_values( array_unique( array_filter( array_map( 'sanitize_key', $queue['reasons'] ) ) ) );
			/* One bounded slice per runner pass; the remainder stays queued and is
			 * continued shortly after, so a 5000-URL mass change never becomes one
			 * long purge (and one huge preload wave) in a single request. */
			$per_pass    = self::max_deferred_urls_per_pass( $mode );
			$urls        = array_slice( $all_urls, 0, $per_pass );
			$product_ids = array_slice( $all_product_ids, 0, $per_pass );
			$has_more    = count( $all_urls ) > count( $urls ) || count( $all_product_ids ) > count( $product_ids );
			if ( $product_overflow && self::MODE_AGGRESSIVE !== $mode ) {
				/* Safe mode never escalates to a sitewide flush; pages beyond the
				 * bounded map expire through the cache plugin's own lifespan. */
				$product_overflow = false;
			}
			if ( empty( $urls ) ) {
				if ( class_exists( 'Mobo_Core_State_Store' ) ) { Mobo_Core_State_Store::delete( self::STATE_ARCHIVE_QUEUE ); }
				self::delete_legacy_archive_queue();
				self::release_archive_queue_lock( $lock );
				$lock = false;
				return array( 'success' => true, 'status' => 'empty-normalized', 'processed' => false, 'urlCount' => 0, 'productCount' => 0 );
			}

			$claim_token = wp_generate_uuid4();
			$queue['processingToken']      = $claim_token;
			$queue['processingUntil']      = time() + self::CLAIM_SECONDS;
			$queue['processingUrls']       = $urls;
			$queue['processingProductIds'] = $product_ids;
			$queue['requeueUrls']          = array();
			$queue['requeueProductIds']    = array();
			$queue['updatedAt']            = time();
			self::write_archive_queue( $queue );

			/* Third-party purge APIs/hooks may be slow. Never hold the queue mutex here. */
			self::release_archive_queue_lock( $lock );
			$lock = false;

			$inventory = self::get_current_integration_inventory();
			$integration_data['wpRocket'] = self::run_integration(
				'wpRocket',
				$inventory['wpRocket'],
				static function () use ( $product_ids, $urls, $mode ) {
					if ( ! self::purge_wp_rocket_archive_targets( $product_ids, $urls, self::MODE_AGGRESSIVE === $mode ) ) {
						throw new RuntimeException( 'WP Rocket archive purge API is unavailable.' );
					}
				}
			);
			$integration_data['liteSpeedCache'] = self::run_integration(
				'liteSpeedCache',
				$inventory['liteSpeedCache'],
				static function () use ( $product_ids, $urls ) {
					if ( ! self::purge_litespeed_archive_targets( $product_ids, $urls ) ) {
						throw new RuntimeException( 'LiteSpeed archive purge hooks are unavailable.' );
					}
				}
			);
			$integration_data['w3TotalCache'] = self::run_integration(
				'w3TotalCache',
				$inventory['w3TotalCache'],
				static function () use ( $product_ids, $urls ) {
					if ( ! self::purge_w3_total_cache_archive_targets( $product_ids, $urls ) ) {
						throw new RuntimeException( 'W3 Total Cache archive purge API is unavailable.' );
					}
				}
			);
			$integration_data['wpSuperCache'] = self::run_integration(
				'wpSuperCache',
				$inventory['wpSuperCache'],
				static function () use ( $product_ids, $product_overflow ) {
					if ( ! self::purge_wp_super_cache_archive_products( $product_ids, $product_overflow ) ) {
						throw new RuntimeException( 'WP Super Cache archive purge API is unavailable.' );
					}
				}
			);

			$context = array(
				'source'                      => $source,
				'urls'                        => $urls,
				'product_ids'                 => $product_ids,
				'reasons'                     => $reasons,
				'archivePurgeIntervalMinutes' => $interval,
				'dueAt'                       => $due_at,
				'integrationResults'          => $integration_data,
				'productOverflow'             => $product_overflow,
			);
			try {
				do_action( 'mobo_core_cache_purger_after_archive_flush', $context );
			} catch ( Throwable $e ) {
				self::log_warning( 'Deferred archive cache custom hook failed: ' . self::sanitize_error( $e->getMessage() ), array( 'integration' => 'custom-hooks' ) );
			}

			$overall_status = self::resolve_overall_status( $integration_data );
			$last_error     = self::resolve_last_integration_error( $integration_data );
		} catch ( Throwable $e ) {
			$last_error     = self::sanitize_error( $e->getMessage() );
			$overall_status = 'failed';
			self::log_warning( 'Deferred archive cache purge failed: ' . $last_error, array( 'integration' => 'mobo-core-archive-purger' ) );
		}

		/* Re-enter the short critical section only to commit the claimed generation. */
		if ( false === $lock ) {
			$lock = self::acquire_archive_queue_lock();
		}
		if ( false === $lock ) {
			$record = array(
				'schemaVersion'   => self::ARCHIVE_QUEUE_SCHEMA_VERSION,
				'pluginVersion'   => defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '',
				'source'          => $source,
				'status'          => 'commit-lock-lost',
				'attemptedAt'     => $now,
				'completedAt'     => time(),
				'intervalMinutes' => $interval,
				'urlCount'        => count( $urls ),
				'productCount'    => count( $product_ids ),
				'durationMs'      => self::elapsed_ms( $started ),
				'lastError'       => $last_error,
				'integrations'    => $integration_data,
			);
			self::persist_archive_last_result( $record );
			return array( 'success' => false, 'status' => 'commit-lock-lost', 'processed' => true, 'urlCount' => count( $urls ), 'productCount' => count( $product_ids ), 'lastError' => $last_error );
		}

		try {
			$current       = self::read_archive_queue();
			$current_token = isset( $current['processingToken'] ) ? (string) $current['processingToken'] : '';
			if ( '' === $claim_token || '' === $current_token || ! hash_equals( $claim_token, $current_token ) ) {
				/* A newer enqueue/settings mutation owns the durable snapshot now. */
				$overall_status = 'superseded';
			} elseif ( 'failed' !== $overall_status && 'partial' !== $overall_status ) {
				$remaining = self::remove_processed_batch( $current );
				if ( empty( $remaining['urls'] ) && empty( $remaining['productIds'] ) ) {
					if ( class_exists( 'Mobo_Core_State_Store' ) ) { Mobo_Core_State_Store::delete( self::STATE_ARCHIVE_QUEUE ); }
					self::delete_legacy_archive_queue();
				} else {
					/* Continue the coalesced queue soon, one bounded slice at a time. */
					$remaining['dueAt']           = time() + self::DEFERRED_CONTINUATION_SECONDS;
					$remaining['updatedAt']       = time();
					$remaining['attempts']        = 0;
					$remaining['intervalMinutes'] = $interval;
					self::write_archive_queue( $remaining );
				}
			} else {
				$current['dueAt']           = time() + ( $interval * MINUTE_IN_SECONDS );
				$current['updatedAt']       = time();
				$current['attempts']        = isset( $current['attempts'] ) ? absint( $current['attempts'] ) + 1 : 1;
				$current['intervalMinutes'] = $interval;
				$current = self::strip_claim( $current );
				self::write_archive_queue( $current );
			}

			$record = array(
				'schemaVersion'   => self::ARCHIVE_QUEUE_SCHEMA_VERSION,
				'pluginVersion'   => defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '',
				'source'          => $source,
				'status'          => $overall_status,
				'attemptedAt'     => $now,
				'completedAt'     => time(),
				'intervalMinutes' => $interval,
				'urlCount'        => count( $urls ),
				'productCount'    => count( $product_ids ),
				'hasMore'         => ! empty( $has_more ),
				'integrationMode' => $mode,
				'durationMs'      => self::elapsed_ms( $started ),
				'lastError'       => $last_error,
				'integrations'    => $integration_data,
			);
			self::persist_archive_last_result( $record );
		} finally {
			self::release_archive_queue_lock( $lock );
		}

		return array(
			'success'      => ! in_array( $overall_status, array( 'failed', 'partial' ), true ),
			'status'       => $overall_status,
			'processed'    => true,
			'urlCount'     => count( $urls ),
			'productCount' => count( $product_ids ),
			'hasMore'      => ! empty( $has_more ),
			'durationMs'   => self::elapsed_ms( $started ),
			'lastError'    => $last_error,
		);
	}

	/**
	 * Remove claim bookkeeping from a queue snapshot.
	 *
	 * @param array $queue Queue.
	 * @return array
	 */
	private static function strip_claim( $queue ) {
		$queue = is_array( $queue ) ? $queue : array();
		unset( $queue['processingToken'], $queue['processingUntil'], $queue['processingUrls'], $queue['processingProductIds'], $queue['requeueUrls'], $queue['requeueProductIds'] );
		return $queue;
	}

	/**
	 * Drop the successfully purged batch from the current queue snapshot while
	 * keeping targets that were re-enqueued during the claim (they changed again
	 * after or while being purged and must be purged once more).
	 *
	 * @param array $queue Current queue (owned claim).
	 * @return array
	 */
	private static function remove_processed_batch( $queue ) {
		$queue      = is_array( $queue ) ? $queue : array();
		$done_urls  = isset( $queue['processingUrls'] ) && is_array( $queue['processingUrls'] ) ? $queue['processingUrls'] : array();
		$done_ids   = isset( $queue['processingProductIds'] ) && is_array( $queue['processingProductIds'] ) ? array_map( 'absint', $queue['processingProductIds'] ) : array();
		$again_urls = isset( $queue['requeueUrls'] ) && is_array( $queue['requeueUrls'] ) ? $queue['requeueUrls'] : array();
		$again_ids  = isset( $queue['requeueProductIds'] ) && is_array( $queue['requeueProductIds'] ) ? array_map( 'absint', $queue['requeueProductIds'] ) : array();
		$urls       = isset( $queue['urls'] ) && is_array( $queue['urls'] ) ? $queue['urls'] : array();
		$ids        = isset( $queue['productIds'] ) && is_array( $queue['productIds'] ) ? array_map( 'absint', $queue['productIds'] ) : array();

		$queue['urls']       = array_values( array_unique( array_merge( array_diff( $urls, array_diff( $done_urls, $again_urls ) ) ) ) );
		$queue['productIds'] = array_values( array_unique( array_merge( array_diff( $ids, array_diff( $done_ids, $again_ids ) ) ) ) );
		if ( empty( $queue['productIds'] ) ) {
			$queue['productOverflow'] = false;
		}
		return self::strip_claim( $queue );
	}

	/**
	 * Compact persistent archive queue status for health/admin diagnostics.
	 *
	 * @return array
	 */
	public static function get_archive_queue_status() {
		$queue = self::read_archive_queue();
		$last  = self::read_archive_last_result();
		return array(
			'intervalMinutes' => self::archive_purge_interval_minutes(),
			'pendingUrlCount' => isset( $queue['urls'] ) && is_array( $queue['urls'] ) ? count( $queue['urls'] ) : 0,
			'pendingProductCount' => isset( $queue['productIds'] ) && is_array( $queue['productIds'] ) ? count( $queue['productIds'] ) : 0,
			'requiresSitewideFallback' => ! empty( $queue['productOverflow'] ) && self::MODE_AGGRESSIVE === self::integration_mode(),
			'productOverflow' => ! empty( $queue['productOverflow'] ),
			'urlOverflow'     => ! empty( $queue['urlOverflow'] ),
			'droppedUrlCount' => isset( $queue['droppedUrlCount'] ) ? absint( $queue['droppedUrlCount'] ) : 0,
			'lastHasMore'     => ! empty( $last['hasMore'] ),
			'queuedAt'        => self::format_timestamp( isset( $queue['queuedAt'] ) ? absint( $queue['queuedAt'] ) : 0 ),
			'dueAt'           => self::format_timestamp( isset( $queue['dueAt'] ) ? absint( $queue['dueAt'] ) : 0 ),
			'lastAttemptAt'   => self::format_timestamp( isset( $last['attemptedAt'] ) ? absint( $last['attemptedAt'] ) : 0 ),
			'lastCompletedAt' => self::format_timestamp( isset( $last['completedAt'] ) ? absint( $last['completedAt'] ) : 0 ),
			'lastStatus'      => isset( $last['status'] ) ? sanitize_key( (string) $last['status'] ) : 'never-run',
			'lastError'       => isset( $last['lastError'] ) ? self::sanitize_error( $last['lastError'] ) : '',
		);
	}

	private static function enqueue_deferred_archive_purge( $urls, $product_ids, $reasons, $interval ) {
		$interval = self::sanitize_archive_interval( $interval );
		$urls = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_url' ), (array) $urls ) ) ) );
		if ( $interval <= 0 || empty( $urls ) ) {
			return array( 'queued' => false, 'urlCount' => 0, 'productCount' => 0, 'dueAt' => 0 );
		}

		$lock = self::acquire_archive_queue_lock();
		if ( false === $lock ) {
			self::log_warning( 'Deferred archive purge targets could not acquire the queue lock; retrying on the next mutation.', array( 'integration' => 'mobo-core-archive-purger' ) );
			return array( 'queued' => false, 'urlCount' => 0, 'productCount' => 0, 'dueAt' => 0 );
		}

		try {
			$queue = self::read_archive_queue();
			$now   = time();
			$existing_urls = isset( $queue['urls'] ) && is_array( $queue['urls'] ) ? $queue['urls'] : array();
			$existing_products = isset( $queue['productIds'] ) && is_array( $queue['productIds'] ) ? $queue['productIds'] : array();
			$existing_reasons = isset( $queue['reasons'] ) && is_array( $queue['reasons'] ) ? $queue['reasons'] : array();

			$merged_urls = array_values( array_unique( array_merge( $existing_urls, $urls ) ) );
			$merged_products = array_values( array_unique( array_filter( array_map( 'absint', array_merge( $existing_products, (array) $product_ids ) ) ) ) );
			$merged_reasons = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_merge( $existing_reasons, (array) $reasons ) ) ) ) );
			$product_overflow = ! empty( $queue['productOverflow'] ) || count( $merged_products ) > self::ARCHIVE_QUEUE_MAX_PRODUCTS;

			$url_overflow     = ! empty( $queue['urlOverflow'] ) || count( $merged_urls ) > self::ARCHIVE_QUEUE_MAX_URLS;
			$dropped_urls     = ( isset( $queue['droppedUrlCount'] ) ? absint( $queue['droppedUrlCount'] ) : 0 ) + max( 0, count( $merged_urls ) - self::ARCHIVE_QUEUE_MAX_URLS );

			$merged_urls     = array_slice( $merged_urls, 0, self::ARCHIVE_QUEUE_MAX_URLS );
			$merged_products = array_slice( $merged_products, 0, self::ARCHIVE_QUEUE_MAX_PRODUCTS );
			$merged_reasons  = array_slice( $merged_reasons, 0, 50 );

			/*
			 * Keep a live processing claim. Previously every enqueue rewrote the
			 * snapshot without the token, so the in-flight pass was "superseded"
			 * and the whole queue was purged again; with bounded passes that would
			 * starve the remainder during a sync burst.
			 */
			$claim = array();
			$token = isset( $queue['processingToken'] ) ? trim( (string) $queue['processingToken'] ) : '';
			if ( '' !== $token && absint( isset( $queue['processingUntil'] ) ? $queue['processingUntil'] : 0 ) > $now ) {
				$processing_urls = isset( $queue['processingUrls'] ) && is_array( $queue['processingUrls'] ) ? $queue['processingUrls'] : array();
				$processing_ids  = isset( $queue['processingProductIds'] ) && is_array( $queue['processingProductIds'] ) ? array_map( 'absint', $queue['processingProductIds'] ) : array();
				$requeue_urls    = isset( $queue['requeueUrls'] ) && is_array( $queue['requeueUrls'] ) ? $queue['requeueUrls'] : array();
				$requeue_ids     = isset( $queue['requeueProductIds'] ) && is_array( $queue['requeueProductIds'] ) ? array_map( 'absint', $queue['requeueProductIds'] ) : array();
				$claim = array(
					'processingToken'      => $token,
					'processingUntil'      => absint( $queue['processingUntil'] ),
					'processingUrls'       => $processing_urls,
					'processingProductIds' => $processing_ids,
					'requeueUrls'          => array_values( array_unique( array_merge( $requeue_urls, array_intersect( $urls, $processing_urls ) ) ) ),
					'requeueProductIds'    => array_values( array_unique( array_merge( $requeue_ids, array_intersect( array_map( 'absint', (array) $product_ids ), $processing_ids ) ) ) ),
				);
			}

			$current_due = isset( $queue['dueAt'] ) ? absint( $queue['dueAt'] ) : 0;
			$new_due     = $now + ( $interval * MINUTE_IN_SECONDS );
			$due_at      = $current_due > 0 ? min( $current_due, $new_due ) : $new_due;

			$queue = array(
				'schemaVersion'   => self::ARCHIVE_QUEUE_SCHEMA_VERSION,
				'pluginVersion'   => defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '',
				'queuedAt'        => isset( $queue['queuedAt'] ) && absint( $queue['queuedAt'] ) > 0 ? absint( $queue['queuedAt'] ) : $now,
				'updatedAt'       => $now,
				'dueAt'           => $due_at,
				'intervalMinutes' => $interval,
				'attempts'        => isset( $queue['attempts'] ) ? absint( $queue['attempts'] ) : 0,
				'urls'            => $merged_urls,
				'productIds'      => $merged_products,
				'productOverflow' => $product_overflow,
				'urlOverflow'     => $url_overflow,
				'droppedUrlCount' => $dropped_urls,
				'reasons'         => $merged_reasons,
			);
			if ( ! empty( $claim ) ) {
				$queue = array_merge( $queue, $claim );
			}
			self::write_archive_queue( $queue );
			return array( 'queued' => true, 'urlCount' => count( $merged_urls ), 'productCount' => count( $merged_products ), 'dueAt' => $due_at );
		} finally {
			self::release_archive_queue_lock( $lock );
		}
	}

	private static function purge_wp_rocket_archive_targets( $product_ids, $urls, $allow_home = false ) {
		unset( $product_ids ); // Archive roots and deferred product URLs are purged directly.
		if ( ! function_exists( 'rocket_clean_files' ) && ! function_exists( 'rocket_clean_home' ) ) {
			return false;
		}
		$allow_home = $allow_home && (bool) apply_filters( 'mobo_core_cache_allow_home_purge', true );
		$home = trailingslashit( home_url( '/' ) );
		$files = array();
		$purge_home = false;
		foreach ( (array) $urls as $url ) {
			if ( trailingslashit( (string) $url ) === $home ) {
				$purge_home = true;
			} else {
				$files[] = $url;
			}
		}
		if ( ! empty( $files ) && function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( array_values( array_unique( $files ) ) );
		}
		if ( $purge_home && $allow_home && function_exists( 'rocket_clean_home' ) ) {
			// Home page + its pagination only (never rocket_clean_domain); aggressive mode only.
			rocket_clean_home();
		}
		return true;
	}

	private static function purge_litespeed_archive_targets( $product_ids, $urls ) {
		unset( $product_ids ); // Keep product page cache hot; purge only the queued archive URLs.
		if ( false === has_action( 'litespeed_purge_url' ) ) {
			return false;
		}
		foreach ( (array) $urls as $url ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party LiteSpeed hook.
			do_action( 'litespeed_purge_url', $url );
		}
		return true;
	}

	private static function purge_w3_total_cache_archive_targets( $product_ids, $urls ) {
		unset( $product_ids ); // Keep product page cache hot; purge only the queued archive URLs.
		if ( ! function_exists( 'w3tc_flush_url' ) ) {
			return false;
		}
		foreach ( (array) $urls as $url ) {
			w3tc_flush_url( $url );
		}
		return true;
	}

	private static function purge_wp_super_cache_archive_products( $product_ids, $overflow = false ) {
		if ( $overflow ) {
			// A bounded map cannot represent all archives after >5000 distinct products.
			// Use an explicit fallback; never silently claim the omitted IDs were purged.
			if ( ! function_exists( 'wp_cache_clear_cache' ) ) { return false; }
			wp_cache_clear_cache();
			return true;
		}
		if ( ! function_exists( 'wp_cache_post_change' ) ) {
			return false;
		}
		foreach ( (array) $product_ids as $product_id ) {
			$product_id = absint( $product_id );
			if ( $product_id > 0 ) {
				wp_cache_post_change( $product_id );
			}
		}
		return true;
	}

	private static function read_archive_queue() {
		$queue = array();
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() ) {
			if ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { Mobo_Core_State_Store::migrate_option( self::OPTION_ARCHIVE_QUEUE, self::STATE_ARCHIVE_QUEUE ); }
			$queue = Mobo_Core_State_Store::get( self::STATE_ARCHIVE_QUEUE, array() );
		} elseif ( ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) && self::legacy_default_owner_allowed() ) {
			$queue = get_option( self::OPTION_ARCHIVE_QUEUE, array() );
		}
		if ( ! is_array( $queue ) ) { $queue = array(); }
		$queue['urls']       = isset( $queue['urls'] ) && is_array( $queue['urls'] ) ? $queue['urls'] : array();
		$queue['productIds'] = isset( $queue['productIds'] ) && is_array( $queue['productIds'] ) ? $queue['productIds'] : array();
		$queue['reasons']    = isset( $queue['reasons'] ) && is_array( $queue['reasons'] ) ? $queue['reasons'] : array();
		return $queue;
	}

	private static function write_archive_queue( $queue ) {
		$queue = is_array( $queue ) ? $queue : array();
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() ) {
			return Mobo_Core_State_Store::set( self::STATE_ARCHIVE_QUEUE, $queue );
		}
		if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { return false; }
		if ( ! self::legacy_default_owner_allowed() ) { return false; }
		return class_exists( 'Mobo_Core_Durable_State_Policy' )
			? Mobo_Core_Durable_State_Policy::update_option_verified( self::OPTION_ARCHIVE_QUEUE, $queue, false )
			: update_option( self::OPTION_ARCHIVE_QUEUE, $queue, false );
	}

	private static function persist_archive_last_result( $record ) {
		$record = is_array( $record ) ? $record : array();
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() ) {
			Mobo_Core_State_Store::set( self::STATE_ARCHIVE_LAST_RESULT, $record );
			if ( self::legacy_default_owner_allowed() ) { delete_option( self::OPTION_ARCHIVE_LAST_RESULT ); }
			return;
		}
		if ( self::legacy_default_owner_allowed() ) { update_option( self::OPTION_ARCHIVE_LAST_RESULT, $record, false ); }
	}

	private static function read_archive_last_result() {
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() ) {
			if ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { Mobo_Core_State_Store::migrate_option( self::OPTION_ARCHIVE_LAST_RESULT, self::STATE_ARCHIVE_LAST_RESULT ); }
			$value = Mobo_Core_State_Store::get( self::STATE_ARCHIVE_LAST_RESULT, array() );
			return is_array( $value ) ? $value : array();
		}
		if ( ! self::legacy_default_owner_allowed() || ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) ) { return array(); }
		$value = get_option( self::OPTION_ARCHIVE_LAST_RESULT, array() );
		return is_array( $value ) ? $value : array();
	}

	private static function delete_legacy_archive_queue() {
		if ( self::legacy_default_owner_allowed() ) {
			delete_option( self::OPTION_ARCHIVE_QUEUE );
			wp_cache_delete( self::OPTION_ARCHIVE_QUEUE, 'options' );
		}
	}

	private static function legacy_default_owner_allowed() {
		return ! class_exists( 'Mobo_Core_Connection_Context' ) || ! method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) || Mobo_Core_Connection_Context::legacy_default_owner_allowed();
	}

	private static function acquire_archive_queue_lock() {
		if ( ! class_exists( 'Mobo_Core_Lock' ) ) {
			return '__mobo_no_lock__';
		}
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$token = Mobo_Core_Lock::acquire( 'cache_archive_purge_queue', 60 );
			if ( false !== $token ) {
				return $token;
			}
			if ( function_exists( 'usleep' ) ) {
				usleep( 50000 );
			}
		}
		return false;
	}

	private static function release_archive_queue_lock( $token ) {
		if ( '__mobo_no_lock__' === $token || false === $token || ! class_exists( 'Mobo_Core_Lock' ) ) {
			return;
		}
		Mobo_Core_Lock::release( 'cache_archive_purge_queue', $token );
	}

	private static function purge_wordpress_and_woocommerce( $object_ids, $product_ids ) {
		foreach ( $product_ids as $product_id ) {
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $product_id );
			}
		}

		foreach ( $object_ids as $object_id ) {
			clean_post_cache( $object_id );
		}
	}

	private static function purge_wp_rocket( $product_ids, $urls, $custom_urls, $archive_purge_enabled ) {
		$available = false;

		if ( $archive_purge_enabled && function_exists( 'rocket_clean_post' ) ) {
			$available = true;
			foreach ( $product_ids as $product_id ) {
				rocket_clean_post( $product_id );
			}
		}

		if ( function_exists( 'rocket_clean_files' ) ) {
			$target_urls = $archive_purge_enabled ? $custom_urls : $urls;
			$home_url    = trailingslashit( home_url( '/' ) );
			$file_urls   = array_values(
				array_filter(
					$target_urls,
					static function ( $url ) use ( $home_url ) {
						return trailingslashit( (string) $url ) !== $home_url;
					}
				)
			);

			if ( ! empty( $file_urls ) ) {
				$available = true;
				rocket_clean_files( $file_urls );
			}
		}

		if ( $archive_purge_enabled && function_exists( 'rocket_clean_home' ) ) {
			$available = true;
			rocket_clean_home();
		}

		return $available;
	}

	private static function purge_litespeed( $product_ids, $urls, $archive_purge_enabled ) {
		$has_post_hook = false !== has_action( 'litespeed_purge_post' );
		$has_url_hook  = false !== has_action( 'litespeed_purge_url' );

		if ( $archive_purge_enabled && $has_post_hook ) {
			foreach ( $product_ids as $product_id ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party LiteSpeed hook.
				do_action( 'litespeed_purge_post', $product_id );
			}
		}

		if ( $has_url_hook ) {
			foreach ( $urls as $url ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party LiteSpeed hook.
				do_action( 'litespeed_purge_url', $url );
			}
		}

		return $archive_purge_enabled ? ( $has_post_hook || $has_url_hook ) : $has_url_hook;
	}

	private static function purge_w3_total_cache( $product_ids, $urls, $archive_purge_enabled ) {
		$available = false;

		if ( $archive_purge_enabled && function_exists( 'w3tc_flush_post' ) ) {
			$available = true;
			foreach ( $product_ids as $product_id ) {
				w3tc_flush_post( $product_id );
			}
		}

		if ( function_exists( 'w3tc_flush_url' ) ) {
			$available = true;
			foreach ( $urls as $url ) {
				w3tc_flush_url( $url );
			}
		}

		return $available;
	}


	private static function purge_wp_super_cache( $product_ids, $archive_purge_enabled ) {
		if ( ! function_exists( 'wp_cache_post_change' ) ) {
			return false;
		}

		$related_pages_filter = static function () {
			return 0;
		};

		if ( ! $archive_purge_enabled ) {
			add_filter( 'wpsc_delete_related_pages_on_edit', $related_pages_filter, PHP_INT_MAX );
		}

		try {
			foreach ( $product_ids as $product_id ) {
				wp_cache_post_change( $product_id );
			}
		} finally {
			if ( ! $archive_purge_enabled ) {
				remove_filter( 'wpsc_delete_related_pages_on_edit', $related_pages_filter, PHP_INT_MAX );
			}
		}

		return true;
	}


	/**
	 * Queue exact current product pages for deferred warmup.
	 *
	 * @param array $product_ids Product IDs.
	 * @param array $inventory Current cache-plugin inventory.
	 * @param array $integration_data Purge results.
	 * @param array $reasons Mutation reasons.
	 * @return array
	 */
	private static function queue_product_warmups( $product_ids, $inventory, $integration_data, $reasons ) {
		$result = array(
			'queued'       => false,
			'queuedCount'  => 0,
			'pendingCount' => 0,
			'status'       => 'not-needed',
		);

		if ( empty( $product_ids ) || ! class_exists( 'Mobo_Core_Cache_Warmer' ) || ! Mobo_Core_Settings::enabled( 'mobo_core_cache_warmup_enabled', '0' ) ) {
			return $result;
		}

		$cache_keys = array( 'wpRocket', 'liteSpeedCache', 'w3TotalCache', 'wpSuperCache' );
		$detected   = false;
		$purged     = false;
		foreach ( $cache_keys as $key ) {
			if ( ! empty( $inventory[ $key ]['detected'] ) ) {
				$detected = true;
			}
			if ( ! empty( $integration_data[ $key ]['detected'] ) && isset( $integration_data[ $key ]['status'] ) && 'success' === $integration_data[ $key ]['status'] ) {
				$purged = true;
			}
		}

		// A trusted filter may decline to warm, never override absence/failure of
		// a recognized targeted page-cache purge (unsafe fail-open in UX52).
		$enabled = $detected && $purged && (bool) apply_filters(
			'mobo_core_cache_warmup_product_enabled',
			true,
			$product_ids,
			$integration_data,
			$reasons
		);
		if ( ! $enabled ) {
			$result['status'] = $detected ? 'purge-not-successful' : 'no-page-cache-plugin';
			return $result;
		}

		$queued = 0;
		$pending = 0;
		$admission = array( 'queued' => 0, 'skippedCold' => 0, 'capacityRejected' => 0, 'writeFailures' => 0 );
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) ) as $product_id ) {
			try {
				$enqueue = Mobo_Core_Cache_Warmer::enqueue_product( $product_id, '', 'targeted-product-purge' );
			} catch ( Throwable $e ) {
				$enqueue = array( 'success' => false, 'status' => 'exception', 'queued' => false );
			}
			if ( ! empty( $enqueue['queued'] ) ) {
				$queued++;
				$admission['queued']++;
			} elseif ( isset( $enqueue['status'] ) && in_array( $enqueue['status'], array( 'skipped-cold', 'skipped-nonpublic' ), true ) ) {
				$admission['skippedCold']++;
			} elseif ( isset( $enqueue['status'] ) && 'queue-capacity' === $enqueue['status'] ) {
				$admission['capacityRejected']++;
			} elseif ( empty( $enqueue['success'] ) ) {
				$admission['writeFailures']++;
			}
			if ( isset( $enqueue['pendingCount'] ) ) {
				$pending = max( $pending, absint( $enqueue['pendingCount'] ) );
			}
		}
		Mobo_Core_Cache_Warmer::record_admission_metrics( $admission );
		// Never aggregate the whole 20K-row queue on every product update; this
		// field is an O(1) admission hint. Site Health computes the exact count.

		$result['queued']       = $queued > 0;
		$result['queuedCount']  = $queued;
		$result['pendingCount'] = $pending;
		$result['status']       = $queued > 0 ? 'queued' : ( $admission['capacityRejected'] > 0 ? 'queue-capacity' : ( $admission['writeFailures'] > 0 ? 'queue-write-failed' : ( $admission['skippedCold'] > 0 ? 'skipped-cold' : 'nothing-queued' ) ) );
		$result['admission']    = $admission;

		/*
		 * Wake the local bounded runner once for the whole batch. This happens at
		 * shutdown after WordPress init, so it is safe to build the REST worker URL.
		 * If the self runner is disabled/throttled, the site's real cron will drain
		 * the same persistent queue on its next normal hit.
		 */
		if ( $queued > 0 ) {
			$recovery_pending = class_exists( 'Mobo_Core_Recovery_Coordinator' ) && Mobo_Core_Recovery_Coordinator::recovery_pending();
			if ( $recovery_pending ) {
				/* Recovery can enqueue hundreds of product URLs. Keep the deduplicated
				 * queue, but create only one post-recovery serial warmup intent. */
				Mobo_Core_Recovery_Coordinator::mark_post_recovery_warmup_pending();
				$result['status'] = 'queued-after-recovery';
			} elseif ( self::MODE_AGGRESSIVE !== self::integration_mode() ) {
				/* Safe mode: no extra loopback just for warmup; the next real cron
				 * or an already scheduled runner drains the bounded queue. */
				$result['status'] = 'queued-for-next-runner';
			} elseif ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
				try {
					Mobo_Core_Self_Runner::kick( 'cache-warmup-queued', false );
				} catch ( Throwable $e ) {
					self::log_warning( 'Product cache warmup was queued but the self-runner wake-up failed with an internal exception.', array( 'integration' => 'cache-warmup' ) );
				}
			}
		}

		return $result;
	}

	/**
	 * Return the last targeted purge result formatted for the health payload.
	 * No product IDs or URLs are exposed; only counts, versions and bounded errors.
	 *
	 * @return array
	 */
	public static function get_health_status() {
		$stored    = get_option( self::OPTION_LAST_RESULT, array() );
		$stored    = is_array( $stored ) ? $stored : array();
		$inventory = self::get_current_integration_inventory();
		$attempted = isset( $stored['attemptedAt'] ) ? absint( $stored['attemptedAt'] ) : 0;
		$last_success = isset( $stored['lastSuccessAt'] ) ? absint( $stored['lastSuccessAt'] ) : 0;
		$integrations = array();
		$stored_integrations = isset( $stored['integrations'] ) && is_array( $stored['integrations'] ) ? $stored['integrations'] : array();
		$archive_queue_status = self::get_archive_queue_status();
		$warmup_queue_status  = class_exists( 'Mobo_Core_Cache_Warmer' ) ? Mobo_Core_Cache_Warmer::get_status() : array();

		foreach ( $inventory as $key => $current ) {
			$previous = isset( $stored_integrations[ $key ] ) && is_array( $stored_integrations[ $key ] ) ? $stored_integrations[ $key ] : array();
			$tested_version  = isset( $previous['version'] ) ? (string) $previous['version'] : '';
			$current_version = isset( $current['version'] ) ? (string) $current['version'] : '';
			$status = $attempted > 0 && isset( $previous['status'] )
				? sanitize_key( (string) $previous['status'] )
				: ( ! empty( $current['detected'] ) ? 'not_tested' : 'not_detected' );

			$integrations[ $key ] = array(
				'label'                   => isset( $current['label'] ) ? (string) $current['label'] : $key,
				'detected'                => ! empty( $current['detected'] ),
				'status'                  => $status,
				'testedVersion'           => $tested_version,
				'currentVersion'          => $current_version,
				'versionChangedSinceTest' => $attempted > 0 && '' !== $tested_version && '' !== $current_version && $tested_version !== $current_version,
				'durationMs'              => isset( $previous['durationMs'] ) ? absint( $previous['durationMs'] ) : null,
				'error'                   => isset( $previous['error'] ) ? self::sanitize_error( $previous['error'] ) : '',
			);
		}

		if ( isset( $stored_integrations['moboCorePurger'] ) && is_array( $stored_integrations['moboCorePurger'] ) ) {
			$previous = $stored_integrations['moboCorePurger'];
			$integrations['moboCorePurger'] = array(
				'label'                   => 'Mobo Core cache purger',
				'detected'                => true,
				'status'                  => isset( $previous['status'] ) ? sanitize_key( (string) $previous['status'] ) : 'failed',
				'testedVersion'           => isset( $previous['version'] ) ? (string) $previous['version'] : '',
				'currentVersion'          => defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '',
				'versionChangedSinceTest' => isset( $previous['version'] ) && defined( 'MOBO_CORE_VERSION' ) && (string) $previous['version'] !== (string) MOBO_CORE_VERSION,
				'durationMs'              => isset( $previous['durationMs'] ) ? absint( $previous['durationMs'] ) : null,
				'error'                   => isset( $previous['error'] ) ? self::sanitize_error( $previous['error'] ) : '',
			);
		}

		return array(
			'schemaVersion'       => self::HEALTH_SCHEMA_VERSION,
			'pluginVersion'       => isset( $stored['pluginVersion'] ) ? (string) $stored['pluginVersion'] : '',
			'currentPluginVersion'=> defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '',
			'status'              => $attempted > 0 && isset( $stored['status'] ) ? sanitize_key( (string) $stored['status'] ) : 'never_run',
			'lastAttemptAt'       => self::format_timestamp( $attempted ),
			'lastSuccessAt'       => self::format_timestamp( $last_success ),
			'completedAt'         => self::format_timestamp( isset( $stored['completedAt'] ) ? absint( $stored['completedAt'] ) : 0 ),
			'productCount'        => isset( $stored['productCount'] ) ? absint( $stored['productCount'] ) : 0,
			'objectCount'         => isset( $stored['objectCount'] ) ? absint( $stored['objectCount'] ) : 0,
			'urlCount'            => isset( $stored['urlCount'] ) ? absint( $stored['urlCount'] ) : 0,
			'customUrlCount'      => isset( $stored['customUrlCount'] ) ? absint( $stored['customUrlCount'] ) : 0,
			'durationMs'          => isset( $stored['durationMs'] ) ? absint( $stored['durationMs'] ) : null,
			'consecutiveFailures' => isset( $stored['consecutiveFailures'] ) ? absint( $stored['consecutiveFailures'] ) : 0,
			'reasons'             => isset( $stored['reasons'] ) && is_array( $stored['reasons'] ) ? array_values( array_slice( $stored['reasons'], 0, 20 ) ) : array(),
			'lastError'           => isset( $stored['lastError'] ) ? self::sanitize_error( $stored['lastError'] ) : '',
			'integrationMode'     => self::integration_mode(),
			'burstActive'         => self::is_burst_active(),
			'deferredUrlCount'    => isset( $stored['deferredUrlCount'] ) ? absint( $stored['deferredUrlCount'] ) : 0,
			'staleUrlCount'       => isset( $stored['staleUrlCount'] ) ? absint( $stored['staleUrlCount'] ) : 0,
			'archiveQueue'        => $archive_queue_status,
			'warmupQueue'         => $warmup_queue_status,
			'integrations'        => $integrations,
		);
	}

	private static function run_integration( $key, $inventory, $callback ) {
		$detected = ! empty( $inventory['detected'] );
		$result = array(
			'label'      => isset( $inventory['label'] ) ? (string) $inventory['label'] : (string) $key,
			'detected'   => $detected,
			'version'    => isset( $inventory['version'] ) ? (string) $inventory['version'] : '',
			'status'     => $detected ? 'failed' : 'not_detected',
			'durationMs' => 0,
			'error'      => '',
		);

		if ( ! $detected ) {
			return $result;
		}

		$started = microtime( true );
		try {
			call_user_func( $callback );
			$result['status'] = 'success';
		} catch ( Throwable $e ) {
			$result['status'] = 'failed';
			$result['error']  = self::sanitize_error( $e->getMessage() );
			self::log_warning(
				$result['label'] . ' targeted cache purge failed: ' . $result['error'],
				array(
					'integration'       => (string) $key,
					'integrationVersion'=> $result['version'],
				)
			);
		}

		$result['durationMs'] = self::elapsed_ms( $started );
		return $result;
	}

	private static function run_custom_hooks_integration( $inventory, $context, $existing_error ) {
		$result = array(
			'label'      => isset( $inventory['label'] ) ? (string) $inventory['label'] : 'Custom cache purge hooks',
			'detected'   => ! empty( $inventory['detected'] ),
			'version'    => '',
			'status'     => ! empty( $inventory['detected'] ) ? 'success' : 'not_detected',
			'durationMs' => 0,
			'error'      => self::sanitize_error( $existing_error ),
		);

		if ( '' !== $result['error'] ) {
			$result['detected'] = true;
			$result['status']   = 'failed';
		}

		if ( empty( $inventory['detected'] ) ) {
			return $result;
		}

		$started = microtime( true );
		try {
			do_action( 'mobo_core_cache_purger_after_flush', $context );
		} catch ( Throwable $e ) {
			$error = self::sanitize_error( $e->getMessage() );
			$result['status'] = 'failed';
			$result['error']  = '' !== $result['error'] ? self::sanitize_error( $result['error'] . ' | ' . $error ) : $error;
			self::log_warning( 'A custom cache purge integration failed: ' . $error, array( 'integration' => 'custom-hooks' ) );
		}

		$result['durationMs'] = self::elapsed_ms( $started );
		return $result;
	}

	private static function get_current_integration_inventory() {
		$active_plugins = self::get_active_plugin_files();
		$woocommerce_version = defined( 'WC_VERSION' ) ? (string) WC_VERSION : self::read_plugin_version( 'woocommerce/woocommerce.php' );

		return array(
			'wordpressWooCommerce' => array(
				'label'    => 'WordPress / WooCommerce',
				'detected' => true,
				'version'  => 'WordPress ' . get_bloginfo( 'version' ) . ( '' !== $woocommerce_version ? ' / WooCommerce ' . $woocommerce_version : '' ),
			),
			'wpRocket' => array(
				'label'    => 'WP Rocket',
				'detected' => defined( 'WP_ROCKET_VERSION' ) || function_exists( 'rocket_clean_post' ) || in_array( 'wp-rocket/wp-rocket.php', $active_plugins, true ),
				'version'  => defined( 'WP_ROCKET_VERSION' ) ? (string) WP_ROCKET_VERSION : self::read_plugin_version( 'wp-rocket/wp-rocket.php' ),
			),
			'liteSpeedCache' => array(
				'label'    => 'LiteSpeed Cache',
				'detected' => defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_post' ) || in_array( 'litespeed-cache/litespeed-cache.php', $active_plugins, true ),
				'version'  => defined( 'LSCWP_V' ) ? (string) LSCWP_V : self::read_plugin_version( 'litespeed-cache/litespeed-cache.php' ),
			),
			'w3TotalCache' => array(
				'label'    => 'W3 Total Cache',
				'detected' => defined( 'W3TC_VERSION' ) || function_exists( 'w3tc_flush_post' ) || in_array( 'w3-total-cache/w3-total-cache.php', $active_plugins, true ),
				'version'  => defined( 'W3TC_VERSION' ) ? (string) W3TC_VERSION : self::read_plugin_version( 'w3-total-cache/w3-total-cache.php' ),
			),
			'wpSuperCache' => array(
				'label'    => 'WP Super Cache',
				'detected' => function_exists( 'wp_cache_post_change' ) || in_array( 'wp-super-cache/wp-cache.php', $active_plugins, true ),
				'version'  => self::read_plugin_version( 'wp-super-cache/wp-cache.php' ),
			),
			'customHooks' => array(
				'label'    => 'Custom cache purge hooks',
				'detected' => false !== has_action( 'mobo_core_cache_purger_after_flush' ) || false !== has_filter( 'mobo_core_cache_purge_urls' ) || false !== has_filter( 'mobo_core_cache_purge_home_enabled' ),
				'version'  => '',
			),
		);
	}

	private static function get_active_plugin_files() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active  = array_merge( $active, array_keys( $network ) );
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $active ) ) ) );
	}

	private static function read_plugin_version( $plugin_file ) {
		$path = trailingslashit( WP_PLUGIN_DIR ) . ltrim( (string) $plugin_file, '/\\' );
		if ( ! is_file( $path ) || ! function_exists( 'get_file_data' ) ) {
			return '';
		}
		$data = get_file_data( $path, array( 'Version' => 'Version' ), 'plugin' );
		return isset( $data['Version'] ) ? sanitize_text_field( (string) $data['Version'] ) : '';
	}

	private static function resolve_overall_status( $integrations ) {
		$success = 0;
		$failed  = 0;
		foreach ( $integrations as $integration ) {
			$status = isset( $integration['status'] ) ? (string) $integration['status'] : '';
			if ( 'success' === $status ) {
				$success++;
			} elseif ( 'failed' === $status ) {
				$failed++;
			}
		}
		if ( $failed > 0 && $success > 0 ) {
			return 'partial';
		}
		if ( $failed > 0 ) {
			return 'failed';
		}
		return 'success';
	}

	private static function resolve_last_integration_error( $integrations ) {
		$errors = array();
		foreach ( $integrations as $integration ) {
			if ( isset( $integration['status'], $integration['error'] ) && 'failed' === $integration['status'] && '' !== trim( (string) $integration['error'] ) ) {
				$label    = isset( $integration['label'] ) ? (string) $integration['label'] : 'Cache integration';
				$errors[] = $label . ': ' . (string) $integration['error'];
			}
		}
		return self::sanitize_error( implode( ' | ', array_slice( $errors, 0, 5 ) ) );
	}

	private static function persist_health_record( $record ) {
		try {
			$previous = get_option( self::OPTION_LAST_RESULT, array() );
			$previous = is_array( $previous ) ? $previous : array();
			$is_success = isset( $record['status'] ) && 'success' === $record['status'];
			$record['lastSuccessAt'] = $is_success
				? absint( $record['completedAt'] )
				: ( isset( $previous['lastSuccessAt'] ) ? absint( $previous['lastSuccessAt'] ) : 0 );
			$record['consecutiveFailures'] = $is_success
				? 0
				: ( isset( $previous['consecutiveFailures'] ) ? absint( $previous['consecutiveFailures'] ) + 1 : 1 );
			update_option( self::OPTION_LAST_RESULT, $record, false );
		} catch ( Throwable $e ) {
			self::log_warning( 'Cache purge completed but its health result could not be stored: ' . self::sanitize_error( $e->getMessage() ) );
		}
	}

	private static function reset_queue() {
		self::$suppressed_product_ids = array();
		self::$has_change  = false;
		self::$object_ids  = array();
		self::$product_ids = array();
		self::$urls        = array();
		self::$custom_urls = array();
		self::$archive_urls = array();
		self::$reasons           = array();
		self::$collection_errors = array();
	}

	private static function capture_collection_error( $stage, $error ) {
		$message = self::sanitize_error( sanitize_key( (string) $stage ) . ': ' . ( $error instanceof Throwable ? $error->getMessage() : (string) $error ) );
		if ( '' !== $message ) {
			self::$collection_errors[] = $message;
			self::log_warning( 'Cache target collection failed but product synchronization will continue: ' . $message, array( 'integration' => 'custom-hooks' ) );
		}
	}

	private static function sanitize_error( $message ) {
		$message = (string) $message;
		$replacements = array();
		if ( defined( 'ABSPATH' ) && '' !== (string) ABSPATH ) {
			$replacements[ (string) ABSPATH ] = '[ABSPATH]/';
		}
		if ( defined( 'WP_CONTENT_DIR' ) && '' !== (string) WP_CONTENT_DIR ) {
			$replacements[ (string) WP_CONTENT_DIR ] = '[WP_CONTENT_DIR]';
		}
		if ( defined( 'WP_PLUGIN_DIR' ) && '' !== (string) WP_PLUGIN_DIR ) {
			$replacements[ (string) WP_PLUGIN_DIR ] = '[WP_PLUGIN_DIR]';
		}
		if ( ! empty( $replacements ) ) {
			$message = strtr( $message, $replacements );
		}
		$message = preg_replace( '~https?://[^\s<>"\']+~i', '[url]', $message );
		$message = sanitize_text_field( (string) $message );
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $message, 0, self::ERROR_MAX_LENGTH );
		}
		return (string) substr( $message, 0, self::ERROR_MAX_LENGTH );
	}

	private static function elapsed_ms( $started ) {
		return max( 0, (int) round( ( microtime( true ) - (float) $started ) * 1000 ) );
	}

	private static function format_timestamp( $timestamp ) {
		$timestamp = absint( $timestamp );
		return $timestamp > 0 ? gmdate( 'c', $timestamp ) : null;
	}

	private static function archive_purge_interval_minutes() {
		$value = get_option( 'mobo_core_cache_archive_purge_interval_minutes', '15' );
		return self::sanitize_archive_interval( $value );
	}

	private static function sanitize_archive_interval( $value ) {
		if ( class_exists( 'Mobo_Core_Settings' ) && method_exists( 'Mobo_Core_Settings', 'sanitize_cache_archive_purge_interval' ) ) {
			return Mobo_Core_Settings::sanitize_cache_archive_purge_interval( $value, 15 );
		}

		$value = absint( $value );
		return in_array( $value, array( 0, 5, 10, 15, 20, 25, 30, 45, 60 ), true ) ? $value : 15;
	}

	private static function product_category_base() {
		$permalinks = get_option( 'woocommerce_permalinks', array() );
		$permalinks = is_array( $permalinks ) ? $permalinks : array();
		$base = isset( $permalinks['category_base'] ) ? trim( (string) $permalinks['category_base'], '/ ' ) : '';
		return '' !== $base ? $base : 'product-category';
	}

	private static function build_hierarchical_product_category_url( $term ) {
		if ( ! $term instanceof WP_Term || 'product_cat' !== $term->taxonomy ) {
			return '';
		}

		$slugs = array();
		$ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
		$ancestors = array_reverse( array_map( 'absint', (array) $ancestors ) );
		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'product_cat' );
			if ( $ancestor instanceof WP_Term && '' !== (string) $ancestor->slug ) {
				$slugs[] = $ancestor->slug;
			}
		}
		$slugs[] = $term->slug;
		$path = self::product_category_base() . '/' . implode( '/', array_filter( $slugs ) );
		return home_url( user_trailingslashit( $path, 'category' ) );
	}

	private static function capture_related_urls( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return;
		}

		$permalink = get_permalink( $product_id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			self::store_url( $permalink );
		}

		if ( self::archive_purge_interval_minutes() <= 0 ) {
			return;
		}

		foreach ( array( 'product_cat', 'product_tag' ) as $taxonomy ) {
			$terms = wp_get_post_terms( $product_id, $taxonomy );
			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}

				self::capture_term_archive_urls( $term, $taxonomy );
			}
		}

		$shop_url = '';
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop_url = wc_get_page_permalink( 'shop' );
		}

		if ( ( ! is_string( $shop_url ) || '' === $shop_url ) && function_exists( 'get_post_type_archive_link' ) ) {
			$shop_url = get_post_type_archive_link( 'product' );
		}

		if ( is_string( $shop_url ) && '' !== $shop_url ) {
			self::store_archive_url( $shop_url );
		}

		if ( apply_filters( 'mobo_core_cache_purge_home_enabled', true, $product_id ) ) {
			self::store_archive_url( home_url( '/' ) );
		}
	}

	private static function capture_term_archive_urls( $term, $taxonomy ) {
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$terms = array( $term );
		if ( 'product_cat' === $taxonomy ) {
			$ancestor_ids = array_reverse( array_map( 'absint', (array) get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) );
			foreach ( $ancestor_ids as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, 'product_cat' );
				if ( $ancestor instanceof WP_Term ) {
					$terms[] = $ancestor;
				}
			}
		}

		foreach ( $terms as $archive_term ) {
			$link = get_term_link( $archive_term, $taxonomy );
			if ( ! is_wp_error( $link ) ) {
				self::store_archive_url( $link );
			}
			if ( 'product_cat' === $taxonomy ) {
				self::store_archive_url( self::build_hierarchical_product_category_url( $archive_term ) );
			}
		}
	}

	private static function capture_term_taxonomy_urls( $term_taxonomy_ids, $taxonomy ) {
		$term_taxonomy_ids = is_array( $term_taxonomy_ids ) ? $term_taxonomy_ids : array();

		foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
			$term = get_term_by( 'term_taxonomy_id', absint( $term_taxonomy_id ), $taxonomy );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			self::capture_term_archive_urls( $term, $taxonomy );
		}
	}

	private static function resolve_product_ids( $object_id ) {
		$object_id = absint( $object_id );
		$result = array(
			'object_id'  => $object_id,
			'product_id' => 0,
		);

		if ( $object_id <= 0 ) {
			return $result;
		}

		$post_type = get_post_type( $object_id );
		if ( 'product' === $post_type ) {
			$result['product_id'] = $object_id;
			return $result;
		}

		if ( 'product_variation' === $post_type ) {
			$result['product_id'] = absint( wp_get_post_parent_id( $object_id ) );
		}

		return $result;
	}

	private static function is_mobo_linked_object( $object_id ) {
		$resolved = self::resolve_product_ids( $object_id );
		$candidates = array_values( array_unique( array_filter( array( $resolved['object_id'], $resolved['product_id'] ) ) ) );

		foreach ( $candidates as $candidate_id ) {
			foreach ( array( 'product_guid', 'variant_guid', 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id', 'mobo_url' ) as $meta_key ) {
				$value = get_post_meta( $candidate_id, $meta_key, true );
				if ( '' !== (string) $value && null !== $value ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function store_url( $url ) {
		$url = self::normalize_url( $url );
		if ( '' !== $url ) {
			self::$urls[ $url ] = true;
		}
	}

	private static function store_archive_url( $url ) {
		$url = self::normalize_url( $url );
		if ( '' !== $url ) {
			self::$archive_urls[ $url ] = true;
		}
	}

	private static function normalize_url( $url ) {
		$url = esc_url_raw( (string) $url );
		return is_string( $url ) ? $url : '';
	}

	private static function log_warning( $message, $context = array() ) {
		if ( class_exists( 'Mobo_Core_Logger' ) ) {
			$context = is_array( $context ) ? $context : array();
			$context['component']     = 'cache-purger';
			$context['pluginVersion'] = defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '';
			Mobo_Core_Logger::warning( $message, $context );
		}
	}
}
