#!/usr/bin/env python3
"""Fail-closed Git push wrapper for detached continuity executors.

The wrapper accepts no arguments and can only publish the currently checked
out non-protected branch to origin with a normal non-force push.
"""

from __future__ import annotations

import os
import re
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlparse

PROTECTED_BRANCHES = {"main", "master"}
REPOSITORY_RE = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")


def _git(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["git", *args],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=check,
    )


def _current_branch() -> str:
    completed = _git("symbolic-ref", "--quiet", "--short", "HEAD", check=False)
    if completed.returncode != 0:
        return ""
    return completed.stdout.strip()


def _origin_repository() -> str:
    completed = _git("remote", "get-url", "origin", check=False)
    if completed.returncode != 0:
        return ""
    value = completed.stdout.strip()
    if value.startswith("git@github.com:"):
        path = value[len("git@github.com:") :]
    else:
        parsed = urlparse(value)
        if parsed.hostname != "github.com":
            return ""
        path = parsed.path.lstrip("/")
    if path.endswith(".git"):
        path = path[:-4]
    return path.strip("/")


def _fail(code: int, reason: str) -> int:
    print(f"safe_git_push_error={reason}", file=sys.stderr)
    return code


def main(argv: list[str] | None = None) -> int:
    args = list(sys.argv[1:] if argv is None else argv)
    if args:
        return _fail(64, "arguments_forbidden")

    inside = _git("rev-parse", "--is-inside-work-tree", check=False)
    if inside.returncode != 0 or inside.stdout.strip() != "true":
        return _fail(65, "not_git_worktree")

    branch = _current_branch()
    if not branch:
        return _fail(66, "detached_head")
    if branch in PROTECTED_BRANCHES:
        return _fail(67, "protected_branch")
    valid = _git("check-ref-format", "--branch", branch, check=False)
    if valid.returncode != 0:
        return _fail(68, "invalid_branch")

    expected_repository = os.getenv("SHOPVIVALIZ_TASK_REPOSITORY", "").strip()
    if expected_repository:
        if not REPOSITORY_RE.fullmatch(expected_repository):
            return _fail(69, "invalid_expected_repository")
        actual_repository = _origin_repository()
        if actual_repository != expected_repository:
            return _fail(70, "origin_repository_mismatch")

    completed = _git(
        "push",
        "--set-upstream",
        "origin",
        f"HEAD:refs/heads/{branch}",
        check=False,
    )
    if completed.stdout:
        sys.stdout.write(completed.stdout)
    if completed.stderr:
        sys.stderr.write(completed.stderr)
    if completed.returncode != 0:
        return _fail(completed.returncode, "push_failed")

    print(f"safe_git_push_branch={branch}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
