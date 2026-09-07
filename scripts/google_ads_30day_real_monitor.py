#!/usr/bin/env python3
"""Compatibility entrypoint for the former synthetic 30-day Ads monitor.

The old script mixed local files with assumed spend and labelled the result as real.
It is intentionally retired. Use the provider-backed readiness probe instead.
"""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path


def main() -> int:
    root = Path(__file__).resolve().parents[1]
    readiness = root / "scripts" / "google_ads_real_readiness.py"
    print(
        "Legacy 30-day monitor retired: synthetic campaign metrics are not trusted. "
        "Delegating to google_ads_real_readiness.py.",
        file=sys.stderr,
    )
    completed = subprocess.run([sys.executable, str(readiness)], cwd=root, check=False)
    return int(completed.returncode)


if __name__ == "__main__":
    raise SystemExit(main())
