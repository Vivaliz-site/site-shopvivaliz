#!/usr/bin/env bash
set -Eeuo pipefail

PHASE="${1:?phase required}"
VERSION="${2:-0.2.48}"
DEVICE_DIR="${HOME}/.desktop-commander-device"
LINK_FILE="${DEVICE_DIR}/reauth-link.txt"
STATE_FILE="${DEVICE_DIR}/reauth-state.txt"
PID_FILE="${DEVICE_DIR}/reauth-session.pid"
SESSION_LOG="${DEVICE_DIR}/reauth-session.log"
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

    # The official --logout only removes this local file. Removing it directly
    # avoids invoking npx while tearing down the session we are replacing.
    rm -f "$DEVICE_DIR/device.json"
    rm -f "$DEVICE_DIR/auth-required.cooldown" "$DEVICE_DIR/provider-connected.marker"       "$LINK_FILE" "$STATE_FILE" "$SESSION_LOG"

    RUNNER_TRACKING_ID= nohup setsid timeout 600s       npx --yes "$PACKAGE" remote --persist-session >"$SESSION_LOG" 2>&1 < /dev/null &
    pid=$!
    printf '%s\n' "$pid" > "$PID_FILE"
    chmod 600 "$PID_FILE" "$SESSION_LOG"

    found=false
    for _ in $(seq 1 90); do
      if [[ -s "$SESSION_LOG" ]]; then
        url="$(python3 - "$SESSION_LOG" <<'PY'
import re
import sys
from pathlib import Path

text = Path(sys.argv[1]).read_text(encoding="utf-8", errors="replace")
text = re.sub(r"\x1b\[[0-?]*[ -/]*[@-~]", "", text)
patterns = (
    r"Verify this device in your browser:\s*(https://\S+)",
    r"Please visit:\s*(https://\S+)",
)
for pattern in patterns:
    match = re.search(pattern, text, flags=re.IGNORECASE)
    if match:
        print(match.group(1).strip())
        break
PY
)"
        if [[ "$url" == https://* ]]; then
          umask 077
          printf '%s\n' "$url" > "$LINK_FILE"
          found=true
          break
        fi
      fi
      if ! kill -0 "$pid" 2>/dev/null; then
        break
      fi
      sleep 1
    done

    test "$found" = true
    test -s "$LINK_FILE"
    grep -Eq '^https://' "$LINK_FILE"
    printf 'AUTH_LINK_READY\n' > "$STATE_FILE"
    echo "DC_REAUTH_BEGIN=PASS"
    ;;
  finalize)
    test -s "$DEVICE_DIR/device.json"
    stop_manual
    rm -f "$LINK_FILE" "$STATE_FILE" "$SESSION_LOG"
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
