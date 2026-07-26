#!/bin/bash
set -Eeuo pipefail

readonly CURRENT_ROOT="/home/ubuntu/shopvivaliz-deploy/current"
readonly SHARED_ROOT="/home/ubuntu/shopvivaliz-deploy/shared"
readonly LOG_DIR="$SHARED_ROOT/logs"
readonly STATE_DIR="$SHARED_ROOT/storage/autonomous"
readonly CYCLE_LOG="$LOG_DIR/autonomous-worker.log"
readonly SALES_STAMP="$STATE_DIR/last-sales-ranking"

mkdir -p "$LOG_DIR" "$STATE_DIR"

log() {
  printf '[%s] %s\n' "$(date -u +'%Y-%m-%d %H:%M:%S UTC')" "$*" >> "$CYCLE_LOG"
}

run_http_check() {
  local path="$1"
  local expected="$2"
  local output
  output="$(mktemp)"
  if ! curl --fail --silent --show-error --max-time 25 \
      --header 'Cache-Control: no-cache' \
      "https://shopvivaliz.com.br$path" > "$output"; then
    rm -f -- "$output"
    log "ERROR http_check path=$path"
    return 1
  fi
  if ! grep -q -- "$expected" "$output"; then
    rm -f -- "$output"
    log "ERROR content_check path=$path expected=$expected"
    return 1
  fi
  rm -f -- "$output"
}

run_sales_ranking_if_due() {
  local now last age
  now="$(date +%s)"
  last=0
  if [ -f "$SALES_STAMP" ]; then
    last="$(cat "$SALES_STAMP")"
  fi
  age=$((now - last))
  if [ "$age" -lt 21600 ]; then
    return 0
  fi

  log "sales_ranking start"
  python3 "$CURRENT_ROOT/scripts/tiny_sales_report.py" >> "$CYCLE_LOG" 2>&1
  printf '%s\n' "$now" > "$SALES_STAMP"
  log "sales_ranking complete"
}

run_cycle() {
  log "cycle start release=$(basename "$(readlink -f "$CURRENT_ROOT")")"

  php "$CURRENT_ROOT/api/blog/publish-scheduled.php" >> "$CYCLE_LOG" 2>&1
  php "$CURRENT_ROOT/api/stock-alerts/process.php" >> "$CYCLE_LOG" 2>&1
  php "$CURRENT_ROOT/api/agent/cron-dispatcher.php" report >> "$CYCLE_LOG" 2>&1
  run_sales_ranking_if_due

  run_http_check "/" "<html"
  run_http_check "/blog/" "Central de Conhecimento"
  run_http_check "/sitemap.php" "<urlset"
  run_http_check "/api/catalog/products.php?limit=1" '"ok":true'

  log "cycle complete"
}

while true; do
  run_cycle
  sleep 300
done
