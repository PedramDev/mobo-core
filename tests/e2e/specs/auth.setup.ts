/**
 * Global setup: guard the target, log in once as the staging admin and save
 * storageState for every spec. The password is read at runtime and never logged.
 */
import * as fs from 'fs';
import * as path from 'path';
import { test as setup, expect } from '../lib/fixtures';
import { BASE_URL, STORAGE_STATE, adminCredentials, assertLocalUrl } from '../lib/env';

setup('log in to wp-admin and save storageState', async ({ page }) => {
  assertLocalUrl(BASE_URL, 'BASE_URL');
  const { user, password } = adminCredentials();

  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(password);
  await Promise.all([page.waitForURL(/\/wp-admin\//, { timeout: 120_000 }), page.locator('#wp-submit').click()]);

  await expect(page.locator('#wpadminbar')).toBeVisible();
  // The test admin must see the MoboCore top-level menu (manage_options).
  await expect(page.locator('#adminmenu a[href*="page=mobo-core"]').first()).toBeAttached();

  fs.mkdirSync(path.dirname(STORAGE_STATE), { recursive: true });
  await page.context().storageState({ path: STORAGE_STATE });
});
