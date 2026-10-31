#!/usr/bin/env bash
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
protocol=AI-TO-CLI-PROTOCOL.md
test -f "$protocol"
grep -Fq 'PROTOCOLO OBRIGATORIO DE CONCLUSAO DE TAREFAS' "$protocol"
grep -Fq 'falha de ferramenta, comando, plugin, CLI, API, browser, sessao ou timeout nao e estado final' "$protocol"
grep -Fq 'CONCLUIDO' "$protocol"
grep -Fq 'BLOQUEADO' "$protocol"
for f in AGENTS.md AGENTS.override.md CLAUDE.md GEMINI.md .github/copilot-instructions.md .cursor/rules/nonstop-continuity.mdc .windsurf/rules/nonstop-continuity.md; do
  test -f "$f" || { echo "missing agent entrypoint: $f" >&2; exit 1; }
  grep -Fq 'AI-TO-CLI-PROTOCOL.md' "$f" || { echo "$f does not reference canonical protocol" >&2; exit 1; }
done
# Fail closed against the actual systemd process under /opt immutable controller release.
# Hosted CI does not have the production controller mount.
if [[ "$(hostname)" == "always-free-arm-1787907847-26" ]]; then
  python3 scripts/audit-controller-runtime-parity.py \
    --reference scripts/agent_task_state.py \
    --live-service shopvivaliz-gemini-24x7-controller.service
fi
echo 'agent continuity contract: OK'
