#!/usr/bin/env python3
"""Fail-closed helpers for the canonical ShopVivaliz task queue."""
from __future__ import annotations

import json
import os
import tempfile
from contextlib import contextmanager
from copy import deepcopy
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator

PROJECT_ROOT = Path(__file__).resolve().parents[1]
ROOT_QUEUE_FILE = PROJECT_ROOT / "tasks-queue.json"
CANONICAL_SCHEMA_VERSION = 2
ALLOWED_STATES = frozenset({"pending", "running", "blocked", "failed", "completed_verified"})
PRIORITY_ORDER = {"high": 0, "medium": 1, "low": 2}
REQUIRED_COMPLETION_EVIDENCE = (
    "run_id",
    "commit_sha",
    "pull_request",
    "artifact_digest",
    "verified_at",
)


class QueueValidationError(ValueError):
    """Raised when the queue is not in the canonical, evidence-safe schema."""


class QueueMutationRetiredError(RuntimeError):
    """Raised when runtime code attempts to mutate the retired queue."""


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def task_identifier(task: dict[str, Any]) -> str:
    return str(task.get("task_id") or task.get("id") or "").strip()


def _validate_completed_task(task: dict[str, Any], task_id: str) -> None:
    verification = task.get("verification")
    if not isinstance(verification, dict):
        raise QueueValidationError(
            f"Task {task_id} uses completed_verified without a verification object"
        )

    missing = [key for key in REQUIRED_COMPLETION_EVIDENCE if not verification.get(key)]
    if missing:
        raise QueueValidationError(
            f"Task {task_id} completion evidence is missing: {', '.join(missing)}"
        )
    if verification.get("tests_passed") is not True:
        raise QueueValidationError(f"Task {task_id} completion does not prove tests_passed=true")
    if verification.get("read_back_verified") is not True:
        raise QueueValidationError(
            f"Task {task_id} completion does not prove read_back_verified=true"
        )

    last_result = task.get("last_result")
    if not isinstance(last_result, dict) or last_result.get("success") is not True:
        raise QueueValidationError(
            f"Task {task_id} completion does not have a successful last_result"
        )


def _canonical_document(data: Any) -> dict[str, Any]:
    if not isinstance(data, dict):
        raise QueueValidationError("Queue document must be a JSON object")

    if "tasks" not in data:
        if "queue" in data:
            raise QueueValidationError(
                "Legacy top-level 'queue' schema is retired; use schema v2 with 'metadata' and 'tasks'"
            )
        raise QueueValidationError("Queue document is missing the canonical 'tasks' array")

    tasks = data.get("tasks")
    if not isinstance(tasks, list):
        raise QueueValidationError("Queue field 'tasks' must be an array")

    compatibility_queue = data.get("queue")
    if compatibility_queue is not None and compatibility_queue is not tasks:
        raise QueueValidationError("Compatibility 'queue' view must be an in-memory alias of canonical 'tasks'")

    metadata = data.get("metadata")
    if not isinstance(metadata, dict):
        raise QueueValidationError("Queue document is missing metadata")
    if metadata.get("schema_version") != CANONICAL_SCHEMA_VERSION:
        raise QueueValidationError(
            f"Unsupported queue schema version: {metadata.get('schema_version')!r}"
        )

    declared_states = metadata.get("allowed_states")
    if not isinstance(declared_states, list) or set(declared_states) != ALLOWED_STATES:
        raise QueueValidationError(
            "metadata.allowed_states must exactly match the canonical evidence-safe states"
        )

    seen_ids: set[str] = set()
    for index, task in enumerate(tasks, start=1):
        if not isinstance(task, dict):
            raise QueueValidationError(f"Task at index {index} must be an object")
        task_id = task_identifier(task)
        if not task_id:
            raise QueueValidationError(f"Task at index {index} has no id or task_id")
        if task_id in seen_ids:
            raise QueueValidationError(f"Duplicate task identifier: {task_id}")
        seen_ids.add(task_id)

        status = str(task.get("status", "")).strip()
        if status not in ALLOWED_STATES:
            raise QueueValidationError(
                f"Task {task_id} has unsupported status {status!r}; 'completed' is never valid"
            )
        priority = str(task.get("priority", "medium")).strip().lower()
        if priority not in PRIORITY_ORDER:
            raise QueueValidationError(f"Task {task_id} has unsupported priority {priority!r}")
        if status == "completed_verified":
            _validate_completed_task(task, task_id)

    canonical = deepcopy(data)
    canonical.pop("queue", None)
    return canonical


def validate_queue(data: Any) -> dict[str, Any]:
    """Validate and return a deep-copied canonical queue document."""
    return _canonical_document(data)


def _runtime_view(canonical: dict[str, Any]) -> dict[str, Any]:
    """Expose a temporary `queue` alias for legacy readers without persisting it."""
    runtime = deepcopy(canonical)
    runtime["queue"] = runtime["tasks"]
    return runtime


def load_queue(path: Path | None = None) -> dict[str, Any]:
    queue_path = Path(path) if path is not None else ROOT_QUEUE_FILE
    if not queue_path.is_file():
        raise QueueValidationError(f"Canonical queue file does not exist: {queue_path}")
    try:
        raw = json.loads(queue_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise QueueValidationError(f"Invalid queue JSON: {exc}") from exc
    return _runtime_view(validate_queue(raw))


@contextmanager
def _exclusive_lock(lock_path: Path) -> Iterator[None]:
    try:
        import fcntl
    except ImportError as exc:  # pragma: no cover - production is Linux
        raise RuntimeError("Atomic queue writes require POSIX file locking") from exc

    lock_path.parent.mkdir(parents=True, exist_ok=True)
    with lock_path.open("a+", encoding="utf-8") as lock_handle:
        fcntl.flock(lock_handle.fileno(), fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(lock_handle.fileno(), fcntl.LOCK_UN)


def save_queue(
    data: dict[str, Any],
    path: Path | None = None,
    *,
    reviewed_change: bool = False,
) -> None:
    """Atomically write a reviewed queue change; runtime mutation is retired by default."""
    if reviewed_change is not True:
        raise QueueMutationRetiredError(
            "Direct task queue mutation is retired; edit tasks-queue.json in a reviewed pull request"
        )
    queue_path = Path(path) if path is not None else ROOT_QUEUE_FILE
    canonical = validate_queue(data)
    canonical["metadata"]["updated_at"] = utc_now()
    payload = json.dumps(canonical, indent=2, ensure_ascii=False) + "\n"
    queue_path.parent.mkdir(parents=True, exist_ok=True)
    lock_path = queue_path.with_name(f".{queue_path.name}.lock")

    with _exclusive_lock(lock_path):
        mode = 0o644
        if queue_path.exists():
            mode = queue_path.stat().st_mode & 0o777

        fd, temporary_name = tempfile.mkstemp(
            prefix=f".{queue_path.name}.", suffix=".tmp", dir=queue_path.parent
        )
        temporary_path = Path(temporary_name)
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as handle:
                handle.write(payload)
                handle.flush()
                os.fsync(handle.fileno())
            os.chmod(temporary_path, mode)
            os.replace(temporary_path, queue_path)
            directory_fd = os.open(queue_path.parent, os.O_RDONLY)
            try:
                os.fsync(directory_fd)
            finally:
                os.close(directory_fd)
        finally:
            temporary_path.unlink(missing_ok=True)


def next_task_id(queue: dict[str, Any]) -> str:
    numeric_ids: list[int] = []
    for task in queue.get("tasks", []):
        task_id = task_identifier(task)
        if not task_id.startswith("task-"):
            continue
        suffix = task_id.split("-", 1)[1]
        if suffix.isdigit():
            numeric_ids.append(int(suffix))
    return f"task-{max(numeric_ids or [0]) + 1:03d}"


def upsert_task(
    queue: dict[str, Any], task: dict[str, Any], *, match_on_title: bool = True
) -> tuple[dict[str, Any], bool]:
    """Upsert a non-completed task while preserving the canonical document."""
    tasks = queue.get("tasks")
    compatibility_queue = queue.get("queue")
    if not isinstance(tasks, list) or compatibility_queue is not tasks:
        raise QueueValidationError("Queue must come from load_queue() before it can be updated")

    candidate = deepcopy(task)
    status = str(candidate.get("status", "pending")).strip()
    if status not in ALLOWED_STATES or status == "completed_verified":
        raise QueueValidationError(
            "upsert_task cannot create completion; completion requires independently verified evidence"
        )

    title = str(candidate.get("title", "")).strip().lower()
    candidate_id = task_identifier(candidate)
    for existing in tasks:
        same_id = candidate_id and task_identifier(existing) == candidate_id
        same_title = (
            match_on_title
            and title
            and str(existing.get("title", "")).strip().lower() == title
        )
        if same_id or same_title:
            existing.update({key: value for key, value in candidate.items() if value is not None})
            validate_queue(queue)
            return existing, False

    if not candidate_id:
        candidate["id"] = next_task_id(queue)
    candidate.setdefault("created_at", utc_now())
    candidate.setdefault("status", "pending")
    candidate.setdefault("priority", "medium")
    tasks.append(candidate)
    validate_queue(queue)
    return candidate, True


def executable_pending_tasks(queue: dict[str, Any]) -> list[dict[str, Any]]:
    tasks = [task for task in queue.get("tasks", []) if task.get("status") == "pending"]
    tasks.sort(
        key=lambda task: (
            int(task.get("queue_rank", 9999)),
            PRIORITY_ORDER.get(str(task.get("priority", "medium")), 99),
            str(task.get("created_at", "")),
            task_identifier(task),
        )
    )
    return tasks


def queue_summary(queue: dict[str, Any]) -> dict[str, int]:
    summary = {state: 0 for state in sorted(ALLOWED_STATES)}
    for task in queue.get("tasks", []):
        summary[str(task.get("status"))] += 1
    summary["total"] = len(queue.get("tasks", []))
    return summary
