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

    def test_files_do_not_require_dual_review_by_name_alone(self):
        ordinary = (
            "AGENTS.md", "CLAUDE.md", ".github/workflows/ci.yml",
            ".github/CODEOWNERS", "scripts/agent_task_state.py",
            "scripts/quality/verify_owner_protected_pr_review.py",
        )
        for path in ordinary:
            with self.subTest(path=path):
                files = [{"filename": path, "patch": "@@ -1 +1 @@\n+ajuste tecnico sem novos impedimentos"}]
                self.assertEqual(GATE.decide(files, [], HEAD), (True, "non_protected_pr"))

    def test_new_restrictive_changes_require_two_approvals(self):
        changed = (
            (".github/CODEOWNERS", "+/admin/ @shopvivaliz-human"),
            (".github/workflows/build.yml", "+if: github.actor == 'owner'"),
            ("AGENTS.md", "+Proibido executar comandos sem aprovação."),
            ("CLAUDE.md", "+Não pode executar workflow sem permissão."),
            ("scripts/agent_task_state.py", "+return False  # blocked execution"),
            ("remote-control-mcp/server.py", "+assert_runtime_lock(lease, token, 'desktop_click')"),
        )
        human = review(login="shopvivaliz-human")
        operator = review(login="fredmourao-ai")
        for path, line in changed:
            with self.subTest(path=path):
                files = [{"filename": path, "patch": "@@ -1 +1 @@\n" + line}]
                self.assertEqual(GATE.decide(files, [], HEAD), (False, "dual_owner_approval_missing"))
                self.assertFalse(GATE.decide(files, [human], HEAD)[0])
                self.assertFalse(GATE.decide(files, [operator], HEAD)[0])
                self.assertEqual(GATE.decide(files, [human, operator], HEAD),
                                 (True, "dual_owner_review_approved_exact_head"))

    def test_only_removing_or_relocating_existing_blocks_is_not_new_block(self):
        remove = {"filename": "remote-control-mcp/server.py",
                  "patch": "@@ -1 +1 @@\n-assert_runtime_lock(lease, token, 'desktop_click')"}
        self.assertEqual(GATE.decide([remove], [], HEAD), (True, "non_protected_pr"))
        move = {"filename": "remote-control-mcp/server.py",
                "patch": "@@ -1 +1 @@\n-assert_runtime_lock(lease, token, 'desktop_click')\n+assert_runtime_lock(lease, token, 'desktop_click')"}
        self.assertEqual(GATE.decide([move], [], HEAD), (True, "non_protected_pr"))

    def test_comments_and_tests_do_not_create_runtime_restrictions(self):
        for path in ("remote-control-mcp/server.py", "tests/test_locking.py"):
            for line in ("+# nova trava de seguranca para agentes",
                         "+<!-- ALL_LOCK_INSERTION_DUAL_APPROVAL_20261108 -->"):
                f = {"filename": path, "patch": "@@ -1 +1 @@\n" + line}
                self.assertFalse(GATE.requires_dual_approval([f]))

    def test_new_optional_fields_do_not_automatically_block_execution(self):
        innocuous = [
            "+config = {'runtime_lock_required': False}",
            "+runtime_lease_id = args.get('runtime_lease_id')",
            "+runtime_fencing_token = args.get('runtime_fencing_token')",
            "+MUTATING_RUNTIME_ACTIONS = existing_actions",
        ]
        for line in innocuous:
            with self.subTest(line=line):
                f = {"filename": "remote-control-mcp/server.py",
                     "patch": "@@ -1 +1 @@\n" + line}
                self.assertFalse(GATE.requires_dual_approval([f]))

    def test_no_author_exemption_for_dual_approval(self):
        files = [{"filename": "scripts/quality/verify_owner_protected_pr_review.py"}]
        approvals = [review(login="shopvivaliz-human"), review(login="fredmourao-ai")]
        for author in ("shopvivaliz-human", "fredmourao-ai"):
            with self.subTest(author=author):
                self.assertEqual(
                    GATE.decide(files, approvals, HEAD, pr_author=author),
                    (False, "protected_pr_requires_distinct_author"),
                )
        self.assertEqual(
            GATE.decide(files, approvals, HEAD, pr_author="github-actions[bot]"),
            (True, "dual_owner_review_approved_exact_head"),
        )

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
