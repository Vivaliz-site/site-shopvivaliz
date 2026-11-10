import datetime as dt
import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "scripts", "quality"))
import cloudflare_access_token_probe as probe  # noqa: E402

NOW = dt.datetime(2026, 11, 10, 12, 0, tzinfo=dt.timezone.utc)
CLIENT_ID = "abc123.access"
TOKEN = {"id": "tok-1", "name": "marina-mcp", "client_id": CLIENT_ID, "expires_at": "2027-01-01T00:00:00Z"}
APP = {"id": "app-1", "name": "mcp-marina", "domain": "mcp-marina.shopvivaliz.com.br/mcp"}
ALLOW = {"app-1": [{"include": [{"service_token": {"token_id": "tok-1"}}]}]}


class CloudflareAccessTokenProbeTests(unittest.TestCase):
    def test_ok_when_token_valid_and_policy_accepts(self):
        r = probe.evaluate([TOKEN], [APP], ALLOW, CLIENT_ID, NOW)
        self.assertEqual(r["verdict"], "OK")
        self.assertTrue(r["token_found"])
        self.assertTrue(r["policy_accepts_token"])
        self.assertFalse(r["token_expired"])

    def test_token_not_found_when_client_id_differs(self):
        r = probe.evaluate([TOKEN], [APP], ALLOW, "outro.access", NOW)
        self.assertEqual(r["verdict"], "TOKEN_NOT_FOUND")

    def test_expired_token_is_reported(self):
        expired = dict(TOKEN, expires_at="2026-01-01T00:00:00Z")
        r = probe.evaluate([expired], [APP], ALLOW, CLIENT_ID, NOW)
        self.assertEqual(r["verdict"], "TOKEN_EXPIRED")

    def test_policy_missing_token_is_reported(self):
        r = probe.evaluate([TOKEN], [APP], {"app-1": []}, CLIENT_ID, NOW)
        self.assertEqual(r["verdict"], "NOT_IN_MARINA_POLICY")
        self.assertFalse(r["policy_accepts_token"])

    def test_no_marina_app_is_reported(self):
        other = {"id": "app-2", "name": "outra", "domain": "admin.shopvivaliz.com.br"}
        r = probe.evaluate([TOKEN], [other], {}, CLIENT_ID, NOW)
        self.assertEqual(r["verdict"], "MARINA_APP_NOT_FOUND")

    def test_output_never_contains_client_id(self):
        r = probe.evaluate([TOKEN], [APP], ALLOW, CLIENT_ID, NOW)
        self.assertNotIn(CLIENT_ID, repr(r))


if __name__ == "__main__":
    unittest.main()
