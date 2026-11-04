"""Regression for the owner-approved Marina GUI and browser tools.

Runs without root. No display, browser or host process is started; identity and
process checks are stubbed where needed.
"""
from __future__ import annotations

import importlib.util
import json
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1] / "marina-direct-mcp"
spec = importlib.util.spec_from_file_location("marina_gui_browser_test", ROOT / "gui_browser.py")
assert spec and spec.loader
gui = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gui)


class GrantTests(unittest.TestCase):
    def test_shipped_grant_matches_pinned_owner_approval(self):
        self.assertEqual(gui.load_grant(), gui.EXPECTED_GRANT)

    def test_mismatched_grant_fails_closed(self):
        import tempfile
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "grant.json"
            data = dict(gui.EXPECTED_GRANT, root_or_shell_allowed=True)
            path.write_text(json.dumps(data), encoding="utf-8")
            with self.assertRaisesRegex(RuntimeError, "gui_browser_grant_mismatch"):
                gui.load_grant(path)

    def test_grant_forbids_stored_credentials_and_root(self):
        self.assertIs(gui.EXPECTED_GRANT["stored_credentials_allowed"], False)
        self.assertIs(gui.EXPECTED_GRANT["root_or_shell_allowed"], False)
        self.assertIs(gui.EXPECTED_GRANT["sudo_allowed"], False)


class IdentityTests(unittest.TestCase):
    def test_session_refused_when_not_service_user(self):
        result = gui.gui_session({"action": "status"}, audit=lambda e: True)
        self.assertEqual(result, {"ok": False, "reason": "service_identity_required"})

    def test_navigate_refused_when_not_service_user(self):
        result = gui.browser_navigate({"url": "https://example.com"}, audit=lambda e: True)
        self.assertEqual(result["reason"], "service_identity_required")

    def test_bootstrap_refuses_non_service_identity(self):
        with self.assertRaisesRegex(RuntimeError, "gui_browser_requires_service_identity"):
            gui.bootstrap_gui_browser()


class ValidationTests(unittest.TestCase):
    def setUp(self):
        patcher = patch.object(gui, "_service_identity_ok", return_value=True)
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_session_rejects_unknown_action_and_extra_keys(self):
        self.assertEqual(gui.gui_session({"action": "reboot"})["reason"], "invalid_arguments")
        self.assertEqual(gui.gui_session({"action": "status", "x": 1})["reason"], "invalid_arguments")

    def test_navigate_rejects_non_http_schemes(self):
        for url in ("file:///etc/passwd", "javascript:alert(1)", "ftp://x.example/a"):
            with self.subTest(url=url):
                self.assertEqual(gui.browser_navigate({"url": url})["reason"], "invalid_arguments")

    def test_navigate_rejects_overlong_url_and_bad_timeout(self):
        long_url = "https://e.example/" + "a" * gui.MAX_URL_BYTES
        self.assertEqual(gui.browser_navigate({"url": long_url})["reason"], "invalid_arguments")
        self.assertEqual(gui.browser_navigate({"url": "https://e.example", "timeout_seconds": 0})["reason"],
                         "invalid_arguments")

    def test_navigate_requires_running_display(self):
        with patch.object(gui, "_running_xvfb_pid", return_value=None):
            result = gui.browser_navigate({"url": "https://e.example"}, audit=lambda e: True)
        self.assertEqual(result["reason"], "gui_session_not_running")

    def test_navigate_reports_missing_browser_without_running_one(self):
        with patch.object(gui, "_running_xvfb_pid", return_value=4242), \
             patch.object(gui, "_browser_executable", return_value=None):
            result = gui.browser_navigate({"url": "https://e.example"}, audit=lambda e: True)
        self.assertEqual(result["reason"], "browser_unavailable")


class AuditTests(unittest.TestCase):
    def setUp(self):
        patcher = patch.object(gui, "_service_identity_ok", return_value=True)
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_navigate_fails_when_audit_unavailable(self):
        with patch.object(gui, "_running_xvfb_pid", return_value=4242), \
             patch.object(gui, "_browser_executable", return_value=Path("/x/chrome")):
            result = gui.browser_navigate({"url": "https://e.example"}, audit=lambda e: False)
        self.assertEqual(result["reason"], "audit_unavailable")

    def test_audit_records_url_hash_not_url(self):
        entries = []
        # Force the audited path up to the Playwright import, which is stubbed as missing.
        with patch.object(gui, "_running_xvfb_pid", return_value=4242), \
             patch.object(gui, "_browser_executable", return_value=Path("/x/chrome")), \
             patch.dict("sys.modules", {"playwright": None, "playwright.sync_api": None}):
            result = gui.browser_navigate({"url": "https://secret.example/token=abc"}, audit=lambda e: entries.append(e) or True)
        self.assertEqual(result["reason"], "playwright_unavailable")
        self.assertEqual([e["event"] for e in entries], ["browser_start", "browser_end"])
        self.assertNotIn("secret.example", json.dumps(entries))
        self.assertIn("url_sha256", entries[0])


class DisplayTests(unittest.TestCase):
    def setUp(self):
        patcher = patch.object(gui, "_service_identity_ok", return_value=True)
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_status_reports_not_running_without_pid_file(self):
        import tempfile
        with tempfile.TemporaryDirectory() as tmp:
            with patch.object(gui, "STATE_DIR", Path(tmp)):
                result = gui.gui_session({"action": "status"}, audit=lambda e: True)
        self.assertEqual(result, {"ok": True, "running": False, "display": ":99"})

    def test_start_spawns_only_dedicated_display(self):
        import tempfile
        calls = []

        class FakeProc:
            pid = 31337

        def fake_spawn(argv, **kwargs):
            calls.append(argv)
            return FakeProc()

        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(gui, "STATE_DIR", Path(tmp)), \
             patch.object(gui, "_running_xvfb_pid", return_value=None), \
             patch.object(gui.Path, "exists", return_value=True):
            result = gui.gui_session({"action": "start"}, audit=lambda e: True, spawn=fake_spawn)
        self.assertTrue(result["running"])
        self.assertEqual(calls[0][0], gui.XVFB)
        self.assertEqual(calls[0][1], ":99")
        self.assertIn("-nolisten", calls[0])
        self.assertNotIn("-listen", calls[0])

    def test_stop_never_signals_foreign_process(self):
        import tempfile
        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(gui, "STATE_DIR", Path(tmp)), \
             patch.object(gui, "_running_xvfb_pid", return_value=None), \
             patch.object(gui.os, "kill") as kill:
            gui.gui_session({"action": "stop"}, audit=lambda e: True)
        kill.assert_not_called()


class ToolSchemaTests(unittest.TestCase):
    def test_tools_are_strict_objects(self):
        names = {t["name"] for t in gui.GUI_BROWSER_TOOLS}
        self.assertEqual(names, {"marina_gui_session", "marina_browser_navigate"})
        for tool in gui.GUI_BROWSER_TOOLS:
            self.assertIs(tool["inputSchema"]["additionalProperties"], False)


if __name__ == "__main__":
    unittest.main()
