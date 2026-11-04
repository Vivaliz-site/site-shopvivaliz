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
        self.assertEqual(shell.load_grant()["pr_number"], 3616)

    def test_grant_mismatch_fails_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "grant.json"
            bad = dict(shell.EXPECTED_GRANT, sudo_allowed=True)
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
            return subprocess.CompletedProcess(argv, 0, stdout=b"hi\n", stderr=b"")
        result = shell.run_admin_shell({"command": "echo hi"}, home="/tmp", audit=self.audit, runner=runner)
        self.assertEqual(result, {"ok": True, "exit_code": 0, "timed_out": False,
                                  "stdout": "hi\n", "stderr": "", "truncated": False})

    def test_audit_records_hash_not_command_text(self):
        def runner(argv, **kwargs):
            return subprocess.CompletedProcess(argv, 0, stdout=b"", stderr=b"")
        shell.run_admin_shell({"command": "echo secret-token-123"}, home="/tmp", audit=self.audit, runner=runner)
        self.assertEqual([e["event"] for e in self.audit_entries], ["start", "end"])
        self.assertNotIn("secret-token-123", json.dumps(self.audit_entries))

    def test_audit_failure_refuses_to_run(self):
        called = []
        def runner(argv, **kwargs):
            called.append(argv)
            return subprocess.CompletedProcess(argv, 0, stdout=b"", stderr=b"")
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
            return subprocess.CompletedProcess(argv, 0, stdout=big, stderr=b"")
        result = shell.run_admin_shell({"command": "yes"}, home="/tmp", audit=self.audit, runner=runner)
        self.assertEqual(len(result["stdout"]), shell.MAX_OUTPUT_BYTES)
        self.assertTrue(result["truncated"])

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


if __name__ == "__main__":
    unittest.main()
