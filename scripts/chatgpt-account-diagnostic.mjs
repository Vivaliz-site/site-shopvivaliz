import fs from 'node:fs';

const ATTEMPTS = 3;
const PROMPT = 'Responda apenas: TESTE-OK';
const CDP_URL = 'http://127.0.0.1:9555';
const BROWSER_WORKER_URL = 'http://127.0.0.1:17777';
const CANONICAL_PROFILE = '/home/ubuntu/.local/share/shopvivaliz-browser-worker/profiles/ai-squad-chatgpt';
const CANONICAL_BROWSER = '/home/ubuntu/.local/bin/shopvivaliz-browser-chromium';
const OUTPUT = process.env.CHATGPT_ACCOUNT_DIAG_OUTPUT || '/tmp/chatgpt-account-diagnostic.json';
const forcedProfile = String(process.env.CHATGPT_ACCOUNT_FORCE_PROFILE || '').trim();
const forcedRoute = String(process.env.CHATGPT_ACCOUNT_BROWSER_ROUTE || '').trim();
const playwrightCandidates = [
  String(process.env.CHATGPT_ACCOUNT_PLAYWRIGHT_MODULE || '').trim(),
  '/home/ubuntu/shopvivaliz-browser-worker/node_modules/playwright-core/index.js',
  '/home/ubuntu/shopvivaliz-deploy/repo/node_modules/playwright/index.js',
  '/home/ubuntu/solange-rolla-consultorio/node_modules/playwright/index.js',
];

function nowIso() {
  return new Date().toISOString();
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function safePath(raw) {
  try {
    const url = new URL(raw);
    url.search = '';
    url.hash = '';
    let pathname = url.pathname
      .replace(/[0-9a-f]{8}-[0-9a-f-]{27,}/gi, ':id')
      .replace(/\/c\/[^/]+/g, '/c/:id')
      .replace(/\/g\/[^/]+\/c\/[^/]+/g, '/g/:project/c/:id');
    return url.origin + pathname;
  } catch {
    return 'invalid-url';
  }
}

function allowedHost(raw) {
  try {
    const host = new URL(raw).hostname.toLowerCase();
    return host === 'chatgpt.com' || host.endsWith('.chatgpt.com') || host === 'openai.com' || host.endsWith('.openai.com');
  } catch {
    return false;
  }
}

async function loadChromium() {
  for (const candidate of playwrightCandidates.filter(Boolean)) {
    if (!fs.existsSync(candidate)) continue;
    const mod = await import('file://' + candidate);
    const chromium = mod.chromium || mod.default?.chromium;
    if (chromium) return { chromium, candidate };
  }
  throw new Error('playwright_runtime_missing');
}

function classifyVisibleFlags(bodyText) {
  const text = String(bodyText || '').toLowerCase();
  if (text.includes('esgotou-se o tempo limite da solicitação') || text.includes('request timed out') || text.includes('request timeout')) {
    return 'request_timeout';
  }
  if (text.includes('a reflexão falhou') || text.includes('thinking failed')) {
    return 'reflection_failed';
  }
  if (text.includes('transmissão interrompida') || text.includes('stream interrupted')) {
    return 'stream_interrupted';
  }
  if (text.includes('parou de pensar') || text.includes('stopped thinking')) {
    return 'stopped_thinking';
  }
  if (text.includes('network error') || text.includes('erro de rede')) {
    return 'network_error';
  }
  if (text.includes('something went wrong') || text.includes('algo deu errado')) {
    return 'generic_error';
  }
  return '';
}

async function findComposer(page) {
  const selectors = [
    '#prompt-textarea',
    'textarea[data-testid*="prompt"]',
    'textarea[placeholder*="Message"]',
    'textarea[placeholder*="Perguntar"]',
    'div[contenteditable="true"][data-testid*="composer"]',
    'div[contenteditable="true"]',
  ];
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    try {
      if (await locator.isVisible({ timeout: 1000 })) return locator;
    } catch {}
  }
  return null;
}

async function modelLabel(page) {
  try {
    const labels = await page.locator('button').evaluateAll(nodes => nodes
      .map(node => (node.textContent || '').trim().replace(/\s+/g, ' '))
      .filter(Boolean)
      .filter(text => /(?:GPT|Thinking|Instant|Auto|Sol|Luna|Pro)/i.test(text))
      .slice(0, 8));
    const value = labels.find(x => /GPT|Sol|Luna|Pro/i.test(x)) || labels[0] || '';
    return value.slice(0, 100);
  } catch {
    return '';
  }
}

async function uiSnapshot(page) {
  return page.evaluate(() => {
    const bodyText = document.body?.innerText || '';
    const assistants = Array.from(document.querySelectorAll('[data-message-author-role="assistant"]'));
    const lastAssistant = assistants.at(-1);
    const lastText = (lastAssistant?.textContent || '').trim();
    const stop = Array.from(document.querySelectorAll('button')).some(button => {
      const label = ((button.getAttribute('aria-label') || '') + ' ' + (button.textContent || '')).toLowerCase();
      return /stop|parar/.test(label) && button.offsetParent !== null;
    });
    return {
      bodyText,
      assistantCount: assistants.length,
      assistantHasExpected: lastText.includes('TESTE-OK'),
      assistantTextLength: lastText.length,
      stopVisible: stop,
    };
  });
}

async function fillAndSend(page, composer) {
  try {
    await composer.fill(PROMPT);
  } catch {
    await composer.click();
    await page.keyboard.insertText(PROMPT);
  }
  const sendSelectors = [
    'button[data-testid="send-button"]',
    'button[aria-label*="Send"]',
    'button[aria-label*="Enviar"]',
  ];
  for (const selector of sendSelectors) {
    const button = page.locator(selector).first();
    try {
      if (await button.isVisible({ timeout: 500 })) {
        await button.click();
        return;
      }
    } catch {}
  }
  await composer.press('Enter');
}

const result = {
  schema: 1,
  started_at: nowIso(),
  cdp: CDP_URL,
  auth_state: 'unknown',
  browser_route: '',
  browser_worker_available: false,
  canonical_profile_exists: false,
  playwright_module: '',
  model_label: '',
  attempts: [],
  network: [],
  console_error_count: 0,
  page_error_count: 0,
  request_failure_count: 0,
  blocker: '',
  ok: false,
};

function persist() {
  fs.writeFileSync(OUTPUT, JSON.stringify(result, null, 2) + '\n', { mode: 0o600 });
}

let page;
let fallbackContext = null;
let launchedFallback = false;
try {
  const { chromium, candidate } = await loadChromium();
  result.playwright_module = candidate.replace(/^\/home\/[^/]+\//, '/home/:user/');
  let browser;
  let context;

  if (forcedProfile) {
    result.canonical_profile_exists = fs.existsSync(forcedProfile);
    if (!result.canonical_profile_exists) {
      result.blocker = 'forced_profile_missing';
      persist();
      throw new Error(result.blocker);
    }
    if (!fs.existsSync(CANONICAL_BROWSER)) {
      result.blocker = 'canonical_browser_missing';
      persist();
      throw new Error(result.blocker);
    }
    const display = String(process.env.DISPLAY || '').trim() || ':98';
    try {
      fallbackContext = await chromium.launchPersistentContext(forcedProfile, {
        executablePath: CANONICAL_BROWSER,
        headless: false,
        viewport: { width: 1440, height: 900 },
        env: { ...process.env, DISPLAY: display, LIBGL_ALWAYS_SOFTWARE: '1' },
        args: [
          '--no-sandbox',
          '--disable-dev-shm-usage',
          '--disable-gpu',
          '--disable-vulkan',
          '--use-gl=swiftshader',
          '--use-angle=swiftshader',
        ],
      });
    } catch {
      result.blocker = 'forced_profile_locked_or_launch_failed';
      persist();
      throw new Error(result.blocker);
    }
    context = fallbackContext;
    launchedFallback = true;
    result.browser_route = forcedRoute || 'forced_profile_fallback';
  } else {
    try {
      browser = await chromium.connectOverCDP('http://127.0.0.1:9555');
      const contexts = browser.contexts();
      if (!contexts.length) {
        result.blocker = 'canonical_browser_context_missing';
        persist();
        throw new Error(result.blocker);
      }
      context = contexts[0];
      result.browser_route = 'cdp';
    } catch (cdpError) {
      if (result.blocker === 'canonical_browser_context_missing') throw cdpError;

      let workerHealth = null;
      let workerSessions = null;
      try {
        const healthResponse = await fetch(BROWSER_WORKER_URL + '/health', { signal: AbortSignal.timeout(3000) });
        if (!healthResponse.ok) throw new Error('health_http_' + healthResponse.status);
        workerHealth = await healthResponse.json();
        if (workerHealth?.ok !== true || workerHealth?.endpoint !== 'browser-worker') throw new Error('health_contract_invalid');
        result.browser_worker_available = true;

        const sessionsResponse = await fetch(BROWSER_WORKER_URL + '/sessions', { signal: AbortSignal.timeout(3000) });
        if (!sessionsResponse.ok) throw new Error('sessions_http_' + sessionsResponse.status);
        workerSessions = await sessionsResponse.json();
      } catch {
        result.blocker = 'browser_worker_unavailable';
        persist();
        throw new Error(result.blocker);
      }

      result.canonical_profile_exists = fs.existsSync(CANONICAL_PROFILE);
      const activeCanonical = Array.isArray(workerSessions?.sessions)
        ? workerSessions.sessions.some(session => session?.persistent === true && session?.profile === 'ai-squad-chatgpt')
        : false;

      if (activeCanonical) {
        result.blocker = 'canonical_profile_in_use_without_cdp';
        persist();
        throw new Error(result.blocker);
      }
      if (!result.canonical_profile_exists) {
        result.blocker = 'canonical_profile_missing';
        persist();
        throw new Error(result.blocker);
      }
      if (!fs.existsSync(CANONICAL_BROWSER)) {
        result.blocker = 'canonical_browser_missing';
        persist();
        throw new Error(result.blocker);
      }

      const display = String(workerHealth?.display || '').trim() || ':98';
      try {
        fallbackContext = await chromium.launchPersistentContext(CANONICAL_PROFILE, {
          executablePath: CANONICAL_BROWSER,
          headless: false,
          viewport: { width: 1440, height: 900 },
          env: { ...process.env, DISPLAY: display, LIBGL_ALWAYS_SOFTWARE: '1' },
          args: [
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--disable-vulkan',
            '--use-gl=swiftshader',
            '--use-angle=swiftshader',
          ],
        });
      } catch {
        result.blocker = 'canonical_profile_locked_or_launch_failed';
        persist();
        throw new Error(result.blocker);
      }
      context = fallbackContext;
      launchedFallback = true;
      result.browser_route = 'browser_worker_profile_fallback';
    }
  }

  page = await context.newPage();
  page.setDefaultTimeout(15000);

  page.on('console', message => {
    if (message.type() === 'error') result.console_error_count += 1;
  });
  page.on('pageerror', () => {
    result.page_error_count += 1;
  });
  page.on('requestfailed', request => {
    if (!allowedHost(request.url())) return;
    result.request_failure_count += 1;
    if (result.network.length < 250) {
      result.network.push({
        at: nowIso(),
        kind: 'request_failed',
        method: request.method(),
        path: safePath(request.url()),
        error: String(request.failure()?.errorText || '').slice(0, 120),
      });
    }
  });
  page.on('response', async response => {
    if (!allowedHost(response.url())) return;
    const status = response.status();
    const path = safePath(response.url());
    if (status < 400 && !/(backend-api|conversation|turn|message|api)/i.test(path)) return;
    if (result.network.length >= 250) return;
    let headers = {};
    try { headers = await response.allHeaders(); } catch {}
    result.network.push({
      at: nowIso(),
      kind: 'response',
      method: response.request().method(),
      path,
      status,
      has_oai_request_id: Boolean(headers['x-oai-request-id'] || headers['x-request-id']),
      has_turn_trace_id: Boolean(headers['x-oai-turn-trace-id']),
      has_cf_ray: Boolean(headers['cf-ray']),
      has_response_date: Boolean(headers['date']),
    });
  });

  await page.goto('https://chatgpt.com/', { waitUntil: 'domcontentloaded', timeout: 45000 });
  const initialComposer = await findComposer(page);
  if (!initialComposer) {
    result.auth_state = 'not_ready_or_not_authenticated';
    result.blocker = 'composer_unavailable';
    persist();
    throw new Error(result.blocker);
  }

  result.auth_state = 'authenticated_ui_ready';
  result.model_label = await modelLabel(page);

  for (let attempt = 1; attempt <= ATTEMPTS; attempt += 1) {
    await page.goto('https://chatgpt.com/', { waitUntil: 'domcontentloaded', timeout: 45000 });
    const composer = await findComposer(page);
    if (!composer) {
      result.attempts.push({
        attempt,
        started_at: nowIso(),
        ended_at: nowIso(),
        outcome: 'composer_unavailable',
        first_response_ms: null,
        completion_ms: null,
        ui_error: '',
      });
      continue;
    }

    const before = await uiSnapshot(page);
    const startedMs = Date.now();
    const startedAt = nowIso();
    const networkStart = result.network.length;
    const consoleStart = result.console_error_count;
    const pageErrorStart = result.page_error_count;
    const requestFailureStart = result.request_failure_count;
    await fillAndSend(page, composer);

    let firstResponseMs = null;
    let outcome = 'timeout_waiting_terminal_state';
    let uiError = '';
    let terminal = false;

    for (let elapsed = 0; elapsed < 120000; elapsed += 500) {
      await sleep(500);
      const snap = await uiSnapshot(page);
      if (firstResponseMs === null && (snap.assistantCount > before.assistantCount || snap.assistantTextLength > 0)) {
        firstResponseMs = Date.now() - startedMs;
      }
      uiError = classifyVisibleFlags(snap.bodyText);
      if (uiError) {
        outcome = uiError;
        terminal = true;
        break;
      }
      if (snap.assistantHasExpected && !snap.stopVisible) {
        outcome = 'expected_response_complete';
        terminal = true;
        break;
      }
    }

    result.attempts.push({
      attempt,
      started_at: startedAt,
      ended_at: nowIso(),
      outcome,
      first_response_ms: firstResponseMs,
      completion_ms: terminal ? Date.now() - startedMs : null,
      ui_error: uiError,
      network_events: result.network.length - networkStart,
      console_errors: result.console_error_count - consoleStart,
      page_errors: result.page_error_count - pageErrorStart,
      request_failures: result.request_failure_count - requestFailureStart,
    });
  }

  result.ok = result.auth_state === 'authenticated_ui_ready' &&
    result.attempts.length === ATTEMPTS &&
    result.attempts.every(x => x.outcome === 'expected_response_complete');
  if (!result.ok && !result.blocker) result.blocker = 'controlled_reproduction_failed';
  result.finished_at = nowIso();
  persist();

  const publicSummary = {
    schema: result.schema,
    started_at: result.started_at,
    finished_at: result.finished_at,
    auth_state: result.auth_state,
    browser_route: result.browser_route,
    browser_worker_available: result.browser_worker_available,
    canonical_profile_exists: result.canonical_profile_exists,
    model_label: result.model_label,
    attempts: result.attempts,
    network_error_statuses: result.network
      .filter(x => x.kind === 'response' && Number(x.status) >= 400)
      .map(x => ({ path: x.path, status: x.status })),
    request_failure_count: result.request_failure_count,
    console_error_count: result.console_error_count,
    page_error_count: result.page_error_count,
    blocker: result.blocker,
    ok: result.ok,
  };
  console.log('CHATGPT_ACCOUNT_DIAGNOSTIC=' + JSON.stringify(publicSummary));
  if (!result.ok) process.exitCode = 2;
} catch (error) {
  result.finished_at = nowIso();
  if (!result.blocker) result.blocker = String(error?.message || 'diagnostic_failed').slice(0, 120);
  persist();
  console.log('CHATGPT_ACCOUNT_DIAGNOSTIC=' + JSON.stringify({
    started_at: result.started_at,
    finished_at: result.finished_at,
    auth_state: result.auth_state,
    browser_route: result.browser_route,
    browser_worker_available: result.browser_worker_available,
    canonical_profile_exists: result.canonical_profile_exists,
    blocker: result.blocker,
    ok: false,
  }));
  process.exitCode = 2;
} finally {
  if (page) await page.close().catch(() => {});
  if (launchedFallback && fallbackContext) await fallbackContext.close().catch(() => {});
}
