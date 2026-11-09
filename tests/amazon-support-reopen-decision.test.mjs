import test from 'node:test';
import assert from 'node:assert/strict';
import { evaluateSupportReopen, evaluateAll } from '../scripts/amazon-returns/support-reopen-decision.mjs';

const ok = { case_id: '22153077391', read_back_ok: true, authenticated: true, buttons: [] };

test('caso fechado com Reopen case -> WOULD_REOPEN em dry-run, nada enviado', () => {
  const r = evaluateSupportReopen({ ...ok, buttons: ['Reopen case', 'Close'] });
  assert.equal(r.status, 'WOULD_REOPEN');
  assert.equal(r.submitted, false);
  assert.equal(r.dry_run, true);
});

test('Reabrir caso (pt-BR) tambem reconhecido', () => {
  assert.equal(evaluateSupportReopen({ ...ok, buttons: ['Reabrir caso'] }).status, 'WOULD_REOPEN');
});

test('sem acao de reabrir -> TERMINAL_NO_REOPEN_ACTION (nunca abre caso novo)', () => {
  const r = evaluateSupportReopen({ ...ok, buttons: ['Contact us', 'Open new case'] });
  assert.equal(r.status, 'TERMINAL_NO_REOPEN_ACTION');
  assert.equal(r.submitted, false);
});

test('caso ja aberto -> ALREADY_OPEN, sem duplicidade', () => {
  assert.equal(evaluateSupportReopen({ ...ok, case_open: true, buttons: ['Reopen case'] }).status, 'ALREADY_OPEN');
});

test('leitura falhou -> READ_BACK_FAILED', () => {
  assert.equal(evaluateSupportReopen({ ...ok, read_back_ok: false }).status, 'READ_BACK_FAILED');
});

test('sessao, MFA e CAPTCHA nao sao contornados', () => {
  assert.equal(evaluateSupportReopen({ ...ok, authenticated: false }).status, 'AUTH_REQUIRED');
  assert.equal(evaluateSupportReopen({ ...ok, mfa_required: true }).status, 'AUTH_REQUIRED');
  assert.equal(evaluateSupportReopen({ ...ok, captcha_present: true }).status, 'HUMAN_CHALLENGE');
});

test('case_id invalido e rejeitado', () => {
  assert.equal(evaluateSupportReopen({ ...ok, case_id: 'abc; rm -rf /' }).status, 'FAILED');
});

test('ids repetidos na mesma execucao nao sao processados duas vezes', () => {
  const r = evaluateAll([{ ...ok, buttons: ['Reopen case'] }, { ...ok, buttons: ['Reopen case'] }]);
  assert.deepEqual(r.map((x) => x.status), ['WOULD_REOPEN', 'DUPLICATE_SKIPPED']);
});

test('nenhum resultado jamais marca submitted=true', () => {
  const all = evaluateAll([{ ...ok, buttons: ['Reopen case'] }, { ...ok, case_id: '22153259501', case_open: true }]);
  assert.ok(all.every((x) => x.submitted === false));
});
