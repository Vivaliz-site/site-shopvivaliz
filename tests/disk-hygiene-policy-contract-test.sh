#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOUSE="$ROOT/ops/host/shopvivaliz-workspace-housekeeper"
GUARD="$ROOT/ops/host/shopvivaliz-disk-guard"
INSTALLER="$ROOT/scripts/install-disk-hygiene.sh"

fail=0
check_fixed() {
  local pattern="$1" file="$2" label="$3"
  if grep -Fq -- "$pattern" "$file"; then
    printf 'PASS: %s\n' "$label"
  else
    printf 'FAIL: %s\n' "$label"
    fail=1
  fi
}

bash -n "$HOUSE"
sh -n "$GUARD"
bash -n "$INSTALLER"

check_fixed '-ge 70' "$HOUSE" 'housekeeper has 70% pressure threshold'
check_fixed '/tmp/control-plane-*' "$HOUSE" 'control-plane temp artifacts are covered'
check_fixed '/tmp/guardian-node.*' "$HOUSE" 'guardian-node temp artifacts are covered'
check_fixed '/tmp/shopvivaliz-guardian-*' "$HOUSE" 'guardian temp artifacts are covered'
check_fixed '/tmp/solange-nfse-*' "$HOUSE" 'solange nfse temp artifacts are covered'
check_fixed 'shopvivaliz-workspace-housekeeper' "$GUARD" 'disk guard invokes workspace housekeeper'
check_fixed 'systemctl enable --now shopvivaliz-disk-guard.timer shopvivaliz-workspace-housekeeper.timer' "$INSTALLER" 'installer enables both timers'

exit "$fail"
