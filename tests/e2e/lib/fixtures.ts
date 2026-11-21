/**
 * Shared test fixture:
 *  - auto-accepts confirm() dialogs (destructive buttons ask first) and records them;
 *  - aborts every request to a non-loopback host (tests must never leave the machine);
 *  - auto-skips @backend tests when the local Backend is down (set
 *    MOBO_REQUIRE_BACKEND=1 to make them fail instead).
 */
import { test as base, expect } from '@playwright/test';
import { isLocalHost } from './env';
import { backendUp } from './services';

type Fixtures = {
  dialogs: string[];
  blockedHosts: string[];
};

let backendState: boolean | null = null;

export const test = base.extend<Fixtures>({
  blockedHosts: async ({ context }, use) => {
    const blocked: string[] = [];
    await context.route('**/*', async (route) => {
      const url = route.request().url();
      let host = '';
      let protocol = '';
      try {
        const u = new URL(url);
        host = u.hostname;
        protocol = u.protocol;
      } catch {
        return route.abort();
      }
      if (/^https?:$/.test(protocol) && !isLocalHost(host)) {
        blocked.push(host);
        return route.abort('blockedbyclient');
      }
      return route.continue();
    });
    await use(blocked);
  },

  // eslint-disable-next-line no-empty-pattern
  dialogs: async ({}, use) => {
    await use([]);
  },

  page: async ({ page, blockedHosts, dialogs }, use, testInfo) => {
    void blockedHosts;
    page.on('dialog', async (dialog) => {
      dialogs.push(dialog.message());
      await dialog.accept();
    });
    if (testInfo.tags.includes('@backend') || /@backend\b/.test(testInfo.title)) {
      if (backendState === null) backendState = await backendUp();
      if (!backendState && process.env.MOBO_REQUIRE_BACKEND !== '1') {
        testInfo.skip(true, 'Local Backend (127.0.0.1:5080) is not reachable; @backend test skipped.');
      }
    }
    await use(page);
  },
});

export { expect };
