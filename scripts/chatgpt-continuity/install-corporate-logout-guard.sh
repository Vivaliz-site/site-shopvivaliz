#!/usr/bin/env bash
# Install versioned MCP logout-automation guard; preserve live Chrome PIDs.
set -Eeuo pipefail
if [[ "$(id -u)" -ne 0 || "$#" -ne 1 ]]; then
  echo 'ERROR root_and_single_source_directory_required' >&2
  exit 77
fi
source_root="$1"
source_base="$source_root/remote-control-mcp/server.py"
source_browser="$source_root/remote-control-browser-mcp/server.py"
dest_base="/opt/shopvivaliz-remote-control/server.py"
dest_browser="/opt/shopvivaliz-remote-control-browser/server.py"
test -s "$source_base" && test -s "$source_browser"
test -f "$dest_base" && test -f "$dest_browser"
grep -q 'corporate_logout_guard_denied' "$source_base"
grep -q '_assert_no_corporate_logout_url' "$source_browser"
python3 -m py_compile "$source_base" "$source_browser"
for unit in \
  shopvivaliz-remote-control-mcp.service \
  shopvivaliz-remote-control-browser-mcp.service \
  shopvivaliz-browser-dev-mcp.service \
  shopvivaliz-browser-atendimento-mcp.service \
  shopvivaliz-dev-browser.service \
  shopvivaliz-atendimento-browser.service \
  shopvivaliz-atendimento-mcp-browser.service
do
  systemctl is-active --quiet "$unit" || {
    echo "ERROR required_service_inactive:$unit" >&2
    exit 42
  }
done

# A distinct lock prevents two concurrent deployments from interleaving.
exec 9>/run/lock/shopvivaliz-mcp-logout-guard.lock
flock -n 9 || { echo 'ERROR deployment_already_in_progress' >&2; exit 43; }
pid_dev="$(systemctl show shopvivaliz-dev-browser.service -p MainPID --value)"
pid_att="$(systemctl show shopvivaliz-atendimento-browser.service -p MainPID --value)"
pid_aux="$(systemctl show shopvivaliz-atendimento-mcp-browser.service -p MainPID --value)"
dir="$(mktemp -d /var/tmp/shopvivaliz-logout-guard.XXXXXXXX)"
chmod 0700 "$dir"
cp -p -- "$dest_base" "$dir/base.before.py"
cp -p -- "$dest_browser" "$dir/browser.before.py"
rollback() {
  local code=$?
  trap - ERR
  if [[ $code -ne 0 ]]; then
    echo 'ROLLBACK_MCP_ONLY=START' >&2
    install -m 0755 "$dir/base.before.py" "$dest_base"
    install -m 0755 "$dir/browser.before.py" "$dest_browser"
    for unit in shopvivaliz-browser-atendimento-mcp.service \
       shopvivaliz-browser-dev-mcp.service shopvivaliz-remote-control-browser-mcp.service \
       shopvivaliz-remote-control-mcp.service; do
      systemctl restart "$unit" || true
    done
    echo 'ROLLBACK_MCP_ONLY=COMPLETE' >&2
  fi
  rm -rf -- "$dir"
  return "$code"
}
trap rollback EXIT
install -m 0755 "$source_base" "$dest_base"
install -m 0755 "$source_browser" "$dest_browser"
# Only MCP services restart. Never stop/restart Chrome or touch its profiles.
for unit in \
   shopvivaliz-remote-control-browser-mcp.service \
   shopvivaliz-browser-dev-mcp.service \
   shopvivaliz-browser-atendimento-mcp.service \
   shopvivaliz-remote-control-mcp.service
do
  systemctl restart "$unit"
  systemctl is-active --quiet "$unit"
done
test "$pid_dev" = "$(systemctl show shopvivaliz-dev-browser.service -p MainPID --value)"
test "$pid_att" = "$(systemctl show shopvivaliz-atendimento-browser.service -p MainPID --value)"
test "$pid_aux" = "$(systemctl show shopvivaliz-atendimento-mcp-browser.service -p MainPID --value)"
cmp -s "$source_base" "$dest_base"
cmp -s "$source_browser" "$dest_browser"
python3 - <<'PY'
import json,urllib.request
with urllib.request.urlopen('http://127.0.0.1:5583/health',timeout=6) as r: d=json.load(r)
assert d.get('ok') is True
assert d.get('session')=='dev'
assert d.get('account_authenticated') is True
assert d.get('account_identity_verified') is True
assert d.get('cdp_reachable') is True
print('DEV_AUX_MCP_IDENTITY=VERIFIED')
PY
python3 - <<'PY'
import json,urllib.request
for port in (9559,9556,9557):
 with urllib.request.urlopen('http://127.0.0.1:%d/json/version'%port,timeout=5) as r:d=json.load(r)
 assert str(d.get('webSocketDebuggerUrl','')).startswith('ws://')
print('THREE_CHROMIUM_CDP_ENDPOINTS=HEALTHY')
PY
echo 'MCP_LOGOUT_GUARD_INSTALLED=true'
echo 'CHROMIUM_PIDS_PRESERVED=true'
