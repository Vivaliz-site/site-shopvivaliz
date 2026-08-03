from __future__ import annotations

import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class OlistEntrypointSafetyTests(unittest.TestCase):
    def test_legacy_paths_are_compatibility_wrappers(self):
        expected = {
            ROOT / "scripts" / "olist-sync-master.py": "sync_master.py",
            ROOT / "scripts" / "olist-oauth-login.py": "oauth_login.py",
            ROOT / "oauth-auto-exec.py": "oauth_login.py",
        }
        for path, target in expected.items():
            text = path.read_text(encoding="utf-8")
            self.assertIn("runpy.run_path", text)
            self.assertIn("marketplace", text)
            self.assertIn("olist", text)
            self.assertIn(target, text)

    def test_oauth_entrypoint_contains_no_insecure_login_automation(self):
        paths = [
            ROOT / "oauth-auto-exec.py",
            ROOT / "scripts" / "olist-oauth-login.py",
            ROOT / "scripts" / "marketplace" / "olist" / "oauth_login.py",
        ]
        forbidden = (
            "session.verify = False",
            "OLIST_PASSWORD",
            "EMAIL_PASSWORD",
            "response.text[:500]",
            "access_token[:",
            "session.post(login_form_url",
            "async_playwright",
        )
        combined = "\n".join(path.read_text(encoding="utf-8") for path in paths)
        for marker in forbidden:
            self.assertNotIn(marker, combined)

    def test_entrypoints_block_without_external_operation(self):
        cases = (
            (
                ROOT / "scripts" / "marketplace" / "olist" / "sync_master.py",
                ROOT / "artifacts" / "disabled-executors" / "olist-sync-master.json",
            ),
            (
                ROOT / "scripts" / "marketplace" / "olist" / "oauth_login.py",
                ROOT / "artifacts" / "disabled-executors" / "olist-oauth-login.json",
            ),
            (
                ROOT / "oauth-auto-exec.py",
                ROOT / "artifacts" / "disabled-executors" / "olist-oauth-login.json",
            ),
        )
        for script, report in cases:
            report.unlink(missing_ok=True)
            result = subprocess.run(
                [sys.executable, str(script)],
                cwd=ROOT,
                text=True,
                capture_output=True,
                check=False,
            )
            self.assertEqual(result.returncode, 2)
            self.assertTrue(report.is_file())
            payload = json.loads(report.read_text(encoding="utf-8"))
            self.assertEqual(payload["status"], "blocked")
            self.assertFalse(payload["external_operation_performed"])


if __name__ == "__main__":
    unittest.main()
