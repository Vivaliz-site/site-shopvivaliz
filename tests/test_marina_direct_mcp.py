#!/usr/bin/env python3
"""Regression tests for the isolated, non-privileged Marina MCP prototype."""
from __future__ import annotations

import importlib.util
import json
import os
import socket
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError
from urllib.request import Request, urlopen

SERVER_SOURCE = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "server.py"
AUTH_VALUE = "fixture-" + ("x" * 48)
spec = importlib.util.spec_from_file_location("marina_direct_mcp", SERVER_SOURCE)
assert spec and spec.loader
m = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = m
spec.loader.exec_module(m)


class MarinaDedicatedMcpTests(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.token_file = Path(self.scratch.name) / "token"
        self.token_file.write_text(AUTH_VALUE, encoding="ascii")
        self.token_file.chmod(0o600)
        self.patcher = patch.object(m.socket, "gethostname", return_value=m.EXPECTED_HOST)
        self.patcher.start()
        self.addCleanup(self.patcher.stop)
        self.probed = []
        def reader(service):
            self.probed.append(service)
            return {"service": service, "ActiveState": "active"}
        self.server = m.create_server(m.load_token(self.token_file), port=0, service_reader=reader)
        self.addCleanup(self.server.server_close)
        self.thread = threading.Thread(target=self.server.serve_forever, kwargs={"poll_interval": 0.05}, daemon=True)
        self.thread.start()
        self.addCleanup(self.thread.join, 1)
        self.addCleanup(self.server.shutdown)
        self.base = f"http://127.0.0.1:{self.server.server_port}"

    def req(self, route="/mcp", body=None, bearer=None, origin=None, extra_headers=None):
        headers = {}
        if bearer:
            headers["Authorization"] = "Bearer " + bearer
        if origin:
            headers["Origin"] = origin
        data = json.dumps(body).encode("utf8") if body is not None else None
        if data is not None:
            headers["Content-Type"] = "application/json"
        if extra_headers:
            headers.update(extra_headers)
        req = Request(self.base + route, data=data, headers=headers)
        try:
            with urlopen(req, timeout=4) as response:
                raw = response.read()
                return response.status, (json.loads(raw) if raw else {})
        except HTTPError as exc:
            raw = exc.read()
            return exc.code, (json.loads(raw) if raw else {})

    def rpc(self, method, params=None, bearer=AUTH_VALUE):
        obj = {"jsonrpc": "2.0", "id": 1, "method": method}
        if params is not None:
            obj["params"] = params
        return self.req(body=obj, bearer=bearer,
                        extra_headers={"Accept": "application/json, text/event-stream",
                                       "MCP-Protocol-Version": m.PROTOCOL_VERSION})

    def test_fail_closed_authentication_and_origin(self):
        self.assertEqual(self.req(route="/health")[0], 401)
        self.assertEqual(self.req(route="/health", bearer="incorrect")[0], 401)
        self.assertEqual(self.req(route="/health", bearer=AUTH_VALUE,
                                  origin="https://evil.example")[0], 403)
        code, data = self.req(route="/health", bearer=AUTH_VALUE)
        self.assertEqual(code, 200)
        self.assertEqual(data["host"], m.EXPECTED_HOST)
        self.assertEqual(data["scope"], "unprivileged_read_only")

    def test_initialize_discovery_and_identity(self):
        code, value = self.rpc("initialize")
        self.assertEqual(code, 200)
        self.assertEqual(value["result"]["protocolVersion"], m.PROTOCOL_VERSION)
        self.assertEqual(self.rpc("ping")[0], 200)
        _, listed = self.rpc("tools/list")
        self.assertEqual({t["name"] for t in listed["result"]["tools"]},
                         {"marina_host_health", "marina_service_status"})
        _, output = self.rpc("tools/call", {"name": "marina_host_health", "arguments": {}})
        result = json.loads(output["result"]["content"][0]["text"])
        self.assertEqual(result["host"], m.EXPECTED_HOST)
        self.assertEqual(result["scope"], "unprivileged_read_only")
        self.assertEqual(self.probed, [])

    def test_read_only_service_allowlist_and_mutation_denial(self):
        _, value = self.rpc("tools/call", {
            "name": "marina_service_status", "arguments": {"service": "ml-pricing-worker.service"}})
        self.assertEqual(json.loads(value["result"]["content"][0]["text"])["ActiveState"], "active")
        for name, args in (
            ("admin_command_run", {"command": "id"}),
            ("service_action", {"service": "ml-pricing-worker.service", "action": "start"}),
            ("marina_service_status", {"service": "ssh.service; sudo id"}),
            ("marina_service_status", {"service": "sshd.service"}),
        ):
            _, refused = self.rpc("tools/call", {"name": name, "arguments": args})
            self.assertIn("error", refused, name)
        self.assertEqual(self.probed, ["ml-pricing-worker.service"])

    def test_streamable_http_requires_get_405_and_valid_headers(self):
        good_token = AUTH_VALUE
        code, _ = self.req(route="/mcp", bearer=good_token)
        self.assertEqual(code, 405, "GET MCP without SSE must return 405, not 404")
        self.assertEqual(self.req(route="/mcp")[0], 401)
        base = {"jsonrpc": "2.0", "method": "ping", "id": 2}
        self.assertEqual(self.req(body=base, bearer=good_token,
                                  extra_headers={"Accept": "application/json, text/event-stream",
                                                 "MCP-Protocol-Version": "bad-version"})[0], 400)
        self.assertEqual(self.req(body=base, bearer=good_token,
                                  extra_headers={"Accept": "application/json",
                                                 "MCP-Protocol-Version": m.PROTOCOL_VERSION})[0], 406)
        self.assertEqual(self.req(body=base, bearer=good_token,
                                  extra_headers={"Content-Type": "text/plain",
                                                 "Accept": "application/json, text/event-stream",
                                                 "MCP-Protocol-Version": m.PROTOCOL_VERSION})[0], 415)

    def test_unknown_rpc_and_body_bounds(self):
        _, unknown = self.rpc("dangerous/execute")
        self.assertEqual(unknown["error"]["code"], -32601)
        body = {"jsonrpc": "2.0", "id": 1, "method": "ping",
                "padding": "x" * (m.MAX_BODY + 10)}
        status, _ = self.req(
            body=body, bearer=AUTH_VALUE,
            extra_headers={"Accept": "application/json, text/event-stream",
                           "MCP-Protocol-Version": m.PROTOCOL_VERSION})
        self.assertEqual(status, 413)

    def test_disconnected_client_does_not_raise_or_log_secret(self):
        handler = object.__new__(self.server.RequestHandlerClass)
        handler.send_response = lambda status: None
        handler.send_header = lambda name, value: None
        handler.end_headers = lambda: None

        class PeerDisconnected:
            def write(self, data):
                raise BrokenPipeError("peer_disconnected")

        handler.wfile = PeerDisconnected()
        handler.send_json(200, {"ok": True})

    def test_slow_clients_have_socket_timeout_and_connection_cap(self):
        """RED: partial headers previously consumed unlimited unbounded threads."""
        with patch.object(m, "MAX_OPEN_CONNECTIONS", 2), patch.object(
            m, "REQUEST_TIMEOUT_SECONDS", 1.25
        ):
            gated = m.create_server(m.load_token(self.token_file), port=0)
            worker = threading.Thread(
                target=gated.serve_forever, kwargs={"poll_interval": 0.02}, daemon=True
            )
            worker.start()
            holders = []
            try:
                for _ in range(2):
                    conn = socket.create_connection(("127.0.0.1", gated.server_port), timeout=2)
                    conn.sendall(b"GET /health HTTP/1.1\r\nHost: localhost\r\n")
                    holders.append(conn)
                deadline = time.monotonic() + 1.0
                while gated._open_connections._value != 0 and time.monotonic() < deadline:
                    time.sleep(0.01)
                self.assertEqual(gated._open_connections._value, 0)
                excess = socket.create_connection(("127.0.0.1", gated.server_port), timeout=2)
                try:
                    excess.settimeout(2)
                    excess.sendall(b"GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n")
                    try:
                        rejected = excess.recv(4096)
                    except ConnectionResetError:
                        rejected = b""
                    self.assertEqual(rejected, b"", "over-limit connection was processed")
                finally:
                    excess.close()
                # Partial requests must time out, release their slots, and permit health probes.
                deadline = time.monotonic() + 3.0
                while gated._open_connections._value != 2 and time.monotonic() < deadline:
                    time.sleep(0.02)
                self.assertEqual(gated._open_connections._value, 2)
                req = Request(
                    f"http://127.0.0.1:{gated.server_port}/health",
                    headers={"Authorization": "Bearer " + AUTH_VALUE},
                )
                with urlopen(req, timeout=2) as response:
                    self.assertEqual(response.status, 200)
            finally:
                for conn in holders:
                    conn.close()
                gated.shutdown()
                worker.join(2)
                gated.server_close()

    def test_token_is_read_from_one_open_file_descriptor(self):
        # Deny pathname reopening after metadata validation (TOCTOU guard).
        with patch.object(Path, "read_bytes", side_effect=AssertionError("path_reopen_forbidden")):
            self.assertEqual(m.load_token(self.token_file),
                             AUTH_VALUE.encode("ascii"))

    def test_host_name_and_listener_fail_closed(self):
        with patch.object(m.socket, "gethostname", return_value="always-free-arm-1787907847-26"):
            with self.assertRaisesRegex(RuntimeError, "host_identity_mismatch"):
                m.create_server(m.load_token(self.token_file), port=0)
        with self.assertRaisesRegex(ValueError, "loopback_only"):
            m.create_server(m.load_token(self.token_file), host="0.0.0.0", port=0)

    def test_token_file_must_be_private_and_regular(self):
        self.token_file.chmod(0o644)
        with self.assertRaisesRegex(RuntimeError, "permissions"):
            m.load_token(self.token_file)
        self.token_file.chmod(0o600)
        symlink = Path(self.scratch.name) / "symlink"
        symlink.symlink_to(self.token_file)
        with self.assertRaisesRegex(RuntimeError, "open_refused|regular"):
            m.load_token(symlink)


if __name__ == "__main__":
    unittest.main()
