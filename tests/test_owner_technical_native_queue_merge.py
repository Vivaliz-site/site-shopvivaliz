"""Official GH queue attributes merged_by to the authenticated enqueuer."""
import unittest
from types import SimpleNamespace
from unittest.mock import patch

from tests.test_owner_merge_queue_authorization import (
    MODULE, MERGE, HEAD, REPO, RULESET, fixture
)


def proven_queue():
    pr, grant, args = fixture()
    pr["merged_by"] = {"login": "fredmourao-ai"}
    proof = dict(args["queue_proof"])
    proof["merge_group_runs"] = [
        {"id": 111, "name": name, "event": "merge_group", "head_sha": MERGE,
         "status": "completed", "conclusion": "success",
         "created_at": "2026-11-02T20:08:00Z"}
        for name in ("Mandatory Validation Gate", "Repository Governance")
    ]
    proof["check_runs"] = [
        {"id": i + 1, "name": name, "status": "completed",
         "conclusion": "success", "app": {"id": 15368}}
        for i, name in enumerate(("validation-gate", "Audit repository automation"))
    ]
    args["queue_proof"] = proof
    return pr, grant, args


def active_queue_ruleset():
    return {"id": 24523478, "name": "Audit V5 - required PR and verified checks",
            "target": "branch", "enforcement": "active", "source_type": "Repository",
            "bypass_actors": [], "conditions": {
                "ref_name": {"include": ["~DEFAULT_BRANCH"], "exclude": []}},
            "rules": [
                {"type": "deletion"}, {"type": "non_fast_forward"},
                {"type": "pull_request", "parameters": {
                    "dismiss_stale_reviews_on_push": True,
                    "required_approving_review_count": 0,
                    "required_review_thread_resolution": True,
                    "require_extra_approval_for_unattributed_changes": True}},
                {"type": "required_status_checks", "parameters": {
                    "strict_required_status_checks_policy": True,
                    "required_status_checks": [
                        {"context": name, "integration_id": app}
                        for name, app in RULESET.REQUIRED.items()]}},
                {"type": "merge_queue", "parameters": dict(RULESET.EXPECTED_QUEUE_PARAMS)},
            ]}


class OwnerTechnicalNativeQueueMergeTests(unittest.TestCase):
    def test_existing_ruleset_is_validated_and_no_repeated_grant_or_write(self):
        current = active_queue_ruleset()
        self.assertTrue(RULESET.verified_existing_queue(current))
        with patch.object(RULESET.os, "uname", return_value=SimpleNamespace(
                nodename="always-free-arm-1787907847-26")), \
             patch.object(RULESET, "gh", side_effect=[
                 {"login": "fredmourao-ai"}, current]) as gh, \
             patch.object(RULESET, "grant_for_merged_pr",
                          side_effect=AssertionError("should not require new grant")):
            self.assertEqual(RULESET.activate(), 0)
            self.assertEqual(gh.call_count, 2)

    def test_existing_ruleset_downgrade_or_bypass_fails_closed(self):
        current = active_queue_ruleset()
        import copy
        for mutation in ("bypass", "strict", "queue", "required_checks",
                         "missing_nff", "source"):
            with self.subTest(mutation=mutation):
                broken = copy.deepcopy(current)
                if mutation == "bypass":
                    broken["bypass_actors"] = [{"actor_id": 1}]
                elif mutation == "strict":
                    broken["rules"][3]["parameters"]["strict_required_status_checks_policy"] = False
                elif mutation == "queue":
                    broken["rules"][4]["parameters"]["grouping_strategy"] = "HEADGREEN"
                elif mutation == "required_checks":
                    broken["rules"][3]["parameters"]["required_status_checks"].pop()
                elif mutation == "missing_nff":
                    broken["rules"].pop(1)
                else:
                    broken["source_type"] = "Organization"
                self.assertFalse(RULESET.verified_existing_queue(broken))

    def test_genuine_owner_approved_native_queue_merges_under_technical_enqueuer(self):
        pr, grant, args = proven_queue()
        self.assertEqual(MODULE.decide(pr, grant, **args),
                         (True, "owner_authorization_verified"))

    def test_direct_technical_merge_without_queue_proof_remains_denied(self):
        pr, grant, args = proven_queue()
        args["queue_proof"] = None
        self.assertFalse(MODULE.decide(pr, grant, **args)[0])

    def test_missing_or_failed_provider_merge_group_checks_denied(self):
        pr, grant, args = proven_queue()
        for change in (
            {"merge_group_runs": []},
            {"check_runs": []},
            {"merge_group_runs": [{**args["queue_proof"]["merge_group_runs"][0],
                                  "conclusion": "failure"}]},
            {"check_runs": [{**args["queue_proof"]["check_runs"][0],
                              "app": {"id": 1}}]},
        ):
            with self.subTest(change=change):
                proof = {**args["queue_proof"], **change}
                self.assertFalse(MODULE.decide(
                    pr, grant, **{**args, "queue_proof": proof})[0])

    def test_wrong_queue_actor_or_sha_does_not_authorize(self):
        pr, grant, args = proven_queue()
        for changed in (
            {"enqueuer": {"login": "attacker"}},
            {"enqueuer": {"login": "shopvivaliz-human"}},
            {"pullRequest": {"number": 123, "headRefOid": "c" * 40}},
        ):
            with self.subTest(changed=changed):
                event = {**args["queue_proof"]["events"][0], **changed}
                proof = {**args["queue_proof"], "events": [event]}
                self.assertFalse(MODULE.decide(
                    pr, grant, **{**args, "queue_proof": proof})[0])


if __name__ == "__main__":
    unittest.main()
