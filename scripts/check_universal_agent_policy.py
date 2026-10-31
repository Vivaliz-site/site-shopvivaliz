#!/usr/bin/env python3
"""Fail closed when the universal agent continuity policy is absent or contradictory."""
from pathlib import Path
import sys
root = Path(__file__).resolve().parents[1]
required = ["AGENTS.md", "CLAUDE.md", "GEMINI.md", "docs/AGENTS.md", ".github/AGENTS.md", "docs/knowledge/agent-rules.md"]
marker = "Regra universal de continuidade e esgotamento de rotas"
errors = []
for rel in required:
    p = root / rel
    if not p.is_file() or marker not in p.read_text(encoding="utf-8"):
        errors.append(f"Missing universal policy: {rel}")
for rel in ("AGENTS.md", "docs/knowledge/agent-rules.md"):
    data = (root / rel).read_text(encoding="utf-8")
    for token in ("MCP/Remote Control", "GitHub CLI/API", "lease/fencing", "nao contornar MFA", "checkpoint"):
        if token not in data:
            errors.append(f"Missing safeguard {token}: {rel}")
if errors:
    print("\n".join(errors), file=sys.stderr)
    sys.exit(1)
print("Universal agent policy present in all entrypoints")
