#!/usr/bin/env python3
"""Offline checks for the operator-only Marina MCP service stager."""
from __future__ import annotations

import os
import subprocess
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "operator_stage_service.sh"


class StagingTests(unittest.TestCase):
    def test_bash_parses(self):
        self.assertEqual(subprocess.run(["bash", "-n", str(SCRIPT)], check=False).returncode, 0)

    def test_unknown_host_rejected_without_mutation(self):
        with tempfile.TemporaryDirectory() as tmp:
            fake_hostname = Path(tmp) / "hostname"
            fake_hostname.write_text("#!/bin/sh\necho not-marina\n")
            fake_hostname.chmod(0o755)
            env = dict(os.environ, PATH=tmp + os.pathsep + os.environ["PATH"])
            result = subprocess.run(["bash", str(SCRIPT), "--stage"], env=env,
                                    text=True, capture_output=True, timeout=8, check=False)
        self.assertEqual(result.returncode, 3)
        self.assertIn("host_identity_mismatch", result.stderr)

    def test_inspect_does_not_provision_or_claim_ready(self):
        with tempfile.TemporaryDirectory() as tmp:
            fake_hostname = Path(tmp) / "hostname"
            fake_hostname.write_text("#!/bin/sh\necho marina-a1-4c24g\n")
            fake_hostname.chmod(0o755)
            env = dict(os.environ, PATH=tmp + os.pathsep + os.environ["PATH"])
            result = subprocess.run(["bash", str(SCRIPT), "--inspect"], env=env,
                                    text=True, capture_output=True, timeout=8, check=False)
        self.assertEqual(result.returncode, 0)
        self.assertIn("MARINA_MCP_STAGE=INSPECT_ONLY", result.stdout)
        self.assertIn("MIGRATION_STATUS=NO_CUT_NAO_APTO", result.stdout)

    def test_required_operator_gates_and_no_autostart(self):
        source = SCRIPT.read_text()
        for required in (
            '"$EUID" -eq 0', 'NoNewPrivs:', 'SHOPVIVALIZ_MARINA_APPROVED_SHA',
            'source_not_root_owned', 'unapproved_checkout', 'unclean_checkout',
            'unit_identity_invalid', 'unit_privilege_invalid',
            'active_unit_mutation_denied', 'concurrent_operator_stage',
            'different_release_active', 'NoNewPrivileges=yes',
            'MIGRATION_STATUS=NO_CUT_NAO_APTO',
        ):
            self.assertIn(required, source)
        for forbidden in ('systemctl enable', 'systemctl start', 'systemctl restart',
                          'systemctl reboot', 'cloudflared tunnel', 'curl -H'):
            self.assertNotIn(forbidden, source)


if __name__ == '__main__':
    unittest.main()
