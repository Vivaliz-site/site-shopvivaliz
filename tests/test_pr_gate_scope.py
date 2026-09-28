from __future__ import annotations

import importlib.util
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def load_scope():
    path = ROOT / "scripts" / "pr_gate_scope.py"
    spec = importlib.util.spec_from_file_location("pr_gate_scope", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load PR gate scope")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class PrGateScopeTests(unittest.TestCase):
    def test_continuity_only_change_does_not_require_unrelated_specialized_gates(self) -> None:
        scope = load_scope()
        gates = scope.required_specialized_gates([
            "scripts/agent_task_state.py",
            "tests/test_task_continuity_enforcement.py",
        ])
        self.assertEqual(gates, [])

    def test_empty_scope_cli_emits_no_blank_gate(self) -> None:
        completed = subprocess.run(
            [
                sys.executable,
                str(ROOT / "scripts" / "pr_gate_scope.py"),
                "--file=scripts/agent_task_state.py",
                "--file=tests/test_task_continuity_enforcement.py",
            ],
            check=True,
            capture_output=True,
            text=True,
        )
        self.assertEqual(completed.stdout, "")

    def test_storefront_change_selects_runtime_gates(self) -> None:
        scope = load_scope()
        gates = set(scope.required_specialized_gates(["public/assets/app.js"]))
        self.assertIn("ShopVivaliz QA", gates)
        self.assertIn("Policy Engine", gates)
        self.assertIn("Ecommerce Excellence Audit", gates)

    def test_pr_automation_change_selects_pr_policy_gate(self) -> None:
        scope = load_scope()
        gates = set(scope.required_specialized_gates(["scripts/pr_gate_replay.py"]))
        self.assertIn("PR Policy Enforcement", gates)
        self.assertNotIn("Policy Engine", gates)

    def test_completion_enforcer_uses_scope_helper_before_replaying_missing_gate(self) -> None:
        workflow = (ROOT / ".github" / "workflows" / "pr-completion-enforcer.yml").read_text(encoding="utf-8")
        self.assertIn("pr_gate_scope.py", workflow)
        self.assertIn("changed_files", workflow)
        self.assertIn("applicable_required", workflow)


if __name__ == "__main__":
    unittest.main()
