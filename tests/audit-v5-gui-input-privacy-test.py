#!/usr/bin/env python3
from __future__ import annotations
import importlib.util
import os
from pathlib import Path
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
os.environ["SHOPVIVALIZ_BROWSER_MCP_BASE_SERVER"] = str(ROOT / "remote-control-mcp" / "server.py")
os.environ["SHOPVIVALIZ_REMOTE_MCP_STATE"] = "/tmp/shopvivaliz-audit-v5-gui-tests"
os.environ["SHOPVIVALIZ_REMOTE_MCP_PORT"] = "0"

SPEC = importlib.util.spec_from_file_location("browser_mcp_v5", ROOT / "remote-control-browser-mcp" / "server.py")
assert SPEC and SPEC.loader
m = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)

class GuiInputPrivacyV5Tests(unittest.TestCase):
    def test_typing_never_places_secret_substring_in_argv(self):
        value = "synthetic-only value\n--literal 'quoted'"
        completed = mock.Mock(returncode=0, stdout="", stderr="")
        with (mock.patch.object(m, "gui_prefix", return_value=["fixture-env"]),
              mock.patch.object(m.subprocess, "run", return_value=completed) as run):
            m.type_text(value)
        argv = run.call_args.args[0]
        self.assertFalse(any(value in str(part) for part in argv))
        self.assertEqual(argv[-2:], ["--file", "-"])
        self.assertEqual(run.call_args.kwargs.get("input"), value)

    def test_typing_rejects_nul_before_process_spawn(self):
        with mock.patch.object(m, "run_gui") as run:
            with self.assertRaisesRegex(ValueError, "^browser_text_contains_nul$"):
                m.type_text("synthetic\x00private")
        run.assert_not_called()

    def test_typing_rejects_non_utf8_surrogate_with_fixed_error_before_process(self):
        with mock.patch.object(m, "run_gui") as run:
            with self.assertRaisesRegex(ValueError, "^browser_text_not_utf8$"):
                m.type_text("\ud800")
        run.assert_not_called()

    def test_typing_timeout_has_fixed_diagnostic(self):
        with mock.patch.object(m, "run_gui", side_effect=m.subprocess.TimeoutExpired(["xdotool"], 30, output="private")):
            with self.assertRaisesRegex(RuntimeError, "^browser_type_timeout$"):
                m.type_text("synthetic-private")

    def test_typing_command_failure_has_fixed_diagnostic(self):
        with mock.patch.object(m, "run_gui", side_effect=RuntimeError("synthetic-private")):
            with self.assertRaisesRegex(RuntimeError, "^browser_type_command_failed$"):
                m.type_text("synthetic-private")

    def test_browser_type_validates_before_focus_side_effects(self):
        with mock.patch.object(m, "active_browser_window") as active:
            with self.assertRaisesRegex(ValueError, "^browser_text_contains_nul$"):
                m.browser_type({"text": "a\x00b"})
        active.assert_not_called()

    def test_validate_url_rejects_ascii_controls(self):
        for value in ("https://example.com/\nnext", "https://example.com/\tq", "https://example.com/\x00x", "https://example.com/\x7fx"):
            with self.subTest(value=repr(value)):
                with self.assertRaisesRegex(ValueError, "^browser_url_control_character$"):
                    m.validate_url(value)

    def test_browser_audit_retains_length_but_no_unsalted_text_digest(self):
        captured = {}
        def fake(tool, host, args, ok, summary):
            captured.update(args)
            return "audit-id"
        with mock.patch.object(m, "BASE_AUDIT", side_effect=fake):
            m.audit("browser_gui_type", None, {"text": "123456"}, True, "ok")
        self.assertEqual(captured["text_length"], 6)
        self.assertNotIn("text", captured)
        self.assertNotIn("text_sha256", captured)

    def test_base_audit_drops_raw_and_prehashed_sensitive_metadata(self):
        fields = ("text", "otp", "secret", "code", "password", "prompt", "message", "body", "payload")
        for field in fields:
            with self.subTest(field=field):
                safe = m.base.sanitize_audit_args("browser_type", {
                    field: "synthetic-private",
                    field + "_sha256": "a" * 64,
                    "tab_id": "ABC",
                })
                self.assertNotIn(field, safe)
                self.assertNotIn(field + "_sha256", safe)
                self.assertEqual(safe[field + "_length"], len("synthetic-private"))
                self.assertEqual(safe["tab_id"], "ABC")

if __name__ == "__main__":
    unittest.main()
