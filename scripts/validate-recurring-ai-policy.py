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


def _uses_paid_ai(text: str) -> bool:
    return any(pattern.search(text) for pattern in PAID_EXECUTION_PATTERNS)


def _has_explicit_human_opt_in(text: str) -> bool:
    lowered = text.lower()
    has_explicit_command = any(marker in lowered for marker in ("@claude", "@codex", "@gpt", "allow_paid_ai"))
    rejects_bots = "[bot]" in lowered or "github.actor" not in lowered
    return has_explicit_command and rejects_bots


def scan_repository(root: Path) -> list[Violation]:
    violations: list[Violation] = []
    workflows = root / ".github" / "workflows"
    if not workflows.is_dir():
        return violations

    for path in sorted((*workflows.glob("*.yml"), *workflows.glob("*.yaml"))):
        if path.name.endswith(".disabled"):
            continue
        text = path.read_text(encoding="utf-8-sig", errors="replace")
        if not _uses_paid_ai(text):
            continue

        if re.search(r"(?m)^\s{2}schedule:\s*$", text):
            violations.append(
                Violation(path, "scheduled_paid_ai", "paid AI may not run from a schedule")
            )

        if any(pattern.search(text) for pattern in BROAD_EVENT_PATTERNS) and not _has_explicit_human_opt_in(text):
            violations.append(
                Violation(
                    path,
                    "broad_paid_ai_event",
                    "paid AI on broad GitHub events requires explicit human opt-in and bot filtering",
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
