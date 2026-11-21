<?php
/**
 * CP21 non-activating durable-intent repository (PHP 7.4).
 * No table is automatically installed. Never sends, schedules or authorizes a purchase.
 * Its DDL must be reviewed/applied ONLY on a disposable environment in a later phase.
 * A durable PREPARED record is not a provider idempotency guarantee.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Mobo_Core_Purchase_Outbox_Store {
    private $db;
    private $table;

    private static function result( $status ) {
        return array( 'status' => $status, 'purchase_allowed' => false, 'provider_calls_permitted' => 0 );
    }
    private static function table_for_prefix( $prefix ) {
        if ( ! is_string( $prefix ) || ! preg_match( '/^[A-Za-z0-9_]{1,40}$/D', $prefix ) ) { return ''; }
        return $prefix . 'mobo_purchase_outbox';
    }
    public function __construct( $wpdb ) {
        $this->db = $wpdb;
        $this->table = is_object( $wpdb ) && isset( $wpdb->prefix ) ? self::table_for_prefix( $wpdb->prefix ) : '';
    }
    /** Return DDL for offline review only; NEVER execute implicitly. */
    public static function proposed_schema_sql( $prefix ) {
        $table = self::table_for_prefix( $prefix );
        if ( '' === $table ) { return ''; }
        return "CREATE TABLE `{$table}` (\n"
            . "id bigint unsigned NOT NULL AUTO_INCREMENT,\n"
            . "woo_order_id bigint unsigned NOT NULL,\n"
            . "connection_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
            . "connection_id varchar(192) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,\n"
            . "generation bigint unsigned NOT NULL,\n"
            . "payload_fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
            . "idempotency_key varchar(70) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
            . "state varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PREPARED',\n"
            . "revision bigint unsigned NOT NULL DEFAULT 1,\n"
            . "created_at datetime NOT NULL,\n"
            . "updated_at datetime NOT NULL,\n"
            . "PRIMARY KEY (id),\n"
            . "UNIQUE KEY order_connection (woo_order_id,connection_hash),\n"
            . "UNIQUE KEY idempotency_key (idempotency_key)\n"
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
    }
    private static function valid_frozen( $f ) {
        return is_array( $f ) && isset( $f['status'], $f['order_id'], $f['connection_id'], $f['generation'],
            $f['idempotency_key'], $f['payload_fingerprint'], $f['purchase_allowed'], $f['provider_calls_permitted'] )
            && 'FROZEN_NOT_AUTHORIZED' === $f['status']
            && false === $f['purchase_allowed'] && 0 === $f['provider_calls_permitted']
            && is_int( $f['order_id'] ) && $f['order_id'] > 0
            && is_int( $f['generation'] ) && $f['generation'] > 0
            && is_string( $f['connection_id'] ) && strlen( $f['connection_id'] ) <= 192
            && '' !== trim( $f['connection_id'] ) && 1 === preg_match( '//u', $f['connection_id'] )
            && ! preg_match( '/[\x00-\x1f\x7f]/', $f['connection_id'] )
            && is_string( $f['idempotency_key'] ) && 1 === preg_match( '/^mobo1_[a-f0-9]{64}$/D', $f['idempotency_key'] )
            && is_string( $f['payload_fingerprint'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $f['payload_fingerprint'] );
    }
    private function ready( $f ) {
        return '' !== $this->table && is_object( $this->db )
            && method_exists( $this->db, 'prepare' ) && method_exists( $this->db, 'get_row' )
            && method_exists( $this->db, 'query' ) && self::valid_frozen( $f );
    }
    /**
     * wpdb reports SELECT errors through last_error and may still return an
     * array from a test double or cached adapter. A successful-looking row
     * does not override a failed database read. Never silently accept an
     * unexpected non-string last_error as proof of health.
     */
    private function db_error_reported() {
        return property_exists( $this->db, 'last_error' )
            && ( ! is_string( $this->db->last_error ) || '' !== $this->db->last_error );
    }
    /** Strict read of one order/connection fence. Caller may not treat read as authorization. */
    private function row( $f ) {
        $sql = $this->db->prepare(
            "SELECT woo_order_id,connection_hash,connection_id,generation,payload_fingerprint,idempotency_key,state,revision FROM `{$this->table}` WHERE woo_order_id=%d AND connection_hash=%s LIMIT 1",
            $f['order_id'], hash( 'sha256', $f['connection_id'] )
        );
        // wpdb::get_row(false) may reuse last_result from an unrelated SQL
        // statement instead of performing this SELECT. Treat prepare failure
        // as an unverifiable read, never as "no existing intent".
        if ( false === $sql || null === $sql || '' === $sql ) { return false; }
        return $this->db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
    }
    /**
     * DB adapters can return unsigned BIGINT fields as decimal strings. NEVER
     * compare them by casting to PHP int: out-of-range decimal strings saturate
     * at PHP_INT_MAX and can impersonate a legitimate order/generation/revision.
     * Require canonical decimal representation, and compare the exact spelling
     * of a positive PHP integer without numeric coercion.
     */
    private static function db_matches_positive_int( $stored, $expected ) {
        if ( ! is_int( $expected ) || $expected < 1 || ( ! is_string( $stored ) && ! is_int( $stored ) ) ) {
            return false;
        }
        return (string) $stored === (string) $expected;
    }
    private static function db_positive_revision( $stored ) {
        if ( ! is_string( $stored ) && ! is_int( $stored ) ) { return false; }
        $value = (string) $stored;
        if ( ! preg_match( '/^[1-9][0-9]*$/D', $value ) ) { return false; }
        $max = (string) PHP_INT_MAX;
        return strlen( $value ) < strlen( $max )
            || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) <= 0 );
    }
    private static function row_matches( $f, $r ) {
        return is_array( $r ) && isset( $r['woo_order_id'], $r['connection_hash'], $r['connection_id'], $r['generation'],
                $r['payload_fingerprint'], $r['idempotency_key'], $r['state'], $r['revision'] )
            && self::db_matches_positive_int( $r['woo_order_id'], $f['order_id'] )
            && is_string( $r['connection_hash'] ) && hash_equals( hash( 'sha256', $f['connection_id'] ), $r['connection_hash'] )
            && $r['connection_id'] === $f['connection_id']
            && self::db_matches_positive_int( $r['generation'], $f['generation'] )
            && is_string( $r['payload_fingerprint'] ) && hash_equals( $f['payload_fingerprint'], $r['payload_fingerprint'] )
            && is_string( $r['idempotency_key'] ) && hash_equals( $f['idempotency_key'], $r['idempotency_key'] )
            && self::db_positive_revision( $r['revision'] );
    }
    /**
     * CP23: never use INSERT IGNORE for a financial intent. It can turn data
     * truncation and constraint warnings into an apparently successful write.
     * Existing intents are first read and strictly verified. A concurrent
     * duplicate insert can fail; that worker remains fail-closed, and a later
     * retry can read the winner's durable record. No outcome authorizes send.
     */
    public function prepare_intent( $f ) {
        if ( ! $this->ready( $f ) ) { return self::result( 'INVALID_OR_UNAVAILABLE_STORE' ); }
        $existing = $this->row( $f );
        // A failed SELECT must never be interpreted as an absent intent. In
        // WordPress wpdb, get_row() can return null on both no row and SQL
        // failure; last_error distinguishes them after the SELECT.
        if ( $this->db_error_reported() || ( null !== $existing && ! is_array( $existing ) ) ) {
            return self::result( 'DATABASE_READ_UNVERIFIED' );
        }
        if ( is_array( $existing ) ) {
            if ( ! self::row_matches( $f, $existing ) ) { return self::result( 'SCOPE_OR_PAYLOAD_CONFLICT_REVIEW' ); }
            if ( ! self::valid_persisted_state( $existing ) ) { return self::result( 'INVALID_PERSISTED_STATE' ); }
            return self::result( 'EXISTING_DURABLE_NOT_AUTHORIZED' );
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        $sql = $this->db->prepare(
            "INSERT INTO `{$this->table}` (woo_order_id,connection_hash,connection_id,generation,payload_fingerprint,idempotency_key,state,revision,created_at,updated_at) VALUES (%d,%s,%s,%d,%s,%s,'PREPARED',1,%s,%s)",
            $f['order_id'], hash( 'sha256', $f['connection_id'] ), $f['connection_id'], $f['generation'],
            $f['payload_fingerprint'], $f['idempotency_key'], $now, $now
        );
        if ( false === $sql || null === $sql || '' === $sql ) {
            return self::result( 'DATABASE_WRITE_UNVERIFIED' );
        }
        $written = $this->db->query( $sql );
        // Includes duplicate-key races: do NOT infer that a failed write was a
        // harmless duplicate from error messages, locale or subsequent reads.
        if ( 1 !== $written || $this->db_error_reported() ) {
            return self::result( 'DATABASE_WRITE_UNVERIFIED' );
        }
        $r = $this->row( $f );
        // A successful INSERT followed by an unreadable/missing row leaves the
        // durability outcome UNKNOWN. Do not relabel that as a scope conflict;
        // callers must not retry the INSERT as though nothing was written.
        if ( ! is_array( $r ) || $this->db_error_reported() ) {
            return self::result( 'DATABASE_READ_AFTER_WRITE_UNVERIFIED' );
        }
        if ( ! self::row_matches( $f, $r ) ) { return self::result( 'SCOPE_OR_PAYLOAD_CONFLICT_REVIEW' ); }
        if ( ! self::valid_persisted_state( $r ) || $r['state'] !== 'PREPARED' || (int) $r['revision'] !== 1 ) {
            return self::result( 'INVALID_PERSISTED_STATE' );
        }
        return self::result( 'PREPARED_DURABLE_NOT_AUTHORIZED' );
    }
    private static function valid_persisted_state( $r ) {
        return is_array( $r ) && isset( $r['state'] ) && in_array( $r['state'], array(
            'PREPARED', 'UNCERTAIN', 'CANCELLED', 'DISPATCH_RECORDED',
            'PROVIDER_CONFIRMED', 'RECONCILED_FOUND', 'RECONCILED_NOT_FOUND'
        ), true );
    }
    /**
     * Limited no-send CAS: PREPARED -> UNCERTAIN/CANCELLED, or
     * DISPATCH_RECORDED -> UNCERTAIN. MySQL/MariaDB varchar state columns
     * created with a legacy case-insensitive collation would treat
     * 'prepared' as equal to 'PREPARED'. HEX() enforces exact wire-byte
     * equality even on those pre-existing tables, without first doing an
     * unsafe read-then-write comparison. CP103 applies exact-byte checks to
     * all textual identity fields too: legacy or Unicode case-insensitive
     * collations must not mutate the wrong record before post-read rejects it.
     * New proposed DDL also uses ascii_bin and utf8mb4_bin.
     * CP107: retain a sargable connection_hash=%s prefix for the composite
     * (woo_order_id,connection_hash) index, AND enforce HEX byte equality.
     * The indexed comparison alone is NEVER sufficient on legacy collations.
     * SQLite exposes hex() for isolated SQL tests; those are not InnoDB proof.
     */
    public function transition_no_send( $f, $expected_revision, $expected_state, $new_state ) {
        $allowed = array( 'PREPARED' => array( 'UNCERTAIN', 'CANCELLED' ), 'DISPATCH_RECORDED' => array( 'UNCERTAIN' ) );
        // Our PHP int representation must be able to verify revision+1 after
        // the database write. Reject the terminal value BEFORE issuing SQL;
        // otherwise the DB could advance to an unrepresentable revision and
        // only then fail the PHP postcondition. This class never sends orders.
        if ( ! $this->ready( $f ) || ! is_int( $expected_revision ) || $expected_revision < 1
            || $expected_revision >= PHP_INT_MAX
            || ! is_string( $expected_state ) || ! isset( $allowed[ $expected_state ] )
            || ! in_array( $new_state, $allowed[ $expected_state ], true ) ) { return self::result( 'INVALID_CAS_TRANSITION' ); }
        $sql = $this->db->prepare(
            "UPDATE `{$this->table}` SET state=%s,revision=revision+1,updated_at=%s WHERE woo_order_id=%d AND connection_hash=%s AND HEX(connection_hash)=HEX(%s) AND HEX(connection_id)=HEX(%s) AND generation=%d AND HEX(payload_fingerprint)=HEX(%s) AND HEX(idempotency_key)=HEX(%s) AND HEX(state)=HEX(%s) AND revision=%d",
            $new_state, gmdate( 'Y-m-d H:i:s' ), $f['order_id'], hash( 'sha256', $f['connection_id'] ),
            hash( 'sha256', $f['connection_id'] ), $f['connection_id'], $f['generation'],
            $f['payload_fingerprint'], $f['idempotency_key'], $expected_state, $expected_revision
        );
        if ( false === $sql || null === $sql || '' === $sql ) {
            return self::result( 'DATABASE_CAS_UNVERIFIED' );
        }
        $written = $this->db->query( $sql );
        if ( 1 !== $written || $this->db_error_reported() ) {
            return self::result( false === $written || $this->db_error_reported()
                ? 'DATABASE_CAS_UNVERIFIED' : 'CAS_CONFLICT_NO_CHANGE' );
        }
        $r = $this->row( $f );
        if ( $this->db_error_reported() || ! self::row_matches( $f, $r )
            || $r['state'] !== $new_state || (int) $r['revision'] !== $expected_revision + 1 ) {
            return self::result( 'CAS_POSTCONDITION_UNVERIFIED' );
        }
        return self::result( 'CAS_PERSISTED_NO_SEND' );
    }
}
