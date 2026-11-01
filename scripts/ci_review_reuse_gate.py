#!/usr/bin/env python3
"""Reuse completed code checks on review events; NEVER infer an approval grant.

This validator must run from the trusted base checkout, not the PR head.
Mandatory agent, history, and commercial approval gates still run anew.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

SHA = re.compile(r"^[0-9a-f]{40}$")


def can_reuse(payload: object, *, pr: int, head: str, base: str,
              head_ref: str, current_run: int) -> bool:
    if (type(pr) is not int or pr <= 0 or type(current_run) is not int
            or current_run <= 0 or not isinstance(head, str)
            or not isinstance(base, str) or not SHA.fullmatch(head)
            or not SHA.fullmatch(base) or not isinstance(head_ref, str)
            or not head_ref or not isinstance(payload, dict)):
        return False
    runs = payload.get("workflow_runs")
    if not isinstance(runs, list):
        return False
    for run in runs:
        if not isinstance(run, dict):
            continue
        if not (run.get("name") == "Mandatory Validation Gate"
                and run.get("path") == ".github/workflows/mandatory-validation-gate.yml"
                and run.get("event") == "pull_request"
                and run.get("status") == "completed"
                and run.get("conclusion") == "success"
                and run.get("head_sha") == head
                and run.get("head_branch") == head_ref
                and type(run.get("id")) is int
                and 0 < run["id"] < current_run):
            continue
        prs = run.get("pull_requests")
        if not isinstance(prs, list):
            continue
        for item in prs:
            if not isinstance(item, dict) or item.get("number") != pr:
                continue
            if not isinstance(item.get("head"), dict) or not isinstance(item.get("base"), dict):
                continue
            if item["head"].get("sha") == head and item["base"].get("sha") == base:
                return True
    return False


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--pr", type=int, required=True)
    parser.add_argument("--head", required=True)
    parser.add_argument("--base", required=True)
    parser.add_argument("--head-ref", required=True)
    parser.add_argument("--current-run", type=int, required=True)
    parser.add_argument("--github-output", required=True)
    args = parser.parse_args()
    try:
        payload = json.load(sys.stdin)
    except (ValueError, TypeError):
        payload = None
    reuse = can_reuse(payload, pr=args.pr, head=args.head, base=args.base,
                      head_ref=args.head_ref, current_run=args.current_run)
    with Path(args.github_output).open("a", encoding="utf-8") as output:
        output.write(f"reuse={'true' if reuse else 'false'}\n")
    print("review_code_validation_reuse=" + ("verified_same_sha_base" if reuse else "full_required"))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
