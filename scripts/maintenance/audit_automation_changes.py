#!/usr/bin/env python3
"""Audit newly introduced automation changes and fail closed on high risk."""
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
AUDITOR_IMPLEMENTATIONS = {
    "scripts/maintenance/audit_automation_changes.py",
    "scripts/audit-agents-real-work.py",
    "scripts/maintenance/audit_active_workflows.py",
    "scripts/maintenance/audit_secret_references.py",
}
CRITICAL_WORKFLOWS = {
    ".github/workflows/agents-hourly-deep-audit.yml",
    ".github/workflows/agents-audit-schedule-watchdog.yml",
    ".github/workflows/autonomous-watchdog.yml",
    ".github/workflows/repository-governance.yml",
}
AUTOMATION_PREFIXES = (
    ".github/workflows/", ".githooks/", "scripts/", ".ai/", "agents/",
    "agent-bridge/", "ai-system/", "deploy/systemd/", "api/agent/", "admin/",
)
ROOT_AUTOMATION_FILES = {
    "SETUP-COMPLETE.ps1", "RUN-ORCHESTRATOR.ps1", "tasks-queue.json",
}
TEXT_SUFFIXES = {
    ".py", ".php", ".js", ".ts", ".tsx", ".jsx", ".sh", ".ps1",
    ".yml", ".yaml", ".json", ".md", ".txt", ".service", ".timer",
}
SECRET_PATTERNS = (
    re.compile(r"gh[pousr]_[A-Za-z0-9_]{20,}"),
    re.compile(r"github_pat_[A-Za-z0-9_]{40,}"),
    re.compile(r"(?<![A-Za-z0-9])sk-(?:proj-)?[A-Za-z0-9_-]{24,}(?![A-Za-z0-9])"),
    re.compile(r"xox[baprs]-[A-Za-z0-9-]{20,}"),
    re.compile(r"-----BEGIN (?:RSA |OPENSSH |EC |DSA |)PRIVATE KEY-----"),
    re.compile(r"eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}"),
)
HUNK = re.compile(r"^@@\s+-\d+(?:,\d+)?\s+\+(\d+)(?:,(\d+))?\s+@@")
SIMULATION = re.compile(
    r"(?:['\"]?simulated['\"]?\s*:\s*true|"
    r"status\s*[:=]\s*['\"](?:simulated|mock|fake)['\"]|"
    r"(?:fake|mock)\s+(?:success|execution|result))",
    re.I,
)
COMPLETION = re.compile(
    r"(?:status|state)[^\n:=]{0,32}[:=]\s*['\"]?"
    r"(?:completed|concluido|concluído|success|ok)\b|"
    r"completed_with_evidence\b|(?:success|ok)\s*:\s*true\b",
    re.I,
)
QUEUE_CONTEXT = re.compile(
    r"(?:task|queue|fila|last_result|completed_at|assigned_to|in_progress)", re.I,
)
QUEUE_MUTATION = re.compile(
    r"(?:\[\s*['\"]status['\"]\s*\]\s*=\s*['\"]"
    r"(?:in_progress|processing|completed|done|success)['\"]|"
    r"\[\s*['\"]assigned_to['\"]\s*\]\s*=)",
    re.I,
)
PRINTED_EXECUTION = re.compile(r"(?:Executando comando|Executing command|comando de teste)", re.I)
EXECUTION_CALL = re.compile(
    r"(?:subprocess\.(?:run|Popen|check_output)|run_shell\(|exec\(|shell_exec\(|proc_open\()",
    re.I,
)
PRIVATE_PERMISSION = re.compile(
    r"(?:(?:chmod|-m)\s+(?:0?770|2770|0?777)[^\n]*"
    r"(?:private|token|secret|credential|\.env)|"
    r"(?:private|token|secret|credential|\.env)[^\n]*"
    r"(?:chmod|-m)\s+(?:0?770|2770|0?777))",
    re.I,
)
EVIDENCE_WORDS = (
    "artifact", "evidence", "commit_sha", "pull_request", "pr_url",
    "test_report", "run_id", "read-back", "readback", "verification",
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
        ["git", *args], cwd=ROOT, text=True, encoding="utf-8",
        errors="replace", capture_output=True, check=check,
    )


def changed_files(base: str, head: str) -> list[tuple[str, str]]:
    output = run_git("diff", "--name-status", "--find-renames", base, head).stdout
    entries: list[tuple[str, str]] = []
    for raw in output.splitlines():
        parts = raw.split("\t")
        if parts:
            entries.append((parts[0][0], parts[-1]))
    return entries


def added_lines(base: str, head: str, path: str) -> list[tuple[int, str]]:
    output = run_git(
        "diff", "--unified=0", "--no-ext-diff", base, head, "--", path,
        check=False,
    ).stdout
    current: int | None = None
    additions: list[tuple[int, str]] = []
    for raw in output.splitlines():
        match = HUNK.match(raw)
        if match:
            current = int(match.group(1))
            continue
        if current is None:
            continue
        if raw.startswith("+") and not raw.startswith("+++"):
            additions.append((current, raw[1:]))
            current += 1
        elif raw.startswith("-") and not raw.startswith("---"):
            continue
        elif not raw.startswith("\\"):
            current += 1
    return additions


def redact(text: str) -> str:
    result = text.strip()
    for pattern in SECRET_PATTERNS:
        result = pattern.sub("<REDACTED_SECRET>", result)
    return result[:240]


def line_of(additions: list[tuple[int, str]], match: re.Match[str]) -> int | None:
    needle = match.group(0).lower()
    return next((line for line, text in additions if needle in text.lower()), None)


def operational_path(path: str) -> bool:
    return path.startswith(AUTOMATION_PREFIXES) or path in ROOT_AUTOMATION_FILES


def append(
    findings: list[Finding], severity: str, rule: str, path: str,
    line: int | None, message: str, excerpt: str,
) -> None:
    findings.append(Finding(severity, rule, path, line, message, redact(excerpt)))


def scan_file(base: str, head: str, status: str, path: str) -> list[Finding]:
    if status == "D" and path in CRITICAL_WORKFLOWS:
        return [Finding(
            "critical", "critical_workflow_removed", path, None,
            "A required audit/governance workflow was removed.", "deleted",
        )]
    if Path(path).suffix.lower() not in TEXT_SUFFIXES and Path(path).name not in {"Dockerfile", "Makefile"}:
        return []

    additions = added_lines(base, head, path)
    if not additions:
        return []
    text = "\n".join(value for _, value in additions)
    lower = text.lower()
    findings: list[Finding] = []

    for line, value in additions:
        if any(pattern.search(value) for pattern in SECRET_PATTERNS):
            append(
                findings, "critical", "credential_exposed", path, line,
                "A credential-like value was introduced in tracked text.", value,
            )

    # Auditor source contains detector regexes and controlled probes. Unit tests
    # validate its behavior; literal secret scanning above still applies.
    if path in AUDITOR_IMPLEMENTATIONS:
        return findings
    if Path(path).suffix.lower() == ".md" or not operational_path(path):
        return findings

    line_rules = (
        ("critical", "auto_merge", re.compile(r"gh\s+pr\s+merge\b[^\n]*--auto|enable_auto_merge\s*\(|auto-merge\s*:\s*true", re.I), "Auto-merge was introduced."),
        ("critical", "destructive_command", re.compile(r"git\s+reset\s+--hard\b|git\s+clean\s+-[a-z]*f[a-z]*d|git\s+checkout\s+--\s+\.|rm\s+-rf\s+/(?:\s|$)", re.I), "Destructive cleanup or reset was introduced."),
        ("high", "broad_git_add", re.compile(r"^\s*git\s+add\s+(?:-A|\.)\s*$", re.I), "Broad staging can publish unrelated changes."),
        ("critical", "protected_or_force_push", re.compile(r"git\s+push\b[^\n]*(?:--force(?:-with-lease)?|(?:HEAD:|refs/heads/)?(?:main|master)\b)", re.I), "Protected-branch or force push was introduced."),
        ("high", "fail_open", re.compile(r"^\s*set\s+\+e\s*$|continue-on-error\s*:\s*true|^\s*exit\s+0\s*$", re.I), "Fail-open behavior can mark a failed operation green."),
        ("high", "ignored_failure", re.compile(r"\|\|\s*(?:true|log\b|echo\b)|if-no-files-found\s*:\s*(?:warn|ignore)", re.I), "A command or required artifact failure is being discarded."),
    )
    audit_context = any(word in path.lower() or word in lower for word in (
        "audit", "health", "incident", "agent", "guardian", "orchestrator", "bridge", "worker",
    ))
    for line, value in additions:
        for severity, rule, pattern, message in line_rules:
            if pattern.search(value) and (rule not in {"fail_open", "ignored_failure"} or audit_context):
                append(findings, severity, rule, path, line, message, value)

    simulation = SIMULATION.search(text)
    completion = COMPLETION.search(text)
    evidence = any(word in lower for word in EVIDENCE_WORDS)
    queue_context = QUEUE_CONTEXT.search(text)
    queue_mutation = QUEUE_MUTATION.search(text)
    retired_blocker = (
        re.search(r"['\"]status['\"]\s*:\s*['\"]blocked['\"]", text, re.I)
        and re.search(r"['\"]queue_modified['\"]\s*:\s*(?:false|False)", text, re.I)
        and ("reason" in lower or "replacement" in lower)
    )

    if simulation and completion:
        append(
            findings, "critical", "simulated_completion", path,
            line_of(additions, simulation),
            "Simulation and successful completion were introduced together.",
            simulation.group(0),
        )
    if completion and queue_context and not evidence and not retired_blocker:
        append(
            findings, "high", "queue_completion_without_evidence", path,
            line_of(additions, completion),
            "Queue/task completion lacks verifiable evidence fields.",
            completion.group(0),
        )
    if queue_mutation and not evidence:
        append(
            findings, "critical", "queue_mutation_without_evidence", path,
            line_of(additions, queue_mutation),
            "Queue state or assignment changes before verifiable evidence exists.",
            queue_mutation.group(0),
        )

    printed = PRINTED_EXECUTION.search(text)
    if printed and not EXECUTION_CALL.search(text):
        append(
            findings, "high", "printed_execution_without_execution", path,
            line_of(additions, printed),
            "A command is described as executing without a command runner.",
            printed.group(0),
        )

    web_mutation = (
        re.search(r"raw\.githubusercontent\.com[^\n]*(?:/main/|/master/)", text, re.I)
        or (
            path.startswith(("admin/", "api/"))
            and "file_put_contents(" in lower
            and "raw.githubusercontent.com" in lower
        )
        or (
            path.startswith(("admin/", "api/"))
            and ("git fetch" in lower or "git merge --ff-only" in lower)
            and ("exec(" in lower or "shell_exec(" in lower)
        )
    )
    if web_mutation:
        match = web_mutation if isinstance(web_mutation, re.Match) else None
        append(
            findings, "critical", "web_triggered_code_mutation", path,
            line_of(additions, match) if match else None,
            "A web endpoint can overwrite or update executable production code.",
            match.group(0) if match else "web code mutation",
        )

    permission = PRIVATE_PERMISSION.search(text)
    if permission:
        append(
            findings, "critical", "unsafe_private_directory_permission", path,
            line_of(additions, permission),
            "Broad write permission was introduced on private or credential state.",
            permission.group(0),
        )

    if path.startswith(".github/workflows/"):
        write_permission = re.search(r"(?:contents|issues|pull-requests|actions)\s*:\s*write", text, re.I)
        automatic = re.search(r"^\s{0,4}(?:push|schedule|workflow_run|issues)\s*:", text, re.I | re.M)
        mutation = re.search(r"git\s+push|gh\s+pr\s+merge|gh\s+issue\s+(?:edit|close)", text, re.I)
        if write_permission and automatic and mutation:
            append(
                findings, "critical", "automatic_write_workflow", path, None,
                "An automatic workflow combines write permissions and mutation.",
                "write permissions + automatic trigger + mutation",
            )
    return findings


def write_report(report_dir: Path, payload: dict) -> None:
    report_dir.mkdir(parents=True, exist_ok=True)
    (report_dir / "report.json").write_text(
        json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    lines = [
        "# Repository automation change audit", "",
        f"- Generated: `{payload['generated_at']}`",
        f"- Base: `{payload['base']}`", f"- Head: `{payload['head']}`",
        f"- Changed files: **{payload['changed_file_count']}**",
        f"- Blocking findings: **{payload['blocking_finding_count']}**", "",
    ]
    if payload["findings"]:
        lines.extend(["## Findings", ""])
        for item in payload["findings"]:
            location = f"{item['path']}:{item['line']}" if item["line"] else item["path"]
            lines.append(
                f"- **{item['severity'].upper()} / {item['rule']}** `{location}` — "
                f"{item['message']} Evidence: `{item['excerpt']}`"
            )
    else:
        lines.append("No new blocking automation regression was detected.")
    (report_dir / "report.md").write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
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
    order = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    findings.sort(key=lambda item: (order.get(item.severity, 9), item.path, item.line or 0, item.rule))
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
