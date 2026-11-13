"""Regressao da recuperacao da auditoria por falha do resumo, sem workflow adicional."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
AUDIT = (ROOT / ".github/workflows/agents-hourly-deep-audit.yml").read_text()
SUMMARY = (ROOT / ".github/workflows/hourly-summary.yml").read_text()
WATCHDOG = (ROOT / ".github/workflows/agents-audit-schedule-watchdog.yml").read_text()
RUNNER = "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-audit]"

class AuditScheduleRecoveryTest(unittest.TestCase):
    def test_fallback_is_only_a_failed_main_summary(self):
        self.assertIn("workflows: ['Hourly Summary Report']", AUDIT)
        self.assertIn("types: [completed]", AUDIT)
        self.assertIn("branches: [main]", AUDIT)
        self.assertIn("github.event.workflow_run.conclusion == 'failure'", AUDIT)
        self.assertIn("github.event.workflow_run.head_branch == 'main'", AUDIT)

    def test_does_not_cancel_running_audits(self):
        self.assertIn("cancel-in-progress: false", AUDIT)

    def test_uses_preexisting_unbilled_runner(self):
        for workflow in (AUDIT, SUMMARY, WATCHDOG):
            self.assertIn(RUNNER, workflow)
            self.assertNotIn("runs-on: ubuntu-latest", workflow)

    def test_freshness_checks_are_not_weakened(self):
        self.assertIn("maximum_audit_age = timedelta(minutes=150)", SUMMARY)
        self.assertIn("const maximumAgeMinutes = 390", WATCHDOG)

if __name__ == "__main__":
    unittest.main()
