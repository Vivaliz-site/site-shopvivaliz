#!/usr/bin/env python3
"""Canonical fail-closed continuous executor entrypoint."""
from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from retired_executor import run_blocked


def main() -> int:
    return run_blocked(
        "scripts/ai/continuous_executor.py",
        "retired executor simulated work, marked tasks complete and published changes without independent validation",
        [
            "auditable isolated branch",
            "real command or API exit code",
            "test artifact",
            "reviewed pull request",
            "verified queue evidence",
        ],
    )


if __name__ == "__main__":
    raise SystemExit(main())
