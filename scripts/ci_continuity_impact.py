#!/usr/bin/env python3
"""Fail-closed continuity test impact selection.

Dispatcher-only changes use targeted tests. Unknown and mixed continuity
changes retain the complete browser-worker regression.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
from pathlib import Path

SHA = re.compile(r"^[a-f0-9]{40}$")
DISPATCHER = frozenset({
    "scripts/chatgpt_continuity_nudge_dispatcher.py",
    "tests/test_chatgpt_continuity_nudge_dispatcher.py",
})
# These independently guarded authorization surfaces have no browser-worker
# dependency. Any other file path forces full continuity regression.
INDEPENDENT_GOVERNANCE = frozenset({
    "AGENTS.md",
    "docs/knowledge/security-approval-via-pr.md",
    "scripts/validate-owner-pr-grant.py",
    "tests/test_validate_owner_pr_grant.py",
    ".github/workflows/absolute-audit-governance.yml",
})


def classify(files: list[str]) -> dict[str, object]:
    paths = sorted(set(files))
    invalid = [
        p for p in paths
        if not p or p.startswith(("/", "./", "../")) or "\\" in p
        or "//" in p or ".." in p.split("/")
    ]
    unexpected = sorted(set(paths) - DISPATCHER - INDEPENDENT_GOVERNANCE)
    dispatcher = sorted(set(paths) & DISPATCHER)
    mode = "dispatcher" if dispatcher and not unexpected and not invalid else "full"
    return {
        "mode": mode,
        "dispatcher_paths": dispatcher,
        "full_reasons": ["invalid_path"] if invalid else unexpected or ([] if dispatcher else ["no_dispatcher_change"]),
    }


def changed_files(base: str, head: str) -> list[str] | None:
    if not SHA.fullmatch(base) or not SHA.fullmatch(head):
        return None
    try:
        result = subprocess.run(
            ["git", "diff", "--name-only", "-z", f"{base}...{head}"],
            capture_output=True, timeout=30, check=True,
        )
        return [p.decode("utf-8", "replace") for p in result.stdout.split(b"\x00") if p]
    except (OSError, subprocess.SubprocessError):
        return None


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--file", action="append", default=[])
    ap.add_argument("--base", default="")
    ap.add_argument("--head", default="")
    ap.add_argument("--github-output", default="")
    args = ap.parse_args()
    paths = args.file if args.file else changed_files(args.base, args.head)
    result = classify(paths or [])
    if paths is None:
        result["mode"] = "full"
        result["full_reasons"] = ["missing_or_untrusted_diff"]
    if args.github_output:
        with Path(args.github_output).open("a", encoding="utf-8") as out:
            out.write(f"mode={result['mode']}\n")
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
