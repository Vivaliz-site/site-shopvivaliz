#!/usr/bin/env bash
set -Eeuo pipefail

repo='Vivaliz-site/site-shopvivaliz'
repo_url="https://github.com/${repo}"
runner_name='shopvivaliz-backend-browser'
runner_label='shopvivaliz-backend-browser'
runner_version="${RUNNER_VERSION:-2.337.0}"
runner_root="${RUNNER_ROOT:-/home/ubuntu/actions-runner-shopvivaliz-browser}"
runner_unit='actions.runner.shopvivaliz-backend-browser.service'
browser_source="${SHOPVIVALIZ_BROWSER_CHROMIUM_SOURCE:-/home/ubuntu/.cache/ms-playwright/chromium-1234/chrome-linux/chrome}"
browser_link='/home/ubuntu/.local/bin/shopvivaliz-browser-chromium'
token="${RUNNER_TOKEN:-}"

fail() {
  printf 'ERROR %s\n' "$1" >&2
  exit 2
}

[[ "$(id -u)" -ne 0 ]] || fail 'run as the ubuntu user, not root'
[[ "$(uname -m)" == 'aarch64' ]] || fail 'backend browser runner requires ARM64'
[[ -x "$browser_source" ]] || fail 'canonical Browser Worker Chromium is unavailable'
command -v systemctl >/dev/null || fail 'systemctl unavailable'
command -v curl >/dev/null || fail 'curl unavailable'

runtime_dir="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
export XDG_RUNTIME_DIR="$runtime_dir"
export DBUS_SESSION_BUS_ADDRESS="${DBUS_SESSION_BUS_ADDRESS:-unix:path=${runtime_dir}/bus}"
[[ -S "${runtime_dir}/bus" ]] || fail 'user systemd bus unavailable; linger/user manager must be active'

install -d -m 700 "$(dirname "$browser_link")"
ln -sfn "$browser_source" "$browser_link"

install_user_unit() {
  local unit_dir="$HOME/.config/systemd/user"
  install -d -m 700 "$unit_dir"
  cat > "$unit_dir/$runner_unit" <<UNIT
[Unit]
Description=ShopVivaliz backend browser GitHub Actions runner
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=/home/ubuntu/actions-runner-shopvivaliz-browser
ExecStart=/home/ubuntu/actions-runner-shopvivaliz-browser/run.sh
Restart=always
RestartSec=5
KillSignal=SIGINT
TimeoutStopSec=300

[Install]
WantedBy=default.target
UNIT
  systemctl --user daemon-reload
  systemctl --user enable --now "$runner_unit"
  systemctl --user is-enabled --quiet "$runner_unit" || fail 'backend browser runner service is not enabled'
  systemctl --user is-active --quiet "$runner_unit" || fail 'backend browser runner service is not active'
}

if [[ -f "$runner_root/.runner" ]]; then
  install_user_unit
  echo 'backend_browser_runner=already_configured'
  echo "runner_service=$runner_unit"
  echo 'browser_runtime=ready'
  exit 0
fi

[[ -n "$token" ]] || fail 'RUNNER_TOKEN is required for first registration'
install -d -m 700 "$runner_root"
archive="$(mktemp)"
trap 'rm -f "$archive"' EXIT
curl -fsSL --retry 3 --retry-delay 2 \
  "https://github.com/actions/runner/releases/download/v${runner_version}/actions-runner-linux-arm64-${runner_version}.tar.gz" \
  -o "$archive"
tar -xzf "$archive" -C "$runner_root"
rm -f "$archive"
trap - EXIT

cd "$runner_root"
./config.sh \
  --url "$repo_url" \
  --token "$token" \
  --name "$runner_name" \
  --labels "$runner_label" \
  --work '_work-shopvivaliz-browser' \
  --unattended \
  --replace

install_user_unit

echo 'backend_browser_runner=registered'
echo "runner_service=$runner_unit"
echo 'browser_runtime=ready'
