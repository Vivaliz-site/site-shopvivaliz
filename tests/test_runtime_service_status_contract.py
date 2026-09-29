from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "runtime-service-status.sh"
WORKFLOW = ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml"


class RuntimeServiceStatusContractTests(unittest.TestCase):
    def test_checked_in_runtime_status_script_is_available_and_valid_bash(self) -> None:
        self.assertTrue(SCRIPT.is_file(), "runtime_status must use a checked-in diagnostic script")
        result = subprocess.run(
            ["bash", "-n", str(SCRIPT)],
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_remote_access_references_the_checked_in_script_for_both_linux_hosts(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertGreaterEqual(workflow.count("scripts/runtime-service-status.sh"), 2)

    def test_site_contract_matches_current_continuity_architecture(self) -> None:
        body = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("report_required_active shopvivaliz-agent.service", body)
        self.assertIn("CHATGPT_CONTINUITY_QUEUE_CERTIFIED=", body)
        self.assertIn("CHATGPT_CONTINUITY_QUEUE_ACTIONABLE_ROWS=", body)
        self.assertIn("CHATGPT_CONTINUITY_NUDGE_LEDGER_PRESENT=", body)
        self.assertIn("AGENT_LAST_AUTONOMOUS_CYCLE_ERROR_AT=", body)
        self.assertIn("AGENT_LAST_WATCHDOG_COMPLETED_AT=", body)
        self.assertIn("AGENT_LAST_CHATGPT_DISPATCHER_COMPLETED_AT=", body)
        self.assertIn("AGENT_LAST_CHATGPT_DISPATCHER_WARN_AT=", body)


if __name__ == "__main__":
    unittest.main()
