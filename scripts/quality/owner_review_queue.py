#!/usr/bin/env python3
"""Queue human-approved protected PRs without rewriting approved HEADs.

Requires GitHub merge_queue on main. Uses technical CLI account only to enqueue
and never uses the Actions GITHUB_TOKEN to mutate GitHub.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys

REPO = "Vivaliz-site/site-shopvivaliz"
OWNER_LOGIN = "shopvivaliz-human"
OPERATOR_LOGIN = "fredmourao-ai"
SHA40 = re.compile(r"^[0-9a-f]{40}$")
REQUIRED_WORKFLOWS = ("Mandatory Validation Gate", "Repository Governance")
PROTECTED_EXACT = frozenset((
    ".github/CODEOWNERS", "docs/knowledge/security-approval-via-pr.md",
    "scripts/validate-owner-pr-grant.py",
    ".github/workflows/owner-human-review-autoqueue.yml",
    ".github/workflows/owner-review-merge-queue-enable.yml",
    ".github/workflows/repository-governance.yml",
    "config/owner-review-merge-queue.json",
    "scripts/quality/owner_review_queue.py",
    "scripts/quality/enable_owner_merge_queue.py",
))
PROTECTED_PREFIXES = ("security/owner-approvals/", "security/requests/")
EXPLICIT_HOLDS = frozenset(("blocked-by-runtime-lease", "blocked-external"))


def protected_path(path: str) -> bool:
    return path in PROTECTED_EXACT or path.startswith(PROTECTED_PREFIXES)


def decision(pr: dict, reviews: list, files: list, rules: list,
             workflow_runs: list, queued: bool = False) -> tuple[bool, str]:
    if (not isinstance(pr, dict) or pr.get("state") != "open"
            or pr.get("draft") is not False
            or pr.get("base", {}).get("ref") != "main"
            or pr.get("base", {}).get("repo", {}).get("full_name") != REPO
            or pr.get("head", {}).get("repo", {}).get("full_name") != REPO):
        return False, "not_eligible_same_repository_ready_main_pr"
    head = pr["head"].get("sha")
    if not isinstance(head, str) or not SHA40.fullmatch(head):
        return False, "invalid_head"
    if (not isinstance(files, list) or not 0 < len(files) < 100
            or not all(isinstance(f, dict) and isinstance(f.get("filename"), str) for f in files)):
        return False, "changed_files_incomplete"
    if not any(protected_path(f["filename"]) for f in files):
        return False, "technical_pr_not_owner_queue"
    labels = [l.get("name") for l in pr.get("labels", []) if isinstance(l, dict)]
    if EXPLICIT_HOLDS.intersection(labels):
        return False, "runtime_hold"
    if not isinstance(reviews, list) or not 0 < len(reviews) < 100:
        return False, "human_review_missing_or_incomplete"
    owner_reviews = []
    for review in reviews:
        if not isinstance(review, dict) or not isinstance(review.get("user"), dict):
            return False, "review_api_invalid"
        if (review["user"].get("login") == OWNER_LOGIN and
                review.get("state") in ("APPROVED", "CHANGES_REQUESTED", "DISMISSED")):
            if not isinstance(review.get("submitted_at"), str) or not review["submitted_at"]:
                return False, "human_review_timestamp_missing"
            owner_reviews.append(review)
    last = max(owner_reviews, key=lambda r: r["submitted_at"]) if owner_reviews else None
    if not last or last.get("state") != "APPROVED" or last.get("commit_id") != head:
        return False, "human_approval_not_on_current_head"
    if not isinstance(rules, list) or not any(
            isinstance(r, dict) and r.get("type") == "merge_queue" for r in rules):
        return False, "merge_queue_not_enabled"
    if queued:
        return False, "already_in_merge_queue"
    if not isinstance(workflow_runs, list):
        return False, "workflow_data_unavailable"
    for name in REQUIRED_WORKFLOWS:
        matching = [run for run in workflow_runs
                    if isinstance(run, dict) and run.get("name") == name
                    and run.get("head_sha") == head]
        if not matching:
            return False, "required_workflow_missing:" + name
        latest = max(matching, key=lambda r: (r.get("created_at", ""),
                                               int(r.get("run_attempt") or 1)))
        # GitHub Actions only exposes conclusion=success for completed runs.
        # Do not confuse an observed workflow status with task completion.
        if latest.get("conclusion") != "success":
            return False, "required_workflow_not_green:" + name
    return True, "approved_head_green_queue_ready"


def technical_env() -> dict:
    env = os.environ.copy()
    for key in ("GH_TOKEN", "GITHUB_TOKEN", "GIT_ASKPASS", "SSH_ASKPASS"):
        env.pop(key, None)
    return env


def github(args: list[str]) -> object:
    result = subprocess.run(["gh", "api", *args], check=True, capture_output=True,
                            text=True, timeout=40, env=technical_env())
    data = json.loads(result.stdout)
    if isinstance(data, dict) and data.get("errors"):
        raise ValueError("graphql_rejected")
    return data


def rest(path: str) -> object:
    return github([path])


def graph_pr(number: int) -> dict:
    query = (
        "query($owner:String!,$name:String!,$number:Int!){"
        "repository(owner:$owner,name:$name){pullRequest(number:$number)"
        "{id mergeQueueEntry{id}}}}"
    )
    data = github(["graphql", "-f", "query=" + query, "-f", "owner=Vivaliz-site",
                   "-f", "name=site-shopvivaliz", "-F", "number=" + str(number)])
    result = data.get("data", {}).get("repository", {}).get("pullRequest")
    if not isinstance(result, dict) or not result.get("id"):
        raise ValueError("pr_graphql_response_missing")
    return result


def enqueue(node_id: str) -> object:
    mutation = (
        "mutation($id:ID!){enqueuePullRequest(input:{pullRequestId:$id})"
        "{clientMutationId}}"
    )
    return github(["graphql", "-f", "query=" + mutation, "-f", "id=" + node_id])


def run_one(number: int, *, expected_head: str = "", dry_run: bool = False) -> str:
    if number <= 0:
        raise ValueError("invalid_pr_number")
    pr = rest(f"repos/{REPO}/pulls/{number}")
    if not isinstance(pr, dict):
        raise ValueError("invalid_pr_response")
    head = pr.get("head", {}).get("sha", "")
    if expected_head and head != expected_head:
        return "skipped:event_head_stale"
    # All data is retrieved from official GitHub endpoints; a missing page
    # fails closed rather than assuming a review or file classification.
    reviews = rest(f"repos/{REPO}/pulls/{number}/reviews?per_page=100")
    files = rest(f"repos/{REPO}/pulls/{number}/files?per_page=100")
    rules = rest(f"repos/{REPO}/rules/branches/main")
    workflows = rest(f"repos/{REPO}/actions/runs?head_sha={head}&per_page=100")
    if not isinstance(workflows, dict) or workflows.get("total_count", 0) > 100:
        return "skipped:workflow_runs_incomplete"
    graph = graph_pr(number)
    ok, reason = decision(pr, reviews, files, rules,
                          workflows.get("workflow_runs", []),
                          queued=graph.get("mergeQueueEntry") is not None)
    if not ok:
        return "skipped:" + reason
    # Compare-and-swap: never enqueue a newer unapproved head.
    if rest(f"repos/{REPO}/pulls/{number}").get("head", {}).get("sha") != head:
        return "skipped:head_moved_before_enqueue"
    if dry_run:
        return "dry_run:eligible"
    enqueue(graph["id"])
    if rest(f"repos/{REPO}/pulls/{number}").get("head", {}).get("sha") != head:
        raise ValueError("post_enqueue_unexpected_head_change")
    return "enqueued:approved_head_unchanged"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pr", type=int)
    parser.add_argument("--expected-head", default="")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    if os.environ.get("GITHUB_REPOSITORY", REPO) != REPO:
        print("OWNER_QUEUE=DENIED repo_mismatch")
        return 2
    if os.uname().nodename.split(".")[0] != "always-free-arm-1787907847-26":
        print("OWNER_QUEUE=DENIED untrusted_host")
        return 2
    if subprocess.run(["id", "-un"], capture_output=True, text=True,
                      check=True).stdout.strip() != "ubuntu":
        print("OWNER_QUEUE=DENIED untrusted_user")
        return 2
    actor = rest("user")
    if not isinstance(actor, dict) or actor.get("login") != OPERATOR_LOGIN:
        print("OWNER_QUEUE=DENIED untrusted_github_identity")
        return 2
    if args.expected_head and not SHA40.fullmatch(args.expected_head):
        return 2
    if args.pr is None:
        prs = rest(f"repos/{REPO}/pulls?state=open&base=main&per_page=100")
        if not isinstance(prs, list) or len(prs) >= 100:
            print("OWNER_QUEUE=DENIED incomplete_open_pr_page")
            return 2
        numbers = [p["number"] for p in prs]
    else:
        numbers = [args.pr]
    errors = 0
    for number in numbers:
        try:
            result = run_one(number, expected_head=args.expected_head,
                             dry_run=args.dry_run)
            print(f"PR_{number}_OWNER_QUEUE={result}")
        except Exception as exc:
            # Fail closed, avoid dumping API responses, credentials, tokens.
            print(f"PR_{number}_OWNER_QUEUE=ERROR:{type(exc).__name__}")
            errors += 1
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
