#!/usr/bin/env python3
"""Reject autonomous diffs that cross ShopVivaliz governance boundaries."""
from __future__ import annotations

import argparse
import re
import subprocess
import sys

BLOCKED_PATHS = (
    ".github/workflows/deploy.yml",
    ".github/workflows/promote-staging-to-main.yml",
    "config/runtime-secrets.php",
)

BLOCKED_ADDITIONS = (
    r"(?i)(sale_price|regular_price|promotional_price|markup|discount|desconto)\s*[=:]",
    r"(?i)(campaign|campanha).{0,40}(publish|activate|ativar|publicar)",
    r"(?i)(budget|or[cç]amento).{0,30}(increase|aument)",
    r"(?i)(git push|ftp|rsync).{0,80}(production|produ[cç][aã]o)",
)


def git(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(["git", *args], capture_output=True, text=True)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="HEAD")
    args = parser.parse_args()

    names = git("diff", "--name-only", args.base).stdout.splitlines()
    names += git("ls-files", "--others", "--exclude-standard").stdout.splitlines()
    blocked = sorted({path for path in names if path in BLOCKED_PATHS or path.startswith("storage/private/")})
    if blocked:
        print("BLOCKED_PATHS: " + ", ".join(blocked))
        return 2

    diff = git("diff", "--unified=0", args.base).stdout
    additions = "\n".join(line[1:] for line in diff.splitlines() if line.startswith("+") and not line.startswith("+++"))
    for pattern in BLOCKED_ADDITIONS:
        if re.search(pattern, additions):
            print(f"BLOCKED_CHANGE_PATTERN: {pattern}")
            return 2

    print(f"ALLOWED_AUTONOMOUS_DIFF: {len(set(names))} arquivo(s)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
