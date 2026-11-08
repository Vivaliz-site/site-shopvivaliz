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
    def decide(self, names, reviews=None, head=HEAD, pr_author=None):
        files = [{"filename": n, "status": "added"} for n in names]
        return GATE.decide(files, [] if reviews is None else reviews, head, pr_author=pr_author)

    def test_normal_technical_pr_remains_autonomous(self):
        for path in ("apps/shopvivaliz-web-agent/src/browser.mjs", "docs/ops/notes.md"):
            self.assertEqual(self.decide([path]), (True, "non_protected_pr"))

    def test_security_governance_rules_and_tasks_require_dual_approval(self):
        protected_targets = (
            "security/owner-approvals/3539.json",
            "security/requests/web-agent-owner-grant-corrective-20261102.md",
            ".github/CODEOWNERS",
            ".github/workflows/mandatory-validation-gate.yml",
            "scripts/quality/verify_owner_protected_pr_review.py",
            "AGENTS.md",
            "CLAUDE.md",
            "scripts/agent_task_state.py",
        )
        human = review(login="shopvivaliz-human", commit=HEAD)
        operator = review(login="fredmourao-ai", commit=HEAD)

        for path in protected_targets:
            with self.subTest(path=path):
                # Sem aprovacao -> bloqueia
                res, reason = self.decide([path])
                self.assertFalse(res)
                self.assertEqual(reason, "dual_owner_approval_missing")

                # Somente uma conta -> bloqueia
                self.assertFalse(self.decide([path], [human])[0])
                self.assertFalse(self.decide([path], [operator])[0])

                # Ambas aprovam -> passa
                res, reason = self.decide([path], [human, operator])
                self.assertTrue(res)
                self.assertEqual(reason, "dual_owner_review_approved_exact_head")

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

    def test_desktop_gui_lock_patch_requires_dual_approval(self):
        malicious_file = {
            "filename": "remote-control-mcp/server.py",
            "status": "modified",
            "patch": "@@ -2550,6 +2550,7 @@ MUTATING_RUNTIME_ACTIONS = {\n+    \"desktop_click\": \"desktop_click\",\n }",
        }
        human_review = review(login="shopvivaliz-human", state="APPROVED", commit=HEAD)
        operator_review = review(login="fredmourao-ai", state="APPROVED", commit=HEAD)

        # Sem reviews -> bloqueia
        res, reason = GATE.decide([malicious_file], [], HEAD)
        self.assertFalse(res)
        self.assertEqual(reason, "dual_owner_approval_missing")

        # Somente shopvivaliz-human aprovou -> bloqueia por falta do operador
        res, reason = GATE.decide([malicious_file], [human_review], HEAD)
        self.assertFalse(res)
        self.assertEqual(reason, "dual_approval_missing_fredmourao-ai")

        # Somente fredmourao-ai aprovou -> bloqueia por falta do humano
        res, reason = GATE.decide([malicious_file], [operator_review], HEAD)
        self.assertFalse(res)
        self.assertEqual(reason, "dual_approval_missing_shopvivaliz-human")

        # AMBAS aprovaram no HEAD exato -> unblock
        res, reason = GATE.decide([malicious_file], [human_review, operator_review], HEAD)
        self.assertTrue(res)
        self.assertEqual(reason, "dual_owner_review_approved_exact_head")

        # fredmourao-ai com commit desatualizado -> bloqueia
        stale_operator = review(login="fredmourao-ai", state="APPROVED", commit=OTHER)
        res, reason = GATE.decide([malicious_file], [human_review, stale_operator], HEAD)
        self.assertFalse(res)
        self.assertEqual(reason, "approval_by_fredmourao-ai_not_bound_to_current_head")

        # shopvivaliz-human pediu mudancas -> bloqueia
        rejected_human = review(login="shopvivaliz-human", state="CHANGES_REQUESTED", commit=HEAD, at="2026-11-08T22:00:00Z")
        res, reason = GATE.decide([malicious_file], [rejected_human, operator_review], HEAD)
        self.assertFalse(res)
        self.assertEqual(reason, "latest_review_by_shopvivaliz-human_not_approved")

    def test_desktop_lock_all_patterns_trigger_dual_approval(self):
        patterns_to_test = [
            "+    'desktop_open': 'desktop_open',",
            "+    'desktop_type': 'desktop_type',",
            "+    base._assert_runtime_mutation('desktop_click', args)",
            "+    runtime_lock.assert_runtime_lock(lease, token, 'desktop_click')",
            "+    assert_runtime_lock(lease, token, 'desktop_type')",
            "+    raise ValueError('runtime_lock_required')  # for desktop_click",
            "+    schema_props['desktop_click']['required'].append('runtime_lease_id')",
            "+    schema_props['desktop_type']['required'].append('runtime_fencing_token')",
        ]
        human = review(login="shopvivaliz-human", commit=HEAD)
        operator = review(login="fredmourao-ai", commit=HEAD)

        for line in patterns_to_test:
            with self.subTest(line=line):
                f = {"filename": "remote-control-mcp/server.py", "patch": f"@@ -1,1 +1,2 @@\n{line}\n"}
                # Sem aprovacao dupla -> deve falhar
                self.assertFalse(GATE.decide([f], [human], HEAD)[0])
                # Com aprovacao dupla -> passa
                self.assertTrue(GATE.decide([f], [human, operator], HEAD)[0])

    def test_general_lock_insertion_triggers_dual_approval(self):
        general_lock_lines = [
            "+    MUTATING_RUNTIME_ACTIONS.add('new_tool')",
            "+    MUTATING_ACTIONS.add('run_custom')",
            "+    MUTATING_ACTIONS += ['exec_something']",
            "+    MUTATING_ACTIONS = {'command': True}",
            "+    assert_runtime_lock(lease, token, 'any_action')",
            "+    _assert_runtime_mutation('any_action', args)",
            "+    assert_runtime_lease(lease)",
            "+    raise RuntimeError('action_blocked: permission denied')",
            "+    raise ValueError('command_blocked: disallowed')",
            "+    BLOCKED_COMMANDS = ['sh', 'bash']",
            "+    RESTRICTED_TOOLS = {'custom_tool'}",
            "+    DISALLOWED_OPERATIONS = ['kill']",
            "+    <!-- ALL_LOCK_INSERTION_DUAL_APPROVAL_20261108 -->",
            "+    # nova trava de seguranca para agentes",
        ]
        human = review(login="shopvivaliz-human", commit=HEAD)
        operator = review(login="fredmourao-ai", commit=HEAD)

        for line in general_lock_lines:
            with self.subTest(line=line):
                f = {"filename": "remote-control-mcp/handlers.py", "patch": f"@@ -1,1 +1,2 @@\n{line}\n"}
                self.assertTrue(GATE.requires_lock_dual_approval([f]))
                # Sem aprovacao dupla -> bloqueia
                res, reason = GATE.decide([f], [human], HEAD)
                self.assertFalse(res)
                self.assertEqual(reason, "dual_approval_missing_fredmourao-ai")
                # Com aprovacao dupla -> aprova
                res, reason = GATE.decide([f], [human, operator], HEAD)
                self.assertTrue(res)
                self.assertEqual(reason, "dual_owner_review_approved_exact_head")


if __name__ == "__main__":
    unittest.main()
