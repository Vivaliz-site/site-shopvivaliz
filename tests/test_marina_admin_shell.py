"""Regression for the owner-approved Marina admin shell (grant PR 3616).

Runs without root. Process execution and audit are stubbed; no host command runs.
"""
from __future__ import annotations

import importlib.util
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "admin_shell.py"
spec = importlib.util.spec_from_file_location("marina_admin_shell_test", MODULE)
assert spec and spec.loader
shell = importlib.util.module_from_spec(spec)
spec.loader.exec_module(shell)


class GrantTests(unittest.TestCase):
    def test_shipped_grant_matches_pinned_owner_approval(self):
        self.assertEqual(shell.load_grant(), shell.EXPECTED_GRANT)
        self.assertEqual(shell.load_grant()["pr_number"], shell.GRANT_PR)

    def test_grant_allows_root_only_through_sudo_never_an_http_root_daemon(self):
        grant = shell.EXPECTED_GRANT
        self.assertIs(grant["root_via_sudo_allowed"], True)
        self.assertIs(grant["sudo_allowed"], True)
        self.assertIs(grant["root_http_daemon_allowed"], False)
        self.assertIs(grant["requires_access_jwt"], True)

    def test_grant_mismatch_fails_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "grant.json"
            bad = dict(shell.EXPECTED_GRANT, root_http_daemon_allowed=True)
            path.write_text(json.dumps(bad))
            with self.assertRaises(RuntimeError):
                shell.load_grant(path)

    def test_grant_must_not_require_lease(self):
        self.assertNotIn("requires_host_issued_lease", shell.EXPECTED_GRANT)
        self.assertNotIn("requires_fencing", shell.EXPECTED_GRANT)


class ExecutionTests(unittest.TestCase):
    def setUp(self):
        self.audit_entries = []
        self.audit = lambda entry: (self.audit_entries.append(entry) or True)
        patcher = patch.object(shell, "_service_identity_ok", return_value=True)
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_runs_bash_without_profile_and_reports_output(self):
        def runner(argv, **kwargs):
            self.assertEqual(argv[:4], ["/bin/bash", "--noprofile", "--norc", "-c"])
            self.assertEqual(kwargs["env"]["PATH"], "/usr/local/bin:/usr/bin:/bin")
            kwargs["stdout"].write(b"hi\n")
            return subprocess.CompletedProcess(argv, 0)
        result = shell.run_admin_shell({"command": "echo hi"}, home="/tmp", audit=self.audit, runner=runner)
        self.assertEqual(result, {"ok": True, "exit_code": 0, "timed_out": False,
                                  "stdout": "hi\n", "stderr": "", "truncated": False})

    def test_audit_records_hash_not_command_text(self):
        def runner(argv, **kwargs):
            return subprocess.CompletedProcess(argv, 0)
        shell.run_admin_shell({"command": "echo secret-token-123"}, home="/tmp", audit=self.audit, runner=runner)
        self.assertEqual([e["event"] for e in self.audit_entries], ["start", "end"])
        self.assertNotIn("secret-token-123", json.dumps(self.audit_entries))

    def test_audit_failure_refuses_to_run(self):
        called = []
        def runner(argv, **kwargs):
            called.append(argv)
            return subprocess.CompletedProcess(argv, 0)
        result = shell.run_admin_shell({"command": "id"}, home="/tmp", audit=lambda e: False, runner=runner)
        self.assertEqual(result, {"ok": False, "reason": "audit_unavailable"})
        self.assertEqual(called, [])

    def test_timeout_is_reported_not_raised(self):
        def runner(argv, **kwargs):
            raise subprocess.TimeoutExpired(argv, 1)
        result = shell.run_admin_shell({"command": "sleep 9", "timeout_seconds": 1}, home="/tmp",
                                       audit=self.audit, runner=runner)
        self.assertTrue(result["timed_out"])
        self.assertIs(result["ok"], False)

    def test_output_is_truncated(self):
        big = b"x" * (shell.MAX_OUTPUT_BYTES + 10)
        def runner(argv, **kwargs):
            kwargs["stdout"].write(big)
            return subprocess.CompletedProcess(argv, 0)
        result = shell.run_admin_shell({"command": "yes"}, home="/tmp", audit=self.audit, runner=runner)
        self.assertEqual(len(result["stdout"]), shell.MAX_OUTPUT_BYTES)
        self.assertTrue(result["truncated"])

    def test_default_runs_as_service_user_without_sudo(self):
        seen = {}
        def runner(argv, **kwargs):
            seen["argv"] = argv
            return subprocess.CompletedProcess(argv, 0)
        shell.run_admin_shell({"command": "id"}, home="/tmp", audit=self.audit, runner=runner)
        self.assertEqual(seen["argv"][0], "/bin/bash")
        self.assertIs(self.audit_entries[0]["as_root"], False)

    def test_as_root_goes_through_sudo_with_root_side_timeout(self):
        seen = {}
        def runner(argv, **kwargs):
            seen.update(argv=argv, env=kwargs["env"], timeout=kwargs["timeout"])
            return subprocess.CompletedProcess(argv, 0)
        result = shell.run_admin_shell({"command": "id", "as_root": True, "timeout_seconds": 20},
                                       home="/tmp", audit=self.audit, runner=runner)
        argv = seen["argv"]
        self.assertEqual(argv[:4], [shell.SUDO, "-n", "-H", "--"])
        self.assertEqual(argv[4:9], [shell.TIMEOUT_BIN, "-k", "5", "20", "/bin/bash"])
        self.assertIn("/usr/sbin", seen["env"]["PATH"])
        self.assertGreater(seen["timeout"], 20)
        self.assertIs(self.audit_entries[0]["as_root"], True)
        self.assertTrue(result["ok"])

    def test_as_root_exit_124_is_reported_as_timeout(self):
        runner = lambda argv, **kw: subprocess.CompletedProcess(argv, 124)
        result = shell.run_admin_shell({"command": "sleep 99", "as_root": True, "timeout_seconds": 1},
                                       home="/tmp", audit=self.audit, runner=runner)
        self.assertTrue(result["timed_out"])
        self.assertIs(result["ok"], False)

    def test_as_root_must_be_a_boolean(self):
        for bad in ("yes", 1, None):
            with self.subTest(bad=bad):
                result = shell.run_admin_shell({"command": "id", "as_root": bad}, home="/tmp", audit=self.audit)
                self.assertEqual(result["reason"], "invalid_arguments")

    def test_invalid_arguments_are_rejected(self):
        for bad in ({}, {"command": ""}, {"command": "id", "extra": 1},
                    {"command": "id", "timeout_seconds": 0}, {"command": "id", "timeout_seconds": True}):
            self.assertEqual(shell.run_admin_shell(bad, audit=self.audit)["reason"], "invalid_arguments", bad)

    def test_refuses_root_or_wrong_identity(self):
        with patch.object(shell, "_service_identity_ok", return_value=False):
            result = shell.run_admin_shell({"command": "id"}, audit=self.audit)
        self.assertEqual(result, {"ok": False, "reason": "service_identity_required"})


class BootstrapTests(unittest.TestCase):
    def test_bootstrap_refuses_non_service_identity(self):
        with patch.object(shell, "_service_identity_ok", return_value=False):
            with self.assertRaises(RuntimeError):
                shell.bootstrap_admin_shell()


def _alive(marker: bytes) -> bool:
    """True if any process other than this one has `marker` in its command line."""
    import os
    for entry in os.listdir("/proc"):
        if entry.isdigit() and int(entry) != os.getpid():
            try:
                if marker in Path("/proc/" + entry + "/cmdline").read_bytes():
                    return True
            except OSError:
                pass
    return False


def _kill_exact(argv_bytes: bytes) -> None:
    """Kill only processes whose whole argv equals `argv_bytes` (never a pattern match, so
    it cannot hit the shell that is running the tests)."""
    import os
    import signal
    for entry in os.listdir("/proc"):
        if entry.isdigit() and int(entry) != os.getpid():
            try:
                if Path("/proc/" + entry + "/cmdline").read_bytes() == argv_bytes:
                    os.kill(int(entry), signal.SIGKILL)
            except OSError:
                pass


class RealProcessTimeoutTests(unittest.TestCase):
    """Real processes: a timeout must stop the whole command, not only the shell."""

    def setUp(self):
        patcher = patch.object(shell, "_service_identity_ok", return_value=True)
        patcher.start()
        self.addCleanup(patcher.stop)
        self.addCleanup(_kill_exact, b"sleep\x004343\x00")

    def test_timeout_kills_background_children_of_the_command(self):
        import time
        with tempfile.TemporaryDirectory() as tmp:
            result = shell.run_admin_shell({"command": "sleep 4343 & echo started; wait", "timeout_seconds": 1},
                                           home=tmp, audit=lambda e: True)
        time.sleep(0.3)
        self.assertTrue(result["timed_out"])
        self.assertIs(result["ok"], False)
        self.assertFalse(_alive(b"sleep\x004343"), "child of the timed-out command is still running")

    def test_normal_completion_keeps_exit_code_and_output(self):
        with tempfile.TemporaryDirectory() as tmp:
            result = shell.run_admin_shell({"command": "echo out; echo err >&2; exit 3", "timeout_seconds": 10},
                                           home=tmp, audit=lambda e: True)
        self.assertEqual((result["ok"], result["exit_code"], result["timed_out"]), (False, 3, False))
        self.assertEqual((result["stdout"], result["stderr"]), ("out\n", "err\n"))

    def test_run_group_returns_a_completed_process_and_raises_on_timeout(self):
        done = shell._run_group(["/bin/sh", "-c", "exit 0"], timeout=5, check=False)
        self.assertEqual(done.returncode, 0)
        with self.assertRaises(subprocess.TimeoutExpired):
            shell._run_group(["/bin/sh", "-c", "sleep 4343"], timeout=1, check=False)
        self.assertFalse(_alive(b"sleep\x004343"))


if __name__ == "__main__":
    unittest.main()
