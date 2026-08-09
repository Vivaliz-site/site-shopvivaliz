#!/usr/bin/env python3
"""Audit runtime secrets/tokens without printing their values.

Exit codes:
  0: no critical findings
  2: one or more critical findings

The audit is intentionally conservative: it validates all populated sensitive
variables, flags empty declarations/placeholders, checks known credential
families, and emits only key names plus reasons.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import stat
import sys
from pathlib import Path
from typing import Iterable

SENSITIVE_NAME = re.compile(
    r"(?:TOKEN|SECRET|API_KEY|PASSWORD|PASS|PRIVATE_KEY|CLIENT_ID|CLIENT_SECRET|WEBHOOK|SIGNING_KEY|ACCESS_KEY)",
    re.IGNORECASE,
)

PLACEHOLDERS = {
    "",
    "changeme",
    "change-me",
    "change_me",
    "placeholder",
    "your_token_here",
    "your_key_here",
    "your_secret_here",
    "your_password_here",
    "replace_me",
    "replace-with-real-value",
    "todo",
    "null",
    "none",
    "undefined",
    "example",
    "test",
    "dummy",
    "xxx",
    "xxxx",
    "secret",
    "password",
    "token",
}

REQUIRED_FAMILIES: dict[str, tuple[tuple[str, ...], ...]] = {
    "database": (
        ("DB_HOST",),
        ("DB_NAME", "DB_DATABASE"),
        ("DB_USER", "DB_USERNAME"),
        ("DB_PASS", "DB_PASSWORD"),
    ),
    "google_oauth": (
        ("GOOGLE_OAUTH_CLIENT_ID",),
        ("GOOGLE_OAUTH_CLIENT_SECRET",),
    ),
    "olist_tiny": (
        ("OLIST_CLIENT_ID", "TINY_CLIENT_ID", "CLIENT_ID_API_OLIST"),
        ("OLIST_CLIENT_SECRET", "TINY_CLIENT_SECRET", "CLIENT_SECRET_OLIST"),
        ("OLIST_REFRESH_TOKEN", "TINY_REFRESH_TOKEN"),
    ),
}

KNOWN_MIN_LENGTH = {
    "DB_PASS": 8,
    "DB_PASSWORD": 8,
    "GOOGLE_OAUTH_CLIENT_SECRET": 16,
    "OLIST_CLIENT_SECRET": 16,
    "TINY_CLIENT_SECRET": 16,
    "CLIENT_SECRET_OLIST": 16,
    "OLIST_REFRESH_TOKEN": 16,
    "TINY_REFRESH_TOKEN": 16,
    "OPENAI_API_KEY": 20,
    "ANTHROPIC_API_KEY": 20,
    "GEMINI_API_KEY": 20,
    "SHOPVIVALIZ_AGENT_KEY": 24,
}


def parse_env(path: Path) -> tuple[dict[str, str], list[str]]:
    values: dict[str, str] = {}
    duplicate_keys: list[str] = []
    for raw in path.read_text(encoding="utf-8", errors="strict").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key in values:
            duplicate_keys.append(key)
        values[key] = value
    return values, sorted(set(duplicate_keys))


def looks_placeholder(value: str) -> bool:
    normalized = value.strip().lower()
    if normalized in PLACEHOLDERS:
        return True
    if normalized.startswith("<") and normalized.endswith(">"):
        return True
    if re.fullmatch(r"x{4,}|\*{4,}|0{8,}", normalized):
        return True
    if any(marker in normalized for marker in ("your_", "replace_", "example_", "sample_")):
        return True
    return False


def validate_known_format(key: str, value: str) -> str | None:
    if any(ord(ch) < 32 for ch in value):
        return "contains_control_character"
    if value != value.strip():
        return "has_surrounding_whitespace"
    if "\n" in value and "PRIVATE_KEY" not in key:
        return "unexpected_multiline_value"
    minimum = KNOWN_MIN_LENGTH.get(key)
    if minimum and len(value) < minimum:
        return f"too_short_min_{minimum}"
    if key == "OPENAI_API_KEY" and not value.startswith(("sk-", "sk-proj-")):
        return "unexpected_openai_key_prefix"
    if key == "ANTHROPIC_API_KEY" and not value.startswith("sk-ant-"):
        return "unexpected_anthropic_key_prefix"
    if key.endswith("CLIENT_ID") and len(value) < 6:
        return "client_id_too_short"
    if key.endswith("PRIVATE_KEY") and "BEGIN" not in value and len(value) < 40:
        return "private_key_format_invalid"
    return None


def first_present(values: dict[str, str], aliases: Iterable[str]) -> tuple[str | None, str]:
    for key in aliases:
        value = values.get(key, "")
        if value:
            return key, value
    return None, ""


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("env_path", type=Path)
    parser.add_argument("--php-user", default="")
    parser.add_argument("--json-output", type=Path)
    args = parser.parse_args()

    findings: list[dict[str, str]] = []
    warnings: list[dict[str, str]] = []
    path = args.env_path

    if not path.is_file():
        findings.append({"key": "__file__", "reason": "env_file_missing"})
        values: dict[str, str] = {}
        duplicates: list[str] = []
    else:
        try:
            values, duplicates = parse_env(path)
        except Exception as exc:
            findings.append({"key": "__file__", "reason": f"env_parse_failed:{type(exc).__name__}"})
            values, duplicates = {}, []

    for key in duplicates:
        findings.append({"key": key, "reason": "duplicate_definition"})

    for key, value in sorted(values.items()):
        if not SENSITIVE_NAME.search(key):
            continue
        if value == "":
            findings.append({"key": key, "reason": "empty_sensitive_value"})
            continue
        if looks_placeholder(value):
            findings.append({"key": key, "reason": "placeholder_value"})
            continue
        reason = validate_known_format(key, value)
        if reason:
            findings.append({"key": key, "reason": reason})

    for family, groups in REQUIRED_FAMILIES.items():
        for aliases in groups:
            key, value = first_present(values, aliases)
            label = "/".join(aliases)
            if not value:
                findings.append({"key": label, "reason": f"required_family_missing:{family}"})
            elif looks_placeholder(value):
                findings.append({"key": key or label, "reason": f"required_family_placeholder:{family}"})

    if path.exists():
        mode = stat.S_IMODE(path.stat().st_mode)
        if mode & 0o007:
            findings.append({"key": "__file__", "reason": f"env_world_accessible:{oct(mode)}"})
        elif mode & 0o020:
            findings.append({"key": "__file__", "reason": f"env_group_writable:{oct(mode)}"})
        elif not mode & 0o040:
            warnings.append({"key": "__file__", "reason": f"env_group_not_readable:{oct(mode)}"})

    report = {
        "ok": not findings,
        "checked_sensitive_keys": sum(1 for key in values if SENSITIVE_NAME.search(key)),
        "critical_count": len(findings),
        "warning_count": len(warnings),
        "critical": findings,
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
