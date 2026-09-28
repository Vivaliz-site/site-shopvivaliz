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
  echo 'SUPPORT_PROBE_RESULT={"status":"FAILED","reason":"PROBE_EXEC_FAILED"}'
  probe_sha="$(sha256sum /tmp/shopvivaliz-support-probe.out | cut -c1-64)"
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
const legacyCases=[
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
const currentCases=[
  {
    caseId:'22153259501',
    orderId:'701-0172386-7380246',
    narrative:'Olá. Obrigado pelo retorno. Confirmamos que este caso se refere ao pedido 701-0172386-7380246 (ASIN B076PRVLPB). O valor de R$ 36,39 foi apontado em nossa conciliação como pendência, porém, diante da informação de que ele não corresponde aos registros do pedido, não queremos insistir em um valor possivelmente incorreto. Nossa solicitação é a apuração do valor correto do ressarcimento devido à nossa conta de vendedor. Temos ciência de que o comprador já foi reembolsado; não estamos solicitando novo reembolso ao comprador. Até o momento, não identificamos o crédito correspondente ao ressarcimento do seller. Não dispomos da captura original que exibia R$ 36,39. Por isso, pedimos que a análise seja feita com base nos registros oficiais do pedido e nos eventos financeiros da nossa conta. Caso a Amazon considere que o ressarcimento já foi efetuado, solicitamos o valor creditado, a data do crédito, o ID da transação e/ou do ressarcimento e o relatório ou evento financeiro em que o crédito aparece.'
  },
  {
    caseId:'22154699381',
    orderId:'',
    narrative:'Olá. Continuamos precisando de assistência no caso 22154699381. O motivo da nossa solicitação é o ressarcimento devido à nossa conta de vendedor: o comprador foi reembolsado, mas não identificamos o crédito correspondente ao seller. Não estamos questionando nem solicitando novo reembolso ao comprador. Na mensagem enviada pela Amazon neste caso, o número do pedido aparece em branco após "FBA Onsite:", por isso não conseguimos relacionar com segurança o protocolo a um pedido específico usando a informação recebida. Solicitamos que confirmem qual pedido está vinculado a este caso e façam a revisão financeira/logística correspondente. Não temos imagens adicionais do produto para anexar neste momento. Caso seja necessária alguma evidência específica, pedimos que indiquem exatamente qual documento ou tela deve ser fornecido. Se o ressarcimento do vendedor já tiver sido efetuado, solicitamos o valor, a data do crédito, o ID da transação e/ou do ressarcimento e o relatório ou evento financeiro em que o crédito aparece.'
  },
  {
    caseId:'22199842931',
    orderId:'702-9207715-8524262',
    narrative:'Olá Sara. Aqui é Fred, da ShopVivaLiz. Dando continuidade ao caso 22199842931, referente ao pedido 702-9207715-8524262, ASIN B0CF6R46NF. O problema permanece: o comprador foi reembolsado, mas não identificamos em nossa conta de vendedor o crédito correspondente ao ressarcimento FBA. Valor esperado do ressarcimento: R$ 485,75. Crédito efetivamente conciliado: R$ 0,00. Saldo pendente: R$ 485,75. Solicito a revisão manual do ressarcimento FBA e o pagamento do saldo devido ao vendedor. Caso a Amazon considere que o ressarcimento já foi efetuado, por favor informe o valor creditado, a data do crédito, o ID da transação financeira e/ou o ID do ressarcimento, além do relatório ou evento financeiro em que esse crédito aparece. Caso seja necessária alguma evidência adicional, peço que indiquem exatamente qual tela ou documento deve ser fornecido.'
  }
];
const profile=process.env.AMAZON_SUPPORT_REPLY_PROFILE==='current-tickets'?'current-tickets':'legacy-original';
const profileCases=profile==='current-tickets'?currentCases:legacyCases;
const requestedCaseIds=String(process.env.AMAZON_SUPPORT_REPLY_CASE_IDS||'').split(',').map(x=>x.trim()).filter(Boolean);
const requestedSet=new Set(requestedCaseIds);
const cases=requestedCaseIds.length?profileCases.filter(item=>requestedSet.has(item.caseId)):profileCases;
const unknownRequested=requestedCaseIds.filter(id=>!profileCases.some(item=>item.caseId===id));
const REQUIRED_CHANNEL=process.env.AMAZON_SUPPORT_REPLY_CHANNEL==='Email'?'Email':'Chat';
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
const safeError=value=>String(value||'UNKNOWN').replace(/[^A-Za-z0-9_:-]+/g,'_').slice(0,120);
const normalizeText=value=>String(value??'').replace(/<[^>]*>/g,' ').replace(/&nbsp;/gi,' ').replace(/&amp;/gi,'&').replace(/&quot;/gi,'"').replace(/&#39;/gi,"'").replace(/\s+/g,' ').trim().normalize('NFC').toLowerCase();
const collectStrings=(value,out=[])=>{if(value==null)return out;if(typeof value==='string')out.push(value);else if(Array.isArray(value))for(const item of value)collectStrings(item,out);else if(typeof value==='object')for(const item of Object.values(value))collectStrings(item,out);return out};

async function connect(){
  const targets=await fetch(CDP+'/json').then(r=>r.json());
  const page=targets.find(x=>x.type==='page'&&x.webSocketDebuggerUrl);
  if(!page)throw new Error('NO_CDP_PAGE');
  const ws=new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((ok,no)=>{ws.onopen=ok;ws.onerror=no});
  let seq=0;const pending=new Map();const networkTrace=[];
  ws.onmessage=e=>{const m=JSON.parse(e.data);if(!m.id){if(m.method==='Network.responseReceived'){try{const response=m.params?.response||{};const url=new URL(String(response.url||''));if(url.hostname==='sellercentral.amazon.com.br'&&/\/hill\/|\/cu\//.test(url.pathname)){networkTrace.push({path:url.pathname,status:Number(response.status)||0,mimeType:String(response.mimeType||'').slice(0,80)});if(networkTrace.length>40)networkTrace.shift()}}catch{}}return}if(!pending.has(m.id))return;const [ok,no]=pending.get(m.id);pending.delete(m.id);m.error?no(new Error(m.error.message)):ok(m.result)};
  const send=(method,params={})=>new Promise((ok,no)=>{const id=++seq;pending.set(id,[ok,no]);ws.send(JSON.stringify({id,method,params}))});
  return {ws,send,networkTrace,evalv:async expression=>(await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true})).result?.value};
}


async function findReplyControl(evalv){
  return await evalv(`(()=>{
    const norm=value=>String(value||'').replace(/\\s+/g,' ').trim();
    const nodes=[];const stack=[document];const seen=new Set();
    while(stack.length){
      const root=stack.pop();
      if(!root||seen.has(root))continue;
      seen.add(root);
      for(const el of root.children||[]){
        nodes.push(el);
        stack.push(el);
        if(el.shadowRoot)stack.push(el.shadowRoot);
      }
    }
    const metas=[];
    for(const h of nodes){
      if(!h||!['KAT-BUTTON','BUTTON'].includes(h.tagName))continue;
      const b=h.tagName==='KAT-BUTTON'?(h.shadowRoot?.querySelector('button')||h):h;
      if(!b)continue;
      const r=b.getBoundingClientRect?.();
      if(!r||r.width<=0||r.height<=0)continue;
      const labels=[h.getAttribute?.('label'),h.getAttribute?.('aria-label'),h.getAttribute?.('title'),h.innerText,b.getAttribute?.('aria-label'),b.getAttribute?.('title'),b.innerText].map(norm).filter(Boolean);
      const disabled=Boolean(h.disabled||h.hasAttribute?.('disabled')||b.disabled);
      metas.push({h,b,r,labels,disabled});
    }
    const chosen=metas.find(meta=>!meta.disabled&&meta.labels.some(label=>/^(reply|responder)$/i.test(label)));
    const candidates=metas.slice(0,30).map(meta=>({tag:meta.h.tagName,labels:[...new Set(meta.labels)].slice(0,5).map(value=>value.slice(0,80)),disabled:meta.disabled}));
    if(!chosen)return{rect:null,candidates};
    const b=chosen.b;b.scrollIntoView({block:'center',inline:'nearest'});const r=b.getBoundingClientRect();
    return{rect:{x:r.x,y:r.y,w:r.width,h:r.height,cx:r.x+r.width/2,cy:r.y+r.height/2,vw:innerWidth,vh:innerHeight,label:chosen.labels[0]||'Reply'},candidates};
  })()`);
}

async function run(){
  const {ws,send,evalv,networkTrace}=await connect();
  await send('Network.enable');
  const navigate=async (url,waitMs=3000)=>{await send('Page.navigate',{url});await sleep(waitMs)};
  const trustedClick=async rect=>{
    if(!rect||!Number.isFinite(rect.cx)||!Number.isFinite(rect.cy)||rect.cx<0||rect.cy<0||rect.cx>=rect.vw||rect.cy>=rect.vh)return false;
    await send('Input.dispatchMouseEvent',{type:'mouseMoved',x:rect.cx,y:rect.cy});
    await send('Input.dispatchMouseEvent',{type:'mousePressed',x:rect.cx,y:rect.cy,button:'left',clickCount:1});
    await send('Input.dispatchMouseEvent',{type:'mouseReleased',x:rect.cx,y:rect.cy,button:'left',clickCount:1});
    return true;
  };
  await navigate(CASE_LOBBY);
  const href=String(await evalv('location.href'));
  if(/signin|ap\/signin|auth/i.test(href))throw new Error('AUTH_REQUIRED');
  if(unknownRequested.length)throw new Error('UNAPPROVED_CASE_ID:'+unknownRequested.join(','));
  if(!cases.length)throw new Error('NO_APPROVED_CASES_SELECTED');
  if(!['Chat','Email'].includes(REQUIRED_CHANNEL))throw new Error('CHANNEL_POLICY_INVALID');

  let failures=0;
  for(const item of cases){
    try {
    const lookup=JSON.parse(await evalv(`(async()=>{for(let page=0;page<10;page++){const r=await fetch('/hill/hillservice/mons-api/SearchForCases',{method:'POST',credentials:'include',headers:{'content-type':'application/json'},body:JSON.stringify({page,searchPageSize:50,sortBy:'CreationDate',sortByOrder:'DESC',getCountOnly:false,caseFilters:{caseOwner:'MerchantCases'}})});if(!r.ok)return JSON.stringify({error:'SEARCH_HTTP_'+r.status});const j=await r.json();const rows=Array.isArray(j.caseSearchResultList)?j.caseSearchResultList:[];const x=rows.find(v=>String(v.caseId||'')===${JSON.stringify(item.caseId)});if(x)return JSON.stringify({caseId:String(x.caseId||''),status:String(x.status||''),shortDescription:String(x.shortDescription||'')});if(rows.length<50)break;}return JSON.stringify({error:'NOT_FOUND'})})()`));
    if(lookup.error)throw new Error(item.caseId+':'+lookup.error);
    const detailExpr="(async()=>{const r=await fetch('/hill/hillservice/mons-api/ViewCase?caseId='+encodeURIComponent("+JSON.stringify(item.caseId)+")+'&timeZone=UTC&pageSize=10',{credentials:'include'});if(!r.ok)return JSON.stringify({error:'DETAIL_HTTP_'+r.status});return JSON.stringify(await r.json())})()";
    const readDetail=async()=>JSON.parse(await evalv(detailExpr));
    const detail=await readDetail();
    if(detail.error)throw new Error(item.caseId+':'+detail.error);
    const detailText=normalizeText(collectStrings(detail).join(' '));
    if(item.orderId&&!detailText.includes(normalizeText(item.orderId)))throw new Error(item.caseId+':ORDER_IDENTITY_MISMATCH');
    const prefix=normalizeText(item.narrative.slice(0,180));
    const channelAlready=(Array.isArray(detail.contactList)?detail.contactList:[]).some(contact=>String(contact?.channelType||'').toUpperCase()===REQUIRED_CHANNEL.toUpperCase()&&normalizeText(collectStrings(contact).join(' ')).includes(prefix));
    if(channelAlready){
      console.log(JSON.stringify({case_id:item.caseId,order_id:item.orderId,status:detail?.viewCaseMetaData?.caseStatus||lookup.status,result:'ALREADY_EXISTS',read_back:true,match_scope:'ViewCase.contactList[channelType='+REQUIRED_CHANNEL.toUpperCase()+']',contact_count:Array.isArray(detail.contactList)?detail.contactList.length:null,total_contacts:detail.totalNumberOfContacts??null}));
      continue;
    }

    const channelsExpr="(async()=>{const r=await fetch('/hill/hillservice/mons-api/GetReplyChannels?caseId='+encodeURIComponent("+JSON.stringify(item.caseId)+"),{credentials:'include'});if(!r.ok)return JSON.stringify({error:'CHANNELS_HTTP_'+r.status});return JSON.stringify(await r.json())})()";
    const channels=JSON.parse(await evalv(channelsExpr));
    if(channels.error)throw new Error(item.caseId+':'+channels.error);
    if(REQUIRED_CHANNEL==='Email'){
      const emailChannel=(Array.isArray(channels.channels)?channels.channels:[]).find(channel=>String(channel?.type||'')==='Email');
      if(!emailChannel)throw new Error(item.caseId+':EMAIL_CHANNEL_MISSING');

      await navigate('https://sellercentral.amazon.com.br/cu/case-dashboard/view-case?caseID='+encodeURIComponent(item.caseId),5000);

      let emailTabPresent=Boolean(await evalv("Boolean(document.querySelector('kat-tab[tab-id=\\\"Email\\\"]'))"));
      if(!emailTabPresent){
        const replyProbe=await findReplyControl(evalv);
        if(!(await trustedClick(replyProbe?.rect||null))){
          console.log('REPLY_CONTROL_CANDIDATES='+JSON.stringify(replyProbe?.candidates||[]));
          throw new Error(item.caseId+':REPLY_ACTION_MISSING');
        }
        for(let attempt=0;attempt<20;attempt++){await sleep(250);emailTabPresent=Boolean(await evalv("Boolean(document.querySelector('kat-tab[tab-id=\\\"Email\\\"]'))"));if(emailTabPresent)break}
      }
      if(!emailTabPresent)throw new Error(item.caseId+':EMAIL_TAB_MISSING');

      const emailTabRect=await evalv("(()=>{const h=document.querySelector('kat-tab[tab-id=\\\"Email\\\"]')?.shadowRoot?.querySelector('[role=tab]');if(!h)return null;h.scrollIntoView({block:'center',inline:'nearest'});const r=h.getBoundingClientRect();return{x:r.x,y:r.y,w:r.width,h:r.height,cx:r.x+r.width/2,cy:r.y+r.height/2,vw:innerWidth,vh:innerHeight}})()");
      if(!(await trustedClick(emailTabRect)))throw new Error(item.caseId+':EMAIL_TAB_NOT_CLICKABLE');
      await sleep(300);
      let selectedEmail=String(await evalv("document.querySelector('kat-tab[tab-id=\\\"Email\\\"]')?.parentElement?.getAttribute('selected')||''"));
      if(selectedEmail!=='Email'){
        await evalv("(()=>{const e=document.querySelector('kat-tab[tab-id=\\\"Email\\\"]');const t=e?.parentElement;if(t&&typeof t._setSelected==='function'){t._setSelected('Email');return true}return false})()");
        await sleep(200);
        selectedEmail=String(await evalv("document.querySelector('kat-tab[tab-id=\\\"Email\\\"]')?.parentElement?.getAttribute('selected')||''"));
      }
      if(selectedEmail!=='Email')throw new Error(item.caseId+':EMAIL_TAB_NOT_SELECTED');

      const currentEmailDraft=String(await evalv("document.querySelector('kat-tab[tab-id=\\\"Email\\\"] kat-textarea')?.value||''"));
      if(currentEmailDraft&&currentEmailDraft!==item.narrative)throw new Error(item.caseId+':EMAIL_DRAFT_NOT_EMPTY');
      if(!currentEmailDraft){
        const focused=await evalv("(()=>{const h=document.querySelector('kat-tab[tab-id=\\\"Email\\\"] kat-textarea');const t=h?.shadowRoot?.querySelector('textarea');if(!t)return false;t.focus();return document.activeElement===h&&h.shadowRoot?.activeElement===t})()");
        if(focused!==true)throw new Error(item.caseId+':EMAIL_FOCUS_FAILED');
        await send('Input.insertText',{text:item.narrative});
        await sleep(250);
      }
      const exactEmailDraft=await evalv("(()=>{const h=document.querySelector('kat-tab[tab-id=\\\"Email\\\"] kat-textarea');const t=h?.shadowRoot?.querySelector('textarea');return Boolean(h&&t&&h.value==="+JSON.stringify(item.narrative)+"&&t.value==="+JSON.stringify(item.narrative)+")})()");
      if(exactEmailDraft!==true)throw new Error(item.caseId+':EMAIL_DRAFT_VERIFY_FAILED');

      networkTrace.length=0;
      const emailSendProbe=await evalv(`(()=>{
        const tab=document.querySelector('kat-tab[tab-id="Email"]');
        if(!tab)return{rect:null,candidates:[]};
        const norm=value=>String(value||'').replace(/\\s+/g,' ').trim();
        const nodes=[];const stack=[tab];const seen=new Set();
        while(stack.length){const root=stack.pop();if(!root||seen.has(root))continue;seen.add(root);for(const el of root.children||[]){nodes.push(el);stack.push(el);if(el.shadowRoot)stack.push(el.shadowRoot)}}
        const buttons=nodes.filter(el=>el?.tagName==='KAT-BUTTON'||el?.tagName==='BUTTON');
        const metas=[];
        for(const h of buttons){const b=h.tagName==='KAT-BUTTON'?(h.shadowRoot?.querySelector('button')||h):h;if(!b)continue;const r=b.getBoundingClientRect?.();if(!r||r.width<=0||r.height<=0)continue;const labels=[h.getAttribute?.('label'),h.getAttribute?.('aria-label'),h.getAttribute?.('title'),h.innerText,b.getAttribute?.('aria-label'),b.getAttribute?.('title'),b.innerText].map(norm).filter(Boolean);const type=norm(h.getAttribute?.('type')||b.getAttribute?.('type')).toLowerCase();const disabled=Boolean(h.disabled||h.hasAttribute?.('disabled')||b.disabled);metas.push({h,b,r,labels,type,disabled,variant:norm(h.getAttribute?.('variant'))})}
        const labelRe=/^(send|enviar|send email|enviar e-mail|enviar email)$/i;
        let chosen=metas.find(meta=>!meta.disabled&&meta.labels.some(label=>labelRe.test(label)));
        if(!chosen){const submit=metas.filter(meta=>!meta.disabled&&meta.type==='submit');if(submit.length===1)chosen=submit[0]}
        const candidates=metas.slice(0,20).map(meta=>({tag:meta.h.tagName,labels:[...new Set(meta.labels)].slice(0,4).map(value=>value.slice(0,60)),type:meta.type,variant:meta.variant,disabled:meta.disabled}));
        if(!chosen)return{rect:null,candidates};
        const b=chosen.b;b.scrollIntoView({block:'center',inline:'nearest'});const r=b.getBoundingClientRect();
        return{rect:{x:r.x,y:r.y,w:r.width,h:r.height,cx:r.x+r.width/2,cy:r.y+r.height/2,vw:innerWidth,vh:innerHeight,label:chosen.labels[0]||chosen.type||'Send'},candidates};
      })()`);
      const emailSendRect=emailSendProbe?.rect||null;
      if(!(await trustedClick(emailSendRect))){
        console.log('EMAIL_SEND_CANDIDATES='+JSON.stringify(emailSendProbe?.candidates||[]));
        throw new Error(item.caseId+':EMAIL_SEND_ACTION_MISSING');
      }
      console.log('EMAIL_SEND_CONTROL='+safeError(emailSendRect?.label||'Send'));

      let emailConfirmed=null;
      for(let attempt=0;attempt<30;attempt++){
        await sleep(1000);
        const checked=await readDetail();
        if(checked.error)continue;
        const emailMatch=(Array.isArray(checked.contactList)?checked.contactList:[]).find(contact=>String(contact?.channelType||'').toUpperCase()==='EMAIL'&&normalizeText(collectStrings(contact).join(' ')).includes(prefix));
        if(emailMatch){emailConfirmed={detail:checked,contact:emailMatch};break}
      }
      if(!emailConfirmed){console.log('EMAIL_SUBMIT_TRACE='+JSON.stringify(networkTrace.slice(-30)));throw new Error(item.caseId+':EMAIL_READ_BACK_FAILED')}
      console.log(JSON.stringify({case_id:item.caseId,order_id:item.orderId,status:emailConfirmed.detail?.viewCaseMetaData?.caseStatus||lookup.status,result:'SENT',read_back:true,match_scope:'ViewCase.contactList[channelType=EMAIL]',contact_count:Array.isArray(emailConfirmed.detail.contactList)?emailConfirmed.detail.contactList.length:null,total_contacts:emailConfirmed.detail.totalNumberOfContacts??null}));
      continue;
    }

    const chatChannel=(Array.isArray(channels.channels)?channels.channels:[]).find(channel=>String(channel?.type||'')===REQUIRED_CHANNEL);
    if(!chatChannel)throw new Error(item.caseId+':CHAT_CHANNEL_MISSING');
    const chatOptions=chatChannel?.metadata?.chatMetadata?.target?.options||{};
    if(chatOptions.disabled===true)throw new Error(item.caseId+':CHAT_DISABLED');
    if(chatOptions.withinHOOP!==true)throw new Error(item.caseId+':CHAT_OUTSIDE_HOURS');

    await navigate('https://sellercentral.amazon.com.br/cu/case-dashboard/view-case?caseID='+encodeURIComponent(item.caseId),5000);

    let chatTabPresent=Boolean(await evalv("Boolean(document.querySelector('kat-tab[tab-id=\"Chat\"]'))"));
    if(!chatTabPresent){
      const replyProbe=await findReplyControl(evalv);
      if(!(await trustedClick(replyProbe?.rect||null))){
        console.log('REPLY_CONTROL_CANDIDATES='+JSON.stringify(replyProbe?.candidates||[]));
        throw new Error(item.caseId+':REPLY_ACTION_MISSING');
      }
      for(let attempt=0;attempt<20;attempt++){await sleep(250);chatTabPresent=Boolean(await evalv("Boolean(document.querySelector('kat-tab[tab-id=\"Chat\"]'))"));if(chatTabPresent)break}
    }
    if(!chatTabPresent)throw new Error(item.caseId+':CHAT_TAB_MISSING');

    const chatTabRect=await evalv("(()=>{const h=document.querySelector('kat-tab[tab-id=\"Chat\"]')?.shadowRoot?.querySelector('[role=tab]');if(!h)return null;h.scrollIntoView({block:'center',inline:'nearest'});const r=h.getBoundingClientRect();return{x:r.x,y:r.y,w:r.width,h:r.height,cx:r.x+r.width/2,cy:r.y+r.height/2,vw:innerWidth,vh:innerHeight}})()");
    if(!(await trustedClick(chatTabRect)))throw new Error(item.caseId+':CHAT_TAB_NOT_CLICKABLE');
    await sleep(300);
    let selected=String(await evalv("document.querySelector('kat-tab[tab-id=\"Chat\"]')?.parentElement?.getAttribute('selected')||''"));
    if(selected!=='Chat'){
      await evalv("(()=>{const e=document.querySelector('kat-tab[tab-id=\"Chat\"]');const t=e?.parentElement;if(t&&typeof t._setSelected==='function'){t._setSelected('Chat');return true}return false})()");
      await sleep(200);
      selected=String(await evalv("document.querySelector('kat-tab[tab-id=\"Chat\"]')?.parentElement?.getAttribute('selected')||''"));
    }
    if(selected!=='Chat')throw new Error(item.caseId+':CHAT_TAB_NOT_SELECTED');

    const currentDraft=String(await evalv("document.querySelector('kat-tab[tab-id=\"Chat\"] kat-textarea')?.value||''"));
    if(currentDraft&&currentDraft!==item.narrative)throw new Error(item.caseId+':CHAT_DRAFT_NOT_EMPTY');
    if(!currentDraft){
      const focused=await evalv("(()=>{const h=document.querySelector('kat-tab[tab-id=\"Chat\"] kat-textarea');const t=h?.shadowRoot?.querySelector('textarea');if(!t)return false;t.focus();return document.activeElement===h&&h.shadowRoot?.activeElement===t})()");
      if(focused!==true)throw new Error(item.caseId+':CHAT_FOCUS_FAILED');
      await send('Input.insertText',{text:item.narrative});
      await sleep(250);
    }
    const exactDraft=await evalv("(()=>{const h=document.querySelector('kat-tab[tab-id=\"Chat\"] kat-textarea');const t=h?.shadowRoot?.querySelector('textarea');return Boolean(h&&t&&h.value==="+JSON.stringify(item.narrative)+"&&t.value==="+JSON.stringify(item.narrative)+")})()");
    if(exactDraft!==true)throw new Error(item.caseId+':CHAT_DRAFT_VERIFY_FAILED');
    const sellerName=String(await evalv("(()=>{const tab=document.querySelector('kat-tab[tab-id=\"Chat\"]');for(const h of tab?.querySelectorAll('kat-input')||[]){if(/Your name/i.test(h.parentElement?.innerText||''))return h.value||h.shadowRoot?.querySelector('input')?.value||''}return ''})()"));
    if(!sellerName.trim())throw new Error(item.caseId+':CHAT_NAME_MISSING');

    networkTrace.length=0;
    const sendProbe=await evalv(`(()=>{
      const tab=document.querySelector('kat-tab[tab-id="Chat"]');
      if(!tab)return{rect:null,candidates:[]};
      const norm=value=>String(value||'').replace(/\\s+/g,' ').trim();
      const nodes=[];
      const stack=[tab];
      const seen=new Set();
      while(stack.length){
        const root=stack.pop();
        if(!root||seen.has(root))continue;
        seen.add(root);
        for(const el of root.children||[]){
          nodes.push(el);
          stack.push(el);
          if(el.shadowRoot)stack.push(el.shadowRoot);
        }
      }
      const buttons=nodes.filter(el=>el?.tagName==='KAT-BUTTON'||el?.tagName==='BUTTON');
      const metas=[];
      for(const h of buttons){
        const b=h.tagName==='KAT-BUTTON'?(h.shadowRoot?.querySelector('button')||h):h;
        if(!b)continue;
        const r=b.getBoundingClientRect?.();
        if(!r||r.width<=0||r.height<=0)continue;
        const labels=[
          h.getAttribute?.('label'),
          h.getAttribute?.('aria-label'),
          h.getAttribute?.('title'),
          h.innerText,
          b.getAttribute?.('aria-label'),
          b.getAttribute?.('title'),
          b.innerText
        ].map(norm).filter(Boolean);
        const type=norm(h.getAttribute?.('type')||b.getAttribute?.('type')).toLowerCase();
        const disabled=Boolean(h.disabled||h.hasAttribute?.('disabled')||b.disabled);
        metas.push({h,b,r,labels,type,disabled,variant:norm(h.getAttribute?.('variant'))});
      }
      const labelRe=/^(send|enviar|send message|enviar mensagem|chat now)$/i;
      let chosen=metas.find(meta=>!meta.disabled&&meta.labels.some(label=>labelRe.test(label)));
      if(!chosen){
        const submit=metas.filter(meta=>!meta.disabled&&meta.type==='submit');
        if(submit.length===1)chosen=submit[0];
      }
      const candidates=metas.slice(0,20).map(meta=>({
        tag:meta.h.tagName,
        labels:[...new Set(meta.labels)].slice(0,4).map(value=>value.slice(0,60)),
        type:meta.type,
        variant:meta.variant,
        disabled:meta.disabled
      }));
      if(!chosen)return{rect:null,candidates};
      const b=chosen.b;
      b.scrollIntoView({block:'center',inline:'nearest'});
      const r=b.getBoundingClientRect();
      return{
        rect:{x:r.x,y:r.y,w:r.width,h:r.height,cx:r.x+r.width/2,cy:r.y+r.height/2,vw:innerWidth,vh:innerHeight,label:chosen.labels[0]||chosen.type||'Send'},
        candidates
      };
    })()`);
    const sendRect=sendProbe?.rect||null;
    if(!(await trustedClick(sendRect))){
      console.log('SEND_CANDIDATES='+JSON.stringify(sendProbe?.candidates||[]));
      throw new Error(item.caseId+':SEND_ACTION_MISSING');
    }
    console.log('SEND_CONTROL='+safeError(sendRect?.label||'Send'));

    let confirmed=null;
    for(let attempt=0;attempt<30;attempt++){
      await sleep(1000);
      const checked=await readDetail();
      if(checked.error)continue;
      const chatMatch=(Array.isArray(checked.contactList)?checked.contactList:[]).find(contact=>String(contact?.channelType||'').toUpperCase()==='CHAT'&&normalizeText(collectStrings(contact).join(' ')).includes(prefix));
      if(chatMatch){confirmed={detail:checked,contact:chatMatch};break}
    }
    if(!confirmed){console.log('SUBMIT_TRACE='+JSON.stringify(networkTrace.slice(-30)));throw new Error(item.caseId+':CHAT_READ_BACK_FAILED')}
    console.log(JSON.stringify({case_id:item.caseId,order_id:item.orderId,status:confirmed.detail?.viewCaseMetaData?.caseStatus||lookup.status,result:'SENT',read_back:true,match_scope:'ViewCase.contactList[channelType=CHAT]',contact_count:Array.isArray(confirmed.detail.contactList)?confirmed.detail.contactList.length:null,total_contacts:confirmed.detail.totalNumberOfContacts??null}));
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
