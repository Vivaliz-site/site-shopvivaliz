#!/usr/bin/env python3
"""Close aged interactive Claude Code/Codex CLI sessions, not service/GUI processes.

Dry-run by default. Run --apply as the *same non-root OS user* as the sessions.
No command line, environment variable or credential is ever logged.
"""
from __future__ import annotations

import argparse
import json
import os
import signal
import socket
import stat
import time
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Process:
    pid: int
    ppid: int
    uid: int
    age: float
    argv: tuple[str, ...]
    exe: str
    cgroup: str
    start_ticks: int


def kind(p: Process) -> str | None:
    if p.uid != os.geteuid() or p.uid == 0:
        return None
    if ".service" in p.cgroup or "--remote-control" in p.argv:
        return None
    exe = p.exe.lower().replace("\\", "/")
    args = tuple(x.lower().replace("\\", "/") for x in p.argv)
    first = (args[0].rsplit("/", 1)[-1] if args else "")
    if any(x in exe for x in ("/windowsapps/", "/chatgpt/")):
        return None
    if first in ("claude", "claude.exe", "claude.js") or any(
        "@anthropic-ai/claude-code" in x for x in args[:3]
    ):
        return "claude"
    if first in ("codex", "codex.exe", "codex-linux", "codex-linux-arm64") or any(
        "@openai/codex" in x for x in args[:3]
    ):
        return "codex"
    return None


def read_process(pid: int, uptime: float) -> Process | None:
    base = Path("/proc") / str(pid)
    try:
        s = (base / "stat").read_text()
        tail = s.rsplit(") ", 1)[1].split()
        ppid = int(tail[1])
        ticks = int(tail[19])  # field 22 after pid + "(comm)"
        uid = (base / "status").stat().st_uid
        argv = tuple(
            x.decode("utf-8", errors="replace")
            for x in (base / "cmdline").read_bytes().split(b"\0") if x
        )
        exe = os.readlink(base / "exe")
        cgroup = (base / "cgroup").read_text(errors="replace")
        age = max(0.0, uptime - ticks / os.sysconf("SC_CLK_TCK"))
        return Process(pid, ppid, uid, age, argv, exe, cgroup, ticks)
    except (FileNotFoundError, PermissionError, ValueError, IndexError, OSError):
        return None


def live_processes() -> list[Process]:
    uptime = float(Path("/proc/uptime").read_text().split()[0])
    result = []
    for entry in Path("/proc").iterdir():
        if entry.name.isdecimal():
            item = read_process(int(entry.name), uptime)
            if item is not None:
                result.append(item)
    return result


def targets(items: list[Process], limit_sec: int) -> list[tuple[Process, str]]:
    candidates = {p.pid: (p, kind(p)) for p in items}
    children: dict[int, list[int]] = {}
    for p in items:
        children.setdefault(p.ppid, []).append(p.pid)

    def has_younger_agent_descendant(pid: int) -> bool:
        pending = list(children.get(pid, []))
        visited = set()
        while pending:
            child = pending.pop()
            if child in visited:
                continue
            visited.add(child)
            found = candidates.get(child)
            if found and found[1] and found[0].age < limit_sec:
                return True
            pending.extend(children.get(child, []))
        return False

    return sorted(
        [(p, k) for p, k in candidates.values()
         if k and p.age > limit_sec and not has_younger_agent_descendant(p.pid)],
        key=lambda pair: pair[0].pid,
    )


def audit(record: dict) -> None:
    root = Path.home() / ".local/state/shopvivaliz"
    root.mkdir(parents=True, exist_ok=True, mode=0o700)
    log = root / "agent-session-ttl.jsonl"
    fd = os.open(log, os.O_WRONLY | os.O_CREAT | os.O_APPEND | os.O_NOFOLLOW, 0o600)
    try:
        os.write(fd, (json.dumps(record, sort_keys=True) + "\n").encode())
    finally:
        os.close(fd)


def close(p: Process, k: str, limit_sec: int) -> str:
    uptime = float(Path("/proc/uptime").read_text().split()[0])
    current = read_process(p.pid, uptime)
    if (current is None or current.start_ticks != p.start_ticks
            or kind(current) != k or current.age <= limit_sec):
        return "SKIPPED_CHANGED"
    try:
        os.kill(p.pid, signal.SIGTERM)
    except ProcessLookupError:
        return "ALREADY_EXITED"
    except PermissionError:
        return "DENIED"
    # Give the CLI a chance to flush checkpoint/state and release its locks.
    for _ in range(12):
        time.sleep(0.5)
        check = read_process(p.pid, float(Path("/proc/uptime").read_text().split()[0]))
        if check is None or check.start_ticks != p.start_ticks:
            return "CLOSED_GRACEFULLY"
    # A subsequent run retries the session; do not force-kill a running task.
    return "TERM_SENT_STILL_RUNNING"


def main() -> int:
    # Decisão do proprietário (2026-11-08): desativação do encerramento forçado de sessões de CLI.
    print("SESSION_TTL_DISABLED_BY_OWNER")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
