#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOUSE="$ROOT/ops/host/shopvivaliz-workspace-housekeeper"
GUARD="$ROOT/ops/host/shopvivaliz-disk-guard"
INSTALLER="$ROOT/scripts/install-disk-hygiene.sh"
TIMER="$ROOT/deploy/systemd/shopvivaliz-disk-guard.timer"

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
check_absent() {
  local pattern="$1" file="$2" label="$3"
  if grep -Fq -- "$pattern" "$file"; then
    printf 'FAIL: %s\n' "$label"
    fail=1
  else
    printf 'PASS: %s\n' "$label"
  fi
}

bash -n "$HOUSE"
sh -n "$GUARD"
bash -n "$INSTALLER"
test -f "$TIMER"

check_fixed '-ge 70' "$HOUSE" 'housekeeper has 70% pressure threshold'
check_fixed 'FULL_TTL_HOURS="${FULL_TTL_HOURS:-24}"' "$HOUSE" 'housekeeper honors full TTL override'
check_fixed 'CACHE_TTL_HOURS="${CACHE_TTL_HOURS:-12}"' "$HOUSE" 'housekeeper honors cache TTL override'
check_fixed '/tmp/control-plane-*' "$HOUSE" 'control-plane temp artifacts are covered'
check_fixed '/tmp/guardian-node.*' "$HOUSE" 'guardian-node temp artifacts are covered'
check_fixed '/tmp/shopvivaliz-guardian-*' "$HOUSE" 'guardian temp artifacts are covered'
check_fixed '/tmp/solange-nfse-*' "$HOUSE" 'solange nfse temp artifacts are covered'
check_fixed '/tmp/shopvivaliz-db-backup-*' "$HOUSE" 'database backup temp artifacts are covered'
check_fixed 'ORPHAN_WORKTREE_TTL_HOURS="${ORPHAN_WORKTREE_TTL_HOURS:-2}"' "$HOUSE" 'housekeeper defines orphan worktree TTL'
check_fixed '/home/ubuntu/worktrees/browser-type-fix-*' "$HOUSE" 'orphan browser-type worktrees are covered'
check_fixed '/home/ubuntu/worktrees/guardian-drift-*' "$HOUSE" 'orphan guardian drift worktrees are covered'
check_fixed '/home/ubuntu/worktrees/guardian-runtime-*' "$HOUSE" 'orphan guardian runtime worktrees are covered'
check_fixed '/home/ubuntu/worktrees/continuity-report-*' "$HOUSE" 'orphan continuity report worktrees are covered'
check_fixed 'ORPHAN_WORKTREE_TTL_HOURS=0 FULL_TTL_HOURS=0 CACHE_TTL_HOURS=1' "$GUARD" 'pressure cleanup immediately reclaims known orphan worktree copies'
check_fixed 'shopvivaliz-workspace-housekeeper' "$GUARD" 'disk guard invokes workspace housekeeper'
check_fixed 'systemctl enable --now shopvivaliz-disk-guard.timer shopvivaliz-workspace-housekeeper.timer' "$INSTALLER" 'installer enables both timers'
check_fixed 'RAPID_GROWTH_BPH=' "$GUARD" 'disk guard defines a rapid-growth threshold'
check_fixed 'growth_bytes_per_hour=' "$GUARD" 'disk guard persists growth-rate telemetry'
check_fixed 'rapid_growth=1' "$GUARD" 'disk guard detects rapid growth'
check_fixed '[ "$rapid_growth" -eq 1 ]' "$GUARD" 'rapid growth triggers guarded cleanup before percentage threshold'
check_fixed 'WORKTREE_PRESSURE_COUNT=' "$GUARD" 'disk guard defines a worktree pressure threshold'
check_fixed 'worktree_count()' "$GUARD" 'disk guard counts worktree directories'
check_fixed 'FULL_TTL_HOURS=0 CACHE_TTL_HOURS=1' "$GUARD" 'pressure cleanup removes clean delivered inactive worktrees immediately'
check_fixed 'cleanup_unused_docker_images()' "$GUARD" 'disk guard defines safe unused-image cleanup'
check_fixed 'docker image prune -af >/dev/null 2>&1 || true' "$GUARD" 'unused Docker images are reclaimable without a seven-day delay'
check_fixed 'if [ "$before" -ge 75 ] || [ "$rapid_growth" -eq 1 ]; then' "$GUARD" 'disk guard reclaims unused Docker images at observe pressure or rapid growth'
check_absent 'docker volume prune' "$GUARD" 'disk guard never prunes Docker volumes'
check_absent 'docker system prune' "$GUARD" 'disk guard never performs broad Docker system prune'
if [ "$(grep -c '^EOF2$' "$GUARD")" -eq 1 ]; then
  printf 'PASS: disk guard state heredoc is well formed
'
else
  printf 'FAIL: disk guard state heredoc is well formed
'
  fail=1
fi

check_fixed 'MIN_FREE_BYTES="${MIN_FREE_BYTES:-6442450944}"' "$GUARD" 'disk guard reserves an 6 GiB free-space floor'
check_fixed 'available_bytes()' "$GUARD" 'disk guard measures absolute free bytes'
check_fixed 'low_free=1' "$GUARD" 'disk guard records absolute free-space pressure'
check_fixed '[ "$low_free" -eq 1 ]' "$GUARD" 'absolute free-space pressure triggers cleanup'
check_fixed 'PRESSURE_RECLAIM_PUSHED=1' "$GUARD" 'pressure cleanup may reclaim clean pushed inactive worktrees'
check_fixed 'ABANDONED_CLONE_TTL_HOURS=24' "$GUARD" 'pressure cleanup shortens safe abandoned-clone TTL'
check_fixed 'PRESSURE_RECLAIM_PUSHED="${PRESSURE_RECLAIM_PUSHED:-0}"' "$HOUSE" 'housekeeper defaults pushed-worktree reclamation off'
check_fixed 'pushed_worktree()' "$HOUSE" 'housekeeper proves HEAD exists on a remote branch before pressure reclamation'
check_fixed 'pushed_worktree_removed' "$HOUSE" 'pushed clean worktree reclamation is auditable'
check_fixed 'OnUnitActiveSec=5min' "$TIMER" 'disk guard runs every five minutes'
check_fixed 'RandomizedDelaySec=15' "$TIMER" 'disk guard jitter is bounded to fifteen seconds'

check_fixed 'ABANDONED_CLONE_TTL_HOURS="${ABANDONED_CLONE_TTL_HOURS:-168}"' "$HOUSE" 'abandoned clones default to seven-day TTL'
check_fixed 'canonical_clone()' "$HOUSE" 'canonical clones are explicitly protected'
check_fixed '/home/ubuntu/shopvivaliz-deploy/releases/*' "$HOUSE" 'immutable deploy releases are protected from generic abandoned-clone reclamation'
check_fixed 'clone_has_unpushed_commits()' "$HOUSE" 'clones with unpushed local commits are protected'
check_fixed 'linked_worktrees' "$HOUSE" 'clones with linked worktrees are protected'
check_fixed 'abandoned_clone_removed' "$HOUSE" 'abandoned clone removals are persisted in state and logs'

exit "$fail"
