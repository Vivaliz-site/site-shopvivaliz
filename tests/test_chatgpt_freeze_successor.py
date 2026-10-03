import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
STATE = ROOT / "scripts" / "agent_task_state.py"
ENSURE = ROOT / "scripts" / "ensure-chatgpt-freeze-successor.py"


class ChatgptFreezeSuccessorTests(unittest.TestCase):
    def run_state(self, runtime: Path, *args: str) -> dict:
        env = os.environ.copy()
        env["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = str(runtime)
        out = subprocess.check_output([sys.executable, str(STATE), *args], env=env, text=True)
        return json.loads(out)

    def confirm_browser_progress(self, runtime: Path, task_id: str) -> None:
        ledger = runtime / "_chatgpt-continuity-nudges.jsonl"
        row = {
            "task_id": task_id,
            "worker_status": "PROGRESS_CONFIRMED",
            "worker_status_observed_at": "2026-09-29T00:00:00Z",
        }
        with ledger.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(row, sort_keys=True) + "\n")

    def test_creates_g3_only_after_g2_is_concluido_and_preserves_g2_bytes(self):
        self.assertTrue(ENSURE.is_file(), "generational successor helper is missing")
        with tempfile.TemporaryDirectory() as tmp:
            runtime = Path(tmp)
            self.run_state(runtime, "start", "--task", "chatgpt-freeze-root-cause-20260927", "--goal", "root cause")
            self.run_state(runtime, "ready", "--task", "chatgpt-freeze-root-cause-20260927", "--evidence", "verified", "--verification", "done")
            self.run_state(runtime, "complete", "--task", "chatgpt-freeze-root-cause-20260927")

            cmd = [
                sys.executable, str(ENSURE),
                "--state-dir", str(runtime),
                "--state-script", str(STATE),
                "--predecessor", "chatgpt-freeze-root-cause-20260927",
                "--base-id", "chatgpt-freeze-root-cause-20260928",
                "--goal", "continue freeze investigation",
                "--next-action", "continue until verified",
                "--evidence", "fresh natural freeze",
                "--agent", "gpt-5.6-sol",
            ]
            first = json.loads(subprocess.check_output(cmd, text=True))
            self.assertEqual(first["task_id"], "chatgpt-freeze-root-cause-20260928-g2")
            self.confirm_browser_progress(runtime, first["task_id"])
            self.run_state(runtime, "ready", "--task", first["task_id"], "--evidence", "verified", "--verification", "done")
            self.run_state(runtime, "complete", "--task", first["task_id"])
            g2_path = runtime / "chatgpt-freeze-root-cause-20260928-g2.json"
            g2_terminal_bytes = g2_path.read_bytes()

            second = json.loads(subprocess.check_output(cmd, text=True))
            self.assertEqual(g2_path.read_bytes(), g2_terminal_bytes)
            self.assertEqual(second["task_id"], "chatgpt-freeze-root-cause-20260928-g3")
            self.assertEqual(second["predecessor_task_id"], "chatgpt-freeze-root-cause-20260928-g2")
            self.assertEqual(second["status"], "RUNNING")

            same = json.loads(subprocess.check_output(cmd, text=True))
            self.assertEqual(same["task_id"], second["task_id"])
            self.assertEqual(len(list(runtime.glob("chatgpt-freeze-root-cause-20260928-g*.json"))), 2)


    def test_root_normalization_reassigns_checkpoint_to_runtime_owner(self):
        spec = importlib.util.spec_from_file_location("ensure_chatgpt_freeze_successor", ENSURE)
        module = importlib.util.module_from_spec(spec)
        assert spec and spec.loader
        spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as tmp:
            runtime = Path(tmp)
            checkpoint = runtime / "chatgpt-freeze-root-cause-20260928-g3.json"
            checkpoint.write_text("{}\n", encoding="utf-8")
            owner = runtime.stat()
            with (
                mock.patch.object(module.os, "geteuid", return_value=0),
                mock.patch.object(module.os, "chown") as chown,
            ):
                module.normalize_state_permissions(checkpoint, runtime)
            chown.assert_called_once_with(checkpoint, owner.st_uid, owner.st_gid)
            self.assertEqual(checkpoint.stat().st_mode & 0o777, 0o600)

    def test_existing_running_generation_normalizes_mode_without_creating_successor(self):
        with tempfile.TemporaryDirectory() as tmp:
            runtime = Path(tmp)
            self.run_state(runtime, "start", "--task", "chatgpt-freeze-root-cause-20260927", "--goal", "root cause")
            self.run_state(runtime, "ready", "--task", "chatgpt-freeze-root-cause-20260927", "--evidence", "verified", "--verification", "done")
            self.run_state(runtime, "complete", "--task", "chatgpt-freeze-root-cause-20260927")
            cmd = [
                sys.executable, str(ENSURE),
                "--state-dir", str(runtime),
                "--state-script", str(STATE),
                "--predecessor", "chatgpt-freeze-root-cause-20260927",
                "--base-id", "chatgpt-freeze-root-cause-20260928",
                "--goal", "continue freeze investigation",
                "--next-action", "continue until verified",
                "--evidence", "fresh natural freeze",
                "--agent", "gpt-5.6-sol",
            ]
            first = json.loads(subprocess.check_output(cmd, text=True))
            checkpoint = runtime / f"{first['task_id']}.json"
            checkpoint.chmod(0o640)
            same = json.loads(subprocess.check_output(cmd, text=True))
            self.assertEqual(same["task_id"], first["task_id"])
            self.assertEqual(checkpoint.stat().st_mode & 0o777, 0o600)
            self.assertEqual(len(list(runtime.glob("chatgpt-freeze-root-cause-20260928-g*.json"))), 1)

    def test_generation_parser_supports_double_digits(self):
        spec = importlib.util.spec_from_file_location("ensure_chatgpt_freeze_successor", ENSURE)
        module = importlib.util.module_from_spec(spec)
        assert spec and spec.loader
        spec.loader.exec_module(module)
        path = Path("chatgpt-freeze-root-cause-20260928-g10.json")
        self.assertEqual(module.generation(path, "chatgpt-freeze-root-cause-20260928"), 10)


if __name__ == "__main__":
    unittest.main()
