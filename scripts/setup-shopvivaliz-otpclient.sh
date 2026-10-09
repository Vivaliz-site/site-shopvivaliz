#!/usr/bin/env bash
# Install OTPClient GUI service without revealing or modifying its encrypted database.
set -Eeuo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_unit="$root/ops/systemd/shopvivaliz-otpclient.service"
unit=shopvivaliz-otpclient.service
dest=/etc/systemd/system/$unit

test "$(id -u)" -eq 0 || { echo "ERROR=root_required" >&2; exit 2; }
test -f "$source_unit" || { echo "ERROR=unit_source_missing" >&2; exit 3; }
test -x /usr/bin/otpclient || { echo "ERROR=otpclient_not_installed" >&2; exit 4; }
test "$(id -u fredrdp)" = 1002 || { echo "ERROR=unexpected_gui_uid" >&2; exit 5; }
test -s /home/fredrdp/.local/share/otpclient.enc || { echo "ERROR=protected_vault_unavailable" >&2; exit 6; }
test -f /home/fredrdp/.Xauthority || { echo "ERROR=xauthority_unavailable" >&2; exit 7; }
command -v flock >/dev/null || { echo "ERROR=flock_unavailable" >&2; exit 8; }
exec 9>/run/lock/shopvivaliz-otpclient-install.lock
flock -n 9 || { echo "ERROR=concurrent_install" >&2; exit 9; }

tmp="$(mktemp /etc/systemd/system/.shopvivaliz-otpclient.XXXXXXXX)"
trap 'rm -f "$tmp"' EXIT
install -m 0644 "$source_unit" "$tmp"
mv -f "$tmp" "$dest"
systemctl daemon-reload
if systemctl is-active --quiet shopvivaliz-otpclient-recovery.service; then
  systemctl stop shopvivaliz-otpclient-recovery.service
fi
was_active=0
if systemctl is-active --quiet "$unit"; then was_active=1; fi
systemctl enable "$unit" >/dev/null
if [ "$was_active" -eq 1 ]; then
  systemctl restart "$unit"
else
  systemctl start "$unit"
fi
systemctl is-active --quiet "$unit" || { echo "ERROR=otpclient_service_not_running" >&2; exit 10; }
test "$(systemctl is-enabled "$unit")" = enabled || { echo "ERROR=otpclient_service_not_enabled" >&2; exit 11; }
test -s /home/fredrdp/.local/share/otpclient.enc || { echo "ERROR=protected_vault_missing_after_install" >&2; exit 12; }
echo "SHOPVIVALIZ_OTPCLIENT_PERSISTENCE=PASS"
echo "OTPCLIENT_VAULT_UNLOCK_STATUS=NOT_VERIFIED"
