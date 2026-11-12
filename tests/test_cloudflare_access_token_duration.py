"""Regressao do script que torna o service token da Marina sem expiracao. Sem rede."""
from __future__ import annotations

import importlib.util
import io
import os
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

PATH = Path(__file__).resolve().parents[1] / "scripts" / "quality" / "cloudflare_access_token_duration.py"
spec = importlib.util.spec_from_file_location("cf_token_duration", PATH)
assert spec and spec.loader
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

NAME = mod.DEFAULT_TOKEN_NAME


class PureLogicTests(unittest.TestCase):
    def test_find_by_name_is_exact_and_unique(self):
        tokens = [{"name": NAME + "-x", "id": "1"}, {"name": NAME, "id": "2"}, {"name": "outro", "id": "3"}]
        self.assertEqual(mod.find_by_name(tokens, NAME)["id"], "2")
        self.assertIsNone(mod.find_by_name([{"name": NAME}, {"name": NAME}], NAME))
        self.assertIsNone(mod.find_by_name([], NAME))

    def test_plan_variants(self):
        self.assertEqual(mod.plan(None)["verdict"], "TOKEN_NOT_FOUND")
        self.assertTrue(mod.plan({"duration": "8760h", "expires_at": "2026-12-02T00:00:00Z"})["will_update"])
        done = mod.plan({"duration": "forever"})
        self.assertFalse(done["will_update"])
        self.assertEqual(done["verdict"], "ALREADY_NON_EXPIRING")

    def test_http_error_classification(self):
        self.assertEqual(mod.classify_http_error(403), "SCOPE_INSUFFICIENT")
        self.assertEqual(mod.classify_http_error(401), "SCOPE_INSUFFICIENT")
        self.assertEqual(mod.classify_http_error(400), "DURATION_REJECTED")
        self.assertEqual(mod.classify_http_error(500), "HTTP_500")


class MainFlowTests(unittest.TestCase):
    def run_main(self, argv, requests):
        calls = []

        def fake_request(method, path, token, payload=None):
            calls.append((method, path.split("?")[0], payload))
            return requests.pop(0)

        out = io.StringIO()
        env = {"CLOUDFLARE_API_TOKEN": "x", "CLOUDFLARE_DNS_EDIT_TOKEN": "y"}
        with patch.dict(os.environ, env), patch.object(mod, "request", fake_request), \
                patch("sys.argv", ["prog"] + argv), redirect_stdout(out):
            rc = mod.main()
        return rc, out.getvalue(), calls

    ZONE = (200, {"result": [{"account": {"id": "acc"}}]})

    def listing(self, token):
        return (200, {"success": True, "result": [token], "result_info": {"total_pages": 1}})

    def test_dry_run_never_writes(self):
        token = {"id": "t", "name": NAME, "duration": "8760h", "expires_at": "2026-12-02T00:00:00Z"}
        rc, out, calls = self.run_main([], [self.ZONE, self.listing(token)])
        self.assertEqual(rc, 0)
        self.assertIn("MARINA_TOKEN_DURATION=DRY_RUN", out)
        self.assertFalse([c for c in calls if c[0] == "PUT"])

    def test_apply_writes_and_confirms(self):
        before = {"id": "t", "name": NAME, "duration": "8760h", "expires_at": "2026-12-02T00:00:00Z"}
        after = {"id": "t", "name": NAME, "duration": "forever", "expires_at": ""}
        rc, out, calls = self.run_main(["--apply"], [self.ZONE, self.listing(before), (200, {}), self.listing(after)])
        self.assertEqual(rc, 0)
        self.assertIn("MARINA_TOKEN_DURATION=UPDATED", out)
        puts = [c for c in calls if c[0] == "PUT"]
        self.assertEqual(len(puts), 1)
        self.assertEqual(puts[0][2], {"name": NAME, "duration": "forever"})

    def test_forbidden_is_reported_without_leaking(self):
        before = {"id": "t", "name": NAME, "duration": "8760h"}
        rc, out, _ = self.run_main(["--apply"], [self.ZONE, self.listing(before), (403, {})])
        self.assertEqual(rc, 6)
        self.assertIn("MARINA_TOKEN_DURATION=SCOPE_INSUFFICIENT", out)
        self.assertNotIn("client", out.lower())

    def test_unknown_token_fails_closed(self):
        rc, out, calls = self.run_main(["--apply"], [self.ZONE, self.listing({"id": "z", "name": "outro"})])
        self.assertEqual(rc, 5)
        self.assertIn("MARINA_TOKEN_PLAN=TOKEN_NOT_FOUND", out)
        self.assertFalse([c for c in calls if c[0] == "PUT"])

    def test_not_found_lists_only_name_and_dates_of_candidates(self):
        tokens = [{"id": "SECRET-ID", "client_id": "SECRET-CLIENT", "name": "marina-mcp-outro",
                   "expires_at": "2027-01-01T00:00:00Z", "duration": "8760h"},
                  {"id": "x", "name": "nao-relacionado"}]
        rc, out, _ = self.run_main([], [self.ZONE, (200, {"success": True, "result": tokens,
                                                          "result_info": {"total_pages": 1}})])
        self.assertEqual(rc, 5)
        self.assertIn("SERVICE_TOKEN_CANDIDATE name=marina-mcp-outro", out)
        self.assertNotIn("nao-relacionado", out)
        self.assertNotIn("SECRET", out)

    def test_missing_credentials_not_provisioned(self):
        out = io.StringIO()
        with patch.dict(os.environ, {}, clear=True), patch("sys.argv", ["prog"]), redirect_stdout(out):
            self.assertEqual(mod.main(), 2)
        self.assertIn("NOT_PROVISIONED", out.getvalue())


if __name__ == "__main__":
    unittest.main()
