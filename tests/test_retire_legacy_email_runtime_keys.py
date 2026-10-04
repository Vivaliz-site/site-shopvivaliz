from __future__ import annotations

import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "scripts" / "retire-legacy-email-runtime-keys.py"

def load_module():
    spec = importlib.util.spec_from_file_location("retire_email_keys", MODULE)
    if spec is None or spec.loader is None:
        raise RuntimeError("import_failed")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

class RetireLegacyEmailRuntimeKeysTests(unittest.TestCase):
    def test_removes_only_legacy_email_keys(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            path = Path(td) / ".env"
            path.write_text(
                "BREVO_API_KEY=keep\n"
                "EMAIL_TO=fred@example.com\n"
                "EMAIL_USER=old@example.com\n"
                "EMAIL_PASSWORD=secret123\n"
                "MAIL_HOST=smtp.old.invalid\n"
                "OTHER_KEY=value\n",
                encoding="utf-8",
            )
            removed = mod.retire(path, apply=True)
            text = path.read_text(encoding="utf-8")
            self.assertEqual(set(removed), {"EMAIL_USER","EMAIL_PASSWORD","MAIL_HOST"})
            self.assertIn("BREVO_API_KEY=keep", text)
            self.assertIn("EMAIL_TO=fred@example.com", text)
            self.assertIn("OTHER_KEY=value", text)
            self.assertNotIn("EMAIL_PASSWORD=", text)

    def test_dry_run_does_not_change_file(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            path = Path(td) / ".env"
            original = "BREVO_API_KEY=keep\nEMAIL_USER=old@example.com\n"
            path.write_text(original, encoding="utf-8")
            removed = mod.retire(path, apply=False)
            self.assertEqual(removed, ["EMAIL_USER"])
            self.assertEqual(path.read_text(encoding="utf-8"), original)

if __name__ == "__main__":
    unittest.main()
