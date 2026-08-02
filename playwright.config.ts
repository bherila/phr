import { defineConfig, devices } from '@playwright/test'

import { e2eEnv } from './tests/e2e/support/environment'

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'line',
  globalSetup: './tests/e2e/support/global-setup.ts',
  outputDir: 'test-results',
  use: {
    baseURL: e2eEnv.APP_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  webServer: [
    {
      command: 'node tests/e2e/support/oauth-provider.mjs',
      url: 'http://127.0.0.1:4174/health',
      reuseExistingServer: !process.env.CI,
      timeout: 30_000,
    },
    {
      command: 'php artisan config:clear --no-interaction && php artisan serve --host=127.0.0.1 --port=4173 --no-reload',
      url: `${e2eEnv.APP_URL}/up`,
      env: e2eEnv,
      reuseExistingServer: false,
      timeout: 30_000,
    },
  ],
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
