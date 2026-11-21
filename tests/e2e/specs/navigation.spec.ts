/**
 * Menu (A:265), in-page tab navigation (nav.mobo-tabs), advanced view, admin-bar
 * nodes (A:345) and plugin-row action links (mobo-core.php:53). Each test clicks
 * the real link and asserts the destination renders that tab (the wrapper
 * carries `mobo-admin--{tab}`) plus a marker only that tab renders.
 */
import { Page } from '@playwright/test';
import { test, expect } from '../lib/fixtures';
import { ADVANCED_TABS, MAIN_TABS, expectNoPhpError } from '../lib/admin';

const TAB_MARKER: Record<string, string> = {
  dashboard: '#mobo-sync-status-card',
  connection: 'form:has(input[name="mobo_active_tab"][value="connection"])',
  purchase: '#mobo-purchase-title',
  product: 'form:has(input[name="mobo_active_tab"][value="product"])',
  categories: 'form:has(input[name="mobo_active_tab"][value="categories"])',
  pricing: 'form:has(input[name="mobo_active_tab"][value="pricing"])',
  'sync-health': 'h2:has-text("سلامت همگام‌سازی")',
  health: 'form:has(input[name="action"][value="mobo_core_retry_failed_webhooks"])',
  filters: 'form:has(input[name="mobo_active_tab"][value="filters"])',
  queue: 'form:has(input[name="mobo_active_tab"][value="queue"])',
  'image-refresh': '#mobo-image-refresh-live-root',
  cron: 'form:has(input[name="mobo_active_tab"][value="cron"])',
  checkout: 'form:has(input[name="mobo_active_tab"][value="checkout"])',
  sms: 'form:has(input[name="mobo_active_tab"][value="sms"])',
};

const LABELS: Record<string, string> = {
  dashboard: 'نمای کلی',
  connection: 'اتصال و لایسنس',
  purchase: 'خرید و فعال‌سازی',
  product: 'تنظیمات محصول',
  categories: 'دسته‌بندی‌ها',
  pricing: 'قیمت‌گذاری',
  'sync-health': 'سلامت همگام‌سازی',
  health: 'سلامت سایت',
  filters: 'فیلتر محصولات',
  queue: 'سرعت و صف‌ها',
  'image-refresh': 'نوسازی تصاویر',
  cron: 'Cron و Worker',
  checkout: 'سفارش موبو',
  sms: 'پیامک',
};

async function expectTab(page: Page, tab: string): Promise<void> {
  await expectNoPhpError(page);
  await expect(page.locator(`#mobo-core-admin.mobo-admin--${tab}`)).toBeVisible();
  await expect(page.locator(TAB_MARKER[tab]).first()).toBeAttached();
}

test.describe('MoboCore menu and navigation', () => {
  for (const tab of MAIN_TABS) {
    test(`side menu "${LABELS[tab]}" opens the ${tab} tab`, async ({ page }) => {
      // Start on another MoboCore page so the submenu is expanded inline (not a hover flyout).
      await page.goto(`/wp-admin/admin.php?page=mobo-core&tab=${tab === 'purchase' ? 'pricing' : 'purchase'}`);
      const href = tab === 'dashboard' ? 'a[href$="page=mobo-core"]' : `a[href*="page=mobo-core&tab=${tab}"]`;
      const link = page.locator(`#toplevel_page_mobo-core .wp-submenu ${href}`).first();
      await expect(link).toHaveText(LABELS[tab]);
      await link.click();
      await page.waitForLoadState('domcontentloaded');
      await expectTab(page, tab);
    });
  }

  test('side menu "تنظیمات پیشرفته" opens the advanced view', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=mobo-core&tab=purchase');
    const link = page.locator('#toplevel_page_mobo-core .wp-submenu a[href*="view=advanced"]').first();
    await expect(link).toHaveText('تنظیمات پیشرفته');
    await link.click();
    await expect(page).toHaveURL(/view=advanced/);
    await expectTab(page, 'filters');
  });

  for (const tab of MAIN_TABS) {
    test(`in-page tab "${LABELS[tab]}" opens the ${tab} tab`, async ({ page }) => {
      await page.goto(tab === 'dashboard' ? '/wp-admin/admin.php?page=mobo-core&tab=pricing' : '/wp-admin/admin.php?page=mobo-core');
      await page.locator('nav.mobo-tabs a', { hasText: LABELS[tab] }).first().click();
      await expectTab(page, tab);
    });
  }

  for (const tab of ADVANCED_TABS) {
    test(`advanced in-page tab "${LABELS[tab]}" opens the ${tab} tab`, async ({ page }) => {
      await page.goto('/wp-admin/admin.php?page=mobo-core');
      await page.locator('nav.mobo-tabs a.mobo-tab-view', { hasText: 'تنظیمات پیشرفته' }).click();
      await expect(page).toHaveURL(/view=advanced/);
      await page.locator('nav.mobo-tabs a', { hasText: LABELS[tab] }).first().click();
      await expect(page).toHaveURL(new RegExp(`tab=${tab}`));
      await expectTab(page, tab);
    });
  }

  test('"بازگشت به تنظیمات اصلی" leaves the advanced view', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=mobo-core&view=advanced&tab=queue');
    await page.locator('nav.mobo-tabs a.mobo-tab-view', { hasText: 'بازگشت به تنظیمات اصلی' }).click();
    await expectTab(page, 'dashboard');
    await expect(page.locator('nav.mobo-tabs')).not.toHaveClass(/mobo-tabs--advanced/);
  });

  test('admin bar "موبو" node lists every tab and its links work', async ({ page }) => {
    await page.goto('/wp-admin/index.php');
    const root = page.locator('#wp-admin-bar-mobo-core');
    await expect(root).toBeVisible();
    await expect(page.locator('#wp-admin-bar-mobo-core > a .ab-label')).toHaveText('موبو');
    for (const tab of MAIN_TABS) {
      await expect(page.locator(`#wp-admin-bar-mobo-core-${tab} > a`)).toHaveText(LABELS[tab]);
    }
    await expect(page.locator('#wp-admin-bar-mobo-core-advanced > a')).toHaveText('تنظیمات پیشرفته');

    await root.hover();
    await page.locator('#wp-admin-bar-mobo-core-pricing > a').click();
    await expectTab(page, 'pricing');

    await page.locator('#wp-admin-bar-mobo-core').hover();
    await page.locator('#wp-admin-bar-mobo-core-advanced > a').click();
    await expect(page).toHaveURL(/view=advanced/);
    await expectTab(page, 'filters');

    await page.locator('#wp-admin-bar-mobo-core > a').click();
    await expectTab(page, 'dashboard');
  });

  test('plugin row links "تنظیمات موبو" and "سلامت سایت" open the plugin pages', async ({ page }) => {
    await page.goto('/wp-admin/plugins.php');
    const row = page.locator('tr[data-plugin$="mobo-core.php"]').first();
    await expect(row).toBeVisible();
    await row.locator('.row-actions a', { hasText: 'تنظیمات موبو' }).click();
    await expectTab(page, 'dashboard');

    await page.goto('/wp-admin/plugins.php');
    await page.locator('tr[data-plugin$="mobo-core.php"] .row-actions a', { hasText: 'سلامت سایت' }).click();
    await expect(page).toHaveURL(/tab=health/);
    await expectTab(page, 'health');
  });
});
