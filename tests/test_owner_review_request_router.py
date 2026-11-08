"""Regression tests: protected PRs get a nominal review request, common PRs do not."""
import unittest
from scripts.ops.request_owner_pr_review import decision, requires_owner

SHA = "a" * 40


def pr(*, draft=False, reviewers=None, labels=None, head=SHA):
    return {"state": "open", "draft": draft,
            "base": {"ref": "main"},
            "head": {"sha": head, "repo": {"full_name": "Vivaliz-site/site-shopvivaliz"}},
            "requested_reviewers": reviewers if reviewers is not None else [],
            "labels": labels if labels is not None else []}


class OwnerReviewRouterTests(unittest.TestCase):
    def test_specific_owner_grant_requests_review(self):
        files = [{"filename": "security/owner-approvals/9999.json"}]
        self.assertTrue(requires_owner(files, []))
        self.assertEqual(decision(pr(), files, [], SHA), "request_owner_review")

    def test_owner_policy_directive_requires_review(self):
        files = [{"filename": "AGENTS.md", "patch": "@@ -1 +1 @@\n+<!-- OWNER_DIRECTIVES_20261105 -->"}]
        self.assertEqual(decision(pr(), files, [], SHA), "request_owner_review")

    def test_common_docs_do_not_request_human(self):
        files = [{"filename": "docs/knowledge/host-access.md", "patch": "@@ -1 +1 @@\n+normal host docs link"}]
        self.assertEqual(decision(pr(), files, [], SHA), "common_pr_no_owner_review")

    def test_owner_label_opt_in(self):
        files = [{"filename": "docs/ops/manual.md", "patch": "+a"}]
        self.assertTrue(requires_owner(files, [{"name": "owner-review-required"}]))

    def test_draft_protected_pr_not_silently_ignored(self):
        files = [{"filename": "security/owner-approvals/9999.json"}]
        self.assertEqual(decision(pr(draft=True), files, [], SHA), "protected_pr_must_be_ready")

    def test_existing_request_is_idempotent(self):
        files = [{"filename": "security/requests/security.md"}]
        self.assertEqual(decision(pr(reviewers=[{"login": "shopvivaliz-human"}]), files, [], SHA),
                         "owner_already_requested")

    def test_review_exact_sha_is_not_requested_twice(self):
        files = [{"filename": "scripts/ops/owner_approved_merge_queue.py"}]
        reviews = [{"user": {"login": "shopvivaliz-human"}, "state": "APPROVED",
                    "commit_id": SHA, "submitted_at": "2026-11-05T15:00:00Z"}]
        self.assertEqual(decision(pr(), files, reviews, SHA), "owner_already_reviewed_current_head")
        self.assertEqual(decision(pr(), files, [{**reviews[0], "commit_id": "b" * 40}], SHA),
                         "request_owner_review")

    def test_conflicting_head_fails_closed(self):
        with self.assertRaises(ValueError):
            decision(pr(head="b" * 40), [{"filename": "security/requests/x.md"}], [], SHA)

    def test_uninspectable_owner_policy_fails_closed(self):
        files = [{"filename": "AGENTS.md", "patch": None}]
        self.assertTrue(requires_owner(files, []))

    def test_desktop_gui_lock_restoration_requires_dual_review(self):
        files = [{
            "filename": "remote-control-mcp/server.py",
            "patch": "@@ -10,3 +10,4 @@\n MUTATING_ACTIONS = {\n+    \"desktop_click\": True,\n }",
        }]
        self.assertTrue(requires_owner(files, []))
        # Neither requested -> request review
        self.assertEqual(decision(pr(), files, [], SHA), "request_owner_review")
        # Only human requested -> still need operator
        self.assertEqual(
            decision(pr(reviewers=[{"login": "shopvivaliz-human"}]), files, [], SHA),
            "request_owner_review",
        )
        # Only operator requested -> still need human
        self.assertEqual(
            decision(pr(reviewers=[{"login": "fredmourao-ai"}]), files, [], SHA),
            "request_owner_review",
        )
        # Both requested -> idempotent
        self.assertEqual(
            decision(
                pr(reviewers=[{"login": "shopvivaliz-human"}, {"login": "fredmourao-ai"}]),
                files,
                [],
                SHA,
            ),
            "owner_already_requested",
        )
        # Both reviewed current head -> not requested twice
        reviews = [
            {
                "user": {"login": "shopvivaliz-human"},
                "state": "APPROVED",
                "commit_id": SHA,
                "submitted_at": "2026-11-08T18:00:00Z",
            },
            {
                "user": {"login": "fredmourao-ai"},
                "state": "APPROVED",
                "commit_id": SHA,
                "submitted_at": "2026-11-08T18:05:00Z",
            },
        ]
        self.assertEqual(
            decision(pr(), files, reviews, SHA),
            "owner_already_reviewed_current_head",
        )
        # One reviewed on head, one requested -> considered handled
        self.assertEqual(
            decision(pr(reviewers=[{"login": "fredmourao-ai"}]), files, [reviews[0]], SHA),
            "owner_already_requested",
        )


if __name__ == "__main__":
    unittest.main()
