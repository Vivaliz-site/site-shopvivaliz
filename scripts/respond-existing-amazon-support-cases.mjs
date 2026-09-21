#!/usr/bin/env node
const CDP_BASE = process.env.SELLER_CENTRAL_CDP_URL || 'http://127.0.0.1:9225';
const CASES = [
  { caseId: '22153077391', orderId: '701-8413776-8628228' },
  { caseId: '22153259501', orderId: '701-0172386-7380246' },
];
const sleep = ms => new Promise(r => setTimeout(r, ms));

class Cdp {
  constructor(ws) {
    this.ws = ws;
    this.id = 0;
    this.pending = new Map();
    ws.onmessage = event => {
      const message = JSON.parse(event.data);
      if (!message.id || !this.pending.has(message.id)) return;
      const [resolve, reject] = this.pending.get(message.id);
      this.pending.delete(message.id);
      message.error ? reject(new Error(message.error.message || 'CDP error')) : resolve(message.result);
    };
  }
  send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = ++this.id;
      this.pending.set(id, [resolve, reject]);
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }
  async eval(expression) {
    const result = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error('browser expression failed');
    return result.result?.value;
  }
  async navigate(url, waitMs = 2500) {
    await this.send('Page.navigate', { url });
    await sleep(waitMs);
  }
}

async function connect() {
  const pages = await fetch(CDP_BASE + '/json').then(r => r.json());
  const page = pages.find(x => x.type === 'page' && x.webSocketDebuggerUrl);
  if (!page) throw new Error('NO_CDP_PAGE');
  const ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    ws.addEventListener('open', resolve, { once: true });
    ws.addEventListener('error', reject, { once: true });
  });
  return new Cdp(ws);
}

function narrative(orderId) {
  return 'No pedido ' + orderId + ', nossa solicitação é o ressarcimento devido à nossa conta de vendedor, e não o reembolso já realizado ao comprador. '
    + 'Até o momento, não identificamos o crédito correspondente em nossa conciliação financeira. '
    + 'Se a Amazon considerar o ressarcimento já efetuado, solicitamos informar o valor, a data, o ID da transação financeira e/ou o ID do ressarcimento, '
    + 'além do relatório ou evento financeiro em que esse crédito aparece. '
    + 'Solicitamos que o chamado permaneça em análise até que o crédito devido ao vendedor seja identificado e conciliado.';
}

async function verifyIdentity(cdp, caseId, orderId) {
  await cdp.navigate('https://sellercentral.amazon.com.br/cu/case-lobby', 1800);
  const raw = await cdp.eval(`(async()=>{const r=await fetch('/hill/hillservice/mons-api/ViewCase?caseId=${JSON.stringify(caseId)}&timeZone=UTC&pageSize=10',{credentials:'include'});if(!r.ok)return JSON.stringify({ok:false,http:r.status});const d=await r.json();return JSON.stringify({ok:true,containsOrder:JSON.stringify(d).includes(${JSON.stringify(orderId)})})})()`);
  const data = JSON.parse(raw || '{}');
  if (data.ok !== true || data.containsOrder !== true) throw new Error('CASE_IDENTITY_MISMATCH:' + caseId);
}

async function ensureComposer(cdp) {
  for (let attempt = 0; attempt < 20; attempt++) {
    const selector = await cdp.eval(`(()=>{const usable=h=>{if(!h||h.disabled===true||h.hasAttribute('disabled'))return false;const p=(h.getAttribute('placeholder')||'').toLowerCase();return !p.includes('feedback')};if([...document.querySelectorAll('kat-textarea')].find(usable))return 'kat-textarea';if([...document.querySelectorAll('textarea')].find(usable))return 'textarea';for(const h of document.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.getAttribute('aria-label')||h.innerText||'').trim();if(!['Reply','Responder'].includes(label))continue;const b=h.tagName==='KAT-BUTTON'?(h.shadowRoot?.querySelector('button')||h):h;if(b&&!b.disabled){b.click();break}}return ''})()`);
    if (selector) return selector;
    await sleep(350);
  }
  return '';
}

async function replyExisting(cdp, item) {
  const body = narrative(item.orderId);
  await verifyIdentity(cdp, item.caseId, item.orderId);
  await cdp.navigate('https://sellercentral.amazon.com.br/cu/case-dashboard/view-case?caseID=' + encodeURIComponent(item.caseId), 2500);

  const authState = await cdp.eval(`(()=>({href:location.href,text:(document.body?.innerText||'').slice(0,5000)}))()`);
  if (!authState || /signin|ap/signin|mfa|captcha/i.test(String(authState.href || ''))) throw new Error('AUTH_REQUIRED:' + item.caseId);

  const prefix = body.slice(0, 220);
  const already = await cdp.eval(`(document.body?.innerText||'').includes(${JSON.stringify(prefix)})`);
  if (already) {
    console.log('SUPPORT_REPLY_CONFIRMED case_id=' + item.caseId + ' status=ALREADY_EXISTS');
    return;
  }

  const selector = await ensureComposer(cdp);
  if (!selector) throw new Error('SUPPORT_REPLY_FIELD_MISSING:' + item.caseId);

  let written = false;
  if (selector === 'kat-textarea') {
    written = await cdp.eval(`(()=>{const h=[...document.querySelectorAll('kat-textarea')].find(x=>!x.disabled&&!x.hasAttribute('disabled'));const i=h?.shadowRoot?.querySelector('textarea');if(!h||!i)return false;const setter=Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value')?.set;if(!setter)return false;setter.call(i,${JSON.stringify(body)});i.dispatchEvent(new InputEvent('input',{bubbles:true,composed:true,inputType:'insertText',data:${JSON.stringify(body)}}));i.dispatchEvent(new Event('change',{bubbles:true,composed:true}));return i.value===${JSON.stringify(body)}})()`);
  } else {
    written = await cdp.eval(`(()=>{const i=[...document.querySelectorAll('textarea')].find(x=>!x.disabled&&!x.hasAttribute('disabled')&&!((x.getAttribute('placeholder')||'').toLowerCase().includes('feedback')));if(!i)return false;const setter=Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value')?.set;if(!setter)return false;setter.call(i,${JSON.stringify(body)});i.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'insertText',data:${JSON.stringify(body)}}));i.dispatchEvent(new Event('change',{bubbles:true}));return i.value===${JSON.stringify(body)}})()`);
  }
  if (!written) throw new Error('SUPPORT_REPLY_NOT_WRITABLE:' + item.caseId);

  const sent = await cdp.eval(`(()=>{for(const h of document.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.innerText||'').trim();if(!['Send','Send message','Reply','Enviar','Enviar mensagem','Responder'].includes(label))continue;const b=h.tagName==='KAT-BUTTON'?h.shadowRoot?.querySelector('button'):h;if(b&&!b.disabled){b.click();return label}}return ''})()`);
  if (!sent) throw new Error('SUPPORT_REPLY_SEND_MISSING:' + item.caseId);

  await sleep(3500);
  const confirmed = await cdp.eval(`(document.body?.innerText||'').includes(${JSON.stringify(prefix)})`);
  if (!confirmed) throw new Error('SUPPORT_REPLY_NOT_CONFIRMED:' + item.caseId);
  console.log('SUPPORT_REPLY_CONFIRMED case_id=' + item.caseId + ' status=ACCEPTED');
}

const cdp = await connect();
try {
  for (const item of CASES) await replyExisting(cdp, item);
} finally {
  try { cdp.ws.close(); } catch {}
}
