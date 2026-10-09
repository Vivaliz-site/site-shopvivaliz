"""No failed provider may manufacture an unbound durable task.

Local fixtures only; no provider, backend, browser or real runtime is touched.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "autonomous-provider-failover.sh"
STATE_CLI = ROOT / "scripts" / "agent_task_state.py"


class FiniteFailoverCheckpointGuardTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.work = Path(self.temp.name)
        # The production shell invokes scripts/agent_task_state.py relatively.
        # Point only that path at the source; runtime state/logs stay in temp.
        (self.work / "scripts").symlink_to(ROOT / "scripts", target_is_directory=True)
        self.state = self.work / "checkpoints"
        self.state.mkdir()
        self.prompt = self.work / "prompt.txt"
        self.prompt.write_text("Continue a preexisting task", encoding="utf-8")
        self.env = {
            **os.environ,
            "PYTHONDONTWRITEBYTECODE": "1",
            "SHOPVIVALIZ_TASK_ID": "orphan-proof-fixture",
            "SHOPVIVALIZ_RESUME_STAGE": "chatgpt_common",
            "SHOPVIVALIZ_RESUME_RESULT_MODE": "git_diff",
            "SHOPVIVALIZ_RESUME_BACKGROUND": "0",
            "SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF": "1",
            "SHOPVIVALIZ_AGENT_TASK_STATE_DIR": str(self.state),
            "SHOPVIVALIZ_TASK_REPOSITORY": "Vivaliz-site/site-shopvivaliz",
        }

    def run_fallback(self, **env_changes: str) -> subprocess.CompletedProcess:
        return subprocess.run(
            ["bash", str(SCRIPT), str(self.prompt)],
            cwd=self.work, env={**self.env, **env_changes},
            capture_output=True, text=True, timeout=20, check=False,
        )

    def test_default_durable_fallback_cannot_start_unbound_task(self) -> None:
        result = self.run_fallback()
        self.assertEqual(result.returncode, 76, (result.stdout, result.stderr))
        self.assertIn("durable checkpoint missing", result.stderr)
        self.assertFalse((self.state / "orphan-proof-fixture.json").exists())

    def test_background_may_not_start_unbound_with_legacy_opt_out(self) -> None:
        result = self.run_fallback(
            SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF="0",
            SHOPVIVALIZ_RESUME_BACKGROUND="1",
        )
        self.assertEqual(result.returncode, 76, (result.stdout, result.stderr))
        self.assertFalse((self.state / "orphan-proof-fixture.json").exists())

    def test_existing_bound_task_is_preserved_and_progressed(self) -> None:
        started = subprocess.run(
            [
                sys.executable, str(STATE_CLI), "start",
                "--task", "orphan-proof-fixture", "--goal", "real origin",
                "--conversation-id", "01234567-89ab-cdef-0123-456789abcdef",
                "--browser-session", "dev",
            ],
            cwd=self.work, env=self.env, capture_output=True, text=True,
            check=False, timeout=20,
        )
        self.assertEqual(started.returncode, 0, started.stderr)
        before = json.loads((self.state / "orphan-proof-fixture.json").read_text())
        result = self.run_fallback()
        self.assertEqual(result.returncode, 75, (result.stdout, result.stderr))
        after = json.loads((self.state / "orphan-proof-fixture.json").read_text())
        self.assertEqual(after["conversation_id"], before["conversation_id"])
        self.assertEqual(after["browser_session"], "dev")
        self.assertEqual(after["status"], "RUNNING")
        self.assertGreater(after["checkpoint_version"], before["checkpoint_version"])

    def test_explicit_non_durable_foreground_legacy_mode_is_unchanged(self) -> None:
        result = self.run_fallback(SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF="0")
        self.assertEqual(result.returncode, 75, (result.stdout, result.stderr))
        after = json.loads((self.state / "orphan-proof-fixture.json").read_text())
        self.assertEqual(after["status"], "RUNNING")
        self.assertNotIn("conversation_id", after)


if __name__ == "__main__":
    unittest.main()
