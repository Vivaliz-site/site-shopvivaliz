#!/usr/bin/env bash
set -Eeuo pipefail

browser_unit="${CHATGPT_BROWSER_UNIT:-shopvivaliz-chatgpt-browser.service}"
cdp_url="${CHATGPT_BROWSER_CDP_URL:-http://127.0.0.1:9555/json/version}"
browser_pattern='^/opt/shopvivaliz-browser/chrome-linux/chrome --user-data-dir=/home/fredrdp/.config/shopvivaliz-chromium .*--remote-debugging-port=9555'

cdp_ready() {
  curl -fsS --connect-timeout 2 --max-time 3 "$cdp_url" 2>/dev/null \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); raise SystemExit(0 if isinstance(d.get("webSocketDebuggerUrl"), str) and d["webSocketDebuggerUrl"].startswith(("ws://","wss://")) else 1)' \
    >/dev/null 2>&1
}

status=0
if cdp_ready; then
  echo "CHATGPT_BROWSER_GUARDIAN=HEALTHY"
elif pgrep -u fredrdp -f "$browser_pattern" >/dev/null 2>&1; then
  echo "CHATGPT_BROWSER_GUARDIAN=DEGRADED_PROCESS_PRESENT_CDP_UNAVAILABLE" >&2
  status=1
else
  systemctl start "$browser_unit"
  status=1
  for _ in $(seq 1 15); do
    sleep 1
    if cdp_ready; then
      echo "CHATGPT_BROWSER_GUARDIAN=RECOVERED"
      status=0
      break
    fi
  done
  if [[ "$status" -ne 0 ]]; then
    echo "CHATGPT_BROWSER_GUARDIAN=RECOVERY_FAILED" >&2
  fi
fi

exit "$status"
