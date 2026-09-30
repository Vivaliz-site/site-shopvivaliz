#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="${1:-/home/ubuntu/shopvivaliz-deploy/current}"
DEPLOY_ROOT="/home/ubuntu/shopvivaliz-deploy"
SHARED="$DEPLOY_ROOT/shared"
SERVICE="shopvivaliz-shopee-logistics-worker.service"
TIMER="shopvivaliz-shopee-logistics-worker.timer"
SOURCE_SERVICE="$ROOT/deploy/systemd/$SERVICE"
SOURCE_TIMER="$ROOT/deploy/systemd/$TIMER"
TARGET_DIR="/etc/systemd/system"

if [ "$(id -u)" -ne 0 ]; then
  echo "install_shopee_logistics_worker_requires_root" >&2
  exit 2
fi
for file in "$SOURCE_SERVICE" "$SOURCE_TIMER" "$ROOT/scripts/shopee_logistics_worker.py" "$ROOT/scripts/utils/shopee_client.py"; do
  test -f "$file" || { echo "missing_runtime_file=$file" >&2; exit 3; }
done

test -f "$SHARED/.env" || { echo "missing_runtime_file=$SHARED/.env" >&2; exit 4; }
install -d -o ubuntu -g www-data -m 2770 "$SHARED/storage/shopee-logistics-worker" "$SHARED/logs"
install -o root -g root -m 0644 "$SOURCE_SERVICE" "$TARGET_DIR/$SERVICE"
install -o root -g root -m 0644 "$SOURCE_TIMER" "$TARGET_DIR/$TIMER"
systemd-analyze verify "$TARGET_DIR/$SERVICE" "$TARGET_DIR/$TIMER"
systemctl daemon-reload
systemctl enable --now "$TIMER"
systemctl is-active --quiet "$TIMER"
systemctl is-enabled --quiet "$TIMER"
echo "SHOPEE_LOGISTICS_WORKER_INSTALL=PASS"
