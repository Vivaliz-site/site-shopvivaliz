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
CHATGPT_MONITOR_STATE_FILE = "_chatgpt-continuity-monitor-state.json"
CHATGPT_MONITOR_FALLBACK_FILE = Path(os.environ.get("CHATGPT_CONTINUITY_MONITOR_FALLBACK_FILE", "/home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/_chatgpt-continuity-monitor-state.json"))
CHATGPT_BROWSER_HEALTH_STATE_FILE = "_chatgpt-browser-health.json"
DEFAULT_BROWSER_HEALTH_MAX_AGE_SECONDS = 90
DEFAULT_MONITOR_HEALTH_MAX_AGE_SECONDS = 180
CLAUDE_REMOTE_CONTROL_POINTER_FILE = "/home/ubuntu/.claude/projects/-home-ubuntu-shopvivaliz-claude-workspace-site-shopvivaliz/bridge-pointer.json"


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _parse_utc(value: object) -> datetime | None:
    try:
        return datetime.fromisoformat(str(value).replace("Z", "+00:00")).astimezone(timezone.utc)
    except (TypeError, ValueError):
        return None


def _boot_id() -> str:
    try:
        return Path("/proc/sys/kernel/random/boot_id").read_text(encoding="utf-8").strip()
    except OSError:
        return ""


def _process_start_ticks(pid: int) -> str:
    try:
        fields = Path(f"/proc/{int(pid)}/stat").read_text(encoding="utf-8").split()
    except (OSError, ValueError):
        return ""
    return fields[21] if len(fields) > 21 else ""


def _current_process_identity() -> dict[str, Any]:
    pid = os.getpid()
    return {
        "pid": pid,
        "pid_start_ticks": _process_start_ticks(pid),
        "boot_id": _boot_id(),
    }


def _lease_owner_alive(payload: dict[str, Any]) -> bool | None:
    """Return True/False when a Linux owner identity can be verified.

    Legacy leases without process identity remain TTL-governed (None) rather
    than being guessed dead.
    """
    try:
        pid = int(payload.get("pid"))
    except (TypeError, ValueError):
        return None
    if pid <= 0:
        return None

    expected_boot = str(payload.get("boot_id", "")).strip()
    current_boot = _boot_id()
    if expected_boot and current_boot and expected_boot != current_boot:
        return False

    actual_start = _process_start_ticks(pid)
    if not actual_start:
        return False
    expected_start = str(payload.get("pid_start_ticks", "")).strip()
    if expected_start and expected_start != actual_start:
        return False
    return True


def _fsync_dir(path: Path) -> None:
    try:
        fd = os.open(path, os.O_RDONLY)
    except OSError:
        return
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


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
        _fsync_dir(path.parent)
    finally:
        temp.unlink(missing_ok=True)


def _read_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return value if isinstance(value, dict) else {}


def _chatgpt_monitor_health(root: Path) -> dict[str, Any]:
    runtime_root = Path(root).resolve()
    primary = _read_json(runtime_root / CHATGPT_MONITOR_STATE_FILE)
    fallback_path = Path(CHATGPT_MONITOR_FALLBACK_FILE).resolve()
    canonical_runtime = Path(RUNTIME_DIR).resolve()
    # The installed worker fallback belongs to the canonical production runtime.
    # Never let it leak into an isolated/test runtime unless that runtime
    # explicitly points the fallback inside its own directory.
    fallback_allowed = runtime_root == canonical_runtime or fallback_path.parent == runtime_root
    fallback = _read_json(fallback_path) if fallback_allowed else {}
    primary_updated = _parse_utc(primary.get("updated_at"))
    fallback_updated = _parse_utc(fallback.get("updated_at"))
    state = primary
    source = "primary"
    if fallback_updated is not None and (primary_updated is None or fallback_updated > primary_updated):
        state = fallback
        source = "fallback"
    updated_at = str(state.get("updated_at", "")).strip()
    updated = _parse_utc(updated_at)
    try:
        max_age_seconds = max(
            60,
            int(os.environ.get(
                "CHATGPT_CONTINUITY_MONITOR_HEALTH_MAX_AGE_SECONDS",
                DEFAULT_MONITOR_HEALTH_MAX_AGE_SECONDS,
            )),
        )
    except (TypeError, ValueError):
        max_age_seconds = DEFAULT_MONITOR_HEALTH_MAX_AGE_SECONDS

    age_seconds: int | None = None
    fresh = False
    if updated is not None:
        age_seconds = int(max(0, (datetime.now(timezone.utc) - updated).total_seconds()))
        fresh = age_seconds <= max_age_seconds

    return {
        "degraded": state.get("degraded") is True,
        "action": str(state.get("action", "")).strip(),
        "last_cycle_action": str(state.get("last_cycle_action", "")).strip(),
        "updated_at": updated_at,
        "failure_reason": str(state.get("failure_reason", "")).strip(),
        "fresh": fresh,
        "age_seconds": age_seconds,
        "max_age_seconds": max_age_seconds,
        "source": source,
    }


def _chatgpt_browser_health(root: Path) -> dict[str, Any]:
    state = _read_json(root / CHATGPT_BROWSER_HEALTH_STATE_FILE)
    session_state = str(state.get("session_state", "")).strip().upper() or "UNKNOWN"
    updated_at = str(state.get("updated_at", "")).strip()
    updated = _parse_utc(updated_at)
    try:
        configured_max_age = int(
            os.environ.get("CHATGPT_BROWSER_HEALTH_MAX_AGE_SECONDS", DEFAULT_BROWSER_HEALTH_MAX_AGE_SECONDS)
        )
    except (TypeError, ValueError):
        configured_max_age = DEFAULT_BROWSER_HEALTH_MAX_AGE_SECONDS
    try:
        probe_interval_seconds = max(0, int(state.get("probe_interval_seconds") or 0))
    except (TypeError, ValueError):
        probe_interval_seconds = 0
    # A health sample must remain fresh for at least one full probe interval.
    # Otherwise a healthy authenticated browser oscillates to AUTH_UNKNOWN
    # between probes (production probes currently run every 300 seconds).
    max_age_seconds = max(
        30,
        configured_max_age,
        probe_interval_seconds + 60 if probe_interval_seconds else 0,
    )
    age_seconds: int | None = None
    fresh = False
    if updated is not None:
        age_seconds = int(max(0, (datetime.now(timezone.utc) - updated).total_seconds()))
        fresh = age_seconds <= max_age_seconds
    authenticated = (
        fresh
        and session_state == "AUTHENTICATED"
        and state.get("authenticated") is True
    )
    return {
        "session_state": session_state,
        "authenticated": authenticated,
        "fresh": fresh,
        "updated_at": updated_at,
        "age_seconds": age_seconds,
        "max_age_seconds": max_age_seconds,
    }


def _claude_remote_control_health() -> dict[str, Any]:
    pointer_path = Path(
        os.environ.get("CLAUDE_REMOTE_CONTROL_POINTER_FILE", CLAUDE_REMOTE_CONTROL_POINTER_FILE)
    ).expanduser()
    state = _read_json(pointer_path)
    try:
        pid = int(state.get("pid"))
    except (TypeError, ValueError):
        pid = 0
    expected_start = str(state.get("procStart", "")).strip()
    actual_start = _process_start_ticks(pid) if pid > 0 else ""
    source = str(state.get("source", "")).strip()
    pointer_present = bool(state)
    process_alive = bool(actual_start)
    identity_match = bool(expected_start and actual_start and expected_start == actual_start)
    session_present = bool(str(state.get("sessionId", "")).strip())
    environment_present = bool(str(state.get("environmentId", "")).strip())
    connected = bool(
        pointer_present
        and process_alive
        and identity_match
        and source == "standalone"
        and session_present
        and environment_present
    )
    return {
        "connected": connected,
        "pointer_present": pointer_present,
        "process_alive": process_alive,
        "identity_match": identity_match,
        "source": source,
        "session_present": session_present,
        "environment_present": environment_present,
    }


def _append_event(root: Path, event: str, **fields: Any) -> None:
    row = {"at": utc_now(), "event": event}
    row.update({key: value for key, value in fields.items() if value not in (None, "", {}, [])})
    path = root / EVENTS_FILE
    created = not path.exists()
    with path.open("a", encoding="utf-8") as handle:
        os.chmod(path, 0o600)
        handle.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n")
        handle.flush()
        os.fsync(handle.fileno())
    if created:
        _fsync_dir(root)


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
        owner_changed = bool(existing) and existing.get("owner_id") != owner_id
        owner_alive = _lease_owner_alive(existing) if owner_changed else None
        if (
            expires is not None
            and expires > now
            and owner_changed
            and owner_alive is not False
        ):
            return LeaseResult(False, reason="lease_held")
        recovered = owner_changed
        recovery_reason = "owner_dead" if recovered and owner_alive is False else "lease_expired"
        payload = {
            "schema_version": 2,
            "owner_id": owner_id,
            **_current_process_identity(),
            "acquired_at": utc_now(),
            "expires_at": (now + timedelta(seconds=max(1, int(ttl_seconds)))).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
        }
        _atomic_json(root / LEASE_FILE, payload)
        if recovered:
            _append_event(
                root,
                "lease_recovered",
                previous_owner_id=str(existing.get("owner_id", "")),
                reason=recovery_reason,
            )
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
            _fsync_dir(root)


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
        no_progress = int(resumed.get("no_progress") or 0)
        failed = int(resumed.get("failed") or 0)
        monitor = _chatgpt_monitor_health(root)
        monitor_required = os.environ.get("CHATGPT_CONTINUITY_MONITOR_REQUIRED", "1").strip().lower() not in {"0", "false", "no", "off"}
        monitor["required"] = monitor_required
        browser_health = _chatgpt_browser_health(root)
        claude_health = _claude_remote_control_health()
        degraded_reasons: list[str] = []
        if no_progress > 0:
            degraded_reasons.append("dispatcher_no_progress")
        if failed > 0:
            degraded_reasons.append("dispatcher_failed")
        if int(nudge.get("failed") or 0) > 0:
            degraded_reasons.append("chatgpt_resume_failed")
        if int(nudge.get("skipped_no_token") or 0) > 0:
            degraded_reasons.append("chatgpt_resume_missing_token")
        if int(nudge.get("skipped_attempt_limit") or 0) > 0:
            degraded_reasons.append("chatgpt_resume_send_budget_exhausted")
        if monitor_required and monitor.get("fresh") is not True:
            degraded_reasons.append("chatgpt_browser_monitor_stale")
        if monitor_required and monitor.get("degraded") is True:
            degraded_reasons.append("chatgpt_browser_stall_unresolved")
        if browser_health.get("fresh") is not True:
            degraded_reasons.append("chatgpt_browser_auth_unknown")
        elif browser_health.get("session_state") == "AUTH_FLOW":
            degraded_reasons.append("chatgpt_browser_auth_in_progress")
        elif browser_health.get("authenticated") is not True:
            if browser_health.get("session_state") == "LOGGED_OUT":
                degraded_reasons.append("chatgpt_browser_not_authenticated")
            else:
                degraded_reasons.append("chatgpt_browser_auth_unknown")
        if claude_health.get("pointer_present") is not True:
            degraded_reasons.append("claude_remote_control_pointer_missing")
        elif claude_health.get("process_alive") is not True:
            degraded_reasons.append("claude_remote_control_process_missing")
        elif claude_health.get("identity_match") is not True:
            degraded_reasons.append("claude_remote_control_pointer_stale")
        elif claude_health.get("connected") is not True:
            degraded_reasons.append("claude_remote_control_not_connected")
        continuity_ready = not degraded_reasons
        summary = {
            # "ok" is intentionally readiness, not mere process liveness.  A
            # daemon that is alive but unable to advance continuity must fail
            # closed so dashboards cannot turn no-progress into green.
            "ok": continuity_ready,
            "liveness_ok": True,
            "continuity_ready": continuity_ready,
            "degraded": not continuity_ready,
            "degraded_reasons": degraded_reasons,
            "owner_id": owner,
            "lease_recovered": lease.recovered,
            "watchdog": {key: watch.get(key) for key in ("scanned", "eligible", "dispatched")},
            "chatgpt_nudge": {key: nudge.get(key) for key in ("scanned", "eligible", "dispatched", "skipped_no_token", "skipped_stale_checkpoint", "failed", "skipped_attempt_limit")},
            "dispatcher": {key: resumed.get(key) for key in ("scanned", "eligible", "executed", "progressed", "terminal", "no_progress", "failed", "deferred_chatgpt")},
            "chatgpt_monitor": monitor,
            "chatgpt_browser": browser_health,
            "claude_remote_control": claude_health,
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
