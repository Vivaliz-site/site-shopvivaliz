#!/usr/bin/env python3
"""Audit and safely compact the durable ChatGPT resume-request queue."""
from __future__ import annotations

import argparse
import fcntl
import json
import os
import tempfile
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

try:
    from .agent_task_state import RUNTIME_DIR
    from .task_continuation_watchdog import REQUESTS_FILE, read_requests
    from .task_resume_dispatcher import EXECUTIONS_FILE, _request_matches_state, _safe_task_id
except ImportError:
    from agent_task_state import RUNTIME_DIR
    from task_continuation_watchdog import REQUESTS_FILE, read_requests
    from task_resume_dispatcher import EXECUTIONS_FILE, _request_matches_state, _safe_task_id

LOCK_FILE = "_resume-queue-maintenance.lock"
ARCHIVE_FILE = "_resume-requests-archive.jsonl"
TERMINAL = frozenset({"CONCLUIDO", "BLOCKED_EXTERNAL"})


def _read_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return value if isinstance(value, dict) else {}


def _read_jsonl(path: Path) -> list[dict[str, Any]]:
    if not path.is_file():
        return []
    rows: list[dict[str, Any]] = []
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(row, dict):
            rows.append(row)
    return rows


def classify(runtime: Path) -> tuple[list[tuple[dict[str, Any], str]], dict[str, int]]:
    requests = read_requests(runtime)
    executed = {
        str(row.get("fingerprint", "")).strip()
        for row in _read_jsonl(runtime / EXECUTIONS_FILE)
        if str(row.get("fingerprint", "")).strip()
    }
    seen: set[str] = set()
    rows: list[tuple[dict[str, Any], str]] = []
    counts: Counter[str] = Counter()
    for request in requests:
        task_id = str(request.get("task_id", "")).strip()
        fingerprint = str(request.get("fingerprint", "")).strip()
        if not task_id or not fingerprint or str(request.get("status", "")).strip() != "queued":
            reason = "malformed_or_nonqueued"
        elif fingerprint in seen:
            reason = "duplicate"
        else:
            seen.add(fingerprint)
            state = _read_json(runtime / f"{_safe_task_id(task_id)}.json")
            if not state:
                reason = "orphaned"
            elif str(state.get("status", "")).strip() in TERMINAL:
                reason = "terminal"
            elif fingerprint in executed:
                reason = "already_executed"
            elif _request_matches_state(request, state):
                reason = "actionable"
            else:
                reason = "superseded"
        rows.append((request, reason))
        counts[reason] += 1
    return rows, dict(sorted(counts.items()))


def _atomic_write(path: Path, rows: list[dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            for row in rows:
                handle.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(name, path)
    finally:
        try:
            os.unlink(name)
        except FileNotFoundError:
            pass


def compact(runtime: Path) -> dict[str, Any]:
    runtime.mkdir(parents=True, exist_ok=True)
    with (runtime / LOCK_FILE).open("a+", encoding="utf-8") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        classified, before = classify(runtime)
        active = [row for row, reason in classified if reason == "actionable"]
        inactive = [
            {**row, "_queue_archive_reason": reason}
            for row, reason in classified
            if reason != "actionable"
        ]
        if inactive:
            with (runtime / ARCHIVE_FILE).open("a", encoding="utf-8") as archive:
                for row in inactive:
                    archive.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n")
                archive.flush()
                os.fsync(archive.fileno())
        _atomic_write(runtime / REQUESTS_FILE, active)
        _, after = classify(runtime)
    return {
        "ok": True,
        "before": before,
        "after": after,
        "archived": len(inactive),
        "retained_actionable": len(active),
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--runtime-dir", default="")
    parser.add_argument("--compact", action="store_true")
    args = parser.parse_args()
    runtime = Path(args.runtime_dir).expanduser() if args.runtime_dir else Path(RUNTIME_DIR)
    if args.compact:
        result = compact(runtime)
    else:
        rows, counts = classify(runtime)
        result = {"ok": True, "total": len(rows), "counts": counts}
    result["generated_at"] = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
