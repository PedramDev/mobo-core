<?php
/**
 * Standalone tests: remote updater package-URL trust (http from the exact
 * Backend origin only), migration gate and /upgrade/apply refusal.
 *
 * Run: php tests-dev/remote-updater-policy-test.php
 * No WordPress, no network, no database (fake $wpdb).
 */

require __DIR__ . '/stubs.php';

define( 'MOBO_CORE_VERSION', '10.34.77' );
define( 'MOBO_CORE_PLUGIN_DIR', __DIR__ . '/__no_plugin_dir__/' );
define( 'DB_NAME', 'mobo_test' );

$GLOBALS['mobo_test_base_url'] = 'http://mobo.codeya.ir/';
$GLOBALS['mobo_test_security'] = 'SECRET-XSEC';

/* Fake repository: only what the updater consults. */
final class Mobo_Core_Connection_Repository {
	public static function get_runtime_base_url( $fallback = '' ) { return $GLOBALS['mobo_test_base_url']; }
	public static function get_runtime_value( $field, $fallback = '' ) {
		return 'security_code' === $field ? $GLOBALS['mobo_test_security'] : $fallback;
	}
}

final class Mobo_Core_Migration {
	const MIGRATION_LOCK_OPTION = 'mobo_core_migration_request_lock_v1';
}

class Mobo_Test_WPDB {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $last_error = '';
	public $mysql_holder = null;
	public $lease = null;
	public function prepare( $query, ...$args ) {
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
			return "'" . addslashes( (string) ( $args[ $i++ ] ?? '' ) ) . "'";
		}, $query );
	}
	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'IS_USED_LOCK' ) ) {
			$expected = "'mobo_mig_" . substr( sha1( DB_NAME . '|wp_' ), 0, 40 ) . "'";
			return false !== strpos( $sql, $expected ) ? $this->mysql_holder : 'WRONG-LOCK-NAME';
		}
		if ( false !== strpos( $sql, 'mobo_core_migration_request_lock_v1' ) ) { return $this->lease; }
		return null;
	}
}
$GLOBALS['wpdb'] = new Mobo_Test_WPDB();

function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); }

require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-remote-updater.php';

$trusted = new ReflectionMethod( 'Mobo_Core_Remote_Updater', 'is_trusted_package_url' );
$trusted->setAccessible( true );
$is_trusted = function ( $url ) use ( $trusted ) { return $trusted->invoke( null, $url ); };

echo "package URL trust with an http Backend (no allowlist)\n";
$GLOBALS['mobo_test_base_url'] = 'http://mobo.codeya.ir/';
mobo_assert_same( true, $is_trusted( 'http://mobo.codeya.ir/api/plugin-packages/10.34.80/download' ), 'http package from the exact http Backend origin is trusted' );
mobo_assert_same( true, $is_trusted( 'http://MOBO.codeya.ir:80/api/plugin-packages/x' ), 'explicit default port / host case are the same origin' );
mobo_assert_same( false, $is_trusted( 'http://mobo.codeya.ir:8080/api/plugin-packages/x' ), 'different port rejected' );
mobo_assert_same( false, $is_trusted( 'http://evil.example/api/plugin-packages/x' ), 'other host rejected' );
mobo_assert_same( false, $is_trusted( 'http://sub.mobo.codeya.ir/api/plugin-packages/x' ), 'subdomain rejected' );
mobo_assert_same( false, $is_trusted( 'http://mobo.codeya.ir/downloads/x.zip' ), 'path without /api/plugin-packages/ rejected' );
mobo_assert_same( false, $is_trusted( 'https://mobo.codeya.ir/api/plugin-packages/x' ), 'https package is a different origin from the http Backend (exact origin rule unchanged)' );
mobo_assert_same( false, $is_trusted( 'http://user:pw@mobo.codeya.ir/api/plugin-packages/x' ), 'credentials in URL rejected' );
mobo_assert_same( false, $is_trusted( 'ftp://mobo.codeya.ir/api/plugin-packages/x' ), 'non-http(s) scheme rejected' );

echo "package URL trust with an https Backend\n";
$GLOBALS['mobo_test_base_url'] = 'https://mobo.codeya.ir/';
mobo_assert_same( true, $is_trusted( 'https://mobo.codeya.ir/api/plugin-packages/x' ), 'https package from https Backend still trusted' );
mobo_assert_same( false, $is_trusted( 'http://mobo.codeya.ir/api/plugin-packages/x' ), 'http package is NOT trusted when the Backend is https (no downgrade)' );

echo "package URL trust without a Backend URL\n";
$GLOBALS['mobo_test_base_url'] = '';
mobo_assert_same( false, $is_trusted( 'http://mobo.codeya.ir/api/plugin-packages/x' ), 'no Backend URL => nothing trusted' );

echo "administrator allowlist never widens plain http\n";
$GLOBALS['mobo_test_base_url'] = 'http://mobo.codeya.ir/';
$GLOBALS['mobo_test_options']['mobo_core_remote_update_allowed_hosts'] = 'cdn.example.com';
mobo_assert_same( true, $is_trusted( 'https://cdn.example.com/api/plugin-packages/x' ), 'https allowlisted host keeps working' );
mobo_assert_same( false, $is_trusted( 'http://cdn.example.com/api/plugin-packages/x' ), 'http allowlisted host still rejected' );
mobo_assert_same( true, $is_trusted( 'http://mobo.codeya.ir/api/plugin-packages/x' ), 'http exact Backend origin trusted even with an allowlist' );
unset( $GLOBALS['mobo_test_options']['mobo_core_remote_update_allowed_hosts'] );

echo "migration gate\n";
$wpdb = $GLOBALS['wpdb'];
$GLOBALS['mobo_test_options']['mobo_core_db_version'] = '10.34.77';
$gate = Mobo_Core_Remote_Updater::get_migration_gate_status();
mobo_assert( 'complete' === $gate['state'] && true === $gate['upgradeAllowed'] && false === $gate['inProgress'], 'db version current and no lock => complete' );
$GLOBALS['mobo_test_options']['mobo_core_db_version'] = '10.33.67';
$gate = Mobo_Core_Remote_Updater::get_migration_gate_status();
mobo_assert( 'pending' === $gate['state'] && false === $gate['upgradeAllowed'] && '10.33.67' === $gate['dbVersion'], 'db version behind => pending, not allowed' );
$GLOBALS['mobo_test_options']['mobo_core_db_version'] = '10.34.77';
$wpdb->mysql_holder = '4242';
$gate = Mobo_Core_Remote_Updater::get_migration_gate_status();
mobo_assert( 'running' === $gate['state'] && 'mysql' === $gate['lockKind'], 'GET_LOCK held (same name as Mobo_Core_Migration) => running' );
$wpdb->mysql_holder = null;
$wpdb->lease = json_encode( array( 'owner' => 'x', 'expiresAt' => time() + 60 ) );
$gate = Mobo_Core_Remote_Updater::get_migration_gate_status();
mobo_assert( 'running' === $gate['state'] && 'option' === $gate['lockKind'], 'live fallback option lease => running' );
$wpdb->lease = json_encode( array( 'owner' => 'x', 'expiresAt' => time() - 60 ) );
$gate = Mobo_Core_Remote_Updater::get_migration_gate_status();
mobo_assert( 'complete' === $gate['state'], 'expired fallback lease is ignored' );
$wpdb->lease = 'garbage';
$gate = Mobo_Core_Remote_Updater::get_migration_gate_status();
mobo_assert( 'running' === $gate['state'], 'undecodable lease fails closed (held)' );
$wpdb->lease = null;

echo "/upgrade/apply refuses during migration (retryable 423)\n";
class Mobo_Test_Request {
	private $params;
	public function __construct( $params ) { $this->params = $params; }
	public function get_json_params() { return $this->params; }
}
function mobo_signed_apply_params( $extra = array() ) {
	$p = array(
		'deploymentId' => 'dep-1', 'targetVersion' => '10.34.80', 'expectedCurrentVersion' => '10.34.77',
		'packageUrl' => 'http://mobo.codeya.ir/api/plugin-packages/10.34.80/download',
		'packageSha256' => str_repeat( 'a', 64 ), 'downloadToken' => str_repeat( 'b', 40 ),
		'issuedAt' => time(), 'signatureVersion' => 1,
	);
	$canonical = implode( "\n", array( $p['deploymentId'], $p['expectedCurrentVersion'], $p['targetVersion'], $p['packageUrl'], $p['packageSha256'], $p['downloadToken'], (string) $p['issuedAt'] ) );
	$p['requestSignature'] = hash_hmac( 'sha256', $canonical, $GLOBALS['mobo_test_security'] );
	return array_merge( $p, $extra );
}
$GLOBALS['mobo_test_base_url'] = 'http://mobo.codeya.ir/';
$GLOBALS['mobo_test_options']['mobo_core_db_version'] = '10.33.67';
$result = Mobo_Core_Remote_Updater::apply( new Mobo_Test_Request( mobo_signed_apply_params() ) );
mobo_assert_error( 'mobo_core_upgrade_migration_in_progress', $result, 'signed http apply is refused while db version is behind' );
$data = is_wp_error( $result ) ? $result->get_error_data() : array();
mobo_assert( 423 === ( $data['status'] ?? 0 ) && ( $data['retryAfter'] ?? 0 ) > 0 && 'migration_in_progress' === ( $data['reason'] ?? '' ), 'refusal is 423 + retryAfter + reason=migration_in_progress' );

$GLOBALS['mobo_test_options']['mobo_core_db_version'] = '10.34.77';
$wpdb->mysql_holder = '99';
$result = Mobo_Core_Remote_Updater::apply( new Mobo_Test_Request( mobo_signed_apply_params( array( 'allowPendingMigration' => true ) ) ) );
mobo_assert_error( 'mobo_core_upgrade_migration_in_progress', $result, 'held migration lock refuses even with allowPendingMigration' );
$wpdb->mysql_holder = null;

$tampered = mobo_signed_apply_params();
$tampered['packageUrl'] = 'http://evil.example/api/plugin-packages/10.34.80/download';
$result = Mobo_Core_Remote_Updater::apply( new Mobo_Test_Request( $tampered ) );
mobo_assert_error( 'mobo_core_upgrade_signature_invalid', $result, 'HMAC signature still covers packageUrl' );

$GLOBALS['mobo_test_options']['mobo_core_db_version'] = '10.34.77';
$down = mobo_signed_apply_params();
$down['targetVersion'] = '10.34.70';
$canonical = implode( "\n", array( $down['deploymentId'], $down['expectedCurrentVersion'], $down['targetVersion'], $down['packageUrl'], $down['packageSha256'], $down['downloadToken'], (string) $down['issuedAt'] ) );
$down['requestSignature'] = hash_hmac( 'sha256', $canonical, $GLOBALS['mobo_test_security'] );
$result = Mobo_Core_Remote_Updater::apply( new Mobo_Test_Request( $down ) );
mobo_assert_error( 'mobo_core_upgrade_downgrade_rejected', $result, 'downgrade rejection unchanged' );

mobo_finish();
