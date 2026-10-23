#!/usr/bin/env python3
"""Root-only origin timer+cron retirement. No signed ticket => NO MUTATION.

This fixed helper is installed outside immutable production releases, only
through the officially authorized maintenance rollout. Signed approval is read
from stdin (never command-line args or audit output), then checked independently
on the source against its own protected trusted-operator registry.
"""
from __future__ import annotations
import argparse
import fcntl
import hashlib
import json
import os
import socket
import stat
import subprocess
import sys
from contextlib import ExitStack
from pathlib import Path
from maintenance_cart import (_LockedLedger, SOURCE_HOST, TIMER, MaintenanceDenied,
                              verify_ticket)

FENCE = Path("/etc/shopvivaliz/migration/origin-workers-fenced")
CRON_LOCK = Path("/var/lock/shopvivaliz-abandoned-cart-email.lock")
TIMER_LOCK = Path("/home/ubuntu/shopvivaliz-deploy/shared/locks/abandoned-cart-recovery.lock")
EXPECTED_CRON = ("*/30 * * * * flock -n /var/lock/shopvivaliz-abandoned-cart-email.lock php "
                 "/home/ubuntu/shopvivaliz-deploy/current/scripts/send-abandoned-cart-emails.php "
                 ">> /home/ubuntu/shopvivaliz-deploy/shared/logs/abandoned-cart-email.log 2>&1")

def _run(cmd: list[str], *, data: bytes | None = None, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    r = subprocess.run(cmd, input=data, capture_output=True, timeout=15, check=False)
    if check and r.returncode != 0:
        raise MaintenanceDenied("system_operation_or_readback_failed")
    return r

def _identity() -> None:
    if os.geteuid() != 0 or socket.gethostname().split(".")[0] != SOURCE_HOST:
        raise MaintenanceDenied("wrong_host_or_privilege")

def _read_cron() -> bytes:
    p = _run(["crontab", "-u", "ubuntu", "-l"], check=False)
    if p.returncode == 0:
        if len(p.stdout) > 65536 or b"\0" in p.stdout:
            raise MaintenanceDenied("crontab_unsafe")
        return p.stdout
    if p.returncode == 1 and b"no crontab for ubuntu" in p.stderr.lower():
        return b""
    raise MaintenanceDenied("crontab_read_failed")

def _count_legacy(cron: bytes) -> int:
    try:
        rows = cron.decode("utf-8").splitlines()
    except UnicodeDecodeError as exc:
        raise MaintenanceDenied("crontab_encoding_invalid") from exc
    matches = [x for x in rows if "send-abandoned-cart-emails.php" in x]
    if len(matches) > 1 or any(x != EXPECTED_CRON for x in matches):
        raise MaintenanceDenied("crontab_sender_unexpected")
    return len(matches)

def _timer_state() -> tuple[str, str]:
    active = _run(["systemctl", "show", TIMER, "-p", "ActiveState", "--value"])
    enabled = _run(["systemctl", "is-enabled", TIMER], check=False)
    if enabled.returncode not in (0, 1):
        raise MaintenanceDenied("timer_read_failed")
    return active.stdout.decode().strip(), enabled.stdout.decode().strip()

def inspect() -> dict[str, object]:
    _identity()
    cron = _read_cron()
    active, enabled = _timer_state()
    return {"host": SOURCE_HOST, "fence_present": FENCE.is_file(),
            "cron_entry_count": _count_legacy(cron), "cron_sha256": hashlib.sha256(cron).hexdigest(),
            "timer_active": active, "timer_enabled": enabled}

def _lock_writers(stack: ExitStack) -> None:
    # The legacy cron and systemd unit hold DIFFERENT flock -n lock files.
    # Hold both simultaneously so neither can begin an email delivery.
    for path in sorted((CRON_LOCK, TIMER_LOCK), key=lambda p: str(p)):
        fd = os.open(str(path), os.O_CREAT | os.O_RDWR | getattr(os, "O_NOFOLLOW", 0), 0o660)
        file = stack.enter_context(os.fdopen(fd, "rb+"))
        if not stat.S_ISREG(os.fstat(file.fileno()).st_mode):
            raise MaintenanceDenied("sender_lock_unsafe")
        try:
            fcntl.flock(file.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as exc:
            raise MaintenanceDenied("sender_currently_running") from exc

def _remove_one_cron(original: bytes) -> None:
    rows = original.decode("utf-8").splitlines(keepends=True)
    retained = [row for row in rows if row.rstrip("\r\n") != EXPECTED_CRON]
    if len(rows) - len(retained) != 1:
        raise MaintenanceDenied("crontab_sender_cas_invalid")
    _run(["crontab", "-u", "ubuntu", "-"], data="".join(retained).encode("utf-8"))

def retire(ticket: dict[str, object], signature_b64: str) -> dict[str, object]:
    _identity()
    signer = verify_ticket(ticket, signature_b64)
    if not FENCE.is_file() or FENCE.stat().st_uid != 0:
        raise MaintenanceDenied("source_fence_not_trusted")
    with ExitStack() as locks:
        _lock_writers(locks)
        before = inspect()
        if (before["cron_entry_count"] != 1 or before["cron_sha256"] != ticket["source_cron_sha256"]
                or before["timer_active"] != "active" or before["timer_enabled"] != "enabled"):
            raise MaintenanceDenied("source_changed_since_approval")
        sender = _run(["systemctl", "is-active", "shopvivaliz-abandoned-cart-recovery.service"], check=False)
        if sender.stdout.strip() == b"active":
            raise MaintenanceDenied("sender_service_running")
        unit = _run(["systemctl", "show", "shopvivaliz-abandoned-cart-recovery.service", "-p", "ExecStart", "--value"])
        if str(TIMER_LOCK).encode() not in unit.stdout:
            raise MaintenanceDenied("timer_lock_contract_mismatch")
        with _LockedLedger() as ledger:
            ledger.claim(ticket["approval_id"], signer)
            status = "indeterminate"
            try:
                cron = _read_cron()
                if hashlib.sha256(cron).hexdigest() != ticket["source_cron_sha256"]:
                    raise MaintenanceDenied("crontab_last_cas_failed")
                # Store original cron on SOURCE only, never in chat/Git/logs.
                backup_path = ledger.directory / (ticket["approval_id"] + ".cron.backup")
                fd = os.open(str(backup_path), os.O_WRONLY | os.O_EXCL | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0), 0o600)
                with os.fdopen(fd, "wb") as backup:
                    backup.write(cron)
                    backup.flush()
                    os.fsync(backup.fileno())
                _run(["systemctl", "stop", TIMER])
                _run(["systemctl", "disable", TIMER])
                _remove_one_cron(cron)
                after = inspect()
                if (after["timer_active"] != "inactive" or after["timer_enabled"] != "disabled"
                        or after["cron_entry_count"] != 0):
                    raise MaintenanceDenied("origin_retirement_postcondition_failed")
                status = "applied"
                return {"source_host": SOURCE_HOST, "retirement": "APPLIED",
                        "timer_active": "inactive", "timer_enabled": "disabled", "cron_entry_count": 0}
            finally:
                # Never automatically re-enable source senders on uncertain outcome.
                ledger.outcome(ticket["approval_id"], status)

def main() -> int:
    parser = argparse.ArgumentParser()
    selection = parser.add_mutually_exclusive_group(required=True)
    selection.add_argument("--inspect", action="store_true")
    selection.add_argument("--retire", action="store_true")
    args = parser.parse_args()
    try:
        if args.inspect:
            print(json.dumps(inspect(), sort_keys=True))
            return 0
        raw = sys.stdin.buffer.read(2049)
        if len(raw) > 2048:
            raise MaintenanceDenied("approval_payload_too_large")
        signed = json.loads(raw)
        if not isinstance(signed, dict) or set(signed) != {"ticket", "signature_b64"}:
            raise MaintenanceDenied("approval_payload_invalid")
        result = retire(signed["ticket"], signed["signature_b64"])
        print(json.dumps(result, sort_keys=True))
        return 0
    except (MaintenanceDenied, json.JSONDecodeError) as exc:
        print(json.dumps({"retirement": "DENIED", "reason": str(exc)}), file=sys.stderr)
        return 75
    except Exception as exc:
        print(json.dumps({"retirement": "INDETERMINATE", "reason_class": type(exc).__name__}), file=sys.stderr)
        return 70

if __name__ == "__main__":
    raise SystemExit(main())
