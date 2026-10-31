#!/usr/bin/env python3
"""Regression: a stale active controller must never pass Extreme Audit V5."""
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CHECK = ROOT / "scripts" / "audit-controller-runtime-parity.py"
CURRENT = ROOT / "scripts" / "agent_task_state.py"


class AuditControllerRuntimeParityTest(unittest.TestCase):
    def run_check(self, active: Path) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(CHECK), "--reference", str(CURRENT), "--active", str(active)],
            text=True, capture_output=True, check=False,
        )

    def test_current_controller_passes(self):
        result = self.run_check(CURRENT)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("AUDIT_CONTROLLER_PARITY=PASS", result.stdout)

    def test_legacy_controller_fails_closed(self):
        with tempfile.TemporaryDirectory() as temp:
            old = Path(temp) / "agent_task_state.py"
            old.write_text("def start_task():\n    pass\ndef complete_task():\n    pass\n", encoding="utf-8")
            result = self.run_check(old)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("AUDIT_CONTROLLER_PARITY=FAIL", result.stderr)

    def test_shared_continuity_gate_remains_executable(self):
        gate = ROOT / "scripts" / "agent-continuity-validate.sh"
        self.assertTrue(gate.stat().st_mode & 0o111, "continuity validator lost executable permission")

    def test_missing_active_release_fails_closed(self):
        result = self.run_check(Path("/nonexistent/audit-v5-active-controller.py"))
        self.assertNotEqual(result.returncode, 0)


if __name__ == "__main__":
    unittest.main()
