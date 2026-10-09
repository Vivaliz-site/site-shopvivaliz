#!/usr/bin/env python3
"""Static, secret-safe regression tests for reboot recovery of browser displays."""
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]


class DisplayRecoveryTests(unittest.TestCase):
    def test_xvfb_has_private_display_cookie_and_no_public_tcp(self):
        unit = (ROOT / "ops/systemd/shopvivaliz-xvfb99.service").read_text()
        self.assertIn("User=fredrdp", unit)
        self.assertIn("NoNewPrivileges=true", unit)
        self.assertIn("PrivateTmp=false", unit)
        self.assertIn("ExecStartPre=+/usr/bin/install -d -m 1777 /tmp/.X11-unix", unit)
        self.assertIn("ExecStartPost=/usr/local/libexec/shopvivaliz-xvfb99-prepare.sh wait", unit)
        self.assertIn("-nolisten tcp -auth /home/fredrdp/.Xauthority", unit)
        self.assertNotIn(" -ac", unit)
        self.assertNotIn("DISPLAY=:0", unit)

    def test_cookie_helper_never_logs_or_passes_auth_cookie_on_command_line(self):
        script = ROOT / "scripts/shopvivaliz-xvfb99-prepare.sh"
        body = script.read_text()
        self.assertIn("umask 077", body)
        self.assertIn('cookie=$(/usr/bin/mcookie)', body)
        self.assertIn('| /usr/bin/xauth -f "$auth" -', body)
        self.assertIn("unset cookie", body)
        self.assertIn('/bin/chmod 0600 "$auth"', body)
        self.assertNotIn("xhost +", body)
        self.assertEqual(0, subprocess.run(["sh", "-n", str(script)], check=False).returncode)

    def test_installer_wires_exact_display_principals_without_restarting_profiles(self):
        script = ROOT / "scripts/setup-shopvivaliz-display-recovery.sh"
        body = script.read_text()
        self.assertIn("shopvivaliz-xvfb99.service", body)
        for name in ("shopvivaliz-dev-browser", "shopvivaliz-atendimento-browser",
                     "shopvivaliz-chatgpt-browser", "shopvivaliz-authenticated-browser-wm"):
            self.assertIn(name, body)
        self.assertIn("Requires=shopvivaliz-xvfb99.service", body)
        self.assertIn("After=shopvivaliz-xvfb99.service", body)
        self.assertIn("shopvivaliz-atendimento-mcp-browser.service.d", body)
        self.assertIn("/usr/bin/xhost +SI:localuser:fredrdp", body)
        self.assertIn("XAUTHORITY=/home/fredconsole/.Xauthority", body)
        self.assertNotIn("cp /home/fredconsole/.Xauthority", body)
        self.assertNotIn("systemctl restart shopvivaliz-", body)
        self.assertNotIn("rm -rf", body)
        self.assertEqual(0, subprocess.run(["bash", "-n", str(script)], check=False).returncode)

    def test_browser_bridge_installer_invokes_display_bootstrap_first(self):
        body = (ROOT / "scripts/setup-remote-control-browser-mcp.sh").read_text()
        self.assertIn("bash scripts/setup-shopvivaliz-display-recovery.sh", body)
        self.assertLess(
            body.index("bash scripts/setup-shopvivaliz-display-recovery.sh"),
            body.index('install -d -m 0755 "$INSTALL_DIR"'),
        )
        self.assertEqual(0, subprocess.run(
            ["bash", "-n", str(ROOT / "scripts/setup-remote-control-browser-mcp.sh")],
            check=False,
        ).returncode)


if __name__ == "__main__":
    unittest.main()
