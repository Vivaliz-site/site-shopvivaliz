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
check_fixed 'FULL_TTL_HOURS="${FULL_TTL_HOURS:-24}"' "$HOUSE" 'housekeeper honors full TTL override'
check_fixed 'CACHE_TTL_HOURS="${CACHE_TTL_HOURS:-12}"' "$HOUSE" 'housekeeper honors cache TTL override'
check_fixed '/tmp/control-plane-*' "$HOUSE" 'control-plane temp artifacts are covered'
check_fixed '/tmp/guardian-node.*' "$HOUSE" 'guardian-node temp artifacts are covered'
check_fixed '/tmp/shopvivaliz-guardian-*' "$HOUSE" 'guardian temp artifacts are covered'
check_fixed '/tmp/solange-nfse-*' "$HOUSE" 'solange nfse temp artifacts are covered'
check_fixed '/tmp/shopvivaliz-db-backup-*' "$HOUSE" 'database backup temp artifacts are covered'
check_fixed 'shopvivaliz-workspace-housekeeper' "$GUARD" 'disk guard invokes workspace housekeeper'
check_fixed 'systemctl enable --now shopvivaliz-disk-guard.timer shopvivaliz-workspace-housekeeper.timer' "$INSTALLER" 'installer enables both timers'
check_fixed 'RAPID_GROWTH_BPH=' "$GUARD" 'disk guard defines a rapid-growth threshold'
check_fixed 'growth_bytes_per_hour=' "$GUARD" 'disk guard persists growth-rate telemetry'
check_fixed 'rapid_growth=1' "$GUARD" 'disk guard detects rapid growth'
check_fixed '[ "$rapid_growth" -eq 1 ]' "$GUARD" 'rapid growth triggers guarded cleanup before percentage threshold'
check_fixed 'WORKTREE_PRESSURE_COUNT=' "$GUARD" 'disk guard defines a worktree pressure threshold'
check_fixed 'worktree_count()' "$GUARD" 'disk guard counts worktree directories'
check_fixed 'FULL_TTL_HOURS=1 CACHE_TTL_HOURS=1' "$GUARD" 'pressure cleanup uses one-hour delivered-worktree TTL'
if [ "$(grep -c '^EOF2$' "$GUARD")" -eq 1 ]; then
  printf 'PASS: disk guard state heredoc is well formed
'
else
  printf 'FAIL: disk guard state heredoc is well formed
'
  fail=1
fi

exit "$fail"
