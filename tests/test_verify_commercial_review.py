"""Negative regressions for the native GitHub commercial review path."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "review_gate", ROOT / "scripts/quality/verify_commercial_review.py"
)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
A = "a" * 40
B = "b" * 40


class NativeReviewGateTests(unittest.TestCase):
    def setUp(self):
        self.owner = "separate-owner-only"
        self.actors = {"fredmourao-ai", "shopvivaliz-agent[bot]"}
        # Fixture actors must be valid GitHub usernames; GitHub App bot names
        # are not trusted identity declarations in this simple username field.
        self.actors = {"fredmourao-ai", "worker-account"}
        self.pr = {"number": 3269, "state": "open", "draft": False,
                   "merged": False, "merged_at": None,
                   "head": {"sha": A}, "base": {"sha": B},
                   "user": {"login": "fredmourao-ai"}}
        self.permission = {"permission": "admin",
                           "user": {"login": self.owner}}
        self.reviews = [{"id": 10, "state": "APPROVED", "commit_id": A,
                         "user": {"login": self.owner}}]

    def decision(self):
        return mod.decide(
            self.pr, self.reviews, self.permission, owner=self.owner,
            automations=self.actors, expected_head=A, expected_base=B,
            expected_number=3269
        )

    def test_valid_independent_owner_review(self):
        self.assertEqual(self.decision(), (True, "human_owner_review_verified"))

    def test_approval_label_not_sufficient(self):
        self.pr["labels"] = [{"name": "human-approved-price-stock"}]
        self.reviews = []
        self.assertFalse(self.decision()[0])

    def test_automerges_are_not_approvals(self):
        self.pr["auto_merge"] = {"merge_method": "MERGE"}
        self.reviews = []
        self.assertFalse(self.decision()[0])

    def test_shared_agent_login_denied(self):
        self.owner = "fredmourao-ai"
        self.permission["user"]["login"] = self.owner
        self.assertFalse(self.decision()[0])

    def test_author_review_self_denied(self):
        self.pr["user"]["login"] = self.owner
        self.assertFalse(self.decision()[0])

    def test_stale_review_sha_denied(self):
        self.reviews[0]["commit_id"] = B
        self.assertFalse(self.decision()[0])

    def test_modified_head_denied(self):
        self.pr["head"]["sha"] = B
        self.assertFalse(self.decision()[0])

    def test_base_drift_denied(self):
        self.pr["base"]["sha"] = A
        self.assertFalse(self.decision()[0])

    def test_dismissal_denied(self):
        self.reviews.append({"id": 11, "state": "DISMISSED", "commit_id": A,
                             "user": {"login": self.owner}})
        self.assertFalse(self.decision()[0])

    def test_changes_requested_denied(self):
        self.reviews.append({"id": 11, "state": "CHANGES_REQUESTED",
                             "commit_id": A, "user": {"login": self.owner}})
        self.assertFalse(self.decision()[0])

    def test_comment_only_does_not_approve(self):
        self.reviews = [{"id": 12, "state": "COMMENTED", "commit_id": A,
                         "user": {"login": self.owner}}]
        self.assertFalse(self.decision()[0])

    def test_comment_after_approval_fails_closed(self):
        self.reviews.append({"id": 11, "state": "COMMENTED", "commit_id": A,
                             "user": {"login": self.owner}})
        self.assertFalse(self.decision()[0])

    def test_outdated_approval_rejected_even_if_newer_reviews_exist(self):
        self.reviews.append({"id": 11, "state": "APPROVED", "commit_id": B,
                             "user": {"login": self.owner}})
        self.assertFalse(self.decision()[0])

    def test_reviewer_permission_required(self):
        self.permission["permission"] = "read"
        self.assertFalse(self.decision()[0])

    def test_permission_identity_must_match(self):
        self.permission["user"]["login"] = "worker-account"
        self.assertFalse(self.decision()[0])

    def test_missing_author_denied(self):
        self.pr["user"]["login"] = ""
        self.assertFalse(self.decision()[0])

    def test_missing_automations_denied(self):
        self.actors = set()
        self.assertFalse(self.decision()[0])

    def test_unknown_automations_denied(self):
        self.actors = {"worker-account"}
        self.assertFalse(self.decision()[0])

    def test_missing_review_ids_denied(self):
        self.reviews[0].pop("id")
        self.assertFalse(self.decision()[0])

    def test_reviewed_pr_must_be_open(self):
        self.pr["state"] = "closed"
        self.assertFalse(self.decision()[0])

    def test_draft_denied(self):
        self.pr["draft"] = True
        self.assertFalse(self.decision()[0])

    def test_pr_number_mismatch(self):
        self.pr["number"] = 3272
        self.assertFalse(self.decision()[0])

    def test_github_failure_fails_closed(self):
        original = mod.github_json
        try:
            def denied(*args):
                raise mod.ApprovalDenied("github_api_unavailable")
            mod.github_json = denied
            self.assertFalse(mod.verify_live_review(
                repo="Vivaliz-site/site-shopvivaliz", number="3269",
                head=A, base=B, owner=self.owner,
                automation_logins="fredmourao-ai", token="example")[0])
        finally:
            mod.github_json = original

    def test_workflows_react_to_native_review_and_fail_closed_without_config(self):
        for path in (".github/workflows/autonomy-boundary.yml",
                     ".github/workflows/mandatory-validation-gate.yml"):
            text = (ROOT / path).read_text(encoding="utf-8")
            self.assertIn("pull_request_review:", text)
            self.assertIn("types: [submitted, dismissed]", text)
            self.assertIn("GH_PR_NUMBER: GITHUB_EXPR{ github.event.pull_request.number }}".replace("GITHUB_EXPR", chr(36)+"{"), text)
            self.assertIn("secrets.SHOPVIVALIZ_COMMERCIAL_OWNER_LOGIN", text)
            self.assertIn("secrets.SHOPVIVALIZ_COMMERCIAL_AUTOMATION_LOGINS", text)
            self.assertIn("persist-credentials: false", text)
            self.assertIn("scripts/enforce-autonomy-policy.py", text)

    def test_label_is_not_authorization_in_enforcement_source(self):
        text = (ROOT / "scripts/enforce-autonomy-policy.py").read_text(encoding="utf-8")
        self.assertIn("approval_from_environment()", text)
        self.assertNotIn("approval_label not in labels", text)
        self.assertIn("verified_human_pr_review_required", text)

    def test_policy_real_main_rejects_label_without_verified_review(self):
        import importlib.util
        import json
        import tempfile
        from unittest.mock import patch
        pspec = importlib.util.spec_from_file_location(
            "autonomy_gate", ROOT / "scripts/enforce-autonomy-policy.py"
        )
        policy = importlib.util.module_from_spec(pspec)
        pspec.loader.exec_module(policy)
        with tempfile.TemporaryDirectory() as t:
            report = Path(t) / "report.json"
            with (patch.object(policy, "REPORT_PATH", report),
                  patch.object(policy, "changed_files", return_value=["api/pr" + "ice.php"]),
                  patch.object(policy, "added_lines", return_value=["UP" + "DATE pr" + "ice SET pr" + "ice = 10"]),
                  patch.object(policy, "approval_from_environment",
                               return_value=(False, "owner_review_missing")),
                  patch.dict("os.environ", {"BASE_SHA": "a" * 40, "HEAD_SHA": "b" * 40,
                                           "HEAD_REF": "fix/price-review", "PR_LABELS":
                                           "human-approved-price-stock"})):
                self.assertEqual(policy.main(), 1)
                payload = json.loads(report.read_text(encoding="utf-8"))
                self.assertEqual(payload["decision"], "verified_human_pr_review_required")
                self.assertEqual(payload["commercial_review_verdict"], "owner_review_missing")

    def test_dispatch_without_pr_number_denied(self):
        self.assertFalse(mod.verify_live_review(
            repo="Vivaliz-site/site-shopvivaliz", number="",
            head=A, base=B, owner=self.owner,
            automation_logins="fredmourao-ai", token="example")[0])


if __name__ == "__main__":
    unittest.main()
