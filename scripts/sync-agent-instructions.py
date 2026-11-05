#!/usr/bin/env python3
"""Keep CLAUDE.md a byte-identical mirror of AGENTS.md (owner request, 2026-11-05).

AGENTS.md is the source of truth. Usage:
  sync-agent-instructions.py          # rewrite CLAUDE.md from AGENTS.md
  sync-agent-instructions.py --check  # exit 1 when they differ
"""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "AGENTS.md"
MIRROR = ROOT / "CLAUDE.md"


def main(argv: list[str]) -> int:
    source = SOURCE.read_bytes()
    if "--check" in argv:
        if MIRROR.read_bytes() == source:
            print("AGENT_INSTRUCTIONS_MIRROR=IN_SYNC")
            return 0
        print("AGENT_INSTRUCTIONS_MIRROR=DIFFERS (run scripts/sync-agent-instructions.py)")
        return 1
    MIRROR.write_bytes(source)
    print("CLAUDE.md rewritten from AGENTS.md")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
