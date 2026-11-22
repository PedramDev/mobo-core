<?php
/**
 * Standalone developer test (no WordPress, no network) for the self-healing
 * Reprice / pricing-override flows:
 *  - Mobo_Core_Job_Diagnostics (pure watchdog decision, Persian status line,
 *    bounded ring buffer, secret/PII filter, support bundle);
 *  - Mobo_Core_Reprice_Queue::watchdog_tick() recovery + "never loop forever"
 *    (poison item skip, give up -> stalled, admin skip/resume), B10 switches;
 *  - B27 product busy heartbeat (updatedAt = 0 is stale, not busy);
 *  - B9 pricing override crash guard / sweeper / bounded attempts.
 *
 * Run: php tests-dev/job-watchdog-test.php
 * PHP 7.4 compatible.
 */

require __DIR__ . '/pricing-test-stubs.php';

final class Mobo_Core_Lock {
	public static $status = array( 'active' => false, 'lastHeartbeatAt' => 0, 'expiresAt' => 0 );
	public static $recovered = 0;
	public static function acquire( $name, $ttl = 30 ) { return 'token'; }
	public static function release( $name, $token ) { return true; }
	public static function get_status( $name ) { return self::$status; }
	public static function recover_stale_locks( $names = array() ) { self::$recovered++; return array( 'recovered' => 1 ); }
}

require_once $t_inc . 'class-mobo-core-product-concurrency.php';
require_once $t_inc . 'class-mobo-core-product-pricing-policy.php';
require_once $t_inc . 'class-mobo-core-reprice-queue.php';

$now = time();
$stall = 15 * 60;

echo "Job diagnostics: pure watchdog decision\n";
$d = Mobo_Core_Job_Diagnostics::diagnose( array( 'status' => 'running', 'lastProgressAt' => $now - 120 ), array(), $now, $stall );
t_same( 'ok', $d['health'], 'progress 2 minutes ago => healthy' );
$d = Mobo_Core_Job_Diagnostics::diagnose( array( 'status' => 'done' ), array(), $now, $stall );
t_same( 'idle', $d['health'], 'finished job => idle' );
$base = array( 'status' => 'running', 'lastProgressAt' => $now - 1200 );
$fresh_cron = array( 'cronLastHitAt' => $now - 30 );
$d = Mobo_Core_Job_Diagnostics::diagnose( $base, array_merge( $fresh_cron, array( 'failingItem' => 55, 'failingAttempts' => 2 ) ), $now, $stall );
t_check( 'stalled' === $d['health'] && 'poison_item' === $d['reason'] && 'skip_item' === $d['action'] && 55 === $d['itemId'], 'repeatedly failing item 55 => skip poison item' );
$d = Mobo_Core_Job_Diagnostics::diagnose( $base, array_merge( $fresh_cron, array( 'blockedItem' => 77, 'blockedCount' => 12, 'blockedLimit' => 20 ) ), $now, $stall );
t_check( 'item_blocked' === $d['reason'] && 77 === $d['itemId'], 'product busy for many slices => item_blocked' );
$d = Mobo_Core_Job_Diagnostics::diagnose( $base, array_merge( $fresh_cron, array( 'budgetZeroStreak' => 3 ) ), $now, $stall );
t_check( 'budget_exhausted' === $d['reason'] && 'shrink_batch' === $d['action'], 'budget exhausted every slice => shrink batch' );
$d = Mobo_Core_Job_Diagnostics::diagnose( $base, array( 'cronLastHitAt' => $now - 1800 ), $now, $stall );
t_check( 'cron_not_firing' === $d['reason'], 'cron silent for 30 minutes => cron_not_firing' );
$d = Mobo_Core_Job_Diagnostics::diagnose( $base, array_merge( $fresh_cron, array( 'lockActive' => true, 'lockHeartbeatAt' => $now - 3600, 'lockExpiresAt' => $now + 99999 ) ), $now, $stall );
t_check( 'stale_lock' === $d['reason'] && 'release_lock' === $d['action'], 'lock held by a dead process => release lock' );
$d = Mobo_Core_Job_Diagnostics::diagnose( $base, array( 'waitingBackend' => true ), $now, $stall );
t_check( 'waiting' === $d['health'] && 'waiting_backend' === $d['reason'], 'waiting on Backend is not "stalled"' );
$d = Mobo_Core_Job_Diagnostics::diagnose( $base, array_merge( $fresh_cron, array( 'recoveries' => array( 'no_progress_unknown' => 5 ) ) ), $now, $stall );
t_check( ! empty( $d['giveUp'] ), 'after 5 automatic recoveries without progress => give up (no infinite loop)' );

echo "\nPersian status line\n";
t_same( 'در حال اجرا – ۴۵٪ – آخرین پیشرفت ۲ دقیقه پیش', Mobo_Core_Job_Diagnostics::status_line_fa( 'running', 45.6, array( 'health' => 'ok', 'secondsSinceProgress' => 125 ) ), 'running line' );
$line = Mobo_Core_Job_Diagnostics::status_line_fa( 'running', 45, array( 'health' => 'stalled', 'reason' => 'poison_item', 'itemId' => 55 ) );
t_check( 0 === strpos( $line, 'گیر کرده: ' ) && false !== strpos( $line, '55' ), 'stalled line names the reason and item' );

echo "\nBounded buffers and secret filter\n";
$diag = Mobo_Core_Job_Diagnostics::from_array( 'test', array() );
for ( $i = 0; $i < 250; $i++ ) { $diag->event( 'tick', 'event ' . $i ); }
for ( $i = 1; $i <= 60; $i++ ) { $diag->poison( $i, 'bad' ); }
$data = $diag->data();
t_same( 200, count( $data['events'] ), 'event ring buffer keeps the last 200' );
t_same( 50, count( $data['poisonItems'] ), 'poison list bounded to 50' );
$clean = Mobo_Core_Job_Diagnostics::sanitize_context( array( 'itemId' => 5, 'licenseToken' => 'x', 'password' => 'p', 'billing_email' => 'a@b', 'mobile' => '0912', 'nested' => array( 'api_key' => 'k', 'ok' => 'y' ) ) );
t_same( array( 'itemId' => 5, 'nested' => array( 'ok' => 'y' ) ), $clean, 'tokens, passwords, emails, phones and keys are dropped' );
try {
	throw new RuntimeException( 'boom' );
} catch ( RuntimeException $e ) {
	$diag->error( '', 42, $e );
}
$data = $diag->data();
t_check( 'RuntimeException' === $data['lastError']['exceptionClass'] && 42 === $data['lastError']['itemId'] && $data['lastError']['line'] > 0 && 'boom' === $data['lastError']['message'], 'last error keeps class, item, message and file:line' );

echo "\nReprice watchdog: skip poison item, then give up -> stalled\n";
t_reset();
update_option( 'mobo_core_real_cron_last_hit_at', $now - 20 );
$queue = new Mobo_Core_Reprice_Queue();
$state = array(
	'status' => 'running', 'lastPostId' => 40, 'processed' => 10, 'total' => 100, 'startedAt' => $now - 3600, 'updatedAt' => $now - 1200,
	'lastProgressAt' => $now - 1200, 'failureAttempts' => array( '55' => 2 ), 'pendingParentIds' => array(),
);
// Keep the generation valid for the current policy.
$fp = new ReflectionMethod( 'Mobo_Core_Reprice_Queue', 'current_policy_fingerprint' );
$fp->setAccessible( true );
$state['policyFingerprint'] = $fp->invoke( $queue );
Mobo_Core_State_Store::set( Mobo_Core_Reprice_Queue::STATE_OPTION, $state );
$result = $queue->watchdog_tick( 'test' );
t_same( 'skipped-item', $result['performed'], 'watchdog skipped the failing item' );
$after = Mobo_Core_State_Store::get( Mobo_Core_Reprice_Queue::STATE_OPTION );
t_check( 55 === $after['lastPostId'] && ! isset( $after['failureAttempts']['55'] ), 'cursor advanced past item 55' );
$diag = Mobo_Core_Job_Diagnostics::open( 'reprice' )->data();
t_same( 55, $diag['poisonItems'][0]['itemId'], 'item 55 recorded as poison with reason' );
$status = $queue->get_status();
t_same( 'ok', $status['health'], 'progress timestamp refreshed => healthy again' );

// No progress again and no identifiable cause: bounded nudges, then stalled.
$after['lastProgressAt'] = $now - 1200;
Mobo_Core_State_Store::set( Mobo_Core_Reprice_Queue::STATE_OPTION, $after );
$performed = array();
for ( $i = 0; $i < 8; $i++ ) {
	$r = $queue->watchdog_tick( 'test' );
	$performed[] = $r['performed'];
	if ( 'marked-stalled' === $r['performed'] ) { break; }
}
t_check( in_array( 'marked-stalled', $performed, true ) && count( $performed ) <= 6, 'gives up after at most 5 nudges: ' . implode( ',', $performed ) );
$status = $queue->get_status();
t_same( 'stalled', $status['status'], 'state is stalled with a reason' );
t_check( 0 === strpos( $status['statusLine'], 'گیر کرده' ), 'admin status line: ' . $status['statusLine'] );
t_same( 'idle', $queue->watchdog_tick( 'test' )['health'], 'stalled job is not touched again by cron (no loop)' );
$health = $queue->health_payload();
t_check( 'stalled' === $health['status'] && '' !== $health['stalledReason'] && isset( $health['progressPct'] ), 'health jobs.reprice exposes status/progress/stalledReason' );
$bundle = $queue->debug_bundle();
$json = wp_json_encode( $bundle );
t_check( is_string( $json ) && false !== strpos( $json, '"job":"reprice"' ) && false === stripos( $json, 'password' ), 'debug bundle is JSON without secrets' );

// Admin skip on a stalled run with a blocked product resumes it.
$stalled = Mobo_Core_State_Store::get( Mobo_Core_Reprice_Queue::STATE_OPTION );
$stalled['blockedItem'] = 88;
$stalled['blockedCount'] = 7;
Mobo_Core_State_Store::set( Mobo_Core_Reprice_Queue::STATE_OPTION, $stalled );
$skip = $queue->skip_problem_item();
$resumed = Mobo_Core_State_Store::get( Mobo_Core_Reprice_Queue::STATE_OPTION );
t_check( ! empty( $skip['success'] ) && 'running' === $resumed['status'] && 88 === $resumed['lastPostId'], '«رد کردن مورد مشکل‌دار» skips item 88 and resumes' );

echo "\nB10 Reprice honours the price update switches\n";
t_reset();
$queue = new Mobo_Core_Reprice_Queue();
t_check( $queue->price_updates_enabled(), 'defaults: price updates on' );
t_setting( 'global_product_auto_price', '0' );
t_setting( 'global_product_auto_compare_price', '0' );
$queue = new Mobo_Core_Reprice_Queue();
t_check( ! $queue->price_updates_enabled(), 'both switches off => Reprice leaves manual prices alone' );
t_setting( 'global_product_auto_compare_price', '1' );
$queue = new Mobo_Core_Reprice_Queue();
t_check( $queue->price_updates_enabled(), 'same OR semantics as Product Sync' );

echo "\nB27 manual sync heartbeat\n";
t_check( ! Mobo_Core_Product_Concurrency::sync_heartbeat_is_fresh( 0, $now ), 'updatedAt = 0 is stale (was busy forever)' );
t_check( Mobo_Core_Product_Concurrency::sync_heartbeat_is_fresh( $now - 10, $now ), 'recent heartbeat is busy' );
t_check( ! Mobo_Core_Product_Concurrency::sync_heartbeat_is_fresh( $now - 7200, $now ), 'two hours old is stale' );
Mobo_Core_State_Store::set( 'sync_state', array( 'status' => 'running', 'currentProductGuid' => 'g-1', 'updatedAt' => 0 ) );
t_check( ! Mobo_Core_Product_Concurrency::is_manual_sync_busy_for_product( 'g-1' ), 'product with updatedAt=0 is no longer reported busy to Reprice' );
Mobo_Core_State_Store::set( 'sync_state', array( 'status' => 'running', 'currentProductGuid' => 'g-1', 'updatedAt' => $now - 5 ) );
t_check( Mobo_Core_Product_Concurrency::is_manual_sync_busy_for_product( 'g-1' ), 'live sync still blocks the product' );

echo "\nB9 pricing override: crash guard, sweeper, bounded attempts\n";
t_reset();
Mobo_Core_Product_Pricing_Policy::schedule_retry( 10, 1, Mobo_Core_Product_Pricing_Policy::CRASH_GUARD_DELAY );
t_check( 1 === count( $GLOBALS['t_scheduled'] ) && $GLOBALS['t_scheduled'][0]['ts'] >= time() + 299 && array( 10, 1 ) === $GLOBALS['t_scheduled'][0]['args'], 'crash-guard retry scheduled +5 minutes with owner Connection' );
t_reset();
$GLOBALS['wpdb']->pending_meta_ids = array( '10', '11', '12' );
$GLOBALS['t_meta'][11][ Mobo_Core_Product_Pricing_Policy::META_ATTEMPTS ] = (string) Mobo_Core_Product_Pricing_Policy::MAX_ATTEMPTS;
wp_schedule_single_event( time() + 60, Mobo_Core_Product_Pricing_Policy::ACTION_RETRY, array( 12, 1 ) );
$sweep = Mobo_Core_Product_Pricing_Policy::sweep_stuck_requests( 20 );
t_same( 1, $sweep['rescheduled'], 'orphaned pending request (killed PHP) is re-scheduled' );
t_same( 1, $sweep['exhausted'], 'request that hit the attempt limit is left for the admin' );
t_check( false !== wp_next_scheduled( Mobo_Core_Product_Pricing_Policy::ACTION_RETRY, array( 10, 1 ) ), 'product 10 now has a retry' );
t_same( 'skipped-not-due', Mobo_Core_Product_Pricing_Policy::sweep_stuck_requests( 20 )['status'], 'sweeper is rate limited' );
$events = Mobo_Core_Job_Diagnostics::open( Mobo_Core_Product_Pricing_Policy::DIAG_JOB )->data();
t_check( ! empty( $events['events'] ) && 'rescheduled' === $events['events'][0]['type'], 'sweeper action recorded in diagnostics' );

echo "\nDISABLE_WP_CRON: the Mobo runner drives overdue pricing override retries\n";
t_reset();
$GLOBALS['wpdb']->pending_meta_ids = array( '13', '14', '15', '16' );
foreach ( array( 13, 14, 15 ) as $overdue_id ) {
	wp_schedule_single_event( time() - 600, Mobo_Core_Product_Pricing_Policy::ACTION_RETRY, array( $overdue_id, 1 ) );
}
wp_schedule_single_event( time() - 30, Mobo_Core_Product_Pricing_Policy::ACTION_RETRY, array( 16, 1 ) );
$sweep = Mobo_Core_Product_Pricing_Policy::sweep_stuck_requests( 20, true );
t_same( Mobo_Core_Product_Pricing_Policy::RUNNER_DRIVE_PER_SWEEP, $sweep['driven'], 'overdue WP-Cron retries are applied inline by the runner (bounded per sweep)' );
t_same( false, wp_next_scheduled( Mobo_Core_Product_Pricing_Policy::ACTION_RETRY, array( 13, 1 ) ), '  the driven retry is removed from WP-Cron (no double apply)' );
t_check( false !== wp_next_scheduled( Mobo_Core_Product_Pricing_Policy::ACTION_RETRY, array( 15, 1 ) ), '  work beyond the per-sweep bound stays scheduled for the next sweep' );
t_check( false !== wp_next_scheduled( Mobo_Core_Product_Pricing_Policy::ACTION_RETRY, array( 16, 1 ) ), '  a retry inside the grace window is left to WP-Cron/Action Scheduler' );
$events = Mobo_Core_Job_Diagnostics::open( Mobo_Core_Product_Pricing_Policy::DIAG_JOB )->data();
$driven_logged = false;
foreach ( (array) ( $events['events'] ?? array() ) as $event ) { $driven_logged = $driven_logged || 'runner-driven' === ( $event['type'] ?? '' ); }
t_check( $driven_logged, '  runner-driven retry recorded in diagnostics' );

/* envA T14 (B5): after the entity-meta retirement the pending generation lives in
 * Custom Storage; the sweeper must probe wp_mobo_entity_meta, not wp_postmeta. */
if ( ! class_exists( 'Mobo_Core_Storage_Entity_Meta_Retirement' ) ) {
	final class Mobo_Core_Storage_Entity_Meta_Retirement {
		public static $authoritative = true;
		public static function is_authoritative() { return self::$authoritative; }
		public static function is_managed_key( $k ) { return 0 === strpos( (string) $k, '_mobo_' ); }
		public static function table_name() { return 'wp_mobo_entity_meta'; }
	}
}
$q = Mobo_Core_Product_Pricing_Policy::pending_candidates_sql( 20 );
t_check( false !== strpos( $q['q'], 'FROM wp_mobo_entity_meta' ), 'retired storage: sweeper probes wp_mobo_entity_meta' );
Mobo_Core_Storage_Entity_Meta_Retirement::$authoritative = false;
$q = Mobo_Core_Product_Pricing_Policy::pending_candidates_sql( 20 );
t_check( false !== strpos( $q['q'], 'FROM wp_postmeta' ), 'legacy storage: sweeper still probes wp_postmeta' );
Mobo_Core_Storage_Entity_Meta_Retirement::$authoritative = true;
$GLOBALS['wpdb']->pending_meta_ids   = array();
$GLOBALS['wpdb']->entity_pending_ids = array( '21' );
$sweep = Mobo_Core_Product_Pricing_Policy::sweep_stuck_requests( 20, true );
t_same( 1, $sweep['pending'] ?? null, 'retired storage: pending generation found (was 0 -> never retried)' );

t_done();
