#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="${SHOPVIVALIZ_PROJECT_DIR:-/home/ubuntu/shopvivaliz-deploy/current}"
LOG_FILE="${SHOPVIVALIZ_AGENT_LOG:-$PROJECT_DIR/logs/autonomous-agent.log}"
EXECUTION_LOG_FILE="${SHOPVIVALIZ_AGENT_EXECUTION_LOG:-$PROJECT_DIR/logs/execution/autonomous-cycle.log}"
INTERVAL_SECONDS="${SHOPVIVALIZ_AGENT_INTERVAL_SECONDS:-60}"
LOCK_FILE="${SHOPVIVALIZ_AGENT_LOCK:-/tmp/shopvivaliz-agent.lock}"
STOP_FILE="${SHOPVIVALIZ_AGENT_STOP_FILE:-$PROJECT_DIR/.agent-stop}"

if ! START_SCRIPT_REALPATH="$(readlink -f "$0" 2>/dev/null)"; then
  printf '[%s] ERROR cannot resolve agent script path: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$0" >&2
  exit 1
fi
SHOPVIVALIZ_AGENT_REEXEC="${SHOPVIVALIZ_AGENT_REEXEC:-0}"
SHOPVIVALIZ_AGENT_LOCK_INHERITED="${SHOPVIVALIZ_AGENT_LOCK_INHERITED:-}"

ts() {
  date -u +"%Y-%m-%dT%H:%M:%SZ"
}

log() {
  printf '[%s] %s\n' "$(ts)" "$*"
}

if [ ! -d "$PROJECT_DIR" ]; then
  mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
  printf '[%s] ERROR project dir not found: %s\n' "$(ts)" "$PROJECT_DIR" >> "$LOG_FILE"
  exit 1
fi

mkdir -p "$PROJECT_DIR/logs" "$PROJECT_DIR/logs/execution"
touch "$LOG_FILE"
touch "$EXECUTION_LOG_FILE"
cd "$PROJECT_DIR" || exit 1
exec >> "$LOG_FILE" 2>&1

if command -v flock >/dev/null 2>&1; then
  inherited_lock_ok=0
  if [ "$SHOPVIVALIZ_AGENT_LOCK_INHERITED" = "fd9" ] && [ -e "/proc/self/fd/9" ]; then
    inherited_target=""
    lock_target=""
    if inherited_target="$(readlink -f "/proc/self/fd/9" 2>/dev/null)" \
      && lock_target="$(readlink -f "$LOCK_FILE" 2>/dev/null)" \
      && [ -n "$inherited_target" ] \
      && [ "$inherited_target" = "$lock_target" ]; then
      inherited_lock_ok=1
    fi
  fi
  if [ "$inherited_lock_ok" -ne 1 ]; then
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
      log "Another shopvivaliz autonomous agent instance is already running."
      exit 75
    fi
  fi
else
  LOCK_DIR="${LOCK_FILE}.d"
  if [ "$SHOPVIVALIZ_AGENT_LOCK_INHERITED" != "mkdir" ]; then
    if ! mkdir "$LOCK_DIR" 2>/dev/null; then
      log "Another shopvivaliz autonomous agent instance is already running."
      exit 75
    fi
  elif [ ! -d "$LOCK_DIR" ]; then
    log "Inherited lock directory missing during self-refresh."
    exit 1
  fi
  trap 'rmdir "$LOCK_DIR" 2>/dev/null || true; exit 0' INT TERM EXIT
fi
unset SHOPVIVALIZ_AGENT_LOCK_INHERITED

shutdown_requested=0
trap 'shutdown_requested=1; log "Shutdown signal received; finishing current cycle."' INT TERM

run_cycle() {
  # Re-resolve the mutable current symlink every cycle. A long-lived systemd
  # process must not remain pinned to the release that was active at startup.
  if ! CURRENT_SCRIPT_REALPATH="$(readlink -f "$PROJECT_DIR/scripts/autonomous-agent-loop.sh" 2>/dev/null)"; then
    log "ERROR cannot resolve current autonomous-agent-loop.sh."
    return 1
  fi
  if [ "$CURRENT_SCRIPT_REALPATH" != "$START_SCRIPT_REALPATH" ]; then
    log "Current release changed; self-refreshing autonomous agent."
    export SHOPVIVALIZ_AGENT_REEXEC=1
    if command -v flock >/dev/null 2>&1 && [ -e "/proc/self/fd/9" ]; then
      export SHOPVIVALIZ_AGENT_LOCK_INHERITED=fd9
    else
      export SHOPVIVALIZ_AGENT_LOCK_INHERITED=mkdir
    fi
    exec /bin/bash "$PROJECT_DIR/scripts/autonomous-agent-loop.sh"
  fi

  if ! cd "$PROJECT_DIR"; then
    log "ERROR project dir unavailable during cycle: $PROJECT_DIR"
    return 1
  fi

  log "Cycle started."
  printf '[%s] %s\n' "$(ts)" "Cycle started." >> "$EXECUTION_LOG_FILE"
  log "Governance active: no price changes, no campaign publishing, no budget increases, no deploys, no financial actions."
  printf '[%s] %s\n' "$(ts)" "Governance active: no price changes, no campaign publishing, no budget increases, no deploys, no financial actions." >> "$EXECUTION_LOG_FILE"

  if [ -f "$STOP_FILE" ]; then
    log "Stop file found at $STOP_FILE; cycle skipped."
    return 0
  fi

  if [ ! -f "tasks-queue.json" ]; then
    log "WARNING tasks-queue.json not found."
  fi

  docs_count="$(find docs -type f 2>/dev/null | wc -l | tr -d ' ')"
  reports_count="$(find logs -maxdepth 1 -type f 2>/dev/null | wc -l | tr -d ' ')"
  log "Context snapshot: docs_files=${docs_count:-0} log_reports=${reports_count:-0}."

  if [ -d "$PROJECT_DIR/.git" ] && command -v git >/dev/null 2>&1; then
    git status --short
  else
    log "Immutable release detected; Git status is checked by the deployment clone."
  fi

  if ! command -v python3 >/dev/null 2>&1; then
    log "ERROR python3 is required for the autonomous cycle."
    return 1
  fi

  if [ ! -f "scripts/autonomous-continuous-cycle.py" ]; then
    log "ERROR scripts/autonomous-continuous-cycle.py not found."
    return 1
  fi

  if ! python3 scripts/autonomous-continuous-cycle.py --advance; then
    log "ERROR autonomous continuous cycle failed."
    return 1
  fi
  log "Autonomous continuous cycle completed."

  if [ -f "scripts/task_continuation_watchdog.py" ]; then
    if ! python3 scripts/task_continuation_watchdog.py --stale-seconds "${SHOPVIVALIZ_TASK_STALE_SECONDS:-120}"; then
      log "ERROR task continuation watchdog failed."
      return 1
    fi
    log "Task continuation watchdog completed."
  fi

  if [ -f "scripts/task_resume_dispatcher.py" ]; then
    if python3 scripts/task_resume_dispatcher.py \
      --timeout-seconds "${SHOPVIVALIZ_RESUME_TIMEOUT_SECONDS:-900}" \
      --max-requests "${SHOPVIVALIZ_RESUME_MAX_REQUESTS:-1}" \
      --retry-after-seconds "${SHOPVIVALIZ_RESUME_RETRY_AFTER_SECONDS:-900}"; then
      log "Detached task resume dispatcher completed."
    else
      resume_rc=$?
      if [ "$resume_rc" -eq 75 ]; then
        log "Detached task resume attempt made no durable progress; checkpoint remains RUNNING."
      else
        log "ERROR detached task resume dispatcher failed with rc=$resume_rc."
        return 1
      fi
    fi
  fi

  if [ -f "scripts/agent-operations-worker.py" ]; then
    if ! python3 scripts/agent-operations-worker.py; then
      log "ERROR agent operations worker failed."
      return 1
    fi
  fi

  if [ -f "scripts/log-health-checker.py" ]; then
    if ! python3 scripts/log-health-checker.py; then
      log "ERROR log health checker failed."
      return 1
    fi
  fi

  log "Cycle finished."
  printf '[%s] %s\n' "$(ts)" "Cycle finished." >> "$EXECUTION_LOG_FILE"
  return 0
}

if [ "$SHOPVIVALIZ_AGENT_REEXEC" = "1" ]; then
  log "ShopVivaliz autonomous agent self-refresh completed. script=$START_SCRIPT_REALPATH"
else
  log "ShopVivaliz autonomous agent started. project=$PROJECT_DIR interval=${INTERVAL_SECONDS}s"
fi
unset SHOPVIVALIZ_AGENT_REEXEC

while [ "$shutdown_requested" -eq 0 ]; do
  run_cycle || log "Cycle failed; systemd Restart=always will also recover hard failures."

  slept=0
  while [ "$slept" -lt "$INTERVAL_SECONDS" ] && [ "$shutdown_requested" -eq 0 ]; do
    sleep 5
    slept=$((slept + 5))
  done
done

log "ShopVivaliz autonomous agent stopped."
