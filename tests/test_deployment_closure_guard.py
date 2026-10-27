#!/usr/bin/env python3
"""The deploy-closure check rejects CI-only and PR-only success."""
from __future__ import annotations
import importlib.util
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "deployment_closure_guard", ROOT / "scripts/quality/deployment_closure_guard.py"
)
assert spec is not None and spec.loader is not None
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)
NEW = "a" * 40
OLD = "b" * 40
EVIDENCE = {
    "status": "PRODUCTION_UPDATED", "sha": NEW,
    "jobs": {"validate": "success", "deploy": "success", "smoke_test": "success"},
    "verified_at": "2026-10-27T12:00:00Z", "workflow_run_id": "12345",
}


class DeployClosureTests(unittest.TestCase):
    def test_exact_release_is_verified(self):
        self.assertEqual(guard.evaluate(NEW, EVIDENCE, NEW, False)["status"], "VERIFIED_EXACT")

    def test_merged_code_without_deploy_is_not_done(self):
        report = guard.evaluate(NEW, {**EVIDENCE, "sha": OLD}, OLD, True)
        self.assertFalse(report["ok"])
        self.assertEqual(report["status"], "MAIN_HAS_UNDEPLOYED_CODE")

    def test_documentation_only_does_not_require_artificial_deploy(self):
        report = guard.evaluate(NEW, {**EVIDENCE, "sha": OLD}, OLD, False)
        self.assertTrue(report["ok"])
        self.assertEqual(report["status"], "VERIFIED_DOCS_ONLY_AHEAD")

    def test_expected_docs_only_main_commit_is_covered(self):
        # old deployed SHA is the ancestor of the newer docs-only main SHA.
        ancestry = lambda ancestor, child: (
            ancestor == child or (ancestor, child) == (OLD, NEW)
        )
        with mock.patch.object(guard, "descendant", side_effect=ancestry):
            covered = guard.expected_revision_covered(NEW, NEW, OLD, False)
            self.assertTrue(covered)
            report = guard.evaluate(
                NEW, {**EVIDENCE, "sha": OLD}, OLD, False,
                expected_sha=NEW, expected_is_live=covered,
            )
            self.assertEqual(report["status"], "VERIFIED_DOCS_ONLY_AHEAD")
            self.assertTrue(report["ok"])
            # A deployable change must never inherit the docs-only exception.
            self.assertFalse(guard.expected_revision_covered(NEW, NEW, OLD, True))
            # A commit not in main must not be accepted as the expected SHA.
            self.assertFalse(guard.expected_revision_covered("c" * 40, NEW, OLD, False))

    def test_no_duplicate_hourly_paid_monitor(self):
        workflow = (ROOT / ".github/workflows/production-deployment-closure-guard.yml").read_text()
        self.assertIn("  workflow_run:", workflow)
        self.assertIn("  workflow_dispatch:", workflow)
        self.assertNotIn("\n  schedule:\n", workflow)

    def test_bad_evidence_rejected(self):
        report = guard.evaluate(NEW, {**EVIDENCE, "status": "FAILED"}, NEW, False)
        self.assertFalse(report["ok"])

    def test_incorrect_live_sha_rejected(self):
        report = guard.evaluate(NEW, EVIDENCE, OLD, False)
        self.assertEqual(report["status"], "PUBLIC_RELEASE_MISMATCH")

    def test_expected_commit_absent_from_release_rejected(self):
        report = guard.evaluate(NEW, EVIDENCE, NEW, False, expected_sha=OLD, expected_is_live=False)
        self.assertEqual(report["status"], "EXPECTED_COMMIT_NOT_DEPLOYED")

    def test_invalid_sha_is_fail_closed(self):
        with self.assertRaises(guard.GuardError):
            guard.evaluate("not-a-sha", EVIDENCE, NEW, False)

    def test_duplicate_issue_not_allowed(self):
        with mock.patch.object(guard, "command", return_value=(
            '[{"title":"' + guard.ISSUE_TITLE + '","number":1,"state":"OPEN"},'
            '{"title":"' + guard.ISSUE_TITLE + '","number":2,"state":"OPEN"}]'
        )):
            with self.assertRaises(guard.GuardError):
                guard.reconcile_issue({"ok":False, "status":"UNVERIFIED"}, "Vivaliz-site/site-shopvivaliz")


if __name__ == "__main__":
    unittest.main()
