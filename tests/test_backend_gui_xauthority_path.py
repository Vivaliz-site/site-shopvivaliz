"""Regression: the backend GUI xauth cookie path must not depend on Path.home().

The browser MCP service runs as root. Path.home() then resolves to /root, so the
cookie lookup pointed at a file that does not exist and desktop_health reported
display_accessible=false. The cookie lives in the ubuntu user's secrets directory.
No real display or cookie is read here.
"""
from __future__ import annotations

import importlib.util
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock

if "fcntl" not in sys.modules:
    sys.modules["fcntl"] = mock.MagicMock()

ROOT = Path(__file__).resolve().parents[1]
SERVER = ROOT / "remote-control-browser-mcp" / "server.py"
UNIT = ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-browser-mcp.service"
EXPECTED_XAUTH = "/home/ubuntu/.shopvivaliz/secrets/gui-xvfb.xauth"
BACKEND = "always-free-arm-1787907847-26"


def _load_server_with_env(extra: dict[str, str], drop: tuple[str, ...]):
    state = tempfile.mkdtemp(prefix="shopvivaliz-xauth-tests-")
    env = dict(os.environ)
    env.update({
        "SHOPVIVALIZ_BROWSER_MCP_BASE_SERVER": str(ROOT / "remote-control-mcp" / "server.py"),
        "SHOPVIVALIZ_REMOTE_MCP_STATE": state,
        "SHOPVIVALIZ_REMOTE_MCP_PORT": "0",
    })
    env.update(extra)
    for key in drop:
        env.pop(key, None)
    with mock.patch.dict(os.environ, env, clear=True):
        spec = importlib.util.spec_from_file_location(
            f"browser_mcp_xauth_{abs(hash(tuple(sorted(extra.items()))))}", SERVER)
        assert spec and spec.loader
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
    return module


class BackendXauthorityPathTests(unittest.TestCase):
    def test_default_cookie_path_is_ubuntu_user_even_when_running_as_root(self):
        # Simulate the root service: HOME=/root must not leak into the cookie path.
        m = _load_server_with_env({"HOME": "/root"}, drop=("SHOPVIVALIZ_BACKEND_GUI_XAUTHORITY",))
        self.assertEqual(m.LINUX_GUI_HOSTS[BACKEND]["xauthority"], EXPECTED_XAUTH)
        self.assertFalse(m.LINUX_GUI_HOSTS[BACKEND]["xauthority"].startswith("/root/"))

    def test_env_override_wins_over_default(self):
        override = "/var/lib/example/custom.xauth"
        m = _load_server_with_env(
            {"HOME": "/root", "SHOPVIVALIZ_BACKEND_GUI_XAUTHORITY": override}, drop=())
        self.assertEqual(m.LINUX_GUI_HOSTS[BACKEND]["xauthority"], override)

    def test_service_unit_declares_the_xauthority_variable(self):
        text = UNIT.read_text(encoding="utf8")
        self.assertIn(f"Environment=SHOPVIVALIZ_BACKEND_GUI_XAUTHORITY={EXPECTED_XAUTH}", text)


if __name__ == "__main__":
    unittest.main()
