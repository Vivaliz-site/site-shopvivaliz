#!/usr/bin/env python3
"""Reconcile existing mandatory owner review requests; never create review gates.

Reads only the trusted default-branch source. No PR-controlled code is executed.
Ordinary technical PRs must not be assigned to the human owner for visibility.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path

OWNER = "shopvivaliz-human"
ORG = "Vivaliz-site"
SITE = f"{ORG}/site-shopvivaliz"
GRANT_PREFIX = "security/owner-approvals/"
OWNER_OWNED_SITE = {
    "docs/knowledge/security-approval-via-pr.md",
    "scripts/validate-owner-pr-grant.py",
}
REPO_NAME = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")
SHA = re.compile(r"^[0-9a-f]{40}$")
MAX_PAGES = 30


class GitHubError(Exception):
    pass


class GitHub:
    def __init__(self, token: str):
        if not token:
            raise GitHubError("Token de API ausente")
        self.token = token

    def call(self, method: str, path: str, payload: dict | None = None):
        body = json.dumps(payload).encode() if payload is not None else None
        request = urllib.request.Request(
            "https://api.github.com/" + path.lstrip("/"),
            data=body,
            method=method,
            headers={
                "Accept": "application/vnd.github+json",
                "Authorization": "Bearer " + self.token,
                "X-GitHub-Api-Version": "2022-11-28",
                "User-Agent": "shopvivaliz-protected-review-reconciler",
                **({"Content-Type": "application/json"} if body is not None else {}),
            },
        )
        try:
            with urllib.request.urlopen(request, timeout=20) as response:
                return json.load(response)
        except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError,
                ValueError) as exc:
            # Deliberately never print the URL, response body or access token.
            status = getattr(exc, "code", "network")
            raise GitHubError(f"Falha API GitHub ({status})") from None

    def get(self, path: str):
        return self.call("GET", path)

    def post(self, path: str, payload: dict):
        return self.call("POST", path, payload)

    def pages(self, path: str) -> list[dict]:
        items: list[dict] = []
        for page in range(1, MAX_PAGES + 1):
            joiner = "&" if "?" in path else "?"
            batch = self.get(f"{path}{joiner}per_page=100&page={page}")
            if not isinstance(batch, list):
                raise GitHubError("Pagina GitHub invalida")
            items.extend(batch)
            if len(batch) < 100:
                return items
        raise GitHubError("Paginacao excedeu o limite seguro")


def load_site_policy():
    # This file always comes from the trusted main checkout, never from a PR.
    path = Path(__file__).resolve().with_name("enforce-autonomy-policy.py")
    spec = importlib.util.spec_from_file_location("trusted_autonomy_policy", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("Classificador comercial canonico indisponivel")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def protection_reason(repo: str, files: list[dict], site_policy) -> str | None:
    paths = {str(item.get("filename", "")) for item in files}
    if any(p.startswith(GRANT_PREFIX) for p in paths):
        return "grant_explicito"
    if repo != SITE:
        return None
    if paths & OWNER_OWNED_SITE:
        return "superficie_owner_codeowners"
    for entry in files:
        path = str(entry.get("filename", ""))
        if site_policy.sensitive_path(path):
            return "comercial_caminho_protegido"
        if site_policy.should_scan_content(path):
            patch = entry.get("patch")
            if patch is None:
                # Do not guess whether an oversized diff contains price writes.
                raise GitHubError(f"Diff nao classificavel: {path[:100]}")
            added = [
                line[1:] for line in patch.splitlines()
                if line.startswith("+") and not line.startswith("+++")
            ]
            if site_policy.sensitive_content(added):
                return "comercial_mutacao_existente"
    return None


def owner_approved(reviews: list[dict], head: str) -> bool:
    owner_reviews = [
        review for review in reviews
        if str((review.get("user") or {}).get("login", "")).lower() == OWNER
    ]
    # The latest decision, not any historical APPROVED, is authoritative.
    if not owner_reviews:
        return False
    if not all(type(item.get("id")) is int for item in owner_reviews):
        return False
    latest = max(owner_reviews, key=lambda item: item["id"])
    return latest.get("state") == "APPROVED" and latest.get("commit_id") == head


def has_request(pr: dict) -> bool:
    return any(
        str(item.get("login", "")).lower() == OWNER
        for item in (pr.get("requested_reviewers") or [])
    )


def review_one(client, repo: str, pr: dict, *, policy, dry_run: bool = False) -> dict:
    number = pr.get("number")
    if type(number) is not int or number < 1 or not REPO_NAME.fullmatch(repo):
        raise GitHubError("Identificador de PR/repo invalido")
    label = f"{repo}#{number}"
    if pr.get("state") != "open":
        return {"pr": label, "action": "closed"}
    head = (pr.get("head") or {}).get("sha", "")
    if not SHA.fullmatch(head):
        raise GitHubError("HEAD de PR invalido")
    files = client.pages(f"repos/{repo}/pulls/{number}/files")
    protected = protection_reason(repo, files, policy)
    if protected is None:
        return {"pr": label, "action": "ordinary_no_review_required"}
    # Reload the PR after reading its diff to prevent races with merge/push.
    live = client.get(f"repos/{repo}/pulls/{number}")
    if live.get("state") != "open" or (live.get("head") or {}).get("sha") != head:
        return {"pr": label, "action": "changed_recheck_next_cycle", "reason": protected}
    if live.get("draft"):
        return {"pr": label, "action": "protected_draft_requires_owner_triage", "reason": protected}
    if str((live.get("user") or {}).get("login", "")).lower() == OWNER:
        return {"pr": label, "action": "owner_is_author_manual_triage", "reason": protected}
    if has_request(live):
        return {"pr": label, "action": "already_requested", "reason": protected}
    reviews = client.pages(f"repos/{repo}/pulls/{number}/reviews")
    if owner_approved(reviews, head):
        return {"pr": label, "action": "already_approved_current_head", "reason": protected}
    if dry_run:
        return {"pr": label, "action": "would_request", "reason": protected}
    client.post(
        f"repos/{repo}/pulls/{number}/requested_reviewers",
        {"reviewers": [OWNER]},
    )
    # A concurrent approval can clear the request immediately. Both states OK.
    live = client.get(f"repos/{repo}/pulls/{number}")
    if live.get("state") != "open" or (live.get("head") or {}).get("sha") != head:
        return {"pr": label, "action": "changed_after_request", "reason": protected}
    if has_request(live):
        return {"pr": label, "action": "requested_verified", "reason": protected}
    if owner_approved(client.pages(f"repos/{repo}/pulls/{number}/reviews"), head):
        return {"pr": label, "action": "approved_during_request", "reason": protected}
    raise GitHubError("Pedido nao confirmado no readback da API")


def scan(client, *, repo: str | None, number: int | None,
         policy, dry_run: bool) -> dict:
    if (repo is None) != (number is None):
        raise GitHubError("--repo e --pr devem ser fornecidos juntos")
    if repo is not None:
        if not REPO_NAME.fullmatch(repo) or not repo.startswith(ORG + "/"):
            raise GitHubError("Repositorio fora da organizacao autorizada")
        candidates = [(repo, [client.get(f"repos/{repo}/pulls/{number}")])]
    else:
        repos = client.pages(f"orgs/{ORG}/repos?type=all")
        candidates = []
        for entry in repos:
            full_name = str(entry.get("full_name", ""))
            if not REPO_NAME.fullmatch(full_name) or not full_name.startswith(ORG + "/"):
                continue
            candidates.append(
                (full_name, client.pages(f"repos/{full_name}/pulls?state=open"))
            )
    outcomes = []
    failures = []
    for full_name, pull_requests in candidates:
        for pr in pull_requests:
            try:
                outcomes.append(review_one(
                    client, full_name, pr, policy=policy, dry_run=dry_run
                ))
            except GitHubError as err:
                # Fail visible for follow-up, never broaden the reviewer set.
                failures.append({"pr": f"{full_name}#{pr.get('number')}", "error": str(err)})
    return {
        "scope": repo or ORG,
        "scanned": len(outcomes) + len(failures),
        "requested": sum(v["action"] == "requested_verified" for v in outcomes),
        "errors": failures,
        "results": outcomes,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo")
    parser.add_argument("--pr", type=int)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    try:
        report = scan(
            GitHub(os.getenv("REVIEW_ROUTER_TOKEN", "")),
            repo=args.repo,
            number=args.pr,
            policy=load_site_policy(),
            dry_run=args.dry_run,
        )
    except (GitHubError, RuntimeError) as err:
        print(json.dumps({"ok": False, "error": str(err)}, ensure_ascii=False))
        return 1
    print(json.dumps(report, ensure_ascii=False, sort_keys=True))
    return 1 if report["errors"] else 0


if __name__ == "__main__":
    sys.exit(main())
