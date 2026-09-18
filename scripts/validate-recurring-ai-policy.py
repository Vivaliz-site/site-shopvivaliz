#!/usr/bin/env python3
"""Fail closed when GitHub workflows can invoke paid AI recurrently or broadly."""

from dataclasses import dataclass
from pathlib import Path
import re
import sys


@dataclass(frozen=True)
class Violation:
    path: Path
    code: str
    message: str


PAID_EXECUTION_PATTERNS = (
    re.compile(r"anthropics/claude-code-action@", re.IGNORECASE),
    re.compile(r"\bcodex\s+exec\b", re.IGNORECASE),
    re.compile(r"api\.openai\.com", re.IGNORECASE),
    re.compile(r"api\.anthropic\.com", re.IGNORECASE),
    re.compile(r"\bopenai\.OpenAI\s*\(", re.IGNORECASE),
    re.compile(r"\banthropic\.Anthropic\s*\(", re.IGNORECASE),
)

BROAD_EVENT_PATTERNS = (
    re.compile(r"(?m)^\s{2}(?:issue_comment|issues|pull_request_review|pull_request_review_comment|pull_request|pull_request_target|push):\s*"),
)

SAFE_AUDIT_SCRIPT_PATHS = {
    "scripts/validate-recurring-ai-policy.py",
}
SAFE_NON_RUNTIME_PREFIXES = ("tests/",)

SCRIPT_REFERENCE_PATTERN = re.compile(
    r"(?m)(?:python3?|php|bash|sh|node)\s+['\"]?([A-Za-z0-9_./-]+\.(?:py|php|sh|js|mjs|cjs))",
    re.IGNORECASE,
)


def _uses_paid_ai(text: str) -> bool:
    return any(pattern.search(text) for pattern in PAID_EXECUTION_PATTERNS)


def _referenced_paid_scripts(root: Path, text: str, max_depth: int = 4) -> tuple[Path, ...]:
    queue: list[tuple[str, int]] = [(match.group(1), 0) for match in SCRIPT_REFERENCE_PATTERN.finditer(text)]
    seen: set[Path] = set()
    paid: list[Path] = []

    while queue:
        raw, depth = queue.pop(0)
        candidate = (root / raw.lstrip("./")).resolve()
        try:
            candidate.relative_to(root.resolve())
        except ValueError:
            continue
        if candidate in seen or not candidate.is_file():
            continue
        seen.add(candidate)
        body = candidate.read_text(encoding="utf-8-sig", errors="replace")
        relative = candidate.relative_to(root.resolve()).as_posix()
        is_non_runtime = relative.startswith(SAFE_NON_RUNTIME_PREFIXES)
        if not is_non_runtime and relative not in SAFE_AUDIT_SCRIPT_PATHS and _uses_paid_ai(body):
            paid.append(candidate)
        if depth < max_depth:
            queue.extend((match.group(1), depth + 1) for match in SCRIPT_REFERENCE_PATTERN.finditer(body))

    return tuple(paid)


def _has_explicit_human_opt_in(text: str) -> bool:
    explicit_command = re.search(
        r"contains\s*\([^,\n]+,\s*['\"]@(?:claude|codex|gpt)['\"]\s*\)",
        text,
        re.IGNORECASE,
    ) is not None or re.search(r"\ballow_paid_ai\b", text, re.IGNORECASE) is not None
    rejects_bots = re.search(
        r"!\s*endsWith\s*\(\s*github\.actor\s*,\s*['\"]\[bot\]['\"]\s*\)",
        text,
        re.IGNORECASE,
    ) is not None
    return explicit_command and rejects_bots


def scan_repository(root: Path) -> list[Violation]:
    violations: list[Violation] = []
    workflows = root / ".github" / "workflows"
    if not workflows.is_dir():
        return violations

    for path in sorted((*workflows.glob("*.yml"), *workflows.glob("*.yaml"))):
        if path.name.endswith(".disabled"):
            continue
        text = path.read_text(encoding="utf-8-sig", errors="replace")
        paid_targets = _referenced_paid_scripts(root, text)
        if not _uses_paid_ai(text) and not paid_targets:
            continue

        target_detail = ""
        if paid_targets:
            rel_targets = ", ".join(str(path.relative_to(root)) for path in paid_targets)
            target_detail = f" via referenced paid-AI scripts: {rel_targets}"

        if re.search(r"(?m)^\s{2}schedule:\s*$", text):
            violations.append(
                Violation(path, "scheduled_paid_ai", "paid AI may not run from a schedule" + target_detail)
            )

        if any(pattern.search(text) for pattern in BROAD_EVENT_PATTERNS) and not _has_explicit_human_opt_in(text):
            violations.append(
                Violation(
                    path,
                    "broad_paid_ai_event",
                    "paid AI on broad GitHub events requires explicit human opt-in and bot filtering" + target_detail,
                )
            )

    return violations


def main() -> int:
    root = Path(__file__).resolve().parents[1]
    violations = scan_repository(root)
    if not violations:
        print("OK recurring AI policy: no scheduled/broad paid-AI workflow consumers")
        return 0

    for violation in violations:
        rel = violation.path.relative_to(root)
        print(f"ERROR {violation.code}: {rel}: {violation.message}", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
