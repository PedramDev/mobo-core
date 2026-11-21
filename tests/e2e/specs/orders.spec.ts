/**
 * WooCommerce order screen: "ثبت سفارش در موبو" meta box (CV:6902) —
 * retry_mobo_order_submission (CV:6913) and clear_mobo_order_log (CV:7052).
 * Both handlers redirect back to the order; effects are order notes / order meta.
 * Works with HPOS (admin.php?page=wc-orders) and legacy post storage.
 */
import { Page } from '@playwright/test';
import { test, expect } from '../lib/fixtures';
import { expectNoPhpError, reveal } from '../lib/admin';
import { hposEnabled, latestOrderNote, orderMeta, orderNoteCount, query, t } from '../lib/db';


function editUrl(id: number): string {
  return hposEnabled() ? `/wp-admin/admin.php?page=wc-orders&action=edit&id=${id}` : `/wp-admin/post.php?post=${id}&action=edit`;
}

/** Orders that carry a given meta key (most recent first). */
function ordersWithMeta(key: string, limit = 10): number[] {
  const rows = hposEnabled()
    ? query(`SELECT DISTINCT order_id AS id FROM ${t('wc_orders_meta')} WHERE meta_key = '${key}' ORDER BY order_id DESC LIMIT ${limit}`)
    : query(`SELECT DISTINCT post_id AS id FROM ${t('postmeta')} WHERE meta_key = '${key}' ORDER BY post_id DESC LIMIT ${limit}`);
  return rows.map((r) => Number(r.id));
}

/** Processing orders not yet submitted to Mobo (candidates for the retry control). */
function processingCandidates(limit = 15): number[] {
  const rows = hposEnabled()
    ? query(
        `SELECT o.id FROM ${t('wc_orders')} o WHERE o.type = 'shop_order' AND o.status = 'wc-processing' AND NOT EXISTS (SELECT 1 FROM ${t('wc_orders_meta')} m WHERE m.order_id = o.id AND m.meta_key = '_mobo_order_id' AND m.meta_value <> '') ORDER BY o.id DESC LIMIT ${limit}`,
      )
    : query(
        `SELECT p.ID AS id FROM ${t('posts')} p WHERE p.post_type = 'shop_order' AND p.post_status = 'wc-processing' AND NOT EXISTS (SELECT 1 FROM ${t('postmeta')} m WHERE m.post_id = p.ID AND m.meta_key = '_mobo_order_id' AND m.meta_value <> '') ORDER BY p.ID DESC LIMIT ${limit}`,
      );
  return rows.map((r) => Number(r.id));
}

async function openOrder(page: Page, id: number) {
  await page.goto(editUrl(id));
  await expectNoPhpError(page);
  const box = page.locator('#mobo_core_order_submission_box');
  await expect(box, 'Mobo meta box registered on the order screen').toBeVisible();
  return box;
}

test.describe('Order screen: Mobo submission meta box', () => {
  test('meta box renders the Mobo submission state', async ({ page }) => {
    const ids = [...processingCandidates(1), ...ordersWithMeta('_mobo_order_submission_log', 1)];
    test.skip(ids.length === 0, 'no WooCommerce orders on this site');
    const box = await openOrder(page, ids[0]);
    await expect(box.locator('h2, .hndle').first()).toContainText('ثبت سفارش در موبو');
    await expect(box.locator('#mobo-order-admin-state')).toContainText('وضعیت موبو:');
  });

  test('"ارسال به موبو" / "ارسال مجدد به موبو" submits the order and records the attempt', { tag: '@backend' }, async ({ page }) => {
    let orderId = 0;
    for (const id of processingCandidates()) {
      const box = await openOrder(page, id);
      if ((await box.locator('form:has(input[name="action"][value="mobo_core_retry_mobo_order_submission"]) button[type="submit"]').count()) > 0) {
        orderId = id;
        break;
      }
    }
    test.skip(orderId === 0, 'no processing order currently offers the submit/retry control');

    const notesBefore = orderNoteCount(orderId);
    const logBefore = orderMeta(orderId, '_mobo_order_submission_log') || '';
    const button = page.locator('#mobo_core_order_submission_box form:has(input[name="action"][value="mobo_core_retry_mobo_order_submission"]) button[type="submit"]');
    await expect(button).toHaveText(/ارسال (مجدد )?به موبو/);
    // The handler redirects back to the same order URL: wait for the new document, not a URL change.
    await Promise.all([page.waitForEvent('load', { timeout: 170_000 }), button.click()]);
    await page.waitForLoadState('domcontentloaded');
    await expectNoPhpError(page);

    const logAfter = orderMeta(orderId, '_mobo_order_submission_log') || '';
    const notesAfter = orderNoteCount(orderId);
    const progressed = logAfter.includes('admin_manual_retry_requested') && logAfter !== logBefore;
    expect(progressed || notesAfter > notesBefore, 'retry produced an order note or a logged attempt').toBe(true);
    if (notesAfter > notesBefore) {
      const latest = latestOrderNote(orderId) || '';
      await expect(page.locator('#woocommerce-order-notes, .order_notes').first()).toContainText(latest.slice(0, 30));
    }
  });

  test('"پاک کردن لاگ فنی" deletes the order technical log only', async ({ page }) => {
    const ids = ordersWithMeta('_mobo_order_submission_log');
    test.skip(ids.length === 0, 'no order has a Mobo technical log');
    const id = ids[0];
    const statusBefore = orderMeta(id, '_mobo_order_submit_status');
    const box = await openOrder(page, id);
    const btn = box.locator('form:has(input[name="action"][value="mobo_core_clear_mobo_order_log"]) button[type="submit"]');
    await reveal(btn);
    await expect(btn).toHaveText('پاک کردن لاگ فنی');
    await Promise.all([page.waitForEvent('load'), btn.click()]);
    await page.waitForLoadState('domcontentloaded');
    expect(orderMeta(id, '_mobo_order_submission_log'), 'log meta deleted').toBeNull();
    expect(orderMeta(id, '_mobo_order_submit_status'), 'submission status preserved').toBe(statusBefore);
    const box2 = page.locator('#mobo_core_order_submission_box');
    await reveal(box2.locator('details').first());
    await expect(box2).toContainText('برای این سفارش هنوز لاگ فنی موبو ثبت نشده است.');
  });
});
