#!/usr/bin/env bash
set -Eeuo pipefail

HOME_DIR="${HOME:-/home/ubuntu}"
DEVICE_DIR="$HOME_DIR/.desktop-commander-device"
DEVICE_FILE="$DEVICE_DIR/device.json"
SESSION_BACKUP_DIR="$DEVICE_DIR/session-backup"
SESSION_BACKUP_FILE="$DEVICE_DIR/session-backup/device.json"
COOLDOWN_FILE="$DEVICE_DIR/auth-required.cooldown"
CONNECTED_MARKER="$DEVICE_DIR/provider-connected.marker"
LOCK_FILE="$DEVICE_DIR/remote-owner.lock"
PACKAGE='@wonderwhy-er/desktop-commander@0.2.47'
NPX_BIN="${NPX_BIN:-npx}"
AUTH_REGEX='Please complete authentication|Starting device authorization flow|device code|Authorization required'
CONNECTED_REGEX='Device ready|Found persisted session|Connected to Remote MCP|WebSocket connected'
REMOTE_OWNER_PID="$$"
REMOTE_OWNER_SESSION='systemd'

mkdir -p "$DEVICE_DIR"
install -d -m 700 "$SESSION_BACKUP_DIR"

backup_device_state() {
  if [[ -s "$DEVICE_FILE" ]]; then
    install -m 600 "$DEVICE_FILE" "$SESSION_BACKUP_FILE"
  fi
}

restore_device_state() {
  if [[ ! -f "$DEVICE_FILE" && -s "$SESSION_BACKUP_FILE" ]]; then
    install -m 600 "$SESSION_BACKUP_FILE" "$DEVICE_FILE"
    echo 'SESSION_RESTORED=true reason=primary_device_state_missing'
  fi
}

restore_device_state

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  echo 'REMOTE_OWNER_CONFLICT=true reason=supervisor_lock_held'
  exit 0
fi
chmod 0600 "$LOCK_FILE"

if [[ ! -f "$DEVICE_FILE" ]]; then
  echo 'AUTH_REQUIRED=true reason=device_state_missing'
  exit 20
fi

device_state_newer_than_cooldown() {
  [[ -f "$DEVICE_FILE" && -f "$COOLDOWN_FILE" && "$DEVICE_FILE" -nt "$COOLDOWN_FILE" ]]
}

find_competing_remote_sessions() {
  pgrep -f 'npm exec @wonderwhy-er/desktop-commander@0\.2\.47 remote --persist-session' 2>/dev/null || true
}

terminate_process_tree() {
  local pid="$1" child
  while read -r child; do
    [[ -n "$child" ]] && terminate_process_tree "$child"
  done < <(pgrep -P "$pid" 2>/dev/null || true)
  kill -TERM "$pid" 2>/dev/null || true
}

terminate_competing_remote_sessions() {
  local pid
  while read -r pid; do
    [[ -z "$pid" || "$pid" = "$REMOTE_OWNER_PID" ]] && continue
    terminate_process_tree "$pid"
  done < <(find_competing_remote_sessions)
}

if [[ -f "$COOLDOWN_FILE" ]]; then
  if device_state_newer_than_cooldown; then
    rm -f "$COOLDOWN_FILE"
    echo 'AUTH_COOLDOWN_CLEARED=true reason=newer_device_state'
  elif find "$COOLDOWN_FILE" -mmin -360 -print -quit | grep -q .; then
    echo 'AUTH_REQUIRED=true reason=recent_provider_reauth_request'
    exit 20
  else
    rm -f "$COOLDOWN_FILE"
  fi
fi

terminate_competing_remote_sessions
for _ in {1..20}; do
  [[ -z "$(find_competing_remote_sessions)" ]] && break
  sleep 1
done
if [[ -n "$(find_competing_remote_sessions)" ]]; then
  echo 'REMOTE_OWNER_CONFLICT=true reason=competing_remote_session_survived'
  exit 21
fi

tmp="$(mktemp)"
chmod 0600 "$tmp"
child=''
connected=0
cleanup() {
  if [[ "$connected" -eq 1 ]]; then backup_device_state; fi
  rm -f "$tmp"
  if [[ -n "$child" ]] && kill -0 "$child" 2>/dev/null; then
    kill -TERM -- "-$child" 2>/dev/null || kill -TERM "$child" 2>/dev/null || true
  fi
  rm -f "$CONNECTED_MARKER"
}
trap cleanup EXIT INT TERM
rm -f "$CONNECTED_MARKER"

echo "REMOTE_OWNER_PID=$REMOTE_OWNER_PID REMOTE_OWNER_SESSION=$REMOTE_OWNER_SESSION"
setsid "$NPX_BIN" --yes "$PACKAGE" remote --persist-session >"$tmp" 2>&1 &
child=$!
auth_required=0
while kill -0 "$child" 2>/dev/null; do
  if grep -Eqi "$AUTH_REGEX" "$tmp"; then
    auth_required=1
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
    backup_device_state
    connected=1
    echo 'REMOTE_CONNECTED=true'
  fi
  sleep 1
done

rc=0
if wait "$child"; then rc=0; else rc=$?; fi
if [[ "$connected" -eq 1 ]]; then backup_device_state; fi
if [[ "$auth_required" -eq 0 ]] && grep -Eqi "$AUTH_REGEX" "$tmp"; then
  auth_required=1
  : > "$COOLDOWN_FILE"
  chmod 0600 "$COOLDOWN_FILE"
fi
if [[ "$auth_required" -eq 1 ]]; then
  echo 'AUTH_REQUIRED=true reason=provider_device_flow'
  exit 20
fi

echo "REMOTE_AGENT_EXIT_RC=$rc"
exit "$rc"
