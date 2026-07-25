#!/usr/bin/env python3
"""Deprecated continuous executor.

The previous implementation simulated work, marked tasks complete, and pushed
commits without independent validation. It is intentionally disabled.
"""

import sys


def main() -> int:
    print(
        "ERROR: scripts/continuous-executor.py is disabled. Continuous agent "
        "work must create auditable branches, commits, test artifacts, and pull "
        "requests; queue state alone is not proof of completion.",
        file=sys.stderr,
    )
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
