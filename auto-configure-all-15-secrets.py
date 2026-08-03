#!/usr/bin/env python3
"""Retired legacy secret configurator.

This entrypoint previously accepted credential values, printed fragments and
mutated secrets in an obsolete repository. Secret changes must be performed
manually through a reviewed administrator session or an approved secret manager.
"""
from __future__ import annotations

import sys


def main() -> int:
    print(
        "This legacy secret configurator is retired. "
        "Use docs/knowledge/secrets-and-integrations-map.md and a reviewed "
        "administrator workflow. Secret values must never be stored in tracked files."
    )
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
