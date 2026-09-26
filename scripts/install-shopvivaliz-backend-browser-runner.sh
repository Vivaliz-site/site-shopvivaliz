#!/usr/bin/env bash
set -Eeuo pipefail

repo_url='https://github.com/Vivaliz-site/site-shopvivaliz'
runner_name='shopvivaliz-backend-browser'
runner_label='shopvivaliz-backend-browser'
runner_version="${RUNNER_VERSION:-2.337.0}"
runner_root="${RUNNER_ROOT:-/home/ubuntu/actions-runner-shopvivaliz-browser}"
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

install -d -m 700 "$(dirname "$browser_link")"
ln -sfn "$browser_source" "$browser_link"

if [[ -f "$runner_root/.runner" ]]; then
  unit="$(systemctl list-unit-files --type=service --no-legend 'actions.runner.*.service' 2>/dev/null | awk '{print $1}' | while read -r candidate; do
    [[ -n "$candidate" ]] || continue
    workdir="$(systemctl show -p WorkingDirectory --value "$candidate" 2>/dev/null || true)"
    [[ "$workdir" == "$runner_root" ]] && { printf '%s\n' "$candidate"; break; }
  done)"
  [[ -n "$unit" ]] || fail 'configured runner exists but service is missing'
  sudo systemctl enable "$unit" >/dev/null
  sudo systemctl restart "$unit"
  sudo systemctl is-active --quiet "$unit" || fail 'backend browser runner service failed to start'
  echo 'backend_browser_runner=already_configured'
  echo "runner_service=$unit"
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
sudo ./svc.sh install ubuntu
sudo ./svc.sh start

unit="$(systemctl list-unit-files --type=service --no-legend 'actions.runner.*.service' | awk '{print $1}' | while read -r candidate; do
  [[ -n "$candidate" ]] || continue
  workdir="$(systemctl show -p WorkingDirectory --value "$candidate" 2>/dev/null || true)"
  [[ "$workdir" == "$runner_root" ]] && { printf '%s\n' "$candidate"; break; }
done)"
[[ -n "$unit" ]] || fail 'runner service not found after install'
sudo systemctl is-enabled --quiet "$unit" || fail 'runner service is not enabled'
sudo systemctl is-active --quiet "$unit" || fail 'runner service is not active'
echo 'backend_browser_runner=registered'
echo "runner_service=$unit"
echo 'browser_runtime=ready'
