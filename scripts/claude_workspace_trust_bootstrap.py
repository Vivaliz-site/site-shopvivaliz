#!/usr/bin/env python3
"""Bootstrap Claude Code workspace trust and Remote Control through bounded PTYs."""

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
TRUST_SETTLE_SECONDS = 5.0


class PtyResult:
    def __init__(
        self,
        status: str,
        accepted_trust: bool = False,
        accepted_remote_control: bool = False,
    ) -> None:
        self.status = status
        self.accepted_trust = accepted_trust
        self.accepted_remote_control = accepted_remote_control


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
    # Official server-mode prompt: Trust <directory>? [y/N]
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


def workspace_not_trusted_visible(value: str) -> bool:
    text = clean_screen(value).lower()
    return "workspace not trusted" in text and "trust" in text


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
    for sig, timeout in ((signal.SIGINT, 2), (signal.SIGTERM, 2), (signal.SIGKILL, 2)):
        try:
            os.killpg(proc.pid, sig)
            proc.wait(timeout=timeout)
            return
        except ProcessLookupError:
            return
        except subprocess.TimeoutExpired:
            continue


def open_pty_process(command: list[str]) -> tuple[subprocess.Popen[bytes], int, int]:
    master_fd, slave_fd = pty.openpty()
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
    return proc, master_fd, slave_fd


def read_chunk(master_fd: int) -> bytes | None:
    ready, _, _ = select.select([master_fd], [], [], 0.25)
    if not ready:
        return None
    try:
        return os.read(master_fd, 4096)
    except OSError:
        return b""


def bootstrap_plain_workspace_trust(claude_bin: str, workspace: str) -> PtyResult:
    command = [claude_bin]
    proc, master_fd, slave_fd = open_pty_process(command)
    buffer = ""
    accepted_at: float | None = None
    deadline = time.monotonic() + 25.0

    try:
        os.close(slave_fd)
        slave_fd = -1
        while time.monotonic() < deadline:
            if accepted_at is not None and time.monotonic() - accepted_at >= TRUST_SETTLE_SECONDS:
                return PtyResult("plain_trust_persisted", accepted_trust=True)

            chunk = read_chunk(master_fd)
            if chunk is None:
                if proc.poll() is not None:
                    break
                continue
            if not chunk:
                break

            buffer = (buffer + chunk.decode("utf-8", errors="replace"))[-65536:]
            sequence = documented_server_trust_sequence(buffer, workspace)
            if sequence is None:
                sequence = legacy_trust_acceptance_sequence(buffer)
            if sequence is not None and accepted_at is None:
                os.write(master_fd, sequence)
                accepted_at = time.monotonic()
                continue

            if unexpected_prompt_visible(buffer, workspace):
                return PtyResult("unexpected_prompt")
    finally:
        if slave_fd >= 0:
            os.close(slave_fd)
        stop_process(proc)
        os.close(master_fd)

    return PtyResult("plain_prompt_missing")


def run_server_mode(claude_bin: str, workspace: str) -> PtyResult:
    command = [
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
    proc, master_fd, slave_fd = open_pty_process(command)
    buffer = ""
    accepted_trust = False
    accepted_remote = False
    deadline = time.monotonic() + 30.0

    try:
        os.close(slave_fd)
        slave_fd = -1
        while time.monotonic() < deadline:
            chunk = read_chunk(master_fd)
            if chunk is None:
                if proc.poll() is not None:
                    break
                continue
            if not chunk:
                break

            buffer = (buffer + chunk.decode("utf-8", errors="replace"))[-65536:]

            if workspace_not_trusted_visible(buffer):
                return PtyResult("workspace_not_trusted")

            if not accepted_trust:
                sequence = documented_server_trust_sequence(buffer, workspace)
                if sequence is None:
                    sequence = legacy_trust_acceptance_sequence(buffer)
                if sequence is not None:
                    os.write(master_fd, sequence)
                    accepted_trust = True
                    continue

            if not accepted_remote:
                sequence = remote_control_acceptance_sequence(buffer)
                if sequence is not None:
                    os.write(master_fd, sequence)
                    accepted_remote = True
                    continue

            if server_startup_visible(buffer):
                return PtyResult(
                    "started",
                    accepted_trust=accepted_trust,
                    accepted_remote_control=accepted_remote,
                )

            if unexpected_prompt_visible(buffer, workspace):
                return PtyResult("unexpected_prompt")
    finally:
        if slave_fd >= 0:
            os.close(slave_fd)
        stop_process(proc)
        os.close(master_fd)

    if accepted_trust or accepted_remote:
        return PtyResult(
            "startup_missing",
            accepted_trust=accepted_trust,
            accepted_remote_control=accepted_remote,
        )
    return PtyResult("prompt_missing")


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=args")
        return 64

    claude_bin = argv[1]
    if not os.path.isfile(claude_bin) or not os.access(claude_bin, os.X_OK):
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class=binary")
        return 65

    workspace = os.path.realpath(os.getcwd())

    # The installer invokes this helper only after its bounded non-PTY
    # server attempt has classified workspace trust as the blocker. Follow
    # Claude's supported first-run order: plain CLI trust first, then
    # remote-control server mode. If trust was already persisted, plain
    # Claude may not prompt; only a real server startup can make that case
    # PASS.
    plain = bootstrap_plain_workspace_trust(claude_bin, workspace)
    if plain.status not in {"plain_trust_persisted", "plain_prompt_missing"}:
        print(f"CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class={plain.status}")
        return 69

    server = run_server_mode(claude_bin, workspace)
    if server.status == "started":
        print("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=PASS")
        return 0

    if server.status == "workspace_not_trusted":
        safe_class = (
            "trust_not_persisted"
            if plain.status == "plain_trust_persisted"
            else "plain_prompt_missing"
        )
        print(f"CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class={safe_class}")
        return 67

    safe_class = {
        "unexpected_prompt": "unexpected_prompt",
        "startup_missing": "startup_missing",
        "prompt_missing": "prompt_missing",
    }.get(server.status, "other")
    print(f"CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=FAIL class={safe_class}")
    return 66 if safe_class == "unexpected_prompt" else 67


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
