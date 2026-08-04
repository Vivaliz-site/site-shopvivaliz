#!/usr/bin/env python3
"""Read-only compatibility observer for the retired operations worker.

This file used to assign pending tasks, print validation commands without
executing them, emit synthetic ``alive`` heartbeats and acknowledge commands as
successful. It now validates only the immutable evidence written by the
canonical production-agent orchestrator and never changes queues or commands.
"""
from __future__ import annotations

import json
import re
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
EVIDENCE_PATH = ROOT / "storage" / "agent-evidence" / "latest-agent-cycle.json"
REPORT_PATH = ROOT / "logs" / "agent-operations-observer.json"
MAX_AGE_SECONDS = 1800
EXPECTED_AGENTS = {
    "operational_work",
    "catalog_metadata",
    "conversion_funnel",
    "media_quality",
    "media_mismatch",
}


def utc_now() -> datetime:
    return datetime.now(timezone.utc)


def fail(reason: str, details: dict[str, Any] | None = None) -> int:
    payload: dict[str, Any] = {
        "ok": False,
        "status": "blocked",
        "queue_modified": False,
        "commands_executed": 0,
        "reason": reason,
        "checked_at": utc_now().isoformat().replace("+00:00", "Z"),
    }
    if details:
        payload["details"] = details
    REPORT_PATH.parent.mkdir(parents=True, exist_ok=True)
    REPORT_PATH.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 2


def parse_time(raw: object) -> datetime | None:
    if not isinstance(raw, str) or not raw.strip():
        return None
    try:
        value = datetime.fromisoformat(raw.strip().replace("Z", "+00:00"))
    except ValueError:
        return None
    if value.tzinfo is None:
        value = value.replace(tzinfo=timezone.utc)
    return value.astimezone(timezone.utc)


def main() -> int:
    if not EVIDENCE_PATH.is_file() or EVIDENCE_PATH.stat().st_size == 0:
        return fail("canonical_agent_evidence_missing", {"path": str(EVIDENCE_PATH.relative_to(ROOT))})

    try:
        payload = json.loads(EVIDENCE_PATH.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        return fail("canonical_agent_evidence_unreadable", {"error": str(exc)})
    if not isinstance(payload, dict):
        return fail("canonical_agent_evidence_invalid_type")

    finished = parse_time(payload.get("finished_at") or payload.get("generated_at"))
    if finished is None:
        return fail("canonical_agent_evidence_timestamp_invalid")
    age = (utc_now() - finished).total_seconds()
    if age < -120 or age > MAX_AGE_SECONDS:
        return fail("canonical_agent_evidence_stale", {"age_seconds": int(age)})

    active_agents = set(payload.get("active_agents", []))
    checks = {
        "execution_accepted": payload.get("execution_accepted") is True,
        "execution_completed": payload.get("execution_completed") is True,
        "execution_status": payload.get("execution_status") == "completed",
        "active_agents": active_agents == EXPECTED_AGENTS,
        "work_evidence_count": int(payload.get("work_evidence_count", 0) or 0) >= 12,
        "registry_sha256": re.fullmatch(r"[0-9a-f]{64}", str(payload.get("registry_sha256", ""))) is not None,
    }
    failed = sorted(name for name, passed in checks.items() if not passed)
    if failed:
        return fail("canonical_agent_evidence_failed_validation", {"failed_checks": failed})

    report = {
        "ok": True,
        "status": "verified_observation",
        "queue_modified": False,
        "commands_executed": 0,
        "checked_at": utc_now().isoformat().replace("+00:00", "Z"),
        "cycle_id": payload.get("cycle_id"),
        "finished_at": finished.isoformat().replace("+00:00", "Z"),
        "age_seconds": int(age),
        "active_agent_count": len(active_agents),
        "work_evidence_count": int(payload.get("work_evidence_count", 0) or 0),
        "evidence_path": str(EVIDENCE_PATH.relative_to(ROOT)),
        "checks": checks,
    }
    REPORT_PATH.parent.mkdir(parents=True, exist_ok=True)
    REPORT_PATH.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
