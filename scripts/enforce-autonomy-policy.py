#!/usr/bin/env python3
from __future__ import annotations

import ast
import fnmatch
import io
import json
import os
import re
import subprocess
import sys
import textwrap
import tokenize
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

NONCOMMERCIAL_SENSITIVE_PATH_EXCEPTIONS = {
    ".github/workflows/test-inventory.yml",
}

MUTATION_PATTERN = re.compile(
    r"(?i)(?:\bupdate\b|\binsert\s+into\b|\breplace\s+into\b|\bdelete\s+from\b|"
    r"\bset\b|\bassign\b|\bwrite\b|\bsync\b|\bpatch\b|\bput\b|\bpost\b).{0,160}"
    r"\b(price|pricing|preco|preço|stock|estoque|inventory|quantity|quantidade)\b"
)

ASSERTION_NAMES = {"assertIn", "assertNotIn"}
ASSERTION_TEXT = re.compile(r"\b(?:self\.)?assert(?:NotIn|In)\s*\(")


def assertion_name(call: ast.Call) -> str | None:
    if isinstance(call.func, ast.Name):
        return call.func.id
    if isinstance(call.func, ast.Attribute):
        return call.func.attr
    return None


def assertion_evaluated_text(line: str) -> str | None:
    source = textwrap.dedent(line)
    try:
        module = ast.parse(source)
    except SyntaxError:
        return None
    if len(module.body) != 1:
        return None

    statement = module.body[0]
    if isinstance(statement, (ast.Assign, ast.AnnAssign)):
        value = statement.value
        if isinstance(value, ast.Constant) and isinstance(value.value, str):
            if ASSERTION_TEXT.search(value.value):
                return ""
        return None

    if not isinstance(statement, ast.Expr) or not isinstance(statement.value, ast.Call):
        return None
    call = statement.value
    if assertion_name(call) not in ASSERTION_NAMES:
        return None

    fragments: list[str] = []
    values = list(call.args) + [item.value for item in call.keywords]
    for value in values:
        if isinstance(value, ast.Constant) and isinstance(value.value, (str, bytes)):
            continue
        fragment = ast.get_source_segment(source, value)
        if fragment:
            fragments.append(" ".join(fragment.split()))
    return " ".join(fragments)


def assertion_source(lines: list[str], index: int) -> str | None:
    source = "\n".join(lines[index:])
    depth = 0
    started = False
    try:
        for token in tokenize.generate_tokens(io.StringIO(source).readline):
            if token.type != tokenize.OP:
                continue
            if token.string == "(":
                depth += 1
                started = True
            elif token.string == ")" and started:
                depth -= 1
                if depth == 0:
                    return "\n".join(lines[index:index + token.end[0]])
    except (tokenize.TokenError, IndentationError):
        pass
    return None


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
    if lower in NONCOMMERCIAL_SENSITIVE_PATH_EXCEPTIONS:
        return False
    name = Path(lower).name
    return any(fnmatch.fnmatch(name, pattern) for pattern in SENSITIVE_PATH_PATTERNS)


def should_scan_content(path: str) -> bool:
    lower = path.lower()
    if lower.startswith(IGNORED_PREFIXES):
        return False
    return True


def sensitive_content(lines: list[str]) -> list[str]:
    findings: list[str] = []
    consumed: set[int] = set()

    def inspect(scan_text: str, index: int, original: str) -> None:
        if not scan_text:
            return
        lower = scan_text.lower()
        if not any(token in lower for token in SENSITIVE_TOKENS):
            return
        if MUTATION_PATTERN.search(scan_text):
            findings.append(f"added-line-{index + 1}: {original[:240]}")

    for index, line in enumerate(lines):
        if index in consumed:
            continue

        if ASSERTION_TEXT.search(line):
            source = assertion_source(lines, index)
            if source is None:
                evaluated = assertion_evaluated_text(line)
                inspect(line if evaluated is None else evaluated, index, line)
                continue
            evaluated = assertion_evaluated_text(source)
            if evaluated is None:
                inspect(line, index, line)
                continue
            consumed.update(range(index, index + source.count("\n") + 1))
            inspect(evaluated, index, source)
            continue

        inspect(line, index, line)

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
        if should_scan_content(path):
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
