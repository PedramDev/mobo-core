<?php
/** Shared webhook event-name policy. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Event_Type_Policy {
	/** @var array */
	private static $numeric_map = array(
		0  => 'ProductUpdated',
		1  => 'UpdateVariant',
		2  => 'ProductUpdated',
		4  => 'UpdateVariant',
		20 => 'ShippingMethodsChanged',
		21 => 'WebhookDeliveryStatusChanged',
	);

	public static function map_numeric( $type ) {
		$type = absint( $type );
		return isset( self::$numeric_map[ $type ] ) ? self::$numeric_map[ $type ] : '';
	}

	public static function detect( $payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}
		$event = self::value( $payload, 'event', '' );
		if ( ! is_scalar( $event ) || '' === trim( (string) $event ) ) {
			$event = self::value( $payload, 'type', '' );
		}
		if ( is_numeric( $event ) ) {
			$event = self::map_numeric( $event );
		}
		return sanitize_text_field( is_scalar( $event ) ? (string) $event : '' );
	}

	private static function value( $array, $key, $default = null ) {
		if ( array_key_exists( $key, $array ) ) { return $array[ $key ]; }
		$pascal = ucfirst( (string) $key );
		return array_key_exists( $pascal, $array ) ? $array[ $pascal ] : $default;
	}
}
