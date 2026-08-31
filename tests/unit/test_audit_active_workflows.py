from __future__ import annotations

import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE_PATH = Path(__file__).resolve().parents[2] / "scripts" / "maintenance" / "audit_active_workflows.py"
SPEC = importlib.util.spec_from_file_location("audit_active_workflows", MODULE_PATH)
assert SPEC and SPEC.loader
AUDIT = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = AUDIT
SPEC.loader.exec_module(AUDIT)


class ActiveWorkflowPolicyTests(unittest.TestCase):
    def audit(self, name: str, content: str):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / ".github" / "workflows" / name
            path.parent.mkdir(parents=True)
            path.write_text(content, encoding="utf-8")
            with patch.object(AUDIT, "ROOT", root):
                return AUDIT.audit_workflow(path)

    def test_allows_read_only_workflow_with_required_artifact(self):
        findings = self.audit(
            "audit.yml",
            """name: Audit
on:
  schedule:
    - cron: '0 * * * *'
permissions:
  contents: read
jobs:
  audit:
    runs-on: ubuntu-latest
    steps:
      - run: python audit.py
      - uses: actions/upload-artifact@v4
        with:
          path: artifacts/
          if-no-files-found: error
""",
        )
        self.assertEqual(findings, [])

    def test_blocks_automatic_write_and_push(self):
        findings = self.audit(
            "sync.yml",
            """name: Sync
on:
  schedule:
    - cron: '0 * * * *'
permissions:
  contents: write
jobs:
  sync:
    runs-on: ubuntu-latest
    steps:
      - run: git push origin generated
""",
        )
        rules = {item.rule for item in findings}
        self.assertIn("workflow_push", rules)
        self.assertIn("automatic_write_workflow", rules)

    def test_blocks_optional_evidence_and_suppressed_failure(self):
        findings = self.audit(
            "health.yml",
            """name: Health
on:
  workflow_dispatch:
jobs:
  health:
    runs-on: ubuntu-latest
    steps:
      - run: check-health
        continue-on-error: true
      - uses: actions/upload-artifact@v4
        with:
          path: reports/
          if-no-files-found: warn
""",
        )
        rules = {item.rule for item in findings}
        self.assertIn("continue_on_error", rules)
        self.assertIn("optional_evidence", rules)

    def test_blocks_production_push_trigger(self):
        findings = self.audit(
            "shopee-production.yml",
            """name: Production
on:
  workflow_dispatch:
  push:
    branches: [main]
permissions:
  contents: read
jobs:
  apply:
    runs-on: ubuntu-latest
    steps:
      - run: python apply.py
""",
        )
        self.assertIn("production_push_trigger", {item.rule for item in findings})


    def test_manual_issue_permission_is_not_an_automatic_trigger(self):
        findings = self.audit(
            "manual.yml",
            """name: Manual
on:
  workflow_dispatch:
permissions:
  contents: read
  issues: write
jobs:
  report:
    runs-on: ubuntu-latest
    steps:
      - run: gh issue comment 1 --body ok
""",
        )
        self.assertNotIn("automatic_write_workflow", {item.rule for item in findings})

    def test_real_issue_trigger_with_write_is_automatic(self):
        findings = self.audit(
            "event.yml",
            """name: Event
on:
  issues:
    types: [opened]
permissions:
  issues: write
jobs:
  report:
    runs-on: ubuntu-latest
    steps:
      - run: gh issue comment 1 --body ok
""",
        )
        self.assertIn("automatic_write_workflow", {item.rule for item in findings})

    def test_blocks_destructive_reset(self):
        findings = self.audit(
            "reset.yml",
            """name: Reset
on:
  workflow_dispatch:
jobs:
  x:
    runs-on: ubuntu-latest
    steps:
      - run: git reset --hard origin/main
""",
        )
        self.assertIn("destructive_git", {item.rule for item in findings})


if __name__ == "__main__":
    unittest.main()
