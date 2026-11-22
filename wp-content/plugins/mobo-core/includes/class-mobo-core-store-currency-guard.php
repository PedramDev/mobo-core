<?php
/**
 * C1: detect a WooCommerce store-currency change AFTER Mobo prices were written.
 *
 * Owner decision: NOTIFY ONLY. Nothing is auto-stopped or auto-repriced. When the
 * store currency changes (for example IRT -> IRR) every existing storefront price
 * is instantly wrong by a factor of 10 because WooCommerce reinterprets the same
 * number in the new unit. This guard:
 *  - records one durable change event (from, to, detectedAt);
 *  - shows a red admin notice on every admin page until a Reprice has completed
 *    for every enabled Connection after the change (or the admin dismisses it);
 *  - emails the site administrator once per event;
 *  - lets the pricing converter follow the WooCommerce currency even when the
 *    administrator never saved a destination-currency preference, so the Reprice
 *    the notice asks for actually corrects the prices instead of failing closed.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Store_Currency_Guard {
	/** Store unit the current catalog prices were last written/repriced in. */
	const OPTION_PRICED_UNIT = 'mobo_core_money_priced_store_unit';
	/** Durable change event (array) or absent. */
	const OPTION_EVENT = 'mobo_core_store_currency_change';

	/** @var bool */
	private static $checked = false;

	/**
	 * Price factor between two units: the multiplier that converts an amount
	 * written in $from into the same value expressed in $to.
	 *   IRT -> IRR : 10   (12,000,000 IRT == 120,000,000 IRR)
	 *   IRR -> IRT : 0.1
	 *
	 * @return float|null Null for an unsupported pair.
	 */
	public static function factor( $from, $to ) {
		$from = self::unit( $from );
		$to   = self::unit( $to );
		if ( '' === $from || '' === $to ) {
			return null;
		}
		if ( $from === $to ) {
			return 1.0;
		}
		if ( 'IRT' === $from && 'IRR' === $to ) {
			return 10.0;
		}
		if ( 'IRR' === $from && 'IRT' === $to ) {
			return 0.1;
		}
		return null;
	}

	/**
	 * Pure decision: the event to persist for a detected unit transition.
	 *
	 * @param string     $from     Unit prices were written in.
	 * @param string     $to       Current WooCommerce unit.
	 * @param array|null $existing Current event.
	 * @param int        $now      Timestamp.
	 * @return array|null New/updated event, or null when nothing changed.
	 */
	public static function evaluate_change( $from, $to, $existing, $now ) {
		$from = self::unit( $from );
		$to   = self::unit( $to );
		if ( '' === $to ) {
			return null;
		}
		/* A second change keeps the ORIGINAL priced unit as "from": prices were
		 * written in that unit and are still wrong by from->to. */
		$original_from = is_array( $existing ) && ! empty( $existing['from'] ) ? self::unit( $existing['from'] ) : $from;
		/* Switched back to the priced unit: prices are correct again. Checked before
		 * the from === to short-circuit, because the option hook passes the priced
		 * unit as $from, so a revert (IRT -> IRR -> IRT) arrives as IRT -> IRT. */
		if ( is_array( $existing ) && '' !== $original_from && $original_from === $to ) {
			return array( 'resolved' => true );
		}
		if ( '' === $from || $from === $to ) {
			return null;
		}
		if ( is_array( $existing ) && ! empty( $existing['to'] ) && self::unit( $existing['to'] ) === $to ) {
			return null;
		}
		if ( $original_from === $to ) {
			return array( 'resolved' => true );
		}
		$factor = self::factor( $original_from, $to );
		return array(
			'from'                => $original_from,
			'to'                  => $to,
			'factor'              => null === $factor ? 0 : $factor,
			'supported'           => null !== $factor,
			'detectedAt'          => absint( $now ),
			'emailedAt'           => 0,
			'repricedConnections' => array(),
		);
	}

	/**
	 * Persian explanation used by the notice and the email.
	 *
	 * @param array $event Event.
	 * @return string
	 */
	public static function message_fa( $event ) {
		$from = self::unit( isset( $event['from'] ) ? $event['from'] : '' );
		$to   = self::unit( isset( $event['to'] ) ? $event['to'] : '' );
		$from_label = self::unit_label( $from );
		$to_label   = self::unit_label( $to );
		$base = sprintf( 'ارز فروشگاه WooCommerce از «%1$s» به «%2$s» تغییر کرده است، اما قیمت محصولات موبو با «%1$s» نوشته شده‌اند.', $from_label, $to_label );
		$factor = self::factor( $from, $to );
		if ( 10.0 === $factor ) {
			$detail = sprintf( 'هر قیمت فعلی اکنون به‌عنوان %s خوانده می‌شود؛ یعنی همه قیمت‌ها ۱۰ برابر ارزان‌تر از مقدار واقعی نمایش و فروخته می‌شوند (مثلاً ۱۲٬۰۰۰٬۰۰۰ %s اکنون فقط ۱٬۲۰۰٬۰۰۰ %s ارزش دارد).', $to_label, $to_label, $from_label );
		} elseif ( 0.1 === $factor ) {
			$detail = sprintf( 'هر قیمت فعلی اکنون به‌عنوان %s خوانده می‌شود؛ یعنی همه قیمت‌ها ۱۰ برابر گران‌تر از مقدار واقعی نمایش داده می‌شوند.', $to_label );
		} else {
			$detail = 'این جفت ارز توسط MoboCore قابل تبدیل نیست؛ تا اصلاح ارز فروشگاه، به‌روزرسانی قیمت محصولات متوقف (fail-closed) می‌ماند.';
		}
		$action = 'MoboCore فروش را متوقف نمی‌کند و قیمت‌ها را خودکار تغییر نمی‌دهد. همین حالا از «تنظیمات MoboCore ← قیمت‌گذاری» گزینه «اعمال قیمت روی همه کاندیداها از ابتدا» (Reprice) را برای هر اتصال اجرا کنید تا قیمت‌ها با ارز جدید دوباره محاسبه شوند.';
		return $base . ' ' . $detail . ' ' . $action;
	}

	/** @return array|null Active event. */
	public static function current_event() {
		$event = get_option( self::OPTION_EVENT, null );
		return is_array( $event ) && ! empty( $event['to'] ) ? $event : null;
	}

	/**
	 * Whether pricing may follow the WooCommerce currency without a saved
	 * destination preference: only while a detected post-sync currency change is
	 * pending, i.e. exactly when the admin is asked to run Reprice.
	 */
	public static function implicit_follow_store_allowed() {
		$event = self::current_event();
		return is_array( $event ) && ! empty( $event['supported'] )
			&& self::unit( $event['to'] ) === ( class_exists( 'Mobo_Core_Money_Policy' ) ? Mobo_Core_Money_Policy::store_unit() : '' );
	}

	/** woocommerce_currency option update hook. */
	public static function on_currency_option_updated( $old_value, $new_value ) {
		$old = self::unit( $old_value );
		$new = self::unit( $new_value );
		if ( '' === $old || '' === $new || $old === $new ) {
			return;
		}
		$priced = self::unit( get_option( self::OPTION_PRICED_UNIT, '' ) );
		self::record_change( '' !== $priced ? $priced : $old, $new );
	}

	/** Lazy detection for changes made outside update_option (filters, direct DB). */
	public static function maybe_detect() {
		if ( self::$checked || ! class_exists( 'Mobo_Core_Money_Policy' ) ) {
			return;
		}
		self::$checked = true;
		$current = Mobo_Core_Money_Policy::store_unit();
		if ( '' === $current ) {
			return;
		}
		$priced = self::unit( get_option( self::OPTION_PRICED_UNIT, '' ) );
		if ( '' === $priced ) {
			update_option( self::OPTION_PRICED_UNIT, $current, false );
			return;
		}
		if ( $priced !== $current || null !== self::current_event() ) {
			/* Also re-evaluate a pending event when the units already agree: the
			 * currency may have been switched back (directly in the DB, by a filter,
			 * or before this fix), which must clear the stale alarm. */
			self::record_change( $priced, $current );
		}
		$event = self::current_event();
		if ( is_array( $event ) && empty( $event['emailedAt'] ) ) {
			self::send_email( $event );
		}
	}

	/** Persist a change event and notify once. */
	public static function record_change( $from, $to ) {
		if ( ! self::has_synced_catalog() ) {
			update_option( self::OPTION_PRICED_UNIT, self::unit( $to ), false );
			if ( null !== self::current_event() ) {
				delete_option( self::OPTION_EVENT ); // No Mobo prices exist, so none can be wrong.
			}
			return null;
		}
		$event = self::evaluate_change( $from, $to, self::current_event(), time() );
		if ( null === $event ) {
			return self::current_event();
		}
		if ( ! empty( $event['resolved'] ) ) {
			delete_option( self::OPTION_EVENT );
			update_option( self::OPTION_PRICED_UNIT, self::unit( $to ), false );
			return null;
		}
		update_option( self::OPTION_EVENT, $event, false );
		if ( class_exists( 'Mobo_Core_Logger' ) ) {
			Mobo_Core_Logger::error( sprintf( 'Mobo Core store currency changed after sync: %s -> %s. Existing prices need Reprice.', $event['from'], $event['to'] ) );
		}
		self::send_email( $event );
		return $event;
	}

	/**
	 * Called when a Reprice generation finishes for one Connection. The event is
	 * cleared once every enabled Connection completed a Reprice started after the
	 * change was detected.
	 */
	public static function note_reprice_completed( $connection_id, $started_at ) {
		$event = self::current_event();
		if ( null === $event ) {
			return;
		}
		$connection_id = absint( $connection_id );
		if ( $connection_id <= 0 || absint( $started_at ) < absint( $event['detectedAt'] ) ) {
			return;
		}
		$event['repricedConnections'][ (string) $connection_id ] = time();
		$required = array();
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) && method_exists( 'Mobo_Core_Connection_Repository', 'list_enabled' ) ) {
			foreach ( (array) Mobo_Core_Connection_Repository::list_enabled() as $row ) {
				$id = is_array( $row ) ? absint( $row['id'] ?? 0 ) : 0;
				if ( $id > 0 ) {
					$required[] = (string) $id;
				}
			}
		}
		$missing = array_diff( $required, array_keys( $event['repricedConnections'] ) );
		if ( empty( $missing ) ) {
			self::resolve( $event );
			return;
		}
		update_option( self::OPTION_EVENT, $event, false );
	}

	/**
	 * Close an event. When no destination preference was ever saved, persist
	 * "follow WooCommerce" so later Syncs keep converting instead of failing
	 * closed again the moment the notice disappears.
	 */
	private static function resolve( $event ) {
		delete_option( self::OPTION_EVENT );
		/* After Reprice or a manual correction, prices are in the CURRENT store unit.
		 * Using the event's "to" was wrong when the currency moved again after the
		 * event was recorded (dismissing raised an immediate reverse false alarm). */
		$current = class_exists( 'Mobo_Core_Money_Policy' ) ? self::unit( Mobo_Core_Money_Policy::store_unit() ) : '';
		if ( '' !== $current ) {
			update_option( self::OPTION_PRICED_UNIT, $current, false );
		} elseif ( is_array( $event ) && ! empty( $event['to'] ) ) {
			update_option( self::OPTION_PRICED_UNIT, self::unit( $event['to'] ), false );
		}
		if ( is_array( $event ) && ! empty( $event['supported'] ) && class_exists( 'Mobo_Core_Money_Target_Settings' )
			&& null === get_option( Mobo_Core_Money_Target_Settings::OPTION, null ) ) {
			update_option( Mobo_Core_Money_Target_Settings::OPTION, Mobo_Core_Money_Target_Settings::FOLLOW_WOO, false );
		}
	}

	/** admin_notices callback. */
	public static function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::maybe_detect();
		$event = self::current_event();
		if ( null === $event ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=mobo_core_dismiss_currency_change' ), 'mobo_core_dismiss_currency_change' );
		$pricing = admin_url( 'admin.php?page=mobo-core&tab=pricing' );
		echo '<div class="notice notice-error"><p><strong>' . esc_html( 'MoboCore: ارز فروشگاه بعد از همگام‌سازی تغییر کرده است — قیمت‌ها اشتباه‌اند.' ) . '</strong></p>';
		echo '<p>' . esc_html( self::message_fa( $event ) ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $pricing ) . '">' . esc_html( 'رفتن به قیمت‌گذاری و اجرای Reprice' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $dismiss ) . '">' . esc_html( 'قیمت‌ها را دستی اصلاح کرده‌ام؛ این هشدار را ببند' ) . '</a></p></div>';
	}

	/** admin-post dismiss handler. */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}
		check_admin_referer( 'mobo_core_dismiss_currency_change' );
		self::resolve( self::current_event() );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/** Compact health payload. */
	public static function health_payload() {
		$event = self::current_event();
		if ( null === $event ) {
			return array( 'pending' => false );
		}
		return array(
			'pending'    => true,
			'from'       => self::unit( $event['from'] ),
			'to'         => self::unit( $event['to'] ),
			'factor'     => (float) $event['factor'],
			'supported'  => ! empty( $event['supported'] ),
			'detectedAt' => absint( $event['detectedAt'] ),
		);
	}

	private static function send_email( $event ) {
		if ( ! function_exists( 'wp_mail' ) || ! is_array( $event ) || ! empty( $event['emailedAt'] ) ) {
			return;
		}
		$to = sanitize_email( (string) get_option( 'admin_email', '' ) );
		if ( '' === $to ) {
			return;
		}
		$subject = '[MoboCore] ارز فروشگاه تغییر کرد — قیمت‌ها نیاز به Reprice دارند';
		$body = self::message_fa( $event ) . "\n\n" . 'سایت: ' . home_url( '/' );
		$sent = wp_mail( $to, $subject, $body );
		if ( $sent ) {
			$event['emailedAt'] = time();
			update_option( self::OPTION_EVENT, $event, false );
		}
	}

	/** Whether any Mobo product exists (no catalog scan; single LIMIT 1 probe). */
	private static function has_synced_catalog() {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return true; // Unknown: prefer notifying.
		}
		$table = Mobo_Core_Storage_Schema::table_name( 'products' );
		if ( '' === $table ) {
			return true;
		}
		$found = $wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" );
		return '' !== trim( (string) $wpdb->last_error ) || null !== $found;
	}

	private static function unit( $value ) {
		if ( class_exists( 'Mobo_Core_Money_Policy' ) ) {
			return Mobo_Core_Money_Policy::normalize_unit( $value );
		}
		$value = strtoupper( trim( (string) $value ) );
		return in_array( $value, array( 'TOMAN', 'TOMANS' ), true ) ? 'IRT' : $value;
	}

	/** Persian currency label. */
	public static function unit_label( $unit ) {
		$unit = self::unit( $unit );
		$labels = array(
			'IRT'  => 'تومان',
			'IRR'  => 'ریال',
			'IRHT' => 'هزار تومان',
			'IRHR' => 'هزار ریال',
		);
		return isset( $labels[ $unit ] ) ? $labels[ $unit ] : $unit;
	}
}
