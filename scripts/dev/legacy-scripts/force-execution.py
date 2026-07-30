#!/usr/bin/env python3
"""Deprecated force-completion utility.

The former script changed pending tasks to completed without executing or
validating them. It is intentionally disabled to preserve queue integrity.
"""

import sys


def main() -> int:
    print(
        "ERROR: scripts/force-execution.py is disabled. A task may only be "
        "completed after verifiable execution evidence exists (commit, tests, "
        "artifacts, and review).",
        file=sys.stderr,
    )
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
