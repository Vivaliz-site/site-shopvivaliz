#!/usr/bin/env python3
"""Fail-closed compatibility entrypoint for the retired AI marketplace pipeline."""
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REPORT = ROOT / "artifacts" / "disabled-executors" / "pipeline-orchestrator.json"


def main() -> int:
    payload = {
        "schema_version": 2,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "status": "blocked",
        "executor": "scripts/automation/pipeline_orchestrator.py",
        "reason": "retired pipeline mixed sample products and generated test outcomes with marketplace update attempts",
        "required_replacement": [
            "real catalog input only",
            "no generated performance or test outcomes",
            "marketplace failures propagated",
            "backup and read-back verification",
            "immutable run artifact"
        ]
    }
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(payload), file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
