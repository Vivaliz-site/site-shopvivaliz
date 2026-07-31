#!/usr/bin/env python3
"""Verify the critical repository reorganization contract and emit evidence."""
from __future__ import annotations

import json
import re
import subprocess
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REPORT_DIR = ROOT / "artifacts" / "reorganization-final"

REQUIRED_CANONICAL = (
    "scripts/maintenance/audit_automation_changes.py",
    "scripts/maintenance/audit_active_workflows.py",
    "scripts/maintenance/system_health_check.py",
    "scripts/maintenance/finalize_reorganization.py",
    "scripts/ai/retired_executor.py",
    "scripts/ai/autonomous_executor.py",
    "scripts/ai/continuous_executor.py",
    "scripts/ai/parallel_executor.py",
    "scripts/marketplace/olist/sync_master.py",
    "scripts/marketplace/olist/oauth_login.py",
    ".github/workflows/repository-governance.yml",
    ".github/workflows/agents-hourly-deep-audit.yml",
    "docs/knowledge/repository-index.md",
    "docs/knowledge/structure-policy.md",
    "docs/audits/repository-cleanup-backlog.md",
    "docs/audits/reorganization-final-report.md",
)

WRAPPERS = {
    "scripts/system-health-check.py": "scripts/maintenance/system_health_check.py",
    "scripts/autonomous-executor.py": "scripts/ai/autonomous_executor.py",
    "scripts/continuous-executor.py": "scripts/ai/continuous_executor.py",
    "scripts/parallel-executor.py": "scripts/ai/parallel_executor.py",
    "scripts/olist-sync-master.py": "scripts/marketplace/olist/sync_master.py",
    "scripts/olist-oauth-login.py": "scripts/marketplace/olist/oauth_login.py",
}

FORBIDDEN_FILES = (
    ".github/workflows/repository-safe-migration-push.yml",
)

TEXT_SUFFIXES = {
    ".py", ".php", ".js", ".ts", ".tsx", ".jsx", ".json", ".yml", ".yaml",
    ".md", ".txt", ".env", ".example", ".ini", ".conf", ".sh", ".sql", ".ps1",
}

CREDENTIAL_PATTERNS = (
    re.compile(r"gh[pousr]_[A-Za-z0-9_]{20,}"),
    re.compile(r"github_pat_[A-Za-z0-9_]{40,}"),
    re.compile(r"sk-(?:proj-)?[A-Za-z0-9_-]{24,}"),
    re.compile(r"xox[baprs]-[A-Za-z0-9-]{20,}"),
    re.compile(r"-----BEGIN (?:RSA |OPENSSH |EC |DSA |)PRIVATE KEY-----"),
    re.compile(
        r"(?i)\b(?:token|secret|api[_ -]?key|client[_ -]?secret)\b"
        r"[^\n]{0,32}[:=]\s*[`'\"]?([A-Fa-f0-9]{32,})\b"
    ),
)


@dataclass(frozen=True)
class Finding:
    severity: str
    rule: str
    path: str
    message: str


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8", errors="replace")


def tracked_text_files() -> list[Path]:
    result = subprocess.run(
        ["git", "ls-files", "-z"],
        cwd=ROOT,
        check=True,
        capture_output=True,
    )
    files: list[Path] = []
    for raw in result.stdout.split(b"\0"):
        if not raw:
            continue
        relative = raw.decode("utf-8", errors="replace")
        path = ROOT / relative
        if path.is_file() and (path.suffix.lower() in TEXT_SUFFIXES or path.name in {"Dockerfile", "Makefile"}):
            files.append(path)
    return files


def evaluate() -> tuple[list[Finding], dict[str, object]]:
    findings: list[Finding] = []
    checks: dict[str, object] = {}

    for relative in REQUIRED_CANONICAL:
        exists = (ROOT / relative).is_file()
        checks[f"required:{relative}"] = exists
        if not exists:
            findings.append(Finding("critical", "missing_canonical_file", relative, "Required canonical file is missing."))

    for legacy, target in WRAPPERS.items():
        legacy_path = ROOT / legacy
        target_path = ROOT / target
        valid = False
        if legacy_path.is_file() and target_path.is_file():
            text = legacy_path.read_text(encoding="utf-8", errors="replace")
            valid = "runpy.run_path" in text and Path(target).name in text
        checks[f"wrapper:{legacy}"] = valid
        if not valid:
            findings.append(Finding("high", "invalid_compatibility_wrapper", legacy, f"Wrapper must delegate to {target}."))

    for relative in FORBIDDEN_FILES:
        absent = not (ROOT / relative).exists()
        checks[f"forbidden_absent:{relative}"] = absent
        if not absent:
            findings.append(Finding("critical", "automatic_migration_workflow_present", relative, "Automatic repository migration workflow must remain absent."))

    hourly = ROOT / ".github/workflows/agents-hourly-deep-audit.yml"
    if hourly.is_file():
        text = read(".github/workflows/agents-hourly-deep-audit.yml")
        scheduled = "17 * * * *" in text
        read_only = "contents: read" in text and "contents: write" not in text
        required_artifact = "if-no-files-found: error" in text
        checks["hourly_schedule_minute_17"] = scheduled
        checks["hourly_read_only"] = read_only
        checks["hourly_required_artifact"] = required_artifact
        if not scheduled:
            findings.append(Finding("critical", "hourly_schedule_missing", hourly.relative_to(ROOT).as_posix(), "Hourly audit must run at minute 17."))
        if not read_only:
            findings.append(Finding("critical", "hourly_write_permission", hourly.relative_to(ROOT).as_posix(), "Hourly audit must remain read-only."))
        if not required_artifact:
            findings.append(Finding("high", "hourly_artifact_optional", hourly.relative_to(ROOT).as_posix(), "Hourly evidence must be mandatory."))

    shopee = ROOT / ".github/workflows/shopee-production-seo.yml"
    if shopee.is_file():
        text = read(".github/workflows/shopee-production-seo.yml")
        manual_only = "workflow_dispatch:" in text and "\n  push:" not in text
        checks["shopee_manual_only"] = manual_only
        if not manual_only:
            findings.append(Finding("critical", "shopee_automatic_trigger", shopee.relative_to(ROOT).as_posix(), "Shopee production workflow must be manual-only."))

    scanned = 0
    for path in tracked_text_files():
        scanned += 1
        text = path.read_text(encoding="utf-8", errors="replace")
        for pattern in CREDENTIAL_PATTERNS:
            if pattern.search(text):
                relative = path.relative_to(ROOT).as_posix()
                findings.append(Finding("critical", "credential_like_value", relative, "Tracked text contains a credential-like value; revoke it and use protected secrets."))
                break
    checks["credential_scan_file_count"] = scanned

    root_legacy_docs = sorted(
        path.name for path in ROOT.iterdir()
        if path.is_file() and path.suffix.lower() in {".md", ".txt"}
        and path.name not in {"README.md", "LICENSE", "CHANGELOG.md", "CONTRIBUTING.md", "SECURITY.md"}
    )
    checks["legacy_root_document_count"] = len(root_legacy_docs)
    checks["legacy_root_documents_sample"] = root_legacy_docs[:20]
    return findings, checks


def write_report(findings: list[Finding], checks: dict[str, object]) -> None:
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    payload = {
        "schema_version": 1,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "status": "success" if not findings else "failure",
        "blocking_finding_count": len(findings),
        "checks": checks,
        "findings": [asdict(item) for item in findings],
        "scope_note": "Legacy root documents are reported as non-runtime historical inventory; critical automation, credentials and canonical paths are blocking.",
    }
    (REPORT_DIR / "report.json").write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    lines = [
        "# Final repository reorganization verification",
        "",
        f"- Status: **{payload['status']}**",
        f"- Blocking findings: **{len(findings)}**",
        f"- Tracked text files scanned for credentials: **{checks['credential_scan_file_count']}**",
        f"- Legacy root documents inventoried: **{checks['legacy_root_document_count']}**",
        "",
    ]
    if findings:
        lines.extend(["## Blocking findings", ""])
        for item in findings:
            lines.append(f"- **{item.severity.upper()} / {item.rule}** `{item.path}` — {item.message}")
    else:
        lines.append("All critical reorganization checks passed.")
    (REPORT_DIR / "report.md").write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    findings, checks = evaluate()
    write_report(findings, checks)
    print(json.dumps({
        "status": "success" if not findings else "failure",
        "blocking_finding_count": len(findings),
        "findings": [asdict(item) for item in findings],
    }, indent=2, ensure_ascii=False))
    return 1 if findings else 0


if __name__ == "__main__":
    raise SystemExit(main())
