<?php
/**
 * Staged, side-effect-free decision primitive for an AUTHENTICATED, RECENT
 * Backend/Provider variant quote. This is not an API client, a checkout hook,
 * or a substitute for order idempotency. Only a trusted transport adapter may
 * supply evidence; without it the result is UNVERIFIED_PENDING.
 *
 * v1: exact owner/product/variant identity; no implicit ID rebinding.
 * php 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Mobo_Core_Purchase_Eligibility {
	const VERSION = 'stale-eligibility-v2';
	const VERIFIED = 'ELIGIBLE_VERIFIED';
	const PENDING = 'UNVERIFIED_PENDING';
	const VARIANT = 'INELIGIBLE_VARIANT';
	const QUANTITY = 'INELIGIBLE_QUANTITY';
	const PRICE = 'PRICE_CONFIRMATION_REQUIRED';

	/**
	 * Fail-closed CP3 capability negotiation. An explicitly unsupported
	 * read-only provider quote can never be upgraded to a VERIFIED decision
	 * by a product snapshot, legacy payload, feature flag or stale cache.
	 * A future, independently audited live-quote adapter must implement the
	 * supported protocol before this method is expanded.
	 */
	public static function evaluate_backend_capability( $capability ) {
		if ( ! is_array( $capability )
			|| ! isset( $capability['protocol_version'] ) || 1 !== $capability['protocol_version']
			|| ! array_key_exists( 'quote_supported', $capability )
			|| ! array_key_exists( 'provider_read_only_quote', $capability )
			|| ! array_key_exists( 'purchase_allowed', $capability ) ) {
			return self::decision( self::PENDING, 'capability_missing_or_invalid' );
		}
		if ( false === $capability['quote_supported'] && false === $capability['provider_read_only_quote']
			&& false === $capability['purchase_allowed']
			&& isset( $capability['reason_code'] )
			&& 'provider_authoritative_quote_not_implemented' === $capability['reason_code'] ) {
			return self::decision( self::PENDING, 'provider_authoritative_quote_not_implemented' );
		}
		return self::decision( self::PENDING, 'quote_capability_not_approved_in_this_build' );
	}

	private static function decision( $status, $reason ) {
		return array( 'status' => $status, 'reason' => $reason, 'contractVersion' => self::VERSION );
	}

	/** Entirely caller supplied; $evidence MUST be an authenticated quote, never cache or Woo meta. */
	public static function evaluate( $request, $evidence, $now = null ) {
		if ( ! is_array( $request ) || ! is_array( $evidence ) || ! isset( $evidence['trusted'] ) || true !== $evidence['trusted'] ) {
			return self::decision( self::PENDING, 'authoritative_evidence_missing' );
		}
		$now = null === $now ? time() : (int) $now;
		if ( ! isset( $evidence['checkedAt'], $evidence['validUntil'] ) || ! is_int( $evidence['checkedAt'] )
			|| ! is_int( $evidence['validUntil'] ) || $evidence['checkedAt'] > $now
			|| $evidence['validUntil'] <= $now || $evidence['validUntil'] <= $evidence['checkedAt'] ) {
			return self::decision( self::PENDING, 'quote_expired_or_invalid' );
		}
		foreach ( array( 'connectionId', 'productId', 'variantId' ) as $key ) {
			// Exact wire identity is UTF-8 text, never a bool/int/float converted
			// to text by PHP. A true Connection ID used to compare equal to "1".
			// Preserve raw spelling; display-text normalization is NOT rebinding.
			if ( ! isset( $request[ $key ], $evidence[ $key ] )
				|| ! self::wire_identity( $request[ $key ] )
				|| ! self::wire_identity( $evidence[ $key ] )
				|| ! hash_equals( $request[ $key ], $evidence[ $key ] ) ) {
				return self::decision( self::VARIANT, 'identity_or_connection_mismatch' );
			}
		}
		// A verified zero-stock answer is INELIGIBLE_QUANTITY, not a timeout or
		// missing-evidence condition. Null/unlimited and all coercible booleans
		// must be explicitly resolved by the Provider adapter first.
		if ( ! array_key_exists( 'available', $evidence ) || ! is_bool( $evidence['available'] )
			|| ! self::whole_quantity( $request['quantity'] ?? null ) ) {
			return self::decision( self::PENDING, 'availability_unverified' );
		}
		if ( false === $evidence['available'] ) {
			return ! empty( $evidence['tombstoneVerified'] ) && true === $evidence['tombstoneVerified']
				? self::decision( self::VARIANT, 'verified_variant_tombstone' )
				: self::decision( self::PENDING, 'availability_unverified' );
		}
		if ( ! array_key_exists( 'availableQty', $evidence ) || ! self::nonnegative_quantity( $evidence['availableQty'] ) ) {
			return self::decision( self::PENDING, 'availability_unverified' );
		}
		if ( (int) $request['quantity'] > (int) $evidence['availableQty'] ) {
			return self::decision( self::QUANTITY, 'insufficient_authoritative_stock' );
		}
		if ( ! isset( $request['attributes'], $evidence['attributes'] ) || ! is_array( $request['attributes'] )
			|| ! is_array( $evidence['attributes'] ) ) {
			return self::decision( self::PENDING, 'purchase_attributes_unverified' );
		}
		$requested = self::canonical_attributes( $request['attributes'] );
		$actual = self::canonical_attributes( $evidence['attributes'] );
		if ( null === $requested || null === $actual ) {
			return self::decision( self::PENDING, 'ambiguous_unicode_or_attribute' );
		}
		if ( $requested !== $actual ) {
			return self::decision( self::VARIANT, 'purchase_attributes_differ' );
		}
		$expected_unit = self::unit( $request['moneyUnit'] ?? '' );
		$actual_unit = self::unit( $evidence['moneyUnit'] ?? '' );
		$expected_price = self::decimal( $request['unitPrice'] ?? null );
		$actual_price = self::decimal( $evidence['unitPrice'] ?? null );
		if ( '' === $expected_unit || '' === $actual_unit || null === $expected_price || null === $actual_price ) {
			return self::decision( self::PENDING, 'price_or_currency_unverified' );
		}
		if ( $expected_unit !== $actual_unit || $expected_price !== $actual_price ) {
			return self::decision( self::PRICE, 'price_or_currency_changed' );
		}
		return self::decision( self::VERIFIED, 'exact_variant_quantity_and_money' );
	}

	/** Wire-contract identity: no implicit scalar coercion or text-derived identity. */
	private static function wire_identity( $raw ) {
		return is_string( $raw ) && '' !== trim( $raw ) && strlen( $raw ) <= 192
			&& 1 === preg_match( '//u', $raw )
			&& 0 === preg_match( '/[\x00-\x1F\x7F]/', $raw );
	}

	private static function whole_quantity( $raw ) {
		return ( is_int( $raw ) || is_string( $raw ) ) && 1 === preg_match( '/^[1-9][0-9]*$/D', (string) $raw )
			&& strlen( (string) $raw ) <= 12;
	}
	private static function nonnegative_quantity( $raw ) {
		return ( is_int( $raw ) || is_string( $raw ) ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', (string) $raw )
			&& strlen( (string) $raw ) <= 12;
	}

	/**
	 * Side-effect-free group-level quantity verification for a Woo cart/order.
	 * $lines consists of ['request' => snapshot, 'evidence' => authenticated quote]
	 * pairs. The caller MUST independently verify the authentication, freshness,
	 * owner and provenance of each quote. No Local/Woo cache becomes authority.
	 *
	 * Repeated lines for one (connection,product,variant) are aggregated before
	 * stock checks; contradictory attributes, prices or quotes block the group.
	 * $single_connection_required is true for an automatic Provider purchase.
	 * NEVER call this as a substitute for the final pre-commit revalidation.
	 */
	public static function evaluate_cart_lines( $lines, $single_connection_required = false, $now = null ) {
		if ( ! is_array( $lines ) || ! $lines || count( $lines ) > 500 ) {
			return array( 'status' => self::PENDING, 'reason' => 'cart_evidence_missing_or_oversized', 'groups' => array() );
		}
		$groups = array();
		$owners = array();
		foreach ( $lines as $index => $line ) {
			if ( ! is_array( $line ) || ! isset( $line['request'], $line['evidence'] )
				|| ! is_array( $line['request'] ) || ! is_array( $line['evidence'] ) ) {
				return array( 'status' => self::PENDING, 'reason' => 'cart_line_evidence_missing', 'groups' => array() );
			}
			$request = $line['request'];
			foreach ( array( 'connectionId', 'productId', 'variantId' ) as $identity ) {
				if ( ! isset( $request[ $identity ] ) || ! self::wire_identity( $request[ $identity ] ) ) {
					return array( 'status' => self::PENDING, 'reason' => 'invalid_group_identity', 'groups' => array() );
				}
			}
			if ( ! self::whole_quantity( $request['quantity'] ?? null ) ) {
				return array( 'status' => self::PENDING, 'reason' => 'invalid_line_quantity', 'groups' => array() );
			}
			$attributes = self::canonical_attributes( $request['attributes'] ?? null );
			$unit = self::unit( $request['moneyUnit'] ?? '' );
			$price = self::decimal( $request['unitPrice'] ?? null );
			if ( null === $attributes || '' === $unit || null === $price ) {
				return array( 'status' => self::PENDING, 'reason' => 'invalid_line_attributes_or_money', 'groups' => array() );
			}
			$owner = (string) $request['connectionId'];
			$owners[ $owner ] = true;
			$key = hash( 'sha256', json_encode( array( $owner, (string) $request['productId'], (string) $request['variantId'] ), JSON_UNESCAPED_UNICODE ) );
			// Require identical authoritative evidence across lines. Different
			// quote generations must be reconciled, not silently cherry-picked.
			$evidence_fingerprint = self::quote_evidence_fingerprint( $line['evidence'] );
			if ( null === $evidence_fingerprint ) {
				return array( 'status' => self::PENDING, 'reason' => 'quote_evidence_structure_invalid', 'groups' => array() );
			}
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'request' => $request, 'evidence' => $line['evidence'],
					'fingerprint' => $evidence_fingerprint, 'attributes' => $attributes, 'unit' => $unit,
					'price' => $price, 'quantity' => 0, 'lineIndexes' => array() );
			} elseif ( $groups[ $key ]['attributes'] !== $attributes || $groups[ $key ]['unit'] !== $unit
				|| $groups[ $key ]['price'] !== $price || $groups[ $key ]['fingerprint'] !== $evidence_fingerprint ) {
				return array( 'status' => self::PENDING, 'reason' => 'conflicting_duplicate_variant_lines', 'groups' => array() );
			}
			// Bounded integer arithmetic prevents overflow and bypass of stock.
			$sum = $groups[ $key ]['quantity'] + (int) $request['quantity'];
			if ( $sum > 999999999999 ) {
				return array( 'status' => self::PENDING, 'reason' => 'aggregate_quantity_exceeds_contract', 'groups' => array() );
			}
			$groups[ $key ]['quantity'] = $sum;
			$groups[ $key ]['lineIndexes'][] = $index;
		}
		if ( $single_connection_required && count( $owners ) !== 1 ) {
			return array( 'status' => self::PENDING, 'reason' => 'multiple_connection_order_requires_manual_review', 'groups' => array() );
		}
		$results = array();
		$overall = self::VERIFIED;
		foreach ( $groups as $key => $group ) {
			$request = $group['request'];
			$request['quantity'] = $group['quantity'];
			$decision = self::evaluate( $request, $group['evidence'], $now );
			$results[] = array( 'groupKey' => $key, 'lineIndexes' => $group['lineIndexes'],
				'quantity' => $group['quantity'], 'decision' => $decision );
			if ( self::VERIFIED !== $decision['status'] ) { $overall = self::PENDING; }
		}
		return array( 'status' => $overall, 'reason' => self::VERIFIED === $overall ? 'all_groups_verified' : 'one_or_more_groups_not_verified',
			'groups' => $results, 'contractVersion' => self::VERSION );
	}
	/** Flatten group decisions for UX while preserving the conservative
	 * cart-wide PENDING status of the existing evaluate_cart_lines contract.
	 * This method never turns a partial cart into a VERIFIED order. */
	public static function rejected_groups( $aggregate ) {
		$rejected = array();
		if ( ! is_array( $aggregate ) || ! isset( $aggregate['groups'] ) || ! is_array( $aggregate['groups'] ) ) {
			return $rejected;
		}
		foreach ( $aggregate['groups'] as $group ) {
			if ( ! is_array( $group ) || ! isset( $group['decision']['status'] )
				|| self::VERIFIED === $group['decision']['status'] ) { continue; }
			$rejected[] = array( 'lineIndexes' => isset( $group['lineIndexes'] ) ? $group['lineIndexes'] : array(),
				'status' => $group['decision']['status'],
				'reason' => isset( $group['decision']['reason'] ) ? $group['decision']['reason'] : 'unverified' );
		}
		return $rejected;
	}

	/**
	 * Quote evidence is a JSON object, not an ordered PHP insertion-history.
	 * Reordering JSON object fields (including attributes) must not create a
	 * conflict between two otherwise identical authenticated quote snapshots.
	 * Keep every field and every value, including revision, timestamps,
	 * availableQty, owner and price: differing generations still fail closed.
	 * This is only a consistency fingerprint, NEVER quote authentication.
	 */
	private static function quote_evidence_fingerprint( $evidence ) {
		if ( ! is_array( $evidence ) ) { return null; }
		$copy = $evidence;
		$visited = 0;
		if ( ! self::canonicalize_quote_evidence( $copy, 0, $visited ) ) { return null; }
		return hash( 'sha256', serialize( $copy ) );
	}

	/** Restrict fingerprint inputs to bounded JSON-compatible data. */
	private static function canonicalize_quote_evidence( &$node, $depth, &$visited ) {
		$visited++;
		if ( $visited > 1024 || $depth > 12 ) { return false; }
		if ( is_array( $node ) ) {
			if ( count( $node ) > 256 ) { return false; }
			ksort( $node, SORT_STRING );
			foreach ( $node as &$value ) {
				if ( ! self::canonicalize_quote_evidence( $value, $depth + 1, $visited ) ) { return false; }
			}
			unset( $value );
			return true;
		}
		return null === $node || is_scalar( $node );
	}

	private static function unit( $value ) {
		if ( ! is_string( $value ) ) { return ''; }
		// Preserve exact protocol bytes; casing/whitespace are not implicit FX normalization.
		// Money conversions belong to the versioned connection money policy;
		// do not reinterpret free-form TOMAN aliases at purchase authorization.
		// Protocol v1 is explicitly IRT/IRR (aligned with the authenticated Quote
		// envelope). Unknown currencies cannot be marked ELIGIBLE_VERIFIED.
		return in_array( $value, array( 'IRT', 'IRR' ), true ) ? $value : '';
	}
	private static function decimal( $value ) {
		if ( ! is_string( $value ) && ! is_int( $value ) ) { return null; }
		$value = (string) $value;
		// Exactly the Backend v1 money envelope: <=40 ASCII bytes, no leading
		// zero ambiguity, and at most eight fractional digits. Do not round.
		if ( strlen( $value ) > 40 || ! preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/D', $value ) ) { return null; }
		$parts = explode( '.', $value, 2 );
		$whole = ltrim( $parts[0], '0' );
		if ( '' === $whole ) { $whole = '0'; }
		$fraction = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';
		return $whole . ( '' !== $fraction ? '.' . $fraction : '' );
	}
	private static function canonical_attributes( $attributes ) {
		if ( ! is_array( $attributes ) || count( $attributes ) < 1 || count( $attributes ) > 32 ) { return null; }
		$out = array();
		foreach ( $attributes as $name => $value ) {
			if ( ! is_string( $name ) || ! is_string( $value ) || '' === trim( $name ) || '' === trim( $value )
				|| strlen( $name ) > 192 || strlen( $value ) > 512
				|| preg_match( '/[\x00-\x1F\x7F]/', $name ) || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) { return null; }
			$name = self::normalize_unicode( $name );
			$value = self::normalize_unicode( $value );
			if ( null === $name || null === $value || '' === $name || '' === $value || array_key_exists( $name, $out ) ) { return null; }
			$out[ $name ] = $value;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}
	private static function normalize_unicode( $text ) {
		// Search normalization only: identity itself is NEVER inferred from text.
		if ( ! preg_match( '//u', $text ) ) { return null; }
		if ( class_exists( 'Normalizer' ) ) {
			$text = Normalizer::normalize( $text, Normalizer::FORM_C );
			if ( false === $text ) { return null; }
		} elseif ( preg_match( '/[\x{0300}-\x{036F}]/u', $text ) ) {
			return null; // No silent NFC/NFD guess when intl is unavailable.
		}
		$text = strtr( $text, array( 'ي' => 'ی', 'ك' => 'ک', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3',
			'٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) );
		return trim( $text ); // ZWNJ remains significant; never collapse it silently.
	}
}
