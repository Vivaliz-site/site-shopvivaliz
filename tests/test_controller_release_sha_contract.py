#!/usr/bin/env python3
"""Regression tests for immutable continuity-controller release provenance."""

import os
import re
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INSTALLER = ROOT / "scripts" / "install-gemini-24x7-controller.sh"
CONTROL_PLANE = ROOT / "remote-control-mcp" / "server.py"


class ControllerReleaseShaContractTests(unittest.TestCase):
    def test_installer_rejects_unverifiable_release_names_before_mutation(self) -> None:
        source = INSTALLER.read_text(encoding="utf-8")
        guard = 'if ! [[ "$release_id" =~ ^[0-9a-f]{40}$ ]]; then'
        self.assertIn(guard, source)
        self.assertNotIn("^[A-Za-z0-9._-]{7,160}$", source)
        self.assertLess(
            source.index(guard),
            source.index('sudo install -d -o ubuntu -g ubuntu -m 0700 "$runtime_dir"'),
            "Invalid releases must be rejected before any privileged mutations",
        )

    def test_installer_sha_rule_matches_controller_status_and_real_bash(self) -> None:
        installer = INSTALLER.read_text(encoding="utf-8")
        status_source = CONTROL_PLANE.read_text(encoding="utf-8")
        match = re.search(
            r'if ! \[\[ "\$release_id" =~ (\^\[0-9a-f\]\{40\}\$) \]\]; then',
            installer,
        )
        self.assertIsNotNone(match, "Installer must use an exact 40-hex SHA guard")
        self.assertIn('r"/releases/([0-9a-f]{40})/"', status_source)
        pattern = match.group(1)
        samples = [
            ("0123456789abcdef0123456789abcdef01234567", True),
            ("free-provider-v2-8b9c77d333ed8b", False),
            ("a" * 39, False),
            ("G" * 40, False),
            ("../unsafe", False),
        ]
        for candidate, accepted in samples:
            with self.subTest(candidate=candidate):
                result = subprocess.run(
                    ["bash", "-c", f'[[ "$RELEASE_ID" =~ {pattern} ]]'],
                    env={**os.environ, "RELEASE_ID": candidate},
                    check=False,
                    capture_output=True,
                    text=True,
                    timeout=5,
                )
                self.assertEqual(result.returncode == 0, accepted)


if __name__ == "__main__":
    unittest.main()
