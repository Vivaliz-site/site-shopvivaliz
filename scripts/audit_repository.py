#!/usr/bin/env python3
"""Repository hygiene audit for ShopVivaliz.

This script is intentionally conservative: it blocks obvious repository hygiene
problems and warns about legacy aliases that should be migrated gradually.
It never prints secret values.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

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
    ".md", ".txt", ".env", ".example", ".ini", ".conf", ".sh", ".sql",
}

SECRET_VALUE_PATTERNS = [
    re.compile(r"gh[pousr]_[A-Za-z0-9_]{20,}"),
    re.compile(r"github_pat_[A-Za-z0-9_]{40,}"),
    re.compile(r"sk-[A-Za-z0-9]{32,}"),
    re.compile(r"sk-proj-[A-Za-z0-9_-]{32,}"),
    re.compile(r"xox[baprs]-[A-Za-z0-9-]{20,}"),
    re.compile(r"-----BEGIN (?:RSA |OPENSSH |EC |DSA |)PRIVATE KEY-----"),
]

REQUIRED_DOCS_FOR_ROUTINES = [
    Path("docs/knowledge/repository-index.md"),
    Path("docs/knowledge/routines-registry.md"),
    Path("docs/knowledge/secrets-and-integrations-map.md"),
    Path("docs/knowledge/structure-policy.md"),
    Path("docs/knowledge/ownership-map.md"),
    Path("docs/audits/repository-cleanup-backlog.md"),
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

IGNORE_DIRS = {
    ".git", "node_modules", "vendor", ".next", "dist", "build", "coverage",
    "storage/private", "logs", ".cache", "__pycache__",
}


def rel(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def iter_files() -> list[Path]:
    files: list[Path] = []
    for path in ROOT.rglob("*"):
        if path.is_dir():
            continue
        r = rel(path)
        if any(r == d or r.startswith(d + "/") for d in IGNORE_DIRS):
            continue
        files.append(path)
    return files


def is_text_file(path: Path) -> bool:
    if path.suffix.lower() in TEXT_SUFFIXES:
        return True
    if path.name in {"Dockerfile", "Makefile"}:
        return True
    return False


def read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


def audit_sensitive_files(files: list[Path]) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    for path in files:
        r = rel(path)
        name = path.name
        if name in SENSITIVE_FILE_NAMES and not r.endswith(".example"):
            errors.append(f"Sensitive file name must not be committed: {r}")
        if path.suffix.lower() in {".pem", ".key", ".p12", ".pfx"}:
            errors.append(f"Private key/certificate-like file must not be committed: {r}")
    return errors, warnings


def audit_secret_values(files: list[Path]) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    for path in files:
        if not is_text_file(path):
            continue
        r = rel(path)
        text = read_text(path)
        for pattern in SECRET_VALUE_PATTERNS:
            if pattern.search(text):
                errors.append(f"Possible hardcoded secret value in {r}; move it to GitHub Secrets or environment variables")
    return errors, warnings


def audit_legacy_aliases(files: list[Path], strict_aliases: bool) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    for path in files:
        if not is_text_file(path):
            continue
        r = rel(path)
        if r in ALLOWED_SECRET_ALIAS_FILES:
            continue
        text = read_text(path)
        for alias, canonical in LEGACY_SECRET_ALIASES.items():
            if re.search(rf"\b{re.escape(alias)}\b", text):
                message = f"Legacy secret alias {alias} used in {r}; prefer {canonical}"
                if strict_aliases:
                    errors.append(message)
                else:
                    warnings.append(message)
    return errors, warnings


def audit_required_docs(files: list[Path]) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    for doc in REQUIRED_DOCS_FOR_ROUTINES:
        if not (ROOT / doc).exists():
            errors.append(f"Required governance document missing: {doc.as_posix()}")
    workflow_files = [p for p in files if rel(p).startswith(".github/workflows/") and p.suffix in {".yml", ".yaml"}]
    if workflow_files and not (ROOT / "docs/knowledge/routines-registry.md").exists():
        errors.append("Workflows exist but docs/knowledge/routines-registry.md is missing")
    return errors, warnings


def audit_migrated_shopee_structure(files: list[Path]) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    for path in CANONICAL_SHOPEE_FILES:
        if not (ROOT / path).exists():
            errors.append(f"Canonical Shopee file missing after migration: {path.as_posix()}")
    for path in LEGACY_SHOPEE_WRAPPERS:
        wrapper = ROOT / path
        if not wrapper.exists():
            warnings.append(f"Legacy Shopee wrapper missing: {path.as_posix()}")
            continue
        text = read_text(wrapper)
        if "runpy.run_path" not in text or "marketplace" not in text:
            errors.append(f"Legacy Shopee file is not a compatibility wrapper: {path.as_posix()}")
    workflow = ROOT / ".github/workflows/shopee-production-seo.yml"
    if workflow.exists():
        text = read_text(workflow)
        if "scripts/marketplace/shopee/production_seo_apply.py" not in text:
            errors.append("Shopee production workflow still does not call canonical marketplace executor")
    return errors, warnings


def audit_production_guards(files: list[Path]) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    risky_terms = ("update_product", "delete", "DROP TABLE", "TRUNCATE", "production", "APPLY_ALL")
    for path in files:
        r = rel(path)
        if not (r.startswith("scripts/") or r.startswith(".github/workflows/")):
            continue
        if not is_text_file(path):
            continue
        text = read_text(path)
        if any(term in text for term in risky_terms) and "backup" not in text.lower() and "read-back" not in text.lower() and "readback" not in text.lower():
            warnings.append(f"Potential production routine without explicit backup/read-back wording: {r}")
    return errors, warnings


def main() -> int:
    parser = argparse.ArgumentParser(description="Audit ShopVivaliz repository hygiene")
    parser.add_argument("--strict-aliases", action="store_true", help="Fail on legacy secret aliases outside the centralizer")
    args = parser.parse_args()

    files = iter_files()
    errors: list[str] = []
    warnings: list[str] = []

    for audit in (
        audit_sensitive_files,
        audit_secret_values,
        lambda f: audit_legacy_aliases(f, args.strict_aliases),
        audit_required_docs,
        audit_migrated_shopee_structure,
        audit_production_guards,
    ):
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
