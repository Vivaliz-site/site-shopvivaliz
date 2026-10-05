#!/usr/bin/env python3
"""Emit deduplicated resume requests for stale non-terminal task checkpoints.

This watchdog is intentionally deterministic. It never invokes an AI provider,
shell command, browser, network client, or paid executor. It only converts a
stale durable checkpoint into a persistent resume request that another finite
executor can consume.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import fcntl
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

try:
    from .agent_task_state import DEFAULT_REPOSITORY, RUNTIME_DIR
    from . import task_resume_queue as resume_queue
except ImportError:  # direct CLI execution from repository root
    from agent_task_state import DEFAULT_REPOSITORY, RUNTIME_DIR
    import task_resume_queue as resume_queue

REQUESTS_FILE = resume_queue.REQUESTS_FILE
LOCK_FILE = "_continuity-watchdog.lock"
DEFAULT_STALE_SECONDS = 120
DEFAULT_LOOKBACK_DAYS = 10


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


@contextmanager
def _watchdog_lock(root: Path):
    root.mkdir(parents=True, exist_ok=True)
    path = root / LOCK_FILE
    with path.open("a+", encoding="utf-8") as handle:
        try:
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            yield False
            return
        try:
            yield True
        finally:
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def _parse_time(value: Any) -> datetime | None:
    raw = str(value or "").strip()
    if not raw:
        return None
    try:
        parsed = datetime.fromisoformat(raw.replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def _state_files(runtime_dir: Path) -> list[Path]:
    if not runtime_dir.exists():
        return []
    return sorted(
        path
        for path in runtime_dir.glob("*.json")
        if path.is_file() and not path.name.startswith("_")
    )


def _read_state(path: Path) -> dict[str, Any] | None:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    return payload if isinstance(payload, dict) else None


def _fingerprint(payload: dict[str, Any]) -> str:
    return resume_queue.checkpoint_fingerprint(payload)


def read_requests(runtime_dir: Path | None = None) -> list[dict[str, Any]]:
    return resume_queue.read_requests(runtime_dir)


def _append_request(runtime_dir: Path, row: dict[str, Any]) -> None:
    resume_queue.append_request(runtime_dir, row)


def _run_once_locked(
    *,
    stale_seconds: int = DEFAULT_STALE_SECONDS,
    lookback_days: int = DEFAULT_LOOKBACK_DAYS,
    runtime_dir: Path | None = None,
    now: datetime | None = None,
) -> dict[str, Any]:
    root = Path(runtime_dir or RUNTIME_DIR)
    current = (now or datetime.now(timezone.utc)).astimezone(timezone.utc)
    cutoff = max(1, int(stale_seconds))
    lookback = max(1, int(lookback_days))
    lookback_cutoff = current - timedelta(days=lookback)
    queue_maintenance = resume_queue.compact_queue(root)

    existing = {
        str(row.get("fingerprint", "")).strip()
        for row in read_requests(root)
        if str(row.get("fingerprint", "")).strip()
    }

    scanned = 0
    eligible = 0
    dispatched = 0
    skipped_outside_lookback = 0
    skipped_invalid_timestamp = 0

    for path in _state_files(root):
        payload = _read_state(path)
        if not payload:
            continue

        created = _parse_time(payload.get("created_at"))
        if created is None:
            skipped_invalid_timestamp += 1
            continue
        if created < lookback_cutoff:
            skipped_outside_lookback += 1
            continue
        scanned += 1

        updated = _parse_time(payload.get("updated_at"))
        if updated is None:
            skipped_invalid_timestamp += 1
            continue
        if str(payload.get("status", "")).strip() != "RUNNING":
            continue

        next_action = str(payload.get("next_action", "")).strip()
        if not next_action:
            continue

        age_seconds = (current - updated).total_seconds()
        if age_seconds < cutoff:
            continue

        eligible += 1
        fingerprint = _fingerprint(payload)
        if fingerprint in existing:
            continue

        task_id = str(payload.get("task_id", "")).strip()
        if not task_id:
            continue

        previous_agent_id = str(payload.get("agent_id", "")).strip() or "gpt"
        request = {
            "id": f"resume-{fingerprint[:20]}",
            "kind": "auto_resume",
            "status": "queued",
            "task_id": task_id,
            "repository": str(payload.get("repository", DEFAULT_REPOSITORY)).strip() or DEFAULT_REPOSITORY,
            "agent_id": "gpt",
            "preferred_executor": "chatgpt_common",
            "secondary_executor": "chatgpt_work",
            "final_fallback": "cli",
            "executor_order": ["chatgpt_common", "chatgpt_work", "cli"],
            "fallback_policy": "chatgpt_common_then_work_then_cli",
            "previous_agent_id": previous_agent_id,
            "goal": str(payload.get("goal", "")).strip(),
            "next_action": next_action,
            "checkpoint_updated_at": str(payload.get("updated_at", "")).strip(),
            "checkpoint_age_seconds": int(max(0, age_seconds)),
            "fingerprint": fingerprint,
            "created_at": utc_now(),
        }
        _append_request(root, request)
        existing.add(fingerprint)
        dispatched += 1

    queue_after = resume_queue.certify_queue(root)
    return {
        "ok": True,
        "runtime_dir": str(root),
        "stale_seconds": cutoff,
        "lookback_days": lookback,
        "scanned": scanned,
        "eligible": eligible,
        "dispatched": dispatched,
        "skipped_outside_lookback": skipped_outside_lookback,
        "skipped_invalid_timestamp": skipped_invalid_timestamp,
        "queue": {
            "compacted": bool(queue_maintenance.get("compacted")),
            "archived_rows": int(queue_maintenance.get("archived_rows") or 0),
            "before": queue_maintenance.get("before") or {},
            "after": queue_after,
        },
        "generated_at": utc_now(),
    }


def run_once(
    *,
    stale_seconds: int = DEFAULT_STALE_SECONDS,
    lookback_days: int = DEFAULT_LOOKBACK_DAYS,
    runtime_dir: Path | None = None,
    now: datetime | None = None,
) -> dict[str, Any]:
    root = Path(runtime_dir or RUNTIME_DIR)
    cutoff = max(1, int(stale_seconds))
    lookback = max(1, int(lookback_days))
    with _watchdog_lock(root) as acquired:
        if not acquired:
            return {
                "ok": True,
                "runtime_dir": str(root),
                "stale_seconds": cutoff,
                "lookback_days": lookback,
                "scanned": 0,
                "eligible": 0,
                "dispatched": 0,
                "locked": True,
                "generated_at": utc_now(),
            }
        return _run_once_locked(
            stale_seconds=cutoff,
            lookback_days=lookback,
            runtime_dir=root,
            now=now,
        )


def main() -> int:
    parser = argparse.ArgumentParser(description="Emit resume requests for stale RUNNING task checkpoints.")
    parser.add_argument("--stale-seconds", type=int, default=DEFAULT_STALE_SECONDS)
    parser.add_argument("--lookback-days", type=int, default=DEFAULT_LOOKBACK_DAYS)
    parser.add_argument("--runtime-dir", default="")
    args = parser.parse_args()

    runtime_dir = Path(args.runtime_dir).expanduser() if args.runtime_dir else None
    result = run_once(
        stale_seconds=args.stale_seconds,
        lookback_days=args.lookback_days,
        runtime_dir=runtime_dir,
    )
    print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
