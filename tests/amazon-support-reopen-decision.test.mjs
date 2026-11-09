import test from 'node:test';
import assert from 'node:assert/strict';
import { evaluateSupportReopen, evaluateAll, finalizeReopen } from '../scripts/amazon-returns/support-reopen-decision.mjs';

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

test('dry-run: nenhum resultado marca submitted=true', () => {
  const all = evaluateAll([{ ...ok, buttons: ['Reopen case'] }, { ...ok, case_id: '22153259501', case_open: true }], { dryRun: true });
  assert.ok(all.every((x) => x.submitted === false));
});

test('dryRun=false e elegivel -> REOPEN_ELIGIBLE (ainda nao enviado)', () => {
  const r = evaluateSupportReopen({ ...ok, buttons: ['Reopen case'] }, { dryRun: false });
  assert.equal(r.status, 'REOPEN_ELIGIBLE');
  assert.equal(r.dry_run, false);
  assert.equal(r.submitted, false);
});

test('dryRun=false com caso ja aberto nao clica nem duplica', () => {
  const r = evaluateSupportReopen({ ...ok, case_open: true, buttons: ['Reopen case'] }, { dryRun: false });
  assert.equal(r.status, 'ALREADY_OPEN');
  assert.equal(r.submitted, false);
});

test('clique confirmado por releitura -> REOPENED e sem retry', () => {
  const d = evaluateSupportReopen({ ...ok, buttons: ['Reopen case'] }, { dryRun: false });
  const r = finalizeReopen(d, { clicked: true, verified: true });
  assert.equal(r.status, 'REOPENED');
  assert.equal(r.submitted, true);
  assert.equal(r.retry_safe, false);
});

test('clique sem confirmacao -> READ_BACK_FAILED, submitted=true e NAO repetir', () => {
  const d = evaluateSupportReopen({ ...ok, buttons: ['Reopen case'] }, { dryRun: false });
  const r = finalizeReopen(d, { clicked: true, verified: false });
  assert.equal(r.status, 'READ_BACK_FAILED');
  assert.equal(r.submitted, true);
  assert.equal(r.retry_safe, false);
});

test('falha antes do clique -> FAILED, nada enviado, pode repetir', () => {
  const d = evaluateSupportReopen({ ...ok, buttons: ['Reopen case'] }, { dryRun: false });
  const r = finalizeReopen(d, { clicked: false });
  assert.equal(r.status, 'FAILED');
  assert.equal(r.submitted, false);
  assert.equal(r.retry_safe, true);
});

test('evaluateAll aplica o resultado do clique somente quando houve tentativa', () => {
  const r = evaluateAll([{ ...ok, buttons: ['Reopen case'], reopen_attempted: true, reopen_clicked: true, reopen_verified: true }], { dryRun: false });
  assert.equal(r[0].status, 'REOPENED');
});
