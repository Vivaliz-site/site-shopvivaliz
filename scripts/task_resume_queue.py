#!/usr/bin/env python3
"""Certify and compact the durable task-resume request queue.

The active queue contains only unique requests that still match a current
RUNNING checkpoint. Historical, superseded, orphaned, duplicate, malformed,
or otherwise non-actionable rows are moved to an append-only archive under the
same runtime directory. No task payload is printed by the CLI; only aggregate
counts are emitted.
"""
from __future__ import annotations

import argparse
import fcntl
import hashlib
import json
import os
from contextlib import contextmanager
from pathlib import Path
from typing import Any, Iterator

try:
    from .agent_task_state import DEFAULT_REPOSITORY, RUNTIME_DIR
except ImportError:  # direct CLI execution from repository root
    from agent_task_state import DEFAULT_REPOSITORY, RUNTIME_DIR

REQUESTS_FILE = "_resume-requests.jsonl"
ARCHIVE_FILE = "_resume-requests-archive.jsonl"
LOCK_FILE = "_resume-queue.lock"
DEFAULT_COMPACT_THRESHOLD = 25
TERMINAL_STATES = frozenset({"CONCLUIDO", "BLOCKED_EXTERNAL"})


def checkpoint_fingerprint(payload: dict[str, Any]) -> str:
    basis = "\n".join(
        [
            str(payload.get("repository", DEFAULT_REPOSITORY)).strip(),
            str(payload.get("task_id", "")).strip(),
            str(payload.get("updated_at", "")).strip(),
            str(payload.get("next_action", "")).strip(),
        ]
    )
    return hashlib.sha256(basis.encode("utf-8")).hexdigest()


@contextmanager
def _queue_lock(root: Path, *, exclusive: bool) -> Iterator[None]:
    root.mkdir(parents=True, exist_ok=True)
    lock_path = root / LOCK_FILE
    with lock_path.open("a+", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX if exclusive else fcntl.LOCK_SH)
        try:
            yield
        finally:
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def _load_state(path: Path) -> dict[str, Any] | None:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    return payload if isinstance(payload, dict) else None


def _states(root: Path) -> dict[str, dict[str, Any]]:
    states: dict[str, dict[str, Any]] = {}
    if not root.is_dir():
        return states
    for path in sorted(root.glob("*.json")):
        if path.name.startswith("_") or not path.is_file():
            continue
        payload = _load_state(path)
        if not payload:
            continue
        task_id = str(payload.get("task_id", "")).strip()
        if task_id:
            states[task_id] = payload
    return states


def _raw_lines(root: Path) -> list[str]:
    path = root / REQUESTS_FILE
    if not path.is_file():
        return []
    return path.read_text(encoding="utf-8", errors="replace").splitlines()


def _analyze(lines: list[str], states: dict[str, dict[str, Any]], *, threshold: int) -> tuple[dict[str, Any], set[int]]:
    counts = {
        "raw_rows": len(lines),
        "parsed_rows": 0,
        "queued_rows": 0,
        "actionable_rows": 0,
        "duplicate_rows": 0,
        "terminal_history_rows": 0,
        "superseded_rows": 0,
        "orphan_rows": 0,
        "malformed_rows": 0,
        "other_status_rows": 0,
        "running_tasks": sum(1 for state in states.values() if str(state.get("status", "")).strip() == "RUNNING"),
    }
    retain: set[int] = set()
    seen_actionable: set[str] = set()

    for index, line in enumerate(lines):
        if not line.strip():
            counts["malformed_rows"] += 1
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            counts["malformed_rows"] += 1
            continue
        if not isinstance(row, dict):
            counts["malformed_rows"] += 1
            continue

        counts["parsed_rows"] += 1
        if str(row.get("status", "")).strip() != "queued":
            counts["other_status_rows"] += 1
            continue
        counts["queued_rows"] += 1

        task_id = str(row.get("task_id", "")).strip()
        state = states.get(task_id)
        if not task_id or state is None:
            counts["orphan_rows"] += 1
            continue

        state_status = str(state.get("status", "")).strip()
        if state_status in TERMINAL_STATES:
            counts["terminal_history_rows"] += 1
            continue
        if state_status != "RUNNING":
            counts["superseded_rows"] += 1
            continue

        row_repository = str(row.get("repository", DEFAULT_REPOSITORY)).strip() or DEFAULT_REPOSITORY
        state_repository = str(state.get("repository", DEFAULT_REPOSITORY)).strip() or DEFAULT_REPOSITORY
        fingerprint = str(row.get("fingerprint", "")).strip()
        matches = (
            row_repository == state_repository
            and str(row.get("checkpoint_updated_at", "")).strip() == str(state.get("updated_at", "")).strip()
            and str(row.get("next_action", "")).strip() == str(state.get("next_action", "")).strip()
            and fingerprint == checkpoint_fingerprint(state)
        )
        if not matches:
            counts["superseded_rows"] += 1
            continue
        if fingerprint in seen_actionable:
            counts["duplicate_rows"] += 1
            continue

        seen_actionable.add(fingerprint)
        retain.add(index)
        counts["actionable_rows"] += 1

    counts["nonactionable_rows"] = counts["raw_rows"] - counts["actionable_rows"]
    counts["oversized"] = counts["raw_rows"] > max(1, int(threshold))
    counts["certified"] = (
        counts["nonactionable_rows"] == 0
        and counts["actionable_rows"] <= counts["running_tasks"]
    )
    return counts, retain


def _certify_unlocked(root: Path, *, threshold: int) -> tuple[dict[str, Any], list[str], set[int]]:
    lines = _raw_lines(root)
    summary, retain = _analyze(lines, _states(root), threshold=threshold)
    return summary, lines, retain


def certify_queue(runtime_dir: Path | None = None, *, threshold: int = DEFAULT_COMPACT_THRESHOLD) -> dict[str, Any]:
    root = Path(runtime_dir or RUNTIME_DIR)
    with _queue_lock(root, exclusive=False):
        summary, _, _ = _certify_unlocked(root, threshold=threshold)
    return summary


def read_requests(runtime_dir: Path | None = None) -> list[dict[str, Any]]:
    root = Path(runtime_dir or RUNTIME_DIR)
    with _queue_lock(root, exclusive=False):
        lines = _raw_lines(root)
    rows: list[dict[str, Any]] = []
    for line in lines:
        if not line.strip():
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(row, dict):
            rows.append(row)
    return rows


def append_request(runtime_dir: Path, row: dict[str, Any]) -> None:
    root = Path(runtime_dir)
    with _queue_lock(root, exclusive=True):
        path = root / REQUESTS_FILE
        with path.open("a", encoding="utf-8") as handle:
            try:
                os.chmod(path, 0o600)
            except OSError:
                pass
            handle.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n")
            handle.flush()
            os.fsync(handle.fileno())


def _fsync_dir(root: Path) -> None:
    try:
        fd = os.open(root, os.O_RDONLY)
    except OSError:
        return
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def compact_queue(
    runtime_dir: Path | None = None,
    *,
    force: bool = False,
    threshold: int = DEFAULT_COMPACT_THRESHOLD,
) -> dict[str, Any]:
    root = Path(runtime_dir or RUNTIME_DIR)
    with _queue_lock(root, exclusive=True):
        before, lines, retain = _certify_unlocked(root, threshold=threshold)
        removable = [line for index, line in enumerate(lines) if index not in retain]
        should_compact = bool(removable) or (force and bool(lines))

        if should_compact and removable:
            archive = root / ARCHIVE_FILE
            with archive.open("a", encoding="utf-8") as handle:
                try:
                    os.chmod(archive, 0o600)
                except OSError:
                    pass
                for line in removable:
                    handle.write(line + "\n")
                handle.flush()
                os.fsync(handle.fileno())

        if should_compact:
            target = root / REQUESTS_FILE
            temp = root / f".{REQUESTS_FILE}.{os.getpid()}.tmp"
            with temp.open("w", encoding="utf-8") as handle:
                for index, line in enumerate(lines):
                    if index in retain:
                        handle.write(line + "\n")
                handle.flush()
                os.fsync(handle.fileno())
            try:
                os.chmod(temp, 0o600)
            except OSError:
                pass
            os.replace(temp, target)
            _fsync_dir(root)

        after, _, _ = _certify_unlocked(root, threshold=threshold)

    return {
        "compacted": bool(should_compact),
        "archived_rows": len(removable) if should_compact else 0,
        "before": before,
        "after": after,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Certify or compact the durable task-resume queue.")
    parser.add_argument("--runtime-dir", default="")
    parser.add_argument("--compact", action="store_true")
    parser.add_argument("--threshold", type=int, default=DEFAULT_COMPACT_THRESHOLD)
    args = parser.parse_args()

    runtime = Path(args.runtime_dir).expanduser() if args.runtime_dir else None
    if args.compact:
        result = compact_queue(runtime, threshold=args.threshold)
    else:
        result = certify_queue(runtime, threshold=args.threshold)
    print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))
    return 0 if (result.get("after", result).get("certified") is True) else 75


if __name__ == "__main__":
    raise SystemExit(main())
