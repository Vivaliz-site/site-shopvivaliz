from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

import agent_task_state as state  # noqa: E402


class ObsoleteTaskTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.original = state.RUNTIME_DIR
        state.RUNTIME_DIR = Path(self.temp.name)

    def tearDown(self) -> None:
        state.RUNTIME_DIR = self.original
        self.temp.cleanup()

    def test_recent_task_cannot_be_retired(self) -> None:
        state.start_task("fresh-one", "keep going", "claude")
        with self.assertRaisesRegex(state.TaskStateError, "recent progress"):
            state.obsolete_task("fresh-one", authorized_by="owner", reason="stale")

    def test_requires_owner_and_reason(self) -> None:
        state.start_task("needs-owner", "goal", "claude")
        with self.assertRaisesRegex(state.TaskStateError, "authorized_by"):
            state.obsolete_task("needs-owner", authorized_by="", reason="r", stale_after_hours=0)
        with self.assertRaisesRegex(state.TaskStateError, "reason"):
            state.obsolete_task("needs-owner", authorized_by="owner", reason="", stale_after_hours=0)

    def test_retire_then_resume_roundtrip_keeps_history(self) -> None:
        state.start_task("old-one", "goal", "claude")
        done = state.obsolete_task("old-one", authorized_by="owner", reason="abandoned", stale_after_hours=0)
        self.assertEqual(done["status"], "BLOCKED_EXTERNAL")
        self.assertEqual(done["blocker"]["kind"], "obsolete_by_owner")
        self.assertEqual(done["blocker"]["authorized_by"], "owner")
        self.assertIn("obsolete_by_owner", [row.get("event") for row in done["history"]])
        with self.assertRaisesRegex(state.TaskStateError, "only an active task"):
            state.obsolete_task("old-one", authorized_by="owner", reason="again", stale_after_hours=0)
        back = state.resume_task("old-one", next_action="reopen by owner request")
        self.assertEqual(back["status"], "RUNNING")


if __name__ == "__main__":
    unittest.main()
