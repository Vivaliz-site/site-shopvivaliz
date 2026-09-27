from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUNNER = ROOT / "scripts" / "run_background_gemini.py"


def load_runner():
    spec = importlib.util.spec_from_file_location("run_background_gemini_headless_test", RUNNER)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load background Gemini runner")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class BackgroundGeminiHeadlessRegistrationTests(unittest.TestCase):
    def test_noninteractive_command_explicitly_registers_bounded_state_shell(self) -> None:
        mod = load_runner()
        command = mod.build_gemini_command(
            executable="/home/ubuntu/.local/bin/gemini",
            model="gemini-2.5-flash",
            prompt="continue task",
            policy_path=Path("/tmp/continuity-policy.toml"),
        )
        self.assertIn("--allowed-tools", command)
        allowed = command[command.index("--allowed-tools") + 1]
        self.assertIn("ShellTool(python3 scripts/agent_task_state.py)", allowed)
        self.assertIn("ShellTool(./scripts/agent_task_state.py)", allowed)
        self.assertNotIn("ShellTool,", allowed)
        self.assertNotIn("yolo", command)


if __name__ == "__main__":
    unittest.main()
