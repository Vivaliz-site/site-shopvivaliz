"""Contract tests for the isolated backend Xvfb display (no real display is touched)."""
from __future__ import annotations

import importlib.util
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
UNIT = ROOT / "deploy" / "systemd" / "shopvivaliz-gui-xvfb.service"
INSTALLER = ROOT / "scripts" / "install-backend-gui-xvfb.sh"
BACKEND = "always-free-arm-1787907847-26"
AC_FLAG = re.compile(r"(?<!\S)-ac(?!\S)")

os.environ["SHOPVIVALIZ_BROWSER_MCP_BASE_SERVER"] = str(ROOT / "remote-control-mcp" / "server.py")
os.environ["SHOPVIVALIZ_REMOTE_MCP_STATE"] = tempfile.mkdtemp(prefix="shopvivaliz-backend-gui-tests-")
os.environ["SHOPVIVALIZ_REMOTE_MCP_PORT"] = "0"

SPEC = importlib.util.spec_from_file_location("browser_mcp_backend_gui", ROOT / "remote-control-browser-mcp" / "server.py")
assert SPEC and SPEC.loader
m = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)


class SystemdUnitTests(unittest.TestCase):
    def setUp(self):
        self.text = UNIT.read_text(encoding="utf8")

    def test_display_is_dedicated_and_authenticated(self):
        self.assertIn("Xvfb :97", self.text)
        self.assertIn("-screen 0 1440x900x24", self.text)
        self.assertIn("-nolisten tcp", self.text)
        self.assertIn("-auth /home/ubuntu/.shopvivaliz/secrets/gui-xvfb.xauth", self.text)

    def test_never_runs_with_access_control_disabled(self):
        self.assertIsNone(AC_FLAG.search(self.text))

    def test_runs_as_ubuntu_without_sudo_or_other_user_session(self):
        self.assertIn("User=ubuntu", self.text)
        self.assertNotIn("fredconsole", self.text)
        self.assertNotIn("lightdm", self.text)
        self.assertNotIn("sudo", self.text)


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.text = INSTALLER.read_text(encoding="utf8")

    def test_installer_never_disables_access_control(self):
        self.assertIsNone(AC_FLAG.search(self.text))

    def test_cookie_is_created_private_and_never_printed(self):
        self.assertIn("umask 077", self.text)
        self.assertIn("chmod 600", self.text)
        self.assertIn("MIT-MAGIC-COOKIE-1", self.text)
        self.assertNotRegex(self.text, r'echo[^\n]*\$cookie')

    def test_installer_is_bash_syntax_valid(self):
        result = subprocess.run(["bash", "-n", str(INSTALLER)], capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)


class BackendGeometryAndRoutingTests(unittest.TestCase):
    def test_backend_is_a_desktop_host_with_only_isolated_display(self):
        self.assertEqual(set(m.base.DESKTOP_HOSTS), {"Fred-Win", "KOCEPSV", BACKEND})
        self.assertEqual(m.LINUX_GUI_HOSTS[BACKEND]["geometry"], (1440, 900))
        self.assertEqual(m.LINUX_GUI_HOSTS[BACKEND]["display"], ":97")

    def test_click_outside_geometry_is_rejected_before_any_subprocess(self):
        with mock.patch.object(m.subprocess, "run") as run:
            with self.assertRaisesRegex(ValueError, "desktop_click_outside_geometry"):
                m.linux_gui_click(BACKEND, 1440, 10, "left", 1)
            with self.assertRaisesRegex(ValueError, "desktop_click_outside_geometry"):
                m.linux_gui_click(BACKEND, 10, 900, "left", 1)
            run.assert_not_called()

    def test_commands_run_on_dedicated_display_without_sudo(self):
        captured = {}

        def fake_run(argv, **kwargs):
            captured["argv"] = argv
            captured["env"] = kwargs["env"]
            return subprocess.CompletedProcess(argv, 0, stdout="1440 900\n", stderr="")

        # linux_gui_health checks shutil.which directly; mock it so the test does not
        # depend on xdotool/xwd being installed on the CI runner.
        with mock.patch.object(m.shutil, "which", return_value="/usr/bin/xdotool"), \
             mock.patch.object(m, "require_binary", return_value="/usr/bin/xdotool"), \
             mock.patch.object(m.subprocess, "run", side_effect=fake_run):
            m.linux_gui_health(BACKEND)
        self.assertNotIn("sudo", captured["argv"])
        self.assertEqual(captured["env"]["DISPLAY"], ":97")
        self.assertTrue(captured["env"]["XAUTHORITY"].endswith("gui-xvfb.xauth"))

    def test_health_requires_the_expected_geometry(self):
        with mock.patch.object(m, "require_binary", return_value="/usr/bin/xdotool"), \
             mock.patch.object(m.subprocess, "run",
                               return_value=subprocess.CompletedProcess([], 0, stdout="800 600\n", stderr="")):
            result = m.linux_gui_health(BACKEND)
        self.assertFalse(result["ok"])
        self.assertFalse(result["display_accessible"])

    def test_text_never_appears_in_argv(self):
        argvs = []

        def fake_linux_run(cfg, argv, **kwargs):
            argvs.append(list(argv))
            return subprocess.CompletedProcess(argv, 0, stdout="", stderr="")

        secret_text = "senha-de-teste-123"
        with mock.patch.object(m, "linux_gui_run", side_effect=fake_linux_run):
            m.linux_gui_type(BACKEND, secret_text, press_enter=False)
        typed = [argv for argv in argvs if argv[:2] == ["xdotool", "type"]]
        self.assertEqual(len(typed), 1)
        self.assertIn("--file", typed[0])
        self.assertFalse(any(secret_text in part for argv in argvs for part in argv))

    def test_desktop_tools_route_backend_to_linux_path_not_windows(self):
        with mock.patch.object(m, "linux_gui_health", return_value={"ok": True}) as health, \
             mock.patch.object(m, "native_desktop_bridge") as bridge:
            self.assertEqual(m.desktop_health({"host": BACKEND}), {"ok": True})
            health.assert_called_once_with(BACKEND)
            bridge.assert_not_called()

    def test_click_handler_uses_linux_path_for_backend(self):
        with mock.patch.object(m, "linux_gui_click", return_value={"ok": True}) as click, \
             mock.patch.object(m, "native_desktop_bridge") as bridge:
            m.desktop_click({"host": BACKEND, "x": 10, "y": 20, "button": "left", "clicks": 1})
            click.assert_called_once_with(BACKEND, 10, 20, "left", 1)
            bridge.assert_not_called()

    def test_open_never_launches_or_restarts_the_display(self):
        with mock.patch.object(m, "linux_gui_health", return_value={"ok": False}), \
             mock.patch.object(m.subprocess, "Popen") as popen, \
             mock.patch.object(m.subprocess, "run") as run:
            with self.assertRaisesRegex(RuntimeError, "linux_gui_display_unavailable"):
                m.linux_gui_open(BACKEND)
            popen.assert_not_called()
            run.assert_not_called()


if __name__ == "__main__":
    unittest.main()
