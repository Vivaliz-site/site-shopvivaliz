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

    def test_all_base_tools_and_seven_browser_tools_are_exposed(self):
        names = {item["name"] for item in m.tool_specs()}
        base_names = {item["name"] for item in m.base.tool_specs()}
        self.assertTrue(base_names <= names)
        self.assertEqual(
            {"browser_health", "browser_gui_tabs", "browser_open", "browser_gui_navigate", "browser_screenshot", "browser_gui_click", "browser_gui_type"},
            m.BROWSER_TOOLS,
        )
        self.assertTrue(m.BROWSER_TOOLS <= names)

    def test_atendimento_browser_tools_are_explicit_and_preserve_base_contracts(self):
        specs = {item["name"]: item for item in m.tool_specs()}
        base_specs = {item["name"]: item for item in m.base.tool_specs()}
        expected = {
            "browser_atendimento_tabs": "browser_tabs",
            "browser_atendimento_controls": "browser_controls",
            "browser_atendimento_navigate": "browser_navigate",
            "browser_atendimento_click": "browser_click",
            "browser_atendimento_click_control": "browser_click_control",
            "browser_atendimento_type": "browser_type",
        }
        self.assertEqual(expected, m.ATTENDIMENTO_TOOL_MAP)
        for public_name, base_name in expected.items():
            self.assertIn(public_name, specs)
            self.assertEqual(specs[public_name]["inputSchema"], base_specs[base_name]["inputSchema"])

    def test_atendimento_tools_route_only_to_canonical_base_browser(self):
        args = {"tab_id": "tab-one", "selector": "input[name=Company]", "text": "sample"}
        with mock.patch.object(m, "BASE_EXECUTE_TOOL", return_value={"route": "base"}) as base:
            result = m.execute_tool("browser_atendimento_type", args)
        self.assertEqual({"route": "base"}, result)
        base.assert_called_once_with("browser_type", args, cancel_check=None)

    def test_browser_health_is_read_only(self):
        specs = {item["name"]: item for item in m.tool_specs()}
        self.assertTrue(specs["browser_health"]["annotations"]["readOnlyHint"])
        self.assertFalse(specs["browser_health"]["annotations"]["destructiveHint"])

    def test_browser_type_audit_redacts_text(self):
        captured = {}
        def fake_audit(tool, host, args, ok, summary):
            captured.update(args)
            return "audit-id"
        with mock.patch.object(m, "BASE_AUDIT", fake_audit):
            aid = m.audit("browser_gui_type", None, {"text": "top-secret-value", "press_enter": True}, True, "ok")
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

    def test_tool_specs_remain_unique_when_base_exposes_browser_actions(self):
        names = [spec["name"] for spec in m.tool_specs()]
        self.assertEqual(len(names), len(set(names)))
        for name in ("browser_tabs", "browser_navigate", "browser_click", "browser_type"):
            self.assertEqual(names.count(name), 1)

        specs = {spec["name"]: spec for spec in m.tool_specs()}
        base_specs = {spec["name"]: spec for spec in m.base.tool_specs()}
        for name in ("browser_tabs", "browser_navigate", "browser_click", "browser_type"):
            self.assertEqual(specs[name]["inputSchema"], base_specs[name]["inputSchema"])

    def test_public_gui_aliases_route_by_argument_shape(self):
        with (
            mock.patch.object(m, "browser_navigate", return_value={"route": "gui-navigate"}) as navigate,
            mock.patch.object(m, "browser_click", return_value={"route": "gui-click"}) as click,
            mock.patch.object(m, "browser_type", return_value={"route": "gui-type"}) as type_,
            mock.patch.object(m, "BASE_EXECUTE_TOOL", return_value={"route": "base"}) as base,
        ):
            self.assertEqual(
                {"route": "base"},
                m.execute_tool("browser_navigate", {"url": "https://chatgpt.com/"}),
            )
            self.assertEqual(
                {"route": "gui-click"},
                m.execute_tool("browser_click", {"x": 10, "y": 20}),
            )
            self.assertEqual(
                {"route": "base"},
                m.execute_tool("browser_type", {"text": "123456", "press_enter": False}),
            )
            self.assertEqual(
                {"route": "base"},
                m.execute_tool("browser_type", {"tab_id": "abc", "selector": "#code", "text": "123456"}),
            )

        navigate.assert_not_called()
        click.assert_called_once()
        type_.assert_not_called()
        self.assertEqual(3, base.call_count)

    def test_public_browser_type_prefers_canonical_focused_cdp_before_gui(self):
        args = {"text": "123456", "press_enter": False}
        with (
            mock.patch.object(m, "BASE_EXECUTE_TOOL", return_value={"route": "base"}) as base,
            mock.patch.object(m, "browser_type", return_value={"route": "gui-type"}) as gui_type,
        ):
            result = m.execute_tool("browser_type", args)
        self.assertEqual({"route": "base"}, result)
        base.assert_called_once_with("browser_type", args, cancel_check=None)
        gui_type.assert_not_called()

    def test_public_browser_type_alias_falls_back_to_gui_only_when_canonical_focus_is_unavailable(self):
        for error in ("focused_editable_not_found", "focused_editable_ambiguous"):
            with (
                self.subTest(error=error),
                mock.patch.object(m, "BASE_EXECUTE_TOOL", return_value={"ok": False, "stderr": error}) as base,
                mock.patch.object(m, "browser_type", return_value={"route": "gui-type"}) as gui,
            ):
                result = m.execute_tool("browser_type", {"text": "sample", "press_enter": False})
                self.assertEqual({"route": "gui-type"}, result)
                base.assert_called_once()
                gui.assert_called_once()

    def test_public_browser_type_alias_does_not_hide_other_canonical_failures(self):
        failure = {"ok": False, "stderr": "tab_origin_not_allowlisted"}
        with (
            mock.patch.object(m, "BASE_EXECUTE_TOOL", return_value=failure) as base,
            mock.patch.object(m, "browser_type") as gui,
        ):
            result = m.execute_tool("browser_type", {"text": "sample", "press_enter": False})
        self.assertEqual(failure, result)
        base.assert_called_once()
        gui.assert_not_called()

    def test_canonical_focused_type_allows_loopback_helpers_but_not_lan_hosts(self):
        src = m.base.BROWSER_FOCUSED_TYPE_NODE_SCRIPT
        self.assertIn("127.0.0.1", src)
        self.assertIn("localhost", src)
        self.assertNotIn("192.168.", src)

    def test_public_browser_type_alias_audit_redacts_text(self):
        captured = {}
        def fake_audit(tool, host, args, ok, summary):
            captured.update(args)
            return "audit-id"
        with mock.patch.object(m, "BASE_AUDIT", fake_audit):
            aid = m.audit("browser_type", None, {"text": "458170", "press_enter": False}, True, "ok")
        self.assertEqual("audit-id", aid)
        self.assertNotIn("text", captured)
        self.assertEqual(6, captured["text_length"])
        self.assertIn("text_sha256", captured)

    def test_gui_browser_actions_are_explicitly_namespaced(self):
        specs = {spec["name"]: spec for spec in m.tool_specs()}
        for name in ("browser_gui_tabs", "browser_gui_navigate", "browser_gui_click", "browser_gui_type"):
            self.assertIn(name, specs)
        self.assertIn("tab_id", specs["browser_type"]["inputSchema"]["properties"])
        self.assertNotIn("tab_id", specs["browser_gui_type"]["inputSchema"]["properties"])

    def test_desktop_window_resolution_is_target_bound_and_ambiguous_fails_closed(self):
        search = mock.Mock(returncode=0, stdout="101\n202\n")
        with (
            mock.patch.object(m, "run_gui", return_value=search),
            mock.patch.object(m, "window_title", side_effect=lambda w: {"101": "DESKTOP-KOCEPSV - RustDesk", "202": "RustDesk"}[w]),
        ):
            self.assertEqual(["101"], m.rustdesk_windows("KOCEPSV", "123456789"))
        with mock.patch.object(m, "rustdesk_windows", return_value=["101", "102"]):
            with self.assertRaisesRegex(RuntimeError, "rustdesk_session_window_ambiguous"):
                m.active_desktop_window("KOCEPSV", "123456789")

    def test_desktop_click_is_relative_and_bounded_to_rustdesk_window(self):
        with (
            mock.patch.object(m.base, "rustdesk_host_id", return_value="123456789"),
            mock.patch.object(m, "active_desktop_window", return_value="123"),
            mock.patch.object(m, "focus"),
            mock.patch.object(m, "parse_geometry", return_value={"X": 100, "Y": 200, "WIDTH": 500, "HEIGHT": 400}),
            mock.patch.object(m, "run_gui") as run,
        ):
            with self.assertRaisesRegex(ValueError, "desktop_click_outside_window"):
                m.desktop_click({"host": "KOCEPSV", "x": 500, "y": 10})
            result = m.desktop_click({"host": "KOCEPSV", "x": 50, "y": 60, "button": "left", "clicks": 1})
        self.assertTrue(result["ok"])
        self.assertEqual(50, result["x"])
        self.assertEqual(60, result["y"])
        self.assertIn(mock.call(["xdotool", "mousemove", "--sync", "150", "260"]), run.call_args_list)

    def test_desktop_type_uses_stdin_clipboard_and_never_puts_text_in_argv(self):
        secret = "sample-sensitive-input"
        with (
            mock.patch.object(m.base, "rustdesk_host_id", return_value="123456789"),
            mock.patch.object(m, "active_desktop_window", return_value="123"),
            mock.patch.object(m, "focus"),
            mock.patch.object(m, "run_gui") as run,
            mock.patch.object(m, "key") as key,
        ):
            result = m.desktop_type({"host": "KOCEPSV", "text": secret, "press_enter": True})
        self.assertEqual(len(secret), result["typed_characters"])
        for call in run.call_args_list:
            argv = call.args[0]
            self.assertNotIn(secret, argv)
        self.assertIn(mock.call(["xclip", "-selection", "clipboard", "-i"], input_text=secret), run.call_args_list)
        self.assertIn(mock.call(["xclip", "-selection", "clipboard", "-i"], input_text="", check=False), run.call_args_list)
        self.assertIn(mock.call("ctrl+v"), key.call_args_list)
        self.assertIn(mock.call("Return"), key.call_args_list)

    def test_desktop_screenshot_captures_only_resolved_rustdesk_window(self):
        with (
            mock.patch.object(m.base, "rustdesk_host_id", return_value="123456789"),
            mock.patch.object(m, "active_desktop_window", return_value="321") as active,
            mock.patch.object(m, "capture_window", return_value={"ok": True, "window_id": "321", "mime_type": "image/png"}) as capture,
        ):
            result = m.desktop_screenshot({"host": "KOCEPSV"})
        self.assertTrue(result["ok"])
        active.assert_called_once_with("KOCEPSV", mock.ANY)
        capture.assert_called_once_with("321", "KOCEPSV", "shopvivaliz-desktop-")

    def test_desktop_open_uses_runtime_target_id_but_does_not_return_it(self):
        proc = mock.Mock()
        with (
            mock.patch.object(m.base, "rustdesk_host_id", return_value="123456789"),
            mock.patch.object(m, "rustdesk_windows", side_effect=[[], ["901"]]),
            mock.patch.object(m.subprocess, "Popen", return_value=proc) as popen,
            mock.patch.object(m, "focus"),
            mock.patch.object(m.time, "sleep"),
        ):
            result = m.desktop_open({"host": "KOCEPSV"})
        self.assertTrue(result["ok"])
        self.assertEqual("901", result["window_id"])
        self.assertNotIn("123456789", repr(result))
        argv = popen.call_args.args[0]
        self.assertEqual("--connect", argv[-2])
        self.assertEqual("123456789", argv[-1])

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

    def test_active_browser_window_uses_focus_fallback_without_ewmh(self):
        with (
            mock.patch.object(m, "browser_windows", return_value=["123"]),
            mock.patch.object(m, "run_gui", return_value=mock.Mock(returncode=0, stdout="999\n")) as run,
            mock.patch.object(m, "focus") as focus,
        ):
            self.assertEqual("123", m.active_browser_window())
        run.assert_called_once_with(["xdotool", "getactivewindow"], check=False)
        focus.assert_called_once_with("123")

    def test_focus_falls_back_to_x11_windowfocus_without_ewmh(self):
        activate_failed = mock.Mock(returncode=1)
        focused = mock.Mock(returncode=0)
        with mock.patch.object(m, "run_gui", side_effect=[activate_failed, focused]) as run:
            m.focus("123")
        self.assertEqual(
            [
                mock.call(["xdotool", "windowactivate", "--sync", "123"], check=False),
                mock.call(["xdotool", "windowfocus", "--sync", "123"]),
            ],
            run.call_args_list,
        )

    def test_browser_mcp_is_isolated_from_chatgpt_continuity_session(self):
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-browser-mcp.service").read_text(encoding="utf-8")
        self.assertIn("Environment=SHOPVIVALIZ_BROWSER_MCP_GUI_USER=fredconsole", unit)
        self.assertIn("Environment=SHOPVIVALIZ_BROWSER_MCP_DISPLAY=:0", unit)
        self.assertIn("Environment=SHOPVIVALIZ_BROWSER_MCP_BROWSER_BINARY=/opt/shopvivaliz-browser/chrome-linux/chrome", unit)
        self.assertIn("Environment=SHOPVIVALIZ_BROWSER_MCP_PROFILE_DIR=/home/fredconsole/.config/shopvivaliz-general-chromium", unit)
        self.assertIn("Environment=SHOPVIVALIZ_BROWSER_MCP_WINDOW_CLASS=shopvivaliz-general", unit)
        self.assertNotIn("Environment=SHOPVIVALIZ_BROWSER_MCP_GUI_USER=fredrdp", unit)
        self.assertNotIn("Environment=SHOPVIVALIZ_BROWSER_MCP_DISPLAY=:99", unit)

    def test_desktop_runtime_config_uses_separate_protected_env_file(self):
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-browser-mcp.service").read_text(encoding="utf-8")
        setup = (ROOT / "scripts" / "setup-remote-control-browser-mcp.sh").read_text(encoding="utf-8")
        self.assertIn("EnvironmentFile=-/var/lib/shopvivaliz-remote-control/desktop.env", unit)
        self.assertIn("SHOPVIVALIZ_DESKTOP_RUSTDESK_BINARY=/usr/bin/rustdesk", unit)
        self.assertIn('DESKTOP_ENV="/var/lib/shopvivaliz-remote-control/desktop.env"', setup)
        self.assertIn('command -v rustdesk >/dev/null 2>&1', setup)
        self.assertIn('stat -c %a "$DESKTOP_ENV"', setup)
        self.assertIn('stat -c %U:%G "$DESKTOP_ENV"', setup)
        self.assertIn("grep -q '^SHOPVIVALIZ_RUSTDESK_HOST_IDS=' \"$DESKTOP_ENV\"", setup)
        self.assertNotIn('cat "$DESKTOP_ENV"', setup)
        self.assertNotRegex(unit, r"SHOPVIVALIZ_RUSTDESK_HOST_IDS=.*[0-9]{6}")

    def test_desktop_contract_is_documented_without_hardcoded_target_ids(self):
        browser_spec = (ROOT / "remote-control-browser-mcp" / "SPEC.md").read_text(encoding="utf-8")
        base_spec = (ROOT / "remote-control-mcp" / "SPEC.md").read_text(encoding="utf-8")
        host_access = (ROOT / "docs" / "knowledge" / "host-access.md").read_text(encoding="utf-8")
        for token in ("desktop_health", "desktop_open", "desktop_screenshot", "desktop_click", "desktop_type"):
            self.assertIn(token, browser_spec)
        self.assertIn("desktop.env", browser_spec)
        self.assertIn("desktop.env", base_spec)
        self.assertIn("desktop_*", host_access)
        self.assertIn("fredconsole", browser_spec)
        self.assertNotIn("A automação usa somente a sessão gráfica X11 do usuário `fredrdp`", browser_spec)

    def test_browser_mcp_execstart_overrides_shared_env_for_session_isolation(self):
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-browser-mcp.service").read_text(encoding="utf-8")
        exec_line = next(line for line in unit.splitlines() if line.startswith("ExecStart="))
        self.assertIn("/usr/bin/env", exec_line)
        self.assertIn("SHOPVIVALIZ_BROWSER_MCP_GUI_USER=fredconsole", exec_line)
        self.assertIn("SHOPVIVALIZ_BROWSER_MCP_DISPLAY=:0", exec_line)
        self.assertIn("SHOPVIVALIZ_BROWSER_MCP_PROFILE_DIR=/home/fredconsole/.config/shopvivaliz-general-chromium", exec_line)
        self.assertIn("SHOPVIVALIZ_BROWSER_MCP_WINDOW_CLASS=shopvivaliz-general", exec_line)

    def test_browser_windows_only_targets_dedicated_general_browser_class(self):
        found = mock.Mock(returncode=0, stdout="123\n")
        with (
            mock.patch.object(m, "BROWSER_WINDOW_CLASS", "shopvivaliz-general", create=True),
            mock.patch.object(m, "require_binary", return_value="/usr/bin/xdotool"),
            mock.patch.object(m, "run_gui", return_value=found) as run,
        ):
            self.assertEqual(["123"], m.browser_windows())
        run.assert_called_once_with(["xdotool", "search", "--onlyvisible", "--class", "shopvivaliz-general"], check=False)

    def test_browser_health_is_ready_when_general_browser_is_launchable_without_window(self):
        with (
            mock.patch.object(m, "browser_windows", return_value=[]),
            mock.patch.object(m, "BROWSER_BINARY", "/opt/shopvivaliz-browser/chrome-linux/chrome", create=True),
            mock.patch.object(m.shutil, "which", return_value="/usr/bin/fake"),
            mock.patch.object(m.os.path, "isfile", return_value=True),
            mock.patch.object(m.os, "access", return_value=True),
            mock.patch.object(m, "run_gui", return_value=mock.Mock(returncode=0, stdout="1\n")),
        ):
            result = m.browser_health()
        self.assertTrue(result["ok"], result)
        self.assertTrue(result["browser_launchable"], result)
        self.assertEqual(0, result["window_count"])

    def test_browser_open_uses_configured_binary_when_general_session_has_no_window(self):
        launched = {}
        class FakeProcess:
            pass
        def fake_popen(argv, **kwargs):
            launched["argv"] = argv
            launched["kwargs"] = kwargs
            return FakeProcess()
        with (
            mock.patch.object(m, "browser_windows", return_value=[]),
            mock.patch.object(m, "BROWSER_BINARY", "/opt/shopvivaliz-browser/chrome-linux/chrome", create=True),
            mock.patch.object(m, "BROWSER_PROFILE_DIR", "/home/fredconsole/.config/shopvivaliz-general-chromium", create=True),
            mock.patch.object(m, "BROWSER_WINDOW_CLASS", "shopvivaliz-general", create=True),
            mock.patch.object(m.os.path, "isfile", return_value=True),
            mock.patch.object(m.os, "access", return_value=True),
            mock.patch.object(m, "gui_prefix", return_value=["gui-prefix"]),
            mock.patch.object(m.subprocess, "Popen", side_effect=fake_popen),
        ):
            result = m.browser_open({"url": "https://example.com/"})
        self.assertEqual("new_window", result["action"])
        self.assertIn("/opt/shopvivaliz-browser/chrome-linux/chrome", launched["argv"])
        self.assertIn("--new-window", launched["argv"])
        self.assertIn("--no-sandbox", launched["argv"])
        self.assertIn("--class=shopvivaliz-general", launched["argv"])
        self.assertIn("--user-data-dir=/home/fredconsole/.config/shopvivaliz-general-chromium", launched["argv"])

    def test_setup_restarts_existing_browser_service_after_install(self):
        setup = (ROOT / "scripts" / "setup-remote-control-browser-mcp.sh").read_text(encoding="utf-8")
        self.assertIn("systemctl enable shopvivaliz-remote-control-browser-mcp.service", setup)
        self.assertIn("systemctl restart shopvivaliz-remote-control-browser-mcp.service", setup)
        self.assertNotIn("systemctl enable --now shopvivaliz-remote-control-browser-mcp.service", setup)

    def test_setup_validates_health_identity(self):
        setup = (ROOT / "scripts" / "setup-remote-control-browser-mcp.sh").read_text(encoding="utf-8")
        self.assertIn("shopvivaliz-remote-control-browser-mcp", setup)
        self.assertIn("REMOTE_CONTROL_BROWSER_MCP_HEALTH=PASS", setup)
        self.assertNotIn("|| true", setup)
        self.assertNotIn("set +e", setup)


if __name__ == "__main__":
    unittest.main()
