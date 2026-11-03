"""Merge queue lint scope: lint the queue change set, never silently skip it.

Companion to tests/test_ci_php_lint_scope.py, kept separate so the merge-group
contract can be reviewed on its own.
"""
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class MergeGroupLintScopeTests(unittest.TestCase):
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
        self.write('with space.php', '<?php echo 3;')
        self.write('new.php', '<?php echo 4;')
        self.git('add', '.')
        self.git('commit', '-qm', 'queue change set')
        self.bin = self.root / 'fake-bin'
        self.bin.mkdir()
        php = self.bin / 'php'
        php.write_text('#!/bin/sh\nprintf "%s\\n" "$2" >> "$LINT_LOG"\n')
        php.chmod(0o755)

    def write(self, path, content):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content)

    def git(self, *args):
        return subprocess.run(['git', *args], cwd=self.root, check=True,
                              capture_output=True, text=True).stdout.strip()

    def run_lint(self, merge_base=''):
        source = (ROOT / 'scripts/repository-governance-validate.sh').read_text()
        start = source.index('lint_php_list() {')
        end = source.index('\nif [ "$phase" = "ci" ]; then', start)
        # The lint block reads the queue base from a plain shell variable, exactly as
        # the script does when it receives it as its second argument.
        code = ('set -Eeuo pipefail\n'
                f'merge_group_lint_base_sha={shlex.quote(merge_base)}\n'
                + source[start:end])
        env = dict(os.environ, PATH=str(self.bin) + os.pathsep + os.environ['PATH'],
                   LINT_LOG=str(self.root / 'lint.log'), phase='ci',
                   GITHUB_EVENT_NAME='merge_group', GITHUB_BASE_REF='',
                   PUSH_BEFORE_SHA='')
        for key in ('GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_PREFIX'):
            env.pop(key, None)
        result = subprocess.run(['bash', '-c', code], cwd=self.root, env=env,
                                capture_output=True, text=True)
        log = self.root / 'lint.log'
        files = {p.removeprefix('./') for p in log.read_text().splitlines()} if log.exists() else set()
        return result, files

    def test_lints_only_queue_change_set_when_base_sha_is_given(self):
        result, files = self.run_lint(merge_base=self.git('rev-parse', 'HEAD^'))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(files, {'new.php', 'with space.php'})

    def test_unknown_base_sha_fails_instead_of_skipping_lint(self):
        result, _ = self.run_lint(merge_base='0' * 40)
        self.assertNotEqual(result.returncode, 0)

    def test_without_base_sha_falls_back_to_all_tracked_php(self):
        result, files = self.run_lint(merge_base='')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(files, {'new.php', 'with space.php', 'unchanged.php'})


if __name__ == '__main__':
    unittest.main()
