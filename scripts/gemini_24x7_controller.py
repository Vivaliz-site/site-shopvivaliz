#!/usr/bin/env python3
"""Durable Gemini-only supervisor for the existing task-continuity stack.

The controller deliberately composes the watchdog, ChatGPT-first nudge and
bounded dispatcher.  It does not replace any of them and never treats a queue
ACK, a child exit status or a provider response as terminal task evidence.
"""
from __future__ import annotations

import argparse
import fcntl
import json
import os
import signal
import sys
import tempfile
import time
import uuid
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

try:
    from .agent_task_state import RUNTIME_DIR
    from . import chatgpt_continuity_nudge_dispatcher as nudge_dispatcher
    from . import task_continuation_watchdog as watchdog
    from . import task_resume_dispatcher as dispatcher
except ImportError:  # direct execution from a release checkout
    from scripts.agent_task_state import RUNTIME_DIR
    from scripts import chatgpt_continuity_nudge_dispatcher as nudge_dispatcher
    from scripts import task_continuation_watchdog as watchdog
    from scripts import task_resume_dispatcher as dispatcher


LEASE_FILE = "_gemini-24x7-controller-lease.json"
LOCK_FILE = "_gemini-24x7-controller.lock"
DAEMON_LOCK_FILE = "_gemini-24x7-controller-daemon.lock"
EVENTS_FILE = "_gemini-24x7-controller-events.jsonl"
STATE_FILE = "_gemini-24x7-controller-state.json"
DEFAULT_LEASE_SECONDS = 960
DEFAULT_INTERVAL_SECONDS = 30


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _parse_utc(value: object) -> datetime | None:
    try:
        return datetime.fromisoformat(str(value).replace("Z", "+00:00")).astimezone(timezone.utc)
    except (TypeError, ValueError):
        return None


def _atomic_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    temp = Path(name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, ensure_ascii=False, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp, 0o600)
        os.replace(temp, path)
    finally:
        temp.unlink(missing_ok=True)


def _read_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return value if isinstance(value, dict) else {}


def _append_event(root: Path, event: str, **fields: Any) -> None:
    row = {"at": utc_now(), "event": event}
    row.update({key: value for key, value in fields.items() if value not in (None, "", {}, [])})
    path = root / EVENTS_FILE
    with path.open("a", encoding="utf-8") as handle:
        os.chmod(path, 0o600)
        handle.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n")
        handle.flush()
        os.fsync(handle.fileno())


@dataclass(frozen=True)
class LeaseResult:
    acquired: bool
    recovered: bool = False
    reason: str = ""


def acquire_lease(runtime_dir: Path, *, owner_id: str, ttl_seconds: int = DEFAULT_LEASE_SECONDS) -> LeaseResult:
    """Atomically claim a crash-recoverable controller lease.

    The advisory lock protects the read/replace transaction; the lease file is
    retained on crash so a restarted controller can distinguish a live owner
    from an expired one.  The short-lived lock itself is never evidence of
    completion or task ownership.
    """
    root = Path(runtime_dir)
    root.mkdir(parents=True, exist_ok=True)
    lock_path = root / LOCK_FILE
    with lock_path.open("a+", encoding="utf-8") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        existing = _read_json(root / LEASE_FILE)
        expires = _parse_utc(existing.get("expires_at"))
        now = datetime.now(timezone.utc)
        if expires is not None and expires > now and existing.get("owner_id") != owner_id:
            return LeaseResult(False, reason="lease_held")
        recovered = bool(existing) and existing.get("owner_id") != owner_id
        payload = {
            "schema_version": 1,
            "owner_id": owner_id,
            "acquired_at": utc_now(),
            "expires_at": (now + timedelta(seconds=max(1, int(ttl_seconds)))).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
        }
        _atomic_json(root / LEASE_FILE, payload)
        if recovered:
            _append_event(root, "lease_recovered", previous_owner_id=str(existing.get("owner_id", "")))
        return LeaseResult(True, recovered=recovered)


def release_lease(runtime_dir: Path, *, owner_id: str) -> None:
    root = Path(runtime_dir)
    lock_path = root / LOCK_FILE
    with lock_path.open("a+", encoding="utf-8") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        lease_path = root / LEASE_FILE
        existing = _read_json(lease_path)
        if existing.get("owner_id") == owner_id:
            lease_path.unlink(missing_ok=True)


@contextmanager
def daemon_guard(runtime_dir: Path):
    """Hold one process-lifetime lock for the 24x7 daemon.

    The per-cycle durable lease still protects crash recovery and run_once
    callers. This guard closes the gap between cycles so two daemon processes
    cannot alternate ownership and duplicate external effects.
    """
    root = Path(runtime_dir)
    root.mkdir(parents=True, exist_ok=True)
    lock_path = root / DAEMON_LOCK_FILE
    with lock_path.open("a+", encoding="utf-8") as handle:
        try:
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            yield False
            return
        try:
            yield True
        finally:
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def _has_material_activity(
    lease: LeaseResult,
    watch: dict[str, Any],
    nudge: dict[str, Any],
    resumed: dict[str, Any],
) -> bool:
    if lease.recovered:
        return True
    watched = ("eligible", "dispatched")
    nudged = (
        "eligible",
        "dispatched",
        "skipped_no_token",
        "skipped_stale_checkpoint",
        "retry_attempted",
        "skipped_attempt_limit",
    )
    dispatched = (
        "eligible",
        "executed",
        "progressed",
        "terminal",
        "no_progress",
        "failed",
        "deferred_chatgpt",
    )
    return any(int(watch.get(key) or 0) > 0 for key in watched) or any(
        int(nudge.get(key) or 0) > 0 for key in nudged
    ) or any(int(resumed.get(key) or 0) > 0 for key in dispatched)


def run_once(
    *,
    runtime_dir: Path | None = None,
    stale_seconds: int = 120,
    timeout_seconds: int = 900,
    owner_id: str = "",
) -> dict[str, Any]:
    root = Path(runtime_dir or RUNTIME_DIR)
    root.mkdir(parents=True, exist_ok=True)
    owner = owner_id or f"gemini-24x7-{uuid.uuid4()}"
    lease = acquire_lease(root, owner_id=owner, ttl_seconds=max(DEFAULT_LEASE_SECONDS, timeout_seconds + 60))
    if not lease.acquired:
        _append_event(root, "duplicate_suppressed", reason=lease.reason)
        return {"ok": True, "owner_id": owner, "duplicate_suppressed": True, "reason": lease.reason}
    try:
        watch = watchdog.run_once(stale_seconds=max(1, int(stale_seconds)), runtime_dir=root)
        nudge = nudge_dispatcher.run_once(runtime_dir=root)
        # The canonical dispatcher itself retains ChatGPT's first recovery
        # window and owns the Gemini-only execution boundary.
        resumed = dispatcher.run_once(runtime_dir=root, timeout_seconds=max(1, int(timeout_seconds)))
        summary = {
            "ok": True,
            "owner_id": owner,
            "lease_recovered": lease.recovered,
            "watchdog": {key: watch.get(key) for key in ("scanned", "eligible", "dispatched")},
            "chatgpt_nudge": {key: nudge.get(key) for key in ("scanned", "eligible", "dispatched", "skipped_no_token", "skipped_stale_checkpoint")},
            "dispatcher": {key: resumed.get(key) for key in ("scanned", "eligible", "executed", "progressed", "terminal", "no_progress", "failed", "deferred_chatgpt")},
            "generated_at": utc_now(),
        }
        _atomic_json(root / STATE_FILE, summary)
        if _has_material_activity(lease, watch, nudge, resumed):
            _append_event(
                root,
                "cycle_completed",
                owner_id=owner,
                lease_recovered=lease.recovered,
                watchdog=summary["watchdog"],
                chatgpt_nudge=summary["chatgpt_nudge"],
                dispatcher=summary["dispatcher"],
            )
        return summary
    finally:
        release_lease(root, owner_id=owner)


def main() -> int:
    parser = argparse.ArgumentParser(description="Run the durable Gemini-only continuity controller.")
    parser.add_argument("--runtime-dir", default="")
    parser.add_argument("--stale-seconds", type=int, default=120)
    parser.add_argument("--timeout-seconds", type=int, default=900)
    parser.add_argument("--interval-seconds", type=int, default=DEFAULT_INTERVAL_SECONDS)
    parser.add_argument("--daemon", action="store_true")
    args = parser.parse_args()
    runtime = Path(args.runtime_dir).expanduser() if args.runtime_dir else None
    running = True

    def stop(_signum: int, _frame: object) -> None:
        nonlocal running
        running = False

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)

    root = Path(runtime or RUNTIME_DIR)
    if not args.daemon:
        print(
            json.dumps(
                run_once(
                    runtime_dir=root,
                    stale_seconds=args.stale_seconds,
                    timeout_seconds=args.timeout_seconds,
                ),
                ensure_ascii=False,
                sort_keys=True,
            ),
            flush=True,
        )
        return 0

    with daemon_guard(root) as acquired:
        if not acquired:
            print(
                json.dumps(
                    {
                        "ok": False,
                        "duplicate_daemon_suppressed": True,
                        "generated_at": utc_now(),
                    },
                    ensure_ascii=False,
                    sort_keys=True,
                ),
                flush=True,
            )
            return 75
        while running:
            print(
                json.dumps(
                    run_once(
                        runtime_dir=root,
                        stale_seconds=args.stale_seconds,
                        timeout_seconds=args.timeout_seconds,
                    ),
                    ensure_ascii=False,
                    sort_keys=True,
                ),
                flush=True,
            )
            for _ in range(max(1, int(args.interval_seconds))):
                if not running:
                    break
                time.sleep(1)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
