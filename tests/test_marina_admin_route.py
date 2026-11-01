#!/usr/bin/env python3
"""Negative-first contracts for the local Marina administrative maintenance adapter."""
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import Mock

SRC = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "operator_admin_route.py"
spec = importlib.util.spec_from_file_location("operator_admin_route", SRC)
assert spec is not None
route = importlib.util.module_from_spec(spec)
spec.loader.exec_module(route)

HEAD = "a" * 40
MERGE = "b" * 40

class MarinaOperatorAdminRouteTests(unittest.TestCase):
    def setUp(self):
        self.request = dict(
            operation="olist_browser_restart", pr_number=9999,
            head_sha=HEAD, merge_sha=MERGE,
            idempotency_key="request-20261101-0001", fencing_token=31,
        )
        self.operator = Mock()
        self.operator.hostname.return_value = "marina-a1-4c24g"
        self.operator.effective_uid.return_value = 0
        self.operator.olist_config_is_ready.return_value = True
        self.operator.owner_pr_grant_is_valid.return_value = True
        self.operator.runtime_permission_is_valid.return_value = True
        self.operator.lease_reserve_once.return_value = True
        self.operator.audit_ready.return_value = True
        self.operator.restart_service.return_value = True

    def decide(self, overrides=None):
        return route.execute_admin_operation({**self.request, **(overrides or {})}, self.operator)

    def test_positive_fixed_olist_restart_with_complete_trusted_guards(self):
        self.assertEqual(self.decide(), (True, "restart_requested"))
        self.operator.restart_service.assert_called_once_with("amazon-returns-olist-erp-browser.service")
        self.operator.owner_pr_grant_is_valid.assert_called_once_with(9999, HEAD, MERGE, "deployment_manage", "olist_browser_restart")
        self.operator.lease_reserve_once.assert_called_once_with(
            "olist_browser_restart", "request-20261101-0001", 31,
        )

    def test_missing_proof_fail_closed(self):
        for field in ("pr_number", "head_sha", "merge_sha", "idempotency_key", "fencing_token"):
            self.setUp()
            value = self.decide({field: None})
            self.assertFalse(value[0], field)
            self.operator.restart_service.assert_not_called()

    def test_no_arbitrary_commands_or_service_changes(self):
        for operation in ("shell", "database_admin", "restart_other_service", "file_write", "", None):
            self.setUp()
            self.assertFalse(self.decide({"operation": operation})[0])
            self.operator.restart_service.assert_not_called()

    def test_wrong_host_user_or_missing_persistent_olist_config_denied(self):
        for method, reply in [
            ("hostname", "always-free-arm-1787907847-26"),
            ("effective_uid", 1000),
            ("olist_config_is_ready", False),
        ]:
            self.setUp()
            getattr(self.operator, method).return_value = reply
            self.assertFalse(self.decide()[0], method)
            self.operator.restart_service.assert_not_called()

    def test_owner_denial_runtime_denial_and_lease_denial(self):
        for method in (
            "owner_pr_grant_is_valid",
            "runtime_permission_is_valid",
            "lease_reserve_once",
        ):
            self.setUp()
            getattr(self.operator, method).return_value = False
            self.assertFalse(self.decide()[0], method)
            self.operator.restart_service.assert_not_called()

    def test_bad_fences_and_identity_fail_closed(self):
        for value in (0, -1, True, "31", 2**63, None):
            self.setUp()
            self.assertFalse(self.decide({"fencing_token": value})[0], value)
            self.operator.restart_service.assert_not_called()
        for key in ("", "../malicious", "a" * 130, "with whitespace", None):
            self.setUp()
            self.assertFalse(self.decide({"idempotency_key": key})[0], key)
            self.operator.restart_service.assert_not_called()

    def test_restart_failure_and_trusted_host_exception_are_not_success(self):
        self.operator.restart_service.return_value = False
        self.assertFalse(self.decide()[0])
        self.setUp()
        self.operator.olist_config_is_ready.side_effect = RuntimeError("missing")
        self.assertFalse(self.decide()[0])
        self.operator.restart_service.assert_not_called()

    def test_extra_client_fields_rejected(self):
        self.assertFalse(self.decide({"sudo": True})[0])
        self.operator.restart_service.assert_not_called()


    def test_audit_readiness_is_a_required_independent_gate(self):
        self.operator.audit_ready.return_value = False
        self.assertEqual(self.decide(), (False, "protected_audit_unavailable"))
        self.operator.restart_service.assert_not_called()

    def test_boolean_identity_or_pr_number_never_passes(self):
        self.assertFalse(self.decide({"pr_number": True})[0])
        self.operator.restart_service.assert_not_called()

    def test_missing_operator_denied(self):
        self.assertEqual(route.execute_admin_operation(self.request, None),
                         (False, "host_operator_unavailable"))

if __name__ == "__main__":
    unittest.main()
