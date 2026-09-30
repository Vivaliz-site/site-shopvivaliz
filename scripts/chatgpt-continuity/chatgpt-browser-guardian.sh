#!/usr/bin/env bash
set -Eeuo pipefail

browser_unit="${CHATGPT_BROWSER_UNIT:-shopvivaliz-chatgpt-browser.service}"
cdp_url="${CHATGPT_BROWSER_CDP_URL:-http://127.0.0.1:9555/json/version}"
cdp_base="${CHATGPT_BROWSER_CDP_BASE:-${cdp_url%/json/version}}"
worker_module="${CHATGPT_CONTINUITY_WORKER_MODULE:-/home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs}"
browser_pattern='^/opt/shopvivaliz-browser/chrome-linux/chrome --user-data-dir=/home/fredrdp/.config/shopvivaliz-chromium .*--remote-debugging-port=9555'

runtime_eval_ready() {
  CHATGPT_CONTINUITY_WORKER_MODULE="$worker_module" CHATGPT_BROWSER_CDP_BASE="$cdp_base" \
    timeout 8s node --input-type=module -e '
      const { Cdp, connectFirstUsableChatgptTab } = await import(
        "file://" + process.env.CHATGPT_CONTINUITY_WORKER_MODULE
      );
      const base = String(process.env.CHATGPT_BROWSER_CDP_BASE || "").replace(/\/$/, "");
      const response = await fetch(base + "/json", { signal: AbortSignal.timeout(2500) });
      if (!response.ok) process.exit(1);
      const tabs = await response.json();
      const c = await connectFirstUsableChatgptTab(tabs, async page => {
        let ws;
        let candidate;
        try {
          ws = new WebSocket(page.webSocketDebuggerUrl);
          await Promise.race([
            new Promise((resolve, reject) => {
              ws.addEventListener("open", resolve, { once: true });
              ws.addEventListener("error", reject, { once: true });
            }),
            new Promise((_, reject) => setTimeout(() => reject(new Error("open timeout")), 2500)),
          ]);
          candidate = new Cdp(ws);
          await Promise.race([
            candidate.evaluate("true"),
            new Promise((_, reject) => setTimeout(() => reject(new Error("evaluate timeout")), 2500)),
          ]);
          return candidate;
        } catch (error) {
          try { candidate?.close(); } catch {}
          try { ws?.close(); } catch {}
          throw error;
        }
      });
      if (!c) process.exit(1);
      try {
        const value = await c.evaluate("(()=>42)()");
        if (value !== 42) process.exitCode = 1;
      } finally {
        c.close();
      }
    ' >/dev/null 2>&1
}

cdp_ready() {
  curl -fsS --connect-timeout 2 --max-time 3 "$cdp_url" 2>/dev/null \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); raise SystemExit(0 if isinstance(d.get("webSocketDebuggerUrl"), str) and d["webSocketDebuggerUrl"].startswith(("ws://","wss://")) else 1)' \
    >/dev/null 2>&1 \
    && runtime_eval_ready
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

pid_is_live() {
  local pid="$1"
  kill -0 "$pid" 2>/dev/null || return 1
  local state
  state="$(ps -o stat= -p "$pid" 2>/dev/null | awk '{print $1}')"
  [[ -n "$state" && "$state" != Z* ]]
}

mapfile -t canonical_pids < <(pgrep -u fredrdp -f "$browser_pattern" || true)

status=0
if cdp_ready; then
  echo "CHATGPT_BROWSER_GUARDIAN=HEALTHY"
elif [[ "${#canonical_pids[@]}" -gt 0 ]]; then
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
  elif [[ "${#canonical_pids[@]}" -eq 1 ]]; then
    canonical_pid="${canonical_pids[0]}"
    if [[ ! "$canonical_pid" =~ ^[0-9]+$ ]]; then
      echo "CHATGPT_BROWSER_GUARDIAN=DEGRADED_INVALID_CANONICAL_PID" >&2
      status=1
    elif ! systemctl is-enabled --quiet "$browser_unit"; then
      echo "CHATGPT_BROWSER_GUARDIAN=DEGRADED_UNMANAGED_TAKEOVER_UNIT_NOT_ENABLED" >&2
      status=1
    else
      # The process matched the fully anchored canonical browser command and
      # there is exactly one candidate. Terminate only that PID, never a broad
      # process class, then relaunch the same profile under systemd supervision.
      kill -TERM "$canonical_pid" 2>/dev/null || true
      terminated=false
      for _ in $(seq 1 10); do
        if ! pid_is_live "$canonical_pid"; then
          terminated=true
          break
        fi
        sleep 1
      done
      if [[ "$terminated" != true ]]; then
        echo "CHATGPT_BROWSER_GUARDIAN=DEGRADED_UNMANAGED_TAKEOVER_TIMEOUT" >&2
        status=1
      else
        systemctl start "$browser_unit"
        if wait_for_cdp; then
          echo "CHATGPT_BROWSER_GUARDIAN=RECOVERED_UNMANAGED_TAKEOVER"
        else
          echo "CHATGPT_BROWSER_GUARDIAN=RECOVERY_FAILED" >&2
          status=1
        fi
      fi
    fi
  else
    echo "CHATGPT_BROWSER_GUARDIAN=DEGRADED_MULTIPLE_CANONICAL_PROCESSES" >&2
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
