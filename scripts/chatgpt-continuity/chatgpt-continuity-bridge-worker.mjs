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
const PROGRESS_CONFIRM_MS = Math.max(5000, Number(process.env.CHATGPT_CONTINUITY_PROGRESS_CONFIRM_MS || 90000));
const PROGRESS_POLL_MS = Math.max(1000, Number(process.env.CHATGPT_CONTINUITY_PROGRESS_POLL_MS || 2000));
const PASSIVE_REATTACH_CONFIRM_MS = Math.max(
  3000,
  Number(process.env.CHATGPT_CONTINUITY_PASSIVE_REATTACH_CONFIRM_MS || 15000),
);
const RECENT_CONVERSATION_MAX_AGE_MS = Math.max(
  60_000,
  Number(process.env.CHATGPT_CONTINUITY_RECENT_CONVERSATION_MAX_AGE_MS || 10 * 60_000),
);

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const text = value => String(value ?? '').replace(/\s+/g, ' ').trim();
const sha = value => createHash('sha256').update(String(value ?? '')).digest('hex');

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
    const page = tabs.find(tab => tab.type === 'page' && /^https:\/\/chatgpt\.com\//.test(tab.url || ''));
    if (!page?.webSocketDebuggerUrl) {
      throw new Error('no open chatgpt.com tab found in the attached browser');
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

async function conversationStreamStatus(cdp) {
  return cdp.evaluate(`(async()=>{
    const match=location.pathname.match(/^\\/c\\/([^/?#]+)/);
    if(!match) return {http_status:0,status:'NO_CONVERSATION'};
    try{
      const response=await fetch('/backend-api/conversation/'+encodeURIComponent(match[1])+'/stream_status',{credentials:'same-origin'});
      let body=null;
      try{body=await response.json();}catch{}
      return {http_status:response.status,status:String(body?.status||'')};
    }catch{
      return {http_status:0,status:'FETCH_FAILED'};
    }
  })()`);
}

async function clearStaleCompleteGeneration(cdp) {
  const clicked = await cdp.evaluate(`(()=>{
    /* stale-complete-stop-clear */
    const button=document.querySelector('[data-testid="stop-button"]');
    if(!button) return true;
    button.click();
    return true;
  })()`);
  if (!clicked) return false;
  await sleep(1200);
  return !(await conversationIsGenerating(cdp));
}

async function composerIsUsable(cdp) {
  return cdp.evaluate(`(()=>{const b=document.querySelector('[data-testid="send-button"]');return Boolean(b)&&!b.disabled})()`);
}

async function assistantSnapshot(cdp) {
  return cdp.evaluate(`(()=>{
    const nodes=Array.from(document.querySelectorAll('[data-message-author-role="assistant"]'));
    const last=nodes.length ? nodes[nodes.length-1] : null;
    const lastText=(last?.innerText||last?.textContent||'').trim();
    return {count:nodes.length,lastText,lastLength:lastText.length};
  })()`);
}

function assistantProgressed(before, after) {
  const prior = before || { count: 0, lastText: '', lastLength: 0 };
  const current = after || { count: 0, lastText: '', lastLength: 0 };
  if (Number(current.count || 0) > Number(prior.count || 0)) return true;
  const priorText = String(prior.lastText || '');
  const currentText = String(current.lastText || '');
  return currentText.length > priorText.length && currentText !== priorText;
}

async function confirmAssistantProgress(
  cdp,
  baseline,
  timeoutMs = PROGRESS_CONFIRM_MS,
  pollMs = PROGRESS_POLL_MS,
) {
  const deadline = Date.now() + Math.max(1000, Number(timeoutMs || PROGRESS_CONFIRM_MS));
  while (Date.now() < deadline) {
    await sleep(Math.max(250, Number(pollMs || PROGRESS_POLL_MS)));
    const current = await assistantSnapshot(cdp);
    if (assistantProgressed(baseline, current)) return true;

    // If ChatGPT has already finalized the stream and no assistant content
    // advanced, waiting longer cannot turn a click into a successful resume.
    const generating = await conversationIsGenerating(cdp);
    if (!generating) {
      const stream = await conversationStreamStatus(cdp);
      if (stream?.http_status === 200 && stream?.status === 'COMPLETE') {
        return false;
      }
    }
  }
  return false;
}

async function latestConversationProbe(cdp) {
  try {
    const result = await cdp.evaluate(`(async()=>{
      const candidates = [
        {source:'filtered',url:'/backend-api/conversations?offset=0&limit=1&order=updated&is_archived=false&is_starred=false'},
        {source:'fallback_unfiltered',url:'/backend-api/conversations?offset=0&limit=1&order=updated'},
      ];
      let last={http_status:0,source:'none',item_present:false,item_keys:[]};
      for(const candidate of candidates){
        try{
          const response=await fetch(candidate.url,{credentials:'same-origin',cache:'no-store'});
          let body=null; try{body=await response.json();}catch{}
          const items=Array.isArray(body?.items)?body.items:(Array.isArray(body?.conversations)?body.conversations:(Array.isArray(body)?body:[]));
          last={http_status:Number(response.status||0),source:candidate.source,item_present:items.length>0,item_keys:[]};
          if(!response.ok||items.length===0) continue;
          const item=items[0]||{};
          const item_keys=Object.keys(item)
            .map(key=>String(key).replace(/[^A-Za-z0-9_]/g,'').slice(0,64))
            .filter(Boolean)
            .slice(0,32);
          return {...last,item_keys,id:String(item.id||item.conversation_id||''),update_time:item.update_time??item.updateTime??null,updated_at:item.updated_at??item.updatedAt??null};
        }catch{last={http_status:0,source:candidate.source,item_present:false,item_keys:[]};}
      }
      return last;
    })()`);
    return result && typeof result === 'object'
      ? result
      : {http_status:0,source:'probe_failed',item_present:false,item_keys:[]};
  } catch {
    return {http_status:0,source:'probe_failed',item_present:false,item_keys:[]};
  }
}

function normalizeLatestConversationMeta(result) {
  if (!result || typeof result !== 'object') return null;
  const id = text(result.id);
  const rawUpdateTime = result.update_time ?? result.updated_at;
  let updateTime = Number(rawUpdateTime || 0);
  if (!Number.isFinite(updateTime) || updateTime <= 0) {
    const parsedMs = Date.parse(String(rawUpdateTime || ''));
    updateTime = Number.isFinite(parsedMs) ? parsedMs / 1000 : 0;
  }
  if (!/^[A-Za-z0-9_-]{8,160}$/.test(id) || !Number.isFinite(updateTime) || updateTime <= 0) return null;
  return {id, update_time:updateTime};
}

async function latestConversationMeta(cdp) {
  return normalizeLatestConversationMeta(await latestConversationProbe(cdp));
}

async function alignToLatestConversation(
  cdp,
  discoverLatest = latestConversationMeta,
  nowMs = Date.now(),
  maxAgeMs = RECENT_CONVERSATION_MAX_AGE_MS,
) {
  const latest = await discoverLatest(cdp);
  if (!latest) return { action: 'latest_unavailable' };

  const updatedAtMs = Number(latest.update_time) * 1000;
  const ageMs = Math.max(0, Number(nowMs) - updatedAtMs);
  if (!Number.isFinite(ageMs) || ageMs > Math.max(60_000, Number(maxAgeMs || RECENT_CONVERSATION_MAX_AGE_MS))) {
    return { action: 'stale_latest' };
  }

  const currentPath = await cdp.evaluate('location.pathname');
  const currentMatch = String(currentPath || '').match(/^\/c\/([^/?#]+)/);
  if (currentMatch && currentMatch[1] === latest.id) return { action: 'already_latest' };

  const target = '/c/' + latest.id;
  const navigated = await cdp.evaluate(`(()=>{location.assign(${JSON.stringify(target)});return true})()`);
  if (!navigated) return { action: 'navigation_failed' };
  await sleep(1500);
  return { action: 'navigated' };
}

async function errorBannerPresent(cdp) {
  // ChatGPT surfaces an explicit banner on a genuine stream failure -- this
  // is the one unambiguous, well-known DOM signal available to a script
  // that has no semantic understanding of whether a "complete-looking"
  // reply is actually incomplete. Anything short of that ambiguous case is
  // deliberately left to the checkpoint-driven trigger, not guessed here.
  const state = await cdp.pageState(6000);
  const haystack = `${state.text || ''}`.toLowerCase();
  return (
    haystack.includes('something went wrong')
    || haystack.includes('algo deu errado')
    || haystack.includes('there was an error generating')
    || haystack.includes('houve um erro ao gerar')
    // Confirmed live on ChatGPT Free (mobile app), 2026-09-27: this is the
    // actual banner text observed, not a guess -- "streaming interrupted,
    // waiting for the complete message".
    || haystack.includes('streaming interrupted')
    || haystack.includes('transmissão interrompida')
    || haystack.includes('transmissao interrompida')
    || haystack.includes('stopped thinking')
    || haystack.includes('parou de pensar')
  );
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

async function attemptNudge(
  taskId,
  connect = () => Cdp.connectToChatgptTab(),
  confirmProgress = confirmAssistantProgress,
) {
  let cdp;
  try {
    cdp = await connect();
    let recoveredStaleComplete = false;
    if (await conversationIsGenerating(cdp)) {
      const stream = await conversationStreamStatus(cdp);
      if (stream?.http_status !== 200 || stream?.status !== 'COMPLETE') {
        // A real stream may still be in flight, but live failures also show
        // the client stuck on Stop/Thinking while stream bookkeeping remains
        // non-terminal. First try one read-only reattach of the same
        // conversation. This can recover lost client/server reconciliation
        // without creating a duplicate ChatGPT turn.
        const baseline = await assistantSnapshot(cdp);
        await cdp.evaluate(`(()=>{location.reload();return true})()`);
        await sleep(1200);
        const progressed = await confirmProgress(
          cdp,
          baseline,
          PASSIVE_REATTACH_CONFIRM_MS,
          PROGRESS_POLL_MS,
        );
        if (progressed) {
          return {
            result_status: 'PROGRESS_CONFIRMED',
            detail: 'passive reattach restored assistant progress without sending continuation',
          };
        }
        return {
          result_status: 'STALLED_NOT_CONFIRMED',
          detail: 'passive reattach observed no assistant progress; active stream remains unconfirmed',
        };
      }
      // Live evidence showed ChatGPT can leave the Stop button visible after
      // its backend has already finalized the stream as COMPLETE. That stale
      // client state blocks all future continuations unless the stale Stop is
      // cleared first.
      if (!(await clearStaleCompleteGeneration(cdp))) {
        return { result_status: 'STALLED_NOT_CONFIRMED', detail: 'stale COMPLETE stream detected but Stop state did not clear' };
      }
      recoveredStaleComplete = true;
    }
    if (!(await composerIsUsable(cdp))) {
      return { result_status: 'CONVERSATION_NOT_FOUND', detail: 'composer/send-button selector not found (possible UI drift)' };
    }
    const baseline = await assistantSnapshot(cdp);
    const sent = await sendContinueMessage(cdp);
    if (!sent) return { result_status: 'ERROR', detail: 'composer found but send failed' };

    const progressed = await confirmProgress(cdp, baseline);
    if (!progressed) {
      return {
        result_status: 'SENT_UNCONFIRMED',
        detail: recoveredStaleComplete
          ? 'recovered stale COMPLETE stream and sent continuation, but no assistant progress was observed'
          : 'sent continuation, but no assistant progress was observed',
      };
    }
    return {
      result_status: 'PROGRESS_CONFIRMED',
      detail: recoveredStaleComplete
        ? 'recovered stale COMPLETE stream; continuation produced assistant progress'
        : 'continuation produced assistant progress',
    };
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
  confirmProgress = confirmAssistantProgress,
  alignLatest = alignToLatestConversation,
) {
  let cdp;
  try {
    cdp = await connect();
    const alignment = await alignLatest(cdp);
    if (alignment.action === 'latest_unavailable' || alignment.action === 'stale_latest' || alignment.action === 'navigation_failed') {
      return alignment;
    }
    if (!(await errorBannerPresent(cdp))) return { action: 'no_banner' };
    await sleep(confirmDelayMs);
    cdp.close();
    cdp = await connect();
    if (!(await errorBannerPresent(cdp))) {
      console.log('chatgpt_continuity_reinforcement error_banner_self_resolved');
      return { action: 'self_resolved' };
    }
    const baseline = await assistantSnapshot(cdp);
    const sent = await sendContinueMessage(cdp);
    if (!sent) {
      console.log('chatgpt_continuity_reinforcement error_banner_confirmed sent=false');
      return { action: 'send_failed', sent: false, progress_confirmed: false };
    }
    const progressed = await confirmProgress(cdp, baseline);
    const action = progressed ? 'confirmed_progress' : 'sent_unconfirmed';
    console.log(`chatgpt_continuity_reinforcement error_banner_confirmed sent=true progress=${progressed}`);
    return { action, sent: true, progress_confirmed: progressed };
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
  latestConversationProbe,
  normalizeLatestConversationMeta,
  latestConversationMeta,
  alignToLatestConversation,
  assistantSnapshot,
  assistantProgressed,
  confirmAssistantProgress,
  sendContinueMessage,
  attemptNudge,
  reinforcementCheckOnce,
};
