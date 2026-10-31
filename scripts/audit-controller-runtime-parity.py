#!/usr/bin/env python3
"""Fail closed when the active continuity controller predates Extreme Audit V5 guards.

Run on the controller host, comparing the canonical checkout against the active
immutable release. This does not update a live release or certify UI evidence.
"""
from __future__ import annotations

import argparse
import ast
from pathlib import Path
import sys

REQUIRED_FUNCTIONS = {
    "_require_verified_chatgpt_origin_on_create",
    "_require_extreme_audit_proof",
    "pin_audit_certifier",
}
REQUIRED_TOKENS = {
    "EXTREME_AUDIT_GOAL_RE",
    "AUDIT_VERDICT=APTO",
}


def inspect(path: Path) -> set[str]:
    if not path.is_file():
        raise ValueError(f"controller file missing: {path}")
    source = path.read_text(encoding="utf-8")
    tree = ast.parse(source, filename=str(path))
    functions = {n.name for n in ast.walk(tree) if isinstance(n, (ast.FunctionDef, ast.AsyncFunctionDef))}
    missing = {f"function:{name}" for name in REQUIRED_FUNCTIONS - functions}
    missing.update(f"marker:{token}" for token in REQUIRED_TOKENS if token not in source)
    if "pin-audit-certifier" not in source:
        missing.add("CLI:pin-audit-certifier")
    if missing:
        raise ValueError(f"{path}: missing V5 protections: {', '.join(sorted(missing))}")
    return functions


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--reference", type=Path, required=True)
    parser.add_argument("--active", type=Path, required=True)
    args = parser.parse_args()
    try:
        inspect(args.reference)
        inspect(args.active)
    except (ValueError, OSError, SyntaxError) as exc:
        print(f"AUDIT_CONTROLLER_PARITY=FAIL {exc}", file=sys.stderr)
        return 1
    print("AUDIT_CONTROLLER_PARITY=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
