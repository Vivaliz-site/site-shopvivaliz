#!/usr/bin/env python3
"""Marina MCP with read-only default and optional protected scoped writes. Never grants shell or sudo.

This component does not set up an ingress tunnel or register a ChatGPT
connector. Its sole listener must remain on loopback.
"""
from __future__ import annotations

import argparse
import hmac
import json
import os
import runpy
import socket
import stat
import subprocess
import sys
import threading
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

EXPECTED_HOST = "marina-a1-4c24g"
# Fixed sibling file staged root-owned and read-only by the official operator.
# run_path avoids ambient PYTHONPATH and caller-controlled module lookup.
_TELEMETRY = runpy.run_path(str(Path(__file__).with_name("read_only_telemetry.py")))
# Dormant unless trusted host startup supplies a protected write operator.
_WRITE = runpy.run_path(str(Path(__file__).with_name("scoped_write.py")))
# The admin route is also dormant unless an independent root-owned host broker
# supplies a verified, restricted operator; never use the network daemon as root.
_ADMIN = runpy.run_path(str(Path(__file__).with_name("operator_admin_route.py")))
PROTOCOL_VERSION = "2025-06-18"
MAX_BODY = 16384
# A local or tunneled slow client must not exhaust the read-only MCP's threads.
REQUEST_TIMEOUT_SECONDS = 5.0
MAX_OPEN_CONNECTIONS = 16
ALLOWED_SERVICES = frozenset({
    "shopvivaliz-desktop-commander.service",
    "shopvivaliz-queue-worker.service",
    "mlrr-fetch-worker.service",
    "amazon-returns-safet.service",
    "ml-pricing-worker.service",
    "docker.service",
})
TOOLS = [
    {
        "name": "marina_host_health",
        "description": "Identify the directly attached Marina host (read only).",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "marina_processes_list",
        "description": "List up to 48 process IDs and sanitized executable names, without argv, users or secrets.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "marina_system_usage",
        "description": "Read aggregate CPU, memory and root disk metrics without host mutation.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "marina_kernel_telemetry",
        "description": "Read one fixed non-secret Linux kernel metrics file, never caller-selected paths.",
        "inputSchema": {
            "type": "object",
            "properties": {"source": {"type": "string", "enum": ["uptime", "loadavg", "meminfo"]}},
            "required": ["source"], "additionalProperties": False,
        },
    },
    {
        "name": "marina_kernel_telemetry_batch",
        "description": "Read up to three unique fixed non-secret Linux telemetry resources.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "sources": {"type": "array", "items": {
                    "type": "string", "enum": ["uptime", "loadavg", "meminfo"]},
                    "minItems": 1, "maxItems": 3, "uniqueItems": True},
            },
            "required": ["sources"], "additionalProperties": False,
        },
    },
    {
        "name": "marina_service_status",
        "description": "Inspect an allowlisted service on Marina; never start or stop it.",
        "inputSchema": {
            "type": "object",
            "properties": {"service": {"type": "string", "enum": sorted(ALLOWED_SERVICES)}},
            "required": ["service"],
            "additionalProperties": False,
        },
    },
]

WRITE_TOOL = {
    "name": "marina_workspace_file_write",
    "description": "Create or CAS-replace one small private data file in Marina MCP's own workspace. Requires approved owner grant and durable host lease.",
    "inputSchema": {
        "type": "object",
        "properties": {
            "filename": {"type": "string", "pattern": "^[A-Za-z0-9][A-Za-z0-9_-]{0,47}\\.(txt|json)$"},
            "content_utf8": {"type": "string", "maxLength": 4096},
            "expected_sha256": {"oneOf": [{"type": "null"}, {"type": "string", "pattern": "^[0-9a-f]{64}$"}]},
            "pr_number": {"type": "integer", "minimum": 1},
            "head_sha": {"type": "string", "pattern": "^[0-9a-f]{40}$"},
            "merge_sha": {"type": "string", "pattern": "^[0-9a-f]{40}$"},
            "idempotency_key": {"type": "string", "pattern": "^[a-z0-9][a-z0-9-]{7,127}$"},
            "fencing_token": {"type": "integer", "minimum": 1},
        },
        "required": ["filename", "content_utf8", "expected_sha256", "pr_number",
                     "head_sha", "merge_sha", "idempotency_key", "fencing_token"],
        "additionalProperties": False,
    },
}

ADMIN_TOOL = {
    "name": "marina_olist_browser_restart",
    "description": "Request one allowlisted Olist browser systemd restart on Marina through a separate protected root broker; requires exact owner grant and durable fencing.",
    "inputSchema": {
        "type": "object",
        "properties": {
            "operation": {"type": "string", "enum": ["olist_browser_restart"]},
            "pr_number": {"type": "integer", "minimum": 1},
            "head_sha": {"type": "string", "pattern": "^[0-9a-f]{40}$"},
            "merge_sha": {"type": "string", "pattern": "^[0-9a-f]{40}$"},
            "idempotency_key": {"type": "string", "pattern": "^[a-z0-9][a-z0-9-]{7,127}$"},
            "fencing_token": {"type": "integer", "minimum": 1},
        },
        "required": ["operation", "pr_number", "head_sha", "merge_sha",
                     "idempotency_key", "fencing_token"],
        "additionalProperties": False,
    },
}


def load_token(path: Path) -> bytes:
    # Open once with O_NOFOLLOW: no lstat-to-open symlink/rename race.
    # Do not reveal filesystem paths, token bytes or syscall details on errors.
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except OSError:
        raise RuntimeError("token_file_open_refused") from None
    with os.fdopen(fd, "rb") as stream:
        metadata = os.fstat(stream.fileno())
        if not stat.S_ISREG(metadata.st_mode):
            raise RuntimeError("token_file_not_regular")
        if metadata.st_uid != os.geteuid() or stat.S_IMODE(metadata.st_mode) != 0o600:
            raise RuntimeError("token_file_permissions_invalid")
        if metadata.st_size < 32 or metadata.st_size > 258:
            raise RuntimeError("token_file_size_invalid")
        token = stream.read(259).strip()
    if not 32 <= len(token) <= 256 or any(byte < 33 or byte > 126 for byte in token):
        raise RuntimeError("token_file_value_invalid")
    return token


def inspect_service(service: str) -> dict[str, str]:
    if service not in ALLOWED_SERVICES:
        raise ValueError("service_not_allowed")
    result = subprocess.run(
        ["/usr/bin/systemctl", "show", service, "--no-pager",
         "--property=ActiveState,SubState,UnitFileState,NoNewPrivileges"],
        capture_output=True, text=True, timeout=4, check=False,
    )
    if result.returncode != 0:
        raise RuntimeError("service_metadata_unavailable")
    permitted = {"ActiveState", "SubState", "UnitFileState", "NoNewPrivileges"}
    values = {}
    for line in result.stdout[:4096].splitlines():
        key, separator, value = line.partition("=")
        if separator and key in permitted:
            values[key] = value[:64]
    return {"service": service, **values}


def create_server(
    token: bytes, port: int = 5580, *,
    host: str = "127.0.0.1",
    service_reader=inspect_service,
    write_operator=None,
    admin_operator=None,
) -> ThreadingHTTPServer:
    if host != "127.0.0.1":
        raise ValueError("loopback_only")
    actual_host = socket.gethostname().split(".")[0]
    if actual_host != EXPECTED_HOST:
        raise RuntimeError("host_identity_mismatch")
    if not isinstance(token, bytes) or len(token) < 32:
        raise ValueError("invalid_auth_configuration")
    slots = threading.BoundedSemaphore(8)

    class Handler(BaseHTTPRequestHandler):
        server_version = "MarinaReadOnlyMCP"
        sys_version = ""

        def log_message(self, _format: str, *_args: object) -> None:
            # No request URL, bearer headers, client address or body in logs.
            return

        def send_json(self, status: int, value: object) -> None:
            raw = json.dumps(value, separators=(",", ":"), ensure_ascii=True).encode()
            try:
                self.send_response(status)
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.send_header("Content-Length", str(len(raw)))
                self.send_header("Cache-Control", "no-store")
                self.send_header("X-Content-Type-Options", "nosniff")
                self.end_headers()
                self.wfile.write(raw)
            except (BrokenPipeError, ConnectionResetError):
                # A disconnected peer is normal and must not produce a traceback.
                return

        def authorized(self) -> bool:
            # Reject browser-origin cross-site calls unless an approved proxy
            # strips Origin after independently authenticating its client.
            if self.headers.get("Origin"):
                self.send_json(403, {"error": "origin_not_allowed"})
                return False
            value = self.headers.get("Authorization", "")
            candidate = value[7:].encode("utf-8") if value.startswith("Bearer ") else b""
            if not hmac.compare_digest(candidate, token):
                self.send_json(401, {"error": "unauthorized"})
                return False
            return True

        def do_GET(self) -> None:
            if self.path == "/mcp":
                if self.authorized():
                    # MCP Streamable HTTP 2025-06-18: GET without SSE must be 405.
                    self.send_response(405)
                    self.send_header("Allow", "POST")
                    self.send_header("Content-Length", "0")
                    self.send_header("Cache-Control", "no-store")
                    self.end_headers()
            elif self.path == "/health" and self.authorized():
                self.send_json(200, {
                    "ok": True,
                    "name": "shopvivaliz-marina-direct-readonly",
                    "host": EXPECTED_HOST,
                    "scope": "unprivileged_read_only",
                    "timestamp": datetime.now(timezone.utc).isoformat(),
                })
            else:
                self.send_json(404, {"error": "not_found"})

        def do_POST(self) -> None:
            if self.path != "/mcp":
                self.send_json(404, {"error": "not_found"})
                return
            if not self.authorized():
                return
            if self.headers.get("Content-Type", "").split(";", 1)[0].strip().lower() != "application/json":
                self.send_json(415, {"error": "unsupported_media_type"})
                return
            accepts = {part.split(";", 1)[0].strip().lower()
                       for part in self.headers.get("Accept", "").split(",")}
            if not {"application/json", "text/event-stream"}.issubset(accepts):
                self.send_json(406, {"error": "mcp_accept_required"})
                return
            version = self.headers.get("MCP-Protocol-Version")
            if version is not None and version != PROTOCOL_VERSION:
                self.send_json(400, {"error": "unsupported_protocol_version"})
                return
            if not slots.acquire(blocking=False):
                self.send_json(503, {"error": "busy"})
                return
            try:
                self.handle_rpc()
            finally:
                slots.release()

        def handle_rpc(self) -> None:
            try:
                length = int(self.headers.get("Content-Length", "-1"))
            except ValueError:
                self.send_json(400, {"error": "invalid_content_length"})
                return
            if length < 0:
                self.send_json(411, {"error": "length_required"})
                return
            if length > MAX_BODY:
                self.send_json(413, {"error": "body_too_large"})
                return
            try:
                request = json.loads(self.rfile.read(length))
            except (ValueError, UnicodeDecodeError):
                self.send_json(400, {"error": "invalid_json"})
                return
            if not isinstance(request, dict) or request.get("jsonrpc") != "2.0" or not isinstance(request.get("method"), str):
                self.send_json(400, {"error": "invalid_jsonrpc"})
                return
            method, identifier = request["method"], request.get("id")
            if method == "notifications/initialized" and "id" not in request:
                self.send_response(202)
                self.send_header("Content-Length", "0")
                self.end_headers()
                return
            if "id" not in request or isinstance(identifier, bool) or not isinstance(identifier, (str, int)):
                self.send_json(400, {"error": "invalid_request_id"})
                return
            result = None
            error = None
            try:
                if method == "initialize":
                    result = {
                        "protocolVersion": PROTOCOL_VERSION,
                        "capabilities": {"tools": {"listChanged": False}},
                        "serverInfo": {"name": "shopvivaliz-marina-direct-readonly", "version": "0.1.0"},
                    }
                elif method == "ping":
                    result = {}
                elif method == "tools/list":
                    result = {"tools": TOOLS + ([WRITE_TOOL] if write_operator is not None else [])
                              + ([ADMIN_TOOL] if admin_operator is not None else [])}
                elif method == "tools/call":
                    params = request.get("params")
                    if not isinstance(params, dict) or set(params) != {"name", "arguments"}:
                        raise ValueError("invalid_tool_request")
                    name, arguments = params["name"], params["arguments"]
                    if not isinstance(arguments, dict):
                        raise ValueError("invalid_tool_arguments")
                    tool_failed = False
                    if name == "marina_host_health" and not arguments:
                        output = {
                            "host": actual_host,
                            "user_uid": os.geteuid(),
                            "scope": "unprivileged_read_only",
                        }
                    elif name == "marina_processes_list" and not arguments:
                        output = _TELEMETRY["public_processes_list"]()
                    elif name == "marina_system_usage" and not arguments:
                        output = _TELEMETRY["public_system_usage"]()
                    elif name == "marina_kernel_telemetry" and set(arguments) == {"source"}:
                        output = _TELEMETRY["public_telemetry_read"](arguments["source"])
                    elif name == "marina_kernel_telemetry_batch" and set(arguments) == {"sources"}:
                        output = _TELEMETRY["public_telemetry_batch"](arguments["sources"])
                    elif name == "marina_olist_browser_restart" and admin_operator is not None:
                        admin_ok, reason = _ADMIN["execute_admin_operation"](arguments, admin_operator)
                        tool_failed = not admin_ok
                        output = {"status": reason} if admin_ok else {"reason": reason}
                    elif name == "marina_workspace_file_write" and write_operator is not None:
                        write_ok, output = _WRITE["execute"](arguments, write_operator)
                        tool_failed = not write_ok
                    elif name == "marina_service_status" and set(arguments) == {"service"}:
                        requested_service = arguments["service"]
                        if not isinstance(requested_service, str) or requested_service not in ALLOWED_SERVICES:
                            raise ValueError("service_not_allowed")
                        output = service_reader(requested_service)
                    else:
                        raise ValueError("unsupported_tool_or_arguments")
                    result = {"content": [{"type": "text", "text": json.dumps(output, sort_keys=True)}], "isError": tool_failed}
                else:
                    error = {"code": -32601, "message": "method_not_found"}
            except (ValueError, TypeError):
                error = {"code": -32602, "message": "invalid_params"}
            except (OSError, RuntimeError, subprocess.TimeoutExpired):
                error = {"code": -32603, "message": "read_only_probe_unavailable"}
            payload = {"jsonrpc": "2.0", "id": identifier}
            payload["error" if error else "result"] = error or result
            self.send_json(200, payload)

    class RedactedHTTPServer(ThreadingHTTPServer):
        def __init__(self, *args, **kwargs):
            self._open_connections = threading.BoundedSemaphore(MAX_OPEN_CONNECTIONS)
            super().__init__(*args, **kwargs)

        def process_request(self, request, client_address):
            # Reject excess sockets before creating another handler thread.
            if not self._open_connections.acquire(blocking=False):
                self.shutdown_request(request)
                return
            try:
                super().process_request(request, client_address)
            except BaseException:
                self._open_connections.release()
                raise

        def process_request_thread(self, request, client_address):
            try:
                # Covers incomplete HTTP headers AND partial JSON bodies.
                request.settimeout(REQUEST_TIMEOUT_SECONDS)
                super().process_request_thread(request, client_address)
            finally:
                self._open_connections.release()

        def handle_error(self, request, client_address):
            failure = sys.exc_info()[1]
            if isinstance(failure, (BrokenPipeError, ConnectionResetError, TimeoutError)):
                return
            # Suppress request/header/client details, retain diagnostic class.
            print("MARINA_MCP_HANDLER_FAILURE=" + type(failure).__name__, file=sys.stderr)

    server = RedactedHTTPServer((host, port), Handler)
    server.daemon_threads = True
    return server


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--token-file", type=Path,
                        default=Path.home() / ".config/shopvivaliz-marina-mcp/auth-token")
    parser.add_argument("--port", type=int, default=5580)
    parser.add_argument("--check", action="store_true", help="Check identity and secret metadata without binding")
    args = parser.parse_args()
    if socket.gethostname().split(".")[0] != EXPECTED_HOST:
        raise SystemExit("MARINA_MCP_REFUSED=host_identity_mismatch")
    try:
        token = load_token(args.token_file)
        if args.check:
            print("MARINA_MCP_READONLY_PREFLIGHT=PASS")
            return 0
        server = create_server(token, args.port)
    except (OSError, ValueError, RuntimeError) as exc:
        print(f"MARINA_MCP_REFUSED={str(exc)}", file=sys.stderr)
        return 1
    print("MARINA_MCP_READONLY_LISTEN=127.0.0.1", flush=True)
    try:
        server.serve_forever(poll_interval=0.2)
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
