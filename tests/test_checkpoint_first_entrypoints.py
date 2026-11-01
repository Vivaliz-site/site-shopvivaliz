import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
ENTRYPOINTS = ("CLAUDE.md", "GEMINI.md", "GEPETO-POLICY.md", ".github/copilot-instructions.md")

class CheckpointFirstEntrypointsTests(unittest.TestCase):
    def test_all_agent_entrypoints_have_checkpoint_first(self):
        for name in ENTRYPOINTS:
            with self.subTest(entrypoint=name):
                content = (ROOT / name).read_text(encoding="utf-8")
                for token in ("CHECKPOINT_FIRST_V9", "agent_task_state.py start", "agent_task_state.py progress", "antes da primeira"):
                    self.assertIn(token, content)

    def test_validator_rejects_missing_marker_in_each_entrypoint(self):
        import runpy
        original = Path.read_text
        for name in ENTRYPOINTS:
            target = ROOT / name
            def modified_read(path, *args, **kwargs):
                result = original(path, *args, **kwargs)
                return result.replace("CHECKPOINT_FIRST_V9", "MISSING_CHECKPOINT_MARKER") if path == target else result
            with self.subTest(entrypoint=name), patch.object(Path, "read_text", modified_read):
                with self.assertRaises(SystemExit) as failure:
                    runpy.run_path(str(ROOT / "scripts/validate-task-continuity-enforcement.py"), run_name="__main__")
                self.assertNotEqual(failure.exception.code, 0)
