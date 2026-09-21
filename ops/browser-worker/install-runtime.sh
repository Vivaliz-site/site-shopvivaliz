#!/usr/bin/env bash
set -euo pipefail

SOURCE_ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
RUNTIME_ROOT="${SHOPVIVALIZ_BROWSER_ROOT:-/home/ubuntu/shopvivaliz-browser-worker}"
USER_SYSTEMD="${HOME}/.config/systemd/user"

if [ "$(id -un)" != "ubuntu" ]; then
  echo "browser worker installer must run as ubuntu" >&2
  exit 2
fi

test -f "$SOURCE_ROOT/ops/browser-worker/server.mjs"
test -f "$SOURCE_ROOT/ops/browser-worker/package.json"
test -f "$SOURCE_ROOT/ops/browser-worker/supervisor.sh"

node --check "$SOURCE_ROOT/ops/browser-worker/server.mjs"

mkdir -p "$RUNTIME_ROOT" "$USER_SYSTEMD"
install -m 600 "$SOURCE_ROOT/ops/browser-worker/server.mjs" "$RUNTIME_ROOT/server.mjs"
install -m 600 "$SOURCE_ROOT/ops/browser-worker/package.json" "$RUNTIME_ROOT/package.json"
install -m 700 "$SOURCE_ROOT/ops/browser-worker/supervisor.sh" "$RUNTIME_ROOT/supervisor.sh"

if [ ! -d "$RUNTIME_ROOT/node_modules/playwright-core" ]; then
  npm --prefix "$RUNTIME_ROOT" install --omit=dev --no-audit --no-fund
fi

install -m 644 "$SOURCE_ROOT/ops/browser-worker/shopvivaliz-browser-worker-ensure.service" "$USER_SYSTEMD/shopvivaliz-browser-worker-ensure.service"
install -m 644 "$SOURCE_ROOT/ops/browser-worker/shopvivaliz-browser-worker-ensure.timer" "$USER_SYSTEMD/shopvivaliz-browser-worker-ensure.timer"

systemctl --user daemon-reload
"$RUNTIME_ROOT/supervisor.sh" restart
systemctl --user enable --now shopvivaliz-browser-worker-ensure.timer

curl -fsS --connect-timeout 2 --max-time 8 http://127.0.0.1:17777/health >/dev/null
curl -fsS --connect-timeout 2 --max-time 15 http://127.0.0.1:17777/chatgpt/health >/dev/null

echo "BROWSER_WORKER_INSTALL=PASS"
