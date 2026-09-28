#!/usr/bin/env python3
"""Accept only Claude Code's recognized workspace-trust prompt over a real PTY."""

from __future__ import annotations

import os
import pty
import re
import select
import signal
import subprocess
import sys
import time

ANSI_RE = re.compile(r"\x1b(?:\[[0-?]*[ -/]*[@-~]|\][^\x07]*(?:\x07|\x1b\\))")


def clean_screen(value: str) -> str:
    return ANSI_RE.sub("", value).replace("\r", "\n")


def trust_prompt_visible(value: str) -> bool:
    text = clean_screen(value).lower()
    return "quick safety check" in text and "yes, i trust this folder" in text


def remote_control_confirmation_visible(value: str) -> bool:
    return "enable remote control?" in clean_screen(value).lower()


def unexpected_prompt_visible(value: str) -> bool:
    text = clean_screen(value).lower()
    return (
        "do you want to allow this tool" in text
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
    trust_accepted = False
    remote_control_accepted = False
    unexpected = False
    accepted_at = 0.0
    deadline = time.monotonic() + 15.0

    try:
        proc = subprocess.Popen(
            [claude_bin, "--remote-control"],
            stdin=slave_fd,
            stdout=slave_fd,
            stderr=slave_fd,
            close_fds=True,
            start_new_session=True,
            env=os.environ.copy(),
        )
        os.close(slave_fd)
        slave_fd = -1

        while time.monotonic() < deadline:
            elapsed = time.monotonic() - accepted_at if accepted_at else 0.0
            if remote_control_accepted and elapsed >= 1.5:
                break
            if trust_accepted and elapsed >= 5.0:
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
            if not trust_accepted and trust_prompt_visible(buffer):
                os.write(master_fd, b"1\r")
                trust_accepted = True
                accepted_at = time.monotonic()
                continue
            if trust_accepted and not remote_control_accepted and remote_control_confirmation_visible(buffer):
                os.write(master_fd, b"y\r")
                remote_control_accepted = True
                accepted_at = time.monotonic()
                continue
            if unexpected_prompt_visible(buffer) or (
                not trust_accepted and remote_control_confirmation_visible(buffer)
            ):
                unexpected = True
                break
    finally:
        if slave_fd >= 0:
            os.close(slave_fd)
        if proc is not None:
            stop_process(proc)
        os.close(master_fd)

    if trust_accepted:
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=PASS")
        return 0
    if unexpected:
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=unexpected_prompt")
        return 66
    print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=prompt_missing")
    return 67


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
