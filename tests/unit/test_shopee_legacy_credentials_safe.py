from __future__ import annotations

import importlib.util
import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "scripts" / "maintenance" / "finalize_reorganization.py"
SPEC = importlib.util.spec_from_file_location("finalize_reorganization", MODULE_PATH)
assert SPEC and SPEC.loader
FINALIZER = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = FINALIZER
SPEC.loader.exec_module(FINALIZER)


class CredentialScannerTests(unittest.TestCase):
    def pattern(self, name: str):
        return dict(FINALIZER.CREDENTIAL_PATTERNS)[name]

    def test_task_branch_name_is_not_openai_key(self):
        text = "docs/status-task-033-stock-alerts.md"
        self.assertIsNone(self.pattern("openai_key").search(text))

    def test_detects_constructed_openai_key_shape(self):
        prefix = "".join(("s", "k", "-"))
        body = "".join(("Ab3K9z", "Q7mN2p", "R8vT4x", "L6cD5f"))
        self.assertIsNotNone(self.pattern("openai_key").search(prefix + body))

    def test_detects_constructed_shopee_partner_key(self):
        prefix = "".join(("sh", "pk"))
        body = "".join(("Ab3K9z", "Q7mN2p", "R8vT4x", "L6cD5f"))
        candidate = prefix + body
        self.assertIsNotNone(self.pattern("shopee_partner_key").search(candidate))
        self.assertFalse(FINALIZER.likely_placeholder(candidate))

    def test_explicit_protected_placeholder_is_ignored(self):
        self.assertTrue(FINALIZER.likely_placeholder("<SECRET_PROTEGIDO>"))

    def test_sensitive_quoted_literal_requires_a_literal_value(self):
        value = "".join(("Aa9Bb8", "Cc7Dd6", "Ee5Ff4"))
        literal = f'password="{value}"'
        runtime_lookup = 'password=os.environ.get("PASSWORD")'
        self.assertIsNotNone(self.pattern("sensitive_quoted_literal").search(literal))
        self.assertIsNone(self.pattern("sensitive_quoted_literal").search(runtime_lookup))

    def test_private_key_requires_complete_block(self):
        header = "".join(("-----BEGIN ", "PRIVATE KEY-----"))
        footer = "".join(("-----END ", "PRIVATE KEY-----"))
        self.assertIsNone(self.pattern("private_key_block").search(header))
        body = "".join(("A1b2C3d4",) * 16)
        full = f"{header}\n{body}\n{footer}"
        self.assertIsNotNone(self.pattern("private_key_block").search(full))


class RetiredShopeeToolTests(unittest.TestCase):
    paths = (
        "scripts/get_token.py",
        "scripts/run_playwright.py",
        "scripts/shopee_full_pipeline.py",
        "scripts/test_final.py",
        "scripts/test_shopee_simple.py",
        "scripts/test_shopee_api.py",
        "claude/api/shopee-integration/scripts/run_playwright.py",
        "claude/api/shopee-integration/scripts/test_final.py",
        "claude/api/shopee-integration/scripts/test_shopee_api.py",
    )

    def test_legacy_tools_are_wrappers(self):
        for relative in self.paths:
            with self.subTest(relative=relative):
                text = (ROOT / relative).read_text(encoding="utf-8")
                self.assertIn("runpy.run_path", text)
                self.assertIn("retired_credential_tool.py", text)
                self.assertNotIn("requests.post", text)
                self.assertNotIn("sync_playwright", text)

    def test_legacy_tools_fail_closed_with_evidence(self):
        report = ROOT / "artifacts" / "disabled-executors" / "shopee-legacy-credential-tool.json"
        for relative in self.paths:
            with self.subTest(relative=relative):
                report.unlink(missing_ok=True)
                result = subprocess.run(
                    [sys.executable, str(ROOT / relative)],
                    cwd=ROOT,
                    text=True,
                    capture_output=True,
                    timeout=20,
                    check=False,
                )
                self.assertEqual(result.returncode, 2)
                self.assertTrue(report.is_file())
                payload = json.loads(report.read_text(encoding="utf-8"))
                self.assertEqual(payload["status"], "blocked")
                self.assertFalse(payload["external_operation_performed"])


if __name__ == "__main__":
    unittest.main()
