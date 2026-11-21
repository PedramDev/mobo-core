/**
 * Connection tab: save_settings(connection) A:9543, create_connection A:9479,
 * cancel_pending_connection A:9504.
 */
import { test, expect } from '../lib/fixtures';
import { actionForm, clickAndReadNotice, gotoTab, settingsForm, submitForm } from '../lib/admin';
import { count, getOption, lit, query, t, tableExists } from '../lib/db';


const MONEY_KEY = 'mobo_core_money_target_preference';

test.describe('Connection tab', () => {
  test('"ذخیره اطلاعات اتصال" persists the target-currency preference', async ({ page }) => {
    await gotoTab(page, 'connection');
    const form = settingsForm(page, 'connection');
    const select = form.locator(`select[name="${MONEY_KEY}"]`);
    await expect(select).toBeVisible();
    const original = await select.inputValue();
    const options = await select.locator('option').evaluateAll((els) => els.map((e) => (e as HTMLOptionElement).value));
    const target = options.find((v) => v !== original);
    test.skip(!target, 'only one target-currency choice is offered');

    try {
      await select.selectOption(target!);
      const n = await submitForm(page, form);
      expect(n.type, n.message).toBe('success');
      expect(n.message).toBe('تنظیمات همین تب ذخیره شد.');
      expect(getOption(MONEY_KEY), 'wp_options value').toBe(target);
      await expect(settingsForm(page, 'connection').locator(`select[name="${MONEY_KEY}"]`)).toHaveValue(target!);
    } finally {
      await gotoTab(page, 'connection');
      await settingsForm(page, 'connection').locator(`select[name="${MONEY_KEY}"]`).selectOption(original);
      const restore = await submitForm(page, settingsForm(page, 'connection'));
      expect(restore.type).toBe('success');
      expect(getOption(MONEY_KEY)).toBe(original);
    }
  });

  test('invalid webhook security code is rejected and nothing is saved', async ({ page }) => {
    await gotoTab(page, 'connection');
    const before = getOption('mobo_core_security_code');
    const form = settingsForm(page, 'connection');
    await form.locator('[name="mobo_core_security_code"]').fill('کد نامعتبر با فاصله');
    const n = await submitForm(page, form);
    expect(n.type).toBe('error');
    expect(n.message).toContain('کد امنیتی وب‌هوک ذخیره نشد');
    expect(n.message).toContain('مقدار قبلی بدون تغییر باقی ماند');
    expect(getOption('mobo_core_security_code')).toBe(before);
  });

  test('"ایجاد اتصال" creates a pending Connection and "لغو اتصال در انتظار" deletes it', async ({ page }) => {
    test.skip(!tableExists('mobo_connections'), 'Multi-Connection storage table is not installed on this site');
    // Generated, throw-away test token for the local staging site only.
    const token = `e2e-pending-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const conns = t('mobo_connections');

    await gotoTab(page, 'connection');
    const create = actionForm(page, 'mobo_core_create_connection');
    await create.locator('#mobo_new_connection_token').fill(token);
    const created = await clickAndReadNotice(page, create.locator('button[type="submit"]'));
    expect(created.type, created.message).toBe('success');
    expect(created.message).toContain('توکن ذخیره شد');

    const rows = query(`SELECT id, is_enabled, is_default, display_name FROM ${conns} WHERE license_token = ${lit(token)}`);
    expect(rows, 'exactly one pending connection row').toHaveLength(1);
    expect(rows[0].is_enabled).toBe('0');
    expect(rows[0].is_default).toBe('0');
    const id = Number(rows[0].id);

    // Same token again must be refused (unique token per Connection).
    await gotoTab(page, 'connection');
    await actionForm(page, 'mobo_core_create_connection').locator('#mobo_new_connection_token').fill(token);
    const dup = await clickAndReadNotice(page, actionForm(page, 'mobo_core_create_connection').locator('button[type="submit"]'));
    expect(dup.type).toBe('error');
    expect(dup.message).toContain('اتصال ساخته نشد');
    expect(count(`SELECT COUNT(*) FROM ${conns} WHERE license_token = ${lit(token)}`)).toBe(1);

    // The pending Connection page offers the cancel control.
    await gotoTab(page, 'connection', { mobo_connection_id: String(id) });
    const cancel = actionForm(page, 'mobo_core_cancel_pending_connection').filter({ has: page.locator(`input[name="connection_id"][value="${id}"]`) });
    await expect(cancel.locator('button[type="submit"]')).toHaveText('لغو اتصال در انتظار');
    const cancelled = await clickAndReadNotice(page, cancel.locator('button[type="submit"]'));
    expect(cancelled.type, cancelled.message).toBe('success');
    expect(cancelled.message).toContain('اتصال در انتظار و توکن ذخیره‌شده آن حذف شد');
    expect(count(`SELECT COUNT(*) FROM ${conns} WHERE id = ${lit(id)}`), 'row deleted').toBe(0);
    if (tableExists('mobo_connection_settings')) {
      expect(count(`SELECT COUNT(*) FROM ${t('mobo_connection_settings')} WHERE connection_id = ${lit(id)}`), 'scoped settings deleted').toBe(0);
    }
  });
});
