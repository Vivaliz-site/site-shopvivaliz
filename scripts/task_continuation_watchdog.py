#!/usr/bin/env python3
"""Emit deduplicated resume requests for stale non-terminal task checkpoints.

This watchdog is intentionally deterministic. It never invokes an AI provider,
shell command, browser, network client, or paid executor. It only converts a
stale durable checkpoint into a persistent resume request that another finite
executor can consume.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

try:
    from .agent_task_state import RUNTIME_DIR
except ImportError:  # direct CLI execution from repository root
    from agent_task_state import RUNTIME_DIR

REQUESTS_FILE = "_resume-requests.jsonl"
DEFAULT_STALE_SECONDS = 120


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _parse_time(value: Any) -> datetime | None:
    raw = str(value or "").strip()
    if not raw:
        return None
    try:
        parsed = datetime.fromisoformat(raw.replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def _state_files(runtime_dir: Path) -> list[Path]:
    if not runtime_dir.exists():
        return []
    return sorted(
        path
        for path in runtime_dir.glob("*.json")
        if path.is_file() and not path.name.startswith("_")
    )


def _read_state(path: Path) -> dict[str, Any] | None:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    return payload if isinstance(payload, dict) else None


def _fingerprint(payload: dict[str, Any]) -> str:
    basis = "\n".join(
        [
            str(payload.get("task_id", "")).strip(),
            str(payload.get("updated_at", "")).strip(),
            str(payload.get("next_action", "")).strip(),
        ]
    )
    return hashlib.sha256(basis.encode("utf-8")).hexdigest()


def read_requests(runtime_dir: Path | None = None) -> list[dict[str, Any]]:
    root = Path(runtime_dir or RUNTIME_DIR)
    path = root / REQUESTS_FILE
    if not path.is_file():
        return []
    rows: list[dict[str, Any]] = []
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        if not line.strip():
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(row, dict):
            rows.append(row)
    return rows


def _append_request(runtime_dir: Path, row: dict[str, Any]) -> None:
    runtime_dir.mkdir(parents=True, exist_ok=True)
    path = runtime_dir / REQUESTS_FILE
    with path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n")


def run_once(
    *,
    stale_seconds: int = DEFAULT_STALE_SECONDS,
    runtime_dir: Path | None = None,
    now: datetime | None = None,
) -> dict[str, Any]:
    root = Path(runtime_dir or RUNTIME_DIR)
    current = (now or datetime.now(timezone.utc)).astimezone(timezone.utc)
    cutoff = max(1, int(stale_seconds))

    existing = {
        str(row.get("fingerprint", "")).strip()
        for row in read_requests(root)
        if str(row.get("fingerprint", "")).strip()
    }

    scanned = 0
    eligible = 0
    dispatched = 0

    for path in _state_files(root):
        scanned += 1
        payload = _read_state(path)
        if not payload:
            continue
        if str(payload.get("status", "")).strip() != "RUNNING":
            continue

        next_action = str(payload.get("next_action", "")).strip()
        if not next_action:
            continue

        updated = _parse_time(payload.get("updated_at"))
        if updated is None:
            continue

        age_seconds = (current - updated).total_seconds()
        if age_seconds < cutoff:
            continue

        eligible += 1
        fingerprint = _fingerprint(payload)
        if fingerprint in existing:
            continue

        task_id = str(payload.get("task_id", "")).strip()
        if not task_id:
            continue

        request = {
            "id": f"resume-{fingerprint[:20]}",
            "kind": "auto_resume",
            "status": "queued",
            "task_id": task_id,
            "agent_id": str(payload.get("agent_id", "")).strip() or "gpt",
            "goal": str(payload.get("goal", "")).strip(),
            "next_action": next_action,
            "checkpoint_updated_at": str(payload.get("updated_at", "")).strip(),
            "checkpoint_age_seconds": int(max(0, age_seconds)),
            "fingerprint": fingerprint,
            "created_at": utc_now(),
        }
        _append_request(root, request)
        existing.add(fingerprint)
        dispatched += 1

    return {
        "ok": True,
        "runtime_dir": str(root),
        "stale_seconds": cutoff,
        "scanned": scanned,
        "eligible": eligible,
        "dispatched": dispatched,
        "generated_at": utc_now(),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Emit resume requests for stale RUNNING task checkpoints.")
    parser.add_argument("--stale-seconds", type=int, default=DEFAULT_STALE_SECONDS)
    parser.add_argument("--runtime-dir", default="")
    args = parser.parse_args()

    runtime_dir = Path(args.runtime_dir).expanduser() if args.runtime_dir else None
    result = run_once(stale_seconds=args.stale_seconds, runtime_dir=runtime_dir)
    print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
