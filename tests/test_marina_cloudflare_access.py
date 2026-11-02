"""Cloudflare Access auth and noninteractive service-token security tests."""
from __future__ import annotations

import importlib.util
import json
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

import jwt
from cryptography.hazmat.primitives.asymmetric import rsa
from jwt.utils import base64url_encode

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "marina-direct-mcp"))
from cloudflare_access_auth import AccessPrincipal, CloudflareAccessVerifier, load_access_config


class AccessJwtTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.private = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        public = cls.private.public_key().public_numbers()
        cls.jwks = {"keys": [{
            "kty": "RSA", "kid": "approved-key", "use": "sig", "alg": "RS256",
            "n": base64url_encode(public.n.to_bytes(256, "big")).decode(),
            "e": base64url_encode(public.e.to_bytes(3, "big")).decode(),
        }]}

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name) / "access.json"
        self.cfg = {
            "team_domain": "shopvivaliz.cloudflareaccess.com",
            "audience": "approved-app-audience-123456",
            "allowed_emails": ["dev@example.com"],
            "allowed_service_tokens": ["machine-marina-123.access"],
        }
        self.save()
        self.verifier = CloudflareAccessVerifier(load_access_config(self.path))
        self.verifier._jwks.fetch_data = lambda: self.jwks

    def save(self):
        self.path.write_text(json.dumps(self.cfg), encoding="utf-8")
        self.path.chmod(0o600)

    def token(self, **overrides):
        now = int(time.time())
        claims = {"iss": "https://shopvivaliz.cloudflareaccess.com",
                  "aud": self.cfg["audience"], "type": "app",
                  "sub": "identity-123", "email": "dev@example.com",
                  "iat": now - 1, "exp": now + 90}
        claims.update(overrides)
        return jwt.encode(claims, self.private, algorithm="RS256",
                          headers={"kid": "approved-key"})

    def test_valid_user_and_automated_machine_token(self):
        self.assertEqual(self.verifier.verify(self.token()), AccessPrincipal("user", "dev@example.com"))
        self.assertEqual(self.verifier.verify(self.token(sub="", email=None,
                         common_name="machine-marina-123.access")),
                         AccessPrincipal("service", "machine-marina-123.access"))

    def test_invalid_signatures_claims_and_scope_denied(self):
        bad = [self.token(aud="wrong"), self.token(iss="https://wrong.cloudflareaccess.com"),
               self.token(type="org"), self.token(exp=int(time.time()) - 1),
               self.token(nbf=int(time.time()) + 500), self.token(iat=int(time.time()) + 500),
               self.token(email="other@example.com"), self.token(email="DEV@example.com"),
               self.token(sub=""), self.token(sub="", email=None, common_name="unapproved.access"),
               self.token(sub="", common_name="machine-marina-123.access"),
               self.token(common_name="machine-marina-123.access"),
               self.token(exp=None), "not-a-jwt", "x" * 9000]
        for value in bad:
            with self.subTest(value=str(value)[:20]):
                self.assertIsNone(self.verifier.verify(value))
        self.assertIsNone(self.verifier.verify(
            jwt.encode({"exp": int(time.time()) + 200}, "", algorithm="none")))

    def test_config_is_strict_private_file_and_no_dynamic_url(self):
        self.assertEqual(load_access_config(self.path)["audience"], self.cfg["audience"])
        self.path.chmod(0o644)
        with self.assertRaisesRegex(RuntimeError, "metadata_invalid"):
            load_access_config(self.path)
        self.cfg["team_domain"] = "attacker.net"
        self.save()
        with self.assertRaisesRegex(RuntimeError, "domain_invalid"):
            load_access_config(self.path)
        self.cfg["team_domain"] = "shopvivaliz.cloudflareaccess.com"
        self.cfg["allowed_emails"] = []
        self.cfg["allowed_service_tokens"] = []
        self.save()
        with self.assertRaisesRegex(RuntimeError, "no_principals"):
            load_access_config(self.path)
        self.path.unlink()
        self.path.symlink_to(Path(self.tmp.name) / "no-file")
        with self.assertRaisesRegex(RuntimeError, "open_refused"):
            load_access_config(self.path)


class McpAccessIntegration(unittest.TestCase):
    def test_access_user_and_machine_cannot_invoke_approved_local_writes(self):
        spec = importlib.util.spec_from_file_location(
            "marina_cloudflare_server_test", ROOT / "marina-direct-mcp" / "server.py")
        assert spec and spec.loader
        m = importlib.util.module_from_spec(spec)
        sys.modules[spec.name] = m
        spec.loader.exec_module(m)
        class IdentityVerifier:
            def verify(self, token):
                if token == "signed-from-cloudflare":
                    return AccessPrincipal("service", "machine-marina-123.access")
                return None
        with patch.object(m.socket, "gethostname", return_value=m.EXPECTED_HOST):
            server = m.create_server(b"x" * 40, port=0, write_operator=object(),
                                     access_verifier=IdentityVerifier())
        worker = threading.Thread(target=server.serve_forever, daemon=True,
                                  kwargs={"poll_interval": 0.03})
        worker.start()
        self.addCleanup(server.server_close)
        self.addCleanup(worker.join, 2)
        self.addCleanup(server.shutdown)
        endpoint = f"http://127.0.0.1:{server.server_port}/mcp"
        def call(method, args=None, *, token=None, bearer=None, origin=None):
            request = {"jsonrpc": "2.0", "method": method, "id": 10}
            if args is not None:
                request["params"] = args
            headers = {"Content-Type": "application/json",
                       "Accept": "application/json, text/event-stream"}
            if token:
                headers["Cf-Access-Jwt-Assertion"] = token
            if bearer:
                headers["Authorization"] = "Bearer " + bearer
            if origin:
                headers["Origin"] = origin
            req = Request(endpoint, data=json.dumps(request).encode(), headers=headers)
            try:
                with urlopen(req, timeout=4) as response:
                    return response.status, json.loads(response.read())
            except HTTPError as error:
                return error.code, json.loads(error.read())
        self.assertEqual(call("tools/list")[0], 401)
        self.assertEqual(call("tools/list", token="spoofed")[0], 401)
        self.assertEqual(call("tools/list", token="signed-from-cloudflare",
                              origin="https://evil.example")[0], 403)
        status, tools = call("tools/list", token="signed-from-cloudflare")
        self.assertEqual(status, 200)
        names = {t["name"] for t in tools["result"]["tools"]}
        self.assertEqual(names, {t["name"] for t in m.TOOLS})
        status, result = call("tools/call", {"name": "marina_workspace_file_write",
                              "arguments": {}}, token="signed-from-cloudflare")
        self.assertEqual(status, 200)
        self.assertIn("error", result)
        status, result = call("tools/call", {"name": "marina_host_health",
                              "arguments": {}}, token="signed-from-cloudflare")
        self.assertEqual(status, 200)
        self.assertIn("result", result)
        # Existing local-grant behavior remains unchanged.
        _, existing = call("tools/list", bearer="x" * 40)
        self.assertIn("marina_workspace_file_write",
                      {tool["name"] for tool in existing["result"]["tools"]})


if __name__ == "__main__":
    unittest.main(verbosity=2)
