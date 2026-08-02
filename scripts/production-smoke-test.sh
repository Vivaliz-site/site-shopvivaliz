#!/usr/bin/env bash
set -euo pipefail

expected_sha="${1:?Usage: production-smoke-test.sh EXPECTED_SHA}"
base='https://shopvivaliz.com.br'
ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126 Safari/537.36'
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

repo='/home/ubuntu/shopvivaliz-deploy/repo'
shared='/home/ubuntu/shopvivaliz-deploy/shared'
current_root='/home/ubuntu/shopvivaliz-deploy/current'
script_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
sync_script="$script_root/scripts/auto-sync-oracle.sh"
installer="$script_root/scripts/install-auto-sync-oracle.sh"
sync_status="$shared/logs/tri-environment-sync.json"

echo "INFO validating Oracle repository sync for deployed commit $expected_sha"
if [ ! -d "$repo/.git" ]; then
  echo "FAIL Oracle repository is missing: $repo" >&2
  exit 1
fi
if [ ! -r "$sync_script" ]; then
  echo "FAIL Oracle sync script is unreadable: $sync_script" >&2
  exit 1
fi
ROOT="$repo" SHARED_ROOT="$shared" /usr/bin/bash "$sync_script"

repo_sha="$(git -C "$repo" rev-parse HEAD)"
remote_sha="$(git -C "$repo" rev-parse origin/main)"
test "$repo_sha" = "$remote_sha"
git -C "$repo" cat-file -e "${expected_sha}^{commit}"
if ! git -C "$repo" merge-base --is-ancestor "$expected_sha" "$remote_sha"; then
  echo "FAIL deployed commit $expected_sha is not reachable from canonical main $remote_sha" >&2
  exit 1
fi
test -s "$sync_status"
python3 - "$sync_status" "$remote_sha" <<'PY'
from __future__ import annotations

import json
import sys
from pathlib import Path

payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
expected_canonical = sys.argv[2].lower()
action = str(payload.get("action") or "")
allowed = {
    "noop",
    "fast-forward-to-canonical",
    "realigned-to-verified-sanitized-history",
}
local_after = str(
    payload.get("local_sha_after")
    or payload.get("local_sha")
    or ""
).lower()
remote = str(payload.get("remote_sha") or "").lower()
if payload.get("ok") is not True:
    raise SystemExit("sync report is not successful")
if action not in allowed:
    raise SystemExit(f"unexpected sync action: {action}")
if local_after != expected_canonical or remote != expected_canonical:
    raise SystemExit(
        "sync report SHA mismatch: "
        f"local={local_after} remote={remote} canonical={expected_canonical}"
    )
PY
echo "OK Oracle repository sync: canonical=$repo_sha deployed=$expected_sha"

if [ ! -r "$installer" ]; then
  echo "FAIL Oracle sync installer is unreadable: $installer" >&2
  exit 1
fi
ROOT="$repo" SHARED_ROOT="$shared" CURRENT_ROOT="$current_root" \
  /usr/bin/bash "$installer"
crontab -l > "$tmpdir/crontab.txt"
grep -F "/usr/bin/bash $current_root/scripts/auto-sync-oracle.sh" \
  "$tmpdir/crontab.txt" >/dev/null
echo "OK Oracle repository sync cron installed"

check_http() {
  local label="$1"
  local url="$2"
  local allowed="$3"
  local output="$tmpdir/${label//[^a-zA-Z0-9]/_}.body"
  local status

  status="$(curl --location --silent --show-error --output "$output" --max-time 20 --user-agent "$ua" --write-out '%{http_code}' "$url" || true)"
  if [[ ",${allowed}," != *",${status},"* ]]; then
    echo "FAIL ${label}: ${url} returned ${status}; allowed ${allowed}" >&2
    exit 1
  fi
  echo "OK ${label}: ${url} (${status})"
}

deployed_sha="$(cat /home/ubuntu/shopvivaliz-deploy/current/.release-sha)"
test "$deployed_sha" = "$expected_sha"
echo "OK release SHA marker: $deployed_sha"

check_http version "$base/api/health/version.php" '200'
grep -q "\"release_sha\":\"${expected_sha}\"" "$tmpdir/version.body"
echo "OK public version endpoint: $expected_sha"

check_http homepage "$base/" '200'
homepage_body="$tmpdir/homepage.body"
grep -Eqi 'name=.viewport.' "$homepage_body"
grep -Eqi '(mobile|menu-toggle|header)' "$homepage_body"
echo 'OK mobile responsive header contract'

check_http catalog "$base/catalogo" '200'
product_path="$(python3 - "$homepage_body" <<'PY'
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import quote, urlsplit
import sys

class ProductLinkParser(HTMLParser):
    def handle_starttag(self, tag, attrs):
        if tag.lower() != "a":
            return
        for key, value in attrs:
            if key.lower() != "href" or not value:
                continue
            path = urlsplit(value).path
            if path.startswith("/produto/"):
                print(quote(path, safe="/%:@"))
                raise SystemExit(0)

parser = ProductLinkParser()
parser.feed(Path(sys.argv[1]).read_text(encoding="utf-8", errors="ignore"))
PY
)"
test -n "$product_path"
check_http product "$base$product_path" '200'
check_http cart "$base/carrinho" '200,302'
check_http checkout "$base/checkout" '200,302'
check_http css "$base/css/shopvivaliz-core-consolidated.css" '200'
check_http catalog_api "$base/api/catalog/products.php?limit=1" '200'
grep -q '"ok":true' "$tmpdir/catalog_api.body"
echo 'OK catalog API payload'

admin_status="$(curl --silent --show-error --output "$tmpdir/admin.body" --max-time 20 --user-agent "$ua" --write-out '%{http_code}' "$base/admin" || true)"
if [[ "$admin_status" == '200' ]]; then
  grep -Eqi '(login|senha|password|entrar)' "$tmpdir/admin.body"
elif [[ ",$admin_status," != *,301,* && ",$admin_status," != *,302,* && ",$admin_status," != *,303,* && ",$admin_status," != *,401,* && ",$admin_status," != *,403,* ]]; then
  echo "FAIL admin behavior: HTTP $admin_status" >&2
  exit 1
fi
echo "OK admin protected behavior ($admin_status)"

check_http liz_health "$base/api/liz-intelligent.php?health=1" '200'
grep -Eq '"(ok|status)"[[:space:]]*:[[:space:]]*(true|"ok"|"healthy")' "$tmpdir/liz_health.body"
echo 'OK Liz health payload'

check_http orders_health "$base/api/orders/health.php" '200'
grep -Eq '"ok"[[:space:]]*:[[:space:]]*true' "$tmpdir/orders_health.body"
grep -Eq '"quote_signing_configured"[[:space:]]*:[[:space:]]*true' "$tmpdir/orders_health.body"
echo 'OK orders health payload'

check_http olist_webhook_health "$base/api/olist/webhook-health.php" '200'
grep -Eq '"ok"[[:space:]]*:[[:space:]]*true' "$tmpdir/olist_webhook_health.body"
echo 'OK Olist webhook health payload'

olist_webhook_status="$(curl --silent --show-error --output "$tmpdir/olist_webhook_post.body" --max-time 20 --user-agent "$ua" --write-out '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{"event":"health_test","test":true}' "$base/olist/webhook-receiver.php" || true)"
if [[ "$olist_webhook_status" != '200' ]]; then
  echo "FAIL Olist webhook contract: benign unmonitored event returned $olist_webhook_status" >&2
  exit 1
fi
grep -Eq '"sucesso"[[:space:]]*:[[:space:]]*true' "$tmpdir/olist_webhook_post.body"
grep -q 'Webhook ignorado' "$tmpdir/olist_webhook_post.body"
echo "OK Olist webhook ignored benign unmonitored event ($olist_webhook_status)"

redirect_headers="$(curl --silent --show-error --head --max-time 20 --user-agent "$ua" https://www.shopvivaliz.com.br/ | tr -d '\r')"
redirect_status="$(printf '%s\n' "$redirect_headers" | awk 'NR==1 {print $2}')"
redirect_location="$(printf '%s\n' "$redirect_headers" | awk 'BEGIN{IGNORECASE=1} /^location:/ {sub(/^[^:]+:[[:space:]]*/, ""); print; exit}')"
[[ "$redirect_status" == '301' || "$redirect_status" == '302' || "$redirect_status" == '307' || "$redirect_status" == '308' ]]
[[ "$redirect_location" == https://shopvivaliz.com.br/* ]]
echo "OK www redirect: $redirect_status -> $redirect_location"

private_paths=(
  '/.git/config'
  '/.env.local'
  '/config/runtime-secrets.php'
  '/storage/products-cache-ativos.json'
  '/tasks-queue.json'
  '/scripts/'
  '/tests/'
  '/installer/self-test.php'
  '/debug-products.php'
  '/discover-db.php'
  '/find-db-host.php'
  '/fix-database.php'
  '/full-sync.php'
  '/gen-token.php'
  '/shop-catalog-export.php'
  '/sync-now.php'
  '/olist/token-refresh.php'
  '/api/catalog/test-normalize.php'
)
for path in "${private_paths[@]}"; do
  status="$(curl --silent --output /dev/null --max-time 10 --user-agent "$ua" --write-out '%{http_code}' "$base$path" || true)"
  if [[ "$status" != '403' && "$status" != '404' ]]; then
    echo "FAIL private path exposed: $path returned $status" >&2
    exit 1
  fi
  echo "OK private path blocked: $path ($status)"
done

headers="$(curl --silent --show-error --head --max-time 20 --user-agent "$ua" "$base/" | tr -d '\r' | tr '[:upper:]' '[:lower:]')"
for required in strict-transport-security x-content-type-options content-security-policy; do
  grep -q "^${required}:" <<< "$headers"
  echo "OK security header: ${required}"
done
