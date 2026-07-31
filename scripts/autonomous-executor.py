#!/usr/bin/env python3
"""Fail-closed compatibility entrypoint for the retired autonomous executor."""
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "artifacts" / "disabled-executors" / "autonomous-executor.json"


def main() -> int:
    payload = {
        "schema_version": 2,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "status": "blocked",
        "executor": "scripts/autonomous-executor.py",
        "reason": "retired executor could mutate queues without reviewed implementation evidence",
        "required_replacement": [
            "explicit task adapter",
            "command or API exit code",
            "read-back verification",
            "commit SHA and pull request when files change",
            "artifact linked to the workflow run"
        ]
    }
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(payload), file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
