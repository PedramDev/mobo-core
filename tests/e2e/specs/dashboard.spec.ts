/**
 * Dashboard tab: manual sync lifecycle and maintenance tools.
 *   start_sync A:10212, start_repair A:10278, resume_sync A:10305,
 *   cancel_sync A:10332, reset_sync A:10353, sync_categories A:10184,
 *   quarantine_duplicate_products A:10239, rearm_wallet_alert A:9424,
 *   ajax get_sync_status A:9819.
 * Effects are verified in the durable checkpoint (wp_mobo_state.sync_state).
 */
import { Page } from '@playwright/test';
import { test, expect } from '../lib/fixtures';
import { actionForm, cardAjax, clickAndReadNotice, gotoTab, note, postAjax, requireRendered } from '../lib/admin';
import { getOption, getSetting, getState, serializedField } from '../lib/db';
import { mockLog, waitForMockRequests } from '../lib/services';


function syncState(): { status: string | null; syncId: string | null; repair: string | null } {
  const raw = getState('sync_state');
  return { status: serializedField(raw, 'status'), syncId: serializedField(raw, 'syncId'), repair: serializedField(raw, 'repairMode') };
}

function cancelButton(page: Page) {
  return actionForm(page, 'mobo_core_cancel_sync').locator('button[type="submit"]', { hasText: 'توقف همگام‌سازی' });
}

/** Precondition: no running sync (uses the real cancel control when needed). */
async function ensureNotRunning(page: Page): Promise<void> {
  await gotoTab(page, 'dashboard');
  if ((await cancelButton(page).count()) > 0) {
    const n = await clickAndReadNotice(page, cancelButton(page));
    expect(n.type).toBe('success');
  }
}

/** Precondition: a running sync (uses the real start control when needed). */
async function ensureRunning(page: Page): Promise<void> {
  await gotoTab(page, 'dashboard');
  if ((await cancelButton(page).count()) > 0) return;
  const start = actionForm(page, 'mobo_core_start_sync').locator('button[type="submit"]').first();
  await requireRendered(start, 'start sync control (site may be waiting for the Source)');
  const n = await clickAndReadNotice(page, start);
  expect(n.type, n.message).toBe('success');
}

test.describe('Dashboard: product sync controls', () => {
  test('ajax mobo_core_get_sync_status returns the live sync status', async ({ page }) => {
    await gotoTab(page, 'dashboard');
    const { url, nonce } = await cardAjax(page, '#mobo-sync-status-card');
    const ok = await postAjax(page, url, { action: 'mobo_core_get_sync_status', nonce });
    expect(ok.status).toBe(200);
    expect(ok.json?.success).toBe(true);
    expect(ok.json?.data).toHaveProperty('isRunning');
    const stateStatus = syncState().status;
    if (stateStatus === 'running') expect(ok.json.data.isRunning).toBeTruthy();

    const bad = await postAjax(page, url, { action: 'mobo_core_get_sync_status', nonce: 'invalid' });
    expect(bad.status, 'bad nonce is rejected').toBe(403);
  });

  test('"شروع همگام‌سازی محصولات" starts a new manual sync generation', async ({ page }) => {
    await ensureNotRunning(page);
    const before = syncState();
    const start = actionForm(page, 'mobo_core_start_sync').locator('button[type="submit"]').first();
    await requireRendered(start, 'start sync control (sync waiting for the Source; resume is offered instead)');

    const n = await clickAndReadNotice(page, start);
    expect(n.type, n.message).toBe('success');
    expect(n.message).toContain('همگام‌سازی محصولات شروع شد');
    expect(n.tab).toBe('dashboard');

    const after = syncState();
    expect(after.syncId, 'a new sync generation id was persisted').toBeTruthy();
    expect(after.syncId).not.toBe(before.syncId);
    expect(['running', 'completed', 'waiting_for_portal']).toContain(after.status);
    expect(after.repair).toBe('0');
    await expect(page.locator('[data-mobo-sync-hero-status]')).toBeVisible();
  });

  test('running sync reaches the mock Source through the Backend', { tag: '@backend' }, async ({ page }, testInfo) => {
    const baseline = await mockLog();
    test.skip(baseline === null, 'mock Source /__control/log not reachable');
    await ensureRunning(page);
    // The dashboard's own status poll advances a bounded burst when the loopback runner is blocked.
    const { url, nonce } = await cardAjax(page, '#mobo-sync-status-card');
    const deadline = Date.now() + 90_000;
    let fresh: unknown[] = [];
    while (Date.now() < deadline && fresh.length === 0) {
      await postAjax(page, url, { action: 'mobo_core_get_sync_status', nonce });
      fresh = await waitForMockRequests(baseline!.length, 5_000);
    }
    note(testInfo, `mock Source received ${fresh.length} new request(s)`);
    expect(fresh.length, 'sync produced at least one request at the mock Source').toBeGreaterThan(0);
  });

  test('"توقف همگام‌سازی" cancels the running sync', async ({ page }) => {
    await ensureRunning(page);
    const id = syncState().syncId;
    const n = await clickAndReadNotice(page, cancelButton(page));
    expect(n.type, n.message).toBe('success');
    expect(n.message).toContain('همگام‌سازی محصولات متوقف شد');
    const after = syncState();
    expect(after.status).toBe('cancelled');
    expect(after.syncId).toBe(id);
    await expect(cancelButton(page)).toHaveCount(0);
  });

  test('"پاک‌سازی وضعیت ذخیره‌شده" deletes the stored sync checkpoint', async ({ page }) => {
    await ensureNotRunning(page);
    const reset = actionForm(page, 'mobo_core_reset_sync').locator('button[type="submit"]');
    await requireRendered(reset, 'reset control (hidden while running/waiting or after a completed run)');
    const n = await clickAndReadNotice(page, reset);
    expect(n.type, n.message).toBe('success');
    expect(n.message).toContain('وضعیت همگام‌سازی پاک شد');
    expect(getState('sync_state'), 'sync_state row removed').toBeNull();
  });

  test('"Repair کامل محصولات" starts a repair-mode sync', async ({ page }) => {
    await ensureNotRunning(page);
    const repair = actionForm(page, 'mobo_core_start_repair').locator('button[type="submit"]').first();
    await requireRendered(repair, 'repair control');
    const n = await clickAndReadNotice(page, repair);
    expect(n.type, n.message).toBe('success');
    expect(n.message).toContain('Repair محصولات شروع شد');
    const after = syncState();
    expect(['running', 'completed', 'waiting_for_portal']).toContain(after.status);
    expect(after.repair, 'repairMode persisted').toBe('1');
    expect(getSetting('mobo_core_repair_completed_at'), 'repair-completed marker cleared').toBeFalsy();

    // Leave the site idle for the following specs (real cancel + reset controls).
    await ensureNotRunning(page);
    const reset = actionForm(page, 'mobo_core_reset_sync').locator('button[type="submit"]');
    if ((await reset.count()) > 0) expect((await clickAndReadNotice(page, reset)).type).toBe('success');
  });

  test('"ادامه همگام‌سازی" resumes a sync waiting for the Source', async ({ page }) => {
    await gotoTab(page, 'dashboard');
    const resume = actionForm(page, 'mobo_core_resume_sync').locator('button[type="submit"]');
    await requireRendered(resume, 'resume is only offered while the sync waits for the Source (waiting_for_portal)');
    const id = syncState().syncId;
    const n = await clickAndReadNotice(page, resume);
    expect(n.type, n.message).toBe('success');
    const after = syncState();
    expect(after.syncId, 'same generation continues').toBe(id);
    expect(after.status).toBe('running');
  });

  test('"به‌روزرسانی دسته‌بندی‌ها برای نگاشت" loads Source categories', { tag: '@backend' }, async ({ page }) => {
    await ensureNotRunning(page);
    const before = Number(getSetting('mobo_core_categories_mapping_loaded_at') || 0);
    const baseline = await mockLog();
    const btn = actionForm(page, 'mobo_core_sync_categories').locator('button[type="submit"]').first();
    await requireRendered(btn, 'category preload control');
    const n = await clickAndReadNotice(page, btn);
    expect(n.tab).toBe('categories');
    expect(n.type, n.message).toBe('success');
    const after = Number(getSetting('mobo_core_categories_mapping_loaded_at') || getSetting('mobo_core_categories_last_sync_at') || 0);
    expect(after, 'category snapshot timestamp advanced').toBeGreaterThanOrEqual(before);
    expect(after).toBeGreaterThan(0);
    if (baseline) expect((await mockLog())!.length, 'mock Source was queried').toBeGreaterThan(baseline.length);
  });

  test('"حفظ محصول اصلی و کنار گذاشتن نسخه‌های تکراری" quarantines duplicates', async ({ page }) => {
    await gotoTab(page, 'dashboard');
    const btn = actionForm(page, 'mobo_core_quarantine_duplicate_products').locator('button[type="submit"]');
    await requireRendered(btn, 'duplicate-product warning only appears when duplicate groups exist');
    const startedAt = Math.floor(Date.now() / 1000) - 5;
    const n = await clickAndReadNotice(page, btn);
    expect(['success', 'warning']).toContain(n.type);
    expect(n.message).toMatch(/پیش‌نویس منتقل/);
    expect(Number(getOption('mobo_core_last_duplicate_quarantine_at') || 0)).toBeGreaterThanOrEqual(startedAt);
    expect(getOption('mobo_core_last_duplicate_quarantine_result')).toBeTruthy();
  });

  test('dashboard "فعال‌سازی مجدد یادآوری موجودی" re-arms the wallet alert', async ({ page }) => {
    await gotoTab(page, 'dashboard');
    const btn = actionForm(page, 'mobo_core_rearm_wallet_alert').locator('input[type="submit"], button[type="submit"]').first();
    await requireRendered(btn, 're-arm is only offered after a wallet alert was sent (or is uncertain)');
    const n = await clickAndReadNotice(page, btn);
    expect(n.type, n.message).toBe('success');
    expect(n.message).toContain('دوباره فعال شد');
    expect(n.tab).toBe('dashboard');
    expect(getSetting('mobo_core_wallet_alert_notified') || '0').toMatch(/^(0|)$/);
  });
});
