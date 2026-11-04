from __future__ import annotations

import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest import mock

ROOT_BROKER = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "root_broker.py"
SPEC = importlib.util.spec_from_file_location("root_broker_service_test", ROOT_BROKER)
broker = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(broker)

HEAD = "a" * 40
MERGE = "b" * 40
UNIT = "shopvivaliz-desktop-commander.service"


class Completed:
    def __init__(self, returncode=0, stdout=""):
        self.returncode = returncode
        self.stdout = stdout


class GrantRunnerTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.NamedTemporaryFile()
        self.verifier = mock.patch.object(broker, "VERIFIER", Path(self.tmp.name))
        self.verifier.start()
        self.token = mock.patch.object(broker, "protected_token", return_value="t" * 20)
        self.token.start()

    def tearDown(self):
        self.token.stop()
        self.verifier.stop()
        self.tmp.close()

    def test_service_operation_is_bound_to_site_repository_only(self):
        self.assertEqual(broker.OPERATION_TARGETS[broker.SERVICE_OPERATION], broker.REPOSITORY)
        self.assertEqual(broker.OPERATION_TARGETS[broker.OPERATION], broker.TARGET_REPOSITORY)

    def test_grant_runs_verifier_with_the_requested_operation(self):
        stdout = "OWNER_PR_GRANT=AUTHORIZED reason=owner_authorization_verified"
        with mock.patch.object(broker.subprocess, "run", return_value=Completed(0, stdout)) as run:
            valid = broker.real_grant_valid(42, HEAD, MERGE, broker.CAPABILITY,
                                            broker.SERVICE_OPERATION, broker.REPOSITORY)
        self.assertTrue(valid)
        argv = run.call_args[0][0]
        self.assertEqual(argv[argv.index("--operation") + 1], broker.SERVICE_OPERATION)
        self.assertEqual(argv[argv.index("--target-repository") + 1], broker.REPOSITORY)

    def test_operation_with_mismatched_target_never_reaches_verifier(self):
        with mock.patch.object(broker.subprocess, "run") as run:
            self.assertFalse(broker.real_grant_valid(42, HEAD, MERGE, broker.CAPABILITY,
                                                     broker.SERVICE_OPERATION, broker.TARGET_REPOSITORY))
            self.assertFalse(broker.real_grant_valid(42, HEAD, MERGE, broker.CAPABILITY,
                                                     broker.OPERATION, broker.REPOSITORY))
            run.assert_not_called()

    def test_unknown_operation_is_rejected_before_grant_lookup(self):
        with mock.patch.object(broker.subprocess, "run") as run:
            self.assertFalse(broker.real_grant_valid(42, HEAD, MERGE, broker.CAPABILITY,
                                                     "shell", broker.REPOSITORY))
            run.assert_not_called()


class GrantFieldTests(unittest.TestCase):
    def payload(self, **overrides):
        data = {"operation": broker.SERVICE_OPERATION, "pr_number": 42,
                "head_sha": HEAD, "merge_sha": MERGE}
        data.update(overrides)
        return data

    def test_service_request_keeps_its_operation(self):
        self.assertEqual(broker._grant_fields(self.payload()), (42, HEAD, MERGE, broker.SERVICE_OPERATION))

    def test_unknown_operation_is_rejected(self):
        self.assertIsNone(broker._grant_fields(self.payload(operation="ssh_shell")))


class RestartServiceTests(unittest.TestCase):
    def test_restarts_the_requested_unit_not_the_olist_unit(self):
        calls = []

        def runner(argv, **kwargs):
            calls.append(argv)
            return Completed(0, "active")

        operator = broker.BrokerOperator(runner=runner)
        self.assertTrue(operator.restart_service(UNIT))
        self.assertEqual(calls[0], ["/usr/bin/systemctl", "restart", UNIT])
        self.assertEqual(calls[1], ["/usr/bin/systemctl", "is-active", UNIT])

    def test_rejects_units_outside_the_pattern_without_any_call(self):
        calls = []
        operator = broker.BrokerOperator(runner=lambda argv, **kw: calls.append(argv))
        self.assertFalse(operator.restart_service("sshd.service"))
        self.assertFalse(operator.restart_service("shopvivaliz-x.timer"))
        self.assertEqual(calls, [])


class PreflightTests(unittest.TestCase):
    def operator(self, grant=True):
        seen = []
        operator = broker.BrokerOperator(grant_checker=lambda *args: seen.append(args) or grant)
        operator.effective_uid = lambda: 0
        operator.hostname = lambda: broker.HOST
        operator.runtime_permission_is_valid = lambda: True
        return operator, seen

    def payload(self, operation):
        return {"operation": operation, "pr_number": 42, "head_sha": HEAD, "merge_sha": MERGE}

    def test_service_preflight_does_not_depend_on_olist_runtime(self):
        operator, seen = self.operator()
        operator.olist_config_is_ready = mock.Mock(side_effect=AssertionError("olist probed"))
        result = operator.preflight(self.payload(broker.SERVICE_OPERATION))
        self.assertEqual(result, {"ok": True, "operation": broker.SERVICE_OPERATION})
        self.assertEqual(seen[0][3:], (broker.CAPABILITY, broker.SERVICE_OPERATION, broker.REPOSITORY))

    def test_olist_preflight_still_requires_olist_runtime(self):
        operator, _ = self.operator()
        operator.olist_config_is_ready = lambda: False
        result = operator.preflight(self.payload(broker.OPERATION))
        self.assertEqual(result, {"ok": False, "reason": "protected_olist_runtime_not_ready"})

    def test_service_preflight_denied_without_grant(self):
        operator, _ = self.operator(grant=False)
        result = operator.preflight(self.payload(broker.SERVICE_OPERATION))
        self.assertEqual(result, {"ok": False, "reason": "owner_pr_grant_denied"})


if __name__ == "__main__":
    unittest.main()
