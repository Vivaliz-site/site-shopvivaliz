#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import os
from pathlib import Path
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
os.environ["SHOPVIVALIZ_BROWSER_MCP_BASE_SERVER"] = str(ROOT / "remote-control-mcp" / "server.py")
os.environ["SHOPVIVALIZ_REMOTE_MCP_STATE"] = "/tmp/shopvivaliz-browser-mcp-tests-native-hosts"
os.environ["SHOPVIVALIZ_REMOTE_MCP_PORT"] = "0"

SPEC = importlib.util.spec_from_file_location("browser_mcp_native_hosts", ROOT / "remote-control-browser-mcp" / "server.py")
assert SPEC and SPEC.loader
m = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)

KOCEPSV_BRIDGE = r"C:\ProgramData\ShopVivaliz\shopvivaliz-native-desktop-bridge.ps1"
KOCEPSV_CFG = {"platform": "windows", "transport": "reverse_ssh", "address": "127.0.0.1", "port": 2223, "user": "user"}


class NativeDesktopBridgeHostsTests(unittest.TestCase):
    def test_fredwin_is_always_a_native_host(self):
        self.assertIn("Fred-Win", m.NATIVE_DESKTOP_BRIDGES)

    def test_kocepsv_uses_the_native_worker_when_its_script_is_configured(self):
        completed = mock.Mock(returncode=0, stdout='{"ok":true,"action":"click"}', stderr="")
        with (
            mock.patch.dict(m.NATIVE_DESKTOP_BRIDGES, {"KOCEPSV": KOCEPSV_BRIDGE}),
            mock.patch.object(m.base, "validate_desktop_host", return_value=KOCEPSV_CFG),
            mock.patch.object(m.base, "ssh_base", return_value=["ssh", "user@127.0.0.1"]),
            mock.patch.object(m.subprocess, "run", return_value=completed) as run,
        ):
            result = m.desktop_click({"host": "KOCEPSV", "x": 10, "y": 20})
        self.assertEqual("windows_interactive", result["surface"])
        self.assertIn(KOCEPSV_BRIDGE, run.call_args.args[0])

    def test_kocepsv_keeps_the_rustdesk_route_without_a_configured_script(self):
        saved = m.NATIVE_DESKTOP_BRIDGES.pop("KOCEPSV", None)
        try:
            with self.assertRaises(ValueError):
                m.native_desktop_bridge("KOCEPSV", {"action": "health"})
        finally:
            if saved is not None:
                m.NATIVE_DESKTOP_BRIDGES["KOCEPSV"] = saved

    def test_hosts_without_a_script_are_rejected(self):
        with self.assertRaises(ValueError):
            m.native_desktop_bridge("always-free-arm-1787907847-26", {"action": "health"})


if __name__ == "__main__":
    unittest.main()
