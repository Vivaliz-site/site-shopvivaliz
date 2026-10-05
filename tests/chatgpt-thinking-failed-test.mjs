import assert from 'node:assert/strict';
import vm from 'node:vm';

// Execute the actual injected detector program. The small Range fixture gives
// it the current status after the last message, never a canned detector result.
// No real browser, account, credentials or network is used by this test.
function surface({ history = 'Previous reply', tail = '', roles = true, alert = '' } = {}) {
  const message = { innerText: history, textContent: history };
  const document = {
    body: {
      innerText: history + '\n' + tail,
      textContent: history + '\n' + tail,
      querySelectorAll(selector) {
        if (selector === '[data-message-author-role]') return roles ? [message] : [];
        return alert ? [{ innerText: alert, textContent: alert }] : [];
      },
    },
    createRange() {
      return { selectNodeContents() {}, setStartAfter(node) { assert.equal(node, message); }, toString() { return tail; } };
    },
  };
  return { async evaluate(expression) { return vm.runInNewContext(expression, { document }); } };
}

export async function runThinkingFailedTests({ errorBannerPresent, recoverableFailureReason, sendContinueMessage }) {
  const failures = [];
  const cases = [
    ['Portuguese failure in the current status is a generation error', async () => {
      const cdp = surface({ tail: 'O pensamento falhou' });
      assert.equal(await errorBannerPresent(cdp), true);
      assert.equal(await recoverableFailureReason(cdp), 'generation_error');
    }],
    ['English failure in the current status is a generation error', async () => {
      const cdp = surface({ tail: 'Thinking failed' });
      assert.equal(await errorBannerPresent(cdp), true);
      assert.equal(await recoverableFailureReason(cdp), 'generation_error');
    }],
    ['Portuguese live error region is recognized', async () => {
      assert.equal(await recoverableFailureReason(surface({ alert: 'O pensamento falhou' })), 'generation_error');
    }],
    ['English current failure without role metadata is recognized', async () => {
      assert.equal(await recoverableFailureReason(surface({ roles: false, tail: 'Thinking failed' })), 'generation_error');
    }],
    ['historical Portuguese quotation is not a current failure', async () => {
      const cdp = surface({ history: 'The screenshot said O pensamento falhou', tail: 'Ready for another message' });
      assert.equal(await errorBannerPresent(cdp), false);
      assert.equal(await recoverableFailureReason(cdp), '');
    }],
    ['historical English quotation is not a current failure', async () => {
      const cdp = surface({ history: 'The old failure was Thinking failed', tail: '' });
      assert.equal(await errorBannerPresent(cdp), false);
      assert.equal(await recoverableFailureReason(cdp), '');
    }],
    ['ordinary thinking status is not a failure', async () => {
      assert.equal(await recoverableFailureReason(surface({ tail: 'Pensando Thinking' })), '');
    }],
    ['platform additional checks still take priority over failure wording', async () => {
      assert.equal(await recoverableFailureReason(surface({ tail: 'O pensamento falhou. Additional checks before responding' })), 'additional_checks');
    }],
    ['an active server stream still prevents any continuation input', async () => {
      const current = surface({ tail: 'O pensamento falhou' });
      let sends = 0;
      const cdp = {
        evaluate: source => source.includes('/stream_status')
          ? Promise.resolve({ http_status: 200, status: 'IS_STREAMING' }) : current.evaluate(source),
        async send() { sends += 1; throw Error('must_not_type_or_click'); },
      };
      assert.equal(await sendContinueMessage(cdp), false);
      assert.equal(sends, 0);
    }],
  ];
  for (const [name, check] of cases) {
    try { await check(); console.log(`THINKING_FAILED_CASE PASS ${name}`); }
    catch (e) { failures.push(name); console.error(`THINKING_FAILED_CASE FAIL ${name}: ${e.message}`); }
  }
  assert.equal(failures.length, 0, `unrecognized or unsafe failure handling: ${failures.join('; ')}`);
  console.log('THINKING_FAILED_TEST=PASS');
}

if (import.meta.url === `file://${process.argv[1]}`) {
  await runThinkingFailedTests(await import('../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs'));
}
