import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "repair-global-cleanup-false-terminal.py"


class RepairTests(unittest.TestCase):
    def run_script(self, payload):
        with tempfile.TemporaryDirectory() as td:
            p = Path(td) / "state.json"
            p.write_text(json.dumps(payload), encoding="utf-8")
            cp = subprocess.run([sys.executable, str(SCRIPT), "--apply", str(p)], text=True, capture_output=True)
            out = json.loads(p.read_text(encoding="utf-8"))
            backups = list(Path(td).glob("state.json.false-terminal-backup-*"))
            return cp, out, backups

    def base(self):
        return {
            "schema_version": 1,
            "task_id": "global-continuity-cleanup-v2-20260927",
            "status": "CONCLUIDO",
            "verification": "Local workspace and task continuity state successfully persisted and validated.",
            "completed_at": "2026-09-27T23:51:16Z",
            "updated_at": "2026-09-27T23:51:16Z",
            "next_action": "",
            "blocker": None,
            "history": [
                {"at": "2026-09-27T23:51:16Z", "event": "ready_to_complete"},
                {"at": "2026-09-27T23:51:16Z", "event": "completed"},
            ],
        }

    def test_repairs_only_known_false_terminal_and_keeps_backup(self):
        cp, out, backups = self.run_script(self.base())
        self.assertEqual(cp.returncode, 0, cp.stderr)
        self.assertEqual(out["status"], "BLOCKED_EXTERNAL")
        self.assertIsNone(out["verification"])
        self.assertNotIn("completed_at", out)
        self.assertTrue(out["blocker"]["external"])
        self.assertIn("delete_repo", out["blocker"]["description"])
        self.assertEqual(out["history"][-1]["event"], "false_terminal_corrected")
        self.assertEqual(len(backups), 1)

    def test_refuses_unexpected_verification_without_mutation(self):
        payload = self.base()
        payload["verification"] = "something else"
        cp, out, backups = self.run_script(payload)
        self.assertNotEqual(cp.returncode, 0)
        self.assertEqual(out["status"], "CONCLUIDO")
        self.assertEqual(out["verification"], "something else")
        self.assertEqual(backups, [])

    def test_refuses_non_concluido_without_mutation(self):
        payload = self.base()
        payload["status"] = "RUNNING"
        cp, out, backups = self.run_script(payload)
        self.assertNotEqual(cp.returncode, 0)
        self.assertEqual(out["status"], "RUNNING")
        self.assertEqual(backups, [])


if __name__ == "__main__":
    unittest.main()
