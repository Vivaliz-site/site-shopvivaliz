#!/usr/bin/env python3
"""Bootstrap Claude Code server-mode trust/consent over a bounded real PTY."""

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
REMOTE_CONTROL_PROMPT = "Enable Remote Control?"
SERVER_SESSION_URL_RE = re.compile(r"https://claude\.ai/code/[^\s\x1b]+", re.IGNORECASE)


TRUST_PERSIST_SECONDS = 5.0
def workspace_trust_command(claude_bin: str) -> list[str]:
    """Use Claude's normal interactive workspace-trust flow first."""
    return [claude_bin]


def remote_control_server_command(claude_bin: str) -> list[str]:
    return [
        claude_bin,
        "remote-control",
        "--name",
        "ShopVivaliz-Trust-Bootstrap",
        "--spawn",
        "worktree",
        "--capacity",
        "1",
        "--no-create-session-in-dir",
        "--permission-mode",
        "default",

    ]

def clean_screen(value: str) -> str:
    return ANSI_RE.sub("", value).replace("\r", "\n")


def legacy_trust_prompt_visible(value: str) -> bool:
    text = clean_screen(value).lower()
    heading = "quick safety check" in text or "do you trust the files in this folder" in text
    return heading and "yes, i trust this folder" in text and "no, exit" in text


def trust_prompt_visible(value: str) -> bool:
    """Backward-compatible legacy TUI detector used by focused tests."""
    return legacy_trust_prompt_visible(value)


def legacy_trust_acceptance_sequence(value: str) -> bytes | None:
    if not legacy_trust_prompt_visible(value):
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


def trust_acceptance_sequence(value: str) -> bytes | None:
    """Backward-compatible legacy acceptance helper used by focused tests."""
    return legacy_trust_acceptance_sequence(value)


def documented_server_trust_sequence(value: str, workspace: str) -> bytes | None:
    # Current official server-mode prompt: Trust <directory>? [y/N]
    canonical = os.path.realpath(workspace)
    for raw in clean_screen(value).splitlines():
        line = raw.strip()
        match = re.fullmatch(r"Trust\s+(.+?)\?\s*\[y/N\]\s*", line, re.IGNORECASE)
        if not match:
            continue
        target = match.group(1).strip().strip('"').strip("'")
        if os.path.isabs(target) and os.path.realpath(target) == canonical:
            return b"y\r"
    return None


def remote_control_acceptance_sequence(value: str) -> bytes | None:
    for line in clean_screen(value).splitlines():
        if re.fullmatch(r"\s*enable remote control\?\s*\(y/n\)\s*", line, re.IGNORECASE):
            return b"y\r"
    return None


def server_startup_visible(value: str) -> bool:
    return SERVER_SESSION_URL_RE.search(clean_screen(value)) is not None


def unexpected_prompt_visible(value: str, workspace: str) -> bool:
    text = clean_screen(value)
    lowered = text.lower()

    if "do you want to allow this tool" in lowered or "permission to use" in lowered:
        return True

    if REMOTE_CONTROL_PROMPT.lower() in lowered and remote_control_acceptance_sequence(value) is None:
        return True

    if re.search(r"(?im)^\s*Trust\s+.+?\?\s*\[y/N\]\s*$", text):
        if documented_server_trust_sequence(value, workspace) is None:
            return True

    return False


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

def bootstrap_workspace_trust(claude_bin: str, workspace: str) -> str:
    """Accept only the normal-session workspace-trust prompt through a PTY."""
    master_fd, slave_fd = pty.openpty()
    proc: subprocess.Popen[bytes] | None = None
    buffer = ""
    accepted = False
    deadline = time.monotonic() + 15.0
    accepted_at = 0.0
    try:
        fcntl.ioctl(slave_fd, termios.TIOCSWINSZ, struct.pack("HHHH", 24, 120, 0, 0))
        env = os.environ.copy()
        env["TERM"] = "xterm-256color"
        proc = subprocess.Popen(
            workspace_trust_command(claude_bin),
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
            ready, _, _ = select.select([master_fd], [], [], 0.25)
            if not ready:
                if accepted and time.monotonic() - accepted_at >= TRUST_PERSIST_SECONDS:
                    return "accepted"
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
            sequence = legacy_trust_acceptance_sequence(buffer)
            if sequence is not None:
                os.write(master_fd, sequence)
                accepted = True
                accepted_at = time.monotonic()
                continue
            if unexpected_prompt_visible(buffer, workspace):
                return "unexpected"
    finally:
        if slave_fd >= 0:
            os.close(slave_fd)
        if proc is not None:
            stop_process(proc)
        os.close(master_fd)
    return "accepted" if accepted else "not_shown"


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=args")
        return 64

    claude_bin = argv[1]
    if not os.path.isfile(claude_bin) or not os.access(claude_bin, os.X_OK):
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=binary")
        return 65

    workspace = os.path.realpath(os.getcwd())
    trust_result = bootstrap_workspace_trust(claude_bin, workspace)
    if trust_result == "unexpected":
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=unexpected_prompt")
        return 66

    master_fd, slave_fd = pty.openpty()
    proc: subprocess.Popen[bytes] | None = None
    buffer = ""
    trust_accepted = False
    remote_control_accepted = False
    started = False
    unexpected = False
    deadline = time.monotonic() + 30.0

    command = remote_control_server_command(claude_bin)

    try:
        fcntl.ioctl(slave_fd, termios.TIOCSWINSZ, struct.pack("HHHH", 24, 120, 0, 0))
        env = os.environ.copy()
        env["TERM"] = "xterm-256color"
        proc = subprocess.Popen(
            command,
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

            if not trust_accepted:
                sequence = documented_server_trust_sequence(buffer, workspace)
                if sequence is None:
                    sequence = legacy_trust_acceptance_sequence(buffer)
                if sequence is not None:
                    os.write(master_fd, sequence)
                    trust_accepted = True
                    continue

            if not remote_control_accepted:
                sequence = remote_control_acceptance_sequence(buffer)
                if sequence is not None:
                    os.write(master_fd, sequence)
                    remote_control_accepted = True
                    continue

            if server_startup_visible(buffer):
                started = True
                break

            if unexpected_prompt_visible(buffer, workspace):
                unexpected = True
                break
    finally:
        if slave_fd >= 0:
            os.close(slave_fd)
        if proc is not None:
            stop_process(proc)
        os.close(master_fd)

    if started:
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=PASS")
        return 0
    if unexpected:
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=unexpected_prompt")
        return 66
    if trust_accepted or remote_control_accepted:
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=startup_missing")
        return 68
    print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=prompt_missing")
    return 67


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
