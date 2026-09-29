#!/usr/bin/env python3
"""Governance guard: enforce a single `issue_comment` top-level listener.

This repository intentionally centralizes handling of `issue_comment` GitHub
events in a single dispatcher workflow
(`.github/workflows/issue-comment-dispatcher.yml`), which routes to
`scripts/issue-comment-router.py`. Any other workflow that independently
declares `issue_comment` as a direct top-level trigger duplicates that
listener, which has previously caused double-processing of the same comment
and conflicting bot responses.

This script scans `.github/workflows/*.yml` (and `*.yaml`), skips any file
whose name contains `.disabled` and any path containing an `archive` or
`historical` segment, and counts how many of the remaining workflows declare
`issue_comment` as a direct top-level trigger (i.e. top-level `on:
issue_comment`, or `on:` as a list/dict containing `issue_comment`). It
prints the count and the offending file list, and exits non-zero when more
than one such workflow exists.

Usage:
    python3 scripts/governance/check-issue-comment-listeners.py [workflows_dir]

Exit codes:
    0 - exactly zero or one workflow declares issue_comment as a direct
        top-level trigger.
    1 - two or more workflows declare issue_comment as a direct top-level
        trigger (governance violation).
"""
from __future__ import annotations

import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_WORKFLOWS_DIR = ROOT / ".github" / "workflows"

# PyYAML's default (YAML 1.1) resolver treats the bare word `on` as the
# boolean `True` when it appears as a mapping key, so a workflow's top-level
# `on:` key is parsed back as the Python key `True`, not the string "on".
# Both are accepted here so this guard is not fooled by that quirk.
ON_KEYS = ("on", True)

SKIP_NAME_MARKERS = (".disabled",)
SKIP_PATH_MARKERS = ("archive", "historical")


def is_ignored(path: Path) -> bool:
    """Return True when `path` must be excluded from the listener count."""
    name = path.name
    if any(marker in name for marker in SKIP_NAME_MARKERS):
        return True
    parts_lower = {part.lower() for part in path.parts}
    return any(marker in parts_lower for marker in SKIP_PATH_MARKERS)


def declares_issue_comment_top_level(document: object) -> bool:
    """Return True when a parsed workflow document's `on:` trigger includes
    `issue_comment` as a direct top-level trigger (not nested inside another
    trigger's configuration)."""
    if not isinstance(document, dict):
        return False

    trigger = None
    for key in ON_KEYS:
        if key in document:
            trigger = document[key]
            break
    if trigger is None:
        return False

    if isinstance(trigger, str):
        return trigger == "issue_comment"
    if isinstance(trigger, list):
        return "issue_comment" in trigger
    if isinstance(trigger, dict):
        return "issue_comment" in trigger
    return False


def find_workflow_files(workflows_dir: Path) -> list[Path]:
    if not workflows_dir.is_dir():
        return []
    files = [
        path
        for path in workflows_dir.iterdir()
        if path.is_file() and path.suffix in (".yml", ".yaml")
    ]
    return sorted(f for f in files if not is_ignored(f))


def find_issue_comment_listeners(workflows_dir: Path) -> list[Path]:
    """Return the sorted list of workflow files (under `workflows_dir`,
    honoring the same exclusions) that declare `issue_comment` as a direct
    top-level trigger."""
    listeners: list[Path] = []
    for path in find_workflow_files(workflows_dir):
        try:
            text = path.read_text(encoding="utf-8")
        except OSError:
            continue
        try:
            document = yaml.safe_load(text)
        except yaml.YAMLError:
            continue
        if declares_issue_comment_top_level(document):
            listeners.append(path)
    return listeners


def main(argv: list[str]) -> int:
    workflows_dir = Path(argv[0]).resolve() if argv else DEFAULT_WORKFLOWS_DIR
    listeners = find_issue_comment_listeners(workflows_dir)

    print(f"issue_comment top-level listeners found: {len(listeners)}")
    for path in listeners:
        print(f"  - {path}")

    if len(listeners) > 1:
        print(
            "GOVERNANCE VIOLATION: more than one workflow declares "
            "`issue_comment` as a direct top-level trigger. Centralize "
            "handling in the single dispatcher "
            "(.github/workflows/issue-comment-dispatcher.yml) instead.",
            file=sys.stderr,
        )
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
