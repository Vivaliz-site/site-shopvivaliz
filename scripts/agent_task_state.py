#!/usr/bin/env python3
"""Durable task-continuity state for ShopVivaliz agents.

The state machine deliberately has only two terminal states:
- CONCLUIDO: reached only through READY_TO_COMPLETE with verification evidence.
- BLOCKED_EXTERNAL: reached only for a proved external blocker after safe alternatives.

All other states are non-terminal and must retain a concrete next action.
"""
from __future__ import annotations

import argparse
import fcntl
import functools
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

ROOT = Path(__file__).resolve().parents[1]
CONTINUITY_LIB_DIR = ROOT / 'scripts' / 'continuity'
if str(CONTINUITY_LIB_DIR) not in sys.path:
    sys.path.insert(0, str(CONTINUITY_LIB_DIR))
import conversation_lease
import runtime_lock


def resolve_runtime_dir(root: Path, configured: str = "") -> Path:
    """Keep task state outside immutable releases while preserving local-dev behavior."""
    configured_path = str(configured).strip()
    if configured_path:
        return Path(configured_path).expanduser()

    resolved = root.resolve()
    parts = resolved.parts
    if "shopvivaliz-deploy" in parts:
        deploy_index = parts.index("shopvivaliz-deploy")
        deploy_root = Path(*parts[: deploy_index + 1])
        # All checkouts under the canonical deploy root (immutable releases,
        # repo/, sync-repo/, and operational worktrees nested there) must share
        # one durable state directory. Otherwise a task started from repo/ is
        # invisible to the 24x7 watchdog/controller.
        return deploy_root / "shared" / "agent-task-state"
    return resolved / "storage" / "private" / "agent-task-state"


RUNTIME_DIR = resolve_runtime_dir(ROOT, os.getenv("SHOPVIVALIZ_AGENT_TASK_STATE_DIR", ""))
TERMINAL_STATES = frozenset({"CONCLUIDO", "BLOCKED_EXTERNAL"})
NON_TERMINAL_STATES = frozenset({"RUNNING", "READY_TO_COMPLETE"})
SCHEMA_VERSION = 1
PROOF_SCHEMA_VERSION = 2
DEFAULT_REPOSITORY = "Vivaliz-site/site-shopvivaliz"
REPOSITORY_RE = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")
CHATGPT_FREEZE_GENERATION_RE = re.compile(r"^chatgpt-freeze-root-cause-\d{8}-g[1-9][0-9]*$")
CHATGPT_NUDGE_LEDGER_FILE = "_chatgpt-continuity-nudges.jsonl"
STATE_LOCK_FILE = "_agent-task-state.lock"


class TaskStateError(RuntimeError):
    pass

RECOVERY_STATES = frozenset({
    'FOREGROUND_ACTIVE', 'FOREGROUND_RELEASED', 'LEASE_EXPIRED',
    'RECOVERY_CLAIMED', 'RECOVERY_ACTIONED', 'WAITING_FOR_REAL_RESPONSE',
    'PROGRESS_CONFIRMED', 'RECOVERY_EXHAUSTED',
})
RECOVERY_TRANSITIONS = {
    '': {'FOREGROUND_ACTIVE', 'FOREGROUND_RELEASED', 'LEASE_EXPIRED', 'RECOVERY_CLAIMED'},
    'FOREGROUND_ACTIVE': {'FOREGROUND_RELEASED', 'LEASE_EXPIRED', 'RECOVERY_CLAIMED'},
    'FOREGROUND_RELEASED': {'RECOVERY_CLAIMED'},
    'LEASE_EXPIRED': {'RECOVERY_CLAIMED'},
    'RECOVERY_CLAIMED': {'RECOVERY_ACTIONED', 'WAITING_FOR_REAL_RESPONSE', 'RECOVERY_EXHAUSTED'},
    'RECOVERY_ACTIONED': {'WAITING_FOR_REAL_RESPONSE', 'PROGRESS_CONFIRMED', 'RECOVERY_EXHAUSTED'},
    'WAITING_FOR_REAL_RESPONSE': {'PROGRESS_CONFIRMED', 'RECOVERY_EXHAUSTED'},
    'PROGRESS_CONFIRMED': set(),
    'RECOVERY_EXHAUSTED': set(),
}

def _checkpoint_version(payload: dict[str, Any]) -> int:
    try:
        value = int(payload.get('checkpoint_version', 0))
    except (TypeError, ValueError):
        value = 0
    return value if value > 0 else max(1, len(payload.get('history', [])))

def _bump_checkpoint_version(payload: dict[str, Any]) -> int:
    value = _checkpoint_version(payload) + 1
    payload['checkpoint_version'] = value
    return value

@contextmanager
def _continuity_state_env():
    key = 'SHOPVIVALIZ_AGENT_TASK_STATE_DIR'
    previous = os.environ.get(key)
    os.environ[key] = str(RUNTIME_DIR)
    try:
        yield
    finally:
        if previous is None: os.environ.pop(key, None)
        else: os.environ[key] = previous

def _append_recovery_state(payload: dict[str, Any], state: str, **extra: Any) -> None:
    if state not in RECOVERY_STATES:
        raise TaskStateError(f'invalid recovery state: {state}')
    current = str(payload.get('recovery_state', '')).strip()
    if state != current and state not in RECOVERY_TRANSITIONS.get(current, set()):
        raise TaskStateError(f'invalid recovery transition: {current or "NONE"} -> {state}')
    row = {'at': utc_now(), 'state': state}
    row.update({k: v for k, v in extra.items() if v not in (None, '', [])})
    payload['recovery_state'] = state
    payload.setdefault('recovery_history', []).append(row)


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _safe_id(value: str, label: str) -> str:
    normalized = re.sub(r"[^A-Za-z0-9._-]+", "-", str(value).strip()).strip("-.")
    if not normalized:
        raise TaskStateError(f"{label} is required")
    return normalized[:160]


def _safe_repository(value: str) -> str:
    repository = str(value).strip()
    if not REPOSITORY_RE.fullmatch(repository):
        raise TaskStateError("repository must be owner/name")
    return repository


def _safe_conversation_id(value: str) -> str:
    conversation_id = str(value).strip()
    if not re.fullmatch(r"[A-Za-z0-9_-]{8,160}", conversation_id):
        raise TaskStateError("conversation_id must be an explicit ChatGPT conversation identifier")
    return conversation_id


def _path(task_id: str) -> Path:
    return RUNTIME_DIR / f"{_safe_id(task_id, 'task_id')}.json"


def _fsync_dir(path: Path) -> None:
    try:
        fd = os.open(path, os.O_RDONLY)
    except OSError:
        return
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


@contextmanager
def _state_lock():
    """Serialize all checkpoint transitions across interactive and detached writers."""
    RUNTIME_DIR.mkdir(parents=True, exist_ok=True)
    path = RUNTIME_DIR / STATE_LOCK_FILE
    fd = os.open(path, os.O_RDWR | os.O_CREAT, 0o600)
    try:
        if os.geteuid() == 0:
            parent = RUNTIME_DIR.stat()
            os.chown(path, parent.st_uid, parent.st_gid)
        os.fchmod(fd, 0o600)
        fcntl.flock(fd, fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(fd, fcntl.LOCK_UN)
    finally:
        os.close(fd)


def _serialized_transition(func):
    @functools.wraps(func)
    def wrapped(*args, **kwargs):
        with _state_lock():
            return func(*args, **kwargs)
    return wrapped


def _atomic_write(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    runtime_owner: tuple[int, int] | None = None
    if os.geteuid() == 0:
        parent_stat = path.parent.stat()
        runtime_owner = (parent_stat.st_uid, parent_stat.st_gid)

    fd, tmp_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    tmp = Path(tmp_name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, ensure_ascii=False, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        if runtime_owner is not None:
            os.chown(tmp, *runtime_owner)
        os.chmod(tmp, 0o600)
        os.replace(tmp, path)
        _fsync_dir(path.parent)
    finally:
        tmp.unlink(missing_ok=True)


def _load(task_id: str) -> dict[str, Any]:
    path = _path(task_id)
    if not path.is_file():
        raise TaskStateError(f"task state does not exist: {task_id}")
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise TaskStateError(f"task state is unreadable: {task_id}") from exc
    if not isinstance(payload, dict) or payload.get("schema_version") not in {SCHEMA_VERSION, PROOF_SCHEMA_VERSION}:
        raise TaskStateError(f"unsupported task state schema: {task_id}")
    return payload


def _history(payload: dict[str, Any], event: str, **extra: Any) -> None:
    row = {"at": utc_now(), "event": event}
    if os.getenv("SHOPVIVALIZ_RESUME_BACKGROUND") == "1":
        row["resume_request_id"] = os.getenv("SHOPVIVALIZ_RESUME_REQUEST_ID", "")
    row.update({key: value for key, value in extra.items() if value not in (None, "", [])})
    payload.setdefault("history", []).append(row)
    payload["updated_at"] = row["at"]


def is_terminal(payload: dict[str, Any]) -> bool:
    return str(payload.get("status", "")) in TERMINAL_STATES


def _freeze_generation_requires_browser_progress(task_id: str) -> bool:
    return CHATGPT_FREEZE_GENERATION_RE.fullmatch(str(task_id).strip()) is not None


def _latest_chatgpt_nudge_row(task_id: str) -> dict[str, Any] | None:
    ledger = RUNTIME_DIR / CHATGPT_NUDGE_LEDGER_FILE
    if not ledger.is_file():
        return None
    latest: dict[str, Any] | None = None
    try:
        lines = ledger.read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError:
        return None
    for raw in lines:
        raw = raw.strip()
        if not raw:
            continue
        try:
            row = json.loads(raw)
        except json.JSONDecodeError:
            continue
        if not isinstance(row, dict):
            continue
        if str(row.get("task_id", "")).strip() != str(task_id).strip():
            continue
        latest = row
    return latest


def _require_freeze_browser_progress(task_id: str) -> None:
    if not _freeze_generation_requires_browser_progress(task_id):
        return
    latest = _latest_chatgpt_nudge_row(task_id)
    if not latest:
        raise TaskStateError(
            "ChatGPT freeze generation requires browser-worker PROGRESS_CONFIRMED before terminal readiness"
        )
    status = str(latest.get("worker_status", "")).strip().upper()
    observed_at = str(latest.get("worker_status_observed_at", "")).strip()
    if status != "PROGRESS_CONFIRMED" or not observed_at:
        raise TaskStateError(
            "ChatGPT freeze generation requires latest browser-worker PROGRESS_CONFIRMED before terminal readiness"
        )


def _require_current_resume(payload: dict[str, Any]) -> None:
    if os.getenv("SHOPVIVALIZ_RESUME_BACKGROUND") != "1":
        return
    if os.getenv("SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF", "0").strip() == "1":
        required = {
            "owner_id": os.getenv("SHOPVIVALIZ_RESUME_OWNER_ID", "").strip(),
            "conversation_id": os.getenv("SHOPVIVALIZ_RESUME_CONVERSATION_ID", "").strip(),
            "checkpoint_version": os.getenv("SHOPVIVALIZ_RESUME_CHECKPOINT_VERSION", "").strip(),
            "conversation_lease_id": os.getenv("SHOPVIVALIZ_RESUME_CONVERSATION_LEASE_ID", "").strip(),
            "conversation_fencing_token": os.getenv("SHOPVIVALIZ_RESUME_CONVERSATION_FENCING_TOKEN", "").strip(),
            "runtime_lease_id": os.getenv("SHOPVIVALIZ_RESUME_RUNTIME_LEASE_ID", "").strip(),
            "runtime_fencing_token": os.getenv("SHOPVIVALIZ_RESUME_RUNTIME_FENCING_TOKEN", "").strip(),
        }
        if not all(required.values()):
            raise TaskStateError("durable background resume requires fenced ownership metadata")
        if str(payload.get("conversation_id", "")).strip() != required["conversation_id"]:
            raise TaskStateError("stale resume conversation ownership does not match current binding")
        if str(payload.get("recovery_owner_id", "")).strip() != required["owner_id"]:
            raise TaskStateError("stale resume ownership does not match current recovery owner")
        if str(payload.get("recovery_checkpoint_version", "")) != required["checkpoint_version"]:
            raise TaskStateError("stale resume checkpoint ownership does not match current recovery claim")
        if str(payload.get("recovery_lease_id", "")) != required["conversation_lease_id"] or str(payload.get("recovery_fencing_token", "")) != required["conversation_fencing_token"]:
            raise TaskStateError("stale resume conversation lease is no longer current")
        if str(payload.get("runtime_lease_id", "")) != required["runtime_lease_id"] or str(payload.get("runtime_fencing_token", "")) != required["runtime_fencing_token"]:
            raise TaskStateError("stale resume runtime lock is no longer current")
        with _continuity_state_env():
            try:
                conversation_lease.assert_conversation_lease(required["conversation_id"], required["conversation_lease_id"], int(required["conversation_fencing_token"]), "checkpoint_mutation")
                runtime_lock.assert_runtime_lock(required["runtime_lease_id"], int(required["runtime_fencing_token"]), "checkpoint_mutation")
            except (conversation_lease.LeaseConflict, runtime_lock.RuntimeLockConflict, ValueError) as exc:
                raise TaskStateError(f"stale resume fenced ownership rejected: {exc}") from exc
        return
    expected = os.getenv("SHOPVIVALIZ_RESUME_HISTORY_LENGTH", "")
    request_id = os.getenv("SHOPVIVALIZ_RESUME_REQUEST_ID", "")
    history = payload.get("history", [])
    # Legacy invocations remain compatible; the dispatcher supplies the version
    # on every new run. Our own writes advance history without stealing ownership.
    if not expected:
        return
    latest_owner = history[-1].get("resume_request_id", "") if history else ""
    if len(history) != int(expected) and (not request_id or latest_owner != request_id):
        raise TaskStateError("stale resume cannot certify a newer checkpoint; reload and continue current work")


def _require_background_terminal_checks(payload: dict[str, Any]) -> None:
    if os.getenv("SHOPVIVALIZ_RESUME_BACKGROUND") != "1":
        return
    if not payload.get("completion_checks"):
        raise TaskStateError("background terminal certification requires pinned completion checks")


def _normalize_completion_checks(checks: Iterable[Any]) -> list[list[str]]:
    normalized = []
    for command in checks:
        try:
            argv = json.loads(command) if isinstance(command, str) else command
        except json.JSONDecodeError as exc:
            raise TaskStateError("completion check must be valid JSON argv") from exc
        if not isinstance(argv, list) or not argv or not all(isinstance(arg, str) and arg for arg in argv):
            raise TaskStateError("completion check must be a nonempty JSON argv array")
        file_probe = len(argv) == 3 and argv[0] == "/usr/bin/test" and argv[1] in {"-f", "-s", "-d"}
        hash_probe = len(argv) == 3 and argv[:2] == ["/usr/bin/sha256sum", "--check"]
        service_probe = len(argv) == 4 and argv[:3] == ["/usr/bin/systemctl", "is-active", "--quiet"] and argv[3] in {
            "shopvivaliz-gemini-24x7-controller.service", "shopvivaliz-chatgpt-browser.service",
        }
        if not (file_probe or hash_probe or service_probe):
            raise TaskStateError("completion check must use a bounded read-only file/hash/service probe")
        normalized.append(argv)
    if len(normalized) > 4:
        raise TaskStateError("at most four bounded completion checks are supported")
    return normalized


def _run_completion_checks(payload: dict[str, Any]) -> None:
    receipts = []
    for index, argv in enumerate(_normalize_completion_checks(payload.get("completion_checks", []))):
        try:
            result = subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                                    stderr=subprocess.DEVNULL, timeout=10, check=False)
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise TaskStateError(f"completion check {index} unavailable: {type(exc).__name__}") from exc
        if result.returncode != 0:
            raise TaskStateError(f"completion check {index} failed with exit {result.returncode}; keep working")
        receipts.append({"index": index, "exit_code": 0, "checked_at": utc_now(),
                         "argv_sha256": hashlib.sha256(json.dumps(argv).encode()).hexdigest()})
    if receipts:
        payload["completion_check_receipts"] = receipts


@_serialized_transition
def start_task(task_id: str, goal: str, agent_id: str = "", repository: str = "", *, completion_checks: Iterable[Any] = ()) -> dict[str, Any]:
    task = _safe_id(task_id, "task_id")
    goal_text = str(goal).strip()
    if not goal_text:
        raise TaskStateError("goal is required")
    repository_name = _safe_repository(
        repository
        or os.getenv("SHOPVIVALIZ_TASK_REPOSITORY", "")
        or os.getenv("GITHUB_REPOSITORY", "")
        or DEFAULT_REPOSITORY
    )
    checks = _normalize_completion_checks(completion_checks)
    path = _path(task)
    if path.is_file():
        existing = _load(task)
        if (
            str(existing.get("goal", "")).strip() == goal_text
            and str(existing.get("repository", "")).strip() == repository_name
            and (not checks or existing.get("completion_checks", []) == checks)
        ):
            return existing
        raise TaskStateError(
            "task state already exists with different identity; use successor or explicit resume"
        )

    now = utc_now()
    payload = {
        "schema_version": SCHEMA_VERSION,
        "task_id": task,
        "agent_id": str(agent_id).strip(),
        "repository": repository_name,
        "goal": goal_text,
        "status": "RUNNING",
        "next_action": "determine and execute the next safe action required by the original goal",
        "evidence": [],
        "verification": None,
        "blocker": None,
        "created_at": now,
        "updated_at": now,
        "history": [{"at": now, "event": "started"}],
        "checkpoint_version": 1,
    }
    if checks:
        payload["schema_version"] = PROOF_SCHEMA_VERSION
        payload["completion_checks"] = checks
    _atomic_write(path, payload)
    return payload


@_serialized_transition
def start_successor_task(
    task_id: str,
    *,
    predecessor_task_id: str,
    goal: str,
    agent_id: str = "",
    repository: str = "",
) -> dict[str, Any]:
    task = _safe_id(task_id, "task_id")
    predecessor = _load(predecessor_task_id)
    if predecessor.get("status") != "CONCLUIDO":
        raise TaskStateError("successor requires a CONCLUIDO predecessor; BLOCKED_EXTERNAL must use explicit resume")
    if task == predecessor.get("task_id"):
        raise TaskStateError("successor task_id must differ from predecessor")
    path = _path(task)
    if path.exists():
        raise TaskStateError(f"successor task state already exists: {task}")

    goal_text = str(goal).strip()
    if not goal_text:
        raise TaskStateError("goal is required")
    repository_name = _safe_repository(
        repository
        or str(predecessor.get("repository", "")).strip()
        or DEFAULT_REPOSITORY
    )
    now = utc_now()
    payload = {
        "schema_version": SCHEMA_VERSION,
        "task_id": task,
        "agent_id": str(agent_id).strip(),
        "repository": repository_name,
        "goal": goal_text,
        "status": "RUNNING",
        "next_action": "determine and execute the next safe action required by the successor goal",
        "evidence": [],
        "verification": None,
        "blocker": None,
        "predecessor_task_id": str(predecessor.get("task_id", "")).strip(),
        "predecessor_status": "CONCLUIDO",
        "predecessor_completed_at": str(predecessor.get("completed_at", "")).strip(),
        "created_at": now,
        "updated_at": now,
        "history": [{
            "at": now,
            "event": "started_successor",
            "predecessor_task_id": str(predecessor.get("task_id", "")).strip(),
        }],
        "checkpoint_version": 1,
    }
    inherited_conversation_id = str(predecessor.get("conversation_id", "")).strip()
    if inherited_conversation_id:
        payload["conversation_id"] = _safe_conversation_id(inherited_conversation_id)
        inherited_session = predecessor.get("browser_session")
        if inherited_session is not None:
            if inherited_session not in {"fred", "atendimento"}:
                raise TaskStateError("predecessor has invalid browser session binding")
            payload["browser_session"] = inherited_session
    _atomic_write(path, payload)
    return payload


@_serialized_transition
def record_progress(task_id: str, *, next_action: str, evidence: str | None = None) -> dict[str, Any]:
    payload = _load(task_id)
    if is_terminal(payload):
        raise TaskStateError("terminal task cannot record progress; resume a blocked task explicitly")
    _require_current_resume(payload)
    action = str(next_action).strip()
    if not action:
        raise TaskStateError("non-terminal task requires a concrete next_action")
    # Detached recovery must not manufacture progress by re-writing the
    # checkpoint it was asked to resume.  Evidence text alone is not a material
    # state transition; keeping this a strict no-op preserves updated_at,
    # history, evidence and therefore the queue fingerprint/cooldown.
    if (
        os.getenv("SHOPVIVALIZ_RESUME_BACKGROUND") == "1"
        and str(payload.get("status", "")).strip() == "RUNNING"
        and str(payload.get("next_action", "")).strip() == action
    ):
        return payload

    payload["status"] = "RUNNING"
    payload["next_action"] = action
    _bump_checkpoint_version(payload)
    if evidence:
        payload.setdefault("evidence", []).append(str(evidence).strip())
    _history(payload, "progress", next_action=action, evidence=evidence)
    _atomic_write(_path(task_id), payload)
    return payload


@_serialized_transition
def mark_ready(
    task_id: str,
    *,
    evidence: Iterable[str],
    verification: str,
) -> dict[str, Any]:
    payload = _load(task_id)
    if is_terminal(payload):
        raise TaskStateError("terminal task cannot enter READY_TO_COMPLETE")
    evidence_rows = [str(item).strip() for item in evidence if str(item).strip()]
    verification_text = str(verification).strip()
    if not evidence_rows:
        raise TaskStateError("READY_TO_COMPLETE requires fresh evidence")
    if not verification_text:
        raise TaskStateError("READY_TO_COMPLETE requires verification against the original goal")
    _require_freeze_browser_progress(str(payload.get("task_id", task_id)))
    _require_current_resume(payload)
    _require_background_terminal_checks(payload)
    _run_completion_checks(payload)
    payload.setdefault("evidence", []).extend(evidence_rows)
    payload["verification"] = verification_text
    payload["status"] = "READY_TO_COMPLETE"
    payload["next_action"] = ""
    _history(payload, "ready_to_complete", evidence=evidence_rows, verification=verification_text)
    _atomic_write(_path(task_id), payload)
    return payload


@_serialized_transition
def complete_task(task_id: str) -> dict[str, Any]:
    payload = _load(task_id)
    if payload.get("status") != "READY_TO_COMPLETE":
        raise TaskStateError("completion rejected: task must pass READY_TO_COMPLETE first")
    if payload.get("next_action"):
        raise TaskStateError("completion rejected: executable next_action still exists")
    if not payload.get("evidence") or not payload.get("verification"):
        raise TaskStateError("completion rejected: verification evidence is missing")
    _require_freeze_browser_progress(str(payload.get("task_id", task_id)))
    _require_current_resume(payload)
    try:
        _run_completion_checks(payload)
    except TaskStateError:
        payload["status"] = "RUNNING"
        payload["next_action"] = "Investigate failed completion checks, repair the original goal, and rerun readiness verification"
        payload["verification"] = None
        payload.pop("completion_check_receipts", None)
        _history(payload, "completion_check_failed_recovery_required")
        _atomic_write(_path(task_id), payload)
        raise
    payload["status"] = "CONCLUIDO"
    payload["completed_at"] = utc_now()
    _history(payload, "completed")
    _atomic_write(_path(task_id), payload)
    return payload


@_serialized_transition
def block_task(
    task_id: str,
    *,
    blocker: dict[str, Any],
    evidence: Iterable[str],
    alternatives: Iterable[str],
    resume_condition: str,
) -> dict[str, Any]:
    payload = _load(task_id)
    if payload.get("status") == "CONCLUIDO":
        raise TaskStateError("completed task cannot be blocked")
    description = str(blocker.get("description", "")).strip() if isinstance(blocker, dict) else ""
    external = blocker.get("external") is True if isinstance(blocker, dict) else False
    evidence_rows = [str(item).strip() for item in evidence if str(item).strip()]
    alternative_rows = [str(item).strip() for item in alternatives if str(item).strip()]
    resume_text = str(resume_condition).strip()
    if not external:
        raise TaskStateError("BLOCKED_EXTERNAL requires an external=true blocker")
    if not description:
        raise TaskStateError("BLOCKED_EXTERNAL requires a concrete blocker description")
    if not evidence_rows:
        raise TaskStateError("BLOCKED_EXTERNAL requires objective blocker evidence")
    if len(alternative_rows) < 2:
        raise TaskStateError("BLOCKED_EXTERNAL requires at least two distinct safe alternatives attempted")
    if len(set(alternative_rows)) < 2:
        raise TaskStateError("BLOCKED_EXTERNAL alternatives must be distinct")
    if not resume_text:
        raise TaskStateError("BLOCKED_EXTERNAL requires an exact resume condition")
    payload["status"] = "BLOCKED_EXTERNAL"
    payload["next_action"] = ""
    payload["blocker"] = {
        "external": True,
        "description": description,
        "evidence": evidence_rows,
        "alternatives_attempted": alternative_rows,
        "resume_condition": resume_text,
    }
    _history(payload, "blocked_external", blocker=payload["blocker"])
    _atomic_write(_path(task_id), payload)
    return payload


@_serialized_transition
def resume_task(task_id: str, *, next_action: str) -> dict[str, Any]:
    payload = _load(task_id)
    if payload.get("status") != "BLOCKED_EXTERNAL":
        raise TaskStateError("only BLOCKED_EXTERNAL tasks need explicit resume")
    action = str(next_action).strip()
    if not action:
        raise TaskStateError("resumed task requires a concrete next_action")
    payload["status"] = "RUNNING"
    payload["next_action"] = action
    payload["blocker"] = None
    _history(payload, "resumed", next_action=action)
    _atomic_write(_path(task_id), payload)
    return payload


@_serialized_transition
def bind_conversation(task_id: str, *, conversation_id: str) -> dict[str, Any]:
    payload = _load(task_id)
    if is_terminal(payload):
        raise TaskStateError("terminal task cannot change conversation binding")
    bound = _safe_conversation_id(conversation_id)
    existing = str(payload.get("conversation_id", "")).strip()
    if existing and existing != bound:
        raise TaskStateError("conversation binding already exists and cannot be replaced implicitly")
    if existing == bound:
        return payload
    payload["conversation_id"] = bound
    _bump_checkpoint_version(payload)
    # Conversation binding is routing metadata, not task progress. Preserve
    # updated_at so the watchdog checkpoint fingerprint does not manufacture
    # a fresh resume request solely because an exact route was learned.
    row = {"at": utc_now(), "event": "conversation_bound", "conversation_id": bound}
    if os.getenv("SHOPVIVALIZ_RESUME_BACKGROUND") == "1":
        row["resume_request_id"] = os.getenv("SHOPVIVALIZ_RESUME_REQUEST_ID", "")
    payload.setdefault("history", []).append(row)
    _atomic_write(_path(task_id), payload)
    return payload


@_serialized_transition
def bind_browser_session(task_id: str, *, browser_session: str) -> dict[str, Any]:
    """Bind the account without changing progress or existing login."""
    payload = _load(task_id)
    if is_terminal(payload):
        raise TaskStateError("terminal task cannot bind browser session")
    _require_current_resume(payload)
    session = str(browser_session).strip()
    if session not in {"fred", "atendimento"}:
        raise TaskStateError("browser_session must be fred or atendimento")
    _safe_conversation_id(payload.get("conversation_id", ""))
    existing = payload.get("browser_session")
    if existing is not None and existing != session:
        raise TaskStateError("browser session binding already exists and cannot be replaced implicitly")
    if existing == session:
        return payload
    payload["browser_session"] = session
    _bump_checkpoint_version(payload)
    row = {"at": utc_now(), "event": "browser_session_bound", "browser_session": session}
    if os.getenv("SHOPVIVALIZ_RESUME_BACKGROUND") == "1":
        row["resume_request_id"] = os.getenv("SHOPVIVALIZ_RESUME_REQUEST_ID", "")
    payload.setdefault("history", []).append(row)
    _atomic_write(_path(task_id), payload)
    return payload



@_serialized_transition
def record_foreground_handoff(
    task_id: str,
    *,
    conversation_id: str,
    expected_checkpoint_version: int,
    durable_execution_id: str,
    lease_id: str,
    fencing_token: int,
    queue_position: int,
    foreground_duration_ms: int,
) -> dict[str, Any]:
    payload = _load(task_id)
    if is_terminal(payload):
        raise TaskStateError('terminal task cannot record foreground handoff')
    bound = _safe_conversation_id(conversation_id)
    if str(payload.get('conversation_id', '')).strip() != bound:
        raise TaskStateError('foreground handoff requires exact conversation binding')
    current_version = _checkpoint_version(payload)
    if current_version != int(expected_checkpoint_version):
        raise TaskStateError('foreground handoff checkpoint version is stale')
    durable_id = str(durable_execution_id).strip()
    if not durable_id:
        raise TaskStateError('durable_execution_id is required')
    payload['status'] = 'RUNNING'
    payload['durable_execution_id'] = durable_id
    payload['foreground_lease_id'] = str(lease_id).strip()
    payload['foreground_fencing_token'] = int(fencing_token)
    payload['foreground_queue_position'] = int(queue_position)
    payload['foreground_duration_ms'] = int(foreground_duration_ms)
    payload['handoff_checkpoint_version'] = int(expected_checkpoint_version)
    _history(payload, 'foreground_handoff', durable_execution_id=durable_id,
             conversation_id=bound, lease_id=str(lease_id).strip(),
             fencing_token=int(fencing_token), checkpoint_version=int(expected_checkpoint_version),
             queue_position=int(queue_position), foreground_duration_ms=int(foreground_duration_ms))
    _atomic_write(_path(task_id), payload)
    return payload


@_serialized_transition
def acquire_foreground_lease_for_task(task_id: str, *, owner_id: str, ttl_seconds: int = 90) -> dict[str, Any]:
    payload = _load(task_id)
    if is_terminal(payload): raise TaskStateError('terminal task cannot acquire foreground lease')
    conversation_id = _safe_conversation_id(payload.get('conversation_id', ''))
    version = _checkpoint_version(payload)
    with _continuity_state_env():
        lease = conversation_lease.acquire_conversation_lease(
            conversation_id, 'foreground', str(owner_id).strip(), version, int(ttl_seconds), ['read', 'handoff'])
    payload['foreground_lease_id'] = lease['lease_id']
    payload['foreground_fencing_token'] = lease['fencing_token']
    payload['foreground_checkpoint_version'] = version
    _atomic_write(_path(task_id), payload)
    return payload

@_serialized_transition
def renew_foreground_lease_for_task(task_id: str, *, lease_id: str, fencing_token: int, ttl_seconds: int = 90) -> dict[str, Any]:
    payload = _load(task_id)
    if is_terminal(payload):
        raise TaskStateError('terminal task cannot renew foreground lease')
    expected_lease = str(payload.get('foreground_lease_id', '')).strip()
    expected_token = payload.get('foreground_fencing_token')
    if not expected_lease or expected_lease != str(lease_id).strip() or expected_token is None or int(expected_token) != int(fencing_token):
        raise TaskStateError('foreground lease identity is stale')
    conversation_id = _safe_conversation_id(payload.get('conversation_id', ''))
    with _continuity_state_env():
        try:
            lease = conversation_lease.renew_conversation_lease(conversation_id, expected_lease, int(fencing_token), int(ttl_seconds))
        except conversation_lease.LeaseConflict as exc:
            raise TaskStateError(f'foreground lease cannot be renewed: {exc}') from exc
    payload['foreground_lease_expires_at_epoch'] = lease['expires_at_epoch']
    _history(payload, 'foreground_lease_renewed', lease_id=expected_lease, fencing_token=int(fencing_token), expires_at_epoch=lease['expires_at_epoch'])
    _atomic_write(_path(task_id), payload)
    return payload

@_serialized_transition
def release_foreground_lease_for_task(task_id: str, *, lease_id: str, fencing_token: int, reason: str) -> dict[str, Any]:
    payload = _load(task_id)
    expected_lease = str(payload.get('foreground_lease_id', '')).strip()
    expected_token = payload.get('foreground_fencing_token')
    if not expected_lease or expected_lease != str(lease_id).strip() or expected_token is None or int(expected_token) != int(fencing_token):
        raise TaskStateError('foreground lease identity is stale')
    conversation_id = _safe_conversation_id(payload.get('conversation_id', ''))
    release_reason = str(reason).strip() or 'foreground_completed'
    with _continuity_state_env():
        try:
            lease = conversation_lease.release_conversation_lease(conversation_id, expected_lease, int(fencing_token), release_reason)
        except conversation_lease.LeaseConflict as exc:
            raise TaskStateError(f'foreground lease cannot be released: {exc}') from exc
    payload['foreground_released_at_epoch'] = lease['released_at']
    payload['foreground_release_reason'] = release_reason
    _history(payload, 'foreground_lease_released', lease_id=expected_lease, fencing_token=int(fencing_token), reason=release_reason)
    _atomic_write(_path(task_id), payload)
    return payload

@_serialized_transition
def claim_recovery_ownership(task_id: str, *, owner_id: str, allowed_actions: Iterable[str], ttl_seconds: int = 90) -> dict[str, Any]:
    payload = _load(task_id)
    if is_terminal(payload): raise TaskStateError('terminal task cannot claim recovery ownership')
    conversation_id = _safe_conversation_id(payload.get('conversation_id', ''))
    session = str(payload.get('browser_session', '')).strip()
    if session not in {'fred', 'atendimento'}: raise TaskStateError('recovery requires bound browser session')
    version = _checkpoint_version(payload)
    actions = sorted({str(v).strip() for v in allowed_actions if str(v).strip()})
    if not actions: raise TaskStateError('recovery requires allowed actions')
    with _continuity_state_env():
        current = conversation_lease.get_conversation_lease(conversation_id)
        now = __import__('time').time()
        if current and current.get('released_at') is None and float(current.get('expires_at_epoch', 0) or 0) > now:
            if current.get('owner_kind') == 'foreground':
                _append_recovery_state(payload, 'FOREGROUND_ACTIVE', checkpoint_version=version)
                _atomic_write(_path(task_id), payload)
                return payload
            if (current.get('owner_kind') == 'durable-recovery' and current.get('owner_id') == str(owner_id)
                    and payload.get('recovery_lease_id') == current.get('lease_id')):
                return payload
            raise TaskStateError('conversation recovery ownership is busy')
        if current and current.get('owner_kind') == 'foreground':
            _append_recovery_state(payload, 'FOREGROUND_RELEASED' if current.get('released_at') is not None else 'LEASE_EXPIRED', checkpoint_version=version)
        try:
            lease = conversation_lease.acquire_conversation_lease(
                conversation_id, 'durable-recovery', str(owner_id).strip(), version, int(ttl_seconds), actions)
        except conversation_lease.LeaseConflict as exc:
            raise TaskStateError(f'cannot claim conversation recovery lease: {exc}') from exc
        try:
            lock = runtime_lock.acquire_runtime_lock('durable-recovery', str(owner_id).strip(), int(ttl_seconds), actions)
        except runtime_lock.RuntimeLockConflict as exc:
            try: conversation_lease.release_conversation_lease(conversation_id, lease['lease_id'], lease['fencing_token'], 'runtime_lock_unavailable')
            except Exception: pass
            raise TaskStateError(f'cannot claim runtime mutation lock: {exc}') from exc
    payload['recovery_lease_id'] = lease['lease_id']
    payload['recovery_fencing_token'] = lease['fencing_token']
    payload['runtime_lease_id'] = lock['lease_id']
    payload['runtime_fencing_token'] = lock['fencing_token']
    payload['recovery_checkpoint_version'] = version
    payload['recovery_owner_id'] = str(owner_id).strip()
    _append_recovery_state(payload, 'RECOVERY_CLAIMED', checkpoint_version=version)
    _atomic_write(_path(task_id), payload)
    return payload

@_serialized_transition
def release_recovery_ownership(task_id: str, *, owner_id: str, reason: str) -> dict[str, Any]:
    payload = _load(task_id)
    expected_owner = str(owner_id).strip()
    if str(payload.get("recovery_owner_id", "")).strip() != expected_owner:
        raise TaskStateError("recovery ownership does not belong to this executor")
    conversation_id = _safe_conversation_id(payload.get("conversation_id", ""))
    with _continuity_state_env():
        if payload.get("recovery_lease_id") and payload.get("recovery_fencing_token"):
            try:
                conversation_lease.release_conversation_lease(conversation_id, payload["recovery_lease_id"], int(payload["recovery_fencing_token"]), str(reason))
            except conversation_lease.LeaseConflict:
                pass
        if payload.get("runtime_lease_id") and payload.get("runtime_fencing_token"):
            try:
                runtime_lock.release_runtime_lock(payload["runtime_lease_id"], int(payload["runtime_fencing_token"]), str(reason))
            except runtime_lock.RuntimeLockConflict:
                pass
    payload["recovery_released_at"] = utc_now()
    payload["recovery_release_reason"] = str(reason)
    _atomic_write(_path(task_id), payload)
    return payload


@_serialized_transition
def record_recovery_state(task_id: str, *, state: str, expected_conversation_id: str,
                          expected_checkpoint_version: int, real_response_observed: bool = False) -> dict[str, Any]:
    payload = _load(task_id)
    conversation_id = _safe_conversation_id(expected_conversation_id)
    if str(payload.get('conversation_id', '')).strip() != conversation_id:
        raise TaskStateError('recovery conversation does not match current conversation binding')
    if _checkpoint_version(payload) != int(expected_checkpoint_version):
        raise TaskStateError('recovery checkpoint version is stale')
    normalized = str(state).strip().upper()
    if normalized == 'PROGRESS_CONFIRMED' and real_response_observed is not True:
        raise TaskStateError('PROGRESS_CONFIRMED requires a real assistant response')
    _append_recovery_state(payload, normalized, checkpoint_version=int(expected_checkpoint_version), real_response_observed=bool(real_response_observed))
    _atomic_write(_path(task_id), payload)
    return payload

@_serialized_transition
def rebind_conversation(task_id: str, *, conversation_id: str, expected_checkpoint_version: int) -> dict[str, Any]:
    payload = _load(task_id)
    if is_terminal(payload): raise TaskStateError('terminal task cannot rebind conversation')
    if _checkpoint_version(payload) != int(expected_checkpoint_version): raise TaskStateError('conversation rebind checkpoint version is stale')
    old = _safe_conversation_id(payload.get('conversation_id', ''))
    new = _safe_conversation_id(conversation_id)
    if old == new: return payload
    with _continuity_state_env():
        if payload.get('recovery_lease_id') and payload.get('recovery_fencing_token'):
            try: conversation_lease.release_conversation_lease(old, payload['recovery_lease_id'], int(payload['recovery_fencing_token']), 'conversation_rebound')
            except Exception: pass
        if payload.get('runtime_lease_id') and payload.get('runtime_fencing_token'):
            try: runtime_lock.release_runtime_lock(payload['runtime_lease_id'], int(payload['runtime_fencing_token']), 'conversation_rebound')
            except Exception: pass
    payload['conversation_id'] = new
    _bump_checkpoint_version(payload)
    for key in ('recovery_lease_id','recovery_fencing_token','runtime_lease_id','runtime_fencing_token','recovery_checkpoint_version','recovery_owner_id','recovery_state'):
        payload.pop(key, None)
    _history(payload, 'conversation_rebound', old_conversation_id=old, conversation_id=new, checkpoint_version=_checkpoint_version(payload))
    _atomic_write(_path(task_id), payload)
    return payload

def load_task(task_id: str) -> dict[str, Any]:
    return _load(task_id)


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Durable nonstop task-continuity state")
    sub = parser.add_subparsers(dest="command", required=True)

    start = sub.add_parser("start")
    start.add_argument("--task", required=True)
    start.add_argument("--goal", required=True)
    start.add_argument("--agent", default="")
    start.add_argument("--repository", default=os.getenv("SHOPVIVALIZ_TASK_REPOSITORY", ""))
    start.add_argument("--completion-check", action="append", default=[], help="Pinned JSON argv check, rerun at ready and complete; never include secrets")

    successor = sub.add_parser("successor")
    successor.add_argument("--task", required=True)
    successor.add_argument("--predecessor", required=True)
    successor.add_argument("--goal", required=True)
    successor.add_argument("--agent", default="")
    successor.add_argument("--repository", default=os.getenv("SHOPVIVALIZ_TASK_REPOSITORY", ""))

    progress = sub.add_parser("progress")
    progress.add_argument("--task", required=True)
    progress.add_argument("--next-action", required=True)
    progress.add_argument("--evidence")

    ready = sub.add_parser("ready")
    ready.add_argument("--task", required=True)
    ready.add_argument("--evidence", action="append", required=True)
    ready.add_argument("--verification", required=True)

    complete = sub.add_parser("complete")
    complete.add_argument("--task", required=True)

    block = sub.add_parser("block")
    block.add_argument("--task", required=True)
    block.add_argument("--description", required=True)
    block.add_argument("--evidence", action="append", required=True)
    block.add_argument("--alternative", action="append", required=True)
    block.add_argument("--resume-condition", required=True)

    resume = sub.add_parser("resume")
    resume.add_argument("--task", required=True)
    resume.add_argument("--next-action", required=True)

    bind = sub.add_parser("bind-conversation")
    bind.add_argument("--task", required=True)
    bind.add_argument("--conversation-id", required=True)

    browser = sub.add_parser("bind-browser-session")
    browser.add_argument("--task", required=True)
    browser.add_argument("--browser-session", required=True, choices=["fred", "atendimento"])

    show = sub.add_parser("show")
    show.add_argument("--task", required=True)

    terminal = sub.add_parser("terminal")
    terminal.add_argument("--task", required=True)
    return parser


def main() -> int:
    args = _parser().parse_args()
    try:
        if args.command == "start":
            payload = start_task(args.task, args.goal, args.agent, args.repository, completion_checks=args.completion_check)
        elif args.command == "successor":
            payload = start_successor_task(
                args.task,
                predecessor_task_id=args.predecessor,
                goal=args.goal,
                agent_id=args.agent,
                repository=args.repository,
            )
        elif args.command == "progress":
            payload = record_progress(args.task, next_action=args.next_action, evidence=args.evidence)
        elif args.command == "ready":
            payload = mark_ready(args.task, evidence=args.evidence, verification=args.verification)
        elif args.command == "complete":
            payload = complete_task(args.task)
        elif args.command == "block":
            payload = block_task(
                args.task,
                blocker={"external": True, "description": args.description},
                evidence=args.evidence,
                alternatives=args.alternative,
                resume_condition=args.resume_condition,
            )
        elif args.command == "resume":
            payload = resume_task(args.task, next_action=args.next_action)
        elif args.command == "bind-conversation":
            payload = bind_conversation(args.task, conversation_id=args.conversation_id)
        elif args.command == "bind-browser-session":
            payload = bind_browser_session(args.task, browser_session=args.browser_session)
        elif args.command == "show":
            payload = load_task(args.task)
        elif args.command == "terminal":
            payload = load_task(args.task)
            print(json.dumps(payload, ensure_ascii=False, indent=2))
            return 0 if is_terminal(payload) else 3
        else:
            return 64
    except TaskStateError as exc:
        print(json.dumps({"ok": False, "error": str(exc)}, ensure_ascii=False))
        return 3
    print(json.dumps(payload, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
