#!/usr/bin/env python3
"""One scoped owner-authorized controller and continuity-bridge rollout.

Execute only as ubuntu on canonical backend, from clean immutable worktree at
fresh origin/main. Human-approved/merged grant never bypasses lease or fencing.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import subprocess
import sys

REPO = "Vivaliz-site/site-shopvivaliz"
HOST = "always-free-arm-1787907847-26"
RESOURCE = "mcp:controller"
PRINCIPAL = "shopvivaliz-central-mcp"
OPERATION = "continuity_release_install"
TECHNICAL_MERGE = "ae7857ee1187686a0b75bb0bd2e370a297910f60"
CONTROLLER_REPO = Path("/home/ubuntu/shopvivaliz-deploy/repo")
INSTALLED_LOCK = Path("/opt/shopvivaliz-remote-control/scripts/continuity")
INSTALLED_WORKER = Path("/home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs")
SHA40 = re.compile(r"^[0-9a-f]{40}$")
GRANT_FIELDS = {
    "schema", "repository", "pr_number", "resource", "principal", "host",
    "capabilities", "operation", "target_repository", "target_merge_sha",
    "requires_remote_main_sha_match", "requires_free_runtime_lock",
    "requires_exclusive_maintenance_lease", "requires_fencing",
    "forbid_foreign_lease_release", "expires_at_utc",
}

class RolloutDenied(RuntimeError):
    pass

def call(args: list[str], timeout: int) -> str:
    """Do not echo sensitive stdout or stderr on a command failure."""
    result = subprocess.run(args, capture_output=True, text=True,
                            timeout=timeout, check=False)
    if result.returncode != 0:
        raise RolloutDenied("subprocess_failed:" + Path(args[0]).name)
    return (result.stdout or "").strip()

def assert_exact_main(release: Path, expected_sha: str) -> None:
    if call(["git", "-C", str(release), "rev-parse", "HEAD"], 20) != expected_sha:
        raise RolloutDenied("source_release_sha_mismatch")
    if call(["git", "-C", str(release), "status", "--porcelain", "--untracked-files=all"], 30):
        raise RolloutDenied("source_release_worktree_dirty")
    result = call(["git", "-c", f"safe.directory={CONTROLLER_REPO}",
                   "-C", str(CONTROLLER_REPO), "ls-remote", "--heads",
                   "origin", "main"], 25)
    if result != expected_sha + "\trefs/heads/main":
        raise RolloutDenied("remote_main_mismatch")
    call(["git", "-C", str(release), "merge-base", "--is-ancestor",
          TECHNICAL_MERGE, expected_sha], 20)

def assert_scoped_grant(release: Path, grant_pr: int) -> None:
    path = release / "security" / "owner-approvals" / f"{grant_pr}.json"
    try:
        g = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        raise RolloutDenied("exact_grant_missing") from exc
    required = dict(schema="SHOPVIVALIZ_OWNER_PR_GRANT_V1", repository=REPO,
                    pr_number=grant_pr, resource=RESOURCE, principal=PRINCIPAL,
                    host=HOST, operation=OPERATION, target_repository=REPO,
                    target_merge_sha=TECHNICAL_MERGE,
                    capabilities=["controller_promote", "continuity_bridge_install"],
                    requires_remote_main_sha_match=True,
                    requires_free_runtime_lock=True,
                    requires_exclusive_maintenance_lease=True,
                    requires_fencing=True,
                    forbid_foreign_lease_release=True)
    if not isinstance(g, dict) or set(g) != GRANT_FIELDS:
        raise RolloutDenied("grant_fields_not_exact")
    for key, expected in required.items():
        if type(g.get(key)) is not type(expected) or g.get(key) != expected:
            raise RolloutDenied("grant_scope_mismatch")
    if not isinstance(g.get("expires_at_utc"), str):
        raise RolloutDenied("grant_expiry_missing")

def assert_owner_grant(release: Path, grant_pr: int, grant_head: str,
                       grant_merge: str) -> None:
    assert_scoped_grant(release, grant_pr)
    verifier = release / "scripts" / "validate-owner-pr-grant.py"
    if not verifier.is_file():
        raise RolloutDenied("owner_verifier_missing")
    if (os.environ.get("SHOPVIVALIZ_SECURITY_OWNER_LOGIN") != "shopvivaliz-human"
            or not os.environ.get("GITHUB_TOKEN")):
        raise RolloutDenied("protected_owner_verifier_environment_unavailable")
    for capability in ("controller_promote", "continuity_bridge_install"):
        result = call([
            sys.executable, str(verifier), "--repository", REPO,
            "--pr-number", str(grant_pr), "--merge-sha", grant_merge,
            "--head-sha", grant_head, "--resource", RESOURCE,
            "--principal", PRINCIPAL, "--capability", capability,
            "--operation", OPERATION, "--target-repository", REPO,
        ], 35)
        if result != "OWNER_PR_GRANT=AUTHORIZED reason=owner_authorization_verified":
            raise RolloutDenied("owner_grant_not_authorized")

def execute(release: Path, main_sha: str, grant_pr: int, grant_head: str,
            grant_merge: str) -> None:
    if (os.geteuid() == 0 or pwd.getpwuid(os.geteuid()).pw_name != "ubuntu"
            or socket.gethostname().split(".")[0] != HOST):
        raise RolloutDenied("canonical_backend_ubuntu_required")
    if not (all(SHA40.fullmatch(x) for x in (main_sha, grant_head, grant_merge))
            and grant_pr > 0):
        raise RolloutDenied("invalid_sha_or_grant_identity")
    if (not release.is_absolute() or release.is_symlink()
            or "current" in release.parts or not release.is_dir()):
        raise RolloutDenied("immutable_absolute_worktree_required")
    assert_exact_main(release, main_sha)
    assert_owner_grant(release, grant_pr, grant_head, grant_merge)
    controller = release / "scripts" / "controller-recovery-with-lease.py"
    bridge = release / "scripts" / "install-chatgpt-continuity-backend-bridge.sh"
    worker = release / "scripts" / "chatgpt-continuity" / "chatgpt-continuity-bridge-worker.mjs"
    if not (controller.is_file() and bridge.is_file() and worker.is_file()):
        raise RolloutDenied("canonical_artifacts_missing")
    if not INSTALLED_LOCK.is_dir():
        raise RolloutDenied("trusted_runtime_lock_missing")
    # Controller recovery acquires and fences its OWN independent maintenance
    # lease. A live foreign recovery lock fails closed; no override occurs.
    call([sys.executable, str(controller), str(release), main_sha], 1250)
    assert_exact_main(release, main_sha)
    assert_owner_grant(release, grant_pr, grant_head, grant_merge)
    sys.path.insert(0, str(INSTALLED_LOCK))
    import runtime_lock  # type: ignore[import-not-found]
    lease = runtime_lock.acquire_runtime_lock(
        "maintenance", f"owner-pr-{grant_pr}-bridge:{main_sha}", 1500,
        ["continuity_bridge_install"])
    try:
        held = runtime_lock.assert_runtime_lock(
            lease["lease_id"], lease["fencing_token"], "continuity_bridge_install")
        if held.get("owner_kind") != "maintenance":
            raise RolloutDenied("not_a_maintenance_lease")
        assert_exact_main(release, main_sha)
        assert_owner_grant(release, grant_pr, grant_head, grant_merge)
        call(["bash", str(bridge)], 1100)
        if not INSTALLED_WORKER.is_file():
            raise RolloutDenied("bridge_worker_not_installed")
        if hashlib.sha256(worker.read_bytes()).digest() != hashlib.sha256(
                INSTALLED_WORKER.read_bytes()).digest():
            raise RolloutDenied("bridge_worker_hash_mismatch")
        call(["systemctl", "--user", "is-active", "--quiet",
              "shopvivaliz-chatgpt-continuity.service"], 20)
    finally:
        runtime_lock.release_runtime_lock(
            lease["lease_id"], lease["fencing_token"],
            "owner-authorized-bridge-install-finished")

def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--release-dir", type=Path, required=True)
    p.add_argument("--main-sha", required=True)
    p.add_argument("--grant-pr", type=int, required=True)
    p.add_argument("--grant-head-sha", required=True)
    p.add_argument("--grant-merge-sha", required=True)
    a = p.parse_args()
    try:
        execute(a.release_dir, a.main_sha, a.grant_pr,
                a.grant_head_sha, a.grant_merge_sha)
    except Exception as exc:
        print("OWNER_ROLLOUT=DENIED reason=" + type(exc).__name__, file=sys.stderr)
        return 1
    print("OWNER_ROLLOUT=INSTALLED_PENDING_REAL_E2E")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
