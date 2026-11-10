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
        files = [{"filename": n, "status": "modified", "patch": "@@ -1 +1 @@\n-antigo\n+atualizado"} for n in names]
        return GATE.decide(files, [] if reviews is None else reviews, head, pr_author=pr_author)

    def test_normal_technical_pr_remains_autonomous(self):
        for path in ("apps/shopvivaliz-web-agent/src/browser.mjs", "docs/ops/notes.md"):
            self.assertEqual(self.decide([path]), (True, "non_protected_pr"))

    def test_edicoes_comuns_nao_exigem_dupla_revisao_por_caminho(self):
        caminhos = (
            "security/owner-approvals/3539.json",
            "security/requests/correcao.md", ".github/CODEOWNERS",
            ".github/workflows/mandatory-validation-gate.yml",
            "scripts/quality/verify_owner_protected_pr_review.py",
            "AGENTS.md", "CLAUDE.md", "scripts/agent_task_state.py",
            "docs/knowledge/agent-rules.md", "docs/knowledge/host-access.md",
        )
        for caminho in caminhos:
            with self.subTest(caminho=caminho):
                arquivo = {"filename": caminho, "status": "modified",
                           "patch": "@@ -1 +1 @@\n-linha antiga\n+Atualização de texto e correção técnica"}
                self.assertFalse(GATE.requires_dual_approval([arquivo]))
                self.assertEqual(GATE.decide([arquivo], [], HEAD), (True, "non_protected_pr"))

    def test_politica_restritiva_nova_exige_ambas_as_revisoes(self):
        casos = (
            {"filename": "AGENTS.md",
             "patch": "@@ -1 +1 @@\n+> É proibido executar comandos externos pelos agentes"},
            {"filename": "docs/knowledge/agent-rules.md",
             "patch": "@@ -1 +1 @@\n+> Fica proibido utilizar ferramenta administrativa"},
            {"filename": ".github/workflows/mandatory-validation-gate.yml",
             "patch": "@@ -1 +1 @@\n+    BLOCKED_COMMANDS = ['curl']"},
            {"filename": "scripts/ops/owner_approved_merge_queue.py",
             "patch": "@@ -1 +1 @@\n+    assert_runtime_lock(lease, token, 'deploy')"},
        )
        human = review(login="shopvivaliz-human")
        operator = review(login="fredmourao-ai")
        for f in casos:
            with self.subTest(caminho=f["filename"]):
                self.assertTrue(GATE.requires_dual_approval([f]))
                self.assertFalse(GATE.decide([f], [human], HEAD)[0])
                self.assertFalse(GATE.decide([f], [operator], HEAD)[0])
                self.assertTrue(GATE.decide([f], [human, operator], HEAD)[0])

    def test_revisores_nao_podem_ser_autores_da_pr_protegida(self):
        f = {"filename": "remote-control-mcp/server.py",
             "patch": "@@ -1 +1 @@\n+    BLOCKED_COMMANDS = ['sh']"}
        reviews = [review(login="shopvivaliz-human"), review(login="fredmourao-ai")]
        for autor in ("shopvivaliz-human", "fredmourao-ai"):
            self.assertEqual(GATE.decide([f], reviews, HEAD, pr_author=autor),
                             (False, "protected_author_must_be_independent"))

    def test_diff_ausente_gera_erro_de_classificacao_sem_revisao_automatica(self):
        f = {"filename": "AGENTS.md", "status": "modified"}
        self.assertEqual(GATE.decide([f], [], HEAD), (False, "pr_text_patch_unavailable"))
        removido = {"filename": "AGENTS.md", "status": "removed"}
        self.assertEqual(GATE.decide([removido], [], HEAD), (True, "non_protected_pr"))

    def test_remocao_de_trava_e_marcador_isolado_nao_criam_nova_trava(self):
        f = {"filename": "remote-control-mcp/server.py",
             "patch": "@@ -1 +0 @@\n-    BLOCKED_COMMANDS = ['sh']"}
        self.assertFalse(GATE.requires_dual_approval([f]))
        marcador = {"filename": "docs/knowledge/agent-rules.md",
                    "patch": "@@ -1 +1 @@\n+<!-- ALL_LOCK_INSERTION_DUAL_APPROVAL_20261108 -->"}
        self.assertFalse(GATE.requires_dual_approval([marcador]))

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


    def test_configuracoes_que_criam_barreiras_exigem_dupla_revisao(self):
        casos = (
            (".github/CODEOWNERS", "+* @shopvivaliz-human"),
            (".github/workflows/gate.yml", "+    permissions: read-all"),
            (".github/workflows/gate.yml", "+    required_approving_review_count: 2"),
            (".github/workflows/gate.yml", "+    if: false"),
            ("security/rules.json", "+    disabled: true"),
            ("remote-control-mcp/server.py", "+    raise PermissionError('operation forbidden')"),
        )
        aprovadores = [review(login="shopvivaliz-human"),
                       review(login="fredmourao-ai")]
        for caminho, adicao in casos:
            with self.subTest(caminho=caminho, adicao=adicao):
                arquivo = {"filename": caminho, "status": "modified",
                           "patch": "@@ -1,0 +1 @@\n" + adicao}
                self.assertTrue(GATE.requires_dual_approval([arquivo]))
                self.assertFalse(GATE.decide([arquivo], aprovadores[:1], HEAD)[0])
                self.assertTrue(GATE.decide([arquivo], aprovadores, HEAD)[0])


    def test_permission_restrictions_and_relaxations_are_compared_by_effect(self):
        cases = (
            ("permissions: write-all", "permissions: read-all", True),
            ("permissions: read-all", "permissions: write-all", False),
            ("permissions:\n  contents: write", "permissions:\n  contents: read", True),
            ("permissions:\n  contents: read", "permissions:\n  contents: write", False),
        )
        for old, new, expected in cases:
            with self.subTest(old=old, new=new):
                old_lines = old.split("\n")
                new_lines = new.split("\n")
                patch = "@@ -1,%d +1,%d @@\n%s\n%s" % (
                    len(old_lines), len(new_lines),
                    "\n".join("-" + line for line in old_lines),
                    "\n".join("+" + line for line in new_lines),
                )
                f = {"filename": ".github/workflows/gate.yml", "status": "modified", "patch": patch}
                self.assertEqual(GATE.requires_dual_approval([f]), expected)

    def test_required_approval_count_compares_increases_and_decreases(self):
        cases = (
            ("required_approving_review_count: 1", "required_approving_review_count: 2", True),
            ("required_approving_review_count: 2", "required_approving_review_count: 1", False),
            (None, "required_approving_review_count: 2", True),
            ("required_approving_review_count: 2", None, False),
        )
        for old, new, expected in cases:
            with self.subTest(old=old, new=new):
                removed = "" if old is None else "-" + old + "\n"
                added = "" if new is None else "+" + new + "\n"
                f = {"filename": ".github/workflows/gate.yml", "status": "modified",
                     "patch": "@@ -1 +1 @@\n" + removed + added}
                self.assertEqual(GATE.requires_dual_approval([f]), expected)

    def test_test_file_that_adds_required_gate_is_not_blanket_exempt(self):
        f = {"filename": "tests/test_gate_policy.py", "status": "modified",
             "patch": "@@ -1,0 +1,1 @@\n+required_status_checks: [security-audit]\n"}
        self.assertTrue(GATE.requires_dual_approval([f]))

    def test_test_file_without_new_gate_remains_common(self):
        f = {"filename": "tests/test_gate_policy.py", "status": "modified",
             "patch": "@@ -1,0 +1,1 @@\n+assert result == expected\n"}
        self.assertFalse(GATE.requires_dual_approval([f]))


    def test_unchanged_unparsed_permission_context_is_not_a_restriction(self):
        file = {"filename": ".github/workflows/ci.yml", "status": "modified",
                "patch": "@@ -1,2 +1,3 @@\n permissions: inherit\n name: CI\n+description: unchanged permission settings\n"}
        self.assertFalse(GATE.requires_dual_approval([file]))

    def test_unchanged_dynamic_approval_count_context_is_not_a_restriction(self):
        file = {"filename": ".github/workflows/ci.yml", "status": "modified",
                "patch": "@@ -1,2 +1,3 @@\n required_approving_review_count: computed\n name: CI\n+description: update only\n"}
        self.assertFalse(GATE.requires_dual_approval([file]))

    def test_markdown_permission_example_is_not_an_executable_gate(self):
        file = {"filename": "docs/guides/actions-examples.md", "status": "modified",
                "patch": "@@ -1,0 +1,3 @@\n+```yaml\n+permissions: read-all\n+```\n"}
        self.assertFalse(GATE.requires_dual_approval([file]))


if __name__ == "__main__":
    unittest.main()
