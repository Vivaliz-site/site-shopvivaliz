import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
STATE = ROOT / "scripts" / "agent_task_state.py"
ENSURE = ROOT / "scripts" / "ensure-chatgpt-freeze-successor.py"


class ChatgptFreezeSuccessorTests(unittest.TestCase):
    def run_state(self, runtime: Path, *args: str) -> dict:
        env = os.environ.copy()
        env["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = str(runtime)
        out = subprocess.check_output([sys.executable, str(STATE), *args], env=env, text=True)
        return json.loads(out)

    def run_ensure(self, runtime: Path) -> dict:
        out = subprocess.check_output(
            [
                sys.executable,
                str(ENSURE),
                "--state-dir", str(runtime),
                "--predecessor", "chatgpt-freeze-root-cause-20260927",
                "--base-id", "chatgpt-freeze-root-cause-20260928",
                "--goal", "continue freeze investigation",
                "--next-action", "continue until verified",
                "--evidence", "fresh natural freeze",
                "--agent", "gpt-5.6-sol",
            ],
            text=True,
        )
        return json.loads(out)

    def test_creates_next_generation_only_after_previous_successor_is_terminal(self):
        with tempfile.TemporaryDirectory() as tmp:
            runtime = Path(tmp)
            self.run_state(runtime, "start", "--task", "chatgpt-freeze-root-cause-20260927", "--goal", "root cause")
            self.run_state(runtime, "ready", "--task", "chatgpt-freeze-root-cause-20260927", "--evidence", "verified", "--verification", "done")
            self.run_state(runtime, "complete", "--task", "chatgpt-freeze-root-cause-20260927")

            first = self.run_ensure(runtime)
            self.assertEqual(first["task_id"], "chatgpt-freeze-root-cause-20260928-g2")
            self.assertEqual(first["status"], "RUNNING")
            self.assertEqual(first["predecessor_task_id"], "chatgpt-freeze-root-cause-20260927")

            same = self.run_ensure(runtime)
            self.assertEqual(same["task_id"], first["task_id"])
            self.assertEqual(list(runtime.glob("chatgpt-freeze-root-cause-20260928-g*.json")).__len__(), 1)

            self.run_state(runtime, "ready", "--task", first["task_id"], "--evidence", "verified", "--verification", "done")
            self.run_state(runtime, "complete", "--task", first["task_id"])

            second = self.run_ensure(runtime)
            self.assertEqual(second["task_id"], "chatgpt-freeze-root-cause-20260928-g3")
            self.assertEqual(second["status"], "RUNNING")
            self.assertEqual(second["predecessor_task_id"], first["task_id"])


if __name__ == "__main__":
    unittest.main()
