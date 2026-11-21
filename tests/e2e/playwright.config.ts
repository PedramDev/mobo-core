import { defineConfig } from '@playwright/test';
import { BASE_URL, STORAGE_STATE, assertLocalUrl } from './lib/env';

// Fails the whole run (including --list) unless BASE_URL is loopback.
assertLocalUrl(BASE_URL, 'BASE_URL');

export default defineConfig({
  testDir: './specs',
  // All specs mutate one shared WordPress site: run strictly serially.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 180_000,
  expect: { timeout: 20_000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: BASE_URL,
    channel: 'msedge',
    headless: process.env.HEADED !== '1',
    locale: 'fa-IR',
    viewport: { width: 1440, height: 1000 },
    actionTimeout: 30_000,
    navigationTimeout: 120_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
    },
    {
      name: 'admin',
      testMatch: /.*\.spec\.ts/,
      dependencies: ['setup'],
      use: { storageState: STORAGE_STATE },
    },
  ],
});
