#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
claude_installer="$root/ops/ai-squad/install-claude-bridge-user-service.sh"
claude_bridge="$root/ops/ai-squad/claude-bridge.mjs"
codex_installer="$root/ops/ai-squad/install-codex-bridge-user-service.sh"
deploy="$root/scripts/deploy-production.sh"
docs="$root/docs/knowledge/ai-squad.md"
claude_bootstrap="$root/docs/knowledge/claude-vm-bootstrap.md"
agent_rules="$root/docs/knowledge/agent-rules.md"
root_claude="$root/CLAUDE.md"
core="$root/includes/ai-squad-core.php"
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
grep -Fq 'bootstrap_doc="/home/ubuntu/shopvivaliz-deploy/current/docs/knowledge/claude-vm-bootstrap.md"' "$claude_installer"
grep -Fq "bootstrap_import='@~/.claude/shopvivaliz-bootstrap.md'" "$claude_installer"
grep -Fq 'ln -sfn "$bootstrap_doc" "$bootstrap_link"' "$claude_installer"
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
grep -Fq 'Gemini: `gemini-2.5-flash`, thinking `MEDIUM`;' "$docs"
grep -Fq '`thinkingBudget: 8192`' "$docs"
grep -Fq 'manual_chatgpt' "$docs"
! grep -q "getenv('OPENAI_API_KEY')" "$core"
! grep -q "getenv('ANTHROPIC_API_KEY')" "$core"
! grep -q "getenv('OPENROUTER_API_KEY')" "$core"
grep -Fq '`AI_SQUAD_CODEX_WEB_SEARCH_MODE`' "$docs"
test -f "$claude_bootstrap"
grep -Fq '@docs/knowledge/claude-vm-bootstrap.md' "$root_claude"
grep -Fq 'navegador de agente deve executar na VM' "$claude_bootstrap"
grep -Fq 'Não use Opera Connector' "$claude_bootstrap"
grep -Fq 'consulte a web quando a resposta depender de versão' "$claude_bootstrap"
grep -Fq 'documentação oficial' "$claude_bootstrap"
grep -Fq 'Navegador e pesquisa técnica' "$agent_rules"
grep -q 'Fable: desabilitado' "$admin"
if grep -q 'Opus 5 primário' "$admin"; then
  echo 'FAIL: admin UI contains stale hard-coded Anthropic model label' >&2
  exit 1
fi

echo "AI_SQUAD_THREE_PROVIDER_RUNTIME_CONTRACT_TEST=PASS"
