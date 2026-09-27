#!/usr/bin/env python3
"""Select expensive PR gates only when the changed surface makes them relevant."""
from __future__ import annotations

import argparse
import json
from fnmatch import fnmatch
from typing import Iterable

RULES: tuple[tuple[str, tuple[str, ...]], ...] = (
    ("Quality Gate", (
        "blog/**", "includes/blog-**", "tests/blog-**", "tests/google-indexing-**",
        "tests/asset-source-**", "tests/claude-workflow-**", "tests/nondeploy-release-**",
        "package.json", "package-lock.json", "composer.json", "composer.lock",
        ".github/workflows/quality-gate.yml",
    )),
    ("ShopVivaliz QA", (
        "api/**", "admin/**", "includes/**", "public/**", "assets/**", "blog/**",
        "*.php", ".github/workflows/shopvivaliz-qa.yml",
    )),
    ("Policy Engine", (
        "api/**", "includes/**", "public/**", "assets/**", "erp/**",
        "scripts/check-erp.js", "scripts/policy*", "scripts/*policy*",
        ".github/workflows/policy-engine.yml",
    )),
    ("Autonomy Boundary", (
        "api/**", "admin/**", "includes/**", "scripts/**price**", "scripts/**stock**",
        "scripts/**inventory**", "scripts/enforce-autonomy-policy.py",
        ".github/workflows/autonomy-boundary.yml",
    )),
    ("Ecommerce Excellence Audit", (
        "api/**", "admin/**", "includes/**", "public/**", "assets/**", "*.php",
        "scripts/ecommerce*", "tests/test_ecommerce*",
        ".github/workflows/ecommerce-excellence-audit.yml",
    )),
    ("PR Policy Enforcement", (
        "scripts/check_pr_completion_policy.py", "scripts/pr_conflict_gemini_healer.py",
        "scripts/pr_gate_replay.py", "scripts/pr_gate_scope.py",
        "scripts/pr_conflict_vm_heal.sh", "scripts/merge_green_pr_via_vm.sh",
        ".github/workflows/pr-policy-enforcement.yml",
        ".github/workflows/pr-completion-enforcer.yml",
        ".github/workflows/pr-conflict-auto-healer.yml",
    )),
)

def _matches(path: str, pattern: str) -> bool:
    path = path.strip().lstrip("./")
    return bool(path) and fnmatch(path, pattern)

def required_specialized_gates(changed_files: Iterable[str]) -> list[str]:
    files = [str(path).strip().lstrip("./") for path in changed_files if str(path).strip()]
    selected: list[str] = []
    for gate, patterns in RULES:
        if any(_matches(path, pattern) for path in files for pattern in patterns):
            selected.append(gate)
    return selected

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--file", action="append", default=[])
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()
    gates = required_specialized_gates(args.file)
    if args.json:
        print(json.dumps(gates, ensure_ascii=False))
    elif gates:
        print("\n".join(gates))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
