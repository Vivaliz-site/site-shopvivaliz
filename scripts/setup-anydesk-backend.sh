#!/usr/bin/env bash
set -Eeuo pipefail

ACTION="${1:-status}"
EXPECTED_HOST="always-free-arm-1787907847-26"
RDP_USER="fredrdp"
GUI_USER="fredconsole"
GUI_CONTROL_USER="ubuntu"

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
    if version_value="$(anydesk --version 2>/dev/null | head -1)"; then
      echo "ANYDESK_VERSION=$version_value"
    else
      echo "ANYDESK_VERSION=unavailable"
    fi
  else
    echo "ANYDESK_INSTALLED=false"
  fi
  if service_state="$(systemctl is-active anydesk.service 2>/dev/null)"; then
    echo "ANYDESK_SERVICE=$service_state"
  else
    echo "ANYDESK_SERVICE=inactive"
  fi
  if enabled_state="$(systemctl is-enabled anydesk.service 2>/dev/null)"; then
    echo "ANYDESK_ENABLED=$enabled_state"
  else
    echo "ANYDESK_ENABLED=disabled"
  fi
  if command -v anydesk >/dev/null 2>&1; then
    if id_value="$(timeout 10 anydesk --get-id 2>/dev/null)"; then
      :
    else
      id_value=""
    fi
    if [ -n "$id_value" ]; then
      echo "ANYDESK_ID=$id_value"
    else
      echo "ANYDESK_ID=unavailable"
    fi
    if online="$(timeout 10 anydesk --get-status 2>/dev/null)"; then
      :
    else
      online=""
    fi
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
    if ! command -v update-menus >/dev/null 2>&1; then echo "ANYDESK_ERROR=update_menus_missing" >&2; exit 30; fi
    if ! command -v update-desktop-database >/dev/null 2>&1; then echo "ANYDESK_ERROR=update_desktop_database_missing" >&2; exit 31; fi
    if ! command -v xdg-desktop-menu >/dev/null 2>&1; then echo "ANYDESK_ERROR=xdg_desktop_menu_missing" >&2; exit 32; fi
    dpkg --configure anydesk
  fi
  if ! dpkg --audit; then
    echo "ANYDESK_WARN=dpkg_audit_reported_pending_items" >&2
  fi
  systemctl daemon-reload
  systemctl enable --now anydesk.service
  sleep 2
  status
  echo "ANYDESK_INSTALL=PASS"
}

launch_gui() {
  if ! command -v anydesk >/dev/null 2>&1; then
    echo "ANYDESK_ERROR=not_installed" >&2
    exit 24
  fi

  local tray_pid env_dump display xauthority runtime dbus window_dump session_id session_type session_remote
  if tray_pid="$(pgrep -u "$GUI_USER" -f '/usr/bin/anydesk --tray' | head -1)"; then
    :
  else
    tray_pid=""
  fi
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
  if session_type="$(loginctl show-session "$session_id" -p Type --value 2>/dev/null)"; then
    :
  else
    session_type=""
  fi
  if session_remote="$(loginctl show-session "$session_id" -p Remote --value 2>/dev/null)"; then
    :
  else
    session_remote=""
  fi

  if [ -z "$display" ] || { [ "$display" != ":0" ] && [ "$display" != ":0.0" ]; } || [ "$session_type" != "x11" ] || [ "$session_remote" != "no" ]; then
    echo "ANYDESK_ERROR=unsupported_gui_session" >&2
    echo "ANYDESK_DISPLAY=${display:-missing}" >&2
    echo "ANYDESK_SESSION_TYPE=${session_type:-missing}" >&2
    echo "ANYDESK_SESSION_REMOTE=${session_remote:-missing}" >&2
    exit 28
  fi

  if [ -z "$xauthority" ]; then xauthority="/home/$GUI_USER/.Xauthority"; fi
  if [ -z "$runtime" ]; then runtime="/run/user/$(id -u "$GUI_USER")"; fi
  if [ -z "$dbus" ]; then dbus="unix:path=$runtime/bus"; fi

  if runuser -u "$GUI_USER" -- env \
    DISPLAY="$display" \
    XAUTHORITY="$xauthority" \
    XDG_RUNTIME_DIR="$runtime" \
    DBUS_SESSION_BUS_ADDRESS="$dbus" \
    GDK_BACKEND=x11 \
    anydesk --settings >/dev/null 2>&1; then
    :
  else
    echo "ANYDESK_WARN=settings_open_returned_nonzero" >&2
  fi

  sleep 2
  if window_dump="$(runuser -u "$GUI_USER" -- env DISPLAY="$display" XAUTHORITY="$xauthority" xwininfo -root -tree 2>/dev/null)"; then
    :
  else
    window_dump=""
  fi
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

console_control() {
  local mode="${1:-grant}" tray_pid env_dump display xauthority
  if tray_pid="$(pgrep -u "$GUI_USER" -f '/usr/bin/anydesk --tray' | head -1)"; then
    :
  else
    tray_pid=""
  fi
  if [ -z "$tray_pid" ]; then
    echo "ANYDESK_ERROR=physical_tray_missing" >&2
    exit 25
  fi
  env_dump="$(tr '\0' '\n' < "/proc/$tray_pid/environ")"
  display="$(printf '%s\n' "$env_dump" | sed -n 's/^DISPLAY=//p' | head -1)"
  xauthority="$(printf '%s\n' "$env_dump" | sed -n 's/^XAUTHORITY=//p' | head -1)"
  if [ -z "$xauthority" ]; then xauthority="/home/$GUI_USER/.Xauthority"; fi
  if [ "$display" != ":0" ] && [ "$display" != ":0.0" ]; then
    echo "ANYDESK_ERROR=unsupported_control_display" >&2
    exit 33
  fi
  case "$mode" in
    grant)
      runuser -u "$GUI_USER" -- env DISPLAY="$display" XAUTHORITY="$xauthority" xhost "+SI:localuser:$GUI_CONTROL_USER" >/dev/null
      echo "ANYDESK_CONTROL=granted"
      ;;
    revoke)
      runuser -u "$GUI_USER" -- env DISPLAY="$display" XAUTHORITY="$xauthority" xhost "-SI:localuser:$GUI_CONTROL_USER" >/dev/null 2>&1 || true
      echo "ANYDESK_CONTROL=revoked"
      ;;
    *) echo "ANYDESK_ERROR=unsupported_control_action" >&2; exit 34 ;;
  esac
  echo "ANYDESK_CONTROL_USER=$GUI_CONTROL_USER"
  echo "ANYDESK_DISPLAY=$display"
}

case "$ACTION" in
  install) install_anydesk ;;
  status) status ;;
  launch) launch_gui ;;
  control_grant) console_control grant ;;
  control_revoke) console_control revoke ;;
  *)
    echo "ANYDESK_ERROR=unsupported_action" >&2
    exit 64
    ;;
esac
