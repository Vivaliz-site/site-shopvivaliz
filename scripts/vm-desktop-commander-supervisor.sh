#!/usr/bin/env bash
set -Eeuo pipefail

HOME_DIR="${HOME:-/home/ubuntu}"
DEVICE_FILE="$HOME_DIR/.desktop-commander-device/device.json"
COOLDOWN_FILE="$HOME_DIR/.desktop-commander-device/auth-required.cooldown"
PACKAGE='@wonderwhy-er/desktop-commander@0.2.47'
NPX_BIN="${NPX_BIN:-npx}"

if [[ ! -f "$DEVICE_FILE" ]]; then
  echo 'AUTH_REQUIRED=true reason=device_state_missing'
  exit 20
fi

if [[ -f "$COOLDOWN_FILE" ]]; then
  if find "$COOLDOWN_FILE" -mmin -360 -print -quit | grep -q .; then
    echo 'AUTH_REQUIRED=true reason=recent_provider_reauth_request'
    exit 20
  fi
  rm -f "$COOLDOWN_FILE"
fi

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
set +e
"$NPX_BIN" --yes "$PACKAGE" remote 2>&1 | tee "$tmp"
rc=${PIPESTATUS[0]}
set -e

if grep -Eqi 'Please complete authentication|Starting device authorization flow|device code|Authorization required' "$tmp"; then
  mkdir -p "$(dirname "$COOLDOWN_FILE")"
  : > "$COOLDOWN_FILE"
  echo 'AUTH_REQUIRED=true reason=provider_device_flow'
  exit 20
fi

exit "$rc"
