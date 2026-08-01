#!/usr/bin/env python3
"""Fail closed unless a commit came from a reviewed, fully checked PR.

The script is intended for protected-branch CI and production deployment gates.
It produces a redacted JSON artifact even when verification fails.
"""
from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

REPORT_PATH = Path(os.getenv("PROVENANCE_REPORT", "artifacts/pr-provenance/report.json"))
DEFAULT_REQUIRED_CHECKS = (
    "Validate workflows and source",
    "Audit repository automation",
    "Audit agents with evidence",
)


class ProvenanceError(RuntimeError):
    """Raised when commit provenance is missing or insufficient."""


def parse_time(value: str | None) -> datetime | None:
    if not value:
        return None
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def before_or_at(value: str | None, cutoff: datetime) -> bool:
    parsed = parse_time(value)
    return parsed is not None and parsed <= cutoff


@dataclass
class GitHubClient:
    api_url: str
    token: str
    repository: str

    def get(self, path: str) -> Any:
        url = f"{self.api_url.rstrip('/')}{path}"
        request = urllib.request.Request(
            url,
            headers={
                "Accept": "application/vnd.github+json",
                "Authorization": f"Bearer {self.token}",
                "X-GitHub-Api-Version": "2022-11-28",
                "User-Agent": "shopvivaliz-pr-provenance-gate/1",
            },
        )
        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                return json.load(response)
        except urllib.error.HTTPError as exc:
            body = exc.read().decode("utf-8", errors="replace")[:500]
            raise ProvenanceError(f"GitHub API HTTP {exc.code} for {path}: {body}") from exc
        except urllib.error.URLError as exc:
            raise ProvenanceError(f"GitHub API unavailable for {path}: {exc.reason}") from exc

    def paged(self, path: str) -> list[dict[str, Any]]:
        items: list[dict[str, Any]] = []
        separator = "&" if "?" in path else "?"
        for page in range(1, 11):
            payload = self.get(f"{path}{separator}per_page=100&page={page}")
            if not isinstance(payload, list):
                raise ProvenanceError(f"Expected list response for {path}")
            items.extend(item for item in payload if isinstance(item, dict))
            if len(payload) < 100:
                return items
        raise ProvenanceError(f"Pagination limit exceeded for {path}")


def required_checks_from_env() -> tuple[str, ...]:
    raw = os.getenv("REQUIRED_PREMERGE_CHECKS", ",".join(DEFAULT_REQUIRED_CHECKS))
    checks = tuple(item.strip() for item in raw.split(",") if item.strip())
    if not checks:
        raise ProvenanceError("REQUIRED_PREMERGE_CHECKS cannot be empty")
    return checks


def select_merged_pr(pulls: Iterable[dict[str, Any]], sha: str, base_branch: str) -> dict[str, Any]:
    candidates = []
    for pull in pulls:
        base = pull.get("base") or {}
        if (
            pull.get("state") == "closed"
            and pull.get("merged_at")
            and base.get("ref") == base_branch
            and str(pull.get("merge_commit_sha") or "").lower() == sha.lower()
        ):
            candidates.append(pull)
    if len(candidates) != 1:
        raise ProvenanceError(
            f"Commit {sha} must map to exactly one merged PR into {base_branch}; found {len(candidates)}"
        )
    return candidates[0]


def effective_approved_reviewers(
    reviews: Iterable[dict[str, Any]], *, author_login: str, merged_at: datetime
) -> list[dict[str, str]]:
    latest: dict[str, dict[str, Any]] = {}
    for review in reviews:
        user = review.get("user") or {}
        login = str(user.get("login") or "").strip()
        submitted_at = review.get("submitted_at")
        if not login or not before_or_at(submitted_at, merged_at):
            continue
        previous = latest.get(login)
        previous_time = parse_time(previous.get("submitted_at")) if previous else None
        current_time = parse_time(submitted_at)
        if previous is None or (current_time and previous_time and current_time >= previous_time):
            latest[login] = review

    approved: list[dict[str, str]] = []
    for login, review in latest.items():
        user = review.get("user") or {}
        if login == author_login:
            continue
        if user.get("type") != "User":
            continue
        if review.get("state") != "APPROVED":
            continue
        approved.append({"login": login, "submitted_at": str(review.get("submitted_at"))})
    return sorted(approved, key=lambda item: item["login"].lower())


def verified_checks_before_merge(
    check_runs: Iterable[dict[str, Any]], *, required_names: Iterable[str], merged_at: datetime
) -> dict[str, dict[str, str]]:
    by_name: dict[str, list[dict[str, Any]]] = {}
    for run in check_runs:
        name = str(run.get("name") or "")
        if not name or not before_or_at(run.get("completed_at"), merged_at):
            continue
        by_name.setdefault(name, []).append(run)

    evidence: dict[str, dict[str, str]] = {}
    missing: list[str] = []
    for required in required_names:
        eligible = by_name.get(required, [])
        if not eligible:
            missing.append(required)
            continue
        latest = max(
            eligible,
            key=lambda item: parse_time(item.get("completed_at"))
            or datetime.min.replace(tzinfo=timezone.utc),
        )
        if latest.get("conclusion") != "success":
            missing.append(f"{required} (latest conclusion={latest.get('conclusion')})")
            continue
        evidence[required] = {
            "conclusion": "success",
            "completed_at": str(latest.get("completed_at")),
            "details_url": str(latest.get("details_url") or ""),
        }
    if missing:
        raise ProvenanceError("Required checks were not successful before merge: " + ", ".join(missing))
    return evidence


def write_report(payload: dict[str, Any]) -> None:
    REPORT_PATH.parent.mkdir(parents=True, exist_ok=True)
    REPORT_PATH.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(payload, indent=2, ensure_ascii=False))


def main() -> int:
    repository = os.getenv("GITHUB_REPOSITORY", "").strip()
    sha = os.getenv("DEPLOY_SHA", os.getenv("GITHUB_SHA", "")).strip()
    base_branch = os.getenv("PROTECTED_BRANCH", "main").strip()
    token = os.getenv("GH_TOKEN", os.getenv("GITHUB_TOKEN", "")).strip()
    api_url = os.getenv("GITHUB_API_URL", "https://api.github.com").strip()
    required_checks = required_checks_from_env()

    report: dict[str, Any] = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "status": "fail",
        "repository": repository,
        "sha": sha,
        "protected_branch": base_branch,
        "required_premerge_checks": list(required_checks),
    }

    try:
        if not repository or "/" not in repository:
            raise ProvenanceError("GITHUB_REPOSITORY is missing or invalid")
        if len(sha) != 40 or any(char not in "0123456789abcdefABCDEF" for char in sha):
            raise ProvenanceError("DEPLOY_SHA/GITHUB_SHA must be a full 40-character commit SHA")
        if not token:
            raise ProvenanceError("GH_TOKEN/GITHUB_TOKEN is required")

        owner, repo = repository.split("/", 1)
        encoded_sha = urllib.parse.quote(sha, safe="")
        client = GitHubClient(api_url=api_url, token=token, repository=repository)

        pulls = client.paged(f"/repos/{owner}/{repo}/commits/{encoded_sha}/pulls")
        pull = select_merged_pr(pulls, sha, base_branch)
        number = int(pull["number"])
        pull = client.get(f"/repos/{owner}/{repo}/pulls/{number}")
        merged_at = parse_time(pull.get("merged_at"))
        if merged_at is None:
            raise ProvenanceError(f"PR #{number} does not have a valid merged_at timestamp")

        author_login = str((pull.get("user") or {}).get("login") or "")
        reviews = client.paged(f"/repos/{owner}/{repo}/pulls/{number}/reviews")
        approved_reviewers = effective_approved_reviewers(
            reviews, author_login=author_login, merged_at=merged_at
        )
        if not approved_reviewers:
            raise ProvenanceError(
                f"PR #{number} had no effective human approval from a reviewer other than the author before merge"
            )

        head_sha = str((pull.get("head") or {}).get("sha") or "")
        if len(head_sha) != 40:
            raise ProvenanceError(f"PR #{number} has no valid head SHA")
        check_payload = client.get(
            f"/repos/{owner}/{repo}/commits/{urllib.parse.quote(head_sha, safe='')}/check-runs?per_page=100"
        )
        check_runs = check_payload.get("check_runs") if isinstance(check_payload, dict) else None
        if not isinstance(check_runs, list):
            raise ProvenanceError(f"Could not retrieve check runs for PR #{number} head {head_sha}")
        checks = verified_checks_before_merge(
            check_runs, required_names=required_checks, merged_at=merged_at
        )

        report.update(
            {
                "status": "pass",
                "pull_request": {
                    "number": number,
                    "url": str(pull.get("html_url") or ""),
                    "author": author_login,
                    "head_sha": head_sha,
                    "merge_commit_sha": str(pull.get("merge_commit_sha") or ""),
                    "merged_at": pull.get("merged_at"),
                },
                "approved_reviewers": approved_reviewers,
                "premerge_checks": checks,
            }
        )
        write_report(report)
        return 0
    except Exception as exc:
        report["error"] = str(exc)
        write_report(report)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
