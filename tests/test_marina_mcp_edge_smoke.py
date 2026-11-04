"""Read-only edge smoke for the public Marina MCP route. Stub server on loopback; no network."""
from __future__ import annotations

import importlib.util
import json
import os
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("marina_edge_smoke_test", ROOT / "scripts" / "marina_mcp_edge_smoke.py")
assert spec and spec.loader
smoke = importlib.util.module_from_spec(spec)
spec.loader.exec_module(smoke)
client = smoke.load_client()

FAKE_ID = "fake-client-id.access"
FAKE_SECRET = "fake-secret-value-0123456789abcdef"
ALL_TOOLS = sorted(smoke.EXPECTED_TOOLS | {"marina_kernel_telemetry"})


class Stub(BaseHTTPRequestHandler):
    methods: list = []
    tools = ALL_TOOLS
    edge_block_urllib = True

    def log_message(self, *args):
        pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        Stub.methods.append(body["method"])
        ua = self.headers.get("User-Agent") or ""
        if (Stub.edge_block_urllib and ua.startswith("Python-urllib")) or \
                self.headers.get("CF-Access-Client-Secret") != FAKE_SECRET:
            self.send_response(403)
            self.end_headers()
            return
        result = {"serverInfo": {"name": "stub"}} if body["method"] == "initialize" else \
            {"tools": [{"name": n} for n in Stub.tools]}
        data = json.dumps({"jsonrpc": "2.0", "id": body["id"], "result": result}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


class EdgeSmokeTests(unittest.TestCase):
    def setUp(self):
        Stub.methods, Stub.tools = [], ALL_TOOLS
        self.server = HTTPServer(("127.0.0.1", 0), Stub)
        threading.Thread(target=self.server.serve_forever, daemon=True, kwargs={"poll_interval": 0.03}).start()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)
        self.url = f"http://127.0.0.1:{self.server.server_port}/mcp"
        env = patch.dict(os.environ, {client.ID_NAME: FAKE_ID, client.SECRET_NAME: FAKE_SECRET})
        env.start()
        self.addCleanup(env.stop)

    def test_healthy_route_reports_ok_and_never_calls_a_tool(self):
        code, report = smoke.smoke(client, self.url)
        self.assertEqual((code, report["status"]), (0, "OK"))
        self.assertEqual(Stub.methods, ["initialize", "tools/list"])
        self.assertNotIn(FAKE_SECRET, json.dumps(report))

    def test_urllib_user_agent_block_is_reported_as_edge_failure(self):
        # Control: the same stub with the client's own UA passes, so the 403 is the edge block.
        with patch.object(client, "USER_AGENT", "Python-urllib/3.12"):
            code, report = smoke.smoke(client, self.url)
        self.assertEqual((code, report["status"]), (3, "EDGE_OR_ACCESS_FAILURE"))

    def test_wrong_secret_is_an_access_failure(self):
        with patch.dict(os.environ, {client.SECRET_NAME: "wrong-secret-value-000000000000"}):
            code, report = smoke.smoke(client, self.url)
        self.assertEqual(code, 3)
        self.assertNotIn("wrong-secret", json.dumps(report))

    def test_missing_admin_tool_is_reported_as_drift(self):
        Stub.tools = [t for t in ALL_TOOLS if t != "marina_admin_shell"]
        code, report = smoke.smoke(client, self.url)
        self.assertEqual((code, report["status"]), (5, "TOOLSET_DRIFT"))
        self.assertEqual(report["missing"], ["marina_admin_shell"])

    def test_missing_credentials_exit_before_any_request(self):
        with patch.dict(os.environ, {client.ID_NAME: "", client.SECRET_NAME: ""}):
            with patch.object(client, "secrets_dir", return_value=Path("/nonexistent-marina-secrets")):
                code, report = smoke.smoke(client, self.url)
        self.assertEqual((code, report["status"]), (2, "CREDENTIALS_NOT_READY"))
        self.assertEqual(Stub.methods, [])

    def test_non_https_remote_endpoint_is_refused_without_a_request(self):
        code, report = smoke.smoke(client, "http://example.com/mcp")
        self.assertEqual((code, report["status"]), (2, "ENDPOINT_REFUSED"))
        self.assertEqual(Stub.methods, [])


if __name__ == "__main__":
    unittest.main()
