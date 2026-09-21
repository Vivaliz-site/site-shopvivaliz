#!/usr/bin/env bash
set -euo pipefail

release_path="${1:-/home/ubuntu/shopvivaliz-deploy/current}"
codex_installer="$release_path/ops/ai-squad/install-codex-bridge-user-service.sh"
claude_service="shopvivaliz-squad-claude-bridge.service"
claude_source="$release_path/deploy/systemd/$claude_service"
claude_target="/etc/systemd/system/$claude_service"
claude_runtime="/home/ubuntu/.local/share/shopvivaliz-squad-claude"
claude_workspace="$claude_runtime/workspace"

test -f "$codex_installer"
XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}" bash "$codex_installer"

codex_health="$(curl -fsS --max-time 5 http://127.0.0.1:17656/health)"
if ! printf '%s' "$codex_health" | grep -q '"ok":true' \
  || ! printf '%s' "$codex_health" | grep -q '"web_search_mode":"live"'; then
  echo "AI_SQUAD_CODEX_RUNTIME_MODE_INVALID=true" >&2
  exit 1
fi

if [ ! -f "$claude_source" ]; then
  if sudo systemctl cat "$claude_service" >/dev/null 2>&1; then
    sudo systemctl disable --now "$claude_service"
  fi
  sudo rm -f "$claude_target"
  sudo systemctl daemon-reload
  exit 0
fi

sudo install -d -o ubuntu -g ubuntu -m 0700 "$claude_runtime" "$claude_workspace"
sudo install -o root -g root -m 0644 "$claude_source" "$claude_target"
sudo systemd-analyze verify "$claude_target"
sudo systemctl daemon-reload
sudo systemctl enable "$claude_service"
sudo systemctl restart "$claude_service"
sudo systemctl is-active --quiet "$claude_service"

health_url="http://127.0.0.1:17657/health"
for _ in $(seq 1 20); do
  if body="$(curl -fsS --max-time 3 "$health_url" 2>/dev/null)"; then
    if printf '%s' "$body" | grep -q '"endpoint":"ai-squad-claude-bridge"'; then
      echo "AI_SQUAD_RUNTIME_RECONCILE=PASS"
      exit 0
    fi
  fi
  sleep 1
done

echo "AI_SQUAD_CLAUDE_BRIDGE_HEALTH=FAILED" >&2
exit 1
