/**
 * Site health tab: retry_failed_webhooks A:10590, php_diagnostics A:4070,
 * database unicode migration start/resume/pause A:3976/4016/4046.
 */
import { test, expect } from '../lib/fixtures';
import { actionForm, clickAndReadNotice, gotoTab, note, requireRendered, reveal, toAsciiDigits } from '../lib/admin';
import { columnExists, count, defaultConnectionId, getOption, lit, serializedField, t, tableExists } from '../lib/db';


function failedWebhooks(): number {
  if (!tableExists('mobo_sync_events')) return 0;
  const scope = columnExists('mobo_sync_events', 'connection_id') ? `connection_id = ${lit(defaultConnectionId())} AND ` : '';
  return count(`SELECT COUNT(*) FROM ${t('mobo_sync_events')} WHERE ${scope}status = 'failed'`);
}

/**
 * Failed rows that retry_failed_events() retired as superseded (status done,
 * progress_json.superseded). -1 when the column is unavailable.
 */
function supersededWebhooks(): number {
  if (!tableExists('mobo_sync_events') || !columnExists('mobo_sync_events', 'progress_json')) return -1;
  const scope = columnExists('mobo_sync_events', 'connection_id') ? `connection_id = ${lit(defaultConnectionId())} AND ` : '';
  return count(`SELECT COUNT(*) FROM ${t('mobo_sync_events')} WHERE ${scope}status = 'done' AND progress_json LIKE '%"superseded":true%'`);
}

const migrationStatus = () => serializedField(getOption('mobo_core_database_unicode_migration_state'), 'status');

test.describe('Site health tab', () => {
  test('"برگرداندن وب‌هوک‌های ناموفق به صف" re-queues failed webhook events', async ({ page }, testInfo) => {
    await gotoTab(page, 'health');
    const before = failedWebhooks();
    const supersededBefore = supersededWebhooks();
    const btn = actionForm(page, 'mobo_core_retry_failed_webhooks').locator('button[type="submit"]');
    await expect(btn).toHaveText('برگرداندن وب‌هوک‌های ناموفق به صف');
    const n = await clickAndReadNotice(page, btn);
    expect(n.tab).toBe('health');
    const m = toAsciiDigits(n.message).match(/(\d+) وب‌هوک failed دوباره به صف برگشت/);
    expect(m, n.message).not.toBeNull();
    const requeued = Number(m![1]);
    // 10.34.77 retires failed rows already superseded by a newer event (status done,
    // progress_json.superseded) and re-arms only the rest; the notice counts re-armed rows.
    const considered = Math.min(before, 1000);
    expect(requeued, 'never more than the failed rows considered').toBeLessThanOrEqual(considered);
    expect(requeued).toBeGreaterThanOrEqual(0);
    expect(n.type).toBe(requeued > 0 ? 'success' : 'warning');
    const after = failedWebhooks();
    const retired = before - requeued - after;
    note(testInfo, `failed before=${before}, re-armed=${requeued}, retired as superseded=${retired}, failed after=${after}`);
    expect(after, 're-armed rows left the failed state').toBeLessThanOrEqual(before - requeued);
    expect(requeued + retired, 'only considered rows changed').toBeLessThanOrEqual(considered);
    if (supersededBefore >= 0) {
      expect(supersededWebhooks() - supersededBefore, 'every other row that left failed was retired as superseded').toBeGreaterThanOrEqual(retired);
    }
  });

  test('"مشاهده جزئیات امن محیط PHP" opens the bounded diagnostics page', async ({ page, context }) => {
    await gotoTab(page, 'health');
    const link = page.locator('a', { hasText: 'مشاهده جزئیات امن محیط PHP' }).first();
    await reveal(link);
    const [popup] = await Promise.all([context.waitForEvent('page'), link.click()]);
    await popup.waitForLoadState('domcontentloaded');
    await expect(popup).toHaveTitle('جزئیات محیط PHP - موبو');
    await expect(popup.locator('h1')).toHaveText('جزئیات امن محیط PHP');
    const versionRow = popup.locator('tr', { has: popup.locator('th', { hasText: 'Mobo Core Version' }) });
    await expect(versionRow.locator('td')).toHaveText(/\d+\.\d+\.\d+/);
    await expect(popup.locator('tr', { has: popup.locator('th', { hasText: 'PHP Version' }) }).locator('td')).toHaveText(/\d+\.\d+/);
    // Must not leak phpinfo()/environment details.
    await expect(popup.locator('body')).not.toContainText(/DOCUMENT_ROOT|SCRIPT_FILENAME|DB_PASSWORD|HTTP_COOKIE/);
    await popup.close();
  });

  test('php diagnostics endpoint rejects a request without a valid nonce', async ({ page }) => {
    const res = await page.request.get('/wp-admin/admin-post.php?action=mobo_core_php_diagnostics&_wpnonce=invalid', { maxRedirects: 0 });
    expect([403, 200]).toContain(res.status());
    expect(await res.text()).not.toContain('PHP Extensions');
  });

  test('"پیش‌بررسی دیتابیس" then "شروع تبدیل دستی utf8mb4" starts the Unicode migration', async ({ page }) => {
    test.setTimeout(600_000);
    await gotoTab(page, 'health');
    const precheck = actionForm(page, 'mobo_core_precheck_database_unicode_migration').locator('button[type="submit"]');
    await requireRendered(precheck, 'database already utf8mb4-compatible ("نیازی به Migration نیست") or a migration is already active');
    const p = await clickAndReadNotice(page, precheck, 120_000);
    expect(p.type, p.message).toBe('success');
    const form = actionForm(page, 'mobo_core_start_database_unicode_migration');
    const start = form.locator('button[type="submit"]');
    await requireRendered(start, 'start form not rendered after pre-check');
    const backup = form.locator('input[name="mobo_backup_confirmed"]');
    await reveal(backup); // the utf8mb4 card sits in the "گزارش فنی کامل" <details>
    await backup.check();
    const n = await clickAndReadNotice(page, start, 540_000);
    expect(['success', 'warning'], n.message).toContain(n.type);
    expect(n.message).toMatch(/تبدیل/);
    expect(migrationStatus(), 'migration checkpoint persisted').toBeTruthy();
  });

  test('"توقف (Pause)" pauses an active Unicode migration', async ({ page }) => {
    await gotoTab(page, 'health');
    const pause = actionForm(page, 'mobo_core_pause_database_unicode_migration').locator('button[type="submit"]');
    await requireRendered(pause, 'no active Unicode migration');
    const n = await clickAndReadNotice(page, pause);
    expect(n.type).toBe('warning');
    expect(n.message).toMatch(/Pause|متوقف/);
    expect(['paused', 'ready', 'migrating']).toContain(String(migrationStatus()));
  });

  test('"ادامه از Checkpoint" / "تبدیل جدول بعدی" resumes the Unicode migration', async ({ page }) => {
    test.setTimeout(600_000);
    await gotoTab(page, 'health');
    const resume = actionForm(page, 'mobo_core_resume_database_unicode_migration').locator('button[type="submit"]').first();
    await requireRendered(resume, 'no paused/failed/awaiting-config/active Unicode migration to resume');
    const n = await clickAndReadNotice(page, resume, 540_000);
    expect(['success', 'warning'], n.message).toContain(n.type);
    expect(n.message).toMatch(/Unicode|utf8mb4|wp-config|مرحله|جدول|تبدیل/);
  });
});
