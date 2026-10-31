#!/usr/bin/env python3
"""Verify a commercial PR review from GitHub, never from a PR-controlled label.

Trusted configuration is supplied via protected Actions secrets, not the PR.
No credentials, approval events, or authorization flags are accepted from
workflow_dispatch inputs or the patch under review.
"""
from __future__ import annotations

import json
import os
import re
import urllib.error
import urllib.request

SHA = re.compile(r"^[0-9a-f]{40}$")
REPO = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")
LOGIN = re.compile(r"^[A-Za-z0-9-]{1,39}$")


class ApprovalDenied(Exception):
    pass


def decide(pr: dict, reviews: list[dict], permission: dict, *,
           owner: str, automations: set[str], expected_head: str,
           expected_base: str, expected_number: int) -> tuple[bool, str]:
    owner = owner.lower().strip()
    actors = {a.lower().strip() for a in automations}
    author = str((pr.get("user") or {}).get("login") or "").lower().strip()
    if not LOGIN.fullmatch(owner) or not actors or not all(LOGIN.fullmatch(a) for a in actors):
        return False, "missing_trusted_identity_configuration"
    # The GitHub user fredmourao-ai is available to operational connectors.
    # Until ownership is segregated it cannot attest a human-only action.
    if "fredmourao-ai" not in actors or owner in actors:
        return False, "shared_credential_cannot_attest_human"
    if author == owner or not author:
        return False, "author_cannot_review_own_pr"
    if pr.get("number") != expected_number or pr.get("state") != "open":
        return False, "wrong_pr_or_not_open"
    if pr.get("draft") or pr.get("merged") or pr.get("merged_at"):
        return False, "pr_not_reviewable"
    if (pr.get("head") or {}).get("sha") != expected_head or not SHA.fullmatch(expected_head):
        return False, "head_changed_since_review"
    if (pr.get("base") or {}).get("sha") != expected_base or not SHA.fullmatch(expected_base):
        return False, "base_changed_since_validation"
    if str((permission or {}).get("permission") or "").lower() not in {"admin", "maintain", "write"}:
        return False, "owner_lacks_required_repository_permission"
    if (permission.get("user") or {}).get("login", "").lower() != owner:
        return False, "permission_subject_mismatch"
    # REST reviews carry commit_id and chronological review ID. The latest
    # decision must be APPROVED on the exact head. Dismissal/change request
    # or a new commit invalidates earlier approvals.
    owner_reviews = [r for r in reviews
                     if str((r.get("user") or {}).get("login") or "").lower() == owner]
    if not owner_reviews:
        return False, "owner_review_missing"
    if any(type(r.get("id")) is not int or r["id"] <= 0 for r in owner_reviews):
        return False, "unverifiable_review_order"
    latest = max(owner_reviews, key=lambda r: r["id"])
    if latest.get("state") != "APPROVED" or latest.get("commit_id") != expected_head:
        return False, "owner_review_not_approved_on_current_head"
    return True, "human_owner_review_verified"


def github_json(url: str, token: str):
    req = urllib.request.Request(
        url,
        headers={"Authorization": "Bearer " + token,
                 "Accept": "application/vnd.github+json",
                 "X-GitHub-Api-Version": "2022-11-28",
                 "User-Agent": "shopvivaliz-commercial-review-gate"},
    )
    try:
        with urllib.request.urlopen(req, timeout=12) as response:
            return json.load(response)
    except (urllib.error.HTTPError, urllib.error.URLError, ValueError, TimeoutError) as e:
        raise ApprovalDenied("github_api_unavailable") from e


def verify_live_review(*, repo: str, number: str, head: str, base: str,
                       owner: str, automation_logins: str, token: str):
    if (not REPO.fullmatch(repo) or not number.isdecimal()
            or int(number) < 1 or not SHA.fullmatch(head)
            or not SHA.fullmatch(base) or not token):
        return False, "missing_or_invalid_pr_evidence"
    actors = {s.strip().lower() for s in automation_logins.split(",") if s.strip()}
    if not owner or not actors:
        return False, "missing_trusted_identity_configuration"
    url = "https://api.github.com/repos/" + repo
    try:
        pr = github_json(f"{url}/pulls/{number}", token)
        permission = github_json(f"{url}/collaborators/{owner}/permission", token)
        reviews = []
        for page in range(1, 7):
            batch = github_json(f"{url}/pulls/{number}/reviews?per_page=100&page={page}", token)
            if not isinstance(batch, list):
                return False, "invalid_github_reviews_response"
            reviews.extend(batch)
            if len(batch) < 100:
                break
        else:
            return False, "review_history_unbounded"
    except ApprovalDenied as err:
        return False, str(err)
    if not isinstance(pr, dict) or not isinstance(permission, dict):
        return False, "invalid_github_pr_response"
    return decide(pr, reviews, permission, owner=owner, automations=actors,
                  expected_head=head, expected_base=base,
                  expected_number=int(number))


def approval_from_environment():
    # GH_PR_NUMBER is supplied by github.event.pull_request.number, never
    # from a user-provided workflow_dispatch input.
    return verify_live_review(
        repo=os.environ.get("GITHUB_REPOSITORY", ""),
        number=os.environ.get("GH_PR_NUMBER", ""),
        head=os.environ.get("HEAD_SHA", ""),
        base=os.environ.get("BASE_SHA", ""),
        owner=os.environ.get("SHOPVIVALIZ_COMMERCIAL_OWNER_LOGIN", ""),
        automation_logins=os.environ.get("SHOPVIVALIZ_COMMERCIAL_AUTOMATION_LOGINS", ""),
        token=os.environ.get("GITHUB_TOKEN", ""),
    )
