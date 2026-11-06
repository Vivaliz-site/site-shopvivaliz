#!/usr/bin/env python3
"""Owner-PR-gated administrative central MCP route to Marina (marina_admin_command).

Run only by a trusted backend operator (root on the controller), from a clean
checkout of exact repository main. No state change happens until the official
GitHub verification of the merged owner grant succeeds. The read-only grant,
its attestation and its drop-ins are never touched.
Do not print tokens, key material, remote shell output, or protected profiles.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
from pathlib import Path
import socket
import stat
import subprocess
import sys
from datetime import datetime, timezone

_READONLY = Path(__file__).with_name("activate-mcp-central-marina-readonly.py")
_spec = importlib.util.spec_from_file_location("_marina_central_readonly", _READONLY)
base = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(base)

ActivationDenied = base.ActivationDenied
CAPABILITY = "marina_admin_command"
ADMIN_STATE = base.STATE_DIR / "marina-central-admin-owner-grant.json"
ADMIN_DROPIN = Path("/etc/systemd/system/shopvivaliz-remote-control-mcp.service.d/93-marina-admin-grant.conf")
BROWSER_ADMIN_DROPIN = Path("/etc/systemd/system/shopvivaliz-remote-control-browser-mcp.service.d/93-marina-admin-grant.conf")
ADMIN_FLAG_TEXT = "[Service]\nEnvironment=SHOPVIVALIZ_MARINA_CENTRAL_ADMIN_ENABLED=approved-v1\n"


def grant_proof(pr_number: int, token: str) -> dict:
    pr = base.github(f"pulls/{pr_number}", token)
    if not isinstance(pr, dict) or "auto_merge" not in pr:
        raise ActivationDenied("personal_owner_merge_not_verified")
    auto = pr["auto_merge"]
    if auto is not None:
        enabled_by = auto.get("enabled_by") if isinstance(auto, dict) else None
        if (not isinstance(enabled_by, dict) or enabled_by.get("login") != base.OWNER or
                auto.get("merge_method") not in ("merge", "squash", "rebase")):
            raise ActivationDenied("owner_auto_merge_not_authorized")
    if (not pr.get("merged") or pr.get("state") != "closed" or
            pr.get("user", {}).get("login") == base.OWNER or
            pr.get("merged_by", {}).get("login") != base.OWNER):
        raise ActivationDenied("personal_owner_merge_not_verified")
    head = pr.get("head", {}).get("sha")
    merge = pr.get("merge_commit_sha")
    if (not isinstance(head, str) or not base.SHA.fullmatch(head) or
            not isinstance(merge, str) or not base.SHA.fullmatch(merge)):
        raise ActivationDenied("untrusted_pr_commit_ids")
    # Canonical immutable-grant validator: the grant was ADDED in the reviewed
    # PR (APPROVED by the human owner on the exact head) and is unchanged on main.
    base.run([sys.executable, str(base.ROOT / "scripts/validate-owner-pr-grant.py"),
              "--repository", base.REPOSITORY, "--pr-number", str(pr_number),
              "--merge-sha", merge, "--head-sha", head,
              "--resource", "mcp:central:marina",
              "--principal", "shopvivaliz-central-mcp",
              "--capability", CAPABILITY], token=token)
    encoded = base.github(f"contents/security/owner-approvals/{pr_number}.json?ref=main", token)
    if not isinstance(encoded, dict) or encoded.get("encoding") != "base64":
        raise ActivationDenied("grant_contents_unavailable")
    try:
        grant = json.loads(base.base64.b64decode(encoded["content"]).decode("utf-8"))
    except (KeyError, ValueError, UnicodeError) as exc:
        raise ActivationDenied("grant_contents_invalid") from exc
    expected = {
        "schema": base.PR_SCHEMA, "repository": base.REPOSITORY,
        "pr_number": pr_number, "resource": "mcp:central:marina",
        "principal": "shopvivaliz-central-mcp", "host": base.MARINA,
        "controller_host": base.CONTROLLER, "capabilities": [CAPABILITY],
    }
    if not isinstance(grant, dict) or any(grant.get(k) != v for k, v in expected.items()):
        raise ActivationDenied("grant_exact_scope_failed")
    attestation = {
        "schema": base.RUNTIME_SCHEMA, "repository": base.REPOSITORY,
        "pr_number": pr_number, "resource": "mcp:central:marina",
        "principal": "shopvivaliz-central-mcp", "host": base.MARINA,
        "controller_host": base.CONTROLLER, "capabilities": [CAPABILITY],
        "head_sha": head, "merge_sha": merge, "reviewer": base.OWNER,
        "merged_by": base.OWNER,
    }
    # An expiry is optional for this grant; when present it must still be in the future.
    expiry = grant.get("expires_at_utc")
    if expiry is not None:
        try:
            deadline = (datetime.fromisoformat(expiry.replace("Z", "+00:00"))
                        if isinstance(expiry, str) and expiry.endswith("Z") else None)
        except ValueError:
            deadline = None
        if deadline is None or deadline <= datetime.now(timezone.utc):
            raise ActivationDenied("grant_expired")
        attestation["expires_at_utc"] = expiry
    return attestation


def preflight(pr_number: int) -> dict:
    if os.geteuid() != 0 or socket.gethostname().split(".")[0] != base.CONTROLLER:
        raise ActivationDenied("operator_must_be_root_on_backend")
    if not base.STATE_DIR.is_dir() or base.STATE_DIR.is_symlink():
        raise ActivationDenied("protected_state_dir_unavailable")
    base.validate_metadata(base.SSH_KEY, key=True)
    base.validate_metadata(base.PINNED)
    if base.checkout_sha_as_owner() != base.run(
            ["runuser", "-u", "ubuntu", "--", "gh", "api",
             f"repos/{base.REPOSITORY}/commits/main", "--jq", ".sha"]):
        raise ActivationDenied("operator_source_not_exact_main")
    if (not base.SERVER.is_file() or
            base.SERVER.read_bytes() != (base.ROOT / "remote-control-mcp/server.py").read_bytes()):
        raise ActivationDenied("approved_mcp_code_not_installed")
    return grant_proof(pr_number, base.gh_token())



def verify_current_admin_state(attestation: dict) -> None:
    """Repair must match the exact installed, approved admin grant and base flag."""
    try:
        fd = os.open(ADMIN_STATE, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
        with os.fdopen(fd, "r", encoding="utf-8") as source:
            meta = os.fstat(source.fileno())
            if (not stat.S_ISREG(meta.st_mode) or meta.st_uid != os.geteuid()
                    or meta.st_mode & 0o077 or meta.st_nlink != 1
                    or meta.st_size > 4096):
                raise ActivationDenied("existing_admin_attestation_metadata_invalid")
            current = json.load(source)
    except (OSError, ValueError, TypeError, UnicodeError) as exc:
        raise ActivationDenied("existing_admin_attestation_unavailable") from exc
    if current != attestation:
        raise ActivationDenied("existing_admin_attestation_not_exact_approved_pr")
    try:
        meta = ADMIN_DROPIN.lstat()
        if (not stat.S_ISREG(meta.st_mode) or meta.st_uid != os.geteuid()
                or meta.st_mode & 0o022 or meta.st_nlink != 1
                or ADMIN_DROPIN.read_text() != ADMIN_FLAG_TEXT):
            raise ActivationDenied("base_mcp_admin_flag_not_approved")
    except OSError as exc:
        raise ActivationDenied("base_mcp_admin_flag_missing") from exc


def validate_browser_admin_dropin() -> bool:
    """Fail closed on a replaced, symlinked, or unexpectedly writable unit."""
    parent = BROWSER_ADMIN_DROPIN.parent
    if parent.is_symlink():
        raise ActivationDenied("browser_admin_unit_dir_symlink_forbidden")
    if parent.exists():
        meta = parent.lstat()
        if (not stat.S_ISDIR(meta.st_mode) or meta.st_uid != os.geteuid()
                or meta.st_mode & 0o022):
            raise ActivationDenied("browser_admin_unit_dir_not_trusted")
    if BROWSER_ADMIN_DROPIN.is_symlink():
        raise ActivationDenied("browser_admin_dropin_symlink_forbidden")
    if not BROWSER_ADMIN_DROPIN.exists():
        return False
    meta = BROWSER_ADMIN_DROPIN.lstat()
    if (not stat.S_ISREG(meta.st_mode) or meta.st_uid != os.geteuid()
            or meta.st_mode & 0o022 or meta.st_nlink != 1
            or BROWSER_ADMIN_DROPIN.read_text() != ADMIN_FLAG_TEXT):
        raise ActivationDenied("unexpected_existing_browser_admin_grant")
    return True


def restart_browser_admin_and_verify(*, expected_enabled: bool = True) -> None:
    """Restart only the sibling browser endpoint, without logging the process environment."""
    base.run(["systemctl", "daemon-reload"])
    base.run(["systemctl", "restart", base.BROWSER_SERVICE])
    for _ in range(20):
        try:
            base.run(["curl", "--fail", "--silent", "--show-error", "--max-time", "2",
                      "http://127.0.0.1:5581/health"])
            expected = "0" if expected_enabled else "1"
            # Two backslashes in the Python source create shell tr '\0' and '\n'.
            # A literal NUL in subprocess argv would make this check fail.
            shell_check = (
                "pid=$(/usr/bin/systemctl show " + base.BROWSER_SERVICE +
                " --property=MainPID --value); "
                "test \"$pid\" -gt 1; "
                "if /usr/bin/tr '\\0' '\\n' < \"/proc/$pid/environ\" | "
                "/usr/bin/grep -qx 'SHOPVIVALIZ_MARINA_CENTRAL_ADMIN_ENABLED=approved-v1'; "
                "then test " + expected + " -eq 0; "
                "else test " + expected + " -eq 1; fi"
            )
            base.run(["/usr/bin/bash", "-c", shell_check])
            return
        except ActivationDenied:
            base.time.sleep(0.5)
    raise ActivationDenied("browser_mcp_admin_flag_not_effective")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=["preflight", "activate", "revoke", "repair-browser"])
    parser.add_argument("--pr", type=int, default=None,
                        help="Exact merged owner grant PR; required for preflight/activate")
    args = parser.parse_args()
    try:
        if args.action == "revoke":
            if os.geteuid() != 0 or socket.gethostname().split(".")[0] != base.CONTROLLER:
                raise ActivationDenied("operator_must_be_root_on_backend")
            # Local revocation needs no GitHub access; only the admin route is removed.
            BROWSER_ADMIN_DROPIN.unlink(missing_ok=True)
            ADMIN_DROPIN.unlink(missing_ok=True)
            ADMIN_STATE.unlink(missing_ok=True)
            base.restart_and_verify()
            restart_browser_admin_and_verify(expected_enabled=False)
            print("CENTRAL_MARINA_ADMIN_REVOKED=PASS")
            return 0
        if args.pr is None or args.pr <= 0:
            raise ActivationDenied("explicit_owner_grant_pr_required")
        attestation = preflight(args.pr)
        if args.action == "preflight":
            print("CENTRAL_MARINA_ADMIN_PREFLIGHT=PASS no_runtime_change=true")
            return 0
        if args.action == "repair-browser":
            verify_current_admin_state(attestation)
            if validate_browser_admin_dropin():
                restart_browser_admin_and_verify()
                print("CENTRAL_MARINA_ADMIN_BROWSER_REPAIR=PASS already_configured=true")
                return 0
            base.write_private(BROWSER_ADMIN_DROPIN, ADMIN_FLAG_TEXT)
            BROWSER_ADMIN_DROPIN.chmod(0o644)
            try:
                restart_browser_admin_and_verify()
            except Exception:
                BROWSER_ADMIN_DROPIN.unlink(missing_ok=True)
                restart_browser_admin_and_verify(expected_enabled=False)
                raise
            print("CENTRAL_MARINA_ADMIN_BROWSER_REPAIR=PASS no_base_mutation=true")
            return 0
        if (ADMIN_STATE.exists() or ADMIN_STATE.is_symlink() or
                ADMIN_DROPIN.exists() or ADMIN_DROPIN.is_symlink() or
                BROWSER_ADMIN_DROPIN.exists() or BROWSER_ADMIN_DROPIN.is_symlink()):
            raise ActivationDenied("existing_admin_grant_requires_explicit_revocation")
        base.write_private(ADMIN_STATE, json.dumps(attestation, sort_keys=True) + "\n")
        browser_restart_attempted = False
        try:
            base.write_private(ADMIN_DROPIN, ADMIN_FLAG_TEXT)
            ADMIN_DROPIN.chmod(0o644)
            base.write_private(BROWSER_ADMIN_DROPIN, ADMIN_FLAG_TEXT)
            BROWSER_ADMIN_DROPIN.chmod(0o644)
            base.restart_and_verify()
            browser_restart_attempted = True
            restart_browser_admin_and_verify()
        except Exception:
            BROWSER_ADMIN_DROPIN.unlink(missing_ok=True)
            ADMIN_DROPIN.unlink(missing_ok=True)
            ADMIN_STATE.unlink(missing_ok=True)
            base.restart_and_verify()
            if browser_restart_attempted:
                restart_browser_admin_and_verify(expected_enabled=False)
            raise
        print("CENTRAL_MARINA_ADMIN_INSTALLED=PASS")
        return 0
    except (ActivationDenied, subprocess.TimeoutExpired, OSError) as exc:
        reason = str(exc) if isinstance(exc, ActivationDenied) else "operator_or_runtime_unavailable"
        print(f"CENTRAL_MARINA_ADMIN=DENIED reason={reason}")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
