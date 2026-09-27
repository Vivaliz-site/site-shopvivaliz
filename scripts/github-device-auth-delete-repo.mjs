import fs from 'node:fs';

const code = String(process.env.GITHUB_DEVICE_CODE || '').trim().toUpperCase();
const forcedProfile = String(process.env.GITHUB_DEVICE_FORCE_PROFILE || '').trim();
const forcedBrowser = String(process.env.GITHUB_DEVICE_BROWSER || '').trim();
const forcedRoute = String(process.env.GITHUB_DEVICE_ROUTE || '').trim();
const CANONICAL_PROFILE = '/home/ubuntu/.local/share/shopvivaliz-browser-worker/profiles/ai-squad-chatgpt';
const CANONICAL_BROWSER = '/home/ubuntu/.local/bin/shopvivaliz-browser-chromium';
const BROWSER_WORKER_URL = 'http://127.0.0.1:17777';
const CDP_URL = 'http://127.0.0.1:9555';
const playwrightCandidates = [
  '/home/ubuntu/shopvivaliz-browser-worker/node_modules/playwright-core/index.js',
  '/home/ubuntu/shopvivaliz-deploy/repo/node_modules/playwright/index.js',
  '/home/ubuntu/solange-rolla-consultorio/node_modules/playwright/index.js',
];

function fail(tag) {
  console.log(`GITHUB_DEVICE_AUTH=FAIL blocker=${tag}`);
  process.exitCode = 2;
}

if (!/^[A-Z0-9]{4}-[A-Z0-9]{4}$/.test(code)) {
  fail('invalid_device_code_shape');
} else {
  let page = null;
  let context = null;
  let launched = false;
  try {
    let chromium = null;
    for (const candidate of playwrightCandidates) {
      if (!fs.existsSync(candidate)) continue;
      const mod = await import('file://' + candidate);
      chromium = mod.chromium || mod.default?.chromium;
      if (chromium) break;
    }
    if (!chromium) throw new Error('playwright_runtime_missing');

    const browserPath = [forcedBrowser, CANONICAL_BROWSER, '/usr/bin/chromium', chromium.executablePath()]
      .find(candidate => candidate && fs.existsSync(candidate));
    if (!browserPath) throw new Error('browser_missing');

    let route = '';
    if (!forcedProfile) {
      try {
        const browser = await chromium.connectOverCDP(CDP_URL);
        const contexts = browser.contexts();
        if (contexts.length) {
          context = contexts[0];
          route = 'cdp';
        }
      } catch {}
    }

    if (!context) {
      const profile = forcedProfile || CANONICAL_PROFILE;
      if (!fs.existsSync(profile)) throw new Error('profile_missing');
      if (!forcedProfile) {
        try {
          const response = await fetch(BROWSER_WORKER_URL + '/sessions', { signal: AbortSignal.timeout(3000) });
          if (response.ok) {
            const data = await response.json();
            const locked = Array.isArray(data?.sessions) && data.sessions.some(session => session?.persistent === true && session?.profile === 'ai-squad-chatgpt');
            if (locked) throw new Error('canonical_profile_in_use_without_cdp');
          }
        } catch (error) {
          if (String(error?.message || '').includes('canonical_profile_in_use_without_cdp')) throw error;
        }
      }
      const display = String(process.env.DISPLAY || '').trim() || ':98';
      context = await chromium.launchPersistentContext(profile, {
        executablePath: browserPath,
        headless: false,
        viewport: { width: 1440, height: 900 },
        env: { ...process.env, DISPLAY: display, LIBGL_ALWAYS_SOFTWARE: '1' },
        args: ['--no-sandbox','--disable-dev-shm-usage','--disable-gpu','--disable-vulkan','--use-gl=swiftshader','--use-angle=swiftshader'],
      });
      launched = true;
      route = forcedRoute || (forcedProfile ? 'forced_profile' : 'canonical_profile');
    }

    page = await context.newPage();
    page.setDefaultTimeout(12000);

    const gotoDevice = async () => {
      await page.goto('https://github.com/login/device', { waitUntil: 'domcontentloaded', timeout: 45000 });
      await page.waitForTimeout(1000);
    };

    await gotoDevice();

    if (/github\.com\/login(?:\?|$)/.test(page.url()) || await page.locator('input[name="login"]').count()) {
      const google = page.locator('button, a').filter({ hasText: /Google/i }).first();
      if (!(await google.count())) throw new Error('github_web_login_required');
      await google.click();
      await page.waitForTimeout(1800);

      if (/accounts\.google\.com/.test(page.url())) {
        const fred = page.locator('[data-identifier], [role="link"], [role="button"]').filter({ hasText: /Fred/i }).first();
        if (!(await fred.count())) throw new Error('google_account_selection_required');
        await fred.click();
        await page.waitForTimeout(2500);
      }

      if (/accounts\.google\.com/.test(page.url())) {
        if (await page.locator('input[type="password"]').count()) throw new Error('google_interactive_challenge_required');
      }
      if (!/github\.com/.test(page.url())) {
        await page.waitForURL(/github\.com/, { timeout: 30000 }).catch(() => {});
      }
      await gotoDevice();
    }

    const userCode = page.locator('input[name="user_code"], input#user-code').first();
    if (!(await userCode.count())) throw new Error('device_code_input_missing');
    await userCode.fill(code);

    const continueButton = page.getByRole('button', { name: /Continue/i }).first();
    if (await continueButton.count()) {
      await continueButton.click();
    } else {
      await userCode.press('Enter');
    }
    await page.waitForTimeout(1500);

    const authorize = page.locator('button[name="authorize"], input[type="submit"][value*="Authorize" i]').first();
    if (await authorize.count()) {
      await authorize.click();
      await page.waitForTimeout(1500);
    } else {
      const byText = page.getByRole('button', { name: /Authorize|Confirm/i }).first();
      if (await byText.count()) {
        await byText.click();
        await page.waitForTimeout(1500);
      }
    }

    const success = await page.locator('body').evaluate(node =>
      /congratulations|all set|successfully authorized|device activated|authorization complete/i.test(node.innerText || '')
    );
    if (!success) throw new Error('device_authorization_not_confirmed');

    console.log(`GITHUB_DEVICE_AUTH=PASS route=${route}`);
  } catch (error) {
    const tag = String(error?.message || 'device_auth_failed').replace(/[^A-Za-z0-9_.-]+/g, '_').slice(0, 120);
    fail(tag);
  } finally {
    if (page) await page.close().catch(() => {});
    if (launched && context) await context.close().catch(() => {});
  }
}
