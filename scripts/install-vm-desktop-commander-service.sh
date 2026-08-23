#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd -P)"
UNIT_SOURCE="$REPO_ROOT/ops/systemd/shopvivaliz-desktop-commander.service"
SUPERVISOR_SOURCE="$REPO_ROOT/scripts/vm-desktop-commander-supervisor.sh"
UNIT_TARGET='/etc/systemd/system/shopvivaliz-desktop-commander.service'
ENV_TARGET='/etc/default/shopvivaliz-desktop-commander'
LIB_DIR='/usr/local/lib/shopvivaliz'
SUPERVISOR_TARGET="$LIB_DIR/vm-desktop-commander-supervisor.sh"
SERVICE='shopvivaliz-desktop-commander.service'
TARGET_USER='ubuntu'

id "$TARGET_USER" >/dev/null 2>&1 || { echo 'ERROR target user missing'; exit 2; }
command -v node >/dev/null 2>&1 || { echo 'ERROR node missing'; exit 3; }
NPX_BIN="$(sudo -u "$TARGET_USER" -H bash -lc 'command -v npx' 2>/dev/null || true)"
[[ -n "$NPX_BIN" && -x "$NPX_BIN" ]] || { echo 'ERROR npx missing for target user'; exit 4; }
[[ -f "$UNIT_SOURCE" ]] || { echo 'ERROR unit template missing'; exit 5; }
[[ -f "$SUPERVISOR_SOURCE" ]] || { echo 'ERROR supervisor missing'; exit 6; }

install -d -m 0755 "$LIB_DIR"
install -m 0755 "$SUPERVISOR_SOURCE" "$SUPERVISOR_TARGET"
install -m 0644 "$UNIT_SOURCE" "$UNIT_TARGET"
NODE_BIN_DIR="$(dirname "$NPX_BIN")"
printf 'NPX_BIN=%s\nPATH=%s:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n' "$NPX_BIN" "$NODE_BIN_DIR" > "$ENV_TARGET"
chmod 0644 "$ENV_TARGET"
systemctl daemon-reload
systemctl enable shopvivaliz-desktop-commander.service
systemctl restart shopvivaliz-desktop-commander.service
sleep 3
printf 'SERVICE_ENABLED=%s\n' "$(systemctl is-enabled "$SERVICE" 2>/dev/null || true)"
printf 'SERVICE_ACTIVE=%s\n' "$(systemctl is-active "$SERVICE" 2>/dev/null || true)"
printf 'SERVICE_USER=%s\n' "$(systemctl show -p User --value "$SERVICE" 2>/dev/null || true)"
printf 'SERVICE_MAINPID=%s\n' "$(systemctl show -p MainPID --value "$SERVICE" 2>/dev/null || true)"
