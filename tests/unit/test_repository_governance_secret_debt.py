from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "repository-governance.yml"


class RepositoryGovernanceSecretDebtTests(unittest.TestCase):
    def test_pr_compares_secret_debt_against_base(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("secret-audit-base", text)
        self.assertIn("git worktree add --detach", text)
        self.assertIn("compare_secret_audit_reports.py", text)
        self.assertIn('--base-report "$base_dir/artifacts/secret-references/report.json"', text)
        self.assertIn('--head-report "artifacts/secret-references/report.json"', text)
        self.assertIn('if [ "$EVENT_NAME" = "pull_request" ]', text)

    def test_non_pr_secret_audit_remains_fail_closed(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn('exit "$status"', text)
        self.assertIn("python scripts/maintenance/audit_secret_references.py", text)

    def test_secret_debt_logic_has_no_fail_open_shortcuts(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertNotIn("|| true", text)
        self.assertNotIn("set +e", text)
        self.assertNotIn("\n            exit 0\n", text)


if __name__ == "__main__":
    unittest.main()
