import importlib.util
import pathlib
import unittest
from unittest import mock

SCRIPT = pathlib.Path(__file__).parents[1] / 'scripts' / 'codex-failover.py'
spec = importlib.util.spec_from_file_location('codex_failover', SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class CodexFailoverCompatibilityTests(unittest.TestCase):
    def test_shim_points_to_native_business_profile_engine(self):
        self.assertEqual(mod.native_engine().name, 'codex-native-profile-failover.py')
        self.assertTrue(mod.native_engine().is_file())

    def test_api_credentials_are_removed(self):
        with mock.patch.dict(mod.os.environ, {'OPENAI_API_KEY': 'x', 'CODEX_API_KEY': 'y'}, clear=False):
            env = mod.sanitized_env()
        self.assertNotIn('OPENAI_API_KEY', env)
        self.assertNotIn('CODEX_API_KEY', env)

    def test_main_delegates_without_api_keys(self):
        with mock.patch.object(mod.subprocess, 'call', return_value=0) as call:
            rc = mod.main(['login', 'status'])
        self.assertEqual(rc, 0)
        command = call.call_args.args[0]
        self.assertIn('codex-native-profile-failover.py', command[1])
        self.assertEqual(command[-2:], ['login', 'status'])
        env = call.call_args.kwargs['env']
        self.assertNotIn('OPENAI_API_KEY', env)
        self.assertNotIn('CODEX_API_KEY', env)


if __name__ == '__main__':
    unittest.main()
