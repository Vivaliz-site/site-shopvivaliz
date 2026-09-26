#!/usr/bin/env python3
"""Fail closed when nonstop task-continuity enforcement drifts."""
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
MARKER = "TASK_CONTINUITY_ENFORCEMENT_V3"
CODEX_MARKER = "CODEX_LAST_RESORT_V1"
NORMATIVE = (
    ROOT / "AGENTS.md",
    ROOT / "AI-TO-CLI-PROTOCOL.md",
    ROOT / "docs" / "knowledge" / "agent-rules.md",
    ROOT / "CLAUDE.md",
    ROOT / "GEMINI.md",
    ROOT / ".github" / "copilot-instructions.md",
)
CODEX_NORMATIVE = (
    ROOT / "AGENTS.md",
    ROOT / "docs" / "knowledge" / "task-continuity.md",
    ROOT / "docs" / "knowledge" / "agent-rules.md",
    ROOT / "docs" / "knowledge" / "dev-agent-briefing.md",
    ROOT / "CLAUDE.md",
    ROOT / "GEMINI.md",
    ROOT / "GEPETO-POLICY.md",
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
FALLBACK = ROOT / "scripts" / "autonomous-provider-failover.sh"

errors: list[str] = []
for path in NORMATIVE:
    if not path.is_file():
        errors.append(f"missing normative entrypoint: {path.relative_to(ROOT)}")
        continue
    text = path.read_text(encoding="utf-8", errors="replace")
    for token in REQUIRED_TOKENS:
        if token not in text:
            errors.append(f"{path.relative_to(ROOT)}: missing {token}")

for path in CODEX_NORMATIVE:
    if not path.is_file():
        errors.append(f"missing Codex policy entrypoint: {path.relative_to(ROOT)}")
        continue
    text = path.read_text(encoding="utf-8", errors="replace")
    if CODEX_MARKER not in text:
        errors.append(f"{path.relative_to(ROOT)}: missing {CODEX_MARKER}")

if not FALLBACK.is_file():
    errors.append("missing scripts/autonomous-provider-failover.sh")
else:
    fallback_text = FALLBACK.read_text(encoding="utf-8", errors="replace")
    for token in (
        "ORDER=(gemini anthropic codex)",
        "SHOPVIVALIZ_TASK_ID",
        "agent_task_state.py progress",
        "exit 75",
    ):
        if token not in fallback_text:
            errors.append(f"scripts/autonomous-provider-failover.sh: missing {token}")

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
