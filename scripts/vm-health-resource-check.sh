#!/usr/bin/env bash
set -Eeuo pipefail

role="${1:-}"
mode="${2:-check}"

case "$role" in
  backend|site) ;;
  *) echo "ERROR=unsupported-role"; exit 2 ;;
esac
case "$mode" in
  check|repair) ;;
  *) echo "ERROR=unsupported-mode"; exit 2 ;;
esac

health="ok"
attention_reasons=()
degraded_reasons=()

attention() {
  local reason="$1"
  attention_reasons+=("$reason")
  if [ "$health" = "ok" ]; then health="attention"; fi
}

degrade() {
  local reason="$1"
  degraded_reasons+=("$reason")
  health="degraded"
}

pct() {
  local num="$1" den="$2"
  if [ "$den" -le 0 ]; then printf '0'; return; fi
  awk -v n="$num" -v d="$den" 'BEGIN { printf "%.0f", (n*100)/d }'
}

restart_system_unit_if_inactive() {
  local unit="$1"
  if ! systemctl cat "$unit" >/dev/null 2>&1; then
    echo "REPAIR_SKIP_UNIT_NOT_FOUND=$unit"
    return 0
  fi
  if systemctl is-active --quiet "$unit"; then
    echo "REPAIR_SKIP_ALREADY_ACTIVE=$unit"
    return 0
  fi
  echo "REPAIR_RESTART_SYSTEM_UNIT=$unit"
  systemctl restart "$unit"
  systemctl is-active --quiet "$unit"
}

restart_user_unit_if_inactive() {
  local user="$1" unit="$2"
  local uid runtime
  uid="$(id -u "$user")"
  runtime="/run/user/$uid"
  if sudo -u "$user" env XDG_RUNTIME_DIR="$runtime" DBUS_SESSION_BUS_ADDRESS="unix:path=$runtime/bus" systemctl --user is-active --quiet "$unit"; then
    echo "REPAIR_SKIP_ALREADY_ACTIVE_USER_UNIT=$unit"
    return 0
  fi
  echo "REPAIR_RESTART_USER_UNIT=$unit"
  sudo -u "$user" env XDG_RUNTIME_DIR="$runtime" DBUS_SESSION_BUS_ADDRESS="unix:path=$runtime/bus" systemctl --user restart "$unit"
  sudo -u "$user" env XDG_RUNTIME_DIR="$runtime" DBUS_SESSION_BUS_ADDRESS="unix:path=$runtime/bus" systemctl --user is-active --quiet "$unit"
}

if [ "$mode" = "repair" ]; then
  echo "VM_HEALTH_SAFE_REPAIR_BEGIN"
  if [ "$role" = "backend" ]; then
    restart_system_unit_if_inactive shopvivaliz-remote-control-mcp.service
    restart_user_unit_if_inactive ubuntu shopvivaliz-chatgpt-continuity.service
  else
    for unit in       apache2.service       shopvivaliz-queue-worker.service       shopvivaliz-token-renewer.service       shopvivaliz-shopee-token-renewer.service       shopvivaliz-agent.service       shopvivaliz-catalog-reconcile.timer       shopvivaliz-sync-safe.timer       shopvivaliz-abandoned-cart-recovery.timer
    do
      restart_system_unit_if_inactive "$unit"
    done
  fi
  echo "VM_HEALTH_SAFE_REPAIR_END"
  exit 0
fi

hostname_value="$(hostname)"
cpu_count="$(nproc)"
load5="$(awk '{print $2}' /proc/loadavg)"
load5_per_cpu="$(awk -v l="$load5" -v c="$cpu_count" 'BEGIN { if (c < 1) c=1; printf "%.2f", l/c }')"

disk_use="$(df -P / | awk 'NR==2 {gsub(/%/,"",$5); print $5}')"
inode_use="$(df -Pi / | awk 'NR==2 {gsub(/%/,"",$5); print $5}')"

mem_total_kb="$(awk '/^MemTotal:/ {print $2}' /proc/meminfo)"
mem_available_kb="$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)"
mem_available_pct="$(pct "$mem_available_kb" "$mem_total_kb")"

swap_total_kb="$(awk '/^SwapTotal:/ {print $2}' /proc/meminfo)"
swap_free_kb="$(awk '/^SwapFree:/ {print $2}' /proc/meminfo)"
if [ "$swap_total_kb" -gt 0 ]; then
  swap_used_kb="$((swap_total_kb - swap_free_kb))"
  swap_use_pct="$(pct "$swap_used_kb" "$swap_total_kb")"
else
  swap_use_pct=0
fi

if [ "$disk_use" -ge 95 ]; then degrade "disk_critical"; elif [ "$disk_use" -ge 85 ]; then attention "disk_warning"; fi
if [ "$inode_use" -ge 95 ]; then degrade "inode_critical"; elif [ "$inode_use" -ge 85 ]; then attention "inode_warning"; fi
if [ "$mem_available_pct" -le 5 ]; then degrade "memory_available_critical"; elif [ "$mem_available_pct" -le 15 ]; then attention "memory_available_low"; fi
if [ "$swap_use_pct" -ge 95 ] && [ "$mem_available_pct" -le 15 ]; then
  degrade "swap_pressure_critical"
elif [ "$swap_use_pct" -ge 90 ]; then
  attention "swap_pressure_high"
fi
if awk -v v="$load5_per_cpu" 'BEGIN { exit !(v >= 2.0) }'; then
  degrade "load5_per_cpu_critical"
elif awk -v v="$load5_per_cpu" 'BEGIN { exit !(v >= 1.0) }'; then
  attention "load5_per_cpu_high"
fi

failed_units=()
while IFS= read -r unit; do
  [ -n "$unit" ] || continue
  case "$unit" in
    safet-audit-*.service) continue ;;
  esac
  failed_units+=("$unit")
done < <(systemctl --failed --no-legend --plain 2>/dev/null | awk '{print $1}')

if [ "${#failed_units[@]}" -gt 0 ]; then
  degrade "failed_systemd_units"
fi

echo "VM_HEALTH_MONITOR_BEGIN"
echo "HOST=$hostname_value"
echo "ROLE=$role"
echo "DISK_USE_PCT=$disk_use"
echo "INODE_USE_PCT=$inode_use"
echo "MEM_AVAILABLE_PCT=$mem_available_pct"
echo "SWAP_USE_PCT=$swap_use_pct"
echo "CPU_COUNT=$cpu_count"
echo "LOAD5=$load5"
echo "LOAD5_PER_CPU=$load5_per_cpu"
echo "FAILED_UNIT_COUNT=${#failed_units[@]}"
if [ "${#failed_units[@]}" -gt 0 ]; then
  printf 'FAILED_UNITS=%s\n' "$(IFS=,; echo "${failed_units[*]}")"
else
  echo "FAILED_UNITS=NONE"
fi
if [ "${#attention_reasons[@]}" -gt 0 ]; then
  printf 'ATTENTION_REASONS=%s\n' "$(IFS=,; echo "${attention_reasons[*]}")"
else
  echo "ATTENTION_REASONS=NONE"
fi
if [ "${#degraded_reasons[@]}" -gt 0 ]; then
  printf 'DEGRADED_REASONS=%s\n' "$(IFS=,; echo "${degraded_reasons[*]}")"
else
  echo "DEGRADED_REASONS=NONE"
fi
echo "VM_HEALTH=$health"
echo "VM_HEALTH_MONITOR_END"

if [ "$health" = "degraded" ]; then exit 1; fi
exit 0
