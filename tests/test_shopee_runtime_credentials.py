import importlib.util
import json
import os
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]


def load_module(name: str, relative: str):
    path = ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


class ShopeeRuntimeCredentialsTest(unittest.TestCase):
    def test_runtime_loader_imports_only_shopee_values(self):
        module = load_module("shopee_runtime_exec_test", "scripts/shopee_runtime_exec.py")
        with tempfile.TemporaryDirectory() as tmp:
            env_file = Path(tmp) / ".env"
            env_file.write_text(
                "SHOPEE_PARTNER_ID=123\n"
                "SHOPEE_PARTNER_KEY='abc secret'\n"
                "SHOPEE_SHOP_ID=456\n"
                "SHOPEE_ACCESS_TOKEN=token-value\n"
                "DB_PASS=must-not-load\n",
                encoding="utf-8",
            )
            loaded = module.load_shopee_env(env_file)
        self.assertEqual(loaded["SHOPEE_PARTNER_ID"], "123")
        self.assertEqual(loaded["SHOPEE_PARTNER_KEY"], "abc secret")
        self.assertEqual(loaded["SHOPEE_SHOP_ID"], "456")
        self.assertEqual(loaded["SHOPEE_ACCESS_TOKEN"], "token-value")
        self.assertNotIn("DB_PASS", loaded)

    def test_preflight_presence_distinguishes_access_and_refresh_tokens(self):
        fake_utils = types.ModuleType("utils")
        fake_client_module = types.ModuleType("utils.shopee_client")
        fake_client_module.ShopeeClient = object
        with patch.dict(sys.modules, {"utils": fake_utils, "utils.shopee_client": fake_client_module}):
            module = load_module("shopee_runtime_preflight_test", "scripts/shopee_runtime_preflight.py")
        values = {
            "SHOPEE_PARTNER_ID": "123",
            "SHOPEE_PARTNER_KEY": "key",
            "SHOPEE_SHOP_ID": "456",
            "SHOPEE_ACCESS_TOKEN": "",
            "SHOPEE_REFRESH_TOKEN": "refresh",
        }
        with patch.dict(os.environ, values, clear=False):
            state = module.presence()
        self.assertTrue(state["SHOPEE_PARTNER_ID"])
        self.assertFalse(state["SHOPEE_ACCESS_TOKEN"])
        self.assertTrue(state["SHOPEE_REFRESH_TOKEN"])


class ShopeeDaemonTokenCacheTest(unittest.TestCase):
    def test_daemon_prefers_canonical_cache_for_rotating_tokens(self):
        module = load_module("shopee_daemon_token_cache_test", "daemon-shopee-token-renewer.py")
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            env_file = root / ".env"
            env_file.write_text(
                "SHOPEE_PARTNER_ID=123\n"
                "SHOPEE_PARTNER_KEY=partner-key-value\n"
                "SHOPEE_SHOP_ID=456\n"
                "SHOPEE_ACCESS_TOKEN=legacy-access\n"
                "SHOPEE_REFRESH_TOKEN=legacy-refresh\n",
                encoding="utf-8",
            )
            token_file = root / "shopee-tokens.json"
            token_file.write_text(
                json.dumps(
                    {
                        "access_token": "canonical-access",
                        "refresh_token": "canonical-refresh",
                        "expires_at": 123,
                    }
                ),
                encoding="utf-8",
            )
            module.ENV_PATH = env_file
            module.TOKEN_PATH = token_file
            with patch.dict(
                os.environ,
                {
                    "SHOPEE_PARTNER_ID": "",
                    "SHOPEE_PARTNER_KEY": "",
                    "SHOPEE_SHOP_ID": "",
                },
                clear=False,
            ):
                config = module.get_config()

        self.assertEqual(config["SHOPEE_PARTNER_ID"], "123")
        self.assertEqual(config["SHOPEE_ACCESS_TOKEN"], "canonical-access")
        self.assertEqual(config["SHOPEE_REFRESH_TOKEN"], "canonical-refresh")

    def test_daemon_refuses_legacy_env_tokens_when_cache_is_missing(self):
        module = load_module("shopee_daemon_no_legacy_fallback_test", "daemon-shopee-token-renewer.py")
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            env_file = root / ".env"
            env_file.write_text(
                "SHOPEE_PARTNER_ID=123\n"
                "SHOPEE_PARTNER_KEY=partner-key-value\n"
                "SHOPEE_SHOP_ID=456\n"
                "SHOPEE_ACCESS_TOKEN=legacy-access\n"
                "SHOPEE_REFRESH_TOKEN=legacy-refresh\n",
                encoding="utf-8",
            )
            module.ENV_PATH = env_file
            module.TOKEN_PATH = root / "missing-token-cache.json"
            with patch.dict(
                os.environ,
                {
                    "SHOPEE_PARTNER_ID": "",
                    "SHOPEE_PARTNER_KEY": "",
                    "SHOPEE_SHOP_ID": "",
                },
                clear=False,
            ):
                config = module.get_config()

        self.assertNotIn("SHOPEE_ACCESS_TOKEN", config)
        self.assertNotIn("SHOPEE_REFRESH_TOKEN", config)

    def test_daemon_updates_cache_atomically_preserving_mode_and_metadata(self):
        module = load_module("shopee_daemon_cache_write_test", "daemon-shopee-token-renewer.py")
        with tempfile.TemporaryDirectory() as tmp:
            token_file = Path(tmp) / "shopee-tokens.json"
            token_file.write_text(
                json.dumps(
                    {
                        "access_token": "old-access",
                        "refresh_token": "old-refresh",
                        "expires_at": 123,
                        "keep_me": "metadata",
                    }
                ),
                encoding="utf-8",
            )
            os.chmod(token_file, 0o640)
            module.TOKEN_PATH = token_file
            with patch.object(module.time, "time", return_value=1000):
                module.update_token_cache("new-access", "new-refresh", 3600)

            payload = json.loads(token_file.read_text(encoding="utf-8"))
            self.assertEqual(payload["access_token"], "new-access")
            self.assertEqual(payload["refresh_token"], "new-refresh")
            self.assertEqual(payload["expires_at"], 4600)
            self.assertEqual(payload["updated_at"], 1000)
            self.assertEqual(payload["keep_me"], "metadata")
            self.assertEqual(token_file.stat().st_mode & 0o777, 0o640)


class ShopeeClientRetryTest(unittest.TestCase):
    def _client(self):
        fake_requests = types.ModuleType("requests")

        class FakeHTTPError(Exception):
            def __init__(self, *args, response=None, **kwargs):
                super().__init__(*args)
                self.response = response

        class FakeConnectionError(Exception):
            pass

        class FakeTimeout(Exception):
            pass

        fake_requests.HTTPError = FakeHTTPError
        fake_requests.ConnectionError = FakeConnectionError
        fake_requests.Timeout = FakeTimeout
        fake_requests.Session = object
        fake_requests.Response = object
        with patch.dict(sys.modules, {"requests": fake_requests}):
            module = load_module("shopee_client_retry_test", "scripts/utils/shopee_client.py")
        client = module.ShopeeClient.__new__(module.ShopeeClient)
        client._decode = lambda response: response
        return module, client

    def test_first_request_with_fresh_access_token_does_not_force_refresh(self):
        module, client = self._client()
        client.refresh_token = "refresh-token"
        client.access_token = "access-token"
        client.access_expires_at = 5000
        client._last_refresh_attempt_monotonic = 0.0
        with (
            patch.object(module.time, "monotonic", return_value=100.0),
            patch.object(module.time, "time", return_value=1000.0),
            patch.object(client, "_refresh_access_token") as refresh,
        ):
            client._refresh_if_due()
        refresh.assert_not_called()
        self.assertEqual(client._last_refresh_attempt_monotonic, 100.0)

    def test_first_request_still_refreshes_when_access_token_near_expiry(self):
        module, client = self._client()
        client.refresh_token = "refresh-token"
        client.access_token = "access-token"
        client.access_expires_at = 1500
        client._last_refresh_attempt_monotonic = 0.0
        with (
            patch.object(module.time, "monotonic", return_value=100.0),
            patch.object(module.time, "time", return_value=1000.0),
            patch.object(client, "_refresh_access_token") as refresh,
        ):
            client._refresh_if_due()
        refresh.assert_called_once_with(required=True)

    def test_transient_timeout_retries_up_to_four_attempts(self):
        module, client = self._client()
        calls = 0

        def send(*args, **kwargs):
            nonlocal calls
            calls += 1
            if calls < 4:
                raise module.requests.Timeout("temporary")
            return {"ok": True}

        client._send_with_refresh = send
        with patch.object(module.time, "sleep") as sleep:
            self.assertEqual(client._get("/product/get_item_list"), {"ok": True})
        self.assertEqual(calls, 4)
        self.assertEqual([call.args[0] for call in sleep.call_args_list], [2, 4, 8])

    def test_transient_timeout_stops_after_four_attempts(self):
        module, client = self._client()
        calls = 0

        def send(*args, **kwargs):
            nonlocal calls
            calls += 1
            raise module.requests.Timeout("temporary")

        client._send_with_refresh = send
        with patch.object(module.time, "sleep") as sleep:
            with self.assertRaises(module.requests.Timeout):
                client._get("/product/get_item_list")
        self.assertEqual(calls, 4)
        self.assertEqual([call.args[0] for call in sleep.call_args_list], [2, 4, 8])

    def test_permanent_error_is_not_retried(self):
        module, client = self._client()
        calls = 0

        def send(*args, **kwargs):
            nonlocal calls
            calls += 1
            raise ValueError("permanent")

        client._send_with_refresh = send
        with patch.object(module.time, "sleep") as sleep:
            with self.assertRaises(ValueError):
                client._get("/product/get_item_list")
        self.assertEqual(calls, 1)
        sleep.assert_not_called()


if __name__ == "__main__":
    unittest.main()
