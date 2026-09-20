#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
workflow="$root/.github/workflows/sync-ai-keys-to-vm.yml"
unit="$root/deploy/systemd/shopvivaliz-squad-claude-bridge.service"
deploy="$root/scripts/deploy-production.sh"
docs="$root/docs/knowledge/ai-squad.md"

grep -q 'CLAUDE_CODE_OAUTH_TOKEN:.*secrets.CLAUDE_CODE_OAUTH_TOKEN' "$workflow"
grep -q "'CLAUDE_CODE_OAUTH_TOKEN='" "$workflow"
grep -q 'CLAUDE_CODE_OAUTH_TOKEN' "$workflow"

test -f "$unit"
grep -q '^User=ubuntu$' "$unit"
grep -q 'claude-bridge.mjs' "$unit"
grep -q 'AI_SQUAD_CLAUDE_BRIDGE_PORT=17657' "$unit"
grep -q 'AI_SQUAD_CLAUDE_ENV_PATH=/home/ubuntu/shopvivaliz-deploy/shared/.env' "$unit"

grep -q 'reconcile_ai_squad_claude_bridge_unit' "$deploy"
grep -q 'shopvivaliz-squad-claude-bridge.service' "$deploy"
grep -q 'claude_code' "$docs"
grep -q 'vertex_oauth' "$docs"

echo "AI_SQUAD_THREE_PROVIDER_RUNTIME_CONTRACT_TEST=PASS"
