import fs from 'node:fs';

const CDP_URL = 'http://127.0.0.1:9555';
const DASH_URL = 'https://dash.cloudflare.com/';
const ZERO_TRUST_URL = 'https://one.dash.cloudflare.com/';
const playwrightCandidates = [
  '/home/ubuntu/shopvivaliz-browser-worker/node_modules/playwright-core/index.js',
  '/home/ubuntu/shopvivaliz-deploy/repo/node_modules/playwright/index.js',
  '/home/ubuntu/solange-rolla-consultorio/node_modules/playwright/index.js',
];

function safeTag(value) {
  return String(value || 'probe_failed').replace(/[^A-Za-z0-9_.-]+/g, '_').slice(0, 100);
}

async function challengeRequired(page) {
  if ((await page.locator('input[type="password"]').count()) > 0) return true;
  if ((await page.locator('iframe[src*="captcha"], iframe[src*="challenge"]').count()) > 0) return true;
  const text = await page.locator('body').innerText({ timeout: 5000 }).catch(() => '');
  return /two.factor|2-step|verification code|security key|captcha|verify (?:it.?s )?you/i.test(text);
}

function looksLoggedOut(url) {
  return /(?:dash\.cloudflare\.com|one\.dash\.cloudflare\.com)\/(?:login|sign-up)|login\.cloudflare/i.test(url);
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

  await page.goto(DASH_URL, { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.waitForTimeout(3500);
  let challenge = await challengeRequired(page);
  let authenticated = !looksLoggedOut(page.url()) && !challenge;

  let zeroTrustAvailable = false;
  let manageAvailable = false;

  if (authenticated) {
    const zeroTrustLink = page.locator('a[href*="one.dash.cloudflare.com"], a').filter({ hasText: /Zero Trust/i }).first();
    zeroTrustAvailable = (await zeroTrustLink.count()) > 0;

    await page.goto(ZERO_TRUST_URL, { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {});
    await page.waitForTimeout(4000);
    challenge = challenge || await challengeRequired(page);
    const zeroUrl = page.url();
    const zeroAuthenticated = !looksLoggedOut(zeroUrl) && !challenge;
    if (zeroAuthenticated) {
      const bodyText = await page.locator('body').innerText({ timeout: 7000 }).catch(() => '');
      zeroTrustAvailable = zeroTrustAvailable || /Zero Trust|Access|Networks|Gateway/i.test(bodyText);
      const addButton = page.getByRole('button', { name: /add (?:an )?application|add application|create application/i }).first();
      const addLink = page.getByRole('link', { name: /add (?:an )?application|add application|create application/i }).first();
      manageAvailable = (await addButton.count()) > 0 || (await addLink.count()) > 0;
      if (!manageAvailable) {
        const accessLinks = await page.locator('a[href*="/access/"]').count();
        zeroTrustAvailable = zeroTrustAvailable || accessLinks > 0;
      }
    } else {
      authenticated = false;
    }
  }

  console.log('CLOUDFLARE_UI_AUTHENTICATED=' + String(authenticated));
  console.log('CLOUDFLARE_ZERO_TRUST_AVAILABLE=' + String(zeroTrustAvailable));
  console.log('CLOUDFLARE_ACCESS_MANAGE_AVAILABLE=' + String(manageAvailable));
  console.log('CLOUDFLARE_UI_AUTH_CHALLENGE_REQUIRED=' + String(challenge));
  console.log('CLOUDFLARE_UI_PROBE=PASS');
} catch (error) {
  console.log('CLOUDFLARE_UI_AUTHENTICATED=false');
  console.log('CLOUDFLARE_ZERO_TRUST_AVAILABLE=false');
  console.log('CLOUDFLARE_ACCESS_MANAGE_AVAILABLE=false');
  console.log('CLOUDFLARE_UI_AUTH_CHALLENGE_REQUIRED=false');
  console.log('CLOUDFLARE_UI_PROBE=FAIL blocker=' + safeTag(error?.message));
  process.exitCode = 2;
} finally {
  if (page) await page.close().catch(() => {});
  process.exit(process.exitCode || 0);
}
