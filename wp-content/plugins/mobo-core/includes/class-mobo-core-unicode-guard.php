<?php
/**
 * Per-column four-byte (supplementary-plane) Unicode write guard.
 *
 * Customer databases on cheap shared hosting frequently still run WordPress core
 * tables (wp_terms, wp_posts, wp_postmeta ...) on utf8 / utf8mb3. Such a column
 * cannot store a character above U+FFFF (emoji, rare CJK, math alphanumerics):
 * wpdb rejects the whole INSERT/UPDATE, wp_insert_term()/wp_insert_post() return
 * an error and the Source item was retried forever.
 *
 * This guard is consulted immediately before Mobo writes Source text into a WP
 * table. It detects whether the target column (and the live connection) supports
 * four-byte characters and, only when it does not, replaces each supplementary
 * character with its numeric HTML entity (&#x1f600;) - the exact format WordPress
 * core itself uses for utf8 post fields (wp_encode_emoji). The transform is:
 *
 *  - deterministic: the same Source text always yields the same stored text, so a
 *    compare against guarded Source text never reports a perpetual change;
 *  - idempotent: guarding an already guarded value is a no-op;
 *  - reversible: canonical() decodes the entities, so hashes can ignore it;
 *  - display preserving: browsers render the entity as the original glyph.
 *
 * When a column is later converted to utf8mb4 the guard stops transforming, the
 * next sync sees one genuine difference and writes the original character once.
 *
 * PHP 7.4 compatible. No WordPress dependency for the pure string helpers.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Unicode_Guard {

	const SUPPORT_OPTION = 'mobo_core_unicode_column_support';
	const EVENTS_OPTION  = 'mobo_core_unicode_guard_events';
	const SUPPORT_TTL    = 21600;
	const MAX_SAMPLES    = 10;

	/** @var array<string,bool> Request-local column support cache. */
	private static $support = array();

	/** @var array|null Request-local copy of the persisted support cache. */
	private static $persisted = null;

	/** @var array<string,bool> Columns already recorded during this request. */
	private static $recorded = array();

	/** @var callable|null Test seam: function( $table, $column ) returning a charset string. */
	private static $charset_resolver = null;

	/** @var string|null Test seam: connection charset override. */
	private static $connection_charset = null;

	/* ------------------------------------------------------------------
	 * Pure string helpers.
	 * ---------------------------------------------------------------- */

	/**
	 * Whether a string contains at least one character above U+FFFF.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function contains_supplementary( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		/* Lead bytes F0-F4 only ever start a four-byte UTF-8 sequence. A byte test
		 * is also correct for strings that are not valid UTF-8 (preg would fail). */
		return 1 === preg_match( '/[\xF0-\xF4]/', $value );
	}

	/**
	 * Replace every supplementary character with a lowercase hex numeric entity,
	 * matching WordPress core's wp_encode_emoji() output format.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function encode_supplementary( $value ) {
		$value = (string) $value;
		if ( ! self::contains_supplementary( $value ) ) {
			return $value;
		}
		$out = preg_replace_callback(
			'/[\xF0-\xF4][\x80-\xBF]{3}/',
			static function ( $m ) {
				$cp = self::utf8_codepoint( $m[0] );
				return $cp >= 0x10000 && $cp <= 0x10FFFF ? '&#x' . dechex( $cp ) . ';' : '';
			},
			$value
		);
		/* Remove any dangling invalid lead byte so the column write cannot fail. */
		return null === $out ? self::strip_supplementary( $value ) : preg_replace( '/[\xF0-\xF7]/', '', $out );
	}

	/**
	 * Remove supplementary characters (last resort when entities do not fit).
	 *
	 * @param string $value Value.
	 * @param string $replacement Replacement text.
	 * @return string
	 */
	public static function strip_supplementary( $value, $replacement = '' ) {
		$value = (string) $value;
		if ( ! self::contains_supplementary( $value ) ) {
			return $value;
		}
		$out = preg_replace( '/[\xF0-\xF4][\x80-\xBF]{0,3}/', (string) $replacement, $value );
		return null === $out ? '' : preg_replace( '/[\xF0-\xF7]/', '', $out );
	}

	/**
	 * Decode supplementary-plane numeric entities back to UTF-8. Lower-plane
	 * entities (&amp;, &#1575; ...) are deliberately left untouched.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function canonical( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::canonical( $item );
			}
			return $value;
		}
		if ( ! is_string( $value ) || false === strpos( $value, '&#' ) ) {
			return $value;
		}
		$out = preg_replace_callback(
			'/&#(?:x([0-9a-fA-F]{5,6})|([0-9]{5,7}));/',
			static function ( $m ) {
				$cp = '' !== $m[1] ? hexdec( $m[1] ) : (int) $m[2];
				if ( $cp < 0x10000 || $cp > 0x10FFFF ) {
					return $m[0];
				}
				return self::utf8_chr( (int) $cp );
			},
			$value
		);
		return null === $out ? $value : $out;
	}

	/**
	 * Equality that ignores the guard transform (raw Source vs stored value).
	 *
	 * @param mixed $source Source value.
	 * @param mixed $stored Stored value.
	 * @return bool
	 */
	public static function equivalent( $source, $stored ) {
		return self::canonical( $source ) === self::canonical( $stored );
	}

	/**
	 * Transform a value for a column that cannot hold four-byte characters.
	 *
	 * @param string $value Value.
	 * @param int    $max_chars Column character limit (0 = unlimited).
	 * @return string
	 */
	public static function downgrade( $value, $max_chars = 0 ) {
		$encoded = self::encode_supplementary( $value );
		if ( $max_chars > 0 && self::char_length( $encoded ) > $max_chars ) {
			return self::strip_supplementary( $value );
		}
		return $encoded;
	}

	/**
	 * Recursively downgrade strings inside arrays (serialized meta values).
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function downgrade_deep( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_string( $key ) ? self::downgrade( $key ) : $key ] = self::downgrade_deep( $item );
			}
			return $out;
		}
		return is_string( $value ) ? self::downgrade( $value ) : $value;
	}

	/**
	 * Whether a reported charset can store supplementary characters. Unknown
	 * single-byte charsets return true: the guard cannot help them and must not
	 * rewrite their data.
	 *
	 * @param mixed $charset Charset.
	 * @return bool
	 */
	public static function charset_supports_supplementary( $charset ) {
		$charset = strtolower( trim( (string) $charset ) );
		return ! in_array( $charset, array( 'utf8', 'utf8mb3', 'ucs2' ), true );
	}

	/* ------------------------------------------------------------------
	 * Column detection.
	 * ---------------------------------------------------------------- */

	/**
	 * Whether the live connection and the target column accept four-byte text.
	 *
	 * @param string $table Full table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	public static function column_supports_supplementary( $table, $column ) {
		$table  = (string) $table;
		$column = strtolower( (string) $column );
		$key    = strtolower( $table ) . '.' . $column;

		if ( isset( self::$support[ $key ] ) ) {
			return self::$support[ $key ];
		}

		if ( ! self::charset_supports_supplementary( self::connection_charset() ) ) {
			self::$support[ $key ] = false;
			return false;
		}

		$persisted = self::persisted_support();
		if ( isset( $persisted['columns'][ $key ] ) ) {
			self::$support[ $key ] = (bool) $persisted['columns'][ $key ];
			return self::$support[ $key ];
		}

		$charset = self::resolve_column_charset( $table, $column );
		if ( null === $charset ) {
			/* Unknown metadata: transform defensively. Entities are display-safe,
			 * while a failed write would stall the item. Do not persist a guess. */
			self::$support[ $key ] = false;
			return false;
		}

		$supported = '' === $charset || self::charset_supports_supplementary( $charset );
		self::$support[ $key ] = $supported;
		self::persist_support( $key, $supported, $charset );
		return $supported;
	}

	/**
	 * Guard one string for one target column.
	 *
	 * @param mixed  $value Value.
	 * @param string $table Full table name.
	 * @param string $column Column.
	 * @param string $context Diagnostic context.
	 * @param int    $max_chars Column character limit.
	 * @return mixed
	 */
	public static function prepare_for_column( $value, $table, $column, $context = '', $max_chars = 0 ) {
		if ( ! self::contains_supplementary( $value ) ) {
			return $value;
		}
		if ( self::column_supports_supplementary( $table, $column ) ) {
			return $value;
		}
		$out = self::downgrade( $value, $max_chars );
		self::record_event( $table, $column, $context );
		return $out;
	}

	/** @return mixed */
	public static function prepare_term_name( $value, $context = 'term-name' ) {
		global $wpdb;
		return self::prepare_for_column( $value, self::table( 'terms', $wpdb ), 'name', $context, 200 );
	}

	/** @return mixed */
	public static function prepare_post_field( $value, $field = 'post_title', $context = 'post' ) {
		global $wpdb;
		return self::prepare_for_column( $value, self::table( 'posts', $wpdb ), (string) $field, $context );
	}

	/**
	 * Guard a text fragment that will be stored inside wp_postmeta.meta_value
	 * (directly or inside a serialized array such as _product_attributes).
	 *
	 * @return mixed
	 */
	public static function prepare_meta_text( $value, $context = 'postmeta' ) {
		global $wpdb;
		return self::prepare_for_column( $value, self::table( 'postmeta', $wpdb ), 'meta_value', $context );
	}

	/**
	 * JSON for wp_postmeta: keep raw UTF-8 when possible, otherwise escape only
	 * the supplementary characters as \uXXXX surrogate pairs (json_decode returns
	 * the identical original structure).
	 *
	 * @param mixed  $data Data.
	 * @param int    $flags json flags (JSON_UNESCAPED_UNICODE is expected).
	 * @param string $context Context.
	 * @return string|false
	 */
	public static function json_for_meta( $data, $flags = 0, $context = 'postmeta-json' ) {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data, $flags ) : json_encode( $data, $flags );
		if ( false === $json || ! self::contains_supplementary( $json ) ) {
			return $json;
		}
		global $wpdb;
		$table = self::table( 'postmeta', $wpdb );
		if ( self::column_supports_supplementary( $table, 'meta_value' ) ) {
			return $json;
		}
		self::record_event( $table, 'meta_value', $context );
		return self::json_escape_supplementary( $json );
	}

	/**
	 * Escape supplementary characters in a JSON document as surrogate pairs.
	 *
	 * @param string $json JSON.
	 * @return string
	 */
	public static function json_escape_supplementary( $json ) {
		$out = preg_replace_callback(
			'/[\xF0-\xF4][\x80-\xBF]{3}/',
			static function ( $m ) {
				$cp = self::utf8_codepoint( $m[0] ) - 0x10000;
				if ( $cp < 0 ) {
					return '';
				}
				return sprintf( '\\u%04x\\u%04x', 0xD800 + ( $cp >> 10 ), 0xDC00 + ( $cp & 0x3FF ) );
			},
			(string) $json
		);
		return null === $out ? (string) $json : $out;
	}

	/* ------------------------------------------------------------------
	 * Diagnostics / readiness.
	 * ---------------------------------------------------------------- */

	/**
	 * Check the core columns Mobo writes and return a compact snapshot. Uses the
	 * persisted 6h support cache, so this is cheap enough for readiness reads.
	 *
	 * @return array
	 */
	public static function snapshot() {
		global $wpdb;
		$columns = array(
			array( 'terms', 'name' ),
			array( 'terms', 'slug' ),
			array( 'term_taxonomy', 'description' ),
			array( 'termmeta', 'meta_value' ),
			array( 'posts', 'post_title' ),
			array( 'posts', 'post_content' ),
			array( 'posts', 'post_excerpt' ),
			array( 'postmeta', 'meta_value' ),
		);
		$unsupported = array();
		$connection  = self::connection_charset();
		if ( is_object( $wpdb ) ) {
			foreach ( $columns as $pair ) {
				$table = self::table( $pair[0], $wpdb );
				if ( '' === $table ) {
					continue;
				}
				if ( ! self::column_supports_supplementary( $table, $pair[1] ) ) {
					$unsupported[] = $table . '.' . $pair[1];
				}
			}
		}
		$events = self::events();
		return array(
			'connectionCharset'   => sanitize_key( (string) $connection ),
			'connectionSupports'  => self::charset_supports_supplementary( $connection ),
			'unsupportedColumns'  => $unsupported,
			'replacements'        => absint( isset( $events['count'] ) ? $events['count'] : 0 ),
			'lastReplacementAt'   => absint( isset( $events['lastAt'] ) ? $events['lastAt'] : 0 ),
			'replacementColumns'  => isset( $events['columns'] ) && is_array( $events['columns'] ) ? $events['columns'] : array(),
			'samples'             => isset( $events['samples'] ) && is_array( $events['samples'] ) ? $events['samples'] : array(),
			'status'              => empty( $unsupported ) ? 'ok' : 'degraded',
		);
	}

	/** @return array */
	public static function health_payload() {
		try {
			$snapshot = self::snapshot();
		} catch ( Throwable $e ) {
			return array( 'status' => 'unknown', 'error' => 'snapshot-failed' );
		}
		$snapshot['recommendation'] = 'ok' === $snapshot['status'] ? '' : 'run-utf8mb4-conversion';
		return $snapshot;
	}

	/** @return array */
	public static function events() {
		$events = function_exists( 'get_option' ) ? get_option( self::EVENTS_OPTION, array() ) : array();
		return is_array( $events ) ? $events : array();
	}

	/**
	 * Forget column detection (after a DDL conversion step).
	 *
	 * @param bool $clear_events Also clear the replacement counters.
	 * @return void
	 */
	public static function reset_cache( $clear_events = false ) {
		self::$support   = array();
		self::$persisted = null;
		self::$recorded  = array();
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::SUPPORT_OPTION );
			if ( $clear_events ) {
				delete_option( self::EVENTS_OPTION );
			}
		}
	}

	/**
	 * Test seam. Pass null to restore the live wpdb resolver.
	 *
	 * @param callable|null $resolver function( $table, $column ): ?string.
	 * @param string|null   $connection_charset Connection charset override.
	 * @return void
	 */
	public static function set_test_resolver( $resolver, $connection_charset = null ) {
		self::$charset_resolver   = $resolver;
		self::$connection_charset = $connection_charset;
		self::$support            = array();
		self::$persisted          = null;
		self::$recorded           = array();
	}

	/* ------------------------------------------------------------------
	 * Internals.
	 * ---------------------------------------------------------------- */

	/** @return string */
	private static function connection_charset() {
		if ( null !== self::$connection_charset ) {
			return (string) self::$connection_charset;
		}
		global $wpdb;
		return is_object( $wpdb ) && isset( $wpdb->charset ) ? (string) $wpdb->charset : 'utf8mb4';
	}

	/**
	 * @param string $table Table.
	 * @param string $column Column.
	 * @return string|null '' = not a text column, null = unknown.
	 */
	private static function resolve_column_charset( $table, $column ) {
		if ( is_callable( self::$charset_resolver ) ) {
			$charset = call_user_func( self::$charset_resolver, $table, $column );
			return null === $charset ? null : strtolower( (string) $charset );
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col_charset' ) ) {
			return null;
		}
		$old_error = isset( $wpdb->last_error ) ? $wpdb->last_error : '';
		$charset   = $wpdb->get_col_charset( $table, $column );
		$wpdb->last_error = $old_error;
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $charset ) ) {
			return null;
		}
		if ( false === $charset ) {
			return '';
		}
		return strtolower( (string) $charset );
	}

	/** @return string */
	private static function schema_signature() {
		global $wpdb;
		$db     = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		$prefix = is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		return md5( $db . '|' . $prefix . '|' . self::connection_charset() );
	}

	/** @return array */
	private static function persisted_support() {
		if ( null !== self::$persisted ) {
			return self::$persisted;
		}
		self::$persisted = array( 'sig' => '', 'at' => 0, 'columns' => array() );
		if ( is_callable( self::$charset_resolver ) || ! function_exists( 'get_option' ) ) {
			return self::$persisted;
		}
		$stored = get_option( self::SUPPORT_OPTION, array() );
		$now    = time();
		if ( is_array( $stored )
			&& isset( $stored['sig'], $stored['at'], $stored['columns'] )
			&& self::schema_signature() === $stored['sig']
			&& is_array( $stored['columns'] )
			&& ( $now - absint( $stored['at'] ) ) < self::SUPPORT_TTL ) {
			self::$persisted = $stored;
		}
		return self::$persisted;
	}

	/** @return void */
	private static function persist_support( $key, $supported, $charset ) {
		if ( is_callable( self::$charset_resolver ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$current = self::persisted_support();
		if ( '' === (string) $current['sig'] ) {
			$current = array( 'sig' => self::schema_signature(), 'at' => time(), 'columns' => array(), 'charsets' => array() );
		}
		$current['columns'][ $key ] = (bool) $supported;
		$current['charsets'][ $key ] = substr( sanitize_key( (string) $charset ), 0, 32 );
		if ( count( $current['columns'] ) > 64 ) {
			$current['columns']  = array_slice( $current['columns'], -64, null, true );
			$current['charsets'] = array_slice( $current['charsets'], -64, null, true );
		}
		self::$persisted = $current;
		update_option( self::SUPPORT_OPTION, $current, false );
	}

	/**
	 * Count a replacement and log once per column per day.
	 *
	 * @return void
	 */
	private static function record_event( $table, $column, $context ) {
		$key = strtolower( (string) $table ) . '.' . strtolower( (string) $column );
		if ( isset( self::$recorded[ $key ] ) ) {
			return;
		}
		self::$recorded[ $key ] = true;
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}
		$now    = time();
		$events = self::events();
		$events['count']   = absint( isset( $events['count'] ) ? $events['count'] : 0 ) + 1;
		$events['firstAt'] = absint( isset( $events['firstAt'] ) ? $events['firstAt'] : 0 ) ?: $now;
		$events['lastAt']  = $now;
		if ( ! isset( $events['columns'] ) || ! is_array( $events['columns'] ) ) {
			$events['columns'] = array();
		}
		$events['columns'][ $key ] = absint( isset( $events['columns'][ $key ] ) ? $events['columns'][ $key ] : 0 ) + 1;
		if ( ! isset( $events['samples'] ) || ! is_array( $events['samples'] ) ) {
			$events['samples'] = array();
		}
		$events['samples'][] = array( 'column' => $key, 'context' => substr( sanitize_key( (string) $context ), 0, 48 ), 'at' => $now );
		$events['samples']   = array_slice( $events['samples'], -self::MAX_SAMPLES );
		if ( ! isset( $events['loggedAt'] ) || ! is_array( $events['loggedAt'] ) ) {
			$events['loggedAt'] = array();
		}
		$log = ( $now - absint( isset( $events['loggedAt'][ $key ] ) ? $events['loggedAt'][ $key ] : 0 ) ) >= DAY_IN_SECONDS;
		if ( $log ) {
			$events['loggedAt'][ $key ] = $now;
		}
		update_option( self::EVENTS_OPTION, $events, false );

		if ( $log && class_exists( 'Mobo_Core_Logger' ) ) {
			Mobo_Core_Logger::warning(
				'ستون ' . $key . ' از کاراکترهای ۴ بایتی (ایموجی) پشتیبانی نمی‌کند (utf8/utf8mb3)؛ این کاراکترها هنگام همگام‌سازی به‌صورت موجودیت HTML ذخیره شدند تا Sync متوقف نشود. ابزار «تبدیل دیتابیس به utf8mb4» را اجرا کنید.',
				array( 'column' => $key, 'context' => sanitize_key( (string) $context ) )
			);
		}
	}

	/** @return string */
	private static function table( $name, $wpdb ) {
		if ( is_object( $wpdb ) && isset( $wpdb->{$name} ) && '' !== (string) $wpdb->{$name} ) {
			return (string) $wpdb->{$name};
		}
		$prefix = is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		return $prefix . $name;
	}

	/** @return int */
	private static function char_length( $value ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( (string) $value, 'UTF-8' );
		}
		/* Count UTF-8 lead bytes (everything except 10xxxxxx continuation bytes). */
		return strlen( (string) $value ) - preg_match_all( '/[\x80-\xBF]/', (string) $value );
	}

	/** @return int */
	private static function utf8_codepoint( $bytes ) {
		$b = array_values( unpack( 'C*', $bytes ) );
		if ( 4 !== count( $b ) ) {
			return -1;
		}
		return ( ( $b[0] & 0x07 ) << 18 ) | ( ( $b[1] & 0x3F ) << 12 ) | ( ( $b[2] & 0x3F ) << 6 ) | ( $b[3] & 0x3F );
	}

	/** @return string */
	private static function utf8_chr( $cp ) {
		return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}
}
