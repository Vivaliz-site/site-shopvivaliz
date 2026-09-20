import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { chromium } from 'playwright-core';

const HOST = '127.0.0.1';
const PORT = Number(process.env.SHOPVIVALIZ_BROWSER_PORT || '17777');
const STATE_DIR = process.env.SHOPVIVALIZ_BROWSER_STATE || '/home/ubuntu/.local/share/shopvivaliz-browser-worker';
const BROWSER_PATH = process.env.SHOPVIVALIZ_CHROMIUM_PATH || '/home/ubuntu/.cache/ms-playwright/chromium-1234/chrome-linux/chrome';
const DEFAULT_TTL = 2 * 60 * 60;
const MAX_TTL = 12 * 60 * 60;
const sessions = new Map();

fs.mkdirSync(path.join(STATE_DIR, 'profiles'), { recursive: true, mode: 0o700 });

function json(res, status, payload) {
  const body = Buffer.from(JSON.stringify(payload));
  res.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'content-length': String(body.length),
    'cache-control': 'no-store',
  });
  res.end(body);
}

function validHttpUrl(raw) {
  const value = String(raw || '').trim();
  if (!value) return 'about:blank';
  const u = new URL(value);
  if (!['http:', 'https:', 'about:'].includes(u.protocol)) throw new Error('unsupported_url_scheme');
  return u.toString();
}

function safeName(value, fallback = 'session') {
  const out = String(value || '').trim().replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
  return out || fallback;
}

function ttlSeconds(value) {
  const n = Number(value || DEFAULT_TTL);
  if (!Number.isFinite(n)) return DEFAULT_TTL;
  return Math.max(300, Math.min(MAX_TTL, Math.floor(n)));
}

async function readJson(req) {
  const chunks = [];
  let total = 0;
  for await (const chunk of req) {
    total += chunk.length;
    if (total > 1024 * 1024) throw new Error('request_too_large');
    chunks.push(chunk);
  }
  if (!chunks.length) return {};
  return JSON.parse(Buffer.concat(chunks).toString('utf8'));
}

function currentPage(session) {
  const pages = session.context.pages();
  session.page = pages.at(-1) || session.page;
  return session.page;
}

async function publicSession(session) {
  const page = currentPage(session);
  let title = '';
  let url = '';
  try { title = await page.title(); } catch {}
  try { url = page.url(); } catch {}
  return {
    id: session.id,
    label: session.label,
    origin: session.origin,
    persistent: session.persistent,
    profile: session.profile,
    started_at: new Date(session.startedAt).toISOString(),
    last_activity_at: new Date(session.lastActivity).toISOString(),
    expires_at: new Date(session.expiresAt).toISOString(),
    worker_pid: process.pid,
    browser_pid: null,
    url,
    title,
  };
}

async function closeSession(session) {
  if (!session || session.closing) return;
  session.closing = true;
  try { await session.context.close(); } catch {}
  try { await session.browser?.close(); } catch {}
  sessions.delete(session.id);
}

async function createSession(body) {
  const label = safeName(body.label, 'browser');
  const origin = safeName(body.origin, 'unknown');
  const persistent = Boolean(body.persistent);
  const profile = persistent ? safeName(body.profile, label) : null;
  const ttl = ttlSeconds(body.ttl_seconds);
  const url = validHttpUrl(body.url || 'about:blank');
  const common = {
    executablePath: BROWSER_PATH,
    headless: false,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-background-networking'],
  };

  let context;
  let browser = null;
  if (persistent) {
    const profileDir = path.join(STATE_DIR, 'profiles', profile);
    fs.mkdirSync(profileDir, { recursive: true, mode: 0o700 });
    context = await chromium.launchPersistentContext(profileDir, {
      ...common,
      viewport: { width: 1440, height: 900 },
    });
  } else {
    browser = await chromium.launch(common);
    context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  }
  const page = context.pages()[0] || await context.newPage();
  page.setDefaultTimeout(15000);
  const now = Date.now();
  const session = {
    id: crypto.randomUUID(),
    label,
    origin,
    persistent,
    profile,
    browser,
    context,
    page,
    startedAt: now,
    lastActivity: now,
    expiresAt: now + ttl * 1000,
    closing: false,
  };
  sessions.set(session.id, session);
  if (url !== 'about:blank') {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {});
  }
  return session;
}

function touch(session, ttl = DEFAULT_TTL) {
  const now = Date.now();
  session.lastActivity = now;
  session.expiresAt = now + ttlSeconds(ttl) * 1000;
}

function getSession(id) {
  const session = sessions.get(id);
  if (!session) throw Object.assign(new Error('session_not_found'), { status: 404 });
  return session;
}

async function performAction(session, body) {
  const page = currentPage(session);
  const action = String(body.action || '');
  if (action === 'goto') {
    await page.goto(validHttpUrl(body.url), { waitUntil: 'domcontentloaded', timeout: 45000 });
  } else if (action === 'click') {
    const x = Math.max(0, Math.min(1440, Number(body.x)));
    const y = Math.max(0, Math.min(900, Number(body.y)));
    if (!Number.isFinite(x) || !Number.isFinite(y)) throw new Error('invalid_coordinates');
    await page.mouse.click(x, y);
  } else if (action === 'type') {
    await page.keyboard.insertText(String(body.text || '').slice(0, 4000));
  } else if (action === 'key') {
    const allowed = new Set(['Enter','Tab','Escape','Backspace','ArrowUp','ArrowDown','ArrowLeft','ArrowRight','PageUp','PageDown','Space','Home','End','Delete']);
    const key = String(body.key || '');
    if (!allowed.has(key)) throw new Error('key_not_allowed');
    await page.keyboard.press(key);
  } else if (action === 'scroll') {
    const dy = Math.max(-2400, Math.min(2400, Number(body.dy || 0)));
    await page.mouse.wheel(0, dy);
  } else if (action === 'click_selector') {
    const selector = String(body.selector || '').slice(0, 500);
    if (!selector) throw new Error('selector_required');
    await page.locator(selector).first().click();
  } else if (action === 'fill_selector') {
    const selector = String(body.selector || '').slice(0, 500);
    if (!selector) throw new Error('selector_required');
    await page.locator(selector).first().fill(String(body.text || '').slice(0, 4000));
  } else if (action === 'new_page') {
    session.page = await session.context.newPage();
  } else {
    throw new Error('action_not_allowed');
  }
  touch(session, body.ttl_seconds || DEFAULT_TTL);
}

const chromiumVersion = (() => {
  try {
    return spawnSync(BROWSER_PATH, ['--version'], { encoding: 'utf8', timeout: 5000 }).stdout.trim();
  } catch {
    return '';
  }
})();

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, `http://${HOST}:${PORT}`);
    const parts = url.pathname.split('/').filter(Boolean);

    if (req.method === 'GET' && url.pathname === '/health') {
      return json(res, 200, {
        ok: true,
        endpoint: 'browser-worker',
        chromium: chromiumVersion,
        browser_path_exists: fs.existsSync(BROWSER_PATH),
        display: process.env.DISPLAY || '',
        active_sessions: sessions.size,
        default_ttl_seconds: DEFAULT_TTL,
      });
    }

    if (req.method === 'GET' && url.pathname === '/sessions') {
      const items = [];
      for (const session of sessions.values()) items.push(await publicSession(session));
      return json(res, 200, { ok: true, sessions: items });
    }

    if (req.method === 'POST' && url.pathname === '/sessions') {
      const session = await createSession(await readJson(req));
      return json(res, 201, { ok: true, session: await publicSession(session) });
    }

    if (parts[0] === 'sessions' && parts[1]) {
      const session = getSession(parts[1]);

      if (req.method === 'GET' && parts[2] === 'screenshot') {
        const png = await currentPage(session).screenshot({ type: 'png' });
        touch(session);
        res.writeHead(200, {
          'content-type': 'image/png',
          'content-length': String(png.length),
          'cache-control': 'no-store',
        });
        return res.end(png);
      }

      if (req.method === 'POST' && parts[2] === 'renew') {
        const body = await readJson(req);
        touch(session, body.ttl_seconds || DEFAULT_TTL);
        return json(res, 200, { ok: true, session: await publicSession(session) });
      }

      if (req.method === 'POST' && parts[2] === 'action') {
        const body = await readJson(req);
        await performAction(session, body);
        return json(res, 200, { ok: true, session: await publicSession(session) });
      }

      if (req.method === 'POST' && parts[2] === 'close') {
        await closeSession(session);
        return json(res, 200, { ok: true });
      }
    }

    return json(res, 404, { ok: false, error: 'not_found' });
  } catch (error) {
    const status = Number(error?.status || 400);
    return json(res, status, { ok: false, error: String(error?.message || 'request_failed').slice(0, 200) });
  }
});

server.listen(PORT, HOST, () => {
  process.stdout.write(`BROWSER_WORKER_LISTENING=${HOST}:${PORT}\n`);
});

setInterval(async () => {
  const now = Date.now();
  for (const session of [...sessions.values()]) {
    if (session.expiresAt <= now) await closeSession(session);
  }
}, 60000).unref();

async function shutdown() {
  for (const session of [...sessions.values()]) await closeSession(session);
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 5000).unref();
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
