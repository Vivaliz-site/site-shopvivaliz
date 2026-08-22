import importlib.util
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
SECRETS_PATH = ROOT / "config" / "secrets.py"


class SecretsCanonicalizationTest(unittest.TestCase):
    def load_module(self, environment: dict[str, str]):
        previous_cwd = Path.cwd()
        with tempfile.TemporaryDirectory() as temp_dir:
            os.chdir(temp_dir)
            try:
                with patch.dict(os.environ, environment, clear=True):
                    spec = importlib.util.spec_from_file_location(
                        f"config_secrets_test_{id(environment)}", SECRETS_PATH
                    )
                    module = importlib.util.module_from_spec(spec)
                    assert spec and spec.loader
                    spec.loader.exec_module(module)
                    return module
            finally:
                os.chdir(previous_cwd)

    def test_canonical_olist_token_does_not_read_legacy_static_alias(self):
        legacy_key = "TOKEN_" + "API_OLIST"
        module = self.load_module(
            {
                "OLIST_ACCESS_TOKEN": "canonical-value",
                legacy_key: "legacy-value",
            }
        )
        self.assertEqual(module.OLIST_ACCESS_TOKEN, "canonical-value")
        self.assertFalse(hasattr(module, legacy_key))

    def test_legacy_olist_static_alias_is_ignored(self):
        legacy_key = "TOKEN_" + "API_OLIST"
        module = self.load_module({legacy_key: "legacy-only"})
        self.assertEqual(module.OLIST_ACCESS_TOKEN, "")
        self.assertFalse(hasattr(module, legacy_key))

    def test_tiny_native_credentials_are_separate_from_olist(self):
        module = self.load_module(
            {
                "OLIST_ACCESS_TOKEN": "olist-value",
                "TINY_ACCESS_TOKEN": "tiny-value",
            }
        )
        self.assertEqual(module.OLIST_ACCESS_TOKEN, "olist-value")
        self.assertEqual(module.TINY_ACCESS_TOKEN, "tiny-value")
        self.assertNotEqual(module.OLIST_ACCESS_TOKEN, module.TINY_ACCESS_TOKEN)

    def test_ftp_and_smtp_canonical_names_precede_legacy_aliases(self):
        module = self.load_module(
            {
                "FTP_SERVER": "canonical-ftp",
                "FTP_HOST": "legacy-ftp",
                "SMTP_USER": "canonical-mail",
                "EMAIL_USER": "legacy-mail",
            }
        )
        self.assertEqual(module.FTP_SERVER, "canonical-ftp")
        self.assertEqual(module.FTP_HOST, "canonical-ftp")
        self.assertEqual(module.SMTP_USER, "canonical-mail")
        self.assertEqual(module.EMAIL_USER, "canonical-mail")

    def test_mask_secret_never_returns_full_value(self):
        module = self.load_module({})
        original = "sensitive-example-value"
        masked = module.mask_secret(original)
        self.assertNotEqual(masked, original)
        self.assertTrue(masked.startswith("sens"))
        self.assertNotIn("example-value", masked)


if __name__ == "__main__":
    unittest.main()
