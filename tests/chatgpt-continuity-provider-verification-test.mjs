#!/usr/bin/env node
import assert from 'node:assert/strict';

import {
  providerVerificationPending,
  attemptNudge,
  reinforcementCheckOnce,
} from '../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs';

function fakeCdp({
  generating = true,
  pageText = 'Nossos sistemas estão fazendo verificações adicionais antes de responder a esta solicitação.',
} = {}) {
  const calls = [];
  return {
    calls,
    async evaluate(expression) {
      const source = String(expression);
      calls.push(source);
      if (source.includes('continuity-provider-verification-probe')) {
        return /(verificações adicionais antes de responder|verificacoes adicionais antes de responder|additional checks before responding)/i.test(pageText);
      }
      if (source.includes('continuity-error-banner-probe')) return false;
      if (source.includes('[data-testid="stop-button"]')) return generating;
      if (source.includes('/stream_status')) return { http_status: 200, status: 'COMPLETE' };
      if (source.includes('prompt-textarea')) return false;
      return null;
    },
    async pageState() {
      return {
        href: 'https://chatgpt.com/c/provider-verification-test',
        title: 'ChatGPT',
        text: pageText,
      };
    },
    close() {},
  };
}

async function run() {
  {
    const cdp = fakeCdp();
    assert.equal(await providerVerificationPending(cdp), true);
    assert.equal(
      await providerVerificationPending(fakeCdp({ generating: false, pageText: 'normal completed answer' })),
      false,
    );
  }

  {
    const cdp = fakeCdp();
    const result = await attemptNudge(
      'task-provider-verification-pending',
      async () => cdp,
      async () => false,
    );
    assert.equal(result.result_status, 'STALLED_NOT_CONFIRMED');
    assert.match(result.detail, /provider verification pending/i);
    assert.equal(
      cdp.calls.some(call => call.includes('location.reload')),
      false,
      'provider verification must not reload the active turn',
    );
    assert.equal(
      cdp.calls.some(call => call.includes('b.click()') || call.includes('insertText')),
      false,
      'provider verification must not inject a continuation',
    );
  }

  {
    const events = [];
    const cdp = fakeCdp();
    const result = await reinforcementCheckOnce(
      async () => {
        events.push('connect');
        return cdp;
      },
      1,
      async () => false,
      async () => {
        events.push('align');
        return { action: 'already_latest', http_status: 200 };
      },
      { allowCrossDeviceDiscovery: true },
    );
    assert.equal(result.action, 'provider_verification_pending');
    assert.equal(result.sent, false);
    assert.equal(result.progress_confirmed, false);
    assert.equal(result.cross_device_discovery, false);
    assert.deepEqual(events, ['connect']);
    assert.equal(
      cdp.calls.some(call => call.includes('location.reload')),
      false,
      'reinforcement must not reload provider verification',
    );
  }

  console.log('CHATGPT_CONTINUITY_PROVIDER_VERIFICATION_TEST=PASS');
}

run().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
