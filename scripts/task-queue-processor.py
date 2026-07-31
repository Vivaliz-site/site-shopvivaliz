#!/usr/bin/env python3
"""Deprecated synthetic task queue processor.

The former implementation invented default tasks and marked them assigned
without creating a real execution record, branch, commit, or review request.
It is intentionally disabled.
"""

import sys


def main() -> int:
    print(
        "ERROR: scripts/task-queue-processor.py is disabled. Tasks must originate "
        "from an auditable source such as a GitHub issue and may only advance "
        "after verifiable execution evidence exists.",
        file=sys.stderr,
    )
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
