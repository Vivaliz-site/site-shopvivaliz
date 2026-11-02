"""Regression: official queue proof may substitute HUMAN merge actor only safely."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


def module(path, name):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


grants = module("scripts/validate-owner-pr-grant.py", "owner_grants")
activator = module("scripts/quality/enable_owner_merge_queue.py", "queue_activator")
APPROVED = "2026-11-02T19:25:01Z"
MERGED = "2026-11-02T19:50:00Z"


def added(at="2026-11-02T19:40:00Z", actor="fredmourao-ai"):
    return {"__typename": "AddedToMergeQueueEvent", "createdAt": at,
            "enqueuer": {"login": actor}, "actor": {"login": actor}}


class QueueGrantProofTests(unittest.TestCase):
    def test_real_enqueued_after_review_is_accepted(self):
        self.assertTrue(grants.valid_owner_approved_queue_history(
            [added()], APPROVED, MERGED))

    def test_fake_or_different_enqueuer_is_rejected(self):
        self.assertFalse(grants.valid_owner_approved_queue_history(
            [added(actor="github-actions[bot]")], APPROVED, MERGED))

    def test_adding_before_human_review_is_not_authorization(self):
        self.assertFalse(grants.valid_owner_approved_queue_history(
            [added(at="2026-11-02T19:24:00Z")], APPROVED, MERGED))

    def test_queue_removal_revokes_technical_queue_proof(self):
        removed = {"__typename": "RemovedFromMergeQueueEvent",
                   "createdAt": "2026-11-02T19:45:00Z",
                   "actor": {"login": "fredmourao-ai"}}
        self.assertFalse(grants.valid_owner_approved_queue_history(
            [added(), removed], APPROVED, MERGED))

    def test_incomplete_or_unparseable_history_is_denied(self):
        self.assertFalse(grants.valid_owner_approved_queue_history(
            None, APPROVED, MERGED))
        self.assertFalse(grants.valid_owner_approved_queue_history(
            [], APPROVED, MERGED))
        self.assertFalse(grants.valid_owner_approved_queue_history(
            [added()] * 100, APPROVED, MERGED))
        self.assertFalse(grants.valid_owner_approved_queue_history(
            [added()], "invalid", MERGED))

    def test_merge_queue_alone_without_owner_review_is_not_grant(self):
        # Actual decide() also enforces APPROVED on reviewed HEAD,
        # immutable grant and SHA before it checks queue event.
        text = (ROOT / "scripts/validate-owner-pr-grant.py").read_text()
        self.assertIn("security_pr_not_approved_by_human", text)
        self.assertIn("owner_reviewed_merge_queue_proof_missing", text)
        self.assertIn("MERGE_QUEUE_BOT", text)


class RulesetActivationTests(unittest.TestCase):
    def data(self):
        cfg = {
            "approval_pr": 9999,
            "approval_request_path": "security/requests/owner-review-merge-queue-20261102.md",
        }
        pr = {
            "number": 9999, "state": "closed", "merged": True,
            "merged_by": {"login": "shopvivaliz-human"},
            "base": {"ref": "main"},
            "head": {"sha": "a" * 40, "repo": {"full_name": activator.REPO}},
        }
        reviews = [{"state": "APPROVED", "commit_id": "a" * 40,
                    "user": {"login": "shopvivaliz-human"}}]
        files = [{"filename": f} for f in (
            cfg["approval_request_path"],
            "config/owner-review-merge-queue.json",
            ".github/workflows/owner-human-review-autoqueue.yml",
            ".github/workflows/mandatory-validation-gate.yml",
            ".github/workflows/repository-governance.yml",
            "scripts/validate-owner-pr-grant.py",
        )]
        return pr, reviews, files, cfg

    def test_grant_requires_specific_human_merged_pr(self):
        p, r, f, c = self.data()
        self.assertTrue(activator.approved_owner_p2(p, r, f, c))
        p["merged_by"] = {"login": "fredmourao-ai"}
        self.assertFalse(activator.approved_owner_p2(p, r, f, c))

    def test_review_exact_sha_required(self):
        p, r, f, c = self.data()
        r[0]["commit_id"] = "b" * 40
        self.assertFalse(activator.approved_owner_p2(p, r, f, c))

    def test_request_must_be_part_of_exact_reviewed_diff(self):
        p, r, f, c = self.data()
        f.pop(0)
        self.assertFalse(activator.approved_owner_p2(p, r, f, c))

    def test_activation_refuses_request_without_required_review_workflow(self):
        pr,reviews,files,config=self.data()
        files=[f for f in files if f["filename"]!=".github/workflows/mandatory-validation-gate.yml"]
        self.assertFalse(activator.approved_owner_p2(pr,reviews,files,config))

    def test_activator_preserves_required_rules(self):
        rules = [
            {"type": "pull_request", "parameters": {"required_approving_review_count": 0}},
            {"type": "required_status_checks", "parameters": {
                "strict_required_status_checks_policy": True,
                "required_status_checks": [
                    {"context": "Audit repository automation"},
                    {"context": "validation-gate"},
                ]}},
            {"type": "non_fast_forward"},
        ]
        base = {
            "name": "Audit V5 - required PR and verified checks",
            "enforcement": "active", "target": "branch",
            "conditions": {"ref_name": {"include": ["~DEFAULT_BRANCH"], "exclude": []}},
            "rules": rules, "bypass_actors": []
        }
        queue = {"type": "merge_queue", "parameters": {"merge_method": "SQUASH"}}
        out = activator.prepare_update(base, queue)
        self.assertEqual(out["rules"], rules + [queue])
        self.assertEqual(out["bypass_actors"], [])
        self.assertEqual(out["conditions"], base["conditions"])
        base["rules"] = out["rules"]
        self.assertEqual(activator.prepare_update(base, queue), {})


if __name__ == "__main__":
    unittest.main()
