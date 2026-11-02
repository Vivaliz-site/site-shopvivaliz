"""The local MCP smoke test keeps both positive and negative auth boundaries."""
import importlib.util
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "local_auth_smoke.py"
spec = importlib.util.spec_from_file_location("local_auth_smoke", SOURCE)
assert spec and spec.loader
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class SmokeContract(unittest.TestCase):
    def test_authenticated_smoke_and_denied_admin(self):
        replies = [(401, {"error": "unauthorized"}), (200, {"host": m.EXPECTED_HOST}),
                   (200, {"result": {"tools": [{"name": "marina_host_health"}, {"name": "marina_service_status"}]}}),
                   (200, {"error": {"code": -32602}})]
        with patch.object(m.socket, "gethostname", return_value=m.EXPECTED_HOST), \
             patch.object(m.os, "geteuid", return_value=1001), \
             patch.object(m.pwd, "getpwnam", return_value=type("U", (), {"pw_uid": 1001})()), \
             patch.object(m, "safe_read_token", return_value=b"a" * 64), \
             patch.object(m, "request", side_effect=replies) as req:
            report = m.smoke()
            self.assertEqual(report["local_mcp_smoke"], "PASS")
            self.assertTrue(report["admin_mutation_rejected"])
            self.assertIsNone(req.call_args_list[0].args[1] if len(req.call_args_list[0].args) > 1 else None)

    def test_admin_mutation_not_denied_fails_smoke(self):
        replies = [(401, {}), (200, {"host": m.EXPECTED_HOST}),
                   (200, {"result": {"tools": [{"name": "marina_host_health"}, {"name": "marina_service_status"}]}}),
                   (200, {"result": {"content": []}})]
        with patch.object(m.socket, "gethostname", return_value=m.EXPECTED_HOST), \
             patch.object(m.os, "geteuid", return_value=1001), \
             patch.object(m.pwd, "getpwnam", return_value=type("U", (), {"pw_uid": 1001})()), \
             patch.object(m, "safe_read_token", return_value=b"a" * 64), \
             patch.object(m, "request", side_effect=replies):
            with self.assertRaisesRegex(RuntimeError, "mutation_must_be_denied"):
                m.smoke()

    def test_secret_symlink_refused(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            token = root / "key"
            token.write_bytes(b"x" * 64)
            token.chmod(0o600)
            link = root / "link"
            link.symlink_to(token)
            with self.assertRaises(OSError):
                m.safe_read_token(link)
            self.assertEqual(m.safe_read_token(token), b"x" * 64)


if __name__ == "__main__":
    unittest.main()
