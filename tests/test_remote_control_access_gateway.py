"""Testes do gateway Access -> MCP do backend: sem JWT valido nada chega ao upstream."""
from __future__ import annotations

import json
import sys
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "marina-direct-mcp"))
sys.path.insert(0, str(ROOT / "remote-control-access-gateway"))
import gateway  # noqa: E402
from cloudflare_access_auth import AccessPrincipal  # noqa: E402


class FakeVerifier:
    def verify(self, assertion):
        return AccessPrincipal("user", "owner@example.com") if assertion == "good" else None


class Upstream(BaseHTTPRequestHandler):
    seen: list = []

    def log_message(self, *a):
        return

    def do_POST(self):
        body = self.rfile.read(int(self.headers["Content-Length"]))
        Upstream.seen.append((self.path, self.headers.get("Authorization"), body))
        out = b'{"ok":true}'
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(out)))
        self.end_headers()
        self.wfile.write(out)


def serve(server):
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server


class GatewayTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.up = serve(HTTPServer(("127.0.0.1", 0), Upstream))
        handler = gateway.make_handler(FakeVerifier(), "local-secret", cls.up.server_address[1])
        cls.gw = serve(HTTPServer(("127.0.0.1", 0), handler))
        cls.url = "http://127.0.0.1:%d/mcp" % cls.gw.server_address[1]

    @classmethod
    def tearDownClass(cls):
        cls.gw.shutdown()
        cls.up.shutdown()

    def setUp(self):
        Upstream.seen.clear()

    def post(self, headers=None, url=None, data=b'{"jsonrpc":"2.0"}'):
        h = {"Content-Type": "application/json"}
        h.update(headers or {})
        try:
            with urlopen(Request(url or self.url, data=data, headers=h, method="POST"), timeout=5) as r:
                return r.status, r.read()
        except HTTPError as e:
            return e.code, e.read()

    def test_missing_or_invalid_assertion_never_reaches_upstream(self):
        self.assertEqual(self.post()[0], 401)
        self.assertEqual(self.post({"Cf-Access-Jwt-Assertion": "bad"})[0], 401)
        self.assertEqual(Upstream.seen, [])

    def test_valid_assertion_forwards_with_local_bearer_only(self):
        status, body = self.post({"Cf-Access-Jwt-Assertion": "good", "Authorization": "Bearer client"})
        self.assertEqual((status, json.loads(body)), (200, {"ok": True}))
        self.assertEqual(Upstream.seen, [("/mcp", "Bearer local-secret", b'{"jsonrpc":"2.0"}')])

    def test_other_paths_and_methods_are_refused(self):
        self.assertEqual(self.post({"Cf-Access-Jwt-Assertion": "good"}, self.url.replace("/mcp", "/x"))[0], 404)
        with self.assertRaises(HTTPError) as ctx:
            urlopen(self.url, timeout=5)
        self.assertEqual(ctx.exception.code, 405)
        self.assertEqual(Upstream.seen, [])

    def test_upstream_down_fails_closed(self):
        handler = gateway.make_handler(FakeVerifier(), "t", 1)
        gw = serve(HTTPServer(("127.0.0.1", 0), handler))
        try:
            url = "http://127.0.0.1:%d/mcp" % gw.server_address[1]
            self.assertEqual(self.post({"Cf-Access-Jwt-Assertion": "good"}, url)[0], 502)
        finally:
            gw.shutdown()


if __name__ == "__main__":
    unittest.main()
