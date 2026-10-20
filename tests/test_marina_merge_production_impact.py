#!/usr/bin/env python3
"""Regression: first-parent production-impact classification for merge commits.

No production access, no credentials, no network. Each test creates a temporary
repository and asks the checked-in scripts to classify synthetic commits.
"""
from __future__ import annotations

import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DIFF_SCRIPT = ROOT / "scripts" / "production-impact-changed-paths.sh"
CLASSIFIER = ROOT / "scripts" / "should-deploy-production.sh"


class MergeProductionImpactTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="shopvivaliz-merge-impact-")
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        self.git("init", "-q", "-b", "main")
        self.git("config", "user.name", "test")
        self.git("config", "user.email", "test@example.invalid")
        self.write("index.php", "<?php echo 'original';")
        self.git("add", "--all")
        self.git("commit", "-qm", "base")

    def git(self, *args):
        return subprocess.check_output(
            ["git", *args], cwd=self.repo, stderr=subprocess.STDOUT,
            text=True, timeout=10,
        ).strip()

    def write(self, path: str, content: str):
        file = self.repo / path
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(content, encoding="utf8")

    def changes(self, sha: str):
        result = subprocess.run(
            ["bash", str(DIFF_SCRIPT), sha], cwd=self.repo,
            capture_output=True, text=True, timeout=10, check=True,
        )
        return result.stdout

    def deploy(self, changed_paths: str):
        result = subprocess.run(
            ["bash", str(CLASSIFIER)], input=changed_paths,
            capture_output=True, text=True, timeout=10, check=True,
        )
        return result.stdout.strip()

    def merge_change(self, file_path, content):
        self.git("checkout", "-qb", "feature")
        self.write(file_path, content)
        self.git("add", "--all")
        self.git("commit", "-qm", "feature")
        self.git("checkout", "-q", "main")
        self.write("README.md", "on first parent")
        self.git("add", "--all")
        self.git("commit", "-qm", "main-only")
        self.git("merge", "--no-ff", "-qm", "merge feature", "feature")
        sha = self.git("rev-parse", "HEAD")
        self.assertEqual(len(self.git("rev-list", "--parents", "-n", "1", sha).split()), 3)
        return sha

    def test_docs_only_merge_does_not_wait_for_production_deployment(self):
        sha = self.merge_change("docs/quality/AUDIT_STATUS.md", "scope = storefront")
        paths = self.changes(sha)
        self.assertEqual(paths.splitlines(), ["docs/quality/AUDIT_STATUS.md"])
        self.assertEqual(self.deploy(paths), "false")

    def test_web_source_merge_still_requires_real_deployment(self):
        sha = self.merge_change("index.php", "<?php echo 'updated';")
        paths = self.changes(sha)
        self.assertEqual(paths.splitlines(), ["index.php"])
        self.assertEqual(self.deploy(paths), "true")

    def test_root_commit_does_not_masquerade_as_no_change(self):
        root_sha = self.git("rev-list", "--max-parents=0", "HEAD")
        paths = self.changes(root_sha)
        self.assertIn("index.php", paths.splitlines())
        self.assertEqual(self.deploy(paths), "true")

    def test_linear_documentation_change_remains_nondeploy(self):
        self.write("docs/quality/REPORT.md", "docs only")
        self.git("add", "--all")
        self.git("commit", "-qm", "docs")
        paths = self.changes(self.git("rev-parse", "HEAD"))
        self.assertEqual(paths.splitlines(), ["docs/quality/REPORT.md"])
        self.assertEqual(self.deploy(paths), "false")

    def test_reject_malformed_and_missing_commit(self):
        for sha in ("not-a-sha", "0" * 40):
            result = subprocess.run(
                ["bash", str(DIFF_SCRIPT), sha], cwd=self.repo,
                capture_output=True, text=True, timeout=10, check=False,
            )
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(result.stdout, "")

    def test_both_workflow_callers_use_verified_first_parent_classifier(self):
        for path in (
            ROOT / ".github/workflows/repair-catalog-hard-quality-pending.yml",
            ROOT / ".github/workflows/runtime-env-keyset-lock.yml",
        ):
            code = path.read_text(encoding="utf-8")
            self.assertIn("bash scripts/production-impact-changed-paths.sh", code)
            self.assertNotIn("git diff-tree --no-commit-id --name-only -r", code)


if __name__ == "__main__":
    unittest.main()
