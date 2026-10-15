#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOUSE="$ROOT/ops/host/shopvivaliz-workspace-housekeeper"
bash -n "$HOUSE"
grep -Fq 'deploy_worktree_reclaim()' "$HOUSE"
grep -Fq 'git -c safe.directory="$repo" -C "$repo" worktree remove -- "$d"' "$HOUSE"
grep -Fq 'ls-files -o -i --exclude-standard' "$HOUSE"
grep -Fq 'status --porcelain -uall' "$HOUSE"
grep -Fq 'grep -Fql -- "$d"' "$HOUSE"
grep -Fq 'path_active "$d"' "$HOUSE"
grep -Fq 'PRESSURE_RECLAIM_PUSHED' "$HOUSE"
grep -Fq 'tried" -lt 5' "$HOUSE"
grep -Fq 'success" -lt 2' "$HOUSE"

# Exercise the exact production function against safe, tiny Git worktrees.
# Only fixture paths and the sudo wrapper are substituted.
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
git init -q --bare "$tmp/origin.git"
git clone -q "$tmp/origin.git" "$tmp/repo"
git -C "$tmp/repo" config user.email test@example.invalid
git -C "$tmp/repo" config user.name ShopVivaliz-Test
printf 'ignored-folder/\n' > "$tmp/repo/.gitignore"
printf 'tracked\n' > "$tmp/repo/source.txt"
git -C "$tmp/repo" add .gitignore source.txt
git -C "$tmp/repo" commit -qm initial
git -C "$tmp/repo" branch -M main
git -C "$tmp/repo" push -q -u origin main
git -C "$tmp/repo" fetch -q origin
mkdir -p "$tmp/worktrees" "$tmp/state" "$tmp/checkpoints"
for name in a_clean b_dirty c_ignored d_unpushed e_checkpoint f_active g_clean; do
  git -C "$tmp/repo" worktree add -q -b "test-$name" "$tmp/worktrees/$name" origin/main
done
printf 'untracked\n' >"$tmp/worktrees/b_dirty/unsaved.txt"
mkdir -p "$tmp/worktrees/c_ignored/ignored-folder"
printf 'protected\n' >"$tmp/worktrees/c_ignored/ignored-folder/data.txt"
git -C "$tmp/worktrees/d_unpushed" config user.email test@example.invalid
git -C "$tmp/worktrees/d_unpushed" config user.name ShopVivaliz-Test
printf 'unique\n' >> "$tmp/worktrees/d_unpushed/source.txt"
git -C "$tmp/worktrees/d_unpushed" commit -qam unpublished
printf '{"path":"%s"}\n' "$tmp/worktrees/e_checkpoint" > "$tmp/checkpoints/checkpoint.json"
for d in "$tmp/worktrees"/*; do touch -d '4 days ago' "$d"; done

# Extract function only. The real job's housekeeping code must never run in CI.
ROOT="$ROOT" TEST_BASE="$tmp/worktrees" TEST_REPO="$tmp/repo" TEST_CHECKPOINTS="$tmp/checkpoints" TEST_OUTPUT="$tmp/func.sh" python3 - <<'PY'
import os,pathlib
s=(pathlib.Path(os.environ['ROOT'])/'ops/host/shopvivaliz-workspace-housekeeper').read_text()
start=s.index('deploy_worktree_reclaim() {')
end=s.index('\n}\n\ndeploy_worktree_reclaim',start)+2
s=s[start:end]
for a,b in [
 ('local base=/home/ubuntu/shopvivaliz-deploy/worktrees','local base='+os.environ['TEST_BASE']),
 ('local repo=/home/ubuntu/shopvivaliz-deploy/repo','local repo='+os.environ['TEST_REPO']),
 ('local checkpoint_root=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state','local checkpoint_root='+os.environ['TEST_CHECKPOINTS']),
 ('sudo -u ubuntu ','')
]:
 assert a in s,a
 s=s.replace(a,b)
pathlib.Path(os.environ['TEST_OUTPUT']).write_text(s+'\n')
PY
bash -n "$tmp/func.sh"
STATE_DIR="$tmp/state"
now_epoch="$(date +%s)"
root_pct_pre=92
PRESSURE_RECLAIM_PUSHED=1
DRY_RUN=1
worktree_protected() { return 1; }
path_active() { [[ "$1" == */f_active ]]; }
log() { printf '%s\n' "$*" >> "$STATE_DIR/log"; }
source "$tmp/func.sh"
deploy_worktree_reclaim
test -d "$tmp/worktrees/a_clean"
test -d "$tmp/worktrees/b_dirty"
test -d "$tmp/worktrees/c_ignored"
test -d "$tmp/worktrees/d_unpushed"
test -d "$tmp/worktrees/e_checkpoint"
DRY_RUN=0
printf '0\n' > "$STATE_DIR/deploy-worktree-cursor"
deploy_worktree_reclaim
test ! -e "$tmp/worktrees/a_clean"
test -e "$tmp/worktrees/b_dirty"
test -e "$tmp/worktrees/c_ignored"
test -e "$tmp/worktrees/d_unpushed"
test -e "$tmp/worktrees/e_checkpoint"
test -e "$tmp/worktrees/f_active"
# Explicitly ensure a clean later worktree is reachable despite prior preserves.
for _ in 1 2 3 4; do
  deploy_worktree_reclaim
  if [ ! -d "$tmp/worktrees/g_clean" ]; then break; fi
done
test ! -e "$tmp/worktrees/g_clean"
test -d "$tmp/worktrees/f_active"
test -f "$tmp/worktrees/e_checkpoint/source.txt"
echo 'DEPLOY_WORKTREE_HOUSEKEEPER_CONTRACT=PASS'
