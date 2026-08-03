#!/usr/bin/env python3
"""Verify the hourly agent audit instead of triggering an autonomous executor."""
from __future__ import annotations

import argparse
import json
import os
import subprocess
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "artifacts" / "agent-heartbeat" / "report.json"
DEFAULT_REPOSITORY = "Vivaliz-site/site-shopvivaliz"
WORKFLOW = "agents-hourly-deep-audit.yml"
ARTIFACT_PREFIX = "agents-deep-audit-"


def gh_json(arguments: list[str]) -> object:
    result = subprocess.run(
        ["gh", *arguments],
        cwd=ROOT,
        text=True,
        encoding="utf-8",
        errors="replace",
        capture_output=True,
        check=True,
    )
    return json.loads(result.stdout)


def parse_time(value: str) -> datetime:
    return datetime.fromisoformat(value.replace("Z", "+00:00")).astimezone(timezone.utc)


def evaluate_run(run: dict[str, object], artifacts: list[dict[str, object]], now: datetime, max_age_minutes: int) -> tuple[str, list[str]]:
    errors: list[str] = []
    status = str(run.get("status") or "")
    conclusion = str(run.get("conclusion") or "")
    updated_at = str(run.get("updatedAt") or "")
    run_id = run.get("databaseId")

    if status != "completed":
        errors.append(f"latest hourly audit is not completed: {status or 'missing'}")
    if conclusion != "success":
        errors.append(f"latest hourly audit did not succeed: {conclusion or 'missing'}")
    if not updated_at:
        errors.append("latest hourly audit has no updatedAt")
    else:
        age_minutes = (now - parse_time(updated_at)).total_seconds() / 60
        if age_minutes > max_age_minutes:
            errors.append(f"latest hourly audit is stale: {age_minutes:.1f} minutes")
    expected_name = f"{ARTIFACT_PREFIX}{run_id}"
    if not any(str(artifact.get("name")) == expected_name and not artifact.get("expired") for artifact in artifacts):
        errors.append(f"required artifact is missing or expired: {expected_name}")
    return ("completed_verified" if not errors else "failed"), errors


def write_report(payload: dict[str, object]) -> None:
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Verify hourly agent audit evidence")
    parser.add_argument("--repository", default=os.getenv("GITHUB_REPOSITORY", DEFAULT_REPOSITORY))
    parser.add_argument("--max-age-minutes", type=int, default=120)
    args = parser.parse_args()

    now = datetime.now(timezone.utc)
    base_payload: dict[str, object] = {
        "schema_version": 2,
        "generated_at": now.isoformat(),
        "repository": args.repository,
        "workflow": WORKFLOW,
        "effect": "read_only_verification",
    }

    if not os.getenv("GH_TOKEN") and not os.getenv("GITHUB_TOKEN"):
        payload = {
            **base_payload,
            "status": "blocked",
            "reason": "GitHub token is not configured",
            "evidence": {"artifact": REPORT.relative_to(ROOT).as_posix()},
        }
        write_report(payload)
        print(json.dumps(payload, ensure_ascii=False))
        return 3

    try:
        runs = gh_json([
            "run", "list", "--repo", args.repository, "--workflow", WORKFLOW,
            "--limit", "1", "--json", "databaseId,status,conclusion,updatedAt,url,headSha",
        ])
        if not isinstance(runs, list) or not runs:
            raise RuntimeError("no hourly audit run was returned")
        run = runs[0]
        run_id = run.get("databaseId")
        if not isinstance(run_id, int):
            raise RuntimeError("latest hourly audit has no numeric run id")
        artifact_payload = gh_json(["api", f"repos/{args.repository}/actions/runs/{run_id}/artifacts"])
        artifacts = artifact_payload.get("artifacts", []) if isinstance(artifact_payload, dict) else []
        if not isinstance(artifacts, list):
            raise RuntimeError("artifact response is not a list")
        status, errors = evaluate_run(run, artifacts, now, args.max_age_minutes)
        payload = {
            **base_payload,
            "status": status,
            "errors": errors,
            "evidence": {
                "run_id": run_id,
                "run_url": run.get("url"),
                "commit_sha": run.get("headSha"),
                "artifact_name": f"{ARTIFACT_PREFIX}{run_id}",
                "report": REPORT.relative_to(ROOT).as_posix(),
            },
        }
        write_report(payload)
        print(json.dumps(payload, ensure_ascii=False))
        return 0 if status == "completed_verified" else 2
    except (subprocess.CalledProcessError, json.JSONDecodeError, RuntimeError, ValueError) as exc:
        payload = {
            **base_payload,
            "status": "failed",
            "errors": [str(exc)],
            "evidence": {"report": REPORT.relative_to(ROOT).as_posix()},
        }
        write_report(payload)
        print(json.dumps(payload, ensure_ascii=False))
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
