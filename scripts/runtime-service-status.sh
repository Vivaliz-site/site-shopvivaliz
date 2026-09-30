#!/usr/bin/env bash
set -Eeuo pipefail

role="${1:-}"
health="ok"

degrade() {
  health="degraded"
}

attention() {
  if [[ "$health" == "ok" ]]; then
    health="attention"
  fi
}

unit_value() {
  local unit="$1"
  local prop="$2"
  local value
  if value="$(systemctl show "$unit" -p "$prop" --value 2>/dev/null)"; then
    :
  else
    value=""
  fi
  if [[ -z "$value" && "$prop" == "LoadState" ]]; then
    value="not-found"
  fi
  printf '%s' "$value"
}

report_required_active() {
  local unit="$1"
  local load active sub enabled
  load="$(unit_value "$unit" LoadState)"
  active="$(unit_value "$unit" ActiveState)"
  sub="$(unit_value "$unit" SubState)"
  if enabled="$(systemctl is-enabled "$unit" 2>/dev/null)"; then
    :
  else
    enabled="unknown"
  fi
  printf 'UNIT=%s LOAD=%s ACTIVE=%s SUB=%s ENABLED=%s EXPECTED=active\n' \
    "$unit" "$load" "$active" "$sub" "${enabled:-unknown}"
  if [[ "$load" != "loaded" || "$active" != "active" ]]; then
    degrade
  fi
}

report_disk() {
  local use_pct disk_health
  use_pct="$(df -P / | awk 'NR==2 {gsub(/%/,"",$5); print $5}')"
  disk_health="ok"
  if (( use_pct >= 95 )); then
    disk_health="critical"
    degrade
  elif (( use_pct >= 85 )); then
    disk_health="warning"
    attention
  fi
  printf 'DISK_USE_PCT=%s DISK_HEALTH=%s\n' "$use_pct" "$disk_health"
}

last_marker_time() {
  local log_file="$1"
  local marker="$2"
  local candidate
  if [[ ! -f "$log_file" ]]; then
    printf 'NONE'
    return 0
  fi
  candidate="$(
    { grep -F "$marker" "$log_file" 2>/dev/null || true; } \
      | tail -n 1 \
      | sed -n 's/^\[\([^]]*\)\].*/\1/p'
  )"
  if [[ "$candidate" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]]; then
    printf '%s' "$candidate"
  else
    printf 'NONE'
  fi
}

last_json_number() {
  local log_file="$1"
  local key="$2"
  if [[ ! -f "$log_file" ]]; then
    printf 'NA'
    return 0
  fi
  awk -v needle="\""$key"\"" '
    index($0, needle) {
      line=$0
      sub(/^.*:[[:space:]]*/, "", line)
      sub(/[^0-9].*$/, "", line)
      if (line ~ /^[0-9]+$/) value=line
    }
    END { print (value == "" ? "NA" : value) }
  ' "$log_file"
}

report_site_continuity() {
  local root runtime_dir agent_log stop_file
  root="/home/ubuntu/shopvivaliz-deploy/current"
  runtime_dir="/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state"
  agent_log="$root/logs/autonomous-agent.log"
  stop_file="$root/.agent-stop"

  if [[ -L "$root" || -d "$root" ]]; then
    printf 'ACTIVE_RELEASE_PATH=%s\n' "$(readlink -f "$root" 2>/dev/null || printf '%s' "$root")"
  else
    echo "ACTIVE_RELEASE_PATH=missing"
    degrade
  fi

  if [[ -f "$stop_file" ]]; then
    echo "AGENT_STOP_FILE_PRESENT=true"
    degrade
  else
    echo "AGENT_STOP_FILE_PRESENT=false"
  fi

  if [[ -f "$agent_log" ]]; then
    echo "AGENT_LOG_PRESENT=true"
  else
    echo "AGENT_LOG_PRESENT=false"
    degrade
  fi

  local cycle_started cycle_finished advance_error watchdog_done dispatcher_done dispatcher_warn resume_no_progress
  cycle_started="$(last_marker_time "$agent_log" "Cycle started.")"
  cycle_finished="$(last_marker_time "$agent_log" "Cycle finished.")"
  advance_error="$(last_marker_time "$agent_log" "ERROR autonomous continuous cycle failed.")"
  watchdog_done="$(last_marker_time "$agent_log" "Task continuation watchdog completed.")"
  dispatcher_done="$(last_marker_time "$agent_log" "ChatGPT continuity nudge dispatcher completed.")"
  dispatcher_warn="$(last_marker_time "$agent_log" "WARN ChatGPT continuity nudge dispatcher did not complete cleanly; continuing.")"
  resume_no_progress="$(last_marker_time "$agent_log" "Detached task resume attempt made no durable progress; checkpoint remains RUNNING.")"

  printf 'AGENT_LAST_CYCLE_STARTED_AT=%s\n' "$cycle_started"
  printf 'AGENT_LAST_CYCLE_FINISHED_AT=%s\n' "$cycle_finished"
  printf 'AGENT_LAST_AUTONOMOUS_CYCLE_ERROR_AT=%s\n' "$advance_error"
  printf 'AGENT_LAST_WATCHDOG_COMPLETED_AT=%s\n' "$watchdog_done"
  printf 'AGENT_LAST_CHATGPT_DISPATCHER_COMPLETED_AT=%s\n' "$dispatcher_done"
  printf 'AGENT_LAST_CHATGPT_DISPATCHER_WARN_AT=%s\n' "$dispatcher_warn"
  printf 'AGENT_LAST_DETACHED_RESUME_NO_PROGRESS_AT=%s\n' "$resume_no_progress"

  local blocked_by_prestep=false
  if [[ "$advance_error" != "NONE" ]]; then
    if [[ "$dispatcher_done" == "NONE" || "$advance_error" > "$dispatcher_done" ]]; then
      blocked_by_prestep=true
      attention
    fi
  fi
  printf 'AGENT_CONTINUITY_PATH_BLOCKED_BY_PRESTEP=%s\n' "$blocked_by_prestep"

  printf 'AGENT_LAST_DISPATCH_SCANNED=%s\n' "$(last_json_number "$agent_log" scanned)"
  printf 'AGENT_LAST_DISPATCH_ELIGIBLE=%s\n' "$(last_json_number "$agent_log" eligible)"
  printf 'AGENT_LAST_DISPATCHED=%s\n' "$(last_json_number "$agent_log" dispatched)"
  printf 'AGENT_LAST_DISPATCH_SKIPPED_NO_TOKEN=%s\n' "$(last_json_number "$agent_log" skipped_no_token)"
  printf 'AGENT_LAST_DISPATCH_SKIPPED_STALE=%s\n' "$(last_json_number "$agent_log" skipped_stale_checkpoint)"
  printf 'AGENT_LAST_DISPATCH_RETRY_ATTEMPTED=%s\n' "$(last_json_number "$agent_log" retry_attempted)"
  printf 'AGENT_LAST_DISPATCH_SKIPPED_ATTEMPT_LIMIT=%s\n' "$(last_json_number "$agent_log" skipped_attempt_limit)"

  local queue_json
  queue_json="$(mktemp)"
  if python3 - "$runtime_dir" "$root/scripts" >"$queue_json" <<'PY'
import json
import pathlib
import sys

runtime_dir = pathlib.Path(sys.argv[1])
scripts_dir = pathlib.Path(sys.argv[2])
sys.path.insert(0, str(scripts_dir))
import task_resume_queue

summary = task_resume_queue.certify_queue(runtime_dir)
print(json.dumps({
    "certified": bool(summary.get("certified")),
    "raw_rows": int(summary.get("raw_rows", 0)),
    "actionable_rows": int(summary.get("actionable_rows", 0)),
    "nonactionable_rows": int(summary.get("nonactionable_rows", 0)),
}, sort_keys=True))
PY
  then
    python3 - "$queue_json" <<'PY'
import json
import sys
row = json.load(open(sys.argv[1], encoding="utf-8"))
print("CHATGPT_CONTINUITY_QUEUE_CERTIFIED=" + str(bool(row["certified"])).lower())
print("CHATGPT_CONTINUITY_QUEUE_RAW_ROWS=" + str(row["raw_rows"]))
print("CHATGPT_CONTINUITY_QUEUE_ACTIONABLE_ROWS=" + str(row["actionable_rows"]))
print("CHATGPT_CONTINUITY_QUEUE_NONACTIONABLE_ROWS=" + str(row["nonactionable_rows"]))
PY
  else
    echo "CHATGPT_CONTINUITY_QUEUE_CERTIFIED=false"
    echo "CHATGPT_CONTINUITY_QUEUE_RAW_ROWS=NA"
    echo "CHATGPT_CONTINUITY_QUEUE_ACTIONABLE_ROWS=NA"
    echo "CHATGPT_CONTINUITY_QUEUE_NONACTIONABLE_ROWS=NA"
    attention
  fi
  rm -f "$queue_json"

  local ledger="$runtime_dir/_chatgpt-continuity-nudges.jsonl"
  if [[ -f "$ledger" ]]; then
    echo "CHATGPT_CONTINUITY_NUDGE_LEDGER_PRESENT=true"
    printf 'CHATGPT_CONTINUITY_NUDGE_LEDGER_MTIME=%s\n' "$(stat -c %Y "$ledger" 2>/dev/null || printf 'NA')"
  else
    echo "CHATGPT_CONTINUITY_NUDGE_LEDGER_PRESENT=false"
    echo "CHATGPT_CONTINUITY_NUDGE_LEDGER_MTIME=NA"
  fi

  local requests="$runtime_dir/_resume-requests.jsonl"
  if [[ -f "$requests" ]]; then
    echo "CHATGPT_CONTINUITY_REQUESTS_FILE_PRESENT=true"
    printf 'CHATGPT_CONTINUITY_REQUESTS_FILE_MTIME=%s\n' "$(stat -c %Y "$requests" 2>/dev/null || printf 'NA')"
  else
    echo "CHATGPT_CONTINUITY_REQUESTS_FILE_PRESENT=false"
    echo "CHATGPT_CONTINUITY_REQUESTS_FILE_MTIME=NA"
  fi
}

report_backend_continuity() {
  local runtime_dir user_bus
  runtime_dir="/run/user/$(id -u)"
  user_bus="unix:path=$runtime_dir/bus"

  local user_active=false
  if XDG_RUNTIME_DIR="$runtime_dir" DBUS_SESSION_BUS_ADDRESS="$user_bus" \
      systemctl --user is-active --quiet shopvivaliz-chatgpt-continuity.service 2>/dev/null; then
    user_active=true
  else
    degrade
  fi
  printf 'CHATGPT_CONTINUITY_BACKEND_WORKER_ACTIVE=%s\n' "$user_active"

  local cdp_reachable=false
  if curl -fsS --connect-timeout 2 --max-time 5 http://127.0.0.1:9555/json/version >/dev/null 2>&1; then
    cdp_reachable=true
  else
    degrade
  fi
  printf 'CHATGPT_CONTINUITY_CDP_REACHABLE=%s\n' "$cdp_reachable"
}

echo "RUNTIME_STATUS_BEGIN"
echo "HOST=$(hostname)"
echo "ROLE=$role"

# Policy scope is intentionally narrow: only services required by the current
# web/runtime and ChatGPT continuity architecture are health gates here.
# Legacy remote-control stacks and unrelated optional services are omitted.
case "$role" in
  site)
    report_required_active apache2.service
    report_required_active shopvivaliz-queue-worker.service
    report_required_active shopvivaliz-token-renewer.service
    report_required_active shopvivaliz-shopee-token-renewer.service
    report_required_active shopvivaliz-agent.service
    report_site_continuity
    ;;
  backend)
    report_backend_continuity
    ;;
  *)
    echo "ERROR=unsupported-role"
    echo "RUNTIME_STATUS_END"
    exit 2
    ;;
esac

report_disk
echo "RUNTIME_HEALTH=$health"
echo "RUNTIME_STATUS_END"

if [[ "$health" == "degraded" ]]; then
  exit 1
fi
