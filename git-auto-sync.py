#!/usr/bin/env python3
"""Safely deploy origin/main without mutating the production working tree."""

from __future__ import annotations

import fcntl
import os
import re
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

DEPLOY_DIR = Path(os.environ.get("SHOPVIVALIZ_DEPLOY_DIR", "/home/ubuntu/site-shopvivaliz"))
SOURCE_DIR = Path(os.environ.get("SHOPVIVALIZ_SOURCE_DIR", "/home/ubuntu/.cache/shopvivaliz-deploy-source"))
REMOTE_URL = os.environ.get(
    "SHOPVIVALIZ_REMOTE_URL",
    "https://github.com/Vivaliz-site/site-shopvivaliz.git",
)
LOG_FILE = Path(os.environ.get("SHOPVIVALIZ_SYNC_LOG", "/var/log/git-auto-sync.log"))
LOCK_FILE = Path(os.environ.get("SHOPVIVALIZ_SYNC_LOCK", "/tmp/shopvivaliz-git-auto-sync.lock"))
INSTALLATION_TOKEN_RE = re.compile(r"^ghs_[A-Za-z0-9.\-_]{36,}$")

# These paths belong to the running store and must never be replaced by a deploy.
PROTECTED_PATHS = (
    ".env",
    ".env.*",
    ".agent-heartbeats",
    ".secrets",
    "backups",
    "config/runtime-secrets.php",
    "logs",
    "storage",
    "tasks-queue.json",
    "agents/v9.2.84/database",
)


def log(message: str) -> None:
    line = f"[{datetime.now(timezone.utc).isoformat()}] {message}"
    print(line, flush=True)
    try:
        LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
        with LOG_FILE.open("a", encoding="utf-8") as handle:
            handle.write(line + "\n")
    except OSError as exc:
        print(f"Warning: could not write to {LOG_FILE}: {exc}", flush=True)


def run_cmd(command: list[str], cwd: Path | None = None, timeout: int = 180) -> str:
    result = subprocess.run(
        command,
        cwd=str(cwd) if cwd else None,
        capture_output=True,
        text=True,
        timeout=timeout,
        check=False,
    )
    if result.returncode != 0:
        detail = result.stderr.strip() or result.stdout.strip() or f"exit status {result.returncode}"
        raise RuntimeError(f"{' '.join(command[:3])} failed: {detail}")
    return result.stdout.strip()


def log_token_compatibility() -> None:
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


def ensure_clean_source() -> None:
    if not (SOURCE_DIR / ".git").is_dir():
        if SOURCE_DIR.exists() and any(SOURCE_DIR.iterdir()):
            raise RuntimeError(f"source directory is not an empty Git clone: {SOURCE_DIR}")
        SOURCE_DIR.parent.mkdir(parents=True, exist_ok=True)
        run_cmd(["git", "clone", "--branch", "main", "--single-branch", REMOTE_URL, str(SOURCE_DIR)])

    status = run_cmd(["git", "status", "--porcelain"], SOURCE_DIR)
    if status:
        raise RuntimeError(f"clean deployment source has local changes; refusing sync: {status}")


def update_source() -> str:
    # Policy requires status validation before any fetch or merge.
    ensure_clean_source()
    log("Fetching origin/main in clean deployment source...")
    run_cmd(["git", "fetch", "--prune", "origin", "main"], SOURCE_DIR)
    run_cmd(["git", "switch", "main"], SOURCE_DIR)
    run_cmd(["git", "merge", "--ff-only", "FETCH_HEAD"], SOURCE_DIR)
    return run_cmd(["git", "rev-parse", "HEAD"], SOURCE_DIR)


def deploy(revision: str) -> None:
    DEPLOY_DIR.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="shopvivaliz-release-") as temp_dir:
        release_dir = Path(temp_dir)
        archive_path = release_dir / "release.tar"
        content_dir = release_dir / "content"
        content_dir.mkdir()

        run_cmd(["git", "archive", "--format=tar", "-o", str(archive_path), revision], SOURCE_DIR)
        run_cmd(["tar", "-xf", str(archive_path), "-C", str(content_dir)])

        rsync = ["rsync", "-a", "--no-owner", "--no-group"]
        for protected in PROTECTED_PATHS:
            rsync.extend(["--exclude", protected])
        rsync.extend([f"{content_dir}/", f"{DEPLOY_DIR}/"])
        run_cmd(rsync, timeout=300)

    marker = DEPLOY_DIR / ".deploy-sha"
    marker.write_text(revision + "\n", encoding="ascii")
    log(f"COMPROVADO: deployed origin/main revision {revision}")


def main() -> int:
    LOCK_FILE.parent.mkdir(parents=True, exist_ok=True)
    with LOCK_FILE.open("w", encoding="ascii") as lock_handle:
        try:
            fcntl.flock(lock_handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            log("Another synchronization is already running; exiting cleanly")
            return 0

        try:
            log_token_compatibility()
            revision = update_source()
            current = ""
            marker = DEPLOY_DIR / ".deploy-sha"
            if marker.is_file():
                current = marker.read_text(encoding="ascii").strip()
            if current == revision:
                log(f"COMPROVADO: production already at {revision}")
                return 0
            deploy(revision)
            return 0
        except (OSError, RuntimeError, subprocess.TimeoutExpired) as exc:
            log(f"FALHOU: {exc}")
            return 1
        except Exception as exc:
            log(f"FALHOU: unexpected error: {exc}")
            return 1


if __name__ == "__main__":
    sys.exit(main())
