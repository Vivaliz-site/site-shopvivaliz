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
    "scripts/marketplace/shopee/retired_credential_tool.py",
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
    "scripts/get_token.py": "scripts/marketplace/shopee/retired_credential_tool.py",
    "scripts/run_playwright.py": "scripts/marketplace/shopee/retired_credential_tool.py",
    "scripts/shopee_full_pipeline.py": "scripts/marketplace/shopee/retired_credential_tool.py",
    "scripts/test_final.py": "scripts/marketplace/shopee/retired_credential_tool.py",
    "scripts/test_shopee_simple.py": "scripts/marketplace/shopee/retired_credential_tool.py",
    "scripts/test_shopee_api.py": "scripts/marketplace/shopee/retired_credential_tool.py",
    "claude/api/shopee-integration/scripts/run_playwright.py": "scripts/marketplace/shopee/retired_credential_tool.py",
    "claude/api/shopee-integration/scripts/test_final.py": "scripts/marketplace/shopee/retired_credential_tool.py",
    "claude/api/shopee-integration/scripts/test_shopee_api.py": "scripts/marketplace/shopee/retired_credential_tool.py",
}

FORBIDDEN_FILES = (
    ".github/workflows/repository-safe-migration-push.yml",
    "storage/private/melhorenvio-tokens.json",
)

TEXT_SUFFIXES = {
    ".py", ".php", ".js", ".ts", ".tsx", ".jsx", ".json", ".yml", ".yaml",
    ".md", ".txt", ".env", ".example", ".ini", ".conf", ".sh", ".sql", ".ps1",
}

SENSITIVE_LABEL = (
    r"(?:partner[_ -]?key|app[_ -]?secret|client[_ -]?secret|api[_ -]?key|"
    r"access[_ -]?token|refresh[_ -]?token|auth(?:orization)?[_ -]?code|"
    r"webhook[_ -]?(?:secret|token)|sandbox[_ -]?(?:pass|password)|"
    r"password|token|secret)"
)

CREDENTIAL_PATTERNS: tuple[tuple[str, re.Pattern[str]], ...] = (
    ("github_classic_token", re.compile(r"(?<![A-Za-z0-9])gh[pousr]_[A-Za-z0-9_]{20,}")),
    ("github_fine_grained_token", re.compile(r"(?<![A-Za-z0-9])github_pat_[A-Za-z0-9_]{40,}")),
    ("openai_key", re.compile(r"(?<![A-Za-z0-9])sk-(?:proj-)?[A-Za-z0-9_-]{24,}")),
    ("slack_token", re.compile(r"(?<![A-Za-z0-9])xox[baprs]-[A-Za-z0-9-]{20,}")),
    ("aws_access_key", re.compile(r"(?<![A-Za-z0-9])AKIA[A-Z0-9]{16}(?![A-Za-z0-9])")),
    ("shopee_partner_key", re.compile(r"(?<![A-Za-z0-9])shpk[A-Za-z0-9]{20,}")),
    ("jwt", re.compile(r"(?<![A-Za-z0-9_-])eyJ[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{8,}")),
    (
        "private_key_block",
        re.compile(
            r"-----BEGIN (?:RSA |OPENSSH |EC |DSA |)PRIVATE KEY-----"
            r"[\s\S]{80,}?"
            r"-----END (?:RSA |OPENSSH |EC |DSA |)PRIVATE KEY-----"
        ),
    ),
    (
        "sensitive_quoted_literal",
        re.compile(
            rf"(?ix)(?:['\"]?{SENSITIVE_LABEL}['\"]?)\s*[:=]\s*"
            r"[`'\"]([A-Za-z0-9][A-Za-z0-9._:/+~-]{11,})[`'\"]"
        ),
    ),
    (
        "sensitive_markdown_literal",
        re.compile(
            rf"(?ix){SENSITIVE_LABEL}[^|\n]{{0,24}}\|\s*"
            r"[`'\"]?([A-Za-z0-9][A-Za-z0-9._:/+~-]{11,})[`'\"]?\s*\|"
        ),
    ),
    (
        "sensitive_query_literal",
        re.compile(
            r"(?i)[?&](?:token|secret|access_token|refresh_token)="
            r"([A-Za-z0-9][A-Za-z0-9._~-]{11,})"
        ),
    ),
    (
        "sensitive_unquoted_hex",
        re.compile(
            rf"(?ix)\b{SENSITIVE_LABEL}\b[^\n]{{0,32}}(?:[:=]|\|)\s*"
            r"[`'\"]?([A-Fa-f0-9]{32,})\b"
        ),
    ),
)

PLACEHOLDER_PATTERNS = (
    re.compile(r"^<[^>]+>$"),
    re.compile(r"^\$\{[^}]+\}$"),
    re.compile(r"^\{\{[^}]+\}\}$"),
)


@dataclass(frozen=True)
class Finding:
    severity: str
    rule: str
    path: str
    message: str
    line: int | None = None
    pattern: str | None = None


def read(relative: str) -> str:
    return (ROOT / relative).read_text(encoding="utf-8", errors="replace")


def line_number(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def likely_placeholder(value: str) -> bool:
    normalized = value.strip().strip("`'\"")
    lowered = normalized.lower()
    if any(pattern.fullmatch(normalized) for pattern in PLACEHOLDER_PATTERNS):
        return True
    if any(word in lowered for word in (
        "placeholder", "substitua", "example", "exemplo", "secret_protegido",
        "protected_secret", "authorization_code", "access_token_here",
    )):
        return True
    if lowered.startswith(("your_", "seu_", "sua_", "my_")):
        return True
    compact = re.sub(r"[^a-z0-9]", "", lowered)
    return bool(compact) and len(compact) >= 12 and len(set(compact)) <= 2


def tracked_text_files() -> list[Path]:
    result = subprocess.run(
        ["git", "ls-files", "-z"], cwd=ROOT, check=True, capture_output=True
    )
    files: list[Path] = []
    for raw in result.stdout.split(b"\0"):
        if not raw:
            continue
        path = ROOT / raw.decode("utf-8", errors="replace")
        if path.is_file() and (
            path.suffix.lower() in TEXT_SUFFIXES or path.name in {"Dockerfile", "Makefile"}
        ):
            files.append(path)
    return files


def tracked_paths() -> set[str]:
    result = subprocess.run(
        ["git", "ls-files", "-z"], cwd=ROOT, check=True, capture_output=True
    )
    return {
        raw.decode("utf-8", errors="replace")
        for raw in result.stdout.split(b"\0")
        if raw
    }


def validate_wrapper(legacy: str, target: str) -> bool:
    legacy_path = ROOT / legacy
    target_path = ROOT / target
    if not legacy_path.is_file() or not target_path.is_file():
        return False
    text = legacy_path.read_text(encoding="utf-8", errors="replace")
    return "runpy.run_path" in text and Path(target).name in text


def credential_findings() -> tuple[list[Finding], int]:
    findings: list[Finding] = []
    files = tracked_text_files()
    seen: set[tuple[str, int, str]] = set()
    placeholder_aware = {
        "sensitive_quoted_literal",
        "sensitive_markdown_literal",
        "sensitive_query_literal",
        "sensitive_unquoted_hex",
    }
    for path in files:
        text = path.read_text(encoding="utf-8", errors="replace")
        relative = path.relative_to(ROOT).as_posix()
        for pattern_name, pattern in CREDENTIAL_PATTERNS:
            for match in pattern.finditer(text):
                candidate = match.group(1) if match.lastindex else match.group(0)
                if pattern_name in placeholder_aware and likely_placeholder(candidate):
                    continue
                line = line_number(text, match.start())
                key = (relative, line, pattern_name)
                if key in seen:
                    continue
                seen.add(key)
                findings.append(Finding(
                    "critical",
                    "credential_like_value",
                    relative,
                    "Tracked text contains a credential-like literal; revoke real values and use protected secrets.",
                    line=line,
                    pattern=pattern_name,
                ))
    return findings, len(files)


def evaluate() -> tuple[list[Finding], dict[str, object]]:
    findings: list[Finding] = []
    checks: dict[str, object] = {}
    tracked = tracked_paths()

    for relative in REQUIRED_CANONICAL:
        exists = (ROOT / relative).is_file()
        checks[f"required:{relative}"] = exists
        if not exists:
            findings.append(Finding("critical", "missing_canonical_file", relative, "Required canonical file is missing."))

    for legacy, target in WRAPPERS.items():
        valid = validate_wrapper(legacy, target)
        checks[f"wrapper:{legacy}"] = valid
        if not valid:
            findings.append(Finding("high", "invalid_compatibility_wrapper", legacy, f"Wrapper must delegate to {target}."))

    for relative in FORBIDDEN_FILES:
        absent = relative not in tracked and not (ROOT / relative).exists()
        checks[f"forbidden_absent:{relative}"] = absent
        if not absent:
            findings.append(Finding("critical", "forbidden_file_present", relative, "Forbidden workflow or credential file must remain absent."))

    tracked_private = sorted(path for path in tracked if path.startswith("storage/private/"))
    checks["tracked_private_file_count"] = len(tracked_private)
    checks["tracked_private_files"] = tracked_private
    for relative in tracked_private:
        findings.append(Finding("critical", "tracked_private_file", relative, "Runtime private storage must not be tracked."))

    hourly_relative = ".github/workflows/agents-hourly-deep-audit.yml"
    if (ROOT / hourly_relative).is_file():
        text = read(hourly_relative)
        scheduled = "17 * * * *" in text
        read_only = "contents: read" in text and "contents: write" not in text
        artifact_required = "if-no-files-found: error" in text
        checks.update({
            "hourly_schedule_minute_17": scheduled,
            "hourly_read_only": read_only,
            "hourly_required_artifact": artifact_required,
        })
        if not scheduled:
            findings.append(Finding("critical", "hourly_schedule_missing", hourly_relative, "Hourly audit must run at minute 17."))
        if not read_only:
            findings.append(Finding("critical", "hourly_write_permission", hourly_relative, "Hourly audit must remain read-only."))
        if not artifact_required:
            findings.append(Finding("high", "hourly_artifact_optional", hourly_relative, "Hourly evidence must be mandatory."))

    shopee_relative = ".github/workflows/shopee-production-seo.yml"
    if (ROOT / shopee_relative).is_file():
        text = read(shopee_relative)
        manual_only = "workflow_dispatch:" in text and "\n  push:" not in text
        checks["shopee_manual_only"] = manual_only
        if not manual_only:
            findings.append(Finding("critical", "shopee_automatic_trigger", shopee_relative, "Shopee production workflow must be manual-only."))

    secret_findings, scanned = credential_findings()
    findings.extend(secret_findings)
    checks["credential_scan_file_count"] = scanned

    root_docs = sorted(
        path.name for path in ROOT.iterdir()
        if path.is_file() and path.suffix.lower() in {".md", ".txt"}
        and path.name not in {"README.md", "LICENSE", "CHANGELOG.md", "CONTRIBUTING.md", "SECURITY.md"}
    )
    checks["legacy_root_document_count"] = len(root_docs)
    checks["legacy_root_documents_sample"] = root_docs[:20]
    severity_order = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    findings.sort(key=lambda item: (severity_order.get(item.severity, 9), item.path, item.line or 0, item.rule))
    return findings, checks


def write_report(findings: list[Finding], checks: dict[str, object]) -> None:
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    payload = {
        "schema_version": 3,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "status": "success" if not findings else "failure",
        "blocking_finding_count": len(findings),
        "checks": checks,
        "findings": [asdict(item) for item in findings],
        "scope_note": "Historical root documents are inventory only; automation, credentials, canonical paths, wrappers and private runtime files are blocking.",
    }
    (REPORT_DIR / "report.json").write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    lines = [
        "# Final repository reorganization verification", "",
        f"- Status: **{payload['status']}**",
        f"- Blocking findings: **{len(findings)}**",
        f"- Tracked text files scanned: **{checks['credential_scan_file_count']}**",
        f"- Tracked private files: **{checks['tracked_private_file_count']}**",
        f"- Legacy root documents inventoried: **{checks['legacy_root_document_count']}**", "",
    ]
    if findings:
        lines.extend(["## Blocking findings", ""])
        for item in findings:
            location = f"{item.path}:{item.line}" if item.line else item.path
            pattern = f" Pattern: `{item.pattern}`." if item.pattern else ""
            lines.append(f"- **{item.severity.upper()} / {item.rule}** `{location}` — {item.message}{pattern}")
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
