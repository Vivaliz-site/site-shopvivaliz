"""Agent client for the Marina MCP: the service token must never leak.

Uses a loopback stub server and fake credentials; no real endpoint is contacted.
"""
from __future__ import annotations

import contextlib
import importlib.util
import io
import json
import os
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("marina_agent_client_test", ROOT / "scripts" / "marina_mcp_agent_client.py")
assert spec and spec.loader
client = importlib.util.module_from_spec(spec)
spec.loader.exec_module(client)

FAKE_ID = "fake-client-id.access"
FAKE_SECRET = "fake-secret-value-0123456789abcdef"


class Stub(BaseHTTPRequestHandler):
    seen: list = []
    mode = "json"

    def log_message(self, *args):
        pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        Stub.seen.append({"id": self.headers.get("CF-Access-Client-Id"), "secret": self.headers.get("CF-Access-Client-Secret"),
                          "method": body["method"], "params": body.get("params")})
        if self.headers.get("CF-Access-Client-Secret") != FAKE_SECRET:
            self.send_response(401)
            self.end_headers()
            return
        method = body["method"]
        if method == "initialize":
            result = {"serverInfo": {"name": "stub"}}
        elif method == "tools/list":
            result = {"tools": [{"name": "marina_admin_shell"}, {"name": "marina_host_health"}]}
        else:
            name = body["params"]["name"]
            if name == "echo_secret":
                result = {"content": [{"type": "text", "text": "leak: " + FAKE_SECRET}]}
            elif name == "fails":
                result = {"isError": True, "content": [{"type": "text", "text": "boom"}]}
            else:
                result = {"content": [{"type": "text", "text": "stub-ok"}], "isError": False}
        payload = json.dumps({"jsonrpc": "2.0", "id": body["id"], "result": result})
        if Stub.mode == "sse":
            data, ctype = ("event: message\ndata: " + payload + "\n\n").encode(), "text/event-stream"
        else:
            data, ctype = payload.encode(), "application/json"
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


class AgentClientTests(unittest.TestCase):
    def setUp(self):
        Stub.seen, Stub.mode = [], "json"
        self.server = HTTPServer(("127.0.0.1", 0), Stub)
        threading.Thread(target=self.server.serve_forever, daemon=True, kwargs={"poll_interval": 0.02}).start()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)
        self.endpoint = f"http://127.0.0.1:{self.server.server_port}/mcp"
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.dir = Path(self.tmp.name)
        env = {"MARINA_MCP_SECRETS_DIR": str(self.dir)}
        patcher = patch.dict(os.environ, env)
        patcher.start()
        self.addCleanup(patcher.stop)
        for name in (client.ID_NAME, client.SECRET_NAME):
            os.environ.pop(name, None)

    def write(self, name, value, mode=0o600):
        path = self.dir / name
        path.write_text(value, encoding="utf-8")
        path.chmod(mode)
        return path

    def provision(self):
        self.write(client.ID_NAME, FAKE_ID)
        self.write(client.SECRET_NAME, FAKE_SECRET)

    def run_cli(self, *argv):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = client.main(["--endpoint", self.endpoint, *argv])
        return code, out.getvalue(), err.getvalue()

    def test_preflight_reports_absent_without_touching_the_network(self):
        code, out, _ = self.run_cli("preflight")
        self.assertEqual(code, 2)
        state = json.loads(out)
        self.assertEqual(state["client_id"]["state"], "ABSENT")
        self.assertIs(state["ready"], False)
        self.assertEqual(Stub.seen, [])

    def test_preflight_present_never_prints_values(self):
        self.provision()
        code, out, err = self.run_cli("preflight")
        self.assertEqual(code, 0)
        self.assertNotIn(FAKE_SECRET, out + err)
        self.assertNotIn(FAKE_ID, out + err)
        self.assertEqual(json.loads(out)["client_secret"]["state"], "PRESENT")

    def test_loose_permissions_symlink_and_foreign_owner_are_refused(self):
        self.provision()
        (self.dir / client.SECRET_NAME).chmod(0o644)
        self.assertEqual(client.file_state(self.dir / client.SECRET_NAME), "UNSAFE_MODE")
        code, _, err = self.run_cli("tools")
        self.assertEqual(code, 2)
        self.assertNotIn(FAKE_SECRET, err)
        (self.dir / client.SECRET_NAME).unlink()
        (self.dir / client.SECRET_NAME).symlink_to(self.dir / client.ID_NAME)
        self.assertEqual(client.file_state(self.dir / client.SECRET_NAME), "UNSAFE_SYMLINK")
        with patch.object(client.os, "geteuid", return_value=os.geteuid() + 1):
            self.assertEqual(client.file_state(self.dir / client.ID_NAME), "UNSAFE_OWNER")

    def test_non_https_remote_endpoint_is_refused_before_any_request(self):
        self.provision()
        for bad in ("http://example.com/mcp", "ftp://example.com/mcp", "http://10.0.0.5/mcp"):
            with self.subTest(endpoint=bad):
                with self.assertRaises(client.Refused):
                    client.check_endpoint(bad)
        self.assertEqual(client.check_endpoint("https://mcp-marina.shopvivaliz.com.br/mcp"), "https://mcp-marina.shopvivaliz.com.br/mcp")
        self.assertEqual(Stub.seen, [])

    def test_tools_sends_the_service_token_headers_and_lists_names(self):
        self.provision()
        code, out, err = self.run_cli("tools")
        self.assertEqual(code, 0, err)
        self.assertEqual(json.loads(out)["tools"], ["marina_admin_shell", "marina_host_health"])
        self.assertEqual([s["method"] for s in Stub.seen], ["initialize", "tools/list"])
        self.assertTrue(all(s["id"] == FAKE_ID and s["secret"] == FAKE_SECRET for s in Stub.seen))
        self.assertNotIn(FAKE_SECRET, out + err)

    def test_call_passes_tool_and_arguments(self):
        self.provision()
        code, out, _ = self.run_cli("call", "marina_admin_shell", "--args", '{"command": "id -un", "as_root": true}')
        self.assertEqual(code, 0)
        self.assertIn("stub-ok", out)
        sent = Stub.seen[-1]
        self.assertEqual(sent["method"], "tools/call")
        self.assertEqual(sent["params"], {"name": "marina_admin_shell", "arguments": {"command": "id -un", "as_root": True}})

    def test_a_secret_echoed_by_the_server_is_redacted(self):
        self.provision()
        code, out, err = self.run_cli("call", "echo_secret")
        self.assertEqual(code, 0)
        self.assertNotIn(FAKE_SECRET, out + err)
        self.assertIn("[REDACTED]", out)

    def test_tool_error_returns_exit_4_and_bad_token_returns_3(self):
        self.provision()
        self.assertEqual(self.run_cli("call", "fails")[0], 4)
        self.write(client.SECRET_NAME, "wrong-secret")
        code, _, err = self.run_cli("tools")
        self.assertEqual(code, 3)
        self.assertIn("HTTP 401", err)
        self.assertNotIn("wrong-secret", err)

    def test_event_stream_responses_are_parsed(self):
        self.provision()
        Stub.mode = "sse"
        code, out, _ = self.run_cli("call", "marina_host_health")
        self.assertEqual(code, 0)
        self.assertIn("stub-ok", out)

    def test_invalid_args_json_is_refused(self):
        self.provision()
        self.assertEqual(self.run_cli("call", "marina_admin_shell", "--args", "[1]")[0], 2)
        self.assertEqual(self.run_cli("call", "marina_admin_shell", "--args", "{bad")[0], 2)
        self.assertEqual(Stub.seen, [])

    def test_environment_credentials_work_without_files(self):
        with patch.dict(os.environ, {client.ID_NAME: FAKE_ID, client.SECRET_NAME: FAKE_SECRET}):
            code, out, err = self.run_cli("tools")
        self.assertEqual(code, 0, err)
        self.assertNotIn(FAKE_SECRET, out + err)


if __name__ == "__main__":
    unittest.main()
