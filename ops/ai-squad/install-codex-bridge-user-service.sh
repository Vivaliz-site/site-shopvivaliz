#!/usr/bin/env bash
set -euo pipefail

export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"

service="shopvivaliz-squad-codex-bridge.service"
service_dir="$HOME/.config/systemd/user"
unit="$service_dir/$service"
runtime="$HOME/.local/share/shopvivaliz-squad-codex"
workspace="$runtime/workspace"
bridge_source="$(cd -- "$(dirname -- "$0")" && pwd -P)/codex-bridge.mjs"
bridge="$HOME/.local/share/shopvivaliz-ai-squad/codex-bridge.mjs"
codex_real="/home/ubuntu/.local/lib/node_modules/@openai/codex/bin/codex.js"
business_home="/home/ubuntu/.codex-business"
node_bin="$(command -v node)"
paid_bridge_manual_approved="${SHOPVIVALIZ_PAID_BRIDGE_MANUAL_APPROVED:-0}"
paid_bridge_mode="${SHOPVIVALIZ_PAID_BRIDGE_MODE:-disabled}"

case "$paid_bridge_manual_approved:$paid_bridge_mode" in
  0:disabled|1:interactive) ;;
  *)
    printf '%s\n' 'Invalid paid bridge policy; expected 0:disabled or 1:interactive' >&2
    exit 64
    ;;
esac

if [ -z "$node_bin" ]; then
  printf '%s\n' 'AI_SQUAD_CODEX_PREREQUISITE=node_missing' >&2
  exit 1
fi
if [ ! -f "$bridge_source" ]; then
  printf '%s\n' 'AI_SQUAD_CODEX_PREREQUISITE=bridge_source_missing' >&2
  exit 1
fi
if [ ! -f "$codex_real" ]; then
  printf '%s\n' 'AI_SQUAD_CODEX_PREREQUISITE=codex_script_missing' >&2
  exit 1
fi
mkdir -p "$service_dir" "$workspace"
chmod 700 "$runtime" "$workspace"
# Install the policy-gated bridge into a stable user-owned runtime location.
# This avoids an old release symlink enabling paid inference during rollback.
mkdir -p "$(dirname "$bridge")"
chmod 700 "$(dirname "$bridge")"
staged_bridge="$(mktemp "$(dirname "$bridge")/.codex-bridge.XXXXXX.mjs")"
trap 'rm -f "$staged_bridge"' EXIT
install -m 0644 "$bridge_source" "$staged_bridge"
"$node_bin" --check "$staged_bridge"
mv -f "$staged_bridge" "$bridge"
trap - EXIT

tmp="$(mktemp "$service_dir/.${service}.XXXXXX")"
trap 'rm -f "$tmp"' EXIT
cat >"$tmp" <<EOF
[Unit]
Description=ShopVivaliz AI Squad Codex ChatGPT bridge
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=$workspace
ExecStart=$node_bin $bridge
Environment=AI_SQUAD_CODEX_BRIDGE_PORT=17656
Environment=SHOPVIVALIZ_PAID_BRIDGE_MANUAL_APPROVED=$paid_bridge_manual_approved
Environment=SHOPVIVALIZ_PAID_BRIDGE_MODE=$paid_bridge_mode
Environment=AI_SQUAD_CODEX_REAL=$codex_real
Environment=AI_SQUAD_CODEX_BUSINESS_HOME=$business_home
Environment=AI_SQUAD_CODEX_WORKDIR=$workspace
Environment=AI_SQUAD_CODEX_WEB_SEARCH_MODE=live
Environment=PATH=/home/ubuntu/.local/bin:/usr/local/bin:/usr/bin:/bin
Restart=always
RestartSec=5
TimeoutStopSec=15
KillMode=mixed
NoNewPrivileges=yes
PrivateTmp=yes
UMask=0077

[Install]
WantedBy=default.target
EOF

chmod 600 "$tmp"
mv "$tmp" "$unit"
trap - EXIT

systemctl --user daemon-reload
systemctl --user enable --now "$service"
systemctl --user restart "$service"

checker="$(cd -- "$(dirname -- "$0")" && pwd -P)/bridge-health-policy.py"
test -f "$checker"
for _ in $(seq 1 30); do
  if python3 "$checker" codex; then
    printf '%s\n' "AI_SQUAD_CODEX_BRIDGE_SERVICE=ACTIVE"
    exit 0
  fi
  sleep 1
done

printf '%s\n' "AI_SQUAD_CODEX_BRIDGE_HEALTH=FAILED" >&2
if ! systemctl --user --no-pager --full status "$service" >&2; then
  printf '%s\n' "AI_SQUAD_CODEX_BRIDGE_STATUS=UNAVAILABLE" >&2
fi
exit 1
