#!/usr/bin/env python3
"""Regression: Oracle merge helper rejects human-only security authorization PRs."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class SecurityMergeGuardTest(unittest.TestCase):
    def test_owner_grant_and_request_blocked_before_merge(self):
        script = (ROOT / "scripts/merge_green_pr_via_vm.sh").read_text()
        self.assertIn("changed_paths=", script)
        self.assertIn("^security/owner-approvals/[0-9]+[.]json$", script)
        self.assertIn("^security/requests/", script)
        self.assertIn("owner-only security grant cannot be merged by an automation", script)
        self.assertLess(script.index("changed_paths="), script.index('result="$(gh api --method PUT'))

    def test_owner_guard_cannot_use_requester_supplied_labels(self):
        script = (ROOT / "scripts/merge_green_pr_via_vm.sh").read_text()
        self.assertIn('pulls/${pr_number}/files?per_page=100', script)
        self.assertNotIn("if [[ \"$pr_labels\"", script)


if __name__ == "__main__":
    unittest.main()
