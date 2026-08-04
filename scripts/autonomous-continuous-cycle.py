#!/usr/bin/env python3
"""Retired compatibility entrypoint for the legacy queue selector.

The previous implementation could move a task to ``in_progress`` before any
executor performed work. Real production agents are executed by
``api/agent/real-work-orchestrator.php`` and must produce immutable evidence.
This entrypoint therefore never changes the queue and always fails closed.
"""
from __future__ import annotations

import argparse
import json
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT_JSON = ROOT / "logs" / "autonomous-cycle-report.json"
REPORT_MD = ROOT / "logs" / "autonomous-cycle-report.md"


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def main() -> int:
    parser = argparse.ArgumentParser(description="Retired legacy queue selector")
    parser.add_argument("--advance", action="store_true")
    args = parser.parse_args()

    payload = {
        "generated_at": utc_now(),
        "ok": False,
        "status": "blocked",
        "queue_modified": False,
        "advance_requested": bool(args.advance),
        "replacement": "api/agent/real-work-orchestrator.php",
        "reason": (
            "legacy selector retired: a task may only change state after real "
            "execution, read-back verification and immutable evidence"
        ),
    }
    REPORT_JSON.parent.mkdir(parents=True, exist_ok=True)
    REPORT_JSON.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    REPORT_MD.write_text(
        "# Legacy autonomous cycle blocked\n\n"
        f"- Generated: `{payload['generated_at']}`\n"
        "- Queue modified: `false`\n"
        f"- Replacement: `{payload['replacement']}`\n"
        f"- Reason: {payload['reason']}\n",
        encoding="utf-8",
    )
    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
