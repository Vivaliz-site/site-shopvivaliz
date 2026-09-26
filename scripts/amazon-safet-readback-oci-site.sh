#!/usr/bin/env bash
set -Eeuo pipefail

test "$(hostname)" = "shopvivaliz-free-a1"
echo SAFE_T_READBACK_BEGIN

CLAIM_ID="52214-19729-8255607"
ORDER_ID="701-4306982-6000233"
CDP_URL="http://127.0.0.1:9227"
CLAIM_URL="https://sellercentral.amazon.com.br/safet-claims/claim/52214-19729-8255607"

if [[ "$(id -u)" -eq 0 ]]; then
  sv_systemctl() { systemctl "$@"; }
  sv_as_ubuntu() { runuser -u ubuntu -g www-data -- bash -lc "$1"; }
else
  sudo -n true
  sv_systemctl() { sudo -n systemctl "$@"; }
  sv_as_ubuntu() { sudo -n -u ubuntu -g www-data bash -lc "$1"; }
fi

browser_pid=""
cleanup() {
  if [[ -n "$browser_pid" ]] && kill -0 "$browser_pid" 2>/dev/null; then
    kill -TERM -- "-$browser_pid" 2>/dev/null || true
  fi
  rm -f /tmp/shopvivaliz-amazon-safet-readback.mjs /tmp/shopvivaliz-amazon-safet-readback-browser.pid /tmp/shopvivaliz-safet-auth-probe.out
  sv_systemctl start amazon-returns-seller-central-browser.timer >/dev/null 2>&1 || true
}
trap cleanup EXIT

sv_systemctl stop amazon-returns-seller-central-browser.timer
service_state=""
for _ in $(seq 1 180); do
  service_state="$(sv_systemctl is-active amazon-returns-seller-central-browser.service 2>/dev/null || true)"
  case "$service_state" in inactive|failed) break ;; esac
  sleep 1
done
if [[ "$service_state" != "inactive" && "$service_state" != "failed" ]]; then
  echo ERROR_CODE=SELLER_CENTRAL_SERVICE_BUSY
  exit 72
fi

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
  setsid "$SELLER_CENTRAL_BROWSER"     --headless=new --no-sandbox --disable-gpu     --remote-debugging-address=127.0.0.1 --remote-debugging-port=9227     "--user-data-dir=$SELLER_CENTRAL_PROFILE"     --no-first-run --no-default-browser-check about:blank     >/tmp/shopvivaliz-amazon-safet-readback-browser.log 2>&1 &
  echo $! >/tmp/shopvivaliz-amazon-safet-readback-browser.pid
'
browser_pid="$(cat /tmp/shopvivaliz-amazon-safet-readback-browser.pid)"
[[ "$browser_pid" =~ ^[0-9]+$ ]]

ready=false
for _ in $(seq 1 40); do
  if curl -fsS --max-time 1 "$CDP_URL/json/version" >/dev/null 2>&1; then ready=true; break; fi
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
' >/tmp/shopvivaliz-safet-auth-probe.out 2>&1; then
  echo ERROR_CODE=SELLER_CENTRAL_AUTH_PROBE_FAILED
  exit 71
fi
grep -q '"status":"OK"' /tmp/shopvivaliz-safet-auth-probe.out
grep -q '"auth_state":"AUTHENTICATED"' /tmp/shopvivaliz-safet-auth-probe.out
echo SELLER_CENTRAL_AUTH=AUTHENTICATED

cat >/tmp/shopvivaliz-amazon-safet-readback.mjs <<'NODE'
import { createHash } from 'node:crypto';
import { get } from 'node:http';
import { parseSafeTStatus } from 'file:///home/ubuntu/amazon-returns-deploy/current/scripts/amazon-returns/safe-t-status-parser.mjs';

const CDP='http://127.0.0.1:9227';
const claimId='52214-19729-8255607';
const orderId='701-4306982-6000233';
const claimUrl='https://sellercentral.amazon.com.br/safet-claims/claim/52214-19729-8255607';
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const sha=value=>createHash('sha256').update(String(value??'')).digest('hex');

function jsonGet(url){
  return new Promise((resolve,reject)=>{
    get(url,response=>{
      let body='';
      response.setEncoding('utf8');
      response.on('data',chunk=>body+=chunk);
      response.on('end',()=>{try{resolve(JSON.parse(body));}catch(error){reject(error);}});
    }).on('error',reject);
  });
}

async function connect(){
  const targets=await jsonGet(CDP+'/json');
  const page=targets.find(target=>target.type==='page'&&target.webSocketDebuggerUrl);
  if(!page)throw new Error('NO_CDP_PAGE');
  const ws=new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve,reject)=>{ws.onopen=resolve;ws.onerror=reject;});
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
    const id=++seq; pending.set(id,[resolve,reject]); ws.send(JSON.stringify({id,method,params}));
  });
  const evalv=async expression=>(await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true})).result?.value;
  return {ws,send,evalv};
}

const {ws,send,evalv}=await connect();
try{
  await send('Page.navigate',{url:claimUrl});
  await sleep(4500);
  const href=String(await evalv('location.href'));
  if(/signin|ap\/signin|auth/i.test(href))throw new Error('AUTH_REQUIRED');
  const text=String(await evalv('document.body ? document.body.innerText : ""'));
  if(!text.includes(orderId))throw new Error('ORDER_IDENTITY_MISMATCH');
  const read=parseSafeTStatus(text,{safe_t_id:claimId,order_id:orderId});
  const status=String(read?.claim_status||'UNKNOWN').toUpperCase();
  console.log(JSON.stringify({
    safe_t_id:claimId,
    order_id:orderId,
    claim_status:status,
    appeal_submitted:read?.appeal_submitted ?? null,
    appeal_denied:read?.appeal_denied ?? null,
    appeal_deadline_at:read?.appeal_deadline_at ?? null,
    decision_text_present:Boolean(read?.decision_text),
    decision_text_sha256:read?.decision_text?sha(read.decision_text):null,
    page_text_sha256:sha(text)
  }));
  if(status==='UNKNOWN')throw new Error('SAFE_T_STATUS_UNKNOWN');
  console.log('SAFE_T_READBACK_VERIFIED=true');
} finally {
  ws.close();
}
NODE

"$NODE_BIN" /tmp/shopvivaliz-amazon-safet-readback.mjs
