#!/usr/bin/env python3
from __future__ import annotations

import fnmatch
import json
import os
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
POLICY_PATH = ROOT / "config" / "autonomy-policy.json"
REPORT_PATH = ROOT / "artifacts" / "autonomy-policy" / "report.json"

SENSITIVE_TOKENS = (
    "price", "pricing", "preco", "precos", "preço", "preços",
    "stock", "estoque", "inventory", "quantity", "quantidade",
)

SENSITIVE_PATH_PATTERNS = (
    "*price*", "*pricing*", "*preco*", "*preços*", "*stock*",
    "*estoque*", "*inventory*", "*quantity*", "*quantidade*",
)

IGNORED_PREFIXES = (
    "docs/", "release-notes/", "reports/", "artifacts/", "tests/fixtures/",
)

MUTATION_PATTERN = re.compile(
    r"(?i)(?:\bupdate\b|\binsert\s+into\b|\breplace\s+into\b|\bdelete\s+from\b|"
    r"\bset\b|\bassign\b|\bwrite\b|\bsync\b|\bpatch\b|\bput\b|\bpost\b).{0,160}"
    r"\b(price|pricing|preco|preço|stock|estoque|inventory|quantity|quantidade)\b"
)


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args], cwd=ROOT, check=True, capture_output=True, text=True
    )
    return result.stdout


def load_policy() -> dict:
    return json.loads(POLICY_PATH.read_text(encoding="utf-8"))


def is_autonomous_branch(branch: str, prefixes: list[str]) -> bool:
    return any(branch.startswith(prefix) for prefix in prefixes)


def changed_files(base: str, head: str) -> list[str]:
    return [line.strip() for line in git("diff", "--name-only", base, head).splitlines() if line.strip()]


def added_lines(base: str, head: str, path: str) -> list[str]:
    output = git("diff", "--unified=0", "--no-color", base, head, "--", path)
    lines: list[str] = []
    for line in output.splitlines():
        if line.startswith("+++"):
            continue
        if line.startswith("+"):
            lines.append(line[1:])
    return lines


def sensitive_path(path: str) -> bool:
    lower = path.lower()
    if lower.startswith(IGNORED_PREFIXES):
        return False
    # Workflow filenames describe automation/test infrastructure, not catalog data.
    # Their contents are still scanned below for real price/stock mutations.
    if lower.startswith(".github/workflows/"):
        return False
    name = Path(lower).name
    return any(fnmatch.fnmatch(name, pattern) for pattern in SENSITIVE_PATH_PATTERNS)


def sensitive_content(lines: list[str]) -> list[str]:
    findings: list[str] = []
    for index, line in enumerate(lines, start=1):
        lower = line.lower()
        if not any(token in lower for token in SENSITIVE_TOKENS):
            continue
        if MUTATION_PATTERN.search(line):
            findings.append(f"added-line-{index}: {line[:240]}")
    return findings


def main() -> int:
    policy = load_policy()
    base = os.getenv("BASE_SHA", "origin/main")
    head = os.getenv("HEAD_SHA", "HEAD")
    branch = os.getenv("HEAD_REF", "")
    labels = {item.strip() for item in os.getenv("PR_LABELS", "").split(",") if item.strip()}
    approval_label = str(policy["human_approval_label"])
    autonomous = is_autonomous_branch(branch, list(policy["autonomous_branch_prefixes"]))

    files = changed_files(base, head)
    findings: list[dict] = []
    for path in files:
        path_findings: list[str] = []
        if sensitive_path(path):
            path_findings.append("sensitive_path")
        if not path.lower().startswith(IGNORED_PREFIXES):
            path_findings.extend(sensitive_content(added_lines(base, head, path)))
        if path_findings:
            findings.append({"path": path, "reasons": path_findings})

    allowed = True
    decision = "allowed"
    if findings and autonomous:
        allowed = False
        decision = "blocked_autonomous_price_or_stock_change"
    elif findings and approval_label not in labels:
        allowed = False
        decision = "human_approval_label_required"

    payload = {
        "ok": allowed,
        "decision": decision,
        "branch": branch,
        "autonomous": autonomous,
        "approval_label": approval_label,
        "labels": sorted(labels),
        "base": base,
        "head": head,
        "changed_files": files,
        "sensitive_findings": findings,
    }
    REPORT_PATH.parent.mkdir(parents=True, exist_ok=True)
    REPORT_PATH.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 0 if allowed else 1


if __name__ == "__main__":
    raise SystemExit(main())
