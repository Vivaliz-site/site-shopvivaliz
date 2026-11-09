#!/usr/bin/env node
// Probe do Amazon Seller Support via CDP em perfil EFEMERO.
// Le o estado de cada caso e, com DRY_RUN=false, REABRE o caso existente (um unico
// clique por caso, sem retentativa) e confirma por releitura. Nunca abre caso novo.
// Sem URL/variaveis provisionadas, falha com exit 78.
import { evaluateSupportReopen, REOPEN_RE } from './support-reopen-decision.mjs';

const ids = [...new Set((process.env.CASE_IDS || '').split(',').map((s) => s.trim()).filter(Boolean))];
const template = process.env.CASE_URL_TEMPLATE || '';
const cdp = process.env.CDP_URL || 'http://127.0.0.1:9226';
const dryRun = String(process.env.DRY_RUN ?? 'true').toLowerCase() !== 'false';

if (!template.includes('{case_id}') || ids.length === 0) {
  console.error('NOT_PROVISIONED=AMAZON_SUPPORT_CASE_URL_TEMPLATE');
  process.exit(78);
}

let chromium;
for (const mod of ['playwright-core', 'playwright']) {
  try { ({ chromium } = await import(mod)); break; } catch { /* tenta o proximo */ }
}
if (!chromium) {
  console.error('NOT_PROVISIONED=playwright');
  process.exit(78);
}

async function readCase(page, id) {
  const url = page.url();
  const text = (await page.innerText('body').catch(() => '')).slice(0, 20000);
  const authenticated = !/\/ap\/signin|\/ap\/mfa|signin/i.test(url);
  return {
    text,
    authenticated,
    mfa_required: /\/ap\/mfa|two-step verification|verifica[cç][aã]o em duas etapas/i.test(url + ' ' + text.slice(0, 2000)),
    captcha_present: /captcha/i.test(text.slice(0, 4000)),
    read_back_ok: authenticated && text.includes(id),
    case_open: /\bstatus\s*[:\-]?\s*(open|aberto)\b/i.test(text),
    buttons: await page.$$eval('button, a[role="button"], kat-button', (els) =>
      els.map((e) => (e.innerText || e.getAttribute('label') || '').trim()).filter(Boolean).slice(0, 40)
    ).catch(() => []),
  };
}

const snapshots = [];
const browser = await chromium.connectOverCDP(cdp);
try {
  const context = browser.contexts()[0] || (await browser.newContext());
  for (const id of ids) {
    const snap = { case_id: id, read_back_ok: false, authenticated: false, mfa_required: false, captcha_present: false, case_open: false, buttons: [] };
    const page = await context.newPage();
    try {
      await page.goto(template.replace('{case_id}', id), { waitUntil: 'domcontentloaded', timeout: 30000 });
      await page.waitForTimeout(1500);
      const { text, ...state } = await readCase(page, id);
      Object.assign(snap, state);

      if (!dryRun && evaluateSupportReopen(snap, { dryRun: false }).status === 'REOPEN_ELIGIBLE') {
        snap.reopen_attempted = true;
        snap.reopen_clicked = false;
        snap.reopen_verified = false;
        try {
          await page.getByRole('button', { name: REOPEN_RE }).first().click({ timeout: 10000 });
          snap.reopen_clicked = true; // um unico clique; nao repetir em hipotese alguma
          await page.waitForTimeout(3000);
          await page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 });
          await page.waitForTimeout(1500);
          const after = await readCase(page, id);
          const stillOffersReopen = after.buttons.some((label) => REOPEN_RE.test(label));
          snap.reopen_verified = after.read_back_ok && (after.case_open || !stillOffersReopen);
        } catch (error) {
          snap.reopen_error = error?.name || 'Error';
        }
      }
    } catch (error) {
      snap.read_back_ok = false;
      snap.error_class = error?.name || 'Error';
    } finally {
      await page.close().catch(() => {});
    }
    snapshots.push(snap);
  }
} finally {
  await browser.close().catch(() => {});
}
console.log(JSON.stringify(snapshots));
