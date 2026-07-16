// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
    testDir: __dirname,
    testMatch: ['*.spec.js'],
    timeout: 30_000,
    retries: 1,
    reporter: [['line'], ['json', { outputFile: 'reports/playwright-results.json' }]],
    use: {
        baseURL: process.env.BASE_URL || 'https://shopvivaliz.com.br',
        headless: true,
        screenshot: 'only-on-failure',
        video: 'off',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
