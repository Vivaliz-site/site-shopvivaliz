#!/usr/bin/env python3
"""Root-only stdio adapter for the loopback ShopVivaliz Remote Control MCP."""
from __future__ import annotations
import json
import sys
import urllib.error
import urllib.request
from pathlib import Path

TOKEN_PATH = Path("/var/lib/shopvivaliz-remote-control/mcp-token")
MCP_URLS = (
    "http://127.0.0.1:5581/mcp",
    "http://127.0.0.1:5580/mcp",
)
MAX_MESSAGE = 1_048_576

def error_response(request, message):
    rid = request.get("id") if isinstance(request, dict) else None
    return {"jsonrpc":"2.0","id":rid,"error":{"code":-32000,"message":message}}

def forward(raw: bytes):
    if not raw or len(raw) > MAX_MESSAGE:
        return error_response(None, "invalid_message_size")
    try:
        request = json.loads(raw)
    except json.JSONDecodeError:
        return error_response(None, "invalid_json")
    token = TOKEN_PATH.read_text(encoding="utf-8").strip()
    if not token or any(ch.isspace() for ch in token):
        return error_response(request, "controller_token_unavailable")
    for index, mcp_url in enumerate(MCP_URLS):
        req = urllib.request.Request(
            mcp_url,
            data=raw,
            headers={"Content-Type":"application/json","Authorization":"Bearer "+token},
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=30) as response:
                body = response.read(MAX_MESSAGE + 1)
                status = response.status
        except urllib.error.HTTPError as exc:
            status = exc.code
            body = exc.read(MAX_MESSAGE + 1)
        except (OSError, urllib.error.URLError):
            if index + 1 < len(MCP_URLS):
                continue
            return error_response(request, "controller_unavailable")
        if status == 202:
            return None
        if status != 200:
            return error_response(request, "controller_http_"+str(status))
        if len(body) > MAX_MESSAGE:
            return error_response(request, "controller_response_too_large")
        try:
            return json.loads(body)
        except json.JSONDecodeError:
            return error_response(request, "controller_invalid_json")
    return error_response(request, "controller_unavailable")

def main():
    if len(sys.argv) != 1:
        raise SystemExit(64)
    for line in sys.stdin.buffer:
        raw=line.strip()
        if not raw:
            continue
        result=forward(raw)
        if result is not None:
            sys.stdout.write(json.dumps(result, ensure_ascii=False, separators=(",",":"))+"\n")
            sys.stdout.flush()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
