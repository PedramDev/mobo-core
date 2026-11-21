/**
 * Advanced › Cron & Worker: save_settings(cron) A:4845, cron presets (client
 * side, then saved) and tool_run_cron_now A:9228/9330.
 */
import { test, expect } from '../lib/fixtures';
import { clickAndReadNotice, gotoTab, restoreForm, reveal, settingsForm, snapshotForm, submitForm } from '../lib/admin';
import { getOption, getSetting } from '../lib/db';


test.describe('Advanced: cron & worker', () => {
  test('"هاست ضعیف" preset fills the form and "ذخیره" persists it', async ({ page }) => {
    await gotoTab(page, 'cron');
    const form = settingsForm(page, 'cron');
    const snap = await snapshotForm(form);
    try {
      const preset = page.locator('[data-mobo-cron-preset="weak"]');
      await reveal(preset);
      await preset.click();
      await expect(page.locator('[data-mobo-cron-preset-message]')).toContainText('هاست ضعیف');
      await expect(form.locator('[name="mobo_core_real_cron_max_rounds"]')).toHaveValue('50');
      await expect(form.locator('[name="mobo_core_real_cron_time_budget_seconds"]')).toHaveValue('10');
      const n = await submitForm(page, form);
      expect(n.type, n.message).toBe('success');
      expect(n.tab).toBe('cron');
      expect(getSetting('mobo_core_real_cron_max_rounds')).toBe('50');
      expect(getSetting('mobo_core_real_cron_time_budget_seconds')).toBe('10');
      expect(getSetting('mobo_core_self_runner_min_interval_seconds')).toBe('10');
    } finally {
      await gotoTab(page, 'cron');
      await restoreForm(settingsForm(page, 'cron'), snap);
      expect((await submitForm(page, settingsForm(page, 'cron'))).type).toBe('success');
      const original = snap.find((s) => s.name === 'mobo_core_real_cron_max_rounds');
      if (original) expect(getSetting('mobo_core_real_cron_max_rounds')).toBe(original.value);
    }
  });

  test('"اجرای دستی یک Slice واقعی Worker" runs one worker slice', async ({ page }) => {
    await gotoTab(page, 'cron');
    const btn = page.locator('[data-mobo-admin-tool-action="mobo_core_tool_run_cron_now"]');
    await expect(btn).toHaveText('اجرای دستی یک Slice واقعی Worker');
    const before = getOption('mobo_core_real_cron_last_result');
    const n = await clickAndReadNotice(page, btn, 170_000);
    expect(n.tab).toBe('cron');
    const m = n.message.match(/وضعیت: ([a-z0-9_-]+)/);
    expect(m, n.message).not.toBeNull();
    const status = m![1];
    expect(n.type).toBe(status === 'ok' ? 'success' : status === 'partial' ? 'warning' : 'error');
    expect(n.message).toContain('یک Slice واقعی Worker به‌صورت دستی اجرا شد');
    const after = getOption('mobo_core_real_cron_last_result');
    expect(after, 'runner recorded its result').toBeTruthy();
    expect(after).not.toBe(before);
  });
});
