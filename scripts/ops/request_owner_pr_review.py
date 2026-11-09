#!/usr/bin/env python3
"""Request the real owner reviewer for trusted owner-protected PRs only.

Run from protected main on the existing self-hosted runner; never execute PR code.
This requests a review, not an approval, a merge, or a grant.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
from pathlib import Path
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))
from scripts.ops.owner_approved_merge_queue import protected
from scripts.quality.verify_owner_protected_pr_review import (
    is_protected_path,
    requires_dual_approval,
    requires_lock_dual_approval,
    requires_desktop_lock_dual_approval,
)

REPO = "Vivaliz-site/site-shopvivaliz"
HUMAN = "shopvivaliz-human"
OPERATOR = "fredmourao-ai"
SHA40 = re.compile(r"[0-9a-f]{40}\Z")
OWNER_LABELS = frozenset({"owner-review-required", "owner-approval-required", "commercial-owner-approval"})
POLICY_DOCS = frozenset({"AGENTS.md", "CLAUDE.md", "docs/knowledge/agent-rules.md", "docs/knowledge/host-access.md"})
OWNER_DIRECTIVE_MARKERS = ("OWNER_DIRECTIVES_", "OWNER_NO_UNAPPROVED_RESTRICTIONS_",
                           "SOLO_OWNER_PROPORTIONAL_GOVERNANCE_V1", "PR_HUMAN_VISIBILITY_ROUTING_V1",
                           "PROTECTED_OWNER_REVIEW_REQUEST_VISIBILITY_V1",
                           "OWNER_ONE_CLICK_MERGE_QUEUE_POLICY_P2",
                           "DESKTOP_GUI_LOCK_PROHIBITION_",
                           "ALL_LOCK_INSERTION_DUAL_APPROVAL_")


def requires_owner(files: object, labels: object) -> bool:
    """Only new restrictions or independently protected owner grants need review."""
    if not isinstance(files, list) or not files or len(files) >= 3000:
        raise ValueError("changed_files_incomplete")
    if not isinstance(labels, list):
        raise ValueError("labels_unavailable")
    if any(not isinstance(label, dict) or not isinstance(label.get("name"), str) for label in labels):
        raise ValueError("labels_invalid")
    if any(label["name"].casefold() in OWNER_LABELS for label in labels):
        return True
    if requires_dual_approval(files):
        return True
    for f in files:
        if not isinstance(f, dict) or not isinstance(f.get("filename"), str):
            raise ValueError("changed_files_invalid")
        name = f["filename"]
        if protected(name) or is_protected_path(name):
            return True

    return False


def decision(pr: dict, files: list, reviews: list, expected_sha: str) -> str:
    if (pr.get("state") != "open" or pr.get("head", {}).get("sha") != expected_sha
            or not SHA40.fullmatch(expected_sha)
            or pr.get("base", {}).get("ref") != "main"
            or pr.get("head", {}).get("repo", {}).get("full_name") != REPO):
        raise ValueError("untrusted_or_changed_pr")
    if not requires_owner(files, pr.get("labels")):
        return "common_pr_no_owner_review"
    if pr.get("draft") is not False:
        return "protected_pr_must_be_ready"
    requested = pr.get("requested_reviewers")
    if not isinstance(requested, list):
        raise ValueError("review_requests_unavailable")
    requested_logins = {
        r.get("login", "").casefold()
        for r in requested
        if isinstance(r, dict) and isinstance(r.get("login"), str)
    }

    author = pr.get("user", {}).get("login", "") if isinstance(pr.get("user"), dict) else ""
    dual_required = requires_dual_approval(files)
    needed_reviewers = {HUMAN, OPERATOR} if dual_required else {HUMAN}
    if dual_required and (not author or author.casefold() in needed_reviewers):
        return "protected_pr_requires_distinct_author"
    if not dual_required and author and author.casefold() in needed_reviewers:
        needed_reviewers = {u for u in needed_reviewers if u.casefold() != author.casefold()}

    if needed_reviewers <= requested_logins:
        return "owner_already_requested"

    if not isinstance(reviews, list) or len(reviews) >= 100:
        raise ValueError("review_history_unavailable_or_incomplete")

    reviewed_on_head = set()
    for user in needed_reviewers:
        user_reviews = [
            r for r in reviews
            if isinstance(r, dict)
            and isinstance(r.get("user"), dict)
            and r["user"].get("login", "").casefold() == user.casefold()
            and r.get("state") in {"APPROVED", "CHANGES_REQUESTED", "DISMISSED"}
        ]
        if user_reviews:
            if any(not isinstance(r.get("submitted_at"), str) for r in user_reviews):
                raise ValueError("review_timestamp_missing")
            latest = max(user_reviews, key=lambda r: r["submitted_at"])
            if latest.get("commit_id") == expected_sha and latest.get("state") in {"APPROVED", "CHANGES_REQUESTED"}:
                reviewed_on_head.add(user.casefold())

    if needed_reviewers <= (requested_logins | reviewed_on_head):
        if needed_reviewers <= reviewed_on_head:
            return "owner_already_reviewed_current_head"
        return "owner_already_requested"

    return "request_owner_review"


def github(path: str, token: str, *, method: str = "GET", data: object = None) -> object:
    body = json.dumps(data).encode("utf-8") if data is not None else None
    request = Request("https://api.github.com/repos/" + REPO + path, data=body, method=method,
                      headers={"Authorization": "Bearer " + token,
                               "Accept": "application/vnd.github+json",
                               "X-GitHub-Api-Version": "2022-11-28",
                               "Content-Type": "application/json",
                               "User-Agent": "shopvivaliz-owner-review-router"})
    with urlopen(request, timeout=15) as response:
        content = response.read(2_000_001)
    if len(content) > 2_000_000:
        raise ValueError("github_response_too_large")
    return json.loads(content)


def files_for_pr(number: int, token: str) -> list:
    files = []
    for page in range(1, 31):
        items = github(f"/pulls/{number}/files?per_page=100&page={page}", token)
        if not isinstance(items, list):
            raise ValueError("changed_files_invalid")
        files.extend(items)
        if len(items) < 100:
            return files
    raise ValueError("changed_files_pagination_incomplete")


def run(number: int, expected_sha: str, token: str) -> int:
    if number < 1 or not SHA40.fullmatch(expected_sha) or len(token) < 10:
        print("OWNER_REVIEW_REQUEST=ERROR invalid_arguments")
        return 2
    try:
        pr = github(f"/pulls/{number}", token)
        files = files_for_pr(number, token)
        if not isinstance(pr, dict):
            raise ValueError("pr_response_invalid")
        # Only query review history for protected PRs. Common PRs stay invisible to the reviewer inbox.
        owner_required = requires_owner(files, pr.get("labels"))
        reviews = github(f"/pulls/{number}/reviews?per_page=100", token) if owner_required else []
        status = decision(pr, files, reviews, expected_sha)
        if status in {"protected_pr_must_be_ready", "protected_pr_requires_distinct_author"}:
            print("OWNER_REVIEW_REQUEST=PENDING " + status)
            return 3
        if status != "request_owner_review":
            print("OWNER_REVIEW_REQUEST=SKIPPED reason=" + status)
            return 0
        # Prevent an event with an obsolete SHA from requesting a new review after a concurrent push.
        current = github(f"/pulls/{number}", token)
        if current.get("head", {}).get("sha") != expected_sha or current.get("state") != "open":
            raise ValueError("pr_changed_before_request")
        author = current.get("user", {}).get("login", "") if isinstance(current.get("user"), dict) else ""
        raw_reviewers = [HUMAN, OPERATOR] if requires_dual_approval(files) else [HUMAN]
        if requires_dual_approval(files) and author.casefold() in {HUMAN, OPERATOR}:
            raise ValueError("protected_pr_requires_distinct_author")
        reviewers_to_request = [u for u in raw_reviewers if u.casefold() != author.casefold()]
        already_requested = {
            r.get("login", "").casefold()
            for r in current.get("requested_reviewers", [])
            if isinstance(r, dict)
        }
        to_request = [u for u in reviewers_to_request if u.casefold() not in already_requested]
        if to_request:
            github(f"/pulls/{number}/requested_reviewers", token, method="POST", data={"reviewers": to_request})
            readback = github(f"/pulls/{number}", token)
            if readback.get("head", {}).get("sha") != expected_sha:
                raise ValueError("pr_changed_after_request")
            new_requested = {
                r.get("login", "").casefold()
                for r in readback.get("requested_reviewers", [])
                if isinstance(r, dict)
            }
            if not all(u.casefold() in new_requested for u in to_request):
                raise ValueError("reviewer_request_not_persisted")
        if len(reviewers_to_request) == 1:
            print("OWNER_REVIEW_REQUEST=SUCCESS reviewer=" + HUMAN)
        else:
            print("OWNER_REVIEW_REQUEST=SUCCESS reviewers=" + ",".join(sorted(reviewers_to_request)))
        return 0
    except (OSError, ValueError, KeyError, TypeError, json.JSONDecodeError) as exc:
        # A real 401/403/422 terminates this attempt; never change identity to bypass it.
        print("OWNER_REVIEW_REQUEST=ERROR kind=" + type(exc).__name__)
        return 2


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--pr", type=int, required=True)
    parser.add_argument("--expected-head", required=True)
    args = parser.parse_args()
    if os.getenv("GITHUB_REPOSITORY") != REPO:
        print("OWNER_REVIEW_REQUEST=ERROR repository_mismatch")
        return 2
    return run(args.pr, args.expected_head, os.getenv("GITHUB_TOKEN", ""))


if __name__ == "__main__":
    raise SystemExit(main())
