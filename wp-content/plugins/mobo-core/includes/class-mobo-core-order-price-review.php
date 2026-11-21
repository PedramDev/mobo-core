<?php
/**
 * Automatic-purchase price guard and order-queue deferral backoff.
 *
 * The pure helpers (backoff, decimal canonicalization, unit shift, Source cart
 * price extraction and per-line comparison) use no WordPress API and are unit
 * tested standalone. The remaining methods own the administrator side effects
 * of a "needs manual review: price difference" order: a bounded wp-admin
 * notice index and one Persian e-mail per order.
 *
 * Comparison contract: every line is compared PER UNIT and PER VARIANT, never
 * on the order total, so Source shipping is never reported as a price change.
 * There is no tolerance: any difference, an unknown expected price, or an
 * unreadable Source price stops the automatic purchase before Checkout/Wallet.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Order_Price_Review {
	const STATUS                = 'price_review';
	const ERROR_CODE            = 'mobo_core_order_submission_price_review';
	const OPTION_INDEX          = 'mobo_core_price_review_orders';
	const INDEX_MAX             = 50;
	const NOTICE_MAX            = 15;
	const BACKOFF_BASE_SECONDS  = 60;
	const BACKOFF_MAX_SECONDS   = 3600;
	const DISMISS_ACTION        = 'mobo_core_dismiss_price_review';
	/** Per-Connection setting: "بررسی اختلاف قیمت قبل از خرید خودکار" (default '1'). */
	const OPTION_GUARD_ENABLED  = 'mobo_core_source_price_guard_enabled';

	/* ------------------------------------------------------------------
	 * Pure helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Bounded exponential backoff for a pre-payment deferral: 1m, 2m, 4m ... 1h.
	 *
	 * @param int $defer_count 1-based consecutive deferral count for one reason.
	 * @return int Seconds.
	 */
	public static function defer_backoff_seconds( $defer_count ) {
		$count = max( 1, (int) $defer_count );
		$delay = self::BACKOFF_BASE_SECONDS;
		for ( $i = 1; $i < $count && $delay < self::BACKOFF_MAX_SECONDS; $i++ ) {
			$delay *= 2;
		}
		return (int) min( self::BACKOFF_MAX_SECONDS, $delay );
	}

	/**
	 * Whether the Source price guard is enabled for the current Connection
	 * (setting "بررسی اختلاف قیمت قبل از خرید خودکار", default ON).
	 *
	 * @return bool
	 */
	public static function guard_enabled() {
		if ( ! class_exists( 'Mobo_Core_Settings' ) || ! method_exists( 'Mobo_Core_Settings', 'enabled' ) ) {
			return true; // Fail closed: keep guarding.
		}
		return Mobo_Core_Settings::enabled( self::OPTION_GUARD_ENABLED, '1' );
	}

	/**
	 * Normalize Persian/Arabic-Indic digits and separators to ASCII.
	 * "۱۲۵٬۰۰۰" -> "125,000", "١٢٫٥" -> "12.5".
	 *
	 * @param string $value Raw.
	 * @return string
	 */
	public static function normalize_digits( $value ) {
		$value = (string) $value;
		$map   = array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			"\xD9\xAC" => ',', // U+066C ARABIC THOUSANDS SEPARATOR.
			"\xD9\xAB" => '.', // U+066B ARABIC DECIMAL SEPARATOR.
			"\xE2\x80\x8F" => '', "\xE2\x80\x8E" => '', "\xE2\x80\x8C" => '', // RLM, LRM, ZWNJ.
			"\xE2\x80\xAF" => '', "\xE2\x80\x89" => '', "\xC2\xA0" => '', // Narrow/thin/no-break spaces.
		);
		return strtr( $value, $map );
	}

	/**
	 * Canonical non-negative decimal string ("1250000.000000" -> "1250000").
	 * Persian/Arabic digits and well-formed thousands grouping ("1,250,000",
	 * "۱٬۲۵۰٬۰۰۰") are accepted; malformed grouping, exponents, negatives and
	 * booleans are rejected. Two amounts are numerically equal iff their
	 * canonical strings are equal.
	 *
	 * @param mixed $value Raw amount.
	 * @return string|null
	 */
	public static function canonical_decimal( $value ) {
		if ( is_bool( $value ) || null === $value ) {
			return null;
		}
		if ( is_int( $value ) ) {
			return $value >= 0 ? (string) $value : null;
		}
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) || $value < 0 || $value > 9007199254740991 ) {
				return null;
			}
			$value = sprintf( '%.6F', $value );
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$raw = str_replace( array( "\xC2\xA0", ' ' ), '', trim( self::normalize_digits( $value ) ) );
		$raw = ltrim( $raw, '+' );
		if ( false !== strpos( $raw, ',' ) ) {
			if ( 1 !== preg_match( '/^[0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]+)?$/D', $raw ) ) {
				return null; // Malformed grouping is never guessed.
			}
			$raw = str_replace( ',', '', $raw );
		}
		if ( 1 !== preg_match( '/^([0-9]+)(?:\.([0-9]+))?$/D', $raw, $m ) ) {
			return null;
		}
		$whole    = ltrim( $m[1], '0' );
		$fraction = isset( $m[2] ) ? rtrim( $m[2], '0' ) : '';
		if ( '' === $whole ) {
			$whole = '0';
		}
		return '' === $fraction ? $whole : $whole . '.' . $fraction;
	}

	/**
	 * Numeric a <= b on canonical decimals, strings only (no float rounding).
	 *
	 * @param string $a Canonical decimal.
	 * @param string $b Canonical decimal.
	 * @return bool
	 */
	public static function decimal_lte( $a, $b ) {
		$a = self::canonical_decimal( $a );
		$b = self::canonical_decimal( $b );
		if ( null === $a || null === $b ) {
			return false;
		}
		$pa = explode( '.', $a, 2 );
		$pb = explode( '.', $b, 2 );
		if ( strlen( $pa[0] ) !== strlen( $pb[0] ) ) {
			return strlen( $pa[0] ) < strlen( $pb[0] );
		}
		$fa  = isset( $pa[1] ) ? $pa[1] : '';
		$fb  = isset( $pb[1] ) ? $pb[1] : '';
		$len = max( strlen( $fa ), strlen( $fb ) );
		return strcmp( $pa[0] . str_pad( $fa, $len, '0' ), $pb[0] . str_pad( $fb, $len, '0' ) ) <= 0;
	}

	/** Shift a canonical decimal by a power of ten using strings only. @return string|null */
	public static function shift_decimal( $canonical, $exponent ) {
		$canonical = self::canonical_decimal( $canonical );
		if ( null === $canonical ) {
			return null;
		}
		$exponent = (int) $exponent;
		$parts    = explode( '.', $canonical, 2 );
		$whole    = $parts[0];
		$fraction = isset( $parts[1] ) ? $parts[1] : '';
		$digits   = $whole . $fraction;
		$point    = strlen( $whole ) + $exponent;
		if ( $point <= 0 ) {
			return self::canonical_decimal( '0.' . str_repeat( '0', -$point ) . $digits );
		}
		if ( $point >= strlen( $digits ) ) {
			return self::canonical_decimal( $digits . str_repeat( '0', $point - strlen( $digits ) ) );
		}
		return self::canonical_decimal( substr( $digits, 0, $point ) . '.' . substr( $digits, $point ) );
	}

	/** Normalize a declared money unit (IRT/Toman, IRR/Rial). @return string */
	public static function normalize_unit( $value ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}
		$raw = trim( (string) $value );
		if ( in_array( $raw, array( 'تومان', 'تومن' ), true ) ) {
			return 'IRT';
		}
		if ( 'ریال' === $raw ) {
			return 'IRR';
		}
		$unit = strtoupper( $raw );
		if ( in_array( $unit, array( 'TOMAN', 'TOMANS', 'TOMAN_IR' ), true ) ) {
			return 'IRT';
		}
		if ( in_array( $unit, array( 'RIAL', 'RIALS' ), true ) ) {
			return 'IRR';
		}
		return 1 === preg_match( '/^[A-Z0-9_]{2,16}$/D', $unit ) ? $unit : '';
	}

	/**
	 * Exact unit conversion for the fixed IRT/IRR relation (1 IRT = 10 IRR).
	 * Any other pair is unsupported (null) - no FX rates are ever inferred.
	 *
	 * @return string|null Canonical decimal in $to_unit.
	 */
	public static function convert_unit( $amount, $from_unit, $to_unit ) {
		$from = self::normalize_unit( $from_unit );
		$to   = self::normalize_unit( $to_unit );
		if ( '' === $from || '' === $to ) {
			return null;
		}
		if ( $from === $to ) {
			return self::canonical_decimal( $amount );
		}
		if ( 'IRT' === $from && 'IRR' === $to ) {
			return self::shift_decimal( $amount, 1 );
		}
		if ( 'IRR' === $from && 'IRT' === $to ) {
			return self::shift_decimal( $amount, -1 );
		}
		return null;
	}

	/**
	 * Explicit money unit declared by a Source cart response, if any.
	 *
	 * @param mixed $snapshot Decoded cart response.
	 * @return string Normalized unit or '' when the response does not declare one.
	 */
	public static function extract_snapshot_unit( $snapshot ) {
		if ( ! is_array( $snapshot ) ) {
			return '';
		}
		$cart = isset( $snapshot['cart'] ) && is_array( $snapshot['cart'] ) ? $snapshot['cart'] : array();
		foreach ( array( $cart, $snapshot ) as $container ) {
			foreach ( array( 'currency', 'currency_code', 'currencyCode', 'money_unit', 'moneyUnit' ) as $key ) {
				if ( isset( $container[ $key ] ) && is_scalar( $container[ $key ] ) && '' !== trim( (string) $container[ $key ] ) ) {
					$unit = self::normalize_unit( $container[ $key ] );
					return '' !== $unit ? $unit : 'UNKNOWN_DECLARED';
				}
			}
		}
		return '';
	}

	/**
	 * Per-unit Source price of one cart row: the price the Source will
	 * actually charge for one unit.
	 *
	 * Field precedence (the chosen field is returned in 'field' and logged):
	 * 1. An ACTIVE per-unit sale/discount price on the variant or row
	 *    (product.variant.final_price|sale_price|discount_price|discounted_price|
	 *    special_price, camelCase variants, or row final_unit_price) - active
	 *    means parseable, > 0 and not above product.variant.price.
	 * 2. product.variant.price (the same field Product Sync stores as
	 *    mobo_api_price; compare_price is the struck-through original and is
	 *    never used).
	 * 3. unit_price, unitPrice, price.
	 * A malformed value in the chosen field stays null (fail closed).
	 *
	 * @param mixed $cart_item One raw cart.items[] row.
	 * @return array{price:?string,field:string}
	 */
	public static function extract_remote_unit_price( $cart_item ) {
		$out = array( 'price' => null, 'field' => '' );
		if ( ! is_array( $cart_item ) ) {
			return $out;
		}
		$variant = isset( $cart_item['product']['variant'] ) && is_array( $cart_item['product']['variant'] ) ? $cart_item['product']['variant'] : array();
		$base    = array_key_exists( 'price', $variant ) && null !== $variant['price'] && '' !== $variant['price'] ? self::canonical_decimal( $variant['price'] ) : null;
		$sale_candidates = array();
		foreach ( array( 'final_price', 'finalPrice', 'sale_price', 'salePrice', 'discount_price', 'discountPrice', 'discounted_price', 'discountedPrice', 'special_price', 'specialPrice' ) as $key ) {
			if ( array_key_exists( $key, $variant ) ) {
				$sale_candidates[ 'product.variant.' . $key ] = $variant[ $key ];
			}
		}
		foreach ( array( 'final_unit_price', 'finalUnitPrice' ) as $key ) {
			if ( array_key_exists( $key, $cart_item ) ) {
				$sale_candidates[ $key ] = $cart_item[ $key ];
			}
		}
		foreach ( $sale_candidates as $field => $value ) {
			$sale = ( null === $value || '' === $value || is_bool( $value ) ) ? null : self::canonical_decimal( $value );
			if ( null === $sale || '0' === $sale ) {
				continue; // Absent/zero/unreadable sale price means "no active discount".
			}
			if ( null !== $base && ! self::decimal_lte( $sale, $base ) ) {
				continue; // A "sale" above the list price is not a discount.
			}
			$out['field'] = $field;
			$out['price'] = $sale;
			return $out;
		}
		$candidates = array(
			'product.variant.price' => array_key_exists( 'price', $variant ) ? $variant['price'] : null,
			'unit_price'            => array_key_exists( 'unit_price', $cart_item ) ? $cart_item['unit_price'] : null,
			'unitPrice'             => array_key_exists( 'unitPrice', $cart_item ) ? $cart_item['unitPrice'] : null,
			'price'                 => array_key_exists( 'price', $cart_item ) ? $cart_item['price'] : null,
		);
		foreach ( $candidates as $field => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$out['field'] = $field;
			$out['price'] = self::canonical_decimal( $value );
			return $out; // First present field is authoritative; malformed stays null (fail closed).
		}
		return $out;
	}

	/**
	 * Compare expected and Source unit prices per variant line.
	 *
	 * @param array $expected variantKey => array{name:string, expected:?string} (canonical, expected unit).
	 * @param array $remote   variantKey => list<?string> Source per-unit prices already in the expected unit.
	 * @return array List of mismatches: variantId, name, expected, remote, reason.
	 */
	public static function compare_lines( $expected, $remote ) {
		$mismatches = array();
		foreach ( is_array( $expected ) ? $expected : array() as $key => $line ) {
			$key           = (string) $key;
			$name          = is_array( $line ) && isset( $line['name'] ) ? (string) $line['name'] : '';
			$expected_raw  = is_array( $line ) && array_key_exists( 'expected', $line ) ? $line['expected'] : null;
			$expected_norm = null === $expected_raw ? null : self::canonical_decimal( $expected_raw );
			if ( ! isset( $remote[ $key ] ) ) {
				continue; // Missing rows are owned by the existing cart/quantity comparison.
			}
			$prices = is_array( $remote[ $key ] ) ? $remote[ $key ] : array( $remote[ $key ] );
			foreach ( $prices as $remote_raw ) {
				$remote_norm = null === $remote_raw ? null : self::canonical_decimal( $remote_raw );
				$reason      = '';
				if ( null === $expected_norm ) {
					$reason = 'expected_unknown';
				} elseif ( null === $remote_norm ) {
					$reason = 'remote_unknown';
				} elseif ( $expected_norm !== $remote_norm ) {
					$reason = 'price_differs';
				}
				if ( '' === $reason ) {
					continue;
				}
				$mismatches[] = array(
					'variantId' => $key,
					'name'      => $name,
					'expected'  => $expected_norm,
					'remote'    => $remote_norm,
					'reason'    => $reason,
				);
				break; // One entry per line is enough for the administrator.
			}
		}
		return $mismatches;
	}

	/** True when one side is exactly ten times the other (likely Rial/Toman confusion). */
	public static function looks_like_unit_confusion( $expected, $remote ) {
		$expected = self::canonical_decimal( $expected );
		$remote   = self::canonical_decimal( $remote );
		if ( null === $expected || null === $remote || '0' === $expected || '0' === $remote ) {
			return false;
		}
		return self::shift_decimal( $expected, 1 ) === $remote || self::shift_decimal( $remote, 1 ) === $expected;
	}

	/**
	 * Stable identity of what the administrator reviewed: variant + Source price.
	 * The expected side is excluded so a later catalogue sync does not by itself
	 * invalidate an explicit administrator decision about the Source price.
	 */
	public static function mismatch_fingerprint( $mismatches ) {
		$rows = array();
		foreach ( is_array( $mismatches ) ? $mismatches : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = (string) ( isset( $row['variantId'] ) ? $row['variantId'] : '' ) . '=' . ( isset( $row['remote'] ) && null !== $row['remote'] ? (string) $row['remote'] : '?' );
		}
		sort( $rows, SORT_STRING );
		return hash( 'sha256', implode( '|', $rows ) );
	}

	/** Human readable amount with grouping, e.g. "1,250,000". */
	public static function format_amount( $canonical ) {
		$canonical = self::canonical_decimal( $canonical );
		if ( null === $canonical ) {
			return '';
		}
		$parts   = explode( '.', $canonical, 2 );
		$grouped = strrev( implode( ',', str_split( strrev( $parts[0] ), 3 ) ) );
		return isset( $parts[1] ) ? $grouped . '.' . $parts[1] : $grouped;
	}

	/** Persian label of a money unit. */
	public static function unit_label( $unit ) {
		$unit = self::normalize_unit( $unit );
		if ( 'IRT' === $unit ) {
			return 'تومان';
		}
		if ( 'IRR' === $unit ) {
			return 'ریال';
		}
		return $unit;
	}

	/**
	 * One Persian line per mismatching item (used by order note and e-mail).
	 *
	 * @return string[]
	 */
	public static function describe_mismatches( $mismatches, $unit ) {
		$label = self::unit_label( $unit );
		$lines = array();
		foreach ( is_array( $mismatches ) ? $mismatches : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name     = isset( $row['name'] ) && '' !== (string) $row['name'] ? (string) $row['name'] : 'محصول';
			$expected = isset( $row['expected'] ) && null !== $row['expected']
				? self::format_amount( $row['expected'] ) . ' ' . $label
				: 'نامشخص (قیمت همگام‌شده موجود نیست)';
			$remote   = isset( $row['remote'] ) && null !== $row['remote']
				? self::format_amount( $row['remote'] ) . ' ' . $label
				: 'نامشخص (قیمت در پاسخ Source قابل خواندن نیست)';
			$line = sprintf( '- %s (Variant #%s): قیمت مورد انتظار هر عدد %s، قیمت Source هر عدد %s', $name, (string) ( isset( $row['variantId'] ) ? $row['variantId'] : '' ), $expected, $remote );
			if ( isset( $row['expected'], $row['remote'] ) && self::looks_like_unit_confusion( $row['expected'], $row['remote'] ) ) {
				$line .= ' (اختلاف دقیقاً ۱۰ برابر است؛ احتمال ناهماهنگی واحد ریال/تومان را بررسی کنید)';
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/* ------------------------------------------------------------------
	 * WordPress side effects.
	 * ------------------------------------------------------------------ */

	/** Register admin hooks (called from the checkout validator in wp-admin). */
	public static function register_admin_hooks() {
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'handle_dismiss' ) );
	}

	private static function current_user_can_review() {
		return function_exists( 'current_user_can' ) && ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) );
	}

	private static function read_index() {
		$index = get_option( self::OPTION_INDEX, array() );
		return is_array( $index ) ? $index : array();
	}

	private static function write_index( $index ) {
		$clean = array();
		foreach ( is_array( $index ) ? $index : array() as $order_id => $at ) {
			$order_id = absint( $order_id );
			if ( $order_id > 0 ) {
				$clean[ $order_id ] = absint( $at );
			}
		}
		arsort( $clean, SORT_NUMERIC );
		$clean = array_slice( $clean, 0, self::INDEX_MAX, true );
		if ( empty( $clean ) ) {
			delete_option( self::OPTION_INDEX );
			return;
		}
		update_option( self::OPTION_INDEX, $clean, false );
	}

	/** Add one order to the wp-admin review notice. */
	public static function register_order( $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return;
		}
		$index              = self::read_index();
		$index[ $order_id ] = time();
		self::write_index( $index );
	}

	/** Remove one order from the wp-admin review notice. */
	public static function unregister_order( $order_id ) {
		$order_id = absint( $order_id );
		$index    = self::read_index();
		if ( $order_id > 0 && isset( $index[ $order_id ] ) ) {
			unset( $index[ $order_id ] );
			self::write_index( $index );
		}
	}

	/**
	 * Notice listing orders still in price review. Resolved orders (retried,
	 * cancelled, deleted) are pruned automatically; a dismissed order leaves only
	 * the notice - its order state and meta box keep the review evidence.
	 */
	public static function render_admin_notice() {
		if ( ! self::current_user_can_review() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$index = self::read_index();
		if ( empty( $index ) ) {
			return;
		}
		$rows    = array();
		$changed = false;
		foreach ( $index as $order_id => $at ) {
			$order = wc_get_order( absint( $order_id ) );
			if ( ! $order instanceof WC_Order || self::STATUS !== sanitize_key( (string) $order->get_meta( '_mobo_order_submit_status', true ) ) ) {
				unset( $index[ $order_id ] );
				$changed = true;
				continue;
			}
			if ( count( $rows ) < self::NOTICE_MAX ) {
				$rows[] = $order;
			}
		}
		if ( $changed ) {
			self::write_index( $index );
		}
		if ( empty( $rows ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html( 'موبو: سفارش‌های زیر به‌دلیل اختلاف قیمت Source نیازمند بررسی دستی هستند.' ) . '</strong> ';
		echo esc_html( 'خرید خودکار و پرداخت از کیف پول برای این سفارش‌ها انجام نشد. پس از بررسی، از کادر «ثبت سفارش در موبو» در صفحه سفارش، ارسال مجدد را انجام دهید.' ) . '</p><ul style="list-style:disc;margin-right:20px;">';
		foreach ( $rows as $order ) {
			$order_id    = absint( $order->get_id() );
			$dismiss_url = wp_nonce_url(
				add_query_arg( array( 'action' => self::DISMISS_ACTION, 'order_id' => $order_id ), admin_url( 'admin-post.php' ) ),
				self::DISMISS_ACTION . '_' . $order_id
			);
			echo '<li><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( sprintf( 'سفارش #%s', $order->get_order_number() ) ) . '</a>';
			$at = absint( $order->get_meta( '_mobo_order_price_review_at', true ) );
			if ( $at > 0 && function_exists( 'wp_date' ) ) {
				echo ' &mdash; ' . esc_html( wp_date( 'Y-m-d H:i', $at ) );
			}
			echo ' &mdash; <a href="' . esc_url( $dismiss_url ) . '">' . esc_html( 'بستن این اعلان' ) . '</a></li>';
		}
		echo '</ul></div>';
	}

	/** Dismiss one order from the notice (nonce + capability). */
	public static function handle_dismiss() {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( $order_id <= 0 || ! self::current_user_can_review() ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}
		check_admin_referer( self::DISMISS_ACTION . '_' . $order_id );
		self::unregister_order( $order_id );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Send exactly one Persian e-mail per order to the site administrator.
	 * The durable order meta marker is written before sending so a crash or a
	 * failing mail transport can never cause repeated mails for the same order.
	 *
	 * @return bool True when wp_mail accepted the message.
	 */
	public static function send_admin_email_once( $order, $mismatches, $unit ) {
		if ( ! $order instanceof WC_Order || ! function_exists( 'wp_mail' ) ) {
			return false;
		}
		if ( '' !== (string) $order->get_meta( '_mobo_order_price_review_emailed_at', true ) ) {
			return false;
		}
		$to = sanitize_email( (string) get_option( 'admin_email' ) );
		if ( '' === $to || ! is_email( $to ) ) {
			return false;
		}
		$order->update_meta_data( '_mobo_order_price_review_emailed_at', time() );
		$order->save();

		$site    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$subject = sprintf( '[%s] سفارش #%s نیازمند بررسی: اختلاف قیمت موبو', $site, $order->get_order_number() );
		$lines   = array(
			'سلام،',
			'',
			sprintf( 'خرید خودکار سفارش #%s در موبو متوقف شد، چون قیمت Source برای اقلام زیر با قیمت همگام‌شده در افزونه تفاوت دارد:', $order->get_order_number() ),
			'',
		);
		$lines   = array_merge( $lines, self::describe_mismatches( $mismatches, $unit ) );
		$lines[] = '';
		$lines[] = 'پرداخت از کیف پول موبو انجام نشد و سفارشی در موبو ثبت نشده است. سایر سفارش‌ها به‌صورت عادی ادامه پیدا می‌کنند.';
		$lines[] = 'پس از بررسی قیمت‌ها، در صفحه سفارش و در کادر «ثبت سفارش در موبو» می‌توانید ارسال مجدد را انجام دهید:';
		$lines[] = $order->get_edit_order_url();
		$lines[] = '';
		$lines[] = 'این ایمیل فقط یک بار برای این سفارش ارسال می‌شود.';

		$sent = (bool) wp_mail( $to, $subject, implode( "\n", $lines ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
		return $sent;
	}
}
