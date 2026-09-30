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
  || 'http://127.0.0.1:18081/api/chatgpt-continuity/bridge.php';
const BRIDGE_HOST_HEADER = process.env.CHATGPT_CONTINUITY_BRIDGE_HOST_HEADER || 'shopvivaliz.com.br';
const TOKEN_FILE = process.env.CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE
  || '/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token';
const CDP_BASE = process.env.CHATGPT_CONTINUITY_CDP_URL || 'http://127.0.0.1:9555';
const POLL_MS = Math.max(5000, Number(process.env.CHATGPT_CONTINUITY_POLL_MS || 15000));
const STALL_REINFORCEMENT_ENABLED = process.env.CHATGPT_CONTINUITY_STALL_MONITOR !== '0';
const REINFORCEMENT_POLL_MS = Math.max(
  15_000,
  Number(process.env.CHATGPT_CONTINUITY_REINFORCEMENT_POLL_MS || 30_000),
);
const REINFORCEMENT_DISCOVERY_INTERVAL_MS = Math.max(
  60_000,
  Number(process.env.CHATGPT_CONTINUITY_REINFORCEMENT_DISCOVERY_MS || 2 * 60_000),
);
const REINFORCEMENT_429_BACKOFF_MS = Math.max(
  REINFORCEMENT_DISCOVERY_INTERVAL_MS + 60_000,
  Number(process.env.CHATGPT_CONTINUITY_REINFORCEMENT_429_BACKOFF_MS || 5 * 60_000),
);
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
const LATEST_CONVERSATION_PROBE_TIMEOUT_MS = Math.max(
  1000,
  Number(process.env.CHATGPT_CONTINUITY_LATEST_PROBE_TIMEOUT_MS || 12000),
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
    if (!response.ok) return false;
    const data = await response.json();
    return typeof data?.webSocketDebuggerUrl === 'string'
      && /^wss?:\/\//.test(data.webSocketDebuggerUrl);
  } catch {
    return false;
  }
}

function chatgptTabRank(tab) {
  if (!tab || tab.type !== 'page' || !tab.webSocketDebuggerUrl) return Number.POSITIVE_INFINITY;
  let url;
  try {
    url = new URL(String(tab.url || ''));
  } catch {
    return Number.POSITIVE_INFINITY;
  }
  if (url.protocol !== 'https:' || url.hostname !== 'chatgpt.com') return Number.POSITIVE_INFINITY;
  if (/^\/c\/[^/]+/.test(url.pathname)) return 0;
  if (url.pathname === '/' || url.pathname === '') return 1;
  if (/^\/(?:auth|login|logout)(?:\/|$)/.test(url.pathname)) return 3;
  return 2;
}

function selectChatgptTab(tabs) {
  if (!Array.isArray(tabs)) return null;
  let selected = null;
  let selectedRank = Number.POSITIVE_INFINITY;
  for (const tab of tabs) {
    const rank = chatgptTabRank(tab);
    if (rank < selectedRank) {
      selected = tab;
      selectedRank = rank;
    }
  }
  return selectedRank === Number.POSITIVE_INFINITY ? null : selected;
}

async function connectFirstUsableChatgptTab(tabs, connector) {
  if (!Array.isArray(tabs) || typeof connector !== 'function') return null;
  const ranked = tabs
    .map((tab, index) => ({ tab, index, rank: chatgptTabRank(tab) }))
    .filter(row => Number.isFinite(row.rank))
    .sort((a, b) => (a.rank - b.rank) || (a.index - b.index));
  for (const { tab } of ranked) {
    try {
      const connected = await connector(tab);
      if (connected) return connected;
    } catch {}
  }
  return null;
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
    const connected = await connectFirstUsableChatgptTab(tabs, async page => {
      let ws;
      let cdp;
      try {
        ws = new WebSocket(page.webSocketDebuggerUrl);
        await Promise.race([
          new Promise((resolve, reject) => {
            ws.addEventListener('open', resolve, { once: true });
            ws.addEventListener('error', reject, { once: true });
          }),
          new Promise((_, reject) => setTimeout(() => reject(new Error('CDP target open timeout')), 3000)),
        ]);
        cdp = new Cdp(ws);
        await Promise.race([
          cdp.evaluate('true'),
          new Promise((_, reject) => setTimeout(() => reject(new Error('CDP target liveness timeout')), 3000)),
        ]);
        return cdp;
      } catch (error) {
        try { cdp?.close(); } catch {}
        try { ws?.close(); } catch {}
        throw error;
      }
    });
    if (!connected) {
      throw new Error('no usable open chatgpt.com tab found in the attached browser');
    }
    return connected;
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
  return cdp.evaluate(`(()=>{
    const el=document.querySelector('[data-testid="prompt-textarea"]')
      || document.querySelector('[role="textbox"][contenteditable="true"]');
    if(!el) return false;
    return !Boolean(el.disabled) && el.getAttribute('aria-disabled') !== 'true';
  })()`);
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

async function latestConversationProbe(cdp, timeoutMs = LATEST_CONVERSATION_PROBE_TIMEOUT_MS) {
  let timeoutHandle;
  try {
    const requestedTimeout = Number(timeoutMs);
    const boundedTimeoutMs = Number.isFinite(requestedTimeout)
      ? Math.max(10, requestedTimeout)
      : LATEST_CONVERSATION_PROBE_TIMEOUT_MS;
    const result = await Promise.race([
      cdp.evaluate(`(async()=>{
      let accountId='';
      let accessToken='';
      try{
        const sessionResponse=await fetch('/api/auth/session',{credentials:'same-origin',cache:'no-store'});
        if(sessionResponse.ok){
          let session=null; try{session=await sessionResponse.json();}catch{}
          accessToken=String(session?.accessToken||session?.access_token||'').trim();
          accountId=String(session?.account?.id||'').trim();
          accessToken=String(session?.accessToken||session?.access_token||'').trim();
        }
      }catch{}
      const headers={Accept:'application/json'};
      if(accessToken) headers.Authorization='Bearer '+accessToken;
      if(accountId) headers['ChatGPT-Account-Id']=accountId;
      const candidates = [
        {source:'filtered',url:'/backend-api/conversations?offset=0&limit=1&order=updated&is_archived=false&is_starred=false'},
        {source:'fallback_unfiltered',url:'/backend-api/conversations?offset=0&limit=1&order=updated'},
      ];
      let last={http_status:0,source:'none',item_present:false,item_keys:[]};
      for(const candidate of candidates){
        try{
          const response=await fetch(candidate.url,{credentials:'same-origin',cache:'no-store',headers});
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
    })()`),
      new Promise((_, reject) => {
        timeoutHandle = setTimeout(
          () => reject(new Error('latest conversation probe timeout')),
          boundedTimeoutMs,
        );
      }),
    ]);
    return result && typeof result === 'object'
      ? result
      : {http_status:0,source:'probe_failed',item_present:false,item_keys:[]};
  } catch {
    return {http_status:0,source:'probe_failed',item_present:false,item_keys:[]};
  } finally {
    if (timeoutHandle) clearTimeout(timeoutHandle);
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

async function alignLatestForReinforcement(
  cdp,
  probeLatest = latestConversationProbe,
  nowMs = Date.now(),
  maxAgeMs = RECENT_CONVERSATION_MAX_AGE_MS,
) {
  const probe = await probeLatest(cdp);
  const rawStatus = Number(probe?.http_status || 0);
  const httpStatus = Number.isFinite(rawStatus)
    ? Math.max(0, Math.min(599, Math.trunc(rawStatus)))
    : 0;
  const latest = normalizeLatestConversationMeta(probe);
  if (!latest) return { action: 'latest_unavailable', http_status: httpStatus };
  const alignment = await alignToLatestConversation(
    cdp,
    async () => latest,
    nowMs,
    maxAgeMs,
  );
  return { ...alignment, http_status: httpStatus };
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
  const typed = await cdp.evaluate(`(()=>{
    const el = document.querySelector('[data-testid="prompt-textarea"]')
      || document.querySelector('[role="textbox"][contenteditable="true"]');
    if (!el || Boolean(el.disabled) || el.getAttribute('aria-disabled') === 'true') return false;
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
  return cdp.evaluate(`(()=>{
    const exactSelectors=[
      '[data-testid="send-button"]',
      'button[aria-label="Send"]',
      'button[aria-label="Send prompt"]',
      'button[aria-label="Send message"]',
      'button[aria-label="Enviar"]',
      'button[aria-label="Enviar prompt"]',
      'button[aria-label="Enviar mensagem"]'
    ];
    let b=null;
    for(const selector of exactSelectors){
      const candidate=document.querySelector(selector);
      if(candidate && !candidate.disabled && candidate.getAttribute('aria-disabled') !== 'true'){ b=candidate; break; }
    }
    if(!b){
      const composer=document.querySelector('[data-testid="prompt-textarea"]')
        || document.querySelector('[role="textbox"][contenteditable="true"]');
      let root=composer;
      for(let i=0;i<6 && root && !b;i++,root=root.parentElement){
        b=Array.from(root.querySelectorAll('button')).find(candidate=>{
          if(candidate.disabled || candidate.getAttribute('aria-disabled') === 'true') return false;
          const label=String(candidate.getAttribute('aria-label')||'').trim();
          return /^(send|send prompt|send message|enviar|enviar prompt|enviar mensagem)$/i.test(label);
        }) || null;
      }
    }
    if(!b) return false;
    b.click();
    return true;
  })()`);
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
      // Never treat transport bookkeeping as semantic completion. Independent
      // 2026-09 captures show stream/message COMPLETE can coexist with an
      // unfinished assistant/tool branch. Every apparent active turn therefore
      // gets one passive reattach before any Stop clear or continuation send.
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

      // Re-read server bookkeeping only after the passive recovery window.
      // Anything other than a confirmed COMPLETE remains potentially active
      // and must not receive a duplicate continuation.
      const stream = await conversationStreamStatus(cdp);
      if (stream?.http_status !== 200 || stream?.status !== 'COMPLETE') {
        return {
          result_status: 'STALLED_NOT_CONFIRMED',
          detail: 'passive reattach observed no assistant progress; active stream remains unconfirmed',
        };
      }

      // If Stop survived the reattach while server bookkeeping says COMPLETE,
      // clear only that stale UI state before sending the checkpoint-driven
      // continuation. If Stop disappeared naturally, continue without a click.
      if (await conversationIsGenerating(cdp)) {
        if (!(await clearStaleCompleteGeneration(cdp))) {
          return { result_status: 'STALLED_NOT_CONFIRMED', detail: 'stale COMPLETE stream detected but Stop state did not clear' };
        }
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
  alignLatest = alignLatestForReinforcement,
  { allowCrossDeviceDiscovery = true } = {},
) {
  let cdp;
  let crossDeviceDiscovery = false;
  let alignmentHttpStatus = 0;
  try {
    cdp = await connect();

    // Cheap, local signal first. Do not hit the account-scoped conversation
    // listing when the currently open conversation already exposes a failure.
    let bannerPresent = await errorBannerPresent(cdp);
    if (!bannerPresent) {
      if (!allowCrossDeviceDiscovery) {
        return { action: 'no_banner', cross_device_discovery: false };
      }

      // Cross-device discovery is deliberately gated by reinforcementLoop().
      // It may trigger account-scoped requests, so mark the attempt before
      // awaiting it to ensure errors also consume the wider discovery window.
      crossDeviceDiscovery = true;
      const alignment = await alignLatest(cdp);
      alignmentHttpStatus = Number.isFinite(Number(alignment?.http_status))
        ? Math.max(0, Math.min(599, Math.trunc(Number(alignment.http_status))))
        : 0;
      if (
        alignment.action === 'latest_unavailable'
        || alignment.action === 'stale_latest'
        || alignment.action === 'navigation_failed'
      ) {
        return {
          ...alignment,
          http_status: alignmentHttpStatus,
          cross_device_discovery: true,
        };
      }
      if (alignment.action === 'already_latest') {
        return {
          action: 'no_banner',
          http_status: alignmentHttpStatus,
          cross_device_discovery: true,
        };
      }
      bannerPresent = await errorBannerPresent(cdp);
      if (!bannerPresent) {
        return {
          action: 'no_banner',
          http_status: alignmentHttpStatus,
          cross_device_discovery: true,
        };
      }
    }

    await sleep(confirmDelayMs);
    cdp.close();
    cdp = await connect();
    if (!(await errorBannerPresent(cdp))) {
      console.log('chatgpt_continuity_reinforcement error_banner_self_resolved');
      return {
        action: 'self_resolved',
        http_status: alignmentHttpStatus,
        cross_device_discovery: crossDeviceDiscovery,
      };
    }
    const baseline = await assistantSnapshot(cdp);
    const sent = await sendContinueMessage(cdp);
    if (!sent) {
      console.log('chatgpt_continuity_reinforcement error_banner_confirmed sent=false');
      return {
        action: 'send_failed',
        sent: false,
        progress_confirmed: false,
        http_status: alignmentHttpStatus,
        cross_device_discovery: crossDeviceDiscovery,
      };
    }
    const progressed = await confirmProgress(cdp, baseline);
    const action = progressed ? 'confirmed_progress' : 'sent_unconfirmed';
    console.log(`chatgpt_continuity_reinforcement error_banner_confirmed sent=true progress=${progressed}`);
    return {
      action,
      sent: true,
      progress_confirmed: progressed,
      http_status: alignmentHttpStatus,
      cross_device_discovery: crossDeviceDiscovery,
    };
  } catch (error) {
    // The reinforcement monitor is best-effort: the checkpoint-driven path
    // above is the primary trigger and already surfaces real failures.
    return {
      action: 'error',
      detail: text(error?.message),
      http_status: alignmentHttpStatus,
      cross_device_discovery: crossDeviceDiscovery,
    };
  } finally {
    cdp?.close();
  }
}

function reinforcementDiscoveryDelayMs(result) {
  if (result?.cross_device_discovery !== true) return 0;
  if (result?.action === 'latest_unavailable' && Number(result?.http_status) === 429) {
    return REINFORCEMENT_429_BACKOFF_MS;
  }
  return REINFORCEMENT_DISCOVERY_INTERVAL_MS;
}

async function bridgeLoop(
  poll = pollBridgeOnce,
  wait = sleep,
) {
  for (;;) {
    try {
      await poll();
    } catch (error) {
      console.error(`chatgpt_continuity_bridge_poll_error ${text(error?.message)}`);
    }
    await wait(POLL_MS);
  }
}

async function reinforcementLoop(
  check = reinforcementCheckOnce,
  now = () => Date.now(),
  wait = sleep,
) {
  let nextCrossDeviceDiscoveryAt = 0;
  for (;;) {
    const allowCrossDeviceDiscovery = now() >= nextCrossDeviceDiscoveryAt;
    let outcome;
    try {
      outcome = await check(
        () => Cdp.connectToChatgptTab(),
        REINFORCEMENT_CONFIRM_DELAY_MS,
        confirmAssistantProgress,
        alignLatestForReinforcement,
        { allowCrossDeviceDiscovery },
      );
    } catch (error) {
      console.error(`chatgpt_continuity_reinforcement_error ${text(error?.message)}`);
      outcome = {
        action: 'error',
        cross_device_discovery: allowCrossDeviceDiscovery,
      };
    }

    const discoveryDelayMs = reinforcementDiscoveryDelayMs(outcome);
    if (discoveryDelayMs > 0) {
      nextCrossDeviceDiscoveryAt = now() + discoveryDelayMs;
      if (outcome?.action === 'latest_unavailable' && Number(outcome?.http_status) === 429) {
        console.log(`chatgpt_continuity_reinforcement latest_discovery_backoff_ms=${discoveryDelayMs}`);
      }
    }
    await wait(REINFORCEMENT_POLL_MS);
  }
}

async function mainLoop(
  runBridgeLoop = bridgeLoop,
  runReinforcementLoop = reinforcementLoop,
  reinforcementEnabled = STALL_REINFORCEMENT_ENABLED,
) {
  const loops = [runBridgeLoop()];
  if (reinforcementEnabled) loops.push(runReinforcementLoop());
  await Promise.all(loops);
}

if (import.meta.url === `file://${process.argv[1]}`) {
  mainLoop();
}

export {
  Cdp,
  selectChatgptTab,
  connectFirstUsableChatgptTab,
  conversationIsGenerating,
  composerIsUsable,
  errorBannerPresent,
  latestConversationProbe,
  normalizeLatestConversationMeta,
  latestConversationMeta,
  alignToLatestConversation,
  alignLatestForReinforcement,
  assistantSnapshot,
  assistantProgressed,
  confirmAssistantProgress,
  sendContinueMessage,
  attemptNudge,
  reinforcementCheckOnce,
  reinforcementDiscoveryDelayMs,
  bridgeLoop,
  reinforcementLoop,
  mainLoop,
};
