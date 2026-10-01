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
const TASK_STATE_DIR = process.env.SHOPVIVALIZ_AGENT_TASK_STATE_DIR
  || '/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state';
const POLL_MS = Math.max(5000, Number(process.env.CHATGPT_CONTINUITY_POLL_MS || 15000));
const STALL_REINFORCEMENT_ENABLED = process.env.CHATGPT_CONTINUITY_STALL_MONITOR !== '0';
const AUTO_ALLOW_ENABLED = process.env.CHATGPT_CONTINUITY_AUTO_ALLOW !== '0';
const AUTHORIZATION_POLL_MS = Math.max(1000, Number(process.env.CHATGPT_CONTINUITY_AUTHORIZATION_POLL_MS || 3000));
const AUTHORIZATION_TAB_TIMEOUT_MS = Math.max(250, Number(process.env.CHATGPT_CONTINUITY_AUTHORIZATION_TAB_TIMEOUT_MS || 3000));
const REINFORCEMENT_POLL_MS = Math.max(
  15_000,
  Number(process.env.CHATGPT_CONTINUITY_REINFORCEMENT_POLL_MS || 30_000),
);
const REINFORCEMENT_DISCOVERY_INTERVAL_MS = Math.max(
  60_000,
  Number(process.env.CHATGPT_CONTINUITY_REINFORCEMENT_DISCOVERY_MS || 60_000),
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
const COMPOSER_READY_TIMEOUT_MS = Math.max(
  2000,
  Number(process.env.CHATGPT_CONTINUITY_COMPOSER_READY_TIMEOUT_MS || 15000),
);
const COMPOSER_READY_POLL_MS = Math.max(
  250,
  Number(process.env.CHATGPT_CONTINUITY_COMPOSER_READY_POLL_MS || 500),
);
const POST_SEND_BASELINE_SETTLE_MS = Math.max(
  250,
  Number(process.env.CHATGPT_CONTINUITY_POST_SEND_BASELINE_SETTLE_MS || 600),
);
const RECENT_CONVERSATION_MAX_AGE_MS = Math.max(
  60_000,
  Number(process.env.CHATGPT_CONTINUITY_RECENT_CONVERSATION_MAX_AGE_MS || 10 * 60_000),
);
const CHECKPOINT_AMBIGUOUS_CONVERSATION_MAX_AGE_MS = Math.max(
  RECENT_CONVERSATION_MAX_AGE_MS,
  Number(process.env.CHATGPT_CONTINUITY_CHECKPOINT_LATEST_MAX_AGE_MS || 30 * 60_000),
);
const CHECKPOINT_TARGET_MAX_DELTA_MS = Math.max(
  60_000,
  Number(process.env.CHATGPT_CONTINUITY_CHECKPOINT_TARGET_MAX_DELTA_MS || 15 * 60_000),
);
const CHECKPOINT_TARGET_MIN_SEPARATION_MS = Math.max(
  10_000,
  Number(process.env.CHATGPT_CONTINUITY_CHECKPOINT_TARGET_MIN_SEPARATION_MS || 60_000),
);
const requestedLatestNotOpenMaxAgeMs = Number(
  process.env.CHATGPT_CONTINUITY_CHECKPOINT_LATEST_NOT_OPEN_MAX_AGE_MS || 15 * 60_000,
);
const CHECKPOINT_LATEST_NOT_OPEN_MAX_AGE_MS = Math.min(
  CHECKPOINT_AMBIGUOUS_CONVERSATION_MAX_AGE_MS,
  Math.max(
    RECENT_CONVERSATION_MAX_AGE_MS,
    Number.isFinite(requestedLatestNotOpenMaxAgeMs)
      ? requestedLatestNotOpenMaxAgeMs
      : 15 * 60_000,
  ),
);
const LATEST_CONVERSATION_PROBE_TIMEOUT_MS = Math.max(
  1000,
  Number(process.env.CHATGPT_CONTINUITY_LATEST_PROBE_TIMEOUT_MS || 12000),
);
const LATEST_CONVERSATION_FETCH_TIMEOUT_MS = Math.min(
  LATEST_CONVERSATION_PROBE_TIMEOUT_MS,
  Math.max(500, Number(process.env.CHATGPT_CONTINUITY_LATEST_FETCH_TIMEOUT_MS || 1500) || 1500),
);
const STREAM_STATUS_TIMEOUT_MS = Math.max(
  1000,
  Number(process.env.CHATGPT_CONTINUITY_STREAM_STATUS_TIMEOUT_MS || 5000),
);
const REINFORCEMENT_SWEEP_BATCH_SIZE = Math.max(
  1,
  Math.min(6, Number(process.env.CHATGPT_CONTINUITY_SWEEP_BATCH_SIZE || 3)),
);
const REINFORCEMENT_SWEEP_MAX_CANDIDATES = Math.max(
  REINFORCEMENT_SWEEP_BATCH_SIZE,
  Math.min(48, Number(process.env.CHATGPT_CONTINUITY_SWEEP_MAX_CANDIDATES || 24)),
);


const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const text = value => String(value ?? '').replace(/\s+/g, ' ').trim();
const sha = value => createHash('sha256').update(String(value ?? '')).digest('hex');
const AMBIGUOUS_CONVERSATION_ERROR = 'multiple open ChatGPT conversation tabs found; continuity target is ambiguous';
const SINGLE_SAFE_REINFORCEMENT_CDPS = new WeakSet();
const SIDEBAR_CONSENSUS_SAFE_REINFORCEMENT_CDPS = new WeakSet();
let REINFORCEMENT_RECENT_CANDIDATES = [];
let REINFORCEMENT_RECENT_CURSOR = 0;
let REINFORCEMENT_LATEST_ID = '';


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

function conversationIdFromTab(tab) {
  if (chatgptTabRank(tab) !== 0) return '';
  try {
    return new URL(String(tab.url || '')).pathname.match(/^\/c\/([^/]+)/)?.[1] || '';
  } catch {
    return '';
  }
}

function checkpointUpdatedAtMs(taskId, taskStateDir = TASK_STATE_DIR) {
  const safeTaskId = text(taskId);
  if (!/^[A-Za-z0-9._-]{1,200}$/.test(safeTaskId)) return 0;
  try {
    const payload = JSON.parse(fs.readFileSync(taskStateDir + '/' + safeTaskId + '.json', 'utf8'));
    if (!payload || text(payload.status).toUpperCase() !== 'RUNNING') return 0;
    const parsed = Date.parse(String(payload.updated_at || ''));
    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
  } catch {
    return 0;
  }
}

function selectCheckpointConversationCandidate(
  candidates,
  targetUpdatedAtMs,
  maxDeltaMs = CHECKPOINT_TARGET_MAX_DELTA_MS,
  minSeparationMs = CHECKPOINT_TARGET_MIN_SEPARATION_MS,
) {
  const target = Number(targetUpdatedAtMs || 0);
  if (!Number.isFinite(target) || target <= 0) return null;
  const ranked = normalizeConversationCandidates({ candidates })
    .map(candidate => ({
      candidate,
      delta_ms: Math.abs(candidate.update_time * 1000 - target),
    }))
    .sort((a, b) => (a.delta_ms - b.delta_ms) || a.candidate.id.localeCompare(b.candidate.id));
  if (ranked.length === 0 || ranked[0].delta_ms > Math.max(60_000, Number(maxDeltaMs || 0))) return null;
  if (
    ranked.length > 1
    && (ranked[1].delta_ms - ranked[0].delta_ms) < Math.max(10_000, Number(minSeparationMs || 0))
  ) {
    return null;
  }
  return ranked[0].candidate;
}

async function resolveAmbiguousConversationTabs(
  tabs,
  connector,
  probeLatest = latestConversationProbe,
  nowMs = Date.now(),
  maxAgeMs = CHECKPOINT_AMBIGUOUS_CONVERSATION_MAX_AGE_MS,
  navigateLatest = navigateNeutralTabToConversation,
  targetUpdatedAtMs = 0,
) {
  const sourceTabs = Array.isArray(tabs) ? tabs : [];
  const conversationIds = new Set(sourceTabs.map(conversationIdFromTab).filter(Boolean));
  if (conversationIds.size <= 1) return sourceTabs;

  let discoveryCdp;
  try {
    discoveryCdp = await connectFirstUsableChatgptTab(sourceTabs, connector);
    if (!discoveryCdp) throw new Error(AMBIGUOUS_CONVERSATION_ERROR);

    const probe = await probeLatest(discoveryCdp);
    const targetTimestamp = Number(targetUpdatedAtMs || 0);
    const checkpointCandidate = targetTimestamp > 0
      ? selectCheckpointConversationCandidate(normalizeConversationCandidates(probe), targetTimestamp)
      : null;
    if (targetTimestamp > 0 && !checkpointCandidate) {
      throw new Error(AMBIGUOUS_CONVERSATION_ERROR);
    }
    const latest = checkpointCandidate || normalizeLatestConversationMeta(probe);
    if (!latest) throw new Error(AMBIGUOUS_CONVERSATION_ERROR);

    const targetTabs = sourceTabs.filter(tab => conversationIdFromTab(tab) === latest.id);
    if (checkpointCandidate) {
      if (targetTabs.length > 0) return targetTabs;
      const neutralHomeTabs = sourceTabs.filter(tab => chatgptTabRank(tab) === 1);
      if (neutralHomeTabs.length !== 1) throw new Error(AMBIGUOUS_CONVERSATION_ERROR);
      const [neutralHomeTab] = neutralHomeTabs;
      const navigated = await navigateLatest(neutralHomeTab, latest.id, connector);
      if (!navigated) throw new Error(AMBIGUOUS_CONVERSATION_ERROR);
      return [neutralHomeTab];
    }

    const updatedAtMs = Number(latest.update_time) * 1000;
    const ageMs = Math.max(0, Number(nowMs) - updatedAtMs);
    const ageLimitMs = Math.max(60_000, Number(maxAgeMs || CHECKPOINT_AMBIGUOUS_CONVERSATION_MAX_AGE_MS));
    if (!Number.isFinite(ageMs) || ageMs > ageLimitMs) {
      throw new Error(AMBIGUOUS_CONVERSATION_ERROR);
    }

    if (targetTabs.length > 0) return targetTabs;

    // Cross-device or another-client activity can make the server-confirmed
    // latest conversation newer than every conversation currently open in
    // this persistent Chromium. Never guess among those older conversation
    // tabs. The navigation fallback is deliberately stricter than matching an
    // already-open conversation: the latest item must still be within the
    // normal recent-conversation window and there must be exactly one neutral
    // ChatGPT home tab available to repurpose.
    if (ageMs > CHECKPOINT_LATEST_NOT_OPEN_MAX_AGE_MS) {
      throw new Error(AMBIGUOUS_CONVERSATION_ERROR);
    }
    const neutralHomeTabs = sourceTabs.filter(tab => chatgptTabRank(tab) === 1);
    if (neutralHomeTabs.length !== 1) throw new Error(AMBIGUOUS_CONVERSATION_ERROR);
    const [neutralHomeTab] = neutralHomeTabs;

    const navigated = await navigateLatest(neutralHomeTab, latest.id, connector);
    if (!navigated) throw new Error(AMBIGUOUS_CONVERSATION_ERROR);
    return [neutralHomeTab];
  } catch (error) {
    if (text(error?.message) === AMBIGUOUS_CONVERSATION_ERROR) throw error;
    throw new Error(AMBIGUOUS_CONVERSATION_ERROR);
  } finally {
    try { discoveryCdp?.close(); } catch {}
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

  static async connectToChatgptTab({ allowLatestDisambiguation = false, targetUpdatedAtMs = 0 } = {}) {
    if (!(await cdpReady())) {
      throw new Error(
        `CDP endpoint unreachable at ${CDP_BASE}. This worker never launches its own browser -- `
        + 'it only attaches to one you already have open and logged into ChatGPT. Launch it with '
        + `--remote-debugging-port=${new URL(CDP_BASE).port} (see docs/AGENT-VM-PROMPTS.md).`
      );
    }
    const tabs = await (await fetch(`${CDP_BASE}/json`)).json();
    const conversationIds = new Set(
      (Array.isArray(tabs) ? tabs : []).map(conversationIdFromTab).filter(Boolean),
    );
    let candidateTabs = tabs;
    if (conversationIds.size > 1) {
      if (!allowLatestDisambiguation) throw new Error(AMBIGUOUS_CONVERSATION_ERROR);
      candidateTabs = await resolveAmbiguousConversationTabs(
        tabs,
        connectCdpTarget,
        latestConversationProbe,
        Date.now(),
        CHECKPOINT_AMBIGUOUS_CONVERSATION_MAX_AGE_MS,
        navigateNeutralTabToConversation,
        targetUpdatedAtMs,
      );
    }
    const connected = await connectFirstUsableChatgptTab(candidateTabs, connectCdpTarget);
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

async function reinforcementSendReady(cdp) { return Boolean(await cdp.evaluate(`(()=>{const c=document.querySelector('[data-testid="prompt-textarea"]')||document.querySelector('[role="textbox"][contenteditable="true"]');if(!c||c.disabled||c.getAttribute('aria-disabled')==='true')return false;return [...document.querySelectorAll('button')].some(b=>/^(send|enviar)$/i.test(b.getAttribute('aria-label')||'')&&!b.disabled&&b.getAttribute('aria-disabled')!=='true')})()`)); }

async function authorizationButtonTarget(cdp) {
  return cdp.evaluate(`(()=>{
    /* continuity-authorization-button-target */
    const normalize=value=>String(value||'')
      .normalize('NFD').replace(/[\\u0300-\\u036f]/g,'')
      .replace(/\\s+/g,' ').trim().toLowerCase();
    const preferred=[
      'sempre permitir','always allow',
      'permitir uma vez','allow once',
      'permitir','allow','autorizar','authorize'
    ];
    const rank=new Map(preferred.map((value,index)=>[value,index]));
    const candidates=[];
    for(const button of document.querySelectorAll('button')){
      if(button.disabled||button.getAttribute('aria-disabled')==='true') continue;
      const label=normalize(button.getAttribute('aria-label')||button.innerText||button.textContent||'');
      if(!rank.has(label)) continue;
      const rect=button.getBoundingClientRect();
      if(!(rect.width>0&&rect.height>0)) continue;
      const style=getComputedStyle(button);
      if(style.visibility==='hidden'||style.display==='none'||Number(style.opacity||1)===0) continue;
      candidates.push({
        rank:rank.get(label),
        kind:label.includes('sempre')||label.includes('always')?'always_allow':'allow',
        x:rect.left+rect.width/2,
        y:rect.top+rect.height/2
      });
    }
    candidates.sort((a,b)=>a.rank-b.rank);
    return candidates[0]||null;
  })()`);
}

async function clickAuthorizationIfPresent(cdp) {
  if (!AUTO_ALLOW_ENABLED) return { action: 'disabled' };
  if (!cdp || typeof cdp.send !== 'function') return { action: 'unsupported' };
  const target = await authorizationButtonTarget(cdp);
  if (!target) return { action: 'no_request' };
  const x=Number(target.x);
  const y=Number(target.y);
  if(!Number.isFinite(x)||!Number.isFinite(y)) return { action: 'invalid_target' };
  await cdp.send('Input.dispatchMouseEvent', {type:'mouseMoved',x,y,button:'none'});
  await cdp.send('Input.dispatchMouseEvent', {type:'mousePressed',x,y,button:'left',clickCount:1});
  await cdp.send('Input.dispatchMouseEvent', {type:'mouseReleased',x,y,button:'left',clickCount:1});
  return { action: 'clicked', kind: String(target.kind||'allow') };
}

async function authorizationCheckOnce(
  listTabs = async () => {
    if (!(await cdpReady())) return [];
    const response = await fetch(`${CDP_BASE}/json`, { signal: AbortSignal.timeout(3000) });
    return response.ok ? await response.json() : [];
  },
  connector = connectCdpTarget,
  tabTimeoutMs = AUTHORIZATION_TAB_TIMEOUT_MS,
) {
  if (!AUTO_ALLOW_ENABLED) return { action: 'disabled', scanned: 0 };
  const tabs = await listTabs();
  const candidates=(Array.isArray(tabs)?tabs:[])
    .filter(tab=>Number.isFinite(chatgptTabRank(tab)))
    .sort((a,b)=>chatgptTabRank(a)-chatgptTabRank(b));
  let scanned=0;
  for(const tab of candidates){
    let cdp;
    try{
      cdp=await connector(tab);
      if(!cdp) continue;
      scanned+=1;
      const timeoutMs=Math.max(25, Number(tabTimeoutMs||AUTHORIZATION_TAB_TIMEOUT_MS));
      let timeoutHandle;
      const outcome=await Promise.race([
        clickAuthorizationIfPresent(cdp),
        new Promise(resolve => {
          timeoutHandle=setTimeout(() => resolve({action:'tab_timeout'}), timeoutMs);
        }),
      ]);
      if(timeoutHandle) clearTimeout(timeoutHandle);
      if(outcome.action==='clicked'){
        return {...outcome,scanned};
      }
    }catch{
      // A transient tab detach must not terminate the always-on watcher.
    }finally{
      try{cdp?.close();}catch{}
    }
  }
  return { action: 'no_request', scanned };
}

async function authorizationLoop(
  check = authorizationCheckOnce,
  wait = sleep,
) {
  for (;;) {
    try {
      const outcome = await check();
      if (outcome?.action === 'clicked') {
        console.log(`chatgpt_continuity_authorization action=clicked kind=${text(outcome.kind)}`);
      }
    } catch (error) {
      console.error(`chatgpt_continuity_authorization_error ${text(error?.message)}`);
    }
    await wait(AUTHORIZATION_POLL_MS);
  }
}

async function connectReinforcementChatgptTab({
  allowCrossDeviceDiscovery = false,
  tabs: providedTabs = null,
  connector = connectCdpTarget,
  probeBanner = errorBannerPresent,
} = {}) {
  let tabs = providedTabs;
  if (!Array.isArray(tabs)) {
    if (!(await cdpReady())) {
      throw new Error(
        `CDP endpoint unreachable at ${CDP_BASE}. This worker only attaches to the canonical authenticated browser.`,
      );
    }
    tabs = await (await fetch(`${CDP_BASE}/json`)).json();
  }

  const ranked = (Array.isArray(tabs) ? tabs : [])
    .map((tab, index) => ({ tab, index, rank: chatgptTabRank(tab) }))
    .filter(row => row.rank === 0 || row.rank === 1)
    .sort((a, b) => (a.rank - b.rank) || (a.index - b.index));

  const opened = [];
  for (const row of ranked) {
    let cdp;
    try {
      cdp = await connector(row.tab);
      if (!cdp) continue;
      const banner = Boolean(await probeBanner(cdp));
      opened.push({ ...row, cdp, banner });
    } catch {
      try { cdp?.close(); } catch {}
    }
  }

  if (opened.length === 0) {
    throw new Error('no usable open chatgpt.com tab found in the attached browser');
  }

  const interrupted = opened.filter(row => row.banner);
  if (interrupted.length > 1) {
    // Duplicate CDP targets can point to the exact same conversation (for
    // example after a browser reconnect). They are not ambiguous targets:
    // deduplicate by conversation id before applying the ambiguity guard.
    const interruptedConversationIds = new Set(interrupted.map(row => conversationIdFromTab(row.tab)).filter(Boolean));
    if (interruptedConversationIds.size === 1) {
      let selected = interrupted[0];
      for (const row of interrupted) { if (await reinforcementSendReady(row.cdp)) { selected = row; break; } }
      for (const row of opened) {
        if (row !== selected) {
          try { row.cdp.close(); } catch {}
        }
      }
      return selected.cdp;
    }
    for (const row of opened) {
      try { row.cdp.close(); } catch {}
    }
    throw new Error(AMBIGUOUS_CONVERSATION_ERROR);
  }

  if (interrupted.length === 1) {
    const selected = interrupted[0];
    for (const row of opened) {
      if (row !== selected) {
        try { row.cdp.close(); } catch {}
      }
    }
    return selected.cdp;
  }

  if (interrupted.length > 1) {
    for (const row of opened) {
      try { row.cdp.close(); } catch {}
    }
    throw new Error(AMBIGUOUS_CONVERSATION_ERROR);
  }

  let selected = opened[0];
  if (allowCrossDeviceDiscovery) {
    const neutralHomes = opened.filter(row => row.rank === 1);
    const conversationRows = opened.filter(row => row.rank === 0);
    if (neutralHomes.length >= 1) {
      // Home tabs are neutral and equivalent discovery contexts. Prefer the
      // first deterministically even when stale duplicate home tabs exist;
      // ambiguity only applies to conversation targets, never to /.
      selected = neutralHomes[0];
    } else if (conversationRows.length > 1) {
      // Cross-device discovery should resolve the *target* from the account
      // endpoint, not from whichever conversations happen to be open in the
      // canonical browser. Prefer a strict sidebar consensus when available
      // because that also enables the 429 fallback; otherwise choose the first
      // idle conversation only as a temporary discovery context. A context
      // that lacks consensus is deliberately NOT trusted for sidebar fallback.
      const sidebarRows = [];
      for (const row of conversationRows) {
        try {
          const sidebarId = await sidebarLatestConversationId(row.cdp);
          if (sidebarId) sidebarRows.push({ row, sidebarId });
        } catch {}
      }

      const counts = new Map();
      for (const item of sidebarRows) {
        counts.set(item.sidebarId, (counts.get(item.sidebarId) || 0) + 1);
      }
      const rankedSidebarIds = [...counts.entries()]
        .sort((a, b) => (b[1] - a[1]) || a[0].localeCompare(b[0]));
      const top = rankedSidebarIds[0] || ['', 0];
      const secondCount = rankedSidebarIds[1]?.[1] || 0;

      let consensusRow = null;
      if (top[1] >= 2 && top[1] > secondCount) {
        for (const item of sidebarRows) {
          if (item.sidebarId !== top[0]) continue;
          try {
            if (!(await conversationIsGenerating(item.row.cdp))) {
              consensusRow = item.row;
              break;
            }
          } catch {}
        }
      }

      if (consensusRow) {
        selected = consensusRow;
        SIDEBAR_CONSENSUS_SAFE_REINFORCEMENT_CDPS.add(selected.cdp);
      } else {
        let idleRow = null;
        for (const row of conversationRows) {
          try {
            if (!(await conversationIsGenerating(row.cdp))) {
              idleRow = row;
              break;
            }
          } catch {}
        }
        if (!idleRow) {
          for (const row of opened) {
            try { row.cdp.close(); } catch {}
          }
          throw new Error(AMBIGUOUS_CONVERSATION_ERROR);
        }
        selected = idleRow;
      }
    }
  }
  for (const row of opened) {
    if (row !== selected) {
      try { row.cdp.close(); } catch {}
    }
  }
  if (
    allowCrossDeviceDiscovery
    && opened.length === 1
    && selected.rank === 0
    && selected.cdp
    && typeof selected.cdp === 'object'
  ) {
    SINGLE_SAFE_REINFORCEMENT_CDPS.add(selected.cdp);
  }
  return selected.cdp;
}

async function sidebarLatestConversationId(cdp) {
  const href = await cdp.evaluate(`(()=>{
    // sidebar-latest-conversation: local, already-synchronized fallback only.
    const unique=[];
    const seen=new Set();
    for(const anchor of document.querySelectorAll('a[href^="/c/"]')){
      const value=String(anchor.getAttribute('href')||'').trim();
      if(!/^\\/c\\/[A-Za-z0-9_-]{8,160}$/.test(value) || seen.has(value)) continue;
      seen.add(value);
      unique.push(value);
    }
    return unique[0]||'';
  })()`);
  const match = String(href || '').match(/^\/c\/([A-Za-z0-9_-]{8,160})$/);
  return match?.[1] || '';
}

async function alignToSidebarLatestConversation(cdp) {
  const currentPath = String(await cdp.evaluate('location.pathname') || '');
  const currentConversation = /^\/c\/[A-Za-z0-9_-]{8,160}$/.test(currentPath);
  const homeContext = currentPath === '/';
  const uniqueConversationContext = currentConversation && (
    SINGLE_SAFE_REINFORCEMENT_CDPS.has(cdp)
    || SIDEBAR_CONSENSUS_SAFE_REINFORCEMENT_CDPS.has(cdp)
  );
  if (!homeContext && !uniqueConversationContext) {
    return { action: 'sidebar_unavailable', sidebar_fallback: false };
  }

  const id = await sidebarLatestConversationId(cdp);
  if (!id) return { action: 'sidebar_unavailable', sidebar_fallback: false };

  const target = '/c/' + id;
  if (currentPath === target) {
    return {
      action: 'already_latest_sidebar_fallback',
      sidebar_fallback: true,
      restore_path: '',
    };
  }

  const navigated = await cdp.evaluate(`(()=>{location.assign(${JSON.stringify(target)});return true})()`);
  if (!navigated) {
    return {
      action: 'navigation_failed',
      sidebar_fallback: true,
      restore_path: '',
    };
  }
  await sleep(1500);
  return {
    action: 'navigated_sidebar_fallback',
    sidebar_fallback: true,
    restore_path: currentPath,
  };
}

function safeReinforcementRestorePath(value) {
  const path = String(value || '');
  if (path === '/') return path;
  return /^\/c\/[A-Za-z0-9_-]{8,160}$/.test(path) ? path : '';
}

async function restoreReinforcementPath(cdp, requestedPath) {
  const path = safeReinforcementRestorePath(requestedPath);
  if (!cdp || !path) return false;
  try {
    const currentPath = String(await cdp.evaluate('location.pathname') || '');
    if (currentPath === path) return true;
    return Boolean(await cdp.evaluate(
      `(()=>{location.assign(${JSON.stringify(path)});return true})()`,
    ));
  } catch {
    return false;
  }
}

async function navigateNeutralTabToConversation(
  tab,
  conversationId,
  connector = connectCdpTarget,
  timeoutMs = 12000,
  pollMs = 250,
) {
  if (chatgptTabRank(tab) !== 1) return false;
  const id = text(conversationId);
  if (!/^[A-Za-z0-9_-]{8,160}$/.test(id)) return false;

  let cdp;
  try {
    cdp = await connector(tab);
    if (!cdp) return false;

    const target = '/c/' + id;
    const navigated = await cdp.evaluate(
      `(()=>{location.assign(${JSON.stringify(target)});return true})()`,
    );
    if (!navigated) return false;

    const requestedTimeout = Number(timeoutMs);
    const requestedPoll = Number(pollMs);
    const deadline = Date.now() + (Number.isFinite(requestedTimeout)
      ? Math.max(500, requestedTimeout)
      : 12000);
    const intervalMs = Number.isFinite(requestedPoll)
      ? Math.max(50, requestedPoll)
      : 250;

    while (Date.now() < deadline) {
      await sleep(intervalMs);
      try {
        const pathname = await cdp.evaluate('location.pathname');
        const currentId = String(pathname || '').match(/^\/c\/([^/?#]+)/)?.[1] || '';
        if (currentId === id) return true;
      } catch {
        // Navigation can transiently detach the execution context. Keep the
        // check bounded and fail closed if the target never becomes readable.
      }
    }
    return false;
  } catch {
    return false;
  } finally {
    try { cdp?.close(); } catch {}
  }
}

async function connectCdpTarget(page) {
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
}

// OpenAI's stable, documented selectors for the ChatGPT web composer. These
// are the same data-testid attributes OpenAI itself uses in first-party
// automation examples; still, UI can drift -- see the UI_DRIFT result below,
// mirroring the same recognized failure mode as the Amazon Returns bridge.
async function conversationIsGenerating(cdp) {
  return cdp.evaluate(`Boolean(document.querySelector('[data-testid="stop-button"]'))`);
}

async function conversationStreamStatus(cdp, timeoutMs = STREAM_STATUS_TIMEOUT_MS) {
  const requestedTimeout = Number(timeoutMs);
  const boundedTimeoutMs = Number.isFinite(requestedTimeout)
    ? Math.max(10, requestedTimeout)
    : STREAM_STATUS_TIMEOUT_MS;
  let outerTimeoutHandle;
  try {
    return await Promise.race([
      cdp.evaluate(`(async()=>{
        const match=location.pathname.match(/^\\/c\\/([^/?#]+)/);
        if(!match) return {http_status:0,status:'NO_CONVERSATION'};
        const controller=new AbortController();
        const timer=setTimeout(()=>controller.abort(), ${boundedTimeoutMs});
        try{
          const response=await fetch(
            '/backend-api/conversation/'+encodeURIComponent(match[1])+'/stream_status',
            {credentials:'same-origin',cache:'no-store',signal:controller.signal}
          );
          let body=null;
          try{body=await response.json();}catch{}
          return {http_status:response.status,status:String(body?.status||'')};
        }catch(error){
          return {
            http_status:0,
            status:String(error?.name||'')==='AbortError'?'FETCH_TIMEOUT':'FETCH_FAILED'
          };
        }finally{
          clearTimeout(timer);
        }
      })()`),
      new Promise(resolve => {
        outerTimeoutHandle = setTimeout(
          () => resolve({ http_status: 0, status: 'FETCH_TIMEOUT' }),
          boundedTimeoutMs + 250,
        );
      }),
    ]);
  } finally {
    if (outerTimeoutHandle) clearTimeout(outerTimeoutHandle);
  }
}

async function conversationTurnState(cdp, timeoutMs = STREAM_STATUS_TIMEOUT_MS) {
  const requestedTimeout = Number(timeoutMs);
  const boundedTimeoutMs = Number.isFinite(requestedTimeout)
    ? Math.max(10, requestedTimeout)
    : STREAM_STATUS_TIMEOUT_MS;
  let outerTimeoutHandle;
  try {
    return await Promise.race([
      cdp.evaluate(`(async()=>{
        /* conversation-turn-state */
        const match=location.pathname.match(/^\\/c\\/([^/?#]+)/);
        if(!match) {
          return {
            http_status:0,
            role:'',
            end_turn:null,
            child_count:-1,
            message_status:'NO_CONVERSATION'
          };
        }
        let accountId='';
        let accessToken='';
        try{
          const sessionResponse=await fetch('/api/auth/session',{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(${LATEST_CONVERSATION_FETCH_TIMEOUT_MS})});
          if(sessionResponse.ok){
            let session=null;
            try{session=await sessionResponse.json();}catch{}
            accountId=String(session?.account?.id||'').trim();
            accessToken=String(session?.accessToken||session?.access_token||'').trim();
          }
        }catch{}
        const headers={Accept:'application/json'};
        if(accessToken) headers.Authorization='Bearer '+accessToken;
        if(accountId) headers['ChatGPT-Account-Id']=accountId;

        const controller=new AbortController();
        const timer=setTimeout(()=>controller.abort(), ${boundedTimeoutMs});
        try{
          const response=await fetch(
            '/backend-api/conversation/'+encodeURIComponent(match[1]),
            {credentials:'same-origin',cache:'no-store',headers,signal:controller.signal}
          );
          let body=null;
          try{body=await response.json();}catch{}
          if(!response.ok){
            return {
              http_status:Number(response.status||0),
              role:'',
              end_turn:null,
              child_count:-1,
              message_status:'HTTP_ERROR'
            };
          }
          const current=String(body?.current_node||'');
          const node=current && body?.mapping ? body.mapping[current] : null;
          const message=node?.message||null;
          const endTurn=message?.end_turn;
          return {
            http_status:Number(response.status||0),
            role:String(message?.author?.role||''),
            end_turn:endTurn===true?true:(endTurn===false?false:null),
            child_count:Array.isArray(node?.children)?node.children.length:-1,
            message_status:String(message?.status||'')
          };
        }catch(error){
          return {
            http_status:0,
            role:'',
            end_turn:null,
            child_count:-1,
            message_status:String(error?.name||'')==='AbortError'?'FETCH_TIMEOUT':'FETCH_FAILED'
          };
        }finally{
          clearTimeout(timer);
        }
      })()`),
      new Promise(resolve => {
        outerTimeoutHandle = setTimeout(
          () => resolve({
            http_status: 0,
            role: '',
            end_turn: null,
            child_count: -1,
            message_status: 'FETCH_TIMEOUT',
          }),
          boundedTimeoutMs + 250,
        );
      }),
    ]);
  } finally {
    if (outerTimeoutHandle) clearTimeout(outerTimeoutHandle);
  }
}

async function localIncompleteTurnPresent(cdp) {
  return cdp.evaluate(`(()=>{
    /* local-incomplete-turn */
    const turns=[...document.querySelectorAll('[data-turn-key]')];
    const last=turns.length ? turns[turns.length-1] : null;
    if(!last) return false;

    const hasAssistantNode=Boolean(
      last.querySelector('[data-message-author-role="assistant"],[data-conversation-role="assistant"]')
    );
    const labels=[...last.querySelectorAll('button[aria-label]')]
      .map(button=>String(button.getAttribute('aria-label')||'').trim().toLowerCase())
      .filter(Boolean);
    const hasUserControls=labels.some(label=>
      /^(copy message|edit message|copiar mensagem|editar mensagem)$/.test(label)
    ) || Boolean(last.querySelector('[class*="group/user-message"]'));
    const hasAssistantControls=labels.some(label=>
      /^(rate response|read aloud|regenerate response|avaliar resposta|ler em voz alta|regenerar resposta)$/.test(label)
    );

    return Boolean(hasUserControls && !hasAssistantNode && !hasAssistantControls);
  })()`);
}

async function silentStallPresent(cdp) {
  const stream = await conversationStreamStatus(cdp);
  if (Number(stream?.http_status || 0) !== 200) return false;

  const streamStatus = String(stream?.status || '').toUpperCase();

  // Live reproduction 2026-09-30 23:22 BRT:
  // backend stream_status=IS_STREAMING while the canonical web client shows
  // neither a Stop/generating signal nor an enabled composer. Treat this as a
  // candidate orphaned stream. reinforcementCheckOnce requires the same state
  // to persist across its confirmation window before recovery proceeds.
  if (['IS_STREAMING', 'IN_PROGRESS', 'STREAMING'].includes(streamStatus)) {
    const generating = await conversationIsGenerating(cdp);
    if (generating) return false;
    return !(await composerIsUsable(cdp));
  }

  if (streamStatus !== 'COMPLETE') return false;

  const turn = await conversationTurnState(cdp);
  if (Number(turn?.http_status || 0) === 200) {
    return (
      String(turn?.role || '').toLowerCase() === 'assistant'
      && turn?.end_turn === false
      && Number(turn?.child_count) === 0
    );
  }

  // The account-scoped metadata endpoint can be rate-limited or temporarily
  // unavailable while the conversation itself remains fully rendered in the
  // authenticated browser. Fall back only to an unambiguous local shape:
  // COMPLETE transport + a last user turn with no assistant node/actions.
  return Boolean(await localIncompleteTurnPresent(cdp));
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

async function waitForComposerUsable(
  cdp,
  timeoutMs = COMPOSER_READY_TIMEOUT_MS,
  pollMs = COMPOSER_READY_POLL_MS,
) {
  const requestedTimeout = Number(timeoutMs);
  const requestedPoll = Number(pollMs);
  const boundedTimeoutMs = Number.isFinite(requestedTimeout)
    ? Math.max(10, requestedTimeout)
    : COMPOSER_READY_TIMEOUT_MS;
  const boundedPollMs = Number.isFinite(requestedPoll)
    ? Math.max(10, requestedPoll)
    : COMPOSER_READY_POLL_MS;
  const deadline = Date.now() + boundedTimeoutMs;
  while (true) {
    if (await composerIsUsable(cdp)) return true;
    const remaining = deadline - Date.now();
    if (remaining <= 0) return false;
    await sleep(Math.min(boundedPollMs, remaining));
  }
}

async function assistantSnapshot(cdp) {
  return cdp.evaluate(`(()=>{
    const candidates=[
      ...document.querySelectorAll('[data-message-author-role="assistant"]'),
      ...document.querySelectorAll('[data-conversation-role="assistant"]')
    ];
    const legacyNodes=[];
    const legacySeen=new Set();
    for(const candidate of candidates){
      const node=candidate.closest('[data-turn-key]')||candidate;
      if(!node||legacySeen.has(node)) continue;
      legacySeen.add(node);
      legacyNodes.push(node);
    }

    // Current ChatGPT Web no longer exposes the legacy assistant-role
    // attributes in every surface. Completed assistant replies still expose
    // semantic action controls; use their enclosing turn container as a
    // second, localization-independent source.
    const actionButtons=[
      ...document.querySelectorAll(
        'button[aria-label="Rate response"],button[aria-label="Read aloud"],button[aria-label="Regenerate response"]'
      )
    ];
    const actionNodes=[];
    const actionSeen=new Set();
    for(const button of actionButtons){
      const controls=button.closest('.turn-action-controls');
      const node=controls?.parentElement||null;
      if(!node||actionSeen.has(node)) continue;
      actionSeen.add(node);
      actionNodes.push(node);
    }

    const nodes=legacyNodes.length ? legacyNodes : actionNodes;
    const last=nodes.length ? nodes[nodes.length-1] : null;
    const lastText=(last?.innerText||last?.textContent||'').trim();
    const keyed=last?.closest?.('[data-turn-key]')||last;
    const lastKey=legacyNodes.length
      ? String(keyed?.getAttribute?.('data-turn-key')||'')
      : (nodes.length ? 'action-controls-'+String(nodes.length) : '');

    // Progress during reasoning/tool use may not yet have final action
    // controls. Capture the current conversation surface as a secondary
    // fingerprint. It is consumed only from a post-send baseline so the
    // worker's own "continue" message cannot be mistaken for assistant
    // progress.
    const main=document.querySelector('main');
    const surfaceText=(main?.innerText||main?.textContent||'').trim();
    return {
      count:nodes.length,
      lastText,
      lastLength:lastText.length,
      lastKey,
      surfaceText,
      surfaceLength:surfaceText.length,
      snapshotSource:legacyNodes.length?'legacy':(actionNodes.length?'action-controls':'main')
    };
  })()`);
}

function assistantProgressed(before, after) {
  const prior = before || { count: 0, lastText: '', lastLength: 0, lastKey: '' };
  const current = after || { count: 0, lastText: '', lastLength: 0, lastKey: '' };
  if (Number(current.count || 0) > Number(prior.count || 0)) return true;
  const priorKey = String(prior.lastKey || '');
  const currentKey = String(current.lastKey || '');
  if (priorKey && currentKey && currentKey !== priorKey) return true;
  const priorText = String(prior.lastText || '');
  const currentText = String(current.lastText || '');
  return currentText.length > priorText.length && currentText !== priorText;
}

function assistantSurfaceProgressed(before, after) {
  const priorText=String(before?.surfaceText||'');
  const currentText=String(after?.surfaceText||'');
  if(!priorText || !currentText) return false;
  return currentText.length > priorText.length && currentText !== priorText;
}

async function postSendConfirmationBaseline(cdp, before) {
  await sleep(POST_SEND_BASELINE_SETTLE_MS);
  const after=await assistantSnapshot(cdp);
  return {
    progressed:assistantProgressed(before, after),
    baseline:after,
  };
}

async function confirmAfterSend(
  cdp,
  beforeSend,
  confirmProgress = confirmAssistantProgress,
) {
  const settled = await postSendConfirmationBaseline(cdp, beforeSend);
  if (settled.progressed) return true;
  return confirmProgress(cdp, settled.baseline);
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
    if (assistantProgressed(baseline, current) || assistantSurfaceProgressed(baseline, current)) return true;

    // An explicit transmission failure is terminal for this send attempt;
    // do not burn the full progress-confirmation window before recovery.
    if (await transmissionErrorPresent(cdp)) return false;

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
        const sessionResponse=await fetch('/api/auth/session',{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(${LATEST_CONVERSATION_FETCH_TIMEOUT_MS})});
        if(sessionResponse.ok){
          let session=null; try{session=await sessionResponse.json();}catch{}
          accessToken=String(session?.accessToken||session?.access_token||'').trim();
          accountId=String(session?.account?.id||'').trim();
        }
      }catch{}
      const headers={Accept:'application/json'};
      if(accessToken) headers.Authorization='Bearer '+accessToken;
      if(accountId) headers['ChatGPT-Account-Id']=accountId;
      const fetchJson=async(url,options={})=>{
        try{
          const response=await fetch(url,{
            ...options,
            signal:AbortSignal.timeout(${LATEST_CONVERSATION_FETCH_TIMEOUT_MS}),
          });
          let body=null; try{body=await response.json();}catch{}
          return {response,body};
        }catch{
          return {response:null,body:null};
        }
      };

      const discovered=[];
      const statuses=[];
      const addItems=(items,source,projectId='')=>{
        for(const item of (Array.isArray(items)?items:[]).slice(0,24)){
          const id=String(item?.id||item?.conversation_id||'').trim();
          const update_time=item?.update_time??item?.updateTime??item?.updated_at??item?.updatedAt??null;
          if(!id) continue;
          discovered.push({
            id,
            update_time,
            source,
            project_id:projectId||'',
          });
        }
      };

      // Regular account conversations.
      const candidates = [
        {source:'filtered',url:'/backend-api/conversations?offset=0&limit=12&order=updated&is_archived=false&is_starred=false'},
        {source:'fallback_unfiltered',url:'/backend-api/conversations?offset=0&limit=12&order=updated'},
      ];
      let globalLast={http_status:0,source:'none',item_present:false,item_keys:[]};
      for(const candidate of candidates){
        const {response,body}=await fetchJson(
          candidate.url,
          {credentials:'same-origin',cache:'no-store',headers},
        );
        if(!response){
          globalLast={http_status:0,source:candidate.source,item_present:false,item_keys:[]};
          continue;
        }
        const items=Array.isArray(body?.items)?body.items:(Array.isArray(body?.conversations)?body.conversations:(Array.isArray(body)?body:[]));
        const status=Number(response.status||0);
        statuses.push(status);
        globalLast={http_status:status,source:candidate.source,item_present:items.length>0,item_keys:[]};
        if(response.ok&&items.length>0){
          addItems(items,'global','');
          break;
        }
      }

      // Project chats have the same /c/<id> route, but are discovered from
      // /backend-api/gizmos/g-p-.../conversations. Project ids are already
      // present in the authenticated sidebar's loaded resource graph, so this
      // adds no content scraping and avoids guessing from titles.
      const projectIds=[];
      const seenProjects=new Set();
      const addProjectId=raw=>{
        const match=String(raw||'').match(/(g-p-[A-Za-z0-9_-]{8,160})/);
        if(!match||seenProjects.has(match[1])||projectIds.length>=16) return;
        seenProjects.add(match[1]);
        projectIds.push(match[1]);
      };
      try{
        for(const entry of performance.getEntriesByType('resource')) addProjectId(entry?.name);
        for(const anchor of document.querySelectorAll('a[href*="g-p-"]')) addProjectId(anchor.getAttribute('href'));
      }catch{}

      for(let offset=0;offset<projectIds.length;offset+=4){
        const batch=projectIds.slice(offset,offset+4);
        await Promise.allSettled(batch.map(async projectId=>{
          const {response,body}=await fetchJson(
            '/backend-api/gizmos/'+encodeURIComponent(projectId)+'/conversations?limit=24&owned_only=false',
            {credentials:'same-origin',cache:'no-store',headers},
          );
          if(!response) return;
          const items=Array.isArray(body?.items)?body.items:(Array.isArray(body)?body:[]);
          statuses.push(Number(response.status||0));
          if(response.ok&&items.length>0) addItems(items,'project',projectId);
        }));
      }

      const byId=new Map();
      const toEpoch=value=>{
        let n=Number(value||0);
        if(Number.isFinite(n)&&n>0) return n;
        const parsed=Date.parse(String(value||''));
        return Number.isFinite(parsed)?parsed/1000:0;
      };
      for(const item of discovered){
        const update=toEpoch(item.update_time);
        if(!/^[A-Za-z0-9_-]{8,160}$/.test(item.id)||update<=0) continue;
        const normalized={...item,update_time:update};
        const previous=byId.get(item.id);
        if(!previous||update>Number(previous.update_time||0)) byId.set(item.id,normalized);
      }
      const combined=[...byId.values()]
        .sort((a,b)=>Number(b.update_time||0)-Number(a.update_time||0))
        .slice(0,24);
      const top=combined[0]||null;
      const item=top||{};
      const any429=statuses.some(status=>status===429);
      const anySuccess=statuses.some(status=>status>=200&&status<300);
      const httpStatus=any429?429:(anySuccess?200:Number(globalLast.http_status||0));
      const item_keys=top?Object.keys(item)
        .map(key=>String(key).replace(/[^A-Za-z0-9_]/g,'').slice(0,64))
        .filter(Boolean)
        .slice(0,32):[];
      return {
        http_status:httpStatus,
        source:'combined',
        item_present:Boolean(top),
        item_keys,
        id:String(top?.id||''),
        update_time:top?.update_time??null,
        candidates:combined,
        project_count:projectIds.length,
      };
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
      : {http_status:0,source:'probe_failed',item_present:false,item_keys:[],candidates:[]};
  } catch {
    return {http_status:0,source:'probe_failed',item_present:false,item_keys:[],candidates:[]};
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
function normalizeConversationCandidates(result) {
  if (!result || typeof result !== 'object') return [];
  const raw = Array.isArray(result.candidates) && result.candidates.length > 0
    ? result.candidates
    : [result];
  const byId = new Map();
  for (const item of raw) {
    const meta = normalizeLatestConversationMeta(item);
    if (!meta) continue;
    const candidate = {
      ...meta,
      source: text(item?.source) || 'unknown',
      project_id: text(item?.project_id),
    };
    const previous = byId.get(meta.id);
    if (!previous || candidate.update_time > previous.update_time) {
      byId.set(meta.id, candidate);
    }
  }
  return [...byId.values()]
    .sort((a, b) => b.update_time - a.update_time)
    .slice(0, REINFORCEMENT_SWEEP_MAX_CANDIDATES);
}

function mergeRecentConversationCandidates(
  existing,
  incoming,
  nowMs = Date.now(),
  maxAgeMs = CHECKPOINT_AMBIGUOUS_CONVERSATION_MAX_AGE_MS,
) {
  const byId = new Map();
  for (const candidate of [...(Array.isArray(existing) ? existing : []), ...(Array.isArray(incoming) ? incoming : [])]) {
    const normalized = normalizeConversationCandidates({ candidates: [candidate] })[0];
    if (!normalized) continue;
    const ageMs = Math.max(0, Number(nowMs) - normalized.update_time * 1000);
    if (!Number.isFinite(ageMs) || ageMs > Math.max(60_000, Number(maxAgeMs || CHECKPOINT_AMBIGUOUS_CONVERSATION_MAX_AGE_MS))) {
      continue;
    }
    const previous = byId.get(normalized.id);
    if (!previous || normalized.update_time > previous.update_time) byId.set(normalized.id, normalized);
  }
  return [...byId.values()]
    .sort((a, b) => b.update_time - a.update_time)
    .slice(0, REINFORCEMENT_SWEEP_MAX_CANDIDATES);
}

function reinforcementSweepCandidates(
  candidates,
  latestId = '',
  cursor = 0,
  batchSize = REINFORCEMENT_SWEEP_BATCH_SIZE,
) {
  const pool = (Array.isArray(candidates) ? candidates : [])
    .filter(candidate => candidate?.id && candidate.id !== latestId);
  if (pool.length === 0) return { batch: [], next_cursor: 0 };
  const start = ((Number(cursor) || 0) % pool.length + pool.length) % pool.length;
  const count = Math.min(pool.length, Math.max(1, Number(batchSize) || 1));
  const batch = [];
  for (let index = 0; index < count; index += 1) {
    batch.push(pool[(start + index) % pool.length]);
  }
  return {
    batch,
    next_cursor: (start + count) % pool.length,
  };
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

  const currentPath = String(await cdp.evaluate('location.pathname') || '');
  const currentMatch = currentPath.match(/^\/c\/([^/?#]+)/);
  if (currentMatch && currentMatch[1] === latest.id) {
    return { action: 'already_latest', restore_path: '' };
  }

  const target = '/c/' + latest.id;
  const navigated = await cdp.evaluate(`(()=>{location.assign(${JSON.stringify(target)});return true})()`);
  if (!navigated) return { action: 'navigation_failed', restore_path: '' };
  await sleep(1500);
  return { action: 'navigated', restore_path: currentPath };
}

async function alignLocalSidebarForReinforcement(cdp) {
  const sidebar = await alignToSidebarLatestConversation(cdp);
  if (sidebar.action === 'sidebar_unavailable') {
    return {
      action: 'latest_unavailable',
      http_status: 0,
      local_sidebar_only: true,
    };
  }
  return {
    ...sidebar,
    http_status: 0,
    local_sidebar_only: true,
  };
}

async function alignLatestForReinforcement(
  cdp,
  probeLatest = latestConversationProbe,
  nowMs = Date.now(),
  maxAgeMs = CHECKPOINT_AMBIGUOUS_CONVERSATION_MAX_AGE_MS,
) {
  const probe = await probeLatest(cdp);
  const rawStatus = Number(probe?.http_status || 0);
  const httpStatus = Number.isFinite(rawStatus)
    ? Math.max(0, Math.min(599, Math.trunc(rawStatus)))
    : 0;
  const discoveredCandidates = normalizeConversationCandidates(probe);
  if (discoveredCandidates.length > 0) {
    REINFORCEMENT_RECENT_CANDIDATES = mergeRecentConversationCandidates(
      REINFORCEMENT_RECENT_CANDIDATES,
      discoveredCandidates,
      nowMs,
      CHECKPOINT_AMBIGUOUS_CONVERSATION_MAX_AGE_MS,
    );
    REINFORCEMENT_LATEST_ID = discoveredCandidates[0].id;
  }
  const latest = discoveredCandidates[0] || normalizeLatestConversationMeta(probe);
  const projectCount = Math.max(0, Number(probe?.project_count || 0));
  const candidateCount = discoveredCandidates.length;
  const latestAgeSeconds = latest
    ? Math.max(0, Math.round((Number(nowMs) - Number(latest.update_time) * 1000) / 1000))
    : null;
  if (!latest) {
    if (httpStatus === 429) {
      const sidebar = await alignToSidebarLatestConversation(cdp);
      if (
        sidebar.action === 'navigated_sidebar_fallback'
        || sidebar.action === 'already_latest_sidebar_fallback'
      ) {
        return {
          ...sidebar,
          http_status: httpStatus,
          candidate_count: candidateCount,
          project_count: projectCount,
          latest_age_seconds: latestAgeSeconds,
        };
      }
    }
    return {
      action: 'latest_unavailable',
      http_status: httpStatus,
      candidate_count: candidateCount,
      project_count: projectCount,
      latest_age_seconds: latestAgeSeconds,
    };
  }
  const alignment = await alignToLatestConversation(
    cdp,
    async () => latest,
    nowMs,
    maxAgeMs,
  );
  return {
    ...alignment,
    http_status: httpStatus,
    candidate_count: candidateCount,
    project_count: projectCount,
    latest_age_seconds: latestAgeSeconds,
  };
}

async function currentConversationSurfaceContains(cdp, markers, probeToken) {
  const normalized = markers.map(marker => String(marker || '').toLowerCase()).filter(Boolean);
  return Boolean(await cdp.evaluate(`(()=>{
    /* ${probeToken} */
    const needles=${JSON.stringify(normalized)};
    const body=document.body;
    if(!body||needles.length===0) return false;

    let scope='';
    const messages=[...body.querySelectorAll('[data-message-author-role]')];
    const lastMessage=messages[messages.length-1]||null;

    if(lastMessage){
      try{
        const range=document.createRange();
        range.selectNodeContents(body);
        range.setStartAfter(lastMessage);
        scope=range.toString();
      }catch{
        // If Range cannot be constructed for a transient React tree, collect
        // only following siblings/ancestors rather than scanning old turns.
        let node=lastMessage;
        while(node&&node!==body){
          for(let sibling=node.nextSibling;sibling;sibling=sibling.nextSibling){
            scope+=' '+String(sibling.innerText||sibling.textContent||'');
          }
          node=node.parentElement;
        }
      }
    }else{
      // Some loading/virtualized ChatGPT surfaces do not expose role markers.
      // Keep only the tail so historical failures near the top cannot retrigger.
      scope=String(body.innerText||body.textContent||'').slice(-16000);
    }

    // Error/status UI often lives in a portal outside the turn subtree.
    for(const el of body.querySelectorAll('[role="alert"],[aria-live],[data-testid*="error" i]')){
      scope+=' '+String(el.innerText||el.textContent||'');
    }

    const haystack=scope.toLowerCase();
    return needles.some(needle=>haystack.includes(needle));
  })()`));
}

async function transmissionErrorPresent(cdp) {
  return currentConversationSurfaceContains(
    cdp,
    [
      'erro na transmissão',
      'erro na transmissao',
      'error sending message',
      'error in message transmission',
      'message transmission error',
    ],
    'continuity-transmission-error-probe',
  );
}

async function errorBannerPresent(cdp) {
  // Query only the current turn surface / live error regions. A truncated
  // pageState prefix misses bottom-of-thread failures in long conversations,
  // while scanning the whole history would falsely retrigger old failures.
  return currentConversationSurfaceContains(
    cdp,
    [
      'something went wrong',
      'algo deu errado',
      'there was an error generating',
      'houve um erro ao gerar',
      'streaming interrupted',
      'transmissão interrompida',
      'transmissao interrompida',
      'stopped thinking',
      'parou de pensar',
    ],
    'continuity-error-banner-probe',
  );
}

async function clickTrustedSendButton(cdp) {
  for (let attempt=0; attempt<8; attempt += 1) {
    const submitTarget = await cdp.evaluate(`(()=>{
      /* continuity-send-button-target */
      const el=document.querySelector('[data-testid="prompt-textarea"]')
        || document.querySelector('[role="textbox"][contenteditable="true"]');
      const form=el?.closest('form')||null;
      const exactSelectors=[
        '[data-testid="send-button"]',
        'button[aria-label="Send"]',
        'button[aria-label="Send prompt"]',
        'button[aria-label="Send message"]',
        'button[aria-label="Enviar"]',
        'button[aria-label="Enviar prompt"]',
        'button[aria-label="Enviar mensagem"]',
        'button[type="submit"]'
      ];
      let button=null;
      for(const selector of exactSelectors){
        const candidate=(form||document).querySelector(selector);
        if(candidate){button=candidate;break;}
      }
      if(!button) return {state:'absent'};
      if(button.disabled || button.getAttribute('aria-disabled') === 'true') {
        return {state:'disabled'};
      }
      button.scrollIntoView({block:'nearest',inline:'nearest'});
      const rect=button.getBoundingClientRect();
      if(!(rect.width>0&&rect.height>0)) return {state:'unusable'};
      return {
        state:'ready',
        x:rect.left+rect.width/2,
        y:rect.top+rect.height/2
      };
    })()`);

    if (submitTarget?.state === 'ready') {
      try {
        const x=Number(submitTarget.x);
        const y=Number(submitTarget.y);
        if(!Number.isFinite(x)||!Number.isFinite(y)) return false;
        await cdp.send('Input.dispatchMouseEvent', {
          type:'mouseMoved', x, y, button:'none',
        });
        await cdp.send('Input.dispatchMouseEvent', {
          type:'mousePressed', x, y, button:'left', clickCount:1,
        });
        await cdp.send('Input.dispatchMouseEvent', {
          type:'mouseReleased', x, y, button:'left', clickCount:1,
        });
        return true;
      } catch {
        return false;
      }
    }
    if (submitTarget?.state === 'disabled') {
      await sleep(150);
      continue;
    }
    break;
  }
  return false;
}

async function sendContinueMessage(cdp) {
  const trustedProbe = typeof cdp?.send === 'function'
    ? await cdp.evaluate(`(()=>{
        /* continuity-composer-draft-probe */
        const el=document.querySelector('[data-testid="prompt-textarea"]')
          || document.querySelector('[role="textbox"][contenteditable="true"]');
        if(!el) return {usable:false,text:''};
        const usable=!Boolean(el.disabled) && el.getAttribute('aria-disabled') !== 'true';
        return {usable,text:String(el.innerText||el.value||el.textContent||'')};
      })()`)
    : null;

  if (trustedProbe && typeof trustedProbe === 'object') {
    if (!trustedProbe.usable) return false;

    const existing = String(trustedProbe.text || '').trim();
    const expected = String(CONTINUE_MESSAGE || '').trim();
    if (!expected) return false;

    // Never overwrite a real draft. The only non-empty value safe to replace
    // is our own stale continuation left by a previous failed DOM-only send.
    if (existing && existing !== expected) return false;

    // A DOM focus() is not sufficient for the current ChatGPT editor:
    // live production proved that only a trusted pointer click initializes
    // the editor selection/state so subsequent keyboard events enable Send.
    const composerTarget = await cdp.evaluate(`(()=>{
      /* continuity-composer-click-target */
      const el=document.querySelector('[data-testid="prompt-textarea"]')
        || document.querySelector('[role="textbox"][contenteditable="true"]');
      if(!el) return null;
      el.scrollIntoView({block:'center',inline:'nearest'});
      const rect=el.getBoundingClientRect();
      if(!(rect.width>0&&rect.height>0)) return null;
      return {
        x:rect.left+rect.width/2,
        y:rect.top+rect.height/2
      };
    })()`);
    if (
      !composerTarget
      || !Number.isFinite(Number(composerTarget.x))
      || !Number.isFinite(Number(composerTarget.y))
    ) return false;

    try {
      const x=Number(composerTarget.x);
      const y=Number(composerTarget.y);
      await cdp.send('Input.dispatchMouseEvent', {
        type:'mouseMoved', x, y, button:'none',
      });
      await cdp.send('Input.dispatchMouseEvent', {
        type:'mousePressed', x, y, button:'left', clickCount:1,
      });
      await cdp.send('Input.dispatchMouseEvent', {
        type:'mouseReleased', x, y, button:'left', clickCount:1,
      });
    } catch {
      return false;
    }
    await sleep(120);

    const focused = await cdp.evaluate(`(()=>{
      /* continuity-composer-focus */
      const el=document.querySelector('[data-testid="prompt-textarea"]')
        || document.querySelector('[role="textbox"][contenteditable="true"]');
      if(!el) return false;
      return document.activeElement===el || el.contains(document.activeElement);
    })()`);
    if (!focused) {
      // Current ProseMirror can receive a trusted pointer event while leaving
      // document.activeElement on BODY. If the only draft present is exactly
      // our own bounded continuation and Send is already enabled, submitting
      // that safe draft is preferable to treating the editor as unusable.
      if (existing === expected && await clickTrustedSendButton(cdp)) return true;
      return false;
    }

    // Reset stale DOM/editor state using trusted keyboard events. This is
    // important because DOM text can be visible while ChatGPT's internal
    // editor state still considers the composer empty and keeps Send disabled.
    try {
      await cdp.send('Input.dispatchKeyEvent', {
        type:'rawKeyDown', key:'Control', code:'ControlLeft',
        windowsVirtualKeyCode:17, nativeVirtualKeyCode:17, modifiers:2,
      });
      await cdp.send('Input.dispatchKeyEvent', {
        type: 'rawKeyDown', key: 'a', code: 'KeyA',
        windowsVirtualKeyCode: 65, nativeVirtualKeyCode: 65, modifiers: 2,
      });
      await cdp.send('Input.dispatchKeyEvent', {
        type: 'keyUp', key: 'a', code: 'KeyA',
        windowsVirtualKeyCode: 65, nativeVirtualKeyCode: 65, modifiers: 2,
      });
      await cdp.send('Input.dispatchKeyEvent', {
        type:'keyUp', key:'Control', code:'ControlLeft',
        windowsVirtualKeyCode:17, nativeVirtualKeyCode:17,
      });
      await cdp.send('Input.dispatchKeyEvent', {
        type: 'rawKeyDown', key: 'Backspace', code: 'Backspace',
        windowsVirtualKeyCode: 8, nativeVirtualKeyCode: 8,
      });
      await cdp.send('Input.dispatchKeyEvent', {
        type: 'keyUp', key: 'Backspace', code: 'Backspace',
        windowsVirtualKeyCode: 8, nativeVirtualKeyCode: 8,
      });

      for (const ch of expected) {
        let key=ch;
        let code='Unidentified';
        let vk=0;
        if (/^[a-z]$/i.test(ch)) {
          const upper=ch.toUpperCase();
          code='Key'+upper;
          vk=upper.charCodeAt(0);
        } else if (/^[0-9]$/.test(ch)) {
          code='Digit'+ch;
          vk=ch.charCodeAt(0);
        } else if (ch === ' ') {
          key=' ';
          code='Space';
          vk=32;
        }

        if (vk > 0) {
          await cdp.send('Input.dispatchKeyEvent', {
            type: 'rawKeyDown', key, code,
            windowsVirtualKeyCode: vk, nativeVirtualKeyCode: vk,
          });
          await cdp.send('Input.dispatchKeyEvent', {
            type: 'char', key, code,
            windowsVirtualKeyCode: vk, nativeVirtualKeyCode: vk,
            text: ch, unmodifiedText: ch,
          });
          await cdp.send('Input.dispatchKeyEvent', {
            type: 'keyUp', key, code,
            windowsVirtualKeyCode: vk, nativeVirtualKeyCode: vk,
          });
        } else {
          await cdp.send('Input.insertText', {text: ch});
        }
      }
    } catch {
      return false;
    }

    await sleep(300);

    // Prefer the real enabled submit control once trusted input has updated
    // ChatGPT's internal editor state. Submit it with a trusted CDP pointer
    // click; a synthetic HTMLElement.click() did not reproduce the successful
    // live interaction on the current editor.
    if (await clickTrustedSendButton(cdp)) return true;

    try {
      await cdp.send('Input.dispatchKeyEvent', {
        type: 'rawKeyDown', key: 'Enter', code: 'Enter',
        windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13,
      });
      await cdp.send('Input.dispatchKeyEvent', {
        type: 'keyUp', key: 'Enter', code: 'Enter',
        windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13,
      });
      return true;
    } catch {
      return false;
    }
  }

  // Legacy/mock compatibility path. Production Cdp instances expose send()
  // and therefore use the trusted keyboard path above.
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
  const clicked = await cdp.evaluate(`(()=>{
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
  if (clicked) return true;
  if (typeof cdp?.send !== 'function') return false;
  try {
    await cdp.send('Input.dispatchKeyEvent', {
      type: 'keyDown', key: 'Enter', code: 'Enter',
      windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13,
    });
    await cdp.send('Input.dispatchKeyEvent', {
      type: 'keyUp', key: 'Enter', code: 'Enter',
      windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13,
    });
    return true;
  } catch {
    return false;
  }
}

async function attemptNudge(
  taskId,
  connect = null,
  confirmProgress = confirmAssistantProgress,
  waitComposer = waitForComposerUsable,
) {
  let cdp;
  try {
    const connector = connect || (() => Cdp.connectToChatgptTab({
      allowLatestDisambiguation: true,
      targetUpdatedAtMs: checkpointUpdatedAtMs(taskId),
    }));
    cdp = await connector();
    let recoveredStaleComplete = false;

    // A real 2026-09-30 silent-stall capture proved that ChatGPT can expose no
    // Stop button while the canonical current_node still ends in an assistant
    // tool/thought branch with end_turn=false. Therefore every checkpoint
    // resume gets exactly one passive reattach before any continuation send,
    // not only turns whose DOM still looks generating.
    const wasGenerating = await conversationIsGenerating(cdp);
    const passiveBaseline = await assistantSnapshot(cdp);
    await cdp.evaluate(`(()=>{location.reload();return true})()`);
    await sleep(1200);
    const passiveProgressed = await confirmProgress(
      cdp,
      passiveBaseline,
      PASSIVE_REATTACH_CONFIRM_MS,
      PROGRESS_POLL_MS,
    );
    if (passiveProgressed) {
      return {
        result_status: 'PROGRESS_CONFIRMED',
        detail: 'passive reattach restored assistant progress without sending continuation',
      };
    }

    const generatingAfterReattach = await conversationIsGenerating(cdp);
    if (wasGenerating || generatingAfterReattach) {
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
      if (generatingAfterReattach) {
        if (!(await clearStaleCompleteGeneration(cdp))) {
          return { result_status: 'STALLED_NOT_CONFIRMED', detail: 'stale COMPLETE stream detected but Stop state did not clear' };
        }
      }
      recoveredStaleComplete = true;
    }
    if (!(await waitComposer(cdp))) {
      return {
        result_status: 'CONVERSATION_NOT_FOUND',
        detail: 'composer/send-button selector not found after bounded post-reattach wait (possible UI drift)',
      };
    }
    let baseline = await assistantSnapshot(cdp);
    let sent = await sendContinueMessage(cdp);
    if (!sent) {
      // Live production evidence 2026-10-01: the error banner can be visible
      // while the composer remains temporarily disabled. Reattach once before
      // declaring send failure; otherwise the watchdog loses the conversation
      // exactly when "Parou de pensar" is displayed.
      await cdp.evaluate(`(()=>{location.reload();return true})()`);
      await sleep(1200);
      if (!(await waitComposer(cdp))) {
        return { result_status: 'ERROR', detail: 'composer/send-button remained unavailable after bounded reattach' };
      }
      baseline = await assistantSnapshot(cdp);
      sent = await sendContinueMessage(cdp);
      if (!sent) return { result_status: 'ERROR', detail: 'composer found but send failed after bounded reattach' };
    }

    let progressed = await confirmAfterSend(cdp, baseline, confirmProgress);
    if (!progressed && await transmissionErrorPresent(cdp)) {
      // A real iOS capture shows an explicit "Erro na transmissão de mensagem".
      // Treat this as a transport failure, not as an ambiguous unconfirmed send:
      // reload/reattach once and retry only after the UI still reports the error.
      const retryBaseline = await assistantSnapshot(cdp);
      await cdp.evaluate(`(()=>{location.reload();return true})()`);
      await sleep(1200);
      if (await confirmProgress(cdp, retryBaseline, PASSIVE_REATTACH_CONFIRM_MS, PROGRESS_POLL_MS)) {
        return {
          result_status: 'PROGRESS_CONFIRMED',
          detail: 'transmission error recovered during passive reattach without duplicate continuation',
        };
      }
      if (!(await waitComposer(cdp))) {
        return {
          result_status: 'ERROR',
          detail: 'transmission error persisted and composer was unavailable after reattach',
        };
      }
      const retryAfterReattachBaseline = await assistantSnapshot(cdp);
      const retrySent = await sendContinueMessage(cdp);
      if (!retrySent) {
        return {
          result_status: 'ERROR',
          detail: 'transmission error persisted and retry send failed',
        };
      }
      progressed = await confirmAfterSend(cdp, retryAfterReattachBaseline, confirmProgress);
      if (progressed) {
        return {
          result_status: 'PROGRESS_CONFIRMED',
          detail: 'transmission error recovered by one bounded reattach and retry',
        };
      }
      if (await transmissionErrorPresent(cdp)) {
        return {
          result_status: 'ERROR',
          detail: 'transmission error persisted after bounded recovery retry',
        };
      }
    }
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
  connect = () => connectReinforcementChatgptTab({ allowCrossDeviceDiscovery: true }),
  confirmDelayMs = REINFORCEMENT_CONFIRM_DELAY_MS,
  confirmProgress = confirmAssistantProgress,
  alignLatest = alignLatestForReinforcement,
  { allowCrossDeviceDiscovery = true } = {},
) {
  let cdp;
  let crossDeviceDiscovery = false;
  let alignmentHttpStatus = 0;
  let restorePath = '';
  let failureSignal = '';
  try {
    cdp = await connect();

    // Cheap, local signal first. Do not hit the account-scoped conversation
    // listing when the currently open conversation already exposes a failure.
    let bannerPresent = await errorBannerPresent(cdp);
    if (bannerPresent) {
      failureSignal = 'banner';
    } else {
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
      restorePath = safeReinforcementRestorePath(alignment?.restore_path);
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

      bannerPresent = await errorBannerPresent(cdp);
      if (bannerPresent) {
        failureSignal = 'banner';
      } else if (await silentStallPresent(cdp)) {
        // The iOS client can show "Transmissão interrompida" while the same
        // latest conversation has no banner in the canonical VM. Only the
        // canonical unfinished-turn shape below is accepted as equivalent.
        failureSignal = 'silent_stall';
      } else {
        return {
          action: 'no_banner',
          http_status: alignmentHttpStatus,
          cross_device_discovery: true,
        };
      }
    }

    await sleep(confirmDelayMs);
    if (!crossDeviceDiscovery) {
      cdp.close();
      cdp = await connect();
    }

    let failureStillPresent = await errorBannerPresent(cdp);
    if (!failureStillPresent && crossDeviceDiscovery) {
      const silentStillPresent = await silentStallPresent(cdp);
      if (silentStillPresent) {
        failureSignal = 'silent_stall';
        failureStillPresent = true;
      }
    }
    if (!failureStillPresent) {
      console.log(`chatgpt_continuity_reinforcement ${failureSignal || 'failure'}_self_resolved`);
      return {
        action: 'self_resolved',
        http_status: alignmentHttpStatus,
        cross_device_discovery: crossDeviceDiscovery,
      };
    }

    if (failureSignal === 'silent_stall') {
      const passiveBaseline = await assistantSnapshot(cdp);
      await cdp.evaluate(`(()=>{location.reload();return true})()`);
      await sleep(1200);
      const passiveProgressed = await confirmProgress(
        cdp,
        passiveBaseline,
        PASSIVE_REATTACH_CONFIRM_MS,
        PROGRESS_POLL_MS,
      );
      if (passiveProgressed) {
        console.log('chatgpt_continuity_reinforcement silent_stall_passive_reattach_progress=true');
        return {
          action: 'self_resolved',
          sent: false,
          progress_confirmed: true,
          http_status: alignmentHttpStatus,
          cross_device_discovery: crossDeviceDiscovery,
        };
      }

      if (!(await silentStallPresent(cdp))) {
        console.log('chatgpt_continuity_reinforcement silent_stall_state_cleared_after_reattach');
        return {
          action: 'self_resolved',
          sent: false,
          progress_confirmed: false,
          http_status: alignmentHttpStatus,
          cross_device_discovery: crossDeviceDiscovery,
        };
      }

      if (!(await waitForComposerUsable(cdp))) {
        console.log('chatgpt_continuity_reinforcement silent_stall_confirmed composer=false');
        return {
          action: 'send_failed',
          sent: false,
          progress_confirmed: false,
          http_status: alignmentHttpStatus,
          cross_device_discovery: crossDeviceDiscovery,
        };
      }

      const baseline = await assistantSnapshot(cdp);
      const sent = await sendContinueMessage(cdp);
      if (!sent) {
        console.log('chatgpt_continuity_reinforcement silent_stall_confirmed sent=false');
        return {
          action: 'send_failed',
          sent: false,
          progress_confirmed: false,
          http_status: alignmentHttpStatus,
          cross_device_discovery: crossDeviceDiscovery,
        };
      }

      const progressed = await confirmAfterSend(cdp, baseline, confirmProgress);
      const action = progressed ? 'confirmed_progress' : 'sent_unconfirmed';
      console.log(`chatgpt_continuity_reinforcement silent_stall_confirmed sent=true progress=${progressed}`);
      return {
        action,
        sent: true,
        progress_confirmed: progressed,
        http_status: alignmentHttpStatus,
        cross_device_discovery: crossDeviceDiscovery,
      };
    }

    // Reuse the same hardened recovery path as checkpoint-driven nudges.
    // The live VM reproduced a selector mismatch here even though attemptNudge
    // could successfully reattach and confirm progress on the same conversation.
    const outcome = await attemptNudge(
      'reinforcement-live',
      async () => cdp,
      confirmProgress,
    );
    const action = outcome.result_status === 'PROGRESS_CONFIRMED'
      ? 'confirmed_progress'
      : outcome.result_status === 'SENT_UNCONFIRMED'
        ? 'sent_unconfirmed'
        : outcome.result_status === 'ERROR'
          ? 'send_failed'
          : 'send_failed';
    console.log(`chatgpt_continuity_reinforcement error_banner_confirmed result=${outcome.result_status}`);
    return {
      action,
      sent: outcome.result_status !== 'CONVERSATION_NOT_FOUND',
      progress_confirmed: outcome.result_status === 'PROGRESS_CONFIRMED',
      detail: outcome.detail,
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
    if (restorePath) {
      await restoreReinforcementPath(cdp, restorePath);
    }
    cdp?.close();
  }
}

function reinforcementDiscoveryDelayMs(result) {
  if (result?.cross_device_discovery !== true) return 0;
  if (Number(result?.http_status) === 429) {
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
  let nextAccountDiscoveryAt = 0;
  for (;;) {
    const allowAccountDiscovery = now() >= nextAccountDiscoveryAt;
    const alignLatest = allowAccountDiscovery
      ? alignLatestForReinforcement
      : alignLocalSidebarForReinforcement;

    let outcome;
    try {
      outcome = await check(
        () => connectReinforcementChatgptTab({ allowCrossDeviceDiscovery: true }),
        REINFORCEMENT_CONFIRM_DELAY_MS,
        confirmAssistantProgress,
        alignLatest,
        { allowCrossDeviceDiscovery: true },
      );
    } catch (error) {
      console.error(`chatgpt_continuity_reinforcement_error ${text(error?.message)}`);
      outcome = {
        action: 'error',
        cross_device_discovery: true,
      };
    }

    if (allowAccountDiscovery) {
      console.log(
        'chatgpt_continuity_reinforcement_discovery'
        + ' action=' + text(outcome?.action)
        + ' http_status=' + String(Number(outcome?.http_status || 0))
        + ' candidate_count=' + String(Number(outcome?.candidate_count || 0))
        + ' project_count=' + String(Number(outcome?.project_count || 0))
        + ' latest_age_seconds=' + String(
          outcome?.latest_age_seconds === null || outcome?.latest_age_seconds === undefined
            ? -1
            : Math.max(0, Number(outcome.latest_age_seconds) || 0),
        ),
      );
      const discoveryDelayMs = reinforcementDiscoveryDelayMs(outcome);
      if (discoveryDelayMs > 0) {
        nextAccountDiscoveryAt = now() + discoveryDelayMs;
        if (Number(outcome?.http_status) === 429) {
          console.log(
            `chatgpt_continuity_reinforcement latest_discovery_backoff_ms=${discoveryDelayMs} action=${text(outcome?.action)} local_sidebar_continues=true`,
          );
        }
      }
    }

    // Production default only: after the newest chat is checked, rotate through
    // additional recent chats discovered from both the global account list and
    // Project gizmo lists. Keep this inside the same loop so browser navigation
    // stays serialized; tests that inject a custom check retain the historical
    // single-call contract.
    if (check === reinforcementCheckOnce && REINFORCEMENT_RECENT_CANDIDATES.length > 1) {
      const sweep = reinforcementSweepCandidates(
        REINFORCEMENT_RECENT_CANDIDATES,
        REINFORCEMENT_LATEST_ID,
        REINFORCEMENT_RECENT_CURSOR,
        REINFORCEMENT_SWEEP_BATCH_SIZE,
      );
      REINFORCEMENT_RECENT_CURSOR = sweep.next_cursor;
      for (const candidate of sweep.batch) {
        try {
          await check(
            () => connectReinforcementChatgptTab({ allowCrossDeviceDiscovery: true }),
            REINFORCEMENT_CONFIRM_DELAY_MS,
            confirmAssistantProgress,
            cdp => alignToLatestConversation(
              cdp,
              async () => candidate,
              now(),
              CHECKPOINT_AMBIGUOUS_CONVERSATION_MAX_AGE_MS,
            ),
            { allowCrossDeviceDiscovery: true },
          );
        } catch (error) {
          console.error(
            `chatgpt_continuity_reinforcement_sweep_error source=${text(candidate?.source)} project=${candidate?.project_id ? 'true' : 'false'} detail=${text(error?.message)}`,
          );
        }
      }
    }

    await wait(REINFORCEMENT_POLL_MS);
  }
}

async function mainLoop(
  runBridgeLoop = bridgeLoop,
  runReinforcementLoop = reinforcementLoop,
  reinforcementEnabled = STALL_REINFORCEMENT_ENABLED,
  runAuthorizationLoop = authorizationLoop,
  autoAllowEnabled = AUTO_ALLOW_ENABLED,
) {
  const loops = [runBridgeLoop()];
  if (reinforcementEnabled) loops.push(runReinforcementLoop());
  if (autoAllowEnabled) loops.push(runAuthorizationLoop());
  await Promise.all(loops);
}

if (import.meta.url === `file://${process.argv[1]}`) {
  mainLoop();
}

export {
  Cdp,
  selectChatgptTab,
  connectFirstUsableChatgptTab,
  connectReinforcementChatgptTab,
  resolveAmbiguousConversationTabs,
  checkpointUpdatedAtMs,
  selectCheckpointConversationCandidate,
  conversationIsGenerating,
  conversationStreamStatus,
  conversationTurnState,
  silentStallPresent,
  composerIsUsable,
  waitForComposerUsable,
  errorBannerPresent,
  transmissionErrorPresent,
  latestConversationProbe,
  normalizeLatestConversationMeta,
  normalizeConversationCandidates,
  mergeRecentConversationCandidates,
  reinforcementSweepCandidates,
  latestConversationMeta,
  alignToLatestConversation,
  alignLatestForReinforcement,
  alignLocalSidebarForReinforcement,
  assistantSnapshot,
  assistantProgressed,
  confirmAssistantProgress,
  sendContinueMessage,
  attemptNudge,
  reinforcementCheckOnce,
  reinforcementDiscoveryDelayMs,
  bridgeLoop,
  reinforcementLoop,
  authorizationButtonTarget,
  clickAuthorizationIfPresent,
  authorizationCheckOnce,
  authorizationLoop,
  mainLoop,
};
