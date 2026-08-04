#!/usr/bin/env python3
"""Fail-closed health guardian for canonical production-agent evidence.

The legacy guardian attempted queue generation, task selection, a retired
executor and service restarts, then returned zero regardless of the outcome.
This implementation is intentionally read-only: it validates the canonical
agent-cycle evidence and returns non-zero whenever the evidence is missing,
stale or inconsistent.
"""
from __future__ import annotations

import json
import re
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
EVIDENCE_PATH = ROOT / "storage" / "agent-evidence" / "latest-agent-cycle.json"
RELEASE_SHA_PATH = ROOT / ".release-sha"
REPORT_PATH = ROOT / "logs" / "autonomous-hourly-guardian.json"
EVENTS_PATH = ROOT / "logs" / "autonomous-hourly-guardian.jsonl"
MAX_AGE_SECONDS = 1800
EXPECTED_AGENTS = {
    "operational_work",
    "catalog_metadata",
    "conversion_funnel",
    "media_quality",
    "media_mismatch",
}


def now() -> datetime:
    return datetime.now(timezone.utc)


def parse_time(value: object) -> datetime | None:
    if not isinstance(value, str) or not value.strip():
        return None
    try:
        parsed = datetime.fromisoformat(value.strip().replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def write_report(payload: dict[str, Any]) -> None:
    REPORT_PATH.parent.mkdir(parents=True, exist_ok=True)
    serialized = json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
    REPORT_PATH.write_text(serialized, encoding="utf-8")
    with EVENTS_PATH.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(payload, ensure_ascii=False) + "\n")


def main() -> int:
    checked_at = now()
    errors: list[str] = []
    evidence: dict[str, Any] = {}

    if not EVIDENCE_PATH.is_file() or EVIDENCE_PATH.stat().st_size == 0:
        errors.append("canonical_agent_evidence_missing")
    else:
        try:
            raw = json.loads(EVIDENCE_PATH.read_text(encoding="utf-8"))
            if isinstance(raw, dict):
                evidence = raw
            else:
                errors.append("canonical_agent_evidence_invalid_type")
        except (OSError, ValueError) as exc:
            errors.append(f"canonical_agent_evidence_unreadable:{exc}")

    finished = parse_time(evidence.get("finished_at") or evidence.get("generated_at"))
    age_seconds: int | None = None
    if evidence and finished is None:
        errors.append("canonical_agent_evidence_timestamp_invalid")
    elif finished is not None:
        age_seconds = int((checked_at - finished).total_seconds())
        if age_seconds < -120 or age_seconds > MAX_AGE_SECONDS:
            errors.append(f"canonical_agent_evidence_stale:{age_seconds}")

    release_sha = RELEASE_SHA_PATH.read_text(encoding="utf-8").strip() if RELEASE_SHA_PATH.is_file() else ""
    if re.fullmatch(r"[0-9a-f]{40}", release_sha) is None:
        errors.append("release_sha_invalid_or_missing")

    active_agents = set(evidence.get("active_agents", [])) if evidence else set()
    checks = {
        "execution_accepted": evidence.get("execution_accepted") is True,
        "execution_completed": evidence.get("execution_completed") is True,
        "execution_status": evidence.get("execution_status") == "completed",
        "active_agents": active_agents == EXPECTED_AGENTS,
        "active_agent_count": int(evidence.get("active_agent_count", 0) or 0) == 5,
        "work_evidence_count": int(evidence.get("work_evidence_count", 0) or 0) >= 12,
        "registry_sha256": re.fullmatch(r"[0-9a-f]{64}", str(evidence.get("registry_sha256", ""))) is not None,
    }
    errors.extend(f"failed_check:{name}" for name, passed in checks.items() if not passed)

    evidence_release_sha = str(evidence.get("release_sha", "") or evidence.get("commit_sha", ""))
    if evidence_release_sha:
        if evidence_release_sha != release_sha:
            errors.append("evidence_release_sha_mismatch")

    payload = {
        "generated_at": checked_at.isoformat().replace("+00:00", "Z"),
        "status": "HEALTHY" if not errors else "CRITICAL",
        "ok": not errors,
        "read_only": True,
        "queue_modified": False,
        "service_restarted": False,
        "release_sha": release_sha or None,
        "cycle_id": evidence.get("cycle_id"),
        "evidence_finished_at": finished.isoformat().replace("+00:00", "Z") if finished else None,
        "evidence_age_seconds": age_seconds,
        "evidence_path": str(EVIDENCE_PATH.relative_to(ROOT)),
        "checks": checks,
        "errors": errors,
    }
    write_report(payload)
    print(json.dumps(payload, ensure_ascii=False, indent=2))
    return 0 if not errors else 2


if __name__ == "__main__":
    raise SystemExit(main())
