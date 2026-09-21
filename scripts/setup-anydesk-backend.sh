#!/usr/bin/env bash
set -Eeuo pipefail

ACTION="${1:-status}"
EXPECTED_HOST="always-free-arm-1787907847-26"
RDP_USER="fredrdp"
GUI_USER="fredconsole"

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
if ! id "$GUI_USER" >/dev/null 2>&1; then
  echo "ANYDESK_ERROR=gui_user_missing" >&2
  exit 27
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

  local tray_pid env_dump display xauthority runtime dbus window_dump session_id session_type session_remote
  tray_pid="$(pgrep -u "$GUI_USER" -f '/usr/bin/anydesk --tray' | head -1 || true)"
  if [ -z "$tray_pid" ]; then
    echo "ANYDESK_ERROR=physical_tray_missing" >&2
    exit 25
  fi

  env_dump="$(tr '\0' '\n' < "/proc/$tray_pid/environ")"
  display="$(printf '%s\n' "$env_dump" | sed -n 's/^DISPLAY=//p' | head -1)"
  xauthority="$(printf '%s\n' "$env_dump" | sed -n 's/^XAUTHORITY=//p' | head -1)"
  runtime="$(printf '%s\n' "$env_dump" | sed -n 's/^XDG_RUNTIME_DIR=//p' | head -1)"
  dbus="$(printf '%s\n' "$env_dump" | sed -n 's/^DBUS_SESSION_BUS_ADDRESS=//p' | head -1)"

  session_id="$(loginctl list-sessions --no-legend | awk -v user="$GUI_USER" '$3 == user {print $1; exit}')"
  session_type="$(loginctl show-session "$session_id" -p Type --value 2>/dev/null || true)"
  session_remote="$(loginctl show-session "$session_id" -p Remote --value 2>/dev/null || true)"

  if [ -z "$display" ] || [ "$display" != ":0" ] || [ "$session_type" != "x11" ] || [ "$session_remote" != "no" ]; then
    echo "ANYDESK_ERROR=unsupported_gui_session" >&2
    echo "ANYDESK_DISPLAY=${display:-missing}" >&2
    echo "ANYDESK_SESSION_TYPE=${session_type:-missing}" >&2
    echo "ANYDESK_SESSION_REMOTE=${session_remote:-missing}" >&2
    exit 28
  fi

  [ -n "$xauthority" ] || xauthority="/home/$GUI_USER/.Xauthority"
  [ -n "$runtime" ] || runtime="/run/user/$(id -u "$GUI_USER")"
  [ -n "$dbus" ] || dbus="unix:path=$runtime/bus"

  runuser -u "$GUI_USER" -- env \
    DISPLAY="$display" \
    XAUTHORITY="$xauthority" \
    XDG_RUNTIME_DIR="$runtime" \
    DBUS_SESSION_BUS_ADDRESS="$dbus" \
    GDK_BACKEND=x11 \
    anydesk --settings >/dev/null 2>&1 || true

  sleep 2
  window_dump="$(runuser -u "$GUI_USER" -- env DISPLAY="$display" XAUTHORITY="$xauthority" xwininfo -root -tree 2>/dev/null || true)"
  if printf '%s\n' "$window_dump" | grep -qi 'AnyDesk'; then
    echo "ANYDESK_GUI=window_present"
  elif pgrep -u "$GUI_USER" -f '/usr/bin/anydesk --tray' >/dev/null 2>&1; then
    echo "ANYDESK_GUI=tray_present"
  else
    echo "ANYDESK_ERROR=gui_not_running" >&2
    exit 26
  fi
  echo "ANYDESK_GUI_USER=$GUI_USER"
  echo "ANYDESK_DISPLAY=$display"
  echo "ANYDESK_SESSION_TYPE=$session_type"
  echo "ANYDESK_SESSION_REMOTE=$session_remote"
  echo "ANYDESK_LAUNCH=PASS"
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
