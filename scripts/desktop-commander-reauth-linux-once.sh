#!/usr/bin/env bash
set -Eeuo pipefail

PHASE="${1:?phase required}"
VERSION="${2:-0.2.48}"
DEVICE_DIR="${HOME}/.desktop-commander-device"
LINK_FILE="${DEVICE_DIR}/reauth-link.txt"
STATE_FILE="${DEVICE_DIR}/reauth-state.txt"
PID_FILE="${DEVICE_DIR}/reauth-session.pid"
SESSION_SCRIPT="${DEVICE_DIR}/reauth-session.sh"
PACKAGE="@wonderwhy-er/desktop-commander@${VERSION}"
SERVICE="shopvivaliz-desktop-commander.service"
GUARDIAN="shopvivaliz-desktop-commander-guardian.timer"

install -d -m 700 "$DEVICE_DIR"

stop_manual() {
  if [[ -s "$PID_FILE" ]]; then
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [[ "$pid" =~ ^[0-9]+$ ]]; then
      kill -- "-$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true
      sleep 1
    fi
  fi
  rm -f "$PID_FILE"
}

case "$PHASE" in
  begin)
    sudo -n systemctl stop "$GUARDIAN" 2>/dev/null || true
    sudo -n systemctl stop "$SERVICE"
    stop_manual

    npx --yes "$PACKAGE" remote --logout >/dev/null 2>&1
    rm -f "$DEVICE_DIR/auth-required.cooldown" "$DEVICE_DIR/provider-connected.marker" "$LINK_FILE" "$STATE_FILE"

    cat > "$SESSION_SCRIPT" <<'SESSION'
#!/usr/bin/env bash
set -uo pipefail
package="$1"
link_file="$2"
state_file="$3"
npx --yes "$package" remote --persist-session 2>&1 | while IFS= read -r line; do
  clean="$(printf '%s' "$line" | sed -E $'s/\033\\[[0-9;]*[[:alpha:]]//g')"
  url="$(printf '%s\n' "$clean" | grep -Eo 'https://[^[:space:]]+' | head -n 1 || true)"
  if [[ "$url" == https://* ]]; then
    umask 077
    printf '%s\n' "$url" > "$link_file"
  fi
  if [[ "$clean" == *"Device ready"* ]]; then
    umask 077
    printf 'DEVICE_READY\n' > "$state_file"
  fi
done
rc="${PIPESTATUS[0]}"
umask 077
printf 'EXITED rc=%s\n' "$rc" > "$state_file"
SESSION
    chmod 700 "$SESSION_SCRIPT"

    RUNNER_TRACKING_ID= nohup setsid "$SESSION_SCRIPT" "$PACKAGE" "$LINK_FILE" "$STATE_FILE" >/dev/null 2>&1 < /dev/null &
    pid=$!
    printf '%s\n' "$pid" > "$PID_FILE"
    chmod 600 "$PID_FILE"

    for _ in $(seq 1 60); do
      if [[ -s "$LINK_FILE" ]]; then break; fi
      sleep 1
    done
    test -s "$LINK_FILE"
    grep -Eq '^https://' "$LINK_FILE"
    echo "DC_REAUTH_BEGIN=PASS"
    ;;
  finalize)
    test -s "$DEVICE_DIR/device.json"
    stop_manual
    rm -f "$LINK_FILE" "$STATE_FILE" "$SESSION_SCRIPT"
    sudo -n systemctl start "$SERVICE"
    sudo -n systemctl start "$GUARDIAN" 2>/dev/null || true
    sleep 5
    sudo -n systemctl is-active --quiet "$SERVICE"
    echo "DC_REAUTH_FINALIZE=PASS"
    ;;
  *)
    echo "unsupported phase" >&2
    exit 2
    ;;
esac
