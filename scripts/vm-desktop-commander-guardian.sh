#!/usr/bin/env bash
set -Eeuo pipefail

SERVICE='shopvivaliz-desktop-commander.service'
DEVICE_DIR='/home/ubuntu/.desktop-commander-device'
DEVICE_FILE="$DEVICE_DIR/device.json"
COOLDOWN_FILE="$DEVICE_DIR/auth-required.cooldown"
LOCK_FILE='/run/lock/shopvivaliz-desktop-commander-guardian.lock'
REMOTE_PATTERN='(@wonderwhy-er/desktop-commander@0[.]2[.]47 remote --persist-session|shopvivaliz-dc-remote|desktop-commander/dist/index[.]js remote --persist-session)'
SERVICE_CGROUP="/system.slice/$SERVICE"
AUTH_RETRY_MINUTES="${AUTH_RETRY_MINUTES:-360}"

exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

log_guardian() {
  logger -t shopvivaliz-dc-guardian -- "$*" 2>/dev/null || true
}

proc_cgroup() {
  awk -F: '$1 == "0" { print $3 }' "/proc/$1/cgroup" 2>/dev/null || true
}

proc_parent() {
  awk '/^PPid:/ { print $2 }' "/proc/$1/status" 2>/dev/null || true
}
is_dc_wrapper() {
  local pid="$1" cmd
  [[ -r "/proc/$pid/cmdline" ]] || return 1
  cmd="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null || true)"
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
  local root="$1" child
  while read -r child; do
    [[ -n "$child" ]] && kill_tree "$child"
  done < <(pgrep -P "$root" 2>/dev/null || true)
  kill -TERM "$root" 2>/dev/null || true
  sleep 1
  kill -KILL "$root" 2>/dev/null || true
}

kill_foreign_launchers() {
  local pid cg root
  declare -A seen=()
  while read -r pid; do
    [[ -n "$pid" ]] || continue
    cg="$(proc_cgroup "$pid")"
    [[ "$cg" == "$SERVICE_CGROUP" ]] && continue
    root="$(foreign_root_for_pid "$pid")"
    [[ -n "${seen[$root]:-}" ]] && continue
    seen[$root]=1
    log_guardian "foreign_launcher_removed pid=$root cgroup=${cg:-unknown}"
    kill_tree "$root"
  done < <(pgrep -f "$REMOTE_PATTERN" 2>/dev/null || true)
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

kill_foreign_launchers
active="$(systemctl is-active "$SERVICE" 2>/dev/null || true)"
if [[ "$active" != 'active' ]]; then
  if auth_blocked; then
    log_guardian 'service_not_started reason=provider_auth_or_missing_device_state'
    exit 0
  fi
  systemctl reset-failed "$SERVICE" 2>/dev/null || true
  systemctl start "$SERVICE"
  sleep 5
fi

kill_foreign_launchers
active="$(systemctl is-active "$SERVICE" 2>/dev/null || true)"
if [[ "$active" != 'active' ]] && ! auth_blocked; then
  log_guardian "service_recovery_failed state=${active:-unknown}"
  exit 1
fi
exit 0
