#!/usr/bin/env python3
"""Proof of an approved Marina MCP local write with no production data touched.

Runs ONLY as the dedicated service account against loopback :5580. A completed
smoke leaves a harmless private probe file plus durable audit and lease rows.
No credentials, command, production path or protected data are printed.
"""
from __future__ import annotations

import hashlib
import json
import os
import pwd
import secrets
import socket
import sqlite3
import stat
import sys
from pathlib import Path

from local_auth_smoke import request, safe_read_token

HOST = "marina-a1-4c24g"
ACCOUNT = "shopvivaliz-marina-mcp"
WORKSPACE = Path("/var/lib/shopvivaliz-marina-mcp/workspace")
STATE = Path("/var/lib/shopvivaliz-marina-mcp/state")
FILENAME = "mcp-operator-smoke.json"
GRANT = {
    "pr_number": 3514,
    "head_sha": "e58172531a29c99c1f67aa2eb6bcd3fb20ce0f2b",
    "merge_sha": "a22f64f76729a21ccd7d1bf19f4a133fc0224a0a",
}


def _private_directory(path: Path, uid: int) -> None:
    meta = path.lstat()
    if not stat.S_ISDIR(meta.st_mode) or stat.S_ISLNK(meta.st_mode) or meta.st_uid != uid or stat.S_IMODE(meta.st_mode) != 0o700:
        raise RuntimeError("private_directory_invalid")


def _current_file_hash(uid: int) -> str | None:
    try:
        fd = os.open(WORKSPACE / FILENAME, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except FileNotFoundError:
        return None
    try:
        meta = os.fstat(fd)
        if (not stat.S_ISREG(meta.st_mode) or meta.st_uid != uid
                or stat.S_IMODE(meta.st_mode) != 0o600 or meta.st_size > 4096):
            raise RuntimeError("probe_file_metadata_unsafe")
        value = os.read(fd, 4097)
        if len(value) > 4096:
            raise RuntimeError("probe_file_too_large")
        return hashlib.sha256(value).hexdigest()
    finally:
        os.close(fd)


def _next_fence() -> int:
    db = STATE / "write-leases.sqlite3"
    if not db.is_file():
        return 1
    metadata = db.lstat()
    if not stat.S_ISREG(metadata.st_mode) or stat.S_ISLNK(metadata.st_mode) or stat.S_IMODE(metadata.st_mode) != 0o600:
        raise RuntimeError("lease_file_metadata_unsafe")
    # SQLite URI is fixed, not a client-controllable path.
    conn = sqlite3.connect("file:" + str(db) + "?mode=ro", uri=True, timeout=2)
    try:
        last = conn.execute(
            "SELECT MAX(fence) FROM reservations WHERE operation=?",
            ("marina_workspace_file_write",),
        ).fetchone()[0]
    finally:
        conn.close()
    next_value = (int(last) if last is not None else 0) + 1
    if next_value >= 2**63:
        raise RuntimeError("fencing_exhausted")
    return next_value


def _rpc(token: bytes, method: str, params: dict | None = None) -> dict:
    body: dict = {"jsonrpc": "2.0", "id": 1, "method": method}
    if params is not None:
        body["params"] = params
    http, result = request("/mcp", token, body)
    if http != 200 or not isinstance(result, dict):
        raise RuntimeError("local_rpc_failed")
    return result


def smoke() -> dict:
    uid = pwd.getpwnam(ACCOUNT).pw_uid
    if uid == 0 or os.geteuid() != uid or socket.gethostname().split(".", 1)[0] != HOST:
        raise RuntimeError("wrong_host_or_service_uid")
    _private_directory(WORKSPACE, uid)
    _private_directory(STATE, uid)
    token = safe_read_token()
    tools = _rpc(token, "tools/list")
    names = {x.get("name") for x in tools.get("result", {}).get("tools", []) if isinstance(x, dict)}
    if "marina_workspace_file_write" not in names:
        raise RuntimeError("approved_writer_not_discovered")

    previous_hash = _current_file_hash(uid)
    fencing = _next_fence()
    request_id = "marina-smoke-" + secrets.token_hex(12)
    body = json.dumps({"diagnostic": "mcp-private-file-write", "nonce": secrets.token_hex(10)},
                      separators=(",", ":"), sort_keys=True)
    args = dict(GRANT, filename=FILENAME, content_utf8=body,
                expected_sha256=previous_hash, idempotency_key=request_id,
                fencing_token=fencing)
    created = _rpc(token, "tools/call", {"name": "marina_workspace_file_write",
                                       "arguments": args}).get("result", {})
    if created.get("isError") is not False:
        raise RuntimeError("approved_private_write_denied")
    new_hash = hashlib.sha256(body.encode("utf-8")).hexdigest()
    if _current_file_hash(uid) != new_hash:
        raise RuntimeError("private_write_readback_mismatch")

    replay = dict(args, expected_sha256=new_hash)
    denied = _rpc(token, "tools/call", {"name": "marina_workspace_file_write",
                                       "arguments": replay}).get("result", {})
    if denied.get("isError") is not True or "lease_or_replay_denied" not in json.dumps(denied):
        raise RuntimeError("replay_not_denied")

    stale = dict(args, idempotency_key="marina-stalecas-" + secrets.token_hex(12),
                 fencing_token=fencing + 1, expected_sha256=previous_hash)
    denied = _rpc(token, "tools/call", {"name": "marina_workspace_file_write",
                                       "arguments": stale}).get("result", {})
    if denied.get("isError") is not True or "compare_and_swap_mismatch" not in json.dumps(denied):
        raise RuntimeError("stale_compare_and_swap_not_denied")
    return {"host": HOST, "approved_private_write": "PASS",
            "persisted_sha256": new_hash, "replay_denied": True,
            "stale_compare_and_swap_denied": True}


def main() -> int:
    try:
        result = smoke()
    except Exception as exc:
        print("MARINA_SCOPED_WRITE_SMOKE=FAIL reason=" + type(exc).__name__, file=sys.stderr)
        return 2
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
