#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKFLOW="$ROOT/.github/workflows/backend-disk-hygiene-reconcile.yml"

fail=0
check_fixed() {
  local pattern="$1" label="$2"
  if [ -f "$WORKFLOW" ] && grep -Fq -- "$pattern" "$WORKFLOW"; then
    printf 'PASS: %s\n' "$label"
  else
    printf 'FAIL: %s\n' "$label"
    fail=1
  fi
}

check_fixed 'schedule:' 'disk hygiene reconciliation has an independent schedule'
check_fixed "cron: '*/30 * * * *'" 'disk hygiene reconciliation runs at least every thirty minutes'
check_fixed 'shopvivaliz-a1-deploy' 'reconciliation runs outside the backend it protects'
check_fixed "'ops/host/shopvivaliz-disk-guard'" 'guard source changes trigger reconciliation'
check_fixed "'ops/host/shopvivaliz-workspace-housekeeper'" 'housekeeper source changes trigger reconciliation'
check_fixed "'scripts/install-disk-hygiene.sh'" 'installer changes trigger reconciliation'
check_fixed "'deploy/systemd/shopvivaliz-disk-guard.timer'" 'timer changes trigger reconciliation'
check_fixed 'sha256sum' 'reconciliation compares canonical and runtime hashes'
check_fixed 'install-disk-hygiene.sh' 'reconciliation invokes the canonical installer'
check_fixed 'systemctl is-enabled --quiet shopvivaliz-disk-guard.timer' 'reconciliation verifies the guard timer is enabled'
check_fixed 'systemctl is-active --quiet shopvivaliz-disk-guard.timer' 'reconciliation verifies the guard timer is active'
check_fixed 'systemctl start shopvivaliz-disk-guard.service' 'reconciliation runs the guard after reconciling'
check_fixed 'MIN_FREE_BYTES=6442450944' 'reconciliation enforces the 6 GiB free-space floor during verification'
check_fixed 'DISK_HYGIENE_RECONCILE=PASS' 'reconciliation emits an explicit terminal proof'

exit "$fail"
