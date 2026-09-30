#!/usr/bin/env bash
set -Eeuo pipefail

MODE="${1:-diagnose}"
UNIT_SOURCE="${2:-deploy/systemd/shopvivaliz-secure-mcp-tunnel.service}"
BACKEND_HOST="always-free-arm-1787907847-26"
TUNNEL_USER="ubuntu"
TUNNEL_HOME="/home/ubuntu"
PROFILE="shopvivaliz-remote-control"
ALIAS="shopvivaliz-private-mcp"
SERVICE="shopvivaliz-secure-mcp-tunnel.service"
UNIT_TARGET="/etc/systemd/system/${SERVICE}"
CANONICAL_BIN="/usr/local/bin/tunnel-client"

die() {
  printf 'SECURE_MCP_RUNTIME_FINAL=FAIL class=%s\n' "$1" >&2
  exit "${2:-1}"
}

require_backend_root() {
  [ "$(hostname)" = "$BACKEND_HOST" ] || die backend_host_mismatch 21
  [ "$(id -u)" -eq 0 ] || die root_required 22
}

run_as_tunnel_user() {
  sudo -u "$TUNNEL_USER" -H env     HOME="$TUNNEL_HOME"     XDG_CONFIG_HOME="$TUNNEL_HOME/.config"     "$@"
}

count_tunnel_processes() {
  local pids=""
  if pids="$(pgrep -u "$TUNNEL_USER" -x tunnel-client 2>/dev/null)"; then
    printf '%s\n' "$pids" | sed '/^$/d' | wc -l | tr -d '[:space:]'
  else
    echo 0
  fi
}

find_client() {
  local candidate=""
  if candidate="$(run_as_tunnel_user bash -lc 'command -v tunnel-client 2>/dev/null')" && [ -x "$candidate" ]; then
    printf '%s\n' "$candidate"
    return 0
  fi
  for candidate in "$TUNNEL_HOME/.local/bin/tunnel-client" "$CANONICAL_BIN"; do
    if [ -x "$candidate" ]; then
      printf '%s\n' "$candidate"
      return 0
    fi
  done
  return 1
}

doctor_ok() {
  local client="$1"
  local tmp
  tmp="$(mktemp)"
  trap 'rm -f "$tmp"' RETURN
  if run_as_tunnel_user "$client" doctor --profile "$PROFILE" --explain >"$tmp" 2>&1; then
    if grep -Eq '(^|[[:space:]])RESULT[[:space:]]+ok([[:space:]]|$)' "$tmp"; then
      rm -f "$tmp"
      trap - RETURN
      return 0
    fi
  fi
  rm -f "$tmp"
  trap - RETURN
  return 1
}

status_json() {
  local client="$1"
  local target="$2"
  run_as_tunnel_user "$client" runtimes status "$ALIAS" --json >"$target" 2>/dev/null
}

emit_runtime_snapshot() {
  local file="$1"
  local status_ok="$2"
  python3 - "$file" "$status_ok" <<'PY'
import json
import sys

path, status_ok = sys.argv[1], sys.argv[2]
data = {}
if status_ok == "true":
    try:
        with open(path, encoding="utf-8") as handle:
            payload = json.load(handle)
        if isinstance(payload, dict):
            data = payload
    except (OSError, UnicodeError, json.JSONDecodeError):
        data = {}

def lookup(obj, key):
    if isinstance(obj, dict):
        if key in obj:
            return obj[key]
        for value in obj.values():
            found = lookup(value, key)
            if found is not None:
                return found
    elif isinstance(obj, list):
        for value in obj:
            found = lookup(value, key)
            if found is not None:
                return found
    return None

def b(value, default=False):
    return "true" if value is True else "false" if value is False else ("true" if default else "false")

state = lookup(data, "runtime_state")
if not isinstance(state, str) or not state.replace("_", "").replace("-", "").isalnum():
    state = "unknown"

print(f"SECURE_MCP_RUNTIME_STATUS={status_ok}")
print(f"SECURE_MCP_RUNTIME_STATE={state[:40]}")
print(f"SECURE_MCP_RUNTIME_HEALTHY={b(lookup(data, 'healthy'))}")
print(f"SECURE_MCP_RUNTIME_READY={b(lookup(data, 'ready'))}")
print(f"SECURE_MCP_RUNTIME_STALE={b(lookup(data, 'stale'), default=True)}")
print(f"SECURE_MCP_RUNTIME_PROCESS_RUNNING={b(lookup(data, 'process_running'))}")
PY
}

initialization_error_count() {
  python3 - <<'PY'
import os
import time
from pathlib import Path

roots = (
    Path("/home/ubuntu/.local/state"),
    Path("/home/ubuntu/.codex/tunnel-mcp"),
)
needles = (
    b"mcp_initialization_required",
    b"MCP server is not initialized",
)
cutoff = time.time() - 2 * 24 * 3600
count = 0
for root in roots:
    if not root.exists():
        continue
    for base, _dirs, files in os.walk(root):
        if "tunnel" not in base.lower():
            continue
        for name in files:
            if not (name.endswith(".log") or name.endswith(".ndjson")):
                continue
            path = Path(base) / name
            try:
                stat = path.stat()
                if stat.st_mtime < cutoff or stat.st_size > 16 * 1024 * 1024:
                    continue
                data = path.read_bytes()
            except OSError:
                continue
            for needle in needles:
                count += data.count(needle)
print(count)
PY
}

diagnose() {
  require_backend_root

  local client=""
  local runtime_tmp
  runtime_tmp="$(mktemp)"
  trap 'rm -f "$runtime_tmp"' RETURN

  if client="$(find_client)"; then
    echo "SECURE_MCP_RUNTIME_CLIENT=true"
  else
    echo "SECURE_MCP_RUNTIME_CLIENT=false"
    : >"$runtime_tmp"
    emit_runtime_snapshot "$runtime_tmp" false
    echo "SECURE_MCP_RUNTIME_DOCTOR=false"
    echo "SECURE_MCP_RUNTIME_INITIALIZATION_ERRORS=$(initialization_error_count)"
    rm -f "$runtime_tmp"
    trap - RETURN
    return 0
  fi

  local status_ok=false
  if status_json "$client" "$runtime_tmp"; then
    status_ok=true
  fi
  emit_runtime_snapshot "$runtime_tmp" "$status_ok"

  if doctor_ok "$client"; then
    echo "SECURE_MCP_RUNTIME_DOCTOR=true"
  else
    echo "SECURE_MCP_RUNTIME_DOCTOR=false"
  fi

  echo "SECURE_MCP_RUNTIME_INITIALIZATION_ERRORS=$(initialization_error_count)"
  rm -f "$runtime_tmp"
  trap - RETURN
}

stop_managed_runtime() {
  local client="$1"
  local tmp
  tmp="$(mktemp)"
  trap 'rm -f "$tmp"' RETURN

  if status_json "$client" "$tmp"; then
    local running
    running="$(python3 - "$tmp" <<'PY'
import json, sys
try:
    with open(sys.argv[1], encoding="utf-8") as handle:
        data=json.load(handle)
except Exception:
    print("unknown")
    raise SystemExit(0)

def lookup(obj, key):
    if isinstance(obj, dict):
        if key in obj:
            return obj[key]
        for value in obj.values():
            found=lookup(value,key)
            if found is not None:
                return found
    elif isinstance(obj, list):
        for value in obj:
            found=lookup(value,key)
            if found is not None:
                return found
    return None

value=lookup(data,"process_running")
print("true" if value is True else "false" if value is False else "unknown")
PY
)"
    if [ "$running" = "true" ]; then
      run_as_tunnel_user "$client" runtimes stop "$ALIAS" >/dev/null
      for _ in $(seq 1 20); do
        [ "$(count_tunnel_processes)" = "0" ] && break
        sleep 1
      done
    elif [ "$running" = "unknown" ]; then
      die runtime_state_unknown_before_cutover 41
    fi
  fi

  rm -f "$tmp"
  trap - RETURN

  local count
  count="$(count_tunnel_processes)"
  if [ "$count" != "0" ]; then
    die duplicate_tunnel_client_before_cutover 42
  fi
}

install_runtime() {
  require_backend_root
  [ -f "$UNIT_SOURCE" ] || die unit_source_missing 30

  local client
  client="$(find_client)" || die tunnel_client_missing 31
  doctor_ok "$client" || die profile_doctor_failed 32

  if systemctl is-active --quiet "$SERVICE"; then
    systemctl stop "$SERVICE"
  fi

  stop_managed_runtime "$client"

  if [ "$client" != "$CANONICAL_BIN" ]; then
    install -m 0755 -o root -g root "$client" "$CANONICAL_BIN"
  fi
  "$CANONICAL_BIN" version >/dev/null 2>&1 || die canonical_binary_invalid 33
  install -m 0644 -o root -g root "$UNIT_SOURCE" "$UNIT_TARGET"
  systemctl daemon-reload
  systemctl enable "$SERVICE" >/dev/null
  systemctl restart "$SERVICE"

  local active=false
  for _ in $(seq 1 20); do
    if systemctl is-active --quiet "$SERVICE"; then
      sleep 2
      if systemctl is-active --quiet "$SERVICE"; then
        active=true
        break
      fi
    fi
    sleep 1
  done
  [ "$active" = true ] || die service_not_active 34

  local count
  count="$(count_tunnel_processes)"
  [ "$count" = "1" ] || die tunnel_client_process_count 35

  doctor_ok "$CANONICAL_BIN" || die post_install_doctor_failed 36

  local restarts
  restarts="$(systemctl show "$SERVICE" -p NRestarts --value)"
  case "$restarts" in
    ''|*[!0-9]*) die service_restart_counter_invalid 37 ;;
  esac
  [ "$restarts" -le 1 ] || die service_restart_loop 38

  echo "SECURE_MCP_RUNTIME_REPAIR=PASS"
}

validate_runtime() {
  require_backend_root
  systemctl is-enabled --quiet "$SERVICE" || die service_not_enabled 50
  systemctl is-active --quiet "$SERVICE" || die service_not_active 51
  [ -x "$CANONICAL_BIN" ] || die canonical_binary_missing 52
  doctor_ok "$CANONICAL_BIN" || die profile_doctor_failed 53

  local count
  count="$(count_tunnel_processes)"
  [ "$count" = "1" ] || die tunnel_client_process_count 54

  systemctl is-active --quiet shopvivaliz-remote-control-mcp.service || die controller_inactive 55

  echo "SECURE_MCP_RUNTIME_FINAL=PASS"
}

case "$MODE" in
  diagnose)
    diagnose
    ;;
  install)
    install_runtime
    ;;
  status)
    validate_runtime
    ;;
  *)
    die unsupported_mode 64
    ;;
esac
