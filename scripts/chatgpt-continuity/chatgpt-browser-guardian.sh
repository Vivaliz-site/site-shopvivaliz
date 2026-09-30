#!/usr/bin/env bash
set -Eeuo pipefail

browser_unit="${CHATGPT_BROWSER_UNIT:-shopvivaliz-chatgpt-browser.service}"
cdp_url="${CHATGPT_BROWSER_CDP_URL:-http://127.0.0.1:9555/json/version}"
cdp_base="${CHATGPT_BROWSER_CDP_BASE:-${cdp_url%/json/version}}"
browser_pattern='^/opt/shopvivaliz-browser/chrome-linux/chrome --user-data-dir=/home/fredrdp/.config/shopvivaliz-chromium .*--remote-debugging-port=9555'

cdp_ready() {
  curl -fsS --connect-timeout 2 --max-time 3 "$cdp_url" 2>/dev/null \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); raise SystemExit(0 if isinstance(d.get("webSocketDebuggerUrl"), str) and d["webSocketDebuggerUrl"].startswith(("ws://","wss://")) else 1)' \
    >/dev/null 2>&1
}

target_ready() {
  CHATGPT_BROWSER_CDP_BASE="$cdp_base" timeout 6s node --input-type=module >/dev/null 2>&1 <<'NODE'
const base = String(process.env.CHATGPT_BROWSER_CDP_BASE || '').replace(/\/$/, '');
let tabs;
try {
  const response = await fetch(base + '/json', { signal: AbortSignal.timeout(2000) });
  if (!response.ok) process.exit(1);
  tabs = await response.json();
} catch {
  process.exit(1);
}
if (!Array.isArray(tabs)) process.exit(1);

for (const tab of tabs) {
  let parsed;
  try {
    parsed = new URL(String(tab?.url || ''));
  } catch {
    continue;
  }
  if (parsed.protocol !== 'https:' || parsed.hostname !== 'chatgpt.com') continue;
  const websocketUrl = String(tab?.webSocketDebuggerUrl || '');
  if (!/^wss?:\/\//.test(websocketUrl)) continue;

  let ws;
  try {
    ws = new WebSocket(websocketUrl);
    await Promise.race([
      new Promise((resolve, reject) => {
        ws.addEventListener('open', resolve, { once: true });
        ws.addEventListener('error', reject, { once: true });
      }),
      new Promise((_, reject) => setTimeout(() => reject(new Error('open timeout')), 1500)),
    ]);

    const id = 1;
    const reply = new Promise((resolve, reject) => {
      const onMessage = event => {
        let message;
        try {
          message = JSON.parse(event.data);
        } catch {
          return;
        }
        if (message?.id !== id) return;
        ws.removeEventListener('message', onMessage);
        message?.error ? reject(new Error('runtime evaluate failed')) : resolve(message);
      };
      ws.addEventListener('message', onMessage);
    });
    ws.send(JSON.stringify({
      id,
      method: 'Runtime.evaluate',
      params: { expression: 'true', returnByValue: true },
    }));
    await Promise.race([
      reply,
      new Promise((_, reject) => setTimeout(() => reject(new Error('evaluate timeout')), 1500)),
    ]);
    try { ws.close(); } catch {}
    process.exit(0);
  } catch {
    try { ws?.close(); } catch {}
  }
}
process.exit(1);
NODE
}

browser_ready() {
  cdp_ready && target_ready
}

wait_for_browser() {
  for _ in $(seq 1 15); do
    sleep 1
    if browser_ready; then
      return 0
    fi
  done
  return 1
}

status=0
if browser_ready; then
  echo "CHATGPT_BROWSER_GUARDIAN=HEALTHY"
elif pgrep -u fredrdp -f "$browser_pattern" >/dev/null 2>&1; then
  if systemctl is-active --quiet "$browser_unit"; then
    sleep 5
    if browser_ready; then
      echo "CHATGPT_BROWSER_GUARDIAN=HEALTHY_AFTER_RECHECK"
    else
      systemctl restart "$browser_unit"
      if wait_for_browser; then
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
  if wait_for_browser; then
    echo "CHATGPT_BROWSER_GUARDIAN=RECOVERED"
  else
    echo "CHATGPT_BROWSER_GUARDIAN=RECOVERY_FAILED" >&2
    status=1
  fi
fi

exit "$status"
