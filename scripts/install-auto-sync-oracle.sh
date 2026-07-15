#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-/home/ubuntu/site-shopvivaliz}"
chmod +x "$ROOT/scripts/auto-sync-oracle.sh"

( crontab -l 2>/dev/null | grep -v "auto-sync-oracle.sh" ; echo "*/5 * * * * ROOT=$ROOT BASE=main $ROOT/scripts/auto-sync-oracle.sh" ) | crontab -
echo "Auto sync Oracle instalado no cron a cada 5 minutos."
