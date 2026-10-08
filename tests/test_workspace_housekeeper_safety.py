"""Run the real cleanup loops on disposable Git worktrees, never host paths."""
import os
from pathlib import Path
import re
import shlex
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'ops/host/shopvivaliz-workspace-housekeeper'
NOW = 2000000000


def function_text(source, name):
    match = re.search(r'^' + re.escape(name) + r'\(\) \{\n.*?^\}', source, re.M | re.S)
    if match is None:
        raise AssertionError('production function missing: ' + name)
    return match.group(0)


class HousekeeperWorktreeSafetyTests(unittest.TestCase):
    def exercise(self, *, age=0, locked=False, dirty=False, chat=False, bad_metadata=False, late_lock=None, real_cache=False, wrapper_age=None, empty_lock=False):
        source = SOURCE.read_text()
        with tempfile.TemporaryDirectory(prefix='housekeeper-regression-') as tmp:
            base = Path(tmp)
            git_env = {k: v for k, v in os.environ.items() if not k.startswith('GIT_')}
            git_env.update({'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_SYSTEM': '/dev/null', 'HOME': str(base)})
            repo = base / 'repo'
            parent = base / ('chat-workspaces' if chat else 'worktrees')
            worktree = parent / 'task' / 'repo' if chat else parent / 'candidate'
            worktree.parent.mkdir(parents=True)
            def git(*args):
                return subprocess.run(['git', *args], cwd=base, env=git_env, check=True,
                                      capture_output=True, text=True, timeout=15)
            git('init', '-q', '-b', 'main', str(repo))
            (repo / 'tracked.txt').write_text('fixture\n')
            git('-C', str(repo), 'add', 'tracked.txt')
            git('-C', str(repo), '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid',
                'commit', '-qm', 'fixture base')
            git('-C', str(repo), 'update-ref', 'refs/remotes/origin/main', 'HEAD')
            git('-C', str(repo), 'worktree', 'add', '-qb', 'candidate', str(worktree), 'HEAD')
            if locked:
                lock_args = [] if empty_lock else ['--reason', 'queued test task']
                git('-C', str(repo), 'worktree', 'lock', *lock_args, str(worktree))
            if real_cache:
                (worktree / 'node_modules').mkdir()
                (worktree / 'node_modules' / 'fixture.txt').write_text('disposable cache')
            if dirty:
                (worktree / 'pending.txt').write_text('uncommitted fixture\n')
            if bad_metadata:
                (worktree / '.git').write_text('gitdir: ' + str(base / 'missing-metadata') + '\n')
            os.utime(worktree, (NOW - age, NOW - age))
            if chat:
                os.utime(worktree.parent, (NOW - (age if wrapper_age is None else wrapper_age),) * 2)
                start = source.index('if [ -d "$ROOT" ]; then')
                end = source.index('\nfor d in /tmp/shopvivaliz-claude-oci-', start)
                loop = source[start:end]
            else:
                start = source.index('worktree_removed=0\nif [ -d /home/ubuntu/worktrees ]; then')
                end = source.index('\nfor repo in', start)
                loop = source[start:end].replace('/home/ubuntu/worktrees', str(parent))
            self.assertNotIn('/home/ubuntu', loop, 'test must never use live workspace paths')
            constant = re.search(r'^WORKTREE_MIN_AGE_SECONDS=.*$', source, re.M)
            self.assertIsNotNone(constant, 'production grace constant missing')
            cache_log = base / 'cache.log'
            guard_log = base / 'guard.log'
            prelude = f'''set -Eeuo pipefail
ROOT={shlex.quote(str(parent))}
now_epoch={NOW}
FULL_TTL_HOURS=0
CACHE_TTL_HOURS=0
DRY_RUN=0
preserved=0
removed=0
cache_cleaned=0
freed_bytes=0
log() {{ printf '%s\\n' "$*" >> {shlex.quote(str(guard_log))}; }}
path_active() {{ return 1; }}
cleanup_cache() {{ printf 'cache_called\\n' >> {shlex.quote(str(cache_log))}; }}
sudo() {{ if [ "$1" = -u ]; then shift 2; fi; "$@"; }}
'''
            # Only the original Git queries, protection helper and cleanup loop run.
            # Process activity is deliberately absent, as for a queued task.
            script = prelude + '\n' + (constant.group(0) if constant else '') + '\n'
            script += '\n'.join(function_text(source, name) for name in
                                ('git_root', 'repo_slug', 'delivered', 'worktree_protected'))
            if real_cache:
                script += '\n' + function_text(source, 'cleanup_cache')
            if late_lock:
                lock_command = 'git -C ' + shlex.quote(str(repo)) + ' worktree lock ' + shlex.quote(str(worktree))
                if late_lock == 'delivered':
                    script += '\ndelivered() { ' + lock_command + '; return 0; }\n'
                elif late_lock == 'cache':
                    once = shlex.quote(str(base / 'late-lock-created'))
                    script += '\ndu() { if [ ! -e ' + once + ' ]; then ' + lock_command + '; touch ' + once + '; fi; command du "$@"; }\n'
            # Deny unplanned external commands, including timeout-wrapped fallbacks.
            script += '\ntimeout() { shift; "$@"; }\ngh() { return 1; }\n'
            script += '\n' + loop
            result = subprocess.run(['bash', '-s'], input=script, text=True, cwd=base, env=git_env,
                                    capture_output=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.last_guard_log = guard_log.read_text() if guard_log.exists() else ''
            self.last_cache_exists = (worktree / "node_modules").exists()
            return worktree.exists(), cache_log.exists()

    def test_fresh_clean_main_based_worktree_survives_emergency_ttl_zero(self):
        exists, cache_called = self.exercise(age=1)
        self.assertTrue(exists, 'queued fresh worktree was deleted')
        self.assertFalse(cache_called)

    def test_old_git_locked_worktree_and_caches_are_preserved(self):
        exists, cache_called = self.exercise(age=172800, locked=True)
        self.assertTrue(exists, 'Git-locked worktree was deleted')
        self.assertFalse(cache_called, 'Git-locked workspace cache was touched')

    def test_chat_workspace_honors_git_lock(self):
        exists, cache_called = self.exercise(age=172800, locked=True, chat=True)
        self.assertTrue(exists)
        self.assertFalse(cache_called)

    def test_chat_workspace_honors_fresh_worktree_grace(self):
        exists, cache_called = self.exercise(age=1, chat=True)
        self.assertTrue(exists)
        self.assertFalse(cache_called)

    def test_grace_boundary_before_at_after(self):
        for age, expected in ((1799, True), (1800, False), (1801, False)):
            with self.subTest(age=age):
                exists, _ = self.exercise(age=age)
                self.assertEqual(exists, expected)

    def test_old_unlocked_delivered_worktree_can_still_be_cleaned(self):
        exists, _ = self.exercise(age=172800)
        self.assertFalse(exists, 'ordinary cleanup must remain enabled')

    def test_uncommitted_work_remains_preserved(self):
        exists, _ = self.exercise(age=172800, dirty=True)
        self.assertTrue(exists)

    def test_broken_git_metadata_preserves_workspace_and_cache(self):
        exists, cache_called = self.exercise(age=172800, bad_metadata=True)
        self.assertTrue(exists)
        self.assertFalse(cache_called, 'metadata failure must not authorize cleanup')

    def test_future_mtime_is_preserved(self):
        exists, cache_called = self.exercise(age=-10)
        self.assertTrue(exists)
        self.assertFalse(cache_called)

    def test_lock_acquired_during_delivery_check_is_revalidated(self):
        for chat in (False, True):
            with self.subTest(chat=chat):
                exists, _ = self.exercise(age=172800, chat=chat, late_lock='delivered')
                self.assertTrue(exists, 'late Git lock must stop workspace deletion')

    def test_lock_acquired_during_cache_sizing_preserves_cache(self):
        exists, _ = self.exercise(age=172800, late_lock='cache', real_cache=True)
        self.assertTrue(exists, 'late Git lock must stop workspace deletion')
        self.assertTrue(self.last_cache_exists, 'late Git lock must also stop cache deletion')

    def test_fresh_chat_wrapper_preserves_old_checkout(self):
        exists, cache_called = self.exercise(age=172800, chat=True, wrapper_age=1)
        self.assertTrue(exists)
        self.assertFalse(cache_called)

    def test_chat_old_unlocked_delivered_can_be_cleaned(self):
        exists, _ = self.exercise(age=172800, chat=True)
        self.assertFalse(exists)

    def test_chat_dirty_work_is_preserved(self):
        exists, _ = self.exercise(age=172800, chat=True, dirty=True)
        self.assertTrue(exists)

    def test_empty_reason_git_lock_is_preserved(self):
        exists, cache_called = self.exercise(age=172800, locked=True, empty_lock=True)
        self.assertTrue(exists)
        self.assertFalse(cache_called)

    def test_fixture_ignores_foreign_git_environment(self):
        with tempfile.TemporaryDirectory(prefix='foreign-git-regression-') as tmp:
            foreign = Path(tmp) / 'foreign'
            env = {k: v for k, v in os.environ.items() if not k.startswith('GIT_')}
            env.update({'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_SYSTEM': '/dev/null'})
            def git(*args):
                return subprocess.run(['git', *args], env=env, check=True,
                                      capture_output=True, text=True, timeout=15)
            git('init', '-q', '-b', 'main', str(foreign))
            (foreign / 'tracked.txt').write_text('foreign fixture only')
            git('-C', str(foreign), 'add', 'tracked.txt')
            git('-C', str(foreign), '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid',
                'commit', '-qm', 'foreign base')
            before = git('-C', str(foreign), 'rev-parse', 'HEAD').stdout
            injected = {'GIT_DIR': str(foreign / '.git'), 'GIT_WORK_TREE': str(foreign),
                        'GIT_INDEX_FILE': str(foreign / '.git' / 'index')}
            with patch.dict(os.environ, injected):
                try:
                    exists, _ = self.exercise(age=172800, locked=True)
                except subprocess.CalledProcessError:
                    self.fail('fixture used foreign Git environment')
                self.assertTrue(exists)
            self.assertEqual(git('-C', str(foreign), 'rev-parse', 'HEAD').stdout, before)

    def test_preservation_reason_is_observable(self):
        for params, reason in (({'age': 1}, 'grace'),
                               ({'age': 172800, 'locked': True}, 'locked'),
                               ({'age': 172800, 'bad_metadata': True}, 'metadata')):
            with self.subTest(reason=reason):
                self.exercise(**params)
                self.assertIn('reason=' + reason, self.last_guard_log)

    def test_regression_is_part_of_canonical_governance(self):
        gate = (ROOT / 'scripts/repository-governance-validate.sh').read_text()
        self.assertTrue('python3 tests/test_workspace_housekeeper_safety.py' in gate,
                        'worktree regression is missing from canonical gate')


if __name__ == '__main__':
    unittest.main()
