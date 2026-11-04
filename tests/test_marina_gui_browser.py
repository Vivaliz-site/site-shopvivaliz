"""Regression for the owner-approved Marina GUI and browser tools.

Runs without root. No display, browser or host process is started; identity and
process checks are stubbed where needed.
"""
from __future__ import annotations

import importlib.util
import json
import subprocess
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
            data = dict(gui.EXPECTED_GRANT, root_http_daemon_allowed=True)
            path.write_text(json.dumps(data), encoding="utf-8")
            with self.assertRaisesRegex(RuntimeError, "gui_browser_grant_mismatch"):
                gui.load_grant(path)

    def test_grant_forbids_stored_credentials_and_root_daemon(self):
        self.assertIs(gui.EXPECTED_GRANT["stored_credentials_allowed"], False)
        self.assertIs(gui.EXPECTED_GRANT["root_http_daemon_allowed"], False)

    def test_gui_and_shell_pin_the_same_grant(self):
        spec = importlib.util.spec_from_file_location("marina_admin_shell_for_gui", ROOT / "admin_shell.py")
        other = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(other)
        self.assertEqual(gui.EXPECTED_GRANT, other.EXPECTED_GRANT)


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

    def test_navigate_does_not_need_the_display(self):
        with patch.object(gui, "_running_xvfb_pid", return_value=None), \
             patch.object(gui, "_runtime_ready", return_value=False):
            result = gui.browser_navigate({"url": "https://e.example"}, audit=lambda e: True)
        self.assertEqual(result["reason"], "browser_unavailable")

    def test_navigate_reports_missing_browser_without_running_one(self):
        with patch.object(gui, "_running_xvfb_pid", return_value=4242), \
             patch.object(gui, "_runtime_ready", return_value=False):
            result = gui.browser_navigate({"url": "https://e.example"}, audit=lambda e: True)
        self.assertEqual(result["reason"], "browser_unavailable")


class AuditTests(unittest.TestCase):
    def setUp(self):
        patcher = patch.object(gui, "_service_identity_ok", return_value=True)
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_navigate_fails_when_audit_unavailable(self):
        with patch.object(gui, "_running_xvfb_pid", return_value=4242), \
             patch.object(gui, "_runtime_ready", return_value=True):
            result = gui.browser_navigate({"url": "https://e.example"}, audit=lambda e: False)
        self.assertEqual(result["reason"], "audit_unavailable")

    def test_audit_records_url_hash_not_url(self):
        entries = []
        page = json.dumps({"title": "T", "final_url": "https://secret.example/final",
                           "screenshot_png_base64": "AAAA"}).encode()
        def runner(argv, **kwargs):
            self.assertEqual(argv[1], str(gui.RUNNER_SCRIPT))
            self.assertEqual(kwargs["env"]["DISPLAY"], ":99")
            self.assertNotIn("PLAYWRIGHT_BROWSERS_PATH", {k for k in kwargs["env"] if "ubuntu" in kwargs["env"][k]})
            return subprocess.CompletedProcess(argv, 0, stdout=page, stderr=b"")
        with patch.object(gui, "_running_xvfb_pid", return_value=4242), \
             patch.object(gui, "_runtime_ready", return_value=True), \
             patch.object(gui, "_node_binary", return_value=Path("/opt/node/bin/node")), \
             patch.object(gui.Path, "mkdir"):
            result = gui.browser_navigate({"url": "https://secret.example/token=abc"},
                                          audit=lambda e: entries.append(e) or True, runner=runner)
        self.assertTrue(result["ok"])
        self.assertEqual(result["title"], "T")
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

            def poll(self):
                return None

        def fake_spawn(argv, **kwargs):
            calls.append(argv)
            return FakeProc()

        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(gui, "STATE_DIR", Path(tmp)), \
             patch.object(gui, "_running_xvfb_pid", side_effect=[None, 31337]), \
             patch.object(gui.Path, "exists", return_value=True):
            result = gui.gui_session({"action": "start"}, audit=lambda e: True, spawn=fake_spawn)
        self.assertTrue(result["running"])
        self.assertEqual(calls[0][0], gui.XVFB)
        self.assertEqual(calls[0][1], ":99")
        self.assertIn("-nolisten", calls[0])
        self.assertNotIn("-listen", calls[0])

    def test_start_waits_for_exec_before_reporting_running(self):
        import tempfile

        class FakeProc:
            pid = 555

            def poll(self):
                return None

        # Parent cmdline first (not yet exec'd), then the real Xvfb.
        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(gui, "STATE_DIR", Path(tmp)), \
             patch.object(gui, "_running_xvfb_pid", side_effect=[None, None, None, 555]), \
             patch.object(gui.Path, "exists", return_value=True), \
             patch.object(gui.time, "sleep"):
            result = gui.gui_session({"action": "start"}, audit=lambda e: True, spawn=lambda *a, **k: FakeProc())
        self.assertEqual(result["pid"], 555)

    def test_start_reports_failure_when_xvfb_dies(self):
        import tempfile

        class DeadProc:
            pid = 556

            def poll(self):
                return 1

        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(gui, "STATE_DIR", Path(tmp)), \
             patch.object(gui, "_running_xvfb_pid", return_value=None), \
             patch.object(gui.Path, "exists", return_value=True), \
             patch.object(gui.time, "sleep"):
            result = gui.gui_session({"action": "start"}, audit=lambda e: True, spawn=lambda *a, **k: DeadProc())
        self.assertEqual(result["reason"], "xvfb_failed")

    def test_stop_signals_the_recognised_display(self):
        import tempfile
        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(gui, "STATE_DIR", Path(tmp)), \
             patch.object(gui, "_running_xvfb_pid", return_value=777), \
             patch.object(gui.os, "kill") as kill:
            gui.gui_session({"action": "stop"}, audit=lambda e: True)
        kill.assert_called_once_with(777, gui.signal.SIGTERM)

    def test_stop_never_signals_foreign_process(self):
        import tempfile
        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(gui, "STATE_DIR", Path(tmp)), \
             patch.object(gui, "_running_xvfb_pid", return_value=None), \
             patch.object(gui.os, "kill") as kill:
            gui.gui_session({"action": "stop"}, audit=lambda e: True)
        kill.assert_not_called()


class BrowserFailureTests(unittest.TestCase):
    def setUp(self):
        for target, value in (("_service_identity_ok", True), ("_running_xvfb_pid", 4242), ("_runtime_ready", True)):
            patcher = patch.object(gui, target, return_value=value)
            patcher.start()
            self.addCleanup(patcher.stop)
        for patcher in (patch.object(gui, "_node_binary", return_value=Path("/opt/node/bin/node")),
                        patch.object(gui.Path, "mkdir")):
            patcher.start()
            self.addCleanup(patcher.stop)

    def test_nonzero_exit_is_reported_without_page_content(self):
        runner = lambda argv, **kw: subprocess.CompletedProcess(argv, 1, stdout=b"secret", stderr=b"boom")
        result = gui.browser_navigate({"url": "https://e.example"}, audit=lambda e: True, runner=runner)
        self.assertEqual(result, {"ok": False, "reason": "browser_failed", "exit_code": 1})

    def test_malformed_output_is_rejected(self):
        runner = lambda argv, **kw: subprocess.CompletedProcess(argv, 0, stdout=b"not json", stderr=b"")
        result = gui.browser_navigate({"url": "https://e.example"}, audit=lambda e: True, runner=runner)
        self.assertEqual(result["reason"], "browser_failed")

    def test_timeout_is_reported(self):
        def runner(argv, **kw):
            raise subprocess.TimeoutExpired(argv, 1)
        result = gui.browser_navigate({"url": "https://e.example"}, audit=lambda e: True, runner=runner)
        self.assertEqual(result["reason"], "browser_timeout")


class ToolSchemaTests(unittest.TestCase):
    def test_tools_are_strict_objects(self):
        names = {t["name"] for t in gui.GUI_BROWSER_TOOLS}
        self.assertEqual(names, {"marina_gui_session", "marina_browser_navigate"})
        for tool in gui.GUI_BROWSER_TOOLS:
            self.assertIs(tool["inputSchema"]["additionalProperties"], False)


if __name__ == "__main__":
    unittest.main()
