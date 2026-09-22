#!/usr/bin/env bash
set -Eeuo pipefail
test "$(hostname)" = "shopvivaliz-free-a1"
echo SITE_IDENTITY=PASS

if [[ "$(id -u)" -eq 0 ]]; then
  echo PRIVILEGE_MODE=ROOT
  sv_systemctl() { systemctl "$@"; }
  sv_as_ubuntu() { runuser -u ubuntu -g www-data -- bash -lc "$1"; }
else
  if ! sudo -n true; then
    echo ERROR_CODE=SUDO_UNAVAILABLE
    exit 70
  fi
  echo PRIVILEGE_MODE=SUDO
  sv_systemctl() { sudo -n systemctl "$@"; }
  sv_as_ubuntu() { sudo -n -u ubuntu -g www-data bash -lc "$1"; }
fi

browser_pid=""
cleanup() {
  if [[ -n "$browser_pid" ]] && kill -0 "$browser_pid" 2>/dev/null; then
    kill -TERM -- "-$browser_pid"
  fi
  rm -f /tmp/shopvivaliz-amazon-support-breakglass.mjs /tmp/shopvivaliz-support-probe.out
  sv_systemctl start amazon-returns-seller-central-browser.timer
}
trap cleanup EXIT

sv_systemctl stop amazon-returns-seller-central-browser.timer
service_state=""
for _ in $(seq 1 180); do
  if service_state="$(sv_systemctl is-active amazon-returns-seller-central-browser.service 2>/dev/null)"; then
    :
  else
    :
  fi
  case "$service_state" in
    inactive|failed) break ;;
  esac
  sleep 1
done
if service_state="$(sv_systemctl is-active amazon-returns-seller-central-browser.service 2>/dev/null)"; then
  :
else
  :
fi
if [[ "$service_state" != "inactive" && "$service_state" != "failed" ]]; then
  echo ERROR_CODE=SELLER_CENTRAL_SERVICE_BUSY
  exit 72
fi
CDP_URL="http://127.0.0.1:9227"
echo SYSTEMD_CONTROL=PASS

NODE_BIN=""
for candidate in /usr/local/bin/node /usr/bin/node; do
  if [[ -x "$candidate" ]] && "$candidate" -e 'process.exit(typeof WebSocket === "function" ? 0 : 1)' >/dev/null 2>&1; then
    NODE_BIN="$candidate"
    break
  fi
done
test -n "$NODE_BIN"

sv_as_ubuntu '
  set -Eeuo pipefail
  set -a
  . /home/ubuntu/amazon-returns-deploy/shared/seller-central-browser.env
  set +a
  setsid "$SELLER_CENTRAL_BROWSER" \
    --headless=new --no-sandbox --disable-gpu \
    --remote-debugging-address=127.0.0.1 --remote-debugging-port=9227 \
    "--user-data-dir=$SELLER_CENTRAL_PROFILE" \
    --no-first-run --no-default-browser-check about:blank \
    >/tmp/shopvivaliz-amazon-support-breakglass-browser.log 2>&1 &
  echo $! >/tmp/shopvivaliz-amazon-support-breakglass-browser.pid
'
browser_pid="$(cat /tmp/shopvivaliz-amazon-support-breakglass-browser.pid)"
[[ "$browser_pid" =~ ^[0-9]+$ ]]

ready=false
for _ in $(seq 1 40); do
  if curl -fsS --max-time 1 "$CDP_URL/json/version" >/dev/null 2>&1; then
    ready=true
    break
  fi
  sleep 0.5
done
test "$ready" = true

if ! sv_as_ubuntu '
  set -Eeuo pipefail
  set -a
  . /home/ubuntu/amazon-returns-deploy/shared/seller-central-browser.env
  set +a
  export SELLER_CENTRAL_CDP_URL=http://127.0.0.1:9227
  exec /usr/local/bin/node /home/ubuntu/amazon-returns-deploy/current/scripts/amazon-returns/seller-central-support-lookup-probe.mjs
' >/tmp/shopvivaliz-support-probe.out 2>&1; then
  probe_result=""
  if probe_result="$(grep -E '^\\{.*\"event\":\"support_lookup_probe\".*\\}$' /tmp/shopvivaliz-support-probe.out | tail -n 1)"; then
    :
  else
    probe_result='{"status":"FAILED","reason":"PROBE_NO_JSON_OUTPUT"}'
  fi
  printf 'SUPPORT_PROBE_RESULT=%s\\n' "$probe_result"
  probe_sha="$(sha256sum /tmp/shopvivaliz-support-probe.out | awk '{print $1}')"
  printf 'SUPPORT_PROBE_OUTPUT_SHA256=%s\\n' "$probe_sha"
  echo ERROR_CODE=SUPPORT_PROBE_FAILED
  exit 71
fi
if ! grep -q '"status":"OK"' /tmp/shopvivaliz-support-probe.out \
  || ! grep -q '"auth_state":"AUTHENTICATED"' /tmp/shopvivaliz-support-probe.out; then
  echo ERROR_CODE=SUPPORT_AUTH_CHECK_FAILED
  exit 71
fi
echo SELLER_CENTRAL_AUTH=AUTHENTICATED
echo SUPPORT_LOOKUP_PROBE=PASS

cat >/tmp/shopvivaliz-amazon-support-breakglass.mjs <<'NODE'
const CDP='http://127.0.0.1:9227';
const CASE_LOBBY='https://sellercentral.amazon.com.br/cu/case-lobby';
const cases=[
  {
    caseId:'22153077391',
    orderId:'701-8413776-8628228',
    narrative:'Temos ciência de que o comprador já foi reembolsado no pedido 701-8413776-8628228. Nossa solicitação não se refere ao reembolso realizado ao comprador. Estamos solicitando o nosso ressarcimento como vendedores. Até o momento, não identificamos em nossa conta de vendedor o crédito correspondente a esse ressarcimento. Caso a Amazon considere que o ressarcimento já foi efetuado, solicitamos que informe o valor creditado em nossa conta de vendedor, a data do crédito, o ID da transação financeira e/ou o ID do ressarcimento, além do relatório ou evento financeiro em que esse crédito aparece. Enquanto esse crédito não puder ser identificado e conciliado em nossa conta de vendedor, consideramos o ressarcimento pendente.'
  },
  {
    caseId:'22153259501',
    orderId:'701-0172386-7380246',
    narrative:'Temos ciência de que o comprador já foi reembolsado no pedido 701-0172386-7380246. Nossa solicitação não se refere ao reembolso realizado ao comprador. Estamos solicitando o nosso ressarcimento como vendedores. Até o momento, não identificamos em nossa conta de vendedor o crédito correspondente a esse ressarcimento. Caso a Amazon considere que o ressarcimento já foi efetuado, solicitamos que informe o valor creditado em nossa conta de vendedor, a data do crédito, o ID da transação financeira e/ou o ID do ressarcimento, além do relatório ou evento financeiro em que esse crédito aparece. Enquanto esse crédito não puder ser identificado e conciliado em nossa conta de vendedor, consideramos o ressarcimento pendente.'
  }
];
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
const safeError=value=>String(value||'UNKNOWN').replace(/[^A-Za-z0-9_:-]+/g,'_').slice(0,120);

async function connect(){
  const targets=await fetch(CDP+'/json').then(r=>r.json());
  const page=targets.find(x=>x.type==='page'&&x.webSocketDebuggerUrl);
  if(!page)throw new Error('NO_CDP_PAGE');
  const ws=new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((ok,no)=>{ws.onopen=ok;ws.onerror=no});
  let seq=0;const pending=new Map();
  ws.onmessage=e=>{const m=JSON.parse(e.data);if(!m.id||!pending.has(m.id))return;const [ok,no]=pending.get(m.id);pending.delete(m.id);m.error?no(new Error(m.error.message)):ok(m.result)};
  const send=(method,params={})=>new Promise((ok,no)=>{const id=++seq;pending.set(id,[ok,no]);ws.send(JSON.stringify({id,method,params}))});
  return {ws,send,evalv:async expression=>(await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true})).result?.value};
}

async function run(){
  const {ws,send,evalv}=await connect();
  const navigate=async (url,waitMs=3000)=>{await send('Page.navigate',{url});await sleep(waitMs)};
  await navigate(CASE_LOBBY);
  const href=String(await evalv('location.href'));
  if(/signin|ap\/signin|auth/i.test(href))throw new Error('AUTH_REQUIRED');

  let failures=0;
  for(const item of cases){
    try {
    const lookup=JSON.parse(await evalv(`(async()=>{for(let page=0;page<10;page++){const r=await fetch('/hill/hillservice/mons-api/SearchForCases',{method:'POST',credentials:'include',headers:{'content-type':'application/json'},body:JSON.stringify({page,searchPageSize:50,sortBy:'CreationDate',sortByOrder:'DESC',getCountOnly:false,caseFilters:{caseOwner:'MerchantCases'}})});if(!r.ok)return JSON.stringify({error:'SEARCH_HTTP_'+r.status});const j=await r.json();const rows=Array.isArray(j.caseSearchResultList)?j.caseSearchResultList:[];const x=rows.find(v=>String(v.caseId||'')===${JSON.stringify(item.caseId)});if(x)return JSON.stringify({caseId:String(x.caseId||''),status:String(x.status||''),shortDescription:String(x.shortDescription||'')});if(rows.length<50)break;}return JSON.stringify({error:'NOT_FOUND'})})()`));
    if(lookup.error)throw new Error(item.caseId+':'+lookup.error);
    const normalized=String(lookup.status||'').toUpperCase();
    const terminal=/RESOLVED|CLOSED|CANCELLED/.test(normalized);

    const rawDetail=await evalv(`(async()=>{const r=await fetch('/hill/hillservice/mons-api/ViewCase?caseId='+encodeURIComponent(${JSON.stringify(item.caseId)})+'&timeZone=UTC&pageSize=10',{credentials:'include'});if(!r.ok)return JSON.stringify({error:'DETAIL_HTTP_'+r.status});return JSON.stringify(await r.json())})()`);
    const detail=JSON.parse(rawDetail);
    if(detail.error)throw new Error(item.caseId+':'+detail.error);
    const serialized=JSON.stringify(detail);
    if(!serialized.includes(item.orderId))throw new Error(item.caseId+':ORDER_IDENTITY_MISMATCH');
    const canEdit=detail?.viewCaseMetaData?.canEditCase===true;
    const prefix=item.narrative.slice(0,180);
    if(serialized.includes(prefix)){
      console.log(JSON.stringify({case_id:item.caseId,order_id:item.orderId,status:lookup.status,result:'ALREADY_EXISTS',read_back:true}));
      continue;
    }

    await navigate('https://sellercentral.amazon.com.br/cu/case-dashboard/view-case?caseID='+encodeURIComponent(item.caseId),5000);

    if(terminal && !canEdit){
      const replyAvailable=Boolean(await evalv(`(()=>{for(const h of document.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.getAttribute('aria-label')||h.innerText||'').trim();if(!['Reply','Responder'].includes(label))continue;const b=h.tagName==='KAT-BUTTON'?(h.shadowRoot?.querySelector('button')||h):h;if(b&&!b.disabled)return true}return false})()`));
      if(!replyAvailable){
        const reopened=String(await evalv(`(()=>{for(const h of document.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.getAttribute('aria-label')||h.innerText||'').trim();if(!/^(Reopen case|Reopen Case|Reabrir caso|Reabrir)$/i.test(label))continue;const b=h.tagName==='KAT-BUTTON'?(h.shadowRoot?.querySelector('button')||h):h;if(b&&!b.disabled){b.click();return label}}return ''})()`)||'');
        if(!reopened)throw new Error(item.caseId+':TERMINAL_NOT_EDITABLE:'+normalized);
        await sleep(1200);
      }
    }

    let selector='';
    let triggered=false;
    const deadline=Date.now()+15000;
    while(Date.now()<deadline){
      selector=String(await evalv(`(()=>{const usable=h=>{if(!h||h.disabled===true||h.hasAttribute('disabled'))return false;const p=(h.getAttribute('placeholder')||'').toLowerCase();return !p.includes('feedback')};const k=[...document.querySelectorAll('kat-textarea')].find(usable);if(k)return 'kat-textarea';const n=[...document.querySelectorAll('textarea')].find(usable);return n?'textarea':''})()`)||'');
      if(selector)break;
      if(!triggered){
        const opened=String(await evalv(`(()=>{for(const h of document.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.getAttribute('aria-label')||h.innerText||'').trim();if(!['Reply','Responder'].includes(label))continue;const b=h.tagName==='KAT-BUTTON'?(h.shadowRoot?.querySelector('button')||h):h;if(b&&!b.disabled){b.click();return label}}return ''})()`)||'');
        if(opened)triggered=true;
      }
      await sleep(500);
    }
    if(!selector)throw new Error(item.caseId+':REPLY_ACTION_MISSING');

    const written=await evalv(`(()=>{const value=${JSON.stringify(item.narrative)};const host=[...document.querySelectorAll('kat-textarea')].find(h=>!h.disabled&&!h.hasAttribute('disabled'));if(host){const i=host.shadowRoot?.querySelector('textarea');if(!i)return false;const setter=Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value')?.set;if(!setter)return false;setter.call(i,value);i.dispatchEvent(new InputEvent('input',{bubbles:true,composed:true,inputType:'insertText',data:value}));i.dispatchEvent(new Event('change',{bubbles:true,composed:true}));return i.value===value}const i=[...document.querySelectorAll('textarea')].find(h=>!h.disabled&&!h.hasAttribute('disabled')&&!String(h.placeholder||'').toLowerCase().includes('feedback'));if(!i)return false;const setter=Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value')?.set;if(!setter)return false;setter.call(i,value);i.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'insertText',data:value}));i.dispatchEvent(new Event('change',{bubbles:true}));return i.value===value})()`);
    if(written!==true)throw new Error(item.caseId+':REPLY_NOT_WRITABLE');
    const sent=String(await evalv(`(()=>{for(const h of document.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.innerText||'').trim();if(!['Send','Send message','Reply','Enviar','Enviar mensagem','Responder'].includes(label))continue;const b=h.tagName==='KAT-BUTTON'?h.shadowRoot?.querySelector('button'):h;if(b&&!b.disabled){b.click();return label}}return ''})()`)||'');
    if(!sent)throw new Error(item.caseId+':SEND_ACTION_MISSING');

    let confirmed=false;
    for(let attempt=0;attempt<6;attempt++){
      await sleep(1500);
      const check=await evalv(`(async()=>{const r=await fetch('/hill/hillservice/mons-api/ViewCase?caseId='+encodeURIComponent(${JSON.stringify(item.caseId)})+'&timeZone=UTC&pageSize=10',{credentials:'include'});if(!r.ok)return false;const d=await r.json();return JSON.stringify(d).includes(${JSON.stringify(prefix)})})()`);
      if(check===true){confirmed=true;break}
    }
    if(!confirmed)throw new Error(item.caseId+':READ_BACK_FAILED');
    console.log(JSON.stringify({case_id:item.caseId,order_id:item.orderId,status:lookup.status,result:'SENT',read_back:true}));
    } catch(error) {
      failures++;
      console.log(JSON.stringify({case_id:item.caseId,order_id:item.orderId,status:'UNKNOWN',result:'ERROR',read_back:false,error:String(error?.message||error).slice(0,160)}));
    }
  }
  ws.close();
  if(failures>0) process.exitCode=1;
}

run().catch(error=>{
  console.error('ERROR_CODE='+safeError(error?.message));
  process.exit(1);
});
NODE

"$NODE_BIN" /tmp/shopvivaliz-amazon-support-breakglass.mjs
 /tmp/shopvivaliz-support-probe.out | tail -n 1)"; then
    :
  fi
  if [[ -n "$probe_result" ]]; then
    printf 'SUPPORT_PROBE_RESULT=%s\n' "$probe_result"
  else
    echo 'SUPPORT_PROBE_RESULT={"status":"FAILED","reason":"PROBE_NO_JSON_OUTPUT"}'
  fi
  probe_sha="$(sha256sum /tmp/shopvivaliz-support-probe.out | awk '{print $1}')"
  printf 'SUPPORT_PROBE_OUTPUT_SHA256=%s\n' "$probe_sha"
  echo ERROR_CODE=SUPPORT_PROBE_FAILED
  exit 71
fi
if ! grep -q '"status":"OK"' /tmp/shopvivaliz-support-probe.out \
  || ! grep -q '"auth_state":"AUTHENTICATED"' /tmp/shopvivaliz-support-probe.out; then
  echo ERROR_CODE=SUPPORT_AUTH_CHECK_FAILED
  exit 71
fi
echo SELLER_CENTRAL_AUTH=AUTHENTICATED
echo SUPPORT_LOOKUP_PROBE=PASS

cat >/tmp/shopvivaliz-amazon-support-breakglass.mjs <<'NODE'
const CDP='http://127.0.0.1:9227';
const CASE_LOBBY='https://sellercentral.amazon.com.br/cu/case-lobby';
const cases=[
  {
    caseId:'22153077391',
    orderId:'701-8413776-8628228',
    narrative:'Temos ciência de que o comprador já foi reembolsado no pedido 701-8413776-8628228. Nossa solicitação não se refere ao reembolso realizado ao comprador. Estamos solicitando o nosso ressarcimento como vendedores. Até o momento, não identificamos em nossa conta de vendedor o crédito correspondente a esse ressarcimento. Caso a Amazon considere que o ressarcimento já foi efetuado, solicitamos que informe o valor creditado em nossa conta de vendedor, a data do crédito, o ID da transação financeira e/ou o ID do ressarcimento, além do relatório ou evento financeiro em que esse crédito aparece. Enquanto esse crédito não puder ser identificado e conciliado em nossa conta de vendedor, consideramos o ressarcimento pendente.'
  },
  {
    caseId:'22153259501',
    orderId:'701-0172386-7380246',
    narrative:'Temos ciência de que o comprador já foi reembolsado no pedido 701-0172386-7380246. Nossa solicitação não se refere ao reembolso realizado ao comprador. Estamos solicitando o nosso ressarcimento como vendedores. Até o momento, não identificamos em nossa conta de vendedor o crédito correspondente a esse ressarcimento. Caso a Amazon considere que o ressarcimento já foi efetuado, solicitamos que informe o valor creditado em nossa conta de vendedor, a data do crédito, o ID da transação financeira e/ou o ID do ressarcimento, além do relatório ou evento financeiro em que esse crédito aparece. Enquanto esse crédito não puder ser identificado e conciliado em nossa conta de vendedor, consideramos o ressarcimento pendente.'
  }
];
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
const safeError=value=>String(value||'UNKNOWN').replace(/[^A-Za-z0-9_:-]+/g,'_').slice(0,120);

async function connect(){
  const targets=await fetch(CDP+'/json').then(r=>r.json());
  const page=targets.find(x=>x.type==='page'&&x.webSocketDebuggerUrl);
  if(!page)throw new Error('NO_CDP_PAGE');
  const ws=new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((ok,no)=>{ws.onopen=ok;ws.onerror=no});
  let seq=0;const pending=new Map();
  ws.onmessage=e=>{const m=JSON.parse(e.data);if(!m.id||!pending.has(m.id))return;const [ok,no]=pending.get(m.id);pending.delete(m.id);m.error?no(new Error(m.error.message)):ok(m.result)};
  const send=(method,params={})=>new Promise((ok,no)=>{const id=++seq;pending.set(id,[ok,no]);ws.send(JSON.stringify({id,method,params}))});
  return {ws,send,evalv:async expression=>(await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true})).result?.value};
}

async function run(){
  const {ws,send,evalv}=await connect();
  const navigate=async (url,waitMs=3000)=>{await send('Page.navigate',{url});await sleep(waitMs)};
  await navigate(CASE_LOBBY);
  const href=String(await evalv('location.href'));
  if(/signin|ap\/signin|auth/i.test(href))throw new Error('AUTH_REQUIRED');

  let failures=0;
  for(const item of cases){
    try {
    const lookup=JSON.parse(await evalv(`(async()=>{for(let page=0;page<10;page++){const r=await fetch('/hill/hillservice/mons-api/SearchForCases',{method:'POST',credentials:'include',headers:{'content-type':'application/json'},body:JSON.stringify({page,searchPageSize:50,sortBy:'CreationDate',sortByOrder:'DESC',getCountOnly:false,caseFilters:{caseOwner:'MerchantCases'}})});if(!r.ok)return JSON.stringify({error:'SEARCH_HTTP_'+r.status});const j=await r.json();const rows=Array.isArray(j.caseSearchResultList)?j.caseSearchResultList:[];const x=rows.find(v=>String(v.caseId||'')===${JSON.stringify(item.caseId)});if(x)return JSON.stringify({caseId:String(x.caseId||''),status:String(x.status||''),shortDescription:String(x.shortDescription||'')});if(rows.length<50)break;}return JSON.stringify({error:'NOT_FOUND'})})()`));
    if(lookup.error)throw new Error(item.caseId+':'+lookup.error);
    const normalized=String(lookup.status||'').toUpperCase();
    const terminal=/RESOLVED|CLOSED|CANCELLED/.test(normalized);

    const rawDetail=await evalv(`(async()=>{const r=await fetch('/hill/hillservice/mons-api/ViewCase?caseId='+encodeURIComponent(${JSON.stringify(item.caseId)})+'&timeZone=UTC&pageSize=10',{credentials:'include'});if(!r.ok)return JSON.stringify({error:'DETAIL_HTTP_'+r.status});return JSON.stringify(await r.json())})()`);
    const detail=JSON.parse(rawDetail);
    if(detail.error)throw new Error(item.caseId+':'+detail.error);
    const serialized=JSON.stringify(detail);
    if(!serialized.includes(item.orderId))throw new Error(item.caseId+':ORDER_IDENTITY_MISMATCH');
    const canEdit=detail?.viewCaseMetaData?.canEditCase===true;
    const prefix=item.narrative.slice(0,180);
    if(serialized.includes(prefix)){
      console.log(JSON.stringify({case_id:item.caseId,order_id:item.orderId,status:lookup.status,result:'ALREADY_EXISTS',read_back:true}));
      continue;
    }

    await navigate('https://sellercentral.amazon.com.br/cu/case-dashboard/view-case?caseID='+encodeURIComponent(item.caseId),5000);

    if(terminal && !canEdit){
      const replyAvailable=Boolean(await evalv(`(()=>{for(const h of document.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.getAttribute('aria-label')||h.innerText||'').trim();if(!['Reply','Responder'].includes(label))continue;const b=h.tagName==='KAT-BUTTON'?(h.shadowRoot?.querySelector('button')||h):h;if(b&&!b.disabled)return true}return false})()`));
      if(!replyAvailable){
        const reopened=String(await evalv(`(()=>{for(const h of document.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.getAttribute('aria-label')||h.innerText||'').trim();if(!/^(Reopen case|Reopen Case|Reabrir caso|Reabrir)$/i.test(label))continue;const b=h.tagName==='KAT-BUTTON'?(h.shadowRoot?.querySelector('button')||h):h;if(b&&!b.disabled){b.click();return label}}return ''})()`)||'');
        if(!reopened)throw new Error(item.caseId+':TERMINAL_NOT_EDITABLE:'+normalized);
        await sleep(1200);
      }
    }

    let selector='';
    let triggered=false;
    const deadline=Date.now()+15000;
    while(Date.now()<deadline){
      selector=String(await evalv(`(()=>{const usable=h=>{if(!h||h.disabled===true||h.hasAttribute('disabled'))return false;const p=(h.getAttribute('placeholder')||'').toLowerCase();return !p.includes('feedback')};const k=[...document.querySelectorAll('kat-textarea')].find(usable);if(k)return 'kat-textarea';const n=[...document.querySelectorAll('textarea')].find(usable);return n?'textarea':''})()`)||'');
      if(selector)break;
      if(!triggered){
        const opened=String(await evalv(`(()=>{for(const h of document.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.getAttribute('aria-label')||h.innerText||'').trim();if(!['Reply','Responder'].includes(label))continue;const b=h.tagName==='KAT-BUTTON'?(h.shadowRoot?.querySelector('button')||h):h;if(b&&!b.disabled){b.click();return label}}return ''})()`)||'');
        if(opened)triggered=true;
      }
      await sleep(500);
    }
    if(!selector)throw new Error(item.caseId+':REPLY_ACTION_MISSING');

    const written=await evalv(`(()=>{const value=${JSON.stringify(item.narrative)};const host=[...document.querySelectorAll('kat-textarea')].find(h=>!h.disabled&&!h.hasAttribute('disabled'));if(host){const i=host.shadowRoot?.querySelector('textarea');if(!i)return false;const setter=Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value')?.set;if(!setter)return false;setter.call(i,value);i.dispatchEvent(new InputEvent('input',{bubbles:true,composed:true,inputType:'insertText',data:value}));i.dispatchEvent(new Event('change',{bubbles:true,composed:true}));return i.value===value}const i=[...document.querySelectorAll('textarea')].find(h=>!h.disabled&&!h.hasAttribute('disabled')&&!String(h.placeholder||'').toLowerCase().includes('feedback'));if(!i)return false;const setter=Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value')?.set;if(!setter)return false;setter.call(i,value);i.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'insertText',data:value}));i.dispatchEvent(new Event('change',{bubbles:true}));return i.value===value})()`);
    if(written!==true)throw new Error(item.caseId+':REPLY_NOT_WRITABLE');
    const sent=String(await evalv(`(()=>{for(const h of document.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.innerText||'').trim();if(!['Send','Send message','Reply','Enviar','Enviar mensagem','Responder'].includes(label))continue;const b=h.tagName==='KAT-BUTTON'?h.shadowRoot?.querySelector('button'):h;if(b&&!b.disabled){b.click();return label}}return ''})()`)||'');
    if(!sent)throw new Error(item.caseId+':SEND_ACTION_MISSING');

    let confirmed=false;
    for(let attempt=0;attempt<6;attempt++){
      await sleep(1500);
      const check=await evalv(`(async()=>{const r=await fetch('/hill/hillservice/mons-api/ViewCase?caseId='+encodeURIComponent(${JSON.stringify(item.caseId)})+'&timeZone=UTC&pageSize=10',{credentials:'include'});if(!r.ok)return false;const d=await r.json();return JSON.stringify(d).includes(${JSON.stringify(prefix)})})()`);
      if(check===true){confirmed=true;break}
    }
    if(!confirmed)throw new Error(item.caseId+':READ_BACK_FAILED');
    console.log(JSON.stringify({case_id:item.caseId,order_id:item.orderId,status:lookup.status,result:'SENT',read_back:true}));
    } catch(error) {
      failures++;
      console.log(JSON.stringify({case_id:item.caseId,order_id:item.orderId,status:'UNKNOWN',result:'ERROR',read_back:false,error:String(error?.message||error).slice(0,160)}));
    }
  }
  ws.close();
  if(failures>0) process.exitCode=1;
}

run().catch(error=>{
  console.error('ERROR_CODE='+safeError(error?.message));
  process.exit(1);
});
NODE

"$NODE_BIN" /tmp/shopvivaliz-amazon-support-breakglass.mjs
