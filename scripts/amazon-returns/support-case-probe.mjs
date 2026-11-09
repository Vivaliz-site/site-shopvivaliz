#!/usr/bin/env node
// Probe SOMENTE LEITURA de casos do Amazon Seller Support via CDP em perfil efemero.
// Nunca clica, nunca digita, nunca envia. Emite um array JSON de snapshots em stdout
// para support-reopen-decision.mjs. Sem URL/variaveis provisionadas, falha com exit 78.
const ids = (process.env.CASE_IDS || '').split(',').map((s) => s.trim()).filter(Boolean);
const template = process.env.CASE_URL_TEMPLATE || '';
const cdp = process.env.CDP_URL || 'http://127.0.0.1:9226';

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
      const url = page.url();
      const text = (await page.innerText('body').catch(() => '')).slice(0, 20000);
      snap.authenticated = !/\/ap\/signin|\/ap\/mfa|signin/i.test(url);
      snap.mfa_required = /\/ap\/mfa|two-step verification|verifica[cç][aã]o em duas etapas/i.test(url + ' ' + text.slice(0, 2000));
      snap.captcha_present = /captcha/i.test(text.slice(0, 4000));
      snap.read_back_ok = snap.authenticated && text.includes(id);
      snap.case_open = /\bstatus\s*[:\-]?\s*(open|aberto)\b/i.test(text);
      snap.buttons = await page.$$eval('button, a[role="button"], kat-button', (els) =>
        els.map((e) => (e.innerText || e.getAttribute('label') || '').trim()).filter(Boolean).slice(0, 40)
      ).catch(() => []);
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
