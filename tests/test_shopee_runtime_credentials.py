import importlib.util
import os
import tempfile
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


if __name__ == "__main__":
    unittest.main()
