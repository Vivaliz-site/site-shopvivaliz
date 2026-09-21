#!/usr/bin/env bash
set -Eeuo pipefail

export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"

service="shopvivaliz-squad-claude-bridge.service"
service_dir="$HOME/.config/systemd/user"
unit="$service_dir/$service"
runtime="$HOME/.local/share/shopvivaliz-squad-claude"
workspace="$runtime/workspace"
bridge="/home/ubuntu/shopvivaliz-deploy/current/ops/ai-squad/claude-bridge.mjs"
claude_bin="/home/ubuntu/.local/bin/claude"
env_path="/home/ubuntu/shopvivaliz-deploy/shared/.env"
credentials_path="/home/ubuntu/.claude/.credentials.json"
legacy_bridge="/home/ubuntu/.local/share/shopvivaliz-ai-squad/claude-bridge.mjs"
node_bin="$(command -v node)"

test -n "$node_bin"
test -f "$bridge"
test -x "$claude_bin"
test -f "$env_path"
mkdir -p "$service_dir" "$workspace"
chmod 700 "$runtime" "$workspace"

tmp="$(mktemp "$service_dir/.${service}.XXXXXX")"
trap 'rm -f "$tmp"' EXIT
cat >"$tmp" <<EOF
[Unit]
Description=ShopVivaliz AI Squad Claude Code account bridge
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=$workspace
ExecStart=$node_bin $bridge
Environment=AI_SQUAD_CLAUDE_BRIDGE_PORT=17657
Environment=AI_SQUAD_CLAUDE_BIN=$claude_bin
Environment=AI_SQUAD_CLAUDE_ENV_PATH=$env_path
Environment=AI_SQUAD_CLAUDE_HOME=/home/ubuntu
Environment=AI_SQUAD_CLAUDE_CREDENTIALS_PATH=$credentials_path
Environment=AI_SQUAD_CLAUDE_WORKDIR=$workspace
Environment=HOME=/home/ubuntu
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
systemctl --user stop "$service" >/dev/null 2>&1 || true
if command -v fuser >/dev/null 2>&1; then
  fuser -k 17657/tcp >/dev/null 2>&1 || true
fi
systemctl --user enable "$service" >/dev/null
systemctl --user start "$service"

health_url="http://127.0.0.1:17657/health"
for _ in $(seq 1 50); do
  if body="$(curl -fsS --max-time 3 "$health_url" 2>/dev/null)"; then
    if printf '%s' "$body" | grep -q '"endpoint":"ai-squad-claude-bridge"' \
      && printf '%s' "$body" | grep -q '"ok":true' \
      && printf '%s' "$body" | grep -q '"authenticated":true'; then
      printf '%s\n' "AI_SQUAD_CLAUDE_BRIDGE_SERVICE=ACTIVE"
      printf '%s\n' "AI_SQUAD_CLAUDE_BRIDGE_HEALTH=VERIFIED"
      exit 0
    fi
  fi
  sleep 1
done

printf '%s\n' "AI_SQUAD_CLAUDE_BRIDGE_HEALTH=FAILED" >&2
systemctl --user --no-pager --full status "$service" >&2 || true
exit 1
