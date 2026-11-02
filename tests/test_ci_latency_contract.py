from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / ".github" / "workflows"


class CiLatencyContractTests(unittest.TestCase):
    def test_completion_enforcer_scopes_workflow_run_to_triggering_pr(self) -> None:
        text = (WF / "pr-completion-enforcer.yml").read_text(encoding="utf-8")
        self.assertIn("pr-completion-enforcer-${{ github.event.workflow_run.pull_requests[0].number", text)
        self.assertIn("|| github.event_name }}", text)
        self.assertIn("TRIGGER_PR", text)
        self.assertIn("TARGET_PR", text)
        self.assertIn("target_pr_count", text)
        self.assertIn("stale_gate_event", text)

    def test_scheduled_finalizer_limits_self_hosted_recovery_to_one_pr(self) -> None:
        text = (WF / "pr-completion-enforcer.yml").read_text(encoding="utf-8")
        gate = text.split("  enforce:", 1)[0]
        self.assertIn("GITHUB_EVENT_NAME", gate)
        self.assertIn("schedule_target_budget=1", gate)
        self.assertIn("if [[ \"$GITHUB_EVENT_NAME\" == 'schedule' ]]", gate)
        self.assertIn("pulls?state=open&base=main&per_page=100", gate)
        self.assertNotIn("schedule_no_self_hosted_sweep=true", gate)
        enforce = text.split("  enforce:", 1)[1]
        self.assertIn("TARGET_PR", enforce)
        self.assertIn("workflow_dispatch", enforce)
        before_manual_sweep = enforce.split("elif [[ \"$GITHUB_EVENT_NAME\" == 'workflow_dispatch' ]]", 1)[0]
        self.assertNotIn("pulls?state=open&base=main", before_manual_sweep)

    def test_completion_enforcer_keeps_fanout_wait_on_hosted_runner(self) -> None:
        text = (WF / "pr-completion-enforcer.yml").read_text(encoding="utf-8")
        hosted = text.split("  enforce:", 1)[0]
        self.assertIn("runs-on: ubuntu-latest", hosted)
        self.assertIn("fanout_settle_attempt", hosted)
        self.assertIn("master-production-pipeline.yml", hosted)

    def test_stale_pr_sync_avoids_native_bot_update_branch(self) -> None:
        workflow = (WF / "pr-completion-enforcer.yml").read_text(encoding="utf-8")
        self.assertNotIn("update-branch", workflow)
        self.assertNotIn("ai-stale-pr-repair.yml", workflow)
        self.assertIn("external_auth_branch_sync=true", workflow)
        self.assertIn("bash trusted/scripts/pr_conflict_vm_heal.sh", workflow)
        self.assertIn("bash trusted/scripts/merge_green_pr_via_vm.sh", workflow)
        self.assertNotIn("REMOTE_HEAL_SCRIPT", workflow)
        self.assertNotIn("ssh -o BatchMode", workflow)

    def test_fanout_settles_only_impacted_workflows_before_finalizer(self) -> None:
        workflow = (WF / "pr-completion-enforcer.yml").read_text(encoding="utf-8")
        hosted = workflow.split("  enforce:", 1)[0]
        self.assertIn("mapfile -t changed_files", hosted)
        self.assertIn("trusted/scripts/pr_gate_scope.py", hosted)
        self.assertIn("settle_required=('Repository Governance'", hosted)
        self.assertIn("fanout_applicable_required=", hosted)
        self.assertIn("fanout_scope_unavailable=fail_closed", hosted)
        self.assertNotIn("settle_required=(\\n              'Quality Gate'", hosted)
        from scripts.pr_gate_scope import required_specialized_gates
        self.assertEqual(required_specialized_gates(["scripts/pr_conflict_vm_heal.sh"]), ["PR Policy Enforcement"])
        self.assertEqual(required_specialized_gates(["docs/ops/unrelated-note.md"]), [])

    def test_clean_stale_pr_sync_does_not_require_gemini(self) -> None:
        healer = (ROOT / "scripts" / "pr_conflict_vm_heal.sh").read_text(encoding="utf-8")
        merge_pos = healer.index('if [[ "$merge_clean" -eq 0 ]]')
        credential_pos = healer.index("--credential-preflight")
        self.assertGreater(credential_pos, merge_pos)

    def test_branch_sync_never_publishes_with_actions_bot_token(self) -> None:
        # A GITHUB_TOKEN-authenticated branch push creates action_required CI
        # approval runs; the independent GitHub technical identity avoids it.
        healer = (ROOT / "scripts" / "pr_conflict_vm_heal.sh").read_text(encoding="utf-8")
        self.assertIn("independent_actor=", healer)
        self.assertIn('[[ "$independent_actor" == \'fredmourao-ai\' ]]', healer)
        self.assertIn("fail 'independent_github_auth_unavailable'", healer)
        self.assertIn("fail 'independent_github_actor_not_approved'", healer)
        self.assertIn("env -u GH_TOKEN -u GITHUB_TOKEN -u GIT_ASKPASS -u SSH_ASKPASS", healer)
        self.assertIn("gh auth setup-git", healer)
        self.assertIn('    git push origin "HEAD:refs/heads/', healer)
        self.assertNotIn('\n  git push origin "HEAD:refs/heads/', healer)
        self.assertIn("remote PR branch moved before publication", healer)
        self.assertIn("publication would not be fast-forward", healer)

    def test_technical_merger_never_uses_actions_bot_for_postmerge(self) -> None:
        finalizer = (ROOT / "scripts" / "merge_green_pr_via_vm.sh").read_text(encoding="utf-8")
        self.assertIn("technical_gh()", finalizer)
        self.assertIn("env -u GH_TOKEN -u GITHUB_TOKEN -u GIT_ASKPASS -u SSH_ASKPASS", finalizer)
        self.assertIn("independent_technical_actor_required", finalizer)
        self.assertIn("result=\"$(technical_gh api --method PUT", finalizer)
        self.assertNotIn("result=\"$(gh api --method PUT", finalizer)
        self.assertIn("owner-protected PR must not be autonomously merged", finalizer)
        self.assertIn("PR head does not contain current main", finalizer)

    def test_backend_finalizer_reuses_independent_technical_auth(self) -> None:
        workflow = (WF / "pr-completion-enforcer.yml").read_text(encoding="utf-8")
        enforce = workflow.split("  enforce:", 1)[1]
        self.assertIn("runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]", enforce)
        self.assertIn("pr_finalizer_host=always-free-arm-1787907847-26", enforce)
        self.assertIn("trusted/.github/scripts/ai_conflict_resolver.py", enforce)
        self.assertNotIn("pr_finalizer_host=marina-a1-4c24g", enforce)

    def test_backend_healer_uses_preexisting_technical_identity_not_runner_bot(self) -> None:
        workflow = (WF / "pr-conflict-auto-healer.yml").read_text(encoding="utf-8")
        heal = workflow.split("  heal:", 1)[1]
        self.assertIn("runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]", heal)
        self.assertIn("independent_backend_operator_verified=true", heal)
        self.assertNotIn("SHOPVIVALIZ_VM_SSH_KEY", heal)
        self.assertNotIn("ssh -o BatchMode", heal)
        healer = (ROOT / "scripts" / "pr_conflict_vm_heal.sh").read_text()
        self.assertIn("flock -n 9", healer)
        self.assertIn("conflict_ai_route=free_loopback_ollama", healer)
        self.assertIn("owner_protected_pr_head_cannot_be_mutated", healer)
        self.assertLess(healer.index("owner_protected_pr_head_cannot_be_mutated"), healer.index("git clone -q"))

    def test_owner_grants_skipped_before_head_mutation_in_both_workflows(self) -> None:
        completion = (WF / "pr-completion-enforcer.yml").read_text(encoding="utf-8")
        enforce = completion.split("  enforce:", 1)[1]
        self.assertLess(enforce.index("pr_${pr}_owner_review_only=true"), enforce.index('relation="$(gh api'))
        conflict = (WF / "pr-conflict-auto-healer.yml").read_text(encoding="utf-8")
        heal = conflict.split("  heal:", 1)[1]
        self.assertIn("pr_${pr}_owner_protected_no_autosync=true", heal)
        self.assertLess(heal.index("pr_${pr}_owner_protected_no_autosync=true"), heal.index("bash trusted/scripts/pr_conflict_vm_heal.sh"))

    def test_history_integrity_avoids_blob_downloads(self) -> None:
        workflow = (WF / "history-integrity.yml").read_text(encoding="utf-8")
        validator = (ROOT / "scripts" / "maintenance" / "validate_sanitized_history.py").read_text(encoding="utf-8")
        self.assertIn("filter: blob:none", workflow)
        self.assertIn("--filter=blob:none", validator)

    def test_history_integrity_uses_batched_ref_checks(self) -> None:
        text = (ROOT / "scripts" / "maintenance" / "validate_sanitized_history.py").read_text(encoding="utf-8")
        self.assertIn("existing_commit_shas", text)
        self.assertIn("branches_containing_root", text)
        self.assertIn("tags_containing_root", text)
        self.assertIn("batched_ref_contains", text)
        self.assertNotIn("def is_ancestor", text)
        self.assertNotIn("def commit_count", text)

    def test_conflict_healer_reserves_oracle_only_after_hosted_preflight(self) -> None:
        text = (WF / "pr-conflict-auto-healer.yml").read_text(encoding="utf-8")
        preflight = text.split("  heal:", 1)[0]
        self.assertIn("runs-on: ubuntu-latest", preflight)
        self.assertIn("needs: preflight", text)
        self.assertIn("needs.preflight.outputs.should_heal", text)


if __name__ == "__main__":
    unittest.main()
