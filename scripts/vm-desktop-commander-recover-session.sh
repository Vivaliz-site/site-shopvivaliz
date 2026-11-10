#!/usr/bin/env bash
set -Eeuo pipefail

SERVICE='shopvivaliz-desktop-commander.service'
TARGET='/home/ubuntu/.desktop-commander-device/device.json'
COOLDOWN='/home/ubuntu/.desktop-commander-device/auth-required.cooldown'

candidate="$(python3 - "$TARGET" <<'PY'
import glob
import json
import os
import sys

target = sys.argv[1]
try:
    with open(target, encoding='utf-8') as fh:
        current = json.load(fh)
except Exception:
    raise SystemExit(2)
current_id = current.get('deviceId') if isinstance(current, dict) else None
if not isinstance(current_id, str) or not current_id:
    raise SystemExit(2)
patterns = [
    '/home/ubuntu/.desktop-commander-device/session-backup/device.json',
    '/home/ubuntu/.desktop-commander-device/*.json*',
    '/home/ubuntu/.desktop-commander-device*/*.json*',
    '/home/ubuntu/.config/**/*desktop*commander*.json*',
]
seen = set()
for pattern in patterns:
    for path in glob.glob(pattern, recursive=True):
        if path == target or path in seen or not os.path.isfile(path):
            continue
        seen.add(path)
        try:
            with open(path, encoding='utf-8') as fh:
                data = json.load(fh)
        except Exception:
            continue
        if not isinstance(data, dict) or data.get('deviceId') != current_id:
            continue
        session = data.get('session')
        if not isinstance(session, dict):
            continue
        if not session.get('access_token') or not session.get('refresh_token'):
            continue
        print(path)
        raise SystemExit(0)
raise SystemExit(1)
PY
)" || true

if [[ -z "$candidate" ]]; then
  echo 'SESSION_BACKUP_FOUND=false'
  exit 21
fi

echo 'SESSION_BACKUP_FOUND=true'
systemctl stop "$SERVICE" || true
install -m 0600 -o ubuntu -g ubuntu "$candidate" "$TARGET"
rm -f "$COOLDOWN"
systemctl start "$SERVICE"
sleep 8
systemctl is-active --quiet "$SERVICE"
