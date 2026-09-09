#!/usr/bin/env bash
set -Eeuo pipefail

SSH_BIN="${SV_RELAY_SSH_BIN:-ssh}"
VM_HOST="${SV_RELAY_VM_HOST:-144.22.157.209}"
VM_USER="${SV_RELAY_VM_USER:-ubuntu}"
IDENTITY_FILE="${SV_RELAY_IDENTITY_FILE:-$HOME/.ssh/id_rsa}"
KNOWN_HOSTS_FILE="${SV_RELAY_KNOWN_HOSTS_FILE:-$HOME/.ssh/known_hosts}"

probe_relay() {
  local port="$1"
  local expected_environment="$2"
  local health rc

  if ! health="$("$SSH_BIN" \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=yes \
    -o "UserKnownHostsFile=$KNOWN_HOSTS_FILE" \
    -o ConnectTimeout=10 \
    -i "$IDENTITY_FILE" \
    "$VM_USER@$VM_HOST" \
    "curl -fsS --connect-timeout 3 --max-time 8 http://127.0.0.1:$port/health" \
    2>/dev/null)"; then
    return 1
  fi
  EXPECTED_ENVIRONMENT="$expected_environment" python3 -c '
import json, os, sys
payload = json.load(sys.stdin)
assert payload.get("status") == "ok"
assert payload.get("environment") == os.environ["EXPECTED_ENVIRONMENT"]
assert payload.get("mcp_version") == "1.0.0"
' <<<"$health" >/dev/null 2>&1
}

selected_port=""
if probe_relay 5557 fred-win; then
  selected_port=5557
elif probe_relay 5558 desktop-kocepsv; then
  selected_port=5558
else
  echo 'No healthy allowlisted Windows browser relay is available' >&2
  exit 7
fi

printf '%s\n' "$selected_port"
