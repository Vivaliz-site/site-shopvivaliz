from __future__ import annotations

import importlib.util
from pathlib import Path
import unittest
from unittest import mock

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "validate-owner-pr-grant.py"
SPEC = importlib.util.spec_from_file_location("owner_pr_grant_units", SCRIPT)
grant_module = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(grant_module)

UNIT = "shopvivaliz-marina-root-broker.service"
HEAD = "c" * 40


def merged_pr(**overrides):
    pr = {"merged": True, "state": "closed", "number": 42,
          "head": {"sha": HEAD}, "base": {"ref": "main"}}
    pr.update(overrides)
    return pr


def review(login="shopvivaliz-human", state="APPROVED", commit=HEAD):
    return {"user": {"login": login}, "state": state, "commit_id": commit}


class UnitPatternAuthorizationTests(unittest.TestCase):
    def test_human_approved_merged_unit_change_is_authorized(self):
        self.assertEqual(grant_module.unit_pattern_authorized(
            UNIT, merged_pr(), [review()]), (True, "unit_change_approved_by_human"))

    def test_name_outside_pattern_is_denied_even_if_approved(self):
        for unit in ("shopvivaliz-x.timer", "other-app.service", "shopvivaliz-../x.service",
                     "shopvivaliz-Upper.service", "shopvivaliz-.service"):
            self.assertEqual(grant_module.unit_pattern_authorized(
                unit, merged_pr(), [review()])[0], False, unit)

    def test_unmerged_or_wrong_base_is_denied(self):
        self.assertFalse(grant_module.unit_pattern_authorized(
            UNIT, merged_pr(merged=False, state="open"), [review()])[0])
        self.assertFalse(grant_module.unit_pattern_authorized(
            UNIT, merged_pr(base={"ref": "feature"}), [review()])[0])

    def test_agent_or_non_owner_approval_is_denied(self):
        self.assertFalse(grant_module.unit_pattern_authorized(
            UNIT, merged_pr(), [review(login="fredmourao-ai")])[0])
        self.assertFalse(grant_module.unit_pattern_authorized(UNIT, merged_pr(), [])[0])

    def test_stale_approval_on_older_head_is_denied(self):
        self.assertFalse(grant_module.unit_pattern_authorized(
            UNIT, merged_pr(), [review(commit="d" * 40)])[0])

    def test_later_changes_requested_revokes_approval(self):
        self.assertFalse(grant_module.unit_pattern_authorized(
            UNIT, merged_pr(), [review(), review(state="CHANGES_REQUESTED")])[0])

    def test_github_lookup_uses_latest_main_commit_and_its_single_merged_pr(self):
        commit = "e" * 40
        responses = [
            [{"sha": commit}],
            [{"number": 42, "merged_at": "2026-11-03T00:00:00Z"}],
            merged_pr(),
            [review()],
        ]
        with mock.patch.object(grant_module, "github_json", side_effect=responses):
            pr, reviews = grant_module.github_unit_change_pr(
                "Vivaliz-site/site-shopvivaliz", UNIT, "token")
        self.assertEqual(pr["number"], 42)
        self.assertEqual(reviews, [review()])

    def test_lookup_without_single_merged_pr_returns_nothing(self):
        responses = [[{"sha": "e" * 40}], []]
        with mock.patch.object(grant_module, "github_json", side_effect=responses):
            self.assertEqual(grant_module.github_unit_change_pr(
                "Vivaliz-site/site-shopvivaliz", UNIT, "token"), (None, None))


if __name__ == "__main__":
    unittest.main()
