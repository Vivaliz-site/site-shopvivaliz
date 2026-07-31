#!/usr/bin/env python3
"""Fail-closed replacement for legacy Shopee credential and sandbox tools."""
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
REPORT = ROOT / "artifacts" / "disabled-executors" / "shopee-legacy-credential-tool.json"


def main() -> int:
    payload = {
        "schema_version": 1,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "status": "blocked",
        "executor": "scripts/marketplace/shopee/retired_credential_tool.py",
        "external_operation_performed": False,
        "reason": "legacy Shopee tool contained hardcoded credentials or tokens and could call provider endpoints",
        "required_replacement": [
            "protected SHOPEE_* secrets",
            "manual authorization through the provider UI",
            "redacted request identifiers",
            "read-back verification",
            "immutable workflow artifact",
        ],
    }
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(payload), file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
