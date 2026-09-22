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
  rm -f /tmp/shopvivaliz-amazon-support-readback.mjs /tmp/shopvivaliz-support-readback-probe.out /tmp/shopvivaliz-amazon-support-readback-browser.pid
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
    >/tmp/shopvivaliz-amazon-support-readback-browser.log 2>&1 &
  echo $! >/tmp/shopvivaliz-amazon-support-readback-browser.pid
'
browser_pid="$(cat /tmp/shopvivaliz-amazon-support-readback-browser.pid)"
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
' >/tmp/shopvivaliz-support-readback-probe.out 2>&1; then
  echo 'SUPPORT_READBACK_PROBE={"status":"FAILED","reason":"PROBE_EXEC_FAILED"}'
  probe_sha="$(sha256sum /tmp/shopvivaliz-support-readback-probe.out | cut -c1-64)"
  printf 'SUPPORT_READBACK_PROBE_SHA256=%s\n' "$probe_sha"
  echo ERROR_CODE=SUPPORT_PROBE_FAILED
  exit 71
fi
if ! grep -q '"status":"OK"' /tmp/shopvivaliz-support-readback-probe.out \
  || ! grep -q '"auth_state":"AUTHENTICATED"' /tmp/shopvivaliz-support-readback-probe.out; then
  echo ERROR_CODE=SUPPORT_AUTH_CHECK_FAILED
  exit 71
fi
echo SELLER_CENTRAL_AUTH=AUTHENTICATED
echo SUPPORT_LOOKUP_PROBE=PASS

cat >/tmp/shopvivaliz-amazon-support-readback.mjs <<'NODE'
import { createHash } from 'node:crypto';

const CDP='http://127.0.0.1:9227';
const CASE_LOBBY='https://sellercentral.amazon.com.br/cu/case-lobby';
const cases=[
  {
    caseId:'22154699381',
    orderId:'',
    narrative:'Temos ciência de que o comprador já foi reembolsado no pedido 701-8413776-8628228. Nossa solicitação não se refere ao reembolso realizado ao comprador. Estamos solicitando o nosso ressarcimento como vendedores. Até o momento, não identificamos em nossa conta de vendedor o crédito correspondente a esse ressarcimento. Caso a Amazon considere que o ressarcimento já foi efetuado, solicitamos que informe o valor creditado em nossa conta de vendedor, a data do crédito, o ID da transação financeira e/ou o ID do ressarcimento, além do relatório ou evento financeiro em que esse crédito aparece. Enquanto esse crédito não puder ser identificado e conciliado em nossa conta de vendedor, consideramos o ressarcimento pendente.'
  },
  {
    caseId:'22153259501',
    orderId:'701-0172386-7380246',
    narrative:'Temos ciência de que o comprador já foi reembolsado no pedido 701-0172386-7380246. Nossa solicitação não se refere ao reembolso realizado ao comprador. Estamos solicitando o nosso ressarcimento como vendedores. Até o momento, não identificamos em nossa conta de vendedor o crédito correspondente a esse ressarcimento. Caso a Amazon considere que o ressarcimento já foi efetuado, solicitamos que informe o valor creditado em nossa conta de vendedor, a data do crédito, o ID da transação financeira e/ou o ID do ressarcimento, além do relatório ou evento financeiro em que esse crédito aparece. Enquanto esse crédito não puder ser identificado e conciliado em nossa conta de vendedor, consideramos o ressarcimento pendente.'
  }
];
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const sha256=value=>createHash('sha256').update(String(value??'')).digest('hex');
const decodeEntities=value=>String(value??'')
  .replace(/&nbsp;|&#160;/gi,' ')
  .replace(/&amp;/gi,'&')
  .replace(/&quot;/gi,'"')
  .replace(/&#39;|&apos;/gi,"'")
  .replace(/&lt;/gi,'<')
  .replace(/&gt;/gi,'>');
const normalize=value=>decodeEntities(value)
  .replace(/<br\s*\/?\s*>/gi,' ')
  .replace(/<[^>]+>/g,' ')
  .replace(/\u00a0/g,' ')
  .replace(/\s+/g,' ')
  .trim();

function collectStrings(value,path='$',out=[]){
  if(typeof value==='string'){
    out.push({path,value});
    return out;
  }
  if(Array.isArray(value)){
    value.forEach((item,index)=>collectStrings(item,path+'['+index+']',out));
    return out;
  }
  if(value&&typeof value==='object'){
    for(const [key,item] of Object.entries(value)){
      const safeKey=String(key).replace(/[^A-Za-z0-9_.-]/g,'_').slice(0,80);
      collectStrings(item,path+'.'+safeKey,out);
    }
  }
  return out;
}

function readBackEvidence(detail,narrative){
  const expected=normalize(narrative);
  const prefix=expected.slice(0,180);
  const strings=collectStrings(detail);
  let match=null;
  for(const entry of strings){
    const normalized=normalize(entry.value);
    if(normalized&&prefix&&normalized.includes(prefix)){
      match={scope:entry.path,normalized};
      break;
    }
  }
  const contacts=Array.isArray(detail?.contactList)?detail.contactList:[];
  if(!match){
    for(let index=0;index<contacts.length;index++){
      const combined=collectStrings(contacts[index]).map(entry=>normalize(entry.value)).filter(Boolean).join(' ');
      if(combined&&prefix&&combined.includes(prefix)){
        match={scope:'$.contactList['+index+']',normalized:combined};
        break;
      }
    }
  }
  return {
    found:Boolean(match),
    match_scope:match?.scope||null,
    expected_sha256:sha256(expected),
    matched_sha256:match?sha256(match.normalized):null,
    detail_sha256:sha256(JSON.stringify(detail)),
    contact_count:contacts.length,
    total_contacts:Number.isFinite(Number(detail?.totalNumberOfContacts))?Number(detail.totalNumberOfContacts):null
  };
}

async function connect(){
  const targets=await fetch(CDP+'/json').then(response=>response.json());
  const page=targets.find(target=>target.type==='page'&&target.webSocketDebuggerUrl);
  if(!page)throw new Error('NO_CDP_PAGE');
  const ws=new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve,reject)=>{ws.onopen=resolve;ws.onerror=reject});
  let seq=0;
  const pending=new Map();
  ws.onmessage=event=>{
    const message=JSON.parse(event.data);
    if(!message.id||!pending.has(message.id))return;
    const [resolve,reject]=pending.get(message.id);
    pending.delete(message.id);
    message.error?reject(new Error(message.error.message)):resolve(message.result);
  };
  const send=(method,params={})=>new Promise((resolve,reject)=>{
    const id=++seq;
    pending.set(id,[resolve,reject]);
    ws.send(JSON.stringify({id,method,params}));
  });
  const evalv=async expression=>(await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true})).result?.value;
  return {ws,send,evalv};
}

async function run(){
  const {ws,send,evalv}=await connect();
  await send('Page.navigate',{url:CASE_LOBBY});
  await sleep(3500);
  const href=String(await evalv('location.href'));
  if(/signin|ap\/signin|auth/i.test(href))throw new Error('AUTH_REQUIRED');

  let failures=0;
  for(const item of cases){
    try{
      const target=JSON.stringify(item.caseId);
      const searchCode='(async()=>{for(let page=0;page<10;page++){const r=await fetch("/hill/hillservice/mons-api/SearchForCases",{method:"POST",credentials:"include",headers:{"content-type":"application/json"},body:JSON.stringify({page,searchPageSize:50,sortBy:"CreationDate",sortByOrder:"DESC",getCountOnly:false,caseFilters:{caseOwner:"MerchantCases"}})});if(!r.ok)return JSON.stringify({error:"SEARCH_HTTP_"+r.status});const j=await r.json();const rows=Array.isArray(j.caseSearchResultList)?j.caseSearchResultList:[];const x=rows.find(v=>String(v.caseId||"")==='+target+');if(x)return JSON.stringify({caseId:String(x.caseId||""),status:String(x.status||""),lastOutboundReply:String(x.lastOutboundReply||"")});if(rows.length<50)break;}return JSON.stringify({error:"NOT_FOUND"})})()';
      const lookup=JSON.parse(await evalv(searchCode));
      if(lookup.error)throw new Error(item.caseId+':'+lookup.error);

      const detailCode='(async()=>{const r=await fetch("/hill/hillservice/mons-api/ViewCase?caseId="+encodeURIComponent('+target+')+"&timeZone=UTC&pageSize=10",{credentials:"include"});if(!r.ok)return JSON.stringify({error:"DETAIL_HTTP_"+r.status});return JSON.stringify(await r.json())})()';
      const detail=JSON.parse(await evalv(detailCode));
      if(detail.error)throw new Error(item.caseId+':'+detail.error);
      if(item.orderId && !JSON.stringify(detail).includes(item.orderId))throw new Error(item.caseId+':ORDER_IDENTITY_MISMATCH');
      const discoveredOrders=[...new Set((JSON.stringify(detail).match(/\\b\\d{3}-\\d{7}-\\d{7}\\b/g)||[]))].slice(0,10);

      const evidence=readBackEvidence(detail,item.narrative);
      const lastOutbound=normalize(lookup.lastOutboundReply);
      const result=evidence.found?'ALREADY_EXISTS':'NOT_CONFIRMED';
      const readBack=evidence.found===true;

      console.log(JSON.stringify({
        case_id:item.caseId,
        order_id:item.orderId,
        status:lookup.status,
        result,
        read_back:readBack,
        match_scope:evidence.match_scope,
        expected_sha256:evidence.expected_sha256,
        matched_sha256:evidence.matched_sha256,
        detail_sha256:evidence.detail_sha256,
        contact_count:evidence.contact_count,
        total_contacts:evidence.total_contacts,
        last_outbound_present:Boolean(lastOutbound),
        last_outbound_sha256:lastOutbound?sha256(lastOutbound):null,
        last_outbound_length:lastOutbound.length,
        discovered_orders:discoveredOrders
      }));
    }catch(error){
      failures++;
      console.log(JSON.stringify({
        case_id:item.caseId,
        order_id:item.orderId,
        status:'UNKNOWN',
        result:'ERROR',
        read_back:false,
        error:String(error?.message||error).replace(/[^A-Za-z0-9_:-]+/g,'_').slice(0,160)
      }));
    }
  }
  ws.close();
  if(failures>0)process.exitCode=1;
}

run().catch(error=>{
  console.error('ERROR_CODE='+String(error?.message||error).replace(/[^A-Za-z0-9_:-]+/g,'_').slice(0,120));
  process.exit(1);
});
NODE

"$NODE_BIN" /tmp/shopvivaliz-amazon-support-readback.mjs
