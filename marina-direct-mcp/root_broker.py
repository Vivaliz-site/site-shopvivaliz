#!/usr/bin/env python3
"""Marina root broker: Unix-socket-only, one fixed owner-approved maintenance operation.

No remote shell, arbitrary command, arbitrary unit, HTTP listener, or direct
credential access from MCP requests. Root daemon is dormant unless an official
operator installs the separate systemd socket+service AND exact human PR grant.
"""
from __future__ import annotations

import json
import os
import pwd
import re
import runpy
import secrets
import socket
import sqlite3
import stat
import struct
import subprocess
import sys
import time
from contextlib import closing
from pathlib import Path

HOST = "marina-a1-4c24g"
PEER_USER = "shopvivaliz-marina-mcp"
REPOSITORY = "Vivaliz-site/site-shopvivaliz"
TARGET_REPOSITORY = "Vivaliz-site/amazon-returns-safet"
OWNER_LOGIN = "shopvivaliz-human"
OPERATION = "olist_browser_restart"
CAPABILITY = "deployment_manage"
RESOURCE = "mcp:marina"
PRINCIPAL = "marina-mcp-service"
UNIT = "amazon-returns-olist-erp-browser.service"
_SERVICE_UNIT = re.compile(r"^shopvivaliz-[a-z0-9][a-z0-9-]*\.service$")
STATE_DIR = Path("/var/lib/shopvivaliz-marina-broker")
ROOT_TOKEN = Path("/etc/shopvivaliz/marina-root-broker/github-read-token")
VERIFIER = Path(__file__).with_name("validate_owner_pr_grant.py")
MAX_REQUEST_BYTES = 8192
LEASE_TTL_SECONDS = 60
_admin = runpy.run_path(str(Path(__file__).with_name("operator_admin_route.py")))


def safe_state_root(path: Path) -> bool:
    try:
        s = path.lstat()
        return stat.S_ISDIR(s.st_mode) and not stat.S_ISLNK(s.st_mode) and s.st_uid == 0 and stat.S_IMODE(s.st_mode) == 0o700
    except OSError:
        return False


def protected_token(path: Path) -> str | None:
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
        with os.fdopen(fd, "rb") as stream:
            m = os.fstat(stream.fileno())
            if (not stat.S_ISREG(m.st_mode) or m.st_uid != 0
                    or stat.S_IMODE(m.st_mode) != 0o600):
                return None
            token = stream.read(4097).rstrip(b"\r\n")
            if not 20 <= len(token) <= 4096 or not token.isascii() or any(x <= 32 or x >= 127 for x in token):
                return None
            return token.decode("ascii")
    except OSError:
        return None


def real_grant_valid(pr: int, head: str, merge: str, capability: str,
                     operation: str, target: str) -> bool:
    if (operation != OPERATION or capability != CAPABILITY
            or target != TARGET_REPOSITORY or not VERIFIER.is_file()):
        return False
    token = protected_token(ROOT_TOKEN)
    if token is None:
        return False
    args = [
        "/usr/bin/python3", "-B", str(VERIFIER),
        "--repository", REPOSITORY, "--pr-number", str(pr),
        "--head-sha", head, "--merge-sha", merge,
        "--resource", RESOURCE, "--principal", PRINCIPAL,
        "--capability", CAPABILITY, "--operation", OPERATION,
        "--target-repository", TARGET_REPOSITORY,
    ]
    env = {"PATH": "/usr/bin:/bin", "GITHUB_TOKEN": token,
           "SHOPVIVALIZ_SECURITY_OWNER_LOGIN": OWNER_LOGIN}
    try:
        result = subprocess.run(args, env=env, capture_output=True,
                                text=True, timeout=35, check=False)
        return (result.returncode == 0 and
                result.stdout.strip() == "OWNER_PR_GRANT=AUTHORIZED reason=owner_authorization_verified")
    except (OSError, subprocess.TimeoutExpired):
        return False


def _db(path: Path) -> sqlite3.Connection:
    if not safe_state_root(path):
        raise RuntimeError("broker_state_root_invalid")
    target = path / "leases.sqlite3"
    if target.exists() or target.is_symlink():
        info = target.lstat()
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0
                or stat.S_IMODE(info.st_mode) != 0o600):
            raise RuntimeError("broker_ledger_metadata_invalid")
    else:
        fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
        os.close(fd)
    con = sqlite3.connect(str(target), timeout=3, isolation_level=None)
    try:
        con.execute("PRAGMA busy_timeout=3000")
        con.execute("PRAGMA trusted_schema=OFF")
        con.execute("""CREATE TABLE IF NOT EXISTS leases(
            fence INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id TEXT NOT NULL UNIQUE,
            pr INTEGER NOT NULL,
            head TEXT NOT NULL,
            merge TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            used INTEGER NOT NULL DEFAULT 0)""")
    except BaseException:
        con.close()
        raise
    return con


def _audit(path: Path, event: str, request_id: str, result: str) -> bool:
    if not safe_state_root(path):
        return False
    target = path / "broker-audit.jsonl"
    try:
        fd = os.open(target, os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
        with os.fdopen(fd, "ab", closefd=True) as out:
            meta = os.fstat(out.fileno())
            if (not stat.S_ISREG(meta.st_mode) or meta.st_uid != 0
                    or stat.S_IMODE(meta.st_mode) != 0o600):
                return False
            data = json.dumps({"event": event, "operation": OPERATION,
                               "request_id": request_id, "result": result,
                               "at_epoch": int(time.time())},
                              separators=(",", ":")).encode() + b"\n"
            if len(data) > 600:
                return False
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        return True
    except OSError:
        return False


def _grant_fields(data: object) -> tuple[int, str, str] | None:
    if not isinstance(data, dict) or set(data) != {"operation", "pr_number", "head_sha", "merge_sha"}:
        return None
    import re
    if (data["operation"] != OPERATION or type(data["pr_number"]) is not int
            or data["pr_number"] <= 0
            or type(data["head_sha"]) is not str or not re.fullmatch(r"[0-9a-f]{40}", data["head_sha"])
            or type(data["merge_sha"]) is not str or not re.fullmatch(r"[0-9a-f]{40}", data["merge_sha"])):
        return None
    return data["pr_number"], data["head_sha"], data["merge_sha"]


class BrokerOperator:
    def __init__(self, state: Path = STATE_DIR, grant_checker=real_grant_valid,
                 runner=subprocess.run):
        self.state = state
        self.grant_checker = grant_checker
        self.runner = runner
        self._bound_pr: tuple[int, str, str] | None = None

    def hostname(self) -> str:
        return socket.gethostname().split(".")[0]

    def effective_uid(self) -> int:
        return os.geteuid()

    def olist_config_is_ready(self) -> bool:
        try:
            p = self.runner(["/usr/bin/systemctl", "show", UNIT, "-p", "LoadState", "--value"],
                            capture_output=True, text=True, timeout=4, check=False)
            return p.returncode == 0 and p.stdout.strip() == "loaded"
        except (OSError, subprocess.TimeoutExpired):
            return False

    def owner_pr_grant_is_valid(self, pr, head, merge, cap, op, target) -> bool:
        self._bound_pr = (pr, head, merge)
        return self.grant_checker(pr, head, merge, cap, op, target) is True

    def runtime_permission_is_valid(self) -> bool:
        return safe_state_root(self.state) and self.effective_uid() == 0

    def audit_ready(self, operation, request_id) -> bool:
        return operation == OPERATION and _audit(self.state, "attempt", request_id, "prepared")

    def lease_reserve_once(self, operation, request_id, fencing) -> bool:
        if operation != OPERATION or self._bound_pr is None:
            return False
        try:
            with closing(_db(self.state)) as db, db:
                db.execute("BEGIN IMMEDIATE")
                result = db.execute("""UPDATE leases SET used=1
                    WHERE request_id=? AND fence=? AND pr=? AND head=? AND merge=?
                      AND expires_at>? AND used=0
                      AND fence=(SELECT MAX(fence) FROM leases)""",
                    (request_id, fencing, *self._bound_pr, int(time.time())) )
                valid = result.rowcount == 1
                db.execute("COMMIT" if valid else "ROLLBACK")
                return valid
        except (sqlite3.Error, OSError, RuntimeError):
            return False

    def unit_pattern_is_valid(self, unit: str) -> bool:
        """Fresh host-side check of the human-approved shopvivaliz-*.service rule."""
        token = protected_token(ROOT_TOKEN)
        if token is None or not VERIFIER.is_file():
            return False
        args = [
            "/usr/bin/python3", "-B", str(VERIFIER),
            "--repository", REPOSITORY, "--unit", unit,
        ]
        env = {"PATH": "/usr/bin:/bin", "GITHUB_TOKEN": token,
               "SHOPVIVALIZ_SECURITY_OWNER_LOGIN": OWNER_LOGIN}
        try:
            result = self.runner(args, env=env, capture_output=True,
                                 text=True, timeout=35, check=False)
            return (result.returncode == 0 and
                    result.stdout.strip() == "UNIT_PATTERN=AUTHORIZED reason=unit_change_approved_by_human")
        except (OSError, subprocess.TimeoutExpired):
            return False

    def restart_service(self, unit: str) -> bool:
        if unit != UNIT and _SERVICE_UNIT.fullmatch(unit) is None:
            return False
        try:
            x = self.runner(["/usr/bin/systemctl", "restart", UNIT],
                            capture_output=True, text=True, timeout=30, check=False)
            if x.returncode != 0:
                return False
            y = self.runner(["/usr/bin/systemctl", "is-active", UNIT],
                            capture_output=True, text=True, timeout=4, check=False)
            return y.returncode == 0 and y.stdout.strip() == "active"
        except (OSError, subprocess.TimeoutExpired):
            return False

    def preflight(self, data: object) -> dict:
        """Fresh authorization without issuing a lease or changing the host."""
        fields = _grant_fields(data)
        if fields is None:
            return {"ok": False, "reason": "invalid_grant_request"}
        if self.effective_uid() != 0 or self.hostname() != HOST or not self.runtime_permission_is_valid():
            return {"ok": False, "reason": "protected_root_operator_unavailable"}
        if not self.olist_config_is_ready():
            return {"ok": False, "reason": "protected_olist_runtime_not_ready"}
        if not self.grant_checker(*fields, CAPABILITY, OPERATION, TARGET_REPOSITORY):
            return {"ok": False, "reason": "owner_pr_grant_denied"}
        return {"ok": True, "operation": OPERATION}

    def prepare(self, data: object) -> dict:
        allowed = self.preflight(data)
        if allowed.get("ok") is not True:
            return allowed
        fields = _grant_fields(data)
        if fields is None:
            return {"ok": False, "reason": "invalid_grant_request"}
        rid = "broker-" + secrets.token_hex(16)
        expires = int(time.time()) + LEASE_TTL_SECONDS
        try:
            with closing(_db(self.state)) as db, db:
                db.execute("BEGIN IMMEDIATE")
                record = db.execute(
                    "INSERT INTO leases (request_id,pr,head,merge,expires_at) VALUES (?,?,?,?,?)",
                    (rid, *fields, expires))
                fence = record.lastrowid
                db.execute("COMMIT")
            if not _audit(self.state, "lease_issued", rid, "pending"):
                return {"ok": False, "reason": "audit_unavailable"}
            return {"ok": True, "operation": OPERATION, "pr_number": fields[0],
                    "head_sha": fields[1], "merge_sha": fields[2],
                    "idempotency_key": rid, "fencing_token": fence, "expires_at": expires}
        except (OSError, sqlite3.Error, RuntimeError):
            return {"ok": False, "reason": "protected_lease_unavailable"}

    def execute(self, data: object) -> dict:
        if not isinstance(data, dict):
            return {"ok": False, "reason": "invalid_request"}
        ok, reason = _admin["execute_admin_operation"](data, self)
        rid = data.get("idempotency_key", "") if isinstance(data.get("idempotency_key"), str) else ""
        if rid.startswith("broker-"):
            _audit(self.state, "operation_result", rid, reason)
        return {"ok": ok, "reason": reason}


def _serve_connection(conn: socket.socket, operator: BrokerOperator,
                      expected_uid: int) -> None:
    try:
        conn.settimeout(4)
        ucred = conn.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12)
        _, uid, _ = struct.unpack("3i", ucred)
        if uid != expected_uid:
            result = {"ok": False, "reason": "peer_identity_denied"}
        else:
            buffer = b""
            while b"\n" not in buffer and len(buffer) <= MAX_REQUEST_BYTES:
                chunk = conn.recv(min(2048, MAX_REQUEST_BYTES + 1 - len(buffer)))
                if not chunk:
                    break
                buffer += chunk
            if len(buffer) > MAX_REQUEST_BYTES or not buffer.endswith(b"\n"):
                result = {"ok": False, "reason": "request_size_or_frame_invalid"}
            else:
                try:
                    data = json.loads(buffer)
                    if (not isinstance(data, dict) or set(data) != {"action", "request"}
                            or not isinstance(data["request"], dict)):
                        result = {"ok": False, "reason": "request_shape_invalid"}
                    elif data["action"] == "preflight":
                        result = operator.preflight(data["request"])
                    elif data["action"] == "prepare":
                        result = operator.prepare(data["request"])
                    elif data["action"] == "execute":
                        result = operator.execute(data["request"])
                    else:
                        result = {"ok": False, "reason": "unsupported_action"}
                except (ValueError, UnicodeError):
                    result = {"ok": False, "reason": "invalid_json"}
        conn.sendall(json.dumps(result, separators=(",", ":")).encode() + b"\n")
    except (TimeoutError, BrokenPipeError, ConnectionError, OSError):
        return


def main() -> int:
    if (os.geteuid() != 0 or socket.gethostname().split(".")[0] != HOST
            or os.environ.get("LISTEN_FDS") != "1"
            or os.environ.get("LISTEN_PID") != str(os.getpid())):
        print("MARINA_ROOT_BROKER=DENIED")
        return 3
    try:
        peer = pwd.getpwnam(PEER_USER).pw_uid
        if peer == 0 or not safe_state_root(STATE_DIR):
            raise RuntimeError("invalid_service_identity_or_state")
        sock = socket.socket(fileno=3)
        if sock.family != socket.AF_UNIX or sock.type != socket.SOCK_STREAM:
            raise RuntimeError("invalid_socket")
    except (OSError, KeyError, ValueError, RuntimeError):
        print("MARINA_ROOT_BROKER=DENIED")
        return 3
    while True:
        conn, _ = sock.accept()
        with conn:
            _serve_connection(conn, BrokerOperator(), peer)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
