import fs from 'node:fs';

const CDP_URL = 'http://127.0.0.1:9555';
const TARGET_URL = 'https://platform.openai.com/settings/organization/tunnels';
const playwrightCandidates = [
  '/home/ubuntu/shopvivaliz-browser-worker/node_modules/playwright-core/index.js',
  '/home/ubuntu/shopvivaliz-deploy/repo/node_modules/playwright/index.js',
  '/home/ubuntu/solange-rolla-consultorio/node_modules/playwright/index.js',
];

function safeTag(value) {
  return String(value || 'probe_failed')
    .replace(/[^A-Za-z0-9_.-]+/g, '_')
    .slice(0, 100);
}

let page = null;
try {
  let chromium = null;
  for (const candidate of playwrightCandidates) {
    if (!fs.existsSync(candidate)) continue;
    const mod = await import('file://' + candidate);
    chromium = mod.chromium || mod.default?.chromium;
    if (chromium) break;
  }
  if (!chromium) throw new Error('playwright_runtime_missing');

  const browser = await chromium.connectOverCDP(CDP_URL);
  const contexts = browser.contexts();
  if (!contexts.length) throw new Error('cdp_context_missing');

  page = await contexts[0].newPage();
  page.setDefaultTimeout(15000);
  await page.goto(TARGET_URL, { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.waitForTimeout(3500);

  const currentUrl = page.url();
  const passwordInput = await page.locator('input[type="password"]').count();
  const authenticated = !/auth\.openai\.com|\/login(?:[/?#]|$)/i.test(currentUrl) && passwordInput === 0;

  let bodyText = '';
  if (authenticated) {
    bodyText = await page.locator('body').innerText({ timeout: 8000 }).catch(() => '');
  }

  const accessRequired = /tunnels? access required|access to tunnels? is required|insufficient permissions?|permission required/i.test(bodyText);
  const createButton = page.getByRole('button', { name: /create tunnel|new tunnel|add tunnel/i }).first();
  const createLink = page.getByRole('link', { name: /create tunnel|new tunnel|add tunnel/i }).first();
  const manageAvailable = authenticated && !accessRequired && (
    (await createButton.count()) > 0 || (await createLink.count()) > 0
  );

  const ids = bodyText.match(/tunnel_[0-9a-f]{32}/gi) || [];
  const existingCount = new Set(ids.map(value => value.toLowerCase())).size;

  console.log('OPENAI_TUNNEL_UI_AUTHENTICATED=' + String(authenticated));
  console.log('OPENAI_TUNNEL_UI_MANAGE_AVAILABLE=' + String(manageAvailable));
  console.log('OPENAI_TUNNEL_UI_ACCESS_REQUIRED=' + String(accessRequired));
  console.log('OPENAI_TUNNEL_UI_EXISTING_COUNT=' + String(existingCount));
  console.log('OPENAI_TUNNEL_UI_PROBE=PASS');
} catch (error) {
  console.log('OPENAI_TUNNEL_UI_PROBE=FAIL blocker=' + safeTag(error?.message));
  process.exitCode = 2;
} finally {
  if (page) await page.close().catch(() => {});
  process.exit(process.exitCode || 0);
}
