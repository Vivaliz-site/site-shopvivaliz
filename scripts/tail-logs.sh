#!/usr/bin/env bash
set -euo pipefail

ROOT="/home/ubuntu/site-shopvivaliz"
MODE="${1:-apache}"

case "$MODE" in
  apache)
    tail -f /var/log/apache2/error.log
    ;;
  watchdog)
    tail -f "$ROOT/logs/watchdog.log"
    ;;
  dev)
    tail -f "$ROOT/logs/dev-agent.log"
    ;;
  *)
    echo "Uso: $0 apache|watchdog|dev"
    exit 2
    ;;
esac
