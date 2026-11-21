/**
 * Product settings tab (save_settings product, A:9045) and the WooCommerce
 * Products list integrations: ownership filter (A:393 / A:41) and the per-product
 * pricing override modal + ajax save (A:726 / A:753 / A:789).
 */
import { Page } from '@playwright/test';
import { test, expect } from '../lib/fixtures';
import { boolValue, expectNoPhpError, gotoTab, setBool, settingsForm, submitForm, toAsciiDigits } from '../lib/admin';
import { getSetting, postMeta } from '../lib/db';


const PRODUCT_BOOLS = [
  'global_product_auto_stock',
  'global_product_auto_price',
  'global_product_auto_compare_price',
  'mobo_core_only_in_stock',
  'global_product_auto_title',
  'global_product_auto_slug',
  'global_update_images',
  'mobo_core_cache_warmup_enabled',
];

test.describe('Product settings tab', () => {
  for (const key of PRODUCT_BOOLS) {
    test(`toggle ${key} → "ذخیره تنظیمات" persists it`, async ({ page }) => {
      await gotoTab(page, 'product');
      const form = settingsForm(page, 'product');
      const original = (await boolValue(form, key)) ?? '0';
      const target = original === '1' ? '0' : '1';
      try {
        await setBool(form, key, target);
        const n = await submitForm(page, form);
        expect(n.type, n.message).toBe('success');
        expect(n.message).toBe('تنظیمات همین تب ذخیره شد.');
        expect(getSetting(key), `${key} stored`).toBe(target);
        await expect(settingsForm(page, 'product').locator(`#${key}_${target === '1' ? 'on' : 'off'}`)).toBeChecked();
      } finally {
        await gotoTab(page, 'product');
        await setBool(settingsForm(page, 'product'), key, original as '0' | '1');
        expect((await submitForm(page, settingsForm(page, 'product'))).type).toBe('success');
        expect(getSetting(key)).toBe(original);
      }
    });
  }

  test('cache warmup policy select persists', async ({ page }) => {
    await gotoTab(page, 'product');
    const select = settingsForm(page, 'product').locator('select[name="mobo_core_cache_warmup_policy"]');
    const original = await select.inputValue();
    const target = original === 'all' ? 'selective' : 'all';
    try {
      await select.selectOption(target);
      expect((await submitForm(page, settingsForm(page, 'product'))).type).toBe('success');
      expect(getSetting('mobo_core_cache_warmup_policy')).toBe(target);
    } finally {
      await gotoTab(page, 'product');
      await settingsForm(page, 'product').locator('select[name="mobo_core_cache_warmup_policy"]').selectOption(original);
      expect((await submitForm(page, settingsForm(page, 'product'))).type).toBe('success');
      expect(getSetting('mobo_core_cache_warmup_policy')).toBe(original);
    }
  });
});

async function displayedTotal(page: Page): Promise<number> {
  const txt = await page.locator('.tablenav.top .displaying-num').first().textContent().catch(() => null);
  if (!txt) return await page.locator('#the-list tr.type-product').count();
  return Number(toAsciiDigits(txt).replace(/[^0-9]/g, '')) || 0;
}

async function filterProducts(page: Page, value: '' | 'mobo' | 'non_mobo'): Promise<void> {
  await page.goto('/wp-admin/edit.php?post_type=product');
  await expectNoPhpError(page);
  const select = page.locator('#mobo-core-product-source-filter');
  await expect(select).toBeVisible();
  test.skip(await select.isDisabled(), 'Mobo identity authority unavailable: the filter is intentionally disabled');
  await select.selectOption(value);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#post-query-submit').click()]);
  await expectNoPhpError(page);
}

test.describe('Products list: Mobo ownership filter', () => {
  test('"محصولات موبویی" / "غیر موبویی" filters partition the product list', async ({ page }) => {
    await filterProducts(page, '');
    const all = await displayedTotal(page);

    await filterProducts(page, 'mobo');
    await expect(page).toHaveURL(/mobo_core_product_source=mobo/);
    await expect(page.locator('#mobo-core-product-source-filter')).toHaveValue('mobo');
    const mobo = await displayedTotal(page);
    const moboRows = page.locator('#the-list tr.type-product');
    const moboVisible = await moboRows.count();
    for (let i = 0; i < Math.min(moboVisible, 20); i++) {
      await expect(moboRows.nth(i).locator('.mobo-product-state'), 'every row is a Mobo product').toHaveCount(1);
    }

    await filterProducts(page, 'non_mobo');
    await expect(page).toHaveURL(/mobo_core_product_source=non_mobo/);
    const nonMobo = await displayedTotal(page);
    const nonRows = page.locator('#the-list tr.type-product');
    const nonVisible = await nonRows.count();
    for (let i = 0; i < Math.min(nonVisible, 20); i++) {
      await expect(nonRows.nth(i).locator('.mobo-product-state'), 'no row is a Mobo product').toHaveCount(0);
    }

    expect(mobo + nonMobo, 'mobo + non-mobo == all').toBe(all);
  });
});

test.describe('Products list: per-product pricing override modal', () => {
  async function openModalForFirstProduct(page: Page): Promise<{ productId: number }> {
    await filterProducts(page, 'mobo');
    const action = page.locator('a.mobo-pricing-row-action').first();
    test.skip((await action.count()) === 0, 'no Mobo product with a mutation-ready owner Connection on this site');
    const row = page.locator('#the-list tr', { has: action }).first();
    await row.hover();
    const productId = Number(await action.getAttribute('data-product-id'));
    await action.click();
    await expect(page.locator('#mobo-pricing-modal')).toBeVisible();
    await expect(page.locator('#mobo-pricing-title')).toHaveText('قیمت‌گذاری اختصاصی موبو');
    return { productId };
  }

  test('out-of-range percentage is rejected client-side; "انصراف" closes the modal', async ({ page }) => {
    await openModalForFirstProduct(page);
    let ajaxCalls = 0;
    page.on('request', (r) => {
      if (r.url().includes('admin-ajax.php') && (r.postData() || '').includes('mobo_core_save_product_pricing_override')) ajaxCalls++;
    });
    await page.locator('input[name="mobo_pricing_mode"][value="custom"]').check();
    await page.locator('#mobo-pricing-value').fill('20000');
    await page.locator('#mobo-pricing-save').click();
    await expect(page.locator('#mobo-pricing-message')).toHaveText('درصد سود باید عددی بین ۰ تا ۱۰۰۰۰ باشد.');
    expect(ajaxCalls).toBe(0);
    await page.locator('#mobo-pricing-cancel').click();
    await expect(page.locator('#mobo-pricing-modal')).toBeHidden();
  });

  test('"ذخیره و قیمت‌گذاری مجدد" saves a custom percentage, then global restores it', async ({ page }) => {
    const { productId } = await openModalForFirstProduct(page);
    const originalEnabled = postMeta(productId, '_mobo_pricing_override_enabled');
    const originalValue = postMeta(productId, '_mobo_pricing_override_value');
    const value = '12.5';

    await page.locator('input[name="mobo_pricing_mode"][value="custom"]').check();
    await page.locator('#mobo-pricing-value').fill(value);
    const [resp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('mobo_core_save_product_pricing_override')),
      page.locator('#mobo-pricing-save').click(),
    ]);
    const body = await resp.json();
    expect(body.success, JSON.stringify(body)).toBe(true);
    await expect(page.locator('#mobo-pricing-message')).toContainText(body.data.message);
    if (body.data.status === 'applied') {
      expect(postMeta(productId, '_mobo_pricing_override_enabled')).toBe('1');
      expect(Number(postMeta(productId, '_mobo_pricing_override_value'))).toBeCloseTo(12.5, 4);
      await page.waitForLoadState('load'); // modal reloads the list
      await expect(page.locator(`#post-${productId} .mobo-pricing-state`)).toContainText('12.5');
    } else {
      expect(body.data.status).toBe('queued');
      expect(postMeta(productId, '_mobo_pricing_override_pending'), 'durable pending request stored').toBeTruthy();
    }

    // Restore: global mode clears the product override (unless one existed before).
    await filterProducts(page, 'mobo');
    const target = page.locator(`a.mobo-pricing-row-action[data-product-id="${productId}"]`);
    await page.locator('#the-list tr', { has: target }).hover();
    await target.click();
    await expect(page.locator('#mobo-pricing-modal')).toBeVisible();
    if (originalEnabled === '1' && originalValue) {
      await page.locator('input[name="mobo_pricing_mode"][value="custom"]').check();
      await page.locator('#mobo-pricing-value').fill(originalValue);
    } else {
      await page.locator('input[name="mobo_pricing_mode"][value="global"]').check();
    }
    const [restore] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('mobo_core_save_product_pricing_override')),
      page.locator('#mobo-pricing-save').click(),
    ]);
    const rb = await restore.json();
    expect(rb.success, JSON.stringify(rb)).toBe(true);
    if (rb.data.status === 'applied' && originalEnabled !== '1') {
      expect(postMeta(productId, '_mobo_pricing_override_enabled') || '').not.toBe('1');
    }
  });

  test('ajax mobo_core_save_product_pricing_override rejects an invalid nonce', async ({ page }) => {
    await page.goto('/wp-admin/edit.php?post_type=product');
    const res = await page.request.post('/wp-admin/admin-ajax.php', {
      form: { action: 'mobo_core_save_product_pricing_override', nonce: 'invalid', product_id: '1', mode: 'global', value: '' },
    });
    expect(res.status()).toBe(403);
  });
});
