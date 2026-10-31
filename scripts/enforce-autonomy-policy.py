#!/usr/bin/env python3
from __future__ import annotations

import ast
import fnmatch
import hashlib
import io
import json
import os
import re
import subprocess
import sys
import textwrap
import tokenize
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from quality.verify_commercial_review import approval_from_environment

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

READONLY_STOCK_VALIDATOR_PATH = "scripts/quality/validate-product-stock.php"
READONLY_STOCK_VALIDATOR_ALLOWED_ADD = "if ($rows === []) $errors[] = 'empty_catalog_or_missing_cache';"

READONLY_CATALOG_TEST_PATH = "tests/catalog-search-stock-race.test.cjs"
CATALOG_SOURCE_PATH = "js/catalog.js"
GENERATED_CATALOG_RE = re.compile(r"^public/dist/js/catalog\.[0-9a-f]{10}\.min\.js$")


NONCOMMERCIAL_SENSITIVE_PATH_EXCEPTIONS = {
    ".github/workflows/test-inventory.yml",
    # The stock quality probe is exempt ONLY for the precise fail-closed one-line
    # addition checked below. Any other addition or deletion requires approval.
    READONLY_STOCK_VALIDATOR_PATH,
    # Only this regression test is exempt by path; its code is still scanned.
    READONLY_CATALOG_TEST_PATH,
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


def approved_readonly_stock_validator_change(diff: str) -> bool:
    """Accept only the one known fail-closed assertion; other deltas stay human-only.

    In particular, a deletion must never silently lose stock protections merely
    because the filename itself describes a read-only validator.
    """
    added = []
    deleted = []
    for line in diff.splitlines():
        if line.startswith(("+++", "---")):
            continue
        if line.startswith("+"):
            added.append(line[1:].strip())
        elif line.startswith("-"):
            deleted.append(line[1:].strip())
    return not deleted and added == [READONLY_STOCK_VALIDATOR_ALLOWED_ADD]


def minify_catalog_source(source: str) -> bytes:
    """Match the nonexecuting deterministic transform of build-assets.mjs."""
    no_block = re.sub(r"/\*[\s\S]*?\*/", "", source)
    no_line = re.sub(r"^\s*//.*$", "", no_block, flags=re.MULTILINE)
    return re.sub(r"\s+", " ", no_line).strip().encode("utf-8")


def verified_generated_catalog_asset(
    path: str, changed: set[str], root: Path = ROOT
) -> bool:
    """Only exempt exact generated JS with separately scanned changed source."""
    if not GENERATED_CATALOG_RE.fullmatch(path) or CATALOG_SOURCE_PATH not in changed:
        return False
    try:
        source_bytes = (root / CATALOG_SOURCE_PATH).read_bytes()
        expected = minify_catalog_source(source_bytes.decode("utf-8"))
        actual = (root / path).read_bytes()
        entry = json.loads(
            (root / "public/dist/asset-manifest.json").read_text(encoding="utf-8")
        )["assets"][f"/{CATALOG_SOURCE_PATH}"]
        digest = hashlib.sha256(expected).hexdigest()[:10]
        return (
            actual == expected
            and Path(path).name == f"catalog.{digest}.min.js"
            and isinstance(entry, dict)
            and entry.get("file") == f"/{path}"
            and type(entry.get("bytes")) is int
            and entry["bytes"] == len(expected)
            and type(entry.get("source_bytes")) is int
            and entry["source_bytes"] == len(source_bytes)
        )
    except (OSError, ValueError, UnicodeError, KeyError, TypeError):
        return False


def main() -> int:
    policy = load_policy()
    base = os.getenv("BASE_SHA", "origin/main")
    head = os.getenv("HEAD_SHA", "HEAD")
    branch = os.getenv("HEAD_REF", "")
    labels = {item.strip() for item in os.getenv("PR_LABELS", "").split(",") if item.strip()}
    approval_label = str(policy["human_approval_label"])
    autonomous = is_autonomous_branch(branch, list(policy["autonomous_branch_prefixes"]))

    files = changed_files(base, head)
    file_set = set(files)
    findings: list[dict] = []
    for path in files:
        path_findings: list[str] = []
        generated_asset = bool(GENERATED_CATALOG_RE.fullmatch(path))
        asset_present = (ROOT / path).is_file() if generated_asset else False
        verified_asset = (
            verified_generated_catalog_asset(path, file_set)
            if asset_present else False
        )
        if generated_asset and asset_present and not verified_asset:
            path_findings.append("generated_catalog_asset_not_reproducible")
        if path == READONLY_STOCK_VALIDATOR_PATH:
            patch = git("diff", "--unified=0", "--no-color", base, head, "--", path)
            if not approved_readonly_stock_validator_change(patch):
                path_findings.append("unapproved_stock_validator_diff")
        elif sensitive_path(path):
            path_findings.append("sensitive_path")
        # The validated minified artifact is derived byte-for-byte from the
        # separately scanned js/catalog.js. Scanning one concatenated line
        # otherwise creates false positives across unrelated source functions.
        if should_scan_content(path) and not verified_asset:
            path_findings.extend(sensitive_content(added_lines(base, head, path)))
        if path_findings:
            findings.append({"path": path, "reasons": path_findings})

    allowed = True
    decision = "allowed"
    # The PR label, auto-merge, and comments are not independent approvals.
    approval_reason = "not_required"
    if findings:
        approved, approval_reason = approval_from_environment()
        if not approved:
            allowed = False
            decision = (
                "human_approval_required_on_autonomous_branch"
                if autonomous else "verified_human_pr_review_required"
            )

    payload = {
        "ok": allowed,
        "decision": decision,
        "branch": branch,
        "autonomous": autonomous,
        "approval_label": approval_label,
        "labels": sorted(labels),
        "commercial_review_verdict": approval_reason,
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
