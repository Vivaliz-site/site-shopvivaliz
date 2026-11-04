"""End to end (real HTTP server) check that the admin shell, GUI and browser tools are
reachable only with a verified Cloudflare Access JWT, never with the local bearer.

The executors are stubs: no command, display or browser runs.
"""
from __future__ import annotations

import importlib.util
import json
import sys
import threading
import unittest
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
JWT_ONLY = {"marina_admin_shell", "marina_gui_session", "marina_browser_navigate"}
BEARER = "b" * 40


class Principal:
    def __init__(self):
        self.kind, self.subject = "service", "machine-test.access"


class Verifier:
    def verify(self, token):
        return Principal() if token == "signed-by-cloudflare" else None


class JwtGatedTools(unittest.TestCase):
    def start(self, *, wired=True):
        spec = importlib.util.spec_from_file_location("marina_jwt_gated_server_test", ROOT / "marina-direct-mcp" / "server.py")
        assert spec and spec.loader
        module = importlib.util.module_from_spec(spec)
        sys.modules[spec.name] = module
        spec.loader.exec_module(module)
        self.calls = []
        shell = lambda args: (self.calls.append(("shell", args)) or {"ok": True, "exit_code": 0, "stdout": "stub\n"})
        gui = {
            "gui_session": lambda args: (self.calls.append(("gui", args)) or {"ok": True, "running": True}),
            "browser_navigate": lambda args: (self.calls.append(("browser", args)) or {"ok": True, "title": "stub"}),
        }
        kwargs = {"admin_shell": shell, "gui_browser": gui} if wired else {}
        with patch.object(module.socket, "gethostname", return_value=module.EXPECTED_HOST):
            server = module.create_server(BEARER.encode(), port=0, access_verifier=Verifier(), **kwargs)
        worker = threading.Thread(target=server.serve_forever, daemon=True, kwargs={"poll_interval": 0.03})
        worker.start()
        self.addCleanup(server.server_close)
        self.addCleanup(worker.join, 2)
        self.addCleanup(server.shutdown)
        self.endpoint = f"http://127.0.0.1:{server.server_port}/mcp"

    def call(self, method, params=None, *, jwt=None, bearer=None, origin=None):
        body = {"jsonrpc": "2.0", "method": method, "id": 7}
        if params is not None:
            body["params"] = params
        headers = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream"}
        if jwt:
            headers["Cf-Access-Jwt-Assertion"] = jwt
        if bearer:
            headers["Authorization"] = "Bearer " + bearer
        if origin:
            headers["Origin"] = origin
        try:
            with urlopen(Request(self.endpoint, data=json.dumps(body).encode(), headers=headers), timeout=5) as response:
                return response.status, json.loads(response.read())
        except HTTPError as error:
            return error.code, json.loads(error.read() or b"null")

    def names(self, **auth):
        status, body = self.call("tools/list", {}, **auth)
        self.assertEqual(status, 200)
        return {tool["name"] for tool in body["result"]["tools"]}

    def test_unauthenticated_and_spoofed_requests_are_refused(self):
        self.start()
        self.assertEqual(self.call("tools/list", {})[0], 401)
        self.assertEqual(self.call("tools/list", {}, jwt="spoofed")[0], 401)
        self.assertEqual(self.call("tools/list", {}, bearer="wrong" * 8)[0], 401)

    def test_local_bearer_never_sees_or_runs_the_jwt_only_tools(self):
        self.start()
        self.assertFalse(JWT_ONLY & self.names(bearer=BEARER))
        for tool in sorted(JWT_ONLY):
            status, body = self.call("tools/call", {"name": tool, "arguments": {"command": "id", "action": "status", "url": "https://e.example"}}, bearer=BEARER)
            self.assertIn("error", body, tool)
        self.assertEqual(self.calls, [])

    def test_verified_jwt_sees_and_runs_all_three_tools(self):
        self.start()
        self.assertTrue(JWT_ONLY <= self.names(jwt="signed-by-cloudflare"))
        for tool, arguments in (("marina_admin_shell", {"command": "id", "as_root": True}),
                                ("marina_gui_session", {"action": "status"}),
                                ("marina_browser_navigate", {"url": "https://e.example"})):
            status, body = self.call("tools/call", {"name": tool, "arguments": arguments}, jwt="signed-by-cloudflare")
            self.assertEqual(status, 200, tool)
            self.assertFalse(body["result"].get("isError"), tool)
        self.assertEqual([kind for kind, _ in self.calls], ["shell", "gui", "browser"])
        self.assertEqual(self.calls[0][1], {"command": "id", "as_root": True})

    def test_jwt_request_with_cross_site_origin_is_refused(self):
        self.start()
        status, _ = self.call("tools/list", {}, jwt="signed-by-cloudflare", origin="https://evil.example")
        self.assertEqual(status, 403)

    def test_tools_are_hidden_when_not_wired_even_with_a_valid_jwt(self):
        self.start(wired=False)
        self.assertFalse(JWT_ONLY & self.names(jwt="signed-by-cloudflare"))


if __name__ == "__main__":
    unittest.main()
