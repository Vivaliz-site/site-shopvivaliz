#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="${SHOPVIVALIZ_BROWSER_ROOT:-/home/ubuntu/shopvivaliz-browser-worker}"
REF="${SHOPVIVALIZ_BROWSER_REF:-main}"
REPO_RAW="https://raw.githubusercontent.com/Vivaliz-site/site-shopvivaliz/$REF"
PW_VERSION="1.62.1"

if [ "$(id -un)" != "ubuntu" ]; then
  echo "browser worker must run as ubuntu" >&2
  exit 20
fi
if [ "$(hostname)" != "always-free-arm-1787907847-26" ]; then
  echo "backend host identity mismatch" >&2
  exit 21
fi

umask 077
mkdir -p "$ROOT" "$ROOT/logs" "$ROOT/run" /home/ubuntu/.local/share/shopvivaliz-browser-worker/profiles
chmod 700 "$ROOT" "$ROOT/logs" "$ROOT/run" /home/ubuntu/.local/share/shopvivaliz-browser-worker

curl -fsSL "$REPO_RAW/ops/browser-worker/server.mjs" -o "$ROOT/server.mjs"
curl -fsSL "$REPO_RAW/ops/browser-worker/supervisor.sh" -o "$ROOT/supervisor.sh"
chmod 700 "$ROOT/supervisor.sh"
chmod 600 "$ROOT/server.mjs"

if [ ! -f "$ROOT/package.json" ]; then
  printf '{"private":true,"type":"module"}\n' >"$ROOT/package.json"
fi

current=""
if [ -f "$ROOT/node_modules/playwright-core/package.json" ]; then
  current_value=""
  if current_value="$(node -p "require('$ROOT/node_modules/playwright-core/package.json').version" 2>/dev/null)"; then
    current="$current_value"
  fi
fi
if [ "$current" != "$PW_VERSION" ]; then
  npm install --prefix "$ROOT" --no-audit --no-fund --omit=dev --save-exact "playwright-core@$PW_VERSION"
fi

chrome="$(find /home/ubuntu/.cache/ms-playwright -maxdepth 3 -type f -path '*/chromium-*/chrome-linux/chrome' -perm -u+x 2>/dev/null | sort -V | tail -1)"
test -n "$chrome"
"$chrome" --version
if ldd "$chrome" | grep -q 'not found'; then
  echo "Chromium has missing shared libraries" >&2
  exit 22
fi
command -v Xvfb >/dev/null

marker="# SHOPVIVALIZ_BROWSER_WORKER_V1"
existing=""
if current_crontab="$(crontab -l 2>/dev/null)"; then
  existing="$current_crontab"
else
  rc=$?
  test "$rc" -eq 1 || exit "$rc"
fi
clean="$(printf '%s\n' "$existing" | awk -v marker="$marker" -v root="$ROOT" '
  index($0, marker) == 0 &&
  index($0, root "/supervisor.sh ensure") == 0 &&
  index($0, root "/supervisor.sh start") == 0 { print }
')"
{
  printf '%s\n' "$clean"
  printf '%s\n' "$marker"
  printf '@reboot sleep 30 && %s ensure >/dev/null 2>&1\n' "$ROOT/supervisor.sh"
  printf '*/5 * * * * %s ensure >/dev/null 2>&1 %s\n' "$ROOT/supervisor.sh" "$marker"
} | sed '/^[[:space:]]*$/d' | crontab -

"$ROOT/supervisor.sh" restart
curl -fsS --connect-timeout 2 --max-time 5 http://127.0.0.1:17777/health
echo
echo "BROWSER_WORKER_INSTALL=PASS"
