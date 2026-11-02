"""MCP must hide all mutating tools unless a trusted host operator is installed."""
import http.client
import importlib.util
import json
import threading
import unittest
from pathlib import Path
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "server.py"
spec = importlib.util.spec_from_file_location("marina_mcp_write_gate", SOURCE)
assert spec is not None and spec.loader is not None
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class NetworkWriteGateTest(unittest.TestCase):
    def setUp(self):
        self.hostname_patch = patch.object(m.socket, "gethostname", return_value=m.EXPECTED_HOST)
        self.hostname_patch.start()
        self.addCleanup(self.hostname_patch.stop)

    def serve(self, *, write_operator=None, admin_operator=None):
        server = m.create_server(b"x" * 64, port=0, write_operator=write_operator, admin_operator=admin_operator)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(lambda: (server.shutdown(), server.server_close(), thread.join(timeout=5)))
        return server.server_address[1]

    def rpc(self, port, method, params=None):
        conn = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "Authorization": "Bearer " + "x" * 64,
            "MCP-Protocol-Version": m.PROTOCOL_VERSION,
        }
        body = {"jsonrpc": "2.0", "id": 1, "method": method}
        if params is not None:
            body["params"] = params
        try:
            conn.request("POST", "/mcp", json.dumps(body).encode(), headers)
            response = conn.getresponse()
            return response.status, json.loads(response.read())
        finally:
            conn.close()

    def test_readonly_instance_hides_write_and_rejects_invocation(self):
        port = self.serve()
        status, reply = self.rpc(port, "tools/list")
        self.assertEqual(status, 200)
        names = [x["name"] for x in reply["result"]["tools"]]
        self.assertNotIn("marina_workspace_file_write", names)
        self.assertNotIn("marina_olist_browser_restart", names)
        status, reply = self.rpc(port, "tools/call", {
            "name": "marina_workspace_file_write", "arguments": {},
        })
        self.assertIn("error", reply)
        status, reply = self.rpc(port, "tools/call", {
            "name": "marina_olist_browser_restart", "arguments": {},
        })
        self.assertIn("error", reply)

    def test_admin_broker_gate_does_not_execute_without_grant(self):
        port = self.serve(admin_operator=object())
        status, reply = self.rpc(port, "tools/list")
        self.assertEqual(status, 200)
        self.assertIn("marina_olist_browser_restart", [t["name"] for t in reply["result"]["tools"]])
        self.assertNotIn("marina_workspace_file_write", [t["name"] for t in reply["result"]["tools"]])
        with patch.dict(m._ADMIN, {"execute_admin_operation": lambda args, op: (False, "test_admin_grant_denied")}):
            status, reply = self.rpc(port, "tools/call", {
                "name": "marina_olist_browser_restart", "arguments": {},
            })
        self.assertEqual(status, 200)
        self.assertTrue(reply["result"]["isError"])
        self.assertIn("test_admin_grant_denied", reply["result"]["content"][0]["text"])

    def test_only_trusted_operator_instance_advertises_write(self):
        trusted_host_object = object()
        port = self.serve(write_operator=trusted_host_object)
        status, reply = self.rpc(port, "tools/list")
        self.assertEqual(status, 200)
        self.assertIn("marina_workspace_file_write", [x["name"] for x in reply["result"]["tools"]])
        with patch.dict(m._WRITE, {"execute": lambda args, op: (False, {"reason": "test_protected_grant_denied"})}):
            status, reply = self.rpc(port, "tools/call", {
                "name": "marina_workspace_file_write", "arguments": {},
            })
        self.assertEqual(status, 200)
        self.assertTrue(reply["result"]["isError"])
        self.assertIn("test_protected_grant_denied", reply["result"]["content"][0]["text"])


if __name__ == "__main__":
    unittest.main()
