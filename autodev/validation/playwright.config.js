// @ts-check
/**
 * AutoDev – Playwright Configuration
 *
 * Shared configuration for all AutoDev E2E and regression tests.
 */

const { defineConfig } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost';

module.exports = defineConfig({
  // Directory that contains test files
  testDir: './',

  // Output directory for screenshots, videos, and traces
  outputDir: '../../test-results',

  // Run tests in parallel inside a single worker by default
  fullyParallel: false,

  // Retry once on CI, to tolerate transient flakiness
  retries: 1,

  // Timeout per test (ms)
  timeout: 30_000,

  // Number of parallel workers
  workers: process.env.CI ? 1 : undefined,

  // Reporters: HTML report (saved to playwright-report/) + line output to terminal
  reporter: [
    ['html', { outputFolder: '../../playwright-report', open: 'never' }],
    ['line'],
  ],

  // Shared settings applied to all tests
  use: {
    // Base URL for page.goto('/path') calls
    baseURL: BASE_URL,

    // Run headless (set HEADED=1 to override during local debugging)
    headless: process.env.HEADED !== '1',

    // Capture screenshot only when a test fails
    screenshot: 'only-on-failure',

    // Do not record video (keeps test runs fast)
    video: 'off',

    // Capture trace on first retry to aid debugging
    trace: 'on-first-retry',

    // Default navigation timeout
    navigationTimeout: 30_000,

    // Default action timeout (clicks, fills, etc.)
    actionTimeout: 10_000,

    // Viewport
    viewport: { width: 1280, height: 720 },

    // Accept all languages from server
    locale: 'pt-BR',
    timezoneId: 'America/Sao_Paulo',
  },
});
