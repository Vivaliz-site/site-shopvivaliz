"""Deterministic negatives: human exact-HEAD owner approval and protected queue."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "owner_review_queue", ROOT / "scripts/quality/owner_review_queue.py")
MOD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MOD)
HEAD = "a" * 40


def make_inputs():
    pr = {
        "number": 9999, "state": "open", "draft": False,
        "base": {"ref": "main", "repo": {"full_name": MOD.REPO}},
        "head": {"ref": "security/approved", "sha": HEAD,
                 "repo": {"full_name": MOD.REPO}},
        "labels": [{"name": "no-auto-merge"}],
    }
    reviews = [{"user": {"login": MOD.OWNER_LOGIN},
                "state": "APPROVED", "commit_id": HEAD,
                "submitted_at": "2026-11-02T19:25:01Z"}]
    files = [{"filename": "security/requests/owner-review-merge-queue.md"}]
    rules = [{"type": "merge_queue", "parameters": {}}]
    runs = [{"name": name, "head_sha": HEAD, "status": "completed",
             "conclusion": "success", "created_at": "2026-11-02T20:00:00Z",
             "run_attempt": 1} for name in MOD.REQUIRED_WORKFLOWS]
    return pr, reviews, files, rules, runs


class OwnerQueueTests(unittest.TestCase):
    def check(self, mutate=None, queued=False):
        args = list(make_inputs())
        if mutate is not None:
            mutate(args)
        return MOD.decision(*args, queued=queued)

    def test_human_approval_and_green_checks_allows_queue_despite_label(self):
        self.assertEqual(self.check(), (True, "approved_head_green_queue_ready"))

    def test_active_merge_queue_is_mandatory(self):
        self.assertEqual(self.check(lambda x: x[3].clear())[1], "merge_queue_not_enabled")

    def test_branch_cannot_be_rewritten_after_approval(self):
        self.assertEqual(
            self.check(lambda x: x[0]["head"].update(sha="b" * 40))[1],
            "human_approval_not_on_current_head")

    def test_missing_or_revoked_owner_review_denied(self):
        self.assertFalse(self.check(lambda x: x[1].clear())[0])
        self.assertEqual(self.check(lambda x: x[1].append({
            "user": {"login": MOD.OWNER_LOGIN},
            "state": "DISMISSED", "commit_id": HEAD,
            "submitted_at": "2026-11-02T19:30:01Z"}))[1],
            "human_approval_not_on_current_head")

    def test_agent_approval_never_substitutes_human(self):
        self.assertFalse(self.check(
            lambda x: x[1][0]["user"].update(login=MOD.OPERATOR_LOGIN))[0])

    def test_technical_pr_never_queued(self):
        self.assertEqual(self.check(
            lambda x: x[2][0].update(filename="src/ordinary.py"))[1],
            "technical_pr_not_owner_queue")

    def test_explicit_runtime_holds_are_hard_stops(self):
        for label in ("blocked-by-runtime-lease", "blocked-external"):
            with self.subTest(label=label):
                self.assertEqual(self.check(
                    lambda x: x[0]["labels"].append({"name": label}))[1],
                    "runtime_hold")

    def test_required_checks_must_be_current_head_and_green(self):
        self.assertFalse(self.check(
            lambda x: x[4][0].update(conclusion="failure"))[0])
        self.assertFalse(self.check(
            lambda x: x[4][0].update(head_sha="c" * 40))[0])

    def test_in_progress_without_success_conclusion_is_rejected(self):
        self.assertFalse(self.check(
            lambda x: x[4][0].update(status="in_progress", conclusion=None))[0])

    def test_github_expressions_have_literal_string_comparisons(self):
        wf=(ROOT/".github/workflows/owner-human-review-autoqueue.yml").read_text()
        self.assertIn("number || '"+ "sweep' }}",wf)
        self.assertIn("github.event_name != 'pull_request_review'",wf)
        self.assertIn("github.event.review.state == 'approved'",wf)
        self.assertIn("github.event.review.user.login == 'shopvivaliz-human'",wf)

    def test_enqueue_mutation_atomically_binds_current_approved_sha(self):
        from unittest.mock import patch
        observed = []
        def valid_call(args):
            observed.append(args)
            return {"data":{"enqueuePullRequest":{"mergeQueueEntry":{"id":"queue_entry"}}}}
        with patch.object(MOD,"github",side_effect=valid_call):
            MOD.enqueue("PR_known", HEAD)
        request = observed[0]
        self.assertIn("expectedHeadOid:$head",request[2])
        self.assertIn("head="+HEAD,request)
        with patch.object(MOD,"github",return_value={"data":{"enqueuePullRequest":{"mergeQueueEntry":None}}}):
            with self.assertRaises(ValueError):
                MOD.enqueue("PR_known",HEAD)
        with self.assertRaises(ValueError):
            MOD.enqueue("PR_known","stale_or_invalid")

    def test_queue_idempotency(self):
        self.assertEqual(self.check(queued=True)[1], "already_in_merge_queue")

    def test_fork_draft_other_base_denied(self):
        self.assertFalse(self.check(lambda x: x[0].update(draft=True))[0])
        self.assertFalse(self.check(lambda x: x[0]["head"]["repo"].update(
            full_name="outside/repo"))[0])
        self.assertFalse(self.check(lambda x: x[0]["base"].update(ref="other"))[0])

    def test_all_queue_mutation_paths_always_owner_protected(self):
        import importlib.util
        spec = importlib.util.spec_from_file_location(
            "canonical_owner_review_guard",
            ROOT / "scripts/quality/verify_owner_protected_pr_review.py")
        guard = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(guard)
        sensitive = (
            ".github/workflows/owner-human-review-autoqueue.yml",
            ".github/workflows/owner-review-merge-queue-enable.yml",
            ".github/workflows/repository-governance.yml",
            "config/owner-review-merge-queue.json",
            "scripts/quality/owner_review_queue.py",
            "scripts/quality/enable_owner_merge_queue.py",
            "scripts/validate-owner-pr-grant.py",
        )
        for path in sensitive:
            with self.subTest(path=path):
                self.assertTrue(MOD.protected_path(path))
                self.assertTrue(guard.is_protected_path(path))

    def test_required_owner_gate_and_verifier_protected_in_both_policies(self):
        import importlib.util
        spec=importlib.util.spec_from_file_location(
            "trusted_verifier", ROOT/"scripts/quality/verify_owner_protected_pr_review.py")
        guard=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(guard)
        for protected in (
            ".github/workflows/mandatory-validation-gate.yml",
            "scripts/quality/verify_owner_protected_pr_review.py",
        ):
            with self.subTest(protected=protected):
                self.assertTrue(MOD.protected_path(protected))
                self.assertTrue(guard.is_protected_path(protected))

    def test_owner_review_event_strings_quoted_for_real_github_actions(self):
        text=(ROOT/".github/workflows/owner-human-review-autoqueue.yml").read_text()
        self.assertIn("github.event_name != 'pull_request_review'",text)
        self.assertIn("github.event.review.state == 'approved'",text)
        self.assertIn("github.event.review.user.login == 'shopvivaliz-human'",text)
        self.assertIn("github.event.pull_request.number || 'sweep'",text)

    def test_workflow_trust_syntax(self):
        text = (ROOT / ".github/workflows/owner-human-review-autoqueue.yml").read_text()
        self.assertIn("ref: main", text)
        self.assertIn("persist-credentials: false", text)
        self.assertIn("runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]", text)
        self.assertIn("pull_request_review:", text)
        self.assertIn("gh api user --jq .login", text)


if __name__ == "__main__":
    unittest.main()
