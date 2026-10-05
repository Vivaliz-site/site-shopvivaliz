import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

// Only the external CDP transport is substituted. The real recovery worker
// must refuse each actuator when the canonical stream changes between reads.
function transport(options = {}) {
  const state = { reloads: 0, streamReads: 0, turnReads: 0, sendAttempts: 0,
    nativeRetries: 0, stopClicks: 0, draft: options.initialDraft || '', enterSubmissions: 0, generating: Boolean(options.generating), closed: false };
  const pageText = () => typeof options.pageText === 'function'
    ? options.pageText(state) : (options.pageText || '');
  const pathname = '/c/stream-guard-conversation';
  const cdp = {
    state,
    async evaluate(source) {
      if (source.includes('/stream_status')) {
        state.streamReads += 1;
        return typeof options.stream === 'function' ? options.stream(state)
          : (options.stream || { http_status: 200, status: 'COMPLETE' });
      }
      if (source.includes('continuity-composer-draft-probe')) return { usable: true, text: state.draft };
      if (source.includes('continuity-composer-click-target')) return { x: 100, y: 200 };
      if (source.includes('continuity-composer-focus')) return options.focused !== false;
      if (source.includes('continuity-composer-draft-after-trusted-insert')) return state.draft;
      if (source.includes('continuity-send-button-target')) {
        if (options.activateOnSendTarget) state.becameActive = true;
        return options.submitTargetAbsent ? { state: 'absent' } : { state: 'ready', x: 300, y: 400 };
      }
      if (source.includes('continuity-browser-account-match')) return true;
      if (source === 'location.pathname' || source.includes('continuity-conversation-identity-probe')) return pathname;
      if (source.includes('conversationPath:String')) return {
        count: 1, lastText: 'tool branch', lastId: 'old-assistant',
        conversationPath: pathname, surfaceText: 'tool branch', surfaceLength: 11,
      };
      if (source.includes('/backend-api/conversation/')) {
        state.turnReads += 1;
        const overridden = options.turn ? options.turn(state) : null;
        if (overridden) return overridden;
        return { http_status: 200, node_id: 'old-assistant', role: 'assistant',
          end_turn: false, child_count: 0, content_text_length: 0 };
      }
      if (source.includes('location.reload')) { state.reloads += 1; return true; }
      if (source.includes('continuity-retry-button-target')) {
        if (options.activateOnRetryTarget) state.becameActive = true;
        return { x: 10, y: 20 };
      }
      if (source.includes('continuity-retry-button-click-legacy')) { state.nativeRetries += 1; return true; }
      if (source.includes('stale-complete-stop-clear')) {
        state.stopClicks += 1; state.generating = false; return true;
      }
      if (source.includes('stop-button')) return state.generating;
      if (source.includes('continuity-conversation-unavailable-probe')
          || source.includes('continuity-responding-indicator-probe')
          || source.includes('continuity-additional-checks-probe')) return false;
      if (source.includes('continuity-stopped-thinking-probe')) return /Stopped thinking/i.test(pageText());
      if (source.includes('continuity-streaming-interrupted-probe')
          || source.includes('continuity-request-timeout-probe')) return false;
      if (source.includes('continuity-error-banner-probe')) return /Something went wrong|Stopped thinking/i.test(pageText());
      if (source.includes('continuity-transmission-error-probe')) return /Erro na transmissão/i.test(pageText());
      if (source.includes('proto.value') || source.includes("execCommand('insertText'")) return true;
      if (source.includes('b.click()')) {
        state.sendAttempts += 1;
        return options.sendSucceeds !== false;
      }
      if (source.includes('prompt-textarea') || source.includes('send-button')) {
        return options.silentBeforeReload ? state.reloads > 0 : true;
      }
      return null;
    },
    async pageState() { return { href: 'https://chatgpt.com' + pathname, title: 'ChatGPT', text: pageText() }; },
    close() { state.closed = true; },
  };
  if (options.trusted) cdp.send = async (method, params = {}) => {
    if (method === 'Input.insertText') state.draft += String(params.text || '');
    if (method === 'Input.dispatchKeyEvent') {
      if (params.type === 'rawKeyDown' && params.key === 'Backspace') state.draft = '';
      if (params.type === 'char') state.draft += String(params.text || '');
      if ((params.type === 'rawKeyDown' || params.type === 'keyDown') && params.key === 'Enter') {
        state.sendAttempts += 1; state.enterSubmissions += 1;
      }
    }
    if (method === 'Input.dispatchMouseEvent' && params.type === 'mouseReleased') {
      if (params.x === 300) state.sendAttempts += 1;
      if (params.x === 10) state.nativeRetries += 1;
    }
    return {};
  };
  return cdp;
}

export async function runStreamActuatorGuardTests(api, directory) {
  const active = { http_status: 200, status: 'IS_STREAMING' };
  const complete = { http_status: 200, status: 'COMPLETE' };
  const rateLimited = { http_status: 429, node_id: '', role: '', end_turn: null,
    child_count: -1, content_text_length: 0, message_status: 'RATE_LIMIT_BACKOFF' };
  const cases = [
    ['canonical history 429 prevents the initial passive reload', {
      turn: () => rateLimited, wantReloads: 0,
    }],
    ['canonical history 429 prevents native Retry after reattach', {
      pageText: 'Stopped thinking', turn: state => state.reloads ? rateLimited : null,
      wantReloads: 1,
    }],
    ['canonical history 429 prevents first continuation after composer readiness', {
      turn: state => state.rateLimited ? rateLimited : null,
      waitComposer: async cdp => { cdp.state.rateLimited = true; return true; },
      wantReloads: 1,
    }],
    ['canonical history 429 prevents another send after failed submission', {
      sendSucceeds: false, turn: state => state.reloads >= 2 ? rateLimited : null,
      wantAttempts: 1, wantReloads: 2,
    }],
    ['canonical history 429 after a real send preserves the send budget and prevents duplicate transmission', {
      pageText: state => state.sendAttempts ? 'Erro na transmiss\u00e3o de mensagem' : '',
      turn: state => state.reloads >= 2 ? rateLimited : null,
      wantStatus: 'SENT_UNCONFIRMED', wantSent: true, wantAttempts: 1, wantReloads: 2,
    }],
    ['silent stream without Stop remains active after reattach', {
      silentBeforeReload: true, stream: active,
    }],
    ['error banner cannot override active stream after reattach', {
      pageText: 'Something went wrong', stream: state => state.reloads ? active : complete,
    }],
    ['native Retry cannot run against an active stream', {
      pageText: 'Stopped thinking', stream: state => state.reloads ? active : complete,
    }],
    ['native Retry rechecks after its canonical baseline read', {
      pageText: 'Stopped thinking', stream: state => state.turnReads >= 2 ? active : complete,
    }],
    ['stale Stop rechecks stream immediately before clearing it', {
      pageText: 'Something went wrong', generating: true,
      stream: state => state.streamReads >= 2 ? active : complete,
    }],
    ['composer becoming usable does not authorize an active stream send', {
      stream: state => state.becameActive ? active : complete,
      waitComposer: async cdp => { cdp.state.becameActive = true; return true; },
    }],
    ['failed first send cannot retry after stream becomes active', {
      sendSucceeds: false, stream: state => state.reloads >= 2 ? active : complete,
      wantAttempts: 1,
    }],
    ['transmission retry cannot duplicate a now-active first send', {
      pageText: state => state.sendAttempts ? 'Erro na transmissão de mensagem' : '',
      stream: state => state.reloads >= 2 ? active : complete,
      wantAttempts: 1, wantSent: true,
    }],
    ['bound corporate COMPLETE can become active after reattach', {
      bound: true, stream: state => state.reloads ? active : complete,
    }],
    ['HTTP 401 cannot authorize a continuation', { stream: { http_status: 401, status: 'COMPLETE' } }],
    ['HTTP 429 cannot authorize a continuation', { stream: { http_status: 429, status: 'COMPLETE' } }],
    ['unknown stream state cannot authorize a continuation', { stream: { http_status: 200, status: 'UNKNOWN' } }],
    ['confirmed COMPLETE preserves checkpoint continuation', {
      stream: complete, wantStatus: 'SENT_UNCONFIRMED', wantSent: true, wantAttempts: 1,
    }],
    ['confirmed COMPLETE preserves native Retry recovery', {
      pageText: 'Stopped thinking', stream: complete, wantStatus: 'PROGRESS_CONFIRMED',
      wantRetries: 1, confirmProgress: (_cdp, _baseline, _timeout, _poll, _turn, calls) => calls >= 2,
    }],
  ];
  cases.push(
    ['direct reinforcement cannot send into silent active stream', {
      kind: 'reinforcement', silentBeforeReload: true, stream: active,
    }],
    ['direct legacy send refuses active stream before drafting', { kind: 'primitive', stream: active }],
    ['direct trusted send refuses active stream before input', { kind: 'primitive', trusted: true, stream: active }],
    ['trusted submit rechecks when stream activates during typing', {
      kind: 'primitive', trusted: true, stream: state => state.draft ? active : complete,
    }],
    ['trusted click rejection cannot fall through to Enter', {
      kind: 'primitive', trusted: true, activateOnSendTarget: true,
      stream: state => state.becameActive ? active : complete,
    }],
    ['trusted Enter with focus rechecks active stream after typing', {
      kind: 'primitive', trusted: true, submitTargetAbsent: true,
      stream: state => state.draft ? active : complete,
    }],
    ['trusted BODY-focus Enter rechecks active stream after insert', {
      kind: 'primitive', trusted: true, focused: false, submitTargetAbsent: true,
      stream: state => state.draft ? active : complete,
    }],
    ['stale continuation draft cannot submit when stream activates at target', {
      kind: 'primitive', trusted: true, focused: false, initialDraft: 'continue',
      activateOnSendTarget: true, stream: state => state.becameActive ? active : complete,
    }],
    ['direct Retry rechecks after target discovery', {
      kind: 'retry', trusted: true, activateOnRetryTarget: true,
      stream: state => state.becameActive ? active : complete,
    }],
    ['confirmed COMPLETE preserves trusted pointer submission', {
      kind: 'primitive', trusted: true, stream: complete, wantSubmitted: true, wantAttempts: 1,
    }],
    ['confirmed COMPLETE preserves focused trusted Enter fallback', {
      kind: 'primitive', trusted: true, submitTargetAbsent: true, stream: complete,
      wantSubmitted: true, wantAttempts: 1, wantEnter: 1,
    }],
    ['confirmed COMPLETE preserves BODY-focus trusted Enter fallback', {
      kind: 'primitive', trusted: true, focused: false, submitTargetAbsent: true, stream: complete,
      wantSubmitted: true, wantAttempts: 1, wantEnter: 1,
    }],
  );
  const failures = [];
  const fixture = path.join(directory, 'stream-actuator-guard-bound.json');
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async () => { throw Error('unexpected external transport in stream guard test'); };
  try {
    for (const [name, options] of cases) {
      const cdp = transport(options);
      let confirmationCalls = 0;
      const id = options.bound ? 'stream-actuator-guard-bound' : 'stream-actuator-guard-legacy';
      if (options.bound) fs.writeFileSync(fixture, JSON.stringify({
        schema_version: 1, task_id: id, status: 'RUNNING',
        repository: 'Vivaliz-site/site-shopvivaliz', browser_session: 'atendimento',
        conversation_id: 'stream-guard-conversation',
      }));
      try {
        if (options.kind === 'primitive' || options.kind === 'retry') {
          const submitted = options.kind === 'primitive'
            ? await api.sendContinueMessage(cdp) : await api.clickRecoverableRetryButton(cdp);
          assert.equal(submitted, options.wantSubmitted || false);
        } else if (options.kind === 'reinforcement') {
          const result = await api.reinforcementCheckOnce(async () => cdp, 0, async () => false,
            async () => ({ action: 'already_latest', http_status: 200 }),
            { allowCrossDeviceDiscovery: true });
          assert.equal(result.sent, false);
          assert.equal(result.progress_confirmed, false);
        } else {
          const result = await api.attemptNudge(id, async () => cdp,
            async (...args) => {
              confirmationCalls += 1;
              return options.confirmProgress ? options.confirmProgress(...args, confirmationCalls) : false;
            },
            options.waitComposer || (async () => true),
            options.bound ? 'stream-guard-conversation' : '');
          assert.equal(result.result_status, options.wantStatus || 'STALLED_NOT_CONFIRMED');
          assert.equal(result.sent, options.wantSent || false);
        }
        if (Object.hasOwn(options, 'wantReloads')) assert.equal(cdp.state.reloads, options.wantReloads, 'bounded passive reload count');
        assert.equal(cdp.state.sendAttempts, options.wantAttempts || 0, 'continuation submission count');
        assert.equal(cdp.state.nativeRetries, options.wantRetries || 0, 'native Retry count');
        assert.equal(cdp.state.stopClicks, 0, 'active stream must not receive Stop');
        assert.equal(cdp.state.enterSubmissions, options.wantEnter || 0, 'trusted Enter submission count');
        if (!options.kind || options.kind === 'reinforcement') assert.equal(cdp.state.closed, true);
        console.log('STREAM_ACTUATOR_CASE PASS ' + name);
      } catch (error) {
        failures.push(name);
        console.error('STREAM_ACTUATOR_CASE FAIL ' + name + ': ' + error.message);
      } finally { fs.rmSync(fixture, { force: true }); }
    }
  } finally { globalThis.fetch = originalFetch; }
  assert.equal(failures.length, 0, 'stream actuator failures: ' + failures.join('; '));
  console.log('STREAM_ACTUATOR_GUARD_TEST=PASS');
}

if (import.meta.url === 'file://' + process.argv[1]) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'stream-actuator-guard-'));
  process.env.SHOPVIVALIZ_AGENT_TASK_STATE_DIR = directory;
  try {
    await runStreamActuatorGuardTests(
      await import('../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs'), directory);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
}
