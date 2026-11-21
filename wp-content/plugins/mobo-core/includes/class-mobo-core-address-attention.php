<?php
/**
 * Escalation for paid orders whose Source address cannot be resolved (review B7).
 *
 * The submission queue keeps retrying a location_resolution_pending order with
 * bounded backoff (1 minute .. 1 hour) forever. Silent forever is the bug: after
 * ATTEMPT_THRESHOLD consecutive deferrals OR AGE_THRESHOLD seconds since the first
 * one, the order is escalated exactly once:
 * - an order note with a direct edit link;
 * - one email to the site admin (wp_mail, admin_email);
 * - a persistent admin notice listing the orders until each one is submitted or
 *   reaches another outcome (then clear() removes it).
 * Retrying continues unchanged after the escalation.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Address_Attention {

	const ATTEMPT_THRESHOLD = 5;
	const AGE_THRESHOLD     = 3600;
	const OPTION            = 'mobo_core_address_attention_orders';
	const META_NOTIFIED_AT  = '_mobo_order_address_attention_at';
	const MAX_ORDERS        = 50;

	/**
	 * Pure threshold decision.
	 *
	 * @param int  $defer_count Consecutive location deferrals (1-based).
	 * @param int  $first_deferred_at Unix time of the first deferral (0 unknown).
	 * @param int  $now Unix time.
	 * @param bool $already_notified Whether this order was already escalated.
	 * @return bool
	 */
	public static function should_notify( $defer_count, $first_deferred_at, $now, $already_notified ) {
		if ( $already_notified ) {
			return false;
		}
		if ( absint( $defer_count ) >= self::ATTEMPT_THRESHOLD ) {
			return true;
		}
		$first_deferred_at = absint( $first_deferred_at );
		return $first_deferred_at > 0 && ( absint( $now ) - $first_deferred_at ) >= self::AGE_THRESHOLD;
	}

	/**
	 * Escalate one order once. The caller owns order persistence of everything else;
	 * this method saves the notified marker itself so a failing mailer cannot cause
	 * repeated emails.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $message Last resolver message.
	 * @return bool Whether an escalation happened now.
	 */
	public static function notify( $order, $message ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return false;
		}
		$order_id = absint( $order->get_id() );
		if ( $order_id <= 0 || '' !== (string) $order->get_meta( self::META_NOTIFIED_AT, true ) ) {
			return false;
		}
		$order->update_meta_data( self::META_NOTIFIED_AT, time() );
		$order->save();

		$link = self::order_edit_url( $order );
		$order->add_order_note( sprintf(
			'نیاز به اقدام مدیر: آدرس این سفارش پرداخت‌شده پس از چند تلاش هنوز به شهر/استان منبع نگاشت نشده و سفارش در منبع ثبت نشده است. نام شهر/استان سفارش را اصلاح کنید یا مورد را در پنل مرکزی تعیین تکلیف کنید؛ تلاش خودکار ادامه دارد. علت آخر: %s',
			sanitize_text_field( (string) $message )
		) );

		$list              = self::get_list();
		$list[ $order_id ] = array(
			'at'      => time(),
			'number'  => sanitize_text_field( (string) ( method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order_id ) ),
			'message' => sanitize_text_field( (string) $message ),
		);
		if ( count( $list ) > self::MAX_ORDERS ) {
			$list = array_slice( $list, -self::MAX_ORDERS, null, true );
		}
		update_option( self::OPTION, $list, false );

		if ( function_exists( 'wp_mail' ) && function_exists( 'get_option' ) ) {
			$to = sanitize_email( (string) get_option( 'admin_email', '' ) );
			if ( '' !== $to ) {
				$subject = sprintf( '[%s] سفارش #%s نیاز به نگاشت دستی آدرس دارد', wp_specialchars_decode( (string) get_option( 'blogname', '' ), ENT_QUOTES ), $list[ $order_id ]['number'] );
				$body    = "آدرس سفارش پرداخت‌شده #" . $list[ $order_id ]['number'] . " به شهر/استان منبع نگاشت نشد و سفارش هنوز در منبع ثبت نشده است.\n\n"
					. "علت آخر: " . $list[ $order_id ]['message'] . "\n\n"
					. "لطفاً نام شهر/استان سفارش را اصلاح کنید یا مورد را در پنل مرکزی تعیین تکلیف کنید. تلاش خودکار ادامه دارد.\n\n"
					. $link . "\n";
				wp_mail( $to, $subject, $body );
			}
		}
		return true;
	}

	/**
	 * Forget an order (submitted, failed for another reason, or no longer deferred).
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function clear( $order_id ) {
		$order_id = absint( $order_id );
		$list     = self::get_list();
		if ( $order_id > 0 && isset( $list[ $order_id ] ) ) {
			unset( $list[ $order_id ] );
			update_option( self::OPTION, $list, false );
		}
	}

	/** @return array */
	public static function get_list() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	/** @return void */
	public static function register_admin_hooks() {
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
	}

	/** @return void */
	public static function render_admin_notice() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$list = self::get_list();
		if ( empty( $list ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html( 'MoboCore:' ) . '</strong> '
			. esc_html( sprintf( '%d سفارش پرداخت‌شده به‌دلیل آدرس نگاشت‌نشده هنوز در منبع ثبت نشده‌اند و نیاز به نگاشت دستی آدرس دارند:', count( $list ) ) )
			. '</p><ul>';
		$shown = 0;
		foreach ( array_reverse( $list, true ) as $order_id => $row ) {
			if ( ++$shown > 10 ) {
				break;
			}
			$url = self::order_edit_url_by_id( absint( $order_id ) );
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( '#' . ( is_array( $row ) && isset( $row['number'] ) ? $row['number'] : $order_id ) ) . '</a> '
				. esc_html( is_array( $row ) && isset( $row['message'] ) ? (string) $row['message'] : '' ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function order_edit_url( $order ) {
		if ( method_exists( $order, 'get_edit_order_url' ) ) {
			return (string) $order->get_edit_order_url();
		}
		return self::order_edit_url_by_id( absint( $order->get_id() ) );
	}

	/**
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private static function order_edit_url_by_id( $order_id ) {
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && method_exists( $order, 'get_edit_order_url' ) ) {
				return (string) $order->get_edit_order_url();
			}
		}
		return function_exists( 'admin_url' ) ? admin_url( 'post.php?post=' . absint( $order_id ) . '&action=edit' ) : '';
	}
}
