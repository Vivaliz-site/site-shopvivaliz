#!/usr/bin/env python3
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
CHECKS = {
    "docs/AGENT-DOCS-PREFLIGHT.md": ["AGENT_DOCS_PREFLIGHT_V1", "agent-docs-gate.py read", "agent-docs-gate.py verify"],
    "scripts/agent_docs_gate.py": ["AGENT_DOCS_PREFLIGHT_V1", "deliver_read_packet", "acknowledge", "verify_receipt"],
    "scripts/agent-operations-worker.py": ["from agent_docs_gate import verify_receipt", "docs_preflight", "docs-preflight-required"],
    "docs/ai-agents-map.md": ["AGENT_DOCS_PREFLIGHT_V1", "every agent"],
    "AGENTS-ACCESS-INDEX.md": ["AGENT_DOCS_PREFLIGHT_V1", "PRÉ-LEITURA OBRIGATÓRIA"],
    "AGENTS.md": ["REGRAS-AGENTES-CENTRALIZADAS.md"],
    "CLAUDE.md": ["docs/AGENTS.md"],
}

failures = []
for relative, markers in CHECKS.items():
    path = ROOT / relative
    if not path.is_file():
        failures.append(f"missing: {relative}")
        continue
    text = path.read_text(encoding="utf-8", errors="replace")
    for marker in markers:
        if marker not in text:
            failures.append(f"{relative}: missing marker {marker}")

# Audit every agent entrypoint without modifying globally pinned policy blobs.
TOPOLOGY = "docs/knowledge/current-topology-and-access-2026-10-29.md"
entrypoints = [
    "AGENTS.md",
    "CLAUDE.md",
    "GEMINI.md",
    "docs/AGENTS.md",
    ".github/AGENTS.md",
    ".github/copilot-instructions.md",
    ".github/instructions/ai-to-cli.instructions.md",
    ".codex/agents/shopvivaliz-devops.md",
    ".claude/rules/ai-to-cli.md",
    ".cursor/rules/nonstop-continuity.mdc",
    ".windsurf/rules/ai-to-cli.md",
    ".windsurf/rules/nonstop-continuity.md",
    "AGENTS-ACCESS-INDEX.md",
    "docs/ai-agents-map.md",
    "docs/knowledge/agent-rules.md",
    "docs/knowledge/atendimento-chatgpt-bootstrap.md",
    "docs/knowledge/claude-vm-bootstrap.md",
    "docs/knowledge/dev-agent-briefing.md",
    ".ai/README.md"
]
policy = (ROOT / TOPOLOGY).read_text(encoding="utf-8", errors="replace")
if "## Contrato de roteamento para todos os agentes" not in policy:
    failures.append(f"{TOPOLOGY}: missing active routing contract")
if f'"{TOPOLOGY}"' not in (ROOT / "scripts/agent_docs_gate.py").read_text(encoding="utf-8"):
    failures.append("agent docs manifest does not require current topology")
for relative in entrypoints:
    candidate = ROOT / relative
    if not candidate.is_file():
        failures.append(f"agent entrypoint missing: {relative}")
        continue
    body = candidate.read_text(encoding="utf-8", errors="replace")
    if "ACTIVE_MARINA_ROUTING_20261029" not in body:
        failures.append(f"agent entrypoint missing retired VM guard: {relative}")
    if TOPOLOGY not in body:
        failures.append(f"agent entrypoint missing topology reference: {relative}")

if failures:
    for failure in failures:
        print(f"ERROR {failure}", file=sys.stderr)
    raise SystemExit(2)

print("OK AGENT_DOCS_PREFLIGHT_V1 enforcement is intact")
