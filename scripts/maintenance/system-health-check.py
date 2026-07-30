#!/usr/bin/env python3
"""Evidence-based health check for the agent automation surface.

This checker intentionally does not treat file existence, queue counters, or
self-reported agent messages as proof of health.
"""

from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "logs" / "system-health-check.json"

ACTIVE_REQUIRED = [
    ROOT / ".github/workflows/agentes-listener.yml",
    ROOT / ".github/workflows/agent-dual-validation.yml",
    ROOT / "scripts/agentes-leitor.py",
    ROOT / "docs/AGENTS-24X7-AUDIT-2026-07-25.md",
]

DEPRECATED_EXECUTORS = [
    ROOT / "scripts/real-task-executor.py",
    ROOT / "scripts/continuous-executor.py",
    ROOT / "scripts/force-execution.py",
    ROOT / "scripts/task-queue-processor.py",
]

DISABLED_MARKERS = (
    "intentionally disabled",
    "is disabled",
    "fail-closed",
)


def contains_any(path: Path, markers: tuple[str, ...]) -> bool:
    try:
        text = path.read_text(encoding="utf-8").lower()
    except OSError:
        return False
    return any(marker.lower() in text for marker in markers)


def main() -> int:
    checks: dict[str, object] = {}
    errors: list[str] = []
    warnings: list[str] = []

    for path in ACTIVE_REQUIRED:
        relative = str(path.relative_to(ROOT))
        exists = path.is_file()
        checks[f"required:{relative}"] = exists
        if not exists:
            errors.append(f"Required automation file missing: {relative}")

    listener = ROOT / ".github/workflows/agentes-listener.yml"
    if listener.is_file():
        text = listener.read_text(encoding="utf-8")
        safe_listener = (
            "permissions:" in text
            and "contents: read" in text
            and "scripts/agentes-leitor.py" in text
        )
        checks["listener_configuration"] = safe_listener
        if not safe_listener:
            errors.append("Agent listener does not match the expected safe configuration")

    for path in DEPRECATED_EXECUTORS:
        relative = str(path.relative_to(ROOT))
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

    queue = ROOT / "tasks-queue.json"
    if queue.is_file():
        try:
            payload = json.loads(queue.read_text(encoding="utf-8"))
            tasks = payload.get("queue", payload if isinstance(payload, list) else [])
            completed_without_evidence = [
                task.get("id", "unknown")
                for task in tasks
                if isinstance(task, dict)
                and task.get("status") == "completed"
                and not any(
                    task.get(field)
                    for field in ("commit_sha", "pull_request", "evidence_url", "artifact")
                )
            ]
            checks["completed_tasks_without_evidence"] = completed_without_evidence
            if completed_without_evidence:
                warnings.append(
                    "Queue contains completed tasks without commit, PR, artifact, or evidence URL"
                )
        except (OSError, ValueError) as exc:
            errors.append(f"Canonical task queue is unreadable: {exc}")

    status = "CRITICAL" if errors else ("WARNING" if warnings else "HEALTHY")
    report = {
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "status": status,
        "checks": checks,
        "errors": errors,
        "warnings": warnings,
        "note": "Health requires evidence; file existence and queue counters are insufficient.",
    }

    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0 if status == "HEALTHY" else 1


if __name__ == "__main__":
    raise SystemExit(main())
