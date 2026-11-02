#!/usr/bin/env python3
"""Read-only Marina MCP local smoke; never displays credentials or mutates services."""
from __future__ import annotations

import http.client
import json
import os
import pwd
import socket
import stat
import sys
from pathlib import Path

EXPECTED_HOST = "marina-a1-4c24g"
SERVICE_USER = "shopvivaliz-marina-mcp"
TOKEN_FILE = Path("/var/lib/shopvivaliz-marina-mcp/.config/shopvivaliz-marina-mcp/auth-token")


def safe_read_token(path: Path = TOKEN_FILE) -> bytes:
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(fd, "rb") as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o600:
            raise RuntimeError("unsafe_token_metadata")
        value = stream.read(257).strip()
        if not 32 <= len(value) <= 256 or any(byte < 33 or byte > 126 for byte in value):
            raise RuntimeError("invalid_token_metadata")
        return value


def request(path: str, bearer: bytes | None = None, body: dict | None = None) -> tuple[int, dict]:
    headers = {"Accept": "application/json, text/event-stream", "MCP-Protocol-Version": "2025-06-18"}
    if bearer is not None:
        headers["Authorization"] = "Bearer " + bearer.decode("ascii")
    encoded = None
    method = "GET"
    if body is not None:
        method = "POST"
        headers["Content-Type"] = "application/json"
        encoded = json.dumps(body, separators=(",", ":")).encode("ascii")
    connection = http.client.HTTPConnection("127.0.0.1", 5580, timeout=5)
    try:
        connection.request(method, path, body=encoded, headers=headers)
        response = connection.getresponse()
        payload = response.read(32769)
        if len(payload) > 32768:
            raise RuntimeError("response_oversized")
        try:
            parsed = json.loads(payload) if payload else {}
        except ValueError:
            raise RuntimeError("invalid_response_json") from None
        if not isinstance(parsed, dict):
            raise RuntimeError("invalid_response_shape")
        return response.status, parsed
    finally:
        connection.close()


def smoke() -> dict:
    if socket.gethostname().split(".", 1)[0] != EXPECTED_HOST:
        raise RuntimeError("host_mismatch")
    if os.geteuid() != pwd.getpwnam(SERVICE_USER).pw_uid:
        raise RuntimeError("service_identity_mismatch")
    token = safe_read_token()
    no_auth_status, _ = request("/health")
    ok_status, ok = request("/health", token)
    if no_auth_status != 401 or ok_status != 200 or ok.get("host") != EXPECTED_HOST:
        raise RuntimeError("auth_boundary_failed")
    status, tools = request("/mcp", token, {"jsonrpc": "2.0", "id": 1, "method": "tools/list"})
    names = {tool.get("name") for tool in tools.get("result", {}).get("tools", []) if isinstance(tool, dict)}
    if status != 200 or not {"marina_host_health", "marina_service_status"}.issubset(names):
        raise RuntimeError("tools_discovery_failed")
    status, denied = request("/mcp", token, {"jsonrpc": "2.0", "id": 2, "method": "tools/call",
                                                    "params": {"name": "admin_command_run", "arguments": {"command": "id"}}})
    if status != 200 or "error" not in denied:
        raise RuntimeError("mutation_must_be_denied")
    return {"host": EXPECTED_HOST, "unauthorized_http": no_auth_status,
            "authorized_http": ok_status, "tools_discovered": len(names),
            "admin_mutation_rejected": True, "local_mcp_smoke": "PASS"}


def main() -> int:
    try:
        report = smoke()
    except Exception as exc:
        print("MARINA_MCP_LOCAL_SMOKE=FAIL;reason=" + type(exc).__name__ + "_" + str(exc).split(":", 1)[0][:48])
        return 2
    print(json.dumps(report, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
