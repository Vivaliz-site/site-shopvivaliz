#!/usr/bin/env python3
"""Activate GitHub native merge queue only after canonical exact P2 owner grant.

No branch rule mutation before a human-merged, exact SHA PR grant.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

REPO = "Vivaliz-site/site-shopvivaliz"
RULESET_ID = 24523478
RULESET_NAME = "Audit V5 - required PR and verified checks"
HUMAN = "shopvivaliz-human"
OPERATOR = "fredmourao-ai"
RESOURCE = "github:site-shopvivaliz:merge_queue"
CAPABILITY = "github_ruleset_manage"
OPERATION = "activate_native_merge_queue"
REQUIRED = {"Audit repository automation": 15368, "validation-gate": 15368}
SHA40 = re.compile(r"^[0-9a-f]{40}$")


def gh(*args: str):
    env = dict(os.environ)
    for name in ("GH_TOKEN", "GITHUB_TOKEN", "GIT_ASKPASS", "SSH_ASKPASS"):
        env.pop(name, None)
    proc = subprocess.run(("gh", *args), check=True, capture_output=True,
                          text=True, env=env, timeout=45)
    return json.loads(proc.stdout)


EXPECTED_QUEUE_PARAMS = {
    "check_response_timeout_minutes": 60,
    "grouping_strategy": "ALLGREEN",
    "max_entries_to_build": 1,
    "max_entries_to_merge": 1,
    "merge_method": "SQUASH",
    "min_entries_to_merge": 1,
    "min_entries_to_merge_wait_minutes": 0,
}


def verified_existing_queue(ruleset: object) -> bool:
    """No new grant is needed to observe an already-enforced safe ruleset."""
    if not isinstance(ruleset, dict):
        return False
    if (ruleset.get("id") != RULESET_ID or
            ruleset.get("name") != RULESET_NAME or
            ruleset.get("target") != "branch" or
            ruleset.get("enforcement") != "active" or
            ruleset.get("source_type") != "Repository" or
            ruleset.get("bypass_actors") != [] or
            ruleset.get("conditions") != {
                "ref_name": {"include": ["~DEFAULT_BRANCH"], "exclude": []}}):
        return False
    rules = ruleset.get("rules")
    if not isinstance(rules, list) or len(rules) != 5:
        return False
    by_type = {r.get("type"): r for r in rules if isinstance(r, dict)}
    if set(by_type) != {
        "deletion", "non_fast_forward", "pull_request",
        "required_status_checks", "merge_queue"
    }:
        return False
    checks = by_type["required_status_checks"].get("parameters", {})
    current = checks.get("required_status_checks")
    if (checks.get("strict_required_status_checks_policy") is not True or
            not isinstance(current, list) or len(current) != 2 or
            {r.get("context"): r.get("integration_id") for r in current
             if isinstance(r, dict)} != REQUIRED):
        return False
    params = by_type["pull_request"].get("parameters", {})
    if (params.get("dismiss_stale_reviews_on_push") is not True or
            params.get("required_approving_review_count") != 0 or
            params.get("required_review_thread_resolution") is not True or
            params.get("require_extra_approval_for_unattributed_changes") is not True):
        return False
    return by_type["merge_queue"].get("parameters") == EXPECTED_QUEUE_PARAMS


def mutation_plan(ruleset: object) -> dict:
    if not isinstance(ruleset, dict):
        raise ValueError("invalid_ruleset")
    if (ruleset.get("id") != RULESET_ID or ruleset.get("name") != RULESET_NAME
            or ruleset.get("enforcement") != "active"
            or ruleset.get("target") != "branch"
            or ruleset.get("source_type") != "Repository"
            or ruleset.get("bypass_actors") != []
            or ruleset.get("conditions") != {"ref_name": {"include": ["~DEFAULT_BRANCH"], "exclude": []}}):
        raise ValueError("unexpected_ruleset_security_boundary")
    rules = ruleset.get("rules")
    if not isinstance(rules, list):
        raise ValueError("rules_unavailable")
    types = [r.get("type") for r in rules if isinstance(r, dict)]
    if len(types) != len(rules) or set(types) - {"deletion", "non_fast_forward", "pull_request", "required_status_checks", "merge_queue"}:
        raise ValueError("unexpected_rule_type")
    if types.count("required_status_checks") != 1 or types.count("pull_request") != 1:
        raise ValueError("missing_exact_rules")
    if "merge_queue" in types:
        raise ValueError("merge_queue_already_active")
    if set(types) != {"deletion", "non_fast_forward", "pull_request", "required_status_checks"}:
        raise ValueError("missing_protection")
    mapped = {r["type"]: r for r in rules}
    checks = mapped["required_status_checks"].get("parameters", {})
    got_checks = checks.get("required_status_checks")
    if not isinstance(got_checks, list) or len(got_checks) != 2:
        raise ValueError("required_checks_incomplete")
    current = {c.get("context"): c.get("integration_id") for c in got_checks if isinstance(c, dict)}
    if current != REQUIRED or checks.get("strict_required_status_checks_policy") is not True:
        raise ValueError("required_status_checks_changed")
    pull = mapped["pull_request"].get("parameters", {})
    if (pull.get("dismiss_stale_reviews_on_push") is not True
            or pull.get("required_approving_review_count") != 0
            or pull.get("required_review_thread_resolution") is not True):
        raise ValueError("pull_request_governance_changed")
    planned_rules = json.loads(json.dumps(rules))
    # Native queue runs on integration SHA; never downgrade strict=true
    # merely because the rule is being added.
    planned_rules.append({"type": "merge_queue", "parameters": EXPECTED_QUEUE_PARAMS})
    return {"name": RULESET_NAME, "target": "branch", "enforcement": "active",
            "bypass_actors": [], "conditions": ruleset["conditions"], "rules": planned_rules}


def grant_for_merged_pr(sha: str) -> tuple[int, dict] | None:
    if not SHA40.fullmatch(sha):
        raise ValueError("invalid_push_sha")
    prs = gh("api", f"repos/{REPO}/commits/{sha}/pulls?per_page=20")
    if not isinstance(prs, list) or not 0 < len(prs) < 20:
        raise ValueError("missing_merge_pr_proof")
    matches = []
    for summary in prs:
        if not isinstance(summary, dict) or not isinstance(summary.get("number"), int):
            continue
        n = summary["number"]
        grant_path = Path(f"security/owner-approvals/{n}.json")
        if not grant_path.is_file():
            continue
        grant = json.loads(grant_path.read_text(encoding="utf-8"))
        if grant.get("resource") == RESOURCE and grant.get("operation") == OPERATION:
            matches.append((n, grant))
    if not matches:
        return None  # Unrelated owner grant: no queue ruleset mutation.
    if len(matches) != 1:
        raise ValueError("ambiguous_owner_merge_grant")
    return matches[0]


def validate_merged_human_grant(n: int, grant: dict, merge_sha: str) -> None:
    if (grant.get("schema") != "SHOPVIVALIZ_OWNER_PR_GRANT_V1"
            or grant.get("repository") != REPO or grant.get("pr_number") != n
            or grant.get("resource") != RESOURCE
            or grant.get("principal") != OPERATOR
            or grant.get("target_repository") != REPO
            or grant.get("capabilities") != [CAPABILITY]
            or grant.get("operation") != OPERATION):
        raise ValueError("invalid_owner_grant_scope")
    pr = gh("api", f"repos/{REPO}/pulls/{n}")
    if not isinstance(pr, dict) or pr.get("merged") is not True or pr.get("merge_commit_sha") != merge_sha:
        raise ValueError("not_merged_at_expected_sha")
    head = pr.get("head", {}).get("sha", "")
    if not SHA40.fullmatch(head):
        raise ValueError("unknown_head_sha")
    env = dict(os.environ)
    if not env.get("GITHUB_TOKEN") or env.get("SHOPVIVALIZ_SECURITY_OWNER_LOGIN") != HUMAN:
        raise ValueError("missing_readonly_owner_verifier_context")
    command = [sys.executable, "scripts/validate-owner-pr-grant.py",
               "--repository", REPO, "--pr-number", str(n),
               "--merge-sha", merge_sha, "--head-sha", head,
               "--resource", RESOURCE, "--principal", OPERATOR,
               "--capability", CAPABILITY, "--operation", OPERATION,
               "--target-repository", REPO]
    result = subprocess.run(command, env=env, capture_output=True, text=True, timeout=45)
    if result.returncode != 0 or "OWNER_PR_GRANT=AUTHORIZED" not in result.stdout:
        raise ValueError("canonical_owner_pr_grant_not_valid")
    print(f"OWNER_QUEUE_GRANT=VERIFIED pr={n} sha={head}")


def activate() -> int:
    if os.uname().nodename.split(".")[0] != "always-free-arm-1787907847-26":
        raise ValueError("wrong_backend_host")
    if gh("api", "user").get("login") != OPERATOR:
        raise ValueError("wrong_technical_actor")
    current = gh("api", f"repos/{REPO}/rulesets/{RULESET_ID}")
    if any(r.get("type") == "merge_queue" for r in current.get("rules", [])):
        if not verified_existing_queue(current):
            raise ValueError("native_queue_active_but_security_invariants_changed")
        print("OWNER_QUEUE_RULESET=ALREADY_ACTIVE_VERIFIED strict=true no_bypass=true")
        return 0
    sha = os.getenv("GITHUB_SHA", "")
    found = grant_for_merged_pr(sha)
    if found is None:
        print("OWNER_QUEUE_RULESET=NOT_APPLICABLE no_matching_owner_merge_grant")
        return 0
    n, grant = found
    validate_merged_human_grant(n, grant, sha)
    before = gh("api", f"repos/{REPO}/rulesets/{RULESET_ID}")
    if any(r.get("type") == "merge_queue" for r in before.get("rules", [])):
        if not verified_existing_queue(before):
            raise ValueError("concurrent_ruleset_changed_unsafely")
        return 0
    new = mutation_plan(before)
    with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", prefix="shopvivaliz-queue-ruleset-",
                                     suffix=".json", delete=True) as tmp:
        json.dump(new, tmp, separators=(",", ":"))
        tmp.flush()
        gh("api", "--method", "PUT", f"repos/{REPO}/rulesets/{RULESET_ID}",
           "--input", tmp.name)
    actual = gh("api", f"repos/{REPO}/rulesets/{RULESET_ID}")
    types = [r.get("type") for r in actual.get("rules", [])]
    check = next((r for r in actual["rules"] if r.get("type") == "required_status_checks"), None)
    if (types.count("merge_queue") != 1 or check is None or
            {c.get("context"): c.get("integration_id") for c in
             check["parameters"]["required_status_checks"]} != REQUIRED or
            actual.get("bypass_actors") != [] or actual.get("enforcement") != "active"):
        raise ValueError("ruleset_write_readback_failed_manual_rollback_needed")
    print(f"OWNER_QUEUE_RULESET=ACTIVE id={RULESET_ID} required_checks_preserved=true")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(activate())
    except (OSError, ValueError, KeyError, subprocess.CalledProcessError,
            subprocess.TimeoutExpired) as exc:
        print("OWNER_QUEUE_RULESET=NOT_CHANGED_OR_UNVERIFIED type=" + type(exc).__name__)
        sys.exit(2)
