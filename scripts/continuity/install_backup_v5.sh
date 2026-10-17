#!/usr/bin/env bash
# Install an independently verified checkpoint backup. Never touch browsers.
set -Eeuo pipefail
if [[ "$(id -u)" != "0" ]]; then
  echo "ERROR=continuity_backup_requires_root" >&2
  exit 77
fi

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
source_script="$repo_root/scripts/continuity/backup_state_v5.py"
source_service="$repo_root/ops/systemd/shopvivaliz-continuity-state-backup.service"
source_timer="$repo_root/ops/systemd/shopvivaliz-continuity-state-backup.timer"
state_dir=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state
backup_dir=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state-backups

for item in "$source_script" "$source_service" "$source_timer"; do
  test -s "$item" || { echo "ERROR=missing_backup_prerequisite" >&2; exit 74; }
done
test -f "$state_dir/_agent-task-state.lock" && test ! -L "$state_dir/_agent-task-state.lock" || { echo "ERROR=checkpoint_lock_missing" >&2; exit 74; }
test -d "$backup_dir" && test ! -L "$backup_dir" || { echo "ERROR=private_backup_directory_missing" >&2; exit 74; }
test "$(stat -c '%a' "$backup_dir")" = 700 || { echo "ERROR=backup_directory_not_private" >&2; exit 74; }
python3 -c 'import ast,sys;ast.parse(open(sys.argv[1],encoding="utf-8").read())' "$source_script"

install -m 0750 -o root -g root "$source_script" /usr/local/libexec/shopvivaliz-continuity-state-backup.py
install -m 0644 -o root -g root "$source_service" /etc/systemd/system/shopvivaliz-continuity-state-backup.service
install -m 0644 -o root -g root "$source_timer" /etc/systemd/system/shopvivaliz-continuity-state-backup.timer

systemd-analyze verify /etc/systemd/system/shopvivaliz-continuity-state-backup.service /etc/systemd/system/shopvivaliz-continuity-state-backup.timer >/dev/null
systemctl daemon-reload
systemctl start shopvivaliz-continuity-state-backup.service

latest="$(find "$backup_dir" -maxdepth 1 -type f -name 'continuity-v5-*.tar.gz.sha256' -printf '%T@ %p\n' | sort -nr | head -n 1 | cut -d' ' -f2-)"
test -n "$latest" && test -s "$latest" || { echo "ERROR=missing_verified_backup" >&2; exit 75; }
(cd "$backup_dir" && sha256sum -c -- "$(basename "$latest")" >/dev/null)
systemctl enable --now shopvivaliz-continuity-state-backup.timer
systemctl is-active --quiet shopvivaliz-continuity-state-backup.timer
echo "CONTINUITY_BACKUP_V5=VERIFIED"
