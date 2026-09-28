#!/usr/bin/env python3
"""Tokenless local stdio adapter for the ShopVivaliz Remote Control MCP.

Claude Code launches this process as an MCP stdio server. Each newline-delimited
JSON-RPC message is forwarded over a filesystem-permission-protected Unix socket
to the privileged controller. No bearer token or reusable OS credential is
available to this process.
"""
from __future__ import annotations

import http.client
import json
import os
import socket
import sys
from typing import Any

SOCKET_PATH = os.environ.get(
    "SHOPVIVALIZ_REMOTE_MCP_UNIX_SOCKET",
    "/run/shopvivaliz-remote-control/mcp.sock",
)
MAX_MESSAGE = 1_048_576


class UnixHTTPConnection(http.client.HTTPConnection):
    def __init__(self, socket_path: str) -> None:
        super().__init__("localhost", timeout=30)
        self.socket_path = socket_path

    def connect(self) -> None:
        sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        sock.settimeout(self.timeout)
        sock.connect(self.socket_path)
        self.sock = sock


def error_response(request: dict[str, Any] | None, message: str) -> bytes:
    rid = request.get("id") if isinstance(request, dict) else None
    return json.dumps(
        {
            "jsonrpc": "2.0",
            "id": rid,
            "error": {"code": -32000, "message": message},
        },
        ensure_ascii=False,
        separators=(",", ":"),
    ).encode("utf-8")


def forward(raw: bytes) -> bytes | None:
    if not raw or len(raw) > MAX_MESSAGE:
        return error_response(None, "invalid_message_size")

    try:
        request = json.loads(raw)
    except json.JSONDecodeError:
        return error_response(None, "invalid_json")

    connection = UnixHTTPConnection(SOCKET_PATH)
    try:
        connection.request(
            "POST",
            "/mcp",
            body=raw,
            headers={"Content-Type": "application/json"},
        )
        response = connection.getresponse()
        body = response.read(MAX_MESSAGE + 1)
    except (OSError, http.client.HTTPException) as exc:
        return error_response(request, "controller_unavailable:" + type(exc).__name__)
    finally:
        connection.close()

    if response.status == 202:
        return None
    if response.status != 200:
        return error_response(request, "controller_http_" + str(response.status))
    if len(body) > MAX_MESSAGE:
        return error_response(request, "controller_response_too_large")
    return body


def main() -> int:
    for line in sys.stdin.buffer:
        raw = line.strip()
        if not raw:
            continue
        result = forward(raw)
        if result is not None:
            sys.stdout.buffer.write(result + b"\n")
            sys.stdout.buffer.flush()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
