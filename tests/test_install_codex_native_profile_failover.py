import importlib.util
import json
import sys
import tempfile
import unittest
from unittest import mock
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / 'scripts' / 'install-codex-native-profile-failover.py'


def load_module():
    spec = importlib.util.spec_from_file_location('install_codex_native_profile_failover', MODULE)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


class InstallerRenderTests(unittest.TestCase):
    def test_linux_launchers_use_real_codex_and_native_engine(self):
        mod = load_module()
        auto = mod.render_linux_auto_launcher('/real/codex', '/home/u/.codex-business/codex-native-profile-failover.py')
        manual = mod.render_linux_manual_launcher('/real/codex', '/home/u/.codex-business/fredmourao')
        self.assertIn('CODEX_REAL=/real/codex', auto)
        self.assertIn('codex-native-profile-failover.py', auto)
        self.assertNotIn('codex-failover.py', auto)
        self.assertIn('CODEX_HOME=/home/u/.codex-business/fredmourao', manual)
        self.assertIn('exec /real/codex', manual)
        self.assertIn('#!/bin/bash', auto)
        self.assertIn('set -Eeuo pipefail', auto)
        self.assertIn('set -Eeuo pipefail', manual)
        self.assertNotIn('|| true', manual)

    def test_windows_scope_guard_patch_is_idempotent(self):
        mod = load_module()
        original = "if($Tool -eq 'codex'){\n  Write-Output 'old'\n}\n& $exe.Source @ToolArgs\n"
        patched = mod.patch_windows_scope_guard(original, 'C:\\Users\\x\\.local\\bin\\codex-auto.ps1')
        patched_twice = mod.patch_windows_scope_guard(patched, 'C:\\Users\\x\\.local\\bin\\codex-auto.ps1')
        self.assertEqual(patched, patched_twice)
        self.assertEqual(patched.count(mod.WINDOWS_SENTINEL_BEGIN), 1)
        self.assertIn('codex-auto.ps1', patched)
        self.assertIn("Write-Output 'old'", patched)

    def test_windows_guard_file_preserves_bom_and_crlf(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            path = Path(td) / 'guard.ps1'
            raw = b'\xef\xbb\xbf' + b"if($Tool -eq 'codex'){\r\n  Write-Output 'old'\r\n}\r\n"
            path.write_bytes(raw)
            mod.patch_windows_scope_guard_file(path, 'C:\\Users\\x\\.local\\bin\\codex-auto.ps1')
            patched = path.read_bytes()
        self.assertTrue(patched.startswith(b'\xef\xbb\xbf'))
        self.assertNotIn(b'\n', patched.replace(b'\r\n', b''))
        self.assertIn(b'BEGIN SHOPVIVALIZ CODEX NATIVE PROFILE FAILOVER', patched)


class InstallerIntegrationTests(unittest.TestCase):
    def _home(self, root: Path) -> Path:
        home = root / 'home'
        (home / '.codex-business' / 'fredmourao').mkdir(parents=True)
        (home / '.codex-business' / 'marinaofaleiro').mkdir(parents=True)
        return home

    def test_linux_install_is_idempotent_and_preserves_profiles(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            home = self._home(root)
            real = root / 'real-codex'
            real.write_text('#!/bin/sh\nexit 0\n')
            real.chmod(0o755)
            engine = root / 'engine.py'
            engine.write_text('print("engine")\n')
            first = mod.install(home, 'linux', str(real), engine)
            snapshots = {
                name: (home / '.local' / 'bin' / name).read_text()
                for name in ('codex', 'codex-auto', 'codex-fred', 'codex-marina')
            }
            second = mod.install(home, 'linux', str(real), engine)
            self.assertEqual(
                snapshots,
                {name: (home / '.local' / 'bin' / name).read_text() for name in snapshots},
            )
            self.assertTrue((home / '.codex-business' / 'fredmourao').is_dir())
            self.assertTrue((home / '.codex-business' / 'marinaofaleiro').is_dir())
            self.assertEqual(first['platform'], 'linux')
            self.assertEqual(second['platform'], 'linux')

    def test_install_fails_closed_when_profile_missing(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            home = root / 'home'
            (home / '.codex-business' / 'fredmourao').mkdir(parents=True)
            real = root / 'real-codex'
            real.write_text('x')
            engine = root / 'engine.py'
            engine.write_text('x')
            with self.assertRaises(FileNotFoundError):
                mod.install(home, 'linux', str(real), engine)

    def test_session_inventory_is_session_only_and_deduplicates_identical_files(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            home = Path(td) / 'home'
            shared = home / '.codex-business' / 'shared-session-state' / 'sessions'
            legacy = home / '.codex' / 'sessions' / '2026' / '09' / '13'
            fred = home / '.codex-business' / 'fredmourao' / 'sessions' / '2026' / '09' / '13'
            legacy.mkdir(parents=True); fred.mkdir(parents=True)
            (home / '.codex-business' / 'fredmourao' / 'auth.json').write_text('SUPER_SECRET')
            (legacy / 'a.jsonl').write_text('same\n')
            (fred / 'a.jsonl').write_text('same\n')
            inv = mod._build_session_inventory(mod._session_source_dirs(home, shared), shared)
            self.assertEqual(set(inv), {'2026/09/13/a.jsonl'})
            self.assertNotIn('SUPER_SECRET', repr(inv))

    def test_session_inventory_rejects_conflicting_same_path(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            home = Path(td) / 'home'
            shared = home / '.codex-business' / 'shared-session-state' / 'sessions'
            a = home / '.codex' / 'sessions' / 'x.jsonl'
            b = home / '.codex-business' / 'fredmourao' / 'sessions' / 'x.jsonl'
            a.parent.mkdir(parents=True); b.parent.mkdir(parents=True)
            a.write_text('one'); b.write_text('two')
            with self.assertRaises(mod.SessionConflictError):
                mod._build_session_inventory(mod._session_source_dirs(home, shared), shared)
            self.assertFalse(shared.exists())

    def test_shared_session_migration_preserves_auth_and_links_profiles(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            home = self._home(root)
            legacy = home / '.codex' / 'sessions' / 'old.jsonl'
            legacy.parent.mkdir(parents=True)
            legacy.write_text('history\n')
            for profile in mod.PROFILES:
                (home / '.codex-business' / profile / 'auth.json').write_text(f'auth-{profile}')
            backup = home / '.codex-business' / 'backups' / 'case'
            result = mod._prepare_shared_sessions(home, 'linux', backup)
            shared = Path(result['shared_sessions'])
            self.assertEqual((shared / 'old.jsonl').read_text(), 'history\n')
            for profile in mod.PROFILES:
                p = home / '.codex-business' / profile
                self.assertEqual((p / 'sessions').resolve(), shared.resolve())
                self.assertEqual((p / 'auth.json').read_text(), f'auth-{profile}')
            second = mod._prepare_shared_sessions(home, 'linux', backup / 'second')
            self.assertEqual(second['session_count'], 1)
            self.assertEqual(Path(second['shared_sessions']).resolve(), shared.resolve())

    def test_windows_directory_link_uses_junction_command(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            link = root / 'profile' / 'sessions'
            target = root / 'shared' / 'sessions'
            target.mkdir(parents=True)
            with mock.patch.object(mod.subprocess, 'run') as run:
                mod._create_directory_link(link, target, 'windows')
            self.assertEqual(
                run.call_args.args[0][:5],
                ['cmd.exe', '/d', '/c', 'mklink', '/J'],
            )
            self.assertEqual(run.call_args.args[0][5:], [str(link), str(target)])
            self.assertTrue(run.call_args.kwargs['check'])

    def test_rollback_restores_profile_sessions_and_preserves_shared_store(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            home = self._home(root)
            fred_sessions = home / '.codex-business' / 'fredmourao' / 'sessions'
            fred_sessions.mkdir(parents=True)
            (fred_sessions / 'fred.jsonl').write_text('fred-history\n')
            backup = home / '.codex-business' / 'backups' / 'case'
            result = mod._prepare_shared_sessions(home, 'linux', backup)
            shared = Path(result['shared_sessions'])
            self.assertTrue((shared / 'fred.jsonl').is_file())
            rolled = mod.rollback_shared_sessions(home, backup)
            self.assertTrue((home / '.codex-business' / 'fredmourao' / 'sessions').is_dir())
            self.assertFalse((home / '.codex-business' / 'fredmourao' / 'sessions').is_symlink())
            self.assertEqual(
                (home / '.codex-business' / 'fredmourao' / 'sessions' / 'fred.jsonl').read_text(),
                'fred-history\n',
            )
            self.assertTrue((shared / 'fred.jsonl').is_file())
            self.assertEqual(rolled['shared_sessions'], str(shared))

    def test_reinstall_ignores_stale_legacy_after_profiles_share_store(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            home = self._home(root)
            legacy = home / '.codex' / 'sessions' / 'old.jsonl'
            legacy.parent.mkdir(parents=True)
            legacy.write_text('history\n')
            first = mod._prepare_shared_sessions(
                home, 'linux', home / '.codex-business' / 'backups' / 'first'
            )
            shared_file = Path(first['shared_sessions']) / 'old.jsonl'
            shared_file.write_text('history\ncontinued\n')
            second = mod._prepare_shared_sessions(
                home, 'linux', home / '.codex-business' / 'backups' / 'second'
            )
            self.assertEqual(second['session_count'], 1)
            self.assertEqual(shared_file.read_text(), 'history\ncontinued\n')

    def test_link_failure_rolls_back_profile_session_paths(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            home = self._home(root)
            expected = {}
            for profile in mod.PROFILES:
                sessions = home / '.codex-business' / profile / 'sessions'
                sessions.mkdir(parents=True)
                content = f'{profile}-history\n'
                (sessions / f'{profile}.jsonl').write_text(content)
                expected[profile] = content
            backup = home / '.codex-business' / 'backups' / 'case'
            original = mod._create_directory_link
            calls = []

            def flaky(link, target, platform):
                calls.append(str(link))
                if len(calls) == 2:
                    raise OSError('synthetic second-link failure')
                return original(link, target, platform)

            with mock.patch.object(mod, '_create_directory_link', side_effect=flaky):
                with self.assertRaisesRegex(OSError, 'synthetic second-link failure'):
                    mod._prepare_shared_sessions(home, 'linux', backup)

            shared = home / '.codex-business' / mod.SESSION_STORE_DIR / 'sessions'
            self.assertTrue(shared.is_dir())
            for profile in mod.PROFILES:
                sessions = home / '.codex-business' / profile / 'sessions'
                self.assertTrue(sessions.is_dir())
                self.assertFalse(sessions.is_symlink())
                self.assertEqual(
                    (sessions / f'{profile}.jsonl').read_text(), expected[profile]
                )

    def test_install_returns_shared_session_metadata(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            home = self._home(root)
            legacy = home / '.codex' / 'sessions' / 'one.jsonl'
            legacy.parent.mkdir(parents=True)
            legacy.write_text('one\n')
            real = root / 'real-codex'
            real.write_text('#!/bin/sh\nexit 0\n')
            real.chmod(0o755)
            engine = root / 'engine.py'
            engine.write_text('print("engine")\n')
            result = mod.install(home, 'linux', str(real), engine)
            self.assertEqual(result['session_count'], 1)
            self.assertTrue(Path(result['shared_sessions']).is_dir())
            manifest = Path(result['session_manifest'])
            self.assertTrue(manifest.is_file())
            data = json.loads(manifest.read_text())
            self.assertEqual(data['session_count'], 1)


if __name__ == '__main__':
    unittest.main()
