#!/usr/bin/env bash
set -Eeuo pipefail

MODE="${1:-install}"
BACKEND_HOST="always-free-arm-1787907847-26"
CLAUDE_USER="${SHOPVIVALIZ_CLAUDE_REMOTE_USER:-ubuntu}"
CLAUDE_HOME="${SHOPVIVALIZ_CLAUDE_REMOTE_HOME:-/home/${CLAUDE_USER}}"
CLAUDE_NATIVE_BIN="${CLAUDE_HOME}/.local/bin/claude"
WORKSPACE="${SHOPVIVALIZ_CLAUDE_REMOTE_WORKSPACE:-${CLAUDE_HOME}/shopvivaliz-claude-workspace/site-shopvivaliz}"
STATE_DIR="/var/lib/shopvivaliz-remote-control"
TOKEN_FILE="${STATE_DIR}/mcp-token"
MCP_URL="http://127.0.0.1:5580/mcp"
MCP_NAME="shopvivaliz-remote-control"
AUTH_HELPER="/usr/local/sbin/shopvivaliz-claude-mcp-headers"
SUDOERS_FILE="/etc/sudoers.d/shopvivaliz-claude-mcp"
SERVICE="shopvivaliz-claude-remote-control.service"
UNIT_SOURCE="${2:-deploy/systemd/${SERVICE}}"
UNIT_TARGET="/etc/systemd/system/${SERVICE}"

die() {
  echo "CLAUDE_REMOTE_CONTROL_SETUP=FAIL reason=$1" >&2
  exit "${2:-1}"
}

require_backend() {
  local actual
  actual="$(hostname)"
  [ "$actual" = "$BACKEND_HOST" ] || die "backend_host_mismatch:${actual}" 21
}

require_root() {
  [ "$(id -u)" -eq 0 ] || die root_required 22
}

run_as_claude() {
  sudo -u "$CLAUDE_USER" -H env \
    -u ANTHROPIC_BASE_URL \
    -u CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC \
    -u DISABLE_GROWTHBOOK \
    HOME="$CLAUDE_HOME" \
    "$@"
}

install_claude_if_missing() {
  require_backend
  if [ -x "$CLAUDE_NATIVE_BIN" ]; then
    echo "CLAUDE_NATIVE_INSTALL=PASS state=present"
    return 0
  fi

  local installer
  installer="$(mktemp)"
  curl -fsSL https://claude.ai/install.sh -o "$installer"
  chown "$CLAUDE_USER:$CLAUDE_USER" "$installer"
  chmod 0700 "$installer"
  run_as_claude bash "$installer" latest >/dev/null
  rm -f "$installer"
  [ -x "$CLAUDE_NATIVE_BIN" ] || die claude_native_install_failed 29
  echo "CLAUDE_NATIVE_INSTALL=PASS state=installed"
}

claude_bin() {
  if [ -x "$CLAUDE_NATIVE_BIN" ]; then
    printf '%s\n' "$CLAUDE_NATIVE_BIN"
    return 0
  fi
  run_as_claude bash -lc 'command -v claude'
}

probe_eligibility() {
  require_backend
  local bin rc auth_json auth_err doctor_out
  if bin="$(claude_bin 2>/dev/null)"; then
    rc=0
  else
    rc=$?
  fi
  [ "$rc" -eq 0 ] && [ -n "$bin" ] || die claude_not_installed 30
  echo "CLAUDE_PRESENT=PASS"
  run_as_claude "$bin" --version | head -n 1 | sed -E 's/[^A-Za-z0-9._+() -]/?/g'

  auth_json="$(mktemp)"
  auth_err="$(mktemp)"
  if run_as_claude "$bin" auth status >"$auth_json" 2>"$auth_err"; then
    rc=0
  else
    rc=$?
  fi
  if [ "$rc" -ne 0 ]; then
    sed -E 's/[A-Za-z0-9_=-]{24,}/[REDACTED]/g' "$auth_err" | tail -n 12
    rm -f "$auth_json" "$auth_err"
    echo "REMOTE_CONTROL_LOGIN_REQUIRED"
    return 31
  fi
  if ! run_as_claude python3 - "$auth_json" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    payload = json.load(handle)
if payload.get("loggedIn") is not True:
    raise SystemExit(1)
PY
  then
    rm -f "$auth_json" "$auth_err"
    echo "REMOTE_CONTROL_LOGIN_REQUIRED"
    return 31
  fi
  rm -f "$auth_json" "$auth_err"

  doctor_out="$(mktemp)"
  if run_as_claude timeout 30s "$bin" doctor >"$doctor_out" 2>&1; then
    rc=0
  else
    rc=$?
  fi
  if grep -Eqi 'remote control.*(disabled|not enabled|ineligible|unavailable)|requires.*trusted device|feature.flag.*(disabled|unavailable)' "$doctor_out"; then
    sed -E 's/[A-Za-z0-9_=-]{24,}/[REDACTED]/g' "$doctor_out" | grep -Ei 'remote control|trusted device|feature.flag' | head -n 20 || :
    rm -f "$doctor_out"
    echo "REMOTE_CONTROL_POLICY_REQUIRED"
    return 32
  fi
  if [ "$rc" -ne 0 ]; then
    sed -E 's/[A-Za-z0-9_=-]{24,}/[REDACTED]/g' "$doctor_out" | tail -n 12
    rm -f "$doctor_out"
    echo "REMOTE_CONTROL_DOCTOR_INCONCLUSIVE"
    return "$rc"
  fi
  rm -f "$doctor_out"
  echo "REMOTE_CONTROL_ELIGIBLE=PASS"
}

install_auth_helper() {
  require_root
  [ -s "$TOKEN_FILE" ] || die mcp_token_missing 40
  cat >"$AUTH_HELPER" <<'PY'
#!/usr/bin/env python3
import json
from pathlib import Path

token = Path("/var/lib/shopvivaliz-remote-control/mcp-token").read_text(encoding="utf-8").strip()
if not token or any(ch.isspace() for ch in token):
    raise SystemExit("invalid_mcp_token")
print(json.dumps({"Authorization": f"Bearer {token}"}))
PY
  chown root:root "$AUTH_HELPER"
  chmod 0755 "$AUTH_HELPER"

  printf '%s ALL=(root) NOPASSWD: %s\n' "$CLAUDE_USER" "$AUTH_HELPER" >"$SUDOERS_FILE"
  chmod 0440 "$SUDOERS_FILE"
  visudo -cf "$SUDOERS_FILE" >/dev/null
  echo "CLAUDE_MCP_HEADERS_HELPER=PASS"
}

prepare_workspace() {
  require_root
  local parent
  parent="$(dirname "$WORKSPACE")"
  install -d -m 0755 -o "$CLAUDE_USER" -g "$CLAUDE_USER" "$parent"
  if [ ! -d "$WORKSPACE/.git" ]; then
    run_as_claude git clone --origin origin https://github.com/Vivaliz-site/site-shopvivaliz.git "$WORKSPACE"
  else
    local origin
    origin="$(run_as_claude git -C "$WORKSPACE" remote get-url origin)"
    [ "$origin" = "https://github.com/Vivaliz-site/site-shopvivaliz.git" ] || die workspace_origin_mismatch 41
  fi
  echo "CLAUDE_WORKSPACE=PASS"
}

configure_mcp() {
  require_root
  local bin config
  bin="$(claude_bin)"
  config='{"type":"http","url":"http://127.0.0.1:5580/mcp","headersHelper":"sudo -n /usr/local/sbin/shopvivaliz-claude-mcp-headers"}'

  if run_as_claude "$bin" mcp get "$MCP_NAME" >/dev/null 2>&1; then
    run_as_claude "$bin" mcp remove "$MCP_NAME" --scope user >/dev/null
  fi
  run_as_claude "$bin" mcp add-json "$MCP_NAME" "$config" --scope user >/dev/null

  run_as_claude python3 - "$WORKSPACE" <<'PY'
import json
import sys
from pathlib import Path

workspace = str(Path(sys.argv[1]).resolve())
path = Path.home() / ".claude.json"
data = json.loads(path.read_text(encoding="utf-8")) if path.is_file() else {}
projects = data.setdefault("projects", {})
project = projects.setdefault(workspace, {})
project["hasTrustDialogAccepted"] = True
path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
PY
  echo "CLAUDE_MCP_CONFIG=PASS"
}

verify_private_mcp() {
  require_root
  systemctl is-active --quiet shopvivaliz-remote-control-mcp.service || die mcp_service_inactive 42
  curl -fsS --connect-timeout 3 --max-time 8 http://127.0.0.1:5580/health >/dev/null

  run_as_claude python3 - <<'PY'
import json
import subprocess
import urllib.request

helper = subprocess.run(
    ["sudo", "-n", "/usr/local/sbin/shopvivaliz-claude-mcp-headers"],
    text=True,
    stdout=subprocess.PIPE,
    stderr=subprocess.PIPE,
    check=True,
    timeout=5,
)
headers = json.loads(helper.stdout)
request = urllib.request.Request(
    "http://127.0.0.1:5580/mcp",
    data=json.dumps({"jsonrpc": "2.0", "id": 1, "method": "tools/list", "params": {}}).encode(),
    headers={"Content-Type": "application/json", **headers},
    method="POST",
)
with urllib.request.urlopen(request, timeout=8) as response:
    payload = json.loads(response.read().decode())
tools = payload.get("result", {}).get("tools", [])
names = {tool.get("name") for tool in tools}
required = {"hosts_list", "host_health", "task_submit", "task_status", "task_result"}
missing = sorted(required - names)
if missing:
    raise SystemExit("missing_mcp_tools:" + ",".join(missing))
print("CLAUDE_PRIVATE_MCP_DIRECT=PASS tools=" + str(len(tools)))
PY
}

install_service() {
  require_root
  [ -f "$UNIT_SOURCE" ] || die service_unit_source_missing 43
  install -m 0644 -o root -g root "$UNIT_SOURCE" "$UNIT_TARGET"
  systemctl daemon-reload
  systemctl enable "$SERVICE" >/dev/null
  echo "CLAUDE_REMOTE_CONTROL_SERVICE_INSTALLED=PASS"
}

accept_remote_control_once() {
  require_root
  local bin out rc
  bin="$(claude_bin)"
  out="$(mktemp)"
  if printf 'y\n' | run_as_claude timeout 12s "$bin" remote-control \
    --name "ShopVivaliz Bootstrap" \
    --spawn worktree \
    --capacity 1 \
    --no-create-session-in-dir \
    --permission-mode default >"$out" 2>&1; then
    rc=0
  else
    rc=$?
  fi

  if grep -Eqi 'requires a claude\.ai subscription|full-scope login token|run.*/login|sign in' "$out"; then
    rm -f "$out"
    echo "REMOTE_CONTROL_LOGIN_REQUIRED"
    return 31
  fi
  if grep -Eqi 'isn.t enabled|disabled by your organization|trusted device|feature-flag|eligibility' "$out"; then
    rm -f "$out"
    echo "REMOTE_CONTROL_POLICY_REQUIRED"
    return 32
  fi
  if [ "$rc" -ne 0 ] && [ "$rc" -ne 124 ]; then
    echo "REMOTE_CONTROL_CONSENT=FAIL rc=$rc"
    sed -E 's/[A-Za-z0-9_=-]{24,}/[REDACTED]/g' "$out" | tail -n 12
    rm -f "$out"
    return "$rc"
  fi
  rm -f "$out"
  echo "REMOTE_CONTROL_CONSENT=PASS"
}

start_service() {
  require_root
  systemctl restart "$SERVICE"
  for attempt in $(seq 1 15); do
    if systemctl is-active --quiet "$SERVICE"; then
      sleep 2
      if systemctl is-active --quiet "$SERVICE"; then
        echo "CLAUDE_REMOTE_CONTROL_SERVICE=PASS"
        return 0
      fi
    fi
    sleep 1
  done
  systemctl status "$SERVICE" --no-pager -l | sed -E 's/[A-Za-z0-9_=-]{24,}/[REDACTED]/g' >&2
  die service_failed_to_stay_active 44
}

case "$MODE" in
  probe)
    require_root
    install_claude_if_missing
    probe_eligibility
    ;;
  install)
    require_backend
    require_root
    install_claude_if_missing
    install_auth_helper
    prepare_workspace
    configure_mcp
    verify_private_mcp
    probe_eligibility
    install_service
    accept_remote_control_once
    start_service
    ;;
  verify)
    require_backend
    require_root
    verify_private_mcp
    probe_eligibility
    systemctl is-active --quiet "$SERVICE" || die service_inactive 45
    echo "CLAUDE_REMOTE_CONTROL_VERIFY=PASS"
    ;;
  *)
    die "unknown_mode:$MODE" 64
    ;;
esac
