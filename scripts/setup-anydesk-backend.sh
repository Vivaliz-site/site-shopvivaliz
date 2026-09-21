#!/usr/bin/env bash
set -Eeuo pipefail

ACTION="${1:-status}"
EXPECTED_HOST="always-free-arm-1787907847-26"
RDP_USER="fredrdp"

if [ "$(id -u)" -ne 0 ]; then
  echo "ANYDESK_ERROR=root_required" >&2
  exit 20
fi
if [ "$(hostname)" != "$EXPECTED_HOST" ]; then
  echo "ANYDESK_ERROR=host_mismatch" >&2
  exit 21
fi
if ! id "$RDP_USER" >/dev/null 2>&1; then
  echo "ANYDESK_ERROR=rdp_user_missing" >&2
  exit 22
fi
if [ "$(dpkg --print-architecture)" != "arm64" ]; then
  echo "ANYDESK_ERROR=unsupported_arch" >&2
  exit 23
fi

status() {
  echo "ANYDESK_HOST=$(hostname)"
  echo "ANYDESK_ARCH=$(dpkg --print-architecture)"
  if dpkg-query -W -f='${Status}' anydesk 2>/dev/null | grep -q 'install ok installed'; then
    echo "ANYDESK_INSTALLED=true"
    echo "ANYDESK_VERSION=$(anydesk --version 2>/dev/null | head -1 || true)"
  else
    echo "ANYDESK_INSTALLED=false"
  fi
  echo "ANYDESK_SERVICE=$(systemctl is-active anydesk.service 2>/dev/null || true)"
  echo "ANYDESK_ENABLED=$(systemctl is-enabled anydesk.service 2>/dev/null || true)"
  if command -v anydesk >/dev/null 2>&1; then
    id_value="$(timeout 10 anydesk --get-id 2>/dev/null || true)"
    if [ -n "$id_value" ]; then
      echo "ANYDESK_ID=$id_value"
    else
      echo "ANYDESK_ID=unavailable"
    fi
    online="$(timeout 10 anydesk --get-status 2>/dev/null || true)"
    echo "ANYDESK_NETWORK_STATUS=${online:-unknown}"
  fi
}

install_anydesk() {
  apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ca-certificates curl apt-transport-https menu desktop-file-utils xdg-utils
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://keys.anydesk.com/repos/DEB-GPG-KEY -o /etc/apt/keyrings/keys.anydesk.com.asc
  chmod a+r /etc/apt/keyrings/keys.anydesk.com.asc
  printf '%s\n' 'deb [signed-by=/etc/apt/keyrings/keys.anydesk.com.asc] https://deb.anydesk.com all main' > /etc/apt/sources.list.d/anydesk-stable.list
  apt-get update -qq
  if ! DEBIAN_FRONTEND=noninteractive apt-get install -y -qq anydesk; then
    echo "ANYDESK_WARN=apt_install_failed_attempting_dpkg_repair" >&2
    command -v update-menus >/dev/null 2>&1 || { echo "ANYDESK_ERROR=update_menus_missing" >&2; exit 30; }
    command -v update-desktop-database >/dev/null 2>&1 || { echo "ANYDESK_ERROR=update_desktop_database_missing" >&2; exit 31; }
    command -v xdg-desktop-menu >/dev/null 2>&1 || { echo "ANYDESK_ERROR=xdg_desktop_menu_missing" >&2; exit 32; }
    dpkg --configure anydesk
  fi
  dpkg --audit || true
  systemctl daemon-reload
  systemctl enable --now anydesk.service
  sleep 2
  status
  echo "ANYDESK_INSTALL=PASS"
}

launch_gui() {
  command -v anydesk >/dev/null 2>&1 || {
    echo "ANYDESK_ERROR=not_installed" >&2
    exit 24
  }
  display="$(ps -u "$RDP_USER" -o args= | sed -n 's#.*Xorg \(:[0-9][0-9]*\).*#\1#p' | head -1)"
  if [ -z "$display" ]; then
    echo "ANYDESK_ERROR=xorg_session_missing" >&2
    exit 25
  fi
  uid="$(id -u "$RDP_USER")"
  runtime="/run/user/$uid"
  auth="/home/$RDP_USER/.Xauthority"
  log="/home/$RDP_USER/.local/state/shopvivaliz-anydesk-gui.log"
  install -d -m 700 -o "$RDP_USER" -g "$RDP_USER" "/home/$RDP_USER/.local/state"
  runuser -u "$RDP_USER" -- env     DISPLAY="$display"     XAUTHORITY="$auth"     XDG_RUNTIME_DIR="$runtime"     DBUS_SESSION_BUS_ADDRESS="unix:path=$runtime/bus"     GDK_BACKEND=x11     sh -lc "nohup anydesk >'$log' 2>&1 </dev/null &"
  sleep 3
  if pgrep -u "$RDP_USER" -x anydesk >/dev/null 2>&1; then
    echo "ANYDESK_GUI=running"
    echo "ANYDESK_DISPLAY=$display"
    echo "ANYDESK_LAUNCH=PASS"
  else
    echo "ANYDESK_ERROR=gui_not_running" >&2
    tail -20 "$log" 2>/dev/null || true
    exit 26
  fi
}

case "$ACTION" in
  install) install_anydesk ;;
  status) status ;;
  launch) launch_gui ;;
  *)
    echo "ANYDESK_ERROR=unsupported_action" >&2
    exit 64
    ;;
esac
