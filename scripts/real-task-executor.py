#!/usr/bin/env python3
"""Deprecated agent executor.

This file previously generated fictional implementation reports and marked queue
items as completed without changing code, running tests, or producing evidence.
It is intentionally disabled. Real work must happen through a reviewed branch,
commits, CI evidence, and a pull request.
"""

import sys


def main() -> int:
    print(
        "ERROR: scripts/real-task-executor.py is disabled because it did not "
        "execute real tasks. Use the GitHub issue triage workflow and a reviewed "
        "pull request with test evidence instead.",
        file=sys.stderr,
    )
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
