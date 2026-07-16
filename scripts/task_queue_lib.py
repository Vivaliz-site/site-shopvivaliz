#!/usr/bin/env python3
"""Shared helpers for the ShopVivaliz autonomous task queue."""
from __future__ import annotations

import json
from copy import deepcopy
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT_QUEUE_FILE = Path("tasks-queue.json")
LEGACY_QUEUE_FILE = Path("logs/tasks-queue.json")

DEFAULT_QUEUE = {
    "version": "1.1",
    "created_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
    "queue": [],
}

PRIORITY_ORDER = {"high": 0, "medium": 1, "low": 2}


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def _task_from_external(item: dict[str, Any], index: int) -> dict[str, Any]:
    task_id = str(item.get("id") or item.get("task_id") or f"external-{index:03d}")
    priority = str(item.get("priority") or "medium").lower()
    if priority not in PRIORITY_ORDER:
        priority = "medium"

    normalized = {
        "id": task_id,
        "title": item.get("title") or item.get("action") or task_id,
        "description": item.get("description") or "",
        "priority": priority,
        "status": item.get("status") or "pending",
        "created_at": item.get("created_at") or utc_now(),
        "source": item.get("source") or "external-task-format",
        "tags": item.get("tags") or [],
    }

    if "requires_env" in item:
        normalized["requires_env"] = item.get("requires_env") or []
    elif "requires_secrets" in item:
        normalized["requires_env"] = item.get("requires_secrets") or []

    if "requires_human_approval" in item:
        normalized["requires_human_approval"] = bool(item.get("requires_human_approval"))

    if "requires_manual_access" in item:
        normalized["requires_manual_access"] = bool(item.get("requires_manual_access"))

    for key in (
        "phase",
        "queue_rank",
        "type",
        "action",
        "assigned_to",
        "estimated_hours",
        "metadata",
    ):
        if key in item:
            normalized[key] = item.get(key)

    return normalized


def _normalize(data: Any) -> dict[str, Any]:
    if isinstance(data, dict) and isinstance(data.get("queue"), list):
        normalized = deepcopy(data)
    elif isinstance(data, dict) and isinstance(data.get("tasks"), list):
        normalized = {
            "queue": [_task_from_external(task, index) for index, task in enumerate(data.get("tasks", []), start=1)],
            "metadata": deepcopy(data.get("metadata", {})),
        }
    elif isinstance(data, list):
        normalized = {"queue": data}
    else:
        normalized = {"queue": []}

    normalized.setdefault("version", DEFAULT_QUEUE["version"])
    normalized.setdefault("created_at", DEFAULT_QUEUE["created_at"])

    for task in normalized["queue"]:
        task.setdefault("priority", "medium")
        task.setdefault("status", "pending")
        task.setdefault("created_at", utc_now())

    return normalized


def _read_queue(path: Path) -> dict[str, Any] | None:
    if not path.exists():
        return None
    return _normalize(json.loads(path.read_text(encoding="utf-8")))


def load_queue() -> dict[str, Any]:
    root_data = _read_queue(ROOT_QUEUE_FILE)
    if root_data:
        return root_data

    legacy_data = _read_queue(LEGACY_QUEUE_FILE)
    if legacy_data:
        save_queue(legacy_data)
        return legacy_data

    save_queue(DEFAULT_QUEUE)
    return _normalize(DEFAULT_QUEUE)


def save_queue(data: dict[str, Any]) -> None:
    normalized = _normalize(data)
    for path in (ROOT_QUEUE_FILE, LEGACY_QUEUE_FILE):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(
            json.dumps(normalized, indent=2, ensure_ascii=False) + "\n",
            encoding="utf-8",
        )


def next_task_id(queue: dict[str, Any]) -> str:
    numeric_ids: list[int] = []
    for task in queue.get("queue", []):
        task_id = str(task.get("id", ""))
        if not task_id.startswith("task-"):
            continue
        suffix = task_id.split("-", 1)[1]
        if suffix.isdigit():
            numeric_ids.append(int(suffix))
    return f"task-{max(numeric_ids or [0]) + 1:03d}"


def upsert_task(queue: dict[str, Any], task: dict[str, Any], *, match_on_title: bool = True) -> tuple[dict[str, Any], bool]:
    title = task.get("title", "").strip().lower()
    task_id = task.get("id")

    for existing in queue.get("queue", []):
        same_id = task_id and existing.get("id") == task_id
        same_title = match_on_title and title and existing.get("title", "").strip().lower() == title
        if same_id or same_title:
            existing.update({k: v for k, v in task.items() if v is not None})
            return existing, False

    new_task = deepcopy(task)
    new_task.setdefault("id", next_task_id(queue))
    new_task.setdefault("created_at", utc_now())
    new_task.setdefault("status", "pending")
    new_task.setdefault("priority", "medium")
    queue.setdefault("queue", []).append(new_task)
    return new_task, True


def executable_pending_tasks(queue: dict[str, Any]) -> list[dict[str, Any]]:
    tasks = [task for task in queue.get("queue", []) if task.get("status") == "pending"]
    tasks.sort(
        key=lambda task: (
            int(task.get("queue_rank", 9999)),
            PRIORITY_ORDER.get(str(task.get("priority", "medium")), 99),
            str(task.get("created_at", "")),
            str(task.get("id", "")),
        )
    )
    return tasks
