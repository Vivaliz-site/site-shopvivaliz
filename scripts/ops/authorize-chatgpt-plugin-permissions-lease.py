#!/usr/bin/env python3
"""Issue ONE owner-PR-verified maintenance lease for ChatGPT plugin settings.

No Chrome clicks, account changes, authorization bypass, or owner impersonation.
Must be launched by the approved backend operator after the owner-reviewed
security PR has been merged. A live runtime lock is still exclusive.
"""
from __future__ import annotations

import argparse
import base64
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import socket
import stat
import subprocess
import sys
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts" / "continuity"))
import runtime_lock
from file_lock import locked_file

REPOSITORY = "Vivaliz-site/site-shopvivaliz"
OWNER = "shopvivaliz-human"
CONTROLLER = "always-free-arm-1787907847-26"
RESOURCE = "mcp:fred-win:plugin-permissions"
PRINCIPAL = "shopvivaliz-central-mcp"
CAPABILITY = "desktop_plugin_permission_maintenance"
OPERATION = "configure_chatgpt_plugin_permissions"
ACTIONS = ["desktop_open", "desktop_click", "desktop_type"]
TTL = 180
STATE_ROOT = Path("/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state")
LEDGER_DIR = STATE_ROOT / "_desktop-plugin-permission-approvals"
SHA = re.compile(r"^[0-9a-f]{40}$")
PR_NUM = re.compile(r"^[1-9][0-9]{0,6}$")

class PermissionMaintenanceDenied(RuntimeError):
    pass

def protected_token() -> str:
    token = os.environ.get("GITHUB_TOKEN", "").strip()
    if not token:
        # Independently provisioned technical identity only. No human-account OAuth.
        proc = subprocess.run(["runuser", "-u", "ubuntu", "--", "gh", "auth", "token"],
                              text=True, capture_output=True, check=False, timeout=20)
        if proc.returncode == 0:
            token = proc.stdout.strip()
    if len(token) < 20 or any(ch.isspace() for ch in token):
        raise PermissionMaintenanceDenied("technical_github_token_unavailable")
    return token

def github_json(path: str, token: str) -> object:
    if not path.startswith(("/user", "/repos/Vivaliz-site/site-shopvivaliz/")):
        raise PermissionMaintenanceDenied("github_endpoint_out_of_scope")
    req = Request("https://api.github.com" + path, headers={
        "Accept": "application/vnd.github+json",
        "Authorization": "Bearer " + token,
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "shopvivaliz-owner-pr-plugin-maintenance",
    })
    with urlopen(req, timeout=20) as resp:
        return json.load(resp)

def verify_approved_pr(pr_number: int, token: str, *, now=None) -> dict:
    now = now or datetime.now(timezone.utc)
    user = github_json("/user", token)
    if not isinstance(user, dict) or user.get("login") in (None, "", OWNER):
        raise PermissionMaintenanceDenied("technical_actor_not_independent")
    pr = github_json(f"/repos/{REPOSITORY}/pulls/{pr_number}", token)
    if not isinstance(pr, dict):
        raise PermissionMaintenanceDenied("github_pr_metadata_missing")
    head = (pr.get("head") or {}).get("sha")
    merge = pr.get("merge_commit_sha")
    if not (isinstance(head, str) and SHA.fullmatch(head) and
            isinstance(merge, str) and SHA.fullmatch(merge)):
        raise PermissionMaintenanceDenied("unverified_pr_sha")
    if os.environ.get("SHOPVIVALIZ_SECURITY_OWNER_LOGIN", "") != OWNER:
        raise PermissionMaintenanceDenied("protected_owner_identity_unavailable")
    env = dict(os.environ, GITHUB_TOKEN=token)
    verifier = ROOT / "scripts" / "validate-owner-pr-grant.py"
    args = [sys.executable, str(verifier),
            "--repository", REPOSITORY,
            "--pr-number", str(pr_number),
            "--merge-sha", merge,
            "--head-sha", head,
            "--resource", RESOURCE,
            "--principal", PRINCIPAL,
            "--capability", CAPABILITY,
            "--operation", OPERATION]
    check = subprocess.run(args, text=True, capture_output=True,
                           env=env, timeout=70, check=False)
    if (check.returncode != 0 or
            check.stdout.strip() != "OWNER_PR_GRANT=AUTHORIZED reason=owner_authorization_verified"):
        raise PermissionMaintenanceDenied("owner_pr_grant_not_authorized")
    raw = github_json(f"/repos/{REPOSITORY}/contents/security/owner-approvals/{pr_number}.json?ref=main", token)
    if not isinstance(raw, dict) or raw.get("encoding") != "base64":
        raise PermissionMaintenanceDenied("owner_grant_readback_unavailable")
    try:
        grant = json.loads(base64.b64decode(raw["content"].replace("\n", ""), validate=True))
    except (KeyError, TypeError, ValueError, UnicodeError) as exc:
        raise PermissionMaintenanceDenied("owner_grant_readback_invalid") from exc
    expected = {
        "schema": "SHOPVIVALIZ_OWNER_PR_GRANT_V1",
        "repository": REPOSITORY,
        "pr_number": pr_number,
        "resource": RESOURCE,
        "principal": PRINCIPAL,
        "host": "Fred-Win",
        "controller_host": CONTROLLER,
        "capabilities": [CAPABILITY],
        "operation": OPERATION,
        "allowed_actions": ACTIONS,
        "target_origin": "https://chatgpt.com",
        "permission_mode": "full_access",
        "one_use": True,
        "ttl_seconds": TTL,
    }
    if not isinstance(grant, dict) or set(grant) != set(expected) | {"expires_at_utc"}:
        raise PermissionMaintenanceDenied("owner_grant_extra_or_missing_fields")
    if any(type(grant.get(k)) is not type(v) or grant.get(k) != v for k, v in expected.items()):
        raise PermissionMaintenanceDenied("owner_grant_scope_different")
    try:
        expires = datetime.fromisoformat(grant["expires_at_utc"].replace("Z", "+00:00"))
    except (KeyError, AttributeError, ValueError) as exc:
        raise PermissionMaintenanceDenied("owner_grant_expiration_invalid") from exc
    if expires <= now:
        raise PermissionMaintenanceDenied("owner_grant_expired")
    return {"pr_number": pr_number, "head_sha": head, "merge_sha": merge,
            "expires_at_utc": grant["expires_at_utc"]}

def assert_official_host_and_source(token: str) -> None:
    if os.name != "posix" or os.geteuid() != 0 or socket.gethostname().split(".")[0] != CONTROLLER:
        raise PermissionMaintenanceDenied("backend_privileged_operator_required")
    if os.environ.get("SHOPVIVALIZ_AGENT_TASK_STATE_DIR", str(STATE_ROOT)) != str(STATE_ROOT):
        raise PermissionMaintenanceDenied("noncanonical_runtime_state_denied")
    source = subprocess.run(["git", "-C", str(ROOT), "rev-parse", "HEAD"],
                            text=True, capture_output=True, timeout=15, check=False)
    main = github_json(f"/repos/{REPOSITORY}/commits/main", token)
    if (source.returncode != 0 or not isinstance(main, dict) or
            source.stdout.strip() != (main.get("sha") or "")):
        raise PermissionMaintenanceDenied("approved_source_not_exact_main")

def issue_one_use(pr_number: int, proof: dict) -> dict:
    if not PR_NUM.fullmatch(str(pr_number)) or proof.get("pr_number") != pr_number:
        raise PermissionMaintenanceDenied("unbound_owner_pr_number")
    if STATE_ROOT.is_symlink() or not STATE_ROOT.is_dir():
        raise PermissionMaintenanceDenied("protected_runtime_state_unavailable")
    try:
        expires = datetime.fromisoformat(proof["expires_at_utc"].replace("Z", "+00:00"))
    except (KeyError, AttributeError, ValueError) as exc:
        raise PermissionMaintenanceDenied("missing_verified_grant_expiration") from exc
    if expires <= datetime.now(timezone.utc):
        raise PermissionMaintenanceDenied("owner_grant_expired_before_issuance")
    LEDGER_DIR.mkdir(mode=0o700, parents=False, exist_ok=True)
    ledger_stat = LEDGER_DIR.lstat()
    if (not stat.S_ISDIR(ledger_stat.st_mode) or
            ledger_stat.st_uid != os.geteuid() or ledger_stat.st_mode & 0o077):
        raise PermissionMaintenanceDenied("untrusted_ledger_directory")
    ledger = LEDGER_DIR / f"pr-{pr_number}.json"
    with locked_file(LEDGER_DIR / "issue.lock"):
        if ledger.exists() or ledger.is_symlink():
            raise PermissionMaintenanceDenied("one_use_owner_grant_already_consumed")
        owner_id = f"shopvivaliz-plugin-permissions:{pr_number}"
        try:
            lock = runtime_lock.acquire_runtime_lock("maintenance", owner_id, TTL, list(ACTIONS))
        except runtime_lock.RuntimeLockConflict as exc:
            raise PermissionMaintenanceDenied("runtime_lock_busy_or_unavailable") from exc
        receipt = {"schema": "SHOPVIVALIZ_PLUGIN_MAINTENANCE_LEASE_V1",
                   "pr_number": pr_number, "head_sha": proof["head_sha"],
                   "merge_sha": proof["merge_sha"], "owner_id": owner_id,
                   "lease_id": lock["lease_id"],
                   "fencing_token": lock["fencing_token"],
                   "expires_at_epoch": lock["expires_at_epoch"]}
        try:
            fd = os.open(ledger, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
            with os.fdopen(fd, "w", encoding="utf-8") as out:
                json.dump(receipt, out, sort_keys=True)
                out.write("\n")
                out.flush()
                os.fsync(out.fileno())
        except Exception as exc:
            try:
                runtime_lock.release_runtime_lock(lock["lease_id"], lock["fencing_token"], "ledger_persistence_failed")
            except Exception:
                pass
            raise PermissionMaintenanceDenied("lease_ledger_persistence_failed") from exc
    return {"status": "SCOPED_RUNTIME_LEASE_ISSUED", "host": "Fred-Win",
            "pr_number": pr_number,
            "owner_id": owner_id, "lease_id": lock["lease_id"],
            "fencing_token": lock["fencing_token"],
            "expires_at_epoch": lock["expires_at_epoch"],
            "allowed_actions": ACTIONS}

def release_owned(pr_number: int, lease_id: str, fencing_token: int) -> dict:
    lock = runtime_lock.get_runtime_lock()
    expected_owner = f"shopvivaliz-plugin-permissions:{pr_number}"
    if (not isinstance(lock, dict) or lock.get("owner_id") != expected_owner or
            lock.get("lease_id") != lease_id or
            lock.get("fencing_token") != fencing_token):
        raise PermissionMaintenanceDenied("maintenance_lease_owner_mismatch")
    runtime_lock.release_runtime_lock(lease_id, fencing_token, "official_settings_ui_completed")
    return {"status": "SCOPED_RUNTIME_LEASE_RELEASED", "pr_number": pr_number}

def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--pr-number", type=int, required=True)
    group = p.add_mutually_exclusive_group(required=True)
    group.add_argument("--issue", action="store_true")
    group.add_argument("--release", action="store_true")
    p.add_argument("--lease-id")
    p.add_argument("--fencing-token", type=int)
    args = p.parse_args()
    try:
        if not PR_NUM.fullmatch(str(args.pr_number)):
            raise PermissionMaintenanceDenied("invalid_pr_number")
        if args.release:
            if os.name != "posix" or os.geteuid() != 0 or socket.gethostname().split(".")[0] != CONTROLLER:
                raise PermissionMaintenanceDenied("backend_privileged_operator_required")
            if not args.lease_id or args.fencing_token is None:
                raise PermissionMaintenanceDenied("lease_identity_required")
            result = release_owned(args.pr_number, args.lease_id, args.fencing_token)
        else:
            token = protected_token()
            assert_official_host_and_source(token)
            proof = verify_approved_pr(args.pr_number, token)
            result = issue_one_use(args.pr_number, proof)
        print(json.dumps(result, sort_keys=True))
        return 0
    except (PermissionMaintenanceDenied, OSError, subprocess.TimeoutExpired) as exc:
        reason = str(exc) if isinstance(exc, PermissionMaintenanceDenied) else "trusted_preflight_or_runtime_unavailable"
        print(json.dumps({"status": "DENIED", "reason": reason}, sort_keys=True))
        return 2

if __name__ == "__main__":
    sys.exit(main())
