"""Regression tests for the existing MEI daily sync monitor's execution route."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/monitor-mei-daily-base-sync.yml"


class MeiMonitorBackendRunnerTests(unittest.TestCase):
    def test_uses_provisioned_backend_runner_and_real_timer(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]", workflow)
        self.assertIn("systemctl is-active mei-mg-email-base-sync.timer", workflow)
        self.assertIn("systemctl is-enabled mei-mg-email-base-sync.timer", workflow)
        self.assertIn("HEALTH_FAIL=sincronizacao_nao_executou_nas_ultimas_26h", workflow)
        self.assertIn("HEALTH_FAIL=fonte_mais_antiga_que_48h", workflow)
        self.assertNotIn("print(json.dumps(row", workflow)

    def test_no_cross_tenancy_ssh_or_private_key_in_monitor(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        for forbidden in (
            "ubuntu@10.0.1.38",
            "StrictHostKeyChecking=no",
            "SHOPVIVALIZ_VM_SSH_KEY",
            "mei-mg-base-sync.timer",
            "~/.ssh/vmkey",
        ):
            self.assertNotIn(forbidden, workflow, forbidden)


if __name__ == "__main__":
    unittest.main()
