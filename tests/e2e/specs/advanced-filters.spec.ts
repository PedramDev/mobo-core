/**
 * Advanced › Product filters: save_settings(filters) A:5223 via
 * save_filters_tab_from_post (normalize + verified read-back).
 */
import { test, expect } from '../lib/fixtures';
import { gotoTab, settingsForm, submitForm, toAsciiDigits } from '../lib/admin';
import { getSetting } from '../lib/db';

const KEY = 'mobo_core_excluded_product_urls';

test.describe('Advanced: product sync exclusions', () => {
  test('"ذخیره استثناها" stores a normalized, de-duplicated exclusion', async ({ page }) => {
    await gotoTab(page, 'filters');
    const form = settingsForm(page, 'filters');
    const textarea = form.locator(`textarea[name="${KEY}"]`);
    const original = await textarea.inputValue();
    const slug = `e2e-exclusion-${Date.now()}`;
    try {
      const lines = [original.trim(), `https://source.example/products/${slug}/?utm=1`, `/products/${slug}`].filter(Boolean);
      await textarea.fill(lines.join('\n'));
      const n = await submitForm(page, form);
      expect(n.type, n.message).toBe('success');
      expect(n.tab).toBe('filters');
      expect(toAsciiDigits(n.message)).toMatch(/\d+ استثنای یکتا ذخیره شد\./);
      expect(toAsciiDigits(n.message)).toMatch(/\d+ مورد تکراری نیز هنگام ذخیره حذف شد/);
      const stored = getSetting(KEY) || '';
      expect(stored).toContain(`/products/${slug}`);
      expect(stored.split(`/products/${slug}`).length - 1, 'duplicate collapsed to one canonical path').toBe(1);
      expect(stored).not.toContain('utm=');
      await expect(settingsForm(page, 'filters').locator(`textarea[name="${KEY}"]`)).toHaveValue(new RegExp(slug));
    } finally {
      await gotoTab(page, 'filters');
      await settingsForm(page, 'filters').locator(`textarea[name="${KEY}"]`).fill(original);
      expect((await submitForm(page, settingsForm(page, 'filters'))).type).toBe('success');
      expect(getSetting(KEY) || '').not.toContain(slug);
    }
  });

  test('an absolute URL without a product path is rejected and nothing changes', async ({ page }) => {
    await gotoTab(page, 'filters');
    const before = getSetting(KEY);
    const form = settingsForm(page, 'filters');
    await form.locator(`textarea[name="${KEY}"]`).fill('https://source.example');
    const n = await submitForm(page, form);
    expect(n.type).toBe('error');
    expect(n.message).toContain('خط قابل تبدیل به مسیر محصول نیست');
    expect(n.message).toContain('فهرست قبلی بدون تغییر باقی ماند');
    expect(getSetting(KEY)).toBe(before);
  });
});
