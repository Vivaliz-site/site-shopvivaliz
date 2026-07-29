#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${SHOPVIVALIZ_PROJECT_DIR:-/home/ubuntu/shopvivaliz-deploy/current}"
PHP_BIN="${PHP_BIN:-php}"
CRON_FILE="/etc/cron.d/shopvivaliz-blog-publish"
LOG_DIR="$PROJECT_DIR/logs"
LOG_FILE="$LOG_DIR/blog-publish-scheduled.log"

if [ ! -f "$PROJECT_DIR/api/blog/publish-scheduled.php" ]; then
  echo "Arquivo nao encontrado: $PROJECT_DIR/api/blog/publish-scheduled.php" >&2
  exit 1
fi

mkdir -p "$LOG_DIR"

cat > "$CRON_FILE" <<CRON
# ShopVivaliz - publica automaticamente artigos do blog agendados.
# Roda a cada 5 minutos; o script PHP publica apenas itens vencidos.
*/5 * * * * www-data cd $PROJECT_DIR && $PHP_BIN api/blog/publish-scheduled.php >> $LOG_FILE 2>&1
CRON

chmod 0644 "$CRON_FILE"

if command -v systemctl >/dev/null 2>&1; then
  systemctl reload cron 2>/dev/null || systemctl reload crond 2>/dev/null || true
fi

echo "Cron instalado em $CRON_FILE"
echo "Log: $LOG_FILE"
