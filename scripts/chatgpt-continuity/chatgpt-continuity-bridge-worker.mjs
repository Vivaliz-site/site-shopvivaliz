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
  if (normalized.includes('canonical history read rate limited')) return 'CANONICAL_READ_RATE_LIMITED';
  if (normalized.includes('bound browser session stream active or unconfirmed')) return 'BOUND_STREAM_NOT_COMPLETE';
  if (normalized.includes('another conversation in the same browser session is active')) return 'SIBLING_STREAM_ACTIVE';
  if (normalized.includes('canonical presence could not be confirmed')) return 'CONVERSATION_PRESENCE_UNCONFIRMED';
  if (normalized.includes('no unfinished response is confirmed')) return 'CANONICAL_NO_UNFINISHED_RESPONSE';
  if (normalized.includes('active stream remains unconfirmed')) return 'ACTIVE_STREAM_AFTER_REATTACH';
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
    || action === 'idle_no_checkpoint'
    || action === 'monitor_disabled';
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

function classifyChatgptPath(pathname) {
  const conversation = String(pathname || '').match(/^\/(?:g\/[^/]+\/)?(?:c|uc)\/([^/]+)/);
  if (conversation) return { rank: 0, conversationId: conversation[1] };
  if (pathname === '/' || pathname === '') return { rank: 1, conversationId: '' };
  if (/^\/(?:auth|login|logout)(?:\/|$)/.test(pathname)) return { rank: 3, conversationId: '' };
  return { rank: 2, conversationId: '' };
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
  return classifyChatgptPath(url.pathname).rank;
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
    return classifyChatgptPath(new URL(String(tab.url || '')).pathname).conversationId;
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
        // Global/detached tasks without a bound browser conversation must not
        // keep the Fred browser hot with account-scoped reinforcement sweeps.
        if (Object.hasOwn(payload || {}, 'browser_session') && payload.browser_session !== 'fred') continue;
        if (!safeConversationId(payload?.conversation_id)) continue;
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
        // Persist only a non-secret deadline in this browser profile. A new
        // tab or worker restart must not immediately repeat a throttled read.
        const backoffKey='shopvivaliz.canonical-read-backoff-until.v1';
        let backoffUntil=Number(globalThis.__shopvivalizCanonicalReadBackoffUntil||0);
        if(!Number.isFinite(backoffUntil)) backoffUntil=0;
        try{
          const stored=Number(localStorage.getItem(backoffKey)||0);
          if(Number.isFinite(stored)) backoffUntil=Math.max(backoffUntil,stored);
        }catch{}
        const rateLimited=()=>({http_status:429,node_id:'',role:'',end_turn:null,
          child_count:-1,content_text_length:0,message_status:'RATE_LIMIT_BACKOFF',
          retry_after_ms:Math.max(0,backoffUntil-Date.now())});
        if(backoffUntil>Date.now()) return rateLimited();
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
          if(response.status===429){
            const retryAfter=String(response.headers?.get?.('retry-after')||'').trim();
            const seconds=Number(retryAfter);
            const retryDate=Date.parse(retryAfter);
            const requestedWait=retryAfter&&Number.isFinite(seconds)&&seconds>=0
              ? seconds*1000 : (Number.isFinite(retryDate)?retryDate-Date.now():0);
            const waitMs=Number.isFinite(requestedWait)?Math.max(300000,requestedWait):300000;
            backoffUntil=Date.now()+waitMs;
            globalThis.__shopvivalizCanonicalReadBackoffUntil=backoffUntil;
            try{
              const stored=Number(localStorage.getItem(backoffKey)||0);
              if(Number.isFinite(stored)) backoffUntil=Math.max(backoffUntil,stored);
              localStorage.setItem(backoffKey,String(backoffUntil));
            }catch{}
            return {...rateLimited(),message_status:'HTTP_ERROR'};
          }
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

// Fresh server bookkeeping is required at the actuator boundary as well as
// in callers: reinforcement and delayed keyboard fallbacks share these helpers.
async function conversationStreamComplete(cdp) {
  const stream = await conversationStreamStatus(cdp);
  return stream?.http_status === 200 && String(stream?.status || '').toUpperCase() === 'COMPLETE';
}

async function clearStaleCompleteGeneration(cdp) {
  if (!(await conversationStreamComplete(cdp))) return false;
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

    const nod