import fs from 'node:fs';

const CDP_URL = 'http://127.0.0.1:9555';
const TARGET_URL = 'https://platform.openai.com/settings/organization/tunnels';
const MFA_WAIT_TIMEOUT_MS = 60000;
const playwrightCandidates = [
  '/home/ubuntu/shopvivaliz-browser-worker/node_modules/playwright-core/index.js',
  '/home/ubuntu/shopvivaliz-deploy/repo/node_modules/playwright/index.js',
  '/home/ubuntu/solange-rolla-consultorio/node_modules/playwright/index.js',
];

function safeTag(value) {
  return String(value || 'auth_failed').replace(/[^A-Za-z0-9_.-]+/g, '_').slice(0, 100);
}

function classifyLocation(url) {
  const value = String(url || '');
  if (/\/login(?:[/?#]|$)/i.test(value)) return 'login';
  if (/accounts\.google\.com/i.test(value)) return 'google';
  if (/auth\.openai\.com/i.test(value)) return 'openai_auth';
  if (/platform\.openai\.com/i.test(value)) return 'platform';
  return 'other';
}

async function authenticationBlocker(page) {
  const location = classifyLocation(page.url());
  if (location !== 'platform') return location;
  const password = await page.locator('input[type="password"]').count().catch(() => 0);
  if (password > 0) return 'password_field';
  return 'none';
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

async function waitForHumanMfa(primaryPage, authPage, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (authPage && authPage !== primaryPage && authPage.isClosed()) return 'completed';
    if (await isAuthenticated(primaryPage).catch(() => false)) return 'completed';
    if (authPage && !authPage.isClosed()) {
      const stillRequired = await challengeRequired(authPage).catch(() => false);
      const authUrl = authPage.url();
      if (!stillRequired && !/accounts\.google\.com/i.test(authUrl)) return 'completed';
    }
    await primaryPage.waitForTimeout(1000).catch(() => {});
  }
  return 'timeout';
}

async function waitForOAuthHandoff(authPage, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (!authPage || authPage.isClosed()) return 'closed';
    const location = classifyLocation(authPage.url());
    if (location !== 'openai_auth') return location;
    if (await challengeRequired(authPage).catch(() => false)) return 'challenge';
    await authPage.waitForTimeout(500).catch(() => {});
  }
  return 'timeout';
}

async function waitForOAuthCompletion(primaryPage, authPage, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (await isAuthenticated(primaryPage).catch(() => false)) return 'completed';
    if (authPage && authPage !== primaryPage && authPage.isClosed()) {
      await primaryPage.waitForTimeout(500).catch(() => {});
      if (await isAuthenticated(primaryPage).catch(() => false)) return 'completed';
      return 'closed';
    }
    if (authPage && !authPage.isClosed()) {
      if (await challengeRequired(authPage).catch(() => false)) return 'challenge';
      const location = classifyLocation(authPage.url());
      if (location === 'platform' && await isAuthenticated(authPage).catch(() => false)) return 'completed';
      if (location === 'login') return 'login';
      if (location === 'other') return 'other';
    }
    await primaryPage.waitForTimeout(500).catch(() => {});
  }
  return 'timeout';
}

async function tunnelManageAvailable(page) {
  const text = await page.locator('body').innerText({ timeout: 8000 }).catch(() => '');
  if (/tunnels? access required|access to tunnels? is required|insufficient permissions?|permission required/i.test(text)) return false;
  const button = page.getByRole('button', { name: /create tunnel|new tunnel|add tunnel/i }).first();
  const link = page.getByRole('link', { name: /create tunnel|new tunnel|add tunnel/i }).first();
  return (await button.count()) > 0 || (await link.count()) > 0;
}

let page = null;
let authPage = null;
let popupPage = null;
let authStage = 'initial';
let accountChooserPresent = false;
let returnedToPlatform = false;
let googlePopupUsed = false;
let mfaWaitResult = 'not_needed';
let postGoogleLocation = 'not_observed';
let finalLocation = 'not_observed';
let authBlocker = 'not_observed';
let oauthHandoffResult = 'not_observed';
let oauthCompletionResult = 'not_observed';
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
  returnedToPlatform = /platform\.openai\.com/i.test(page.url());
  authStage = authenticated ? 'already_authenticated' : 'login_required';

  if (!authenticated) {
    challenge = await challengeRequired(page);
    if (!challenge) {
      const googleButton = page.getByRole('button', { name: /Continue with Google|Google/i }).first();
      const googleLink = page.getByRole('link', { name: /Continue with Google|Google/i }).first();
      const popupPromise = page.waitForEvent('popup', { timeout: 5000 }).catch(() => null);
      if ((await googleButton.count()) > 0) {
        await googleButton.click();
        authStage = 'google_clicked';
      } else if ((await googleLink.count()) > 0) {
        await googleLink.click();
        authStage = 'google_clicked';
      } else {
        throw new Error('google_login_option_missing');
      }

      popupPage = await popupPromise;
      authPage = popupPage || page;
      googlePopupUsed = Boolean(popupPage);
      if (popupPage) {
        authStage = 'google_popup';
        popupPage.setDefaultTimeout(15000);
        await popupPage.waitForLoadState('domcontentloaded', { timeout: 15000 }).catch(() => {});
      }

      await authPage.waitForTimeout(1000);
      postGoogleLocation = classifyLocation(authPage.url());
      oauthHandoffResult = postGoogleLocation === 'openai_auth'
        ? await waitForOAuthHandoff(authPage, 20000)
        : postGoogleLocation;

      if (oauthHandoffResult === 'challenge') {
        challenge = true;
      } else if (oauthHandoffResult === 'timeout') {
        authStage = 'oauth_handoff_timeout';
      }

      if (!challenge && authPage && !authPage.isClosed() && classifyLocation(authPage.url()) === 'google') {
        authStage = 'google_account_chooser';
        const identifierCount = await authPage.locator('[data-identifier]').count();
        const anotherAccountCount = await authPage.getByText(/Use another account/i).count().catch(() => 0);
        accountChooserPresent = identifierCount > 0 || anotherAccountCount > 0;
        challenge = await challengeRequired(authPage);
        if (!challenge) {
          const account = authPage
            .locator('[data-identifier], [role="link"], [role="button"]')
            .filter({ hasText: /fredmourao|Fred/i })
            .first();
          if ((await account.count()) > 0) {
            await account.click();
            authStage = 'google_account_selected';
          } else if (accountChooserPresent) {
            authStage = 'account_match_missing';
          }
        }
      }

      if (!challenge && !['account_match_missing', 'oauth_handoff_timeout'].includes(authStage)) {
        oauthCompletionResult = await waitForOAuthCompletion(page, authPage, 30000);
        if (oauthCompletionResult === 'challenge') challenge = true;
        else if (oauthCompletionResult === 'timeout') authStage = 'oauth_completion_timeout';
        else if (oauthCompletionResult === 'login') authStage = 'oauth_returned_login';
        else if (oauthCompletionResult === 'other') authStage = 'oauth_returned_other';
        else if (oauthCompletionResult === 'closed') authStage = 'oauth_popup_closed_without_session';
      }

      if (challenge && !['account_match_missing'].includes(authStage)) {
        authStage = 'challenge_required';
        console.log('OPENAI_PLATFORM_MFA_WAIT_ACTIVE=true');
        mfaWaitResult = await waitForHumanMfa(page, authPage, MFA_WAIT_TIMEOUT_MS);
        if (mfaWaitResult === 'completed') {
          challenge = false;
          authStage = 'mfa_completed';
          oauthCompletionResult = await waitForOAuthCompletion(page, authPage, 30000);
        } else {
          authStage = 'challenge_timeout';
        }
      }

      if (!challenge && oauthCompletionResult === 'completed') {
        await page.goto(TARGET_URL, { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {});
        await page.waitForTimeout(3000);
        returnedToPlatform = /platform\.openai\.com/i.test(page.url());
        if (!authenticated && !['account_match_missing'].includes(authStage)) {
          authStage = returnedToPlatform ? 'platform_return' : 'oauth_not_returned';
        }
      }
      authenticated = await isAuthenticated(page);
      finalLocation = classifyLocation(page.url());
      authBlocker = authenticated ? 'none' : await authenticationBlocker(page);
      if (challenge && authStage !== 'challenge_timeout') authStage = 'challenge_required';
      else if (authenticated) authStage = 'authenticated';
    }
  }

  if (finalLocation === 'not_observed') finalLocation = classifyLocation(page.url());
  if (authBlocker === 'not_observed') authBlocker = authenticated ? 'none' : await authenticationBlocker(page);
  const manage = authenticated ? await tunnelManageAvailable(page) : false;
  console.log('OPENAI_PLATFORM_AUTH_STAGE=' + safeTag(authStage));
  console.log('OPENAI_PLATFORM_ACCOUNT_CHOOSER_PRESENT=' + String(accountChooserPresent));
  console.log('OPENAI_PLATFORM_AUTH_RETURNED_TO_PLATFORM=' + String(returnedToPlatform));
  console.log('OPENAI_PLATFORM_GOOGLE_POPUP_USED=' + String(googlePopupUsed));
  console.log('OPENAI_PLATFORM_MFA_WAIT_RESULT=' + safeTag(mfaWaitResult));
  console.log('OPENAI_PLATFORM_POST_GOOGLE_LOCATION=' + safeTag(postGoogleLocation));
  console.log('OPENAI_PLATFORM_OAUTH_HANDOFF_RESULT=' + safeTag(oauthHandoffResult));
  console.log('OPENAI_PLATFORM_OAUTH_COMPLETION_RESULT=' + safeTag(oauthCompletionResult));
  console.log('OPENAI_PLATFORM_FINAL_LOCATION=' + safeTag(finalLocation));
  console.log('OPENAI_PLATFORM_AUTH_BLOCKER=' + safeTag(authBlocker));
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
  console.log('OPENAI_PLATFORM_AUTH_STAGE=' + safeTag(authStage));
  console.log('OPENAI_PLATFORM_ACCOUNT_CHOOSER_PRESENT=' + String(accountChooserPresent));
  console.log('OPENAI_PLATFORM_AUTH_RETURNED_TO_PLATFORM=' + String(returnedToPlatform));
  console.log('OPENAI_PLATFORM_GOOGLE_POPUP_USED=' + String(googlePopupUsed));
  console.log('OPENAI_PLATFORM_MFA_WAIT_RESULT=' + safeTag(mfaWaitResult));
  console.log('OPENAI_PLATFORM_POST_GOOGLE_LOCATION=' + safeTag(postGoogleLocation));
  console.log('OPENAI_PLATFORM_OAUTH_HANDOFF_RESULT=' + safeTag(oauthHandoffResult));
  console.log('OPENAI_PLATFORM_OAUTH_COMPLETION_RESULT=' + safeTag(oauthCompletionResult));
  console.log('OPENAI_PLATFORM_FINAL_LOCATION=' + safeTag(finalLocation));
  console.log('OPENAI_PLATFORM_AUTH_BLOCKER=' + safeTag(authBlocker));
  console.log('OPENAI_PLATFORM_AUTHENTICATED=false');
  console.log('OPENAI_PLATFORM_TUNNEL_MANAGE_AVAILABLE=false');
  console.log('OPENAI_PLATFORM_AUTH_CHALLENGE_REQUIRED=false');
  console.log('CLOUD_CLIENT_COMPATIBILITY_REQUIRED=true');
  console.log('CLAUDE_CLOUD_ACCESS_REQUIRED=true');
  console.log('OPENAI_PLATFORM_AUTH_RESULT=FAIL blocker=' + safeTag(error?.message));
  process.exitCode = 2;
} finally {
  if (popupPage && !popupPage.isClosed()) await popupPage.close().catch(() => {});
  if (page) await page.close().catch(() => {});
  process.exit(process.exitCode || 0);
}
