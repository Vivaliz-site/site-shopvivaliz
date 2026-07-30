from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE_PATH = Path(__file__).resolve().parents[2] / "scripts" / "maintenance" / "audit_automation_changes.py"
SPEC = importlib.util.spec_from_file_location("audit_automation_changes", MODULE_PATH)
assert SPEC and SPEC.loader
AUDIT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(AUDIT)


class AutomationChangeAuditTests(unittest.TestCase):
    def scan(self, path: str, lines: list[tuple[int, str]], status: str = "M"):
        with patch.object(AUDIT, "added_lines", return_value=lines):
            return AUDIT.scan_file("base", "head", status, path)

    def test_blocks_hourly_audit_removal(self):
        findings = AUDIT.scan_file(
            "base", "head", "D", ".github/workflows/agents-hourly-deep-audit.yml"
        )
        self.assertEqual([item.rule for item in findings], ["hourly_audit_removed"])
        self.assertEqual(findings[0].severity, "critical")

    def test_blocks_broad_git_add(self):
        findings = self.scan("scripts/ai/runner.py", [(10, "git add -A")])
        self.assertIn("broad_git_add", {item.rule for item in findings})

    def test_blocks_simulated_completion(self):
        findings = self.scan(
            "scripts/ai/runner.py",
            [(10, "# simulate processing"), (11, "status = 'completed'")],
        )
        self.assertIn("simulated_completion", {item.rule for item in findings})

    def test_blocks_queue_completion_without_evidence(self):
        findings = self.scan(
            "scripts/ai/queue.py",
            [(20, "task['status'] = 'completed'"), (21, "queue.save(task)")],
        )
        self.assertIn("queue_completion_without_evidence", {item.rule for item in findings})

    def test_allows_completion_with_verifiable_evidence(self):
        findings = self.scan(
            "scripts/ai/queue.py",
            [
                (20, "task['status'] = 'completed'"),
                (21, "queue.save(task)"),
                (22, "task['artifact'] = artifact"),
                (23, "task['commit_sha'] = commit_sha"),
            ],
        )
        self.assertNotIn("queue_completion_without_evidence", {item.rule for item in findings})

    def test_detector_does_not_match_its_own_rules(self):
        findings = self.scan(
            "scripts/maintenance/audit_automation_changes.py",
            [(1, "git push origin main --force"), (2, "git add -A")],
        )
        self.assertEqual(findings, [])

    def test_redacts_secret_excerpt(self):
        secret = "ghp_123456789012345678901234567890"
        findings = self.scan("config/runtime.yml", [(3, f"token: {secret}")])
        credential = next(item for item in findings if item.rule == "credential_exposed")
        self.assertNotIn(secret, credential.excerpt)
        self.assertIn("REDACTED_SECRET", credential.excerpt)


if __name__ == "__main__":
    unittest.main()
