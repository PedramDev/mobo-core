<?php
/**
 * Standalone tests: Backend URL validation, legacy URL adoption and the
 * provisioning host-change rule in Mobo_Core_Connection_Repository.
 *
 * Run: php tests-dev/connection-url-policy-test.php
 * No WordPress, no network (DNS is injected), no database (fake $wpdb).
 */

require __DIR__ . '/stubs.php';

class Mobo_Test_WPDB {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $insert_id = 7;
	public $last_error = '';
	public $default_row = null;
	public $fresh_row = null;
	public $token_rows = array();
	public $inserted = null;
	public $queries = array();
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
			$value = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
		}, $query );
	}
	public function get_var( $sql ) {
		if ( 0 === strpos( $sql, 'SHOW TABLES' ) ) { return 'wp_mobo_connections'; }
		if ( false !== strpos( $sql, 'COUNT(*)' ) ) { return 1; }
		return null;
	}
	public function get_row( $sql ) {
		if ( false !== strpos( $sql, 'WHERE id =' ) ) { return null !== $this->fresh_row ? $this->fresh_row : $this->default_row; }
		return $this->default_row;
	}
	public function get_results( $sql ) { return $this->token_rows; }
	public function insert( $table, $data, $formats ) { $this->inserted = $data; return 1; }
	public function update( $table, $data, $where ) { $this->queries[] = array( 'update', $data ); return 1; }
	public function query( $sql ) { $this->queries[] = $sql; return 1; }
}

class Mobo_Core_Provider_Registry {
	public static function normalize_key( $key ) { return strtolower( trim( (string) $key ) ); }
	public static function exists( $key ) { return 'portal.ir' === $key; }
}

class Mobo_Core_Money_Policy {
	const REPRESENTATION_MAJOR = 'major';
	const CONTRACT_VERSION = 1;
	public static function normalize_unit( $v ) { return strtoupper( trim( (string) $v ) ); }
	public static function normalize_fraction_digits( $v ) { return is_numeric( $v ) ? (int) $v : -1; }
	public static function normalize_representation( $v ) { return strtolower( trim( (string) $v ) ); }
	public static function normalize_contract_version( $v ) { return absint( $v ); }
	public static function contract_from_row( $row ) {
		return array( 'unit' => (string) $row['money_unit'], 'fractionDigits' => (int) $row['money_fraction_digits'], 'priceRepresentation' => (string) $row['money_price_representation'] );
	}
	public static function compatibility_status_for_row( $row ) { return array( 'compatible' => true ); }
}

$GLOBALS['wpdb'] = new Mobo_Test_WPDB();
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-connection-repository.php';

$repo = 'Mobo_Core_Connection_Repository';
$public_dns = function ( $host ) { return array( '185.10.20.30' ); };
$private_dns = function ( $host ) { return array( '185.10.20.30', '10.0.0.5' ); };
$no_dns = function ( $host ) { return false; };

echo "validate_backend_base_url (production: local targets forbidden)\n";
mobo_assert_same( 'http://mobo.codeya.ir/', $repo::validate_backend_base_url( 'http://mobo.codeya.ir', false, $public_dns ), 'plain http Backend is accepted and normalized' );
mobo_assert_same( 'https://portal.example.com:8443/', $repo::validate_backend_base_url( 'https://portal.example.com:8443', false, $public_dns ), 'https with port accepted' );
mobo_assert_same( 'http://dev.codeya.ir:5015/', $repo::validate_backend_base_url( 'http://dev.codeya.ir:5015', false, $public_dns ), 'Portal dev form (authority only) accepted' );
mobo_assert_same( 'http://8.8.8.8/', $repo::validate_backend_base_url( 'http://8.8.8.8', false, $no_dns ), 'public IPv4 literal accepted without DNS' );
mobo_assert_same( 'http://[2001:4860::8888]/', $repo::validate_backend_base_url( 'http://[2001:4860::8888]', false, $no_dns ), 'public IPv6 literal accepted' );
foreach ( array(
	'http://127.0.0.1/', 'http://127.1.2.3:8080/', 'http://10.1.2.3/', 'http://172.16.5.5/', 'http://172.31.255.255/',
	'http://192.168.1.10/', 'http://169.254.169.254/', 'http://100.64.0.1/', 'http://0.0.0.0/', 'http://224.0.0.1/',
	'http://[::1]/', 'http://[::]/', 'http://[fe80::1]/', 'http://[fd12:3456::1]/', 'http://[::ffff:127.0.0.1]/',
	'http://[::ffff:10.0.0.1]/', 'http://[64:ff9b::a00:1]/', 'http://localhost/', 'http://api.localhost/',
	'http://backend.local/', 'http://intranet/', 'http://svc.internal/',
) as $bad ) {
	mobo_assert_error( 'mobo_core_backend_url_local', $repo::validate_backend_base_url( $bad, false, $public_dns ), 'rejects local target ' . $bad );
}
mobo_assert_same( 'http://172.32.0.1/', $repo::validate_backend_base_url( 'http://172.32.0.1', false, $no_dns ), '172.32.0.1 is public (just outside 172.16/12)' );
mobo_assert_error( 'mobo_core_backend_url_local', $repo::validate_backend_base_url( 'http://mobo.example.com', false, $private_dns ), 'hostname resolving to a private address is rejected' );
mobo_assert_error( 'mobo_core_backend_url_unresolvable', $repo::validate_backend_base_url( 'http://mobo.example.com', false, $no_dns ), 'unresolvable hostname fails closed' );
foreach ( array( '', 'ftp://mobo.codeya.ir/', 'javascript:alert(1)', 'http://user:pw@mobo.codeya.ir/', 'http://mobo.codeya.ir/?a=1', 'http://mobo.codeya.ir/#x', 'mobo.codeya.ir', 'http://mo bo.ir/', 'http://bad_host.example.com/' ) as $bad ) {
	mobo_assert_error( 'mobo_core_backend_url_invalid', $repo::validate_backend_base_url( $bad, false, $public_dns ), 'rejects malformed ' . var_export( $bad, true ) );
}

echo "validate_backend_base_url (local development allowed)\n";
mobo_assert_same( 'http://127.0.0.1:5015/', $repo::validate_backend_base_url( 'http://127.0.0.1:5015', true, $no_dns ), 'loopback allowed when local dev enabled' );
mobo_assert_same( 'http://localhost/', $repo::validate_backend_base_url( 'http://localhost', true, $no_dns ), 'localhost allowed when local dev enabled' );
mobo_assert_error( 'mobo_core_backend_url_invalid', $repo::validate_backend_base_url( 'ftp://localhost', true, $no_dns ), 'scheme still enforced in local dev' );
mobo_assert_same( false, $repo::local_backend_urls_allowed(), 'no WP_DEBUG / constant / env type => local not allowed' );

echo "same_url_host\n";
mobo_assert_same( true, $repo::same_url_host( 'http://mobo.codeya.ir/', 'https://MOBO.codeya.ir.:8443/x/' ), 'scheme/port/path/case/trailing-dot ignored' );
mobo_assert_same( false, $repo::same_url_host( 'http://mobo.codeya.ir/', 'http://evil.example/' ), 'different host' );
mobo_assert_same( false, $repo::same_url_host( '', 'http://evil.example/' ), 'empty current host never matches' );

echo "legacy adoption context and URL precedence\n";
$GLOBALS['mobo_test_options']['mobo_core_db_version'] = '10.33.67';
mobo_assert_same( true, $repo::is_legacy_adoption_context(), '10.33.67 upgrade is a legacy adoption' );
$GLOBALS['mobo_test_options']['mobo_core_db_version'] = '';
mobo_assert_same( false, $repo::is_legacy_adoption_context(), 'fresh install is not' );
$GLOBALS['mobo_test_options']['mobo_core_db_version'] = '10.34.12';
mobo_assert_same( false, $repo::is_legacy_adoption_context(), '10.34.12+ is not' );
mobo_assert_same( '', $repo::legacy_adoption_api_base_url( false ), 'outside adoption => no legacy URL (new/extra Connections)' );
unset( $GLOBALS['mobo_test_options']['mobo_core_api_base_url'] );
mobo_assert_same( 'http://mobo.codeya.ir/', $repo::legacy_adoption_api_base_url( true ), 'no legacy option => old built-in default' );
$GLOBALS['mobo_test_options']['mobo_core_api_base_url'] = 'http://dev.mobo.codeya.ir';
mobo_assert_same( 'http://mobo.codeya.ir/', $repo::legacy_adoption_api_base_url( true ), 'H1: stale legacy option is IGNORED (10.33.67 never used it at runtime) => old default' );
mobo_assert_same( 'http://8.8.8.8/', $repo::legacy_adoption_api_base_url( true, 'http://8.8.8.8' ), 'H1: wp-config MOBO_API_BASE_URL is seeded (it was the 10.33.67 effective URL)' );
mobo_assert_same( 'http://mobo.codeya.ir/', $repo::legacy_adoption_api_base_url( true, 'http://127.0.0.1/' ), 'H1: local constant on production falls back to the old default' );
mobo_assert_same( 'http://mobo.codeya.ir/', $repo::legacy_adoption_api_base_url( true, '' ), 'H1: empty constant => old default' );
$GLOBALS['mobo_test_options']['mobo_core_api_base_url'] = 'http://127.0.0.1/';
mobo_assert_same( 'http://mobo.codeya.ir/', $repo::legacy_adoption_api_base_url( true ), 'local legacy option on production falls back to default' );
$GLOBALS['mobo_test_options']['mobo_core_api_base_url'] = 'not a url';
mobo_assert_same( 'http://mobo.codeya.ir/', $repo::legacy_adoption_api_base_url( true ), 'garbage legacy option falls back to default' );
unset( $GLOBALS['mobo_test_options']['mobo_core_api_base_url'] );

echo "ensure_default_connection seeds the legacy URL only on upgrade\n";
$wpdb = $GLOBALS['wpdb'];
$GLOBALS['mobo_test_options']['mobo_core_db_version'] = '10.33.67';
$wpdb->default_row = null;
$id = $repo::ensure_default_connection();
mobo_assert_same( 7, $id, 'default row inserted' );
mobo_assert_same( 'http://mobo.codeya.ir/', $wpdb->inserted['api_base_url'] ?? null, 'upgrade insert carries the legacy Backend URL' );
$GLOBALS['mobo_test_options']['mobo_core_api_base_url'] = 'http://staging.mobo.example';
$wpdb->inserted = null;
$repo::ensure_default_connection();
mobo_assert_same( 'http://mobo.codeya.ir/', $wpdb->inserted['api_base_url'] ?? null, 'H1: upgrade insert ignores a stale dev/staging legacy option' );
unset( $GLOBALS['mobo_test_options']['mobo_core_api_base_url'] );
$GLOBALS['mobo_test_options']['mobo_core_db_version'] = '';
$wpdb->inserted = null;
$repo::ensure_default_connection();
mobo_assert_same( '', $wpdb->inserted['api_base_url'] ?? null, 'fresh-install insert stays unprovisioned (empty URL)' );

$GLOBALS['mobo_test_options']['mobo_core_db_version'] = '10.33.67';
$wpdb->queries = array();
$wpdb->default_row = array( 'id' => 7, 'is_default' => 1, 'source_key' => '', 'api_base_url' => '', 'license_token' => '', 'security_code' => '' );
$repo::ensure_default_connection();
$backfill = array_values( array_filter( $wpdb->queries, function ( $q ) { return is_string( $q ) && false !== strpos( $q, "SET api_base_url = 'http://mobo.codeya.ir/'" ); } ) );
mobo_assert( 1 === count( $backfill ) && false !== strpos( $backfill[0], "api_base_url = '' AND source_key = ''" ), 'existing URL-less unprovisioned Default is backfilled with a CAS guard' );
$wpdb->queries = array();
$wpdb->default_row = array( 'id' => 7, 'is_default' => 1, 'source_key' => 'mobomobo', 'api_base_url' => '', 'license_token' => '', 'security_code' => '' );
$repo::ensure_default_connection();
mobo_assert_same( array(), array_values( array_filter( $wpdb->queries, 'is_string' ) ), 'provisioned/bound Default is never backfilled' );
$GLOBALS['mobo_test_options']['mobo_core_db_version'] = '10.34.77';
$wpdb->default_row = array( 'id' => 7, 'is_default' => 1, 'source_key' => '', 'api_base_url' => '', 'license_token' => '', 'security_code' => '' );
$repo::ensure_default_connection();
mobo_assert_same( array(), array_values( array_filter( $wpdb->queries, 'is_string' ) ), 'no backfill after the upgrade has finalized' );

echo "provision_by_token: URL policy and host-change rule\n";
$token = '11111111-2222-4333-8444-555555555555';
$provisioned = array(
	'id' => 7, 'connection_key' => 'k', 'is_default' => 1, 'is_enabled' => 1, 'license_token' => $token,
	'security_code' => 'SECRET-XSEC', 'source_key' => 'mobomobo', 'provider_key' => 'portal.ir',
	'api_base_url' => 'http://8.8.8.8/', 'source_site_url' => 'https://mobomobo.ir/',
	'money_unit' => 'IRT', 'money_fraction_digits' => 0, 'money_price_representation' => 'major', 'money_contract_version' => 1,
);
$payload = array(
	'token' => $token, 'provider_key' => 'portal.ir', 'source_key' => 'mobomobo', 'source_display_name' => 'MoboMobo',
	'api_base_url' => 'http://8.8.8.8', 'source_site_url' => 'https://mobomobo.ir',
	'money_unit' => 'IRT', 'money_fraction_digits' => 0, 'money_price_representation' => 'major', 'money_contract_version' => 1, 'enabled' => true,
);
$wpdb->token_rows = array( $provisioned );
$wpdb->fresh_row = array_merge( $provisioned, array( 'display_name' => 'MoboMobo' ) );
$result = $repo::provision_by_token( $token, $payload );
mobo_assert( is_array( $result ), 'same-host re-provisioning (current Backend client, no X-SEC) still succeeds' );

$wpdb->fresh_row = array_merge( $provisioned, array( 'api_base_url' => 'https://8.8.8.8:8443/' ) );
$result = $repo::provision_by_token( $token, array_merge( $payload, array( 'api_base_url' => 'https://8.8.8.8:8443' ) ) );
mobo_assert( is_array( $result ), 'same host may change scheme/port (http -> https)' );

$result = $repo::provision_by_token( $token, array_merge( $payload, array( 'api_base_url' => 'http://1.1.1.1' ) ) );
mobo_assert_error( 'mobo_core_provision_api_host_change_forbidden', $result, 'provisioned Connection cannot move host with Token HMAC only' );
$result = $repo::provision_by_token( $token, array_merge( $payload, array( 'api_base_url' => 'http://1.1.1.1' ) ), array( 'security_code' => 'wrong' ) );
mobo_assert_error( 'mobo_core_provision_api_host_change_forbidden', $result, 'wrong X-SEC proof is rejected' );
$wpdb->fresh_row = array_merge( $provisioned, array( 'api_base_url' => 'http://1.1.1.1/' ) );
$result = $repo::provision_by_token( $token, array_merge( $payload, array( 'api_base_url' => 'http://1.1.1.1' ) ), array( 'security_code' => 'SECRET-XSEC' ) );
mobo_assert( is_array( $result ), 'host move allowed with matching X-SEC proof' );

$result = $repo::provision_by_token( $token, array_merge( $payload, array( 'api_base_url' => 'http://192.168.0.10' ) ), array( 'security_code' => 'SECRET-XSEC' ) );
mobo_assert_error( 'mobo_core_backend_url_local', $result, 'private Backend IP rejected even with X-SEC' );
mobo_assert_same( 422, is_wp_error( $result ) ? ( $result->get_error_data()['status'] ?? 0 ) : 0, 'URL policy rejection is HTTP 422' );

$unprovisioned = array_merge( $provisioned, array( 'source_key' => '', 'provider_key' => '', 'api_base_url' => 'http://mobo.codeya.ir/', 'source_site_url' => '' ) );
$wpdb->token_rows = array( $unprovisioned );
$wpdb->fresh_row = array_merge( $provisioned, array( 'api_base_url' => 'http://1.1.1.1/' ) );
$result = $repo::provision_by_token( $token, array_merge( $payload, array( 'api_base_url' => 'http://1.1.1.1' ) ) );
mobo_assert( is_array( $result ), 'legacy-seeded (unprovisioned) Default can be provisioned to any public host' );

echo "resolve_api_base_url precedence\n";
mobo_assert_same( 'http://mobo.codeya.ir/', $repo::resolve_api_base_url( 'http://mobo.codeya.ir' ), 'without constant the Connection URL is used' );
mobo_assert_same( '', $repo::resolve_api_base_url( '' ), 'no URL stays empty (no hidden default at runtime)' );
define( 'MOBO_API_BASE_URL', 'http://override.example/' );
mobo_assert_same( 'http://override.example/', $repo::resolve_api_base_url( 'http://mobo.codeya.ir/' ), 'MOBO_API_BASE_URL constant wins over the Connection URL' );

mobo_finish();
