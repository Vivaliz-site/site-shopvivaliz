#!/usr/bin/env python3
"""Marina-only protected workspace write adapter (no generic filesystem or root).

Only the root-owned, reviewed MCP server may instantiate this operator. The
public GitHub API is used for a fresh, fail-closed owner-PR verification on
EACH write. No GitHub token, admin key, SSH key or caller URL is accepted.
"""
from __future__ import annotations

import base64
import json
import os
import pwd
import runpy
import socket
import sqlite3
import stat
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote
from urllib.request import Request, urlopen

HOST = "marina-a1-4c24g"
ACCOUNT = "shopvivaliz-marina-mcp"
REPOSITORY = "Vivaliz-site/site-shopvivaliz"
GRANT_PR = 3514
GRANT_HEAD = "e58172531a29c99c1f67aa2eb6bcd3fb20ce0f2b"
GRANT_MERGE = "a22f64f76729a21ccd7d1bf19f4a133fc0224a0a"
GRANT_PATH = "security/owner-approvals/3514.json"
WORKSPACE = Path("/var/lib/shopvivaliz-marina-mcp/workspace")
STATE = Path("/var/lib/shopvivaliz-marina-mcp/state")
API = "https://api.github.com"
CAPABILITY = "file_write"
OPERATION = "marina_workspace_file_write"
_VERIFIER = runpy.run_path(str(Path(__file__).with_name("validate_owner_pr_grant.py")))


def _safe_dir(directory: Path, uid: int) -> bool:
    try:
        s = directory.lstat()
        return stat.S_ISDIR(s.st_mode) and not stat.S_ISLNK(s.st_mode) and s.st_uid == uid and stat.S_IMODE(s.st_mode) == 0o700
    except OSError:
        return False


def _public_api(path: str) -> object:
    """The caller never supplies a URL; only fixed repository API endpoints."""
    if not path.startswith("/repos/Vivaliz-site/site-shopvivaliz/"):
        raise RuntimeError("github_resource_not_allowlisted")
    request = Request(API + path, headers={
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "shopvivaliz-marina-owner-grant-readonly",
    })
    with urlopen(request, timeout=5) as response:
        if response.status != 200:
            raise RuntimeError("github_read_unavailable")
        raw = response.read(65537)
        if len(raw) > 65536:
            raise RuntimeError("github_response_too_large")
        return json.loads(raw)


def _owner_grant_valid(pr: int, head: str, merge: str, capability: str, operation: str,
                       repository: str) -> bool:
    if (pr != GRANT_PR or head != GRANT_HEAD or merge != GRANT_MERGE
            or capability != CAPABILITY or operation != OPERATION
            or repository != REPOSITORY):
        return False
    base = "/repos/Vivaliz-site/site-shopvivaliz"
    try:
        pr_json = _public_api(base + "/pulls/3514")
        reviews = _public_api(base + "/pulls/3514/reviews?per_page=100")
        changed = _public_api(base + "/pulls/3514/files?per_page=100")
        blob = _public_api(base + "/contents/" + GRANT_PATH + "?ref=" + GRANT_MERGE)
        current = _public_api(base + "/contents/" + GRANT_PATH + "?ref=main")
        if (not isinstance(blob, dict) or blob.get("encoding") != "base64"
                or not _VERIFIER["immutable_grant_still_active"](blob, current)
                or not _VERIFIER["grant_added_in_reviewed_pr"](changed, GRANT_PATH, blob)):
            return False
        data = base64.b64decode(blob["content"].replace("\n", ""), validate=True)
        grant = json.loads(data.decode("utf-8"))
        valid, _ = _VERIFIER["decide"](
            pr_json, grant, repository=REPOSITORY, pr_number=GRANT_PR,
            owner_login="shopvivaliz-human", merge_sha=merge, head_sha=head,
            resource="mcp:marina", principal="marina-mcp-service",
            capability=capability, operation=operation, target_repository=REPOSITORY,
            reviews=reviews)
        if not valid:
            return False
        # Refuse a modified/broadened grant, even when the generic validator
        # still accepts the requested capability as one of several.
        return (grant.get("host") == HOST
                and grant.get("capabilities") == ["file_write"]
                and grant.get("workspace_root") == str(WORKSPACE)
                and grant.get("allowed_extensions") == [".json", ".txt"]
                and grant.get("max_content_bytes") == 4096
                and grant.get("cas_required") is True
                and grant.get("lease_fencing_required") is True
                and grant.get("audit_required") is True
                and grant.get("root_or_shell_allowed") is False)
    except Exception:
        return False


def _locked_state_file(path: Path, uid: int) -> int:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_APPEND | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != uid or stat.S_IMODE(info.st_mode) != 0o600:
        os.close(fd)
        raise RuntimeError("protected_state_file_unsafe")
    return fd


class ProtectedWorkspaceOperator:
    """Trusted host-owned callback implementation, not constructed by RPC."""

    def __init__(self, *, workspace: Path = WORKSPACE, state: Path = STATE):
        self.workspace = workspace
        self.state = state

    def _uid(self) -> int:
        uid = pwd.getpwnam(ACCOUNT).pw_uid
        if (uid == 0 or uid != os.geteuid() or
                socket.gethostname().split(".", 1)[0] != HOST):
            raise RuntimeError("wrong_service_identity")
        return uid

    def grant_valid(self, pr: int, head: str, merge: str, capability: str,
                    operation: str, repository: str) -> bool:
        self._uid()
        return _owner_grant_valid(pr, head, merge, capability, operation, repository)

    def runtime_permission_valid(self) -> bool:
        try:
            uid = self._uid()
            if not _safe_dir(self.workspace, uid) or not _safe_dir(self.state, uid):
                return False
            # An actual write is still required to prove systemd ReadWritePaths.
            # The state audit/lease insertion below fails closed if unavailable.
            return os.access(self.workspace, os.W_OK) and os.access(self.state, os.W_OK)
        except Exception:
            return False

    def reserve_lease_once(self, operation: str, request_id: str, fencing: int) -> bool:
        if operation != OPERATION or type(fencing) is not int or not 0 < fencing < 2**63:
            return False
        uid = self._uid()
        if not _safe_dir(self.state, uid):
            return False
        dbfile = self.state / "write-leases.sqlite3"
        # Reject an existing symlink or non-private file before SQLite opens it.
        if dbfile.exists() or dbfile.is_symlink():
            s = dbfile.lstat()
            if not stat.S_ISREG(s.st_mode) or s.st_uid != uid or stat.S_IMODE(s.st_mode) != 0o600:
                return False
        else:
            fd = os.open(dbfile, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
            os.close(fd)
        connection = sqlite3.connect(str(dbfile), timeout=2, isolation_level=None)
        try:
            connection.execute("PRAGMA busy_timeout=2000")
            connection.execute("PRAGMA journal_mode=WAL")
            connection.execute("CREATE TABLE IF NOT EXISTS reservations (request_id TEXT PRIMARY KEY, operation TEXT NOT NULL, fence INTEGER NOT NULL, created_at TEXT NOT NULL)")
            connection.execute("BEGIN IMMEDIATE")
            last = connection.execute("SELECT MAX(fence) FROM reservations WHERE operation=?", (operation,)).fetchone()[0]
            if last is not None and fencing <= last:
                connection.execute("ROLLBACK")
                return False
            connection.execute("INSERT INTO reservations VALUES (?,?,?,?)", (request_id, operation, fencing, datetime.now(timezone.utc).isoformat()))
            connection.execute("COMMIT")
            return True
        except (sqlite3.Error, OSError):
            try:
                connection.execute("ROLLBACK")
            except sqlite3.Error:
                pass
            return False
        finally:
            connection.close()

    def _audit(self, event: str, operation: str, request_id: str,
               filename: str, sha256: str) -> bool:
        if operation != OPERATION:
            return False
        try:
            uid = self._uid()
            if not _safe_dir(self.state, uid):
                return False
            # Bounded, redacted record: NO contents, auth headers or URL.
            body = json.dumps({"event": event, "operation": operation,
                               "request_id": request_id, "filename": filename,
                               "sha256": sha256, "at": datetime.now(timezone.utc).isoformat()},
                              separators=(",", ":"), sort_keys=True).encode() + b"\n"
            if len(body) > 1024:
                return False
            fd = _locked_state_file(self.state / "write-audit.jsonl", uid)
            try:
                if os.write(fd, body) != len(body):
                    return False
                os.fsync(fd)
            finally:
                os.close(fd)
            return True
        except Exception:
            return False

    def audit_prepared(self, operation: str, request_id: str, filename: str, sha256: str) -> bool:
        return self._audit("prepared", operation, request_id, filename, sha256)

    def audit_committed(self, operation: str, request_id: str, filename: str, sha256: str) -> bool:
        return self._audit("committed", operation, request_id, filename, sha256)


def bootstrap_operator() -> ProtectedWorkspaceOperator:
    """Host startup only. No privileges or scope are taken from MCP calls."""
    operator = ProtectedWorkspaceOperator()
    if not operator.runtime_permission_valid():
        raise RuntimeError("protected_workspace_unavailable")
    return operator
