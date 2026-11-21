/**
 * Advanced › Speed & queues: save_settings(queue) A:5509 via
 * save_queue_settings_from_post (clamped, verified read-back).
 */
import { test, expect } from '../lib/fixtures';
import { boolValue, gotoTab, reveal, setBool, settingsForm, submitForm } from '../lib/admin';
import { getSetting } from '../lib/db';


test.describe('Advanced: queue & capacity settings', () => {
  test('"تعداد محصول در هر صفحه" is saved and read back', async ({ page }) => {
    await gotoTab(page, 'queue');
    const form = settingsForm(page, 'queue');
    const input = form.locator('#mobo_core_products_per_page');
    await reveal(input);
    const original = await input.inputValue();
    const target = original === '7' ? '8' : '7';
    try {
      await input.fill(target);
      const n = await submitForm(page, form);
      expect(['success', 'warning'], n.message).toContain(n.type);
      expect(n.message).toContain('read-back شدند');
      expect(n.tab).toBe('queue');
      expect(getSetting('mobo_core_products_per_page')).toBe(target);
    } finally {
      await gotoTab(page, 'queue');
      const f = settingsForm(page, 'queue');
      await reveal(f.locator('#mobo_core_products_per_page'));
      await f.locator('#mobo_core_products_per_page').fill(original);
      const r = await submitForm(page, f);
      expect(['success', 'warning']).toContain(r.type);
      expect(getSetting('mobo_core_products_per_page')).toBe(original);
    }
  });

  test('turning the image queue off persists and returns the documented warning', async ({ page }) => {
    const key = 'mobo_core_image_queue_enabled';
    await gotoTab(page, 'queue');
    const form = settingsForm(page, 'queue');
    test.skip((await form.locator(`input[name="${key}"]`).count()) === 0, 'image queue toggle not rendered');
    const original = (await boolValue(form, key)) ?? '1';
    try {
      await setBool(form, key, '0');
      const n = await submitForm(page, form);
      expect(n.type).toBe('warning');
      expect(n.message).toContain('صف مستقل تصویر خاموش است');
      expect(getSetting(key)).toBe('0');
    } finally {
      await gotoTab(page, 'queue');
      await setBool(settingsForm(page, 'queue'), key, original as '0' | '1');
      expect(['success', 'warning']).toContain((await submitForm(page, settingsForm(page, 'queue'))).type);
      expect(getSetting(key)).toBe(original);
    }
  });
});
