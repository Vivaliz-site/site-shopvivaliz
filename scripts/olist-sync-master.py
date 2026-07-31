#!/usr/bin/env python3
"""Fail-closed compatibility entrypoint for the retired Olist sync master."""
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "artifacts" / "disabled-executors" / "olist-sync-master.json"


def main() -> int:
    payload = {
        "schema_version": 2,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "status": "blocked",
        "executor": "scripts/olist-sync-master.py",
        "reason": "retired sync returned success for products images tokens and stock without verified provider API operations",
        "required_replacement": [
            "authenticated provider request",
            "request and response identifiers",
            "item counts",
            "read-back verification",
            "artifact with redacted evidence"
        ]
    }
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(payload), file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
