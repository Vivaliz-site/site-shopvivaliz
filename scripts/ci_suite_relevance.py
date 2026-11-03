#!/usr/bin/env python3
"""Decide whether one gate suite must run for the files changed in a pull request.

A suite runs when a changed file is named by its command, imported by module name,
or mentioned in the source it reads. Changes to the gate itself run every suite.
Opaque commands run too, so the default is to validate.
"""
from __future__ import annotations

import argparse
import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

# Changing any of these can change what the gate verifies, so every suite runs.
GATE_CORE = (
    ".github/",
    "scripts/repository-governance-validate.sh",
    "scripts/ci_",
    "scripts/enforce-autonomy-policy.py",
    "scripts/maintenance/",
    "scripts/governance/",
    "security/",
    "composer.json",
    "composer.lock",
    "package.json",
    "package-lock.json",
    "AGENTS.md",
    "CLAUDE.md",
    "AUDIT_POLICY.md",
    "REGRAS-AGENTES-CENTRALIZADAS.md",
)

PATH_TOKEN = re.compile(r"[A-Za-z0-9_./-]+\.(?:py|php|phpt|mjs|sh|js|json|md|yml|yaml)\b")
MODULE_TOKEN = re.compile(r"\btests\.[A-Za-z0-9_.]+")


def _norm(text: str) -> str:
    return text.lower().replace("_", "-")


def _stem(path: str) -> str:
    return Path(path).stem


def _resolve_module(module: str) -> list[str]:
    parts = module.split(".")
    candidates = [
        "/".join(parts) + ".py",
        "/".join(parts[:-1]) + ".py" if len(parts) > 1 else "",
    ]
    return [c for c in candidates if c and (ROOT / c).is_file()]


def suite_sources(command: str) -> tuple[set[str], str]:
    """Return (files the suite names or runs, combined source text it reads)."""
    files: set[str] = set()
    for token in PATH_TOKEN.findall(command):
        if (ROOT / token).is_file():
            files.add(token)
    for module in MODULE_TOKEN.findall(command):
        files.update(_resolve_module(module))
    text_parts: list[str] = []
    for rel in sorted(files):
        try:
            text_parts.append((ROOT / rel).read_text(encoding="utf-8", errors="replace"))
        except OSError:
            pass
    return files, "\n".join(text_parts)


def decide(command: str, changed: list[str]) -> tuple[bool, str]:
    """Return (run, reason). Opaque commands and gate-core changes always run."""
    if not changed:
        return True, "no_diff_available"
    for path in changed:
        if path.startswith(GATE_CORE) or path in GATE_CORE:
            return True, f"gate_core_changed:{path}"
    files, source = suite_sources(command)
    if not files:
        return True, "opaque_command"
    if any(path in files for path in changed):
        return True, "suite_file_changed"
    haystack = _norm(source + "\n" + command)
    for path in changed:
        if path in source or path in command:
            return True, f"path_referenced:{path}"
        stem = _stem(path)
        # Short stems ("add", "x") match too much; only the full path counts for them.
        if len(stem) >= 4 and _norm(stem) in haystack:
            return True, f"stem_referenced:{path}"
    return False, "no_related_path_changed"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--command", required=True)
    parser.add_argument("--changed-file", action="append", default=[])
    parser.add_argument("--changed-list", default="")
    args = parser.parse_args(argv)
    changed = list(args.changed_file)
    if args.changed_list and Path(args.changed_list).is_file():
        changed += [line.strip() for line in Path(args.changed_list).read_text().splitlines() if line.strip()]
    run, reason = decide(args.command, changed)
    print(("RUN" if run else "SKIP") + f"\t{reason}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
