#!/usr/bin/env python3
"""Fail-closed certifier for ChatGPT same-conversation continuity evidence."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path
from typing import Any

EXPECTED_VERIFICATION = "continuity_e2e_pass"
NUDGE_LEDGER = "_chatgpt-continuity-nudges.jsonl"
CONVERSATION_RE = re.compile(r"^[A-Za-z0-9_-]{8,160}$")


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
    for raw in path.read_text(encoding="utf-8", errors="replace").splitlines():
        if not raw.strip():
            continue
        try:
            value = json.loads(raw)
        except json.JSONDecodeError:
            continue
        if isinstance(value, dict):
            rows.append(value)
    return rows


def _fingerprint(repository: str, task_id: str, updated_at: str, next_action: str) -> str:
    basis = "\n".join([repository.strip(), task_id.strip(), updated_at.strip(), next_action.strip()])
    return hashlib.sha256(basis.encode("utf-8")).hexdigest()


def _historical_fingerprints(state: dict[str, Any]) -> set[str]:
    repository = str(state.get("repository", "")).strip()
    task_id = str(state.get("task_id", "")).strip()
    result: set[str] = set()
    for row in state.get("history", []):
        if not isinstance(row, dict):
            continue
        next_action = str(row.get("next_action", "")).strip()
        at = str(row.get("at", "")).strip()
        if repository and task_id and at and next_action:
            result.add(_fingerprint(repository, task_id, at, next_action))
    return result


def certify(runtime_dir: Path, task_id: str) -> dict[str, Any]:
    state = _read_json(runtime_dir / f"{task_id}.json")
    failures: list[str] = []

    if not state:
        failures.append("task_state_missing")
        return {"ok": False, "task_id": task_id, "failures": failures}

    if str(state.get("status", "")).strip() != "CONCLUIDO":
        failures.append("task_not_concluido")
    if str(state.get("verification", "")).strip() != EXPECTED_VERIFICATION:
        failures.append("verification_not_continuity_e2e_pass")

    conversation_id = str(state.get("conversation_id", "")).strip()
    if not CONVERSATION_RE.fullmatch(conversation_id):
        failures.append("explicit_conversation_binding_missing")

    historical_fingerprints = _historical_fingerprints(state)
    matching = [
        row
        for row in _read_jsonl(runtime_dir / NUDGE_LEDGER)
        if str(row.get("task_id", "")).strip() == task_id
    ]
    confirmed = [
        row
        for row in matching
        if str(row.get("worker_status", "")).strip().upper() == "PROGRESS_CONFIRMED"
        and str(row.get("worker_status_observed_at", "")).strip()
        and str(row.get("conversation_id", "")).strip() == conversation_id
        and str(row.get("fingerprint", "")).strip() in historical_fingerprints
    ]
    if not confirmed:
        failures.append("no_bound_progress_confirmed_for_checkpoint")

    latest_by_fingerprint: dict[str, dict[str, Any]] = {}
    for row in matching:
        fp = str(row.get("fingerprint", "")).strip()
        if fp:
            latest_by_fingerprint[fp] = row
    confirmed_latest = [
        row
        for row in confirmed
        if latest_by_fingerprint.get(str(row.get("fingerprint", "")).strip()) is row
    ]
    if confirmed and not confirmed_latest:
        failures.append("progress_confirmed_superseded_by_later_worker_result")

    proof = confirmed_latest[-1] if confirmed_latest else (confirmed[-1] if confirmed else {})
    return {
        "ok": not failures,
        "task_id": task_id,
        "status": str(state.get("status", "")).strip(),
        "verification": str(state.get("verification", "")).strip(),
        "conversation_id": conversation_id if CONVERSATION_RE.fullmatch(conversation_id) else "",
        "fingerprint": str(proof.get("fingerprint", "")).strip(),
        "worker_status": str(proof.get("worker_status", "")).strip().upper(),
        "worker_status_observed_at": str(proof.get("worker_status_observed_at", "")).strip(),
        "send_attempt_count": int(proof.get("send_attempt_count") or 0) if proof else 0,
        "failures": failures,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Certify same-conversation ChatGPT continuity without false-green.")
    parser.add_argument("--runtime-dir", required=True)
    parser.add_argument("--task", required=True)
    args = parser.parse_args()
    result = certify(Path(args.runtime_dir).expanduser(), args.task)
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0 if result["ok"] else 3


if __name__ == "__main__":
    raise SystemExit(main())
