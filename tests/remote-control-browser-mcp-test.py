#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import os
from pathlib import Path
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
os.environ["SHOPVIVALIZ_BROWSER_MCP_BASE_SERVER"] = str(ROOT / "remote-control-mcp" / "server.py")
os.environ["SHOPVIVALIZ_REMOTE_MCP_STATE"] = "/tmp/shopvivaliz-browser-mcp-tests"
os.environ["SHOPVIVALIZ_REMOTE_MCP_PORT"] = "0"

SPEC = importlib.util.spec_from_file_location("browser_mcp", ROOT / "remote-control-browser-mcp" / "server.py")
assert SPEC and SPEC.loader
m = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)


class BrowserMcpTests(unittest.TestCase):
    def test_base_server_directory_is_available_for_sibling_imports(self):
        self.assertIn(str((ROOT / "remote-control-mcp").resolve()), m.sys.path)

    def test_all_base_tools_and_six_browser_tools_are_exposed(self):
        names = {item["name"] for item in m.tool_specs()}
        base_names = {item["name"] for item in m.base.tool_specs()}
        self.assertTrue(base_names <= names)
        self.assertEqual(
            {"browser_tabs", "browser_open", "browser_navigate", "browser_screenshot", "browser_click", "browser_type"},
            m.BROWSER_TOOLS,
        )
        self.assertTrue(m.BROWSER_TOOLS <= names)

    def test_browser_type_audit_redacts_text(self):
        captured = {}
        def fake_audit(tool, host, args, ok, summary):
            captured.update(args)
            return "audit-id"
        with mock.patch.object(m, "BASE_AUDIT", fake_audit):
            aid = m.audit("browser_type", None, {"text": "top-secret-value", "press_enter": True}, True, "ok")
        self.assertEqual("audit-id", aid)
        self.assertNotIn("text", captured)
        self.assertEqual(len("top-secret-value"), captured["text_length"])
        self.assertIn("text_sha256", captured)

    def test_url_audit_strips_query_fragment_and_credentials_are_rejected(self):
        self.assertEqual("https://chatgpt.com/account", m.safe_url("https://chatgpt.com/account?token=abc#secret"))
        with self.assertRaises(ValueError):
            m.validate_url("https://user:pass@example.com/")
        with self.assertRaises(ValueError):
            m.validate_url("file:///tmp/a")

    def test_click_must_remain_inside_active_browser_window(self):
        with mock.patch.object(m, "active_browser_window", return_value="123"),              mock.patch.object(m, "focus"),              mock.patch.object(m, "parse_geometry", return_value={"X": 100, "Y": 100, "WIDTH": 500, "HEIGHT": 400}),              mock.patch.object(m, "run_gui"):
            with self.assertRaisesRegex(ValueError, "outside_active_window"):
                m.browser_click({"x": 50, "y": 50})
            result = m.browser_click({"x": 150, "y": 150})
            self.assertTrue(result["ok"])

    def test_screenshot_uses_active_window_capture(self):
        src = (ROOT / "remote-control-browser-mcp" / "server.py").read_text(encoding="utf-8")
        self.assertIn('["scrot", "-u", path]', src)
        self.assertIn("tempfile.mkdtemp", src)
        self.assertNotIn("tempfile.mkstemp", src)

    def test_source_has_no_cdp_devtools_or_profile_cookie_automation(self):
        src = (ROOT / "remote-control-browser-mcp" / "server.py").read_text(encoding="utf-8").lower()
        forbidden_runtime_tokens = (
            "remote-debugging-port",
            "devtoolsactiveport",
            "/json/version",
            "/json/list",
            "cookies sqlite",
        )
        for token in forbidden_runtime_tokens:
            self.assertNotIn(token, src)

    def test_unit_is_loopback_and_separate_port(self):
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-browser-mcp.service").read_text(encoding="utf-8")
        self.assertIn("SHOPVIVALIZ_REMOTE_MCP_HOST=127.0.0.1", unit)
        self.assertIn("SHOPVIVALIZ_REMOTE_MCP_PORT=5581", unit)
        self.assertIn("SHOPVIVALIZ_REMOTE_MCP_PORT=5580", (ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-mcp.service").read_text(encoding="utf-8"))

    def test_stdio_bridge_prefers_browser_mcp_and_keeps_base_fallback(self):
        bridge = (ROOT / "scripts" / "claude-remote-control-mcp-stdio.py").read_text(encoding="utf-8")
        browser_url = "http://127.0.0.1:5581/mcp"
        base_url = "http://127.0.0.1:5580/mcp"
        self.assertIn(browser_url, bridge)
        self.assertIn(base_url, bridge)
        self.assertLess(bridge.index(browser_url), bridge.index(base_url))
        self.assertIn("MCP_URLS", bridge)

    def test_browser_mcp_targets_canonical_chatgpt_xvfb_display(self):
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-browser-mcp.service").read_text(encoding="utf-8")
        self.assertIn("Environment=SHOPVIVALIZ_BROWSER_MCP_GUI_USER=fredrdp", unit)
        self.assertIn("Environment=SHOPVIVALIZ_BROWSER_MCP_DISPLAY=:99", unit)
        self.assertNotIn("Environment=SHOPVIVALIZ_BROWSER_MCP_DISPLAY=:0", unit)

    def test_setup_validates_health_identity(self):
        setup = (ROOT / "scripts" / "setup-remote-control-browser-mcp.sh").read_text(encoding="utf-8")
        self.assertIn("shopvivaliz-remote-control-browser-mcp", setup)
        self.assertIn("REMOTE_CONTROL_BROWSER_MCP_HEALTH=PASS", setup)
        self.assertNotIn("|| true", setup)
        self.assertNotIn("set +e", setup)


if __name__ == "__main__":
    unittest.main()
