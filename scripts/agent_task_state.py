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
import tempfile
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

ROOT = Path(__file__).resolve().parents[1]


def resolve_runtime_dir(root: Path, configured: str = "") -> Path:
    """Keep task state outside immutable releases while preserving local-dev behavior."""
    configured_path = str(configured).strip()
    if configured_path:
        return Path(configured_path).expanduser()

    resolved = root.resolve()
    parts = resolved.parts
    if "shopvivaliz-deploy" in parts and "releases" in parts:
        deploy_index = parts.index("shopvivaliz-deploy")
        deploy_root = Path(*parts[: deploy_index + 1])
        return deploy_root / "shared" / "agent-task-state"
    return resolved / "storage" / "private" / "agent-task-state"


RUNTIME_DIR = resolve_runtime_dir(ROOT, os.getenv("SHOPVIVALIZ_AGENT_TASK_STATE_DIR", ""))
TERMINAL_STATES = frozenset({"CONCLUIDO", "BLOCKED_EXTERNAL"})
NON_TERMINAL_STATES = frozenset({"RUNNING", "READY_TO_COMPLETE"})
SCHEMA_VERSION = 1
DEFAULT_REPOSITORY = "Vivaliz-site/site-shopvivaliz"
REPOSITORY_RE = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")
CHATGPT_FREEZE_GENERATION_RE = re.compile(r"^chatgpt-freeze-root-cause-\d{8}-g[1-9][0-9]*$")
CHATGPT_NUDGE_LEDGER_FILE = "_chatgpt-continuity-nudges.jsonl"
STATE_LOCK_FILE = "_agent-task-state.lock"
COMPLETION_CHECK_MAX_COUNT = 8
COMPLETION_CHECK_TIMEOUT_SECONDS = 20
FORBIDDEN_COMPLETION_CHECK_EXECUTABLES = frozenset({
    "bash", "sh", "dash", "zsh", "fish", "cmd", "cmd.exe",
    "powershell", "powershell.exe", "pwsh", "pwsh.exe",
})


class TaskStateError(RuntimeError):
    pass


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
    if not isinstance(payload, dict) or payload.get("schema_version") != SCHEMA_VERSION:
        raise TaskStateError(f"unsupported task state schema: {task_id}")
    return payload


def _history(payload: dict[str, Any], event: str, **extra: Any) -> None:
    row = {"at": utc_now(), "event": event}
    row.update({key: value for key, value in extra.items() if value not in (None, "", [])})
    payload.setdefault("history", []).append(row)
    payload["updated_at"] = row["at"]


def is_terminal(payload: dict[str, Any]) -> bool:
    return str(payload.get("status", "")) in TERMINAL_STATES


def _checkpoint_fingerprint(payload: dict[str, Any]) -> str:
    basis = "\n".join(
        [
            str(payload.get("repository", DEFAULT_REPOSITORY)).strip(),
            str(payload.get("task_id", "")).strip(),
            str(payload.get("updated_at", "")).strip(),
            str(payload.get("next_action", "")).strip(),
        ]
    )
    return hashlib.sha256(basis.encode("utf-8")).hexdigest()


def _state_revision(payload: dict[str, Any]) -> str:
    encoded = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def _resume_fingerprint() -> str:
    background = str(os.getenv("SHOPVIVALIZ_RESUME_BACKGROUND", "")).strip()
    fingerprint = str(os.getenv("SHOPVIVALIZ_RESUME_FINGERPRINT", "")).strip()
    if background != "1" and not fingerprint:
        return ""
    if not fingerprint:
        raise TaskStateError("detached resume is missing SHOPVIVALIZ_RESUME_FINGERPRINT")
    return fingerprint


def _require_current_resume(payload: dict[str, Any]) -> str:
    expected = _resume_fingerprint()
    if not expected:
        return ""
    current = _checkpoint_fingerprint(payload)
    if current != expected:
        raise TaskStateError(
            "stale detached resume rejected: checkpoint fingerprint changed after executor start"
        )
    return expected


def _normalize_completion_checks(payload: dict[str, Any]) -> list[list[str]]:
    raw = payload.get("completion_checks")
    if raw in (None, []):
        return []
    if not isinstance(raw, list):
        raise TaskStateError("completion_checks must be a list of argv lists")
    if len(raw) > COMPLETION_CHECK_MAX_COUNT:
        raise TaskStateError(
            f"completion_checks supports at most {COMPLETION_CHECK_MAX_COUNT} checks"
        )

    normalized: list[list[str]] = []
    for index, check in enumerate(raw, start=1):
        if not isinstance(check, list) or not check:
            raise TaskStateError(f"completion check {index} must be a non-empty argv list")
        if len(check) > 64:
            raise TaskStateError(f"completion check {index} has too many argv entries")
        argv: list[str] = []
        for value in check:
            if not isinstance(value, str) or not value or "\x00" in value:
                raise TaskStateError(f"completion check {index} contains an invalid argv value")
            if len(value) > 32768:
                raise TaskStateError(f"completion check {index} argv value is too long")
            argv.append(value)
        executable = Path(argv[0]).name.lower()
        if executable in FORBIDDEN_COMPLETION_CHECK_EXECUTABLES:
            raise TaskStateError(
                f"completion check {index} rejects shell executable {executable}; use direct argv"
            )
        normalized.append(argv)
    return normalized


def _completion_checks_digest(checks: list[list[str]]) -> str:
    encoded = json.dumps(checks, ensure_ascii=False, sort_keys=False, separators=(",", ":"))
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def _run_completion_checks(payload: dict[str, Any]) -> dict[str, Any] | None:
    checks = _normalize_completion_checks(payload)
    if not checks:
        return None

    for index, argv in enumerate(checks, start=1):
        try:
            completed = subprocess.run(
                argv,
                shell=False,
                stdin=subprocess.DEVNULL,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                check=False,
                timeout=COMPLETION_CHECK_TIMEOUT_SECONDS,
            )
        except subprocess.TimeoutExpired as exc:
            raise TaskStateError(
                f"completion check {index} timed out after {COMPLETION_CHECK_TIMEOUT_SECONDS}s"
            ) from exc
        except OSError as exc:
            raise TaskStateError(f"completion check {index} could not execute") from exc
        if int(completed.returncode) != 0:
            raise TaskStateError(
                f"completion check {index} failed with exit code {int(completed.returncode)}"
            )

    return {
        "digest": _completion_checks_digest(checks),
        "count": len(checks),
        "passed_at": utc_now(),
    }


def _validate_completion_receipt(payload: dict[str, Any]) -> None:
    checks = _normalize_completion_checks(payload)
    if not checks:
        return
    receipt = payload.get("completion_checks_receipt")
    if not isinstance(receipt, dict):
        raise TaskStateError("completion checks require a successful READY_TO_COMPLETE receipt")
    if str(receipt.get("digest", "")).strip() != _completion_checks_digest(checks):
        raise TaskStateError("completion checks receipt does not match the declared checks")
    if int(receipt.get("count") or 0) != len(checks):
        raise TaskStateError("completion checks receipt count does not match the declared checks")
    if not str(receipt.get("passed_at", "")).strip():
        raise TaskStateError("completion checks receipt is missing passed_at")


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


@_serialized_transition
def start_task(task_id: str, goal: str, agent_id: str = "", repository: str = "") -> dict[str, Any]:
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
    path = _path(task)
    if path.is_file():
        existing = _load(task)
        if (
            str(existing.get("goal", "")).strip() == goal_text
            and str(existing.get("repository", "")).strip() == repository_name
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
    }
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
    }
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
    payload["status"] = "RUNNING"
    payload["next_action"] = action
    payload.pop("ready_resume_fingerprint", None)
    payload.pop("completion_checks_receipt", None)
    if evidence:
        payload.setdefault("evidence", []).append(str(evidence).strip())
    _history(payload, "progress", next_action=action, evidence=evidence)
    _atomic_write(_path(task_id), payload)
    return payload


def mark_ready(
    task_id: str,
    *,
    evidence: Iterable[str],
    verification: str,
) -> dict[str, Any]:
    evidence_rows = [str(item).strip() for item in evidence if str(item).strip()]
    verification_text = str(verification).strip()
    if not evidence_rows:
        raise TaskStateError("READY_TO_COMPLETE requires fresh evidence")
    if not verification_text:
        raise TaskStateError("READY_TO_COMPLETE requires verification against the original goal")

    with _state_lock():
        payload = _load(task_id)
        if is_terminal(payload):
            raise TaskStateError("terminal task cannot enter READY_TO_COMPLETE")
        resume_fingerprint = _require_current_resume(payload)
        _require_freeze_browser_progress(str(payload.get("task_id", task_id)))
        expected_revision = _state_revision(payload)

    receipt = _run_completion_checks(payload)

    with _state_lock():
        payload = _load(task_id)
        if _state_revision(payload) != expected_revision:
            raise TaskStateError("checkpoint changed while completion checks were running; retry verification")
        if is_terminal(payload):
            raise TaskStateError("terminal task cannot enter READY_TO_COMPLETE")
        current_resume_fingerprint = _require_current_resume(payload)
        if current_resume_fingerprint != resume_fingerprint:
            raise TaskStateError("stale detached resume rejected during terminal readiness")
        _require_freeze_browser_progress(str(payload.get("task_id", task_id)))

        payload.setdefault("evidence", []).extend(evidence_rows)
        payload["verification"] = verification_text
        payload["status"] = "READY_TO_COMPLETE"
        payload["next_action"] = ""
        if receipt is not None:
            payload["completion_checks_receipt"] = receipt
        else:
            payload.pop("completion_checks_receipt", None)
        if resume_fingerprint:
            payload["ready_resume_fingerprint"] = resume_fingerprint
        else:
            payload.pop("ready_resume_fingerprint", None)
        _history(
            payload,
            "ready_to_complete",
            evidence=evidence_rows,
            verification=verification_text,
            completion_checks=receipt,
        )
        _atomic_write(_path(task_id), payload)
        return payload


def complete_task(task_id: str) -> dict[str, Any]:
    with _state_lock():
        payload = _load(task_id)
        if payload.get("status") != "READY_TO_COMPLETE":
            raise TaskStateError("completion rejected: task must pass READY_TO_COMPLETE first")
        if payload.get("next_action"):
            raise TaskStateError("completion rejected: executable next_action still exists")
        if not payload.get("evidence") or not payload.get("verification"):
            raise TaskStateError("completion rejected: verification evidence is missing")
        _require_freeze_browser_progress(str(payload.get("task_id", task_id)))

        resume_fingerprint = _resume_fingerprint()
        if resume_fingerprint:
            ready_resume_fingerprint = str(payload.get("ready_resume_fingerprint", "")).strip()
            if ready_resume_fingerprint != resume_fingerprint:
                raise TaskStateError(
                    "stale detached resume rejected: READY_TO_COMPLETE belongs to another checkpoint"
                )

        _validate_completion_receipt(payload)
        expected_revision = _state_revision(payload)

    receipt = _run_completion_checks(payload)

    with _state_lock():
        payload = _load(task_id)
        if _state_revision(payload) != expected_revision:
            raise TaskStateError("checkpoint changed while final completion checks were running")
        if payload.get("status") != "READY_TO_COMPLETE":
            raise TaskStateError("completion rejected: task must pass READY_TO_COMPLETE first")
        if payload.get("next_action"):
            raise TaskStateError("completion rejected: executable next_action still exists")
        if not payload.get("evidence") or not payload.get("verification"):
            raise TaskStateError("completion rejected: verification evidence is missing")
        _require_freeze_browser_progress(str(payload.get("task_id", task_id)))

        current_resume_fingerprint = _resume_fingerprint()
        if current_resume_fingerprint:
            ready_resume_fingerprint = str(payload.get("ready_resume_fingerprint", "")).strip()
            if ready_resume_fingerprint != current_resume_fingerprint:
                raise TaskStateError(
                    "stale detached resume rejected: READY_TO_COMPLETE belongs to another checkpoint"
                )

        _validate_completion_receipt(payload)
        if receipt is not None:
            payload["completion_checks_receipt"] = receipt
        payload["status"] = "CONCLUIDO"
        payload["completed_at"] = utc_now()
        _history(payload, "completed", completion_checks=receipt)
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
    _require_current_resume(payload)
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

    show = sub.add_parser("show")
    show.add_argument("--task", required=True)

    terminal = sub.add_parser("terminal")
    terminal.add_argument("--task", required=True)
    return parser


def main() -> int:
    args = _parser().parse_args()
    try:
        if args.command == "start":
            payload = start_task(args.task, args.goal, args.agent, args.repository)
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
