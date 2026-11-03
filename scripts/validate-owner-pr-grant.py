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
SECURITY_HUMAN_LOGIN = "shopvivaliz-human"
# Both accounts belong to the repository owner; neither is an agent identity.
SECURITY_OWNER_LOGINS = frozenset({SECURITY_HUMAN_LOGIN, "fredmourao-ai"})
CENTRAL_MARINA_CAPABILITIES = frozenset({"marina_readonly", "marina_admin_command"})


def is_security_owner(login: object) -> bool:
    return isinstance(login, str) and login.casefold() in {x.casefold() for x in SECURITY_OWNER_LOGINS}
SHA40 = re.compile(r"^[0-9a-f]{40}$")
LOGIN = re.compile(r"^[A-Za-z0-9-]{1,39}$")
REPO = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")
OPERATION = re.compile(r"^[a-z][a-z0-9_]{3,63}$")
GITHUB_QUEUE_BOT = "github-merge-queue[bot]"
TRUSTED_QUEUE_ENQUEUERS = frozenset({"shopvivaliz-human", "fredmourao-ai"})



def official_merge_group_proof(proof: dict, merge_sha: str,
                               queued_at: datetime, merged_at: datetime) -> bool:
    """Cross-check technical merge attribution with GitHub's real queue CI.

    An old AddedToMergeQueueEvent alone is insufficient: another actor could
    dequeue a PR and attempt a direct merge. The final integration commit must
    itself carry two successful official GitHub Actions merge_group workflows.
    """
    if not SHA40.fullmatch(merge_sha):
        return False
    # Actual provider readback evidence, not a queue completion flag:
    # the integration SHA must have both official workflow runs AND checks.
    merge_group_readback_evidence = proof.get("merge_group_runs")
    required_check_readback_evidence = proof.get("check_runs")
    runs = merge_group_readback_evidence
    checks = required_check_readback_evidence
    if (not isinstance(runs, list) or not 0 < len(runs) < 100 or
            not isinstance(checks, list) or not 0 < len(checks) < 100):
        return False
    for name in ("Mandatory Validation Gate", "Repository Governance"):
        matched = [r for r in runs if isinstance(r, dict) and
                   r.get("name") == name and r.get("event") == "merge_group" and
                   r.get("head_sha") == merge_sha and
                   isinstance(r.get("id"), int) and r["id"] > 0]
        if not matched:
            return False
        latest = max(matched, key=lambda r: (str(r.get("created_at", "")), r["id"]))
        if latest.get("status") != "completed" or latest.get("conclusion") != "success":
            return False
        try:
            created = datetime.fromisoformat(str(latest["created_at"]).replace("Z", "+00:00"))
        except (KeyError, ValueError, TypeError):
            return False
        if created.tzinfo is None or not queued_at <= created <= merged_at:
            return False
    for name in ("validation-gate", "Audit repository automation"):
        matching = [r for r in checks if isinstance(r, dict) and
                    r.get("name") == name and isinstance(r.get("id"), int) and
                    r["id"] > 0]
        if not matching:
            return False
        latest = max(matching, key=lambda r: r["id"])
        app = latest.get("app")
        if (latest.get("status") != "completed" or
                latest.get("conclusion") != "success" or
                not isinstance(app, dict) or app.get("id") != 15368):
            return False
    return True


def verified_native_merge_queue(pr: object, reviews: object, queue_proof: object,
                                repository: str, pr_number: int,
                                head_sha: str, merge_sha: str) -> bool:
    """Validate provider-signed queue timeline, not a bot label in PR text.

    Human review must precede the actual native enqueue operation, remain
    bound to the exact HEAD and precede the provider-enforced queue merge.
    """
    if not isinstance(pr, dict) or not isinstance(queue_proof, dict) or not isinstance(reviews, list):
        return False
    if (queue_proof.get("repository") != repository or
            queue_proof.get("number") != pr_number or
            pr.get("merged_by", {}).get("login") not in
                (GITHUB_QUEUE_BOT, "fredmourao-ai") or
            pr.get("head", {}).get("sha") != head_sha):
        return False
    events = queue_proof.get("events")
    if not isinstance(events, list) or not 0 < len(events) < 100:
        return False
    human_approval = None
    for review in reviews:
        if not isinstance(review, dict) or not isinstance(review.get("user"), dict):
            return False
        if (str(review["user"].get("login", "")).casefold() == SECURITY_HUMAN_LOGIN and
                review.get("state") in ("APPROVED", "CHANGES_REQUESTED", "DISMISSED")):
            if not review.get("submitted_at"):
                return False
            if human_approval is None or review["submitted_at"] > human_approval.get("submitted_at", ""):
                human_approval = review
    if (human_approval is None or human_approval.get("state") != "APPROVED" or
            human_approval.get("commit_id") != head_sha):
        return False
    try:
        approved_at = datetime.fromisoformat(human_approval["submitted_at"].replace("Z", "+00:00"))
        merged_at = datetime.fromisoformat(str(pr["merged_at"]).replace("Z", "+00:00"))
    except (ValueError, KeyError, TypeError):
        return False
    if approved_at.tzinfo is None or merged_at.tzinfo is None or approved_at > merged_at:
        return False
    for event in events:
        if not isinstance(event, dict):
            return False
        actor = event.get("enqueuer")
        related = event.get("pullRequest")
        if not isinstance(actor, dict) or not isinstance(related, dict):
            return False
        if (str(actor.get("login", "")).casefold() not in TRUSTED_QUEUE_ENQUEUERS or
                related.get("number") != pr_number or
                related.get("headRefOid") != head_sha):
            continue
        try:
            queued_at = datetime.fromisoformat(str(event["createdAt"]).replace("Z", "+00:00"))
        except (KeyError, ValueError, TypeError):
            return False
        if queued_at.tzinfo is not None and approved_at <= queued_at <= merged_at:
            merger = pr["merged_by"]["login"]
            if merger == GITHUB_QUEUE_BOT:
                return True
            if (repository != "Vivaliz-site/site-shopvivaliz" or
                    str(actor.get("login", "")).casefold() != merger.casefold()):
                continue
            return official_merge_group_proof(queue_proof, merge_sha, queued_at, merged_at)
    return False


def decide(pr: object, grant: object, *, repository: str, pr_number: int,
           owner_login: str, merge_sha: str, head_sha: str, resource: str,
           principal: str, capability: str, operation: str | None = None, target_repository: str | None = None, reviews: object = None,
           queue_proof: object = None, now: datetime | None = None) -> tuple[bool, str]:
    """Require authenticated owner merger and exact immutable scope match."""
    if not (isinstance(pr, dict) and isinstance(grant, dict)):
        return False, "invalid_response_shape"
    if not (REPO.fullmatch(repository) and LOGIN.fullmatch(owner_login) and
            SHA40.fullmatch(merge_sha) and SHA40.fullmatch(head_sha) and
            isinstance(pr_number, int) and pr_number > 0):
        return False, "invalid_trust_parameters"
    if not is_security_owner(owner_login):
        return False, "configured_owner_not_authorized"
    if not resource or not principal or not capability:
        return False, "missing_requested_scope"
    if not (pr.get("merged") is True and pr.get("state") == "closed" and
            pr.get("number") == pr_number):
        return False, "pr_not_merged"
    # Legacy explicit owner merge, or independently attested GitHub native
    # merge queue bot after exact SHA-bound human APPROVED. A technical direct
    # merge without queue provenance remains forbidden.
    merged_by = pr.get("merged_by")
    actor = str(merged_by.get("login", "")) if isinstance(merged_by, dict) else ""
    if actor in (GITHUB_QUEUE_BOT, "fredmourao-ai"):
        # Preserve legacy explicit-denial diagnostics for a direct agent
        # merge without native queue provenance.
        if actor == "fredmourao-ai" and queue_proof is None:
            return False, "merge_actor_not_owner"
        if not verified_native_merge_queue(pr, reviews, queue_proof,
                                           repository, pr_number, head_sha, merge_sha):
            return False, "github_queue_owner_provenance_not_verified"
    else:
        if "auto_merge" not in pr:
            return False, "owner_merge_metadata_missing"
        auto_merge = pr["auto_merge"]
        if auto_merge is not None:
            enabled_by = auto_merge.get("enabled_by") if isinstance(auto_merge, dict) else None
            if (not isinstance(enabled_by, dict) or
                    str(enabled_by.get("login", "")).casefold() != owner_login.casefold() or
                    auto_merge.get("merge_method") not in ("merge", "squash", "rebase")):
                return False, "owner_auto_merge_not_authorized"
        if actor.casefold() != owner_login.casefold():
            return False, "merge_actor_not_owner"
    author = pr.get("user")
    author_login = str(author.get("login", "")) if isinstance(author, dict) else ""
    if not LOGIN.fullmatch(author_login):
        return False, "security_pr_author_must_be_distinct"
    if pr.get("merge_commit_sha") != merge_sha or pr.get("head", {}).get("sha") != head_sha:
        return False, "commit_binding_mismatch"
    # Only the latest security-owner review on the exact PR head may approve.
    # An incomplete first page fails closed; approval cannot be inferred from merge.
    if not isinstance(reviews, list) or not 0 < len(reviews) < 100:
        return False, "security_pr_not_approved_by_human"
    latest_owner_review = None
    for review in reviews:
        if not isinstance(review, dict):
            return False, "security_pr_not_approved_by_human"
        actor = review.get("user")
        reviewer_login = str(actor.get("login", "")) if isinstance(actor, dict) else ""
        if reviewer_login.casefold() != SECURITY_HUMAN_LOGIN:
            continue
        if review.get("state") in ("APPROVED", "CHANGES_REQUESTED", "DISMISSED"):
            latest_owner_review = review
    if (latest_owner_review is None or latest_owner_review.get("state") != "APPROVED"
            or latest_owner_review.get("commit_id") != head_sha):
        return False, "security_pr_not_approved_by_human"
    approver_login = str(latest_owner_review["user"].get("login", ""))
    if approver_login.casefold() == author_login.casefold():
        return False, "security_pr_author_must_be_distinct"
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
    if resource == "mcp:marina" and capability == "deployment_manage" and operation != "olist_browser_restart":
        return False, "privileged_operation_required"
    # A capability alone must not authorize a different privileged operation.
    bound_operation = grant.get("operation")
    if operation is not None:
        if (not isinstance(operation, str) or not OPERATION.fullmatch(operation)
                or bound_operation != operation):
            return False, "operation_scope_not_approved"
    elif bound_operation is not None:
        return False, "operation_binding_required"
    # A central-to-Marina read grant must never inherit general Marina admin
    # permissions, unrelated target repositories, or an unbound operation.
    if resource == "mcp:central:marina":
        if (principal != "shopvivaliz-central-mcp" or
                capability not in CENTRAL_MARINA_CAPABILITIES or
                caps != [capability] or
                operation is not None or target_repository is not None or
                grant.get("operation") is not None or
                grant.get("target_repository") is not None or
                grant.get("host") != "marina-a1-4c24g" or
                grant.get("controller_host") != "always-free-arm-1787907847-26"):
            return False, "central_marina_scope_not_approved"

    # Plugin permission maintenance is a narrowly named, one-use human grant.
    # It NEVER changes ChatGPT provider policy or mints a runtime lock itself.
    if resource == "mcp:fred-win:plugin-permissions":
        exact_fields = {
            "schema", "repository", "pr_number", "resource", "principal",
            "host", "controller_host", "capabilities", "operation",
            "allowed_actions", "target_origin", "permission_mode",
            "one_use", "ttl_seconds", "expires_at_utc",
        }
        if (set(grant) != exact_fields or
                principal != "shopvivaliz-central-mcp" or
                capability != "desktop_plugin_permission_maintenance" or
                caps != ["desktop_plugin_permission_maintenance"] or
                operation != "configure_chatgpt_plugin_permissions" or
                target_repository is not None or
                grant.get("host") != "Fred-Win" or
                grant.get("controller_host") != "always-free-arm-1787907847-26" or
                grant.get("allowed_actions") != ["desktop_open", "desktop_click", "desktop_type"] or
                grant.get("target_origin") != "https://chatgpt.com" or
                grant.get("permission_mode") != "full_access" or
                grant.get("one_use") is not True or
                type(grant.get("ttl_seconds")) is not int or
                grant["ttl_seconds"] != 180):
            return False, "fred_win_plugin_permissions_scope_not_approved"
    if resource == "mcp:marina":
        if (not isinstance(target_repository, str) or
                not REPO.fullmatch(target_repository) or
                grant.get("target_repository") != target_repository):
            return False, "target_repository_not_approved"
    elif target_repository is not None:
        if (not isinstance(target_repository, str) or
                not REPO.fullmatch(target_repository) or
                grant.get("target_repository") != target_repository):
            return False, "target_repository_not_approved"
    elif grant.get("target_repository") is not None:
        return False, "target_repository_binding_required"
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


def github_graphql(query: str, variables: dict, token: str) -> object:
    body = json.dumps({"query": query, "variables": variables}).encode("utf-8")
    request = Request("https://api.github.com/graphql", data=body, headers={
        "Accept": "application/vnd.github+json",
        "Authorization": "Bearer " + token,
        "X-GitHub-Api-Version": "2022-11-28",
        "Content-Type": "application/json",
        "User-Agent": "shopvivaliz-owner-pr-authorization",
    })
    with urlopen(request, timeout=15) as response:
        document = json.load(response)
    if not isinstance(document, dict) or document.get("errors"):
        raise ValueError("queue_provenance_graphql_error")
    return document


def github_queue_proof(repository: str, number: int, token: str) -> dict:
    owner, name = repository.split("/", 1)
    query = """
    query($owner:String!,$name:String!,$number:Int!){
      repository(owner:$owner,name:$name){
        pullRequest(number:$number){
          timelineItems(last:100,itemTypes:[ADDED_TO_MERGE_QUEUE_EVENT]){
            nodes{... on AddedToMergeQueueEvent{
              createdAt
              enqueuer{login}
              pullRequest{number headRefOid}
            }}
          }
        }
      }
    }
    """
    body = github_graphql(query, {"owner": owner, "name": name, "number": number}, token)
    items = (body.get("data") or {}).get("repository")
    items = (items or {}).get("pullRequest")
    items = (items or {}).get("timelineItems")
    if not isinstance(items, dict) or not isinstance(items.get("nodes"), list):
        raise ValueError("queue_provenance_missing")
    return {"repository": repository, "number": number, "events": items["nodes"]}


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--repository", required=True)
    p.add_argument("--pr-number", required=True, type=int)
    p.add_argument("--merge-sha", required=True)
    p.add_argument("--head-sha", required=True)
    p.add_argument("--resource", required=True)
    p.add_argument("--principal", required=True)
    p.add_argument("--capability", required=True)
    p.add_argument("--operation", default=None, help="Exact privileged operation bound to this grant")
    p.add_argument("--target-repository", default=None, help="Exact project repository bound to this grant")
    args = p.parse_args()
    owner = os.environ.get("SHOPVIVALIZ_SECURITY_OWNER_LOGIN", "").strip()
    token = os.environ.get("GITHUB_TOKEN", "").strip()
    if not owner or not token or not REPO.fullmatch(args.repository) or args.pr_number <= 0:
        print("OWNER_PR_GRANT=DENIED reason=missing_trusted_configuration")
        return 1
    if not is_security_owner(owner):
        print("OWNER_PR_GRANT=DENIED reason=configured_owner_not_authorized")
        return 1
    path = "security/owner-approvals/" + str(args.pr_number) + ".json"
    repo = quote(args.repository, safe="/")
    try:
        pr = github_json(f"/repos/{repo}/pulls/{args.pr_number}", token)
        reviews = github_json(f"/repos/{repo}/pulls/{args.pr_number}/reviews?per_page=100", token)
        encoded = github_json(
            f"/repos/{repo}/contents/{path}?ref={quote(args.merge_sha, safe='')}", token)
        if not isinstance(encoded, dict) or encoded.get("encoding") != "base64":
            raise ValueError("unsupported_contents_encoding")
        current = github_json(f"/repos/{repo}/contents/{path}?ref=main", token)
        if not immutable_grant_still_active(encoded, current):
            raise ValueError("authorization_revoked_or_replaced")
        merger = (pr.get("merged_by") or {}).get("login") if isinstance(pr, dict) else None
        if merger in (GITHUB_QUEUE_BOT, "fredmourao-ai"):
            queue_proof = github_queue_proof(args.repository, args.pr_number, token)
            if merger == "fredmourao-ai":
                if args.repository != "Vivaliz-site/site-shopvivaliz":
                    raise ValueError("technical_queue_grant_repository_not_supported")
                runs = github_json(
                    f"/repos/{repo}/actions/runs?head_sha={args.merge_sha}&event=merge_group&per_page=100",
                    token)
                check_data = github_json(
                    f"/repos/{repo}/commits/{args.merge_sha}/check-runs?per_page=100", token)
                if (not isinstance(runs, dict) or
                        not isinstance(runs.get("total_count"), int) or
                        runs["total_count"] >= 100 or
                        not isinstance(check_data, dict) or
                        not isinstance(check_data.get("total_count"), int) or
                        check_data["total_count"] >= 100):
                    raise ValueError("merge_group_provenance_page_incomplete")
                queue_proof["merge_group_runs"] = runs.get("workflow_runs")
                queue_proof["check_runs"] = check_data.get("check_runs")
        else:
            queue_proof = None
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
                                capability=args.capability, operation=args.operation, target_repository=args.target_repository,
                                reviews=reviews, queue_proof=queue_proof)
    except Exception:
        ok, reason = False, "verification_unavailable"
    print(f"OWNER_PR_GRANT={'AUTHORIZED' if ok else 'DENIED'} reason={reason}")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
