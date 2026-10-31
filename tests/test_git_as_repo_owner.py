import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'scripts/maintenance/git_as_repo_owner.py'


class RepoOwnerGitTests(unittest.TestCase):
    def test_missing_git_args_rejected(self):
        with tempfile.TemporaryDirectory() as root:
            (Path(root) / '.git').mkdir()
            result = subprocess.run([sys.executable, str(SCRIPT), '--repo', root], capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)

    def test_git_reads_repo_without_global_safe_directory(self):
        repo = Path(__file__).resolve().parents[1]
        result = subprocess.run([sys.executable, str(SCRIPT), '--repo', str(repo), '--', 'rev-parse', '--is-inside-work-tree'], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), 'true')

    def test_non_repository_rejected(self):
        with tempfile.TemporaryDirectory() as root:
            result = subprocess.run([sys.executable, str(SCRIPT), '--repo', root, '--', 'status'], capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)


if __name__ == '__main__':
    unittest.main()
