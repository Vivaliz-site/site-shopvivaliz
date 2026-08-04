#!/usr/bin/env python3
"""Fail-closed compatibility runtime for explicitly configured executors.

The runtime never changes the queue. Every execution receives a unique run ID,
an expected evidence path and the current commit SHA. Evidence from an earlier
cycle cannot be reused: the file must be new, recent and contain matching
``run_id``, ``task_id`` and ``commit_sha`` fields plus independent verification.
"""
from __future__ import annotations

import hashlib
import json
import os
import subprocess
import sys
import uuid
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
    if not isinstance(data, dict) or not isinstance(data.get("tasks"), list):
        raise RuntimeError("invalid_queue_schema")
    return data


def current_commit_sha() -> str:
    completed = subprocess.run(
        ["git", "rev-parse", "HEAD"], cwd=ROOT, capture_output=True, text=True, check=False
    )
    value = completed.stdout.strip()
    if completed.returncode != 0 or len(value) != 40:
        raise RuntimeError("current_commit_sha_unavailable")
    return value


def executor_command(task: dict[str, Any]) -> list[str] | None:
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


def failed(task_id: str, status: str, reason: str, **extra: Any) -> dict[str, Any]:
    return {
        "task_id": task_id,
        "success": False,
        "status": status,
        "reason": reason,
        "queue_modified": False,
        "simulated": False,
        **extra,
    }


def execute_real(task: dict[str, Any], commit_sha: str) -> dict[str, Any]:
    command = executor_command(task)
    task_id = str(task.get("task_id", ""))
    if not command:
        return failed(task_id, "blocked", "real_executor_not_configured")

    run_id = str(uuid.uuid4())
    EVIDENCE_DIR.mkdir(parents=True, exist_ok=True)
    evidence = EVIDENCE_DIR / f"{task_id}-{run_id}.json"
    if evidence.exists():
        return failed(task_id, "failed", "evidence_path_collision", run_id=run_id)

    started = datetime.now(timezone.utc)
    env = os.environ.copy()
    env.update({
        "SHOPVIVALIZ_EXECUTION_RUN_ID": run_id,
        "SHOPVIVALIZ_EVIDENCE_PATH": str(evidence),
        "SHOPVIVALIZ_EXPECTED_COMMIT_SHA": commit_sha,
        "SHOPVIVALIZ_TASK_ID": task_id,
    })
    completed = subprocess.run(
        command,
        cwd=ROOT,
        env=env,
        capture_output=True,
        text=True,
        timeout=900,
        check=False,
    )
    if completed.returncode != 0:
        return failed(
            task_id,
            "failed",
            "executor_failed",
            run_id=run_id,
            returncode=completed.returncode,
            stderr=completed.stderr[-2000:],
        )
    if not evidence.is_file() or evidence.stat().st_size == 0:
        return failed(task_id, "failed", "evidence_missing", run_id=run_id)
    if evidence.stat().st_mtime < started.timestamp() - 1:
        return failed(task_id, "failed", "evidence_predates_execution", run_id=run_id)

    try:
        payload = json.loads(evidence.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        return failed(task_id, "failed", "evidence_unreadable", run_id=run_id, error=str(exc))
    if not isinstance(payload, dict):
        return failed(task_id, "failed", "evidence_invalid_type", run_id=run_id)

    required = {
        "task_id": task_id,
        "run_id": run_id,
        "commit_sha": commit_sha,
        "success": True,
    }
    mismatches = [key for key, value in required.items() if payload.get(key) != value]
    if mismatches:
        return failed(task_id, "failed", "evidence_identity_mismatch", run_id=run_id, fields=mismatches)
    if not payload.get("verification") or not payload.get("artifact"):
        return failed(task_id, "failed", "evidence_lacks_verification_or_artifact", run_id=run_id)

    finished = datetime.now(timezone.utc)
    return {
        "task_id": task_id,
        "run_id": run_id,
        "success": True,
        "status": "completed_verified",
        "queue_modified": False,
        "started_at": started.isoformat(),
        "finished_at": finished.isoformat(),
        "commit_sha": commit_sha,
        "evidence": str(evidence.relative_to(ROOT)),
        "evidence_sha256": hashlib.sha256(evidence.read_bytes()).hexdigest(),
        "verification": payload["verification"],
        "artifact": payload["artifact"],
        "simulated": False,
    }


def main() -> int:
    queue = load_queue()
    if str(queue.get("metadata", {}).get("status", "")) == "retired_read_only":
        result = {
            "ok": False,
            "status": "blocked",
            "queue_modified": False,
            "reason": "legacy_queue_retired",
            "replacement": "api/agent/real-work-orchestrator.php",
            "results": [],
        }
        print(json.dumps(result, ensure_ascii=False))
        return 2

    commit_sha = current_commit_sha()
    candidates = [task for task in queue.get("tasks", []) if task.get("status") != "completed_verified"]
    results = [execute_real(task, commit_sha) for task in candidates]
    output = {
        "ok": bool(results) and all(item.get("success") is True for item in results),
        "queue_modified": False,
        "commit_sha": commit_sha,
        "results": results,
    }
    print(json.dumps(output, ensure_ascii=False))
    return 0 if output["ok"] else 2


if __name__ == "__main__":
    sys.exit(main())
