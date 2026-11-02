#!/usr/bin/env python3
"""One-time, formally owner-reviewed ruleset activation; fail-closed.

Read-only until the specific approval PR was approved on exact HEAD and merged
by the exclusive human owner via GitHub. No authentication secrets in logs.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import sys

REPO = "Vivaliz-site/site-shopvivaliz"
OWNER = "shopvivaliz-human"
TECHNICAL = "fredmourao-ai"
ROOT = Path(__file__).resolve().parents[2]


def env() -> dict:
    result = os.environ.copy()
    for key in ("GH_TOKEN", "GITHUB_TOKEN", "GIT_ASKPASS", "SSH_ASKPASS"):
        result.pop(key, None)
    return result


def api(endpoint: str, payload: dict | None = None) -> dict | list:
    args = ["gh", "api"]
    if payload is not None:
        args.extend(["--method", "PUT", endpoint, "--input", "-"])
    else:
        args.append(endpoint)
    reply = subprocess.run(args, input=json.dumps(payload) if payload else None,
                           capture_output=True, text=True, timeout=45,
                           env=env(), check=True)
    return json.loads(reply.stdout)


def approved_owner_p2(pr: dict, reviews: list, files: list, config: dict) -> bool:
    if (not isinstance(pr, dict) or pr.get("number") != config["approval_pr"]
            or not pr.get("merged") or pr.get("state") != "closed"
            or pr.get("merged_by", {}).get("login") != OWNER
            or pr.get("base", {}).get("ref") != "main"
            or pr.get("head", {}).get("repo", {}).get("full_name") != REPO):
        return False
    head = pr.get("head", {}).get("sha")
    if not isinstance(head, str) or len(head) != 40:
        return False
    if not isinstance(reviews, list) or not 0 < len(reviews) < 100:
        return False
    owner_reviews = [r for r in reviews if
                     isinstance(r, dict) and
                     isinstance(r.get("user"), dict) and
                     r["user"].get("login") == OWNER and
                     r.get("state") in ("APPROVED", "DISMISSED", "CHANGES_REQUESTED")]
    if not owner_reviews or owner_reviews[-1].get("state") != "APPROVED":
        return False
    if owner_reviews[-1].get("commit_id") != head:
        return False
    if not isinstance(files, list) or not 0 < len(files) < 100:
        return False
    if config["approval_request_path"] not in [
            f.get("filename") for f in files if isinstance(f, dict)]:
        return False
    # The main process and the queue review contract must themselves be part
    # of the reviewed change. No generic preexisting owner PR can authorize it.
    must_include = {
        "config/owner-review-merge-queue.json",
        ".github/workflows/owner-human-review-autoqueue.yml",
        ".github/workflows/repository-governance.yml",
        "scripts/validate-owner-pr-grant.py",
    }
    # The mandatory gate merge_group trigger is already on main via
    # preflight PR #3548, not reintroduced in this protected P2 diff.
    # Require only files actually changed by this exact owner-reviewed PR.
    if not must_include.issubset({
            f.get("filename") for f in files if isinstance(f, dict)}):
        return False
    return True


def prepare_update(ruleset: dict, queue_rule: dict) -> dict:
    if (ruleset.get("name") != "Audit V5 - required PR and verified checks"
            or ruleset.get("enforcement") != "active"
            or ruleset.get("target") != "branch"
            or ruleset.get("source_type", "Repository") != "Repository"):
        raise ValueError("ruleset_not_exact_canonical")
    conditions = ruleset.get("conditions", {})
    ref = conditions.get("ref_name", {})
    if "~DEFAULT_BRANCH" not in ref.get("include", []) and \
            "refs/heads/main" not in ref.get("include", []):
        raise ValueError("main_not_in_ruleset_scope")
    current = ruleset.get("rules", [])
    if not isinstance(current, list):
        raise ValueError("invalid_rules")
    required = [r for r in current if r.get("type") == "required_status_checks"]
    if len(required) != 1:
        raise ValueError("required_checks_missing")
    params = required[0].get("parameters", {})
    contexts = {x.get("context") for x in params.get("required_status_checks", [])}
    if contexts != {"Audit repository automation", "validation-gate"}:
        raise ValueError("unexpected_required_checks")
    if params.get("strict_required_status_checks_policy") is not True:
        raise ValueError("strict_required_checks_changed")
    if not any(r.get("type") == "pull_request" for r in current):
        raise ValueError("pull_request_protection_missing")
    existing = [r for r in current if r.get("type") == "merge_queue"]
    if existing:
        if len(existing) == 1 and existing[0] == queue_rule:
            return {}
        raise ValueError("different_merge_queue_configuration_exists")
    return {
        "name": ruleset["name"], "target": "branch",
        "enforcement": ruleset["enforcement"],
        "conditions": conditions,
        "bypass_actors": ruleset.get("bypass_actors", []),
        "rules": current + [queue_rule],
    }


def main() -> int:
    if os.uname().nodename.split(".")[0] != "always-free-arm-1787907847-26":
        print("OWNER_MERGE_QUEUE=DENIED untrusted_host")
        return 2
    if subprocess.run(["id", "-un"], capture_output=True, text=True,
                      check=True).stdout.strip() != "ubuntu":
        print("OWNER_MERGE_QUEUE=DENIED untrusted_user")
        return 2
    config = json.loads((ROOT / "config/owner-review-merge-queue.json").read_text())
    if config.get("repository") != REPO or config.get("ruleset_id") != 24523478 \
            or not isinstance(config.get("approval_pr"), int) \
            or config["approval_pr"] <= 0:
        print("OWNER_MERGE_QUEUE=DENIED invalid_grant_config")
        return 2
    if api("user").get("login") != TECHNICAL:
        print("OWNER_MERGE_QUEUE=DENIED actor_not_technical")
        return 2
    n = config["approval_pr"]
    pr = api(f"repos/{REPO}/pulls/{n}")
    reviews = api(f"repos/{REPO}/pulls/{n}/reviews?per_page=100")
    files = api(f"repos/{REPO}/pulls/{n}/files?per_page=100")
    if not approved_owner_p2(pr, reviews, files, config):
        print("OWNER_MERGE_QUEUE=WAITING protected_pr_review_or_human_merge")
        return 0
    endpoint = f"repos/{REPO}/rulesets/{config['ruleset_id']}"
    current = api(endpoint)
    update = prepare_update(current, config["queue"])
    if not update:
        print("OWNER_MERGE_QUEUE=ACTIVE idempotent")
        return 0
    # Preserve all existing approvals, contexts, bypass restrictions and scope
    # from latest readback. Never remove strict status checks.
    api(endpoint, payload=update)
    after = api(endpoint)
    if not any(r.get("type") == "merge_queue" and
               r == config["queue"] for r in after.get("rules", [])):
        raise ValueError("queue_activation_readback_failed")
    for rule in current["rules"]:
        if rule not in after["rules"]:
            raise ValueError("existing_security_rule_modified")
    print("OWNER_MERGE_QUEUE=ACTIVATED verified_readback")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"OWNER_MERGE_QUEUE=ERROR {type(exc).__name__}")
        sys.exit(1)
