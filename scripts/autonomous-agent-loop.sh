#!/usr/bin/env bash
set -u

PROJECT_DIR="${SHOPVIVALIZ_PROJECT_DIR:-/home/ubuntu/site-shopvivaliz}"
LOG_FILE="${SHOPVIVALIZ_AGENT_LOG:-$PROJECT_DIR/logs/autonomous-agent.log}"
EXECUTION_LOG_FILE="${SHOPVIVALIZ_AGENT_EXECUTION_LOG:-$PROJECT_DIR/logs/execution/autonomous-cycle.log}"
INTERVAL_SECONDS="${SHOPVIVALIZ_AGENT_INTERVAL_SECONDS:-60}"
LOCK_FILE="${SHOPVIVALIZ_AGENT_LOCK:-/tmp/shopvivaliz-agent.lock}"
STOP_FILE="${SHOPVIVALIZ_AGENT_STOP_FILE:-$PROJECT_DIR/.agent-stop}"

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
  exec 9>"$LOCK_FILE"
  if ! flock -n 9; then
    log "Another shopvivaliz autonomous agent instance is already running."
    exit 0
  fi
else
  LOCK_DIR="${LOCK_FILE}.d"
  if ! mkdir "$LOCK_DIR" 2>/dev/null; then
    log "Another shopvivaliz autonomous agent instance is already running."
    exit 0
  fi
  trap 'rmdir "$LOCK_DIR" 2>/dev/null || true; exit 0' INT TERM EXIT
fi

shutdown_requested=0
trap 'shutdown_requested=1; log "Shutdown signal received; finishing current cycle."' INT TERM

run_cycle() {
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

  if command -v git >/dev/null 2>&1; then
    git status --short || true
  else
    log "WARNING git command not available."
  fi

  if ! command -v python3 >/dev/null 2>&1; then
    log "ERROR python3 is required for the autonomous cycle."
    return 1
  fi

  if [ ! -f "scripts/autonomous-continuous-cycle.py" ]; then
    log "ERROR scripts/autonomous-continuous-cycle.py not found."
    return 1
  fi

  python3 scripts/autonomous-continuous-cycle.py --advance
  cycle_exit="$?"
  log "Autonomous continuous cycle exit_code=$cycle_exit."

  if [ -f "scripts/autonomous-executor.py" ]; then
    python3 scripts/autonomous-executor.py --max-cycles 1 || log "WARNING autonomous executor reported issues."
  else
    log "WARNING scripts/autonomous-executor.py not found."
  fi

  if [ -f "scripts/agent-operations-worker.py" ]; then
    python3 scripts/agent-operations-worker.py || log "WARNING agent operations worker reported issues."
  fi

  if [ -f "scripts/log-health-checker.py" ]; then
    python3 scripts/log-health-checker.py || log "WARNING log health checker reported issues."
  fi

  log "Cycle finished."
  printf '[%s] %s\n' "$(ts)" "Cycle finished." >> "$EXECUTION_LOG_FILE"
  return "$cycle_exit"
}

log "ShopVivaliz autonomous agent started. project=$PROJECT_DIR interval=${INTERVAL_SECONDS}s"

while [ "$shutdown_requested" -eq 0 ]; do
  run_cycle || log "Cycle failed; systemd Restart=always will also recover hard failures."

  slept=0
  while [ "$slept" -lt "$INTERVAL_SECONDS" ] && [ "$shutdown_requested" -eq 0 ]; do
    sleep 5
    slept=$((slept + 5))
  done
done

log "ShopVivaliz autonomous agent stopped."
