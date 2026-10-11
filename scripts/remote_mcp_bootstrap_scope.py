#!/usr/bin/env python3
"""Fail-closed routing between browser-only MCP reload and the four-host bootstrap.

Read changed paths on stdin. No GitHub API, credentials, network or browser
access is needed to classify a trusted Git diff.
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

# Only changes confined to this surface can safely avoid Windows/prod setup.
BROWSER_PREFIXES = ("remote-control-browser-mcp/",)
BROWSER_FILES = frozenset({
    "scripts/setup-remote-control-browser-mcp.sh",
    "scripts/setup-shopvivaliz-display-recovery.sh",
    "scripts/shopvivaliz-xvfb99-prepare.sh",
    "ops/systemd/shopvivaliz-xvfb99.service",
    "tests/test_shopvivaliz_display_recovery.py",
    "scripts/remote_mcp_bootstrap_scope.py",
    "deploy/systemd/shopvivaliz-remote-control-browser-mcp.service",
    "deploy/systemd/shopvivaliz-browser-atendimento-mcp.service",
    "deploy/systemd/shopvivaliz-browser-dev-mcp.service",
    "deploy/systemd/shopvivaliz-browser-universal-mcp.service",
    "tests/remote-control-browser-mcp-test.py",
    "tests/test_chatgpt_access_parity.py",
    "tests/test_remote_mcp_bootstrap_scope.py",
    "docs/knowledge/chatgpt-access-parity.md",
    ".github/workflows/quality-gate.yml",
    ".github/workflows/remote-control-browser-mcp-ci.yml",
    ".github/workflows/remote-control-mcp-bootstrap.yml",
})


def is_browser_only(paths: list[str]) -> bool:
    if not paths:
        return False
    for raw in paths:
        name = raw.strip()
        if not name or name.startswith("/") or "\\" in name or ".." in name.split("/"):
            return False
        if name not in BROWSER_FILES and not any(name.startswith(p) for p in BROWSER_PREFIXES):
            return False
    return True


# A narrowly scoped backend-only MCP upgrade never touches production/Windows.
BACKEND_CONTROLLER_FILES = frozenset({
    "remote-control-mcp/server.py",
    "remote-control-mcp/SPEC.md",
    "scripts/continuity/checkpoint_update_atomic.py",
    "tests/test_orquestrador_atomic_checkpoint.py",
    "tests/remote-control-mcp-test.py",
    "scripts/remote_mcp_bootstrap_scope.py",
    "tests/test_remote_mcp_bootstrap_scope.py",
    ".github/workflows/remote-control-mcp-bootstrap.yml",
    "scripts/should-deploy-production.sh",
    "tests/should-deploy-production-test.sh",
})


def is_backend_controller_only(paths: list[str]) -> bool:
    clean = [path.strip() for path in paths]
    if not clean or len(clean) != len(set(clean)):
        return False
    if any(not name or name.startswith('/') or '\\' in name or '..' in name.split('/') for name in clean):
        return False
    # Require the actual restricted handler AND its implementation together.
    mandatory = {'remote-control-mcp/server.py', 'scripts/continuity/checkpoint_update_atomic.py'}
    return mandatory.issubset(clean) and set(clean).issubset(BACKEND_CONTROLLER_FILES)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--github-output", type=Path)
    args = parser.parse_args()
    paths = sys.stdin.read().splitlines()
    browser_only = is_browser_only(paths)
    backend_controller_only = is_backend_controller_only(paths)
    if args.github_output:
        with args.github_output.open("a", encoding="utf-8") as output:
            output.write("browser_only=" + ("true" if browser_only else "false") + "\n")
            output.write("backend_controller_only=" + ("true" if backend_controller_only else "false") + "\n")
    scope = "BROWSER_ONLY" if browser_only else "BACKEND_CONTROLLER_ONLY" if backend_controller_only else "FULL"
    print("REMOTE_CONTROL_BOOTSTRAP_SCOPE=" + scope)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
