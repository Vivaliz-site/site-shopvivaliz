#!/usr/bin/env node
import { Cdp } from 'file:///home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs';

const CDP_BASE=(process.env.SHOPVIVALIZ_BROWSER_CDP_URL||'http://127.0.0.1:9559').replace(/\/$/,'');
const SESSION=String(process.env.SHOPVIVALIZ_BROWSER_SESSION_NAME||'dev');
const EXPECTED_EMAIL='dev@shopvivaliz.com.br';
const TIMEOUT_MS=Math.max(30000,Number(process.env.SHOPVIVALIZ_CHATGPT_DEV_TIMEOUT_MS||180000));
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const tidy=v=>String(v||'').replace(/\s+/g,' ').trim();

function validate(input){
  if(!input||typeof input!=='object'||Array.isArray(input)) throw new Error('invalid_request');
  if(tidy(input.model)!=='gpt-5.6-sol') throw new Error('invalid_model');
  if(tidy(input.effort).toLowerCase()!=='xhigh') throw new Error('invalid_effort');
  if(tidy(input.profile)!=='okx') throw new Error('invalid_profile');
  if(input.web_search===true) throw new Error('web_search_disabled');
  const prompt=String(input.prompt||'').trim();
  if(!prompt||prompt.length>120000) throw new Error('invalid_prompt');
  if(SESSION!=='dev'||CDP_BASE!=='http://127.0.0.1:9559') throw new Error('dev_session_required');
  return {model:'gpt-5.6-sol',effort:'xhigh',profile:'okx',prompt,web_search:false};
}

async function readStdin(){
  let raw='';
  for await(const chunk of process.stdin) raw+=chunk;
  return validate(JSON.parse(raw));
}

async function listTabs(){
  const response=await fetch(CDP_BASE+'/json',{signal:AbortSignal.timeout(2500)});
  if(!response.ok) throw new Error('dev_cdp_unavailable');
  return await response.json();
}

async function createHomeTab(){
  const response=await fetch(CDP_BASE+'/json/new?https://chatgpt.com/',{method:'PUT',signal:AbortSignal.timeout(2500)});
  if(!response.ok) throw new Error('dev_chatgpt_tab_create_failed');
  return await response.json();
}

async function connectTab(){
  const tabs=await listTabs();
  let tab=tabs.find(item=>{
    try{return item?.type==='page'&&new URL(String(item.url||'')).hostname==='chatgpt.com'&&item.webSocketDebuggerUrl}
    catch{return false}
  });
  if(!tab) tab=await createHomeTab();
  const ws=new WebSocket(tab.webSocketDebuggerUrl);
  await Promise.race([
    new Promise((resolve,reject)=>{
      ws.addEventListener('open',resolve,{once:true});
      ws.addEventListener('error',reject,{once:true});
    }),
    new Promise((_,reject)=>setTimeout(()=>reject(new Error('dev_chatgpt_websocket_timeout')),2500)),
  ]);
  const cdp=new Cdp(ws,{commandTimeoutMs:15000});
  await cdp.evaluate('true');
  return cdp;
}

async function authState(cdp){
  return await cdp.evaluate("(async()=>{try{const r=await fetch('/api/auth/session',{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(1800)});let s=null;try{s=await r.json()}catch{};const email=String(s?.user?.email||'').trim().toLowerCase();return {ok:r.ok,status:r.status,account:Boolean(s?.account),email_match:email==="+JSON.stringify(EXPECTED_EMAIL)+"};}catch{return {ok:false,status:0,account:false,email_match:false}}})()");
}

async function navigateHome(cdp,deadline){
  const state=await cdp.evaluate("({origin:location.origin,path:location.pathname})");
  if(state?.origin==='https://chatgpt.com'&&state?.path==='/') return;
  await cdp.evaluate("(()=>{location.assign('https://chatgpt.com/');return true})()");
  while(Date.now()<deadline){
    await sleep(250);
    const now=await cdp.evaluate("({origin:location.origin,path:location.pathname,ready:document.readyState})");
    if(now?.origin==='https://chatgpt.com'&&now?.path==='/'&&now?.ready==='complete') return;
  }
  throw new Error('dev_chatgpt_home_timeout');
}

async function trustedClick(cdp,target){
  const x=Number(target?.x),y=Number(target?.y);
  if(!Number.isFinite(x)||!Number.isFinite(y)) throw new Error('click_target_invalid');
  await cdp.send('Input.dispatchMouseEvent',{type:'mouseMoved',x,y,button:'none'});
  await cdp.send('Input.dispatchMouseEvent',{type:'mousePressed',x,y,button:'left',clickCount:1});
  await cdp.send('Input.dispatchMouseEvent',{type:'mouseReleased',x,y,button:'left',clickCount:1});
}

async function modelButton(cdp){
  return await cdp.evaluate("(()=>{const b=document.querySelector('button[aria-label=\"Select ChatGPT model\"],[role=button][aria-label=\"Select ChatGPT model\"]');if(!b)return null;const r=b.getBoundingClientRect();return {text:String(b.innerText||b.textContent||'').trim(),x:r.left+r.width/2,y:r.top+r.height/2,visible:r.width>0&&r.height>0}})()");
}

function isExtraHigh(value){
  const v=tidy(value).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  return v==='extra high'||v==='extra alto';
}

async function ensureExtraHigh(cdp){
  let button=await modelButton(cdp);
  if(button?.visible&&isExtraHigh(button.text)) return;
  if(!button?.visible) throw new Error('model_selector_unavailable');
  await trustedClick(cdp,button);
  await sleep(250);
  const candidates=await cdp.evaluate("(()=>{const tidy=v=>String(v||'').replace(/\\s+/g,' ').trim();const out=[];for(const e of document.querySelectorAll('button,[role=menuitem],[role=menuitemradio],[role=option],[role=radio]')){const label=tidy(e.innerText||e.textContent||e.getAttribute('aria-label')||'');if(!label)continue;const r=e.getBoundingClientRect();const style=getComputedStyle(e);out.push({label,x:r.left+r.width/2,y:r.top+r.height/2,visible:r.width>0&&r.height>0&&style.display!=='none'&&style.visibility!=='hidden',disabled:Boolean(e.disabled)||e.getAttribute('aria-disabled')==='true'});}return out})()");
  const target=(Array.isArray(candidates)?candidates:[]).find(row=>{
    const v=tidy(row?.label).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
    return row?.visible&&row?.disabled!==true&&(v==='extra high'||v==='extra alto'||v.startsWith('extra high ')||v.startsWith('extra alto '));
  });
  if(!target) throw new Error('extra_high_unavailable');
  await trustedClick(cdp,target);
  await sleep(350);
  button=await modelButton(cdp);
  if(!button?.visible||!isExtraHigh(button.text)) throw new Error('extra_high_not_selected');
}

async function composer(cdp){
  return await cdp.evaluate("(()=>{const e=document.querySelector('[data-testid=\"prompt-textarea\"]')||document.querySelector('[role=\"textbox\"][contenteditable=\"true\"]');if(!e)return null;const r=e.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2,visible:r.width>0&&r.height>0,text:String(e.innerText||e.textContent||'').trim()}})()");
}

async function waitComposer(cdp,deadline){
  while(Date.now()<deadline){
    const target=await composer(cdp);
    if(target?.visible) return target;
    await sleep(200);
  }
  throw new Error('composer_unavailable');
}

async function sendPrompt(cdp,prompt,deadline){
  const target=await waitComposer(cdp,Math.min(deadline,Date.now()+10000));
  if(target.text) throw new Error('composer_not_empty');
  await trustedClick(cdp,target);
  for(let i=0;i<prompt.length;i+=6000){
    await cdp.send('Input.insertText',{text:prompt.slice(i,i+6000)});
  }
  let send=null;
  while(Date.now()<Math.min(deadline,Date.now()+6000)){
    send=await cdp.evaluate("(()=>{const e=document.querySelector('[data-testid=\"prompt-textarea\"]')||document.querySelector('[role=\"textbox\"][contenteditable=\"true\"]');const form=e?.closest('form')||document;const b=form.querySelector('[data-testid=\"send-button\"],button[aria-label=\"Send\"],button[aria-label=\"Send prompt\"],button[aria-label=\"Send message\"],button[aria-label=\"Enviar\"],button[type=\"submit\"]');if(!b||b.disabled||b.getAttribute('aria-disabled')==='true')return null;const r=b.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2,visible:r.width>0&&r.height>0}})()");
    if(send?.visible) break;
    await sleep(150);
  }
  if(!send?.visible) throw new Error('send_button_unavailable');
  await trustedClick(cdp,send);
}

async function waitConversationId(cdp,deadline){
  while(Date.now()<deadline){
    const path=String(await cdp.evaluate('location.pathname')||'');
    const match=path.match(/^\/(?:c|uc)\/([A-Za-z0-9_-]{8,160})/);
    if(match) return match[1];
    await sleep(250);
  }
  throw new Error('conversation_id_timeout');
}

async function resultState(cdp,id){
  const expression="(async()=>{let session=null;try{session=await (await fetch('/api/auth/session',{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(1500)})).json()}catch{};const headers={Accept:'application/json'};const aid=String(session?.account?.id||'');const tok=String(session?.accessToken||session?.access_token||'');if(aid)headers['ChatGPT-Account-Id']=aid;if(tok)headers.Authorization='Bearer '+tok;let sr=null;try{sr=await fetch('/backend-api/conversation/'+encodeURIComponent("+JSON.stringify(id)+")+'/stream_status',{credentials:'same-origin',cache:'no-store',headers,signal:AbortSignal.timeout(2000)})}catch{};let sb=null;try{sb=await sr?.json()}catch{};const status=String(sb?.status||'');if(!sr?.ok||status!=='COMPLETE')return {complete:false,status};const r=await fetch('/backend-api/conversation/'+encodeURIComponent("+JSON.stringify(id)+"),{credentials:'same-origin',cache:'no-store',headers,signal:AbortSignal.timeout(4000)});let body=null;try{body=await r.json()}catch{};if(!r.ok)return {complete:false,status};const mapping=body?.mapping||{};let node=mapping[String(body?.current_node||'')];let message=node?.message||null;let hops=0;while(message&&String(message?.author?.role||'')!=='assistant'&&hops++<20){node=mapping[String(node?.parent||'')];message=node?.message||null;}const parts=Array.isArray(message?.content?.parts)?message.content.parts:[];const text=parts.map(p=>typeof p==='string'?p:(p&&typeof p==='object'?String(p.text||p.content||''):'')).join('').trim();const md=message?.metadata||{};return {complete:true,role:String(message?.author?.role||''),end_turn:message?.end_turn===true,text,resolved_model_slug:String(md.resolved_model_slug||md.model_slug||'')};})()";
  return await cdp.evaluate(expression);
}

function validResolvedModel(slug){
  const v=tidy(slug).toLowerCase();
  return v==='gpt-5-6-thinking'||v==='gpt-5-6-sol';
}

async function main(){
  const request=await readStdin();
  const deadline=Date.now()+TIMEOUT_MS;
  const cdp=await connectTab();
  try{
    const auth=await authState(cdp);
    if(!auth?.ok||!auth?.account) throw new Error('browser_auth_required');
    if(!auth?.email_match) throw new Error('browser_identity_mismatch');
    await navigateHome(cdp,deadline);
    await ensureExtraHigh(cdp);
    await sendPrompt(cdp,request.prompt,deadline);
    const id=await waitConversationId(cdp,Math.min(deadline,Date.now()+12000));
    while(Date.now()<deadline){
      await sleep(1000);
      const state=await resultState(cdp,id);
      if(!state?.complete) continue;
      if(state.role!=='assistant'||state.end_turn!==true||!state.text) throw new Error('assistant_response_incomplete');
      if(!validResolvedModel(state.resolved_model_slug)) throw new Error('model_mismatch');
      process.stdout.write(JSON.stringify({
        ok:true,
        text:state.text,
        model:'gpt-5.6-sol',
        effort:'xhigh',
        transport:'chatgpt_browser',
        profile:'okx',
        resolved_model_slug:state.resolved_model_slug,
      }));
      return;
    }
    throw new Error('browser_response_timeout');
  } finally {
    cdp.close();
  }
}

main().catch(error=>{
  const code=tidy(error?.message||error||'browser_failure').replace(/[^a-zA-Z0-9_:-]/g,'_').slice(0,120);
  process.stderr.write(code+'\n');
  process.exit(2);
});
