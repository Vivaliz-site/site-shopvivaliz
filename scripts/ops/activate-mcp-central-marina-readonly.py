#!/usr/bin/env python3
"""Owner-PR-gated, expiring, read-only central MCP route to Marina.

Run only by a trusted backend operator, from a clean checkout of exact
repository main. No state change until official GitHub verification succeeds.
Do not print tokens, key material, remote shell output, or protected profiles.
"""
from __future__ import annotations

import argparse
import base64
import json
import os
import pwd
from pathlib import Path
import re
import socket
import stat
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone
from urllib.request import Request, urlopen

REPOSITORY = "Vivaliz-site/site-shopvivaliz"
OWNER = "shopvivaliz-human"
CONTROLLER = "always-free-arm-1787907847-26"
MARINA = "marina-a1-4c24g"
STATE_DIR = Path("/var/lib/shopvivaliz-remote-control")
GRANT_STATE = STATE_DIR / "marina-central-owner-grant.json"
DROPIN = Path("/etc/systemd/system/shopvivaliz-remote-control-mcp.service.d/92-marina-readonly-grant.conf")
SERVER = Path("/opt/shopvivaliz-remote-control/server.py")
SSH_KEY = Path("/home/ubuntu/.ssh/oci-marina-a1-2026")
PINNED = Path("/home/ubuntu/.ssh/known_hosts_marina_verified")
PR_SCHEMA = "SHOPVIVALIZ_OWNER_PR_GRANT_V1"
RUNTIME_SCHEMA = "SHOPVIVALIZ_CENTRAL_MARINA_RUNTIME_GRANT_V1"
SHA = re.compile(r"^[0-9a-f]{40}$")
ROOT = Path(__file__).resolve().parents[2]


class ActivationDenied(RuntimeError):
    pass


def run(argv: list[str], *, token: str | None = None) -> str:
    env = dict(os.environ)
    if token is not None:
        env.update(GITHUB_TOKEN=token, GH_TOKEN=token,
                   SHOPVIVALIZ_SECURITY_OWNER_LOGIN=OWNER)
    proc = subprocess.run(argv, env=env, text=True, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=45, check=False)
    if proc.returncode != 0:
        # Never include subprocess stderr: GitHub CLI and auth providers may
        # incidentally echo private config or token-bearing endpoints.
        raise ActivationDenied("official_verification_or_command_failed")
    return proc.stdout.strip()


def gh_token() -> str:
    # The existing, independently authorized Ubuntu GitHub identity is used.
    # No agent is permitted to borrow credentials from shopvivaliz-human.
    token = run(["runuser", "-u", "ubuntu", "--", "gh", "auth", "token"])
    if len(token) < 20 or any(c.isspace() for c in token):
        raise ActivationDenied("official_github_auth_unavailable")
    return token


def github(endpoint: str, token: str) -> object:
    raw = run(["runuser", "-u", "ubuntu", "--", "gh", "api",
               f"repos/{REPOSITORY}/{endpoint}"], token=token)
    try:
        return json.loads(raw)
    except ValueError as exc:
        raise ActivationDenied("invalid_github_api_response") from exc


def validate_metadata(path: Path, *, key: bool = False) -> None:
    try:
        st = path.lstat()
        if (not stat.S_ISREG(st.st_mode) or st.st_uid != pwd.getpwnam("ubuntu").pw_uid or
                st.st_mode & (0o077 if key else 0o022) or st.st_size <= 0):
            raise ActivationDenied("ssh_identity_or_host_key_metadata_invalid")
    except OSError as exc:
        raise ActivationDenied("ssh_identity_or_host_key_metadata_unavailable") from exc


def grant_proof(pr_number: int, token: str) -> dict:
    pr = github(f"pulls/{pr_number}", token)
    if not isinstance(pr, dict) or "auto_merge" not in pr:
        raise ActivationDenied("personal_owner_merge_not_verified")
    auto = pr["auto_merge"]
    # An owner APPROVED review of the current SHA is validated by the
    # canonical owner-pr verifier below. Native auto-merge is allowed only
    # when the same human owner enabled it; the GitHub checks still gate merge.
    if auto is not None:
        enabled_by = auto.get("enabled_by") if isinstance(auto, dict) else None
        if (not isinstance(enabled_by, dict) or
                enabled_by.get("login") != OWNER or
                auto.get("merge_method") not in ("merge", "squash", "rebase")):
            raise ActivationDenied("owner_auto_merge_not_authorized")
    if (not pr.get("merged") or pr.get("state") != "closed" or
            pr.get("user", {}).get("login") == OWNER or
            pr.get("merged_by", {}).get("login") != OWNER):
        raise ActivationDenied("personal_owner_merge_not_verified")
    head = pr.get("head", {}).get("sha")
    merge = pr.get("merge_commit_sha")
    if not isinstance(head, str) or not SHA.fullmatch(head) or not isinstance(merge, str) or not SHA.fullmatch(merge):
        raise ActivationDenied("untrusted_pr_commit_ids")
    # Execute the canonical immutable-grant validator. It verifies that the
    # grant was ADDED in the reviewed PR and remains unchanged on main.
    run([sys.executable, str(ROOT / "scripts/validate-owner-pr-grant.py"),
         "--repository", REPOSITORY, "--pr-number", str(pr_number),
         "--merge-sha", merge, "--head-sha", head,
         "--resource", "mcp:central:marina",
         "--principal", "shopvivaliz-central-mcp",
         "--capability", "marina_readonly"], token=token)
    encoded = github(f"contents/security/owner-approvals/{pr_number}.json?ref=main", token)
    if not isinstance(encoded, dict) or encoded.get("encoding") != "base64":
        raise ActivationDenied("grant_contents_unavailable")
    try:
        grant = json.loads(base64.b64decode(encoded["content"]).decode("utf-8"))
    except (KeyError, ValueError, UnicodeError) as exc:
        raise ActivationDenied("grant_contents_invalid") from exc
    expected = {
        "schema": PR_SCHEMA, "repository": REPOSITORY,
        "pr_number": pr_number, "resource": "mcp:central:marina",
        "principal": "shopvivaliz-central-mcp", "host": MARINA,
        "controller_host": CONTROLLER, "capabilities": ["marina_readonly"],
    }
    if not isinstance(grant, dict) or any(grant.get(k) != v for k, v in expected.items()):
        raise ActivationDenied("grant_exact_scope_failed")
    expiry = grant.get("expires_at_utc")
    try:
        deadline = datetime.fromisoformat(expiry.replace("Z", "+00:00")) if isinstance(expiry, str) and expiry.endswith("Z") else None
    except ValueError:
        deadline = None
    if deadline is None or deadline <= datetime.now(timezone.utc):
        raise ActivationDenied("grant_expired")
    return {
        "schema": RUNTIME_SCHEMA, "repository": REPOSITORY,
        "pr_number": pr_number, "resource": "mcp:central:marina",
        "principal": "shopvivaliz-central-mcp", "host": MARINA,
        "controller_host": CONTROLLER, "capabilities": ["marina_readonly"],
        "head_sha": head, "merge_sha": merge, "reviewer": OWNER,
        "merged_by": OWNER, "expires_at_utc": expiry,
    }


def preflight(pr_number: int) -> dict:
    if os.geteuid() != 0 or socket.gethostname().split(".")[0] != CONTROLLER:
        raise ActivationDenied("operator_must_be_root_on_backend")
    if not STATE_DIR.is_dir() or STATE_DIR.is_symlink():
        raise ActivationDenied("protected_state_dir_unavailable")
    validate_metadata(SSH_KEY, key=True)
    validate_metadata(PINNED)
    if run(["git", "-C", str(ROOT), "rev-parse", "HEAD"]) != run(
            ["runuser", "-u", "ubuntu", "--", "gh", "api",
             f"repos/{REPOSITORY}/commits/main", "--jq", ".sha"]):
        raise ActivationDenied("operator_source_not_exact_main")
    if not SERVER.is_file() or SERVER.read_bytes() != (ROOT / "remote-control-mcp/server.py").read_bytes():
        raise ActivationDenied("approved_mcp_code_not_installed")
    token = gh_token()
    return grant_proof(pr_number, token)


def write_private(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmpname = tempfile.mkstemp(prefix=".marina-owner-", dir=str(path.parent))
    tmp = Path(tmpname)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as out:
            out.write(text)
            out.flush()
            os.fsync(out.fileno())
        tmp.chmod(0o600)
        os.replace(tmp, path)
    finally:
        tmp.unlink(missing_ok=True)


def restart_and_verify() -> None:
    run(["systemctl", "daemon-reload"])
    run(["systemctl", "restart", "shopvivaliz-remote-control-mcp.service"])
    for _ in range(20):
        try:
            run(["curl", "--fail", "--silent", "--show-error", "--max-time", "2",
                 "http://127.0.0.1:5580/health"])
            return
        except ActivationDenied:
            time.sleep(0.5)
    raise ActivationDenied("central_mcp_health_not_ready")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=["preflight", "activate", "revoke"])
    parser.add_argument("--pr", type=int, default=3478)
    args = parser.parse_args()
    try:
        if args.action == "revoke":
            if os.geteuid() != 0 or socket.gethostname().split(".")[0] != CONTROLLER:
                raise ActivationDenied("operator_must_be_root_on_backend")
            # Immediate local revocation needs no external GitHub access.
            DROPIN.unlink(missing_ok=True)
            GRANT_STATE.unlink(missing_ok=True)
            restart_and_verify()
            print("CENTRAL_MARINA_GRANT_REVOKED=PASS")
            return 0
        if args.pr <= 0:
            raise ActivationDenied("invalid_pr")
        attestation = preflight(args.pr)
        if args.action == "preflight":
            print("CENTRAL_MARINA_GRANT_PREFLIGHT=PASS no_runtime_change=true")
            return 0
        if GRANT_STATE.exists() or DROPIN.exists():
            raise ActivationDenied("existing_grant_requires_explicit_revocation")
        write_private(GRANT_STATE, json.dumps(attestation, sort_keys=True) + "\n")
        try:
            write_private(DROPIN, '[Service]\nEnvironment=SHOPVIVALIZ_MARINA_CENTRAL_READONLY_ENABLED=approved-v1\n')
            DROPIN.chmod(0o644)
            restart_and_verify()
        except Exception:
            DROPIN.unlink(missing_ok=True)
            GRANT_STATE.unlink(missing_ok=True)
            restart_and_verify()
            raise
        print("CENTRAL_MARINA_GRANT_INSTALLED=PASS")
        return 0
    except (ActivationDenied, subprocess.TimeoutExpired, OSError) as exc:
        # Public reason only; never include credential or subprocess output.
        reason = str(exc) if isinstance(exc, ActivationDenied) else "operator_or_runtime_unavailable"
        print(f"CENTRAL_MARINA_GRANT=DENIED reason={reason}")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
