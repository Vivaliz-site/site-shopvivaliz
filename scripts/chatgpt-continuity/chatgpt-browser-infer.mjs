#!/usr/bin/env node
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const clean = value => String(value ?? '').replace(/\s+/g, ' ').trim();
const norm = value => clean(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

export function validateInferenceRequest(input) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) throw new Error('invalid_request');
  const model = clean(input.model);
  const effort = clean(input.effort).toLowerCase();
  const prompt = String(input.prompt ?? '').trim();
  if (model !== 'gpt-5.6-sol') throw new Error('invalid_model');
  if (effort !== 'xhigh') throw new Error('invalid_effort');
  if (!prompt || prompt.length > 120000) throw new Error('invalid_prompt');
  return { model, effort, prompt };
}

export function isExtraHighLabel(value) {
  const valueNorm = norm(value);
  return valueNorm === 'extra high' || valueNorm === 'extra alto' ||
    valueNorm.startsWith('extra high ') || valueNorm.startsWith('extra alto ');
}

export function isSolResolvedModel(value) {
  const valueNorm = norm(value).replace(/[._]/g, '-');
  return valueNorm === 'gpt-5-6-thinking' || valueNorm === 'gpt-5-6-sol';
}

export function chooseExtraHighCandidate(rows) {
  const matches = (Array.isArray(rows) ? rows : []).filter(row =>
    row && row.visible !== false && row.disabled !== true && isExtraHighLabel(row.label)
  );
  return matches.length === 1 ? matches[0] : null;
}

export function classifyChatgptReadyState(state) {
  if (!state || state.ready !== 'complete') return 'waiting';
  if (state.host === 'chatgpt.com') return state.composer ? 'ready' : 'session_check';
  if (state.host === 'auth.openai.com') return 'auth_required';
  return 'unexpected_origin';
}

function requireDevSession() {
  const session = clean(process.env.SHOPVIVALIZ_BROWSER_SESSION_NAME || '');
  const cdpBase = clean(process.env.SHOPVIVALIZ_BROWSER_CDP_URL || '').replace(/\/+$/, '');
  if (session !== 'dev') throw new Error('dev_session_required');
  if (cdpBase !== 'http://127.0.0.1:9559') throw new Error('dev_cdp_required');
  return { session, cdpBase, expectedEmail: 'dev@shopvivaliz.com.br' };
}

async function fetchJson(url, options = {}, timeoutMs = 4000) {
  const response = await fetch(url, { ...options, signal: AbortSignal.timeout(timeoutMs) });
  if (!response.ok) throw new Error('http_' + response.status);
  return await response.json();
}

async function openChatgptTab(cdpBase) {
  const url = cdpBase + '/json/new?' + encodeURIComponent('https://chatgpt.com/');
  return await fetchJson(url, { method: 'PUT' }, 5000);
}

async function closeTab(cdpBase, id) {
  if (!id) return;
  try {
    await fetch(cdpBase + '/json/close/' + encodeURIComponent(id), { signal: AbortSignal.timeout(2500) });
  } catch {}
}

async function connectTab(tab) {
  const workerModule = process.env.SHOPVIVALIZ_BROWSER_WORKER_MODULE || '/home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs';
  const { Cdp } = await import('file://' + workerModule);
  if (!tab?.webSocketDebuggerUrl) throw new Error('tab_websocket_missing');
  const ws = new WebSocket(tab.webSocketDebuggerUrl);
  await Promise.race([
    new Promise((resolve, reject) => {
      ws.addEventListener('open', resolve, { once: true });
      ws.addEventListener('error', reject, { once: true });
    }),
    new Promise((_, reject) => setTimeout(() => reject(new Error('websocket_open_timeout')), 3000)),
  ]);
  return new Cdp(ws, { commandTimeoutMs: 15000 });
}

async function waitChatgptReady(cdp, deadline) {
  while (Date.now() < deadline) {
    const state = await cdp.evaluate(
      "(()=>({host:location.hostname,path:location.pathname,ready:document.readyState,composer:!!(document.querySelector('[data-testid=\"prompt-textarea\"]')||document.querySelector('[role=\"textbox\"][contenteditable=\"true\"]'))}))()"
    ).catch(() => null);
    const classification = classifyChatgptReadyState(state);
    if (classification === 'ready' || classification === 'session_check') return state;
    if (classification === 'auth_required') throw new Error('browser_auth_required');
    if (classification === 'unexpected_origin') throw new Error('unexpected_browser_origin');
    await sleep(250);
  }
  throw new Error('chatgpt_ready_timeout');
}

async function verifySession(cdp, expectedEmail) {
  const expression = "(async()=>{try{const r=await fetch('/api/auth/session',{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(2500)});let s=null;try{s=await r.json()}catch{};const email=String(s?.user?.email||'').trim().toLowerCase();return {ok:r.ok,status:r.status,account:Boolean(s?.account),identity_match:email===" +
    JSON.stringify(expectedEmail) + "};}catch{return {ok:false,status:0,account:false,identity_match:false}}})()";
  const state = await cdp.evaluate(expression);
  if (!state?.ok || !state?.account) throw new Error('browser_auth_required');
  if (!state?.identity_match) throw new Error('browser_identity_mismatch');
}

async function waitComposerReady(cdp, deadline) {
  while (Date.now() < deadline) {
    const ready = await cdp.evaluate(
      "(()=>!!(document.querySelector('[data-testid=\"prompt-textarea\"]')||document.querySelector('[role=\"textbox\"][contenteditable=\"true\"]')))()"
    ).catch(() => false);
    if (ready) return;
    await sleep(250);
  }
  throw new Error('composer_ready_timeout');
}

async function trustedClick(cdp, target) {
  const x = Number(target?.x);
  const y = Number(target?.y);
  if (!Number.isFinite(x) || !Number.isFinite(y)) throw new Error('invalid_click_target');
  await cdp.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y, button: 'none' });
  await cdp.send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
  await cdp.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
}

async function ensureTemporaryChat(cdp) {
  const state = await cdp.evaluate(
    "(()=>{const tidy=v=>String(v||'').replace(/\\s+/g,' ').trim();const rows=[...document.querySelectorAll('button,[role=\"button\"]')].map((e,i)=>{const r=e.getBoundingClientRect();const label=tidy(e.getAttribute('aria-label')||e.innerText||e.textContent||'');return {i,label,pressed:e.getAttribute('aria-pressed'),x:r.left+r.width/2,y:r.top+r.height/2,visible:r.width>0&&r.height>0,disabled:!!e.disabled||e.getAttribute('aria-disabled')==='true'};});return rows;})()"
  );
  const active = (state || []).find(row => {
    const n = norm(row.label);
    return row.visible && (row.pressed === 'true' || n.includes('exit temporary chat') || n.includes('sair do chat tempor'));
  });
  if (active) return;
  const candidates = (state || []).filter(row => {
    const n = norm(row.label);
    return row.visible && !row.disabled && (n === 'temporary chat' || n === 'chat temporario');
  });
  if (candidates.length !== 1) throw new Error('temporary_chat_unavailable');
  await trustedClick(cdp, candidates[0]);
  await sleep(500);
  const verified = await cdp.evaluate(
    "(()=>[...document.querySelectorAll('button,[role=\"button\"]')].some(e=>{const t=String(e.getAttribute('aria-label')||e.innerText||'').normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').toLowerCase();return e.getAttribute('aria-pressed')==='true'&&t.includes('temporary')||t.includes('exit temporary chat')||t.includes('sair do chat tempor')}))()"
  );
  if (!verified) throw new Error('temporary_chat_not_selected');
}

async function modelSelector(cdp) {
  return await cdp.evaluate(
    "(()=>{const b=document.querySelector('button[aria-label=\"Select ChatGPT model\"],[role=\"button\"][aria-label=\"Select ChatGPT model\"]');if(!b)return null;const r=b.getBoundingClientRect();return {label:String(b.innerText||b.textContent||'').replace(/\\s+/g,' ').trim(),x:r.left+r.width/2,y:r.top+r.height/2,visible:r.width>0&&r.height>0};})()"
  );
}

async function ensureExtraHigh(cdp) {
  const selector = await modelSelector(cdp);
  if (!selector?.visible) throw new Error('model_selector_unavailable');
  if (isExtraHighLabel(selector.label)) return;
  await trustedClick(cdp, selector);
  await sleep(350);
  const rows = await cdp.evaluate(
    "(()=>{const tidy=v=>String(v||'').replace(/\\s+/g,' ').trim();const out=[];for(const e of document.querySelectorAll('button,[role=\"menuitem\"],[role=\"menuitemradio\"],[role=\"option\"],[role=\"radio\"],[data-radix-collection-item]')){const r=e.getBoundingClientRect();const s=getComputedStyle(e);const label=tidy(e.innerText||e.textContent||e.getAttribute('aria-label')||'');if(!label)continue;out.push({label,x:r.left+r.width/2,y:r.top+r.height/2,visible:r.width>0&&r.height>0&&s.display!=='none'&&s.visibility!=='hidden',disabled:!!e.disabled||e.getAttribute('aria-disabled')==='true'});}return out;})()"
  );
  const target = chooseExtraHighCandidate(rows);
  if (!target) throw new Error('extra_high_unavailable');
  await trustedClick(cdp, target);
  await sleep(550);
  const verified = await cdp.evaluate(
    "(()=>{const tidy=v=>String(v||'').replace(/\\s+/g,' ').trim().normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').toLowerCase();const b=document.querySelector('button[aria-label=\"Select ChatGPT model\"],[role=\"button\"][aria-label=\"Select ChatGPT model\"]');if(b&&((tidy(b.innerText).startsWith('extra high'))||(tidy(b.innerText).startsWith('extra alto'))))return true;return [...document.querySelectorAll('[role=\"menuitemradio\"],[role=\"radio\"],[role=\"option\"]')].some(e=>{const t=tidy(e.innerText||e.textContent||e.getAttribute('aria-label')||'');const selected=e.getAttribute('aria-checked')==='true'||e.getAttribute('aria-selected')==='true'||e.getAttribute('data-state')==='checked';return selected&&(t.startsWith('extra high')||t.startsWith('extra alto'));});})()"
  );
  if (!verified) throw new Error('extra_high_not_selected');
}

async function sendPrompt(cdp, prompt) {
  const target = await cdp.evaluate(
    "(()=>{const e=document.querySelector('[data-testid=\"prompt-textarea\"]')||document.querySelector('[role=\"textbox\"][contenteditable=\"true\"]');if(!e)return null;const r=e.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2,visible:r.width>0&&r.height>0,text:String(e.innerText||e.textContent||'').trim()};})()"
  );
  if (!target?.visible) throw new Error('composer_unavailable');
  if (target.text) throw new Error('composer_not_empty');
  await trustedClick(cdp, target);
  for (let offset = 0; offset < prompt.length; offset += 4000) {
    await cdp.send('Input.insertText', { text: prompt.slice(offset, offset + 4000) });
  }
  await sleep(250);
  const send = await cdp.evaluate(
    "(()=>{const e=document.querySelector('[data-testid=\"prompt-textarea\"]')||document.querySelector('[role=\"textbox\"][contenteditable=\"true\"]');const root=e?.closest('form')||document;const b=root.querySelector('[data-testid=\"send-button\"],button[aria-label=\"Send\"],button[aria-label=\"Send prompt\"],button[aria-label=\"Send message\"],button[aria-label=\"Enviar\"],button[type=\"submit\"]');if(!b||b.disabled||b.getAttribute('aria-disabled')==='true')return null;const r=b.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2,visible:r.width>0&&r.height>0};})()"
  );
  if (!send?.visible) throw new Error('send_button_unavailable');
  await trustedClick(cdp, send);
}

async function waitConversationId(cdp, deadline) {
  while (Date.now() < deadline) {
    const path = String(await cdp.evaluate('location.pathname').catch(() => '') || '');
    const match = path.match(/^\/(?:c|uc)\/([A-Za-z0-9_-]{8,160})/);
    if (match) return match[1];
    await sleep(250);
  }
  throw new Error('conversation_id_timeout');
}

async function readConversation(cdp, id) {
  const expression = "(async()=>{let session=null;try{session=await (await fetch('/api/auth/session',{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(1500)})).json()}catch{};const headers={Accept:'application/json'};const aid=String(session?.account?.id||'');const tok=String(session?.accessToken||session?.access_token||'');if(aid)headers['ChatGPT-Account-Id']=aid;if(tok)headers.Authorization='Bearer '+tok;let r;try{r=await fetch('/backend-api/conversation/'+encodeURIComponent(" +
    JSON.stringify(id) + "),{credentials:'same-origin',cache:'no-store',headers,signal:AbortSignal.timeout(4000)})}catch{return {ready:false}};let body=null;try{body=await r.json()}catch{};if(!r.ok||!body)return {ready:false,http_status:r.status};const mapping=body.mapping||{};let node=mapping[String(body.current_node||'')];let msg=node?.message||null;let hops=0;while(msg&&String(msg?.author?.role||'')!=='assistant'&&hops++<20){node=mapping[String(node?.parent||'')];msg=node?.message||null}const parts=Array.isArray(msg?.content?.parts)?msg.content.parts:[];const text=parts.map(p=>typeof p==='string'?p:(p&&typeof p==='object'?String(p.text||p.content||''):'')).join('').trim();const md=msg?.metadata||{};let web_used=false;for(const n of Object.values(mapping)){const m=n?.message;if(!m)continue;const probe=JSON.stringify({author:m.author||{},recipient:m.recipient||'',metadata:m.metadata||{}}).toLowerCase();if(probe.includes('web.run')||probe.includes('web_search')||probe.includes('search_query'))web_used=true;}return {ready:msg?.end_turn===true&&Boolean(text),role:String(msg?.author?.role||''),text,resolved_model_slug:String(md.resolved_model_slug||md.model_slug||''),web_used};})()";
  return await cdp.evaluate(expression);
}

async function infer(request) {
  const cfg = requireDevSession();
  const tab = await openChatgptTab(cfg.cdpBase);
  let cdp = null;
  try {
    cdp = await connectTab(tab);
    const deadline = Date.now() + 210000;
    const readyState = await waitChatgptReady(cdp, Math.min(deadline, Date.now() + 20000));
    await verifySession(cdp, cfg.expectedEmail);
    if (!readyState?.composer) {
      await waitComposerReady(cdp, Math.min(deadline, Date.now() + 10000));
    }
    await ensureTemporaryChat(cdp);
    await ensureExtraHigh(cdp);
    await sendPrompt(cdp, request.prompt);
    const conversationId = await waitConversationId(cdp, Math.min(deadline, Date.now() + 15000));
    while (Date.now() < deadline) {
      await sleep(1500);
      const result = await readConversation(cdp, conversationId);
      if (!result?.ready) continue;
      if (result.role !== 'assistant') throw new Error('assistant_response_invalid');
      if (result.web_used) throw new Error('web_search_used');
      if (!isSolResolvedModel(result.resolved_model_slug)) throw new Error('model_mismatch');
      return {
        ok: true,
        text: result.text,
        model: 'gpt-5.6-sol',
        effort: 'xhigh',
        transport: 'chatgpt_browser',
        profile: 'dev',
        resolved_model_slug: result.resolved_model_slug,
      };
    }
    throw new Error('browser_response_timeout');
  } finally {
    try { cdp?.close(); } catch {}
    await closeTab(cfg.cdpBase, tab?.id);
  }
}

async function readStdin() {
  let raw = '';
  for await (const chunk of process.stdin) raw += chunk;
  return raw;
}

function isDirectInvocation() {
  try {
    return new URL(import.meta.url).pathname === process.argv[1];
  } catch {
    return false;
  }
}

if (isDirectInvocation()) {
  try {
    const raw = await readStdin();
    const request = validateInferenceRequest(JSON.parse(raw));
    const result = await infer(request);
    process.stdout.write(JSON.stringify(result));
  } catch (error) {
    process.stdout.write(JSON.stringify({ ok: false, error: clean(error?.message || error || 'browser_infer_failed') }));
    process.exitCode = 2;
  }
}
