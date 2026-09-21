#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="${SHOPVIVALIZ_BROWSER_ROOT:-/home/ubuntu/shopvivaliz-browser-worker}"
PID_DIR="$ROOT/run"
LOG_DIR="$ROOT/logs"
DISPLAY_NUM="${SHOPVIVALIZ_BROWSER_DISPLAY:-:98}"
PORT="${SHOPVIVALIZ_BROWSER_PORT:-17777}"
SITE_HOST="${SHOPVIVALIZ_BROWSER_SITE_HOST:-10.0.1.112}"
SITE_USER="${SHOPVIVALIZ_BROWSER_SITE_USER:-ubuntu}"
SITE_KEY="${SHOPVIVALIZ_BROWSER_SITE_KEY:-/home/ubuntu/.ssh/shopvivaliz-free-a1-monitor}"
SITE_REMOTE_PORT="${SHOPVIVALIZ_BROWSER_SITE_REMOTE_PORT:-17777}"

mkdir -p "$PID_DIR" "$LOG_DIR"
if ! chmod 700 "$ROOT" "$PID_DIR" "$LOG_DIR" 2>/dev/null; then
  echo "failed to secure browser worker directories" >&2
  exit 1
fi
exec 9>"$ROOT/.supervisor.lock"
flock -w 15 9

pid_alive() {
  local file="$1" needle="$2" pid
  test -s "$file" || return 1
  if ! pid="$(cat "$file" 2>/dev/null)"; then
    return 1
  fi
  [[ "$pid" =~ ^[0-9]+$ ]] || return 1
  kill -0 "$pid" 2>/dev/null || return 1
  tr '\0' ' ' <"/proc/$pid/cmdline" 2>/dev/null | grep -Fq "$needle"
}

stop_pid() {
  local file="$1" needle="$2" pid
  if pid_alive "$file" "$needle"; then
    pid="$(cat "$file")"
    if ! kill "$pid" 2>/dev/null; then
      echo "failed to stop pid=$pid for $needle" >&2
    fi
    for _ in $(seq 1 20); do
      kill -0 "$pid" 2>/dev/null || break
      sleep 0.25
    done
    if kill -0 "$pid" 2>/dev/null; then
      kill -9 "$pid"
    fi
  fi
  rm -f "$file"
}

browser_binary() {
  local candidate
  candidate="$(find /home/ubuntu/.cache/ms-playwright -maxdepth 3 -type f -path '*/chromium-*/chrome-linux/chrome' -perm -u+x 2>/dev/null | sort -V | tail -1)"
  test -n "$candidate"
  printf '%s\n' "$candidate"
}

start_xvfb() {
  local pf="$PID_DIR/xvfb.pid"
  if pid_alive "$pf" "Xvfb $DISPLAY_NUM"; then return 0; fi
  rm -f "$pf"
  nohup Xvfb "$DISPLAY_NUM" -screen 0 1440x900x24 -nolisten tcp -ac >>"$LOG_DIR/xvfb.log" 2>&1 9>&- &
  echo $! >"$pf"
  sleep 1
  pid_alive "$pf" "Xvfb $DISPLAY_NUM"
}

start_worker() {
  local pf="$PID_DIR/worker.pid" chrome
  if pid_alive "$pf" "server.mjs"; then
    if curl -fsS --connect-timeout 2 --max-time 5 "http://127.0.0.1:$PORT/health" >/dev/null; then return 0; fi
    stop_pid "$pf" "server.mjs"
  fi
  chrome="$(browser_binary)"
  test -f "$ROOT/server.mjs"
  test -d "$ROOT/node_modules/playwright-core"
  nohup env     DISPLAY="$DISPLAY_NUM"     SHOPVIVALIZ_BROWSER_PORT="$PORT"     SHOPVIVALIZ_CHROMIUM_PATH="$chrome"     SHOPVIVALIZ_BROWSER_STATE="/home/ubuntu/.local/share/shopvivaliz-browser-worker"     node "$ROOT/server.mjs" >>"$LOG_DIR/worker.log" 2>&1 9>&- &
  echo $! >"$pf"
  for _ in $(seq 1 30); do
    if curl -fsS --connect-timeout 1 --max-time 2 "http://127.0.0.1:$PORT/health" >/dev/null 2>&1; then return 0; fi
    sleep 0.5
  done
  return 1
}

start_tunnel() {
  local pf="$PID_DIR/tunnel.pid"
  if pid_alive "$pf" "127.0.0.1:$SITE_REMOTE_PORT:127.0.0.1:$PORT"; then return 0; fi
  rm -f "$pf"
  test -f "$SITE_KEY"
  if ! chmod 600 "$SITE_KEY" 2>/dev/null; then
    echo "failed to secure SITE_KEY" >&2
    return 1
  fi
  nohup ssh -NT     -i "$SITE_KEY"     -o BatchMode=yes     -o IdentitiesOnly=yes     -o ExitOnForwardFailure=yes     -o ServerAliveInterval=30     -o ServerAliveCountMax=3     -o StrictHostKeyChecking=yes     -R "127.0.0.1:$SITE_REMOTE_PORT:127.0.0.1:$PORT"     "$SITE_USER@$SITE_HOST" >>"$LOG_DIR/tunnel.log" 2>&1 9>&- &
  echo $! >"$pf"
  sleep 2
  pid_alive "$pf" "127.0.0.1:$SITE_REMOTE_PORT:127.0.0.1:$PORT"
}

verify_remote() {
  ssh -i "$SITE_KEY"     -o BatchMode=yes     -o IdentitiesOnly=yes     -o ConnectTimeout=5     -o StrictHostKeyChecking=yes     "$SITE_USER@$SITE_HOST"     "curl -fsS --connect-timeout 2 --max-time 5 http://127.0.0.1:$SITE_REMOTE_PORT/health" >/dev/null
}

start_all() {
  start_xvfb
  start_worker
  start_tunnel
  verify_remote
  echo "BROWSER_WORKER_START=PASS"
}

status_all() {
  local x=false w=false t=false local_health=false remote_health=false
  pid_alive "$PID_DIR/xvfb.pid" "Xvfb $DISPLAY_NUM" && x=true
  pid_alive "$PID_DIR/worker.pid" "server.mjs" && w=true
  pid_alive "$PID_DIR/tunnel.pid" "127.0.0.1:$SITE_REMOTE_PORT:127.0.0.1:$PORT" && t=true
  curl -fsS --connect-timeout 2 --max-time 5 "http://127.0.0.1:$PORT/health" >/dev/null 2>&1 && local_health=true
  if test -f "$SITE_KEY"; then
    verify_remote >/dev/null 2>&1 && remote_health=true
  fi
  printf 'XVFB_ACTIVE=%s\nWORKER_ACTIVE=%s\nTUNNEL_ACTIVE=%s\nLOCAL_HEALTH=%s\nREMOTE_HEALTH=%s\n'     "$x" "$w" "$t" "$local_health" "$remote_health"
  "$local_health" && "$remote_health"
}

stop_all() {
  stop_pid "$PID_DIR/tunnel.pid" "127.0.0.1:$SITE_REMOTE_PORT:127.0.0.1:$PORT"
  stop_pid "$PID_DIR/worker.pid" "server.mjs"
  stop_pid "$PID_DIR/xvfb.pid" "Xvfb $DISPLAY_NUM"
  echo "BROWSER_WORKER_STOP=PASS"
}

case "${1:-status}" in
  start) start_all ;;
  ensure)
    if ! status_all >/dev/null 2>&1; then start_all; else echo "BROWSER_WORKER_ENSURE=HEALTHY"; fi
    ;;
  stop) stop_all ;;
  restart) stop_all; start_all ;;
  status) status_all ;;
  *) echo "usage: $0 {start|ensure|stop|restart|status}" >&2; exit 2 ;;
esac
