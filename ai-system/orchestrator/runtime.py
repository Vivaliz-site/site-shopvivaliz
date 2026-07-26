#!/usr/bin/env python3
"""Legacy orchestrator compatibility entrypoint.

This module previously marked marketplace and audit tasks as completed without
calling a real API or producing evidence. It is intentionally fail-closed.
Real work must be executed by an explicitly configured executor that returns an
immutable evidence file. The queue is never modified by this compatibility
entrypoint.
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
QUEUE = ROOT / "tasks-queue.json"
EVIDENCE_DIR = ROOT / "reports" / "orchestrator-evidence"


def load_queue() -> dict[str, Any]:
    if not QUEUE.is_file():
        raise RuntimeError(f"queue_not_found:{QUEUE}")
    data = json.loads(QUEUE.read_text(encoding="utf-8"))
    if not isinstance(data, dict) or not isinstance(data.get("tasks", []), list):
        raise RuntimeError("invalid_queue_schema")
    return data


def executor_command(task: dict[str, Any]) -> list[str] | None:
    """Resolve an allow-listed external executor from environment.

    Each task type requires its own command. No shell is used and no command is
    inferred from task data.
    """
    task_id = str(task.get("task_id", "")).lower()
    mapping = {
        "shopee": "SHOPVIVALIZ_SHOPEE_EXECUTOR",
        "ml": "SHOPVIVALIZ_ML_EXECUTOR",
        "gads": "SHOPVIVALIZ_GOOGLE_ADS_EXECUTOR",
        "audit": "SHOPVIVALIZ_AUDIT_EXECUTOR",
    }
    key = next((name for token, name in mapping.items() if token in task_id), None)
    if not key:
        return None
    configured = os.environ.get(key, "").strip()
    if not configured:
        return None
    path = Path(configured)
    if not path.is_absolute() or not path.is_file() or not os.access(path, os.X_OK):
        raise RuntimeError(f"invalid_executor:{key}")
    return [str(path), "--task-id", str(task.get("task_id", ""))]


def execute_real(task: dict[str, Any]) -> dict[str, Any]:
    command = executor_command(task)
    task_id = str(task.get("task_id", ""))
    if not command:
        return {
            "task_id": task_id,
            "success": False,
            "status": "blocked",
            "reason": "real_executor_not_configured",
            "simulated": False,
        }

    started = datetime.now(timezone.utc)
    completed = subprocess.run(
        command,
        cwd=ROOT,
        capture_output=True,
        text=True,
        timeout=900,
        check=False,
    )
    evidence = EVIDENCE_DIR / f"{task_id}.json"
    if completed.returncode != 0:
        return {
            "task_id": task_id,
            "success": False,
            "status": "failed",
            "reason": "executor_failed",
            "returncode": completed.returncode,
            "stderr": completed.stderr[-2000:],
            "simulated": False,
        }
    if not evidence.is_file() or evidence.stat().st_size == 0:
        return {
            "task_id": task_id,
            "success": False,
            "status": "failed",
            "reason": "evidence_missing",
            "simulated": False,
        }
    payload = json.loads(evidence.read_text(encoding="utf-8"))
    if payload.get("task_id") != task_id or payload.get("success") is not True:
        return {
            "task_id": task_id,
            "success": False,
            "status": "failed",
            "reason": "invalid_evidence",
            "simulated": False,
        }
    return {
        "task_id": task_id,
        "success": True,
        "status": "completed",
        "started_at": started.isoformat(),
        "finished_at": datetime.now(timezone.utc).isoformat(),
        "evidence": str(evidence.relative_to(ROOT)),
        "simulated": False,
    }


def main() -> int:
    queue = load_queue()
    candidates = [task for task in queue.get("tasks", []) if task.get("status") != "completed"]
    results = [execute_real(task) for task in candidates]
    print(json.dumps({"queue_modified": False, "results": results}, ensure_ascii=False))
    return 0 if results and all(item["success"] for item in results) else 2


if __name__ == "__main__":
    sys.exit(main())
