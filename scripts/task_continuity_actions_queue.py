#!/usr/bin/env python3
"""Certify and prune stale GitHub Actions runs without touching current work."""
from __future__ import annotations

import argparse
import json
import os
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter
from datetime import datetime, timezone
from typing import Any

API_ROOT = "https://api.github.com"
DEFAULT_MIN_AGE_SECONDS = 6 * 3600
MAX_PAGES = 20


def _parse_time(value: Any) -> datetime | None:
    raw = str(value or "").strip()
    if not raw:
        return None
    try:
        parsed = datetime.fromisoformat(raw.replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def _run_head_key(run: dict[str, Any], repository: str) -> tuple[str, str]:
    head_repo = run.get("head_repository")
    repo_name = ""
    if isinstance(head_repo, dict):
        repo_name = str(head_repo.get("full_name") or "").strip()
    if not repo_name:
        repo_name = repository
    return repo_name, str(run.get("head_branch") or "").strip()


def classify_queued_run(
    run: dict[str, Any],
    *,
    repository: str,
    open_heads: set[tuple[str, str]],
    now: datetime,
    min_age_seconds: int,
) -> str:
    if str(run.get("status") or "").strip() != "queued":
        return "not_queued"
    if str(run.get("event") or "").strip() != "pull_request":
        return "non_pr"
    created = _parse_time(run.get("created_at"))
    if created is None:
        return "invalid_created_at"
    age = (now - created).total_seconds()
    if age < max(1, int(min_age_seconds)):
        return "recent"
    head_key = _run_head_key(run, repository)
    if not head_key[1]:
        return "missing_head"
    if head_key in open_heads:
        return "open_pr"
    return "stale_closed_pr"


class GitHubApi:
    def __init__(self, repository: str, token: str) -> None:
        self.repository = repository
        self.token = token

    def request(self, method: str, path: str) -> tuple[int, Any]:
        url = API_ROOT + path
        headers = {
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {self.token}",
            "User-Agent": "shopvivaliz-actions-queue-hygiene",
            "X-GitHub-Api-Version": "2022-11-28",
        }
        data = b"" if method != "GET" else None
        req = urllib.request.Request(url, method=method, headers=headers, data=data)
        try:
            with urllib.request.urlopen(req, timeout=30) as response:
                raw = response.read()
                payload = json.loads(raw.decode("utf-8")) if raw else None
                return int(response.status), payload
        except urllib.error.HTTPError as exc:
            raw = exc.read()
            payload = None
            try:
                payload = json.loads(raw.decode("utf-8")) if raw else None
            except (UnicodeError, json.JSONDecodeError):
                payload = None
            return int(exc.code), payload

    def list_open_heads(self) -> set[tuple[str, str]]:
        heads: set[tuple[str, str]] = set()
        for page in range(1, MAX_PAGES + 1):
            status, payload = self.request(
                "GET",
                f"/repos/{self.repository}/pulls?state=open&per_page=100&page={page}",
            )
            if status != 200 or not isinstance(payload, list):
                raise RuntimeError(f"open_pr_list_failed:{status}")
            for pr in payload:
                if not isinstance(pr, dict):
                    continue
                head = pr.get("head")
                if not isinstance(head, dict):
                    continue
                repo_obj = head.get("repo")
                repo_name = str(repo_obj.get("full_name") or "").strip() if isinstance(repo_obj, dict) else ""
                ref = str(head.get("ref") or "").strip()
                if repo_name and ref:
                    heads.add((repo_name, ref))
            if len(payload) < 100:
                break
        return heads

    def list_queued_runs(self) -> list[dict[str, Any]]:
        runs: list[dict[str, Any]] = []
        for page in range(1, MAX_PAGES + 1):
            status, payload = self.request(
                "GET",
                f"/repos/{self.repository}/actions/runs?status=queued&per_page=100&page={page}",
            )
            if status != 200 or not isinstance(payload, dict):
                raise RuntimeError(f"queued_run_list_failed:{status}")
            page_runs = payload.get("workflow_runs")
            if not isinstance(page_runs, list):
                raise RuntimeError("queued_run_list_shape_invalid")
            runs.extend(row for row in page_runs if isinstance(row, dict))
            if len(page_runs) < 100:
                break
        return runs

    def get_run(self, run_id: int) -> dict[str, Any]:
        status, payload = self.request("GET", f"/repos/{self.repository}/actions/runs/{run_id}")
        if status != 200 or not isinstance(payload, dict):
            return {}
        return payload

    def cancel_run(self, run_id: int) -> int:
        status, _ = self.request("POST", f"/repos/{self.repository}/actions/runs/{run_id}/cancel")
        return status


def run_hygiene(
    *,
    api: GitHubApi,
    min_age_seconds: int = DEFAULT_MIN_AGE_SECONDS,
    apply: bool = False,
    now: datetime | None = None,
) -> dict[str, Any]:
    current = (now or datetime.now(timezone.utc)).astimezone(timezone.utc)
    open_heads = api.list_open_heads()
    queued = api.list_queued_runs()
    classifications: Counter[str] = Counter()
    stale_ids: list[int] = []
    cancelled_ids: list[int] = []
    race_skipped = 0

    for run in queued:
        reason = classify_queued_run(
            run,
            repository=api.repository,
            open_heads=open_heads,
            now=current,
            min_age_seconds=min_age_seconds,
        )
        classifications[reason] += 1
        if reason != "stale_closed_pr":
            continue
        run_id = int(run.get("id") or 0)
        if run_id > 0:
            stale_ids.append(run_id)

    if apply:
        for run_id in stale_ids:
            fresh = api.get_run(run_id)
            reason = classify_queued_run(
                fresh,
                repository=api.repository,
                open_heads=open_heads,
                now=current,
                min_age_seconds=min_age_seconds,
            )
            if reason != "stale_closed_pr":
                race_skipped += 1
                continue
            status = api.cancel_run(run_id)
            if status in {202, 204}:
                cancelled_ids.append(run_id)
            elif status in {409, 422}:
                race_skipped += 1
            else:
                raise RuntimeError(f"cancel_failed:{run_id}:{status}")

    return {
        "ok": True,
        "repository": api.repository,
        "queued_total": len(queued),
        "classifications": dict(sorted(classifications.items())),
        "stale_closed_pr_ids": stale_ids,
        "cancelled_ids": cancelled_ids,
        "race_skipped": race_skipped,
        "apply": bool(apply),
        "min_age_seconds": max(1, int(min_age_seconds)),
        "generated_at": current.replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Certify and prune stale queued GitHub Actions runs.")
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--min-age-seconds", type=int, default=DEFAULT_MIN_AGE_SECONDS)
    args = parser.parse_args()

    repository = str(os.getenv("GITHUB_REPOSITORY", "")).strip()
    token = str(os.getenv("GITHUB_TOKEN", "")).strip()
    if not repository or "/" not in repository:
        raise SystemExit("GITHUB_REPOSITORY is required")
    if not token:
        raise SystemExit("GITHUB_TOKEN is required")

    result = run_hygiene(
        api=GitHubApi(repository, token),
        min_age_seconds=args.min_age_seconds,
        apply=args.apply,
    )
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
