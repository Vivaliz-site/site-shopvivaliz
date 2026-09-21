#!/usr/bin/env python3
"""Require an explicit final-response gate in every normative agent document."""

from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    ROOT / "REGRAS-AGENTES-CENTRALIZADAS.md",
    ROOT / "AGENTS.md",
    ROOT / "docs" / "AGENTS.md",
    ROOT / "CLAUDE.md",
)
MARKER = "FINAL_RESPONSE_DEPLOY_GATE_V1"
REQUIRED = (MARKER, "resposta final", "pós-deploy")

errors = []
for path in FILES:
    try:
        text = path.read_text(encoding="utf-8").lower()
    except OSError as exc:
        errors.append(f"{path}: unreadable: {exc}")
        continue
    for token in REQUIRED:
        if token.lower() not in text:
            errors.append(f"{path}: missing {token}")

if errors:
    print("FINAL RESPONSE DEPLOY GATE: FAIL", file=sys.stderr)
    print("\n".join(errors), file=sys.stderr)
    raise SystemExit(1)
print("FINAL RESPONSE DEPLOY GATE: OK")