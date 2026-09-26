from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
ENFORCER = ROOT / ".github" / "workflows" / "pr-completion-enforcer.yml"
HISTORY_WORKFLOW = ROOT / ".github" / "workflows" / "history-integrity.yml"
HISTORY_VALIDATOR = ROOT / "scripts" / "maintenance" / "validate_sanitized_history.py"


class PrCompletionLatencyContractTests(unittest.TestCase):
    def test_enforcer_has_no_fixed_debounce_sleep(self) -> None:
        text = ENFORCER.read_text(encoding="utf-8")
        self.assertNotIn("sleep 20", text)
        self.assertIn("target_pr", text)
        self.assertIn("required_gates_ready", text)

    def test_enforcer_scopes_workflow_run_to_triggering_pr(self) -> None:
        text = ENFORCER.read_text(encoding="utf-8")
        self.assertIn("github.event.workflow_run.pull_requests[0].number", text)
        self.assertIn("TARGET_PR", text)
        self.assertIn("target_prs", text)

    def test_enforcer_concurrency_is_not_global_for_all_prs(self) -> None:
        text = ENFORCER.read_text(encoding="utf-8")
        self.assertIn(
            "pr-completion-enforcer-${{ github.event.workflow_run.pull_requests[0].number || 'sweep' }}",
            text,
        )
        self.assertNotIn("group: pr-completion-enforcer\n", text)

    def test_history_integrity_avoids_blob_downloads(self) -> None:
        workflow = HISTORY_WORKFLOW.read_text(encoding="utf-8")
        validator = HISTORY_VALIDATOR.read_text(encoding="utf-8")
        self.assertIn("filter: blob:none", workflow)
        self.assertIn("--filter=blob:none", validator)

    def test_history_integrity_uses_batched_ancestry_checks(self) -> None:
        text = HISTORY_VALIDATOR.read_text(encoding="utf-8")
        self.assertIn("branches_containing_root", text)
        self.assertIn("existing_commit_shas", text)
        self.assertNotIn("\"merge-base\",\n        \"--is-ancestor\"", text)
        self.assertNotIn("\"rev-list\", \"--count\"", text)


    def test_conflict_healer_reserves_oracle_only_for_real_candidates(self) -> None:
        workflow = (ROOT / ".github" / "workflows" / "pr-conflict-auto-healer.yml").read_text(encoding="utf-8")
        self.assertIn("conflict-preflight", workflow)
        self.assertIn("needs: conflict-preflight", workflow)
        self.assertIn("needs.conflict-preflight.outputs.should_heal == 'true'", workflow)
        self.assertIn("TARGET_PRS", workflow)
        self.assertIn("mergeable", workflow)


if __name__ == "__main__":
    unittest.main()
