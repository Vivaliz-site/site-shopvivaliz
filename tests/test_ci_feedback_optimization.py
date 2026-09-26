from __future__ import annotations

import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def load_lineage_module():
    path = ROOT / "scripts" / "maintenance" / "validate_current_history_lineage.py"
    spec = importlib.util.spec_from_file_location("validate_current_history_lineage", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load current history lineage validator")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class CiFeedbackOptimizationTests(unittest.TestCase):
    def test_slow_global_audits_do_not_run_on_every_pull_request(self) -> None:
        history = (ROOT / ".github" / "workflows" / "history-integrity.yml").read_text(encoding="utf-8")
        inventory = (ROOT / ".github" / "workflows" / "test-inventory.yml").read_text(encoding="utf-8")
        self.assertNotIn("\n  pull_request:", history)
        self.assertNotIn("\n  pull_request:", inventory)

    def test_fast_pr_gate_keeps_current_head_history_lineage_protection(self) -> None:
        gate = (ROOT / ".github" / "workflows" / "mandatory-validation-gate.yml").read_text(encoding="utf-8")
        self.assertIn("validate_current_history_lineage.py", gate)

        module = load_lineage_module()
        with tempfile.TemporaryDirectory() as tmp:
            repo = Path(tmp)
            marker = repo / "sanitized-history.json"
            marker.write_text('{"root_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}\n', encoding="utf-8")

            calls = []
            def fake_run(*args, **kwargs):
                calls.append(args)
                class Result:
                    returncode = 0
                    stdout = ""
                    stderr = ""
                return Result()

            findings, checks = module.evaluate(repo=repo, marker_path=marker, run_command=fake_run)
            self.assertEqual(findings, [])
            self.assertEqual(checks["root_sha"], "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
            self.assertIn(("git", "merge-base", "--is-ancestor", "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", "HEAD"), calls)

    def test_pr_feedback_workflows_cancel_superseded_runs(self) -> None:
        workflows = (
            "mandatory-validation-gate.yml",
            "shopvivaliz-qa.yml",
            "policy-engine.yml",
            "autonomy-boundary.yml",
            "agents-hourly-deep-audit.yml",
            "history-integrity.yml",
            "test-inventory.yml",
        )
        for name in workflows:
            text = (ROOT / ".github" / "workflows" / name).read_text(encoding="utf-8")
            self.assertIn("concurrency:", text, name)
            self.assertIn("cancel-in-progress: true", text, name)


    def test_task_continuity_fast_gate_executes_resume_order_regressions(self) -> None:
        gate = (ROOT / ".github" / "workflows" / "task-continuity-fast-gate.yml").read_text(encoding="utf-8")
        self.assertIn("scripts/task_continuation_watchdog.py", gate)
        self.assertIn("scripts/autonomous-agent-loop.sh", gate)
        self.assertIn("scripts/autonomous-provider-failover.sh", gate)
        self.assertIn("tests/test_task_continuation_watchdog.py", gate)
        self.assertIn("tests.test_task_continuation_watchdog", gate)
        self.assertIn("GEPETO-POLICY.md", gate)
        self.assertIn("docs/knowledge/dev-agent-briefing.md", gate)


if __name__ == "__main__":
    unittest.main()
