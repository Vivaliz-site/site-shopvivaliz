import importlib.util
import json
import os
import tempfile
import unittest
from unittest import mock
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / 'scripts' / 'codex-native-profile-failover.py'


def load_module():
    spec = importlib.util.spec_from_file_location('codex_native_profile_failover', MODULE)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def write_fake_executable(root: Path, name: str, posix_text: str, windows_text: str) -> Path:
    if os.name == 'nt':
        path = root / f'{name}.cmd'
        path.write_text(windows_text, encoding='utf-8')
    else:
        path = root / name
        path.write_text(posix_text, encoding='utf-8')
        path.chmod(0o755)
    return path


class FakeProbe:
    def __init__(self, results):
        self.results = list(results)
        self.profiles = []

    def __call__(self, profile):
        self.profiles.append(profile)
        return self.results.pop(0)


class FakeTask:
    def __init__(self, result):
        self.result = result
        self.calls = []

    def __call__(self, profile, argv):
        self.calls.append((profile, list(argv)))
        return self.result


class NativeProfileFailoverTests(unittest.TestCase):
    def test_capacity_and_auth_failures_are_retryable(self):
        mod = load_module()
        for text in (
            'usage limit reached', 'rate limit exceeded', 'quota exhausted',
            'too many requests', 'authentication required', 'unauthorized',
            'token expired', 'refresh token failed', 'not logged in',
        ):
            self.assertEqual(mod.classify_failure(1, text), 'failover')

    def test_generic_network_cancel_and_task_failures_do_not_switch(self):
        mod = load_module()
        self.assertEqual(mod.classify_failure(1, 'connection timed out'), 'terminal')
        self.assertEqual(mod.classify_failure(1, 'tests failed'), 'terminal')
        self.assertEqual(mod.classify_failure(130, 'usage limit reached'), 'cancelled')

    def test_probe_selects_secondary_before_real_task(self):
        mod = load_module()
        probe = FakeProbe([
            mod.AttemptResult('fredmourao', 1, '', 'usage limit reached'),
            mod.AttemptResult('marinaofaleiro', 0, 'PROFILE_OK\n', ''),
        ])
        selected, attempts = mod.select_profile('fredmourao', probe)
        self.assertEqual(selected, 'marinaofaleiro')
        self.assertEqual(attempts, 2)
        self.assertEqual(probe.profiles, ['fredmourao', 'marinaofaleiro'])

    def test_real_task_is_never_retried_after_it_starts(self):
        mod = load_module()
        probe = FakeProbe([mod.AttemptResult('fredmourao', 0, 'PROFILE_OK\n', '')])
        task = FakeTask(mod.AttemptResult('fredmourao', 1, '', 'usage limit reached'))
        rc, selected, result_class = mod.run_model_command(
            ['exec', 'make changes'], 'fredmourao', probe, task
        )
        self.assertEqual((rc, selected, result_class), (1, 'fredmourao', 'failover'))
        self.assertEqual(len(task.calls), 1)
        self.assertEqual(task.calls[0][0], 'fredmourao')

    def test_secondary_failure_makes_primary_eligible_next_invocation(self):
        mod = load_module()
        probe = FakeProbe([
            mod.AttemptResult('marinaofaleiro', 1, '', 'quota exhausted'),
            mod.AttemptResult('fredmourao', 0, 'PROFILE_OK\n', ''),
        ])
        selected, attempts = mod.select_profile('marinaofaleiro', probe)
        self.assertEqual((selected, attempts), ('fredmourao', 2))

    def test_both_profiles_unavailable_attempt_each_once(self):
        mod = load_module()
        probe = FakeProbe([
            mod.AttemptResult('fredmourao', 1, '', 'not logged in'),
            mod.AttemptResult('marinaofaleiro', 1, '', 'usage limit reached'),
        ])
        with self.assertRaises(mod.NoUsableProfile):
            mod.select_profile('fredmourao', probe)
        self.assertEqual(probe.profiles, ['fredmourao', 'marinaofaleiro'])

    def test_state_contains_no_secret_fields(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            path = Path(td) / 'state.json'
            mod.write_state(path, 'marinaofaleiro', 'success', 2)
            data = json.loads(path.read_text())
        self.assertEqual(data['preferred_profile'], 'marinaofaleiro')
        self.assertEqual(data['result_class'], 'success')
        self.assertEqual(data['attempts'], 2)
        self.assertEqual(set(data), {'preferred_profile', 'updated_at', 'result_class', 'attempts'})


class NativeProfileCliContractTests(unittest.TestCase):
    def test_command_modes_are_explicit(self):
        mod = load_module()
        self.assertEqual(mod.command_mode(['exec', 'x']), 'model')
        self.assertEqual(mod.command_mode(['e', 'x']), 'model')
        self.assertEqual(mod.command_mode(['review']), 'model')
        self.assertEqual(mod.command_mode(['--ask-for-approval', 'never', 'exec', 'x']), 'model')
        self.assertEqual(mod.command_mode(['doctor', '--json']), 'admin')
        self.assertEqual(mod.command_mode(['login', 'status']), 'admin')
        self.assertEqual(mod.command_mode(['--version']), 'admin')
        self.assertEqual(mod.command_mode(['-V']), 'admin')
        self.assertEqual(mod.command_mode(['--help']), 'admin')
        self.assertEqual(mod.command_mode(['-h']), 'admin')
        self.assertEqual(mod.command_mode([]), 'interactive')
        self.assertEqual(mod.command_mode(['explain this repo']), 'interactive')

    def test_probe_contract_is_ephemeral_read_only_and_bounded(self):
        mod = load_module()
        self.assertEqual(mod.PROBE_TIMEOUT_SECONDS, 45)
        self.assertEqual(mod.PROBE_ARGS[:3], ['--ask-for-approval', 'never', 'exec'])
        self.assertIn('--ephemeral', mod.PROBE_ARGS)
        self.assertIn('--skip-git-repo-check', mod.PROBE_ARGS)
        self.assertIn('--ignore-user-config', mod.PROBE_ARGS)
        self.assertIn('--ignore-rules', mod.PROBE_ARGS)
        self.assertIn('read-only', mod.PROBE_ARGS)
        self.assertIn('never', mod.PROBE_ARGS)

    def test_actual_capacity_failure_prefers_other_profile_next_time(self):
        mod = load_module()
        self.assertEqual(mod.preference_after_task('fredmourao', 'failover'), 'marinaofaleiro')
        self.assertEqual(mod.preference_after_task('marinaofaleiro', 'failover'), 'fredmourao')
        self.assertEqual(mod.preference_after_task('fredmourao', 'terminal'), 'fredmourao')


class NativeProfileProcessTests(unittest.TestCase):
    def test_captured_runner_sets_only_requested_codex_home(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            exe = write_fake_executable(
                root,
                'fake-codex',
                '#!/bin/sh\nprintf "%s\\n" "$CODEX_HOME"\nprintf "%s\\n" "$*"\n',
                '@echo off\r\necho %CODEX_HOME%\r\necho %*\r\nexit /b 0\r\n',
            )
            home = root / '.codex-business' / 'fredmourao'
            home.mkdir(parents=True)
            result = mod.run_captured(str(exe), 'fredmourao', home, ['doctor', '--json'])
        self.assertEqual(result.returncode, 0)
        self.assertIn(str(home), result.stdout)
        self.assertIn('doctor --json', result.stdout)

    def test_probe_timeout_is_terminal_not_failover(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            exe = write_fake_executable(
                root,
                'slow-codex',
                '#!/bin/sh\nsleep 2\n',
                '@echo off\r\nping 127.0.0.1 -n 3 >nul\r\nexit /b 0\r\n',
            )
            home = root / '.codex-business' / 'fredmourao'
            home.mkdir(parents=True)
            result = mod.run_captured(str(exe), 'fredmourao', home, ['exec'], timeout=0.05)
        self.assertEqual(result.returncode, 124)
        self.assertEqual(mod.classify_failure(result.returncode, result.stderr), 'terminal')

    def test_probe_closes_stdin_to_avoid_waiting_for_piped_input(self):
        mod = load_module()
        completed = type('Completed', (), {
            'returncode': 0, 'stdout': 'PROFILE_OK\n', 'stderr': ''
        })()
        with tempfile.TemporaryDirectory() as td:
            home = Path(td) / 'fredmourao'
            home.mkdir()
            with mock.patch.object(mod.subprocess, 'run', return_value=completed) as run:
                result = mod.probe_profile('/real/codex', 'fredmourao', home)
        self.assertEqual(result.returncode, 0)
        self.assertIs(run.call_args.kwargs['stdin'], mod.subprocess.DEVNULL)


class NativeProfileMainTests(unittest.TestCase):
    def _setup_home(self, root: Path):
        business = root / '.codex-business'
        (business / 'fredmourao').mkdir(parents=True)
        (business / 'marinaofaleiro').mkdir(parents=True)
        return business

    def test_main_selects_secondary_before_actual_task(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            business = self._setup_home(root)
            log = root / 'calls.log'
            exe = write_fake_executable(
                root,
                'fake-codex',
                '#!/bin/sh\n'
                'name=$(basename "$CODEX_HOME")\n'
                'printf "%s|%s\\n" "$name" "$*" >> "$CALL_LOG"\n'
                'case "$*" in\n'
                '  *PROFILE_OK*) if [ "$name" = fredmourao ]; then echo "usage limit reached" >&2; exit 1; else echo PROFILE_OK; exit 0; fi ;;\n'
                '  *) echo "TASK:$name"; exit 0 ;;\n'
                'esac\n',
                '@echo off\r\n'
                'for %%I in ("%CODEX_HOME%") do set "name=%%~nxI"\r\n'
                '>>"%CALL_LOG%" echo %name%^|%*\r\n'
                'echo %* | findstr /C:"PROFILE_OK" >nul\r\n'
                'if errorlevel 1 goto task\r\n'
                'if "%name%"=="fredmourao" goto fredfail\r\n'
                'echo PROFILE_OK\r\n'
                'exit /b 0\r\n'
                ':fredfail\r\n'
                '>&2 echo usage limit reached\r\n'
                'exit /b 1\r\n'
                ':task\r\n'
                'echo TASK:%name%\r\n'
                'exit /b 0\r\n',
            )
            old = dict(os.environ)
            try:
                os.environ['HOME'] = str(root)
                os.environ['USERPROFILE'] = str(root)
                os.environ['CODEX_REAL'] = str(exe)
                os.environ['CALL_LOG'] = str(log)
                rc = mod.main(['exec', 'do-work'])
            finally:
                os.environ.clear(); os.environ.update(old)
            calls = log.read_text().splitlines()
            state = json.loads((business / 'failover-state.json').read_text())
        self.assertEqual(rc, 0)
        self.assertEqual(sum('do-work' in c for c in calls), 1)
        self.assertTrue(any(c.startswith('marinaofaleiro|exec do-work') for c in calls))
        self.assertEqual(state['preferred_profile'], 'marinaofaleiro')

    def test_main_never_retries_actual_task_after_capacity_failure(self):
        mod = load_module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            business = self._setup_home(root)
            log = root / 'calls.log'
            exe = write_fake_executable(
                root,
                'fake-codex',
                '#!/bin/sh\n'
                'name=$(basename "$CODEX_HOME")\n'
                'printf "%s|%s\\n" "$name" "$*" >> "$CALL_LOG"\n'
                'case "$*" in\n'
                '  *PROFILE_OK*) echo PROFILE_OK; exit 0 ;;\n'
                '  *) echo "usage limit reached" >&2; exit 1 ;;\n'
                'esac\n',
                '@echo off\r\n'
                'for %%I in ("%CODEX_HOME%") do set "name=%%~nxI"\r\n'
                '>>"%CALL_LOG%" echo %name%^|%*\r\n'
                'echo %* | findstr /C:"PROFILE_OK" >nul\r\n'
                'if errorlevel 1 goto taskfail\r\n'
                'echo PROFILE_OK\r\n'
                'exit /b 0\r\n'
                ':taskfail\r\n'
                '>&2 echo usage limit reached\r\n'
                'exit /b 1\r\n',
            )
            old = dict(os.environ)
            try:
                os.environ['HOME'] = str(root)
                os.environ['USERPROFILE'] = str(root)
                os.environ['CODEX_REAL'] = str(exe)
                os.environ['CALL_LOG'] = str(log)
                rc = mod.main(['exec', 'do-work'])
            finally:
                os.environ.clear(); os.environ.update(old)
            calls = log.read_text().splitlines()
            state = json.loads((business / 'failover-state.json').read_text())
        self.assertEqual(rc, 1)
        self.assertEqual(sum('do-work' in c for c in calls), 1)
        self.assertEqual(state['preferred_profile'], 'marinaofaleiro')
        self.assertEqual(state['result_class'], 'failover')


if __name__ == '__main__':
    unittest.main()
