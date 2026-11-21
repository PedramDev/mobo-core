<?php
/**
 * Hosts with many disable_functions / missing extensions.
 *
 *   php tests-dev/disabled-functions-test.php
 *
 * [1] Mobo_Core_Environment fallbacks with simulated disabled functions.
 * [2] Capability probe classification + Persian readiness items.
 * [3] Real `php -d disable_functions=...` subprocesses (current PHP binary):
 *     - lint smoke and a load smoke that requires every plugin file;
 *     - runtime child: request deadline, env helpers, shutdown fallback, error
 *       log, cron runner config/memory helpers, migration lock helper;
 *     - critical existing suites (deadline/lease/lock, migration slice guard,
 *       image processing guards, cache purger, reprice watchdog) re-run with the
 *       functions disabled. A PHP 7.4 "has been disabled" warning or a PHP 8
 *       "Call to undefined function" anywhere fails the test.
 * [4] `php -n` child: extensions loaded via php.ini (mbstring, intl, openssl,
 *     gd, curl, ...) are absent; the probe must report them.
 *
 * No WordPress, no database, no network. PHP 7.4 compatible.
 */

$mode = isset( $argv[1] ) ? (string) $argv[1] : '';
$GLOBALS['__opts_file'] = '';
require __DIR__ . '/diagnostics-test-stubs.php';

$plugin_dir = str_replace( '\\', '/', dirname( __DIR__ ) ) . '/wp-content/plugins/mobo-core/';
$inc        = $plugin_dir . 'includes/';

/** Every function the plugin calls that hosts commonly disable (+ the usual suspects). */
const MOBO_DISABLED_ALL = 'exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec,pcntl_fork,set_time_limit,ignore_user_abort,ini_set,ini_get,ini_get_all,putenv,getenv,getmypid,posix_getpid,posix_kill,posix_uname,sys_getloadavg,fsockopen,pfsockopen,stream_socket_client,curl_multi_exec,parse_ini_file,symlink,link,chmod,chown,flock,disk_free_space,disk_total_space,highlight_file,show_source,phpinfo,mail,error_log,apache_request_headers,opcache_get_status,register_shutdown_function,memory_get_usage,memory_get_peak_usage,php_ini_loaded_file,gethostbyname,gethostbynamel,dns_get_record,usleep,scandir,random_bytes,php_uname';

/** Children record PHP 7.4 "disabled for security reasons" warnings as failures. */
function child_error_guard() {
	set_error_handler(
		static function ( $no, $str, $file, $line ) {
			if ( ( error_reporting() & $no ) && false !== stripos( $str, 'has been disabled' ) ) {
				echo "DISABLED-CALL: {$str} @ " . basename( $file ) . ":{$line}\n";
			}
			return false;
		}
	);
}

/* ======================================================================
 * Child: load every plugin file under the disabled list.
 * ==================================================================== */
if ( '--child-load' === $mode ) {
	child_error_guard();
	define( 'WPINC', 'wp-includes' );
	if ( ! class_exists( 'WC_Shipping_Method' ) ) {
		class WC_Shipping_Method { public $id = ''; public function __construct( $instance_id = 0 ) {} public function init_settings() {} public function get_option( $k, $d = null ) { return $d; } }
	}
	$loaded = 0;
	require $plugin_dir . 'mobo-core.php';
	$loaded++;
	$files = array_merge( glob( $inc . '*.php' ), glob( $inc . '*/*.php' ) );
	sort( $files );
	foreach ( $files as $file ) {
		require_once $file;
		$loaded++;
	}
	echo 'LOADED ' . $loaded . "\n";
	echo 'ERRORLOG-BOOTSTRAP ' . ( isset( $GLOBALS['__actions']['shutdown'] ) ? 'wp-shutdown' : 'native' ) . "\n";
	exit( 0 );
}

/* ======================================================================
 * Child: runtime critical paths under the disabled list.
 * ==================================================================== */
if ( '--child-runtime' === $mode ) {
	child_error_guard();
	define( 'MOBO_CORE_VERSION', '10.34.77' );
	define( 'MOBO_CORE_PLUGIN_DIR', $plugin_dir );
	require $inc . 'class-mobo-core-environment.php';
	require $inc . 'class-mobo-core-request-deadline.php';
	require $inc . 'class-mobo-core-correlation.php';
	require $inc . 'class-mobo-core-error-log.php';
	require $inc . 'class-mobo-core-environment-probe.php';
	if ( ! class_exists( 'Mobo_Core_Settings' ) ) {
		class Mobo_Core_Settings {
			public static function get_int( $k, $d, $min = 0, $max = PHP_INT_MAX ) { return (int) $d; }
			public static function enabled( $k, $d = '1' ) { return '1' === (string) $d; }
			public static function get( $k, $d = '' ) { return $d; }
		}
	}
	require $inc . 'class-mobo-core-cron-runner.php';
	$E = 'Mobo_Core_Environment';
	$out = array();
	$out['ini']            = $E::ini( 'max_execution_time', 'fallback' );
	$out['setTimeLimit']   = $E::try_set_time_limit( 30 );
	$out['ignoreAbort']    = $E::ignore_user_abort( true );
	$out['shutdown']       = $E::on_shutdown( 'strlen', 'x' );
	$out['memory']         = $E::memory_usage();
	$out['peak']           = $E::memory_peak();
	$out['env']            = $E::env( 'PATH' );
	$out['iniFile']        = $E::ini_file();
	$out['log']            = $E::log( 'probe' );
	$E::sleep_us( 1000 );
	$out['scandir']        = $E::scandir( __DIR__ );
	$out['resolve4']       = $E::resolve_ipv4( 'example.com' );
	$out['resolveHost']    = $E::resolve_host( 'example.com' );
	$out['randomHex']      = $E::random_hex( 16 );
	$out['randomRaw']      = strlen( $E::random_raw( 16 ) );
	$out['ctype']          = ctype_digit( '123' ) && ! ctype_digit( '12a' ) && ! ctype_digit( '' );
	$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 2.0;
	Mobo_Core_Request_Deadline::reset();
	$out['phpLimit']       = Mobo_Core_Request_Deadline::php_time_limit();
	$out['remaining']      = Mobo_Core_Request_Deadline::remaining();
	$out['migrationBoot']  = Mobo_Core_Request_Deadline::migration_bootstrap_action( false, true );
	$runner = new ReflectionClass( 'Mobo_Core_Cron_Runner' );
	$instance = $runner->newInstanceWithoutConstructor();
	$memory = $runner->getMethod( 'get_memory_limit_bytes' );
	$memory->setAccessible( true );
	$out['cronMemoryLimit'] = $memory->invoke( $instance );
	$sig = Mobo_Core_Error_Log::capture( 'error', 'probe.child', 'child capture' );
	Mobo_Core_Error_Log::on_shutdown();
	$out['errorLogged']    = '' !== $sig && 1 === count( Mobo_Core_Error_Log::recent() );
	$probe = Mobo_Core_Environment_Probe::compute( Mobo_Core_Environment_Probe::collect_inputs() );
	$out['probeDisabled']  = $probe['disabledFunctions'];
	$out['probeImpacts']   = array_map( static function ( $i ) { return $i['key']; }, $probe['impacts'] );
	$out['probeMaxExec']   = $probe['maxExecutionTime'];
	echo 'RESULT ' . json_encode( $out ) . "\n";
	exit( 0 );
}

/* ======================================================================
 * Child (`php -n`): extensions from php.ini are not loaded.
 * ==================================================================== */
if ( '--child-noext' === $mode ) {
	child_error_guard();
	require $inc . 'class-mobo-core-environment.php';
	require $inc . 'class-mobo-core-environment-probe.php';
	if ( ! class_exists( 'Mobo_Core_Settings' ) ) {
		class Mobo_Core_Settings { public static function enabled( $k, $d = '1' ) { return true; } }
	}
	$probe = Mobo_Core_Environment_Probe::compute( Mobo_Core_Environment_Probe::collect_inputs() );
	echo 'RESULT ' . json_encode( array( 'missing' => $probe['missingExtensions'], 'impacts' => array_map( static function ( $i ) { return $i['key']; }, $probe['impacts'] ), 'editor' => $probe['imageEditor'], 'loaded' => get_loaded_extensions() ) ) . "\n";
	exit( 0 );
}

/* ======================================================================
 * Parent.
 * ==================================================================== */
define( 'MOBO_CORE_VERSION', '10.34.77' );
require $inc . 'class-mobo-core-environment.php';
require $inc . 'class-mobo-core-request-deadline.php';
require $inc . 'class-mobo-core-environment-probe.php';
if ( ! class_exists( 'Mobo_Core_Settings' ) ) {
	class Mobo_Core_Settings { public static function enabled( $k, $d = '1' ) { return true; } public static function get_int( $k, $d, $a = 0, $b = 0 ) { return (int) $d; } }
}
$E = 'Mobo_Core_Environment';
echo 'PHP ' . PHP_VERSION . "\n";

echo "\n[1] Mobo_Core_Environment with simulated disabled functions\n";
$E::simulate_disabled( array( 'ini_get', 'set_time_limit', 'ignore_user_abort', 'register_shutdown_function', 'memory_get_usage', 'memory_get_peak_usage', 'getenv', 'php_ini_loaded_file', 'error_log', 'usleep', 'scandir', 'gethostbynamel', 'gethostbyname', 'random_bytes' ) );
t_same( '30x', $E::ini( 'max_execution_time', '30x' ), 'ini() returns the fallback when ini_get is disabled' );
t_same( false, $E::try_set_time_limit( 10 ), 'set_time_limit disabled -> false (deadline helper is used instead)' );
t_same( false, $E::ignore_user_abort( true ), 'ignore_user_abort disabled -> no-op' );
$GLOBALS['__actions'] = array();
t_same( 'wp-shutdown', $E::on_shutdown( 'strlen', 'x' ), 'register_shutdown_function disabled -> WordPress shutdown action fallback' );
t_ok( ! empty( $GLOBALS['__actions']['shutdown'] ), '  shutdown callback registered on the WP hook' );
t_same( 0, $E::memory_usage(), 'memory_get_usage disabled -> 0' );
t_same( false, $E::env( 'PATH' ), 'getenv disabled -> false' );
t_same( '', $E::ini_file(), 'php_ini_loaded_file disabled -> empty' );
t_same( false, $E::log( 'x' ), 'error_log disabled -> false' );
t_same( array(), $E::scandir( __DIR__ ), 'scandir disabled -> empty list' );
t_same( array(), $E::resolve_ipv4( 'example.com' ), 'gethostbynamel disabled -> unresolvable' );
t_same( 'example.com', $E::resolve_host( 'example.com' ), 'gethostbyname disabled -> host unchanged' );
$hex = $E::random_hex( 24 );
t_ok( 48 === strlen( $hex ) && ctype_xdigit( $hex ), 'random_bytes disabled -> random_int fallback hex token (48 chars)' );
t_ok( $hex !== $E::random_hex( 24 ), '  tokens differ' );
$E::simulate_disabled( array( 'random_bytes', 'random_int' ) );
$hex = $E::random_hex( 40 );
t_ok( 80 === strlen( $hex ) && ctype_xdigit( $hex ), 'random_bytes+random_int disabled -> wp_generate_password fallback (80 hex chars)' );
t_same( 16, strlen( $E::random_raw( 16 ) ), 'random_raw() returns 16 raw bytes' );
$E::simulate_disabled( array( 'ini_get' ) );
t_ok( in_array( 'ini_get', $E::disabled_functions(), true ), 'disabled_functions() lists simulated functions' );
Mobo_Core_Request_Deadline::reset();
t_same( 30, Mobo_Core_Request_Deadline::php_time_limit(), 'request deadline assumes 30 s when ini_get is disabled' );
t_ok( Mobo_Core_Request_Deadline::remaining() > 0 && Mobo_Core_Request_Deadline::remaining() <= 24.01, '  deadline budget stays within 80% of 30 s' );
$E::simulate_disabled( array() );
t_ok( ctype_digit( '0042' ) && ! ctype_digit( '4.2' ) && ! ctype_digit( '' ), 'ctype_digit available (native or polyfill)' );

echo "\n[2] capability probe classification\n";
$P = 'Mobo_Core_Environment_Probe';
$all_on = array();
foreach ( array_keys( $P::relevant_functions() ) as $fn ) { $all_on[ $fn ] = true; }
$exts_on = array();
foreach ( array_keys( $P::relevant_extensions() ) as $ext ) { $exts_on[ $ext ] = true; }
$base = array( 'functions' => $all_on, 'extensions' => $exts_on, 'imagickClass' => true, 'openBasedir' => '', 'maxExecutionTime' => '30', 'memoryLimit' => '256M', 'sapi' => 'fpm-fcgi', 'phpVersion' => '8.3.14', 'httpSupportsSsl' => true, 'loopbackEnabled' => true, 'loopbackAttemptAt' => 100, 'loopbackSuccessAt' => 100, 'now' => 1000 );
$r = $P::compute( $base );
t_same( array(), $r['impacts'], 'healthy host -> no readiness impacts' );
t_same( 'ok', $r['loopback'], '  loopback ok' );
$in = $base;
foreach ( array( 'exec', 'shell_exec', 'proc_open', 'flock', 'putenv', 'getmypid', 'ignore_user_abort', 'random_bytes', 'usleep', 'error_log' ) as $fn ) { $in['functions'][ $fn ] = false; }
$r = $P::compute( $in );
t_ok( 10 === count( $r['disabledFunctions'] ), 'disabled functions without feature impact are listed in capabilities' );
t_same( array(), $r['impacts'], '  ... but produce no readiness item (complete fallbacks)' );
$in = $base;
$in['functions']['set_time_limit'] = false;
$in['functions']['scandir'] = false;
$in['functions']['register_shutdown_function'] = false;
$in['functions']['gethostbynamel'] = false;
$in['functions']['dns_get_record'] = false;
$in['extensions']['mbstring'] = false;
$in['extensions']['gd'] = false;
$in['extensions']['imagick'] = false;
$in['openBasedir'] = '/home/u/public_html:/tmp';
$in['uploadsWritable'] = false;
$in['maxExecutionTime'] = '10';
$in['memoryLimit'] = '64M';
$in['httpSupportsSsl'] = false;
$r = $P::compute( $in );
$keys = array_map( static function ( $i ) { return $i['key']; }, $r['impacts'] );
foreach ( array( 'fn_set_time_limit', 'fn_scandir', 'fn_register_shutdown_function', 'fn_dns', 'ext_mbstring', 'image_editor', 'open_basedir', 'max_execution_time', 'memory_limit', 'http_transport' ) as $k ) {
	t_ok( in_array( $k, $keys, true ), "impact reported: {$k}" );
}
t_same( 'none', $r['imageEditor'], 'no GD/Imagick -> imageEditor none' );
t_ok( true === $r['openBasedir'] && 0 === count( array_filter( $r['openBasedirPaths'], static function ( $p ) { return false !== strpos( $p, '/home/u/public_html' ); } ) ), 'open_basedir reported without full server paths' );
$items = $P::readiness_items( $r );
t_same( count( $r['impacts'] ), count( $items ), 'one readiness item per impact' );
$persian = true;
foreach ( $items as $item ) {
	$persian = $persian && false === $item['ok'] && 0 === strpos( $item['key'], 'env_' ) && preg_match( '/[\x{0600}-\x{06FF}]/u', $item['message'] );
}
t_ok( $persian, 'readiness items are not-ok, env_-prefixed and carry a Persian impact message' );
$summary = $P::impact_summary( $r );
t_ok( isset( $summary[0]['key'], $summary[0]['feature'], $summary[0]['severity'] ) && ! isset( $summary[0]['message'] ), 'impact_summary() is compact (no messages)' );
$in = $base;
$in['extensions']['gd'] = false;
t_ok( 'imagick' === $P::compute( $in )['imageEditor'] && array() === $P::compute( $in )['impacts'], 'GD missing but Imagick present -> no image impact' );
$in = $base;
$in['functions']['openssl_sign'] = false;
$in['extensions']['openssl'] = false;
$r = $P::compute( $in );
$keys = array_map( static function ( $i ) { return $i['key']; }, $r['impacts'] );
t_ok( in_array( 'fn_openssl_sign', $keys, true ) && ! in_array( 'ext_openssl', $keys, true ), 'OpenSSL missing is reported once (license signing impact)' );
t_same( 'critical', $r['impacts'][0]['severity'], '  license impact is critical' );
t_same( 268435456, $P::parse_bytes( '256M' ), 'parse_bytes(256M)' );
t_same( -1, $P::parse_bytes( '-1' ), 'parse_bytes(-1)' );
$GLOBALS['__opts'] = array();
$first = $P::get();
$GLOBALS['__opts'][ $P::CACHE_OPTION ]['report']['marker'] = 'cached';
t_same( 'cached', $P::get()['marker'] ?? '', 'probe is cached (daily) in a non-autoloaded option' );
t_ok( ! isset( $P::get( true )['marker'] ), '  force recomputes' );

echo "\n[3] real disable_functions subprocesses (" . PHP_BINARY . ")\n";
/**
 * Run a PHP child with flags; returns [exitCode, output].
 */
function run_php( $flags, $script, $args = '' ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . $flags . ' ' . escapeshellarg( $script ) . ( '' !== $args ? ' ' . $args : '' ) . ' 2>&1';
	$lines = array();
	$code  = 0;
	exec( $cmd, $lines, $code );
	return array( $code, implode( "\n", $lines ) );
}
function disabled_flag( $except = array() ) {
	$list = array_diff( explode( ',', MOBO_DISABLED_ALL ), $except );
	return '-d disable_functions=' . implode( ',', $list );
}
function no_disabled_calls( $output ) {
	return false === stripos( $output, 'has been disabled' ) && false === stripos( $output, 'DISABLED-CALL' ) && false === stripos( $output, 'Call to undefined function' );
}

/* Lint smoke. */
$all_files = array_merge( array( $plugin_dir . 'mobo-core.php', $plugin_dir . 'mobo-cron.php', $plugin_dir . 'uninstall.php' ), glob( $inc . '*.php' ), glob( $inc . '*/*.php' ) );
$lint_fail = array();
foreach ( $all_files as $file ) {
	list( $code, $output ) = run_php( disabled_flag() . ' -l', $file );
	if ( 0 !== $code || false === strpos( $output, 'No syntax errors' ) ) {
		$lint_fail[] = basename( $file );
	}
}
t_ok( empty( $lint_fail ), 'php -d disable_functions=... -l passes for all ' . count( $all_files ) . ' plugin files' . ( $lint_fail ? ' (' . implode( ', ', $lint_fail ) . ')' : '' ) );

/* Load smoke. */
list( $code, $output ) = run_php( disabled_flag(), __FILE__, '--child-load' );
preg_match( '/LOADED (\d+)/', $output, $m );
t_ok( 0 === $code && isset( $m[1] ) && (int) $m[1] >= count( $all_files ) - 2 && no_disabled_calls( $output ), 'every plugin file loads with the disabled list (no load-time fatal/warning)' . ( 0 !== $code || ! isset( $m[1] ) ? "\n" . substr( $output, 0, 1500 ) : '' ) );
t_ok( false !== strpos( $output, 'ERRORLOG-BOOTSTRAP wp-shutdown' ), '  error capture falls back to the WordPress shutdown hook' );

/* Runtime child. */
list( $code, $output ) = run_php( disabled_flag(), __FILE__, '--child-runtime' );
$res = preg_match( '/RESULT (\{.*\})/', $output, $m ) ? json_decode( $m[1], true ) : null;
t_ok( 0 === $code && is_array( $res ) && no_disabled_calls( $output ), 'runtime child completes with everything disabled' . ( is_array( $res ) ? '' : "\n" . substr( $output, 0, 1500 ) ) );
if ( is_array( $res ) ) {
	t_same( 'fallback', $res['ini'], '  ini_get disabled -> fallback value' );
	t_same( false, $res['setTimeLimit'], '  set_time_limit disabled -> false' );
	t_same( false, $res['ignoreAbort'], '  ignore_user_abort disabled -> false' );
	t_same( 'wp-shutdown', $res['shutdown'], '  register_shutdown_function disabled -> WP shutdown hook' );
	t_same( 0, $res['memory'], '  memory_get_usage disabled -> 0' );
	t_same( false, $res['env'], '  getenv disabled -> false' );
	t_same( array(), $res['scandir'], '  scandir disabled -> empty list' );
	t_same( 'example.com', $res['resolveHost'], '  DNS disabled -> host unchanged' );
	t_ok( 32 === strlen( $res['randomHex'] ) && 16 === $res['randomRaw'], '  random_bytes disabled -> tokens still generated' );
	t_same( true, $res['ctype'], '  ctype_digit works' );
	t_same( 30, $res['phpLimit'], '  request deadline: unknown limit -> 30 s' );
	t_ok( $res['remaining'] > 20 && $res['remaining'] <= 22.01, '  request deadline: 2 s spent of a 24 s budget' );
	t_same( 'none', $res['migrationBoot'], '  migration bootstrap decision unaffected' );
	t_same( 0, $res['cronMemoryLimit'], '  cron runner memory guard: unknown memory_limit -> unlimited (0), no fatal' );
	t_same( true, $res['errorLogged'], '  error capture + shutdown flush work' );
	/* set_time_limit is not asserted: Xdebug keeps it visible to function_exists()
	 * on PHP 7.4 and, with ini_get disabled too, nothing can tell it is disabled
	 * (the wrapper still degrades safely: @set_time_limit returns false). */
	foreach ( array( 'ini_get', 'register_shutdown_function', 'scandir', 'getenv' ) as $fn ) {
		t_ok( in_array( $fn, $res['probeDisabled'], true ), "  probe sees {$fn} disabled" );
	}
	t_ok( in_array( 'fn_scandir', $res['probeImpacts'], true ) && in_array( 'fn_ini_get', $res['probeImpacts'], true ) && in_array( 'fn_dns', $res['probeImpacts'], true ), '  probe reports the feature impacts' );
}

/* Critical existing suites re-run with the functions disabled. */
$suites = array(
	'runtime-deadline-test.php'      => array( 'ini_set', 'shell_exec', 'proc_open', 'exec' ),
	'migration-hardening-test.php'   => array( 'ini_set' ),
	'job-watchdog-test.php'          => array(),
	'image-duplicate-guard-test.php' => array(),
	'image-efficiency-test.php'      => array(),
	'image-refresh-stop-test.php'    => array(),
	'legacy-image-cleanup-test.php'  => array( 'getmypid' ), // the harness itself names its temp dir with getmypid().
	'cache-integration-test.php'     => array(),
);
foreach ( $suites as $suite => $except ) {
	list( $code, $output ) = run_php( disabled_flag( $except ), __DIR__ . '/' . $suite );
	$fails = preg_match_all( '/^\s*FAIL/m', $output );
	t_ok( 0 === $code && 0 === $fails && no_disabled_calls( $output ), "suite {$suite} passes with disable_functions" . ( 0 === $code && 0 === $fails ? '' : "\n" . substr( $output, -1200 ) ) );
}

echo "\n[4] php -n: php.ini extensions absent\n";
list( $code, $output ) = run_php( '-n', __FILE__, '--child-noext' );
$res = preg_match( '/RESULT (\{.*\})/', $output, $m ) ? json_decode( $m[1], true ) : null;
if ( ! is_array( $res ) ) {
	t_ok( false, 'no-extension child ran' . "\n" . substr( $output, 0, 800 ) );
} else {
	$loaded = array_map( 'strtolower', $res['loaded'] );
	$expect = array();
	foreach ( array( 'mbstring', 'intl', 'openssl', 'gd', 'curl' ) as $ext ) {
		if ( ! in_array( $ext, $loaded, true ) ) {
			$expect[] = $ext;
		}
	}
	t_ok( ! empty( $expect ), 'child runs without php.ini extensions (' . implode( ',', $expect ) . ')' );
	t_same( array(), array_values( array_diff( $expect, $res['missing'] ) ), '  probe lists every missing extension' );
	if ( in_array( 'gd', $expect, true ) ) {
		t_ok( in_array( 'image_editor', $res['impacts'], true ) && 'none' === $res['editor'], '  image editor impact reported' );
	}
	if ( in_array( 'mbstring', $expect, true ) ) {
		t_ok( in_array( 'ext_mbstring', $res['impacts'], true ), '  mbstring impact reported' );
	}
}

t_done();
