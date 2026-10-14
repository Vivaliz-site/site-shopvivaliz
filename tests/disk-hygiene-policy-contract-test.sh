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
check_fixed 'if [ "$heavy_cleanup_ran" -eq 1 ] && { [ "$before" -ge 75 ] || [ "$rapid_growth" -eq 1 ] || [ "$low_free" -eq 1 ]; }; then' "$GUARD" 'Docker pruning is inside the heavy-clean cycle'
check_fixed 'HEAVY_CLEAN_COOLDOWN_SECONDS="${HEAVY_CLEAN_COOLDOWN_SECONDS:-21600}"' "$GUARD" 'cooldown defaults to six hours'
check_fixed 'HOUSEKEEPER_TIMEOUT_SECONDS="${HOUSEKEEPER_TIMEOUT_SECONDS:-60}"' "$GUARD" 'housekeeper has a 60-second timeout'
check_fixed 'timeout -k 5s "${HOUSEKEEPER_TIMEOUT_SECONDS}s"' "$GUARD" 'housekeeper execution is bounded'
check_fixed 'timeout -k 5s 25s docker image prune -af' "$GUARD" 'Docker image cleanup is bounded'
check_fixed 'timeout -k 5s 25s docker builder prune -af' "$GUARD" 'Docker build cleanup is bounded'
UNIT="$ROOT/deploy/systemd/shopvivaliz-disk-guard.service"
check_fixed 'TimeoutStartSec=180s' "$UNIT" 'systemd caps disk guard to three minutes'
check_fixed 'IOWeight=20' "$UNIT" 'service I/O weight limits cleanup pressure'
check_fixed 'CPUWeight=20' "$UNIT" 'service CPU weight limits cleanup pressure'
check_fixed 'ANON_RECLAIM_COOLDOWN_SECONDS=3600' "$GUARD" 'lightweight anonymous-volume cleanup has independent one-hour cooldown'
check_fixed 'shopvivaliz-safe-anonymous-volume-reclaim.py --apply --min-age-hours 24' "$GUARD" 'disk guard activates bounded conservative anonymous-volume cleanup'
check_fixed 'anonymous_volume_reclaim=$anon_reclaim_status' "$GUARD" 'reclaim outcome is persisted in disk status'
check_fixed 'shopvivaliz-safe-anonymous-volume-reclaim.py' "$INSTALLER" 'canonical installer deploys guarded anonymous-volume cleaner'
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


# HEAVY_CLEANUP_BOOTSTRAP_V2: behavioral regression. The old code used the
# preceding five-minute sample on EVERY cycle when the marker was missing,
# and therefore could never reach the six-hour threshold.
source <(sed -n '/^bootstrap_heavy_cleanup_epoch() {/,/^}/p' "$GUARD")
if ! declare -F bootstrap_heavy_cleanup_epoch >/dev/null; then
  printf 'FAIL: cooldown bootstrap function missing\n'
  fail=1
else
  cooldown_dir="$(mktemp -d)"
  trap 'rm -rf "$cooldown_dir"' EXIT
  marker="$cooldown_dir/heavy.epoch"
  first="$(bootstrap_heavy_cleanup_epoch "$marker" 1000 1300)"
  second="$(bootstrap_heavy_cleanup_epoch "$marker" 1599 1600)"
  third="$(bootstrap_heavy_cleanup_epoch "$marker" 22599 22600)"
  if [[ "$first" == 1000 && "$second" == 1000 && "$third" == 1000 && "$(cat "$marker")" == 1000 ]]; then
    printf 'PASS: legacy baseline persisted once across repeated guard ticks\n'
  else
    printf 'FAIL: moving baseline would postpone heavy cleanup forever\n'
    fail=1
  fi
  if (( 22600 - third >= 21600 )); then
    printf 'PASS: six-hour heavy cleanup eventually becomes eligible\n'
  else
    printf 'FAIL: six-hour cleanup never eligible\n'
    fail=1
  fi
  empty="$cooldown_dir/fresh.epoch"
  no_previous="$(bootstrap_heavy_cleanup_epoch "$empty" "" 3000)"
  if [[ "$no_previous" == 3000 && "$(cat "$empty")" == 3000 ]]; then
    printf 'PASS: no previous marker conservatively starts the six-hour cooldown\n'
  else
    printf 'FAIL: fresh install could start an unbounded immediate cleanup\n'
    fail=1
  fi
  invalid="$cooldown_dir/corrupt.epoch"
  printf '%s\n' 'not-a-timestamp' > "$invalid"
  if [[ "$(bootstrap_heavy_cleanup_epoch "$invalid" 3000 3500)" == 3500 ]]; then
    printf 'PASS: corrupt marker fails closed\n'
  else
    printf 'FAIL: corrupt marker allowed heavy cleanup\n'
    fail=1
  fi
  future="$cooldown_dir/future.epoch"
  printf '%s\n' 999999 > "$future"
  if [[ "$(bootstrap_heavy_cleanup_epoch "$future" 3000 3500)" == 3500 ]]; then
    printf 'PASS: future marker fails closed\n'
  else
    printf 'FAIL: future marker allowed heavy cleanup\n'
    fail=1
  fi
fi

exit "$fail"
