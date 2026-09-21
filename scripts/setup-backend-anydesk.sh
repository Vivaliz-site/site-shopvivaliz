#!/usr/bin/env bash
set -Eeuo pipefail

ACTION="${1:-status}"
EXPECTED_HOST="always-free-arm-1787907847-26"
GUI_USER="fredrdp"
ANYDESK_VERSION="8.0.4"
ANYDESK_SHA256="059e5b39a2a368a0db1b458a1d4033b01d9760a99bfbc867fbd56b78a72f1819"
ANYDESK_URL="https://deb.anydesk.com/pool/main/a/anydesk/anydesk_${ANYDESK_VERSION}_arm64.deb"

if [ "$(id -u)" -ne 0 ]; then
  echo "BACKEND_ANYDESK_ERROR=root_required" >&2
  exit 20
fi
if [ "$(hostname)" != "$EXPECTED_HOST" ]; then
  echo "BACKEND_ANYDESK_ERROR=host_mismatch" >&2
  exit 21
fi
if [ "$(dpkg --print-architecture)" != "arm64" ]; then
  echo "BACKEND_ANYDESK_ERROR=architecture_mismatch" >&2
  exit 22
fi

print_status() {
  local pkg service lightdm local_xorg anydesk_id
  pkg="$(dpkg-query -W -f='${Status} ${Version} ${Architecture}' anydesk 2>/dev/null || true)"
  service="$(systemctl is-active anydesk 2>/dev/null || true)"
  lightdm="$(systemctl is-active lightdm 2>/dev/null || true)"
  if pgrep -af '/usr/lib/xorg/Xorg :0([[:space:]]|$)' >/dev/null 2>&1; then local_xorg=true; else local_xorg=false; fi
  anydesk_id="$(anydesk --get-id 2>/dev/null | tr -cd '0-9' | head -c 20 || true)"
  echo "BACKEND_ANYDESK_STATUS=ok"
  echo "ANYDESK_PACKAGE=${pkg:-not-installed}"
  echo "ANYDESK_SERVICE=${service:-unknown}"
  echo "LIGHTDM_SERVICE=${lightdm:-unknown}"
  echo "LOCAL_XORG=${local_xorg}"
  echo "ANYDESK_ID=${anydesk_id:-unavailable}"
}

launch_gui() {
  local pid display xauth runtime bus
  pid="$(pgrep -u "$GUI_USER" -x xfce4-session | head -1 || true)"
  if [ -z "$pid" ]; then
    echo "ANYDESK_GUI=skipped_no_active_xfce_session"
    return 0
  fi
  display="$(tr '\0' '\n' < "/proc/$pid/environ" | sed -n 's/^DISPLAY=//p' | head -1)"
  xauth="$(tr '\0' '\n' < "/proc/$pid/environ" | sed -n 's/^XAUTHORITY=//p' | head -1)"
  runtime="$(tr '\0' '\n' < "/proc/$pid/environ" | sed -n 's/^XDG_RUNTIME_DIR=//p' | head -1)"
  bus="$(tr '\0' '\n' < "/proc/$pid/environ" | sed -n 's/^DBUS_SESSION_BUS_ADDRESS=//p' | head -1)"
  [ -n "$display" ] || display=":10"
  [ -n "$xauth" ] || xauth="/home/$GUI_USER/.Xauthority"
  [ -n "$runtime" ] || runtime="/run/user/$(id -u "$GUI_USER")"
  [ -n "$bus" ] || bus="unix:path=$runtime/bus"
  runuser -u "$GUI_USER" -- env DISPLAY="$display" XAUTHORITY="$xauth" XDG_RUNTIME_DIR="$runtime" DBUS_SESSION_BUS_ADDRESS="$bus" sh -c 'nohup anydesk >/tmp/anydesk-gui.log 2>&1 </dev/null &'
  echo "ANYDESK_GUI=launched display=$display user=$GUI_USER"
}

install_anydesk() {
  local pkg
  pkg="$(mktemp --suffix=.deb)"
  trap 'rm -f "$pkg"' RETURN
  curl -fL --retry 3 --connect-timeout 10 -A 'Mozilla/5.0' "$ANYDESK_URL" -o "$pkg"
  printf '%s  %s\n' "$ANYDESK_SHA256" "$pkg" | sha256sum -c -
  apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg"
  systemctl enable --now anydesk
  systemctl start lightdm
  sleep 2
  launch_gui
  print_status
  echo "BACKEND_ANYDESK_INSTALL=PASS"
}

case "$ACTION" in
  install) install_anydesk ;;
  status) print_status ;;
  launch_gui) launch_gui ;;
  *) echo "BACKEND_ANYDESK_ERROR=unsupported_action" >&2; exit 64 ;;
esac
