#!/usr/bin/env python3
"""Fail closed when the universal agent execution and checklist contract drifts."""
from __future__ import annotations

import argparse
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
REQUIRED = (
    "AGENTS.md", "CLAUDE.md", "GEMINI.md", "docs/AGENTS.md",
    ".github/AGENTS.md", ".github/copilot-instructions.md",
    "docs/knowledge/agent-rules.md", "docs/knowledge/task-continuity.md",
)
MARKER = "Regra universal de continuidade e esgotamento de rotas"
CHECKLIST_MARKER = "MANDATORY_AGENT_CHECKLIST_V1"


def validate(root: Path) -> list[str]:
    errors = []
    for rel in REQUIRED:
        file = root / rel
        if not file.is_file():
            errors.append(f"Missing universal agent entrypoint: {rel}")
            continue
        content = file.read_text(encoding="utf-8")
        if rel in {"AGENTS.md", "docs/knowledge/agent-rules.md", "CLAUDE.md", "GEMINI.md", ".github/AGENTS.md"} and MARKER not in content:
            errors.append(f"Missing universal continuity policy: {rel}")
        if CHECKLIST_MARKER not in content:
            errors.append(f"Missing mandatory checklist policy: {rel}")
    for rel in ("AGENTS.md", "docs/knowledge/agent-rules.md"):
        file = root / rel
        if not file.is_file():
            continue
        content = file.read_text(encoding="utf-8")
        for token in ("MCP/Remote Control", "GitHub CLI/API", "lease/fencing", "nao contornar MFA", "checkpoint"):
            if token not in content:
                errors.append(f"Missing safeguard {token}: {rel}")
    state = root / "scripts/agent_task_state.py"
    if not state.is_file():
        errors.append("Missing agent task checklist state machine")
    else:
        content = state.read_text(encoding="utf-8")
        for token in ("MANDATORY_AGENT_CHECKLIST_V1", "set_checklist", "complete_checklist_step",
                      "_require_checklist_complete", "add_checklist_step", "CANONICAL_CONTROLLER_RUNTIME_DIR"):
            if token not in content:
                errors.append(f"Missing checklist state invariant: {token}")
    workflow = root / ".github/workflows/mandatory-validation-gate.yml"
    if not workflow.is_file() or "tests.test_mandatory_agent_checklist" not in workflow.read_text(encoding="utf-8"):
        errors.append("Required PR validation must run mandatory checklist regressions")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=ROOT)
    args = parser.parse_args()
    errors = validate(args.root)
    if errors:
        print("\n".join(errors), file=sys.stderr)
        return 1
    print("Universal agent checklist and guard surfaces present")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
