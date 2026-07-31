#!/usr/bin/env python3
"""Canonical fail-closed autonomous executor entrypoint."""
from __future__ import annotations

from retired_executor import run_blocked


def main() -> int:
    return run_blocked(
        "scripts/ai/autonomous_executor.py",
        "retired executor could mutate queues without reviewed implementation evidence",
        [
            "explicit task adapter",
            "command or API exit code",
            "read-back verification",
            "commit SHA and pull request when files change",
            "artifact linked to the workflow run",
        ],
    )


if __name__ == "__main__":
    raise SystemExit(main())
