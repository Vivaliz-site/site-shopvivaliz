#!/usr/bin/env python3
"""Find sensitive runtime variables referenced by executable code but absent at runtime.

The scanner never prints secret values. It reports only variable names, reference
locations, and whether the reference appears mandatory or optional.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

SENSITIVE = re.compile(
    r"(?:TOKEN|SECRET|API_KEY|PASSWORD|PASS|PRIVATE_KEY|CLIENT_ID|CLIENT_SECRET|WEBHOOK|SIGNING_KEY|ACCESS_KEY)",
    re.IGNORECASE,
)

SKIP_DIRS = {
    ".git", "vendor", "node_modules", "storage", "cache", "logs", "backup", "backups",
    "docs", "tests", "test", "examples", "example", ".venv", "venv",
}

SCAN_SUFFIXES = {".php", ".py", ".sh", ".yml", ".yaml", ".js", ".ts"}

PATTERNS = [
    re.compile(r"getenv\(\s*['\"]([A-Z][A-Z0-9_]+)['\"]\s*\)"),
    re.compile(r"\$_(?:ENV|SERVER)\s*\[\s*['\"]([A-Z][A-Z0-9_]+)['\"]\s*\]"),
    re.compile(r"os\.environ(?:\.get)?\(\s*['\"]([A-Z][A-Z0-9_]+)['\"]"),
    re.compile(r"os\.environ\s*\[\s*['\"]([A-Z][A-Z0-9_]+)['\"]\s*\]"),
    re.compile(r"process\.env\.([A-Z][A-Z0-9_]+)"),
    re.compile(r"\$\{\{\s*secrets\.([A-Z][A-Z0-9_]+)\s*\}\}"),
    re.compile(r"\$\{([A-Z][A-Z0-9_]+)(?::[-=?+][^}]*)?\}"),
]

PLACEHOLDERS = {
    "", "changeme", "change-me", "change_me", "placeholder", "replace_me",
    "your_token_here", "your_key_here", "your_secret_here", "your_password_here",
    "null", "none", "undefined", "example", "sample", "dummy", "xxx", "xxxx",
}


def parse_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.is_file():
        return values
    for raw in path.read_text(encoding="utf-8", errors="replace").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def looks_placeholder(value: str) -> bool:
    normalized = value.strip().lower()
    return (
        normalized in PLACEHOLDERS
        or (normalized.startswith("<") and normalized.endswith(">"))
        or bool(re.fullmatch(r"x{4,}|\*{4,}|0{8,}", normalized))
        or any(part in normalized for part in ("your_", "replace_", "example_", "sample_"))
    )


def mandatory_context(line: str, key: str) -> bool:
    lowered = line.lower()
    mandatory_markers = (
        "raise", "exit 1", "exit(1", "die(", "throw new", "required", "obrigat",
        "missing", "ausente", "not configured", "nao configur", "não configur",
    )
    if any(marker in lowered for marker in mandatory_markers):
        return True
    if re.search(rf"os\.environ\s*\[\s*['\"]{re.escape(key)}['\"]", line):
        return True
    if re.search(rf"\$\{{\{{\s*secrets\.{re.escape(key)}\s*\}}\}}", line):
        return True
    return False


def scan(repo: Path) -> dict[str, list[dict[str, object]]]:
    references: dict[str, list[dict[str, object]]] = {}
    for path in repo.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in SCAN_SUFFIXES:
            continue
        rel = path.relative_to(repo)
        if any(part in SKIP_DIRS for part in rel.parts):
            continue
        try:
            lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
        except OSError:
            continue
        for number, line in enumerate(lines, 1):
            for pattern in PATTERNS:
                for match in pattern.finditer(line):
                    key = match.group(1)
                    if not SENSITIVE.search(key):
                        continue
                    references.setdefault(key, []).append({
                        "path": str(rel),
                        "line": number,
                        "mandatory": mandatory_context(line, key),
                    })
    return references


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("repo_root", type=Path)
    parser.add_argument("env_path", type=Path)
    parser.add_argument("--json-output", type=Path)
    args = parser.parse_args()

    env = parse_env(args.env_path)
    references = scan(args.repo_root)
    critical: list[dict[str, object]] = []
    warnings: list[dict[str, object]] = []

    for key, locations in sorted(references.items()):
        value = env.get(key, "")
        missing = value == ""
        placeholder = bool(value) and looks_placeholder(value)
        if not missing and not placeholder:
            continue
        mandatory = any(bool(item["mandatory"]) for item in locations)
        finding = {
            "key": key,
            "reason": "referenced_but_placeholder" if placeholder else "referenced_but_not_provisioned",
            "mandatory": mandatory,
            "references": locations[:10],
            "reference_count": len(locations),
        }
        (critical if mandatory else warnings).append(finding)

    report = {
        "ok": not critical,
        "referenced_sensitive_keys": len(references),
        "missing_or_placeholder_count": len(critical) + len(warnings),
        "critical_count": len(critical),
        "warning_count": len(warnings),
        "critical": critical,
        "warnings": warnings,
    }
    rendered = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True)
    if args.json_output:
        args.json_output.parent.mkdir(parents=True, exist_ok=True)
        args.json_output.write_text(rendered + "\n", encoding="utf-8")
    print(rendered)
    return 0 if report["ok"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
