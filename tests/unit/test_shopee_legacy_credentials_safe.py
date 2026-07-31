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
    def matches(self, text: str) -> set[str]:
        return {
            name
            for name, pattern in FINALIZER.CREDENTIAL_PATTERNS
            if any(not FINALIZER.likely_placeholder(match.group(1) if match.lastindex else match.group(0)) for match in pattern.finditer(text))
        }

    def test_task_branch_name_is_not_openai_key(self):
        self.assertNotIn("openai_key", self.matches("docs/status-task-033-stock-alerts.md"))

    def test_token_shaped_placeholder_is_ignored(self):
        synthetic = "sk-" + ("x" * 32)
        self.assertNotIn("openai_key", self.matches(synthetic))

    def test_detects_constructed_shopee_partner_key(self):
        synthetic = "shpk" + ("Ab3" * 12)
        self.assertIn("shopee_partner_key", self.matches(f"PARTNER_KEY={synthetic}"))

    def test_private_key_requires_complete_block(self):
        header = "-----BEGIN PRIVATE KEY-----"
        self.assertNotIn("private_key_block", self.matches(header))
        body = "A1b2" * 30
        full = f"{header}\n{body}\n-----END PRIVATE KEY-----"
        self.assertIn("private_key_block", self.matches(full))


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
