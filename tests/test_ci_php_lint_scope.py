"""Exercise the real governance lint phase against isolated Git histories."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class PhpLintScopeTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.git('init', '-q')
        self.git('config', 'user.name', 'Lint test')
        self.git('config', 'user.email', 'lint@example.invalid')
        self.write('unchanged.php', '<?php echo 1;')
        self.write('with space.php', '<?php echo 2;')
        self.git('add', '.')
        self.git('commit', '-qm', 'base')
        self.git('update-ref', 'refs/remotes/origin/main', 'HEAD')
        self.write('with space.php', '<?php echo 3;')
        self.write('new.php', '<?php echo 4;')
        self.git('add', '.')
        self.git('commit', '-qm', 'PR change')
        self.write('trusted-review/copy.php', '<?php echo 5;')
        self.write('trusted-owner-gate/copy.php', '<?php echo 6;')
        self.write('.worktrees/other/ignored.php', '<?php echo 7;')
        self.write('node_modules/ignored.php', '<?php echo 8;')
        self.write('vendor/ignored.php', '<?php echo 9;')
        self.bin = self.root / 'fake-bin'
        self.bin.mkdir()
        php = self.bin / 'php'
        php.write_text('#!/bin/sh\nprintf "%s\\n" "$2" >> "$LINT_LOG"\n'
                       'case "$2" in *broken.php) exit 1;; esac\n')
        php.chmod(0o755)

    def write(self, path, content):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content)

    def git(self, *args):
        return subprocess.run(['git', *args], cwd=self.root, check=True,
                              capture_output=True, text=True).stdout.strip()

    def run_lint(self, event, phase='ci', base='main', before=''):
        # Run the unmodified production lint block, not a duplicated selector.
        source = (ROOT / 'scripts/repository-governance-validate.sh').read_text()
        start = source.index('lint_php_list() {')
        end = source.index('\nif [ "$phase" = "ci" ]; then', start)
        code = 'set -Eeuo pipefail\n' + source[start:end]
        env = dict(os.environ, PATH=str(self.bin) + os.pathsep + os.environ['PATH'],
                   LINT_LOG=str(self.root / 'lint.log'), phase=phase,
                   GITHUB_EVENT_NAME=event, GITHUB_BASE_REF=base,
                   PUSH_BEFORE_SHA=before)
        for key in list(env):
            if key in {'GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_PREFIX'}:
                env.pop(key)
        result = subprocess.run(['bash', '-c', code], cwd=self.root, env=env,
                                capture_output=True, text=True)
        log = self.root / 'lint.log'
        files = {p.removeprefix('./') for p in log.read_text().splitlines()} if log.exists() else set()
        return result, files

    def test_review_lints_only_changed_php_instead_of_three_checkouts(self):
        result, files = self.run_lint('pull_request_review')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(files, {'new.php', 'with space.php'})

    def test_pull_request_lints_changed_files_with_spaces(self):
        result, files = self.run_lint('pull_request')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(files, {'new.php', 'with space.php'})

    def test_merge_group_lints_all_tracked_php_not_untracked_trusted_checkouts(self):
        result, files = self.run_lint('merge_group', base='')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(files, {'new.php', 'with space.php', 'unchanged.php'})

    def test_review_with_no_base_falls_back_to_all_tracked_php(self):
        result, files = self.run_lint('pull_request_review', base='')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(files, {'new.php', 'with space.php', 'unchanged.php'})

    def test_docs_only_review_does_not_lint_php_or_untracked_checkouts(self):
        self.git('update-ref', 'refs/remotes/origin/main', 'HEAD')
        self.write('notes.md', 'Only documentation')
        self.git('add', 'notes.md')
        self.git('commit', '-qm', 'docs')
        result, files = self.run_lint('pull_request_review')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(files, set())

    def test_missing_base_ref_fails_instead_of_silently_skipping_lint(self):
        result, _ = self.run_lint('pull_request_review', base='missing')
        self.assertNotEqual(result.returncode, 0)

    def test_invalid_php_still_fails_review(self):
        self.write('broken.php', '<?php invalid')
        self.git('add', 'broken.php')
        self.git('commit', '-qm', 'broken')
        result, files = self.run_lint('pull_request_review')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('broken.php', files)

    def test_push_uses_exact_before_sha(self):
        before = self.git('rev-parse', 'HEAD^')
        result, files = self.run_lint('push', base='', before=before)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(files, {'new.php', 'with space.php'})


if __name__ == '__main__':
    unittest.main()
