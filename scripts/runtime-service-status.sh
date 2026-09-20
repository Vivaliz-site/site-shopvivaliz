#!/usr/bin/env bash
set -Eeuo pipefail

role="${1:-}"
health="ok"

degrade() {
  health="degraded"
}

attention() {
  if [[ "$health" == "ok" ]]; then
    health="attention"
  fi
}

unit_value() {
  local unit="$1"
  local prop="$2"
  local value
  if value="$(systemctl show "$unit" -p "$prop" --value 2>/dev/null)"; then
    :
  else
    value=""
  fi
  if [[ -z "$value" && "$prop" == "LoadState" ]]; then
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
  if [[ "$load" != "loaded" || "$active" != "active" ]]; then
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
  if [[ "$load" != "loaded" || "$result" != "success" || "$exec_status" != "0" ]]; then
    degrade
  fi
}

report_expected_absent() {
  local unit="$1"
  local load
  load="$(unit_value "$unit" LoadState)"
  printf 'UNIT=%s LOAD=%s EXPECTED=absent-retired\n' "$unit" "$load"
  if [[ "$load" != "not-found" ]]; then
    degrade
  fi
}

report_disk() {
  local use_pct disk_health
  use_pct="$(df -P / | awk 'NR==2 {gsub(/%/,"",$5); print $5}')"
  disk_health="ok"
  if (( use_pct >= 95 )); then
    disk_health="critical"
    degrade
  elif (( use_pct >= 85 )); then
    disk_health="warning"
    attention
  fi
  printf 'DISK_USE_PCT=%s DISK_HEALTH=%s\n' "$use_pct" "$disk_health"
}

echo "RUNTIME_STATUS_BEGIN"
echo "HOST=$(hostname)"
echo "ROLE=$role"

case "$role" in
  site)
    report_required_active apache2.service
    report_required_active shopvivaliz-queue-worker.service
    report_required_active shopvivaliz-token-renewer.service
    report_required_active shopvivaliz-shopee-token-renewer.service
    report_required_active shopvivaliz-catalog-reconcile.timer
    report_required_active shopvivaliz-desktop-commander.service
    report_oneshot_success shopvivaliz-catalog-reconcile.service

    report_expected_absent shopvivaliz-products-active-sync.service
    report_expected_absent shopvivaliz-agent.service
    report_expected_absent shopvivaliz-agent-bridge.service
    report_expected_absent shopvivaliz-catalog-audit.service
    report_expected_absent shopvivaliz-orchestrator.service
    ;;
  backend)
    report_required_active shopvivaliz-desktop-commander.service
    report_required_active mei-mg-email-api.service
    report_required_active mei-mg-email-monitor.service
    report_required_active mei-mg-email-queue-replenisher.service
    report_required_active mei-mg-email-brevo-reconciler.service
    report_required_active mei-mg-email-site-tunnel.service

    worker_unit="mei-mg-email-worker.service"
    worker_load="$(unit_value "$worker_unit" LoadState)"
    worker_active="$(unit_value "$worker_unit" ActiveState)"
    sender_block="/var/lib/mei-mg-email/sender_blocked.pause"
    if [[ -f "$sender_block" ]]; then
      printf 'UNIT=%s LOAD=%s ACTIVE=%s EXPECTED=inactive-sender-block\n'         "$worker_unit" "$worker_load" "$worker_active"
      echo "SENDER_BLOCK=active"
      if [[ "$worker_load" != "loaded" || "$worker_active" != "inactive" ]]; then
        degrade
      else
        attention
      fi
    else
      printf 'UNIT=%s LOAD=%s ACTIVE=%s EXPECTED=active-no-sender-block\n'         "$worker_unit" "$worker_load" "$worker_active"
      if [[ "$worker_load" != "loaded" || "$worker_active" != "active" ]]; then
        degrade
      fi
    fi

    report_expected_absent shopvivaliz-24x7.service
    report_expected_absent agent-bridge.service
    report_expected_absent shopvivaliz-agent-bridge.service
    report_expected_absent shopvivaliz-mcp.service
    report_expected_absent mei-mg-email.service
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

if [[ "$health" == "degraded" ]]; then
  exit 1
fi
