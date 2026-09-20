import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';

const target = process.env.BROWSER_SMOKE_URL || 'https://shopvivaliz.com.br';
const artifactDir = process.env.BROWSER_ARTIFACT_DIR || '/var/lib/shopvivaliz-browser/artifacts/smoke';
await fs.mkdir(artifactDir, { recursive: true });

const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage({ viewport: { width: 1365, height: 768 } });
  const response = await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 45000 });
  if (!response || response.status() >= 400) {
    throw new Error(`navigation failed status=${response?.status() ?? 'none'}`);
  }
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  const title = (await page.title()).trim();
  if (!title) throw new Error('empty page title');
  const screenshot = path.join(artifactDir, 'shopvivaliz-home.png');
  await page.screenshot({ path: screenshot, fullPage: false });
  console.log(JSON.stringify({
    ok: true,
    status: response.status(),
    title_present: true,
    screenshot_present: true
  }));
} finally {
  await browser.close();
}
