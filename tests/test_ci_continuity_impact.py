"""Regression tests for fail-closed, diff-based continuity suite selection."""
from __future__ import annotations

import importlib.util
from pathlib import Path
from unittest import TestCase, main
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("ci_continuity_impact", ROOT / "scripts" / "ci_continuity_impact.py")
assert SPEC is not None and SPEC.loader is not None
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)


class ContinuityImpactTests(TestCase):
    def test_dispatcher_only_runs_dispatcher_tests(self):
        self.assertEqual(mod.classify(["scripts/chatgpt_continuity_nudge_dispatcher.py"])["mode"], "dispatcher")
        self.assertEqual(mod.classify(["tests/test_chatgpt_continuity_nudge_dispatcher.py"])["mode"], "dispatcher")

    def test_owner_auth_pr_3446_does_not_restart_unrelated_browser_worker(self):
        changed = [
            "AGENTS.md",
            ".github/workflows/absolute-audit-governance.yml",
            "docs/knowledge/security-approval-via-pr.md",
            "scripts/chatgpt_continuity_nudge_dispatcher.py",
            "scripts/validate-owner-pr-grant.py",
            "tests/test_validate_owner_pr_grant.py",
        ]
        self.assertEqual(mod.classify(changed)["mode"], "dispatcher")

    def test_shared_browser_worker_or_undefined_code_runs_full(self):
        for path in (
            "scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs",
            "tests/chatgpt-continuity-bridge-worker-test.mjs",
            "scripts/agent_task_state.py",
            "scripts/task_resume_dispatcher.py",
            "api/chatgpt-continuity/status.php",
            "includes/chatgpt-continuity/handler.php",
            "api/site/orders.php",
            "docs/knowledge/task-continuity.md",
            ".github/workflows/task-continuity-fast-gate.yml",
        ):
            with self.subTest(path=path):
                self.assertEqual(mod.classify(["scripts/chatgpt_continuity_nudge_dispatcher.py", path])["mode"], "full")

    def test_no_dispatcher_or_empty_diff_runs_full(self):
        self.assertEqual(mod.classify([])["mode"], "full")
        self.assertEqual(mod.classify(["AGENTS.md"])["mode"], "full")

    def test_suspicious_or_invalid_paths_run_full(self):
        for path in ("../AGENTS.md", "scripts\\malicious.py", "/tmp/malicious.py", "docs//bad.md"):
            with self.subTest(path=path):
                self.assertEqual(mod.classify(["scripts/chatgpt_continuity_nudge_dispatcher.py", path])["mode"], "full")

    def test_missing_git_diff_is_not_a_fast_pass(self):
        self.assertIsNone(mod.changed_files("bogus", "b" * 40))
        with patch.object(mod.subprocess, "run", side_effect=FileNotFoundError):
            self.assertIsNone(mod.changed_files("a" * 40, "b" * 40))

    def test_real_git_diff_uses_merge_base(self):
        with patch.object(mod.subprocess, "run") as run:
            run.return_value.stdout = b"scripts/chatgpt_continuity_nudge_dispatcher.py\x00AGENTS.md\x00"
            actual = mod.changed_files("a" * 40, "b" * 40)
        self.assertEqual(actual, ["scripts/chatgpt_continuity_nudge_dispatcher.py", "AGENTS.md"])
        self.assertEqual(run.call_args.args[0][-1], "a" * 40 + "..." + "b" * 40)


if __name__ == "__main__":
    main()
