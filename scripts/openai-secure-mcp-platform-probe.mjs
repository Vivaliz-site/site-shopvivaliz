import fs from 'node:fs';

const CDP_URL = 'http://127.0.0.1:9555';
const TARGET_URL = 'https://platform.openai.com/settings/organization/tunnels';
const ADMIN_KEYS_URL = 'https://platform.openai.com/settings/organization/admin-keys';
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

function classifyAdminKeysLocation(url) {
  const value = String(url || '');
  if (/auth\.openai\.com|\/login(?:[/?#]|$)/i.test(value)) return 'login';
  if (!/platform\.openai\.com/i.test(value)) return 'external';
  if (/\/settings\/organization\/admin-keys(?:[/?#]|$)/i.test(value)) return 'admin_keys';
  if (/\/settings\/organization(?:[/?#]|$)/i.test(value)) return 'organization_settings';
  if (/\/settings\/project(?:[/?#]|$)/i.test(value)) return 'project_settings';
  return 'platform_other';
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
  const genericCreateButton = page.getByRole('button', { name: /(?:create|new|add)/i }).first();
  const genericCreateLink = page.getByRole('link', { name: /(?:create|new|add)/i }).first();
  const genericCreateControl = authenticated && !accessRequired && (
    (await genericCreateButton.count()) > 0 || (await genericCreateLink.count()) > 0
  );

  const ids = bodyText.match(/tunnel_[0-9a-f]{32}/gi) || [];
  const existingCount = new Set(ids.map(value => value.toLowerCase())).size;

  const targetOrgVisibleOnTunnels = /ShopVivaliz ltda/i.test(bodyText);
  let orgOwnerSurfaceAvailable = false;
  let adminKeysAccessDenied = false;
  let adminKeysNavAvailable = false;
  let adminKeysLocation = 'not_checked';
  let adminKeysTextPresent = false;
  let targetOrgVisible = targetOrgVisibleOnTunnels;
  if (authenticated) {
    await page.goto(ADMIN_KEYS_URL, { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {});
    await page.waitForTimeout(2500);
    const adminUrl = page.url();
    adminKeysLocation = classifyAdminKeysLocation(adminUrl);
    const adminPasswordInput = await page.locator('input[type="password"]').count();
    const adminAuthenticated = !/auth\.openai\.com|\/login(?:[/?#]|$)/i.test(adminUrl) && adminPasswordInput === 0;
    const adminText = adminAuthenticated
      ? await page.locator('body').innerText({ timeout: 8000 }).catch(() => '')
      : '';
    adminKeysTextPresent = /admin api keys?|admin keys?/i.test(adminText);
    targetOrgVisible = targetOrgVisible || /ShopVivaliz ltda/i.test(adminText);
    adminKeysAccessDenied = /access denied|not authorized|not permitted|permission required|insufficient permissions?|(?:^|\s)404(?:\s|$)|page not found/i.test(adminText);
    const adminHeading = page.getByRole('heading', { name: /admin api keys?|admin keys?/i }).first();
    const adminKeyButton = page.getByRole('button', { name: /(?:create|new|add).*admin.*key/i }).first();
    const adminKeyLink = page.getByRole('link', { name: /(?:create|new|add).*admin.*key/i }).first();
    const adminNavLink = page.getByRole('link', { name: /admin api keys?|admin keys?/i }).first();
    adminKeysNavAvailable = (await adminNavLink.count()) > 0;
    orgOwnerSurfaceAvailable = adminAuthenticated && !adminKeysAccessDenied && (
      (await adminHeading.count()) > 0 ||
      (await adminKeyButton.count()) > 0 ||
      (await adminKeyLink.count()) > 0 ||
      adminKeysTextPresent
    );
  }

  let rbacDiag = 'owner_surface_unknown';
  if (!authenticated) rbacDiag = 'unauthenticated';
  else if (manageAvailable) rbacDiag = 'tunnel_manage';
  else if (genericCreateControl) rbacDiag = 'tunnel_create_selector_drift';
  else if (orgOwnerSurfaceAvailable) rbacDiag = 'owner_surface_no_tunnel_create';
  else if (adminKeysAccessDenied) rbacDiag = 'owner_surface_denied';

  console.log('OPENAI_TUNNEL_UI_AUTHENTICATED=' + String(authenticated));
  console.log('OPENAI_TUNNEL_UI_MANAGE_AVAILABLE=' + String(manageAvailable));
  console.log('OPENAI_TUNNEL_UI_ACCESS_REQUIRED=' + String(accessRequired));
  console.log('OPENAI_TUNNEL_UI_EXISTING_COUNT=' + String(existingCount));
  console.log('OPENAI_TUNNEL_UI_GENERIC_CREATE_CONTROL=' + String(genericCreateControl));
  console.log('OPENAI_TUNNEL_UI_ORG_OWNER_SURFACE_AVAILABLE=' + String(orgOwnerSurfaceAvailable));
  console.log('OPENAI_TUNNEL_UI_ADMIN_KEYS_ACCESS_DENIED=' + String(adminKeysAccessDenied));
  console.log('OPENAI_TUNNEL_UI_TARGET_ORG_VISIBLE=' + String(targetOrgVisible));
  console.log('OPENAI_TUNNEL_UI_ADMIN_KEYS_NAV_AVAILABLE=' + String(adminKeysNavAvailable));
  console.log('OPENAI_TUNNEL_UI_ADMIN_KEYS_LOCATION=' + safeTag(adminKeysLocation));
  console.log('OPENAI_TUNNEL_UI_ADMIN_KEYS_TEXT_PRESENT=' + String(adminKeysTextPresent));
  console.log('OPENAI_TUNNEL_UI_RBAC_DIAG=' + safeTag(rbacDiag));
  console.log('OPENAI_TUNNEL_UI_PROBE=PASS');
} catch (error) {
  console.log('OPENAI_TUNNEL_UI_PROBE=FAIL blocker=' + safeTag(error?.message));
  process.exitCode = 2;
} finally {
  if (page) await page.close().catch(() => {});
  process.exit(process.exitCode || 0);
}
