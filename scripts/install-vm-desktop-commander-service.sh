#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd -P)"
UNIT_SOURCE="$REPO_ROOT/ops/systemd/shopvivaliz-desktop-commander.service"
SUPERVISOR_SOURCE="$REPO_ROOT/scripts/vm-desktop-commander-supervisor.sh"
UNIT_TARGET='/etc/systemd/system/shopvivaliz-desktop-commander.service'
LIB_DIR='/usr/local/lib/shopvivaliz'
SUPERVISOR_TARGET="$LIB_DIR/vm-desktop-commander-supervisor.sh"
SERVICE='shopvivaliz-desktop-commander.service'
TARGET_USER='ubuntu'

id "$TARGET_USER" >/dev/null 2>&1 || { echo 'ERROR target user missing'; exit 2; }
command -v node >/dev/null 2>&1 || { echo 'ERROR node missing'; exit 3; }
command -v npx >/dev/null 2>&1 || { echo 'ERROR npx missing'; exit 4; }
[[ -f "$UNIT_SOURCE" ]] || { echo 'ERROR unit template missing'; exit 5; }
[[ -f "$SUPERVISOR_SOURCE" ]] || { echo 'ERROR supervisor missing'; exit 6; }

install -d -m 0755 "$LIB_DIR"
install -m 0755 "$SUPERVISOR_SOURCE" "$SUPERVISOR_TARGET"
install -m 0644 "$UNIT_SOURCE" "$UNIT_TARGET"
systemctl daemon-reload
systemctl enable shopvivaliz-desktop-commander.service
systemctl restart shopvivaliz-desktop-commander.service
sleep 3
printf 'SERVICE_ENABLED=%s\n' "$(systemctl is-enabled "$SERVICE" 2>/dev/null || true)"
printf 'SERVICE_ACTIVE=%s\n' "$(systemctl is-active "$SERVICE" 2>/dev/null || true)"
printf 'SERVICE_USER=%s\n' "$(systemctl show -p User --value "$SERVICE" 2>/dev/null || true)"
printf 'SERVICE_MAINPID=%s\n' "$(systemctl show -p MainPID --value "$SERVICE" 2>/dev/null || true)"
