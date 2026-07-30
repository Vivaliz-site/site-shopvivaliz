#!/usr/bin/env python3
"""Run one agent command while holding the repository-wide edit lock."""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

LOCK_PATH = Path(".git/shopvivaliz-agent-edit.lock")


def lock_file(handle, blocking: bool) -> bool:
    if os.name == "nt":
        import msvcrt
        handle.seek(0)
        mode = msvcrt.LK_LOCK if blocking else msvcrt.LK_NBLCK
        try:
            msvcrt.locking(handle.fileno(), mode, 1)
            return True
        except OSError:
            return False
    import fcntl
    flags = fcntl.LOCK_EX | (0 if blocking else fcntl.LOCK_NB)
    try:
        fcntl.flock(handle.fileno(), flags)
        return True
    except BlockingIOError:
        return False


def main() -> int:
    parser = argparse.ArgumentParser(description="Execute a command with the ShopVivaliz agent edit lock")
    parser.add_argument("--owner", required=True, help="Agent/provider identifier")
    parser.add_argument("--timeout", type=int, default=1800)
    parser.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    command = args.command[1:] if args.command[:1] == ["--"] else args.command
    if not command:
        parser.error("a command is required after --")

    LOCK_PATH.parent.mkdir(parents=True, exist_ok=True)
    deadline = time.monotonic() + max(0, args.timeout)
    with LOCK_PATH.open("a+", encoding="utf-8") as handle:
        while not lock_file(handle, blocking=False):
            if time.monotonic() >= deadline:
                print(f"Agent edit lock timeout: owner={args.owner}", file=sys.stderr)
                return 75
            time.sleep(2)

        handle.seek(0)
        handle.truncate()
        handle.write(json.dumps({
            "owner": args.owner,
            "pid": os.getpid(),
            "acquired_at": datetime.now(timezone.utc).isoformat(),
            "command": command[0],
        }))
        handle.flush()
        print(f"Agent edit lock acquired: owner={args.owner}")
        try:
            return subprocess.run(command).returncode
        finally:
            print(f"Agent edit lock released: owner={args.owner}")


if __name__ == "__main__":
    raise SystemExit(main())
