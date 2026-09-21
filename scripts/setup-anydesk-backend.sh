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

  local settings_log
  settings_log="/home/$GUI_USER/.local/state/shopvivaliz-anydesk-settings.log"
  install -d -m 700 -o "$GUI_USER" -g "$GUI_USER" "/home/$GUI_USER/.local/state"
  runuser -u "$GUI_USER" -- env \
    DISPLAY="$display" \
    XAUTHORITY="$xauthority" \
    XDG_RUNTIME_DIR="$runtime" \
    DBUS_SESSION_BUS_ADDRESS="$dbus" \
    GDK_BACKEND=x11 \
    sh -lc "nohup anydesk --settings >'$settings_log' 2>&1 </dev/null &"

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

admin_security() {
  local tray_pid env_dump display xauthority runtime dbus admin_log
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
  if [ -z "$xauthority" ]; then xauthority="/home/$GUI_USER/.Xauthority"; fi
  if [ -z "$runtime" ]; then runtime="/run/user/$(id -u "$GUI_USER")"; fi
  if [ -z "$dbus" ]; then dbus="unix:path=$runtime/bus"; fi
  if [ "$display" != ":0" ] && [ "$display" != ":0.0" ]; then
    echo "ANYDESK_ERROR=unsupported_admin_display" >&2
    exit 38
  fi
  admin_log="/home/$GUI_USER/.local/state/shopvivaliz-anydesk-admin-security.log"
  install -d -m 700 -o "$GUI_USER" -g "$GUI_USER" "/home/$GUI_USER/.local/state"
  nohup env \
    DISPLAY="$display" \
    XAUTHORITY="$xauthority" \
    XDG_RUNTIME_DIR="$runtime" \
    DBUS_SESSION_BUS_ADDRESS="$dbus" \
    QT_ACCESSIBILITY=1 \
    GDK_BACKEND=x11 \
    anydesk --admin-settings:security >"$admin_log" 2>&1 </dev/null &
  sleep 2
  echo "ANYDESK_ADMIN_SECURITY=PASS"
  echo "ANYDESK_DISPLAY=$display"
}

admin_security_rdp() {
  local session_pid env_dump display xauthority runtime dbus admin_log
  if session_pid="$(pgrep -n -u "$RDP_USER" -f 'xfce4-session' 2>/dev/null)"; then
    :
  else
    session_pid=""
  fi
  if [ -z "$session_pid" ]; then
    echo "ANYDESK_ERROR=rdp_desktop_session_missing" >&2
    exit 39
  fi
  env_dump="$(tr '\0' '\n' < "/proc/$session_pid/environ")"
  display="$(printf '%s\n' "$env_dump" | sed -n 's/^DISPLAY=//p' | head -1)"
  xauthority="$(printf '%s\n' "$env_dump" | sed -n 's/^XAUTHORITY=//p' | head -1)"
  runtime="$(printf '%s\n' "$env_dump" | sed -n 's/^XDG_RUNTIME_DIR=//p' | head -1)"
  dbus="$(printf '%s\n' "$env_dump" | sed -n 's/^DBUS_SESSION_BUS_ADDRESS=//p' | head -1)"
  case "$display" in
    :[1-9]*|:[1-9]*.0) ;;
    *) echo "ANYDESK_ERROR=unsupported_rdp_admin_display" >&2; exit 40 ;;
  esac
  if [ -z "$xauthority" ]; then xauthority="/home/$RDP_USER/.Xauthority"; fi
  if [ -z "$runtime" ]; then runtime="/run/user/$(id -u "$RDP_USER")"; fi
  if [ -z "$dbus" ]; then dbus="unix:path=$runtime/bus"; fi
  admin_log="/home/$RDP_USER/.local/state/shopvivaliz-anydesk-admin-security-rdp.log"
  install -d -m 700 -o "$RDP_USER" -g "$RDP_USER" "/home/$RDP_USER/.local/state"
  nohup env \
    DISPLAY="$display" \
    XAUTHORITY="$xauthority" \
    XDG_RUNTIME_DIR="$runtime" \
    DBUS_SESSION_BUS_ADDRESS="$dbus" \
    QT_ACCESSIBILITY=1 \
    GDK_BACKEND=x11 \
    anydesk --admin-settings:security >"$admin_log" 2>&1 </dev/null &
  sleep 2
  echo "ANYDESK_ADMIN_SECURITY_RDP=PASS"
  echo "ANYDESK_RDP_DISPLAY=$display"
}

console_unlock() {
  local session_id locked active
  session_id="$(loginctl list-sessions --no-legend | awk -v user="$GUI_USER" '$3 == user && $4 == "seat0" {print $1; exit}')"
  if [ -z "$session_id" ]; then
    echo "ANYDESK_ERROR=console_session_missing" >&2
    exit 35
  fi
  loginctl unlock-session "$session_id"
  loginctl activate "$session_id"
  sleep 1
  locked="$(loginctl show-session "$session_id" -p LockedHint --value 2>/dev/null)"
  active="$(loginctl show-seat seat0 -p ActiveSession --value 2>/dev/null)"
  echo "ANYDESK_CONSOLE_SESSION=$session_id"
  echo "ANYDESK_CONSOLE_LOCKED=$locked"
  echo "ANYDESK_CONSOLE_ACTIVE=$active"
  if [ "$locked" = "yes" ] || [ "$active" != "$session_id" ]; then
    echo "ANYDESK_ERROR=console_unlock_not_effective" >&2
    exit 36
  fi
  echo "ANYDESK_CONSOLE_UNLOCK=PASS"
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
      if ! runuser -u "$GUI_USER" -- env DISPLAY="$display" XAUTHORITY="$xauthority" xhost "-SI:localuser:$GUI_CONTROL_USER" >/dev/null 2>&1; then
        echo "ANYDESK_WARN=control_revoke_not_present" >&2
      fi
      echo "ANYDESK_CONTROL=revoked"
      ;;
    *) echo "ANYDESK_ERROR=unsupported_control_action" >&2; exit 34 ;;
  esac
  echo "ANYDESK_CONTROL_USER=$GUI_CONTROL_USER"
  echo "ANYDESK_DISPLAY=$display"
}

ui_dump() {
  local tray_pid env_dump display runtime dbus pyroot typelib
  if tray_pid="$(pgrep -u "$GUI_USER" -f '/usr/bin/anydesk --tray' | head -1)"; then :; else tray_pid=""; fi
  [ -n "$tray_pid" ] || { echo "ANYDESK_UI_ERROR=physical_tray_missing" >&2; exit 41; }
  env_dump="$(tr '\0' '\n' < "/proc/$tray_pid/environ")"
  display="$(printf '%s\n' "$env_dump" | sed -n 's/^DISPLAY=//p' | head -1)"
  runtime="$(printf '%s\n' "$env_dump" | sed -n 's/^XDG_RUNTIME_DIR=//p' | head -1)"
  dbus="$(printf '%s\n' "$env_dump" | sed -n 's/^DBUS_SESSION_BUS_ADDRESS=//p' | head -1)"
  [ -n "$runtime" ] || runtime="/run/user/$(id -u "$GUI_USER")"
  [ -n "$dbus" ] || dbus="unix:path=$runtime/bus"
  pyroot="/tmp/shopvivaliz-atspi"
  typelib="$pyroot/usr/lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH)/girepository-1.0"
  if [ ! -f "$typelib/Atspi-2.0.typelib" ] || [ ! -d "$pyroot/usr/lib/python3/dist-packages/pyatspi" ]; then
    rm -rf "$pyroot"
    mkdir -p "$pyroot/pkg"
    (
      cd "$pyroot/pkg"
      apt-get download gir1.2-atspi-2.0 python3-pyatspi >/dev/null 2>&1
      for f in ./*.deb; do dpkg-deb -x "$f" "$pyroot"; done
    )
  fi
  runuser -u "$GUI_USER" -- env     DISPLAY="$display"     XDG_RUNTIME_DIR="$runtime"     DBUS_SESSION_BUS_ADDRESS="$dbus"     GI_TYPELIB_PATH="$typelib"     PYTHONPATH="$pyroot/usr/lib/python3/dist-packages"     python3 - <<'PY'
import pyatspi
desktop = pyatspi.Registry.getDesktop(0)
def walk(node, depth=0):
    try:
        name = (node.name or "").strip()
        role = node.getRoleName()
    except Exception:
        return
    if name or role in {"check box","push button","text","password text","page tab","label"}:
        safe = name
        if role in {"text","password text"} and len(safe) > 80:
            safe = safe[:80] + "..."
        print(f"ANYDESK_UI|{depth}|{role}|{safe}")
    if depth >= 8:
        return
    try:
        for child in node:
            walk(child, depth+1)
    except Exception:
        return
for app in desktop:
    try:
        if "anydesk" in (app.name or "").lower():
            walk(app)
    except Exception:
        pass
PY
  echo "ANYDESK_UI_DUMP=PASS"
}

case "$ACTION" in
  install) install_anydesk ;;
  status) status ;;
  launch) launch_gui ;;
  admin_security) admin_security ;;
  admin_security_rdp) admin_security_rdp ;;
  ui_dump) ui_dump ;;
  control_grant) console_control grant ;;
  control_revoke) console_control revoke ;;
  console_unlock) console_unlock ;;
  *)
    echo "ANYDESK_ERROR=unsupported_action" >&2
    exit 64
    ;;
esac
