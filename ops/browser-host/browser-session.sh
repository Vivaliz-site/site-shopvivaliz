#!/usr/bin/env bash
set -Eeuo pipefail

STATE=/var/lib/shopvivaliz-browser
APP=/opt/shopvivaliz-browser-host
SESSION_DIR="$STATE/sessions/mfa"
META="$SESSION_DIR/metadata.json"
PROFILE_NAME="${BROWSER_PROFILE:-mfa-default}"
ORIGIN="${BROWSER_SESSION_ORIGIN:-human-mfa}"
TASK="${BROWSER_SESSION_TASK:-interactive-auth}"
TTL_SECONDS="${BROWSER_SESSION_TTL_SECONDS:-7200}"

valid_id() { [[ "$1" =~ ^[A-Za-z0-9._-]{1,80}$ ]]; }
valid_id "$PROFILE_NAME" || { echo "invalid profile name" >&2; exit 2; }
valid_id "$ORIGIN" || { echo "invalid origin" >&2; exit 2; }
valid_id "$TASK" || { echo "invalid task" >&2; exit 2; }
[[ "$TTL_SECONDS" =~ ^[0-9]+$ ]] || { echo "invalid ttl" >&2; exit 2; }
(( TTL_SECONDS >= 300 && TTL_SECONDS <= 7200 )) || { echo "ttl outside 300..7200" >&2; exit 2; }

mkdir -p "$SESSION_DIR" "$STATE/profiles/$PROFILE_NAME" "$STATE/artifacts/mfa"
chmod 750 "$SESSION_DIR" "$STATE/profiles/$PROFILE_NAME" "$STATE/artifacts/mfa"

export HOME="$STATE"
export PLAYWRIGHT_BROWSERS_PATH="$STATE/pw-browsers"
export DISPLAY=:99

BROWSER_BIN="$(node -e "const {chromium}=require('$APP/node_modules/playwright');process.stdout.write(chromium.executablePath())")"
test -x "$BROWSER_BIN"

pids=()
cleanup() {
  for pid in "${pids[@]:-}"; do
    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then kill "$pid" 2>/dev/null || :; fi
  done
  wait 2>/dev/null || :
}
trap cleanup EXIT INT TERM

Xvfb :99 -screen 0 1440x900x24 -nolisten tcp -ac >"$SESSION_DIR/xvfb.log" 2>&1 &
pids+=("$!")
sleep 1
openbox >"$SESSION_DIR/openbox.log" 2>&1 &
pids+=("$!")

"$BROWSER_BIN"   --user-data-dir="$STATE/profiles/$PROFILE_NAME"   --remote-debugging-address=127.0.0.1   --remote-debugging-port=9222   --no-first-run   --no-default-browser-check   --disable-dev-shm-usage   --window-size=1440,900   about:blank >"$SESSION_DIR/chromium.log" 2>&1 &
BROWSER_PID=$!
pids+=("$BROWSER_PID")

x11vnc -display :99 -localhost -forever -shared -nopw -rfbport 5900 >"$SESSION_DIR/x11vnc.log" 2>&1 &
pids+=("$!")
websockify --web=/usr/share/novnc 127.0.0.1:6080 127.0.0.1:5900 >"$SESSION_DIR/websockify.log" 2>&1 &
pids+=("$!")

START_EPOCH="$(date +%s)"
EXPIRES_EPOCH="$((START_EPOCH + TTL_SECONDS))"
python3 - "$META" "$ORIGIN" "$TASK" "$PROFILE_NAME" "$START_EPOCH" "$EXPIRES_EPOCH" "$BROWSER_PID" <<'PY'
import json, sys
path, origin, task, profile, started, expires, pid = sys.argv[1:]
data = {
    "origin": origin,
    "task": task,
    "machine": "always-free-arm-1787907847-26",
    "profile": profile,
    "started_epoch": int(started),
    "expires_epoch": int(expires),
    "browser_pid": int(pid),
}
with open(path, "w", encoding="utf-8") as fh:
    json.dump(data, fh, separators=(",", ":"))
PY
chmod 640 "$META"

for _ in $(seq 1 30); do
  if curl -fsS --connect-timeout 1 --max-time 2 http://127.0.0.1:9222/json/version >/dev/null      && timeout 1 bash -c '</dev/tcp/127.0.0.1/6080' 2>/dev/null; then
    echo "BROWSER_MFA_SESSION=READY"
    break
  fi
  sleep 1
done
curl -fsS --connect-timeout 2 --max-time 3 http://127.0.0.1:9222/json/version >/dev/null
timeout 2 bash -c '</dev/tcp/127.0.0.1/6080'

wait -n "${pids[@]}"
echo "browser session child exited unexpectedly" >&2
exit 1
