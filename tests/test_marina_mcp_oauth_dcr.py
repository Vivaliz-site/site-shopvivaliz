"""Regression checks for the Marina MCP DCR integration."""
import copy
import importlib.util
from pathlib import Path
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "marina_mcp_oauth_dcr.py"
spec = importlib.util.spec_from_file_location("marina_mcp_oauth_dcr", SCRIPT)
dcr = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dcr)

APP_ID = "f" * 32
CALLBACK = "https://chatgpt.com/connector/oauth/abcdef_123456"
APP = {
    "id": APP_ID,
    "domain": "mcp-marina.shopvivaliz.com.br",
    "type": "self_hosted",
    "name": "ShopVivaliz Marina MCP",
    "policies": [{"id": "3521", "precedence": 1}],
    "oauth_configuration": {
        "enabled": True,
        "grant": {"access_token_lifetime": "15m", "session_duration": "7d"},
        "dynamic_client_registration": {
            "enabled": False,
            "allowed_uris": ["https://chatgpt.com/connector_platform_oauth_redirect"],
        },
    },
}


class Tests(unittest.TestCase):
    def test_minimum_diff_and_preserve_policy(self):
        old = copy.deepcopy(APP)
        result = dcr.plan(old, APP_ID, CALLBACK)
        self.assertEqual(old, APP)
        self.assertEqual(result["policies"], APP["policies"])
        self.assertEqual(result["oauth_configuration"]["grant"], APP["oauth_configuration"]["grant"])
        state = result["oauth_configuration"]["dynamic_client_registration"]
        self.assertTrue(state["enabled"])
        self.assertFalse(state["allow_any_on_localhost"])
        self.assertFalse(state["allow_any_on_loopback"])
        self.assertEqual(state["allowed_uris"], [
            "https://chatgpt.com/connector_platform_oauth_redirect", CALLBACK,
        ])

    def test_idempotence(self):
        result = dcr.plan(APP, APP_ID, CALLBACK)
        self.assertEqual(dcr.plan(result, APP_ID, CALLBACK), result)

    def test_wrong_app_rejected(self):
        for field, value in (
            ("id", "a" * 32),
            ("domain", "admin-marina.shopvivaliz.com.br"),
            ("type", "saas"),
        ):
            app = copy.deepcopy(APP)
            app[field] = value
            with self.subTest(field=field), self.assertRaises(dcr.DcrError):
                dcr.plan(app, APP_ID, CALLBACK)

    def test_missing_managed_oauth_rejected(self):
        bad = copy.deepcopy(APP)
        bad["oauth_configuration"]["enabled"] = False
        with self.assertRaises(dcr.DcrError):
            dcr.plan(bad, APP_ID, CALLBACK)

    def test_reject_untrusted_redirects(self):
        bad_redirects = [
            "http://chatgpt.com/connector/oauth/abcdef_123456",
            "https://evil.invalid/connector/oauth/abcdef_123456",
            "https://chatgpt.com/connector/oauth/*",
            "https://chatgpt.com/connector/oauth/abcdef_123456?x=1",
            "https://chatgpt.com/connector/oauth/../admin",
            "https://chatgpt.com/connector/oauth/",
            "https://chatgpt.com:444/connector/oauth/abcdef_123456",
        ]
        for redirect in bad_redirects:
            with self.subTest(redirect=redirect), self.assertRaises(dcr.DcrError):
                dcr.validate_redirect(redirect)

    def test_stable_redirect_supported(self):
        uri = "https://chatgpt.com/connector_platform_oauth_redirect"
        self.assertEqual(dcr.validate_redirect(uri), uri)

    def test_existing_open_local_clients_rejected(self):
        for key in ("allow_any_on_localhost", "allow_any_on_loopback"):
            bad = copy.deepcopy(APP)
            bad["oauth_configuration"]["dynamic_client_registration"][key] = True
            with self.subTest(key=key), self.assertRaises(dcr.DcrError):
                dcr.plan(bad, APP_ID, CALLBACK)

    def test_private_backup_exclusive(self):
        with tempfile.TemporaryDirectory() as td:
            path = Path(td) / "backup.json"
            dcr.save_private_backup(path, APP)
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            with self.assertRaises(FileExistsError):
                dcr.save_private_backup(path, APP)

    def test_missing_process_token_does_not_claim_credential_unprovisioned(self):
        from contextlib import redirect_stderr, redirect_stdout
        from io import StringIO
        import os
        import sys
        from unittest.mock import patch

        output, errors = StringIO(), StringIO()
        argv = ["marina_mcp_oauth_dcr.py", "--apply", "--app-id", APP_ID,
                "--redirect-uri", CALLBACK, "--backup-path", "/tmp/not-written.json"]
        with patch.object(dcr, "probe", return_value=False), \
             patch.object(sys, "argv", argv), \
             patch.dict(os.environ, {"CLOUDFLARE_API_TOKEN": ""}), \
             redirect_stdout(output), redirect_stderr(errors):
            status = dcr.main()
        self.assertEqual(status, 3)
        self.assertIn("provider_credential_not_in_current_process", errors.getvalue())
        self.assertNotIn("provider_credential_not_provisioned", errors.getvalue())

    def test_rollback_rejects_concurrent_policy_change_without_put(self):
        from unittest.mock import patch
        initial = copy.deepcopy(APP)
        updated = dcr.plan(initial, APP_ID, CALLBACK)
        concurrent = copy.deepcopy(updated)
        concurrent["policies"] = [{"id": "different-policy", "precedence": 1}]
        with patch.object(dcr, "app_get", return_value=concurrent), \
             patch.object(dcr, "api_json") as api:
            with self.assertRaisesRegex(dcr.DcrError, "rollback_concurrent_app_change"):
                dcr.rollback_if_safe("fake", APP_ID, initial, updated)
            api.assert_not_called()

    def test_apply_auto_rolls_back_if_public_discovery_never_propagates(self):
        from contextlib import redirect_stdout, redirect_stderr
        from io import StringIO
        from unittest.mock import patch
        import os,sys
        initial = copy.deepcopy(APP)
        updated = dcr.plan(initial, APP_ID, CALLBACK)
        puts = []
        def fake_api(url, token='', method='GET', payload=None):
            if method == 'PUT':
                puts.append(copy.deepcopy(payload))
                return {'success': True}
            raise AssertionError('unexpected_url')
        with tempfile.TemporaryDirectory() as folder:
            argv = ["dcr.py", "--apply", "--app-id", APP_ID, "--redirect-uri", CALLBACK,
                    "--backup-path", str(Path(folder) / "before.json")]
            stdout, stderr = StringIO(), StringIO()
            with patch.object(sys, "argv", argv), \
                 patch.dict(os.environ, {"CLOUDFLARE_API_TOKEN": "unit-test-only"}), \
                 patch.object(dcr, "probe", return_value=False), \
                 patch.object(dcr.time, "sleep", return_value=None), \
                 patch.object(dcr, "app_get", side_effect=[initial, updated, updated, initial]), \
                 patch.object(dcr, "api_json", side_effect=fake_api), \
                 redirect_stdout(stdout), redirect_stderr(stderr):
                status = dcr.main()
            self.assertEqual(3, status)
            self.assertEqual(puts, [updated, initial])
            self.assertIn('CLOUDFLARE_DCR_ROLLBACK_CONFIRMED=true', stdout.getvalue())
            self.assertIn('public_oauth_discovery_not_propagated', stderr.getvalue())
            self.assertEqual((Path(folder) / "before.json").stat().st_mode & 0o777, 0o600)

    def test_readback_policy_change_is_rejected(self):
        from unittest.mock import patch
        altered=copy.deepcopy(APP)
        altered["policies"] = [{"id": "different"}]
        with patch.object(dcr, 'app_get', return_value=altered):
            with self.assertRaisesRegex(dcr.DcrError, 'rollback_concurrent_app_change'):
                dcr.rollback_if_safe('fake', APP_ID, APP, dcr.plan(APP, APP_ID, CALLBACK))
    def test_probe_detects_missing_dcr(self):
        original = dcr.api_json
        def fake(url, **kwargs):
            if url.endswith("oauth-authorization-server"):
                return {"issuer": "https://issuer.example", "code_challenge_methods_supported": ["S256"]}
            return {"authorization_servers": ["https://issuer.example"], "resource": dcr.RESOURCE}
        try:
            dcr.api_json = fake
            self.assertFalse(dcr.probe())
        finally:
            dcr.api_json = original


if __name__ == "__main__":
    unittest.main()
