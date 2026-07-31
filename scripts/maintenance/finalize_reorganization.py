#!/usr/bin/env python3
"""Verify the critical repository reorganization contract and emit evidence."""
from __future__ import annotations

import json
import math
import re
import subprocess
from collections import Counter
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REPORT_DIR = ROOT / "artifacts" / "reorganization-final"
SELF_PATH = "scripts/maintenance/finalize_reorganization.py"

REQUIRED_CANONICAL = (
    "scripts/maintenance/audit_automation_changes.py",
    "scripts/maintenance/audit_active_workflows.py",
    "scripts/maintenance/system_health_check.py",
    SELF_PATH,
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
    "oauth-auto-exec.py": "scripts/marketplace/olist/oauth_login.py",
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
    r"webhook[_ -]?(?:secret|token)|token|secret)"
)
PASSWORD_LABEL = r"(?:sandbox[_ -]?(?:pass|password)|password|passphrase)"

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
        "password_quoted_literal",
        re.compile(
            rf"(?ix)(?:['\"]?{PASSWORD_LABEL}['\"]?)\s*[:=]\s*"
            r"[`'\"]([^`'\"\n]{8,})[`'\"]"
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

DIRECT_FORMAT_PATTERNS = {
    "github_classic_token",
    "github_fine_grained_token",
    "openai_key",
    "slack_token",
    "aws_access_key",
    "shopee_partner_key",
    "jwt",
    "private_key_block",
}

INSECURE_DEFAULT_PASSWORDS = {
    "password",
    "password123",
    "supersecret",
    "changeme",
    "change_me",
    "letmein",
    "admin123",
    "admin123456",
    "medusa123",
    "medusa123456",
    "supabase123456",
}


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
        "placeholder", "substitua", "configure", "example", "exemplo",
        "secret_protegido", "protected_secret", "authorization_code",
        "access_token_here", "refresh_token_here", "test-", "test_",
        "fake-", "fake_", "mock-", "mock_", "dummy-", "dummy_",
    )):
        return True
    if lowered.startswith(("your_", "seu_", "sua_", "my_")):
        return True
    compact = re.sub(r"[^a-z0-9]", "", lowered)
    return bool(compact) and len(compact) >= 12 and len(set(compact)) <= 2


def shannon_entropy(value: str) -> float:
    if not value:
        return 0.0
    counts = Counter(value)
    length = len(value)
    return -sum((count / length) * math.log2(count / length) for count in counts.values())


def character_class_count(value: str) -> int:
    return sum((
        any(char.islower() for char in value),
        any(char.isupper() for char in value),
        any(char.isdigit() for char in value),
        any(not char.isalnum() for char in value),
    ))


def looks_like_secret_literal(value: str) -> bool:
    candidate = value.strip().strip("`'\"")
    if likely_placeholder(candidate):
        return False
    if len(candidate) < 16:
        return False
    if "://" in candidate or candidate.startswith(("/", "./", "../")):
        return False
    if re.fullmatch(r"[A-Z][A-Z0-9_]{11,}", candidate):
        return False
    has_digit_or_symbol = any(char.isdigit() for char in candidate) or any(
        not char.isalnum() for char in candidate
    )
    return (
        character_class_count(candidate) >= 2
        and has_digit_or_symbol
        and shannon_entropy(candidate) >= 3.35
    )


def looks_like_password_literal(value: str) -> bool:
    candidate = value.strip().strip("`'\"")
    lowered = candidate.lower()
    if likely_placeholder(candidate):
        return False
    if lowered in INSECURE_DEFAULT_PASSWORDS:
        return True
    if re.fullmatch(r"[A-Z][A-Z0-9_]{7,}", candidate):
        return False
    if re.fullmatch(r"[A-Za-zÀ-ÿ ]+(?:\s*\([^)]*\))?", candidate):
        return False
    return (
        len(candidate) >= 10
        and character_class_count(candidate) >= 3
        and shannon_entropy(candidate) >= 3.0
    )


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


def should_block_candidate(pattern_name: str, candidate: str) -> bool:
    if pattern_name in DIRECT_FORMAT_PATTERNS:
        return True
    if pattern_name == "password_quoted_literal":
        return looks_like_password_literal(candidate)
    if pattern_name == "sensitive_unquoted_hex":
        return not likely_placeholder(candidate)
    return looks_like_secret_literal(candidate)


def credential_findings() -> tuple[list[Finding], int]:
    findings: list[Finding] = []
    files = tracked_text_files()
    seen: set[tuple[str, int, str]] = set()
    for path in files:
        text = path.read_text(encoding="utf-8", errors="replace")
        relative = path.relative_to(ROOT).as_posix()
        is_test_fixture = relative.startswith(("tests/", "test/"))
        for pattern_name, pattern in CREDENTIAL_PATTERNS:
            if relative == SELF_PATH and pattern_name not in DIRECT_FORMAT_PATTERNS:
                continue
            if is_test_fixture and pattern_name not in DIRECT_FORMAT_PATTERNS:
                continue
            for match in pattern.finditer(text):
                candidate = match.group(1) if match.lastindex else match.group(0)
                if not should_block_candidate(pattern_name, candidate):
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
        "schema_version": 5,
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
