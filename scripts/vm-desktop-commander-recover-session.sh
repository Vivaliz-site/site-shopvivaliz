#!/usr/bin/env bash
set -Eeuo pipefail

SERVICE='shopvivaliz-desktop-commander.service'
TARGET='/home/ubuntu/.desktop-commander-device/device.json'
COOLDOWN='/home/ubuntu/.desktop-commander-device/auth-required.cooldown'

systemctl stop "$SERVICE" || true

candidate="$(python3 - "$TARGET" <<'PY'
import glob
import json
import os
import sys

target = sys.argv[1]
patterns = [
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
        session = data.get('session') if isinstance(data, dict) else None
        if isinstance(session, dict) and session:
            print(path)
            raise SystemExit(0)
raise SystemExit(1)
PY
)" || true

if [[ -z "$candidate" ]]; then
  echo 'SESSION_BACKUP_FOUND=false'
  systemctl start "$SERVICE" || true
  exit 21
fi

echo 'SESSION_BACKUP_FOUND=true'
install -m 0600 -o ubuntu -g ubuntu "$candidate" "$TARGET"
rm -f "$COOLDOWN"
systemctl start "$SERVICE"
sleep 8
systemctl is-active --quiet "$SERVICE"
