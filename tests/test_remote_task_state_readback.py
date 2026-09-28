#!/usr/bin/env python3
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml"


class RemoteTaskStateReadbackTest(unittest.TestCase):
    def setUp(self) -> None:
        self.workflow = WORKFLOW.read_text(encoding="utf-8")

    def test_remote_control_exposes_readonly_task_state_show(self) -> None:
        self.assertIn("task_state_show", self.workflow)
        self.assertIn(
            'if action == "task_state_show" and target != "shopvivaliz-free-a1":',
            self.workflow,
        )
        self.assertIn('r"task=([A-Za-z0-9._-]{1,160})"', self.workflow)
        self.assertIn("task_id=", self.workflow)
        self.assertIn(
            "SHOPVIVALIZ_AGENT_TASK_STATE_DIR=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state",
            self.workflow,
        )
        self.assertIn(
            'python3 scripts/agent_task_state.py show --task "$TASK_ID"',
            self.workflow,
        )


if __name__ == "__main__":
    unittest.main()
