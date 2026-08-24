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
LEGACY_SERVICE='desktop-commander.service'
TARGET_USER='ubuntu'

if ! id "$TARGET_USER" >/dev/null 2>&1; then echo 'ERROR target user missing'; exit 2; fi
if NODE_BIN="$(sudo -u "$TARGET_USER" -H bash -lc 'command -v node' 2>/dev/null)"; then :; else NODE_BIN=''; fi
if NPX_BIN="$(sudo -u "$TARGET_USER" -H bash -lc 'command -v npx' 2>/dev/null)"; then :; else NPX_BIN=''; fi
if [[ -z "$NODE_BIN" || ! -x "$NODE_BIN" ]]; then echo 'ERROR node missing for target user'; exit 3; fi
if [[ -z "$NPX_BIN" || ! -x "$NPX_BIN" ]]; then echo 'ERROR npx missing for target user'; exit 4; fi
if [[ ! -f "$UNIT_SOURCE" ]]; then echo 'ERROR unit template missing'; exit 5; fi
if [[ ! -f "$SUPERVISOR_SOURCE" ]]; then echo 'ERROR supervisor missing'; exit 6; fi

install -d -m 0755 "$LIB_DIR"
install -m 0755 "$SUPERVISOR_SOURCE" "$SUPERVISOR_TARGET"
install -m 0644 "$UNIT_SOURCE" "$UNIT_TARGET"
NODE_BIN_DIR="$(dirname "$NODE_BIN")"
printf 'NODE_BIN=%s\nNPX_BIN=%s\nPATH=%s:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n' "$NODE_BIN" "$NPX_BIN" "$NODE_BIN_DIR" > "$ENV_TARGET"
chmod 0644 "$ENV_TARGET"

systemctl daemon-reload
systemctl enable "$SERVICE"
systemctl restart "$SERVICE"
sleep 5
if SERVICE_ENABLED="$(systemctl is-enabled "$SERVICE" 2>/dev/null)"; then :; else SERVICE_ENABLED='unknown'; fi
if SERVICE_ACTIVE="$(systemctl is-active "$SERVICE" 2>/dev/null)"; then :; else SERVICE_ACTIVE='unknown'; fi
if SERVICE_USER="$(systemctl show -p User --value "$SERVICE" 2>/dev/null)"; then :; else SERVICE_USER='unknown'; fi
if SERVICE_MAINPID="$(systemctl show -p MainPID --value "$SERVICE" 2>/dev/null)"; then :; else SERVICE_MAINPID='0'; fi
printf 'SERVICE_ENABLED=%s\n' "$SERVICE_ENABLED"
printf 'SERVICE_ACTIVE=%s\n' "$SERVICE_ACTIVE"
printf 'SERVICE_USER=%s\n' "$SERVICE_USER"
printf 'SERVICE_MAINPID=%s\n' "$SERVICE_MAINPID"
[[ "$SERVICE_ENABLED" == 'enabled' ]]
[[ "$SERVICE_ACTIVE" == 'active' ]]
[[ "$SERVICE_USER" == "$TARGET_USER" ]]
[[ "$SERVICE_MAINPID" =~ ^[0-9]+$ && "$SERVICE_MAINPID" -gt 1 ]]
[[ -f "/home/$TARGET_USER/.desktop-commander-device/device.json" ]]

if systemctl disable --now "$LEGACY_SERVICE" >/dev/null 2>&1; then
  LEGACY_SERVICE_DISABLED=true
else
  LEGACY_SERVICE_DISABLED=true
fi
sleep 3
if LEGACY_SERVICE_ENABLED="$(systemctl is-enabled "$LEGACY_SERVICE" 2>/dev/null)"; then :; else LEGACY_SERVICE_ENABLED='not-enabled'; fi
printf 'LEGACY_SERVICE_DISABLED=%s\n' "$LEGACY_SERVICE_DISABLED"
printf 'LEGACY_SERVICE_ENABLED=%s\n' "$LEGACY_SERVICE_ENABLED"
[[ "$LEGACY_SERVICE_ENABLED" != 'enabled' ]]

REMOTE_LAUNCHERS="$(pgrep -af '@wonderwhy-er/desktop-commander@.*remote' 2>/dev/null || :)"
CANONICAL_REMOTE_COUNT="$(printf '%s\n' "$REMOTE_LAUNCHERS" | grep -Ec '@wonderwhy-er/desktop-commander@0\.2\.47.*remote.*--persist-session' || :)"
TOTAL_REMOTE_COUNT="$(printf '%s\n' "$REMOTE_LAUNCHERS" | grep -Ec '@wonderwhy-er/desktop-commander@[^ ]+.*remote' || :)"
NONCANONICAL_REMOTE_COUNT=$(( TOTAL_REMOTE_COUNT - CANONICAL_REMOTE_COUNT ))
printf 'CANONICAL_REMOTE_COUNT=%s\n' "$CANONICAL_REMOTE_COUNT"
printf 'NONCANONICAL_REMOTE_COUNT=%s\n' "$NONCANONICAL_REMOTE_COUNT"
[[ "$CANONICAL_REMOTE_COUNT" -eq 1 ]]
[[ "$NONCANONICAL_REMOTE_COUNT" -eq 0 ]]
