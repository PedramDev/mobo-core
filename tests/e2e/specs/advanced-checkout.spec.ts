/**
 * Advanced › Mobo order (checkout): save_settings(checkout) A:2817 and the
 * support tools A:9228 — tool_test_mobo_login, tool_sync_address_mapping,
 * tool_sync_remote_shipping_methods, tool_clear_mobo_debug_log,
 * tool_clear_shipping_diagnostics.
 */
import { Locator, Page } from '@playwright/test';
import { test, expect } from '../lib/fixtures';
import { Notice, boolValue, clickAndReadNotice, gotoTab, reveal, setBool, settingsForm, submitForm } from '../lib/admin';
import { columnExists, count, defaultConnectionId, getOption, getSetting, lit, t, tableExists } from '../lib/db';


const ORDER_GUARD = 'تنظیمات ذخیره شد، اما ثبت خودکار سفارش برای جلوگیری از ارسال ناقص غیرفعال شد';

/** A checkout save may legitimately fail-close order submission (incomplete config) while saving the rest. */
function expectCheckoutSaved(n: Notice): void {
  if (n.type === 'error') expect(n.message).toContain(ORDER_GUARD);
  else expect(n.type, n.message).toBe('success');
  expect(n.tab).toBe('checkout');
}

async function toolButton(page: Page, action: string): Promise<Locator> {
  await gotoTab(page, 'checkout');
  const btn = page.locator(`[data-mobo-admin-tool-action="${action}"]`).first();
  await expect(btn).toBeAttached();
  await reveal(btn);
  test.skip(!(await btn.isVisible()), `tool button ${action} is hidden by its UI group in the current checkout configuration`);
  return btn;
}

const now = () => Math.floor(Date.now() / 1000) - 2;

test.describe('Advanced: checkout / Mobo order', () => {
  test('toggle shipping diagnostics → "ذخیره اعتبارسنجی و سفارش" persists it', async ({ page }) => {
    const key = 'mobo_core_shipping_diagnostics_enabled';
    await gotoTab(page, 'checkout');
    const form = settingsForm(page, 'checkout');
    await reveal(form.locator(`input[name="${key}"]`));
    const original = (await boolValue(form, key)) ?? '0';
    const target = original === '1' ? '0' : '1';
    try {
      await setBool(form, key, target);
      expectCheckoutSaved(await submitForm(page, form));
      expect(getSetting(key)).toBe(target);
    } finally {
      await gotoTab(page, 'checkout');
      await setBool(settingsForm(page, 'checkout'), key, original as '0' | '1');
      expectCheckoutSaved(await submitForm(page, settingsForm(page, 'checkout')));
      expect(getSetting(key)).toBe(original);
    }
  });

  test('"پاک کردن گزارش سبد موبو" clears the cart debug log', async ({ page }) => {
    const btn = await toolButton(page, 'mobo_core_tool_clear_mobo_debug_log');
    const n = await clickAndReadNotice(page, btn);
    expect(n.type).toBe('success');
    expect(n.message).toBe('لاگ دیباگ سبد موبو پاک شد.');
    expect(n.tab).toBe('checkout');
    if (tableExists('mobo_events') && columnExists('mobo_events', 'connection_id')) {
      expect(count(`SELECT COUNT(*) FROM ${t('mobo_events')} WHERE connection_id = ${lit(defaultConnectionId())} AND event_type = 'checkout_debug'`)).toBe(0);
    }
    expect(getOption('mobo_core_checkout_mobo_debug_log')).toBeNull();
  });

  test('"پاک کردن گزارش ارسال" clears the shipping diagnostics report', async ({ page }) => {
    const btn = await toolButton(page, 'mobo_core_tool_clear_shipping_diagnostics');
    const n = await clickAndReadNotice(page, btn);
    expect(n.type).toBe('success');
    expect(n.message).toBe('گزارش دیباگ حمل و نقل پاک شد.');
    expect(getOption('mobo_core_shipping_diagnostics_last')).toBeNull();
  });

  test('"تست اتصال به موبو" runs the login test and records its result', { tag: '@backend' }, async ({ page }) => {
    const btn = await toolButton(page, 'mobo_core_tool_test_mobo_login');
    const startedAt = now();
    const n = await clickAndReadNotice(page, btn);
    expect(n.tab).toBe('checkout');
    const result = getSetting('mobo_core_checkout_mobo_login_test_result');
    if (n.type === 'success') {
      expect(n.message).toBe('تست ورود به موبو موفق بود.');
      expect(result).toBe('موفق');
    } else {
      expect(n.type).toBe('error');
      expect(n.message).toContain('تست ورود ناموفق بود');
      expect(result).toBe('ناموفق');
    }
    expect(Number(getSetting('mobo_core_checkout_mobo_login_test_at') || 0)).toBeGreaterThanOrEqual(startedAt);
  });

  test('"بروزرسانی آدرس موبو و ساخت فایل شهرهای Checkout" syncs the address catalog', { tag: '@backend' }, async ({ page }) => {
    const btn = await toolButton(page, 'mobo_core_tool_sync_address_mapping');
    const startedAt = now();
    const n = await clickAndReadNotice(page, btn, 170_000);
    expect(n.type, n.message).toBe('success');
    expect(n.message).toBe('کشور، استان و شهرها از MoboCore بروزرسانی و فایل‌های شهر Checkout ساخته شدند.');
    expect(Number(getSetting('mobo_core_address_mapping_last_attempt_at') || 0)).toBeGreaterThanOrEqual(startedAt);
    expect(Number(getSetting('mobo_core_address_mapping_last_success_at') || 0)).toBeGreaterThanOrEqual(startedAt);
  });

  test('"بروزرسانی روش‌های ارسال از MoboCore" syncs remote shipping methods', { tag: '@backend' }, async ({ page }) => {
    const btn = await toolButton(page, 'mobo_core_tool_sync_remote_shipping_methods');
    const startedAt = now();
    const n = await clickAndReadNotice(page, btn, 170_000);
    expect(n.type, n.message).toBe('success');
    expect(n.message).toBe('روش‌های ارسال موبو از MoboCore بروزرسانی شدند.');
    expect(Number(getSetting('mobo_core_remote_shipping_methods_last_attempt_at') || 0)).toBeGreaterThanOrEqual(startedAt);
    expect(Number(getSetting('mobo_core_remote_shipping_methods_last_success_at') || 0)).toBeGreaterThanOrEqual(startedAt);
  });
});
