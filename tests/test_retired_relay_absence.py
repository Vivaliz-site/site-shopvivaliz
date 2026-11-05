#!/usr/bin/env python3
"""Regression for removal of the retired-origin iPhone relay.

Historical SSH/tunnel provisioning on the decommissioned VM was removed by
PR #3690. The live MCP/control-plane test suite must not require files from
that retired topology or restore its obsolete deployment path.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class RetiredRelayAbsenceTest(unittest.TestCase):
    def test_retired_origin_iphone_relay_and_test_remain_absent(self):
        self.assertFalse((ROOT / "scripts/setup-iphone-private-ssh-relay.sh").exists())
        self.assertFalse((ROOT / "tests/iphone-private-ssh-relay-contract-test.py").exists())

    def test_active_control_plane_files_stay_present(self):
        for path in (
            "remote-control-mcp/server.py",
            "scripts/setup-remote-control-access.sh",
            "tests/remote-control-mcp-test.py",
            "scripts/validate-mcp-rdc-capability-parity.py",
            "tests/test_mcp_rdc_capability_parity.py",
        ):
            with self.subTest(path=path):
                self.assertTrue((ROOT / path).is_file())

    def test_mcp_ci_does_not_reintroduce_missing_relay_dependency(self):
        content = (ROOT / ".github/workflows/remote-control-mcp-ci.yml").read_text(encoding="utf-8")
        self.assertNotIn("setup-iphone-private-ssh-relay.sh", content)
        self.assertNotIn("iphone-private-ssh-relay-contract-test.py", content)
        self.assertIn("python3 tests/test_retired_relay_absence.py", content)
        self.assertIn("python3 tests/remote-control-mcp-test.py", content)


if __name__ == "__main__":
    unittest.main()
