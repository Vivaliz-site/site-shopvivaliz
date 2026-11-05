#!/usr/bin/env python3
from pathlib import Path

workflow = (Path(__file__).resolve().parents[1] / ".github/workflows/oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")

required = (
    "action=ai-squad-claude-diagnostic",
    "Diagnose AI Squad Claude bridge through Remote Control MCP",
    "AI_SQUAD_CLAUDE_SERVICE_ACTIVE=",
    # O workflow monta AI_SQUAD_CLAUDE_HEALTH_<DST>= em tempo de execucao a partir
    # de pares (campo, DST); nao ha literal "HEALTH_OK=" no arquivo.
    "'AI_SQUAD_CLAUDE_HEALTH_' + dst + '='",
    "('ok','OK')",
    "'AI_SQUAD_CLAUDE_HEALTH_OK'",
    "AI_SQUAD_CLAUDE_AUTH_LOGGED_IN=",
    "AI_SQUAD_CLAUDE_PROBE_FAILURE_CLASS=",
    "pgrep -c -u ubuntu",
)
for marker in required:
    assert marker in workflow, marker

# O job bootstrap esta aposentado (VM de origem excluida em 2026-10-29) e deve
# permanecer fail-closed.
assert "RETIRED_SOURCE_JOB_GUARD_20261029" in workflow
# O passo de diagnostico nao pode apontar para o host excluido nem chamar o MCP
# sem alvo definido: sem alvo valido, ele falha de forma explicita.
step_start = workflow.index("- name: Diagnose AI Squad Claude bridge through Remote Control MCP")
step = workflow[step_start:]
next_step = step.find("\n      - name:", 10)
if next_step != -1:
    step = step[:next_step]
assert "shopvivaliz-free-a1" not in step, "diagnostic step targets the retired host"
assert "RETIRED_HOST_TARGET_REMOVED_20261105" in step
assert "AI_SQUAD_CLAUDE_MCP_FAILURE_CLASS=target_host_not_defined" in step
assert "'host':target_host" in step
assert step.index("target_host_not_defined") < step.index("urllib.request.Request("), \
    "target check must run before any MCP request"
assert "${{\n        false && (" in workflow

for forbidden in (
    "cat /home/ubuntu/.claude/.credentials.json",
    "cat ~/.claude/.credentials.json",
    "print(stderr",
    "print(stdout",
):
    assert forbidden not in workflow, forbidden

print("AI_SQUAD_CLAUDE_BASTION_DIAGNOSTIC_CONTRACT=PASS")
