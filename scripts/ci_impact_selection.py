#!/usr/bin/env python3
"""Fail-closed scope selection for the ShopVivaliz mandatory PR validation gate.

Only isolated, specifically audited paths can use a focused test suite.
Everything else (especially dependencies, infrastructure and security) is full.
"""
from __future__ import annotations

import argparse
from fnmatch import fnmatchcase
import json
import re
import subprocess
import sys
from pathlib import Path

SCOPED = {
    "shopee": (
        "scripts/shopee_logistics_worker.py",
        "scripts/shopee_logistics_watchdog.py",
        "tests/test_shopee_logistics_worker.py",
        "tests/test_shopee_logistics_watchdog.py",
        "tests/test_shopee_logistics_systemd.py",
    ),
    "continuity": (
        "scripts/task_continuation_watchdog.py",
        "scripts/task_resume_queue.py",
        "scripts/task_resume_dispatcher.py",
        "tests/test_task_continuation_watchdog.py",
        "tests/test_task_resume_queue.py",
        "tests/test_task_resume_dispatcher.py",
        "docs/knowledge/task-continuity.md",
    ),
    "mail": (
        "tests/shopvivaliz-mail-legacy-provider-guard-test.py",
        "tests/shopvivaliz-brevo-mailer-contract-test.php",
        "tests/shopvivaliz-brevo-mailer-runtime-test.php",
        "tests/cloudflare-email-titan-finalize-test.php",
        "tests/test_stock_alerts_brevo_mail.py",
    ),
}
DOCS = (
    "README.md",
    "docs/knowledge/*.md",
    "docs/ops/*.md",
    "docs/audits/*.md",
    "docs/superpowers/*.md",
    "docs/superpowers/**/*.md",
)
# Policy, audit certifiers, workflows, dependencies and shared application code
# deliberately do not appear in the fast whitelist.
ALWAYS_FULL = (
    "AUDIT_POLICY.md",
    "AGENTS.md",
    "CLAUDE.md",
    "GEMINI.md",
    "REGRAS-AGENTES-CENTRALIZADAS.md",
    "docs/knowledge/atendimento-chatgpt-bootstrap.md",
    "docs/knowledge/agent-rules.md",
    "docs/knowledge/host-access.md",
    "docs/knowledge/README.md",
    "docs/ops/cloudflare*",
    "docs/quality/*",
    ".github/*",
    ".github/**/*",
    "composer.json",
    "composer.lock",
    "package.json",
    "package-lock.json",
    "scripts/repository-governance-validate.sh",
    "scripts/ci_impact_selection.py",
    "tests/test_ci_impact_selection.py",
)
SHA_PATTERN = re.compile(r"^[0-9a-f]{40}$")


def classify(paths: list[str]) -> dict[str, object]:
    """Return a deterministic, auditable decision, never skipping unknown paths."""
    files = sorted(set(p.strip().replace("\\", "/").removeprefix("./") for p in paths if p.strip()))
    groups: set[str] = set()
    blockers: list[str] = []
    if not files:
        blockers.append("no_changes_or_diff_unavailable")
    for path in files:
        if path.startswith("/") or path.startswith("../") or "//" in path or ".." in path.split("/"):
            blockers.append(path)
        elif any(fnmatchcase(path, p) for p in ALWAYS_FULL):
            blockers.append(path)
        else:
            matched = [group for group, rules in SCOPED.items() if path in rules]
            if matched:
                groups.update(matched)
            elif any(fnmatchcase(path, p) for p in DOCS):
                groups.add("docs")
            else:
                blockers.append(path)
    fast = not blockers
    effective_groups = sorted(groups) if fast else ["full"]
    return {
        "mode": "scoped" if fast else "full",
        "groups": effective_groups,
        "changed_files": files,
        "full_reasons": sorted(set(blockers)),
        "needs_php": bool(not fast or "mail" in groups),
    }


def changed_files(base: str, head: str) -> list[str] | None:
    if not SHA_PATTERN.fullmatch(base) or not SHA_PATTERN.fullmatch(head):
        return None
    try:
        result = subprocess.run(
            # All statuses, including deletions: deleted policy files can never
            # be silently ignored when mixed with scoped documentation changes.
            ["git", "diff", "--name-only", "-z", f"{base}...{head}"],
            check=True, capture_output=True, timeout=30,
        )
        return [p.decode("utf-8", errors="replace") for p in result.stdout.split(b"\x00") if p]
    except (OSError, subprocess.SubprocessError):
        return None


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--file", action="append", default=[])
    parser.add_argument("--base", default="")
    parser.add_argument("--head", default="")
    parser.add_argument("--github-output", default="")
    args = parser.parse_args(argv)
    files = args.file if args.file else changed_files(args.base, args.head)
    result = classify(files or [])
    if files is None:
        result["full_reasons"] = ["unavailable_or_untrusted_diff"]
    if args.github_output:
        out = Path(args.github_output)
        with out.open("a", encoding="utf-8") as fh:
            for key, value in (
                ("mode", result["mode"]),
                ("groups", ",".join(result["groups"])),
                ("needs_php", str(result["needs_php"]).lower()),
            ):
                fh.write(f"{key}={value}\n")
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
