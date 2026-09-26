from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / ".github" / "workflows"


class WorkflowLatencyBudgetTests(unittest.TestCase):
    def read(self, name: str) -> str:
        return (WF / name).read_text(encoding="utf-8")

    def test_completion_enforcer_has_single_workflow_run_signal(self) -> None:
        text = self.read("pr-completion-enforcer.yml")
        trigger = text.split("types: [completed]", 1)[0]
        self.assertIn("- Mandatory Validation Gate", trigger)
        self.assertNotIn("- Quality Gate", trigger)
        self.assertNotIn("- ShopVivaliz QA", trigger)
        self.assertNotIn("- Repository Governance", trigger)
        self.assertNotIn("- Policy Engine", trigger)
        self.assertNotIn("- Autonomy Boundary", trigger)
        self.assertNotIn("- History Integrity", trigger)
        self.assertNotIn("- Ecommerce Excellence Audit", trigger)
        self.assertNotIn("- PR Policy Enforcement", trigger)

    def test_history_integrity_is_not_on_every_pr(self) -> None:
        text = self.read("history-integrity.yml")
        header = text.split("permissions:", 1)[0]
        self.assertNotIn("pull_request:", header)

    def test_nonblocking_inventory_is_not_on_every_pr(self) -> None:
        text = self.read("test-inventory.yml")
        header = text.split("permissions:", 1)[0]
        self.assertNotIn("pull_request:", header)

    def test_hourly_deep_audit_is_not_duplicated_on_pr(self) -> None:
        text = self.read("agents-hourly-deep-audit.yml")
        header = text.split("permissions:", 1)[0]
        self.assertNotIn("pull_request:", header)

    def test_ai_conflict_resolver_is_path_scoped(self) -> None:
        text = self.read("ai-conflict-resolver.yml")
        header = text.split("permissions:", 1)[0]
        self.assertIn("pull_request:", header)
        self.assertIn("paths:", header)
        self.assertIn(".github/scripts/ai_conflict_resolver.py", header)

    def test_conflict_healer_uses_hosted_preflight_before_oracle_runner(self) -> None:
        text = self.read("pr-conflict-auto-healer.yml")
        self.assertIn("jobs:\n  preflight:", text)
        self.assertIn("needs: preflight", text)
        self.assertIn("needs.preflight.outputs.should_heal == 'true'", text)

    def test_finalizer_no_longer_requires_history_integrity(self) -> None:
        text = self.read("pr-completion-enforcer.yml")
        required = text.split("required=(", 1)[1].split(")", 1)[0]
        self.assertNotIn("History Integrity", required)
        self.assertIn("Mandatory Validation Gate", required)


if __name__ == "__main__":
    unittest.main()
