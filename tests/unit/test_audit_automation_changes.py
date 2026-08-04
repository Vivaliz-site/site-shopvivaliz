from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE_PATH = (
    Path(__file__).resolve().parents[2]
    / "scripts"
    / "maintenance"
    / "audit_automation_changes.py"
)
SPEC = importlib.util.spec_from_file_location(
    "audit_automation_changes", MODULE_PATH
)
assert SPEC and SPEC.loader
AUDIT = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = AUDIT
SPEC.loader.exec_module(AUDIT)


class AutomationChangeAuditTests(unittest.TestCase):
    def scan(
        self, path: str, lines: list[tuple[int, str]], status: str = "M"
    ):
        with patch.object(AUDIT, "added_lines", return_value=lines):
            return AUDIT.scan_file("base", "head", status, path)

    def test_blocks_required_workflow_removal(self):
        findings = AUDIT.scan_file(
            "base",
            "head",
            "D",
            ".github/workflows/agents-hourly-deep-audit.yml",
        )
        self.assertEqual(
            [item.rule for item in findings],
            ["critical_workflow_removed"],
        )
        self.assertEqual(findings[0].severity, "critical")

    def test_blocks_broad_git_add(self):
        findings = self.scan(
            "scripts/ai/runner.py", [(10, "git add -A")]
        )
        self.assertIn("broad_git_add", {item.rule for item in findings})

    def test_blocks_simulated_completion(self):
        findings = self.scan(
            "scripts/ai/runner.py",
            [(10, "simulated: true"), (11, "status = 'completed'")],
        )
        self.assertIn(
            "simulated_completion", {item.rule for item in findings}
        )

    def test_blocks_queue_completion_without_evidence(self):
        findings = self.scan(
            "scripts/ai/queue.py",
            [
                (20, "task['status'] = 'completed'"),
                (21, "queue.save(task)"),
            ],
        )
        rules = {item.rule for item in findings}
        self.assertIn("queue_completion_without_evidence", rules)
        self.assertIn("queue_mutation_without_evidence", rules)

    def test_allows_completion_with_verifiable_evidence(self):
        findings = self.scan(
            "scripts/ai/queue.py",
            [
                (20, "task['status'] = 'completed'"),
                (21, "queue.save(task)"),
                (22, "task['artifact'] = artifact"),
                (23, "task['commit_sha'] = commit_sha"),
                (24, "task['verification'] = readback"),
            ],
        )
        rules = {item.rule for item in findings}
        self.assertNotIn("queue_completion_without_evidence", rules)
        self.assertNotIn("queue_mutation_without_evidence", rules)

    def test_allows_retired_blocker_without_queue_mutation(self):
        findings = self.scan(
            "scripts/autonomous-continuous-cycle.py",
            [
                (20, '"status": "blocked",'),
                (21, '"queue_modified": False,'),
                (22, '"reason": "legacy selector retired",'),
                (23, '"replacement": "api/agent/real-work-orchestrator.php",'),
            ],
        )
        rules = {item.rule for item in findings}
        self.assertNotIn("queue_completion_without_evidence", rules)
        self.assertNotIn("queue_mutation_without_evidence", rules)

    def test_blocks_printed_execution_without_runner(self):
        findings = self.scan(
            "agent-bridge/worker.py",
            [(10, "print('Executando comando de teste: php -l index.php')")],
        )
        self.assertIn(
            "printed_execution_without_execution",
            {item.rule for item in findings},
        )

    def test_blocks_web_triggered_code_mutation(self):
        findings = self.scan(
            "admin/update.php",
            [
                (
                    1,
                    "$url = 'https://raw.githubusercontent.com/org/repo/main/index.php';",
                ),
                (2, "$body = file_get_contents($url);"),
                (3, "file_put_contents('../index.php', $body);"),
            ],
        )
        self.assertIn(
            "web_triggered_code_mutation",
            {item.rule for item in findings},
        )

    def test_blocks_private_group_write_permission(self):
        findings = self.scan(
            ".github/workflows/health.yml",
            [
                (
                    10,
                    "sudo install -d -g www-data -m 2770 storage/private",
                )
            ],
        )
        self.assertIn(
            "unsafe_private_directory_permission",
            {item.rule for item in findings},
        )

    def test_detector_does_not_match_its_own_rules(self):
        findings = self.scan(
            "scripts/maintenance/audit_automation_changes.py",
            [(1, "git push origin main --force"), (2, "git add -A")],
        )
        self.assertEqual(findings, [])

    def test_redacts_secret_excerpt(self):
        secret = "ghp_" + ("1" * 30)
        findings = self.scan(
            "config/runtime.yml", [(3, f"token: {secret}")]
        )
        credential = next(
            item for item in findings if item.rule == "credential_exposed"
        )
        self.assertNotIn(secret, credential.excerpt)
        self.assertIn("REDACTED_SECRET", credential.excerpt)

    def test_task_filename_is_not_mistaken_for_openai_key(self):
        findings = self.scan(
            "docs/status-task-a-very-long-ordinary-filename.md",
            [(1, "docs/status-task-a-very-long-ordinary-filename.md")],
            status="A",
        )
        self.assertNotIn(
            "credential_exposed", {item.rule for item in findings}
        )

    def test_standalone_openai_key_shape_remains_blocked(self):
        secret = "sk-" + ("a" * 32)
        findings = self.scan(
            "config/runtime.yml", [(3, f"token: {secret}")]
        )
        credential = next(
            item for item in findings if item.rule == "credential_exposed"
        )
        self.assertNotIn(secret, credential.excerpt)

    def test_prefixed_openai_key_shape_remains_blocked(self):
        secret = "sk-" + ("a" * 32)
        for prefix in ("backup-", "_"):
            findings = self.scan(
                "config/runtime.yml",
                [(3, f"token: {prefix}{secret}")],
            )
            self.assertIn(
                "credential_exposed", {item.rule for item in findings}
            )


if __name__ == "__main__":
    unittest.main()
