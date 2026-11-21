<?php
/**
 * Dynamic pricing rule coverage: validation, admin warnings and the runtime
 * fallback used when no active range matches a Source price.
 *
 * Rows use the stored shape:
 *   { "is_active": "true"|"false", "low": 0, "high": 1000000 (0 = no cap),
 *     "benefit_type": "static"|"percentage", "benefit": 50000 }
 *
 * Range matching uses the integer part of the price (rows are contiguous
 * integers: next.low = previous.high + 1), identical to the historical calculator.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Dynamic_Price_Rules {

	/** @return array<int,array> Clean list of array rows. */
	public static function rows( $rows ) {
		if ( is_string( $rows ) ) {
			$rows = json_decode( $rows, true );
		}
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( array_values( $rows ) as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public static function is_active_row( $row ) {
		return is_array( $row ) && isset( $row['is_active'] ) && 'true' === strtolower( trim( (string) $row['is_active'] ) );
	}

	private static function bound( $row, $key ) {
		return isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ? max( 0, (int) floor( (float) $row[ $key ] ) ) : 0;
	}

	/**
	 * Coverage problems for a rule set. Used both as save-time validation errors
	 * (when dynamic pricing is selected) and as runtime admin warnings.
	 *
	 * @param mixed $rows Rows or JSON.
	 * @return array<int,string> Persian messages; empty when coverage is complete.
	 */
	public static function coverage_problems( $rows ) {
		$rows = self::rows( $rows );
		$problems = array();
		if ( empty( $rows ) ) {
			$problems[] = 'هیچ قانون داینامیکی تعریف نشده است؛ حداقل یک ردیف با سود مشخص اضافه کنید.';
			return $problems;
		}
		$has_active = false;
		$count = count( $rows );
		foreach ( $rows as $index => $row ) {
			$number = $index + 1;
			$low = self::bound( $row, 'low' );
			$high = self::bound( $row, 'high' );
			if ( self::is_active_row( $row ) ) {
				$has_active = true;
			} else {
				$range = $high > 0
					? sprintf( '%s تا %s', number_format( $low ), number_format( $high ) )
					: sprintf( 'از %s به بالا', number_format( $low ) );
				$problems[] = sprintf( 'ردیف %d غیرفعال است ولی بازه %s را اشغال می‌کند؛ کالاهای این بازه هیچ قانون فعالی ندارند. این ردیف را حذف یا فعال کنید.', $number, $range );
			}
			if ( $index === $count - 1 && $high > 0 ) {
				$problems[] = sprintf( 'آخرین ردیف سقف %s دارد؛ کالاهای گران‌تر از این مبلغ هیچ قانونی ندارند. سقف آخرین ردیف را خالی یا ۰ بگذارید (یعنی بدون سقف).', number_format( $high ) );
			}
		}
		if ( ! $has_active ) {
			$problems[] = 'هیچ ردیف فعالی وجود ندارد؛ حداقل یک ردیف را فعال کنید.';
		}
		return $problems;
	}

	/**
	 * First active row matching the integer price, or null.
	 *
	 * @param mixed $rows Rows.
	 * @param int   $price Integer Source/store price used for range selection.
	 * @return array|null
	 */
	public static function match( $rows, $price ) {
		$price = (int) $price;
		foreach ( self::rows( $rows ) as $row ) {
			if ( ! self::is_active_row( $row ) ) {
				continue;
			}
			$low = isset( $row['low'] ) ? intval( $row['low'] ) : 0;
			$high = isset( $row['high'] ) ? intval( $row['high'] ) : 0;
			if ( $price < $low || ( $high > 0 && $price > $high ) ) {
				continue;
			}
			return $row;
		}
		return null;
	}

	/**
	 * Base markup when no active rule matches: the nearest active rule. A price
	 * inside a disabled/missing range uses the closest active range below it (the
	 * one that ends nearest to the price); a price below every active range uses
	 * the first active range above it. Returns null only when no active row exists.
	 *
	 * @param mixed $rows Rows.
	 * @param int   $price Integer price.
	 * @return array|null
	 */
	public static function fallback( $rows, $price ) {
		$price = (int) $price;
		$below = null;
		$below_distance = PHP_INT_MAX;
		$above = null;
		$above_distance = PHP_INT_MAX;
		foreach ( self::rows( $rows ) as $row ) {
			if ( ! self::is_active_row( $row ) ) {
				continue;
			}
			$low = isset( $row['low'] ) ? intval( $row['low'] ) : 0;
			$high = isset( $row['high'] ) ? intval( $row['high'] ) : 0;
			if ( $high > 0 && $high < $price ) {
				$distance = $price - $high;
				if ( $distance < $below_distance ) {
					$below = $row;
					$below_distance = $distance;
				}
			} elseif ( $low > $price ) {
				$distance = $low - $price;
				if ( $distance < $above_distance ) {
					$above = $row;
					$above_distance = $distance;
				}
			}
		}
		return null !== $below ? $below : $above;
	}
}
