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
import threading
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
# Optional independently provisioned repository read-only credential. Never borrow
# an existing CLI/browser/controller token or trust one supplied by an RPC.
GITHUB_READ_TOKEN_FILE = Path("/var/lib/shopvivaliz-marina-mcp/.config/shopvivaliz-marina-mcp/github-read-token")
API = "https://api.github.com"
CAPABILITY = "file_write"
OPERATION = "marina_workspace_file_write"
# The installed release has a pinned, root-owned copy. Source-tree CI uses
# the matching canonical script; installed hosts must never import from elsewhere.
_VERIFIER_FILE = Path(__file__).with_name("validate_owner_pr_grant.py")
if not _VERIFIER_FILE.is_file() and Path(__file__).parent.name == "marina-direct-mcp":
    _VERIFIER_FILE = Path(__file__).parent.parent / "scripts" / "validate-owner-pr-grant.py"
_VERIFIER = runpy.run_path(str(_VERIFIER_FILE))
# PR/merge/file-at-merge are immutable historical facts. Only cache those.
# Every write still MUST recheck fresh owner reviews and the live main blob.
_STATIC_PROOF = None
_STATIC_PROOF_LOCK = threading.Lock()


def _safe_dir(directory: Path, uid: int) -> bool:
    try:
        s = directory.lstat()
        return stat.S_ISDIR(s.st_mode) and not stat.S_ISLNK(s.st_mode) and s.st_uid == uid and stat.S_IMODE(s.st_mode) == 0o700
    except OSError:
        return False

def _read_protected_github_token() -> str | None:
    """Credential is optional; if present but unsafe, refuse the API request."""
    try:
        fd = os.open(GITHUB_READ_TOKEN_FILE, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except FileNotFoundError:
        return None
    try:
        metadata = os.fstat(fd)
        if not _safe_dir(GITHUB_READ_TOKEN_FILE.parent, os.geteuid()):
            raise RuntimeError("github_read_token_parent_unsafe")
        if (not stat.S_ISREG(metadata.st_mode)
                or metadata.st_uid != os.geteuid()
                or stat.S_IMODE(metadata.st_mode) != 0o600):
            raise RuntimeError("github_read_token_metadata_unsafe")
        raw = os.read(fd, 4097)
        if (not 20 <= len(raw) <= 4096
                or any(c < 33 or c > 126 for c in raw.rstrip(b"\r\n"))
                or raw.rstrip(b"\r\n").find(b" ") != -1):
            raise RuntimeError("github_read_token_invalid")
        token = raw.rstrip(b"\r\n").decode("ascii")
        if not 20 <= len(token) <= 4096 or "\n" in token or "\r" in token:
            raise RuntimeError("github_read_token_invalid")
        return token
    finally:
        os.close(fd)


def _public_api(path: str) -> object:
    """The caller never supplies a URL; only fixed repository API endpoints."""
    if not path.startswith("/repos/Vivaliz-site/site-shopvivaliz/"):
        raise RuntimeError("github_resource_not_allowlisted")
    headers = {
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "shopvivaliz-marina-owner-grant-readonly",
    }
    token = _read_protected_github_token()
    if token is not None:
        headers["Authorization"] = "Bearer " + token
    request = Request(API + path, headers=headers)
    with urlopen(request, timeout=5) as response:
        if response.status != 200:
            raise RuntimeError("github_read_unavailable")
        raw = response.read(65537)
        if len(raw) > 65536:
            raise RuntimeError("github_response_too_large")
        return json.loads(raw)


def _immutable_owner_proof() -> tuple | None:
    """Fetch/certify historical PR artifacts once per process, never the live grant."""
    global _STATIC_PROOF
    with _STATIC_PROOF_LOCK:
        if _STATIC_PROOF is not None:
            return _STATIC_PROOF
        base = "/repos/Vivaliz-site/site-shopvivaliz"
        pr_json = _public_api(base + "/pulls/3514")
        changed = _public_api(base + "/pulls/3514/files?per_page=100")
        blob = _public_api(base + "/contents/" + GRANT_PATH + "?ref=" + GRANT_MERGE)
        if (not isinstance(pr_json, dict) or not isinstance(blob, dict)
                or blob.get("encoding") != "base64"
                or not _VERIFIER["grant_added_in_reviewed_pr"](changed, GRANT_PATH, blob)):
            return None
        grant = json.loads(base64.b64decode(
            blob["content"].replace("\n", ""), validate=True).decode("utf-8"))
        # This fixed, narrowly scoped data never changes without a different
        # main-branch blob, which is checked live on EVERY operation.
        if (not isinstance(grant, dict)
                or grant.get("host") != HOST
                or grant.get("capabilities") != ["file_write"]
                or grant.get("workspace_root") != str(WORKSPACE)
                or grant.get("allowed_extensions") != [".json", ".txt"]
                or grant.get("max_content_bytes") != 4096
                or grant.get("cas_required") is not True
                or grant.get("lease_fencing_required") is not True
                or grant.get("audit_required") is not True
                or grant.get("root_or_shell_allowed") is not False):
            return None
        _STATIC_PROOF = (pr_json, blob, grant)
        return _STATIC_PROOF


def _owner_grant_valid(pr: int, head: str, merge: str, capability: str, operation: str,
                       repository: str) -> bool:
    if (pr != GRANT_PR or head != GRANT_HEAD or merge != GRANT_MERGE
            or capability != CAPABILITY or operation != OPERATION
            or repository != REPOSITORY):
        return False
    base = "/repos/Vivaliz-site/site-shopvivaliz"
    try:
        proof = _immutable_owner_proof()
        if proof is None:
            return False
        pr_json, approved_blob, grant = proof
        # Dynamic authorization evidence is NEVER cached: reviewer revocation
        # and subsequent edits/deletion on main must deny on the very next call.
        reviews = _public_api(base + "/pulls/3514/reviews?per_page=100")
        current = _public_api(base + "/contents/" + GRANT_PATH + "?ref=main")
        if not _VERIFIER["immutable_grant_still_active"](approved_blob, current):
            return False
        valid, _ = _VERIFIER["decide"](
            pr_json, grant, repository=REPOSITORY, pr_number=GRANT_PR,
            owner_login="shopvivaliz-human", merge_sha=merge, head_sha=head,
            resource="mcp:marina", principal="marina-mcp-service",
            capability=capability, operation=operation, target_repository=REPOSITORY,
            reviews=reviews)
        return bool(valid)
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
