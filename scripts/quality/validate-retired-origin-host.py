#!/usr/bin/env python3
"""Fail closed when new executable files point at the retired origin host.

The origin `shopvivaliz-free-a1` (10.0.1.112) was retired on 2026-10-29 and must
never be a target again. Existing references are frozen in BASELINE as known debt
until each one is reviewed and repaired; any other tracked file that mentions the
address fails this check. Documentation (*.md, docs/) is out of scope.
"""
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

RETIRED_ORIGIN_IP = "10.0.1.112"

BASELINE = frozenset({
    ".github/workflows/oci-bastion-private-access-bootstrap.yml",
    ".github/workflows/remote-control-mcp-bootstrap.yml",
    "config/tri-environment-sync.json",
    "remote-control-mcp/server.py",
    "scripts/chatgpt_continuity_nudge_dispatcher.py",
    "scripts/desktop-commander-four-host-sentinel.sh",
    "scripts/oci-private-access-bootstrap.sh",
    "scripts/setup-iphone-private-ssh-relay.sh",
    "scripts/windows/marina-precutover-preflight.ps1",
    "tests/iphone-private-ssh-relay-contract-test.py",
    "tests/oci-private-access-bootstrap-contract-test.php",
    "tests/remote-control-mcp-test.py",
    "tests/test_marina_precutover_safety.py",
    "tests/test_repository_workflow_governance_remediation.py",
})


GUARD_FILES = frozenset({
    "scripts/quality/validate-retired-origin-host.py",
    "tests/test_retired_origin_host_guard.py",
})


def is_in_scope(path: str) -> bool:
    return not path.endswith(".md") and not path.startswith("docs/") and path not in GUARD_FILES


def find_violations(files: dict[str, str]) -> list[str]:
    """Return sorted paths that mention the retired origin outside the baseline."""
    return sorted(
        path
        for path, content in files.items()
        if is_in_scope(path) and path not in BASELINE and RETIRED_ORIGIN_IP in content
    )


def tracked_files(root: Path) -> dict[str, str]:
    listing = subprocess.run(
        ["git", "-C", str(root), "grep", "-l", "-F", RETIRED_ORIGIN_IP],
        capture_output=True, text=True, check=False,
    )
    files: dict[str, str] = {}
    for rel in listing.stdout.splitlines():
        files[rel] = (root / rel).read_text(encoding="utf-8", errors="replace")
    return files


def main() -> int:
    root = Path(__file__).resolve().parents[2]
    violations = find_violations(tracked_files(root))
    if violations:
        for path in violations:
            print(f"RETIRED_ORIGIN_REFERENCE_NOT_ALLOWED={path}")
        print("validate-retired-origin-host: FAIL")
        return 1
    print(f"validate-retired-origin-host: OK (baseline={len(BASELINE)} known files)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
