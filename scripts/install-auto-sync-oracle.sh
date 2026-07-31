#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-/home/ubuntu/shopvivaliz-deploy/repo}"
SHARED_ROOT="${SHARED_ROOT:-/home/ubuntu/shopvivaliz-deploy/shared}"
CURRENT_ROOT="${CURRENT_ROOT:-/home/ubuntu/shopvivaliz-deploy/current}"
SCRIPT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOCK_FILE="/var/lock/shopvivaliz-deploy.lock"
LOG_DIR="$SHARED_ROOT/logs"
CRON_FILE="$(mktemp)"

cleanup() {
  rm -f -- "$CRON_FILE"
}
trap cleanup EXIT

if [ -x "$CURRENT_ROOT/scripts/auto-sync-oracle.sh" ]; then
  SYNC_SCRIPT="$CURRENT_ROOT/scripts/auto-sync-oracle.sh"
else
  SYNC_SCRIPT="$SCRIPT_ROOT/scripts/auto-sync-oracle.sh"
fi

if [ ! -f "$SYNC_SCRIPT" ]; then
  echo "Script de sync ausente: $SYNC_SCRIPT" >&2
  exit 2
fi

mkdir -p "$LOG_DIR"
chmod 0755 \
  "$SCRIPT_ROOT/git-auto-sync.py" \
  "$SCRIPT_ROOT/scripts/safe-repo-sync.sh" \
  "$SCRIPT_ROOT/scripts/auto-sync-oracle.sh"

if ! crontab -l > "$CRON_FILE" 2>/dev/null; then
  : > "$CRON_FILE"
fi

python3 - "$CRON_FILE" "$ROOT" "$SHARED_ROOT" "$LOCK_FILE" "$LOG_DIR" "$SYNC_SCRIPT" <<'PY'
from __future__ import annotations

import sys
from pathlib import Path

cron_path = Path(sys.argv[1])
root = sys.argv[2]
shared_root = sys.argv[3]
lock_file = sys.argv[4]
log_dir = sys.argv[5]
sync_script = sys.argv[6]

kept = [
    line
    for line in cron_path.read_text(encoding="utf-8", errors="replace").splitlines()
    if "auto-sync-oracle.sh" not in line and "safe-repo-sync.sh" not in line
]
entry = (
    "*/5 * * * * "
    f"ROOT={root} SHARED_ROOT={shared_root} "
    f"/usr/bin/flock -n {lock_file} {sync_script} "
    f">> {log_dir}/safe-repo-sync-cron.log 2>&1"
)
kept.append(entry)
cron_path.write_text("\n".join(kept) + "\n", encoding="utf-8")
PY

crontab "$CRON_FILE"
echo "Auto sync Oracle instalado a cada 5 minutos usando a release ativa."
