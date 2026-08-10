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

echo "INFO validating Oracle repository state for deployed commit $expected_sha"
if [ ! -d "$repo/.git" ]; then
  echo "FAIL Oracle repository is missing: $repo" >&2
  exit 1
fi
if [ ! -r "$sync_script" ]; then
  echo "FAIL Oracle sync script is unreadable: $sync_script" >&2
  exit 1
fi

# Fetching the canonical ref is read-only with respect to the active working
# tree. The operational clone can legitimately be checked out on a clean
# patch/* branch while an autonomous agent owns that checkout. In that case the
# deploy smoke must not switch branches underneath the agent; it validates the
# canonical remote ref and defers the mutating sync safely instead.
git -C "$repo" fetch --prune --no-tags origin main >/dev/null 2>&1
current_branch="$(git -C "$repo" branch --show-current)"
repo_sha="$(git -C "$repo" rev-parse HEAD)"
remote_sha="$(git -C "$repo" rev-parse origin/main)"
git -C "$repo" cat-file -e "${expected_sha}^{commit}"
if ! git -C "$repo" merge-base --is-ancestor "$expected_sha" "$remote_sha"; then
  echo "FAIL deployed commit $expected_sha is not reachable from canonical main $remote_sha" >&2
  exit 1
fi

if [[ "$current_branch" == 'main' ]]; then
  ROOT="$repo" SHARED_ROOT="$shared" /usr/bin/bash "$sync_script"

  repo_sha="$(git -C "$repo" rev-parse HEAD)"
  remote_sha="$(git -C "$repo" rev-parse origin/main)"
  test "$repo_sha" = "$remote_sha"
  test -s "$sync_status"
  python3 - "$sync_status" "$remote_sha" <<'PY'
from __future__ import annotations

import json
import sys
from pathlib import Path

payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
expected_canonical = sys.argv[2].lower()

# The canonical git-auto-sync.py currently reports success with
# status="completed" and SHA fields, while older sync runners also emitted
# ok=true/action. Accept both schemas so a successful repository sync does not
# become a false-negative deployment failure.
ok_value = payload.get("ok")
if ok_value is None:
    ok_value = payload.get("status") == "completed"
if ok_value is not True:
    raise SystemExit("sync report is not successful")

local_before = str(payload.get("local_sha_before") or "").lower()
local_after = str(
    payload.get("local_sha_after")
    or payload.get("final_sha")
    or payload.get("local_sha")
    or ""
).lower()
remote = str(payload.get("remote_sha") or "").lower()

if local_after != expected_canonical or remote != expected_canonical:
    raise SystemExit(
        "sync report SHA mismatch: "
        f"local={local_after} remote={remote} canonical={expected_canonical}"
    )

action = str(payload.get("action") or "")
if not action:
    action = "noop" if local_before == expected_canonical else "fast-forward-to-canonical"

allowed = {
    "noop",
    "fast-forward-to-canonical",
    "realigned-to-verified-sanitized-history",
}
if action not in allowed:
    raise SystemExit(f"unexpected sync action: {action}")
PY
  echo "OK Oracle repository sync: canonical=$repo_sha deployed=$expected_sha"
elif [[ "$current_branch" == patch/* ]]; then
  tracked_changes="$(git -C "$repo" status --porcelain --untracked-files=no)"
  if [[ -n "$tracked_changes" ]]; then
    echo "FAIL active agent checkout has tracked working-tree changes; refusing to treat repository state as safe" >&2
    exit 1
  fi
  echo "OK Oracle canonical ref: remote=$remote_sha deployed=$expected_sha"
  echo "INFO repository sync deferred safely while active checkout is $current_branch"
else
  echo "FAIL Oracle repository is on unexpected branch: ${current_branch:-detached}" >&2
  exit 1
fi

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
grep -q 'footer-cols' "$homepage_body"
if grep -qE 'class="hero-slide is-active"[^>]*style="[^"]*linear-gradient' "$homepage_body"; then
  echo 'FAIL possible duplicate hero gradient regression detected' >&2
  exit 1
fi
echo 'OK public homepage HTML contract'
grep -Eqi 'name=.viewport.' "$homepage_body"
grep -Eqi '(mobile|menu-toggle|header)' "$homepage_body"
echo 'OK mobile responsive header contract'

check_http llms "$base/llms.txt" '200'
grep -qx '# ShopVivaliz' "$tmpdir/llms.body"
grep -Fq 'https://shopvivaliz.com.br/catalogo' "$tmpdir/llms.body"
echo 'OK public llms.txt contract'

check_http catalog "$base/catalogo" '200'
check_http catalog_api "$base/api/catalog/products.php?limit=1&available=1" '200'
grep -q '"ok":true' "$tmpdir/catalog_api.body"
echo 'OK catalog API payload'

# The storefront product grid is rendered by JavaScript, so the static homepage
# is not a reliable source for a /produto/ link. Use the same public catalog API
# that feeds the browser and validate one canonical slug from live inventory.
product_path="$(python3 - "$tmpdir/catalog_api.body" <<'PY'
from __future__ import annotations

import json
import sys
from pathlib import Path
from urllib.parse import quote

payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
products = payload.get("products") or []
if not products:
    raise SystemExit("catalog API returned no available product for smoke test")
slug = str(products[0].get("slug") or "").strip()
if not slug:
    raise SystemExit("catalog API product is missing canonical slug")
print('/produto/' + quote(slug, safe='-._~'))
PY
)"
test -n "$product_path"
check_http product "$base$product_path" '200'
check_http cart "$base/carrinho" '200,302'
check_http checkout "$base/checkout" '200,302'
check_http css "$base/css/shopvivaliz-core-consolidated.css" '200'

# Follow the complete unauthenticated admin redirect chain. The previous smoke
# accepted the first 301 from /admin and therefore could not detect a cached or
# server-side /admin/ -> /admin/ loop. A cache-busting query also prevents an
# intermediary cache from masking the current routing behavior.
admin_probe="$base/admin/?smoke=${expected_sha:0:12}"
admin_meta="$(curl --location --max-redirs 5 --silent --show-error \
  --output "$tmpdir/admin.body" --max-time 20 --user-agent "$ua" \
  --write-out $'%{http_code}\n%{url_effective}\n%{num_redirects}' \
  "$admin_probe" || true)"
admin_status="$(printf '%s\n' "$admin_meta" | sed -n '1p')"
admin_effective="$(printf '%s\n' "$admin_meta" | sed -n '2p')"
admin_redirects="$(printf '%s\n' "$admin_meta" | sed -n '3p')"

if [[ "$admin_status" != '200' ]]; then
  echo "FAIL admin redirect chain: final HTTP ${admin_status:-unknown}; effective=${admin_effective:-unknown}; redirects=${admin_redirects:-unknown}" >&2
  exit 1
fi
if [[ "$admin_effective" != "$base/auth/login.php"* ]]; then
  echo "FAIL admin redirect chain: unauthenticated request did not finish at login; effective=$admin_effective" >&2
  exit 1
fi
if [[ ! "$admin_redirects" =~ ^[0-9]+$ ]] || (( admin_redirects < 1 || admin_redirects > 5 )); then
  echo "FAIL admin redirect chain: invalid redirect count ${admin_redirects:-unknown}" >&2
  exit 1
fi
grep -Eqi '(login|senha|password|entrar)' "$tmpdir/admin.body"
echo "OK admin redirect chain: final=200 redirects=$admin_redirects effective=$admin_effective"

# Verify that the public login flow keeps one anonymous PHP session across
# requests. The CSRF token is intentionally stable for the session scope, so
# matching tokens prove that the browser cookie can round-trip through PHP.
login_cookie_jar="$tmpdir/login.cookies"
login_first="$tmpdir/login-first.html"
login_second="$tmpdir/login-second.html"
login_post="$tmpdir/login-post.html"
login_url="$base/auth/login.php?redirect=%2Fadmin%2F%3Fsession_smoke%3D${expected_sha:0:12}"

curl --silent --show-error --max-time 20 --user-agent "$ua" \
  --cookie-jar "$login_cookie_jar" --output "$login_first" "$login_url"
login_token_first="$(python3 - "$login_first" <<'PY'
import re
import sys
from pathlib import Path

html = Path(sys.argv[1]).read_text(encoding='utf-8', errors='replace')
match = re.search(r'name=["\']csrf_token["\'][^>]*value=["\']([^"\']+)', html, re.I)
if not match:
    raise SystemExit('missing login CSRF token on first request')
print(match.group(1))
PY
)"

curl --silent --show-error --max-time 20 --user-agent "$ua" \
  --cookie "$login_cookie_jar" --cookie-jar "$login_cookie_jar" \
  --output "$login_second" "$login_url"
login_token_second="$(python3 - "$login_second" <<'PY'
import re
import sys
from pathlib import Path

html = Path(sys.argv[1]).read_text(encoding='utf-8', errors='replace')
match = re.search(r'name=["\']csrf_token["\'][^>]*value=["\']([^"\']+)', html, re.I)
if not match:
    raise SystemExit('missing login CSRF token on second request')
print(match.group(1))
PY
)"

if [[ "$login_token_first" != "$login_token_second" ]]; then
  echo 'FAIL login session continuity: CSRF token changed despite cookie jar reuse' >&2
  exit 1
fi

login_post_status="$(curl --silent --show-error --max-time 20 --user-agent "$ua" \
  --cookie "$login_cookie_jar" --cookie-jar "$login_cookie_jar" \
  --output "$login_post" --write-out '%{http_code}' \
  --request POST \
  --data-urlencode "csrf_token=$login_token_second" \
  --data-urlencode 'redirect=/admin/' \
  --data-urlencode "email=shopvivaliz-session-smoke-${expected_sha:0:12}@invalid.example" \
  --data-urlencode 'password=SmokeSessionPassword123!' \
  "$base/auth/login.php" || true)"
if [[ "$login_post_status" != '200' ]]; then
  echo "FAIL login session continuity: controlled invalid login returned HTTP $login_post_status" >&2
  exit 1
fi
if grep -Fq 'Sua sessão expirou' "$login_post"; then
  echo 'FAIL login session continuity: CSRF validation lost the PHP session' >&2
  exit 1
fi
grep -Fq 'Email ou senha incorretos' "$login_post"
echo 'OK login session continuity and CSRF round-trip'

admin_guard_cli="$(/usr/bin/php "$current_root/scripts/admin-guard-production-smoke.php" 2>&1 || true)"
if [[ "$admin_guard_cli" != 'ADMIN_GUARD_OK' ]]; then
  echo 'FAIL authenticated admin guard database fallback' >&2
  printf '%s\n' "$admin_guard_cli" >&2
  exit 1
fi
echo 'OK authenticated admin guard database fallback'

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