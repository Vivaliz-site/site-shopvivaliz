#!/usr/bin/env python3
"""Shared fail-closed implementation for retired agent executors."""
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REPORT_DIR = ROOT / "artifacts" / "disabled-executors"


def run_blocked(
    executor: str,
    reason: str,
    required_replacement: list[str],
) -> int:
    payload = {
        "schema_version": 2,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "status": "blocked",
        "executor": executor,
        "external_operation_performed": False,
        "reason": reason,
        "required_replacement": required_replacement,
    }
    report_name = Path(executor).stem.replace("_", "-") + ".json"
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    (REPORT_DIR / report_name).write_text(
        json.dumps(payload, indent=2) + "\n", encoding="utf-8"
    )
    print(json.dumps(payload), file=sys.stderr)
    return 2
