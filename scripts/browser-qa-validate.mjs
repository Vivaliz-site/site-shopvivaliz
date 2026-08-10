import { mkdirSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const baseUrl = process.env.E2E_BASE_URL || 'https://shopvivaliz.com.br';
const userDataDir = process.env.PLAYWRIGHT_USER_DATA_DIR || '';
const outDir = process.env.PLAYWRIGHT_ARTIFACTS_DIR || join(process.cwd(), 'artifacts', 'browser-qa');
const targets = [
  { name: 'admin-home', url: `${baseUrl}/admin/` },
  { name: 'ai-studio', url: `${baseUrl}/admin/ai-image-studio/admin_dashboard.php` },
  { name: 'catalog-optimization', url: `${baseUrl}/admin/catalog-optimization/admin_catalog.php` },
];

mkdirSync(outDir, { recursive: true });

const launchOptions = { headless: true };
let context;
let browser;

if (userDataDir && existsSync(userDataDir)) {
  context = await chromium.launchPersistentContext(userDataDir, {
    ...launchOptions,
    viewport: { width: 1440, height: 1200 },
  });
} else {
  browser = await chromium.launch(launchOptions);
  context = await browser.newContext({ viewport: { width: 1440, height: 1200 } });
}

const results = [];
for (const target of targets) {
  const page = await context.newPage();
  try {
    await page.goto(target.url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.screenshot({ path: join(outDir, `${target.name}.png`), fullPage: true });
    results.push({
      name: target.name,
      url: page.url(),
      title: await page.title(),
      h1: await page.locator('h1').first().textContent().catch(() => ''),
    });
  } finally {
    await page.close();
  }
}

if (browser) {
  await browser.close();
} else {
  await context.close();
}

console.log(JSON.stringify({
  ok: true,
  baseUrl,
  outDir,
  results,
}, null, 2));
