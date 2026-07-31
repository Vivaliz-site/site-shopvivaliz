#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-/home/ubuntu/shopvivaliz-deploy/repo}"
SHARED_ROOT="${SHARED_ROOT:-/home/ubuntu/shopvivaliz-deploy/shared}"
SYNC_SCRIPT="$ROOT/scripts/auto-sync-oracle.sh"
LOCK_FILE="/var/lock/shopvivaliz-deploy.lock"
LOG_DIR="$SHARED_ROOT/logs"
CRON_FILE="$(mktemp)"

cleanup() {
  rm -f -- "$CRON_FILE"
}
trap cleanup EXIT

if [ ! -f "$SYNC_SCRIPT" ]; then
  echo "Script de sync ausente: $SYNC_SCRIPT" >&2
  exit 2
fi

mkdir -p "$LOG_DIR"
chmod 0755 \
  "$ROOT/git-auto-sync.py" \
  "$ROOT/scripts/safe-repo-sync.sh" \
  "$SYNC_SCRIPT"

if ! crontab -l > "$CRON_FILE" 2>/dev/null; then
  : > "$CRON_FILE"
fi

python3 - "$CRON_FILE" "$ROOT" "$SHARED_ROOT" "$LOCK_FILE" "$LOG_DIR" <<'PY'
from __future__ import annotations

import sys
from pathlib import Path

cron_path = Path(sys.argv[1])
root = sys.argv[2]
shared_root = sys.argv[3]
lock_file = sys.argv[4]
log_dir = sys.argv[5]

kept = [
    line
    for line in cron_path.read_text(encoding="utf-8", errors="replace").splitlines()
    if "auto-sync-oracle.sh" not in line and "safe-repo-sync.sh" not in line
]
entry = (
    "*/5 * * * * "
    f"ROOT={root} SHARED_ROOT={shared_root} "
    f"/usr/bin/flock -n {lock_file} {root}/scripts/auto-sync-oracle.sh "
    f">> {log_dir}/safe-repo-sync-cron.log 2>&1"
)
kept.append(entry)
cron_path.write_text("\n".join(kept) + "\n", encoding="utf-8")
PY

crontab "$CRON_FILE"
echo "Auto sync Oracle instalado a cada 5 minutos com o mesmo lock do deploy."
