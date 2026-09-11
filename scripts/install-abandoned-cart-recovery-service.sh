#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-/home/ubuntu/shopvivaliz-deploy/current}"
DEPLOY_ROOT="/home/ubuntu/shopvivaliz-deploy"
SHARED="$DEPLOY_ROOT/shared"
SERVICE="shopvivaliz-abandoned-cart-recovery.service"
TIMER="shopvivaliz-abandoned-cart-recovery.timer"
SOURCE_SERVICE="$ROOT/deploy/systemd/$SERVICE"
SOURCE_TIMER="$ROOT/deploy/systemd/$TIMER"
TARGET_DIR="/etc/systemd/system"

if [ "$(id -u)" -ne 0 ]; then
  echo "install_abandoned_cart_recovery_requires_root" >&2
  exit 2
fi
for file in "$SOURCE_SERVICE" "$SOURCE_TIMER" "$ROOT/scripts/send-abandoned-cart-emails.php"; do
  test -f "$file" || { echo "missing_runtime_file=$file" >&2; exit 3; }
done

install -d -o ubuntu -g www-data -m 0770 "$SHARED/locks"
install -o root -g root -m 0644 "$SOURCE_SERVICE" "$TARGET_DIR/$SERVICE"
install -o root -g root -m 0644 "$SOURCE_TIMER" "$TARGET_DIR/$TIMER"
systemd-analyze verify "$TARGET_DIR/$SERVICE" "$TARGET_DIR/$TIMER"
systemctl daemon-reload
systemctl enable --now "$TIMER"
systemctl is-active --quiet "$TIMER"
systemctl is-enabled --quiet "$TIMER"
echo "ABANDONED_CART_RECOVERY_INSTALL=PASS"
