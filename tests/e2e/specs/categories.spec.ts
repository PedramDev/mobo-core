/**
 * Categories tab: save_settings(categories) A:5102 policy form / A:5125 mapping
 * form, sync_categories A:10184 (tab button), recategorize start/cancel/reset
 * A:10502/10534/10562 and ajax recategorize status A:10085.
 */
import { Page } from '@playwright/test';
import { test, expect } from '../lib/fixtures';
import { actionForm, boolValue, cardAjax, clickAndReadNotice, gotoTab, postAjax, requireRendered, reveal, setBool, submitForm } from '../lib/admin';
import { defaultConnectionId, getSetting, getState, lit, scalar, t, tableExists } from '../lib/db';


const policyForm = (page: Page) => page.locator('form[data-mobo-category-policy-form="1"]');
const mappingForm = (page: Page) => page.locator('form.mobo-category-mapping-form');

async function recategorizeStatus(page: Page): Promise<any> {
  const { url, nonce } = await cardAjax(page, '#mobo-recategorize-status-card');
  const res = await postAjax(page, url, { action: 'mobo_core_get_recategorize_status', nonce });
  expect(res.status).toBe(200);
  expect(res.json?.success, JSON.stringify(res.json)).toBe(true);
  return res.json.data;
}

test.describe('Categories tab', () => {
  test('"ذخیره رفتار دسته‌بندی" persists global_update_categories', async ({ page }) => {
    const key = 'global_update_categories';
    await gotoTab(page, 'categories');
    const original = (await boolValue(policyForm(page), key)) ?? '1';
    const target = original === '1' ? '0' : '1';
    try {
      await setBool(policyForm(page), key, target);
      const n = await submitForm(page, policyForm(page));
      expect(n.type, n.message).toBe('success');
      expect(n.tab).toBe('categories');
      expect(getSetting(key)).toBe(target);
    } finally {
      await gotoTab(page, 'categories');
      await setBool(policyForm(page), key, original as '0' | '1');
      expect((await submitForm(page, policyForm(page))).type).toBe('success');
      expect(getSetting(key)).toBe(original);
    }
  });

  test('turning manual mapping off also clears "الزام نگاشت دستی"', async ({ page }) => {
    await gotoTab(page, 'categories');
    const enabled = (await boolValue(policyForm(page), 'mobo_core_category_mapping_enabled')) ?? '1';
    const required = (await boolValue(policyForm(page), 'mobo_core_category_mapping_required')) ?? '0';
    try {
      await setBool(policyForm(page), 'mobo_core_category_mapping_enabled', '0');
      expect((await submitForm(page, policyForm(page))).type).toBe('success');
      expect(getSetting('mobo_core_category_mapping_enabled')).toBe('0');
      expect(getSetting('mobo_core_category_mapping_required')).toBe('0');
    } finally {
      await gotoTab(page, 'categories');
      await setBool(policyForm(page), 'mobo_core_category_mapping_enabled', enabled as '0' | '1');
      expect((await submitForm(page, policyForm(page))).type).toBe('success');
      if (enabled === '1' && required === '1') {
        await gotoTab(page, 'categories');
        await setBool(policyForm(page), 'mobo_core_category_mapping_required', '1');
        expect((await submitForm(page, policyForm(page))).type).toBe('success');
      }
      expect(getSetting('mobo_core_category_mapping_enabled')).toBe(enabled);
    }
  });

  test('"ذخیره نگاشت‌های این صفحه" stores a manual category mapping', async ({ page }) => {
    test.skip(!tableExists('mobo_category_map'), 'category map table not installed');
    await gotoTab(page, 'categories');
    const select = mappingForm(page).locator('select[name^="mobo_category_map["]').first();
    await requireRendered(select, 'no Source categories loaded yet (run "دریافت تازه‌ترین فهرست دسته‌ها" with the Backend up)');
    const name = (await select.getAttribute('name'))!;
    const guid = name.slice('mobo_category_map['.length, -1);
    const original = await select.inputValue();
    const options = await select.locator('option').evaluateAll((els) => els.map((e) => (e as HTMLOptionElement).value).filter((v) => v !== ''));
    const target = options.find((v) => v !== original && v !== '0');
    test.skip(!target, 'no alternative WooCommerce category to map to');
    const conn = defaultConnectionId();
    const read = () =>
      scalar(`SELECT manual_term_id FROM ${t('mobo_category_map')} WHERE connection_id = ${lit(conn)} AND remote_guid = ${lit(guid)} LIMIT 1`);
    try {
      await select.selectOption(target!, { force: true });
      const n = await submitForm(page, mappingForm(page));
      expect(n.type, n.message).toBe('success');
      expect(n.message).toContain('نگاشت‌های دسته‌بندی ذخیره شدند');
      expect(read()).toBe(target);
    } finally {
      await gotoTab(page, 'categories');
      await mappingForm(page).locator(`select[name="${name}"]`).selectOption(original || '0', { force: true });
      expect((await submitForm(page, mappingForm(page))).type).toBe('success');
    }
  });

  test('"دریافت تازه‌ترین فهرست دسته‌ها" fetches Source categories', { tag: '@backend' }, async ({ page }) => {
    await gotoTab(page, 'categories');
    /* T1: the plugin refreshes categories from the Backend's GET /get-categories, which the Backend answers from
     * its own DB; the Source is only polled by the Backend fetcher on its own schedule, so a Source request in this
     * window is not evidence. Proof of a fresh, fully applied snapshot instead: the microsecond commit watermark
     * (written only after every row was applied, Mobo_Core_Category_Sync::apply_fenced_snapshot) strictly advances. */
    const watermarkBefore = Number(getState('category_snapshot_committed_us') || 0);
    const n = await clickAndReadNotice(page, actionForm(page, 'mobo_core_sync_categories').locator('button[type="submit"]').first());
    expect(n.type, n.message).toBe('success');
    expect(Number(getSetting('mobo_core_categories_mapping_loaded_at') || getSetting('mobo_core_categories_last_sync_at') || 0)).toBeGreaterThan(0);
    expect(Number(getState('category_snapshot_committed_us') || 0), 'a new category snapshot was committed').toBeGreaterThan(watermarkBefore);
    expect(Number(getSetting('mobo_core_category_mapping_active_snapshot_at') || 0), 'active category snapshot recorded').toBeGreaterThan(0);
  });

  test('ajax mobo_core_get_recategorize_status returns queue status', async ({ page }) => {
    await gotoTab(page, 'categories');
    await reveal(page.locator('#mobo-recategorize-status-card'));
    const data = await recategorizeStatus(page);
    expect(data).toHaveProperty('status');
  });

  test('"اعمال روی محصولات موجود" starts the recategorize queue', async ({ page }) => {
    await gotoTab(page, 'categories');
    const start = actionForm(page, 'mobo_core_start_recategorize').locator('button[type="submit"]');
    await requireRendered(start, 'recategorize already running');
    const n = await clickAndReadNotice(page, start);
    expect(n.tab).toBe('categories');
    expect(n.type, n.message).toBe('success');
    expect(getState('mobo_core_recategorize_state'), 'durable queue state written').toBeTruthy();
    const data = await recategorizeStatus(page);
    expect(['running', 'completed', 'done', 'idle']).toContain(String(data.status));
  });

  test('"توقف عملیات" cancels the running recategorize queue', async ({ page }) => {
    await gotoTab(page, 'categories');
    const cancel = actionForm(page, 'mobo_core_cancel_recategorize').locator('button[type="submit"]');
    await requireRendered(cancel, 'recategorize is not running (small catalogs finish immediately)');
    const n = await clickAndReadNotice(page, cancel);
    expect(n.type, n.message).toBe('success');
    const data = await recategorizeStatus(page);
    expect(String(data.status)).not.toBe('running');
  });

  test('"پاک کردن گزارش قبلی" resets the recategorize report', async ({ page }) => {
    await gotoTab(page, 'categories');
    const reset = actionForm(page, 'mobo_core_reset_recategorize').locator('button[type="submit"]');
    await requireRendered(reset, 'no finished recategorize report to clear');
    const n = await clickAndReadNotice(page, reset);
    expect(n.type, n.message).toBe('success');
    expect(n.message).toContain('وضعیت اعمال مجدد دسته‌بندی‌ها پاک شد');
    expect(getState('mobo_core_recategorize_state'), 'state row deleted').toBeNull();
  });
});
