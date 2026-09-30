import fs from 'node:fs';

const CDP_URL = 'http://127.0.0.1:9555';
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

function bool(value) {
  return value ? 'true' : 'false';
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
  page.setDefaultTimeout(12000);

  await page.goto('https://chatgpt.com/', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.waitForTimeout(2500);

  let currentUrl = page.url();
  const passwordInput = await page.locator('input[type="password"]').count();
  const authenticated =
    !/auth\.openai\.com|\/auth(?:[/?#]|$)|\/login(?:[/?#]|$)/i.test(currentUrl) &&
    passwordInput === 0;

  let modelsPageReached = false;
  let selectedRoute = 'none';
  let bodyText = '';

  if (authenticated) {
    const discovered = await page
      .locator('a[href]')
      .evaluateAll((els) =>
        els
          .map((el) => String(el.getAttribute('href') || ''))
          .filter((href) => /(?:^|\/)admin(?:\/|$)|models?/i.test(href))
          .slice(0, 50)
      )
      .catch(() => []);

    const candidatePaths = [
      ...discovered,
      '/admin/models',
      '/admin/settings/models',
      '/admin',
    ];

    const seen = new Set();
    for (const raw of candidatePaths) {
      let target;
      try {
        target = new URL(raw, 'https://chatgpt.com/').toString();
      } catch {
        continue;
      }
      if (!target.startsWith('https://chatgpt.com/')) continue;
      if (seen.has(target)) continue;
      seen.add(target);

      await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {});
      await page.waitForTimeout(2500);
      currentUrl = page.url();
      bodyText = await page.locator('body').innerText({ timeout: 8000 }).catch(() => '');

      if (/admin/i.test(currentUrl)) {
        const modelsNav = page
          .getByRole('link', { name: /^(models?|modelos)$/i })
          .or(page.getByRole('button', { name: /^(models?|modelos)$/i }))
          .first();
        if ((await modelsNav.count().catch(() => 0)) > 0) {
          await modelsNav.click().catch(() => {});
          await page.waitForTimeout(2500);
          currentUrl = page.url();
          bodyText = await page.locator('body').innerText({ timeout: 8000 }).catch(() => '');
        }
      }

      const looksLikeModels =
        /admin/i.test(currentUrl) &&
        /models?|modelos|model settings|recommended|recomendad|reasoning|thinking|effort|intelligence|intelig[eê]ncia/i.test(bodyText);

      if (looksLikeModels) {
        modelsPageReached = true;
        selectedRoute = new URL(currentUrl).pathname.slice(0, 120) || '/';
        break;
      }
    }
  }

  const normalized = bodyText.replace(/\s+/g, ' ').trim();
  const loadErrorPresent =
    /couldn.?t load model settings|could not load model settings|model settings.*try again|n[aã]o foi poss[ií]vel carregar.*model/i.test(normalized);
  const accessDeniedPresent =
    /access denied|insufficient permission|permission required|not authorized|forbidden|sem permiss[aã]o|acesso negado/i.test(normalized);
  const solRecommendedPresent =
    /5\.6\s+Sol.{0,80}Recommended|Recommended.{0,80}5\.6\s+Sol|5\.6\s+Sol.{0,80}Recomendad/i.test(normalized);
  const reasoningTextPresent =
    /Instant(?:aneous|âneo)?|M[eé]dio|Medium|High|Alto|Extra\s+high|Extra\s+alto|reasoning|thinking|effort|intelig[eê]ncia superior|higher intelligence/i.test(normalized);

  const controlStats = await page
    .locator('button, select, [role="button"], [role="combobox"], input')
    .evaluateAll((els) => {
      const interesting = els.filter((el) => {
        const text = [
          el.textContent,
          el.getAttribute('aria-label'),
          el.getAttribute('name'),
          el.getAttribute('title'),
          el.getAttribute('data-testid'),
        ]
          .filter(Boolean)
          .join(' ');
        return /model|reasoning|thinking|effort|intelligence|intelig[eê]ncia|instant|medium|m[eé]dio|high|alto/i.test(text);
      });
      const disabled = interesting.filter(
        (el) =>
          el.hasAttribute('disabled') ||
          el.getAttribute('aria-disabled') === 'true'
      );
      return { total: interesting.length, disabled: disabled.length };
    })
    .catch(() => ({ total: 0, disabled: 0 }));

  const controlsPresent = Number(controlStats.total || 0) > 0;
  const enabledControls = Math.max(
    0,
    Number(controlStats.total || 0) - Number(controlStats.disabled || 0)
  );
  const controlsEnabled = controlsPresent && enabledControls > 0 && !loadErrorPresent && !accessDeniedPresent;

  console.log('CHATGPT_BUSINESS_MODELS_AUTHENTICATED=' + bool(authenticated));
  console.log('CHATGPT_BUSINESS_MODELS_PAGE_REACHED=' + bool(modelsPageReached));
  console.log('CHATGPT_BUSINESS_MODELS_ROUTE=' + safeTag(selectedRoute));
  console.log('CHATGPT_BUSINESS_MODELS_LOAD_ERROR_PRESENT=' + bool(loadErrorPresent));
  console.log('CHATGPT_BUSINESS_MODELS_ACCESS_DENIED_PRESENT=' + bool(accessDeniedPresent));
  console.log('CHATGPT_BUSINESS_MODELS_SOL_RECOMMENDED_PRESENT=' + bool(solRecommendedPresent));
  console.log('CHATGPT_BUSINESS_MODELS_REASONING_TEXT_PRESENT=' + bool(reasoningTextPresent));
  console.log('CHATGPT_BUSINESS_MODELS_CONTROLS_PRESENT=' + bool(controlsPresent));
  console.log('CHATGPT_BUSINESS_MODELS_CONTROLS_ENABLED=' + bool(controlsEnabled));
  console.log('CHATGPT_BUSINESS_MODELS_CONTROL_COUNT=' + Math.min(99, Number(controlStats.total || 0)));
  console.log('CHATGPT_BUSINESS_MODELS_DISABLED_COUNT=' + Math.min(99, Number(controlStats.disabled || 0)));
  console.log('CHATGPT_BUSINESS_MODELS_PROBE=PASS');
} catch (error) {
  console.log('CHATGPT_BUSINESS_MODELS_PROBE=FAIL blocker=' + safeTag(error?.message));
  process.exitCode = 2;
} finally {
  if (page) await page.close().catch(() => {});
  process.exit(process.exitCode || 0);
}
