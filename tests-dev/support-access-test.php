<?php
/**
 * Standalone test: Support access (token lifecycle, rate limit, Source status
 * classification, password sealing, readiness computation, health shapes).
 *
 * Run: php tests-dev/support-access-test.php
 *
 * The sealing test decrypts with the staging PRIVATE key read at runtime from
 * C:\MOBO-WORK\staging\secrets\support-access-private.pem (or the
 * MOBO_SUPPORT_PRIVATE_KEY_FILE environment variable). Its contents are never
 * stored in the repository.
 */

require __DIR__ . '/stubs.php';

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['mobo_test_transients'] = array();
$GLOBALS['mobo_test_users']      = array();
$GLOBALS['mobo_test_user_meta']  = array();

function delete_option( $name ) { unset( $GLOBALS['mobo_test_options'][ $name ] ); return true; }
function get_transient( $key ) { return isset( $GLOBALS['mobo_test_transients'][ $key ] ) ? $GLOBALS['mobo_test_transients'][ $key ] : false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['mobo_test_transients'][ $key ] = $value; return true; }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function home_url( $path = '' ) { return 'http://shop.test' . $path; }
function rest_url( $path = '' ) { return 'http://shop.test/wp-json/' . ltrim( $path, '/' ); }
function admin_url( $path = '' ) { return 'http://shop.test/wp-admin/' . $path; }
function add_query_arg( $key, $value = null, $url = null ) {
	if ( is_array( $key ) ) {
		$url  = $value;
		$args = $key;
	} else {
		$args = array( $key => $value );
	}
	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}
function get_userdata( $id ) { return isset( $GLOBALS['mobo_test_users'][ $id ] ) ? $GLOBALS['mobo_test_users'][ $id ] : false; }
function get_user_meta( $id, $key, $single = false ) { return isset( $GLOBALS['mobo_test_user_meta'][ $id ][ $key ] ) ? $GLOBALS['mobo_test_user_meta'][ $id ][ $key ] : ''; }
function user_can( $user, $cap ) { return is_object( $user ) && ! empty( $user->is_admin ); }

/**
 * Minimal $wpdb fake over an in-memory options table. prepare() returns a
 * structured query so get_var()/query() can apply the exact CAS semantics.
 */
class Mobo_Test_Wpdb {
	public $options = 'wp_options';
	public $rows = array();
	/** @var callable|null Runs right before a CAS UPDATE (simulates a racing request). */
	public $before_update = null;
	public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
	public function insert( $table, $data, $format = null ) {
		if ( isset( $this->rows[ $data['option_name'] ] ) ) { return false; }
		$this->rows[ $data['option_name'] ] = $data['option_value'];
		return 1;
	}
	public function get_var( $query ) {
		$name = $query['args'][0];
		return isset( $this->rows[ $name ] ) ? $this->rows[ $name ] : null;
	}
	public function query( $query ) {
		if ( is_callable( $this->before_update ) ) {
			$hook = $this->before_update;
			$this->before_update = null;
			$hook( $this );
		}
		list( $new, $name, $expected ) = $query['args'];
		if ( isset( $this->rows[ $name ] ) && $this->rows[ $name ] === $expected ) {
			$this->rows[ $name ] = $new;
			return 1;
		}
		return 0;
	}
	public function delete( $table, $where, $format = null ) {
		unset( $this->rows[ $where['option_name'] ] );
		return 1;
	}
}

$GLOBALS['wpdb'] = new Mobo_Test_Wpdb();

require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-support-access.php';

$S   = 'Mobo_Core_Support_Access';
$now = 1800000000;

/* ------------------------------------------------------------------ */
echo "Status resolution\n";
mobo_assert_same( 'not_granted', $S::resolve_status( array(), $now ), 'no state -> not_granted' );
mobo_assert_same( 'granted', $S::resolve_status( array( 'status' => 'granted', 'expiresAt' => $now + 10 ), $now ), 'granted and future expiry -> granted' );
mobo_assert_same( 'expired', $S::resolve_status( array( 'status' => 'granted', 'expiresAt' => $now ), $now ), 'granted but expiry reached -> expired' );
mobo_assert_same( 'revoked', $S::resolve_status( array( 'status' => 'revoked', 'expiresAt' => $now + 99 ), $now ), 'revoked stays revoked' );
mobo_assert_same( 'expired', $S::resolve_status( array( 'status' => 'expired' ), $now ), 'expired stays expired' );
mobo_assert_same( 'not_granted', $S::resolve_status( array( 'status' => 'weird' ), $now ), 'unknown status -> not_granted' );
mobo_assert_same( 60, $S::clamp_ttl( 5 ), 'ttl clamped to 60' );
mobo_assert_same( 600, $S::clamp_ttl( 9999 ), 'ttl clamped to 600' );
mobo_assert_same( 300, $S::clamp_ttl( null ), 'ttl default 300' );
mobo_assert_same( 7, $S::clamp_days( 0 ), 'days default 7' );
mobo_assert_same( 30, $S::clamp_days( 90 ), 'days clamped to 30' );
mobo_assert_same( 1, $S::clamp_days( 1 ), 'days min 1' );
mobo_assert( is_wp_error( $S::grant( 7, 'x' ) ), 'grant() refuses without an admin capability context (never reachable from REST)' );

/* ------------------------------------------------------------------ */
echo "Token lifecycle\n";
$GLOBALS['mobo_test_users'][42]                                  = (object) array( 'ID' => 42, 'user_login' => 'MoboCore', 'is_admin' => true );
$GLOBALS['mobo_test_user_meta'][42][ $S::USER_META_MARK ]        = '1';
update_option( $S::OPTION_STATE, array( 'status' => 'granted', 'userId' => 42, 'login' => 'MoboCore', 'grantedAt' => $now - 60, 'expiresAt' => $now + 3600 ) );

$link = $S::create_login_link( 'admin@mobo.test', 300, $now );
mobo_assert( ! empty( $link['success'] ), 'login link created while access granted' );
mobo_assert( 0 === strpos( $link['url'], 'http://shop.test/wp-json/mobo-core/v1/support/login?token=' ), 'URL uses the public login route' );
mobo_assert_same( gmdate( 'c', $now + 300 ), $link['expiresAt'], 'expiresAt is ISO-8601 UTC now+ttl' );
mobo_assert( (bool) preg_match( '/^[a-f0-9]{64}$/', $link['token'] ), 'token is 32 random bytes (hex)' );
$stored_dump = json_encode( $GLOBALS['wpdb']->rows ) . json_encode( $GLOBALS['mobo_test_options'] );
mobo_assert( false === strpos( $stored_dump, $link['token'] ), 'plain token is never stored (hash only)' );
mobo_assert( isset( $GLOBALS['wpdb']->rows[ $S::token_option_name( hash( 'sha256', $link['token'] ) ) ] ), 'SHA-256 hash record stored' );

$first = $S::consume_login_token( $link['token'], '203.0.113.5', $now + 10 );
mobo_assert( ! empty( $first['ok'] ) && 42 === $first['user']->ID, 'first use logs in the support user' );
mobo_assert_same( 'admin@mobo.test', $first['requestedBy'], 'requestedBy carried to the log' );
$second = $S::consume_login_token( $link['token'], '203.0.113.5', $now + 11 );
mobo_assert( empty( $second['ok'] ) && 'already_used' === $second['reason'], 'second use is rejected (single use)' );

$race = $S::create_login_link( 'admin@mobo.test', 300, $now );
$GLOBALS['wpdb']->before_update = static function ( $db ) use ( $race, $S ) {
	$name = $S::token_option_name( hash( 'sha256', $race['token'] ) );
	$rec = json_decode( $db->rows[ $name ], true );
	$rec['used'] = 1;
	$db->rows[ $name ] = json_encode( $rec );
};
$raced = $S::consume_login_token( $race['token'], '203.0.113.6', $now + 5 );
mobo_assert( empty( $raced['ok'] ) && 'already_used' === $raced['reason'], 'CAS: a concurrent consumer wins, the loser is rejected' );

$late = $S::create_login_link( 'admin@mobo.test', 60, $now );
$expired = $S::consume_login_token( $late['token'], '203.0.113.7', $now + 61 );
mobo_assert( empty( $expired['ok'] ) && 'expired_token' === $expired['reason'], 'expired token is rejected' );

$pre_revoke = $S::create_login_link( 'admin@mobo.test', 600, $now );
update_option( $S::OPTION_STATE, array( 'status' => 'revoked', 'userId' => 42, 'login' => 'MoboCore', 'expiresAt' => $now + 3600 ) );
$after_revoke = $S::consume_login_token( $pre_revoke['token'], '203.0.113.8', $now + 1 );
mobo_assert( empty( $after_revoke['ok'] ) && 'access_not_granted' === $after_revoke['reason'], 'token issued before revoke is rejected' );
$refused = $S::create_login_link( 'admin@mobo.test', 300, $now );
mobo_assert( empty( $refused['success'] ) && 'support_access_not_granted' === $refused['code'] && 'revoked' === $refused['status'], 'link refused after revoke with status=revoked' );
update_option( $S::OPTION_STATE, array( 'status' => 'granted', 'userId' => 42, 'login' => 'MoboCore', 'expiresAt' => $now - 1 ) );
$refused = $S::create_login_link( 'admin@mobo.test', 300, $now );
mobo_assert_same( 'expired', $refused['status'], 'link refused after expiry with status=expired' );
delete_option( $S::OPTION_STATE );
$refused = $S::create_login_link( 'admin@mobo.test', 300, $now );
mobo_assert_same( 'not_granted', $refused['status'], 'link refused when never granted' );

update_option( $S::OPTION_STATE, array( 'status' => 'granted', 'userId' => 42, 'login' => 'MoboCore', 'expiresAt' => $now + 3600 ) );
$GLOBALS['mobo_test_users'][42]->is_admin = false;
$demoted = $S::create_login_link( 'x', 300, $now );
$demoted_use = $S::consume_login_token( $demoted['token'], '203.0.113.9', $now + 1 );
mobo_assert( empty( $demoted_use['ok'] ) && 'support_user_invalid' === $demoted_use['reason'], 'support user without admin capability cannot log in' );
$GLOBALS['mobo_test_users'][42]->is_admin = true;

$S::purge_all_tokens();
mobo_assert_same( array(), array_filter( array_keys( $GLOBALS['wpdb']->rows ), static function ( $k ) use ( $S ) { return 0 === strpos( $k, $S::TOKEN_OPTION_PREFIX ); } ), 'purge removes every token record' );

mobo_assert_same( 'malformed', $S::consume_login_token( 'abc', '198.51.100.1', $now )['reason'], 'malformed token rejected' );
mobo_assert_same( 'unknown_token', $S::consume_login_token( str_repeat( 'a', 64 ), '198.51.100.1', $now )['reason'], 'unknown token rejected' );

echo "Rate limiting\n";
$GLOBALS['mobo_test_transients'] = array();
for ( $i = 0; $i < 10; $i++ ) {
	$S::consume_login_token( str_repeat( 'b', 64 ), '192.0.2.10', $now );
}
$good = $S::create_login_link( 'x', 300, $now );
$limited = $S::consume_login_token( $good['token'], '192.0.2.10', $now + 1 );
mobo_assert( empty( $limited['ok'] ) && 'rate_limited' === $limited['reason'], '11th attempt in a minute is blocked even with a valid token' );
$other = $S::consume_login_token( $good['token'], '192.0.2.11', $now + 1 );
mobo_assert( ! empty( $other['ok'] ), 'other IP unaffected; blocked attempt did not consume the token' );
mobo_assert( ! $S::is_rate_limited( '192.0.2.10', $now + 61 ), 'window resets after 60s' );

echo "Access log\n";
$log = $S::get_log();
mobo_assert( count( $log ) > 5 && count( $log ) <= 100, 'log populated and bounded' );
$events = array_column( $log, 'event' );
mobo_assert( in_array( 'login_success', $events, true ) && in_array( 'login_failure', $events, true ), 'success and failure logged' );
mobo_assert( false === strpos( json_encode( $log ), $good['token'] ), 'log never contains tokens' );
for ( $i = 0; $i < 150; $i++ ) { $S::log_event( 'link_created', array( 'requestedBy' => 'x' ) ); }
mobo_assert_same( 100, count( $S::get_log() ), 'log keeps last 100' );

echo "Access requests\n";
$S::record_access_request( '<b>please</b> check orders', 'agent@mobo' );
mobo_assert( $S::has_pending_request(), 'request becomes pending' );
mobo_assert_same( 'please check orders', $S::get_pending_requests()[0]['message'], 'message sanitized' );
$S::mark_requests_handled( 'granted' );
mobo_assert( ! $S::has_pending_request(), 'grant/decline closes pending requests' );

/* ------------------------------------------------------------------ */
echo "Health shapes\n";
$hs = $S::health_support_access( $now );
mobo_assert_same( array( 'status', 'user', 'grantedAt', 'expiresAt', 'pendingRequest' ), array_keys( $hs ), 'supportAccess keys' );
mobo_assert_same( 'granted', $hs['status'], 'supportAccess status' );
update_option( $S::OPTION_HOSTING, array( 'status' => 'provided', 'panelType' => 'cpanel', 'panelUrl' => 'https://host.test:2083', 'username' => 'cpuser', 'submittedAt' => $now ) );
$hh = $S::health_hosting_access();
mobo_assert_same( array( 'status', 'panelType', 'panelUrl', 'submittedAt' ), array_keys( $hh ), 'hostingAccess keys (no username/password)' );
mobo_assert( false === strpos( json_encode( $hh ), 'cpuser' ), 'hostingAccess never exposes the username' );
delete_option( $S::OPTION_HOSTING );
mobo_assert_same( array( 'status' => 'not_provided', 'panelType' => null, 'panelUrl' => null, 'submittedAt' => null ), $S::health_hosting_access(), 'hostingAccess default' );
$sa = $S::health_source_account();
mobo_assert_same( array( 'status', 'checkedAt', 'message', 'walletLow' ), array_keys( $sa ), 'sourceAccount keys' );
mobo_assert_same( 'unknown', $sa['status'], 'sourceAccount unknown before first check' );

/* ------------------------------------------------------------------ */
echo "Source status classification\n";
mobo_assert_same( 'ok', $S::classify_source_result( true ), 'success -> ok' );
mobo_assert_same( 'not_configured', $S::classify_source_result( new WP_Error( 'mobo_core_mobo_credentials_missing', '' ) ), 'missing credentials -> not_configured' );
mobo_assert_same( 'invalid_credentials', $S::classify_source_result( new WP_Error( 'mobo_core_mobo_login_http_error', '', array( 'failure_class' => 'authentication_failed', 'status' => 401 ) ) ), '401 -> invalid_credentials' );
mobo_assert_same( 'invalid_credentials', $S::classify_source_result( new WP_Error( 'mobo_core_mobo_login_http_error', '', array( 'failure_class' => 'request_rejected', 'status' => 400 ) ) ), '400 rejected login -> invalid_credentials' );
mobo_assert_same( 'invalid_credentials', $S::classify_source_result( new WP_Error( 'mobo_core_mobo_login_missing_cookie', '' ) ), 'no auth cookie -> invalid_credentials' );
mobo_assert_same( 'locked', $S::classify_source_result( new WP_Error( 'mobo_core_mobo_login_http_error', '', array( 'failure_class' => 'request_rejected', 'status' => 423 ) ) ), '423 -> locked' );
mobo_assert_same( 'locked', $S::classify_source_result( new WP_Error( 'mobo_core_mobo_login_http_error', '', array( 'failure_class' => 'rate_limited', 'status' => 429 ) ) ), '429 -> locked' );
mobo_assert_same( 'locked', $S::classify_source_result( new WP_Error( 'mobo_core_mobo_login_http_error', '', array( 'failure_class' => 'access_denied', 'status' => 403 ) ) ), '403 -> locked' );
mobo_assert_same( 'unreachable', $S::classify_source_result( new WP_Error( 'mobo_core_mobo_login_transport_error', '', array( 'failure_class' => 'timeout' ) ) ), 'timeout -> unreachable' );
mobo_assert_same( 'unreachable', $S::classify_source_result( new WP_Error( 'mobo_core_mobo_login_http_error', '', array( 'failure_class' => 'upstream_failure', 'status' => 502 ) ) ), '5xx -> unreachable' );
mobo_assert_same( 'unreachable', $S::classify_source_result( new WP_Error( 'mobo_core_dependency_backoff', '', array( 'failure_class' => 'dns_failure' ) ) ), 'circuit backoff -> unreachable' );
mobo_assert_same( 'skip', $S::classify_source_result( new WP_Error( 'mobo_core_shared_cart_locked', '' ) ), 'local cart lock contention -> skip (keeps last verdict)' );
mobo_assert_same( 'unknown', $S::classify_source_result( new WP_Error( 'something_else', '' ) ), 'anything else -> unknown' );
mobo_assert_same( true, $S::wallet_low_from_status( array( 'supported' => true, 'hasBalance' => true, 'lastBalance' => 500, 'threshold' => 1000 ) ), 'wallet below threshold -> walletLow true' );
mobo_assert_same( false, $S::wallet_low_from_status( array( 'supported' => true, 'hasBalance' => true, 'lastBalance' => 5000, 'threshold' => 1000 ) ), 'wallet above threshold -> false' );
mobo_assert_same( null, $S::wallet_low_from_status( array( 'supported' => true, 'hasBalance' => false ) ), 'no balance -> null' );
mobo_assert_same( null, $S::wallet_low_from_status( array( 'supported' => true, 'hasBalance' => true, 'lastBalance' => 5, 'threshold' => 0 ) ), 'no threshold -> null' );

echo "Scheduled Source check policy\n";
mobo_assert( $S::scheduled_check_due( array(), 'fp1', $now ), 'never checked -> due' );
mobo_assert( ! $S::scheduled_check_due( array( 'status' => 'ok', 'fingerprint' => 'fp1', 'nextDueAt' => $now + 100 ), 'fp1', $now ), 'within 6h -> not due' );
mobo_assert( $S::scheduled_check_due( array( 'status' => 'ok', 'fingerprint' => 'fp1', 'nextDueAt' => $now ), 'fp1', $now ), 'after 6h -> due' );
mobo_assert( ! $S::scheduled_check_due( array( 'status' => 'invalid_credentials', 'fingerprint' => 'fp1', 'nextDueAt' => 0 ), 'fp1', $now ), 'wrong password never retried automatically (no lockout)' );
mobo_assert( ! $S::scheduled_check_due( array( 'status' => 'locked', 'fingerprint' => 'fp1', 'nextDueAt' => 0 ), 'fp1', $now ), 'locked account never retried automatically' );
mobo_assert( $S::scheduled_check_due( array( 'status' => 'invalid_credentials', 'fingerprint' => 'fp1', 'nextDueAt' => $now + 999 ), 'fp2', $now ), 'changed credentials -> due immediately' );
mobo_assert( ! $S::scheduled_check_due( array( 'status' => 'not_configured', 'fingerprint' => '', 'nextDueAt' => 0 ), '', $now ), 'still not configured -> not due' );

/* ------------------------------------------------------------------ */
echo "Sealing (RSA-OAEP)\n";
$key = $S::public_key();
mobo_assert_same( 'sa1-31e692f99104d251', $key['keyId'], 'embedded keyId' );
mobo_assert( false !== openssl_pkey_get_public( $key['pem'] ), 'embedded public key parses' );
$secret = 'P@ss wörd-' . bin2hex( random_bytes( 6 ) );
$sealed = $S::seal_password( $secret, $key['pem'] );
mobo_assert( is_string( $sealed ) && false !== base64_decode( $sealed, true ), 'sealed password is base64' );
mobo_assert( false === strpos( (string) base64_decode( (string) $sealed ), $secret ), 'ciphertext does not contain the password' );
$private_file = getenv( 'MOBO_SUPPORT_PRIVATE_KEY_FILE' ) ?: 'C:\\MOBO-WORK\\staging\\secrets\\support-access-private.pem';
if ( is_readable( $private_file ) ) {
	$private = openssl_pkey_get_private( (string) file_get_contents( $private_file ) );
	$plain   = '';
	$ok      = $private && openssl_private_decrypt( (string) base64_decode( (string) $sealed ), $plain, $private, OPENSSL_PKCS1_OAEP_PADDING );
	mobo_assert( $ok && $plain === $secret, 'staging private key decrypts the sealed password' );
	$details = $private ? openssl_pkey_get_details( $private ) : array();
	$pub     = openssl_pkey_get_details( openssl_pkey_get_public( $key['pem'] ) );
	mobo_assert( isset( $details['key'], $pub['key'] ) && $details['key'] === $pub['key'], 'embedded public key matches the staging key pair' );
	unset( $private, $plain );
} else {
	mobo_assert( false, 'staging private key not readable at ' . $private_file . ' (decryptability not verified)' );
}
mobo_assert_error( 'mobo_core_support_password_invalid', $S::seal_password( str_repeat( 'x', 257 ), $key['pem'] ), 'password over 256 bytes refused' );
mobo_assert_error( 'mobo_core_support_key_invalid', $S::seal_password( 'abc', 'not a key' ), 'invalid key refused' );

$fields = $S::validate_hosting_input( array( 'panelType' => 'cpanel', 'panelUrl' => 'https://host.test:2083', 'username' => 'cpuser', 'password' => $secret ) );
mobo_assert( is_array( $fields ), 'valid hosting input accepted' );
$payload = $S::build_hosting_payload( $fields );
mobo_assert_same( array( 'panelType', 'panelUrl', 'username', 'keyId', 'sealedPassword' ), array_keys( $payload ), 'Backend body keys match the contract' );
mobo_assert( false === strpos( json_encode( $payload ), $secret ), 'Backend body never carries the plain password' );
mobo_assert_error( 'mobo_core_support_panel_type', $S::validate_hosting_input( array( 'panelType' => 'webmin', 'panelUrl' => 'https://h.test', 'username' => 'u', 'password' => 'p' ) ), 'unknown panel type refused' );
mobo_assert_error( 'mobo_core_support_panel_url', $S::validate_hosting_input( array( 'panelType' => 'plesk', 'panelUrl' => 'ftp://h.test', 'username' => 'u', 'password' => 'p' ) ), 'non-http URL refused' );
mobo_assert_error( 'mobo_core_support_panel_url', $S::validate_hosting_input( array( 'panelType' => 'plesk', 'panelUrl' => 'https://u:p@h.test', 'username' => 'u', 'password' => 'p' ) ), 'URL with embedded credentials refused' );
mobo_assert_error( 'mobo_core_support_panel_url', $S::validate_hosting_input( array( 'panelType' => 'plesk', 'panelUrl' => 'not a url', 'username' => 'u', 'password' => 'p' ) ), 'garbage URL refused' );
mobo_assert_error( 'mobo_core_support_panel_password', $S::validate_hosting_input( array( 'panelType' => 'other', 'panelUrl' => 'http://h.test', 'username' => 'u', 'password' => '' ) ), 'empty password refused' );
mobo_assert_error( 'mobo_core_support_panel_username', $S::validate_hosting_input( array( 'panelType' => 'other', 'panelUrl' => 'http://h.test', 'username' => '', 'password' => 'p' ) ), 'empty username refused' );

$GLOBALS['mobo_test_filters']['mobo_core_support_public_key'] = static function ( $value ) { return array( 'keyId' => 'staging-key', 'pem' => $value['pem'] ); };
mobo_assert_same( 'staging-key', $S::public_key()['keyId'], 'public key overridable by filter (staging)' );
$GLOBALS['mobo_test_filters']['mobo_core_support_public_key'] = static function () { return 'garbage'; };
mobo_assert_same( 'sa1-31e692f99104d251', $S::public_key()['keyId'], 'invalid filter value falls back to the embedded key' );
unset( $GLOBALS['mobo_test_filters']['mobo_core_support_public_key'] );

/* ------------------------------------------------------------------ */
echo "Readiness computation\n";
$keys = array( 'support_access', 'source_account', 'hosting_access', 'cron', 'loopback', 'php_version', 'migration', 'plugin_integrity' );
$good = $S::compute_readiness(
	array(
		'now' => $now, 'supportStatus' => 'granted', 'sourceStatus' => 'ok', 'hostingStatus' => 'provided',
		'cronLastSuccessAt' => $now - 120, 'selfRunLastSuccessAt' => 0, 'loopbackEnabled' => true,
		'loopbackLastAttemptAt' => $now - 50, 'loopbackLastRunSuccessAt' => $now - 40,
		'phpVersion' => '8.3.14', 'phpMinimum' => '7.4', 'php64bit' => true,
		'migrationStamped' => true, 'migrationMarked' => 0, 'integrity' => true,
	)
);
mobo_assert_same( $keys, array_column( $good, 'key' ), 'readiness keys and order match the contract' );
mobo_assert_same( array_fill( 0, 8, true ), array_column( $good, 'ok' ), 'healthy inputs -> all ok' );
mobo_assert( 8 === count( array_filter( array_column( $good, 'message' ), 'strlen' ) ), 'every item has a message' );
$bad = $S::compute_readiness(
	array(
		'now' => $now, 'supportStatus' => 'expired', 'sourceStatus' => 'invalid_credentials', 'hostingStatus' => 'send_failed',
		'cronLastSuccessAt' => $now - 3 * 3600, 'selfRunLastSuccessAt' => 0, 'loopbackEnabled' => true,
		'loopbackLastAttemptAt' => $now - 50, 'loopbackLastRunSuccessAt' => $now - 2 * 86400,
		'phpVersion' => '7.3.33', 'phpMinimum' => '7.4', 'php64bit' => true,
		'migrationStamped' => true, 'migrationMarked' => 3, 'integrity' => false,
	)
);
mobo_assert_same( array_fill( 0, 8, false ), array_column( $bad, 'ok' ), 'broken inputs -> all not ok' );
$by_key = array_combine( array_column( $bad, 'key' ), $bad );
mobo_assert( false !== strpos( $by_key['migration']['message'], '3' ), 'migration message mentions marked count' );
mobo_assert( false !== strpos( $by_key['php_version']['message'], '7.3.33' ), 'php message mentions current version' );
$mixed = $S::compute_readiness( array( 'now' => $now, 'cronLastSuccessAt' => 0, 'selfRunLastSuccessAt' => $now - 60, 'loopbackEnabled' => false, 'php64bit' => false, 'phpVersion' => '8.1.0', 'integrity' => null ) );
$by_key = array_combine( array_column( $mixed, 'key' ), $mixed );
mobo_assert( true === $by_key['cron']['ok'], 'self-runner success counts for cron' );
mobo_assert( true === $by_key['loopback']['ok'], 'disabled loopback is not a failure' );
mobo_assert( false === $by_key['php_version']['ok'], '32-bit PHP is not ready' );
mobo_assert( false === $by_key['plugin_integrity']['ok'], 'missing manifest is not ok' );
mobo_assert( false === $by_key['migration']['ok'], 'unstamped migration is not ok' );

echo "Manifest integrity\n";
$dir = sys_get_temp_dir() . '/mobo-support-manifest-' . bin2hex( random_bytes( 4 ) );
mkdir( $dir . '/includes', 0777, true );
file_put_contents( $dir . '/includes/a.php', '<?php // a' );
file_put_contents( $dir . '/mobo-core-manifest.json', json_encode( array( 'algorithm' => 'sha256', 'version' => '1.2.3', 'files' => array( 'includes/a.php' => hash_file( 'sha256', $dir . '/includes/a.php' ) ) ) ) );
mobo_assert_same( true, $S::verify_manifest( $dir, '1.2.3' ), 'intact tree verifies' );
mobo_assert_same( false, $S::verify_manifest( $dir, '9.9.9' ), 'version mismatch fails' );
file_put_contents( $dir . '/includes/a.php', '<?php // tampered' );
mobo_assert_same( false, $S::verify_manifest( $dir, '1.2.3' ), 'tampered file fails' );
unlink( $dir . '/includes/a.php' );
unlink( $dir . '/mobo-core-manifest.json' );
mobo_assert_same( null, $S::verify_manifest( $dir, '1.2.3' ), 'missing manifest -> null' );
rmdir( $dir . '/includes' );
rmdir( $dir );

mobo_finish();
