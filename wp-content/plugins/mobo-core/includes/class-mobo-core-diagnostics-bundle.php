<?php
/**
 * Remote diagnostics bundle (POST /wp-json/mobo-core/v1/support/diagnostics).
 *
 * Lets support see what happened on a customer site without logging into it:
 * environment + capabilities, readiness, job diagnostics, the scrubbed error
 * ring buffer, recent webhook/sync/order events, queue depths, cron/worker state
 * and plugin file integrity. Every section is built independently and can fail
 * without breaking the others. The JSON is capped at MAX_BYTES by dropping the
 * oldest errors/events first.
 *
 * Contract (keys):
 *   generatedAt, sinceMinutes, sections, truncated, sizeBytes,
 *   plugin{version, dbVersion, migration{...}},
 *   environment{php, sapi, limits, capabilities, wp, wc, theme, activePlugins[{slug,version}],
 *               cachePlugins, objectCache, dbServer, charset},
 *   readiness[], jobs{sync, repair, reprice, recategorize, migration, imageRefresh},
 *   errors[], events[], queues{webhook, image, order, priceReview},
 *   cron{lastRun, realCron, selfRunner, loopback, lease},
 *   integrity{manifestOk, changedFiles[]}
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Diagnostics_Bundle {

	const MAX_BYTES             = 524288;
	const DEFAULT_SINCE_MINUTES = 1440;
	const MAX_SINCE_MINUTES     = 43200;
	const INTEGRITY_OPTION      = 'mobo_core_diag_integrity_files';
	const INTEGRITY_TTL         = 21600;

	/** @var string[] */
	const SECTIONS = array( 'environment', 'readiness', 'jobs', 'errors', 'events', 'queues', 'cron', 'integrity' );

	/** Known page-cache / optimization plugin slugs. */
	const CACHE_PLUGINS = array( 'wp-rocket', 'litespeed-cache', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'autoptimize', 'sg-cachepress', 'breeze', 'cache-enabler', 'hummingbird-performance', 'swift-performance-lite', 'swift-performance', 'nitropack', 'wp-optimize', 'comet-cache', 'powered-cache', 'flying-press', 'perfmatters', 'redis-cache', 'object-cache-pro', 'docket-cache', 'atec-cache-apcu' );

	/**
	 * Normalize the request body.
	 *
	 * @param mixed $body Decoded JSON body.
	 * @return array{sections:string[],sinceMinutes:int}
	 */
	public static function normalize_request( $body ) {
		$body     = is_array( $body ) ? $body : array();
		$sections = array();
		if ( isset( $body['sections'] ) && is_array( $body['sections'] ) ) {
			foreach ( $body['sections'] as $section ) {
				$section = is_scalar( $section ) ? sanitize_key( (string) $section ) : '';
				if ( in_array( $section, self::SECTIONS, true ) && ! in_array( $section, $sections, true ) ) {
					$sections[] = $section;
				}
			}
		}
		if ( empty( $sections ) ) {
			$sections = self::SECTIONS;
		}
		$since = isset( $body['sinceMinutes'] ) && is_numeric( $body['sinceMinutes'] ) ? (int) $body['sinceMinutes'] : self::DEFAULT_SINCE_MINUTES;
		return array(
			'sections'     => $sections,
			'sinceMinutes' => max( 5, min( self::MAX_SINCE_MINUTES, $since ) ),
		);
	}

	/**
	 * Build the capped bundle.
	 *
	 * @param string[] $sections      Sections.
	 * @param int      $since_minutes Look-back window.
	 * @param int      $max_bytes     Size cap.
	 * @return array
	 */
	public static function build( $sections, $since_minutes, $max_bytes = self::MAX_BYTES ) {
		$request  = self::normalize_request( array( 'sections' => $sections, 'sinceMinutes' => $since_minutes ) );
		$since_ts = time() - 60 * $request['sinceMinutes'];
		if ( class_exists( 'Mobo_Core_Error_Log' ) ) {
			Mobo_Core_Error_Log::flush();
		}
		$bundle = array(
			'generatedAt'  => gmdate( 'c' ),
			'sinceMinutes' => $request['sinceMinutes'],
			'sections'     => $request['sections'],
			'truncated'    => false,
			'sizeBytes'    => 0,
			'plugin'       => self::safe( array( __CLASS__, 'plugin_section' ) ),
		);
		foreach ( $request['sections'] as $section ) {
			$bundle[ $section ] = self::safe( array( __CLASS__, $section . '_section' ), $since_ts );
		}
		return self::cap( $bundle, $max_bytes );
	}

	/**
	 * Run one section builder; a failure becomes {"error": "..."}.
	 *
	 * @param callable $builder  Builder.
	 * @param int      $since_ts Since timestamp.
	 * @return mixed
	 */
	private static function safe( $builder, $since_ts = 0 ) {
		try {
			return call_user_func( $builder, $since_ts );
		} catch ( Throwable $e ) {
			return array( 'error' => 'section_failed', 'exceptionClass' => get_class( $e ) );
		}
	}

	/**
	 * Enforce the size cap: oldest errors/events first, then job event history,
	 * then changed file samples. Lists are newest-first.
	 *
	 * @param array $bundle    Bundle.
	 * @param int   $max_bytes Cap.
	 * @return array
	 */
	public static function cap( $bundle, $max_bytes = self::MAX_BYTES ) {
		$max_bytes = max( 4096, absint( $max_bytes ) );
		$size = self::size( $bundle );
		$guard = 0;
		while ( $size > $max_bytes && $guard++ < 2000 ) {
			$bundle['truncated'] = true;
			$errors = isset( $bundle['errors'] ) && is_array( $bundle['errors'] ) ? count( $bundle['errors'] ) : 0;
			$events = isset( $bundle['events'] ) && is_array( $bundle['events'] ) ? count( $bundle['events'] ) : 0;
			if ( $errors > 0 || $events > 0 ) {
				/* Drop roughly 10% of the larger list per pass (always >= 1). */
				$key = $errors >= $events ? 'errors' : 'events';
				$drop = max( 1, (int) floor( max( $errors, $events ) / 10 ) );
				$bundle[ $key ] = array_slice( $bundle[ $key ], 0, max( 0, count( $bundle[ $key ] ) - $drop ) );
			} elseif ( isset( $bundle['jobs'] ) && is_array( $bundle['jobs'] ) && self::trim_job_events( $bundle['jobs'] ) ) {
				// Trimmed job histories.
			} elseif ( ! empty( $bundle['integrity']['changedFiles'] ) ) {
				$bundle['integrity']['changedFiles'] = array_slice( $bundle['integrity']['changedFiles'], 0, (int) floor( count( $bundle['integrity']['changedFiles'] ) / 2 ) );
			} elseif ( isset( $bundle['environment']['capabilities']['disabledFunctionsAll'] ) && ! empty( $bundle['environment']['capabilities']['disabledFunctionsAll'] ) ) {
				$bundle['environment']['capabilities']['disabledFunctionsAll'] = array();
			} elseif ( isset( $bundle['environment']['activePlugins'] ) && count( (array) $bundle['environment']['activePlugins'] ) > 20 ) {
				$bundle['environment']['activePlugins'] = array_slice( $bundle['environment']['activePlugins'], 0, 20 );
			} else {
				break;
			}
			$size = self::size( $bundle );
		}
		$bundle['sizeBytes'] = $size;
		return $bundle;
	}

	/**
	 * Drop the oldest half of the longest job event history.
	 *
	 * @param array $jobs Jobs (by reference).
	 * @return bool Whether something was removed.
	 */
	private static function trim_job_events( &$jobs ) {
		$longest = '';
		$len = 0;
		foreach ( $jobs as $name => $job ) {
			$count = isset( $job['events'] ) && is_array( $job['events'] ) ? count( $job['events'] ) : 0;
			if ( $count > $len ) {
				$len = $count;
				$longest = (string) $name;
			}
		}
		if ( '' === $longest ) {
			return false;
		}
		$jobs[ $longest ]['events'] = array_slice( $jobs[ $longest ]['events'], (int) ceil( $len / 2 ) );
		return true;
	}

	/**
	 * JSON byte size.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public static function size( $value ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? strlen( $json ) : 0;
	}

	/* ------------------------------------------------------------------ *
	 * Sections
	 * ------------------------------------------------------------------ */

	/** @return array */
	public static function plugin_section() {
		return array(
			'version'   => defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '',
			'dbVersion' => (string) get_option( 'mobo_core_db_version', '' ),
			'migration' => class_exists( 'Mobo_Core_Migration_Quarantine' ) ? Mobo_Core_Migration_Quarantine::health_payload() : array( 'status' => 'unavailable' ),
		);
	}

	/** @return array */
	public static function environment_section() {
		global $wpdb;
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		$active = self::active_plugins();
		$slugs  = wp_list_pluck( $active, 'slug' );
		return array(
			'php'           => PHP_VERSION,
			'sapi'          => PHP_SAPI,
			'limits'        => array(
				'memoryLimit'      => Mobo_Core_Environment::ini( 'memory_limit' ),
				'maxExecutionTime' => Mobo_Core_Environment::ini( 'max_execution_time' ),
				'postMaxSize'      => Mobo_Core_Environment::ini( 'post_max_size' ),
				'uploadMaxFilesize'=> Mobo_Core_Environment::ini( 'upload_max_filesize' ),
				'maxInputVars'     => Mobo_Core_Environment::ini( 'max_input_vars' ),
				'wpMemoryLimit'    => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '',
			),
			'capabilities'  => class_exists( 'Mobo_Core_Environment_Probe' ) ? Mobo_Core_Environment_Probe::get() : array(),
			'wp'            => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			'wc'            => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'theme'         => is_object( $theme ) ? array(
				'name'    => sanitize_text_field( (string) $theme->get( 'Name' ) ),
				'version' => sanitize_text_field( (string) $theme->get( 'Version' ) ),
				'parent'  => $theme->parent() ? sanitize_text_field( (string) $theme->parent()->get( 'Name' ) ) : '',
			) : array(),
			'activePlugins' => $active,
			'cachePlugins'  => array_values( array_intersect( self::CACHE_PLUGINS, $slugs ) ),
			'objectCache'   => array(
				'external' => function_exists( 'wp_using_ext_object_cache' ) && (bool) wp_using_ext_object_cache(),
				'dropin'   => defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
			),
			'dbServer'      => is_object( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ? sanitize_text_field( (string) $wpdb->db_server_info() ) : ( is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '' ),
			'charset'       => array(
				'charset' => is_object( $wpdb ) && isset( $wpdb->charset ) ? (string) $wpdb->charset : '',
				'collate' => is_object( $wpdb ) && isset( $wpdb->collate ) ? (string) $wpdb->collate : '',
			),
		);
	}

	/**
	 * Active plugins as {slug, version}.
	 *
	 * @return array[]
	 */
	private static function active_plugins() {
		$files = (array) get_option( 'active_plugins', array() );
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$out = array();
		foreach ( array_unique( array_map( 'strval', $files ) ) as $file ) {
			$slug = false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
			$out[] = array(
				'slug'    => sanitize_key( $slug ),
				'version' => isset( $all[ $file ]['Version'] ) ? sanitize_text_field( (string) $all[ $file ]['Version'] ) : '',
			);
		}
		return array_slice( $out, 0, 100 );
	}

	/** @return array */
	public static function readiness_section() {
		return class_exists( 'Mobo_Core_Support_Access' ) ? Mobo_Core_Support_Access::health_readiness() : array();
	}

	/**
	 * Job diagnostics with their recent event history.
	 *
	 * @param int $since_ts Since.
	 * @return array
	 */
	public static function jobs_section( $since_ts = 0 ) {
		$jobs = array();
		$sync_status = class_exists( 'Mobo_Core_Product_Sync' ) ? ( new Mobo_Core_Product_Sync() )->get_manual_sync_status() : array();
		$sync_job    = class_exists( 'Mobo_Core_Job_Diagnostics' ) ? Mobo_Core_Job_Diagnostics::manual_sync_job( $sync_status ) : 'sync';
		foreach ( array( 'sync', 'repair' ) as $job ) {
			$current = $job === $sync_job;
			$jobs[ $job ] = self::job_entry(
				$job,
				$current ? (string) ( $sync_status['status'] ?? 'idle' ) : 'idle',
				$current ? absint( $sync_status['processedProducts'] ?? 0 ) : 0,
				$current ? absint( $sync_status['productTotalCount'] ?? 0 ) : 0,
				'manual_sync',
				$since_ts,
				$current ? array( 'syncId' => (string) ( $sync_status['syncId'] ?? '' ), 'lastMessage' => (string) ( $sync_status['lastMessage'] ?? '' ), 'lastError' => (string) ( $sync_status['lastError'] ?? '' ), 'currentProductId' => absint( $sync_status['currentProductId'] ?? 0 ), 'workerLock' => $sync_status['workerLock'] ?? array() ) : array()
			);
		}
		$jobs['reprice'] = class_exists( 'Mobo_Core_Reprice_Queue' ) ? array_merge( ( new Mobo_Core_Reprice_Queue() )->health_payload(), array( 'events' => self::job_events( 'reprice', $since_ts ) ) ) : array( 'status' => 'unavailable' );
		$jobs['recategorize'] = class_exists( 'Mobo_Core_Recategorize_Queue' ) ? array_merge( ( new Mobo_Core_Recategorize_Queue() )->health_payload(), array( 'events' => self::job_events( 'recategorize', $since_ts ) ) ) : array( 'status' => 'unavailable' );
		$jobs['migration'] = array(
			'dbVersion'     => (string) get_option( 'mobo_core_db_version', '' ),
			'targetVersion' => defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '',
			'quarantine'    => class_exists( 'Mobo_Core_Migration_Quarantine' ) ? Mobo_Core_Migration_Quarantine::health_payload() : array(),
		);
		$jobs['imageRefresh'] = class_exists( 'Mobo_Core_Image_Refresh_Automation' ) ? Mobo_Core_Image_Refresh_Automation::get_status() : array( 'status' => 'unavailable' );
		return $jobs;
	}

	/**
	 * One snapshot job entry.
	 *
	 * @return array
	 */
	private static function job_entry( $job, $status, $processed, $total, $lock, $since_ts, $extra ) {
		if ( ! class_exists( 'Mobo_Core_Job_Diagnostics' ) ) {
			return array( 'status' => $status );
		}
		$entry = Mobo_Core_Job_Diagnostics::health_payload_for( $job, $status, $processed, $total, $lock );
		$entry['statusLine'] = Mobo_Core_Job_Diagnostics::status_extras( $job, $status, $total > 0 ? ( $processed / $total ) * 100 : 0, $lock )['statusLine'];
		$entry['events'] = self::job_events( $job, $since_ts );
		return array_merge( $entry, Mobo_Core_Job_Diagnostics::sanitize_context( $extra ) );
	}

	/**
	 * Job diagnostic events since a timestamp (oldest first, as stored).
	 *
	 * @param string $job      Job.
	 * @param int    $since_ts Since.
	 * @return array
	 */
	private static function job_events( $job, $since_ts ) {
		if ( ! class_exists( 'Mobo_Core_Job_Diagnostics' ) ) {
			return array();
		}
		$data = Mobo_Core_Job_Diagnostics::open( $job )->data();
		$out  = array();
		foreach ( (array) $data['events'] as $event ) {
			if ( is_array( $event ) && absint( $event['at'] ?? 0 ) >= absint( $since_ts ) ) {
				$event['time'] = gmdate( 'c', absint( $event['at'] ?? 0 ) );
				$out[] = $event;
			}
		}
		return array_slice( $out, -50 );
	}

	/** @return array Newest first. */
	public static function errors_section( $since_ts = 0 ) {
		return class_exists( 'Mobo_Core_Error_Log' ) ? Mobo_Core_Error_Log::recent( $since_ts ) : array();
	}

	/** @return array Newest first. */
	public static function events_section( $since_ts = 0 ) {
		return class_exists( 'Mobo_Core_Error_Log' ) ? Mobo_Core_Error_Log::recent_events( $since_ts ) : array();
	}

	/** @return array */
	public static function queues_section() {
		$queues = array();
		try {
			$store = class_exists( 'Mobo_Core_Sync_Event_Store' ) ? new Mobo_Core_Sync_Event_Store() : null;
			$queues['webhook'] = $store && Mobo_Core_Sync_Event_Store::table_exists()
				? array( 'pending' => absint( $store->count_pending() ), 'failed' => absint( $store->count_failed() ), 'due' => absint( $store->count_due() ) )
				: array( 'status' => 'unavailable' );
		} catch ( Throwable $e ) {
			$queues['webhook'] = array( 'error' => get_class( $e ) );
		}
		try {
			$queues['image'] = class_exists( 'Mobo_Core_Image_Sync' ) ? ( new Mobo_Core_Image_Sync() )->get_queue_status() : array( 'status' => 'unavailable' );
		} catch ( Throwable $e ) {
			$queues['image'] = array( 'error' => get_class( $e ) );
		}
		$queues['order'] = class_exists( 'Mobo_Core_Work_Queue_Store' ) && class_exists( 'Mobo_Core_Checkout_Validator' )
			? array( 'queued' => Mobo_Core_Work_Queue_Store::count( Mobo_Core_Checkout_Validator::ORDER_QUEUE_TYPE ) )
			: array( 'status' => 'unavailable' );
		$review = array();
		if ( function_exists( 'wc_get_orders' ) ) {
			$review = wc_get_orders(
				array(
					'limit'      => 101,
					'status'     => array_keys( function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array( 'wc-processing' => '' ) ),
					'meta_key'   => '_mobo_order_submit_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded support diagnostics.
					'meta_value' => 'price_review', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded support diagnostics.
					'return'     => 'ids',
				)
			);
		}
		$review = is_array( $review ) ? $review : array();
		$queues['priceReview'] = array( 'pending' => min( 100, count( $review ) ), 'more' => count( $review ) > 100, 'orderIds' => array_slice( array_map( 'absint', $review ), 0, 20 ) );
		return $queues;
	}

	/** @return array */
	public static function cron_section() {
		$self = class_exists( 'Mobo_Core_Self_Runner' ) ? Mobo_Core_Self_Runner::get_status() : array();
		$probe = class_exists( 'Mobo_Core_Environment_Probe' ) ? Mobo_Core_Environment_Probe::get() : array();
		$last_hit = absint( get_option( 'mobo_core_real_cron_last_hit_at', 0 ) );
		return array(
			'lastRun'    => class_exists( 'Mobo_Core_Cron_Runner' ) ? Mobo_Core_Cron_Runner::get_health_status() : array(),
			'realCron'   => array(
				'lastHitAt'       => $last_hit > 0 ? gmdate( 'c', $last_hit ) : null,
				'disableWpCron'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			),
			'selfRunner' => is_array( $self ) ? Mobo_Core_Job_Diagnostics::sanitize_context( $self ) : array(),
			'loopback'   => isset( $probe['loopback'] ) ? (string) $probe['loopback'] : 'unknown',
			'lease'      => class_exists( 'Mobo_Core_Lock' ) ? array(
				'realCronRunner' => Mobo_Core_Lock::get_status( 'real_cron_runner' ),
				'manualSync'     => Mobo_Core_Lock::get_status( 'manual_sync' ),
				'repriceWorker'  => Mobo_Core_Lock::get_status( 'reprice_queue_worker' ),
				'recategorize'   => Mobo_Core_Lock::get_status( 'recategorize_queue_worker' ),
			) : array(),
		);
	}

	/** @return array */
	public static function integrity_section() {
		$ok = class_exists( 'Mobo_Core_Support_Access' ) ? Mobo_Core_Support_Access::plugin_integrity() : null;
		return array(
			'manifestOk'   => $ok,
			'changedFiles' => true === $ok ? array() : self::changed_files( defined( 'MOBO_CORE_PLUGIN_DIR' ) ? MOBO_CORE_PLUGIN_DIR : '', defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '' ),
		);
	}

	/**
	 * Manifest entries whose file is missing or differs (cached 6 h per version).
	 *
	 * @param string $root    Plugin root.
	 * @param string $version Version.
	 * @param int    $limit   Max rows.
	 * @param bool   $force   Skip the cache.
	 * @return array<int,array{path:string,status:string}>
	 */
	public static function changed_files( $root, $version, $limit = 100, $force = false ) {
		$cache = function_exists( 'get_option' ) ? get_option( self::INTEGRITY_OPTION, array() ) : array();
		if ( ! $force && is_array( $cache ) && isset( $cache['v'], $cache['at'], $cache['files'] ) && (string) $version === (string) $cache['v'] && ( time() - absint( $cache['at'] ) ) < self::INTEGRITY_TTL ) {
			return $cache['files'];
		}
		$root  = rtrim( (string) $root, '/\\' ) . '/';
		$file  = $root . 'mobo-core-manifest.json';
		$files = array();
		if ( '/' !== $root && is_readable( $file ) ) {
			$manifest = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			foreach ( is_array( $manifest ) && isset( $manifest['files'] ) && is_array( $manifest['files'] ) ? $manifest['files'] : array() as $relative => $expected ) {
				$relative = str_replace( '\\', '/', (string) $relative );
				if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
					continue;
				}
				$path = $root . ltrim( $relative, '/' );
				if ( ! is_file( $path ) ) {
					$files[] = array( 'path' => $relative, 'status' => 'missing' );
				} else {
					$actual = hash_file( 'sha256', $path );
					if ( ! is_string( $actual ) || ! hash_equals( strtolower( (string) $expected ), strtolower( $actual ) ) ) {
						$files[] = array( 'path' => $relative, 'status' => 'modified' );
					}
				}
				if ( count( $files ) >= max( 1, absint( $limit ) ) ) {
					break;
				}
			}
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::INTEGRITY_OPTION, array( 'v' => (string) $version, 'at' => time(), 'files' => $files ), false );
		}
		return $files;
	}
}
