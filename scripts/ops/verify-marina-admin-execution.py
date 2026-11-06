#!/usr/bin/env python3
"""Fail closed on a sanitized Marina admin task receipt."""
import json
import sys
from pathlib import Path

def verify(r):
    if not isinstance(r, dict) or r.get("host") != "marina-a1-4c24g":
        return "unexpected_host"
    if r.get("command") != "hostname -s; id -un":
        return "unexpected_command"
    if r.get("ok") is not True or r.get("exit_code") != 0:
        return "admin_execution_denied_or_failed"
    if r.get("stdout", "").strip().splitlines() != ["marina-a1-4c24g", "ubuntu"]:
        return "unexpected_output"
    if not r.get("audit_id"):
        return "missing_audit_id"
    return None

if __name__ == "__main__":
    try:
        p = Path(sys.argv[1])
        if p.stat().st_size > 65536:
            raise ValueError("too_large")
        data = json.loads(p.read_text())
        result = verify(data.get("structuredContent", data))
    except (OSError, ValueError, IndexError):
        result = "invalid_receipt"
    print("MARINA_ADMIN_E2E=" + ("PASS" if result is None else "FAIL:" + result))
    sys.exit(0 if result is None else 2)
