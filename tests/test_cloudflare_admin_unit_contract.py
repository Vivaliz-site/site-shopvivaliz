"""Fail-closed contract for the standalone Cloudflare admin systemd unit."""
import re
import unittest
from pathlib import Path

UNIT_PATH = (
    Path(__file__).resolve().parents[1]
    / "ops" / "cloudflare-admin" / "shopvivaliz-cloudflared-admin.service"
)


class TestCloudflareAdminUnitContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = UNIT_PATH.read_text(encoding="utf-8")
        cls.lines = cls.text.splitlines()

    def test_requires_explicit_access_approval_and_private_token(self):
        self.assertIn(
            "ConditionPathExists=/etc/shopvivaliz/cloudflare-admin/token", self.lines
        )
        self.assertIn(
            "ConditionPathExists=/etc/shopvivaliz/cloudflare-admin/access-approved",
            self.lines,
        )

    def test_credential_is_file_based_not_exposed_as_argument(self):
        self.assertIn(
            "LoadCredential=tunnel-token:/etc/shopvivaliz/cloudflare-admin/token",
            self.lines,
        )
        cmd = next(line for line in self.lines if line.startswith("ExecStart="))
        self.assertIn("--token-file %d/tunnel-token", cmd)
        self.assertFalse(re.search(r"(?<![\w-])--token(?:\s|=)", cmd))

    def test_runs_without_root_or_escalation(self):
        for setting in [
            "DynamicUser=yes",
            "NoNewPrivileges=yes",
            "ProtectSystem=strict",
            "ProtectHome=yes",
            "PrivateDevices=yes",
            "CapabilityBoundingSet=",
            "AmbientCapabilities=",
            "UMask=0077",
        ]:
            self.assertIn(setting, self.lines)
        self.assertNotIn("User=root", self.lines)
        self.assertNotIn("ExecStartPre=/usr/bin/sudo", self.lines)

    def test_service_is_not_auto_started_by_contract(self):
        self.assertIn("WantedBy=multi-user.target", self.lines)
        self.assertIn("Restart=on-failure", self.lines)
        self.assertNotIn("ExecStartPost=/bin/systemctl enable", self.lines)


if __name__ == "__main__":
    unittest.main()
