#!/usr/bin/env bash
set -Eeuo pipefail

MODE="${1:-status}"
BRIDGE_SOURCE="${2:-scripts/claude-remote-control-mcp-stdio.py}"
UNIT_SOURCE="${3:-deploy/systemd/shopvivaliz-claude-remote-control.service}"
TRUST_HELPER_SOURCE="${4:-scripts/claude_workspace_trust_bootstrap.py}"
BACKEND_HOST="always-free-arm-1787907847-26"
CLAUDE_USER="ubuntu"
CLAUDE_HOME="/home/ubuntu"
CLAUDE_BIN="$CLAUDE_HOME/.local/bin/claude"
WORKSPACE="$CLAUDE_HOME/shopvivaliz-claude-workspace/site-shopvivaliz"
BRIDGE_TARGET="/usr/local/sbin/shopvivaliz-claude-mcp-stdio"
SETUP_TARGET="/usr/local/sbin/shopvivaliz-setup-claude-remote-control"
SUDOERS_FILE="/etc/sudoers.d/shopvivaliz-claude-mcp"
UNIT_TARGET="/etc/systemd/system/shopvivaliz-claude-remote-control.service"
SERVICE="shopvivaliz-claude-remote-control.service"

die(){ echo "CLAUDE_REMOTE_CONTROL_SETUP=FAIL reason=$1" >&2; exit "${2:-1}"; }
require_backend(){ [ "$(hostname)" = "$BACKEND_HOST" ] || die backend_host_mismatch 21; }
require_root(){ [ "$(id -u)" -eq 0 ] || die root_required 22; }
run_as_claude(){ sudo -u "$CLAUDE_USER" -H env -u ANTHROPIC_BASE_URL -u CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC -u DISABLE_GROWTHBOOK -u DISABLE_TELEMETRY -u DO_NOT_TRACK HOME="$CLAUDE_HOME" "$@"; }
run_in_workspace_as_claude(){ run_as_claude bash -c 'cd "$1"; shift; exec "$@"' bash "$WORKSPACE" "$@"; }

probe_auth_and_command(){
  test -x "$CLAUDE_BIN" || die claude_missing 30
  local tmp
  tmp="$(mktemp)"
  trap 'rm -f "$tmp"' RETURN
  if ! run_as_claude timeout 15s "$CLAUDE_BIN" auth status --json >"$tmp" 2>/dev/null; then
    die claude_auth_status_failed 31
  fi
  python3 - "$tmp" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as h:
    data=json.load(h)
raise SystemExit(0 if data.get("loggedIn") is True else 1)
PY
  run_as_claude timeout 15s "$CLAUDE_BIN" remote-control --help >/dev/null 2>&1 || die remote_control_unavailable 32
  echo "CLAUDE_REMOTE_CONTROL_ELIGIBLE=PASS"
}

install_bridge(){
  test -f "$BRIDGE_SOURCE" || die bridge_source_missing 40
  test -f /var/lib/shopvivaliz-remote-control/mcp-token || die mcp_token_missing 41
  install -m 0755 -o root -g root "$BRIDGE_SOURCE" "$BRIDGE_TARGET"
  printf '%s ALL=(root) NOPASSWD: %s\n' "$CLAUDE_USER" "$BRIDGE_TARGET" >"$SUDOERS_FILE"
  chmod 0440 "$SUDOERS_FILE"
  visudo -cf "$SUDOERS_FILE" >/dev/null
  install -m 0755 -o root -g root "$0" "$SETUP_TARGET"
  echo "CLAUDE_MCP_BRIDGE_INSTALL=PASS"
}

prepare_workspace(){
  install -d -m 0755 -o "$CLAUDE_USER" -g "$CLAUDE_USER" "$(dirname "$WORKSPACE")"
  if [ ! -d "$WORKSPACE/.git" ]; then
    run_as_claude git clone --origin origin https://github.com/Vivaliz-site/site-shopvivaliz.git "$WORKSPACE" >/dev/null
  else
    test "$(run_as_claude git -C "$WORKSPACE" remote get-url origin)" = "https://github.com/Vivaliz-site/site-shopvivaliz.git" || die workspace_origin_mismatch 42
    run_as_claude git -C "$WORKSPACE" fetch origin main --quiet
  fi
  echo "CLAUDE_WORKSPACE=PASS"
}

configure_mcp(){
  local config
  config='{"type":"stdio","command":"sudo","args":["-n","/usr/local/sbin/shopvivaliz-claude-mcp-stdio"]}'
  if run_as_claude "$CLAUDE_BIN" mcp get shopvivaliz-remote-control >/dev/null 2>&1; then
    run_as_claude "$CLAUDE_BIN" mcp remove shopvivaliz-remote-control --scope user >/dev/null
  fi
  run_as_claude "$CLAUDE_BIN" mcp add-json shopvivaliz-remote-control "$config" --scope user >/dev/null
  echo "CLAUDE_MCP_CONFIG=PASS"
}

verify_bridge(){
  systemctl is-active --quiet shopvivaliz-remote-control-mcp.service || die controller_inactive 43
  local out bridge_rc classification
  bridge_rc=0
  if out="$(printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' | run_as_claude sudo -n "$BRIDGE_TARGET" 2>/dev/null)"; then
    bridge_rc=0
  else
    bridge_rc=$?
  fi
  if [ "$bridge_rc" -ne 0 ]; then
    echo "CLAUDE_PRIVATE_MCP_BRIDGE=FAIL class=adapter"
    return 54
  fi
  classification="$(python3 - "$out" <<'PY'
import json,sys
try:
    p=json.loads(sys.argv[1])
except Exception:
    print("json")
    raise SystemExit(0)
names={x.get("name") for x in p.get("result",{}).get("tools",[]) if isinstance(x,dict)}
required={"hosts_list","host_health","task_submit","task_status"}
print("ok" if required.issubset(names) else "tools")
PY
)"
  case "$classification" in
    ok)
      echo "CLAUDE_PRIVATE_MCP_BRIDGE=PASS"
      ;;
    json)
      echo "CLAUDE_PRIVATE_MCP_BRIDGE=FAIL class=json"
      return 55
      ;;
    tools)
      echo "CLAUDE_PRIVATE_MCP_BRIDGE=FAIL class=tools"
      return 56
      ;;
    *)
      echo "CLAUDE_PRIVATE_MCP_BRIDGE=FAIL class=json"
      return 55
      ;;
  esac
}

run_consent_attempt(){
  local out="$1"
  if printf 'y\n' | run_in_workspace_as_claude timeout 18s "$CLAUDE_BIN" remote-control --name ShopVivaliz-Bootstrap --spawn worktree --capacity 1 --no-create-session-in-dir --permission-mode default >"$out" 2>&1; then
    return 0
  fi
  return $?
}

bootstrap_workspace_trust(){
  local trust_out trust_rc
  test -f "$TRUST_HELPER_SOURCE" || die trust_helper_missing 57
  trust_out="$(mktemp)"
  trust_rc=0
  if run_in_workspace_as_claude timeout 90s python3 "$TRUST_HELPER_SOURCE" "$CLAUDE_BIN" >"$trust_out" 2>&1; then
    trust_rc=0
  else
    trust_rc=$?
  fi
  awk '/^CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=/{print}' "$trust_out"
  if [ "$trust_rc" -ne 0 ] || ! grep -Fqx 'CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=PASS' "$trust_out"; then
    rm -f "$trust_out"
    return 1
  fi
  rm -f "$trust_out"
}

accept_consent(){
  local out rc
  out="$(mktemp)"
  rc=0
  if run_consent_attempt "$out"; then
    rc=0
  else
    rc=$?
  fi
  if grep -Eqi 'workspace trust|trusted directory|trust (this|the) (folder|directory|workspace|project)|accept.*trust' "$out"; then
    rm -f "$out"
    if ! bootstrap_workspace_trust; then
      echo "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=trust"
      return 47
    fi
    out="$(mktemp)"
    rc=0
    if run_consent_attempt "$out"; then
      rc=0
    else
      rc=$?
    fi
  fi
  if grep -Eqi 'requires a claude\.ai subscription|run.*/login|sign in|not logged in' "$out"; then
    rm -f "$out"
    echo "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=auth"
    return 44
  fi
  if grep -Eqi 'disabled by your organization|not enabled|ineligible|trusted device' "$out"; then
    rm -f "$out"
    echo "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=policy"
    return 45
  fi
  if grep -Eqi 'not a git repository|requires? a git repository|git repository required|worktree.*repository|project directory' "$out"; then
    rm -f "$out"
    echo "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=repo"
    return 46
  fi
  if grep -Eqi 'workspace trust|trusted directory|trust (this|the) (folder|directory|workspace|project)|accept.*trust' "$out"; then
    rm -f "$out"
    echo "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=trust"
    return 47
  fi
  if grep -Eqi 'tty|terminal required|not a terminal|interactive input|stdin.*terminal' "$out"; then
    rm -f "$out"
    echo "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=tty"
    return 48
  fi
  if grep -Eqi 'unknown (option|argument)|unrecognized (option|argument)|invalid.*permission.mode|unexpected argument' "$out"; then
    rm -f "$out"
    echo "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=flag"
    return 49
  fi
  if [ "$rc" -ne 0 ] && [ "$rc" -ne 124 ]; then
    rm -f "$out"
    echo "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=other"
    return 50
  fi
  rm -f "$out"
  echo "CLAUDE_REMOTE_CONTROL_CONSENT=PASS"
}

install_service(){
  test -f "$UNIT_SOURCE" || die unit_source_missing 47
  install -m 0644 -o root -g root "$UNIT_SOURCE" "$UNIT_TARGET"
  systemctl daemon-reload
  systemctl enable "$SERVICE" >/dev/null
  systemctl restart "$SERVICE"
  for _ in $(seq 1 15); do
    if systemctl is-active --quiet "$SERVICE"; then
      sleep 2
      if systemctl is-active --quiet "$SERVICE"; then
        echo "CLAUDE_REMOTE_CONTROL_SERVICE=PASS"
        return 0
      fi
    fi
    sleep 1
  done
  die service_failed_to_stay_active 48
}

status(){
  require_backend
  require_root
  probe_auth_and_command
  test -x "$BRIDGE_TARGET" || die bridge_missing 50
  visudo -cf "$SUDOERS_FILE" >/dev/null || die sudoers_invalid 51
  verify_bridge
  systemctl is-enabled --quiet "$SERVICE" || die service_not_enabled 52
  systemctl is-active --quiet "$SERVICE" || die service_not_active 53
  echo "CLAUDE_REMOTE_CONTROL_STATUS=PASS"
}

case "$MODE" in
  install)
    require_backend; require_root
    probe_auth_and_command
    install_bridge
    prepare_workspace
    configure_mcp
    verify_bridge
    if systemctl is-active --quiet "$SERVICE"; then
      systemctl stop "$SERVICE"
    fi
    accept_consent
    install_service
    echo "CLAUDE_REMOTE_CONTROL_INSTALL=PASS"
    ;;
  status)
    status
    ;;
  *)
    die unknown_mode 64
    ;;
esac
