#!/bin/bash
set -e

PROJECT_DIR="/home/ubuntu/site-shopvivaliz"
LOG_DIR="${PROJECT_DIR}/logs"
PID_FILE="${LOG_DIR}/.orchestrator.pid"

mkdir -p "${LOG_DIR}"
echo $$ > "${PID_FILE}"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ORCHESTRATOR] $1" | tee -a "${LOG_DIR}/orchestrator.log"
}

cleanup() {
    log "Shutting down gracefully..."
    rm -f "${PID_FILE}"
    exit 0
}

trap cleanup SIGTERM SIGINT

log "Starting Autonomous Orchestrator Loop"

while true; do
    CYCLE_START=$(date +%s)
    
    log "Running orchestration cycle..."
    
    # Run director orchestration
    php "${PROJECT_DIR}/api/autonomous/project-director.php" 2>&1 >> "${LOG_DIR}/orchestrator.log" || true
    
    # Check productivity
    php -r "
    require '${PROJECT_DIR}/api/autonomous/productivity-tracker.php';
    echo ProductivityTracker::generateSummary() . PHP_EOL;
    " >> "${LOG_DIR}/orchestrator.log" 2>&1 || true
    
    CYCLE_END=$(date +%s)
    CYCLE_TIME=$((CYCLE_END - CYCLE_START))
    
    log "Cycle complete in ${CYCLE_TIME}s"
    
    INTERVAL=${SHOPVIVALIZ_AGENT_INTERVAL_SECONDS:-60}
    SLEEP_TIME=$((INTERVAL - CYCLE_TIME))
    if [ $SLEEP_TIME -lt 1 ]; then SLEEP_TIME=1; fi
    
    sleep $SLEEP_TIME
done
