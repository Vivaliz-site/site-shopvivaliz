#!/usr/bin/env python3
"""Fail-closed audit for new automation and agent changes.

Only lines introduced between an explicit base and head are treated as new
regressions. Existing debt remains visible in the repository backlog without
making every unrelated pull request impossible to merge.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_REPORT_DIR = ROOT / "artifacts" / "repository-governance"
SELF_PATH = "scripts/maintenance/audit_automation_changes.py"
TEXT_SUFFIXES = {
    ".py", ".php", ".js", ".ts", ".tsx", ".jsx", ".sh", ".ps1",
    ".yml", ".yaml", ".json", ".md", ".txt",
}
AUTOMATION_PREFIXES = (".github/workflows/", "scripts/", ".ai/", "agents/", "config/")
AUDIT_WORDS = ("audit", "auditoria", "health", "incident", "agent", "agente", "governance", "hygiene")
EVIDENCE_WORDS = ("artifact", "evidence", "commit_sha", "pull_request", "pr_url", "test_report", "run_id", "read-back", "readback")

LINE_RULES: tuple[tuple[str, str, re.Pattern[str], str], ...] = (
    ("critical", "auto_merge", re.compile(r"(?:gh\s+pr\s+merge\b[^\n]*--auto|enable_auto_merge\s*\(|auto-merge\s*:\s*true)", re.I), "Auto-merge was introduced."),
    ("critical", "destructive_command", re.compile(r"(?:git\s+reset\s+--hard\b|git\s+clean\s+-[a-z]*f[a-z]*d|git\s+checkout\s+--\s+\.|rm\s+-rf\s+/(?:\s|$))", re.I), "Destructive cleanup/reset command was introduced."),
    ("high", "broad_git_add", re.compile(r"^\s*git\s+add\s+(?:-A|\.)\s*$", re.I), "Broad staging can publish unrelated changes."),
    ("critical", "protected_or_force_push", re.compile(r"git\s+push\b[^\n]*(?:--force(?:-with-lease)?|\b(?:main|master)\b)", re.I), "Push to a protected branch or force-push was introduced."),
    ("high", "workflow_push", re.compile(r"^\s*git\s+push\b", re.I), "Workflow/script push requires an explicitly scoped, reviewed publication path."),
    ("high", "fail_open", re.compile(r"(?:^\s*set\s+\+e\s*$|continue-on-error\s*:\s*true|^\s*exit\s+0\s*$)", re.I), "Fail-open behavior can mark a failed operation green."),
    ("high", "ignored_failure", re.compile(r"(?:\|\|\s*true\s*$|2>/dev/null\s*\|\|\s*true)", re.I), "A command failure is being discarded."),
    ("high", "weak_artifact", re.compile(r"if-no-files-found\s*:\s*warn", re.I), "Required evidence must fail when missing."),
)

SECRET_PATTERNS: tuple[re.Pattern[str], ...] = (
    re.compile(r"gh[pousr]_[A-Za-z0-9_]{20,}"),
    re.compile(r"github_pat_[A-Za-z0-9_]{40,}"),
    re.compile(r"sk-(?:proj-)?[A-Za-z0-9_-]{24,}"),
    re.compile(r"xox[baprs]-[A-Za-z0-9-]{20,}"),
    re.compile(r"-----BEGIN (?:RSA |OPENSSH |EC |DSA |)PRIVATE KEY-----"),
    re.compile(r"eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}"),
)

HUNK = re.compile(r"^@@\s+-\d+(?:,\d+)?\s+\+(\d+)(?:,(\d+))?\s+@@")
COMPLETION_PATTERN = re.compile(
    r"(?:status|state)[^\n:=]{0,32}[:=]\s*['\"]?(?:completed|concluido|concluído|success)"
    r"|completed_with_evidence|success\s*:\s*true",
    re.I,
)
QUEUE_PATTERN = re.compile(r"(?:task|queue|fila|last_result|completed_at)", re.I)
SIMULATION_PATTERN = re.compile(
    r"\b(?:simulate|simulated|simulation|simular|simulacao|simulação|fake success|mock success)\b",
    re.I,
)


@dataclass(frozen=True)
class Finding:
    severity: str
    rule: str
    path: str
    line: int | None
    message: str
    excerpt: str


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["git", *args], cwd=ROOT, text=True, encoding="utf-8", errors="replace",
        capture_output=True, check=check,
    )


def changed_files(base: str, head: str) -> list[tuple[str, str]]:
    result = run_git("diff", "--name-status", "--find-renames", base, head)
    entries: list[tuple[str, str]] = []
    for raw in result.stdout.splitlines():
        parts = raw.split("\t")
        if parts:
            entries.append((parts[0][0], parts[-1]))
    return entries


def added_lines(base: str, head: str, path: str) -> list[tuple[int, str]]:
    result = run_git("diff", "--unified=0", "--no-ext-diff", base, head, "--", path, check=False)
    current_line: int | None = None
    additions: list[tuple[int, str]] = []
    for line in result.stdout.splitlines():
        match = HUNK.match(line)
        if match:
            current_line = int(match.group(1))
            continue
        if current_line is None:
            continue
        if line.startswith("+") and not line.startswith("+++"):
            additions.append((current_line, line[1:]))
            current_line += 1
        elif line.startswith("-") and not line.startswith("---"):
            continue
        elif not line.startswith("\\"):
            current_line += 1
    return additions


def redact(text: str) -> str:
    value = text.strip()
    for pattern in SECRET_PATTERNS:
        value = pattern.sub("<REDACTED_SECRET>", value)
    return value[:240]


def line_for_match(additions: list[tuple[int, str]], match: re.Match[str]) -> int | None:
    needle = match.group(0).lower()
    return next((number for number, text in additions if needle in text.lower()), None)


def scan_file(base: str, head: str, status: str, path: str) -> list[Finding]:
    findings: list[Finding] = []
    lower_path = path.lower()

    if status == "D" and path == ".github/workflows/agents-hourly-deep-audit.yml":
        return [Finding("critical", "hourly_audit_removed", path, None, "The hourly deep agent audit workflow was removed.", "deleted")]

    suffix = Path(path).suffix.lower()
    if suffix not in TEXT_SUFFIXES and Path(path).name not in {"Dockerfile", "Makefile"}:
        return findings

    additions = added_lines(base, head, path)
    if not additions or path == SELF_PATH:
        return findings

    added_text = "\n".join(text for _, text in additions)
    added_lower = added_text.lower()

    for line_no, text in additions:
        if any(pattern.search(text) for pattern in SECRET_PATTERNS):
            findings.append(Finding("critical", "credential_exposed", path, line_no, "A credential-like value was introduced in tracked text.", redact(text)))

    if not path.startswith(AUTOMATION_PREFIXES):
        return findings

    for line_no, text in additions:
        for severity, rule, pattern, message in LINE_RULES:
            if not pattern.search(text):
                continue
            if rule == "workflow_push" and "ALLOW_SCOPED_PUSH" in added_text and not re.search(r"\b(?:main|master)\b|--force", text, re.I):
                continue
            if rule in {"fail_open", "ignored_failure"} and not any(word in lower_path or word in added_lower for word in AUDIT_WORDS):
                continue
            findings.append(Finding(severity, rule, path, line_no, message, redact(text)))

    simulation = SIMULATION_PATTERN.search(added_text)
    completion = COMPLETION_PATTERN.search(added_text)
    evidence = any(word in added_lower for word in EVIDENCE_WORDS)
    queue_change = QUEUE_PATTERN.search(added_text)

    if simulation and completion:
        findings.append(Finding("critical", "simulated_completion", path, line_for_match(additions, simulation), "Simulation and successful completion were introduced in the same automation.", redact(simulation.group(0))))
    if completion and queue_change and not evidence:
        findings.append(Finding("high", "queue_completion_without_evidence", path, line_for_match(additions, completion), "Queue/task completion was introduced without verifiable evidence fields.", redact(completion.group(0))))

    if lower_path.startswith(".github/workflows/"):
        write_permission = re.search(r"(?:contents|issues|pull-requests|actions)\s*:\s*write", added_text, re.I)
        automatic_trigger = re.search(r"^\s{0,4}(?:push|schedule|workflow_run|issues)\s*:", added_text, re.I | re.M)
        mutation = re.search(r"(?:git\s+push|gh\s+pr\s+merge|gh\s+issue\s+(?:edit|close)|curl\b[^\n]*-(?:X|d)\s*(?:POST|PUT|PATCH|DELETE))", added_text, re.I)
        if write_permission and automatic_trigger and mutation:
            findings.append(Finding("critical", "automatic_write_workflow", path, None, "An automatically triggered workflow combines write permissions with mutation commands.", "write permissions + automatic trigger + mutation"))

    return findings


def write_report(report_dir: Path, payload: dict) -> None:
    report_dir.mkdir(parents=True, exist_ok=True)
    (report_dir / "report.json").write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    lines = [
        "# Repository automation change audit", "",
        f"- Generated: `{payload['generated_at']}`",
        f"- Base: `{payload['base']}`",
        f"- Head: `{payload['head']}`",
        f"- Changed files: **{payload['changed_file_count']}**",
        f"- Blocking findings: **{payload['blocking_finding_count']}**", "",
    ]
    if payload["findings"]:
        lines.extend(["## Findings", ""])
        for item in payload["findings"]:
            location = f"{item['path']}:{item['line']}" if item["line"] else item["path"]
            lines.append(f"- **{item['severity'].upper()} / {item['rule']}** `{location}` — {item['message']} Evidence: `{item['excerpt']}`")
    else:
        lines.append("No new blocking automation regression was detected.")
    (report_dir / "report.md").write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Audit new automation changes")
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", required=True)
    parser.add_argument("--report-dir", default=str(DEFAULT_REPORT_DIR))
    args = parser.parse_args()

    entries = changed_files(args.base, args.head)
    findings = [finding for status, path in entries for finding in scan_file(args.base, args.head, status, path)]
    severity_order = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    findings.sort(key=lambda item: (severity_order.get(item.severity, 9), item.path, item.line or 0, item.rule))
    payload = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "base": args.base,
        "head": args.head,
        "changed_file_count": len(entries),
        "changed_files": [{"status": status, "path": path} for status, path in entries],
        "blocking_finding_count": sum(item.severity in {"critical", "high"} for item in findings),
        "findings": [asdict(item) for item in findings],
    }
    write_report(Path(args.report_dir), payload)
    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 1 if payload["blocking_finding_count"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
