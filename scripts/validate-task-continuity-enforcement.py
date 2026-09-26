#!/usr/bin/env python3
"""Fail closed when nonstop task-continuity enforcement drifts."""
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
MARKER = "TASK_CONTINUITY_ENFORCEMENT_V3"
CODEX_MARKER = "CODEX_LAST_RESORT_V1"
RESUME_ORDER_MARKER = "CHATGPT_RESUME_ORDER_V5"
RESUME_ORDER_POLICY = "chatgpt_common_then_work_then_cli"
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
WATCHDOG = ROOT / "scripts" / "task_continuation_watchdog.py"
TEST = ROOT / "tests" / "test_task_continuity_enforcement.py"
WATCHDOG_TEST = ROOT / "tests" / "test_task_continuation_watchdog.py"
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
        "SHOPVIVALIZ_RESUME_STAGE",
        "chatgpt_common",
        "chatgpt_work",
        "cli_last",
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

if not WATCHDOG.is_file():
    errors.append("missing scripts/task_continuation_watchdog.py")
else:
    watchdog_text = WATCHDOG.read_text(encoding="utf-8", errors="replace")
    for token in (
        "auto_resume",
        "stale_seconds",
        "_resume-requests.jsonl",
        "chatgpt_common",
        "chatgpt_work",
        "final_fallback",
        RESUME_ORDER_POLICY,
    ):
        if token not in watchdog_text:
            errors.append(f"scripts/task_continuation_watchdog.py: missing {token}")

routing_docs = (
    ROOT / "AGENTS.md",
    ROOT / "docs" / "knowledge" / "agent-rules.md",
    ROOT / "docs" / "knowledge" / "task-continuity.md",
    ROOT / "CLAUDE.md",
    ROOT / "GEMINI.md",
    ROOT / "GEPETO-POLICY.md",
    ROOT / ".github" / "copilot-instructions.md",
)
for path in routing_docs:
    if not path.is_file():
        errors.append(f"missing resume-order policy entrypoint: {path.relative_to(ROOT)}")
        continue
    routing_text = path.read_text(encoding="utf-8", errors="replace")
    if RESUME_ORDER_MARKER not in routing_text:
        errors.append(f"{path.relative_to(ROOT)}: missing {RESUME_ORDER_MARKER}")

worker_path = ROOT / "scripts" / "agent-operations-worker.py"
if not worker_path.is_file():
    errors.append("missing scripts/agent-operations-worker.py")
else:
    worker_text = worker_path.read_text(encoding="utf-8", errors="replace")
    for token in ("chatgpt_common", "chatgpt_work", "final_fallback", RESUME_ORDER_POLICY):
        if token not in worker_text:
            errors.append(f"scripts/agent-operations-worker.py: missing {token}")

if not TEST.is_file():
    errors.append("missing tests/test_task_continuity_enforcement.py")
if not WATCHDOG_TEST.is_file():
    errors.append("missing tests/test_task_continuation_watchdog.py")

if not GOVERNANCE.is_file():
    errors.append("missing scripts/repository-governance-validate.sh")
else:
    governance = GOVERNANCE.read_text(encoding="utf-8", errors="replace")
    if "python3 scripts/validate-task-continuity-enforcement.py" not in governance:
        errors.append("repository governance does not execute task-continuity validator")
    if "tests.test_task_continuity_enforcement" not in governance:
        errors.append("repository governance does not execute task-continuity regression tests")
    if "tests.test_task_continuation_watchdog" not in governance:
        errors.append("repository governance does not execute auto-resume regression tests")

if errors:
    print("TASK CONTINUITY ENFORCEMENT: FAIL", file=sys.stderr)
    for error in errors:
        print(f"- {error}", file=sys.stderr)
    raise SystemExit(1)

print("TASK CONTINUITY ENFORCEMENT: OK")
