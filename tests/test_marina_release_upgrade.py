"""Fail-closed immutable Marina MCP upgrade runner (no root or site deploy in CI)."""
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UPGRADE = ROOT / "marina-direct-mcp" / "operator_upgrade_release.sh"
UNIT = ROOT / "marina-direct-mcp" / "systemd" / "shopvivaliz-marina-mcp.service"
SERVER = ROOT / "marina-direct-mcp" / "server.py"


class UpgradeOperatorSafety(unittest.TestCase):
    def test_bash_parses_and_refuses_wrong_host(self):
        self.assertEqual(subprocess.run(["bash", "-n", str(UPGRADE)], capture_output=True).returncode, 0)
        # On CI the host is not Marina; this must refuse before any mutation.
        import socket
        if socket.gethostname().split(".", 1)[0] == "marina-a1-4c24g":
            self.skipTest("wrong-host rejection requires test runner other than Marina")
        result = subprocess.run(["bash", str(UPGRADE), "--activate"],
                                capture_output=True, text=True, timeout=5)
        self.assertEqual(result.returncode, 3)
        self.assertIn("wrong_host", result.stderr)

    def test_pinned_sha_and_cas_active_release(self):
        s = UPGRADE.read_text()
        for marker in ("SHOPVIVALIZ_MARINA_APPROVED_SHA", "SHOPVIVALIZ_MARINA_EXPECTED_CURRENT_SHA",
                       "unapproved_checkout", "stale_active_release", "dirty_checkout",
                       "concurrent_upgrade", "safe_git", "source_file_unsafe",
                       "existing_file_mismatch", "rollback", "ROLLED_BACK_AFTER_FAILURE",
                       "owner_grant_preflight_denied", "systemctl daemon-reload",
                       "local_auth_smoke.py", "CURRENT_UNCHANGED=true"):
            self.assertIn(marker, s, marker)
        for forbidden in ("git reset --hard", "chmod 777", "chown -R",
                          "/home/ubuntu/shopvivaliz-deploy/current", "systemctl restart apache",
                          "systemctl restart mysql", "systemctl restart docker"):
            self.assertNotIn(forbidden, s)

    def test_exact_nonroot_minimal_write_unit(self):
        service = UNIT.read_text()
        self.assertIn("User=shopvivaliz-marina-mcp", service)
        self.assertIn("NoNewPrivileges=yes", service)
        self.assertIn("ProtectSystem=strict", service)
        self.assertIn("CapabilityBoundingSet=\n", service)
        self.assertIn("ReadWritePaths=/var/lib/shopvivaliz-marina-mcp/workspace /var/lib/shopvivaliz-marina-mcp/state", service)
        self.assertIn("--enable-approved-scoped-write", service)
        self.assertNotIn("User=root", service)
        self.assertNotIn("ReadWritePaths=/", service)
        source = SERVER.read_text()
        self.assertIn("--enable-approved-scoped-write", source)
        self.assertIn('owner_approved_scoped_write_grant_unavailable', source)
        self.assertIn("write_operator=write_operator", source)

    def test_release_copies_all_protected_modules(self):
        s = UPGRADE.read_text()
        for basename in ("scoped_write.py", "trusted_write_operator.py",
                         "operator_admin_route.py", "server.py", "read_only_telemetry.py",
                         "validate_owner_pr_grant.py", "local_auth_smoke.py"):
            self.assertIn(basename, s)
        self.assertIn("grep -Fqx 'NoNewPrivileges=yes'", s)


if __name__ == "__main__":
    unittest.main()
