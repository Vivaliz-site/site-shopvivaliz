"""MCP read-only recovery when the approved write-grant provider is unavailable."""
from __future__ import annotations

import importlib.util
import io
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from unittest.mock import Mock, patch

SOURCE = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "server.py"
spec = importlib.util.spec_from_file_location("marina_server_write_startup", SOURCE)
assert spec and spec.loader
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class StartupGrantResilienceTest(unittest.TestCase):
    def run_main(self, *, check: bool, accepted: bool = False, unavailable: bool = False):
        args = ["server.py", "--enable-approved-scoped-write", "--token-file", "/tmp/test-token"]
        if check:
            args.append("--check")
        server = Mock()
        trusted_operator = object()
        def grant(*_args):
            if unavailable:
                raise OSError("offline")
            return accepted
        trusted = {
            "bootstrap_operator": lambda: trusted_operator,
            "_owner_grant_valid": grant,
            "GRANT_PR": 3514,
            "GRANT_HEAD": "a" * 40,
            "GRANT_MERGE": "b" * 40,
            "CAPABILITY": "file_write",
            "OPERATION": "marina_workspace_file_write",
            "REPOSITORY": "Vivaliz-site/site-shopvivaliz",
        }
        with patch.object(m.sys, "argv", args), \
             patch.object(m.socket, "gethostname", return_value=m.EXPECTED_HOST), \
             patch.object(m, "load_token", return_value=b"x"*64), \
             patch.object(m.runpy, "run_path", return_value=trusted), \
             patch.object(m, "create_server", return_value=server) as created, \
             redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
            status = m.main()
        return status, created, server, trusted_operator

    def test_false_grant_downgrades_to_readonly_but_strict_check_denies(self):
        status, created, server, _ = self.run_main(check=False)
        self.assertEqual(status, 0)
        self.assertIsNone(created.call_args.kwargs["write_operator"])
        server.serve_forever.assert_called_once()
        status, created, _, _ = self.run_main(check=True)
        self.assertEqual(status, 1)
        created.assert_not_called()

    def test_provider_outage_downgrades_to_readonly_but_strict_check_denies(self):
        status, created, _, _ = self.run_main(check=False, unavailable=True)
        self.assertEqual(status, 0)
        self.assertIsNone(created.call_args.kwargs["write_operator"])
        status, created, _, _ = self.run_main(check=True, unavailable=True)
        self.assertEqual(status, 1)
        created.assert_not_called()

    def test_approved_grant_exposes_only_trusted_operator(self):
        status, created, server, trusted = self.run_main(check=False, accepted=True)
        self.assertEqual(status, 0)
        self.assertIs(created.call_args.kwargs["write_operator"], trusted)
        server.server_close.assert_called_once()
        status, created, _, _ = self.run_main(check=True, accepted=True)
        self.assertEqual(status, 0)
        created.assert_not_called()


if __name__ == "__main__":
    unittest.main()
