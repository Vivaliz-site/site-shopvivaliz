#!/usr/bin/env bash
set -Eeuo pipefail

browser_unit="${CHATGPT_BROWSER_UNIT:-shopvivaliz-chatgpt-browser.service}"
cdp_url="${CHATGPT_BROWSER_CDP_URL:-http://127.0.0.1:9555/json/version}"
cdp_base="${CHATGPT_BROWSER_CDP_BASE:-${cdp_url%/json/version}}"
worker_module="${CHATGPT_CONTINUITY_WORKER_MODULE:-/home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs}"
browser_health_file="${CHATGPT_BROWSER_HEALTH_FILE:-/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state/_chatgpt-browser-health.json}"
probe_cache_helper="${CHATGPT_BROWSER_PROBE_CACHE_HELPER:-$(dirname "$0")/chatgpt-browser-probe-cache.py}"
task_state_dir="${SHOPVIVALIZ_AGENT_TASK_STATE_DIR:-/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state}"
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
      const authHosts = new Set(["auth.openai.com", "accounts.google.com", "appleid.apple.com"]);
      const authPage = tabs.find(page => {
        if (!page || page.type !== "page" || !page.webSocketDebuggerUrl) return false;
        try { return authHosts.has(new URL(String(page.url || "")).hostname); } catch { return false; }
      });
      const connect = async page => {
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
      };
      const c = authPage ? await connect(authPage) : await connectFirstUsableChatgptTab(tabs, connect);
      if (!c) process.exit(1);
      try {
        const value = await c.evaluate("(()=>42)()");
        if (value !== 42) process.exitCode = 1;
      } finally {
        c.close();
      }
    ' >/dev/null 2>&1
}

browser_session_state() {
  CHATGPT_CONTINUITY_WORKER_MODULE="$worker_module" CHATGPT_BROWSER_CDP_BASE="$cdp_base" \
    timeout 8s node --input-type=module -e '
      // CONTINUITY_BROWSER_SESSION_STATE_PROBE
      const { Cdp, connectFirstUsableChatgptTab } = await import(
        "file://" + process.env.CHATGPT_CONTINUITY_WORKER_MODULE
      );
      const base = String(process.env.CHATGPT_BROWSER_CDP_BASE || "").replace(/\/$/, "");
      const response = await fetch(base + "/json", { signal: AbortSignal.timeout(2500) });
      if (!response.ok) { console.log("UNREACHABLE"); process.exit(0); }
      const tabs = await response.json();
      const authHosts = new Set(["auth.openai.com", "accounts.google.com", "appleid.apple.com"]);
      const authPages = tabs.filter(page => {
        if (!page || page.type !== "page" || !page.webSocketDebuggerUrl) return false;
        try { return authHosts.has(new URL(String(page.url || "")).hostname); } catch { return false; }
      });
      const authFlow = authPages.length > 0;
      let authTerminal = false;
      let residualAuthTerminal = false;
      let validOpenAiAuthFlow = false;
      for (const page of authPages) {
        let authCdp;
        let pageHost = "";
        try {
          pageHost = new URL(String(page.url || "")).hostname;
          const ws = new WebSocket(page.webSocketDebuggerUrl);
          await Promise.race([
            new Promise((resolve, reject) => {
              ws.addEventListener("open", resolve, { once: true });
              ws.addEventListener("error", reject, { once: true });
            }),
            new Promise((_, reject) => setTimeout(() => reject(new Error("open timeout")), 2500)),
          ]);
          authCdp = new Cdp(ws);
          const authProbe = await Promise.race([
            authCdp.evaluate(`(()=>{
              /* CONTINUITY_BROWSER_AUTH_TERMINAL_PROBE */
              const body = String(document.body?.innerText || "").toLowerCase();
              const href = String(location.href || "").toLowerCase();
              const terminal = body.includes("invalid_state")
                || href.includes("error=invalid_state")
                || body.includes("session ended")
                || body.includes("your sign-in session is no longer valid")
                || body.includes("operation timed out")
                || body.includes("oops, an error occurred");
              const activeOpenAiVerification = location.hostname === "auth.openai.com"
                && location.pathname === "/email-verification"
                && body.includes("enter the verification code")
                && Boolean(document.querySelector("input[name=code]"));
              return { terminal, activeOpenAiVerification };
            })()`),
            new Promise((_, reject) => setTimeout(() => reject(new Error("auth probe timeout")), 2500)),
          ]);
          if (authProbe?.activeOpenAiVerification === true) validOpenAiAuthFlow = true;
          if (authProbe?.terminal === true) {
            if (pageHost === "auth.openai.com") {
              authTerminal = true;
            } else {
              residualAuthTerminal = true;
            }
          }
        } catch {
          // A detached auth tab is not enough evidence to classify terminal auth.
        } finally {
          try { authCdp?.close(); } catch {}
        }
      }
      authTerminal = (authTerminal || residualAuthTerminal) && !validOpenAiAuthFlow;
      const probeChatgptSessionState = async candidate => candidate.evaluate(`(async()=>{
        try {
          const sessionResponse = await fetch("/api/auth/session", {
            credentials: "same-origin",
            cache: "no-store",
            signal: AbortSignal.timeout(2000),
          });
          if (sessionResponse.ok) {
            let session = null;
            try { session = await sessionResponse.json(); } catch {}
            const hasIdentity = Boolean(session?.account || session?.user);
            const hasAccessToken = Boolean(session?.accessToken || session?.access_token);
            if (hasIdentity && hasAccessToken) return "AUTHENTICATED";
          }
        } catch {}
        const body = String(document.body?.innerText || "").toLowerCase();
        const path = String(location.pathname || "");
        const loggedOut = /^\\/auth\\/(?:login|logout)(?:\\/|$)/.test(path)
          || body.includes("log in or sign up")
          || body.includes("log in to get answers");
        if (loggedOut) return "LOGGED_OUT";
        if (document.querySelector("[contenteditable=true]")) return "AUTHENTICATED";
        return "UNKNOWN";
      })()`);

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
            new Promise((_, reject) => setTimeout(() => reject(new Error("open timeout")), 2000)),
          ]);
          candidate = new Cdp(ws);
          await Promise.race([
            candidate.evaluate("true"),
            new Promise((_, reject) => setTimeout(() => reject(new Error("evaluate timeout")), 1500)),
          ]);
          return candidate;
        } catch (error) {
          try { candidate?.close(); } catch {}
          try { ws?.close(); } catch {}
          throw error;
        }
      }, async candidate => {
        /* CONTINUITY_BROWSER_PREFER_AUTHENTICATED_TAB */
        try {
          const state = await Promise.race([
            probeChatgptSessionState(candidate),
            new Promise((_, reject) => setTimeout(() => reject(new Error("session probe timeout")), 2500)),
          ]);
          return state === "AUTHENTICATED";
        } catch {
          return false;
        }
      });
      if (!c) {
        console.log(authTerminal ? "AUTH_TERMINAL" : (authFlow ? "AUTH_FLOW" : "UNKNOWN"));
        process.exit(0);
      }
      try {
        const state = await probeChatgptSessionState(c);
        if (state === "AUTHENTICATED") {
          console.log("AUTHENTICATED");
        } else if (authTerminal) {
          console.log("AUTH_TERMINAL");
        } else if (authFlow) {
          console.log("AUTH_FLOW");
        } else {
          console.log(String(state || "UNKNOWN"));
        }
      } finally {
        c.close();
      }
    ' 2>/dev/null || printf '%s\n' "UNREACHABLE"
}

persist_browser_health() {
  local state="$1"
  case "$state" in
    AUTHENTICATED|LOGGED_OUT|AUTH_FLOW|AUTH_TERMINAL|UNKNOWN|UNREACHABLE) ;;
    *) state="UNKNOWN" ;;
  esac
  python3 "$probe_cache_helper" record --health "$browser_health_file" \
    --base "$cdp_base" --tasks "$task_state_dir" --state "$state"
}

report_browser_state() {
  local guardian_state="$1"
  local session_state="${2:-}"
  if [[ -z "$session_state" ]]; then
    session_state="$(browser_session_state | tail -n 1)"
  fi
  persist_browser_health "$session_state"
  echo "CHATGPT_BROWSER_GUARDIAN=$guardian_state"
  echo "CHATGPT_BROWSER_SESSION=$session_state"
}

validate_browser_session() {
  local healthy_guardian_state="$1"
  local session_state
  session_state="$(browser_session_state | tail -n 1)"
  case "$session_state" in
    AUTHENTICATED)
      report_browser_state "$healthy_guardian_state" "$session_state"
      return 0
      ;;
    AUTH_FLOW)
      report_browser_state "AUTH_PENDING" "$session_state"
      return 0
      ;;
    AUTH_TERMINAL)
      # Authentication reached a terminal page. The browser/CDP runtime is
      # healthy, so keep the unauthenticated health state without turning the
      # periodic guardian unit into a permanent failure loop.
      report_browser_state "QUIESCENT_AUTH_TERMINAL" "$session_state"
      return 0
      ;;
    LOGGED_OUT)
      report_browser_state "DEGRADED_LOGGED_OUT" "$session_state"
      return 0
      ;;
    *)
      report_browser_state "DEGRADED_SESSION" "${session_state:-UNKNOWN}"
      return 1
      ;;
  esac
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

status=0

# Keep the timer and transport liveness checks enabled. Reuse only a negative
# session observation, for a bounded window, when browser/task context agrees.
# No renderer evaluation or account request is made on this path.
if [[ "${CHATGPT_BROWSER_FORCE_SESSION_PROBE:-0}" != 1 ]] && \
  cached_state="$(python3 "$probe_cache_helper" check --health "$browser_health_file" \
    --base "$cdp_base" --tasks "$task_state_dir")"; then
  echo "CHATGPT_BROWSER_GUARDIAN=QUIESCENT_AUTH_CACHE"
  echo "CHATGPT_BROWSER_SESSION=$cached_state"
else
  mapfile -t canonical_pids < <(pgrep -u fredrdp -f "$browser_pattern" || true)

  if cdp_ready; then
    validate_browser_session "HEALTHY" || status=1
  elif [[ "${#canonical_pids[@]}" -gt 0 ]]; then
    if systemctl is-active --quiet "$browser_unit"; then
      sleep 5
      if cdp_ready; then
        validate_browser_session "HEALTHY_AFTER_RECHECK" || status=1
      else
        systemctl restart "$browser_unit"
        if wait_for_cdp; then
          validate_browser_session "RECOVERED_MANAGED_RESTART" || status=1
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
        if ! kill -TERM "$canonical_pid" 2>/dev/null; then
        # A concurrent exit is possible; the bounded absence check below is
        # still authoritative and rejects takeover while the process is live.
        echo "CHATGPT_BROWSER_SIGNAL=NOT_DELIVERED_RECHECK_REQUIRED" >&2
      fi
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
            validate_browser_session "RECOVERED_UNMANAGED_TAKEOVER" || status=1
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
      validate_browser_session "RECOVERED" || status=1
    else
      echo "CHATGPT_BROWSER_GUARDIAN=RECOVERY_FAILED" >&2
      status=1
    fi
  fi
fi

exit "$status"
