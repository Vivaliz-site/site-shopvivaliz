#!/usr/bin/env bash
# Opt-in, reversible MCP routing to ALREADY authenticated Dev Chrome.
# Never copies browser profile or restarts any Chromium process.
set -Eeuo pipefail
if [[ "$(id -u)" -ne 0 ]]; then echo 'DEV_AUX_ROOT_REQUIRED'; exit 2; fi
if [[ "${SHOPVIVALIZ_AUTHORIZE_DEV_AUX_ADOPTION:-}" != "1" ]]; then
  echo 'DEV_AUX_EXPLICIT_OWNER_OPTIN_REQUIRED'; exit 3
fi
unit=shopvivaliz-browser-dev-mcp.service
dropin_dir=/etc/systemd/system/shopvivaliz-browser-dev-mcp.service.d
dropin="$dropin_dir/90-verified-dev-aux.conf"
if [[ -e "$dropin" ]]; then echo 'DEV_AUX_ROUTING_ALREADY_PRESENT'; exit 4; fi
for service in shopvivaliz-atendimento-mcp-browser.service shopvivaliz-dev-browser.service shopvivaliz-atendimento-browser.service "$unit"; do
  systemctl is-active --quiet "$service" || { echo 'REQUIRED_SERVICE_INACTIVE'; exit 5; }
done

# Exact, opt-in corporate Dev scope only.
export SHOPVIVALIZ_REMOTE_MCP_PORT=5583
export SHOPVIVALIZ_BROWSER_SESSION_NAME=dev
export SHOPVIVALIZ_BROWSER_CDP_URL=http://127.0.0.1:9557
export SHOPVIVALIZ_DEV_ADOPTED_CDP=9557
umask 077
lease_file="$(mktemp /run/shopvivaliz-dev-aux-adoption.XXXXXXXX)"
rollback=0
lease_acquired=0
cleanup() {
  rc=$?
  trap - EXIT
  if [[ "$rollback" == 1 && "$rc" -ne 0 ]]; then
    # Only roll back the MCP endpoint: never touch either Chrome service.
    if [[ -f "$dropin" ]]; then rm -f "$dropin"; fi
    if ! systemctl daemon-reload; then echo "DEV_AUX_ROLLBACK_RELOAD_FAILED" >&2; fi
    if ! systemctl restart shopvivaliz-browser-dev-mcp.service; then echo "DEV_AUX_ROLLBACK_RESTART_FAILED" >&2; fi
    echo 'DEV_AUX_ADOPTION_ROLLBACK_APPLIED'
  fi
  if [[ "$lease_acquired" == 1 ]]; then
    if ! python3 - "$lease_file" <<'PY'
import importlib.util, json, sys
from pathlib import Path
lease = json.loads(Path(sys.argv[1]).read_text())
spec = importlib.util.spec_from_file_location(
    'adopted_dev_backend', '/opt/shopvivaliz-remote-control-browser/server.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
module.base.runtime_lock.release_runtime_lock(
    lease['lease_id'], lease['fencing_token'], 'dev_aux_mcp_adoption_complete')
PY
    then echo "DEV_AUX_LEASE_RELEASE_FAILED" >&2; fi
  fi
  rm -f "$lease_file"
  exit "$rc"
}
trap cleanup EXIT

# Real account-specific maintenance lease and native exact-email identity gate.
python3 - "$lease_file" <<'PY'
import importlib.util, json, sys
from pathlib import Path
spec = importlib.util.spec_from_file_location(
    'adopted_dev_backend', '/opt/shopvivaliz-remote-control-browser/server.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
lock = module.base.runtime_lock.acquire_runtime_lock(
    'maintenance', 'shopvivaliz-account-auth:dev', 120, ['dev_aux_adoption'])
try:
    aux = module._corporate_profile_probe('dev_aux', 'status')
    canonical = module.browser_auth_status({'session': 'dev'})
    atendimento = module.browser_auth_status({'session': 'atendimento'})
    if aux.get('state') != 'AUTHENTICATED' or aux.get('identity_match') is not True:
        raise RuntimeError('DEV_AUX_IDENTITY_UNVERIFIED')
    if canonical.get('state') == 'AUTHENTICATED':
        raise RuntimeError('CANONICAL_DEV_ALREADY_AUTHENTICATED')
    if atendimento.get('state') != 'AUTHENTICATED':
        raise RuntimeError('ATENDIMENTO_IDENTITY_UNVERIFIED')
    Path(sys.argv[1]).write_text(json.dumps({
        'lease_id': lock['lease_id'], 'fencing_token': lock['fencing_token']}))
except Exception:
    module.base.runtime_lock.release_runtime_lock(
        lock['lease_id'], lock['fencing_token'], 'dev_aux_adoption_preflight_denied')
    raise
PY
lease_acquired=1

# No cookies, tokens, sessions, profile directories, or Windows/RustDesk changes.
rollback=1
install -d -m 0755 "$dropin_dir"
tmp="$(mktemp "$dropin_dir/.verified-dev-aux.XXXXXXXX")"
cat > "$tmp" <<'SYSTEMD'
[Service]
Environment=SHOPVIVALIZ_BROWSER_CDP_URL=http://127.0.0.1:9557
Environment=SHOPVIVALIZ_DEV_ADOPTED_CDP=9557
SYSTEMD
chmod 0644 "$tmp"
mv -n "$tmp" "$dropin"
systemctl daemon-reload
systemctl restart shopvivaliz-browser-dev-mcp.service

# E2E: require CDP transport and exact account identity from live /health.
for attempt in $(seq 1 12); do
  if curl -fsS --max-time 8 http://127.0.0.1:5583/health | python3 -c '
import json,sys
try:
 d=json.load(sys.stdin)
 assert d.get("ok") is True
 assert d.get("session") == "dev"
 assert d.get("cdp_reachable") is True
 assert d.get("account_authenticated") is True
 assert d.get("account_identity_verified") is True
except Exception:
 raise SystemExit(1)
'; then break; fi
  if [[ "$attempt" -eq 12 ]]; then echo 'DEV_AUX_HEALTH_UNVERIFIED'; exit 7; fi
  sleep 1
done
systemctl is-active --quiet shopvivaliz-atendimento-browser.service
systemctl is-active --quiet shopvivaliz-atendimento-mcp-browser.service
systemctl is-active --quiet shopvivaliz-dev-browser.service
echo 'DEV_AUX_MCP_ADOPTED_E2E=true'
echo 'EXISTING_CHROMIUM_PROCESSES_PRESERVED=true'
rollback=0
