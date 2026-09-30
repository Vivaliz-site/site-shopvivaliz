#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="${1:-/home/ubuntu/shopvivaliz-deploy/current}"
DEPLOY_ROOT="/home/ubuntu/shopvivaliz-deploy"
SHARED="$DEPLOY_ROOT/shared"
SERVICE="shopvivaliz-shopee-logistics-worker.service"
TIMER="shopvivaliz-shopee-logistics-worker.timer"
WATCHDOG_SERVICE="shopvivaliz-shopee-logistics-watchdog.service"
WATCHDOG_TIMER="shopvivaliz-shopee-logistics-watchdog.timer"
SOURCE_SERVICE="$ROOT/deploy/systemd/$SERVICE"
SOURCE_TIMER="$ROOT/deploy/systemd/$TIMER"
SOURCE_WATCHDOG_SERVICE="$ROOT/deploy/systemd/$WATCHDOG_SERVICE"
SOURCE_WATCHDOG_TIMER="$ROOT/deploy/systemd/$WATCHDOG_TIMER"
TARGET_DIR="/etc/systemd/system"

if [ "$(id -u)" -ne 0 ]; then
  echo "install_shopee_logistics_worker_requires_root" >&2
  exit 2
fi
for file in "$SOURCE_SERVICE" "$SOURCE_TIMER" "$SOURCE_WATCHDOG_SERVICE" "$SOURCE_WATCHDOG_TIMER" \
  "$ROOT/scripts/shopee_logistics_worker.py" "$ROOT/scripts/shopee_logistics_watchdog.py" "$ROOT/scripts/utils/shopee_client.py"; do
  test -f "$file" || { echo "missing_runtime_file=$file" >&2; exit 3; }
done

test -f "$SHARED/.env" || { echo "missing_runtime_file=$SHARED/.env" >&2; exit 4; }
install -d -o ubuntu -g www-data -m 2770 "$SHARED/storage/shopee-logistics-worker" "$SHARED/logs"
install -d -o root -g www-data -m 2770 "$SHARED/storage/shopee-logistics-watchdog"
install -o root -g root -m 0644 "$SOURCE_SERVICE" "$TARGET_DIR/$SERVICE"
install -o root -g root -m 0644 "$SOURCE_TIMER" "$TARGET_DIR/$TIMER"
install -o root -g root -m 0644 "$SOURCE_WATCHDOG_SERVICE" "$TARGET_DIR/$WATCHDOG_SERVICE"
install -o root -g root -m 0644 "$SOURCE_WATCHDOG_TIMER" "$TARGET_DIR/$WATCHDOG_TIMER"
systemd-analyze verify "$TARGET_DIR/$SERVICE" "$TARGET_DIR/$TIMER" "$TARGET_DIR/$WATCHDOG_SERVICE" "$TARGET_DIR/$WATCHDOG_TIMER"
systemctl daemon-reload
systemctl enable --now "$TIMER" "$WATCHDOG_TIMER"
systemctl is-active --quiet "$TIMER"
systemctl is-enabled --quiet "$TIMER"
systemctl is-active --quiet "$WATCHDOG_TIMER"
systemctl is-enabled --quiet "$WATCHDOG_TIMER"
echo "SHOPEE_LOGISTICS_WORKER_INSTALL=PASS"
echo "SHOPEE_LOGISTICS_WATCHDOG_INSTALL=PASS"
