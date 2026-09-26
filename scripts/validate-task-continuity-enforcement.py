#!/usr/bin/env python3
"""Fail closed when nonstop task-continuity enforcement drifts."""
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
MARKER = "TASK_CONTINUITY_ENFORCEMENT_V3"
NORMATIVE = (
    ROOT / "REGRAS-AGENTES-CENTRALIZADAS.md",
    ROOT / "AGENTS.md",
    ROOT / "AI-TO-CLI-PROTOCOL.md",
    ROOT / "docs" / "knowledge" / "agent-rules.md",
    ROOT / "CLAUDE.md",
    ROOT / "GEMINI.md",
    ROOT / "AGENTS.override.md",
    ROOT / ".github" / "copilot-instructions.md",
)
REQUIRED_TOKENS = (
    MARKER,
    "BLOCKED_EXTERNAL",
    "CONCLUIDO",
    "agent_task_state.py",
)
STATE = ROOT / "scripts" / "agent_task_state.py"
TEST = ROOT / "tests" / "test_task_continuity_enforcement.py"
GOVERNANCE = ROOT / "scripts" / "repository-governance-validate.sh"

errors: list[str] = []
for path in NORMATIVE:
    if not path.is_file():
        errors.append(f"missing normative entrypoint: {path.relative_to(ROOT)}")
        continue
    text = path.read_text(encoding="utf-8", errors="replace")
    for token in REQUIRED_TOKENS:
        if token not in text:
            errors.append(f"{path.relative_to(ROOT)}: missing {token}")

if not STATE.is_file():
    errors.append("missing scripts/agent_task_state.py")
else:
    state_text = STATE.read_text(encoding="utf-8", errors="replace")
    for token in ("READY_TO_COMPLETE", "BLOCKED_EXTERNAL", "alternatives_attempted", "next_action"):
        if token not in state_text:
            errors.append(f"scripts/agent_task_state.py: missing {token}")

if not TEST.is_file():
    errors.append("missing tests/test_task_continuity_enforcement.py")

if not GOVERNANCE.is_file():
    errors.append("missing scripts/repository-governance-validate.sh")
else:
    governance = GOVERNANCE.read_text(encoding="utf-8", errors="replace")
    if "python3 scripts/validate-task-continuity-enforcement.py" not in governance:
        errors.append("repository governance does not execute task-continuity validator")
    if "tests.test_task_continuity_enforcement" not in governance:
        errors.append("repository governance does not execute task-continuity regression tests")

if errors:
    print("TASK CONTINUITY ENFORCEMENT: FAIL", file=sys.stderr)
    for error in errors:
        print(f"- {error}", file=sys.stderr)
    raise SystemExit(1)

print("TASK CONTINUITY ENFORCEMENT: OK")
