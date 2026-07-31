#!/usr/bin/env python3
"""Fail-closed compatibility entrypoint for the retired parallel executor."""
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "artifacts" / "disabled-executors" / "parallel-executor.json"


def main() -> int:
    payload = {
        "schema_version": 2,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "status": "blocked",
        "executor": "scripts/parallel-executor.py",
        "reason": "retired executor could mark tasks complete and record consensus without independent votes or execution evidence",
        "required_replacement": [
            "one signed result per agent",
            "individual failure propagation",
            "canonical tasks queue adapter",
            "completion evidence with run id artifact commit SHA and verification"
        ]
    }
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(payload), file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
