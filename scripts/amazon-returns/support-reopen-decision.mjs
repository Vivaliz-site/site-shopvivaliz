#!/usr/bin/env node
// Decisao (sem rede, sem navegador) para o Amazon Seller Support.
// Contrato: somente REABRIR caso existente (Reopen case / Reabrir caso).
// Nunca decide abrir um caso novo. O clique em si e feito pelo probe; este
// modulo decide se e elegivel e classifica o resultado confirmado por releitura.

export const REOPEN_RE = /\b(reopen case|reabrir caso)\b/i;

function clean(value, max = 200) {
  return String(value ?? '').replace(/\s+/g, ' ').trim().slice(0, max);
}

export function listAvailableActions(buttons) {
  return (Array.isArray(buttons) ? buttons : [])
    .map((label) => clean(label, 80))
    .filter(Boolean)
    .slice(0, 20);
}

export function evaluateSupportReopen(snapshot, { dryRun = true } = {}) {
  const s = snapshot && typeof snapshot === 'object' ? snapshot : {};
  const caseId = clean(s.case_id, 32);
  const base = { case_id: caseId, submitted: false, dry_run: dryRun === true };

  if (!/^\d{6,20}$/.test(caseId)) return { ...base, status: 'FAILED', reason: 'INVALID_CASE_ID' };
  if (s.read_back_ok !== true) return { ...base, status: 'READ_BACK_FAILED', reason: 'CASE_PAGE_NOT_READ' };
  if (s.authenticated !== true) return { ...base, status: 'AUTH_REQUIRED', reason: 'SESSION_NOT_AUTHENTICATED' };
  if (s.mfa_required === true) return { ...base, status: 'AUTH_REQUIRED', reason: 'MFA_REQUIRED' };
  if (s.captcha_present === true) return { ...base, status: 'HUMAN_CHALLENGE', reason: 'CAPTCHA_PRESENT' };

  const actions = listAvailableActions(s.buttons);
  if (s.case_open === true) return { ...base, status: 'ALREADY_OPEN', reason: 'CASE_ALREADY_OPEN', available_actions: actions };

  const reopen = actions.some((label) => REOPEN_RE.test(label));
  if (!reopen) return { ...base, status: 'TERMINAL_NO_REOPEN_ACTION', reason: 'NO_REOPEN_ACTION', available_actions: actions };
  return {
    ...base,
    status: dryRun === true ? 'WOULD_REOPEN' : 'REOPEN_ELIGIBLE',
    reason: dryRun === true ? 'REOPEN_AVAILABLE_DRY_RUN' : 'REOPEN_AVAILABLE',
    available_actions: actions,
  };
}

// Resultado depois da acao: so vira REOPENED com clique feito E confirmado por
// releitura. Clique sem confirmacao NAO e repetido (retry_safe=false) para nao
// duplicar a operacao externa.
export function finalizeReopen(decision, outcome) {
  const o = outcome && typeof outcome === 'object' ? outcome : {};
  if (decision?.status !== 'REOPEN_ELIGIBLE') return decision;
  if (o.clicked !== true) return { ...decision, status: 'FAILED', reason: 'REOPEN_CLICK_FAILED', retry_safe: true };
  if (o.verified !== true) return { ...decision, status: 'READ_BACK_FAILED', reason: 'REOPEN_CLICKED_NOT_CONFIRMED', submitted: true, retry_safe: false };
  return { ...decision, status: 'REOPENED', reason: 'REOPEN_CONFIRMED_BY_READ_BACK', submitted: true, retry_safe: false };
}

export function evaluateAll(snapshots, options = {}) {
  const seen = new Set();
  const results = [];
  for (const snap of Array.isArray(snapshots) ? snapshots : []) {
    const id = clean(snap?.case_id, 32);
    if (seen.has(id)) { results.push({ case_id: id, status: 'DUPLICATE_SKIPPED', submitted: false, dry_run: options.dryRun !== false }); continue; }
    seen.add(id);
    const decision = evaluateSupportReopen(snap, options);
    results.push(snap?.reopen_attempted === true
      ? finalizeReopen(decision, { clicked: snap.reopen_clicked, verified: snap.reopen_verified })
      : decision);
  }
  return results;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const dryRun = String(process.env.DRY_RUN ?? 'true').toLowerCase() !== 'false';
  const chunks = [];
  process.stdin.on('data', (c) => chunks.push(c));
  process.stdin.on('end', () => {
    let input = [];
    try { input = JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch { /* entrada invalida vira lista vazia */ }
    for (const r of evaluateAll(input, { dryRun })) console.log(JSON.stringify(r));
  });
}
