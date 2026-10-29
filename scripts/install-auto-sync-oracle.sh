#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-/home/ubuntu/shopvivaliz-deploy/sync-repo}"
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

if [ -r "$CURRENT_ROOT/scripts/auto-sync-oracle.sh" ]; then
  SYNC_SCRIPT="$CURRENT_ROOT/scripts/auto-sync-oracle.sh"
else
  SYNC_SCRIPT="$SCRIPT_ROOT/scripts/auto-sync-oracle.sh"
fi

if [ ! -r "$SYNC_SCRIPT" ]; then
  echo "Script de sync ausente ou ilegivel: $SYNC_SCRIPT" >&2
  exit 2
fi

mkdir -p "$LOG_DIR"

# A single canonical scheduler avoids simultaneous duplicate syncs. The
# systemd timer is the primary scheduler on Marina; cron is a fallback ONLY
# when the timer is not both enabled and active. Both scripts already take the
# same repository lock, but the cron wrapper additionally owns the deploy
# lock and can interfere with a legitimate release cutover.
if command -v systemctl >/dev/null 2>&1 \
  && systemctl is-enabled --quiet shopvivaliz-sync-safe.timer \
  && systemctl is-active --quiet shopvivaliz-sync-safe.timer; then
  if command -v crontab >/dev/null 2>&1; then
    crontab -l > "$CRON_FILE" 2>/dev/null || : > "$CRON_FILE"
    if grep -E 'auto-sync-oracle\\.sh|safe-repo-sync\\.sh' "$CRON_FILE" >/dev/null; then
      python3 - "$CRON_FILE" <<'PY'
import sys
from pathlib import Path
p = Path(sys.argv[1])
lines = p.read_text(encoding="utf-8", errors="replace").splitlines()
safe = [line for line in lines if "auto-sync-oracle.sh" not in line and "safe-repo-sync.sh" not in line]
p.write_text("\\n".join(safe) + ("\\n" if safe else ""), encoding="utf-8")
PY
      crontab "$CRON_FILE"
      echo 'SAFE_SYNC_CRON_DUPLICATE_REMOVED=YES'
    fi
  fi
  echo 'SAFE_SYNC_SYSTEMD_CANONICAL=PASS'
  exit 0
fi

if ! command -v crontab >/dev/null 2>&1; then
  if systemctl is-enabled --quiet shopvivaliz-sync-safe.timer \
    && systemctl is-active --quiet shopvivaliz-sync-safe.timer; then
    echo "Auto sync Oracle ativo via shopvivaliz-sync-safe.timer (systemd)."
    exit 0
  fi
  echo "crontab indisponivel e shopvivaliz-sync-safe.timer nao esta ativo; execute o instalador systemd no deploy." >&2
  exit 3
fi

if ! crontab -l > "$CRON_FILE" 2>/dev/null; then
  : > "$CRON_FILE"
fi

python3 - "$CRON_FILE" "$ROOT" "$SHARED_ROOT" "$LOCK_FILE" "$LOG_DIR" "$SYNC_SCRIPT" <<'PY'
from __future__ import annotations

import shlex
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
    f"ROOT={shlex.quote(root)} SHARED_ROOT={shlex.quote(shared_root)} "
    f"/usr/bin/flock -n {shlex.quote(lock_file)} "
    f"/usr/bin/bash {shlex.quote(sync_script)} "
    f">> {shlex.quote(log_dir + '/safe-repo-sync-cron.log')} 2>&1"
)
kept.append(entry)
cron_path.write_text("\n".join(kept) + "\n", encoding="utf-8")
PY

crontab "$CRON_FILE"
echo "Auto sync Oracle instalado a cada 5 minutos usando bash e a release ativa."
