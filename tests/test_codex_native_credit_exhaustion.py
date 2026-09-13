import importlib.util
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / 'scripts' / 'codex-native-profile-failover.py'


def load_module():
    spec = importlib.util.spec_from_file_location('codex_native_profile_failover_credit_test', MODULE)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


class NativeWorkspaceCreditExhaustionTests(unittest.TestCase):
    def test_workspace_out_of_credits_triggers_failover(self):
        mod = load_module()
        message = 'Your workspace is out of credits. Add credits to continue.'
        self.assertEqual(mod.classify_failure(1, message), 'failover')


if __name__ == '__main__':
    unittest.main()
