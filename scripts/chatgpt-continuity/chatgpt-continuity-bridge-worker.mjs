#!/usr/bin/env node
// Nudges the user's own, already-logged-in ChatGPT conversation ("continue")
// when regular Chat comum stops before finishing a long task. Chat comum has
// no real background execution: everything happens inside one streamed
// reply, and once that turn ends -- naturally, on a network drop, or on any
// other interruption -- nothing continues until a new message starts a new
// turn (see docs/knowledge/task-continuity.md, CHATGPT_RESUME_ORDER_V5).
// This worker is that new message, sent automatically instead of requiring
// the human to notice and type it.
//
// Canonical runtime: always-free-arm-1787907847-26, attached to the existing
// authenticated ChatGPT browser over CDP 127.0.0.1:9555. The Windows
// installer is retained only as a legacy/fallback route.
//
// Unlike scripts/amazon-returns/seller-central-bridge-worker.mjs, this
// worker NEVER spawns its own browser instance: doing so would create a
// separate, logged-out browser context, not the user's real conversation.
// It only attaches, via CDP, to a browser the user already launched with a
// remote-debugging port open. If that port is not reachable, the worker
// fails loudly with an actionable message instead of silently doing nothing.
import fs from 'node:fs';
import { createHash } from 'node:crypto';

const BRIDGE_ENDPOINT = process.env.CHATGPT_CONTINUITY_BRIDGE_ENDPOINT
  || 'http://10.0.1.112:8080/api/chatgpt-continuity/bridge.php';
const BRIDGE_HOST_HEADER = process.env.CHATGPT_CONTINUITY_BRIDGE_HOST_HEADER || 'shopvivaliz.com.br';
const TOKEN_FILE = process.env.CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE
  || '/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token';
const CDP_BASE = process.env.CHATGPT_CONTINUITY_CDP_URL || 'http://127.0.0.1:9555';
const POLL_MS = Math.max(5000, Number(process.env.CHATGPT_CONTINUITY_POLL_MS || 15000));
const STALL_REINFORCEMENT_ENABLED = process.env.CHATGPT_CONTINUITY_STALL_MONITOR !== '0';
const CONTINUE_MESSAGE = process.env.CHATGPT_CONTINUITY_MESSAGE || 'continue';

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const text = value => String(value ?? '').replace(/\s+/g, ' ').trim();
const sha = value => createHash('sha256').update(String(value ?? '')).digest('hex');

function interruptionTextPresent(value) {
  const haystack = String(value ?? '').toLowerCase();
  return (
    haystack.includes('something went wrong')
    || haystack.includes('algo deu errado')
    || haystack.includes('there was an error generating')
    || haystack.includes('houve um erro ao gerar')
    || haystack.includes('streaming interrupted')
    || haystack.includes('transmissão interrompida')
    || haystack.includes('transmissao interrompida')
  );
}

function selectChatgptTab(tabs) {
  const conversations = (Array.isArray(tabs) ? tabs : []).filter(
    tab => tab?.type === 'page' && /^https:\/\/chatgpt\.com\/c\//.test(String(tab.url || '')),
  );
  const interrupted = conversations.filter(tab => interruptionTextPresent(tab.text));
  if (interrupted.length === 1) return interrupted[0];
  if (interrupted.length > 1) return null;
  return conversations.length === 1 ? conversations[0] : null;
}

function token() {
  const value = fs.readFileSync(TOKEN_FILE, 'utf8').trim();
  if (value.length < 32) throw new Error('bridge token missing or too short');
  return value;
}

async function bridge(operation, payload = {}) {
  const response = await fetch(BRIDGE_ENDPOINT, {
    method: 'POST',
    headers: {
      authorization: `Bearer ${token()}`,
      'content-type': 'application/json',
      accept: 'application/json',
      'user-agent': 'ShopVivaliz-ChatgptContinuityBridge/1.0',
      ...(BRIDGE_HOST_HEADER ? { Host: BRIDGE_HOST_HEADER } : {}),
    },
    body: JSON.stringify({ operation, ...payload }),
    signal: AbortSignal.timeout(20000),
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`bridge HTTP ${response.status}: ${text(body.status)}`);
  return body;
}

async function cdpReady() {
  try {
    const response = await fetch(`${CDP_BASE}/json/version`, { signal: AbortSignal.timeout(2500) });
    const data = await response.json();
    return Boolean(data.webSocketDebuggerUrl || true);
  } catch {
    return false;
  }
}

class Cdp {
  constructor(ws) {
    this.ws = ws;
    this.id = 0;
    this.pending = new Map();
    ws.addEventListener('message', event => {
      const message = JSON.parse(event.data);
      if (!message.id || !this.pending.has(message.id)) return;
      const waiter = this.pending.get(message.id);
      this.pending.delete(message.id);
      message.error ? waiter.reject(new Error(message.error.message || 'CDP error')) : waiter.resolve(message.result);
    });
  }

  static async connectToChatgptTab() {
    if (!(await cdpReady())) {
      throw new Error(
        `CDP endpoint unreachable at ${CDP_BASE}. This worker never launches its own browser -- `
        + 'it only attaches to one you already have open and logged into ChatGPT. Launch it with '
        + `--remote-debugging-port=${new URL(CDP_BASE).port} (see docs/AGENT-VM-PROMPTS.md).`
      );
    }
    const tabs = await (await fetch(`${CDP_BASE}/json`)).json();
    const conversationTabs = tabs.filter(
      tab => tab.type === 'page' && /^https:\/\/chatgpt\.com\/c\//.test(String(tab.url || '')) && tab.webSocketDebuggerUrl,
    );
    if (conversationTabs.length === 0) {
      throw new Error('no open ChatGPT conversation tab found in the attached browser');
    }

    if (conversationTabs.length === 1) {
      const ws = new WebSocket(conversationTabs[0].webSocketDebuggerUrl);
      await new Promise((resolve, reject) => {
        ws.addEventListener('open', resolve, { once: true });
        ws.addEventListener('error', reject, { once: true });
      });
      return new Cdp(ws);
    }

    // When multiple conversations are open, inspect each one and select only
    // a unique tab carrying an explicit interruption. Never guess by tab
    // order: sending "continue" into the wrong conversation is worse than a
    // safe no-op.
    const enriched = [];
    for (const tab of conversationTabs) {
      let probe;
      try {
        const ws = new WebSocket(tab.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => {
          ws.addEventListener('open', resolve, { once: true });
          ws.addEventListener('error', reject, { once: true });
        });
        probe = new Cdp(ws);
        const state = await probe.pageState(6000);
        enriched.push({ ...tab, text: state.text || '' });
      } catch {
        enriched.push({ ...tab, text: '' });
      } finally {
        probe?.close();
      }
    }
    const page = selectChatgptTab(enriched);
    if (!page?.webSocketDebuggerUrl) {
      throw new Error('multiple ChatGPT conversation tabs are open and no unique interrupted target was found');
    }
    const ws = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
      ws.addEventListener('open', resolve, { once: true });
      ws.addEventListener('error', reject, { once: true });
    });
    return new Cdp(ws);
  }

  send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = ++this.id;
      this.pending.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  async evaluate(expression) {
    const result = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error('browser expression failed');
    return result.result?.value;
  }

  async pageState(limit = 8000) {
    return this.evaluate(`JSON.stringify({href:location.href,title:document.title,text:(document.body?.innerText||'').slice(0,${limit})})`)
      .then(value => JSON.parse(value || '{}'));
  }

  close() {
    try { this.ws.close(); } catch {}
  }
}

// OpenAI's stable, documented selectors for the ChatGPT web composer. These
// are the same data-testid attributes OpenAI itself uses in first-party
// automation examples; still, UI can drift -- see the UI_DRIFT result below,
// mirroring the same recognized failure mode as the Amazon Returns bridge.
async function conversationIsGenerating(cdp) {
  return cdp.evaluate(`Boolean(document.querySelector('[data-testid="stop-button"]'))`);
}

async function composerIsUsable(cdp) {
  return cdp.evaluate(`(()=>{const b=document.querySelector('[data-testid="send-button"]');return Boolean(b)&&!b.disabled})()`);
}

async function errorBannerPresent(cdp) {
  // ChatGPT surfaces an explicit banner on a genuine stream failure -- this
  // is the one unambiguous, well-known DOM signal available to a script
  // that has no semantic understanding of whether a "complete-looking"
  // reply is actually incomplete. Anything short of that ambiguous case is
  // deliberately left to the checkpoint-driven trigger, not guessed here.
  const state = await cdp.pageState(6000);
  return interruptionTextPresent(state.text);
}

async function sendContinueMessage(cdp) {
  const composerSelector = '[data-testid="prompt-textarea"]';
  const typed = await cdp.evaluate(`(()=>{
    const el = document.querySelector(${JSON.stringify(composerSelector)});
    if (!el) return false;
    el.focus();
    const isContentEditable = el.getAttribute('contenteditable') === 'true';
    if (isContentEditable) {
      document.execCommand('insertText', false, ${JSON.stringify(CONTINUE_MESSAGE)});
    } else {
      const proto = HTMLTextAreaElement.prototype;
      Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(CONTINUE_MESSAGE)});
      el.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: ${JSON.stringify(CONTINUE_MESSAGE)} }));
    }
    return true;
  })()`);
  if (!typed) return false;
  await sleep(300);
  return cdp.evaluate(`(()=>{const b=document.querySelector('[data-testid="send-button"]');if(!b||b.disabled)return false;b.click();return true})()`);
}

async function attemptNudge(taskId, connect = () => Cdp.connectToChatgptTab()) {
  let cdp;
  try {
    cdp = await connect();
    if (await conversationIsGenerating(cdp)) {
      // A real stream is already in flight; nudging now would interleave
      // an unwanted "continue" mid-answer. Report as not-yet-confirmed
      // rather than forcing it -- the caller may retry on the next tick.
      return { result_status: 'STALLED_NOT_CONFIRMED', detail: 'conversation is actively generating, deferred' };
    }
    if (!(await composerIsUsable(cdp))) {
      return { result_status: 'CONVERSATION_NOT_FOUND', detail: 'composer/send-button selector not found (possible UI drift)' };
    }
    const sent = await sendContinueMessage(cdp);
    return sent
      ? { result_status: 'SENT', detail: `typed "${CONTINUE_MESSAGE}" and clicked send` }
      : { result_status: 'ERROR', detail: 'composer found but send failed' };
  } catch (error) {
    return { result_status: 'ERROR', detail: text(error?.message).slice(0, 400) };
  } finally {
    cdp?.close();
  }
}

async function pollBridgeOnce() {
  const response = await bridge('pull');
  if (response.status !== 'JOB') return;
  const taskId = response.nudge?.task_id;
  if (!taskId) return;
  const outcome = await attemptNudge(taskId);
  await bridge('result', { task_id: taskId, ...outcome });
  console.log(`chatgpt_continuity_nudge task_id=${taskId} result=${outcome.result_status}`);
}

// "Streaming interrupted, waiting for the complete message" is shown while
// ChatGPT's own client is already retrying -- confirmed live: the banner
// carries a spinner, not a dead end. Nudging on the very first sighting
// risks colliding with that in-flight auto-retry (duplicate/garbled
// message). Requiring the banner to still be present after a short grace
// window filters out the transient flash and only acts once the client's
// own retry has genuinely given up.
const REINFORCEMENT_CONFIRM_DELAY_MS = Math.max(3000, Number(process.env.CHATGPT_CONTINUITY_REINFORCEMENT_CONFIRM_MS || 8000));

async function reinforcementCheckOnce(
  connect = () => Cdp.connectToChatgptTab(),
  confirmDelayMs = REINFORCEMENT_CONFIRM_DELAY_MS,
) {
  let cdp;
  try {
    cdp = await connect();
    if (!(await errorBannerPresent(cdp))) return { action: 'no_banner' };
    await sleep(confirmDelayMs);
    cdp.close();
    cdp = await connect();
    if (!(await errorBannerPresent(cdp))) {
      console.log('chatgpt_continuity_reinforcement error_banner_self_resolved');
      return { action: 'self_resolved' };
    }
    const sent = await sendContinueMessage(cdp);
    console.log(`chatgpt_continuity_reinforcement error_banner_confirmed sent=${sent}`);
    return { action: 'confirmed', sent };
  } catch (error) {
    // The reinforcement monitor is best-effort: the checkpoint-driven path
    // above is the primary trigger and already surfaces real failures.
    return { action: 'error', detail: text(error?.message) };
  } finally {
    cdp?.close();
  }
}

async function mainLoop() {
  for (;;) {
    try {
      await pollBridgeOnce();
    } catch (error) {
      console.error(`chatgpt_continuity_bridge_poll_error ${text(error?.message)}`);
    }
    if (STALL_REINFORCEMENT_ENABLED) {
      try {
        await reinforcementCheckOnce();
      } catch (error) {
        console.error(`chatgpt_continuity_reinforcement_error ${text(error?.message)}`);
      }
    }
    await sleep(POLL_MS);
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  mainLoop();
}

export {
  Cdp,
  conversationIsGenerating,
  composerIsUsable,
  errorBannerPresent,
  sendContinueMessage,
  attemptNudge,
  reinforcementCheckOnce,
  selectChatgptTab,
};
