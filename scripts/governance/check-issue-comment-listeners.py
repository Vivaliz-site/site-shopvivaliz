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

Intentionally dependency-free: GitHub Actions runners used by this
repository's CI do not guarantee PyYAML is installed, and pulling in a new
dependency for a small governance guard is not worth the manifest/lockfile
churn. Instead of a full YAML parser, this is a small, well-tested
YAML-light scanner that only needs to understand the shape of a workflow's
top-level `on:` trigger block, not the full YAML grammar.

Usage:
    python3 scripts/governance/check-issue-comment-listeners.py [workflows_dir]

Exit codes:
    0 - exactly zero or one workflow declares issue_comment as a direct
        top-level trigger.
    1 - two or more workflows declare issue_comment as a direct top-level
        trigger (governance violation).
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_WORKFLOWS_DIR = ROOT / ".github" / "workflows"

SKIP_NAME_MARKERS = (".disabled",)
SKIP_PATH_MARKERS = ("archive", "historical")

# Matches the top-level `on:` key (column 0, exactly "on" — not "onward" or
# similar) with everything after the colon captured as the inline remainder.
TOP_LEVEL_ON = re.compile(r"^on:[ \t]*(.*?)[ \t]*$")

# Matches a top-level mapping key at column 0, e.g. "jobs:", "permissions:".
# Used to find where the `on:` block ends.
TOP_LEVEL_KEY = re.compile(r"^[A-Za-z_][\w.-]*:")

# A mapping key line inside the `on:` block, e.g. "  issue_comment:" or
# "  push:". Captures the leading indentation and the key name.
BLOCK_MAPPING_KEY = re.compile(r"^([ \t]+)([A-Za-z_][\w.-]*)\s*:")

# A sequence item line inside the `on:` block, e.g. "  - issue_comment".
BLOCK_SEQUENCE_ITEM = re.compile(r"^[ \t]*-[ \t]*(.+?)[ \t]*$")


def strip_comment(line: str) -> str:
    """Strip a trailing `# ...` comment from a line, ignoring `#` inside
    quotes. Good enough for the simple values workflow triggers use."""
    in_single = False
    in_double = False
    for i, ch in enumerate(line):
        if ch == "'" and not in_double:
            in_single = not in_single
        elif ch == '"' and not in_single:
            in_double = not in_double
        elif ch == "#" and not in_single and not in_double:
            return line[:i]
    return line


def unquote(token: str) -> str:
    token = token.strip()
    if len(token) >= 2 and token[0] == token[-1] and token[0] in "'\"":
        return token[1:-1]
    return token


def parse_flow_list(value: str) -> list[str]:
    """Parse a YAML flow sequence like `[push, issue_comment]`."""
    inner = value.strip()
    if inner.startswith("[") and inner.endswith("]"):
        inner = inner[1:-1]
    if not inner.strip():
        return []
    return [unquote(part) for part in inner.split(",") if unquote(part)]


def is_ignored(path: Path) -> bool:
    """Return True when `path` must be excluded from the listener count."""
    name = path.name
    if any(marker in name for marker in SKIP_NAME_MARKERS):
        return True
    parts_lower = {part.lower() for part in path.parts}
    return any(marker in parts_lower for marker in SKIP_PATH_MARKERS)


def extract_on_block(lines: list[str]) -> tuple[str, list[str]] | None:
    """Find the top-level `on:` trigger and return (inline_remainder,
    block_lines), where block_lines are the raw lines that make up the
    (possibly empty) nested block following `on:`. Returns None if this
    document has no top-level `on:` key."""
    on_index = None
    inline = ""
    for i, raw_line in enumerate(lines):
        line = strip_comment(raw_line).rstrip("\r\n")
        match = TOP_LEVEL_ON.match(line)
        if match:
            on_index = i
            inline = match.group(1)
            break
    if on_index is None:
        return None

    block: list[str] = []
    for raw_line in lines[on_index + 1 :]:
        line = strip_comment(raw_line).rstrip("\r\n")
        if not line.strip():
            continue
        if TOP_LEVEL_KEY.match(line):
            break
        block.append(line)
    return inline, block


def triggers_from_on_block(inline: str, block: list[str]) -> set[str]:
    """Return the set of direct top-level trigger names declared by an
    `on:` key, given its inline remainder and nested block lines."""
    triggers: set[str] = set()

    inline = inline.strip()
    if inline:
        if inline.startswith("[") or inline.startswith("{"):
            triggers.update(parse_flow_list(inline))
        else:
            # A bare scalar, e.g. `on: issue_comment`.
            triggers.add(unquote(inline))
        return triggers

    if not block:
        return triggers

    # Sequence form: every retained line is a "- item" at some indentation.
    if all(BLOCK_SEQUENCE_ITEM.match(line) for line in block):
        for line in block:
            item = BLOCK_SEQUENCE_ITEM.match(line).group(1)
            triggers.add(unquote(item))
        return triggers

    # Mapping form: only keys at the minimum (outermost) indentation are
    # direct top-level triggers; deeper lines are trigger configuration.
    key_lines = [
        (len(m.group(1).expandtabs()), m.group(2))
        for m in (BLOCK_MAPPING_KEY.match(line) for line in block)
        if m
    ]
    if not key_lines:
        return triggers
    min_indent = min(indent for indent, _ in key_lines)
    triggers.update(name for indent, name in key_lines if indent == min_indent)
    return triggers


def declares_issue_comment_top_level(text: str) -> bool:
    """Return True when a workflow file's `on:` trigger includes
    `issue_comment` as a direct top-level trigger (not nested inside
    another trigger's own configuration)."""
    lines = text.splitlines()
    extracted = extract_on_block(lines)
    if extracted is None:
        return False
    inline, block = extracted
    triggers = triggers_from_on_block(inline, block)
    return "issue_comment" in triggers


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
        if declares_issue_comment_top_level(text):
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
