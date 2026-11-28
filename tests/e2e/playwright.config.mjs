import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: '.',
  testMatch: '**/*.spec.mjs',
  workers: 1,
  timeout: 60_000,
  reporter: [['list']],
  use: { baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', locale: 'fr-FR', screenshot: 'only-on-failure', trace: 'retain-on-failure', viewport: { width: 1440, height: 900 } },
});
