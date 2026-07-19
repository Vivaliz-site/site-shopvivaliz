import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  forbidOnly: false,
  retries: 0,
  workers: 1,
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
  ],
  use: {
    baseURL: process.env.E2E_BASE_URL || 'https://shopvivaliz.com.br',
    actionTimeout: 10 * 1000,
    navigationTimeout: 25 * 1000,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],

  webServer: undefined,
  // Must finish before the 15-minute GitHub Actions job timeout. Individual
  // pages must fail fast instead of hanging forever on `networkidle`.
  globalTimeout: 12 * 60 * 1000,
  timeout: 45 * 1000,
});
