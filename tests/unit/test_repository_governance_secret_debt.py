from pathlib import Path
import sys
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

    def test_replayed_pr_governance_keeps_base_and_head_context(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("workflow_dispatch:\n    inputs:", text)
        self.assertIn("base_sha:", text)
        self.assertIn("head_sha:", text)
        self.assertIn("REPLAY_BASE_SHA", text)
        self.assertIn("REPLAY_HEAD_SHA", text)
        self.assertIn('PR_CONTEXT="true"', text)

    def test_non_pr_secret_audit_remains_fail_closed(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn('exit "$status"', text)
        self.assertIn("python scripts/maintenance/audit_secret_references.py", text)

    def test_secret_debt_logic_has_no_fail_open_shortcuts(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertNotIn("|| true", text)
        self.assertNotIn("set +e", text)
        self.assertNotIn("\n            exit 0\n", text)


class SecretAuditDiffScopeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import importlib.util
        spec = importlib.util.spec_from_file_location(
            "audit_secret_references", ROOT / "scripts" / "maintenance" / "audit_secret_references.py")
        cls.audit = importlib.util.module_from_spec(spec)
        sys.modules[spec.name] = cls.audit
        spec.loader.exec_module(cls.audit)
        cls.files = [ROOT / "api" / "cart" / "add.php", ROOT / "docs" / "x.md", ROOT / "scripts" / "y.py"]

    def test_application_only_diff_scans_only_changed_files(self):
        files, scope = self.audit.scoped_files(self.files, ["api/cart/add.php", "docs/x.md"])
        self.assertEqual(scope, "pull_request_diff")
        self.assertEqual([p.relative_to(ROOT).as_posix() for p in files], ["api/cart/add.php", "docs/x.md"])

    def test_governance_change_forces_full_scan(self):
        for changed in (
            ["api/cart/add.php", ".github/workflows/x.yml"],
            ["scripts/maintenance/audit_secret_references.py"],
            ["security/owner-approvals/1.json"],
        ):
            files, scope = self.audit.scoped_files(self.files, changed)
            self.assertEqual(scope, "repository", changed)
            self.assertEqual(len(files), len(self.files), changed)

    def test_unknown_or_empty_diff_scans_everything(self):
        for changed in (None, []):
            files, scope = self.audit.scoped_files(self.files, changed)
            self.assertEqual(scope, "repository")
            self.assertEqual(len(files), len(self.files))


if __name__ == "__main__":
    unittest.main()
