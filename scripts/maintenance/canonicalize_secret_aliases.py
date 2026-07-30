#!/usr/bin/env python3
"""Replace legacy secret identifiers with canonical names across text files.

The operation is lexical and idempotent. It intentionally skips the canonical
centralizer and the documentation that defines compatibility aliases.
"""
from __future__ import annotations

import argparse
import json
import re
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REPORT = ROOT / "docs" / "audits" / "secret-alias-canonicalization-report.json"

MAPPINGS = {
    "TOKEN_API_OLIST": "OLIST_ACCESS_TOKEN",
    "CLIENT_ID_API_OLIST": "OLIST_CLIENT_ID",
    "CLIENT_SECRET_OLIST": "OLIST_CLIENT_SECRET",
    "URL_REDIRCT_OLIST": "OLIST_REDIRECT_URI",
    "URL_TINY_OLIST": "OLIST_API_BASE_URL",
    "FTP_HOST": "FTP_SERVER",
    "FTP_USER": "FTP_USERNAME",
    "FTP_PASS": "FTP_PASSWORD",
    "EMAIL_PASSWORD": "SMTP_PASS",
    "EMAIL_USER": "SMTP_USER",
    "EMAIL_SMTP_HOST": "SMTP_HOST",
    "EMAIL_SMTP_PORT": "SMTP_PORT",
}

SKIP_FILES = {
    "config/secrets.py",
    "docs/knowledge/secrets-and-integrations-map.md",
    "docs/audits/repository-cleanup-backlog.md",
    "scripts/audit_repository.py",
    "scripts/maintenance/canonicalize_secret_aliases.py",
    "docs/audits/secret-alias-canonicalization-report.json",
}

IGNORE_PREFIXES = (
    ".git/", "node_modules/", "vendor/", ".next/", "dist/", "build/",
    "coverage/", "storage/private/", "logs/", ".cache/", "__pycache__/",
)

TEXT_SUFFIXES = {
    ".py", ".php", ".js", ".ts", ".tsx", ".jsx", ".json", ".yml", ".yaml",
    ".md", ".txt", ".sh", ".ps1", ".env", ".example", ".ini", ".conf", ".sql",
}


def rel(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def eligible(path: Path) -> bool:
    if not path.is_file():
        return False
    value = rel(path)
    if value in SKIP_FILES:
        return False
    if any(value == prefix.rstrip("/") or value.startswith(prefix) for prefix in IGNORE_PREFIXES):
        return False
    return path.suffix.lower() in TEXT_SUFFIXES or path.name in {"Dockerfile", "Makefile"}


def replace_text(text: str) -> tuple[str, Counter[str]]:
    counts: Counter[str] = Counter()
    updated = text
    for legacy, canonical in MAPPINGS.items():
        pattern = re.compile(rf"\b{re.escape(legacy)}\b")
        updated, total = pattern.subn(canonical, updated)
        if total:
            counts[legacy] += total
    return updated, counts


def main() -> int:
    parser = argparse.ArgumentParser(description="Canonicalize legacy secret aliases")
    parser.add_argument("--apply", action="store_true", help="Write replacements")
    args = parser.parse_args()

    changed_files: list[dict[str, object]] = []
    totals: Counter[str] = Counter()

    for path in sorted(ROOT.rglob("*")):
        if not eligible(path):
            continue
        original = path.read_text(encoding="utf-8", errors="replace")
        updated, counts = replace_text(original)
        if not counts:
            continue
        totals.update(counts)
        changed_files.append({
            "path": rel(path),
            "replacements": sum(counts.values()),
        })
        if args.apply:
            path.write_text(updated, encoding="utf-8")

    report = {
        "applied": args.apply,
        "changed_files": len(changed_files),
        "total_replacements": sum(totals.values()),
        "by_legacy_alias": dict(sorted(totals.items())),
        "files": changed_files,
    }
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({
        "applied": args.apply,
        "changed_files": report["changed_files"],
        "total_replacements": report["total_replacements"],
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
