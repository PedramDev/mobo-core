/**
 * Advanced › SMS: save_settings(sms) A:3785 via
 * save_sms_notification_settings_from_post, test_wallet_sms A:9452 and the
 * SMS-tab re-arm form (rearm_wallet_alert A:9424 with mobo_return_tab=sms).
 */
import { test, expect } from '../lib/fixtures';
import { actionForm, clickAndReadNotice, gotoTab, requireRendered, reveal, settingsForm, submitForm } from '../lib/admin';
import { getSetting } from '../lib/db';

const THRESHOLD = 'mobo_core_wallet_alert_threshold';

test.describe('Advanced: SMS & wallet alert', () => {
  test('"حد هشدار موجودی" is saved and read back', async ({ page }) => {
    await gotoTab(page, 'sms');
    const form = settingsForm(page, 'sms');
    const input = form.locator(`#${THRESHOLD}`);
    await reveal(input);
    const original = await input.inputValue();
    const target = original === '123457' ? '123458' : '123457';
    try {
      await input.fill(target);
      const n = await submitForm(page, form);
      expect(n.tab).toBe('sms');
      if (n.type === 'warning') expect(n.message).toContain('تنظیمات ذخیره شد، اما این موارد هنوز نیازمند تکمیل هستند');
      else {
        expect(n.type, n.message).toBe('success');
        expect(n.message).toBe('تنظیمات پیامک و هشدار موجودی ذخیره و read-back شدند.');
      }
      expect(getSetting(THRESHOLD)).toBe(target);
    } finally {
      await gotoTab(page, 'sms');
      const f = settingsForm(page, 'sms');
      await reveal(f.locator(`#${THRESHOLD}`));
      await f.locator(`#${THRESHOLD}`).fill(original || '0');
      expect(['success', 'warning']).toContain((await submitForm(page, f)).type);
      expect(getSetting(THRESHOLD) ?? '0').toBe(original || '0');
    }
  });

  test('"ارسال SMS آزمایشی" sends a wallet SMS transport test', async ({ page }) => {
    await gotoTab(page, 'sms');
    const btn = actionForm(page, 'mobo_core_test_wallet_sms').locator('input[type="submit"], button[type="submit"]').first();
    await requireRendered(btn, 'test SMS needs the Persian WooCommerce SMS API plus saved recipients and template');
    const before = Number(getSetting('mobo_core_wallet_test_sms_last_attempt_at') || 0);
    const n = await clickAndReadNotice(page, btn);
    expect(n.tab).toBe('sms');
    if (n.type === 'success') {
      expect(n.message).toContain('API درگاه درخواست SMS آزمایشی را پذیرفت');
      expect(getSetting('mobo_core_wallet_test_sms_last_result')).toBe('sent');
    } else {
      expect(n.type).toBe('error');
      expect(n.message).toContain('SMS آزمایشی ارسال نشد');
    }
    expect(Number(getSetting('mobo_core_wallet_test_sms_last_attempt_at') || 0)).toBeGreaterThanOrEqual(before);
  });

  test('SMS-tab "فعال‌سازی مجدد هشدار این Connection" re-arms and returns to the SMS tab', async ({ page }) => {
    await gotoTab(page, 'sms');
    const form = actionForm(page, 'mobo_core_rearm_wallet_alert').filter({ has: page.locator('input[name="mobo_return_tab"][value="sms"]') });
    const btn = form.locator('input[type="submit"], button[type="submit"]').first();
    await requireRendered(btn, 're-arm is only offered after a wallet alert was sent (or is uncertain)');
    const n = await clickAndReadNotice(page, btn);
    expect(n.type, n.message).toBe('success');
    expect(n.tab).toBe('sms');
    expect(getSetting('mobo_core_wallet_alert_notified')).toBe('0');
  });
});
