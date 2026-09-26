#!/usr/bin/env bash
set -euo pipefail

release_path="${1:-/home/ubuntu/shopvivaliz-deploy/current}"
codex_installer="$release_path/ops/ai-squad/install-codex-bridge-user-service.sh"
claude_service="shopvivaliz-squad-claude-bridge.service"
legacy_claude_service="shopvivaliz-claude-bridge.service"
claude_installer="$release_path/ops/ai-squad/install-claude-bridge-user-service.sh"

test -f "$codex_installer"
test -f "$claude_installer"
XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}" bash "$codex_installer"

codex_health="$(curl -fsS --max-time 5 http://127.0.0.1:17656/health)"
if ! printf '%s' "$codex_health" | grep -q '"ok":true' \
  || ! printf '%s' "$codex_health" | grep -q '"web_search_mode":"live"'; then
  echo "AI_SQUAD_CODEX_RUNTIME_MODE_INVALID=true" >&2
  exit 1
fi

# Claude is canonically a user-level service because it must reuse the
# authenticated Claude.ai credential store owned by the ubuntu account.
# Retire the pre-Squad service first; it used the same port (17657) and can
# otherwise keep the canonical bridge in an EADDRINUSE restart loop.
if XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}" systemctl --user cat "$legacy_claude_service" >/dev/null 2>&1; then
  XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}" systemctl --user disable --now "$legacy_claude_service"
fi
XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}" bash "$claude_installer"

claude_health="$(curl -fsS --max-time 5 http://127.0.0.1:17657/health)"
if ! printf '%s' "$claude_health" | grep -q '"endpoint":"ai-squad-claude-bridge"' \
  || ! printf '%s' "$claude_health" | grep -q '"ok":true' \
  || ! printf '%s' "$claude_health" | grep -q '"authenticated":true'; then
  echo "AI_SQUAD_CLAUDE_BRIDGE_HEALTH=FAILED" >&2
  exit 1
fi

echo "AI_SQUAD_RUNTIME_RECONCILE=PASS"
