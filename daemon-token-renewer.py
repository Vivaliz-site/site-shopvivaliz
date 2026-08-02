#!/usr/bin/env python3
"""Run the canonical PHP Olist token refresh on the production host."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

PHP_BINARY = "/usr/bin/php"
REFRESH_SCRIPT = Path("api/olist/refresh-token.php")


def renew_once() -> bool:
    run_id = f"systemd-{uuid.uuid4().hex}"
    env = os.environ.copy()
    env["SHOPVIVALIZ_REFRESH_RUN_ID"] = run_id
    env["SHOPVIVALIZ_REFRESH_TRIGGER"] = "systemd-daemon"

    try:
        completed = subprocess.run(
            [PHP_BINARY, str(REFRESH_SCRIPT)],
            check=False,
            capture_output=True,
            text=True,
            timeout=90,
            env=env,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        print(f"[!] Olist refresh launcher failed: {type(exc).__name__}", flush=True)
        return False

    try:
        payload: Any = json.loads(completed.stdout)
    except json.JSONDecodeError:
        print(
            f"[!] Olist refresh returned invalid JSON (exit={completed.returncode})",
            flush=True,
        )
        return False

    if not isinstance(payload, dict):
        print("[!] Olist refresh returned a non-object payload", flush=True)
        return False

    status = str(payload.get("status", ""))
    execution_id = str(payload.get("execution_id", ""))
    timestamp = str(payload.get("timestamp", ""))
    evidence_run_id = str(payload.get("run_id", ""))
    evidence_trigger = str(payload.get("trigger", ""))

    valid = (
        completed.returncode == 0
        and status == "ok"
        and evidence_run_id == run_id
        and evidence_trigger == "systemd-daemon"
        and len(execution_id) == 32
    )

    if valid:
        print(
            "[+] Olist token refreshed "
            f"execution_id={execution_id} timestamp={timestamp}",
            flush=True,
        )
        return True

    print(
        "[!] Olist token refresh failed "
        f"exit={completed.returncode} status={status or 'missing'} "
        f"execution_id={execution_id or 'missing'}",
        flush=True,
    )
    return False


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true", help="Run once and exit")
    parser.add_argument("--interval", type=int, default=7200, help="Interval after success")
    parser.add_argument("--retry-interval", type=int, default=900, help="Interval after failure")
    args = parser.parse_args()

    while True:
        try:
            ok = renew_once()
        except KeyboardInterrupt:
            return 130
        except Exception as exc:  # fail closed without exposing secret values
            print(
                f"[!] Olist refresh failed safely: {type(exc).__name__} "
                f"at {datetime.now(timezone.utc).isoformat()}",
                flush=True,
            )
            ok = False

        if args.once:
            return 0 if ok else 1
        time.sleep(max(60, args.interval if ok else args.retry_interval))


if __name__ == "__main__":
    raise SystemExit(main())
