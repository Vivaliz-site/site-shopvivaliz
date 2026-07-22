#!/usr/bin/env python3
"""Keep the Oracle VM production checkout exactly aligned with origin/main."""

from __future__ import annotations

import os
import re
import subprocess
import sys
from datetime import datetime

REPO_DIR = "/home/ubuntu/site-shopvivaliz"
LOG_FILE = "/var/log/git-auto-sync.log"
INSTALLATION_TOKEN_RE = re.compile(r"^ghs_[A-Za-z0-9.\-_]{36,}$")


def log(message: str) -> None:
    line = f"[{datetime.now().isoformat()}] {message}"
    print(line)
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as handle:
            handle.write(line + "\n")
    except OSError as exc:
        print(f"Warning: Could not write to {LOG_FILE}: {exc}")


def run_cmd(command: list[str]) -> tuple[int, str, str]:
    try:
        result = subprocess.run(
            command, cwd=REPO_DIR, capture_output=True, text=True,
            timeout=60, check=False,
        )
        return result.returncode, result.stdout, result.stderr
    except subprocess.TimeoutExpired:
        return 124, "", "Command timed out"
    except OSError as exc:
        return 1, "", str(exc)


def require_success(command: list[str], label: str) -> tuple[str, str]:
    code, stdout, stderr = run_cmd(command)
    if code != 0:
        detail = stderr.strip() or stdout.strip() or f"exit status {code}"
        raise RuntimeError(f"{label} failed: {detail}")
    return stdout, stderr


def log_token_compatibility() -> None:
    """Accept classic and JWT-format GitHub App tokens without exposing them."""
    token = os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
    if not token:
        return
    if token.startswith("ghs_"):
        if not INSTALLATION_TOKEN_RE.fullmatch(token):
            raise RuntimeError("invalid GitHub App installation token format")
        kind = "stateless JWT" if token.count(".") == 2 else "classic opaque"
        log(f"GitHub App installation token accepted ({kind})")
    else:
        log("Git credential uses a non-installation-token format")


def main() -> int:
    if not os.path.isdir(os.path.join(REPO_DIR, ".git")):
        log(f"ERROR: Git repository not found at {REPO_DIR}")
        return 1

    try:
        log_token_compatibility()
        log("Fetching origin/main...")
        require_success(
            ["git", "fetch", "--prune", "origin",
             "+refs/heads/main:refs/remotes/origin/main"],
            "git fetch",
        )
        require_success(
            ["git", "rev-parse", "--verify", "origin/main^{commit}"],
            "verify origin/main",
        )

        status, _ = require_success(["git", "status", "--porcelain"], "git status")
        if status.strip():
            stamp = datetime.now().strftime("%Y%m%dT%H%M%S")
            log("Local changes detected; preserving them in stash...")
            require_success(
                ["git", "stash", "push", "--include-untracked",
                 "-m", f"auto-sync-{stamp}"],
                "git stash",
            )

        # Deployment checkout follows the remote even after an intentional rollback.
        require_success(["git", "checkout", "-B", "main", "origin/main"], "git checkout")
        require_success(["git", "reset", "--hard", "origin/main"], "git reset")
        revision, _ = require_success(
            ["git", "rev-parse", "--short", "HEAD"], "git rev-parse"
        )
        log(f"SUCCESS: Production checkout synchronized to {revision.strip()}")
        return 0
    except RuntimeError as exc:
        log(f"ERROR: {exc}")
        return 1
    except Exception as exc:
        log(f"ERROR: Unexpected error: {exc}")
        return 1


if __name__ == "__main__":
    sys.exit(main())
