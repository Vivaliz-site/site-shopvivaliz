#!/usr/bin/env python3
"""Fail-closed OKX PAPER decision health watchdog, with deduplicated alerts."""
from __future__ import annotations
import argparse
from datetime import datetime, timezone
import fcntl
import json
import os
import re
from pathlib import Path
import subprocess
import sys

STATUS_DEFAULT = Path("/var/lib/shopvivaliz-okx-paper/paper-status-v3.json")
STATE_DEFAULT = Path("/var/lib/shopvivaliz-okx-watchdog/state.json")
REPO = "Vivaliz-site/site-shopvivaliz"
ISSUE = "1586"
ALERT_REPEAT_SECONDS = 6 * 3600


def evaluate(status_path: Path, now: datetime, max_age: int = 240):
    try:
        data = json.loads(status_path.read_text(encoding="utf-8"))
        updated = datetime.fromisoformat(str(data["updated_at"]))
        if updated.tzinfo is None:
            raise ValueError("naive_timestamp")
        age = (now - updated).total_seconds()
        if age < -60 or age > max_age:
            return "DEGRADED", "status_stale", data
        if data.get("mode") != "PAPER" or data.get("real_orders_enabled") is not False:
            return "CRITICAL", "paper_only_boundary_invalid", data
        if data.get("ai_20_layers_active") is not True:
            error = str(data.get("decision_provider_last_error") or "no_valid_ai_decision")
            if not re.fullmatch(r"[A-Za-z0-9_.:-]{1,100}", error):
                error = "unclassified_error_redacted"
            return "DEGRADED", "decision_provider_inactive:" + error, data
        return "HEALTHY", "ok", data
    except (OSError, ValueError, KeyError, TypeError, json.JSONDecodeError):
        return "DEGRADED", "status_unavailable_or_invalid", {}


def notify(kind: str, reason: str, data: dict, runner=subprocess.run):
    details = {
        "status": kind,
        "reason": reason,
        "mode": data.get("mode", "unknown"),
        "decision_provider": data.get("decision_provider", "unknown"),
        "last_updated": data.get("updated_at", "unknown"),
        "cycle": data.get("cycle", "unknown"),
        "closed_trades": data.get("closed_trades", "unknown"),
        "real_orders_enabled": data.get("real_orders_enabled", "unknown"),
    }
    body = "OKX_PAPER_PROVIDER_" + kind + "\n" + json.dumps(details, sort_keys=True, default=str)
    runner(
        ["runuser", "-u", "ubuntu", "--", "env", "HOME=/home/ubuntu",
         "gh", "issue", "comment", ISSUE, "--repo", REPO, "--body", body],
        check=True, timeout=25, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )


def check_and_alert(status_path: Path, state_path: Path, now: datetime,
                    dry_run: bool = False, notify_fn=notify):
    status, reason, data = evaluate(status_path, now)
    print(json.dumps({"health": status, "reason": reason,
                      "status_updated_at": data.get("updated_at"),
                      "real_orders_enabled": data.get("real_orders_enabled")}, sort_keys=True), flush=True)
    if dry_run:
        return status

    state_path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    lock_path = state_path.with_suffix(".lock")
    with lock_path.open("a+") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        try:
            prior = json.loads(state_path.read_text()) if state_path.exists() else {}
        except (ValueError, OSError):
            prior = {}
        epoch = int(now.timestamp())
        previous = prior.get("health")
        last_alert = int(prior.get("last_alert_epoch", 0))
        do_notify = False
        if status != "HEALTHY":
            do_notify = (status != previous or epoch - last_alert >= ALERT_REPEAT_SECONDS)
        elif previous and previous != "HEALTHY":
            do_notify = True
        if do_notify:
            notify_fn(status, reason, data)
            last_alert = epoch
        new_state = {"health": status, "reason": reason,
                     "last_checked_epoch": epoch, "last_alert_epoch": last_alert}
        tmp = state_path.with_suffix(".tmp")
        with tmp.open("w") as fh:
            json.dump(new_state, fh, sort_keys=True)
            fh.flush()
            os.fsync(fh.fileno())
        os.chmod(tmp, 0o600)
        tmp.replace(state_path)
    return status


def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--status-file", type=Path, default=STATUS_DEFAULT)
    parser.add_argument("--state-file", type=Path, default=STATE_DEFAULT)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args(argv)
    try:
        state = check_and_alert(args.status_file, args.state_file,
                                datetime.now(timezone.utc), args.dry_run)
    except (OSError, subprocess.SubprocessError) as exc:
        print("ALERT_DELIVERY_FAILED:" + type(exc).__name__, file=sys.stderr)
        return 3
    return 0 if state == "HEALTHY" else 2


if __name__ == "__main__":
    sys.exit(main())
