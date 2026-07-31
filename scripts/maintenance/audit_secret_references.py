#!/usr/bin/env python3
"""Audit tracked secret references without reading configured secret values."""
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
EXPANDED_SECRET_NAME = re.compile(
    r"\$(?:\{)?([A-Z][A-Z0-9_]*(?:TOKEN|SECRET|PASSWORD|PASS|PRIVATE_KEY|API_KEY|ACCESS_KEY)[A-Z0-9_]*)(?:\})?"
)
PLACEHOLDER_WORDS = (
    "seu-valor", "example", "exemplo", "placeholder", "changeme", "aqui",
    "removido", "redacted", "dummy", "fake", "test-token", "<", "[",
)
CREDENTIAL_PATTERNS: tuple[tuple[str, re.Pattern[str]], ...] = (
    ("github_token", re.compile(r"\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{30,})\b")),
    ("openai_key", re.compile(r"\bsk-(?:proj-)?[A-Za-z0-9_-]{24,}\b")),
    ("slack_token", re.compile(r"\bxox[baprs]-[A-Za-z0-9-]{20,}\b")),
    ("aws_access_key", re.compile(r"\bAKIA[0-9A-Z]{16}\b")),
    ("jwt", re.compile(r"\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\b")),
)
PRIVATE_KEY_BLOCK = re.compile(
    r"-----BEGIN (?:RSA |OPENSSH |EC |DSA |)?PRIVATE KEY-----[\s\S]{80,}?"
    r"-----END (?:RSA |OPENSSH |EC |DSA |)?PRIVATE KEY-----"
)
SECRET_COMMAND = re.compile(r"\bgh\s+secret\s+(?:set|list|remove)\b", re.I)
SHELL_TRACE = re.compile(r"(?m)^\s*set\s+-x\s*$")
OUTPUT_CALL = re.compile(r"\b(?:echo|printf|print|pprint|console\.log|logger\.(?:debug|info|warning|error))\b", re.I)
MASKING_WORD = re.compile(r"mask|redact|sanitize|censor", re.I)
FILE_REDIRECTION = re.compile(r"(?<![>&])>\s*(?:~?/|\.?\.?/|[A-Za-z0-9_.-]+/)[^&|;]*$")
PATH_REFERENCE = re.compile(r"(?<![A-Za-z0-9_.-])((?:scripts|config|\.ai|agents)/[A-Za-z0-9_./-]+\.(?:py|js|ts|php|sh|bash|ps1))")
ACTIVE_EVENTS = re.compile(
    r"(?m)^\s{2}(?:push|pull_request|schedule|issues|workflow_run|repository_dispatch|workflow_dispatch|workflow_call):"
)
ROTATION_WORKFLOW = ".github/workflows/rotate-agent-key.yml"
ROTATION_GUARDS = (
    "permissions:\n  contents: read",
    "gh secret set SHOPVIVALIZ_AGENT_KEY --repo \"$GITHUB_REPOSITORY\" < /tmp/new_agent_key",
    "Roll back both sides on failure",
    "Verify watchdog authentication",
    "::add-mask::",
    "shred -u /tmp/new_agent_key /tmp/current_agent_key",
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


def approved_rotation(rel: str, text: str) -> bool:
    if rel != ROTATION_WORKFLOW or not all(guard in text for guard in ROTATION_GUARDS):
        return False
    commands = [line.strip() for line in text.splitlines() if SECRET_COMMAND.search(line)]
    return bool(commands) and all(
        "gh secret set SHOPVIVALIZ_AGENT_KEY" in command for command in commands
    )


def scan_file(path: Path, active: bool) -> tuple[list[Finding], set[str], list[str]]:
    rel = path.relative_to(ROOT).as_posix()
    text = path.read_text(encoding="utf-8", errors="replace")
    findings: list[Finding] = []
    references = set(SECRET_REFERENCE.findall(text))
    approved: list[str] = []

    for rule, pattern in CREDENTIAL_PATTERNS:
        for match in pattern.finditer(text):
            value = match.group(0)
            if is_placeholder(value):
                continue
            findings.append(Finding(
                "critical", "credential_literal", rel, line_number(text, match.start()),
                f"Credential-like literal detected ({rule}).", redacted(value), active,
            ))

    for match in PRIVATE_KEY_BLOCK.finditer(text):
        value = match.group(0)
        if not is_placeholder(value):
            findings.append(Finding(
                "critical", "credential_literal", rel, line_number(text, match.start()),
                "Complete private-key block detected.", "<PRIVATE KEY REDACTED>", active,
            ))

    for match in SHELL_TRACE.finditer(text):
        findings.append(Finding(
            "critical" if active else "medium", "shell_trace", rel, line_number(text, match.start()),
            "Shell tracing can expose environment variables and command arguments.", "set -x", active,
        ))

    for index, line in enumerate(text.splitlines(), 1):
        if not OUTPUT_CALL.search(line) or MASKING_WORD.search(line):
            continue
        names = EXPANDED_SECRET_NAME.findall(line)
        if not names or FILE_REDIRECTION.search(line):
            continue
        findings.append(Finding(
            "high" if active else "medium", "secret_output_risk", rel, index,
            "An expanded secret-like variable is referenced by an output/logging call.",
            ", ".join(sorted(set(names))), active,
        ))

    rotation_approved = approved_rotation(rel, text)
    for match in SECRET_COMMAND.finditer(text):
        if rotation_approved:
            approved.append(f"{rel}:{line_number(text, match.start())}:SHOPVIVALIZ_AGENT_KEY")
            continue
        findings.append(Finding(
            "high" if active else "medium", "secret_management_command", rel,
            line_number(text, match.start()),
            "Tracked code can mutate or enumerate repository secrets; keep it manual and reviewed.",
            match.group(0), active,
        ))

    return findings, references, approved


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
        f"- Approved transactional secret mutations: **{len(payload['approved_secret_mutations'])}**",
        f"- Blocking findings: **{payload['blocking_finding_count']}**", "",
        "## Referenced GitHub Secret names", "",
    ]
    lines.extend([f"- `{name}`" for name in payload["secret_references"]] or ["- None"])
    lines.extend(["", "## Approved transactional mutations", ""])
    lines.extend([f"- `{item}`" for item in payload["approved_secret_mutations"]] or ["- None"])
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
    approved: list[str] = []
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        file_findings, file_references, file_approved = scan_file(path, rel in active)
        findings.extend(file_findings)
        references.update(file_references)
        approved.extend(file_approved)

    severity_order = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    findings.sort(key=lambda item: (severity_order.get(item.severity, 9), item.path, item.line or 0))
    blocking = [item for item in findings if item.severity in {"critical", "high"}]
    payload = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "files_scanned": len(files),
        "active_surfaces": sorted(active),
        "secret_references": sorted(references),
        "approved_secret_mutations": sorted(approved),
        "blocking_finding_count": len(blocking),
        "legacy_finding_count": sum(not item.active for item in findings),
        "findings": [asdict(item) for item in findings],
    }
    write_report(payload)
    print(json.dumps(payload, ensure_ascii=False, indent=2))
    return 1 if blocking else 0


if __name__ == "__main__":
    raise SystemExit(main())
