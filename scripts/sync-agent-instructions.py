#!/usr/bin/env python3
"""Keep AGENTS.md, CLAUDE.md, GEMINI.md and docs/AGENTS.md identical (owner request, 2026-11-05).

An inclusion or change made in any of the four is reflected in the other three.
The common ancestor is the committed (HEAD) content; changes are combined with a
3-way merge, so edits to different parts of different files are all kept.

Usage:
  sync-agent-instructions.py                # propagate edits to all four files
  sync-agent-instructions.py --check        # exit 1 when they differ
  sync-agent-instructions.py --from FILE    # copy FILE to the other three
Exit codes: 0 ok, 1 differs or conflict, 2 usage or git problem.
"""
from __future__ import annotations

import os
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(os.environ.get("SHOPVIVALIZ_AGENT_INSTRUCTIONS_ROOT") or Path(__file__).resolve().parents[1])
FILES = ("AGENTS.md", "CLAUDE.md", "GEMINI.md", "docs/AGENTS.md")


def committed_base() -> bytes | None:
    versions = []
    for name in FILES:
        shown = subprocess.run(["git", "-C", str(ROOT), "show", f"HEAD:{name}"], capture_output=True)
        if shown.returncode != 0:
            return None
        versions.append(shown.stdout)
    return versions[0] if len(set(versions)) == 1 else None


def merge3(base: bytes, ours: bytes, theirs: bytes) -> bytes | None:
    with tempfile.TemporaryDirectory() as tmp:
        paths = []
        for label, data in (("ours", ours), ("base", base), ("theirs", theirs)):
            path = Path(tmp) / label
            path.write_bytes(data)
            paths.append(str(path))
        result = subprocess.run(["git", "merge-file", "-p", *paths], capture_output=True)
    return result.stdout if result.returncode == 0 else None


def write_all(content: bytes) -> None:
    for name in FILES:
        (ROOT / name).write_bytes(content)


def main(argv: list[str]) -> int:
    contents = {name: (ROOT / name).read_bytes() for name in FILES}
    identical = len(set(contents.values())) == 1
    if "--check" in argv:
        print("AGENT_INSTRUCTIONS_MIRROR=" + ("IN_SYNC" if identical else
              "DIFFERS (run scripts/sync-agent-instructions.py)"))
        return 0 if identical else 1
    if "--from" in argv:
        at = argv.index("--from") + 1
        source = argv[at] if at < len(argv) else ""
        if source not in FILES:
            print("usage: --from " + "|".join(FILES), file=sys.stderr)
            return 2
        write_all(contents[source])
        print(f"{source} copied to the other three")
        return 0
    if identical:
        print("AGENT_INSTRUCTIONS_MIRROR=IN_SYNC")
        return 0
    base = committed_base()
    if base is None:
        print("AGENT_INSTRUCTIONS_MIRROR=NO_COMMON_BASE (use --from FILE to pick the source)", file=sys.stderr)
        return 1
    merged = base
    for name in FILES:
        if contents[name] == base:
            continue
        merged = contents[name] if merged == base else merge3(base, merged, contents[name])
        if merged is None:
            print(f"AGENT_INSTRUCTIONS_MIRROR=CONFLICT in {name} (the same lines were edited in two files; use --from FILE)",
                  file=sys.stderr)
            return 1
    write_all(merged)
    print("AGENT_INSTRUCTIONS_MIRROR=SYNCED")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
