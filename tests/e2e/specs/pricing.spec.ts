/**
 * Pricing tab: save_settings(pricing) A:5160, reprice start/resume/cancel/reset
 * A:10375/10412/10444/10472 and ajax reprice status A:10032.
 * Reprice state lives in wp_mobo_state (mobo_core_reprice_state).
 */
import { Locator, Page } from '@playwright/test';
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

/**
 * Pick a price type the way an admin does: the radios are visually hidden
 * (`.mobo-price-types input { display:none }`) and the segmented label is the control.
 */
async function choosePriceType(form: Locator, value: string): Promise<void> {
  const radio = form.locator(`input[name="mobo_price_type"][value="${value}"]`);
  await form.locator('.mobo-price-types label', { has: form.page().locator(`input[name="mobo_price_type"][value="${value}"]`) }).click();
  await expect(radio).toBeChecked();
}

/**
 * Hold the page's live reprice-status poll. On a tiny catalog the poll itself may run
 * the last bounded batch and then reload the page, racing the click on a control that
 * only renders while the run is active. The control under test is unaffected.
 */
async function freezeRepricePoll(page: Page): Promise<void> {
  await page.route('**/admin-ajax.php', (route) =>
    (route.request().postData() || '').includes('mobo_core_get_reprice_status') ? route.abort() : route.fallback());
}

const REPRICE_NOT_ACTIVE = 'عملیات قیمت‌گذاری فعال نیست';
/** Fail-safe answer while a Cron worker still finishes the batch that was just cancelled. */
const REPRICE_BATCH_BUSY = 'هنوز batch جاری را تمام نکرده است';

/**
 * Click a reprice control and, while the plugin refuses with the "worker is still finishing the current batch"
 * fail-safe (a real cron/self-runner is mid-batch), re-open the tab and retry for up to ~60 s. Returns the last
 * notice; null when the control disappeared meanwhile (the run changed state on its own).
 */
async function clickRepriceControlWhenIdle(page: Page, control: () => Locator): Promise<{ type: string; message: string } | null> {
  const deadline = Date.now() + 60_000;
  let n = await clickAndReadNotice(page, control());
  while (n.message.includes(REPRICE_BATCH_BUSY) && Date.now() < deadline) {
    await page.waitForTimeout(5_000);
    await gotoTab(page, 'pricing');
    if ((await control().count()) === 0) return null;
    n = await clickAndReadNotice(page, control());
  }
  expect(n.message, 'worker batch still busy after ~60 s').not.toContain(REPRICE_BATCH_BUSY);
  return n;
}

test.describe('Pricing tab', () => {
  test('"ذخیره سیاست قیمت" persists price type and percentage', async ({ page }) => {
    await gotoTab(page, 'pricing');
    const form = settingsForm(page, 'pricing');
    test.skip(await form.locator('fieldset.mobo-pricing-settings-fieldset').isDisabled(), 'pricing policy is locked while a reprice runs');
    const originalType = (await form.locator('input[name="mobo_price_type"]:checked').inputValue().catch(() => 'static-price')) || 'static-price';
    const originalPct = await form.locator('#global_additional_percentage').inputValue();
    const newPct = originalPct === '17.5' ? '18.25' : '17.5';
    try {
      await choosePriceType(form, 'static-percentage');
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
      await choosePriceType(f, 'static-percentage');
      await f.locator('#global_additional_percentage').fill(originalPct || '0');
      await choosePriceType(f, originalType);
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
    await freezeRepricePoll(page);
    await gotoTab(page, 'pricing');
    const cancel = () => actionForm(page, 'mobo_core_cancel_reprice').locator('button[type="submit"]');
    await requireRendered(cancel(), 'reprice is not running (small catalogs finish within the first batch)');
    // The real cron may be mid-batch; the plugin then refuses the cancel (fail-safe) until the batch ends.
    const clicked = await clickRepriceControlWhenIdle(page, cancel);
    const n = clicked ?? { type: 'success', message: REPRICE_NOT_ACTIVE };
    expect(n.type, n.message).toBe('success');
    if (n.message.includes(REPRICE_NOT_ACTIVE)) {
      // A Cron/Self-Runner worker finished the run between render and click: the
      // handler truthfully reports "not active" and leaves the finished state alone.
      expect(['done', 'completed']).toContain(String(repriceDbStatus()));
    } else {
      expect(repriceDbStatus()).toBe('cancelled');
    }
  });

  test('"ادامه از checkpoint" resumes a cancelled reprice', async ({ page }) => {
    test.setTimeout(240_000);
    await freezeRepricePoll(page);
    await gotoTab(page, 'pricing');
    const resumeButton = () => actionForm(page, 'mobo_core_resume_reprice').locator('button[type="submit"]');
    await requireRendered(resumeButton(), 'resume is only offered for a cancelled reprice run');
    let resumed = false;
    try {
      // The real cron may still be finishing the batch the cancel test just stopped;
      // the plugin then refuses the resume (fail-safe). Retry for up to ~60 s.
      const n = await clickRepriceControlWhenIdle(page, resumeButton);
      expect(n, 'resume control disappeared before it could be used').not.toBeNull();
      expect(n!.type, n!.message).toBe('success');
      resumed = true;
      expect(['running', 'done', 'completed']).toContain(String(repriceDbStatus()));
    } finally {
      // Leave it stopped for the reset test.
      if (resumed) {
        await gotoTab(page, 'pricing');
        const cancel = () => actionForm(page, 'mobo_core_cancel_reprice').locator('button[type="submit"]');
        if ((await cancel().count()) > 0) {
          const c = await clickRepriceControlWhenIdle(page, cancel);
          if (c) expect(c.type, c.message).toBe('success');
        }
      }
    }
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
