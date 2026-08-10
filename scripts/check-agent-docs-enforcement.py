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

if failures:
    for failure in failures:
        print(f"ERROR {failure}", file=sys.stderr)
    raise SystemExit(2)

print("OK AGENT_DOCS_PREFLIGHT_V1 enforcement is intact")
