#!/usr/bin/env python3
"""Fast pull-request lineage check against the sanitized repository root."""
from __future__ import annotations

import json
import subprocess
from pathlib import Path
from typing import Callable, Any

ROOT = Path(__file__).resolve().parents[2]
MARKER = ROOT / ".security" / "sanitized-history.json"


def run(*args: str, cwd: Path | None = None, check: bool = False) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        args,
        cwd=cwd or ROOT,
        text=True,
        capture_output=True,
        check=check,
    )


def evaluate(
    *,
    repo: Path = ROOT,
    marker_path: Path = MARKER,
    run_command: Callable[..., Any] = run,
) -> tuple[list[dict[str, str]], dict[str, object]]:
    findings: list[dict[str, str]] = []
    payload = json.loads(marker_path.read_text(encoding="utf-8"))
    root_sha = payload.get("root_sha")
    if not isinstance(root_sha, str) or len(root_sha) != 40:
        raise ValueError("marker root_sha must be a full 40-character SHA")

    checks: dict[str, object] = {
        "root_sha": root_sha,
        "head": "HEAD",
        "scope": "current_head_lineage",
    }

    root_exists = run_command(
        "git", "cat-file", "-e", f"{root_sha}^{{commit}}",
        cwd=repo,
        check=False,
    )
    if root_exists.returncode != 0:
        findings.append({
            "severity": "critical",
            "rule": "root_missing",
            "message": "Sanitized root commit is unavailable in the PR checkout.",
        })
        return findings, checks

    lineage = run_command(
        "git", "merge-base", "--is-ancestor", root_sha, "HEAD",
        cwd=repo,
        check=False,
    )
    checks["head_descends_from_sanitized_root"] = lineage.returncode == 0
    if lineage.returncode != 0:
        findings.append({
            "severity": "critical",
            "rule": "head_outside_clean_history",
            "message": "Current PR head does not descend from the sanitized root.",
        })

    return findings, checks


def main() -> int:
    try:
        findings, checks = evaluate()
    except (OSError, ValueError, json.JSONDecodeError, subprocess.SubprocessError) as exc:
        print(json.dumps({
            "ok": False,
            "scope": "current_head_lineage",
            "error": str(exc),
        }, ensure_ascii=False))
        return 2

    result = {
        "ok": not findings,
        "scope": "current_head_lineage",
        "checks": checks,
        "findings": findings,
    }
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0 if not findings else 1


if __name__ == "__main__":
    raise SystemExit(main())
