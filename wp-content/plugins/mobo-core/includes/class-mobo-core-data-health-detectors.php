<?php
/**
 * Data health detectors: read-only, chunked queries for every corruption class
 * that can exist on 10.33.67 / 10.34.77 sites.
 *
 * Every detector works in bounded steps (an ID window, a GUID cursor or a parent
 * cursor) and returns its findings plus the next cursor, so the scanner can stop
 * at any step boundary and resume in the next web request. Nothing here writes.
 *
 * SQL is deliberately portable (no CONCAT/JSON/INTERVAL/UTC_TIMESTAMP, LIKE
 * without escapes): the same statements run on MySQL/MariaDB and in the SQLite
 * test harness. Time thresholds are computed in PHP (UTC). Strings from different
 * tables are compared as BINARY: customer sites mix utf8mb4_unicode_ci (core
 * tables) with utf8mb4_unicode_520_ci (plugin tables), which makes UNION/=
 * fail with "Illegal mix of collations"; GUIDs are byte-exact identities anyway.
 *
 * Repair classes:
 *  a = automatic & safe (idempotent, reversible, never customer-visible removal)
 *  b = admin-confirmed (dry-run with exact IDs, backup, apply, undo)
 *  c = report / support only
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Data_Health_Detectors {

	/** Post-ID window per step for ordinary detectors. */
	const WINDOW = 4000;

	/** Smaller window where each row costs a filesystem probe. */
	const FILE_WINDOW = 400;

	/** GUID groups per step. */
	const GROUP_LIMIT = 100;

	/** Variable parents per step for attribute-set grouping. */
	const PARENT_LIMIT = 60;

	/** IN() list chunk. */
	const IN_CHUNK = 500;

	/** Live (non-trash) post statuses for SQL. */
	const LIVE = "('trash','auto-draft')";

	/** Product taxonomies whose term relationships must point at a post. */
	const PRODUCT_TAXONOMIES = array( 'product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class' );

	/** @var array<string,bool> */
	private static $table_cache = array();

	/** @var array<string,array> */
	private static $column_cache = array();

	/** @return void */
	public static function reset_request_cache() {
		self::$table_cache  = array();
		self::$column_cache = array();
	}

	/**
	 * Ordered detector registry. Labels are Persian (admin UI); ids are stable
	 * (health payload keys, findings, backups).
	 *
	 * @return array<string,array>
	 */
	public static function definitions() {
		return array(
			'dup_product_guid'          => array( 'group' => 'identity', 'class' => 'b', 'risk' => 'medium', 'action' => 'hide_copy', 'cursor' => 'str', 'label' => 'محصول تکراری با شناسه موبو یکسان', 'fix' => 'نسخه اصلی (صاحب نگاشت) حفظ می‌شود؛ کپی‌ها با تأیید مدیر ناموجود، غیرقابل‌خرید و پنهان از فهرست می‌شوند (نشانی حفظ می‌شود) و شناسه تکراری‌شان به پشتیبان منتقل می‌شود. حذف نمی‌شود.' ),
			'dup_variant_guid'          => array( 'group' => 'identity', 'class' => 'b', 'risk' => 'medium', 'action' => 'disable_variation', 'cursor' => 'str', 'label' => 'تنوع تکراری با شناسه موبو یکسان', 'fix' => 'تنوع اصلی حفظ می‌شود؛ تکراری‌ها با تأیید مدیر غیرفعال (خصوصی) و ناموجود می‌شوند و شناسه‌شان به پشتیبان منتقل می‌شود. حذف نمی‌شود.' ),
			'unmapped_object'           => array( 'group' => 'identity', 'class' => 'a', 'risk' => 'low', 'action' => 'seed_map', 'cursor' => 'int', 'label' => 'محصول/تنوع موبو بدون نگاشت', 'fix' => 'اگر شناسه یکتا باشد، نگاشت به‌صورت خودکار ساخته می‌شود؛ اگر تکراری باشد زیر «تکراری‌ها» حل می‌شود؛ بعد از Migration به صف quarantine/reconciler سپرده می‌شود.' ),
			'broken_map_row'            => array( 'group' => 'identity', 'class' => 'a', 'risk' => 'low', 'action' => 'delete_row', 'cursor' => 'int', 'label' => 'ردیف نگاشت شکسته (پست حذف‌شده یا نوع غلط)', 'fix' => 'ردیف نگاشت بی‌صاحب با پشتیبان حذف می‌شود؛ محصول دست نمی‌خورد. قابل برگشت.' ),
			'custom_identity_dangling'  => array( 'group' => 'identity', 'class' => 'c', 'risk' => 'low', 'action' => '', 'cursor' => 'str', 'label' => 'ردیف جدول جدید محصولات بدون پست یا با والد نامطابق', 'fix' => 'reconciler ذخیره‌سازی آن را بازسازی می‌کند؛ فقط گزارش.' ),
			'orphan_variation'          => array( 'group' => 'topology', 'class' => 'b', 'risk' => 'low', 'action' => 'disable_variation', 'cursor' => 'int', 'label' => 'تنوع یتیم (والد ندارد یا والد زباله‌دان است)', 'fix' => 'تنوع منتشرشده‌ای که والد ندارد با تأیید مدیر غیرفعال می‌شود؛ تنوع زیر والد زباله‌دان فقط گزارش می‌شود.' ),
			'variation_under_simple'    => array( 'group' => 'topology', 'class' => 'c', 'risk' => 'low', 'action' => '', 'cursor' => 'int', 'label' => 'تنوع زیر محصول غیرمتغیر', 'fix' => 'نیاز به Repair همراه با داده منبع دارد (فاز legacy-simple-topology)؛ فقط گزارش.' ),
			'dup_attribute_set'         => array( 'group' => 'topology', 'class' => 'b', 'risk' => 'medium', 'action' => 'disable_variation', 'cursor' => 'int', 'label' => 'چند تنوع با ویژگی‌های یکسان زیر یک محصول', 'fix' => 'برای محصول موبویی که دقیقاً یک تنوع نگاشت‌شده دارد، تنوع‌های بی‌نگاشت با تأیید مدیر غیرفعال می‌شوند؛ موارد مبهم و محصولات خود فروشگاه فقط گزارش می‌شوند.' ),
			'product_type_term'         => array( 'group' => 'topology', 'class' => 'c', 'risk' => 'low', 'action' => '', 'cursor' => 'int', 'label' => 'محصول بدون نوع یا با چند نوع', 'fix' => 'ووکامرس آن را «ساده» فرض می‌کند؛ با ذخیره محصول یا Sync اصلاح می‌شود. فقط گزارش.' ),
			'variable_without_children' => array( 'group' => 'topology', 'class' => 'c', 'risk' => 'low', 'action' => '', 'cursor' => 'int', 'label' => 'محصول متغیر منتشرشده بدون تنوع فعال', 'fix' => 'در فروشگاه قابل خرید نیست؛ Sync/Repair تنوع‌ها را برمی‌گرداند. فقط گزارش.' ),
			'orphan_term_relationship'  => array( 'group' => 'index', 'class' => 'a', 'risk' => 'low', 'action' => 'delete_row', 'cursor' => 'int', 'label' => 'اتصال دسته/برچسب/ویژگی به محصولی که وجود ندارد', 'fix' => 'ردیف‌های یتیم با پشتیبان حذف و شمارش دسته‌ها بازشماری می‌شود. قابل برگشت.' ),
			'lookup_missing_or_stale'   => array( 'group' => 'index', 'class' => 'a', 'risk' => 'low', 'action' => 'regen_lookup', 'cursor' => 'int', 'label' => 'جدول جست‌وجوی ووکامرس ناقص یا ناهماهنگ با موجودی/قیمت', 'fix' => 'ردیف lookup از روی داده خود محصول بازسازی می‌شود (پشتیبان دارد).' ),
			'lookup_orphan'             => array( 'group' => 'index', 'class' => 'a', 'risk' => 'low', 'action' => 'delete_row', 'cursor' => 'int', 'label' => 'ردیف جدول جست‌وجوی ووکامرس برای محصول حذف‌شده', 'fix' => 'با پشتیبان حذف می‌شود. قابل برگشت.' ),
			'attr_lookup_orphan'        => array( 'group' => 'index', 'class' => 'a', 'risk' => 'low', 'action' => 'delete_row', 'cursor' => 'int', 'label' => 'ردیف جدول ویژگی‌های ووکامرس برای محصول حذف‌شده', 'fix' => 'با پشتیبان حذف می‌شود. قابل برگشت.' ),
			'media_ref_missing'         => array( 'group' => 'media', 'class' => 'b', 'risk' => 'low', 'action' => 'clear_media_ref', 'cursor' => 'int', 'label' => 'تصویر شاخص/گالری به پیوستی که وجود ندارد', 'fix' => 'با تأیید مدیر فقط ارجاع شکسته برداشته می‌شود؛ هیچ تصویری حذف نمی‌شود. قابل برگشت.' ),
			'attachment_file_missing'   => array( 'group' => 'media', 'class' => 'c', 'risk' => 'low', 'action' => '', 'cursor' => 'int', 'label' => 'فایل تصویر روی دیسک نیست یا صفر بایت است', 'fix' => 'از «نوسازی تصاویر» یا بازیابی تصاویر گم‌شده دوباره دریافت می‌شود؛ هیچ پیوستی حذف نمی‌شود. فقط گزارش.' ),
			'shared_attached_file'      => array( 'group' => 'media', 'class' => 'c', 'risk' => 'low', 'action' => '', 'cursor' => 'str', 'label' => 'چند پیوست با یک فایل فیزیکی مشترک', 'fix' => 'برای جلوگیری از حذف فایل مشترک گزارش می‌شود؛ هیچ تغییری داده نمی‌شود.' ),
			'duplicate_single_meta'     => array( 'group' => 'price', 'class' => 'c', 'risk' => 'low', 'action' => '', 'cursor' => 'int', 'label' => 'meta تکراری قیمت/موجودی/SKU روی یک محصول', 'fix' => 'پاکسازی meta قیمت در Repair انجام می‌شود؛ فقط گزارش.' ),
			'stock_meta_invalid'        => array( 'group' => 'price', 'class' => 'c', 'risk' => 'low', 'action' => '', 'cursor' => 'int', 'label' => 'وضعیت موجودی نامعتبر یا مدیریت موجودی بدون تعداد', 'fix' => 'Sync بعدی مقدار درست را می‌نویسد؛ داده خالی هرگز موجودی را صفر نمی‌کند. فقط گزارش.' ),
			'mobo_instock_without_price' => array( 'group' => 'price', 'class' => 'c', 'risk' => 'low', 'action' => '', 'cursor' => 'int', 'label' => 'تنوع موبویی موجود بدون قیمت', 'fix' => 'در فروشگاه قابل خرید نیست؛ Sync/بازقیمت‌گذاری اصلاح می‌کند. فقط گزارش.' ),
			'question_mark_text'        => array( 'group' => 'text', 'class' => 'c', 'risk' => 'low', 'action' => '', 'cursor' => 'int', 'label' => 'عنوان محصول خراب‌شده به «????»', 'fix' => 'پس از تبدیل utf8mb4، با Sync از منبع دوباره نوشته می‌شود؛ فقط گزارش.' ),
			'question_mark_terms'       => array( 'group' => 'text', 'class' => 'c', 'risk' => 'low', 'action' => '', 'cursor' => 'once', 'label' => 'نام دسته/ویژگی خراب‌شده به «????»', 'fix' => 'پس از تبدیل utf8mb4، با همگام‌سازی دسته‌ها اصلاح می‌شود؛ فقط گزارش.' ),
			'trashed_mobo_products'     => array( 'group' => 'identity', 'class' => 'c', 'risk' => 'low', 'action' => '', 'cursor' => 'int', 'label' => 'محصول موبویی در زباله‌دان', 'fix' => 'فاز بازیابی Repair آن را برمی‌گرداند (محصول هرگز حذف نمی‌شود)؛ فقط گزارش.' ),
			'quarantine'                => array( 'group' => 'state', 'class' => 'c', 'risk' => 'medium', 'action' => '', 'cursor' => 'once', 'label' => 'اشیای قرنطینه‌شده در Migration', 'fix' => 'رفع تکراری‌ها علت را برطرف می‌کند و بازبینی دوره‌ای آن‌ها را آزاد می‌کند؛ فقط گزارش.' ),
			'stale_locks'               => array( 'group' => 'state', 'class' => 'a', 'risk' => 'low', 'action' => 'recover_locks', 'cursor' => 'once', 'label' => 'قفل منقضی‌شده عملیات', 'fix' => 'با سازوکار موجود قفل (فقط قفل‌های منقضی) آزاد می‌شود.' ),
			'stale_processing_rows'     => array( 'group' => 'state', 'class' => 'b', 'risk' => 'low', 'action' => 'reclaim_row', 'cursor' => 'once', 'label' => 'رویداد/صف تصویر گیرکرده در حالت «در حال پردازش»', 'fix' => 'با تأیید مدیر به صف برگردانده می‌شود (پشتیبان دارد).' ),
			'half_migrated'             => array( 'group' => 'state', 'class' => 'c', 'risk' => 'medium', 'action' => '', 'cursor' => 'once', 'label' => 'Migration نیمه‌کاره یا ذخیره‌سازی آماده نیست', 'fix' => 'Migration از نقطه ذخیره‌شده ادامه می‌دهد؛ اگر گیر کرده باشد پشتیبانی بررسی می‌کند.' ),
			'false_success_timestamp'   => array( 'group' => 'state', 'class' => 'c', 'risk' => 'low', 'action' => '', 'cursor' => 'once', 'label' => '«آخرین Sync موفق» که در زمان Migration نوشته شده (R6)', 'fix' => 'مقدار قبلی قابل بازیابی نیست؛ با اولین Sync واقعی درست می‌شود. فقط گزارش.' ),
		);
	}

	/**
	 * Run one bounded step of a detector.
	 *
	 * @param string     $id Detector id.
	 * @param int|string $cursor Cursor (0 / '' at start).
	 * @param array      $dstate Per-detector scan state (may get 'max').
	 * @return array{findings:array,groups:int,next:mixed,done:bool,error:string}
	 */
	public static function step( $id, $cursor, array &$dstate ) {
		$method = 'scan_' . $id;
		if ( ! method_exists( __CLASS__, $method ) ) {
			return self::result( array(), 0, $cursor, true, 'unknown_detector' );
		}
		global $wpdb;
		$wpdb->last_error = '';
		try {
			return self::$method( $cursor, $dstate );
		} catch ( Throwable $e ) {
			return self::result( array(), 0, $cursor, false, 'exception: ' . substr( $e->getMessage(), 0, 160 ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array      $findings Findings.
	 * @param int        $groups Groups.
	 * @param int|string $next Next cursor.
	 * @param bool       $done Done.
	 * @param string     $error Error.
	 * @return array
	 */
	private static function result( $findings, $groups, $next, $done, $error = '' ) {
		return array( 'findings' => $findings, 'groups' => absint( $groups ), 'next' => $next, 'done' => (bool) $done, 'error' => (string) $error );
	}

	/**
	 * @param int    $object_id Object.
	 * @param string $class a|b|c.
	 * @param string $action Action.
	 * @param array  $detail Detail.
	 * @param string $group_key Group.
	 * @param int    $canonical Canonical id.
	 * @param string $ref Object reference.
	 * @param string $status open|covered.
	 * @param int    $weight Count contribution.
	 * @return array
	 */
	private static function finding( $object_id, $class, $action, $detail = array(), $group_key = '', $canonical = 0, $ref = '', $status = 'open', $weight = 1 ) {
		return array(
			'object_id'    => absint( $object_id ),
			'class'        => $class,
			'action'       => 'c' === $class ? '' : (string) $action,
			'detail'       => $detail,
			'group_key'    => (string) $group_key,
			'canonical_id' => absint( $canonical ),
			'object_ref'   => (string) $ref,
			'status'       => $status,
			'weight'       => max( 0, (int) $weight ),
		);
	}

	/** @return bool */
	private static function db_failed() {
		global $wpdb;
		return '' !== trim( (string) $wpdb->last_error );
	}

	/**
	 * Resolve a logical table name.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function table( $key ) {
		global $wpdb;
		switch ( $key ) {
			case 'posts':
				return $wpdb->posts;
			case 'postmeta':
				return $wpdb->postmeta;
			case 'options':
				return $wpdb->options;
			case 'term_relationships':
				return isset( $wpdb->term_relationships ) ? $wpdb->term_relationships : $wpdb->prefix . 'term_relationships';
			case 'term_taxonomy':
				return isset( $wpdb->term_taxonomy ) ? $wpdb->term_taxonomy : $wpdb->prefix . 'term_taxonomy';
			case 'terms':
				return isset( $wpdb->terms ) ? $wpdb->terms : $wpdb->prefix . 'terms';
			case 'map':
				return $wpdb->prefix . 'mobo_product_map';
			case 'custom_products':
				return class_exists( 'Mobo_Core_Storage_Schema' ) ? Mobo_Core_Storage_Schema::table_name( 'products' ) : $wpdb->prefix . 'mobo_products';
			case 'custom_variants':
				return class_exists( 'Mobo_Core_Storage_Schema' ) ? Mobo_Core_Storage_Schema::table_name( 'variants' ) : $wpdb->prefix . 'mobo_variants';
			case 'migrations':
				return class_exists( 'Mobo_Core_Storage_Schema' ) ? Mobo_Core_Storage_Schema::table_name( 'migrations' ) : $wpdb->prefix . 'mobo_migrations';
			case 'lookup':
				return $wpdb->prefix . 'wc_product_meta_lookup';
			case 'attr_lookup':
				return $wpdb->prefix . 'wc_product_attributes_lookup';
			case 'sync_events':
				return $wpdb->prefix . 'mobo_sync_events';
			case 'image_queue':
				return $wpdb->prefix . 'mobo_image_queue';
			case 'sync_health':
				return $wpdb->prefix . 'mobo_sync_health';
		}
		return '';
	}

	/**
	 * Request-cached table existence.
	 *
	 * @param string $key Logical key.
	 * @return bool
	 */
	public static function has( $key ) {
		$table = self::table( $key );
		if ( '' === $table ) {
			return false;
		}
		if ( ! isset( self::$table_cache[ $table ] ) ) {
			self::$table_cache[ $table ] = Mobo_Core_Data_Health_Store::table_exists( $table );
		}
		return self::$table_cache[ $table ];
	}

	/**
	 * Column names of a table (request-cached).
	 *
	 * @param string $key Logical key.
	 * @return string[]
	 */
	public static function columns( $key ) {
		global $wpdb;
		$table = self::table( $key );
		if ( ! isset( self::$column_cache[ $table ] ) ) {
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
			self::$column_cache[ $table ] = is_array( $cols ) ? array_map( 'strval', $cols ) : array();
		}
		return self::$column_cache[ $table ];
	}

	/** @return string[] */
	public static function product_guid_keys() {
		return class_exists( 'Mobo_Core_Product_Identity_Policy' ) ? Mobo_Core_Product_Identity_Policy::product_guid_meta_keys() : array( 'product_guid' );
	}

	/** @return string[] */
	public static function variant_guid_keys() {
		return class_exists( 'Mobo_Core_Product_Identity_Policy' ) ? Mobo_Core_Product_Identity_Policy::variant_guid_meta_keys() : array( 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid' );
	}

	/** @return string[] All product identity aliases (guid + portal/external ids). */
	public static function product_identity_keys() {
		return class_exists( 'Mobo_Core_Product_Identity_Policy' ) ? Mobo_Core_Product_Identity_Policy::product_identity_meta_keys() : array( 'product_guid', '_mobo_external_product_id', 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id', 'PortalProductId' );
	}

	/** @return string[] All variation identity aliases. */
	public static function variation_identity_keys() {
		return class_exists( 'Mobo_Core_Product_Identity_Policy' ) ? Mobo_Core_Product_Identity_Policy::variation_identity_meta_keys() : array( 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid', '_mobo_external_variant_id', 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id', 'PortalVariantId' );
	}

	/**
	 * Quoted IN() list of strings.
	 *
	 * @param string[] $values Values.
	 * @return string
	 */
	public static function in_strings( array $values ) {
		global $wpdb;
		$out = array();
		foreach ( $values as $value ) {
			$out[] = $wpdb->prepare( '%s', (string) $value );
		}
		return empty( $out ) ? "''" : implode( ',', $out );
	}

	/**
	 * IN() list of integers.
	 *
	 * @param array $ids IDs.
	 * @return string
	 */
	public static function in_ints( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		return empty( $ids ) ? '0' : implode( ',', $ids );
	}

	/**
	 * Whether identity postmeta is retired (Custom Storage authoritative).
	 *
	 * @return bool
	 */
	public static function custom_identity_mode() {
		return class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' )
			&& method_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement', 'must_use_custom_reads' )
			&& Mobo_Core_Storage_Identity_Meta_Retirement::must_use_custom_reads()
			&& class_exists( 'Mobo_Core_Storage_Schema' )
			&& Mobo_Core_Storage_Schema::is_ready();
	}

	/**
	 * Highest ID of a table column, cached in the detector state.
	 *
	 * @param array  $dstate Detector state.
	 * @param string $table Table.
	 * @param string $column Column.
	 * @param string $where Extra WHERE.
	 * @return int|false
	 */
	private static function max_id( array &$dstate, $table, $column, $where = '' ) {
		global $wpdb;
		if ( isset( $dstate['max'] ) ) {
			return absint( $dstate['max'] );
		}
		$value = $wpdb->get_var( "SELECT MAX({$column}) FROM {$table}" . ( '' !== $where ? " WHERE {$where}" : '' ) );
		if ( self::db_failed() ) {
			return false;
		}
		$dstate['max'] = absint( $value );
		return $dstate['max'];
	}

	/**
	 * Generic ID window: [cursor+1 .. cursor+size].
	 *
	 * @param int $cursor Cursor.
	 * @param int $size Window.
	 * @return array{0:int,1:int}
	 */
	private static function window( $cursor, $size = self::WINDOW ) {
		$low = absint( $cursor );
		return array( $low, $low + max( 1, absint( $size ) ) );
	}

	/**
	 * Post IDs among $ids that carry Mobo ownership evidence (identity meta,
	 * Product Map row or Custom Storage row).
	 *
	 * @param int[]  $ids IDs.
	 * @param string $type product|variation.
	 * @return array<int,bool>|false
	 */
	public static function mobo_owned( array $ids, $type ) {
		global $wpdb;
		$owned = array();
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return $owned;
		}
		$keys = 'product' === $type ? self::product_identity_keys() : self::variation_identity_keys();
		foreach ( array_chunk( $ids, self::IN_CHUNK ) as $chunk ) {
			$in   = self::in_ints( $chunk );
			$rows = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN (" . self::in_strings( $keys ) . ") AND meta_value<>''" );
			if ( self::db_failed() ) {
				return false;
			}
			foreach ( $rows as $id ) {
				$owned[ absint( $id ) ] = true;
			}
			foreach ( self::authoritative_owned( $chunk, $type ) as $id => $yes ) {
				$owned[ $id ] = true;
			}
		}
		return $owned;
	}

	/**
	 * Post IDs among $ids mapped by an authoritative identity store (Product Map
	 * row or Custom Storage row).
	 *
	 * @param int[]  $ids IDs.
	 * @param string $type product|variation.
	 * @return array<int,bool>
	 */
	public static function authoritative_owned( array $ids, $type ) {
		global $wpdb;
		$owned = array();
		$in    = self::in_ints( $ids );
		if ( self::has( 'map' ) ) {
			$object_type = 'product' === $type ? 'product' : 'variation';
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT wp_post_id FROM ' . self::table( 'map' ) . " WHERE object_type=%s AND wp_post_id IN ({$in})", $object_type ) ) as $id ) {
				$owned[ absint( $id ) ] = true;
			}
		}
		$custom = 'product' === $type ? 'custom_products' : 'custom_variants';
		$col    = 'product' === $type ? 'woo_product_id' : 'woo_variation_id';
		if ( self::has( $custom ) ) {
			foreach ( (array) $wpdb->get_col( 'SELECT DISTINCT ' . $col . ' FROM ' . self::table( $custom ) . " WHERE {$col} IN ({$in})" ) as $id ) {
				$owned[ absint( $id ) ] = true;
			}
		}
		return $owned;
	}

	/* ------------------------------------------------------------------ */
	/* Identity                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Union of all GUID claims (identity meta, Product Map, Custom Storage) on
	 * live posts of one type, filtered by a GUID predicate.
	 *
	 * @param string $type product|variation.
	 * @param string $predicate SQL predicate with one %s placeholder.
	 * @param string $value Value for the predicate.
	 * @return string SQL selecting (g, pid, src).
	 */
	private static function claims_sql( $type, $predicate, $value ) {
		global $wpdb;
		$post_type = 'product' === $type ? 'product' : 'product_variation';
		$keys      = 'product' === $type ? self::product_guid_keys() : self::variant_guid_keys();
		$branches  = array();
		$branches[] = $wpdb->prepare(
			"SELECT BINARY pm.meta_value AS g, pm.post_id AS pid, 'meta' AS src FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key IN (" . self::in_strings( $keys ) . ') AND ' . str_replace( 'G', 'BINARY pm.meta_value', $predicate ) . " AND pm.meta_value<>'' AND p.post_type=%s AND p.post_status NOT IN " . self::LIVE,
			$value,
			$post_type
		);
		if ( self::has( 'map' ) ) {
			$branches[] = $wpdb->prepare(
				"SELECT BINARY m.remote_guid AS g, m.wp_post_id AS pid, 'map' AS src FROM " . self::table( 'map' ) . " m INNER JOIN {$wpdb->posts} p ON p.ID=m.wp_post_id WHERE m.object_type=%s AND " . str_replace( 'G', 'BINARY m.remote_guid', $predicate ) . " AND m.remote_guid<>'' AND p.post_type=%s AND p.post_status NOT IN " . self::LIVE,
				'product' === $type ? 'product' : 'variation',
				$value,
				$post_type
			);
		}
		$custom = 'product' === $type ? 'custom_products' : 'custom_variants';
		if ( self::has( $custom ) ) {
			$gcol = 'product' === $type ? 'product_guid' : 'variant_guid';
			$pcol = 'product' === $type ? 'woo_product_id' : 'woo_variation_id';
			$branches[] = $wpdb->prepare(
				"SELECT BINARY c.{$gcol} AS g, c.{$pcol} AS pid, 'custom' AS src FROM " . self::table( $custom ) . " c INNER JOIN {$wpdb->posts} p ON p.ID=c.{$pcol} WHERE " . str_replace( 'G', 'BINARY c.' . $gcol, $predicate ) . " AND c.{$gcol}<>'' AND p.post_type=%s AND p.post_status NOT IN " . self::LIVE,
				$value,
				$post_type
			);
		}
		return implode( ' UNION ALL ', $branches );
	}

	/**
	 * Shared duplicate-GUID detector.
	 *
	 * @param string $type product|variation.
	 * @param string $cursor Last GUID.
	 * @return array
	 */
	private static function scan_duplicate_guid( $type, $cursor ) {
		global $wpdb;
		$cursor = (string) $cursor;
		$sql    = 'SELECT x.g FROM (' . self::claims_sql( $type, 'G>%s', $cursor ) . ') x GROUP BY x.g HAVING COUNT(DISTINCT x.pid)>1 ORDER BY x.g ASC LIMIT ' . self::GROUP_LIMIT;
		$guids  = $wpdb->get_col( $sql );
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		$action   = 'product' === $type ? 'hide_copy' : 'disable_variation';
		foreach ( $guids as $guid ) {
			$guid = (string) $guid;
			$rows = $wpdb->get_results( 'SELECT x.pid, x.src FROM (' . self::claims_sql( $type, 'G=%s', $guid ) . ') x', ARRAY_A );
			if ( self::db_failed() ) {
				return self::result( array(), 0, $cursor, false, 'db_read_failed' );
			}
			$members = array();
			$owners  = array();
			foreach ( $rows as $row ) {
				$pid             = absint( $row['pid'] );
				$members[ $pid ] = true;
				if ( 'meta' !== $row['src'] ) {
					$owners[ $pid ] = true;
				}
			}
			ksort( $members );
			$member_ids = array_keys( $members );
			$canonical  = 1 === count( $owners ) ? (int) key( $owners ) : 0;
			$group      = substr( $type . ':' . $guid, 0, 191 );
			foreach ( $member_ids as $pid ) {
				if ( $canonical > 0 && $pid === $canonical ) {
					continue;
				}
				if ( $canonical > 0 ) {
					$findings[] = self::finding( $pid, 'b', $action, array( 'reason' => 'duplicate_guid', 'canonical' => $canonical, 'members' => $member_ids, 'retireIdentity' => true ), $group, $canonical, $guid );
				} else {
					$findings[] = self::finding( $pid, 'c', '', array( 'reason' => empty( $owners ) ? 'no_authoritative_owner' : 'several_owners', 'owners' => array_keys( $owners ), 'members' => $member_ids ), $group, 0, $guid );
				}
			}
			$cursor = $guid;
		}
		return self::result( $findings, count( $guids ), $cursor, count( $guids ) < self::GROUP_LIMIT );
	}

	/** @return array */
	private static function scan_dup_product_guid( $cursor, array &$dstate ) {
		return self::scan_duplicate_guid( 'product', $cursor );
	}

	/** @return array */
	private static function scan_dup_variant_guid( $cursor, array &$dstate ) {
		return self::scan_duplicate_guid( 'variation', $cursor );
	}

	/** @return array */
	private static function scan_unmapped_object( $cursor, array &$dstate ) {
		global $wpdb;
		$custom_mode = self::custom_identity_mode();
		if ( ! $custom_mode && ! self::has( 'map' ) ) {
			return self::result( array(), 0, 0, true, '' );
		}
		$max = self::max_id( $dstate, $wpdb->posts, 'ID' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor );
		$pkeys = self::product_guid_keys();
		$vkeys = self::variant_guid_keys();
		if ( $custom_mode ) {
			$mapped_p = self::has( 'custom_products' ) ? 'EXISTS (SELECT 1 FROM ' . self::table( 'custom_products' ) . ' c WHERE c.woo_product_id=p.ID)' : '0';
			$mapped_v = self::has( 'custom_variants' ) ? 'EXISTS (SELECT 1 FROM ' . self::table( 'custom_variants' ) . ' c WHERE c.woo_variation_id=p.ID)' : '0';
		} else {
			$map      = self::table( 'map' );
			$mapped_p = "EXISTS (SELECT 1 FROM {$map} m WHERE m.wp_post_id=p.ID AND m.object_type='product')";
			$mapped_v = "EXISTS (SELECT 1 FROM {$map} m WHERE m.wp_post_id=p.ID AND m.object_type='variation')";
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type, pm.meta_value AS g FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID WHERE p.ID>%d AND p.ID<=%d AND p.post_status NOT IN " . self::LIVE . " AND pm.meta_value<>'' AND ((p.post_type='product' AND pm.meta_key IN (" . self::in_strings( $pkeys ) . ") AND NOT {$mapped_p}) OR (p.post_type='product_variation' AND pm.meta_key IN (" . self::in_strings( $vkeys ) . ") AND NOT {$mapped_v})) ORDER BY p.ID ASC",
				$low,
				$high
			),
			ARRAY_A
		);
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		$seen     = array();
		foreach ( $rows as $row ) {
			$pid = absint( $row['ID'] );
			if ( isset( $seen[ $pid ] ) ) {
				continue;
			}
			$seen[ $pid ] = true;
			$type  = 'product' === $row['post_type'] ? 'product' : 'variation';
			$guid  = (string) $row['g'];
			$claim = $wpdb->get_results( 'SELECT x.pid, x.src FROM (' . self::claims_sql( $type, 'G=%s', $guid ) . ') x', ARRAY_A );
			if ( self::db_failed() ) {
				return self::result( array(), 0, $cursor, false, 'db_read_failed' );
			}
			$others = 0;
			$owner  = 0;
			foreach ( $claim as $c ) {
				if ( absint( $c['pid'] ) !== $pid ) {
					$others++;
				}
				if ( 'meta' !== $c['src'] ) {
					$owner++;
				}
			}
			if ( $others > 0 ) {
				$findings[] = self::finding( $pid, 'c', '', array( 'reason' => 'covered_by_duplicate_detector', 'type' => $type ), '', 0, $guid, 'covered' );
			} elseif ( $custom_mode ) {
				$findings[] = self::finding( $pid, 'c', '', array( 'reason' => 'legacy_residue_after_identity_fence', 'type' => $type ), '', 0, $guid );
			} elseif ( 0 === $owner && 'variation' === $type && self::has_signature_twin( $pid ) ) {
				/* A stale leftover and a live Source variant look alike locally; never
				 * adopt it automatically (the attribute-set detector proposes disabling). */
				$findings[] = self::finding( $pid, 'c', '', array( 'reason' => 'attribute_twin_needs_source', 'type' => $type ), '', 0, $guid );
			} elseif ( 0 === $owner ) {
				$findings[] = self::finding( $pid, 'a', 'seed_map', array( 'reason' => 'unique_guid_without_map', 'type' => $type ), '', 0, $guid );
			}
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/**
	 * Whether a published sibling variation has the same attribute signature.
	 *
	 * @param int $vid Variation.
	 * @return bool
	 */
	private static function has_signature_twin( $vid ) {
		global $wpdb;
		$parent = absint( $wpdb->get_var( $wpdb->prepare( "SELECT post_parent FROM {$wpdb->posts} WHERE ID=%d", $vid ) ) );
		if ( $parent <= 0 ) {
			return false;
		}
		$ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent=%d AND post_type='product_variation' AND post_status IN ('publish','private') ORDER BY ID ASC LIMIT 2000", $parent ) ) );
		if ( count( $ids ) < 2 ) {
			return false;
		}
		$attrs = array();
		foreach ( $ids as $id ) {
			$attrs[ $id ] = array();
		}
		$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . self::in_ints( $ids ) . ") AND SUBSTR(meta_key,1,10)='attribute_'", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$attrs[ absint( $row['post_id'] ) ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
		}
		$mine = self::signature( isset( $attrs[ $vid ] ) ? $attrs[ $vid ] : array() );
		foreach ( $attrs as $id => $set ) {
			if ( $id !== $vid && self::signature( $set ) === $mine ) {
				return true;
			}
		}
		return false;
	}

	/** @return array */
	private static function scan_broken_map_row( $cursor, array &$dstate ) {
		global $wpdb;
		if ( ! self::has( 'map' ) ) {
			return self::result( array(), 0, 0, true );
		}
		$map = self::table( 'map' );
		$max = self::max_id( $dstate, $map, 'id' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.wp_post_id, m.object_type, p.ID AS pid, p.post_type FROM {$map} m LEFT JOIN {$wpdb->posts} p ON p.ID=m.wp_post_id WHERE m.id>%d AND m.id<=%d AND (p.ID IS NULL OR (m.object_type='product' AND p.post_type<>'product') OR (m.object_type='variation' AND p.post_type<>'product_variation')) ORDER BY m.id ASC",
				$low,
				$high
			),
			ARRAY_A
		);
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		foreach ( $rows as $row ) {
			$findings[] = self::finding( absint( $row['id'] ), 'a', 'delete_row', array( 'reason' => null === $row['pid'] ? 'missing_post' : 'wrong_post_type', 'postId' => absint( $row['wp_post_id'] ), 'objectType' => (string) $row['object_type'] ), '', 0, 'map:' . absint( $row['id'] ) );
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/** @return array Two phases: "p<id>" products then "v<id>" variants. */
	private static function scan_custom_identity_dangling( $cursor, array &$dstate ) {
		global $wpdb;
		$cursor = (string) $cursor;
		$phase  = '' === $cursor ? 'p' : substr( $cursor, 0, 1 );
		$pos    = absint( substr( $cursor, 1 ) );
		if ( 'p' === $phase && ! self::has( 'custom_products' ) ) {
			return self::result( array(), 0, 'v0', false );
		}
		if ( 'v' === $phase && ! self::has( 'custom_variants' ) ) {
			return self::result( array(), 0, 'v0', true );
		}
		$table = self::table( 'p' === $phase ? 'custom_products' : 'custom_variants' );
		$key   = 'max_' . $phase;
		if ( ! isset( $dstate[ $key ] ) ) {
			$dstate[ $key ] = absint( $wpdb->get_var( "SELECT MAX(id) FROM {$table}" ) );
		}
		$max  = absint( $dstate[ $key ] );
		$high = $pos + self::WINDOW;
		if ( 'p' === $phase ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.woo_product_id AS wid, 'missing_post' AS reason FROM {$table} c LEFT JOIN {$wpdb->posts} p ON p.ID=c.woo_product_id WHERE c.id>%d AND c.id<=%d AND c.woo_product_id>0 AND (p.ID IS NULL OR p.post_type<>'product')", $pos, $high ), ARRAY_A );
		} else {
			$products = self::has( 'custom_products' ) ? self::table( 'custom_products' ) : '';
			$orphan   = '' !== $products ? " OR (c.product_guid<>'' AND NOT EXISTS (SELECT 1 FROM {$products} pr WHERE pr.connection_id=c.connection_id AND BINARY pr.product_guid=BINARY c.product_guid))" : '';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.woo_variation_id AS wid, CASE WHEN p.ID IS NULL OR p.post_type<>'product_variation' THEN 'missing_post' WHEN c.woo_product_id>0 AND p.post_parent<>c.woo_product_id THEN 'parent_mismatch' ELSE 'product_row_missing' END AS reason FROM {$table} c LEFT JOIN {$wpdb->posts} p ON p.ID=c.woo_variation_id WHERE c.id>%d AND c.id<=%d AND ((c.woo_variation_id>0 AND (p.ID IS NULL OR p.post_type<>'product_variation')) OR (c.woo_product_id>0 AND p.ID IS NOT NULL AND p.post_parent<>c.woo_product_id){$orphan})", $pos, $high ), ARRAY_A );
		}
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		foreach ( $rows as $row ) {
			$findings[] = self::finding( absint( $row['wid'] ), 'c', '', array( 'reason' => (string) $row['reason'], 'table' => 'p' === $phase ? 'products' : 'variants', 'rowId' => absint( $row['id'] ) ), '', 0, ( 'p' === $phase ? 'custom_products:' : 'custom_variants:' ) . absint( $row['id'] ) );
		}
		if ( $high >= $max ) {
			return self::result( $findings, 0, 'p' === $phase ? 'v0' : 'v' . $high, 'v' === $phase );
		}
		return self::result( $findings, 0, $phase . $high, false );
	}

	/** @return array */
	private static function scan_trashed_mobo_products( $cursor, array &$dstate ) {
		global $wpdb;
		$max = self::max_id( $dstate, $wpdb->posts, 'ID' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND ID<=%d AND post_type='product' AND post_status='trash'", $low, $high ) );
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$owned = self::mobo_owned( $ids, 'product' );
		if ( false === $owned ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		foreach ( array_keys( $owned ) as $pid ) {
			$findings[] = self::finding( $pid, 'c', '', array( 'reason' => 'mobo_product_in_trash' ) );
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/* ------------------------------------------------------------------ */
	/* Topology                                                           */
	/* ------------------------------------------------------------------ */

	/** @return array */
	private static function scan_orphan_variation( $cursor, array &$dstate ) {
		global $wpdb;
		$max = self::max_id( $dstate, $wpdb->posts, 'ID' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.ID, v.post_status, v.post_parent, p.ID AS pid, p.post_type AS ptype, p.post_status AS pstatus FROM {$wpdb->posts} v LEFT JOIN {$wpdb->posts} p ON p.ID=v.post_parent WHERE v.ID>%d AND v.ID<=%d AND v.post_type='product_variation' AND v.post_status IN ('publish','private') AND (p.ID IS NULL OR p.post_type<>'product' OR p.post_status='trash')",
				$low,
				$high
			),
			ARRAY_A
		);
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		foreach ( $rows as $row ) {
			$vid = absint( $row['ID'] );
			if ( null !== $row['pid'] && 'product' === $row['ptype'] && 'trash' === $row['pstatus'] ) {
				$findings[] = self::finding( $vid, 'c', '', array( 'reason' => 'parent_in_trash', 'parent' => absint( $row['post_parent'] ) ) );
			} elseif ( 'publish' === $row['post_status'] ) {
				$findings[] = self::finding( $vid, 'b', 'disable_variation', array( 'reason' => null === $row['pid'] ? 'parent_missing' : 'parent_not_product', 'parent' => absint( $row['post_parent'] ) ) );
			}
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/** @return string SQL: product p has the product_type term $slug. */
	private static function has_type_sql( $alias, $slug ) {
		$tr = self::table( 'term_relationships' );
		$tt = self::table( 'term_taxonomy' );
		$t  = self::table( 'terms' );
		return "EXISTS (SELECT 1 FROM {$tr} tr INNER JOIN {$tt} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_type' INNER JOIN {$t} t ON t.term_id=tt.term_id WHERE tr.object_id={$alias}.ID AND t.slug='{$slug}')";
	}

	/** @return array Cursor = last parent ID. */
	private static function scan_variation_under_simple( $cursor, array &$dstate ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.post_parent AS parent, COUNT(*) AS n FROM {$wpdb->posts} v INNER JOIN {$wpdb->posts} p ON p.ID=v.post_parent WHERE v.post_type='product_variation' AND v.post_status IN ('publish','private') AND v.post_parent>%d AND p.post_type='product' AND p.post_status NOT IN " . self::LIVE . ' AND NOT ' . self::has_type_sql( 'p', 'variable' ) . ' GROUP BY v.post_parent ORDER BY v.post_parent ASC LIMIT %d',
				absint( $cursor ),
				self::PARENT_LIMIT
			),
			ARRAY_A
		);
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$parents = array();
		foreach ( $rows as $row ) {
			$parents[] = absint( $row['parent'] );
		}
		$owned    = empty( $parents ) ? array() : self::mobo_owned( $parents, 'product' );
		$findings = array();
		$next     = absint( $cursor );
		foreach ( $rows as $row ) {
			$parent     = absint( $row['parent'] );
			$findings[] = self::finding( $parent, 'c', '', array( 'reason' => 'variations_under_non_variable_parent', 'variations' => absint( $row['n'] ), 'mobo' => is_array( $owned ) && isset( $owned[ $parent ] ) ), '', 0, '', 'open', absint( $row['n'] ) );
			$next       = max( $next, $parent );
		}
		return self::result( $findings, count( $rows ), $next, count( $rows ) < self::PARENT_LIMIT );
	}

	/**
	 * Deterministic attribute signature (same policy as Sync/Repair).
	 *
	 * @param array $attributes attribute_* => value.
	 * @return string
	 */
	public static function signature( array $attributes ) {
		if ( class_exists( 'Mobo_Core_Variation_Identity_Policy' ) ) {
			$sig = Mobo_Core_Variation_Identity_Policy::attribute_signature( $attributes );
		} else {
			$normalized = array();
			foreach ( $attributes as $key => $value ) {
				$key   = preg_replace( '/^attribute_/', '', strtolower( trim( (string) $key ) ) );
				$value = strtolower( trim( (string) $value ) );
				if ( '' !== $key && '' !== $value ) {
					$normalized[ $key ] = $value;
				}
			}
			ksort( $normalized );
			$sig = empty( $normalized ) ? '' : md5( json_encode( $normalized ) );
		}
		return '' === $sig ? '__any__' : $sig;
	}

	/** @return array Cursor = last parent ID. */
	private static function scan_dup_attribute_set( $cursor, array &$dstate ) {
		global $wpdb;
		$parents = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_parent FROM {$wpdb->posts} WHERE post_type='product_variation' AND post_status='publish' AND post_parent>%d GROUP BY post_parent HAVING COUNT(*)>1 ORDER BY post_parent ASC LIMIT %d",
				absint( $cursor ),
				self::PARENT_LIMIT
			)
		);
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$parents = array_map( 'absint', $parents );
		if ( empty( $parents ) ) {
			return self::result( array(), 0, $cursor, true );
		}
		$children = array();
		$by_id    = array();
		$rows     = $wpdb->get_results( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type='product_variation' AND post_status='publish' AND post_parent IN (" . self::in_ints( $parents ) . ') ORDER BY ID ASC', ARRAY_A );
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		foreach ( $rows as $row ) {
			$id                                    = absint( $row['ID'] );
			$children[ absint( $row['post_parent'] ) ][] = $id;
			$by_id[ $id ]                          = array();
		}
		foreach ( array_chunk( array_keys( $by_id ), self::IN_CHUNK ) as $chunk ) {
			$attrs = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . self::in_ints( $chunk ) . ") AND SUBSTR(meta_key,1,10)='attribute_'", ARRAY_A );
			if ( self::db_failed() ) {
				return self::result( array(), 0, $cursor, false, 'db_read_failed' );
			}
			foreach ( $attrs as $a ) {
				$by_id[ absint( $a['post_id'] ) ][ (string) $a['meta_key'] ] = (string) $a['meta_value'];
			}
		}
		$parent_owned = self::mobo_owned( $parents, 'product' );
		if ( false === $parent_owned ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$auth     = array();
		foreach ( array_chunk( array_keys( $by_id ), self::IN_CHUNK ) as $chunk ) {
			$auth += self::authoritative_owned( $chunk, 'variation' );
		}
		$findings = array();
		$groups   = 0;
		foreach ( $parents as $parent ) {
			$sigs = array();
			foreach ( isset( $children[ $parent ] ) ? $children[ $parent ] : array() as $vid ) {
				$sigs[ self::signature( $by_id[ $vid ] ) ][] = $vid;
			}
			foreach ( $sigs as $sig => $members ) {
				if ( count( $members ) < 2 ) {
					continue;
				}
				$groups++;
				$owners = array();
				foreach ( $members as $vid ) {
					if ( isset( $auth[ $vid ] ) ) {
						$owners[] = $vid;
					}
				}
				$group = substr( 'attr:' . $parent . ':' . $sig, 0, 191 );
				$mobo  = isset( $parent_owned[ $parent ] );
				foreach ( $members as $vid ) {
					if ( $mobo && 1 === count( $owners ) ) {
						if ( $vid === $owners[0] ) {
							continue;
						}
						$findings[] = self::finding( $vid, 'b', 'disable_variation', array( 'reason' => 'duplicate_attribute_set', 'canonical' => $owners[0], 'parent' => $parent, 'members' => $members ), $group, $owners[0] );
					} else {
						$findings[] = self::finding( $vid, 'c', '', array( 'reason' => $mobo ? 'ambiguous_attribute_duplicates' : 'customer_catalog', 'parent' => $parent, 'members' => $members, 'owners' => $owners ), $group );
					}
				}
			}
		}
		return self::result( $findings, $groups, max( $parents ), count( $parents ) < self::PARENT_LIMIT );
	}

	/** @return array */
	private static function scan_product_type_term( $cursor, array &$dstate ) {
		global $wpdb;
		$max = self::max_id( $dstate, $wpdb->posts, 'ID' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor );
		$tr   = self::table( 'term_relationships' );
		$tt   = self::table( 'term_taxonomy' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, (SELECT COUNT(*) FROM {$tr} tr INNER JOIN {$tt} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.taxonomy='product_type' AND tr.object_id=p.ID) AS c FROM {$wpdb->posts} p WHERE p.ID>%d AND p.ID<=%d AND p.post_type='product' AND p.post_status NOT IN " . self::LIVE,
				$low,
				$high
			),
			ARRAY_A
		);
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		foreach ( $rows as $row ) {
			$c = absint( $row['c'] );
			if ( 1 !== $c ) {
				$findings[] = self::finding( absint( $row['ID'] ), 'c', '', array( 'reason' => 0 === $c ? 'product_type_missing' : 'product_type_multiple', 'terms' => $c ) );
			}
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/** @return array */
	private static function scan_variable_without_children( $cursor, array &$dstate ) {
		global $wpdb;
		$max = self::max_id( $dstate, $wpdb->posts, 'ID' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor );
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p WHERE p.ID>%d AND p.ID<=%d AND p.post_type='product' AND p.post_status='publish' AND " . self::has_type_sql( 'p', 'variable' ) . " AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} v WHERE v.post_parent=p.ID AND v.post_type='product_variation' AND v.post_status='publish')",
				$low,
				$high
			)
		);
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$owned    = self::mobo_owned( $ids, 'product' );
		$findings = array();
		foreach ( $ids as $id ) {
			$findings[] = self::finding( absint( $id ), 'c', '', array( 'reason' => 'variable_without_published_variations', 'mobo' => is_array( $owned ) && isset( $owned[ absint( $id ) ] ) ) );
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/* ------------------------------------------------------------------ */
	/* WooCommerce indexes                                                */
	/* ------------------------------------------------------------------ */

	/** @return string SQL predicate: tt is a product taxonomy. */
	private static function product_taxonomy_sql( $alias ) {
		return "({$alias}.taxonomy IN (" . self::in_strings( self::PRODUCT_TAXONOMIES ) . ") OR SUBSTR({$alias}.taxonomy,1,3)='pa_')";
	}

	/** @return array */
	private static function scan_orphan_term_relationship( $cursor, array &$dstate ) {
		global $wpdb;
		$tr  = self::table( 'term_relationships' );
		$tt  = self::table( 'term_taxonomy' );
		$max = self::max_id( $dstate, $tr, 'object_id' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor, self::WINDOW * 2 );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id, tr.term_taxonomy_id, tt.taxonomy FROM {$tr} tr INNER JOIN {$tt} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id LEFT JOIN {$wpdb->posts} p ON p.ID=tr.object_id WHERE tr.object_id>%d AND tr.object_id<=%d AND p.ID IS NULL AND " . self::product_taxonomy_sql( 'tt' ) . ' ORDER BY tr.object_id ASC, tr.term_taxonomy_id ASC',
				$low,
				$high
			),
			ARRAY_A
		);
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		foreach ( $rows as $row ) {
			$findings[] = self::finding( absint( $row['object_id'] ), 'a', 'delete_row', array( 'reason' => 'object_missing', 'taxonomy' => (string) $row['taxonomy'] ), '', 0, 'tr:' . absint( $row['object_id'] ) . ':' . absint( $row['term_taxonomy_id'] ) );
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/** @return array */
	private static function scan_lookup_missing_or_stale( $cursor, array &$dstate ) {
		global $wpdb;
		if ( ! self::has( 'lookup' ) ) {
			return self::result( array(), 0, 0, true );
		}
		$max = self::max_id( $dstate, $wpdb->posts, 'ID' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor );
		$l       = self::table( 'lookup' );
		$reasons = array();
		$queries = array(
			'lookup_missing'   => "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$l} l ON l.product_id=p.ID WHERE p.ID>%d AND p.ID<=%d AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN " . self::LIVE . ' AND l.product_id IS NULL',
			'stock_mismatch'   => "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$l} l ON l.product_id=p.ID INNER JOIN {$wpdb->postmeta} s ON s.post_id=p.ID AND s.meta_key='_stock_status' WHERE p.ID>%d AND p.ID<=%d AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN " . self::LIVE . " AND s.meta_value IN ('instock','outofstock','onbackorder') AND (l.stock_status IS NULL OR BINARY l.stock_status<>BINARY s.meta_value)",
			'price_mismatch'   => "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$l} l ON l.product_id=p.ID INNER JOIN {$wpdb->postmeta} pr ON pr.post_id=p.ID AND pr.meta_key='_price' WHERE p.ID>%d AND p.ID<=%d AND p.post_type='product_variation' AND p.post_status NOT IN " . self::LIVE . " AND pr.meta_value<>'' AND (l.min_price IS NULL OR ABS(l.min_price - (pr.meta_value+0)) > 0.0001)",
		);
		foreach ( $queries as $reason => $sql ) {
			$ids = $wpdb->get_col( $wpdb->prepare( $sql, $low, $high ) );
			if ( self::db_failed() ) {
				return self::result( array(), 0, $cursor, false, 'db_read_failed' );
			}
			foreach ( $ids as $id ) {
				$reasons[ absint( $id ) ][] = $reason;
			}
		}
		ksort( $reasons );
		$findings = array();
		foreach ( $reasons as $id => $why ) {
			$findings[] = self::finding( $id, 'a', 'regen_lookup', array( 'reason' => implode( ',', array_unique( $why ) ) ), '', 0, 'lookup:' . $id );
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/** @return array */
	private static function scan_lookup_orphan( $cursor, array &$dstate ) {
		global $wpdb;
		if ( ! self::has( 'lookup' ) ) {
			return self::result( array(), 0, 0, true );
		}
		$l   = self::table( 'lookup' );
		$max = self::max_id( $dstate, $l, 'product_id' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor, self::WINDOW * 2 );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT l.product_id FROM {$l} l LEFT JOIN {$wpdb->posts} p ON p.ID=l.product_id WHERE l.product_id>%d AND l.product_id<=%d AND p.ID IS NULL ORDER BY l.product_id ASC", $low, $high ) );
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		foreach ( $ids as $id ) {
			$findings[] = self::finding( absint( $id ), 'a', 'delete_row', array( 'reason' => 'product_missing' ), '', 0, 'lookup:' . absint( $id ) );
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/** @return array */
	private static function scan_attr_lookup_orphan( $cursor, array &$dstate ) {
		global $wpdb;
		if ( ! self::has( 'attr_lookup' ) ) {
			return self::result( array(), 0, 0, true );
		}
		$a   = self::table( 'attr_lookup' );
		$max = self::max_id( $dstate, $a, 'product_id' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor, self::WINDOW * 2 );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT a.product_id, COUNT(*) AS n FROM {$a} a LEFT JOIN {$wpdb->posts} p ON p.ID=a.product_id WHERE a.product_id>%d AND a.product_id<=%d AND p.ID IS NULL GROUP BY a.product_id ORDER BY a.product_id ASC", $low, $high ), ARRAY_A );
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		foreach ( $rows as $row ) {
			$findings[] = self::finding( absint( $row['product_id'] ), 'a', 'delete_row', array( 'reason' => 'product_missing', 'rows' => absint( $row['n'] ) ), '', 0, 'attr:' . absint( $row['product_id'] ), 'open', absint( $row['n'] ) );
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/* ------------------------------------------------------------------ */
	/* Media                                                              */
	/* ------------------------------------------------------------------ */

	/** @return array */
	private static function scan_media_ref_missing( $cursor, array &$dstate ) {
		global $wpdb;
		$max = self::max_id( $dstate, $wpdb->posts, 'ID' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} o ON o.ID=pm.post_id WHERE pm.post_id>%d AND pm.post_id<=%d AND pm.meta_key IN ('_thumbnail_id','_product_image_gallery') AND o.post_type IN ('product','product_variation') AND o.post_status NOT IN " . self::LIVE . " AND pm.meta_value<>'' AND pm.meta_value<>'0'",
				$low,
				$high
			),
			ARRAY_A
		);
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$refs = array();
		$all  = array();
		foreach ( $rows as $row ) {
			$ids = array_filter( array_map( 'absint', explode( ',', (string) $row['meta_value'] ) ) );
			foreach ( $ids as $aid ) {
				$refs[ absint( $row['post_id'] ) ][ (string) $row['meta_key'] ][] = $aid;
				$all[ $aid ] = true;
			}
		}
		$exists = array();
		foreach ( array_chunk( array_keys( $all ), self::IN_CHUNK ) as $chunk ) {
			foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND ID IN (" . self::in_ints( $chunk ) . ')' ) as $aid ) {
				$exists[ absint( $aid ) ] = true;
			}
			if ( self::db_failed() ) {
				return self::result( array(), 0, $cursor, false, 'db_read_failed' );
			}
		}
		ksort( $refs );
		$findings = array();
		foreach ( $refs as $pid => $by_key ) {
			$missing_thumb   = 0;
			$missing_gallery = array();
			foreach ( isset( $by_key['_thumbnail_id'] ) ? $by_key['_thumbnail_id'] : array() as $aid ) {
				if ( ! isset( $exists[ $aid ] ) ) {
					$missing_thumb = $aid;
				}
			}
			foreach ( isset( $by_key['_product_image_gallery'] ) ? $by_key['_product_image_gallery'] : array() as $aid ) {
				if ( ! isset( $exists[ $aid ] ) ) {
					$missing_gallery[] = $aid;
				}
			}
			if ( $missing_thumb > 0 || ! empty( $missing_gallery ) ) {
				$findings[] = self::finding( $pid, 'b', 'clear_media_ref', array( 'reason' => 'attachment_missing', 'thumbnail' => $missing_thumb, 'gallery' => $missing_gallery ) );
			}
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/** @return string Uploads base directory or '' when unavailable. */
	private static function uploads_basedir() {
		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$dir = wp_get_upload_dir();
			return is_array( $dir ) && ! empty( $dir['basedir'] ) ? rtrim( (string) $dir['basedir'], '/\\' ) : '';
		}
		return '';
	}

	/** @return array */
	private static function scan_attachment_file_missing( $cursor, array &$dstate ) {
		global $wpdb;
		$base = self::uploads_basedir();
		$can  = ! class_exists( 'Mobo_Core_Environment' ) || ( Mobo_Core_Environment::can( 'file_exists' ) && Mobo_Core_Environment::can( 'filesize' ) );
		if ( '' === $base || ! $can ) {
			$dstate['note'] = 'filesystem_unavailable';
			return self::result( array(), 0, 0, true );
		}
		$max = self::max_id( $dstate, $wpdb->posts, 'ID', "post_type='attachment'" );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor, self::FILE_WINDOW );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.ID, f.meta_value AS file FROM {$wpdb->posts} a LEFT JOIN {$wpdb->postmeta} f ON f.post_id=a.ID AND f.meta_key='_wp_attached_file' WHERE a.ID>%d AND a.ID<=%d AND a.post_type='attachment' AND SUBSTR(a.post_mime_type,1,6)='image/'",
				$low,
				$high
			),
			ARRAY_A
		);
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$bad = array();
		foreach ( $rows as $row ) {
			$file = (string) $row['file'];
			if ( '' === $file ) {
				$bad[ absint( $row['ID'] ) ] = 'no_file_meta';
				continue;
			}
			if ( false !== strpos( $file, '..' ) ) {
				continue;
			}
			$path = ( preg_match( '#^([a-zA-Z]:)?[/\\\\]#', $file ) ? '' : $base . '/' ) . $file;
			if ( ! @file_exists( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir hosts warn.
				$bad[ absint( $row['ID'] ) ] = 'file_missing';
			} elseif ( 0 === (int) @filesize( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$bad[ absint( $row['ID'] ) ] = 'zero_bytes';
			}
		}
		$used = array();
		if ( ! empty( $bad ) ) {
			foreach ( (array) $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' AND meta_value IN (" . self::in_strings( array_map( 'strval', array_keys( $bad ) ) ) . ')' ) as $aid ) {
				$used[ absint( $aid ) ] = true;
			}
		}
		$findings = array();
		foreach ( $bad as $aid => $reason ) {
			$findings[] = self::finding( $aid, 'c', '', array( 'reason' => $reason, 'usedAsThumbnail' => isset( $used[ $aid ] ) ) );
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/** @return array Cursor = last file path. */
	private static function scan_shared_attached_file( $cursor, array &$dstate ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.meta_value AS file, COUNT(*) AS n, MIN(f.post_id) AS first_id FROM {$wpdb->postmeta} f INNER JOIN {$wpdb->posts} a ON a.ID=f.post_id AND a.post_type='attachment' WHERE f.meta_key='_wp_attached_file' AND f.meta_value>%s GROUP BY f.meta_value HAVING COUNT(*)>1 ORDER BY f.meta_value ASC LIMIT %d",
				(string) $cursor,
				self::GROUP_LIMIT
			),
			ARRAY_A
		);
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		$next     = (string) $cursor;
		foreach ( $rows as $row ) {
			$findings[] = self::finding( absint( $row['first_id'] ), 'c', '', array( 'reason' => 'shared_physical_file', 'attachments' => absint( $row['n'] ) ), '', 0, '', 'open', absint( $row['n'] ) );
			$next       = (string) $row['file'];
		}
		return self::result( $findings, count( $rows ), $next, count( $rows ) < self::GROUP_LIMIT );
	}

	/* ------------------------------------------------------------------ */
	/* Price / stock / text                                               */
	/* ------------------------------------------------------------------ */

	/** @return array */
	private static function scan_duplicate_single_meta( $cursor, array &$dstate ) {
		global $wpdb;
		$max = self::max_id( $dstate, $wpdb->posts, 'ID' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_key, COUNT(*) AS n FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.post_id>%d AND pm.post_id<=%d AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN " . self::LIVE . " AND (pm.meta_key IN ('_regular_price','_sale_price','_stock_status','_stock','_sku','_manage_stock') OR (pm.meta_key='_price' AND p.post_type='product_variation')) GROUP BY pm.post_id, pm.meta_key HAVING COUNT(*)>1",
				$low,
				$high
			),
			ARRAY_A
		);
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$by_post = array();
		foreach ( $rows as $row ) {
			$by_post[ absint( $row['post_id'] ) ][] = (string) $row['meta_key'];
		}
		$findings = array();
		foreach ( $by_post as $pid => $keys ) {
			$findings[] = self::finding( $pid, 'c', '', array( 'reason' => 'duplicate_meta_rows', 'keys' => $keys ) );
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/** @return array */
	private static function scan_stock_meta_invalid( $cursor, array &$dstate ) {
		global $wpdb;
		$max = self::max_id( $dstate, $wpdb->posts, 'ID' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor );
		$invalid = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.post_id>%d AND pm.post_id<=%d AND pm.meta_key='_stock_status' AND pm.meta_value NOT IN ('instock','outofstock','onbackorder') AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN " . self::LIVE, $low, $high ) );
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$noqty = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id LEFT JOIN {$wpdb->postmeta} q ON q.post_id=p.ID AND q.meta_key='_stock' WHERE pm.post_id>%d AND pm.post_id<=%d AND pm.meta_key='_manage_stock' AND pm.meta_value='yes' AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN " . self::LIVE . " AND (q.meta_id IS NULL OR q.meta_value IS NULL OR q.meta_value='')", $low, $high ) );
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		foreach ( array_unique( array_map( 'absint', $invalid ) ) as $id ) {
			$findings[] = self::finding( $id, 'c', '', array( 'reason' => 'invalid_stock_status' ) );
		}
		foreach ( array_unique( array_map( 'absint', $noqty ) ) as $id ) {
			$findings[] = self::finding( $id, 'c', '', array( 'reason' => 'managed_stock_without_quantity' ) );
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/** @return array */
	private static function scan_mobo_instock_without_price( $cursor, array &$dstate ) {
		global $wpdb;
		$max = self::max_id( $dstate, $wpdb->posts, 'ID' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor );
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} s ON s.post_id=p.ID AND s.meta_key='_stock_status' AND s.meta_value='instock' LEFT JOIN {$wpdb->postmeta} pr ON pr.post_id=p.ID AND pr.meta_key='_price' WHERE p.ID>%d AND p.ID<=%d AND p.post_type='product_variation' AND p.post_status='publish' AND (pr.meta_id IS NULL OR pr.meta_value IS NULL OR pr.meta_value='')",
				$low,
				$high
			)
		);
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$owned    = self::mobo_owned( $ids, 'variation' );
		$findings = array();
		foreach ( is_array( $owned ) ? array_keys( $owned ) : array() as $id ) {
			$findings[] = self::finding( $id, 'c', '', array( 'reason' => 'mobo_variation_in_stock_without_price' ) );
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/** @return array */
	private static function scan_question_mark_text( $cursor, array &$dstate ) {
		global $wpdb;
		$max = self::max_id( $dstate, $wpdb->posts, 'ID' );
		if ( false === $max ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		list( $low, $high ) = self::window( $cursor );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND ID<=%d AND post_type IN ('product','product_variation') AND post_status NOT IN " . self::LIVE . " AND post_title LIKE '%%????%%'", $low, $high ) );
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		foreach ( $ids as $id ) {
			$findings[] = self::finding( absint( $id ), 'c', '', array( 'reason' => 'title_question_marks' ) );
		}
		return self::result( $findings, 0, $high, $high >= $max );
	}

	/** @return array */
	private static function scan_question_mark_terms( $cursor, array &$dstate ) {
		global $wpdb;
		$t    = self::table( 'terms' );
		$tt   = self::table( 'term_taxonomy' );
		$rows = $wpdb->get_results( "SELECT t.term_id, tt.taxonomy FROM {$t} t INNER JOIN {$tt} tt ON tt.term_id=t.term_id WHERE " . self::product_taxonomy_sql( 'tt' ) . " AND t.name LIKE '%????%' LIMIT 5000", ARRAY_A );
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		foreach ( $rows as $row ) {
			$findings[] = self::finding( absint( $row['term_id'] ), 'c', '', array( 'reason' => 'term_question_marks', 'taxonomy' => (string) $row['taxonomy'] ), '', 0, 'term:' . absint( $row['term_id'] ) );
		}
		return self::result( $findings, 0, 1, true );
	}

	/* ------------------------------------------------------------------ */
	/* Runtime state                                                      */
	/* ------------------------------------------------------------------ */

	/** @return array */
	private static function scan_quarantine( $cursor, array &$dstate ) {
		if ( ! class_exists( 'Mobo_Core_Migration_Quarantine' ) ) {
			return self::result( array(), 0, 1, true );
		}
		$findings = array();
		foreach ( Mobo_Core_Migration_Quarantine::all() as $post_id => $entry ) {
			$findings[] = self::finding( absint( $post_id ), 'c', '', array( 'reason' => sanitize_key( (string) ( isset( $entry['r'] ) ? $entry['r'] : 'unknown' ) ), 'type' => sanitize_key( (string) ( isset( $entry['t'] ) ? $entry['t'] : '' ) ) ) );
		}
		if ( method_exists( 'Mobo_Core_Migration_Quarantine', 'health_payload' ) ) {
			$payload = Mobo_Core_Migration_Quarantine::health_payload();
			if ( is_array( $payload ) && ! empty( $payload['overflow'] ) ) {
				$dstate['note'] = 'quarantine_overflow';
			}
		}
		return self::result( $findings, 0, 1, true );
	}

	/** @return array */
	private static function scan_stale_locks( $cursor, array &$dstate ) {
		global $wpdb;
		$prefix = 'mobo_core_runtime_lock_';
		$like   = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $prefix ) . '%' : $prefix . '%';
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 2000", $like ), ARRAY_A );
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$now      = time();
		$findings = array();
		foreach ( $rows as $row ) {
			$name = (string) $row['option_name'];
			if ( 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			$data    = json_decode( (string) $row['option_value'], true );
			$expires = is_array( $data ) && isset( $data['expires_at'] ) ? absint( $data['expires_at'] ) : 0;
			if ( $expires > 0 && $expires < $now - 3600 ) {
				$findings[] = self::finding( 0, 'a', 'recover_locks', array( 'reason' => 'lock_expired', 'ageSeconds' => $now - $expires ), '', 0, substr( $name, strlen( $prefix ) ) );
			}
		}
		return self::result( $findings, 0, 1, true );
	}

	/** @return array */
	private static function scan_stale_processing_rows( $cursor, array &$dstate ) {
		global $wpdb;
		$lock_cutoff    = gmdate( 'Y-m-d H:i:s', time() - 3600 );
		$updated_cutoff = gmdate( 'Y-m-d H:i:s', time() - 12 * 3600 );
		$findings       = array();
		foreach ( array( 'sync_events', 'image_queue' ) as $key ) {
			if ( ! self::has( $key ) ) {
				continue;
			}
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, updated_at FROM ' . self::table( $key ) . " WHERE status='processing' AND (locked_until IS NULL OR locked_until<%s) AND updated_at<%s ORDER BY id ASC LIMIT 2000", $lock_cutoff, $updated_cutoff ), ARRAY_A );
			if ( self::db_failed() ) {
				return self::result( array(), 0, $cursor, false, 'db_read_failed' );
			}
			foreach ( $rows as $row ) {
				$findings[] = self::finding( absint( $row['id'] ), 'b', 'reclaim_row', array( 'reason' => 'processing_without_live_claim', 'table' => $key, 'updatedAt' => (string) $row['updated_at'] ), '', 0, $key . ':' . absint( $row['id'] ) );
			}
		}
		return self::result( $findings, 0, 1, true );
	}

	/** @return array */
	private static function scan_half_migrated( $cursor, array &$dstate ) {
		global $wpdb;
		$findings = array();
		if ( self::has( 'migrations' ) ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - 3600 );
			$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT id, migration_key, status FROM ' . self::table( 'migrations' ) . " WHERE status NOT IN ('completed','complete','done') AND updated_at<%s LIMIT 200", $cutoff ), ARRAY_A );
			if ( self::db_failed() ) {
				return self::result( array(), 0, $cursor, false, 'db_read_failed' );
			}
			foreach ( $rows as $row ) {
				$findings[] = self::finding( absint( $row['id'] ), 'c', '', array( 'reason' => 'migration_not_completed', 'status' => sanitize_key( (string) $row['status'] ) ), '', 0, 'migration:' . sanitize_key( (string) $row['migration_key'] ) );
			}
		}
		if ( class_exists( 'Mobo_Core_Storage_Schema' ) && '' !== (string) get_option( Mobo_Core_Storage_Schema::VERSION_OPTION, '' ) && ! Mobo_Core_Storage_Schema::is_ready() ) {
			$findings[] = self::finding( 0, 'c', '', array( 'reason' => 'custom_storage_not_ready' ), '', 0, 'storage_schema' );
		}
		if ( class_exists( 'Mobo_Core_Migration_Quarantine' ) && method_exists( 'Mobo_Core_Migration_Quarantine', 'health_payload' ) ) {
			$payload = Mobo_Core_Migration_Quarantine::health_payload();
			$status  = is_array( $payload ) && isset( $payload['status'] ) ? sanitize_key( (string) $payload['status'] ) : '';
			if ( in_array( $status, array( 'blocked', 'overflow', 'failed' ), true ) ) {
				$findings[] = self::finding( 0, 'c', '', array( 'reason' => 'database_migration_' . $status ), '', 0, 'database_migration' );
			}
		}
		return self::result( $findings, 0, 1, true );
	}

	/** @return array */
	private static function scan_false_success_timestamp( $cursor, array &$dstate ) {
		global $wpdb;
		if ( ! self::has( 'sync_health' ) || ! self::has( 'migrations' ) ) {
			return self::result( array(), 0, 1, true );
		}
		$window = $wpdb->get_row( 'SELECT MIN(started_at) AS s, MAX(completed_at) AS e FROM ' . self::table( 'migrations' ) . ' WHERE started_at IS NOT NULL', ARRAY_A );
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		if ( ! is_array( $window ) || empty( $window['s'] ) ) {
			return self::result( array(), 0, 1, true );
		}
		$start = (string) $window['s'];
		$end   = ! empty( $window['e'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $window['e'] . ' UTC' ) + 120 ) : gmdate( 'Y-m-d H:i:s' );
		$count = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'sync_health' ) . ' WHERE last_successful_sync_time>=%s AND last_successful_sync_time<=%s', $start, $end ) ) );
		if ( self::db_failed() ) {
			return self::result( array(), 0, $cursor, false, 'db_read_failed' );
		}
		$findings = array();
		if ( $count > 0 ) {
			$findings[] = self::finding( 0, 'c', '', array( 'reason' => 'success_time_inside_migration_window', 'rows' => $count ), '', 0, 'sync_health', 'open', $count );
		}
		return self::result( $findings, 0, 1, true );
	}
}
