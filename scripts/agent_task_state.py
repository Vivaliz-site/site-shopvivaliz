#!/usr/bin/env python3
"""Durable task-continuity state for ShopVivaliz agents.

The state machine deliberately has only two terminal states:
- CONCLUIDO: reached only through READY_TO_COMPLETE with verification evidence.
- BLOCKED_EXTERNAL: reached only for a proved external blocker after safe alternatives.

All other states are non-terminal and must retain a concrete next action.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

ROOT = Path(__file__).resolve().parents[1]
RUNTIME_DIR = ROOT / "storage" / "private" / "agent-task-state"
TERMINAL_STATES = frozenset({"CONCLUIDO", "BLOCKED_EXTERNAL"})
NON_TERMINAL_STATES = frozenset({"RUNNING", "READY_TO_COMPLETE"})
SCHEMA_VERSION = 1


class TaskStateError(RuntimeError):
    pass


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _safe_id(value: str, label: str) -> str:
    normalized = re.sub(r"[^A-Za-z0-9._-]+", "-", str(value).strip()).strip("-.")
    if not normalized:
        raise TaskStateError(f"{label} is required")
    return normalized[:160]


def _path(task_id: str) -> Path:
    return RUNTIME_DIR / f"{_safe_id(task_id, 'task_id')}.json"


def _atomic_write(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    tmp = Path(tmp_name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, ensure_ascii=False, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp, path)
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


def start_task(task_id: str, goal: str, agent_id: str = "") -> dict[str, Any]:
    task = _safe_id(task_id, "task_id")
    goal_text = str(goal).strip()
    if not goal_text:
        raise TaskStateError("goal is required")
    now = utc_now()
    payload = {
        "schema_version": SCHEMA_VERSION,
        "task_id": task,
        "agent_id": str(agent_id).strip(),
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
    _atomic_write(_path(task), payload)
    return payload


def record_progress(task_id: str, *, next_action: str, evidence: str | None = None) -> dict[str, Any]:
    payload = _load(task_id)
    if is_terminal(payload):
        raise TaskStateError("terminal task cannot record progress; resume a blocked task explicitly")
    action = str(next_action).strip()
    if not action:
        raise TaskStateError("non-terminal task requires a concrete next_action")
    payload["status"] = "RUNNING"
    payload["next_action"] = action
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
    payload = _load(task_id)
    if is_terminal(payload):
        raise TaskStateError("terminal task cannot enter READY_TO_COMPLETE")
    evidence_rows = [str(item).strip() for item in evidence if str(item).strip()]
    verification_text = str(verification).strip()
    if not evidence_rows:
        raise TaskStateError("READY_TO_COMPLETE requires fresh evidence")
    if not verification_text:
        raise TaskStateError("READY_TO_COMPLETE requires verification against the original goal")
    payload.setdefault("evidence", []).extend(evidence_rows)
    payload["verification"] = verification_text
    payload["status"] = "READY_TO_COMPLETE"
    payload["next_action"] = ""
    _history(payload, "ready_to_complete", evidence=evidence_rows, verification=verification_text)
    _atomic_write(_path(task_id), payload)
    return payload


def complete_task(task_id: str) -> dict[str, Any]:
    payload = _load(task_id)
    if payload.get("status") != "READY_TO_COMPLETE":
        raise TaskStateError("completion rejected: task must pass READY_TO_COMPLETE first")
    if payload.get("next_action"):
        raise TaskStateError("completion rejected: executable next_action still exists")
    if not payload.get("evidence") or not payload.get("verification"):
        raise TaskStateError("completion rejected: verification evidence is missing")
    payload["status"] = "CONCLUIDO"
    payload["completed_at"] = utc_now()
    _history(payload, "completed")
    _atomic_write(_path(task_id), payload)
    return payload


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
            payload = start_task(args.task, args.goal, args.agent)
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
