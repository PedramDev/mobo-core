<?php
/**
 * Correlation ids for support diagnostics.
 *
 * Every captured error, job diagnostic event and recent-event row carries the
 * id of the unit of work that produced it, so support can follow one webhook
 * event, sync/repair run, reprice run, migration slice or order purchase across
 * the error log, job diagnostics and the remote diagnostics bundle without
 * logging into the site.
 *
 *   $token = Mobo_Core_Correlation::push( 'order', $order_id );
 *   try { ... } finally { Mobo_Core_Correlation::pop( $token ); }
 *
 * Ids are "<kind>:<id>" (kind = req|webhook|sync|repair|reprice|recategorize|
 * migration|order|job). The request-level id is generated lazily.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Correlation {

	/** @var string[] Stack of active ids (innermost last). */
	private static $stack = array();

	/** @var string Request-level id. */
	private static $request_id = '';

	/**
	 * Build a normalized correlation id.
	 *
	 * @param string     $kind Kind.
	 * @param string|int $id   Identifier (no secrets: event ids, run ids, order ids).
	 * @return string
	 */
	public static function make( $kind, $id ) {
		$kind = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $kind ) );
		$id   = preg_replace( '/[^A-Za-z0-9_.:\-]/', '', (string) $id );
		$kind = '' === $kind ? 'job' : substr( $kind, 0, 24 );
		return $kind . ':' . substr( (string) $id, 0, 64 );
	}

	/**
	 * Enter a unit of work.
	 *
	 * @param string     $kind Kind.
	 * @param string|int $id   Identifier.
	 * @return int Token for pop().
	 */
	public static function push( $kind, $id ) {
		self::$stack[] = self::make( $kind, $id );
		return count( self::$stack );
	}

	/**
	 * Enter a unit of work with an already-built id ("kind:id").
	 *
	 * @param string $correlation_id Id.
	 * @return int Token for pop() (0 when the id is empty).
	 */
	public static function push_id( $correlation_id ) {
		$correlation_id = substr( preg_replace( '/[^A-Za-z0-9_.:\-]/', '', (string) $correlation_id ), 0, 96 );
		if ( '' === $correlation_id ) {
			return 0;
		}
		self::$stack[] = $correlation_id;
		return count( self::$stack );
	}

	/**
	 * Leave a unit of work (also unwinds anything pushed after it).
	 *
	 * @param int $token Token from push().
	 * @return void
	 */
	public static function pop( $token ) {
		$token = (int) $token;
		if ( $token <= 0 ) {
			return;
		}
		self::$stack = array_slice( self::$stack, 0, max( 0, $token - 1 ) );
	}

	/**
	 * Run a callback inside a correlation scope.
	 *
	 * @param string     $kind     Kind.
	 * @param string|int $id       Identifier.
	 * @param callable   $callback Callback.
	 * @return mixed
	 */
	public static function with( $kind, $id, $callback ) {
		$token = self::push( $kind, $id );
		try {
			return call_user_func( $callback );
		} finally {
			self::pop( $token );
		}
	}

	/** @return string Innermost id, or the request id. */
	public static function current() {
		if ( ! empty( self::$stack ) ) {
			return (string) self::$stack[ count( self::$stack ) - 1 ];
		}
		return self::request_id();
	}

	/** @return string Lazily generated per-request id. */
	public static function request_id() {
		if ( '' === self::$request_id ) {
			$random = class_exists( 'Mobo_Core_Environment' ) ? Mobo_Core_Environment::random_hex( 6 ) : substr( md5( uniqid( '', true ) ), 0, 12 );
			self::$request_id = 'req:' . $random;
		}
		return self::$request_id;
	}

	/** Test helper. @return void */
	public static function reset() {
		self::$stack      = array();
		self::$request_id = '';
	}
}
