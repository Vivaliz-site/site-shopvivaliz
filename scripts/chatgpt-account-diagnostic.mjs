import fs from 'node:fs';
import path from 'node:path';

const ATTEMPTS = 3;
const PROMPT = 'Responda apenas: TESTE-OK';
const CDP_URL = 'http://127.0.0.1:9555';
const BROWSER_WORKER_URL = 'http://127.0.0.1:17777';
const CANONICAL_PROFILE = '/home/ubuntu/.local/share/shopvivaliz-browser-worker/profiles/ai-squad-chatgpt';
const CANONICAL_BROWSER = '/home/ubuntu/.local/bin/shopvivaliz-browser-chromium';
const OUTPUT = process.env.CHATGPT_ACCOUNT_DIAG_OUTPUT || '/tmp/chatgpt-account-diagnostic.json';
const OUTPUT_DIR = process.env.CHATGPT_ACCOUNT_DIAG_DIR || path.dirname(OUTPUT);
const HAR_OUTPUT = path.join(OUTPUT_DIR, 'sanitized.har.json');
const CONSOLE_OUTPUT = path.join(OUTPUT_DIR, 'console-errors.json');
const BLOCKER_SCREENSHOT = path.join(OUTPUT_DIR, 'blocker.png');
fs.mkdirSync(OUTPUT_DIR, { recursive: true, mode: 0o700 });
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

function sanitizeConsoleText(raw) {
  return String(raw || '')
    .replace(/https?:\/\/[^\s"'<>]+/gi, value => safePath(value))
    .replace(/[A-Za-z0-9_-]{48,}/g, '[REDACTED_LONG_TOKEN]')
    .replace(/[A-Fa-f0-9]{40,}/g, '[REDACTED_HEX]')
    .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '[REDACTED_EMAIL]')
    .slice(0, 1000);
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

function resolveBrowserPath(chromium) {
  const candidates = [
    String(process.env.CHATGPT_ACCOUNT_BROWSER_PATH || '').trim(),
    CANONICAL_BROWSER,
    '/usr/bin/chromium',
    '/snap/bin/chromium',
    '/usr/bin/chromium-browser',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/google-chrome',
    chromium.executablePath(),
  ];
  for (const candidate of candidates.filter(Boolean)) {
    try {
      if (fs.existsSync(candidate) && fs.statSync(candidate).isFile()) return candidate;
    } catch {}
  }
  return '';
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

async function collectBlockerEvidence(page) {
  const state = await page.evaluate(() => {
    const body = String(document.body?.innerText || '').toLowerCase();
    const controls = Array.from(document.querySelectorAll('button,a'))
      .map(node => String(node.textContent || '').trim().toLowerCase())
      .filter(Boolean);
    const composerCandidates = document.querySelectorAll(
      '#prompt-textarea, textarea[data-testid*="prompt"], textarea, div[contenteditable="true"]'
    ).length;
    return {
      title_length: String(document.title || '').length,
      composer_candidate_count: composerCandidates,
      login_prompt_present: controls.some(text => /^(log in|sign in|entrar|fazer login)$/.test(text)),
      signup_prompt_present: controls.some(text => /^(sign up|criar conta|cadastre-se)$/.test(text)),
      session_expired_present: /session expired|your session has expired|sessão expirou|sua sessão expirou/.test(body),
      challenge_present: /verify you are human|checking your browser|verifique se você é humano|verificando seu navegador|additional checks|verificações adicionais/.test(body),
      visible_error: classifyVisibleFlags(body),
    };
  }).catch(() => ({
    title_length: 0,
    composer_candidate_count: 0,
    login_prompt_present: false,
    signup_prompt_present: false,
    session_expired_present: false,
    challenge_present: false,
    visible_error: '',
  }));

  result.blocker_page = {
    url: safePath(page.url()),
    ...state,
  };

  const main = page.locator('main').first();
  try {
    if (await main.isVisible({ timeout: 1000 })) {
      await main.screenshot({
        path: BLOCKER_SCREENSHOT,
        mask: [
          main.locator('input'),
          main.locator('[data-testid*="profile"]'),
          main.locator('[data-testid*="account"]'),
        ],
      });
    }
  } catch {}
  result.blocker_screenshot = fs.existsSync(BLOCKER_SCREENSHOT) ? path.basename(BLOCKER_SCREENSHOT) : '';
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
  websockets: [],
  console_errors: [],
  console_error_count: 0,
  page_error_count: 0,
  request_failure_count: 0,
  blocker: '',
  blocker_page: null,
  blocker_screenshot: '',
  ok: false,
};

function persistSupportArtifacts() {
  const harEntries = result.network
    .filter(item => item.kind === 'response')
    .map(item => ({
      startedDateTime: item.at,
      time: 0,
      request: {
        method: item.method,
        url: item.path,
        httpVersion: '',
        headers: [],
        queryString: [],
        cookies: [],
        headersSize: -1,
        bodySize: -1,
      },
      response: {
        status: item.status,
        statusText: '',
        httpVersion: '',
        headers: [
          ['x-oai-request-id', item.request_id],
          ['x-oai-turn-trace-id', item.turn_trace_id],
          ['cf-ray', item.cf_ray],
          ['date', item.response_date],
        ].filter(([, value]) => Boolean(value)).map(([name, value]) => ({ name, value })),
        cookies: [],
        content: { size: 0, mimeType: '' },
        redirectURL: '',
        headersSize: -1,
        bodySize: -1,
      },
      cache: {},
      timings: { send: 0, wait: 0, receive: 0 },
    }));
  fs.writeFileSync(HAR_OUTPUT, JSON.stringify({
    log: {
      version: '1.2',
      creator: { name: 'shopvivaliz-chatgpt-account-diagnostic', version: '1' },
      pages: [],
      entries: harEntries,
    },
  }, null, 2) + '\n', { mode: 0o600 });
  fs.writeFileSync(CONSOLE_OUTPUT, JSON.stringify(result.console_errors, null, 2) + '\n', { mode: 0o600 });
}

function persist() {
  fs.writeFileSync(OUTPUT, JSON.stringify(result, null, 2) + '\n', { mode: 0o600 });
  persistSupportArtifacts();
}

let page;
let fallbackContext = null;
let launchedFallback = false;
try {
  const { chromium, candidate } = await loadChromium();
  result.playwright_module = candidate.replace(/^\/home\/[^/]+\//, '/home/:user/');
  const browserPath = resolveBrowserPath(chromium);
  let browser;
  let context;

  if (forcedProfile) {
    result.canonical_profile_exists = fs.existsSync(forcedProfile);
    if (!result.canonical_profile_exists) {
      result.blocker = 'forced_profile_missing';
      persist();
      throw new Error(result.blocker);
    }
    if (!browserPath) {
      result.blocker = 'canonical_browser_missing';
      persist();
      throw new Error(result.blocker);
    }
    const display = String(process.env.DISPLAY || '').trim() || ':98';
    try {
      fallbackContext = await chromium.launchPersistentContext(forcedProfile, {
        executablePath: browserPath,
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
          executablePath: browserPath,
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
    if (message.type() !== 'error') return;
    result.console_error_count += 1;
    if (result.console_errors.length < 100) {
      result.console_errors.push({ at: nowIso(), kind: 'console', text: sanitizeConsoleText(message.text()) });
    }
  });
  page.on('pageerror', error => {
    result.page_error_count += 1;
    if (result.console_errors.length < 100) {
      result.console_errors.push({ at: nowIso(), kind: 'pageerror', text: sanitizeConsoleText(error?.message || error) });
    }
  });
  page.on('websocket', socket => {
    if (!allowedHost(socket.url())) return;
    const entry = { at: nowIso(), url: safePath(socket.url()), closed_at: '', error: '' };
    result.websockets.push(entry);
    socket.on('close', () => { entry.closed_at = nowIso(); });
    socket.on('socketerror', error => { entry.error = sanitizeConsoleText(error); });
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
      request_id: headers['x-oai-request-id'] || headers['x-request-id'] || '',
      turn_trace_id: headers['x-oai-turn-trace-id'] || '',
      cf_ray: headers['cf-ray'] || '',
      response_date: headers['date'] || '',
    });
  });

  await page.goto('https://chatgpt.com/', { waitUntil: 'domcontentloaded', timeout: 45000 });
  const initialComposer = await findComposer(page);
  if (!initialComposer) {
    result.auth_state = 'not_ready_or_not_authenticated';
    result.blocker = 'composer_unavailable';
    await collectBlockerEvidence(page);
    persist();
    throw new Error(result.blocker);
  }

  result.auth_state = 'authenticated_ui_ready';
  result.model_label = await modelLabel(page);

  for (let attempt = 1; attempt <= ATTEMPTS; attempt += 1) {
    await page.goto('https://chatgpt.com/', { waitUntil: 'domcontentloaded', timeout: 45000 });
    const composer = await findComposer(page);
    if (!composer) {
      await collectBlockerEvidence(page);
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

    const screenshotFile = path.join(OUTPUT_DIR, `attempt-${attempt}.png`);
    const mainSurface = page.locator('main').first();
    if (await mainSurface.isVisible().catch(() => false)) {
      await mainSurface.screenshot({ path: screenshotFile }).catch(() => {});
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
      screenshot_file: fs.existsSync(screenshotFile) ? path.basename(screenshotFile) : '',
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
    websocket_count: result.websockets.length,
    safe_request_ids: result.network
      .filter(x => x.kind === 'response' && (x.request_id || x.turn_trace_id || x.cf_ray))
      .slice(-20)
      .map(x => ({
        path: x.path,
        status: x.status,
        request_id: x.request_id,
        turn_trace_id: x.turn_trace_id,
        cf_ray: x.cf_ray,
        response_date: x.response_date,
      })),
    blocker: result.blocker,
    blocker_page: result.blocker_page,
    blocker_screenshot: result.blocker_screenshot,
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
    blocker_page: result.blocker_page,
    blocker_screenshot: result.blocker_screenshot,
    ok: false,
  }));
  process.exitCode = 2;
} finally {
  if (page) await page.close().catch(() => {});
  if (launchedFallback && fallbackContext) await fallbackContext.close().catch(() => {});
}
