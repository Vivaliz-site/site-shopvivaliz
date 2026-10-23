"""Operator-signed, one-use maintenance transaction for Marina migration.

This is not an approval issuer. A separately authorized platform owner must
provision an Ed25519 public key registry; a valid owner-signed ticket is needed.
"""
from __future__ import annotations
import base64
import binascii
import fcntl
import hashlib
import json
import os
import re
import stat
import time
import uuid
from pathlib import Path
from typing import Any, Callable
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.exceptions import InvalidSignature

SOURCE_HOST = "shopvivaliz-free-a1"
DEST_HOST = "marina-a1-4c24g"
TIMER = "shopvivaliz-abandoned-cart-recovery.timer"
APPROVAL_ACTION = "retire_origin_abandoned_cart_v1"
SOURCE_PROGRAM = "/opt/shopvivaliz-remote-control/retire-origin-abandoned-cart.py"
TRUSTED_OPERATORS = Path("/etc/shopvivaliz/maintenance/abandoned-cart-operators.json")
STATE_DIR = Path("/var/lib/shopvivaliz-remote-control/maintenance-cart")
ALLOWED_TICKET_KEYS = frozenset({"version", "approval_id", "operator_id", "action", "source_host", "destination_host", "timer", "source_cron_sha256", "issued_at", "expires_at"})
_ID_RE = re.compile(r"[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}\Z")
_HEX_RE = re.compile(r"[a-f0-9]{64}\Z")

class MaintenanceDenied(RuntimeError):
    """Sanitized, safe-to-display denial."""

def _canonical(document: dict[str, Any]) -> bytes:
    return json.dumps(document, sort_keys=True, separators=(",", ":"), ensure_ascii=True, allow_nan=False).encode("ascii")

def _read_protected_json(path: Path, owner_uid: int = 0) -> dict[str, Any]:
    try:
        fd = os.open(str(path), os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        with os.fdopen(fd, "rb") as f:
            meta = os.fstat(f.fileno())
            if not stat.S_ISREG(meta.st_mode) or meta.st_uid != owner_uid or meta.st_mode & 0o077:
                raise MaintenanceDenied("operator_registry_untrusted")
            data = f.read(32769)
        if len(data) > 32768:
            raise MaintenanceDenied("operator_registry_invalid")
        doc = json.loads(data)
        if not isinstance(doc, dict):
            raise MaintenanceDenied("operator_registry_invalid")
        return doc
    except (OSError, ValueError, UnicodeError) as exc:
        raise MaintenanceDenied("operator_registry_unavailable") from exc

def verify_ticket(ticket: dict[str, Any], signature_b64: str, *,
                  registry_path: Path = TRUSTED_OPERATORS, owner_uid: int = 0,
                  now: int | None = None) -> str:
    current = int(time.time()) if now is None else int(now)
    if not isinstance(ticket, dict) or set(ticket) != ALLOWED_TICKET_KEYS:
        raise MaintenanceDenied("ticket_shape_invalid")
    if type(ticket["version"]) is not int or ticket["version"] != 1:
        raise MaintenanceDenied("ticket_version_invalid")
    try:
        u = uuid.UUID(ticket["approval_id"], version=4)
    except (ValueError, TypeError, AttributeError):
        raise MaintenanceDenied("ticket_id_invalid") from None
    if not isinstance(ticket["approval_id"], str) or str(u) != ticket["approval_id"]:
        raise MaintenanceDenied("ticket_id_invalid")
    if not isinstance(ticket["operator_id"], str) or not _ID_RE.fullmatch(ticket["operator_id"]):
        raise MaintenanceDenied("operator_identity_invalid")
    if (ticket["action"] != APPROVAL_ACTION or ticket["source_host"] != SOURCE_HOST
            or ticket["destination_host"] != DEST_HOST or ticket["timer"] != TIMER):
        raise MaintenanceDenied("ticket_scope_invalid")
    if not isinstance(ticket["source_cron_sha256"], str) or not _HEX_RE.fullmatch(ticket["source_cron_sha256"]):
        raise MaintenanceDenied("ticket_cron_digest_invalid")
    issued, expires = ticket["issued_at"], ticket["expires_at"]
    if type(issued) is not int or type(expires) is not int or issued > current + 15 or issued < current - 300 or expires <= current + 20 or expires - issued > 180:
        raise MaintenanceDenied("ticket_expired_or_not_yet_valid")
    if not isinstance(signature_b64, str) or len(signature_b64) > 128:
        raise MaintenanceDenied("ticket_signature_invalid")
    registry = _read_protected_json(registry_path, owner_uid=owner_uid)
    if registry.get("schema_version") != 1 or not isinstance(registry.get("operators"), dict):
        raise MaintenanceDenied("operator_registry_invalid")
    signer = registry["operators"].get(ticket["operator_id"])
    if not isinstance(signer, dict) or signer.get("enabled") is not True:
        raise MaintenanceDenied("operator_not_authorized")
    try:
        pub = base64.b64decode(signer["ed25519_public_key_b64"], validate=True)
        sig = base64.b64decode(signature_b64, validate=True)
        if len(pub) != 32 or len(sig) != 64:
            raise ValueError("invalid signature/public key length")
        Ed25519PublicKey.from_public_bytes(pub).verify(sig, _canonical(ticket))
    except (KeyError, TypeError, ValueError, InvalidSignature, binascii.Error) as exc:
        raise MaintenanceDenied("ticket_signature_invalid") from exc
    return ticket["operator_id"]

def _decode_result(out: dict[str, Any]) -> dict[str, Any]:
    if not isinstance(out, dict) or out.get("exit_code") != 0:
        raise MaintenanceDenied("host_readback_failed")
    try:
        status = json.loads(out["stdout"])
    except (KeyError, ValueError, TypeError) as exc:
        raise MaintenanceDenied("host_response_invalid") from exc
    if not isinstance(status, dict):
        raise MaintenanceDenied("host_response_invalid")
    return status

def _inspect_source(run_host: Callable[[str, str], dict[str, Any]]) -> dict[str, Any]:
    source = _decode_result(run_host(SOURCE_HOST, SOURCE_PROGRAM + " --inspect"))
    if (source.get("host") != SOURCE_HOST or source.get("fence_present") is not True
            or source.get("cron_entry_count") not in (0, 1)
            or not isinstance(source.get("cron_sha256"), str)
            or not _HEX_RE.fullmatch(source["cron_sha256"])):
        raise MaintenanceDenied("source_preflight_unsafe")
    return source

def _inspect_destination(run_host: Callable[[str, str], dict[str, Any]]) -> dict[str, Any]:
    command = ('python3 -c \'import json,socket,subprocess;'
               'a=subprocess.run(["systemctl","is-active","shopvivaliz-abandoned-cart-recovery.timer"],capture_output=True,text=True);'
               'b=subprocess.run(["systemctl","is-enabled","shopvivaliz-abandoned-cart-recovery.timer"],capture_output=True,text=True);'
               'print(json.dumps({"host":socket.gethostname().split(".")[0],"active":a.stdout.strip(),"enabled":b.stdout.strip()}))\'')
    dest = _decode_result(run_host(DEST_HOST, command))
    if dest.get("host") != DEST_HOST or dest.get("active") != "active" or dest.get("enabled") != "enabled":
        raise MaintenanceDenied("destination_writer_not_confirmed")
    return dest

def preview(run_host: Callable[[str, str], dict[str, Any]]) -> dict[str, Any]:
    """Read-only scope preview; never emits a lease or approval."""
    source = _inspect_source(run_host)
    dest = _inspect_destination(run_host)
    return {"eligible_for_request": True, "source_host": SOURCE_HOST, "destination_host": DEST_HOST,
            "source_cron_sha256": source["cron_sha256"], "source_cron_entry_count": source["cron_entry_count"],
            "source_timer_active": source.get("timer_active"), "source_timer_enabled": source.get("timer_enabled"),
            "destination_timer_active": dest["active"], "destination_timer_enabled": dest["enabled"],
            "requires_separate_operator_signature": True, "lease_issued": False}

class _LockedLedger:
    def __init__(self, directory: Path = STATE_DIR, owner_uid: int = 0):
        self.directory, self.owner_uid = directory, owner_uid

    def __enter__(self):
        try:
            meta = self.directory.parent.lstat()
            if not stat.S_ISDIR(meta.st_mode) or meta.st_uid != self.owner_uid or meta.st_mode & 0o077:
                raise MaintenanceDenied("maintenance_state_parent_untrusted")
            self.directory.mkdir(mode=0o700, exist_ok=True)
            meta = self.directory.lstat()
            if not stat.S_ISDIR(meta.st_mode) or meta.st_uid != self.owner_uid or meta.st_mode & 0o077:
                raise MaintenanceDenied("maintenance_state_dir_untrusted")
            fd = os.open(str(self.directory / ".lock"), os.O_CREAT | os.O_RDWR | getattr(os, "O_NOFOLLOW", 0), 0o600)
            self.handle = os.fdopen(fd, "a+")
            meta = os.fstat(self.handle.fileno())
            if not stat.S_ISREG(meta.st_mode) or meta.st_uid != self.owner_uid or meta.st_mode & 0o077:
                self.handle.close()
                raise MaintenanceDenied("maintenance_state_lock_untrusted")
            fcntl.flock(self.handle.fileno(), fcntl.LOCK_EX)
            return self
        except OSError as exc:
            raise MaintenanceDenied("maintenance_state_unavailable") from exc

    def __exit__(self, *_):
        fcntl.flock(self.handle.fileno(), fcntl.LOCK_UN)
        self.handle.close()

    def claim(self, approval_id: str, signer: str) -> None:
        try:
            fd = os.open(str(self.directory / (approval_id + ".json")), os.O_CREAT | os.O_EXCL | os.O_WRONLY | getattr(os, "O_NOFOLLOW", 0), 0o600)
            with os.fdopen(fd, "wb") as stream:
                stream.write(_canonical({"status":"claimed","approval_id":approval_id,"operator_id":signer,"created_at":int(time.time())}) + b"\n")
                stream.flush()
                os.fsync(stream.fileno())
            fd = os.open(str(self.directory), os.O_RDONLY | os.O_DIRECTORY)
            try: os.fsync(fd)
            finally: os.close(fd)
        except FileExistsError as exc:
            raise MaintenanceDenied("approval_already_consumed") from exc
        except OSError as exc:
            raise MaintenanceDenied("maintenance_ledger_write_failed") from exc

    def outcome(self, approval_id: str, status: str) -> None:
        if status not in ("applied", "indeterminate"):
            raise ValueError("invalid_outcome")
        path = self.directory / (approval_id + ".json")
        temporary = self.directory / (approval_id + ".new")
        with temporary.open("xb") as stream:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(_canonical({"status":status,"approval_id":approval_id,"updated_at":int(time.time())}) + b"\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)

def apply(ticket: dict[str, Any], signature_b64: str, *,
          run_host: Callable[[str, str], dict[str, Any]],
          execute_source: Callable[[dict[str, Any], str], dict[str, Any]],
          runtime_lock: Any, registry_path: Path = TRUSTED_OPERATORS,
          state_dir: Path = STATE_DIR, owner_uid: int = 0,
          now: int | None = None) -> dict[str, Any]:
    """Single approved action; consumes ticket; issues/releases INTERNAL scoped lease."""
    signer = verify_ticket(ticket, signature_b64, registry_path=registry_path, owner_uid=owner_uid, now=now)
    with _LockedLedger(state_dir, owner_uid=owner_uid) as ledger:
        source = _inspect_source(run_host)
        _inspect_destination(run_host)
        if source["cron_entry_count"] != 1 or source["cron_sha256"] != ticket["source_cron_sha256"]:
            raise MaintenanceDenied("source_cron_cas_mismatch")
        if source.get("timer_active") != "active" or source.get("timer_enabled") != "enabled":
            raise MaintenanceDenied("source_timer_state_changed")
        try:
            lease = runtime_lock.acquire_runtime_lock("maintenance", "cart-source-retire:" + ticket["approval_id"], 90, [APPROVAL_ACTION])
        except Exception as exc:
            raise MaintenanceDenied("runtime_lease_unavailable") from exc
        try:
            ledger.claim(ticket["approval_id"], signer)
            status = "indeterminate"
            try:
                # Signed capability travels through SSH stdin, NEVER argv, audit or logs.
                response = _decode_result(execute_source(ticket, signature_b64))
                if (response.get("retirement") != "APPLIED" or response.get("source_host") != SOURCE_HOST
                        or response.get("timer_active") != "inactive" or response.get("timer_enabled") != "disabled"
                        or response.get("cron_entry_count") != 0):
                    raise MaintenanceDenied("source_retirement_readback_invalid")
                after = _inspect_source(run_host)
                if (after["cron_entry_count"] != 0 or after.get("timer_active") != "inactive"
                        or after.get("timer_enabled") != "disabled"):
                    raise MaintenanceDenied("source_postcondition_invalid")
                _inspect_destination(run_host)
                status = "applied"
                return {"applied":True, "approval_id":ticket["approval_id"], "source_timer":"inactive/disabled",
                        "source_cron_entries":0, "destination_timer":"active/enabled", "owner_kind":"maintenance",
                        "fencing_enforced":True, "approval_verified":True, "reboot_and_two_windows_verified":False}
            finally:
                ledger.outcome(ticket["approval_id"], status)
        finally:
            runtime_lock.release_runtime_lock(lease["lease_id"], lease["fencing_token"], "cart-source-retirement-finished")
