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
# The repository copy is only a reviewed template. Runtime automation must use
# the mutable queue under the deployment shared directory when it is present.
ROOT_QUEUE_FILE = PROJECT_ROOT / "tasks-queue.json"
DEFAULT_RUNTIME_QUEUE_FILE = Path("/home/ubuntu/shopvivaliz-deploy/shared/tasks-queue.json")
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


def runtime_queue_file() -> Path:
    """Return the explicit or detected mutable runtime queue.

    Immutable releases deliberately keep their checked-in queue separate from
    operational state.  On the production VM the shared file therefore wins;
    on local and CI checkouts (where it does not exist) the reviewed repository
    file remains the safe read-only default.
    """
    configured_runtime = os.getenv("SHOPVIVALIZ_RUNTIME_QUEUE_FILE", "").strip()
    if configured_runtime:
        return Path(configured_runtime)
    deployment_root = Path("/home/ubuntu/shopvivaliz-deploy")
    try:
        in_production_checkout = PROJECT_ROOT.resolve().is_relative_to(deployment_root.resolve())
    except OSError:
        in_production_checkout = False
    if in_production_checkout and DEFAULT_RUNTIME_QUEUE_FILE.is_file():
        return DEFAULT_RUNTIME_QUEUE_FILE
    return ROOT_QUEUE_FILE


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


def _legacy_tasks(data: Any) -> list[dict[str, Any]] | None:
    if isinstance(data, list):
        return data if all(isinstance(item, dict) for item in data) else None
    if isinstance(data, dict) and isinstance(data.get("queue"), list):
        queue = data["queue"]
        return queue if all(isinstance(item, dict) for item in queue) else None
    return None


def migrate_legacy_queue(data: Any) -> dict[str, Any]:
    """Convert the retired queue shape into schema v2 without inferring success."""
    legacy_tasks = _legacy_tasks(data)
    if legacy_tasks is None:
        raise QueueValidationError("Queue is neither canonical schema v2 nor a migratable legacy queue")

    migrated: list[dict[str, Any]] = []
    for index, raw in enumerate(legacy_tasks, start=1):
        task = deepcopy(raw)
        task["id"] = task_identifier(task) or f"legacy-{index:03d}"
        status = str(task.get("status", "pending")).strip().lower()
        if status in {"done", "completed", "success", "succeeded"}:
            status = "failed"
            task["blocked_reason"] = "legacy completion had no independently verified evidence"
            task["last_result"] = {"success": False, "reason": task["blocked_reason"]}
        elif status not in ALLOWED_STATES:
            status = "failed"
            task["blocked_reason"] = "legacy task used an unsupported execution state"
            task["last_result"] = {"success": False, "reason": task["blocked_reason"]}
        task["status"] = status
        task["priority"] = str(task.get("priority", "medium")).strip().lower()
        if task["priority"] not in PRIORITY_ORDER:
            task["priority"] = "medium"
        task.setdefault("created_at", utc_now())
        if status == "failed":
            task.setdefault("last_result", {"success": False, "reason": "legacy migration"})
        if status == "blocked":
            task.setdefault("blocked_reason", "legacy task requires renewed evidence")
        migrated.append(task)

    document = {
        "metadata": {
            "schema_version": CANONICAL_SCHEMA_VERSION,
            "generated_at": utc_now(),
            "updated_at": utc_now(),
            "status": "runtime_migrated",
            "description": "Canonical runtime queue migrated from the legacy format with no inferred completions.",
            "allowed_states": sorted(ALLOWED_STATES),
            "migration": "legacy_queue_to_schema_v2",
        },
        "tasks": migrated,
    }
    return validate_queue(document)


def validate_queue(data: Any) -> dict[str, Any]:
    """Validate and return a deep-copied canonical queue document."""
    return _canonical_document(data)


def _runtime_view(canonical: dict[str, Any]) -> dict[str, Any]:
    """Expose a temporary `queue` alias for legacy readers without persisting it."""
    runtime = deepcopy(canonical)
    runtime["queue"] = runtime["tasks"]
    return runtime


def load_queue(path: Path | None = None) -> dict[str, Any]:
    queue_path = Path(path) if path is not None else runtime_queue_file()
    if not queue_path.is_file():
        raise QueueValidationError(f"Canonical queue file does not exist: {queue_path}")
    try:
        raw = json.loads(queue_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise QueueValidationError(f"Invalid queue JSON: {exc}") from exc
    try:
        return _runtime_view(validate_queue(raw))
    except QueueValidationError:
        migrated = migrate_legacy_queue(raw)
        save_queue(migrated, path=queue_path, runtime_actor="legacy-queue-migration")
        return _runtime_view(migrated)


@contextmanager
def _exclusive_lock(lock_path: Path) -> Iterator[None]:
    try:
        import fcntl
    except ImportError:  # pragma: no cover - exercised by the Windows workstation
        import msvcrt

        lock_path.parent.mkdir(parents=True, exist_ok=True)
        with lock_path.open("a+", encoding="utf-8") as lock_handle:
            lock_handle.seek(0)
            if lock_handle.read(1) == "":
                lock_handle.seek(0)
                lock_handle.write("0")
                lock_handle.flush()
            lock_handle.seek(0)
            msvcrt.locking(lock_handle.fileno(), msvcrt.LK_LOCK, 1)
            try:
                yield
            finally:
                lock_handle.seek(0)
                msvcrt.locking(lock_handle.fileno(), msvcrt.LK_UNLCK, 1)
        return

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
    runtime_actor: str | None = None,
) -> None:
    """Atomically write a reviewed change or the explicit shared runtime queue."""
    queue_path = Path(path) if path is not None else runtime_queue_file()
    configured_runtime = os.getenv("SHOPVIVALIZ_RUNTIME_QUEUE_FILE", "").strip()
    allowed_runtime = Path(configured_runtime) if configured_runtime else DEFAULT_RUNTIME_QUEUE_FILE
    try:
        is_runtime_target = queue_path.resolve() == allowed_runtime.resolve()
    except OSError:
        is_runtime_target = False
    if reviewed_change is not True and not (runtime_actor and is_runtime_target):
        raise QueueMutationRetiredError(
            "Queue mutation requires a reviewed change or an explicit shared runtime actor"
        )
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
            if os.name == "posix":
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
