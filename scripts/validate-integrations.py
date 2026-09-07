#!/usr/bin/env python3
"""Run the real, sanitized integration-health probes.

This compatibility entrypoint intentionally contains no synthetic metrics and
never prints credentials or provider payloads.
"""
from __future__ import annotations

import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_HEALTH = ROOT / "includes/integration-health.php"


def main() -> int:
    code = (
        "require " + repr(str(PHP_HEALTH)) + "; "
        "$r=svih_check_all(false); echo json_encode($r);"
    )
    proc = subprocess.run(
        ["php", "-r", code], cwd=ROOT, text=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120,
    )
    if proc.returncode != 0:
        print("INTEGRATION_PROBE_FAILED reason=php_probe_error")
        return 1
    try:
        result = json.loads(proc.stdout)
    except json.JSONDecodeError:
        print("INTEGRATION_PROBE_FAILED reason=invalid_json")
        return 1

    integrations = result.get("integrations") or []
    for item in integrations:
        if not isinstance(item, dict):
            continue
        key = str(item.get("key") or "unknown")
        status = str(item.get("status") or "unknown")
        provider_status = str(item.get("provider_status") or "-")
        print(f"INTEGRATION key={key} status={status} provider_status={provider_status}")

    summary = result.get("summary") or {}
    failed = int(summary.get("failed") or 0)
    print(f"INTEGRATION_SUMMARY connected={int(summary.get('connected') or 0)} failed={failed} attention={int(summary.get('attention') or 0)}")
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
