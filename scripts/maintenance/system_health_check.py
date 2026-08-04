#!/usr/bin/env python3
"""Evidence-aware repository health check for agent automation.

This check validates repository configuration only. It never calls a queue with
failed/blocked tasks ``HEALTHY`` and never represents repository structure as
proof that production agents are currently running. Live runtime health is
validated by the autonomous watchdog and its immutable artifacts.
"""
from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REPORT = ROOT / "artifacts" / "system-health" / "report.json"
QUEUE_PATH = ROOT / "tasks-queue.json"
ALLOWED_STATES = {"pending", "running", "blocked", "failed", "completed_verified"}
REQUIRED_COMPLETION_EVIDENCE = {"run_id", "artifact", "commit_sha", "verification"}

ACTIVE_REQUIRED = (
    ROOT / ".github/workflows/agents-hourly-deep-audit.yml",
    ROOT / ".github/workflows/agents-audit-schedule-watchdog.yml",
    ROOT / ".github/workflows/autonomous-watchdog.yml",
    ROOT / ".github/workflows/repository-governance.yml",
    ROOT / "scripts/audit-agents-real-work.py",
    ROOT / "scripts/maintenance/audit_automation_changes.py",
    ROOT / "scripts/maintenance/audit_active_workflows.py",
    ROOT / "scripts/maintenance/system_health_check.py",
    ROOT / "api/agent/real-work-orchestrator.php",
    ROOT / "includes/production-agent-registry.php",
)

DEPRECATED_EXECUTORS: tuple[tuple[Path, Path | None], ...] = (
    (ROOT / "scripts/real-task-executor.py", None),
    (ROOT / "scripts/continuous-executor.py", ROOT / "scripts/ai/continuous_executor.py"),
    (ROOT / "scripts/force-execution.py", None),
    (ROOT / "scripts/task-queue-processor.py", None),
    (ROOT / "scripts/autonomous-executor.py", ROOT / "scripts/ai/autonomous_executor.py"),
    (ROOT / "scripts/parallel-executor.py", ROOT / "scripts/ai/parallel_executor.py"),
    (ROOT / "scripts/olist-sync-master.py", ROOT / "scripts/marketplace/olist/sync_master.py"),
    (ROOT / "oauth-auto-exec.py", ROOT / "scripts/marketplace/olist/oauth_login.py"),
    (ROOT / "scripts/git-auto-sync-master.py", None),
    (ROOT / "scripts/automation/pipeline_orchestrator.py", None),
    (ROOT / "scripts/autonomous-continuous-cycle.py", None),
)

DISABLED_MARKERS = (
    "intentionally disabled",
    "is disabled",
    "fail-closed",
    "fail closed",
    "retired",
    '"status": "blocked"',
    "never changes the queue",
)
WRAPPER_MARKERS = ("runpy.run_path", "compatibility wrapper")


def read_lower(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8", errors="replace").lower()
    except OSError:
        return ""


def contains_any(path: Path, markers: tuple[str, ...]) -> bool:
    text = read_lower(path)
    return bool(text) and any(marker.lower() in text for marker in markers)


def is_fail_closed_executor(legacy_path: Path, canonical_path: Path | None = None) -> bool:
    if not legacy_path.is_file():
        return False
    if contains_any(legacy_path, DISABLED_MARKERS):
        return True
    if canonical_path is None or not canonical_path.is_file():
        return False
    wrapper_text = read_lower(legacy_path)
    return all(marker in wrapper_text for marker in WRAPPER_MARKERS) and contains_any(canonical_path, DISABLED_MARKERS)


def task_identifier(task: dict[str, object]) -> str:
    return str(task.get("task_id") or task.get("id") or "unknown")


def validate_queue(payload: object) -> tuple[list[str], dict[str, object]]:
    """Validate task schema without claiming that unresolved tasks are healthy."""
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

    for legacy_path, canonical_path in DEPRECATED_EXECUTORS:
        relative = legacy_path.relative_to(ROOT).as_posix()
        disabled = is_fail_closed_executor(legacy_path, canonical_path)
        checks[f"deprecated_disabled:{relative}"] = disabled
        if not disabled:
            errors.append(f"Deprecated executor is not fail-closed: {relative}")

    registry = ROOT / ".ai/agents.js"
    registry_safe = registry.is_file() and contains_any(registry, ("success: false", "execution is disabled", "fail-closed"))
    checks["legacy_agent_registry_fail_closed"] = registry_safe
    if not registry_safe:
        errors.append(".ai/agents.js can still report unverified success")

    queue_mode = "missing"
    runtime_health = "NOT_OBSERVED_IN_REPOSITORY_CHECKOUT"
    if not QUEUE_PATH.is_file():
        errors.append("Legacy queue retirement marker is missing")
    else:
        try:
            payload = json.loads(QUEUE_PATH.read_text(encoding="utf-8"))
            queue_errors, queue_checks = validate_queue(payload)
            errors.extend(queue_errors)
            checks.update(queue_checks)
            metadata = payload.get("metadata", {}) if isinstance(payload, dict) else {}
            queue_mode = str(metadata.get("status", "active")) if isinstance(metadata, dict) else "active"
            checks["queue_mode"] = queue_mode
            if queue_mode == "retired_read_only":
                if payload.get("tasks") != []:
                    errors.append("Retired queue must be empty and read-only")
                if metadata.get("queue_modified_by_runtime") is not False:
                    errors.append("Retired queue must explicitly forbid runtime mutation")
            else:
                counts = queue_checks.get("queue_state_counts", {})
                unresolved = int(counts.get("failed", 0)) + int(counts.get("blocked", 0))
                verified = int(counts.get("completed_verified", 0))
                if unresolved:
                    errors.append(f"Active queue has unresolved failed/blocked tasks: {unresolved}")
                if verified == 0:
                    errors.append("Active queue has no completed_verified task with evidence")
        except (OSError, ValueError) as exc:
            errors.append(f"Queue is unreadable: {exc}")

    structural_status = "HEALTHY" if not errors else "CRITICAL"
    report = {
        "schema_version": 3,
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "status": structural_status,
        "structural_status": structural_status,
        "runtime_health": runtime_health,
        "queue_mode": queue_mode,
        "checks": checks,
        "errors": errors,
        "note": (
            "This report proves repository structure only. Live agent health requires a recent "
            "Autonomous Real Work Watchdog artifact tied to the deployed release SHA."
        ),
    }
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0 if structural_status == "HEALTHY" else 1


if __name__ == "__main__":
    raise SystemExit(main())
