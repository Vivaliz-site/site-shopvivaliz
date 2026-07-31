#!/usr/bin/env python3
"""Validate that all reachable branches and tags descend from the sanitized root."""
from __future__ import annotations

import json
import subprocess
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MARKER = ROOT / ".security" / "sanitized-history.json"
REPORT_DIR = ROOT / "artifacts" / "history-integrity"
FORBIDDEN_REF_PARTS = (
    "backup/pre-history-cleanup",
    "history-clean-candidate",
    "maintenance/history-cleanup-run",
)


@dataclass(frozen=True)
class Finding:
    severity: str
    rule: str
    ref: str
    message: str


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        args,
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=check,
    )


def load_marker() -> dict[str, object]:
    payload = json.loads(MARKER.read_text(encoding="utf-8"))
    root_sha = payload.get("root_sha")
    expected_tag = payload.get("expected_tag")
    if not isinstance(root_sha, str) or len(root_sha) != 40:
        raise ValueError("marker root_sha must be a full 40-character SHA")
    if not isinstance(expected_tag, str) or not expected_tag:
        raise ValueError("marker expected_tag is required")
    return payload


def fetch_all_refs() -> None:
    run(
        "git",
        "fetch",
        "--force",
        "--prune",
        "--tags",
        "origin",
        "+refs/heads/*:refs/remotes/origin/*",
    )


def remote_heads() -> dict[str, str]:
    result = run("git", "ls-remote", "--heads", "origin")
    refs: dict[str, str] = {}
    for line in result.stdout.splitlines():
        if not line.strip():
            continue
        sha, ref = line.split("\t", 1)
        refs[ref.removeprefix("refs/heads/")] = sha
    return refs


def remote_tags() -> dict[str, str]:
    result = run("git", "ls-remote", "--tags", "origin")
    direct: dict[str, str] = {}
    peeled: dict[str, str] = {}
    for line in result.stdout.splitlines():
        if not line.strip():
            continue
        sha, ref = line.split("\t", 1)
        name = ref.removeprefix("refs/tags/")
        if name.endswith("^{}"):
            peeled[name[:-3]] = sha
        else:
            direct[name] = sha
    return {name: peeled.get(name, sha) for name, sha in direct.items()}


def commit_exists(sha: str) -> bool:
    return run("git", "cat-file", "-e", f"{sha}^{{commit}}", check=False).returncode == 0


def is_ancestor(ancestor: str, descendant: str) -> bool:
    return run(
        "git",
        "merge-base",
        "--is-ancestor",
        ancestor,
        descendant,
        check=False,
    ).returncode == 0


def commit_count(root_sha: str, ref_sha: str) -> int:
    result = run("git", "rev-list", "--count", f"{root_sha}..{ref_sha}")
    return int(result.stdout.strip())


def evaluate() -> tuple[list[Finding], dict[str, object]]:
    findings: list[Finding] = []
    marker = load_marker()
    root_sha = str(marker["root_sha"])
    expected_tag = str(marker["expected_tag"])

    fetch_all_refs()
    heads = remote_heads()
    tags = remote_tags()

    checks: dict[str, object] = {
        "root_sha": root_sha,
        "expected_tag": expected_tag,
        "remote_branch_count": len(heads),
        "remote_tag_count": len(tags),
        "remote_branches": heads,
        "remote_tags": tags,
        "descendant_commit_counts": {},
    }

    if not commit_exists(root_sha):
        findings.append(Finding("critical", "root_missing", root_sha, "Sanitized root commit is unavailable."))
        return findings, checks

    parent_line = run("git", "rev-list", "--parents", "-n", "1", root_sha).stdout.strip().split()
    root_has_no_parent = len(parent_line) == 1
    checks["root_has_no_parent"] = root_has_no_parent
    if not root_has_no_parent:
        findings.append(Finding("critical", "root_has_parent", root_sha, "Sanitized root must remain a parentless commit."))

    main_sha = heads.get("main")
    checks["main_present"] = main_sha is not None
    if main_sha is None:
        findings.append(Finding("critical", "main_missing", "refs/heads/main", "Default branch main is missing."))

    for name, sha in sorted(heads.items()):
        ref = f"refs/heads/{name}"
        if any(part in name for part in FORBIDDEN_REF_PARTS):
            findings.append(Finding("high", "transient_branch_present", ref, "Transient cleanup branch must not remain reachable."))
        if not commit_exists(sha):
            findings.append(Finding("critical", "branch_commit_missing", ref, "Branch commit object is unavailable."))
            continue
        if not is_ancestor(root_sha, sha):
            findings.append(Finding("critical", "branch_outside_clean_history", ref, "Branch does not descend from the sanitized root."))
            continue
        checks["descendant_commit_counts"][ref] = commit_count(root_sha, sha)

    for name, sha in sorted(tags.items()):
        ref = f"refs/tags/{name}"
        if not commit_exists(sha):
            findings.append(Finding("critical", "tag_commit_missing", ref, "Tag target commit is unavailable."))
            continue
        if not is_ancestor(root_sha, sha):
            findings.append(Finding("critical", "tag_outside_clean_history", ref, "Tag does not descend from the sanitized root."))
            continue
        checks["descendant_commit_counts"][ref] = commit_count(root_sha, sha)

    expected_tag_sha = tags.get(expected_tag)
    expected_tag_matches_root = expected_tag_sha == root_sha
    checks["expected_tag_matches_root"] = expected_tag_matches_root
    if not expected_tag_matches_root:
        findings.append(Finding("high", "expected_tag_invalid", f"refs/tags/{expected_tag}", "Expected cleanup tag must point directly to the sanitized root."))

    return findings, checks


def write_report(findings: list[Finding], checks: dict[str, object]) -> None:
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    status = "success" if not findings else "failure"
    payload = {
        "schema_version": 1,
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "status": status,
        "blocking_finding_count": len(findings),
        "checks": checks,
        "findings": [asdict(item) for item in findings],
        "scope_note": "Validates reachable branch and tag refs. GitHub pull-request refs and platform caches require separate platform purge when sensitive commits were previously exposed.",
    }
    (REPORT_DIR / "report.json").write_text(
        json.dumps(payload, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )

    lines = [
        "# Sanitized history integrity",
        "",
        f"- Status: `{status}`",
        f"- Blocking findings: `{len(findings)}`",
        f"- Root SHA: `{checks.get('root_sha')}`",
        f"- Remote branches: `{checks.get('remote_branch_count')}`",
        f"- Remote tags: `{checks.get('remote_tag_count')}`",
        "",
    ]
    if findings:
        lines.extend(["## Findings", ""])
        for item in findings:
            lines.append(f"- **{item.severity}** `{item.rule}` `{item.ref}` — {item.message}")
    else:
        lines.append("All reachable branches and tags descend from the sanitized root.")
    (REPORT_DIR / "report.md").write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    try:
        findings, checks = evaluate()
    except (OSError, ValueError, subprocess.CalledProcessError) as exc:
        findings = [Finding("critical", "validator_error", "repository", str(exc))]
        checks = {}
    write_report(findings, checks)
    print((REPORT_DIR / "report.json").read_text(encoding="utf-8"))
    return 0 if not findings else 1


if __name__ == "__main__":
    raise SystemExit(main())
