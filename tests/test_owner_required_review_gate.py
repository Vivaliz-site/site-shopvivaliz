"""Security regression: owner approval must be proved in the required gate."""
import importlib.util
from pathlib import Path
import unittest

PATH = Path(__file__).resolve().parents[1] / "scripts/quality/verify_owner_protected_pr_review.py"
SPEC = importlib.util.spec_from_file_location("review_gate", PATH)
GATE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GATE)

HEAD = "a" * 40
OTHER = "b" * 40


def review(login="shopvivaliz-human", state="APPROVED", commit=HEAD, at="2026-11-02T19:00:00Z"):
    return {"user": {"login": login}, "state": state, "commit_id": commit, "submitted_at": at}


class OwnerRequiredReviewGateTests(unittest.TestCase):
    def decide(self, names, reviews=None, head=HEAD):
        files = [{"filename": n, "status": "added"} for n in names]
        return GATE.decide(files, [] if reviews is None else reviews, head)

    def test_normal_technical_pr_remains_autonomous(self):
        for path in ("apps/shopvivaliz-web-agent/src/browser.mjs", "docs/ops/notes.md"):
            self.assertEqual(self.decide([path]), (True, "non_protected_pr"))

    def test_security_and_gate_files_no_longer_require_human(self):
        for path in ("security/owner-approvals/3539.json",
                     "security/requests/web-agent-owner-grant-corrective-20261102.md",
                     ".github/CODEOWNERS",
                     ".github/workflows/mandatory-validation-gate.yml",
                     "scripts/quality/verify_owner_protected_pr_review.py"):
            with self.subTest(path=path):
                self.assertEqual(self.decide([path]), (True, "non_protected_pr"))

    def test_vm_deletion_and_price_changes_require_human(self):
        for path in ("scripts/ops/terminate-oci-instance.sh",
                     "deploy/oci/destroy-vm.sh",
                     "scripts/ops/vm-delete-old.py",
                     "includes/pricing-sync.php",
                     "api/catalog/price-update.php",
                     "includes/preco-tabela.php"):
            with self.subTest(path=path):
                self.assertEqual(self.decide([path])[0], False)

    def test_human_owner_exact_head_unblocks_only_protected_pr(self):
        self.assertEqual(self.decide(["includes/pricing-sync.php"],
                                     [review()]), (True, "owner_review_approved_exact_head"))
        self.assertEqual(self.decide(["includes/pricing-sync.php", "docs/other.md"],
                                     [review()])[0], True)

    def test_technical_actor_not_a_human_review(self):
        for login in ("fredmourao-ai", "github-actions[bot]", "another-user"):
            self.assertEqual(self.decide(["includes/pricing-sync.php"],
                                         [review(login=login)])[0], False)

    def test_stale_review_and_change_request_revokes_approval(self):
        self.assertEqual(self.decide(["includes/pricing-sync.php"],
                                     [review(commit=OTHER)])[0], False)
        approvals = [review(), review(state="CHANGES_REQUESTED",
                                      at="2026-11-02T19:02:00Z")]
        self.assertEqual(self.decide(["includes/pricing-sync.php"],
                                     approvals), (False, "latest_owner_review_not_approved"))

    def test_comments_do_not_override_owner_approval(self):
        approvals = [review(), review(state="COMMENTED", at="2026-11-02T19:05:00Z")]
        self.assertTrue(self.decide(["includes/pricing-sync.php"], approvals)[0])

    def test_invalid_or_incomplete_github_pages_fail_closed(self):
        self.assertEqual(GATE.decide([], [], HEAD)[0], False)
        self.assertEqual(GATE.decide([{"filename": "docs/a.md"}] * 100, [], HEAD)[0], False)
        self.assertEqual(GATE.decide([{"filename": "includes/pricing-a.php"}], [review()] * 100, HEAD)[0], False)
        self.assertEqual(GATE.decide([{"foo": "bar"}], [], HEAD)[0], False)
        self.assertEqual(GATE.decide([{"filename": "includes/pricing-a.php"}], [{"user": {}}], HEAD)[0], False)
        self.assertEqual(self.decide(["includes/pricing-a.php"], [review(at="")])[0], False)
        self.assertEqual(self.decide(["includes/pricing-a.php"], [review()], head=OTHER)[0], False)


if __name__ == "__main__":
    unittest.main()
