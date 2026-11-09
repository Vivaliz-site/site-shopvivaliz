#!/usr/bin/env node
// Decisao pura (sem rede, sem navegador) para suporte Amazon Seller Support.
// Contrato: somente REABRIR caso existente (Reopen case / Reabrir caso).
// Nunca decide abrir um caso novo. Escrita real fica fora deste modulo.

const REOPEN_RE = /\b(reopen case|reabrir caso)\b/i;

function clean(value, max = 200) {
  return String(value ?? '').replace(/\s+/g, ' ').trim().slice(0, max);
}

export function listAvailableActions(buttons) {
  return (Array.isArray(buttons) ? buttons : [])
    .map((label) => clean(label, 80))
    .filter(Boolean)
    .slice(0, 20);
}

export function evaluateSupportReopen(snapshot) {
  const s = snapshot && typeof snapshot === 'object' ? snapshot : {};
  const caseId = clean(s.case_id, 32);
  const base = { case_id: caseId, submitted: false, dry_run: true };

  if (!/^\d{6,20}$/.test(caseId)) return { ...base, status: 'FAILED', reason: 'INVALID_CASE_ID' };
  if (s.read_back_ok !== true) return { ...base, status: 'READ_BACK_FAILED', reason: 'CASE_PAGE_NOT_READ' };
  if (s.authenticated !== true) return { ...base, status: 'AUTH_REQUIRED', reason: 'SESSION_NOT_AUTHENTICATED' };
  if (s.mfa_required === true) return { ...base, status: 'AUTH_REQUIRED', reason: 'MFA_REQUIRED' };
  if (s.captcha_present === true) return { ...base, status: 'HUMAN_CHALLENGE', reason: 'CAPTCHA_PRESENT' };

  const actions = listAvailableActions(s.buttons);
  if (s.case_open === true) return { ...base, status: 'ALREADY_OPEN', reason: 'CASE_ALREADY_OPEN', available_actions: actions };

  const reopen = actions.some((label) => REOPEN_RE.test(label));
  if (!reopen) return { ...base, status: 'TERMINAL_NO_REOPEN_ACTION', reason: 'NO_REOPEN_ACTION', available_actions: actions };
  return { ...base, status: 'WOULD_REOPEN', reason: 'REOPEN_AVAILABLE_DRY_RUN', available_actions: actions };
}

export function evaluateAll(snapshots) {
  const seen = new Set();
  const results = [];
  for (const snap of Array.isArray(snapshots) ? snapshots : []) {
    const id = clean(snap?.case_id, 32);
    if (seen.has(id)) { results.push({ case_id: id, status: 'DUPLICATE_SKIPPED', submitted: false, dry_run: true }); continue; }
    seen.add(id);
    results.push(evaluateSupportReopen(snap));
  }
  return results;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const chunks = [];
  process.stdin.on('data', (c) => chunks.push(c));
  process.stdin.on('end', () => {
    let input = [];
    try { input = JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch { /* invalid input handled below */ }
    for (const r of evaluateAll(input)) console.log(JSON.stringify(r));
  });
}
