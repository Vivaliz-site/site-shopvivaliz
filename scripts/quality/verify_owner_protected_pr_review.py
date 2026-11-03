#!/usr/bin/env python3
"""Fail-closed status gate for owner-only security PRs.

Intended to run from a *trusted base checkout* in the required validation gate.
A CI-green status alone is never an APPROVED review by shopvivaliz-human.
This tool grants no privileges and makes no GitHub mutations.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
from urllib.request import Request, urlopen

OWNER = "shopvivaliz-human"
SHA40 = re.compile(r"^[0-9a-f]{40}$")
REPO = re.compile(r"^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$")
PROTECTED_PREFIXES = ("security/owner-approvals/", "security/requests/")
PROTECTED_EXACT = frozenset({
    ".github/CODEOWNERS",
    "docs/knowledge/security-approval-via-pr.md",
    "scripts/validate-owner-pr-grant.py",
    ".github/workflows/mandatory-validation-gate.yml",
    "scripts/quality/verify_owner_protected_pr_review.py",
    ".github/workflows/pr-owner-approval-merge-queue.yml",
    ".github/workflows/pr-owner-merge-queue-activate.yml",
    "scripts/ops/owner_approved_merge_queue.py",
    "scripts/ops/activate_owner_merge_queue_ruleset.py",
})


def is_protected_path(path: str) -> bool:
    return path in PROTECTED_EXACT or any(path.startswith(p) for p in PROTECTED_PREFIXES)


def decide(files: object, reviews: object, head_sha: str, changed_files: object = None) -> tuple[bool, str]:
    """Fail closed for untrusted/incomplete GitHub pages and stale approvals."""
    if not isinstance(head_sha, str) or not SHA40.fullmatch(head_sha):
        return False, "invalid_head_sha"
    if not isinstance(files, list) or len(files) >= 100:
        return False, "pr_files_unavailable_or_incomplete"
    if not files:
        if changed_files == 0:
            return True, "non_protected_pr"
        return False, "pr_files_unavailable_or_incomplete"
    if any(not isinstance(f, dict) or not isinstance(f.get("filename"), str) for f in files):
        return False, "pr_files_invalid"
    protected = sorted({f["filename"] for f in files if is_protected_path(f["filename"])})
    if not protected:
        return True, "non_protected_pr"
    if not isinstance(reviews, list) or len(reviews) >= 100:
        return False, "owner_reviews_unavailable_or_incomplete"
    owner_reviews = []
    for review in reviews:
        if not isinstance(review, dict):
            return False, "review_shape_invalid"
        user = review.get("user")
        if not isinstance(user, dict):
            return False, "review_actor_missing"
        if str(user.get("login", "")).lower() == OWNER:
            if review.get("state") in ("APPROVED", "CHANGES_REQUESTED", "DISMISSED"):
                owner_reviews.append(review)
    if not owner_reviews:
        return False, "owner_approval_missing"
    # The reviews API returns chronological creation order. Sort by submitted_at
    # for explicit and stable handling; reject any missing timestamps.
    if any(not isinstance(r.get("submitted_at"), str) or not r["submitted_at"] for r in owner_reviews):
        return False, "owner_review_timestamp_missing"
    latest = max(owner_reviews, key=lambda r: r["submitted_at"])
    if latest.get("state") != "APPROVED":
        return False, "latest_owner_review_not_approved"
    if latest.get("commit_id") != head_sha:
        return False, "approval_not_bound_to_current_head"
    return True, "owner_review_approved_exact_head"


def github_json(repo: str, api_path: str, token: str):
    request = Request(
        "https://api.github.com/repos/" + repo + api_path,
        headers={
            "Authorization": "Bearer " + token,
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "shopvivaliz-owner-review-gate",
        },
    )
    with urlopen(request, timeout=12) as response:
        raw = response.read(1_000_001)
    if len(raw) > 1_000_000:
        raise ValueError("github_response_too_large")
    return json.loads(raw)


def main(argv=None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo", required=True)
    parser.add_argument("--pr", required=True, type=int)
    parser.add_argument("--head", required=True)
    args = parser.parse_args(argv)
    token = os.getenv("GITHUB_TOKEN", "")
    if not REPO.fullmatch(args.repo) or args.pr <= 0 or not SHA40.fullmatch(args.head):
        print("OWNER_REVIEW_GATE=DENIED reason=invalid_args")
        return 2
    if not token or len(token) < 10:
        print("OWNER_REVIEW_GATE=DENIED reason=github_token_unavailable")
        return 2
    try:
        pr = github_json(args.repo, f"/pulls/{args.pr}", token)
        if not isinstance(pr, dict) or pr.get("state") != "open":
            raise ValueError("pr_not_open")
        if pr.get("head", {}).get("sha") != args.head:
            raise ValueError("pr_head_moved")
        if pr.get("base", {}).get("ref") != "main":
            raise ValueError("unexpected_base")
        if pr.get("head", {}).get("repo", {}).get("full_name") != args.repo:
            raise ValueError("untrusted_head_repo")
        files = github_json(args.repo, f"/pulls/{args.pr}/files?per_page=100", token)
        if not isinstance(files, list) or not 0 < len(files) < 100:
            raise ValueError("pr_files_unavailable_or_incomplete")
        protected = any(is_protected_path(f.get("filename", ""))
                        for f in files if isinstance(f, dict))
        reviews = (github_json(args.repo, f"/pulls/{args.pr}/reviews?per_page=100", token)
                   if protected else [])
        approved, reason = decide(files, reviews, args.head, pr.get("changed_files"))
    except (OSError, ValueError, KeyError, TypeError, json.JSONDecodeError) as exc:
        print("OWNER_REVIEW_GATE=DENIED reason=github_verification_unavailable")
        print(f"OWNER_REVIEW_DIAGNOSTIC={type(exc).__name__}")
        return 2
    print(f"OWNER_REVIEW_GATE={'PASS' if approved else 'DENIED'} reason={reason}")
    return 0 if approved else 3


if __name__ == "__main__":
    sys.exit(main())
