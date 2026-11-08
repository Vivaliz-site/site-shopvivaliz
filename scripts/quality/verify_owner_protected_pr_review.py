#!/usr/bin/env python3
"""Fail-closed status gate for owner-only PRs (VM deletion and price changes).

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
OPERATOR = "fredmourao-ai"
DUAL_REQUIRED_REVIEWERS = frozenset({OWNER, OPERATOR})
SHA40 = re.compile(r"^[0-9a-f]{40}$")
REPO = re.compile(r"^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$")
# Only two change classes need the owner's approval. Each rule pairs the
# directories it applies to with the file-name terms that mark the change.
OWNER_SENSITIVE_RULES = (
    # VM deletion: tooling that terminates or destroys an instance.
    (("scripts/ops/", "deploy/"), ("terminate", "destroy", "vm-delete", "delete-instance")),
    # Price changes: pricing code in the storefront runtime.
    (("includes/", "api/"), ("pricing", "price", "preco", "preço")),
)

DESKTOP_LOCK_PATTERNS = (
    re.compile(r"""['"]desktop_(?:open|click|type)['"]\s*:"""),
    re.compile(r"""MUTATING_RUNTIME_ACTIONS.*['"]desktop_"""),
    re.compile(r"""_assert_runtime_mutation\(\s*['"]desktop_"""),
    re.compile(r"""runtime_lock\.assert_runtime_lock\(.*desktop_"""),
    re.compile(r"""assert_runtime_lock\(.*['"]desktop_"""),
    re.compile(r"""desktop_(?:open|click|type).*runtime_lease_id"""),
    re.compile(r"""desktop_(?:open|click|type).*runtime_fencing_token"""),
    re.compile(r"""(?:desktop_(?:open|click|type).*runtime_lock_required|runtime_lock_required.*desktop_(?:open|click|type))"""),
)


def is_desktop_gui_lock_restoration(f: dict) -> bool:
    """Detect any attempt to re-introduce desktop GUI locks or restrictions."""
    if not isinstance(f, dict):
        return False
    patch = f.get("patch")
    if not isinstance(patch, str):
        return False
    for line in patch.splitlines():
        if line.startswith("+") and not line.startswith("+++"):
            added_line = line[1:].strip()
            for pattern in DESKTOP_LOCK_PATTERNS:
                if pattern.search(added_line):
                    return True
    return False


def requires_desktop_lock_dual_approval(files: list[dict]) -> bool:
    for f in files:
        if isinstance(f, dict) and is_desktop_gui_lock_restoration(f):
            return True
    return False


def is_protected_path(path: str) -> bool:
    name = path.rsplit("/", 1)[-1].lower()
    return any(path.startswith(dirs) and any(term in name for term in terms)
               for dirs, terms in OWNER_SENSITIVE_RULES)


def verify_approvals(reviews: list[dict], required_users: set[str] | frozenset[str], head_sha: str) -> tuple[bool, str]:
    if not isinstance(reviews, list) or len(reviews) >= 100:
        return False, "owner_reviews_unavailable_or_incomplete"
    approved_users: set[str] = set()
    for user_login in required_users:
        user_reviews = []
        for review in reviews:
            if not isinstance(review, dict):
                return False, "review_shape_invalid"
            user = review.get("user")
            if not isinstance(user, dict):
                return False, "review_actor_missing"
            if str(user.get("login", "")).casefold() == user_login.casefold():
                if review.get("state") in ("APPROVED", "CHANGES_REQUESTED", "DISMISSED"):
                    user_reviews.append(review)
        if not user_reviews:
            continue
        if any(not isinstance(r.get("submitted_at"), str) or not r["submitted_at"] for r in user_reviews):
            return False, "owner_review_timestamp_missing"
        latest = max(user_reviews, key=lambda r: r["submitted_at"])
        if latest.get("state") != "APPROVED":
            return False, "latest_owner_review_not_approved" if len(required_users) == 1 else f"latest_review_by_{user_login}_not_approved"
        if latest.get("commit_id") != head_sha:
            return False, "approval_not_bound_to_current_head" if len(required_users) == 1 else f"approval_by_{user_login}_not_bound_to_current_head"
        approved_users.add(user_login.casefold())

    missing = {u for u in required_users if u.casefold() not in approved_users}
    if missing:
        if len(missing) == len(required_users):
            return False, "dual_owner_approval_missing" if len(required_users) > 1 else "owner_approval_missing"
        return False, f"dual_approval_missing_{sorted(missing)[0]}"
    return True, "dual_owner_review_approved_exact_head" if len(required_users) > 1 else "owner_review_approved_exact_head"


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

    # Priority Check: Does this PR attempt to re-introduce desktop GUI locks?
    if requires_desktop_lock_dual_approval(files):
        return verify_approvals(reviews, DUAL_REQUIRED_REVIEWERS, head_sha)

    # Standard Check: VM deletion or price changes require shopvivaliz-human
    protected = sorted({f["filename"] for f in files if is_protected_path(f["filename"])})
    if not protected:
        return True, "non_protected_pr"

    return verify_approvals(reviews, {OWNER}, head_sha)


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
        if not isinstance(files, list) or len(files) >= 100:
            raise ValueError("pr_files_unavailable_or_incomplete")
        protected = (any(is_protected_path(f.get("filename", "")) for f in files if isinstance(f, dict))
                     or requires_desktop_lock_dual_approval(files))
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
