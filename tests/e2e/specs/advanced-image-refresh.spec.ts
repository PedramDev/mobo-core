/**
 * Advanced › Image refresh: start_image_refresh_automation A:10990 and the live
 * status ajax A:10120 that re-renders the tab.
 */
import { test, expect } from '../lib/fixtures';
import { actionForm, clickAndReadNotice, gotoTab, postAjax, requireRendered, toAsciiDigits } from '../lib/admin';
import { getSetting } from '../lib/db';


test.describe('Advanced: image refresh automation', () => {
  test('ajax mobo_core_get_image_refresh_live_status re-renders the tab HTML', async ({ page }) => {
    await gotoTab(page, 'image-refresh');
    const root = page.locator('#mobo-image-refresh-live-root');
    const url = (await root.getAttribute('data-ajax-url')) || '/wp-admin/admin-ajax.php';
    const nonce = (await root.getAttribute('data-nonce')) || '';
    expect(nonce).not.toBe('');
    const res = await postAjax(page, url, { action: 'mobo_core_get_image_refresh_live_status', nonce });
    expect(res.status).toBe(200);
    expect(res.json?.success, JSON.stringify(res.json).slice(0, 300)).toBe(true);
    expect(String(res.json.data.html)).toContain('mobo-image-refresh-live-root');
    // Mobo_Core_Iran_Date renders Jalali dates with Persian digits.
    expect(toAsciiDigits(String(res.json.data.serverTime))).toMatch(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/);

    const bad = await postAjax(page, url, { action: 'mobo_core_get_image_refresh_live_status', nonce: 'invalid' });
    expect(bad.status).toBe(403);
  });

  test('"نوسازی کامل تصاویر از منبع" starts the automated refresh cycle', async ({ page }) => {
    await gotoTab(page, 'image-refresh');
    const start = actionForm(page, 'mobo_core_start_image_refresh_automation').locator('button[type="submit"]');
    await requireRendered(start, 'start requires a completed product Repair, a provisioned Connection and image tables (and no active cycle)');
    const startedBefore = Number(getSetting('mobo_core_image_refresh_automation_started_at') || 0);
    const n = await clickAndReadNotice(page, start);
    expect(n.tab).toBe('image-refresh');
    expect(n.type, n.message).toBe('success');
    expect(getSetting('mobo_core_image_refresh_automation_enabled')).toBe('1');
    expect(Number(getSetting('mobo_core_image_refresh_automation_started_at') || 0)).toBeGreaterThanOrEqual(startedBefore);
    await expect(actionForm(page, 'mobo_core_start_image_refresh_automation'), 'start hidden while the cycle is active').toHaveCount(0);
  });
});
