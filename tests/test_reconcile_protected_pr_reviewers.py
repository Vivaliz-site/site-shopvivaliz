"""Regression tests for selective, non-gating owner-review reconciliation."""
from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts/reconcile_protected_pr_reviewers.py"
spec = importlib.util.spec_from_file_location("protected_review_sync", MODULE_PATH)
sync = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sync)

SHA1 = "a" * 40
SHA2 = "b" * 40
REPO = "Vivaliz-site/site-shopvivaliz"
GRANT_REPO = "Vivaliz-site/solange-rolla-consultorio"


class Policy:
    @staticmethod
    def sensitive_path(path):
        return path.endswith("PriceWorker.php")

    @staticmethod
    def should_scan_content(path):
        return not path.startswith("docs/")

    @staticmethod
    def sensitive_content(lines):
        return ["commercial mutation"] if any("UPDATE price" in line for line in lines) else []


def pr(*, sha=SHA1, draft=False, reviewers=(), state="open",
       number=31, author="fredmourao-ai"):
    return {
        "number": number, "state": state, "draft": draft,
        "head": {"sha": sha}, "user": {"login": author},
        "requested_reviewers": [{"login": user} for user in reviewers],
    }


class FakeGitHub:
    def __init__(self, *, files, item=None, reviews=None):
        self.item = item or pr()
        self.files = files
        self.reviews = reviews or []
        self.posts = []
        self.changed_on_reload = False

    def pages(self, path):
        if path.endswith("/files"):
            return self.files
        if path.endswith("/reviews"):
            return self.reviews
        raise AssertionError(path)

    def get(self, path):
        if self.changed_on_reload:
            changed = dict(self.item)
            changed["head"] = {"sha": SHA2}
            return changed
        return self.item

    def post(self, path, payload):
        self.posts.append((path, payload))
        self.item["requested_reviewers"] = [{"login": sync.OWNER}]
        return {}


class ProtectedReviewerTests(unittest.TestCase):
    def run_one(self, client, repo=GRANT_REPO, **kwargs):
        return sync.review_one(client, repo, dict(client.item), policy=Policy, **kwargs)

    def test_grant_requests_only_the_owner_and_readback_confirms(self):
        gh = FakeGitHub(files=[{"filename": "security/owner-approvals/200.json", "patch": "+new"}])
        out = self.run_one(gh)
        self.assertEqual(out["action"], "requested_verified")
        self.assertEqual(gh.posts[0][1], {"reviewers": [sync.OWNER]})

    def test_retry_is_idempotent(self):
        gh = FakeGitHub(files=[{"filename": "security/owner-approvals/200.json"}])
        self.assertEqual(self.run_one(gh)["action"], "requested_verified")
        self.assertEqual(self.run_one(gh)["action"], "already_requested")
        self.assertEqual(len(gh.posts), 1)

    def test_exact_sha_owner_approval_means_no_pending_request(self):
        gh = FakeGitHub(
            files=[{"filename": "security/owner-approvals/200.json"}],
            reviews=[{"id": 14, "user": {"login": sync.OWNER},
                      "state": "APPROVED", "commit_id": SHA1}],
        )
        self.assertEqual(self.run_one(gh)["action"], "already_approved_current_head")
        self.assertEqual(gh.posts, [])

    def test_stale_approval_requires_new_request(self):
        gh = FakeGitHub(
            files=[{"filename": "security/owner-approvals/200.json"}],
            reviews=[{"id": 14, "user": {"login": sync.OWNER},
                      "state": "APPROVED", "commit_id": SHA2}],
        )
        self.assertEqual(self.run_one(gh)["action"], "requested_verified")

    def test_later_owner_comment_invalidates_older_approval(self):
        gh = FakeGitHub(
            files=[{"filename": "security/owner-approvals/200.json"}],
            reviews=[
                {"id": 14, "user": {"login": sync.OWNER},
                 "state": "APPROVED", "commit_id": SHA1},
                {"id": 15, "user": {"login": sync.OWNER},
                 "state": "COMMENTED", "commit_id": SHA1},
            ],
        )
        self.assertEqual(self.run_one(gh)["action"], "requested_verified")

    def test_draft_is_not_silently_promoted_or_requested(self):
        gh = FakeGitHub(files=[{"filename": "security/owner-approvals/200.json"}],
                        item=pr(draft=True))
        self.assertEqual(self.run_one(gh)["action"], "protected_draft_requires_owner_triage")
        self.assertEqual(gh.posts, [])

    def test_ordinary_code_never_requests_human_review(self):
        gh = FakeGitHub(files=[{"filename": "src/GenericRefactor.php", "patch": "+return true;"}])
        self.assertEqual(self.run_one(gh)["action"], "ordinary_no_review_required")
        self.assertEqual(gh.posts, [])

    def test_commercial_sensitive_path_matches_existing_policy(self):
        gh = FakeGitHub(files=[{"filename": "includes/PriceWorker.php", "patch": "+data();"}])
        self.assertEqual(self.run_one(gh, repo=REPO)["action"], "requested_verified")

    def test_commercial_sensitive_added_lines_matches_existing_policy(self):
        gh = FakeGitHub(files=[{"filename": "includes/adapter.php", "patch": "@@ -1 +1 @@\n+UPDATE price = 5"}])
        self.assertEqual(self.run_one(gh, repo=REPO)["action"], "requested_verified")

    def test_missing_sensitive_diff_is_not_assumed_unprotected(self):
        gh = FakeGitHub(files=[{"filename": "includes/adapter.php", "patch": None}])
        with self.assertRaises(sync.GitHubError):
            self.run_one(gh, repo=REPO)

    def test_new_head_during_scan_cannot_be_targeted(self):
        gh = FakeGitHub(files=[{"filename": "security/owner-approvals/200.json"}])
        gh.changed_on_reload = True
        self.assertEqual(self.run_one(gh)["action"], "changed_recheck_next_cycle")
        self.assertEqual(gh.posts, [])

    def test_author_cannot_be_requested_to_review_own_pr(self):
        gh = FakeGitHub(files=[{"filename": "security/owner-approvals/200.json"}],
                        item=pr(author=sync.OWNER))
        self.assertEqual(self.run_one(gh)["action"], "owner_is_author_manual_triage")
        self.assertEqual(gh.posts, [])

    def test_dry_run_never_mutates(self):
        gh = FakeGitHub(files=[{"filename": "security/owner-approvals/200.json"}])
        self.assertEqual(self.run_one(gh, dry_run=True)["action"], "would_request")
        self.assertEqual(gh.posts, [])

    def test_large_plaintext_agent_docs_do_not_force_commercial_review(self):
        gh = FakeGitHub(files=[{"filename": "AGENTS.md", "patch": None},
                               {"filename": "CLAUDE.md", "patch": None}])
        self.assertEqual(self.run_one(gh, repo=REPO)["action"], "ordinary_no_review_required")
        self.assertEqual(gh.posts, [])

    def test_site_ownership_paths_are_protected(self):
        for path in sync.OWNER_OWNED_SITE:
            with self.subTest(path=path):
                self.assertEqual(
                    sync.protection_reason(REPO, [{"filename": path}], Policy),
                    "superficie_owner_codeowners",
                )

    def test_unrelated_security_doc_is_not_automatically_protected(self):
        self.assertIsNone(sync.protection_reason(
            REPO, [{"filename": "docs/security/suggestions.md", "patch": "+note"}], Policy
        ))

    def test_workflow_never_executes_pr_code_or_admin_pat_on_pr_events(self):
        workflow = (ROOT / ".github/workflows/reconcile-protected-pr-reviewers.yml").read_text()
        self.assertIn("pull_request_target:", workflow)
        self.assertIn("continue-on-error: true", workflow)
        self.assertIn("ref: main", workflow)
        self.assertIn("github.token", workflow)
        self.assertIn("SHOPVIVALIZ_GITHUB_ADMIN_TOKEN", workflow)
        self.assertNotIn("ref: " + "$" + "{{ github.event.pull_request.head.sha }}", workflow)
        self.assertNotIn("pull_request:", workflow)


if __name__ == "__main__":
    unittest.main()
