#!/usr/bin/env python3
"""Evidence-based health check for agent automation and queue state."""
from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "artifacts" / "system-health" / "report.json"
QUEUE_PATH = ROOT / "tasks-queue.json"
ALLOWED_STATES = {"pending", "running", "blocked", "failed", "completed_verified"}
REQUIRED_COMPLETION_EVIDENCE = {"run_id", "artifact", "commit_sha", "verification"}

ACTIVE_REQUIRED = (
    ROOT / ".github/workflows/agents-hourly-deep-audit.yml",
    ROOT / ".github/workflows/repository-governance.yml",
    ROOT / "scripts/audit-agents-real-work.py",
    ROOT / "scripts/maintenance/audit_automation_changes.py",
    ROOT / "scripts/maintenance/audit_active_workflows.py",
)

DEPRECATED_EXECUTORS = (
    ROOT / "scripts/real-task-executor.py",
    ROOT / "scripts/continuous-executor.py",
    ROOT / "scripts/force-execution.py",
    ROOT / "scripts/task-queue-processor.py",
    ROOT / "scripts/autonomous-executor.py",
    ROOT / "scripts/parallel-executor.py",
    ROOT / "scripts/olist-sync-master.py",
    ROOT / "scripts/git-auto-sync-master.py",
    ROOT / "scripts/automation/pipeline_orchestrator.py",
)

DISABLED_MARKERS = ("intentionally disabled", "is disabled", "fail-closed", "retired")


def contains_any(path: Path, markers: tuple[str, ...]) -> bool:
    try:
        text = path.read_text(encoding="utf-8", errors="replace").lower()
    except OSError:
        return False
    return any(marker.lower() in text for marker in markers)


def task_identifier(task: dict[str, object]) -> str:
    return str(task.get("task_id") or task.get("id") or "unknown")


def validate_queue(payload: object) -> tuple[list[str], dict[str, object]]:
    errors: list[str] = []
    checks: dict[str, object] = {}
    if not isinstance(payload, dict):
        return ["Queue payload must be a JSON object"], checks
    tasks = payload.get("tasks")
    if not isinstance(tasks, list):
        return ["Canonical queue must contain a tasks list"], checks

    state_counts = {state: 0 for state in sorted(ALLOWED_STATES)}
    for raw_task in tasks:
        if not isinstance(raw_task, dict):
            errors.append("Queue contains a non-object task")
            continue
        task_id = task_identifier(raw_task)
        status = raw_task.get("status")
        if status not in ALLOWED_STATES:
            errors.append(f"{task_id}: invalid status {status!r}")
            continue
        state_counts[str(status)] += 1

        completed_at = raw_task.get("completed_at")
        last_result = raw_task.get("last_result")
        last_success = last_result.get("success") if isinstance(last_result, dict) else None

        if status in {"failed", "blocked", "pending", "running"} and completed_at:
            errors.append(f"{task_id}: {status} task must not have completed_at")
        if status == "failed" and last_success is not False:
            errors.append(f"{task_id}: failed task must record last_result.success=false")
        if status == "blocked" and not raw_task.get("blocked_reason"):
            errors.append(f"{task_id}: blocked task requires blocked_reason")
        if status != "completed_verified" and last_success is True:
            errors.append(f"{task_id}: success=true is only valid for completed_verified")

        if status == "completed_verified":
            evidence = raw_task.get("evidence")
            missing = sorted(
                field for field in REQUIRED_COMPLETION_EVIDENCE
                if not isinstance(evidence, dict) or not evidence.get(field)
            )
            if missing:
                errors.append(f"{task_id}: completed_verified lacks evidence fields: {', '.join(missing)}")
            if not completed_at:
                errors.append(f"{task_id}: completed_verified requires completed_at")
            if last_success is not True:
                errors.append(f"{task_id}: completed_verified requires last_result.success=true")

    checks["queue_task_count"] = len(tasks)
    checks["queue_state_counts"] = state_counts
    return errors, checks


def main() -> int:
    checks: dict[str, object] = {}
    errors: list[str] = []

    for path in ACTIVE_REQUIRED:
        relative = path.relative_to(ROOT).as_posix()
        exists = path.is_file()
        checks[f"required:{relative}"] = exists
        if not exists:
            errors.append(f"Required automation file missing: {relative}")

    for path in DEPRECATED_EXECUTORS:
        relative = path.relative_to(ROOT).as_posix()
        disabled = path.is_file() and contains_any(path, DISABLED_MARKERS)
        checks[f"deprecated_disabled:{relative}"] = disabled
        if not disabled:
            errors.append(f"Deprecated executor is not fail-closed: {relative}")

    agent_registry = ROOT / ".ai/agents.js"
    registry_safe = agent_registry.is_file() and contains_any(
        agent_registry,
        ("success: false", "execution is disabled", "fail-closed"),
    )
    checks["agent_registry_fail_closed"] = registry_safe
    if not registry_safe:
        errors.append(".ai/agents.js can still report unverified success")

    if not QUEUE_PATH.is_file():
        errors.append("Canonical task queue is missing")
    else:
        try:
            payload = json.loads(QUEUE_PATH.read_text(encoding="utf-8"))
            queue_errors, queue_checks = validate_queue(payload)
            errors.extend(queue_errors)
            checks.update(queue_checks)
        except (OSError, ValueError) as exc:
            errors.append(f"Canonical task queue is unreadable: {exc}")

    status = "HEALTHY" if not errors else "CRITICAL"
    report = {
        "schema_version": 2,
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "status": status,
        "checks": checks,
        "errors": errors,
        "note": "Health requires current workflows, fail-closed retired executors, canonical queue states, and verifiable completion evidence.",
    }
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0 if status == "HEALTHY" else 1


if __name__ == "__main__":
    raise SystemExit(main())
