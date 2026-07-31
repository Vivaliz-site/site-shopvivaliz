#!/usr/bin/env python3
"""Canonical fail-closed parallel executor entrypoint."""
from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from retired_executor import run_blocked


def main() -> int:
    return run_blocked(
        "scripts/ai/parallel_executor.py",
        "retired executor marked tasks complete and recorded consensus without independent votes or execution evidence",
        [
            "one signed result per agent",
            "individual failure propagation",
            "canonical tasks queue adapter",
            "completion evidence with run id, artifact, commit SHA and verification",
        ],
    )


if __name__ == "__main__":
    raise SystemExit(main())
