#!/usr/bin/env python3
"""Fail closed unless the live controller really runs the reviewed V5 code.

The systemd daemon imports agent_task_state from its own immutable /opt
release. The web deployment's current/ tree is a *different* artifact and
must never be treated as proof of the controller's running code.
"""
from __future__ import annotations

import argparse
import ast
import hashlib
import os
from pathlib import Path
import re
import subprocess
import sys

REQUIRED_FUNCTIONS = {
    "_require_verified_chatgpt_origin_on_create",
    "_require_extreme_audit_proof",
    "pin_audit_certifier",
}
REQUIRED_TOKENS = {"EXTREME_AUDIT_GOAL_RE", "AUDIT_VERDICT=APTO"}
CONTROLLER_RELEASES = Path("/opt/shopvivaliz-gemini-24x7-controller/releases")
CONTROLLER_UNIT = "shopvivaliz-gemini-24x7-controller.service"


def _digest(path: Path) -> str:
    if not path.is_file():
        raise ValueError(f"required code file missing: {path}")
    data = path.read_bytes()
    ast.parse(data, filename=str(path))
    return hashlib.sha256(data).hexdigest()


def inspect(path: Path) -> str:
    if not path.is_file():
        raise ValueError(f"controller guard file missing: {path}")
    source = path.read_text(encoding="utf-8")
    tree = ast.parse(source, filename=str(path))
    functions = {n.name for n in ast.walk(tree) if isinstance(n, (ast.FunctionDef, ast.AsyncFunctionDef))}
    missing = {f"function:{name}" for name in REQUIRED_FUNCTIONS - functions}
    missing.update(f"marker:{token}" for token in REQUIRED_TOKENS if token not in source)
    if "pin-audit-certifier" not in source:
        missing.add("CLI:pin-audit-certifier")
    if missing:
        raise ValueError(f"{path}: missing V5 protections: {', '.join(sorted(missing))}")
    return hashlib.sha256(source.encode("utf-8")).hexdigest()


def live_controller_scripts(service: str) -> Path:
    if service != CONTROLLER_UNIT:
        raise ValueError("controller service not allowlisted")
    result = subprocess.run(
        ["systemctl", "show", service, "--property=MainPID", "--value"],
        capture_output=True, text=True, timeout=6, check=False,
    )
    if result.returncode != 0:
        raise ValueError("controller service identity not available")
    pid_text = result.stdout.strip()
    if not pid_text.isdecimal() or int(pid_text) <= 1:
        raise ValueError("controller daemon not running")
    raw = (Path("/proc") / pid_text / "cmdline").read_bytes()
    argv = raw.rstrip(b"\0").split(b"\0")
    if len(argv) < 2 or Path(os.fsdecode(argv[0])).name not in {"python3", "python3.11", "python3.12", "python3.13"}:
        raise ValueError("live controller process identity mismatch")
    entry = Path(os.fsdecode(argv[1])).resolve(strict=True)
    try:
        relative = entry.relative_to(CONTROLLER_RELEASES.resolve(strict=True))
    except ValueError as exc:
        raise ValueError("controller process is outside immutable releases") from exc
    if (len(relative.parts) != 3 or re.fullmatch(r"[0-9a-f]{40}", relative.parts[0]) is None
            or relative.parts[1:] != ("scripts", "gemini_24x7_controller.py")):
        raise ValueError("controller entrypoint has unexpected release identity")
    return entry.parent


def compare_exact(reference: Path, active: Path) -> None:
    if _digest(reference) != _digest(active):
        raise ValueError(f"reviewed code differs from active immutable controller: {active.name}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--reference", type=Path, required=True)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--active", type=Path)
    group.add_argument("--live-service", type=str)
    args = parser.parse_args()
    try:
        reference_sha = inspect(args.reference)
        if args.live_service:
            directory = live_controller_scripts(args.live_service)
            active = directory / "agent_task_state.py"
        else:
            directory = None
            active = args.active
        if inspect(active) != reference_sha:
            raise ValueError("active agent_task_state.py differs from reviewed V5 implementation")
        if directory is not None:
            for name in ("gemini_24x7_controller.py", "certify-audit-manifest.py"):
                compare_exact(args.reference.parent / name, directory / name)
    except (ValueError, OSError, SyntaxError, UnicodeError, subprocess.SubprocessError) as exc:
        print(f"AUDIT_CONTROLLER_PARITY=FAIL {exc}", file=sys.stderr)
        return 1
    print("AUDIT_CONTROLLER_PARITY=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
