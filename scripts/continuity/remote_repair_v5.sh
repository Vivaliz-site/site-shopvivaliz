#!/usr/bin/env bash
# One-shot, fenced-by-source backup V5 repair over the canonical private runner.
# Does not mutate task checkpoints, browser profiles, auth, or controller leases.
set -Eeuo pipefail

staging_dir="${1:?staging directory required}"
expected_sha="${2:?source sha256 required}"
[[ "$expected_sha" =~ ^[a-f0-9]{64}$ ]] || { echo "BACKUP_V5_REPAIR=INVALID_SOURCE_SHA"; exit 64; }
[[ "$staging_dir" =~ ^/tmp/shopvivaliz-backup-v5-[0-9]+-[0-9]+$ ]] || { echo "BACKUP_V5_REPAIR=INVALID_STAGE"; exit 64; }
test "$(hostname)" = "always-free-arm-1787907847-26"
test "$(id -un)" = "ubuntu"
test -d "$staging_dir" && test ! -L "$staging_dir"
test "$(stat -c '%a' "$staging_dir")" = 700

source_script="$staging_dir/scripts/continuity/backup_state_v5.py"
test -s "$source_script" && test ! -L "$source_script"
source_sha="$(sha256sum "$source_script" | awk '{print $1}')"
test "$source_sha" = "$expected_sha" || { echo "BACKUP_V5_REPAIR=SOURCE_MISMATCH"; exit 74; }

state_dir="/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state"
free_kib="$(df -Pk "$state_dir" | awk 'NR==2 {print $4}')"
state_kib="$(sudo -n du -sk -- "$state_dir" | awk '{print $1}')"
[[ "$free_kib" =~ ^[0-9]+$ && "$state_kib" =~ ^[0-9]+$ ]] || exit 74
printf 'BACKUP_V5_PREFLIGHT_FREE_KIB=%s STATE_KIB=%s\n' "$free_kib" "$state_kib"
if (( free_kib < state_kib * 2 + 262144 )); then
  echo "BACKUP_V5_REPAIR=BLOCKED_INSUFFICIENT_SPACE"
  exit 75
fi

# The canonical installer independently verifies all prerequisites, performs
# a real locked backup and sha256 readback, and enables the existing timer.
timeout 240 sudo -n bash "$staging_dir/scripts/continuity/install_backup_v5.sh"

installed_sha="$(sudo -n sha256sum /usr/local/libexec/shopvivaliz-continuity-state-backup.py | awk '{print $1}')"
test "$installed_sha" = "$expected_sha"
test "$(systemctl show shopvivaliz-continuity-state-backup.service -p Result --value)" = success
test "$(systemctl show shopvivaliz-continuity-state-backup.service -p ExecMainStatus --value)" = 0
systemctl is-enabled --quiet shopvivaliz-continuity-state-backup.timer
systemctl is-active --quiet shopvivaliz-continuity-state-backup.timer
echo "BACKUP_V5_INSTALLED_SOURCE_MATCH=PASS"
echo "BACKUP_V5_ONESHOT_AND_TIMER=PASS"
echo "CONTINUITY_BACKUP_V5_REMOTE_REPAIR=PASS"
