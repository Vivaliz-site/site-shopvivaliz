"""Tests for the read-only Cloudflare admin tunnel host preflight."""
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "ops" / "cloudflare-admin-preflight.sh"


class TestCloudflareAdminPreflight(unittest.TestCase):
    def run_for(self, hostname):
        with tempfile.TemporaryDirectory() as tmp:
            fake_hostname = Path(tmp) / "hostname"
            fake_hostname.write_text("#!/bin/sh\nprintf '" + hostname + "\\n'\n")
            fake_hostname.chmod(0o755)
            env = {**os.environ, "PATH": tmp + ":" + os.environ.get("PATH", "")}
            return subprocess.run(
                ["bash", str(SCRIPT)], env=env, text=True, capture_output=True, timeout=5
            )

    def test_unknown_host_denied_without_credentials(self):
        p = self.run_for("unrelated-host")
        self.assertEqual(p.returncode, 3)
        self.assertIn("PREFLIGHT_STATUS=UNKNOWN_HOST", p.stdout)
        self.assertNotIn("TUNNEL_TOKEN_METADATA=", p.stdout)

    def test_backend_is_not_claimed_authenticated(self):
        p = self.run_for("always-free-arm-1787907847-26")
        self.assertEqual(p.returncode, 0)
        self.assertIn("ROLE=backend", p.stdout)
        self.assertIn("CLOUDFLARE_ACCOUNT_AUTH=NOT_TESTED", p.stdout)
        self.assertIn("ACCESS_POLICY=NOT_TESTED", p.stdout)
        self.assertIn("TUNNEL_CONNECTIVITY=NOT_TESTED", p.stdout)

    def test_marina_cannot_claim_sudo_approval(self):
        p = self.run_for("marina-a1-4c24g")
        self.assertEqual(p.returncode, 0)
        self.assertIn("ROLE=marina", p.stdout)
        self.assertIn("SUDO_PERMISSION=NOT_TESTED", p.stdout)

    def test_origin_does_not_boot_or_claim_apt(self):
        p = self.run_for("shopvivaliz-free-a1")
        self.assertEqual(p.returncode, 0)
        self.assertIn("ROLE=origin", p.stdout)
        self.assertNotIn("APTO", p.stdout)


if __name__ == "__main__":
    unittest.main()
