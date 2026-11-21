/**
 * Pricing tab: save_settings(pricing) A:5160, reprice start/resume/cancel/reset
 * A:10375/10412/10444/10472 and ajax reprice status A:10032.
 * Reprice state lives in wp_mobo_state (mobo_core_reprice_state).
 */
import { Page } from '@playwright/test';
import { test, expect } from '../lib/fixtures';
import { actionForm, cardAjax, clickAndReadNotice, gotoTab, postAjax, requireRendered, reveal, settingsForm, submitForm } from '../lib/admin';
import { getSetting, getState, serializedField } from '../lib/db';


async function repriceStatus(page: Page): Promise<any> {
  await reveal(page.locator('#mobo-reprice-status-card'));
  const { url, nonce } = await cardAjax(page, '#mobo-reprice-status-card');
  const res = await postAjax(page, url, { action: 'mobo_core_get_reprice_status', nonce });
  expect(res.status).toBe(200);
  expect(res.json?.success, JSON.stringify(res.json)).toBe(true);
  return res.json.data;
}

const repriceDbStatus = () => serializedField(getState('mobo_core_reprice_state'), 'status');

test.describe('Pricing tab', () => {
  test('"ذخیره سیاست قیمت" persists price type and percentage', async ({ page }) => {
    await gotoTab(page, 'pricing');
    const form = settingsForm(page, 'pricing');
    test.skip(await form.locator('fieldset.mobo-pricing-settings-fieldset').isDisabled(), 'pricing policy is locked while a reprice runs');
    const originalType = (await form.locator('input[name="mobo_price_type"]:checked').inputValue().catch(() => 'static-price')) || 'static-price';
    const originalPct = await form.locator('#global_additional_percentage').inputValue();
    const newPct = originalPct === '17.5' ? '18.25' : '17.5';
    try {
      await form.locator('input[name="mobo_price_type"][value="static-percentage"]').check({ force: true });
      await expect(form.locator('#static-percentage')).toBeVisible();
      await form.locator('#global_additional_percentage').fill(newPct);
      const n = await submitForm(page, form);
      expect(n.type, n.message).toBe('success');
      expect(n.tab).toBe('pricing');
      expect(getSetting('mobo_price_type')).toBe('static-percentage');
      expect(Number(getSetting('global_additional_percentage'))).toBeCloseTo(Number(newPct), 4);
      await expect(settingsForm(page, 'pricing').locator('#global_additional_percentage')).toHaveValue(new RegExp(`^${newPct.replace('.', '\\.')}`));
    } finally {
      await gotoTab(page, 'pricing');
      const f = settingsForm(page, 'pricing');
      await f.locator('input[name="mobo_price_type"][value="static-percentage"]').check({ force: true });
      await f.locator('#global_additional_percentage').fill(originalPct || '0');
      await f.locator(`input[name="mobo_price_type"][value="${originalType}"]`).check({ force: true });
      expect((await submitForm(page, f)).type).toBe('success');
      expect(getSetting('mobo_price_type')).toBe(originalType);
    }
  });

  test('ajax mobo_core_get_reprice_status returns queue status', async ({ page }) => {
    await gotoTab(page, 'pricing');
    const data = await repriceStatus(page);
    expect(data).toHaveProperty('status');
  });

  test('"اعمال قیمت روی همه کاندیداها از ابتدا" starts a reprice run', async ({ page }) => {
    await gotoTab(page, 'pricing');
    const start = actionForm(page, 'mobo_core_start_reprice').locator('button[type="submit"]');
    await requireRendered(start, 'reprice already running');
    const n = await clickAndReadNotice(page, start);
    expect(n.tab).toBe('pricing');
    expect(n.type, n.message).toBe('success');
    expect(['running', 'done', 'completed']).toContain(String(repriceDbStatus()));
    expect(['running', 'done', 'completed']).toContain(String((await repriceStatus(page)).status));
  });

  test('"توقف اعمال قیمت" cancels the running reprice', async ({ page }) => {
    await gotoTab(page, 'pricing');
    const cancel = actionForm(page, 'mobo_core_cancel_reprice').locator('button[type="submit"]');
    await requireRendered(cancel, 'reprice is not running (small catalogs finish within the first batch)');
    const n = await clickAndReadNotice(page, cancel);
    expect(n.type, n.message).toBe('success');
    expect(repriceDbStatus()).toBe('cancelled');
  });

  test('"ادامه از checkpoint" resumes a cancelled reprice', async ({ page }) => {
    await gotoTab(page, 'pricing');
    const resume = actionForm(page, 'mobo_core_resume_reprice').locator('button[type="submit"]');
    await requireRendered(resume, 'resume is only offered for a cancelled reprice run');
    const n = await clickAndReadNotice(page, resume);
    expect(n.type, n.message).toBe('success');
    expect(['running', 'done', 'completed']).toContain(String(repriceDbStatus()));
    // Leave it stopped for the reset test.
    await gotoTab(page, 'pricing');
    const cancel = actionForm(page, 'mobo_core_cancel_reprice').locator('button[type="submit"]');
    if ((await cancel.count()) > 0) expect((await clickAndReadNotice(page, cancel)).type).toBe('success');
  });

  test('"پاک کردن گزارش قبلی" resets the reprice report', async ({ page }) => {
    await gotoTab(page, 'pricing');
    const reset = actionForm(page, 'mobo_core_reset_reprice').locator('button[type="submit"]');
    await requireRendered(reset, 'no finished reprice report to clear');
    const n = await clickAndReadNotice(page, reset);
    expect(n.type, n.message).toBe('success');
    expect(n.message).toContain('وضعیت اعمال مجدد قیمت‌گذاری پاک شد');
    expect(getState('mobo_core_reprice_state'), 'state row deleted').toBeNull();
  });
});
