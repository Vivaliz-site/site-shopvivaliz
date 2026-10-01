#!/usr/bin/env python3
"""Fail closed when nonstop task-continuity enforcement drifts."""
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
MARKER = "TASK_CONTINUITY_ENFORCEMENT_V3"
CODEX_MARKER = "CODEX_LAST_RESORT_V1"
RESUME_ORDER_MARKER = "CHATGPT_RESUME_ORDER_V5"
RESUME_ORDER_POLICY = "chatgpt_common_then_work_then_cli"
DETACHED_MARKER = "DETACHED_CONTINUATION_EXECUTOR_V6"
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
DISPATCHER = ROOT / "scripts" / "task_resume_dispatcher.py"
GEMINI_CONTROLLER = ROOT / "scripts" / "gemini_24x7_controller.py"
GEMINI_CONTROLLER_TEST = ROOT / "tests" / "test_gemini_24x7_controller.py"
DISPATCHER_TEST = ROOT / "tests" / "test_task_resume_dispatcher.py"
QUEUE = ROOT / "scripts" / "task_resume_queue.py"
QUEUE_TEST = ROOT / "tests" / "test_task_resume_queue.py"
QUEUE_MARKER = "RESUME_QUEUE_CERTIFICATION_V12"
LOOP = ROOT / "scripts" / "autonomous-agent-loop.sh"
E2E_PROBE = ROOT / "scripts" / "task_continuity_e2e.py"
E2E_PROBE_TEST = ROOT / "tests" / "test_task_continuity_e2e.py"
E2E_WORKFLOW = ROOT / ".github" / "workflows" / "task-continuity-production-e2e.yml"
E2E_MARKER = "DETACHED_TASK_RECOVERY_E2E_V7"
E2E_VERIFICATION = "continuity_e2e_pass"
GLOBAL_MARKER = "GLOBAL_TASK_CONTINUITY_V8"
CHECKPOINT_FIRST_MARKER = "CHECKPOINT_FIRST_V9"
GLOBAL_TEST = ROOT / "tests" / "test_global_task_continuity_v8.py"

errors: list[str] = []

agents_path = ROOT / "AGENTS.md"
if not agents_path.is_file():
    errors.append("missing AGENTS.md")
else:
    agents_text = agents_path.read_text(encoding="utf-8", errors="replace")
    for token in (GLOBAL_MARKER, CHECKPOINT_FIRST_MARKER, "agent_task_state.py start", "antes da primeira"):
        if token not in agents_text:
            errors.append(f"AGENTS.md: missing {token}")

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
        "SHOPVIVALIZ_RESUME_RESULT_MODE",
        "task_state_signature",
        "task_state_advanced",
        "SHOPVIVALIZ_RESUME_BACKGROUND",
        "BACKGROUND_ORDER=(gemini)",
        "background_paid_fallback_forbidden=true",
    ):
        if token not in fallback_text:
            errors.append(f"scripts/autonomous-provider-failover.sh: missing {token}")

if not STATE.is_file():
    errors.append("missing scripts/agent_task_state.py")
else:
    state_text = STATE.read_text(encoding="utf-8", errors="replace")
    for token in ("READY_TO_COMPLETE", "BLOCKED_EXTERNAL", "alternatives_attempted", "next_action", "DEFAULT_REPOSITORY", "repository"):
        if token not in state_text:
            errors.append(f"scripts/agent_task_state.py: missing {token}")

if not WATCHDOG.is_file():
    errors.append("missing scripts/task_continuation_watchdog.py")
else:
    watchdog_text = WATCHDOG.read_text(encoding="utf-8", errors="replace")
    for token in (
        "auto_resume",
        "stale_seconds",
        "resume_queue.REQUESTS_FILE",
        "chatgpt_common",
        "chatgpt_work",
        "final_fallback",
        RESUME_ORDER_POLICY,
        "repository",
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

if not DISPATCHER.is_file():
    errors.append("missing scripts/task_resume_dispatcher.py")
else:
    dispatcher_text = DISPATCHER.read_text(encoding="utf-8", errors="replace")
    for token in (
        "_resume-executions.jsonl",
        "SHOPVIVALIZ_RESUME_RESULT_MODE",
        "task_state",
        "cli_last",
        "max_requests",
        "ALLOWED_REPOSITORIES",
        "\"repo\"",
        "\"clone\"",
    ):
        if token not in dispatcher_text:
            errors.append(f"scripts/task_resume_dispatcher.py: missing {token}")

if not GEMINI_CONTROLLER.is_file():
    errors.append("missing scripts/gemini_24x7_controller.py")
else:
    controller_text = GEMINI_CONTROLLER.read_text(encoding="utf-8", errors="replace")
    for token in ("acquire_lease", "duplicate_suppressed", "lease_recovered", "chatgpt_continuity_nudge_dispatcher", "task_resume_dispatcher"):
        if token not in controller_text:
            errors.append(f"scripts/gemini_24x7_controller.py: missing {token}")
if not GEMINI_CONTROLLER_TEST.is_file():
    errors.append("missing tests/test_gemini_24x7_controller.py")

if not DISPATCHER_TEST.is_file():
    errors.append("missing tests/test_task_resume_dispatcher.py")

if not QUEUE.is_file():
    errors.append("missing scripts/task_resume_queue.py")
else:
    queue_text = QUEUE.read_text(encoding="utf-8", errors="replace")
    for token in (
        "_resume-requests-archive.jsonl",
        "_resume-queue.lock",
        "checkpoint_fingerprint",
        "certify_queue",
        "compact_queue",
        "fcntl.LOCK_EX",
        "os.replace",
        "os.fsync",
    ):
        if token not in queue_text:
            errors.append(f"scripts/task_resume_queue.py: missing {token}")

if not QUEUE_TEST.is_file():
    errors.append("missing tests/test_task_resume_queue.py")

continuity_docs = ROOT / "docs" / "knowledge" / "task-continuity.md"
if not continuity_docs.is_file():
    errors.append("missing docs/knowledge/task-continuity.md")
else:
    continuity_docs_text = continuity_docs.read_text(encoding="utf-8", errors="replace")
    if DETACHED_MARKER not in continuity_docs_text:
        errors.append(f"docs/knowledge/task-continuity.md: missing {DETACHED_MARKER}")
    if E2E_MARKER not in continuity_docs_text:
        errors.append(f"docs/knowledge/task-continuity.md: missing {E2E_MARKER}")
    if E2E_VERIFICATION not in continuity_docs_text:
        errors.append(f"docs/knowledge/task-continuity.md: missing {E2E_VERIFICATION}")
    if QUEUE_MARKER not in continuity_docs_text:
        errors.append(f"docs/knowledge/task-continuity.md: missing {QUEUE_MARKER}")
    if GLOBAL_MARKER not in continuity_docs_text:
        errors.append(f"docs/knowledge/task-continuity.md: missing {GLOBAL_MARKER}")

if not E2E_PROBE.is_file():
    errors.append("missing scripts/task_continuity_e2e.py")
else:
    probe_text = E2E_PROBE.read_text(encoding="utf-8", errors="replace")
    for token in (E2E_MARKER, E2E_VERIFICATION, "--repository", "\"repository\": repository"):
        if token not in probe_text:
            errors.append(f"scripts/task_continuity_e2e.py: missing {token}")
    for forbidden in ("task_continuation_watchdog", "task_resume_dispatcher", "run_once("):
        if forbidden in probe_text:
            errors.append(f"scripts/task_continuity_e2e.py: must not reference {forbidden}")

if not E2E_PROBE_TEST.is_file():
    errors.append("missing tests/test_task_continuity_e2e.py")

if not E2E_WORKFLOW.is_file():
    errors.append("missing .github/workflows/task-continuity-production-e2e.yml")
else:
    e2e_workflow_text = E2E_WORKFLOW.read_text(encoding="utf-8", errors="replace")
    for token in ("scripts/task_continuity_e2e.py", "shopvivaliz-a1-deploy", "TARGET_REPOSITORY", "--repository"):
        if token not in e2e_workflow_text:
            errors.append(f".github/workflows/task-continuity-production-e2e.yml: missing {token}")

audit_policy = ROOT / "AUDIT_POLICY.md"
if not audit_policy.is_file() or E2E_VERIFICATION not in audit_policy.read_text(encoding="utf-8", errors="replace"):
    errors.append(f"AUDIT_POLICY.md: missing {E2E_VERIFICATION}")

if not LOOP.is_file():
    errors.append("missing scripts/autonomous-agent-loop.sh")
else:
    loop_text = LOOP.read_text(encoding="utf-8", errors="replace")
    try:
        watchdog_pos = loop_text.index("task_continuation_watchdog.py")
        dispatcher_pos = loop_text.index("task_resume_dispatcher.py")
        worker_pos = loop_text.index("agent-operations-worker.py")
        if not (watchdog_pos < dispatcher_pos < worker_pos):
            errors.append("autonomous loop must run watchdog -> dispatcher -> worker")
    except ValueError:
        errors.append("autonomous loop missing continuity execution stages")

if not GLOBAL_TEST.is_file():
    errors.append("missing tests/test_global_task_continuity_v8.py")

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
    if "tests.test_task_resume_dispatcher" not in governance:
        errors.append("repository governance does not execute detached-resume regression tests")
    if "tests.test_task_resume_queue" not in governance:
        errors.append("repository governance does not execute resume-queue certification tests")
    if "chatgpt-continuity-bridge-worker-test.mjs" not in governance:
        errors.append("repository governance does not execute ChatGPT bridge-worker regression")
    if "tests.test_global_task_continuity_v8" not in governance:
        errors.append("repository governance does not execute global-continuity regression tests")

if errors:
    print("TASK CONTINUITY ENFORCEMENT: FAIL", file=sys.stderr)
    for error in errors:
        print(f"- {error}", file=sys.stderr)
    raise SystemExit(1)

print("TASK CONTINUITY ENFORCEMENT: OK")
