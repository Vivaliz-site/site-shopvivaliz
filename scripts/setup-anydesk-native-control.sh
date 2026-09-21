#!/usr/bin/env bash
set -Eeuo pipefail

ACTION="${1:-status}"
EXPECTED_HOST="always-free-arm-1787907847-26"
RDP_USER="fredrdp"
CONTROL_USER="ubuntu"
NATIVE_DISPLAY=":0"
XRDP_DISPLAY=":10"
NATIVE_LIGHTDM_CONF="/etc/lightdm/lightdm.conf.d/90-shopvivaliz-anydesk-native.conf"

if [ "$(id -u)" -ne 0 ]; then
  echo "ANYDESK_NATIVE_ERROR=root_required" >&2
  exit 20
fi
if [ "$(hostname)" != "$EXPECTED_HOST" ]; then
  echo "ANYDESK_NATIVE_ERROR=host_mismatch" >&2
  exit 21
fi

seat0_user() {
  local s
  if ! s="$(loginctl show-seat seat0 -p ActiveSession --value 2>/dev/null)"; then
    s=""
  fi
  if [ -n "$s" ]; then
    if ! loginctl show-session "$s" -p Name --value 2>/dev/null; then
      :
    fi
  fi
}

wait_native_user() {
  local i
  for i in $(seq 1 40); do
    [ "$(seat0_user)" = "$RDP_USER" ] && return 0
    sleep 1
  done
  echo "ANYDESK_NATIVE_ERROR=session_not_ready user=$(seat0_user)" >&2
  return 1
}

grant_local_control() {
  local auth="/home/$RDP_USER/.Xauthority"
  test -r "$auth"
  runuser -u "$RDP_USER" -- env DISPLAY="$NATIVE_DISPLAY" XAUTHORITY="$auth"     xhost "+SI:localuser:$CONTROL_USER" >/dev/null
  if ! runuser -u "$RDP_USER" -- env DISPLAY="$XRDP_DISPLAY" XAUTHORITY="$auth"     xhost "+SI:localuser:$CONTROL_USER" >/dev/null 2>&1; then
    echo "ANYDESK_NATIVE_WARN=xrdp_control_not_granted" >&2
  fi
}

launch_native_anydesk() {
  local uid runtime auth log i
  uid="$(id -u "$RDP_USER")"
  runtime="/run/user/$uid"
  auth="/home/$RDP_USER/.Xauthority"
  log="/home/$RDP_USER/.local/state/shopvivaliz-anydesk-native-gui.log"
  install -d -m 700 -o "$RDP_USER" -g "$RDP_USER" "/home/$RDP_USER/.local/state"
  runuser -u "$RDP_USER" -- env DISPLAY="$NATIVE_DISPLAY" XAUTHORITY="$auth"     XDG_RUNTIME_DIR="$runtime" DBUS_SESSION_BUS_ADDRESS="unix:path=$runtime/bus"     GDK_BACKEND=x11 sh -lc "nohup anydesk >'$log' 2>&1 </dev/null &"
  for i in $(seq 1 15); do
    sleep 1
    if runuser -u "$RDP_USER" -- env DISPLAY="$NATIVE_DISPLAY" XAUTHORITY="$auth"       xwininfo -root -tree 2>/dev/null | grep -qi 'AnyDesk'; then
      echo "ANYDESK_NATIVE_GUI=window_present"
      return 0
    fi
  done
  echo "ANYDESK_NATIVE_ERROR=gui_not_running" >&2
  if [ -r "$log" ]; then
    tail -20 "$log" | sed -E 's/([A-Za-z0-9+\/_=-]{32,})/[redacted]/g' >&2
  fi
  return 1
}

status_native() {
  local auth="/home/$RDP_USER/.Xauthority" gui="absent"
  if [ "$(seat0_user)" = "$RDP_USER" ] && [ -r "$auth" ]; then
    if runuser -u "$RDP_USER" -- env DISPLAY="$NATIVE_DISPLAY" XAUTHORITY="$auth"       xwininfo -root -tree 2>/dev/null | grep -qi 'AnyDesk'; then
      gui="present"
    fi
  fi
  echo "ANYDESK_NATIVE_SEAT_USER=$(seat0_user)"
  echo "ANYDESK_NATIVE_DISPLAY=$NATIVE_DISPLAY"
  echo "ANYDESK_NATIVE_GUI=$gui"
}

prepare_native() {
  command -v anydesk >/dev/null 2>&1
  apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq xclip xdotool x11-utils scrot
  install -d -m 755 /etc/lightdm/lightdm.conf.d
  cat > "$NATIVE_LIGHTDM_CONF" <<EOF
[Seat:*]
autologin-user=$RDP_USER
autologin-user-timeout=0
user-session=xfce
EOF
  chmod 644 "$NATIVE_LIGHTDM_CONF"
  systemctl restart lightdm.service
  wait_native_user
  grant_local_control
  launch_native_anydesk
  status_native
  echo "ANYDESK_NATIVE_PREPARE=PASS"
}

password_from_clipboard() {
  local auth="/home/$RDP_USER/.Xauthority"
  test -r "$auth"
  runuser -u "$RDP_USER" -- env DISPLAY="$NATIVE_DISPLAY" XAUTHORITY="$auth"     xclip -selection clipboard -o     | anydesk --set-password >/dev/null 2>&1
  echo "ANYDESK_PASSWORD_FROM_CLIPBOARD=PASS"
}

cleanup_native() {
  local auth="/home/$RDP_USER/.Xauthority"
  if [ -r "$auth" ]; then
    if ! runuser -u "$RDP_USER" -- env DISPLAY="$NATIVE_DISPLAY" XAUTHORITY="$auth"       xhost "-SI:localuser:$CONTROL_USER" >/dev/null 2>&1; then
      :
    fi
    if ! runuser -u "$RDP_USER" -- env DISPLAY="$XRDP_DISPLAY" XAUTHORITY="$auth"       xhost "-SI:localuser:$CONTROL_USER" >/dev/null 2>&1; then
      :
    fi
  fi
  rm -f "$NATIVE_LIGHTDM_CONF"
  systemctl restart lightdm.service
  sleep 3
  echo "ANYDESK_NATIVE_CLEANUP=PASS"
}

case "$ACTION" in
  prepare) prepare_native ;;
  status) status_native ;;
  password_from_clipboard) password_from_clipboard ;;
  cleanup) cleanup_native ;;
  *) echo "ANYDESK_NATIVE_ERROR=unsupported_action" >&2; exit 64 ;;
esac
