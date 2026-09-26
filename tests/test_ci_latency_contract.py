from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / ".github" / "workflows"


class CiLatencyContractTests(unittest.TestCase):
    def test_completion_enforcer_scopes_workflow_run_to_triggering_pr(self) -> None:
        text = (WF / "pr-completion-enforcer.yml").read_text(encoding="utf-8")
        self.assertIn("pr-completion-enforcer-${{ github.event.workflow_run.pull_requests[0].number", text)
        self.assertIn("|| 'sweep' }}", text)
        self.assertIn("TRIGGER_PR", text)
        self.assertIn("TARGET_PR", text)
        self.assertIn("target_pr_count", text)
        self.assertIn("stale_gate_event", text)

    def test_completion_enforcer_keeps_fanout_wait_on_hosted_runner(self) -> None:
        text = (WF / "pr-completion-enforcer.yml").read_text(encoding="utf-8")
        hosted = text.split("  enforce:", 1)[0]
        self.assertIn("runs-on: ubuntu-latest", hosted)
        self.assertIn("fanout_settle_attempt", hosted)
        self.assertIn("master-production-pipeline.yml", hosted)

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
