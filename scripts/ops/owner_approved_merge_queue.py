#!/usr/bin/env python3
"""Enqueue exact owner-approved protected PRs via GitHub native merge queue.

Run exclusively from trusted main on the authorized backend technical principal.
No PR branch edits, ruleset changes, bot approvals, direct merges or --admin.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
from datetime import datetime
from typing import Any

REPOSITORY = "Vivaliz-site/site-shopvivaliz"
HUMAN = "shopvivaliz-human"
OPERATOR = "fredmourao-ai"
QUEUE_ACTOR = "github-merge-queue[bot]"
REQUIRED = frozenset({"Audit repository automation", "validation-gate"})
PROTECTED_PREFIXES = ("security/owner-approvals/", "security/requests/")
PROTECTED_FILES = {
    ".github/CODEOWNERS",
    "docs/knowledge/security-approval-via-pr.md",
    "scripts/validate-owner-pr-grant.py",
    ".github/workflows/mandatory-validation-gate.yml",
    "scripts/quality/verify_owner_protected_pr_review.py",
    ".github/workflows/pr-owner-approval-merge-queue.yml",
    "scripts/ops/owner_approved_merge_queue.py",
    "scripts/ops/activate_owner_merge_queue_ruleset.py",
}
SHA40 = re.compile(r"^[0-9a-f]{40}$")


def protected(path: str) -> bool:
    return path in PROTECTED_FILES or any(path.startswith(p) for p in PROTECTED_PREFIXES)


def technical_gh(*args: str) -> Any:
    env = dict(os.environ)
    for name in ("GH_TOKEN", "GITHUB_TOKEN", "GIT_ASKPASS", "SSH_ASKPASS"):
        env.pop(name, None)
    proc = subprocess.run(("gh", *args), check=True, capture_output=True, text=True,
                          env=env, timeout=50)
    return json.loads(proc.stdout)


def gh_json(path: str) -> Any:
    return technical_gh("api", path)


def approved_exact_head(reviews: object, sha: str) -> bool:
    if not SHA40.fullmatch(sha) or not isinstance(reviews, list) or not 0 < len(reviews) < 100:
        return False
    owner = []
    for review in reviews:
        if not isinstance(review, dict) or not isinstance(review.get("user"), dict):
            return False
        if review["user"].get("login", "").casefold() == HUMAN:
            if review.get("state") in ("APPROVED", "CHANGES_REQUESTED", "DISMISSED"):
                if not review.get("submitted_at"):
                    return False
                owner.append(review)
    if not owner:
        return False
    last = max(owner, key=lambda r: r["submitted_at"])
    return last.get("state") == "APPROVED" and last.get("commit_id") == sha


def required_green(checks: object) -> bool:
    if not isinstance(checks, list) or len(checks) >= 100:
        return False
    latest = {}
    for check in checks:
        if not isinstance(check, dict):
            return False
        name = check.get("name")
        if name in REQUIRED:
            app = check.get("app") or {}
            if not isinstance(app, dict) or app.get("id") != 15368:
                return False
            if name not in latest or check.get("id", 0) > latest[name].get("id", 0):
                latest[name] = check
    return (set(latest) == REQUIRED and all(
        c.get("status") == "completed" and c.get("conclusion") == "success"
        for c in latest.values()))


def queue_rules_enabled(rules: object) -> bool:
    if not isinstance(rules, list):
        return False
    types = {r.get("type") for r in rules if isinstance(r, dict)}
    contexts = set()
    for rule in rules:
        if not isinstance(rule, dict):
            return False
        if rule.get("type") == "required_status_checks":
            contexts |= {c.get("context") for c in rule.get("parameters", {}).get("required_status_checks", [])
                         if isinstance(c, dict)}
    return "merge_queue" in types and REQUIRED <= contexts and {"pull_request", "non_fast_forward"} <= types


def eligible(pr: object, files: object, reviews: object, checks: object, rules: object) -> tuple[bool, str]:
    if not queue_rules_enabled(rules):
        return False, "native_merge_queue_not_enabled"
    if not isinstance(pr, dict) or pr.get("state") != "open" or pr.get("draft") is not False:
        return False, "pr_not_ready"
    if pr.get("base", {}).get("ref") != "main" or pr.get("head", {}).get("repo", {}).get("full_name") != REPOSITORY:
        return False, "wrong_repository_or_base"
    if not isinstance(files, list) or not 0 < len(files) < 100:
        return False, "files_missing_or_pagination_boundary"
    if any(not isinstance(f, dict) or not isinstance(f.get("filename"), str) for f in files):
        return False, "invalid_file_metadata"
    if not any(protected(f["filename"]) for f in files):
        return False, "technical_pr_not_owner_only"
    creator = pr.get("user")
    if not isinstance(creator, dict) or creator.get("login", "").casefold() == HUMAN:
        return False, "protected_grant_author_must_be_distinct"
    labels = pr.get("labels")
    if not isinstance(labels, list):
        return False, "labels_unavailable"
    if any((not isinstance(l, dict)) for l in labels):
        return False, "labels_unavailable"
    holds = {l.get("name") for l in labels}
    if holds & {"blocked-by-runtime-lease", "blocked-external"}:
        return False, "active_operational_hold"
    # Historical no-auto-merge label blocks pre-approval bots. Under the P2
    # queue policy it does not block an exact human-approved HEAD.
    sha = pr.get("head", {}).get("sha", "")
    if not approved_exact_head(reviews, sha):
        return False, "owner_review_not_approved_on_exact_head"
    if not required_green(checks):
        return False, "official_required_checks_not_green"
    return True, "approved_exact_head_ready_for_native_queue"


def run(limit: int = 30) -> int:
    if os.uname().nodename.split(".")[0] != "always-free-arm-1787907847-26":
        print("OWNER_QUEUE=REFUSED wrong_backend_host")
        return 2
    user = technical_gh("api", "user")
    if user.get("login") != OPERATOR:
        print("OWNER_QUEUE=REFUSED wrong_technical_identity")
        return 2
    rules = gh_json(f"repos/{REPOSITORY}/rules/branches/main")
    if not queue_rules_enabled(rules):
        print("OWNER_QUEUE=INACTIVE native_merge_queue_not_enabled")
        return 0
    prs = gh_json(f"repos/{REPOSITORY}/pulls?state=open&base=main&per_page=100")
    if not isinstance(prs, list) or len(prs) >= 100:
        print("OWNER_QUEUE=REFUSED incomplete_pr_listing")
        return 2
    count = 0
    for brief in prs[:limit]:
        number = brief.get("number") if isinstance(brief, dict) else None
        if not isinstance(number, int) or number <= 0:
            return 2
        pr = gh_json(f"repos/{REPOSITORY}/pulls/{number}")
        files = gh_json(f"repos/{REPOSITORY}/pulls/{number}/files?per_page=100")
        if not isinstance(files, list) or not 0 < len(files) < 100:
            print(f"owner_queue_pr={number} state=fail_closed_files")
            continue
        if not any(protected(f.get("filename", "")) for f in files if isinstance(f, dict)):
            continue
        reviews = gh_json(f"repos/{REPOSITORY}/pulls/{number}/reviews?per_page=100")
        sha = pr.get("head", {}).get("sha", "")
        checks = gh_json(f"repos/{REPOSITORY}/commits/{sha}/check-runs?per_page=100").get("check_runs")
        allow, reason = eligible(pr, files, reviews, checks, rules)
        if not allow:
            print(f"owner_queue_pr={number} state=deferred reason={reason}")
            continue
        node = technical_gh("api", "graphql",
                            "-F", f"number={number}",
                            "-f", "query=query($number:Int!){repository(owner:\"Vivaliz-site\",name:\"site-shopvivaliz\"){pullRequest(number:$number){id headRefOid mergeQueueEntry{id}}}}")
        item = node.get("data", {}).get("repository", {}).get("pullRequest") or {}
        if item.get("headRefOid") != sha or not item.get("id"):
            print(f"owner_queue_pr={number} state=fail_closed_head_race")
            continue
        if item.get("mergeQueueEntry"):
            print(f"owner_queue_pr={number} state=already_in_native_queue")
            continue
        response = technical_gh("api", "graphql",
            "-f", f"prId={item['id']}", "-f", f"head={sha}",
            "-f", "query=mutation($prId:ID!,$head:GitObjectID!){enqueuePullRequest(input:{pullRequestId:$prId,expectedHeadOid:$head}){mergeQueueEntry{id}}}")
        entry = response.get("data", {}).get("enqueuePullRequest", {}).get("mergeQueueEntry") or {}
        if not entry.get("id"):
            raise RuntimeError("GitHub did not confirm native merge queue entry")
        print(f"owner_queue_pr={number} state=enqueued actor={OPERATOR} sha={sha}")
        count += 1
        # One per sweep to avoid crowding a shared self-hosted test runner.
        break
    print(f"OWNER_QUEUE_ENQUEUED={count}")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(run())
    except (OSError, ValueError, KeyError, subprocess.CalledProcessError,
            subprocess.TimeoutExpired, RuntimeError) as exc:
        print("OWNER_QUEUE=DEFERRED external_api_or_runner_unavailable type=" + type(exc).__name__)
        sys.exit(3)
