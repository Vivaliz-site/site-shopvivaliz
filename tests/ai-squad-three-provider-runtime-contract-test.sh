#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
claude_installer="$root/ops/ai-squad/install-claude-bridge-user-service.sh"
claude_bridge="$root/ops/ai-squad/claude-bridge.mjs"
codex_installer="$root/ops/ai-squad/install-codex-bridge-user-service.sh"
deploy="$root/scripts/deploy-production.sh"
docs="$root/docs/knowledge/ai-squad.md"
admin="$root/admin/ai-squad.php"

test -f "$claude_installer"
test -f "$claude_bridge"
test -f "$codex_installer"

grep -q 'shopvivaliz-squad-claude-bridge.service' "$claude_installer"
grep -q 'current/ops/ai-squad/claude-bridge.mjs' "$claude_installer"
grep -q 'AI_SQUAD_CLAUDE_BRIDGE_PORT=17657' "$claude_installer"
grep -q 'AI_SQUAD_CLAUDE_ENV_PATH=' "$claude_installer"
grep -q 'AI_SQUAD_CLAUDE_CREDENTIALS_PATH=' "$claude_installer"
grep -q 'systemctl --user' "$claude_installer"
grep -q 'fuser -k 17657/tcp' "$claude_installer"
grep -q 'authenticated.*true' "$claude_installer"
grep -q 'resolveClaudeAuthSource' "$claude_bridge"
grep -q 'credential_store_configured' "$claude_bridge"
grep -q 'buildClaudeArgs(request).*20000' "$claude_bridge"

grep -q 'reconcile_ai_squad_codex_bridge_unit' "$deploy"
grep -q 'install-codex-bridge-user-service.sh' "$deploy"
grep -q 'reconcile_ai_squad_claude_bridge_unit' "$deploy"
grep -q 'install-claude-bridge-user-service.sh' "$deploy"
grep -q 'system-level legado' "$deploy"

python3 - "$deploy" <<'PY'
from pathlib import Path
import sys
text = Path(sys.argv[1]).read_text(encoding="utf-8")
start = text.index('if [ "${REMOTE_SHA:0:8}" = "$ACTIVE_SHA" ]; then')
end = text.index('log INFO "Producao, runtime e bridges AI Squad ja alinhados', start)
block = text[start:end]
for required in (
    'reconcile_ai_squad_codex_bridge_unit "$CURRENT_LINK"',
    'reconcile_ai_squad_claude_bridge_unit "$CURRENT_LINK"',
):
    if required not in block:
        raise SystemExit(f"FAIL: aligned-release path missing {required}")
if 'deploy/systemd/shopvivaliz-squad-claude-bridge.service' in text:
    raise SystemExit("FAIL: deploy must not depend on removed Claude system-level unit")
print("AI_SQUAD_RUNTIME_RECONCILE_CONTRACT=PASS")
PY

grep -q 'claude_code' "$docs"
grep -q 'vertex_oauth' "$docs"
grep -Fq 'OpenAI: `gpt-5.6-terra`, effort `medium`;' "$docs"
grep -Fq 'Anthropic: `claude-sonnet-5`, effort `medium`;' "$docs"
grep -Fq 'Gemini: `gemini-3.5-flash`, thinking `MEDIUM`;' "$docs"
grep -Fq '`AI_SQUAD_CODEX_WEB_SEARCH_MODE`' "$docs"
grep -q 'Fable: desabilitado' "$admin"
if grep -q 'Opus 5 primário' "$admin"; then
  echo 'FAIL: admin UI contains stale hard-coded Anthropic model label' >&2
  exit 1
fi

echo "AI_SQUAD_THREE_PROVIDER_RUNTIME_CONTRACT_TEST=PASS"
