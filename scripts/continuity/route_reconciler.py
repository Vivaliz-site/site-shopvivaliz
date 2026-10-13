"""Recover exact ChatGPT routes only from attested, matching worker receipts.

Never infer a conversation from a task title, agent label, browser tab count,
timestamp proximity, or unconfirmed browser submission. A missing route stays
visible and cannot be silently replaced with another user's conversation.
"""
from __future__ import annotations

import json
import re
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

try:
    from .. import agent_task_state
    from ..task_resume_queue import checkpoint_fingerprint
except ImportError:
    from scripts import agent_task_state
    from scripts.task_resume_queue import checkpoint_fingerprint

LEDGER_FILE = "_chatgpt-continuity-nudges.jsonl"
CONVERSATION_ID = re.compile(r"[A-Za-z0-9_-]{8,160}\Z")
SESSIONS = frozenset({"dev", "atendimento"})
LOOKBACK_DAYS = 10


def _utc(value: Any) -> datetime | None:
    try:
        parsed = datetime.fromisoformat(str(value or "").replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def reconcile(runtime_dir: Path, *, now: datetime | None = None) -> dict[str, int]:
    """Bind an unbound checkpoint only if a real worker attested its exact route.

    The worker receipt must be PROGRESS_CONFIRMED for the current checkpoint
    fingerprint and contain both a conversation ID and the authenticated
    browser profile. A partial checkpoint session can supply that profile,
    but a generic agent ID cannot. Conflicting receipts are quarantined.
    """
    root = Path(runtime_dir)
    summary = {"scanned": 0, "bound": 0, "ambiguous": 0,
               "skipped_no_proof": 0, "skipped_stale": 0, "failed": 0}
    proofs: dict[tuple[str, str], set[tuple[str, str]]] = {}
    path = root / LEDGER_FILE
    if path.is_file():
        try:
            with path.open(encoding="utf-8", errors="replace") as stream:
                for line in stream:
                    try:
                        row = json.loads(line)
                    except (ValueError, TypeError):
                        continue
                    if not isinstance(row, dict) or row.get("worker_status") != "PROGRESS_CONFIRMED":
                        continue
                    task_id = str(row.get("task_id", "")).strip()
                    fingerprint = str(row.get("fingerprint", "")).strip()
                    cid = str(row.get("conversation_id", "")).strip()
                    session = str(row.get("browser_session", "")).strip()
                    if not task_id or not fingerprint or not CONVERSATION_ID.fullmatch(cid):
                        continue
                    if session and session not in SESSIONS:
                        continue
                    proofs.setdefault((task_id, fingerprint), set()).add((cid, session))
        except OSError:
            summary["failed"] += 1
            return summary

    current = (now or datetime.now(timezone.utc)).astimezone(timezone.utc)
    old_runtime = agent_task_state.RUNTIME_DIR
    agent_task_state.RUNTIME_DIR = root
    try:
        for state_path in sorted(root.glob("*.json")):
            if state_path.name.startswith("_") or not state_path.is_file():
                continue
            try:
                payload = json.loads(state_path.read_text(encoding="utf-8"))
            except (OSError, ValueError):
                continue
            if not isinstance(payload, dict) or payload.get("status") != "RUNNING":
                continue
            created = _utc(payload.get("created_at"))
            if created is None or created < current - timedelta(days=LOOKBACK_DAYS):
                summary["skipped_stale"] += 1
                continue
            task_id = str(payload.get("task_id", "")).strip()
            if not task_id or state_path.name != task_id + ".json":
                continue
            existing_cid = str(payload.get("conversation_id", "")).strip()
            existing_session = str(payload.get("browser_session", "")).strip()
            if existing_cid and existing_session in SESSIONS:
                continue
            summary["scanned"] += 1
            candidates = set()
            for cid, session in proofs.get((task_id, checkpoint_fingerprint(payload)), set()):
                resolved_session = session or existing_session
                if resolved_session not in SESSIONS:
                    continue
                if existing_cid and existing_cid != cid:
                    continue
                if existing_session and existing_session != resolved_session:
                    continue
                candidates.add((cid, resolved_session))
            if not candidates:
                summary["skipped_no_proof"] += 1
                continue
            if len(candidates) != 1:
                summary["ambiguous"] += 1
                continue
            cid, session = next(iter(candidates))
            try:
                agent_task_state.bind_route(
                    task_id, conversation_id=cid, browser_session=session,
                    expected_checkpoint_version=int(payload.get("checkpoint_version") or 1),
                )
            except (agent_task_state.TaskStateError, OSError, ValueError):
                summary["failed"] += 1
            else:
                summary["bound"] += 1
    finally:
        agent_task_state.RUNTIME_DIR = old_runtime
    return summary
