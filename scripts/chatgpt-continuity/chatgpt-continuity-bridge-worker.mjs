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
import { AsyncLocalStorage } from 'node:async_hooks';
import { createHash } from 'node:crypto';

const BRIDGE_ENDPOINT = process.env.CHATGPT_CONTINUITY_BRIDGE_ENDPOINT
  || 'http://127.0.0.1:18081/api/chatgpt-continuity/bridge.php';
const BRIDGE_HOST_HEADER = process.env.CHATGPT_CONTINUITY_BRIDGE_HOST_HEADER || 'shopvivaliz.com.br';
const TOKEN_FILE = process.env.CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE
  || '/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token';
const CDP_BASE = process.env.CHATGPT_CONTINUITY_CDP_URL || 'http://127.0.0.1:9555';
const TASK_STATE_DIR = process.env.SHOPVIVALIZ_AGENT_TASK_STATE_DIR
  || '/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state';
// Keep one queue consumer. Scope each explicitly bound checkpoint to its own
// browser without changing the concurrent legacy reinforcement context.
const BROWSER_SESSION_CONTEXT = new AsyncLocalStorage();
const BROWSER_SESSIONS = Object.freeze({
  fred: Object.freeze({ cdpBase: 'http://127.0.0.1:9555', expectedEmail: 'fredmourao@gmail.com' }),
  atendimento: Object.freeze({ cdpBase: 'http://127.0.0.1:9556', expectedEmail: 'atendimento@shopvivaliz.com.br' }),
});
function browserCdpBase() {
  return BROWSER_SESSION_CONTEXT.getStore()?.cdpBase || CDP_BASE;
}
function taskBrowserSession(taskId, requestedConversationId = '') {
  if (!/^[A-Za-z0-9._-]{1,160}$/.test(String(taskId)) || String(taskId).includes('..')) {
    throw new Error('invalid browser session checkpoint identity');
  }
  let checkpoint;
  try { checkpoint = JSON.parse(fs.readFileSync(`${TASK_STATE_DIR}/${taskId}.json`, 'utf8')); }
  catch (error) {
    if (error?.code === 'ENOENT') return Object.freeze({ cdpBase: CDP_BASE });
    throw new Error('browser session checkpoint unreadable');
  }
  if (!Object.hasOwn(checkpoint || {}, 'browser_session')) return Object.freeze({ cdpBase: CDP_BASE });
  const name = checkpoint.browser_session;
  if (typeof name !== 'string' || !Object.hasOwn(BROWSER_SESSIONS, name)) {
    throw new Error('invalid browser session binding');
  }
  const bound = safeConversationId(checkpoint.conversation_id);
  if (!['RUNNING', 'READY_TO_COMPLETE'].includes(checkpoint.status) || !bound
      || (requestedConversationId && requestedConversationId !== bound)) {
    throw new Error('browser session checkpoint conversation mismatch or terminal state');
  }
  return Object.freeze({ ...BROWSER_SESSIONS[name], conversationId: bound, name });
}

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
const ADDITIONAL_CHECKS_COOLDOWN_MS = Math.max(
  60_000,
  Number(process.env.CHATGPT_CONTINUITY_ADDITIONAL_CHECKS_COOLDOWN_MS || 5 * 60_000),
);
const REINFORCEMENT_RECOVERY_RETRY_COOLDOWN_MS = Math.max(
  60_000,
  Number(process.env.CHATGPT_CONTINUITY_REINFORCEMENT_RECOVERY_RETRY_COOLDOWN_MS || 5 * 60_000),
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
// Full conversation reads can exceed the lightweight stream probe budget.
// Keep their total deadline below the 15s CDP command timeout.
const requestedTurnStateTimeoutMs = Number(process.env.CHATGPT_CONTINUITY_TURN_STATE_TIMEOUT_MS || 10000);
const CONVERSATION_TURN_TIMEOUT_MS = Math.min(
  12_000,
  Math.max(1000, Number.isFinite(requestedTurnStateTimeoutMs) ? requestedTurnStateTimeoutMs : 10000),
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
const MONITOR_STATE_FILE = `${TASK_STATE_DIR}/_chatgpt-continuity-monitor-state.json`;
const BROWSER_HEALTH_MAX_AGE_MS = Math.max(30_000, Number(process.env.CHATGPT_BROWSER_HEALTH_MAX_AGE_MS || 90_000));
const MONITOR_FALLBACK_FILE = process.env.CHATGPT_CONTINUITY_MONITOR_FALLBACK_FILE
  || '/home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/_chatgpt-continuity-monitor-state.json';

function outcomeDetailCode(detail) {
  const normalized = text(detail).toLowerCase();
  if (!normalized) return 'NONE';
  if (normalized.includes('failure_reason=additional_checks')) return 'RECOVERABLE_ADDITIONAL_CHECKS';
  if (normalized.includes('failure_reason=stopped_thinking')) return 'RECOVERABLE_STOPPED_THINKING';
  if (normalized.includes('failure_reason=streaming_interrupted')) return 'RECOVERABLE_STREAMING_INTERRUPTED';
  if (normalized.includes('failure_reason=request_timeout')) return 'RECOVERABLE_REQUEST_TIMEOUT';
  if (normalized.includes('composer/send-button remained unavailable after bounded reattach')) return 'COMPOSER_UNAVAILABLE_AFTER_REATTACH';
  if (normalized.includes('composer found but send failed after bounded reattach')) return 'SEND_FAILED_AFTER_REATTACH';
  if (normalized.includes('transmission error persisted and composer was unavailable after reattach')) return 'TRANSMISSION_COMPOSER_UNAVAILABLE';
  if (normalized.includes('transmission error persisted and retry send failed')) return 'TRANSMISSION_RETRY_SEND_FAILED';
  if (normalized.includes('transmission error persisted after bounded recovery retry')) return 'TRANSMISSION_PERSISTED_AFTER_RETRY';
  if (normalized.includes('conversation changed during recovery')) return 'CONVERSATION_CHANGED_DURING_RECOVERY';
  if (normalized.includes('multiple open chatgpt conversation tabs found')) return 'AMBIGUOUS_CONVERSATION_TARGET';
  if (normalized.includes('cdp endpoint unreachable')) return 'CDP_ENDPOINT_UNREACHABLE';
  if (normalized.includes('no usable open chatgpt.com tab found')) return 'NO_USABLE_CHATGPT_TAB';
  return 'UNCLASSIFIED_RUNTIME_ERROR';
}

function outcomeStatusDetailCode(status, detail) {
  const normalizedStatus = text(status).toUpperCase();
  if (normalizedStatus === 'PROGRESS_CONFIRMED') return 'PROGRESS_CONFIRMED';
  return outcomeDetailCode(detail);
}

function reinforcementHealthPayload(
  outcome,
  updatedAt = new Date().toISOString(),
  previous = {},
) {
  const action = text(outcome?.action) || 'heartbeat';
  const degradedAction = action === 'sent_unconfirmed'
    || action === 'send_failed'
    || action === 'error'
    || action === 'auth_quiescent'
    || action === 'additional_checks_cooldown'
    || action === 'recovery_retry_cooldown'
    || (outcome?.sent === true && outcome?.progress_confirmed !== true);
  const recoveredAction = action === 'self_resolved'
    || action === 'confirmed_progress'
    || action === 'idle_no_checkpoint';
  const prior = previous && typeof previous === 'object' ? previous : {};

  let degraded = prior.degraded === true;
  let effectiveAction = text(prior.action);
  let sent = prior.sent === true;
  let progressConfirmed = prior.progress_confirmed === true;
  let detail = text(prior.detail).slice(0, 400);
  let failureReason = text(prior.failure_reason);

  if (degradedAction || recoveredAction || !effectiveAction) {
    degraded = degradedAction;
    effectiveAction = action;
    sent = outcome?.sent === true;
    progressConfirmed = outcome?.progress_confirmed === true;
    detail = text(outcome?.detail).slice(0, 400);
    failureReason = text(outcome?.failure_reason);
  }

  return {
    schema_version: 2,
    updated_at: updatedAt,
    degraded,
    action: effectiveAction || action,
    last_cycle_action: action,
    sent,
    progress_confirmed: progressConfirmed,
    detail,
    failure_reason: failureReason,
  };
}

function monitorStateUpdatedAtMs(state) {
  const parsed = Date.parse(text(state?.updated_at));
  return Number.isFinite(parsed) ? parsed : 0;
}

function readMonitorState(file) {
  try {
    const parsed = JSON.parse(fs.readFileSync(file, 'utf8'));
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

function persistMonitorStateFile(file, payload) {
  const slash = file.lastIndexOf('/');
  const dir = slash > 0 ? file.slice(0, slash) : '.';
  fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
  const temp = file + '.tmp.' + process.pid;
  const fd = fs.openSync(temp, 'w', 0o600);
  try {
    fs.writeFileSync(fd, JSON.stringify(payload) + '\n', 'utf8');
    fs.fsyncSync(fd);
  } finally {
    fs.closeSync(fd);
  }
  fs.renameSync(temp, file);
  try {
    const dirFd = fs.openSync(dir, 'r');
    try { fs.fsyncSync(dirFd); } finally { fs.closeSync(dirFd); }
  } catch {}
}

function persistReinforcementHealth(outcome) {
  let previous = {};
  for (const file of [MONITOR_STATE_FILE, MONITOR_FALLBACK_FILE]) {
    const candidate = readMonitorState(file);
    if (monitorStateUpdatedAtMs(candidate) >= monitorStateUpdatedAtMs(previous)) {
      previous = candidate;
    }
  }

  const payload = reinforcementHealthPayload(outcome, new Date().toISOString(), previous);
  let persisted = 0;
  let firstError = null;
  for (const file of [...new Set([MONITOR_STATE_FILE, MONITOR_FALLBACK_FILE])]) {
    try {
      persistMonitorStateFile(file, payload);
      persisted += 1;
    } catch (error) {
      if (!firstError) firstError = error;
    }
  }
  if (persisted === 0) throw firstError || new Error('monitor health persistence failed');
  return payload;
}

async function withReinforcementHeartbeat(
  work,
  heartbeatIntervalMs = REINFORCEMENT_POLL_MS,
) {
  const requestedIntervalMs = Number(heartbeatIntervalMs);
  const intervalMs = Number.isFinite(requestedIntervalMs)
    ? Math.max(1, requestedIntervalMs)
    : REINFORCEMENT_POLL_MS;
  const timer = setInterval(() => {
    try {
      persistReinforcementHealth({
        action: 'heartbeat',
        sent: false,
        progress_confirmed: false,
        cross_device_discovery: false,
      });
    } catch (error) {
      console.error(
        `chatgpt_continuity_reinforcement_heartbeat_error ${text(error?.message)}`,
      );
    }
  }, intervalMs);
  timer.unref?.();
  try {
    return await work();
  } finally {
    clearInterval(timer);
  }
}

const SINGLE_SAFE_REINFORCEMENT_CDPS = new WeakSet();
const SIDEBAR_CONSENSUS_SAFE_REINFORCEMENT_CDPS = new WeakSet();
let REINFORCEMENT_RECENT_CANDIDATES = [];
let REINFORCEMENT_RECENT_CURSOR = 0;
let REINFORCEMENT_LATEST_ID = '';

// The checkpoint-driven bridge and reinforcement monitor share one canonical
// ChatGPT browser. Serialize only browser recovery/mutation operations so one
// path cannot navigate/reload the conversation while the other is confirming
// or sending a continuation.
let BROWSER_RECOVERY_TAIL = Promise.resolve();

async function withBrowserRecoveryLock(operation) {
  if (typeof operation !== 'function') {
    throw new TypeError('browser recovery operation must be a function');
  }
  const run = BROWSER_RECOVERY_TAIL.then(() => operation());
  BROWSER_RECOVERY_TAIL = run.catch(() => undefined);
  return run;
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
    const response = await fetch(`${browserCdpBase()}/json/version`, { signal: AbortSignal.timeout(2500) });
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
  if (/^\/(?:c|uc)\/[^/]+/.test(url.pathname)) return 0;
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

async function connectFirstUsableChatgptTab(tabs, connector, preferred = null) {
  if (!Array.isArray(tabs) || typeof connector !== 'function') return null;
  const ranked = tabs
    .map((tab, index) => ({ tab, index, rank: chatgptTabRank(tab) }))
    .filter(row => Number.isFinite(row.rank))
    .sort((a, b) => (a.rank - b.rank) || (a.index - b.index));
  let fallback = null;
  for (const { tab } of ranked) {
    try {
      const connected = await connector(tab);
      if (!connected) continue;
      if (typeof preferred !== 'function') return connected;
      let accepted = false;
      try { accepted = Boolean(await preferred(connected, tab)); } catch {}
      if (accepted) {
        if (fallback && fallback !== connected) {
          try { fallback.close(); } catch {}
        }
        return connected;
      }
      if (!fallback) fallback = connected;
      else { try { connected.close(); } catch {} }
    } catch {}
  }
  return fallback;
}

async function boundConversationRecoveryReady(cdp) {
  try { if (await conversationUnavailablePresent(cdp)) return false; } catch {}
  try { if (await composerIsUsable(cdp)) return true; } catch {}
  try { if (await conversationIsGenerating(cdp)) return true; } catch {}
  try { if (await recoverableFailureReason(cdp)) return true; } catch {}
  return false;
}

function safeConversationId(value) {
  const id = text(value);
  return /^[A-Za-z0-9_-]{8,160}$/.test(id) ? id : '';
}

function selectBoundConversationTabs(tabs, conversationId) {
  const id = safeConversationId(conversationId);
  if (!id) return [];
  return (Array.isArray(tabs) ? tabs : []).filter(tab => conversationIdFromTab(tab) === id);
}

async function selectBoundConversationReentryTab(
  tabs,
  createNeutral = createNeutralChatgptTab,
) {
  const neutralHomeTabs = (Array.isArray(tabs) ? tabs : [])
    .filter(tab => chatgptTabRank(tab) === 1);
  // Browser recovery is serialized by withBrowserRecoveryLock(), so an
  // existing neutral Home target can be reused safely. Creating another tab
  // whenever more than one Home target already exists causes an unbounded
  // tab/process leak: once two Home tabs exist, every recovery cycle adds one.
  if (neutralHomeTabs.length >= 1) return neutralHomeTabs[0];
  return await createNeutral();
}

function conversationIdFromTab(tab) {
  if (chatgptTabRank(tab) !== 0) return '';
  try {
    return new URL(String(tab.url || '')).pathname.match(/^\/(?:c|uc)\/([^/]+)/)?.[1] || '';
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

function hasActiveContinuityCheckpoint(taskStateDir = TASK_STATE_DIR) {
  try {
    for (const entry of fs.readdirSync(taskStateDir, { withFileTypes: true })) {
      if (!entry.isFile() || !entry.name.endsWith('.json') || entry.name.startsWith('_')) continue;
      try {
        const payload = JSON.parse(fs.readFileSync(taskStateDir + '/' + entry.name, 'utf8'));
        // The reinforcement loop belongs only to the legacy/personal browser.
        if (Object.hasOwn(payload || {}, 'browser_session') && payload.browser_session !== 'fred') continue;
        const status = text(payload?.status).toUpperCase();
        if (status === 'RUNNING' || status === 'READY_TO_COMPLETE') return true;
      } catch {
        // Ignore one malformed/unreadable checkpoint; another valid active task
        // must still be able to enable continuity.
      }
    }
  } catch {}
  return false;
}

function browserSessionReadyForReinforcement(
  taskStateDir = TASK_STATE_DIR,
  nowMs = Date.now(),
  maxAgeMs = BROWSER_HEALTH_MAX_AGE_MS,
) {
  try {
    const payload = JSON.parse(fs.readFileSync(taskStateDir + '/_chatgpt-browser-health.json', 'utf8'));
    if (!payload || typeof payload !== 'object') return false;
    const updatedAtMs = Date.parse(text(payload.updated_at));
    if (!Number.isFinite(updatedAtMs) || updatedAtMs <= 0) return false;
    const ageMs = Math.max(0, Number(nowMs) - updatedAtMs);
    if (!Number.isFinite(ageMs) || ageMs > Math.max(30_000, Number(maxAgeMs || BROWSER_HEALTH_MAX_AGE_MS))) return false;
    return payload.authenticated === true && text(payload.session_state).toUpperCase() === 'AUTHENTICATED';
  } catch {
    return false;
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
  if (conversationIds.size <= 1 && Number(targetUpdatedAtMs || 0) <= 0) return sourceTabs;

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
  constructor(ws, { commandTimeoutMs = 15_000 } = {}) {
    this.ws = ws;
    this.id = 0;
    this.pending = new Map();
    this.closed = false;
    this.commandTimeoutMs = Number.isFinite(commandTimeoutMs) && commandTimeoutMs > 0
      ? commandTimeoutMs : 15_000;
    const disconnected = () => {
      this.closed = true;
      for (const waiter of this.pending.values()) {
        clearTimeout(waiter.timer);
        waiter.reject(new Error('CDP connection closed'));
      }
      this.pending.clear();
    };
    this.disconnect = disconnected;
    ws.addEventListener('close', disconnected);
    ws.addEventListener('error', disconnected);
    ws.addEventListener('message', event => {
      let message;
      try { message = JSON.parse(event.data); } catch { return; }
      if (!message?.id || !this.pending.has(message.id)) return;
      const waiter = this.pending.get(message.id);
      this.pending.delete(message.id);
      clearTimeout(waiter.timer);
      message.error ? waiter.reject(new Error(message.error.message || 'CDP error')) : waiter.resolve(message.result);
    });
  }

  static async connectToChatgptTab({
    allowLatestDisambiguation = false,
    targetUpdatedAtMs = 0,
    targetConversationId = '',
  } = {}) {
    if (!(await cdpReady())) {
      throw new Error(
        `CDP endpoint unreachable at ${browserCdpBase()}. This worker never launches its own browser -- `
        + 'it only attaches to one you already have open and logged into ChatGPT. Launch it with '
        + `--remote-debugging-port=${new URL(browserCdpBase()).port} (see docs/AGENT-VM-PROMPTS.md).`
      );
    }
    const tabs = await (await fetch(`${browserCdpBase()}/json`)).json();
    const conversationIds = new Set(
      (Array.isArray(tabs) ? tabs : []).map(conversationIdFromTab).filter(Boolean),
    );
    let candidateTabs = tabs;
    let preferredCandidate = null;
    const boundConversationId = safeConversationId(targetConversationId);
    const checkpointTargetMs = Number(targetUpdatedAtMs || 0);
    if (boundConversationId) {
      candidateTabs = selectBoundConversationTabs(tabs, boundConversationId);
      if (candidateTabs.length === 0) {
        // Never guess among multiple neutral tabs and never repurpose another
        // real conversation. A single neutral home tab is safe to reuse; when
        // there are zero or multiple neutral homes, create one isolated target
        // exclusively for exact bound-conversation reentry.
        const neutralHomeTab = await selectBoundConversationReentryTab(tabs);
        if (!neutralHomeTab) {
          throw new Error('bound conversation is not available in the attached browser');
        }
        const navigated = await navigateNeutralTabToConversation(
          neutralHomeTab,
          boundConversationId,
          connectCdpTarget,
        );
        if (!navigated) throw new Error('bound conversation could not be opened');
        candidateTabs = [neutralHomeTab];
      }
      if (candidateTabs.length > 1) preferredCandidate = boundConversationRecoveryReady;
    } else if (allowLatestDisambiguation && checkpointTargetMs > 0) {
      // Checkpoint-driven recovery must bind to the intended conversation
      // even when the persistent browser currently has only a neutral home
      // tab. Otherwise "continue" can be typed into a new chat instead of the
      // interrupted thread.
      candidateTabs = await resolveAmbiguousConversationTabs(
        tabs,
        connectCdpTarget,
        latestConversationProbe,
        Date.now(),
        CHECKPOINT_AMBIGUOUS_CONVERSATION_MAX_AGE_MS,
        navigateNeutralTabToConversation,
        checkpointTargetMs,
      );
    } else if (conversationIds.size > 1) {
      if (!allowLatestDisambiguation) throw new Error(AMBIGUOUS_CONVERSATION_ERROR);
      candidateTabs = await resolveAmbiguousConversationTabs(
        tabs,
        connectCdpTarget,
        latestConversationProbe,
        Date.now(),
        CHECKPOINT_AMBIGUOUS_CONVERSATION_MAX_AGE_MS,
        navigateNeutralTabToConversation,
        0,
      );
    }
    const connected = await connectFirstUsableChatgptTab(candidateTabs, connectCdpTarget, preferredCandidate);
    if (!connected) {
      throw new Error('no usable open chatgpt.com tab found in the attached browser');
    }
    return connected;
  }

  send(method, params = {}) {
    if (this.closed) return Promise.reject(new Error('CDP connection closed'));
    return new Promise((resolve, reject) => {
      const id = ++this.id;
      const timer = setTimeout(() => {
        this.pending.delete(id);
        reject(new Error('CDP command timed out'));
      }, this.commandTimeoutMs);
      this.pending.set(id, { resolve, reject, timer });
      try {
        this.ws.send(JSON.stringify({ id, method, params }));
      } catch (error) {
        clearTimeout(timer);
        this.pending.delete(id);
        reject(error);
      }
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
    this.disconnect();
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
    const response = await fetch(`${browserCdpBase()}/json`, { signal: AbortSignal.timeout(3000) });
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
        `CDP endpoint unreachable at ${browserCdpBase()}. This worker only attaches to the canonical authenticated browser.`,
      );
    }
    tabs = await (await fetch(`${browserCdpBase()}/json`)).json();
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
    for(const anchor of document.querySelectorAll('a[href]')){
      const value=String(anchor.getAttribute('href')||'').trim();
      if(!/^\\/(?:c|uc)\\/[A-Za-z0-9_-]{8,160}$/.test(value) || seen.has(value)) continue;
      seen.add(value);
      unique.push(value);
    }
    return unique[0]||'';
  })()`);
  const match = String(href || '').match(/^\/(?:c|uc)\/([A-Za-z0-9_-]{8,160})$/);
  return match?.[1] || '';
}

async function alignToSidebarLatestConversation(cdp) {
  const currentPath = String(await cdp.evaluate('location.pathname') || '');
  const currentConversation = /^\/(?:c|uc)\/[A-Za-z0-9_-]{8,160}$/.test(currentPath);
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
  return /^\/(?:c|uc)\/[A-Za-z0-9_-]{8,160}$/.test(path) ? path : '';
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

    const requestedTimeout = Number(timeoutMs);
    const requestedPoll = Number(pollMs);
    const totalTimeoutMs = Number.isFinite(requestedTimeout)
      ? Math.max(500, requestedTimeout)
      : 12000;
    const intervalMs = Number.isFinite(requestedPoll)
      ? Math.max(50, requestedPoll)
      : 250;
    const startedAt = Date.now();
    const deadline = startedAt + totalTimeoutMs;
    const readBoundId = async () => {
      try {
        const pathname = String(await cdp.evaluate('location.pathname') || '');
        return pathname.match(/^\/(?:c|uc)\/([^/?#]+)/)?.[1] || '';
      } catch {
        return '';
      }
    };

    // A freshly created neutral CDP target can be connected before the
    // authenticated ChatGPT shell/sidebar has hydrated. Prefer the exact
    // existing sidebar route, whether ChatGPT exposes it as /c/<id> or
    // /uc/<id>, before trying direct navigation.
    const sidebarDeadline = Math.min(deadline, startedAt + Math.min(5000, totalTimeoutMs));
    let route = 'waiting';
    while (Date.now() < sidebarDeadline) {
      route = await cdp.evaluate(
        `(()=>{
          /* continuity-bound-sidebar-route */
          const id=${JSON.stringify(id)};
          const link=[...document.querySelectorAll('a[href]')].find(anchor=>{
            try {
              const pathname=new URL(anchor.href,location.href).pathname;
              const match=pathname.match(/^\\/(?:c|uc)\\/([^/?#]+)/);
              return match?.[1]===id;
            } catch { return false; }
          });
          if(!link) return 'waiting';
          const pathname=new URL(link.href,location.href).pathname;
          link.click();
          return pathname;
        })()`,
      );
      if (typeof route === 'string' && /^\/(?:c|uc)\//.test(route)) break;
      if (await readBoundId() === id) return true;
      await sleep(intervalMs);
    }

    if (await readBoundId() === id) return true;

    // Direct /c is the long-standing route. Some current ChatGPT surfaces use
    // /uc instead, so fail over to /uc when /c redirects to Home/unavailable.
    for (const prefix of ['/c/', '/uc/']) {
      if (Date.now() >= deadline) break;
      await cdp.evaluate(
        `(()=>{location.assign(${JSON.stringify(prefix + id)});return true;})()`,
      );
      const routeDeadline = Math.min(deadline, Date.now() + Math.max(750, Math.floor((deadline - Date.now()) / 2)));
      while (Date.now() < routeDeadline) {
        await sleep(intervalMs);
        if (await readBoundId() === id) return true;
      }
    }
    return false;
  } catch {
    return false;
  } finally {
    try { cdp?.close(); } catch {}
  }
}

async function createNeutralChatgptTab(fetcher = fetch) {
  try {
    const response = await fetcher(`${browserCdpBase()}/json/new?https://chatgpt.com/`, { method: 'PUT' });
    if (!response?.ok) return null;
    const tab = await response.json();
    return chatgptTabRank(tab) === 1 ? tab : null;
  } catch {
    return null;
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

async function conversationRespondingIndicatorPresent(cdp) {
  return currentConversationSurfaceContains(
    cdp,
    [
      'chatgpt is responding',
      'chatgpt está respondendo',
      'chatgpt esta respondendo',
    ],
    'continuity-responding-indicator-probe',
  );
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
        const match=location.pathname.match(/^\\/(?:c|uc)\\/([^/?#]+)/);
        if(!match) return {http_status:0,status:'NO_CONVERSATION'};
        const deadline=Date.now()+${boundedTimeoutMs};
        let accountId='';
        let accessToken='';
        try{
          const remaining=Math.max(1,deadline-Date.now());
          const sessionResponse=await fetch('/api/auth/session',{
            credentials:'same-origin',
            cache:'no-store',
            signal:AbortSignal.timeout(remaining)
          });
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
        const timer=setTimeout(()=>controller.abort(), Math.max(1,deadline-Date.now()));
        try{
          const response=await fetch(
            '/backend-api/conversation/'+encodeURIComponent(match[1])+'/stream_status',
            {credentials:'same-origin',cache:'no-store',headers,signal:controller.signal}
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

async function conversationTurnState(cdp, timeoutMs = CONVERSATION_TURN_TIMEOUT_MS) {
  const requestedTimeout = Number(timeoutMs);
  const boundedTimeoutMs = Math.min(
    12_000,
    Number.isFinite(requestedTimeout) ? Math.max(10, requestedTimeout) : CONVERSATION_TURN_TIMEOUT_MS,
  );
  let outerTimeoutHandle;
  try {
    return await Promise.race([
      cdp.evaluate(`(async()=>{
        /* conversation-turn-state */
        const deadline = Date.now() + ${boundedTimeoutMs};
        const match=location.pathname.match(/^\\/(?:c|uc)\\/([^/?#]+)/);
        if(!match) {
          return {
            http_status:0,
            node_id:'',
            role:'',
            end_turn:null,
            child_count:-1,
            content_text_length:0,
            message_status:'NO_CONVERSATION'
          };
        }
        let accountId='';
        let accessToken='';
        try{
          const sessionResponse=await fetch('/api/auth/session',{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(${Math.min(LATEST_CONVERSATION_FETCH_TIMEOUT_MS, boundedTimeoutMs)})});
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
        const timer=setTimeout(()=>controller.abort(), Math.max(1, deadline - Date.now()));
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
              node_id:'',
              role:'',
              end_turn:null,
              child_count:-1,
              content_text_length:0,
              message_status:'HTTP_ERROR'
            };
          }
          const current=String(body?.current_node||'');
          const node=current && body?.mapping ? body.mapping[current] : null;
          const message=node?.message||null;
          const endTurn=message?.end_turn;
          const parts=Array.isArray(message?.content?.parts)?message.content.parts:[];
          const contentTextLength=parts.reduce((total,part)=>{
            if(typeof part==='string') return total+part.trim().length;
            if(part&&typeof part==='object'){
              const value=String(part?.text||part?.content||'').trim();
              return total+value.length;
            }
            return total;
          },0);
          return {
            http_status:Number(response.status||0),
            node_id:current,
            role:String(message?.author?.role||''),
            end_turn:endTurn===true?true:(endTurn===false?false:null),
            child_count:Array.isArray(node?.children)?node.children.length:-1,
            content_text_length:contentTextLength,
            message_status:String(message?.status||'')
          };
        }catch(error){
          return {
            http_status:0,
            node_id:'',
            role:'',
            end_turn:null,
            child_count:-1,
            content_text_length:0,
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
            node_id: '',
            role: '',
            end_turn: null,
            child_count: -1,
            content_text_length: 0,
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

function realAssistantResponseCompletedSince(before, after) {
  if (Number(after?.http_status || 0) !== 200) return false;
  if (String(after?.role || '').toLowerCase() !== 'assistant') return false;
  if (after?.end_turn !== true) return false;
  if (Number(after?.content_text_length || 0) <= 0) return false;
  const priorNode = String(before?.node_id || '');
  const currentNode = String(after?.node_id || '');
  if (!priorNode || !currentNode) return false;
  if (currentNode !== priorNode) return true;
  return before?.end_turn === false;
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
  const snapshot = await cdp.evaluate(`(()=>{
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

    // Capture the current conversation surface for diagnostics only. Generic
    // surface growth is never sufficient to certify recovery because it can
    // reflect tool activity, Thinking UI, banners, or our own continuation.
    const main=document.querySelector('main');
    const surfaceText=(main?.innerText||main?.textContent||'').trim();
    return {
      count:nodes.length,
      lastText,
      lastLength:lastText.length,
      lastKey,
      surfaceText,
      surfaceLength:surfaceText.length,
      conversationPath:String(globalThis.location?.pathname||''),
      snapshotSource:legacyNodes.length?'legacy':(actionNodes.length?'action-controls':'main')
    };
  })()`);
  if (snapshot && Object.hasOwn(snapshot, 'conversationPath')) {
    const { conversationPath, ...content } = snapshot;
    const path = String(conversationPath || '').match(/^\/(?:c|uc)\/[^/]+/)?.[0] || '';
    return { ...content, conversationFingerprint: path ? sha(path) : '' };
  }
  // Older injected adapters may lack route metadata; live snapshots always
  // include it, and an empty route cannot certify conversation progress.
  return snapshot;
}

function sameConversationSnapshot(before, after) {
  const priorBound = Boolean(before && Object.hasOwn(before, 'conversationFingerprint'));
  const currentBound = Boolean(after && Object.hasOwn(after, 'conversationFingerprint'));
  if (!priorBound && !currentBound) return true;
  return priorBound && currentBound
    && Boolean(before.conversationFingerprint)
    && before.conversationFingerprint === after.conversationFingerprint;
}

function assistantProgressed(before, after) {
  if (!sameConversationSnapshot(before, after)) return false;
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
  turnBaseline = null,
) {
  const canonicalBaseline = turnBaseline || await conversationTurnState(cdp);
  const settled = await postSendConfirmationBaseline(cdp, beforeSend);
  if (!sameConversationSnapshot(beforeSend, settled.baseline)) return false;
  return confirmProgress(
    cdp,
    settled.baseline,
    PROGRESS_CONFIRM_MS,
    PROGRESS_POLL_MS,
    canonicalBaseline,
  );
}

async function confirmAssistantProgress(
  cdp,
  baseline,
  timeoutMs = PROGRESS_CONFIRM_MS,
  pollMs = PROGRESS_POLL_MS,
  turnBaseline = null,
) {
  const canonicalBaseline = turnBaseline || await conversationTurnState(cdp);
  if (!String(canonicalBaseline?.node_id || '')) return false;
  const deadline = Date.now() + Math.max(1000, Number(timeoutMs || PROGRESS_CONFIRM_MS));
  while (Date.now() < deadline) {
    await sleep(Math.max(250, Number(pollMs || PROGRESS_POLL_MS)));
    const current = await assistantSnapshot(cdp);
    if (!sameConversationSnapshot(baseline, current)) return false;

    // UI growth is diagnostic only. Re-reading the full conversation while a
    // response is still streaming can issue dozens of expensive history reads
    // during a single 90s confirmation window and drive the account into 429.
    // Poll the lightweight account-scoped stream endpoint instead.
    if (await transmissionErrorPresent(cdp)) return false;
    const stream = await conversationStreamStatus(cdp);
    if (stream?.http_status === 200 && stream?.status === 'COMPLETE') {
      // The user's requirement is a real response, not bridge activity: take
      // exactly one canonical history read after transport completion and
      // require a completed assistant turn with content.
      const turn = await conversationTurnState(cdp);
      return realAssistantResponseCompletedSince(canonicalBaseline, turn);
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
  const currentMatch = currentPath.match(/^\/(?:c|uc)\/([^/?#]+)/);
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
      'nossos sistemas estão fazendo verificações adicionais',
      'nossos sistemas estao fazendo verificacoes adicionais',
      'verificações adicionais antes de responder',
      'verificacoes adicionais antes de responder',
      'tentar novamente com um modelo mais rápido',
      'tentar novamente com um modelo mais rapido',
      'our systems are performing additional checks',
      'our systems are doing additional checks',
      'additional checks before responding',
      'try again with a faster model',
      'try again using a faster model',
      'esgotou-se o tempo limite da solicitação',
      'esgotou-se o tempo limite da solicitacao',
      'request timed out',
      'request timeout',
    ],
    'continuity-error-banner-probe',
  );
}

async function conversationUnavailablePresent(cdp) {
  return Boolean(await cdp.evaluate(`(()=>{
    /* continuity-conversation-unavailable-probe */
    const path=String(location.pathname||'');
    const parts=path.split('/');
    if(parts.length!==3||!['c','uc'].includes(parts[1])||!/^[A-Za-z0-9_-]{8,160}$/.test(parts[2])) return false;
    const text=String(document.body?.innerText||'').toLowerCase();
    return [
      'could not load this chatgpt conversation',
      'unable to load this chatgpt conversation',
      'não foi possível carregar esta conversa',
      'nao foi possivel carregar esta conversa',
    ].some(value=>text.includes(value));
  })()`));
}

async function recoverableFailureReason(cdp) {
  if (!(await errorBannerPresent(cdp))) return '';

  if (await currentConversationSurfaceContains(
    cdp,
    [
      'nossos sistemas estão fazendo verificações adicionais',
      'nossos sistemas estao fazendo verificacoes adicionais',
      'verificações adicionais antes de responder',
      'verificacoes adicionais antes de responder',
      'tentar novamente com um modelo mais rápido',
      'tentar novamente com um modelo mais rapido',
      'our systems are performing additional checks',
      'our systems are doing additional checks',
      'additional checks before responding',
      'try again with a faster model',
      'try again using a faster model',
    ],
    'continuity-additional-checks-probe',
  )) return 'additional_checks';

  if (await currentConversationSurfaceContains(
    cdp,
    ['stopped thinking', 'parou de pensar'],
    'continuity-stopped-thinking-probe',
  )) return 'stopped_thinking';

  if (await currentConversationSurfaceContains(
    cdp,
    ['streaming interrupted', 'transmissão interrompida', 'transmissao interrompida'],
    'continuity-streaming-interrupted-probe',
  )) return 'streaming_interrupted';

  if (await currentConversationSurfaceContains(
    cdp,
    [
      'esgotou-se o tempo limite da solicitação',
      'esgotou-se o tempo limite da solicitacao',
      'request timed out',
      'request timeout',
    ],
    'continuity-request-timeout-probe',
  )) return 'request_timeout';

  return 'generation_error';
}

async function conversationMatchesFingerprint(cdp, expectedFingerprint) {
  if (!expectedFingerprint) return true;
  const path = await cdp.evaluate(`(()=>{
    /* continuity-conversation-identity-probe */
    return String(location.pathname||'').match(/^\\/(?:c|uc)\\/[^/]+/)?.[0]||'';
  })()`);
  return Boolean(path) && sha(path) === expectedFingerprint;
}

async function clickRecoverableRetryButton(cdp, expectedFingerprint = '') {
  const target = await cdp.evaluate(`(()=>{
    /* continuity-retry-button-target */
    const normalize=value=>String(value||'')
      .normalize('NFD').replace(/[\\u0300-\\u036f]/g,'')
      .replace(/\\s+/g,' ').trim().toLowerCase();
    const accepted=new Set(['retry','repetir','tentar novamente']);
    const candidates=[];
    for(const button of document.querySelectorAll('button')){
      if(button.disabled||button.getAttribute('aria-disabled')==='true') continue;
      const label=normalize(button.getAttribute('aria-label')||button.innerText||button.textContent||'');
      if(!accepted.has(label)) continue;
      const rect=button.getBoundingClientRect();
      if(!(rect.width>0&&rect.height>0)) continue;
      const style=getComputedStyle(button);
      if(style.visibility==='hidden'||style.display==='none'||Number(style.opacity||1)===0) continue;
      candidates.push({x:rect.left+rect.width/2,y:rect.top+rect.height/2});
    }
    return candidates.length===1 ? candidates[0] : null;
  })()`);
  if (!target) return false;
  if (!(await conversationMatchesFingerprint(cdp, expectedFingerprint))) return false;

  if (typeof cdp?.send === 'function') {
    try {
      const x=Number(target.x);
      const y=Number(target.y);
      if(!Number.isFinite(x)||!Number.isFinite(y)) return false;
      await cdp.send('Input.dispatchMouseEvent',{type:'mouseMoved',x,y,button:'none'});
      await cdp.send('Input.dispatchMouseEvent',{type:'mousePressed',x,y,button:'left',clickCount:1});
      await cdp.send('Input.dispatchMouseEvent',{type:'mouseReleased',x,y,button:'left',clickCount:1});
      return true;
    } catch {
      return false;
    }
  }

  // Legacy/mock compatibility only. Production Cdp exposes send() and uses
  // trusted pointer events above.
  return Boolean(await cdp.evaluate(`(()=>{
    /* continuity-retry-button-click-legacy */
    const normalize=value=>String(value||'')
      .normalize('NFD').replace(/[\\u0300-\\u036f]/g,'')
      .replace(/\\s+/g,' ').trim().toLowerCase();
    const accepted=new Set(['retry','repetir','tentar novamente']);
    const candidates=[...document.querySelectorAll('button')].filter(button=>{
      if(button.disabled||button.getAttribute('aria-disabled')==='true') return false;
      const label=normalize(button.getAttribute('aria-label')||button.innerText||button.textContent||'');
      return accepted.has(label);
    });
    if(candidates.length!==1) return false;
    candidates[0].click();
    return true;
  })()`));
}

async function clickTrustedSendButton(cdp, expectedFingerprint = '') {
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
      if (!(await conversationMatchesFingerprint(cdp, expectedFingerprint))) return false;
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

async function sendContinueMessage(cdp, expectedFingerprint = '') {
  if (!(await conversationMatchesFingerprint(cdp, expectedFingerprint))) return false;
  if (await recoverableFailureReason(cdp) === 'additional_checks') return false;
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

    let focused = await cdp.evaluate(`(()=>{
      /* continuity-composer-focus */
      const el=document.querySelector('[data-testid="prompt-textarea"]')
        || document.querySelector('[role="textbox"][contenteditable="true"]');
      if(!el) return false;
      return document.activeElement===el || el.contains(document.activeElement);
    })()`);
    if (!focused) {
      // Current ChatGPT can accept the trusted pointer event while leaving
      // activeElement on BODY after a reload/reattach. We already proved the
      // exact conversation fingerprint and refused to overwrite any real
      // draft above, so a single bounded DOM focus repair is safe here. The
      // trusted pointer event remains the user-gesture boundary; focus() only
      // repairs the editor selection target before keyboard input.
      focused = await cdp.evaluate(`(()=>{
        /* continuity-composer-focus-repair */
        const el=document.querySelector('[data-testid="prompt-textarea"]')
          || document.querySelector('[role="textbox"][contenteditable="true"]');
        if(!el) return false;
        try{ el.focus({preventScroll:true}); }catch{ try{el.focus();}catch{} }
        return document.activeElement===el || el.contains(document.activeElement);
      })()`);
    }
    if (!focused) {
      // If a previous safe attempt left exactly our continuation draft and
      // Send is enabled, submit it without touching any other draft.
      if (existing === expected && await clickTrustedSendButton(cdp, expectedFingerprint)) return true;
      if (!existing) {
        // Some ProseMirror builds keep document.activeElement on BODY even
        // after the trusted pointer click and the bounded focus repair. Never
        // overwrite a real draft: only the already-proven empty composer may
        // receive one trusted CDP text insertion, then read it back exactly
        // before resolving and clicking Send.
        try {
          await cdp.send('Input.insertText', { text: expected });
        } catch {
          return false;
        }
        await sleep(120);
        const insertedDraft = await cdp.evaluate(`(()=>{
          /* continuity-composer-draft-after-trusted-insert */
          const el=document.querySelector('[data-testid="prompt-textarea"]')
            || document.querySelector('[role="textbox"][contenteditable="true"]');
          return el ? String(el.innerText||el.textContent||'').trim() : '';
        })()`);
        if (String(insertedDraft || '').trim() !== expected) return false;
        if (await clickTrustedSendButton(cdp, expectedFingerprint)) return true;
        if (!(await conversationMatchesFingerprint(cdp, expectedFingerprint))) return false;
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
    if (await clickTrustedSendButton(cdp, expectedFingerprint)) return true;

    if (!(await conversationMatchesFingerprint(cdp, expectedFingerprint))) return false;
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

// Returns only a boolean: no credentials, cookies or session payload leave
// the authenticated tab. A changed path is rejected before touching the UI.
async function boundBrowserAccountMatches(cdp, session) {
  return (await cdp.evaluate(`(async()=>{
    /* continuity-browser-account-match */
    const id=String(location.pathname||'').match(/^\\/(?:c|uc)\\/([^/?#]+)/)?.[1]||'';
    if(id!==${JSON.stringify(session.conversationId)}) return false;
    try{
      const r=await fetch('/api/auth/session',{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(2000)});
      if(!r.ok) return false;
      const d=await r.json();
      return String(d?.user?.email||'').toLowerCase()===${JSON.stringify(session.expectedEmail)};
    }catch{return false;}
  })()`)) === true;
}

async function attemptNudge(taskId, connect = null, confirmProgress = confirmAssistantProgress, waitComposer = waitForComposerUsable, conversationId = '') {
  let session;
  try { session = taskBrowserSession(taskId, conversationId); }
  catch (error) { return { result_status: 'ERROR', sent: false, detail: text(error?.message) }; }
  return BROWSER_SESSION_CONTEXT.run(session, () => attemptNudgeInSession(
    taskId, connect, confirmProgress, waitComposer, session.conversationId || conversationId,
  ));
}

async function attemptNudgeInSession(
  taskId,
  connect = null,
  confirmProgress = confirmAssistantProgress,
  waitComposer = waitForComposerUsable,
  conversationId = '',
) {
  const recoveryStartedAtMs = Date.now();
  let detectedFailureReason = '';
  let continuationSent = false;
  let resolvedConversationId = safeConversationId(conversationId);
  const recoveryMetadata = () => ({
    sent: continuationSent,
    ...(resolvedConversationId ? { conversation_id: resolvedConversationId } : {}),
    ...(detectedFailureReason
    ? {
        failure_class: 'RECOVERABLE_CHAT_FAILURE',
        failure_reason: detectedFailureReason,
        recovery_attempt: 1,
        recovery_latency_ms: Math.max(0, Date.now() - recoveryStartedAtMs),
      }
    : {})});
  let cdp;
  try {
    const connector = connect || (() => Cdp.connectToChatgptTab({
      allowLatestDisambiguation: true,
      targetUpdatedAtMs: conversationId ? 0 : checkpointUpdatedAtMs(taskId),
      targetConversationId: conversationId,
    }));
    cdp = await connector();
    const browserSession = BROWSER_SESSION_CONTEXT.getStore();
    if (browserSession?.expectedEmail) {
      if (!(await boundBrowserAccountMatches(cdp, browserSession))) {
        return { result_status: 'ERROR', detail: 'browser session account mismatch or bound conversation mismatch', ...recoveryMetadata() };
      }
      // A missing DOM Stop does not prove an inactive server stream.
      const stream = await conversationStreamStatus(cdp);
      if (stream?.http_status !== 200 || String(stream?.status || '').toUpperCase() !== 'COMPLETE') {
        return { result_status: 'STALLED_NOT_CONFIRMED', detail: 'bound browser session stream active or unconfirmed; deferred without reload or continuation', ...recoveryMetadata() };
      }
    }
    try {
      const pathname = String(await cdp.evaluate('location.pathname') || '');
      const actualConversationId = safeConversationId(pathname.match(/^\/(?:c|uc)\/([^/?#]+)/)?.[1] || '');
      if (actualConversationId) resolvedConversationId = actualConversationId;
    } catch {}
    if (await conversationUnavailablePresent(cdp)) {
      return {
        result_status: 'CONVERSATION_NOT_FOUND',
        detail: 'bound conversation surface is unavailable; deferred to detached recovery without sending continuation',
        ...recoveryMetadata(),
      };
    }
    detectedFailureReason = await recoverableFailureReason(cdp);
    if (detectedFailureReason === 'additional_checks') {
      return {
        result_status: 'STALLED_NOT_CONFIRMED',
        detail: 'additional checks active; deferred without reload or continuation',
        ...recoveryMetadata(),
      };
    }
    let recoveredStaleComplete = false;
    let recoveredTerminalFailure = false;

    // A real 2026-09-30 silent-stall capture proved that ChatGPT can expose no
    // Stop button while the canonical current_node still ends in an assistant
    // tool/thought branch with end_turn=false. Passive reattach remains the
    // bounded recovery for that state, for explicit recoverable failures, and
    // for stale UI whose backend stream is already COMPLETE. A healthy active
    // stream is never reloaded solely because the checkpoint age crossed the
    // watchdog threshold.
    const wasGenerating = await conversationIsGenerating(cdp);
    const respondingIndicator = !wasGenerating && !detectedFailureReason
      ? await conversationRespondingIndicatorPresent(cdp)
      : false;
    if ((wasGenerating || respondingIndicator) && !detectedFailureReason) {
      const liveStream = await conversationStreamStatus(cdp);
      const liveStatus = String(liveStream?.status || '').toUpperCase();
      const streamComplete = Number(liveStream?.http_status || 0) === 200 && liveStatus === 'COMPLETE';
      if (!streamComplete) {
        return {
          result_status: 'STALLED_NOT_CONFIRMED',
          detail: ['IS_STREAMING', 'IN_PROGRESS', 'STREAMING'].includes(liveStatus)
            ? 'active generation is still in progress; deferred without reload or continuation'
            : 'generation is active and stream completion is unconfirmed; deferred without reload or continuation',
          ...recoveryMetadata(),
        };
      }
    }
    // A passive reload is only allowed to certify recovery when there was a
    // real pre-existing signal to recover. An idle conversation can hydrate
    // extra DOM after reload; treating that surface growth as assistant
    // progress creates a false-green without ever sending the checkpoint
    // continuation.
    const silentStallBeforeReattach = !wasGenerating
      && !detectedFailureReason
      && await silentStallPresent(cdp);
    const passiveRecoveryEligible = wasGenerating
      || Boolean(detectedFailureReason)
      || silentStallBeforeReattach;
    const passiveTurnBaseline = await conversationTurnState(cdp);
    const passiveBaseline = await assistantSnapshot(cdp);
    await cdp.evaluate(`(()=>{location.reload();return true})()`);
    await sleep(1200);
    const passiveProgressed = await confirmProgress(
      cdp,
      passiveBaseline,
      PASSIVE_REATTACH_CONFIRM_MS,
      PROGRESS_POLL_MS,
      passiveTurnBaseline,
    );
    if (passiveProgressed && passiveRecoveryEligible) {
      return {
        result_status: 'PROGRESS_CONFIRMED',
        detail: 'passive reattach restored assistant progress without sending continuation',
        ...recoveryMetadata(),
      };
    }

    const postReattachFailureReason = await recoverableFailureReason(cdp);
    if (postReattachFailureReason) detectedFailureReason = postReattachFailureReason;

    // "Stopped thinking" exposes a native Retry action in the current ChatGPT
    // UI. Prefer that platform-native retry once before writing a new
    // continuation message. This preserves the exact conversation and avoids
    // accumulating duplicate "continue" turns when generation itself failed.
    if (detectedFailureReason === 'stopped_thinking') {
      const retryBaseline = await assistantSnapshot(cdp);
      const retryTurnBaseline = await conversationTurnState(cdp);
      const retryClicked = await clickRecoverableRetryButton(
        cdp,
        retryBaseline?.conversationFingerprint,
      );
      if (retryClicked) {
        const retryProgressed = await confirmProgress(
          cdp,
          retryBaseline,
          PROGRESS_CONFIRM_MS,
          PROGRESS_POLL_MS,
          retryTurnBaseline,
        );
        if (retryProgressed) {
          return {
            result_status: 'PROGRESS_CONFIRMED',
            detail: 'native Retry restored assistant progress without sending continuation',
            ...recoveryMetadata(),
          };
        }
        detectedFailureReason = await recoverableFailureReason(cdp) || detectedFailureReason;
      }
    }

    if (detectedFailureReason === 'additional_checks') {
      return {
        result_status: 'STALLED_NOT_CONFIRMED',
        detail: 'additional checks appeared after reattach; deferred without stop or continuation',
        ...recoveryMetadata(),
      };
    }

    const generatingAfterReattach = await conversationIsGenerating(cdp);
    if (wasGenerating || generatingAfterReattach) {
      // Re-read server bookkeeping only after the passive recovery window.
      // Anything other than a confirmed COMPLETE remains potentially active
      // and must not receive a duplicate continuation.
      const stream = await conversationStreamStatus(cdp);
      const streamComplete = stream?.http_status === 200 && stream?.status === 'COMPLETE';
      if (!streamComplete && !detectedFailureReason) {
        return {
          result_status: 'STALLED_NOT_CONFIRMED',
          detail: 'passive reattach observed no assistant progress; active stream remains unconfirmed',
          ...recoveryMetadata(),
        };
      }

      // A persisted terminal/degraded failure is stronger evidence than a
      // stale Stop control or server-side IN_PROGRESS bookkeeping. The
      // reinforcement path already gives ChatGPT its own retry grace window;
      // after no progress, clear only that stale UI control and resume the
      // checkpoint on the same model.
      if (generatingAfterReattach) {
        if (!(await clearStaleCompleteGeneration(cdp))) {
          return {
            result_status: 'STALLED_NOT_CONFIRMED',
            detail: detectedFailureReason
              ? 'recoverable failure detected but stale Stop state did not clear'
              : 'stale COMPLETE stream detected but Stop state did not clear',
            ...recoveryMetadata(),
          };
        }
      }
      recoveredStaleComplete = streamComplete;
      recoveredTerminalFailure = !streamComplete && Boolean(detectedFailureReason);
    }
    if (!(await waitComposer(cdp))) {
      return {
        result_status: 'CONVERSATION_NOT_FOUND',
        detail: 'composer/send-button selector not found after bounded post-reattach wait (possible UI drift)',
        ...recoveryMetadata(),
      };
    }
    let baseline = await assistantSnapshot(cdp);
    if (!sameConversationSnapshot(passiveBaseline, baseline)) throw new Error('conversation changed during recovery');
    let turnBaseline = await conversationTurnState(cdp);
    let sent = await sendContinueMessage(cdp, passiveBaseline?.conversationFingerprint);
    continuationSent = Boolean(sent);
    if (!sent) {
      // Live production evidence 2026-10-01: the error banner can be visible
      // while the composer remains temporarily disabled. Reattach once before
      // declaring send failure; otherwise the watchdog loses the conversation
      // exactly when "Parou de pensar" is displayed.
      await cdp.evaluate(`(()=>{location.reload();return true})()`);
      await sleep(1200);
      if (!(await waitComposer(cdp))) {
        return {
          result_status: 'ERROR',
          detail: 'composer/send-button remained unavailable after bounded reattach',
          ...recoveryMetadata(),
        };
      }
      baseline = await assistantSnapshot(cdp);
      if (!sameConversationSnapshot(passiveBaseline, baseline)) throw new Error('conversation changed during recovery');
      turnBaseline = await conversationTurnState(cdp);
      sent = await sendContinueMessage(cdp, passiveBaseline?.conversationFingerprint);
      continuationSent = continuationSent || Boolean(sent);
      if (!sent) {
        return {
          result_status: 'ERROR',
          detail: 'composer found but send failed after bounded reattach',
          ...recoveryMetadata(),
        };
      }
    }

    let progressed = await confirmAfterSend(cdp, baseline, confirmProgress, turnBaseline);
    if (!progressed && await transmissionErrorPresent(cdp)) {
      // A real iOS capture shows an explicit "Erro na transmissão de mensagem".
      // Treat this as a transport failure, not as an ambiguous unconfirmed send:
      // reload/reattach once and retry only after the UI still reports the error.
      const retryTurnBaseline = await conversationTurnState(cdp);
      const retryBaseline = await assistantSnapshot(cdp);
      await cdp.evaluate(`(()=>{location.reload();return true})()`);
      await sleep(1200);
      if (await confirmProgress(cdp, retryBaseline, PASSIVE_REATTACH_CONFIRM_MS, PROGRESS_POLL_MS, retryTurnBaseline)) {
        return {
          result_status: 'PROGRESS_CONFIRMED',
          detail: 'transmission error recovered during passive reattach without duplicate continuation',
          ...recoveryMetadata(),
        };
      }
      if (!(await waitComposer(cdp))) {
        return {
          result_status: 'ERROR',
          detail: 'transmission error persisted and composer was unavailable after reattach',
          ...recoveryMetadata(),
        };
      }
      const retryAfterReattachBaseline = await assistantSnapshot(cdp);
      if (!sameConversationSnapshot(passiveBaseline, retryAfterReattachBaseline)) throw new Error('conversation changed during recovery');
      const retryAfterReattachTurnBaseline = await conversationTurnState(cdp);
      const retrySent = await sendContinueMessage(cdp, passiveBaseline?.conversationFingerprint);
      if (!retrySent) {
        return {
          result_status: 'ERROR',
          detail: 'transmission error persisted and retry send failed',
          ...recoveryMetadata(),
        };
      }
      progressed = await confirmAfterSend(cdp, retryAfterReattachBaseline, confirmProgress, retryAfterReattachTurnBaseline);
      if (progressed) {
        return {
          result_status: 'PROGRESS_CONFIRMED',
          detail: 'transmission error recovered by one bounded reattach and retry',
          ...recoveryMetadata(),
        };
      }
      if (await transmissionErrorPresent(cdp)) {
        return {
          result_status: 'ERROR',
          detail: 'transmission error persisted after bounded recovery retry',
          ...recoveryMetadata(),
        };
      }
    }
    if (!progressed) {
      return {
        result_status: 'SENT_UNCONFIRMED',
        detail: recoveredStaleComplete
          ? 'recovered stale COMPLETE stream and sent continuation, but no assistant progress was observed'
          : recoveredTerminalFailure
            ? 'recovered terminal generation state and sent continuation, but no assistant progress was observed'
            : 'sent continuation, but no assistant progress was observed',
        ...recoveryMetadata(),
      };
    }
    return {
      result_status: 'PROGRESS_CONFIRMED',
      detail: recoveredStaleComplete
        ? 'recovered stale COMPLETE stream; continuation produced assistant progress'
        : recoveredTerminalFailure
          ? 'recovered terminal generation state; continuation produced assistant progress'
          : 'continuation produced assistant progress',
      ...recoveryMetadata(),
    };
  } catch (error) {
    return { result_status: 'ERROR', detail: text(error?.message).slice(0, 400), ...recoveryMetadata() };
  } finally {
    cdp?.close();
  }
}

function bridgeResultPayload(taskId, outcome, persistedDetail) {
  const payload = { task_id: taskId, ...outcome, detail: persistedDetail };
  if (outcome?.result_status !== 'PROGRESS_CONFIRMED') delete payload.conversation_id;
  return payload;
}

async function pollBridgeOnce() {
  const response = await bridge('pull');
  if (response.status !== 'JOB') return;
  const taskId = response.nudge?.task_id;
  if (!taskId) return;
  const conversationId = safeConversationId(response.nudge?.conversation_id || '');
  const outcome = await withBrowserRecoveryLock(() => attemptNudge(
    taskId,
    null,
    confirmAssistantProgress,
    waitForComposerUsable,
    conversationId,
  ));
  const failureReason = text(outcome.failure_reason);
  const persistedDetail = failureReason
    ? `failure_class=RECOVERABLE_CHAT_FAILURE;failure_reason=${failureReason};recovery_attempt=${Number(outcome.recovery_attempt || 1)};recovery_latency_ms=${Math.max(0, Number(outcome.recovery_latency_ms || 0))}; ${text(outcome.detail).slice(0, 360)}`
    : outcome.detail;
  // A bound conversation is authoritative only after observable assistant
  // progress. The PHP bridge deliberately rejects conversation_id on any
  // non-confirmed result so an ERROR/SENT_UNCONFIRMED attempt cannot poison
  // the durable binding. Keep those outcomes retryable by omitting the id.
  await bridge('result', bridgeResultPayload(taskId, outcome, persistedDetail));
  console.log(
    `chatgpt_continuity_nudge task_id=${taskId} result=${outcome.result_status} detail_code=${outcomeStatusDetailCode(outcome.result_status, persistedDetail)} failure_class=${failureReason ? 'RECOVERABLE_CHAT_FAILURE' : 'none'} failure_reason=${failureReason || 'none'} recovery_attempt=${Number(outcome.recovery_attempt || 0)} recovery_latency_ms=${Math.max(0, Number(outcome.recovery_latency_ms || 0))}`,
  );
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
  let failureReason = '';
  let failureBaseline = null;
  let failureTurnBaseline = null;
  const recoveryStartedAtMs = Date.now();
  try {
    cdp = await connect();

    // Cheap, local signal first. Do not hit the account-scoped conversation
    // listing when the currently open conversation already exposes a failure.
    let bannerPresent = await errorBannerPresent(cdp);
    if (bannerPresent) {
      failureSignal = 'banner';
      failureReason = await recoverableFailureReason(cdp) || 'generation_error';
      failureBaseline = await assistantSnapshot(cdp);
      failureTurnBaseline = await conversationTurnState(cdp);
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
        failureReason = await recoverableFailureReason(cdp) || 'generation_error';
        failureBaseline = await assistantSnapshot(cdp);
        failureTurnBaseline = await conversationTurnState(cdp);
      } else if (await silentStallPresent(cdp)) {
        // The iOS client can show "Transmissão interrompida" while the same
        // latest conversation has no banner in the canonical VM. Only the
        // canonical unfinished-turn shape below is accepted as equivalent.
        failureSignal = 'silent_stall';
        failureReason = 'silent_stall';
        failureBaseline = await assistantSnapshot(cdp);
      } else {
        return {
          action: 'no_banner',
          http_status: alignmentHttpStatus,
          cross_device_discovery: true,
        };
      }
    }

    if (failureReason === 'additional_checks') {
      return {
        action: 'additional_checks_cooldown',
        sent: false,
        progress_confirmed: false,
        failure_class: 'RECOVERABLE_CHAT_FAILURE',
        failure_reason: failureReason,
        recovery_attempt: 0,
        recovery_latency_ms: Math.max(0, Date.now() - recoveryStartedAtMs),
        http_status: alignmentHttpStatus,
        cross_device_discovery: crossDeviceDiscovery,
      };
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
      const selfRecoveryProgressed = await confirmProgress(
        cdp,
        failureBaseline,
        PASSIVE_REATTACH_CONFIRM_MS,
        PROGRESS_POLL_MS,
        failureTurnBaseline,
      );
      if (selfRecoveryProgressed) {
        console.log(
          `chatgpt_continuity_reinforcement ${failureSignal || 'failure'}_self_resolved progress_confirmed=true failure_reason=${failureReason || 'unknown'}`,
        );
        return {
          action: 'self_resolved',
          sent: false,
          progress_confirmed: true,
          failure_class: 'RECOVERABLE_CHAT_FAILURE',
          failure_reason: failureReason || 'unknown',
          recovery_attempt: 0,
          recovery_latency_ms: Math.max(0, Date.now() - recoveryStartedAtMs),
          http_status: alignmentHttpStatus,
          cross_device_discovery: crossDeviceDiscovery,
        };
      }
      console.log(
        `chatgpt_continuity_reinforcement ${failureSignal || 'failure'}_cleared_without_progress recovery_continues=true failure_reason=${failureReason || 'unknown'}`,
      );
    }

    if (failureSignal === 'silent_stall' && failureStillPresent) {
      const passiveTurnBaseline = await conversationTurnState(cdp);
      const passiveBaseline = await assistantSnapshot(cdp);
      await cdp.evaluate(`(()=>{location.reload();return true})()`);
      await sleep(1200);
      const passiveProgressed = await confirmProgress(
        cdp,
        passiveBaseline,
        PASSIVE_REATTACH_CONFIRM_MS,
        PROGRESS_POLL_MS,
        passiveTurnBaseline,
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
        console.log(
          'chatgpt_continuity_reinforcement silent_stall_state_cleared_after_reattach recovery_continues=true',
        );
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
      const turnBaseline = await conversationTurnState(cdp);
      const sent = await sendContinueMessage(cdp, baseline?.conversationFingerprint);
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

      const progressed = await confirmAfterSend(cdp, baseline, confirmProgress, turnBaseline);
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
    const normalizedFailureReason = text(outcome.failure_reason || failureReason || 'generation_error');
    console.log(
      `chatgpt_continuity_reinforcement error_banner_confirmed result=${outcome.result_status} failure_class=RECOVERABLE_CHAT_FAILURE failure_reason=${normalizedFailureReason} recovery_attempt=${Number(outcome.recovery_attempt || 1)} recovery_latency_ms=${Math.max(0, Number(outcome.recovery_latency_ms || (Date.now() - recoveryStartedAtMs)))}`,
    );
    return {
      action,
      sent: outcome.sent === true,
      progress_confirmed: outcome.result_status === 'PROGRESS_CONFIRMED',
      detail: outcome.detail,
      failure_class: 'RECOVERABLE_CHAT_FAILURE',
      failure_reason: normalizedFailureReason,
      recovery_attempt: Number(outcome.recovery_attempt || 1),
      recovery_latency_ms: Math.max(0, Number(outcome.recovery_latency_ms || (Date.now() - recoveryStartedAtMs))),
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

function reinforcementSweepAllowed(allowAccountDiscovery, outcome) {
  if (!allowAccountDiscovery) return false;
  if (outcome?.cross_device_discovery !== true) return false;
  if (Number(outcome?.http_status) === 429) return false;
  return true;
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
  checkpointActive = check === reinforcementCheckOnce ? hasActiveContinuityCheckpoint : null,
  browserSessionReady = check === reinforcementCheckOnce ? browserSessionReadyForReinforcement : null,
  heartbeatIntervalMs = REINFORCEMENT_POLL_MS,
) {
  let nextAccountDiscoveryAt = 0;
  let nextReinforcementCheckAt = 0;
  let nextRecoveryRetryAt = 0;
  let recoveryRetryFailureReason = '';
  for (;;) {
    if (checkpointActive && !(await checkpointActive())) {
      REINFORCEMENT_RECENT_CANDIDATES = [];
      REINFORCEMENT_RECENT_CURSOR = 0;
      REINFORCEMENT_LATEST_ID = '';
      persistReinforcementHealth({
        action: 'idle_no_checkpoint',
        sent: false,
        progress_confirmed: false,
        cross_device_discovery: false,
      });
      await wait(REINFORCEMENT_POLL_MS);
      continue;
    }

    if (browserSessionReady && !(await browserSessionReady())) {
      REINFORCEMENT_RECENT_CANDIDATES = [];
      REINFORCEMENT_RECENT_CURSOR = 0;
      REINFORCEMENT_LATEST_ID = '';
      persistReinforcementHealth({
        action: 'auth_quiescent',
        sent: false,
        progress_confirmed: false,
        cross_device_discovery: false,
      });
      await wait(REINFORCEMENT_POLL_MS);
      continue;
    }

    if (now() < nextRecoveryRetryAt) {
      // The checkpoint-driven dispatcher remains fully enabled during this
      // cooldown. This only prevents the secondary reinforcement loop from
      // repeatedly reloading/sending against the same persistent failed turn.
      persistReinforcementHealth({
        action: 'recovery_retry_cooldown',
        sent: false,
        progress_confirmed: false,
        failure_reason: recoveryRetryFailureReason,
        cross_device_discovery: false,
      });
      await wait(REINFORCEMENT_POLL_MS);
      continue;
    }

    if (now() < nextReinforcementCheckAt) {
      // Keep local liveness fresh without contacting the browser or account.
      persistReinforcementHealth({
        action: 'additional_checks_cooldown',
        sent: false,
        progress_confirmed: false,
        failure_reason: 'additional_checks',
        cross_device_discovery: false,
      });
      await wait(REINFORCEMENT_POLL_MS);
      continue;
    }

    const allowAccountDiscovery = now() >= nextAccountDiscoveryAt;
    const alignLatest = allowAccountDiscovery
      ? alignLatestForReinforcement
      : alignLocalSidebarForReinforcement;

    let outcome;
    try {
      outcome = await withReinforcementHeartbeat(
        () => withBrowserRecoveryLock(() => check(
          () => connectReinforcementChatgptTab({ allowCrossDeviceDiscovery: true }),
          REINFORCEMENT_CONFIRM_DELAY_MS,
          confirmAssistantProgress,
          alignLatest,
          { allowCrossDeviceDiscovery: true },
        )),
        heartbeatIntervalMs,
      );
    } catch (error) {
      console.error(`chatgpt_continuity_reinforcement_error ${text(error?.message)}`);
      outcome = {
        action: 'error',
        cross_device_discovery: true,
      };
    }

    persistReinforcementHealth(outcome);

    if (outcome?.action === 'send_failed' || outcome?.action === 'sent_unconfirmed') {
      recoveryRetryFailureReason = text(outcome?.failure_reason);
      nextRecoveryRetryAt = Math.max(
        nextRecoveryRetryAt,
        now() + REINFORCEMENT_RECOVERY_RETRY_COOLDOWN_MS,
      );
      console.log(
        `chatgpt_continuity_reinforcement recovery_retry_backoff_ms=${REINFORCEMENT_RECOVERY_RETRY_COOLDOWN_MS} action=${text(outcome?.action)} checkpoint_dispatcher_remains_enabled=true`,
      );
      await wait(REINFORCEMENT_POLL_MS);
      continue;
    }

    if (outcome?.action === 'additional_checks_cooldown') {
      nextReinforcementCheckAt = Math.max(
        nextReinforcementCheckAt,
        now() + ADDITIONAL_CHECKS_COOLDOWN_MS,
      );
      await wait(REINFORCEMENT_POLL_MS);
      continue;
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
          REINFORCEMENT_RECENT_CANDIDATES = [];
          REINFORCEMENT_RECENT_CURSOR = 0;
          REINFORCEMENT_LATEST_ID = '';
          console.log(
            `chatgpt_continuity_reinforcement latest_discovery_backoff_ms=${discoveryDelayMs} action=${text(outcome?.action)} local_sidebar_continues=true cached_sweep_suspended=true`,
          );
        }
      }
    }

    // Production default only: after the newest chat is checked, rotate through
    // additional recent chats discovered from both the global account list and
    // Project gizmo lists. Keep this inside the same loop so browser navigation
    // stays serialized; tests that inject a custom check retain the historical
    // single-call contract.
    if (
      check === reinforcementCheckOnce
      && reinforcementSweepAllowed(allowAccountDiscovery, outcome)
      && REINFORCEMENT_RECENT_CANDIDATES.length > 1
    ) {
      const sweep = reinforcementSweepCandidates(
        REINFORCEMENT_RECENT_CANDIDATES,
        REINFORCEMENT_LATEST_ID,
        REINFORCEMENT_RECENT_CURSOR,
        REINFORCEMENT_SWEEP_BATCH_SIZE,
      );
      REINFORCEMENT_RECENT_CURSOR = sweep.next_cursor;
      for (const candidate of sweep.batch) {
        try {
          const candidateOutcome = await withReinforcementHeartbeat(
            () => withBrowserRecoveryLock(() => check(
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
            )),
            heartbeatIntervalMs,
          );
          persistReinforcementHealth(candidateOutcome);
          if (candidateOutcome?.action === 'additional_checks_cooldown') {
            nextReinforcementCheckAt = Math.max(
              nextReinforcementCheckAt, now() + ADDITIONAL_CHECKS_COOLDOWN_MS,
            );
            break;
          }
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
  safeConversationId,
  selectBoundConversationTabs,
  selectBoundConversationReentryTab,
  connectFirstUsableChatgptTab,
  connectReinforcementChatgptTab,
  resolveAmbiguousConversationTabs,
  createNeutralChatgptTab,
  navigateNeutralTabToConversation,
  checkpointUpdatedAtMs,
  hasActiveContinuityCheckpoint,
  browserSessionReadyForReinforcement,
  selectCheckpointConversationCandidate,
  conversationIsGenerating,
  conversationStreamStatus,
  conversationTurnState,
  realAssistantResponseCompletedSince,
  silentStallPresent,
  composerIsUsable,
  waitForComposerUsable,
  errorBannerPresent,
  recoverableFailureReason,
  conversationUnavailablePresent,
  clickRecoverableRetryButton,
  outcomeStatusDetailCode,
  reinforcementHealthPayload,
  persistReinforcementHealth,
  bridgeResultPayload,
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
  sameConversationSnapshot,
  confirmAssistantProgress,
  sendContinueMessage,
  attemptNudge,
  reinforcementCheckOnce,
  reinforcementDiscoveryDelayMs,
  reinforcementSweepAllowed,
  withBrowserRecoveryLock,
  bridgeLoop,
  reinforcementLoop,
  authorizationButtonTarget,
  clickAuthorizationIfPresent,
  authorizationCheckOnce,
  authorizationLoop,
  mainLoop,
};
