#!/usr/bin/env python3
"""Repository hygiene audit for ShopVivaliz.

The audit blocks concrete security and structure problems. Production-guard
warnings are intentionally limited to active mutating routines, avoiding noise
from wrappers, archived code, documentation and read-only clients.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MANIFEST_PATH = ROOT / "config" / "repository-structure-manifest.json"

ALLOWED_SECRET_ALIAS_FILES = {
    "config/secrets.py",
    "docs/knowledge/secrets-and-integrations-map.md",
    "docs/SECRETS_INVENTORY.md",
    "docs/audits/repository-cleanup-backlog.md",
    "scripts/audit_repository.py",
}

LEGACY_SECRET_ALIASES = {
    "TOKEN_API_OLIST": "OLIST_ACCESS_TOKEN or OLIST_API_KEY",
    "CLIENT_ID_API_OLIST": "OLIST_CLIENT_ID",
    "CLIENT_SECRET_OLIST": "OLIST_CLIENT_SECRET",
    "URL_REDIRCT_OLIST": "OLIST_REDIRECT_URI",
    "URL_TINY_OLIST": "OLIST_API_BASE_URL or integration-specific URL",
    "FTP_HOST": "FTP_SERVER",
    "FTP_USER": "FTP_USERNAME",
    "FTP_PASS": "FTP_PASSWORD",
    "EMAIL_PASSWORD": "SMTP_PASS",
    "EMAIL_USER": "SMTP_USER",
    "EMAIL_SMTP_HOST": "SMTP_HOST",
    "EMAIL_SMTP_PORT": "SMTP_PORT",
}

SENSITIVE_FILE_NAMES = {
    ".env",
    ".env.local",
    ".env.production",
    ".env.prod",
    "id_rsa",
    "id_ed25519",
    "credentials.json",
    "service-account.json",
}

TEXT_SUFFIXES = {
    ".py", ".php", ".js", ".ts", ".tsx", ".jsx", ".json", ".yml", ".yaml",
    ".md", ".txt", ".env", ".example", ".ini", ".conf", ".sh", ".sql", ".ps1",
}

SECRET_VALUE_PATTERNS = [
    re.compile(r"gh[pousr]_[A-Za-z0-9_]{20,}"),
    re.compile(r"github_pat_[A-Za-z0-9_]{40,}"),
    re.compile(r"sk-[A-Za-z0-9]{32,}"),
    re.compile(r"sk-proj-[A-Za-z0-9_-]{32,}"),
    re.compile(r"xox[baprs]-[A-Za-z0-9-]{20,}"),
    re.compile(r"-----BEGIN (?:RSA |OPENSSH |EC |DSA |)PRIVATE KEY-----"),
    re.compile(r"(?i)\b(?:token|api[_ -]?key|secret|client[_ -]?secret)\s*[:=]\s*[A-Fa-f0-9]{32,}\b"),
    re.compile(r"(?i)\b(?:token|bearer|authorization)\s*[:=]\s*eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}"),
]

REQUIRED_GOVERNANCE_FILES = [
    Path("docs/knowledge/repository-index.md"),
    Path("docs/knowledge/routines-registry.md"),
    Path("docs/knowledge/secrets-and-integrations-map.md"),
    Path("docs/knowledge/structure-policy.md"),
    Path("docs/knowledge/ownership-map.md"),
    Path("docs/audits/repository-cleanup-backlog.md"),
    Path("docs/operations/legacy-root-docs-index.md"),
    Path("scripts/maintenance/restructure_repository.py"),
    Path("scripts/maintenance/validate_structure_manifest.py"),
    Path("config/repository-structure-manifest.json"),
]

CANONICAL_SHOPEE_FILES = [
    Path("scripts/marketplace/shopee/production_seo_apply.py"),
    Path("scripts/marketplace/shopee/full_catalog_optimizer.py"),
    Path("scripts/marketplace/shopee/README.md"),
]

LEGACY_SHOPEE_WRAPPERS = [
    Path("scripts/shopee_production_seo_apply.py"),
    Path("scripts/shopee_full_catalog_optimizer.py"),
]

ROOT_DOC_ALLOWLIST = {
    "README.md",
    "START_HERE.md",
    "START-HERE.md",
    "LICENSE",
    "CHANGELOG.md",
    "CONTRIBUTING.md",
    "SECURITY.md",
}

ROOT_STUB_MARKERS = (
    "documento migrado",
    "relatório migrado",
    "relatorio migrado",
    "auditoria migrada",
    "registro migrado",
    "plano migrado",
    "diagnóstico migrado",
    "diagnostico migrado",
    "guia de agentes migrado",
    "início rápido",
    "inicio rapido",
    "pipeline shopee - documento migrado",
    "conteúdo histórico preservado",
    "conteudo historico preservado",
    "permanece somente como ponte",
    "apenas como ponte",
)

IGNORE_DIRS = {
    ".git", "node_modules", "vendor", ".next", "dist", "build", "coverage",
    "storage/private", "logs", ".cache", "__pycache__",
}

WRAPPER_MARKERS = (
    "runpy.run_path",
    "exec(compile(",
    "wrapper legado",
    "implementação canônica",
    "implementacao canonica",
)

SKIP_PRODUCTION_GUARD_PREFIXES = (
    "scripts/dev/",
    "archive/",
    ".github/workflows-archive/",
)

SKIP_PRODUCTION_GUARD_SEGMENTS = (
    "/legacy/",
    "/tests/",
)

SKIP_PRODUCTION_GUARD_FILES = {
    "scripts/audit_repository.py",
    "scripts/maintenance/restructure_repository.py",
    "scripts/maintenance/validate_structure_manifest.py",
    "scripts/maintenance/canonicalize_secret_aliases.py",
    "scripts/maintenance/reconcile_structure_manifest.py",
    ".github/workflows/repo-hygiene.yml",
    ".github/workflows/shopee-optimizer-safety.yml",
    ".github/workflows/shopvivaliz-qa.yml",
}

MUTATION_PATTERNS = [
    re.compile(r"\bupdate_product\s*\("),
    re.compile(r"\bdelete_product\s*\("),
    re.compile(r"(?i)\b(?:drop\s+table|truncate\s+table|delete\s+from|update\s+[A-Za-z_][A-Za-z0-9_]*\s+set)\b"),
    re.compile(r"(?i)\b(?:ftp|sftp|scp|rsync)\b[^\n]{0,80}\b(?:upload|put|deploy|sync)\b"),
    re.compile(r"(?i)\bgit\s+(?:push|reset\s+--hard)\b"),
    re.compile(r"(?i)\b(?:publish|activate|apply)\b[^\n]{0,80}\b(?:campaign|production|catalog|product)\b"),
    re.compile(r"\bAPPLY_ALL_[A-Z0-9_]+\b"),
]

GUARD_MARKERS = (
    "backup",
    "read-back",
    "readback",
    "rollback",
    "dry-run",
    "dry_run",
    "confirmation",
    "confirm",
    "canary",
    "limit",
    "artifact",
    "pull request",
    "branch isolada",
    "branch isolated",
    "desativado",
    "disabled",
)


def rel(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def iter_files() -> list[Path]:
    files: list[Path] = []
    for path in ROOT.rglob("*"):
        if path.is_dir():
            continue
        value = rel(path)
        if any(value == directory or value.startswith(directory + "/") for directory in IGNORE_DIRS):
            continue
        files.append(path)
    return files


def is_text_file(path: Path) -> bool:
    return path.suffix.lower() in TEXT_SUFFIXES or path.name in {"Dockerfile", "Makefile"}


def read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


def audit_sensitive_files(files: list[Path]) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    for path in files:
        value = rel(path)
        if path.name in SENSITIVE_FILE_NAMES and not value.endswith(".example"):
            errors.append(f"Sensitive file name must not be committed: {value}")
        if path.suffix.lower() in {".pem", ".key", ".p12", ".pfx"}:
            errors.append(f"Private key/certificate-like file must not be committed: {value}")
    return errors, []


def audit_secret_values(files: list[Path]) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    for path in files:
        if not is_text_file(path):
            continue
        text = read_text(path)
        if any(pattern.search(text) for pattern in SECRET_VALUE_PATTERNS):
            errors.append(f"Possible hardcoded secret value in {rel(path)}; move it to protected secrets")
    return errors, []


def audit_legacy_aliases(files: list[Path], strict_aliases: bool) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    for path in files:
        if not is_text_file(path):
            continue
        value = rel(path)
        if value in ALLOWED_SECRET_ALIAS_FILES:
            continue
        text = read_text(path)
        for alias, canonical in LEGACY_SECRET_ALIASES.items():
            if re.search(rf"\b{re.escape(alias)}\b", text):
                message = f"Legacy secret alias {alias} used in {value}; prefer {canonical}"
                (errors if strict_aliases else warnings).append(message)
    return errors, warnings


def audit_required_governance(_files: list[Path]) -> tuple[list[str], list[str]]:
    errors = [
        f"Required governance file missing: {path.as_posix()}"
        for path in REQUIRED_GOVERNANCE_FILES
        if not (ROOT / path).exists()
    ]
    if MANIFEST_PATH.exists():
        try:
            json.loads(read_text(MANIFEST_PATH))
        except json.JSONDecodeError as exc:
            errors.append(f"Structure manifest is invalid JSON: {exc}")
    return errors, []


def audit_shopee_structure(_files: list[Path]) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    for path in CANONICAL_SHOPEE_FILES:
        if not (ROOT / path).exists():
            errors.append(f"Canonical Shopee file missing: {path.as_posix()}")
    for path in LEGACY_SHOPEE_WRAPPERS:
        wrapper = ROOT / path
        if not wrapper.exists():
            errors.append(f"Legacy Shopee wrapper missing: {path.as_posix()}")
            continue
        text = read_text(wrapper).lower()
        if not any(marker in text for marker in WRAPPER_MARKERS) or "marketplace" not in text:
            errors.append(f"Legacy Shopee path is not a compatibility wrapper: {path.as_posix()}")
    workflow = ROOT / ".github/workflows/shopee-production-seo.yml"
    if workflow.exists() and "scripts/marketplace/shopee/production_seo_apply.py" not in read_text(workflow):
        errors.append("Shopee production workflow does not call canonical executor")
    return errors, []


def audit_root_documents(files: list[Path], strict_root_docs: bool) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    root_docs = [
        path for path in files
        if path.parent == ROOT
        and path.suffix.lower() in {".md", ".txt"}
        and path.name not in ROOT_DOC_ALLOWLIST
    ]
    for path in root_docs:
        lowered = read_text(path).lower()
        if any(marker in lowered for marker in ROOT_STUB_MARKERS):
            continue
        message = f"Legacy root document should be indexed/migrated: {rel(path)}"
        (errors if strict_root_docs else warnings).append(message)
    return errors, warnings


def is_wrapper_text(text: str) -> bool:
    lowered = text.lower()
    return any(marker in lowered for marker in WRAPPER_MARKERS)


def should_scan_production_guard(path: Path, text: str) -> bool:
    value = rel(path)
    if value in SKIP_PRODUCTION_GUARD_FILES:
        return False
    if any(value.startswith(prefix) for prefix in SKIP_PRODUCTION_GUARD_PREFIXES):
        return False
    if any(segment in value for segment in SKIP_PRODUCTION_GUARD_SEGMENTS):
        return False
    if is_wrapper_text(text):
        return False

    if value.startswith("scripts/production/"):
        return True
    if value.startswith("scripts/marketplace/") and "/legacy/" not in value:
        return True
    if value.startswith(".github/workflows/"):
        filename = path.name.lower()
        return any(word in filename for word in ("deploy", "production", "publish", "sync", "autonomous"))

    return any(pattern.search(text) for pattern in MUTATION_PATTERNS)


def audit_production_guards(files: list[Path]) -> tuple[list[str], list[str]]:
    warnings: list[str] = []
    for path in files:
        if not is_text_file(path):
            continue
        value = rel(path)
        if not (value.startswith("scripts/") or value.startswith(".github/workflows/")):
            continue
        text = read_text(path)
        if not should_scan_production_guard(path, text):
            continue
        if not any(pattern.search(text) for pattern in MUTATION_PATTERNS):
            continue
        lowered = text.lower()
        if not any(marker in lowered for marker in GUARD_MARKERS):
            warnings.append(f"Active mutating routine lacks explicit safety guard wording: {value}")
    return [], warnings


def main() -> int:
    parser = argparse.ArgumentParser(description="Audit ShopVivaliz repository hygiene")
    parser.add_argument("--strict-aliases", action="store_true")
    parser.add_argument("--strict-root-docs", action="store_true")
    args = parser.parse_args()

    files = iter_files()
    errors: list[str] = []
    warnings: list[str] = []

    audits = (
        audit_sensitive_files,
        audit_secret_values,
        lambda items: audit_legacy_aliases(items, args.strict_aliases),
        audit_required_governance,
        audit_shopee_structure,
        lambda items: audit_root_documents(items, args.strict_root_docs),
        audit_production_guards,
    )

    for audit in audits:
        audit_errors, audit_warnings = audit(files)
        errors.extend(audit_errors)
        warnings.extend(audit_warnings)

    print("# Repository hygiene audit")
    print(f"Scanned files: {len(files)}")
    print(f"Errors: {len(errors)}")
    print(f"Warnings: {len(warnings)}")

    if warnings:
        print("\n## Warnings")
        for item in warnings:
            print(f"- {item}")

    if errors:
        print("\n## Errors")
        for item in errors:
            print(f"- {item}")
        return 1

    print("\nOK: no blocking repository hygiene errors found.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
