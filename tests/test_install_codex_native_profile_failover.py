import importlib.util
import sys
import tempfile
import unittest
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


if __name__ == '__main__':
    unittest.main()
