#!/usr/bin/env python3
"""Fail-closed GitHub PR owner authorization proof for ShopVivaliz-controlled providers.

This checks human authorization only. It never creates users, grants roles, or
alters runtime locks. Downstream tools must perform their own capability checks.
"""
from __future__ import annotations

import argparse
import base64
from datetime import datetime, timezone
import json
import os
import re
import sys
from urllib.parse import quote
from urllib.request import Request, urlopen

SCHEMA = "SHOPVIVALIZ_OWNER_PR_GRANT_V1"
SHA40 = re.compile(r"^[0-9a-f]{40}$")
LOGIN = re.compile(r"^[A-Za-z0-9-]{1,39}$")
REPO = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")


def decide(pr: object, grant: object, *, repository: str, pr_number: int,
           owner_login: str, merge_sha: str, head_sha: str, resource: str,
           principal: str, capability: str, now: datetime | None = None) -> tuple[bool, str]:
    """Require authenticated owner merger and exact immutable scope match."""
    if not (isinstance(pr, dict) and isinstance(grant, dict)):
        return False, "invalid_response_shape"
    if not (REPO.fullmatch(repository) and LOGIN.fullmatch(owner_login) and
            SHA40.fullmatch(merge_sha) and SHA40.fullmatch(head_sha) and
            isinstance(pr_number, int) and pr_number > 0):
        return False, "invalid_trust_parameters"
    if not resource or not principal or not capability:
        return False, "missing_requested_scope"
    if not (pr.get("merged") is True and pr.get("state") == "closed" and
            pr.get("number") == pr_number):
        return False, "pr_not_merged"
    if str(pr.get("merged_by", {}).get("login", "")).casefold() != owner_login.casefold():
        return False, "merge_actor_not_owner"
    if pr.get("merge_commit_sha") != merge_sha or pr.get("head", {}).get("sha") != head_sha:
        return False, "commit_binding_mismatch"
    base = pr.get("base")
    if not isinstance(base, dict) or base.get("ref") != "main" or base.get("repo", {}).get("full_name") != repository:
        return False, "unexpected_merge_target"
    if grant.get("schema") != SCHEMA or grant.get("repository") != repository or grant.get("pr_number") != pr_number:
        return False, "invalid_grant_identity"
    if grant.get("resource") != resource or grant.get("principal") != principal:
        return False, "resource_or_principal_mismatch"
    caps = grant.get("capabilities")
    if not isinstance(caps, list) or not caps or any(not isinstance(s, str) or not s for s in caps) or len(set(caps)) != len(caps):
        return False, "invalid_capability_list"
    if capability not in caps:
        return False, "capability_not_approved"
    expiry = grant.get("expires_at_utc")
    try:
        if not isinstance(expiry, str) or not expiry.endswith("Z"):
            return False, "invalid_expiry"
        expires = datetime.fromisoformat(expiry.replace("Z", "+00:00"))
    except ValueError:
        return False, "invalid_expiry"
    if expires <= (now or datetime.now(timezone.utc)):
        return False, "authorization_expired"
    return True, "owner_authorization_verified"


def immutable_grant_still_active(approved_blob: object, current_blob: object) -> bool:
    """Revocation or later edits on main invalidate an older authorization."""
    return bool(isinstance(approved_blob, dict) and isinstance(current_blob, dict)
                and approved_blob.get("sha") and approved_blob.get("sha") == current_blob.get("sha")
                and approved_blob.get("path") == current_blob.get("path"))


def grant_added_in_reviewed_pr(changed_files: object, path: str, approved_blob: object) -> bool:
    """A grant must originate in the reviewed PR, not a preexisting main file.

    The API is requested with per_page=100. Refuse a saturated first page
    rather than silently trusting an incomplete PR diff. The blob at the
    merged commit must exactly match the file introduced by that PR.
    """
    if (not isinstance(changed_files, list) or not 0 < len(changed_files) < 100
            or not isinstance(approved_blob, dict) or not approved_blob.get("sha")
            or approved_blob.get("path") != path):
        return False
    matches = [f for f in changed_files if isinstance(f, dict) and f.get("filename") == path]
    return (len(matches) == 1 and matches[0].get("status") == "added"
            and matches[0].get("sha") == approved_blob["sha"]
            and not matches[0].get("previous_filename"))


def github_json(path: str, token: str) -> object:
    request = Request("https://api.github.com" + path, headers={
        "Accept": "application/vnd.github+json",
        "Authorization": "Bearer " + token,
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "shopvivaliz-owner-pr-authorization",
    })
    with urlopen(request, timeout=15) as response:
        return json.load(response)


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--repository", required=True)
    p.add_argument("--pr-number", required=True, type=int)
    p.add_argument("--merge-sha", required=True)
    p.add_argument("--head-sha", required=True)
    p.add_argument("--resource", required=True)
    p.add_argument("--principal", required=True)
    p.add_argument("--capability", required=True)
    args = p.parse_args()
    owner = os.environ.get("SHOPVIVALIZ_SECURITY_OWNER_LOGIN", "").strip()
    token = os.environ.get("GITHUB_TOKEN", "").strip()
    if not owner or not token or not REPO.fullmatch(args.repository) or args.pr_number <= 0:
        print("OWNER_PR_GRANT=DENIED reason=missing_trusted_configuration")
        return 1
    path = "security/owner-approvals/" + str(args.pr_number) + ".json"
    repo = quote(args.repository, safe="/")
    try:
        pr = github_json(f"/repos/{repo}/pulls/{args.pr_number}", token)
        encoded = github_json(
            f"/repos/{repo}/contents/{path}?ref={quote(args.merge_sha, safe='')}", token)
        if not isinstance(encoded, dict) or encoded.get("encoding") != "base64":
            raise ValueError("unsupported_contents_encoding")
        current = github_json(f"/repos/{repo}/contents/{path}?ref=main", token)
        if not immutable_grant_still_active(encoded, current):
            raise ValueError("authorization_revoked_or_replaced")
        changed = github_json(f"/repos/{repo}/pulls/{args.pr_number}/files?per_page=100", token)
        if not grant_added_in_reviewed_pr(changed, path, encoded):
            ok, reason = False, "grant_not_added_by_reviewed_pr"
        else:
            raw = base64.b64decode(encoded["content"].replace("\n", ""), validate=True)
            grant = json.loads(raw.decode("utf-8"))
            ok, reason = decide(pr, grant, repository=args.repository,
                                pr_number=args.pr_number, owner_login=owner,
                                merge_sha=args.merge_sha, head_sha=args.head_sha,
                                resource=args.resource, principal=args.principal,
                                capability=args.capability)
    except Exception:
        ok, reason = False, "verification_unavailable"
    print(f"OWNER_PR_GRANT={'AUTHORIZED' if ok else 'DENIED'} reason={reason}")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
