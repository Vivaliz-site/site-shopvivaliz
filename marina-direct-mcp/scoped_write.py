#!/usr/bin/env python3
"""Fail-closed Marina workspace file writer, not a generic host filesystem tool.

This module does not acquire privileges or discover grants. The verified local
operator is injected by trusted host startup code; never from an MCP request.
The server MUST NOT publish this tool without such an operator.
"""
from __future__ import annotations

import fcntl
import hashlib
import os
import pwd
import re
import secrets
import socket
import stat
from collections.abc import Mapping
from pathlib import Path
from typing import Protocol

HOST = "marina-a1-4c24g"
ACCOUNT = "shopvivaliz-marina-mcp"
REPOSITORY = "Vivaliz-site/site-shopvivaliz"
OPERATION = "marina_workspace_file_write"
CAPABILITY = "file_write"
WORKSPACE = Path("/var/lib/shopvivaliz-marina-mcp/workspace")
MAX_CONTENT_BYTES = 4096
_FILENAME = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_-]{0,47}\.(?:txt|json)$")
_SHA = re.compile(r"^[a-f0-9]{40}$")
_SHA256 = re.compile(r"^[a-f0-9]{64}$")
_KEY = re.compile(r"^[a-z0-9][a-z0-9-]{7,127}$")
_KEYS = frozenset({"filename", "content_utf8", "expected_sha256", "pr_number",
                   "head_sha", "merge_sha", "idempotency_key", "fencing_token"})


class VerifiedWriteOperator(Protocol):
    """Protected host adapter, never assembled from caller-provided values."""
    def grant_valid(self, pr: int, head: str, merge: str, capability: str,
                    operation: str, repository: str) -> bool: ...
    def runtime_permission_valid(self) -> bool: ...
    def reserve_lease_once(self, operation: str, request_id: str, fencing: int) -> bool: ...
    def audit_prepared(self, operation: str, request_id: str, filename: str,
                       new_sha256: str) -> bool: ...
    def audit_committed(self, operation: str, request_id: str, filename: str,
                        new_sha256: str) -> bool: ...


def _open_workspace(base: Path, uid: int) -> int:
    """Return pinned directory descriptor with verified identity and mode."""
    fd = os.open(base, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    metadata = os.fstat(fd)
    if (not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != uid
            or stat.S_IMODE(metadata.st_mode) != 0o700):
        os.close(fd)
        raise OSError("workspace_permissions_invalid")
    return fd


def _read_current(dirfd: int, name: str, uid: int) -> bytes | None:
    try:
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=dirfd)
    except FileNotFoundError:
        return None
    try:
        meta = os.fstat(fd)
        if (not stat.S_ISREG(meta.st_mode) or meta.st_uid != uid
                or stat.S_IMODE(meta.st_mode) != 0o600 or meta.st_size > MAX_CONTENT_BYTES):
            raise OSError("existing_target_not_private_regular_file")
        with os.fdopen(fd, "rb", closefd=False) as stream:
            value = stream.read(MAX_CONTENT_BYTES + 1)
        if len(value) > MAX_CONTENT_BYTES:
            raise OSError("existing_target_oversized")
        return value
    finally:
        os.close(fd)


def _write_atomic(dirfd: int, name: str, contents: bytes) -> None:
    tmp = ".write-" + secrets.token_hex(16)
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                 0o600, dir_fd=dirfd)
    try:
        with os.fdopen(fd, "wb", closefd=False) as stream:
            stream.write(contents)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(tmp, name, src_dir_fd=dirfd, dst_dir_fd=dirfd)
        os.fsync(dirfd)
    finally:
        os.close(fd)
        try:
            os.unlink(tmp, dir_fd=dirfd)
        except FileNotFoundError:
            pass


def _lock_workspace(dirfd: int, uid: int) -> int:
    fd = os.open(".write-lock", os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC,
                 0o600, dir_fd=dirfd)
    meta = os.fstat(fd)
    if (not stat.S_ISREG(meta.st_mode) or meta.st_uid != uid or
            stat.S_IMODE(meta.st_mode) != 0o600):
        os.close(fd)
        raise OSError("workspace_lock_invalid")
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        os.close(fd)
        raise
    return fd


def execute(request: object, authority: VerifiedWriteOperator | None, *,
            workspace: Path = WORKSPACE) -> tuple[bool, dict[str, str]]:
    """Write one bounded data file only after protected grant and lease gates.

    All deny responses are secret-free. No client-controlled path separators,
    service names, shell commands, or root-owned production paths are accepted.
    """
    if not isinstance(request, Mapping) or set(request) != _KEYS:
        return False, {"reason": "invalid_request_shape"}
    name = request.get("filename")
    content = request.get("content_utf8")
    expected = request.get("expected_sha256")
    pr = request.get("pr_number")
    head, merge = request.get("head_sha"), request.get("merge_sha")
    key, fence = request.get("idempotency_key"), request.get("fencing_token")
    if (not isinstance(name, str) or not _FILENAME.fullmatch(name)
            or not isinstance(content, str)):
        return False, {"reason": "invalid_filename_or_content"}
    try:
        data = content.encode("utf-8", errors="strict")
    except UnicodeEncodeError:
        return False, {"reason": "invalid_utf8"}
    if len(data) > MAX_CONTENT_BYTES:
        return False, {"reason": "content_too_large"}
    if (expected is not None and
            (not isinstance(expected, str) or _SHA256.fullmatch(expected) is None)):
        return False, {"reason": "invalid_compare_and_swap"}
    if (type(pr) is not int or pr <= 0 or not isinstance(head, str) or not _SHA.fullmatch(head)
            or not isinstance(merge, str) or not _SHA.fullmatch(merge)
            or not isinstance(key, str) or not _KEY.fullmatch(key)
            or type(fence) is not int or not 0 < fence < 2**63):
        return False, {"reason": "invalid_grant_or_lease_binding"}
    if authority is None:
        return False, {"reason": "trusted_write_operator_unavailable"}
    try:
        if socket.gethostname().split(".", 1)[0] != HOST:
            return False, {"reason": "wrong_host"}
        uid = pwd.getpwnam(ACCOUNT).pw_uid
        if uid == 0 or os.geteuid() != uid:
            return False, {"reason": "service_identity_mismatch"}
        if authority.grant_valid(pr, head, merge, CAPABILITY, OPERATION, REPOSITORY) is not True:
            return False, {"reason": "owner_pr_grant_not_verified"}
        if authority.runtime_permission_valid() is not True:
            return False, {"reason": "runtime_permission_denied"}
        dirfd = _open_workspace(workspace, uid)
        try:
            lockfd = _lock_workspace(dirfd, uid)
            try:
                prior = _read_current(dirfd, name, uid)
                prior_hash = hashlib.sha256(prior).hexdigest() if prior is not None else None
                if expected != prior_hash:
                    return False, {"reason": "compare_and_swap_mismatch"}
                new_hash = hashlib.sha256(data).hexdigest()
                # A protected, durable lease and audit reservation must precede mutation.
                if authority.reserve_lease_once(OPERATION, key, fence) is not True:
                    return False, {"reason": "lease_or_replay_denied"}
                if authority.audit_prepared(OPERATION, key, name, new_hash) is not True:
                    return False, {"reason": "audit_preparation_denied"}
                _write_atomic(dirfd, name, data)
                if authority.audit_committed(OPERATION, key, name, new_hash) is not True:
                    return False, {"reason": "audit_commit_unavailable_requires_reconciliation"}
                return True, {"status": "written", "filename": name, "sha256": new_hash}
            finally:
                os.close(lockfd)
        finally:
            os.close(dirfd)
    except Exception:
        return False, {"reason": "protected_workspace_operation_failed"}


if __name__ == "__main__":
    print("MARINA_WORKSPACE_WRITE=DENIED reason=trusted_write_operator_unavailable")
    raise SystemExit(1)
