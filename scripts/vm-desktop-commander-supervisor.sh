#!/usr/bin/env bash
set -Eeuo pipefail

HOME_DIR="${HOME:-/home/ubuntu}"
DEVICE_FILE="$HOME_DIR/.desktop-commander-device/device.json"
COOLDOWN_FILE="$HOME_DIR/.desktop-commander-device/auth-required.cooldown"
CONNECTED_MARKER="$HOME_DIR/.desktop-commander-device/provider-connected.marker"
PACKAGE='@wonderwhy-er/desktop-commander@0.2.47'
NPX_BIN="${NPX_BIN:-npx}"
AUTH_REGEX='Please complete authentication|Starting device authorization flow|device code|Authorization required'
CONNECTED_REGEX='Device ready|Found persisted session|Connected to Remote MCP|WebSocket connected'

if [[ ! -f "$DEVICE_FILE" ]]; then
  echo 'AUTH_REQUIRED=true reason=device_state_missing'
  exit 20
fi

if [[ -f "$COOLDOWN_FILE" ]]; then
  if find "$COOLDOWN_FILE" -mmin -360 -print -quit | grep -q .; then
    echo 'AUTH_REQUIRED=true reason=recent_provider_reauth_request'
    exit 20
  fi
  rm -f "$COOLDOWN_FILE"
fi

tmp="$(mktemp)"
chmod 0600 "$tmp"
child=''
cleanup() {
  rm -f "$tmp"
  if [[ -n "$child" ]] && kill -0 "$child" 2>/dev/null; then
    kill -TERM -- "-$child" 2>/dev/null || kill -TERM "$child" 2>/dev/null || true
  fi
  rm -f "$CONNECTED_MARKER"
}
trap cleanup EXIT INT TERM
rm -f "$CONNECTED_MARKER"

setsid "$NPX_BIN" --yes "$PACKAGE" remote --persist-session >"$tmp" 2>&1 &
child=$!
connected=0
auth_required=0
while kill -0 "$child" 2>/dev/null; do
  if grep -Eqi "$AUTH_REGEX" "$tmp"; then
    auth_required=1
    mkdir -p "$(dirname "$COOLDOWN_FILE")"
    : > "$COOLDOWN_FILE"
    chmod 0600 "$COOLDOWN_FILE"
    kill -TERM -- "-$child" 2>/dev/null || kill -TERM "$child" 2>/dev/null || true
    for _ in {1..10}; do
      kill -0 "$child" 2>/dev/null || break
      sleep 1
    done
    kill -KILL -- "-$child" 2>/dev/null || kill -KILL "$child" 2>/dev/null || true
    break
  fi
  if [[ "$connected" -eq 0 ]] && grep -Eqi "$CONNECTED_REGEX" "$tmp"; then
    : > "$CONNECTED_MARKER"
    chmod 0600 "$CONNECTED_MARKER"
    connected=1
    echo 'REMOTE_CONNECTED=true'
  fi
  sleep 1
done

rc=0
if wait "$child"; then rc=0; else rc=$?; fi
if [[ "$auth_required" -eq 0 ]] && grep -Eqi "$AUTH_REGEX" "$tmp"; then
  auth_required=1
  mkdir -p "$(dirname "$COOLDOWN_FILE")"
  : > "$COOLDOWN_FILE"
  chmod 0600 "$COOLDOWN_FILE"
fi
if [[ "$auth_required" -eq 1 ]]; then
  echo 'AUTH_REQUIRED=true reason=provider_device_flow'
  exit 20
fi

echo "REMOTE_AGENT_EXIT_RC=$rc"
exit "$rc"
