#!/usr/bin/env bash
set -Eeuo pipefail

SERVICE='shopvivaliz-desktop-commander.service'
DEVICE_DIR='/home/ubuntu/.desktop-commander-device'
DEVICE_FILE="$DEVICE_DIR/device.json"
COOLDOWN_FILE="$DEVICE_DIR/auth-required.cooldown"
LOCK_FILE='/run/lock/shopvivaliz-desktop-commander-guardian.lock'
REMOTE_PATTERN='(@wonderwhy-er/desktop-commander@0[.]2[.]47 remote --persist-session|shopvivaliz-dc-remote|desktop-commander/dist/index[.]js remote --persist-session)'
SERVICE_CGROUP="/system.slice/$SERVICE"
AUTH_RETRY_MINUTES="${AUTH_RETRY_MINUTES:-15}"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  exit 0
fi

log_guardian() {
  if ! logger -t shopvivaliz-dc-guardian -- "$*" 2>/dev/null; then
    return 0
  fi
}

proc_cgroup() {
  local value=''
  if value="$(awk -F: '$1 == "0" { print $3 }' "/proc/$1/cgroup" 2>/dev/null)"; then
    printf '%s\n' "$value"
  fi
  return 0
}
proc_parent() {
  local value=''
  if value="$(awk '/^PPid:/ { print $2 }' "/proc/$1/status" 2>/dev/null)"; then
    printf '%s\n' "$value"
  fi
  return 0
}

is_dc_wrapper() {
  local pid="$1" cmd=''
  if [[ ! -r "/proc/$pid/cmdline" ]]; then
    return 1
  fi
  if ! cmd="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null)"; then
    return 1
  fi
  [[ "$cmd" == *'desktop-commander'* ]]
}

foreign_root_for_pid() {
  local pid="$1" root parent cg pcg
  root="$pid"
  cg="$(proc_cgroup "$pid")"
  while :; do
    parent="$(proc_parent "$root")"
    [[ "$parent" =~ ^[0-9]+$ ]] || break
    (( parent > 1 )) || break
    pcg="$(proc_cgroup "$parent")"
    [[ "$pcg" == "$cg" ]] || break
    is_dc_wrapper "$parent" || break
    root="$parent"
  done
  printf '%s\n' "$root"
}

kill_tree() {
  local root="$1" child children=''
  if children="$(pgrep -P "$root" 2>/dev/null)"; then
    while read -r child; do
      [[ -n "$child" ]] && kill_tree "$child"
    done <<< "$children"
  fi
  if ! kill -TERM "$root" 2>/dev/null; then
    :
  fi
  sleep 1
  if ! kill -KILL "$root" 2>/dev/null; then
    :
  fi
}

kill_foreign_launchers() {
  local pid cg root matches=''
  declare -A seen=()
  if ! matches="$(pgrep -f "$REMOTE_PATTERN" 2>/dev/null)"; then
    return 0
  fi
  while read -r pid; do
    [[ -n "$pid" ]] || continue
    cg="$(proc_cgroup "$pid")"
    [[ "$cg" == "$SERVICE_CGROUP" ]] && continue
    root="$(foreign_root_for_pid "$pid")"
    [[ -n "${seen[$root]:-}" ]] && continue
    seen[$root]=1
    log_guardian "foreign_launcher_removed pid=$root cgroup=${cg:-unknown}"
    kill_tree "$root"
  done <<< "$matches"
}

auth_blocked() {
  [[ -f "$DEVICE_FILE" ]] || return 0
  [[ -f "$COOLDOWN_FILE" ]] || return 1
  if [[ "$DEVICE_FILE" -nt "$COOLDOWN_FILE" ]]; then
    rm -f "$COOLDOWN_FILE"
    log_guardian 'stale_auth_cooldown_cleared=true'
    return 1
  fi
  if find "$COOLDOWN_FILE" -mmin "-$AUTH_RETRY_MINUTES" -print -quit | grep -q .; then
    return 0
  fi
  rm -f "$COOLDOWN_FILE"
  return 1
}

service_state() {
  local value='unknown'
  if value="$(systemctl is-active "$SERVICE" 2>/dev/null)"; then
    printf '%s\n' "$value"
    return 0
  fi
  if [[ -n "$value" ]]; then
    printf '%s\n' "$value"
  else
    printf 'unknown\n'
  fi
}

kill_foreign_launchers
active="$(service_state)"
if [[ "$active" != 'active' ]]; then
  if auth_blocked; then
    log_guardian 'service_not_started reason=provider_auth_or_missing_device_state'
    exit 0
  fi
  if ! systemctl reset-failed "$SERVICE" 2>/dev/null; then
    log_guardian 'reset_failed_warning=true'
  fi
  systemctl start "$SERVICE"
  sleep 5
fi

kill_foreign_launchers
active="$(service_state)"
if [[ "$active" != 'active' ]] && ! auth_blocked; then
  log_guardian "service_recovery_failed state=${active:-unknown}"
  exit 1
fi
exit 0
