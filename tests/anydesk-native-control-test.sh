#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
script="$root/scripts/setup-anydesk-native-control.sh"
workflow="$root/.github/workflows/backend-vm-oci-control.yml"

test -f "$script"
grep -q 'autologin-user=$RDP_USER' "$script"
grep -q 'user-session=xfce' "$script"
grep -q 'xhost "+SI:localuser:$CONTROL_USER"' "$script"
grep -q 'xhost "-SI:localuser:$CONTROL_USER"' "$script"
grep -q 'DISPLAY="$NATIVE_DISPLAY"' "$script"
grep -q 'anydesk --set-password' "$script"
if grep -qE 'x11vnc|rfbport|nopw' "$script"; then
  echo "unexpected network VNC exposure"
  exit 1
fi
grep -q 'anydesk_native_prepare' "$workflow"
grep -q 'anydesk_native_status' "$workflow"
grep -q 'anydesk_password_from_clipboard' "$workflow"
grep -q 'anydesk_native_cleanup' "$workflow"
grep -q 'setup-anydesk-native-control.sh' "$workflow"

echo "ANYDESK_NATIVE_CONTROL_CONTRACT=PASS"
