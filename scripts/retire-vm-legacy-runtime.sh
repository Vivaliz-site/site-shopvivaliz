#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ${EUID} -ne 0 ]]; then
  echo 'ERROR root privileges required' >&2
  exit 2
fi

RETIRED_UNITS=(
  'desktop-commander.service'
  'shopvivaliz-mcp.service'
  'shopvivaliz-monitor.service'
  'shopvivaliz-sync.service'
  'shopvivaliz-24x7.service'
  'shopvivaliz-24x7.timer'
  'shopvivaliz-auto-sync.service'
  'shopvivaliz-auto-sync.timer'
  'shopvivaliz-git-sync.service'
  'shopvivaliz-git-sync.timer'
)

PRESERVED_UNITS=(
  'shopvivaliz-desktop-commander.service'
  'shopvivaliz-agent.service'
  'shopvivaliz-queue-worker.service'
  'shopvivaliz-token-renewer.service'
  'shopvivaliz-shopee-token-renewer.service'
  'shopvivaliz-sync-safe.service'
  'shopvivaliz-agent-bridge.service'
  'shopvivaliz-catalog-audit.service'
  'shopvivaliz-orchestrator.service'
  'shopvivaliz-products-active-sync.service'
)

for unit in "${RETIRED_UNITS[@]}"; do
  systemctl stop "$unit" >/dev/null 2>&1 || :
  systemctl disable "$unit" >/dev/null 2>&1 || :
  find /etc/systemd/system -type l -name "$unit" -delete 2>/dev/null || :
  rm -f "/etc/systemd/system/$unit"
  rm -rf "/etc/systemd/system/${unit}.d"
done

rm -f \
  /etc/systemd/system/shopvivaliz-shopee-token-renewer.service.pre-hardening-20260803 \
  /etc/systemd/system/shopvivaliz-shopee-token-renewer.service.repair-20260808T160822Z.bak

systemctl daemon-reload
systemctl reset-failed >/dev/null 2>&1 || :

for unit in "${RETIRED_UNITS[@]}"; do
  if systemctl cat "$unit" >/dev/null 2>&1; then
    echo "ERROR retired unit still present: $unit" >&2
    exit 10
  fi
  echo "RETIRED_UNIT_ABSENT=$unit"
done

for unit in "${PRESERVED_UNITS[@]}"; do
  PRESERVE_UNIT="$unit"
  if ! systemctl cat "$PRESERVE_UNIT" >/dev/null 2>&1; then
    echo "ERROR preserved unit missing: $PRESERVE_UNIT" >&2
    exit 11
  fi
done

[[ "$(systemctl is-enabled shopvivaliz-desktop-commander.service 2>/dev/null)" == 'enabled' ]]
[[ "$(systemctl is-active shopvivaliz-desktop-commander.service 2>/dev/null)" == 'active' ]]

echo 'VM_LEGACY_RUNTIME_RETIREMENT=ok'
