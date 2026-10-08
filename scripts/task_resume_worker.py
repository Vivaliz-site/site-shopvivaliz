#!/usr/bin/env python3
"""Durable detached worker for ShopVivaliz task-resume execution records."""
from __future__ import annotations

import argparse
import fcntl
import json
import os
import signal
import time
from pathlib import Path
from typing import Any, Sequence

try:
    from . import task_resume_dispatcher as dispatcher
    from .agent_task_state import RUNTIME_DIR
except ImportError:
    import task_resume_dispatcher as dispatcher
    from agent_task_state import RUNTIME_DIR

ROOT = Path(__file__).resolve().parents[1]
WORKER_LOCK_FILE = "_resume-worker.lock"


def _process_start_time(pid: int) -> str:
    try:
        fields = Path(f"/proc/{pid}/stat").read_text(encoding="utf-8").split()
        return fields[21] if len(fields) > 21 else ""
    except OSError:
        return ""


def _matching_request(runtime_dir: Path, record: dict[str, Any]) -> dict[str, Any]:
    request_id = str(record.get("request_id", "")).strip()
    fingerprint = str(record.get("fingerprint", "")).strip()
    for request in dispatcher.read_requests(runtime_dir):
        if (
            str(request.get("id", "")).strip() == request_id
            and str(request.get("fingerprint", "")).strip() == fingerprint
        ):
            return request
    return {}


def _worker_identity_alive(record: dict[str, Any]) -> bool:
    try:
        pid = int(record.get("worker_pid") or 0)
    except (TypeError, ValueError):
        return False
    if pid <= 0:
        return False
    expected = str(record.get("worker_start_time", "")).strip()
    current = _process_start_time(pid)
    return bool(expected and current and expected == current)


def _recover_dead_running_records(runtime_dir: Path) -> int:
    recovered = 0
    for path in runtime_dir.glob(f"{dispatcher.EXECUTION_RECORD_PREFIX}*.json"):
        record = dispatcher._load_json(path)
        if str(record.get("status", "")).strip() != "running":
            continue
        if _worker_identity_alive(record):
            continue
        now = dispatcher.utc_now()
        diagnostic = record.get("diagnostic") if isinstance(record.get("diagnostic"), dict) else {}
        diagnostic = dict(diagnostic)
        diagnostic["worker_recovery"] = "dead_or_reused_process_identity"
        record.update(
            status="queued",
            worker_pid=None,
            worker_start_time=None,
            recovered_at=now,
            updated_at=now,
            diagnostic=diagnostic,
        )
        dispatcher._atomic_json(path, record)
        recovered += 1
    return recovered


def _queued_records(runtime_dir: Path) -> list[Path]:
    paths: list[tuple[str, Path]] = []
    for path in runtime_dir.glob(f"{dispatcher.EXECUTION_RECORD_PREFIX}*.json"):
        record = dispatcher._load_json(path)
        if str(record.get("status", "")).strip() == "queued":
            paths.append((str(record.get("created_at", "")), path))
    return [path for _created, path in sorted(paths, key=lambda item: (item[0], item[1].name))]


def worker_run_once(
    *,
    runtime_dir: Path | None = None,
    project_dir: Path | None = None,
    executor: Sequence[str] | None = None,
) -> dict[str, Any]:
    runtime = Path(runtime_dir or RUNTIME_DIR)
    project = Path(project_dir or ROOT)
    runtime.mkdir(parents=True, exist_ok=True)
    summary = {
        "ok": True,
        "claimed": 0,
        "progressed": 0,
        "terminal": 0,
        "no_progress": 0,
        "failed": 0,
        "recovered": 0,
        "generated_at": dispatcher.utc_now(),
    }

    lock_path = runtime / WORKER_LOCK_FILE
    with lock_path.open("a+", encoding="utf-8") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            summary["locked"] = True
            return summary

        summary["recovered"] = _recover_dead_running_records(runtime)
        candidates = _queued_records(runtime)
        if not candidates:
            return summary
        record_path = candidates[0]
        record = dispatcher._load_json(record_path)
        request = _matching_request(runtime, record)
        task_id = str(record.get("task_id", "")).strip()
        state = dispatcher._load_json(dispatcher._state_path(runtime, task_id))

        if not request or not state:
            dispatcher._release_resume_ownership(
                runtime,
                state or {"task_id": task_id},
                request or {"id": str(record.get("request_id", "")).strip()},
                "resume_worker_context_missing",
            )
            diagnostic = {"worker_error": "execution_context_missing"}
            record.update(
                status="completed",
                result="executor_error",
                diagnostic=diagnostic,
                evidence={
                    "checkpoint_before": str(record.get("checkpoint_before", "")).strip(),
                    "checkpoint_after": "",
                    "diagnostic": diagnostic,
                },
                updated_at=dispatcher.utc_now(),
            )
            dispatcher._atomic_json(record_path, record)
            summary["failed"] = 1
            return summary

        if str(state.get("updated_at", "")).strip() != str(record.get("checkpoint_before", "")).strip():
            checkpoint_after = str(state.get("updated_at", "")).strip()
            diagnostic = {"worker_status": "checkpoint_already_advanced"}
            record.update(
                status="completed",
                result="superseded",
                checkpoint_after=checkpoint_after,
                diagnostic=diagnostic,
                evidence={
                    "checkpoint_before": str(record.get("checkpoint_before", "")).strip(),
                    "checkpoint_after": checkpoint_after,
                    "diagnostic": diagnostic,
                },
                updated_at=dispatcher.utc_now(),
            )
            dispatcher._atomic_json(record_path, record)
            dispatcher._release_resume_ownership(
                runtime, state, request, "resume_worker_superseded"
            )
            return summary

        record.update(
            status="running",
            worker_pid=os.getpid(),
            worker_start_time=_process_start_time(os.getpid()),
            started_at=dispatcher.utc_now(),
            updated_at=dispatcher.utc_now(),
        )
        dispatcher._atomic_json(record_path, record)
        summary["claimed"] = 1

        try:
            result, exit_code, after_state, diagnostic = dispatcher._execute(
                runtime_dir=runtime,
                project_dir=project,
                request=request,
                state=state,
                executor=executor,
                timeout_seconds=max(1, int(record.get("timeout_seconds") or dispatcher.DEFAULT_TIMEOUT_SECONDS)),
            )
        finally:
            dispatcher._release_resume_ownership(
                runtime, state, request, "resume_worker_finished"
            )
        checkpoint_after = str(after_state.get("updated_at", "")).strip() if after_state else ""
        diagnostic = diagnostic or {}
        record.update(
            status="completed",
            result=result,
            executor_exit_code=exit_code,
            checkpoint_after=checkpoint_after,
            diagnostic=diagnostic,
            evidence={
                "checkpoint_before": str(record.get("checkpoint_before", "")).strip(),
                "checkpoint_after": checkpoint_after,
                "diagnostic": diagnostic,
            },
            finished_at=dispatcher.utc_now(),
            updated_at=dispatcher.utc_now(),
        )
        dispatcher._atomic_json(record_path, record)
        if result == "progress":
            summary["progressed"] = 1
        elif result == "terminal":
            summary["terminal"] = 1
        elif result == "no_progress":
            summary["no_progress"] = 1
        else:
            summary["failed"] = 1
        return summary


def main() -> int:
    parser = argparse.ArgumentParser(description="Run the detached task-resume worker.")
    parser.add_argument("--runtime-dir", default="")
    parser.add_argument("--project-dir", default="")
    parser.add_argument("--daemon", action="store_true")
    parser.add_argument("--interval-seconds", type=int, default=5)
    args = parser.parse_args()
    runtime = Path(args.runtime_dir).expanduser() if args.runtime_dir else None
    project = Path(args.project_dir).expanduser() if args.project_dir else None

    if not args.daemon:
        result = worker_run_once(runtime_dir=runtime, project_dir=project)
        print(json.dumps(result, ensure_ascii=False, sort_keys=True))
        return 0 if not result.get("failed") else 75

    running = True

    def stop(_signum: int, _frame: object) -> None:
        nonlocal running
        running = False

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    while running:
        worker_run_once(runtime_dir=runtime, project_dir=project)
        time.sleep(max(1, int(args.interval_seconds)))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
