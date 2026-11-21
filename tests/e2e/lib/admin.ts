/**
 * UI helpers for the MoboCore admin (page=mobo-core). Selectors prefer stable
 * form contracts (hidden `action` / `mobo_active_tab` inputs, field names) and
 * Persian button text over CSS layout classes.
 */
import { Locator, Page, TestInfo, test } from '@playwright/test';
import { expect } from './fixtures';

export type Notice = { type: string; message: string; tab: string | null };

export const ADVANCED_TABS = ['filters', 'queue', 'image-refresh', 'cron', 'checkout', 'sms'] as const;
export const MAIN_TABS = ['dashboard', 'connection', 'purchase', 'product', 'categories', 'pricing', 'sync-health', 'health', 'support'] as const;

/** Persian/Arabic-Indic digits -> ASCII; strips thousands separators. */
export function toAsciiDigits(s: string): string {
  return s
    .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
    .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
    .replace(/[٬,]/g, '');
}

export async function expectNoPhpError(page: Page): Promise<void> {
  const body = page.locator('body');
  await expect(body).not.toContainText('There has been a critical error');
  await expect(body).not.toContainText(/Fatal error:|Parse error:/);
}

export function tabUrl(tab: string, extra: Record<string, string> = {}): string {
  const params = new URLSearchParams({ page: 'mobo-core', ...(tab === 'dashboard' ? {} : { tab }), ...extra });
  if ((ADVANCED_TABS as readonly string[]).includes(tab)) params.set('view', 'advanced');
  return '/wp-admin/admin.php?' + params.toString();
}

export async function gotoTab(page: Page, tab: string, extra: Record<string, string> = {}): Promise<void> {
  const res = await page.goto(tabUrl(tab, extra));
  expect(res?.status(), `HTTP status of ${tab} tab`).toBeLessThan(500);
  await expect(page.locator('#wpadminbar')).toBeVisible();
  await expectNoPhpError(page);
}

/** The form that posts `action=<action>` to admin-post.php. */
export function actionForm(page: Page | Locator, action: string): Locator {
  return page.locator(`form:has(input[name="action"][value="${action}"])`);
}

/** The settings form for a given `mobo_active_tab`. */
export function settingsForm(page: Page, tab: string): Locator {
  return page.locator(`form:has(input[name="mobo_active_tab"][value="${tab}"])`);
}

/** Skip (with a reason recorded in the report) when a state-dependent control is not rendered. */
export async function requireRendered(loc: Locator, reason: string): Promise<void> {
  const n = await loc.count();
  test.skip(n === 0, `Control not rendered in the current site state: ${reason}`);
}

/** Open every collapsed <details> ancestor so the control becomes clickable (pure disclosure, no side effect). */
export async function reveal(loc: Locator): Promise<void> {
  await loc.first().evaluate((el) => {
    let d: HTMLDetailsElement | null = el.closest('details');
    while (d) {
      d.open = true;
      d = d.parentElement ? d.parentElement.closest('details') : null;
    }
  });
  await loc.first().scrollIntoViewIfNeeded();
}

/** Read and assert the MoboCore redirect notice (`mobo_message` / `mobo_type`) rendered as `.mobo-alert-{type}`. */
export async function readNotice(page: Page): Promise<Notice> {
  const url = new URL(page.url());
  const message = (url.searchParams.get('mobo_message') || '').trim();
  const type = url.searchParams.get('mobo_type') || 'success';
  expect(message, 'redirect carries a mobo_message').not.toBe('');
  const cls = type === 'error' ? 'mobo-alert-error' : type === 'warning' ? 'mobo-alert-warning' : 'mobo-alert-success';
  const snippet = message.replace(/\s+/g, ' ').slice(0, 40);
  await expect(page.locator(`.mobo-alert.${cls}`).filter({ hasText: snippet }).first(), `notice "${snippet}" rendered`).toBeVisible();
  await expectNoPhpError(page);
  return { type, message, tab: url.searchParams.get('tab') };
}

/** Click a control that triggers an admin-post redirect, then return the rendered notice. */
export async function clickAndReadNotice(page: Page, control: Locator, timeout = 90_000): Promise<Notice> {
  await reveal(control);
  await Promise.all([page.waitForURL(/[?&]mobo_message=/, { timeout }), control.first().click()]);
  return readNotice(page);
}

/** Submit a form via its own submit button (the last one is the bottom save button). */
export async function submitForm(page: Page, form: Locator, timeout = 90_000): Promise<Notice> {
  const button = form.first().locator('button[type="submit"], input[type="submit"]').last();
  return clickAndReadNotice(page, button, timeout);
}

/** Current checked value ('1' | '0') of a MoboCore bool_field radio pair. */
export async function boolValue(scope: Locator, key: string): Promise<string | null> {
  const checked = scope.locator(`input[name="${key}"]:checked`);
  return (await checked.count()) ? checked.first().inputValue() : null;
}

/** Click the visible label of a bool_field option (روشن / خاموش). */
export async function setBool(scope: Locator, key: string, value: '0' | '1'): Promise<void> {
  const label = scope.locator(`label[for="${key}_${value === '1' ? 'on' : 'off'}"]`).first();
  await reveal(label);
  await label.click();
  await expect(scope.locator(`#${key}_${value === '1' ? 'on' : 'off'}`)).toBeChecked();
}

/** POST to admin-ajax.php with the logged-in browser session (same cookies as the page). */
export async function postAjax(page: Page, ajaxUrl: string, form: Record<string, string>): Promise<{ status: number; json: any }> {
  const res = await page.request.post(ajaxUrl, { form });
  let json: any = null;
  try {
    json = await res.json();
  } catch {
    json = null;
  }
  return { status: res.status(), json };
}

/** Read data-ajax-url / data-nonce from a status card. */
export async function cardAjax(page: Page, selector: string): Promise<{ url: string; nonce: string }> {
  const card = page.locator(selector).first();
  await expect(card).toBeAttached();
  const url = (await card.getAttribute('data-ajax-url')) || '/wp-admin/admin-ajax.php';
  const nonce = (await card.getAttribute('data-nonce')) || '';
  expect(nonce, `${selector} exposes a nonce`).not.toBe('');
  return { url, nonce };
}

export type FormSnapshot = Array<{ name: string; kind: 'radio' | 'checkbox' | 'value'; value: string; checked?: boolean }>;

/** Capture every named control of a form (used only to restore test data afterwards). */
export async function snapshotForm(form: Locator): Promise<FormSnapshot> {
  return form.first().evaluate((f) => {
    const out: any[] = [];
    f.querySelectorAll('input[name], select[name], textarea[name]').forEach((el: any) => {
      const type = (el.type || '').toLowerCase();
      if (['hidden', 'submit', 'button', 'password', 'file'].includes(type)) return;
      if (type === 'radio' || type === 'checkbox') out.push({ name: el.name, kind: type, value: el.value, checked: el.checked });
      else out.push({ name: el.name, kind: 'value', value: el.value });
    });
    return out;
  });
}

/** Put a snapshot back into the form (cleanup only; the save itself is still a real click). */
export async function restoreForm(form: Locator, snap: FormSnapshot): Promise<void> {
  await form.first().evaluate((f, items) => {
    for (const it of items as any[]) {
      const els = Array.from(f.querySelectorAll(`[name="${CSS.escape(it.name)}"]`)) as any[];
      for (const el of els) {
        if (it.kind === 'value') el.value = it.value;
        else if (el.value === it.value) el.checked = !!it.checked;
      }
    }
  }, snap);
}

export function note(testInfo: TestInfo, description: string): void {
  testInfo.annotations.push({ type: 'observed', description });
}
