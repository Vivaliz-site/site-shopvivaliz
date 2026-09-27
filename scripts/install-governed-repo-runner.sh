#!/usr/bin/env bash
set -Eeuo pipefail

repo="${TARGET_REPO:-}"
runner_name="${RUNNER_NAME:-}"
runner_label="${RUNNER_LABEL:-}"
runner_version="${RUNNER_VERSION:-2.337.0}"
token="${RUNNER_TOKEN:-}"

case "${repo}|${runner_name}|${runner_label}" in
  "Vivaliz-site/ml-pricing-api|governed-ml-pricing-ci|ml-pricing-ci" | \
  "Vivaliz-site/mercadolivre-returns-recovery|governed-mlrr-ci|mlrr-ci" | \
  "Vivaliz-site/shopvivaliz-m365|governed-m365-ci|m365-ci")
    ;;
  *)
    echo "ERROR unsupported governed runner identity" >&2
    exit 64
    ;;
esac

[[ "$(id -u)" -ne 0 ]] || { echo "ERROR run as ubuntu, not root" >&2; exit 2; }
[[ "$(uname -m)" == "aarch64" ]] || { echo "ERROR ARM64 required" >&2; exit 2; }
command -v curl >/dev/null
command -v systemctl >/dev/null
command -v loginctl >/dev/null
command -v sudo >/dev/null

runner_user="$(id -un)"
sudo -n loginctl enable-linger "$runner_user"
[[ "$(loginctl show-user "$runner_user" -p Linger --value)" == "yes" ]] || {
  echo "ERROR user linger is required for persistent runner services" >&2
  exit 2
}

runner_root="/home/ubuntu/actions-runner-${runner_label}"
runner_unit="actions.runner.${runner_name}.service"
work_dir="_work-${runner_label}"
runtime="/run/user/$(id -u)"
export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-$runtime}"
export DBUS_SESSION_BUS_ADDRESS="${DBUS_SESSION_BUS_ADDRESS:-unix:path=$runtime/bus}"
for attempt in $(seq 1 10); do
  [[ -S "$runtime/bus" ]] && break
  sleep 1
done
[[ -S "$runtime/bus" ]] || { echo "ERROR user systemd bus unavailable after linger enablement" >&2; exit 2; }

install_unit() {
  local unit_dir="$HOME/.config/systemd/user"
  install -d -m 700 "$unit_dir"
  cat > "$unit_dir/$runner_unit" <<UNIT
[Unit]
Description=Governed GitHub Actions runner ${runner_name}
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=${runner_root}
ExecStart=${runner_root}/run.sh
Restart=always
RestartSec=5
KillSignal=SIGINT
TimeoutStopSec=300

[Install]
WantedBy=default.target
UNIT
  systemctl --user daemon-reload
  systemctl --user enable --now "$runner_unit"
  systemctl --user is-enabled --quiet "$runner_unit"
  systemctl --user is-active --quiet "$runner_unit"
}

if [[ -f "$runner_root/.runner" ]]; then
  install_unit
  echo "governed_runner=already_configured repo=$repo name=$runner_name label=$runner_label"
  exit 0
fi

[[ -n "$token" ]] || { echo "ERROR RUNNER_TOKEN required for first registration" >&2; exit 2; }
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
  --url "https://github.com/${repo}" \
  --token "$token" \
  --name "$runner_name" \
  --labels "$runner_label" \
  --work "$work_dir" \
  --unattended \
  --replace

unset RUNNER_TOKEN token
install_unit
echo "governed_runner=registered repo=$repo name=$runner_name label=$runner_label"
