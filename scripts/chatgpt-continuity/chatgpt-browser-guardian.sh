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

wait_for_cdp() {
  for _ in $(seq 1 15); do
    sleep 1
    if cdp_ready; then
      return 0
    fi
  done
  return 1
}

status=0
if cdp_ready; then
  echo "CHATGPT_BROWSER_GUARDIAN=HEALTHY"
elif pgrep -u fredrdp -f "$browser_pattern" >/dev/null 2>&1; then
  if systemctl is-active --quiet "$browser_unit"; then
    sleep 5
    if cdp_ready; then
      echo "CHATGPT_BROWSER_GUARDIAN=HEALTHY_AFTER_RECHECK"
    else
      systemctl restart "$browser_unit"
      if wait_for_cdp; then
        echo "CHATGPT_BROWSER_GUARDIAN=RECOVERED_MANAGED_RESTART"
      else
        echo "CHATGPT_BROWSER_GUARDIAN=RECOVERY_FAILED" >&2
        status=1
      fi
    fi
  else
    echo "CHATGPT_BROWSER_GUARDIAN=DEGRADED_PROCESS_PRESENT_CDP_UNAVAILABLE" >&2
    status=1
  fi
else
  systemctl start "$browser_unit"
  if wait_for_cdp; then
    echo "CHATGPT_BROWSER_GUARDIAN=RECOVERED"
  else
    echo "CHATGPT_BROWSER_GUARDIAN=RECOVERY_FAILED" >&2
    status=1
  fi
fi

exit "$status"
