#!/usr/bin/env python3
"""Promote the immutable controller release under an exclusive runtime lease.

This entrypoint is used only by the allowlisted GitHub remote-recovery workflow.
It does not disable or override any runtime mutex, production approval or
authenticated ChatGPT conversation gate.
"""
from __future__ import annotations

import os
import re
import subprocess
import sys
import time
from pathlib import Path
from typing import Any


CONTROLLER_REPO = Path("/home/ubuntu/shopvivaliz-deploy/repo")
CONTROLLER_UNIT = "shopvivaliz-gemini-24x7-controller.service"
SHA_PATTERN = re.compile(r"[0-9a-f]{40}\Z")


def promote(
    release_dir: Path,
    expected_sha: str,
    *,
    runtime: Any,
    run: Any = subprocess.run,
    repo: Path = CONTROLLER_REPO,
    lock_wait_seconds: float = 0,
    monotonic: Any = time.monotonic,
    sleep: Any = time.sleep,
) -> dict[str, Any]:
    """Fail closed on another writer or on a stale GitHub main commit."""
    if not SHA_PATTERN.fullmatch(expected_sha):
        raise ValueError("invalid_expected_sha")
    release_dir = Path(release_dir)
    if not release_dir.is_absolute():
        raise ValueError("release_dir_must_be_absolute")
    installer = release_dir / "scripts" / "install-gemini-24x7-controller.sh"
    if not installer.is_file():
        raise ValueError("canonical_controller_installer_missing")

    # Wait briefly for a legitimate writer to finish. Never release, extend,
    # replace or steal another owner's lease; the runtime lock performs the
    # final atomic acquisition and fencing check.
    if not 0 <= lock_wait_seconds <= 180:
        raise ValueError("lock_wait_seconds_out_of_range")
    deadline = monotonic() + lock_wait_seconds
    while True:
        try:
            lease = runtime.acquire_runtime_lock(
                "maintenance",
                "github-actions-controller:" + expected_sha,
                1080,
                ["controller_promote"],
            )
            break
        except runtime.RuntimeLockConflict as exc:
            if str(exc) != "runtime lock already held":
                raise
            remaining = deadline - monotonic()
            if remaining <= 0:
                raise
            sleep(min(5.0, remaining))
    try:
        runtime.assert_runtime_lock(
            lease["lease_id"], lease["fencing_token"], "controller_promote"
        )
        result = run(
            [
                "git",
                "-c",
                f"safe.directory={repo}",
                "-C",
                str(repo),
                "ls-remote",
                "--heads",
                "origin",
                "main",
            ],
            check=True,
            stdout=subprocess.PIPE,
            text=True,
            timeout=20,
            env={**os.environ, "GIT_TERMINAL_PROMPT": "0"},
        )
        match = re.fullmatch(
            r"([0-9a-f]{40})\s+refs/heads/main",
            str(result.stdout or "").strip(),
        )
        if not match or match.group(1) != expected_sha:
            raise ValueError("expected_sha_not_remote_main")

        # The installer uses an immutable source and verifies all related
        # systemd units; the lease remains live through the health check.
        run(
            ["bash", str(installer), str(release_dir), expected_sha],
            check=True,
            timeout=950,
        )
        run(
            ["systemctl", "is-active", "--quiet", CONTROLLER_UNIT],
            check=True,
            timeout=15,
        )
        return {"ok": True, "promoted_sha": expected_sha}
    finally:
        runtime.release_runtime_lock(
            lease["lease_id"],
            lease["fencing_token"],
            "github-actions-controller-promotion-finished",
        )


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: controller-recovery-with-lease.py RELEASE_DIR EXPECTED_SHA", file=sys.stderr)
        return 64
    # Use the installed trusted lock module, not a copy supplied by the job.
    sys.path.insert(0, "/opt/shopvivaliz-remote-control/scripts/continuity")
    import runtime_lock

    try:
        # Bounded wait on existing legitimate browser/recovery writers; the
        # workflow still exits nonzero when contention persists.
        result = promote(Path(sys.argv[1]), sys.argv[2], runtime=runtime_lock, lock_wait_seconds=120)
    except Exception as exc:
        # No lease IDs, fencing tokens, API credentials or OTP are logged.
        print("CONTROLLER_RECOVERY=FAILED:" + type(exc).__name__, file=sys.stderr)
        return 1
    print("CONTROLLER_RECOVERY=PASS")
    print("PROMOTED_SHA=" + result["promoted_sha"])
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
