import importlib.util
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / 'scripts' / 'install-codex-native-profile-failover.py'


def load_module():
    spec = importlib.util.spec_from_file_location('install_codex_native_profile_failover_manual_stderr', MODULE)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


class WindowsManualLauncherStderrTests(unittest.TestCase):
    def test_manual_launcher_does_not_promote_native_stderr(self):
        mod = load_module()
        manual = mod.render_windows_manual_launcher(
            r'C:\Codex\codex.exe',
            r'C:\Users\x\.codex-business\fredmourao',
        )
        self.assertIn("$ErrorActionPreference='Continue'", manual)
        self.assertNotIn("$ErrorActionPreference='Stop'", manual)
        self.assertIn('exit $LASTEXITCODE', manual)


if __name__ == '__main__':
    unittest.main()
