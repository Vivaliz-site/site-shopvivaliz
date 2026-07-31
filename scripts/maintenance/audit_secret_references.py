#!/usr/bin/env python3
"""Audit tracked secret references without reading configured secret values.

The report inventories GitHub Actions secret names, rejects credential-like
literals, detects unsafe logging patterns and identifies legacy secret-management
scripts. Values are always redacted and GitHub Secrets are never queried.
"""
from __future__ import annotations

import json
import re
import subprocess
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REPORT_DIR = ROOT / "artifacts" / "secret-references"
SELF_PATH = "scripts/maintenance/audit_secret_references.py"
TEXT_SUFFIXES = {
    ".py", ".php", ".js", ".ts", ".tsx", ".jsx", ".sh", ".bash", ".ps1",
    ".yml", ".yaml", ".json", ".md", ".txt", ".env", ".ini", ".conf",
}
SKIP_PREFIXES = (
    ".git/", "node_modules/", "vendor/", "artifacts/", "storage/", "logs/",
    "playwright-report/", "test-results/",
)
WORKFLOW_PREFIX = ".github/workflows/"
SECRET_REFERENCE = re.compile(r"\$\{\{\s*secrets\.([A-Z][A-Z0-9_]*)\s*\}\}")
ENV_SECRET_NAME = re.compile(r"\b([A-Z][A-Z0-9_]*(?:TOKEN|SECRET|PASSWORD|PASS|PRIVATE_KEY|API_KEY|ACCESS_KEY)[A-Z0-9_]*)\b")
PLACEHOLDER_WORDS = (
    "seu-valor", "example", "exemplo", "placeholder", "changeme", "aqui",
    "removido", "redacted", "dummy", "fake", "test-token", "<", "[",
)
CREDENTIAL_PATTERNS: tuple[tuple[str, re.Pattern[str]], ...] = (
    ("github_token", re.compile(r"\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{30,})\b")),
    ("openai_key", re.compile(r"\bsk-(?:proj-)?[A-Za-z0-9_-]{24,}\b")),
    ("slack_token", re.compile(r"\bxox[baprs]-[A-Za-z0-9-]{20,}\b")),
    ("aws_access_key", re.compile(r"\bAKIA[0-9A-Z]{16}\b")),
    ("private_key", re.compile(r"-----BEGIN (?:RSA |OPENSSH |EC |DSA |)?PRIVATE KEY-----")),
    ("jwt", re.compile(r"\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\b")),
)
SECRET_COMMAND = re.compile(r"\bgh\s+secret\s+(?:set|list|remove)\b", re.I)
SHELL_TRACE = re.compile(r"(?m)^\s*set\s+-x\s*$")
OUTPUT_CALL = re.compile(r"\b(?:echo|printf|print|pprint|console\.log|logger\.(?:debug|info|warning|error))\b", re.I)
MASKING_WORD = re.compile(r"mask|redact|sanitize|censor", re.I)
PATH_REFERENCE = re.compile(r"(?<![A-Za-z0-9_.-])((?:scripts|config|\.ai|agents)/[A-Za-z0-9_./-]+\.(?:py|js|ts|php|sh|bash|ps1))")
ACTIVE_EVENTS = re.compile(
    r"(?m)^\s{2}(?:push|pull_request|schedule|issues|workflow_run|repository_dispatch|workflow_dispatch|workflow_call):"
)


@dataclass(frozen=True)
class Finding:
    severity: str
    rule: str
    path: str
    line: int | None
    message: str
    excerpt: str
    active: bool


def git_output(*args: str) -> bytes:
    return subprocess.run(["git", *args], cwd=ROOT, check=True, capture_output=True).stdout


def tracked_files() -> list[Path]:
    files: list[Path] = []
    for raw in git_output("ls-files", "-z").split(b"\0"):
        if not raw:
            continue
        rel = raw.decode("utf-8", errors="replace")
        if rel == SELF_PATH or rel.startswith(SKIP_PREFIXES):
            continue
        path = ROOT / rel
        if path.is_file() and (path.suffix.lower() in TEXT_SUFFIXES or path.name in {"Dockerfile", "Makefile"}):
            files.append(path)
    return files


def active_surfaces(files: list[Path]) -> set[str]:
    active: set[str] = set()
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        if not rel.startswith(WORKFLOW_PREFIX):
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        if not ACTIVE_EVENTS.search(text):
            continue
        active.add(rel)
        for match in PATH_REFERENCE.finditer(text):
            candidate = match.group(1).rstrip("'\"),]")
            if (ROOT / candidate).is_file():
                active.add(candidate)
    return active


def redacted(value: str) -> str:
    compact = " ".join(value.strip().split())
    if len(compact) <= 10:
        return "<REDACTED>"
    return compact[:5] + "..." + compact[-3:]


def line_number(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def is_placeholder(value: str) -> bool:
    lowered = value.lower()
    return any(word in lowered for word in PLACEHOLDER_WORDS)


def scan_file(path: Path, active: bool) -> tuple[list[Finding], set[str]]:
    rel = path.relative_to(ROOT).as_posix()
    text = path.read_text(encoding="utf-8", errors="replace")
    findings: list[Finding] = []
    references = set(SECRET_REFERENCE.findall(text))

    for rule, pattern in CREDENTIAL_PATTERNS:
        for match in pattern.finditer(text):
            value = match.group(0)
            if is_placeholder(value):
                continue
            findings.append(Finding(
                "critical", "credential_literal", rel, line_number(text, match.start()),
                f"Credential-like literal detected ({rule}).", redacted(value), active,
            ))

    for match in SHELL_TRACE.finditer(text):
        severity = "critical" if active else "medium"
        findings.append(Finding(
            severity, "shell_trace", rel, line_number(text, match.start()),
            "Shell tracing can expose environment variables and command arguments.", "set -x", active,
        ))

    lines = text.splitlines()
    for index, line in enumerate(lines, 1):
        if not OUTPUT_CALL.search(line) or MASKING_WORD.search(line):
            continue
        names = ENV_SECRET_NAME.findall(line)
        if not names:
            continue
        severity = "high" if active else "medium"
        findings.append(Finding(
            severity, "secret_output_risk", rel, index,
            "A secret-like variable is referenced by an output/logging call.",
            ", ".join(sorted(set(names))), active,
        ))

    for match in SECRET_COMMAND.finditer(text):
        severity = "high" if active else "medium"
        findings.append(Finding(
            severity, "secret_management_command", rel, line_number(text, match.start()),
            "Tracked code can mutate or enumerate repository secrets; keep it manual and reviewed.",
            match.group(0), active,
        ))

    return findings, references


def write_report(payload: dict) -> None:
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    (REPORT_DIR / "report.json").write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    lines = [
        "# Secret and token reference audit", "",
        f"- Generated: `{payload['generated_at']}`",
        f"- Files scanned: **{payload['files_scanned']}**",
        f"- GitHub Secret names referenced: **{len(payload['secret_references'])}**",
        f"- Blocking findings: **{payload['blocking_finding_count']}**", "",
        "## Referenced GitHub Secret names", "",
    ]
    lines.extend([f"- `{name}`" for name in payload["secret_references"]] or ["- None"])
    lines.extend(["", "## Findings", ""])
    if payload["findings"]:
        for item in payload["findings"]:
            location = f"{item['path']}:{item['line']}" if item["line"] else item["path"]
            scope = "ACTIVE" if item["active"] else "LEGACY/UNREFERENCED"
            lines.append(
                f"- **{item['severity'].upper()} / {item['rule']} / {scope}** "
                f"`{location}` — {item['message']} Evidence: `{item['excerpt']}`"
            )
    else:
        lines.append("No credential literal or unsafe secret handling was detected.")
    lines.extend([
        "", "## Scope limitation", "",
        "This audit verifies tracked references and handling patterns only. It never reads GitHub Secret values.",
    ])
    (REPORT_DIR / "report.md").write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    files = tracked_files()
    active = active_surfaces(files)
    findings: list[Finding] = []
    references: set[str] = set()
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        file_findings, file_references = scan_file(path, rel in active)
        findings.extend(file_findings)
        references.update(file_references)

    severity_order = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    findings.sort(key=lambda item: (severity_order.get(item.severity, 9), item.path, item.line or 0))
    blocking = [item for item in findings if item.severity in {"critical", "high"}]
    payload = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "files_scanned": len(files),
        "active_surfaces": sorted(active),
        "secret_references": sorted(references),
        "blocking_finding_count": len(blocking),
        "legacy_finding_count": sum(not item.active for item in findings),
        "findings": [asdict(item) for item in findings],
    }
    write_report(payload)
    print(json.dumps(payload, ensure_ascii=False, indent=2))
    return 1 if blocking else 0


if __name__ == "__main__":
    raise SystemExit(main())
