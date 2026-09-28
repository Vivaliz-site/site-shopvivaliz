import fs from 'node:fs';

const CDP_URL = 'http://127.0.0.1:9555';
const TARGET_URL = 'https://platform.openai.com/settings/organization/tunnels';
const playwrightCandidates = [
  '/home/ubuntu/shopvivaliz-browser-worker/node_modules/playwright-core/index.js',
  '/home/ubuntu/shopvivaliz-deploy/repo/node_modules/playwright/index.js',
  '/home/ubuntu/solange-rolla-consultorio/node_modules/playwright/index.js',
];

function safeTag(value) {
  return String(value || 'auth_failed').replace(/[^A-Za-z0-9_.-]+/g, '_').slice(0, 100);
}

async function isAuthenticated(page) {
  const url = page.url();
  const password = await page.locator('input[type="password"]').count();
  return !/auth\.openai\.com|accounts\.google\.com|\/login(?:[/?#]|$)/i.test(url) && password === 0;
}

async function challengeRequired(page) {
  if ((await page.locator('input[type="password"]').count()) > 0) return true;
  if ((await page.locator('iframe[src*="recaptcha"], iframe[src*="captcha"]').count()) > 0) return true;
  const text = await page.locator('body').innerText({ timeout: 5000 }).catch(() => '');
  return /2-Step Verification|Verify it.?s you|Enter (?:the )?(?:verification )?code|Try another way|captcha|security key/i.test(text);
}

async function tunnelManageAvailable(page) {
  const text = await page.locator('body').innerText({ timeout: 8000 }).catch(() => '');
  if (/tunnels? access required|access to tunnels? is required|insufficient permissions?|permission required/i.test(text)) return false;
  const button = page.getByRole('button', { name: /create tunnel|new tunnel|add tunnel/i }).first();
  const link = page.getByRole('link', { name: /create tunnel|new tunnel|add tunnel/i }).first();
  return (await button.count()) > 0 || (await link.count()) > 0;
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
  await page.waitForTimeout(2500);

  let authenticated = await isAuthenticated(page);
  let challenge = false;

  if (!authenticated) {
    challenge = await challengeRequired(page);
    if (!challenge) {
      const googleButton = page.getByRole('button', { name: /Continue with Google|Google/i }).first();
      const googleLink = page.getByRole('link', { name: /Continue with Google|Google/i }).first();
      if ((await googleButton.count()) > 0) {
        await googleButton.click();
      } else if ((await googleLink.count()) > 0) {
        await googleLink.click();
      } else {
        throw new Error('google_login_option_missing');
      }

      await page.waitForTimeout(2500);
      if (/accounts\.google\.com/i.test(page.url())) {
        challenge = await challengeRequired(page);
        if (!challenge) {
          const account = page
            .locator('[data-identifier], [role="link"], [role="button"]')
            .filter({ hasText: /fredmourao|Fred/i })
            .first();
          if ((await account.count()) > 0) {
            await account.click();
            await page.waitForTimeout(3500);
          }
        }
      }

      challenge = challenge || await challengeRequired(page);
      if (!challenge) {
        await page.waitForURL(/platform\.openai\.com|auth\.openai\.com/, { timeout: 30000 }).catch(() => {});
        await page.goto(TARGET_URL, { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {});
        await page.waitForTimeout(3000);
      }
      authenticated = await isAuthenticated(page);
    }
  }

  const manage = authenticated ? await tunnelManageAvailable(page) : false;
  console.log('OPENAI_PLATFORM_AUTHENTICATED=' + String(authenticated));
  console.log('OPENAI_PLATFORM_TUNNEL_MANAGE_AVAILABLE=' + String(manage));
  console.log('OPENAI_PLATFORM_AUTH_CHALLENGE_REQUIRED=' + String(challenge));
  console.log('CLOUD_CLIENT_COMPATIBILITY_REQUIRED=true');
  console.log('CLAUDE_CLOUD_ACCESS_REQUIRED=true');

  if (!authenticated) {
    console.log('OPENAI_PLATFORM_AUTH_RESULT=BLOCKED');
    process.exitCode = 2;
  } else {
    console.log('OPENAI_PLATFORM_AUTH_RESULT=PASS');
  }
} catch (error) {
  console.log('OPENAI_PLATFORM_AUTHENTICATED=false');
  console.log('OPENAI_PLATFORM_TUNNEL_MANAGE_AVAILABLE=false');
  console.log('OPENAI_PLATFORM_AUTH_CHALLENGE_REQUIRED=false');
  console.log('CLOUD_CLIENT_COMPATIBILITY_REQUIRED=true');
  console.log('CLAUDE_CLOUD_ACCESS_REQUIRED=true');
  console.log('OPENAI_PLATFORM_AUTH_RESULT=FAIL blocker=' + safeTag(error?.message));
  process.exitCode = 2;
} finally {
  if (page) await page.close().catch(() => {});
  process.exit(process.exitCode || 0);
}
