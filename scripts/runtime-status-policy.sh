#!/usr/bin/env bash
set -Eeuo pipefail

role="${1:-}"
health="ok"

degrade() {
  health="degraded"
}

attention() {
  if [ "$health" = "ok" ]; then
    health="attention"
  fi
}

unit_value() {
  local unit="$1"
  local prop="$2"
  local value=""
  if value="$(systemctl show "$unit" -p "$prop" --value 2>/dev/null)"; then
    :
  fi
  if [ -z "$value" ] && [ "$prop" = "LoadState" ]; then
    value="not-found"
  fi
  printf '%s' "$value"
}

user_unit_value() {
  local unit="$1"
  local prop="$2"
  local uid runtime value=""
  uid="$(id -u)"
  runtime="/run/user/$uid"
  if value="$(env XDG_RUNTIME_DIR="$runtime" DBUS_SESSION_BUS_ADDRESS="unix:path=$runtime/bus" systemctl --user show "$unit" -p "$prop" --value 2>/dev/null)"; then
    :
  fi
  if [ -z "$value" ] && [ "$prop" = "LoadState" ]; then
    value="not-found"
  fi
  printf '%s' "$value"
}

report_required_active() {
  local unit="$1"
  local load active sub enabled
  load="$(unit_value "$unit" LoadState)"
  active="$(unit_value "$unit" ActiveState)"
  sub="$(unit_value "$unit" SubState)"
  if enabled="$(systemctl is-enabled "$unit" 2>/dev/null)"; then
    :
  else
    enabled="unknown"
  fi
  printf 'UNIT=%s LOAD=%s ACTIVE=%s SUB=%s ENABLED=%s EXPECTED=active\n'     "$unit" "$load" "$active" "$sub" "${enabled:-unknown}"
  if [ "$load" != "loaded" ] || [ "$active" != "active" ]; then
    degrade
  fi
}

report_user_required_active() {
  local unit="$1"
  local uid runtime load active sub enabled
  uid="$(id -u)"
  runtime="/run/user/$uid"
  load="$(user_unit_value "$unit" LoadState)"
  active="$(user_unit_value "$unit" ActiveState)"
  sub="$(user_unit_value "$unit" SubState)"
  if enabled="$(env XDG_RUNTIME_DIR="$runtime" DBUS_SESSION_BUS_ADDRESS="unix:path=$runtime/bus" systemctl --user is-enabled "$unit" 2>/dev/null)"; then
    :
  else
    enabled="unknown"
  fi
  printf 'USER_UNIT=%s LOAD=%s ACTIVE=%s SUB=%s ENABLED=%s EXPECTED=active\n'     "$unit" "$load" "$active" "$sub" "${enabled:-unknown}"
  if [ "$load" != "loaded" ] || [ "$active" != "active" ]; then
    degrade
  fi
}

report_oneshot_success() {
  local unit="$1"
  local load active result exec_status
  load="$(unit_value "$unit" LoadState)"
  active="$(unit_value "$unit" ActiveState)"
  result="$(unit_value "$unit" Result)"
  exec_status="$(unit_value "$unit" ExecMainStatus)"
  printf 'UNIT=%s LOAD=%s ACTIVE=%s RESULT=%s EXEC_MAIN_STATUS=%s EXPECTED=oneshot-success\n'     "$unit" "$load" "$active" "${result:-unknown}" "${exec_status:-unknown}"
  if [ "$load" != "loaded" ] || [ "$result" != "success" ] || [ "$exec_status" != "0" ]; then
    degrade
  fi
}

report_http_health() {
  local label="$1"
  local url="$2"
  local state="down"
  if curl -fsS --connect-timeout 2 --max-time 4 -o /dev/null "$url"; then
    state="ok"
  else
    degrade
  fi
  printf 'ENDPOINT=%s STATE=%s\n' "$label" "$state"
}

report_mei_worker_policy() {
  local unit="mei-mg-email-worker.service"
  local sender_block="/var/lib/mei-mg-email/sender_blocked.pause"
  local load active
  load="$(unit_value "$unit" LoadState)"
  active="$(unit_value "$unit" ActiveState)"

  if [ -f "$sender_block" ]; then
    printf 'UNIT=%s LOAD=%s ACTIVE=%s EXPECTED=inactive-sender-block\n' "$unit" "$load" "$active"
    echo "SENDER_BLOCK=active"
    if [ "$load" != "loaded" ] || [ "$active" != "inactive" ]; then
      degrade
    else
      attention
    fi
  else
    printf 'UNIT=%s LOAD=%s ACTIVE=%s EXPECTED=active-no-sender-block\n' "$unit" "$load" "$active"
    echo "SENDER_BLOCK=inactive"
    if [ "$load" != "loaded" ] || [ "$active" != "active" ]; then
      degrade
    fi
  fi
}

report_disk() {
  local use_pct disk_health="ok"
  use_pct="$(df -P / | awk 'NR==2 {gsub(/%/,"",$5); print $5}')"
  if ! [[ "$use_pct" =~ ^[0-9]+$ ]]; then
    echo "DISK_USE_PCT=unknown DISK_HEALTH=unknown"
    degrade
    return
  fi
  if [ "$use_pct" -ge 95 ]; then
    disk_health="critical"
    degrade
  elif [ "$use_pct" -ge 85 ]; then
    disk_health="warning"
    attention
  fi
  printf 'DISK_USE_PCT=%s DISK_HEALTH=%s\n' "$use_pct" "$disk_health"
}

echo "RUNTIME_STATUS_BEGIN"
echo "HOST=$(hostname)"
echo "ROLE=$role"
echo "POLICY=runtime-status-policy-v1"

case "$role" in
  site)
    report_required_active apache2.service
    report_required_active shopvivaliz-queue-worker.service
    report_required_active shopvivaliz-token-renewer.service
    report_required_active shopvivaliz-shopee-token-renewer.service
    report_required_active shopvivaliz-agent.service
    report_required_active shopvivaliz-catalog-reconcile.timer
    report_required_active shopvivaliz-sync-safe.timer
    report_required_active shopvivaliz-abandoned-cart-recovery.timer
    report_oneshot_success shopvivaliz-catalog-reconcile.service
    report_oneshot_success shopvivaliz-sync-safe.service
    ;;
  backend)
    report_required_active shopvivaliz-remote-control-mcp.service
    report_user_required_active shopvivaliz-chatgpt-continuity.service
    report_http_health remote-control-mcp http://127.0.0.1:5580/health
    report_http_health chatgpt-cdp http://127.0.0.1:9555/json/version
    report_mei_worker_policy
    ;;
  *)
    echo "ERROR=unsupported-role"
    echo "RUNTIME_STATUS_END"
    exit 2
    ;;
esac

report_disk
echo "RUNTIME_HEALTH=$health"
echo "RUNTIME_STATUS_END"

if [ "$health" = "degraded" ]; then
  exit 1
fi
