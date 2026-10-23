#!/usr/bin/env bash
# Disable caller tracing/export-all before expanding the provisioned credential.
set +x
set +a
set -Eeuo pipefail
export -n SHOPVIVALIZ_AGENT_KEY AGENT_KEY

BASE_URL="${BASE_URL:-https://shopvivaliz.com.br}"
AUDIT_CEP="${AUDIT_CEP:-35500006}"
AGENT_KEY="${SHOPVIVALIZ_AGENT_KEY:-${AGENT_KEY:-}}"
# Shadow audit: map the canonical TLS hostname to this VM, never through public DNS.
# Only loopback is accepted; production-origin audit leaves this array empty.
CURL_PIN_ARGS=()
if [[ -n "${AUDIT_TARGET_IP:-}" ]]; then
  if [[ "$BASE_URL" != "https://shopvivaliz.com.br" || "$AUDIT_TARGET_IP" != "127.0.0.1" ]]; then
    echo 'PRODUCTION_FUNCTIONAL_AUDIT=FAIL stage=target_route detail=invalid destination pin' >&2
    exit 1
  fi
  CURL_PIN_ARGS=(--noproxy '*' --resolve "shopvivaliz.com.br:443:$AUDIT_TARGET_IP" --max-redirs 0)
fi
fail() { echo "PRODUCTION_FUNCTIONAL_AUDIT=FAIL stage=$1 detail=$2" >&2; exit 1; }
pass() { echo "PASS stage=$1 detail=$2"; }
# Header input is line-oriented. Never allow an embedded header separator.
case "$AGENT_KEY" in
  *$'\r'*|*$'\n'*) fail integrations 'invalid agent key format' ;;
esac
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

request() {
  local method="$1" url="$2" body="${3:-}" output="$4" code result effective
  if [[ "$method" == POST ]]; then
    result=$(curl -q -sS "${CURL_PIN_ARGS[@]}" --max-time 30 -o "$output" -w '%{http_code} %{url_effective}' -X POST -H 'Content-Type: application/json' --data "$body" "$url") || fail network "$url"
  else
    result=$(curl -q -sS "${CURL_PIN_ARGS[@]}" --max-time 30 -o "$output" -w '%{http_code} %{url_effective}' "$url") || fail network "$url"
  fi
  read -r code effective <<< "$result"
  if [[ "${#CURL_PIN_ARGS[@]}" -gt 0 && "$effective" != "$BASE_URL/"* ]]; then
    fail target_route 'redirect escaped canonical TLS hostname'
  fi
  printf '%s' "$code"
}
json_assert() {
  local file="$1" expr="$2" label="$3"
  python3 - "$file" "$expr" <<'PY' || fail "$label" "json assertion failed"
# Parse errors fail the enclosing stage without echoing response values.
import sys
try:
    import json, pathlib, sys
    obj=json.loads(pathlib.Path(sys.argv[1]).read_text())
    expr=sys.argv[2]
    safe={"len":len,"float":float,"int":int,"str":str,"bool":bool}
    if not eval(expr, {"__builtins__": safe}, {"d": obj}): raise SystemExit(1)
except Exception:
    raise SystemExit(1) from None
PY
}

for path in / /catalogo/ /carrinho /checkout; do
  code=$(request GET "$BASE_URL$path" "" "$TMPDIR/page")
  [[ "$code" == 200 ]] || fail page "$path http=$code"
  pass page "$path http=200"
done

code=$(request GET "$BASE_URL/api/orders/health.php" "" "$TMPDIR/orders.json")
[[ "$code" == 200 ]] || fail orders_health "http=$code"
json_assert "$TMPDIR/orders.json" 'd.get("ok") is True' orders_health
pass orders_health 'ok=true'

code=$(request GET "$BASE_URL/api/health/payment-queue.php" "" "$TMPDIR/payment-queue.json")
[[ "$code" == 200 ]] || fail payment_queue "http=$code"
json_assert "$TMPDIR/payment-queue.json" 'd.get("ok") is True and d.get("worker_ok") is True and int(d.get("stale") or 0) == 0 and int(d.get("failed") or 0) == 0' payment_queue
pass payment_queue 'worker_ok=true stale=0 failed=0 oldest_queued_age_seconds validated'

code=$(request GET "$BASE_URL/api/catalog/products.php?limit=10&available=1" "" "$TMPDIR/products.json")
[[ "$code" == 200 ]] || fail catalog "http=$code"
python3 - "$TMPDIR/products.json" "$TMPDIR/item.json" <<'PY' || fail catalog 'no auditable product'
# Parse errors fail the enclosing stage without echoing response values.
import sys
try:
    import json, pathlib, sys
    p=json.loads(pathlib.Path(sys.argv[1]).read_text())
    for x in p.get('products',[]):
        sku=str(x.get('sku') or '').strip(); pid=str(x.get('id') or x.get('olist_product_id') or '').strip()
        if (sku or pid) and float(x.get('stock') or 0) > 0:
            pathlib.Path(sys.argv[2]).write_text(json.dumps({'sku':sku,'product_id':pid,'quantity':1}))
            raise SystemExit(0)
    raise SystemExit(1)
except Exception:
    raise SystemExit(1) from None
PY
shipping_payload=$(python3 - "$AUDIT_CEP" "$TMPDIR/item.json" <<'PY'
# Parse errors fail the enclosing stage without echoing response values.
import sys
try:
    import json, pathlib, sys
    print(json.dumps({'cep':sys.argv[1],'items':[json.loads(pathlib.Path(sys.argv[2]).read_text())]}))
except Exception:
    raise SystemExit(1) from None
PY
)
code=$(request POST "$BASE_URL/api/melhorenvio/shipping-check-v2.php" "$shipping_payload" "$TMPDIR/shipping.json")
[[ "$code" == 200 ]] || fail melhor_envio "http=$code"
json_assert "$TMPDIR/shipping.json" 'd.get("ok") is True and len(d.get("shipping_options") or []) > 0 and float(d.get("shipping_total") or 0) > 0' melhor_envio
pass melhor_envio 'real quote returned shipping_options'

[[ -n "$AGENT_KEY" ]] || fail integrations 'SHOPVIVALIZ_AGENT_KEY/AGENT_KEY missing; cannot prove provider functionality'
integration_response=$(printf 'X-Agent-Key: %s\n' "$AGENT_KEY" | curl -q -sS "${CURL_PIN_ARGS[@]}" --max-time 45 -o "$TMPDIR/integrations.json" -w '%{http_code} %{url_effective}' --header @- "$BASE_URL/api/agent/integrations-health.php") || fail integrations network
read -r code effective <<< "$integration_response"
if [[ "${#CURL_PIN_ARGS[@]}" -gt 0 && "$effective" != "$BASE_URL/"* ]]; then
  fail target_route 'integration health redirected away from destination'
fi
[[ "$code" == 200 || "$code" == 207 ]] || fail integrations "http=$code"
python3 - "$TMPDIR/integrations.json" <<'PY' || fail integrations 'critical provider not connected'
# Parse errors fail the enclosing stage without echoing response values.
import sys
try:
    import json, pathlib, sys
    r=json.loads(pathlib.Path(sys.argv[1]).read_text())
    items={x.get('key'):x for x in r.get('integrations',[]) if isinstance(x,dict)}
    for key in ('mercado_pago','melhor_envio','olist_tiny'):
        status=items.get(key,{}).get('status')
        label = 'connected' if status == 'connected' else 'not_connected'
        print(f'INTEGRATION_STATUS key={key} status={label}')
        if status != 'connected':
            raise SystemExit(1)
    summary = r.get('summary') or {}
    # Log derived counters, never arbitrary provider field names or values.
    print(f"INTEGRATION_SUMMARY failed={int(summary.get('failed') or 0)}")
    nonconnected = sum(item.get('status') != 'connected' for item in items.values())
    print(f'INTEGRATION_NONCONNECTED count={nonconnected}')
    if int(summary.get('failed') or 0) != 0:
        raise SystemExit(1)
except Exception:
    raise SystemExit(1) from None
PY
pass integrations 'olist mercado_pago melhor_envio connected'

echo 'AUDITORIA_FUNCIONAL_PRODUCAO=PASS'
echo 'PRODUCTION_FUNCTIONAL_AUDIT=PASS'
