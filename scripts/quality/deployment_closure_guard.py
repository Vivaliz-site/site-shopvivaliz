#!/usr/bin/env python3
"""Read-only, fail-closed deploy verification for ShopVivaliz."""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import urllib.request
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
PUBLIC_VERSION_URL = "https://shopvivaliz.com.br/api/health/version.php?deployment_guard=1"
EVIDENCE_PATH = "deployment/latest.json?ref=deployment-evidence"
ISSUE_TITLE = "[Deploy Closure] Producao nao corresponde ao codigo publicado"
SHA = re.compile(r"^[a-f0-9]{40}$")


class GuardError(RuntimeError):
    pass


def command(*args: str, stdin: str | None = None) -> str:
    try:
        result = subprocess.run(
            args, input=stdin, capture_output=True, text=True, cwd=ROOT,
            timeout=35, check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise GuardError(f"command_unavailable:{args[0]}") from exc
    if result.returncode:
        raise GuardError(f"command_failed:{args[0]}")
    return result.stdout.strip()


def valid_sha(value: Any) -> str:
    candidate = str(value or "").strip().lower()
    if SHA.fullmatch(candidate) is None:
        raise GuardError("invalid_sha")
    return candidate


def descendant(ancestor_sha: str, descendant_sha: str) -> bool:
    try:
        result = subprocess.run(
            ["git", "merge-base", "--is-ancestor", ancestor_sha, descendant_sha],
            cwd=ROOT, capture_output=True, timeout=15, check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise GuardError("git_ancestry_unavailable") from exc
    if result.returncode not in (0, 1):
        raise GuardError("git_ancestry_error")
    return result.returncode == 0


def expected_revision_covered(
    expected_sha: str, main_sha: str, deployed_sha: str, deploy_needed: bool,
) -> bool:
    """Accept an expected main commit when it is live or only docs/tests are ahead."""
    if not expected_sha:
        return True
    expected = valid_sha(expected_sha)
    if not descendant(expected, main_sha):
        return False
    if descendant(expected, deployed_sha):
        return True
    # A docs/tests-only commit cannot be present in an intentionally older release.
    # Permit that explicit exception only when the official classifier found no
    # deployable files since the deployed SHA and the target is on that ancestry.
    return not deploy_needed and descendant(deployed_sha, expected)


def evaluate(
    main_sha: str, evidence: dict[str, Any], public_sha: str,
    deploy_needed: bool, expected_sha: str = "", expected_is_live: bool = True,
) -> dict[str, Any]:
    main = valid_sha(main_sha)
    deployed = valid_sha(evidence.get("sha"))
    public = valid_sha(public_sha)
    jobs = evidence.get("jobs") if isinstance(evidence.get("jobs"), dict) else {}
    evidence_ok = (
        evidence.get("status") == "PRODUCTION_UPDATED"
        and all(jobs.get(name) == "success" for name in ("validate", "deploy", "smoke_test"))
        and bool(evidence.get("verified_at"))
        and bool(evidence.get("workflow_run_id"))
    )
    if not evidence_ok:
        status = "EVIDENCE_INVALID"
    elif public != deployed:
        status = "PUBLIC_RELEASE_MISMATCH"
    elif expected_sha and not expected_is_live:
        status = "EXPECTED_COMMIT_NOT_DEPLOYED"
    elif main != deployed and deploy_needed:
        status = "MAIN_HAS_UNDEPLOYED_CODE"
    elif main != deployed:
        status = "VERIFIED_DOCS_ONLY_AHEAD"
    else:
        status = "VERIFIED_EXACT"
    return {
        "ok": status in ("VERIFIED_EXACT", "VERIFIED_DOCS_ONLY_AHEAD"),
        "status": status, "main_sha": main,
        "deployed_sha": deployed, "public_sha": public,
        "deploy_needed": bool(deploy_needed),
    }


def observe(repository: str, expected_sha: str = "") -> dict[str, Any]:
    command("git", "fetch", "--no-tags", "origin", "main")
    main = valid_sha(command("git", "rev-parse", "refs/remotes/origin/main"))
    evidence = json.loads(command(
        "gh", "api", "-H", "Accept: application/vnd.github.raw+json",
        f"repos/{repository}/contents/{EVIDENCE_PATH}",
    ))
    if not isinstance(evidence, dict):
        raise GuardError("evidence_not_object")
    deployed = valid_sha(evidence.get("sha"))
    if main != deployed:
        if not descendant(deployed, main):
            raise GuardError("deployed_revision_not_ancestor_of_main")
        changed = command("git", "diff", "--name-only", deployed, main)
        decision = command("bash", "scripts/should-deploy-production.sh", stdin=changed + "\n")
        if decision not in ("true", "false"):
            raise GuardError("deployment_classifier_invalid")
        needs_deploy = decision == "true"
    else:
        needs_deploy = False
    request = urllib.request.Request(PUBLIC_VERSION_URL, headers={
        "Cache-Control": "no-cache, no-store",
        "User-Agent": "ShopVivaliz-DeploymentClosureGuard/1.0",
    })
    try:
        with urllib.request.urlopen(request, timeout=16) as response:
            if response.status != 200:
                raise GuardError("version_endpoint_not_200")
            public = json.loads(response.read(32768))
    except (OSError, ValueError) as exc:
        raise GuardError("public_version_unavailable") from exc
    if not isinstance(public, dict):
        raise GuardError("public_version_invalid")
    expected_live = expected_revision_covered(expected_sha, main, deployed, needs_deploy)
    return evaluate(
        main, evidence, public.get("release_sha"), needs_deploy, expected_sha, expected_live,
    )


def reconcile_issue(report: dict[str, Any], repository: str) -> None:
    # Exactly one persistent issue; no repeated comment spam.
    issues = json.loads(command(
        "gh", "issue", "list", "--repo", repository, "--state", "all",
        "--search", '"[Deploy Closure]" in:title', "--limit", "100",
        "--json", "number,title,state",
    ))
    matches = [issue for issue in issues if issue.get("title") == ISSUE_TITLE]
    if len(matches) > 1:
        raise GuardError("duplicate_tracking_issues")
    existing = matches[0] if matches else None
    if report["ok"]:
        if existing and existing.get("state") == "OPEN":
            command("gh", "issue", "close", str(existing["number"]), "--repo", repository,
                    "--reason", "completed", "--comment", "Runtime e evidencia de deploy reconciliados.")
        return
    body = (
        "Guard de deploy falhou: `" + report["status"] + "`.\n\n"
        "- main: `" + str(report.get("main_sha", "unverified")) + "`\n"
        "- evidencia: `" + str(report.get("deployed_sha", "unverified")) + "`\n"
        "- endpoint publico: `" + str(report.get("public_sha", "unverified")) + "`\n\n"
        "Tarefa **NAO CONCLUIDA** ate CI, merge, release imutavel, "
        "evidencia oficial e smoke/UI serem validados na mesma revisao. "
        "Nao bypassar branch protection, seguranca ou ownership.\n"
    )
    if existing is None:
        command("gh", "issue", "create", "--repo", repository, "--title", ISSUE_TITLE, "--body", body)
    else:
        command("gh", "issue", "edit", str(existing["number"]), "--repo", repository, "--body", body)
        if existing.get("state") == "CLOSED":
            command("gh", "issue", "reopen", str(existing["number"]), "--repo", repository)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo", default=os.getenv("GITHUB_REPOSITORY", "Vivaliz-site/site-shopvivaliz"))
    parser.add_argument("--expected-sha", default="")
    parser.add_argument("--sync-issue", action="store_true")
    args = parser.parse_args()
    try:
        report = observe(args.repo, args.expected_sha)
    except (GuardError, ValueError) as exc:
        report = {"ok": False, "status": "UNVERIFIED:" + str(exc)}
    print(json.dumps(report, sort_keys=True))
    if args.sync_issue:
        try:
            reconcile_issue(report, args.repo)
        except (GuardError, ValueError) as exc:
            print("issue_reconciliation_failed:" + str(exc), file=sys.stderr)
            return 4
    return 0 if report["ok"] else 3


if __name__ == "__main__":
    raise SystemExit(main())
