from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("ci_impact_selection", ROOT / "scripts/ci_impact_selection.py")
assert spec is not None and spec.loader is not None
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ImpactSelectionTests(unittest.TestCase):
    def test_shopee_isolated_changes_avoid_unrelated_auth_suite(self):
        r = module.classify(["scripts/shopee_logistics_worker.py", "tests/test_shopee_logistics_worker.py"])
        self.assertEqual(r["mode"], "scoped")
        self.assertEqual(r["groups"], ["shopee"])
        self.assertFalse(r["needs_php"])

    def test_chatgpt_dispatcher_isolated_does_not_run_unrelated_worker_or_site(self):
        paths = [
            "scripts/chatgpt_continuity_nudge_dispatcher.py",
            "tests/test_chatgpt_continuity_nudge_dispatcher.py",
        ]
        actual = module.classify(paths)
        self.assertEqual(actual["mode"], "scoped")
        self.assertEqual(actual["groups"], ["chatgpt-dispatcher"])
        self.assertFalse(actual["needs_php"])

    def test_chatgpt_dispatcher_combined_with_security_or_worker_is_full(self):
        for path in (
            "AGENTS.md",
            ".github/workflows/absolute-audit-governance.yml",
            "scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs",
            "scripts/ci_impact_selection.py",
            "api/chatgpt-continuity/action.php",
        ):
            with self.subTest(path=path):
                actual = module.classify([
                    "scripts/chatgpt_continuity_nudge_dispatcher.py",
                    path,
                ])
                self.assertEqual(actual["mode"], "full")

    def test_docs_only_runs_core(self):
        r = module.classify(["docs/knowledge/unrelated-runbook.md", "README.md"])
        self.assertEqual(r["groups"], ["docs"])

    def test_shared_site_api_requires_full(self):
        for path in ("api/olist/webhook.php", "includes/erp/pricing.php", "api/shopee/auth.php", "scripts/ml_auth.py"):
            with self.subTest(path=path):
                self.assertEqual(module.classify([path])["mode"], "full")

    def test_workflow_or_security_policy_always_full(self):
        for path in ("AGENTS.md", "docs/knowledge/agent-rules.md", "docs/ops/cloudflare-admin.md", ".github/workflows/mandatory-validation-gate.yml", "AUDIT_POLICY.md", "docs/quality/AUDIT_BROWSER_E2E_REAL_V1.md", "composer.lock"):
            with self.subTest(path=path):
                self.assertEqual(module.classify([path])["mode"], "full")

    def test_mixed_scoped_groups_are_unioned(self):
        r = module.classify(["scripts/shopee_logistics_worker.py", "docs/knowledge/independent-runbook.md", "scripts/task_resume_queue.py"])
        self.assertEqual(r["groups"], ["continuity", "docs", "shopee"])

    def test_mixed_unknown_file_fails_closed(self):
        r = module.classify(["scripts/shopee_logistics_worker.py", "includes/common.php"])
        self.assertEqual(r["mode"], "full")
        self.assertIn("includes/common.php", r["full_reasons"])

    def test_unknown_or_missing_diff_fails_closed(self):
        self.assertEqual(module.classify([])["mode"], "full")
        self.assertIsNone(module.changed_files("", ""))
        self.assertIsNone(module.changed_files("deadbeef", ""))

    def test_no_fake_scope_from_path_traversal(self):
        self.assertEqual(module.classify(["../docs/knowledge/agent-rules.md"])["mode"], "full")

    def test_email_requires_php_but_not_unrelated_suite(self):
        r = module.classify(["tests/shopvivaliz-brevo-mailer-contract-test.php"])
        self.assertEqual(r["groups"], ["mail"])
        self.assertTrue(r["needs_php"])

    def test_duplicate_paths_are_deduped(self):
        self.assertEqual(len(module.classify(["README.md", "README.md"])["changed_files"]), 1)

    def test_no_environment_override(self):
        with patch.dict("os.environ", {"CI_IMPACT_MODE": "scoped"}):
            self.assertEqual(module.classify(["includes/unsafe.php"])["mode"], "full")


if __name__ == "__main__":
    unittest.main()
