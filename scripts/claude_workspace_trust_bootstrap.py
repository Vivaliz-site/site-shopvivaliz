#!/usr/bin/env python3
"""Accept only Claude Code's recognized workspace-trust prompt over a real PTY."""

from __future__ import annotations

import fcntl
import os
import pty
import re
import select
import signal
import struct
import subprocess
import sys
import termios
import time

ANSI_RE = re.compile(r"\x1b(?:\[[0-?]*[ -/]*[@-~]|\][^\x07]*(?:\x07|\x1b\\))")


def clean_screen(value: str) -> str:
    return ANSI_RE.sub("", value).replace("\r", "\n")


def trust_prompt_visible(value: str) -> bool:
    text = clean_screen(value).lower()
    heading = "quick safety check" in text or "do you trust the files in this folder" in text
    return heading and "yes, i trust this folder" in text and "no, exit" in text


def trust_acceptance_sequence(value: str) -> bytes | None:
    if not trust_prompt_visible(value):
        return None

    lines = [line.strip() for line in clean_screen(value).splitlines() if line.strip()]
    selected_index: int | None = None
    yes_index: int | None = None
    no_index: int | None = None
    selected_yes = False
    for index, line in enumerate(lines):
        lowered = line.lower()
        if "yes, i trust this folder" in lowered:
            yes_index = index
        if "no, exit" in lowered:
            no_index = index
        if line.startswith("❯") or line.startswith(">"):
            selected_index = index
            selected_yes = "yes, i trust this folder" in lowered

    if selected_index is None or yes_index is None or no_index is None:
        return None
    if selected_yes:
        return b"\r"
    if selected_index == no_index:
        distance = yes_index - selected_index
        if distance > 0:
            return b"\x1b[B" * distance + b"\r"
        if distance < 0:
            return b"\x1b[A" * (-distance) + b"\r"
    return None


def unexpected_prompt_visible(value: str) -> bool:
    text = clean_screen(value).lower()
    return (
        "enable remote control?" in text
        or "do you want to allow this tool" in text
        or "permission to use" in text
    )


def stop_process(proc: subprocess.Popen[bytes]) -> None:
    if proc.poll() is not None:
        return
    try:
        os.killpg(proc.pid, signal.SIGINT)
        proc.wait(timeout=2)
        return
    except (ProcessLookupError, subprocess.TimeoutExpired):
        pass
    try:
        os.killpg(proc.pid, signal.SIGTERM)
        proc.wait(timeout=2)
        return
    except (ProcessLookupError, subprocess.TimeoutExpired):
        pass
    try:
        os.killpg(proc.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    try:
        proc.wait(timeout=2)
    except subprocess.TimeoutExpired:
        pass


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=args")
        return 64

    claude_bin = argv[1]
    if not os.path.isfile(claude_bin) or not os.access(claude_bin, os.X_OK):
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=binary")
        return 65

    master_fd, slave_fd = pty.openpty()
    proc: subprocess.Popen[bytes] | None = None
    buffer = ""
    accepted = False
    unexpected = False
    accepted_at = 0.0
    deadline = time.monotonic() + 15.0

    try:
        fcntl.ioctl(slave_fd, termios.TIOCSWINSZ, struct.pack("HHHH", 24, 120, 0, 0))
        env = os.environ.copy()
        env["TERM"] = "xterm-256color"
        proc = subprocess.Popen(
            [claude_bin],
            stdin=slave_fd,
            stdout=slave_fd,
            stderr=slave_fd,
            close_fds=True,
            start_new_session=True,
            env=env,
        )
        os.close(slave_fd)
        slave_fd = -1

        while time.monotonic() < deadline:
            if accepted and time.monotonic() - accepted_at >= 1.5:
                break
            ready, _, _ = select.select([master_fd], [], [], 0.25)
            if not ready:
                if proc.poll() is not None:
                    break
                continue
            try:
                chunk = os.read(master_fd, 4096)
            except OSError:
                break
            if not chunk:
                break
            buffer = (buffer + chunk.decode("utf-8", errors="replace"))[-65536:]
            if not accepted:
                sequence = trust_acceptance_sequence(buffer)
                if sequence is not None:
                    os.write(master_fd, sequence)
                    accepted = True
                    accepted_at = time.monotonic()
                    continue
            if not accepted and unexpected_prompt_visible(buffer):
                unexpected = True
                break
    finally:
        if slave_fd >= 0:
            os.close(slave_fd)
        if proc is not None:
            stop_process(proc)
        os.close(master_fd)

    if accepted:
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=PASS")
        return 0
    if unexpected:
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=unexpected_prompt")
        return 66
    print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=prompt_missing")
    return 67


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
