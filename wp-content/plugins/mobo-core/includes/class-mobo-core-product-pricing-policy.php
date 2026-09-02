<?php
/**
 * Durable parent-level pricing override policy for Mobo products.
 *
 * A custom percentage is stored only on the parent product and is resolved by
 * both simple products and Mobo-owned variations. Pending admin requests use a
 * generation token so a retry can never clear a newer request.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Product_Pricing_Policy {
	const META_ENABLED = '_mobo_pricing_override_enabled';
	const META_TYPE = '_mobo_pricing_override_type';
	const META_VALUE = '_mobo_pricing_override_value';
	const META_UPDATED_AT = '_mobo_pricing_override_updated_at';
	/* Canonical pending request. One JSON value avoids crash-visible partial generations. */
	const META_PENDING = '_mobo_pricing_override_pending';
	const META_PENDING_ERROR = '_mobo_pricing_override_pending_error';
	const ACTION_RETRY = 'mobo_core_apply_product_pricing_override';
	const TYPE_PERCENTAGE = 'percentage';

	public static function active_meta_keys() {
		return array( self::META_ENABLED, self::META_TYPE, self::META_VALUE, self::META_UPDATED_AT );
	}

	public static function pending_meta_keys() {
		return array( self::META_PENDING, self::META_PENDING_ERROR );
	}

	/** Resolve the parent-level custom percentage for a product/variation. */
	public static function resolve_for_object( $object_id, $parent_id = 0, $context = 'product' ) {
		$object_id = absint( $object_id );
		$parent_id = absint( $parent_id );
		$context = sanitize_key( (string) $context );

		if ( 'variation' === $context ) {
			if ( $parent_id <= 0 && $object_id > 0 ) {
				$parent_id = absint( wp_get_post_parent_id( $object_id ) );
			}
			if ( $parent_id <= 0 ) {
				return self::disabled_result();
			}
			/* Existing manual/non-Mobo children never inherit Mobo pricing policy. A
			 * conflicting Mobo identity is a fail-closed pricing condition, not a
			 * reason to silently fall back to global pricing. */
			if ( $object_id > 0 && class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ) {
				$identity = Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $object_id );
				if ( is_wp_error( $identity ) ) {
					return array( 'enabled' => false, 'type' => '', 'value' => '', 'parentId' => $parent_id, 'error' => $identity->get_error_message() );
				}
				if ( empty( $identity['owned'] ) ) { return self::disabled_result( $parent_id ); }
			} elseif ( $object_id > 0 && class_exists( 'Mobo_Core_Product_Identity_Policy' ) && ! Mobo_Core_Product_Identity_Policy::is_mobo_object_id( $object_id ) ) {
				return self::disabled_result( $parent_id );
			}
		} else {
			$parent_id = $object_id > 0 ? $object_id : $parent_id;
		}

		if ( $parent_id <= 0 ) {
			return self::disabled_result();
		}
		if ( class_exists( 'Mobo_Core_Product_Identity_Policy' ) && ! Mobo_Core_Product_Identity_Policy::is_mobo_object_id( $parent_id ) ) {
			return self::disabled_result( $parent_id );
		}

		$enabled_raw = trim( (string) get_post_meta( $parent_id, self::META_ENABLED, true ) );
		if ( '' === $enabled_raw || '0' === $enabled_raw ) { return self::disabled_result( $parent_id ); }
		if ( '1' !== $enabled_raw ) {
			return array( 'enabled' => false, 'type' => '', 'value' => '', 'parentId' => $parent_id, 'error' => 'Product pricing override enabled flag is malformed.' );
		}
		$type = sanitize_key( (string) get_post_meta( $parent_id, self::META_TYPE, true ) );
		$value = self::normalize_percentage( get_post_meta( $parent_id, self::META_VALUE, true ) );
		if ( self::TYPE_PERCENTAGE !== $type || null === $value ) {
			return array( 'enabled' => false, 'type' => $type, 'value' => '', 'parentId' => $parent_id, 'error' => 'Product pricing override metadata is malformed.' );
		}

		return array( 'enabled' => true, 'type' => self::TYPE_PERCENTAGE, 'value' => $value, 'parentId' => $parent_id );
	}

	public static function effective_policy_type( $object_id, $parent_id = 0, $context = 'product' ) {
		$override = self::resolve_for_object( $object_id, $parent_id, $context );
		if ( ! empty( $override['error'] ) ) { return 'product-override-invalid'; }
		if ( ! empty( $override['enabled'] ) ) {
			return 'product-percentage-override';
		}
		$object_id = absint( $object_id );
		if ( $object_id > 0 && absint( get_post_meta( $object_id, 'mobo_additional_price', true ) ) > 0 ) {
			return 'object-additional-price';
		}
		return (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' );
	}

	public static function get_parent_state( $product_id ) {
		$product_id = absint( $product_id );
		$resolved = self::resolve_for_object( $product_id, $product_id, 'product' );
		$pending_raw = trim( (string) get_post_meta( $product_id, self::META_PENDING, true ) );
		$pending_error = self::read_pending_request( $product_id );
		$error = isset( $resolved['error'] ) ? sanitize_text_field( (string) $resolved['error'] ) : '';
		if ( is_wp_error( $pending_error ) ) {
			$error = '' === $error ? $pending_error->get_error_message() : $error . ' ' . $pending_error->get_error_message();
		}
		return array(
			'mode' => ! empty( $resolved['enabled'] ) ? 'custom' : 'global',
			'value' => ! empty( $resolved['enabled'] ) ? (string) $resolved['value'] : '',
			'pending' => '' !== $pending_raw,
			'error' => sanitize_text_field( $error ),
		);
	}

	/** Persist a desired request before trying to acquire the shared product lock. */
	public static function request( $product_id, $mode, $value = '' ) {
		$product_id = absint( $product_id );
		$mode = sanitize_key( (string) $mode );
		if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
			return new WP_Error( 'mobo_core_pricing_override_invalid_product', 'Product is invalid.' );
		}
		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $product_id ) ) {
			return new WP_Error( 'mobo_core_pricing_override_excluded', 'Excluded Mobo products cannot be repriced.' );
		}
		if ( ! class_exists( 'Mobo_Core_Product_Identity_Policy' ) || ! Mobo_Core_Product_Identity_Policy::is_mobo_object_id( $product_id ) ) {
			return new WP_Error( 'mobo_core_pricing_override_not_mobo', 'Only Mobo-owned products can use this pricing override.' );
		}
		if ( ! in_array( $mode, array( 'global', 'custom' ), true ) ) {
			return new WP_Error( 'mobo_core_pricing_override_mode_invalid', 'Pricing override mode is invalid.' );
		}
		$normalized = '';
		if ( 'custom' === $mode ) {
			$normalized = self::normalize_percentage( $value );
			if ( null === $normalized ) {
				return new WP_Error( 'mobo_core_pricing_override_value_invalid', 'Custom profit percentage must be a number between 0 and 10000.' );
			}
		}

		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mobo-pricing-', true );
		$payload = wp_json_encode( array(
			'token' => $token,
			'mode' => $mode,
			'value' => $normalized,
			'requestedAt' => gmdate( 'c' ),
		) );
		if ( ! is_string( $payload ) || '' === $payload ) {
			return new WP_Error( 'mobo_core_pricing_override_request_encode_failed', 'Pricing override request could not be encoded durably.' );
		}
		/* Diagnostics are non-authoritative. The canonical desired generation is one
		 * scalar meta update, so a crash cannot expose a half-written token/mode/value. */
		delete_post_meta( $product_id, self::META_PENDING_ERROR );
		if ( ! self::persist_meta_verified( $product_id, self::META_PENDING, $payload ) ) {
			return new WP_Error( 'mobo_core_pricing_override_request_persist_failed', 'Pricing override request could not be persisted durably.' );
		}
		return self::apply_pending_request( $product_id, $token );
	}

	/** Apply the currently pending generation under the shared product lock. */
	public static function apply_pending_request( $product_id, $expected_token = '' ) {
		$product_id = absint( $product_id );
		$pending = self::read_pending_request( $product_id );
		if ( null === $pending ) {
			return array( 'success' => true, 'status' => 'nothing-pending' );
		}
		if ( is_wp_error( $pending ) ) {
			self::set_pending_error( $product_id, $pending->get_error_message() );
			return array( 'success' => false, 'status' => 'pending-invalid', 'queued' => false, 'message' => $pending->get_error_message() );
		}
		$token = $pending['token'];
		if ( '' !== $expected_token && ! hash_equals( $token, sanitize_text_field( (string) $expected_token ) ) ) {
			self::schedule_retry( $product_id );
			return array( 'success' => true, 'status' => 'newer-request-pending', 'queued' => true );
		}

		$product_guid = sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) );
		if ( '' === $product_guid || ! class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			self::set_pending_error( $product_id, 'Shared product lock identity is unavailable.' );
			self::schedule_retry( $product_id );
			return array( 'success' => true, 'status' => 'queued', 'queued' => true );
		}
		$lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 0, 180 );
		if ( false === $lock ) {
			self::set_pending_error( $product_id, 'Product lock is busy.' );
			self::schedule_retry( $product_id );
			return array( 'success' => true, 'status' => 'queued', 'queued' => true );
		}

		try {
			$current = self::read_pending_request( $product_id );
			if ( is_wp_error( $current ) ) {
				self::set_pending_error( $product_id, $current->get_error_message() );
				return array( 'success' => false, 'status' => 'pending-invalid', 'queued' => false, 'message' => $current->get_error_message() );
			}
			if ( null === $current ) {
				return array( 'success' => true, 'status' => 'nothing-pending' );
			}
			if ( ! hash_equals( $token, $current['token'] ) ) {
				self::schedule_retry( $product_id );
				return array( 'success' => true, 'status' => 'newer-request-pending', 'queued' => true );
			}
			$mode = $current['mode'];
			$value = $current['value'];
			$active_snapshot = self::snapshot_meta( $product_id, self::active_meta_keys() );

			$active_result = self::write_active_state( $product_id, $mode, $value );
			if ( is_wp_error( $active_result ) ) {
				$active_restored = self::restore_meta_snapshot( $product_id, $active_snapshot );
				$restore_suffix = $active_restored ? '' : ' Active pricing policy rollback could not be verified.';
				self::set_pending_error( $product_id, $active_result->get_error_message() . $restore_suffix );
				self::schedule_retry( $product_id );
				return array( 'success' => false, 'status' => 'persist-failed', 'queued' => true, 'message' => $active_result->get_error_message() . $restore_suffix );
			}

			$queue = new Mobo_Core_Reprice_Queue();
			$repriced = $queue->reprice_product_family_locked( $product_id );
			if ( is_wp_error( $repriced ) || empty( $repriced['success'] ) ) {
				$active_restored = self::restore_meta_snapshot( $product_id, $active_snapshot );
				$message = is_wp_error( $repriced ) ? $repriced->get_error_message() : ( isset( $repriced['message'] ) ? (string) $repriced['message'] : 'Product repricing failed.' );
				if ( ! $active_restored ) { $message .= ' Active pricing policy rollback could not be verified.'; }
				self::set_pending_error( $product_id, $message );
				self::schedule_retry( $product_id );
				return array( 'success' => false, 'status' => 'reprice-failed', 'queued' => true, 'message' => $message );
			}

			/* Clear only the exact generation we successfully applied. */
			$after = self::read_pending_request( $product_id );
			if ( is_wp_error( $after ) ) {
				self::set_pending_error( $product_id, $after->get_error_message() );
				return array( 'success' => false, 'status' => 'pending-invalid', 'queued' => false, 'message' => $after->get_error_message() );
			}
			if ( is_array( $after ) && hash_equals( $token, $after['token'] ) ) {
				delete_post_meta( $product_id, self::META_PENDING );
				if ( '' !== trim( (string) get_post_meta( $product_id, self::META_PENDING, true ) ) ) {
					self::set_pending_error( $product_id, 'Pending request cleanup could not be verified.' );
					self::schedule_retry( $product_id );
					return array( 'success' => false, 'status' => 'cleanup-failed', 'queued' => true );
				}
				delete_post_meta( $product_id, self::META_PENDING_ERROR );
			} elseif ( is_array( $after ) ) {
				self::schedule_retry( $product_id );
			}

			return array( 'success' => true, 'status' => 'applied', 'queued' => false, 'processed' => isset( $repriced['processed'] ) ? absint( $repriced['processed'] ) : 0, 'updated' => isset( $repriced['updated'] ) ? absint( $repriced['updated'] ) : 0 );
		} finally {
			Mobo_Core_Product_Concurrency::release_product_lock( $lock );
		}
	}

	public static function handle_retry_action( $product_id ) {
		self::apply_pending_request( absint( $product_id ) );
	}

	public static function schedule_retry( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) { return false; }
		$timestamp = time() + 60;
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_has_scheduled_action( self::ACTION_RETRY, array( $product_id ), 'mobo-core' ) ) {
				as_schedule_single_action( $timestamp, self::ACTION_RETRY, array( $product_id ), 'mobo-core', true );
			}
			return true;
		}
		if ( ! wp_next_scheduled( self::ACTION_RETRY, array( $product_id ) ) ) {
			return false !== wp_schedule_single_event( $timestamp, self::ACTION_RETRY, array( $product_id ), true );
		}
		return true;
	}

	/** Read and strictly validate the one-record pending generation. */
	private static function read_pending_request( $product_id ) {
		$raw = trim( (string) get_post_meta( absint( $product_id ), self::META_PENDING, true ) );
		if ( '' === $raw ) { return null; }
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'mobo_core_pricing_override_pending_malformed', 'Pending product pricing request is malformed.' );
		}
		$token = isset( $decoded['token'] ) && is_scalar( $decoded['token'] ) ? sanitize_text_field( (string) $decoded['token'] ) : '';
		$mode = isset( $decoded['mode'] ) && is_scalar( $decoded['mode'] ) ? sanitize_key( (string) $decoded['mode'] ) : '';
		$value = isset( $decoded['value'] ) && is_scalar( $decoded['value'] ) ? (string) $decoded['value'] : '';
		if ( '' === $token || ! in_array( $mode, array( 'global', 'custom' ), true ) ) {
			return new WP_Error( 'mobo_core_pricing_override_pending_malformed', 'Pending product pricing request identity or mode is malformed.' );
		}
		if ( 'custom' === $mode ) {
			$value = self::normalize_percentage( $value );
			if ( null === $value ) {
				return new WP_Error( 'mobo_core_pricing_override_pending_malformed', 'Pending product pricing percentage is malformed.' );
			}
		} else {
			$value = '';
		}
		return array( 'token' => $token, 'mode' => $mode, 'value' => $value );
	}

	private static function write_active_state( $product_id, $mode, $value ) {
		if ( 'global' === $mode ) {
			foreach ( self::active_meta_keys() as $key ) { delete_post_meta( $product_id, $key ); }
			foreach ( array( self::META_ENABLED, self::META_TYPE, self::META_VALUE ) as $key ) {
				if ( '' !== (string) get_post_meta( $product_id, $key, true ) ) {
					return new WP_Error( 'mobo_core_pricing_override_clear_failed', 'Product pricing override could not be cleared durably.' );
				}
			}
			return true;
		}
		$value = self::normalize_percentage( $value );
		if ( 'custom' !== $mode || null === $value ) {
			return new WP_Error( 'mobo_core_pricing_override_pending_invalid', 'Pending product pricing override is invalid.' );
		}
		$writes = array( self::META_ENABLED => '1', self::META_TYPE => self::TYPE_PERCENTAGE, self::META_VALUE => $value, self::META_UPDATED_AT => gmdate( 'c' ) );
		foreach ( $writes as $key => $stored ) {
			if ( ! self::persist_meta_verified( $product_id, $key, $stored ) ) {
				return new WP_Error( 'mobo_core_pricing_override_active_persist_failed', 'Product pricing override could not be persisted durably.' );
			}
		}
		return true;
	}

	private static function disabled_result( $parent_id = 0 ) { return array( 'enabled' => false, 'type' => '', 'value' => '', 'parentId' => absint( $parent_id ) ); }
	private static function normalize_percentage( $value ) {
		if ( is_bool( $value ) || is_array( $value ) || is_object( $value ) || null === $value ) { return null; }
		$raw = trim( (string) $value );
		if ( '' === $raw || ! preg_match( '/^(?:[0-9]+(?:\.[0-9]{0,4})?|\.[0-9]{1,4})$/D', $raw ) ) { return null; }
		$number = (float) $raw;
		if ( ! is_finite( $number ) || $number < 0 || $number > 10000 ) { return null; }
		return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $number, 4 ) : rtrim( rtrim( number_format( $number, 4, '.', '' ), '0' ), '.' );
	}
	private static function persist_meta_verified( $post_id, $key, $value ) {
		$current = get_post_meta( $post_id, $key, true );
		if ( (string) $current === (string) $value ) { return true; }
		$written = update_post_meta( $post_id, $key, $value );
		$stored = get_post_meta( $post_id, $key, true );
		return ( false !== $written || (string) $stored === (string) $value ) && (string) $stored === (string) $value;
	}
	private static function snapshot_meta( $post_id, $keys ) {
		$out = array(); foreach ( $keys as $key ) { $out[ $key ] = get_post_meta( $post_id, $key, false ); } return $out;
	}
	private static function restore_meta_snapshot( $post_id, $snapshot ) {
		foreach ( $snapshot as $key => $values ) {
			delete_post_meta( $post_id, $key );
			foreach ( (array) $values as $value ) { add_post_meta( $post_id, $key, $value, false ); }
		}
		foreach ( $snapshot as $key => $values ) {
			if ( maybe_serialize( array_values( (array) $values ) ) !== maybe_serialize( array_values( (array) get_post_meta( $post_id, $key, false ) ) ) ) { return false; }
		}
		return true;
	}
	private static function set_pending_error( $product_id, $message ) {
		self::persist_meta_verified( $product_id, self::META_PENDING_ERROR, sanitize_text_field( (string) $message ) );
	}
}
