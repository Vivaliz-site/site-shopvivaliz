#!/usr/bin/env python3
"""Fail-closed audit for newly introduced automation regressions."""
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
    ".yml", ".yaml", ".json", ".md", ".txt", ".service", ".timer",
}
AUTOMATION_PREFIXES = (
    ".github/workflows/", ".githooks/", "scripts/", ".ai/", "agents/",
    "agent-bridge/", "ai-system/", "deploy/systemd/", "api/agent/", "admin/",
)
ROOT_AUTOMATION_FILES = {
    "SETUP-COMPLETE.ps1", "RUN-ORCHESTRATOR.ps1", "tasks-queue.json",
}
CRITICAL_WORKFLOWS = {
    ".github/workflows/agents-hourly-deep-audit.yml",
    ".github/workflows/agents-audit-schedule-watchdog.yml",
    ".github/workflows/autonomous-watchdog.yml",
    ".github/workflows/repository-governance.yml",
}
AUDIT_WORDS = (
    "audit", "health", "incident", "agent", "guardian", "orchestrator",
    "bridge", "worker",
)
EVIDENCE_WORDS = (
    "artifact", "evidence", "commit_sha", "pull_request", "pr_url",
    "test_report", "run_id", "read-back", "readback", "verification",
)

LINE_RULES: tuple[tuple[str, str, re.Pattern[str], str], ...] = (
    (
        "critical", "auto_merge",
        re.compile(
            r"(?:gh\s+pr\s+merge\b[^\n]*--auto|enable_auto_merge\s*\(|"
            r"auto-merge\s*:\s*true)", re.I,
        ),
        "Auto-merge was introduced.",
    ),
    (
        "critical", "destructive_command",
        re.compile(
            r"(?:git\s+reset\s+--hard\b|git\s+clean\s+-[a-z]*f[a-z]*d|"
            r"git\s+checkout\s+--\s+\.|rm\s+-rf\s+/(?:\s|$))", re.I,
        ),
        "Destructive cleanup or reset was introduced.",
    ),
    (
        "high", "broad_git_add",
        re.compile(r"^\s*git\s+add\s+(?:-A|\.)\s*$", re.I),
        "Broad staging can publish unrelated changes.",
    ),
    (
        "critical", "protected_or_force_push",
        re.compile(
            r"git\s+push\b[^\n]*(?:--force(?:-with-lease)?|"
            r"(?:HEAD:|refs/heads/)?(?:main|master)\b)", re.I,
        ),
        "Protected-branch or force push was introduced.",
    ),
    (
        "high", "fail_open",
        re.compile(
            r"(?:^\s*set\s+\+e\s*$|continue-on-error\s*:\s*true|"
            r"^\s*exit\s+0\s*$)", re.I,
        ),
        "Fail-open behavior can mark a failed operation green.",
    ),
    (
        "high", "ignored_failure",
        re.compile(
            r"(?:\|\|\s*(?:true|log\b|echo\b)|"
            r"if-no-files-found\s*:\s*(?:warn|ignore))", re.I,
        ),
        "A command or required artifact failure is being discarded.",
    ),
)

SECRET_PATTERNS: tuple[re.Pattern[str], ...] = (
    re.compile(r"gh[pousr]_[A-Za-z0-9_]{20,}"),
    re.compile(r"github_pat_[A-Za-z0-9_]{40,}"),
    re.compile(
        r"(?<![A-Za-z0-9])sk-(?:proj-)?[A-Za-z0-9_-]{24,}(?![A-Za-z0-9])"
    ),
    re.compile(r"xox[baprs]-[A-Za-z0-9-]{20,}"),
    re.compile(r"-----BEGIN (?:RSA |OPENSSH |EC |DSA |)PRIVATE KEY-----"),
    re.compile(
        r"eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\."
        r"[A-Za-z0-9_-]{10,}"
    ),
)

HUNK = re.compile(r"^@@\s+-\d+(?:,\d+)?\s+\+(\d+)(?:,(\d+))?\s+@@")
COMPLETION_PATTERN = re.compile(
    r"(?:status|state)[^\n:=]{0,32}[:=]\s*['\"]?"
    r"(?:completed|concluido|concluído|success|ok)\b"
    r"|completed_with_evidence\b"
    r"|(?:success|ok)\s*:\s*true\b",
    re.I,
)
QUEUE_PATTERN = re.compile(
    r"(?:task|queue|fila|last_result|completed_at|assigned_to|in_progress)",
    re.I,
)
QUEUE_MUTATION_PATTERN = re.compile(
    r"(?:\[\s*['\"]status['\"]\s*\]\s*=\s*['\"]"
    r"(?:in_progress|processing|completed|done|success)['\"]|"
    r"\[\s*['\"]assigned_to['\"]\s*\]\s*=)",
    re.I,
)
RETIRED_BLOCKER_PATTERN = re.compile(
    r"['\"]status['\"]\s*:\s*['\"]blocked['\"]",
    re.I,
)
QUEUE_IMMUTABLE_PATTERN = re.compile(
    r"['\"]queue_modified['\"]\s*:\s*(?:false|False)",
    re.I,
)
SIMULATION_PATTERN = re.compile(
    r"(?:['\"]simulated['\"]\s*:\s*true|"
    r"status\s*[:=]\s*['\"](?:simulated|mock|fake)['\"]|"
    r"fake\s+(?:success|execution|result)|"
    r"mock\s+(?:success|execution|result))",
    re.I,
)
PRINTED_EXECUTION_PATTERN = re.compile(
    r"(?:Executando comando|Executing command|comando de teste)", re.I,
)
EXECUTION_CALL_PATTERN = re.compile(
    r"(?:subprocess\.(?:run|Popen|check_output)|run_shell\(|exec\(|"
    r"shell_exec\(|proc_open\()", re.I,
)
WEB_MUTATION_PATTERN = re.compile(
    r"raw\.githubusercontent\.com[^\n]*(?:/main/|/master/)|"
    r"(?:git\s+fetch|git\s+merge\s+--ff-only)[\s\S]{0,500}"
    r"(?:exec\(|shell_exec\()",
    re.I,
)
UNSAFE_PRIVATE_PERMISSION_PATTERN = re.compile(
    r"(?:chmod\s+0?77[07]|\b-m\s+2?770\b|\b-m\s+0?777\b)"
    r"[^\n]*(?:private|token|secret|credential|\.env)|"
    r"(?:private|token|secret|credential|\.env)[^\n]*"
    r"(?:chmod\s+0?77[07]|\b-m\s+2?770\b|\b-m\s+0?777\b)",
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
        ["git", *args],
        cwd=ROOT,
        text=True,
        encoding="utf-8",
        errors="replace",
        capture_output=True,
        check=check,
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
    result = run_git(
        "diff", "--unified=0", "--no-ext-diff", base, head, "--", path,
        check=False,
    )
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


def line_for_match(
    additions: list[tuple[int, str]], match: re.Match[str]
) -> int | None:
    needle = match.group(0).lower()
    return next(
        (number for number, text in additions if needle in text.lower()),
        None,
    )


def is_automation_path(path: str) -> bool:
    return path.startswith(AUTOMATION_PREFIXES) or path in ROOT_AUTOMATION_FILES


def scan_file(
    base: str, head: str, status: str, path: str
) -> list[Finding]:
    findings: list[Finding] = []
    if status == "D" and path in CRITICAL_WORKFLOWS:
        return [
            Finding(
                "critical", "critical_workflow_removed", path, None,
                "A required audit/governance workflow was removed.",
                "deleted",
            )
        ]

    suffix = Path(path).suffix.lower()
    if (
        suffix not in TEXT_SUFFIXES
        and Path(path).name not in {"Dockerfile", "Makefile"}
    ):
        return findings
    additions = added_lines(base, head, path)
    if not additions or path == SELF_PATH:
        return findings

    added_text = "\n".join(text for _, text in additions)
    added_lower = added_text.lower()
    lower_path = path.lower()

    for line_no, text in additions:
        if any(pattern.search(text) for pattern in SECRET_PATTERNS):
            findings.append(
                Finding(
                    "critical", "credential_exposed", path, line_no,
                    "A credential-like value was introduced in tracked text.",
                    redact(text),
                )
            )

    if suffix == ".md" or not is_automation_path(path):
        return findings

    for line_no, text in additions:
        for severity, rule, pattern, message in LINE_RULES:
            if not pattern.search(text):
                continue
            if (
                rule in {"fail_open", "ignored_failure"}
                and not any(
                    word in lower_path or word in added_lower
                    for word in AUDIT_WORDS
                )
            ):
                continue
            findings.append(
                Finding(
                    severity, rule, path, line_no, message, redact(text)
                )
            )

    simulation = SIMULATION_PATTERN.search(added_text)
    completion = COMPLETION_PATTERN.search(added_text)
    evidence = any(word in added_lower for word in EVIDENCE_WORDS)
    queue_change = QUEUE_PATTERN.search(added_text)
    queue_mutation = QUEUE_MUTATION_PATTERN.search(added_text)
    retired_blocker = bool(
        RETIRED_BLOCKER_PATTERN.search(added_text)
        and QUEUE_IMMUTABLE_PATTERN.search(added_text)
        and ("reason" in added_lower or "replacement" in added_lower)
    )

    if simulation and completion:
        findings.append(
            Finding(
                "critical", "simulated_completion", path,
                line_for_match(additions, simulation),
                "Simulation and successful completion were introduced in the same automation.",
                redact(simulation.group(0)),
            )
        )
    if completion and queue_change and not evidence and not retired_blocker:
        findings.append(
            Finding(
                "high", "queue_completion_without_evidence", path,
                line_for_match(additions, completion),
                "Queue/task completion was introduced without verifiable evidence fields.",
                redact(completion.group(0)),
            )
        )
    if queue_mutation and not evidence:
        findings.append(
            Finding(
                "critical", "queue_mutation_without_evidence", path,
                line_for_match(additions, queue_mutation),
                "Queue state or assignment changes before verifiable evidence exists.",
                redact(queue_mutation.group(0)),
            )
        )

    printed = PRINTED_EXECUTION_PATTERN.search(added_text)
    if printed and not EXECUTION_CALL_PATTERN.search(added_text):
        findings.append(
            Finding(
                "high", "printed_execution_without_execution", path,
                line_for_match(additions, printed),
                "A command is described as executing without a command runner or API call.",
                redact(printed.group(0)),
            )
        )

    web_mutation = WEB_MUTATION_PATTERN.search(added_text)
    if web_mutation or (
        path.startswith(("admin/", "api/"))
        and "file_put_contents(" in added_lower
        and "raw.githubusercontent.com" in added_lower
    ):
        match = web_mutation or re.search(
            r"raw\.githubusercontent\.com", added_text, re.I
        )
        findings.append(
            Finding(
                "critical", "web_triggered_code_mutation", path,
                line_for_match(additions, match) if match else None,
                "A web endpoint can download, overwrite or update executable production code.",
                redact(match.group(0) if match else "web code mutation"),
            )
        )

    permission = UNSAFE_PRIVATE_PERMISSION_PATTERN.search(added_text)
    if permission:
        findings.append(
            Finding(
                "critical", "unsafe_private_directory_permission", path,
                line_for_match(additions, permission),
                "Broad write permission was introduced on private or credential state.",
                redact(permission.group(0)),
            )
        )

    if lower_path.startswith(".github/workflows/"):
        write_permission = re.search(
            r"(?:contents|issues|pull-requests|actions)\s*:\s*write",
            added_text,
            re.I,
        )
        automatic_trigger = re.search(
            r"^\s{0,4}(?:push|schedule|workflow_run|issues)\s*:",
            added_text,
            re.I | re.M,
        )
        mutation = re.search(
            r"(?:git\s+push|gh\s+pr\s+merge|"
            r"gh\s+issue\s+(?:edit|close)|"
            r"curl\b[^\n]*-(?:X|d)\s*(?:POST|PUT|PATCH|DELETE))",
            added_text,
            re.I,
        )
        if write_permission and automatic_trigger and mutation:
            findings.append(
                Finding(
                    "critical", "automatic_write_workflow", path, None,
                    "An automatically triggered workflow combines write permissions with mutation commands.",
                    "write permissions + automatic trigger + mutation",
                )
            )

    return findings


def write_report(report_dir: Path, payload: dict) -> None:
    report_dir.mkdir(parents=True, exist_ok=True)
    (report_dir / "report.json").write_text(
        json.dumps(payload, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    lines = [
        "# Repository automation change audit",
        "",
        f"- Generated: `{payload['generated_at']}`",
        f"- Base: `{payload['base']}`",
        f"- Head: `{payload['head']}`",
        f"- Changed files: **{payload['changed_file_count']}**",
        f"- Blocking findings: **{payload['blocking_finding_count']}**",
        "",
    ]
    if payload["findings"]:
        lines.extend(["## Findings", ""])
        for item in payload["findings"]:
            location = (
                f"{item['path']}:{item['line']}"
                if item["line"]
                else item["path"]
            )
            lines.append(
                f"- **{item['severity'].upper()} / {item['rule']}** "
                f"`{location}` — {item['message']} "
                f"Evidence: `{item['excerpt']}`"
            )
    else:
        lines.append("No new blocking automation regression was detected.")
    (report_dir / "report.md").write_text(
        "\n".join(lines) + "\n", encoding="utf-8"
    )


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Audit new automation changes"
    )
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", required=True)
    parser.add_argument("--report-dir", default=str(DEFAULT_REPORT_DIR))
    args = parser.parse_args()

    entries = changed_files(args.base, args.head)
    findings = [
        finding
        for status, path in entries
        for finding in scan_file(args.base, args.head, status, path)
    ]
    severity_order = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    findings.sort(
        key=lambda item: (
            severity_order.get(item.severity, 9),
            item.path,
            item.line or 0,
            item.rule,
        )
    )
    payload = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "base": args.base,
        "head": args.head,
        "changed_file_count": len(entries),
        "changed_files": [
            {"status": status, "path": path} for status, path in entries
        ],
        "blocking_finding_count": sum(
            item.severity in {"critical", "high"} for item in findings
        ),
        "findings": [asdict(item) for item in findings],
    }
    write_report(Path(args.report_dir), payload)
    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 1 if payload["blocking_finding_count"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
