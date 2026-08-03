#!/usr/bin/env python3
"""Canonical fail-closed entrypoint for the retired Olist sync master.

This module intentionally performs no provider mutation. A future replacement
must use authenticated provider requests, persist redacted request identifiers,
verify item counts through read-back, and publish immutable run evidence.
"""
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
REPORT = ROOT / "artifacts" / "disabled-executors" / "olist-sync-master.json"


def build_report() -> dict[str, object]:
    return {
        "schema_version": 2,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "status": "blocked",
        "executor": "scripts/marketplace/olist/sync_master.py",
        "external_operation_performed": False,
        "reason": (
            "retired sync returned success for products, images, tokens and stock "
            "without verified provider API operations"
        ),
        "required_replacement": [
            "authenticated provider request",
            "redacted request and response identifiers",
            "item counts",
            "read-back verification",
            "immutable artifact",
        ],
    }


def main() -> int:
    payload = build_report()
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(payload), file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
