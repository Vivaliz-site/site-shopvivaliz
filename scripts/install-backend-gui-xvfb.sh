#!/usr/bin/env bash
# Installs the isolated Xvfb display used by the backend remote-control MCP.
#
# - Creates a dedicated xauth cookie (0600, outside the repo). The cookie value is never printed.
# - Installs deploy/systemd/shopvivaliz-gui-xvfb.service as a system unit running as `ubuntu`.
# - Never attaches to another user's session and never uses `-ac`.
# Requires sudo only for the unit installation step.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SECRETS_DIR="${HOME}/.shopvivaliz/secrets"
XAUTH_FILE="${SECRETS_DIR}/gui-xvfb.xauth"
UNIT_SRC="${REPO_ROOT}/deploy/systemd/shopvivaliz-gui-xvfb.service"
UNIT_DST="/etc/systemd/system/shopvivaliz-gui-xvfb.service"
DISPLAY_NAME=":97"

if [[ "$(id -un)" != "ubuntu" ]]; then
  echo "run as ubuntu (the unit executes as ubuntu)" >&2
  exit 1
fi

missing=()
for bin in Xvfb xdotool xwd xauth; do
  command -v "$bin" >/dev/null 2>&1 || missing+=("$bin")
done
if (( ${#missing[@]} > 0 )); then
  echo "missing binaries: ${missing[*]}" >&2
  echo "install with: sudo apt-get install -y xvfb xdotool x11-apps xauth" >&2
  exit 1
fi

umask 077
mkdir -p "$SECRETS_DIR"
chmod 700 "$SECRETS_DIR"

if [[ ! -s "$XAUTH_FILE" ]]; then
  cookie="$(od -An -tx1 -N16 /dev/urandom | tr -d ' \n')"
  xauth -f "$XAUTH_FILE" add "$DISPLAY_NAME" MIT-MAGIC-COOKIE-1 "$cookie"
  unset cookie
fi
chmod 600 "$XAUTH_FILE"

sudo install -m 0644 "$UNIT_SRC" "$UNIT_DST"
sudo systemctl daemon-reload
sudo systemctl enable --now shopvivaliz-gui-xvfb.service

# Verification uses only the dedicated display and its cookie.
geometry="$(DISPLAY="$DISPLAY_NAME" XAUTHORITY="$XAUTH_FILE" xdotool getdisplaygeometry)"
if [[ "$geometry" != "1440 900" ]]; then
  echo "unexpected display geometry: ${geometry}" >&2
  exit 1
fi
echo "isolated display ${DISPLAY_NAME} ready at 1440x900 (system unit, survives logout)"
