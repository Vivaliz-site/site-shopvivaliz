#!/usr/bin/env python3
"""Fail-closed compatibility entrypoint for the retired Git auto-sync master."""
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "artifacts" / "disabled-executors" / "git-auto-sync-master.json"


def main() -> int:
    payload = {
        "schema_version": 2,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "status": "blocked",
        "executor": "scripts/git-auto-sync-master.py",
        "reason": "retired sync could discard uncommitted files ignore validation failures and clear runtime caches",
        "required_replacement": [
            "abort on dirty checkout",
            "explicit branch and expected SHA",
            "complete validation with propagated exit codes",
            "no cache deletion during source synchronization",
            "reviewed pull request for publication"
        ]
    }
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(payload), file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
