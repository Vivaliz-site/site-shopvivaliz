"""Contract for non-disruptive stale reverse-SSH session expiry on Oracle."""
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
INSTALLER = ROOT / "scripts/install-oracle-reverse-ssh-keepalive.sh"
CONFIG = ROOT / "deploy/ssh/05-shopvivaliz-reverse-ssh-keepalive.conf"

class ReverseSSHKeepaliveTests(unittest.TestCase):
    def test_config_uses_bounded_authenticated_ssh_keepalives(self):
        data = CONFIG.read_text(encoding="utf-8")
        directives = [
            row.strip() for row in data.splitlines()
            if row.strip() and not row.lstrip().startswith("#")
        ]
        self.assertEqual(directives, [
            "ClientAliveInterval 30",
            "ClientAliveCountMax 3",
        ])

    def test_installer_validates_then_reloads_instead_of_restart(self):
        script = INSTALLER.read_text(encoding="utf-8")
        result = subprocess.run(["bash", "-n", str(INSTALLER)],
                                text=True, capture_output=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("sshd -t", script)
        self.assertIn("sshd -T", script)
        self.assertIn("systemctl reload ssh.service", script)
        self.assertNotIn("systemctl restart ssh.service", script)
        self.assertIn("rollback", script)
        self.assertIn("trap", script)
        self.assertIn("sshd_config.d", script)
        self.assertIn("always-free-arm-1787907847-26", script)

    def test_installer_is_guarded_from_public_side_effects(self):
        script = INSTALLER.read_text(encoding="utf-8")
        self.assertNotIn("PermitRootLogin yes", script)
        self.assertNotIn("PasswordAuthentication yes", script)
        self.assertNotIn("rm -rf", script)
        self.assertNotIn("systemctl restart tailscaled", script)
        self.assertNotIn("systemctl restart shopvivaliz", script)

if __name__ == "__main__":
    unittest.main()
